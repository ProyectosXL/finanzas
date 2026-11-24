<?php
/**
 * UNA FUNCION, UN NOMBRE, EN CADA BLOQUE DE Js/.
 *
 * En JavaScript dos "function x()" en el mismo bloque no dan error: gana la
 * ultima, en silencio, para todas las llamadas. Asi Logistica Local tuvo dos
 * celdaEje -la de cada fletero, de tres parametros, y la de los totales, de
 * uno- y cada celda del eje de cada fletero salia en guion, porque recibia al
 * fletero entero donde esperaba un numero (fix/cashflow-hallazgos-ayuda).
 *
 * Los archivos de Js/ arman cada modulo en un bloque "(function() { ... })()"
 * de primer nivel, y dos modulos del mismo archivo SI pueden repetir nombres
 * (ayuda.js: AyudaCentro y AyudaPanel tienen cada uno su el(), su buscar()...).
 * Por eso se cuenta por bloque: el archivo se corta en cada "})" de la columna
 * cero, y adentro de cada tramo se miran las funciones de primer nivel del
 * bloque (sangria de 4) y las del archivo (sangria 0).
 *
 * Lee el fuente normalizando CRLF: con core.autocrlf una regex con "\n" no
 * encuentra nada y la prueba pasaria sin mirar. No toca la base.
 */

/** Los nombres repetidos de un fuente, como "archivo: nombre (bloque N, xK)" */
function jsFuncionesRepetidas($archivo, $fuente) {
    $fuente = str_replace("\r\n", "\n", $fuente);
    $tramos = preg_split('/^\}\)[^\n]*$/m', $fuente);
    $repetidas = [];
    $deArchivo = [];

    foreach ($tramos as $i => $tramo) {
        preg_match_all('/^( *)function\s+([A-Za-z_$][\w$]*)\s*\(/m', $tramo, $m, PREG_SET_ORDER);
        $delBloque = [];

        foreach ($m as $x) {
            $sangria = strlen($x[1]);

            if ($sangria === 0) {
                $deArchivo[$x[2]] = ($deArchivo[$x[2]] ?? 0) + 1;
            } elseif ($sangria === 4) {
                $delBloque[$x[2]] = ($delBloque[$x[2]] ?? 0) + 1;
            }
        }

        foreach ($delBloque as $nombre => $n) {
            if ($n > 1) {
                $repetidas[] = $archivo . ': ' . $nombre . ' (bloque ' . ($i + 1) . ', x' . $n . ')';
            }
        }
    }

    foreach ($deArchivo as $nombre => $n) {
        if ($n > 1) {
            $repetidas[] = $archivo . ': ' . $nombre . ' (primer nivel, x' . $n . ')';
        }
    }

    return $repetidas;
}

seccion('la regla se prueba a si misma');

$dos = "(function() {\n    function a(x) {}\n    function a() {}\n})();\n";
chequear('dos iguales en un bloque se ven', ['x.js: a (bloque 1, x2)'], jsFuncionesRepetidas('x.js', $dos));

$separados = "var A = (function() {\n    function a() {}\n})();\nvar B = (function() {\n    function a() {}\n})();\n";
chequear('el mismo nombre en dos bloques es legitimo', [], jsFuncionesRepetidas('x.js', $separados));

chequear('y tambien con CRLF', ['x.js: a (bloque 1, x2)'],
    jsFuncionesRepetidas('x.js', str_replace("\n", "\r\n", $dos)));

seccion('ningun archivo de Js/ repite una funcion en el mismo bloque');

$archivos = glob(__DIR__ . '/../Js/*.js');
chequear('hay archivos que mirar', true, count($archivos) > 20);

$todas = [];

foreach ($archivos as $ruta) {
    $todas = array_merge($todas, jsFuncionesRepetidas(basename($ruta), file_get_contents($ruta)));
}

chequear('ninguna funcion declarada dos veces en el mismo bloque', [], $todas);

seccion('Logistica Local: la celda de cada fletero y la de los totales');

$logistica = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Logistica-Local.js'));

chequear('celdaEje se declara una sola vez',
    1, preg_match_all('/^\s*function celdaEje\(/m', $logistica));
chequear('y es la de cada fletero, con la columna',
    1, preg_match('/^\s*function celdaEje\(fletero, eje, columna\)/m', $logistica));
chequear('cada fila la llama con el fletero, el eje y la columna',
    1, preg_match('/celdaEje\(f, eje, c\)/', $logistica));
chequear('los totales tienen la suya',
    1, preg_match_all('/^\s*function celdaEjeTotal\(suma\)/m', $logistica));
chequear('el pie la usa',
    1, preg_match('/celdaEjeTotal\(suma\)/', substr($logistica, strpos($logistica, 'TOTALES'))));
chequear('y los totales arriba de cada fecha tambien',
    1, preg_match('/formato:\s*celdaEjeTotal,/', $logistica));
chequear('ninguna llamada de un argumento a celdaEje',
    0, preg_match('/celdaEje\(\s*[A-Za-z_$][\w$.]*\s*\)/', $logistica));
