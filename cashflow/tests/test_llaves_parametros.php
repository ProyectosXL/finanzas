<?php
/**
 * Parametros -> Llaves y Renovaciones (Class/LlavesParametros.php): los
 * validadores, que son los que mandan, y el script 66.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. VACIO SE GUARDA COMO 0. Un escribano o un % de impuestos vacio es "sin
 *      dato" y avisa; 0 es un valor. Si el validador los confunde, la llave se
 *      proyecta sin impuestos sin que nadie lo haya decidido.
 *   2. UN NO RENUEVA SIN MOTIVO. Nadie sabe despues por que desaparecio una
 *      llave del flujo.
 *   3. UNA MARCA DE PAGADA A FUTURO. Lo que no se pago sigue proyectado.
 *   4. EL SCRIPT SIEMBRA DE MAS. Solo los tres defaults de Generales.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/LlavesParametros.php';

seccion('LlavesParametros: Generales');

chequear('el % de llave acepta coma decimal', 27.5, LlavesParametros::validarGeneral('PCT_LLAVE', '27,5'));
chequearLanza('el % de llave no se vacía', function () { LlavesParametros::validarGeneral('PCT_LLAVE', ''); });
chequearLanza('las cuotas son un entero de 1 a 60', function () { LlavesParametros::validarGeneral('CUOTAS', '0'); });
chequearLanza('el día de pago llega hasta 28', function () { LlavesParametros::validarGeneral('DIA_PAGO', '31'); });
chequear('un costo en U$S se puede vaciar', null, LlavesParametros::validarGeneral('USD_OBRA_CHICO', ''));
chequearLanza('el corte de mediano tiene que ser mayor que el de chico', function () {
    LlavesParametros::validarGeneral('CORTE_MEDIANO', '80', ['CORTE_CHICO' => 84.0]);
}, 'El corte de mediano tiene que ser mayor que el de chico (84,00 m²): si no, ningún local sería mediano.');
chequearLanza('una clave que no existe', function () { LlavesParametros::validarGeneral('OTRA', '1'); });

seccion('LlavesParametros: por operador, vacío no es 0');

$v = LlavesParametros::validarOperador(['pct_llave' => '', 'pct_comisiones' => '33,8', 'pct_fpc' => '', 'escribano' => '0',
                                        'pct_impuestos' => '', 'cuotas' => '', 'corrimiento' => '-3', 'pct_otros' => '43,8']);
chequear('vacío es null', [null, null, null, null], [$v['pct_llave'], $v['pct_fpc'], $v['pct_impuestos'], $v['cuotas']]);
chequear('el % de comisiones con coma decimal', 33.8, $v['pct_comisiones']);
chequear('el % de otros gastos ya no se valida ni se guarda', false, array_key_exists('pct_otros', $v));
chequearLanza('el % de FPC es un número >= 0', function () { LlavesParametros::validarOperador(['pct_fpc' => '-1']); });
chequear('0 en el escribano es 0', 0.0, $v['escribano']);
chequear('el corrimiento puede ser negativo', -3, $v['corrimiento']);
chequearLanza('el corrimiento tiene tope', function () { LlavesParametros::validarOperador(['corrimiento' => '30']); });

seccion('LlavesParametros: por local');

chequearLanza('No renueva pide motivo', function () { LlavesParametros::validarLocal(['no_renueva' => '1']); });
$v = LlavesParametros::validarLocal(['no_renueva' => '1', 'motivo_no_renueva' => 'El local cierra', 'mes_primera_cuota' => '2027-03']);
chequear('No renueva con motivo y el mes de la primera cuota', [true, 'El local cierra', '2027-03'],
    [$v['no_renueva'], $v['motivo_no_renueva'], $v['mes_primera_cuota']]);
chequearLanza('un mes mal escrito', function () { LlavesParametros::validarLocal(['mes_primera_cuota' => '2027-13']); });

seccion('LlavesParametros: ya pagada');

$v = LlavesParametros::validarPagada(['concepto' => 'llave', 'fecha' => '2026-08-31', 'monto' => '180525000'], '2026-11-23');
chequear('llave pagada el 31/08', ['LLAVE', '2026-08-31', 180525000.0], [$v['concepto'], $v['fecha'], $v['monto']]);
chequearLanza('una fecha futura no está pagada', function () {
    LlavesParametros::validarPagada(['concepto' => 'LLAVE', 'fecha' => '2026-12-01', 'monto' => '1'], '2026-11-23');
});
chequearLanza('con monto, tiene que ser mayor que 0', function () {
    LlavesParametros::validarPagada(['concepto' => 'OTROS', 'fecha' => '2026-08-31', 'monto' => '0'], '2026-11-23');
}, 'El monto pagado tiene que ser mayor que 0, o quedar vacío.');
$v = LlavesParametros::validarPagada(['concepto' => 'OTROS', 'fecha' => '2026-08-31', 'monto' => ''], '2026-11-23');
chequear('sin monto se graba: el monto es null, no 0', ['OTROS', null], [$v['concepto'], $v['monto']]);

seccion('LlavesParametros: obras, aperturas y compromisos');

$loc = [78 => ['nombre' => 'DISTRITO ARCOS']];
$v = LlavesParametros::validarObra(['nro' => '78', 'mes' => '2027-03', 'segmento' => '', 'costo_usd' => ''], $loc);
chequear('una obra sin segmento ni costo propio (usa los de m² y del segmento)', [78, '2027-03', null, null],
    [$v['nro'], $v['mes'], $v['segmento'], $v['costo_usd']]);
chequearLanza('una obra de un local que no está en la app', function () use ($loc) {
    LlavesParametros::validarObra(['nro' => '999', 'mes' => '2027-03'], $loc);
});
chequearLanza('una apertura pide el segmento', function () use ($loc) {
    LlavesParametros::validarApertura(['nombre' => 'Nuevo', 'mes' => '2027-03'], $loc);
});
$v = LlavesParametros::validarCompromiso(['tipo' => 'ALQUILERES', 'cod_provee' => 'saunic', 'concepto' => 'Prenda',
    'fecha' => '2026-12-10', 'monto' => '1000000', 'cuotas' => '4', 'forma' => 'ECHEQ'], ['SAUNIC' => true], [], $loc);
chequear('una prenda en 4 cuotas mensuales', ['SAUNIC', 4, '2027-03-10'],
    [$v['cod_provee'], count($v['cuotas']), $v['cuotas'][3]['fecha']]);
chequearLanza('el operador tiene que estar en su universo', function () use ($loc) {
    LlavesParametros::validarCompromiso(['tipo' => 'PROVEEDOR', 'cod_provee' => 'NOEXISTE', 'concepto' => 'X',
        'fecha' => '2026-12-10', 'monto' => '1'], [], ['SAUNIS' => true], $loc);
});

seccion('LlavesParametros: el script 66');

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_llaves.sql');
foreach (LlavesParametros::TABLAS as $t) {
    chequear("el script crea $t", true, strpos($sql, "CREATE TABLE dbo.$t") !== false);
}
chequear('siembra sólo los tres defaults de Generales', true,
    strpos($sql, "(VALUES ('PCT_LLAVE', 25.00), ('CUOTAS', 4.00), ('DIA_PAGO', 15.00))") !== false
    && substr_count($sql, 'INSERT INTO') === 1);
chequear('no hay bajas físicas', 0, preg_match_all('/\b(DELETE|DROP|TRUNCATE)\b/i',
    preg_replace(['#/\*.*?\*/#s', '#--[^\n]*#'], '', $sql)));
chequear('las claves de Generales del CHECK son las de la clase', true, array_reduce(array_keys(LlavesParametros::GENERALES),
    function ($ok, $k) use ($sql) { return $ok && strpos($sql, "'" . $k . "'") !== false; }, true));

seccion('LlavesParametros: el cableado de la sub-pestaña');

require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

$mods = array_column(Parametros::getModulos(), null, 'codigo');
chequear('el módulo LLAVES_RENOV está declarado, con su controller', 'Controller/LlavesController.php',
    $mods['LLAVES_RENOV']['endpoint'] ?? null);
chequear('el permiso es cashflow.editar.parametros.llaves_renov', 'cashflow.editar.parametros.llaves_renov',
    AuthCashflow::claveEdicion('parametros', 'LLAVES_RENOV'));
$paramTab = file_get_contents(__DIR__ . '/../Tabs/parametros.php');
chequear('el pane se incluye con su permiso', true,
    strpos($paramTab, "id=\"paneParamLlaves_renov\"") !== false && strpos($paramTab, "include __DIR__ . '/parametros_llaves.php'") !== false);
$jsLl = file_get_contents(__DIR__ . '/../Js/Parametros-Llaves.js');
$ctrlLl = file_get_contents(__DIR__ . '/../Controller/LlavesController.php');
preg_match_all("/escribir\('([A-Za-z]+)'/", $jsLl, $mJs);
preg_match_all("/data-accion=\"' \+ accion/", $jsLl, $mBaja);
$acciones = array_unique(array_merge($mJs[1], ['bajaPagada', 'bajaObra', 'bajaApertura', 'bajaCompromiso']));
$sinCase = array_values(array_filter($acciones, function ($a) use ($ctrlLl) { return strpos($ctrlLl, "case '" . $a . "':") === false; }));
chequear('cada acción del JS tiene su case en el controller', [], $sinCase);
$sinMapa = array_values(array_filter($acciones, function ($a) { return !isset(AuthCashflow::ESCRITURAS['Llaves'][$a]); }));
chequear('y está en el mapa de escrituras', [], $sinMapa);
chequear('todas las escrituras de Parámetros piden la sub-pestaña', true, array_reduce(
    ['guardarGeneral', 'guardarOperador', 'guardarLocal', 'altaObra', 'altaApertura', 'altaCompromiso'],
    function ($ok, $a) { return $ok && AuthCashflow::ESCRITURAS['Llaves'][$a][0] === ['parametros', 'LLAVES_RENOV']; }, true));
$codLl = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', file_get_contents(__DIR__ . '/../Class/LlavesTango.php'));
chequear('LlavesTango sólo lee', 0, preg_match_all('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC|DROP|ALTER|CREATE)\b/i', $codLl));
chequear('sin permiso de edición, los controles no se dibujan', true,
    substr_count(file_get_contents(__DIR__ . '/../Tabs/parametros_llaves.php'), 'if ($edita): ?>') >= 5);

seccion('LlavesParametros: ya pagada de la renovación entera');

$v = LlavesParametros::validarPagadas(['conceptos' => [['concepto' => 'LLAVE', 'monto' => '92750000'],
    ['concepto' => 'otros', 'monto' => '9741393,38']], 'fecha' => '2026-08-31', 'comprobante' => 'FAC A0003000013817'], '2026-11-23');
chequear('llave y otros gastos juntos, cada uno con su monto', [['concepto' => 'LLAVE', 'monto' => 92750000.0],
    ['concepto' => 'OTROS', 'monto' => 9741393.38]], $v['conceptos']);
chequear('con la fecha y el comprobante en común', ['2026-08-31', 'FAC A0003000013817'], [$v['fecha'], $v['comprobante']]);
chequear('el formato de un solo concepto sigue sirviendo', [['concepto' => 'LLAVE', 'monto' => 1.0]],
    LlavesParametros::validarPagadas(['concepto' => 'LLAVE', 'monto' => '1', 'fecha' => '2026-08-31'], '2026-11-23')['conceptos']);
chequearLanza('sin ningún concepto tildado', function () {
    LlavesParametros::validarPagadas(['conceptos' => [], 'fecha' => '2026-08-31'], '2026-11-23');
});
chequear('la renovación entera sin montos (como la marca la solapa Contratos)',
    [['concepto' => 'LLAVE', 'monto' => null], ['concepto' => 'OTROS', 'monto' => null]],
    LlavesParametros::validarPagadas(['conceptos' => [['concepto' => 'LLAVE', 'monto' => null], ['concepto' => 'OTROS', 'monto' => null]],
        'fecha' => '2026-08-31'], '2026-11-23')['conceptos']);
chequearLanza('un monto negativo o en 0, aunque el otro venga bien', function () {
    LlavesParametros::validarPagadas(['conceptos' => [['concepto' => 'LLAVE', 'monto' => '1'], ['concepto' => 'OTROS', 'monto' => '0']],
        'fecha' => '2026-08-31'], '2026-11-23');
});
chequearLanza('el mismo concepto dos veces', function () {
    LlavesParametros::validarPagadas(['conceptos' => [['concepto' => 'LLAVE', 'monto' => '1'], ['concepto' => 'LLAVE', 'monto' => '2']],
        'fecha' => '2026-08-31'], '2026-11-23');
});
foreach (['Tabs/llaves_renov.php' => 'll-pag-concepto', 'Tabs/parametros_llaves.php' => 'pll-pag-concepto'] as $archivo => $clase) {
    $html = file_get_contents(__DIR__ . '/../' . $archivo);
    chequear($archivo . ': los dos conceptos, tildados por defecto', 2,
        preg_match_all('/class="form-check-input ' . $clase . '" type="checkbox" id="\w+" value="(LLAVE|OTROS)" checked>/', $html));
}
$tabLl = file_get_contents(__DIR__ . '/../Tabs/llaves_renov.php');
$jsLl = file_get_contents(__DIR__ . '/../Js/Llaves.js');
chequear('Contratos: el modal «Ya pagada» no pide importes', [0, true],
    [preg_match('/id="llPagMonto/', $tabLl), strpos($jsLl, "return { concepto: c.value, monto: null };") !== false]);
chequear('y la nota sin monto omite la parte del importe', true,
    strpos($jsLl, "(p.monto === null || p.monto === undefined ? '' : ' por ' + plata(p.monto))") !== false);
chequear('Parámetros: el monto de cada concepto es opcional, y sin monto la tabla muestra «—»', [2, true],
    [substr_count(file_get_contents(__DIR__ . '/../Tabs/parametros_llaves.php'), 'inputmode="decimal" placeholder="opcional"'),
     strpos(file_get_contents(__DIR__ . '/../Js/Parametros-Llaves.js'), "(p.monto === null || p.monto === undefined ? '—' : plata(p.monto))") !== false]);
chequear('sin el script 73, marcar sin monto dice que falta', true,
    strpos(file_get_contents(__DIR__ . '/../Class/LlavesParametros.php'),
        "Todavía no se puede marcar «Ya pagada» sin monto: falta correr '") !== false);

seccion('Por local: tambien las renovaciones cargadas, con cuotas y 1.a cuota editables');

/* Con el contrato nuevo cargado siguen valiendo las cuotas y el mes de la 1.a
   cuota (LlavesCalculo::proyectarRenovacion()), asi que la renovacion tiene que
   seguir en la tabla para poder verlas y cambiarlas. Lo que dice cuanto se ve
   deshabilitado: manda el contrato. */
$pllJs = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Parametros-Llaves.js'));
chequear('la tabla muestra las estimadas y las cargadas', true,
    strpos($pllJs, "return r.tipo === 'ESTIMADA' || r.tipo === 'CARGADA';") !== false);
chequear('en una cargada, no renueva y su motivo deshabilitados con el tooltip', 2,
    substr_count($pllJs, "+ dis + '"));
foreach (['llave_manual', 'pct_llave', 'meses'] as $campoCuanto) {
    chequear($campoCuanto . ' deshabilitado en una cargada', true,
        (bool) preg_match("/campo\('" . $campoCuanto . "', p\." . $campoCuanto
            . ", cargada \? \{ titulo: MANDA_EL_CONTRATO, deshabilitado: true \}/", $pllJs));
}
chequear('cuotas sigue editable', true,
    strpos($pllJs, "campo('cuotas', p.cuotas, { titulo: 'Vacío = las del operador o las generales', hereda: heredado(usa.cuotas, '') })") !== false);
chequear('y la 1.a cuota tambien', false,
    (bool) preg_match('/data-campo="mes_primera_cuota"[^\n]*dis/', $pllJs));
chequear('el tooltip dice que manda el contrato', true,
    strpos($pllJs, "var MANDA_EL_CONTRATO = 'El contrato nuevo ya está cargado en la app: manda el contrato.") !== false);

seccion('Por local: sólo las renovaciones cargadas vigentes o futuras, con cuotas por delante');

require_once __DIR__ . '/../Class/Llaves.php';

/* Un doble de Llaves que expone renovacionesEnCurso() sin leer nada: la app,
   los parametros y Alquileres no se construyen (van objetos vacios). */
class LlavesPorLocalDoble extends Llaves {
    public function enCurso($base, $par) { return $this->renovacionesEnCurso($base, $par); }
}

$plK = function ($id, $desde, $hasta, $llave) {
    return ['id' => $id, 'desde' => $desde, 'hasta' => $hasta, 'llave' => $llave, 'comisiones' => 0.0, 'fpc' => 0.0];
};
$plBase = ['locales' => [
    /* Alto Palermo: el #85 (renovó al #3) ya terminó; el #90 (renueva al #85) está vigente con cuotas por delante. */
    7 => ['nro' => 7, 'nombre' => 'ALTO PALERMO', 'operador' => 'IRSA', 'cod_provee' => 'SAIRSA', 'vigencia' => false,
          'contratos' => [$plK(3, '2022-04-16', '2026-04-15', 17578666.0), $plK(85, '2026-04-16', '2026-10-15', 9000000.0),
                          $plK(90, '2026-10-16', '2027-12-31', 44500000.0)]],
    /* Un vigente cargado hace dos años: sus cuotas son de 2024, ya no muestra nada. */
    9 => ['nro' => 9, 'nombre' => 'CABALLITO', 'operador' => 'OTRO', 'cod_provee' => 'SAOTRO', 'vigencia' => false,
          'contratos' => [$plK(10, '2020-01-01', '2023-12-31', 1000000.0), $plK(11, '2024-01-01', '2027-12-31', 2000000.0)]]
]];
$plPar = ['generales' => ['CUOTAS' => 4.0], 'operadores' => [], 'locales' => []];
$plDoble = new LlavesPorLocalDoble('2026-11-23', new stdClass(), new stdClass(), new stdClass());
$plRens = $plDoble->enCurso($plBase, $plPar);
$plCargadas = array_map(function ($r) { return $r['nro'] . '|#' . $r['contrato']['id'] . '|renueva al #' . $r['ant']; },
    array_values(array_filter($plRens, function ($r) { return $r['tipo'] === LlavesCalculo::CARGADA; })));

chequear('el #85 de Alto Palermo (terminado) no aparece; el #90, vigente y con cuotas por delante, sí',
    ['7|#90|renueva al #85'], $plCargadas);
chequear('una cargada vigente con todas sus cuotas atrás tampoco (Caballito #11, cuotas de 2024)', 0,
    count(array_filter($plRens, function ($r) { return $r['nro'] === 9; })));

/* Con 36 cuotas desde enero de 2024, la última del #11 de Caballito es de diciembre de 2026: no es vieja. */
$plPar36 = $plPar;
$plPar36['locales']['9|10'] = ['cuotas' => 36];
chequear('las cuotas del local cuentan: con 36 cuotas, Caballito #11 tiene pagos por delante y aparece', ['9|#11'],
    array_map(function ($r) { return $r['nro'] . '|#' . $r['contrato']['id']; },
        array_values(array_filter($plDoble->enCurso($plBase, $plPar36), function ($r) { return $r['nro'] === 9; }))));

/* Una renovacion futura (cargada, empieza el año que viene) se muestra. */
$plFut = $plBase;
$plFut['locales'][7]['contratos'][] = $plK(95, '2028-01-01', '2030-12-31', 60000000.0);
chequear('una cargada futura también aparece', true,
    in_array('7|#95', array_map(function ($r) { return $r['nro'] . '|#' . ($r['contrato']['id'] ?? ''); },
        $plDoble->enCurso($plFut, $plPar)), true));

chequear('el criterio de «vieja» es el de la proyección (un solo lugar)', true,
    strpos(file_get_contents(__DIR__ . '/../Class/Llaves.php'), 'LlavesCalculo::esVieja(') !== false
    && strpos(file_get_contents(__DIR__ . '/../Class/LlavesCalculo.php'), 'self::esVieja($fechas, $hoy)') !== false);
chequear('la columna se llama «Contrato» y muestra el contrato nuevo de una cargada', true,
    strpos(file_get_contents(__DIR__ . '/../Tabs/parametros_llaves.php'), '>Contrato</th>') !== false
    && strpos($pllJs, "var c = r.tipo === 'CARGADA' ? r.contrato : r.vigente;") !== false
    && strpos($pllJs, "renueva al #' + r.vigente.id") !== false);

seccion('la pantalla nombra los conceptos del alquiler, no sus numeros');

$sinNumeros = [];

foreach (['Class/LlavesCalculo.php', 'Class/Llaves.php', 'Class/LlavesSugeridos.php', 'Js/Llaves.js', 'Js/Parametros-Llaves.js',
          'Tabs/llaves_renov.php', 'Tabs/parametros_llaves.php'] as $archivoLl) {
    $src = file_get_contents(__DIR__ . '/../' . $archivoLl);
    // Solo los textos entre comillas y el HTML: los comentarios pueden numerarlos.
    $sinComentarios = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#^\s*\*.*$#m', '#<!--.*?-->#s'], '', $src);

    if (preg_match('/1 \+ 2 \+ 8/', $sinComentarios)) {
        $sinNumeros[] = $archivoLl;
    }
}

chequear('ningun texto de la pantalla de Llaves dice "1 + 2 + 8"', [], $sinNumeros);

seccion('El script 73 (sql/cashflow_llaves_ajustes.sql)');

$s73 = (string) file_get_contents(__DIR__ . '/../' . LlavesParametros::SCRIPT_AJUSTES);
chequear('MONTO de «Ya pagada» acepta vacío, con su CHECK rehecho', [true, true], [
    strpos($s73, 'ALTER COLUMN MONTO DECIMAL(18,2) NULL') !== false,
    strpos($s73, 'CHECK (MONTO IS NULL OR MONTO > 0)') !== false]);
chequear('agrega PCT_COMISIONES y PCT_FPC por operador, vacíos, y no toca PCT_OTROS', [true, true, false], [
    strpos($s73, 'ADD PCT_COMISIONES DECIMAL(7,2) NULL') !== false, strpos($s73, 'ADD PCT_FPC DECIMAL(7,2) NULL') !== false,
    (bool) preg_match('/(UPDATE|ALTER)[^;]*PCT_OTROS/', $s73)]);
chequear('las facturas admiten COMISIONES y FPC, y siguen admitiendo OTROS', true,
    strpos($s73, "CHECK (CONCEPTO IN ('LLAVE', 'OTROS', 'COMISIONES', 'FPC', 'OBRA'))") !== false);
chequear('reejecutable: cada parte pregunta antes', true, strpos($s73, "COLUMNPROPERTY(OBJECT_ID('dbo.RO_T_CASHFLOW_LLAVES_PAGADA'), 'MONTO', 'AllowsNull') = 1") !== false
    && strpos($s73, "COL_LENGTH('dbo.RO_T_CASHFLOW_LLAVES_OPERADOR', 'PCT_COMISIONES') IS NULL") !== false
    && strpos($s73, "definition LIKE '%COMISIONES%'") !== false);
chequear('no borra datos', 0, preg_match('/\b(DELETE|TRUNCATE|DROP TABLE)\b/i', $s73));
chequear('informa las marcas OTROS vigentes y los operadores con % de otros gastos', true,
    strpos($s73, "WHERE VIGENTE = 1 AND CONCEPTO = 'OTROS'") !== false && strpos($s73, 'WHERE VIGENTE = 1 AND PCT_OTROS IS NOT NULL') !== false);
chequear('guardarOperador no escribe PCT_OTROS', false,
    strpos(file_get_contents(__DIR__ . '/../Class/LlavesParametros.php'), 'PCT_OTROS, ESCRIBANO') !== false);

seccion('Parámetros: lo sugerido no se confunde con lo grabado');

$p6Js = file_get_contents(__DIR__ . '/../Js/Parametros-Llaves.js');
$p6Tab = file_get_contents(__DIR__ . '/../Tabs/parametros_llaves.php');
/* Ningun placeholder numerico: ni literal en el HTML, ni armado con numero(), ni con un valor. */
$p6Num = [];
preg_match_all('/placeholder="([^"]*)"/', $p6Tab . $p6Js, $m);
foreach ($m[1] as $ph) {
    if (preg_match('/\d/', $ph)) { $p6Num[] = $ph; }
}
chequear('ningún input lleva un número como placeholder', [], $p6Num);
chequear('ni se le asigna uno desde el JS', 0, preg_match('/\.placeholder\s*=\s*+(?!\'\')/', $p6Js));
chequear('el campo vacío no lleva placeholder', true, strpos($p6Js, "'\" placeholder=\"\" '") !== false
    || strpos($p6Js, '\'" placeholder="" \'') !== false);
chequear('vacío que hereda: «usa X (de dónde)» debajo', true,
    strpos($p6Js, "return 'usa ' + numero(u.valor) + unidad + (u.de ? ' (' + u.de + ')' : '');") !== false);
chequear('vacío sin dato: borde punteado y «sin dato» en negrita', [true, true, true], [
    strpos($p6Js, "' pll-sin-dato'") !== false,
    strpos($p6Js, '<i class="fas fa-triangle-exclamation"></i> sin dato') !== false,
    (bool) preg_match('/\.pll-sin-dato \{ border: 1px dashed #000; \}/', file_get_contents(__DIR__ . '/../Css/Parametros.css'))]);
foreach (['pct_comisiones', 'pct_fpc', 'escribano', 'pct_impuestos'] as $p6c) {
    chequear($p6c . ': sin dato', 1, preg_match("/campo\('" . $p6c . "'[^;]*sinDato: true/s", $p6Js));
}
foreach (['pct_llave', 'cuotas', 'corrimiento'] as $p6c) {
    chequear($p6c . ' (operador): hereda', 1, preg_match("/campo\('" . $p6c . "', v\." . $p6c . ", \{[^}]*hereda: heredado\(usa\./", $p6Js));
}
chequear('el sugerido va en un chip con «Usar» y su tooltip', true,
    strpos($p6Js, '<span class="pll-sugerido" title="') !== false && strpos($p6Js, 'pll-usar') !== false);
chequear('«Usar» graba: la fila del operador o la clave de Generales, como cualquier cambio', [true, true], [
    strpos($p6Js, "guardarFila('guardarOperador', fila, { cod_provee: fila.getAttribute('data-cod') });\n    }") !== false
        || strpos(str_replace("\r\n", "\n", $p6Js), "el.value = valor;\n        guardarFila('guardarOperador', fila, { cod_provee: fila.getAttribute('data-cod') });") !== false,
    strpos($p6Js, "escribir('guardarGeneral', { clave: clave, valor: valor });") !== false]);
chequear('y guardarOperador pasa por usuarioDeEscritura (auditoría)', 1,
    preg_match('/function guardarOperador\(\$cod, \$datos, \$razones, \$usuario\) \{\s+\$usuario = AuthCashflow::usuarioDeEscritura\(\$usuario\);/',
        file_get_contents(__DIR__ . '/../Class/LlavesParametros.php')));

seccion('LlavesCalculo::queSeUsa(): lo que se hereda, en un solo lugar');

$gen6 = ['PCT_LLAVE' => 25.0, 'CUOTAS' => 4.0];
chequear('el operador vacío usa el general', ['valor' => 25.0, 'de' => 'general'],
    LlavesCalculo::queSeUsa(null, null, $gen6)['pct_llave']);
chequear('el local vacío usa las cuotas del operador', ['valor' => 2, 'de' => 'operador'],
    LlavesCalculo::queSeUsa(null, ['cuotas' => 2], $gen6)['cuotas']);
chequear('el corrimiento vacío es 0 por defecto', ['valor' => 0, 'de' => 'por defecto'],
    LlavesCalculo::queSeUsa(null, [], $gen6)['corrimiento']);
chequear('lo del local manda', ['valor' => 30.0, 'de' => 'local'],
    LlavesCalculo::queSeUsa(['pct_llave' => 30.0], ['pct_llave' => 28.0], $gen6)['pct_llave']);

$plPar6 = ['generales' => ['CUOTAS' => 4.0, 'PCT_LLAVE' => 25.0], 'locales' => [],
           'operadores' => ['SAIRSA' => ['cuotas' => 3, 'corrimiento' => -1]]];
$plRen6 = array_values(array_filter($plDoble->enCurso($plBase, $plPar6), function ($r) { return $r['nro'] === 7; }))[0] ?? [];
chequear('Por local dice lo que usa un campo vacío: cuotas del operador y % general', [['valor' => 3, 'de' => 'operador'],
    ['valor' => 25.0, 'de' => 'general']], [$plRen6['usa']['cuotas'] ?? null, $plRen6['usa']['pct_llave'] ?? null]);
chequear('y el mes de la 1.ª cuota con el corrimiento del operador (inicio oct-2026, -1)', '2026-09', $plRen6['mes1_defecto'] ?? null);
