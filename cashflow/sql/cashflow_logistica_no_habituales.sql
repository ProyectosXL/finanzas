/* ============================================================================
   MODULO CASHFLOW - LOGISTICA LOCAL: FLETEROS NO HABITUALES Y EL IMPORTE DEL
   PROXIMO PAGO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_logistica_fleteros.sql (el maestro) y de
            sql/cashflow_auditoria_usuario.sql (las columnas de auditoria del
            maestro). Si el maestro no existe, el script corta.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   1. HABITUAL en RO_T_CASHFLOW_LOGISTICA_FLETEROS: BIT NOT NULL, DEFAULT 1,
      WITH VALUES. TODOS LOS FLETEROS EXISTENTES QUEDAN COMO HABITUALES, asi que
      apenas se corre Logistica proyecta EXACTAMENTE lo mismo que antes.

        habitual      proyecta horas x valor hora, segun su cronograma (como hoy)
        no habitual   no proyecta por calculo: solo sus pagos puntuales

      Pasar a un fletero a no habitual NO borra sus horas, su valor hora ni su
      mes base: se conservan y dejan de proyectar. Si vuelve a habitual, vuelve a
      proyectar con esos datos.

   2. RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL: los pagos de un fletero no habitual.
      Importe y fecha; puede tener mas de uno. La fecha va DE MANANA EN ADELANTE
      -lo valida el PHP, porque "hoy" es del servidor de la aplicacion-: lo que
      se paga hoy ya esta en el saldo bancario que abre el tablero. No se corre
      al dia habil. Un pago cuya fecha ya paso deja de proyectarse y queda en el
      historial. Sin bajas fisicas: VIGENTE = 0.

   3. RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT: el importe REAL del proximo pago de
      un fletero habitual, cargado a mano. Vale SOLO para ese pago -los
      siguientes se siguen calculando-. La clave es (fletero, mes, numero de
      pago), la misma identidad que los overrides de fecha del cronograma: si un
      feriado nuevo corre la fecha, la edicion sigue pegada al mismo pago. Se
      guarda tambien la FECHA del pago y el IMPORTE CALCULADO del momento, que es
      contra lo que se compara. Deshacer = VIGENTE = 0, no DELETE.

      Cambiar el dia o la frecuencia del cronograma de Logistica da de baja las
      ediciones vigentes desde el mes actual, igual que los overrides: con otro
      dia, el numero de pago apunta a otra fecha.

   LAS DOS TABLAS LLEVAN LAS SEIS COLUMNAS DE AUDITORIA. Se graba el username
   de la sesion; sin usuario, el PHP rechaza la escritura.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.

   SI NO SE CORRE

   Logistica Local funciona como hoy: todos los fleteros son habituales y se
   proyectan igual. La pantalla avisa que falta el script, no ofrece marcar a
   nadie como no habitual ni cargar pagos puntuales, y la celda del proximo
   pago no se edita.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda: sin el maestro no hay a que colgarle nada.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS. Corre primero sql/cashflow_logistica_fleteros.sql.', 16, 1);
    SET NOEXEC ON;
END
GO

/* ============================================================================
   1. HABITUAL
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS', 'HABITUAL') IS NULL
BEGIN
    /* WITH VALUES: las filas existentes toman el 1 al crear la columna. Sin
       eso quedarian en NULL, y NOT NULL no lo permite. */
    ALTER TABLE dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS
        ADD HABITUAL BIT NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGFLE_HABITUAL DEFAULT (1) WITH VALUES;

    PRINT 'Agregada HABITUAL: todos los fleteros existentes quedaron como habituales.';
END
ELSE
    PRINT 'HABITUAL ya existia.';
GO

/* ============================================================================
   2. RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL (
        ID              INT IDENTITY(1,1) NOT NULL,
        COD_PROVEE      VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        /* El dia en que se paga. No se corre al habil: lo cargo alguien que
           sabe cuando se paga. */
        FECHA           DATE          NOT NULL,

        /* Final, sin IVA ni otros conceptos, como todo Logistica. */
        IMPORTE         DECIMAL(18,2) NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGPP_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGPP_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGPP_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_LOGPP_FLETERO FOREIGN KEY (COD_PROVEE)
            REFERENCES dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS (COD_PROVEE),

        /* Un pago de cero no es un pago: se da de baja. */
        CONSTRAINT CK_RO_T_CF_LOGPP_IMPORTE CHECK (IMPORTE > 0)
    );

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_LOGPP_VIGENTE
        ON dbo.RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL (VIGENTE, COD_PROVEE)
        INCLUDE (FECHA, IMPORTE);

    PRINT 'RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL ya existia.';
GO

/* ============================================================================
   3. RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT (
        ID                 INT IDENTITY(1,1) NOT NULL,
        COD_PROVEE         VARCHAR(6)    COLLATE Latin1_General_BIN NOT NULL,

        /* La identidad del pago: el mes del cronograma y su numero. */
        MES                CHAR(7)       NOT NULL,
        NRO_PAGO           TINYINT       NOT NULL,

        /* La fecha del pago al editarlo, y lo que daba la cuenta en ese momento:
           es contra lo que se compara la edicion, y recalcularlo despues
           mostraria otra diferencia si en el medio cambio la inflacion. */
        FECHA_PAGO         DATE          NOT NULL,
        IMPORTE_CALCULADO  DECIMAL(18,2) NULL,

        /* El importe real de ese pago. */
        IMPORTE            DECIMAL(18,2) NOT NULL,

        VIGENTE            BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGIE_VIGENTE DEFAULT (1),

        USUARIO_ALTA       VARCHAR(50)   NULL,
        FECHA_ALTA         DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGIE_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF      VARCHAR(50)   NULL,
        FECHA_MODIF        DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_LOGIE_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA       VARCHAR(50)   NULL,
        FECHA_BAJA         DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT PRIMARY KEY CLUSTERED (ID),
        CONSTRAINT FK_RO_T_CF_LOGIE_FLETERO FOREIGN KEY (COD_PROVEE)
            REFERENCES dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS (COD_PROVEE),
        CONSTRAINT CK_RO_T_CF_LOGIE_IMPORTE CHECK (IMPORTE > 0),
        CONSTRAINT CK_RO_T_CF_LOGIE_NRO CHECK (NRO_PAGO BETWEEN 1 AND 5),
        CONSTRAINT CK_RO_T_CF_LOGIE_MES
            CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]')
    );

    /* Una sola edicion vigente por pago: la segunda da de baja a la primera. */
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_LOGIE_VIGENTE
        ON dbo.RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT (COD_PROVEE, MES, NRO_PAGO)
        WHERE VIGENTE = 1;

    PRINT 'RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT creada.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT ya existia.';
GO

/* ============================================================================
   4. CONTROL: COMO QUEDO
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS', 'HABITUAL') IS NOT NULL
    EXEC ('SELECT HABITUAL, ACTIVO, COUNT(*) AS FLETEROS
           FROM dbo.RO_T_CASHFLOW_LOGISTICA_FLETEROS
           GROUP BY HABITUAL, ACTIVO ORDER BY HABITUAL DESC, ACTIVO DESC');
GO

SET NOEXEC OFF;
GO
