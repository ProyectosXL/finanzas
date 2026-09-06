/* ============================================================================
   SJ_CASHFLOW_VENTAS_HIST
   ----------------------------------------------------------------------------
   Base   : central
   Destino: RO_T_CASHFLOW_VENTAS_HIST (misma base)

   Consolida el historico de ventas por ANIO / MES / CANAL / TIPO_COMPROBANTE
   para que el modulo de proyeccion lo use como base de calculo.

   Esta basado en SJ_BI_SALES_LAKERS y conserva exactamente sus cuatro partes,
   sus joins y sus filtros (incluidos COD_ARTICU LIKE '[XO*]%' y PROMOCION = 0).
   Diferencias contra el SP de referencia:
     1. Discrimina TIPO_COMPROBANTE en 'FACTURA' / 'REMITO'. La parte B es la
        unica que produce REMITO. La proyeccion usa solo FACTURA; los remitos se
        guardan igual para poder controlar el total contra el tablero.
     2. Salida agregada por ANIO, MES, CANAL, TIPO_COMPROBANTE.
     3. Importe neto sin IVA, tal como ya lo devuelve el SP original.
     4. Las notas de credito mantienen el signo negativo (T_COMP LIKE 'NC%' -> *-1),
        con lo cual restan en el mes de la NC.
     5. Persiste con DELETE del rango + INSERT, de modo que es reejecutable sin
        duplicar filas.

   Parametros (ambos opcionales, para poder recorrer el historico hacia atras):
     @Desde  NULL -> DATEADD(DAY, -30, GETDATE())
     @Hasta  NULL -> GETDATE()

   IMPORTANTE - normalizacion del rango:
   La tabla destino agrega al grano de MES. Si el rango pedido empezara o
   terminara a mitad de mes, el DELETE borraria el mes completo y el INSERT
   solo repondria la porcion del rango, perdiendo importe. Por eso el rango se
   expande internamente al primer dia del mes de @Desde y al ultimo dia del mes
   de @Hasta, y ese rango expandido es el que se usa tanto para leer el origen
   como para borrar el destino.

   Carga inicial del historico:
     EXEC SJ_CASHFLOW_VENTAS_HIST @Desde = '2025-01-01', @Hasta = '2025-12-31';
   ============================================================================ */

IF OBJECT_ID('dbo.SJ_CASHFLOW_VENTAS_HIST', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SJ_CASHFLOW_VENTAS_HIST;
GO

CREATE PROCEDURE dbo.SJ_CASHFLOW_VENTAS_HIST
    @Desde DATE = NULL,
    @Hasta DATE = NULL
AS
BEGIN
    SET XACT_ABORT ON;
    SET NOCOUNT ON;

    IF @Desde IS NULL SET @Desde = DATEADD(DAY, -30, CAST(GETDATE() AS DATE));
    IF @Hasta IS NULL SET @Hasta = CAST(GETDATE() AS DATE);

    IF @Desde > @Hasta
    BEGIN
        RAISERROR ('SJ_CASHFLOW_VENTAS_HIST: @Desde no puede ser mayor que @Hasta.', 16, 1);
        RETURN;
    END

    /* Expansion del rango a meses completos (ver cabecera) */
    DECLARE @DesdeMes DATE = DATEFROMPARTS(YEAR(@Desde), MONTH(@Desde), 1);
    DECLARE @HastaMes DATE = EOMONTH(@Hasta);

    CREATE TABLE #TempVentas (
        FECHA            DATE,
        CANAL            VARCHAR(20) COLLATE DATABASE_DEFAULT,
        TIPO_COMPROBANTE VARCHAR(20) COLLATE DATABASE_DEFAULT,
        CANTIDAD         FLOAT,
        IMPORTE_NETO     DECIMAL(18,4)
    );

    BEGIN TRY

        /* ------------------------------------------------------------------
           PARTE A: FACTURAS FRANQUICIAS / MAYORISTAS  (GVA12 + GVA53)
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            A.FECHA_EMIS,
            CASE WHEN A.COD_CLIENT LIKE 'FR%' THEN 'FRANQUICIAS'
                 WHEN A.COD_CLIENT LIKE 'MA%' THEN 'MAYORISTAS'
                 ELSE '' END COLLATE DATABASE_DEFAULT,
            'FACTURA' COLLATE DATABASE_DEFAULT,
            CASE WHEN B.COD_ARTICU LIKE '*%' THEN 0
                 WHEN A.T_COMP LIKE 'NC%' THEN B.CANTIDAD * -1
                 ELSE B.CANTIDAD END,
            CASE WHEN A.T_COMP LIKE 'NC%' THEN B.IMP_NETO_P * -1
                 ELSE B.IMP_NETO_P END
        FROM GVA12 A
        INNER JOIN GVA53 B ON A.T_COMP = B.T_COMP AND A.N_COMP = B.N_COMP
        WHERE A.FECHA_EMIS BETWEEN @DesdeMes AND @HastaMes
          AND A.COD_CLIENT LIKE '[FM][RA]%'
          AND B.COD_ARTICU LIKE '[XO*]%'
          AND B.PROMOCION = 0
          AND A.T_COMP IN ('FAC','NCP','NCR','NDP','NDR');

        /* ------------------------------------------------------------------
           PARTE B: REMITOS 599  (STA14 + STA20)
           Unica parte que produce TIPO_COMPROBANTE = 'REMITO'.
           No entra en la proyeccion: es solo bloque de control.
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            A.FECHA_MOV,
            CASE WHEN A.COD_PRO_CL LIKE 'FR%' THEN 'FRANQUICIAS'
                 WHEN A.COD_PRO_CL LIKE 'MA%' THEN 'MAYORISTAS'
                 ELSE '' END COLLATE DATABASE_DEFAULT,
            'REMITO' COLLATE DATABASE_DEFAULT,
            B.CANTIDAD,
            B.PRECIO_REM * B.CANTIDAD
        FROM STA14 A
        INNER JOIN STA20 B ON A.ID_STA14 = B.ID_STA14
        WHERE A.FECHA_MOV BETWEEN @DesdeMes AND @HastaMes
          AND A.N_COMP LIKE 'X%'
          AND A.COD_PRO_CL LIKE '[FM][RA]%'
          AND B.PROMOCION = 0
          AND A.ESTADO_MOV <> 'A';

        /* ------------------------------------------------------------------
           PARTE C: VENTAS LOCALES PROPIOS  (CTA02 + CTA03 en [XL-LAKERBIS])
           La conversion por MON_CTE / COTIZ ya viene resuelta del SP original.
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            CAST(A.FECHA_EMIS AS DATE),
            'LOCALES' COLLATE DATABASE_DEFAULT,
            'FACTURA' COLLATE DATABASE_DEFAULT,
            CASE WHEN B.COD_ARTICU LIKE '*%' THEN 0
                 WHEN B.T_COMP LIKE 'NC%' THEN B.CANTIDAD * -1
                 ELSE B.CANTIDAD END,
            CASE WHEN A.MON_CTE = 0
                 THEN (CASE WHEN B.T_COMP LIKE 'NC%' THEN B.IMP_NETO_P * -1 ELSE B.IMP_NETO_P END) * A.COTIZ
                 ELSE (CASE WHEN B.T_COMP LIKE 'NC%' THEN B.IMP_NETO_P * -1 ELSE B.IMP_NETO_P END)
            END
        FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.CTA02 A
        INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.CTA03 B
            ON  A.T_COMP     = B.T_COMP
            AND A.N_COMP     = B.N_COMP
            AND A.NRO_SUCURS = B.NRO_SUCURS
            AND A.FECHA_EMIS = B.FECHA_MOV
        INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS C
            ON A.NRO_SUCURS = C.NRO_SUCURSAL
        WHERE A.ESTADO <> 'ANU'
          AND A.FECHA_EMIS BETWEEN @DesdeMes AND @HastaMes
          AND C.CANAL = 'PROPIOS';

        /* ------------------------------------------------------------------
           PARTE D: ECOMMERCE  (GVA12 + GVA53, COD_CLIENT = '000000')
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            A.FECHA_EMIS,
            'ECOMMERCE' COLLATE DATABASE_DEFAULT,
            'FACTURA' COLLATE DATABASE_DEFAULT,
            CASE WHEN B.COD_ARTICU LIKE '*%' THEN 0
                 WHEN A.T_COMP LIKE 'NC%' THEN B.CANTIDAD * -1
                 ELSE B.CANTIDAD END,
            CASE WHEN B.COD_ARTICU LIKE '*%' THEN B.PRECIO_NET
                 WHEN A.T_COMP LIKE 'NC%' THEN B.CANTIDAD * -B.PRECIO_NET
                 ELSE B.CANTIDAD * B.PRECIO_NET END
        FROM GVA12 A
        INNER JOIN GVA53 B ON A.T_COMP = B.T_COMP AND A.N_COMP = B.N_COMP
        WHERE A.FECHA_EMIS BETWEEN @DesdeMes AND @HastaMes
          AND A.COD_CLIENT = '000000';

        /* ------------------------------------------------------------------
           PERSISTENCIA
           DELETE del rango expandido + INSERT agregado -> reejecutable.
           Se descartan los CANAL vacios: el LIKE '[FM][RA]%' de las partes A y B
           admite prefijos FA/MR que el CASE no mapea a ningun canal del modelo.
           ------------------------------------------------------------------ */
        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_VENTAS_HIST
            WHERE DATEFROMPARTS(ANIO, MES, 1) BETWEEN @DesdeMes AND @HastaMes;

            INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_HIST
                (ANIO, MES, CANAL, TIPO_COMPROBANTE, IMPORTE_NETO, CANTIDAD, FECHA_CARGA)
            SELECT
                YEAR(T.FECHA),
                MONTH(T.FECHA),
                T.CANAL,
                T.TIPO_COMPROBANTE,
                SUM(T.IMPORTE_NETO),
                SUM(T.CANTIDAD),
                GETDATE()
            FROM #TempVentas T
            WHERE T.CANAL <> ''
            GROUP BY YEAR(T.FECHA), MONTH(T.FECHA), T.CANAL, T.TIPO_COMPROBANTE;

        COMMIT TRANSACTION;

    END TRY
    BEGIN CATCH

        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR (@ErrorMessage, 16, 1);

    END CATCH

    IF OBJECT_ID('tempdb..#TempVentas') IS NOT NULL
        DROP TABLE #TempVentas;
END;
GO
