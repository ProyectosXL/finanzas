/* ============================================================================
   INFORME ECONOMICO POR CANAL - ALTA EN GESTIONUSUARIOS Y EL HUB
   ----------------------------------------------------------------------------
   Base   : apps (la de Gestionusuarios: FP_MODULOS, FP_PERMISOS, FP_ROL_PERMISO)
   Orden  : 4 de 4. Independiente de los otros tres (van a otra base).
   ----------------------------------------------------------------------------
   QUE HACE

   1. El modulo en FP_MODULOS, por codigo INFORME_ECONOMICO. El PHP resuelve
      el modulo_id por este codigo (AuthInformeEconomico::MODULO_CODIGO): no hay
      ningun id escrito en el codigo.
   2. El permiso de HUB hub.app.informe_economico: es lo que hace aparecer la
      tarjeta en el HUB.
   3. Los permisos internos, con descripcion "[Seccion] ...":
        ie.tab.canales, ie.tab.locales, ie.tab.mensual, ie.tab.dashboard,
        ie.tab.parametros, ie.editar
   4. Todo (HUB e internos) a tres roles, resueltos por CODIGO verificando
      sector y subsector, NUNCA por id:
        RESPONSABLE y DIRECCION2  subsector GERENCIA, sector ADMINISTRACION
        DIRECTOR                  sector GERENCIAGENERAL
      Si alguno no existe, lo informa y sigue con los otros.

   El resto de los accesos se gestiona desde Gestionusuarios. El administrador
   (sector Proyectos + es_admin / CONTROLTOTAL) entra a todo sin asignarle nada.

   Reejecutable: todo va con IF NOT EXISTS. No borra ni modifica nada existente.

   SI NO SE CORRE: el modulo no aparece en el HUB y solo entra el
   administrador.
   ========================================================================== */

SET NOCOUNT ON;
GO

/* ---- 1. Modulo ---- */
IF NOT EXISTS (SELECT 1 FROM dbo.FP_MODULOS WHERE codigo = 'INFORME_ECONOMICO')
BEGIN
    INSERT INTO dbo.FP_MODULOS (codigo, nombre, ruta_url, icono, activo, categoria, descripcion, orden, color, target_blank)
    VALUES ('INFORME_ECONOMICO', N'Informe Económico', '/finanzas/informe_economico/', 'fa-chart-column', 1,
            N'Administración & Finanzas',
            N'Informe económico por canal y por local, comparativo interanual, ranking de locales y corrección de importes del resumen.',
            95,
            -- Indigo: del mismo estilo que los demas y distinto del verde
            -- azulado de Cashflow, que es el vecino en el HUB (orden 90).
            N'linear-gradient(135deg, #4338ca 0%, #6366f1 100%)',
            0);
    PRINT 'Creado el modulo INFORME_ECONOMICO.';
END
ELSE
    PRINT 'El modulo INFORME_ECONOMICO ya existe: no se toca.';
GO

/* ---- 2 y 3. Permisos ---- */
DECLARE @MODULO INT = (SELECT id FROM dbo.FP_MODULOS WHERE codigo = 'INFORME_ECONOMICO');

DECLARE @PERMISOS TABLE (clave VARCHAR(100), nombre VARCHAR(100), descripcion VARCHAR(255));
INSERT INTO @PERMISOS VALUES
('hub.app.informe_economico', 'Acceso a Informe Económico en Hub',
 '[Hub] Informe económico por canal y por local, comparativo interanual, ranking de locales y corrección de importes del resumen.'),
('ie.tab.canales',    'Solapa IE por Canales',     '[Informes] Informe económico por canal: locales, franquicias, mayoristas, ecommerce y otros ingresos'),
('ie.tab.locales',    'Solapa IE por Locales',     '[Informes] Informe económico por local propio y por apertura de ecommerce'),
('ie.tab.mensual',    'Solapa Evolución Mensual',  '[Informes] Evolución mes a mes del informe económico, por canal'),
('ie.tab.dashboard',  'Solapa Dashboard',          '[Informes] Indicadores, estructura de costos por canal, ranking de locales y alertas'),
('ie.tab.parametros', 'Solapa Parámetros',         '[Configuración] Estructura de filas, categoría de rubros, umbrales del semáforo y parámetro de alerta'),
('ie.editar',         'Editar importes y parámetros', '[Edición] Corregir importes del resumen (con motivo e historial) y guardar parámetros');

INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
SELECT @MODULO, P.clave, P.nombre, P.descripcion
FROM @PERMISOS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X WHERE X.clave = P.clave);

PRINT CONCAT('Permisos: ', @@ROWCOUNT, ' nuevos.');

IF EXISTS (SELECT 1 FROM dbo.FP_PERMISOS X JOIN @PERMISOS P ON P.clave = X.clave WHERE X.modulo_id <> @MODULO)
    PRINT 'ATENCION: hay claves ie.* o hub.app.informe_economico colgadas de OTRO modulo. No se tocan; revisalas en Gestionusuarios.';
GO

/* ---- 4. Asignacion a roles, por codigo y verificando sector/subsector ---- */
DECLARE @ROLES TABLE (rol_codigo VARCHAR(50), sub_codigo VARCHAR(50), sec_codigo VARCHAR(50));
INSERT INTO @ROLES VALUES
('RESPONSABLE', 'GERENCIA', 'ADMINISTRACION'),
('DIRECCION2',  'GERENCIA', 'ADMINISTRACION'),
('DIRECTOR',    NULL,       'GERENCIAGENERAL');   -- solo se verifica el sector

DECLARE @rc VARCHAR(50), @sc VARCHAR(50), @secc VARCHAR(50), @rol INT, @n INT;

DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT rol_codigo, sub_codigo, sec_codigo FROM @ROLES;
OPEN c;
FETCH NEXT FROM c INTO @rc, @sc, @secc;

WHILE @@FETCH_STATUS = 0
BEGIN
    SELECT @n = COUNT(*), @rol = MIN(r.id)
    FROM dbo.FP_ROLES_PERMISOS_MAP r
    JOIN dbo.FP_SUBSECTORES sub ON sub.id = r.subsector_id
    JOIN dbo.FP_SECTORES s ON s.id = sub.sector_id
    WHERE r.codigo = @rc AND s.codigo = @secc AND (@sc IS NULL OR sub.codigo = @sc);

    IF @n = 0
        PRINT CONCAT('AVISO: no existe el rol ', @rc, ' en ', @secc, ISNULL(' / ' + @sc, ''), '. Se sigue con los otros.');
    ELSE IF @n > 1
        PRINT CONCAT('AVISO: hay ', @n, ' roles ', @rc, ' en ', @secc, ': no se asigna ninguno, para no adivinar. Asignalo desde Gestionusuarios.');
    ELSE
    BEGIN
        INSERT INTO dbo.FP_ROL_PERMISO (rol_id, permiso_id)
        SELECT @rol, p.id
        FROM dbo.FP_PERMISOS p
        JOIN dbo.FP_MODULOS m ON m.id = p.modulo_id
        WHERE m.codigo = 'INFORME_ECONOMICO'
          AND NOT EXISTS (SELECT 1 FROM dbo.FP_ROL_PERMISO x WHERE x.rol_id = @rol AND x.permiso_id = p.id);

        PRINT CONCAT('Rol ', @rc, ' (', @secc, '): ', @@ROWCOUNT, ' permisos asignados.');
    END

    FETCH NEXT FROM c INTO @rc, @sc, @secc;
END

CLOSE c;
DEALLOCATE c;
GO

/* ---- Control: el modulo puede estar dado de alta pero apagado ----
   En produccion quedo con activo = 0 el 28/09/2026: el script se corrio antes
   de tiempo y se apago el modulo hasta que el codigo este publicado. Este
   script NO lo prende (no modifica nada existente): se prende a mano al pasar
   a produccion. Ver README, "Para pasar a produccion". */
IF EXISTS (SELECT 1 FROM dbo.FP_MODULOS WHERE codigo = 'INFORME_ECONOMICO' AND activo = 0)
    PRINT 'ATENCION: INFORME_ECONOMICO existe con activo = 0. Cuando el codigo este publicado: UPDATE dbo.FP_MODULOS SET activo = 1 WHERE codigo = ''INFORME_ECONOMICO''.';
GO

PRINT 'Alta del Informe Economico en Gestionusuarios lista.';
GO
