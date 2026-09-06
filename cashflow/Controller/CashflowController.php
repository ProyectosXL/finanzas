<?php
/**
 * CashflowController.php
 * Endpoint del tablero de Cashflow.
 *
 * Antes este archivo era una clase base con helpers de conexion y formato que
 * NADIE requeria ni extendia: codigo muerto. Se reemplaza por un script con
 * switch($action), que es la forma que tienen todos los controllers de este
 * modulo (VentasController, ComexController, ParametrosController), y deja el
 * par Class/Cashflow.php <-> Controller/CashflowController.php alineado con
 * Ventas y Comex.
 *
 * Solo lectura. La administracion de la estructura vive en
 * CashflowEstructuraController, para que la configuracion y el calculo queden
 * separados.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);   // que un warning de PHP no ensucie el JSON

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/Cashflow.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $cashflow = new Cashflow();

    switch ($action) {

        case 'getTablero':
            echo json_encode([
                'success' => true,
                'data' => $cashflow->proyectar()
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
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
