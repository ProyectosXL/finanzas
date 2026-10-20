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

    /* Quien escribe y si puede, antes de tocar nada: null en las acciones
       que solo leen. Ver Controller/autorizacion.php. */
    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Cashflow', $action);

    $cashflow = new Cashflow();

    switch ($action) {

        case 'getTablero':
            echo json_encode([
                'success' => true,
                'data' => $cashflow->proyectar()
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* LOS AVISOS DEL TABLERO DE UNA PESTANA, calculados con el mismo
           codigo del motor (Cashflow::avisosDePestana()). La pestana los pide en
           paralelo con sus datos y los junta con los suyos.

           LECTURA DE ESA PESTANA, no del tablero: quien ve Proveedores
           Exterior tiene que poder leer lo que el tablero dice de ella aunque
           no pueda abrir el tablero. La accion esta en LECTURAS -no pide nada
           por si sola- y el permiso lo pide autorizarLectura() con la pestana
           ya validada contra el registro.

           Y LIBERA LA SESION apenas autoriza: ver AuthCashflow::liberarSesion(). */
        case 'getAvisosPestana':
            $tab = isset($_GET['tab']) ? trim((string) $_GET['tab']) : '';

            if (!in_array($tab, CashflowRegistry::tabsConProveedor(), true)) {
                throw new Exception('La pestaña "' . $tab . '" no tiene avisos del tablero.');
            }

            autorizarLectura($tab);
            AuthCashflow::liberarSesion();

            echo json_encode([
                'success' => true,
                'data' => [
                    'tab' => $tab,
                    'avisos' => $cashflow->avisosDePestana($tab)
                ]
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
