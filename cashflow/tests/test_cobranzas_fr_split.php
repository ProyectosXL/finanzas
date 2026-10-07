<?php
/**
 * Cobranzas FR partida en dos filas del tablero: Real y Proyectada.
 *
 * Lo que se prueba aca es la INVARIANTE entre dos consultas que viven
 * separadas: lo que cuenta la cobranza real y lo que la proyeccion excluye
 * tienen que ser complementarios. Si se desalinean, las facturas que caen en el
 * hueco desaparecen de las dos pestanas y la plata se evapora en silencio, que
 * es exactamente el tipo de error que no se ve mirando la pantalla.
 *
 * No toca la base: las dos listas de estados son constantes de la clase.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/DirectorioFranquicias.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';

/* ================================================================
   Los estados de propuesta y su reparto
   ================================================================ */
seccion('real y proyectado se reparten el universo sin huecos');

// Los tres estados que existen de verdad en FP_propuestas_pago.
$ESTADOS = ['ACEPTADA', 'PAGADO', 'PENDIENTE_APROBACION_CLIENTE'];

chequear('Real cuenta unicamente las propuestas aceptadas',
    ['ACEPTADA'], Ingresos::ESTADOS_REAL);

chequear('la proyeccion excluye lo aceptado y lo ya pagado',
    ['ACEPTADA', 'PAGADO'], Ingresos::ESTADOS_YA_CONTADOS);

// La regla de oro: todo estado que Real NO cuenta y que no esta ya cobrado
// tiene que volver a la proyeccion.
$vuelveAProyeccion = array_values(array_diff($ESTADOS, Ingresos::ESTADOS_YA_CONTADOS));

chequear('una propuesta pendiente de aprobacion vuelve a la proyeccion',
    ['PENDIENTE_APROBACION_CLIENTE'], $vuelveAProyeccion);

foreach ($ESTADOS as $estado) {
    $enReal = in_array($estado, Ingresos::ESTADOS_REAL, true);
    $excluido = in_array($estado, Ingresos::ESTADOS_YA_CONTADOS, true);
    $enProyeccion = !$excluido;

    // 'PAGADO' es el unico que no esta en ninguna de las dos, y esta bien: ya
    // se cobro, no es plata a cobrar.
    $contado = ($enReal ? 1 : 0) + ($enProyeccion ? 1 : 0);
    $esperado = ($estado === 'PAGADO') ? 0 : 1;

    chequear($estado . ' se cuenta ' . $esperado . ' vez/veces', $esperado, $contado);
}

chequear('lo que cuenta Real esta siempre excluido de la proyeccion',
    [], array_values(array_diff(Ingresos::ESTADOS_REAL, Ingresos::ESTADOS_YA_CONTADOS)));

/* ================================================================
   La invariante con el universo filtrado

   Las dos solapas se filtran por el directorio de sucursales -solo las
   franquicias habilitadas-, y el filtro es POR CLIENTE. Lo que se prueba es
   que, aplicado igual a las dos consultas, la particion estado por estado se
   mantiene dentro del universo: un comprobante de una habilitada esta en una
   solapa o en la otra, y uno de una inhabilitada no esta en ninguna -y queda
   contado afuera, para el aviso-. Si una solapa filtrara y la otra no, el
   comprobante de una inhabilitada en una propuesta pendiente apareceria en
   una sola, y la plata de ese cliente estaria a medias en el tablero.
   ================================================================ */

seccion('la invariante se mantiene con el filtro de franquicias habilitadas');

$dirSplit = DirectorioFranquicias::armar([
    ['COD_CLIENT' => 'FRHAB', 'HABILITADO' => 1],
    ['COD_CLIENT' => 'LAHAB', 'HABILITADO' => 1],
    ['COD_CLIENT' => 'FRBAJA', 'HABILITADO' => 0]
]);

// Un comprobante por cliente y por estado de propuesta, mas uno sin propuesta.
$comprobantes = [];
$n = 0;

foreach (['FRHAB', 'LAHAB', 'FRBAJA'] as $cli) {
    foreach (array_merge($ESTADOS, [null]) as $estado) {
        $comprobantes[] = ['N_COMP' => ++$n, 'COD_CLI' => $cli, 'ESTADO' => $estado, 'importe_neto' => 10.0];
    }
}

// Lo que cuenta cada solapa ANTES de filtrar, con las dos constantes.
$real = array_values(array_filter($comprobantes, function ($c) {
    return $c['ESTADO'] !== null && in_array($c['ESTADO'], Ingresos::ESTADOS_REAL, true);
}));
$proy = array_values(array_filter($comprobantes, function ($c) {
    return $c['ESTADO'] === null || !in_array($c['ESTADO'], Ingresos::ESTADOS_YA_CONTADOS, true);
}));

// El mismo filtro de los dos lados, como en Ingresos.
$realU = DirectorioFranquicias::filtrarUniverso($real, $dirSplit, 'COD_CLI', 'importe_neto');
$proyU = DirectorioFranquicias::filtrarUniverso($proy, $dirSplit, 'COD_CLI', 'importe_neto');

$enReal = array_column($realU['items'], 'N_COMP');
$enProy = array_column($proyU['items'], 'N_COMP');

foreach ($comprobantes as $c) {
    $veces = (in_array($c['N_COMP'], $enReal, true) ? 1 : 0) + (in_array($c['N_COMP'], $enProy, true) ? 1 : 0);
    $habilitada = ($c['COD_CLI'] !== 'FRBAJA');
    $esperado = (!$habilitada || $c['ESTADO'] === 'PAGADO') ? 0 : 1;

    chequear($c['COD_CLI'] . ' / ' . ($c['ESTADO'] ?: 'sin propuesta') . ' se cuenta '
        . $esperado . ' vez/veces', $esperado, $veces);
}

chequear('ninguna solapa trae nada de la inhabilitada', [],
    array_values(array_intersect(['FRBAJA'],
        array_merge(array_column($realU['items'], 'COD_CLI'), array_column($proyU['items'], 'COD_CLI')))));

$afueraSplit = DirectorioFranquicias::sumarAfuera($realU['afuera'], $proyU['afuera']);

// FRBAJA tiene cuatro comprobantes: el PAGADO no estaba en ninguna solapa
// antes de filtrar, asi que tampoco es plata que el filtro saque.
chequear('afuera quedan los tres que hubieran entrado', 3,
    $afueraSplit[DirectorioFranquicias::INHABILITADA]['FRBAJA']['cantidad']);
chequear('con su importe', 30.0, $afueraSplit[DirectorioFranquicias::INHABILITADA]['FRBAJA']['importe']);

/* ================================================================
   El registro ya declara las dos series por separado
   ================================================================ */
seccion('las dos series existen y su total declara la relacion');

chequear('serie COBRANZA_REAL existe',
    true, CashflowRegistry::serieExiste('COBRANZAS_FR', 'COBRANZA_REAL'));
chequear('serie COBRANZA_PROYECTADA existe',
    true, CashflowRegistry::serieExiste('COBRANZAS_FR', 'COBRANZA_PROYECTADA'));

$meta = CashflowRegistry::meta('COBRANZAS_FR');

chequear('COBRANZA declara a las dos como sus componentes',
    ['COBRANZA_REAL', 'COBRANZA_PROYECTADA'], $meta['componentes']['COBRANZA']);

/* ================================================================
   El validador acepta la estructura partida y rechaza la mezclada
   ================================================================ */
seccion('la estructura partida es valida contra el registro real');

$provs = [];

foreach (CashflowRegistry::todos() as $p) {
    $provs[$p['codigo']] = $p;
}

$secciones = [
    ['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
     'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]
];

function filaFr($id, $codigo, $serie, $activo) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => 'ING',
            'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'COBRANZAS_FR',
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $id * 10, 'ACTIVO' => $activo];
}

// El estado al que llega el script: la total inhabilitada y las dos hijas activas.
$r = CashflowEstructura::validar($secciones, [
    filaFr(1, 'COBRANZAS_FR',      'COBRANZA',            0),
    filaFr(2, 'COBRANZAS_FR_REAL', 'COBRANZA_REAL',       1),
    filaFr(3, 'COBRANZAS_FR_PROY', 'COBRANZA_PROYECTADA', 1)
], $provs);

chequear('con la total inhabilitada, las dos hijas conviven', true, $r['valido']);
chequear('y no reporta ningun error', 0, count($r['errores']));

// Y si alguien reactiva la total, el validador tiene que frenarlo: seria
// contar dos veces el mismo importe.
$r = CashflowEstructura::validar($secciones, [
    filaFr(1, 'COBRANZAS_FR',      'COBRANZA',            1),
    filaFr(2, 'COBRANZAS_FR_REAL', 'COBRANZA_REAL',       1),
    filaFr(3, 'COBRANZAS_FR_PROY', 'COBRANZA_PROYECTADA', 1)
], $provs);

chequear('reactivar la total junto a las hijas es invalido', false, $r['valido']);
