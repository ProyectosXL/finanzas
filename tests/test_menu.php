<?php
/**
 * Menu lateral: estructura y estado de cada pestana.
 *
 * No toca la base. Si lee el disco -es como se detecta un placeholder- pero
 * contra los archivos del propio repo, asi que corre en cualquier maquina.
 */

require_once __DIR__ . '/../cashflow/Class/Menu.php';

$menu = Menu::estructura();

/* ================================================================
   Estructura
   ================================================================ */
seccion('estructura del menu');

chequear('el menu tiene las tres zonas',
    ['principales', 'categorias', 'pie'], array_keys($menu));

chequear('Cashflow es la primera pestana', 'cashflow', $menu['principales'][0]['tab']);

// Parametros al pie: no es un modulo de datos como los de arriba, es la
// configuracion de todos ellos.
chequear('Parametros esta al pie y no arriba', 'parametros', $menu['pie'][0]['tab']);

$arriba = array_map(function ($i) { return $i['tab']; }, $menu['principales']);

chequear('y no quedo tambien arriba', false, in_array('parametros', $arriba, true));

seccion('todos los items estan completos');

// Un item sin icono o sin estado dibuja un enlace roto. Se juntan TODOS los
// items de las tres zonas en una sola lista para no repetir el chequeo.
$todos = array_merge($menu['principales'], $menu['pie']);

foreach ($menu['categorias'] as $cat) {
    $todos = array_merge($todos, $cat['items']);
}

// 2 arriba + 24 en las seis categorias + 1 al pie
chequear('el menu tiene los 27 items', 27, count($todos));

$incompletos = [];

foreach ($todos as $item) {
    if (empty($item['tab']) || empty($item['nombre']) || empty($item['icono'])
        || empty($item['estado'])) {
        $incompletos[] = isset($item['tab']) ? $item['tab'] : '?';
    }
}

chequear('todos tienen tab, nombre, icono y estado', [], $incompletos);

$categoriasIncompletas = [];

foreach ($menu['categorias'] as $cat) {
    if (empty($cat['nombre']) || empty($cat['icono']) || empty($cat['codigo'])) {
        $categoriasIncompletas[] = isset($cat['codigo']) ? $cat['codigo'] : '?';
    }
}

chequear('y todas las categorias tambien', [], $categoriasIncompletas);

// Dos items con el mismo icono se confunden de un vistazo, que es justamente lo
// que los iconos vienen a evitar.
$iconos = array_map(function ($i) { return $i['icono']; }, $todos);
$repetidos = array_keys(array_filter(array_count_values($iconos), function ($n) {
    return $n > 1;
}));

chequear('ningun icono esta repetido entre dos pestanas', [], $repetidos);

seccion('no hay pestanas repetidas ni enlaces muertos');

$tabs = Menu::tabs();

chequear('ninguna pestana aparece dos veces en el menu',
    count($tabs), count(array_unique($tabs)));

// El menu no puede ofrecer una pestana que TabController rechaza: el enlace
// devolveria "Tab no valido" y el usuario veria un error.
$controller = file_get_contents(__DIR__ . '/../cashflow/Controller/TabController.php');
$fuera = [];

foreach ($tabs as $tab) {
    if (strpos($controller, "'" . $tab . "'") === false) {
        $fuera[] = $tab;
    }
}

chequear('todas las pestanas del menu estan aceptadas por TabController', [], $fuera);

/* ================================================================
   Los tres estados
   ================================================================ */
seccion('el estado de cada pestana');

$porTab = [];

foreach ($menu['principales'] as $i) { $porTab[$i['tab']] = $i; }
foreach ($menu['pie'] as $i) { $porTab[$i['tab']] = $i; }

foreach ($menu['categorias'] as $cat) {
    foreach ($cat['items'] as $i) { $porTab[$i['tab']] = $i; }
}

chequear('Cashflow tiene datos', Menu::DATOS, $porTab['cashflow']['estado']);
chequear('Ventas tiene datos', Menu::DATOS, $porTab['ventas']['estado']);
chequear('Saldos tiene datos', Menu::DATOS, $porTab['saldos']['estado']);
chequear('Cobranzas FR tiene datos', Menu::DATOS, $porTab['cobranzas_fr']['estado']);
chequear('Cobranzas May tiene datos', Menu::DATOS, $porTab['cobranzas_may']['estado']);
chequear('Cob. Electronicos tiene datos',
    Menu::DATOS, $porTab['cob_electronicos']['estado']);

// El caso que motivo tener tres estados y no dos: el Dashboard DIBUJA, asi que
// no es un placeholder, pero no tiene una sola llamada al servidor. Marcarlo
// como "con datos" seria peor que marcarlo como pendiente, porque tiene la
// forma de una pantalla terminada y numeros que parecen reales.
chequear('el Dashboard esta marcado como maqueta', Menu::MAQUETA, $porTab['dashboard']['estado']);
chequear('y su tooltip lo dice',
    true, strpos($porTab['dashboard']['titulo'], 'de ejemplo') !== false);

chequear('Echeqs tiene datos', Menu::DATOS, $porTab['echeqs']['estado']);
chequear('Haberes esta pendiente', Menu::PENDIENTE, $porTab['haberes']['estado']);

// Una pestana con datos NO se marca: marcar el caso normal es ruido.
chequear('una pestana con datos no lleva marca', '', $porTab['ventas']['icono_estado']);
chequear('ni tooltip', '', $porTab['ventas']['titulo']);
chequear('una pendiente si lleva marca', true, $porTab['haberes']['icono_estado'] !== '');

seccion('el titulo de la pagina sale del menu');

// El encabezado lo lee main.js del enlace del menu: no hay otra lista de
// nombres. Por defecto es el nombre del menu; cuando ese va abreviado para
// entrar en el sidebar, el encabezado va completo.
foreach ($porTab as $tab => $item) {
    if (empty($item['encabezado'])) {
        chequear('toda pestana tiene encabezado: ' . $tab, true, false);
    }
}

chequear('por defecto es el nombre del menu', 'Exportaciones Tasky', $porTab['exportaciones_tasky']['encabezado']);
chequear('Cobranzas FR va completo', 'Cobranzas Franquicias', $porTab['cobranzas_fr']['encabezado']);
chequear('Cobranzas May tambien', 'Cobranzas Mayoristas', $porTab['cobranzas_may']['encabezado']);
chequear('y Cob. Electronicos', 'Cobranzas Electrónicas', $porTab['cob_electronicos']['encabezado']);

seccion('el placeholder se detecta y baja el estado declarado');

// Es la guarda que evita que el menu prometa datos que no existen. Va en una
// sola direccion: nunca SUBE un estado, solo lo baja.
chequear('una pestana placeholder se detecta', true, Menu::esPlaceholder('haberes'));
chequear('una pestana construida no', false, Menu::esPlaceholder('ventas'));
chequear('Echeqs ya no es un placeholder', false, Menu::esPlaceholder('echeqs'));
chequear('Cobranzas May tampoco', false, Menu::esPlaceholder('cobranzas_may'));
chequear('una pestana que no existe cuenta como pendiente',
    true, Menu::esPlaceholder('no_existe_esta_pestana'));

chequear('declarar datos sobre un placeholder no alcanza: baja a pendiente',
    Menu::PENDIENTE, Menu::estado('haberes', Menu::DATOS));
chequear('declarar maqueta sobre un placeholder tambien baja',
    Menu::PENDIENTE, Menu::estado('haberes', Menu::MAQUETA));
chequear('sobre una pestana construida manda lo declarado',
    Menu::DATOS, Menu::estado('ventas', Menu::DATOS));
chequear('y una maqueta construida queda como maqueta',
    Menu::MAQUETA, Menu::estado('dashboard', Menu::MAQUETA));
chequear('un estado inventado se trata como pendiente',
    Menu::PENDIENTE, Menu::estado('ventas', 'CUALQUIERA'));

seccion('el contador de avance de cada categoria');

$porCategoria = [];

foreach ($menu['categorias'] as $cat) {
    $porCategoria[$cat['codigo']] = $cat;
}

// Ventas, Saldos, Echeqs, Cobranzas FR, Cobranzas May, Cob. Electronicos y
// Exportaciones Tasky tienen datos: la categoria esta completa, 7 de 7. El
// contador cuenta SOLO 'datos', que es lo que lo hace confiable: una pestana
// en construccion no sumaria.
chequear('Ingresos tiene sus 7 pestanas con datos', 7, $porCategoria['Ingresos']['con_datos']);
chequear('y son 7 en total', 7, $porCategoria['Ingresos']['total']);

// Otros Ingresos es la categoria de lo que se carga a mano: hoy son dos
// conceptos -dolares en cuenta comitente y saldo de inversiones-, los dos con
// datos. La categoria quedo armada para que sumar uno fuera agregar una
// pestana, y el segundo lo confirma.
chequear('Otros Ingresos existe', true, isset($porCategoria['OtrosIngresos']));
chequear('con sus dos pestanas', 2, $porCategoria['OtrosIngresos']['total']);
chequear('las dos con datos', 2, $porCategoria['OtrosIngresos']['con_datos']);
chequear('arranca cerrada', false, $porCategoria['OtrosIngresos']['abierta']);

// Va DESPUES de Ingresos: la categoria agrupa lo que se tipea, y leerlo
// pegado a lo que sale de un circuito es lo que hace que la diferencia se vea.
$orden = array_column($menu['categorias'], 'codigo');

chequear('Otros Ingresos va justo despues de Ingresos',
    array_search('Ingresos', $orden) + 1, array_search('OtrosIngresos', $orden));
// Comercio Exterior queda completa: sus dos pestanas tienen datos. Despachante
// y Asesor se dio de baja del menu porque no se usa mas.
chequear('Comex tiene sus 2 pestanas con datos', 2, $porCategoria['Comex']['con_datos']);
chequear('y son 2 en total, ya sin Despachante', 2, $porCategoria['Comex']['total']);
// Proveedores Locales ya tiene datos: sale de Tango (CPA04 + CPA54 + CPA01).
// Cronograma y Logistica Local siguen siendo maquetas.
chequear('Proveedores tiene 1 pestana con datos', 1, $porCategoria['Proveedores']['con_datos']);
chequear('y 3 en total', 3, $porCategoria['Proveedores']['total']);

// El contador cuenta SOLO las que tienen datos: una maqueta no cuenta, que es
// lo que hace que el numero sea confiable.
chequear('contarConDatos no cuenta maquetas ni pendientes', 1, Menu::contarConDatos([
    ['estado' => Menu::DATOS],
    ['estado' => Menu::MAQUETA],
    ['estado' => Menu::PENDIENTE]
]));
chequear('una lista vacia da cero', 0, Menu::contarConDatos([]));

seccion('la primera categoria abre y el resto no');

chequear('Ingresos abre por defecto', true, $porCategoria['Ingresos']['abierta']);
chequear('Financiero no', false, $porCategoria['Financiero']['abierta']);
