<?php
/**
 * OtrosIngresosController.php
 * Controlador de la categoria Otros Ingresos. Hoy atiende una sola pestana:
 * Dolares Cuenta Comitente.
 *
 * LA VALIDACION QUE VALE ES LA DE ACA. El formulario del navegador acota lo que
 * se puede tipear, pero lo que manda es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por la pantalla. La fecha y el importe se
 * validan de nuevo en OtrosIngresos::validarFecha() y validarImporte().
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
    require_once __DIR__ . '/../Class/OtrosIngresos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $otros = new OtrosIngresos();

    switch ($action) {
        case 'getDolaresComitente':
            // Los avisos van SIEMPRE, incluso -sobre todo- cuando la grilla
            // esta vacia: es la diferencia entre "falta correr el script" y
            // "todavia nadie cargo nada".
            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $otros->getDolaresComitente(),
                    'avisos' => $otros->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialDolares':
            if (!isset($_GET['fecha'])) {
                throw new Exception('Falta la fecha del historial');
            }

            echo json_encode([
                'success' => true,
                'data' => $otros->getHistorialFecha($_GET['fecha'])
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveDolaresComitente':
            $data = bodyJson();

            if (!isset($data['fecha']) || !array_key_exists('importe_usd', $data)) {
                throw new Exception('Faltan la fecha o el importe en dólares');
            }

            $r = $otros->guardarDolaresComitente(
                $data['fecha'], $data['importe_usd'], usuarioActual());

            echo json_encode([
                'success' => true,
                // Que la carga PISO una anterior es lo que hay que decir: si
                // no, una corrección se ve igual que un alta y nadie sabe que
                // el número de esa fecha cambió.
                'message' => $r['piso']
                    ? 'Importe actualizado. La carga anterior de esa fecha queda en el historial.'
                    : 'Importe cargado.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
