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
