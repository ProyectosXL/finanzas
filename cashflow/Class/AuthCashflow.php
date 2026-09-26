<?php
/**
 * AuthCashflow
 * Verificación centralizada de autenticación y permisos de usuario para Cashflow.
 */

/**
 * El padrón de usuarios vive en OTRO módulo de htdocs, no en este repo.
 *
 * El require va condicionado porque un checkout de finanzas sin Gestionusuarios
 * al lado es un caso real -es lo que pasa al correr tests/run.php en una máquina
 * que sólo clonó este repo- y ahí un require_once directo mata el proceso con un
 * fatal, sin llegar a ninguna prueba.
 *
 * FALLA CERRADA, y eso es lo que hace que la guarda sea segura: sin el padrón no
 * hay usuario, así que puede() devuelve false para TODO y TabController
 * responde 403. Nadie entra de más; lo que se pierde es la aplicación, no el
 * control de acceso.
 */
define('AUTH_CASHFLOW_PADRON', __DIR__ . '/../../../Gestionusuarios/config/Database.php');

if (file_exists(AUTH_CASHFLOW_PADRON)) {
    require_once AUTH_CASHFLOW_PADRON;
}

class AuthCashflow {

    private static $inicializado = false;
    private static $usuario = null;
    private static $permisos = [];
    private static $esAdmin = false;

    public static function init() {
        if (self::$inicializado) {
            return;
        }

        // Sin el padrón no hay a quién preguntarle: se queda sin usuario, que es
        // el estado en el que puede() deniega todo.
        if (!class_exists('\GestionUsuarios\Config\Database')) {
            self::$inicializado = true;

            return;
        }

        /* !headers_sent(): arrancar una sesión después de haber emitido salida
           no funciona y sólo deja un warning. Pasa por línea de comandos -las
           pruebas- y ahí el estado correcto es justamente el que queda: sin
           sesión, o sea sin usuario, o sea denegando todo. */
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $username = $_SESSION['username'] ?? $_SESSION['usuario'] ?? $_SESSION['nodo_usuario_activo'] ?? ($_SESSION['fp_auth_user']['username'] ?? null);

        if (!$username) {
            self::$inicializado = true;
            return;
        }

        try {
            $db = \GestionUsuarios\Config\Database::getInstance()->connectApps();
            if (!$db) {
                self::$inicializado = true;
                return;
            }

            $sql = "SELECT u.id, u.username, u.nombre_completo, u.email, u.tipo, 
                           u.rol_id, u.sector_id,
                           s.codigo as sector_codigo, s.nombre as sector_nombre,
                           r.nombre as rol_nombre, r.codigo as rol_codigo, r.es_admin
                    FROM FP_SOF_USUARIOS u 
                    LEFT JOIN FP_SECTORES s ON s.id = u.sector_id
                    LEFT JOIN FP_ROLES_PERMISOS_MAP r ON r.id = u.rol_id
                    WHERE UPPER(TRIM(u.username)) = UPPER(TRIM(?)) AND u.activo = 1";

            $stmt = sqlsrv_query($db, $sql, [trim($username)]);
            if ($stmt && ($user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                $esSectorProyectos = ((int)($user['sector_id'] ?? 0) === 7)
                    || (strtoupper(trim($user['sector_codigo'] ?? '')) === 'PROYECTOS')
                    || (stripos($user['sector_nombre'] ?? '', 'Proyectos') !== false);

                self::$esAdmin = $esSectorProyectos && (
                    !empty($user['es_admin']) 
                    || (int)($user['es_admin'] ?? 0) === 1
                    || (int)($user['rol_id'] ?? 0) === 1
                    || strtoupper(trim($user['rol_codigo'] ?? '')) === 'CONTROLTOTAL'
                    || strtoupper(trim($user['username'] ?? '')) === 'FRANCOPERTUS'
                );

                self::$usuario = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nombre' => $user['nombre_completo'],
                    'rol_id' => $user['rol_id'],
                    'rol_nombre' => $user['rol_nombre'] ?? 'Sin Rol Asignado',
                    'sector_id' => $user['sector_id'],
                    'sector_nombre' => $user['sector_nombre'] ?? 'Sin Sector',
                    'es_admin' => self::$esAdmin
                ];

                // Cargar permisos específicos para el módulo Cashflow (modulo_id = 7)
                if (!empty($user['rol_id'])) {
                    $sqlP = "SELECT p.clave 
                             FROM FP_ROL_PERMISO rp
                             JOIN FP_PERMISOS p ON p.id = rp.permiso_id
                             WHERE rp.rol_id = ? AND p.modulo_id = 7";
                    $stmtP = sqlsrv_query($db, $sqlP, [$user['rol_id']]);
                    if ($stmtP) {
                        while ($rowP = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC)) {
                            self::$permisos[$rowP['clave']] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $t) {
            // Silencioso para no romper la app en caso de excepción de BD
        }

        self::$inicializado = true;
    }

    public static function usuario() {
        self::init();
        return self::$usuario;
    }

    public static function esAdmin() {
        self::init();
        return self::$esAdmin;
    }

    public static function estaAutenticado() {
        self::init();
        return self::$usuario !== null;
    }

    /**
     * Verifica si el usuario actual puede ver/acceder a una pestaña de Cashflow.
     *
     * @param string $tab Código de pestaña (ej: 'saldos', 'echeqs', 'cashflow')
     * @return bool
     */
    public static function puede($tab) {
        self::init();

        // Si es Admin Total, tiene acceso a todo
        if (self::$esAdmin) {
            return true;
        }

        // Si no está autenticado, denegamos
        if (!self::$usuario) {
            return false;
        }

        $tab = trim($tab);
        $clavePermiso = 'cashflow.tab.' . $tab;

        return isset(self::$permisos[$clavePermiso]);
    }

    /**
     * Devuelve el array de todas las claves de pestañas permitidas.
     */
    public static function tabsPermitidos() {
        self::init();
        if (self::$esAdmin) {
            return ['*'];
        }

        $permitidos = [];
        foreach (array_keys(self::$permisos) as $clave) {
            if (strpos($clave, 'cashflow.tab.') === 0) {
                $permitidos[] = substr($clave, strlen('cashflow.tab.'));
            }
        }
        return $permitidos;
    }

    /**
     * Devuelve la primera pestaña permitida para abrir por defecto.
     *
     * @param string $default Pestaña por defecto deseada (ej. 'cashflow')
     * @return string
     */
    public static function primeraTabPermitida($default = 'cashflow') {
        self::init();

        if (self::$esAdmin || self::puede($default)) {
            return $default;
        }

        $tabs = self::tabsPermitidos();
        if (!empty($tabs)) {
            return $tabs[0];
        }

        return $default;
    }
}
