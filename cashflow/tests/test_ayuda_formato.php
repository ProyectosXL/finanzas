<?php
/**
 * El formato de un articulo de la ayuda: el encabezado de datos, las secciones,
 * la plantilla de nueve y las anclas.
 *
 * Puro: trabaja sobre strings armados aca. Los articulos reales de Ayuda/ los
 * revisa test_ayuda_articulos.php con estas mismas reglas.
 */

require_once __DIR__ . '/../Class/AyudaArticulo.php';

/** Un articulo de pestana valido, con las nueve secciones */
function articuloDePrueba($cambios = [], $cuerpo = null) {
    $meta = array_merge([
        'titulo' => 'Llaves y Renov. Contratos',
        'pestana' => 'llaves_renov',
        'categoria' => 'RRHH y Operativos',
        'estado' => 'en_revision',
        'actualizado' => '2026-11-23',
        'usuarios' => 'Tesorería, Administración'
    ], $cambios);

    $txt = "---\n";

    foreach ($meta as $k => $v) {
        $txt .= $k . ': ' . $v . "\n";
    }

    $txt .= "---\n";

    if ($cuerpo === null) {
        foreach (AyudaArticulo::SECCIONES_PESTANA as $s) {
            $txt .= "## $s\n\nTexto de $s.\n\n";
        }
    } else {
        $txt .= $cuerpo;
    }

    return $txt;
}

$TABS = ['llaves_renov' => 'RRHH y Operativos', 'ventas' => 'Ingresos'];

seccion('el encabezado de datos');

$a = AyudaArticulo::parsear(articuloDePrueba());

chequear('se leen los campos', 'llaves_renov', $a['meta']['pestana']);
chequear('con acentos', 'Tesorería, Administración', $a['meta']['usuarios']);
chequear('sin errores de formato', [], $a['errores']);
chequear('nueve secciones', 9, count($a['secciones']));
chequear('un articulo valido no tiene errores', [], AyudaArticulo::validar($a, 'llaves_renov', $TABS, '2026-11-23'));
chequear('es de pestana', true, AyudaArticulo::esDePestana($a));

// Windows: los archivos del repo pueden llegar con CRLF y con BOM.
$crlf = AyudaArticulo::parsear("\xEF\xBB\xBF" . str_replace("\n", "\r\n", articuloDePrueba()));
chequear('CRLF y BOM se leen igual', [$a['meta'], 9, []],
    [$crlf['meta'], count($crlf['secciones']), $crlf['errores']]);

$sin = AyudaArticulo::parsear("## Para qué sirve\nhola\n");
chequear('sin encabezado es un error, y el cuerpo se lee igual', [true, 1],
    [count($sin['errores']) > 0, count($sin['secciones'])]);

$abierto = AyudaArticulo::parsear("---\ntitulo: x\n## Para qué sirve\n");
chequear('un encabezado sin cierre se avisa', true,
    strpos(implode(' ', $abierto['errores']), 'no cierra') !== false);

$raro = AyudaArticulo::parsear("---\ntitulo: x\ncolor: rojo\nsin dos puntos\n---\n## A\nb\n");
chequear('un campo que no existe y un renglon sin ":" se avisan', 2, count($raro['errores']));

$previo = AyudaArticulo::parsear("---\ntitulo: x\n---\nsuelto\n## A\nb\n");
chequear('el texto antes de la primera seccion se avisa', 1, count($previo['errores']));

seccion('las validaciones');

function erroresDe($cambios, $nombre = 'llaves_renov', $cuerpo = null) {
    global $TABS;

    return AyudaArticulo::validar(AyudaArticulo::parsear(articuloDePrueba($cambios, $cuerpo)),
        $nombre, $TABS, '2026-11-23');
}

chequear('estado desconocido', 1, count(erroresDe(['estado' => 'terminada'])));
chequear('los tres estados conocidos pasan', [[], [], []], [
    erroresDe(['estado' => 'completa']), erroresDe(['estado' => 'en_revision']),
    erroresDe(['estado' => 'pendiente'])]);
chequear('fecha que no existe', 1, count(erroresDe(['actualizado' => '2026-02-30'])));
chequear('fecha con otro formato', 1, count(erroresDe(['actualizado' => '23/11/2026'])));
chequear('fecha posterior a hoy', 1, count(erroresDe(['actualizado' => '2026-11-24'])));
chequear('falta un obligatorio', 1, count(erroresDe(['usuarios' => ''])));
chequear('pestana que no esta en el menu', true, count(erroresDe(['pestana' => 'inventada'], 'inventada')) > 0);
chequear('categoria distinta de la del menu', 1, count(erroresDe(['categoria' => 'Ingresos'])));
chequear('el archivo se llama como la pestana', 1, count(erroresDe([], 'llaves')));

$ocho = '';
foreach (array_slice(AyudaArticulo::SECCIONES_PESTANA, 0, 8) as $s) { $ocho .= "## $s\nx\n"; }
chequear('falta una seccion de la plantilla', 1, count(erroresDe([], 'llaves_renov', $ocho)));

$orden = AyudaArticulo::SECCIONES_PESTANA;
list($orden[0], $orden[1]) = [$orden[1], $orden[0]];
$desordenado = '';
foreach ($orden as $s) { $desordenado .= "## $s\nx\n"; }
chequear('las secciones fuera de orden', 1, count(erroresDe([], 'llaves_renov', $desordenado)));

$vacia = '';
foreach (AyudaArticulo::SECCIONES_PESTANA as $i => $s) {
    $vacia .= "## $s\n" . ($i === 5 ? "\n" : "x\n");
}
chequear('una seccion vacia', 1, count(erroresDe([], 'llaves_renov', $vacia)));

$noAplica = '';
foreach (AyudaArticulo::SECCIONES_PESTANA as $i => $s) {
    $noAplica .= "## $s\n" . ($i === 5 ? AyudaArticulo::NO_APLICA . "\n" : "x\n");
}
chequear('"no aplica" sin el porque', 1, count(erroresDe([], 'llaves_renov', $noAplica)));
chequear('"no aplica" con el porque pasa', [], erroresDe([], 'llaves_renov',
    str_replace(AyudaArticulo::NO_APLICA, AyudaArticulo::NO_APLICA . "\nNo tiene parámetros propios.", $noAplica)));

$general = AyudaArticulo::parsear(articuloDePrueba(['pestana' => '', 'categoria' => 'General'],
    "## Cómo leer el tablero\nx\n"));
chequear('un general no lleva la plantilla', [],
    AyudaArticulo::validar($general, 'general_tablero', $TABS, '2026-11-23'));
chequear('pero su nombre empieza con general_', 1,
    count(AyudaArticulo::validar($general, 'tablero', $TABS, '2026-11-23')));
chequear('y su categoria es General', 1, count(AyudaArticulo::validar(
    AyudaArticulo::parsear(articuloDePrueba(['pestana' => ''], "## A\nx\n")), 'general_x', $TABS)));

seccion('las anclas');

chequear('sin acentos, en minusculas y con guiones', 'por-que-no-estima-llave',
    AyudaArticulo::slug('¿Por qué no estima llave?'));
chequear('la eñe', 'ano-y-pestana', AyudaArticulo::slug('Año y PESTAÑA'));
chequear('un titulo sin letras no queda vacio', 'seccion', AyudaArticulo::slug('¿?'));

$conBloques = AyudaArticulo::parsear("---\ntitulo: x\n---\n"
    . "## Preguntas frecuentes\n### Ejemplo\n:::pregunta ¿Qué es un puente?\n### no es titulo\n:::\n"
    . ":::pregunta ¿Qué es un puente?\notra\n:::\n"
    . "## Glosario\n### Ejemplo\n");
$anclas = AyudaArticulo::anclas($conBloques);

chequear('secciones, subsecciones y preguntas, en orden', [
    'preguntas-frecuentes', 'ejemplo', 'que-es-un-puente', 'que-es-un-puente-2', 'glosario', 'ejemplo-2'],
    array_column($anclas, 'ancla'));
chequear('un ### adentro de un bloque no es un titulo', false,
    in_array('no es titulo', array_column($anclas, 'titulo'), true));
chequear('cada ancla sabe su renglon del archivo', 6, $anclas[2]['linea']);

seccion('las preguntas frecuentes');

$deTab = AyudaArticulo::parsear(articuloDePrueba([], "## Para qué sirve\n:::pregunta ¿Esta no?\n:::\n"
    . "## Preguntas frecuentes\n:::pregunta ¿Esta sí?\nsí\n:::\n"));
chequear('en un articulo de pestana, solo las de su seccion', ['¿Esta sí?'],
    array_column(AyudaArticulo::preguntas($deTab), 'titulo'));
chequear('en un general, todas', ['¿Esta no?', '¿Esta sí?'], array_column(AyudaArticulo::preguntas(
    AyudaArticulo::parsear(str_replace("pestana: llaves_renov", "pestana: ",
        articuloDePrueba([], "## A\n:::pregunta ¿Esta no?\n:::\n## B\n:::pregunta ¿Esta sí?\n:::\n")))), 'titulo'));

seccion('el normalizador es el del modulo');

chequear('sin acentos ni mayusculas', 'DOLAR FUTURO', AyudaArticulo::normalizar('dólar Futuro'));
chequear('sin cambiar el largo, para poder resaltar', mb_strlen('Ñandú ÁÉÍÓÚ'),
    mb_strlen(AyudaArticulo::normalizar('Ñandú ÁÉÍÓÚ')));
chequear('la fecha legible', '23/11/2026', AyudaArticulo::fechaLegible('2026-11-23'));
