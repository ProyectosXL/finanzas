<?php
/**
 * CobElectronicosController.php
 * Controlador de la pestana Cob. Electronicos: acreditaciones de las
 * procesadoras de pago.
 *
 * NI EL NETO NI LA TASA SE ACEPTAN DEL CLIENTE. Del navegador vienen la
 * procesadora, el importe bruto, la fecha de acreditacion y las observaciones;
 * la tasa y el neto los resuelve Class/CobElectronicos.php con las alicuotas
 * vigentes. Aceptarlos permitiria guardar cualquier numero como si fuera el
 * calculado, que es la version informatica del *0.969 escrito a mano en el
 * Excel.
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
    require_once __DIR__ . '/../Class/CobElectronicos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $modulo = new CobElectronicos();

    switch ($action) {
        case 'getPestana':
            // Los filtros van por GET porque son parte de lo que se esta
            // mirando: asi la pantalla se puede recargar con el mismo recorte.
            echo json_encode([
                'success' => true,
                'data' => $modulo->getPestana([
                    'id_procesadora' => isset($_GET['id_procesadora'])
                        ? intval($_GET['id_procesadora']) : 0,
                    'desde' => isset($_GET['desde']) ? $_GET['desde'] : null,
                    'hasta' => isset($_GET['hasta']) ? $_GET['hasta'] : null
                ])
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addMovimiento':
            $data = bodyJson();

            if (!isset($data['id_procesadora']) || !isset($data['importe_bruto'])
                || !isset($data['fecha_acreditacion'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $modulo->addMovimiento(
                $data['id_procesadora'],
                $data['importe_bruto'],
                $data['fecha_acreditacion'],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            // El mensaje dice con que tasa se calculo: es el dato que el usuario
            // no tipeo y que define el importe que va a ver en el tablero.
            $mensaje = 'Movimiento guardado. Importe neto $ '
                . number_format($r['neto'], 2, ',', '.') . ', calculado con una retención del '
                . number_format($r['tasa'] * 100, 4, ',', '.') . '%.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMovimiento':
            $data = bodyJson();

            if (!isset($data['id']) || !isset($data['importe_bruto'])
                || !isset($data['fecha_acreditacion'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // La tasa se vuelve a resolver aunque solo haya cambiado el bruto:
            // se resuelve contra la fecha de acreditacion, y si esa fecha se
            // movio, la tasa que corresponde puede ser otra.
            $r = $modulo->saveMovimiento(
                $data['id'],
                $data['importe_bruto'],
                $data['fecha_acreditacion'],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            $mensaje = 'Movimiento actualizado. Importe neto $ '
                . number_format($r['neto'], 2, ',', '.') . ', recalculado con una retención del '
                . number_format($r['tasa'] * 100, 4, ',', '.') . '%.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaMovimiento':
            $data = bodyJson();

            if (!isset($data['id'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // Baja LOGICA: la fila queda, deja de sumar al tablero.
            $modulo->bajaMovimiento($data['id'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Movimiento dado de baja. No se borró: queda inhabilitado y sale '
                           . 'del tablero.'
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
