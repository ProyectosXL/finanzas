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

/* ================================================================
   LA BASE HISTORICA: TRES RENGLONES, VALIDADOS EN EL SERVIDOR
   ================================================================ */
seccion('la base historica son exactamente tres renglones');

function renglonTF($mes, $ars, $usd, $fecha) {
    return ['mes' => $mes, 'importe_ars' => $ars, 'importe_usd' => $usd,
            'fecha_vencimiento' => $fecha];
}

function lanzaConTF($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

$tres = [
    renglonTF('2026-07', 300000, null, '2026-07-15'),
    renglonTF('2026-08', null, 1200.5, '2026-08-17'),
    renglonTF('2026-09', 270000, 800, '2026-09-15')
];

$b = TarjetasResumen::validarBase($tres);

chequear('tres renglones entran', ['2026-07', '2026-08', '2026-09'], array_keys($b['filas']));
chequear('con sus importes convertidos', [300000.0, null, 1200.5],
    [$b['filas']['2026-07']['ars'], $b['filas']['2026-08']['ars'], $b['filas']['2026-08']['usd']]);
chequear('sin avisos si el mes coincide con el vencimiento', [], $b['avisos']);

lanzaConTF('dos renglones: rechazado, y no entra ninguno', function () use ($tres) {
    TarjetasResumen::validarBase(array_slice($tres, 0, 2));
}, 'llegaron 2. No se cargó ningún resumen.');

lanzaConTF('cuatro renglones: rechazado', function () use ($tres) {
    TarjetasResumen::validarBase(array_merge($tres, [renglonTF('2026-10', 1, null, '2026-10-15')]));
}, 'llegaron 4');

lanzaConTF('un renglon sin importes: dice cual', function () use ($tres) {
    $t = $tres;
    $t[1] = renglonTF('2026-08', '', '', '2026-08-17');
    TarjetasResumen::validarBase($t);
}, 'Renglón 2: El resumen tiene que traer al menos uno');

lanzaConTF('un importe en cero: rechazado', function () use ($tres) {
    $t = $tres;
    $t[2] = renglonTF('2026-09', 0, null, '2026-09-15');
    TarjetasResumen::validarBase($t);
}, 'Renglón 3: El importe en pesos tiene que ser mayor a cero');

lanzaConTF('un mes repetido: rechazado', function () use ($tres) {
    $t = $tres;
    $t[2] = renglonTF('2026-08', 1, null, '2026-08-15');
    TarjetasResumen::validarBase($t);
}, 'viene dos veces');

lanzaConTF('un mes mal escrito', function () use ($tres) {
    $t = $tres;
    $t[0] = renglonTF('2026-13', 1, null, '2026-07-15');
    TarjetasResumen::validarBase($t);
}, 'Renglón 1: Mes inválido');

lanzaConTF('una fecha que no existe', function () use ($tres) {
    $t = $tres;
    $t[0] = renglonTF('2026-07', 1, null, '2026-02-30');
    TarjetasResumen::validarBase($t);
}, 'no existe en el calendario');

$conAviso = $tres;
$conAviso[0] = renglonTF('2026-07', 300000, null, '2026-08-02');

chequear('un vencimiento de otro mes avisa, no rechaza', true,
    strpos(implode(' ', TarjetasResumen::validarBase($conAviso)['avisos']), 'Renglón 1') === 0);

/* ================================================================
   EL RESUMEN SUELTO: LAS VALIDACIONES DE SIEMPRE, EN EL SERVIDOR
   ================================================================ */
seccion('el resumen suelto se valida en el servidor');

lanzaConTF('sin ningun importe no deja guardar, y dice por que', function () {
    TarjetasResumen::validarImportes('', null);
}, 'al menos uno de los dos importes');

chequear('con los dos importes entra', ['ars' => 450000.0, 'usd' => 320.5],
    TarjetasResumen::validarImportes('450000', '320.50'));

lanzaConTF('el periodo es obligatorio', function () {
    TarjetasResumen::validarMes('');
}, 'Mes inválido');

lanzaConTF('el vencimiento es obligatorio', function () {
    TarjetasResumen::validarFecha('');
}, 'no es una fecha válida');
