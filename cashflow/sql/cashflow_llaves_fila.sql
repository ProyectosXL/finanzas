/* ============================================================================
   MODULO CASHFLOW - LA FILA "LLAVES Y RENOVACIONES" DEL TABLERO, REAL Y
   PROYECTADO (script 69)
   ----------------------------------------------------------------------------
   Base   : central
   Orden  : DESPUES de sql/cashflow_estructura_grupos.sql (las columnas GRUPO,
            NATURALEZA y GRUPO_NOMBRE). Sin el de grupos, las filas se crean
            igual pero sueltas, y lo dice. Independiente de los scripts 66 a 68:
            sin ellos la fila suma lo que puede (y la pestaña avisa que falta).
   ----------------------------------------------------------------------------
   QUE CAMBIA

   La fila LLAVES_RENOV de Costos Indirectos (la de sql/cashflow_estructura.sql,
   LLAVES_RENOV / PAGOS, ID 17 y ORDEN 30 en produccion, activa y en cero hasta
   que el modulo existiera) se reemplaza por DOS FILAS AGRUPADAS, una por serie:

     LLAVES_RENOV_REAL  'Llaves y renovaciones'              LLAVES_RENOV / REAL        NATURALEZA REAL
     LLAVES_RENOV_PROY  'Llaves y renovaciones proyectadas'  LLAVES_RENOV / PROYECTADO  NATURALEZA PROYECTADO

   con GRUPO = 'LLAVES_RENOV': el tablero las dibuja en UN renglon que se abre
   en real y proyectado. La fila vieja queda INHABILITADA, no se borra.

   REAL son las facturas de alquiler marcadas como llave en Alquileres ->
   Facturas, en sus pagos. PROYECTADO, lo de los contratos cargados en la app,
   lo estimado y los compromisos a mano que ninguna factura reemplazo. PAGOS es
   el total de las dos: la vieja y las nuevas no pueden quedar activas a la
   vez -el validador lo rechaza- y el cambio va en UNA transaccion.

   EL DIA QUE SE CORRE el tablero NO SE MUEVE: con el codigo nuevo la fila
   vieja (activa) ya muestra REAL + PROYECTADO, que es lo mismo que las dos
   nuevas. Solo cambia el dibujo: un renglon que se abre. Lo que mueve el Flujo
   Neto es el codigo (el modulo pasa a disponible), no este script.

   ----------------------------------------------------------------------------
   DOBLE CONTEO: LO QUE EL VALIDADOR NO PUEDE VER

   Las facturas marcadas como llave salen de Alquileres y no vuelven a
   Proveedores Locales por codigo (AlquileresFacturas::destino()). El control
   del pie verifica que nadie lea la serie informativa de Proveedores Locales
   donde quedan (PAGOS_ALQUILERES), como el de Alquileres.

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

   ORDEN 31 y 32: pegadas a la vieja (30) y antes de Impuestos (40). El editor
   renumera en el primer guardado; el agrupamiento es por filas ACTIVAS
   consecutivas, asi que la vieja inhabilitada en el medio no estorba.
   ============================================================================ */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'LLAVES_RENOV_REAL')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('LLAVES_RENOV_REAL', 'Llaves y renovaciones', 'COSTOS_INDIRECTOS', 'EGRESO', 1, 'LLAVES_RENOV', 'REAL', 31, 1);

    PRINT 'Creada la fila LLAVES_RENOV_REAL (LLAVES_RENOV / REAL), orden 31.';
END
ELSE
    PRINT 'La fila LLAVES_RENOV_REAL ya existe: no se toca.';

IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'LLAVES_RENOV_PROY')
BEGIN
    INSERT INTO dbo.RO_T_CASHFLOW_CONF_FILA
        (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO)
    VALUES
        ('LLAVES_RENOV_PROY', 'Llaves y renovaciones proyectadas', 'COSTOS_INDIRECTOS', 'EGRESO', 1, 'LLAVES_RENOV', 'PROYECTADO', 32, 1);

    PRINT 'Creada la fila LLAVES_RENOV_PROY (LLAVES_RENOV / PROYECTADO), orden 32.';
END
ELSE
    PRINT 'La fila LLAVES_RENOV_PROY ya existe: no se toca.';

/* La vieja: PAGOS es el total de las dos nuevas. Activa junto a ellas contaria
   dos veces la misma llave. */
UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
SET ACTIVO = 0, FECHA_UPDATE = GETDATE()
WHERE ORIGEN_PROVIDER = 'LLAVES_RENOV' AND ORIGEN_SERIE = 'PAGOS' AND ACTIVO = 1;

IF @@ROWCOUNT > 0
    PRINT 'Inhabilitada la fila de antes (LLAVES_RENOV / PAGOS). No se borra.';

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
    SET GRUPO = ''LLAVES_RENOV'', NATURALEZA = ''REAL'', GRUPO_NOMBRE = ''Llaves y renovaciones'', FECHA_UPDATE = GETDATE()
    WHERE CODIGO = ''LLAVES_RENOV_REAL''
      AND (ISNULL(GRUPO, '''') <> ''LLAVES_RENOV'' OR ISNULL(NATURALEZA, '''') <> ''REAL''
           OR ISNULL(GRUPO_NOMBRE, '''') <> ''Llaves y renovaciones'');

    UPDATE dbo.RO_T_CASHFLOW_CONF_FILA
    SET GRUPO = ''LLAVES_RENOV'', NATURALEZA = ''PROYECTADO'', GRUPO_NOMBRE = NULL, FECHA_UPDATE = GETDATE()
    WHERE CODIGO = ''LLAVES_RENOV_PROY''
      AND (ISNULL(GRUPO, '''') <> ''LLAVES_RENOV'' OR ISNULL(NATURALEZA, '''') <> ''PROYECTADO''
           OR GRUPO_NOMBRE IS NOT NULL);
    ');

    PRINT 'Llaves y renovaciones: las dos filas quedan agrupadas en un renglon.';
END
GO

/* ============================================================================
   3. CONTROLES: COMO QUEDO
   ============================================================================ */
PRINT 'Costos Indirectos, como queda:';
GO

SELECT ORDEN, CODIGO, NOMBRE, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE, ACTIVO
FROM dbo.RO_T_CASHFLOW_CONF_FILA
WHERE SECCION = 'COSTOS_INDIRECTOS'
ORDER BY ORDEN;
GO

/* 3a. Ninguna otra fila activa de LLAVES_RENOV ademas de las dos. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'LLAVES_RENOV' AND ACTIVO = 1
             AND CODIGO NOT IN ('LLAVES_RENOV_REAL', 'LLAVES_RENOV_PROY'))
BEGIN
    PRINT 'ATENCION: hay otra fila activa leyendo LLAVES_RENOV. Si lee PAGOS junto a estas dos, la '
        + 'misma llave se cuenta DOS VECES. Se listan abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE, ORDEN FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'LLAVES_RENOV' AND ACTIVO = 1
      AND CODIGO NOT IN ('LLAVES_RENOV_REAL', 'LLAVES_RENOV_PROY');
END
ELSE
    PRINT 'LLAVES_RENOV alimenta solo las dos filas del grupo: sin doble conteo interno.';
GO

/* 3b. Las facturas marcadas como llave quedan en la serie informativa
       PAGOS_ALQUILERES de Proveedores Locales: nadie tiene que leerla. */
IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA
           WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1 AND ORIGEN_SERIE = 'PAGOS_ALQUILERES')
BEGIN
    PRINT 'ATENCION: DOBLE CONTEO. Hay una fila activa de Proveedores Locales leyendo PAGOS_ALQUILERES: '
        + 'esas facturas ya se cuentan en Alquileres y en Llaves. Inhabilitala. Se lista abajo.';

    SELECT CODIGO, NOMBRE, SECCION, ORIGEN_SERIE FROM dbo.RO_T_CASHFLOW_CONF_FILA
    WHERE ORIGEN_PROVIDER = 'PROV_LOCALES' AND ACTIVO = 1 AND ORIGEN_SERIE = 'PAGOS_ALQUILERES';
END
ELSE
    PRINT 'Ninguna fila de Proveedores Locales lee PAGOS_ALQUILERES: sin doble conteo entre las pestanas.';
GO

PRINT 'Fila de Llaves y Renovaciones lista.';
GO
