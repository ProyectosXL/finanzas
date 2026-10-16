<?php
/**
 * Echeqs: los cheques depositados, leidos de Tango.
 *
 * Como el resto del modulo, lo delicado son CRITERIOS y no consultas: que es
 * un deposito, cuando acredita, con que tasa, cual se proyecta y en que serie
 * cae cada peso. Viven en helpers estaticos
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
seccion('el script del deposito: alicuotas y plazo');

chequear('ya no crea la tabla de depositos del circuito viejo', false,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO') !== false);
chequear('ni la borra: no hay bajas fisicas', false,
    stripos($SQL_DEPOSITO, 'DROP TABLE') !== false || stripos($SQL_DEPOSITO, 'DELETE ') !== false);
chequear('y el encabezado la documenta como en desuso', true,
    strpos($SQL_DEPOSITO, '1. RO_T_CASHFLOW_ECHEQ_DEPOSITO, EN DESUSO') !== false);
chequear('crea la de alicuotas propia, no filas en la de Cob. Electronicos', true,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA') !== false
    && strpos($SQL_DEPOSITO, 'INSERT INTO dbo.RO_T_CASHFLOW_COBEL') === false);
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
chequear('es reejecutable: la tabla pregunta si existe', 1,
    preg_match_all("/IF OBJECT_ID\('dbo\.RO_T_CASHFLOW_ECHEQ_ALICUOTA', 'U'\) IS NULL/", $SQL_DEPOSITO));

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
   Las alicuotas no recalculan nada: el neto se calcula al leer
   ================================================================ */
seccion('cambiar una alicuota no recalcula nada guardado');

$srcEcheqs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));
chequear('ya no existe el recalculo de los depositos pendientes', false,
    method_exists('Echeqs', 'recalcularDepositosPendientes'));
chequear('cargar o dar de baja una alicuota no devuelve un recalculo', 0,
    substr_count($srcEcheqs, "'recalculo'"));

$ctrlParam = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/ParametrosController.php'));
chequear('el controller de Parametros ya no arma el mensaje del recalculo de depositos', false,
    strpos($ctrlParam, 'mensajeRecalculoDepositos') !== false);

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
   Los depositos, leidos de Tango
   ================================================================ */

/** Una fila de cartera como las de getEcheqsCartera(), armada a mano */
function chequeCartera($id, $importe = 100000.0, $extra = []) {
    return Echeqs::ubicarEnEje(array_merge([
        'ID_SBA14' => $id,
        'N_CHEQUE' => 9000 + $id,
        'FECHA_CHEQUE' => '2026-10-30',
        'FECHA_PAGO' => '2026-10-30',
        'ID_BANCO' => 10,
        'BANCO' => 'GALICIA',
        'IMPORTE' => $importe,
        'CLIENTE' => 'CLIENTE ' . $id,
        'COD_CLIENTE' => 'FR' . $id,
        'EXCLUIDO' => false,
        'DEPOSITO' => null
    ], $extra));
}

/** Una fila de sqlDepositados(), normalizada, armada a mano */
function chequeDepositado($id, $fechaSal, $importe = 100000.0, $extra = []) {
    return array_merge([
        'ID_SBA14' => $id,
        'N_CHEQUE' => 9000 + $id,
        'FECHA_CHEQUE' => '2026-10-30',
        'FECHA_PAGO' => '2026-10-30',
        'ID_BANCO' => 10,
        'BANCO' => 'GALICIA',
        'IMPORTE' => $importe,
        'CLIENTE' => 'CLIENTE ' . $id,
        'COD_CLIENTE' => 'FR' . $id,
        'EXCLUIDO' => false,
        'ESTADO' => 'A',
        'T_COMP_SAL' => 'BDE',
        'N_COMP_SAL' => '0200500004' . $id,
        'TIPO_SAL' => 'D',
        'CTA_DESTIN' => 110500,
        'FECHA_SAL' => $fechaSal
    ], $extra);
}

$ctaBanco = ['cod_cta' => 110500, 'descripcion' => 'BCO.RIO CTA.CTE. $', 'tipo' => 'B'];
$ctaComitente = ['cod_cta' => 110530, 'descripcion' => 'BCO. RIO - CTA COMITENTE 7786', 'tipo' => 'O'];
$ctaCaja = ['cod_cta' => 100100, 'descripcion' => 'CAJA FABRICA $', 'tipo' => 'O'];

seccion('que es un deposito');

$bde = chequeDepositado(1, '2026-10-15');

chequear('BDE con ESTADO = A a una cuenta bancaria: deposito', true,
    Echeqs::esDepositoTango($bde, $ctaBanco));
chequear('una orden de pago (O/P) es un endoso a un proveedor: no', false,
    Echeqs::esDepositoTango(array_merge($bde, ['T_COMP_SAL' => 'O/P', 'TIPO_SAL' => 'P']), null));
chequear('en cartera (C) no es un deposito', false,
    Echeqs::esDepositoTango(array_merge($bde, ['ESTADO' => 'C']), $ctaBanco));
chequear('rechazado (R) no se lee', false,
    Echeqs::esDepositoTango(array_merge($bde, ['ESTADO' => 'R']), $ctaBanco));
chequear('anulado (X) tampoco', false,
    Echeqs::esDepositoTango(array_merge($bde, ['ESTADO' => 'X']), $ctaBanco));
chequear('con FECHA_SAL centinela no', false,
    Echeqs::esDepositoTango(array_merge($bde, ['FECHA_SAL' => '1800-01-01']), $ctaBanco));
chequear('sin FECHA_SAL tampoco', false,
    Echeqs::esDepositoTango(array_merge($bde, ['FECHA_SAL' => null]), $ctaBanco));
chequear('un mayorista (M) si: el universo de cartera es [FLM]', true,
    Echeqs::esDepositoTango(array_merge($bde, ['COD_CLIENTE' => 'MAVENF']), $ctaBanco));
chequear('un cheque sin cliente (CAJA INGRESO) no', false,
    Echeqs::esDepositoTango(array_merge($bde, ['COD_CLIENTE' => '']), $ctaBanco));
chequear('ni otro prefijo', false,
    Echeqs::esDepositoTango(array_merge($bde, ['COD_CLIENTE' => 'O00001']), $ctaBanco));
chequear('un local (L) si', true,
    Echeqs::esDepositoTango(array_merge($bde, ['COD_CLIENTE' => 'L00001']), $ctaBanco));
chequear('la comparacion del cliente es binaria, como la columna', false,
    Echeqs::esDepositoTango(array_merge($bde, ['COD_CLIENTE' => 'fr0001']), $ctaBanco));
chequear('TIPO_SAL = O con CTA_DESTIN en 0 a un banco: deposito', true,
    Echeqs::esDepositoTango(array_merge($bde, ['TIPO_SAL' => 'O', 'CTA_DESTIN' => 0]), $ctaBanco));
chequear('a la cuenta comitente (endoso a Santander Valores): no', false,
    Echeqs::esDepositoTango($bde, $ctaComitente));
chequear('a la caja (una devolucion): no', false, Echeqs::esDepositoTango($bde, $ctaCaja));
chequear('sin la linea de la boleta: cuenta como deposito', true,
    Echeqs::esDepositoTango($bde, null));
chequear('el comprobante es el codigo BDE, no el id de SBA02', 'BDE', Echeqs::COMPROBANTE_DEPOSITO);

seccion('la fecha de acreditacion y el importe');

// Octubre y noviembre de 2026 con el lunes 12/10 feriado.
$cal = [];

for ($i = 0; $i < 92; $i++) {
    $d = date('Y-m-d', strtotime('2026-09-01 +' . $i . ' day'));
    $cal[$d] = intval(date('N', strtotime($d))) <= 5 && $d !== '2026-10-12';
}

// IIBB 2,5% y SICREB 0,6% desde el 1/10; IIBB 3% desde el 21/10.
$ali = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 3, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.030, 'VIGENCIA_DESDE' => '2026-10-21', 'ACTIVO' => 1]
];
$plazo2 = Echeqs::plazoDesdeValor('2');

/** El deposito resuelto de una boleta, con el resto de los datos fijos */
function depositoDe($fechaSal, $hoy, $extra = [], $marca = null) {
    global $cal, $ali, $plazo2, $ctaBanco;

    return Echeqs::resolverDeposito(chequeDepositado(1, $fechaSal, 100000.0, $extra), $ctaBanco,
        $plazo2, $cal, $ali, $marca, $hoy);
}

chequear('una boleta del viernes acredita el martes', '2026-10-20',
    depositoDe('2026-10-16', '2026-10-16')['fecha_acreditacion']);
chequear('con el lunes feriado, se corre un dia mas: viernes -> miercoles', '2026-10-14',
    depositoDe('2026-10-09', '2026-10-09')['fecha_acreditacion']);
chequear('lunes -> miercoles', '2026-10-21', depositoDe('2026-10-19', '2026-10-19')['fecha_acreditacion']);
chequear('la fecha del cheque no interviene, aunque sea posterior', '2026-10-20',
    depositoDe('2026-10-16', '2026-10-16', ['FECHA_CHEQUE' => '2026-11-29',
                                           'FECHA_PAGO' => '2026-11-29'])['fecha_acreditacion']);
chequear('ni aunque sea anterior', '2026-10-20',
    depositoDe('2026-10-16', '2026-10-16', ['FECHA_CHEQUE' => '2026-10-01',
                                           'FECHA_PAGO' => '2026-10-01'])['fecha_acreditacion']);

$d = depositoDe('2026-10-19', '2026-10-19');
chequear('la tasa es la vigente a la ACREDITACION (21/10), no a la boleta', 0.036, $d['tasa']);
chequear('con su desglose', 'IIBB 3% + SICREB 0,6%', $d['desglose']);
chequear('el neto es bruto x (1 - tasa)', 96400.0, $d['neto']);
chequear('y el bruto, el del cheque', 100000.0, $d['bruto']);
chequear('dice con que plazo se calculo', 2, $d['dias_plazo']);
chequear('y trae la boleta, su fecha, TIPO_SAL, CTA_DESTIN y la cuenta, para el tooltip',
    ['02005000041', '2026-10-19', 'D', 110500, 110500],
    [$d['boleta'], $d['fecha_deposito'], $d['tipo_sal'], $d['cta_destin'], $d['cuenta']['cod_cta']]);

// Un cambio de alicuota se refleja sin recalcular nada: la misma boleta con
// otro juego da otro neto, porque no hay nada guardado.
$aliNueva = array_merge($ali, [
    ['ID' => 4, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.010, 'VIGENCIA_DESDE' => '2026-10-20', 'ACTIVO' => 1]
]);
$d2 = Echeqs::resolverDeposito(chequeDepositado(1, '2026-10-19'), $ctaBanco, $plazo2, $cal, $aliNueva,
    null, '2026-10-19');
chequear('un cambio de alicuota se refleja solo, al leer', [0.04, 96000.0], [$d2['tasa'], $d2['neto']]);

chequear('y el plazo tambien: con 3 dias la misma boleta acredita un dia despues', '2026-11-04',
    Echeqs::resolverDeposito(chequeDepositado(1, '2026-10-30'), $ctaBanco, Echeqs::plazoDesdeValor('3'),
        $cal, $ali, null, '2026-10-30')['fecha_acreditacion']);

$sinCal = Echeqs::resolverDeposito(chequeDepositado(1, '2026-11-30'), $ctaBanco, $plazo2, $cal, $ali,
    null, '2026-11-30');
chequear('sin calendario, el respaldo de lunes a viernes', '2026-12-02', $sinCal['fecha_acreditacion']);
chequear('y dice que mes falta', ['2026-12'], $sinCal['faltan']);

$roto = [];

for ($i = 0; $i < 60; $i++) {
    $roto[date('Y-m-d', strtotime('2026-10-16 +' . $i . ' day'))] = false;
}

$d = Echeqs::resolverDeposito(chequeDepositado(1, '2026-10-15'), $ctaBanco, $plazo2, $roto, $ali, null,
    '2026-10-16');
chequear('sin ningun habil en el tope, la fecha es null y no se inventa', [null, null],
    [$d['fecha_acreditacion'], $d['neto']]);
chequear('no se proyecta: SIN_DATOS, con el motivo', [Echeqs::DEPOSITO_SIN_DATOS, true],
    [$d['estado'], strpos((string) $d['error'], 'Revisá RO_T_CALENDARIO') !== false]);

$d = Echeqs::resolverDeposito(chequeDepositado(1, '2026-10-15'), $ctaBanco, $plazo2, $cal, [], null,
    '2026-10-16');
chequear('sin alicuotas vigentes a la acreditacion, el neto es null, no el bruto', [null, null],
    [$d['tasa'], $d['neto']]);
chequear('y no se proyecta', Echeqs::DEPOSITO_SIN_DATOS, $d['estado']);
chequear('el motivo dice donde cargarlas', true, strpos($d['error'], 'Parámetros → Cheques') !== false);

$d = Echeqs::resolverDeposito(chequeDepositado(1, '2026-10-15'), $ctaBanco, Echeqs::plazoDesdeValor(null),
    $cal, $ali, null, '2026-10-16');
chequear('sin plazo no hay fecha: SIN_DATOS, nombrando el script', [null, Echeqs::DEPOSITO_SIN_DATOS, true],
    [$d['fecha_acreditacion'], $d['estado'], strpos($d['error'], Echeqs::SCRIPT_DEPOSITO) !== false]);

seccion('cuanto tiempo se proyecta');

// Hoy es el viernes 16/10.
$hoyP = '2026-10-16';

$d = depositoDe('2026-10-15', $hoyP);
chequear('acredita manana o despues (lunes 19): se proyecta', [Echeqs::DEPOSITO_PROYECTA, '2026-10-19', true],
    [$d['estado'], $d['fecha_acreditacion'], $d['listar']]);

$d = depositoDe('2026-10-14', $hoyP);
chequear('acredita HOY: acreditado por plazo, se lista sin sumar',
    [Echeqs::DEPOSITO_ACREDITADO, Echeqs::ACREDITADO_POR_PLAZO, true],
    [$d['estado'], $d['motivo'], $d['listar']]);

$d = depositoDe('2026-10-13', $hoyP);
chequear('acredito ayer: ya no se lista', [Echeqs::DEPOSITO_ACREDITADO, false], [$d['estado'], $d['listar']]);

$marcaManual = ['id' => 5, 'origen' => 'MANUAL', 'referencia' => null, 'observacion' => 'lo vi en el extracto',
                'fecha_acreditacion' => '2026-10-16', 'usuario' => 'rorozco',
                'fecha_alta' => '2026-10-16 11:00:00'];
$marcaIb = array_merge($marcaManual, ['id' => 6, 'origen' => 'INTERBANKING', 'referencia' => 'IB-778899']);

$d = depositoDe('2026-10-15', $hoyP, [], $marcaManual);
chequear('marcado "ya acredito": sale aunque falten dias, y se lista hasta su fecha',
    [Echeqs::DEPOSITO_ACREDITADO, Echeqs::ACREDITADO_POR_MARCA, true],
    [$d['estado'], $d['motivo'], $d['listar']]);
chequear('la marca viaja para el tooltip', 'lo vi en el extracto', $d['marca']['observacion']);

$dIb = depositoDe('2026-10-15', $hoyP, [], $marcaIb);
chequear('una marca de INTERBANKING tiene el mismo efecto que una MANUAL', [$d['estado'], $d['motivo']],
    [$dIb['estado'], $dIb['motivo']]);

chequear('deshacer la marca (sin marca vigente): vuelve a proyectarse si sigue en plazo',
    Echeqs::DEPOSITO_PROYECTA, depositoDe('2026-10-15', $hoyP, [], null)['estado']);
chequear('una marca sobre uno que acredito ayer no lo vuelve a listar', false,
    depositoDe('2026-10-13', $hoyP, [], $marcaManual)['listar']);

seccion('la lectura: que se lee, que se lista y que se avisa');

chequear('se leen las boletas desde hoy - plazo x corrimiento, y el calendario hasta lo mismo adelante',
    ['desde' => '2026-08-17', 'hasta' => '2026-12-15'], Echeqs::rangoDepositos('2026-10-16', 2));
chequear('sin plazo, un solo tramo de corrimiento', ['desde' => '2026-09-16', 'hasta' => '2026-11-15'],
    Echeqs::rangoDepositos('2026-10-16', null));

$filasTango = [
    chequeDepositado(1, '2026-10-15', 100000.0),                                     // proyecta
    chequeDepositado(2, '2026-10-14', 50000.0),                                      // acredita hoy
    chequeDepositado(3, '2026-10-13', 70000.0),                                      // ya acredito
    chequeDepositado(4, '2026-10-15', 30000.0, ['N_COMP_SAL' => 'COMITENTE']),       // no es deposito
    chequeDepositado(5, '2026-10-15', 20000.0, ['N_COMP_SAL' => 'SINLINEA']),        // falta la cuenta
    chequeDepositado(6, '2026-10-15', 10000.0, ['T_COMP_SAL' => 'O/P']),             // endoso
    chequeDepositado(7, '2026-10-15', 40000.0, ['EXCLUIDO' => false])                // excluido en cartera
];
$cuentasTango = [];

foreach ($filasTango as $f) {
    $cuentasTango[$f['N_COMP_SAL']] = $ctaBanco;
}

$cuentasTango['COMITENTE'] = $ctaComitente;
unset($cuentasTango['SINLINEA']);

$lect = Echeqs::resolverDepositos($filasTango, $cuentasTango, $plazo2, $cal, $ali, [], $hoyP);
$porId = [];

foreach ($lect['filas'] as $f) {
    $porId[$f['ID_SBA14']] = $f;
}

chequear('se listan los depositos que todavia no vencieron', [1, 2, 5, 7], array_keys($porId));
chequear('el endoso y la cuenta comitente no estan', [false, false], [isset($porId[4]), isset($porId[6])]);
chequear('el que falta la cuenta se proyecta igual', Echeqs::DEPOSITO_PROYECTA, $porId[5]['ESTADO_DEPOSITO']);
chequear('y avisa la boleta, como atencion', true, count(array_filter($lect['avisos'], function ($a) {
    return $a['nivel'] === 'warning' && strpos($a['texto'], 'SINLINEA') !== false;
})) === 1);
chequear('un cheque excluido en cartera que despues se deposita se proyecta: la exclusion es de cartera',
    [Echeqs::DEPOSITO_PROYECTA, false], [$porId[7]['ESTADO_DEPOSITO'], $porId[7]['EXCLUIDO']]);
chequear('cada fila ya viene ubicada en el eje: acreditacion y neto',
    ['2026-10-19', 96900.0], [$porId[1]['FECHA_EJE'], $porId[1]['IMPORTE_EJE']]);
chequear('la de hoy, en ninguna columna', [null, 0.0], [$porId[2]['FECHA_EJE'], $porId[2]['IMPORTE_EJE']]);
chequear('con el calendario completo y todo en orden, un solo aviso: la boleta sin cuenta', 1,
    count($lect['avisos']));

$lectSin = Echeqs::resolverDepositos($filasTango, $cuentasTango, $plazo2, $cal, [], [], $hoyP);
$criticos = array_values(array_filter($lectSin['avisos'], function ($a) { return $a['nivel'] === 'danger'; }));
chequear('sin alicuotas, un aviso critico con cuantos y cuanto', true,
    count($criticos) === 1 && strpos($criticos[0]['texto'], '3 cheque(s) depositado(s) en Tango por $ 160.000,00') === 0);
chequear('el de hoy no cuenta: ya acredito y no se proyectaria igual', true,
    strpos($criticos[0]['texto'], '210.000') === false);

$lectMarca = Echeqs::resolverDepositos($filasTango, $cuentasTango, $plazo2, $cal, $ali, [1 => $marcaManual], $hoyP);
chequear('la marca se cruza por ID_SBA14', Echeqs::DEPOSITO_ACREDITADO, $lectMarca['filas'][0]['ESTADO_DEPOSITO']);

seccion('donde cae cada fila en el eje');

$normal = chequeCartera(20);
chequear('una de cartera, en la fecha del cheque y por el bruto', ['2026-10-30', 100000.0],
    [$normal['FECHA_EJE'], $normal['IMPORTE_EJE']]);
chequear('con Acredita, Retencion y Neto vacios, no en cero', [null, null, null],
    [$normal['ACREDITA'], $normal['RETENCION'], $normal['NETO']]);
chequear('el acreditado hoy muestra su deposito', ['2026-10-16', 1550.0, 48450.0],
    [$porId[2]['ACREDITA'], $porId[2]['RETENCION'], $porId[2]['NETO']]);

$sinNeto = $lectSin['filas'][0];
chequear('uno sin neto: Retencion y Neto vacios, y en ninguna columna', [null, null, null, 0.0],
    [$sinNeto['RETENCION'], $sinNeto['NETO'], $sinNeto['FECHA_EJE'], $sinNeto['IMPORTE_EJE']]);

$r = Echeqs::resumenDepositos(array_merge(
    [array_merge($porId[1], ['total_tramo' => 96900.0, 'total_meses' => 0, 'total_horizonte' => 96900.0])],
    [$porId[2], $normal, $sinNeto]));
chequear('el resumen cuenta los que se proyectan con bruto, retencion y neto',
    [1, 100000.0, 3100.0, 96900.0], [$r['cheques'], $r['bruto'], $r['retencion'], $r['neto']]);
chequear('y sus totales por vista', 96900.0, $r['total_horizonte']);
chequear('los acreditados van aparte', [1, 50000.0], [$r['acreditados']['cheques'], $r['acreditados']['bruto']]);
chequear('y los sin datos, aparte tambien', [1, 100000.0], [$r['sin_datos']['cheques'], $r['sin_datos']['bruto']]);

seccion('las consultas');

$sqlCart = str_replace("\r\n", "\n", Echeqs::sqlCartera(true));
chequear('la cartera es solo ESTADO = C, sin union con ningun deposito', [false, true], [
    strpos($sqlCart, 'DEPOSITO') !== false,
    strpos($sqlCart, "WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)\n                  AND s.ESTADO = ?") !== false
]);
chequear('la agregada usa el mismo universo', true,
    strpos(str_replace("\r\n", "\n", Echeqs::sqlCarteraTotales(true)),
        "WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)\n                  AND s.ESTADO = ?") !== false);
chequear('y sin el script no agrupa por una constante', false,
    strpos(Echeqs::sqlCarteraTotales(false), 'GROUP BY CAST(s.FECHA_CHEQ AS DATE),') !== false);

$sqlDep = str_replace("\r\n", "\n", Echeqs::sqlDepositados());
chequear('los depositados aplican el filtro del helper: estado, comprobante, [FLM] y fecha desde', true,
    strpos($sqlDep, "WHERE s.ESTADO = ?\n                  AND s.T_COMP_SAL = ?\n                  AND s.CLIENTE LIKE '[FLM]%'\n                  AND s.FECHA_SAL >= ?") !== false);

/* UN SOLO UNIVERSO PARA CHEQUES EN CARTERA: la cartera, la agregada, los
   depositados y el resumen de excluidos dicen [FLM], y el helper tambien. Si
   uno dijera otra cosa, la pestana y el tablero mirarian dos universos. */
chequear('el universo de cartera es franquicias, locales y mayoristas', ['[FLM]%', '/^[FLM]/'],
    [Echeqs::UNIVERSO_CARTERA_SQL, Echeqs::UNIVERSO_CARTERA_REGEX]);
chequear('la cartera y la agregada lo usan', [true, true], [
    strpos(Echeqs::sqlCartera(true), "s.CLIENTE LIKE '[FLM]%'") !== false,
    strpos(Echeqs::sqlCarteraTotales(true), "s.CLIENTE LIKE '[FLM]%'") !== false
]);

$srcUniverso = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));
$iniExc = strpos($srcUniverso, 'public function getExclusionesEnCartera(');
chequear('el resumen de excluidos tambien', true,
    strpos(substr($srcUniverso, $iniExc, 1500), "s.CLIENTE LIKE '\" . self::UNIVERSO_CARTERA_SQL . \"'") !== false);
$iniPre = strpos($srcUniverso, 'private function getChequesDeClientes(');
chequear('la venta cobrada anticipada sigue en [FL]: es otro circuito', true,
    strpos(substr($srcUniverso, $iniPre, 2500), "AND s.CLIENTE LIKE '[FL]%'") !== false);
chequear('y ninguna consulta de cartera o depositados quedo en [FL]', 1,
    substr_count($srcUniverso, "s.CLIENTE LIKE '[FL]%'"));
chequear('y traen lo que muestra el tooltip', true,
    strpos($sqlDep, 's.N_COMP_SAL') !== false && strpos($sqlDep, 's.TIPO_SAL') !== false
    && strpos($sqlDep, 'AS CTA_DESTIN') !== false);
chequear('las boletas y las cuentas se cruzan en PHP: sin JOIN a SBA05 ni SBA01', false,
    strpos($sqlDep, 'SBA05') !== false || strpos($sqlDep, 'SBA01') !== false);

/* RO_T_CASHFLOW_ECHEQ_DEPOSITO NO SE LEE. Es la tabla del circuito viejo: lo
   que tiene son pruebas, y ningun dato suyo puede entrar al tablero. Se busca
   como se la usaria en una consulta -con dbo. o entre comillas-: un
   comentario puede nombrarla para decir justamente que no se lee. */
$leenDeposito = [];

foreach (['Class', 'Controller', 'Js', 'Tabs'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));

    foreach ($it as $archivo) {
        if ($archivo->isFile()
            && preg_match("/dbo\.RO_T_CASHFLOW_ECHEQ_DEPOSITO|['\"]RO_T_CASHFLOW_ECHEQ_DEPOSITO['\"]|ECHEQ_DEPOSITO'/",
                file_get_contents($archivo->getPathname()))) {
            $leenDeposito[] = $dir . '/' . $archivo->getFilename();
        }
    }
}

chequear('ningun archivo de Class, Controller, Js ni Tabs consulta RO_T_CASHFLOW_ECHEQ_DEPOSITO', [],
    $leenDeposito);
chequear('ni existe la constante de esa tabla', false, defined('Echeqs::TABLA_DEPOSITO'));

$srcEcheqs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));
chequear('el chequeo del script mira las alicuotas, no la tabla vieja', [true, false], [
    strpos($srcEcheqs, "\"SELECT OBJECT_ID('dbo.\" . self::TABLA_ALICUOTA . \"', 'U') AS A\"") !== false,
    method_exists('Echeqs', 'depositoCreado')
]);

$bloqueExcluir = substr($srcEcheqs, strpos($srcEcheqs, 'public function excluirCheques('), 4000);
chequear('excluir valida contra la cartera: un depositado vuelve como rechazado', true,
    strpos($bloqueExcluir, "foreach (\$this->getEcheqsCartera() as \$fila) {") !== false);

seccion('el circuito Depositar del cashflow ya no existe');

foreach (['previsualizarDeposito', 'depositarCheques', 'deshacerDepositos'] as $accion) {
    chequear($accion . ' salio del mapa de permisos', false,
        isset(AuthCashflow::ESCRITURAS['Echeqs'][$accion]));
}

foreach (['validarSeleccionDeposito', 'calcularDeposito', 'desgloseBancos', 'textoTanda',
          'validarDeshacer', 'estadoParaDepositar', 'previsualizarDeposito', 'sinDeposito',
          'depositarCheques', 'paramsInsertDeposito', 'deshacerDepositos', 'estadoDeposito',
          'visibleEnCartera', 'avisoAcreditadosSinRegistrar', 'getHistorialDepositos',
          'hayDepositos'] as $metodo) {
    chequear('Echeqs::' . $metodo . '() ya no existe', false, method_exists('Echeqs', $metodo));
}

/* NINGUN TEXTO DICE YA LA REGLA VIEJA: el registro en Tango es ahora lo
   primero que pasa, no algo que falta. */
$textosViejos = ['falta registrar', 'sin registrar', 'registrá el depósito', 'Depositar ',
                 'deshacé el depósito'];

foreach (['Class/Echeqs.php', 'Class/Providers/EcheqsProvider.php', 'Controller/EcheqsController.php',
          'Js/Ingresos-Echeqs.js', 'Tabs/echeqs.php', 'Js/Parametros-Prechequeado.js',
          'Tabs/parametros_prechequeado.php'] as $archivo) {
    $txt = file_get_contents(__DIR__ . '/../' . $archivo);
    $hallados = array_values(array_filter($textosViejos, function ($t) use ($txt) {
        return mb_stripos($txt, $t) !== false;
    }));

    chequear($archivo . ' ya no habla del circuito viejo', [], $hallados);
}

/* ================================================================
   El proveedor: la particion
   ================================================================ */
seccion('el proveedor: la cartera en A_COBRAR, los depositados en DEPOSITADOS');

require_once __DIR__ . '/../Class/Providers/EcheqsProvider.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';
require_once __DIR__ . '/../Class/EjeVista.php';

/** Eje del 16/10/2026: 28 dias y 12 meses */
$hDep = new Horizonte(28, 12, [], new DateTime('2026-10-16'));

/** Una fila agregada de getEcheqsCarteraTotales(), armada a mano */
function itemCartera($fecha, $importe, $extra = []) {
    return array_merge(['FECHA_PAGO' => $fecha, 'EXCLUIDO' => false, 'IMPORTE' => $importe,
                        'CHEQUES' => 1], $extra);
}

/** El total de una serie, con lo que quedo fuera del eje */
function totalSerieDep($s) {
    return array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'];
}

$items = array_merge([
    itemCartera('2026-10-20', 1000.0),                              // cobrable
    itemCartera('2026-10-22', 400.0, ['EXCLUIDO' => true])          // excluido
], EcheqsProvider::itemsDeDepositados($lect['filas']));

$s = EcheqsProvider::repartir($hDep, $items);

// Se proyectan el 1 (96.900), el 5 (19.380) y el 7 (38.760), todos el 19/10.
chequear('DEPOSITADOS suma los netos de los que se proyectan', 96900.0 + 19380.0 + 38760.0,
    totalSerieDep($s['DEPOSITADOS']));
chequear('en su fecha de ACREDITACION', 155040.0, $s['DEPOSITADOS']['dias']['2026-10-19']);
chequear('y no en la fecha del cheque', 0.0, $s['DEPOSITADOS']['dias']['2026-10-30']);
chequear('A_COBRAR no incluye ningun depositado', 1000.0, totalSerieDep($s['A_COBRAR']));
chequear('los excluidos quedan donde estaban', 400.0, totalSerieDep($s['A_COBRAR_EXCLUIDOS']));
chequear('el que acredita hoy no esta en ninguna serie', 0.0,
    $s['A_COBRAR_TODO']['dias']['2026-10-16'] + $s['DEPOSITADOS']['dias']['2026-10-16']);

/* EL INVARIANTE: cada cheque en una sola parte, y las partes cierran contra el
   total. Columna por columna, no solo en el total. */
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
chequear('A_COBRAR_TODO es cartera en bruto mas depositados en neto', 1000.0 + 400.0 + 155040.0,
    totalSerieDep($s['A_COBRAR_TODO']));
chequear('la retencion es el bruto menos el neto de los que se proyectan', 160000.0 - 155040.0,
    $s['DEPOSITADOS']['retencion']);
chequear('y el universo en bruto = A_COBRAR_TODO + retencion', 1000.0 + 400.0 + 160000.0,
    totalSerieDep($s['A_COBRAR_TODO']) + $s['DEPOSITADOS']['retencion']);

$sMarca = EcheqsProvider::repartir($hDep, EcheqsProvider::itemsDeDepositados($lectMarca['filas']));
chequear('marcado "ya acredito", sale de DEPOSITADOS aunque falten dias', 19380.0 + 38760.0,
    totalSerieDep($sMarca['DEPOSITADOS']));

$sSin = EcheqsProvider::repartir($hDep, EcheqsProvider::itemsDeDepositados($lectSin['filas']));
chequear('sin neto no suma nada: ni el bruto ni un cero inventado', [0.0, 0.0],
    [floatval(totalSerieDep($sSin['DEPOSITADOS'])), floatval(totalSerieDep($sSin['A_COBRAR_TODO']))]);

$sinNada = EcheqsProvider::repartir($hDep, [itemCartera('2026-10-20', 1000.0)]);
chequear('sin depositos, DEPOSITADOS va en cero y las cuatro series existen', [0.0, 4],
    [floatval(totalSerieDep($sinNada['DEPOSITADOS'])), count($sinNada)]);

$lejos = EcheqsProvider::repartir($hDep, [itemCartera('2026-10-20', 100.0, ['DEPOSITO' => [
    'estado' => Echeqs::DEPOSITO_PROYECTA, 'fecha_acreditacion' => '2030-01-02', 'bruto' => 100.0,
    'neto' => 96.9]])]);
chequear('una acreditacion fuera del eje se informa en la fila de depositados y en el total',
    [96.9, 96.9], [$lejos['DEPOSITADOS']['fuera_horizonte'], $lejos['A_COBRAR_TODO']['fuera_horizonte']]);

seccion('la pestana y el proveedor suman lo mismo');

// La grilla arma el eje con FECHA_EJE / IMPORTE_EJE, como el controller.
$filasGrilla = array_merge([chequeCartera(30, 1000.0, ['FECHA_PAGO' => '2026-10-20'])], $lect['filas']);
$payload = EjeVista::armar($hDep, $filasGrilla, 'FECHA_EJE', 'IMPORTE_EJE');
$resumen = Echeqs::resumenDepositos($payload['filas']);
$sGrilla = EcheqsProvider::repartir($hDep, array_merge([itemCartera('2026-10-20', 1000.0)],
    EcheqsProvider::itemsDeDepositados($lect['filas'])));

chequear('"Echeqs depositados": el total de la pestana es el de la serie del proveedor',
    round(totalSerieDep($sGrilla['DEPOSITADOS']), 2), round($resumen['total_horizonte'], 2));
chequear('y la grilla entera es A_COBRAR_TODO', round(totalSerieDep($sGrilla['A_COBRAR_TODO']), 2),
    round($payload['totales']['total_horizonte'], 2));

$srcProv = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Providers/EcheqsProvider.php'));
chequear('el proveedor lee los depositados por la misma lectura que la pestana', 1,
    substr_count($srcProv, "\$dep = \$echeqs->getDepositadosTango(\$h->hoy());"));
chequear('y deja sus avisos con su nivel', 1, substr_count($srcProv, "\$this->avisarTodos(\$dep['avisos']);"));

$ctrlCartera = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/EcheqsController.php'));
chequear('la pestana tambien', 1, substr_count($ctrlCartera, "\$dep = \$echeqs->getDepositadosTango(\$hoy);"));
chequear('y junta las dos lecturas en una grilla', 1,
    substr_count($ctrlCartera, "\$filas = array_merge(\$echeqs->getEcheqsCartera(), \$dep['filas']);"));

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

chequear('el controller resta los dos con el mismo helper', 1, substr_count($ctrlCartera,
    "Echeqs::totalesNetos(\n                Echeqs::totalesNetos(\$payload['totales'], \$payload['excluidos']),\n                \$payload['depositados']);"));
chequear('los avisos de los depositados entran al panel con su nivel', 1,
    substr_count($ctrlCartera, "foreach (\$dep['avisos'] as \$a) {\n                \$payload['warnings'][] = \$a;"));
chequear('y el de la fila del tablero, como critico, solo si hay depositados que se proyectan', 1,
    substr_count($ctrlCartera, "if (\$payload['depositados']['cheques'] > 0 && !\$echeqs->filaDepositadosActiva()) {"));

/* ================================================================
   La marca "ya acredito"
   ================================================================ */
seccion('ya acredito: solo sobre depositados que se proyectan, todo o nada');

$grilla = array_merge([chequeCartera(40)], $lect['filas']);
$grillaMarca = array_merge([chequeCartera(40)], $lectMarca['filas']);

$v = Echeqs::validarSeleccionAcreditado([5, 7], $grilla);
chequear('dos depositados que se proyectan se pueden marcar', [null, [5, 7]], [$v['error'], $v['ids']]);

$v = Echeqs::validarSeleccionAcreditado([5, 40], $grilla);
chequear('uno de cartera se rechaza, y con el no se marca ninguno',
    [true, [], 'está en cartera: todavía no se depositó en Tango'],
    [$v['error'] !== null, $v['ids'], $v['rechazos'][0]['motivo']]);

$v = Echeqs::validarSeleccionAcreditado([5, 3], $grilla);
chequear('uno vencido -ya no se lee- tambien', [true, []], [$v['error'] !== null, $v['ids']]);
chequear('y el mensaje dice cuantos y cuales', true,
    strpos($v['error'], '1 de los 2') !== false
    && strpos($v['error'], 'N° 3 no está entre los depositados') !== false);

$v = Echeqs::validarSeleccionAcreditado([4], $grilla);
chequear('uno endosado o a la cuenta comitente no esta en la lectura: se rechaza', true,
    $v['error'] !== null);

$v = Echeqs::validarSeleccionAcreditado([2], $grilla);
chequear('uno que acredita hoy ya no suma: se rechaza', 'acredita hoy: ya no suma a ninguna fila',
    $v['rechazos'][0]['motivo']);

$v = Echeqs::validarSeleccionAcreditado([1], $grillaMarca);
chequear('uno ya marcado no se vuelve a marcar: un solo vigente por cheque',
    'ya está marcado como acreditado', $v['rechazos'][0]['motivo']);

$v = Echeqs::validarSeleccionAcreditado([1], $lectSin['filas']);
chequear('uno sin datos no se proyecta: se rechaza', 0,
    strpos($v['rechazos'][0]['motivo'], 'no se proyecta'));

chequear('los ids se deduplican y los invalidos no cuentan', [5],
    Echeqs::validarSeleccionAcreditado([5, '5', 0, -1], $grilla)['ids']);
chequear('sin cheques, no hay marca', 'No llegó ningún cheque para marcar.',
    Echeqs::validarSeleccionAcreditado([], $grilla)['error']);

seccion('ya acredito: deshacer');

chequear('con todos marcados, se deshacen', [1],
    Echeqs::validarDeshacerAcreditado([1], [1 => $marcaManual])['ids']);
chequear('una marca de Interbanking se deshace igual', [1],
    Echeqs::validarDeshacerAcreditado([1], [1 => $marcaIb])['ids']);
chequear('con uno sin marca vigente, ninguno', true,
    Echeqs::validarDeshacerAcreditado([1, 5], [1 => $marcaManual])['error'] !== null);
chequear('sin la marca, el cheque vuelve a proyectarse si sigue en plazo', Echeqs::DEPOSITO_PROYECTA,
    $lect['filas'][0]['ESTADO_DEPOSITO']);

seccion('ya acredito: lo que se graba');

chequear('5 parametros por cheque: id, fecha de hoy, observacion y usuario dos veces',
    [5, '2026-10-16', 'extracto', 'rorozco', 'rorozco', 7, '2026-10-16', 'extracto', 'rorozco', 'rorozco'],
    Echeqs::paramsInsertAcreditado([5, 7], '2026-10-16', 'extracto', 'rorozco'));

$srcEcheqs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));
$iniMarcar = strpos($srcEcheqs, 'public function marcarAcreditados(');
$bloqueMarcar = substr($srcEcheqs, $iniMarcar,
    strpos($srcEcheqs, 'public function deshacerAcreditados(') - $iniMarcar);

chequear('el origen de una marca de la pantalla es MANUAL, puesto por el servidor', 1,
    substr_count($bloqueMarcar, "\"(?, ?, '\" . self::ORIGEN_MANUAL . \"', ?, 1, ?, ?)\""));
chequear('con el usuario de escritura', 1,
    substr_count($bloqueMarcar, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);'));
chequear('valida antes de abrir la transaccion, contra la lectura de hoy', true,
    strpos($bloqueMarcar, 'validarSeleccionAcreditado(') < strpos($bloqueMarcar, 'sqlsrv_begin_transaction')
    && strpos($bloqueMarcar, 'sqlsrv_rollback') !== false);
chequear('sin el script, lo dice', true, strpos($bloqueMarcar, 'self::SCRIPT_ACREDITADO') !== false);

$m = new ReflectionMethod('Echeqs', 'marcarAcreditados');
chequear('del navegador llegan solo ids y observacion: ni origen ni fecha',
    ['ids', 'observacion', 'usuario', 'hoy'],
    array_map(function ($p) { return $p->getName(); }, $m->getParameters()));

$bloqueDeshacer = substr($srcEcheqs, strpos($srcEcheqs, 'public function deshacerAcreditados('), 2500);
chequear('deshacer marca VIGENTE = 0 con quien y cuando, sin borrar', [true, false], [
    strpos($bloqueDeshacer, "SET VIGENTE = 0, \" . Auditoria::SET_BAJA . \"") !== false,
    stripos($bloqueDeshacer, 'DELETE') !== false
]);

foreach (['marcarAcreditados', 'deshacerAcreditados'] as $accion) {
    chequear($accion . ' pide editar Echeqs, como excluir', [['echeqs', null]],
        AuthCashflow::ESCRITURAS['Echeqs'][$accion]);
}

$bloqueCtrl = substr($ctrlCartera, strpos($ctrlCartera, "case 'marcarAcreditados':"), 900);
chequear('el controller no lee del pedido ni origen, ni fecha, ni referencia', 0,
    preg_match("/\\\$data\['(origen|fecha|referencia|acredita)/i", $bloqueCtrl));
chequear('sin el script de la marca, la pestana lo avisa y apaga el boton', [1, true], [
    substr_count($ctrlCartera, "\$payload['acreditado'] = \$echeqs->acreditadoCreado();"),
    strpos($ctrlCartera, 'Echeqs::SCRIPT_ACREDITADO') !== false
]);

$srcLect = substr($srcEcheqs, strpos($srcEcheqs, 'public function getMarcasAcreditado('), 2500);
chequear('sin el script no hay marcas: los depositados se proyectan hasta el plazo', true,
    strpos($srcLect, "if (empty(\$ids) || !\$this->acreditadoCreado()) {\n            return \$r;") !== false);

$iniRes = strpos($srcEcheqs, 'public static function resolverDeposito(');
$bloqueRes = substr($srcEcheqs, $iniRes, strpos($srcEcheqs, 'public static function resolverDepositos(') - $iniRes);
chequear('la regla no mira el origen: resolverDeposito() no lee la columna', false,
    strpos($bloqueRes, "['origen']") !== false);

seccion('el script de la marca');

$SQL_ACR = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../sql/cashflow_echeqs_acreditado.sql'));

foreach (['ID_SBA14', 'FECHA_ACREDITACION', 'ORIGEN', 'REFERENCIA', 'OBSERVACION', 'VIGENTE',
          'ID_REEMPLAZA', 'USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF',
          'USUARIO_BAJA', 'FECHA_BAJA'] as $col) {
    chequear('la marca guarda ' . $col, 1, preg_match('/\n\s+' . $col . '\s+[A-Z]/', $SQL_ACR));
}

chequear('crea la tabla si no existe: reejecutable', 1,
    substr_count($SQL_ACR, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_ACREDITADO', 'U') IS NULL"));
chequear('una sola marca vigente por cheque: indice unico filtrado', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQACR_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_ACREDITADO \(ID_SBA14\)\s+WHERE VIGENTE = 1/', $SQL_ACR));
chequear('una referencia del origen se graba una sola vez, aunque este dada de baja', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQACR_REFERENCIA\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_ACREDITADO \(ORIGEN, REFERENCIA\)\s+WHERE REFERENCIA IS NOT NULL;/', $SQL_ACR));
chequear('los origenes son MANUAL e INTERBANKING', 1,
    substr_count($SQL_ACR, "CHECK (ORIGEN IN ('MANUAL', 'INTERBANKING'))"));
chequear('y son los mismos de la clase', ['MANUAL', 'INTERBANKING'],
    [Echeqs::ORIGEN_MANUAL, Echeqs::ORIGEN_INTERBANKING]);
chequear('sin FK contra dbo.SBA14, sin bajas fisicas', [false, false],
    [strpos($SQL_ACR, 'REFERENCES dbo.SBA14') !== false, stripos($SQL_ACR, 'DELETE ') !== false]);
chequear('el encabezado dice que el proceso no puede volver a grabar una referencia', true,
    strpos($SQL_ACR, "NO\n   PUEDE VOLVER A GRABAR UNA REFERENCIA QUE YA EXISTE EN ESTA TABLA, AUNQUE ESTE\n   DADA DE BAJA") !== false);
