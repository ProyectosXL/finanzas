<?php
/**
 * LA REGLA DE MANTENIMIENTO DE LA AYUDA, sobre los articulos reales de Ayuda/.
 *
 * Una ayuda desactualizada es peor que no tener ayuda: dice con seguridad algo
 * que ya no es cierto. Esta prueba no puede leer el texto y saber si sigue
 * siendo verdad -eso lo hace quien cambia la pantalla, en la misma rama; ver
 * "La ayuda funcional" en README-cashflow.md-, pero si puede impedir lo que
 * hace que una ayuda se pudra sin que nadie lo note:
 *
 *   - COBERTURA: cada pestaña en 'datos' del menu tiene su articulo, o esta en
 *     la lista PENDIENTES de abajo. Una pestaña nueva sin ayuda hace fallar la
 *     suite hasta que alguien escribe el articulo o la anota como pendiente A
 *     PROPOSITO. Y una pendiente que ya tiene articulo tambien falla: la lista
 *     no puede quedar vieja.
 *   - FORMATO: las nueve secciones en orden, el encabezado valido, los enlaces
 *     "ayuda:" a articulos y secciones que existen, las imagenes en el disco y
 *     con texto alternativo, y ningun bloque sin cerrar.
 *
 * No toca la base.
 */

require_once __DIR__ . '/../Class/Ayuda.php';

/**
 * Las pestañas en 'datos' que TODAVIA NO TIENEN AYUDA, a proposito.
 *
 * Sacar una de aca es la otra mitad de escribir su articulo. Agregar una es
 * decir, en un commit que alguien revisa, que esa pestaña sale sin ayuda.
 */
const AYUDA_PENDIENTES = [
    'ventas', 'saldos', 'cobranzas_fr',
    'compras_proyectadas',
    'proveedores_locales',
    'alquileres'
];

$menu = Menu::estructuraCompleta();
$tabs = Ayuda::categoriasDeTabs($menu);
$articulos = Ayuda::cargar();
$hoy = date('Y-m-d');

seccion('la carpeta de la ayuda');

chequear('Ayuda/ existe', true, is_dir(Ayuda::DIR));
chequear('y Ayuda/img/ para las capturas', true, is_dir(Ayuda::DIR . '/img'));

$sueltos = [];

foreach (glob(Ayuda::DIR . '/*') as $ruta) {
    $base = basename($ruta);

    if ($base !== 'img' && !preg_match('/^[a-z0-9_]+\.md$/', $base)) {
        $sueltos[] = $base;
    }
}

chequear('solo articulos .md con nombre valido, y la carpeta img', [], $sueltos);

seccion('cobertura: cada pestaña con datos tiene ayuda o esta pendiente a proposito');

$sinAyuda = [];
$pendientesConAyuda = [];

foreach (Ayuda::tabsConDatos($menu) as $tab) {
    if (in_array($tab, Ayuda::SIN_PANEL, true)) {
        continue;
    }

    $tiene = Ayuda::articuloDeTab($tab, $articulos) !== null;

    if (!$tiene && !in_array($tab, AYUDA_PENDIENTES, true)) {
        $sinAyuda[] = $tab;
    }

    if ($tiene && in_array($tab, AYUDA_PENDIENTES, true)) {
        $pendientesConAyuda[] = $tab;
    }
}

chequear('ninguna pestaña con datos queda sin articulo ni anotada como pendiente', [], $sinAyuda);
chequear('ninguna pendiente tiene ya su articulo (sacala de AYUDA_PENDIENTES)', [], $pendientesConAyuda);

$fantasmas = array_values(array_diff(AYUDA_PENDIENTES, Ayuda::tabsConDatos($menu)));
chequear('la lista de pendientes solo nombra pestañas con datos del menu', [], $fantasmas);

$huerfanos = [];

foreach ($articulos as $nombre => $a) {
    if (AyudaArticulo::esDePestana($a['art']) && !isset($tabs[$a['art']['meta']['pestana'] ?? ''])) {
        $huerfanos[] = $nombre;
    }
}

chequear('ningun articulo de una pestaña que ya no esta en el menu', [], $huerfanos);

seccion('formato de cada articulo');

foreach ($articulos as $nombre => $a) {
    chequear($nombre . ': sin errores de formato, plantilla, enlaces ni bloques', [],
        Ayuda::errores($nombre, $a['art'], $articulos, $tabs, $hoy));
}

seccion('las imagenes existen y tienen texto alternativo');

$faltan = [];
$fueraDeSuCarpeta = [];

foreach ($articulos as $nombre => $a) {
    foreach (AyudaRender::articulo($a['art'])['imagenes'] as $img) {
        if (!file_exists(Ayuda::DIR . '/' . $img['ruta'])) {
            $faltan[] = $nombre . ': ' . $img['ruta'];
        }

        // Cada articulo, sus capturas: img/<articulo>/. Asi borrar un articulo
        // se lleva sus imagenes y no quedan sueltas.
        if (strpos($img['ruta'], 'img/' . $nombre . '/') !== 0) {
            $fueraDeSuCarpeta[] = $nombre . ': ' . $img['ruta'];
        }
    }
}

chequear('toda imagen citada existe', [], $faltan);
chequear('y esta en la carpeta de su articulo', [], $fueraDeSuCarpeta);
// El texto alternativo vacio ya es un error del renderizador: ver el formato.

// Los generales que abre una pestaña (Ayuda::ARTICULO_DE_TAB) los cubre la
// cobertura: el tablero y Parametros son pestañas con datos como las demas.
