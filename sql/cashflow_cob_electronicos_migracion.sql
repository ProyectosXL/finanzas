/* ============================================================================
   MODULO CASHFLOW - COB. ELECTRONICOS
   Migracion de los movimientos del Excel
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_cob_electronicos.sql, que crea las tablas y
            siembra las procesadoras y sus alicuotas.
   ----------------------------------------------------------------------------
   VA APARTE DEL DDL A PROPOSITO. El DDL se corre una vez por entorno y no
   depende de ningun dato del negocio; esto es una carga inicial que se corre
   UNA vez, contra un Excel puntual, y que el dia que se importe el archivo de
   la procesadora deja de tener sentido. Mezclarlos obligaria a mirar si el
   script "trae datos" cada vez que hay que recrear una tabla.

   ----------------------------------------------------------------------------
   SOLO SE MIGRAN LOS MOVIMIENTOS CON FECHA_ACREDITACION >= LA FECHA EN QUE SE
   CORRE ESTE SCRIPT

   Lo anterior a hoy YA SE ACREDITO: esa plata esta en la cuenta y la informa el
   saldo bancario de la pestana Saldos. Migrarla duplicaria plata en el tablero.
   Es la misma razon por la que el proveedor deja fuera del eje un movimiento con
   fecha pasada en lugar de reubicarlo en la primera columna.

   El corte es CAST(GETDATE() AS DATE) y no una fecha escrita: asi el criterio es
   el mismo el dia que se corra, y no depende de que alguien acuerde de
   actualizar una constante.

   Los tres movimientos que en el Excel nunca calcularon neto -D6 (Payway
   1.648.264,10 del 07/09), D7 (Payway 1.144.017,00 del 08/09) y D24 (Mercado
   Pago 43.150.368,26 del 07/09)- caen los tres en fechas ya pasadas, asi que
   con este criterio quedan fuera solos. IGUAL, ANTES DE CORRER ESTE SCRIPT
   CONVIENE VERIFICAR CON EL USUARIO que esos tres sean movimientos validos y no
   duplicados: es el unico dato de la hoja que no se puede reconstruir solo.

   ----------------------------------------------------------------------------
   EL NETO Y LA TASA SE RECALCULAN, NO SE COPIAN

   Del Excel se toman UNICAMENTE los tres datos de entrada: procesadora, importe
   bruto y fecha de acreditacion. La tasa y el neto los deriva este script con la
   misma regla que PHP -de cada CONCEPTO, la ultima vigencia con VIGENCIA_DESDE
   <= la fecha de acreditacion, sumadas- porque los valores de la hoja son
   justamente de donde viene el problema: D8:D43 tienen *0.969 escrito a mano y
   D6, D7 y D24 no tienen formula.

   El bloque F:AK de la hoja NO se migra: es la agrupacion contra el eje temporal
   y la deriva el proveedor. Ademas esta roto (AA5 es #!REF!, AB6:AB43 comparan
   contra $AB$2 en lugar de $AB$3, y las columnas mensuales devuelven FALSO por
   un IF sin rama else).

   Las fechas de la hoja son fechas reales de Excel, no seriales: no hace falta
   ninguna conversion de base 1900. Las filas 44 a 55 estan vacias con formulas
   listas y no se migran.

   ----------------------------------------------------------------------------
   ES REEJECUTABLE SIN DUPLICAR

   Cada movimiento entra por NOT EXISTS sobre (procesadora, fecha de
   acreditacion, importe bruto), que es la clave natural de una liquidacion
   informada. Una segunda corrida no inserta nada y tampoco pisa un movimiento
   que alguien haya editado desde la pantalla.

   Cuando exista la importacion del archivo de la procesadora, la clave va a ser
   ID_EXTERNO -que hoy queda en NULL- y ese indice unico filtrado ya esta creado.

   ORIGEN_DATO queda en 'MANUAL': el dato salio de una carga a mano, aunque la
   haya hecho este script. ARCHIVO_ORIGEN se deja en NULL porque no hubo archivo
   de la procesadora, y OBSERVACIONES deja dicho de donde vino la fila.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* La guarda corta el script entero y no solo este lote: sin SET NOEXEC ON, un
   RAISERROR deja seguir con el lote siguiente y el error real seria un "Invalid
   object name" en medio de la migracion. Se apaga al final del script. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO', 'U') IS NULL
    OR OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_ALICUOTA', 'U') IS NULL
    OR OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_PROCESADORA', 'U') IS NULL
BEGIN
    RAISERROR('Faltan las tablas del modulo. Corre primero sql/cashflow_cob_electronicos.sql.',
              16, 1);
    SET NOEXEC ON;
END
GO

DECLARE @HOY DATE = CAST(GETDATE() AS DATE);

/* ----------------------------------------------------------------------------
   Los 38 movimientos de la hoja (filas 6 a 43): 18 de Payway y 20 de Mercado
   Pago, 601.968.975,12 de bruto en total.

   Se cargan los tres datos de entrada y nada mas.
   ---------------------------------------------------------------------------- */
DECLARE @Excel TABLE (
    RAZON_SOCIAL       VARCHAR(80)   NOT NULL,
    IMPORTE_BRUTO      DECIMAL(19,4) NOT NULL,
    FECHA_ACREDITACION DATE          NOT NULL
);

INSERT INTO @Excel (RAZON_SOCIAL, IMPORTE_BRUTO, FECHA_ACREDITACION)
VALUES
    /* -- Payway: 18 movimientos ------------------------------------------- */
    ('Payway',        1648264.10, '2026-09-07'),   -- D6:  sin formula en el Excel
    ('Payway',        1144017.00, '2026-09-08'),   -- D7:  sin formula en el Excel
    ('Payway',        1069326.00, '2026-09-09'),
    ('Payway',        3757900.50, '2026-09-10'),
    ('Payway',       28620401.00, '2026-09-11'),
    ('Payway',        2487834.00, '2026-09-14'),
    ('Payway',        1787805.00, '2026-09-15'),
    ('Payway',        3280806.00, '2026-09-16'),
    ('Payway',        2145519.00, '2026-09-17'),
    ('Payway',        1094274.00, '2026-09-18'),
    ('Payway',         328770.00, '2026-09-21'),
    ('Payway',        1206747.00, '2026-09-22'),
    ('Payway',        1025640.00, '2026-09-23'),
    ('Payway',         461790.00, '2026-09-24'),
    ('Payway',          99000.00, '2026-09-25'),
    ('Payway',         647370.00, '2026-09-28'),
    ('Payway',        3662676.00, '2026-09-29'),
    ('Payway',        1151847.00, '2026-09-30'),

    /* -- Mercado Pago: 20 movimientos ------------------------------------- */
    ('Mercado Pago', 43150368.26, '2026-09-07'),   -- D24: sin formula en el Excel
    ('Mercado Pago', 22798184.75, '2026-09-08'),
    ('Mercado Pago', 35257406.00, '2026-09-09'),
    ('Mercado Pago', 24469320.51, '2026-09-10'),
    ('Mercado Pago', 23368722.84, '2026-09-11'),
    ('Mercado Pago', 31553248.46, '2026-09-12'),
    ('Mercado Pago', 51886008.88, '2026-09-13'),
    ('Mercado Pago', 36992032.23, '2026-09-14'),
    ('Mercado Pago', 21483087.73, '2026-09-15'),
    ('Mercado Pago', 25066421.70, '2026-09-16'),
    ('Mercado Pago', 22972469.78, '2026-09-17'),
    ('Mercado Pago', 31112231.87, '2026-09-18'),
    ('Mercado Pago', 37758527.98, '2026-09-19'),
    ('Mercado Pago', 61059211.78, '2026-09-20'),
    ('Mercado Pago', 43456727.18, '2026-09-21'),
    ('Mercado Pago', 13392407.72, '2026-09-22'),
    ('Mercado Pago', 10008445.26, '2026-09-23'),
    ('Mercado Pago', 10002053.27, '2026-09-24'),
    ('Mercado Pago',   465372.91, '2026-09-25'),
    ('Mercado Pago',    96739.41, '2026-09-26');

/* ----------------------------------------------------------------------------
   Que se va a migrar, resuelto con la tasa vigente A LA FECHA DE ACREDITACION
   de cada movimiento.

   El CROSS APPLY es la misma regla que CobElectronicos::tasaRetencion(): de cada
   CONCEPTO, la ultima vigencia con VIGENCIA_DESDE <= la fecha, con desempate por
   ID, y la suma de todas. Esta escrita dos veces -aca y en PHP- porque este
   script corre en SSMS sin PHP; es la unica duplicacion de la formula y esta
   deliberadamente calcada. La version de PHP es la que tiene pruebas.
   ---------------------------------------------------------------------------- */
DECLARE @Migrar TABLE (
    ID_PROCESADORA     INT           NULL,
    RAZON_SOCIAL       VARCHAR(80)   NOT NULL,
    IMPORTE_BRUTO      DECIMAL(19,4) NOT NULL,
    FECHA_ACREDITACION DATE          NOT NULL,
    TASA               DECIMAL(9,6)  NULL,
    CONCEPTOS          INT           NULL,
    MOTIVO             VARCHAR(120)  NOT NULL
);

INSERT INTO @Migrar (ID_PROCESADORA, RAZON_SOCIAL, IMPORTE_BRUTO, FECHA_ACREDITACION,
                     TASA, CONCEPTOS, MOTIVO)
SELECT P.ID,
       E.RAZON_SOCIAL,
       E.IMPORTE_BRUTO,
       E.FECHA_ACREDITACION,
       T.TASA,
       T.CONCEPTOS,
       CASE
           WHEN E.FECHA_ACREDITACION < @HOY
               THEN 'Ya acreditado: lo informa el saldo bancario de la pestana Saldos'
           WHEN P.ID IS NULL
               THEN 'La procesadora no existe: corre primero el DDL del modulo'
           WHEN ISNULL(T.CONCEPTOS, 0) = 0
               THEN 'La procesadora no tiene alicuotas vigentes a esa fecha'
           WHEN T.TASA >= 1
               THEN 'Las alicuotas vigentes suman 1 o mas: el neto seria cero o negativo'
           WHEN EXISTS (
                   SELECT 1 FROM dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO M
                   WHERE M.ID_PROCESADORA = P.ID
                     AND M.FECHA_ACREDITACION = E.FECHA_ACREDITACION
                     AND M.IMPORTE_BRUTO = E.IMPORTE_BRUTO
               )
               THEN 'Ya estaba cargado'
           ELSE 'MIGRA'
       END
FROM @Excel E
LEFT JOIN dbo.RO_T_CASHFLOW_COBEL_PROCESADORA P
    ON P.RAZON_SOCIAL = E.RAZON_SOCIAL
OUTER APPLY (
    SELECT ISNULL(SUM(V.ALICUOTA), 0) AS TASA, COUNT(*) AS CONCEPTOS
    FROM (
        SELECT A.ALICUOTA,
               ROW_NUMBER() OVER (
                   PARTITION BY A.CONCEPTO
                   ORDER BY A.VIGENCIA_DESDE DESC, A.ID DESC
               ) AS RN
        FROM dbo.RO_T_CASHFLOW_COBEL_ALICUOTA A
        WHERE A.ACTIVO = 1
          AND A.ID_PROCESADORA = P.ID
          AND A.VIGENCIA_DESDE <= E.FECHA_ACREDITACION
    ) V
    WHERE V.RN = 1
) T;

/* ----------------------------------------------------------------------------
   La insercion. El neto se calcula con la misma formula que PHP:
       importe neto = importe bruto * (1 - tasa)
   ---------------------------------------------------------------------------- */
INSERT INTO dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO
    (ID_PROCESADORA, IMPORTE_BRUTO, FECHA_ACREDITACION, TASA_APLICADA, IMPORTE_NETO,
     ORIGEN_DATO, ID_EXTERNO, ARCHIVO_ORIGEN, OBSERVACIONES, ACTIVO,
     FECHA_ALTA, FECHA_UPDATE, USUARIO)
SELECT M.ID_PROCESADORA,
       M.IMPORTE_BRUTO,
       M.FECHA_ACREDITACION,
       M.TASA,
       CAST(M.IMPORTE_BRUTO * (1 - M.TASA) AS DECIMAL(19,4)),
       'MANUAL',
       NULL,   -- lo va a llenar la importacion del archivo de la procesadora
       NULL,
       'Migrado del Excel de cobranzas electronicas',
       1,
       GETDATE(),
       GETDATE(),
       NULL    -- todavia no hay login
FROM @Migrar M
WHERE M.MOTIVO = 'MIGRA';

/* ----------------------------------------------------------------------------
   Que paso con cada fila de la hoja. Se informa TODO, incluido lo que no se
   migro y por que: una migracion que no dice que dejo afuera es una migracion
   que informa de menos en silencio.
   ---------------------------------------------------------------------------- */
SELECT MOTIVO,
       COUNT(*)                 AS MOVIMIENTOS,
       SUM(IMPORTE_BRUTO)       AS IMPORTE_BRUTO,
       MIN(FECHA_ACREDITACION)  AS DESDE,
       MAX(FECHA_ACREDITACION)  AS HASTA
FROM @Migrar
GROUP BY MOTIVO
ORDER BY CASE WHEN MOTIVO = 'MIGRA' THEN 0 ELSE 1 END, MOTIVO;

SELECT RAZON_SOCIAL,
       FECHA_ACREDITACION,
       IMPORTE_BRUTO,
       TASA                                                   AS TASA_APLICADA,
       CAST(IMPORTE_BRUTO * (1 - TASA) AS DECIMAL(19,4))       AS IMPORTE_NETO,
       MOTIVO
FROM @Migrar
ORDER BY MOTIVO, FECHA_ACREDITACION, RAZON_SOCIAL;

PRINT 'Migracion de Cob. Electronicos terminada. Revisa los dos cuadros de arriba: el primero '
    + 'resume que se migro y que quedo afuera, el segundo lo detalla fila por fila.';
GO

SET NOEXEC OFF;
GO
