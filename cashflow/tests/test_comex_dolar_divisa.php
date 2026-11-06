<?php
/**
 * Comex - Crono Nacionalizacion valua los proximos dias con el dolar divisa.
 *
 * Desde feature/crono-nacionalizacion-dolar-divisas. Las nacionalizaciones de
 * los proximos N dias -y las vencidas- van al dolar DIVISA vendedor del dia
 * habil anterior (FP_DOLAR_DIVISAS) en vez del BCRA. Proveedores Exterior y
 * Compras Proyectadas siguen con el BCRA.
 *
 * LA REGLA ES LA MISMA DE test_comex_dolar_bcra.php, con otra fuente: aca se
 * prueba que la fuente cambie el dolar, el origen, el motivo y los textos, y
 * que no cambie nada mas. Sin base, salvo la ultima seccion, que lee
 * FP_DOLAR_DIVISAS y se saltea sola.
 */

require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Cotizacion.php';

/** La curva de juguete: octubre y noviembre de 2026 */
function curvaDivisaPrueba() {
    return [
        '2026-10' => ['clave' => '2026-10', 'simbolo' => 'DLR/OCT26', 'cotizacion' => 1537.3,
                      'anio' => 2026, 'mes' => 10, 'actualizacion' => '2026-10-30 08:00:00'],
        '2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26', 'cotizacion' => 1570.0,
                      'anio' => 2026, 'mes' => 11, 'actualizacion' => '2026-10-30 08:00:00']
    ];
}

/** El ultimo divisa vendedor, como lo devuelve DolarFuturo::divisaHasta() */
function divisaPrueba($fecha = '2026-11-05', $valor = 1546.0) {
    return ['fecha' => $fecha, 'valor' => $valor, 'punta' => Cotizacion::VENDEDOR];
}

$curva = curvaDivisaPrueba();
$hoy = '2026-11-06';
$DIV = DolarFuturo::FUENTE_DIVISA;

/* ================================================================
   LA REGLA PURA CON FUENTE DIVISA
   ================================================================ */
seccion('las nacionalizaciones de los proximos N dias van a dolar divisa');

$regla = DolarFuturo::reglaBcra($hoy, 7, 3, divisaPrueba(), null, $DIV);

chequear('la regla lleva su fuente', $DIV, $regla['fuente']);
chequear('N = 7 desde el 06/11: hasta el 13/11', '2026-11-13', $regla['hasta']);

$r = DolarFuturo::resolver($curva, $hoy, null, $regla);
chequear('hoy: DIVISA', DolarFuturo::ORIGEN_DIVISA, $r['origen']);
chequear('hoy: la cotizacion divisa', 1546.0, $r['cotizacion']);
chequear('hoy: viaja la fecha de la cotizacion', '2026-11-05', $r['bcra_fecha']);
chequear('hoy: y la fuente', $DIV, $r['bcra_fuente']);
chequear('hoy: la punta vendedora', Cotizacion::VENDEDOR, $r['bcra_punta']);

$r = DolarFuturo::resolver($curva, '2026-11-13', null, $regla);
chequear('el dia N: DIVISA', DolarFuturo::ORIGEN_DIVISA, $r['origen']);

$r = DolarFuturo::resolver($curva, '2026-11-14', null, $regla);
chequear('el dia N + 1: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);
chequear('el dia N + 1: la de noviembre', 1570.0, $r['cotizacion']);
chequear('el dia N + 1: sin fecha ni fuente del dolar del dia', [null, null],
    [$r['bcra_fecha'], $r['bcra_fuente']]);

$r = DolarFuturo::resolver($curva, '2026-10-02', null, $regla);
chequear('vencida: DIVISA', DolarFuturo::ORIGEN_DIVISA, $r['origen']);
chequear('vencida: la divisa, no la de octubre', 1546.0, $r['cotizacion']);

seccion('el override sigue ganando sobre la divisa');

$r = DolarFuturo::resolver($curva, $hoy, '1600', $regla);
chequear('hoy con override: OVERRIDE', DolarFuturo::ORIGEN_OVERRIDE, $r['origen']);
chequear('hoy con override: el valor cargado', 1600.0, $r['cotizacion']);

$r = DolarFuturo::resolver($curva, '2026-10-02', '1600', $regla);
chequear('vencida con override: OVERRIDE', DolarFuturo::ORIGEN_OVERRIDE, $r['origen']);

seccion('con N = 0 nadie va a la divisa');

$cero = DolarFuturo::reglaBcra($hoy, 0, 3, divisaPrueba(), null, $DIV);
chequear('N = 0: no hay ventana', null, $cero['hasta']);

$r = DolarFuturo::resolver($curva, $hoy, null, $cero);
chequear('N = 0, hoy: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);

$r = DolarFuturo::resolver($curva, '2026-10-02', null, $cero);
chequear('N = 0, vencida: curva', DolarFuturo::ORIGEN_CURVA, $r['origen']);

seccion('la misma regla con BCRA sigue dando BCRA');

$bcra = DolarFuturo::reglaBcra($hoy, 7, 3, divisaPrueba('2026-11-05', 1570.0));
chequear('sin fuente, la regla es BCRA', DolarFuturo::FUENTE_BCRA, $bcra['fuente']);

$r = DolarFuturo::resolver($curva, $hoy, null, $bcra);
chequear('hoy con BCRA: BCRA', DolarFuturo::ORIGEN_BCRA, $r['origen']);
chequear('y su fuente', DolarFuturo::FUENTE_BCRA, $r['bcra_fuente']);

chequear('una regla vieja, sin la clave fuente, es BCRA', DolarFuturo::FUENTE_BCRA,
    DolarFuturo::fuenteDe(['hasta' => '2026-11-13']));
chequearLanza('una fuente mal escrita no cae en BCRA en silencio', function () {
    DolarFuturo::fuente('divisa');
});

/* ================================================================
   DIVISA VIEJA Y SIN DIVISA
   ================================================================ */
seccion('una divisa de hace mas de M dias se usa y se avisa');

$vieja = DolarFuturo::reglaBcra($hoy, 7, 3, divisaPrueba('2026-11-02', 1534.0), null, $DIV);

$r = DolarFuturo::resolver($curva, $hoy, null, $vieja);
chequear('vieja: se usa igual', 1534.0, $r['cotizacion']);
chequear('vieja (4 dias, M = 3): es vieja', true, DolarFuturo::bcraVieja($vieja));

$aviso = DolarFuturo::avisoBcraVieja($vieja);
chequear('vieja: aviso de atencion', Aviso::WARNING, $aviso['nivel']);
chequear('vieja: nombra el dolar divisa',
    'La última cotización del dólar divisa es del 02/11/2026; se usa ésa para las '
    . 'nacionalizaciones de los próximos 7 días.', $aviso['texto']);

chequear('3 dias con M = 3: todavia no', false,
    DolarFuturo::bcraVieja(DolarFuturo::reglaBcra($hoy, 7, 3, divisaPrueba('2026-11-03'), null, $DIV)));

seccion('sin divisa: null y aviso, nunca el BCRA ni la curva');

$sin = DolarFuturo::reglaBcra($hoy, 7, 3, null, 'la tabla no existe', $DIV);

$r = DolarFuturo::resolver($curva, $hoy, null, $sin);
chequear('hoy sin divisa: null', null, $r['cotizacion']);
chequear('hoy sin divisa: el motivo es la divisa, no el BCRA', DolarFuturo::SIN_DIVISA, $r['motivo']);
chequear('hoy sin divisa: sin origen', null, $r['origen']);
chequear('el tooltip dice que no cae a ningun otro dolar', true,
    strpos(DolarFuturo::explicar($r), 'No se usa el dólar BCRA ni la curva en su lugar') !== false);

$r = DolarFuturo::resolver($curva, '2026-11-20', null, $sin);
chequear('despues de N sin divisa: la curva, que no depende de la divisa',
    DolarFuturo::ORIGEN_CURVA, $r['origen']);

$fila = Comex::valuar(['IMPORTE_EST' => 1000, 'FECHA_NAC_EFECTIVA' => $hoy], $curva,
    'IMPORTE_EST', 'FECHA_NAC_EFECTIVA', $sin);
chequear('valuar sin divisa: importe en pesos null, no cero', null, $fila['IMPORTE_ARS']);

$avisos = Comex::avisosValuacionConNivel([$fila], '2026-11', 'IMPORTE_EST',
    'fecha de nacionalización');
chequear('sin divisa: un aviso, critico', [1, Aviso::DANGER], [count($avisos), $avisos[0]['nivel']]);
chequear('sin divisa: dice cuantos, cuanto en dolares y que dolar falta', true,
    strpos($avisos[0]['texto'], '1 contenedor(es) por U$S 1.000,00') === 0
    && strpos($avisos[0]['texto'], 'dólar divisa vendedor') !== false
    && strpos($avisos[0]['texto'], 'No se usa el dólar BCRA ni la curva en su lugar') !== false);

$aviso = DolarFuturo::avisoSinLeer($sin);
chequear('la divisa que no se puede leer: aviso critico', Aviso::DANGER, $aviso['nivel']);
chequear('y nombra la tabla y el motivo', true,
    strpos($aviso['texto'], DolarFuturo::ORIGEN_DIVISAS) !== false
    && strpos($aviso['texto'], 'la tabla no existe') !== false);
chequear('el BCRA que no se puede leer sigue en atencion', Aviso::WARNING,
    DolarFuturo::avisoSinLeer(DolarFuturo::reglaBcra($hoy, 7, 3, null, 'x'))['nivel']);
chequear('sin error no hay aviso de lectura', null, DolarFuturo::avisoSinLeer($regla));

/* ================================================================
   LOS TEXTOS
   ================================================================ */
seccion('siempre se dice que dolar se uso: fuente, fecha y punta');

$fila = Comex::valuar(['IMPORTE_EST' => 1000, 'FECHA_NAC_EFECTIVA' => $hoy], $curva,
    'IMPORTE_EST', 'FECHA_NAC_EFECTIVA', $regla);
chequear('el tooltip arranca con la especificacion', 0,
    strpos($fila['COTIZ_DETALLE'], 'Dólar divisa vendedor del 05/11/2026: $ 1.546,00'));
chequear('la fila lleva su origen para la marca', DolarFuturo::ORIGEN_DIVISA, $fila['COTIZ_ORIGEN']);
chequear('y la fecha de la divisa', '2026-11-05', $fila['COTIZ_BCRA_FECHA']);
chequear('y su importe en pesos', 1546000.0, $fila['IMPORTE_ARS']);

chequear('la regla en una linea nombra la divisa',
    'Las nacionalizaciones de los próximos 7 días —hasta el 13/11/2026— y las vencidas van a '
    . 'dólar divisa vendedor del 05/11/2026 ($ 1.546,00); las posteriores, a dólar futuro ROFEX '
    . 'del mes en que caen.', DolarFuturo::explicarRegla($regla));
chequear('y sin divisa lo dice', true,
    strpos(DolarFuturo::explicarRegla($sin), 'dólar divisa vendedor —que hoy no se puede leer') !== false);

/* ================================================================
   CONTRA LA BASE, SOLO LECTURA
   ================================================================ */
seccion('contra la base: FP_DOLAR_DIVISAS se lee con su punta vendedora');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $dolar = new DolarFuturo();

    /* El viernes 30/10/2026 es habil y tiene su fila: vendedor $ 1.539. La
       fila del sabado 31 esta rellenada con la del lunes 02/11 ($ 1.534): por
       eso la regla nunca busca hasta un dia no habil. */
    $v = $dolar->divisaHasta('2026-10-30');
    chequear('el 30/10 da su propia fila', '2026-10-30', $v['fecha']);
    chequear('con la punta vendedora, no la compradora', [1539.0, Cotizacion::VENDEDOR],
        [$v['valor'], $v['punta']]);

    $r = $dolar->bcra('2026-11-02', $DIV);
    chequear('un lunes, la regla divisa toma el viernes y no el domingo rellenado',
        '2026-10-30', $r['cotizacion'] === null ? null : $r['cotizacion']['fecha']);
    chequear('y sin error', null, $r['error']);

    $b = $dolar->bcra('2026-11-02');
    chequear('la regla BCRA del mismo dia sigue siendo BCRA', DolarFuturo::FUENTE_BCRA, $b['fuente']);
    /* El viernes 30/10: BCRA vendedor $ 1.560, divisa vendedor $ 1.539. */
    chequear('y el cache no mezcla las fuentes: cada una con su valor del viernes',
        [1560.0, 1539.0, $DIV],
        [$b['cotizacion']['valor'], $r['cotizacion']['valor'], $dolar->bcra('2026-11-02', $DIV)['fuente']]);

    chequear('antes de 2011 no hay divisa: null, no cero', null, $dolar->divisaHasta('2010-12-31'));
}

/* ================================================================
   QUIEN USA CADA DOLAR
   Leyendo el codigo sin comentarios, como test_comex_dolar_bcra.php.
   ================================================================ */
seccion('solo Crono Nacionalizacion pide la divisa; las otras dos siguen con BCRA');

$sinComentarios = function ($archivo) {
    $src = file_get_contents($archivo);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);

    return preg_replace('#^\s*//.*$#m', '', $src);
};

$srcComex = $sinComentarios(__DIR__ . '/../Class/Comex.php');
$srcCompras = $sinComentarios(__DIR__ . '/../Class/Providers/ComprasProyectadasProvider.php');
$srcComprasCtl = $sinComentarios(__DIR__ . '/../Controller/ComprasProyectadasController.php');
$srcCtl = $sinComentarios(__DIR__ . '/../Controller/ComexController.php');

/* Los dos listados de Comex, por su firma, para no confundir uno con otro */
$cuerpo = function ($src, $funcion) {
    $ini = strpos($src, 'function ' . $funcion . '(');
    $fin = strpos($src, "\n    public ", $ini + 1);

    return substr($src, $ini, ($fin === false ? strlen($src) : $fin) - $ini);
};

$crono = $cuerpo($srcComex, 'getCronoNacionalizacion');
$prov = $cuerpo($srcComex, 'getProveedoresExterior');

chequear('Crono Nacionalizacion valua con la regla divisa', 1,
    substr_count($crono, 'bcra($hoy, DolarFuturo::FUENTE_DIVISA)'));
chequear('Proveedores Exterior no pide la divisa', 0, substr_count($prov, 'FUENTE_DIVISA'));
chequear('Proveedores Exterior sigue con la regla por defecto, BCRA', 1,
    substr_count($prov, '$bcra = $this->dolarFuturo()->bcra($hoy);'));
chequear('Compras Proyectadas, FOB y nacionalizacion, no piden la divisa', 0,
    substr_count($srcCompras . $srcComprasCtl, 'FUENTE_DIVISA'));
chequear('Compras Proyectadas sigue con la regla BCRA', 1,
    substr_count($srcCompras, '$bcra = $dolar->bcra($h->hoy());'));

chequear('los avisos de Crono Nacionalizacion son los de la divisa', 1,
    substr_count($cuerpo($srcComex, 'avisosNacionalizacionConNivel'),
        'avisosDolarConNivel(null, DolarFuturo::FUENTE_DIVISA)'));
chequear('y los de Proveedores Exterior, los del BCRA', 1,
    substr_count($cuerpo($srcComex, 'avisosExteriorConNivel'), 'avisosDolarConNivel()'));

chequear('la linea de la regla de Crono nombra la divisa; la de Proveedores, el BCRA',
    [1, 1], [substr_count($srcCtl, 'bcra(null, DolarFuturo::FUENTE_DIVISA)'),
             substr_count($srcCtl, 'explicarRegla($comex->dolarFuturo()->bcra())')]);

/* Nadie fuera de DolarFuturo arma su propio "si es divisa": la decision de
   fuente vive en resolver(). */
$propias = [];

foreach (glob(__DIR__ . '/../Class/{,Providers/}*.php', GLOB_BRACE) as $archivo) {
    if (basename($archivo) !== 'DolarFuturo.php'
        && preg_match('/ORIGEN_DIVISAS|divisaHasta\(/', $sinComentarios($archivo))) {
        $propias[] = basename($archivo);
    }
}

chequear('solo DolarFuturo lee FP_DOLAR_DIVISAS', [], $propias);
chequear('y se lee con el nombre de cuatro partes, en una constante', true,
    strpos(file_get_contents(__DIR__ . '/../Class/DolarFuturo.php'),
        "const ORIGEN_DIVISAS = '[XL-APPS].sistemas.dbo.FP_DOLAR_DIVISAS';") !== false);
chequear('y nadie le escribe', 0, preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+"\s*\.\s*self::ORIGEN_DIVISAS/i',
    file_get_contents(__DIR__ . '/../Class/DolarFuturo.php')));
