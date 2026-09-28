<?php
/**
 * AuthInformeEconomico
 * Autenticacion y permisos del Informe Economico contra el padron de
 * Gestionusuarios. Mismo mecanismo que cashflow/Class/AuthCashflow.php.
 *
 * POR QUE ES UNA COPIA Y NO SE COMPARTE CON AuthCashflow
 * -----------------------------------------------------
 * No hay forma limpia de reusarla sin tocar cashflow: el modulo (7) y el
 * prefijo 'cashflow.tab.' estan escritos dentro de init() y de puede(), y el
 * estado es estatico de una sola instancia, asi que cargarla desde aca traeria
 * los permisos de Cashflow y no los de este modulo. Queda como pendiente sacar
 * la parte comun a un class/AuthPadron.php en otra rama (README).
 *
 * Lo que SI cambia respecto de la original:
 *   - el modulo se resuelve por FP_MODULOS.codigo, en UN solo lugar
 *     (MODULO_CODIGO), y no por un id escrito en el codigo;
 *   - puede() recibe la clave completa ('ie.tab.canales', 'ie.editar'), porque
 *     aca hay permisos que no son de pestana.
 *
 * FALLA CERRADA: sin el padron, sin sesion o ante cualquier error de base no
 * hay usuario, y puede() devuelve false para todo. Nadie entra de mas.
 */
define('AUTH_IE_PADRON', __DIR__ . '/../../../Gestionusuarios/config/Database.php');

if (file_exists(AUTH_IE_PADRON)) {
    require_once AUTH_IE_PADRON;
}

class AuthInformeEconomico {

    /** El codigo del modulo en FP_MODULOS. El unico lugar donde se nombra. */
    const MODULO_CODIGO = 'INFORME_ECONOMICO';

    const EDITAR = 'ie.editar';

    private static $inicializado = false;
    private static $usuario = null;
    private static $permisos = [];
    private static $esAdmin = false;

    public static function init() {
        if (self::$inicializado) {
            return;
        }

        self::$inicializado = true;

        if (!class_exists('\GestionUsuarios\Config\Database')) {
            return;
        }

        // Por linea de comandos (las pruebas) no hay sesion y el estado
        // correcto es justamente ese: sin usuario, denegando todo.
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $username = $_SESSION['username'] ?? $_SESSION['usuario'] ?? $_SESSION['nodo_usuario_activo']
            ?? ($_SESSION['fp_auth_user']['username'] ?? null);

        if (!$username) {
            return;
        }

        try {
            $db = \GestionUsuarios\Config\Database::getInstance()->connectApps();

            if (!$db) {
                return;
            }

            $sql = "SELECT u.id, u.username, u.nombre_completo, u.rol_id, u.sector_id,
                           s.codigo AS sector_codigo, s.nombre AS sector_nombre,
                           r.nombre AS rol_nombre, r.codigo AS rol_codigo, r.es_admin
                    FROM FP_SOF_USUARIOS u
                    LEFT JOIN FP_SECTORES s ON s.id = u.sector_id
                    LEFT JOIN FP_ROLES_PERMISOS_MAP r ON r.id = u.rol_id
                    WHERE UPPER(TRIM(u.username)) = UPPER(TRIM(?)) AND u.activo = 1";

            $stmt = sqlsrv_query($db, $sql, [trim($username)]);

            if (!$stmt || !($user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                return;
            }

            // La MISMA regla de administrador que AuthCashflow y AuthCentral:
            // sector Proyectos (id 7) + es_admin / CONTROLTOTAL. Cubre al rol
            // Control Total del subsector Desarrollo, que entra a todo sin
            // asignarle nada. Se copia tal cual, incluidos los casos de rol 1
            // y el usuario puntual, para que las tres digan lo mismo.
            $esSectorProyectos = ((int) ($user['sector_id'] ?? 0) === 7)
                || (strtoupper(trim($user['sector_codigo'] ?? '')) === 'PROYECTOS')
                || (stripos($user['sector_nombre'] ?? '', 'Proyectos') !== false);

            self::$esAdmin = $esSectorProyectos && (
                !empty($user['es_admin'])
                || (int) ($user['rol_id'] ?? 0) === 1
                || strtoupper(trim($user['rol_codigo'] ?? '')) === 'CONTROLTOTAL'
                || strtoupper(trim($user['username'] ?? '')) === 'FRANCOPERTUS'
            );

            self::$usuario = [
                'id' => $user['id'],
                'username' => $user['username'],
                'nombre' => $user['nombre_completo'],
                'rol_id' => $user['rol_id'],
                'rol_nombre' => $user['rol_nombre'] ?? 'Sin Rol Asignado',
                'sector_nombre' => $user['sector_nombre'] ?? 'Sin Sector',
                'es_admin' => self::$esAdmin
            ];

            if (!empty($user['rol_id'])) {
                $sqlP = "SELECT p.clave
                         FROM FP_ROL_PERMISO rp
                         JOIN FP_PERMISOS p ON p.id = rp.permiso_id
                         JOIN FP_MODULOS m ON m.id = p.modulo_id
                         WHERE rp.rol_id = ? AND m.codigo = ?";
                $stmtP = sqlsrv_query($db, $sqlP, [$user['rol_id'], self::MODULO_CODIGO]);

                if ($stmtP) {
                    while ($p = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC)) {
                        self::$permisos[$p['clave']] = true;
                    }
                }
            }
        } catch (\Throwable $t) {
            // Falla cerrada: se queda con lo que haya, que en el peor caso es
            // un usuario sin permisos.
        }
    }

    public static function usuario() {
        self::init();

        return self::$usuario;
    }

    /** El nombre que se guarda en la auditoria */
    public static function username() {
        $u = self::usuario();

        return $u ? (string) $u['username'] : null;
    }

    public static function esAdmin() {
        self::init();

        return self::$esAdmin;
    }

    public static function estaAutenticado() {
        return self::usuario() !== null;
    }

    /** @param string $clave 'ie.tab.canales', 'ie.editar', ... */
    public static function puede($clave) {
        self::init();

        if (self::$esAdmin) {
            return true;
        }

        if (!self::$usuario) {
            return false;
        }

        return isset(self::$permisos[trim($clave)]);
    }

    public static function puedeEditar() {
        return self::puede(self::EDITAR);
    }
}
