<?php
/**
 * El editor del mix de cobro en Parametros: lo que recibe la pantalla, la
 * previsualizacion mientras se edita y el guardado (Class/MixCobro.php).
 *
 * El guardado se prueba con una subclase que reemplaza la lectura de los
 * nodos y la escritura de cada uno: NO ESCRIBE EN NINGUNA TABLA. Lo que corre
 * del codigo de produccion es guardarCanal() -validar, abrir la transaccion,
 * escribir cada cambio, confirmar-, que es lo que se prueba. Las escrituras
 * reales se corrieron contra una #temporal antes de publicar (ver el commit).
 */

require_once __DIR__ . '/../Class/MixCobro.php';

/** Una fila cruda de RO_T_CASHFLOW_VENTAS_MIX_NODO */
function nodoMG($id, $canal, $padre, $nivel, $nombre, $porc, $costo = null, $tasa = null,
                $dias = null, $activo = 1) {
    return ['ID' => $id, 'CANAL' => $canal, 'ID_PADRE' => $padre, 'NIVEL' => $nivel,
            'NOMBRE' => $nombre, 'PORCENTAJE' => $porc, 'COSTO' => $costo, 'TASA' => $tasa,
            'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo, 'ORDEN' => $id];
}

/** Un arbol chico y valido de los cuatro canales */
function arbolMG($cambios = []) {
    $filas = [
        nodoMG(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 0.1, null, null, 1),
        nodoMG(2, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 0.9, null, null, 2),
        nodoMG(3, 'LOCALES', 2, 'TIPO_TARJETA', 'Débito', 0.0, 0.0318, null, 7, 0),
        nodoMG(4, 'LOCALES', 2, 'TIPO_TARJETA', 'Crédito', 0.0, null, null, null, 0),
        nodoMG(5, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 40),
        nodoMG(6, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 60),
        nodoMG(7, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 1.0),
        nodoMG(8, 'ECOMMERCE', 7, 'MEDIO_PAGO', 'Tarjeta', 1.0, null, null, 2)
    ];

    foreach ($filas as $i => $f) {
        if (isset($cambios[$f['ID']])) {
            $filas[$i] = array_merge($f, $cambios[$f['ID']]);
        }
    }

    return MixCobro::normalizar($filas);
}

/** Encender Debito y Credito repartiendo el 100% de Tarjeta */
function abrirTarjetaMG($credDias = '5') {
    return [
        ['id' => 3, 'activo' => true, 'porcentaje' => 0.2],
        ['id' => 4, 'activo' => true, 'porcentaje' => 0.8, 'dias' => $credDias]
    ];
}

seccion('lo que recibe la pantalla: la regla ya resuelta');

$ed = MixCobro::datosEditor(arbolMG(), 'NODO');
$porClave = [];

foreach ($ed['nodos'] as $n) {
    $porClave[$n['clave']] = $n;
}

chequear('con el script, editable', true, $ed['editable']);
chequear('los niveles en el orden de la jerarquia', array_keys(MixCobro::NIVELES), array_column($ed['niveles'], 'codigo'));
chequear('que nodo tiene hijos: no se le cambia el nivel', [true, false],
    [$porClave['N2']['tiene_hijos'], $porClave['N3']['tiene_hijos']]);
chequear('que niveles admite un hijo de Debito', ['PROCESADORA', 'CUOTAS'], $porClave['N3']['niveles_hijo']);
chequear('que niveles admite el propio Debito', ['TIPO_TARJETA', 'PROCESADORA', 'CUOTAS'],
    $porClave['N3']['niveles_permitidos']);
chequear('el primer nivel de Ecommerce, solo marketplace', ['MARKETPLACE'], $ed['niveles_primer']['ECOMMERCE']);
chequear('lo resuelto viaja: Credito hereda 2 dias de Tarjeta', [2, 'Tarjeta'],
    [$ed['resuelto']['nodos']['N4']['dias'], $ed['resuelto']['nodos']['N4']['dias_de']['nombre']]);

$edPlano = MixCobro::datosEditor(MixCobro::desdeMixPlano([
    ['ID' => 1, 'CANAL' => 'ECOMMERCE', 'MEDIO_PAGO' => 'TARJETA', 'PORCENTAJE' => 1, 'DIAS_ACREDITACION' => 2, 'ACTIVO' => 1]
]), 'PLANO');

chequear('sin el script, solo lectura', false, $edPlano['editable']);
chequear('y sin errores de estructura: el mix plano no tiene marketplace y no se edita', [],
    $edPlano['estructura']);

seccion('la previsualizacion: como quedaria el canal, sin guardar');

$v = MixCobro::previsualizar(arbolMG(), 'LOCALES', abrirTarjetaMG());

chequear('encender Debito y Credito al 20/80: valido', [true, []], [$v['valido'], $v['errores']]);
chequear('dos nodos cambiarian', 2, $v['cambios']);
chequear('Credito usa sus propios 5 dias', 5, $v['nodos']['N4']['dias']);
chequear('Tarjeta deja de ser hoja', false, $v['nodos']['N2']['hoja']);
chequear('solo trae los nodos del canal', ['N1', 'N2', 'N3', 'N4'], array_keys($v['nodos']));

$v = MixCobro::previsualizar(arbolMG(), 'LOCALES', abrirTarjetaMG(''));

chequear('Credito sin dias hereda los de Tarjeta: sigue valido', [true, 2],
    [$v['valido'], $v['nodos']['N4']['dias']]);

$v = MixCobro::previsualizar(arbolMG(), 'LOCALES', [['id' => 3, 'activo' => true, 'porcentaje' => 0.2]]);

chequear('Debito solo al 20%: invalido, y dice que grupo', [false, 1], [$v['valido'], count($v['errores'])]);
chequear('el texto nombra la rama', true, strpos($v['errores'][0], 'Locales › Tarjeta suman 20,00%') !== false);
chequear('la suma del grupo viaja para pintarla en rojo', [0.2, false], (function ($g) {
    return [round($g['suma'], 6), $g['valido']];
})(array_values(array_filter($v['grupos'], function ($g) { return $g['padre'] === 'N2'; }))[0]));

$v = MixCobro::previsualizar(arbolMG(), 'LOCALES', [['id' => 3, 'costo' => 1.5]]);

chequear('un campo mal cargado vuelve como error, no como excepcion', [false, true],
    [$v['valido'], strpos($v['errores'][0], 'El costo de "Débito" tiene que estar entre 0% y 100%') !== false]);

$v = MixCobro::previsualizar(arbolMG(), 'LOCALES', [['id' => 4, 'nombre' => 'debito']]);

chequear('un nombre repetido entre hermanos tambien', false, $v['valido']);

seccion('el guardado: solo se escribe lo que cambio');

$todoLocales = array_map(function ($n) {
    return ['id' => $n['ID'], 'nombre' => $n['NOMBRE'], 'nivel' => $n['NIVEL'],
            'porcentaje' => $n['PORCENTAJE'], 'costo' => $n['COSTO'], 'tasa' => $n['TASA'],
            'dias' => $n['DIAS_ACREDITACION'], 'activo' => $n['ACTIVO']];
}, array_values(array_filter(arbolMG(), function ($n) { return $n['CANAL'] === 'LOCALES'; })));

chequear('el canal entero sin tocar: nada que escribir', [],
    MixCobro::planGuardado(arbolMG(), 'LOCALES', $todoLocales));

$conCambio = $todoLocales;
$conCambio[2]['activo'] = 1;
$conCambio[2]['porcentaje'] = 0.2;
$conCambio[3]['activo'] = 1;
$conCambio[3]['porcentaje'] = 0.8;

chequear('el canal entero con dos nodos cambiados: dos escrituras, solo con sus campos', [
    ['id' => 3, 'campos' => ['ACTIVO' => 1, 'PORCENTAJE' => 0.2]],
    ['id' => 4, 'campos' => ['ACTIVO' => 1, 'PORCENTAJE' => 0.8]]
], MixCobro::planGuardado(arbolMG(), 'LOCALES', $conCambio));

chequearLanza('un arbol invalido no se guarda, y el mensaje dice por que', function () {
    MixCobro::planGuardado(arbolMG(), 'LOCALES', [['id' => 3, 'activo' => true, 'porcentaje' => 0.2]]);
}, 'El mix de Locales no se guardó: Los medios en juego de Locales › Tarjeta suman 20,00% y tienen que sumar 100%.');

chequear('otro canal roto no impide guardar este', 1, count(MixCobro::planGuardado(
    arbolMG([6 => ['ACTIVO' => 0]]), 'LOCALES', [['id' => 1, 'dias' => 2]])));

// ============================================================================
// guardarCanal(), con la lectura y la escritura reemplazadas
// ============================================================================

/**
 * El guardado real con los nodos de la prueba y las escrituras registradas.
 *
 * NO ESCRIBE EN NINGUNA TABLA: escribirNodo() solo anota, y la conexion es la
 * de la prueba, donde no se ejecuta ningun UPDATE. Lo unico que corre del
 * codigo de produccion es guardarCanal().
 */
class MixCobroGuardadoPrueba extends MixCobro {
    public $nodos = [];
    public $cid = null;
    public $conexiones = 0;
    public $escritos = [];

    public function tablaCreada() {
        return true;
    }

    public function getNodos() {
        return $this->nodos;
    }

    protected function conexionEscritura() {
        $this->conexiones++;

        return $this->cid;
    }

    protected function escribirNodo($cid, $id, $campos, $usuario) {
        $this->escritos[] = ['id' => $id, 'campos' => $campos, 'usuario' => $usuario];
    }
}

seccion('el guardado de prueba no escribe en ninguna tabla real');

$rcMG = new ReflectionClass('MixCobroGuardadoPrueba');
$fuenteMG = implode('', array_slice(file(__FILE__),
    $rcMG->getStartLine() - 1, $rcMG->getEndLine() - $rcMG->getStartLine() + 1));

chequear('la subclase no nombra ninguna tabla real', 0, preg_match('/RO_T_|dbo\./i', $fuenteMG));
chequear('y no llama a las escrituras originales', false, strpos($fuenteMG, 'parent::') !== false);

seccion('un arbol invalido corta antes de abrir la transaccion');

$pruebaMG = new MixCobroGuardadoPrueba();
$pruebaMG->nodos = arbolMG();

chequearLanza('se rechaza', function () use ($pruebaMG) {
    $pruebaMG->guardarCanal('LOCALES', [['id' => 3, 'activo' => true, 'porcentaje' => 0.2]], 'prueba');
});
chequear('sin pedir siquiera la conexion de escritura', 0, $pruebaMG->conexiones);
chequear('ni escribir nada', [], $pruebaMG->escritos);
chequear('sin cambios, tampoco se abre nada', [0, 0],
    [$pruebaMG->guardarCanal('LOCALES', $todoLocales, 'prueba'), $pruebaMG->conexiones]);
chequearLanza('sin usuario no se guarda', function () use ($pruebaMG) {
    $pruebaMG->guardarCanal('LOCALES', abrirTarjetaMG(), '');
});

seccion('un arbol valido escribe solo sus cambios, en una transaccion');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base no hay transaccion que abrir');
} else {
    $conexionMG = new Conexion;
    $pruebaMG->cid = $conexionMG->conectar('central');

    chequear('dos nodos escritos', 2, $pruebaMG->guardarCanal('LOCALES', abrirTarjetaMG(), 'prueba'));
    chequear('una sola conexion', 1, $pruebaMG->conexiones);
    chequear('Debito y Credito, con sus campos y el usuario', [
        ['id' => 3, 'campos' => ['ACTIVO' => 1, 'PORCENTAJE' => 0.2], 'usuario' => 'prueba'],
        ['id' => 4, 'campos' => ['ACTIVO' => 1, 'DIAS_ACREDITACION' => 5, 'PORCENTAJE' => 0.8], 'usuario' => 'prueba']
    ], $pruebaMG->escritos);
}

seccion('las escrituras: lo nuevo entra inhabilitado y en 0%, y el UPDATE lleva la auditoria');

$fuenteMix = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/MixCobro.php'));

chequear('el INSERT pone 0 en PORCENTAJE y en ACTIVO, sin parametro', 1,
    substr_count($fuenteMix, 'VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?, ?)'));
chequear('con usuario de alta y de modificacion', 1,
    substr_count($fuenteMix, 'ACTIVO, ORDEN, USUARIO_ALTA, USUARIO_MODIF)'));
chequear('el UPDATE sella la modificacion', true,
    strpos($fuenteMix, '$sets[] = Auditoria::SET_MODIF;') !== false);
chequear('y la baja segun el estado, como el resto del modulo', true,
    strpos($fuenteMix, "\$sets[] = Auditoria::sqlBajaSegunEstado('ACTIVO');") !== false);
chequear('las dos escrituras resuelven el usuario', 2,
    substr_count($fuenteMix, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);'));

seccion('las acciones del editor piden el permiso de Parametros -> Ventas');

$authMG = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/AuthCashflow.php'));
$ctrlMG = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/ParametrosController.php'));

foreach (['previsualizarMixArbol', 'saveMixArbol', 'addMixNodo'] as $accion) {
    chequear("$accion: en el mapa, con ['parametros', 'VENTAS']", 1,
        substr_count($authMG, "'" . $accion . "' => [['parametros', 'VENTAS']],"));
    chequear("$accion: en el controller", 1, substr_count($ctrlMG, "case '" . $accion . "':"));
}

foreach (['getMixCobro', 'saveMixCobro', 'addMixCobro'] as $accion) {
    chequear("$accion: retirada del controller", 0, substr_count($ctrlMG, "case '" . $accion . "':"));
    chequear("$accion: y del mapa", 0, substr_count($authMG, "'" . $accion . "'"));
}
