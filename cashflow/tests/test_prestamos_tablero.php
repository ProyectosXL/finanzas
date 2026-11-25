<?php
/**
 * Los prestamos en el tablero: la seccion Financiamiento, el proveedor
 * FINANCIERO, el interruptor "Incluir simulaciones" y PESTAÑA = TABLERO.
 *
 * SIN BASE Y CON HOY FIJO (25/11/2026). El motor corre con estructura y
 * proveedores inyectados: nunca el tablero de verdad.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. LA PESTAÑA Y EL TABLERO DICEN DISTINTO.
 *   2. UN SIMULADO SUMA EN EL OFICIAL, o el interruptor no llega al proveedor.
 *   3. LA SECCION NUEVA QUEDA FUERA DEL FLUJO NETO por su posicion.
 *   4. EL TOTAL CONVIVE CON SUS PARTES y se cuenta dos veces.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Cashflow.php';
require_once __DIR__ . '/../Class/Prestamos.php';
require_once __DIR__ . '/../Class/Providers/PrestamosProvider.php';

const PT2_HOY = '2026-11-25';

$h = new Horizonte(28, 12, [], new DateTime(PT2_HOY));

function pt2Res($id, $p, $extra = []) {
    $p = $p + ['id' => $id, 'nombre' => 'P' . $id, 'moneda' => 'ARS', 'capital' => 1200000.0, 'fecha_desembolso' => '2026-11-25',
               'tipo_tasa' => 'FIJA', 'tna' => 36.0, 'sistema' => 'ALEMAN', 'cuotas' => 12, 'frec_capital' => 'MENSUAL',
               'frec_intereses' => 'MENSUAL', 'gracia' => 0, 'estado' => 'VIGENTE', 'cuenta_nombre' => 'Galicia',
               'criterio_tasa' => 'PROMEDIO', 'referencia' => null, 'spread' => null,
               'cancelacion' => ['modo' => 'FIJA', 'pct_total' => 3, 'pct_parcial' => 3, 'iva' => true, 'tramos' => []]];
    $costos = $extra['costos'] ?? [];
    $cancel = $extra['cancelaciones'] ?? [];
    $tasas = $extra['tasas'] ?? [];
    $r = PrestamosCalculo::proyectar(['hoy' => PT2_HOY, 'prestamo' => $p, 'costos' => $costos, 'iva' => 0.21, 'ajustes' => [],
        'cancelaciones' => $cancel, 'tasas' => $tasas, 'habiles' => [], 'curva' => $extra['curva'] ?? []]);

    return ['datos' => ['prestamo' => $p, 'costos' => $costos, 'ajustes' => [], 'cancelaciones' => $cancel], 'r' => $r];
}

/* El escenario de la prueba central: ARS y USD, fijo y variable, con gracia,
   con cancelacion, y un simulado. */
$curva = [];
foreach (range(0, 26) as $i) {
    $m = date('Y-m', strtotime('2026-11-01 +' . $i . ' month'));
    $curva[$m] = ['clave' => $m, 'simbolo' => 'DLR' . $m, 'cotizacion' => 1500.0 + 25 * $i];
}
$serieTamar = ['2026-11-16' => 24.0, '2026-11-17' => 25.0, '2026-11-18' => 26.0, '2026-11-19' => 27.0, '2026-11-20' => 28.0];
$costos = [['id' => 1, 'nombre' => 'Sellos', 'pct' => 1.2, 'base' => 'CAPITAL', 'momento' => 'INICIAL', 'iva' => false],
           ['id' => 2, 'nombre' => 'IVA s/ int', 'pct' => 10.5, 'base' => 'INTERESES', 'momento' => 'POR_CUOTA', 'iva' => false]];

$resultados = [
    1 => pt2Res(1, ['fecha_desembolso' => '2026-10-25', 'sistema' => 'FRANCES']),
    2 => pt2Res(2, ['moneda' => 'USD', 'capital' => 50000.0, 'fecha_desembolso' => '2026-12-05', 'cuotas' => 8, 'frec_capital' => 'TRIMESTRAL',
                    'frec_intereses' => 'MENSUAL', 'gracia' => 2], ['curva' => $curva, 'costos' => $costos]),
    3 => pt2Res(3, ['tipo_tasa' => 'VARIABLE', 'tna' => null, 'referencia' => 'TAMAR', 'spread' => 4.0, 'fecha_desembolso' => '2026-12-01',
                    'cuotas' => 24, 'gracia' => 6], ['tasas' => ['disponible' => true, 'n' => 5, 'series' => ['TAMAR' => $serieTamar], 'manual' => []]]),
    4 => pt2Res(4, ['fecha_desembolso' => '2026-09-15'], ['cancelaciones' => [['id' => 7, 'fecha' => '2027-02-10', 'tipo' => 'TOTAL']]]),
    5 => pt2Res(5, ['estado' => 'SIMULADO', 'fecha_desembolso' => '2027-01-20', 'capital' => 3000000.0, 'sistema' => 'BULLET', 'cuotas' => 1,
                    'frec_capital' => 'ANUAL', 'frec_intereses' => 'TRIMESTRAL'])
];
$calc = ['resultados' => $resultados, 'avisos' => [], 'avisos_tasas' => [], 'avisos_por' => array_fill_keys(array_keys($resultados), [])];

/* ====================================================================== */
seccion('PESTAÑA = TABLERO, columna por columna');

$series = PrestamosProvider::armarSeries($h, Prestamos::pagosTablero($resultados, PT2_HOY, true));
$pr = Prestamos::armarProyeccion($resultados, $h, PT2_HOY);
$difs = [];

foreach (PrestamosProvider::CONCEPTOS as $c) {
    $pestana = $h->serieVacia();

    foreach ($pr['filas'] as $f) {
        if (($f['concepto'] ?? null) !== $c) {
            continue;
        }

        foreach (['dias', 'meses'] as $rama) {
            foreach ($f['eje'][$rama] as $k => $v) {
                $pestana[$rama][$k] += abs($v);
            }
        }
    }

    foreach (['dias', 'meses'] as $rama) {
        foreach ($pestana[$rama] as $k => $v) {
            if (abs($v - $series[$c][$rama][$k]) > 0.01) {
                $difs[] = $c . ' ' . $rama . ' ' . $k . ': pestaña ' . $v . ', tablero ' . $series[$c][$rama][$k];
            }
        }
    }
}

chequear('cada concepto, en cada columna de dias y de meses', [], $difs);
chequear('hay importes en los tres conceptos (la prueba no es trivial)', true,
    array_sum($series['DESEMBOLSO']['meses']) + array_sum($series['DESEMBOLSO']['dias']) > 0
    && array_sum($series['CAPITAL']['meses']) > 0 && array_sum($series['INTERESES']['meses']) > 0);
chequear('el prestamo en dolares entra en pesos', true,
    count(array_filter(Prestamos::pagosTablero([2 => $resultados[2]], PT2_HOY, false), function ($p) { return $p['moneda'] === 'USD' && $p['importe'] > 10000 * 1500; })) > 0);
chequear('el cancelado aporta la cancelacion y nada despues', 0,
    count(array_filter(Prestamos::pagosTablero([4 => $resultados[4]], PT2_HOY, false), function ($p) { return $p['fecha'] > '2027-02-10'; })));

$neto = 0.0;
foreach (PrestamosProvider::CONCEPTOS as $c) {
    foreach (['REAL', 'PROY'] as $parte) {
        $neto += (($c === 'DESEMBOLSO') ? 1 : -1) * (array_sum($series[$c . '_' . $parte]['dias']) + array_sum($series[$c . '_' . $parte]['meses']));
    }
}
chequear('el total oficial de la pestaña es real + proyectado del tablero (sin simulados)', round($neto, 2), round($pr['totales']['total_horizonte'], 2));

seccion('las partes suman el total, y lo simulado va aparte');
foreach (PrestamosProvider::CONCEPTOS as $c) {
    $ok = true;

    foreach (['dias', 'meses'] as $rama) {
        foreach ($series[$c][$rama] as $k => $v) {
            $partes = $series[$c . '_REAL'][$rama][$k] + $series[$c . '_PROY'][$rama][$k] + $series[$c . '_SIM'][$rama][$k];
            $ok = $ok && abs($partes - $v) < 0.01;
        }
    }

    chequear($c . ': REAL + PROY + SIM = total', true, $ok);
}
chequear('con todos los simulados, el bullet simulado esta en SIM', true, array_sum($series['DESEMBOLSO_SIM']['meses']) + array_sum($series['DESEMBOLSO_SIM']['dias']) > 0);
$oficial = PrestamosProvider::armarSeries($h, Prestamos::pagosTablero($resultados, PT2_HOY, false));
chequear('sin simulaciones, SIM en cero', 0.0, array_sum($oficial['DESEMBOLSO_SIM']['meses']) + array_sum($oficial['DESEMBOLSO_SIM']['dias'])
    + array_sum($oficial['CAPITAL_SIM']['meses']) + array_sum($oficial['INTERESES_SIM']['meses']));
chequear('lo de hoy es REAL', true, array_sum($oficial['CAPITAL_REAL']['dias']) > 0);
chequear('y nada mas que lo de hoy', 0.0, array_sum($oficial['CAPITAL_REAL']['dias']) - $oficial['CAPITAL_REAL']['dias'][PT2_HOY]
    + array_sum($oficial['CAPITAL_REAL']['meses']));

/* ====================================================================== */
seccion('el interruptor llega al proveedor por el contrato');

class PrestamosDobleTablero extends Prestamos {
    public $calc;
    public function __construct($calc) { $this->calc = $calc; }
    public function insumos() { return []; }
    public function calcular($ins) { return $this->calc; }
}

class PrestamosProviderDoble extends PrestamosProvider {
    public static $calc;
    protected function modulo($h) { return new PrestamosDobleTablero(self::$calc); }
}

PrestamosProviderDoble::$calc = $calc;
$prov = new PrestamosProviderDoble('FINANCIERO');
$s = $prov->series($h);
chequear('sin opciones: apagado, SIM en cero', 0.0, array_sum($s['DESEMBOLSO_SIM']['meses']) + array_sum($s['DESEMBOLSO_SIM']['dias']));
$prov = (new PrestamosProviderDoble('FINANCIERO'))->conOpciones(['simulaciones' => true]);
$s = $prov->series($h);
chequear('con simulaciones: SIM con el simulado', true, array_sum($s['DESEMBOLSO_SIM']['meses']) + array_sum($s['DESEMBOLSO_SIM']['dias']) > 0);
chequear('normalizar() no pierde las series', 13, count($s));

class CashflowConPrestamos extends Cashflow {
    protected function instanciar($codigo) {
        return ($codigo === 'FINANCIERO') ? new PrestamosProviderDoble('FINANCIERO') : null;
    }
}

class EstructuraPrestamos {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) { return ['secciones' => $this->secciones, 'filas' => $this->filas]; }
}

class ParametrosPrestamos extends Parametros {
    public function __construct() { }
    public function getParametrosMap() { return ['horizonte_dias' => 28, 'horizonte_meses' => 12]; }
    public function getFeriadosComercio($map = null) { return []; }
}

function pt2Fila($id, $codigo, $seccion, $tipo, $orden, $serie = null, $extra = []) {
    return array_merge(['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion, 'TIPO' => $tipo,
        'COMPUTA' => ($tipo === 'SUBTOTAL' || $tipo === 'FLUJO_NETO' || $tipo === 'SALDO_FINAL') ? 0 : 1,
        'ORIGEN_PROVIDER' => $serie ? 'FINANCIERO' : null, 'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1,
        'GRUPO' => null, 'NATURALEZA' => null, 'GRUPO_NOMBRE' => null], $extra);
}

/** La estructura de produccion reducida, con la seccion del script 72. */
function pt2Estructura() {
    $e = new EstructuraPrestamos();
    $e->secciones = [
        ['CODIGO' => 'EGRESOS', 'NOMBRE' => 'Egresos', 'ROL' => 'DERIVADO', 'ID_PADRE' => null, 'ORDEN' => 80, 'ACTIVO' => 1],
        ['CODIGO' => 'FINANCIAMIENTO', 'NOMBRE' => 'Financiamiento', 'ROL' => 'MOVIMIENTO', 'ID_PADRE' => null, 'ORDEN' => 85, 'ACTIVO' => 1],
        ['CODIGO' => 'AJUSTES', 'NOMBRE' => 'Ajustes', 'ROL' => 'MOVIMIENTO', 'ID_PADRE' => null, 'ORDEN' => 90, 'ACTIVO' => 0],
        ['CODIGO' => 'RESULTADOS', 'NOMBRE' => 'Resultados', 'ROL' => 'DERIVADO', 'ID_PADRE' => null, 'ORDEN' => 100, 'ACTIVO' => 1]
    ];
    $id = 1;
    $filas = [];

    foreach (['DESEMBOLSO' => 'INGRESO', 'CAPITAL' => 'EGRESO', 'INTERESES' => 'EGRESO'] as $c => $tipo) {
        foreach (['REAL' => 'REAL', 'PROY' => 'PROYECTADO', 'SIM' => 'SIMULADO'] as $parte => $nat) {
            $filas[] = pt2Fila($id, 'FIN_' . $c . '_' . $parte, 'FINANCIAMIENTO', $tipo, $id * 10, $c . '_' . $parte,
                ['GRUPO' => 'FIN_' . $c, 'NATURALEZA' => $nat, 'GRUPO_NOMBRE' => ($parte === 'REAL' ? $c : null)]);
            $id++;
        }
    }

    $filas[] = pt2Fila(20, 'SUB_FINANCIAMIENTO', 'FINANCIAMIENTO', 'SUBTOTAL', 200);
    $filas[] = pt2Fila(21, 'FINANCIERO', 'AJUSTES', 'EGRESO', 10, null, ['ORIGEN_PROVIDER' => 'FINANCIERO', 'ORIGEN_SERIE' => 'MOVIMIENTOS', 'ACTIVO' => 0]);
    $filas[] = pt2Fila(22, 'FLUJO_NETO', 'RESULTADOS', 'FLUJO_NETO', 10);
    $filas[] = pt2Fila(23, 'SALDO_ACUM', 'RESULTADOS', 'SALDO_FINAL', 20);
    $e->filas = $filas;

    return $e;
}

$porCodigo = function ($tablero) {
    $o = [];
    foreach ($tablero['filas'] as $f) { $o[$f['codigo']] = $f; }
    return $o;
};

$apagado = (new CashflowConPrestamos(pt2Estructura(), new ParametrosPrestamos(), $h))->proyectar();
$prendido = (new CashflowConPrestamos(pt2Estructura(), new ParametrosPrestamos(), $h, ['simulaciones' => true]))->proyectar();
$fa = $porCodigo($apagado);
$fp = $porCodigo($prendido);
chequear('el motor guarda la opcion y la reparte', ['simulaciones' => true],
    (new Cashflow(pt2Estructura(), new ParametrosPrestamos(), $h, ['simulaciones' => true]))->opciones());
chequear('apagado: la fila simulada en cero', 0.0, $fa['FIN_DESEMBOLSO_SIM']['total_horizonte']);
chequear('prendido: con el desembolso simulado', true, $fp['FIN_DESEMBOLSO_SIM']['total_horizonte'] > 0);
chequear('y el flujo neto cambia', true, abs($fp['FLUJO_NETO']['total_horizonte'] - $fa['FLUJO_NETO']['total_horizonte']) > 1);

seccion('la seccion entra en el Flujo Neto (sin cobertura) por su posicion');
$col = PT2_HOY;
$esperado = $fa['FIN_DESEMBOLSO_REAL']['dias'][$col] + $fa['FIN_DESEMBOLSO_PROY']['dias'][$col]
    - $fa['FIN_CAPITAL_REAL']['dias'][$col] - $fa['FIN_CAPITAL_PROY']['dias'][$col]
    - $fa['FIN_INTERESES_REAL']['dias'][$col] - $fa['FIN_INTERESES_PROY']['dias'][$col];
chequear('Flujo Neto de hoy = desembolsos - capital - intereses', round($esperado, 2), round($fa['FLUJO_NETO']['dias'][$col], 2));
chequear('el subtotal de la seccion da lo mismo', round($esperado, 2), round($fa['SUB_FINANCIAMIENTO']['dias'][$col], 2));
chequear('y hoy hay algo que sumar', true, abs($esperado) > 0);
chequear('el saldo acumulado lo recoge', round($fa['FLUJO_NETO']['total_horizonte'], 2),
    round($fa['SALDO_ACUM']['meses'][array_key_last($fa['SALDO_ACUM']['meses'])], 2));
chequear('el KPI de ingresos incluye los desembolsos', true, $fp['FIN_DESEMBOLSO_SIM']['total_horizonte'] > 0
    && $prendido['kpi']['completo']['ingresos'] > $apagado['kpi']['completo']['ingresos']);

seccion('estructura: los grupos de tres pasan el validador');
$est = pt2Estructura();
$prov = [];
foreach (CashflowRegistry::todos() as $m) { $prov[$m['codigo']] = $m; }
$val = CashflowEstructura::validar($est->secciones, $est->filas, $prov);
chequear('sin errores, con la fila vieja de Ajustes inactiva incluida', [], $val['errores']);
chequear('sin aviso de grupo sin parte simulada ni sin real/proyectada', [], array_values(array_filter($val['advertencias'] ?? [], function ($a) {
    return strpos($a, 'FIN_') !== false || strpos($a, 'DESEMBOLSO') !== false || strpos($a, 'CAPITAL') !== false || strpos($a, 'INTERESES') !== false;
})));
$g = CashflowEstructura::grupos($est->secciones, $est->filas);
chequear('tres grupos consecutivos', [true, true, true], [$g['FIN_DESEMBOLSO']['consecutivo'], $g['FIN_CAPITAL']['consecutivo'], $g['FIN_INTERESES']['consecutivo']]);
chequear('cada uno con real, proyectado y simulado', ['REAL' => 1, 'PROYECTADO' => 1, 'SIMULADO' => 1, '' => 0], $g['FIN_CAPITAL']['naturalezas']);
$mal = $est->filas;
$mal[] = pt2Fila(30, 'FIN_CAPITAL_TOTAL', 'FINANCIAMIENTO', 'EGRESO', 210, 'CAPITAL');
chequear('el total junto a sus partes se rechaza', true, count(CashflowEstructura::validar($est->secciones, $mal, $prov)['errores']) > 0);
$x = $est->filas;
$x[0]['NATURALEZA'] = 'OTRA';
chequear('una naturaleza desconocida se rechaza', true, count(CashflowEstructura::validar($est->secciones, $x, $prov)['errores']) > 0);

seccion('el registro: FINANCIERO reusado para Prestamos');
$m = CashflowRegistry::meta('FINANCIERO');
chequear('disponible', true, CashflowRegistry::disponible('FINANCIERO'));
chequear('con nombre Préstamos y la pestaña prestamos', ['Préstamos', 'prestamos'], [$m['nombre'], $m['tab']]);
chequear('MOVIMIENTOS sigue declarada (la fila inactiva de Ajustes la nombra y el validador revisa las inactivas)', true, CashflowRegistry::serieExiste('FINANCIERO', 'MOVIMIENTOS'));
chequear('las nueve partes, los tres totales y la de desuso', 13, count($m['series']));
chequear('y rinde cero', 0.0, array_sum(PrestamosProvider::armarSeries($h, Prestamos::pagosTablero($resultados, PT2_HOY, true))['MOVIMIENTOS']['meses']));
chequear('se instancia', true, CashflowRegistry::instanciar('FINANCIERO') instanceof PrestamosProvider);
chequear('la pestaña recibe los avisos del tablero', true, in_array('prestamos', CashflowRegistry::tabsConProveedor(), true));

seccion('el script 72');
$sql = file_get_contents(__DIR__ . '/../sql/cashflow_prestamos_fila.sql');
chequear('la seccion con ORDEN 85, sin padre', true, strpos($sql, "VALUES ('FINANCIAMIENTO', 'Financiamiento', 'MOVIMIENTO', NULL, 85, 1)") !== false);
foreach (['DESEMBOLSO', 'CAPITAL', 'INTERESES'] as $c) {
    foreach (['REAL', 'PROY', 'SIM'] as $parte) {
        chequear('crea FIN_' . $c . '_' . $parte, true, strpos($sql, "'FIN_" . $c . '_' . $parte . "'") !== false && strpos($sql, "'" . $c . '_' . $parte . "'") !== false);
    }
}
chequear('el subtotal', true, strpos($sql, "('SUB_FINANCIAMIENTO',  'Total Financiamiento',                   'SUBTOTAL', 0, NULL,              40)") !== false);
chequear('en una transaccion', true, strpos($sql, 'BEGIN TRANSACTION') !== false && strpos($sql, 'COMMIT TRANSACTION') !== false);
chequear('rehace el CHECK con SIMULADO', true, strpos($sql, "NATURALEZA IN (''REAL'', ''PROYECTADO'', ''SIMULADO'')") !== false);
chequear('el control de doble conteo al pie', true, strpos($sql, 'DOBLE CONTEO') !== false);
chequear('reejecutable: NOT EXISTS sobre el codigo', true, strpos($sql, 'WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA X WHERE X.CODIGO = F.CODIGO)') !== false);
chequear('sin bajas fisicas', 0, preg_match('/\bDELETE\s+FROM\b/i', $sql));

seccion('el front del tablero');
$js = file_get_contents(__DIR__ . '/../Js/Cashflow.js');
$tab = file_get_contents(__DIR__ . '/../Tabs/cashflow.php');
chequear('el interruptor esta en el tablero, apagado por defecto', true,
    (bool) preg_match('/<input class="form-check-input" type="checkbox" id="cfSimulaciones">/', $tab));
chequear('no se recuerda (no va a localStorage)', false, (bool) preg_match('/localStorage[^;]*cfSimulaciones|cfSimulaciones[^;]*localStorage/', $js));
chequear('viaja en el pedido', true, strpos($js, "(conSimulaciones() ? '&simulaciones=1' : '')") !== false);
chequear('cambiarlo recarga el tablero', true, strpos($js, "chkSim.addEventListener('change', cargar);") !== false);
chequear('la parte simulada se esconde apagado', true, strpos($js, "if (f.naturaleza === 'SIMULADO' && !conSimulaciones())") !== false);
chequear('y se ve distinta prendido', true, strpos($js, "clases.push('cf-parte-simulada')") !== false);
chequear('con la etiqueta Simulado', true, strpos($js, "if (f.naturaleza === 'SIMULADO') { return 'Simulado'; }") !== false);
chequear('el aviso de arriba', true, strpos($tab, 'Incluye préstamos simulados: no es el cashflow oficial') !== false);
chequear('el tooltip de Ingresos nombra los desembolsos', true, strpos($js, "' de desembolsos de préstamos'") !== false);
chequear('el controller pasa la opcion al motor', true,
    strpos(file_get_contents(__DIR__ . '/../Controller/CashflowController.php'), "new Cashflow(null, null, null, ['simulaciones' => !empty(\$_GET['simulaciones'])])") !== false);
