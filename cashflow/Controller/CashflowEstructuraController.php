<?php
/**
 * CashflowEstructuraController.php
 * Administración de la estructura del tablero de Cashflow.
 *
 * Va aparte de CashflowController a propósito: ahí vive la LECTURA del tablero
 * (el cálculo) y acá la CONFIGURACIÓN. Es la separación que pide el módulo, y
 * además sigue el patrón de la casa, que es un controller por clase
 * (VentasController con Ventas, ComexController con Comex) y no uno por
 * pantalla.
 *
 * Tampoco se cuelga de ParametrosController, aunque la pantalla viva en la
 * pestaña Parámetros: eso obligaría a que Parametros.php requiera
 * CashflowEstructura.php, invirtiendo la dependencia, y una lectura que fallara
 * se llevaría puesta toda la pestaña, incluida la de Ventas.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/**
 * Usuario para la auditoría. Todavía no hay login: devuelve NULL y se graba
 * NULL. Cuando exista, sólo hay que poblar $_SESSION['usuario'].
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/CashflowEstructura.php';
    require_once __DIR__ . '/../Class/CashflowRegistry.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $estructura = new CashflowEstructura();

    switch ($action) {

        /**
         * Todo lo que necesita el editor en una sola llamada: la estructura
         * completa (incluidas las filas inhabilitadas), los orígenes de datos
         * disponibles y los avisos.
         */
        case 'getEstructura':
            // getEstructura() y no getSecciones()+getFilas() sueltos: ordena las
            // filas por posicion de seccion y despues por orden dentro de ella.
            // Leidas sueltas vienen ordenadas por ORDEN global, y como ORDEN es
            // por seccion, las secciones aparecerian entremezcladas.
            $conf = $estructura->getEstructura(false);
            $secciones = $conf['secciones'];
            $filas = $conf['filas'];

            echo json_encode([
                'success' => true,
                'data' => [
                    'secciones' => $secciones,
                    'filas' => $filas,
                    'providers' => CashflowRegistry::todos(),
                    'tipos' => CashflowEstructura::TIPOS,
                    'roles' => CashflowEstructura::ROLES,
                    'tipos_derivados' => CashflowEstructura::TIPOS_DERIVADOS,
                    'tipos_con_origen' => CashflowEstructura::TIPOS_CON_ORIGEN,
                    'validacion' => CashflowEstructura::validar($secciones, $filas),
                    'avisos' => $estructura->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveEstructura':
            $data = bodyJson();

            if (!isset($data['secciones']) || !isset($data['filas'])) {
                throw new Exception('Faltan las secciones o las filas en el request');
            }

            $val = $estructura->guardar($data['secciones'], $data['filas'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Estructura guardada',
                'data' => ['validacion' => $val]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addFila':
            $data = bodyJson();

            $nuevo = $estructura->addFila(
                isset($data['nombre']) ? $data['nombre'] : '',
                isset($data['seccion']) ? $data['seccion'] : '',
                isset($data['tipo']) ? $data['tipo'] : '',
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'data' => $nuevo,
                'message' => 'Fila agregada con el código ' . $nuevo['codigo'] . '. '
                    . 'Queda inhabilitada: elegí el origen de datos y activala para guardar.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addSeccion':
            $data = bodyJson();

            $codigo = $estructura->addSeccion(
                isset($data['nombre']) ? $data['nombre'] : '',
                isset($data['rol']) ? $data['rol'] : '',
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'data' => ['codigo' => $codigo],
                'message' => 'Sección agregada con el código ' . $codigo . '. '
                    . 'Queda inhabilitada: agregale filas y activala para guardar.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
