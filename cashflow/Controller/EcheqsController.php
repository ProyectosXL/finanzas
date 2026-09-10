<?php
/**
 * EcheqsController.php
 * Controlador de la pestana Echeqs: cheques de terceros en cartera y venta
 * cobrada anticipada.
 *
 * LA LISTA DE IDS QUE MANDA EL NAVEGADOR NO ES UNA AUTORIZACION. El universo de
 * cheques marcables lo vuelve a resolver Echeqs::marcarCheques() contra el
 * maestro; un id de un cliente que no esta cargado se rechaza. Aceptarlo
 * guardaria una marca que despues no se ve en ninguna pantalla y que igual
 * netearia la cobranza de Ventas.
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
 * @return string|null Usuario actual
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
    require_once __DIR__ . '/../Class/Echeqs.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $echeqs = new Echeqs();

    switch ($action) {
        case 'getEcheqsCartera':
            // El eje sale de horizonte_dias y horizonte_meses, el mismo del
            // tablero: los importes por columna vienen ya resueltos y el
            // navegador no calcula ninguna fecha.
            $payload = EjeVista::armar(
                Horizonte::desdeParametros(new Parametros()),
                $echeqs->getEcheqsCartera(),
                'FECHA_PAGO',
                'IMPORTE'
            );

            echo json_encode([
                'success' => true,
                'data' => $payload
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getEcheqsPrechequeado':
            $filas = $echeqs->getEcheqsPrechequeado();

            // Los avisos van SIEMPRE, incluso -sobre todo- cuando el listado
            // esta vacio: es la diferencia entre "no hay clientes configurados"
            // y "esta pantalla no anda".
            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $filas,
                    'resumen' => Echeqs::resumenPrechequeado($filas),
                    'avisos' => $echeqs->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'marcarCheques':
            $data = bodyJson();

            if (!isset($data['ids']) || !is_array($data['ids'])
                || !array_key_exists('marcado', $data)) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $echeqs->marcarCheques(
                $data['ids'],
                !empty($data['marcado']),
                usuarioActual()
            );

            $mensaje = ($data['marcado'] ? 'Se tildaron ' : 'Se destildaron ')
                . $r['tocados'] . ' cheque(s).';

            if (!empty($r['rechazados'])) {
                $mensaje .= ' ' . count($r['rechazados']) . ' quedaron afuera porque ya no están '
                    . 'en el listado: actualizá la pantalla para ver cómo quedó.';
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
