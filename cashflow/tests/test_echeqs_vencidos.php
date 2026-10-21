<?php
/**
 * Echeqs: los cheques vencidos en cartera y el corrimiento al dia habil.
 *
 * Lo delicado son CRITERIOS: que es un vencido, cuanto dura la ventana, que
 * fecha se puede asignar, donde cae cada cheque y en que serie. Viven en
 * helpers estaticos puros y se verifican aca sin base, con la fecha de hoy y
 * el calendario inyectados para que las pruebas no caduquen.
 *
 * Nada de este archivo escribe en la base.
 */

require_once __DIR__ . '/../Class/Echeqs.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

// Sin los CR: git puede bajar el script con fin de linea de Windows.
$SQL_VENC = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_echeqs_vencidos.sql'));
$srcEcheqsV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));

/* ================================================================
   El script
   ================================================================ */
seccion('el script de los vencidos');

foreach (['ID_SBA14', 'FECHA_ACREDITACION', 'OBSERVACION', 'VIGENTE', 'ID_REEMPLAZA',
          'USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF',
          'USUARIO_BAJA', 'FECHA_BAJA'] as $col) {
    chequear('la fecha asignada guarda ' . $col, 1, preg_match('/\n\s+' . $col . '\s+[A-Z]/', $SQL_VENC));
}

chequear('crea la tabla si no existe: reejecutable', 1,
    substr_count($SQL_VENC, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION', 'U') IS NULL"));
chequear('una sola fecha vigente por cheque: indice unico filtrado', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQFAC_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION \(ID_SBA14\)\s+WHERE VIGENTE = 1/', $SQL_VENC));
chequear('sin FK contra dbo.SBA14: es de Tango', false,
    strpos($SQL_VENC, 'REFERENCES dbo.SBA14') !== false);
chequear('ni bajas fisicas', false,
    stripos($SQL_VENC, 'DROP TABLE') !== false || stripos($SQL_VENC, 'DELETE ') !== false);
chequear('siembra la ventana en 30, en el modulo PRECHEQUEADO', true,
    strpos($SQL_VENC, "''echeqs_dias_vencidos_cartera'', ''30''") !== false
    && strpos($SQL_VENC, "('echeqs_dias_vencidos_cartera', '30'") !== false
    && strpos($SQL_VENC, "''DEPOSITO'', ''PRECHEQUEADO''") !== false);
chequear('y si ya existe no le pisa el valor', true,
    strpos($SQL_VENC, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'echeqs_dias_vencidos_cartera')") !== false
    && strpos($SQL_VENC, 'UPDATE dbo.RO_T_CASHFLOW_PARAMETROS') === false);
chequear('controla que la ventana sea de 1 a 30', 1,
    substr_count($SQL_VENC, 'NOT BETWEEN 1 AND 30'));
chequear('Echeqs nombra la tabla y el script del archivo', [true, true], [
    strpos($SQL_VENC, Echeqs::TABLA_FECHA_ACREDITACION) !== false,
    file_exists(__DIR__ . '/../' . Echeqs::SCRIPT_VENCIDOS)
]);

/* ================================================================
   La ventana: Parametros -> Cheques
   ================================================================ */
seccion('la ventana de los vencidos');

chequear('1 es una ventana valida', 1, Echeqs::validarVentana('1'));
chequear('30 tambien: el plazo legal', 30, Echeqs::validarVentana(30));
chequearLanza('cero no', function () { Echeqs::validarVentana(0); });
chequearLanza('31 no', function () { Echeqs::validarVentana('31'); });
chequearLanza('vacia no', function () { Echeqs::validarVentana(''); });
chequearLanza('un texto no', function () { Echeqs::validarVentana('treinta'); });
chequearLanza('ni un decimal', function () { Echeqs::validarVentana('10.5'); });

$v = Echeqs::ventanaDesdeValor(null);
chequear('sin el parametro, la ventana es null: no hay un 30 de respaldo', null, $v['dias']);
chequear('y el motivo nombra el script', true, strpos($v['error'], Echeqs::SCRIPT_VENCIDOS) !== false);

$v = Echeqs::ventanaDesdeValor('40');
chequear('una ventana rota en la base tambien es null', null, $v['dias']);
chequear('y dice donde corregirla', true, strpos($v['error'], 'Parámetros → Cheques') !== false);

chequear('una ventana bien guardada se usa', 10, Echeqs::ventanaDesdeValor('10')['dias']);

chequear('Parametros y Echeqs nombran la misma clave de la ventana',
    Echeqs::CLAVE_VENTANA, Parametros::CLAVE_VENTANA_ECHEQS);

$srcParametrosV = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro() no guarda la ventana: tiene su propio camino, que valida', true,
    strpos($srcParametrosV, 'if ($clave === self::CLAVE_VENTANA_ECHEQS)') !== false);

$bloqueGuardarV = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function guardarVentana('), 1400);
chequear('guardarVentana() valida y escribe con el usuario de escritura', true,
    strpos($bloqueGuardarV, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);') !== false
    && strpos($bloqueGuardarV, '$dias = self::validarVentana($dias);') !== false
    && strpos($bloqueGuardarV, 'Auditoria::SET_MODIF') !== false);

chequear('saveDiasVencidosEcheq pide editar Parametros -> Cheques', [['parametros', 'PRECHEQUEADO']],
    AuthCashflow::ESCRITURAS['Parametros']['saveDiasVencidosEcheq']);

/* ================================================================
   El corrimiento al dia habil
   ================================================================ */
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/EjeVista.php';
require_once __DIR__ . '/../Class/Providers/EcheqsProvider.php';

/**
 * Un calendario armado a mano: de lunes a viernes habiles, salvo los feriados
 * que se pasen. Sabados y domingos no habiles. Los meses que no se cubren
 * quedan afuera, para probar el respaldo.
 */
function calendarioV($desde, $hasta, $feriados = []) {
    $m = [];

    for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $m[$d] = intval(date('N', strtotime($d))) <= 5 && !in_array($d, $feriados, true);
    }

    return $m;
}

// Septiembre a diciembre de 2026. Feriados: lunes 12/10, viernes 20/11,
// martes 08/12. Y uno que cae en sabado, el 26/12, para el motivo.
$CAL = calendarioV('2026-09-01', '2026-12-31', ['2026-10-12', '2026-11-20', '2026-12-08', '2026-12-26']);

seccion('correrAHabil: a que dia y por que');

$c = Echeqs::correrAHabil('2026-10-24', $CAL);
chequear('sabado -> lunes, por fin de semana', ['2026-10-26', true, Echeqs::CORRIDA_FIN_DE_SEMANA],
    [$c['fecha'], $c['corrida'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-10-25', $CAL);
chequear('domingo -> lunes', ['2026-10-26', Echeqs::CORRIDA_FIN_DE_SEMANA], [$c['fecha'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-12-08', $CAL);
chequear('un feriado -> el habil siguiente, por feriado', ['2026-12-09', Echeqs::CORRIDA_FERIADO],
    [$c['fecha'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-11-20', $CAL);
chequear('viernes feriado -> lunes, por feriado', ['2026-11-23', Echeqs::CORRIDA_FERIADO],
    [$c['fecha'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-12-26', $CAL);
chequear('un feriado en sabado se informa como fin de semana', ['2026-12-28', Echeqs::CORRIDA_FIN_DE_SEMANA],
    [$c['fecha'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-10-10', $CAL);
chequear('sabado con lunes feriado -> martes, por fin de semana', ['2026-10-13', Echeqs::CORRIDA_FIN_DE_SEMANA],
    [$c['fecha'], $c['motivo']]);
$c = Echeqs::correrAHabil('2026-10-21', $CAL);
chequear('un habil no se corre', ['2026-10-21', false, null, []],
    [$c['fecha'], $c['corrida'], $c['motivo'], $c['faltan']]);

$c = Echeqs::correrAHabil('2027-03-06', $CAL);
chequear('sin calendario rige el respaldo de lunes a viernes: sabado -> lunes', '2027-03-08', $c['fecha']);
chequear('y el mes queda para el aviso', ['2027-03'], $c['faltan']);

$ningunHabil = [];

for ($d = '2027-01-01'; $d <= '2027-03-31'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $ningunHabil[$d] = false;
}

$c = Echeqs::correrAHabil('2027-01-09', $ningunHabil);
chequear('sin habil en el tope el cheque queda en su fecha, con sin_habil', ['2027-01-09', false, true],
    [$c['fecha'], $c['corrida'], $c['sin_habil']]);

$c = Echeqs::correrAHabil('2026-10-24', $CAL, '2026-10-23');
chequear('despues del tope no se corre: el eje termina antes', ['2026-10-24', false], [$c['fecha'], $c['corrida']]);
$c = Echeqs::correrAHabil('2026-10-24', $CAL, '2026-10-24');
chequear('el dia del tope si se corre', '2026-10-26', $c['fecha']);

seccion('desde que fecha se lee la cartera');

chequear('un lunes, desde el sabado: el sabado y el domingo caen hoy', '2026-10-24',
    Echeqs::primerDiaCartera('2026-10-26', $CAL));
chequear('un martes despues de un lunes feriado, desde el sabado', '2026-10-10',
    Echeqs::primerDiaCartera('2026-10-13', $CAL));
chequear('un miercoles, desde hoy', '2026-10-21', Echeqs::primerDiaCartera('2026-10-21', $CAL));
chequear('el lunes despues de un viernes feriado, desde el viernes', '2026-11-20',
    Echeqs::primerDiaCartera('2026-11-23', $CAL));

seccion('el cheque del sabado, leido el lunes y el martes');

$r = Echeqs::resolverFechaCartera('2026-10-24', '2026-10-26', $CAL, '2027-09-30');
chequear('leido el lunes: VIGENTE, cae hoy', [Echeqs::CARTERA_VIGENTE, '2026-10-26', true],
    [$r['estado'], $r['fecha_eje'], $r['corrida']]);
$r = Echeqs::resolverFechaCartera('2026-10-24', '2026-10-27', $CAL, '2027-09-30');
chequear('leido el martes: su dia habil ya paso, no es VIGENTE', [false, null],
    [$r['estado'] === Echeqs::CARTERA_VIGENTE, $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-10', '2026-10-12', $CAL, '2027-09-30');
chequear('el sabado con lunes feriado, leido el lunes: cae el martes', [Echeqs::CARTERA_VIGENTE, '2026-10-13'],
    [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-10', '2026-10-13', $CAL, '2027-09-30');
chequear('leido el martes: cae hoy', [Echeqs::CARTERA_VIGENTE, '2026-10-13'], [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-21', '2026-10-21', $CAL, '2027-09-30');
chequear('el cheque de hoy es VIGENTE y cae hoy', [Echeqs::CARTERA_VIGENTE, '2026-10-21', false],
    [$r['estado'], $r['fecha_eje'], $r['corrida']]);
$r = Echeqs::resolverFechaCartera('1800-01-01', '2026-10-21', $CAL, '2027-09-30');
chequear('la centinela nunca es vigente', [Echeqs::CARTERA_FUERA_VENTANA, null], [$r['estado'], $r['fecha_eje']]);

seccion('la grilla: Fecha de pago la original, Acredita la corrida');

/** Una fila de cartera como las de getEcheqsCartera(), resuelta con el calendario de prueba */
function filaCarteraV($id, $fecha, $importe, $hoy, $extra = []) {
    global $CAL;

    $f = array_merge(['ID_SBA14' => $id, 'N_CHEQUE' => 7000 + $id, 'FECHA_CHEQUE' => $fecha,
                      'FECHA_PAGO' => $fecha, 'IMPORTE' => $importe, 'EXCLUIDO' => false,
                      'DEPOSITO' => null], $extra);
    $f['CARTERA'] = Echeqs::resolverFechaCartera($fecha, $hoy, $CAL, '2027-09-30');

    return Echeqs::ubicarEnEje($f);
}

$f = filaCarteraV(1, '2026-10-24', 1000.0, '2026-10-21');
chequear('el sabado: Fecha de pago sigue diciendo el sabado', '2026-10-24', $f['FECHA_PAGO']);
chequear('Acredita dice el lunes, y ahi cae el importe', ['2026-10-26', '2026-10-26', 1000.0],
    [$f['ACREDITA'], $f['FECHA_EJE'], $f['IMPORTE_EJE']]);
chequear('Retencion y Neto siguen vacias en cartera', [null, null], [$f['RETENCION'], $f['NETO']]);

$sinResolver = Echeqs::ubicarEnEje(['ID_SBA14' => 9, 'IMPORTE' => 50.0, 'FECHA_PAGO' => '2026-10-22',
                                    'DEPOSITO' => null]);
chequear('una fila de cartera sin resolver no cae en ninguna columna: no hay fecha de respaldo',
    [null, 0.0], [$sinResolver['FECHA_EJE'], $sinResolver['IMPORTE_EJE']]);

$dep = Echeqs::ubicarEnEje(['ID_SBA14' => 8, 'IMPORTE' => 100.0, 'FECHA_PAGO' => '2026-10-24',
    'CARTERA' => Echeqs::resolverFechaCartera('2026-10-24', '2026-10-21', $CAL, '2027-09-30'),
    'DEPOSITO' => ['estado' => Echeqs::DEPOSITO_PROYECTA, 'fecha_acreditacion' => '2026-10-23',
                   'bruto' => 100.0, 'neto' => 96.9]]);
chequear('a un depositado no se le aplica: cae en su acreditacion', ['2026-10-23', 96.9],
    [$dep['FECHA_EJE'], $dep['IMPORTE_EJE']]);

seccion('el tablero: cartera y excluidos corridos, la particion cierra');

$hV = new Horizonte(28, 12, [], new DateTime('2026-10-21'));

/** Un item agregado de getEcheqsCarteraTotales(), resuelto */
function itemV($fecha, $importe, $excluido, $hoy) {
    global $CAL;

    return ['FECHA_PAGO' => $fecha, 'EXCLUIDO' => $excluido, 'IMPORTE' => $importe, 'CHEQUES' => 1,
            'CARTERA' => Echeqs::resolverFechaCartera($fecha, $hoy, $CAL, '2027-09-30')];
}

$itemsV = [
    itemV('2026-10-24', 1000.0, false, '2026-10-21'),   // sabado -> lunes 26
    itemV('2026-10-25', 300.0, true, '2026-10-21'),     // domingo excluido -> lunes 26
    itemV('2026-11-20', 500.0, false, '2026-10-21'),    // viernes feriado -> lunes 23
    itemV('2026-10-22', 200.0, false, '2026-10-21')     // jueves, no se corre
];
$sV = EcheqsProvider::repartir($hV, $itemsV);

chequear('en el sabado y el domingo no queda nada', [0.0, 0.0],
    [floatval($sV['A_COBRAR_TODO']['dias']['2026-10-24']), floatval($sV['A_COBRAR_TODO']['dias']['2026-10-25'])]);
chequear('Echeqs en cartera: el sabado cae el lunes', 1000.0, $sV['A_COBRAR']['dias']['2026-10-26']);
chequear('Echeqs excluidos: el domingo tambien', 300.0, $sV['A_COBRAR_EXCLUIDOS']['dias']['2026-10-26']);
chequear('el feriado cae el lunes siguiente: en noviembre, fuera del tramo diario', 500.0, $sV['A_COBRAR']['meses']['2026-11']);
chequear('el habil queda en su dia', 200.0, $sV['A_COBRAR']['dias']['2026-10-22']);
chequear('el total no cambia: solo cambia de columna', 2000.0,
    array_sum($sV['A_COBRAR_TODO']['dias']) + array_sum($sV['A_COBRAR_TODO']['meses']));

/** Si las tres partes suman el total en cada columna */
function cierraPorColumnaV($s) {
    foreach (['dias', 'meses'] as $rama) {
        foreach ($s['A_COBRAR_TODO'][$rama] as $k => $v) {
            if (abs($v - ($s['A_COBRAR'][$rama][$k] + $s['A_COBRAR_EXCLUIDOS'][$rama][$k]
                    + $s['DEPOSITADOS'][$rama][$k])) > 0.001) {
                return false;
            }
        }
    }

    return true;
}

chequear('la particion cierra columna por columna', true, cierraPorColumnaV($sV));

// La pestana arma el eje con FECHA_EJE: las mismas fechas, la misma regla.
$filasV = [
    filaCarteraV(1, '2026-10-24', 1000.0, '2026-10-21'),
    filaCarteraV(2, '2026-10-25', 300.0, '2026-10-21', ['EXCLUIDO' => true]),
    filaCarteraV(3, '2026-11-20', 500.0, '2026-10-21'),
    filaCarteraV(4, '2026-10-22', 200.0, '2026-10-21')
];
$payloadV = EjeVista::armar($hV, $filasV, 'FECHA_EJE', 'IMPORTE_EJE');
$porFecha = [];

foreach ($payloadV['filas'] as $fv) {
    $porFecha[$fv['ACREDITA']] = (isset($porFecha[$fv['ACREDITA']]) ? $porFecha[$fv['ACREDITA']] : 0)
        + $fv['IMPORTE_EJE'];
}

chequear('la pestana pone cada importe en la misma columna que el tablero',
    [$sV['A_COBRAR_TODO']['dias']['2026-10-26'], $sV['A_COBRAR_TODO']['meses']['2026-11']],
    [$porFecha['2026-10-26'], $porFecha['2026-11-23']]);
chequear('y suma lo mismo',
    round(array_sum($sV['A_COBRAR_TODO']['dias']) + array_sum($sV['A_COBRAR_TODO']['meses']), 2),
    round($payloadV['totales']['total_horizonte'], 2));

seccion('los avisos del corrimiento');

$a = Echeqs::avisosCartera([itemV('2027-03-06', 100.0, false, '2026-10-21')]);
chequear('el calendario faltante avisa con nivel de atencion, una vez por mes', [1, 'warning'],
    [count($a), $a[0]['nivel']]);
chequear('con el texto de siempre', true,
    strpos($a[0]['texto'], 'RO_T_CALENDARIO no tiene datos para 2027-03') !== false);

$sinHab = [['IMPORTE' => 700.0, 'CHEQUES' => 2,
            'CARTERA' => Echeqs::resolverFechaCartera('2027-01-09', '2026-10-21', $ningunHabil, '2027-09-30')]];
$a = Echeqs::avisosCartera($sinHab);
chequear('sin habil en el tope avisa como critico, con el texto de DiasHabiles', ['danger', true],
    [$a[0]['nivel'], strpos($a[0]['texto'], DiasHabiles::avisoSinHabil('2027-01-09')) !== false]);
chequear('y el cheque sigue sumando en su fecha: no se inventa otra', '2027-01-09',
    $sinHab[0]['CARTERA']['fecha_eje']);
chequear('lo que no se lista no avisa', [],
    Echeqs::avisosCartera([['IMPORTE' => 1.0, 'CARTERA' => Echeqs::resolverFechaCartera('2027-01-09',
        '2027-02-20', $ningunHabil, '2027-09-30')]]));

$srcProvV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Providers/EcheqsProvider.php'));
$ctrlV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/EcheqsController.php'));
chequear('el proveedor y la pestana dejan los mismos avisos del corrimiento', [1, 1], [
    substr_count($srcProvV, '$this->avisarTodos(Echeqs::avisosCartera($items));'),
    substr_count($ctrlV, 'foreach (Echeqs::avisosCartera($cartera) as $a) {')
]);
chequear('el calendario se lee por la unica lectura del modulo', true,
    strpos($srcEcheqsV, 'return (new Ventas())->getDiasHabiles($desde, $hasta);') !== false);
