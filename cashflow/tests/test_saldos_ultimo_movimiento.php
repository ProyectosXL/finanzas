<?php
/**
 * Saldos > Saldos Locales: el ultimo envio (RAF) o deposito (BDE) de cada
 * local, de RO_V_GASTOS_CAJA_SUCURSALES. Es solo informativo: no toca la
 * serie Caja Locales.
 *
 * Todo sobre helpers puros, sin base, salvo la ultima seccion, que lee la
 * vista real y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../Class/Saldos.php';

/** Una fila de la vista, como la devuelve la consulta */
function umFila($nro, $comp, $nComp, $monto, $fecha, $moneda = 'ARS') {
    return ['FECHA' => new DateTime($fecha), 'NRO_SUCURS' => $nro, 'COD_COMP' => $comp,
            'N_COMP' => ' ' . $nComp, 'MONTO' => $monto, 'MONEDA' => $moneda];
}

// ============================================================================
seccion('el comprobante sale de la gestion');
// ============================================================================

chequear('el que envia, RAF', 'RAF', Saldos::COMPROBANTE_GESTION[Saldos::ENVIA]);
chequear('el que deposita, BDE', 'BDE', Saldos::COMPROBANTE_GESTION[Saldos::DEPOSITA]);

// ============================================================================
seccion('el armado del ultimo RAF y BDE');
// ============================================================================

$mapa = Saldos::armarUltimosMovimientos([
    // 72 envia: un solo RAF en su ultima fecha
    umFila(72, 'RAF', '0000000000732', 680000, '2026-10-30'),
    // 11 envia: dos RAF el mismo dia
    umFila(11, 'RAF', '0000000000101', 3306000, '2026-02-20'),
    umFila(11, 'RAF', '0000000000102', 3306000, '2026-02-20'),
    // 3 deposita pesos por BDE y manda dolares por RAF
    umFila(3, 'BDE', '0000000000900', 3401000, '2026-10-27'),
    umFila(3, 'RAF', '0000000000491', 100, '2026-10-30', 'USD'),
    // 6 envia pesos y dolares el mismo dia
    umFila(6, 'RAF', '0000000000010', 650000, '2026-10-29'),
    umFila(6, 'RAF', '0000000000011', 200, '2026-10-29', 'USD'),
    // 8: si la consulta trajera dos fechas, vale la mas nueva
    umFila(8, 'BDE', '0000000000001', 1, '2026-10-01'),
    umFila(8, 'BDE', '0000000000002', 2, '2026-10-02'),
    umFila(8, 'BDE', '0000000000003', 3, '2026-10-01'),
    // Otro comprobante no se toma
    umFila(9, 'FAC', '0000000000001', 999, '2026-10-30')
]);

$un = Saldos::ultimoMovimiento($mapa, 72, Saldos::ENVIA);
chequear('un comprobante: su fecha', '2026-10-30', $un['fecha']);
chequear('el comprobante es COD_COMP + N_COMP, sin los espacios de Tango', 'RAF 0000000000732',
    $un['comprobantes'][0]['comprobante']);
chequear('y el total es su importe', 680000.0, $un['totales']['ARS']);

$dos = Saldos::ultimoMovimiento($mapa, 11, Saldos::ENVIA);
chequear('varios en la misma fecha: se listan todos', 2, count($dos['comprobantes']));
chequear('y se suman', 6612000.0, $dos['totales']['ARS']);

chequear('un local sin movimientos: null', null, Saldos::ultimoMovimiento($mapa, 40, Saldos::DEPOSITA));
chequear('uno que solo envia, consultado como Deposita: null (sin depositos)', null,
    Saldos::ultimoMovimiento($mapa, 72, Saldos::DEPOSITA));

$dep = Saldos::ultimoMovimiento($mapa, 3, Saldos::DEPOSITA);
$env = Saldos::ultimoMovimiento($mapa, 3, Saldos::ENVIA);
chequear('Deposita toma el ultimo BDE', ['2026-10-27', 3401000.0], [$dep['fecha'], $dep['totales']['ARS']]);
chequear('Envia toma el ultimo RAF del mismo local', ['2026-10-30', 100.0], [$env['fecha'], $env['totales']['USD']]);
chequear('el RAF en dolares no se suma a los pesos', false, isset($env['totales']['ARS']));

$mixto = Saldos::ultimoMovimiento($mapa, 6, Saldos::ENVIA);
chequear('pesos y dolares el mismo dia: un total por moneda, sin mezclar', ['ARS' => 650000.0, 'USD' => 200.0],
    $mixto['totales']);

$vieja = Saldos::ultimoMovimiento($mapa, 8, Saldos::DEPOSITA);
chequear('con dos fechas, solo la mas nueva', ['2026-10-02', 1, 2.0],
    [$vieja['fecha'], count($vieja['comprobantes']), $vieja['totales']['ARS']]);
chequear('otro comprobante no se toma', false, isset($mapa[9]));
chequear('una gestion desconocida se toma como Deposita', $dep, Saldos::ultimoMovimiento($mapa, 3, 'X'));
chequear('sin filas, un mapa vacio', [], Saldos::armarUltimosMovimientos([]));

// ============================================================================
seccion('la lectura: una consulta, solo informativa, nunca tumba la pestana');
// ============================================================================

$src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Saldos.php'));
preg_match('/public function getUltimosMovimientosLocales\(\).*?\n    }\n/s', $src, $m);
$lee = isset($m[0]) ? $m[0] : '';

chequear('lee de la vista en el servidor de locales, con su prefijo', true,
    strpos($lee, "\$this->conectar('locales')") !== false && strpos($lee, '$this->prefijoLocales()') !== false);
chequear('es una sola consulta', 1, substr_count($lee, 'sqlsrv_query('));
chequear('solo los locales propios habilitados, los que se muestran', true,
    strpos($lee, "B.CANAL = 'PROPIOS' AND B.HABILITADO = 1") !== false);
chequear('todos los de la ultima fecha de cada local y comprobante', true,
    strpos($lee, 'MAX(G.FECHA) AS FECHA') !== false && strpos($lee, 'GROUP BY G.NRO_SUCURS, G.COD_COMP') !== false
    && strpos($lee, 'AND U.FECHA = G.FECHA') !== false);
chequear('solo las columnas que se muestran', true,
    strpos($lee, 'SELECT G.FECHA, G.NRO_SUCURS, G.COD_COMP, G.N_COMP, G.MONTO, G.MONEDA') !== false
    && strpos($lee, 'SELECT *') === false);
chequear('si falla, no lanza: aviso y los iconos apagados', true,
    strpos($lee, 'catch (Throwable $e)') !== false && strpos($lee, "'leido' => false") !== false);

preg_match('/public function getPestanaLocales\(\).*?\n    }\n/s', $src, $m);
chequear('no entra en la pestana ni en la serie: va en un pedido aparte', false,
    strpos(isset($m[0]) ? $m[0] : '', 'UltimosMovimientos') !== false);
chequear('ni en el proveedor', false,
    strpos(file_get_contents(__DIR__ . '/../Class/Providers/SaldosProvider.php'), 'UltimosMovimientos') !== false);

$ctrl = file_get_contents(__DIR__ . '/../Controller/SaldosController.php');
$auth = file_get_contents(__DIR__ . '/../Class/AuthCashflow.php');
chequear('el controller la atiende', true, strpos($ctrl, "case 'getUltimosMovLocales':") !== false);
chequear('y es una accion de lectura', true,
    (bool) preg_match("/'Saldos' => \[[^\]]*'getUltimosMovLocales'/", $auth));

$js = file_get_contents(__DIR__ . '/../Js/Saldos.js');
$tab = file_get_contents(__DIR__ . '/../Tabs/saldos.php');
chequear('la pantalla la pide despues de dibujar la tabla', true,
    strpos($js, "'?action=getUltimosMovLocales'") !== false && strpos($js, 'pedirMovLocales();') !== false);
chequear('el icono sigue a la gestion de la pantalla', true, strpos($js, 'pintarIconoMov(f, deposita);') !== false);
chequear('sin movimientos dice "Sin envíos registrados" o "Sin depósitos registrados"', true,
    strpos($js, "deposita ? 'Sin depósitos registrados' : 'Sin envíos registrados'") !== false);
chequear('el modal existe y no depende del permiso de edicion', true,
    strpos($tab, 'id="modalMovLocal"') !== false
    && !preg_match('/<\?php if \(\$edita\): \?>\s*<!--[^>]*?-->\s*<div class="modal fade" id="modalMovLocal"/s', $tab));

// ============================================================================
seccion('contra la base: la vista se lee');
// ============================================================================

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

ob_start();
$real = (new Saldos())->getUltimosMovimientosLocales();
ob_end_clean();

chequear('la vista se lee', true, $real['leido']);

$bien = true;

foreach ($real['locales'] as $nro => $b) {
    foreach ($b as $comp => $mov) {
        if ($mov === null) {
            continue;
        }

        $suma = [];

        foreach ($mov['comprobantes'] as $c) {
            $suma[$c['moneda']] = (isset($suma[$c['moneda']]) ? $suma[$c['moneda']] : 0) + $c['monto'];
        }

        foreach ($suma as $mon => $t) {
            if (abs($t - $mov['totales'][$mon]) > 0.005) {
                $bien = false;
            }
        }
    }
}

chequear('cada total es la suma de sus comprobantes, por moneda', true, $bien);
