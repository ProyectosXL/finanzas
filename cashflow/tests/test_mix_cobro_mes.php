<?php
/**
 * El mix de cobro por mes (Class/MixCobro.php): resolver() con un mes, la
 * config, el guardado de un mes, la copia a otros meses, inhabilitar en los
 * meses al inhabilitar en el constante, y el alta de un nodo en los meses.
 *
 * Las escrituras se prueban con una subclase que reemplaza la lectura y cada
 * escritura: NO ESCRIBE EN NINGUNA TABLA. Lo que corre del codigo de
 * produccion es la validacion, la transaccion y el orden de las escrituras.
 */

require_once __DIR__ . '/../Class/MixCobro.php';

/** Una fila cruda de RO_T_CASHFLOW_VENTAS_MIX_NODO */
function nodoMM($id, $canal, $padre, $nivel, $nombre, $porc, $costo = null, $tasa = null,
                $dias = null, $activo = 1) {
    return ['ID' => $id, 'CANAL' => $canal, 'ID_PADRE' => $padre, 'NIVEL' => $nivel,
            'NOMBRE' => $nombre, 'PORCENTAJE' => $porc, 'COSTO' => $costo, 'TASA' => $tasa,
            'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo, 'ORDEN' => $id];
}

/** El constante: Locales cobra 10% efectivo y 90% tarjeta (70 debito / 30 credito) */
function arbolMM() {
    return MixCobro::normalizar([
        nodoMM(1, 'LOCALES', null, 'MEDIO_PAGO', 'Efectivo', 0.1, null, null, 1),
        nodoMM(2, 'LOCALES', null, 'MEDIO_PAGO', 'Tarjeta', 0.9),
        nodoMM(3, 'LOCALES', 2, 'TIPO_TARJETA', 'Débito', 0.7, 0.03, null, 7),
        nodoMM(4, 'LOCALES', 2, 'TIPO_TARJETA', 'Crédito', 0.3, 0.05, null, 15),
        nodoMM(5, 'FRANQUICIAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 40),
        nodoMM(6, 'MAYORISTAS', null, 'MEDIO_PAGO', 'Echeq', 1.0, null, null, 60),
        nodoMM(7, 'ECOMMERCE', null, 'MARKETPLACE', 'Vtex', 1.0),
        nodoMM(8, 'ECOMMERCE', 7, 'MEDIO_PAGO', 'Tarjeta', 1.0, null, null, 2)
    ]);
}

/** Una fila de TABLA_NODO_MES */
function valorMM($idMes, $idNodo, $porc, $costo = null, $tasa = null, $dias = null, $activo = 1) {
    return ['ID_MIX_MES' => $idMes, 'ID_NODO' => $idNodo, 'PORCENTAJE' => $porc, 'COSTO' => $costo,
            'TASA' => $tasa, 'DIAS_ACREDITACION' => $dias, 'ACTIVO' => $activo];
}

/**
 * Locales con diciembre cargado -mas credito: 40 debito / 60 credito, y el
 * credito a 20 dias- y un febrero dado de baja. $modo es el de Locales.
 */
function configMM($modo = 'VARIABLE', $valoresDic = null) {
    $dic = $valoresDic !== null ? $valoresDic : [
        valorMM(100, 1, 0.1, null, null, 1),
        valorMM(100, 2, 0.9),
        valorMM(100, 3, 0.4, 0.03, null, 7),
        valorMM(100, 4, 0.6, 0.05, null, 20)
    ];

    return MixCobro::armarConfig(
        [['CANAL' => 'LOCALES', 'MODO' => $modo]],
        [['ID' => 100, 'CANAL' => 'LOCALES', 'MES' => 12, 'ACTIVO' => 1],
         ['ID' => 101, 'CANAL' => 'LOCALES', 'MES' => 2, 'ACTIVO' => 0]],
        array_merge($dic, [valorMM(101, 1, 0.5, null, null, 1), valorMM(101, 2, 0.5),
                           valorMM(101, 3, 0.5, null, null, 7), valorMM(101, 4, 0.5, null, null, 15)])
    );
}

/** Lo que el editor manda de Locales: [id => campos] a filas */
function filasMM($cambios) {
    $filas = [];

    foreach ($cambios as $id => $c) {
        $filas[] = array_merge(['id' => $id], $c);
    }

    return $filas;
}

// ============================================================================

seccion('la config: un canal sin fila es constante');

$vacia = MixCobro::configVacia();

chequear('sin el script, todos los canales en constante', ['CONSTANTE'],
    array_values(array_unique($vacia['modos'])));
chequear('y ningun mes', [], $vacia['meses']);

$cfg = configMM();

chequear('Locales en variable, el resto en constante', ['LOCALES' => 'VARIABLE', 'FRANQUICIAS' => 'CONSTANTE',
    'MAYORISTAS' => 'CONSTANTE', 'ECOMMERCE' => 'CONSTANTE'], $cfg['modos']);
chequear('diciembre cargado, febrero dado de baja', [true, false],
    [MixCobro::mesCargado($cfg, 'LOCALES', 12), MixCobro::mesCargado($cfg, 'LOCALES', 2)]);
chequear('febrero dado de baja sigue en la config: volver a cargarlo reactiva su fila', 101,
    $cfg['meses']['LOCALES'][2]['id']);
chequear('los valores conservan null: costo vacio no es cero cargado', [null, 0.03],
    [$cfg['meses']['LOCALES'][12]['valores'][1]['COSTO'], $cfg['meses']['LOCALES'][12]['valores'][3]['COSTO']]);
chequear('un modo desconocido no se toma', 'CONSTANTE',
    MixCobro::armarConfig([['CANAL' => 'LOCALES', 'MODO' => 'otro']], [], [])['modos']['LOCALES']);

// ============================================================================

seccion('resolver() con mix variable');

$constante = MixCobro::resolver(arbolMM());
$dic = MixCobro::resolver(arbolMM(), 12, $cfg);
$nov = MixCobro::resolver(arbolMM(), 11, $cfg);

chequear('un mes con mix propio usa sus valores: Credito 90% x 60% = 54%', 0.54,
    round($dic['nodos']['N4']['porcentaje_efectivo'], 6));
chequear('y sus dias', 20, $dic['nodos']['N4']['dias']);
chequear('el constante, igual que siempre: 90% x 30% = 27%', 0.27,
    round($constante['nodos']['N4']['porcentaje_efectivo'], 6));
chequear('diciembre dice que uso su mix propio en Locales y no en Franquicias', [true, false],
    [$dic['canales']['LOCALES']['mes_propio'], $dic['canales']['FRANQUICIAS']['mes_propio']]);
chequear('un mes sin mix propio toma el constante, nodo por nodo', $constante['nodos'], $nov['nodos']);
chequear('y lo dice', false, $nov['canales']['LOCALES']['mes_propio']);
chequear('un mes dado de baja tambien toma el constante', $constante['nodos'],
    MixCobro::resolver(arbolMM(), 2, $cfg)['nodos']);

$enConstante = configMM('CONSTANTE');

chequear('canal en constante con meses cargados: los ignora', $constante['nodos'],
    MixCobro::resolver(arbolMM(), 12, $enConstante)['nodos']);
chequear('sin mes, resolver() es exactamente lo de antes', $constante,
    MixCobro::resolver(arbolMM(), null, $cfg));
chequear('la estructura es la del constante: las mismas claves, en el mismo orden',
    array_keys($constante['nodos']), array_keys($dic['nodos']));

$cfgSinCredito = configMM('VARIABLE', [valorMM(100, 1, 0.1, null, null, 1), valorMM(100, 2, 0.9),
                                       valorMM(100, 3, 1.0, 0.03, null, 7)]);
$faltaUno = MixCobro::resolver(arbolMM(), 12, $cfgSinCredito);
$errFalta = array_values(array_filter($faltaUno['errores'], function ($e) { return $e['tipo'] === 'SIN_VALOR_MES'; }));

chequear('un nodo sin fila en un mes cargado toma los del constante', 0.3,
    $faltaUno['nodos']['N4']['porcentaje']);
chequear('y se marca, con el mes', [1, 12, 'N4'],
    [count($errFalta), $errFalta[0]['mes'], $errFalta[0]['clave']]);
chequear('el texto nombra la rama y el mes', 'Locales › Tarjeta › Crédito no tiene valores cargados para '
    . 'Diciembre: se usan los del mix constante.', $errFalta[0]['texto']);

seccion('resolverPorMes(): un arbol por mes, y uno solo si todo es constante');

$porMes = MixCobro::resolverPorMes(arbolMM(), $cfg, [11, 12, 1]);

chequear('noviembre y enero comparten el constante', [$constante, $constante], [$porMes[11], $porMes[1]]);
chequear('diciembre tiene el suyo', 0.54, round($porMes[12]['nodos']['N4']['porcentaje_efectivo'], 6));
chequear('con todo en constante, los doce meses son el constante', array_fill(1, 12, $constante),
    MixCobro::resolverPorMes(arbolMM(), $enConstante, range(1, 12)));

// ============================================================================

seccion('guardar un mes: se cargan valores, no estructura');

$v = MixCobro::previsualizar(arbolMM(), 'LOCALES', filasMM([3 => ['porcentaje' => 0.5], 4 => ['porcentaje' => 0.5]]),
    11, $cfg);

chequear('noviembre, sin cargar: parte del constante y queda valido', [true, 11, true],
    [$v['valido'], $v['mes'], $v['nuevo']]);
chequear('se resuelve como quedaria: Credito 90% x 50%', 0.45, round($v['nodos']['N4']['porcentaje_efectivo'], 6));

$plan = MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 11,
    filasMM([3 => ['porcentaje' => 0.5], 4 => ['porcentaje' => 0.5]]));

chequear('un mes nuevo se escribe entero: los cuatro nodos de Locales', [1, 2, 3, 4],
    array_column($plan['cambios'], 'id'));
chequear('con todos sus campos, y sin fila previa', [MixCobro::CAMPOS_MES, false],
    [array_keys($plan['cambios'][0]['campos']), $plan['cambios'][0]['existe']]);
chequear('lo que no se toco toma el constante', ['PORCENTAJE' => 0.1, 'COSTO' => null, 'TASA' => null,
    'DIAS_ACREDITACION' => 1, 'ACTIVO' => 1], $plan['cambios'][0]['campos']);

$plan = MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([
    1 => ['porcentaje' => 0.1, 'nombre' => 'Efectivo', 'nivel' => 'MEDIO_PAGO'],
    4 => ['dias' => '21']
]));

chequear('un mes cargado escribe solo lo que cambio', [['id' => 4, 'existe' => true,
    'campos' => ['DIAS_ACREDITACION' => 21]]], $plan['cambios']);
chequear('y no es nuevo', false, $plan['nuevo']);

chequearLanza('el nombre no se cambia en un mes', function () use ($cfg) {
    MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([4 => ['nombre' => 'Crédito 2']]));
}, 'En Diciembre se cargan los valores de cada nodo: el nombre de "Crédito" se cambia en el mix '
    . 'constante, y vale para todos los meses.');
chequearLanza('el nivel tampoco', function () use ($cfg) {
    MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([4 => ['nivel' => 'PROCESADORA']]));
});
chequearLanza('ni un nodo de otro canal', function () use ($cfg) {
    MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([5 => ['porcentaje' => 1]]));
});
chequearLanza('ni un mes que no existe', function () use ($cfg) {
    MixCobro::resolverCambiosMes(arbolMM(), $cfg, 'LOCALES', 13, []);
}, 'El mes tiene que ser un número de 1 a 12.');

seccion('un mes invalido no se guarda, y dice cual y por que');

chequearLanza('Debito y Credito suman 80% en diciembre', function () use ($cfg) {
    MixCobro::planGuardadoMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([3 => ['porcentaje' => 0.2]]));
}, 'El mix de Diciembre de Locales no se guardó: Los medios en juego de Locales › Tarjeta suman 80,00% '
    . 'y tienen que sumar 100%.');
chequearLanza('una hoja sin dias en toda su rama', function () use ($cfg) {
    MixCobro::planGuardadoMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([4 => ['dias' => '']]));
});
chequearLanza('costo + tasa de 100%', function () use ($cfg) {
    MixCobro::planGuardadoMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([4 => ['costo' => 0.6, 'tasa' => 0.4]]));
});
chequearLanza('un canal sin hojas', function () use ($cfg) {
    MixCobro::planGuardadoMes(arbolMM(), $cfg, 'LOCALES', 12, filasMM([1 => ['activo' => false],
        2 => ['activo' => false]]));
});

$prev = MixCobro::previsualizar(arbolMM(), 'LOCALES', filasMM([3 => ['porcentaje' => 0.2]]), 12, $cfg);

chequear('mientras se edita, el error vuelve como error y no como excepcion', [false, 1],
    [$prev['valido'], count($prev['errores'])]);

// ============================================================================

seccion('inhabilitar en el constante, y tambien en los meses cargados');

$actuales = arbolMM();
$filasApaga = filasMM([1 => ['activo' => false], 2 => ['porcentaje' => 1.0]]);
$camb = MixCobro::resolverCambios($actuales, 'LOCALES', $filasApaga);

chequear('apagar Efectivo en el constante deja diciembre con 90%: no se guarda nada', true, (function () use ($actuales, $camb, $cfg) {
    try {
        MixCobro::planInhabilitarEnMeses($actuales, $camb['simulado'], $cfg, 'LOCALES', $camb['cambios']);
    } catch (Exception $e) {
        return strpos($e->getMessage(), 'No se guardó nada: al inhabilitarlo también en Diciembre, ese mes '
            . 'queda mal. Los medios en juego de Locales suman 90,00%') === 0;
    }
    return false;
})());

$cfgDicSinEfectivo = configMM('VARIABLE', [valorMM(100, 1, 0.0, null, null, 1), valorMM(100, 2, 1.0),
    valorMM(100, 3, 0.4, 0.03, null, 7), valorMM(100, 4, 0.6, 0.05, null, 20)]);
$enMeses = MixCobro::planInhabilitarEnMeses($actuales, $camb['simulado'], $cfgDicSinEfectivo, 'LOCALES',
    $camb['cambios']);

chequear('con diciembre en 0% para Efectivo, apagarlo ahi tambien es valido', [12 => ['id' => 100,
    'cambios' => [['id' => 1, 'existe' => true, 'campos' => ['ACTIVO' => 0]]]]], $enMeses);
chequear('un mes dado de baja no se toca', false, isset($enMeses[2]));
chequear('sin nada apagado, ningun mes', [], MixCobro::planInhabilitarEnMeses($actuales, $actuales,
    $cfg, 'LOCALES', [['id' => 4, 'campos' => ['DIAS_ACREDITACION' => 3]]]));

// ============================================================================

seccion('copiar un mes a otros');

$copia = MixCobro::planCopia(arbolMM(), $cfg, 'LOCALES', 12, [1, '2']);

chequear('enero es nuevo, febrero reactiva su fila', [1 => null, 2 => 101],
    array_map(function ($p) { return $p['id']; }, $copia));
chequear('cada destino lleva todos los nodos con los valores de diciembre', [0.4, 0.6, 20],
    [$copia[1]['cambios'][2]['campos']['PORCENTAJE'], $copia[1]['cambios'][3]['campos']['PORCENTAJE'],
     $copia[1]['cambios'][3]['campos']['DIAS_ACREDITACION']]);
chequear('febrero ya tenia filas: se actualizan', [true, true, true, true],
    array_column($copia[2]['cambios'], 'existe'));
chequear('solo los campos del mes, sin auditoria', MixCobro::CAMPOS_MES,
    array_keys($copia[1]['cambios'][0]['campos']));

chequearLanza('un mes sin mix propio no se copia', function () use ($cfg) {
    MixCobro::planCopia(arbolMM(), $cfg, 'LOCALES', 11, [1]);
}, 'Noviembre usa el mix constante: guardalo como mes propio antes de copiarlo.');
chequearLanza('ni sobre si mismo', function () use ($cfg) {
    MixCobro::planCopia(arbolMM(), $cfg, 'LOCALES', 12, [12]);
});
chequearLanza('ni sin destinos', function () use ($cfg) {
    MixCobro::planCopia(arbolMM(), $cfg, 'LOCALES', 12, []);
});

// ============================================================================

seccion('lo que recibe el editor');

$ed = MixCobro::datosEditor(arbolMM(), 'NODO', $cfg);

chequear('con el script del mix por mes', true, $ed['por_mes']);
chequear('sin config, todo constante y sin mix por mes', [false, 'CONSTANTE'],
    [MixCobro::datosEditor(arbolMM(), 'NODO')['por_mes'], MixCobro::datosEditor(arbolMM(), 'NODO')['modos']['LOCALES']]);
chequear('los doce meses por canal', 12, count($ed['meses']['LOCALES']));
chequear('diciembre cargado, con sus valores por clave', [true, 0.6, true],
    [$ed['meses']['LOCALES'][12]['cargado'], $ed['meses']['LOCALES'][12]['valores']['N4']['PORCENTAJE'],
     $ed['meses']['LOCALES'][12]['valores']['N4']['propio']]);
chequear('noviembre sin cargar, con los del constante', [false, 0.3, false],
    [$ed['meses']['LOCALES'][11]['cargado'], $ed['meses']['LOCALES'][11]['valores']['N4']['PORCENTAJE'],
     $ed['meses']['LOCALES'][11]['valores']['N4']['propio']]);
chequear('los nombres de los meses', 'Diciembre', $ed['nombres_mes'][11]);

// ============================================================================
// Las escrituras, con la lectura y la escritura reemplazadas
// ============================================================================

/**
 * NO ESCRIBE EN NINGUNA TABLA: cada escritura solo anota, y la conexion es la
 * de la prueba. Lo que corre del codigo de produccion son los metodos
 * publicos: validar, abrir la transaccion, escribir en orden, confirmar.
 */
class MixCobroMesPrueba extends MixCobro {
    public $nodos = [];
    public $config = null;
    public $cid = null;
    public $conexiones = 0;
    public $escritos = [];

    public function tablaCreada() { return true; }
    public function tablasMesCreadas() { return true; }
    public function getNodos() { return $this->nodos; }
    public function getConfig() { return $this->config; }

    protected function conexionEscritura() {
        $this->conexiones++;

        return $this->cid;
    }

    protected function escribirNodo($cid, $id, $campos, $usuario) {
        $this->escritos[] = ['nodo', $id, $campos];
    }

    protected function insertarNodo($cid, $fila, $orden, $usuario) {
        $this->escritos[] = ['alta', $fila['NOMBRE'], $orden];

        return 50;
    }

    protected function escribirModo($cid, $canal, $modo, $usuario) {
        $this->escritos[] = ['modo', $canal, $modo];
    }

    protected function asegurarMes($cid, $canal, $mes, $idMes, $usuario) {
        $this->escritos[] = ['mes', $canal, $mes, $idMes];

        return $idMes === null ? 900 + $mes : $idMes;
    }

    protected function escribirValorMes($cid, $idMes, $idNodo, $campos, $existe, $usuario) {
        $this->escritos[] = ['valor', $idMes, $idNodo, $existe, $campos];
    }

    protected function bajaMes($cid, $idMes, $usuario) {
        $this->escritos[] = ['baja', $idMes];
    }
}

seccion('las escrituras de prueba no escriben en ninguna tabla real');

$rcMM = new ReflectionClass('MixCobroMesPrueba');
$fuenteMM = implode('', array_slice(file(__FILE__),
    $rcMM->getStartLine() - 1, $rcMM->getEndLine() - $rcMM->getStartLine() + 1));

chequear('la subclase no nombra ninguna tabla real', 0, preg_match('/RO_T_|dbo\./i', $fuenteMM));
chequear('y no llama a las escrituras originales', false, strpos($fuenteMM, 'parent::') !== false);

/** Una prueba nueva, con el arbol y la config de siempre */
function pruebaMM($cfg = null) {
    $p = new MixCobroMesPrueba();
    $p->nodos = arbolMM();
    $p->config = $cfg === null ? configMM() : $cfg;

    return $p;
}

seccion('un mes invalido se rechaza en el servidor, antes de abrir nada');

$p = pruebaMM();

chequearLanza('el grupo que no suma 100%', function () use ($p) {
    $p->guardarMes('LOCALES', 12, filasMM([3 => ['porcentaje' => 0.2]]), 'prueba');
}, 'El mix de Diciembre de Locales no se guardó: Los medios en juego de Locales › Tarjeta suman 80,00% '
    . 'y tienen que sumar 100%.');
chequear('sin pedir la conexion de escritura ni escribir nada', [0, []], [$p->conexiones, $p->escritos]);
chequearLanza('sin usuario no se guarda', function () use ($p) {
    $p->guardarMes('LOCALES', 12, filasMM([4 => ['dias' => '21']]), '');
});
chequear('un mes cargado sin cambios no abre nada', [0, 0],
    [$p->guardarMes('LOCALES', 12, [], 'prueba'), $p->conexiones]);

seccion('el modo y "Usar el constante en este mes"');

$p = pruebaMM();

chequear('pasar Locales a constante escribe el modo', [true, [['modo', 'LOCALES', 'CONSTANTE']]],
    [$p->guardarModo('LOCALES', 'constante', 'prueba'), $p->escritos]);
chequear('y no toca ningun mes', 0, count(array_filter($p->escritos, function ($e) { return $e[0] !== 'modo'; })));

$p = pruebaMM();

chequear('el mismo modo que ya tiene: nada', [false, []], [$p->guardarModo('LOCALES', 'VARIABLE', 'prueba'), $p->escritos]);
chequearLanza('un modo que no existe', function () use ($p) {
    $p->guardarModo('LOCALES', 'MENSUAL', 'prueba');
});

$p = pruebaMM();
$p->quitarMes('LOCALES', 12, 'prueba');

chequear('usar el constante en diciembre da de baja su fila, sin borrar valores', [['baja', 100]], $p->escritos);
chequearLanza('un mes que ya usa el constante', function () use ($p) {
    $p->quitarMes('LOCALES', 2, 'prueba');
}, 'Febrero de Locales ya usa el mix constante.');

seccion('las escrituras con transaccion');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base no hay transaccion que abrir');
} else {
    $conexionMM = new Conexion;
    $cidMM = $conexionMM->conectar('central');

    $p = pruebaMM();
    $p->cid = $cidMM;

    chequear('guardar noviembre: escribe los cuatro nodos', 4,
        $p->guardarMes('LOCALES', 11, filasMM([3 => ['porcentaje' => 0.5], 4 => ['porcentaje' => 0.5]]), 'prueba'));
    chequear('primero carga el mes, despues sus valores', ['mes', 'valor', 'valor', 'valor', 'valor'],
        array_column($p->escritos, 0));
    chequear('el mes es nuevo y los valores van a su ID', [null, 911, 911],
        [$p->escritos[0][3], $p->escritos[1][1], $p->escritos[4][1]]);

    $p = pruebaMM();
    $p->cid = $cidMM;

    chequear('volver a cargar febrero reactiva su fila', 101, (function () use ($p) {
        $p->guardarMes('LOCALES', 2, filasMM([1 => ['porcentaje' => 0.2], 2 => ['porcentaje' => 0.8]]), 'prueba');
        return $p->escritos[0][3];
    })());
    chequear('y actualiza sus valores', true, $p->escritos[1][3]);

    $p = pruebaMM();
    $p->cid = $cidMM;

    chequear('copiar diciembre a enero y febrero: dos meses', 2,
        $p->copiarMes('LOCALES', 12, [1, 2], 'prueba'));
    chequear('cada uno con su mes y sus cuatro valores', ['mes', 'valor', 'valor', 'valor', 'valor',
        'mes', 'valor', 'valor', 'valor', 'valor'], array_column($p->escritos, 0));

    $p = pruebaMM(configMM('VARIABLE', [valorMM(100, 1, 0.0, null, null, 1), valorMM(100, 2, 1.0),
        valorMM(100, 3, 0.4, 0.03, null, 7), valorMM(100, 4, 0.6, 0.05, null, 20)]));
    $p->cid = $cidMM;
    $p->guardarCanal('LOCALES', $filasApaga, 'prueba', true);

    chequear('inhabilitar en el constante y en diciembre, en la misma transaccion', [
        ['nodo', 1, ['ACTIVO' => 0]], ['nodo', 2, ['PORCENTAJE' => 1.0]],
        ['valor', 100, 1, true, ['ACTIVO' => 0]]
    ], $p->escritos);

    $p = pruebaMM(configMM('VARIABLE', [valorMM(100, 1, 0.0, null, null, 1), valorMM(100, 2, 1.0),
        valorMM(100, 3, 0.4, 0.03, null, 7), valorMM(100, 4, 0.6, 0.05, null, 20)]));
    $p->cid = $cidMM;
    $p->guardarCanal('LOCALES', $filasApaga, 'prueba');

    chequear('sin la casilla, los meses no se tocan', ['nodo', 'nodo'], array_column($p->escritos, 0));

    $p = pruebaMM();
    $p->cid = $cidMM;

    chequear('con un mes que quedaria mal, no se escribe nada', [true, []], [(function () use ($p, $filasApaga) {
        try {
            $p->guardarCanal('LOCALES', $filasApaga, 'prueba', true);
        } catch (Exception $e) {
            return true;
        }
        return false;
    })(), $p->escritos]);

    $p = pruebaMM();
    $p->cid = $cidMM;

    chequear('un nodo nuevo entra en el constante', 50, $p->agregarNodo(['canal' => 'LOCALES', 'id_padre' => 2,
        'nombre' => 'Go Cuotas', 'nivel' => 'TIPO_TARJETA', 'dias' => '10', 'costo' => 0.02], 'prueba'));
    chequear('y en cada mes con fila de su canal, cargado o de baja: inhabilitado y en 0%', [
        ['alta', 'Go Cuotas', 5],
        ['valor', 100, 50, false, ['PORCENTAJE' => 0.0, 'COSTO' => 0.02, 'TASA' => null,
                                   'DIAS_ACREDITACION' => 10, 'ACTIVO' => 0]],
        ['valor', 101, 50, false, ['PORCENTAJE' => 0.0, 'COSTO' => 0.02, 'TASA' => null,
                                   'DIAS_ACREDITACION' => 10, 'ACTIVO' => 0]]
    ], $p->escritos);
}

seccion('las escrituras reales: auditoria y sin bajas fisicas');

$fuenteMix = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/MixCobro.php'));

chequear('ningun DELETE', 0, preg_match('/\bDELETE\b/', $fuenteMix));
chequear('la baja de un mes sella USUARIO_BAJA y FECHA_BAJA', true,
    strpos($fuenteMix, '" SET ACTIVO = 0, " . Auditoria::SET_BAJA . " WHERE ID = ?"') !== false);
chequear('reactivar un mes limpia la baja', true,
    strpos($fuenteMix, 'SET ACTIVO = 1, USUARIO_BAJA = NULL, FECHA_BAJA = NULL, "') !== false);
chequear('los cuatro INSERT -nodo, modo, mes y valor- llevan usuario de alta y de modificacion', 4,
    substr_count($fuenteMix, 'USUARIO_ALTA, USUARIO_MODIF)'));

seccion('el controller: el mes se valida en el servidor');

$ctrlMM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Controller/ParametrosController.php'));

chequear('el mes del cuerpo pasa por MixCobro::validarMes()', true,
    strpos($ctrlMM, "return MixCobro::validarMes(\$data['mes']);") !== false);
chequear('la previsualizacion resuelve el mes con la config', true,
    strpos($ctrlMM, "\$mes, \$arbol['config'])") !== false);
chequear('guardar un mes va a guardarMes(), el constante a guardarCanal() con los nodos tildados', [1, 1], [
    substr_count($ctrlMM, "\$mixCobro->guardarMes(\$data['canal'], \$mes, \$data['nodos'], \$usuario)"),
    substr_count($ctrlMM, "count(\$enMeses) ? \$enMeses : false")
]);

seccion('la casilla se contesta nodo por nodo');

// Apagar Efectivo y Debito en el constante, con la casilla tildada solo para
// Efectivo: en diciembre se apaga solo Efectivo.
$cfgDos = configMM('VARIABLE', [valorMM(100, 1, 0.0, null, null, 1), valorMM(100, 2, 1.0),
    valorMM(100, 3, 0.4, 0.03, null, 7), valorMM(100, 4, 0.6, 0.05, null, 20)]);
$filasDos = filasMM([1 => ['activo' => false], 2 => ['porcentaje' => 1.0], 3 => ['activo' => false],
                     4 => ['porcentaje' => 1.0]]);
$cambDos = MixCobro::resolverCambios(arbolMM(), 'LOCALES', $filasDos);

chequear('solo los nodos tildados se apagan en los meses', [['id' => 1, 'existe' => true, 'campos' => ['ACTIVO' => 0]]],
    MixCobro::planInhabilitarEnMeses(arbolMM(), $cambDos['simulado'], $cfgDos, 'LOCALES', $cambDos['cambios'], [1])[12]['cambios']);

seccion('el editor: el mes viaja, la estructura solo en el constante');

$jsMM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Js/Parametros.js'));
$tabMM = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Tabs/parametros.php'));

chequear('la previsualizacion manda el mes que se ve', true,
    strpos($jsMM, "{ canal: canal, mes: mesDe(canal), nodos: nodosEnPantalla(canal) }") !== false);
chequear('el guardado manda el mes y los nodos tildados para apagar en los meses', true,
    strpos($jsMM, "{ canal: canal, mes: mes, nodos: nodosEnPantalla(canal), inhabilitar_en_meses: enMeses }") !== false);
chequear('en un mes, el nombre, el nivel y el + van solo con estructura', 3,
    substr_count($jsMM, 'if (estructura') );
chequear('las acciones nuevas del mes', [1, 1, 1], [
    substr_count($jsMM, "action=saveMixModo'"), substr_count($jsMM, "action=copiarMixMes'"),
    substr_count($jsMM, "action=quitarMixMes'")
]);
chequear('sin permiso se eligen meses igual: los cargados vienen resueltos en el payload', true,
    strpos($jsMM, 'datosMix().vistas_mes') !== false);
chequear('Exportar baja la tabla de todos los meses', true,
    strpos($tabMM, 'data-exportar="tablaMixExport"') !== false && strpos($tabMM, '<table id="tablaMixExport"') !== false);
chequear('el dialogo de casillas existe en Notificacion', true,
    strpos(file_get_contents(__DIR__ . '/../Js/notificaciones.js'), 'elegirOpciones: elegirOpciones,') !== false);

$edMM = MixCobro::datosEditor(arbolMM(), 'NODO', configMM());

chequear('el payload trae resuelto solo lo cargado: diciembre de Locales', ['LOCALES' => [12]],
    array_map('array_keys', $edMM['vistas_mes']));
chequear('con sus valores: Credito 90% x 60%', 0.54,
    round($edMM['vistas_mes']['LOCALES'][12]['nodos']['N4']['porcentaje_efectivo'], 6));
