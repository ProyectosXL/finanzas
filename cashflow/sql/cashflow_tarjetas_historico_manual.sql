/* ============================================================================
   MODULO CASHFLOW - GASTOS SUPERVISORAS: EL HISTORICO MANUAL
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_tarjetas.sql. No tiene FK -la persona es un
            usuario de RO_V_CASHFLOW_USUARIOS_TARJETAS, que es una vista- pero sin
            el maestro de tarjetas no hay a quien cargarle un historico.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

     RO_T_CASHFLOW_TARJETAS_HIST_MANUAL   el gasto mensual de los ultimos 3
                                          meses de una persona que no carga
                                          gastos en la app de supervision

   Gastos Supervisoras estima con RO_T_GASTOS_SUPERVISION. Quien tiene una
   tarjeta de tipo Supervisora y no carga gastos ahi -una persona del equipo de
   ventas, por ejemplo- no proyectaria nada. Con este historico se proyecta igual
   que una supervisora: promedio de los 3 meses, todo con tarjeta, inflacion
   compuesta, cobertura y debito en su tarjeta.

   ----------------------------------------------------------------------------
   LA PERSONA ES EL USUARIO DE LA VISTA, NO EL NOMBRE

   ID_USUARIO es el de RO_V_CASHFLOW_USUARIOS_TARJETAS, el mismo que lleva su
   tarjeta. No se cruza por nombre contra RO_T_SUPERVISORAS_COMERCIAL: quien
   carga un historico manual justamente no esta ahi. El nombre se guarda como
   copia, para que la fila siga siendo legible si la persona sale de la vista.

   CUANDO LA PERSONA EMPIEZA A TENER GASTOS, MANDAN LOS GASTOS: el historico deja
   de usarse, queda vigente como historia y la pantalla lo avisa.

   ----------------------------------------------------------------------------
   SIN BAJAS FISICAS Y CON AUDITORIA COMPLETA

   Volver a cargar el historico da de baja el anterior (VIGENTE = 0, con quien y
   cuando) y graba el nuevo, en una transaccion. Lo escribe siempre el backend
   con el usuario de la sesion.

   IMPORTE >= 0: un mes en cero es un dato -ese mes no gasto- y se divide igual
   por 3, como en las supervisoras. Que el total sea mayor a cero lo valida el
   backend.

   ES REEJECUTABLE: todo pregunta por OBJECT_ID o por sys.indexes y no se
   siembra ninguna fila. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_TARJETAS. Corre primero sql/cashflow_tarjetas.sql. No se creo nada.', 16, 1);
END
GO

/* ============================================================================
   1. RO_T_CASHFLOW_TARJETAS_HIST_MANUAL
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL (
        ID              INT IDENTITY(1,1) NOT NULL,

        /* El usuario de RO_V_CASHFLOW_USUARIOS_TARJETAS, el de su tarjeta. */
        ID_USUARIO      INT           NOT NULL,

        /* Copia, con la forma de la columna NOMBRE de la vista. */
        NOMBRE_USUARIO  NVARCHAR(200) COLLATE Modern_Spanish_CI_AI NOT NULL,

        /* El mes del gasto, 'YYYY-MM', como los meses de inflacion. */
        MES             CHAR(7)       NOT NULL,

        /* Todo con tarjeta: no hay parte en efectivo. */
        IMPORTE         DECIMAL(18,2) NOT NULL,

        VIGENTE         BIT           NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJHM_VIGENTE DEFAULT (1),

        USUARIO_ALTA    VARCHAR(50)   NULL,
        FECHA_ALTA      DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJHM_ALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NOT NULL
            CONSTRAINT DF_RO_T_CF_TARJHM_MODIF DEFAULT (GETDATE()),
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL,

        CONSTRAINT PK_RO_T_CASHFLOW_TARJETAS_HIST_MANUAL PRIMARY KEY CLUSTERED (ID),

        CONSTRAINT CK_RO_T_CF_TARJHM_MES
            CHECK (MES LIKE '[12][0-9][0-9][0-9]-[01][0-9]'),

        CONSTRAINT CK_RO_T_CF_TARJHM_IMPORTE
            CHECK (IMPORTE >= 0),

        CONSTRAINT CK_RO_T_CF_TARJHM_NOMBRE
            CHECK (LEN(LTRIM(RTRIM(NOMBRE_USUARIO))) > 0)
    );

    PRINT 'Creada dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL.';
END
ELSE IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL', 'U') IS NOT NULL
    PRINT 'dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL ya existe: no se toca.';
GO

/* UN IMPORTE VIGENTE POR PERSONA Y MES. Filtrado: el historial son las bajas. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_TARJHM_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL'))
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_TARJHM_VIGENTE
        ON dbo.RO_T_CASHFLOW_TARJETAS_HIST_MANUAL (ID_USUARIO, MES)
        WHERE VIGENTE = 1;

    PRINT 'Creado UX_RO_T_CF_TARJHM_VIGENTE (un importe por persona y mes).';
END
GO
