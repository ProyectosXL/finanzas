<?php
/**
 * Parametros -> Alquileres (Class/AlquileresParametros.php y
 * Alquileres::parametros()).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN TRIMESTRAL SIN MES DEL ULTIMO AJUSTE SE GUARDA. No ajustaria nunca, y
 *      nadie lo notaria hasta comparar con la factura.
 *
 *   2. UN IVA DISTINTO DE 21 SE RECHAZA. Hay locales con mas (otros cargos) y
 *      con menos.
 *
 *   3. EL RUBRO SE COMPARA EXACTO. 'Alquiler ' o 'alquiler' son ALQUILER; y el
 *      rubro economico 'Alquileres' NO es el criterio: es la columna RUBRO.
 *
 *   4. UN EXTRAORDINARIO SOBRE UN CONCEPTO QUE NO SE PROYECTA. Sumaria al
 *      cashflow un concepto que esta fuera de el (el 4, la llave).
 *
 *   5. SIN EL SCRIPT LA PANTALLA SE CAE. Tiene que cargar y decir que falta
 *      correr sql/cashflow_alquileres.sql.
 *
 * No toca la base: las lecturas se reemplazan por dobles.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Alquileres.php';

seccion('AlquileresParametros: los datos de un local');

$ok = AlquileresParametros::validarLocal([
    'cod_provee' => 'SAUNIC', 'frecuencia' => '3', 'mes_ajuste' => '9', 'pct_iva' => '23',
    'pct_percepcion' => '0', 'pct_retencion' => '1,5', 'fecha_fin' => '', 'venta_mensual_estimada' => ''
]);
chequear('un trimestral completo', ['SAUNIC', 3, 9, 23.0, 0.0, 1.5, null, null],
    array_values($ok));
chequear('un IVA de 19 % tambien', 19.0, AlquileresParametros::validarLocal(['pct_iva' => '19'])['pct_iva']);
chequear('vacio es null, no 0', null, AlquileresParametros::validarLocal(['pct_percepcion' => ''])['pct_percepcion']);
chequear('0 es 0', 0.0, AlquileresParametros::validarLocal(['pct_retencion' => '0'])['pct_retencion']);
chequear('en mensual el mes del ultimo ajuste no se guarda', null,
    AlquileresParametros::validarLocal(['frecuencia' => '1', 'mes_ajuste' => '5'])['mes_ajuste']);
chequearLanza('trimestral sin mes del ultimo ajuste: no se guarda y dice por que',
    function () { AlquileresParametros::validarLocal(['frecuencia' => '3']); },
    'Con frecuencia trimestral hace falta el mes del último ajuste: es el que ancla el ciclo.');
chequearLanza('una frecuencia de 4 meses no existe',
    function () { AlquileresParametros::validarLocal(['frecuencia' => '4']); });
chequearLanza('un IVA negativo no', function () { AlquileresParametros::validarLocal(['pct_iva' => '-1']); });
chequearLanza('tres decimales no', function () { AlquileresParametros::validarLocal(['pct_iva' => '21.005']); });
chequearLanza('una venta estimada en 0 no',
    function () { AlquileresParametros::validarLocal(['venta_mensual_estimada' => '0']); });
chequear('la venta estimada acepta coma decimal', 1500000.5,
    AlquileresParametros::validarLocal(['venta_mensual_estimada' => '1500000,50'])['venta_mensual_estimada']);

seccion('AlquileresParametros: el dia de vencimiento');

chequear('del 1 al 28', 28, AlquileresParametros::entero('28', 1, 28, 'El día', false));
chequearLanza('el 31 no existe en febrero', function () { AlquileresParametros::entero('31', 1, 28, 'El día', false); });
chequear('el de la razon social puede quedar vacio (= el general)', null,
    AlquileresParametros::entero('', 1, 28, 'El día', true));

seccion('AlquileresParametros: los pagos extraordinarios');

$cencosud = AlquileresParametros::validarExtra([
    'cod_provee' => 'SAUNIC', 'todos' => true, 'conceptos' => [8], 'mes_base' => '12', 'pct' => '75',
    'diferimiento' => '1'
]);
chequear('el de Cencosud', ['SAUNIC', true, [], [8], 12, 75.0, 1],
    array_slice(array_values($cencosud), 0, 7));
chequear('con algunos locales', [2, 78], AlquileresParametros::validarExtra(
    ['cod_provee' => 'SAUNIC', 'locales' => ['2', '78'], 'conceptos' => [1, 8], 'mes_base' => 12, 'pct' => 10])['locales']);
chequearLanza('sobre el 4 (la llave) no', function () {
    AlquileresParametros::validarExtra(['cod_provee' => 'X', 'todos' => true, 'conceptos' => [4], 'mes_base' => 1, 'pct' => 1]);
});
chequearLanza('un % de 0 no', function () {
    AlquileresParametros::validarExtra(['cod_provee' => 'X', 'todos' => true, 'conceptos' => [8], 'mes_base' => 1, 'pct' => '0']);
});
chequearLanza('sin locales ni "todos" no', function () {
    AlquileresParametros::validarExtra(['cod_provee' => 'X', 'conceptos' => [8], 'mes_base' => 1, 'pct' => 1]);
});

seccion('AlquileresParametros: el universo del buscador es la columna RUBRO');

$mapa = [
    'SAPALA' => ['NOMBRE' => 'IRSA', 'RUBRO' => 'ALQUILER', 'RUBRO_ECONOMICO' => 'Alquileres'],
    'OGMERC' => ['NOMBRE' => 'MERCADOLIBRE', 'RUBRO' => ' alquiler ', 'RUBRO_ECONOMICO' => 'Arancel'],
    'OGEASY' => ['NOMBRE' => 'CENCOSUD', 'RUBRO' => 'MANTENIMIENTO', 'RUBRO_ECONOMICO' => 'Alquileres'],
    'OGXXXX' => ['NOMBRE' => 'OTRO', 'RUBRO' => null, 'RUBRO_ECONOMICO' => null]
];
chequear('la comparacion es tolerante; el rubro economico no cuenta', ['OGMERC', 'SAPALA'],
    array_keys(AlquileresParametros::universoDelMaestro($mapa)));

seccion('Parametros: la sub-pestaña esta cableada');

require_once __DIR__ . '/../Class/Parametros.php';

$modulo = null;
foreach (Parametros::getModulos() as $m) {
    if ($m['codigo'] === 'ALQUILERES') {
        $modulo = $m;
    }
}
chequear('Parametros declara ALQUILERES', true, $modulo !== null);
chequear('con el controller del modulo', 'Controller/AlquileresController.php', $modulo['endpoint'] ?? null);
chequear('su permiso es cashflow.editar.parametros.alquileres', 'cashflow.editar.parametros.alquileres',
    AuthCashflow::claveEdicion('parametros', 'ALQUILERES'));

$acciones = ['guardarGeneral', 'altaRazon', 'guardarRazon', 'bajaRazon', 'guardarLocal', 'altaExtra', 'bajaExtra'];
$malas = [];
foreach ($acciones as $a) {
    if ((AuthCashflow::ESCRITURAS['Alquileres'][$a] ?? null) !== [['parametros', 'ALQUILERES']]) {
        $malas[] = $a;
    }
}
chequear('cada escritura pide el permiso de la sub-pestaña', [], $malas);
chequear('getParametros es una lectura', true, in_array('getParametros', AuthCashflow::LECTURAS['Alquileres'], true));

$pane = file_get_contents(__DIR__ . '/../Tabs/parametros_alquileres.php');
chequear('la sub-pestaña define $edita con su permiso', true,
    strpos($pane, "AuthCashflow::puedeEditar('parametros', 'ALQUILERES')") !== false);
chequear('el boton de guardar el general solo se dibuja con permiso', true,
    (bool) preg_match('/<\?php if \(\$edita\): \?>\s*<div class="col-auto">\s*<button id="palqBtnGeneral"/', $pane));
chequear('el alta de un extraordinario solo se dibuja con permiso', true,
    (bool) preg_match('/<\?php if \(\$edita\): \?>\s*<div class="card-body border-bottom" id="palqExtraForm">/', $pane));
$controller = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
chequear('el controller pide permiso antes del switch', true,
    strpos($controller, "autorizar('Alquileres', \$action)") < strpos($controller, 'switch ($action)'));

seccion('Sin el script: la sub-pestaña carga y dice que falta');

class AlqParSinTabla extends AlquileresParametros {
    public function tablasFaltantes() { return AlquileresParametros::TABLAS; }
}

class AlqAppDoble extends AlquileresApp {
    public function tablasFaltantes() { return []; }
    public function locales() {
        return [2 => ['nro' => 2, 'nombre' => 'UNICENTER', 'habilitado' => true],
                40 => ['nro' => 40, 'nombre' => 'FLORES 1', 'habilitado' => false]];
    }
    public function mesesCerrados() { return ['2026-09']; }
    public function ventasNetas($desde) { return []; }
}

class AlquileresSinBase extends Alquileres {
    protected function universoRazones(&$avisos) { return []; }
    protected function ivaGeneral() { return 21.0; }
}

$sin = (new AlquileresSinBase('2026-11-10', new AlqAppDoble(), new AlqParSinTabla()))->parametros();
$textos = implode(' | ', array_column($sin['avisos'], 'texto'));
chequear('no lanza y dice que la tabla no esta', false, $sin['tabla_creada']);
chequear('el aviso nombra el script', true, strpos($textos, 'sql/cashflow_alquileres.sql') !== false);
chequear('el aviso es critico', 'danger', $sin['avisos'][0]['nivel']);
chequear('los locales de la app se listan igual', 2, count($sin['locales']));
chequear('el cerrado en Lakers no es un incompleto', 'CERRADO_LAKERS', $sin['locales'][1]['estado']);
chequear('el habilitado sin datos es incompleto', 'INCOMPLETO', $sin['locales'][0]['estado']);
chequear('el IVA general viaja para sugerirlo', 21.0, $sin['iva_general']);
