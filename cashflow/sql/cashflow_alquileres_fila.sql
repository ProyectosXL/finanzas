/* ============================================================================
   MODULO CASHFLOW - LA FILA "ALQUILERES" DEL TABLERO, REAL Y PROYECTADO
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : ULTIMO de los de Alquileres: DESPUES de sql/cashflow_alquileres.sql,
            sql/cashflow_alquileres_facturas.sql y
            sql/cashflow_estructura_grupos.sql (las columnas GRUPO, NATURALEZA y
            GRUPO_NOMBRE). Sin el de grupos, las filas se crean igual pero
            sueltas, y lo dice.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   La fila ALQUILERES de Costos Indirectos (la de sql/cashflow_estructura.sql,
   ALQUILERES / PAGOS, ID 16 en produccion) se reemplaza por DOS FILAS
   AGRUPADAS, una por serie:

     ALQUILERES_REAL   'Alquileres'  EGRESO  ALQUILERES / REAL        NATURALEZA REAL
     ALQUILERES_PROY   'Alquileres'  EGRESO  ALQUILERES / PROYECTADO  NATURALEZA PROYECTADO

   con GRUPO = 'ALQUILERES': el tablero las dibuja en UN renglon que se abre en
   real y proyectado. La fila vieja queda INHABILITADA, no se borra.

   REAL son las facturas de Tango imputadas en la pestaña, en su fecha de pago y
   por su a pagar. PROYECTADO, la proyeccion por local que ninguna factura
   reemplazo. PAGOS es el total de las dos: por eso la vieja y las nuevas no
   pueden quedar activas a la vez -el validador lo rechaza- y el cambio va en
   UNA transaccion.

   ----------------------------------------------------------------------------
   LAS FACTURAS DE ALQUILER Y CUENTAS A PAGAR LOCALES

   Las facturas de las razones sociales dadas de alta en Parametros ->
   Alquileres salen de Proveedores Locales (de PAGOS y de PAGOS_TODO) cuando hay
   una fila activa con ALQUILERES / REAL o PAGOS y existen las tablas de
   sql/cashflow_alquileres_facturas.sql. Lo decide el codigo
   (AlquileresFacturas), no este script: el control del pie verifica que se
   cumpla y que ninguna fila de Proveedores Locales lea PAGOS_ALQUILERES, la
   serie informativa donde quedan. Mismo criterio que
   sql/cashflow_tarjetas_fila.sql.

   EL DIA QUE SE CORRE, si la fila vieja ya estaba activa (asi esta en
   produccion), el tablero NO SE MUEVE: REAL + PROYECTADO es lo mismo que PAGOS.
   Solo cambia el dibujo: un renglon que se abre.

   ES REEJECUTABLE: las filas se crean con NOT EXISTS sobre el CODIGO, y no se
   pisa nada de lo que alguien haya editado desde Parametros -> Cashflow.
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
   0. Guardas duras.
   ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NULL
BEGIN
    RAISERROR('Falta dbo.RO_T_CASHFLOW_CONF_FILA. Corre primero sql/cashflow_estructura.sql.', 16, 1);
END
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION
               WHERE CODIGO = 'COSTOS_INDIRECTOS' AND ACTIVO = 1)
BEGIN
    RAISERROR('No existe la seccion COSTOS_INDIRECTOS activa. Corre sql/cashflow_estructura.sql.', 16, 1);
END
GO

/* ============================================================================
   1. LAS DOS FILAS, Y LA VIEJA INHABILITADA, EN UNA TRANSACCION

   ORDEN 21 y 22: pegadas a la vieja (20) y antes de Llaves y Renovaciones (30).
   El editor renumera en el primer guardado; el agrupamiento es por filas
   ACTIVAS consecutivas, asi que la vieja inhabilitada en el medio no estorba.
   ============================================================================ */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'ALQUILERES_REAL')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('ALQUILERES_REAL', 'Alquileres', 'COSTOS_INDIRECTOS', 'EGRESO', 1, 'ALQUILERES', 'REAL', 21, 1);

    PRINT 'Creada la fila ALQUILERES_REAL (ALQUILERES / REAL), orden 21.';
END
ELSE
    PRINT 'La fila ALQUILERES_REAL ya existe: no se toca.';

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'ALQUILERES_PROY')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('ALQUILERES_PROY', 'Alquileres proyectados', 'COSTOS_INDIRECTOS', 'EGRESO', 1, 'ALQUILERES', 'PROYECTADO', 22, 1);

    PRINT 'Creada la fila ALQUILERES_PROY (ALQUILERES / PROYECTADO), orden 22.';
END
ELSE
    PRINT 'La fila ALQUILERES_PROY ya existe: no se toca.';

/* La vieja: PAGOS es el total de las dos nuevas. Activa junto a ellas contaria
   dos veces el mismo alquiler. */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET ACTIVO = 0, FECHA_UPDATE = GETDATE()
WHERE ORIGEN_PROVIDER = 'ALQUILERES' AND ORIGEN_SERIE = 'PAGOS' AND ACTIVO = 1;

IF @@ROWCOUNT > 0
    PRINT 'Inhabilitada la fila de antes (ALQUILERES / PAGOS). No se borra.';

COMMIT TRANSACTION;
GO

/* ============================================================================
   2. EL GRUPO. Solo si ya se corrio sql/cashflow_estructura_grupos.sql.
   ============================================================================ */
IF COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL
BEGIN
    PRINT 'AVISO: faltan las columnas de grupos (sql/cashflow_estructura_grupos.sql). Las dos filas '
        + 'quedan sueltas: suman bien, pero no se dibujan en un renglon que se abre.';
END
ELSE
BEGIN
    EXEC('
    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = ''ALQUILERES'', NATURALEZA = ''REAL'', GRUPO_NOMBRE = ''Alquileres'', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = ''ALQUILERES_REAL''
      AND (ISNULL(GRUPO, '''') <> ''ALQUILERES'' OR ISNULL(NATURALEZA, '''') <> ''REAL''
           OR ISNULL(GRUPO_NOMBRE, '''') <> ''Alquileres'');

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = ''ALQUILERES'', NATURALEZA = ''PROYECTADO'', GRUPO_NOMBRE = NULL, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = ''ALQUILERES_PROY''
      AND (ISNULL(GRUPO, '''') <> ''ALQUILERES'' OR ISNULL(NATURALEZA, '''') <> ''PROYECTADO''
           OR GRUPO_NOMBRE IS NOT NULL);
    ');

    PRINT 'Alquileres: las dos filas quedan agrupadas en un renglon.';
END
GO

/* ============================================================================
   3. CONTROLES: COMO QUEDO
   ============================================================================ */

/* 3a. La seccion entera. */
PRINT 'Costos Indirectos, como queda:';
GO

SELECT ORDEN, CODIGO, NOMBRE, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ACTIVO
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE SECCION = 'COSTOS_INDIRECTOS'
ORDER BY ORDEN;
GO

/* 3b. Ninguna otra fila activa de ALQUILERES ademas de las dos. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'ALQUILERES' AND ACTIVO = 1
             AND CODIGO NOT IN ('ALQUILERES_REAL', 'ALQUILERES_PROY'))
BEGIN
    PRINT 'ATENCION: hay otra fila activa leyendo ALQUILERES. Si lee PAGOS junto a estas dos, el '
        + 'mismo alquiler se cuenta DOS VECES. Se listan abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'ALQUILERES' AND ACTIVO = 1
      AND CODIGO NOT IN ('ALQUILERES_REAL', 'ALQUILERES_PROY');
END
ELSE
    PRINT 'ALQUILERES alimenta solo las dos filas del grupo: sin doble conteo interno.';
GO

/* 3c. LO QUE EL VALIDADOR NO PUEDE VER: que Cuentas a Pagar Locales no lleve las
       facturas de alquiler. Son dos proveedores distintos y el solapamiento es
       de datos. Las facturas salen de PAGOS y PAGOS_TODO por codigo cuando se
       cumple la condicion; lo que este control mira es que nadie las lea por la
       serie informativa donde quedan, y que la condicion se cumpla. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1 AND ORIGEN_SERIE = 'PAGOS_ALQUILERES')
BEGIN
    PRINT 'ATENCION: DOBLE CONTEO. Hay una fila activa de Proveedores Locales leyendo PAGOS_ALQUILERES: '
        + 'esas facturas ya se cuentan en Alquileres. Inhabilitala. Se lista abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1 AND ORIGEN_SERIE = 'PAGOS_ALQUILERES';
END
ELSE
    PRINT 'Ninguna fila de Proveedores Locales lee PAGOS_ALQUILERES: sin doble conteo entre las dos pestanas.';
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_ALQ_IMPUTACION', 'U') IS NULL
    PRINT 'ATENCION: falta sql/cashflow_alquileres_facturas.sql. Mientras no se corra, las facturas de '
        + 'alquiler SIGUEN en Cuentas a Pagar Locales (es la condicion de seguridad) y la pestana lo avisa '
        + 'en critico.';
ELSE
    PRINT 'Condicion de seguridad cumplida: las facturas de las razones dadas de alta salen de Cuentas a '
        + 'Pagar Locales y se gestionan en Alquileres. Sin imputar, no suman en ninguna fila.';
GO

PRINT 'Fila de Alquileres lista.';
GO
