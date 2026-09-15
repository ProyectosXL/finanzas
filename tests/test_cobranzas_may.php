<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../cashflow/Class/Ingresos.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Horizonte.php';

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
