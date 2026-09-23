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

// ============================================================================
// EL ORDEN CON EL QUE ABRE UNA TABLA
//
// Son tres piezas que sólo sirven juntas: la opcion en el control, el nombre
// estable en el <th> y la declaracion en la pestana. Si alguna se cae, la tabla
// abre sin orden y no avisa.
// ============================================================================

seccion('el orden por defecto de Cobranzas FR');

$ordenJs = file_get_contents($JS . '/tabla-orden.js');
$frJs = file_get_contents($JS . '/Ingresos-Cobranzas_fr.js');
$frTab = contenidoTab($TABS . '/cobranzas_fr.php');

chequear('tabla-orden.js acepta porDefecto', true,
    strpos($ordenJs, 'defaultDeOpciones') !== false);

// NO por api.usar(): ese metodo ESCRIBE en localStorage, asi que un default
// aplicado por ahi seria indistinguible de una eleccion del usuario y le
// pisaria el orden que eligio a mano.
chequear('el default no se guarda en localStorage', false,
    strpos($ordenJs, 'guardar(clave, defaultDeOpciones') !== false);

chequear('Cobranzas FR declara el orden con el que abre', true,
    strpos($frJs, 'porDefecto: { columna: \'cobro\', dir: \'asc\' }') !== false);

// El <th> cambia de rotulo segun la solapa -"Cobro" / "F. Prob. Cobro"- y es la
// misma columna. Sin el nombre estable, el default apuntaria a un rotulo y no
// valdria en la otra solapa, y el orden elegido a mano se perderia al cambiar.
chequear('tabla-orden.js lee data-orden-nombre del th', true,
    strpos($ordenJs, "getAttribute('data-orden-nombre')") !== false);

chequear('la columna de cobro de FR declara su nombre estable', true,
    preg_match('/<th\b[^>]*id="thCobroCob"[^>]*data-orden-nombre="cobro"/', $frTab) === 1);

// Los dos rotulos que el JS le pone a esa misma columna.
foreach (["'F. Prob. Cobro'", "'Cobro'"] as $rotulo) {
    chequear('el JS sigue rotulando la columna como ' . $rotulo, true,
        strpos($frJs, 'thCobro.textContent = ' . $rotulo) !== false);
}

// El default no necesita saber que existe el Resumen: ahi la columna esta
// oculta por CSS y columnaActiva() no ordena por una columna invisible. Si
// alguien saca cualquiera de las dos mitades, el default empieza a aplicarse en
// Resumen -donde la fila es un cliente- sin que nadie lo note.
chequear('columnaActiva descarta las columnas ocultas', true,
    strpos($ordenJs, 'visible(cols[i].th)') !== false);

$frCss = file_get_contents(__DIR__ . '/../cashflow/Css/Ingresos-Cobranzas_fr.css');

chequear('y en Resumen la columna de cobro esta oculta', true,
    strpos($frCss, '.modo-resumen thead tr:first-child th:nth-child(10)') !== false);

// Sin orden tambien es una eleccion: si el tercer click borrara la clave, seria
// indistinguible de no haber elegido nunca y el default volveria en la recarga
// siguiente, reponiendo un orden que el usuario acababa de sacar.
seccion('sacar el orden a mano le gana al default');

chequear('el "sin orden" se guarda en vez de borrarse', true,
    strpos($ordenJs, '{ columna: null }') !== false);

chequear('y ya no se borra la clave', false,
    strpos($ordenJs, 'removeItem') !== false);

// ============================================================================
// LAS FILAS AGRUPADAS DEL TABLERO
//
// Un concepto con parte real y parte proyectada se dibuja como un renglon que
// se abre. Casi todo eso es JavaScript y no hay corredor de JS, pero tiene
// cuatro cables que se rompen callados y que si se pueden verificar leyendo:
//
//   - El boton de "Expandir todo" tiene que existir en la pestana Y estar
//     enganchado en el JS. Si falta un lado, el boton no hace nada.
//   - Las partes tienen que llevar data-orden-sigue, y tabla-orden.js tiene
//     que entenderlo. Si falta cualquiera de los dos, ordenar por importe
//     separa una suma de sus partes: el cuadro queda con la pinta de siempre
//     mostrando la parte real de un concepto debajo de otro.
//   - El colapso se hace con display:none y no sacando la fila, porque de eso
//     depende que Exportar baje lo que se ve. Si alguien lo cambia por un
//     remove(), la planilla deja de coincidir con la pantalla.
//   - El acceso a localStorage va envuelto: sin el try/catch, una ventana
//     privada tira al leer y la grilla no se dibuja.
// ============================================================================

seccion('el boton de expandir todo esta cableado de los dos lados');

$cashflowTab = contenidoTab($TABS . '/cashflow.php');

chequear('el boton existe en la pestana', true,
    strpos($cashflowTab, 'id="cfBtnGrupos"') !== false);
chequear('y el JS lo busca por ese id', true,
    strpos($cashflowJs, "getElementById('cfBtnGrupos')") !== false);
chequear('y le engancha el click', true,
    strpos($cashflowJs, "addEventListener('click', alternarTodos)") !== false);

seccion('las partes viajan pegadas a su fila agrupada');

chequear('Cashflow.js marca las partes', true,
    strpos($cashflowJs, 'data-orden-sigue') !== false);
chequear('y tabla-orden.js lo entiende', true,
    strpos($ordenJs, "hasAttribute('data-orden-sigue')") !== false);

// Una fila pegada NO es un ancla, y es la distincion entera: un ancla se queda
// quieta, una fila pegada se mueve con la de arriba. Si alguien "simplifica"
// metiendo .cf-parte en el selector de anclas, las partes quedan clavadas
// mientras su suma se va a ordenar a otro lado.
chequear('y las partes no estan declaradas como ancla', false,
    strpos($cashflowJs, 'cf-parte,') !== false
        || strpos($cashflowJs, ' .cf-parte\'') !== false);

seccion('un grupo cerrado esconde sus partes, no las saca');

$cashflowCss = file_get_contents(__DIR__ . '/../cashflow/Css/Cashflow.css');

chequear('la clase que las esconde existe en el CSS', true,
    strpos($cashflowCss, '.cf-oculta') !== false);
chequear('y es display:none, que es lo que TablaExport mira', true,
    preg_match('/\.cf-oculta\s*\{\s*display:\s*none/', $cashflowCss) === 1);
chequear('el JS la prende y la apaga', true,
    strpos($cashflowJs, "classList.toggle('cf-oculta'") !== false);

// TablaExport pregunta la visibilidad sobre la tabla VIVA, asi que una fila con
// display:none no baja. Es lo que hace que "exporta lo que se ve" valga tambien
// para los grupos, sin que el componente sepa que existen.
chequear('TablaExport sigue sacando del clon lo que no se ve', true,
    strpos(file_get_contents($JS . '/tabla-export.js'), "display === 'none'") !== false);

seccion('el estado de cada grupo se guarda por usuario, y no puede romper');

chequear('una clave por codigo de grupo', true,
    strpos($cashflowJs, "'cashflow_grupo_' + codigo") !== false);
chequear('se lee de localStorage', true,
    strpos($cashflowJs, 'localStorage.getItem(claveGrupo(codigo))') !== false);
chequear('y se escribe', true,
    strpos($cashflowJs, 'localStorage.setItem(claveGrupo(codigo)') !== false);

// Sin el try/catch, en una ventana privada el getItem lanza y la grilla no se
// dibuja: se perderia el tablero entero por una preferencia de visualizacion.
chequear('las dos veces envuelto en try/catch', 2,
    preg_match_all('/try\s*\{\s*\n\s*(return\s+)?localStorage\./', $cashflowJs));

seccion('la regla de agrupamiento es la misma de los dos lados');

$estructuraPhp = file_get_contents(__DIR__ . '/../cashflow/Class/CashflowEstructura.php');

// Las tres condiciones: mismo grupo, misma seccion, mismo tipo. Estan escritas
// dos veces -el validador sobre la configuracion y el front sobre lo que
// dibuja- y tienen que moverse juntas, asi que cada lado nombra al otro.
chequear('el front dice que la comparte con el servidor', true,
    strpos($cashflowJs, 'CashflowEstructura::grupos()') !== false);
chequear('y el servidor con el front', true,
    strpos($estructuraPhp, 'Js/Cashflow.js') !== false);

foreach ([['corrida.seccion === f.seccion', $cashflowJs],
          ['corrida.tipo === f.tipo', $cashflowJs],
          ["\$anterior['SECCION'] === \$f['SECCION']", $estructuraPhp],
          ["\$anterior['TIPO'] === \$f['TIPO']", $estructuraPhp]] as $par) {
    chequear('condicion presente: ' . $par[0], true, strpos($par[1], $par[0]) !== false);
}
