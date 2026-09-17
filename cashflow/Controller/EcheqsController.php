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

            // DOS FECHAS, DOS FUNCIONES DISTINTAS:
            //
            //   FECHA_VENTA_EST  decide QUE cheques se muestran
            //   FECHA_CHEQUE     decide DONDE cae cada importe en el eje
            //
            // Cada cheque se ubica en la fecha del CHEQUE, que es cuando entra
            // la plata. Antes se ubicaba en la fecha estimada de venta, porque
            // el criterio era caer donde esta la cobranza proyectada de esa
            // venta; ese razonamiento quedo superado. La fecha estimada sigue
            // siendo columna visible: es lo que explica por que ese cheque esta
            // en la lista.
            //
            // ESTA LINEA Y Ventas::repartirNeteo() SE MUEVEN JUNTAS. El modulo
            // esta construido para que la pantalla y el neteo no se puedan
            // desalinear: si solo cambiara una, el usuario tildaria un cheque
            // en una columna y el tablero lo restaria en otra.
            //
            // Los cheques cuya fecha ESTIMADA cae antes de hoy no llegan hasta
            // aca: los descarta Echeqs::cruzarPrechequeado() con
            // Echeqs::ventaYaCobrada(), la misma funcion que usa el neteo. Esa
            // venta ya se facturo y ya se cobro: esta fuera del cashflow, y la
            // leyenda de la sub-pestana lo dice.
            //
            // Lo POSTERIOR al horizonte si llega y si se avisa en 'descartes':
            // ese cheque esta en la tabla y su importe no tiene columna. El
            // neteo informa el mismo importe en 'fuera_horizonte', asi que las
            // dos pantallas dicen lo mismo.
            $payload = EjeVista::armar(
                Horizonte::desdeParametros(new Parametros()),
                $filas,
                'FECHA_CHEQUE',
                'IMPORTE'
            );

            // Los avisos van SIEMPRE, incluso -sobre todo- cuando el listado
            // esta vacio: es la diferencia entre "no hay clientes configurados"
            // y "esta pantalla no anda".
            $payload['resumen'] = Echeqs::resumenPrechequeado($filas);
            $payload['avisos'] = $echeqs->getAvisos();

            echo json_encode([
                'success' => true,
                'data' => $payload
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
