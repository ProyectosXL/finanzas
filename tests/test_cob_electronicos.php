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

require_once __DIR__ . '/../cashflow/Class/CobElectronicos.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Providers/CobElectronicosProvider.php';

/** Eje de referencia de todas las pruebas: 28 dias desde el 6/9/2026 + 12 meses */
$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));

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

$plan = CobElectronicos::planRecalculo($movimientos, [1 => $alicuotas], '2026-09-06');

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
        '2026-09-06')['cambios']));

// Una procesadora que perdio sus alicuotas no puede recalcular: el movimiento
// queda con su tasa vieja y se AVISA, en lugar de quedar en cero (informaria de
// menos) o en el bruto (informaria de mas).
$sinAlicuota = CobElectronicos::planRecalculo($movimientos, [], '2026-09-06');

chequear('sin alicuotas no se recalcula nada', 0, count($sinAlicuota['cambios']));
chequear('y se avisa', 1, count($sinAlicuota['avisos']));
chequear('el aviso dice que conservan su tasa',
    true, strpos($sinAlicuota['avisos'][0], 'Conservan la tasa') !== false);

/* ================================================================
   Donde cae un movimiento respecto del eje
   ================================================================ */
seccion('un movimiento con fecha anterior al eje NO abre el horizonte');

chequear('una fecha del eje esta dentro',
    'DENTRO', CobElectronicos::ubicacionEnEje('2026-09-10', $h));
chequear('el primer dia del eje tambien',
    'DENTRO', CobElectronicos::ubicacionEnEje('2026-09-06', $h));
chequear('una fecha anterior queda afuera',
    'ANTERIOR', CobElectronicos::ubicacionEnEje('2026-09-02', $h));

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
chequear('queda en fuera_horizonte, junto con el posterior al eje',
    775200.0, $serie['fuera_horizonte']);
chequear('y no se descarta en silencio: hay dos avisos', 2, count($serie['warnings']));
chequear('el aviso del ya acreditado explica el doble conteo',
    true, strpos($serie['warnings'][0], 'dos veces') !== false);
chequear('y dice de que fecha es',
    true, strpos($serie['warnings'][0], '02/09/2026') !== false);
chequear('el aviso del posterior dice que no tiene columna',
    true, strpos($serie['warnings'][1], 'posterior') !== false);
chequear('con el importe', true, strpos($serie['warnings'][1], '290.700,00') !== false);

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
chequear('se informa cuanto queda fuera del eje', 775200.0, $armado['totales']['fuera_eje']);
chequear('y cuantos movimientos son', 2, $armado['totales']['fuera_eje_movimientos']);

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
chequear('y tambien lo que quedo fuera del horizonte', true,
    strpos($avisos, 'fuera del horizonte') !== false ||
    strpos($avisos, 'anterior al inicio del horizonte') !== false);

// 5. Todo lo cargado cae fuera del horizonte. Cero, pero con explicacion.
$todoAfuera = proveedorConDoble(function ($f) {
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

$s = $todoAfuera->series($h)['COBRANZA'];

chequear('con todo fuera del horizonte la fila va en cero', 0.0, array_sum($s['dias']));
chequear('el importe no se descarta en silencio', 969.0, $s['fuera_horizonte']);
chequear('y el aviso remite al saldo bancario', true,
    strpos(implode(' ', $todoAfuera->warnings()), 'saldo bancario') !== false);

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
chequear('y lo que no entra queda informado', 775200.0, $s['fuera_horizonte']);
chequear('el eje mas lo de afuera son todos los netos',
    3682200.0, array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte']);

// El proveedor devuelve la serie que declara el registro, y una sola.
chequear('devuelve exactamente la serie del registro',
    ['COBRANZA'], array_keys($normal->series($h)));

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
$pestana = $modulo->getPestana();

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
