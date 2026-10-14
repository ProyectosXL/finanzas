<?php
/**
 * La tasa de retencion compartida: Class/Retenciones.php.
 *
 * La regla en si -que vigencia rige, como se suma, que es pendiente- esta
 * probada a fondo en test_cob_electronicos.php, que llama a los metodos de
 * CobElectronicos y por eso sigue cubriendo la regla despues del refactor. Aca
 * se prueba lo que es NUEVO de la clase compartida: que no sabe de
 * procesadoras -recalcula por 'juego'-, que los textos los pone quien llama, y
 * que los dos juegos de alicuotas no se mezclan.
 */

require_once __DIR__ . '/../Class/Retenciones.php';
require_once __DIR__ . '/../Class/CobElectronicos.php';

$cheques = [
    ['ID' => 1, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.025, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    ['ID' => 2, 'CONCEPTO' => 'SICREB', 'ALICUOTA' => 0.006, 'VIGENCIA_DESDE' => '2026-10-01', 'ACTIVO' => 1],
    // Una vigencia nueva de IIBB que arranca el 20: lo acreditado desde ese
    // dia se calcula con ella, lo de antes no.
    ['ID' => 3, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.030, 'VIGENCIA_DESDE' => '2026-10-20', 'ACTIVO' => 1]
];

seccion('la tasa se resuelve a la fecha de acreditacion');

chequear('antes de la vigencia nueva: 3,1%', 0.031,
    Retenciones::tasaRetencion($cheques, '2026-10-19')['tasa']);
chequear('desde la vigencia nueva: 3,6%', 0.036,
    Retenciones::tasaRetencion($cheques, '2026-10-20')['tasa']);
chequear('el neto es bruto por (1 - tasa)', 96400.0, Retenciones::importeNeto(100000, 0.036));
chequear('el desglose nombra cada concepto', 'IIBB 3% + SICREB 0,6%',
    Retenciones::desglose(Retenciones::tasaRetencion($cheques, '2026-10-20')['detalle']));
chequear('sin alicuotas el desglose queda vacio, no dice 0%', '',
    Retenciones::desglose(Retenciones::tasaRetencion([], '2026-10-20')['detalle']));

seccion('los textos los pone quien llama');

$textos = ['sin_vigentes' => 'Los cheques no tienen', 'de' => 'de los cheques',
           'que' => 'del depósito', 'donde' => 'Parámetros → Cheques'];

try {
    Retenciones::resolverTasa([], '2026-10-21', $textos);
    $mensaje = '';
} catch (Throwable $e) {
    $mensaje = $e->getMessage();
}

chequear('sin alicuotas lanza con el sujeto de quien llama', true,
    strpos($mensaje, 'Los cheques no tienen ninguna alícuota vigente al 21/10/2026') === 0);
chequear('y dice donde cargarlas', true, strpos($mensaje, 'Parámetros → Cheques') !== false);

// Cob. Electronicos conserva su mensaje: el refactor no cambio ningun texto.
try {
    CobElectronicos::resolverTasa([], '2026-10-21', 'Payway');
    $mensaje = '';
} catch (Throwable $e) {
    $mensaje = $e->getMessage();
}

chequear('Cob. Electronicos sigue diciendo lo mismo',
    '"Payway" no tiene ninguna alícuota vigente al 21/10/2026, así que no se puede calcular el '
    . 'importe neto del movimiento. Cargá sus alícuotas en Parámetros → Cob. Electrónicos con '
    . 'una vigencia igual o anterior a esa fecha.', $mensaje);

seccion('el plan de recalculo no sabe de procesadoras: recalcula por juego');

$items = [
    ['id' => 10, 'juego' => 0, 'fecha_acreditacion' => '2026-10-21',
     'importe_bruto' => 100000.0, 'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0],
    ['id' => 11, 'juego' => 0, 'fecha_acreditacion' => '2026-10-15',
     'importe_bruto' => 100000.0, 'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0]
];

$plan = Retenciones::planRecalculo($items, [0 => $cheques], '2026-10-16');

chequear('recalcula solo lo que acredita desde el corte', 1, count($plan['cambios']));
chequear('con la tasa vigente a su acreditacion', 0.036, $plan['cambios'][0]['tasa_nueva']);
chequear('y el desglose nuevo para guardarlo', 'IIBB 3% + SICREB 0,6%',
    $plan['cambios'][0]['desglose_nuevo']);
chequear('el cambio trae el item original', 10, $plan['cambios'][0]['item']['id']);
chequear('lo anterior al corte cuenta como acreditado', 1, $plan['acreditados']);

$sin = Retenciones::planRecalculo($items, [], '2026-10-16');
chequear('sin alicuotas no recalcula y lo devuelve por juego', 1, count($sin['sin_alicuota'][0]));

seccion('los dos juegos no se mezclan');

// Payway con otra tasa: el plan de los cheques no la ve, y el de Cob.
// Electronicos no ve la de los cheques. Cada uno recibe SU juego.
$payway = [['ID' => 9, 'CONCEPTO' => 'IIBB', 'ALICUOTA' => 0.10, 'VIGENCIA_DESDE' => '2026-01-01', 'ACTIVO' => 1]];

chequear('la tasa de los cheques no cambia por la de Payway', 0.036,
    Retenciones::planRecalculo($items, [0 => $cheques, 1 => $payway], '2026-10-16')['cambios'][0]['tasa_nueva']);

$movPayway = [['id' => 1, 'id_procesadora' => 1, 'procesadora' => 'Payway',
               'fecha_acreditacion' => '2026-10-21', 'importe_bruto' => 100000.0,
               'tasa_aplicada' => 0.031, 'importe_neto' => 96900.0]];

chequear('ni la de Payway por la de los cheques', 0.10,
    CobElectronicos::planRecalculo($movPayway, [1 => $payway], '2026-10-16')['cambios'][0]['tasa_nueva']);

seccion('un solo corte de pendientes para los dos modulos');

chequear('es manana', '2026-10-14', Retenciones::cortePendientes('2026-10-13'));
chequear('y Cob. Electronicos usa el mismo', Retenciones::cortePendientes('2026-10-13'),
    CobElectronicos::cortePendientes('2026-10-13'));
