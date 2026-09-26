/* ============================================================================
   RO_SP_CASHFLOW_VENTAS_HIST_DIA
   ----------------------------------------------------------------------------
   Base   : central
   Destino: RO_T_CASHFLOW_VENTAS_HIST_DIA (misma base)

   Consolida el historico de ventas por FECHA / CANAL / TIPO_COMPROBANTE.

   POR QUE EXISTE
   SJ_CASHFLOW_VENTAS_HIST agrega al grano de MES. Con ese grano, el mes en
   curso solo se puede comparar contra un mes completo del anio anterior, y la
   variacion sale siempre hundida porque enfrenta los dias transcurridos contra
   un mes entero. Al grano de dia el anio anterior se puede recortar a los
   mismos dias, que es lo que necesita el bloque de tendencias de la
   sub-pestania Analisis de Ventas.

   Esta basado en SJ_CASHFLOW_VENTAS_HIST y conserva sus joins y sus filtros
   (incluidos COD_ARTICU LIKE '[XO*]%' y PROMOCION = 0). Diferencias:

     1. SOLO FACTURAS. Se omite la parte B (remitos 599, STA14 + STA20), que es
        la unica que produce TIPO_COMPROBANTE = 'REMITO'. El control de remitos
        sigue siendo mensual y vive en la otra tabla.

     2. Salida agregada por FECHA, CANAL, TIPO_COMPROBANTE.

     3. SIN expansion del rango a meses completos. En el SP mensual esa
        expansion es obligatoria: el DELETE borra el mes entero y un rango que
        empezara a mitad de mes solo repondria una porcion, perdiendo importe.
        Al grano de dia el DELETE + INSERT del rango exacto ya es seguro, asi
        que @Desde y @Hasta se usan tal cual llegan.

   OJO - DUPLICACION DELIBERADA
   Las partes A, C y D son una copia de las del SP mensual y pueden derivar. La
   alternativa limpia -que el mensual agregue desde esta tabla- toca el SP que
   hoy alimenta la proyeccion y su job, asi que se dejo para un cambio propio.
   Si se toca un filtro aca, hay que tocarlo tambien alla.

   Parametros (ambos opcionales):
     @Desde  NULL -> DATEADD(DAY, -30, GETDATE())
     @Hasta  NULL -> ayer, que es hasta donde llega la carga de madrugada

   CARGA INICIAL
   El bloque de tendencias muestra los ultimos N meses y los compara contra los
   mismos meses del anio anterior, asi que el rango arranca el dia 1 del mes mas
   viejo mostrado MENOS UN ANIO. Con una ventana de 6 meses parada en sep-2026
   (abr-26 .. sep-26) eso da abr-2025:

     EXEC RO_SP_CASHFLOW_VENTAS_HIST_DIA @Desde = '2025-04-01';

   El rango contiguo se sostiene solo a medida que la ventana avanza: cuando el
   mes que viene pase a may-26 .. oct-26, oct-25 ya quedo cargado.
   ============================================================================ */

IF OBJECT_ID('dbo.RO_SP_CASHFLOW_VENTAS_HIST_DIA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_CASHFLOW_VENTAS_HIST_DIA;
GO

CREATE PROCEDURE dbo.RO_SP_CASHFLOW_VENTAS_HIST_DIA
    @Desde DATE = NULL,
    @Hasta DATE = NULL
AS
BEGIN
    SET XACT_ABORT ON;
    SET NOCOUNT ON;

    IF @Desde IS NULL SET @Desde = DATEADD(DAY, -30, CAST(GETDATE() AS DATE));

    /* El origen se actualiza de madrugada: el ultimo dia cerrado es ayer. Pedir
       hasta hoy grabaria una fila del dia en curso con la venta a medio cargar,
       y el front la leeria como un dia cerrado. */
    IF @Hasta IS NULL SET @Hasta = DATEADD(DAY, -1, CAST(GETDATE() AS DATE));

    IF @Desde > @Hasta
    BEGIN
        RAISERROR ('RO_SP_CASHFLOW_VENTAS_HIST_DIA: @Desde no puede ser mayor que @Hasta.', 16, 1);
        RETURN;
    END

    CREATE TABLE #TempVentasDia (
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
        INSERT INTO #TempVentasDia (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
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
        WHERE A.FECHA_EMIS BETWEEN @Desde AND @Hasta
          AND A.COD_CLIENT LIKE '[FM][RA]%'
          AND B.COD_ARTICU LIKE '[XO*]%'
          AND B.PROMOCION = 0
          AND A.T_COMP IN ('FAC','NCP','NCR','NDP','NDR');

        /* ------------------------------------------------------------------
           PARTE C: VENTAS LOCALES PROPIOS  (CTA02 + CTA03 en [XL-LAKERBIS])
           La conversion por MON_CTE / COTIZ ya viene resuelta del SP original.
           (No hay PARTE B: los remitos no entran en esta tabla.)
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentasDia (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
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
          AND A.FECHA_EMIS BETWEEN @Desde AND @Hasta
          AND C.CANAL = 'PROPIOS';

        /* ------------------------------------------------------------------
           PARTE D: ECOMMERCE  (GVA12 + GVA53, COD_CLIENT = '000000')
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentasDia (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
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
        WHERE A.FECHA_EMIS BETWEEN @Desde AND @Hasta
          AND A.COD_CLIENT = '000000';

        /* ------------------------------------------------------------------
           PERSISTENCIA
           DELETE del rango exacto + INSERT agregado -> reejecutable.
           Se descartan los CANAL vacios: el LIKE '[FM][RA]%' de la parte A
           admite prefijos FA/MR que el CASE no mapea a ningun canal del modelo.
           ------------------------------------------------------------------ */
        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_VENTAS_HIST_DIA
            WHERE FECHA BETWEEN @Desde AND @Hasta;

            INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_HIST_DIA
                (FECHA, CANAL, TIPO_COMPROBANTE, IMPORTE_NETO, CANTIDAD, FECHA_CARGA)
            SELECT
                T.FECHA,
                T.CANAL,
                T.TIPO_COMPROBANTE,
                SUM(T.IMPORTE_NETO),
                SUM(T.CANTIDAD),
                GETDATE()
            FROM #TempVentasDia T
            WHERE T.CANAL <> ''
            GROUP BY T.FECHA, T.CANAL, T.TIPO_COMPROBANTE;

        COMMIT TRANSACTION;

    END TRY
    BEGIN CATCH

        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;

        DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR (@ErrorMessage, 16, 1);

    END CATCH

    IF OBJECT_ID('tempdb..#TempVentasDia') IS NOT NULL
        DROP TABLE #TempVentasDia;
END;
GO
