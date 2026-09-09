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
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    // Obtener acción del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $comex = new Comex();

    /**
     * Eje temporal de las pestañas, el mismo del tablero.
     *
     * Sale de horizonte_dias y horizonte_meses, así que estas pestañas dejan de
     * tener su ventana propia -eran los días del mes en curso y doce meses
     * fijos- y muestran exactamente el período que se está proyectando.
     *
     * @return Horizonte
     */
    function ejeDelModulo() {
        static $h = null;

        if ($h === null) {
            $h = Horizonte::desdeParametros(new Parametros());
        }

        return $h;
    }

    switch ($action) {
        case 'getProveedoresExterior':
            // Los importes de esta pestaña están en DÓLARES y así se muestran:
            // no hay conversión acá. La conversión a pesos la hace
            // ComexProvider, que es quien alimenta el tablero.
            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    ejeDelModulo(),
                    $comex->getProveedoresExterior(),
                    'FECHA_PAGO_EFECTIVA',
                    'VALOR_FOB_DOLAR'
                )
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
            
        case 'updateFechaPago':
            // Recibir datos del POST
            $postData = file_get_contents('php://input');
            $data = json_decode($postData, true);
            
            if (!isset($data['id_mg']) || !isset($data['fecha_pago_orig']) || !isset($data['fecha_pago_edit'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }
            
            $result = $comex->updateFechaPago(
                $data['id_mg'],
                $data['fecha_pago_orig'],
                $data['fecha_pago_edit']
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Fecha actualizada correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'getCronoNacionalizacion':
            // IMPORTE_EST viene de un LEFT JOIN sobre la estimación, así que
            // puede ser nulo: esos casos suman cero y no distorsionan.
            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    ejeDelModulo(),
                    $comex->getCronoNacionalizacion(),
                    'FECHA_NAC_EFECTIVA',
                    'IMPORTE_EST'
                )
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'updateFechaNacPago':
            // Recibir datos del POST
            $postData = file_get_contents('php://input');
            $data = json_decode($postData, true);
            
            if (!isset($data['id_mg']) || !isset($data['fecha_nac_orig']) || !isset($data['fecha_nac_edit'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }
            
            $result = $comex->updateFechaNacPago(
                $data['id_mg'],
                $data['fecha_nac_orig'],
                $data['fecha_nac_edit']
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Fecha actualizada correctamente'
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
