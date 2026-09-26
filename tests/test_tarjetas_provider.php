<?php
/**
 * El proveedor TARJETAS: las cinco series, el invariante del total y los casos en
 * los que la fila va en cero CON un aviso.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL TOTAL NO ES LA SUMA DE LAS TRES PARTES. Es el invariante del proveedor, y
 *      la fila del tablero usa TOTAL: si se desincronizara, el cuadro mostraria un
 *      numero que no corresponde a ninguna de las tres sub-pestanas y cerraria
 *      igual, asi que nada lo delataria.
 *
 *   2. LAS EXCLUIDAS ENTRAN AL TOTAL. Se decidió que no entren: contarlas ahi las
 *      devuelve al tablero por la puerta de atras.
 *
 *   3. LA FILA VA EN CERO SIN DECIR POR QUE. Un egreso en cero se lee como "no hay
 *      que pagar nada", que es lo contrario de lo que pasa cuando faltan las tablas
 *      o no hay ninguna tarjeta cargada.
 *
 *   4. UN MODULO QUE LANZA TUMBA EL TABLERO. El contrato lo prohibe: calcular()
 *      puede lanzar y series() lo envuelve, asi que la fila rinde cero y el resto
 *      del cuadro sigue.
 *
 *   5. EL DETALLE SE PIERDE EN normalizar(). La serie de salida se arma con una
 *      lista cerrada de claves: cualquier cosa que el proveedor cuelgue y no este
 *      en esa lista se descarta EN SILENCIO. Ya paso una vez con el reparto por
 *      fondo de la cobertura.
 *
 *   6. DOS RESUMENES EN LA MISMA COLUMNA DEJAN UNA SOLA NOTA. El contrato admite
 *      una anotacion por celda, asi que dos tarjetas que vencen la misma semana
 *      tienen que juntar sus notas en vez de que la segunda pise a la primera.
 *
 * Se prueba con un DOBLE de PagosTarjetas, no contra la base: los casos que
 * importan -tablas sin crear, ninguna tarjeta, un modulo que lanza- no se pueden
 * montar en una base real sin borrar tablas.
 */

require_once __DIR__ . '/../cashflow/Class/Providers/TarjetasProvider.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Horizonte.php';

/**
 * Un doble de PagosTarjetas que devuelve lo que se le diga.
 *
 * Es la costura que TarjetasProvider::modulo() existe para ofrecer, igual que
 * LogisticaProvider::modulo() y CobElectronicosProvider::modulo().
 */
class PagosTarjetasDoble {

    public $datos;

    /** @var bool Si true, calcular() lanza: el caso que el contrato tiene que atrapar */
    public $lanza = false;

    function __construct($datos) {
        $this->datos = $datos;
    }

    public function calcular($h) {
        if ($this->lanza) {
            throw new Exception('la base no responde');
        }

        return $this->datos;
    }
}

/** El proveedor con el doble adentro */
class TarjetasProviderDoble extends TarjetasProvider {

    public $doble;

    protected function modulo() {
        return $this->doble;
    }
}

/**
 * Arma el payload que PagosTarjetas::calcular() devuelve, con lo minimo.
 *
 * Va aca y no en la clase real porque es la FORMA del payload lo que se prueba: si
 * alguien le agrega una clave obligatoria, esta funcion no la tiene y la prueba
 * falla, que es exactamente lo que hay que enterarse.
 */
function datosTP($sup = [], $corp = [], $soc = [], $tablas = [], $avisos = []) {
    return [
        'hoy' => '2026-09-26',
        'meses' => ['2026-09', '2026-10', '2026-11'],
        'tablas' => array_merge(['tarjetas' => true, 'resumen' => true, 'factura' => true,
                                 'exclusion' => true, 'gastos' => true,
                                 'vista_usuarios' => true], $tablas),
        'tarjetas' => [],
        'avisos' => $avisos,
        'supervisoras' => array_merge(['pagos' => [], 'avisos' => [], 'filas' => [],
                                       'sin_tarjeta' => 0.0], $sup),
        'corporativas' => array_merge(['pagos' => [], 'pagos_excluidos' => [], 'avisos' => [],
                                       'filas' => [], 'cobertura' => [], 'resumenes' => []],
                                      $corp),
        'socios' => array_merge(['pagos' => [], 'avisos' => [], 'filas' => [], 'cartel' => ''],
                                $soc)
    ];
}

/** Un proveedor listo para pedirle las series */
function proveedorTP($datos, $lanza = false) {
    $p = new TarjetasProviderDoble('TARJETAS');
    $p->doble = new PagosTarjetasDoble($datos);
    $p->doble->lanza = $lanza;

    return $p;
}

/* El eje: 28 dias desde el 26/09/2026 y 12 meses. */
$H = new Horizonte(28, 12, [], new DateTime('2026-09-26'));

/* ================================================================
   EL REGISTRO
   ================================================================ */
seccion('el registro declara el proveedor');

chequear('TARJETAS esta registrado', true, CashflowRegistry::existe('TARJETAS'));
chequear('y disponible', true, CashflowRegistry::disponible('TARJETAS'));
chequear('no esta retirado', false, (bool) CashflowRegistry::retirado('TARJETAS'));

$meta = CashflowRegistry::meta('TARJETAS');

chequear('se llama como la pestana', 'Pagos con Tarjetas y Otros', $meta['nombre']);
chequear('su moneda es ARS', 'ARS', $meta['moneda']);
chequear('y apunta a su pestana', 'pagos_tarjetas', $meta['tab']);

chequear('declara las cinco series',
    ['TOTAL', 'SUPERVISORAS', 'CORPORATIVAS', 'SOCIOS', 'CORPORATIVAS_EXCLUIDAS'],
    array_keys($meta['series']));

/* LAS CONSTANTES DE LA CLASE Y LAS CLAVES DEL REGISTRO TIENEN QUE COINCIDIR. Una
   serie declarada y no servida se dibuja "sin datos"; una servida y no declarada no
   se puede configurar desde Parametros. */
foreach ([TarjetasProvider::SERIE_TOTAL, TarjetasProvider::SERIE_SUPERVISORAS,
          TarjetasProvider::SERIE_CORPORATIVAS, TarjetasProvider::SERIE_SOCIOS,
          TarjetasProvider::SERIE_EXCLUIDAS] as $s) {
    chequear("el registro declara $s", true, CashflowRegistry::serieExiste('TARJETAS', $s));
}

seccion('el registro declara exactamente las series que el proveedor devuelve');

/* ES EL INVARIANTE QUE test_proveedores.php fija para Proveedores Locales, y vale
   igual aca: los dos CONJUNTOS tienen que ser el mismo. Una serie declarada y no
   servida se dibuja "sin datos"; una servida y no declarada no se puede configurar
   desde Parametros.

   SE COMPARAN ORDENADOS porque el ORDEN no es parte del contrato: el motor busca
   cada serie por su clave. El proveedor arma TOTAL al final -sumando las tres
   partes, que es lo que hace que el invariante no pueda romperse- y el registro lo
   declara primero, porque es el que usa la fila. Las dos cosas estan bien. */
$series = proveedorTP(datosTP())->series($H);

$declaradas = array_keys($meta['series']);
$servidas = array_keys($series);
sort($declaradas);
sort($servidas);

chequear('coinciden', $declaradas, $servidas);

/* ================================================================
   EL INVARIANTE DEL TOTAL
   ================================================================ */
seccion('TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS');

$datos = datosTP(
    ['pagos' => [
        ['parte' => 'EFECTIVO', 'supervisora' => 'ANA', 'mes' => '2026-10',
         'fecha' => '2026-10-09', 'importe' => 50000],
        ['parte' => 'TARJETA', 'supervisora' => 'ANA', 'mes' => '2026-10',
         'fecha' => '2026-10-12', 'importe' => 800000, 'origen' => 'ESTIMACION']
    ]],
    ['pagos' => [
        ['tipo' => 'FACTURA', 'fecha' => '2026-10-05', 'importe' => 100000],
        ['tipo' => 'COBERTURA', 'fecha' => '2026-10-12', 'importe' => 10000],
        ['tipo' => 'RESUMEN', 'fecha' => '2026-11-12', 'importe' => 450000]
    ]],
    ['pagos' => [
        ['id_tarjeta' => 3, 'mes' => '2026-10', 'fecha' => '2026-10-15',
         'importe' => 1500000, 'origen' => 'ESTIMACION']
    ]]
);

$s = proveedorTP($datos)->series($H);

/* COLUMNA POR COLUMNA, y no solo el gran total: un pago que se cuente en una serie
   y no en el total daria el mismo total con las columnas corridas. */
$malas = [];

foreach (['dias', 'meses'] as $rama) {
    foreach ($s['TOTAL'][$rama] as $clave => $valor) {
        $suma = $s['SUPERVISORAS'][$rama][$clave]
              + $s['CORPORATIVAS'][$rama][$clave]
              + $s['SOCIOS'][$rama][$clave];

        if (abs($valor - $suma) > 0.001) {
            $malas[] = $rama . '|' . $clave;
        }
    }
}

chequear('el invariante se cumple en TODAS las columnas', [], $malas);

/* Y EL GRAN TOTAL TAMBIEN, que es la lectura que hace alguien mirando el pie. */
function totalTP($serie) {
    return array_sum($serie['dias']) + array_sum($serie['meses']);
}

chequear('y en el gran total', totalTP($s['SUPERVISORAS']) + totalTP($s['CORPORATIVAS'])
    + totalTP($s['SOCIOS']), totalTP($s['TOTAL']));

chequear('que son los 2.910.000 de los cuatro pagos', 2910000.0, totalTP($s['TOTAL']));

seccion('cada parte trae lo suyo y nada mas');

chequear('supervisoras suma sus dos pagos', 850000.0, totalTP($s['SUPERVISORAS']));
chequear('corporativas sus tres', 560000.0, totalTP($s['CORPORATIVAS']));
chequear('y socios el suyo', 1500000.0, totalTP($s['SOCIOS']));

seccion('los importes van en POSITIVO');

/* EL SIGNO LO PONE EL TIPO DE LA FILA y no el dato, igual que todos los demas
   proveedores de egresos. Un negativo aca se restaria dos veces. */
$negativos = [];

foreach ($series as $codigo => $serie) {
    foreach (['dias', 'meses'] as $rama) {
        foreach ($s[$codigo][$rama] as $clave => $valor) {
            if ($valor < 0) {
                $negativos[] = $codigo . '/' . $clave;
            }
        }
    }
}

chequear('ninguno', [], $negativos);

/* ================================================================
   LAS EXCLUIDAS NO ENTRAN AL TOTAL
   ================================================================ */
seccion('las excluidas van a su serie y NO al total');

$datos = datosTP(
    [],
    ['pagos' => [['tipo' => 'FACTURA', 'fecha' => '2026-10-05', 'importe' => 100000]],
     'pagos_excluidos' => [['fecha' => '2026-10-06', 'importe' => 777777]]]
);

$s = proveedorTP($datos)->series($H);

chequear('la serie informativa las trae', 777777.0, totalTP($s['CORPORATIVAS_EXCLUIDAS']));
chequear('CORPORATIVAS no', 100000.0, totalTP($s['CORPORATIVAS']));
chequear('y el TOTAL tampoco', 100000.0, totalTP($s['TOTAL']));

/* EL REGISTRO NO LAS DECLARA COMO COMPONENTE DEL TOTAL, y por eso una fila con la
   serie informativa PUEDE convivir con la fila del total sin que el validador la
   rechace: su importe no esta en el total. */
chequear('el registro no las declara componente del total', false,
    in_array('CORPORATIVAS_EXCLUIDAS', $meta['componentes']['TOTAL'], true));

/* ================================================================
   EL EJE DECIDE LA COLUMNA
   ================================================================ */
seccion('el eje ubica cada pago, y lo que cae afuera se informa');

/* Con hoy = 26/09/2026 y 28 dias, el tramo va del 26/09 al 23/10. Un pago del 05/10
   cae en una columna DIARIA y uno del 30/10 en la columna MENSUAL de octubre. */
$datos = datosTP([], ['pagos' => [
    ['tipo' => 'FACTURA', 'fecha' => '2026-10-05', 'importe' => 100000],
    ['tipo' => 'FACTURA', 'fecha' => '2026-10-30', 'importe' => 200000]
]]);

$s = proveedorTP($datos)->series($H);

chequear('el del 5 de octubre cae en su dia', 100000.0, $s['CORPORATIVAS']['dias']['2026-10-05']);
chequear('el del 30 en la columna del mes', 200000.0, $s['CORPORATIVAS']['meses']['2026-10']);

/* UN IMPORTE VA A UN DIA O A UN MES, NUNCA A LOS DOS: es la regla del eje, y esto
   la verifica sobre esta serie. */
chequear('y el del 5 NO esta tambien en el mes', 200000.0, $s['CORPORATIVAS']['meses']['2026-10']);

seccion('lo posterior al horizonte se informa, no se descarta');

$datos = datosTP([], ['pagos' => [
    ['tipo' => 'FACTURA', 'fecha' => '2030-01-15', 'importe' => 333333]
]]);

$p = proveedorTP($datos);
$s = $p->series($H);

chequear('no entra en ninguna columna', 0.0, totalTP($s['CORPORATIVAS']));
chequear('se informa en fuera_horizonte', 333333.0, $s['CORPORATIVAS']['fuera_horizonte']);
chequear('el total lo hereda, que es la fila que se dibuja', 333333.0,
    $s['TOTAL']['fuera_horizonte']);
chequear('y se avisa', true,
    strpos(implode(' ', $p->warnings()), 'posterior al final del horizonte') !== false);

seccion('un pago sin fecha se informa en sin_fecha');

$datos = datosTP([], ['pagos' => [
    ['tipo' => 'FACTURA', 'fecha' => null, 'importe' => 444444]
]]);

$p = proveedorTP($datos);
$s = $p->series($H);

chequear('no entra en ninguna columna', 0.0, totalTP($s['CORPORATIVAS']));
chequear('se informa', 444444.0, $s['CORPORATIVAS']['sin_fecha']);
chequear('y se avisa', true,
    strpos(implode(' ', $p->warnings()), 'sin una fecha') !== false);

/* ================================================================
   EL DETALLE DE LAS CELDAS CON RESUMEN
   ================================================================ */
seccion('un resumen cargado anota su celda');

$datos = datosTP([], ['resumenes' => [
    ['id_tarjeta' => 7, 'id_resumen' => 55, 'mes' => '2026-10', 'importe' => 450000.0,
     'usd' => null, 'fecha' => '2026-10-12', 'pagado' => false, 'proyecta' => true,
     'motivo' => null]
], 'pagos' => [
    ['tipo' => 'RESUMEN', 'fecha' => '2026-10-12', 'importe' => 450000]
]]);

$s = proveedorTP($datos)->series($H);

chequear('la celda del 12 de octubre queda anotada', true,
    isset($s['TOTAL']['detalle']['DIA|2026-10-12']));
chequear('con el importe del resumen', 450000.0,
    $s['TOTAL']['detalle']['DIA|2026-10-12']['importe']);
chequear('y la nota dice que es un resumen cargado', true,
    strpos($s['TOTAL']['detalle']['DIA|2026-10-12']['nota'], 'Resumen cargado') !== false);
chequear('con su mes', true,
    strpos($s['TOTAL']['detalle']['DIA|2026-10-12']['nota'], '2026-10') !== false);

/* EL DETALLE SOBREVIVE A normalizar(). La serie de salida se arma con una lista
   CERRADA de claves, asi que esto es lo que verifica que 'detalle' este en ella: ya
   se perdio una vez en silencio con el reparto por fondo de la cobertura. */
chequear('sobrevive a normalizar()', true, is_array($s['TOTAL']['detalle']));

/* NO ES UNA SERIE APARTE: el importe anotado NO se suma. Una serie nueva seria una
   fila nueva sumando un importe que la fila original ya suma. */
chequear('el detalle no suma nada extra', 450000.0, totalTP($s['TOTAL']));

seccion('dos resumenes en la misma columna juntan sus notas');

/* EL CONTRATO ADMITE UNA ANOTACION POR CELDA. Dos tarjetas que vencen la misma
   semana tienen que juntar sus notas: quedarse con la primera escondería la
   segunda. */
$datos = datosTP(
    ['pagos' => [
        ['parte' => 'TARJETA', 'supervisora' => 'ANA', 'mes' => '2026-10',
         'fecha' => '2026-10-12', 'importe' => 300000, 'origen' => 'RESUMEN']
    ]],
    ['resumenes' => [
        ['id_tarjeta' => 7, 'id_resumen' => 55, 'mes' => '2026-10', 'importe' => 450000.0,
         'usd' => null, 'fecha' => '2026-10-12', 'pagado' => false, 'proyecta' => true,
         'motivo' => null]
    ], 'pagos' => [
        ['tipo' => 'RESUMEN', 'fecha' => '2026-10-12', 'importe' => 450000]
    ]]
);

$s = proveedorTP($datos)->series($H);
$nota = $s['TOTAL']['detalle']['DIA|2026-10-12'];

chequear('los importes se suman', 750000.0, $nota['importe']);
chequear('la nota trae las dos', true, strpos($nota['nota'], '|') !== false);
chequear('nombrando la supervisora', true, strpos($nota['nota'], 'ANA') !== false);

seccion('una anotacion sobre una columna que no existe se descarta');

/* Lo hace normalizar(), y no genera aviso: lo que cayo fuera del eje ya lo informa
   la serie a la que anota. */
$datos = datosTP([], ['resumenes' => [
    ['id_tarjeta' => 7, 'id_resumen' => 55, 'mes' => '2030-01', 'importe' => 450000.0,
     'usd' => null, 'fecha' => '2030-01-15', 'pagado' => false, 'proyecta' => true,
     'motivo' => null]
]]);

$s = proveedorTP($datos)->series($H);

chequear('no queda ninguna anotacion', [], $s['TOTAL']['detalle']);

seccion('un resumen que no proyecta no anota nada');

/* Un resumen PAGADO sale del horizonte, asi que su celda no tiene nada que
   distinguir: no hay importe ahi que explicar. */
$datos = datosTP([], ['resumenes' => [
    ['id_tarjeta' => 7, 'id_resumen' => 55, 'mes' => '2026-10', 'importe' => 450000.0,
     'usd' => null, 'fecha' => '2026-10-12', 'pagado' => true, 'proyecta' => false,
     'motivo' => 'Pagado']
]]);

$s = proveedorTP($datos)->series($H);

chequear('sin anotacion', [], $s['TOTAL']['detalle']);
chequear('y sin importe', 0.0, totalTP($s['TOTAL']));

/* ================================================================
   LA FILA EN CERO SIEMPRE SE EXPLICA
   ================================================================ */
seccion('sin las tablas, la fila va en cero y lo dice');

$p = proveedorTP(datosTP([], [], [], ['tarjetas' => false]));
$s = $p->series($H);
$avisos = implode(' | ', $p->warnings());

chequear('la fila va en cero', 0.0, totalTP($s['TOTAL']));
chequear('se avisa que falta el script', true,
    strpos($avisos, 'sql/cashflow_tarjetas.sql') !== false);
chequear('y se aclara que un cero no significa que no haya que pagar', true,
    strpos($avisos, 'no significa que no haya que pagar nada') !== false);

seccion('con las tablas pero sin nada cargado, tambien');

$p = proveedorTP(datosTP());
$s = $p->series($H);
$avisos = implode(' | ', $p->warnings());

chequear('la fila va en cero', 0.0, totalTP($s['TOTAL']));
chequear('y se dice que falta de las tres partes', true,
    strpos($avisos, 'no hay supervisoras con gastos') !== false
    && strpos($avisos, 'TARJETA CORP') !== false
    && strpos($avisos, 'tarjetas de socios activas') !== false);

seccion('con importes, no se avisa el cero');

$datos = datosTP([], ['pagos' => [
    ['tipo' => 'FACTURA', 'fecha' => '2026-10-05', 'importe' => 100000]
]]);

$p = proveedorTP($datos);
$p->series($H);

chequear('no aparece el aviso de fila en cero', false,
    strpos(implode(' ', $p->warnings()), 'va en CERO') !== false);

/* ================================================================
   LOS AVISOS SE LEVANTAN TAL CUAL
   ================================================================ */
seccion('los avisos de cada parte llegan al tablero');

$datos = datosTP(
    ['avisos' => ['6 supervisoras no tienen tarjeta']],
    ['avisos' => ['90 facturas vencidas no entran']],
    ['avisos' => ['cargá la base histórica']],
    [],
    ['falta la vista de usuarios']
);

$p = proveedorTP($datos);
$p->series($H);
$avisos = implode(' | ', $p->warnings());

chequear('el del modulo llega', true, strpos($avisos, 'falta la vista de usuarios') !== false);
chequear('el de supervisoras tambien', true,
    strpos($avisos, '6 supervisoras no tienen tarjeta') !== false);
chequear('nombrado', true, strpos($avisos, 'Gastos Supervisoras:') !== false);
chequear('el de corporativas', true, strpos($avisos, '90 facturas vencidas') !== false);
chequear('y el de socios', true, strpos($avisos, 'cargá la base histórica') !== false);

/* ================================================================
   NUNCA TUMBA EL TABLERO
   ================================================================ */
seccion('un modulo que lanza no tumba el tablero');

/* ES LA REGLA DEL CONTRATO: calcular() puede lanzar y series() lo envuelve. El
   tablero consolida diecinueve modulos, y si una excepcion se propagara un solo
   modulo con problemas dejaria la pantalla entera en blanco. */
$p = proveedorTP(datosTP(), true);
$s = $p->series($H);

chequear('series() no lanza', true, is_array($s));
chequear('y deja un aviso', 1, count($p->warnings()));
chequear('que nombra el proveedor', true, strpos($p->warnings()[0], 'TARJETAS') !== false);
chequear('y el motivo', true, strpos($p->warnings()[0], 'la base no responde') !== false);

/* ================================================================
   LA FORMA DE LAS SERIES

   Es lo que normalizar() garantiza, y lo que el motor consume sin preguntar.
   ================================================================ */
seccion('las series salen completas contra el eje');

$s = proveedorTP(datosTP())->series($H);

foreach (array_keys($meta['series']) as $codigo) {
    chequear("$codigo completa las claves diarias", 28, count($s[$codigo]['dias']));
    chequear("$codigo completa las mensuales", 12, count($s[$codigo]['meses']));
    chequear("$codigo declara su moneda", 'ARS', $s[$codigo]['moneda_origen']);
}

/* EN PESOS Y SIN TIPO DE CAMBIO A NIVEL SERIE: la conversion del componente en
   dolares de los socios la hace TarjetasSocios fila por fila, con un tipo de cambio
   distinto por mes, asi que no hay UN tipo de cambio que informar. Es el mismo caso
   que Comex con la curva de dolar futuro. */
chequear('no declara un tipo de cambio unico', null, $s['SOCIOS']['tipo_cambio']);

/* ================================================================
   LA FILA DEL TABLERO

   Lo que el script crea, contrastado contra lo que el proveedor sirve.
   ================================================================ */
seccion('la fila del tablero apunta a una serie que existe');

$sqlFila = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_fila.sql');

chequear('el script apunta a TARJETAS / TOTAL', true,
    strpos($sqlFila, "'TARJETAS', 'TOTAL'") !== false);
chequear('y esa serie existe', true, CashflowRegistry::serieExiste('TARJETAS', 'TOTAL'));

/* LA FILA VA EN COSTOS_INDIRECTOS Y ES UNA SOLA. El detalle por parte se ve en la
   pestana; las series de cada parte quedan para poder partirla desde Parametros. */
chequear('va en Costos Indirectos', true, strpos($sqlFila, "'COSTOS_INDIRECTOS'") !== false);
chequear('y es un EGRESO que computa', true, strpos($sqlFila, "'EGRESO', 1") !== false);

/* ================================================================
   LAS TRES GRILLAS DE LA PESTANA: ENCABEZADO, CUERPO Y PIE ALINEADOS

   QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA, y ya se rompio una vez: el pie de
   Corporativas dibujaba una columna de total que el encabezado NO tenia, asi que
   quedaba una celda mas ancho y corria TODOS los totales del eje un mes. Eso no se
   ve como un error: se ve como numeros.

   Se mide sobre el HTML y el JS -no se confia en las constantes- porque la
   constante es justamente el numero que alguien se olvida de actualizar al agregar
   una columna. Es el mismo criterio que la prueba del pie de Proveedores Locales.
   ================================================================ */
seccion('las tres grillas tienen las mismas columnas en el encabezado y en el pie');

$htmlTarj = preg_replace('/<!--.*?-->/s', '',
    file_get_contents(__DIR__ . '/../cashflow/Tabs/pagos_tarjetas.php'));
$jsTarj = preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '',
    file_get_contents(__DIR__ . '/../cashflow/Js/Financiero-Pagos_tarjetas.js'));

$grillas = [
    'Supervisoras' => ['tabla' => 'tablaSup', 'eje' => 'headerEjeSup',
                       'pie' => 'totalesSup', 'const' => 'COLS_SUP'],
    'Corporativas' => ['tabla' => 'tablaCorp', 'eje' => 'headerEjeCorp',
                       'pie' => 'totalesCorp', 'const' => 'COLS_CORP'],
    'Socios' => ['tabla' => 'tablaSoc', 'eje' => 'headerEjeSoc',
                 'pie' => 'totalesSoc', 'const' => 'COLS_SOC']
];

foreach ($grillas as $nombre => $g) {
    $ini = strpos($htmlTarj, '<table id="' . $g['tabla'] . '"');

    chequear($nombre . ': la tabla existe', true, $ini !== false);

    if ($ini === false) {
        continue;
    }

    $thead = substr($htmlTarj, $ini, strpos($htmlTarj, '</thead>', $ini) - $ini);

    /* La primera fila del encabezado son los <th rowspan="2"> mas UN <th> con el
       grupo del eje. De esos rowspan, el ultimo es TOTAL PERIODO -que va despues
       del eje- asi que las descriptivas son los demas. */
    $rowspan = substr_count($thead, 'rowspan="2"');
    $descriptivas = $rowspan - 1;

    chequear($nombre . ': tiene el grupo del eje', true,
        strpos($thead, 'id="' . $g['eje'] . '"') !== false);

    /* LA CONSTANTE DEL JS CUENTA LAS DESCRIPTIVAS, sin la de total. */
    preg_match('/var ' . $g['const'] . ' = (\d+);/', $jsTarj, $m);

    chequear($nombre . ': ' . $g['const'] . ' coincide con el encabezado',
        $descriptivas, intval($m[1]));

    /* EL COLSPAN INICIAL DEL PIE tiene que ser el mismo: es sólo el estado inicial
       -pintarTotales() lo reescribe- pero si arranca mal la tabla parpadea
       desalineada en cada carga. */
    preg_match('/id="' . $g['pie'] . '">\s*<td colspan="(\d+)"/', $htmlTarj, $mp);

    chequear($nombre . ': el colspan inicial del pie tambien', $descriptivas, intval($mp[1]));

    /* EL TOTAL VA DESPUES DEL EJE, que es lo que esta pestana cambio: antes estaba
       antes y se leia un total seguido de los meses de los que sale. */
    $posEje = strpos($thead, 'id="' . $g['eje'] . '"');
    $posTotal = strpos($thead, 'TOTAL PERÍODO');

    chequear($nombre . ': el total va DESPUES de las columnas que suma',
        true, $posTotal !== false && $posTotal > $posEje);
}

/* pintarTotales() ARMA LAS TRES IGUAL: un colspan, las del eje, y el total al
   final. Una firma con un parametro de ajuste por tabla es justamente como se
   colaba el desajuste de Corporativas. */
chequear('pintarTotales ya no recibe un ajuste por tabla', false,
    strpos($jsTarj, 'cols, 1)') !== false);
chequear('y pone el total al final', true,
    strpos($jsTarj, 'El total del período, en la última columna') !== false
    || strpos($jsTarj, "html += '<td class=\"currency fw-bold\">' + plata(vistas.total(totales))")
       !== false);
