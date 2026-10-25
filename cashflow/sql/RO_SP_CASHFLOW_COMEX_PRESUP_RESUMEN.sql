/* ============================================================================
   RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN
   ----------------------------------------------------------------------------
   Base    : central
   Origen  : [XL-APPS].POWER_BI_CONTROL (linked server desde XL-TANGO)
   Destino : RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN,
             RO_T_CASHFLOW_COMEX_PRESUP_RUBRO y
             RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE (misma base)
   Lee     : RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT (misma base), el % importado
   Log     : RO_T_CASHFLOW_JOB_LOG, PROCESO = 'COMEX_PRESUP_RESUMEN'
   Requiere: sql/cashflow_comex_materializado.sql y
             sql/cashflow_compras_proy_rubro_pct.sql, en ese orden. Sin la
             columna FOB_PRESUPUESTO_USD este script ni siquiera crea el SP.

   El presupuesto oficial de la app de compras, ya resumido por pais y
   temporada con el % importado de cada rubro aplicado, el mismo presupuesto
   por rubro, y el contraste de la vista contra sus tablas de origen. Es lo
   que ComprasProyectadasDatos::versionesOficiales() y contrasteVista() leian
   en cada pedido desde POWER_BI_CONTROL.

   EL % IMPORTADO POR RUBRO
   ------------------------
   La vista ya no filtra rubros: devuelve todo el presupuesto. Que parte de
   cada rubro se compra en el exterior lo dice RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT,
   y se aplica FILA POR FILA, ANTES DE SUMAR:

       FOB del rubro x % importado / 100

   a FOB_USD, UNIDADES, UNIDADES_SIN_COSTO, NAC_SEGUN_INC_FOB y
   DEFICIT_COBERTURA. Un rubro que no esta en la tabla entra al 100 % y queda
   CONFIGURADO = 0 en el detalle por rubro; el SP no lo da de alta. El cruce
   compara el rubro normalizado (mayusculas, sin espacios de mas). Ver la
   seccion 7 de README-compras-proyectadas.md.

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
     FOB U$S                      compra x costo_prom x % importado / 100
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
   transaccion, DELETE + INSERT de las tres tablas. Quien lee durante la
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
     @Usuario  quien lo pidio: el username si lo pide una persona desde la
               pantalla, 'JOB:COMEX_PRESUP' desde el job. Vacio ->
               'JOB:RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN'. Va al log y a cada fila.
   ============================================================================ */

CREATE OR ALTER PROCEDURE dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN
    @Usuario VARCHAR(128) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Proceso VARCHAR(60) = 'COMEX_PRESUP_RESUMEN';
    DECLARE @IdLog INT, @Filas INT = 0, @Lock INT;

    /* QUIEN LO PIDIO, y nunca el login de la sesion: SUSER_SNAME() devolvia la
       cuenta del servicio del Agent o la del pool de PHP, que no contestan
       quien. Desde la pantalla llega el username; desde el job, el origen
       'JOB:COMEX_PRESUP'; vacio -alguien lo corrio a mano sin pasarlo-, el nombre del SP.
       Los origenes estan declarados en AuthCashflow::ORIGENES. Recortado a 50,
       el largo de las columnas USUARIO_*. */
    SET @Usuario = LEFT(ISNULL(NULLIF(LTRIM(RTRIM(@Usuario)), ''), 'JOB:RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN'), 50);

    INSERT INTO dbo.RO_T_CASHFLOW_JOB_LOG (PROCESO, INICIO, USUARIO_ALTA, USUARIO_MODIF)
    VALUES (@Proceso, GETDATE(), @Usuario, @Usuario);

    SET @IdLog = SCOPE_IDENTITY();

    EXEC @Lock = sp_getapplock @Resource = 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN',
                               @LockMode = 'Exclusive', @LockOwner = 'Session',
                               @LockTimeout = 0;

    IF @Lock < 0
    BEGIN
        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(),
            ERROR = N'Ya hay otra corrida en curso; esta no hizo nada.',
            USUARIO_MODIF = @Usuario, FECHA_MODIF = GETDATE()
        WHERE ID = @IdLog;

        RETURN;
    END

    BEGIN TRY
        /* 1. LA VISTA TIENE QUE EXISTIR. OBJECT_ID no cruza servidores, asi que
              se pregunta al catalogo remoto. */
        IF NOT EXISTS (SELECT 1 FROM [XL-APPS].POWER_BI_CONTROL.sys.views
                       WHERE name = 'RO_V_COMPRA_PROYECTADA_VIGENTE')
            THROW 50002, 'No existe RO_V_COMPRA_PROYECTADA_VIGENTE en POWER_BI_CONTROL. La crea el bloque 4 de presupuestos/sql/05_baja_logica_versiones.sql en el repo de compras. Se deja el presupuesto anterior.', 1;

        /* 1b. LA TABLA DEL % IMPORTADO TAMBIEN. Sin ella todos los rubros
               entrarian al 100 % -cuero, cosmetica, lentes y relojes
               incluidos-, y eso es una decision que nadie tomo. Se deja el
               presupuesto anterior. */
        IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT', 'U') IS NULL
            THROW 50003, 'No existe RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT: falta correr sql/cashflow_compras_proy_rubro_pct.sql. Sin el % importado por rubro no se recalcula; se deja el presupuesto anterior.', 1;

        /* 2. LAS FILAS DEL TRAMO OBJETIVO, a una temporal. Es la UNICA lectura
              de la vista: el resumen, el detalle por rubro y el contraste
              salen de aca, asi que los tres describen el mismo presupuesto.

              SELECT INTO para que los importes conserven el tipo de la vista:
              el FOB se sigue calculando con la misma precision que antes.

              EL RUBRO SE NORMALIZA: mayusculas, sin espacios en las puntas y
              con los repetidos colapsados a uno. El truco de CHAR(7): 'A  B'
              pasa a 'A <7> <7>B', se borra cada '<7> ' y despues cada <7>. Es
              la misma regla de ComprasProyectadasRubros::normalizar(), que es
              con la que se guarda el rubro en la tabla del %. */
        SELECT
            CAST(v.pais AS VARCHAR(50)) COLLATE DATABASE_DEFAULT                   AS PAIS,
            CAST(LTRIM(RTRIM(v.temporada_codigo)) AS VARCHAR(30)) COLLATE DATABASE_DEFAULT AS TEMPORADA,
            v.id_version                                                           AS ID_VERSION,
            CAST(v.nombre_presupuesto AS VARCHAR(255)) COLLATE DATABASE_DEFAULT    AS NOMBRE,
            CAST(v.solapa AS VARCHAR(255)) COLLATE DATABASE_DEFAULT                AS SOLAPA,
            v.fecha_calculo                                                        AS FECHA_CALCULO_VERSION,
            CAST(v.tramos_estado AS VARCHAR(100)) COLLATE DATABASE_DEFAULT         AS TRAMOS_ESTADO,
            v.temporada_desde                                                      AS TEMPORADA_DESDE,
            v.temporada_hasta                                                      AS TEMPORADA_HASTA,
            CAST(ISNULL(NULLIF(UPPER(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(v.rubro)),
                ' ', ' ' + CHAR(7)), CHAR(7) + ' ', ''), CHAR(7), '')), ''), '(SIN RUBRO)')
                AS VARCHAR(100)) COLLATE DATABASE_DEFAULT                          AS RUBRO,
            v.compra                                                               AS COMPRA,
            v.costo_prom                                                           AS COSTO_PROM,
            v.inc_fob                                                              AS INC_FOB,
            v.compra_deficit_cobertura                                             AS DEFICIT,
            CAST(NULL AS DECIMAL(5,2))                                             AS PCT
        INTO #FILAS
        FROM [XL-APPS].POWER_BI_CONTROL.dbo.RO_V_COMPRA_PROYECTADA_VIGENTE v
        WHERE v.es_objetivo = 1;

        /* 3. EL % IMPORTADO, cruzado en local. Un rubro sin fila queda con PCT
              en NULL: entra al 100 % y se graba CONFIGURADO = 0, que es lo que
              dispara el aviso de rubro sin configurar. El job NO lo da de
              alta: sigue sin configurar hasta que alguien le asigne un %. */
        UPDATE f
        SET PCT = p.PCT_IMPORTADO
        FROM #FILAS f
        JOIN dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT p
            ON p.RUBRO = f.RUBRO AND p.VIGENTE = 1;

        /* 4. EL RESUMEN POR PAIS Y TEMPORADA. EL % SE APLICA FILA POR FILA,
              ANTES DE SUMAR: cada importe y cada cantidad se multiplica por
              ISNULL(PCT, 100) / 100, asi el FOB, las unidades, la
              nacionalizacion segun inc_fob y el deficit describen la misma
              mercaderia. La nacionalizacion que se PROYECTA no sale de aca: el
              cashflow la deriva del FOB, que ya viene reducido.

              FILAS y FILAS_SIN_COSTO cuentan solo las filas que se proyectan
              (% mayor a 0): una fila sin costo de un rubro en 0 % no le quita
              nada a la proyeccion. FOB_PRESUPUESTO_USD es el FOB sin el %,
              para poder comparar con la app de compras. */
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
            FOB_PRESUPUESTO_USD   DECIMAL(18,4) NOT NULL,
            NAC_SEGUN_INC_FOB     DECIMAL(18,4) NOT NULL,
            DEFICIT_COBERTURA     DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #RES
        SELECT
            f.PAIS,
            f.TEMPORADA,
            MAX(f.ID_VERSION),
            MAX(f.NOMBRE),
            MAX(f.SOLAPA),
            MAX(f.FECHA_CALCULO_VERSION),
            MAX(f.TRAMOS_ESTADO),
            MIN(f.TEMPORADA_DESDE),
            MAX(f.TEMPORADA_HASTA),
            SUM(CASE WHEN ISNULL(f.PCT, 100) > 0 THEN 1 ELSE 0 END),
            SUM(CASE WHEN ISNULL(f.PCT, 100) > 0 AND f.COSTO_PROM IS NULL THEN 1 ELSE 0 END),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.PCT, 100) / 100.0),
            SUM(CASE WHEN f.COSTO_PROM IS NULL
                     THEN ISNULL(f.COMPRA, 0) * ISNULL(f.PCT, 100) / 100.0 ELSE 0 END),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0) * ISNULL(f.PCT, 100) / 100.0),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0)),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0) * ISNULL(f.INC_FOB, 0) / 100.0
                * ISNULL(f.PCT, 100) / 100.0),
            SUM(ISNULL(f.DEFICIT, 0) * ISNULL(f.PCT, 100) / 100.0)
        FROM #FILAS f
        GROUP BY f.PAIS, f.TEMPORADA;

        /* 5. EL DETALLE POR RUBRO, con el % que se le aplico. De aca salen el
              aviso de lo que queda fuera por el %, el de rubros sin
              configurar y el FOB por rubro de Parametros > Compras Exterior.
              PCT_APLICADO y CONFIGURADO son por rubro, y el rubro tiene un
              solo % vigente: el MAX no elige entre valores distintos. */
        CREATE TABLE #RUB (
            PAIS            VARCHAR(50)   COLLATE DATABASE_DEFAULT NOT NULL,
            TEMPORADA       VARCHAR(30)   COLLATE DATABASE_DEFAULT NOT NULL,
            RUBRO           VARCHAR(100)  COLLATE DATABASE_DEFAULT NOT NULL,
            ID_VERSION      INT           NOT NULL,
            FILAS           INT           NOT NULL,
            FOB_PRESUPUESTO DECIMAL(18,4) NOT NULL,
            PCT_APLICADO    DECIMAL(5,2)  NOT NULL,
            CONFIGURADO     BIT           NOT NULL,
            FOB_PROYECTADO  DECIMAL(18,4) NOT NULL
        );

        INSERT INTO #RUB
        SELECT
            f.PAIS,
            f.TEMPORADA,
            f.RUBRO,
            MAX(f.ID_VERSION),
            COUNT(*),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0)),
            MAX(ISNULL(f.PCT, 100)),
            MAX(CASE WHEN f.PCT IS NULL THEN 0 ELSE 1 END),
            SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0) * ISNULL(f.PCT, 100) / 100.0)
        FROM #FILAS f
        GROUP BY f.PAIS, f.TEMPORADA, f.RUBRO;

        /* 6. EL CONTRASTE: lo que devuelve la vista contra lo que hay en sus
              tablas. La vista ya no filtra -que parte de cada rubro se
              proyecta lo decide el %-, asi que las dos tienen que dar lo
              mismo. Es un CONTROL: si la vista vuelve a devolver menos,
              alguien le puso un filtro en la base que el cashflow no ve. */
        CREATE TABLE #VISTA (
            PAIS        VARCHAR(50)   COLLATE DATABASE_DEFAULT NOT NULL,
            FILAS_VISTA INT           NOT NULL,
            FOB_VISTA   DECIMAL(18,4) NOT NULL
        );

        INSERT INTO #VISTA
        SELECT f.PAIS, COUNT(*), SUM(ISNULL(f.COMPRA, 0) * ISNULL(f.COSTO_PROM, 0))
        FROM #FILAS f
        GROUP BY f.PAIS;

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

        /* 7. EL REEMPLAZO, con todo ya traido. */
        DECLARE @Ahora DATETIME = GETDATE();

        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN;

            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN
                (PAIS, TEMPORADA, ID_VERSION, NOMBRE, SOLAPA, FECHA_CALCULO_VERSION,
                 TRAMOS_ESTADO, TEMPORADA_DESDE, TEMPORADA_HASTA, FILAS, FILAS_SIN_COSTO,
                 UNIDADES, UNIDADES_SIN_COSTO, FOB_USD, FOB_PRESUPUESTO_USD, NAC_SEGUN_INC_FOB,
                 DEFICIT_COBERTURA, FECHA_CALCULO, USUARIO_ALTA, FECHA_ALTA)
            SELECT PAIS, TEMPORADA, ID_VERSION, NOMBRE, SOLAPA,
                   FECHA_CALCULO_VERSION, TRAMOS_ESTADO, TEMPORADA_DESDE, TEMPORADA_HASTA,
                   FILAS, FILAS_SIN_COSTO, UNIDADES, UNIDADES_SIN_COSTO, FOB_USD,
                   FOB_PRESUPUESTO_USD, NAC_SEGUN_INC_FOB, DEFICIT_COBERTURA, @Ahora, @Usuario, @Ahora
            FROM #RES;

            SET @Filas = @@ROWCOUNT;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO;

            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO
                (PAIS, TEMPORADA, RUBRO, ID_VERSION, FILAS, FOB_PRESUPUESTO, PCT_APLICADO,
                 CONFIGURADO, FOB_PROYECTADO, FECHA_CALCULO, USUARIO_ALTA, FECHA_ALTA,
                 USUARIO_MODIF, FECHA_MODIF)
            SELECT PAIS, TEMPORADA, RUBRO, ID_VERSION, FILAS, FOB_PRESUPUESTO, PCT_APLICADO,
                   CONFIGURADO, FOB_PROYECTADO, @Ahora, @Usuario, @Ahora, @Usuario, @Ahora
            FROM #RUB;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE;

            /* Un pais con tablas y sin nada en la vista tambien va: es
               justamente el caso en que la vista filtra TODO. */
            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE
                (PAIS, FILAS_VISTA, FOB_VISTA, FILAS_TABLAS, FOB_TABLAS, FECHA_CALCULO,
                 USUARIO_ALTA, FECHA_ALTA)
            SELECT ISNULL(t.PAIS, v.PAIS),
                   ISNULL(v.FILAS_VISTA, 0), ISNULL(v.FOB_VISTA, 0),
                   ISNULL(t.FILAS_TABLAS, 0), ISNULL(t.FOB_TABLAS, 0),
                   @Ahora, @Usuario, @Ahora
            FROM #TABLAS t
            FULL OUTER JOIN #VISTA v ON v.PAIS = t.PAIS;

        COMMIT TRANSACTION;

        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(), FILAS = @Filas, USUARIO_MODIF = @Usuario, FECHA_MODIF = GETDATE()
        WHERE ID = @IdLog;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        DECLARE @Error NVARCHAR(4000) = ERROR_MESSAGE();

        UPDATE dbo.RO_T_CASHFLOW_JOB_LOG
        SET FIN = GETDATE(), ERROR = @Error, USUARIO_MODIF = @Usuario, FECHA_MODIF = GETDATE()
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
                EXEC dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN @Usuario = 'JOB:COMEX_PRESUP';
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
   --      @database_name = N'LAKER_SA', @command = N'EXEC dbo.RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN @Usuario = ''JOB:COMEX_PRESUP'';';
   -- EXEC msdb.dbo.sp_add_jobschedule @job_name = N'CASHFLOW - Presupuesto de compras Comex',
   --      @name = N'Diaria 05:00', @freq_type = 4, @freq_interval = 1,
   --      @active_start_time = 050000;
   -- EXEC msdb.dbo.sp_add_jobschedule @job_name = N'CASHFLOW - Presupuesto de compras Comex',
   --      @name = N'Cada 30 min habiles', @freq_type = 8, @freq_interval = 62,
   --      @freq_recurrence_factor = 1, @freq_subday_type = 4, @freq_subday_interval = 30,
   --      @active_start_time = 080000, @active_end_time = 200000;
   -- EXEC msdb.dbo.sp_add_jobserver @job_name = N'CASHFLOW - Presupuesto de compras Comex';
   ============================================================================ */
