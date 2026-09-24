<?php
/**
 * El indicador de carga compartido (Js/cargando.js + Css/cargando.css).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. VUELVE UNA COPIA LOCAL. Habia diecinueve copias del marcado y diez del
 *      CSS, y las pestanas sin copia propia se veian bien solo si antes se
 *      habia abierto otra. Una copia nueva en una pestana vuelve a abrir esa
 *      puerta.
 *
 *   2. UN LUGAR DEL INDICADOR QUE NADIE ENCIENDE. Si el JS de una pestana deja
 *      de llamar a Cargando para su contenedor, la pantalla carga sin decir
 *      nada, o se queda con el indicador para siempre.
 *
 *   3. SE PIERDE LA ACCESIBILIDAD. role="status" y aria-live son lo unico que
 *      le dice a un lector de pantalla que algo esta cargando.
 */

$RAIZ = __DIR__ . '/../cashflow/';

if (!function_exists('jsSinComentariosCAR')) {
    /** Un JS sin sus comentarios de bloque ni de linea completa */
    function jsSinComentariosCAR($ruta) {
        $s = file_get_contents($ruta);
        $s = preg_replace('#/\*.*?\*/#s', '', $s);

        return preg_replace('#^\s*//.*$#m', '', $s);
    }
}

seccion('El componente existe y se carga una sola vez');

$js = $RAIZ . 'Js/cargando.js';
$css = $RAIZ . 'Css/cargando.css';

chequear('Js/cargando.js existe', true, file_exists($js));
chequear('Css/cargando.css existe', true, file_exists($css));

$index = file_get_contents($RAIZ . 'index.php');

chequear('index.php carga el JS una vez', 1, substr_count($index, 'src="Js/cargando.js'));
chequear('y la hoja una vez', 1, substr_count($index, 'href="Css/cargando.css'));
chequear('el JS va antes de main.js, que lo usa en loadTab()',
    true, strpos($index, 'Js/cargando.js') < strpos($index, 'Js/main.js'));

seccion('La API');

$srcJs = jsSinComentariosCAR($js);

foreach (['mostrar', 'paso', 'ocultar'] as $f) {
    chequear('Cargando.' . $f . ' existe', 1, preg_match('/\b' . $f . ':\s*' . $f . '\b/', $srcJs));
}

foreach (['pendiente', 'en_curso', 'listo', 'error'] as $e) {
    chequear('conoce el estado ' . $e, 1, preg_match('/\b' . $e . ':\s*\{/', $srcJs));
}

chequear('avisa a los 10 segundos', 1, preg_match('/SEGUNDOS_DEMORA\s*=\s*10\b/', $srcJs));
chequear('con el texto pedido', true, strpos($srcJs, 'Esto puede tardar un poco más') !== false);
chequear('cuenta los segundos en vivo', true, strpos($srcJs, 'setInterval') !== false);
chequear('y corta el contador si la pestana se reemplazo', true, strpos($srcJs, 'isConnected') !== false);
chequear('tiene modo overlay', true, strpos($srcJs, 'cargando--overlay') !== false);
chequear('escapa el titulo y los pasos', 1, preg_match('/function esc\(/', $srcJs));

seccion('Accesible');

chequear('role="status"', true, strpos($srcJs, 'role="status"') !== false);
chequear('aria-live="polite"', true, strpos($srcJs, 'aria-live="polite"') !== false);
chequear('el contador de segundos no se anuncia', true,
    strpos($srcJs, 'cargando-tiempo" aria-hidden="true"') !== false);
chequear('marca el contenedor como ocupado', true, strpos($srcJs, "'aria-busy'") !== false);

$srcCss = file_get_contents($css);

chequear('respeta prefers-reduced-motion', true, strpos($srcCss, 'prefers-reduced-motion: reduce') !== false);
chequear('usa las variables de color del proyecto', true, strpos($srcCss, 'var(--primary-color') !== false);

seccion('Ninguna pestana trae su propia copia');

$slots = 0;
$ids = [];

foreach (glob($RAIZ . 'Tabs/*.php') as $f) {
    $s = file_get_contents($f);

    chequear(basename($f) . ' no usa .loading-spinner', false, strpos($s, 'loading-spinner') !== false);
    chequear(basename($f) . ' no dibuja un .spinner suelto', false, strpos($s, 'class="spinner"') !== false);

    if (preg_match_all('/<div class="cargando-slot" id="(\w+)" data-cargando="[^"]+"><\/div>/', $s, $m)) {
        $slots += count($m[1]);
        $ids = array_merge($ids, $m[1]);
    }

    /* Todo lugar del indicador tiene id y texto: sin el data-cargando, el
       titulo quedaria en el generico "Cargando…". */
    chequear(basename($f) . ': todo cargando-slot tiene id y texto',
        substr_count($s, 'cargando-slot'),
        preg_match_all('/<div class="cargando-slot" id="\w+" data-cargando="[^"]+"><\/div>/', $s));
}

chequear('los 26 lugares de las 19 pestanas', 26, $slots);

foreach (glob($RAIZ . 'Css/*.css') as $f) {
    if (basename($f) === 'cargando.css') {
        continue;
    }

    $sinComentarios = preg_replace('#/\*.*?\*/#s', '', file_get_contents($f));

    chequear(basename($f) . ' no redefine el spinner', 0,
        preg_match_all('/loading-spinner|\.spinner\b|@keyframes spin\b/', $sinComentarios));
}

seccion('Cada lugar lo enciende y lo apaga el JS de su pestana');

$todoJs = '';

foreach (glob($RAIZ . 'Js/*.js') as $f) {
    $todoJs .= jsSinComentariosCAR($f) . "\n";
}

foreach (array_unique($ids) as $id) {
    chequear($id . ': alguien lo muestra', true,
        strpos($todoJs, "Cargando.mostrar('" . $id . "'") !== false);
    chequear($id . ': alguien lo oculta', true,
        strpos($todoJs, "Cargando.ocultar('" . $id . "'") !== false);
    chequear($id . ': nadie le toca el display a mano', 0,
        preg_match_all("/getElementById\('" . $id . "'\)\.style\.display/", $todoJs));
}

seccion('El cambio de pestana usa el mismo componente');

$main = jsSinComentariosCAR($RAIZ . 'Js/main.js');

chequear('loadTab() usa Cargando', true, strpos($main, "Cargando.mostrar('cargandoPestana'") !== false);
chequear('y corta el contador antes de pisar el contenido', true,
    strpos($main, "Cargando.ocultar('cargandoPestana')") < strpos($main, "$('#tabContent').html(response)"));
chequear('ya no dibuja su propio spinner-border', false, strpos($main, 'spinner-border') !== false);

seccion('Las pestanas de varios pedidos informan sus pasos');

$ventas = jsSinComentariosCAR($RAIZ . 'Js/Ingresos-Ventas.js');

chequear('Ventas > Proyeccion declara sus dos pasos', true,
    strpos($ventas, "'Venta proyectada'") !== false && strpos($ventas, "'Cobranza proyectada'") !== false);
chequear('y los marca al resolverse', true, strpos($ventas, "Cargando.paso('loadingProyeccion'") !== false);

$compras = jsSinComentariosCAR($RAIZ . 'Js/Compras-Proyectadas.js');

chequear('Actualizar ahora declara el paso de la grilla', true,
    strpos($compras, "'Recalcular la grilla'") !== false);
chequear('y marca cada paso', true, strpos($compras, 'Cargando.paso(cont,') !== false);
chequear('la recarga va encima de la tabla, sin sacarla', true,
    strpos($compras, 'overlay: true') !== false);
