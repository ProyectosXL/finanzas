/* ============================================================================
   MIGRACION: columna MODULO en RO_T_CASHFLOW_PARAMETROS
   ----------------------------------------------------------------------------
   Base: central

   Para que hace falta:
   La pestana Parametros agrupa los valores en sub-pestanas por modulo, asi se
   entiende de un vistazo que pestana de la aplicacion afecta cada parametro.
   MODULO es la columna que hace esa atribucion. GRUPO queda como la seccion
   dentro del modulo (GENERAL, RESPALDO, ...).

   Correr este script si la tabla RO_T_CASHFLOW_PARAMETROS ya existia de una
   version anterior. Es equivalente al bloque que ya trae
   sql/ventas_proyeccion.sql: alcanza con correr cualquiera de los dos.

   Es idempotente y NO toca ningun VALOR ya editado: solo agrega la columna y
   completa el modulo de los parametros que ya estan cargados.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NULL
BEGIN
    RAISERROR ('No existe RO_T_CASHFLOW_PARAMETROS. Corre primero sql/ventas_proyeccion.sql', 16, 1);
END
GO

/* 1. Agregar la columna si falta */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_PARAMETROS', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_PARAMETROS
        ADD MODULO VARCHAR(30) NOT NULL
            CONSTRAINT DF_PARAMETROS_MODULO DEFAULT ('VENTAS');

    PRINT 'Columna MODULO agregada.';
END
ELSE
BEGIN
    PRINT 'La columna MODULO ya existia: no se hizo nada.';
END
GO

/* 2. Completar el modulo de los parametros ya cargados.
      Todos los que existen hoy son del modulo de ventas. */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') IS NOT NULL
BEGIN
    UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
    SET MODULO = 'VENTAS'
    WHERE MODULO IS NULL OR MODULO = '';

    PRINT 'Parametros sin modulo asignados a VENTAS: ' + CAST(@@ROWCOUNT AS VARCHAR(10));
END
GO

/* 3. Control: como quedo */
SELECT MODULO, GRUPO, COUNT(*) AS PARAMETROS
FROM dbo.RO_T_CASHFLOW_PARAMETROS
GROUP BY MODULO, GRUPO
ORDER BY MODULO, GRUPO;
GO
