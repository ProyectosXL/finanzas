<?php
/**
 * PrestamosController
 * Los endpoints de la pestaña Financiero › Prestamos y de Parametros ›
 * Prestamos.
 *
 * UN SOLO CONTROLLER PARA LAS DOS PANTALLAS, como Llaves: los parametros que
 * se editan en una son los que la otra copia al dar de alta un prestamo.
 *
 * QUIEN PUEDE QUE lo dice el mapa de AuthCashflow (ESCRITURAS / LECTURAS), no
 * este archivo: autorizar() va antes del switch, y una accion que no esta en el
 * mapa se rechaza. LA VALIDACION MANDA ACA (PrestamosParametros): lo que vino
 * del navegador es un pedido, no un hecho.
 */

header('Content-Type: application/json; charset=utf-8');

function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

function responder($data, $mensaje = null) {
    $r = ['success' => true, 'data' => $data];

    if ($mensaje !== null) {
        $r['message'] = $mensaje;
    }

    echo json_encode($r, JSON_UNESCAPED_UNICODE);
}

try {
    require_once __DIR__ . '/../Class/Prestamos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Prestamos', $action);

    $pr = new Prestamos();
    $par = $pr->par();

    switch ($action) {
        /* ================================================================
           PARAMETROS -> PRESTAMOS
           ================================================================ */
        case 'getParametros':
            autorizarLectura('parametros');
            responder($pr->parametros());
            break;

        case 'guardarGeneral':
            $data = bodyJson();
            $r = $par->guardarGeneral($data['clave'] ?? '', $data['valor'] ?? null, $usuario);
            responder($r, PrestamosParametros::GENERALES[$r['clave']]['nombre'] . ': guardado. Vale para los préstamos que se den de alta desde ahora.');
            break;

        case 'guardarCosto':
            $data = bodyJson();
            $r = $par->guardarCostoParam($data, $usuario);
            responder($r, 'Costo «' . $r['nombre'] . '» guardado. Vale para los préstamos que se den de alta desde ahora.');
            break;

        case 'guardarTramos':
            $data = bodyJson();
            $r = $par->guardarTramosParam($data['tramos'] ?? [], $usuario);
            responder($r, 'Tramos guardados (' . count($r) . '). Valen para los préstamos que se den de alta desde ahora.');
            break;

        case 'guardarTasaManual':
            $data = bodyJson();
            $r = $par->guardarTasaManual($data['referencia'] ?? '', $data['mes'] ?? '', $data['valor'] ?? null, $usuario);
            responder($r, ($r['valor'] === null ? 'Valor manual quitado' : 'Valor manual guardado') . ' para '
                . PrestamosTasas::NOMBRES[$r['referencia']] . ' en ' . $r['mes'] . '.');
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (InvalidArgumentException $e) {
    /* Una validacion: el mensaje es para el usuario, tal cual. */
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
