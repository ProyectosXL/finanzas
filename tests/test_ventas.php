<?php
/**
 * Bloque de tendencias del Analisis de Ventas.
 *
 * No toca la base: Ventas::armarTendencias() esta separado de la lectura
 * justamente para poder verificar sin SQL Server lo que tiene filo -el recorte
 * del mes parcial y el criterio de "sin dato"-, que es donde una comparacion
 * interanual se vuelve mentirosa sin que nadie lo note.
 */

require_once __DIR__ . '/../cashflow/Class/Ventas.php';

/**
 * Serie diaria de juguete: un importe fijo por dia para todos los dias de un
 * mes. Con importes planos, el total de un tramo es dias * importe, asi que
 * cualquier desvio delata el recorte.
 */
function serieMes($anio, $mes, $importePorDia, $serie = []) {
    $dias = intval((new DateTime(sprintf('%04d-%02d-01', $anio, $mes)))->format('t'));

    for ($d = 1; $d <= $dias; $d++) {
        $serie[sprintf('%04d-%02d-%02d', $anio, $mes, $d)] = $importePorDia;
    }

    return $serie;
}

/* ================================================================
   Recorrido de la ventana
   ================================================================ */
seccion('la ventana se ancla en el mes del corte');

$serie = [];

foreach ([2025, 2026] as $anio) {
    for ($m = 1; $m <= 12; $m++) {
        $serie = serieMes($anio, $m, 100, $serie);
    }
}

$t = Ventas::armarTendencias($serie, 6, '2026-09-30');

chequear('devuelve 6 filas', 6, count($t['filas']));
chequear('la primera es la mas vieja', '2026-04', $t['filas'][0]['clave']);
chequear('la ultima es la del corte', '2026-09', $t['filas'][5]['clave']);
chequear('el rotulo sale de Horizonte::labelMes', 'Sep-26', $t['filas'][5]['label']);
chequear('y el del anio anterior tambien', 'Sep-25', $t['filas'][5]['label_anio_anterior']);
chequear('informa el dia de corte', '2026-09-30', $t['dia_corte']);

/* ================================================================
   Mes cerrado
   ================================================================ */
seccion('un mes cerrado se compara contra el mes completo');

// Corte el ultimo dia de septiembre: el mes cierra, no hay fila parcial.
chequear('el mes del corte no queda parcial', false, $t['filas'][5]['parcial']);
chequear('suma los 30 dias de septiembre', 3000.0, $t['filas'][5]['neto']);
chequear('y los 30 del anio anterior', 3000.0, $t['filas'][5]['neto_anio_anterior']);
chequear('sin variacion con importes planos', 0.0, $t['filas'][5]['variacion']);

/* ================================================================
   Mes parcial
   ================================================================ */
seccion('el mes en curso recorta el anio anterior a los mismos dias');

$t = Ventas::armarTendencias($serie, 6, '2026-09-06');

chequear('el mes del corte queda parcial', true, $t['filas'][5]['parcial']);
chequear('suma solo los 6 dias transcurridos', 600.0, $t['filas'][5]['neto']);
chequear('el anio anterior tambien se recorta a 6', 600.0, $t['filas'][5]['neto_anio_anterior']);
chequear('y por eso la variacion es cero, no -80%', 0.0, $t['filas'][5]['variacion']);
chequear('informa los dias comparados', 6, $t['filas'][5]['dias']);
chequear('de los dos lados', 6, $t['filas'][5]['dias_anio_anterior']);

// El mes anterior al del corte NO se recorta: esta cerrado.
chequear('el mes anterior sigue completo', false, $t['filas'][4]['parcial']);
chequear('y suma sus 31 dias', 3100.0, $t['filas'][4]['neto']);

/* ================================================================
   El recorte de fin de mes
   ================================================================ */
seccion('el recorte se acota a los dias que el mes del anio anterior tuvo');

// Marzo de 2024 tiene 31 dias; febrero no. Un corte el 31 no puede pedirle al
// anio anterior un dia que no existe.
$serieFeb = serieMes(2027, 3, 10, serieMes(2026, 3, 10));
$t = Ventas::armarTendencias($serieFeb, 1, '2027-03-31');

chequear('marzo cerrado suma sus 31 dias', 310.0, $t['filas'][0]['neto']);
chequear('y el anio anterior tambien', 310.0, $t['filas'][0]['neto_anio_anterior']);

// Ahora el caso con filo: corte el 29 de febrero de un bisiesto contra un
// febrero de 28.
$serieBis = serieMes(2024, 2, 10, serieMes(2023, 2, 10));
$t = Ventas::armarTendencias($serieBis, 1, '2024-02-28');

chequear('febrero bisiesto al 28 es parcial', true, $t['filas'][0]['parcial']);
chequear('suma 28 dias', 280.0, $t['filas'][0]['neto']);
chequear('el anio anterior se acota a los 28 que tuvo', 28, $t['filas'][0]['dias_anio_anterior']);
chequear('y no se pasa de largo', 280.0, $t['filas'][0]['neto_anio_anterior']);

/* ================================================================
   Sin dato del anio anterior
   ================================================================ */
seccion('sin anio anterior la variacion es null, no cero');

// Solo 2026: el historico arranca ahi y 2025 no existe.
$soloUno = serieMes(2026, 5, 50);
$t = Ventas::armarTendencias($soloUno, 1, '2026-05-31');

chequear('el mes tiene venta', 1550.0, $t['filas'][0]['neto']);
chequear('el anio anterior es cero', 0.0, $t['filas'][0]['neto_anio_anterior']);
chequear('y la variacion es null para que el front pinte un guion',
         null, $t['filas'][0]['variacion']);
chequear('el total tampoco inventa una variacion', null, $t['totales']['variacion']);

/* ================================================================
   Totales
   ================================================================ */
seccion('los totales suman las dos columnas');

$serieCrece = serieMes(2026, 6, 200, serieMes(2025, 6, 100));
$t = Ventas::armarTendencias($serieCrece, 1, '2026-06-30');

chequear('total del periodo', 6000.0, $t['totales']['neto']);
chequear('total del anio anterior', 3000.0, $t['totales']['neto_anio_anterior']);
chequear('la variacion del total sale de los totales', 1.0, $t['totales']['variacion']);

/* ================================================================
   Bloque vacio
   ================================================================ */
seccion('una serie vacia no rompe ni inventa importes');

$t = Ventas::armarTendencias([], 6, '2026-09-06');

chequear('devuelve las 6 filas igual', 6, count($t['filas']));
chequear('todas en cero', 0.0, $t['filas'][0]['neto']);
chequear('y sin variacion', null, $t['filas'][0]['variacion']);
