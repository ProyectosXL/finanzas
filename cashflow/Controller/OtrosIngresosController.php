<?php
/**
 * OtrosIngresosController.php
 * Controlador de la categoria Otros Ingresos. Atiende dos pestanas:
 * Dolares Cuenta Comitente y Saldo de Inversiones.
 *
 * LAS DOS TIENEN ACCIONES PROPIAS y no un parametro 'concepto', aunque el
 * circuito sea el mismo: el importe se llama distinto -importe_usd e
 * importe_ars- y eso no es cosmetico, es la moneda del dato. Una accion
 * generica con un campo 'importe' haria que el endpoint acepte un numero sin
 * moneda, y la que decide cual es quedaria escrita en el navegador.
 *
 * LA VALIDACION QUE VALE ES LA DE ACA. El formulario del navegador acota lo que
 * se puede tipear, pero lo que manda es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por la pantalla. La fecha y el importe se
 * validan de nuevo en OtrosIngresos::validarFecha() y validarImporte().
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
    require_once __DIR__ . '/../Class/OtrosIngresos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $otros = new OtrosIngresos();

    switch ($action) {
        case 'getDolaresComitente':
            /* Las filas van VALUADAS, con la cuenta abierta: cuantos dolares, a
               que cotizacion, de que fecha, y cuanto da en pesos. Es la MISMA
               cuenta que hace el proveedor para el tablero -
               OtrosIngresos::valuarDolares()-, asi que el total de esta grilla
               se ata fila por fila al de la fila del cashflow.

               Los avisos van SIEMPRE, incluso -sobre todo- cuando la grilla
               esta vacia: es la diferencia entre "falta correr el script" y
               "todavia nadie cargo nada". */
            $val = $otros->valuarDolares();
            $avisos = $otros->getAvisos();

            if ($val['error'] !== null) {
                $avisos[] = 'No se pudo leer el tipo de cambio oficial, así que la columna en '
                    . 'pesos va con un guión. Los dólares cargados están; lo que falta es a '
                    . 'cuánto valuarlos. Corré sql/RO_V_DOLAR_OFICIAL_BCRA_DIARIO.sql contra '
                    . 'la base central.';
            }

            if ($val['sin_cotizacion'] > 0) {
                $avisos[] = 'US$ ' . number_format($val['sin_cotizacion'], 2, ',', '.')
                    . ' no se pueden valuar porque no hay ninguna cotización oficial anterior '
                    . 'a su fecha. No se asume ningún tipo de cambio: tampoco entran al tablero.';
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $val['filas'],
                    'avisos' => $avisos
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialDolares':
            if (!isset($_GET['fecha'])) {
                throw new Exception('Falta la fecha del historial');
            }

            echo json_encode([
                'success' => true,
                'data' => $otros->getHistorialFecha($_GET['fecha'])
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ALTA Y EDICION SON LA MISMA ACCION, y no por ahorrar un endpoint:
           editar NO es un UPDATE. Editar un importe -o moverlo a otro día del
           cronograma- es cargar de nuevo ese día, y la versión anterior queda
           en el historial. Un endpoint 'editDolares' aparte insinuaría que hay
           un camino que modifica en el lugar, y no lo hay.

           'fecha' es la del DATO y es la que valúa. La grilla manda la que la
           fila ya tenía: si mandara hoy, editar el importe le cambiaría también
           la cotización y el número se movería por algo que nadie pidió.

           'fecha_cronograma' es opcional. Sin ella se usa 'fecha', que es lo
           correcto en un alta desde el formulario: quien carga sin elegir
           cronograma quiere ver el importe el día del dato. */
        case 'saveDolaresComitente':
            $data = bodyJson();

            if (!isset($data['fecha']) || !array_key_exists('importe_usd', $data)) {
                throw new Exception('Faltan la fecha o el importe en dólares');
            }

            $r = $otros->guardarDolaresComitente(
                $data['fecha'], $data['importe_usd'], usuarioActual(),
                isset($data['fecha_cronograma']) ? $data['fecha_cronograma'] : null,
                isset($data['cronograma_anterior']) ? $data['cronograma_anterior'] : null);

            /* Los tres mensajes dicen tres cosas distintas, y las tres importan:
               una corrección que se ve igual que un alta deja a nadie sabiendo
               que el número de ese día cambió. El que más pesa es el del medio:
               mover una fila a un día que ya tenía importe PISA el que estaba, y
               eso el usuario no lo pidió explícitamente. */
            if ($r['movio'] !== null) {
                $mensaje = 'Importe movido del ' . $r['movio'] . ' al ' . $r['fecha_cronograma'] . '.'
                    . ($r['piso']
                        ? ' Ese día ya tenía un importe: queda en el historial.'
                        : '');
            } elseif ($r['piso']) {
                $mensaje = 'Importe actualizado. La carga anterior del ' . $r['fecha_cronograma']
                    . ' queda en el historial.';
            } else {
                $mensaje = 'Importe cargado.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           SALDO DE INVERSIONES

           Mismo circuito que los dolares -grilla de vigentes, historial por
           fecha y una carga que pisa sin borrar-, pero EN PESOS: el campo es
           importe_ars y no hay conversion. Ver
           sql/cashflow_saldo_inversiones.sql.
           ================================================================ */

        case 'getSaldoInversiones':
            // Los avisos van SIEMPRE, y sobre todo con la grilla vacia: es la
            // diferencia entre "falta correr el script" y "nadie cargo nada".
            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $otros->getSaldoInversiones(),
                    'avisos' => $otros->getAvisosInversiones()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialInversiones':
            if (!isset($_GET['fecha'])) {
                throw new Exception('Falta la fecha del historial');
            }

            echo json_encode([
                'success' => true,
                'data' => $otros->getHistorialInversiones($_GET['fecha'])
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveSaldoInversiones':
            $data = bodyJson();

            if (!isset($data['fecha']) || !array_key_exists('importe_ars', $data)) {
                throw new Exception('Faltan la fecha o el importe en pesos');
            }

            $r = $otros->guardarSaldoInversiones(
                $data['fecha'], $data['importe_ars'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['piso']
                    ? 'Saldo actualizado. La carga anterior de esa fecha queda en el historial.'
                    : 'Saldo cargado.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
