<?php
/**
 * Cashflow: el motor de consolidacion.
 *
 * Lo mas delicado del modulo es el arrastre del saldo sobre columnas que NO
 * estan en orden cronologico, asi que se verifica con numeros conocidos y una
 * estructura controlada, sin depender de las tablas de configuracion ni de los
 * modulos reales.
 */

require_once __DIR__ . '/../cashflow/Class/Cashflow.php';

/* ---- Dobles de prueba ------------------------------------------------ */

class EstructuraFalsa {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosFalsos extends Parametros {
    public $dias = 3;
    public $meses = 3;
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() {
        return ['horizonte_dias' => $this->dias, 'horizonte_meses' => $this->meses];
    }
    public function getFeriadosComercio($map = null) { return []; }
}

class CashflowFalso extends Cashflow {
    public $series = [];
    protected function pedirSeries($h, $filas) { return $this->series; }
}

function seccionConf($codigo, $nombre, $rol, $orden, $padre = null) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $nombre, 'ROL' => $rol,
            'ID_PADRE' => $padre, 'ORDEN' => $orden, 'ACTIVO' => 1];
}

function filaConf($id, $codigo, $seccion, $tipo, $orden, $prov = null, $serie = null, $computa = 1) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
            'TIPO' => $tipo, 'COMPUTA' => $computa, 'ORIGEN_PROVIDER' => $prov,
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1];
}

function serieCon($h, $dias = [], $meses = []) {
    $s = $h->serieVacia();

    foreach ($dias as $k => $v)  { $s['dias'][$k] = $v; }
    foreach ($meses as $k => $v) { $s['meses'][$k] = $v; }

    $s['moneda_origen'] = 'ARS';
    $s['tipo_cambio'] = null;
    $s['fuera_horizonte'] = 0;
    $s['sin_fecha'] = 0;
    $s['warnings'] = [];

    return $s;
}

function porCodigo($tablero) {
    $v = [];

    foreach ($tablero['filas'] as $f) {
        $v[$f['codigo']] = $f;
    }

    return $v;
}

/* ================================================================
   Escenario: 3 dias + 3 meses, hoy = 2026-09-06
     eje diario  : 06/09, 07/09, 08/09
     eje mensual : 2026-09, 2026-10, 2026-11
   La columna 2026-09 SI entra en la secuencia, porque contiene del 09
   al 30 de septiembre, posteriores al tramo diario.
   ================================================================ */

$est = new EstructuraFalsa();
$est->secciones = [
    seccionConf('DISP', 'Disponible', 'SALDO', 10),
    seccionConf('ING', 'Ingresos', 'MOVIMIENTO', 20),
    seccionConf('EGR', 'Egresos', 'MOVIMIENTO', 30),
    seccionConf('RES', 'Resultados', 'DERIVADO', 40),
];
$est->filas = [
    filaConf(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    filaConf(2, 'COBROS', 'ING', 'INGRESO', 10, 'VENTAS', 'COBRANZA'),
    filaConf(3, 'VENTA_INFO', 'ING', 'INGRESO', 20, 'VENTAS', 'VENTA', 0),
    filaConf(4, 'SUB_ING', 'ING', 'SUBTOTAL', 30),
    filaConf(5, 'PAGOS', 'EGR', 'EGRESO', 10, 'COMEX_PROV_EXT', 'PAGOS'),
    filaConf(6, 'SUB_EGR', 'EGR', 'SUBTOTAL', 20),
    filaConf(7, 'FLUJO', 'RES', 'FLUJO_NETO', 10),
    filaConf(8, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 20),
];

$h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

$motor = new CashflowFalso($est, new ParametrosFalsos(), $h);
$motor->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($h, ['2026-09-06' => 1000])],
    'VENTAS' => [
        'COBRANZA' => serieCon($h, ['2026-09-06' => 100, '2026-09-07' => 200], ['2026-10' => 500]),
        'VENTA'    => serieCon($h, ['2026-09-06' => 9999]),
    ],
    'COMEX_PROV_EXT' => ['PAGOS' => serieCon($h, ['2026-09-07' => 50], ['2026-10' => 100])],
];

$t = $motor->proyectar();
$p = porCodigo($t);

seccion('el eje inyectado manda');

// Esta es la costura que sostiene TODO el resto del archivo. El arrastre del
// saldo depende de que dia es hoy, asi que un escenario con importes en fechas
// fijas solo tiene sentido si el motor usa el eje del escenario y no la fecha
// real. Sin esto las pruebas del motor caducan solas al pasar la fecha -y
// caducaron-. Se verifica explicito para que, si alguien saca la inyeccion, el
// mensaje de falla diga por que fallan las otras noventa.
chequear('el tablero se genero en el dia del escenario', '2026-09-06', $t['generado']);
chequear('la primera columna diaria es la del escenario', '2026-09-06', $t['dias'][0]['fecha']);

$sinEje = new CashflowFalso($est, new ParametrosFalsos());
$sinEje->series = [];

chequear('sin eje inyectado el motor usa el dia real',
    (new DateTime('today'))->format('Y-m-d'), $sinEje->proyectar()['generado']);

seccion('eje del tablero');

chequear('3 columnas diarias', 3, count($t['dias']));
chequear('3 columnas mensuales', 3, count($t['meses']));
chequear('secuencia de 6 columnas', 6, count($t['secuencia']));
chequear('el mes en curso entra despues del tramo', 'MES|2026-09', $t['secuencia'][3]);
chequear('el mes en curso esta marcado como parcial', true, $t['meses'][0]['parcial']);
chequear('un mes completo no es parcial', false, $t['meses'][1]['parcial']);

seccion('subtotales, con signo');

chequear('los ingresos suman en positivo', 100.0, $p['SUB_ING']['dias']['2026-09-06']);
chequear('y siguen sumando el dia siguiente', 200.0, $p['SUB_ING']['dias']['2026-09-07']);
chequear('subtotal de ingresos del mes', 500.0, $p['SUB_ING']['meses']['2026-10']);
chequear('los egresos restan', -50.0, $p['SUB_EGR']['dias']['2026-09-07']);
chequear('subtotal de egresos del mes', -100.0, $p['SUB_EGR']['meses']['2026-10']);

seccion('una fila informativa se muestra pero no computa');

chequear('la fila muestra su importe', 9999.0, $p['VENTA_INFO']['dias']['2026-09-06']);
chequear('esta marcada como que no computa', false, $p['VENTA_INFO']['computa']);
chequear('el subtotal de su seccion no la incluye', 100.0, $p['SUB_ING']['dias']['2026-09-06']);
chequear('el flujo neto tampoco (1000 de saldo + 100, y no los 9999)',
    1100.0, $p['FLUJO']['dias']['2026-09-06']);

seccion('flujo neto: Ingresos - Egresos, CON el saldo que se muestra arriba');

// La definicion es Ingresos - Egresos, y los Ingresos del cuadro arrancan en el
// Disponible, que incluye el saldo en bancos: en el Excel D38 = D13 + D37.
// Antes esta fila sumaba solo los movimientos, con lo que un dia con saldo
// inicial mostraba la variacion de caja y no lo que el rotulo promete.
chequear('flujo del 06/09 = 1000 de saldo + 100 de cobros',
    1100.0, $p['FLUJO']['dias']['2026-09-06']);
chequear('flujo del 07/09 = 200 - 50, sin saldo ese dia', 150.0, $p['FLUJO']['dias']['2026-09-07']);
chequear('un dia sin movimiento', 0.0, $p['FLUJO']['dias']['2026-09-08']);
chequear('flujo de octubre = 500 - 100', 400.0, $p['FLUJO']['meses']['2026-10']);
chequear('el flujo neto es la suma de los subtotales',
    $p['SUB_ING']['dias']['2026-09-07'] + $p['SUB_EGR']['dias']['2026-09-07'],
    $p['FLUJO']['dias']['2026-09-07']);

// ES EL SALDO MOSTRADO, NO EL ARRASTRE: el 07/09 el arrastre vale 1100 y el
// flujo neto vale 150. Esa es exactamente la diferencia entre FLUJO_NETO y
// SALDO_FINAL, y es lo que no puede confundirse.
chequear('no arrastra: el 07/09 no repite el saldo del 06/09',
    150.0, $p['FLUJO']['dias']['2026-09-07']);
chequear('y el saldo final del mismo dia si lo arrastra',
    1250.0, $p['SALDO_FIN']['dias']['2026-09-07']);

// SALDO_FINAL no suma dos veces el saldo: entra al arrastre como aporte, no
// como fila mostrada. Si sumara las dos cosas, el 06/09 daria 2100.
chequear('el saldo final no cuenta el saldo dos veces',
    1100.0, $p['SALDO_FIN']['dias']['2026-09-06']);

seccion('arrastre del saldo');

// La fila de saldo inicial es una fila de DATOS: muestra lo que devuelve su
// modulo de origen y nada mas. NO muestra el arrastre. Un modulo que todavia no
// existe tiene que verse en cero.
chequear('el saldo inicial muestra lo que dio su proveedor',
    1000.0, $p['DISPONIBLE']['dias']['2026-09-06']);
chequear('y cero donde el proveedor no dio nada, sin arrastrar',
    0.0, $p['DISPONIBLE']['dias']['2026-09-07']);
chequear('la fila de saldo inicial NO esta marcada como arrastre',
    false, $p['DISPONIBLE']['arrastre']);

// El arrastre sigue existiendo, pero solo lo muestra el saldo final
chequear('saldo final del 06/09 = 1000 de apertura + 100',
    1100.0, $p['SALDO_FIN']['dias']['2026-09-06']);
chequear('el saldo final SI esta marcado como arrastre',
    true, $p['SALDO_FIN']['arrastre']);
chequear('saldo final del 07/09 = 1100 + 150', 1250.0, $p['SALDO_FIN']['dias']['2026-09-07']);
chequear('un dia sin movimiento mantiene el saldo',
    1250.0, $p['SALDO_FIN']['dias']['2026-09-08']);
chequear('el resto de septiembre no tiene movimiento',
    1250.0, $p['SALDO_FIN']['meses']['2026-09']);
chequear('saldo final de octubre = 1250 + 400', 1650.0, $p['SALDO_FIN']['meses']['2026-10']);
chequear('y noviembre lo mantiene', 1650.0, $p['SALDO_FIN']['meses']['2026-11']);

$descuadre = false;

foreach ($t['warnings'] as $w) {
    if (strpos($w, 'arrastre del saldo no cierra') !== false) {
        $descuadre = true;
    }
}

chequear('se cumple cierre[n] == apertura[n+1]', false, $descuadre);

seccion('totales');

chequear('total del tramo del flujo = 1100 + 150 + 0', 1250.0, $p['FLUJO']['total_tramo']);
chequear('total mensual del flujo = 0 + 400 + 0', 400.0, $p['FLUJO']['total_meses']);
chequear('total del horizonte del flujo = 1250 + 400', 1650.0, $p['FLUJO']['total_horizonte']);
chequear('el total de una fila de saldo NO es una suma: es el cierre del tramo',
    1250.0, $p['SALDO_FIN']['total_tramo']);
chequear('y el del horizonte es el cierre final', 1650.0, $p['SALDO_FIN']['total_horizonte']);
chequear('la fila esta marcada como de saldo', true, $p['SALDO_FIN']['es_saldo']);
chequear('el saldo inicial informa la apertura del horizonte',
    1000.0, $p['DISPONIBLE']['total_horizonte']);

seccion('KPI por vista: cada uno mide las columnas que se estan mirando');

// ingresos y egresos son MAGNITUDES positivas, como se muestran en una tarjeta
$kd = $t['kpi']['dias'];
$km = $t['kpi']['meses'];
$kc = $t['kpi']['completo'];

// El saldo que se MUESTRA suma en Ingresos, igual que en la fila Total
// Ingresos: si no, la tarjeta y la fila que tiene al lado dirian numeros
// distintos y ninguna de las dos serviria.
chequear('dias: ingresos = 1000 de saldo + 300 de cobros', 1300.0, $kd['ingresos']);
chequear('dias: egresos en positivo', 50.0, $kd['egresos']);
chequear('dias: flujo = 1300 - 50', 1250.0, $kd['flujo']);
chequear('dias: el KPI coincide con el total de la fila del tablero',
    $p['FLUJO']['total_tramo'], $kd['flujo']);
chequear('dias: saldo de cierre', 1250.0, $kd['saldo_cierre']);
chequear('dias: el saldo mas bajo', 1100.0, $kd['minimo']['valor']);
chequear('dias: y cuando ocurre', 'DIA|2026-09-06', $kd['minimo']['columna']);
chequear('dias: cuenta las columnas', 3, $kd['columnas']);

chequear('meses: ingresos son OTROS (los del tramo mensual)', 500.0, $km['ingresos']);
chequear('meses: egresos', 100.0, $km['egresos']);
chequear('meses: flujo = 500 - 100', 400.0, $km['flujo']);
chequear('meses: el KPI coincide con el total mensual de la fila',
    $p['FLUJO']['total_meses'], $km['flujo']);
chequear('meses: saldo de cierre es el final del horizonte', 1650.0, $km['saldo_cierre']);
chequear('meses: el minimo es el de su propio tramo', 1250.0, $km['minimo']['valor']);

chequear('completo: ingresos son la suma de los dos, con el saldo', 1800.0, $kc['ingresos']);
chequear('completo: egresos', 150.0, $kc['egresos']);
chequear('completo: flujo coincide con el total del horizonte',
    $p['FLUJO']['total_horizonte'], $kc['flujo']);
chequear('completo: saldo de cierre', 1650.0, $kc['saldo_cierre']);
chequear('completo: el minimo es el mas bajo de todos', 1100.0, $kc['minimo']['valor']);
chequear('completo: cuenta todas las columnas', 6, $kc['columnas']);

// El saldo de apertura es el UNICO que no varia: es con cuanto se arranca hoy.
chequear('la apertura no varia entre vistas (dias vs meses)',
    $kd['saldo_apertura'], $km['saldo_apertura']);
chequear('la apertura no varia entre vistas (dias vs completo)',
    $kd['saldo_apertura'], $kc['saldo_apertura']);
chequear('y es lo que aporto el proveedor de saldos', 1000.0, $kd['saldo_apertura']);

// Cada vista dice sobre que periodo esta midiendo
chequear('dias: rotulo del periodo', 'Del 6/9 al 8/9', $kd['periodo']);
// Con tilde: es texto que ve el usuario en la barra de periodo, y la regla del
// repo es que esos strings llevan tilde. El rotulo lo arma EjeVista.
chequear('meses: el rotulo aclara que va despues del tramo diario',
    true, strpos($km['periodo'], 'después del tramo diario') !== false);
chequear('completo: el rotulo dice que es todo el horizonte',
    true, strpos($kc['periodo'], 'todo el horizonte') !== false);

/* ================================================================
   Columna fuera de la secuencia: null, no cero.
   Con 28 dias desde el 06/09, la columna 2026-09 solo contiene del 1
   al 5, que ya pasaron.
   ================================================================ */
seccion('una columna que no representa dias futuros');

$par28 = new ParametrosFalsos();
$par28->dias = 28;
$par28->meses = 2;

$h2 = new Horizonte(28, 2, [], new DateTime('2026-09-06'));

$motor2 = new CashflowFalso($est, $par28, $h2);
$motor2->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($h2, ['2026-09-06' => 1000])],
    'VENTAS' => ['COBRANZA' => serieCon($h2, ['2026-09-06' => 100]), 'VENTA' => serieCon($h2)],
    'COMEX_PROV_EXT' => ['PAGOS' => serieCon($h2)],
];

$t2 = $motor2->proyectar();
$p2 = porCodigo($t2);

chequear('la columna queda marcada fuera de secuencia', false, $t2['meses'][0]['en_secuencia']);
// Null es para las filas que dependen del arrastre: ahi no hay posicion que
// mostrar. Una fila de DATOS, en cambio, muestra lo que tiene, que es cero.
chequear('el saldo final ahi es null, no cero', null, $p2['SALDO_FIN']['meses']['2026-09']);
chequear('y el flujo neto tambien', null, $p2['FLUJO']['meses']['2026-09']);
chequear('pero el saldo inicial, que es una fila de datos, va en cero',
    0.0, $p2['DISPONIBLE']['meses']['2026-09']);
chequear('la columna siguiente si tiene valor', 1100.0, $p2['SALDO_FIN']['meses']['2026-10']);

/* ================================================================
   Un modulo sin datos no rompe el tablero
   ================================================================ */
seccion('modulo sin datos');

$motor3 = new CashflowFalso($est, new ParametrosFalsos(), $h);
$motor3->series = [];

$t3 = $motor3->proyectar();
$p3 = porCodigo($t3);

chequear('el tablero se arma igual', 8, count($t3['filas']));
chequear('la fila queda marcada sin datos', true, $p3['COBROS']['sin_datos']);
chequear('y se muestra en cero', 0.0, $p3['COBROS']['dias']['2026-09-06']);
chequear('el saldo final tambien es cero', 0.0, $p3['SALDO_FIN']['dias']['2026-09-06']);

/* ================================================================
   La estructura del Excel: el saldo en bancos vive DENTRO de la
   seccion de disponibilidades, y el subtotal "Disponible" lo incluye.

   Del Excel original:
     Disponible(8/9) = SaldoInicial(8/9) + Echeqs + CobElec + CobFranq
     SaldoInicial(9/9) = Disponible(8/9) + IngresosVenta(8/9) - egresos
   ================================================================ */
seccion('estructura del Excel: subtotal que incluye el saldo');

$estExcel = new EstructuraFalsa();
$estExcel->secciones = [
    seccionConf('DISPO', 'Disponibilidades', 'SALDO', 10),
    seccionConf('VTAS', 'Ventas', 'MOVIMIENTO', 20),
    seccionConf('RES', 'Resultados', 'DERIVADO', 30),
];
$estExcel->filas = [
    filaConf(1, 'SALDO_BANCOS', 'DISPO', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    filaConf(2, 'ECHEQS', 'DISPO', 'INGRESO', 20, 'ECHEQS', 'A_COBRAR'),
    filaConf(3, 'COB_FR', 'DISPO', 'INGRESO', 30, 'COBRANZAS_FR', 'COBRANZA'),
    filaConf(4, 'DISPONIBLE', 'DISPO', 'SUBTOTAL', 40),
    filaConf(5, 'VTA_LOCALES', 'VTAS', 'INGRESO', 10, 'VENTAS', 'COBRANZA_LOCALES'),
    filaConf(6, 'ING_VENTA', 'VTAS', 'SUBTOTAL', 20),
    filaConf(7, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 10),
];

$motorX = new CashflowFalso($estExcel, new ParametrosFalsos(), $h);
$motorX->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($h, ['2026-09-06' => 90])],
    'ECHEQS' => ['A_COBRAR' => serieCon($h, ['2026-09-06' => 4])],
    'COBRANZAS_FR' => ['COBRANZA' => serieCon($h, ['2026-09-06' => 25])],
    'VENTAS' => ['COBRANZA_LOCALES' => serieCon($h, ['2026-09-06' => 70])],
];

$tx = $motorX->proyectar();
$px = porCodigo($tx);

chequear('el saldo en bancos se muestra', 90.0, $px['SALDO_BANCOS']['dias']['2026-09-06']);
chequear('Disponible = saldo + echeqs + cobranzas = 90 + 4 + 25',
    119.0, $px['DISPONIBLE']['dias']['2026-09-06']);
chequear('Ingresos Venta no incluye el saldo: son 70',
    70.0, $px['ING_VENTA']['dias']['2026-09-06']);
chequear('Saldo Final = Disponible + Ingresos Venta = 119 + 70',
    189.0, $px['SALDO_FIN']['dias']['2026-09-06']);

// El saldo en bancos es un dato de Tesoreria: si no cargaron nada al dia
// siguiente, va en cero. NO hereda el cierre del dia anterior.
chequear('al dia siguiente el saldo en bancos va en cero, no arrastra',
    0.0, $px['SALDO_BANCOS']['dias']['2026-09-07']);
chequear('y su subtotal tampoco arrastra', 0.0, $px['DISPONIBLE']['dias']['2026-09-07']);

// El arrastre lo lleva el saldo final, que si acumula
chequear('el saldo final del dia 2 sigue acumulando', 189.0,
    $px['SALDO_FIN']['dias']['2026-09-07']);

// El subtotal que arrastra saldo hereda su indefinicion fuera de secuencia
$parX = new ParametrosFalsos();
$parX->dias = 28;
$parX->meses = 2;

$hX = new Horizonte(28, 2, [], new DateTime('2026-09-06'));
$motorX2 = new CashflowFalso($estExcel, $parX, $hX);
$motorX2->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($hX, ['2026-09-06' => 90])],
    'ECHEQS' => ['A_COBRAR' => serieCon($hX)],
    'COBRANZAS_FR' => ['COBRANZA' => serieCon($hX)],
    'VENTAS' => ['COBRANZA_LOCALES' => serieCon($hX)],
];

$tx2 = $motorX2->proyectar();
$px2 = porCodigo($tx2);

// Como la fila de saldo ya no arrastra, su subtotal es una suma comun y en una
// columna fuera de secuencia da cero, no null.
chequear('el subtotal con saldo es cero fuera de secuencia',
    0.0, $px2['DISPONIBLE']['meses']['2026-09']);
chequear('igual que uno de solo movimientos',
    0.0, $px2['ING_VENTA']['meses']['2026-09']);
chequear('y su total es una suma', 90.0, $px2['DISPONIBLE']['total_tramo']);

// Solo el saldo final queda marcado como arrastre: es el unico que muestra un
// numero que no viene de su fila.
chequear('la fila de saldo inicial NO esta marcada como arrastre',
    false, $px2['SALDO_BANCOS']['arrastre']);
chequear('el subtotal que la incluye tampoco', false, $px2['DISPONIBLE']['arrastre']);
chequear('un subtotal de solo movimientos tampoco', false, $px2['ING_VENTA']['arrastre']);
chequear('una fila de ingreso comun tampoco', false, $px2['COB_FR']['arrastre']);
chequear('el saldo final SI', true, $px2['SALDO_FIN']['arrastre']);

// FLUJO_NETO tambien tiene celdas en null fuera de secuencia, pero es un FLUJO
// y su total SI es una suma. Con el criterio de "tiene nulls" daba el flujo de
// una sola columna en lugar del acumulado.
seccion('el flujo neto se suma aunque tenga nulls');

$estFlujo = new EstructuraFalsa();
$estFlujo->secciones = [
    seccionConf('DISPO', 'Disponibilidades', 'SALDO', 10),
    seccionConf('RES', 'Resultados', 'DERIVADO', 20),
];
$estFlujo->filas = [
    filaConf(1, 'SALDO_BANCOS', 'DISPO', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    filaConf(2, 'COB', 'DISPO', 'INGRESO', 20, 'COBRANZAS_FR', 'COBRANZA'),
    filaConf(3, 'FLUJO', 'RES', 'FLUJO_NETO', 10),
    filaConf(4, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 20),
];

$motorF = new CashflowFalso($estFlujo, $par28, $hX);
$motorF->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($hX)],
    'COBRANZAS_FR' => ['COBRANZA' => serieCon($hX,
        ['2026-09-06' => 10, '2026-09-07' => 20], ['2026-10' => 100])],
];

$tf = $motorF->proyectar();
$pf = porCodigo($tf);

chequear('el flujo neto tiene null fuera de secuencia', null, $pf['FLUJO']['meses']['2026-09']);
chequear('pero su total del tramo es la SUMA, no la ultima columna',
    30.0, $pf['FLUJO']['total_tramo']);
chequear('y el del horizonte tambien suma', 130.0, $pf['FLUJO']['total_horizonte']);
chequear('el flujo neto no esta marcado como arrastre', false, $pf['FLUJO']['arrastre']);
chequear('el saldo final si, y su total es el cierre', 130.0, $pf['SALDO_FIN']['total_horizonte']);

/* ================================================================
   Un resultado intermedio suma solo lo que tiene por encima
   ================================================================ */
seccion('resultado intermedio (alcance posicional)');

$est2 = new EstructuraFalsa();
$est2->secciones = [
    seccionConf('ING', 'Ingresos', 'MOVIMIENTO', 10),
    seccionConf('RES1', 'Resultado parcial', 'DERIVADO', 20),
    seccionConf('AJU', 'Ajustes', 'MOVIMIENTO', 30),
    seccionConf('RES2', 'Resultado final', 'DERIVADO', 40),
];
$est2->filas = [
    filaConf(1, 'COBROS', 'ING', 'INGRESO', 10, 'VENTAS', 'COBRANZA'),
    filaConf(2, 'PARCIAL', 'RES1', 'FLUJO_NETO', 10),
    filaConf(3, 'AJUSTE', 'AJU', 'EGRESO', 10, 'COMEX_PROV_EXT', 'PAGOS'),
    filaConf(4, 'TOTAL', 'RES2', 'FLUJO_NETO', 10),
];

$motor4 = new CashflowFalso($est2, new ParametrosFalsos(), $h);
$motor4->series = [
    'VENTAS' => ['COBRANZA' => serieCon($h, ['2026-09-06' => 100])],
    'COMEX_PROV_EXT' => ['PAGOS' => serieCon($h, ['2026-09-06' => 30])],
];

$t4 = $motor4->proyectar();
$p4 = porCodigo($t4);

chequear('el resultado parcial solo ve lo que tiene arriba',
    100.0, $p4['PARCIAL']['dias']['2026-09-06']);
chequear('el resultado final ve todo', 70.0, $p4['TOTAL']['dias']['2026-09-06']);

/* ================================================================
   LA SECCION COBERTURA: UNA FILA POR TIPO DE FONDO

   Las cuatro filas -dos de stock y dos de uso- pasaron a ser dos: queda la de
   uso, con el disponible de SUS fondos en la celda de Concepto.

   ES UN CAMBIO DE PRESENTACION Y NADA MAS, y esa es la parte que hay que poder
   verificar. Las filas de stock siguen en la estructura porque son las que le
   dan al motor el tope por fondo, el stock contra el que se calcula el
   disponible y lo que CoberturaAutomatica respeta para no rescatar de mas.
   Desactivarlas -que es lo que parece equivalente a esconderlas- deja al motor
   sin topes y rompe el calculo entero, en silencio.

   Por eso el motor no se toca: test_cobertura, test_cobertura_automatica y
   test_fondos siguen pasando sin cambios, y eso es parte del criterio. Lo que
   se chequea aca es que la decision viva en el FRONT.
   ================================================================ */
seccion('las filas de stock no se dibujan, pero siguen existiendo');

$jsCf = file_get_contents(__DIR__ . '/../cashflow/Js/Cashflow.js');

// El front las esconde al dibujar, y lo hace filtrando ANTES de recorrer: con
// un salteo adentro del bucle, una seccion sin filas visibles igual dibujaria
// su encabezado y quedaria un titulo sin nada debajo.
chequear('el front filtra las filas de stock', true,
    strpos($jsCf, "return f.tipo !== 'STOCK_COBERTURA';") !== false);
// El filtrado se aplica sobre la lista ENTERA antes de recorrerla. Desde las
// filas agrupadas la lista filtrada pasa antes por armarRenglones(), que junta
// las partes de cada concepto; el orden importa y es el mismo motivo: una fila
// que no se dibuja tampoco puede partir un grupo en dos renglones que en
// pantalla estan pegados.
chequear('y filtra antes de recorrer, no adentro del bucle', true,
    strpos($jsCf, 'armarRenglones(datos.filas.filter(filaDibujable)).forEach(') !== false);

// Y el motor las sigue resolviendo igual: si esto desapareciera, el disponible
// y los topes por fondo se irian con ellas.
$cfFuente = file_get_contents(__DIR__ . '/../cashflow/Class/Cashflow.php');

chequear('el motor sigue leyendo el stock', true,
    strpos($cfFuente, "if (\$f['tipo'] === 'STOCK_COBERTURA') {") !== false);
chequear('y de ahi saca los topes por fondo', true,
    strpos($cfFuente, "foreach (\$f['fondos_tope'] as \$clave => \$d) {") !== false);

seccion('el disponible que muestra la fila de uso es el de SUS fondos');

/* El total global sumaria pesos invertidos con dolares comitente, y diria -en
   la fila de Inversiones- plata de la que esa fila no puede rescatar. Las
   claves de la fila estan en fondos_fila y el detalle en cobertura.fondos. */
chequear('la linea corre sobre las filas de uso', true,
    strpos($jsCf, "if (f.tipo !== 'USO_COBERTURA' || !f.cobertura || !f.cobertura.hay_stock)")
        !== false);
chequear('y se arma con los fondos de la fila', true,
    strpos($jsCf, 'fondosConDetalle(f)') !== false);

// SIN FONDOS NO SE ESCRIBE NADA: "$ 0 disponibles" diria que hay un fondo
// vacio en lugar de que no hay fondo.
chequear('una fila sin fondos no dice nada', true,
    strpos($jsCf, 'if (!fondos.length) {') !== false);

// EL EXCEDIDO SE CONSERVA. Lo daba la fila de stock y se perderia al sacarla:
// es el aviso de que se esta cubriendo con plata que todavia no figura como
// invertida.
chequear('el excedido se sigue marcando', true,
    strpos($jsCf, 'var excedido = (disponible < 0);') !== false);

seccion('el desglose calculado / a mano no se borro: se mudo al tooltip');

/* Con el disponible en la misma fila, dejarlo tambien en pantalla daria tres
   lineas en la celda de Concepto, que es lo contrario de simplificar. Pero el
   neto que calculo el motor contra lo cargado a mano es lo que explica el
   numero de la fila: sin eso es un importe sin causa. */
chequear('existe el texto para el tooltip', true,
    strpos($jsCf, 'function textoUsoCobertura(f)') !== false);
chequear('sale de cobertura_totales, que lo suma el motor', true,
    strpos($jsCf, 'var t = f.cobertura_totales;') !== false);
chequear('y se cuelga del tooltip de la fila', true,
    strpos($jsCf, 'textoUsoCobertura(f) + textoSaldoCobertura(f)') !== false);

// Ya no se dibuja como linea aparte.
chequear('no quedo ninguna segunda linea de uso', false,
    strpos($jsCf, 'cf-uso-linea') !== false);

seccion('un fondo con stock que ninguna fila aplica se sigue avisando');

/* ES LA UNICA FORMA DE ENTERARSE. Antes la fila de stock estaba a la vista y
   su importe no cuadraba con nada; ahora, si ninguna fila de uso nombra ese
   fondo, no hay ningun renglon donde aparezca. */
chequear('el motor arma el aviso', true, strpos($cfFuente, 'avisarSinFila') !== false);
chequear('y dice que el motor no rescata de ahi', true,
    strpos($cfFuente, 'ninguna fila de uso los aplica') !== false);

// Y llega a la pantalla: los warnings del motor se pintan.
chequear('los avisos del motor se pintan', true,
    strpos($jsCf, 'datos.warnings.map(') !== false);
