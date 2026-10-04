/* ============================================================================
   MODULO CASHFLOW - UN CRONOGRAMA DE PAGOS POR CONCEPTO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_parametros_generales.sql, que crea
            RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT. Si esa tabla no existe, este
            script siembra los parametros igual y avisa que falta la tabla.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   Hasta ahora habia UN cronograma -el 2do y el 4to viernes, con el viernes
   escrito en el codigo- y lo usaban Logistica Local y la parte en efectivo de
   Gastos Supervisoras. Pasa a haber uno por concepto, con su dia de la semana
   y su frecuencia:

     concepto       dia         frecuencia   lo usa
     PROV_LOCALES   miercoles   QUINCENAL    Cuentas a Pagar Locales
     LOGISTICA      viernes     QUINCENAL    Logistica Local (sin cambios)
     SUPERVISORAS   lunes       SEMANAL      efectivo de Gastos Supervisoras

   QUINCENAL es el 2do y el 4to de ese dia en el mes; SEMANAL, todos los de ese
   dia en el mes (cuatro o cinco). Ver Class/CronogramaPagos.php.

   1. SEIS PARAMETROS en RO_T_CASHFLOW_PARAMETROS, modulo GENERALES:
        cronograma_<concepto>_dia          1..7 ISO (1 = lunes)
        cronograma_<concepto>_frecuencia   QUINCENAL o SEMANAL
      Solo se insertan los que faltan: un valor ya ajustado desde la pantalla no
      se pisa.

      VAN EN EL GRUPO 'CRONOGRAMA' Y NO EN 'GENERAL', y no es estetica: la
      seccion de generales de la sub-pestana dibuja como campo editable todo lo
      que esta en GENERAL, y guardar desde ahi pasaria por saveParametro(), que
      NO da de baja los overrides. Cambiar el dia de un concepto tiene que pasar
      por su propio endpoint (CronogramaDatos::guardarConfig()), que si lo hace.

   2. RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT suma la columna TIPO -el concepto-,
      NOT NULL con default 'LOGISTICA': los overrides que ya existen son TODOS de
      Logistica, porque era el unico cronograma con overrides editables.

   3. El CHECK de NRO_PAGO pasa de (1, 2) a 1..5: en SEMANAL un mes puede tener
      cinco pagos.

   4. El indice unico filtrado pasa de (MES, NRO_PAGO) a (TIPO, MES, NRO_PAGO)
      WHERE VIGENTE = 1: el pago 1 de octubre de Logistica y el de Proveedores
      Locales son dos pagos distintos y pueden tener cada uno su override.

   ----------------------------------------------------------------------------
   POR QUE CAMBIAR EL DIA DA DE BAJA LOS OVERRIDES

   El override se guarda contra (concepto, mes, numero de pago), no contra una
   fecha. Con otro dia o frecuencia el pago 2 de un mes apunta a otra fecha, y
   el override quedaria pegado a un pago que ya no es el mismo. Por eso el
   endpoint que guarda la configuracion da de baja (VIGENTE = 0, con usuario y
   fecha de baja) los overrides vigentes de ese concepto con mes >= el actual,
   despues de pedir confirmacion con cuantos son. Este script no da de baja
   nada: no cambia ninguna configuracion.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   Nada falla. Cada concepto usa su valor por defecto -miercoles quincenal,
   viernes quincenal, lunes semanal- y Parametros -> Generales lo avisa. Lo que
   no se puede es cambiar el dia ni la frecuencia, ni mover a mano un pago de
   Proveedores Locales o de Supervisoras: los overrides que ya existen siguen
   valiendo para Logistica, que es de quien son.

   OJO CON LAS SUPERVISORAS: con el codigo nuevo publicado, el efectivo de
   Gastos Supervisoras pasa de dos viernes a los lunes del mes AUNQUE no se
   corra este script, porque ese es su valor por defecto.

   ES REEJECUTABLE: los parametros preguntan por CLAVE, la columna por
   COL_LENGTH, y el CHECK y el indice se recrean SOLO si todavia tienen la
   forma vieja. La segunda corrida no modifica nada. No borra ninguna fila.
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
   1. LOS SEIS PARAMETROS
   ============================================================================ */
DECLARE @p TABLE (CLAVE VARCHAR(50), VALOR VARCHAR(200), TIPO_DATO VARCHAR(20),
                  DESCRIPCION VARCHAR(200));

INSERT INTO @p (CLAVE, VALOR, TIPO_DATO, DESCRIPCION) VALUES
 ('cronograma_prov_locales_dia', '3', 'INT',
  'Dia de la semana en que se paga a los proveedores locales del cronograma (1 = lunes ... 7 = domingo)'),
 ('cronograma_prov_locales_frecuencia', 'QUINCENAL', 'TEXT',
  'QUINCENAL (el 2do y el 4to de ese dia) o SEMANAL (todos los de ese dia) para Proveedores Locales'),
 ('cronograma_logistica_dia', '5', 'INT',
  'Dia de la semana en que se paga a los fleteros de Logistica Local (1 = lunes ... 7 = domingo)'),
 ('cronograma_logistica_frecuencia', 'QUINCENAL', 'TEXT',
  'QUINCENAL (el 2do y el 4to de ese dia) o SEMANAL (todos los de ese dia) para Logistica Local'),
 ('cronograma_supervisoras_dia', '1', 'INT',
  'Dia de la semana en que se paga el efectivo de Gastos Supervisoras (1 = lunes ... 7 = domingo)'),
 ('cronograma_supervisoras_frecuencia', 'SEMANAL', 'TEXT',
  'QUINCENAL (el 2do y el 4to de ese dia) o SEMANAL (todos los de ese dia) para Gastos Supervisoras');

IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, 'CRONOGRAMA'
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    PRINT 'AVISO: RO_T_CASHFLOW_PARAMETROS no tiene MODULO. Los parametros se crearon igual; '
        + 'corre sql/migracion_parametros_modulo.sql y volve a correr este script para '
        + 'ponerlos en GENERALES.';
END
ELSE
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_PARAMETROS (CLAVE, VALOR, TIPO_DATO, DESCRIPCION, GRUPO, MODULO)
    SELECT p.CLAVE, p.VALOR, p.TIPO_DATO, p.DESCRIPCION, 'CRONOGRAMA', 'GENERALES'
    FROM @p p
    WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS x WHERE x.CLAVE = p.CLAVE);

    /* Donde se muestra, sin tocar el VALOR. */
    UPDATE x
    SET MODULO = 'GENERALES', GRUPO = 'CRONOGRAMA'
    FROM dbo.RO_T_CASHFLOW_PARAMETROS x
    JOIN @p p ON p.CLAVE = x.CLAVE
    WHERE ISNULL(x.MODULO, '') <> 'GENERALES' OR ISNULL(x.GRUPO, '') <> 'CRONOGRAMA';
END

PRINT 'Parametros del cronograma por concepto: listos (los que ya existian no se tocaron).';
GO

/* ============================================================================
   2. EL CONCEPTO EN LOS OVERRIDES
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NULL
BEGIN
    PRINT 'AVISO: no existe RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT. Corre '
        + 'sql/cashflow_parametros_generales.sql y volve a correr este script. Mientras '
        + 'tanto ningun pago se puede mover a mano.';
END
ELSE IF COL_LENGTH('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'TIPO') IS NULL
BEGIN
    /* El DEFAULT se crea con WITH VALUES: las filas que ya existen toman
       'LOGISTICA', que es de quien son. */
    ALTER TABLE dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT
        ADD TIPO VARCHAR(20) NOT NULL
            CONSTRAINT DF_RO_T_CF_CRONOED_TIPO DEFAULT ('LOGISTICA') WITH VALUES;

    PRINT 'Agregada la columna TIPO: los overrides existentes quedaron como LOGISTICA.';
END
ELSE
    PRINT 'La columna TIPO ya existia.';
GO

/* La lista cerrada de conceptos va en la base y no solo en el PHP: el endpoint
   es alcanzable sin pasar por la pantalla. */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'TIPO') IS NOT NULL
   AND OBJECT_ID('dbo.CK_RO_T_CF_CRONOED_TIPO', 'C') IS NULL
BEGIN
    EXEC ('ALTER TABLE dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT
               ADD CONSTRAINT CK_RO_T_CF_CRONOED_TIPO
                   CHECK (TIPO IN (''PROV_LOCALES'', ''LOGISTICA'', ''SUPERVISORAS''))');

    PRINT 'Creado CK_RO_T_CF_CRONOED_TIPO.';
END
GO

/* ============================================================================
   3. EL CHECK DE NRO_PAGO: 1..5
   Se recrea SOLO si todavia no admite el 5. Mirar la definicion y no el nombre
   es lo que hace al script reejecutable sin tocar nada la segunda vez.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NOT NULL
BEGIN
    DECLARE @def NVARCHAR(MAX) = (SELECT definition FROM sys.check_constraints
                                  WHERE name = 'CK_RO_T_CF_CRONOED_NRO'
                                    AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT'));

    IF @def IS NOT NULL AND @def NOT LIKE '%5%'
    BEGIN
        ALTER TABLE dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT DROP CONSTRAINT CK_RO_T_CF_CRONOED_NRO;
        PRINT 'CK_RO_T_CF_CRONOED_NRO viejo (1, 2) dado de baja.';
    END

    IF OBJECT_ID('dbo.CK_RO_T_CF_CRONOED_NRO', 'C') IS NULL
    BEGIN
        ALTER TABLE dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT
            ADD CONSTRAINT CK_RO_T_CF_CRONOED_NRO CHECK (NRO_PAGO BETWEEN 1 AND 5);

        PRINT 'CK_RO_T_CF_CRONOED_NRO: NRO_PAGO entre 1 y 5.';
    END
    ELSE
        PRINT 'CK_RO_T_CF_CRONOED_NRO ya admitia de 1 a 5.';
END
GO

/* ============================================================================
   4. EL INDICE UNICO: (TIPO, MES, NRO_PAGO) WHERE VIGENTE = 1
   Se recrea SOLO si no incluye TIPO.
   ============================================================================ */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'TIPO') IS NOT NULL
BEGIN
    IF EXISTS (SELECT 1 FROM sys.indexes i
               WHERE i.name = 'UX_RO_T_CF_CRONOED_VIGENTE'
                 AND i.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT'))
       AND NOT EXISTS (SELECT 1
                       FROM sys.indexes i
                       JOIN sys.index_columns ic ON ic.object_id = i.object_id
                                                AND ic.index_id = i.index_id
                       JOIN sys.columns c ON c.object_id = ic.object_id
                                         AND c.column_id = ic.column_id
                       WHERE i.name = 'UX_RO_T_CF_CRONOED_VIGENTE'
                         AND i.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT')
                         AND c.name = 'TIPO')
    BEGIN
        DROP INDEX UX_RO_T_CF_CRONOED_VIGENTE ON dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT;
        PRINT 'UX_RO_T_CF_CRONOED_VIGENTE viejo (MES, NRO_PAGO) dado de baja.';
    END

    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE name = 'UX_RO_T_CF_CRONOED_VIGENTE'
                     AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT'))
    BEGIN
        /* Por EXEC: el lote se compila entero antes de correr, y en una base
           sin la columna TIPO -la que corta el IF de arriba- un CREATE INDEX
           escrito directo podria fallar al compilar. */
        EXEC ('CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_CRONOED_VIGENTE
                   ON dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT (TIPO, MES, NRO_PAGO)
                   WHERE VIGENTE = 1');

        PRINT 'UX_RO_T_CF_CRONOED_VIGENTE: un override vigente por (TIPO, MES, NRO_PAGO).';
    END
    ELSE
        PRINT 'UX_RO_T_CF_CRONOED_VIGENTE ya incluia TIPO.';
END
GO

/* ============================================================================
   5. CONTROL: COMO QUEDO
   ============================================================================ */
SELECT CLAVE, VALOR, GRUPO
FROM dbo.RO_T_CASHFLOW_PARAMETROS
WHERE CLAVE LIKE 'cronograma[_]%'
ORDER BY CLAVE;

IF OBJECT_ID('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT', 'TIPO') IS NOT NULL
    EXEC ('SELECT TIPO, VIGENTE, COUNT(*) AS OVERRIDES
           FROM dbo.RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT
           GROUP BY TIPO, VIGENTE ORDER BY TIPO, VIGENTE');
GO
