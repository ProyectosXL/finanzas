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

/**
 * Las dos negativas de una escritura, separadas porque piden cosas distintas a
 * quien las recibe: sin usuario hay que volver a entrar (401); sin permiso hay
 * que pedirlo en Gestionusuarios (403). Ver Controller/autorizacion.php, que es
 * el unico lugar que las traduce a HTTP.
 */
class AuthCashflowSinUsuario extends Exception {}

class AuthCashflowSinPermiso extends Exception {}

class AuthCashflow {

    /** Largo de las columnas USUARIO_* de todas las tablas del modulo */
    const LARGO_USUARIO = 50;

    /**
     * Prefijos de lo que se graba cuando la escritura no la hizo una persona.
     *
     *   SISTEMA: un efecto lateral del codigo PHP, sin accion explicita de nadie.
     *            Lleva Clase.metodo.
     *   JOB:     un job del SQL Agent o un SP corrido sin usuario. Lo arma el
     *            SQL, no el PHP; las claves de abajo existen para que la
     *            pantalla las nombre y para que una prueba verifique que los SP
     *            graben exactamente estas.
     *
     * Con un prefijo y no con un NULL: NULL ya significa "fila historica, de
     * antes de que hubiera auditoria", y mezclar los dos casos haria imposible
     * distinguir un proceso de un dato que nadie sabe de donde salio.
     */
    const ORIGEN_SISTEMA = 'SISTEMA';
    const ORIGEN_JOB = 'JOB';

    /**
     * Los origenes JOB: que existen, con el nombre con el que se muestran.
     *
     * Van los dos de cada SP con @Usuario: el corto, que es el que le pasa el
     * paso del job, y el del nombre del SP, que es en lo que cae el SP cuando
     * alguien lo corre a mano sin pasarlo.
     */
    const ORIGENES = [
        'JOB:COMEX_RECEP_HIST' => 'Historia de recepciones Comex',
        'JOB:RO_SP_CASHFLOW_COMEX_RECEP_HIST' => 'Historia de recepciones Comex',
        'JOB:COMEX_PRESUP' => 'Presupuesto Comex',
        'JOB:RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN' => 'Presupuesto Comex',
        'JOB:VENTAS_HIST' => 'Histórico de ventas',
        'JOB:SJ_CASHFLOW_VENTAS_HIST' => 'Histórico de ventas',
        'JOB:VENTAS_HIST_DIA' => 'Histórico de ventas por día',
        'JOB:RO_SP_CASHFLOW_VENTAS_HIST_DIA' => 'Histórico de ventas por día'
    ];

    /**
     * Sub-pestaña que no se puede saber de antemano: sale del MODULO del
     * parametro que se guarda. Es el caso de saveParametro, un solo endpoint
     * que usan cuatro sub-pestañas de Parametros. Ver exigirAccion().
     */
    const SUB_DEL_PARAMETRO = '@MODULO';

    /**
     * QUE PERMISO EXIGE CADA ACCION DE CADA CONTROLLER.
     *
     * Es la UNICA fuente: ningun controller escribe un nombre de permiso suelto.
     * Cada accion de escritura dice que (pestaña, sub-pestaña) hay que poder
     * editar para ejecutarla.
     *
     * UNA LISTA Y NO UN PAR, porque hay acciones que viven en dos pestañas -la
     * fecha de Comex se mueve desde Proveedores Exterior y desde Crono
     * Nacionalizacion; la fecha de cobro, desde Cobranzas FR y desde May-. Con
     * poder editar CUALQUIERA de las dos alcanza: el endpoint hace lo mismo
     * venga de donde venga, y exigir las dos le negaria la accion a quien solo
     * trabaja una pestaña.
     *
     * LOS PREVIEW TAMBIEN EXIGEN EDICION. No escriben, pero son el primer paso
     * de una escritura -leen el archivo subido y arman lo que se va a aplicar- y
     * la pantalla no los ofrece a quien no puede aplicar.
     *
     * Las acciones de lectura van declaradas en LECTURAS y no exigen nada nuevo:
     * una accion que no esta en ninguna de las dos listas se rechaza. Asi un
     * case nuevo no puede quedar abierto por olvido, y la prueba
     * tests/test_auth_cashflow.php falla si alguien lo agrega sin declararlo.
     */
    const ESCRITURAS = [
        'CashflowEstructura' => [
            'saveEstructura' => [['parametros', 'CASHFLOW']],
            'addFila' => [['parametros', 'CASHFLOW']],
            'addSeccion' => [['parametros', 'CASHFLOW']]
        ],
        'CobElectronicos' => [
            'addMovimiento' => [['cob_electronicos', null]],
            'saveMovimiento' => [['cob_electronicos', null]],
            'previsualizarImportacion' => [['cob_electronicos', null]],
            'confirmarImportacion' => [['cob_electronicos', null]],
            'bajaMovimiento' => [['cob_electronicos', null]]
        ],
        'Cobertura' => [
            'saveAplicacion' => [['cashflow', null]],
            'deleteAplicacion' => [['cashflow', null]]
        ],
        'Comex' => [
            'updateCotizacion' => [['proveedores_exterior', null]],
            'updateFecha' => [['proveedores_exterior', null], ['crono_nacionalizacion', null]],
            'marcarPagado' => [['proveedores_exterior', null], ['crono_nacionalizacion', null]]
        ],
        'ComprasProyectadas' => [
            'actualizarInsumo' => [['compras_proyectadas', null]],
            'guardarAjuste' => [['compras_proyectadas', null]],
            'quitarAjuste' => [['compras_proyectadas', null]]
        ],
        'Echeqs' => [
            'marcarCheques' => [['echeqs', null]],
            'excluirCheques' => [['echeqs', null]]
        ],
        'Ingresos' => [
            'saveFechaCobroManual' => [['cobranzas_fr', null], ['cobranzas_may', null]],
            'deleteFechaCobroManual' => [['cobranzas_fr', null], ['cobranzas_may', null]]
        ],
        'Logistica' => [
            'saveFletero' => [['logistica_local', null], ['parametros', 'LOGISTICA']],
            'activarFletero' => [['parametros', 'LOGISTICA']]
        ],
        'Parametros' => [
            'saveParametro' => [['parametros', self::SUB_DEL_PARAMETRO]],
            'saveInflacionMes' => [['parametros', 'GENERALES']],
            'aplicarInflacionConstante' => [['parametros', 'GENERALES']],
            'saveFechaCronograma' => [['parametros', 'GENERALES']],
            'quitarFechaCronograma' => [['parametros', 'GENERALES']],
            /* El conteo de overrides que se darian de baja es el primer paso de
               cambiar el dia de un concepto: como los preview, pide edicion. */
            'contarOverridesCronograma' => [['parametros', 'GENERALES']],
            'saveConfigCronograma' => [['parametros', 'GENERALES']],
            'saveMixCobro' => [['parametros', 'VENTAS']],
            'addMixCobro' => [['parametros', 'VENTAS']],
            'saveRespaldo' => [['parametros', 'VENTAS']],
            'addCuentaSaldo' => [['parametros', 'SALDOS']],
            'saveCuentasSaldo' => [['parametros', 'SALDOS']],
            'saveSucursalesSaldo' => [['parametros', 'SALDOS']],
            'sincronizarSucursales' => [['parametros', 'SALDOS']],
            'addProcesadoraCobel' => [['parametros', 'COB_ELECTRONICOS']],
            'saveProcesadorasCobel' => [['parametros', 'COB_ELECTRONICOS']],
            'addAlicuotaCobel' => [['parametros', 'COB_ELECTRONICOS']],
            'bajaAlicuotaCobel' => [['parametros', 'COB_ELECTRONICOS']],
            'addClientePrecheq' => [['parametros', 'PRECHEQUEADO']],
            'saveDiasPrecheq' => [['parametros', 'PRECHEQUEADO']],
            'bajaClientePrecheq' => [['parametros', 'PRECHEQUEADO']],
            'addOpcionProvLocal' => [['parametros', 'PROV_LOCALES']],
            'saveOpcionProvLocal' => [['parametros', 'PROV_LOCALES']],
            'bajaOpcionProvLocal' => [['parametros', 'PROV_LOCALES']],
            'savePPPManualGrupo' => [['parametros', 'COBRANZAS']],
            'saveMedioPagoCliente' => [['parametros', 'COBRANZAS']],
            'saveEscalaDescuento' => [['parametros', 'COBRANZAS']]
        ],
        'Proveedores' => [
            'savePago' => [['proveedores_locales', null]],
            'saveFechaMasiva' => [['proveedores_locales', null]],
            'saveFormaCronograma' => [['proveedores_locales', null]],
            'saveExclusion' => [['proveedores_locales', null]],
            'deletePago' => [['proveedores_locales', null]],
            'previewPagos' => [['proveedores_locales', null]],
            'aplicarPagos' => [['proveedores_locales', null]],
            'previewMaestro' => [['proveedores_locales', null]],
            'aplicarMaestro' => [['proveedores_locales', null]],
            'excluirProveedor' => [['proveedores_locales', null]],
            'incluirProveedor' => [['proveedores_locales', null]],
            'saveProveedor' => [['proveedores_locales', null]],
            'deleteProveedor' => [['proveedores_locales', null]],
            'previewConciliacion' => [['proveedores_locales', null]],
            'aplicarConciliacion' => [['proveedores_locales', null]]
        ],
        'Saldos' => [
            'guardarCargaSaldos' => [['saldos', null]],
            'guardarCargaLocales' => [['saldos', null]],
            'guardarMovimientoFondo' => [['saldos', null]],
            'bajaMovimientoFondo' => [['saldos', null]]
        ],
        'Tarjetas' => [
            'vincularFacturas' => [['pagos_tarjetas', null]],
            'desvincularFacturas' => [['pagos_tarjetas', null]],
            'excluirFacturas' => [['pagos_tarjetas', null]],
            'incluirFacturas' => [['pagos_tarjetas', null]],
            'saveResumen' => [['pagos_tarjetas', null]],
            'pagarResumen' => [['pagos_tarjetas', null]],
            'bajaResumen' => [['pagos_tarjetas', null]],
            'cargarBase' => [['pagos_tarjetas', null]],
            'saveTarjeta' => [['parametros', 'TARJETAS']],
            'activarTarjeta' => [['parametros', 'TARJETAS']]
        ],
        'Ventas' => [
            'saveParticipacion' => [['ventas', null]],
            'saveIndice' => [['ventas', null]]
        ]
    ];

    /** Las acciones que solo leen. Ver ESCRITURAS. */
    const LECTURAS = [
        'Cashflow' => ['getTablero'],
        'CashflowEstructura' => ['getEstructura'],
        'CobElectronicos' => ['getPestana'],
        'Cobertura' => ['getAplicaciones', 'getHistorialCobertura'],
        'Comex' => ['getProveedoresExterior', 'getPagosContenedor', 'getProveedoresExteriorRaw',
            'getHistorialPagado', 'getHistorialFecha', 'getCronoNacionalizacion'],
        'ComprasProyectadas' => ['getGrilla', 'getDetalleVersion', 'getHistorialAjustes'],
        'Echeqs' => ['getEcheqsCartera', 'getEcheqsPrechequeado', 'getHistorialExclusion'],
        'Ingresos' => ['getCobranzasFR', 'getCobranzasMay', 'getExportacionesTasky'],
        'Logistica' => ['getPlanilla', 'getFleteros', 'buscarProveedorTango'],
        'Parametros' => ['getTodo', 'getParametros', 'getHistorialCronograma', 'getMixCobro',
            'buscarClientePrecheq', 'getCobranzasClientesConfig', 'getEscalaDescuento'],
        'Proveedores' => ['plantillaMaestro', 'plantillaPagos', 'getPendientes', 'buscarProveedorTango', 'getMaestro',
            'getHistorialExclusion', 'getHistorialProveedor'],
        'Saldos' => ['getSaldos', 'getLocales', 'getFondos', 'getMovimientosFondo'],
        'Tarjetas' => ['getDatos', 'getHistorialFactura', 'getTarjetas', 'getResumenes'],
        'Ventas' => ['getProyeccionVentas', 'getProyeccionCobranzas', 'getAnalisisVentas',
            'getVentaAcumulada', 'getVentaBalance', 'getParametros']
    ];

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

        // Sin usuario no hay permisos cargados ni admin: permiteVer() deniega.
        return self::permiteVer(self::$permisos, self::$esAdmin, $tab);
    }

    /**
     * Si el usuario actual puede editar una pestaña o, en Parametros, una
     * sub-pestaña.
     *
     * @param string $tab Codigo de pestaña
     * @param string|null $sub Codigo de sub-pestaña de Parametros (GENERALES, ...)
     * @return bool
     */
    public static function puedeEditar($tab, $sub = null) {
        self::init();

        return self::permiteEditar(self::$permisos, self::$esAdmin, $tab, $sub);
    }

    /**
     * El atributo que la vista pone en la raiz de una pestaña -o de una
     * sub-pestaña de Parametros- para que el JS sepa si dibujar los controles
     * de edicion. Ver Js/permisos.js.
     *
     * ES COMODIDAD, NO SEGURIDAD. Quien no puede editar no ve los botones, pero
     * lo que lo frena es el 403 del servidor (Controller/autorizacion.php): un
     * atributo del HTML lo cambia cualquiera desde la consola.
     *
     * @param string $tab
     * @param string|null $sub
     * @return string ' data-puede-editar="1"' o ' data-puede-editar="0"'
     */
    public static function atributoEdicion($tab, $sub = null) {
        return ' data-puede-editar="' . (self::puedeEditar($tab, $sub) ? '1' : '0') . '"';
    }

    /**
     * Regla pura de lectura, sobre un juego de permisos dado.
     *
     * @param array $permisos clave => true
     * @param bool $esAdmin
     * @param string $tab
     * @return bool
     */
    public static function permiteVer(array $permisos, $esAdmin, $tab) {
        if ($esAdmin) {
            return true;
        }

        return isset($permisos['cashflow.tab.' . trim((string) $tab)]);
    }

    /**
     * Regla pura de edicion, sobre un juego de permisos dado.
     *
     * ESCRIBIR IMPLICA LEER. Sin cashflow.tab.<tab> se niega aunque el rol
     * tenga la clave de edicion: alguien que no ve la pestaña no tiene desde
     * donde editarla, y una clave de edicion suelta es casi seguro un error de
     * asignacion en Gestionusuarios, no una intencion.
     *
     * La sub-pestaña solo existe en Parametros. La lectura de Parametros es una
     * sola -cashflow.tab.parametros- y la edicion va por sub-pestaña, porque
     * quien carga las cuentas de Saldos no necesariamente es quien toca el
     * horizonte del modulo entero.
     *
     * @param array $permisos clave => true
     * @param bool $esAdmin
     * @param string $tab
     * @param string|null $sub
     * @return bool
     */
    public static function permiteEditar(array $permisos, $esAdmin, $tab, $sub = null) {
        if ($esAdmin) {
            return true;
        }

        if (!self::permiteVer($permisos, false, $tab)) {
            return false;
        }

        return isset($permisos[self::claveEdicion($tab, $sub)]);
    }

    /**
     * La clave de FP_PERMISOS que habilita editar una pestaña o sub-pestaña.
     * La misma que da de alta sql/cashflow_permisos_edicion.sql.
     *
     * @param string $tab
     * @param string|null $sub
     * @return string
     */
    public static function claveEdicion($tab, $sub = null) {
        $tab = trim((string) $tab);

        if ($tab === 'parametros' && $sub !== null && $sub !== '') {
            return 'cashflow.editar.parametros.' . strtolower(trim((string) $sub));
        }

        return 'cashflow.editar.' . $tab;
    }

    /**
     * El username del padron, tal como se graba en las columnas USUARIO_*.
     *
     * Sale SOLO del padron. No hay vuelta a $_SESSION['usuario']: un nombre que
     * no esta en Gestionusuarios no tiene permisos que verificar, y grabarlo
     * igual seria firmar la fila con alguien a quien nunca se le pregunto si
     * podia.
     *
     * @return string|null
     */
    public static function username() {
        self::init();

        return self::recortarUsuario(self::$usuario['username'] ?? null);
    }

    /**
     * Recorta un usuario al largo de las columnas, o null si queda vacio.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function recortarUsuario($valor) {
        $valor = trim((string) $valor);

        return ($valor === '') ? null : mb_substr($valor, 0, self::LARGO_USUARIO);
    }

    /**
     * El usuario que recibe un metodo de escritura, validado.
     *
     * DEFENSA EN PROFUNDIDAD. El controller ya lo exigio, pero las clases se
     * llaman tambien desde pruebas, sondas y procesos, y un metodo que acepta un
     * usuario vacio graba una fila que no dice quien la hizo -justo lo que la
     * auditoria tiene que impedir-. Por eso ningun metodo de escritura tiene
     * $usuario = null por defecto, y todos pasan por aca antes de tocar la base.
     *
     * @param mixed $usuario Un username o un origen SISTEMA:/JOB:
     * @return string Recortado a LARGO_USUARIO
     * @throws InvalidArgumentException si viene vacio
     */
    public static function usuarioDeEscritura($usuario) {
        $u = self::recortarUsuario($usuario);

        if ($u === null) {
            throw new InvalidArgumentException('No se puede guardar un cambio sin saber quién lo '
                . 'hace: falta el usuario.');
        }

        return $u;
    }

    /**
     * El username, o una excepcion que el controller convierte en 401.
     *
     * @return string
     * @throws AuthCashflowSinUsuario
     */
    public static function exigirUsuario() {
        $u = self::username();

        if ($u === null) {
            throw new AuthCashflowSinUsuario('No se pudo identificar tu usuario en el padrón, '
                . 'así que el cambio no se guardó. Volvé a ingresar al sistema y probá de nuevo.');
        }

        return $u;
    }

    /**
     * exigirUsuario() y ademas el permiso de edicion. Devuelve el username.
     *
     * @param string $tab
     * @param string|null $sub
     * @return string
     * @throws AuthCashflowSinUsuario|AuthCashflowSinPermiso
     */
    public static function exigirEdicion($tab, $sub = null) {
        $u = self::exigirUsuario();

        if (!self::puedeEditar($tab, $sub)) {
            throw new AuthCashflowSinPermiso(self::mensajeSinPermiso([[$tab, $sub]]));
        }

        return $u;
    }

    /**
     * La guarda de un controller: exige lo que el mapa dice para esa accion.
     *
     * Devuelve el username si la accion escribe y null si solo lee. Una accion
     * que no esta declarada se rechaza (falla cerrada; ver ESCRITURAS).
     *
     * @param string $controlador Nombre sin "Controller" ('Comex', 'Parametros')
     * @param string $accion
     * @param string|null $subDinamico La sub-pestaña ya resuelta, para las
     *                                 acciones declaradas con SUB_DEL_PARAMETRO
     * @return string|null
     * @throws AuthCashflowSinUsuario|AuthCashflowSinPermiso
     */
    public static function exigirAccion($controlador, $accion, $subDinamico = null) {
        $destinos = self::destinosDe($controlador, $accion, $subDinamico);

        if ($destinos === null) {
            return null;
        }

        $u = self::exigirUsuario();

        foreach ($destinos as $d) {
            if (self::puedeEditar($d[0], $d[1])) {
                return $u;
            }
        }

        throw new AuthCashflowSinPermiso(self::mensajeSinPermiso($destinos));
    }

    /**
     * Que (pestaña, sub) hay que poder editar para una accion. Pura.
     *
     * @param string $controlador
     * @param string $accion
     * @param string|null $subDinamico
     * @return array|null null si la accion solo lee
     * @throws AuthCashflowSinPermiso si la accion no esta declarada, o si
     *         necesita la sub-pestaña y no se la pasaron
     */
    public static function destinosDe($controlador, $accion, $subDinamico = null) {
        if (in_array($accion, self::LECTURAS[$controlador] ?? [], true)) {
            return null;
        }

        if (!isset(self::ESCRITURAS[$controlador][$accion])) {
            throw new AuthCashflowSinPermiso('La acción "' . $accion . '" no está declarada en el '
                . 'mapa de permisos del módulo (Class/AuthCashflow.php), así que no se ejecuta.');
        }

        $destinos = [];

        foreach (self::ESCRITURAS[$controlador][$accion] as $d) {
            if ($d[1] === self::SUB_DEL_PARAMETRO) {
                if ($subDinamico === null || trim((string) $subDinamico) === '') {
                    throw new AuthCashflowSinPermiso('No se pudo determinar a qué sub-pestaña de '
                        . 'Parámetros pertenece este valor, así que no se puede verificar el permiso.');
                }

                $d[1] = $subDinamico;
            }

            $destinos[] = $d;
        }

        return $destinos;
    }

    /**
     * Si la accion necesita que le pasen la sub-pestaña (SUB_DEL_PARAMETRO).
     *
     * @param string $controlador
     * @param string $accion
     * @return bool
     */
    public static function necesitaSubDelParametro($controlador, $accion) {
        foreach (self::ESCRITURAS[$controlador][$accion] ?? [] as $d) {
            if ($d[1] === self::SUB_DEL_PARAMETRO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arma el valor que se graba cuando la escritura no la hizo una persona.
     *
     * @param string $proceso Clase.metodo para SISTEMA, nombre corto para JOB
     * @param string $tipo ORIGEN_SISTEMA u ORIGEN_JOB
     * @return string
     */
    public static function origen($proceso, $tipo = self::ORIGEN_SISTEMA) {
        $proceso = trim((string) $proceso);

        if ($proceso === '') {
            throw new InvalidArgumentException('Un origen automático necesita el nombre del proceso.');
        }

        if ($tipo !== self::ORIGEN_SISTEMA && $tipo !== self::ORIGEN_JOB) {
            throw new InvalidArgumentException('Origen desconocido: ' . $tipo);
        }

        return mb_substr($tipo . ':' . $proceso, 0, self::LARGO_USUARIO);
    }

    /**
     * El mensaje del 403, nombrando la clave que falta para que quien lo lee
     * sepa que pedir en Gestionusuarios.
     */
    private static function mensajeSinPermiso(array $destinos) {
        $claves = array_map(function ($d) {
            return self::claveEdicion($d[0], $d[1]);
        }, $destinos);

        return 'Tu rol no tiene permiso para modificar esta sección (' . implode(' o ', $claves)
            . '). Podés consultarla, pero el cambio no se guardó.';
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
