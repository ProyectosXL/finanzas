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
