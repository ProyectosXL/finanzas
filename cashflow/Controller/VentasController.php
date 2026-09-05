<?php
/**
 * VentasController.php
 * Controlador para la proyeccion de ventas y cobranzas
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
 * que poblar $_SESSION['usuario'] y toda la trazabilidad queda enchufada.
 * @return string|null Usuario actual
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

/**
 * Lee y decodifica el body JSON del request
 * @return array Datos del POST
 */
function bodyJson() {
    $postData = file_get_contents('php://input');
    $data = json_decode($postData, true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Ventas.php';

    // Obtener accion del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $ventas = new Ventas();

    switch ($action) {
        case 'getProyeccionVentas':
            echo json_encode([
                'success' => true,
                'data' => $ventas->proyectarVentas()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getProyeccionCobranzas':
            echo json_encode([
                'success' => true,
                'data' => $ventas->proyectarCobranzas()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getAnalisisVentas':
            // El historico arranca en 2025 (primera carga del SP)
            $anioDesde = isset($_GET['anioDesde']) ? intval($_GET['anioDesde']) : 2025;

            echo json_encode([
                'success' => true,
                'data' => $ventas->getAnalisisVentas($anioDesde)
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveParticipacion':
            $data = bodyJson();

            if (!isset($data['tipo']) || !isset($data['anio']) ||
                !isset($data['mes']) || !isset($data['valores'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $ventas->saveParticipacion(
                $data['tipo'],
                $data['anio'],
                $data['mes'],
                $data['valores'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Participacion guardada correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getParametros':
            $grupo = isset($_GET['grupo']) ? $_GET['grupo'] : null;

            echo json_encode([
                'success' => true,
                'data' => $ventas->getParametros($grupo)
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveIndice':
            $data = bodyJson();

            if (!isset($data['anio']) || !isset($data['mes']) || !isset($data['indice'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $ventas->saveIndice(
                $data['anio'],
                $data['mes'],
                $data['indice'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Indice guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMixCobro':
            $data = bodyJson();

            if (!isset($data['id']) || !isset($data['porcentaje']) ||
                !isset($data['dias_acreditacion'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $ventas->saveMixCobro(
                $data['id'],
                $data['porcentaje'],
                $data['dias_acreditacion'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Mix de cobro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveParametro':
            $data = bodyJson();

            if (!isset($data['clave']) || !isset($data['valor'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $ventas->saveParametro(
                $data['clave'],
                $data['valor'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Parametro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getNeteoPrechequeado':
            // Circuito cableado y apagado: devuelve cero hasta que exista la
            // vista origen de los echeqs adelantados.
            echo json_encode([
                'success' => true,
                'data' => $ventas->getNeteoPrechequeado()
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
