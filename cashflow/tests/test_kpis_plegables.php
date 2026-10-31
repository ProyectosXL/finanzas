<?php
/**
 * Las tarjetas KPI de todas las pestanas, plegables a una franja
 * (Js/kpis-plegables.js).
 *
 * Lo que se fija es el cableado -que ningun bloque de tarjetas quede afuera,
 * que cada uno tenga su propia clave y que el componente se cargue una vez- y
 * que la franja sea una variante de las MISMAS tarjetas, no una copia de los
 * valores. Las reglas puras van en tests/js/kpis_plegables.wsf.
 */

/** Los bloques de tarjetas de un archivo de Tabs/: linea del .row => su tag */
function bloquesKpi($archivo) {
    $bloques = [];
    $ultimaFila = null;

    foreach (file($archivo) as $i => $linea) {
        if (preg_match('/<div[^>]*class="row[^"]*"/', $linea)) {
            $ultimaFila = [$i + 1, trim($linea)];
        }

        if (preg_match('/class="kpi-card[ "]/', $linea) && $ultimaFila !== null) {
            $bloques[$ultimaFila[0]] = $ultimaFila[1];
        }
    }

    return $bloques;
}

seccion('cada bloque de tarjetas de Tabs/ usa el componente');

$sinComponente = [];
$claves = [];
$tabsConKpi = [];

foreach (glob(__DIR__ . '/../Tabs/*.php') as $tabArchivo) {
    foreach (bloquesKpi($tabArchivo) as $linea => $tag) {
        $tabsConKpi[basename($tabArchivo)] = true;

        if (!preg_match('/data-kpis="([^"]+)"/', $tag, $m)) {
            $sinComponente[] = basename($tabArchivo) . ':' . $linea;
            continue;
        }

        $claves[] = $m[1];
    }
}

chequear('ningun bloque de tarjetas sin data-kpis', [], $sinComponente);
chequear('cada bloque con su propia clave (Proveedores Exterior y Crono comparten id)', [],
    array_values(array_unique(array_diff_assoc($claves, array_unique($claves)))));

/* Las quince pestanas con tarjetas: si una deja de tenerlas, o aparece otra,
   esta lista tiene que cambiar a proposito. */
$esperadas = ['cashflow.php', 'cob_electronicos.php', 'cobranzas_fr.php', 'cobranzas_may.php',
    'compras_proyectadas.php', 'crono_nacionalizacion.php', 'echeqs.php', 'exportaciones_tasky.php',
    'gastos_locales.php', 'logistica_local.php', 'pagos_tarjetas.php', 'proveedores_exterior.php', 'proveedores_locales.php',
    'saldos.php', 'ventas.php'];
$conKpi = array_keys($tabsConKpi);
sort($conKpi);
chequear('son las quince pestanas con tarjetas', $esperadas, $conKpi);

$saldosHtml = file_get_contents(__DIR__ . '/../Tabs/saldos.php');
chequear('las tarjetas que dibuja el JS (Fondos) tambien', true,
    strpos($saldosHtml, '<div data-kpis="kpiFondos" class="row g-3 mb-4" id="kpiFondos"></div>') !== false);

chequear('cada sub-pestana con tarjetas tiene su bloque: Echeqs, dos', 2,
    substr_count(file_get_contents(__DIR__ . '/../Tabs/echeqs.php'), 'data-kpis="'));
chequear('Pagos con Tarjetas, tres', 3,
    substr_count(file_get_contents(__DIR__ . '/../Tabs/pagos_tarjetas.php'), 'data-kpis="'));
chequear('Saldos, tres', 3, substr_count($saldosHtml, 'data-kpis="'));

seccion('el componente');

$indexKpi = file_get_contents(__DIR__ . '/../index.php');
$jsKpi = file_get_contents(__DIR__ . '/../Js/kpis-plegables.js');
$cssKpi = file_get_contents(__DIR__ . '/../Css/kpis-plegables.css');

chequear('index.php lo carga una vez, en el head', true,
    substr_count($indexKpi, '<script src="Js/kpis-plegables.js') === 1
    && strpos($indexKpi, '<script src="Js/kpis-plegables.js') < strrpos($indexKpi, '</head>'));
chequear('y su hoja', 1, substr_count($indexKpi, '<link href="Css/kpis-plegables.css'));
chequear('recuerda por bloque en localStorage, con try/catch', 2,
    preg_match_all('/try \{\s*(var v = )?localStorage\./', $jsKpi));
chequear('la clave sale de data-kpis, no del id', true,
    strpos($jsKpi, "leer(bloque.getAttribute('data-kpis'))") !== false);
chequear('se conecta cuando cambia #tabContent', true,
    strpos($jsKpi, "document.getElementById('tabContent')") !== false
    && strpos($jsKpi, 'new MutationObserver(') !== false);
chequear('no toca la visibilidad del bloque', false, strpos($jsKpi, '.style.display') !== false);

/* Variante de CSS sobre las mismas tarjetas: el JS no copia valores ni arma
   otra franja. Si apareciera un textContent o un innerHTML sobre .kpi-card-value,
   la franja podria mostrar un numero viejo. */
chequear('no copia los valores de las tarjetas', 0,
    preg_match_all('/kpi-card-value[^\n]*(textContent|innerHTML)\s*=/', $jsKpi));
chequear('la franja esconde el icono y el pie', true,
    strpos($cssKpi, "[data-kpis].kpis-compacto .kpi-card-icon,\n[data-kpis].kpis-compacto .kpi-card-footer {\n    display: none;")
        !== false
    || strpos(str_replace("\r\n", "\n", $cssKpi),
        "[data-kpis].kpis-compacto .kpi-card-icon,\n[data-kpis].kpis-compacto .kpi-card-footer {\n    display: none;") !== false);
chequear('y en movil pasa a varios renglones, sin ancho minimo', true,
    strpos($cssKpi, 'flex: 1 1 auto;') !== false && strpos($cssKpi, 'min-width: 0;') !== false
    && strpos($cssKpi, 'overflow-x') === false);

seccion('las reglas, sin navegador');

$wsfKpi = realpath(__DIR__ . '/js/kpis_plegables.wsf');
$cscriptKpi = (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec'))
    ? trim((string) @shell_exec('where cscript 2>NUL')) : '';

if ($wsfKpi === false) {
    chequear('existe tests/js/kpis_plegables.wsf', true, false);
} elseif ($cscriptKpi === '') {
    Pruebas::saltear('no hay cscript en esta maquina');
} else {
    $salidaKpi = trim((string) shell_exec('cscript //nologo ' . escapeshellarg($wsfKpi) . ' 2>&1'));
    $lineasKpi = preg_split('/\r?\n/', $salidaKpi);
    $ultimaKpi = trim(end($lineasKpi));

    if (!preg_match('/^OK: (\d+)\s+FALLAS: (\d+)$/', $ultimaKpi, $mKpi)) {
        chequear('kpis_plegables.wsf corre', 'OK: n   FALLAS: 0', $ultimaKpi);
    } else {
        chequear('kpis_plegables.wsf: hay casos', true, intval($mKpi[1]) > 0);
        chequear('kpis_plegables.wsf: sin fallas', 0, intval($mKpi[2]));
    }
}
