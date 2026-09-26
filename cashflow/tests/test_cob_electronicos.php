<?php
/**
 * Modulo Cob. Electronicos.
 *
 * Todo lo delicado de este modulo son CRITERIOS, no consultas: que alicuota rige
 * a que fecha, que pasa con un movimiento cuya procesadora se quedo sin
 * alicuotas, en que columna del eje cae una acreditacion y que se recalcula
 * cuando alguien edita un porcentaje. Por eso esos criterios viven en helpers
 * estaticos puros y se verifican aca sin base, al estilo de test_saldos.php.
 *
 * La ultima seccion si toca la base y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../Class/CobElectronicos.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/Providers/CobElectronicosProvider.php';

/**
 * Eje de referencia de todas las pruebas: 28 dias desde el 6/9/2026 + 12 meses.
 * El corte de pendientes es MANANA, el 7/9. Ver cortePendientes().
 */
$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));
$corte = '2026-09-07';

/**
 * Alicuotas de una procesadora: IIBB 2,5% y SICREB 0,6% desde el 1/9, mas una
 * suba de IIBB al 3% que recien rige desde el 20/9.
 *
 * Es el escenario que distingue "la vigente" de "la ultima cargada".
 */
$alicuotas = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB',   'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-09-01',
     'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-09-01',
     'ACTIVO' => 1],
    ['ID' => 3, 'CONCEPTO' => 'IIBB',   'ALICUOTA' => 0.030, 'VIGENCIA_DESDE' => '2026-09-20',
     'ACTIVO' => 1]
];

/* ================================================================
   La tasa de retencion
   ================================================================ */
seccion('la tasa suma las alicuotas vigentes a la fecha del movimiento');

$tasa = CobElectronicos::tasaRetencion($alicuotas, '2026-09-10');

// Es exactamente el 1-0.025-0.006 = 0.969 que estaba escondido en la celda D3
// del Excel, pero ahora sale de dos filas parametrizadas y no de una constante.
chequear('IIBB 2,5% + SICREB 0,6% dan 3,1%', 0.031, $tasa['tasa']);
chequear('y son dos conceptos', 2, $tasa['conceptos']);

// La suba del 20/9 NO se toma para un movimiento del 10/9: es un porcentaje que
// todavia no regia, y usarlo calcularia el neto con una tasa inexistente.
chequear('no toma una vigencia posterior a la fecha del movimiento',
    0.025, $tasa['detalle']['IIBB']['alicuota']);

$tasaDespues = CobElectronicos::tasaRetencion($alicuotas, '2026-09-25');

chequear('a partir de la vigencia nueva la tasa cambia', 0.036, $tasaDespues['tasa']);
chequear('y sigue habiendo dos conceptos', 2, $tasaDespues['conceptos']);

chequear('el mismo dia de la vigencia ya rige la nueva',
    0.036, CobElectronicos::tasaRetencion($alicuotas, '2026-09-20')['tasa']);
chequear('el dia anterior todavia rige la vieja',
    0.031, CobElectronicos::tasaRetencion($alicuotas, '2026-09-19')['tasa']);

// Cero conceptos NO es tasa cero: es "esta procesadora no tiene alicuotas
// vigentes", que es lo que hace que el alta se rechace en lugar de guardar un
// neto igual al bruto.
$antes = CobElectronicos::tasaRetencion($alicuotas, '2026-08-31');

chequear('antes de la primera vigencia no hay ningun concepto', 0, $antes['conceptos']);
chequear('y la tasa es cero, pero no es lo mismo que no retener', 0.0, $antes['tasa']);

$vacia = CobElectronicos::tasaRetencion([], '2026-09-10');

chequear('sin alicuotas cargadas tampoco hay conceptos', 0, $vacia['conceptos']);

// Una alicuota dada de baja no rige.
$conBaja = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB',   'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-09-01',
     'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-09-01',
     'ACTIVO' => 0]
];

chequear('una alicuota inhabilitada no suma',
    0.025, CobElectronicos::tasaRetencion($conBaja, '2026-09-10')['tasa']);
chequear('y no se cuenta como concepto vigente',
    1, CobElectronicos::tasaRetencion($conBaja, '2026-09-10')['conceptos']);

// Dos vigencias del mismo dia son posibles: es lo que pasa cuando alguien
// corrige dos veces el mismo porcentaje. Gana la insertada despues, por ID.
$mismoDia = [
    ['ID' => 7, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.040, 'VIGENCIA_DESDE' => '2026-09-05',
     'ACTIVO' => 1],
    ['ID' => 8, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-09-05',
     'ACTIVO' => 1]
];

chequear('con dos vigencias del mismo dia gana la insertada despues',
    0.025, CobElectronicos::tasaRetencion($mismoDia, '2026-09-10')['tasa']);
chequear('y no depende del orden de la lista',
    0.025, CobElectronicos::tasaRetencion(array_reverse($mismoDia), '2026-09-10')['tasa']);

/* ================================================================
   El neto
   ================================================================ */
seccion('el importe neto');

chequear('bruto por (1 - tasa)', 969000.0, CobElectronicos::importeNeto(1000000, 0.031));
chequear('con la tasa nueva el neto es menor', 964000.0,
    CobElectronicos::importeNeto(1000000, 0.036));
chequear('sin retencion el neto es el bruto', 1000000.0,
    CobElectronicos::importeNeto(1000000, 0));

// El *0.969 escrito a mano del Excel daba esto mismo, pero para UN valor de
// tasa: con la formula, cambiar el parametro cambia el resultado.
chequear('el 0,969 del Excel es el complemento de la tasa vigente',
    round(1648264.10 * 0.969, 4), CobElectronicos::importeNeto(1648264.10, 0.031));

/* ================================================================
   Un movimiento no puede quedar sin neto
   ================================================================ */
seccion('una procesadora sin alicuotas vigentes rechaza el alta');

// Es exactamente la causa de que D6, D7 y D24 esten vacias en el Excel: se
// cargo el bruto y nunca se calculo el neto.
chequearLanza('sin alicuotas vigentes no se puede calcular el neto', function () {
    CobElectronicos::resolverTasa([], '2026-09-10', 'Payway');
});

chequearLanza('tampoco con una vigencia que arranca despues', function () use ($alicuotas) {
    CobElectronicos::resolverTasa($alicuotas, '2026-08-15', 'Payway');
});

try {
    CobElectronicos::resolverTasa([], '2026-09-10', 'Payway');
    $mensaje = '';
} catch (Throwable $e) {
    $mensaje = $e->getMessage();
}

chequear('el mensaje nombra la procesadora', true, strpos($mensaje, 'Payway') !== false);
chequear('y dice que no se puede calcular el neto',
    true, strpos($mensaje, 'importe neto') !== false);
chequear('y dice donde arreglarlo',
    true, strpos($mensaje, 'Parámetros') !== false);

// Con alicuotas vigentes no lanza y devuelve la tasa.
chequear('con alicuotas vigentes resuelve la tasa',
    0.031, CobElectronicos::resolverTasa($alicuotas, '2026-09-10', 'Payway')['tasa']);

// Una suma imposible tampoco se puede usar, aunque haya quedado guardada.
chequearLanza('una suma de alicuotas >= 1 no se puede usar para calcular', function () {
    CobElectronicos::resolverTasa([
        ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.6, 'VIGENCIA_DESDE' => '2026-09-01',
         'ACTIVO' => 1],
        ['ID' => 2, 'CONCEPTO' => 'OTRO', 'ALICUOTA' => 0.5, 'VIGENCIA_DESDE' => '2026-09-01',
         'ACTIVO' => 1]
    ], '2026-09-10', 'Payway');
});

/* ================================================================
   La validacion de la alicuota, al guardarla
   ================================================================ */
seccion('la suma de alicuotas se valida al guardar, no al usar');

chequear('IIBB al 3% sobre un SICREB del 0,6% suma 3,6%',
    0.036, CobElectronicos::validarAlicuota($alicuotas, 'IIBB', 0.030, '2026-09-20'));

// El caso que este modulo tiene que frenar: con suma 1 o mas, el neto sale cero
// o negativo, o sea que una COBRANZA restaria plata del tablero.
chequearLanza('una alicuota que llevaria la suma a 100% se rechaza',
    function () use ($alicuotas) {
        CobElectronicos::validarAlicuota($alicuotas, 'IIBB', 0.994, '2026-09-20');
    });

chequearLanza('y una que la pasa tambien', function () use ($alicuotas) {
    CobElectronicos::validarAlicuota($alicuotas, 'IIBB', 1.5, '2026-09-20');
});

try {
    CobElectronicos::validarAlicuota($alicuotas, 'IIBB', 0.994, '2026-09-20');
    $mensaje = '';
} catch (Throwable $e) {
    $mensaje = $e->getMessage();
}

chequear('el mensaje enuncia la consecuencia de negocio',
    true, strpos($mensaje, 'restaría plata') !== false);

chequearLanza('una alicuota negativa se rechaza', function () use ($alicuotas) {
    CobElectronicos::validarAlicuota($alicuotas, 'IIBB', -0.01, '2026-09-20');
});

chequearLanza('una alicuota sin concepto se rechaza', function () use ($alicuotas) {
    CobElectronicos::validarAlicuota($alicuotas, '   ', 0.02, '2026-09-20');
});

chequearLanza('una alicuota sin vigencia se rechaza', function () use ($alicuotas) {
    CobElectronicos::validarAlicuota($alicuotas, 'IIBB', 0.02, null);
});

// El concepto NO es un enum cerrado: una retencion nueva se carga sin tocar
// codigo. Lo unico que se valida es la suma.
chequear('un concepto que no existia se puede cargar',
    0.041, CobElectronicos::validarAlicuota($alicuotas, 'PERCEPCION_IVA', 0.010, '2026-09-10'));

seccion('la razon social se normaliza');

chequear('se recortan los espacios', 'Payway',
    CobElectronicos::normalizarRazonSocial('  Payway '));
chequear('y los espacios internos repetidos', 'Mercado Pago',
    CobElectronicos::normalizarRazonSocial('Mercado   Pago'));

seccion('activar una procesadora exige alicuota vigente solo al pasar a activa');

// El invariante: una procesadora sin alicuota vigente no puede ENTRAR al
// estado activo, porque sus movimientos no podrian calcular neto.
chequearLanza('inactiva -> activa sin alicuotas se rechaza', function () {
    CobElectronicos::validarActivacion(0, true, 0, 'Fiserv');
}, '"Fiserv" no se puede activar porque no tiene ninguna alícuota vigente: sus movimientos '
    . 'no podrían calcular el importe neto. Cargale una alícuota primero, en la sección de abajo.');

// Todo lo demas pasa. Sobre todo: una que YA esta activa y a la que solo se le
// edita el nombre no vuelve a pasar por la verificacion, porque la pantalla
// guarda en lote y una fila que no se toca no puede frenar a las demas.
$noLanza = function ($actual, $nuevo, $conceptos) {
    try {
        CobElectronicos::validarActivacion($actual, $nuevo, $conceptos, 'X');

        return true;
    } catch (Throwable $e) {
        return false;
    }
};

chequear('inactiva -> activa con alicuotas pasa', true, $noLanza(0, true, 2));
chequear('activa que sigue activa pasa aunque no tenga alicuotas', true, $noLanza(1, true, 0));
chequear('activa -> inactiva pasa', true, $noLanza(1, false, 0));
chequear('inactiva que sigue inactiva pasa', true, $noLanza(0, false, 0));
chequear('acepta el ACTIVO como string de la base', true, $noLanza('1', true, 0));
chequear('y como booleano', false, $noLanza(false, true, 0));

/* ================================================================
   Editar un porcentaje no reescribe lo ya guardado
   ================================================================ */
seccion('un movimiento guardado conserva su tasa y su neto');

/**
 * Dos movimientos cargados con la tasa vieja (3,1%): uno YA ACREDITADO -del 2/9,
 * antes del eje- y uno PENDIENTE, del 25/9, que cae en la vigencia nueva.
 */
$movimientos = [
    ['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 1000000.0, 'fecha_acreditacion' => '2026-09-02',
     'tasa_aplicada' => 0.031, 'importe_neto' => 969000.0, 'origen_dato' => 'MANUAL'],
    ['id' => 2, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 2000000.0, 'fecha_acreditacion' => '2026-09-25',
     'tasa_aplicada' => 0.031, 'importe_neto' => 1938000.0, 'origen_dato' => 'MANUAL']
];

$plan = CobElectronicos::planRecalculo($movimientos, [1 => $alicuotas], $corte);

chequear('solo se recalcula un movimiento', 1, count($plan['cambios']));
chequear('y es el pendiente', 2, $plan['cambios'][0]['id']);
chequear('el ya acreditado no se toca', 1, $plan['acreditados']);

// La regla en una linea: el movimiento del 2/9 sigue con la tasa con la que se
// calculo, aunque la alicuota de hoy sea otra. Su plata ya entro asi.
$sigueIgual = true;

foreach ($plan['cambios'] as $c) {
    if ($c['id'] === 1) {
        $sigueIgual = false;
    }
}

chequear('el movimiento ya acreditado conserva su TASA_APLICADA', true, $sigueIgual);

chequear('el pendiente pasa de 3,1% a 3,6%', 0.036, $plan['cambios'][0]['tasa_nueva']);
chequear('y su neto baja', 1928000.0, $plan['cambios'][0]['neto_nuevo']);
chequear('la diferencia se informa', -10000.0, $plan['cambios'][0]['diferencia']);
chequear('y tambien el total de la diferencia', -10000.0, $plan['diferencia']);

// Reenviar el mismo escenario no cuenta como cambio: la tolerancia existe
// porque la tasa es DECIMAL(9,6) y da la vuelta por JSON.
$yaRecalculados = [
    ['id' => 2, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 2000000.0, 'fecha_acreditacion' => '2026-09-25',
     'tasa_aplicada' => 0.036, 'importe_neto' => 1928000.0]
];

chequear('un movimiento que ya tiene la tasa vigente no se vuelve a escribir',
    0, count(CobElectronicos::planRecalculo($yaRecalculados, [1 => $alicuotas],
        $corte)['cambios']));

// Un movimiento con fecha de HOY tampoco se recalcula: el corte es el primer
// dia habil despues de hoy, y lo de hoy ya se acredito con la tasa que tenia.
$deHoy = [
    ['id' => 3, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 1000000.0, 'fecha_acreditacion' => '2026-09-06',
     'tasa_aplicada' => 0.010, 'importe_neto' => 990000.0]
];

$planHoy = CobElectronicos::planRecalculo($deHoy, [1 => $alicuotas], $corte);

chequear('un movimiento con fecha de hoy no se recalcula', 0, count($planHoy['cambios']));
chequear('cuenta como ya acreditado', 1, $planHoy['acreditados']);
chequear('y no como pendiente', 0, $planHoy['pendientes']);

// Una procesadora que perdio sus alicuotas no puede recalcular: el movimiento
// queda con su tasa vieja y se AVISA, en lugar de quedar en cero (informaria de
// menos) o en el bruto (informaria de mas).
$sinAlicuota = CobElectronicos::planRecalculo($movimientos, [], $corte);

chequear('sin alicuotas no se recalcula nada', 0, count($sinAlicuota['cambios']));
chequear('y se avisa', 1, count($sinAlicuota['avisos']));
chequear('el aviso dice que conservan su tasa',
    true, strpos($sinAlicuota['avisos'][0], 'Conservan la tasa') !== false);

/* ================================================================
   Donde cae un movimiento respecto del eje
   ================================================================ */
seccion('lo pendiente arranca manana');

// HOY NO ES PENDIENTE: lo que se acredita hoy ya esta -o va a estar al cierre-
// en el saldo bancario de la primera columna. Y el corte es MANANA A SECAS, sin
// regla de dia habil: puede haber acreditaciones cualquier dia.
chequear('un jueves, lo pendiente arranca el viernes',
    '2026-09-11', CobElectronicos::cortePendientes('2026-09-10'));
chequear('un viernes, arranca el SABADO: no se corre al lunes',
    '2026-09-12', CobElectronicos::cortePendientes('2026-09-11'));
chequear('un domingo, el lunes',
    '2026-09-07', CobElectronicos::cortePendientes('2026-09-06'));
chequear('cruza el mes', '2026-10-01', CobElectronicos::cortePendientes('2026-09-30'));
chequear('y el anio', '2027-01-01', CobElectronicos::cortePendientes('2026-12-31'));
chequear('y es el corte de referencia de estas pruebas',
    $corte, CobElectronicos::cortePendientes($h->hoy()));

seccion('un movimiento con fecha anterior al corte NO abre el horizonte');

chequear('una fecha del eje esta dentro',
    'DENTRO', CobElectronicos::ubicacionEnEje('2026-09-10', $h));
chequear('manana, el primer dia pendiente, tambien',
    'DENTRO', CobElectronicos::ubicacionEnEje('2026-09-07', $h));
chequear('el dia de hoy ya NO: esta en el eje pero cuenta como acreditado',
    'ANTERIOR', CobElectronicos::ubicacionEnEje('2026-09-06', $h));
chequear('una fecha anterior queda afuera',
    'ANTERIOR', CobElectronicos::ubicacionEnEje('2026-09-02', $h));

// Un viernes, el sabado siguiente ES pendiente: una billetera acredita
// cualquier dia, y correr el corte al lunes lo escondería.
$viernes = new Horizonte(28, 12, [], new DateTime('2026-09-11'));

chequear('visto un viernes, el sabado es pendiente',
    'DENTRO', CobElectronicos::ubicacionEnEje('2026-09-12', $viernes));
chequear('y el viernes mismo no',
    'ANTERIOR', CobElectronicos::ubicacionEnEje('2026-09-11', $viernes));

// El caso que Horizonte::acumular() resolveria mal para este modulo: el 1/9 cae
// en la columna del mes 2026-09, que existe en el eje pero que el tablero ni
// siquiera incluye en el arrastre. El corte por fecha es explicito.
chequear('una fecha del mes en curso anterior a hoy tambien queda afuera',
    'ANTERIOR', CobElectronicos::ubicacionEnEje('2026-09-01', $h));

chequear('una fecha posterior al eje queda afuera',
    'POSTERIOR', CobElectronicos::ubicacionEnEje('2028-01-15', $h));

seccion('la serie del tablero');

/**
 * Cuatro movimientos: uno dentro del tramo diario, uno en una columna mensual,
 * uno ya acreditado (anterior al eje) y uno mas alla del horizonte.
 */
$paraSerie = [
    ['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 1000000.0, 'fecha_acreditacion' => '2026-09-12',
     'tasa_aplicada' => 0.031, 'importe_neto' => 969000.0],
    ['id' => 2, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 2000000.0, 'fecha_acreditacion' => '2026-11-20',
     'tasa_aplicada' => 0.031, 'importe_neto' => 1938000.0],
    ['id' => 3, 'id_procesadora' => 2, 'procesadora' => 'Mercado Pago',
     'importe_bruto' => 500000.0, 'fecha_acreditacion' => '2026-09-02',
     'tasa_aplicada' => 0.031, 'importe_neto' => 484500.0],
    ['id' => 4, 'id_procesadora' => 2, 'procesadora' => 'Mercado Pago',
     'importe_bruto' => 300000.0, 'fecha_acreditacion' => '2028-03-05',
     'tasa_aplicada' => 0.031, 'importe_neto' => 290700.0]
];

$armado = CobElectronicos::armarMovimientos($paraSerie, $h);
$serie = CobElectronicos::armarSerie($armado['filas'], $h);

// LA FECHA DE IMPUTACION ES LA FECHA DE ACREDITACION, SIN CORRIMIENTOS. El 12/9
// de 2026 es sabado: el importe se queda ahi y no se corre al lunes.
chequear('el neto se imputa en la fecha de acreditacion, un sabado incluido',
    969000.0, $serie['dias']['2026-09-12']);
chequear('y el lunes siguiente queda en cero', 0.0, $serie['dias']['2026-09-14']);
chequear('el que cae fuera del tramo diario va a la columna de su mes',
    1938000.0, $serie['meses']['2026-11']);

// LA REGLA INVERSA A SALDOS: un movimiento ya acreditado NO se reubica en la
// primera columna. Esa plata ya esta en la cuenta y la informa el saldo
// bancario, asi que reubicarla la contaria dos veces.
chequear('un movimiento ya acreditado no se reubica en la primera columna',
    0.0, $serie['dias']['2026-09-06']);

// Y UNO CON FECHA DE HOY TAMPOCO ENTRA: la primera columna del tablero ya lo
// tiene por el saldo bancario. Se cuenta como ya acreditado, no como pendiente.
$deHoyArmado = CobElectronicos::armarMovimientos([
    ['id' => 9, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 100000.0, 'fecha_acreditacion' => '2026-09-06',
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0]
], $h);
$serieHoy = CobElectronicos::armarSerie($deHoyArmado['filas'], $h);

chequear('un movimiento con fecha de hoy no suma en la columna de hoy',
    0.0, $serieHoy['dias']['2026-09-06']);
chequear('ni en ninguna otra', 0.0, array_sum($serieHoy['dias']) + array_sum($serieHoy['meses']));
chequear('se cuenta como ya acreditado', 96900.0, $serieHoy['ya_acreditado']);
chequear('sin aviso', 0, count($serieHoy['warnings']));
chequear('y su fila queda marcada como acreditada',
    'ANTERIOR', $deHoyArmado['filas'][0]['ubicacion_eje']);

// El mismo movimiento, corrido al primer dia pendiente, si entra.
$deMananaArmado = CobElectronicos::armarMovimientos([
    ['id' => 9, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 100000.0, 'fecha_acreditacion' => '2026-09-07',
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0]
], $h);

chequear('el mismo movimiento fechado el primer dia pendiente si entra',
    96900.0, CobElectronicos::armarSerie($deMananaArmado['filas'], $h)['dias']['2026-09-07']);

// Y TAMPOCO SE AVISA NI SUMA A fuera_horizonte. No es plata que el tablero
// informe de menos: es plata que el tablero informa por otra fila, la del saldo
// bancario. Avisarla todos los dias seria ruido sobre algo que ya paso.
chequear('lo ya acreditado no suma a fuera_horizonte', 290700.0, $serie['fuera_horizonte']);
chequear('se devuelve aparte, como dato informativo', 484500.0, $serie['ya_acreditado']);
chequear('y no deja ningun aviso por eso: solo queda el del posterior',
    1, count($serie['warnings']));
chequear('el aviso del posterior dice que no tiene columna',
    true, strpos($serie['warnings'][0], 'posterior') !== false);
chequear('con el importe', true, strpos($serie['warnings'][0], '290.700,00') !== false);
chequear('y ninguno menciona el doble conteo de lo ya acreditado',
    false, strpos(implode(' ', $serie['warnings']), 'dos veces') !== false);

// Con SOLO movimientos ya acreditados la serie queda en cero y muda: no hay
// nada que hacer con eso.
$soloAcreditados = CobElectronicos::armarSerie(
    CobElectronicos::armarMovimientos([$paraSerie[2]], $h)['filas'], $h);

chequear('con solo movimientos ya acreditados la serie va en cero',
    0.0, array_sum($soloAcreditados['dias']));
chequear('sin nada en fuera_horizonte', 0.0, $soloAcreditados['fuera_horizonte']);
chequear('y sin ningun aviso', 0, count($soloAcreditados['warnings']));

chequear('la moneda de origen es pesos', 'ARS', $serie['moneda_origen']);
chequear('y no hay tipo de cambio que informar', null, $serie['tipo_cambio']);

seccion('el cuadro son netos, nunca brutos');

$porDia = CobElectronicos::agruparPorDia($armado['filas']);
$porMes = CobElectronicos::agruparPorMes($armado['filas']);

$sumaDia = 0;

foreach ($porDia as $d) {
    $sumaDia += $d['neto'];
}

$sumaMes = 0;

foreach ($porMes as $m) {
    $sumaMes += $m['neto'];
}

$sumaNetos = 0;
$sumaBrutos = 0;

foreach ($paraSerie as $m) {
    $sumaNetos += $m['importe_neto'];
    $sumaBrutos += $m['importe_bruto'];
}

chequear('la suma diaria y la mensual coinciden entre si', $sumaDia, $sumaMes);
chequear('y coinciden con la suma de netos', $sumaNetos, $sumaDia);
chequear('el total NO es el bruto', true, abs($sumaBrutos - $sumaDia) > 1);
chequear('el total del cuadro es el neto', 3682200.0, $sumaDia);

// El cuadro marca lo que no entra al tablero en lugar de esconderlo: un total
// mas chico en el tablero tiene que tener explicacion en la misma pantalla.
$fuera = array_values(array_filter($porDia, function ($d) {
    return !$d['entra_al_tablero'];
}));

chequear('el cuadro marca los dias que no entran al tablero', 2, count($fuera));
chequear('el primero es el ya acreditado', '2026-09-02', $fuera[0]['fecha']);

// Dos liquidaciones el mismo dia se juntan en una fila del cuadro.
$dosElMismoDia = [
    ['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway', 'importe_bruto' => 100.0,
     'fecha_acreditacion' => '2026-09-10', 'tasa_aplicada' => 0.031, 'importe_neto' => 96.9],
    ['id' => 2, 'id_procesadora' => 1, 'procesadora' => 'Payway', 'importe_bruto' => 200.0,
     'fecha_acreditacion' => '2026-09-10', 'tasa_aplicada' => 0.031, 'importe_neto' => 193.8]
];

$armadoDoble = CobElectronicos::armarMovimientos($dosElMismoDia, $h);
$diaDoble = CobElectronicos::agruparPorDia($armadoDoble['filas']);

chequear('dos movimientos del mismo dia dan una sola fila en el cuadro', 1, count($diaDoble));
chequear('con los netos sumados', 290.7, $diaDoble[0]['neto']);
chequear('y diciendo cuantos movimientos son', 2, $diaDoble[0]['movimientos']);

/* ================================================================
   La tabla de la pantalla
   ================================================================ */
seccion('los totales de la pantalla');

chequear('el total bruto', 3800000.0, $armado['totales']['bruto']);
chequear('el total neto', 3682200.0, $armado['totales']['neto']);
chequear('lo retenido es la diferencia', 117800.0, $armado['totales']['retenido']);
chequear('y se cuentan los movimientos', 4, $armado['totales']['movimientos']);

// 'fuera_eje' es SOLO lo posterior: es lo unico que el tablero deja de mostrar
// teniendo que mostrarlo. Lo ya acreditado se cuenta aparte.
chequear('se informa cuanto queda posterior al eje', 290700.0, $armado['totales']['fuera_eje']);
chequear('y cuantos movimientos son', 1, $armado['totales']['fuera_eje_movimientos']);
chequear('lo ya acreditado se cuenta aparte', 484500.0, $armado['totales']['ya_acreditado']);
chequear('con su propio conteo', 1, $armado['totales']['ya_acreditado_movimientos']);
chequear('y no genera aviso', false,
    strpos(implode(' ', $armado['avisos']), 'ya está informada') !== false);

chequear('hay un total por procesadora', 2, count($armado['por_procesadora']));
chequear('el de Mercado Pago suma sus dos movimientos',
    775200.0, $armado['por_procesadora'][0]['neto']);
chequear('en bruto tambien', 800000.0, $armado['por_procesadora'][0]['bruto']);

// Cada fila queda MARCADA con su ubicacion: la pantalla la muestra igual, en su
// lugar, y dice por que no entra al tablero.
$porId = [];

foreach ($armado['filas'] as $f) {
    $porId[$f['id']] = $f;
}

chequear('la fila del ya acreditado esta marcada', 'ANTERIOR', $porId[3]['ubicacion_eje']);
chequear('y dice que no entra al tablero', false, $porId[3]['entra_al_tablero']);
chequear('la del posterior tambien', 'POSTERIOR', $porId[4]['ubicacion_eje']);
chequear('la que si entra queda sin marca', true, $porId[1]['entra_al_tablero']);

// Dos movimientos de la misma procesadora el mismo dia se AVISAN, no se
// bloquean: puede haber dos liquidaciones el mismo dia, y el aviso alcanza para
// detectar el pegado doble.
chequear('dos movimientos del mismo dia dejan un aviso',
    1, count($armadoDoble['avisos']));
chequear('el aviso no los trata como error',
    true, strpos($armadoDoble['avisos'][0], 'Puede ser correcto') !== false);
chequear('pero nombra el caso que hay que revisar',
    true, strpos($armadoDoble['avisos'][0], 'dos veces') !== false);
chequear('y los dos movimientos siguen estando', 2, count($armadoDoble['filas']));

/* ================================================================
   El proveedor
   ================================================================ */
seccion('el proveedor esta registrado');

chequear('COB_ELECTRONICOS esta registrado', true,
    CashflowRegistry::existe('COB_ELECTRONICOS'));
chequear('y ahora esta disponible', true, CashflowRegistry::disponible('COB_ELECTRONICOS'));
chequear('la serie COBRANZA existe', true,
    CashflowRegistry::serieExiste('COB_ELECTRONICOS', 'COBRANZA'));
chequear('apunta a la pestana cob_electronicos', 'cob_electronicos',
    CashflowRegistry::meta('COB_ELECTRONICOS')['tab']);
chequear('y maneja pesos', 'ARS', CashflowRegistry::meta('COB_ELECTRONICOS')['moneda']);
chequear('se instancia', true,
    CashflowRegistry::instanciar('COB_ELECTRONICOS') instanceof CashflowProvider);

seccion('el proveedor rinde cero con un aviso que dice que falta');

/**
 * Doble del modulo. La costura es CobElectronicosProvider::modulo(): los casos
 * en los que el proveedor tiene que rendir CERO CON UN AVISO no se pueden montar
 * contra una base real sin borrar las tablas.
 */
class CobElectronicosFalso {

    public $tablas = true;
    public $procesadoras = [];
    public $movimientos = [];
    public $alicuotas = [];

    public function tablasCreadas() { return $this->tablas; }
    public function getProcesadoras($soloActivas = false) { return $this->procesadoras; }
    public function getMovimientos($filtros = []) { return $this->movimientos; }
    public function getAlicuotasPorProcesadora() { return $this->alicuotas; }
}

class CobElectronicosProviderDoble extends CobElectronicosProvider {

    public $falso;

    protected function modulo() { return $this->falso; }
}

/** Arma un proveedor con el doble ya configurado */
function proveedorConDoble($configurar) {
    $prov = new CobElectronicosProviderDoble('COB_ELECTRONICOS');
    $prov->falso = new CobElectronicosFalso();

    $configurar($prov->falso);

    return $prov;
}

// 1. El script SQL no se corrio.
$sinTablas = proveedorConDoble(function ($f) {
    $f->tablas = false;
});

$s = $sinTablas->series($h)['COBRANZA'];

chequear('sin tablas la serie completa las claves del eje', 28, count($s['dias']));
chequear('y va en cero', 0.0, array_sum($s['dias']));
chequear('con un solo aviso', 1, count($sinTablas->warnings()));
chequear('que dice que script hay que correr', true,
    strpos($sinTablas->warnings()[0], 'cashflow_cob_electronicos.sql') !== false);
chequear('y no es el mensaje generico de la clase base', false,
    strpos($sinTablas->warnings()[0], 'no se pudo calcular') !== false);

// 2. Ninguna procesadora cargada.
$sinProcesadoras = proveedorConDoble(function ($f) {
    $f->procesadoras = [];
});

chequear('sin procesadoras la fila va en cero',
    0.0, array_sum($sinProcesadoras->series($h)['COBRANZA']['dias']));
chequear('y el aviso dice donde cargarlas', true,
    strpos($sinProcesadoras->warnings()[0], 'Parametros') !== false);

// 3. Procesadoras cargadas pero ningun movimiento: NO es lo mismo que cero.
$sinMovimientos = proveedorConDoble(function ($f) {
    $f->procesadoras = [['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1]];
});

chequear('con procesadoras y sin movimientos tambien va en cero',
    0.0, array_sum($sinMovimientos->series($h)['COBRANZA']['dias']));
chequear('y se distingue de "los datos son cero"', true,
    strpos($sinMovimientos->warnings()[0], 'ningun movimiento') !== false);

// 4. Movimientos de una procesadora que perdio sus alicuotas vigentes: suman
//    igual, con el neto que ya tenian, pero se avisa.
$sinAlicuotasVigentes = proveedorConDoble(function ($f) use ($paraSerie) {
    $f->procesadoras = [['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1]];
    $f->movimientos = $paraSerie;
    $f->alicuotas = [];
});

$s = $sinAlicuotasVigentes->series($h)['COBRANZA'];
$avisos = implode(' | ', $sinAlicuotasVigentes->warnings());

chequear('los movimientos siguen sumando su neto guardado',
    969000.0, $s['dias']['2026-09-12']);
chequear('se avisa que la procesadora perdio sus alicuotas', true,
    strpos($avisos, 'no tiene alicuotas vigentes') !== false);
chequear('y tambien lo posterior al horizonte', true,
    strpos($avisos, 'posterior al final del horizonte') !== false);
chequear('pero nada de lo ya acreditado', false,
    strpos($avisos, 'anterior') !== false);

// 5. Todo lo cargado ya se acredito. La fila va en cero y el aviso explica el
//    cero -que es lo unico que hay que explicar-, sin reclamar nada del pasado.
$todoAcreditado = proveedorConDoble(function ($f) {
    $f->procesadoras = [['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1]];
    $f->alicuotas = [1 => [
        ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.031,
         'VIGENCIA_DESDE' => '2026-01-01', 'ACTIVO' => 1]
    ]];
    $f->movimientos = [
        ['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway',
         'importe_bruto' => 1000.0, 'fecha_acreditacion' => '2026-09-01',
         'tasa_aplicada' => 0.031, 'importe_neto' => 969.0]
    ];
});

$s = $todoAcreditado->series($h)['COBRANZA'];

chequear('con todo ya acreditado la fila va en cero', 0.0, array_sum($s['dias']));
chequear('y no queda nada en fuera_horizonte', 0.0, $s['fuera_horizonte']);
chequear('el aviso explica el cero remitiendo al saldo bancario', true,
    strpos(implode(' ', $todoAcreditado->warnings()), 'saldo bancario') !== false);
chequear('y dice que no hay acreditaciones pendientes', true,
    strpos(implode(' ', $todoAcreditado->warnings()), 'pendientes') !== false);

// 5b. Lo unico cargado tiene fecha de HOY: para el tablero es lo mismo que ya
//     acreditado, porque la primera columna ya lo tiene por el saldo bancario.
$soloHoy = proveedorConDoble(function ($f) {
    $f->procesadoras = [['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1]];
    $f->alicuotas = [1 => [
        ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.031,
         'VIGENCIA_DESDE' => '2026-01-01', 'ACTIVO' => 1]
    ]];
    $f->movimientos = [
        ['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway',
         'importe_bruto' => 1000.0, 'fecha_acreditacion' => '2026-09-06',
         'tasa_aplicada' => 0.031, 'importe_neto' => 969.0]
    ];
});

$s = $soloHoy->series($h)['COBRANZA'];

chequear('con solo un movimiento de hoy la fila va en cero', 0.0, array_sum($s['dias']));
chequear('la columna de hoy no lo suma', 0.0, $s['dias']['2026-09-06']);
chequear('y el aviso explica el cero por el saldo bancario', true,
    strpos(implode(' ', $soloHoy->warnings()), 'saldo bancario') !== false);

// 6. El camino normal: la serie del proveedor es la suma de netos.
$normal = proveedorConDoble(function ($f) use ($paraSerie, $alicuotas) {
    $f->procesadoras = [['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1],
                        ['ID' => 2, 'RAZON_SOCIAL' => 'Mercado Pago', 'ACTIVO' => 1]];
    $f->alicuotas = [1 => $alicuotas, 2 => $alicuotas];
    $f->movimientos = $paraSerie;
});

$s = $normal->series($h)['COBRANZA'];

chequear('la serie devuelve las claves diarias del eje', 28, count($s['dias']));
chequear('y las mensuales', 12, count($s['meses']));
chequear('el total del eje es la suma de netos que entran',
    2907000.0, array_sum($s['dias']) + array_sum($s['meses']));
chequear('y lo posterior al eje queda informado', 290700.0, $s['fuera_horizonte']);
chequear('el eje mas lo posterior son todos los netos pendientes',
    3197700.0, array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte']);

// El proveedor devuelve la serie que declara el registro, y una sola.
chequear('devuelve exactamente la serie del registro',
    ['COBRANZA'], array_keys($normal->series($h)));

/* ================================================================
   IMPORTADOR: leer la planilla
   ================================================================ */
seccion('los importes de una planilla, en cualquier configuracion regional');

// Lo que exporta Excel en espanol y lo que exporta en ingles. El usuario no
// tiene que saber en cual esta.
chequear('coma decimal', 1069326.0, CobElectronicos::numeroDesdePlanilla('1069326,00'));
chequear('punto decimal', 1069326.0, CobElectronicos::numeroDesdePlanilla('1069326.00'));
chequear('miles con punto y decimal con coma',
    3757900.50, CobElectronicos::numeroDesdePlanilla('3.757.900,50'));
chequear('miles con coma y decimal con punto',
    3757900.50, CobElectronicos::numeroDesdePlanilla('3,757,900.50'));
chequear('solo miles con punto', 1648264.0, CobElectronicos::numeroDesdePlanilla('1.648.264'));
chequear('con simbolo de moneda y espacios',
    1234.56, CobElectronicos::numeroDesdePlanilla(' $ 1.234,56 '));
chequear('un entero pelado', 99000.0, CobElectronicos::numeroDesdePlanilla('99000'));
chequear('un negativo se lee negativo', -500.0, CobElectronicos::numeroDesdePlanilla('-500'));

// null y no cero: la diferencia entre "no es un numero" y "es cero" es lo que
// hace que la fila sea un error en vez de un movimiento de cero pesos.
chequear('vacio no es cero', null, CobElectronicos::numeroDesdePlanilla(''));
chequear('un texto tampoco', null, CobElectronicos::numeroDesdePlanilla('no aplica'));

seccion('las fechas de una planilla');

chequear('dd/mm/aaaa', '2026-09-07', CobElectronicos::fechaDesdePlanilla('07/09/2026'));
chequear('aaaa-mm-dd', '2026-09-07', CobElectronicos::fechaDesdePlanilla('2026-09-07'));
chequear('dd-mm-aaaa', '2026-09-07', CobElectronicos::fechaDesdePlanilla('7-9-2026'));
chequear('dos digitos de anio', '2026-09-07', CobElectronicos::fechaDesdePlanilla('07/09/26'));
chequear('con hora al final', '2026-09-07',
    CobElectronicos::fechaDesdePlanilla('2026-09-07 00:00:00'));

// Una fecha que no existe NO se adivina: la fila queda como error, con su linea.
chequear('el 31 de febrero no existe', null, CobElectronicos::fechaDesdePlanilla('31/02/2026'));
chequear('una fecha sin anio no se completa', null,
    CobElectronicos::fechaDesdePlanilla('07/09'));
chequear('vacio es null', null, CobElectronicos::fechaDesdePlanilla(''));

// El serial de Excel es la red para una columna que quedo con formato numero.
chequear('el serial de Excel se convierte con base 1899-12-30',
    date('Y-m-d', strtotime('1899-12-30 +46000 day')),
    CobElectronicos::fechaDesdePlanilla('46000'));

seccion('el parseo de la planilla');

// La plantilla que se descarga tiene que poder volver a entrar: si no, el
// formato que se propone no es el que el parser acepta.
$plantilla = CobElectronicos::plantillaCsv('2026-09-09');
$leido = CobElectronicos::parsearPlanilla($plantilla);

chequear('la plantilla vuelve a entrar por el parser', 2, count($leido['filas']));
chequear('con el separador que usa Excel en espanol', ';', $leido['separador']);
chequear('y la primera fila de ejemplo es cargable',
    'Payway', $leido['filas'][0]['procesadora']);
chequear('la segunda trae numero de liquidacion',
    'LIQ-00123', $leido['filas'][1]['id_externo']);

// Un CSV con coma, que es lo que exporta Excel en ingles.
$conComas = "PROCESADORA,IMPORTE_BRUTO,FECHA_ACREDITACION\nPayway,1069326.00,2026-09-10\n";

chequear('el separador coma se detecta solo',
    ',', CobElectronicos::parsearPlanilla($conComas)['separador']);

// Los titulos de la hoja original, para poder pegar una columna del Excel viejo
// sin renombrar nada.
$comoElExcel = "RAZON_SOC;Importe;Cobro\nMercado Pago;35.257.406,00;09/09/2026\n";
$leidoExcel = CobElectronicos::parsearPlanilla($comoElExcel);

chequear('acepta los titulos del Excel original',
    'Mercado Pago', $leidoExcel['filas'][0]['procesadora']);
chequear('con su importe', '35.257.406,00', $leidoExcel['filas'][0]['importe_bruto']);

// Las lineas vacias que Excel deja debajo de los datos no son un error.
$conVacias = "PROCESADORA;IMPORTE_BRUTO;FECHA_ACREDITACION\nPayway;100;2026-09-10\n;;\n;;\n";

chequear('las filas vacias se saltean', 1,
    count(CobElectronicos::parsearPlanilla($conVacias)['filas']));

chequearLanza('sin una columna obligatoria no se puede leer el archivo', function () {
    CobElectronicos::parsearPlanilla("PROCESADORA;IMPORTE_BRUTO\nPayway;100\n");
});

chequearLanza('un archivo sin filas de datos avisa', function () {
    CobElectronicos::parsearPlanilla("PROCESADORA;IMPORTE_BRUTO;FECHA_ACREDITACION\n");
});

// Un .xlsx es un ZIP: se detecta por su firma para poder decir QUE HACER, en
// lugar de fallar con un archivo lleno de bytes binarios.
try {
    CobElectronicos::parsearPlanilla("PK\x03\x04algo binario");
    $mensajeXlsx = '';
} catch (Throwable $e) {
    $mensajeXlsx = $e->getMessage();
}

chequear('un .xlsx se detecta', true, strpos($mensajeXlsx, '.xlsx') !== false);
chequear('y el mensaje dice como convertirlo',
    true, strpos($mensajeXlsx, 'CSV') !== false);

/* ================================================================
   IMPORTADOR: que cambiaria
   ================================================================ */
seccion('el diff de una importacion');

$procesadorasImp = [
    ['ID' => 1, 'RAZON_SOCIAL' => 'Payway', 'ACTIVO' => 1],
    ['ID' => 2, 'RAZON_SOCIAL' => 'Mercado Pago', 'ACTIVO' => 1],
    ['ID' => 3, 'RAZON_SOCIAL' => 'Naranja X', 'ACTIVO' => 0]
];

$alicuotasImp = [1 => $alicuotas, 2 => $alicuotas, 3 => $alicuotas];

/** Lo que ya esta cargado: dos acreditaciones de Payway */
$cargados = [
    ['id' => 10, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 1000000.0, 'fecha_acreditacion' => '2026-09-10',
     'tasa_aplicada' => 0.031, 'importe_neto' => 969000.0, 'id_externo' => null,
     'origen_dato' => 'MANUAL'],
    ['id' => 11, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 500000.0, 'fecha_acreditacion' => '2026-09-11',
     'tasa_aplicada' => 0.031, 'importe_neto' => 484500.0, 'id_externo' => null,
     'origen_dato' => 'MANUAL']
];

/** Arma las filas crudas de un archivo, como las devuelve el parser */
function filaArchivo($linea, $procesadora, $bruto, $fecha, $idExterno = '', $obs = '') {
    return [
        'linea' => $linea,
        'procesadora' => $procesadora,
        'importe_bruto' => $bruto,
        'fecha_acreditacion' => $fecha,
        'id_externo' => $idExterno,
        'observaciones' => $obs
    ];
}

$archivo = [
    // Igual a lo cargado: no se toca
    filaArchivo(2, 'Payway', '1000000,00', '10/09/2026'),
    // El importe cambio
    filaArchivo(3, 'Payway', '600000,00', '11/09/2026'),
    // Nueva
    filaArchivo(4, 'Mercado Pago', '2000000,00', '12/09/2026'),
    // Ya acreditada: no se importa y no es error
    filaArchivo(5, 'Payway', '300000,00', '01/09/2026')
];

$diff = CobElectronicos::compararImportacion($archivo, $cargados, $procesadorasImp,
    $alicuotasImp, $corte);

chequear('una fila identica no se toca', 1, $diff['resumen']['sin_cambios']);
chequear('una fila con otro importe es un cambio', 1, $diff['resumen']['cambios']);
chequear('una fila que no estaba es un alta', 1, $diff['resumen']['altas']);
chequear('una fila ya acreditada no se importa', 1, $diff['resumen']['ya_acreditadas']);
chequear('y no cuenta como error', 0, $diff['resumen']['errores']);
chequear('se puede importar', true, $diff['puede_importar']);

$porLinea = [];

foreach ($diff['filas'] as $f) {
    $porLinea[$f['linea']] = $f;
}

chequear('el alta trae el neto ya calculado por el servidor',
    1938000.0, $porLinea[4]['importe_neto']);
chequear('con la tasa vigente a su fecha', 0.031, $porLinea[4]['tasa_aplicada']);
chequear('el cambio dice el neto anterior', 484500.0, $porLinea[3]['neto_anterior']);
chequear('y el nuevo', 581400.0, $porLinea[3]['importe_neto']);
chequear('con la diferencia', 96900.0, $porLinea[3]['diferencia']);
chequear('el motivo dice que cambio',
    true, strpos($porLinea[3]['motivo'], 'importe bruto pasa') !== false);
chequear('el cambio queda enganchado al movimiento cargado', 11, $porLinea[3]['id']);
chequear('la fila ya acreditada explica por que no se importa',
    true, strpos($porLinea[5]['motivo'], 'saldo bancario') !== false);
chequear('el neto que se agrega es el del alta', 1938000.0, $diff['resumen']['neto_altas']);
chequear('y la diferencia de los cambios va aparte', 96900.0,
    $diff['resumen']['neto_diferencia']);
chequear('el rango del archivo se informa', '2026-09-10', $diff['rango']['desde']);
chequear('de punta a punta', '2026-09-12', $diff['rango']['hasta']);

seccion('el neto nunca sale del archivo');

// Una columna de neto en el archivo se ignora: el titulo no esta mapeado y el
// neto lo calcula el servidor. Es la misma regla que la carga manual.
$conNeto = "PROCESADORA;IMPORTE_BRUTO;FECHA_ACREDITACION;IMPORTE_NETO\n"
    . "Payway;1000000,00;12/09/2026;999999999\n";

$diffNeto = CobElectronicos::compararImportacion(
    CobElectronicos::parsearPlanilla($conNeto)['filas'],
    [], $procesadorasImp, $alicuotasImp, $corte);

chequear('el neto del archivo se ignora', 969000.0, $diffNeto['filas'][0]['importe_neto']);

seccion('las filas que el importador rechaza');

$conErrores = [
    filaArchivo(2, 'Procesadora Inventada', '1000', '10/09/2026'),
    filaArchivo(3, 'Naranja X', '1000', '10/09/2026'),
    filaArchivo(4, 'Payway', '0', '10/09/2026'),
    filaArchivo(5, 'Payway', 'mil pesos', '10/09/2026'),
    filaArchivo(6, 'Payway', '1000', 'el jueves'),
    filaArchivo(7, '', '1000', '10/09/2026')
];

$diffErr = CobElectronicos::compararImportacion($conErrores, [], $procesadorasImp,
    $alicuotasImp, $corte);

chequear('las seis filas quedan como error', 6, $diffErr['resumen']['errores']);
chequear('y con un solo error no se importa NADA', false, $diffErr['puede_importar']);
chequear('el aviso explica por que es todo o nada',
    true, strpos(implode(' ', $diffErr['avisos']), 'a medias') !== false);

$porLineaErr = [];

foreach ($diffErr['filas'] as $f) {
    $porLineaErr[$f['linea']] = $f;
}

chequear('una procesadora que no existe dice donde darla de alta',
    true, strpos($porLineaErr[2]['motivo'], 'Parámetros') !== false);
chequear('una procesadora inhabilitada no admite movimientos',
    true, strpos($porLineaErr[3]['motivo'], 'inhabilitada') !== false);
chequear('un importe en cero se rechaza',
    true, strpos($porLineaErr[4]['motivo'], 'mayor a cero') !== false);
chequear('un importe que no es numero se rechaza',
    true, strpos($porLineaErr[5]['motivo'], 'no es un número') !== false);
chequear('una fecha que no se entiende dice que formato usar',
    true, strpos($porLineaErr[6]['motivo'], 'dd/mm/aaaa') !== false);
chequear('sin procesadora no hay fila', true,
    strpos($porLineaErr[7]['motivo'], 'Falta la procesadora') !== false);

// Cada fila devuelve lo que vino EN EL ARCHIVO. Es lo que permite que la
// confirmacion mande la misma entrada -las filas con problemas incluidas- y que
// el servidor vuelva a rechazarlas: el "con un solo error no se importa nada" lo
// hace cumplir el servidor y no el navegador.
chequear('la fila conserva el valor crudo del archivo',
    'mil pesos', $porLineaErr[5]['crudo']['importe_bruto']);
chequear('y volver a pasarla por el diff la rechaza igual', 1,
    CobElectronicos::compararImportacion(
        [array_merge(['linea' => 5], $porLineaErr[5]['crudo'])],
        [], $procesadorasImp, $alicuotasImp, $corte)['resumen']['errores']);

// Sin alicuota vigente a esa fecha no se puede calcular el neto: es la misma
// regla que la carga manual, aplicada al archivo.
$sinAli = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000', '10/09/2026')],
    [], $procesadorasImp, [], $corte);

chequear('sin alicuota vigente la fila del archivo se rechaza',
    1, $sinAli['resumen']['errores']);
chequear('con el motivo', true,
    strpos($sinAli['filas'][0]['motivo'], 'alícuota') !== false);

seccion('dos liquidaciones el mismo dia');

// Dos filas con la misma clave y sin ID_EXTERNO son un ERROR y no un aviso: sin
// el numero de liquidacion no hay forma de saber cual es cual.
$repetidas = [
    filaArchivo(2, 'Payway', '1000', '10/09/2026'),
    filaArchivo(3, 'Payway', '2000', '10/09/2026')
];

$diffRep = CobElectronicos::compararImportacion($repetidas, [], $procesadorasImp,
    $alicuotasImp, $corte);

chequear('la segunda queda como error', 1, $diffRep['resumen']['errores']);
chequear('y el mensaje pide llenar ID_EXTERNO',
    true, strpos($diffRep['filas'][1]['motivo'], 'ID_EXTERNO') !== false);
chequear('diciendo con que linea choca',
    true, strpos($diffRep['filas'][1]['motivo'], 'línea 2') !== false);

// Con ID_EXTERNO, las dos entran.
$conExterno = [
    filaArchivo(2, 'Payway', '1000', '10/09/2026', 'LIQ-1'),
    filaArchivo(3, 'Payway', '2000', '10/09/2026', 'LIQ-2')
];

$diffExt = CobElectronicos::compararImportacion($conExterno, [], $procesadorasImp,
    $alicuotasImp, $corte);

chequear('con numero de liquidacion las dos son altas', 2, $diffExt['resumen']['altas']);
chequear('y ninguna es error', 0, $diffExt['resumen']['errores']);

// El ID_EXTERNO manda sobre la fecha: es el caso de una acreditacion
// reprogramada por la procesadora.
$cargadoConExterno = [
    ['id' => 20, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 1000.0, 'fecha_acreditacion' => '2026-09-10',
     'tasa_aplicada' => 0.031, 'importe_neto' => 969.0, 'id_externo' => 'LIQ-1',
     'origen_dato' => 'ARCHIVO']
];

$diffMovida = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000', '15/09/2026', 'LIQ-1')],
    $cargadoConExterno, $procesadorasImp, $alicuotasImp, $corte);

chequear('una acreditacion reprogramada se reconoce por su numero de liquidacion',
    1, $diffMovida['resumen']['cambios']);
chequear('y el motivo dice que se movio la fecha',
    true, strpos($diffMovida['filas'][0]['motivo'], 'fecha pasa') !== false);
chequear('sin proponer un alta', 0, $diffMovida['resumen']['altas']);

seccion('lo que el archivo ya no trae');

// ES LO QUE HACE QUE NO HAYA QUE COMPARAR A MANO: una acreditacion que la
// procesadora dio de baja se queda para siempre si ninguna importacion la
// menciona.
$cargadosBaja = [
    // Dentro del rango del archivo y de su procesadora: candidato
    ['id' => 30, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 700000.0, 'fecha_acreditacion' => '2026-09-11',
     'tasa_aplicada' => 0.031, 'importe_neto' => 678300.0, 'id_externo' => null,
     'origen_dato' => 'ARCHIVO'],
    // Otra procesadora: el archivo no la estaba mirando
    ['id' => 31, 'id_procesadora' => 2, 'procesadora' => 'Mercado Pago',
     'importe_bruto' => 800000.0, 'fecha_acreditacion' => '2026-09-11',
     'tasa_aplicada' => 0.031, 'importe_neto' => 775200.0, 'id_externo' => null,
     'origen_dato' => 'MANUAL'],
    // Fuera del rango de fechas del archivo
    ['id' => 32, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 900000.0, 'fecha_acreditacion' => '2026-09-25',
     'tasa_aplicada' => 0.031, 'importe_neto' => 872100.0, 'id_externo' => null,
     'origen_dato' => 'MANUAL'],
    // Ya acreditada: no se toca nunca
    ['id' => 33, 'id_procesadora' => 1, 'procesadora' => 'Payway',
     'importe_bruto' => 100000.0, 'fecha_acreditacion' => '2026-09-02',
     'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0, 'id_externo' => null,
     'origen_dato' => 'MANUAL']
];

$diffBajas = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000000', '10/09/2026'),
     filaArchivo(3, 'Payway', '1000000', '12/09/2026')],
    $cargadosBaja, $procesadorasImp, $alicuotasImp, $corte);

chequear('solo se propone dar de baja lo que el archivo cubria', 1,
    $diffBajas['resumen']['bajas']);
chequear('y es el que cae dentro de su rango y su procesadora', 30,
    $diffBajas['bajas'][0]['id']);
chequear('el motivo dice el rango que el archivo cubre',
    true, strpos($diffBajas['bajas'][0]['motivo'], '10/09/2026') !== false);
chequear('el aviso avisa que la baja no se aplica sola',
    true, strpos(implode(' ', $diffBajas['avisos']), 'expresamente') !== false);
chequear('el neto que se daria de baja se informa', 678300.0,
    $diffBajas['resumen']['neto_bajas']);

// EL CASO QUE EL RANGO INFERIDO NO PUEDE VER: la procesadora dio de baja la
// PRIMERA acreditacion del periodo, asi que esa fecha ya no esta en el archivo y
// el rango inferido arranca despues. Sin declarar el periodo, esa baja no se
// propone -y no se adivina-.
$diffPrimeraCaida = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000000', '12/09/2026')],
    $cargadosBaja, $procesadorasImp, $alicuotasImp, $corte);

chequear('con el rango inferido, una baja anterior a la primera fila no se propone',
    0, $diffPrimeraCaida['resumen']['bajas']);
chequear('y se informa que la ventana se infirio',
    false, $diffPrimeraCaida['ventana']['declarada']);

// Declarando el periodo -que el usuario conoce, es el que exporto- si se ve.
$diffPeriodo = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000000', '12/09/2026')],
    $cargadosBaja, $procesadorasImp, $alicuotasImp, $corte,
    ['desde' => '2026-09-07', 'hasta' => '2026-09-20']);

chequear('declarando el periodo, la baja aparece', 1, $diffPeriodo['resumen']['bajas']);
chequear('y es la que el archivo dejo de traer', 30, $diffPeriodo['bajas'][0]['id']);
chequear('la ventana queda marcada como declarada',
    true, $diffPeriodo['ventana']['declarada']);
chequear('con las fechas declaradas', '2026-09-07', $diffPeriodo['ventana']['desde']);

// Ni siquiera declarando un periodo largo se toca lo ya acreditado: el corte por
// el inicio del eje manda sobre el periodo.
$diffPeriodoLargo = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000000', '12/09/2026')],
    $cargadosBaja, $procesadorasImp, $alicuotasImp, $corte,
    ['desde' => '2026-01-01', 'hasta' => '2026-12-31']);

$idsBaja = array_map(function ($b) { return $b['id']; }, $diffPeriodoLargo['bajas']);

chequear('un periodo largo no alcanza lo ya acreditado',
    false, in_array(33, $idsBaja, true));
chequear('pero si el resto de la procesadora dentro del periodo',
    true, in_array(32, $idsBaja, true));
chequear('y nunca las de otra procesadora', false, in_array(31, $idsBaja, true));

// Un archivo que no cambia nada lo dice, en lugar de dejar el boton habilitado.
$sinNada = CobElectronicos::compararImportacion(
    [filaArchivo(2, 'Payway', '1000000,00', '10/09/2026')],
    [$cargados[0]], $procesadorasImp, $alicuotasImp, $corte);

chequear('un archivo que no cambia nada no se puede importar',
    false, $sinNada['puede_importar']);
chequear('y lo dice', true,
    strpos(implode(' ', $sinNada['avisos']), 'no cambia nada') !== false);

/* ================================================================
   Contra datos reales
   ================================================================ */
seccion('el modulo contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

// Lo que se verifica aca no es el importe -depende de lo que haya cargado- sino
// que el modulo con las tablas sin crear rinda ceros y avise, en lugar de tumbar
// el tablero.
$prov = CashflowRegistry::instanciar('COB_ELECTRONICOS');
$series = $prov->series($h);

chequear('COB_ELECTRONICOS devuelve exactamente su serie',
    ['COBRANZA'], array_keys($series));
chequear('completa las claves diarias del eje', 28, count($series['COBRANZA']['dias']));
chequear('y las mensuales', 12, count($series['COBRANZA']['meses']));

$noNumericos = array_filter($series['COBRANZA']['dias'], function ($v) {
    return !is_int($v) && !is_float($v);
});

chequear('devuelve solo numeros', 0, count($noNumericos));

// El payload de la pestana tiene que armarse aunque el script no se haya
// corrido: en ese caso avisa y muestra la tabla vacia.
$modulo = new CobElectronicos();
$pestana = $modulo->getPestana([], $h);

chequear('el payload de la pestana trae sus claves', true,
    isset($pestana['filas']) && isset($pestana['por_dia']) && isset($pestana['por_mes'])
    && isset($pestana['totales']) && isset($pestana['avisos']));

if (!$modulo->tablasCreadas()) {
    chequear('sin las tablas creadas la pestana avisa que corran el script', true,
        strpos(implode(' ', $pestana['avisos']), 'cashflow_cob_electronicos.sql') !== false);

    return;
}

// Con las tablas creadas, el cuadro de la pestana y la serie del proveedor
// tienen que decir lo mismo: es el invariante que haria falta romper para que la
// pantalla y el tablero no cierren.
$sumaCuadro = 0;

foreach ($pestana['por_dia'] as $d) {
    $sumaCuadro += $d['neto'];
}

$sumaMesesCuadro = 0;

foreach ($pestana['por_mes'] as $m) {
    $sumaMesesCuadro += $m['neto'];
}

chequear('el cuadro diario y el mensual de la pestana coinciden',
    round($sumaCuadro, 2), round($sumaMesesCuadro, 2));
chequear('y coinciden con el total neto de la pestana',
    round(floatval($pestana['totales']['neto']), 2), round($sumaCuadro, 2));

$enEje = array_sum($series['COBRANZA']['dias']) + array_sum($series['COBRANZA']['meses']);

chequear('el neto de la pestana es el del tablero mas lo que quedo afuera',
    round(floatval($pestana['totales']['neto']), 2),
    round($enEje + floatval($series['COBRANZA']['fuera_horizonte']), 2));
