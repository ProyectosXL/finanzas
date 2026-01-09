<?php
/**
 * ComexController.php
 * Controlador para operaciones de Comercio Exterior (COMEX)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/Comex.php';

    // Obtener acción del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $comex = new Comex();

    switch ($action) {
        case 'getProveedoresExterior':
            $datos = $comex->getProveedoresExterior();
            $resultado = $comex->procesarDatosPorPeriodo($datos);
            echo json_encode([
                'success' => true,
                'data' => $resultado
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'getProveedoresExteriorRaw':
            // Solo los datos crudos sin procesamiento
            $datos = $comex->getProveedoresExterior();
            echo json_encode([
                'success' => true,
                'data' => $datos
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
