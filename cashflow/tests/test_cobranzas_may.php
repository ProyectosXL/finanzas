<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/Horizonte.php';

// ============================================================================
// Parámetros y Registro de Cashflow
// ============================================================================

seccion('registro y configuracion de cobranzas mayoristas');

$meta = CashflowRegistry::meta('COBRANZAS_MAY');
chequear('COBRANZAS_MAY esta registrado', true, $meta !== null);
chequear('COBRANZAS_MAY esta disponible', true, CashflowRegistry::disponible('COBRANZAS_MAY'));
chequear('serie COBRANZA existe en COBRANZAS_MAY', true, CashflowRegistry::serieExiste('COBRANZAS_MAY', 'COBRANZA'));
chequear('la pestana asociada es cobranzas_may', 'cobranzas_may', $meta['tab']);

// ============================================================================
// Parámetro de días de plazo de mayoristas
// ============================================================================

seccion('plazo de vencimiento mayorista');

$ingresos = new Ingresos();
$diasPlazo = $ingresos->getDiasPlazoMayoristas();
chequear('getDiasPlazoMayoristas devuelve un entero mayor a 0', true, is_int($diasPlazo) && $diasPlazo > 0);

// ============================================================================
// Proyección y cálculo de fecha de cobro
// ============================================================================

seccion('calculo de fecha probable de cobro');

$fechaEmision = new DateTime('2026-01-15');
$plazoTest = 60;
$fechaEsperada = clone $fechaEmision;
$fechaEsperada->modify("+{$plazoTest} days");

chequear('fecha de emision + 60 dias suma correctamente', '2026-03-16', $fechaEsperada->format('Y-m-d'));

// ============================================================================
// EL PENDIENTE PUEDE VOLVER NEGATIVO, Y ESO NO SE DESCARTA EN SILENCIO
//
// La consulta cruza los vencimientos (GVA46) con las imputaciones (GVA07):
// una factura sobre-imputada -se le imputo mas de lo que decia- vuelve con
// pendiente negativo aunque siga en ESTADO = 'PEN'. No se muestra ni entra
// al eje -un saldo negativo no es plata a cobrar- pero deja UN aviso
// agregado, porque el desvio hay que revisarlo.
//
// El texto va aparte y estatico justamente para poder probarlo: tener
// facturas sobre-imputadas cargadas es lo que no se le puede pedir a una
// tabla de Tango.
// ============================================================================

seccion('el aviso de pendientes negativos');

chequear('sin facturas negativas no hay aviso', [], Ingresos::avisoSinSaldo(0, 0));
chequear('un conteo negativo tampoco inventa uno', [], Ingresos::avisoSinSaldo(-1, -500));

$unaSola = Ingresos::avisoSinSaldo(1, -1500.50);

chequear('con una factura hay exactamente UN aviso', 1, count($unaSola));
chequear('y esta en singular', true, strpos($unaSola[0], '1 factura de mayoristas vuelve') === 0);

// El importe va en VALOR ABSOLUTO aunque llegue negativo: la palabra
// "NEGATIVO" ya esta en la frase, y "$ -1.500,50 negativo" se lee dos veces
// al reves.
chequear('el importe se muestra en positivo', true,
    strpos($unaSola[0], '1.500,50') !== false);
chequear('sin el signo menos delante', false,
    strpos($unaSola[0], '-1.500,50') !== false);

// UN SOLO AVISO AGREGADO, no uno por comprobante: uno por fila taparia el
// resto de la barra de avisos.
$varias = Ingresos::avisoSinSaldo(7, -90000);

chequear('con siete facturas sigue habiendo UN solo aviso', 1, count($varias));
chequear('y esta en plural', true, strpos($varias[0], '7 facturas de mayoristas vuelven') === 0);
chequear('con el importe total', true, strpos($varias[0], '90.000,00') !== false);

// Lo que el aviso tiene que dejar claro es que esa plata NO esta en el
// cuadro: si no, se lee como un dato de color.
chequear('dice que no entran al cashflow', true,
    strpos($varias[0], 'No se muestran ni entran al cashflow') !== false);

// ============================================================================
// EL VENDEDOR DEL CLIENTE Y SU FILTRO, sin base
// ============================================================================

seccion('el vendedor de cada cliente');

chequear('cliente con vendedor: el nombre de Tango tal cual',
    ['codigo' => 'Z3', 'nombre' => 'VALERIA VILLAREAL'],
    Ingresos::vendedorDeCliente('Z3  ', ' VALERIA VILLAREAL'));
chequear('codigo vacio: Tango lo guarda asi, y es "Sin vendedor"',
    ['codigo' => null, 'nombre' => 'Sin vendedor'], Ingresos::vendedorDeCliente('  ', null));
chequear('codigo NULL, tambien', ['codigo' => null, 'nombre' => 'Sin vendedor'],
    Ingresos::vendedorDeCliente(null, null));
chequear('un codigo que no esta en GVA23 no se esconde: se muestra el codigo',
    ['codigo' => 'Z9', 'nombre' => 'Vendedor Z9 (sin nombre en Tango)'],
    Ingresos::vendedorDeCliente('Z9', null));

seccion('el filtro por vendedor');

chequear('vacio es sin filtro', null, Ingresos::validarFiltroVendedor(''));
chequear('null tambien', null, Ingresos::validarFiltroVendedor(null));
chequear('"Sin vendedor" tiene su valor', 'SIN', Ingresos::validarFiltroVendedor('SIN'));
chequear('un codigo, sin espacios', 'Z3', Ingresos::validarFiltroVendedor(' Z3 '));
chequearLanza('un valor que no es un codigo se rechaza en el servidor', function () {
    Ingresos::validarFiltroVendedor("Z3' OR 1=1 --");
}, 'El filtro por vendedor no es un código de vendedor válido.');
chequearLanza('ni uno mas largo que la columna', function () {
    Ingresos::validarFiltroVendedor('ABCDEFGHIJK');
});

/** Una factura de mayoristas, solo con lo que mira el filtro */
function facturaVendMay($comp, $cliente, $codVend, $nombre) {
    $v = Ingresos::vendedorDeCliente($codVend, $nombre);

    return ['N_COMP' => $comp, 'COD_CLI' => $cliente, 'COD_VENDED' => $v['codigo'],
            'VENDEDOR' => $v['nombre'], 'importe_neto' => 100.0];
}

$carteraVend = [
    facturaVendMay('1', 'MA1', 'Z4', 'SERGIO LOPEZ COBOS'),
    facturaVendMay('2', 'MA2', 'Z3', 'VALERIA VILLAREAL'),
    facturaVendMay('3', 'MA1', 'Z4', 'SERGIO LOPEZ COBOS'),
    facturaVendMay('4', 'MA3', '', null),
    facturaVendMay('5', 'MA2', 'Z3', 'VALERIA VILLAREAL')
];
$compDe = function ($items) { return array_column($items, 'N_COMP'); };

chequear('sin filtro, la cartera entera', ['1', '2', '3', '4', '5'],
    $compDe(Ingresos::filtrarPorVendedor($carteraVend, null)));
chequear('un vendedor: solo las facturas de sus clientes', ['2', '5'],
    $compDe(Ingresos::filtrarPorVendedor($carteraVend, 'Z3')));
chequear('"Sin vendedor": solo las de clientes sin vendedor', ['4'],
    $compDe(Ingresos::filtrarPorVendedor($carteraVend, 'SIN')));
chequear('un codigo que no tiene facturas: ninguna, no todas', [],
    Ingresos::filtrarPorVendedor($carteraVend, 'Z5'));

chequear('las opciones: por nombre, con "Sin vendedor" al final y cuantas facturas tiene cada uno', [
    ['valor' => 'Z4', 'nombre' => 'SERGIO LOPEZ COBOS', 'facturas' => 2],
    ['valor' => 'Z3', 'nombre' => 'VALERIA VILLAREAL', 'facturas' => 2],
    ['valor' => 'SIN', 'nombre' => 'Sin vendedor', 'facturas' => 1]
], Ingresos::vendedoresDe($carteraVend));
chequear('sin clientes sin vendedor, no se ofrece la opcion', ['Z3'],
    array_column(Ingresos::vendedoresDe([$carteraVend[1]]), 'valor'));

// EL FILTRO ES DE PANTALLA. El controller lo aplica sobre lo que devolvio
// getCobranzasMay(), y el tablero lee getCobranzasMayTotales(), que nunca lo
// ve. Si alguien lo moviera adentro de la consulta, el tablero mostraria la
// cobranza de un solo vendedor.
$fuenteIngresos = file_get_contents(__DIR__ . '/../Class/Ingresos.php');
$cuerpoMay = substr($fuenteIngresos, strpos($fuenteIngresos, 'public function getCobranzasMay()'));
$cuerpoMay = substr($cuerpoMay, 0, strpos($cuerpoMay, 'public function avisosPendienteSinSaldo'));
$controllerIng = file_get_contents(__DIR__ . '/../Controller/IngresosController.php');

chequear('getCobranzasMay() no filtra por vendedor', false,
    strpos($cuerpoMay, 'filtrarPorVendedor') !== false || strpos($cuerpoMay, "\$_GET") !== false);
chequear('lo filtra el controller, antes del payload', 1,
    preg_match('/payloadCobranzas\(Ingresos::filtrarPorVendedor\(\$itemsMay, \$vendedor\)/', $controllerIng));
chequear('y las opciones salen de la cartera sin filtrar', 1,
    preg_match('/vendedoresDe\(\$itemsMay\)/', $controllerIng));

// La misma lectura de GVA14, no una segunda consulta por factura
chequear('el vendedor viene en la consulta de mayoristas, con su nombre de GVA23', true,
    strpos($cuerpoMay, 'GVA14.COD_VENDED') !== false
    && strpos($cuerpoMay, 'LEFT JOIN GVA23') !== false);
chequear('y la consulta sigue siendo una sola', 1, substr_count($cuerpoMay, 'sqlsrv_query('));

// ============================================================================
// Consultas y estructura de datos contra la base (si hay conexión)
// ============================================================================

seccion('estructura de datos de cobranzas mayoristas');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

// getCobranzasMay() ya no tiene modo resumen: devuelve una fila por
// comprobante, con su fecha. El agrupado por cliente lo hace
// EjeVista::armarAgrupado() en el controller, que es lo que evita perder la
// fecha de cada factura. Ver README-cobranzas-may.md.
$comprobantes = $ingresos->getCobranzasMay();
chequear('getCobranzasMay() devuelve un array', true, is_array($comprobantes));

if (count($comprobantes) > 0) {
    $fila = $comprobantes[0];
    chequear('la fila tiene COD_CLI', true, isset($fila['COD_CLI']));
    chequear('la fila tiene RAZON_SOC', true, isset($fila['RAZON_SOC']));

    $sinVendedor = array_filter($comprobantes, function ($c) {
        return !array_key_exists('COD_VENDED', $c) || !isset($c['VENDEDOR']) || $c['VENDEDOR'] === '';
    });
    chequear('todas las filas traen el vendedor, o "Sin vendedor"', 0, count($sinVendedor));

    // El vendedor es del cliente: no puede cambiar entre sus facturas, o el
    // agrupado del Resumen lo descartaria.
    $vendPorCliente = [];
    foreach ($comprobantes as $c) { $vendPorCliente[$c['COD_CLI']][$c['VENDEDOR']] = true; }
    chequear('cada cliente tiene un solo vendedor en todas sus facturas', 0,
        count(array_filter($vendPorCliente, function ($v) { return count($v) > 1; })));
    chequear('la fila tiene Cobro', true, isset($fila['Cobro']));
    chequear('la fila tiene importe_neto', true, isset($fila['importe_neto']));
    chequear('la fila tiene TIPO_REGISTRO PROYECCION', 'PROYECCION', $fila['TIPO_REGISTRO']);
    chequear('y trae el comprobante, no un rotulo de grupo', true,
        isset($fila['N_COMP']) && $fila['N_COMP'] !== 'COMPROBANTES');
}

$totales = $ingresos->getCobranzasMayTotales();
chequear('getCobranzasMayTotales devuelve un array', true, is_array($totales));

if (count($totales) > 0) {
    $filaTotal = $totales[0];
    chequear('fila totales tiene FECHA', true, isset($filaTotal['FECHA']));
    chequear('fila totales tiene IMPORTE', true, isset($filaTotal['IMPORTE']));
    chequear('IMPORTE es numerico', true, is_numeric($filaTotal['IMPORTE']));
}

$h = Horizonte::desdeParametros(new Parametros());
$provMay = CashflowRegistry::instanciar('COBRANZAS_MAY');
$seriesMay = $provMay->series($h);

chequear('proveedor COBRANZAS_MAY rinde serie COBRANZA', true, isset($seriesMay['COBRANZA']));
chequear('serie COBRANZA tiene dias alineados al horizonte', $h->cantidadDias(), count($seriesMay['COBRANZA']['dias']));
chequear('serie COBRANZA tiene meses alineados al horizonte', $h->cantidadMeses(), count($seriesMay['COBRANZA']['meses']));

/* ================================================================
   FECHA DE COBRO MANUAL

   Mayoristas pasa a tener el mismo circuito que Cobranzas FR, con la MISMA
   tabla. La jerarquia -la fecha manual manda- vive en resolverFechaCobro() y
   no se reimplementa; lo que se prueba aca es la parte que SI es distinta:
   en mayoristas la fecha manual NO cambia ningun importe, porque no hay
   escala de descuento.
   ================================================================ */
seccion('fecha de cobro manual: la jerarquia es la misma que en FR');

// Sin fecha manual: emision + plazo.
$sinManual = Ingresos::resolverFechaCobro('2026-09-15', 60, null);

chequear('sin fecha manual, cobra a los 60 dias', '2026-11-14', $sinManual['fecha']);
chequear('y los dias son el plazo', 60, $sinManual['dias']);
chequear('no esta marcada como manual', false, $sinManual['manual']);

// Con fecha manual: manda la fecha, y los dias se recalculan sobre ella. En
// mayoristas eso es informativo -no hay escala de descuento-, pero mostrar 60
// al lado de una fecha cargada a mano se contradiria a si mismo.
$conManual = Ingresos::resolverFechaCobro('2026-09-15', 60, '2026-10-02');

chequear('la fecha manual manda sobre el plazo', '2026-10-02', $conManual['fecha']);
chequear('y los dias salen de la fecha resuelta, no del plazo', 17, $conManual['dias']);
chequear('queda marcada como manual', true, $conManual['manual']);

seccion('una fecha manual vencida NO se reubica en hoy');

// Es una fecha que pacto una persona. Moverla seria pisar su decision con una
// regla automatica, y el usuario veria su propia carga en otra columna.
$ubicManual = Ingresos::ubicarCobroVencido('2026-09-01', '2026-09-15',
    Ingresos::DIAS_COBRO_VENCIDO, true);

chequear('se muestra donde la pusieron', '2026-09-01', $ubicManual['fecha']);
chequear('pero se marca vencida, que es un hecho', true, $ubicManual['vencida']);
chequear('y no se descarta', false, $ubicManual['descartar']);

// Sin fecha manual, la misma fecha vencida SI se corre al primer dia del eje.
$ubicAuto = Ingresos::ubicarCobroVencido('2026-09-01', '2026-09-15',
    Ingresos::DIAS_COBRO_VENCIDO, false);

chequear('una proyectada vencida va al primer dia del eje', '2026-09-15', $ubicAuto['fecha']);
chequear('conservando cual era su fecha', '2026-09-01', $ubicAuto['original']);

seccion('la fecha manual no cambia ningun importe en mayoristas');

// ESTA ES LA DIFERENCIA CON COBRANZAS FR y el motivo por el que conviene
// probarla: alla los dias deciden el tramo de la escala de descuento y con eso
// cambia el importe neto. Aca no hay escala, asi que el neto es el bruto
// siempre. Se verifica sobre las filas reales.
if (count($comprobantes) > 0) {
    $netoEsBruto = true;
    $descFijo = true;

    foreach ($comprobantes as $c) {
        if (abs($c['importe_neto'] - $c['importe_bruto']) > 0.001) { $netoEsBruto = false; }
        if ($c['Desc'] !== '0%') { $descFijo = false; }
    }

    chequear('el neto es siempre el bruto', true, $netoEsBruto);
    chequear('y el descuento es 0% en todas', true, $descFijo);

    /* EL IMPORTE QUE VA AL EJE ES EL PENDIENTE, NO EL FACTURADO. Es el cambio
       que trajo la consulta nueva: antes se usaba GVA12.IMPORTE, que es lo que
       decia la factura al emitirse, asi que una factura cobrada a medias
       entraba al cashflow por su importe completo. */
    $soloFac = true;
    $conFactura = true;
    $pendientePositivo = true;
    $pendienteExcede = 0;

    foreach ($comprobantes as $c) {
        if ($c['T_COMP'] !== 'FAC') { $soloFac = false; }
        if (!array_key_exists('IMPORTE_FACTURA', $c)) { $conFactura = false; continue; }
        if ($c['importe_neto'] <= 0) { $pendientePositivo = false; }

        // El pendiente no puede ser mayor que lo facturado: si lo fuera,
        // la tabla de signos de IMPU estaria al reves.
        if ($c['importe_neto'] > $c['IMPORTE_FACTURA'] + 0.001) { $pendienteExcede++; }
    }

    // SOLO FACTURAS, a proposito: las notas de credito y debito imputadas ya
    // estan descontadas del pendiente, asi que traerlas como filas propias las
    // contaria dos veces. Ver README-cobranzas-may.md.
    chequear('todos los comprobantes son FAC', true, $soloFac);

    chequear('cada fila trae el importe facturado como dato informativo',
        true, $conFactura);
    chequear('ninguna fila entra con pendiente cero o negativo',
        true, $pendientePositivo);
    chequear('y ningun pendiente supera al importe facturado', 0, $pendienteExcede);

    // El total del listado tiene que ser el pendiente y no el facturado. Con
    // cartera real los dos numeros difieren; si fueran iguales, o no hay nada
    // cobrado a cuenta, o alguien volvio a usar GVA12.IMPORTE.
    $sumaPend = 0;
    $sumaFact = 0;

    foreach ($comprobantes as $c) {
        $sumaPend += $c['importe_neto'];
        $sumaFact += $c['IMPORTE_FACTURA'];
    }

    chequear('el total pendiente nunca supera al total facturado',
        true, round($sumaPend, 2) <= round($sumaFact, 2));

    // Y lo que consolida el tablero sale del PENDIENTE: si la serie diera el
    // facturado, el cuadro mostraria cobranza que ya se cobro.
    $sumaSerie = 0;

    foreach ($ingresos->getCobranzasMayTotales() as $t) {
        $sumaSerie += $t['IMPORTE'];
    }

    chequear('la serie del tablero es exactamente el pendiente del listado',
        round($sumaPend, 2), round($sumaSerie, 2));

    // La fila transporta la marca para que la grilla pueda dibujar el editor y
    // el Resumen pueda mostrar el indicador.
    chequear('la fila informa si la fecha la cargo una persona',
        true, array_key_exists('FECHA_MANUAL', $comprobantes[0]));

    // PLAZO sigue siendo el del parametro aunque haya fecha manual: es lo que
    // permite auditar contra que se aparto la fecha cargada.
    chequear('y conserva el plazo del parametro',
        true, intval($comprobantes[0]['PLAZO']) > 0);
}

seccion('los dos circuitos comparten la tabla de fechas manuales');

// La clave es el comprobante y un comprobante es de franquicias o de
// mayoristas, nunca de los dos. Por eso no hay tabla paralela ni columna de
// origen. Ver el encabezado de sql/cashflow_cobranzas_fecha_manual.sql.
$mapaManual = $ingresos->getFechasManuales();

chequear('getFechasManuales devuelve un mapa', true, is_array($mapaManual));

$clavesOk = true;

foreach ($mapaManual as $clave => $info) {
    if (strpos($clave, '|') === false || !isset($info['fecha'])) { $clavesOk = false; }
}

chequear('indexado por T_COMP|N_COMP', true, $clavesOk);

// ============================================================================
// LA COLUMNA DEL PENDIENTE SE LLAMA POR LO QUE ES
// ============================================================================

seccion('el rotulo de la columna del pendiente');

// El dato dejo de ser GVA12.IMPORTE en ad2d04d: lo que hay ahi es el saldo
// pendiente. "Importe Bruto" nombraba el facturado y mostraba el pendiente, y
// al lado quedo una columna que SI es el facturado. Los dos rotulos tienen que
// poder leerse juntos sin confundirse.
$tabMay = file_get_contents(__DIR__ . '/../Tabs/cobranzas_may.php');

// Sin los comentarios: ahi el nombre viejo SI puede aparecer, y aparece, porque
// es lo que explica por que el rotulo cambio.
$visibleMay = preg_replace('/<!--.*?-->/s', '', $tabMay);

chequear('la columna del pendiente se llama SALDO PENDIENTE',
    true, strpos($visibleMay, 'SALDO PENDIENTE') !== false);

chequear('y el rotulo viejo no se muestra en ninguna columna',
    false, strpos($visibleMay, 'Importe Bruto') !== false);

// Igual que "Importe Factura", que explica en su title que es informativa.
chequear('tiene un title que explica que es lo que falta cobrar',
    true, strpos($visibleMay, 'title="Lo que falta cobrar') !== false);

chequear('la columna informativa del facturado sigue estando',
    true, strpos($visibleMay, 'Importe Factura') !== false);
