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

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $ingresos = new Ingresos();

    switch ($action) {
        case 'getCobranzasFR':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;
            $datos = $ingresos->getCobranzasFR($summary);
            $resultado = $ingresos->procesarCobranzasPorPeriodo($datos);
            echo json_encode([
                'success' => true,
                'data' => $resultado
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
