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
     6. CANALES POR PREFIJO DE CLIENTE (parte A): FR y L -> FRANQUICIAS
        (L es XL Lomas, LALOMA), MA -> MAYORISTAS. Antes era
        LIKE '[FM][RA]%', que dejaba afuera a LALOMA.
     7. ECOMMERCE CON IMP_NETO_P (parte D). La formula heredada,
        CANTIDAD * PRECIO_NET con un primer WHEN para los articulos '*',
        inflaba la venta: el precio es antes de la bonificacion, y las NC de
        articulos '*' (envios) SUMABAN en vez de restar. Medido: sep-26 +15,4
        millones (6%), oct-26 +36,7 millones (9,4%) contra el libro IVA.
     8. LO QUE QUEDA FUERA SE GRABA (parte E). Todo cliente que no es de un
        canal -regalias, canjes, gift cards, exportaciones, o uno nuevo- va a
        RO_T_CASHFLOW_VENTAS_EXCLUIDAS con su motivo. Es lo que explica la
        diferencia contra el libro IVA ventas de contabilidad:
            libro (neto + no gravado + exento) = esta venta + excluidas
        Si la tabla no existe (sql/cashflow_ventas_excluidas.sql), el SP la
        saltea y carga la venta igual.

   Parametros (todos opcionales, para poder recorrer el historico hacia atras):
     @Desde  NULL -> DATEADD(DAY, -30, GETDATE())
     @Hasta  NULL -> GETDATE()
     @Usuario quien lo corre. El paso del job pasa 'JOB:VENTAS_HIST'; vacio -> 'JOB:SJ_CASHFLOW_VENTAS_HIST'

   IMPORTANTE - normalizacion del rango:
   La tabla destino agrega al grano de MES. Si el rango pedido empezara o
   terminara a mitad de mes, el DELETE borraria el mes completo y el INSERT
   solo repondria la porcion del rango, perdiendo importe. Por eso el rango se
   expande internamente al primer dia del mes de @Desde y al ultimo dia del mes
   de @Hasta, y ese rango expandido es el que se usa tanto para leer el origen
   como para borrar el destino.

   Carga inicial del historico:
     EXEC SJ_CASHFLOW_VENTAS_HIST @Desde = '2025-01-01', @Hasta = '2025-12-31';

   OJO - HAY UNA COPIA DE LAS PARTES A, C y D
   RO_SP_CASHFLOW_VENTAS_HIST_DIA repite esos tres bloques al grano de dia para
   el bloque de tendencias. Si aca se toca un join o un filtro, hay que tocarlo
   tambien alla. tests/test_ventas_hist_sql.php verifica que sigan iguales.
   La parte E (excluidas) es solo de este SP: la auditoria es mensual.
   ============================================================================ */

IF OBJECT_ID('dbo.SJ_CASHFLOW_VENTAS_HIST', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SJ_CASHFLOW_VENTAS_HIST;
GO

CREATE PROCEDURE dbo.SJ_CASHFLOW_VENTAS_HIST
    @Desde   DATE         = NULL,
    @Hasta   DATE         = NULL,
    @Usuario VARCHAR(128) = NULL
AS
BEGIN
    SET XACT_ABORT ON;
    SET NOCOUNT ON;

    /* QUIEN LO CORRIO, para USUARIO_ALTA de cada fila: el job pasa 'JOB:VENTAS_HIST'
       y una corrida a mano sin @Usuario queda como el nombre del SP. Nunca
       SUSER_SNAME(): es la cuenta del servicio, no contesta quien. Los
       origenes estan declarados en AuthCashflow::ORIGENES. */
    SET @Usuario = LEFT(ISNULL(NULLIF(LTRIM(RTRIM(@Usuario)), ''), 'JOB:SJ_CASHFLOW_VENTAS_HIST'), 50);

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

    CREATE TABLE #TempExcluidas (
        COD_CLIENT    VARCHAR(20)  COLLATE DATABASE_DEFAULT,
        T_COMP        VARCHAR(5)   COLLATE DATABASE_DEFAULT,
        N_COMP        VARCHAR(20)  COLLATE DATABASE_DEFAULT,
        FECHA         DATE,
        NETO_GRAVADO  DECIMAL(18,4),
        EXENTO        DECIMAL(18,4),
        IVA           DECIMAL(18,4)
    );

    BEGIN TRY

        /* ------------------------------------------------------------------
           PARTE A: FACTURAS FRANQUICIAS / MAYORISTAS  (GVA12 + GVA53)
           FR y L -> FRANQUICIAS (L = XL Lomas), MA -> MAYORISTAS.
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            A.FECHA_EMIS,
            CASE WHEN A.COD_CLIENT LIKE 'FR%' THEN 'FRANQUICIAS'
                 WHEN A.COD_CLIENT LIKE 'L%'  THEN 'FRANQUICIAS'
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
          AND (A.COD_CLIENT LIKE 'FR%' OR A.COD_CLIENT LIKE 'L%' OR A.COD_CLIENT LIKE 'MA%')
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
           IMP_NETO_P con el signo de la NC en TODOS los renglones, igual que
           la parte A. Ver el punto 7 de la cabecera.
           ------------------------------------------------------------------ */
        INSERT INTO #TempVentas (FECHA, CANAL, TIPO_COMPROBANTE, CANTIDAD, IMPORTE_NETO)
        SELECT
            A.FECHA_EMIS,
            'ECOMMERCE' COLLATE DATABASE_DEFAULT,
            'FACTURA' COLLATE DATABASE_DEFAULT,
            CASE WHEN B.COD_ARTICU LIKE '*%' THEN 0
                 WHEN A.T_COMP LIKE 'NC%' THEN B.CANTIDAD * -1
                 ELSE B.CANTIDAD END,
            CASE WHEN A.T_COMP LIKE 'NC%' THEN B.IMP_NETO_P * -1
                 ELSE B.IMP_NETO_P END
        FROM GVA12 A
        INNER JOIN GVA53 B ON A.T_COMP = B.T_COMP AND A.N_COMP = B.N_COMP
        WHERE A.FECHA_EMIS BETWEEN @DesdeMes AND @HastaMes
          AND A.COD_CLIENT = '000000';

        /* ------------------------------------------------------------------
           PARTE E: LO QUE QUEDA FUERA DE LA VENTA  (GVA12, encabezado)
           Todo cliente que no es de un canal: el complemento exacto de las
           partes A y D. Se lee el ENCABEZADO, que es lo que lee el libro IVA,
           y en pesos: un comprobante en moneda extranjera (MON_CTE = 0, las
           exportaciones) trae gravado, exento e IVA en esa moneda.
           No alimenta la venta: va a RO_T_CASHFLOW_VENTAS_EXCLUIDAS.
           ------------------------------------------------------------------ */
        INSERT INTO #TempExcluidas (COD_CLIENT, T_COMP, N_COMP, FECHA, NETO_GRAVADO, EXENTO, IVA)
        SELECT
            A.COD_CLIENT,
            A.T_COMP,
            A.N_COMP,
            A.FECHA_EMIS,
            A.IMPORTE_GR * S.SIGNO * S.CAMBIO,
            A.IMPORTE_EX * S.SIGNO * S.CAMBIO,
            A.IMPORTE_IV * S.SIGNO * S.CAMBIO
        FROM GVA12 A
        CROSS APPLY (SELECT
            CASE WHEN A.T_COMP LIKE 'NC%' THEN -1 ELSE 1 END AS SIGNO,
            CASE WHEN A.MON_CTE = 0 THEN A.COTIZ ELSE 1 END AS CAMBIO) S
        WHERE A.FECHA_EMIS BETWEEN @DesdeMes AND @HastaMes
          AND A.T_COMP IN ('FAC','NCP','NCR','NDP','NDR')
          AND A.COD_CLIENT NOT LIKE 'FR%'
          AND A.COD_CLIENT NOT LIKE 'L%'
          AND A.COD_CLIENT NOT LIKE 'MA%'
          AND A.COD_CLIENT <> '000000';

        /* ------------------------------------------------------------------
           PERSISTENCIA
           DELETE del rango expandido + INSERT agregado -> reejecutable.
           Se descartan los CANAL vacios: el LIKE '[FM][RA]%' de la parte B
           admite prefijos FA/MR que el CASE no mapea a ningun canal del modelo.
           Las excluidas van en la MISMA transaccion: venta y diferencia contra
           el libro quedan siempre de la misma corrida.
           ------------------------------------------------------------------ */
        BEGIN TRANSACTION;

            DELETE FROM dbo.RO_T_CASHFLOW_VENTAS_HIST
            WHERE DATEFROMPARTS(ANIO, MES, 1) BETWEEN @DesdeMes AND @HastaMes;

            INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_HIST
                (ANIO, MES, CANAL, TIPO_COMPROBANTE, IMPORTE_NETO, CANTIDAD, FECHA_CARGA,
                 USUARIO_ALTA, FECHA_ALTA)
            SELECT
                YEAR(T.FECHA),
                MONTH(T.FECHA),
                T.CANAL,
                T.TIPO_COMPROBANTE,
                SUM(T.IMPORTE_NETO),
                SUM(T.CANTIDAD),
                GETDATE(),
                @Usuario,
                GETDATE()
            FROM #TempVentas T
            WHERE T.CANAL <> ''
            GROUP BY YEAR(T.FECHA), MONTH(T.FECHA), T.CANAL, T.TIPO_COMPROBANTE;

            IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS', 'U') IS NOT NULL
            BEGIN
                DELETE FROM dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS
                WHERE DATEFROMPARTS(ANIO, MES, 1) BETWEEN @DesdeMes AND @HastaMes;

                INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS
                    (ANIO, MES, COD_CLIENT, RAZON_SOCIAL, MOTIVO, COMPROBANTES,
                     NETO_GRAVADO, EXENTO, NETO_SIN_IVA, IVA, FECHA_CARGA,
                     USUARIO_ALTA, FECHA_ALTA)
                SELECT
                    YEAR(E.FECHA),
                    MONTH(E.FECHA),
                    E.COD_CLIENT,
                    LEFT(MAX(C.RAZON_SOCI), 100),
                    -- ****CF: la NC a consumidor final del canje de puntos
                    -- ICBC Mall. Es la otra mitad de la FAC a OTICBC: NC + FAC = 0.
                    CASE WHEN LEFT(E.COD_CLIENT, 2) = 'OT' THEN 'OTROS INGRESOS'
                         WHEN E.COD_CLIENT = '****CF'   THEN 'OTROS INGRESOS'
                         WHEN LEFT(E.COD_CLIENT, 2) = 'EX' THEN 'EXPORTACION'
                         ELSE 'SIN CANAL' END,
                    COUNT(*),
                    SUM(E.NETO_GRAVADO),
                    SUM(E.EXENTO),
                    SUM(E.NETO_GRAVADO + E.EXENTO),
                    SUM(E.IVA),
                    GETDATE(),
                    @Usuario,
                    GETDATE()
                FROM #TempExcluidas E
                -- GVA14 es Latin1_General_BIN y la temporal, DATABASE_DEFAULT
                LEFT JOIN GVA14 C ON C.COD_CLIENT COLLATE DATABASE_DEFAULT = E.COD_CLIENT
                GROUP BY YEAR(E.FECHA), MONTH(E.FECHA), E.COD_CLIENT;
            END

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

    IF OBJECT_ID('tempdb..#TempExcluidas') IS NOT NULL
        DROP TABLE #TempExcluidas;
END;
GO
