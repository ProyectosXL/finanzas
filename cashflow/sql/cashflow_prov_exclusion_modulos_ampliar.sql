/* ============================================================================
   MODULO CASHFLOW - EXCLUIR UN PROVEEDOR DE TARJETAS Y DE ALQUILERES
   ----------------------------------------------------------------------------
   Base    : central
   Orden   : DESPUES de sql/cashflow_prov_exclusion_modulo.sql, que crea la
             tabla. Sin ella, este script no hace nada y lo dice.
             Sin este script, la solapa Maestro muestra las tres columnas pero
             excluir de Tarjetas o de Alquileres falla con un mensaje que nombra
             este archivo; Proveedores Locales funciona como hoy.
   ----------------------------------------------------------------------------
   QUE CAMBIA

   El CHECK de MODULO de RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO pasa de admitir
   solo 'PROV_LOCALES' a admitir los tres modulos que leen las cuentas a pagar
   de Tango:

     PROV_LOCALES    Proveedores Locales (el de siempre)
     TARJETAS_CORP   Tarjetas Pagos Corporativos
     ALQUILERES      Alquileres

   La tabla no cambia: la clave ya era (COD_PROVEE, MODULO), y agregar un modulo
   siempre fue ampliar este CHECK. La lista del lado PHP es
   ProveedoresExclusion::MODULOS, y una prueba fija que las dos coincidan.

   QUE DECIDE CADA EXCLUSION

   Que modulo recibe una factura NO lo decide la exclusion: lo decide el alta
   en Parametros -> Alquileres y, en Tarjetas, la forma de pago. La exclusion
   saca de ESE modulo, a proposito y con motivo. Ver el encabezado de
   cashflow/Class/ProveedoresExclusion.php.

   EL DIA QUE SE CORRE, NADA SE MUEVE: no hay ninguna fila con los modulos
   nuevos, y las de PROV_LOCALES siguen siendo validas.

   ES REEJECUTABLE: el CHECK se reemplaza solo si todavia no admite los tres.
   ============================================================================ */

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO', 'U') IS NULL
BEGIN
    PRINT 'Falta dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO: corre primero '
        + 'sql/cashflow_prov_exclusion_modulo.sql. No se hizo nada.';
END
ELSE IF EXISTS (SELECT 1 FROM sys.check_constraints
                WHERE name = 'CK_RO_T_CF_PROVEXC_MODULO'
                  AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO')
                  AND definition LIKE '%TARJETAS_CORP%'
                  AND definition LIKE '%ALQUILERES%')
BEGIN
    PRINT 'El CHECK de MODULO ya admite los tres modulos: no se toca.';
END
ELSE
BEGIN
    /* En una transaccion: entre el DROP y el ADD la tabla no tiene CHECK, y esa
       ventana no tiene que existir para nadie que escriba. */
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM sys.check_constraints
               WHERE name = 'CK_RO_T_CF_PROVEXC_MODULO'
                 AND parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO'))
        ALTER TABLE dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO
            DROP CONSTRAINT CK_RO_T_CF_PROVEXC_MODULO;

    ALTER TABLE dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO
        ADD CONSTRAINT CK_RO_T_CF_PROVEXC_MODULO
            CHECK (MODULO IN ('PROV_LOCALES', 'TARJETAS_CORP', 'ALQUILERES'));

    COMMIT TRANSACTION;

    PRINT 'CHECK de MODULO ampliado: PROV_LOCALES, TARJETAS_CORP y ALQUILERES.';
END
GO

/* Como queda. */
SELECT name, definition
FROM sys.check_constraints
WHERE parent_object_id = OBJECT_ID('dbo.RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO');
GO
