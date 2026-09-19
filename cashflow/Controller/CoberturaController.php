<?php
/**
 * CoberturaController.php
 * Endpoints de la seccion Cobertura del tablero: cuanta plata invertida se
 * aplica en cada fecha para tapar un bache del flujo.
 *
 * SE LLAMA DESDE EL TABLERO, NO DESDE UNA PESTANA PROPIA. La fila se edita
 * celda por celda en la grilla del Cashflow, que es donde se ven los saldos
 * negativos; un editor en otra pantalla obligaria a ir y volver comparando
 * columnas. Ver Js/Cashflow.js.
 *
 * LO QUE SE GUARDA ES LO MANUAL. El uso de cobertura lo calcula el motor en
 * cada carga del tablero (ver Class/CoberturaAutomatica.php); lo que entra por
 * aca es lo que alguien decide pisar en una fecha y un fondo, y tiene
 * precedencia. Por eso guardar y borrar van por FECHA + ORIGEN: cada fila de
 * uso del cuadro aplica un fondo distinto, y el mismo dia puede llevar una
 * carga desde cada uno.
 *
 * LA VALIDACION QUE VALE ES LA DE ACA. El input del navegador acota lo que se
 * puede tipear, pero lo que manda es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por la pantalla. La fecha, el importe y el
 * origen se validan de nuevo en Cobertura::validarFecha(), validarImporte() y
 * validarOrigen(), y el importe contra lo disponible en el fondo a esa fecha
 * en Cobertura::validarDisponible(): una carga que lo supere se rechaza con el
 * numero, no se recorta.
 *
 * MISMO CONTRATO QUE LOS OTROS CONTROLLERS DEL MODULO: siempre JSON, nunca una
 * excepcion suelta, y el mensaje distingue si la carga PISO una anterior. Sin
 * eso, una correccion se ve igual que un alta y nadie se entera de que el
 * numero de esa fecha cambio.
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

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Cobertura.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $cobertura = new Cobertura();

    switch ($action) {
        /* Las aplicaciones vigentes y los origenes declarados. Los avisos van
           SIEMPRE, y sobre todo con la grilla vacia: es la diferencia entre
           "falta correr el script" y "todavia nadie aplico nada". */
        case 'getAplicaciones':
            echo json_encode([
                'success' => true,
                'data' => [
                    /* Las filas van VALUADAS: una aplicación en dólares trae
                       además con qué cotización y de qué día se convirtió. Es la
                       MISMA cuenta que hace el proveedor del tablero, así que la
                       pantalla y el cuadro no pueden discrepar. */
                    'filas' => $cobertura->valuarAplicaciones()['filas'],
                    /* Los origenes son las cuentas de fondo del catalogo, con
                       su moneda y si estan activas: ya no hay una lista fija
                       en el codigo. Ver Cobertura::origenes(). */
                    'origenes' => $cobertura->origenes(),
                    'origen_defecto' => Cobertura::origenDefectoDe($cobertura->origenes()),
                    'en_dolares' => $cobertura->tieneMoneda(),
                    'avisos' => $cobertura->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* El historial de una celda: fecha Y fondo. Sin 'origen' trae toda
           la fecha, que es lo que traia antes de que hubiera una fila por
           fondo. */
        case 'getHistorialCobertura':
            if (!isset($_GET['fecha'])) {
                throw new Exception('Falta la fecha del historial');
            }

            echo json_encode([
                'success' => true,
                'data' => $cobertura->getHistorial($_GET['fecha'],
                    isset($_GET['origen']) ? $_GET['origen'] : null)
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveAplicacion':
            $data = bodyJson();

            if (!isset($data['fecha']) || !array_key_exists('importe', $data)) {
                throw new Exception('Faltan la fecha o el importe de la cobertura');
            }

            /* LA MONEDA NO VIAJA: la decide el ORIGEN. Aplicar del fondo de
               dolares es aplicar dolares, y recibirla suelta permitiria guardar
               un importe en dolares diciendo que sale de inversiones. */
            $r = $cobertura->guardar(
                $data['fecha'],
                $data['importe'],
                isset($data['origen']) ? $data['origen'] : null,
                isset($data['observacion']) ? $data['observacion'] : null,
                usuarioActual()
            );

            $cuanto = ($r['moneda'] === 'USD')
                ? 'US$ ' . number_format($r['importe'], 2, ',', '.')
                : '$ ' . number_format($r['importe'], 2, ',', '.');

            echo json_encode([
                'success' => true,
                'message' => ($r['piso']
                    ? 'Cobertura manual actualizada a ' . $cuanto . '. La carga anterior de esa '
                        . 'fecha y ese fondo queda en el historial.'
                    : 'Cobertura manual de ' . $cuanto . ' cargada.')
                    . ($r['moneda'] === 'USD'
                        ? ' Se valúa con la cotización del día en que se aplica.' : '')
                    . ' El motor recalcula lo automático sobre lo que quede.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* No borra la fila: la da de baja. Ver Cobertura::borrar(). Lo que
           queda en la celda es lo que calcule el motor. */
        case 'deleteAplicacion':
            $data = bodyJson();

            if (!isset($data['fecha'])) {
                throw new Exception('Falta la fecha de la cobertura a dar de baja');
            }

            $habia = $cobertura->borrar($data['fecha'],
                isset($data['origen']) ? $data['origen'] : null);

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'Cobertura manual dada de baja. Queda en el historial; la celda vuelve a '
                        . 'lo que calcule el motor.'
                    : 'Esa fecha no tenía ninguna cobertura manual de ese fondo.',
                'data' => ['habia' => $habia]
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
