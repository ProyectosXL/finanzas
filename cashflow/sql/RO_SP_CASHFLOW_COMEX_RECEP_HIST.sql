/* ============================================================================
   RO_SP_CASHFLOW_COMEX_RECEP_HIST
   ----------------------------------------------------------------------------
   Base    : central
   Destino : RO_T_CASHFLOW_COMEX_RECEP_HIST (misma base)
   Log     : RO_T_CASHFLOW_JOB_LOG, PROCESO = 'COMEX_RECEP_HIST'
   Requiere: sql/cashflow_comex_materializado.sql

   La historia de recepciones de importacion, agrupada por anio y mes
   calendario, de la que sale la cuota de Compras Exterior.

   ES LA MISMA DEFINICION QUE ComprasProyectadasDatos::historiaRecepcionesEnVivo()
   y tiene que seguir siendolo: hay una prueba que compara las dos para
   2023-2025 contra la base. Si se toca un filtro aca, se toca alla.

     proveedor del exterior  CPA35.COD_PROVEE LIKE 'Z%'
     recepcion               STA20.TCOMP_IN_S = 'RP'
     mes                     el de STA20.FECHA_MOV
     importe                 TOTAL_EXT de la orden x cantidad del movimiento /
                             cantidad total recibida de la orden

   Ver el porque de cada uno en la seccion 3 de README-compras-proyectadas.md.

   POR QUE TARDABA 40 SEGUNDOS, Y POR QUE ESTO NO
   ----------------------------------------------
   La consulta en vivo entraba a STA20 (11,9 millones de filas) por el indice
   de TCOMP_IN_S: todos los remitos de proveedor de todos los proveedores, y
   recien despues se quedaba con los del exterior. Encima el CTE de
   movimientos se evaluaba DOS veces -una para el detalle y otra para el
   total de cada orden-. Medido el 24/09/2026: entre 31 y 57 s por llamada.

   Aca se va al reves: primero las ORDENES del exterior (unas 1.600, de
   CPA35), despues sus movimientos por IX_6 (N_ORDEN_CO), una sola vez, a una
   temporal. Con la misma base: 1,4 s para diez anios, y el mismo resultado
   que la consulta en vivo al centesimo de centavo (0 meses de diferencia,
   0,000035 U$S de diferencia maxima por redondeo).

   EL FILTRO POR FECHA VA AL FINAL Y NO AL PRINCIPIO, a proposito. El total de
   una orden -el denominador del prorrateo- tiene que sumar TODAS sus
   recepciones, aunque alguna caiga fuera del rango: una orden recibida en
   diciembre y enero reparte su importe entre los dos anios. Filtrar STA20
   por fecha antes de sacar ese total cambiaria el prorrateo. Y el filtro que
   achica la lectura no es la fecha -diez anios son casi toda la tabla- sino
   la orden.

   COMO REEMPLAZA LA TABLA
   -----------------------
   Todo se calcula en temporales. La tabla destino recien se toca al final,
   con el resultado listo, en UNA transaccion: DELETE + INSERT. Quien lee
   durante la corrida ve la historia anterior entera; nunca una tabla vacia
   ni a medio llenar.

   UN RESULTADO VACIO NO PISA NADA. Si la consulta no devuelve ningun mes, es
   que algo se rompio -la tabla de Tango cambio, el criterio 'Z%' dejo de
   valer-, no que la empresa dejo de importar. Se registra el error y la
   historia anterior queda.

   DOS CORRIDAS A LA VEZ NO SE PISAN: la segunda encuentra el lock de
   aplicacion tomado, deja el aviso en el log y sale sin tocar nada. Pasa si
   alguien aprieta "Actualizar ahora" mientras corre el job.

   Parametros (opcionales):
     @Anios    cuantos anios calendario COMPLETOS hacia atras. 10 por defecto:
               alcanza de sobra para cualquier compras_proy_anios_cuota
               razonable (hoy 3) y cuesta lo mismo que tres.
     @Usuario  quien lo pidio, para el log. NULL -> el login de la sesion (el
               del SQL Agent si lo corre el job).
   ============================================================================ */

CREATE OR ALTER PROCEDURE dbo.RO_SP_CASHFLOW_COMEX_RECEP_HIST
    @Anios   INT          = 10,
    @Usuario VARCHAR(128) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Proceso VARCHAR(60) = 'COMEX_RECEP_HIST';
    DECLARE @IdLog INT, @Filas INT = 0, @Lock INT;

    SET @Usuario = ISNULL(NULLIF(LTRIM(RTRIM(@Usuario)), ''), SUSER_SNAME());

    /* El intento se registra ANTES de cualquier otra cosa y fuera de la
       transaccion: si la corrida se cae, queda igual en el log. */
    INSERT INTO dbo.RO_T_CASHFLOW_JOB_LOG (PROCESO, INICIO, USUARIO)
    VALUES (@Proceso, GETDATE(), @Usuario);

    SET @IdLog = SCOPE_IDENTITY();

    EXEC @Lock = sp_getapplock @Resource = 'RO_SP_CASHFLOW_COMEX_RECEP_HIST',
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
        IF @Anios IS NULL OR @Anios < 1
            SET @Anios = 10;

        /* Anios calendario COMPLETOS: el anio en curso esta a medias. */
        DECLARE @Hasta DATE = DATEFROMPARTS(YEAR(GETDATE()) - 1, 12, 31);
        DECLARE @Desde DATE = DATEFROMPARTS(YEAR(GETDATE()) - @Anios, 1, 1);

        /* 1. LAS ORDENES DEL EXTERIOR. MAX(TOTAL_EXT) porque una orden puede
              repetirse en CPA35; es el mismo criterio que la consulta en vivo.
              SELECT INTO y no CREATE TABLE: la temporal hereda la collation de
              la columna de Tango, que no es la de la base, y el JOIN contra
              STA20 no choca. */
        SELECT A.N_ORDEN_CO, MAX(A.TOTAL_EXT) AS TOTAL_EXT
        INTO #OC
        FROM dbo.CPA35 A
        WHERE A.COD_PROVEE LIKE 'Z%'
        GROUP BY A.N_ORDEN_CO;

        CREATE UNIQUE CLUSTERED INDEX IX_OC ON #OC (N_ORDEN_CO);

        /* 2. SUS RECEPCIONES, UNA SOLA VEZ, por orden y dia. */
        SELECT B.N_ORDEN_CO, B.FECHA_MOV, SUM(B.CANTIDAD) AS CANT
        INTO #MOV
        FROM #OC O
        JOIN dbo.STA20 B ON B.N_ORDEN_CO = O.N_ORDEN_CO
        WHERE B.TCOMP_IN_S = 'RP'
        GROUP BY B.N_ORDEN_CO, B.FECHA_MOV;

        /* 3. EL TOTAL RECIBIDO DE CADA ORDEN, sobre TODAS sus fechas. */
        SELECT N_ORDEN_CO, SUM(CANT) AS CANT_OC
        INTO #TOT
        FROM #MOV
        GROUP BY N_ORDEN_CO;

        CREATE UNIQUE CLUSTERED INDEX IX_TOT ON #TOT (N_ORDEN_CO);

        /* 4. EL PRORRATEO, y recien aca el rango de anios. */
        SELECT CAST(YEAR(M.FECHA_MOV) AS SMALLINT) AS ANIO,
               CAST(MONTH(M.FECHA_MOV) AS TINYINT) AS MES,
               CAST(SUM(M.CANT) AS DECIMAL(18,4)) AS UNIDADES,
               CAST(SUM(O.TOTAL_EXT * M.CANT / NULLIF(T.CANT_OC, 0)) AS DECIMAL(18,4)) AS IMPORTE_USD
        INTO #RES
        FROM #MOV M
        JOIN #OC O  ON O.N_ORDEN_CO = M.N_ORDEN_CO
        JOIN #TOT T ON T.N_ORDEN_CO = M.N_ORDEN_CO
        WHERE M.FECHA_MOV >= @Desde
          AND M.FECHA_MOV < DATEADD(DAY, 1, @Hasta)
        GROUP BY YEAR(M.FECHA_MOV), MONTH(M.FECHA_MOV);

        IF NOT EXISTS (SELECT 1 FROM #RES)
            THROW 50001, 'La historia de recepciones salio vacia: no se reemplaza la anterior. Revisar CPA35 (COD_PROVEE LIKE Z%) y STA20 (TCOMP_IN_S = RP).', 1;

        /* 5. EL REEMPLAZO, con el resultado ya listo. */
        DECLARE @Ahora DATETIME = GETDATE();

        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_COMEX_RECEP_HIST;

            INSERT INTO dbo.RO_T_CASHFLOW_COMEX_RECEP_HIST
                (ANIO, MES, UNIDADES, IMPORTE_USD, FECHA_CALCULO)
            SELECT ANIO, MES, ISNULL(UNIDADES, 0), ISNULL(IMPORTE_USD, 0), @Ahora
            FROM #RES;

            SET @Filas = @@ROWCOUNT;

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

        EXEC sp_releaseapplock @Resource = 'RO_SP_CASHFLOW_COMEX_RECEP_HIST', @LockOwner = 'Session';

        /* Se relanza para que el paso del job quede FALLIDO y el Agent lo
           muestre, ademas de quedar en el log. */
        THROW;
    END CATCH

    EXEC sp_releaseapplock @Resource = 'RO_SP_CASHFLOW_COMEX_RECEP_HIST', @LockOwner = 'Session';
END;
GO

/* ============================================================================
   PROGRAMACION SUGERIDA PARA EL SQL AGENT (el job lo crea quien administra la
   base; este script NO lo crea)

   Job      : CASHFLOW - Historia de recepciones Comex
   Servidor : XL-TANGO, base LAKER_SA
   Paso 1   : T-SQL
                EXEC dbo.RO_SP_CASHFLOW_COMEX_RECEP_HIST;
   Frecuencia: DIARIA a las 05:00. Tarda alrededor de 1,5 s.

   Con una vez por dia sobra: es historia de anios CERRADOS y el mes en curso
   no entra en la cuota. Lo que la cambia es una recepcion cargada con fecha
   de un anio anterior, que es rara, y el cambio de anio: el 1 de enero el
   anio que termino pasa a contar, y la corrida de las 05:00 de ese dia ya lo
   incluye.

   Si el job no corre, la pestana lo dice: muestra "Historia al dd/mm hh:mm"
   con la fecha de la ultima corrida buena, y avisa si a la historia le faltan
   anios que la cuota necesita.

   -- Ejemplo, para adaptar:
   -- EXEC msdb.dbo.sp_add_job @job_name = N'CASHFLOW - Historia de recepciones Comex';
   -- EXEC msdb.dbo.sp_add_jobstep @job_name = N'CASHFLOW - Historia de recepciones Comex',
   --      @step_name = N'RO_SP_CASHFLOW_COMEX_RECEP_HIST', @subsystem = N'TSQL',
   --      @database_name = N'LAKER_SA', @command = N'EXEC dbo.RO_SP_CASHFLOW_COMEX_RECEP_HIST;';
   -- EXEC msdb.dbo.sp_add_jobschedule @job_name = N'CASHFLOW - Historia de recepciones Comex',
   --      @name = N'Diaria 05:00', @freq_type = 4, @freq_interval = 1,
   --      @active_start_time = 050000;
   -- EXEC msdb.dbo.sp_add_jobserver @job_name = N'CASHFLOW - Historia de recepciones Comex';
   ============================================================================ */
