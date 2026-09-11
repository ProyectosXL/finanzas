<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../cashflow/Class/Ingresos.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';

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
