<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../cashflow/Class/Ingresos.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';

// ============================================================================
// Escala de descuentos por días
// ============================================================================

seccion('escala de descuento aplica los tramos correctamente');

$ingresos = new Ingresos();

$escalas = [
    'FRTEST' => [
        'ECHEQ' => [
            ['dias_desde' => 0, 'dias_hasta' => 20, 'porcentaje_desc' => 8.0],
            ['dias_desde' => 21, 'dias_hasta' => 30, 'porcentaje_desc' => 6.0],
            ['dias_desde' => 31, 'dias_hasta' => 60, 'porcentaje_desc' => 3.0]
        ]
    ]
];

$paramsClientes = [
    'FRTEST' => [
        'dias_pp_max' => 15,
        'desc_pp_max' => 0.08
    ],
    'FROTRO' => [
        'dias_pp_max' => 20,
        'desc_pp_max' => 0.08
    ]
];

// Tramo 1: 15 días -> 8%
$desc1 = $ingresos->calcularDescuentoPorDias('FRTEST', 15, 'ECHEQ', $escalas, $paramsClientes);
chequear('15 dias cae en tramo 0-20 (8%)', 8.0, $desc1);

// Tramo 2: 25 días -> 6%
$desc2 = $ingresos->calcularDescuentoPorDias('FRTEST', 25, 'ECHEQ', $escalas, $paramsClientes);
chequear('25 dias cae en tramo 21-30 (6%)', 6.0, $desc2);

// Tramo 3: 45 días -> 3%
$desc3 = $ingresos->calcularDescuentoPorDias('FRTEST', 45, 'ECHEQ', $escalas, $paramsClientes);
chequear('45 dias cae en tramo 31-60 (3%)', 3.0, $desc3);

// Fuera de tramos: 75 días -> 0%
$desc4 = $ingresos->calcularDescuentoPorDias('FRTEST', 75, 'ECHEQ', $escalas, $paramsClientes);
chequear('75 dias queda fuera de tramo (0%)', 0.0, $desc4);

// Cliente sin escalas específicas -> usa fallback
$desc5 = $ingresos->calcularDescuentoPorDias('FROTRO', 10, 'ECHEQ', $escalas, $paramsClientes);
chequear('cliente sin escala usa fallback si dias <= dias_pp_max', 8.0, $desc5);

$desc6 = $ingresos->calcularDescuentoPorDias('FROTRO', 35, 'ECHEQ', $escalas, $paramsClientes);
chequear('cliente sin escala da 0% si dias > dias_pp_max', 0.0, $desc6);

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
