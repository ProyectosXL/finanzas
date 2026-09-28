<?php
require_once __DIR__ . '/../Class/Formulas.php';

/** Contexto de una columna, con valores redondos para seguir la cuenta a mano */
function ctxIE($tipo, array $rubros, array $extra = []) {
    return array_merge([
        'tipo' => $tipo,
        'rubros' => $rubros,
        'rubrosLocales' => Formulas::esLocal($tipo) ? $rubros : [],
        'secciones' => [],
        'total19' => null,
        'locales19' => null
    ], $extra);
}

$NA = Formulas::NO_APLICA;

seccion('Aritmetica con null');
chequear('Suma con un null', 5.0, Formulas::suma(null, 5));
chequear('Suma de todos null es null, no 0', null, Formulas::suma(null, null));
chequear('Division por cero es null', null, Formulas::div(10, 0));
chequear('Division con dato faltante es null', null, Formulas::div(null, 10));
chequear('No aplica se propaga', $NA, Formulas::div($NA, 10));

seccion('Ventas de un local');
$local = ['1.1.' => 100.0, '1.2.' => 1110.0, '1.5.' => 1000.0, '1.8.' => 50.0, '2.' => 300.0];
$o = Formulas::calcular(ctxIE(Formulas::LOCAL, $local, ['total19' => 4200.0, 'locales19' => 2100.0]));
chequear('1.3 = 1.1 + 1.2', 1210.0, $o['V_1_3']);
chequear('1.4 = -(1.3 - 1.5), en negativo', -210.0, $o['V_1_4']);
chequear('1.9 = 1.5 + 1.6 + 1.7 + 1.8 (1.1 y 1.2 no suman)', 1050.0, $o['V_1_9']);
chequear('Participacion % total', 0.25, $o['PART_TOTAL']);
chequear('Participacion % locales', 0.5, $o['PART_LOCALES']);

seccion('Costo y mark up');
chequear('Relacion costo sobre ventas = 2 / 1.9', 300 / 1050, $o['REL_COSTO_VENTAS']);
chequear('Resultado bruto = 1.9 - 2', 750.0, $o['RESULTADO_BRUTO']);
chequear('Relacion costo / venta con IVA = 2 / 1.3', 300 / 1210, $o['REL_COSTO_VENTA_CON_IVA']);
chequear('Mark up con IVA = 1.3 / 2', 1210 / 300, $o['MARKUP_CON_IVA']);
chequear('Mark up sin IVA en local: 1.5 / 2, SIN el 1.8', 1000 / 300, $o['MARKUP_SIN_IVA']);

$may = Formulas::calcular(ctxIE(Formulas::MAYORISTAS, ['1.6.' => 800.0, '1.8.' => 40.0, '2.' => 400.0]));
chequear('Mayoristas: mark up sin IVA sobre 1.6', 2.0, $may['MARKUP_SIN_IVA']);
chequear('Mayoristas: mark up con IVA no aplica', $NA, $may['MARKUP_CON_IVA']);
chequear('Mayoristas: relacion con IVA no aplica', $NA, $may['REL_COSTO_VENTA_CON_IVA']);
chequear('Mayoristas: 1.3 no aplica', $NA, $may['V_1_3']);
chequear('Mayoristas: participacion locales no aplica', $NA, $may['PART_LOCALES']);

$fr = Formulas::calcular(ctxIE(Formulas::FRANQUICIAS, ['1.7.' => 900.0, '2.' => 450.0]));
chequear('Franquicias: mark up sin IVA sobre 1.7', 2.0, $fr['MARKUP_SIN_IVA']);
chequear('Franquicias: mark up con IVA no aplica', $NA, $fr['MARKUP_CON_IVA']);

$ec = Formulas::calcular(ctxIE(Formulas::ECOMMERCE_AP, ['1.5.' => 600.0, '1.8.' => 10.0]));
chequear('Ecommerce sin costo: mark up sin IVA es "—" (null)', null, $ec['MARKUP_SIN_IVA']);
chequear('Ecommerce: mark up con IVA no aplica', $NA, $ec['MARKUP_CON_IVA']);

$ot = Formulas::calcular(ctxIE(Formulas::OTROS, ['1.5.' => 10.0, '1.6.' => 20.0, '1.7.' => 30.0, '1.8.' => 1000.0, '2.' => 30.0]));
chequear('Otros: base de mercaderia 1.5 + 1.6 + 1.7', 2.0, $ot['MARKUP_SIN_IVA']);
chequear('Otros: 1.3 y 1.4 no aplican', [$NA, $NA], [$ot['V_1_3'], $ot['V_1_4']]);

seccion('Total general: 1.3 y 1.4 son de los locales, el con IVA no aplica');
$tot = Formulas::calcular([
    'tipo' => Formulas::TOTAL,
    'rubros' => ['1.1.' => 100.0, '1.2.' => 1110.0, '1.5.' => 1600.0, '1.6.' => 200.0, '1.7.' => 300.0, '2.' => 500.0],
    'rubrosLocales' => ['1.1.' => 100.0, '1.2.' => 1110.0, '1.5.' => 1000.0],
    'secciones' => []
]);
chequear('1.3 del total = suma de locales', 1210.0, $tot['V_1_3']);
chequear('1.4 del total = -(1.3 - 1.5 de locales), no del 1.5 de todos', -210.0, $tot['V_1_4']);
chequear('Mark up con IVA no aplica en el total', $NA, $tot['MARKUP_CON_IVA']);
chequear('Mark up sin IVA del total = (1.5 + 1.6 + 1.7) / 2', 2100 / 500, $tot['MARKUP_SIN_IVA']);

seccion('Gastos y resultados');
$sec = [
    Formulas::SEC_COMERCIALIZACION => 100.0,
    Formulas::SEC_PERSONAL => 50.0,
    Formulas::SEC_OCUPACION => 40.0,
    Formulas::SEC_OTROS_OPERATIVOS => 10.0,
    Formulas::SEC_BIENES_USO => 20.0,
    Formulas::SEC_ESTRUCTURA => 30.0
];
$r = ['1.5.' => 1000.0, '2.' => 400.0, '4.1.1.' => 20.0, '4.1.2.' => 30.0, '4.1.3.' => 40.0];
$g = Formulas::calcular(ctxIE(Formulas::LOCAL, $r, ['secciones' => $sec]));
chequear('Relacion arancel = 4.1.1 / 1.9', 0.02, $g['REL_ARANCEL']);
chequear('Relacion promociones = 4.1.2 / 1.9', 0.03, $g['REL_PROMOCIONES']);
chequear('Relacion costo financiero = 4.1.3 / 1.9', 0.04, $g['REL_COSTO_FINANCIERO']);
chequear('Resultado comercial es un % = (2 + comerc) / 1.9', 0.5, $g['RESULTADO_COMERCIAL']);
chequear('Total operativo = personal + ocupacion + otros', 100.0, $g['TOTAL_OPERATIVO']);
chequear('Resultado operativo = RB - comerc - operativo', 400.0, $g['RESULTADO_OPERATIVO']);
chequear('Contribucion marginal = RO - bienes de uso', 380.0, $g['CONTRIBUCION_MARGINAL']);
chequear('Resultado explotacion = CMN - estructura', 350.0, $g['RESULTADO_EXPLOTACION']);
chequear('Margen bruto', 0.6, $g['MARGEN_BRUTO']);
chequear('Suma % costos (excluye CMV)', 0.25, $g['SUMA_COSTOS']);

seccion('Rentabilidad total: la base cambia segun la columna, como el Excel');
$rr = ['1.5.' => 1000.0, '1.8.' => 250.0, '2.' => 500.0];
chequear('Local: sobre 1.9', 750 / 1250, Formulas::calcular(ctxIE(Formulas::LOCAL, $rr))['RENTABILIDAD_TOTAL']);
chequear('Subtotal LOCALES: sobre 1.5', 750 / 1000, Formulas::calcular(ctxIE(Formulas::LOCALES, $rr))['RENTABILIDAD_TOTAL']);
chequear('Total general: sobre 1.9', 750 / 1250, Formulas::calcular(ctxIE(Formulas::TOTAL, $rr))['RENTABILIDAD_TOTAL']);
chequear('Canal Ecommerce: sobre 1.9', 750 / 1250, Formulas::calcular(ctxIE(Formulas::ECOMMERCE, $rr))['RENTABILIDAD_TOTAL']);

seccion('Division por cero: siempre null, nunca un infinito ni un 0');
$z = Formulas::calcular(ctxIE(Formulas::LOCAL, ['2.' => 100.0]));
chequear('Sin venta: 1.9 es null', null, $z['V_1_9']);
chequear('Sin venta: relacion costo es null', null, $z['REL_COSTO_VENTAS']);
chequear('Sin venta: rentabilidad es null', null, $z['RENTABILIDAD_TOTAL']);
$cero = Formulas::calcular(ctxIE(Formulas::LOCAL, ['1.5.' => 0.0, '2.' => 0.0]));
chequear('Venta 0: margen bruto null', null, $cero['MARGEN_BRUTO']);
chequear('Costo 0: mark up null', null, $cero['MARKUP_SIN_IVA']);
