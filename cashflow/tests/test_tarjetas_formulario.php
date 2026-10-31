<?php
/**
 * El formulario de carga de resumenes y lo que el servidor valida de el.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL IMPORTE TIPEADO CON MILES SE LEE MAL. '1.500' son mil quinientos y no
 *      uno y medio; '1.234.567,89' tiene que llegar al servidor como
 *      1234567.89. Un error aca no falla: guarda un resumen mil veces mas chico.
 *
 *   2. LA BASE HISTORICA ENTRA CON DOS RENGLONES. Con dos resumenes la tarjeta
 *      estima un promedio distinto sin que nadie se entere: la grilla es de tres
 *      y el servidor lo exige, no solo la pantalla.
 *
 * Todo lo de aca es PURO, salvo el JS, que corre con cscript.
 */

require_once __DIR__ . '/../Class/TarjetasResumen.php';

/* ================================================================
   EL FORMATO DE LOS IMPORTES, EN EL NAVEGADOR
   ================================================================ */
seccion('el importe se tipea con el formato del modulo');

$wsfImporte = realpath(__DIR__ . '/js/importe_formulario.wsf');
$cscriptImporte = (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec'))
    ? trim((string) @shell_exec('where cscript 2>NUL')) : '';

if ($wsfImporte === false) {
    chequear('existe tests/js/importe_formulario.wsf', true, false);
} elseif ($cscriptImporte === '') {
    Pruebas::saltear('no hay cscript en esta maquina');
} else {
    $salidaImporte = trim((string) shell_exec('cscript //nologo ' . escapeshellarg($wsfImporte) . ' 2>&1'));
    $lineasImporte = preg_split('/\r?\n/', $salidaImporte);
    $ultimaImporte = trim(end($lineasImporte));

    if (!preg_match('/^OK: (\d+)\s+FALLAS: (\d+)$/', $ultimaImporte, $mImporte)) {
        chequear('importe_formulario.wsf corre', 'OK: n   FALLAS: 0', $ultimaImporte);
    } else {
        chequear('importe_formulario.wsf: hay casos', true, intval($mImporte[1]) > 0);
        chequear('importe_formulario.wsf: sin fallas', 0, intval($mImporte[2]));
    }
}

$notiJs = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../Js/notificaciones.js'));

chequear('Notificacion expone el formulario', true,
    strpos($notiJs, 'formulario: formulario,') !== false);
chequear('y convierte los importes antes de devolverlos', true,
    strpos($notiJs, 'var n = parsearImporte(v);') !== false);
