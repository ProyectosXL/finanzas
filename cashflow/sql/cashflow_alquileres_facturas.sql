/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: LAS FACTURAS REALES Y SU IMPUTACION
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : DESPUES de sql/cashflow_alquileres.sql (Parametros -> Alquileres).
             No es una dependencia tecnica -las tablas se crean igual- pero sin
             razones sociales dadas de alta no hay ninguna factura de alquiler.
             ANTES de sql/cashflow_alquileres_fila.sql: ver "LA CONDICION DE
             SEGURIDAD", abajo.
   ----------------------------------------------------------------------------
   QUE GUARDA

   Las facturas pendientes de Tango de los proveedores dados de alta en
   Parametros -> Alquileres se gestionan en la pestaña Alquileres -> Facturas.
   Las facturas NO SE COPIAN: salen de Proveedores::getPendientes(), la misma
   lectura de Tango que usa Proveedores Locales. Aca se guarda lo que el cashflow
   decide sobre ellas:

     RO_T_CASHFLOW_ALQ_FACTURA_VTO            el vencimiento a considerar, por
                                              cuota, cuando no es el de Tango
     RO_T_CASHFLOW_ALQ_IMPUTACION             la cabecera de una imputacion:
                                              UN GRUPO de facturas, de una o
                                              varias razones sociales
     RO_T_CASHFLOW_ALQ_IMP_FACTURA            las cuotas del grupo, con el
                                              pendiente al imputar, la retencion
                                              y si ya se pago en Tango
     RO_T_CASHFLOW_ALQ_IMP_RENGLON            los renglones: mes que cubre e
                                              importe con IVA y percepciones
     RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO   los conceptos de cada renglon
     RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL      los locales de cada renglon

   ----------------------------------------------------------------------------
   POR QUE LOS RENGLONES SON DEL GRUPO Y NO DE UNA FACTURA

   Una factura de Cencosud puede venir sin abrir por shopping: un renglon
   "concepto 8, todos los locales de Cencosud" reemplaza la estimacion de N
   locales con un solo importe. Y dos facturas de razones distintas pueden
   cubrir conceptos que se arman mejor juntos. La regla de cierre es del grupo:
   la suma de los renglones da el pendiente de las cuotas tildadas (+-$1). El
   cashflow reparte cada renglon entre las facturas por su pendiente, para que
   cada parte caiga en la fecha de su factura. Ver
   cashflow/Class/AlquileresImputacion.php.

   ----------------------------------------------------------------------------
   UNA CUOTA SE IMPUTA UNA SOLA VEZ

   El indice unico filtrado de IMP_FACTURA (VIGENTE = 1) lo garantiza contra dos
   pantallas guardando a la vez. Reimputar da de baja la imputacion anterior
   entera (VIGENTE = 0, MOTIVO_BAJA, ID_REEMPLAZO) e inserta la nueva, en una
   transaccion. Deshacer es la misma baja, sin una nueva. NO HAY BAJAS FISICAS:
   la que se dio de baja explica por que el tablero de ayer decia otra cosa.

   Los renglones, sus conceptos y sus locales NO SE EDITAN: son parte de la
   imputacion, y una imputacion distinta es otra fila. Por eso no tienen
   VIGENTE propio: valen lo que vale su cabecera.

   LOS LOCALES SE GUARDAN COMO ERAN AL IMPUTAR, aunque el renglon diga "todos
   los de la razon social": la factura es un hecho de ese momento, y un local que
   cambie de razon social despues no la cambia. TODOS_DE_RAZON queda solo para
   mostrar como se armo.

   ----------------------------------------------------------------------------
   PAGADA

   Cuando Tango registra el pago la cuota sale de pendientes. La imputacion NO
   se borra y sigue reemplazando la proyeccion del mes que cubre: si no, ese mes
   volveria a proyectarse como si no se hubiera pagado. ESTADO pasa a PAGADA con
   la fecha de cancelacion de Tango (FECHA_PAGADA) y cuando lo vio el cashflow
   (FECHA_DETECTADA), igual que la conciliacion de Proveedores Locales.

   ----------------------------------------------------------------------------
   LA CONDICION DE SEGURIDAD

   Las facturas de alquiler salen de Proveedores Locales SOLO si existe la
   cabecera de este script Y hay una fila activa del tablero con ALQUILERES /
   REAL o PAGOS. Sin este script se quedan en Proveedores Locales como siempre,
   y las pantallas lo avisan en critico. Ver cashflow/Class/AlquileresFacturas.php.

   EL DIA QUE SE CORRE, si la fila 16 del tablero sigue activa (ALQUILERES /
   PAGOS), las facturas pasan a Alquileres y quedan "sin imputar": no suman
   hasta que se imputen, y el aviso lo dice con el importe.

   ES REEJECUTABLE: cada tabla e indice pregunta si ya existe.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. El vencimiento a considerar, por cuota. Mismo patron que
      RO_T_CASHFLOW_TARJETAS_FACTURA_VTO.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* La cuota, con los tipos y la collation de CPA04. */
        COD_PROVEE      VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)   COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)  COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE         NOT NULL,

        /* El que vale. LA FECHA MINIMA ES HOY, y la valida el PHP. */
        FECHA_VTO       DATE         NOT NULL,
        MOTIVO          VARCHAR(300) NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_VIGENTE DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQVTO_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_FACTURA_VTO PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT CK_RO_T_CF_ALQVTO_CLAVE
            CHECK (LEN(LTRIM(RTRIM(COD_PROVEE))) > 0
               AND LEN(LTRIM(RTRIM(T_COMP)))     > 0
               AND LEN(LTRIM(RTRIM(N_COMP)))     > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQVTO_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQVTO_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_FACTURA_VTO (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;
GO

/* ----------------------------------------------------------------------------
   2. La cabecera de una imputacion. ES LA TABLA QUE MIRA LA CONDICION DE
      SEGURIDAD (AlquileresFacturas::TABLA_IMPUTACION): si cambia de nombre,
      cambia alla.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPUTACION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMPUTACION (
        ID              INT IDENTITY(1,1) NOT NULL,
        OBSERVACION     VARCHAR(300) NULL,

        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMP_VIGENTE DEFAULT (1),
        /* Por que se dio de baja: deshecha, o reemplazada por otra (y cual). */
        MOTIVO_BAJA     VARCHAR(200) NULL,
        ID_REEMPLAZO    INT          NULL,

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMP_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMP_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMPUTACION PRIMARY KEY CLUSTERED (ID)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMPUTACION.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMPUTACION ya existe: no se toca.';
GO

/* ----------------------------------------------------------------------------
   3. Las cuotas de cada imputacion.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_IMPUTACION   INT          NOT NULL,

        COD_PROVEE      VARCHAR(6)   COLLATE Latin1_General_BIN NOT NULL,
        T_COMP          VARCHAR(3)   COLLATE Latin1_General_BIN NOT NULL,
        N_COMP          VARCHAR(14)  COLLATE Latin1_General_BIN NOT NULL,
        FECHA_VTO_TANGO DATE         NOT NULL,

        /* EL PENDIENTE AL IMPUTAR: si Tango lo cambia sin cancelar la factura
           (nota de credito, pago parcial) se proyecta el nuevo y se avisa. */
        PENDIENTE       DECIMAL(18,2) NOT NULL,
        /* El neto gravado de la cuota que se uso para sugerir la retencion. */
        NETO_GRAVADO    DECIMAL(18,2) NULL,
        RETENCION       DECIMAL(18,2) NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPF_RET DEFAULT (0),
        RETENCION_SUGERIDA DECIMAL(18,2) NULL,

        /* Copia del VIGENTE de la cabecera, en la misma transaccion: es lo que
           permite el indice unico filtrado de abajo. */
        VIGENTE         BIT          NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPF_VIGENTE DEFAULT (1),

        ESTADO          VARCHAR(10)  NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPF_ESTADO DEFAULT ('PENDIENTE'),
        FECHA_PAGADA    DATE         NULL,
        FECHA_DETECTADA DATETIME     NULL,

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPF_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPF_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMP_FACTURA PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_ALQIMPF_IMP FOREIGN KEY (ID_IMPUTACION)
            REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMPUTACION (ID),
        CONSTRAINT CK_RO_T_CF_ALQIMPF_ESTADO CHECK (ESTADO IN ('PENDIENTE', 'PAGADA')),
        CONSTRAINT CK_RO_T_CF_ALQIMPF_RET CHECK (RETENCION >= 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA ya existe: no se toca.';
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_RO_T_CF_ALQIMPF_VIGENTE'
               AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ALQIMPF_VIGENTE
        ON dbo.RO_T_CASHFLOW_ALQ_IMP_FACTURA (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO)
        WHERE VIGENTE = 1;
GO

/* ----------------------------------------------------------------------------
   4. Los renglones.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_IMPUTACION   INT          NOT NULL,
        ORDEN           SMALLINT     NOT NULL,
        /* El MES DE PAGO cuya proyeccion reemplaza, 'YYYY-MM'. */
        MES_CUBRE       CHAR(7)      NOT NULL,
        /* Con IVA y percepciones, tal como viene en la factura. */
        IMPORTE         DECIMAL(18,2) NOT NULL,
        /* "Todos los locales de esta razon social", si se armo asi. Solo para
           mostrar: los locales que valen son los de _LOCAL. */
        TODOS_DE_RAZON  VARCHAR(6)   COLLATE Latin1_General_BIN NULL,

        USUARIO_ALTA    VARCHAR(50)  NULL,
        FECHA_ALTA      DATETIME     NOT NULL
            CONSTRAINT DF_RO_T_CF_ALQIMPR_ALTA DEFAULT (GETDATE()),

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMP_RENGLON PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_ALQIMPR_IMP FOREIGN KEY (ID_IMPUTACION)
            REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMPUTACION (ID),
        CONSTRAINT CK_RO_T_CF_ALQIMPR_MES CHECK (MES_CUBRE LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),
        CONSTRAINT CK_RO_T_CF_ALQIMPR_IMPORTE CHECK (IMPORTE > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON ya existe: no se toca.';
GO

/* ----------------------------------------------------------------------------
   5. Los conceptos de cada renglon: los que se proyectan (por su numero en la
      app, ID_CA), EXTRA (los pagos extraordinarios) y OTROS (no proyectados:
      suma en real y no reemplaza nada). LA LISTA DEL CHECK Y
      AlquileresImputacion::CONCEPTOS CAMBIAN JUNTAS.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO (
        ID_RENGLON      INT         NOT NULL,
        CONCEPTO        VARCHAR(5)  NOT NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO PRIMARY KEY CLUSTERED (ID_RENGLON, CONCEPTO),
        CONSTRAINT FK_RO_T_CF_ALQIMPC_REN FOREIGN KEY (ID_RENGLON)
            REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON (ID),
        CONSTRAINT CK_RO_T_CF_ALQIMPC_CONCEPTO
            CHECK (CONCEPTO IN ('1', '2', '3', '6', '7', '8', '9', '11', '14', '16', '17', 'EXTRA', 'OTROS'))
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO ya existe: no se toca.';
GO

/* ----------------------------------------------------------------------------
   6. Los locales de cada renglon (NRO_SUCURS de la app de alquileres).
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL (
        ID_RENGLON      INT         NOT NULL,
        NRO_LOCAL       INT         NOT NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL PRIMARY KEY CLUSTERED (ID_RENGLON, NRO_LOCAL),
        CONSTRAINT FK_RO_T_CF_ALQIMPL_REN FOREIGN KEY (ID_RENGLON)
            REFERENCES dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON (ID)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL.';
END
ELSE
    PRINT 'dbo.RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL ya existe: no se toca.';
GO

PRINT 'Facturas de alquiler listas. Las facturas de las razones dadas de alta pasan a la '
    + 'pestaña Alquileres cuando el tablero tenga una fila activa ALQUILERES / REAL o PAGOS.';
GO
