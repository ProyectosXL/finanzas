<?php
/**
 * LlavesController
 * Los endpoints de Parametros -> Llaves y Renovaciones y de la pestaña RRHH y
 * Operativos -> Llaves y Renov. Contratos.
 *
 * UN SOLO CONTROLLER PARA LAS DOS PANTALLAS, como Alquileres: los parametros
 * que se editan en una son los que la otra usa para proyectar, y dos endpoints
 * escribiendo las mismas tablas se desincronizan en la primera validacion que
 * alguien agregue de un solo lado. "Ya pagada" se marca desde las dos (la
 * solapa Contratos de la pestaña y Parametros), con el permiso de cualquiera.
 *
 * QUIEN PUEDE QUE lo dice el mapa de AuthCashflow (ESCRITURAS / LECTURAS), no
 * este archivo: autorizar() va antes del switch, y una accion que no esta en el
 * mapa se rechaza. LA VALIDACION MANDA ACA (LlavesParametros), contra la app de
 * ahora: lo que vino del navegador es un pedido, no un hecho.
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
    require_once __DIR__ . '/../Class/Llaves.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Llaves', $action);

    $par = new LlavesParametros();

    switch ($action) {
        /* ================================================================
           PARAMETROS -> LLAVES Y RENOVACIONES
           ================================================================ */
        case 'getParametros':
            autorizarLectura('parametros');
            responder((new Llaves())->parametros());
            break;

        case 'guardarGeneral':
            $data = bodyJson();
            $r = $par->guardarGeneral($data['clave'] ?? '', $data['valor'] ?? null, $usuario);
            responder($r, LlavesParametros::GENERALES[$r['clave']]['nombre'] . ': '
                . ($r['valor'] === null ? 'vacío' : number_format($r['valor'], 2, ',', '.')) . '.');
            break;

        case 'guardarOperador':
            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $r = $par->guardarOperador($data['cod_provee'] ?? '', $data, $ctx['razones'], $usuario);
            responder($r, 'Operador ' . ($ctx['razones'][$r['cod']]['razon'] ?? $r['cod']) . ' guardado.');
            break;

        case 'guardarLocal':
            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $nro = (int) ($data['nro'] ?? 0);
            $r = $par->guardarLocal($nro, $data['id_contrato'] ?? null, $data, $ctx['contratos'][$nro] ?? [], $usuario);
            responder($r, ($ctx['locales'][$nro] ?? 'Local') . ': ' . ($r['no_renueva']
                ? 'no renueva, no se estima nada.' : 'guardado.'));
            break;

        case 'altaPagada':
            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $nro = (int) ($data['nro'] ?? 0);
            $r = $par->altaPagada($nro, $data['id_contrato_ant'] ?? null, $data, $ctx['contratos'][$nro] ?? [],
                date('Y-m-d'), $usuario);
            responder($r, LlavesParametros::CONCEPTOS_PAGADA[$r['concepto']] . ' de ' . ($ctx['locales'][$nro] ?? 'el local')
                . ' marcada como ya pagada: ese concepto deja de proyectarse en esa renovación.');
            break;

        case 'bajaPagada':
            $data = bodyJson();
            $habia = $par->baja(LlavesParametros::T_PAGADA, $data['id'] ?? 0, $usuario);
            responder(['habia' => $habia], $habia ? 'Marca de pagada dada de baja: lo proyectado vuelve. Queda en el historial.'
                : 'Esa marca ya no estaba vigente.');
            break;

        case 'altaObra':
            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $r = $par->altaObra($data, $ctx['locales'], $usuario);
            responder($r, 'Obra de imagen de ' . $ctx['locales'][$r['nro']] . ' cargada para ' . $r['mes'] . '.');
            break;

        case 'bajaObra':
            $data = bodyJson();
            $habia = $par->baja(LlavesParametros::T_OBRA, $data['id'] ?? 0, $usuario);
            responder(['habia' => $habia], $habia ? 'Obra dada de baja. Queda en el historial.' : 'Esa obra ya no estaba vigente.');
            break;

        case 'altaApertura':
            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $r = $par->altaApertura($data, $ctx['locales'], $usuario);
            responder($r, 'Apertura «' . $r['nombre'] . '» cargada para ' . $r['mes'] . '.');
            break;

        case 'bajaApertura':
            $data = bodyJson();
            $habia = $par->baja(LlavesParametros::T_APERTURA, $data['id'] ?? 0, $usuario);
            responder(['habia' => $habia], $habia ? 'Apertura dada de baja. Queda en el historial.' : 'Esa apertura ya no estaba vigente.');
            break;

        case 'altaCompromiso':
            require_once __DIR__ . '/../Class/ProveedoresCategorias.php';

            $data = bodyJson();
            $ctx = (new Llaves())->contextoEscritura();
            $r = $par->altaCompromiso($data, $ctx['razones'], (new ProveedoresCategorias())->mapa(), $ctx['locales'], $usuario);
            responder($r, $r['n'] > 1 ? 'Compromiso cargado en ' . $r['n'] . ' cuotas mensuales.' : 'Compromiso cargado.');
            break;

        case 'bajaCompromiso':
            $data = bodyJson();
            $n = $par->bajaCompromiso($data['id'] ?? 0, !empty($data['serie']), $usuario);
            responder(['n' => $n], $n > 1 ? $n . ' cuotas dadas de baja. Quedan en el historial.'
                : ($n === 1 ? 'Compromiso dado de baja. Queda en el historial.' : 'Ese compromiso ya no estaba vigente.'));
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
