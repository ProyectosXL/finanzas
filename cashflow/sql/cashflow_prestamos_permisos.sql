/* ============================================================================
   CASHFLOW - PERMISOS DE PRESTAMOS EN GESTIONUSUARIOS (script 71)
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS)
   Orden  : independiente. Se puede correr en cualquier momento, antes o
            despues de sql/cashflow_prestamos.sql (que va a central).
   ----------------------------------------------------------------------------
   QUE HACE

   Da de alta, si no existen, las tres claves de Financiero > Prestamos:

     cashflow.tab.prestamos                  ver la pestaña (puede existir desde
                                             que era un placeholder; se deja por
                                             si se instala de cero)
     cashflow.editar.prestamos               alta, edicion, cronograma, cambio de
                                             estado, cancelaciones y simulaciones
     cashflow.editar.parametros.prestamos    Parametros > Prestamos

   ESTE SCRIPT NO ASIGNA NINGUN ROL: los permisos se asignan a mano desde el
   hub, como los de Alquileres y Llaves y Renovaciones. Por eso no va en
   sql/cashflow_permisos_edicion.sql, que le da la edicion a todo rol que ve la
   pestaña.

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
('cashflow.tab.prestamos',               'Ver: Préstamos',                 '[Financiero] Préstamos bancarios: cronograma, proyección y simulador'),
('cashflow.editar.prestamos',            'Editar Préstamos',               '[Edición] Préstamos: alta, edición, cronograma, cambio de estado, cancelaciones y simulaciones'),
('cashflow.editar.parametros.prestamos', 'Editar Parámetros > Préstamos',  '[Edición] Parámetros > Préstamos: costos, comisión de cancelación, tasas y simulador');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permisos de Prestamos: ', @@ROWCOUNT, ' nuevos de 3. No se asigno ningun rol.');

/* ---- Control: no inserta ni modifica nada mas ---- */
SELECT X.id, X.modulo_id, X.clave, X.nombre, X.descripcion
FROM dbo.FP_PERMISOS X
JOIN @PERMISOS P ON P.clave = X.clave
ORDER BY X.clave;
GO
