<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/EjeVista.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/DirectorioFranquicias.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';

// ============================================================================
// La escala de descuento es UNA SOLA y general: no depende ni del cliente ni
// del medio de pago. Antes se cargaba por cliente, con un respaldo por
// RO_T_PARAMETROS_DESC_CLIENTES cuando el cliente no tenia escala propia.
// ============================================================================

seccion('la escala general aplica los tramos por dias');

// La escala sembrada por sql/cashflow_cobranzas_escala_general.sql
$ESCALA = [
    ['dias_desde' => 0,  'dias_hasta' => 20,   'porcentaje_desc' => 8.0],
    ['dias_desde' => 21, 'dias_hasta' => 30,   'porcentaje_desc' => 6.0],
    ['dias_desde' => 31, 'dias_hasta' => 45,   'porcentaje_desc' => 4.0],
    ['dias_desde' => 46, 'dias_hasta' => 9999, 'porcentaje_desc' => 0.0]
];

chequear('0 dias: primer tramo, 8%',   8.0, Ingresos::descuentoDeEscala($ESCALA, 0));
chequear('15 dias: primer tramo, 8%',  8.0, Ingresos::descuentoDeEscala($ESCALA, 15));
chequear('20 dias: el borde entra',    8.0, Ingresos::descuentoDeEscala($ESCALA, 20));
chequear('21 dias: cambia de tramo',   6.0, Ingresos::descuentoDeEscala($ESCALA, 21));
chequear('30 dias: sigue en 6%',       6.0, Ingresos::descuentoDeEscala($ESCALA, 30));
chequear('31 dias: tercer tramo',      4.0, Ingresos::descuentoDeEscala($ESCALA, 31));
chequear('45 dias: sigue en 4%',       4.0, Ingresos::descuentoDeEscala($ESCALA, 45));
chequear('46 dias: cuarto tramo, 0%',  0.0, Ingresos::descuentoDeEscala($ESCALA, 46));
chequear('300 dias: sigue en 0%',      0.0, Ingresos::descuentoDeEscala($ESCALA, 300));

// Un plazo que no cae en ningun tramo da 0%. Es lo unico razonable, y por eso
// mismo la semilla llega hasta 9999: el cero es un tramo cargado y no un hueco.
chequear('mas alla del ultimo tramo: 0%', 0.0, Ingresos::descuentoDeEscala($ESCALA, 100000));
chequear('sin escala cargada: 0%', 0.0, Ingresos::descuentoDeEscala([], 10));

// Una fecha manual anterior a la emision da dias negativos. No tiene tramo, y
// eso es correcto: 0%.
chequear('dias negativos: 0%', 0.0, Ingresos::descuentoDeEscala($ESCALA, -5));

// ============================================================================
// El validador de la escala: solapamientos y huecos
// ============================================================================

seccion('la escala se valida antes de guardarse');

chequear('la escala sembrada es valida', [], Ingresos::validarEscala($ESCALA));

$solapada = [
    ['dias_desde' => 0,  'dias_hasta' => 25, 'porcentaje_desc' => 8.0],
    ['dias_desde' => 20, 'dias_hasta' => 30, 'porcentaje_desc' => 6.0]
];

chequear('detecta el solapamiento', 1, count(Ingresos::validarEscala($solapada)));
chequear('y dice por que importa', true,
    mb_stripos(implode(' ', Ingresos::validarEscala($solapada)), 'dos descuentos') !== false);

$conHueco = [
    ['dias_desde' => 0,  'dias_hasta' => 20, 'porcentaje_desc' => 8.0],
    ['dias_desde' => 25, 'dias_hasta' => 30, 'porcentaje_desc' => 6.0]
];

chequear('detecta el hueco', 1, count(Ingresos::validarEscala($conHueco)));
chequear('y dice que esas facturas irian con 0% sin que nadie lo decida', true,
    mb_stripos(implode(' ', Ingresos::validarEscala($conHueco)), '0%') !== false);

$noArrancaEnCero = [
    ['dias_desde' => 5, 'dias_hasta' => 20, 'porcentaje_desc' => 8.0]
];

chequear('exige que la escala arranque en 0', 1,
    count(Ingresos::validarEscala($noArrancaEnCero)));

$alReves = [
    ['dias_desde' => 0,  'dias_hasta' => 20, 'porcentaje_desc' => 8.0],
    ['dias_desde' => 30, 'dias_hasta' => 21, 'porcentaje_desc' => 6.0]
];

chequear('detecta un tramo que termina antes de empezar', true,
    mb_stripos(implode(' ', Ingresos::validarEscala($alReves)), 'antes de empezar') !== false);

$porcImposible = [
    ['dias_desde' => 0, 'dias_hasta' => 9999, 'porcentaje_desc' => 140.0]
];

chequear('rechaza un descuento mayor a 100%', true,
    mb_stripos(implode(' ', Ingresos::validarEscala($porcImposible)), 'entre 0% y 100%') !== false);

// El orden en que llegan los tramos no cambia el veredicto: el validador
// ordena antes de comparar. Si no, una escala valida cargada al reves seria
// rechazada y nadie entenderia por que.
chequear('el orden de carga no cambia el veredicto',
    [], Ingresos::validarEscala(array_reverse($ESCALA)));

// ============================================================================
// La jerarquia de fechas de cobro
// ============================================================================

seccion('la fecha manual manda sobre el PPP');

$sinManual = Ingresos::resolverFechaCobro('2026-09-01', 30);

chequear('sin fecha manual: emision + PPP', '2026-10-01', $sinManual['fecha']);
chequear('y los dias son el PPP', 30, $sinManual['dias']);
chequear('y no queda marcada como manual', false, $sinManual['manual']);

$conManual = Ingresos::resolverFechaCobro('2026-09-01', 30, '2026-09-16');

chequear('con fecha manual: manda la manual', '2026-09-16', $conManual['fecha']);
chequear('y los dias se recalculan sobre ella, no sobre el PPP', 15, $conManual['dias']);
chequear('y queda marcada como manual', true, $conManual['manual']);

// Los dias recalculados son los que eligen el tramo: es el punto de todo esto.
chequear('el PPP de 30 dias daba 6%', 6.0, Ingresos::descuentoDeEscala($ESCALA, $sinManual['dias']));
chequear('la fecha manual lo lleva a 8%', 8.0, Ingresos::descuentoDeEscala($ESCALA, $conManual['dias']));

$manualLejos = Ingresos::resolverFechaCobro('2026-09-01', 30, '2026-11-15');

chequear('una fecha manual lejana alarga el plazo', 75, $manualLejos['dias']);
chequear('y el descuento se cae a 0%', 0.0, Ingresos::descuentoDeEscala($ESCALA, $manualLejos['dias']));

// Diferencia CON SIGNO: DateTime::diff()->days siempre es positivo, y un plazo
// negativo que apareciera como positivo caeria en un tramo con descuento.
$anterior = Ingresos::resolverFechaCobro('2026-09-10', 30, '2026-09-05');

chequear('una fecha manual anterior a la emision da dias negativos', -5, $anterior['dias']);

// Un PPP de cero es un plazo de cero, no "sin dato": la factura se cobra el
// mismo dia que se emite.
$cero = Ingresos::resolverFechaCobro('2026-09-01', 0);

chequear('PPP en cero: se cobra el dia de la emision', '2026-09-01', $cero['fecha']);
chequear('y son cero dias', 0, $cero['dias']);

// ============================================================================
// No se aceptan fechas pasadas: el servidor valida de nuevo
// ============================================================================

seccion('la fecha de cobro manual se valida en el servidor');

chequear('hoy es una fecha valida', '2026-09-10',
    Ingresos::validarFechaCobroManual('2026-09-10', '2026-09-10'));

chequear('una fecha futura tambien', '2026-12-31',
    Ingresos::validarFechaCobroManual('2026-12-31', '2026-09-10'));

chequearLanza('una fecha pasada se rechaza', function () {
    Ingresos::validarFechaCobroManual('2026-09-09', '2026-09-10');
});

chequear('y el mensaje dice que la factura desapareceria del listado', true, (function () {
    try {
        Ingresos::validarFechaCobroManual('2020-01-01', '2026-09-10');
    } catch (Throwable $e) {
        return mb_stripos($e->getMessage(), 'desaparecería') !== false;
    }

    return false;
})());

chequearLanza('un texto que no es fecha se rechaza', function () {
    Ingresos::validarFechaCobroManual('en dos semanas', '2026-09-10');
});

chequearLanza('una fecha que no existe en el calendario se rechaza', function () {
    Ingresos::validarFechaCobroManual('2026-02-30', '2026-01-01');
});

chequearLanza('una fecha vacia se rechaza', function () {
    Ingresos::validarFechaCobroManual('', '2026-09-10');
});

// Un DateTime tambien entra: es lo que devuelve sqlsrv para una columna DATE.
chequear('acepta un DateTime, que es lo que devuelve sqlsrv', '2026-10-05',
    Ingresos::validarFechaCobroManual(new DateTime('2026-10-05'), '2026-09-10'));

// ============================================================================
// Las facturas vencidas entran, ubicadas en hoy
//
// Antes Cobranzas FR y Mayoristas descartaban con un `continue` todo cobro
// cuya fecha probable fuera anterior a hoy, y esa plata desaparecia de la
// pantalla sin que nada lo dijera. Ahora la regla es una sola para las tres
// pestanas de cobranza proyectada: Ingresos::ubicarCobroVencido().
// ============================================================================

seccion('una factura vencida se ubica en hoy en vez de descartarse');

$HOY = '2026-09-10';
$DIAS = Ingresos::DIAS_COBRO_VENCIDO;

chequear('el techo de dias es una constante documentada', 180, $DIAS);

$alDia = Ingresos::ubicarCobroVencido('2026-09-20', $HOY, $DIAS);

chequear('una fecha futura no se toca', '2026-09-20', $alDia['fecha']);
chequear('y no esta vencida', false, $alDia['vencida']);
chequear('y no se descarta', false, $alDia['descartar']);

// El borde: hoy mismo NO esta vencido.
$hoyMismo = Ingresos::ubicarCobroVencido($HOY, $HOY, $DIAS);

chequear('hoy no esta vencido', false, $hoyMismo['vencida']);
chequear('y se queda en hoy', $HOY, $hoyMismo['fecha']);

$ayer = Ingresos::ubicarCobroVencido('2026-09-09', $HOY, $DIAS);

chequear('la de ayer se ubica en hoy', $HOY, $ayer['fecha']);
chequear('y conserva su fecha original', '2026-09-09', $ayer['original']);
chequear('y queda marcada como vencida', true, $ayer['vencida']);
chequear('y NO se descarta: antes se perdia en silencio', false, $ayer['descartar']);

// Los dos bordes del techo. 180 dias para atras entra; 181 no.
$borde180 = Ingresos::ubicarCobroVencido('2026-03-14', $HOY, $DIAS);  // hoy - 180
$borde181 = Ingresos::ubicarCobroVencido('2026-03-13', $HOY, $DIAS);  // hoy - 181

chequear('180 dias atras entra', false, $borde180['descartar']);
chequear('y se ubica en hoy', $HOY, $borde180['fecha']);
chequear('181 dias atras queda afuera', true, $borde181['descartar']);
chequear('y sigue informando su fecha original', '2026-03-13', $borde181['original']);

// Sin techo es lo que usa Exportaciones Tasky: son pocas facturas de un solo
// cliente y todas se gestionan.
$sinTecho = Ingresos::ubicarCobroVencido('2020-01-01', $HOY, null);

chequear('sin techo, una vencida de hace anios entra igual', false, $sinTecho['descartar']);
chequear('y se ubica en hoy', $HOY, $sinTecho['fecha']);

// Con techo cero se reproduce el comportamiento viejo: todo lo anterior a hoy
// afuera. Vale como prueba de que el techo es lo unico que cambio.
chequear('con techo cero, lo de ayer se descarta', true,
    Ingresos::ubicarCobroVencido('2026-09-09', $HOY, 0)['descartar']);

// Sin fecha no hay nada que ubicar: se transporta asi y Horizonte::agrupar()
// la informa en 'sin_fecha' en vez de perderla.
$sinFecha = Ingresos::ubicarCobroVencido(null, $HOY, $DIAS);

chequear('sin fecha no se descarta', false, $sinFecha['descartar']);
chequear('y la fecha sigue siendo null, no hoy', null, $sinFecha['fecha']);

seccion('la fecha manual manda: no se reubica ni se descarta');

$manualVencida = Ingresos::ubicarCobroVencido('2026-09-01', $HOY, $DIAS, true);

chequear('una fecha pactada vencida NO se mueve a hoy', '2026-09-01', $manualVencida['fecha']);
chequear('pero se marca vencida, que es un hecho', true, $manualVencida['vencida']);
chequear('y no se descarta', false, $manualVencida['descartar']);

// Ni siquiera mas atras del techo: el techo es para la fecha automatica. Una
// fecha que alguien cargo no se descarta por antigua; si cae fuera del eje, lo
// informa EjeVista.
$manualVieja = Ingresos::ubicarCobroVencido('2024-01-01', $HOY, $DIAS, true);

chequear('una fecha pactada mas vieja que el techo tampoco se descarta',
    false, $manualVieja['descartar']);
chequear('y se respeta tal cual', '2024-01-01', $manualVieja['fecha']);

// Y la fecha automatica del mismo dia si se descarta: es lo que distingue a las
// dos.
chequear('la misma fecha, pero automatica, si se descarta', true,
    Ingresos::ubicarCobroVencido('2024-01-01', $HOY, $DIAS, false)['descartar']);

seccion('el descuento no cambia por reubicar el importe');

// La fecha manual decide el tramo por el plazo pactado, no por la columna en
// la que se dibuja. Si el descuento se recalculara sobre hoy, una vencida
// cambiaria de importe neto sola con el paso de los dias.
$plazo = Ingresos::resolverFechaCobro('2026-08-01', 25);

chequear('el plazo son 25 dias', 25, $plazo['dias']);
chequear('la fecha probable ya venció', true,
    Ingresos::ubicarCobroVencido($plazo['fecha'], $HOY, $DIAS)['vencida']);
chequear('y el descuento sigue siendo el del tramo de 25 dias', 6.0,
    Ingresos::descuentoDeEscala($ESCALA, $plazo['dias']));

seccion('los avisos de facturas vencidas');

$itemsAviso = [
    ['VENCIDA' => true,  'FECHA_MANUAL' => false, 'importe_neto' => 1000.0],
    ['VENCIDA' => true,  'FECHA_MANUAL' => false, 'importe_neto' => 500.0],
    ['VENCIDA' => true,  'FECHA_MANUAL' => true,  'importe_neto' => 2000.0],
    ['VENCIDA' => false, 'FECHA_MANUAL' => false, 'importe_neto' => 9999.0]
];

$avisos = Ingresos::avisosCobranzasVencidas($itemsAviso);

chequear('hay dos avisos: las reubicadas y las pactadas', 2, count($avisos));
chequear('el primero cuenta las reubicadas', true,
    mb_stripos($avisos[0], '2 facturas por $ 1.500,00') !== false);
chequear('y dice que no es cobranza estimada para hoy', true,
    mb_stripos($avisos[0], 'no cobranza estimada para hoy') !== false);
chequear('el segundo cuenta las pactadas', true,
    mb_stripos($avisos[1], '1 factura por $ 2.000,00') !== false);
chequear('y dice que no se reubican', true,
    mb_stripos($avisos[1], 'sin reubicar') !== false);

// Sin vencidas no hay aviso: un aviso permanente que dice "0 facturas" es
// ruido y entrena a no leerlos.
chequear('sin vencidas no hay aviso', [], Ingresos::avisosCobranzasVencidas([
    ['VENCIDA' => false, 'importe_neto' => 100.0]
]));

chequear('una lista vacia tampoco avisa', [], Ingresos::avisosCobranzasVencidas([]));

seccion('la marca del Resumen dice ALGUNA, no TODAS');

// EjeVista::marcarAlguna() repone lo que la interseccion de armarAgrupado()
// descarta. Sin esto, un cliente con una factura vencida y otra al dia perdia
// la marca y se veia igual que uno sin ninguna.
$payloadFalso = ['filas' => [
    ['COD_CLI' => 'FR001'],
    ['COD_CLI' => 'FR002'],
    ['COD_CLI' => 'FR003']
]];

$itemsGrupo = [
    ['COD_CLI' => 'FR001', 'VENCIDA' => true,  'FECHA_MANUAL' => false],
    ['COD_CLI' => 'FR001', 'VENCIDA' => false, 'FECHA_MANUAL' => false],
    ['COD_CLI' => 'FR002', 'VENCIDA' => false, 'FECHA_MANUAL' => true],
    ['COD_CLI' => 'FR003', 'VENCIDA' => false, 'FECHA_MANUAL' => false]
];

$marcado = EjeVista::marcarAlguna($payloadFalso, $itemsGrupo, 'COD_CLI',
    ['VENCIDA', 'FECHA_MANUAL']);

chequear('un cliente con una vencida entre dos queda marcado',
    true, $marcado['filas'][0]['VENCIDA']);
chequear('y no queda marcado como pactado', false, $marcado['filas'][0]['FECHA_MANUAL']);
chequear('el que tiene fecha pactada queda marcado',
    true, $marcado['filas'][1]['FECHA_MANUAL']);
chequear('y el que no tiene nada dice false explicito, no undefined',
    false, $marcado['filas'][2]['VENCIDA']);

// Un cliente que no aparece en los items tampoco puede quedar con la marca de
// otro: la clave es la que manda.
chequear('un cliente sin items queda sin marca', false,
    EjeVista::marcarAlguna(['filas' => [['COD_CLI' => 'FR999']]], $itemsGrupo,
        'COD_CLI', ['VENCIDA'])['filas'][0]['VENCIDA']);

// ============================================================================
// El Resumen abre con los clientes por su cobro mas proximo
//
// En Resumen la fila es un cliente y su columna de cobro esta oculta, asi que
// el navegador no ordena: el orden inicial lo trae el servidor. Es el mismo de
// Detalle Facturas -cada cliente donde aparece su primera factura-, con el
// codigo como desempate. Lo arma EjeVista::ordenarPorFechaMasProxima().
// ============================================================================

seccion('el Resumen abre con los clientes por su cobro mas proximo');

$agrupado = ['filas' => [
    ['COD_CLI' => 'FRAAA', 'importe_neto' => 10],
    ['COD_CLI' => 'FRSIN', 'importe_neto' => 20],
    ['COD_CLI' => 'FRCCC', 'importe_neto' => 30],
    ['COD_CLI' => 'FRBBB', 'importe_neto' => 40],
    ['COD_CLI' => 'FRPAC', 'importe_neto' => 50]
]];

$itemsCobro = [
    // FRAAA: su cobro mas proximo es el 20, aunque tenga otro el 25.
    ['COD_CLI' => 'FRAAA', 'Cobro' => '2026-10-25'],
    ['COD_CLI' => 'FRAAA', 'Cobro' => '2026-10-20'],
    // FRBBB y FRCCC empatan en el 12: desempata el codigo.
    ['COD_CLI' => 'FRCCC', 'Cobro' => '2026-10-12'],
    ['COD_CLI' => 'FRBBB', 'Cobro' => '2026-10-30'],
    ['COD_CLI' => 'FRBBB', 'Cobro' => '2026-10-12'],
    // FRPAC: una fecha pactada a mano que ya paso conserva la suya y va
    // primera, igual que en Detalle Facturas.
    ['COD_CLI' => 'FRPAC', 'Cobro' => '2026-09-30'],
    // FRSIN: sin ninguna fecha, al final.
    ['COD_CLI' => 'FRSIN', 'Cobro' => null]
];

$ordenado = EjeVista::ordenarPorFechaMasProxima($agrupado, $itemsCobro, 'COD_CLI', 'Cobro');

chequear('los clientes quedan por su fecha mas proxima, ascendente, con el codigo de desempate',
    ['FRPAC', 'FRBBB', 'FRCCC', 'FRAAA', 'FRSIN'], array_column($ordenado['filas'], 'COD_CLI'));
chequear('las filas viajan enteras: no se pierde ningun importe',
    [50, 40, 30, 10, 20], array_column($ordenado['filas'], 'importe_neto'));

// Un DateTime de sqlsrv entra igual que un string.
chequear('acepta las fechas como DateTime', ['FRB', 'FRA'], array_column(
    EjeVista::ordenarPorFechaMasProxima(['filas' => [['COD_CLI' => 'FRA'], ['COD_CLI' => 'FRB']]],
        [['COD_CLI' => 'FRA', 'Cobro' => new DateTime('2026-11-02')],
         ['COD_CLI' => 'FRB', 'Cobro' => '2026-11-01']], 'COD_CLI', 'Cobro')['filas'], 'COD_CLI'));

chequear('sin filas no rompe', [],
    EjeVista::ordenarPorFechaMasProxima(['filas' => []], [], 'COD_CLI', 'Cobro')['filas']);

// Cableado: se aplica en el Resumen -FR, sus excluidas y May pasan por la
// misma funcion- y no en Detalle Facturas, que lo ordena el navegador.
$ctrlIngresos = file_get_contents(__DIR__ . '/../Controller/IngresosController.php');

chequear('payloadCobranzas ordena el Resumen por el cobro mas proximo', 1, preg_match(
    '/\} else \{.*?EjeVista::armarAgrupado\(.*?EjeVista::ordenarPorFechaMasProxima\(\$payload, \$items, \'COD_CLI\', \'Cobro\'\);\s*\}/s',
    $ctrlIngresos));

// ============================================================================
// El filtro por fecha de emisión
//
// Es server-side a proposito: si se filtrara escondiendo filas en el DOM, las
// columnas del eje, el pie de totales y las tarjetas de indicadores seguirian
// describiendo el total sin filtrar.
// ============================================================================

seccion('el rango del filtro se valida en el servidor');

chequear('los dos extremos vacios es "sin filtro", no un error',
    ['desde' => null, 'hasta' => null],
    Ingresos::validarRangoFechaEmision('', ''));

chequear('null tambien', ['desde' => null, 'hasta' => null],
    Ingresos::validarRangoFechaEmision(null, null));

chequear('solo desde', ['desde' => '2026-01-01', 'hasta' => null],
    Ingresos::validarRangoFechaEmision('2026-01-01', ''));

chequear('solo hasta', ['desde' => null, 'hasta' => '2026-03-31'],
    Ingresos::validarRangoFechaEmision('', '2026-03-31'));

chequear('los dos extremos iguales es un dia', ['desde' => '2026-02-10', 'hasta' => '2026-02-10'],
    Ingresos::validarRangoFechaEmision('2026-02-10', '2026-02-10'));

chequearLanza('un rango al reves se rechaza', function () {
    Ingresos::validarRangoFechaEmision('2026-05-01', '2026-04-01');
});

chequear('y el mensaje dice que no puede entrar ninguna factura', true, (function () {
    try {
        Ingresos::validarRangoFechaEmision('2026-05-01', '2026-04-01');
    } catch (Throwable $e) {
        return mb_stripos($e->getMessage(), 'pueda entrar') !== false;
    }

    return false;
})());

chequearLanza('un texto que no es fecha se rechaza', function () {
    Ingresos::validarRangoFechaEmision('el mes pasado', '');
});

chequearLanza('una fecha que no existe en el calendario se rechaza', function () {
    Ingresos::validarRangoFechaEmision('', '2026-02-30');
});

// Un DateTime entra igual: es lo que devuelve sqlsrv para una columna DATE.
chequear('acepta un DateTime', '2026-07-04',
    Ingresos::validarRangoFechaEmision(new DateTime('2026-07-04'), null)['desde']);

seccion('el filtro deja pasar solo los emitidos en el rango');

$ITEMS = [
    ['N_COMP' => 'A', 'FECHA' => '2026-01-15', 'importe_neto' => 100.0],
    ['N_COMP' => 'B', 'FECHA' => '2026-02-01', 'importe_neto' => 200.0],
    ['N_COMP' => 'C', 'FECHA' => '2026-02-28', 'importe_neto' => 300.0],
    ['N_COMP' => 'D', 'FECHA' => '2026-03-10', 'importe_neto' => 400.0],
    // 'N/A' es lo que deja getCobranzasFR() cuando el comprobante no aparece
    // en GVA12: no tiene emision con la que decidir si entra.
    ['N_COMP' => 'E', 'FECHA' => 'N/A', 'importe_neto' => 500.0]
];

$sinFiltro = Ingresos::filtrarPorFechaEmision($ITEMS, ['desde' => null, 'hasta' => null]);

chequear('sin filtro no se toca nada, ni el de fecha "N/A"', 5, count($sinFiltro['items']));
chequear('y no hay nada que informar', 0, $sinFiltro['sin_fecha']);

$febrero = Ingresos::filtrarPorFechaEmision($ITEMS,
    ['desde' => '2026-02-01', 'hasta' => '2026-02-28']);

chequear('febrero deja dos', 2, count($febrero['items']));
chequear('el primero es el del 1/2', 'B', $febrero['items'][0]['N_COMP']);
chequear('los bordes entran: el del 28/2 tambien', 'C', $febrero['items'][1]['N_COMP']);

// El que no se puede ubicar en el rango queda afuera, pero CONTADO: descartarlo
// en silencio seria perder plata sin decirlo.
chequear('el de emision desconocida queda afuera', 1, $febrero['sin_fecha']);

$avisos = Ingresos::avisosFiltroFechaEmision(['desde' => '2026-02-01', 'hasta' => '2026-02-28'], 1);

chequear('y se avisa', 1, count($avisos));
chequear('el aviso dice cuantos son', true,
    mb_stripos($avisos[0], '1 comprobante sin fecha de emisión') !== false);
chequear('y como verlos', true, mb_stripos($avisos[0], 'Quitá el filtro') !== false);
chequear('sin ninguno, no hay aviso', [],
    Ingresos::avisosFiltroFechaEmision(['desde' => '2026-02-01', 'hasta' => null], 0));

// Un extremo suelto acota de un solo lado.
chequear('solo desde: deja los tres posteriores', 3,
    count(Ingresos::filtrarPorFechaEmision($ITEMS, ['desde' => '2026-02-01', 'hasta' => null])['items']));
chequear('solo hasta: deja los dos anteriores', 2,
    count(Ingresos::filtrarPorFechaEmision($ITEMS, ['desde' => null, 'hasta' => '2026-02-01'])['items']));

// Un rango que no contiene nada devuelve una lista vacia, no todo.
chequear('un rango vacio de facturas deja cero filas', 0,
    count(Ingresos::filtrarPorFechaEmision($ITEMS, ['desde' => '2027-01-01', 'hasta' => '2027-12-31'])['items']));

// ============================================================================
// El PPP es por grupo empresario
// ============================================================================

seccion('el PPP efectivo: manual del grupo, calculado del grupo, DIAS_PP_MAX, 30');

// La regla vive UNA vez (Ingresos::pppEfectivo). Antes estaba escrita tres
// veces y podian desalinearse.
chequear('el manual manda', 45, Ingresos::pppEfectivo(45, 20, 15));
chequear('sin manual, el calculado', 20, Ingresos::pppEfectivo(null, 20, 15));
chequear('un manual en cero es "sin manual"', 20, Ingresos::pppEfectivo(0, 20, 15));
chequear('sin calculado, el DIAS_PP_MAX del cliente', 15, Ingresos::pppEfectivo(null, 0, 15));
chequear('sin nada, 30', 30, Ingresos::pppEfectivo(null, 0, 0));
chequear('los vacios de la base cuentan como nada', 30, Ingresos::pppEfectivo('', '', null));
chequear('acepta strings de sqlsrv', 45, Ingresos::pppEfectivo('45', '20', '15'));
chequear('un manual negativo no manda', 20, Ingresos::pppEfectivo(-5, 20, 15));
chequear('el respaldo es la constante', 30, Ingresos::PPP_DEFECTO);

seccion('el agrupador es el grupo empresario o el propio cliente');

chequear('sin grupo, el cliente', 'FR001', Ingresos::codAgrupador('FR001', ''));
chequear('con grupo null tambien', 'FR001', Ingresos::codAgrupador('FR001', null));
chequear('con grupo, el grupo en mayusculas y sin espacios', 'GR1',
    Ingresos::codAgrupador('fr001', ' gr1 '));
chequear('un grupo de solo espacios es sin grupo', 'FR001', Ingresos::codAgrupador(' fr001 ', '   '));

seccion('el PPP del grupo se reparte a todos sus clientes');

// Cuatro clientes: dos del grupo GR1, uno sin grupo, uno del grupo GR2 que no
// tiene recibos en la ventana.
$clientesPPP = [
    ['cod_cliente' => 'FR001', 'razon_social' => 'LOCAL UNO', 'grupo_empr' => 'GR1', 'nombre_gru' => 'GRUPO UNO'],
    ['cod_cliente' => 'FR002', 'razon_social' => 'LOCAL DOS', 'grupo_empr' => 'GR1', 'nombre_gru' => 'GRUPO UNO'],
    ['cod_cliente' => 'FR003', 'razon_social' => 'SOLITARIO SRL', 'grupo_empr' => '', 'nombre_gru' => ''],
    ['cod_cliente' => 'FR004', 'razon_social' => 'LOCAL CUATRO', 'grupo_empr' => 'GR2', 'nombre_gru' => 'GRUPO DOS']
];

$vistaPPP = [
    'GR1' => ['ppp' => 25, 'cant_recibos' => 3, 'cant_clientes' => 2, 'nombre_agrup' => 'GRUPO UNO (VISTA)', 'es_grupo' => true],
    'FR003' => ['ppp' => 40, 'cant_recibos' => 5, 'cant_clientes' => 1, 'nombre_agrup' => 'SOLITARIO SRL', 'es_grupo' => false]
];

$manualesPPP = ['GR2' => 50];

$paramsPPP = [
    'FR004' => ['dias_pp_max' => 12, 'medio_pago' => 'ECHEQ', 'desc_pp_max' => 0],
    'FR002' => ['dias_pp_max' => 99, 'medio_pago' => 'ECHEQ', 'desc_pp_max' => 0]
];

$ppps = Ingresos::armarPPPPorCliente($clientesPPP, $vistaPPP, $manualesPPP, $paramsPPP);

chequear('hay una entrada por cliente', 4, count($ppps));
chequear('los dos del grupo comparten el PPP del grupo', 25, $ppps['FR001']['ppp_efectivo']);
chequear('aunque uno tenga DIAS_PP_MAX propio', 25, $ppps['FR002']['ppp_efectivo']);
chequear('y saben de que grupo salio', 'GR1', $ppps['FR002']['cod_agrup']);
chequear('con el nombre que trae la vista', 'GRUPO UNO (VISTA)', $ppps['FR001']['nombre_agrup']);
chequear('y cuantos recibos lo respaldan', 3, $ppps['FR001']['cant_recibos']);

chequear('el cliente sin grupo es su propio agrupador', 'FR003', $ppps['FR003']['cod_agrup']);
chequear('no es grupo', false, $ppps['FR003']['es_grupo']);
chequear('y usa su propio calculado', 40, $ppps['FR003']['ppp_efectivo']);

chequear('un grupo sin recibos queda con calculado cero', 0, $ppps['FR004']['ppp_calculado']);
chequear('pero manda su manual', 50, $ppps['FR004']['ppp_efectivo']);
chequear('y el nombre sale de GVA62', 'GRUPO DOS', $ppps['FR004']['nombre_agrup']);

// Sin manual, el grupo sin recibos cae al respaldo del cliente; sin params, a 30.
$sinManual = Ingresos::armarPPPPorCliente($clientesPPP, $vistaPPP, [], $paramsPPP);
chequear('sin manual ni calculado, el DIAS_PP_MAX del cliente', 12, $sinManual['FR004']['ppp_efectivo']);

$sinNada = Ingresos::armarPPPPorCliente($clientesPPP, $vistaPPP, [], []);
chequear('sin nada, 30', 30, $sinNada['FR004']['ppp_efectivo']);
chequear('un manual en cero de la tabla es "sin manual"', 25,
    Ingresos::armarPPPPorCliente($clientesPPP, $vistaPPP, ['GR1' => 0], [])['FR001']['ppp_efectivo']);

seccion('la tarjeta lista solo franquicias con sucursal habilitada');

// El directorio: dos sucursales del mismo cliente, una fila sin cliente. Es el
// mismo caso que probaba Parametros::filtrarFranquiciasActivas(), que pasó a
// ser DirectorioFranquicias::filtrarUniverso(): la tarjeta y la proyección
// usan ahora la misma regla.
$direccionario = [
    ['NRO_SUCURSAL' => 804, 'COD_CLIENT' => 'FR001', 'DESC_SUCURSAL' => 'BAHIA BLANCA - CENTRO', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 805, 'COD_CLIENT' => 'FR001', 'DESC_SUCURSAL' => 'BAHIA BLANCA SHOPPING', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 810, 'COD_CLIENT' => 'fr003 ', 'DESC_SUCURSAL' => 'LINIERS', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 811, 'COD_CLIENT' => '', 'DESC_SUCURSAL' => 'SIN CLIENTE', 'HABILITADO' => 1]
];

$mapa = DirectorioFranquicias::mapaSucursales($direccionario);

chequear('un cliente por entrada, sin la fila sin cliente', 2, count($mapa));
chequear('dos sucursales se concatenan', '804, 805', $mapa['FR001']['nro_sucursal']);
chequear('con sus descripciones', 'BAHIA BLANCA - CENTRO / BAHIA BLANCA SHOPPING', $mapa['FR001']['desc_sucursal']);
chequear('y se cuentan', 2, $mapa['FR001']['cant_sucursales']);
chequear('el codigo se normaliza', 'LINIERS', $mapa['FR003']['desc_sucursal']);

$dir = DirectorioFranquicias::armar($direccionario);
$filtrado = DirectorioFranquicias::filtrarUniverso($ppps, $dir, 'cod_cliente');
$conSuc = Parametros::conSucursal($filtrado['items'], $dir);

chequear('quedan solo los que estan en el directorio', 2, count($filtrado['items']));
chequear('con su sucursal colgada', '804, 805', $conSuc['FR001']['nro_sucursal']);
chequear('el mapa por cliente sigue siendo un mapa', ['FR001', 'FR003'], array_keys($filtrado['items']));
chequear('los demas se cuentan afuera, sin directorio', 2,
    DirectorioFranquicias::clientesAfuera($filtrado['afuera'])[DirectorioFranquicias::SIN_DIRECTORIO]);
chequear('sin aviso de caida: las bajas son a proposito', 0, count($filtrado['avisos']));

// Informar de mas antes que vacio: sin directorio se muestran todos y se avisa.
$sinDir = DirectorioFranquicias::filtrarUniverso($ppps, null, 'cod_cliente');
chequear('sin directorio se muestran todos', 4, count($sinDir['items']));
chequear('con un aviso que nombra al servidor', true,
    strpos($sinDir['avisos'][0]['texto'], 'locales') !== false);
chequear('y el aviso es una ATENCION', Aviso::WARNING, $sinDir['avisos'][0]['nivel']);
chequear('sin nada contado afuera', [], $sinDir['afuera']);
chequear('y sin sucursal', '', Parametros::conSucursal($sinDir['items'], null)['FR001']['nro_sucursal']);

$dirVacio = DirectorioFranquicias::filtrarUniverso($ppps, DirectorioFranquicias::armar([]), 'cod_cliente');
chequear('un directorio vacio tambien muestra todos', 4, count($dirVacio['items']));
chequear('y avisa', 1, count($dirVacio['avisos']));

// Un directorio que solo trae bajas es una consulta rota, no un hecho: si se
// le hiciera caso, la tarjeta y la cobranza quedarian en cero.
$soloBajas = DirectorioFranquicias::armar([
    ['COD_CLIENT' => 'FR001', 'HABILITADO' => 0], ['COD_CLIENT' => 'FR002', 'HABILITADO' => 0]
]);
chequear('un directorio sin ninguna habilitada no se usa para filtrar', 4,
    count(DirectorioFranquicias::filtrarUniverso($ppps, $soloBajas, 'cod_cliente')['items']));

// ============================================================================
// El universo de Cobranzas Franquicias: [FL]% habilitadas en el directorio
// ============================================================================

seccion('el estado de cada cliente en el directorio');

$dirU = DirectorioFranquicias::armar([
    ['NRO_SUCURSAL' => 1, 'COD_CLIENT' => 'FRHAB', 'DESC_SUCURSAL' => 'HABILITADA', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 2, 'COD_CLIENT' => 'LALOMA', 'DESC_SUCURSAL' => 'LOMAS (GA)', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 3, 'COD_CLIENT' => 'FRBAJA', 'DESC_SUCURSAL' => 'DADA DE BAJA', 'HABILITADO' => 0],
    ['NRO_SUCURSAL' => 4, 'COD_CLIENT' => 'FRNULO', 'DESC_SUCURSAL' => 'SIN ESTADO', 'HABILITADO' => null],
    // Una habilitada y una de baja: el cliente opera
    ['NRO_SUCURSAL' => 5, 'COD_CLIENT' => 'FRMIXTO', 'DESC_SUCURSAL' => 'ABIERTA', 'HABILITADO' => 1],
    ['NRO_SUCURSAL' => 6, 'COD_CLIENT' => 'FRMIXTO', 'DESC_SUCURSAL' => 'CERRADA', 'HABILITADO' => 0],
    // Una de baja y una sin estado: manda la baja, que es un hecho
    ['NRO_SUCURSAL' => 7, 'COD_CLIENT' => 'FRBAJANULO', 'DESC_SUCURSAL' => 'X', 'HABILITADO' => 0],
    ['NRO_SUCURSAL' => 8, 'COD_CLIENT' => 'FRBAJANULO', 'DESC_SUCURSAL' => 'Y', 'HABILITADO' => null],
    // HABILITADO viene como string desde la base
    ['NRO_SUCURSAL' => 9, 'COD_CLIENT' => 'FRSTR', 'DESC_SUCURSAL' => 'Z', 'HABILITADO' => '1']
]);

chequear('habilitada', DirectorioFranquicias::HABILITADA, DirectorioFranquicias::clasificar('FRHAB', $dirU));
chequear('una L de gestion asistida habilitada entra', DirectorioFranquicias::HABILITADA,
    DirectorioFranquicias::clasificar('laloma', $dirU));
chequear('dada de baja: inhabilitada', DirectorioFranquicias::INHABILITADA,
    DirectorioFranquicias::clasificar('FRBAJA', $dirU));
chequear('HABILITADO en NULL: estado sin cargar, no inhabilitada', DirectorioFranquicias::SIN_ESTADO,
    DirectorioFranquicias::clasificar('FRNULO', $dirU));
chequear('con una sucursal habilitada, el cliente esta habilitado', DirectorioFranquicias::HABILITADA,
    DirectorioFranquicias::clasificar('FRMIXTO', $dirU));
chequear('pero la tarjeta muestra solo la sucursal habilitada', 'ABIERTA',
    $dirU['sucursales']['FRMIXTO']['desc_sucursal']);
chequear('una baja y un NULL: manda la baja', DirectorioFranquicias::INHABILITADA,
    DirectorioFranquicias::clasificar('FRBAJANULO', $dirU));
chequear('un 1 como string es habilitada', DirectorioFranquicias::HABILITADA,
    DirectorioFranquicias::clasificar('FRSTR', $dirU));
chequear('un cliente que no esta: sin directorio', DirectorioFranquicias::SIN_DIRECTORIO,
    DirectorioFranquicias::clasificar('FRNUEVO', $dirU));

seccion('el universo filtra facturas y cuenta lo que deja afuera');

$facturasU = [
    ['COD_CLI' => 'FRHAB', 'importe_neto' => 100.0],
    ['COD_CLI' => 'LALOMA', 'importe_neto' => 50.0],
    ['COD_CLI' => 'FRBAJA', 'importe_neto' => 70.0],
    ['COD_CLI' => 'FRBAJA', 'importe_neto' => 30.0],
    ['COD_CLI' => 'FRNULO', 'importe_neto' => 20.0],
    ['COD_CLI' => 'FRNUEVO', 'importe_neto' => 5.0]
];

$u = DirectorioFranquicias::filtrarUniverso($facturasU, $dirU, 'COD_CLI', 'importe_neto');

chequear('quedan las habilitadas, F y L', ['FRHAB', 'LALOMA'], array_column($u['items'], 'COD_CLI'));
chequear('una lista sigue siendo lista', [0, 1], array_keys($u['items']));
chequear('la inhabilitada queda afuera con sus dos facturas', 2,
    $u['afuera'][DirectorioFranquicias::INHABILITADA]['FRBAJA']['cantidad']);
chequear('y su importe', 100.0, $u['afuera'][DirectorioFranquicias::INHABILITADA]['FRBAJA']['importe']);
chequear('la sin estado, en su propio caso', 20.0,
    $u['afuera'][DirectorioFranquicias::SIN_ESTADO]['FRNULO']['importe']);
chequear('la que no esta en el directorio, en el suyo', 5.0,
    $u['afuera'][DirectorioFranquicias::SIN_DIRECTORIO]['FRNUEVO']['importe']);
chequear('lo que queda mas lo de afuera es todo', 275.0,
    array_sum(array_column($u['items'], 'importe_neto')) + 100.0 + 20.0 + 5.0);

// Los totales de la cobranza real llegan por fecha y cliente: una fila puede
// ser varios comprobantes.
$agrupadasU = DirectorioFranquicias::filtrarUniverso(
    [['COD_CLI' => 'FRBAJA', 'IMPORTE' => 80.0, 'CANT' => 3]], $dirU, 'COD_CLI', 'IMPORTE', 'CANT');
chequear('una fila agrupada cuenta sus comprobantes', 3,
    $agrupadasU['afuera'][DirectorioFranquicias::INHABILITADA]['FRBAJA']['cantidad']);

$sumado = DirectorioFranquicias::sumarAfuera($u['afuera'], $agrupadasU['afuera']);
chequear('real y proyectado se suman por cliente', 5,
    $sumado[DirectorioFranquicias::INHABILITADA]['FRBAJA']['cantidad']);
chequear('con su importe', 180.0, $sumado[DirectorioFranquicias::INHABILITADA]['FRBAJA']['importe']);

// Directorio caido: todas, con aviso. La cobranza no queda en cero.
$caido = DirectorioFranquicias::filtrarUniverso($facturasU, null, 'COD_CLI', 'importe_neto');
chequear('directorio caido: entran todas', 6, count($caido['items']));
chequear('con un aviso de atencion', Aviso::WARNING, $caido['avisos'][0]['nivel']);
chequear('que dice que puede mostrar de mas', true,
    strpos($caido['avisos'][0]['texto'], 'de más') !== false);
chequear('y nada afuera, que no se avisaria', [], DirectorioFranquicias::avisosAfuera($caido['afuera']));

seccion('los avisos de lo que queda afuera distinguen los tres casos');

$avU = DirectorioFranquicias::avisosAfuera($sumado);

chequear('un aviso por caso', 3, count($avU));
chequear('todos informativos: es la regla, no un problema', [Aviso::INFO, Aviso::INFO, Aviso::INFO],
    array_column($avU, 'nivel'));
chequear('el de inhabilitadas dice cuantas facturas y cuanta plata', true,
    strpos($avU[0]['texto'], '5 facturas por $ 180,00') !== false);
chequear('y nombra al cliente con su importe', true,
    strpos($avU[0]['texto'], 'FRBAJA ($ 180,00)') !== false);
chequear('el de estado sin cargar lo dice y sugiere un error de carga', true,
    strpos($avU[1]['texto'], 'estado sin cargar') !== false
        && strpos($avU[1]['texto'], 'error de carga') !== false);
chequear('y nombra a FRNULO', true, strpos($avU[1]['texto'], 'FRNULO ($ 20,00)') !== false);
chequear('el de sin directorio dice que falta darlos de alta', true,
    strpos($avU[2]['texto'], 'Falta darlos de alta') !== false);
chequear('en singular con una sola factura', true,
    strpos($avU[2]['texto'], '1 factura por $ 5,00') !== false);

// Los clientes van del de mayor importe al de menor, y despues de
// MAX_NOMBRADOS se resumen: un aviso con cuarenta nombres no se lee.
$muchos = [];
for ($i = 1; $i <= DirectorioFranquicias::MAX_NOMBRADOS + 2; $i++) {
    $muchos[DirectorioFranquicias::INHABILITADA]['FR' . $i] = ['cantidad' => 1, 'importe' => (float) $i];
}
$avMuchos = DirectorioFranquicias::avisosAfuera($muchos)[0]['texto'];
$mayor = 'FR' . (DirectorioFranquicias::MAX_NOMBRADOS + 2) . ' (';
$segundo = 'FR' . (DirectorioFranquicias::MAX_NOMBRADOS + 1) . ' (';
chequear('el de mayor importe va primero', true,
    strpos($avMuchos, $mayor) !== false && strpos($avMuchos, $mayor) < strpos($avMuchos, $segundo));
chequear('los de menor importe son los que se resumen', false, strpos($avMuchos, 'FR1 (') !== false);
chequear('y el resto se resume', true, strpos($avMuchos, 'y 2 más') !== false);
chequear('sin nada afuera, ningun aviso', [], DirectorioFranquicias::avisosAfuera([]));

seccion('el universo es [FL]% en todas las lecturas de franquicias');

// El patron vive en una constante y las dos consultas de Tango de Ingresos lo
// usan: si una quedara en 'FR%', las L entrarian a la tarjeta y no a la
// proyeccion, o al reves.
$ingresosSrc = file_get_contents(__DIR__ . '/../Class/Ingresos.php');
chequear('el patron es [FL]%', '[FL]%', DirectorioFranquicias::PATRON_SQL);
chequear('Ingresos ya no tiene ningun FR% en una consulta', 0,
    preg_match_all("/LIKE 'FR%'/", $ingresosSrc));
chequear('los clientes del PPP y las facturas usan la constante', 2,
    substr_count($ingresosSrc, "LIKE '\" . DirectorioFranquicias::PATRON_SQL . \"'"));

$vistaSql = file_get_contents(__DIR__ . '/../sql/cashflow_cobranzas_ppp_grupo.sql');
chequear('la vista del PPP tambien', 1, substr_count($vistaSql, "AND V.COD_CLIENTE LIKE '[FL]%'"));
chequear('y su semilla', 1, substr_count($vistaSql, "AND G.COD_CLIENT LIKE '[FL]%'"));
chequear('y ninguna quedo en FR%', 0, substr_count($vistaSql, "LIKE 'FR%'"));

chequear('los canales son una lista explicita', ['FRANQUICIAS', 'FRANQUICIAS GA'],
    DirectorioFranquicias::CANALES);

// La cobranza real, la proyeccion y los totales del tablero filtran con la
// misma funcion: si uno se salteara el filtro, el tablero contaria plata que
// la pestana no muestra.
chequear('la proyeccion filtra el universo', true,
    strpos($ingresosSrc, "\$this->filtrarUniversoFR(\$itemsProyectados, 'COD_CLI', 'importe_neto')") !== false);
chequear('la cobranza real de la pestana tambien', true,
    strpos($ingresosSrc, "\$this->filtrarUniversoFR(\$data, 'COD_CLI', 'importe_neto')") !== false);
chequear('y la de los totales del tablero', true,
    strpos($ingresosSrc, "\$this->filtrarUniversoFR(\$filasReal, 'COD_CLI', 'IMPORTE', 'CANT')") !== false);
chequear('IngresosProvider deja los avisos del universo', true,
    strpos(file_get_contents(__DIR__ . '/../Class/Providers/IngresosProvider.php'),
        'avisarTodos($ingresos->avisosUniversoFR())') !== false);

seccion('la tarjeta agrupa una fila por agrupador');

foreach ($ppps as $cod => $c) {
    $ppps[$cod]['medio_pago_default'] = ($cod === 'FR002') ? 'TRANSFERENCIA' : 'ECHEQ';
    $ppps[$cod]['desc_pp_max'] = 0;
}

$grupos = Parametros::agruparPorAgrupador($ppps);

chequear('tres agrupadores', 3, count($grupos));
chequear('ordenados por nombre', ['GRUPO DOS', 'GRUPO UNO (VISTA)', 'SOLITARIO SRL'],
    array_column($grupos, 'nombre_agrup'));
chequear('el grupo uno tiene dos clientes', 2, count($grupos[1]['clientes']));
chequear('ordenados por codigo', ['FR001', 'FR002'], array_column($grupos[1]['clientes'], 'cod_cliente'));
chequear('el efectivo del grupo con manual es el manual', 50, $grupos[0]['ppp_efectivo']);
chequear('el efectivo del grupo con calculado es el calculado', 25, $grupos[1]['ppp_efectivo']);
chequear('cada cliente conserva su medio de pago', 'TRANSFERENCIA',
    $grupos[1]['clientes'][1]['medio_pago_default']);
chequear('y su efectivo', 25, $grupos[1]['clientes'][1]['ppp_efectivo']);

// El efectivo del grupo no usa DIAS_PP_MAX (es por cliente): un grupo sin
// manual ni calculado queda en 30 aunque su cliente tenga respaldo propio.
$gruposSinManual = Parametros::agruparPorAgrupador($sinManual);
$gr2 = array_values(array_filter($gruposSinManual, function ($g) { return $g['cod_agrup'] === 'GR2'; }))[0];
chequear('el grupo sin PPP queda en 30', 30, $gr2['ppp_efectivo']);
chequear('pero su cliente usa su DIAS_PP_MAX', 12, $gr2['clientes'][0]['ppp_efectivo']);

// ============================================================================
// Parámetros y Registro de Cashflow
// ============================================================================

seccion('registro de modulos y series de cobranzas');

$modulos = Parametros::getModulos();
$codigos = array_column($modulos, 'codigo');

chequear('Parametros::getModulos incluye COBRANZAS', true, in_array('COBRANZAS', $codigos));
chequear('Parametros::getModulos incluye VENTAS', true, in_array('VENTAS', $codigos));
chequear('Parametros::getModulos incluye SALDOS', true, in_array('SALDOS', $codigos));
chequear('Parametros::getModulos incluye CASHFLOW', true, in_array('CASHFLOW', $codigos));

$meta = CashflowRegistry::meta('COBRANZAS_FR');
chequear('COBRANZAS_FR esta registrado', true, $meta !== null);
chequear('serie COBRANZA existe', true, CashflowRegistry::serieExiste('COBRANZAS_FR', 'COBRANZA'));
chequear('serie COBRANZA_PROYECTADA existe', true, CashflowRegistry::serieExiste('COBRANZAS_FR', 'COBRANZA_PROYECTADA'));
chequear('serie COBRANZA_TOTAL existe', true, CashflowRegistry::serieExiste('COBRANZAS_FR', 'COBRANZA_TOTAL'));
chequear('COBRANZA_TOTAL declara sus componentes', true, isset($meta['componentes']['COBRANZA_TOTAL']));
