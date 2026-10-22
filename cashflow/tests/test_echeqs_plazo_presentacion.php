<?php
/**
 * Echeqs: los cheques en plazo de presentacion, los vencidos y el corrimiento
 * al dia habil.
 *
 * Lo delicado son CRITERIOS: que esta en plazo de presentacion y que esta
 * vencido -la Ley de Cheques da 30 dias para presentar un cheque: uno cuyo
 * dia paso NO esta vencido-, que fecha se puede asignar, donde cae cada cheque
 * y en que serie. Viven en
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
seccion('el script de la fecha asignada y el plazo de presentacion');

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
chequear('siembra el plazo de presentacion en 30, en el modulo PRECHEQUEADO', true,
    strpos($SQL_VENC, "''echeqs_dias_vencidos_cartera'', ''30''") !== false
    && strpos($SQL_VENC, "('echeqs_dias_vencidos_cartera', '30'") !== false
    && strpos($SQL_VENC, "''DEPOSITO'', ''PRECHEQUEADO''") !== false);
chequear('y si ya existe no le pisa el valor', true,
    strpos($SQL_VENC, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'echeqs_dias_vencidos_cartera')") !== false
    && strpos($SQL_VENC, 'UPDATE dbo.RO_T_CASHFLOW_PARAMETROS') === false);
chequear('controla que el plazo sea de 1 a 30', 1,
    substr_count($SQL_VENC, 'NOT BETWEEN 1 AND 30'));
chequear('Echeqs nombra la tabla y el script del archivo', [true, true], [
    strpos($SQL_VENC, Echeqs::TABLA_FECHA_ACREDITACION) !== false,
    file_exists(__DIR__ . '/../' . Echeqs::SCRIPT_PLAZO_PRESENTACION)
]);

/* ================================================================
   El plazo de presentacion: Parametros -> Cheques
   ================================================================ */
seccion('el plazo de presentacion');

chequear('1 es un plazo valido', 1, Echeqs::validarPlazoPresentacion('1'));
chequear('30 tambien: el plazo legal', 30, Echeqs::validarPlazoPresentacion(30));
chequearLanza('cero no', function () { Echeqs::validarPlazoPresentacion(0); });
chequearLanza('31 no', function () { Echeqs::validarPlazoPresentacion('31'); });
chequearLanza('vacia no', function () { Echeqs::validarPlazoPresentacion(''); });
chequearLanza('un texto no', function () { Echeqs::validarPlazoPresentacion('treinta'); });
chequearLanza('ni un decimal', function () { Echeqs::validarPlazoPresentacion('10.5'); });

$v = Echeqs::plazoPresentacionDesdeValor(null);
chequear('sin el parametro, el plazo es null: no hay un 30 de respaldo', null, $v['dias']);
chequear('y el motivo nombra el script', true, strpos($v['error'], Echeqs::SCRIPT_PLAZO_PRESENTACION) !== false);

$v = Echeqs::plazoPresentacionDesdeValor('40');
chequear('un plazo roto en la base tambien es null', null, $v['dias']);
chequear('y dice donde corregirla', true, strpos($v['error'], 'Parámetros → Cheques') !== false);

chequear('un plazo bien guardado se usa', 10, Echeqs::plazoPresentacionDesdeValor('10')['dias']);

chequear('Parametros y Echeqs nombran la misma clave del plazo de presentacion',
    Echeqs::CLAVE_PLAZO_PRESENTACION, Parametros::CLAVE_PLAZO_PRESENTACION_ECHEQS);

$srcParametrosV = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro() no guarda el plazo de presentacion: tiene su propio camino, que valida', true,
    strpos($srcParametrosV, 'if ($clave === self::CLAVE_PLAZO_PRESENTACION_ECHEQS)') !== false);

$bloqueGuardarV = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function guardarPlazoPresentacion('), 1400);
chequear('guardarPlazoPresentacion() valida y escribe con el usuario de escritura', true,
    strpos($bloqueGuardarV, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);') !== false
    && strpos($bloqueGuardarV, '$dias = self::validarPlazoPresentacion($dias);') !== false
    && strpos($bloqueGuardarV, 'Auditoria::SET_MODIF') !== false);

chequear('savePlazoPresentacionEcheq pide editar Parametros -> Cheques', [['parametros', 'PRECHEQUEADO']],
    AuthCashflow::ESCRITURAS['Parametros']['savePlazoPresentacionEcheq']);

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
chequear('la centinela nunca es vigente', [Echeqs::CARTERA_NO_SE_LEE, null], [$r['estado'], $r['fecha_eje']]);

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
    substr_count($ctrlV, 'foreach (array_merge(Echeqs::avisosCartera($cartera), $echeqs->avisosPlazoPresentacion($h)) as $a) {')
]);
chequear('el calendario se lee por la unica lectura del modulo', true,
    strpos($srcEcheqsV, 'return (new Ventas())->getDiasHabiles($desde, $hasta);') !== false);

/* ================================================================
   En plazo de presentacion y vencidos
   ================================================================ */
$HOYV = '2026-10-21';   // miercoles
$FINV = '2027-09-30';

seccion('en plazo de presentacion o vencido: el plazo');

/* NO ESTA VENCIDO UN CHEQUE CUYO DIA PASO: la Ley de Cheques da 30 dias
   corridos desde su fecha para presentarlo. "Vencido" es solo el que supero
   el plazo. */
$r = Echeqs::resolverFechaCartera('2026-10-20', $HOYV, $CAL, $FINV, null, 30);
chequear('un cheque de ayer esta en plazo de presentacion, sin fecha', [Echeqs::CARTERA_EN_PLAZO_SIN_FECHA, null, 1],
    [$r['estado'], $r['fecha_eje'], $r['dias_desde_fecha']]);
$r = Echeqs::resolverFechaCartera('2026-09-21', $HOYV, $CAL, $FINV, null, 30);
chequear('uno de hace exactamente N dias sigue en plazo: el plazo vence hoy', [Echeqs::CARTERA_EN_PLAZO_SIN_FECHA, '2026-10-21'],
    [$r['estado'], $r['plazo_hasta']]);
$r = Echeqs::resolverFechaCartera('2026-09-20', $HOYV, $CAL, $FINV, null, 30);
chequear('uno de hace N + 1 dias es VENCIDO: supero el plazo', [Echeqs::CARTERA_VENCIDO, null, '2026-10-20'],
    [$r['estado'], $r['fecha_eje'], $r['plazo_hasta']]);
$r = Echeqs::resolverFechaCartera('1800-01-01', $HOYV, $CAL, $FINV, null, 30);
chequear('la centinela no se lee: no es un vencido de 1800', Echeqs::CARTERA_NO_SE_LEE, $r['estado']);
$r = Echeqs::resolverFechaCartera($HOYV, $HOYV, $CAL, $FINV, null, 30);
chequear('el de hoy no esta en plazo: es vigente y cae hoy', [Echeqs::CARTERA_VIGENTE, $HOYV], [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-20', $HOYV, $CAL, $FINV, null, null);
chequear('sin plazo -sin el script o con el parametro roto- no se puede clasificar: no se lee',
    Echeqs::CARTERA_NO_SE_LEE, $r['estado']);
$r = Echeqs::resolverFechaCartera('2026-10-20', $HOYV, $CAL, $FINV, null, 10);
chequear('con un plazo de 10, el de ayer esta en plazo', Echeqs::CARTERA_EN_PLAZO_SIN_FECHA, $r['estado']);
$r = Echeqs::resolverFechaCartera('2026-10-10', $HOYV, $CAL, $FINV, null, 10);
chequear('y uno de hace 11 dias, vencido', Echeqs::CARTERA_VENCIDO, $r['estado']);

seccion('los vencidos se leen sin limite, y no se corren');

$r = Echeqs::resolverFechaCartera('2024-03-02', $HOYV, $CAL, $FINV, null, 30);
chequear('un sabado de 2024 que sigue en cartera es VENCIDO', Echeqs::CARTERA_VENCIDO, $r['estado']);
chequear('sin correrse ni pedir calendario de 2024: no avisa un calendario faltante', [false, []],
    [$r['corrida'], $r['faltan']]);
chequear('un vencido no suma ni avisa calendario', [], array_filter(Echeqs::avisosCartera([
    ['IMPORTE' => 10.0, 'CARTERA' => $r]]), function ($a) { return strpos($a['texto'], 'RO_T_CALENDARIO') !== false; }));
$r = Echeqs::resolverFechaCartera('2026-09-15', $HOYV, $CAL, $FINV, '2026-10-23', 30);
chequear('a un vencido no le aplica la fecha asignada: no suma, y la fecha queda para el tooltip',
    [Echeqs::CARTERA_VENCIDO, null, '2026-10-23'], [$r['estado'], $r['fecha_eje'], $r['fecha_asignada']]);
$srcCtxV = substr($srcEcheqsV, strpos($srcEcheqsV, 'private function contextoCartera('), 1800);
chequear('con plazo, la lectura arranca en la centinela: sin limite hacia atras', true,
    strpos($srcCtxV, ": self::FECHA_REAL_DESDE;") !== false);

seccion('el sabado leido el lunes, el martes, y el sabado con lunes feriado');

$r = Echeqs::resolverFechaCartera('2026-10-24', '2026-10-26', $CAL, $FINV, null, 30);
chequear('el sabado leido el lunes cae hoy: VIGENTE', [Echeqs::CARTERA_VIGENTE, '2026-10-26'],
    [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-24', '2026-10-27', $CAL, $FINV, null, 30);
chequear('leido el martes esta en plazo de presentacion, sin fecha', [Echeqs::CARTERA_EN_PLAZO_SIN_FECHA, null],
    [$r['estado'], $r['fecha_eje']]);
chequear('y pasaron 3 dias, contados desde la fecha escrita', 3, $r['dias_desde_fecha']);
$r = Echeqs::resolverFechaCartera('2026-10-10', '2026-10-12', $CAL, $FINV, null, 30);
chequear('el sabado con lunes feriado, leido el lunes, sigue vigente: cae el martes',
    [Echeqs::CARTERA_VIGENTE, '2026-10-13'], [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-10', '2026-10-14', $CAL, $FINV, null, 30);
chequear('y leido el miercoles, si', Echeqs::CARTERA_EN_PLAZO_SIN_FECHA, $r['estado']);
chequear('sin plazo, la lectura de un martes arranca en el martes: el sabado ya no cae hoy', '2026-10-27',
    Echeqs::primerDiaCartera('2026-10-27', $CAL));

seccion('la fecha asignada: cuando suma y cuando no');

$r = Echeqs::resolverFechaCartera('2026-10-15', $HOYV, $CAL, $FINV, '2026-10-23', 30);
chequear('con fecha de manana en adelante suma en esa fecha', [Echeqs::CARTERA_EN_PLAZO_CON_FECHA, '2026-10-23'],
    [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-15', $HOYV, $CAL, $FINV, $HOYV, 30);
chequear('una fecha asignada igual a hoy suma hoy', [Echeqs::CARTERA_EN_PLAZO_CON_FECHA, $HOYV],
    [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-10-15', $HOYV, $CAL, $FINV, '2026-10-20', 30);
chequear('una fecha asignada anterior a hoy no suma: FECHA_PASADA', [Echeqs::CARTERA_FECHA_PASADA, null],
    [$r['estado'], $r['fecha_eje']]);
$r = Echeqs::resolverFechaCartera('2026-09-15', $HOYV, $CAL, $FINV, '2026-10-23', 30);
chequear('pasado el plazo la fecha asignada deja de aplicar: es vencido', Echeqs::CARTERA_VENCIDO,
    $r['estado']);
$r = Echeqs::resolverFechaCartera('2026-10-28', $HOYV, $CAL, $FINV, '2026-10-23', 30);
chequear('a un vigente no se le aplica una fecha asignada', [Echeqs::CARTERA_VIGENTE, '2026-10-28'],
    [$r['estado'], $r['fecha_eje']]);

$fv = filaCarteraEnPlazo(30, '2026-10-15', 900.0, $HOYV, '2026-10-24');
chequear('la fecha asignada no se corre: si llegara un sabado, queda el sabado', '2026-10-24', $fv['FECHA_EJE']);

/** Una fila de cartera con fecha asignada, resuelta con el calendario de prueba y plazo 30 */
function filaCarteraEnPlazo($id, $fecha, $importe, $hoy, $asignada = null, $extra = []) {
    global $CAL;

    $f = array_merge(['ID_SBA14' => $id, 'N_CHEQUE' => 8000 + $id, 'FECHA_CHEQUE' => $fecha,
                      'FECHA_PAGO' => $fecha, 'IMPORTE' => $importe, 'EXCLUIDO' => false,
                      'DEPOSITO' => null], $extra);
    $f['CARTERA'] = Echeqs::resolverFechaCartera($fecha, $hoy, $CAL, '2027-09-30', $asignada, 30);

    return Echeqs::ubicarEnEje($f);
}

seccion('la fecha que se puede asignar');

$ok = Echeqs::validarFechaAsignada('2026-10-22', '2026-10-15', $HOYV, $FINV, 30, $CAL);
chequear('manana, habil: si', ['2026-10-22', []], [$ok['fecha'], $ok['faltan']]);
chequearLanza('hoy no', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada($HOYV, '2026-10-15', $HOYV, $FINV, 30, $CAL);
}, 'La fecha de acreditación tiene que ser de mañana en adelante: el 21/10 es hoy.');
chequearLanza('ayer no', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada('2026-10-20', '2026-10-15', $HOYV, $FINV, 30, $CAL);
}, 'La fecha de acreditación tiene que ser de mañana en adelante: el 20/10 ya pasó.');
chequearLanza('un sabado no, y dice por que', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada('2026-10-24', '2026-10-15', $HOYV, $FINV, 30, $CAL);
}, 'El 24/10 es sábado: elegí un día hábil.');
chequearLanza('un feriado no', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada('2026-11-20', '2026-11-15', $HOYV, $FINV, 30, $CAL);
}, 'El 20/11 es feriado: elegí un día hábil.');
chequearLanza('posterior al fin del horizonte no, y dice que tope aplico', function () use ($HOYV, $CAL) {
    Echeqs::validarFechaAsignada('2026-12-02', '2026-11-15', $HOYV, '2026-11-30', 30, $CAL);
}, 'El 02/12 es posterior al fin del horizonte (30/11): no hay columna donde sumarlo.');
chequearLanza('posterior al ultimo dia del plazo de presentacion no, y dice que tope aplico',
    function () use ($HOYV, $FINV, $CAL) {
        Echeqs::validarFechaAsignada('2026-11-16', '2026-10-15', $HOYV, $FINV, 30, $CAL);
    }, 'El 16/11 es posterior al 14/11, el último día del plazo de presentación del cheque (su fecha '
        . 'más 30 días): después está vencido, ya no se puede depositar, y esa fecha no llegaría a sumar.');
chequear('el ultimo dia en que se muestra si se acepta, si es habil', '2026-11-13',
    Echeqs::validarFechaAsignada('2026-11-13', '2026-10-14', $HOYV, $FINV, 30, $CAL)['fecha']);
chequearLanza('un texto no es una fecha', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada('22/10/2026', '2026-10-15', $HOYV, $FINV, 30, $CAL);
}, 'La fecha de acreditación no es una fecha válida.');
chequearLanza('ni el 31 de noviembre', function () use ($HOYV, $FINV, $CAL) {
    Echeqs::validarFechaAsignada('2026-11-31', '2026-10-15', $HOYV, $FINV, 30, $CAL);
});

$ok = Echeqs::validarFechaAsignada('2027-01-05', '2026-12-20', '2027-01-04', $FINV, 30, $CAL);
chequear('un mes sin calendario: respaldo de lunes a viernes, y el mes para el aviso',
    ['2027-01-05', ['2027-01']], [$ok['fecha'], $ok['faltan']]);
chequearLanza('y con el respaldo, un sabado sigue sin servir', function () use ($FINV, $CAL) {
    Echeqs::validarFechaAsignada('2027-01-09', '2026-12-20', '2027-01-04', $FINV, 30, $CAL);
}, 'El 09/01 es sábado: elegí un día hábil.');

seccion('asignar en masa es todo o nada');

$filasSel = [
    filaCarteraEnPlazo(1, '2026-10-15', 100.0, $HOYV),                     // en plazo sin fecha
    filaCarteraEnPlazo(2, '2026-10-16', 200.0, $HOYV, '2026-10-23'),       // en plazo con fecha
    filaCarteraEnPlazo(3, '2026-10-28', 300.0, $HOYV),                     // vigente
    filaCarteraEnPlazo(4, '2026-10-01', 400.0, $HOYV, '2026-10-20'),       // fecha pasada
    ['ID_SBA14' => 5, 'N_CHEQUE' => 8005, 'IMPORTE' => 500.0,
     'DEPOSITO' => ['estado' => Echeqs::DEPOSITO_PROYECTA]],             // depositado
    filaCarteraEnPlazo(6, '2026-09-01', 600.0, $HOYV)                      // vencido
];

$v = Echeqs::validarSeleccionEnPlazo([1, 2, 4], $filasSel);
chequear('sin fecha, con fecha y fecha pasada se aceptan', [null, [1, 2, 4]],
    [$v['error'], array_column($v['filas'], 'ID_SBA14')]);

$v = Echeqs::validarSeleccionEnPlazo([1, 3], $filasSel);
chequear('con un vigente en la seleccion no se acepta ninguno', [], $v['filas']);
chequear('y el mensaje dice cual y por que', true,
    strpos($v['error'], 'N° 8003 no está en plazo de presentación') !== false);
$v = Echeqs::validarSeleccionEnPlazo([1, 6], $filasSel);
chequear('con un vencido tampoco: ya no se puede depositar', [[], true],
    [$v['filas'], strpos($v['error'], 'N° 8006 está vencido: superó su plazo de presentación') !== false]);
$v = Echeqs::validarSeleccionEnPlazo([1, 5], $filasSel);
chequear('con un depositado tampoco', [[], true],
    [$v['filas'], strpos($v['error'], 'N° 8005 está depositado en Tango') !== false]);
$v = Echeqs::validarSeleccionEnPlazo([1, 99], $filasSel);
chequear('ni con uno que no esta en la lectura', [[], true],
    [$v['filas'], strpos($v['error'], 'N° 99 no está en cartera') !== false]);
chequear('vacio, tampoco', 'No llegó ningún cheque.', Echeqs::validarSeleccionEnPlazo([], $filasSel)['error']);

// El tope de cada cheque es SU fecha + N: una fecha puede servir a uno y no a otro.
$dos = [filaCarteraEnPlazo(6, '2026-10-01', 10.0, $HOYV), filaCarteraEnPlazo(7, '2026-10-19', 20.0, $HOYV)];
$vf = Echeqs::validarFechaParaSeleccion('2026-11-02', $dos, $HOYV, $FINV, 30, $CAL);
chequear('una fecha que no sirve para uno no se graba para ninguno', null, $vf['fecha']);
chequear('y el mensaje nombra al cheque', true, strpos($vf['error'], 'N° 8006 El 02/11 es posterior al 31/10') !== false);
$vf = Echeqs::validarFechaParaSeleccion('2026-10-24', $dos, $HOYV, $FINV, 30, $CAL);
chequear('si no sirve para ninguno por el mismo motivo, se dice una vez',
    'No se asignó ninguna fecha: El 24/10 es sábado: elegí un día hábil.', $vf['error']);
$vf = Echeqs::validarFechaParaSeleccion('2026-10-29', $dos, $HOYV, $FINV, 30, $CAL);
chequear('una que sirve para todos pasa', ['2026-10-29', null], [$vf['fecha'], $vf['error']]);

seccion('deshacer y lo que se graba');

chequear('deshacer exige que todos tengan fecha vigente', [[], true], (function () {
    $d = Echeqs::validarDeshacerFecha([2, 1], [2 => ['id' => 50]]);

    return [$d['ids'], strpos($d['error'], '1 de los 2') !== false];
})());
chequear('con todos, pasan todos', [2, 4], Echeqs::validarDeshacerFecha([2, 4], [2 => [], 4 => []])['ids']);
chequear('6 parametros por cheque: id, fecha, observacion, la que reemplaza y el usuario dos veces',
    [1, '2026-10-29', 'obs', null, 'rorozco', 'rorozco', 2, '2026-10-29', 'obs', 50, 'rorozco', 'rorozco'],
    Echeqs::paramsInsertFecha([1, 2], '2026-10-29', 'obs', [2 => 50], 'rorozco'));

$srcAsignar = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function asignarFechaEnPlazo('), 2600);
chequear('asignar valida todo antes de escribir, contra la lectura de hoy', true,
    strpos($srcAsignar, 'validarSeleccionEnPlazo($ids, array_merge($this->getEcheqsCartera($h), $dep[\'filas\']))') !== false
    && strpos($srcAsignar, 'validarFechaParaSeleccion(') < strpos($srcAsignar, '$this->escribirFechas(')
    && strpos($srcAsignar, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);') !== false);
$srcEscribir = substr($srcEcheqsV, strpos($srcEcheqsV, 'protected function escribirFechas('), 2000);
chequear('reasignar da de baja la vigente antes de insertar, en la misma transaccion', true,
    strpos($srcEscribir, 'sqlsrv_begin_transaction') < strpos($srcEscribir, '$this->bajaFechas(')
    && strpos($srcEscribir, '$this->bajaFechas(') < strpos($srcEscribir, 'INSERT INTO')
    && strpos($srcEscribir, 'sqlsrv_rollback') !== false);
$srcBaja = substr($srcEcheqsV, strpos($srcEcheqsV, 'private function bajaFechas('), 900);
chequear('la baja es logica, con quien y cuando', [true, false], [
    strpos($srcBaja, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA . "') !== false,
    stripos($srcBaja, 'DELETE') !== false
]);
$m = new ReflectionMethod('Echeqs', 'asignarFechaEnPlazo');
chequear('del navegador llegan solo ids, fecha y observacion', ['ids', 'fecha', 'observacion', 'usuario', 'h'],
    array_map(function ($p) { return $p->getName(); }, $m->getParameters()));

foreach (['asignarFechaEnPlazo', 'deshacerFechaEnPlazo'] as $accion) {
    chequear($accion . ' pide editar Echeqs, como excluir', [['echeqs', null]],
        AuthCashflow::ESCRITURAS['Echeqs'][$accion]);
}

seccion('el tablero: en plazo y vencidos en la particion');

$itemsVenc = [
    ['FECHA_PAGO' => '2026-10-15', 'EXCLUIDO' => false, 'IMPORTE' => 1000.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-10-15', $HOYV, $CAL, $FINV, '2026-10-23', 30)],
    ['FECHA_PAGO' => '2026-10-16', 'EXCLUIDO' => false, 'IMPORTE' => 700.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-10-16', $HOYV, $CAL, $FINV, null, 30)],
    ['FECHA_PAGO' => '2026-10-17', 'EXCLUIDO' => true, 'IMPORTE' => 50.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-10-17', $HOYV, $CAL, $FINV, null, 30)],
    ['FECHA_PAGO' => '2026-10-18', 'EXCLUIDO' => true, 'IMPORTE' => 80.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-10-18', $HOYV, $CAL, $FINV, '2026-10-26', 30)],
    ['FECHA_PAGO' => '2026-10-02', 'EXCLUIDO' => false, 'IMPORTE' => 30.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-10-02', $HOYV, $CAL, $FINV, '2026-10-20', 30)],
    itemV('2026-10-22', 200.0, false, $HOYV),
    ['FECHA_PAGO' => '2026-09-01', 'EXCLUIDO' => false, 'IMPORTE' => 900.0, 'CHEQUES' => 1,
     'CARTERA' => Echeqs::resolverFechaCartera('2026-09-01', $HOYV, $CAL, $FINV, null, 30)]
];
$sVe = EcheqsProvider::repartir($hV, $itemsVenc);

chequear('vencido con fecha: A_COBRAR en esa fecha, por el bruto', 1000.0, $sVe['A_COBRAR']['dias']['2026-10-23']);
chequear('vencido sin fecha: ninguna serie, ni A_COBRAR_TODO', [], EcheqsProvider::seriesDeItem($itemsVenc[1]));
chequear('vencido excluido sin fecha: ninguna serie', [], EcheqsProvider::seriesDeItem($itemsVenc[2]));
chequear('vencido excluido con fecha: A_COBRAR_EXCLUIDOS en esa fecha', 80.0,
    $sVe['A_COBRAR_EXCLUIDOS']['dias']['2026-10-26']);
chequear('fecha pasada: ninguna serie', [], EcheqsProvider::seriesDeItem($itemsVenc[4]));
chequear('vencido: ninguna serie, ni A_COBRAR_TODO', [], EcheqsProvider::seriesDeItem($itemsVenc[6]));
chequear('el total es lo que suma: 1000 + 80 + 200', 1280.0,
    array_sum($sVe['A_COBRAR_TODO']['dias']) + array_sum($sVe['A_COBRAR_TODO']['meses'])
    + $sVe['A_COBRAR_TODO']['fuera_horizonte'] + $sVe['A_COBRAR_TODO']['sin_fecha']);
chequear('y la particion cierra columna por columna', true, cierraPorColumnaV($sVe));

$itemFuera = ['FECHA_PAGO' => '2026-10-15', 'EXCLUIDO' => false, 'IMPORTE' => 60.0, 'CHEQUES' => 1,
              'CARTERA' => Echeqs::resolverFechaCartera('2026-10-15', $HOYV, $CAL, $FINV, '2026-11-12', 30)];
$sCorto = EcheqsProvider::repartir(new Horizonte(5, 1, [], new DateTime($HOYV)), [$itemFuera]);
chequear('una fecha asignada fuera del eje -se achico el horizonte- va a fuera_horizonte', 60.0,
    $sCorto['A_COBRAR_TODO']['fuera_horizonte']);

$aV = Echeqs::avisosCartera($itemsVenc);
$textosV = implode(' | ', array_column($aV, 'texto'));
chequear('el aviso de en plazo sin fecha: atencion, cuantos y por cuanto, sin el excluido', true,
    strpos($textosV, '1 cheque(s) en plazo de presentación sin fecha de acreditación por $ 700,00 no se '
        . 'proyectan. Asignales una fecha en Ingresos › Echeqs.') !== false);
chequear('el de la fecha pasada, aparte', true,
    strpos($textosV, '1 cheque(s) en plazo de presentación por $ 30,00 tenían fecha de acreditación ya '
        . 'pasada y siguen en cartera: reasignales la fecha') !== false);
chequear('el de los vencidos, aparte: no se pueden presentar y hay que resolverlos en Tango', true,
    strpos($textosV, '1 cheque(s) vencidos por $ 900,00 superaron el plazo de presentación y siguen en '
        . 'cartera en Tango') !== false);
chequear('los tres son de atencion', ['warning', 'warning', 'warning'], array_column(array_slice($aV, 0, 3), 'nivel'));
chequear('ningun aviso llama vencido a un cheque en plazo', 0,
    substr_count($textosV, 'vencidos en cartera sin fecha'));

seccion('la pestana y el proveedor suman lo mismo, con cheques en plazo y vencidos');

$filasVe = [
    filaCarteraEnPlazo(11, '2026-10-15', 1000.0, $HOYV, '2026-10-23'),
    filaCarteraEnPlazo(12, '2026-10-16', 700.0, $HOYV),
    filaCarteraEnPlazo(13, '2026-10-17', 50.0, $HOYV, null, ['EXCLUIDO' => true]),
    filaCarteraEnPlazo(14, '2026-10-18', 80.0, $HOYV, '2026-10-26', ['EXCLUIDO' => true]),
    filaCarteraEnPlazo(15, '2026-10-02', 30.0, $HOYV, '2026-10-20'),
    filaCarteraV(16, '2026-10-22', 200.0, $HOYV),
    filaCarteraEnPlazo(17, '2026-09-01', 900.0, $HOYV)
];
$pVe = EjeVista::armar($hV, $filasVe, 'FECHA_EJE', 'IMPORTE_EJE');

chequear('el vencido no cae en ninguna columna, ni tiene Acredita', [null, 0.0, null],
    [$filasVe[6]['FECHA_EJE'], $filasVe[6]['IMPORTE_EJE'], $filasVe[6]['ACREDITA']]);
chequear('el en plazo sin fecha no cae en ninguna columna', [null, 0.0],
    [$filasVe[1]['FECHA_EJE'], $filasVe[1]['IMPORTE_EJE']]);
chequear('el con fecha cae en su fecha y la muestra en Acredita', ['2026-10-23', '2026-10-23'],
    [$filasVe[0]['FECHA_EJE'], $filasVe[0]['ACREDITA']]);
chequear('el de fecha pasada no muestra fecha en Acredita', null, $filasVe[4]['ACREDITA']);
chequear('la grilla entera es A_COBRAR_TODO', round(array_sum($sVe['A_COBRAR_TODO']['dias'])
    + array_sum($sVe['A_COBRAR_TODO']['meses']), 2), round($pVe['totales']['total_horizonte'], 2));
chequear('y los avisos son los mismos', Echeqs::avisosCartera($itemsVenc), Echeqs::avisosCartera($filasVe));

$rv = Echeqs::resumenEnPlazo($pVe['filas']);
chequear('la tarjeta: en plazo sin los excluidos, con y sin fecha', [3, 1730.0, 1, 1000.0, 2, 730.0],
    [$rv['cheques'], $rv['bruto'], $rv['con_fecha']['cheques'], $rv['con_fecha']['bruto'],
     $rv['sin_fecha']['cheques'], $rv['sin_fecha']['bruto']]);
chequear('los con fecha traen lo que suman en la vista', 1000.0, $rv['con_fecha']['total_horizonte']);
chequear('el interruptor esconde los sin fecha, no los de fecha pasada', [1, 700.0, 1, 30.0],
    [$rv['ocultables']['cheques'], $rv['ocultables']['bruto'], $rv['pasada']['cheques'], $rv['pasada']['bruto']]);
chequear('los vencidos van aparte, fuera de los en plazo', [1, 900.0],
    [$rv['vencidos']['cheques'], $rv['vencidos']['bruto']]);

$netosVe = Echeqs::totalesNetos(Echeqs::totalesNetos($pVe['totales'], Echeqs::resumenExcluidos($pVe['filas'])),
    Echeqs::resumenDepositos($pVe['filas']));
chequear('las tres tarjetas de cartera son "Echeqs en cartera": incluyen los en plazo con fecha',
    round(array_sum($sVe['A_COBRAR']['dias']) + array_sum($sVe['A_COBRAR']['meses']), 2),
    round($netosVe['total_horizonte'], 2));

seccion('un vencido con fecha que se deposita en Tango');

$depVe = Echeqs::ubicarEnEje(array_merge(filaCarteraEnPlazo(20, '2026-10-15', 1000.0, $HOYV, '2026-10-29'), [
    'DEPOSITO' => ['estado' => Echeqs::DEPOSITO_PROYECTA, 'fecha_acreditacion' => '2026-10-23',
                   'bruto' => 1000.0, 'neto' => 969.0]]));
chequear('entra en depositados con la regla de las 48 hs: la fecha asignada no interviene',
    ['2026-10-23', 969.0, null], [$depVe['FECHA_EJE'], $depVe['IMPORTE_EJE'], $depVe['ESTADO_CARTERA']]);
chequear('y el proveedor lo pone en DEPOSITADOS', ['A_COBRAR_TODO', 'DEPOSITADOS'],
    EcheqsProvider::seriesDeItem(['DEPOSITO' => ['estado' => Echeqs::DEPOSITO_PROYECTA],
        'CARTERA' => ['estado' => Echeqs::CARTERA_EN_PLAZO_CON_FECHA, 'fecha_eje' => '2026-10-29']]));

/* ================================================================
   Sin el script, y con el plazo de presentacion roto
   ================================================================ */
seccion('sin el script: no se leen, y el resto anda igual');

/**
 * Echeqs con la tabla y el plazo de presentacion inyectados, y el calendario de prueba: no
 * lee ni escribe nada.
 */
class EcheqsSinScriptPrueba extends Echeqs {
    public $tabla = false;
    public $plazoPres = ['dias' => null, 'error' => null];

    public function fechaAcreditacionCreada() {
        return $this->tabla;
    }

    public function getPlazoPresentacion() {
        return $this->plazoPres;
    }

    protected function diasHabiles($desde, $hasta) {
        global $CAL;

        return $CAL;
    }
}

$sinScript = new EcheqsSinScriptPrueba();
$aSin = $sinScript->avisosPlazoPresentacion($hV);
chequear('la pestana avisa con nivel de atencion y nombra el script', [1, 'warning', true],
    [count($aSin), $aSin[0]['nivel'], strpos($aSin[0]['texto'], Echeqs::SCRIPT_PLAZO_PRESENTACION) !== false]);
chequear('el tablero no dice nada', [], $sinScript->avisosPlazoPresentacion($hV, true));
chequear('no se pueden asignar fechas: el selector no se dibuja', [false, null],
    [$sinScript->datosEnPlazo($hV)['creada'], $sinScript->datosEnPlazo($hV)['plazo_presentacion']]);
chequear('y getFechasAsignadas() no lee nada', ['vigentes' => [], 'historial' => []],
    $sinScript->getFechasAsignadas([1, 2]));

$rota = new EcheqsSinScriptPrueba();
$rota->tabla = true;
$rota->plazoPres = Echeqs::plazoPresentacionDesdeValor(null);
$aRota = $rota->avisosPlazoPresentacion($hV, true);
chequear('con la tabla y el parametro faltante: critico en el tablero', ['danger', true],
    [$aRota[0]['nivel'], strpos($aRota[0]['texto'], 'Falta el parámetro ' . Echeqs::CLAVE_PLAZO_PRESENTACION) !== false]);
chequear('y en la pestana', 'danger', $rota->avisosPlazoPresentacion($hV)[0]['nivel']);

$bien = new EcheqsSinScriptPrueba();
$bien->tabla = true;
$bien->plazoPres = ['dias' => 30, 'error' => null];
chequear('con todo en orden no avisa nada', [], $bien->avisosPlazoPresentacion($hV));
chequear('y el selector va de manana al fin del eje', ['2026-10-22', $hV->fin(), 30],
    [$bien->datosEnPlazo($hV)['manana'], $bien->datosEnPlazo($hV)['fin'], $bien->datosEnPlazo($hV)['plazo_presentacion']]);

$srcGetF = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function getFechasAsignadas('), 900);
chequear('sin la tabla, getFechasAsignadas() devuelve vacio sin lanzar', true,
    strpos($srcGetF, "if (empty(\$ids) || !\$this->fechaAcreditacionCreada()) {\n            return \$r;") !== false);
$srcCtx = substr($srcEcheqsV, strpos($srcEcheqsV, 'private function contextoCartera('), 1800);
chequear('sin la tabla no se lee el plazo: no se leen los de dia habil pasado', true,
    strpos($srcCtx, "\$plazoPres = \$tabla ? \$this->getPlazoPresentacion() : ['dias' => null, 'error' => null];") !== false);

seccion('el universo es uno solo');

$sqlC = str_replace("\r\n", "\n", Echeqs::sqlCartera(true));
$sqlA = str_replace("\r\n", "\n", Echeqs::sqlCarteraTotales(true));
$universo = "s.FECHA_CHEQ >= ?\n                  AND s.FECHA_CHEQ >= '1900-01-01'\n                  AND s.ESTADO = ?";
chequear('la centinela queda afuera explicitamente', true, strpos($universo, "'1900-01-01'") !== false);
chequear('la fila por fila y la agregada usan el mismo WHERE', [true, true],
    [strpos($sqlC, $universo) !== false, strpos($sqlA, $universo) !== false]);
chequear('la agregada lee de hoy en adelante, y lo anterior va fila por fila', [true, true], [
    strpos($sqlA, "AND s.FECHA_CHEQ >= ?\n                GROUP BY") !== false,
    strpos(str_replace("\r\n", "\n", Echeqs::sqlCartera(true, true)), "AND s.FECHA_CHEQ < ?") !== false
]);
$srcExc = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function getExclusionesEnCartera('), 2600);
chequear('el resumen de excluidos usa el mismo WHERE y la misma regla', [true, true], [
    strpos($srcExc, 'AND " . self::sqlUniversoCartera();') !== false,
    strpos($srcExc, 'self::CARTERA_SUMAN') !== false
]);
chequear('ninguna lectura de cartera filtra contra GETDATE()', 0,
    preg_match('/FECHA_CHEQ >= CAST\(GETDATE\(\) AS DATE\)/',
        substr($srcEcheqsV, strpos($srcEcheqsV, 'SUB-PESTANA 1 - CHEQUES EN CARTERA'),
            strpos($srcEcheqsV, 'LOS DEPOSITADOS, LEIDOS DE TANGO') - strpos($srcEcheqsV, 'SUB-PESTANA 1 - CHEQUES EN CARTERA'))));

/* ================================================================
   Las escrituras, sobre una #temporal
   ================================================================ */

/**
 * Las escrituras reales de Echeqs con la tabla y la conexion cambiadas por una
 * #temporal de la sesion de la prueba. NO ESCRIBE EN NINGUNA TABLA REAL: corre
 * escribirFechas() y deshacerFechaEnPlazo() de produccion contra la
 * temporal, y se verifica a si misma abajo.
 */
class EcheqsFechasPrueba extends Echeqs {
    public $cid;

    protected function tablaFechas() {
        return '#FECHA_ACREDITACION_PRUEBA';
    }

    protected function conexionFechas() {
        return $this->cid;
    }

    public function fechaAcreditacionCreada() {
        return true;
    }

    public function escribir($ids, $fecha, $obs, $reemplaza, $usuario) {
        $this->escribirFechas($ids, $fecha, $obs, $reemplaza, $usuario);
    }
}

seccion('las escrituras: reasignar deja un solo vigente, deshacer conserva el historial');

$rcF = new ReflectionClass('EcheqsFechasPrueba');
$fuenteF = implode('', array_slice(file(__FILE__), $rcF->getStartLine() - 1,
    $rcF->getEndLine() - $rcF->getStartLine() + 1));
chequear('la subclase no nombra ninguna tabla real', 0, preg_match('/RO_T_|dbo\./i', $fuenteF));
chequear('ni llama a los metodos originales de conexion o tabla', false, strpos($fuenteF, 'parent::') !== false);

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base, no hay donde crear la #temporal');
} else {
    $connF = new Conexion;
    $cidF = $connF->conectar('central');

    // El cuerpo del CREATE TABLE del script, sin nombres de constraint, y su
    // indice unico filtrado.
    $okTemp = sqlsrv_query($cidF, "CREATE TABLE #FECHA_ACREDITACION_PRUEBA (
            ID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            ID_SBA14 INT NOT NULL,
            FECHA_ACREDITACION DATE NOT NULL,
            OBSERVACION VARCHAR(200) NULL,
            VIGENTE BIT NOT NULL DEFAULT (1),
            ID_REEMPLAZA INT NULL,
            USUARIO_ALTA VARCHAR(50) NULL,
            FECHA_ALTA DATETIME NOT NULL DEFAULT (GETDATE()),
            USUARIO_MODIF VARCHAR(50) NULL,
            FECHA_MODIF DATETIME NOT NULL DEFAULT (GETDATE()),
            USUARIO_BAJA VARCHAR(50) NULL,
            FECHA_BAJA DATETIME NULL);
        CREATE UNIQUE NONCLUSTERED INDEX UX_PRUEBA ON #FECHA_ACREDITACION_PRUEBA (ID_SBA14) WHERE VIGENTE = 1;");

    chequear('se crea la #temporal', true, $okTemp !== false);

    $ef = new EcheqsFechasPrueba();
    $ef->cid = $cidF;

    $ef->escribir([101, 102], '2026-10-23', 'primera', [], 'pruebas');
    $f1 = $ef->getFechasAsignadas([101, 102]);
    chequear('asignar deja una fecha vigente por cheque', ['2026-10-23', '2026-10-23', 'primera'],
        [$f1['vigentes'][101]['fecha'], $f1['vigentes'][102]['fecha'], $f1['vigentes'][101]['observacion']]);

    $ef->escribir([101], '2026-10-27', null, [101 => $f1['vigentes'][101]['id']], 'pruebas2');
    $f2 = $ef->getFechasAsignadas([101]);
    chequear('reasignar deja un solo vigente, la nueva', [1, '2026-10-27'],
        [count(array_filter($f2['historial'][101], function ($x) { return $x['vigente'] === 1; })),
         $f2['vigentes'][101]['fecha']]);
    chequear('que apunta a la que reemplaza', $f1['vigentes'][101]['id'], $f2['vigentes'][101]['id_reemplaza']);
    chequear('y la anterior queda en el historial, dada de baja con quien', [2, 'pruebas2'],
        [count($f2['historial'][101]), $f2['historial'][101][1]['usuario_baja']]);

    $d = $ef->deshacerFechaEnPlazo([101, 102], 'pruebas3');
    $f3 = $ef->getFechasAsignadas([101, 102]);
    chequear('deshacer deja los dos sin fecha', [2, []], [$d['deshechos'], $f3['vigentes']]);
    chequear('y conserva el historial entero', [2, 1], [count($f3['historial'][101]), count($f3['historial'][102])]);

    chequearLanza('deshacer otra vez no puede: ya no tienen fecha', function () use ($ef) {
        $ef->deshacerFechaEnPlazo([101], 'pruebas');
    });

    // El indice unico: una segunda vigente para el mismo cheque falla en la base.
    $ef->escribir([103], '2026-10-23', null, [], 'pruebas');
    $dup = @sqlsrv_query($cidF, "INSERT INTO #FECHA_ACREDITACION_PRUEBA (ID_SBA14, FECHA_ACREDITACION, VIGENTE)
                                 VALUES (103, '2026-10-26', 1)");
    chequear('el indice unico frena una segunda fecha vigente', false, $dup !== false);

    sqlsrv_query($cidF, 'DROP TABLE #FECHA_ACREDITACION_PRUEBA');
}

/* ================================================================
   Venta Cobrada Anticipada y el neteo de Ventas: el mismo corrimiento
   ================================================================ */
require_once __DIR__ . '/../Class/Ventas.php';

seccion('venta cobrada anticipada: el cheque y su neteo en la misma columna corrida');

$preV = Echeqs::ubicarPrechequeado([
    ['ID_SBA14' => 1, 'FECHA_CHEQUE' => '2026-10-24', 'COD_CLIENTE' => 'FRCAST', 'ESTADO' => 'C',
     'IMPORTE' => 100.0, 'MARCADO' => 1, 'FECHA_VENTA_EST' => '2026-10-24'],
    ['ID_SBA14' => 2, 'FECHA_CHEQUE' => '2026-11-20', 'COD_CLIENTE' => 'LMDQ01', 'ESTADO' => 'A',
     'IMPORTE' => 250.0, 'MARCADO' => 1, 'FECHA_VENTA_EST' => '2026-11-10'],
    ['ID_SBA14' => 3, 'FECHA_CHEQUE' => '2026-10-22', 'COD_CLIENTE' => 'FRCAST', 'ESTADO' => 'C',
     'IMPORTE' => 40.0, 'MARCADO' => 1, 'FECHA_VENTA_EST' => '2026-10-22']
], $CAL, $hV->fin());

chequear('el sabado cae el lunes, con la marca', ['2026-10-26', true, Echeqs::CORRIDA_FIN_DE_SEMANA],
    [$preV[0]['FECHA_EJE'], $preV[0]['CORRIMIENTO']['corrida'], $preV[0]['CORRIMIENTO']['motivo']]);
chequear('el viernes feriado cae el lunes siguiente', ['2026-11-23', Echeqs::CORRIDA_FERIADO],
    [$preV[1]['FECHA_EJE'], $preV[1]['CORRIMIENTO']['motivo']]);
chequear('el habil no se corre', ['2026-10-22', false], [$preV[2]['FECHA_EJE'], $preV[2]['CORRIMIENTO']['corrida']]);
chequear('FECHA_VENTA_EST no cambia: decide que se muestra, no donde cae', '2026-10-24',
    $preV[0]['FECHA_VENTA_EST']);

$cartV = filaCarteraV(1, '2026-10-24', 100.0, $HOYV);
chequear('y es la misma columna que el cheque en "Echeqs en cartera"', $cartV['FECHA_EJE'], $preV[0]['FECHA_EJE']);

$ejeDiasV = array_column($hV->dias(), 'fecha');
$ejeMesesV = array_column($hV->meses(), 'clave');
$netV = Ventas::repartirNeteo(array_map(function ($f) {
    return ['FECHA_CHEQUE' => $f['FECHA_CHEQUE'], 'COD_CLIENTE' => $f['COD_CLIENTE'], 'ESTADO' => $f['ESTADO'],
            'IMPORTE' => $f['IMPORTE'], 'CHEQUES' => 1];
}, $preV), $ejeDiasV, $ejeMesesV, [], $CAL);
$pPre = EjeVista::armar($hV, $preV, 'FECHA_EJE', 'IMPORTE');

chequear('el neteo resta el sabado el lunes, no el sabado', [100.0, 0],
    [$netV['dias']['2026-10-26'], $netV['dias']['2026-10-24']]);
chequear('el feriado de noviembre, en la columna de noviembre', 250.0, $netV['meses']['2026-11']);

$mismaColumna = true;

foreach ($pPre['filas'] as $fp) {
    $col = Horizonte::ubicar($netV, $fp['FECHA_EJE']);

    if ($col === null || $netV[$col[0]][$col[1]] < $fp['IMPORTE']) {
        $mismaColumna = false;
    }
}

chequear('la sub-pestana y el neteo ponen cada cheque en la misma columna', true, $mismaColumna);
chequear('y suman lo mismo', round($netV['total'], 2), round($pPre['totales']['total_horizonte'], 2));

chequearLanza('el neteo sin calendario no se reparte: no hay un respaldo escrito', function () use ($ejeDiasV, $ejeMesesV) {
    Ventas::repartirNeteo([], $ejeDiasV, $ejeMesesV, [], null);
});

$netFalta = Ventas::repartirNeteo([['FECHA_CHEQUE' => '2027-03-06', 'COD_CLIENTE' => 'FRCAST', 'ESTADO' => 'C',
    'IMPORTE' => 10.0, 'CHEQUES' => 1]], $ejeDiasV, $ejeMesesV, [], $CAL);
chequear('sin calendario para un mes, el neteo usa el respaldo y lo avisa', [10.0, true],
    [$netFalta['meses']['2027-03'],
     strpos(implode(' ', Ventas::avisosNeteo($netFalta)), 'RO_T_CALENDARIO no tiene datos para 2027-03') !== false]);

$netSin = Ventas::repartirNeteo([['FECHA_CHEQUE' => '2027-01-09', 'COD_CLIENTE' => 'FRCAST', 'ESTADO' => 'C',
    'IMPORTE' => 10.0, 'CHEQUES' => 1]], $ejeDiasV, $ejeMesesV, [], $ningunHabil);
chequear('sin habil en el tope netea en su fecha y lo deja para el aviso critico', [10.0, ['2027-01-09' => true]],
    [$netSin['meses']['2027-01'], $netSin['sin_habil']]);
$srcVentasV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Ventas.php'));
chequear('ese aviso es critico', true, strpos($srcVentasV,
    "\$this->warnings[] = Aviso::nuevo(Aviso::DANGER, 'Neteo de cheques adelantados: '\n                . DiasHabiles::avisoSinHabil(\$fecha)") !== false);
chequear('el neteo y la sub-pestana corren con el mismo helper que la cartera', [1, 1], [
    substr_count($srcVentasV, '$c = Echeqs::correrAHabil($fila[\'FECHA_CHEQUE\'], $habiles, $tope);'),
    substr_count($srcEcheqsV, "\$f['CORRIMIENTO'] = self::correrAHabil(\$f['FECHA_CHEQUE'], \$habiles, \$tope);")
]);
chequear('el neteo lee el calendario por la unica lectura del modulo', 1,
    substr_count($srcVentasV, '$habiles = $this->getDiasHabiles(min($fechas),'));
chequear('la sub-pestana arma su eje con FECHA_EJE', 1,
    substr_count($ctrlV, "\$filas,\n                'FECHA_EJE',\n                'IMPORTE'\n            );"));

$aPre = Echeqs::avisosPrechequeado(Echeqs::ubicarPrechequeado([['FECHA_CHEQUE' => '2027-01-09'],
    ['FECHA_CHEQUE' => '2027-02-06']], $ningunHabil + calendarioV('2027-04-01', '2027-04-30'), '2027-09-30'));
chequear('los avisos de la sub-pestana: sin habil critico', 'danger', $aPre[0]['nivel']);

$jsV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Ingresos-Echeqs.js'));
chequear('la grilla de la sub-pestana muestra la marca corrida con su tooltip', true,
    strpos($jsV, "+ (cor.corrida\n                     ? '<div><span class=\"ech-badge-corrida\" title=\"' + escapar(textoCorrida({") !== false);
