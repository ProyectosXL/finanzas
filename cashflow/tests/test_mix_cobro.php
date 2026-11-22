<?php
/**
 * La regla del arbol del mix de cobro (Class/MixCobro.php): que es una hoja,
 * su % efectivo, su costo y su tasa acumulados, sus dias, y lo que hace
 * invalido a un arbol.
 *
 * Es la regla que comparten el motor de Ventas, la pestana Ventas, Parametros
 * y el tablero, asi que todo lo de aca vale para los cuatro. Sin base: los
 * nodos se arman a mano.
 */

require_once __DIR__ . '/../Class/MixCobro.php';

/** Una fila cruda de RO_T_CASHFLOW_VENTAS_MIX_NODO */
function nodoMC($id, $canal, $padre, $nivel, $nombre, $porc, $costo = null, $tasa = null,
                $dias = null, $activo = 1, $orden = 0) {
    return ['ID' => $id, 'CANAL' => $canal, 'ID_PADRE' => $padre, 'NIVEL' => $nivel,
            'NOMBRE' => $nombre, 'PORCENTAJE' => $porc, 'COSTO' => $costo, 'TASA' => $tasa,
            'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo, 'ORDEN' => $orden ?: $id];
}

/**
 * El arbol de ejemplo de la especificacion, completo y valido. Fiserv lleva
 * 7 dias y Resto ninguno, para ver la herencia.
 */
function arbolMC($cambios = []) {
    $filas = [
        nodoMC(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 0.10, 0.031, null, 1),
        nodoMC(2, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 0.90),
        nodoMC(3, 'LOCALES', 2, 'TIPO_TARJETA', 'Débito', 0.20, 0.0318, null, 7),
        nodoMC(4, 'LOCALES', 2, 'TIPO_TARJETA', 'Crédito', 0.80),
        nodoMC(5, 'LOCALES', 4, 'PROCESADORA', 'Payway', 0.10, 0.049, null, 1),
        nodoMC(6, 'LOCALES', 4, 'PROCESADORA', 'Mercado Pago', 0.10, null, null, 18),
        nodoMC(7, 'LOCALES', 4, 'PROCESADORA', 'Fiserv', 0.60, 0.049, null, 7),
        nodoMC(8, 'LOCALES', 7, 'CUOTAS', '3 cuotas', 0.30, null, 0.009, 1),
        nodoMC(9, 'LOCALES', 7, 'CUOTAS', 'Resto', 0.70),
        nodoMC(10, 'LOCALES', 4, 'PROCESADORA', 'Promo Bancarias', 0.20, 0.049, null, 15),
        nodoMC(11, 'LOCALES', null, 'MEDIO_PAGO', 'Go Cuotas', 0.50, null, null, 10, 0),
        nodoMC(12, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Transferencia', 0.03, null, null, 30),
        nodoMC(13, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 0.97, null, null, 40),
        nodoMC(14, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 60),
        nodoMC(20, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 0.70),
        nodoMC(21, 'ECOMMERCE', 20, 'MEDIO_PAGO', 'Tarjeta', 1.0, null, null, 2),
        nodoMC(22, 'ECOMMERCE', null, 'MARKETPLACE', 'Mercado Libre', 0.30, 0.14),
        nodoMC(23, 'ECOMMERCE', 22, 'MEDIO_PAGO', 'Mercado Pago', 1.0, 0.0, null, 18)
    ];

    foreach ($filas as $i => $f) {
        if (isset($cambios[$f['ID']])) {
            $filas[$i] = array_merge($f, $cambios[$f['ID']]);
        }
    }

    return MixCobro::normalizar($filas);
}

/** Los errores de un tipo */
function erroresMC($r, $tipo) {
    return array_values(array_filter($r['errores'], function ($e) use ($tipo) {
        return isset($e['tipo']) && $e['tipo'] === $tipo;
    }));
}

/** Los textos de validarEstructura() */
function estructuraMC($nodos) {
    return array_column(MixCobro::validarEstructura($nodos), 'texto');
}

$r = MixCobro::resolver(arbolMC());

seccion('el arbol de ejemplo es valido');

chequear('sin errores de estructura', [], estructuraMC(arbolMC()));
chequear('sin errores de la regla', [], $r['errores']);
chequear('valido', true, $r['valido']);

seccion('el % efectivo es el producto de la cadena');

chequear('3 cuotas: 90% x 80% x 60% x 30% = 12,96%', 0.1296, $r['nodos']['N8']['porcentaje_efectivo']);
chequear('Credito, un nodo con hijos: 72%', 0.72, $r['nodos']['N4']['porcentaje_efectivo']);
chequear('Mercado Pago de Mercado Libre: 30% x 100%', 0.30, $r['nodos']['N23']['porcentaje_efectivo']);

$sumaHojas = [];

foreach ($r['canales'] as $canal => $c) {
    $sumaHojas[$canal] = round(array_sum(array_map(function ($k) use ($r) {
        return $r['nodos'][$k]['porcentaje_efectivo'];
    }, $c['hojas'])), 6);
}

chequear('las hojas de cada canal suman la venta entera',
    ['LOCALES' => 1.0, 'FRANQUICIAS' => 1.0, 'MAYORISTAS' => 1.0, 'ECOMMERCE' => 1.0], $sumaHojas);

seccion('costo y tasa se suman a lo largo de la rama, null como cero');

chequear('3 cuotas: el costo de Fiserv', 0.049, $r['nodos']['N8']['costo']);
chequear('3 cuotas: su propia tasa', 0.009, $r['nodos']['N8']['tasa']);
chequear('3 cuotas: costo + tasa = 5,80%', 0.058, $r['nodos']['N8']['carga']);
chequear('Resto: hereda el costo de Fiserv y no tiene tasa', [0.049, 0.0],
    [$r['nodos']['N9']['costo'], $r['nodos']['N9']['tasa']]);
chequear('Mercado Pago (procesadora) sin costo en ninguna rama: cero, no null', 0.0,
    $r['nodos']['N6']['carga']);
chequear('la comision del marketplace la paga lo que cuelga de el', 0.14,
    $r['nodos']['N23']['carga']);
chequear('Vtex sin comision: Tarjeta de Vtex sin costo', 0.0, $r['nodos']['N21']['carga']);
chequear('el costo propio sigue a la vista, null incluido', [null, 0.009],
    [$r['nodos']['N8']['costo_propio'], $r['nodos']['N8']['tasa_propia']]);

seccion('dias: gana el nivel mas cercano que los tenga');

chequear('Resto hereda los 7 dias de Fiserv', 7, $r['nodos']['N9']['dias']);
chequear('y dice de quien los hereda', 'Fiserv', $r['nodos']['N9']['dias_de']['nombre']);
chequear('3 cuotas manda el suyo: 1 dia, no 7 ni 8', 1, $r['nodos']['N8']['dias']);
chequear('y son suyos', 'N8', $r['nodos']['N8']['dias_de']['clave']);
chequear('Tarjeta de Vtex: los suyos, Vtex no tiene', 2, $r['nodos']['N21']['dias']);
chequear('un nodo sin dias ni arriba: null, no cero', null, $r['nodos']['N2']['dias']);

seccion('hojas: activo y sin hijos en juego');

chequear('las hojas de Locales', ['N1', 'N3', 'N5', 'N6', 'N8', 'N9', 'N10'],
    $r['canales']['LOCALES']['hojas']);
chequear('un nodo con hijos no es hoja', false, $r['nodos']['N7']['hoja']);
chequear('un nodo inactivo no es hoja ni esta en juego', [false, false],
    [$r['nodos']['N11']['hoja'], $r['nodos']['N11']['en_juego']]);

$sinCuotas = MixCobro::resolver(arbolMC([8 => ['ACTIVO' => 0], 9 => ['ACTIVO' => 0]]));

chequear('inhabilitar 3 cuotas y Resto convierte a Fiserv en hoja', true, $sinCuotas['nodos']['N7']['hoja']);
chequear('con sus propios 7 dias', 7, $sinCuotas['nodos']['N7']['dias']);
chequear('y el arbol sigue valido: un grupo sin activos no es un grupo invalido', [],
    $sinCuotas['errores']);

$sinFiserv = MixCobro::resolver(arbolMC([7 => ['ACTIVO' => 0]]));

chequear('un nodo inactivo saca de juego todo su subarbol', [false, false, false],
    [$sinFiserv['nodos']['N7']['en_juego'], $sinFiserv['nodos']['N8']['en_juego'],
     $sinFiserv['nodos']['N9']['en_juego']]);
chequear('sus hojas salen de la proyeccion', ['N1', 'N3', 'N5', 'N6', 'N10'],
    $sinFiserv['canales']['LOCALES']['hojas']);

seccion('los hermanos en juego suman 100%; los inactivos no suman');

chequear('Go Cuotas inactivo con 50% no rompe Locales', true, $r['canales']['LOCALES']['valido']);

$sumaMal = erroresMC($sinFiserv, 'SUMA');

chequear('inhabilitar Fiserv deja a Credito sumando 40%: un error', 1, count($sumaMal));
chequear('dice que rama', 'Locales › Tarjeta › Crédito', $sumaMal[0]['rama']);
chequear('y la suma', 0.4, $sumaMal[0]['suma']);
chequear('lo que queda afuera es el 60% de Credito: 43,2% de la venta del canal', 0.432,
    $sumaMal[0]['afuera']);
chequear('el grupo de Fiserv, fuera de juego, no se valida', true,
    array_values(array_filter($sinFiserv['canales']['LOCALES']['grupos'], function ($g) {
        return $g['padre'] === 'N7';
    }))[0]['valido']);

$primerNivel = MixCobro::resolver(arbolMC([1 => ['PORCENTAJE' => 0.2]]));
$e = erroresMC($primerNivel, 'SUMA');

chequear('tambien en el primer nivel: Locales suma 110%', ['Locales', 1.1],
    [$e[0]['rama'], round($e[0]['suma'], 6)]);
chequear('lo que sobra sale negativo: se cobraria mas de lo que se vende', -0.1, $e[0]['afuera']);

seccion('una hoja sin dias en toda su rama es invalida');

$sinDias = MixCobro::resolver(arbolMC([9 => ['DIAS_ACREDITACION' => null], 7 => ['DIAS_ACREDITACION' => null]]));
$e = erroresMC($sinDias, 'SIN_DIAS');

chequear('Resto sin dias y Fiserv tampoco: un error', 1, count($e));
chequear('dice que rama', 'Locales › Tarjeta › Crédito › Fiserv › Resto', $e[0]['rama']);
chequear('y que parte de la venta queda afuera', 0.3024, $e[0]['afuera']);
chequear('los dias de la hoja quedan null, nunca 0', null, $sinDias['nodos']['N9']['dias']);

$fiservHoja = MixCobro::resolver(arbolMC([
    7 => ['DIAS_ACREDITACION' => null], 8 => ['ACTIVO' => 0], 9 => ['ACTIVO' => 0]]));

chequear('Fiserv hoja sin dias tampoco pasa', ['Locales › Tarjeta › Crédito › Fiserv'],
    array_column(erroresMC($fiservHoja, 'SIN_DIAS'), 'rama'));

seccion('costo + tasa de una hoja menor a 100%');

$cara = MixCobro::resolver(arbolMC([7 => ['COSTO' => 0.5], 8 => ['TASA' => 0.5]]));

chequear('Fiserv 50% + 3 cuotas 50% = 100%: invalido', ['Locales › Tarjeta › Crédito › Fiserv › 3 cuotas'],
    array_column(erroresMC($cara, 'CARGA'), 'rama'));

seccion('un canal sin hojas en juego es invalido');

$sinMay = MixCobro::resolver(arbolMC([14 => ['ACTIVO' => 0]]));
$e = erroresMC($sinMay, 'SIN_HOJAS');

chequear('Mayoristas sin medios activos', ['MAYORISTAS'], array_column($e, 'canal'));
chequear('su venta entera queda afuera', 1.0, $e[0]['afuera']);
chequear('un canal que no tiene ningun nodo, tambien', 'SIN_HOJAS', erroresMC(MixCobro::resolver(
    array_values(array_filter(arbolMC(), function ($n) { return $n['CANAL'] !== 'FRANQUICIAS'; }))
), 'SIN_HOJAS')[0]['tipo']);

seccion('la carga de una rama: promedio ponderado por % efectivo');

$hojasCredito = ['N5', 'N6', 'N8', 'N9', 'N10'];
$num = 0;
$den = 0;

foreach ($hojasCredito as $k) {
    $num += $r['nodos'][$k]['porcentaje_efectivo'] * $r['nodos'][$k]['carga'];
    $den += $r['nodos'][$k]['porcentaje_efectivo'];
}

chequear('Credito', round($num / $den, 8), round($r['nodos']['N4']['carga_ponderada'], 8));
chequear('una hoja, su propia carga', 0.058, $r['nodos']['N8']['carga_ponderada']);
chequear('un nodo fuera de juego no tiene', null, $sinFiserv['nodos']['N7']['carga_ponderada']);

seccion('el orden de salida es el de dibujo: padre antes que hijos');

chequear('Locales', ['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7', 'N8', 'N9', 'N10', 'N11'],
    array_values(array_filter(array_keys($r['nodos']), function ($k) use ($r) {
        return $r['nodos'][$k]['canal'] === 'LOCALES';
    })));
chequear('el camino de 3 cuotas', ['Tarjeta', 'Crédito', 'Fiserv', '3 cuotas'],
    array_column($r['nodos']['N8']['camino'], 'nombre'));
chequear('con el rotulo de cada nivel', 'Procesadora', $r['nodos']['N8']['camino'][2]['rotulo']);
chequear('profundidad', [0, 3], [$r['nodos']['N1']['profundidad'], $r['nodos']['N8']['profundidad']]);

seccion('estructura: el nivel de un hijo es posterior al de su padre');

$mal = function ($cambios) { return estructuraMC(arbolMC($cambios)); };

chequear('hijo con el mismo nivel que el padre: rechazado', 1,
    count($mal([3 => ['NIVEL' => 'MEDIO_PAGO']])));
chequear('hijo con un nivel anterior: rechazado', 1,
    count($mal([8 => ['NIVEL' => 'TIPO_TARJETA']])));
chequear('se pueden saltear niveles: Mercado Pago (medio) > Cuotas', [],
    estructuraMC(array_merge(arbolMC(), MixCobro::normalizar([
        nodoMC(24, 'ECOMMERCE', 23, 'CUOTAS', '6 cuotas', 0.0, null, 0.02, null, 0)]))));

seccion('estructura: marketplaces solo en Ecommerce, y todo Ecommerce cuelga de uno');

chequear('MARKETPLACE en Locales: rechazado', 1, count($mal([1 => ['NIVEL' => 'MARKETPLACE']])));
// Dos errores: el de Mercado Libre, y el de su hijo Mercado Pago, que es
// medio de pago y ahora cuelga de otro medio de pago.
chequear('primer nivel de Ecommerce que no es MARKETPLACE: rechazado', true,
    strpos(implode(' ', $mal([22 => ['NIVEL' => 'MEDIO_PAGO']])),
        'En Ecommerce todo cuelga de un marketplace: "Mercado Libre"') !== false);
chequear('primer nivel de Locales que no es Medio de pago: rechazado', 1,
    count($mal([1 => ['NIVEL' => 'TIPO_TARJETA']])));
chequear('un marketplace debajo de otro: rechazado', 1, count($mal([21 => ['NIVEL' => 'MARKETPLACE']])));
chequear('el mensaje dice por que', true, strpos(implode(' ', $mal([1 => ['NIVEL' => 'MARKETPLACE']])),
    'sólo existen en Ecommerce') !== false);

seccion('estructura: nombre unico entre hermanos, repetible en otra rama');

chequear('dos hermanos con el mismo nombre: rechazado', 1, count($mal([4 => ['NOMBRE' => 'Débito']])));
chequear('sin distinguir mayusculas ni acentos, como la base', 1,
    count($mal([4 => ['NOMBRE' => 'DEBITO']])));
chequear('Mercado Pago como procesadora y como medio de Mercado Libre: aceptado', [],
    estructuraMC(arbolMC()));
chequear('el mismo nombre en el primer nivel de otro canal: aceptado', [],
    estructuraMC(arbolMC([14 => ['NOMBRE' => 'Transferencia']])));
chequear('dos primeros niveles del mismo canal con el mismo nombre: rechazado', 1,
    count($mal([2 => ['NOMBRE' => 'Efectivo']])));

seccion('estructura: rangos');

chequear('porcentaje de mas de 100%', 1, count($mal([1 => ['PORCENTAJE' => 1.5]])));
chequear('costo negativo', 1, count($mal([1 => ['COSTO' => -0.01]])));
chequear('tasa de mas de 100%', 1, count($mal([8 => ['TASA' => 1.2]])));
chequear('dias negativos', 1, count($mal([1 => ['DIAS_ACREDITACION' => -1]])));
chequear('un padre de otro canal', 1, count($mal([21 => ['ID_PADRE' => 2]])));
chequear('un padre que no existe', 1, count($mal([21 => ['ID_PADRE' => 999]])));
chequear('un ciclo', true, count($mal([2 => ['ID_PADRE' => 4], 4 => ['ID_PADRE' => 2]])) > 0);
chequear('un canal que no existe', 1, count($mal([14 => ['CANAL' => 'OTRO']])));

seccion('niveles permitidos para agregar');

chequear('primer nivel de Ecommerce', ['MARKETPLACE'], MixCobro::nivelesPermitidos('ECOMMERCE', null));
chequear('primer nivel de Locales', ['MEDIO_PAGO'], MixCobro::nivelesPermitidos('LOCALES', null));
chequear('debajo de un marketplace, sin otro marketplace', ['MEDIO_PAGO', 'TIPO_TARJETA', 'PROCESADORA', 'CUOTAS'],
    MixCobro::nivelesPermitidos('ECOMMERCE', 'MARKETPLACE'));
chequear('debajo de una procesadora', ['CUOTAS'], MixCobro::nivelesPermitidos('LOCALES', 'PROCESADORA'));
chequear('debajo de cuotas, nada', [], MixCobro::nivelesPermitidos('LOCALES', 'CUOTAS'));

seccion('el mix plano como arbol de un nivel');

$plano = MixCobro::desdeMixPlano([
    ['ID' => 1, 'CANAL' => 'LOCALES', 'MEDIO_PAGO' => 'CASH', 'PORCENTAJE' => 0.1, 'DIAS_ACREDITACION' => 1, 'ACTIVO' => 1, 'ORDEN' => 1],
    ['ID' => 2, 'CANAL' => 'LOCALES', 'MEDIO_PAGO' => 'TARJETA', 'PORCENTAJE' => 0.9, 'DIAS_ACREDITACION' => 2, 'ACTIVO' => 1, 'ORDEN' => 2],
    ['ID' => 4, 'CANAL' => 'FRANQUICIAS', 'MEDIO_PAGO' => 'ECHEQ', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 40, 'ACTIVO' => 1, 'ORDEN' => 4],
    ['ID' => 7, 'CANAL' => 'MAYORISTAS', 'MEDIO_PAGO' => 'ECHEQ', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 60, 'ACTIVO' => 1, 'ORDEN' => 7],
    ['ID' => 8, 'CANAL' => 'ECOMMERCE', 'MEDIO_PAGO' => 'TARJETA', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 2, 'ACTIVO' => 1, 'ORDEN' => 8]
]);
$rp = MixCobro::resolver($plano);

chequear('la clave es M<id>', 'M2', $plano[1]['clave']);
chequear('cada fila es un medio de pago del primer nivel, sin costo ni tasa',
    ['MEDIO_PAGO', null, null, null], [$plano[1]['NIVEL'], $plano[1]['ID_PADRE'], $plano[1]['COSTO'], $plano[1]['TASA']]);
chequear('el nombre como estaba guardado', 'CASH', $plano[0]['NOMBRE']);
chequear('se resuelve con la misma regla: hojas con su porcentaje y sus dias', [0.9, 2, 0.0],
    [$rp['nodos']['M2']['porcentaje_efectivo'], $rp['nodos']['M2']['dias'], $rp['nodos']['M2']['carga']]);
chequear('la regla de estructura no se aplica al mix viejo: Ecommerce sin marketplace es como era',
    true, $rp['valido']);

seccion('guardado: solo cambia lo que cambio');

$actuales = arbolMC();
$todo = array_map(function ($n) {
    return ['id' => $n['ID'], 'nombre' => $n['NOMBRE'], 'nivel' => $n['NIVEL'],
            'porcentaje' => $n['PORCENTAJE'], 'costo' => $n['COSTO'], 'tasa' => $n['TASA'],
            'dias' => $n['DIAS_ACREDITACION'], 'activo' => $n['ACTIVO']];
}, array_values(array_filter($actuales, function ($n) { return $n['CANAL'] === 'LOCALES'; })));

chequear('el arbol entero sin tocar: ningun cambio', [],
    MixCobro::resolverCambios($actuales, 'LOCALES', $todo)['cambios']);

$editado = $todo;
$editado[6]['dias'] = 5;      // Fiserv
$editado[8]['costo'] = '';    // Resto: vacio, sigue null

$rc = MixCobro::resolverCambios($actuales, 'LOCALES', $editado);

chequear('un campo de un nodo: un cambio, con ese campo solo',
    [['id' => 7, 'campos' => ['DIAS_ACREDITACION' => 5]]], $rc['cambios']);
chequear('el simulado lleva el cambio', 5, array_values(array_filter($rc['simulado'], function ($n) {
    return $n['ID'] === 7;
}))[0]['DIAS_ACREDITACION']);

$editado = $todo;
$editado[8]['costo'] = 0;

chequear('un costo vacio que pasa a cero SI es un cambio',
    [['id' => 9, 'campos' => ['COSTO' => 0.0]]],
    MixCobro::resolverCambios($actuales, 'LOCALES', $editado)['cambios']);

chequear('renombrar se permite', [['id' => 9, 'campos' => ['NOMBRE' => 'Resto de cuotas']]],
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 9, 'nombre' => '  Resto   de cuotas ']])['cambios']);

chequearLanza('cambiar el nivel de un nodo con hijos: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 7, 'nivel' => 'CUOTAS']]);
}, 'No se puede cambiar el nivel de "Fiserv" porque tiene nodos debajo. Si quedó mal, inhabilitalo y creá otro.');
chequear('el de un nodo sin hijos, si', [['id' => 10, 'campos' => ['NIVEL' => 'CUOTAS']]],
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 10, 'nivel' => 'cuotas']])['cambios']);
chequearLanza('un nodo de otro canal: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 14, 'porcentaje' => 1]]);
});
chequearLanza('un nodo que no existe: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 999, 'porcentaje' => 1]]);
});
chequearLanza('un costo de mas de 100%: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 7, 'costo' => 1.5]]);
});
chequearLanza('dias con decimales: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 7, 'dias' => 2.5]]);
});
chequearLanza('un nombre vacio: rechazado', function () use ($actuales) {
    MixCobro::resolverCambios($actuales, 'LOCALES', [['id' => 7, 'nombre' => '  ']]);
});

seccion('alta: entra inhabilitado y en 0%');

$nuevo = MixCobro::nodoNuevo($actuales, ['canal' => 'LOCALES', 'id_padre' => 7, 'nombre' => '6 cuotas',
    'nivel' => 'CUOTAS', 'porcentaje' => 0.5, 'costo' => '', 'tasa' => 0.012, 'dias' => '']);

chequear('inhabilitado y en 0%, aunque se mande un porcentaje', [0, 0.0],
    [$nuevo['ACTIVO'], $nuevo['PORCENTAJE']]);
chequear('con su costo, su tasa y sus dias', [null, 0.012, null],
    [$nuevo['COSTO'], $nuevo['TASA'], $nuevo['DIAS_ACREDITACION']]);
chequear('colgado de su padre', [7, 'LOCALES'], [$nuevo['ID_PADRE'], $nuevo['CANAL']]);

$conNuevo = MixCobro::resolver(array_merge($actuales, MixCobro::normalizar([array_merge($nuevo, ['ID' => 30])])));

chequear('y no rompe el 100% de su grupo', [], $conNuevo['errores']);

chequearLanza('un nivel que no corresponde: rechazado', function () use ($actuales) {
    MixCobro::nodoNuevo($actuales, ['canal' => 'LOCALES', 'id_padre' => 7, 'nombre' => 'X', 'nivel' => 'TIPO_TARJETA']);
});
chequearLanza('un nombre repetido entre hermanos: rechazado', function () use ($actuales) {
    MixCobro::nodoNuevo($actuales, ['canal' => 'LOCALES', 'id_padre' => 7, 'nombre' => 'resto', 'nivel' => 'CUOTAS']);
});
chequearLanza('un marketplace en Locales: rechazado', function () use ($actuales) {
    MixCobro::nodoNuevo($actuales, ['canal' => 'LOCALES', 'nombre' => 'Vtex', 'nivel' => 'MARKETPLACE']);
});
chequearLanza('colgado de un nodo de otro canal: rechazado', function () use ($actuales) {
    MixCobro::nodoNuevo($actuales, ['canal' => 'LOCALES', 'id_padre' => 22, 'nombre' => 'X', 'nivel' => 'MEDIO_PAGO']);
});

seccion('la lista de niveles y el CHECK del script cambian juntas');

$scriptMC = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../sql/cashflow_ventas_mix_nodo.sql'));

chequear('mismos niveles, mismo orden', 1, substr_count($scriptMC,
    "CHECK (NIVEL IN ('" . implode("', '", array_keys(MixCobro::NIVELES)) . "'))"));
chequear('el largo del nombre es el de la columna', 1, substr_count($scriptMC,
    'NOMBRE            VARCHAR(' . MixCobro::LARGO_NOMBRE . ')'));

seccion('la lista de niveles viaja a la pantalla en orden');

// Una lista y no un mapa: la leyenda de colores los muestra en este orden.
chequear('nivelesLista() es NIVELES, en su orden y con sus rotulos',
    array_map(null, array_keys(MixCobro::NIVELES), array_values(MixCobro::NIVELES)),
    array_map(function ($n) { return [$n['codigo'], $n['rotulo']]; }, MixCobro::nivelesLista()));
chequear('y es la que recibe el editor de Parametros', MixCobro::nivelesLista(),
    MixCobro::datosEditor(arbolMC(), 'NODO')['niveles']);

// ============================================================================
// EL CORTE DE FIN DE SEMANA: se hereda como los dias
// ============================================================================

seccion('corte de fin de semana: se hereda como los dias');

$sinCorte = MixCobro::resolver(arbolMC());

chequear('sin ningun corte cargado, todos los nodos en No aplica y sin origen', [['NO_APLICA'], [null]],
    [array_values(array_unique(array_column($sinCorte['nodos'], 'corte'))),
     array_values(array_unique(array_map(function ($n) { return $n['corte_de']; }, $sinCorte['nodos']), SORT_REGULAR))]);

$conCorte = MixCobro::resolver(arbolMC([7 => ['CORTE_FINDE' => 'VIERNES']]));

chequear('Fiserv con corte propio', ['VIERNES', 'VIERNES', 'Fiserv'],
    [$conCorte['nodos']['N7']['corte_propio'], $conCorte['nodos']['N7']['corte'],
     $conCorte['nodos']['N7']['corte_de']['nombre']]);
chequear('sus cuotas sin corte lo heredan de Fiserv', [['VIERNES', 'Fiserv', null], ['VIERNES', 'Fiserv', null]],
    [[$conCorte['nodos']['N8']['corte'], $conCorte['nodos']['N8']['corte_de']['nombre'], $conCorte['nodos']['N8']['corte_propio']],
     [$conCorte['nodos']['N9']['corte'], $conCorte['nodos']['N9']['corte_de']['nombre'], $conCorte['nodos']['N9']['corte_propio']]]);
chequear('los hermanos de Fiserv no: el corte baja, no se contagia', ['NO_APLICA', 'NO_APLICA', null],
    [$conCorte['nodos']['N5']['corte'], $conCorte['nodos']['N10']['corte'], $conCorte['nodos']['N5']['corte_de']]);
chequear('ni sube: Credito sigue sin corte', 'NO_APLICA', $conCorte['nodos']['N4']['corte']);

$cuotaPropia = MixCobro::resolver(arbolMC([7 => ['CORTE_FINDE' => 'VIERNES'], 8 => ['CORTE_FINDE' => 'NO_APLICA']]));

chequear('una cuota con No aplica propio no lo hereda: corta la herencia', ['NO_APLICA', '3 cuotas'],
    [$cuotaPropia['nodos']['N8']['corte'], $cuotaPropia['nodos']['N8']['corte_de']['nombre']]);
chequear('y su hermana sigue heredando', 'VIERNES', $cuotaPropia['nodos']['N9']['corte']);

$masCerca = MixCobro::resolver(arbolMC([4 => ['CORTE_FINDE' => 'VIERNES'], 7 => ['CORTE_FINDE' => 'SABADO']]));

chequear('gana el nivel mas cercano: Credito desde el viernes, Fiserv desde el sabado', ['SABADO', 'Fiserv', 'VIERNES'],
    [$masCerca['nodos']['N8']['corte'], $masCerca['nodos']['N8']['corte_de']['nombre'], $masCerca['nodos']['N6']['corte']]);
chequear('el corte no cambia la validez del arbol ni sus errores', [true, []],
    [$conCorte['valido'], $conCorte['errores']]);
chequear('ni el % efectivo ni los dias de nadie', [array_column($sinCorte['nodos'], 'porcentaje_efectivo'),
    array_column($sinCorte['nodos'], 'dias')],
    [array_column($conCorte['nodos'], 'porcentaje_efectivo'), array_column($conCorte['nodos'], 'dias')]);

seccion('corte de fin de semana: lo que llega de la base');

chequear('la columna se normaliza a mayusculas y vacio es null', ['VIERNES', null, null],
    [arbolMC([7 => ['CORTE_FINDE' => ' viernes ']])[6]['CORTE_FINDE'],
     arbolMC([7 => ['CORTE_FINDE' => '']])[6]['CORTE_FINDE'],
     arbolMC()[6]['CORTE_FINDE']]);
chequear('sin la columna (falta el script) es null: nadie tiene corte', null,
    MixCobro::normalizar([nodoMC(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 1.0, null, null, 1)])[0]['CORTE_FINDE']);

$corteRaro = estructuraMC(arbolMC([7 => ['CORTE_FINDE' => 'DOMINGO']]));

chequear('un valor que no existe se conserva y la estructura lo dice', true,
    count($corteRaro) === 1 && strpos($corteRaro[0], 'corte de fin de semana de "Fiserv"') !== false);

seccion('corte de fin de semana: validacion en el servidor');

chequear('los tres valores y vacio', ['NO_APLICA', 'VIERNES', 'SABADO', null, null],
    [MixCobro::validarCorte('NO_APLICA', 'x'), MixCobro::validarCorte('viernes', 'x'),
     MixCobro::validarCorte('SABADO', 'x'), MixCobro::validarCorte('', 'x'), MixCobro::validarCorte(null, 'x')]);
chequearLanza('un valor fuera de la lista: rechazado, diciendo cuales valen', function () {
    MixCobro::validarCorte('DOMINGO', '"Fiserv"');
}, 'El corte de fin de semana de "Fiserv" tiene que ser No aplica, Desde el viernes, Desde el sábado o vacío, no "DOMINGO".');
chequearLanza('y en el guardado del canal', function () {
    MixCobro::resolverCambios(arbolMC(), 'LOCALES', [['id' => 7, 'corte' => 'LUNES']]);
});
$pvCorte = MixCobro::previsualizar(arbolMC(), 'LOCALES', [['id' => 7, 'corte' => 'LUNES']]);
chequear('previsualizar lo devuelve como error, no como excepcion', [false, 1],
    [$pvCorte['valido'], count($pvCorte['errores'])]);

seccion('corte de fin de semana: el guardado escribe solo si cambia');

$rcCorte = MixCobro::resolverCambios(arbolMC(), 'LOCALES', [['id' => 7, 'corte' => 'VIERNES'],
                                                           ['id' => 8, 'corte' => '']]);

chequear('Fiserv cambia; "3 cuotas" vacio sigue vacio y no se escribe',
    [['id' => 7, 'campos' => ['CORTE_FINDE' => 'VIERNES']]], $rcCorte['cambios']);
chequear('volver a vacio es un cambio: null es heredar', [['id' => 7, 'campos' => ['CORTE_FINDE' => null]]],
    MixCobro::resolverCambios(arbolMC([7 => ['CORTE_FINDE' => 'VIERNES']]), 'LOCALES',
        [['id' => 7, 'corte' => '']])['cambios']);
chequear('sin el campo, no se toca', [],
    MixCobro::resolverCambios(arbolMC([7 => ['CORTE_FINDE' => 'VIERNES']]), 'LOCALES',
        [['id' => 7, 'dias' => '7']])['cambios']);
chequear('la previsualizacion ya hereda el corte nuevo', 'VIERNES',
    MixCobro::previsualizar(arbolMC(), 'LOCALES', [['id' => 7, 'corte' => 'VIERNES']])['nodos']['N9']['corte']);
chequear('un nodo nuevo entra sin corte (hereda): el alta no lo escribe', false,
    array_key_exists('CORTE_FINDE', MixCobro::nodoNuevo(arbolMC(), ['canal' => 'LOCALES', 'id_padre' => 7,
        'nombre' => '6 cuotas', 'nivel' => 'CUOTAS'])));

seccion('corte de fin de semana: la lista viaja a la pantalla');

chequear('cortesLista() es CORTES, en su orden', array_keys(MixCobro::CORTES),
    array_column(MixCobro::cortesLista(), 'codigo'));
chequear('con sus rotulos', 'Desde el viernes', MixCobro::rotuloCorte('VIERNES'));
$edCorte = MixCobro::datosEditor(arbolMC(), 'NODO', null, true);
chequear('el editor la recibe, con la columna y el script', [MixCobro::cortesLista(), true, MixCobro::SCRIPT_CORTE],
    [$edCorte['cortes'], $edCorte['corte'], $edCorte['script_corte']]);
chequear('sin la columna, el editor lo sabe', false, MixCobro::datosEditor(arbolMC(), 'NODO')['corte']);
chequear('con el mix plano tampoco: no hay arbol donde cargarlo', false,
    MixCobro::datosEditor(arbolMC(), 'PLANO', null, true)['corte']);

// ============================================================================
// LA FECHA DE ACREDITACION: MixCobro::fechaAcreditacion()
// ============================================================================

/* Calendario de lunes a viernes, de noviembre a enero, con dos feriados
   bancarios: el lunes 30/11 y el viernes 04/12. La semana del 16/11 es
   normal: viernes 20, sabado 21, domingo 22, lunes 23, martes 24. */
$habilesFA = [];
$cursorFA = new DateTime('2026-11-01');

while ($cursorFA->format('Y-m-d') <= '2027-01-31') {
    $habilesFA[$cursorFA->format('Y-m-d')] = intval($cursorFA->format('N')) <= 5;
    $cursorFA->modify('+1 day');
}

$habilesFA['2026-11-30'] = false;
$habilesFA['2026-12-04'] = false;

/** La fecha con el calendario de prueba */
function fechaFA($venta, $dias, $corte) {
    global $habilesFA;

    return MixCobro::fechaAcreditacion($venta, $dias, $corte, $habilesFA)['fecha'];
}

seccion('fecha de acreditacion: Fiserv a 1 dia, desde el viernes');

chequear('viernes, sabado y domingo -> martes', ['2026-11-24', '2026-11-24', '2026-11-24'],
    [fechaFA('2026-11-20', 1, 'VIERNES'), fechaFA('2026-11-21', 1, 'VIERNES'), fechaFA('2026-11-22', 1, 'VIERNES')]);
chequear('desde la base: el lunes', ['2026-11-23', true],
    [MixCobro::fechaAcreditacion('2026-11-21', 1, 'VIERNES', $habilesFA)['base'],
     MixCobro::fechaAcreditacion('2026-11-21', 1, 'VIERNES', $habilesFA)['en_corte']]);
chequear('el jueves -> viernes: no cambia', ['2026-11-20', false, null],
    array_values(MixCobro::fechaAcreditacion('2026-11-19', 1, 'VIERNES', $habilesFA)));
chequear('el lunes y el martes, tampoco', ['2026-11-24', '2026-11-25'],
    [fechaFA('2026-11-23', 1, 'VIERNES'), fechaFA('2026-11-24', 1, 'VIERNES')]);
chequear('con el lunes feriado -> base martes -> miercoles', ['2026-12-02', '2026-12-02', '2026-12-02', '2026-12-01'],
    [fechaFA('2026-11-27', 1, 'VIERNES'), fechaFA('2026-11-28', 1, 'VIERNES'), fechaFA('2026-11-29', 1, 'VIERNES'),
     MixCobro::fechaAcreditacion('2026-11-29', 1, 'VIERNES', $habilesFA)['base']]);
chequear('con 0 dias -> el lunes', ['2026-11-23', '2026-11-23', '2026-11-23'],
    [fechaFA('2026-11-20', 0, 'VIERNES'), fechaFA('2026-11-21', 0, 'VIERNES'), fechaFA('2026-11-22', 0, 'VIERNES')]);
chequear('con 7 dias, el viernes se cuenta desde el lunes (+7: el lunes siguiente, o el martes si es feriado)', ['2026-11-23', '2026-12-01'],
    [fechaFA('2026-11-13', 7, 'VIERNES'), fechaFA('2026-11-20', 7, 'VIERNES')]);
chequear('un viernes feriado sigue siendo viernes para el corte', ['2026-12-08', true],
    [fechaFA('2026-12-04', 1, 'VIERNES'), MixCobro::ventaEnCorte('2026-12-04', 'VIERNES')]);
chequear('el mismo viernes feriado sin corte: sabado, corrido al lunes', '2026-12-07', fechaFA('2026-12-04', 1, null));

seccion('fecha de acreditacion: desde el sabado');

chequear('el viernes, como hoy: sabado corrido al lunes', ['2026-11-23', false],
    [fechaFA('2026-11-20', 1, 'SABADO'), MixCobro::fechaAcreditacion('2026-11-20', 1, 'SABADO', $habilesFA)['en_corte']]);
chequear('sabado y domingo -> martes', ['2026-11-24', '2026-11-24'],
    [fechaFA('2026-11-21', 1, 'SABADO'), fechaFA('2026-11-22', 1, 'SABADO')]);

seccion('fecha de acreditacion: sin corte, al ultimo bit como antes');

// La cuenta de antes: venta + dias, corrida con DiasHabiles::siguiente().
$distintos = 0;
$probados = 0;
$cursorFA = new DateTime('2026-11-01');

while ($cursorFA->format('Y-m-d') <= '2026-12-20') {
    $venta = $cursorFA->format('Y-m-d');

    foreach ([0, 1, 2, 7, 18, 25] as $d) {
        $antes = DiasHabiles::siguiente(date('Y-m-d', strtotime($venta . ' +' . $d . ' days')), $habilesFA)['fecha'];

        foreach ([null, 'NO_APLICA'] as $corte) {
            $r = MixCobro::fechaAcreditacion($venta, $d, $corte, $habilesFA);
            $distintos += ($r['fecha'] !== $antes || $r['en_corte'] || $r['base'] !== null) ? 1 : 0;
            $probados++;
        }
    }

    $cursorFA->modify('+1 day');
}

chequear('No aplica y vacio: la fecha de antes en ' . $probados . ' combinaciones de dia y plazo', 0, $distintos);
chequear('un valor desconocido no corta: tampoco entra en el corte', false, MixCobro::ventaEnCorte('2026-11-21', 'DOMINGO'));

seccion('fecha de acreditacion: el calendario entra como mapa o como funcion');

$llamadasFA = [];
$correrFA = function ($fecha) use ($habilesFA, &$llamadasFA) {
    $llamadasFA[] = $fecha;

    return DiasHabiles::siguiente($fecha, $habilesFA)['fecha'];
};

chequear('la funcion de corrimiento da lo mismo que el mapa', fechaFA('2026-11-28', 1, 'VIERNES'),
    MixCobro::fechaAcreditacion('2026-11-28', 1, 'VIERNES', $correrFA)['fecha']);
chequear('y se le pide la base y la fecha teorica: el motor avisa por ahi si falta un mes',
    ['2026-11-30', '2026-12-02'], $llamadasFA);
