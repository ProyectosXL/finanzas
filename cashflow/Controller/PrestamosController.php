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

    /* El prestamo como esta guardado, para las escrituras que lo necesitan. */
    $actual = function ($id) use ($par) {
        $todos = $par->prestamos();

        return $todos[(int) $id] ?? null;
    };

    switch ($action) {
        /* ================================================================
           LA PESTAÑA: lista, cronograma, proyeccion y tarjetas, en un pedido
           ================================================================ */
        case 'getPestana':
            autorizarLectura('prestamos');

            require_once __DIR__ . '/../Class/Parametros.php';
            require_once __DIR__ . '/../Class/Horizonte.php';

            responder($pr->pestana(Horizonte::desdeParametros(new Parametros())));
            break;

        case 'altaPrestamo':
            $data = bodyJson();
            $r = $par->altaPrestamo($data, $pr->cuentas(), $usuario);
            responder(['id' => $r['id']], 'Préstamo «' . $r['nombre'] . '» dado de alta como '
                . strtolower(PrestamosCalculo::NOMBRE_ESTADO[$r['estado']]) . '.'
                . ($r['estado'] === PrestamosCalculo::SIMULADO ? ' No suma en el tablero oficial.' : ''));
            break;

        case 'editarPrestamo':
            $data = bodyJson();
            $id = (int) ($data['id'] ?? 0);
            $a = $actual($id);
            $data['estado'] = $a['prestamo']['estado'] ?? null;
            $r = $par->editarPrestamo($id, $data, $pr->cuentas(), $a, $usuario);
            responder(['id' => $r['id']], 'Préstamo «' . $r['nombre'] . '» guardado.');
            break;

        case 'pasarAVigente':
            $data = bodyJson();
            $id = (int) ($data['id'] ?? 0);
            $a = $actual($id);

            if ($a === null || $a['prestamo']['estado'] !== PrestamosCalculo::SIMULADO) {
                throw new InvalidArgumentException('Solo un préstamo simulado pasa a vigente.');
            }

            $data['estado'] = PrestamosCalculo::VIGENTE;
            $r = $par->editarPrestamo($id, $data, $pr->cuentas(), $a, $usuario);
            responder(['id' => $r['id']], '«' . $r['nombre'] . '» pasó a vigente: desde ahora suma en el tablero.');
            break;

        case 'eliminarSimulado':
            $data = bodyJson();
            $id = (int) ($data['id'] ?? 0);
            $habia = $par->eliminarSimulado($id, $actual($id), $usuario);
            responder(['habia' => $habia], $habia ? 'Préstamo simulado eliminado. Queda en el historial.' : 'Ese préstamo ya no estaba.');
            break;

        case 'guardarAjuste':
            $data = bodyJson();
            $id = (int) ($data['id'] ?? 0);
            $r = $par->guardarAjustes($id, $data['cuota'] ?? null, $data['ajustes'] ?? [], $actual($id), $usuario);
            responder($r, 'Cuota ' . (int) $data['cuota'] . ' corregida (' . count($r) . ' dato' . (count($r) === 1 ? '' : 's')
                . '). Pisa lo calculado; se puede deshacer.');
            break;

        case 'deshacerAjuste':
            $data = bodyJson();
            $n = $par->deshacerAjuste((int) ($data['id'] ?? 0), $data['cuota'] ?? null, $data['concepto'] ?? '', $usuario);
            responder(['n' => $n], $n > 0 ? 'Corrección deshecha: vuelve lo calculado. Queda en el historial.' : 'No había corrección vigente.');
            break;

        case 'simularCancelacion':
            $data = bodyJson();
            responder($pr->simularCancelacion((int) ($data['id'] ?? 0), $data));
            break;

        case 'registrarCancelacion':
            $data = bodyJson();
            $id = (int) ($data['id'] ?? 0);
            $s = $pr->simularCancelacion($id, $data);
            $par->registrarCancelacion($id, $s['validada'], $usuario);
            responder($s, 'Cancelación ' . strtolower(PrestamosCalculo::nombreCancel($s['validada']['tipo'])) . ' registrada para el '
                . PrestamosCalculo::corta($s['validada']['fecha']) . '.');
            break;

        case 'deshacerCancelacion':
            $data = bodyJson();
            $habia = $par->deshacerCancelacion($data['id_cancelacion'] ?? 0, $pr->hoy(), $usuario);
            responder(['habia' => $habia], $habia ? 'Cancelación deshecha: vuelven las cuotas. Queda en el historial.' : 'Esa cancelación ya no estaba vigente.');
            break;

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
