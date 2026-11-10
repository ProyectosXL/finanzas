/* ============================================================================
   CASHFLOW - PERMISO DE LECTURA DE GASTOS LOCALES EN GESTIONUSUARIOS
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS)
   Orden  : independiente. Se puede correr en cualquier momento.
   ----------------------------------------------------------------------------
   QUE HACE

   La pestaña Gastos Locales (Class/Menu.php) pide cashflow.tab.gastos_locales
   para verse, y esa clave nunca se dio de alta: en la matriz de Gestionusuarios
   no habia como asignarla, y solo el administrador veia la pestaña.

   Solo la clave de LECTURA. La pestaña no tiene escrituras -su lista de cuentas
   se edita en Parametros > Generales, cashflow.editar.parametros.generales-,
   asi que no lleva cashflow.editar.gastos_locales.

   El modulo se resuelve por datos, como en sql/cashflow_permisos_edicion.sql:
   el modulo_id de los cashflow.tab.% que ya existen.

   No se la asigna a ningun rol: se asigna desde Gestionusuarios.

   Reejecutable: va con NOT EXISTS. No borra ni modifica nada existente.
   ========================================================================== */

SET NOCOUNT ON;
GO

DECLARE @MODULO INT, @MODULOS INT;

SELECT @MODULOS = COUNT(DISTINCT modulo_id), @MODULO = MIN(modulo_id)
FROM dbo.FP_PERMISOS
WHERE clave LIKE 'cashflow.tab.%';

IF @MODULOS <> 1
BEGIN
    PRINT CONCAT('AVISO: los permisos cashflow.tab.% cuelgan de ', ISNULL(@MODULOS, 0), ' modulos. No se hizo nada.');
    RETURN;
END

IF @MODULO <> 7
    PRINT 'ATENCION: Class/AuthCashflow.php lee los permisos con modulo_id = 7 y este modulo es otro. La clave nueva no se va a ver hasta corregir uno de los dos.';

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, 'cashflow.tab.gastos_locales', 'Ver: Gastos Locales',
       '[Proveedores] Pagos con la caja de los locales propios, proyectados por promedio'
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS WHERE clave = 'cashflow.tab.gastos_locales');

SELECT id, modulo_id, clave, nombre, descripcion
FROM dbo.FP_PERMISOS
WHERE clave = 'cashflow.tab.gastos_locales';
GO
