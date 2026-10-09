/* ============================================================================
   CASHFLOW - VENTAS: EL MIX DE COBRO COMO ARBOL DE NODOS POR CANAL
   ============================================================================
   Base    : central. Reejecutable.
   Orden   : DESPUES de sql/ventas_proyeccion.sql (crea el mix plano que este
             script migra) y EN EL MISMO MOMENTO de publicar el codigo de
             feature/ventas-mix-apertura. Ver README-ventas.md, "Orden de
             ejecucion de los scripts".
   ----------------------------------------------------------------------------
   POR QUE EXISTE
   --------------
   El mix de cobro era plano: una fila por canal y medio de pago, con su
   porcentaje y sus dias de acreditacion. Alcanzaba para decir "Locales cobra
   90% con tarjeta a 2 dias", pero no para decir con QUE tarjeta, por QUE
   procesadora, en CUANTAS cuotas ni CUANTO cuesta cobrar cada cosa. Y eso es
   justamente lo que mueve la caja: un debito se acredita en una semana y una
   promo bancaria en quince dias, y cada procesadora se queda con su comision.

   Esto crea un ARBOL por canal:
       Locales    : Medio de pago > Tipo de tarjeta > Procesadora > Cuotas
       Ecommerce  : Marketplace > Medio de pago > Tipo de tarjeta > ...
       Franquicias y Mayoristas: un solo nivel, como hasta ahora.

   Cada nodo carga SOLO LO SUYO -su porcentaje entre sus hermanos, su costo, su
   tasa, sus dias- y la regla que lo resuelve hasta las hojas vive en UN solo
   lugar: MixCobro::resolver(), en cashflow/Class/MixCobro.php. La usan la
   pestana Ventas, Parametros y el tablero. Este script no resuelve nada.

   QUE HACE, EN ESTE ORDEN

     1. Crea RO_T_CASHFLOW_VENTAS_MIX_NODO.
     2. Migra el mix plano, UNA SOLA VEZ POR CANAL.

   RO_T_CASHFLOW_VENTAS_MIX NO SE BORRA NI SE TOCA. Deja de leerse en cuanto
   existe la tabla nueva y queda por el historico: con que mix se proyecto
   antes de este cambio. Cada nodo migrado guarda en ID_MIX_ORIGEN de que fila
   vino.

   INVARIANTE DE LA MIGRACION: APENAS SE CORRE, LA PROYECCION DA EXACTAMENTE
   LO MISMO QUE ANTES. Los nodos migrados tienen el porcentaje y los dias de
   su fila de origen y el costo y la tasa vacios; Vtex entra al 100% y sin
   dias, asi que no cambia ni el porcentaje ni el plazo de lo que cuelga de
   el. La cobranza bruta es la de antes y la fila de costos da cero.

   SIN ESTE SCRIPT la pantalla no se cae: Ventas y el tablero siguen
   calculando con el mix plano (bruto, sin costos) y Parametros muestra ese
   mix como un arbol de un nivel, SOLO LECTURA, diciendo que script falta.
   Por eso se corre al publicar: hasta entonces el mix no se puede editar.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_VENTAS_MIX_NODO
   Un nodo del arbol del mix de cobro de un canal.

   ID_PADRE NULL es el primer nivel del canal. La FK contra la misma tabla
   impide un nodo colgado de un padre que no existe; que el padre sea del
   MISMO canal lo valida MixCobro::validarEstructura(), porque un CHECK no
   puede mirar otra fila.

   NIVEL es el TIPO de nivel del nodo, una lista fija y ordenada del codigo.
   LA LISTA DEL CHECK Y MixCobro::NIVELES CAMBIAN JUNTAS: es el mismo
   criterio que NATURALEZA en RO_T_CASHFLOW_CONF_FILA. Que el nivel de un hijo
   sea posterior al de su padre, o que MARKETPLACE solo exista en Ecommerce,
   es estructura del arbol y lo valida el PHP.

   PORCENTAJE es la participacion DENTRO DE SUS HERMANOS, no de la venta del
   canal: el % efectivo de una hoja es el producto de su cadena y se calcula
   al leer. Guardarlo multiplicado lo dejaria desactualizado en cuanto alguien
   tocara un nodo de arriba.

   COSTO y TASA son NULLABLES y NULL vale cero: "este nivel no agrega costo".
   Es la excepcion explicita a la regla de "un dato que falta es null y
   avisa": muchos nodos no tienen costo, y obligar a tipear un cero en cada
   uno no agrega informacion. Ya incluyen IVA e impuestos.

   DIAS_ACREDITACION NULL es "lo define un nivel superior": gana el nivel mas
   cercano a la hoja que los tenga. Aca NULL y 0 NO son lo mismo -0 es
   "se acredita el mismo dia"-, y una hoja sin dias en toda su rama es un
   error de validacion, no un cero.

   ACTIVO arranca en 0: lo nuevo entra inhabilitado y en 0%, para no romper el
   100% de su grupo de hermanos en el momento del alta. Inhabilitar un nodo
   saca de la proyeccion todo su subarbol. No hay bajas fisicas.

   EL NOMBRE ES UNICO ENTRE HERMANOS, no en el canal: "Mercado Pago" como
   medio de pago y como procesadora son nodos distintos. En SQL Server un
   UNIQUE trata NULL como un valor mas, asi que la misma restriccion cubre el
   primer nivel (ID_PADRE NULL). Va con Modern_Spanish_CI_AI para que
   "Debito" y "Débito" choquen: dos hermanos que solo difieren en un acento
   son el mismo nodo tipeado dos veces. El PHP compara igual.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO (
        ID                INT IDENTITY(1,1) NOT NULL,
        CANAL             VARCHAR(20)   NOT NULL,
        ID_PADRE          INT           NULL,
        NIVEL             VARCHAR(20)   NOT NULL,
        NOMBRE            VARCHAR(50)   COLLATE Modern_Spanish_CI_AI NOT NULL,
        PORCENTAJE        DECIMAL(12,6) NOT NULL CONSTRAINT DF_CF_VTA_MIXN_PORC   DEFAULT (0),
        COSTO             DECIMAL(12,6) NULL,
        TASA              DECIMAL(12,6) NULL,
        DIAS_ACREDITACION INT           NULL,
        ACTIVO            BIT           NOT NULL CONSTRAINT DF_CF_VTA_MIXN_ACTIVO DEFAULT (0),
        ORDEN             INT           NOT NULL CONSTRAINT DF_CF_VTA_MIXN_ORDEN  DEFAULT (0),
        ID_MIX_ORIGEN     INT           NULL,

        USUARIO_ALTA      VARCHAR(50)   NULL,
        FECHA_ALTA        DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXN_FALTA  DEFAULT (GETDATE()),
        USUARIO_MODIF     VARCHAR(50)   NULL,
        FECHA_MODIF       DATETIME      NULL CONSTRAINT DF_CF_VTA_MIXN_FMODIF DEFAULT (GETDATE()),
        USUARIO_BAJA      VARCHAR(50)   NULL,
        FECHA_BAJA        DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_VENTAS_MIX_NODO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CASHFLOW_VENTAS_MIX_NODO_PADRE
            FOREIGN KEY (ID_PADRE) REFERENCES dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO (ID),
        CONSTRAINT UQ_RO_T_CASHFLOW_VENTAS_MIX_NODO UNIQUE (CANAL, ID_PADRE, NOMBRE),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_NIVEL
            CHECK (NIVEL IN ('MARKETPLACE', 'MEDIO_PAGO', 'TIPO_TARJETA', 'PROCESADORA', 'CUOTAS')),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_PORC
            CHECK (PORCENTAJE >= 0 AND PORCENTAJE <= 1),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_COSTO
            CHECK (COSTO IS NULL OR (COSTO >= 0 AND COSTO <= 1)),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_TASA
            CHECK (TASA IS NULL OR (TASA >= 0 AND TASA <= 1)),
        CONSTRAINT CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_DIAS
            CHECK (DIAS_ACREDITACION IS NULL OR DIAS_ACREDITACION >= 0)
    );

    PRINT 'Creada RO_T_CASHFLOW_VENTAS_MIX_NODO.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_VENTAS_MIX_NODO ya existia: no se toco.';
GO

/* ----------------------------------------------------------------------------
   2. MIGRACION DEL MIX PLANO, UNA SOLA VEZ POR CANAL

   Un canal que ya tiene nodos NO SE TOCA: puede ser una segunda corrida, o un
   arbol que alguien ya edito desde Parametros. Volver a migrarlo pisaria esa
   edicion con el mix viejo, que es justamente lo que dejo de valer.

   QUE QUEDA DE CADA CANAL
     Locales, Franquicias, Mayoristas -> sus medios tal cual, como nodos de
         MEDIO_PAGO en el primer nivel, con su porcentaje, sus dias y su
         estado.
     Ecommerce -> todo cuelga de un marketplace, asi que se crea Vtex
         (MARKETPLACE) al 100% y sin dias, y debajo sus medios actuales.
         Mercado Libre lo carga el usuario despues.

   LOS NOMBRES pasan a mayuscula inicial y se muestran tal cual se guardan:
   ahora son texto libre ("3 cuotas", "Resto") y la pantalla ya no los
   normaliza. CASH pasa a llamarse Efectivo, que es como lo llama el negocio.
   Un medio que no esta en la lista se migra con la primera letra en
   mayuscula y el resto en minuscula. Los nombres van en un CASE y no en una
   tabla cruzada: los cruces del modulo se hacen en PHP, y este no es uno.

   GO CUOTAS QUEDA INHABILITADO en todos los canales. Hoy ya lo esta y en 0%;
   si en alguna base estuviera activo con porcentaje, inhabilitarlo cambiaria
   la proyeccion y romperia el 100% de su canal, asi que el script lo avisa
   con un PRINT para que se reacomode desde Parametros.

   COSTO y TASA quedan vacios en todo lo migrado.

   Los nodos migrados no llevan usuario de alta, como el resto de las
   semillas: no los creo nadie desde la pantalla.

   TODO VA EN UNA TRANSACCION: un canal a medio migrar -Vtex sin sus medios-
   ya no se volveria a migrar, porque tiene nodos, y quedaria sin hojas.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX', 'U') IS NULL
BEGIN
    PRINT 'No existe RO_T_CASHFLOW_VENTAS_MIX: no hay mix plano que migrar. '
        + 'El arbol se carga desde Parametros -> Ventas.';
END
ELSE
BEGIN
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    /* El aviso de Go Cuotas activo, ANTES de inhabilitarlo */
    IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX m
                WHERE m.MEDIO_PAGO = 'GO CUOTAS' AND m.ACTIVO = 1 AND m.PORCENTAJE > 0
                  AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO n
                                   WHERE n.CANAL = m.CANAL))
        PRINT 'AVISO: Go Cuotas estaba activo y con porcentaje en algun canal. Se migra '
            + 'inhabilitado, asi que ese canal ya no suma 100%: reacomodalo desde '
            + 'Parametros -> Ventas -> Mix de Cobro y Plazos.';

    /* ---- Locales, Franquicias y Mayoristas ---------------------------------
       Un solo INSERT para los tres: el NOT EXISTS se evalua contra la tabla
       ANTES de insertar, asi que un canal se migra entero o no se migra. */
    INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO
        (CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION,
         ACTIVO, ORDEN, ID_MIX_ORIGEN)
    SELECT m.CANAL, NULL, 'MEDIO_PAGO',
           CASE m.MEDIO_PAGO
               WHEN 'CASH'          THEN 'Efectivo'
               WHEN 'TARJETA'       THEN 'Tarjeta'
               WHEN 'GO CUOTAS'     THEN 'Go Cuotas'
               WHEN 'TRANSFERENCIA' THEN 'Transferencia'
               WHEN 'ECHEQ'         THEN 'Echeq'
               ELSE UPPER(LEFT(m.MEDIO_PAGO, 1)) + LOWER(SUBSTRING(m.MEDIO_PAGO, 2, 50))
           END,
           m.PORCENTAJE, NULL, NULL, m.DIAS_ACREDITACION,
           CASE WHEN m.MEDIO_PAGO = 'GO CUOTAS' THEN 0 ELSE m.ACTIVO END,
           m.ORDEN, m.ID
    FROM dbo.RO_T_CASHFLOW_VENTAS_MIX m
    WHERE m.CANAL <> 'ECOMMERCE'
      AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO n WHERE n.CANAL = m.CANAL);

    PRINT CAST(@@ROWCOUNT AS VARCHAR(10)) + ' nodo(s) migrado(s) en Locales, Franquicias y Mayoristas.';

    /* ---- Ecommerce: Vtex y debajo sus medios ------------------------------ */
    IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO WHERE CANAL = 'ECOMMERCE')
    BEGIN
        PRINT 'Ecommerce ya tiene nodos: no se toco.';
    END
    ELSE IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX WHERE CANAL = 'ECOMMERCE')
    BEGIN
        PRINT 'Ecommerce no tiene mix plano: no se creo Vtex. Cargalo desde Parametros.';
    END
    ELSE
    BEGIN
        DECLARE @vtex INT;

        /* Vtex al 100% y SIN dias: el plazo lo siguen poniendo sus medios, y
           el porcentaje efectivo de cada medio queda igual (100% x medio). */
        INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO
            (CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION,
             ACTIVO, ORDEN, ID_MIX_ORIGEN)
        SELECT 'ECOMMERCE', NULL, 'MARKETPLACE', 'Vtex', 1, NULL, NULL, NULL,
               1, MIN(ORDEN), NULL
        FROM dbo.RO_T_CASHFLOW_VENTAS_MIX
        WHERE CANAL = 'ECOMMERCE';

        SET @vtex = SCOPE_IDENTITY();

        INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO
            (CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION,
             ACTIVO, ORDEN, ID_MIX_ORIGEN)
        SELECT m.CANAL, @vtex, 'MEDIO_PAGO',
               CASE m.MEDIO_PAGO
               WHEN 'CASH'          THEN 'Efectivo'
               WHEN 'TARJETA'       THEN 'Tarjeta'
               WHEN 'GO CUOTAS'     THEN 'Go Cuotas'
               WHEN 'TRANSFERENCIA' THEN 'Transferencia'
               WHEN 'ECHEQ'         THEN 'Echeq'
               ELSE UPPER(LEFT(m.MEDIO_PAGO, 1)) + LOWER(SUBSTRING(m.MEDIO_PAGO, 2, 50))
           END,
               m.PORCENTAJE, NULL, NULL, m.DIAS_ACREDITACION,
               CASE WHEN m.MEDIO_PAGO = 'GO CUOTAS' THEN 0 ELSE m.ACTIVO END,
               m.ORDEN, m.ID
        FROM dbo.RO_T_CASHFLOW_VENTAS_MIX m
            WHERE m.CANAL = 'ECOMMERCE';

        PRINT 'Ecommerce: Vtex y ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + ' medio(s) migrado(s).';
    END

    COMMIT TRANSACTION;
END
GO

PRINT 'Listo.';
GO
