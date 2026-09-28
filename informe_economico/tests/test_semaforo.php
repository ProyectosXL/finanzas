<?php
require_once __DIR__ . '/../Class/Semaforo.php';

function bandaIE($d, $h) {
    return ['desde' => $d, 'hasta' => $h];
}

// La semilla, igual al Excel "5. Ranking Locales"
$rent = ['sentido' => Semaforo::MAYOR_MEJOR, 'bandas' => [
    'VERDE' => bandaIE(0.15, null), 'AMARILLO' => bandaIE(0.12, 0.15),
    'NARANJA' => bandaIE(0.05, 0.12), 'ROJO' => bandaIE(null, 0.05)
]];
$costo = ['sentido' => Semaforo::MENOR_MEJOR, 'bandas' => ['VERDE' => bandaIE(null, 0.30)]];
$com = ['sentido' => Semaforo::MENOR_MEJOR, 'bandas' => [
    'VERDE' => bandaIE(null, 0.15), 'AMARILLO' => bandaIE(0.15, 0.20), 'ROJO' => bandaIE(0.20, null)
]];

seccion('Rentabilidad (mayor es mejor)');
chequear('20% verde', 'VERDE', Semaforo::color(0.20, $rent));
chequear('13% amarillo', 'AMARILLO', Semaforo::color(0.13, $rent));
chequear('8% naranja', 'NARANJA', Semaforo::color(0.08, $rent));
chequear('-3% rojo', 'ROJO', Semaforo::color(-0.03, $rent));

seccion('Bordes: van a la banda peor');
chequear('15% exacto: amarillo, no verde ("verde > 15%")', 'AMARILLO', Semaforo::color(0.15, $rent));
chequear('12% exacto: naranja', 'NARANJA', Semaforo::color(0.12, $rent));
chequear('5% exacto: rojo (el Excel dice naranja)', 'ROJO', Semaforo::color(0.05, $rent));
chequear('Comercializacion 15% exacto: amarillo ("verde < 15%")', 'AMARILLO', Semaforo::color(0.15, $com));
chequear('Comercializacion 20% exacto: rojo (el Excel dice amarillo)', 'ROJO', Semaforo::color(0.20, $com));
chequear('Comercializacion 14,99%: verde', 'VERDE', Semaforo::color(0.1499, $com));

seccion('Sin banda definida: sin color');
chequear('Costo de mercaderia 25%: verde', 'VERDE', Semaforo::color(0.25, $costo));
chequear('Costo de mercaderia 30% exacto: sin color', null, Semaforo::color(0.30, $costo));
chequear('Costo de mercaderia 40%: sin color', null, Semaforo::color(0.40, $costo));
chequear('Valor null: sin color', null, Semaforo::color(null, $rent));
chequear('No aplica: sin color', null, Semaforo::color('NA', $rent));
