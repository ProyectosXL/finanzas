/* ============================================================================
   CASHFLOW - PERMISO DE PARAMETROS > CALENDARIO EN GESTIONUSUARIOS
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS, FP_ROL_PERMISO)
   Orden  : independiente. Se puede correr antes o despues de
            sql/cashflow_parametros_calendario.sql (que va a central), pero
            CONVIENE ANTES DE PUBLICAR EL CODIGO: ver "SI NO SE CORRE".
   ----------------------------------------------------------------------------
   QUE HACE

   1. Da de alta, si no existe, la clave de la sub-pestana nueva:

        cashflow.editar.parametros.calendario   Parametros > Calendario

   2. SE LA ASIGNA A LOS ROLES QUE HOY TIENEN cashflow.editar.parametros.generales.
      Lo que se mueve a Calendario -el horizonte, los feriados de comercio y el
      cronograma de pagos- hasta hoy se editaba con el permiso de Generales, asi
      que quien lo editaba lo sigue editando. Es la misma migracion "que no le
      quita nada a nadie" de sql/cashflow_permisos_edicion.sql. Lo que haya que
      restringir se recorta despues desde Gestionusuarios.

   El modulo se resuelve por datos, como en sql/cashflow_permisos_edicion.sql:
   el modulo_id de los cashflow.tab.% que ya existen. Si hay mas de uno
   distinto, avisa y no hace nada.

   tests/test_auth_cashflow.php verifica que la clave de este script sea la que
   pide el codigo.

   ----------------------------------------------------------------------------
   SI NO SE CORRE

   Parametros > Calendario se ve igual, pero SOLO EL ADMINISTRADOR puede
   editarla: el cronograma de pagos, los feriados bancarios y -despues de
   correr sql/cashflow_parametros_calendario.sql- el horizonte y los feriados
   de comercio. Para todos los demas la sub-pestana queda de solo lectura, con
   el 403 de siempre si alguien fuerza el pedido.

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
    PRINT 'ATENCION: Class/AuthCashflow.php lee los permisos con modulo_id = 7 y este modulo es otro. La clave nueva no se va a ver hasta corregir uno de los dos.';

/* La columna "origen" dice de que clave se copian los roles. */
DECLARE @PERMISOS TABLE (clave VARCHAR(100) PRIMARY KEY, nombre VARCHAR(100),
                         descripcion VARCHAR(255), origen VARCHAR(100));

INSERT INTO @PERMISOS (clave, nombre, descripcion, origen) VALUES
('cashflow.editar.parametros.calendario', 'Editar Parámetros > Calendario',
 '[Edición] Parámetros > Calendario: horizonte de proyección, feriados bancarios y de comercio, y cronograma de pagos',
 'cashflow.editar.parametros.generales');

/* ---- 1. La clave ---- */
INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permiso de Calendario: ', @@ROWCOUNT, ' nuevo de 1.');

/* ---- 2. Los roles: quien edita Generales, edita Calendario ---- */
INSERT INTO dbo.FP_ROL_PERMISO (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, pn.id
FROM @PERMISOS P
JOIN dbo.FP_PERMISOS po ON po.clave = P.origen AND po.modulo_id = @MODULO
JOIN dbo.FP_ROL_PERMISO rp ON rp.permiso_id = po.id
JOIN dbo.FP_PERMISOS pn ON pn.clave = P.clave AND pn.modulo_id = @MODULO
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_ROL_PERMISO x WHERE x.rol_id = rp.rol_id AND x.permiso_id = pn.id);

PRINT CONCAT('Migracion: ', @@ROWCOUNT, ' asignaciones nuevas (rol que edita Generales -> rol que edita Calendario).');

/* ---- Control: tienen que dar igual ---- */
SELECT P.clave,
       (SELECT COUNT(*) FROM dbo.FP_ROL_PERMISO rp JOIN dbo.FP_PERMISOS x ON x.id = rp.permiso_id
         WHERE x.clave = P.origen AND x.modulo_id = @MODULO) AS roles_que_editan_generales,
       (SELECT COUNT(*) FROM dbo.FP_ROL_PERMISO rp JOIN dbo.FP_PERMISOS x ON x.id = rp.permiso_id
         WHERE x.clave = P.clave AND x.modulo_id = @MODULO) AS roles_que_editan_calendario
FROM @PERMISOS P;
GO
