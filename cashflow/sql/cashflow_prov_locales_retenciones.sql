/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES: RETENCIONES IMPOSITIVAS
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_prov_locales.sql. No depende de ningun
             otro; sin el, la pantalla funciona como antes -proyecta el total- y
             avisa que falta.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   A una factura que se paga por ORDEN DE PAGO -echeq o transferencia, la
   forma vigente del cronograma- se le retienen impuestos: hoy IIBB, Ganancias
   y SUSS. Lo que sale de la caja es el NETO, y es lo que quien arma la planilla
   de pagos pone como importe. El cashflow proyectaba el total.

   POR QUE NO SE LEEN DE TANGO: Tango calcula las retenciones EN la orden de
   pago, y cuando se hace la orden de pago la factura se cancela y sale del
   pendiente. Para el flujo ese calculo llega tarde. Por eso las alicuotas se
   cargan aca.

   ----------------------------------------------------------------------------
   1. RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO

   QUE impuestos se retienen. Es una tabla y no una constante del codigo porque
   un impuesto nuevo -o uno que deja de retenerse- es una decision de
   administracion, no un despliegue.

   ALCANCE dice a quien se le aplica:
       TODOS      tiene una alicuota GENERAL con vigencias, que rige para todo
                  proveedor salvo que el proveedor tenga la suya (incluido 0).
       ASIGNADOS  no tiene general: se aplica SOLO a los proveedores que tengan
                  una alicuota cargada para el.

   EL CODIGO NO SE EDITA: es lo que guardan las alicuotas en CONCEPTO.
   Cambiarlo dejaria huerfano el historial. El nombre si.

   BAJA LOGICA: un impuesto con ACTIVO = 0 deja de aplicarse en el calculo
   desde ese momento, pero sus vigencias y sus asignaciones quedan, y
   reactivarlo las vuelve a poner en juego.

   ----------------------------------------------------------------------------
   2. RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA

   Las alicuotas GENERALES de los impuestos "a todos". Es la MISMA forma que
   RO_T_CASHFLOW_COBEL_ALICUOTA y RO_T_CASHFLOW_ECHEQ_ALICUOTA -concepto,
   alicuota, vigencia, activo- porque la regla de que alicuota rige a que fecha
   vive una sola vez, en Class/Retenciones.php, y recibe estas filas tal cual.
   Una tabla propia y no filas en las otras: un cambio en la tasa del banco no
   puede mover el neto de una factura, ni al reves.

   NO SE EDITA: se inserta una vigencia nueva. La anterior queda y sigue
   rigiendo para las fechas de pago anteriores. NO HAY UNIQUE (CONCEPTO,
   VIGENCIA_DESDE) a proposito: corregir dos veces el mismo dia es lo que pasa
   cuando alguien se equivoca al tipear, y gana la insertada despues.

   ----------------------------------------------------------------------------
   3. RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV

   La alicuota PROPIA de un proveedor para un impuesto. Se cargan desde el
   Maestro, de a muchos o de a uno. La importacion del maestro NO las toca:
   viven aca y no en el maestro, que es una copia reimportable de la planilla.

   ALICUOTA NULL NO ES 0:
       0      "a este proveedor no se le retiene" (en un impuesto "a todos")
       NULL   "sin dato": vuelve a la general, o deja de estar asignado
   Volver a la general es UN REGISTRO CON SU FECHA, no un borrado: el valor
   anterior sigue explicando las fechas de pago anteriores.

   VIGENCIA_DESDE LA PONE EL SERVIDOR: el dia de la carga. Dos cargas el mismo
   dia se desempatan por ID -gana la ultima-, igual que las generales.

   SIN BAJAS FISICAS NI UPDATE: la tabla es su propio historial.

   ----------------------------------------------------------------------------
   LAS ALICUOTAS SON FRACCIONES: 2,5% se guarda 0,025, como en las otras dos
   tablas de alicuotas del modulo.

   LA SEMILLA: IIBB e GANANCIAS "a todos" y SUSS "solo a los asignados", SIN
   ALICUOTAS: los porcentajes los carga administracion en Parametros -> Prov.
   Locales. Mientras un impuesto "a todos" no tenga general vigente, no se
   retiene y la pestana lo avisa: una retencion de 0 inventada informaria un
   neto que nadie decidio.

   EL DIA QUE SE CORRA ESTO EL TABLERO NO SE MUEVE NI UN PESO: no hay ninguna
   alicuota cargada.

   ES REEJECUTABLE: las tablas se crean si faltan, y la semilla es un MERGE
   sobre el codigo que solo inserta lo que falta. Un impuesto renombrado,
   reordenado, con otro alcance o dado de baja NO se pisa ni se reactiva.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. LOS IMPUESTOS
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* Lo que guardan las alicuotas en CONCEPTO: mismo largo que esa
           columna. No se edita. */
        CODIGO         VARCHAR(30)   NOT NULL,
        NOMBRE         VARCHAR(60)   NOT NULL,
        ALCANCE        VARCHAR(10)   NOT NULL,
        ORDEN          INT           NOT NULL
            CONSTRAINT DF_RO_T_CF_PLIMP_ORDEN DEFAULT (0),
        ACTIVO         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_PLIMP_ACTIVO DEFAULT (1),

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLIMP_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)   NULL,
        FECHA_MODIF    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLIMP_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA   VARCHAR(50)   NULL,
        FECHA_BAJA     DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO PRIMARY KEY CLUSTERED (ID),
        /* El codigo es la clave con la que se cruzan las alicuotas: dos
           impuestos con el mismo codigo se repartirian el historial. */
        CONSTRAINT UQ_RO_T_CF_PLIMP_CODIGO UNIQUE (CODIGO),
        /* Tiene que coincidir con ProveedoresRetenciones::ALCANCES. */
        CONSTRAINT CK_RO_T_CF_PLIMP_ALCANCE CHECK (ALCANCE IN ('TODOS', 'ASIGNADOS'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   2. LAS ALICUOTAS GENERALES
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* El CODIGO del impuesto. */
        CONCEPTO       VARCHAR(30)   NOT NULL,
        /* Fraccion: 2,5% es 0,025. */
        ALICUOTA       DECIMAL(9,6)  NOT NULL,
        VIGENCIA_DESDE DATE          NOT NULL,
        ACTIVO         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_PLALI_ACTIVO DEFAULT (1),

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLALI_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF  VARCHAR(50)   NULL,
        FECHA_MODIF    DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLALI_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA   VARCHAR(50)   NULL,
        FECHA_BAJA     DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA PRIMARY KEY CLUSTERED (ID),
        /* Una alicuota sola nunca puede llegar a 1. La suma la valida PHP. */
        CONSTRAINT CK_RO_T_CF_PLALI_RANGO CHECK (ALICUOTA >= 0 AND ALICUOTA < 1)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLALI_VIGENCIA
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA (CONCEPTO, VIGENCIA_DESDE DESC, ID DESC)
        INCLUDE (ALICUOTA, ACTIVO);

    PRINT 'Creada dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA. No se siembra ninguna alicuota.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   3. LAS ALICUOTAS DE CADA PROVEEDOR
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV (
        ID             INT IDENTITY(1,1) NOT NULL,
        /* La collation de CPA01: OGNUNE y OGNUÑE son dos proveedores. Ver
           sql/cashflow_prov_locales_collation.sql. */
        COD_PROVEE     VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        CONCEPTO       VARCHAR(30)   NOT NULL,
        /* NULL = sin dato (vuelve a la general o deja de estar asignado).
           0 = no se le retiene. Ver el encabezado. */
        ALICUOTA       DECIMAL(9,6)  NULL,
        /* El dia de la carga, puesto por el servidor. */
        VIGENCIA_DESDE DATE          NOT NULL,
        /* De que gesto salio: la asignacion masiva desde la grilla o la
           edicion de un proveedor. Lo muestra el historial. */
        ORIGEN         VARCHAR(10)   NOT NULL,

        USUARIO_ALTA   VARCHAR(50)   NULL,
        FECHA_ALTA     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_PLALP_ALTA DEFAULT (GETDATE()),

        CONSTRAINT PK_RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PLALP_RANGO CHECK (ALICUOTA IS NULL OR (ALICUOTA >= 0 AND ALICUOTA < 1)),
        CONSTRAINT CK_RO_T_CF_PLALP_ORIGEN CHECK (ORIGEN IN ('MASIVA', 'INDIVIDUAL'))
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLALP_PROVEEDOR
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV (COD_PROVEE, CONCEPTO, VIGENCIA_DESDE DESC, ID DESC)
        INCLUDE (ALICUOTA);

    PRINT 'Creada dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV.';
END
ELSE
BEGIN
    PRINT 'dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV ya existe: no se toca.';
END
GO

/* ----------------------------------------------------------------------------
   4. LA SEMILLA: los tres impuestos, sin alicuotas.

   MERGE SOLO CON WHEN NOT MATCHED: lo que ya esta no se toca. Un nombre, un
   orden o un alcance editados, y una baja, son decisiones que la semilla no
   revierte.

   QUIEN LOS CARGO: 'SISTEMA:' + el nombre del script. No es NULL porque NULL
   significa en este modulo "fila historica, de antes de la auditoria"
   (AuthCashflow::ORIGEN_SISTEMA), y estas filas no lo son: las puso este
   script, y el historial tiene que poder decirlo.
   ---------------------------------------------------------------------------- */
MERGE dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO AS t
USING (VALUES
    ('IIBB',      'Ingresos Brutos', 'TODOS',     1),
    ('GANANCIAS', 'Ganancias',       'TODOS',     2),
    ('SUSS',      'SUSS',            'ASIGNADOS', 3)
) AS s (CODIGO, NOMBRE, ALCANCE, ORDEN)
ON t.CODIGO = s.CODIGO
WHEN NOT MATCHED BY TARGET THEN
    INSERT (CODIGO, NOMBRE, ALCANCE, ORDEN, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
    VALUES (s.CODIGO, s.NOMBRE, s.ALCANCE, s.ORDEN, 1,
            'SISTEMA:cashflow_prov_locales_retenciones.sql',
            'SISTEMA:cashflow_prov_locales_retenciones.sql');

PRINT 'Semilla de impuestos: ' + CAST(@@ROWCOUNT AS VARCHAR(10)) + ' nuevo(s).';
GO

/* ----------------------------------------------------------------------------
   5. Control: como quedaron.
   ---------------------------------------------------------------------------- */
SELECT i.CODIGO, i.NOMBRE, i.ALCANCE, i.ORDEN, i.ACTIVO,
       (SELECT COUNT(*) FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA a
         WHERE a.CONCEPTO = i.CODIGO AND a.ACTIVO = 1) AS VIGENCIAS_GENERALES
FROM dbo.RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO i
ORDER BY i.ORDEN, i.CODIGO;
GO

PRINT 'Retenciones de Proveedores Locales listas. Las alicuotas se cargan en Parametros -> Prov. Locales.';
GO
