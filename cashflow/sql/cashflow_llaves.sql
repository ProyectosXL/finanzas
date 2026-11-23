/* ============================================================================
   MODULO CASHFLOW - LLAVES Y RENOVACIONES: PARAMETROS (script 66)
   ----------------------------------------------------------------------------
   Base   : central (la misma de la app de alquileres de administracion)
   Orden  : independiente de los de Alquileres para crearse, pero la pestaña
            necesita sql/cashflow_alquileres.sql (51) para saber el operador de
            cada local (la razon social principal de Parametros > Alquileres).
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_LLAVES_GENERAL     los generales: % de llave, cuotas, dia de
                                      pago de obras y aperturas, cortes de
                                      segmento por m2 y costos en U$S
     RO_T_CASHFLOW_LLAVES_OPERADOR    por operador (razon social de Alquileres):
                                      % de llave, % de otros gastos, escribano,
                                      % de impuestos, cuotas y corrimiento
     RO_T_CASHFLOW_LLAVES_LOCAL       por local y por el contrato que se renueva:
                                      no renueva, llave a mano, % propio, meses,
                                      cuotas, mes de la primera cuota
     RO_T_CASHFLOW_LLAVES_PAGADA      "Ya pagada": la llave o los otros gastos de
                                      una renovacion que ya salieron de caja
     RO_T_CASHFLOW_LLAVES_OBRA        las obras de renovacion de imagen
     RO_T_CASHFLOW_LLAVES_APERTURA    las aperturas de locales nuevos
     RO_T_CASHFLOW_LLAVES_COMPROMISO  lo pactado a mano: prendas, sellados,
                                      servicio comercial, cuotas acordadas

   EL CASHFLOW NO ESCRIBE EN LA APP DE ALQUILERES. Los contratos (vigencia,
   llave, comisiones, FPC) salen de RO_T_CONTRATOS_ALQUILERES y aca solo se
   guarda lo que la app no tiene.

   UNA FILA VIGENTE POR CLAVE, CON HISTORIAL. Cambiar un dato es dar de baja la
   fila vigente (VIGENTE = 0, con quien y cuando) y dar de alta otra, en una
   transaccion. Lo garantizan los indices unicos filtrados WHERE VIGENTE = 1.
   No hay bajas fisicas.

   UN DATO QUE FALTA ES NULL, NO 0. Un operador sin % de impuestos no proyecta
   su llave, y un escribano vacio es "sin dato": solo 0 cargado es 0.

   LO QUE SE PARAMETRIZA POR LOCAL CUELGA DEL CONTRATO QUE SE RENUEVA
   (ID_CONTRATO, el ID de RO_T_CONTRATOS_ALQUILERES). Asi un "no renueva" o un
   % propio cargado para la renovacion de 2027 no se aplica solo a la de 2030:
   cuando administracion carga el contrato nuevo, la renovacion siguiente es
   la de ese contrato y arranca sin parametros propios. "Ya pagada" usa la
   misma clave por el mismo motivo, y ademas sobrevive a que se cargue el
   contrato nuevo (Distrito Arcos y Abasto al 23/11/2026: ya pagaron la
   renovacion y el contrato todavia no esta en la app).

   SIEMBRA: SOLO LOS TRES DEFAULTS DE GENERALES (25 % de llave, 4 cuotas, dia
   15), y solo si la clave no tiene fila vigente. Los cortes de segmento se
   sugieren en pantalla con los m2 de hoy y los confirma una persona; los
   costos en U$S, los porcentajes por operador y el escribano los carga
   Tesoreria. Un valor sembrado que nadie decidio seria un numero inventado.

   COD_PROVEE lleva Latin1_General_BIN y el largo de CPA01, como el resto del
   circuito de proveedores.

   SIN ESTE SCRIPT la pestaña y Parametros > Llaves y Renovaciones cargan,
   avisan nombrando el archivo y no proyectan nada estimado; el resto del
   cashflow funciona como hoy.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes, y la siembra
   no repite filas. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ============================================================================
   1. GENERALES: una fila vigente por clave

   Clave y valor, con la lista de claves en un CHECK: once numeros que se
   cargan de a uno desde la misma pantalla no justifican once columnas, y con
   una tabla por clave el historial dice que cambio sin comparar filas enteras.
   Vaciar un valor es dar de baja su fila vigente sin dar de alta otra.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_GENERAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_GENERAL (
        ID              INT IDENTITY(1,1) NOT NULL,
        CLAVE           VARCHAR(30)   NOT NULL,
        VALOR           DECIMAL(18,2) NOT NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLGEN_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLGEN_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLGEN_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_GENERAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLGEN_CLAVE CHECK (CLAVE IN (
            'PCT_LLAVE', 'CUOTAS', 'DIA_PAGO', 'CORTE_CHICO', 'CORTE_MEDIANO',
            'USD_OBRA_CHICO', 'USD_OBRA_MEDIANO', 'USD_OBRA_GRANDE',
            'USD_APERTURA_CHICO', 'USD_APERTURA_MEDIANO', 'USD_APERTURA_GRANDE')),
        CONSTRAINT CK_RO_T_CF_LLGEN_VALOR CHECK (VALOR >= 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_GENERAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_GENERAL ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLGEN_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_GENERAL'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLGEN_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_GENERAL (CLAVE) WHERE VIGENTE = 1;
GO

/* ============================================================================
   2. POR OPERADOR

   El operador es una razon social DADA DE ALTA en Parametros > Alquileres (la
   principal del local). Cada columna vacia es "sin dato":
     PCT_LLAVE      vacio = el general
     PCT_OTROS      (comisiones + FPC) / llave; vacio = sin dato, avisa
     ESCRIBANO      $ por renovacion; vacio = sin dato, avisa; 0 es 0
     PCT_IMPUESTOS  lo que el operador factura de mas sobre la llave y los
                    otros gastos (IRSA x 1,31 -> 31). Vacio = sin dato: la
                    llave de ese operador NO se proyecta
     CUOTAS         vacio = el general
     CORRIMIENTO    meses respecto del inicio del contrato nuevo (negativo =
                    antes); vacio = 0
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_OPERADOR (
        ID              INT IDENTITY(1,1) NOT NULL,
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        PCT_LLAVE       DECIMAL(7,2)  NULL,
        PCT_OTROS       DECIMAL(7,2)  NULL,
        ESCRIBANO       DECIMAL(18,2) NULL,
        PCT_IMPUESTOS   DECIMAL(7,2)  NULL,
        CUOTAS          TINYINT       NULL,
        CORRIMIENTO     SMALLINT      NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLOPE_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLOPE_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLOPE_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_OPERADOR PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLOPE_VALORES CHECK (
            (PCT_LLAVE IS NULL OR PCT_LLAVE >= 0) AND (PCT_OTROS IS NULL OR PCT_OTROS >= 0)
            AND (ESCRIBANO IS NULL OR ESCRIBANO >= 0) AND (PCT_IMPUESTOS IS NULL OR PCT_IMPUESTOS >= 0)
            AND (CUOTAS IS NULL OR CUOTAS BETWEEN 1 AND 60)
            AND (CORRIMIENTO IS NULL OR CORRIMIENTO BETWEEN -24 AND 24))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_OPERADOR.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_OPERADOR ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLOPE_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLOPE_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_OPERADOR (COD_PROVEE) WHERE VIGENTE = 1;
GO

/* ============================================================================
   3. POR LOCAL, POR EL CONTRATO QUE SE RENUEVA

   ID_CONTRATO es el contrato vigente (el que vence) de RO_T_CONTRATOS_ALQUILERES.
   Vacio en cualquier columna = el valor de arriba (operador o general).
   NO_RENUEVA lleva motivo obligatorio. MES_PRIMERA_CUOTA es 'YYYY-MM'.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_LOCAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_LOCAL (
        ID                  INT IDENTITY(1,1) NOT NULL,
        NRO_SUCURS          INT           NOT NULL,
        ID_CONTRATO         INT           NOT NULL,
        NO_RENUEVA          BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLLOC_NORENUEVA DEFAULT (0),
        MOTIVO_NO_RENUEVA   VARCHAR(300)  NULL,
        LLAVE_MANUAL        DECIMAL(18,2) NULL,
        PCT_LLAVE           DECIMAL(7,2)  NULL,
        MESES               SMALLINT      NULL,
        CUOTAS              TINYINT       NULL,
        MES_PRIMERA_CUOTA   CHAR(7)       NULL,
        VIGENTE             BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLLOC_VIGENTE DEFAULT (1),
        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLLOC_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLLOC_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_LOCAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLLOC_MOTIVO CHECK (NO_RENUEVA = 0 OR LEN(LTRIM(RTRIM(ISNULL(MOTIVO_NO_RENUEVA, '')))) > 0),
        CONSTRAINT CK_RO_T_CF_LLLOC_VALORES CHECK (
            (LLAVE_MANUAL IS NULL OR LLAVE_MANUAL >= 0) AND (PCT_LLAVE IS NULL OR PCT_LLAVE >= 0)
            AND (MESES IS NULL OR MESES BETWEEN 1 AND 240) AND (CUOTAS IS NULL OR CUOTAS BETWEEN 1 AND 60)
            AND (MES_PRIMERA_CUOTA IS NULL OR MES_PRIMERA_CUOTA LIKE '[12][0-9][0-9][0-9]-[01][0-9]'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_LOCAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_LOCAL ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLLOC_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_LOCAL'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLLOC_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_LOCAL (NRO_SUCURS, ID_CONTRATO) WHERE VIGENTE = 1;
GO

/* ============================================================================
   4. YA PAGADA

   La llave (LLAVE) o los otros gastos (OTROS) de una renovacion que ya
   salieron de caja: corta lo proyectado de ese concepto en esa renovacion, sea
   del contrato cargado o estimado. ID_CONTRATO_ANT es el contrato QUE SE
   RENUEVA, no el nuevo: la marca sigue valiendo cuando administracion carga
   el contrato nuevo en la app. No suma en el horizonte: ya se pago.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_PAGADA (
        ID                  INT IDENTITY(1,1) NOT NULL,
        NRO_SUCURS          INT           NOT NULL,
        ID_CONTRATO_ANT     INT           NOT NULL,
        CONCEPTO            VARCHAR(10)   NOT NULL,
        FECHA               DATE          NOT NULL,
        MONTO               DECIMAL(18,2) NOT NULL,
        COMPROBANTE         VARCHAR(40)   NULL,
        OBSERVACION         VARCHAR(300)  NULL,
        VIGENTE             BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLPAG_VIGENTE DEFAULT (1),
        USUARIO_ALTA        VARCHAR(50)   NULL,
        FECHA_ALTA          DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLPAG_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF       VARCHAR(50)   NULL,
        FECHA_MODIF         DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLPAG_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA        VARCHAR(50)   NULL,
        FECHA_BAJA          DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_PAGADA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLPAG_CONCEPTO CHECK (CONCEPTO IN ('LLAVE', 'OTROS')),
        CONSTRAINT CK_RO_T_CF_LLPAG_MONTO CHECK (MONTO > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_PAGADA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_PAGADA ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLPAG_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLPAG_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_PAGADA (NRO_SUCURS, ID_CONTRATO_ANT, CONCEPTO) WHERE VIGENTE = 1;
GO

/* ============================================================================
   5. OBRAS DE RENOVACION DE IMAGEN

   Una por local y mes (un local puede tener mas de una, en meses distintos).
   SEGMENTO vacio = el que dan sus m2 contra los cortes; COSTO_USD vacio = el
   del segmento. No se editan: baja y alta, para que el historial explique los
   meses ya proyectados.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OBRA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_OBRA (
        ID              INT IDENTITY(1,1) NOT NULL,
        NRO_SUCURS      INT           NOT NULL,
        MES             CHAR(7)       NOT NULL,
        SEGMENTO        VARCHAR(10)   NULL,
        COSTO_USD       DECIMAL(18,2) NULL,
        OBSERVACION     VARCHAR(300)  NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLOBR_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLOBR_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLOBR_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_OBRA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLOBR_MES CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),
        CONSTRAINT CK_RO_T_CF_LLOBR_SEGMENTO CHECK (SEGMENTO IS NULL OR SEGMENTO IN ('CHICO', 'MEDIANO', 'GRANDE')),
        CONSTRAINT CK_RO_T_CF_LLOBR_COSTO CHECK (COSTO_USD IS NULL OR COSTO_USD > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_OBRA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_OBRA ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_LLOBR_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_OBRA'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LLOBR_VIGENTE
        ON dbo.RO_T_CASHFLOW_LLAVES_OBRA (NRO_SUCURS, MES) WHERE VIGENTE = 1;
GO

/* ============================================================================
   6. APERTURAS DE LOCALES NUEVOS

   Alta manual: el local todavia no existe en el sistema. NRO_SUCURS se carga
   cuando exista (vincular es baja y alta). El segmento es obligatorio: no hay
   m2 de donde sacarlo.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_APERTURA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_APERTURA (
        ID              INT IDENTITY(1,1) NOT NULL,
        NOMBRE          VARCHAR(100)  NOT NULL,
        NRO_SUCURS      INT           NULL,
        MES             CHAR(7)       NOT NULL,
        SEGMENTO        VARCHAR(10)   NOT NULL,
        COSTO_USD       DECIMAL(18,2) NULL,
        OBSERVACION     VARCHAR(300)  NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLAPE_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLAPE_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLAPE_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_APERTURA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLAPE_NOMBRE CHECK (LEN(LTRIM(RTRIM(NOMBRE))) > 0),
        CONSTRAINT CK_RO_T_CF_LLAPE_MES CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),
        CONSTRAINT CK_RO_T_CF_LLAPE_SEGMENTO CHECK (SEGMENTO IN ('CHICO', 'MEDIANO', 'GRANDE')),
        CONSTRAINT CK_RO_T_CF_LLAPE_COSTO CHECK (COSTO_USD IS NULL OR COSTO_USD > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_APERTURA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_APERTURA ya existe: no se toca.';
GO

/* ============================================================================
   7. COMPROMISOS A MANO

   Lo ya pactado que no sale de la app: prendas, sellados, servicio
   comercial, cuotas acordadas. TIPO_OPERADOR dice de donde es COD_PROVEE: una
   razon social de Alquileres (ALQUILERES) o un proveedor del maestro de
   Proveedores Locales (PROVEEDOR). Una serie de N cuotas son N filas con el
   mismo ID_SERIE (el ID de la primera), para poder darla de baja entera.
   FORMA_PAGO es informativa.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_COMPROMISO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LLAVES_COMPROMISO (
        ID              INT IDENTITY(1,1) NOT NULL,
        TIPO_OPERADOR   VARCHAR(12)   NOT NULL,
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,
        NRO_SUCURS      INT           NULL,
        CONCEPTO        VARCHAR(100)  NOT NULL,
        FECHA           DATE          NOT NULL,
        MONTO           DECIMAL(18,2) NOT NULL,
        FORMA_PAGO      VARCHAR(15)   NULL,
        OBSERVACION     VARCHAR(300)  NULL,
        ID_SERIE        INT           NULL,
        CUOTA_NRO       SMALLINT      NULL,
        CUOTAS          SMALLINT      NULL,
        VIGENTE         BIT           NOT NULL CONSTRAINT DF_RO_T_CF_LLCOM_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLCOM_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL CONSTRAINT DF_RO_T_CF_LLCOM_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_LLAVES_COMPROMISO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_LLCOM_TIPO CHECK (TIPO_OPERADOR IN ('ALQUILERES', 'PROVEEDOR')),
        CONSTRAINT CK_RO_T_CF_LLCOM_CONCEPTO CHECK (LEN(LTRIM(RTRIM(CONCEPTO))) > 0),
        CONSTRAINT CK_RO_T_CF_LLCOM_MONTO CHECK (MONTO > 0),
        CONSTRAINT CK_RO_T_CF_LLCOM_FORMA CHECK (FORMA_PAGO IS NULL
            OR FORMA_PAGO IN ('ECHEQ', 'TRANSFERENCIA', 'VALORES', 'AL_DIA'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_LLAVES_COMPROMISO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_LLAVES_COMPROMISO ya existe: no se toca.';
GO

/* ============================================================================
   8. SIEMBRA: solo los tres defaults de Generales
   ============================================================================ */
INSERT INTO dbo.RO_T_CASHFLOW_LLAVES_GENERAL (CLAVE, VALOR, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
SELECT S.CLAVE, S.VALOR, 1, 'SISTEMA:cashflow_llaves.sql', 'SISTEMA:cashflow_llaves.sql'
FROM (VALUES ('PCT_LLAVE', 25.00), ('CUOTAS', 4.00), ('DIA_PAGO', 15.00)) S (CLAVE, VALOR)
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_LLAVES_GENERAL G WHERE G.CLAVE = S.CLAVE AND G.VIGENTE = 1);

PRINT CONCAT('Generales de Llaves sembrados: ', @@ROWCOUNT, ' de 3 (25 % de llave, 4 cuotas, día 15). Nada más se siembra.');
GO

/* ---- Control: lo vigente de Generales ---- */
SELECT CLAVE, VALOR, USUARIO_ALTA, FECHA_ALTA FROM dbo.RO_T_CASHFLOW_LLAVES_GENERAL WHERE VIGENTE = 1 ORDER BY CLAVE;
GO
