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
