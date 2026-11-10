<?php
/**
 * AlquileresController
 * Los endpoints de Parametros -> Alquileres y de la pestaña RRHH y Operativos ->
 * Alquileres.
 *
 * UN SOLO CONTROLLER PARA LAS DOS PANTALLAS, como Logistica y Tarjetas: los
 * parametros que se editan en una son los que la otra usa para proyectar, y dos
 * endpoints escribiendo las mismas tablas se desincronizan en la primera
 * validacion que alguien agregue de un solo lado.
 *
 * QUIEN PUEDE QUE lo dice el mapa de AuthCashflow (ESCRITURAS / LECTURAS), no
 * este archivo: autorizar() va antes del switch, y una accion que no esta en el
 * mapa se rechaza.
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
    require_once __DIR__ . '/../Class/Alquileres.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Alquileres', $action);

    $par = new AlquileresParametros();

    switch ($action) {
        /* ================================================================
           LA PESTAÑA: PROYECCION Y VENTAS

           Un solo pedido para las dos sub-pestañas: tienen que describir el
           mismo estado, y la Proyeccion ya calcula la venta que muestra Ventas.
           ================================================================ */
        case 'getProyeccion':
            autorizarLectura('alquileres');

            require_once __DIR__ . '/../Class/Parametros.php';
            require_once __DIR__ . '/../Class/Horizonte.php';

            $h = Horizonte::desdeParametros(new Parametros());
            responder((new Alquileres($h->hoy()))->proyeccion($h));
            break;

        /* ================================================================
           PARAMETROS -> ALQUILERES
           ================================================================ */
        case 'getParametros':
            autorizarLectura('parametros');
            responder((new Alquileres())->parametros());
            break;

        case 'guardarGeneral':
            $data = bodyJson();
            $r = $par->guardarGeneral($data['dia'] ?? null, $usuario);
            responder($r, 'Día de vencimiento general: ' . $r['dia'] . '.');
            break;

        case 'altaRazon':
            require_once __DIR__ . '/../Class/ProveedoresCategorias.php';

            $data = bodyJson();
            $universo = AlquileresParametros::universoDelMaestro((new ProveedoresCategorias())->mapa());
            $r = $par->altaRazon($data['cod_provee'] ?? '', $universo, $usuario);
            responder($r, $r['nuevo']
                ? $universo[$r['cod']]['nombre'] . ' quedó dada de alta como razón social de alquiler.'
                : $universo[$r['cod']]['nombre'] . ' ya estaba dada de alta.');
            break;

        case 'guardarRazon':
            $data = bodyJson();
            $r = $par->guardarRazon($data['cod_provee'] ?? '', $data['dia'] ?? null,
                                    $data['diferimiento'] ?? null, $usuario);
            responder($r, 'Razón social ' . $r['cod'] . ' guardada.');
            break;

        case 'bajaRazon':
            $data = bodyJson();
            $r = $par->bajaRazon($data['cod_provee'] ?? '', $usuario);
            responder($r, 'La razón social ' . $r['cod'] . ' quedó dada de baja. No se borra: queda en el '
                . 'historial, y sus locales pasan a Incompletos.');
            break;

        case 'guardarLocal':
            $data = bodyJson();
            $universo = (new AlquileresApp())->locales();
            $r = $par->guardarLocal($data['nro'] ?? 0, $data, $universo, $usuario);
            responder($r, 'Local ' . $universo[$r['nro']]['nombre'] . ' guardado.');
            break;

        case 'altaExtra':
            $data = bodyJson();
            $cod = trim((string) ($data['cod_provee'] ?? ''));
            $deLaRazon = [];

            foreach ($par->locales() as $nro => $l) {
                if ($l['cod_provee'] === $cod) {
                    $deLaRazon[] = $nro;
                }
            }

            $r = $par->altaExtra($data, $deLaRazon, $usuario);
            responder($r, 'Pago extraordinario dado de alta: se repite todos los años hasta que se dé de baja.');
            break;

        case 'bajaExtra':
            $data = bodyJson();
            $r = $par->bajaExtra($data['id'] ?? 0, $usuario);
            responder($r, 'El pago extraordinario quedó dado de baja. No se borra: queda en el historial.');
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
