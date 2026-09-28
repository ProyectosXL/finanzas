<?php
require_once __DIR__ . '/../Class/Menu.php';
require_once __DIR__ . '/../Class/ParametrosIE.php';
require_once __DIR__ . '/../Class/Formulas.php';

$sql = file_get_contents(__DIR__ . '/../sql/ie_permisos.sql');
$semilla = file_get_contents(__DIR__ . '/../sql/ie_estructura.sql');

seccion('Cada pestana tiene archivo, JS y permiso dado de alta');
foreach (Menu::todas() as $p) {
    chequear('Tabs/' . $p['tab'] . '.php existe', true, file_exists(__DIR__ . '/../Tabs/' . $p['tab'] . '.php'));
    chequear($p['permiso'] . ' esta en sql/ie_permisos.sql', true, strpos($sql, "'" . $p['permiso'] . "'") !== false);
}
chequear('ie.editar esta en sql/ie_permisos.sql', true, strpos($sql, "'ie.editar'") !== false);
chequear('El codigo del modulo es el del script', true, strpos($sql, "'" . AuthInformeEconomico::MODULO_CODIGO . "'") !== false);
chequear('Sin sesion no se puede nada (falla cerrada)', false, AuthInformeEconomico::puede('ie.tab.canales'));

seccion('La semilla solo nombra formulas que existen');
preg_match_all("/\\('([A-Z_0-9]+)',\\s*'(CALCULO|RATIO)'/", $semilla, $m);
$faltan = array_values(array_diff($m[1], array_keys(Formulas::catalogo())));
chequear('Toda clave CALCULO/RATIO de la semilla esta en el catalogo', [], $faltan);
chequear('Y el tipo coincide', true, array_reduce(array_keys($m[1]), function ($ok, $i) use ($m) {
    return $ok && Formulas::catalogo()[$m[1][$i]]['tipo'] === $m[2][$i];
}, true));

seccion('Validacion de las bandas del semaforo');
$ok = ParametrosIE::validarBandas(['VERDE' => ['desde' => '15', 'hasta' => ''], 'ROJO' => ['desde' => '', 'hasta' => '5']]);
chequear('En % se guarda como fraccion', 0.15, $ok['bandas']['VERDE']['desde']);
chequear('Vacio es abierto', null, $ok['bandas']['VERDE']['hasta']);
chequear('Acepta coma decimal', 0.125, ParametrosIE::validarBandas(['AMARILLO' => ['desde' => '12,5', 'hasta' => '15']])['bandas']['AMARILLO']['desde']);
chequear('Desde >= hasta se rechaza', false, ParametrosIE::validarBandas(['AMARILLO' => ['desde' => '15', 'hasta' => '12']])['ok']);
chequear('Texto se rechaza', false, ParametrosIE::validarBandas(['ROJO' => ['desde' => 'abc', 'hasta' => '']])['ok']);
