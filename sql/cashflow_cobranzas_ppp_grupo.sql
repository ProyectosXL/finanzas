/* ============================================================================
   MODULO COBRANZAS FR - PLAZO PROMEDIO DE PAGO (PPP) POR GRUPO EMPRESARIO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : despues de los otros scripts de cobranzas FR; requiere que exista la
            vista dbo.GC_VIEW_PPP (la de Tango, que no crea este script)
   ----------------------------------------------------------------------------
   QUE CAMBIA Y POR QUE

   1. EL ORIGEN DEL PPP CALCULADO. Hasta ahora salia de FP_propuestas_pago (base
      de apps): el promedio de DATEDIFF(fecha_creacion, fecha_propuesta_pago) de
      las ultimas 3 propuestas pagadas. Eso mide cuanto tarda una PROPUESTA en
      pagarse desde que se crea, no cuanto tarda el cliente en pagar la
      FACTURA; y despues se sumaba a la fecha de emision de la factura. Era el
      numero equivocado aplicado a la fecha correcta.

      Ahora sale de dbo.GC_VIEW_PPP: un PPP por recibo, calculado en Tango
      como los dias ponderados por importe entre la emision de las facturas
      imputadas y el cobro. Es la medida que la proyeccion necesita.

   2. EL GRANO ES EL GRUPO EMPRESARIO. Los franquiciados con varios locales
      pagan como grupo, asi que el PPP se calcula por GVA14.GRUPO_EMPR (nombre
      en GVA62.NOMBRE_GRU) y todos los clientes del grupo lo comparten. Un
      cliente sin grupo es su propio grupo: COD_AGRUP = GRUPO_EMPR o, si esta
      vacio, COD_CLIENTE. Es el mismo criterio que la regla del PHP
      (Ingresos::codAgrupador).

      Ventana: recibos de los ultimos 100 dias. El PPP del grupo es el promedio
      de los promedios por cliente, no el promedio de todos los recibos juntos:
      un cliente con muchos recibos no pesa mas que uno con pocos.

   3. EL PPP MANUAL TAMBIEN ES POR GRUPO. Se guarda en la tabla nueva
      RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO. RO_T_PARAMETROS_DESC_CLIENTES.PPP_MANUAL
      (por cliente) DEJA DE LEERSE pero no se borra: mismo criterio de baja
      logica que el resto del modulo. La semilla migra los valores que habia
      -hoy tres clientes- a su agrupador; si dos clientes de un mismo grupo
      tenian valores distintos, se toma el MAYOR.

   LA REGLA DEL PPP EFECTIVO vive una sola vez en PHP (Ingresos::pppEfectivo):
       manual del grupo > 0  ->  ese
       calculado del grupo > 0 -> ese
       DIAS_PP_MAX del cliente > 0 -> ese
       si no -> 30

   COLLATION: las tablas RO_T_* y las de Tango pueden tener collation distinta.
   La vista solo toca tablas de Tango; el unico cruce con una tabla propia es
   la semilla, que lleva COLLATE DATABASE_DEFAULT. El PHP mergea en memoria y
   no hace ese join.

   ES REEJECUTABLE: la vista se recrea (DROP + CREATE), la tabla se crea solo
   si no existe y la semilla entra por MERGE WHEN NOT MATCHED, asi que una
   segunda corrida no pisa un PPP manual ya editado.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.GC_VIEW_PPP', 'V') IS NULL
    PRINT 'ATENCION: no existe dbo.GC_VIEW_PPP. La vista RO_V_CASHFLOW_PPP_GRUPO se crea igual pero va a fallar al consultarla.';
GO

IF OBJECT_ID('dbo.RO_V_CASHFLOW_PPP_GRUPO', 'V') IS NOT NULL
    DROP VIEW dbo.RO_V_CASHFLOW_PPP_GRUPO;
GO

/* Una fila por agrupador (grupo empresario, o el cliente si no tiene grupo).
   Solo agrupadores con recibos en la ventana: un grupo sin recibos no tiene
   fila, y el PHP lo trata como "sin PPP calculado". */
CREATE VIEW dbo.RO_V_CASHFLOW_PPP_GRUPO AS
WITH BASE AS (
    SELECT
        CASE WHEN NULLIF(LTRIM(RTRIM(G.GRUPO_EMPR)), '') IS NULL
             THEN LTRIM(RTRIM(V.COD_CLIENTE)) ELSE LTRIM(RTRIM(G.GRUPO_EMPR)) END AS COD_AGRUP,
        CASE WHEN NULLIF(LTRIM(RTRIM(G.GRUPO_EMPR)), '') IS NULL
             THEN V.RAZON_SOCIAL ELSE GE.NOMBRE_GRU END                            AS NOMBRE_AGRUP,
        CASE WHEN NULLIF(LTRIM(RTRIM(G.GRUPO_EMPR)), '') IS NULL
             THEN 0 ELSE 1 END                                                     AS ES_GRUPO,
        LTRIM(RTRIM(V.COD_CLIENTE))                                                AS COD_CLIENTE,
        CAST(V.PPP  AS DECIMAL(18,4))                                              AS PPP,
        CAST(V.DIAS AS DECIMAL(18,4))                                              AS DIAS
    FROM dbo.GC_VIEW_PPP V
    INNER JOIN dbo.GVA14 G  ON V.COD_CLIENTE = G.COD_CLIENT
    LEFT  JOIN dbo.GVA62 GE ON G.GRUPO_EMPR  = GE.GRUPO_EMPR
    WHERE V.FECHA_RECIBO >= DATEADD(DAY, -100, CAST(GETDATE() AS DATE))
      AND V.COD_CLIENTE LIKE 'FR%'
),
POR_CLIENTE AS (
    SELECT
        COD_AGRUP,
        MAX(NOMBRE_AGRUP) AS NOMBRE_AGRUP,
        MAX(ES_GRUPO)     AS ES_GRUPO,
        COD_CLIENTE,
        AVG(PPP)          AS PPP_CLI,
        AVG(DIAS)         AS DIAS_CLI,
        COUNT(*)          AS RECIBOS_CLI
    FROM BASE
    GROUP BY COD_AGRUP, COD_CLIENTE
)
SELECT
    COD_AGRUP,
    MAX(NOMBRE_AGRUP)            AS NOMBRE_AGRUP,
    MAX(ES_GRUPO)                AS ES_GRUPO,
    COUNT(*)                     AS CANT_CLIENTES,
    SUM(RECIBOS_CLI)             AS CANT_RECIBOS,
    ROUND(AVG(DIAS_CLI), 2)      AS DIAS,
    CAST(AVG(PPP_CLI) AS INT)    AS PPP
FROM POR_CLIENTE
GROUP BY COD_AGRUP;
GO

/* PPP manual por agrupador. Una fila por COD_AGRUP; PPP_MANUAL en NULL es "sin
   pisar" y la fila queda igual, con quien y cuando lo dejo asi. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO (
        COD_AGRUP    VARCHAR(20) NOT NULL,
        PPP_MANUAL   INT         NULL,
        FECHA_UPDATE DATETIME    NOT NULL
            CONSTRAINT DF_RO_T_CF_COB_PPPGRUPO_FECHA DEFAULT (GETDATE()),
        USUARIO      VARCHAR(50) NULL,
        CONSTRAINT PK_RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO PRIMARY KEY CLUSTERED (COD_AGRUP),
        CONSTRAINT CK_RO_T_CF_COB_PPPGRUPO_POS CHECK (PPP_MANUAL IS NULL OR PPP_MANUAL > 0)
    );
END
GO

/* Semilla: los PPP manuales por cliente que ya habia, llevados a su agrupador.
   MAX desempata cuando dos clientes del mismo grupo tenian valores distintos.
   WHEN NOT MATCHED: una segunda corrida no pisa lo editado desde la pantalla. */
MERGE dbo.RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO AS T
USING (
    SELECT
        CASE WHEN NULLIF(LTRIM(RTRIM(G.GRUPO_EMPR)), '') IS NULL
             THEN LTRIM(RTRIM(G.COD_CLIENT)) ELSE LTRIM(RTRIM(G.GRUPO_EMPR)) END AS COD_AGRUP,
        MAX(D.PPP_MANUAL) AS PPP_MANUAL
    FROM dbo.RO_T_PARAMETROS_DESC_CLIENTES D
    INNER JOIN dbo.GVA14 G
        ON G.COD_CLIENT COLLATE DATABASE_DEFAULT = D.COD_CLIENT COLLATE DATABASE_DEFAULT
    WHERE D.PPP_MANUAL IS NOT NULL AND D.PPP_MANUAL > 0
      AND G.COD_CLIENT LIKE 'FR%'
    GROUP BY
        CASE WHEN NULLIF(LTRIM(RTRIM(G.GRUPO_EMPR)), '') IS NULL
             THEN LTRIM(RTRIM(G.COD_CLIENT)) ELSE LTRIM(RTRIM(G.GRUPO_EMPR)) END
) AS S
    ON T.COD_AGRUP = S.COD_AGRUP COLLATE DATABASE_DEFAULT
WHEN NOT MATCHED BY TARGET THEN
    INSERT (COD_AGRUP, PPP_MANUAL, FECHA_UPDATE, USUARIO)
    VALUES (S.COD_AGRUP, S.PPP_MANUAL, GETDATE(), 'migracion');
GO

PRINT 'PPP por grupo empresario listo: vista RO_V_CASHFLOW_PPP_GRUPO y tabla RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO.';
GO
