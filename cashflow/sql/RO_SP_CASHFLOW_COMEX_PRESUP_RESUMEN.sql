/* ============================================================================
   RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN
   ----------------------------------------------------------------------------
   Base    : central
   Origen  : [XL-APPS].POWER_BI_CONTROL (linked server desde XL-TANGO)
   Destino : RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN y
             RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE (misma base)
   Log     : RO_T_CASHFLOW_JOB_LOG, PROCESO = 'COMEX_PRESUP_RESUMEN'
   Requiere: sql/cashflow_comex_materializado.sql

   El presupuesto oficial de la app de compras, ya resumido por pais y
   temporada, y el contraste de la vista contra sus tablas de origen. Es lo
   que ComprasProyectadasDatos::versionesOficiales() y contrasteVista() leian
   en cada pedido desde POWER_BI_CONTROL.

   POR QUE DESDE CENTRAL Y NO UN SP EN POWER_BI_CONTROL
   ----------------------------------------------------
   Hay linked server de XL-TANGO a XL-APPS -el mismo que usa la curva de dolar
   futuro- y el resumen por ahi tarda entre 60 y 200 ms. Con el SP en central,
   el cashflow lee UNA base y la pestana deja de abrir conexiones a
   POWER_BI_CONTROL en cada pedido (hoy abre cinco). Verificado el 24/09/2026:
   el resumen por linked server da los mismos importes, filas y versiones que
   la lectura directa, temporada por temporada.

   LA MISMA REGLA QUE LA LECTURA EN VIVO
   -------------------------------------
     solo el tramo objetivo       es_objetivo = 1
     FOB U$S                      compra x costo_prom
     filas sin costo              se cuentan aparte, no se multiplican por cero
     inc_fob                      se lee y se guarda SOLO para el contraste de
                                  la pantalla; el porcentaje que se aplica es
                                  compras_proy_nac_pct
   Ver la seccion 2 de README-compras-proyectadas.md.

   COMO REEMPLAZA LAS TABLAS
   -------------------------
   Lo remoto se trae PRIMERO a temporales, fuera de cualquier transaccion: asi
   la transaccion del reemplazo es local y no necesita MSDTC, y una caida del
   linked server corta la corrida antes de tocar nada. Despues, en UNA
   transaccion, DELETE + INSERT de las dos tablas. Quien lee durante la
   corrida ve el presupuesto anterior entero.

   SIN VISTA NO SE PISA NADA. Si la vista no existe en POWER_BI_CONTROL, la
   corrida termina con error en el log y el presupuesto anterior queda: es
   preferible un presupuesto de ayer, que la pantalla avisa como viejo, a uno
   vacio que deja las filas en cero.

   UN RESUMEN SIN FILAS SI SE GRABA. A diferencia de la historia, "ninguna
   version oficial" es un estado valido de la app de compras -recien empieza
   una temporada, o se dio de baja la oficial-, y la pestana lo muestra mes por
   mes como SIN_PRESUPUESTO. Grabar el vacio es decir la verdad.

   Parametros (opcionales):
     @Usuario  quien lo pidio, para el log. NULL -> el login de la sesion.
   ============================================================================ */

CREATE OR ALTER PROCEDURE dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN
    @Usuario VARCHAR(128) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Proceso VARCHAR(60) = 'COMEX_PRESUP_RESUMEN';
    DECLARE @IdLog INT, @Filas INT = 0, @Lock INT;

    SET @Usuario = ISNULL(NULLIF(LTRIM(RTRIM(@Usuario)), ''), SUSER_SNAME());

    INSERT INTO dbo.RO_T_CASHFLOW_JOB_LOG (PROCESO, INICIO, USUARIO)
    VALUES (@Proceso, GETDATE(), @Usuario);

    SET @IdLog = SCOPE_IDENTITY();

    EXEC @Lock = sp_getapplock @Resource = 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN',
                               @LockMode = 'Exclusive', @LockOwner = 'Session',
                               @LockTimeout = 0;

    IF @Lock < 0
    BEGIN
        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(),
            ERROR = N'Ya hay otra corrida en curso; esta no hizo nada.'
        WHERE ID = @IdLog;

        RETURN;
    END

    BEGIN TRY
        /* 1. LA VISTA TIENE QUE EXISTIR. OBJECT_ID no cruza servidores, asi que
              se pregunta al catalogo remoto. */
        IF NOT EXISTS (SELECT 1 FROM [XL-APPS].POWER_BI_CONTROL.sys.views
                       WHERE name = 'RO_V_COMPRA_PROYECTADA_VIGENTE')
            THROW 50002, 'No existe RO_V_COMPRA_PROYECTADA_VIGENTE en POWER_BI_CONTROL. La crea el bloque 4 de presupuestos/sql/05_baja_logica_versiones.sql en el repo de compras. Se deja el presupuesto anterior.', 1;

        /* 2. EL RESUMEN POR PAIS Y TEMPORADA, a una temporal. */
        CREATE TABLE #RES (
            PAIS                  VARCHAR(50)   COLLATE DATABASE_DEFAULT NOT NULL,
            TEMPORADA             VARCHAR(30)   COLLATE DATABASE_DEFAULT NOT NULL,
            ID_VERSION            INT           NOT NULL,
            NOMBRE                VARCHAR(255)  COLLATE DATABASE_DEFAULT NULL,
            SOLAPA                VARCHAR(255)  COLLATE DATABASE_DEFAULT NULL,
            FECHA_CALCULO_VERSION DATE          NULL,
            TRAMOS_ESTADO         VARCHAR(100)  COLLATE DATABASE_DEFAULT NULL,
            TEMPORADA_DESDE       DATE          NULL,
            TEMPORADA_HASTA       DATE          NULL,
            FILAS                 INT           NOT NULL,
            FILAS_SIN_COSTO       INT           NOT NULL,
            UNIDADES              DECIMAL(18,2) NOT NULL,
            UNIDADES_SIN_COSTO    DECIMAL(18,2) NOT NULL,
            FOB_USD               DECIMAL(18,4) NOT NULL,
            NAC_SEGUN_INC_FOB     DECIMAL(18,4) NOT NULL,
            DEFICIT_COBERTURA     DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #RES
        SELECT
            v.pais,
            v.temporada_codigo,
            MAX(v.id_version),
            MAX(v.nombre_presupuesto),
            MAX(v.solapa),
            MAX(v.fecha_calculo),
            MAX(v.tramos_estado),
            MIN(v.temporada_desde),
            MAX(v.temporada_hasta),
            COUNT(*),
            SUM(CASE WHEN v.costo_prom IS NULL THEN 1 ELSE 0 END),
            SUM(ISNULL(v.compra, 0)),
            SUM(CASE WHEN v.costo_prom IS NULL THEN ISNULL(v.compra, 0) ELSE 0 END),
            SUM(ISNULL(v.compra, 0) * ISNULL(v.costo_prom, 0)),
            SUM(ISNULL(v.compra, 0) * ISNULL(v.costo_prom, 0) * ISNULL(v.inc_fob, 0) / 100.0),
            SUM(ISNULL(v.compra_deficit_cobertura, 0))
        FROM [XL-APPS].POWER_BI_CONTROL.dbo.RO_V_COMPRA_PROYECTADA_VIGENTE v
        WHERE v.es_objetivo = 1
        GROUP BY v.pais, v.temporada_codigo;

        /* 3. EL CONTRASTE: lo que devuelve la vista contra lo que hay en sus
              tablas. La diferencia es el filtro propio de la vista -hoy los
              rubros de cuero- y existe para que no sea invisible. */
        CREATE TABLE #VISTA (
            PAIS        VARCHAR(50)   COLLATE DATABASE_DEFAULT NOT NULL,
            FILAS_VISTA INT           NOT NULL,
            FOB_VISTA   DECIMAL(18,4) NOT NULL
        );

        INSERT INTO #VISTA
        SELECT v.pais, COUNT(*), SUM(ISNULL(v.compra, 0) * ISNULL(v.costo_prom, 0))
        FROM [XL-APPS].POWER_BI_CONTROL.dbo.RO_V_COMPRA_PROYECTADA_VIGENTE v
        WHERE v.es_objetivo = 1
        GROUP BY v.pais;

        CREATE TABLE #TABLAS (
            PAIS         VARCHAR(50)   COLLATE DATABASE_DEFAULT NOT NULL,
            FILAS_TABLAS INT           NOT NULL,
            FOB_TABLAS   DECIMAL(18,4) NOT NULL
        );

        INSERT INTO #TABLAS
        SELECT c.pais, COUNT(*), SUM(ISNULL(t.compra, 0) * ISNULL(d.costo_prom, 0))
        FROM [XL-APPS].POWER_BI_CONTROL.dbo.RO_T_HISTORIAL_COMPRAS_PROYECTADAS_CABECERA c
        JOIN [XL-APPS].POWER_BI_CONTROL.dbo.RO_T_HISTORIAL_COMPRAS_PROYECTADAS_PRESUPUESTO d
            ON d.id_cabecera = c.id
        JOIN [XL-APPS].POWER_BI_CONTROL.dbo.RO_T_HISTORIAL_COMPRAS_PROYECTADAS_TRAMO t
            ON t.id_detalle = d.id
        WHERE c.es_oficial = 1 AND c.eliminada = 0 AND t.es_objetivo = 1
        GROUP BY c.pais;

        /* 4. EL REEMPLAZO, con todo ya traido. */
        DECLARE @Ahora DATETIME = GETDATE();

        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN;

            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN
                (PAIS, TEMPORADA, ID_VERSION, NOMBRE, SOLAPA, FECHA_CALCULO_VERSION,
                 TRAMOS_ESTADO, TEMPORADA_DESDE, TEMPORADA_HASTA, FILAS, FILAS_SIN_COSTO,
                 UNIDADES, UNIDADES_SIN_COSTO, FOB_USD, NAC_SEGUN_INC_FOB, DEFICIT_COBERTURA,
                 FECHA_CALCULO)
            SELECT PAIS, LTRIM(RTRIM(TEMPORADA)), ID_VERSION, NOMBRE, SOLAPA,
                   FECHA_CALCULO_VERSION, TRAMOS_ESTADO, TEMPORADA_DESDE, TEMPORADA_HASTA,
                   FILAS, FILAS_SIN_COSTO, UNIDADES, UNIDADES_SIN_COSTO, FOB_USD,
                   NAC_SEGUN_INC_FOB, DEFICIT_COBERTURA, @Ahora
            FROM #RES;

            SET @Filas = @@ROWCOUNT;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE;

            /* Un pais con tablas y sin nada en la vista tambien va: es
               justamente el caso en que la vista filtra TODO. */
            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE
                (PAIS, FILAS_VISTA, FOB_VISTA, FILAS_TABLAS, FOB_TABLAS, FECHA_CALCULO)
            SELECT ISNULL(t.PAIS, v.PAIS),
                   ISNULL(v.FILAS_VISTA, 0), ISNULL(v.FOB_VISTA, 0),
                   ISNULL(t.FILAS_TABLAS, 0), ISNULL(t.FOB_TABLAS, 0),
                   @Ahora
            FROM #TABLAS t
            FULL OUTER JOIN #VISTA v ON v.PAIS = t.PAIS;

        COMMIT TRANSACTION;

        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(), FILAS = @Filas
        WHERE ID = @IdLog;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        DECLARE @Error NVARCHAR(4000) = ERROR_MESSAGE();

        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(), ERROR = @Error
        WHERE ID = @IdLog;

        EXEC sp_releaseapplock @Resource = 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN', @LockOwner = 'Session';

        THROW;
    END CATCH

    EXEC sp_releaseapplock @Resource = 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN', @LockOwner = 'Session';
END;
GO

/* ============================================================================
   PROGRAMACION SUGERIDA PARA EL SQL AGENT (el job lo crea quien administra la
   base; este script NO lo crea)

   Job      : CASHFLOW - Presupuesto de compras Comex
   Servidor : XL-TANGO, base LAKER_SA
   Paso 1   : T-SQL
                EXEC dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN;
   Frecuencia, dos programaciones en el mismo job:
     - DIARIA a las 05:00, con el de la historia.
     - Cada 30 minutos de 08:00 a 20:00, lunes a viernes. Tarda menos de un
       segundo, y es lo que hace que una version marcada oficial a media
       manana llegue al tablero sin esperar al dia siguiente.

   AL MARCAR UNA OFICIAL. Lo ideal es que la app de compras, despues de marcar
   una version como oficial, arranque el job:

       EXEC msdb.dbo.sp_start_job @job_name = N'CASHFLOW - Presupuesto de compras Comex';

   Eso vive en el repo de compras y requiere que su login pueda arrancar jobs
   en XL-TANGO (rol SQLAgentOperatorRole en msdb). Mientras no exista, la
   programacion cada 30 minutos acota la demora, y la pestana avisa cuando hay
   una version oficial mas nueva que el ultimo calculo.

   OJO CON EL LOGIN DEL LINKED SERVER. Corriendo desde el Agent, la consulta a
   [XL-APPS] sale con el mapeo de login que tenga el linked server para la
   cuenta del servicio del Agent (o del owner del job). Si ese mapeo no existe
   el job falla con "Login failed" y queda en RO_T_CASHFLOW_JOB_LOG.ERROR; la
   pestana muestra ese error.

   -- Ejemplo, para adaptar:
   -- EXEC msdb.dbo.sp_add_job @job_name = N'CASHFLOW - Presupuesto de compras Comex';
   -- EXEC msdb.dbo.sp_add_jobstep @job_name = N'CASHFLOW - Presupuesto de compras Comex',
   --      @step_name = N'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN', @subsystem = N'TSQL',
   --      @database_name = N'LAKER_SA', @command = N'EXEC dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN;';
   -- EXEC msdb.dbo.sp_add_jobschedule @job_name = N'CASHFLOW - Presupuesto de compras Comex',
   --      @name = N'Diaria 05:00', @freq_type = 4, @freq_interval = 1,
   --      @active_start_time = 050000;
   -- EXEC msdb.dbo.sp_add_jobschedule @job_name = N'CASHFLOW - Presupuesto de compras Comex',
   --      @name = N'Cada 30 min habiles', @freq_type = 8, @freq_interval = 62,
   --      @freq_recurrence_factor = 1, @freq_subday_type = 4, @freq_subday_interval = 30,
   --      @active_start_time = 080000, @active_end_time = 200000;
   -- EXEC msdb.dbo.sp_add_jobserver @job_name = N'CASHFLOW - Presupuesto de compras Comex';
   ============================================================================ */
