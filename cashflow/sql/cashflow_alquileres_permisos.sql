/* ============================================================================
   CASHFLOW - PERMISOS DE ALQUILERES EN GESTIONUSUARIOS
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS)
   Orden  : independiente. Se puede correr en cualquier momento, antes o
            despues de sql/cashflow_alquileres.sql (que va a central).
   ----------------------------------------------------------------------------
   QUE HACE

   Da de alta, si no existen, las tres claves de la pestaña Alquileres:

     cashflow.tab.alquileres                 ver la pestaña (ya existe en
                                             produccion desde que la pestaña era
                                             un placeholder; se deja por si se
                                             instala desde cero)
     cashflow.editar.alquileres              escrituras de la pestaña. En esta
                                             entrega NO HAY NINGUNA: se crea
                                             ahora para no volver a la base
                                             cuando lleguen las de las facturas
     cashflow.editar.parametros.alquileres   Parametros > Alquileres

   NO VA EN sql/cashflow_permisos_edicion.sql A PROPOSITO. Ese script le da la
   edicion a todo rol que ve la pestaña (su paso 4, la migracion "que no le
   quita nada a nadie"). Aca no hay nada que no quitar: la pestaña es nueva, y
   quien la edita se decide desde Gestionusuarios. ESTE SCRIPT NO ASIGNA
   NINGUN ROL.

   El modulo se resuelve por datos, como en sql/cashflow_permisos_edicion.sql:
   el modulo_id de los cashflow.tab.% que ya existen. Si hay mas de uno
   distinto, avisa y no hace nada.

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
('cashflow.tab.alquileres',               'Ver: Alquileres',                     '[RRHH y Operativos] Alquileres de los locales: proyección por local y venta por local'),
('cashflow.editar.alquileres',            'Editar Alquileres',                   '[Edición] Alquileres: imputación de facturas (todavía sin escrituras)'),
('cashflow.editar.parametros.alquileres', 'Editar Parámetros > Alquileres',      '[Edición] Parámetros > Alquileres: vencimientos, alta y baja de razones sociales, datos de los locales y pagos extraordinarios');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permisos de Alquileres: ', @@ROWCOUNT, ' nuevos de 3. No se asigno ningun rol.');

/* ---- Control: no inserta ni modifica nada ---- */
SELECT X.id, X.modulo_id, X.clave, X.nombre, X.descripcion
FROM dbo.FP_PERMISOS X
JOIN @PERMISOS P ON P.clave = X.clave
ORDER BY X.clave;
GO
