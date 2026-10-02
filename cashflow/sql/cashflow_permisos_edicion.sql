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
('cashflow.editar.cashflow',              'Editar Cashflow',               '[Edición] Tablero: aplicar y quitar cobertura manual en una fecha', 'cashflow.tab.cashflow'),
('cashflow.editar.ventas',                'Editar Ventas',                 '[Edición] Ventas: índices de proyección y participación por canal', 'cashflow.tab.ventas'),
('cashflow.editar.saldos',                'Editar Saldos',                 '[Edición] Saldos: cargar saldos bancarios y de locales, movimientos de los fondos', 'cashflow.tab.saldos'),
('cashflow.editar.echeqs',                'Editar Echeqs',                 '[Edición] Echeqs: marcar cheques pre-chequeados y excluir cheques que no se van a cobrar', 'cashflow.tab.echeqs'),
('cashflow.editar.cobranzas_fr',          'Editar Cobranzas FR',           '[Edición] Cobranzas Franquicias: fijar a mano la fecha de cobro de una factura', 'cashflow.tab.cobranzas_fr'),
('cashflow.editar.cobranzas_may',         'Editar Cobranzas May',          '[Edición] Cobranzas Mayoristas: fijar a mano la fecha de cobro de una factura', 'cashflow.tab.cobranzas_may'),
('cashflow.editar.cob_electronicos',      'Editar Cob. Electrónicos',      '[Edición] Cobranzas Electrónicas: alta, edición, baja e importación de movimientos', 'cashflow.tab.cob_electronicos'),
('cashflow.editar.proveedores_exterior',  'Editar Proveedores Exterior',   '[Edición] Proveedores Exterior: fechas de pago (escriben en Comercio Exterior), pagos hechos y cotización por contenedor', 'cashflow.tab.proveedores_exterior'),
('cashflow.editar.crono_nacionalizacion', 'Editar Crono Nacionalización',  '[Edición] Crono Nacionalización: fechas (escriben en Comercio Exterior) y pagos hechos', 'cashflow.tab.crono_nacionalizacion'),
('cashflow.editar.compras_proyectadas',   'Editar Proyección Comex',       '[Edición] Comercio Exterior > Proyección: ajustes manuales y actualizar los insumos', 'cashflow.tab.compras_proyectadas'),
('cashflow.editar.proveedores_locales',   'Editar Proveedores Locales',    '[Edición] Cuentas a Pagar Locales: fechas y formas de pago, exclusiones, maestro, importar y conciliar', 'cashflow.tab.proveedores_locales'),
('cashflow.editar.logistica_local',       'Editar Logística Local',        '[Edición] Logística Local: horas y valor hora de los fleteros desde la planilla', 'cashflow.tab.logistica_local'),
('cashflow.editar.pagos_tarjetas',        'Editar Pagos con Tarjetas',     '[Edición] Pagos con Tarjetas: resúmenes, pagos, y vincular o excluir facturas', 'cashflow.tab.pagos_tarjetas'),

('cashflow.editar.parametros.generales',        'Editar Parámetros > Generales',         '[Edición] Parámetros > Generales: horizonte, IVA, feriados, inflación y cronograma de pagos (afecta a todo el módulo)', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.ventas',           'Editar Parámetros > Ventas',            '[Edición] Parámetros > Ventas: mix de cobro y participación de respaldo', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.saldos',           'Editar Parámetros > Saldos',            '[Edición] Parámetros > Saldos: cuentas, fondos, sucursales y sus parámetros', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cob_electronicos', 'Editar Parámetros > Cob. Electrónicos', '[Edición] Parámetros > Cob. Electrónicos: procesadoras y alícuotas', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.prechequeado',     'Editar Parámetros > Prechequeado',      '[Edición] Parámetros > Prechequeado: clientes y días de pre-chequeado', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cobranzas',        'Editar Parámetros > Cobranzas',         '[Edición] Parámetros > Cobranzas: escala de descuento, PPP por grupo, medio de pago por cliente', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.compras_proy',     'Editar Parámetros > Compras Proy.',     '[Edición] Parámetros > Compras Proyectadas: parámetros de la proyección de Comex', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.logistica',        'Editar Parámetros > Logística',         '[Edición] Parámetros > Logística: alta, edición y baja de fleteros', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.prov_locales',     'Editar Parámetros > Prov. Locales',     '[Edición] Parámetros > Proveedores Locales: opciones de forma de pago y categorías', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.tarjetas',         'Editar Parámetros > Tarjetas',          '[Edición] Parámetros > Tarjetas: maestro de tarjetas', 'cashflow.tab.parametros'),
('cashflow.editar.parametros.cashflow',         'Editar Parámetros > Cashflow',          '[Edición] Parámetros > Cashflow: estructura del tablero, secciones y filas', 'cashflow.tab.parametros');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permisos de edicion: ', @@ROWCOUNT, ' nuevos de ', (SELECT COUNT(*) FROM @PERMISOS), '.');

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
