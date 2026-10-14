<?php
/**
 * Echeqs: el deposito masivo de cheques de cartera.
 *
 * Como el resto del modulo, lo delicado son CRITERIOS y no consultas: cuando
 * acredita un deposito, con que tasa, que cheques se pueden depositar juntos,
 * cual se proyecta y en que serie cae cada peso. Viven en helpers estaticos
 * puros y se verifican aca sin base, con la fecha de hoy inyectada para que
 * las pruebas no caduquen.
 *
 * Nada de este archivo escribe en la base.
 */

require_once __DIR__ . '/../Class/Echeqs.php';

// Sin los CR: git puede bajar el script con fin de linea de Windows.
$SQL_DEPOSITO = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_echeqs_deposito.sql'));

/* ================================================================
   El script
   ================================================================ */
seccion('el script del deposito');

chequear('crea la tabla de depositos', true,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO') !== false);
chequear('y la de alicuotas propia, no filas en la de Cob. Electronicos', true,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA') !== false
    && strpos($SQL_DEPOSITO, 'INSERT INTO dbo.RO_T_CASHFLOW_COBEL') === false);
chequear('un solo deposito vigente por cheque: indice unico filtrado', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQDEP_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_DEPOSITO \(ID_SBA14\)\s+WHERE VIGENTE = 1/',
        $SQL_DEPOSITO));

foreach (['ID_SBA14', 'ID_BANCO', 'DESC_BANCO', 'FECHA_DEPOSITO', 'FECHA_ACREDITACION',
          'DIAS_PLAZO', 'IMPORTE_BRUTO', 'TASA_APLICADA', 'IMPORTE_NETO', 'OBSERVACION',
          'VIGENTE', 'USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF',
          'USUARIO_BAJA', 'FECHA_BAJA'] as $col) {
    chequear('el deposito guarda ' . $col, 1,
        preg_match('/\n\s+' . $col . '\s+[A-Z]/', $SQL_DEPOSITO));
}

chequear('la tasa y el neto no pueden quedar en null', 2,
    preg_match_all('/(TASA_APLICADA|IMPORTE_NETO)\s+DECIMAL\([0-9,]+\)\s+NOT NULL/', $SQL_DEPOSITO));
chequear('no siembra ninguna alicuota: las carga Tesoreria', false,
    strpos($SQL_DEPOSITO, 'INSERT INTO dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA') !== false);
chequear('siembra el plazo en 2', true,
    strpos($SQL_DEPOSITO, "''echeqs_dias_acreditacion_deposito'', ''2''") !== false
    && strpos($SQL_DEPOSITO, "('echeqs_dias_acreditacion_deposito', '2'") !== false);
chequear('en el modulo PRECHEQUEADO, que es el codigo de la sub-pestana Cheques', true,
    strpos($SQL_DEPOSITO, "''DEPOSITO'', ''PRECHEQUEADO''") !== false);
chequear('y si ya existe no le pisa el valor', true,
    strpos($SQL_DEPOSITO, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'echeqs_dias_acreditacion_deposito')") !== false
    && strpos($SQL_DEPOSITO, 'UPDATE dbo.RO_T_CASHFLOW_PARAMETROS') === false);
chequear('es reejecutable: cada tabla pregunta si existe', 2,
    preg_match_all("/IF OBJECT_ID\('dbo\.RO_T_CASHFLOW_ECHEQ_(DEPOSITO|ALICUOTA)', 'U'\) IS NULL/", $SQL_DEPOSITO));
chequear('no tiene bajas fisicas', false, stripos($SQL_DEPOSITO, 'DELETE ') !== false);

/* ================================================================
   Parametros -> Cheques: el plazo
   ================================================================ */
seccion('el plazo de acreditacion');

chequear('2 es un plazo valido', 2, Echeqs::validarPlazo('2'));
chequear('el tope tambien', Echeqs::PLAZO_MAX, Echeqs::validarPlazo(Echeqs::PLAZO_MAX));
chequearLanza('cero no es un plazo: acreditaria el mismo dia', function () {
    Echeqs::validarPlazo(0);
});
chequearLanza('un negativo tampoco', function () { Echeqs::validarPlazo('-1'); });
chequearLanza('ni un decimal', function () { Echeqs::validarPlazo('1.5'); });
chequearLanza('ni un texto', function () { Echeqs::validarPlazo('dos'); });
chequearLanza('ni mas que el tope', function () { Echeqs::validarPlazo(Echeqs::PLAZO_MAX + 1); });

$p = Echeqs::plazoDesdeValor(null);
chequear('sin el parametro, el plazo es null: no hay un 2 de respaldo', null, $p['dias']);
chequear('y el motivo nombra el script', true, strpos($p['error'], Echeqs::SCRIPT_DEPOSITO) !== false);

$p = Echeqs::plazoDesdeValor('0');
chequear('un plazo roto en la base tambien es null', null, $p['dias']);
chequear('y dice donde corregirlo', true, strpos($p['error'], 'Parámetros → Cheques') !== false);

chequear('un plazo bien guardado se usa', 3, Echeqs::plazoDesdeValor('3')['dias']);

require_once __DIR__ . '/../Class/Parametros.php';

chequear('Parametros y Echeqs nombran la misma clave del plazo',
    Echeqs::CLAVE_PLAZO, Parametros::CLAVE_PLAZO_ECHEQS);

$srcParametros = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro() no guarda el plazo: tiene su propio camino, que valida', true,
    strpos($srcParametros, 'if ($clave === self::CLAVE_PLAZO_ECHEQS)') !== false);

/* ================================================================
   Parametros -> Cheques: las alicuotas
   ================================================================ */
seccion('las alicuotas de los cheques');

chequear('una alicuota numerica pasa', 0.025, Echeqs::numeroAlicuota('0.025'));
chequearLanza('un texto no llega como cero', function () { Echeqs::numeroAlicuota('abc'); });
chequearLanza('vacia tampoco', function () { Echeqs::numeroAlicuota(''); });

$alicuotasCheques = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 3, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.030, 'VIGENCIA_DESDE' => '2026-10-20', 'ACTIVO' => 1],
    ['ID' => 4, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.010, 'VIGENCIA_DESDE' => '2026-09-01', 'ACTIVO' => 0]
];

$marcadas = Echeqs::marcarVigentesHoy($alicuotasCheques, '2026-10-13');
chequear('el 13/10 rige el IIBB del 1/10', true, $marcadas[0]['VIGENTE_HOY']);
chequear('y el SICREB del 1/10', true, $marcadas[1]['VIGENTE_HOY']);
chequear('el IIBB del 20/10 todavia no rige', false, $marcadas[2]['VIGENTE_HOY']);
chequear('una dada de baja no rige', false, $marcadas[3]['VIGENTE_HOY']);

$t = Echeqs::tasaParaPantalla($alicuotasCheques, '2026-10-13');
chequear('la tasa de hoy es la suma de las vigentes', 0.031, $t['tasa']);
chequear('con su desglose', 'IIBB 2,5% + SICREB 0,6%', $t['desglose']);

$t = Echeqs::tasaParaPantalla([], '2026-10-13');
chequear('sin alicuotas la tasa es null, no 0%: la pantalla dice "sin alícuotas"', null, $t['tasa']);

try {
    Retenciones::resolverTasa([], '2026-10-15', Echeqs::TEXTOS_TASA);
    $mensaje = '';
} catch (Throwable $e) {
    $mensaje = $e->getMessage();
}

chequear('sin alicuotas el deposito se rechaza y dice donde cargarlas', true,
    strpos($mensaje, 'Los cheques no tienen ninguna alícuota vigente') === 0
    && strpos($mensaje, 'Parámetros → Cheques') !== false);

/* ================================================================
   El recalculo de los pendientes
   ================================================================ */
seccion('cambiar una alicuota recalcula solo lo que acredita de manana en adelante');

// Hoy es el 20/10, el dia que arranca el IIBB nuevo. Tres depositos vigentes
// calculados con la tasa vieja: uno acredito ayer, uno acredita hoy y uno
// manana. Solo el de manana es pendiente.
$depositos = [
    ['id' => 1, 'juego' => 0, 'fecha_acreditacion' => '2026-10-19', 'importe_bruto' => 100000.0,
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0],
    ['id' => 2, 'juego' => 0, 'fecha_acreditacion' => '2026-10-20', 'importe_bruto' => 100000.0,
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0],
    ['id' => 3, 'juego' => 0, 'fecha_acreditacion' => '2026-10-21', 'importe_bruto' => 100000.0,
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0]
];

$plan = Retenciones::planRecalculo($depositos, [0 => $alicuotasCheques],
    Retenciones::cortePendientes('2026-10-20'));

chequear('se recalcula uno solo', 1, count($plan['cambios']));
chequear('el que acredita manana', 3, $plan['cambios'][0]['id']);
chequear('con la tasa vigente a su acreditacion', 0.036, $plan['cambios'][0]['tasa_nueva']);
chequear('el neto baja 500', -500.0, $plan['cambios'][0]['diferencia']);
chequear('los de ayer y hoy cuentan como acreditados y no se tocan', 2, $plan['acreditados']);

$srcEcheqs = file_get_contents(__DIR__ . '/../Class/Echeqs.php');
chequear('el recalculo usa el corte de Cob. Electronicos', true,
    strpos($srcEcheqs, '$corte = Retenciones::cortePendientes(') !== false);
chequear('y escribe en una transaccion', true,
    strpos($srcEcheqs, 'No se pudo abrir la transacción del recálculo') !== false);
chequear('cargar una alicuota recalcula', true,
    strpos($srcEcheqs, "'recalculo' => \$this->recalcularDepositosPendientes(\$usuario)") !== false);
chequear('darla de baja tambien', true,
    strpos($srcEcheqs, "return ['recalculo' => \$this->recalcularDepositosPendientes(\$usuario)];") !== false);

/* ================================================================
   La sub-pestana Cheques
   ================================================================ */
seccion('Parametros -> Cheques');

$modCheques = null;

foreach (Parametros::getModulos() as $m) {
    if ($m['codigo'] === 'PRECHEQUEADO') {
        $modCheques = $m;
    }
}

chequear('el codigo de modulo sigue siendo PRECHEQUEADO', true, $modCheques !== null);
chequear('pero se llama Cheques', 'Cheques', $modCheques['nombre']);
chequear('la descripcion dice las dos cosas que controla', true,
    strpos($modCheques['descripcion'], 'Venta Cobrada Anticipada') !== false
    && strpos($modCheques['descripcion'], 'depósito de cheques') !== false);
chequear('el deposito va primero, el maestro queda', ['deposito', 'prechequeado'],
    $modCheques['secciones']);

require_once __DIR__ . '/../Class/AuthCashflow.php';

foreach (['savePlazoDepositoEcheq', 'addAlicuotaEcheq', 'bajaAlicuotaEcheq'] as $accion) {
    chequear($accion . ' pide editar Parametros -> Cheques', [['parametros', 'PRECHEQUEADO']],
        AuthCashflow::ESCRITURAS['Parametros'][$accion]);
}

/* ================================================================
   El deposito: que cheques se pueden depositar juntos
   ================================================================ */

/** Una fila de cartera como las de getEcheqsCartera(), armada a mano */
function chequeCartera($id, $banco, $importe = 100000.0, $extra = []) {
    $bancos = [10 => 'GALICIA', 20 => 'MACRO S.A.'];

    return array_merge([
        'ID_SBA14' => $id,
        'N_CHEQUE' => 9000 + $id,
        'FECHA_CHEQUE' => '2026-10-30',
        'FECHA_PAGO' => '2026-10-30',
        'ID_BANCO' => $banco,
        'BANCO' => $banco === null ? 'Sin banco' : $bancos[$banco],
        'IMPORTE' => $importe,
        'CLIENTE' => 'CLIENTE ' . $id,
        'COD_CLIENTE' => 'FR' . $id,
        'EXCLUIDO' => false,
        'DEPOSITO' => null
    ], $extra);
}

$depVigente = ['id' => 77, 'fecha_deposito' => '2026-10-12', 'fecha_acreditacion' => '2026-10-14',
               'dias_plazo' => 2, 'bruto' => 100000.0, 'tasa' => 0.031, 'desglose' => 'IIBB 2,5% + SICREB 0,6%',
               'neto' => 96900.0, 'observacion' => null, 'banco' => 'GALICIA',
               'usuario' => 'rorozco', 'fecha_alta' => '2026-10-12 10:00:00'];

$cartera = [
    chequeCartera(1, 10),
    chequeCartera(2, 10, 50000.0),
    chequeCartera(3, 20),
    chequeCartera(4, null),
    chequeCartera(5, 10, 100000.0, ['EXCLUIDO' => true, 'MOTIVO_EXCLUSION' => 'concurso']),
    chequeCartera(6, 10, 100000.0, ['DEPOSITO' => $depVigente])
];

seccion('la seleccion del deposito: todo o nada');

$v = Echeqs::validarSeleccionDeposito([1, 2], $cartera);
chequear('dos cheques del mismo banco se pueden depositar', null, $v['error']);
chequear('y vuelven los dos', 2, count($v['filas']));

$v = Echeqs::validarSeleccionDeposito([1, 3], $cartera);
chequear('con bancos mezclados se rechaza entero', true, $v['error'] !== null);
chequear('y el motivo dice que bancos hay', true,
    strpos($v['error'], 'GALICIA') !== false && strpos($v['error'], 'MACRO S.A.') !== false);

$v = Echeqs::validarSeleccionDeposito([1, 4], $cartera);
chequear('un cheque sin banco se rechaza', 'no tiene banco emisor', $v['rechazos'][0]['motivo']);
chequear('y con el no se deposita ninguno', true, $v['error'] !== null);

$v = Echeqs::validarSeleccionDeposito([5], $cartera);
chequear('un excluido se rechaza: primero hay que devolverlo', true,
    strpos($v['rechazos'][0]['motivo'], 'excluido') !== false && $v['error'] !== null);

$v = Echeqs::validarSeleccionDeposito([6], $cartera);
chequear('uno ya depositado no se vuelve a depositar', 'ya está depositado', $v['rechazos'][0]['motivo']);

$v = Echeqs::validarSeleccionDeposito([1, 999], $cartera);
chequear('uno que ya no esta en cartera se rechaza', 'ya no está en cartera en Tango',
    $v['rechazos'][0]['motivo']);
chequear('todo o nada: el bueno tampoco pasa', true, $v['error'] !== null);
chequear('el mensaje dice cuantos y cuales', true,
    strpos($v['error'], '1 de los 2') !== false && strpos($v['error'], 'N° 999') !== false);

$v = Echeqs::validarSeleccionDeposito([1, '1', 0, -3], $cartera);
chequear('los ids se deduplican y los invalidos no cuentan', 1, count($v['filas']));

$v = Echeqs::validarSeleccionDeposito([], $cartera);
chequear('sin cheques no hay deposito', 'No llegó ningún cheque para depositar.', $v['error']);

/* ================================================================
   El calculo
   ================================================================ */
seccion('lo que se calcula al depositar');

// Octubre de 2026 con el 12 feriado; el 19 es lunes. Las alicuotas: IIBB 2,5%
// hasta el 20 y 3% desde el 21. El deposito del lunes 19 acredita el
// miercoles 21: tiene que tomar la tasa del 21, no la del 19.
$calOct = [];

for ($i = 1; $i <= 31; $i++) {
    $d = sprintf('2026-10-%02d', $i);
    $calOct[$d] = intval(date('N', strtotime($d))) <= 5 && $d !== '2026-10-12';
}

$aliTasa = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 3, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.030, 'VIGENCIA_DESDE' => '2026-10-21', 'ACTIVO' => 1]
];

$sel = Echeqs::validarSeleccionDeposito([1, 2], $cartera);
$c = Echeqs::calcularDeposito($sel['filas'], 2, $calOct, $aliTasa, '2026-10-19');

chequear('la fecha de deposito es hoy', '2026-10-19', $c['fecha_deposito']);
chequear('acredita a los 2 habiles: el miercoles', '2026-10-21', $c['fecha_acreditacion']);
chequear('guarda con que plazo se calculo', 2, $c['dias_plazo']);
chequear('la tasa es la vigente a la ACREDITACION, no al deposito', 0.036, $c['tasa']);
chequear('con su desglose', 'IIBB 3% + SICREB 0,6%', $c['desglose']);
chequear('el bruto es la suma de los cheques', 150000.0, $c['bruto']);
chequear('el neto es bruto x (1 - tasa)', 144600.0, $c['neto']);
chequear('la retencion, la diferencia', 5400.0, $c['retencion']);
chequear('cada cheque con su neto', 96400.0, $c['cheques'][0]['neto']);
chequear('el banco sale de los cheques, no del navegador', [10, 'GALICIA'],
    [$c['id_banco'], $c['banco']]);
chequear('con el calendario completo no hay avisos', [], $c['avisos']);

$c = Echeqs::calcularDeposito($sel['filas'], 2, $calOct, $aliTasa, '2026-10-23');
chequear('un viernes acredita el martes', '2026-10-27', $c['fecha_acreditacion']);

$c = Echeqs::calcularDeposito($sel['filas'], 2, $calOct, $aliTasa, '2026-10-30');
chequear('sin calendario, el respaldo de lunes a viernes', '2026-11-03', $c['fecha_acreditacion']);
chequear('y el aviso de calendario faltante viaja para el dialogo', true,
    count($c['avisos']) === 1 && strpos($c['avisos'][0], '2026-11') !== false);

chequearLanza('sin alicuotas vigentes a la acreditacion, el deposito se rechaza',
    function () use ($sel, $calOct) {
        Echeqs::calcularDeposito($sel['filas'], 2, $calOct, [], '2026-10-19');
    });

$roto = [];

for ($i = 0; $i < 60; $i++) {
    $roto[date('Y-m-d', strtotime('2026-10-19 +' . $i . ' day'))] = false;
}

chequearLanza('sin ningun habil en el tope, no hay deposito con una fecha inventada',
    function () use ($sel, $roto, $aliTasa) {
        Echeqs::calcularDeposito($sel['filas'], 2, $roto, $aliTasa, '2026-10-19');
    });

// La fecha del cheque no interviene: el cheque es del 30/10 y acredita el 21.
chequear('la fecha del cheque no interviene en la acreditacion', '2026-10-21',
    Echeqs::calcularDeposito($sel['filas'], 2, $calOct, $aliTasa, '2026-10-19')['fecha_acreditacion']);

chequear('el calendario se pide con margen para todo el corrimiento',
    ['desde' => '2026-10-19', 'hasta' => '2026-12-18'],
    Echeqs::rangoCalendarioDeposito('2026-10-19', 2));

chequear('la observacion es opcional: vacia es null', null, Echeqs::validarObservacion('   '));
chequear('y se recorta al largo de la columna', Echeqs::OBSERVACION_MAX,
    mb_strlen(Echeqs::validarObservacion(str_repeat('x', 300))));

/* ================================================================
   Nada que defina el deposito viene del navegador
   ================================================================ */
seccion('del navegador llegan solo los ids');

$m = new ReflectionMethod('Echeqs', 'depositarCheques');
chequear('depositarCheques() recibe ids, observacion y usuario: ni fechas ni importes',
    ['ids', 'observacion', 'usuario', 'hoy'],
    array_map(function ($p) { return $p->getName(); }, $m->getParameters()));

$ctrlEcheqs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/EcheqsController.php'));
$bloqueDep = substr($ctrlEcheqs, strpos($ctrlEcheqs, "case 'depositarCheques':"),
    strpos($ctrlEcheqs, "case 'deshacerDepositos':") - strpos($ctrlEcheqs, "case 'depositarCheques':"));

chequear('el controller no le pasa la fecha de hoy: la pone el servidor', 1,
    substr_count($bloqueDep, "\$r = \$echeqs->depositarCheques(\n                \$data['ids'],\n"
        . "                isset(\$data['observacion']) ? \$data['observacion'] : null,\n"
        . "                \$usuario\n            );"));
chequear('ni lee del pedido ningun neto, tasa, banco o fecha', 0,
    preg_match("/\\\$data\['(neto|tasa|banco|id_banco|fecha|importe|bruto|acredita)/i", $bloqueDep));

/* ================================================================
   Deshacer
   ================================================================ */
seccion('deshacer es una baja logica');

chequear('con todos depositados, se deshacen', [6], Echeqs::validarDeshacer([6], [6 => $depVigente])['ids']);
chequear('con uno sin deposito vigente, ninguno', true,
    Echeqs::validarDeshacer([6, 1], [6 => $depVigente])['error'] !== null);

$srcEcheqs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));
$bloqueDeshacer = substr($srcEcheqs, strpos($srcEcheqs, 'public function deshacerDepositos('),
    strpos($srcEcheqs, 'private function getDepositosVigentes(') - strpos($srcEcheqs, 'public function deshacerDepositos('));

chequear('deshacer marca VIGENTE = 0 con quien y cuando', true,
    strpos($bloqueDeshacer, "SET VIGENTE = 0, \" . Auditoria::SET_BAJA . \"") !== false);
chequear('y no borra nada', false, stripos($bloqueDeshacer, 'DELETE') !== false);
chequear('en una transaccion', true, strpos($bloqueDeshacer, 'sqlsrv_begin_transaction') !== false);

$bloqueHist = substr($srcEcheqs, strpos($srcEcheqs, 'public function getHistorialDepositos('), 1500);
chequear('el historial trae tambien los deshechos', false,
    strpos($bloqueHist, 'WHERE VIGENTE = 1') !== false);

$bloqueDepositar = substr($srcEcheqs, strpos($srcEcheqs, 'public function depositarCheques('),
    strpos($srcEcheqs, 'private function calcularParaHoy(') - strpos($srcEcheqs, 'public function depositarCheques('));
chequear('depositar es una sola transaccion', true,
    strpos($bloqueDepositar, 'sqlsrv_begin_transaction') !== false
    && strpos($bloqueDepositar, 'sqlsrv_rollback') !== false);
chequear('y valida y calcula todo antes de abrirla', true,
    strpos($bloqueDepositar, 'calcularParaHoy(') < strpos($bloqueDepositar, 'sqlsrv_begin_transaction'));
chequear('con el usuario de escritura', true,
    strpos($bloqueDepositar, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);') !== false);

$bloqueExcluir = substr($srcEcheqs, strpos($srcEcheqs, 'public function excluirCheques('), 4000);
chequear('un cheque depositado no se puede excluir', true,
    strpos($bloqueExcluir, "if (!empty(\$excluir) && !empty(\$enPedido)) {") !== false);

/* ================================================================
   Que se lee y que se proyecta
   ================================================================ */
seccion('que depositos se proyectan');

// Hoy es el 13/10.
$depFuturo = array_merge($depVigente, ['fecha_acreditacion' => '2026-10-15']);
$depHoy = array_merge($depVigente, ['fecha_acreditacion' => '2026-10-13']);
$depPasado = array_merge($depVigente, ['fecha_acreditacion' => '2026-10-09']);

chequear('acreditacion futura y en cartera: se proyecta', Echeqs::DEPOSITO_PROYECTA,
    Echeqs::estadoDeposito($depFuturo, true, '2026-10-13'));
chequear('acreditacion de HOY: no se proyecta, ya esta en el saldo bancario', Echeqs::DEPOSITO_ACREDITADO,
    Echeqs::estadoDeposito($depHoy, true, '2026-10-13'));
chequear('acreditacion pasada y sigue en cartera: tampoco', Echeqs::DEPOSITO_ACREDITADO,
    Echeqs::estadoDeposito($depPasado, true, '2026-10-13'));
chequear('ya no esta en cartera: sale solo, sin estado', null,
    Echeqs::estadoDeposito($depFuturo, false, '2026-10-13'));
chequear('sin deposito, sin estado', null, Echeqs::estadoDeposito(null, true, '2026-10-13'));

$vencido = chequeCartera(10, 10, 100000.0, ['FECHA_CHEQUE' => '2026-10-01', 'FECHA_PAGO' => '2026-10-01',
                                             'DEPOSITO' => $depFuturo]);
chequear('un depositado con la fecha del cheque vencida se lee igual', true,
    Echeqs::visibleEnCartera($vencido, '2026-10-13'));
chequear('y se proyecta, en su acreditacion y por el neto',
    [Echeqs::DEPOSITO_PROYECTA, '2026-10-15', 96900.0],
    array_values(array_intersect_key(Echeqs::ubicarEnEje($vencido, '2026-10-13'),
        array_flip(['ESTADO_DEPOSITO', 'FECHA_EJE', 'IMPORTE_EJE']))));

// Depositado con fecha de hoy y deshecho al dia siguiente: el deposito ya no
// esta vigente, la fecha del cheque ya paso, y no reaparece.
$deshecho = chequeCartera(11, 10, 100000.0, ['FECHA_CHEQUE' => '2026-10-12', 'FECHA_PAGO' => '2026-10-12']);
chequear('depositado el dia de su fecha y deshecho al otro dia: no aparece', false,
    Echeqs::visibleEnCartera($deshecho, '2026-10-13'));

$sql = Echeqs::sqlCartera(true, true);
chequear('la consulta une el deposito VIGENTE en la condicion de la union', 1,
    preg_match('/LEFT JOIN dbo\.RO_T_CASHFLOW_ECHEQ_DEPOSITO AS d\s+ON d\.ID_SBA14 = s\.ID_SBA14 AND d\.VIGENTE = 1/', $sql));
chequear('y no en el WHERE: un deshecho no trae la fila', false,
    strpos(substr($sql, strpos($sql, 'WHERE')), 'VIGENTE') !== false);
chequear('los depositados se leen aunque su fecha haya pasado', true,
    strpos($sql, '(s.FECHA_CHEQ >= CAST(GETDATE() AS DATE) OR d.ID IS NOT NULL)') !== false);
chequear('la agregada usa el mismo universo', true,
    strpos(Echeqs::sqlCarteraTotales(true, true), '(s.FECHA_CHEQ >= CAST(GETDATE() AS DATE) OR d.ID IS NOT NULL)') !== false);

// Con core.autocrlf la copia de trabajo de Echeqs.php puede estar en CRLF, y
// los saltos del SQL salen como \r\n: se normalizan antes de buscar.
$sinScript = str_replace("\r\n", "\n", Echeqs::sqlCartera(true, false));
chequear('sin el script, la consulta es la de antes: sin union ni OR', [false, true], [
    strpos($sinScript, 'ECHEQ_DEPOSITO') !== false,
    strpos($sinScript, "WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)\n                  AND s.ESTADO = ?") !== false
]);
chequear('y la agregada no agrupa por una constante', false,
    strpos(Echeqs::sqlCarteraTotales(false, false), 'GROUP BY CAST(s.FECHA_CHEQ AS DATE),') !== false);

seccion('donde cae cada fila en el eje');

$normal = Echeqs::ubicarEnEje(chequeCartera(20, 10), '2026-10-13');
chequear('un no depositado, en la fecha del cheque y por el bruto', ['2026-10-30', 100000.0],
    [$normal['FECHA_EJE'], $normal['IMPORTE_EJE']]);
chequear('con Acredita, Retencion y Neto vacios, no en cero', [null, null, null],
    [$normal['ACREDITA'], $normal['RETENCION'], $normal['NETO']]);

$acreditado = Echeqs::ubicarEnEje(chequeCartera(21, 10, 100000.0, ['DEPOSITO' => $depPasado]), '2026-10-13');
chequear('uno acreditado sin registrar no suma en ninguna columna', [null, 0.0],
    [$acreditado['FECHA_EJE'], $acreditado['IMPORTE_EJE']]);
chequear('pero muestra su deposito', ['2026-10-09', 3100.0, 96900.0],
    [$acreditado['ACREDITA'], $acreditado['RETENCION'], $acreditado['NETO']]);

$r = Echeqs::resumenDepositos([
    array_merge(Echeqs::ubicarEnEje(chequeCartera(30, 10, 100000.0, ['DEPOSITO' => $depFuturo]), '2026-10-13'),
        ['total_tramo' => 96900.0, 'total_meses' => 0, 'total_horizonte' => 96900.0]),
    $acreditado,
    $normal
]);
chequear('el resumen cuenta los que se proyectan con bruto, retencion y neto',
    [1, 100000.0, 3100.0, 96900.0], [$r['cheques'], $r['bruto'], $r['retencion'], $r['neto']]);
chequear('y sus totales por vista', 96900.0, $r['total_horizonte']);
chequear('los acreditados sin registrar van aparte', 1, $r['acreditados']['cheques']);

chequear('el aviso de los acreditados dice cuantos, cuanto y que hacer', true,
    strpos(Echeqs::avisoAcreditadosSinRegistrar(2, 193800), '2 cheque(s) depositados por $ 193.800,00') === 0
    && strpos(Echeqs::avisoAcreditadosSinRegistrar(2, 193800), 'registrá el depósito') !== false);
chequear('sin acreditados no hay aviso', null, Echeqs::avisoAcreditadosSinRegistrar(0, 0));

seccion('los permisos del deposito');

foreach (['previsualizarDeposito', 'depositarCheques', 'deshacerDepositos'] as $accion) {
    chequear($accion . ' pide editar Echeqs, como excluir', [['echeqs', null]],
        AuthCashflow::ESCRITURAS['Echeqs'][$accion]);
}

chequear('sin el script, el boton Depositar dice que script falta', true,
    strpos($srcEcheqs, "'motivo' => 'Para depositar cheques hace falta correr '\n                        . self::SCRIPT_DEPOSITO") !== false);
chequear('sin la fila del tablero, tambien', true,
    strpos($srcEcheqs, "WHERE ORIGEN_PROVIDER = 'ECHEQS' AND ORIGEN_SERIE = 'DEPOSITADOS' AND ACTIVO = 1") !== false);

/* ================================================================
   El proveedor: la particion de la cartera
   ================================================================ */
seccion('el proveedor: los depositados salen de A_COBRAR y entran en DEPOSITADOS');

require_once __DIR__ . '/../Class/Providers/EcheqsProvider.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';

/** Eje del 13/10/2026: 28 dias y 12 meses */
$hDep = new Horizonte(28, 12, [], new DateTime('2026-10-13'));

/** Una fila agregada de getEcheqsCarteraTotales(), armada a mano */
function itemCartera($fecha, $importe, $extra = []) {
    return array_merge(['FECHA_PAGO' => $fecha, 'EXCLUIDO' => false, 'IMPORTE' => $importe,
                        'FECHA_ACREDITACION' => null, 'NETO' => null, 'BRUTO_DEPOSITO' => null,
                        'CHEQUES' => 1], $extra);
}

/** Un deposito agregado: bruto, neto y acreditacion */
function itemDeposito($fechaCheque, $bruto, $neto, $acredita, $cheques = 1) {
    return itemCartera($fechaCheque, $bruto, ['FECHA_ACREDITACION' => $acredita, 'NETO' => $neto,
                                              'BRUTO_DEPOSITO' => $bruto, 'CHEQUES' => $cheques]);
}

/** El total de una serie, con lo que quedo fuera del eje */
function totalSerieDep($s) {
    return array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'];
}

$items = [
    itemCartera('2026-10-20', 1000.0),                              // cobrable
    itemCartera('2026-10-22', 400.0, ['EXCLUIDO' => true]),         // excluido
    itemDeposito('2026-10-30', 2000.0, 1938.0, '2026-10-15'),       // se proyecta
    itemDeposito('2026-10-01', 500.0, 484.5, '2026-10-16'),         // cheque vencido, se proyecta
    itemDeposito('2026-10-25', 300.0, 290.7, '2026-10-13'),         // acredita hoy: no
    itemDeposito('2026-10-02', 700.0, 678.3, '2026-10-09', 2)       // ya acredito y sigue en cartera
];

$s = EcheqsProvider::repartir($hDep, $items);

chequear('DEPOSITADOS suma netos', 1938.0 + 484.5, totalSerieDep($s['DEPOSITADOS']));
chequear('por fecha de ACREDITACION', [1938.0, 484.5],
    [$s['DEPOSITADOS']['dias']['2026-10-15'], $s['DEPOSITADOS']['dias']['2026-10-16']]);
chequear('y no en la fecha del cheque', 0.0, $s['DEPOSITADOS']['dias']['2026-10-30']);
chequear('A_COBRAR no incluye ningun depositado', 1000.0, totalSerieDep($s['A_COBRAR']));
chequear('los excluidos quedan donde estaban', 400.0, totalSerieDep($s['A_COBRAR_EXCLUIDOS']));
chequear('un deposito que acredita hoy no esta en ninguna serie', 0.0,
    $s['A_COBRAR_TODO']['dias']['2026-10-13'] + $s['DEPOSITADOS']['dias']['2026-10-13']);
chequear('los ya acreditados se cuentan para el aviso: hoy y antes', [3, 290.7 + 678.3],
    [$s['DEPOSITADOS']['acreditados_cheques'], $s['DEPOSITADOS']['acreditados_neto']]);

/* EL INVARIANTE: cada cheque en una sola parte, y las partes cierran contra el
   total. Columna por columna, no solo en el total: un deposito que moviera el
   importe de fecha daria el mismo total y un cuadro distinto. */
$cierra = true;

foreach (['dias', 'meses'] as $rama) {
    foreach ($s['A_COBRAR_TODO'][$rama] as $k => $v) {
        if (abs($v - ($s['A_COBRAR'][$rama][$k] + $s['A_COBRAR_EXCLUIDOS'][$rama][$k]
                + $s['DEPOSITADOS'][$rama][$k])) > 0.001) {
            $cierra = false;
        }
    }
}

chequear('A_COBRAR + A_COBRAR_EXCLUIDOS + DEPOSITADOS = A_COBRAR_TODO, columna por columna', true, $cierra);
chequear('la retencion es el bruto menos el neto de los que se proyectan', 2500.0 - 2422.5,
    $s['DEPOSITADOS']['retencion']);
chequear('y el universo en bruto = A_COBRAR_TODO + retencion',
    1000.0 + 400.0 + 2000.0 + 500.0,
    totalSerieDep($s['A_COBRAR_TODO']) + $s['DEPOSITADOS']['retencion']);

$sinNada = EcheqsProvider::repartir($hDep, [itemCartera('2026-10-20', 1000.0)]);
chequear('sin depositos, A_COBRAR vale lo mismo que antes y DEPOSITADOS va en cero',
    [1000.0, 0.0], [floatval(totalSerieDep($sinNada['A_COBRAR'])), floatval(totalSerieDep($sinNada['DEPOSITADOS']))]);
chequear('las cuatro series existen siempre', 4, count($sinNada));

$lejos = EcheqsProvider::repartir($hDep, [itemDeposito('2026-10-20', 100.0, 96.9, '2030-01-02')]);
chequear('una acreditacion fuera del eje se informa en la fila de depositados', 96.9,
    $lejos['DEPOSITADOS']['fuera_horizonte']);
chequear('y en el total', 96.9, $lejos['A_COBRAR_TODO']['fuera_horizonte']);

chequear('un depositado que se proyecta va al total y a DEPOSITADOS', ['A_COBRAR_TODO', 'DEPOSITADOS'],
    EcheqsProvider::seriesDeItem(itemDeposito('2026-10-20', 1.0, 1.0, '2026-10-15'), '2026-10-13'));
chequear('uno que ya acredito no va a ninguna', [],
    EcheqsProvider::seriesDeItem(itemDeposito('2026-10-20', 1.0, 1.0, '2026-10-13'), '2026-10-13'));

$srcProv = file_get_contents(__DIR__ . '/../Class/Providers/EcheqsProvider.php');
chequear('el proveedor avisa los acreditados sin registrar como atencion', true,
    strpos($srcProv, "\$this->avisar(\$aviso, Aviso::WARNING);") !== false);
chequear('y decide con la misma regla que la pestana', true,
    strpos($srcProv, 'return Echeqs::estadoDeposito(') !== false);

/* ================================================================
   El registro y el validador
   ================================================================ */
seccion('la fila nueva y A_COBRAR juntas no cuentan dos veces');

// Sin tercer argumento, el validador usa el registro real.
$PROVS_REALES = null;
$metaE = CashflowRegistry::meta('ECHEQS');

chequear('el registro ofrece DEPOSITADOS', true, isset($metaE['series']['DEPOSITADOS']));
chequear('como parte del total, al lado de las otras dos',
    ['A_COBRAR', 'A_COBRAR_EXCLUIDOS', 'DEPOSITADOS'], $metaE['componentes']['A_COBRAR_TODO']);
chequear('ni el bruto ni la retencion son series: no se pueden usar en una fila', [false, false],
    [isset($metaE['series']['DEPOSITADOS_BRUTO']), isset($metaE['series']['DEPOSITADOS_RETENCION'])]);

$secDisp = [['CODIGO' => 'DISPONIBILIDADES', 'NOMBRE' => 'Disponibilidades', 'ROL' => 'MOVIMIENTO',
             'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]];

/** Una fila de la estructura apuntada a ECHEQS */
function filaEcheqs($id, $codigo, $serie, $orden) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => 'DISPONIBILIDADES',
            'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'ECHEQS',
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1];
}

$juntas = CashflowEstructura::validar($secDisp, [
    filaEcheqs(1, 'ECHEQS', 'A_COBRAR', 20),
    filaEcheqs(2, 'ECHEQS_DEPOSITADOS', 'DEPOSITADOS', 25)
], $PROVS_REALES);

$erroresEcheqs = array_values(array_filter($juntas['errores'], function ($e) {
    return mb_stripos($e, 'Echeqs') !== false;
}));

chequear('A_COBRAR y DEPOSITADOS activas juntas: el validador las acepta', [], $erroresEcheqs);

$conTotal = CashflowEstructura::validar($secDisp, [
    filaEcheqs(1, 'ECHEQS_TODO', 'A_COBRAR_TODO', 20),
    filaEcheqs(2, 'ECHEQS_DEPOSITADOS', 'DEPOSITADOS', 25)
], $PROVS_REALES);

chequear('el total al lado de DEPOSITADOS se rechaza: contaria dos veces', true,
    count(array_filter($conTotal['errores'], function ($e) {
        return mb_stripos($e, 'se contaría dos veces') !== false;
    })) > 0);

seccion('el script de la fila');

$SQL_FILA = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_estructura_echeqs_depositados.sql'));

chequear('crea ECHEQS_DEPOSITADOS en DISPONIBILIDADES, orden 25, ingreso que computa', 1,
    substr_count($SQL_FILA, "('ECHEQS_DEPOSITADOS', 'Echeqs depositados', 'DISPONIBILIDADES', 'INGRESO', 1,\n"
        . "            'ECHEQS', 'DEPOSITADOS', 25)"));
chequear('con MERGE sobre CODIGO', 1, substr_count($SQL_FILA, 'ON T.CODIGO = S.CODIGO'));
chequear('y si ya existe no pisa el nombre ni el orden', true,
    preg_match('/WHEN MATCHED THEN.*?UPDATE SET ORIGEN_PROVIDER = S\.ORIGEN_PROVIDER,\s+ORIGEN_SERIE = S\.ORIGEN_SERIE,\s+FECHA_MODIF = GETDATE\(\)\s+WHEN NOT MATCHED/s', $SQL_FILA) === 1);
chequear('sin la seccion no inserta una fila huerfana', true,
    strpos($SQL_FILA, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = 'DISPONIBILIDADES')") !== false);

seccion('las tarjetas: la cartera cobrable sin depositados, y los depositados aparte');

$tot = ['total_tramo' => 1000.0, 'total_meses' => 500.0, 'total_horizonte' => 1500.0];
$exc = ['total_tramo' => 100.0, 'total_meses' => 0.0, 'total_horizonte' => 100.0];
$dep = ['total_tramo' => 300.0, 'total_meses' => 50.0, 'total_horizonte' => 350.0];

chequear('cobrable = universo - excluidos - depositados, por vista',
    ['total_tramo' => 600.0, 'total_meses' => 450.0, 'total_horizonte' => 1050.0],
    Echeqs::totalesNetos(Echeqs::totalesNetos($tot, $exc), $dep));

$ctrlCartera = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/EcheqsController.php'));
chequear('el controller resta los dos con el mismo helper', 1, substr_count($ctrlCartera,
    "Echeqs::totalesNetos(\n                Echeqs::totalesNetos(\$payload['totales'], \$payload['excluidos']),\n                \$payload['depositados']);"));
chequear('manda si se puede depositar, para apagar el boton con el motivo', 1,
    substr_count($ctrlCartera, "\$payload['deposito'] = \$echeqs->estadoParaDepositar();"));
chequear('el aviso de los acreditados entra al panel como atencion', 1,
    substr_count($ctrlCartera, "\$payload['warnings'][] = ['nivel' => 'warning', 'texto' => \$aviso];"));
chequear('y el del hueco entre scripts, como critico', 1,
    substr_count($ctrlCartera, "\$payload['warnings'][] = ['nivel' => 'danger', 'texto' => 'Hay cheques '"));

$p = Echeqs::sinDeposito('Los cheques no tienen ninguna alícuota vigente al 21/10/2026, así que no se puede '
    . 'calcular el importe neto del depósito. Cargá sus alícuotas en Parámetros → Cheques con una '
    . 'vigencia igual o anterior a esa fecha.', [], []);
chequear('sin alicuotas, el dialogo ofrece ir a Parametros -> Cheques', 'PRECHEQUEADO', $p['ir_a']);
chequear('con bancos mezclados, no', null, Echeqs::sinDeposito('un solo banco emisor', [], [])['ir_a']);
