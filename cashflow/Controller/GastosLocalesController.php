<?php
/**
 * GastosLocalesController.php
 * Endpoints de Proveedores -> Gastos Locales. SOLO LEE.
 *
 * La pestana no escribe nada: el dia de pago se cambia en el cronograma de
 * Parametros -> Generales y la lista de cuentas excluidas en la tarjeta Gastos
 * Locales de la misma sub-pestana, con el permiso de Generales. Por eso las dos
 * acciones de aca estan en AuthCashflow::LECTURAS y no hay clave
 * cashflow.editar.gastos_locales.
 *
 * Lo que si se exige es poder VER la pestana: los comprobantes traen leyendas
 * y usuarios de la caja de cada local.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/GastosLocales.php';
    require_once __DIR__ . '/../Class/Parametros.php';
    require_once __DIR__ . '/../Class/Horizonte.php';
    require_once __DIR__ . '/../Class/EjeVista.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    require_once __DIR__ . '/autorizacion.php';
    autorizar('GastosLocales', $action);
    autorizarLectura('gastos_locales');

    $gl = new GastosLocales();

    switch ($action) {
        /* ================================================================
           LA GRILLA

           Una fila por local, con importes en VARIAS columnas: las semanas
           del tramo y los meses. Es armarAgrupado(), como Logistica Local y
           los Resumen de Cobranzas. Las partidas entran con su fecha y el eje
           las ubica con la misma regla que el tablero, asi que la pestana y
           la fila no pueden dar distinto.
           ================================================================ */
        case 'getPlanilla':
            $h = Horizonte::desdeParametros(new Parametros());
            $datos = $gl->planilla($h);

            $partidas = array_values(array_filter($datos['partidas'], function ($p) {
                return $p['importe'] !== null;
            }));

            $payload = EjeVista::armarAgrupado($h, $partidas, 'nro', 'fecha', 'importe');

            /* Cada celda con su explicacion: de que dias sale, que factor de
               inflacion lleva. Indexado por local y columna, para que el front
               no tenga que repetir la regla del eje. */
            $tooltips = [];

            foreach ($datos['partidas'] as $p) {
                $col = $h->columna($p['fecha']);

                if ($col !== null) {
                    $tooltips[$p['nro']][$col][] = $p['tooltip'];
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'eje' => $payload,
                    'leido' => $datos['leido'],
                    'ventana' => $datos['ventana'],
                    'locales' => array_values(array_map(function ($l) {
                        unset($l['partidas']);

                        return $l;
                    }, $datos['locales'])),
                    'tooltips' => $tooltips,
                    'semanas' => $datos['semanas'],
                    'resumen' => $datos['resumen'],
                    'mes_base' => $datos['mes_base'],
                    'cuentas_excluidas' => $datos['cuentas_excluidas']['texto'],
                    'cronograma_config' => $datos['cronograma_config'],
                    'sin_egresos' => GastosLocalesCalculo::SIN_EGRESOS,
                    'hoy' => $h->hoy(),
                    'avisos' => $datos['avisos']
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Los comprobantes de un local, a pedido: la leyenda, el usuario y el
           numero no hacen falta para el calculo. */
        case 'getComprobantes':
            echo json_encode([
                'success' => true,
                'data' => $gl->comprobantes(isset($_GET['nro']) ? $_GET['nro'] : '')
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('Acción no válida');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
