<?php
/**
 * Class/Planilla.php y lo que le manda el navegador desde un Excel.
 *
 * Las tres importaciones -pagos y maestro de Proveedores Locales, y Cob.
 * Electronicos- leen el Excel en el navegador (Js/planilla-excel.js) y mandan
 * texto. Esto fija las tres cosas de las que depende que eso funcione igual que
 * el CSV de antes: que el parser entienda lo que llega, que el numero de linea
 * sea la fila del Excel, y que la plantilla y el parser salgan de la misma
 * definicion de columnas.
 */

require_once __DIR__ . '/../Class/Planilla.php';
require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/ProveedoresCategorias.php';
require_once __DIR__ . '/../Class/CobElectronicos.php';

seccion('los importes como los manda el navegador');

// Una celda numerica viaja con su valor: sin miles y con punto decimal.
chequear('un entero', 1069326.0, Planilla::numero('1069326'));
chequear('con decimales', 1069326.5, Planilla::numero('1069326.5'));
chequear('con centavos', 35257406.25, Planilla::numero('35257406.25'));
chequear('negativo', -1500.25, Planilla::numero('-1500.25'));
chequear('tres decimales no son miles: el navegador nunca manda miles', 1.648,
    Planilla::numero('1.648'));
chequear('el cero', 0.0, Planilla::numero('0'));
chequear('una celda de texto que no es numero sigue siendo null', null,
    Planilla::numero('mil pesos'));

seccion('las fechas como las manda el navegador');

chequear('una celda de fecha llega como aaaa-mm-dd', '2026-10-15', Planilla::fecha('2026-10-15'));
chequear('un fin de mes', '2026-02-28', Planilla::fecha('2026-02-28'));
chequear('un bisiesto', '2028-02-29', Planilla::fecha('2028-02-29'));
chequear('una fecha escrita como texto en la celda viaja tal cual y se entiende',
    '2026-10-15', Planilla::fecha('15/10/2026'));
chequear('el serial sigue aceptado, para una celda de fecha que llegue como numero',
    '2026-10-13', Planilla::fecha('46308'));
chequear('un dia que no existe es null', null, Planilla::fecha('2026-02-30'));

seccion('el numero de linea es la fila del Excel');

$columnas = Proveedores::columnasImportacion();

/* Encabezado en la fila 1, datos en la 2, una fila vacia en la 3, datos en la
   4, dos vacias, y datos en la 7. Antes las vacias se filtraban antes de
   numerar y la ultima salia como "linea 4". */
$texto = "COD_PROVEEDOR\tNRO_FACTURA\tFECHA_PAGO\n"
    . "MTDODI\tA0000500001731\t2026-10-15\n"
    . "\n"
    . "OGCOAN\tA0000300001234\t2026-10-20\n"
    . "\t\t\n"
    . "\n"
    . "OGCOAN\tA0000300009999\tel jueves\n";

$p = Planilla::parsear($texto, $columnas);

chequear('las filas vacias no son datos', 3, count($p['filas']));
chequear('y no corren la numeracion', [2, 4, 7], array_column($p['filas'], 'linea'));

// Filas vacias ARRIBA del encabezado: el navegador las manda como lineas
// vacias para que la numeracion siga siendo la del Excel.
$p2 = Planilla::parsear("\n\nCOD_PROVEEDOR\tNRO_FACTURA\tFECHA_PAGO\nX\tA1\t2026-10-15\n", $columnas);

chequear('con el encabezado en la fila 3, el primer dato es la fila 4', 4, $p2['filas'][0]['linea']);

// Un CSV de antes, sin vacias, numera exactamente igual que siempre.
$p3 = Planilla::parsear("COD_PROVEEDOR;NRO_FACTURA;FECHA_PAGO\r\nX;A1;15/10/2026\r\nY;A2;16/10/2026\r\n",
    $columnas);

chequear('un CSV sin vacias numera igual que antes', [2, 3], array_column($p3['filas'], 'linea'));
chequear('y el separador se sigue detectando', ';', $p3['separador']);

seccion('lo que manda el navegador: comillas y tabulaciones');

$p4 = Planilla::parsear("\"COD_PROVEEDOR\"\t\"NRO_FACTURA\"\t\"FECHA_PAGO\"\t\"OBSERVACION\"\n"
    . "\"MTDODI\"\t\"A0000500001731\"\t\"2026-10-15\"\t\"pagar; con \"\"cuidado\"\"\"\n", $columnas);

chequear('el separador es la tabulacion', "\t", $p4['separador']);
chequear('un ; adentro de una celda no parte la fila', 'pagar; con "cuidado"',
    $p4['filas'][0]['observacion']);

seccion('la plantilla y el parser salen de la misma definicion');

/* Para las tres importaciones: los titulos de la plantilla son EXACTAMENTE los
   de columnasImportacion() y en el mismo orden, cada columna tiene un tipo que
   el navegador sabe escribir, cada fila de ejemplo tiene una celda por
   columna, y la plantilla -tal como vuelve del Excel- entra por el parser sin
   errores de encabezado y con todas sus filas. */
$importaciones = [
    'pagos' => [Proveedores::columnasImportacion(), Proveedores::plantilla('2026-10-13')],
    'maestro' => [ProveedoresCategorias::columnasImportacion(), ProveedoresCategorias::plantilla()],
    'cob. electronicos' => [CobElectronicos::columnasImportacion(),
                            CobElectronicos::plantilla('2026-10-13')]
];

foreach ($importaciones as $nombre => $par) {
    list($cols, $plantilla) = $par;

    chequear($nombre . ': los titulos son los del parser', array_column(array_values($cols), 'titulo'),
        array_column($plantilla['columnas'], 'titulo'));

    $tiposOk = true;

    foreach ($plantilla['columnas'] as $c) {
        $tiposOk = $tiposOk && in_array($c['tipo'], Planilla::TIPOS, true);
    }

    chequear($nombre . ': cada columna tiene un tipo conocido', true, $tiposOk);

    $anchosOk = true;

    foreach ($plantilla['filas'] as $f) {
        $anchosOk = $anchosOk && count($f) === count($cols);
    }

    chequear($nombre . ': cada ejemplo trae una celda por columna', true, $anchosOk);

    // Cada columna declara un tipo en la definicion misma, no por defecto.
    $declarados = true;

    foreach ($cols as $c) {
        $declarados = $declarados && isset($c['tipo']);
    }

    chequear($nombre . ': el tipo esta declarado en columnasImportacion()', true, $declarados);

    $vuelta = implode("\t", array_column($plantilla['columnas'], 'titulo')) . "\n";

    foreach ($plantilla['filas'] as $f) {
        $vuelta .= implode("\t", array_map('strval', $f)) . "\n";
    }

    chequear($nombre . ': la plantilla vuelve a entrar por el parser',
        count($plantilla['filas']), count(Planilla::parsear($vuelta, $cols)['filas']));
}

// Las fechas de ejemplo de pagos van como fecha de verdad, no como texto.
$pagos = Proveedores::plantilla('2026-10-13');
$colFecha = array_search('FECHA_PAGO', array_column($pagos['columnas'], 'titulo'), true);

chequear('pagos: la fecha de ejemplo es una columna de fecha', 'fecha',
    $pagos['columnas'][$colFecha]['tipo']);
chequear('pagos: y va como aaaa-mm-dd, a una semana', '2026-10-20', $pagos['filas'][0][$colFecha]);

seccion('ya no queda ninguna plantilla CSV');

foreach (['Proveedores', 'ProveedoresCategorias', 'CobElectronicos', 'Planilla'] as $clase) {
    chequear($clase . ' no tiene plantillaCsv()', false, method_exists($clase, 'plantillaCsv'));
}
