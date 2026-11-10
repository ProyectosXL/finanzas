<?php
/**
 * La lectura de la app de alquileres (Class/AlquileresApp.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL CASHFLOW ESCRIBE EN LA APP. Las tablas son de administracion: un
 *      UPDATE "inocente" en RO_T_DETALLE_ALQUILERES cambiaria lo que la app
 *      muestra como cerrado. La clase no tiene que tener ni una sentencia de
 *      escritura, y se lee el fuente para fijarlo.
 *
 *   2. LOS PERIODOS SE CONVIERTEN MAL. '9-2026' y '2026-09' tienen que ser el
 *      mismo mes; '1-2026' no puede ser noviembre (el LIKE de la app hace eso).
 *
 *   3. UN PORCENTAJE CON COMA SE LEE COMO OTRO NUMERO. La app hace
 *      str_replace(',', '.') antes del floatval; sin eso, '6,47' es 6.
 *
 *   4. UN PERIODO ABIERTO SE LEE. Con el periodo abierto los conceptos 6 y 7
 *      estan guardados sin restar el VMA. La ultima seccion, contra la base,
 *      verifica que detalleCerrados() no traiga ningun mes abierto y que en un
 *      mes cerrado el 6 y el 7 ya tengan restado el 8.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresApp.php';
require_once __DIR__ . '/../Class/Inflacion.php';

seccion('AlquileresApp: los periodos');

chequear("'9-2026' es 2026-09", '2026-09', AlquileresApp::mesDePeriodo('9-2026'));
chequear("'09-2026' tambien", '2026-09', AlquileresApp::mesDePeriodo('09-2026'));
chequear("'1-2026' es enero, no noviembre", '2026-01', AlquileresApp::mesDePeriodo('1-2026'));
chequear("'11-2026' es noviembre", '2026-11', AlquileresApp::mesDePeriodo(' 11-2026 '));
chequear('un mes 13 no se adivina', null, AlquileresApp::mesDePeriodo('13-2026'));
chequear('un formato ajeno no se adivina', null, AlquileresApp::mesDePeriodo('2026-09'));
chequear('ida y vuelta: 2026-09 es 9-2026', '9-2026', AlquileresApp::periodoDeMes('2026-09'));
chequear('ida y vuelta: 2026-12 es 12-2026', '12-2026', AlquileresApp::periodoDeMes('2026-12'));

seccion('AlquileresApp: los porcentajes, con el parseo de la app');

chequear('un float queda igual', 6.47, AlquileresApp::porcentaje(6.47));
chequear('con coma decimal', 6.47, AlquileresApp::porcentaje('6,47'));
chequear('con punto decimal', 0.84, AlquileresApp::porcentaje('0.84'));
chequear('un 0 es un porcentaje, no un faltante', 0.0, AlquileresApp::porcentaje('0'));
chequear('vacio es null', null, AlquileresApp::porcentaje(' '));
chequear('NULL es null', null, AlquileresApp::porcentaje(null));

seccion('AlquileresApp: solo lee');

$fuente = file_get_contents(__DIR__ . '/../Class/AlquileresApp.php');
/* Se sacan los comentarios: el encabezado nombra las palabras para explicar
   por que no estan. */
$codigo = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $fuente);
chequear('ninguna sentencia de escritura en el codigo', 0,
    preg_match_all('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC|DROP|ALTER|CREATE)\b/i', $codigo));

seccion('AlquileresApp: contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $app = new AlquileresApp();
    chequear('estan las tablas de la app', [], $app->tablasFaltantes());

    $cerrados = $app->mesesCerrados();
    chequear('hay meses cerrados', true, count($cerrados) > 0);

    $desde = Inflacion::mesMas(end($cerrados), -2);
    $detalle = $app->detalleCerrados($cerrados, $desde);
    $abiertos = 0;

    foreach ($detalle as $meses) {
        foreach (array_keys($meses) as $mes) {
            if (!in_array($mes, $cerrados, true)) {
                $abiertos++;
            }
        }
    }

    chequear('detalleCerrados() no trae ningun mes abierto', 0, $abiertos);

    /* Con el mes cerrado, 6 = max(0, bruta x %6 - c8) y 7 = max(0, neta x %7
       - c8): la resta ya esta hecha. Se mide en todo local que tenga el
       porcentaje y la venta, con un peso de tolerancia porque la app trunca a
       pesos enteros. */
    $mes = end($cerrados);
    $pct = $app->porcentajes();
    $netas = $app->ventasNetas($mes);
    $brutas = $app->ventasBrutas($mes);
    $medidos = 0;
    $distintos = [];

    foreach ($detalle as $nro => $meses) {
        if (!isset($meses[$mes])) {
            continue;
        }

        $c = $meses[$mes];

        foreach ([6 => $brutas, 7 => $netas] as $id => $venta) {
            if (!isset($pct[$nro][$id], $venta[$nro][$mes])) {
                continue;
            }

            $esperado = max(0.0, $venta[$nro][$mes] * $pct[$nro][$id] / 100 - ($c[8] ?? 0.0));
            $medidos++;

            if (abs($esperado - ($c[$id] ?? 0.0)) > 1.5) {
                $distintos[] = "$nro/$id";
            }
        }
    }

    chequear('en el ultimo cerrado se pudo medir el 6 o el 7 en algun local', true, $medidos > 0);
    chequear('en el ultimo cerrado el 6 y el 7 ya tienen restado el VMA', [], $distintos);
}
