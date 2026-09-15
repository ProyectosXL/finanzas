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
 * LA VALIDACION QUE VALE ES LA DE ACA. El input del navegador acota lo que se
 * puede tipear, pero lo que manda es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por la pantalla. La fecha, el importe y el
 * origen se validan de nuevo en Cobertura::validarFecha(), validarImporte() y
 * validarOrigen().
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
                    'filas' => $cobertura->getAplicaciones(),
                    'origenes' => Cobertura::ORIGENES,
                    'avisos' => $cobertura->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialCobertura':
            if (!isset($_GET['fecha'])) {
                throw new Exception('Falta la fecha del historial');
            }

            echo json_encode([
                'success' => true,
                'data' => $cobertura->getHistorial($_GET['fecha'])
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveAplicacion':
            $data = bodyJson();

            if (!isset($data['fecha']) || !array_key_exists('importe', $data)) {
                throw new Exception('Faltan la fecha o el importe de la cobertura');
            }

            $r = $cobertura->guardar(
                $data['fecha'],
                $data['importe'],
                isset($data['origen']) ? $data['origen'] : null,
                isset($data['observacion']) ? $data['observacion'] : null,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => $r['piso']
                    ? 'Cobertura actualizada. La carga anterior de esa fecha queda en el historial.'
                    : 'Cobertura aplicada.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* No borra la fila: la da de baja. Ver Cobertura::borrar(). */
        case 'deleteAplicacion':
            $data = bodyJson();

            if (!isset($data['fecha'])) {
                throw new Exception('Falta la fecha de la cobertura a dar de baja');
            }

            $habia = $cobertura->borrar($data['fecha']);

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'Cobertura dada de baja. Queda en el historial de esa fecha.'
                    : 'Esa fecha no tenía ninguna cobertura aplicada.',
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
