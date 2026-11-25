<?php
/**
 * El simulador de Prestamos (Class/PrestamosSimulador.php y
 * Prestamos::necesidad() / comparar()): cuanto haria falta pedir y para
 * cuando, y el saldo con y sin un simulado.
 *
 * SIN BASE: el tablero lo da un doble (Prestamos::tablero() es la costura).
 * El motor de verdad contra la base no corre en ninguna prueba.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. EL MONTO SUGERIDO sin los costos iniciales (o sin su IVA): el prestamo
 *      no alcanza.
 *   2. EL PRIMER NEGATIVO EN EL ORDEN DE LA PANTALLA y no en el del tiempo.
 *   3. UNA FECHA APROXIMADA (columna mensual) presentada como exacta.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Prestamos.php';
require_once __DIR__ . '/../Class/PrestamosSimulador.php';

const PS_HOY = '2026-11-25';

seccion('el monto sugerido');
chequear('ejemplo de la especificacion: necesidad 10.000.000, margen 0, costos 1,2 % -> 10.121.457', 10121457.0,
    PrestamosSimulador::montoSugerido(10000000, 0, 1.2));
chequear('con el margen de 10 %', round(10000000 * 1.1 / 0.988, 0), PrestamosSimulador::montoSugerido(10000000, 10, 1.2));
chequearLanza('costos de 100 % o mas: no hay monto', function () { PrestamosSimulador::montoSugerido(1, 0, 100); });

$costos = [
    ['nombre' => 'Otorgamiento', 'pct' => 0.0, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false, 'activo' => true],
    ['nombre' => 'Sellos', 'pct' => 1.2, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false, 'activo' => true],
    ['nombre' => 'IVA s/ int', 'pct' => 10.5, 'base' => 'INTERESES', 'momento' => 'POR_CUOTA', 'iva' => false, 'activo' => true],
    ['nombre' => 'Aval SGR', 'pct' => 2.0, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => true, 'activo' => false]
];
chequear('% de costos iniciales: solo los activos e iniciales', 1.2, PrestamosSimulador::pctIniciales($costos, 0.21));
$costos[3]['activo'] = true;
chequear('un inicial con IVA va con su IVA (2 % + 21 % = 2,42 %)', 3.62, PrestamosSimulador::pctIniciales($costos, 0.21));

seccion('el primer negativo y el minimo, en el orden del tiempo');
$sec = ['DIA|2026-11-25', 'DIA|2026-11-26', 'DIA|2026-11-27', 'MES|2027-01', 'MES|2027-02'];
$a = PrestamosSimulador::analizar($sec, ['DIA|2026-11-25' => 100, 'DIA|2026-11-26' => 50, 'DIA|2026-11-27' => -20,
                                          'MES|2026-11' => null, 'MES|2027-01' => -500, 'MES|2027-02' => 30]);
chequear('el primer dia en negativo', 'DIA|2026-11-27', $a['primer']);
chequear('el minimo y su columna', [-500.0, 'MES|2027-01'], [$a['minimo'], $a['minimo_col']]);
$a = PrestamosSimulador::analizar(['MES|2027-02', 'DIA|2026-11-25'], ['MES|2027-02' => -1, 'DIA|2026-11-25' => -2]);
chequear('recorre la secuencia que le dan, no las claves del mapa', 'MES|2027-02', $a['primer']);
$a = PrestamosSimulador::analizar($sec, array_fill_keys($sec, 10));
chequear('sin faltante', [false, null], [$a['hay_faltante'], $a['primer']]);
chequear('las columnas sin dia futuro (null) no cuentan', true,
    PrestamosSimulador::analizar(['MES|2026-11', 'DIA|2026-11-25'], ['MES|2026-11' => null, 'DIA|2026-11-25' => 5])['minimo'] === 5.0);

seccion('la fecha del desembolso');
$f = PrestamosSimulador::fechaDesembolso('DIA|2026-11-28', [], PS_HOY);
chequear('un sabado en negativo: el habil anterior (viernes)', ['fecha' => '2026-11-27', 'aproximada' => false], $f);
$f = PrestamosSimulador::fechaDesembolso('DIA|2026-12-01', ['2026-12-01' => false], PS_HOY);
chequear('un feriado: el habil anterior', '2026-11-30', $f['fecha']);
$f = PrestamosSimulador::fechaDesembolso('MES|2027-02', [], PS_HOY);
chequear('una columna mensual: el primer habil del mes, aproximada', ['fecha' => '2027-02-01', 'aproximada' => true], $f);
$f = PrestamosSimulador::fechaDesembolso('MES|2027-05', [], PS_HOY);
chequear('si el 1 cae sabado, el lunes', '2027-05-03', $f['fecha']);
chequear('nunca antes de hoy', PS_HOY, PrestamosSimulador::fechaDesembolso('DIA|' . PS_HOY, ['2026-11-25' => false], PS_HOY)['fecha']);

seccion('el saldo del tablero y por mes');
$tablero = ['filas' => [
    ['tipo' => 'SALDO_FINAL', 'dias' => ['2026-11-25' => 1], 'meses' => ['2027-01' => 1]],
    ['tipo' => 'FLUJO_NETO', 'dias' => ['2026-11-25' => 9], 'meses' => ['2027-01' => 9]],
    ['tipo' => 'SALDO_FINAL', 'dias' => ['2026-11-25' => 100, '2026-11-26' => -40], 'meses' => ['2026-11' => null, '2027-01' => -300]]
]];
chequear('toma la ULTIMA fila de saldo (con cobertura)', ['DIA|2026-11-25' => 100, 'DIA|2026-11-26' => -40, 'MES|2026-11' => null, 'MES|2027-01' => -300],
    PrestamosSimulador::saldoDelTablero($tablero));
chequear('sin fila de saldo: null', null, PrestamosSimulador::saldoDelTablero(['filas' => []]));
chequear('al cierre de cada mes', ['2026-11' => -40.0, '2027-01' => -300.0],
    PrestamosSimulador::porMes(['DIA|2026-11-25', 'DIA|2026-11-26', 'MES|2026-11', 'MES|2027-01'], PrestamosSimulador::saldoDelTablero($tablero)));

seccion('Prestamos::necesidad() y comparar() con el tablero de un doble');

class ParDobleSim extends PrestamosParametros {
    public function __construct() { }
    public function generales() {
        $g = [];
        foreach (self::GENERALES as $k => $x) { $g[$k] = $x['defecto']; }
        $g['MARGEN'] = 0;

        return $g;
    }
    public function costosParam() {
        return [['id' => 1, 'nombre' => 'Sellos', 'pct' => 1.2, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false, 'activo' => true]];
    }
}

class PrestamosDobleSim extends Prestamos {
    public $saldos;
    public function __construct($saldos) {
        parent::__construct(PS_HOY, new ParDobleSim(), null);
        $this->saldos = $saldos;
    }
    protected function tablero($sim) {
        $k = ($sim === false) ? 'oficial' : 'con';
        $dias = [];
        $meses = [];

        foreach ($this->saldos[$k] as $col => $v) {
            if (strpos($col, 'DIA|') === 0) { $dias[substr($col, 4)] = $v; } else { $meses[substr($col, 4)] = $v; }
        }

        return ['tablero' => ['filas' => [['tipo' => 'SALDO_FINAL', 'dias' => $dias, 'meses' => $meses]]],
                'secuencia' => array_keys($this->saldos[$k])];
    }
    public function habiles($desde, $hasta, &$avisos) { return []; }
}

$pr = new PrestamosDobleSim(['oficial' => ['DIA|2026-11-25' => 5000000, 'DIA|2026-11-26' => -2000000, 'MES|2027-01' => -10000000, 'MES|2027-02' => 1000000],
                             'con' => ['DIA|2026-11-25' => 15000000, 'DIA|2026-11-26' => 8000000, 'MES|2027-01' => 500000, 'MES|2027-02' => 200000]]);
$n = $pr->necesidad();
chequear('hay faltante', true, $n['hay_faltante']);
chequear('primer dia en negativo: el 26/11', '2026-11-26', $n['primer_negativo']);
chequear('no es aproximada (columna diaria)', false, $n['aproximada']);
chequear('necesidad = |minimo|', 10000000.0, $n['necesidad']);
chequear('sugerido con 1,2 % de costos y margen 0', 10121457.0, $n['sugerido']);
chequear('el desembolso, el 26/11 (habil)', '2026-11-26', $n['desembolso']);
$pr2 = new PrestamosDobleSim(['oficial' => ['DIA|2026-11-25' => 5, 'MES|2027-02' => -100], 'con' => []]);
$n = $pr2->necesidad();
chequear('negativo recien en una columna mensual: fecha aproximada, primer habil del mes', [true, '2027-02-01'], [$n['aproximada'], $n['desembolso']]);
$pr3 = new PrestamosDobleSim(['oficial' => ['DIA|2026-11-25' => 5, 'MES|2027-02' => 100], 'con' => []]);
chequear('sin faltante: lo dice y no sugiere nada', [false, false], [$pr3->necesidad()['hay_faltante'], isset($pr3->necesidad()['sugerido'])]);
$c = $pr->comparar(9);
chequear('con y sin, al cierre de cada mes', [['2026-11', -2000000.0, 8000000.0], ['2027-01', -10000000.0, 500000.0], ['2027-02', 1000000.0, 200000.0]],
    array_map(function ($m) { return [$m['mes'], $m['sin'], $m['con']]; }, $c['meses']));
chequear('con el rotulo del mes', 'Ene-27', $c['meses'][1]['rotulo']);

seccion('cableado');
$ctrl = file_get_contents(__DIR__ . '/../Controller/PrestamosController.php');
chequear('calcular la necesidad es una lectura de la pestaña', true, (bool) preg_match("/case 'calcularNecesidad':\s*autorizarLectura\('prestamos'\);\s*AuthCashflow::liberarSesion\(\);/", $ctrl));
chequear('comparar tambien', true, (bool) preg_match("/case 'compararSimulado':\s*autorizarLectura\('prestamos'\);\s*AuthCashflow::liberarSesion\(\);/", $ctrl));
chequear('armar el simulado pide editar Prestamos', [['prestamos', null]], AuthCashflow::destinosDe('Prestamos', 'simularPrestamo'));
chequear('el oficial se pide sin simulaciones', true, strpos(file_get_contents(__DIR__ . '/../Class/Prestamos.php'), '$t = $this->tablero(false);') !== false);
chequear('y el "con" solo con ese simulado', true, strpos(file_get_contents(__DIR__ . '/../Class/Prestamos.php'), '$con = $this->tablero([(int) $id]);') !== false);
$js = file_get_contents(__DIR__ . '/../Js/Prestamos.js');
$tab = file_get_contents(__DIR__ . '/../Tabs/prestamos.php');
chequear('el boton Simular prestamo solo con permiso', true, strpos($js, "Permisos.siEdita(caja, '<div class=\"mt-3") !== false);
chequear('la fecha aproximada se dice en pantalla', true, strpos($js, 'Aproximada: cae en una columna mensual') !== false);
chequear('la aclaracion del monto sugerido', true, strpos($tab, 'El monto sugerido no considera las cuotas del propio préstamo') !== false);
chequear('la tarjeta de necesidad arranca en —', true, (bool) preg_match('/id="prKpiNecesidad">—</', $tab));
