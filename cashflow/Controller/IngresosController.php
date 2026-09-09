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

    switch ($action) {
        case 'getCobranzasFR':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;

            // El eje sale de horizonte_dias y horizonte_meses, el mismo del
            // tablero: esta pestaña deja de tener su ventana propia -eran los
            // dias del mes en curso y doce meses fijos-.
            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    Horizonte::desdeParametros(new Parametros()),
                    $ingresos->getCobranzasFR($summary),
                    'Cobro',
                    'importe_neto'
                )
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
