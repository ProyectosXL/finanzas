<?php
/**
 * Analisis de Ventas: tendencias, Venta Acumulada y Venta Balance.
 *
 * No toca la base: los tres armadores estan separados de la lectura justamente
 * para poder verificar sin SQL Server lo que tiene filo, que es donde estas
 * tablas se vuelven mentirosas sin que nadie lo note:
 *
 *   armarTendencias()     -> el recorte del mes parcial y el criterio de "sin dato"
 *   armarVentaAcumulada() -> la valuacion mes a mes y el mes sin cotizacion
 *   armarVentaBalance()   -> el corte entre real y proyectado, y el grosado por IVA
 */

require_once __DIR__ . '/../cashflow/Class/Ventas.php';

/**
 * Serie diaria de juguete: un importe fijo por dia para todos los dias de un
 * mes. Con importes planos, el total de un tramo es dias * importe, asi que
 * cualquier desvio delata el recorte.
 */
function serieMes($anio, $mes, $importePorDia, $serie = []) {
    $dias = intval((new DateTime(sprintf('%04d-%02d-01', $anio, $mes)))->format('t'));

    for ($d = 1; $d <= $dias; $d++) {
        $serie[sprintf('%04d-%02d-%02d', $anio, $mes, $d)] = $importePorDia;
    }

    return $serie;
}

/* ================================================================
   Recorrido de la ventana
   ================================================================ */
seccion('la ventana se ancla en el mes del corte');

$serie = [];

foreach ([2025, 2026] as $anio) {
    for ($m = 1; $m <= 12; $m++) {
        $serie = serieMes($anio, $m, 100, $serie);
    }
}

$t = Ventas::armarTendencias($serie, 6, '2026-09-30');

chequear('devuelve 6 filas', 6, count($t['filas']));
chequear('la primera es la mas vieja', '2026-04', $t['filas'][0]['clave']);
chequear('la ultima es la del corte', '2026-09', $t['filas'][5]['clave']);
chequear('el rotulo sale de Horizonte::labelMes', 'Sep-26', $t['filas'][5]['label']);
chequear('y el del anio anterior tambien', 'Sep-25', $t['filas'][5]['label_anio_anterior']);
chequear('informa el dia de corte', '2026-09-30', $t['dia_corte']);

/* ================================================================
   Mes cerrado
   ================================================================ */
seccion('un mes cerrado se compara contra el mes completo');

// Corte el ultimo dia de septiembre: el mes cierra, no hay fila parcial.
chequear('el mes del corte no queda parcial', false, $t['filas'][5]['parcial']);
chequear('suma los 30 dias de septiembre', 3000.0, $t['filas'][5]['neto']);
chequear('y los 30 del anio anterior', 3000.0, $t['filas'][5]['neto_anio_anterior']);
chequear('sin variacion con importes planos', 0.0, $t['filas'][5]['variacion']);

/* ================================================================
   Mes parcial
   ================================================================ */
seccion('el mes en curso recorta el anio anterior a los mismos dias');

$t = Ventas::armarTendencias($serie, 6, '2026-09-06');

chequear('el mes del corte queda parcial', true, $t['filas'][5]['parcial']);
chequear('suma solo los 6 dias transcurridos', 600.0, $t['filas'][5]['neto']);
chequear('el anio anterior tambien se recorta a 6', 600.0, $t['filas'][5]['neto_anio_anterior']);
chequear('y por eso la variacion es cero, no -80%', 0.0, $t['filas'][5]['variacion']);
chequear('informa los dias comparados', 6, $t['filas'][5]['dias']);
chequear('de los dos lados', 6, $t['filas'][5]['dias_anio_anterior']);

// El mes anterior al del corte NO se recorta: esta cerrado.
chequear('el mes anterior sigue completo', false, $t['filas'][4]['parcial']);
chequear('y suma sus 31 dias', 3100.0, $t['filas'][4]['neto']);

/* ================================================================
   El recorte de fin de mes
   ================================================================ */
seccion('el recorte se acota a los dias que el mes del anio anterior tuvo');

// Marzo de 2024 tiene 31 dias; febrero no. Un corte el 31 no puede pedirle al
// anio anterior un dia que no existe.
$serieFeb = serieMes(2027, 3, 10, serieMes(2026, 3, 10));
$t = Ventas::armarTendencias($serieFeb, 1, '2027-03-31');

chequear('marzo cerrado suma sus 31 dias', 310.0, $t['filas'][0]['neto']);
chequear('y el anio anterior tambien', 310.0, $t['filas'][0]['neto_anio_anterior']);

// Ahora el caso con filo: corte el 29 de febrero de un bisiesto contra un
// febrero de 28.
$serieBis = serieMes(2024, 2, 10, serieMes(2023, 2, 10));
$t = Ventas::armarTendencias($serieBis, 1, '2024-02-28');

chequear('febrero bisiesto al 28 es parcial', true, $t['filas'][0]['parcial']);
chequear('suma 28 dias', 280.0, $t['filas'][0]['neto']);
chequear('el anio anterior se acota a los 28 que tuvo', 28, $t['filas'][0]['dias_anio_anterior']);
chequear('y no se pasa de largo', 280.0, $t['filas'][0]['neto_anio_anterior']);

/* ================================================================
   Sin dato del anio anterior
   ================================================================ */
seccion('sin anio anterior la variacion es null, no cero');

// Solo 2026: el historico arranca ahi y 2025 no existe.
$soloUno = serieMes(2026, 5, 50);
$t = Ventas::armarTendencias($soloUno, 1, '2026-05-31');

chequear('el mes tiene venta', 1550.0, $t['filas'][0]['neto']);
chequear('el anio anterior es cero', 0.0, $t['filas'][0]['neto_anio_anterior']);
chequear('y la variacion es null para que el front pinte un guion',
         null, $t['filas'][0]['variacion']);
chequear('el total tampoco inventa una variacion', null, $t['totales']['variacion']);

/* ================================================================
   Totales
   ================================================================ */
seccion('los totales suman las dos columnas');

$serieCrece = serieMes(2026, 6, 200, serieMes(2025, 6, 100));
$t = Ventas::armarTendencias($serieCrece, 1, '2026-06-30');

chequear('total del periodo', 6000.0, $t['totales']['neto']);
chequear('total del anio anterior', 3000.0, $t['totales']['neto_anio_anterior']);
chequear('la variacion del total sale de los totales', 1.0, $t['totales']['variacion']);

/* ================================================================
   Bloque vacio
   ================================================================ */
seccion('una serie vacia no rompe ni inventa importes');

$t = Ventas::armarTendencias([], 6, '2026-09-06');

chequear('devuelve las 6 filas igual', 6, count($t['filas']));
chequear('todas en cero', 0.0, $t['filas'][0]['neto']);
chequear('y sin variacion', null, $t['filas'][0]['variacion']);

/* ================================================================
   VENTA ACUMULADA
   ================================================================ */
seccion('cada mes se valua a SU tipo de cambio de cierre');

// Importes planos y tipos de cambio que se duplican mes a mes: con esos numeros
// las dos cuentas posibles dan resultados que no se confunden.
$netoMensual = [1 => 1000.0, 2 => 1000.0, 3 => 1000.0];
$tc = ['2026-01' => 1000.0, '2026-02' => 2000.0, '2026-03' => 4000.0];

$a = Ventas::armarVentaAcumulada(2026, 3, $netoMensual, [], null, null, $tc);

chequear('devuelve una fila por mes transcurrido', 3, count($a['filas']));
chequear('enero se valua a 1000', 1.0, $a['filas'][0]['neto_usd']);
chequear('febrero a 2000', 0.5, $a['filas'][1]['neto_usd']);
chequear('marzo a 4000', 0.25, $a['filas'][2]['neto_usd']);
chequear('el acumulado en pesos suma los tres meses', 3000.0, $a['filas'][2]['acumulado']);
chequear('el acumulado en dolares es la SUMA de los meses valuados',
         1.75, $a['filas'][2]['acumulado_usd']);
chequear('y el total dice lo mismo', 1.75, $a['totales']['neto_usd']);

// Esto es lo que distingue una serie historica en dolares de una reexpresion a
// moneda de hoy: 3000 / 4000 = 0,75, que no es 1,75.
chequear('NO es el acumulado en pesos dividido por el TC del ultimo mes',
         true, abs($a['totales']['neto_usd'] - (3000.0 / 4000.0)) > 0.001);
chequear('las columnas de dolares se muestran', true, $a['usd_disponible']);

seccion('un mes sin cotizacion queda en null y no corta el acumulado');

$tcHueco = ['2026-01' => 1000.0, '2026-03' => 1000.0]; // falta febrero

$a = Ventas::armarVentaAcumulada(2026, 3, $netoMensual, [], null, null, $tcHueco);

chequear('el mes sin dato no inventa un tipo de cambio', null, $a['filas'][1]['tc']);
chequear('su venta en dolares es null, no cero', null, $a['filas'][1]['neto_usd']);
chequear('el acumulado en pesos no se corta', 3000.0, $a['filas'][2]['acumulado']);
chequear('el acumulado en dolares se sostiene en el mes sin dato',
         1.0, $a['filas'][1]['acumulado_usd']);
chequear('y sigue sumando en el mes que si tiene', 2.0, $a['filas'][2]['acumulado_usd']);
chequear('el total informa cuantos meses quedaron sin valuar', 1, $a['totales']['meses_sin_tc']);

seccion('sin ninguna cotizacion la tabla sale sin la parte en dolares');

$a = Ventas::armarVentaAcumulada(2026, 3, $netoMensual, [], null, null, []);

chequear('los pesos salen igual', 3000.0, $a['totales']['neto']);
chequear('el total en dolares es null, no cero', null, $a['totales']['neto_usd']);
chequear('antes del primer mes valuado el acumulado tampoco es cero',
         null, $a['filas'][0]['acumulado_usd']);
chequear('y el front esconde las columnas', false, $a['usd_disponible']);

seccion('el mes en curso sale del historico diario, no del mensual');

// La tabla mensual tiene el mes en curso incompleto: si se leyera de ahi, el
// importe seria el que este a medio cargar. La serie diaria recortada al corte
// es la que manda.
$serieSept = serieMes(2026, 9, 100);

$a = Ventas::armarVentaAcumulada(
    2026, 9, [9 => 999999.0], $serieSept, 9, '2026-09-06', []
);

chequear('nueve filas, de enero al mes en curso', 9, count($a['filas']));
chequear('el mes en curso suma los 6 dias cargados', 600.0, $a['filas'][8]['neto']);
chequear('y queda marcado parcial', true, $a['filas'][8]['parcial']);
chequear('informando hasta que dia llega', 6, $a['filas'][8]['dias']);
chequear('los meses cerrados sin venta no rompen el acumulado',
         600.0, $a['totales']['neto']);
chequear('un mes cerrado no es parcial', false, $a['filas'][7]['parcial']);

seccion('un anio sin ningun mes cerrado no rompe');

// 1 de enero con el corte diario todavia en diciembre: no hay nada que mostrar.
$a = Ventas::armarVentaAcumulada(2026, 0, [], [], null, null, []);

chequear('sin filas', 0, count($a['filas']));
chequear('sin total en pesos', 0.0, $a['totales']['neto']);
chequear('sin total en dolares', null, $a['totales']['neto_usd']);

/* ================================================================
   VENTA BALANCE
   ================================================================ */
seccion('el anio balance corre del 1/8 al 31/7');

chequear('en septiembre arranca el 1/8 de este anio',
         '2026-08-01', Ventas::inicioBalance(new DateTime('2026-09-08')));
chequear('el 1 de agosto ya es el balance nuevo',
         '2026-08-01', Ventas::inicioBalance(new DateTime('2026-08-01')));
chequear('en marzo sigue el balance que arranco el 1/8 del anio anterior',
         '2025-08-01', Ventas::inicioBalance(new DateTime('2026-03-05')));
chequear('el 31 de julio es el ultimo dia de ese balance',
         '2025-08-01', Ventas::inicioBalance(new DateTime('2026-07-31')));
chequear('y en diciembre tambien es el del anio anterior',
         '2026-08-01', Ventas::inicioBalance(new DateTime('2026-12-31')));

seccion('el eje del balance son doce meses anclados al inicio');

// El eje NO usa 'horizonte_meses': es editable y con 6 el balance saldria
// cortado a la mitad.
$mesesBalance = (new Horizonte(
    0, 12, [], new DateTime(Ventas::inicioBalance(new DateTime('2026-09-08')))
))->meses();

chequear('doce meses', 12, count($mesesBalance));
chequear('arranca en agosto', '2026-08', $mesesBalance[0]['clave']);
chequear('y cierra en julio del anio siguiente', '2027-07', $mesesBalance[11]['clave']);

seccion('el balance suma real grosado por IVA mas proyectado');

$baseBalance = [];
$netoRealBalance = [];

foreach ($mesesBalance as $m) {
    $baseBalance[$m['clave']] = [
        'neto_proyectado' => 200.0,
        'con_iva' => 242.0,
        'estimado' => false
    ];

    // Todos los meses tienen venta real cargada, incluido el mes en curso: es
    // justamente el caso que no puede colarse en la parte real.
    $netoRealBalance[$m['clave']] = 100.0;
}

$b = Ventas::armarVentaBalance($mesesBalance, $baseBalance, $netoRealBalance, 0.21, '2026-09');

chequear('agosto esta cerrado y va real', 'REAL', $b['filas'][0]['origen']);
chequear('la parte real se grosa por IVA: neto * (1 + alicuota)',
         121.0, $b['filas'][0]['venta']);
chequear('el mes en curso va PROYECTADO aunque tenga venta cargada',
         'PROYECTADO', $b['filas'][1]['origen']);
chequear('y toma la proyectada, que ya viene con IVA', 242.0, $b['filas'][1]['venta']);
chequear('los meses futuros tambien', 'PROYECTADO', $b['filas'][11]['origen']);
chequear('un mes real', 1, $b['totales']['meses_real']);
chequear('y once proyectados', 11, $b['totales']['meses_proyectado']);
chequear('el desglose real', 121.0, $b['totales']['real']);
chequear('el desglose proyectado', 11 * 242.0, $b['totales']['proyectado']);
chequear('el total es la suma de las dos partes', 121.0 + 11 * 242.0, $b['totales']['venta']);
chequear('el acumulado del ultimo mes es el total del balance',
         121.0 + 11 * 242.0, $b['filas'][11]['acumulado']);
chequear('informa el inicio del periodo', '2026-08-01', $b['inicio']);
chequear('y el cierre', '2027-07-31', $b['fin']);

seccion('la alicuota llega por parametro, no escrita en el codigo');

$b = Ventas::armarVentaBalance($mesesBalance, $baseBalance, $netoRealBalance, 0.105, '2026-09');

chequear('con 10,5% el mes real se grosa al 10,5%', 110.5, $b['filas'][0]['venta']);
chequear('la parte proyectada no se vuelve a grosar', 242.0, $b['filas'][1]['venta']);

seccion('antes de agosto el corte deja mas meses cerrados');

// Balance 2025/2026 visto desde marzo de 2026: agosto a febrero cerrados.
$mesesMarzo = (new Horizonte(
    0, 12, [], new DateTime(Ventas::inicioBalance(new DateTime('2026-03-05')))
))->meses();

$baseMarzo = [];
$netoMarzo = [];

foreach ($mesesMarzo as $m) {
    $baseMarzo[$m['clave']] = ['neto_proyectado' => 200.0, 'con_iva' => 242.0, 'estimado' => false];
    $netoMarzo[$m['clave']] = 100.0;
}

$b = Ventas::armarVentaBalance($mesesMarzo, $baseMarzo, $netoMarzo, 0.21, '2026-03');

chequear('arranca en agosto del anio anterior', '2025-08', $b['filas'][0]['clave']);
chequear('siete meses cerrados: agosto a febrero', 7, $b['totales']['meses_real']);
chequear('y cinco proyectados: marzo a julio', 5, $b['totales']['meses_proyectado']);
chequear('marzo, el mes en curso, es el primer proyectado',
         'PROYECTADO', $b['filas'][7]['origen']);
chequear('febrero, el ultimo cerrado, es real', 'REAL', $b['filas'][6]['origen']);
