<?php
/**
 * SaldosController.php
 * Controlador de la pestana Saldos: disponible inicial y caja de locales.
 *
 * Las dos sub-pestanas se piden por separado. No es solo prolijidad: la
 * pestana 2 consulta el servidor de locales, que puede estar caido, y en ese
 * caso la pestana 1 tiene que seguir dibujandose igual.
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
