<?php
/**
 * El Centro de ayuda y el panel lateral: que articulo le toca a cada pestana,
 * que muestra el inicio, que muestra el panel y quien puede verlos.
 *
 * Puro salvo el menu, que se lee del codigo (Menu::estructuraCompleta()). Los
 * articulos se arman aca; los reales los revisa test_ayuda_articulos.php.
 */

require_once __DIR__ . '/../Class/Ayuda.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

$menu = Menu::estructuraCompleta();
$tabs = Ayuda::categoriasDeTabs($menu);

function artCentro($meta, $cuerpo) {
    $txt = "---\n";

    foreach ($meta as $k => $v) {
        $txt .= "$k: $v\n";
    }

    $art = AyudaArticulo::parsear($txt . "---\n" . $cuerpo);

    return ['art' => $art, 'categoria' => $meta['categoria'] ?? ''];
}

$plantilla = '';
foreach (AyudaArticulo::SECCIONES_PESTANA as $s) {
    $plantilla .= "## $s\nTexto de $s.\n"
        . ($s === 'Preguntas frecuentes' ? ":::pregunta ¿Por qué un local no estima llave?\nPorque…\n:::\n" : '');
}

$articulos = [
    'llaves_renov' => artCentro(['titulo' => 'Llaves y Renov. Contratos', 'pestana' => 'llaves_renov',
        'categoria' => 'RRHH y Operativos', 'estado' => 'en_revision', 'actualizado' => '2026-11-23',
        'usuarios' => 'Tesorería'], $plantilla),
    'general_tablero' => artCentro(['titulo' => 'Cómo leer el tablero', 'pestana' => '', 'categoria' => 'General',
        'estado' => 'completa', 'actualizado' => '2026-11-23', 'usuarios' => 'Todos', 'bajada' => 'Los **cuatro** números.'],
        "## Ingresos y egresos\nx\n## Preguntas frecuentes\n:::pregunta ¿Qué es el flujo neto?\nx\n:::\n"),
    'general_avisos' => artCentro(['titulo' => 'Los avisos', 'pestana' => '', 'categoria' => 'General',
        'estado' => 'en_revision', 'actualizado' => '2026-11-23', 'usuarios' => 'Todos'], "## A\nx\n")
];

seccion('que articulo le toca a cada pestana');

chequear('una pestana con archivo propio', 'llaves_renov', Ayuda::articuloDeTab('llaves_renov', $articulos));
chequear('el tablero abre el general del tablero', 'general_tablero', Ayuda::articuloDeTab('cashflow', $articulos));
chequear('Parametros, el de permisos y parametros', 'general_permisos_parametros',
    Ayuda::articuloDeTab('parametros', ['general_permisos_parametros' => 1]));
chequear('una pestana sin archivo no tiene', null, Ayuda::articuloDeTab('ventas', $articulos));
chequear('el Dashboard, que es maqueta, tampoco', null, Ayuda::articuloDeTab('dashboard', $articulos));
chequear('el Centro de ayuda no tiene panel', null, Ayuda::articuloDeTab('ayuda', ['ayuda' => 1]));
chequear('un nombre con ".." no es un articulo', null, Ayuda::articuloDeTab('../README', ['../README' => 1]));
chequear('nombreValido', [true, false, false],
    [Ayuda::nombreValido('general_tablero'), Ayuda::nombreValido('../x'), Ayuda::nombreValido('Llaves')]);

seccion('el inicio del Centro');

$c = Ayuda::centro($menu, $articulos);

chequear('los modulos son las categorias del menu, en su orden',
    array_column($menu['categorias'], 'nombre'), array_column($c['modulos'], 'nombre'));

$items = [];
foreach ($c['modulos'] as $m) {
    foreach ($m['items'] as $it) {
        $items[$it['tab']] = $it;
    }
}

chequear('solo las pestanas con datos: Haberes, pendiente en el menu, no esta', false, isset($items['haberes']));
chequear('Llaves, con su articulo y su estado', ['llaves_renov', 'en_revision', 'En revisión'],
    [$items['llaves_renov']['articulo'], $items['llaves_renov']['estado'], $items['llaves_renov']['etiqueta']]);
chequear('Ventas, sin archivo: Pendiente y sin articulo para abrir', [null, 'pendiente', 'Pendiente'],
    [$items['ventas']['articulo'], $items['ventas']['estado'], $items['ventas']['etiqueta']]);
chequear('los generales en el orden de Primeros pasos', ['general_tablero', 'general_avisos'],
    array_column($c['generales'], 'articulo'));
chequear('con la bajada ya renderizada', 'Los <strong>cuatro</strong> números.', $c['generales'][0]['bajada']);
chequear('las preguntas frecuentes son las del general del tablero', ['¿Qué es el flujo neto?'],
    array_column($c['preguntas'], 'titulo'));
chequear('con su direccion', '?ayuda=general_tablero#que-es-el-flujo-neto', $c['preguntas'][0]['enlace']);
chequear('y las cuatro busquedas frecuentes', AyudaBusqueda::FRECUENTES, $c['frecuentes']);

seccion('el panel lateral');

$p = Ayuda::panel('llaves_renov', $menu, $articulos, $tabs, '', '2026-11-23');

chequear('de un articulo de pestana: Para que sirve, De donde salen los datos y La pantalla',
    ['Para qué sirve', 'De dónde salen los datos', 'La pantalla'], array_column($p['secciones'], 'titulo'));
chequear('las preguntas, como enlaces al articulo completo', '?ayuda=llaves_renov#por-que-un-local-no-estima-llave',
    $p['preguntas'][0]['enlace']);
chequear('el estado y la fecha', ['en_revision', 'En revisión', '23/11/2026'], [$p['estado'], $p['etiqueta'], $p['actualizado']]);
chequear('los enlaces del pie', ['?ayuda=llaves_renov', '?ayuda='], [$p['completo'], $p['centro']]);
chequear('un articulo bien escrito no trae errores', [], $p['errores']);

$q = Ayuda::panel('llaves_renov', $menu, $articulos, $tabs, 'pantalla', '2026-11-23');
chequear('con un texto, cada seccion dice si coincide', [false, false, true], array_column($q['secciones'], 'coincide'));
chequear('y viene marcado', true, strpos($q['secciones'][2]['html'], 'La <mark>pantalla</mark>') !== false);
chequear('las preguntas tambien', false, $q['preguntas'][0]['coincide']);

$g = Ayuda::panel('cashflow', $menu, $articulos, $tabs);
chequear('de un general: todas sus secciones, sin repetir las preguntas abajo', [2, []],
    [count($g['secciones']), $g['preguntas']]);

$sin = Ayuda::panel('ventas', $menu, $articulos, $tabs);
chequear('una pestana sin articulo dice que no tiene, con su nombre', [false, 'Ventas', '?ayuda='],
    [$sin['encontrado'], $sin['titulo'], $sin['centro']]);

$roto = $articulos;
$roto['llaves_renov'] = artCentro(['titulo' => 'x', 'pestana' => 'llaves_renov', 'categoria' => 'RRHH y Operativos',
    'estado' => 'terminada', 'actualizado' => '2026-11-23', 'usuarios' => 'x'], $plantilla . ":::formula\nsin cerrar\n");
$pr = Ayuda::panel('llaves_renov', $menu, $roto, $tabs);
chequear('un articulo con errores se muestra igual, con los errores', [true, true],
    [$pr['encontrado'], count($pr['errores']) >= 2]);

seccion('el articulo completo');

$a = Ayuda::completo('llaves_renov', $articulos, $tabs, '2026-11-23');
chequear('las nueve secciones', 9, count($a['secciones']));
chequear('el indice tiene las secciones, no las preguntas', 9, count($a['indice']));
chequear('la ficha: usuarios y responsable', ['Tesorería', 'Proyectos e Innovación'], [$a['usuarios'], $a['responsable']]);
chequear('un articulo que no existe', null, Ayuda::completo('no_existe', $articulos, $tabs));
chequear('ni con un nombre armado para salir de la carpeta', null, Ayuda::completo('../README-cashflow', $articulos, $tabs));

seccion('quien ve el Centro de ayuda');

/* Todos los que entran al cashflow, sin permiso propio. Sin usuario, nadie: el
   menu anonimo sigue vacio (test_menu.php). */
chequear('la ayuda es de todos', true, AuthCashflow::esParaTodos('ayuda'));
chequear('las demas pestanas no', false, AuthCashflow::esParaTodos('cashflow'));
chequear('sus cuatro acciones son lecturas', ['getPanel', 'getCentro', 'getArticulo', 'buscar'],
    AuthCashflow::LECTURAS['Ayuda']);
chequear('y ninguna escribe', false, isset(AuthCashflow::ESCRITURAS['Ayuda']));
chequear('TabController la acepta', true,
    strpos(file_get_contents(__DIR__ . '/../Controller/TabController.php'), "'ayuda'") !== false);

$index = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
chequear('la direccion ?ayuda= la abre como pestana inicial', true,
    strpos($index, "if (isset(\$_GET['ayuda']) && AuthCashflow::puede('ayuda')) {\n    \$tabInicial = 'ayuda';") !== false);
chequear('index.php carga el JS y el CSS de la ayuda', true,
    strpos($index, 'Js/ayuda.js') !== false && strpos($index, 'Css/ayuda.css') !== false);

$main = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/main.js'));
chequear('fuera de la ayuda, la direccion deja de decir ?ayuda=', true,
    strpos($main, "if (tabName !== 'ayuda' && /[?&]ayuda=/.test(window.location.search)") !== false);
chequear('la pestana abierta queda en el body', true,
    strpos($main, "document.body.setAttribute('data-tab', tabName);") !== false);

seccion('el boton Ayuda y el panel lateral');

$header = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Components/header.php'));
$panelHtml = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Components/ayuda_panel.php'));
$ayudaJs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/ayuda.js'));
$ayudaCss = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Css/ayuda.css'));

chequear('el boton esta en el encabezado, antes de la fecha', true,
    strpos($header, 'id="btnAyudaPestana"') !== false
    && strpos($header, 'id="btnAyudaPestana"') < strpos($header, 'class="header-date"'));
chequear('con el signo de pregunta y el texto', true,
    strpos($header, '<i class="fas fa-circle-question"></i><span>Ayuda</span>') !== false);
chequear('index.php incluye el panel, fuera de #tabContent', true,
    strpos($index, "include 'Components/ayuda_panel.php'") > strpos($index, '<div id="tabContent">'));
chequear('es un offcanvas de Bootstrap a la derecha', true,
    strpos($panelHtml, 'class="offcanvas offcanvas-end ayuda-panel"') !== false);
chequear('sin fondo oscuro y sin bloquear el desplazamiento: la pestaña sigue a la vista', true,
    strpos($panelHtml, 'data-bs-backdrop="false" data-bs-scroll="true"') !== false);
chequear('con el buscador, el pie y el boton de cerrar', true,
    strpos($panelHtml, 'id="ayudaPanelBuscar"') !== false && strpos($panelHtml, 'Ver el artículo completo') !== false
    && strpos($panelHtml, 'Ir al Centro de ayuda') !== false && strpos($panelHtml, 'data-bs-dismiss="offcanvas"') !== false);
chequear('sabe la pestaña por el data-tab del body', true,
    strpos($ayudaJs, "return document.body.getAttribute('data-tab') || '';") !== false);
chequear('carga una vez por pestaña', true,
    strpos($ayudaJs, "if (cache[tab]) {\n            pintar(tab, cache[tab], '');") !== false);
chequear('sin articulo, lo dice y ofrece el Centro', true,
    strpos($ayudaJs, 'Esta pestaña todavía no tiene ayuda.') !== false);
chequear('en el Centro de ayuda el boton no se ve', true,
    strpos($ayudaCss, "body[data-tab=\"ayuda\"] .btn-ayuda-pestana {\n    display: none;") !== false);

seccion('el modal de ayuda de Comex se reemplazo por el boton comun');

chequear('el modal ya no existe', false, file_exists(__DIR__ . '/../Components/help_modal_comex.php'));

foreach (['proveedores_exterior', 'crono_nacionalizacion'] as $tabComex) {
    $srcTab = file_get_contents(__DIR__ . '/../Tabs/' . $tabComex . '.php');
    chequear($tabComex . ' no lo incluye ni tiene su boton flotante', false,
        strpos($srcTab, 'help_modal_comex') !== false || strpos($srcTab, 'btn-help-float') !== false);
    chequear($tabComex . ' tiene su articulo', true, file_exists(__DIR__ . '/../Ayuda/' . $tabComex . '.md'));
}

seccion('cada tarjeta de Primeros pasos lleva su icono');

$conIcono = Ayuda::centro($menu, $articulos)['generales'];
chequear('el del tablero, el de los avisos', ['fa-table-cells', 'fa-triangle-exclamation'],
    array_column($conIcono, 'icono'));
chequear('un general sin icono propio lleva el libro', Ayuda::ICONO_GENERAL,
    (Ayuda::centro($menu, ['general_nuevo' => artCentro(['titulo' => 'x', 'pestana' => '', 'categoria' => 'General',
        'estado' => 'completa', 'actualizado' => '2026-11-23', 'usuarios' => 'x'], "## A\nx\n")])['generales'][0]['icono']));
chequear('los cuatro generales tienen el suyo', [], array_values(array_diff(Ayuda::GENERALES, array_keys(Ayuda::ICONOS))));
chequear('y la tarjeta lo dibuja', true,
    strpos(file_get_contents(__DIR__ . '/../Js/ayuda.js'), '<span class="ayuda-tarjeta-icono" aria-hidden="true"><i class="fas \'') !== false);
