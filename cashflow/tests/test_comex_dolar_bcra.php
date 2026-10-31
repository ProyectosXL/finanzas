<?php
/**
 * Comex - el dolar BCRA para los pagos de los proximos dias, y el aviso de la
 * curva ROFEX desactualizada.
 *
 * Desde feature/comex-dolar-bcra-proximos-pagos. Lo que se prueba es la regla
 * entera, que es pura y vive en DolarFuturo: que cotizacion le toca a cada
 * pago segun cuando se paga, que pasa cuando falta la cotizacion BCRA o es
 * vieja, y cuando la curva no esta al dia contando solo dias habiles.
 *
 * TODO SIN BASE. La curva, la cotizacion BCRA y el calendario se pasan como
 * argumento. La lectura -bcra(), ultimoHabil()- es un SELECT y no se prueba
 * aca.
 */

require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Cotizacion.php';
require_once __DIR__ . '/../Class/CronogramaPagos.php';

/** La curva de juguete: octubre y noviembre de 2026 */
function curvaBcraPrueba() {
    return [
        '2026-10' => ['clave' => '2026-10', 'simbolo' => 'DLR/OCT26', 'cotizacion' => 1537.3,
                      'anio' => 2026, 'mes' => 10, 'actualizacion' => '2026-10-30 08:00:00'],
        '2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26', 'cotizacion' => 1570.0,
                      'anio' => 2026, 'mes' => 11, 'actualizacion' => '2026-10-30 08:00:00']
    ];
}

/** La ultima BCRA vendedora, como la devuelve Cotizacion::ultimaHasta() */
function bcraPrueba($fecha = '2026-10-28', $valor = 1480.0) {
    return ['fecha' => $fecha, 'valor' => $valor, 'punta' => Cotizacion::VENDEDOR];
}

$curva = curvaBcraPrueba();
$hoy = '2026-10-31';

/* ================================================================
   LA REGLA: hoy + N va a BCRA, despues a la curva
   ================================================================ */
seccion('los pagos de los proximos N dias van a dolar BCRA vendedor');

$regla = DolarFuturo::reglaBcra($hoy, 7, 3, bcraPrueba());

chequear('N = 7 desde el 31/10: hasta el 07/11', '2026-11-07', $regla['hasta']);

$r = DolarFuturo::resolver($curva, $hoy, null, $regla);
chequear('pago hoy: BCRA', DolarFuturo::ORIGEN_BCRA, $r['origen']);
chequear('pago hoy: la cotizacion BCRA, no la de la curva', 1480.0, $r['cotizacion']);
chequear('pago hoy: viaja la fecha de la cotizacion', '2026-10-28', $r['bcra_fecha']);

$r = DolarFuturo::resolver($curva, '2026-11-07', null, $regla);
chequear('pago el dia N: BCRA', DolarFuturo::ORIGEN_BCRA, $r['origen']);
chequear('pago el dia N: la de BCRA', 1480.0, $r['cotizacion']);

$r = DolarFuturo::resolver($curva, '2026-11-08', null, $regla);
chequear('pago el dia N + 1: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);
chequear('pago el dia N + 1: la de noviembre', 1570.0, $r['cotizacion']);
chequear('pago el dia N + 1: sin fecha de BCRA', null, $r['bcra_fecha']);

/* El limite cruza el mes: el 01/11 es noviembre en la curva, pero se paga
   dentro de los 7 dias. Manda la fecha, no el mes. */
$r = DolarFuturo::resolver($curva, '2026-11-01', null, $regla);
chequear('dentro de N aunque sea otro mes de la curva: BCRA', DolarFuturo::ORIGEN_BCRA,
    $r['origen']);

seccion('lo vencido tambien va a BCRA: si no se pago, se paga ya');

$r = DolarFuturo::resolver($curva, '2026-10-02', null, $regla);
chequear('vencido: BCRA', DolarFuturo::ORIGEN_BCRA, $r['origen']);
chequear('vencido: la de BCRA, no la de octubre', 1480.0, $r['cotizacion']);

seccion('sin fecha no hay valuacion, con o sin regla');

$r = DolarFuturo::resolver($curva, null, null, $regla);
chequear('sin fecha: null', null, $r['cotizacion']);
chequear('sin fecha: el motivo es la fecha, no el BCRA', DolarFuturo::SIN_FECHA, $r['motivo']);

$r = DolarFuturo::resolver($curva, null, '1600', $regla);
chequear('sin fecha y con override: igual null', null, $r['cotizacion']);

seccion('el override gana siempre, tambien sobre BCRA');

$r = DolarFuturo::resolver($curva, $hoy, '1600', $regla);
chequear('pago hoy con override: OVERRIDE', DolarFuturo::ORIGEN_OVERRIDE, $r['origen']);
chequear('pago hoy con override: el valor cargado', 1600.0, $r['cotizacion']);

$r = DolarFuturo::resolver($curva, '2026-10-02', '1600', $regla);
chequear('vencido con override: OVERRIDE', DolarFuturo::ORIGEN_OVERRIDE, $r['origen']);

seccion('con N = 0 nadie usa BCRA');

$cero = DolarFuturo::reglaBcra($hoy, 0, 3, bcraPrueba());
chequear('N = 0: no hay ventana', null, $cero['hasta']);

$r = DolarFuturo::resolver($curva, $hoy, null, $cero);
chequear('N = 0, pago hoy: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);
chequear('N = 0, pago hoy: la de octubre', 1537.3, $r['cotizacion']);

$r = DolarFuturo::resolver($curva, '2026-10-02', null, $cero);
chequear('N = 0, vencido: curva, como antes', DolarFuturo::ORIGEN_CURVA, $r['origen']);

seccion('sin regla, resolver() hace lo de antes');

$r = DolarFuturo::resolver($curva, $hoy, null);
chequear('sin regla, pago hoy: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);

/* ================================================================
   SIN BCRA, Y BCRA VIEJA
   ================================================================ */
seccion('sin cotizacion BCRA: null, nunca la curva en silencio');

$sin = DolarFuturo::reglaBcra($hoy, 7, 3, null, 'la vista no existe');

$r = DolarFuturo::resolver($curva, $hoy, null, $sin);
chequear('pago hoy sin BCRA: null', null, $r['cotizacion']);
chequear('pago hoy sin BCRA: el motivo lo dice', DolarFuturo::SIN_BCRA, $r['motivo']);
chequear('pago hoy sin BCRA: sin origen', null, $r['origen']);

$r = DolarFuturo::resolver($curva, '2026-11-20', null, $sin);
chequear('pago despues de N sin BCRA: la curva, que no depende del BCRA',
    DolarFuturo::ORIGEN_CURVA, $r['origen']);

$fila = Comex::valuar(['PENDIENTE_USD' => 1000, 'FECHA_PAGO_EFECTIVA' => $hoy], $curva,
    'PENDIENTE_USD', 'FECHA_PAGO_EFECTIVA', $sin);
chequear('valuar sin BCRA: importe en pesos null, no cero', null, $fila['IMPORTE_ARS']);
chequear('valuar sin BCRA: el tooltip lo dice', true,
    strpos($fila['COTIZ_DETALLE'], 'No se usa la curva en su lugar') !== false);

$avisos = Comex::avisosValuacionConNivel([$fila], '2026-11', 'PENDIENTE_USD');
chequear('sin BCRA: un aviso critico', Aviso::DANGER, $avisos[0]['nivel']);
chequear('sin BCRA: dice cuantos y cuanto en dolares', true,
    strpos($avisos[0]['texto'], '1 contenedor(es) por U$S 1.000,00') !== false);
chequear('sin BCRA: no los confunde con los que no tienen fecha', 1, count($avisos));

seccion('una cotizacion BCRA de hace mas de M dias se usa y se avisa');

$vieja = DolarFuturo::reglaBcra($hoy, 7, 3, bcraPrueba('2026-10-26', 1550.0));

$r = DolarFuturo::resolver($curva, $hoy, null, $vieja);
chequear('vieja: se usa igual', 1550.0, $r['cotizacion']);
chequear('vieja (5 dias, M = 3): es vieja', true, DolarFuturo::bcraVieja($vieja));

$aviso = DolarFuturo::avisoBcraVieja($vieja);
chequear('vieja: aviso de atencion', Aviso::WARNING, $aviso['nivel']);
chequear('vieja: el texto de la especificacion',
    'La última cotización del dólar BCRA es del 26/10/2026; se usa ésa para los pagos de los '
    . 'próximos 7 días.', $aviso['texto']);

chequear('3 dias con M = 3: todavia no es vieja', false,
    DolarFuturo::bcraVieja(DolarFuturo::reglaBcra($hoy, 7, 3, bcraPrueba('2026-10-28'))));
chequear('4 dias con M = 3: ya es vieja', true,
    DolarFuturo::bcraVieja(DolarFuturo::reglaBcra($hoy, 7, 3, bcraPrueba('2026-10-27'))));
chequear('con N = 0 no se avisa: nadie la usa', null,
    DolarFuturo::avisoBcraVieja(DolarFuturo::reglaBcra($hoy, 0, 3, bcraPrueba('2026-10-01'))));
chequear('sin BCRA no se avisa de vieja: eso es otro aviso', false, DolarFuturo::bcraVieja($sin));

/* ================================================================
   LA PUNTA ES VENDEDORA
   ================================================================ */
seccion('la punta es la vendedora, y se dice');

$r = DolarFuturo::resolver($curva, $hoy, null, $regla);
chequear('la punta que viaja es la vendedora', Cotizacion::VENDEDOR, $r['bcra_punta']);
chequear('el tooltip arranca con el dolar, su fecha y su punta', 0,
    strpos(DolarFuturo::explicar($r), 'Dólar BCRA vendedor del 28/10/2026: $ 1.480,00'));

/* ================================================================
   LOS TEXTOS DE LA CURVA
   ================================================================ */
seccion('la curva se nombra con su mes');

chequear('Nov-26', 'Nov-26', DolarFuturo::mesCorto('2026-11'));
chequear('Ene-27', 'Ene-27', DolarFuturo::mesCorto('2027-01'));

$r = DolarFuturo::resolver($curva, '2026-11-20', null, $regla);
chequear('tooltip de la curva', 'Dólar futuro ROFEX de Nov-26 (DLR/NOV26).',
    DolarFuturo::explicar($r));

/* ================================================================
   LOS PARAMETROS
   ================================================================ */
seccion('los parametros se normalizan al leer y se validan al guardar');

chequear('N sin valor: 7', 7, DolarFuturo::diasBcra(null));
chequear('N basura: 7', 7, DolarFuturo::diasBcra('siete'));
chequear('N = 0 vale', 0, DolarFuturo::diasBcra('0'));
chequear('N = 15 vale', 15, DolarFuturo::diasBcra('15'));
chequear('M sin valor: 3', 3, DolarFuturo::antiguedadBcra(''));

chequear('guardar N = 15', '15', DolarFuturo::validarParametro(DolarFuturo::PARAM_DIAS_BCRA, '15'));
chequear('guardar N = 0', '0', DolarFuturo::validarParametro(DolarFuturo::PARAM_DIAS_BCRA, ' 0 '));
chequearLanza('guardar N negativo: no', function () {
    DolarFuturo::validarParametro(DolarFuturo::PARAM_DIAS_BCRA, '-1');
});
chequearLanza('guardar N con decimales: no', function () {
    DolarFuturo::validarParametro(DolarFuturo::PARAM_DIAS_BCRA, '7,5');
});
chequearLanza('guardar N por encima del tope: no', function () {
    DolarFuturo::validarParametro(DolarFuturo::PARAM_DIAS_BCRA, '61');
});
chequearLanza('guardar M vacio: no, y el mensaje nombra el campo', function () {
    DolarFuturo::validarParametro(DolarFuturo::PARAM_ANTIGUEDAD_BCRA, '');
}, '"Antigüedad máxima del dólar BCRA" tiene que ser un número entero de días entre 0 y 30.');

/* ================================================================
   LA CURVA DESACTUALIZADA, CONTANDO SOLO DIAS HABILES
   ================================================================ */
seccion('la curva esta al dia si es del ultimo dia habil');

/* Octubre/noviembre 2026: el viernes 30/10 es habil, el lunes 02/11 es
   feriado en este calendario de juguete. */
$cal = [
    '2026-10-29' => true,  '2026-10-30' => true, '2026-10-31' => false,
    '2026-11-01' => false, '2026-11-02' => false, '2026-11-03' => true
];

$delViernes = '2026-10-30 08:00:00';

foreach (['2026-10-31' => 'sabado', '2026-11-01' => 'domingo', '2026-11-02' => 'lunes feriado']
         as $dia => $nombre) {
    $habil = CronogramaPagos::habilAnterior($dia, $cal)['fecha'];

    chequear('actualizada el viernes, hoy ' . $nombre . ': no avisa', null,
        DolarFuturo::avisoCurvaDesactualizada($delViernes, $habil));
}

$habil = CronogramaPagos::habilAnterior('2026-10-30', $cal)['fecha'];
$aviso = DolarFuturo::avisoCurvaDesactualizada('2026-10-29 08:00:00', $habil, $regla);

chequear('actualizada el jueves, hoy viernes habil: avisa', Aviso::WARNING, $aviso['nivel']);
chequear('el texto de la especificacion',
    'La curva de dólar futuro ROFEX se actualizó por última vez el 29/10/2026 08:00. Los pagos '
    . 'posteriores a los próximos 7 días se valúan con esa curva.', $aviso['texto']);

$habil = CronogramaPagos::habilAnterior('2026-11-03', $cal)['fecha'];
chequear('actualizada el viernes, hoy martes habil: avisa', true,
    DolarFuturo::curvaDesactualizada($delViernes, $habil));
chequear('actualizada hoy mismo: no avisa', false,
    DolarFuturo::curvaDesactualizada('2026-11-03 08:00:00', $habil));
chequear('sin fecha de actualizacion no se afirma nada', false,
    DolarFuturo::curvaDesactualizada(null, $habil));

seccion('el servidor valida al guardar, no solo el JavaScript');

$srcParam = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro() pasa los dos por DolarFuturo::validarParametro()', true,
    strpos($srcParam, '$clave === DolarFuturo::PARAM_DIAS_BCRA || $clave === '
        . 'DolarFuturo::PARAM_ANTIGUEDAD_BCRA') !== false
    && strpos($srcParam, '$valor = DolarFuturo::validarParametro($clave, $valor);') !== false);

$srcSql = file_get_contents(__DIR__ . '/../sql/cashflow_comex_dolar_bcra.sql');
chequear('el script siembra N = 7 en Compras Exterior', true,
    strpos($srcSql, "''comex_dias_bcra'', ''7'', ''INT''") !== false
    && strpos($srcSql, "''GENERAL'', ''COMPRAS_PROY''") !== false);
chequear('el script siembra M = 3', true,
    strpos($srcSql, "''comex_bcra_antiguedad_max'', ''3'', ''INT''") !== false);
chequear('y los iniciales del codigo son los mismos', [7, 3],
    [DolarFuturo::DIAS_BCRA_INICIAL, DolarFuturo::ANTIGUEDAD_BCRA_INICIAL]);

/* ================================================================
   LAS TRES PANTALLAS, UNA SOLA REGLA
   ================================================================ */
seccion('las tres pantallas valuan con la misma funcion y la misma regla');

$srcComex = file_get_contents(__DIR__ . '/../Class/Comex.php');
$srcCompras = file_get_contents(__DIR__ . '/../Class/Providers/ComprasProyectadasProvider.php');
$srcDolar = file_get_contents(__DIR__ . '/../Class/DolarFuturo.php');

chequear('Proveedores Exterior: Comex::valuar() con la regla', true,
    strpos($srcComex, "self::valuar(\$row, \$curva, 'PENDIENTE_USD', 'FECHA_PAGO_EFECTIVA', \$bcra)")
        !== false);
chequear('Crono Nacionalizacion: Comex::valuar() con la regla', true,
    strpos($srcComex, "self::valuar(\$row, \$curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA', \$bcra)")
        !== false);
chequear('Compras Proyectadas, el FOB: Comex::valuar() con la regla', true,
    strpos($srcCompras, "Comex::valuar(\$fila, \$curva, 'FOB_USD', 'FECHA_PAGO', \$bcra)") !== false);
chequear('Compras Proyectadas, la nacionalizacion: Comex::valuar() con la regla', true,
    strpos($srcCompras, "Comex::valuar(\$fila, \$curva, 'NAC_USD', 'FECHA_NAC', \$bcra)") !== false);
chequear('las dos regla salen de DolarFuturo::bcra()', 2,
    substr_count($srcComex, '$bcra = $this->dolarFuturo()->bcra($hoy);'));
chequear('y la de Compras tambien', 1, substr_count($srcCompras, '$bcra = $dolar->bcra($h->hoy());'));

/* Fuera de DolarFuturo, solo Comex::valuar() resuelve con la regla: nadie
   mas en el modulo arma su propia version. TarjetasSocios llama a resolver()
   para la curva y decide su proximo vencimiento aparte, a proposito. */
$conRegla = [];

foreach (glob(__DIR__ . '/../Class/{,Providers/}*.php', GLOB_BRACE) as $archivo) {
    if (preg_match('/DolarFuturo::resolver\([^;]*\$bcra/s', file_get_contents($archivo))) {
        $conRegla[] = basename($archivo);
    }
}

chequear('solo Comex.php resuelve con la regla', ['Comex.php'], $conRegla);

chequear('bcra() pide la punta VENDEDORA al leer la cotizacion', 1,
    preg_match('/ultimaHasta\(\$hoy, Cotizacion::VENDEDOR\)/', $srcDolar));

chequear('los avisos del dolar salen en las dos pestanas de Comex', 2,
    substr_count($srcComex, '$this->dolarFuturo()->avisosDolarConNivel()'));
chequear('y en Compras Proyectadas', 1,
    substr_count($srcCompras, '$dolar->avisosDolarConNivel($h->hoy())'));

/* ================================================================
   EN PANTALLA
   ================================================================ */
seccion('el cartel dice la regla en una linea');

chequear('con N = 7 y BCRA',
    'Los pagos de los próximos 7 días —hasta el 07/11/2026— y los vencidos van a dólar BCRA '
    . 'vendedor del 28/10/2026 ($ 1.480,00); los posteriores, a dólar futuro ROFEX del mes de '
    . 'pago.', DolarFuturo::explicarRegla($regla));
chequear('con N = 0, todo a la curva', true,
    strpos(DolarFuturo::explicarRegla($cero), 'Todos los pagos se valúan con dólar futuro') === 0);
chequear('sin BCRA, lo dice', true,
    strpos(DolarFuturo::explicarRegla($sin), 'que hoy no se puede leer') !== false);

seccion('la grilla distingue BCRA, curva, aproximada y override');

$srcFechasJs = file_get_contents(__DIR__ . '/../Js/Comex-fechas.js');
$srcComprasJs = file_get_contents(__DIR__ . '/../Js/Compras-Proyectadas.js');

chequear('la celda de Comex marca BCRA con su clase', true,
    strpos($srcFechasJs, "clases.push('cotiz-bcra')") !== false);
chequear('y con el dia de la cotizacion debajo del valor', true,
    strpos($srcFechasJs, "'BCRA ' + fecha(item.COTIZ_BCRA_FECHA)") !== false);
chequear('las dos pestanas de Comex tienen el estilo', 2,
    substr_count(file_get_contents(__DIR__ . '/../Css/Comex-Proveedores_exterior.css')
        . file_get_contents(__DIR__ . '/../Css/Comex-Crono_nacionalizacion.css'), '.cotiz-bcra {'));
chequear('Compras Proyectadas marca BCRA con el mismo icono', true,
    strpos($srcComprasJs, "origen === 'BCRA' ? ' <i class=\"fas fa-building-columns") !== false);
chequear('el cartel de Comex suma la regla', true,
    strpos($srcFechasJs, "(c.regla ? ' ' + c.regla : '')") !== false);
chequear('y el de Compras Proyectadas tambien', true,
    strpos($srcComprasJs, "(datos.dolar ? ' ' + esc(datos.dolar) : '')") !== false);

$fila = Comex::valuar(['PENDIENTE_USD' => 1000, 'FECHA_PAGO_EFECTIVA' => $hoy], $curva,
    'PENDIENTE_USD', 'FECHA_PAGO_EFECTIVA', $regla);
chequear('la fila valuada con BCRA lleva su fecha para la marca', '2026-10-28',
    $fila['COTIZ_BCRA_FECHA']);
chequear('y su importe en pesos', 1480000.0, $fila['IMPORTE_ARS']);
