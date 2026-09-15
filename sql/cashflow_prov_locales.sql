/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES
   Maestro de categorias y fechas de pago
   ----------------------------------------------------------------------------
   Base    : central
   Prefijo : RO_T_CASHFLOW_
   Orden   : se puede correr en cualquier momento. Sin el, la pestana avisa que
             faltan las tablas y la fila del tablero va en cero.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Las cuentas a pagar locales salen de Tango (CPA04 + CPA54 + CPA01, con las
   imputaciones de CPA05). Eso dice CUANTO se debe y CUANDO vence. Lo que Tango
   no sabe es dos cosas, y las dos se cargan aca:

     1. QUE ES cada proveedor -su rubro economico, su rubro, su centro de
        costos-, que es lo que permite que el tablero muestre alquileres,
        impuestos y mercaderia en filas distintas en lugar de todo en una.

     2. CUANDO SE PIENSA PAGAR cada comprobante, que casi nunca es la fecha de
        vencimiento.

   Son dos tablas porque son dos cosas distintas: una describe al PROVEEDOR y la
   otra decide sobre un COMPROBANTE.
   ----------------------------------------------------------------------------
   POR QUE UNA COPIA DE LA PLANILLA Y NO CPA01.COD_RUBRO

   CPA01 tiene una columna COD_RUBRO, y esta VACIA en los 4.893 proveedores.
   CAMPOS_ADICIONALES tampoco: los 3.694 registros que lo tienen traen el XML
   vacio.

   Se evaluo empezar a cargar COD_RUBRO desde Tango y se descarto: obligaria a
   administracion a mantener DOS maestros en paralelo -la planilla, que es la que
   usan todos los dias, y Tango-, y dos maestros en paralelo terminan
   discrepando. La planilla sigue siendo la fuente; esta tabla es una COPIA
   REIMPORTABLE, con su fecha de importacion a la vista para que se sepa cuan
   vieja es.

   Por eso el circuito de carga es el mismo que el de los pagos y el de
   Cob. Electronicos: plantilla CSV, previsualizacion del diff, baja logica e
   historial. Ver Class/Planilla.php.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_PROV_LOCALES_CATEG
   El maestro de proveedores, copiado de la hoja "Maestro proveedores" del Excel
   Cronograma de Pagos.

   LA CLAVE ES COD_PROVEE Y NADA MAS. Es el codigo de Tango, y es lo unico que
   permite cruzar la planilla contra las cuentas a pagar.

   LA PLANILLA TIENE UN CODIGO REPETIDO (1.222 unicos en 1.223 filas). NO se
   resuelve con la clave: la importacion lo detecta y lo muestra en la
   previsualizacion, y esa fila no se carga hasta que alguien decida cual de las
   dos vale. Colapsarlo en silencio -quedandose con el ultimo- elegiria por el
   usuario y nadie se enteraria de que hay un dato duplicado en la planilla.

   LOS VALORES SE GUARDAN COMO VINIERON Y NORMALIZADOS, EN DOS COLUMNAS. La
   planilla viene sucia: 'echeq' en minuscula, 'ECOMMERC' por 'ECOMMERCE'. El
   normalizado es con el que se agrupa y se decide; el original es lo que hay que
   poder mostrar cuando algo no matchea. Guardar solo el normalizado perderia la
   evidencia de que la planilla tiene un typo, que es justamente lo que hay que
   arreglar en la planilla.

   PLAZO_PAGO ES TEXTO, NO UN ENTERO. En la planilla los valores son CONTADO,
   7 DIAS, 30 DIAS, 15 DIAS y DEBITO, y esta VACIO en 765 de 1.223 filas (62%).
   Guardarlo como INT obligaria a inventar un numero para CONTADO y para DEBITO.
   Se guarda el texto y aparte PLAZO_DIAS, que es la interpretacion en dias
   cuando se puede: CONTADO -> 0, '30 DIAS' -> 30, DEBITO -> NULL. Las dos
   columnas: la que se usa para calcular y la que explica de donde salio.

   NO HAY BAJA FISICA. Reimportar marca VIGENTE = 0 lo anterior e inserta lo
   nuevo; un proveedor que desaparece de la planilla queda con VIGENTE = 0 y no
   se borra. El historial es lo unico que explica por que un comprobante se
   clasificaba distinto la semana pasada.

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG (
        ID                INT IDENTITY(1,1) NOT NULL,
        /* COLLATE Latin1_General_BIN: la MISMA que CPA01.COD_PROVEE. Sin
           declararla, la columna toma la de la base -Modern_Spanish_CI_AI-, que
           es acento-insensible: 'OGNUNE' y 'OGNUÑE' serian el mismo valor acá y
           dos proveedores distintos en Tango. Hay 27 codigos con caracteres no
           ASCII, asi que no es hipotetico. Ver sql/cashflow_prov_locales_collation.sql,
           que lo corrige en una base ya creada. */
        COD_PROVEE        VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        /* El nombre tal como lo trae la planilla. NO se usa para cruzar -para
           eso esta el codigo- pero es lo que permite ver en la previsualizacion
           que "MTDODI" es Donna Di Dio sin ir a buscarlo. */
        NOMBRE            VARCHAR(120)  NULL,

        /* Las tres clasificaciones. RUBRO_ECONOMICO es la que mapea a filas del
           tablero -26 valores, uno de ellos "Excluidos"-; los otros dos son para
           analisis y para cuando se quiera abrir mas. */
        RUBRO_ECONOMICO   VARCHAR(60)   NULL,
        RUBRO             VARCHAR(60)   NULL,
        CENTRO_COSTOS     VARCHAR(60)   NULL,

        /* Como se le paga habitualmente. Sirve de valor por defecto en la
           importacion de pagos: si la planilla de pagos no trae forma, se usa
           esta en lugar de dejarla vacia. */
        FORMA_PAGO        VARCHAR(30)   NULL,
        FORMA_PAGO_ORIG   VARCHAR(60)   NULL,

        /* El plazo, como texto y como dias. Ver la nota de arriba. */
        PLAZO_PAGO        VARCHAR(30)   NULL,
        PLAZO_DIAS        INT           NULL,

        /* Como se reparte el gasto entre canales. Tiene un typo conocido en la
           planilla ('50% ECOMMERC'), asi que va con su original al lado. */
        CRITERIO_DISTRIB  VARCHAR(60)   NULL,
        CRITERIO_ORIG     VARCHAR(60)   NULL,

        VIGENTE           BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_PLCAT_VIGENTE DEFAULT (1),
        /* Cuando se importo la planilla de la que salio esta fila. Es lo que
           contesta "que tan viejo es este maestro", que es la pregunta obvia
           cuando un proveedor aparece sin clasificar. */
        FECHA_IMPORTACION DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLCAT_IMP DEFAULT (GETDATE()),
        USUARIO           VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_CATEG PRIMARY KEY CLUSTERED (ID)
    );

    /* La consulta que corre en cada carga del tablero es "los vigentes, por
       codigo de proveedor". */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLCAT_VIGENTE
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG (VIGENTE, COD_PROVEE)
        INCLUDE (RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS, FORMA_PAGO, PLAZO_DIAS);

    /* Para abrir el historial de un proveedor puntual. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLCAT_PROVEE
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG (COD_PROVEE, ID);

    PRINT 'Tabla de categorias de proveedores locales creada.';
END
GO

/* ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_PROV_LOCALES_PAGO
   Cuando y como se piensa pagar cada comprobante.

   LA CLAVE INCLUYE AL PROVEEDOR, Y ESTO ES LO CONTRARIO DE COBRANZAS.

   En RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL la unicidad es (T_COMP, N_COMP) y
   el codigo de cliente queda al lado sin formar parte de la clave. Aca NO se
   puede hacer eso, y el motivo es que el numero de comprobante lo pone quien
   emite:

     - En ventas el comprobante lo emitimos nosotros, asi que el par tipo+numero
       identifica un comprobante y punto.
     - En compras lo emite el PROVEEDOR. Dos proveedores distintos emiten, cada
       uno, su factura A-0001-00000001.

   Verificado contra la base: hay 10.293 pares (T_COMP, N_COMP) que se repiten
   entre proveedores LOCALES, sobre 35.777 filas de CPA04. Con la clave de
   cobranzas, la fecha de pago de una factura de Andreani se le aplicaria a una
   de Telecom.

   EL VENCIMIENTO NO ENTRA EN LA CLAVE, y es a proposito. Un comprobante en
   cuotas tiene varios vencimientos, pero la decision "esta factura se paga tal
   dia" se toma por factura. Si algun dia hace falta pagar cuota por cuota, se
   agrega FECHA_VTO a la clave y el resto del circuito no cambia.

   EL DATO SOBREVIVE AL COMPROBANTE: si la factura desaparece del listado -se
   paga, se anula- y despues vuelve, la fecha cargada vuelve a aplicar. No se
   limpia sola. Borrarla automaticamente perderia una decision que alguien tomo,
   y el sintoma seria una fecha que se "desconfigura sola". Mismo criterio que la
   fecha manual de Cobranzas FR.

   ESTADO: lo que distingue una prevision de un hecho.
       'PREVISTO'    -> alguien cargo cuando lo piensa pagar
       'CONCILIADO'  -> Tango dice que el comprobante ya se cancelo

   La prevision NO SE BORRA al conciliar: queda con su fecha real al lado, que es
   lo unico que permite comparar despues lo que se planifico contra lo que paso.
   Ver la conciliacion en Class/Proveedores.php.

   ES REEJECUTABLE: la tabla se crea solo si no existe.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO (
        ID              INT IDENTITY(1,1) NOT NULL,
        /* Las tres con la collation de CPA04, por el mismo motivo que arriba:
           son la clave con la que se cruza contra las cuentas a pagar. */
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)    COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)   COLLATE Latin1_General_BIN NOT NULL,

        FECHA_PAGO      DATE          NOT NULL,
        FORMA_PAGO      VARCHAR(30)   NULL,
        FORMA_PAGO_ORIG VARCHAR(60)   NULL,
        OBSERVACION     VARCHAR(200)  NULL,

        ESTADO          VARCHAR(12)   NOT NULL
            CONSTRAINT DF_RO_T_CF_PLPAG_ESTADO DEFAULT ('PREVISTO'),
        /* Cuando Tango dice que se cancelo de verdad. Se llena al conciliar y
           queda AL LADO de FECHA_PAGO, no la pisa: la gracia es poder comparar
           la prevision contra la realidad. */
        FECHA_CANCELADO DATE          NULL,
        FECHA_CONCILIA  DATETIME      NULL,

        /* De donde salio la carga: la importacion masiva o la edicion fila por
           fila en la grilla. Sin esto no se puede saber si una fecha rara vino
           de un archivo o la tipeo alguien. */
        ORIGEN          VARCHAR(10)   NOT NULL
            CONSTRAINT DF_RO_T_CF_PLPAG_ORIGEN DEFAULT ('MANUAL'),

        USUARIO         VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLPAG_ALTA DEFAULT (GETDATE()),
        FECHA_MOD       DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_PAGO PRIMARY KEY CLUSTERED (ID),
        /* LA CLAVE DE NEGOCIO. Ver la nota del encabezado: incluye al proveedor
           porque el numero de comprobante lo pone el, no nosotros. */
        CONSTRAINT UQ_RO_T_CASHFLOW_PROV_LOCALES_PAGO
            UNIQUE (COD_PROVEE, T_COMP, N_COMP),
        CONSTRAINT CK_RO_T_CASHFLOW_PROV_LOCALES_PAGO_ESTADO
            CHECK (ESTADO IN ('PREVISTO', 'CONCILIADO')),
        CONSTRAINT CK_RO_T_CASHFLOW_PROV_LOCALES_PAGO_ORIGEN
            CHECK (ORIGEN IN ('MANUAL', 'ARCHIVO', 'CONCILIA'))
    );

    /* La consulta del tablero es "todas las previsiones, por comprobante", y la
       de la pestana filtra por estado. */
    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLPAG_ESTADO
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO (ESTADO, FECHA_PAGO)
        INCLUDE (COD_PROVEE, T_COMP, N_COMP, FORMA_PAGO);

    PRINT 'Tabla de fechas de pago a proveedores locales creada.';
END
GO

/* ----------------------------------------------------------------------------
   3. La fila del tablero

   PROV_LOCALES ya existe en RO_T_CASHFLOW_CONF_FILA desde la semilla, apuntada
   al proveedor PROV_LOCALES que hasta ahora no estaba construido. Lo unico que
   cambia es el NOMBRE.

   POR QUE SE RENOMBRA: la fila esta en la seccion Costo de Mercaderia, pero de
   los 1.361 millones pendientes solo unos 80 son mercaderia. El resto es
   aduana (235 M), seguros (63 M), logistica, alquileres, servicios, tarjetas y
   bancos. Dejarla llamandose "Proveedores Locales" dentro de Costo de
   Mercaderia haria que la fila diga una cosa y muestre otra.

   ES UN CAMBIO DE DATOS: el nombre vive en NOMBRE y se edita desde Parametros.
   Va aca para que una base nueva quede bien sin que nadie tenga que acordarse.

   EL PASO SIGUIENTE YA ESTA PREVISTO: cuando el maestro este cargado, partir
   esta fila en varias -una por rubro economico- es configuracion, no codigo. El
   proveedor ya expone una serie por rubro ademas del total, y el registro las
   declara como componentes para que no puedan estar activas a la vez con el
   total. Ver Class/Providers/ProveedoresProvider.php.

   ES REEJECUTABLE: el UPDATE no hace nada si el nombre ya es ese.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET NOMBRE = 'Cuentas a Pagar Locales', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = 'PROV_LOCALES' AND NOMBRE <> 'Cuentas a Pagar Locales';
END
GO

PRINT 'Proveedores Locales: tablas y fila del tablero listas.';
GO
