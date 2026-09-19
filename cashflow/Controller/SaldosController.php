<?php
/**
 * SaldosController.php
 * Controlador de la pestana Saldos: disponible inicial, caja de locales y las
 * cuentas de fondo (inversion y comitente) con su cuenta corriente.
 *
 * Las tres sub-pestanas se piden por separado. No es solo prolijidad: la
 * pestana 2 consulta el servidor de locales, que puede estar caido, y en ese
 * caso la pestana 1 tiene que seguir dibujandose igual. La 3 depende de un
 * script que puede no haberse corrido, y entonces avisa sin tumbar a las otras.
 *
 * LOS MOVIMIENTOS DE UN FONDO SE VALIDAN EN Class/Fondos.php, no aca ni en el
 * navegador: el endpoint es alcanzable sin pasar por la pantalla. La moneda no
 * viaja -sale de la cuenta- y un movimiento que corrige a otro lo dice con
 * 'id_reemplaza', para que el anterior quede en el historial y no se pise.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/**
 * Usuario que realiza la edicion.
 * Todavia no hay login: devuelve NULL y se graba NULL. Cuando exista, solo hay
 * que poblar $_SESSION['usuario'].
 * @return string|null
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

/**
 * Lee y decodifica el body JSON del request
 * @return array
 */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Saldos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $saldos = new Saldos();

    switch ($action) {
        case 'getSaldos':
            echo json_encode([
                'success' => true,
                'data' => $saldos->getPestanaSaldos()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getLocales':
            echo json_encode([
                'success' => true,
                'data' => $saldos->getPestanaLocales()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'guardarCargaSaldos':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // El saldo del efectivo central NO se toma de lo que manda el
            // cliente: lo vuelve a leer la clase de su consulta de origen.
            $id = $saldos->guardarCargaSaldos(
                $data['filas'],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Carga de saldos guardada. Las cargas anteriores quedan en el '
                           . 'histórico: no se pisó ninguna.',
                'data' => ['id' => $id]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'guardarCargaLocales':
            $data = bodyJson();

            // Los saldos de la consulta y las fechas los relee la clase; del
            // cliente se aceptan gestion, reserva y el saldo tipeado a mano.
            $r = $saldos->guardarCargaLocales(
                isset($data['filas']) ? $data['filas'] : [],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            $mensaje = 'Guardado: ' . $r['filas'] . ' locales en el histórico';

            // Se informa cuantos parametros cambiaron porque eso es lo que
            // afecta al tablero de ahora en mas. Cero cambios tambien se dice:
            // "guardado" sin mas dejaria la duda de si la edicion tomo efecto.
            $mensaje .= ($r['parametros'] > 0)
                ? ' y ' . $r['parametros'] . ' local(es) con la gestión o la reserva '
                    . 'actualizadas, que es lo que va a usar el tablero.'
                : '. No cambió ninguna gestión ni reserva.';

            if ($r['saldos_manuales'] > 0) {
                $mensaje .= ' ' . $r['saldos_manuales'] . ' saldo(s) en caja cargados a mano: '
                    . 'mandan sobre la consulta hasta que ésta traiga un cierre más nuevo.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ============================================================
           PESTANA 3: FONDOS
           ============================================================ */

        case 'getFondos':
            echo json_encode([
                'success' => true,
                'data' => $saldos->getPestanaFondos()
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* El historial completo de una cuenta: vigentes, pisados y dados de
           baja. Es lo que explica por que el saldo de ayer era otro. */
        case 'getMovimientosFondo':
            if (!isset($_GET['id_cuenta'])) {
                throw new Exception('Falta la cuenta');
            }

            require_once __DIR__ . '/../Class/Fondos.php';

            echo json_encode([
                'success' => true,
                'data' => (new Fondos())->getMovimientos(intval($_GET['id_cuenta']))
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'guardarMovimientoFondo':
            $data = bodyJson();

            foreach (['id_cuenta', 'fecha', 'tipo', 'importe'] as $campo) {
                if (!isset($data[$campo]) || $data[$campo] === '') {
                    throw new Exception('Falta el campo "' . $campo . '" del movimiento');
                }
            }

            require_once __DIR__ . '/../Class/Fondos.php';

            $r = (new Fondos())->guardarMovimiento(
                $data['id_cuenta'],
                $data['fecha'],
                $data['tipo'],
                $data['importe'],
                isset($data['observacion']) ? $data['observacion'] : null,
                usuarioActual(),
                (isset($data['id_reemplaza']) && $data['id_reemplaza'] !== '')
                    ? intval($data['id_reemplaza']) : null
            );

            $cuanto = ($r['moneda'] === 'USD' ? 'US$ ' : '$ ')
                . number_format($r['importe'], 2, ',', '.');
            $que = ($r['tipo'] === 'SUSCRIPCION') ? 'Suscripción' : 'Rescate';

            echo json_encode([
                'success' => true,
                'message' => $r['reemplazo']
                    ? $que . ' corregido a ' . $cuanto . '. La versión anterior queda en el '
                        . 'historial de la cuenta.'
                    : $que . ' de ' . $cuanto . ' cargado. El saldo del fondo y el stock del '
                        . 'tablero ya lo reflejan.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* No borra la fila: la da de baja. Ver Fondos::bajaMovimiento(). */
        case 'bajaMovimientoFondo':
            $data = bodyJson();

            if (!isset($data['id_cuenta']) || !isset($data['id'])) {
                throw new Exception('Faltan la cuenta o el movimiento a dar de baja');
            }

            require_once __DIR__ . '/../Class/Fondos.php';

            $habia = (new Fondos())->bajaMovimiento(intval($data['id_cuenta']), intval($data['id']));

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'Movimiento dado de baja. Queda en el historial de la cuenta, tachado.'
                    : 'Ese movimiento ya no estaba vigente.',
                'data' => ['habia' => $habia]
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
