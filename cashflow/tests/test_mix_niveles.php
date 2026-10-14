<?php
/**
 * Los colores de los tipos de nivel del mix de cobro: Css/mix-niveles.css y
 * Js/mix-niveles.js.
 *
 * Lo que se rompe en silencio de un color NO es el color: es que falte o que
 * se duplique.
 *
 *   - Si se agrega un tipo a MixCobro::NIVELES y nadie le asigna un color, la
 *     pantalla no avisa: lo pinta en el gris neutro y nadie se entera de que
 *     ese gris no significa nada. Por eso esta prueba recorre NIVELES y nombra
 *     el codigo que falta.
 *   - Si una pantalla copia un color en su propio CSS, el dia que se ajuste
 *     el tono Parametros y Ventas dirian distinto, y el mismo color dejaria de
 *     significar el mismo tipo.
 *
 * Se prueba leyendo los archivos, como test_tablas_controles.php: no hay
 * corredor de JS en el proyecto, salvo cscript para la parte pura del helper.
 */

require_once __DIR__ . '/../Class/MixCobro.php';

$CSS_DIR = __DIR__ . '/../Css';
$cssNiveles = (string) @file_get_contents($CSS_DIR . '/mix-niveles.css');

/** Las variables que declara cada clase de tipo: [CODIGO => [variable => valor]] */
function paletaMixNiveles($css) {
    $paleta = [];

    preg_match_all('/\.mix-nivel-([A-Za-z0-9_]+)\s*\{([^}]*)\}/', $css, $bloques, PREG_SET_ORDER);

    foreach ($bloques as $b) {
        preg_match_all('/--(mix-nivel-[a-z]+)\s*:\s*([^;]+);/', $b[2], $vars, PREG_SET_ORDER);

        foreach ($vars as $v) {
            $paleta[$b[1]][$v[1]] = strtoupper(trim($v[2]));
        }
    }

    return $paleta;
}

$paleta = paletaMixNiveles($cssNiveles);

// ============================================================================
seccion('la paleta: cada tipo de NIVELES tiene su color');
// ============================================================================

chequear('existe Css/mix-niveles.css', true, $cssNiveles !== '');

$faltan = [];
$incompletos = [];

foreach (array_keys(MixCobro::NIVELES) as $codigo) {
    if (!isset($paleta[$codigo])) {
        $faltan[] = $codigo;
        continue;
    }

    foreach (['mix-nivel-fondo', 'mix-nivel-texto', 'mix-nivel-barra'] as $var) {
        if (!isset($paleta[$codigo][$var]) || !preg_match('/^#[0-9A-F]{6}$/', $paleta[$codigo][$var])) {
            $incompletos[] = $codigo . ' --' . $var;
        }
    }
}

// Las listas, y no un true/false: si falla, el mensaje dice que tipo falta.
chequear('tipos de NIVELES sin clase de color en Css/mix-niveles.css', [], $faltan);
chequear('tipos sin alguno de sus tres tonos (fondo, texto, barra)', [], $incompletos);
chequear('y ninguna clase de un tipo que ya no existe', [],
    array_values(array_diff(array_keys($paleta), array_keys(MixCobro::NIVELES))));

$barras = array_column($paleta, 'mix-nivel-barra');
chequear('dos tipos no comparten barra: el color es el tipo', count($barras), count(array_unique($barras)));

chequear('un tipo sin color se ve en el gris neutro de la base', 3,
    preg_match('/\.mix-nivel\s*\{([^}]*)\}/', $cssNiveles, $base)
        ? preg_match_all('/--mix-nivel-(fondo|texto|barra)\s*:/', $base[1]) : 0);

// ============================================================================
seccion('la paleta no pisa los colores que ya significan algo');
// ============================================================================

// El verde y el rojo de la suma del 100% y el gris de los inactivos, como
// estan en Parametros.css.
$cssParam = (string) @file_get_contents($CSS_DIR . '/Parametros.css');
$reservados = [];

foreach (['mixa-suma-ok', 'mixa-suma-mal', 'mixa-suma-fuera', 'mix-inactivo td'] as $sel) {
    if (preg_match('/\.' . preg_quote($sel, '/') . '[^{]*\{([^}]*)\}/', $cssParam, $m)) {
        preg_match_all('/#[0-9A-Fa-f]{6}\b/', $m[1], $hex);
        $reservados = array_merge($reservados, array_map('strtoupper', $hex[0]));
    }
}

chequear('se encontraron los colores de la suma y de los inactivos', true, count($reservados) >= 3);

$pisados = [];

foreach ($paleta as $codigo => $vars) {
    foreach ($vars as $var => $valor) {
        if (in_array($valor, $reservados, true)) {
            $pisados[] = $codigo . ' --' . $var . ' ' . $valor;
        }
    }
}

chequear('ningun tono de un tipo es el de la suma o el de los inactivos', [], $pisados);

// ============================================================================
seccion('una sola fuente: las pantallas no repiten los colores');
// ============================================================================

$hexPaleta = [];

foreach ($paleta as $vars) {
    $hexPaleta = array_merge($hexPaleta, array_values($vars));
}

foreach (['Parametros.css', 'Ingresos-Ventas.css'] as $archivo) {
    $css = strtoupper((string) @file_get_contents($CSS_DIR . '/' . $archivo));
    $repetidos = array_values(array_filter(array_unique($hexPaleta), function ($h) use ($css) {
        return strpos($css, $h) !== false;
    }));

    chequear($archivo . ' no copia ningun color de la paleta', [], $repetidos);
    chequear($archivo . ' no declara variables de nivel', 0, substr_count($css, '--MIX-NIVEL-'));
    chequear($archivo . ' no define clases de tipo', 0,
        preg_match_all('/\.MIX-NIVEL-(' . implode('|', array_keys(MixCobro::NIVELES)) . ')\b/', $css));
}

// Las pantallas del arbol arman las clases y la leyenda con el helper, y no
// escriben ningun tipo a mano: los tipos, su orden y sus rotulos llegan del
// servidor. Un codigo escrito en el JS es una lista paralela a NIVELES que
// nadie va a acordarse de actualizar.
$pantallasArbol = ['Parametros.js', 'Ingresos-Ventas.js'];

foreach ($pantallasArbol as $archivo) {
    $js = (string) @file_get_contents(__DIR__ . '/../Js/' . $archivo);

    chequear($archivo . ' usa el helper compartido para la clase y la leyenda', [true, true], [
        strpos($js, 'MixNiveles.clase(') !== false,
        strpos($js, 'MixNiveles.leyenda(') !== false
    ]);
    chequear($archivo . ' no escribe ningun codigo de nivel', 0,
        preg_match_all("/['\"](" . implode('|', array_keys(MixCobro::NIVELES)) . ")['\"]/", $js));
}

// ============================================================================
seccion('el helper arma las clases y la leyenda, sin navegador');
// ============================================================================

// Js/mix-niveles.js esta escrito en ES3 para correrlo con cscript, como
// EjeTotales.celdas(). Donde no hay cscript -fuera de Windows- se saltea.
$wsf = realpath(__DIR__ . '/js/mix_niveles.wsf');
$cscript = (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec'))
    ? trim((string) @shell_exec('where cscript 2>NUL')) : '';

if ($wsf === false) {
    chequear('existe tests/js/mix_niveles.wsf', true, false);
} elseif ($cscript === '') {
    Pruebas::saltear('no hay cscript en esta maquina');
} else {
    $salida = trim((string) shell_exec('cscript //nologo ' . escapeshellarg($wsf) . ' 2>&1'));
    $lineas = preg_split('/\r?\n/', $salida);
    $ultima = trim(end($lineas));

    if (!preg_match('/^OK: (\d+)\s+FALLAS: (\d+)$/', $ultima, $m)) {
        chequear('mix_niveles.wsf corre', 'OK: n   FALLAS: 0', $ultima);
    } else {
        chequear('mix_niveles.wsf: hay casos', true, intval($m[1]) > 0);
        chequear('mix_niveles.wsf: sin fallas', 0, intval($m[2]));
    }
}

// ============================================================================
seccion('la tabla de tipos del README dice los mismos colores que el CSS');
// ============================================================================

// El README repite el tono de la barra para que se lea sin abrir el CSS. Una
// copia que nadie verifica termina diciendo otra cosa.
$readme = str_replace("\r\n", "\n", (string) @file_get_contents(__DIR__ . '/../README-ventas.md'));
$enReadme = [];

preg_match_all('/^\| \d+ \| `([A-Z_]+)` \| [^|]+ \| [^|`]*`(#[0-9A-Fa-f]{6})` \|$/m', $readme, $filas, PREG_SET_ORDER);

foreach ($filas as $f) {
    $enReadme[$f[1]] = strtoupper($f[2]);
}

chequear('un color por tipo, en el orden de NIVELES', array_keys(MixCobro::NIVELES), array_keys($enReadme));
chequear('y es el de la barra en Css/mix-niveles.css', array_map(function ($v) {
    return $v['mix-nivel-barra'];
}, array_intersect_key($paleta, MixCobro::NIVELES)), array_intersect_key($enReadme, $paleta));
