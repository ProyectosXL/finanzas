/* ============================================================================
   CASHFLOW - PERMISOS DE EDICION EN GESTIONUSUARIOS
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_PERMISOS, FP_ROL_PERMISO)
   Orden  : ANTES de publicar el codigo de feature/cashflow-auditoria-usuario.
            Independiente de sql/cashflow_auditoria_usuario.sql, que va a
            central.
   ----------------------------------------------------------------------------
   QUE HACE

   Hasta ahora el modulo tenia un solo permiso por pestaña, cashflow.tab.<tab>,
   y quien la veia la podia editar. Desde esta entrega leer y escribir son dos
   permisos distintos, y el servidor rechaza (403) toda escritura sin el
   segundo. Ver "Auditoria y permisos de escritura" en README-cashflow.md.

   1. EL MODULO SE RESUELVE POR DATOS, no por un id escrito: es el modulo_id de
      los permisos cashflow.tab.% que ya existen. Si hay mas de uno distinto,
      avisa y NO HACE NADA: colgar las claves nuevas de un modulo elegido al
      azar las dejaria invisibles para el PHP.

   2. Una clave cashflow.editar.<tab> por cada pestaña QUE TIENE ESCRITURAS.
      La lista es la de AuthCashflow::ESCRITURAS; las de solo lectura
      (dashboard, exportaciones_tasky y las pestañas en construccion) no llevan
      clave porque no hay nada que habilitar.

   3. Una clave cashflow.editar.parametros.<sub> por cada sub-pestaña de
      Parametros (Parametros::$modulos, en minuscula). La LECTURA de Parametros
      sigue siendo una sola: cashflow.tab.parametros.

   4. MIGRACION QUE NO LE QUITA NADA A NADIE:
        - todo rol con cashflow.tab.<tab> recibe cashflow.editar.<tab>;
        - todo rol con cashflow.tab.parametros recibe todas las
          cashflow.editar.parametros.*.
      Es decir: el dia del pase todos siguen pudiendo hacer lo que hacian. Lo
      que se quiera restringir se RECORTA DESPUES desde Gestionusuarios.

   tests/test_auth_cashflow.php verifica que las claves de este script sean
   exactamente las que pide el codigo.

   Reejecutable: todo va con IF NOT EXISTS. No borra ni modifica nada existente.
   En una segunda corrida la migracion vuelve a dar editar a quien tiene ver y
   no lo tiene: si ya se recorto desde Gestionusuarios, NO hay que volver a
   correrlo.

   SI NO SE CORRE: solo el administrador puede guardar cambios. Todos los
   demas ven las pantallas sin controles de edicion, y el servidor les
   responde 403 si lo intentan.
   ========================================================================== */

SET NOCOUNT ON;
GO

DECLARE @MODULO INT, @MODULOS INT;

SELECT @MODULOS = COUNT(DISTINCT modulo_id), @MODULO = MIN(modulo_id)
FROM dbo.FP_PERMISOS
WHERE clave LIKE 'cashflow.tab.%';

IF @MODULOS = 0
BEGIN
    PRINT 'AVISO: no hay ningun permiso cashflow.tab.%, asi que no se sabe de que modulo colgar los nuevos. No se hizo nada.';
    RETURN;
END

IF @MODULOS > 1
BEGIN
    PRINT CONCAT('AVISO: los permisos cashflow.tab.% cuelgan de ', @MODULOS, ' modulos distintos. No se hizo nada: hay que unificarlos en Gestionusuarios y volver a correr.');
    RETURN;
END

PRINT CONCAT('Modulo de Cashflow: modulo_id = ', @MODULO, '.');

/* AuthCashflow carga los permisos con modulo_id = 7. Si el modulo resuelto es
   otro, las claves se crean igual pero el PHP no las ve. */
IF @MODULO <> 7
    PRINT 'ATENCION: Class/AuthCashflow.php lee los permisos con modulo_id = 7 y este modulo es otro. Las claves nuevas no se van a ver hasta corregir uno de los dos.';

/* ---- Las claves ----
   lectura: la clave de lectura de la que hereda quien recibe esta en la
   migracion. Para las de Parametros es siempre cashflow.tab.parametros. */
DECLARE @PERMISOS TABLE (clave VARCHAR(100), nombre VARCHAR(100), descripcion VARCHAR(255), lectura VARCHAR(100));
INSERT INTO @PERMISOS VALUES
('cashflow.editar.cashflow',              'Editar: Tablero Cashflow',               '[Tablero y Vistas] Aplicar y quitar cobertura manual en una fecha', 'cashflow.tab.cashflow'),
('cashflow.editar.ventas',                'Editar: Ventas',                 '[Ingresos] Modificar índices de proyección y participación por canal', 'cashflow.tab.ventas'),
('cashflow.editar.saldos',                'Editar: Saldos',                 '[Ingresos] Cargar saldos bancarios, locales y movimientos de fondos', 'cashflow.tab.saldos'),
('cashflow.editar.echeqs',                'Editar: Echeqs',                 '[Ingresos] Marcar pre-chequeados y exclusiones de cobro', 'cashflow.tab.echeqs'),
('cashflow.editar.cobranzas_fr',          'Editar: Cobranzas FR',           '[Ingresos] Fijar manualmente fecha de cobro de facturas de franquicias', 'cashflow.tab.cobranzas_fr'),
('cashflow.editar.cobranzas_may',         'Editar: Cobranzas May',          '[Ingresos] Fijar manualmente fecha de cobro de facturas de mayoristas', 'cashflow.tab.cobranzas_may'),
('cashflow.editar.cob_electronicos',      'Editar: Cob. Electrónicos',      '[Ingresos] Alta, edición, baja e importación de movimientos electrónicos', 'cashflow.tab.cob_electronicos'),
('cashflow.editar.proveedores_exterior',  'Editar: Proveedores Exterior',   '[Comercio Exterior] Fechas de pago, pagos realizados y cotización por contenedor', 'cashflow.tab.proveedores_exterior'),
('cashflow.editar.crono_nacionalizacion', 'Editar: Crono Nacionalización',  '[Comercio Exterior] Fechas de nacionalización y pagos realizados', 'cashflow.tab.crono_nacionalizacion'),
('cashflow.editar.compras_proyectadas',   'Editar: Proyección Comex',       '[Comercio Exterior] Ajustes manuales y actualización de insumos proyectados', 'cashflow.tab.compras_proyectadas'),
('cashflow.editar.proveedores_locales',   'Editar: Proveedores Locales',    '[Proveedores] Fechas/formas de pago, exclusiones, maestro e importación', 'cashflow.tab.proveedores_locales'),
('cashflow.editar.logistica_local',       'Editar: Logística Local',        '[Proveedores] Horas y valor hora de fleteros desde planilla', 'cashflow.tab.logistica_local'),
('cashflow.editar.pagos_tarjetas',        'Editar: Pagos con Tarjetas',     '[Financiero] Carga de resúmenes, pagos y vinculación de facturas', 'cashflow.tab.pagos_tarjetas'),

('cashflow.editar.parametros.generales',        'Editar: Parám. Generales',         '[Configuración y Parámetros] Horizonte, IVA, feriados, inflación y cronograma general', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.ventas',           'Editar: Parám. Ventas',            '[Configuración y Parámetros] Mix de cobro y participación de respaldo', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.saldos',           'Editar: Parám. Saldos',            '[Configuración y Parámetros] Cuentas, fondos, sucursales y sus parámetros', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cob_electronicos', 'Editar: Parám. Cob. Electrónicos', '[Configuración y Parámetros] Procesadoras y alícuotas electrónicas', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.prechequeado',     'Editar: Parám. Prechequeado',      '[Configuración y Parámetros] Clientes y días de pre-chequeado', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cobranzas',        'Editar: Parám. Cobranzas',         '[Configuración y Parámetros] Escala de descuento, PPP por grupo y medios de pago', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.compras_proy',     'Editar: Parám. Compras Proy.',     '[Configuración y Parámetros] Parámetros de proyección de Comercio Exterior', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.logistica',        'Editar: Parám. Logística',         '[Configuración y Parámetros] Alta, edición y baja de fleteros en parámetros', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.prov_locales',     'Editar: Parám. Prov. Locales',     '[Configuración y Parámetros] Opciones de formas de pago y categorías de proveedores locales', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.tarjetas',         'Editar: Parám. Tarjetas',          '[Configuración y Parámetros] Maestro de tarjetas corporativas', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cashflow',         'Editar: Parám. Estructura Tablero',          '[Configuración y Parámetros] Estructura del tablero, secciones y filas', 'cashflow.tab.parametros');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

DECLARE @nuevos INT = @@ROWCOUNT, @total INT = (SELECT COUNT(*) FROM @PERMISOS);
PRINT CONCAT('Permisos de edicion: ', @nuevos, ' nuevos de ', @total, '.');

IF EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X JOIN @PERMISOS P ON P.clave = X.clave WHERE X.modulo_id <> @MODULO)
    PRINT 'ATENCION: hay claves cashflow.editar.* colgadas de OTRO modulo. No se tocan, y el PHP no las ve: revisalas en Gestionusuarios.';

/* ---- Migracion: editar a quien hoy ve ---- */
INSERT INTO dbo.FP_ROL_PERMISO (rol_id, permiso_id)
SELECT DISTINCT rp.rol_id, pe.id
FROM @PERMISOS P
JOIN dbo.FP_PERMISOS pl ON pl.clave = P.lectura AND pl.modulo_id = @MODULO
JOIN dbo.FP_ROL_PERMISO rp ON rp.permiso_id = pl.id
JOIN dbo.FP_PERMISOS pe ON pe.clave = P.clave AND pe.modulo_id = @MODULO
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_ROL_PERMISO x WHERE x.rol_id = rp.rol_id AND x.permiso_id = pe.id);

PRINT CONCAT('Migracion: ', @@ROWCOUNT, ' asignaciones nuevas (rol con la pestaña -> rol con su edicion).');

/* ---- Control ---- */
SELECT P.clave,
       (SELECT COUNT(*) FROM dbo.FP_ROL_PERMISO rp JOIN dbo.FP_PERMISOS x ON x.id = rp.permiso_id
         WHERE x.clave = P.lectura AND x.modulo_id = @MODULO) AS roles_que_ven,
       (SELECT COUNT(*) FROM dbo.FP_ROL_PERMISO rp JOIN dbo.FP_PERMISOS x ON x.id = rp.permiso_id
         WHERE x.clave = P.clave AND x.modulo_id = @MODULO) AS roles_que_editan
FROM @PERMISOS P
ORDER BY P.clave;

PRINT 'Permisos de edicion de Cashflow listos. Lo que haya que restringir se recorta desde Gestionusuarios.';
GO
