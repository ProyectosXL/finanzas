/* ============================================================================
   MODULO CASHFLOW - PROVEEDORES LOCALES
   Alinea la collation de las claves de cruce con la de Tango
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ejecutar DESPUES de sql/cashflow_prov_locales.sql
   ----------------------------------------------------------------------------
   QUE CORRIGE

   Las dos tablas del modulo se crearon sin declarar collation, asi que tomaron
   la de la base: Modern_Spanish_CI_AI. Las columnas con las que se cruza contra
   Tango tienen OTRA:

       CPA01.COD_PROVEE                    Latin1_General_BIN
       CPA04.COD_PROVEE / T_COMP / N_COMP  Latin1_General_BIN

   CI_AI significa Case Insensitive y Accent Insensitive: para SQL Server, en
   NUESTRAS tablas, 'OGNUNE' y 'OGNUÑE' son el MISMO valor. En Tango no, porque
   BIN compara byte a byte.

   POR QUE IMPORTA, SI HOY NO HAY NINGUNA COLISION

   Hoy no la hay -se verifico: cero pares de codigos de CPA01 que sean iguales
   bajo CI_AI y distintos bajo BIN-, asi que esto no esta rompiendo nada. Pero
   el maestro tiene 27 proveedores con caracteres no ASCII en el codigo (eñes,
   '&', '+'), y el dia que alguien de alta un 'OGNUNE' al lado del 'OGNUÑE' que
   ya existe:

     - el UPDATE de baja del maestro daria de baja LOS DOS;
     - la fecha de pago de uno se le aplicaria al otro.

   Ninguna de las dos cosas daria error: darian el resultado equivocado en
   silencio, que es la peor forma de fallar de este modulo.

   El codigo PHP no tiene ese problema -indexa en arreglos, que si distinguen-,
   asi que la discrepancia sera justamente entre lo que la pantalla muestra y lo
   que la base hace.

   ES UN ALTER SOBRE TABLAS QUE PUEDEN TENER DATOS. Cambiar la collation de una
   columna reescribe la columna; con el maestro cargado son unos miles de filas
   y tarda lo que tarda un UPDATE de esa cantidad. Conviene correrlo ANTES de la
   primera importacion del maestro, que es cuando las tablas estan vacias.

   Para poder alterarlas hay que sacar primero las restricciones y los indices
   que las tocan, y volver a ponerlos.

   ES REEJECUTABLE: si la collation ya es la correcta, no hace nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   1. El maestro
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM sys.columns c
    WHERE c.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG')
      AND c.name = 'COD_PROVEE'
      AND c.collation_name <> 'Latin1_General_BIN'
)
BEGIN
    /* El indice incluye la columna, asi que hay que sacarlo antes. */
    IF EXISTS (SELECT 1 FROM sys.indexes
                WHERE name = 'IX_RO_T_CF_PLCAT_VIGENTE'
                  AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG'))
        DROP INDEX IX_RO_T_CF_PLCAT_VIGENTE ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG;

    IF EXISTS (SELECT 1 FROM sys.indexes
                WHERE name = 'IX_RO_T_CF_PLCAT_PROVEE'
                  AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG'))
        DROP INDEX IX_RO_T_CF_PLCAT_PROVEE ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG
        ALTER COLUMN COD_PROVEE VARCHAR(6) COLLATE Latin1_General_BIN NOT NULL;

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLCAT_VIGENTE
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG (VIGENTE, COD_PROVEE)
        INCLUDE (RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS, FORMA_PAGO, PLAZO_DIAS);

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLCAT_PROVEE
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG (COD_PROVEE, ID);

    PRINT 'Maestro: COD_PROVEE pasa a Latin1_General_BIN.';
END
ELSE
BEGIN
    PRINT 'Maestro: la collation ya es la correcta.';
END
GO

/* ----------------------------------------------------------------------------
   2. Las fechas de pago

   Son TRES columnas y las tres forman la clave unica, asi que hay que sacar la
   restriccion antes de alterarlas.
   ---------------------------------------------------------------------------- */
IF EXISTS (
    SELECT 1 FROM sys.columns c
    WHERE c.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO')
      AND c.name IN ('COD_PROVEE', 'T_COMP', 'N_COMP')
      AND c.collation_name <> 'Latin1_General_BIN'
)
BEGIN
    IF EXISTS (SELECT 1 FROM sys.indexes
                WHERE name = 'IX_RO_T_CF_PLPAG_ESTADO'
                  AND object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO'))
        DROP INDEX IX_RO_T_CF_PLPAG_ESTADO ON dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO;

    IF EXISTS (SELECT 1 FROM sys.key_constraints
                WHERE name = 'UQ_RO_T_CASHFLOW_PROV_LOCALES_PAGO')
        ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
            DROP CONSTRAINT UQ_RO_T_CASHFLOW_PROV_LOCALES_PAGO;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ALTER COLUMN COD_PROVEE VARCHAR(6) COLLATE Latin1_General_BIN NOT NULL;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ALTER COLUMN T_COMP VARCHAR(3) COLLATE Latin1_General_BIN NOT NULL;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ALTER COLUMN N_COMP VARCHAR(14) COLLATE Latin1_General_BIN NOT NULL;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO
        ADD CONSTRAINT UQ_RO_T_CASHFLOW_PROV_LOCALES_PAGO
            UNIQUE (COD_PROVEE, T_COMP, N_COMP);

    CREATE NONCLUSTERED INDEX IX_RO_T_CF_PLPAG_ESTADO
        ON dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO (ESTADO, FECHA_PAGO)
        INCLUDE (COD_PROVEE, T_COMP, N_COMP, FORMA_PAGO);

    PRINT 'Pagos: las tres claves pasan a Latin1_General_BIN.';
END
ELSE
BEGIN
    PRINT 'Pagos: la collation ya es la correcta.';
END
GO

/* ----------------------------------------------------------------------------
   3. Verificacion

   Lo que tiene que dar: las siete columnas en Latin1_General_BIN.
   ---------------------------------------------------------------------------- */
SELECT OBJECT_NAME(c.object_id) AS TABLA, c.name AS COLUMNA, c.collation_name
FROM sys.columns c
WHERE (c.object_id = OBJECT_ID('CPA01') AND c.name = 'COD_PROVEE')
   OR (c.object_id = OBJECT_ID('CPA04') AND c.name IN ('COD_PROVEE', 'T_COMP', 'N_COMP'))
   OR (c.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG')
       AND c.name = 'COD_PROVEE')
   OR (c.object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_LOCALES_PAGO')
       AND c.name IN ('COD_PROVEE', 'T_COMP', 'N_COMP'))
ORDER BY TABLA, COLUMNA;
GO
