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

$motor = new CashflowFalso($est, new ParametrosFalsos());
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
chequear('el flujo neto tampoco', 100.0, $p['FLUJO']['dias']['2026-09-06']);

seccion('flujo neto');

chequear('flujo del 06/09', 100.0, $p['FLUJO']['dias']['2026-09-06']);
chequear('flujo del 07/09 = 200 - 50', 150.0, $p['FLUJO']['dias']['2026-09-07']);
chequear('un dia sin movimiento', 0.0, $p['FLUJO']['dias']['2026-09-08']);
chequear('flujo de octubre = 500 - 100', 400.0, $p['FLUJO']['meses']['2026-10']);
chequear('el flujo neto es la suma de los subtotales',
    $p['SUB_ING']['dias']['2026-09-07'] + $p['SUB_EGR']['dias']['2026-09-07'],
    $p['FLUJO']['dias']['2026-09-07']);

seccion('arrastre del saldo');

chequear('el saldo inicial del primer dia es cero: el aporte entra ese dia',
    0.0, $p['DISPONIBLE']['dias']['2026-09-06']);
chequear('saldo final del 06/09 = 1000 de apertura + 100',
    1100.0, $p['SALDO_FIN']['dias']['2026-09-06']);
chequear('el saldo inicial del 07/09 es el cierre del 06/09',
    1100.0, $p['DISPONIBLE']['dias']['2026-09-07']);
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

chequear('total del tramo del flujo = 100 + 150 + 0', 250.0, $p['FLUJO']['total_tramo']);
chequear('total del horizonte del flujo = 250 + 400', 650.0, $p['FLUJO']['total_horizonte']);
chequear('el total de una fila de saldo NO es una suma: es el cierre del tramo',
    1250.0, $p['SALDO_FIN']['total_tramo']);
chequear('y el del horizonte es el cierre final', 1650.0, $p['SALDO_FIN']['total_horizonte']);
chequear('la fila esta marcada como de saldo', true, $p['SALDO_FIN']['es_saldo']);
chequear('el saldo inicial informa la apertura del horizonte',
    0.0, $p['DISPONIBLE']['total_horizonte']);

seccion('KPI');

// ingresos y egresos son MAGNITUDES positivas, como se muestran en una tarjeta
chequear('ingresos del tramo', 300.0, $t['kpi']['ingresos_tramo']);
chequear('egresos del tramo, en positivo', 50.0, $t['kpi']['egresos_tramo']);
chequear('flujo del tramo = 300 - 50', 250.0, $t['kpi']['flujo_tramo']);
chequear('el KPI de flujo coincide con la fila del tablero',
    $p['FLUJO']['total_tramo'], $t['kpi']['flujo_tramo']);
chequear('saldo de cierre del tramo', 1250.0, $t['kpi']['saldo_cierre_tramo']);
chequear('saldo de cierre del horizonte', 1650.0, $t['kpi']['saldo_cierre_horizonte']);
chequear('el saldo mas bajo proyectado', 1100.0, $t['kpi']['minimo']['valor']);
chequear('y cuando ocurre', 'DIA|2026-09-06', $t['kpi']['minimo']['columna']);

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

$motor2 = new CashflowFalso($est, $par28);
$motor2->series = [
    'SALDOS' => ['DISPONIBLE' => serieCon($h2, ['2026-09-06' => 1000])],
    'VENTAS' => ['COBRANZA' => serieCon($h2, ['2026-09-06' => 100]), 'VENTA' => serieCon($h2)],
    'COMEX_PROV_EXT' => ['PAGOS' => serieCon($h2)],
];

$t2 = $motor2->proyectar();
$p2 = porCodigo($t2);

chequear('la columna queda marcada fuera de secuencia', false, $t2['meses'][0]['en_secuencia']);
chequear('el saldo final ahi es null, no cero', null, $p2['SALDO_FIN']['meses']['2026-09']);
chequear('el saldo inicial tambien', null, $p2['DISPONIBLE']['meses']['2026-09']);
chequear('y el flujo neto tambien', null, $p2['FLUJO']['meses']['2026-09']);
chequear('la columna siguiente si tiene valor', 1100.0, $p2['SALDO_FIN']['meses']['2026-10']);

/* ================================================================
   Un modulo sin datos no rompe el tablero
   ================================================================ */
seccion('modulo sin datos');

$motor3 = new CashflowFalso($est, new ParametrosFalsos());
$motor3->series = [];

$t3 = $motor3->proyectar();
$p3 = porCodigo($t3);

chequear('el tablero se arma igual', 8, count($t3['filas']));
chequear('la fila queda marcada sin datos', true, $p3['COBROS']['sin_datos']);
chequear('y se muestra en cero', 0.0, $p3['COBROS']['dias']['2026-09-06']);
chequear('el saldo final tambien es cero', 0.0, $p3['SALDO_FIN']['dias']['2026-09-06']);

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

$motor4 = new CashflowFalso($est2, new ParametrosFalsos());
$motor4->series = [
    'VENTAS' => ['COBRANZA' => serieCon($h, ['2026-09-06' => 100])],
    'COMEX_PROV_EXT' => ['PAGOS' => serieCon($h, ['2026-09-06' => 30])],
];

$t4 = $motor4->proyectar();
$p4 = porCodigo($t4);

chequear('el resultado parcial solo ve lo que tiene arriba',
    100.0, $p4['PARCIAL']['dias']['2026-09-06']);
chequear('el resultado final ve todo', 70.0, $p4['TOTAL']['dias']['2026-09-06']);
