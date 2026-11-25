/* ============================================================================
   MODULO CASHFLOW - PRESTAMOS: PARAMETROS, PRESTAMOS, CORRECCIONES Y
   CANCELACIONES (script 70)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : independiente. Va antes de sql/cashflow_prestamos_fila.sql (72),
            que es el que hace entrar los prestamos al tablero.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_PRESTAMO_PARAM         Parametros > Prestamos, los generales:
                                          dias del promedio de la tasa, antiguedad
                                          tolerada, margen de seguridad, condiciones
                                          del simulador y comision de cancelacion
     RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO   los costos asociados (ABM)
     RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO   los tramos de la comision escalonada
     RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL   el valor proyectado a mano por tasa y mes
     RO_T_CASHFLOW_PRESTAMO               el prestamo, con sus condiciones y la
                                          comision de cancelacion COPIADA
     RO_T_CASHFLOW_PRESTAMO_COSTO         los costos COPIADOS al prestamo
     RO_T_CASHFLOW_PRESTAMO_TRAMO         los tramos COPIADOS al prestamo
     RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE  las correcciones a mano de una cuota
     RO_T_CASHFLOW_PRESTAMO_CANCELACION   las cancelaciones anticipadas

   LOS PARAMETROS SON LOS GENERALES: se copian al prestamo al darlo de alta, y
   un cambio posterior en Parametros NO toca a los prestamos que ya existen.
   Por eso los costos, los tramos y la comision viven tambien en el prestamo.

   EL CRONOGRAMA NO SE GUARDA: lo calcula PrestamosCalculo cada vez. Solo se
   guardan las correcciones y las cancelaciones, con historial.

   AUDITORIA COMO EN EL RESTO: USUARIO_ALTA/MODIF/BAJA y sus fechas, y nunca
   una baja fisica (VIGENTE o ACTIVO en 0).

   ES REEJECUTABLE: cada tabla con IF OBJECT_ID ... IS NULL, la siembra con
   NOT EXISTS. No borra ni modifica nada existente.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. PARAMETROS GENERALES: una fila vigente por clave, con historial
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_PARAM', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_PARAM (
        ID              INT IDENTITY(1,1) NOT NULL,
        CLAVE           VARCHAR(30)   NOT NULL,
        VALOR           VARCHAR(30)   NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRPAR_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPAR_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPAR_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_PARAM PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PRPAR_CLAVE CHECK (CLAVE IN (
            'DIAS_PROMEDIO', 'ANTIGUEDAD_MAX', 'MARGEN',
            'SIM_SISTEMA', 'SIM_CUOTAS', 'SIM_FRECUENCIA', 'SIM_TNA',
            'CANCEL_MODO', 'CANCEL_PCT_TOTAL', 'CANCEL_PCT_PARCIAL', 'CANCEL_IVA'))
    );

    CREATE UNIQUE INDEX UX_RO_T_CF_PRPAR_VIGENTE ON dbo.RO_T_CASHFLOW_PRESTAMO_PARAM (CLAVE) WHERE VIGENTE = 1;

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_PARAM.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_PARAM ya existe: no se toca.';
GO

/* ============================================================================
   2. COSTOS ASOCIADOS (ABM). ACTIVO = 1 es "se copia al dar de alta un
      prestamo". No hay bajas fisicas: se inactivan.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO (
        ID              INT IDENTITY(1,1) NOT NULL,
        NOMBRE          VARCHAR(100)  NOT NULL,
        PCT             DECIMAL(9,4)  NOT NULL,
        BASE            VARCHAR(10)   NOT NULL,
        MOMENTO         VARCHAR(10)   NOT NULL,
        LLEVA_IVA       BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRPCO_IVA DEFAULT (0),
        ACTIVO          BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRPCO_ACTIVO DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPCO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPCO_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PRPCO_PCT CHECK (PCT >= 0 AND PCT <= 100),
        CONSTRAINT CK_RO_T_CF_PRPCO_BASE CHECK (BASE IN ('CAPITAL', 'INTERESES')),
        CONSTRAINT CK_RO_T_CF_PRPCO_MOMENTO CHECK (MOMENTO IN ('INICIAL', 'POR_CUOTA')),
        CONSTRAINT CK_RO_T_CF_PRPCO_INICIAL CHECK (NOT (MOMENTO = 'INICIAL' AND BASE = 'INTERESES'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO ya existe: no se toca.';
GO

/* ============================================================================
   3. TRAMOS DE LA COMISION ESCALONADA: desde que % del plazo transcurrido,
      que % para la total y para la parcial. Se guardan como juego: guardar
      da de baja los vigentes y da de alta los nuevos, en una transaccion.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO (
        ID              INT IDENTITY(1,1) NOT NULL,
        DESDE_PCT       DECIMAL(7,2)  NOT NULL,
        PCT_TOTAL       DECIMAL(9,4)  NOT NULL,
        PCT_PARCIAL     DECIMAL(9,4)  NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRPTR_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPTR_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRPTR_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PRPTR_VALORES CHECK (DESDE_PCT >= 0 AND DESDE_PCT < 100
            AND PCT_TOTAL >= 0 AND PCT_PARCIAL >= 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO ya existe: no se toca.';
GO

/* ============================================================================
   4. TASA PROYECTADA A MANO: una vigente por tasa y mes. Gana sobre el
      promedio para los periodos que empiezan ese mes.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL (
        ID              INT IDENTITY(1,1) NOT NULL,
        REFERENCIA      VARCHAR(10)   NOT NULL,
        MES             CHAR(7)       NOT NULL,
        VALOR           DECIMAL(9,4)  NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRTAS_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRTAS_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRTAS_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PRTAS_REF CHECK (REFERENCIA IN ('TAMAR', 'BADLAR')),
        CONSTRAINT CK_RO_T_CF_PRTAS_VALOR CHECK (VALOR >= 0 AND VALOR <= 1000)
    );

    CREATE UNIQUE INDEX UX_RO_T_CF_PRTAS_VIGENTE ON dbo.RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL (REFERENCIA, MES) WHERE VIGENTE = 1;

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL ya existe: no se toca.';
GO

/* ============================================================================
   5. EL PRESTAMO. ESTADO guarda solo SIMULADO o VIGENTE: Cancelado y
      Finalizado se calculan (una cancelacion total ya ocurrida, o el ultimo
      pago ya pasado), y no se escribe nada al leer. VIGENTE = 0 es la baja de
      un simulado eliminado; un vigente no se elimina, se cancela.

      CUENTA_CLAVE / CUENTA_NOMBRE: la cuenta del desplegable de Saldos
      (Interbanking o el catalogo). Informativo en esta etapa.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO (
        ID                  INT IDENTITY(1,1) NOT NULL,
        NOMBRE              VARCHAR(150)  NOT NULL,
        CUENTA_CLAVE        VARCHAR(80)   NOT NULL,
        CUENTA_NOMBRE       VARCHAR(150)  NOT NULL,
        MONEDA              CHAR(3)       NOT NULL,
        CAPITAL             DECIMAL(18,2) NOT NULL,
        FECHA_DESEMBOLSO    DATE          NOT NULL,
        TIPO_TASA           VARCHAR(10)   NOT NULL,
        TNA                 DECIMAL(9,4)  NULL,
        REFERENCIA          VARCHAR(10)   NULL,
        SPREAD              DECIMAL(9,4)  NULL,
        CRITERIO_TASA       VARCHAR(10)   NOT NULL CONSTRAINT DF_RO_T_CF_PRE_CRITERIO DEFAULT ('PROMEDIO'),
        SISTEMA             VARCHAR(10)   NOT NULL,
        CUOTAS              SMALLINT      NOT NULL,
        FREC_CAPITAL        VARCHAR(12)   NOT NULL,
        FREC_INTERESES      VARCHAR(12)   NOT NULL,
        FECHA_PRIMERA       DATE          NULL,
        GRACIA              SMALLINT      NOT NULL CONSTRAINT DF_RO_T_CF_PRE_GRACIA DEFAULT (0),
        FORMA_PAGO          VARCHAR(15)   NULL,
        CANCEL_MODO         VARCHAR(10)   NOT NULL CONSTRAINT DF_RO_T_CF_PRE_CMODO DEFAULT ('FIJA'),
        CANCEL_PCT_TOTAL    DECIMAL(9,4)  NOT NULL CONSTRAINT DF_RO_T_CF_PRE_CTOT DEFAULT (0),
        CANCEL_PCT_PARCIAL  DECIMAL(9,4)  NOT NULL CONSTRAINT DF_RO_T_CF_PRE_CPAR DEFAULT (0),
        CANCEL_IVA          BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRE_CIVA DEFAULT (1),
        ESTADO              VARCHAR(10)   NOT NULL,
        OBSERVACION         VARCHAR(300)  NULL,
        VIGENTE             BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRE_VIGENTE DEFAULT (1),
        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRE_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRE_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_PRE_MONEDA CHECK (MONEDA IN ('ARS', 'USD')),
        CONSTRAINT CK_RO_T_CF_PRE_CAPITAL CHECK (CAPITAL > 0),
        CONSTRAINT CK_RO_T_CF_PRE_TASA CHECK (
            (TIPO_TASA = 'FIJA' AND TNA IS NOT NULL AND TNA >= 0)
            OR (TIPO_TASA = 'VARIABLE' AND REFERENCIA IN ('TAMAR', 'BADLAR') AND SPREAD IS NOT NULL)),
        CONSTRAINT CK_RO_T_CF_PRE_CRITERIO CHECK (CRITERIO_TASA IN ('PROMEDIO', 'ULTIMO')),
        CONSTRAINT CK_RO_T_CF_PRE_SISTEMA CHECK (SISTEMA IN ('FRANCES', 'ALEMAN', 'BULLET')),
        CONSTRAINT CK_RO_T_CF_PRE_FREC CHECK (FREC_CAPITAL IN ('MENSUAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL')
            AND FREC_INTERESES IN ('MENSUAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL')),
        CONSTRAINT CK_RO_T_CF_PRE_FRANCES CHECK (SISTEMA <> 'FRANCES' OR FREC_CAPITAL = FREC_INTERESES),
        CONSTRAINT CK_RO_T_CF_PRE_CUOTAS CHECK (CUOTAS > 0 AND GRACIA >= 0 AND GRACIA < CUOTAS),
        CONSTRAINT CK_RO_T_CF_PRE_FORMA CHECK (FORMA_PAGO IS NULL OR FORMA_PAGO IN ('DEBITO', 'TRANSFERENCIA')),
        CONSTRAINT CK_RO_T_CF_PRE_CANCEL CHECK (CANCEL_MODO IN ('FIJA', 'ESCALONADA')
            AND CANCEL_PCT_TOTAL >= 0 AND CANCEL_PCT_PARCIAL >= 0),
        CONSTRAINT CK_RO_T_CF_PRE_ESTADO CHECK (ESTADO IN ('SIMULADO', 'VIGENTE'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO ya existe: no se toca.';
GO

/* ============================================================================
   6. LOS COSTOS Y LOS TRAMOS DEL PRESTAMO (copiados de Parametros)
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_COSTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_COSTO (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PRESTAMO     INT           NOT NULL,
        ID_COSTO_PARAM  INT           NULL,
        NOMBRE          VARCHAR(100)  NOT NULL,
        PCT             DECIMAL(9,4)  NOT NULL,
        BASE            VARCHAR(10)   NOT NULL,
        MOMENTO         VARCHAR(10)   NOT NULL,
        LLEVA_IVA       BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRCO_IVA DEFAULT (0),
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRCO_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRCO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRCO_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_COSTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_PRCO_PRESTAMO FOREIGN KEY (ID_PRESTAMO) REFERENCES dbo.RO_T_CASHFLOW_PRESTAMO (ID),
        CONSTRAINT CK_RO_T_CF_PRCO_PCT CHECK (PCT >= 0 AND PCT <= 100),
        CONSTRAINT CK_RO_T_CF_PRCO_BASE CHECK (BASE IN ('CAPITAL', 'INTERESES')),
        CONSTRAINT CK_RO_T_CF_PRCO_MOMENTO CHECK (MOMENTO IN ('INICIAL', 'POR_CUOTA')),
        CONSTRAINT CK_RO_T_CF_PRCO_INICIAL CHECK (NOT (MOMENTO = 'INICIAL' AND BASE = 'INTERESES'))
    );

    CREATE INDEX IX_RO_T_CF_PRCO_PRESTAMO ON dbo.RO_T_CASHFLOW_PRESTAMO_COSTO (ID_PRESTAMO) WHERE VIGENTE = 1;

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_COSTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_COSTO ya existe: no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_TRAMO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_TRAMO (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PRESTAMO     INT           NOT NULL,
        DESDE_PCT       DECIMAL(7,2)  NOT NULL,
        PCT_TOTAL       DECIMAL(9,4)  NOT NULL,
        PCT_PARCIAL     DECIMAL(9,4)  NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRTR_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRTR_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRTR_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_TRAMO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_PRTR_PRESTAMO FOREIGN KEY (ID_PRESTAMO) REFERENCES dbo.RO_T_CASHFLOW_PRESTAMO (ID),
        CONSTRAINT CK_RO_T_CF_PRTR_VALORES CHECK (DESDE_PCT >= 0 AND DESDE_PCT < 100
            AND PCT_TOTAL >= 0 AND PCT_PARCIAL >= 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_TRAMO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_TRAMO ya existe: no se toca.';
GO

/* ============================================================================
   7. CORRECCIONES POR CUOTA: una vigente por (prestamo, cuota, concepto).
      CONCEPTO: CAPITAL, INTERES, TNA o COSTO:<ID de RO_T_CASHFLOW_PRESTAMO_COSTO>.
      Deshacer = VIGENTE 0. Pisar = baja de la vigente y alta, en una
      transaccion.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PRESTAMO     INT           NOT NULL,
        CUOTA           SMALLINT      NOT NULL,
        CONCEPTO        VARCHAR(20)   NOT NULL,
        IMPORTE         DECIMAL(18,4) NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRAJ_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRAJ_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRAJ_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_PRAJ_PRESTAMO FOREIGN KEY (ID_PRESTAMO) REFERENCES dbo.RO_T_CASHFLOW_PRESTAMO (ID),
        CONSTRAINT CK_RO_T_CF_PRAJ_CUOTA CHECK (CUOTA > 0),
        CONSTRAINT CK_RO_T_CF_PRAJ_CONCEPTO CHECK (CONCEPTO IN ('CAPITAL', 'INTERES', 'TNA') OR CONCEPTO LIKE 'COSTO:%'),
        CONSTRAINT CK_RO_T_CF_PRAJ_IMPORTE CHECK (IMPORTE >= 0)
    );

    CREATE UNIQUE INDEX UX_RO_T_CF_PRAJ_VIGENTE ON dbo.RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE (ID_PRESTAMO, CUOTA, CONCEPTO) WHERE VIGENTE = 1;

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE ya existe: no se toca.';
GO

/* ============================================================================
   8. CANCELACIONES. Una sola total vigente por prestamo. Deshacer = VIGENTE 0,
      y solo mientras la fecha no paso (lo controla el codigo).
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PRESTAMO_CANCELACION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_PRESTAMO_CANCELACION (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PRESTAMO     INT           NOT NULL,
        FECHA           DATE          NOT NULL,
        TIPO            VARCHAR(10)   NOT NULL,
        MONTO           DECIMAL(18,2) NULL,
        MODO            VARCHAR(15)   NULL,
        OBSERVACION     VARCHAR(300)  NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_PRCA_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRCA_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_PRCA_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_PRESTAMO_CANCELACION PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_PRCA_PRESTAMO FOREIGN KEY (ID_PRESTAMO) REFERENCES dbo.RO_T_CASHFLOW_PRESTAMO (ID),
        CONSTRAINT CK_RO_T_CF_PRCA_TIPO CHECK (
            (TIPO = 'TOTAL' AND MONTO IS NULL AND MODO IS NULL)
            OR (TIPO = 'PARCIAL' AND MONTO > 0 AND MODO IN ('REDUCIR_CUOTA', 'REDUCIR_PLAZO')))
    );

    CREATE UNIQUE INDEX UX_RO_T_CF_PRCA_TOTAL ON dbo.RO_T_CASHFLOW_PRESTAMO_CANCELACION (ID_PRESTAMO) WHERE VIGENTE = 1 AND TIPO = 'TOTAL';

    PRINT 'Creada dbo.RO_T_CASHFLOW_PRESTAMO_CANCELACION.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_PRESTAMO_CANCELACION ya existe: no se toca.';
GO

/* ============================================================================
   9. SIEMBRA: los generales y los cinco costos de la especificacion
   ============================================================================ */
INSERT INTO dbo.RO_T_CASHFLOW_PRESTAMO_PARAM (CLAVE, VALOR, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
SELECT S.CLAVE, S.VALOR, 1, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql'
FROM (VALUES ('DIAS_PROMEDIO', '5'), ('ANTIGUEDAD_MAX', '5'), ('MARGEN', '10'),
             ('SIM_SISTEMA', 'FRANCES'), ('SIM_CUOTAS', '24'), ('SIM_FRECUENCIA', 'MENSUAL'), ('SIM_TNA', '38'),
             ('CANCEL_MODO', 'FIJA'), ('CANCEL_PCT_TOTAL', '3'), ('CANCEL_PCT_PARCIAL', '3'), ('CANCEL_IVA', '1')) S (CLAVE, VALOR)
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PRESTAMO_PARAM G WHERE G.CLAVE = S.CLAVE AND G.VIGENTE = 1);

PRINT CONCAT('Generales de Prestamos sembrados: ', @@ROWCOUNT, ' de 11.');
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO)
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO (NOMBRE, PCT, BASE, MOMENTO, LLEVA_IVA, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
    VALUES
        ('Comisión de otorgamiento',         0.0000, 'CAPITAL',   'INICIAL',   0, 1, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql'),
        ('Impuesto de sellos',               1.2000, 'CAPITAL',   'INICIAL',   0, 1, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql'),
        ('IVA sobre intereses',             10.5000, 'INTERESES', 'POR_CUOTA', 0, 1, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql'),
        ('Ingresos Brutos sobre intereses',  7.0000, 'INTERESES', 'POR_CUOTA', 0, 0, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql'),
        ('Comisión de aval SGR',             2.0000, 'CAPITAL',   'INICIAL',   1, 0, 'SISTEMA:cashflow_prestamos.sql', 'SISTEMA:cashflow_prestamos.sql');

    PRINT 'Costos asociados sembrados: 5 (3 activos).';
END
ELSE
    PRINT 'Ya hay costos asociados cargados: no se siembra nada.';
GO

/* ---- Control ---- */
SELECT CLAVE, VALOR FROM dbo.RO_T_CASHFLOW_PRESTAMO_PARAM WHERE VIGENTE = 1 ORDER BY CLAVE;
SELECT ID, NOMBRE, PCT, BASE, MOMENTO, LLEVA_IVA, ACTIVO FROM dbo.RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO ORDER BY ID;
GO
