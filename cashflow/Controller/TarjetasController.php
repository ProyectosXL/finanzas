<?php
/**
 * TarjetasController.php
 * Endpoints del circuito de tarjetas: el maestro, los resumenes, el vinculo
 * factura-tarjeta, la exclusion por factura y los datos de las tres
 * sub-pestanas.
 *
 * UN SOLO CONTROLLER PARA LAS CUATRO PANTALLAS, y por eso no vive en
 * ParametrosController: el maestro se da de alta en Parametros -> Tarjetas y los
 * resumenes se cargan desde las tres sub-pestanas de Financiero -> Pagos con
 * Tarjetas y Otros, contra las mismas tablas. Dos endpoints escribiendo lo mismo
 * se desincronizan en la primera validacion que alguien agregue de un solo lado.
 * Es el mismo criterio con el que Logistica y el modulo CASHFLOW declaran su
 * propio endpoint.
 *
 * LA VALIDACION QUE VALE ES LA DE LAS CLASES. La pantalla acota lo que se puede
 * tipear, pero lo que manda el navegador es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por ella. El banco se vuelve a chequear contra
 * BANCO y el usuario contra la vista en cada guardado, aunque el desplegable los
 * haya ofrecido.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Class/AuthCashflow.php';

/**
 * Usuario que realiza la edicion, para la auditoria de las cuatro tablas.
 *
 * PIDE EL USUARIO DEL PADRON Y NO SOLO $_SESSION['usuario'], a diferencia del
 * resto de los controllers del modulo. Esas cuatro tablas exigen auditoria
 * -USUARIO_ALTA, USUARIO_MODIF, USUARIO_BAJA- y grabarla en NULL la convierte en
 * decoracion: la columna existe, el campo esta, y no contesta quien hizo el
 * cambio.
 *
 * AuthCashflow::init() ya resuelve el usuario de la sesion mirando las cuatro
 * claves con las que las distintas pantallas del sistema la escriben
 * ('username', 'usuario', 'nodo_usuario_activo', 'fp_auth_user'), asi que
 * preguntarle a el es preguntar una sola vez y bien.
 *
 * EL FALLBACK A $_SESSION['usuario'] QUEDA para el caso en que el padron no se
 * pueda leer -Gestionusuarios es otro modulo de htdocs y puede no estar-: ahi
 * AuthCashflow falla cerrado y devuelve null, pero la sesion puede tener el
 * nombre igual. Y si tampoco, se graba NULL, que es lo que hace hoy todo el
 * modulo.
 *
 * El resto del modulo sigue grabando NULL y eso se resuelve aparte: cambiarlo
 * aca para diecinueve pantallas seria un refactor que no es de esta tarea.
 *
 * @return string|null
 */
function usuarioActual() {
    $u = AuthCashflow::usuario();

    if ($u !== null && !empty($u['username'])) {
        return substr(trim((string) $u['username']), 0, 50);
    }

    return isset($_SESSION['usuario']) ? substr(trim((string) $_SESSION['usuario']), 0, 50) : null;
}

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Tarjetas.php';
    require_once __DIR__ . '/../Class/TarjetasResumen.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $tarjetas = new Tarjetas();

    switch ($action) {
        /* ================================================================
           EL MAESTRO
           Lo pide Parametros -> Tarjetas, y tambien las tres sub-pestanas para
           poder ofrecer contra que tarjeta cargar un resumen.
           ================================================================ */
        case 'getTarjetas':
            $tipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? $_GET['tipo'] : null;

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $tarjetas->getTarjetas(false, $tipo),
                    'tipos' => Tarjetas::TIPOS,
                    'bancos' => $tarjetas->bancos(),
                    'usuarios' => $tarjetas->usuarios(),
                    'tabla_creada' => $tarjetas->tablaCreada(),
                    'vista_creada' => $tarjetas->vistaUsuariosCreada(),
                    'bancos_disponibles' => $tarjetas->bancosDisponibles(),
                    'avisos' => $tarjetas->avisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Sirve para el alta, para corregir los datos y para reactivar una baja:
           la clase resuelve cual de los tres es y lo dice en la respuesta. Una
           accion "reactivar" aparte obligaria a la pantalla a saber de antemano
           en que estado estaba. */
        case 'saveTarjeta':
            $data = bodyJson();

            $r = $tarjetas->guardarTarjeta(
                isset($data['id']) ? $data['id'] : null,
                [
                    'tipo' => isset($data['tipo']) ? $data['tipo'] : null,
                    'cod_banco' => isset($data['cod_banco']) ? $data['cod_banco'] : null,
                    'id_usuario' => isset($data['id_usuario']) ? $data['id_usuario'] : null,
                    'ultimos_4' => isset($data['ultimos_4']) ? $data['ultimos_4'] : null,
                    'pct_cobertura' => isset($data['pct_cobertura'])
                        ? $data['pct_cobertura'] : null,
                    'dia_vencimiento' => isset($data['dia_vencimiento'])
                        ? $data['dia_vencimiento'] : null
                ],
                usuarioActual());

            $mensaje = $r['nuevo']
                ? ('Tarjeta dada de alta: ' . $r['rotulo'] . '.')
                : ($r['reactivada']
                    ? ('Tarjeta reactivada: ' . $r['rotulo'] . '.')
                    : ('Datos guardados: ' . $r['rotulo'] . '.'));

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'activarTarjeta':
            $data = bodyJson();

            if (!isset($data['id']) || !array_key_exists('activa', $data)) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $r = $tarjetas->activarTarjeta($data['id'], !empty($data['activa']), usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['activa']
                    ? ('Tarjeta reactivada: ' . $r['rotulo'] . '.')
                    : ('Tarjeta dada de baja: ' . $r['rotulo'] . '. Deja de proyectar, pero no '
                        . 'se borra: conserva todos sus resúmenes y se puede reactivar.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LOS RESUMENES
           Se cargan desde las tres sub-pestanas, sobre la fila de la tarjeta.
           ================================================================ */
        case 'getResumenes':
            $resumen = new TarjetasResumen();

            if (isset($_GET['id_tarjeta']) && $_GET['id_tarjeta'] !== '') {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'historial' => $resumen->historial($_GET['id_tarjeta']),
                        'origenes' => TarjetasResumen::ORIGENES,
                        'tabla_creada' => $resumen->tablaCreada(),
                        'aviso' => $resumen->avisoSinTabla()
                    ]
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'vigentes' => $resumen->vigentes(),
                    'origenes' => TarjetasResumen::ORIGENES,
                    'tabla_creada' => $resumen->tablaCreada(),
                    'aviso' => $resumen->avisoSinTabla()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveResumen':
            $data = bodyJson();

            if (!isset($data['id_tarjeta']) || !isset($data['mes'])) {
                throw new Exception('Faltan la tarjeta y el período del resumen');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->guardar($data['id_tarjeta'], $data['mes'], [
                'importe_ars' => isset($data['importe_ars']) ? $data['importe_ars'] : null,
                'importe_usd' => isset($data['importe_usd']) ? $data['importe_usd'] : null,
                'fecha_vencimiento' => isset($data['fecha_vencimiento'])
                    ? $data['fecha_vencimiento'] : null,
                'origen' => isset($data['origen']) ? $data['origen'] : null,
                'observacion' => isset($data['observacion']) ? $data['observacion'] : null
            ], usuarioActual());

            /* SE DICE QUE PISA LA ESTIMACION, y en el mismo mensaje: un "guardado"
               a secas no explica por que la fila del tablero cambio de numero. */
            $mensaje = ($r['nuevo'] ? 'Resumen cargado' : 'Resumen corregido')
                . ' para ' . $r['mes'] . '. Pisa la estimación de ese mes.'
                . ($r['aviso'] !== '' ? ' ' . $r['aviso'] : '');

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'pagarResumen':
            $data = bodyJson();

            if (!isset($data['id']) || !array_key_exists('pagado', $data)) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->marcarPagado($data['id'], !empty($data['pagado']), usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['pagado']
                    ? ('Resumen de ' . $r['mes'] . ' marcado como pagado: sale del horizonte, '
                        . 'porque esa plata ya está reflejada en el saldo bancario.')
                    : ('Resumen de ' . $r['mes'] . ' desmarcado: vuelve a proyectarse.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaResumen':
            $data = bodyJson();

            if (!isset($data['id'])) {
                throw new Exception('Falta el resumen');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->darDeBaja($data['id'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Resumen de ' . $r['mes'] . ' dado de baja. No se borra: queda en '
                    . 'el historial, y ese mes vuelve a proyectarse con la estimación.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* La carga inicial de base de Tarjetas Socios: varios resumenes de una,
           en UNA transaccion, con ORIGEN = HISTORICO y ya pagados. */
        case 'cargarBase':
            $data = bodyJson();

            if (!isset($data['id_tarjeta']) || !isset($data['resumenes'])) {
                throw new Exception('Faltan la tarjeta y los resúmenes de la base');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->cargarBase($data['id_tarjeta'], $data['resumenes'], usuarioActual());

            $mensaje = 'Base histórica cargada: ' . $r['cargados'] . ' resumen(es) ('
                . implode(', ', $r['meses']) . '). Entran como ya pagados, así que no suman al '
                . 'horizonte: son la base con la que se estima.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
