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
chequear('un trimestral completo, segun apertura por defecto', ['SAUNIC', 3, 9, 23.0, 0.0, 1.5, null, null, 'APERTURA', null],
    array_values($ok));
$temporal = AlquileresParametros::validarLocal(['modo' => 'vigencia', 'fecha_inicio' => '2027-07-01',
    'fecha_fin' => '2027-07-31']);
chequear('un temporal segun la vigencia', ['VIGENCIA', '2027-07-01', '2027-07-31'],
    [$temporal['modo'], $temporal['fecha_inicio'], $temporal['fecha_fin']]);
chequear('segun la vigencia no pide fechas: salen de los contratos de la app', 'VIGENCIA',
    AlquileresParametros::validarLocal(['modo' => 'VIGENCIA'])['modo']);
chequearLanza('el inicio despues del vencimiento no', function () {
    AlquileresParametros::validarLocal(['fecha_inicio' => '2027-08-01', 'fecha_fin' => '2027-07-31']);
});
chequearLanza('un modo que no existe no', function () {
    AlquileresParametros::validarLocal(['modo' => 'OTRO']);
});
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

seccion('AlquileresParametros: mas de una razon social por local');

$razVig = ['SASANJ' => ['dia' => null], 'SAUCCS' => ['dia' => null]];
$sanJusto = ['cod_provee' => 'SASANJ', 'adicionales' => []];
chequear('una dada de alta que no es la principal se agrega', 'SAUCCS',
    AlquileresParametros::validarRazonLocal(' sauccs ', $sanJusto, $razVig, 'SAN JUSTO'));
chequearLanza('una que no esta dada de alta no', function () use ($sanJusto, $razVig) {
    AlquileresParametros::validarRazonLocal('OGMERC', $sanJusto, $razVig, 'SAN JUSTO');
});
chequearLanza('la principal no se repite como adicional', function () use ($sanJusto, $razVig) {
    AlquileresParametros::validarRazonLocal('SASANJ', $sanJusto, $razVig, 'SAN JUSTO');
});
chequearLanza('ni una adicional dos veces', function () use ($razVig) {
    AlquileresParametros::validarRazonLocal('SAUCCS', ['cod_provee' => 'SASANJ', 'adicionales' => ['SAUCCS']],
        $razVig, 'SAN JUSTO');
});
chequearLanza('sin principal no: la principal factura el resto', function () use ($razVig) {
    AlquileresParametros::validarRazonLocal('SAUCCS', ['cod_provee' => null], $razVig, 'SAN JUSTO');
});
chequear('la tabla de las adicionales no es obligatoria: sin el script 58 todo sigue como antes', false,
    in_array(AlquileresParametros::T_LOCAL_RAZON, AlquileresParametros::TABLAS, true));
chequear('el script existe y crea la tabla', true, strpos((string) @file_get_contents(__DIR__ . '/../'
    . AlquileresParametros::SCRIPT_RAZONES_LOCAL), 'CREATE TABLE dbo.' . AlquileresParametros::T_LOCAL_RAZON) !== false);

seccion('AlquileresParametros: los conceptos que factura cada razon social');

$sjLocal = ['cod_provee' => 'SASANJ', 'adicionales' => ['SAUCCS', 'SAOTRA'], 'conceptos_razon' => ['SAOTRA' => [3]]];
chequear('una adicional marca los suyos, sin repetir y en orden', [6, 7, 11, 16, 17],
    AlquileresParametros::validarConceptosRazon('sauccs', [17, '6', 7, 11, 16, 6], $sjLocal, 'SAN JUSTO'));
chequear('una lista vacia vale: todo vuelve a la principal', [],
    AlquileresParametros::validarConceptosRazon('SAUCCS', [], $sjLocal, 'SAN JUSTO'));
chequearLanza('a la principal no se le marca nada: factura el resto', function () use ($sjLocal) {
    AlquileresParametros::validarConceptosRazon('SASANJ', [8], $sjLocal, 'SAN JUSTO');
});
chequearLanza('una que no es razon del local tampoco', function () use ($sjLocal) {
    AlquileresParametros::validarConceptosRazon('SAPALA', [8], $sjLocal, 'SAN JUSTO');
});
chequearLanza('un concepto que no se proyecta (4, llave) no', function () use ($sjLocal) {
    AlquileresParametros::validarConceptosRazon('SAUCCS', [4], $sjLocal, 'SAN JUSTO');
});
chequearLanza('un concepto en dos razones sociales del mismo local no', function () use ($sjLocal) {
    AlquileresParametros::validarConceptosRazon('SAUCCS', [3, 11], $sjLocal, 'SAN JUSTO');
});
chequear('la tabla de conceptos no es obligatoria: sin el script 62 todo va a la principal', false,
    in_array(AlquileresParametros::T_LOCAL_RAZON_CONCEPTO, AlquileresParametros::TABLAS, true));
$script62 = (string) @file_get_contents(__DIR__ . '/../' . AlquileresParametros::SCRIPT_CONCEPTOS_RAZON);
chequear('el script existe y crea la tabla con su indice unico filtrado', [true, true], [
    strpos($script62, 'CREATE TABLE dbo.' . AlquileresParametros::T_LOCAL_RAZON_CONCEPTO) !== false,
    strpos($script62, 'WHERE VIGENTE = 1') !== false]);
chequear('el script no borra nada', false, (bool) preg_match('/\b(DROP|DELETE|TRUNCATE)\b/i', $script62));

seccion('Tipos de retencion: el nombre');

require_once __DIR__ . '/../Class/AlquileresTiposRetencion.php';

$tiposVig = [['id' => 1, 'nombre' => 'Ganancias'], ['id' => 2, 'nombre' => 'IIBB Santa Fe'],
             ['id' => 3, 'nombre' => 'IIBB CABA'], ['id' => 4, 'nombre' => 'IIBB Bs As']];
chequear('alta: un nombre nuevo, con los espacios limpios', 'IIBB Córdoba',
    AlquileresTiposRetencion::validarNombre('  IIBB   Córdoba ', $tiposVig));
chequearLanza('alta: un nombre repetido no', function () use ($tiposVig) {
    AlquileresTiposRetencion::validarNombre('Ganancias', $tiposVig);
});
chequearLanza('sin distinguir mayusculas', function () use ($tiposVig) {
    AlquileresTiposRetencion::validarNombre('iibb caba', $tiposVig);
});
chequearLanza('ni acentos', function () use ($tiposVig) {
    AlquileresTiposRetencion::validarNombre('Gananciás', $tiposVig);
});
chequearLanza('vacio no', function () use ($tiposVig) {
    AlquileresTiposRetencion::validarNombre('   ', $tiposVig);
});
chequear('edicion: el propio nombre no choca consigo mismo', 'GANANCIAS',
    AlquileresTiposRetencion::validarNombre('GANANCIAS', $tiposVig, 1));
chequearLanza('edicion: pero si con otro vigente', function () use ($tiposVig) {
    AlquileresTiposRetencion::validarNombre('IIBB CABA', $tiposVig, 1);
});
chequear('un dado de baja no cuenta: su nombre se puede volver a usar', 'Viejo',
    AlquileresTiposRetencion::validarNombre('Viejo', $tiposVig));

$script60 = (string) @file_get_contents(__DIR__ . '/../' . AlquileresTiposRetencion::SCRIPT);
chequear('el script 60 crea la tabla, con nombre CI_AI e indice unico filtrado', [true, true, true], [
    strpos($script60, 'CREATE TABLE dbo.' . AlquileresTiposRetencion::T_TIPO) !== false,
    strpos($script60, 'COLLATE Latin1_General_CI_AI NOT NULL') !== false,
    (bool) preg_match('/UX_RO_T_CF_ALQTRET_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ALQ_TIPO_RETENCION \(NOMBRE\)\s+WHERE VIGENTE = 1/', $script60)]);
chequear('y siembra los cuatro sin pisar', [true, true],
    [strpos($script60, "('Ganancias'), ('IIBB Santa Fe'), ('IIBB CABA'), ('IIBB Bs As')") !== false,
     strpos($script60, 'WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_ALQ_TIPO_RETENCION T WHERE T.NOMBRE = S.NOMBRE)') !== false]);
$clsTipos = file_get_contents(__DIR__ . '/../Class/AlquileresTiposRetencion.php');
chequear('toda escritura de tipos pasa por usuarioDeEscritura', 3,
    substr_count($clsTipos, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);'));
chequear('renombrar es baja y alta, no un UPDATE del nombre', false,
    (bool) preg_match('/SET NOMBRE\s*=/', $clsTipos));

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

$acciones = ['guardarGeneral', 'altaRazon', 'guardarRazon', 'bajaRazon', 'guardarLocal', 'altaRazonLocal',
             'bajaRazonLocal', 'guardarConceptosRazon', 'altaTipoRetencion', 'renombrarTipoRetencion',
             'bajaTipoRetencion', 'altaExtra', 'bajaExtra'];
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
