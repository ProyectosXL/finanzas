/* ============================================================================
   MODULO CASHFLOW - GASTOS LOCALES: LOS PARAMETROS
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_cronograma_conceptos.sql (el cronograma por
            concepto). No depende de el para correr, pero sin el los otros
            conceptos no tienen parametros y la tarjeta del cronograma avisa.
            ANTES de sql/cashflow_estructura_gastos_locales.sql, que crea la
            fila del tablero.
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT

   TRES PARAMETROS en RO_T_CASHFLOW_PARAMETROS, modulo GENERALES:

     cronograma_gastos_locales_dia          1        el lunes (1 = lunes ... 7 = domingo)
     cronograma_gastos_locales_frecuencia   SEMANAL  FIJA: el codigo no admite otra
     gastos_locales_cuentas_excluidas       522400   las cuentas que NO entran en el
                                                     promedio, separadas por coma

   Los dos primeros van en el grupo 'CRONOGRAMA', como los de los otros
   conceptos: se cambian desde la tarjeta del cronograma de Parametros ->
   Generales (CronogramaDatos::guardarConfig()) y no desde el campo generico.

   El tercero va en el grupo 'GASTOS_LOCALES' y NO en 'GENERAL', por el mismo
   motivo: la seccion de generales dibuja como campo libre todo lo que esta en
   GENERAL, y una lista de cuentas tiene que pasar por su propia validacion
   (GastosLocales::validarCuentas()). Se edita en la tarjeta "Gastos Locales" de
   Parametros -> Generales.

   NO CREA NINGUNA TABLA. Los gastos se leen de CTA29 / CTA28 en el servidor
   'locales' cada vez que se piden: un promedio de los ultimos 90 dias no se
   guarda, se calcula.

   SOLO SE INSERTAN LOS QUE FALTAN: un valor ya ajustado desde la pantalla no se
   pisa. ES REEJECUTABLE.

   SI NO SE CORRE

   La pestana Proveedores -> Gastos Locales funciona igual: paga los lunes y
   excluye la 522400, que son los valores por defecto del codigo, y avisa que
   falta este script. Lo que no se puede es cambiar el dia ni la lista de
   cuentas. La fila del tablero no existe hasta correr el script de estructura.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guarda.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NULL
BEGIN
    RAISERROR('Corre primero sql/ventas_proyeccion.sql: no existe RO_T_CASHFLOW_PARAMETROS.', 16, 1);
END
GO

/* ============================================================================
   1. LOS TRES PARAMETROS
   ============================================================================ */
DECLARE @p TABLE (CLAVE VARCHAR(50), VALOR VARCHAR(200), TIPO_DATO VARCHAR(20),
                  DESCRIPCION VARCHAR(200), GRUPO VARCHAR(50));

INSERT INTO @p (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO) VALUES
 ('cronograma_gastos_locales_dia', '1', 'INT',
  'Dia de la semana en que se paga la semana de gastos de caja de los locales (1 = lunes ... 7 = domingo)',
  'CRONOGRAMA'),
 ('cronograma_gastos_locales_frecuencia', 'SEMANAL', 'TEXT',
  'Siempre SEMANAL: el importe de cada pago es una semana de gasto',
  'CRONOGRAMA'),
 ('gastos_locales_cuentas_excluidas', '522400', 'TEXT',
  'Cuentas de tesoreria que empiezan con 5 y NO entran en el promedio de gastos de caja de los locales, separadas por coma',
  'GASTOS_LOCALES');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    PRINT 'AVISO: RO_T_CASHFLOW_PARAMETROS no tiene MODULO. Los parametros se crearon igual; '
        + 'corre sql/migracion_parametros_modulo.sql y volve a correr este script para '
        + 'ponerlos en GENERALES.';
END
ELSE
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, p.GRUPO, 'GENERALES'
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    /* Donde se muestra, sin tocar el VALOR. */
    UPDATE x
    SET MODULO = 'GENERALES', GRUPO = p.GRUPO
    FROM dbo.RO_T_CASHFLOW_PARAMETROS x
    JOIN @p p ON p.CLAVE = x.CLAVE
    WHERE ISNULL(x.MODULO, '') <> 'GENERALES' OR ISNULL(x.GRUPO, '') <> p.GRUPO;
END

PRINT 'Parametros de Gastos Locales: listos (los que ya existian no se tocaron).';
GO

/* ============================================================================
   2. CONTROL: COMO QUEDO
   ============================================================================ */
SELECT CLAVE, VALOR, GRUPO
FROM dbo.RO_T_CASHFLOW_PARAMETROS
WHERE CLAVE LIKE 'cronograma[_]gastos[_]locales%' OR CLAVE = 'gastos_locales_cuentas_excluidas'
ORDER BY CLAVE;
GO
