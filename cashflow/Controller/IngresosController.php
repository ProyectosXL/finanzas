<?php
/**
 * IngresosController.php
 * Controlador para operaciones de Ingresos (Cobranzas FR, etc)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/Ingresos.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $ingresos = new Ingresos();

    /** El cuerpo JSON de un POST */
    $bodyJson = function () {
        $crudo = file_get_contents('php://input');
        $data = json_decode($crudo, true);

        return is_array($data) ? $data : [];
    };

    /** Usuario de sesion. Todavia no hay login: hoy graba NULL. */
    $usuarioActual = function () {
        return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
    };

    switch ($action) {
        case 'getCobranzasFR':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;
            $origen = isset($_GET['origen']) ? $_GET['origen'] : 'todos';

            // El eje sale de horizonte_dias y horizonte_meses, el mismo del
            // tablero: esta pestaña deja de tener su ventana propia -eran los
            // dias del mes en curso y doce meses fijos-.
            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    Horizonte::desdeParametros(new Parametros()),
                    $ingresos->getCobranzasFR($summary, $origen),
                    'Cobro',
                    'importe_neto'
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getCobranzasMay':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;

            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    Horizonte::desdeParametros(new Parametros()),
                    $ingresos->getCobranzasMay($summary),
                    'Cobro',
                    'importe_neto'
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           FECHA DE COBRO MANUAL POR COMPROBANTE

           La validacion de la fecha se hace ACA de nuevo, aunque el input del
           navegador lleve `min` en el dia de hoy: lo que manda el navegador es
           un pedido, no una autorizacion. Vive en
           Ingresos::validarFechaCobroManual().
           ================================================================ */

        case 'saveFechaCobroManual':
            $data = $bodyJson();

            foreach (['t_comp', 'n_comp', 'fecha_cobro'] as $campo) {
                if (!isset($data[$campo]) || $data[$campo] === '') {
                    throw new Exception('Falta el campo ' . $campo
                        . ' para guardar la fecha de cobro.');
                }
            }

            $fecha = $ingresos->saveFechaManualFR(
                isset($data['cod_cliente']) ? $data['cod_cliente'] : '',
                $data['t_comp'],
                $data['n_comp'],
                $data['fecha_cobro'],
                $usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Fecha de cobro guardada.',
                'data' => ['fecha_cobro' => $fecha]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deleteFechaCobroManual':
            $data = $bodyJson();

            if (empty($data['t_comp']) || empty($data['n_comp'])) {
                throw new Exception('Falta el comprobante cuya fecha manual hay que borrar.');
            }

            $ingresos->deleteFechaManualFR($data['t_comp'], $data['n_comp']);

            echo json_encode([
                'success' => true,
                'message' => 'La fecha vuelve a calcularse con el PPP del cliente.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida: ' . $action
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
        'message' => 'Error fatal: ' . $e->getMessage()
    ]);
}
