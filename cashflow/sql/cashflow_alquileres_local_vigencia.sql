/* ============================================================================
   MODULO CASHFLOW - ALQUILERES: PROYECTAR SEGUN APERTURA O SEGUN VIGENCIA
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_alquileres.sql (script 51)
   ----------------------------------------------------------------------------
   QUE AGREGA ESTE SCRIPT, a RO_T_CASHFLOW_ALQ_LOCAL

     MODO_PROYECCION   'APERTURA' (o NULL): el local proyecta mientras este
                       abierto en Lakers. El vencimiento de su ultimo
                       contrato en la app solo avisa: se asume la renovacion.
                       'VIGENCIA': el local proyecta SOLO en los meses de sus
                       contratos de la app de alquileres
                       (RO_T_CONTRATOS_ALQUILERES, VIG_DESDE a VIG_HASTA). Es
                       para los locales temporales: XL RURAL.
     FECHA_INICIO      SIN USO: se agrego para cargar el contrato a mano, y la
                       vigencia sale de la tabla de contratos de la app. Queda
                       vacia; FECHA_FIN tampoco se usa ya.

   NULL ES APERTURA: las filas que ya existen siguen como hoy, sin migrar nada.

   ES REEJECUTABLE: pregunta por COL_LENGTH y por el CHECK. No borra nada.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'U') IS NULL
BEGIN
    PRINT 'Falta dbo.RO_T_CASHFLOW_ALQ_LOCAL: correr antes sql/cashflow_alquileres.sql.';
END
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'MODO_PROYECCION') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL ADD MODO_PROYECCION VARCHAR(10) NULL;
    PRINT 'Agregada RO_T_CASHFLOW_ALQ_LOCAL.MODO_PROYECCION.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_ALQ_LOCAL.MODO_PROYECCION ya existe (o falta la tabla): no se toca.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'FECHA_INICIO') IS NULL
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL ADD FECHA_INICIO DATE NULL;
    PRINT 'Agregada RO_T_CASHFLOW_ALQ_LOCAL.FECHA_INICIO.';
END
ELSE
    PRINT 'RO_T_CASHFLOW_ALQ_LOCAL.FECHA_INICIO ya existe (o falta la tabla): no se toca.';
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'MODO_PROYECCION') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_ALQLOC_MODO')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL ADD CONSTRAINT CK_RO_T_CF_ALQLOC_MODO
        CHECK (MODO_PROYECCION IS NULL OR MODO_PROYECCION IN ('APERTURA', 'VIGENCIA'));
    PRINT 'Creado CK_RO_T_CF_ALQLOC_MODO.';
END
GO

IF COL_LENGTH('dbo.RO_T_CASHFLOW_ALQ_LOCAL', 'FECHA_INICIO') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_ALQLOC_VIGENCIA')
BEGIN
    ALTER TABLE dbo.RO_T_CASHFLOW_ALQ_LOCAL ADD CONSTRAINT CK_RO_T_CF_ALQLOC_VIGENCIA
        CHECK (FECHA_INICIO IS NULL OR FECHA_FIN IS NULL OR FECHA_INICIO <= FECHA_FIN);
    PRINT 'Creado CK_RO_T_CF_ALQLOC_VIGENCIA (inicio <= fin).';
END
GO
