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

/** El horizonte del tablero: las escrituras de Facturas revalidan con el mismo */
function horizonteAlq() {
    require_once __DIR__ . '/../Class/Parametros.php';
    require_once __DIR__ . '/../Class/Horizonte.php';

    return Horizonte::desdeParametros(new Parametros());
}

try {
    require_once __DIR__ . '/../Class/Alquileres.php';
    require_once __DIR__ . '/../Class/AlquileresImputaciones.php';
    require_once __DIR__ . '/../Class/AlquileresFacturaVto.php';
    require_once __DIR__ . '/../Class/AlquileresTiposRetencion.php';
    require_once __DIR__ . '/../Class/AlquileresUniverso.php';
    require_once __DIR__ . '/../Class/Proveedores.php';

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
            $alq = new Alquileres($h->hoy());
            $c = $alq->calcular($h, $alq->insumos($h));

            /* LAS QUE TANGO YA PAGO SE MARCAN SOLAS, para cualquiera que pueda
               ver la pestaña y con un usuario fijo, como la conciliacion de
               Proveedores Locales: no es una decision de nadie, es copiar lo que
               dice Tango. El calculo no depende de la marca (ya la trata como
               pagada); la marca deja la fecha. Si falla, la pestaña carga igual. */
            if (!empty($c['pagadas_nuevas'])) {
                try {
                    (new AlquileresImputaciones())->marcarPagadas($c['pagadas_nuevas']);
                } catch (Throwable $e) {
                    $c['avisos'][] = Aviso::nuevo(Aviso::WARNING, 'No se pudo marcar como pagadas las facturas que '
                        . 'Tango ya canceló (' . $e->getMessage() . '). Se toman igual como pagadas y se '
                        . 'reintenta en la próxima carga.', 'Facturas');
                }
            }

            responder(Alquileres::armarPestana($c, $h));
            break;

        /* ================================================================
           LA SUB-PESTAÑA FACTURAS

           Toda escritura revalida contra los pendientes de AHORA
           (Alquileres::contextoImputacion()): lo que vino del navegador es un
           pedido, no un hecho.
           ================================================================ */
        case 'editarVtoFactura':
            $data = bodyJson();
            $hoy = date('Y-m-d');
            $h = horizonteAlq();
            $e = (new Alquileres($h->hoy()))->contextoImputacion($h);
            $cuota = ['COD_PROVEE' => $data['cod_provee'] ?? '', 'T_COMP' => $data['t_comp'] ?? '',
                      'N_COMP' => $data['n_comp'] ?? '', 'FECHA_VTO' => $data['vto_tango'] ?? ''];

            if (!isset($e['pendientes'][AlquileresImputacion::claveCuota($cuota)])
                || !empty($e['pendientes'][AlquileresImputacion::claveCuota($cuota)]['PAGADA_TANGO'])) {
                throw new InvalidArgumentException('Esa factura ya no está pendiente en Tango, o no es de alquiler: '
                    . 'volvé a cargar la pestaña. No se guardó nada.');
            }

            /* Elegir la fecha que la cuota tendria sin editar (la de Parametros)
               es deshacer. */
            $base = AlquileresImputacion::origenVto($cuota, [], $e['dias']) === AlquileresImputacion::VTO_PARAMETRO
                ? 'de Parámetros' : 'de Tango';
            $r = (new AlquileresFacturaVto())->guardar($cuota['COD_PROVEE'], $cuota['T_COMP'], $cuota['N_COMP'],
                $cuota['FECHA_VTO'], $data['fecha'] ?? '', $data['motivo'] ?? null, $usuario, $hoy,
                AlquileresImputacion::vtoDeParametro($cuota, $e['dias']));
            responder($r, $r['deshecha'] ? 'Vuelve al vencimiento ' . $base . '.'
                : 'Vencimiento a considerar: ' . date('d/m/Y', strtotime($r['fecha'])) . '.');
            break;

        case 'deshacerVtoFactura':
            $data = bodyJson();
            $r = (new AlquileresFacturaVto())->deshacer($data['cod_provee'] ?? '', $data['t_comp'] ?? '',
                $data['n_comp'] ?? '', $data['vto_tango'] ?? '', $usuario);
            responder($r, $r['habia'] ? 'Vuelve al vencimiento sin editar (el de Parámetros de la razón social). '
                . 'El cambio queda en el historial.' : 'Ya estaba con el vencimiento sin editar.');
            break;

        /* LA PREVISUALIZACION Y EL GUARDADO SON LA MISMA VALIDACION
           (AlquileresImputacion::validar()), con lo que hay en Tango ahora. Sin
           el script 60 no se imputa: se dice cual falta. */
        case 'previsualizarImputacion':
            $h = horizonteAlq();
            $e = (new Alquileres($h->hoy()))->contextoImputacion($h);
            responder(AlquileresImputacion::validar(bodyJson(), $e));
            break;

        case 'guardarImputacion':
            $data = bodyJson();
            $h = horizonteAlq();
            $e = (new Alquileres($h->hoy()))->contextoImputacion($h);

            if (empty($e['tabla_imputacion'])) {
                throw new InvalidArgumentException((new AlquileresImputaciones())->avisoSinTabla());
            }

            $v = AlquileresImputacion::validar($data, $e);

            if (!$v['ok']) {
                throw new InvalidArgumentException('No se guardó: ' . implode(' ', $v['errores']));
            }

            $r = (new AlquileresImputaciones())->guardar($v, $data['observacion'] ?? null, $usuario);
            responder($r, 'Imputación #' . $r['id'] . ' guardada: ' . $v['cod_provee'] . ', '
                . AlquileresImputacion::mesLargo($v['mes']) . ', a pagar $ '
                . number_format($v['total_a_pagar'], 2, ',', '.') . '.');
            break;

        case 'deshacerImputacion':
            $data = bodyJson();
            $habia = (new AlquileresImputaciones())->deshacer((int) ($data['id'] ?? 0), $usuario);
            responder(['habia' => $habia], $habia
                ? 'Imputación deshecha: sus facturas vuelven a Sin imputar y la proyección que reemplazaba '
                  . 'vuelve. Queda en el historial; para corregirla, armala de nuevo.'
                : 'Esa imputación ya no estaba vigente.');
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

            /* NO SE DA DE BAJA CON IMPUTACIONES DE FACTURAS TODAVIA PENDIENTES:
               sus facturas volverian a Proveedores Locales con la imputacion
               contada aca. El mensaje lista cuales deshacer. Las de facturas ya
               pagadas no frenan. Los pendientes son de TODO Tango: la condicion
               de seguridad no importa para saber si una factura sigue debiendose. */
            $imps = new AlquileresImputaciones();

            if ($imps->tablaCreada()) {
                $pendClaves = [];

                foreach ((new Proveedores())->getPendientes(date('Y-m-d')) as $p) {
                    $pendClaves[AlquileresImputacion::claveCuota($p)] = true;
                }

                $bloquean = AlquileresImputacion::bloqueanBaja($data['cod_provee'] ?? '', $imps->vigentes(), $pendClaves);

                if (!empty($bloquean)) {
                    throw new InvalidArgumentException(AlquileresImputacion::mensajeBajaBloqueada(
                        Planilla::codigo($data['cod_provee'] ?? ''), $bloquean));
                }
            }

            $r = $par->bajaRazon($data['cod_provee'] ?? '', $usuario);
            AlquileresUniverso::olvidar();
            responder($r, 'La razón social ' . $r['cod'] . ' quedó dada de baja. No se borra: queda en el '
                . 'historial, y sus locales pasan a Incompletos.');
            break;

        case 'guardarLocal':
            $data = bodyJson();
            $universo = (new AlquileresApp())->locales();
            $r = $par->guardarLocal($data['nro'] ?? 0, $data, $universo, $usuario);
            responder($r, 'Local ' . $universo[$r['nro']]['nombre'] . ' guardado.');
            break;

        /* MAS DE UNA RAZON SOCIAL POR LOCAL: las adicionales, y los conceptos
           que factura cada una. Cada concepto se paga con el dia y el
           diferimiento de la razon que lo factura. */
        case 'altaRazonLocal':
            $data = bodyJson();
            $universo = (new AlquileresApp())->locales();
            $r = $par->altaRazonLocal($data['nro'] ?? 0, $data['cod_provee'] ?? '', $universo, $usuario);
            responder($r, $r['cod'] . ' agregada a ' . $universo[$r['nro']]['nombre'] . ': sus facturas se pueden '
                . 'imputar a este local. Todavía no factura ningún concepto: marcale los suyos para que salgan de la '
                . 'principal.');
            break;

        case 'guardarConceptosRazon':
            $data = bodyJson();
            $universo = (new AlquileresApp())->locales();
            $r = $par->guardarConceptosRazon($data['nro'] ?? 0, $data['cod_provee'] ?? '', $data['conceptos'] ?? [],
                                             $universo, $usuario);
            responder($r, empty($r['conceptos'])
                ? $r['cod'] . ' ya no factura ningún concepto de ' . $universo[$r['nro']]['nombre'] . ': todos vuelven a la principal.'
                : $r['cod'] . ' factura ' . implode(', ', $r['conceptos']) . ' en ' . $universo[$r['nro']]['nombre']
                  . ': se pagan con su día de vencimiento y su diferimiento.');
            break;

        case 'bajaRazonLocal':
            $data = bodyJson();
            $r = $par->bajaRazonLocal($data['nro'] ?? 0, $data['cod_provee'] ?? '', $usuario);
            responder($r, $r['cod'] . ' ya no es razón social de ese local. No se borra: queda en el historial.');
            break;

        /* LOS TIPOS DE RETENCION de las imputaciones. Renombrar es baja y alta:
           una imputacion vieja sigue mostrando el nombre con el que se cargo. */
        case 'altaTipoRetencion':
            $data = bodyJson();
            $r = (new AlquileresTiposRetencion())->alta($data['nombre'] ?? '', $usuario);
            responder($r, 'Tipo de retención «' . $r['nombre'] . '» dado de alta: ya se puede elegir al imputar.');
            break;

        case 'renombrarTipoRetencion':
            $data = bodyJson();
            $r = (new AlquileresTiposRetencion())->renombrar($data['id'] ?? 0, $data['nombre'] ?? '', $usuario);
            responder($r, $r['igual'] ? 'El nombre no cambió.' : 'Tipo de retención renombrado a «' . $r['nombre']
                . '». Las imputaciones que ya lo usan siguen mostrando el nombre anterior.');
            break;

        case 'bajaTipoRetencion':
            $data = bodyJson();
            $r = (new AlquileresTiposRetencion())->baja($data['id'] ?? 0, $usuario);
            responder($r, 'Tipo de retención dado de baja: ya no se ofrece para imputar. Las imputaciones que lo '
                . 'usan lo siguen mostrando, y queda en el historial.');
            break;

        case 'altaExtra':
            $data = bodyJson();
            $cod = trim((string) ($data['cod_provee'] ?? ''));
            $deLaRazon = [];

            /* Los locales de la razon social: los que la tienen como principal o
               como adicional. */
            foreach ($par->locales() as $nro => $l) {
                if (in_array(Planilla::codigo($cod), AlquileresPago::razonesDelLocal($l), true)) {
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
