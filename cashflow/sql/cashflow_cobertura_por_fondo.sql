/* ============================================================================
   MODULO CASHFLOW - LA COBERTURA SE CONSUME POR FONDO, Y EN SU MONEDA
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : despues de sql/cashflow_cobertura.sql y de
             sql/cashflow_dolares_comitente_cobertura.sql.
   ----------------------------------------------------------------------------
   QUE RESUELVE

   Aplicar cobertura ya se podia: se carga cuanta plata se usa y en que fecha. Lo
   que faltaba son dos cosas, y las dos salen del mismo lugar.

   1. EL ORIGEN ERA UNA ETIQUETA

   RO_T_CASHFLOW_COBERTURA_APLIC ya guarda ORIGEN -INVERSIONES, SUSCRIPCION,
   DOLARES- pero el encabezado de Class/Cobertura.php lo decia sin vueltas:

       "OJO: el origen es DESCRIPTIVO. Hoy el unico stock que el tablero conoce
        es el saldo de inversiones en pesos, asi que el origen no limita cuanto
        se puede aplicar; dice de donde se piensa sacar."

   Habia UN pozo: el tablero sumaba todos los stocks, sumaba todos los usos, y
   avisaba si se habia aplicado de mas. Se podian aplicar 300 millones "de
   dolares" sin que nada se quejara, mientras el total alcanzara.

   Desde que Dolares Cuenta Comitente es un stock, hay DOS fondos y el origen
   pasa a decidir de cual se descuenta. El saldo de cada uno es lo suyo menos lo
   suyo aplicado.

   QUE FONDO ES CADA STOCK no se guarda en la base: lo declara el registro, en
   'origen_cobertura'. Es una propiedad del MODULO que informa ese saldo -Otros
   Ingresos sabe que su saldo de inversiones es el fondo INVERSIONES- y no una
   configuracion de la fila del tablero. Si estuviera en CONF_FILA seria un dato
   que se puede contradecir con el proveedor que la fila ya declara.

   2. LOS DOLARES NO SE CONSUMEN EN PESOS

   Esta tabla guarda IMPORTE sin decir en que moneda, porque hasta ahora todo era
   pesos. El stock de dolares no lo es.

   Cargar el consumo en pesos dejaria el remanente EN DOLARES moviendose solo con
   la cotizacion: hoy quedan 46.000 dolares y manana 44.800 sin que nadie haya
   tocado nada. Lo que pasa de verdad es que se venden 20.000 dolares un dia, y
   eso es un numero estable.

   Por eso la aplicacion gana MONEDA:

       ARS   el importe es en pesos y entra tal cual. Es lo que habia y sigue
             siendo el default, asi que las aplicaciones cargadas no cambian.
       USD   el importe es en DOLARES y se valua con la cotizacion del dia de la
             aplicacion, punta VENDEDORA, que es la misma con la que se valua el
             saldo. Ver Cotizacion::ultimaHasta().

   ----------------------------------------------------------------------------
   LAS FILAS QUE YA ESTAN

   Quedan en 'ARS', que es lo que son. Al escribir esto no hay ninguna aplicacion
   cargada, asi que no hay nada que migrar.

   ES REEJECUTABLE: cada paso pregunta si ya esta hecho.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'U') IS NULL
BEGIN
    RAISERROR('Falta la tabla RO_T_CASHFLOW_COBERTURA_APLIC. Corre primero sql/cashflow_cobertura.sql.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   1. La moneda de la aplicacion, con su default.

   VARCHAR(3) y no un bit: 'ARS' / 'USD' se leen solos en una consulta a mano, y
   un bit llamado ES_USD obliga a recordar de que lado es cual. Es el mismo
   criterio que la columna ORIGEN que esta tabla ya tiene.
   ---------------------------------------------------------------------------- */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'MONEDA') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_COBERTURA_APLIC
        ADD MONEDA VARCHAR(3) NOT NULL
            CONSTRAINT DF_RO_T_CF_COBAPLIC_MONEDA DEFAULT ('ARS');
END
GO

/* ----------------------------------------------------------------------------
   2. Lo que ya estaba es en pesos.

   El ADD con DEFAULT ya rellena las filas existentes; el UPDATE va igual y es
   barato, por si alguien agrego la columna a mano sin default. Una aplicacion
   sin moneda no se puede valuar y quedaria fuera del cuadro sin aviso.
   ---------------------------------------------------------------------------- */
UPDATE dbo.RO_T_CASHFLOW_COBERTURA_APLIC
SET MONEDA = 'ARS'
WHERE MONEDA IS NULL OR LTRIM(RTRIM(MONEDA)) = '';
GO

/* ----------------------------------------------------------------------------
   3. Control: la moneda solo puede ser una de las dos que el codigo sabe valuar.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC
    WHERE MONEDA NOT IN ('ARS', 'USD')
)
BEGIN
    RAISERROR('ATENCION: hay aplicaciones de cobertura con una moneda que no es ARS ni USD.', 16, 1);
END
GO

/* ----------------------------------------------------------------------------
   4. Control: una aplicacion en dolares tiene que salir del fondo de dolares.

   No es una restriccion del motor -el origen dice de donde sale la plata y la
   moneda en que se mide- pero las dos juntas describen un solo hecho, y verlas
   contradecirse es siempre un error de carga. Se avisa y no se bloquea, que es
   el criterio del modulo.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM dbo.RO_T_CASHFLOW_COBERTURA_APLIC
    WHERE VIGENTE = 1 AND MONEDA = 'USD' AND ORIGEN <> 'DOLARES'
)
BEGIN
    PRINT 'AVISO: hay aplicaciones en dolares cuyo origen no es DOLARES. Revisalas.';
END
GO

PRINT 'Cobertura por fondo y en moneda lista.';
GO
