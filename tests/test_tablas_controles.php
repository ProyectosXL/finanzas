<?php
/**
 * Los dos controles compartidos de las tablas: el orden por encabezado
 * (Js/tabla-orden.js) y la exportacion a Excel (Js/tabla-export.js).
 *
 * POR QUE SE PRUEBAN LEYENDO ARCHIVOS
 * -----------------------------------
 * La logica de los dos vive en JavaScript y no hay corredor de JS en el
 * proyecto. Pero lo que se rompe en silencio de estos dos controles NO es su
 * logica: es el CABLEADO, y eso si se puede verificar leyendo los archivos.
 *
 *   - Un boton con data-exportar mal escrito no hace nada. No tira error, no
 *     avisa: se aprieta y no pasa nada, y desde la pantalla es indistinguible
 *     de una tabla vacia.
 *   - Una tabla sin `id` no entra en el descubrimiento automatico del orden, y
 *     el sintoma es que esa pestana -y solo esa- no se puede ordenar.
 *   - Si alguien vuelve a copiar exportarExcel() en una pestana, la copia
 *     vuelve a divergir, que es de donde salieron las cinco versiones distintas
 *     que este trabajo unifico.
 *
 * Es el mismo criterio que test_menu.php, que detecta los placeholders leyendo
 * el include de cada pestana.
 */

$TABS = __DIR__ . '/../cashflow/Tabs';
$JS = __DIR__ . '/../cashflow/Js';

/** Todo el texto de un archivo de la pestana */
function contenidoTab($ruta) {
    return file_get_contents($ruta);
}

$archivosTab = glob($TABS . '/*.php');

// ============================================================================
// Los dos componentes existen y estan cargados en index.php
// ============================================================================

seccion('los controles compartidos se cargan en index.php');

$index = file_get_contents(__DIR__ . '/../cashflow/index.php');

chequear('tabla-orden.js existe', true, file_exists($JS . '/tabla-orden.js'));
chequear('tabla-export.js existe', true, file_exists($JS . '/tabla-export.js'));

// Van en index.php y no en cada pestana: las pestanas llegan por AJAX, asi que
// el componente tiene que existir ANTES que ellas. Es el mismo motivo por el
// que estan ahi eje-vistas.js y columnas-fijas.js.
chequear('tabla-orden.js se carga en index.php', true,
    strpos($index, 'Js/tabla-orden.js') !== false);
chequear('tabla-export.js se carga en index.php', true,
    strpos($index, 'Js/tabla-export.js') !== false);

foreach (['eje-vistas.js', 'columnas-fijas.js', 'notificaciones.js'] as $previo) {
    chequear('y sigue cargandose ' . $previo, true,
        strpos($index, 'Js/' . $previo) !== false);
}

// ============================================================================
// main.js los reaplica cuando el MutationObserver ve una pestana nueva
// ============================================================================

seccion('main.js reaplica los dos controles');

$main = file_get_contents($JS . '/main.js');

chequear('main.js llama a OrdenTabla.reaplicar()', true,
    strpos($main, 'OrdenTabla.reaplicar()') !== false);
chequear('main.js llama a TablaExport.reaplicar()', true,
    strpos($main, 'TablaExport.reaplicar()') !== false);
chequear('y sigue llamando a ColumnasFijas.reaplicar()', true,
    strpos($main, 'ColumnasFijas.reaplicar()') !== false);

// El orden se aplica ANTES de medir: reordenar mueve filas, y medir una tabla
// que todavia va a cambiar da mal.
chequear('el orden se aplica antes de medir las columnas fijas', true,
    strpos($main, 'OrdenTabla.reaplicar()') < strpos($main, 'ColumnasFijas.reaplicar()'));

// ============================================================================
// Cada data-exportar apunta a una tabla que existe EN SU MISMA PESTANA
//
// Es la prueba que mas paga: un id mal escrito deja un boton que no hace nada.
// ============================================================================

seccion('cada boton de exportar apunta a una tabla que existe');

$botones = 0;

foreach ($archivosTab as $ruta) {
    $html = contenidoTab($ruta);
    $nombre = basename($ruta);

    if (!preg_match_all('/data-exportar="([^"]+)"/', $html, $m)) {
        continue;
    }

    foreach ($m[1] as $idTabla) {
        $botones++;

        chequear($nombre . ': el boton de ' . $idTabla . ' apunta a una tabla de la pestana',
            true, strpos($html, 'id="' . $idTabla . '"') !== false);
    }
}

chequear('hay botones declarados en el HTML', true, $botones > 0);

// ============================================================================
// Toda tabla tiene id: es la clave con la que el orden se descubre y se guarda
// ============================================================================

seccion('toda tabla de una pestana tiene id');

foreach ($archivosTab as $ruta) {
    $html = contenidoTab($ruta);
    $nombre = basename($ruta);

    if (!preg_match_all('/<table\b[^>]*>/', $html, $m)) {
        continue;
    }

    foreach ($m[0] as $tag) {
        chequear($nombre . ': ' . preg_replace('/\s+/', ' ', substr($tag, 0, 60))
            . ' tiene id', true, strpos($tag, 'id=') !== false);
    }
}

// ============================================================================
// No quedan copias de exportarExcel() dando vueltas
// ============================================================================

seccion('la exportacion no esta copiada en ninguna pestana');

$copias = [];

foreach (glob($JS . '/*.js') as $ruta) {
    if (basename($ruta) === 'tabla-export.js') {
        continue;
    }

    $js = file_get_contents($ruta);

    // El tipo MIME de Excel sale UNA sola vez en el modulo, y es en
    // tabla-export.js. Si aparece en otro lado, alguien volvio a copiar la
    // funcion y esa copia va a divergir como divergieron las cinco anteriores.
    if (strpos($js, 'application/vnd.ms-excel') !== false) {
        $copias[] = basename($ruta);
    }
}

chequear('el tipo MIME de Excel sale en un solo archivo', [], $copias);

// ============================================================================
// Las tablas que NO se ordenan lo declaran, y dicen por que
// ============================================================================

seccion('las tablas que no se ordenan lo declaran con data-orden="no"');

/* El orden se descubre solo, asi que una tabla donde ordenar seria enganioso
   tiene que declararlo. Son las tablas donde el ORDEN DE LAS FILAS ES EL DATO:
   el editor de la estructura -que se reordena con botones y donde un FLUJO_NETO
   suma las filas de arriba-, las que llevan una columna de acumulado y los
   formularios que se guardan y validan enteros. */
$sinOrden = [
    'parametros_estructura.php' => ['cfeTabla', 'cfeTablaSecciones'],
    'parametros.php' => ['tablaMix'],
    'parametros_cobranzas.php' => ['tablaEscalaCob'],
    'ventas.php' => ['tablaAcumulada', 'tablaBalance']
];

foreach ($sinOrden as $archivo => $tablas) {
    $html = contenidoTab($TABS . '/' . $archivo);

    foreach ($tablas as $id) {
        // El atributo tiene que estar en el MISMO tag que el id, si no no
        // aplica a esa tabla.
        $tieneAtributo = preg_match(
            '/<table\b[^>]*id="' . preg_quote($id, '/') . '"[^>]*data-orden="no"/', $html) === 1
            || preg_match(
                '/<table\b[^>]*data-orden="no"[^>]*id="' . preg_quote($id, '/') . '"/', $html) === 1;

        chequear($archivo . ': ' . $id . ' declara data-orden="no"', true, $tieneAtributo);
    }
}

// Y el tablero declara sus filas ancla, que es lo que lo hace ordenable sin
// romperse: los subtotales y las filas de arrastre significan lo que significan
// por donde estan.
seccion('el tablero declara sus filas ancla');

$cashflowJs = file_get_contents($JS . '/Cashflow.js');

chequear('Cashflow.js declara el control de orden', true,
    strpos($cashflowJs, "crearOrdenTabla") !== false);

foreach (['cf-seccion', 'cf-tipo-subtotal', 'cf-tipo-flujo_neto',
          'cf-tipo-saldo_inicial', 'cf-tipo-saldo_final'] as $ancla) {
    chequear('ancla ' . $ancla, true,
        strpos($cashflowJs, $ancla) !== false);
}
