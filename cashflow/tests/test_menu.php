<?php
/**
 * Menu lateral: estructura y estado de cada pestana.
 *
 * No toca la base. Si lee el disco -es como se detecta un placeholder- pero
 * contra los archivos del propio repo, asi que corre en cualquier maquina.
 */

require_once __DIR__ . '/../Class/Menu.php';

/* SIN FILTRAR POR PERMISOS, y es a propósito.
   Esta prueba mide la ESTRUCTURA del menú, que es un hecho del código.
   estructura() devuelve lo que puede ver el usuario de la sesión, y por línea de
   comandos no hay sesión: devolvería un menú vacío y esto fallaría entero sin
   que hubiera nada roto. El filtrado por permisos tiene su propia sección, al
   pie de este archivo. */
$menu = Menu::estructuraCompleta();

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

// 2 arriba + 19 en las cinco categorias + 1 al pie. Eran 26 hasta que se
// sacaron Cronograma, Seguros, Bopreal y Pagos Div. Marzo.
chequear('el menu tiene los 22 items', 22, count($todos));

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
$controller = file_get_contents(__DIR__ . '/../Controller/TabController.php');
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

// Otros Ingresos era la categoria de lo que se carga a mano: las fotos del
// saldo invertido y de los dolares de la cuenta comitente. Desde que esos son
// cuentas de fondo de Saldos (Saldos -> Fondos), las dos pestanas se
// ELIMINARON: una pantalla que abre y guarda, y cuyo numero no va a ningun
// lado, confunde aunque lleve un cartel. Ni la categoria ni los archivos
// existen; las tablas si, por el historico.
chequear('Otros Ingresos ya no esta en el menu', false, isset($porCategoria['OtrosIngresos']));
chequear('ni sus pestanas', false,
    isset($porTab['saldo_inversiones']) || isset($porTab['dolares_comitente']));
chequear('y sus archivos no existen', false,
    file_exists(__DIR__ . '/../Tabs/saldo_inversiones.php')
    || file_exists(__DIR__ . '/../Tabs/dolares_comitente.php'));

// Sin archivo, TabController cae al placeholder: el menu tampoco puede
// ofrecerlas, o prometeria una pantalla que muestra "en construccion".
chequear('para el menu son pendientes, por si alguien las vuelve a listar',
    Menu::PENDIENTE, Menu::estado('saldo_inversiones', Menu::DATOS));

$orden = array_column($menu['categorias'], 'codigo');
// Comercio Exterior queda completa: sus TRES pestanas tienen datos. Despachante
// y Asesor se dio de baja del menu porque no se usa mas. La tercera es Compras
// Proyectadas: el mismo circuito mirado un paso antes, cuando la compra todavia
// no tiene contenedor cargado.
chequear('Comex tiene sus 3 pestanas con datos', 3, $porCategoria['Comex']['con_datos']);
chequear('y son 3 en total, ya sin Despachante', 3, $porCategoria['Comex']['total']);
// Proveedores Locales ya tiene datos: sale de Tango (CPA04 + CPA54 + CPA01).
// Las DOS tienen datos: Logistica Local dejo de ser un placeholder y proyecta
// los pagos a los fleteros. Cronograma se saco del menu.
chequear('Proveedores tiene sus 2 pestanas con datos',
    2, $porCategoria['Proveedores']['con_datos']);
chequear('y 2 en total, ya sin Cronograma', 2, $porCategoria['Proveedores']['total']);
chequear('Logistica Local ya no es un placeholder',
    false, Menu::esPlaceholder('logistica_local'));
chequear('y el menu la declara con datos',
    Menu::DATOS, $porTab['logistica_local']['estado']);
chequear('RRHH y Operativos son 4, ya sin Seguros', 4, $porCategoria['RRHH']['total']);
chequear('Financiero son 3, ya sin Bopreal ni Pagos Div. Marzo',
    3, $porCategoria['Financiero']['total']);

/* PAGOS CON TARJETAS Y OTROS DEJO DE SER UN PLACEHOLDER, y las dos comprobaciones
   hacen falta: el estado declarado es 'datos', pero Menu::estado() lo BAJA a
   'pendiente' si el archivo todavia incluye Components/tab_placeholder.php. Con
   solo la segunda, una pestana declarada con datos y todavia sin construir pasaria
   igual; con solo la primera, no se estaria midiendo lo que el menu muestra. */
chequear('Pagos con Tarjetas ya no es un placeholder',
    false, Menu::esPlaceholder('pagos_tarjetas'));
chequear('y el menu la declara con datos',
    Menu::DATOS, $porTab['pagos_tarjetas']['estado']);

/* Y LAS OTRAS DOS DE LA CATEGORIA SIGUEN PENDIENTES: son otros circuitos y no se
   tocaron. Si alguna se marcara con datos de arrastre, el contador de la categoria
   mentiria. */
chequear('Otros Socios sigue pendiente', Menu::PENDIENTE, $porTab['otros_socios']['estado']);
chequear('y Prestamos tambien', Menu::PENDIENTE, $porTab['prestamos']['estado']);

chequear('asi que Financiero pasa a 1 de 3 con datos',
    1, $porCategoria['Financiero']['con_datos']);

/* LAS CUATRO PESTANAS EN DESUSO NO VUELVEN. Eran placeholders sin datos, sin
   proveedor y sin tabla: se fueron del menu, de TabController y del disco. El
   chequeo contra TabController busca el codigo entre comillas, igual que el de
   "enlaces muertos" de arriba, asi que 'crono_nacionalizacion' no lo confunde
   con 'cronograma'. nacionalizacion_2 era configuracion muerta en
   TabController -sin archivo ni menu- y se fue con ellas. */
$retiradas = ['cronograma', 'seguros', 'bopreal', 'pagos_div'];

chequear('las cuatro retiradas ya no estan en el menu', [],
    array_values(array_intersect($retiradas, $tabs)));

$enController = [];
$enDisco = [];

foreach (array_merge($retiradas, ['nacionalizacion_2']) as $tab) {
    if (strpos($controller, "'" . $tab . "'") !== false) {
        $enController[] = $tab;
    }

    if (file_exists(__DIR__ . '/../Tabs/' . $tab . '.php')) {
        $enDisco[] = $tab;
    }
}

chequear('ni en TabController', [], $enController);
chequear('ni sus archivos en Tabs/', [], $enDisco);
chequear('y Proveedores Locales, que tiene el cronograma de pagos, sigue',
    Menu::DATOS, $porTab['proveedores_locales']['estado']);

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

/* ================================================================
   EL FILTRADO POR PERMISOS

   Es la otra mitad del menu, y va aparte de todo lo de arriba porque contesta
   otra pregunta: no "que pestanas tiene el modulo" sino "cuales puede ver ESTE
   usuario". Depende de la sesion, y por linea de comandos no hay ninguna.

   POR LINEA DE COMANDOS EL RESULTADO ES EL MENU VACIO, y eso es lo correcto:
   sin sesion AuthCashflow no tiene usuario, asi que puede() deniega todo y
   TabController responde 403. FALLA CERRADA. Esta prueba existe para que ese
   comportamiento quede fijado: el dia que alguien invierta la guarda y el menu
   anonimo pase a mostrarlo todo, esto lo dice.

   La prueba corre igual en una maquina que no tenga el modulo Gestionusuarios
   al lado -donde vive el padron de usuarios- porque el resultado es el mismo
   por las dos vias: sin padron tampoco hay usuario.
   ================================================================ */
seccion('el menu filtrado por permisos');

require_once __DIR__ . '/../Class/AuthCashflow.php';

if (AuthCashflow::estaAutenticado()) {
    // En una maquina con sesion abierta el filtrado devuelve lo permitido, que
    // depende del rol: no hay un numero fijo que chequear.
    Pruebas::saltear('hay una sesion abierta: el menu filtrado depende del rol');
} else {
    $filtrado = Menu::estructura();

    chequear('sin sesion no se ve ninguna pestana principal', 0, count($filtrado['principales']));
    chequear('ni ninguna categoria', 0, count($filtrado['categorias']));
    chequear('ni Parametros al pie', 0, count($filtrado['pie']));

    chequear('puede() deniega una pestana que existe', false, AuthCashflow::puede('cashflow'));
    chequear('y tambien la nueva', false, AuthCashflow::puede('logistica_local'));
    chequear('el usuario es null', null, AuthCashflow::usuario());
    chequear('y no es admin', false, AuthCashflow::esAdmin());
}

/* LA ESTRUCTURA COMPLETA NO SE FILTRA NUNCA. Si alguien le pusiera el filtro,
   todo lo de arriba pasaria a medir permisos sin que el nombre lo diga. */
chequear('estructuraCompleta no depende de la sesion',
    22, count(Menu::estructuraCompleta()['principales'])
        + count(Menu::estructuraCompleta()['pie'])
        + array_sum(array_map(function ($c) { return count($c['items']); },
                              Menu::estructuraCompleta()['categorias'])));
