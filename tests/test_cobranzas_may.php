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

$resumen = $ingresos->getCobranzasMay(true);
chequear('getCobranzasMay(true) devuelve un array', true, is_array($resumen));

if (count($resumen) > 0) {
    $filaResumen = $resumen[0];
    chequear('fila resumen tiene COD_CLI', true, isset($filaResumen['COD_CLI']));
    chequear('fila resumen tiene RAZON_SOC', true, isset($filaResumen['RAZON_SOC']));
    chequear('fila resumen tiene Cobro', true, isset($filaResumen['Cobro']));
    chequear('fila resumen tiene importe_neto', true, isset($filaResumen['importe_neto']));
    chequear('fila resumen tiene TIPO_REGISTRO PROYECCION', 'PROYECCION', $filaResumen['TIPO_REGISTRO']);
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
