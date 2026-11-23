/* ============================================================================
   CASHFLOW - PERMISOS DE LLAVES Y RENOVACIONES EN GESTIONUSUARIOS (script 67)
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS)
   Orden  : independiente. Se puede correr en cualquier momento, antes o
            despues de sql/cashflow_llaves.sql (que va a central).
   ----------------------------------------------------------------------------
   QUE HACE

   Da de alta, si no existen, las tres claves de la pestaña Llaves y
   Renovaciones:

     cashflow.tab.llaves_renov                 ver la pestaña (puede existir
                                               desde que era un placeholder; se
                                               deja por si se instala de cero)
     cashflow.editar.llaves_renov              las acciones de la pestaña
     cashflow.editar.parametros.llaves_renov   Parametros > Llaves y
                                               Renovaciones

   Marcar una factura de alquiler como llave se hace desde Alquileres >
   Facturas y pide cashflow.editar.alquileres, como excluirla: es una decision
   sobre una factura de alquiler, y la toma quien maneja esas facturas.

   NO VA EN sql/cashflow_permisos_edicion.sql, por el mismo motivo que
   sql/cashflow_alquileres_permisos.sql: ese script le da la edicion a todo rol
   que ve la pestaña. ESTE SCRIPT NO ASIGNA NINGUN ROL.

   El modulo se resuelve por datos: el modulo_id de los cashflow.tab.% que ya
   existen. Si hay mas de uno distinto, avisa y no hace nada.

   tests/test_auth_cashflow.php verifica que las claves de este script sean
   las que pide el codigo.

   Reejecutable: va con NOT EXISTS. No borra ni modifica nada existente.
   ========================================================================== */

SET NOCOUNT ON;
GO

DECLARE @MODULO INT, @MODULOS INT;

SELECT @MODULOS = COUNT(DISTINCT modulo_id), @MODULO = MIN(modulo_id)
FROM dbo.FP_PERMISOS
WHERE clave LIKE 'cashflow.tab.%';

IF ISNULL(@MODULOS, 0) <> 1
BEGIN
    PRINT CONCAT('AVISO: los permisos cashflow.tab.% cuelgan de ', ISNULL(@MODULOS, 0), ' modulos. No se hizo nada.');
    RETURN;
END

IF @MODULO <> 7
    PRINT 'ATENCION: Class/AuthCashflow.php lee los permisos con modulo_id = 7 y este modulo es otro. Las claves nuevas no se van a ver hasta corregir uno de los dos.';

DECLARE @PERMISOS TABLE (clave VARCHAR(100) PRIMARY KEY, nombre VARCHAR(100), descripcion VARCHAR(255));

INSERT INTO @PERMISOS (clave, nombre, descripcion) VALUES
('cashflow.tab.llaves_renov',               'Ver: Llaves y Renov. Contratos',            '[RRHH y Operativos] Llaves y renovaciones de contratos de locales, obras de imagen y aperturas'),
('cashflow.editar.llaves_renov',            'Editar Llaves y Renov. Contratos',          '[Edición] Llaves y Renovaciones: acciones de la pestaña'),
('cashflow.editar.parametros.llaves_renov', 'Editar Parámetros > Llaves y Renovaciones', '[Edición] Parámetros > Llaves y Renovaciones: generales, operadores, locales, ya pagadas, obras, aperturas y compromisos');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permisos de Llaves y Renovaciones: ', @@ROWCOUNT, ' nuevos de 3. No se asigno ningun rol.');

/* ---- Control: no inserta ni modifica nada ---- */
SELECT X.id, X.modulo_id, X.clave, X.nombre, X.descripcion
FROM dbo.FP_PERMISOS X
JOIN @PERMISOS P ON P.clave = X.clave
ORDER BY X.clave;
GO
