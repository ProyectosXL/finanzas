<?php
/**
 * Proveedores Locales: las cinco listas de opciones del maestro.
 *
 * Lo que se prueba es la REGLA: si un valor pertenece a su lista, que pasa
 * cuando no, y de donde salen los dias de un plazo. Todo eso es texto y
 * funciones puras -las listas llegan por parametro, ya resueltas- asi que se
 * verifica sin base y sin archivos, igual que el resto del modulo.
 *
 * Lo que NO se prueba aca es el ABM contra la tabla: eso es SQL.
 */

require_once __DIR__ . '/../Class/ProveedoresOpciones.php';
require_once __DIR__ . '/../Class/ProveedoresCategorias.php';

/** Las cinco listas de juguete, con la forma que devuelve vigentes() */
function listasDePrueba() {
    return [
        'RUBRO_ECONOMICO' => [
            'Alquileres'  => ['valor' => 'Alquileres',  'plazo_dias' => null],
            'Mercaderia'  => ['valor' => 'Mercaderia',  'plazo_dias' => null],
            'Excluidos'   => ['valor' => 'Excluidos',   'plazo_dias' => null]
        ],
        'RUBRO' => [
            'Shoppings' => ['valor' => 'Shoppings', 'plazo_dias' => null],
            'Talleres'  => ['valor' => 'Talleres',  'plazo_dias' => null]
        ],
        'CENTRO_COSTOS' => [
            'Locales' => ['valor' => 'Locales', 'plazo_dias' => null],
            'Fabrica' => ['valor' => 'Fabrica', 'plazo_dias' => null]
        ],
        'PLAZO' => [
            'CONTADO'     => ['valor' => 'CONTADO',     'plazo_dias' => 0],
            '30 DIAS'     => ['valor' => '30 DIAS',     'plazo_dias' => 30],
            'DEBITO'      => ['valor' => 'DEBITO',      'plazo_dias' => null],
            'FIN DE MES'  => ['valor' => 'FIN DE MES',  'plazo_dias' => 30]
        ],
        'CRITERIO_DISTRIB' => [
            '100% LOCALES' => ['valor' => '100% LOCALES', 'plazo_dias' => null],
            '50% ECOMMERCE / 50% VENTAS' =>
                ['valor' => '50% ECOMMERCE / 50% VENTAS', 'plazo_dias' => null]
        ]
    ];
}

/** Una fila cruda del maestro, con todo bien salvo lo que se le pise */
function filaMaestro($extra = []) {
    return array_merge([
        'linea' => 2, 'cod_provee' => 'MTDODI', 'nombre' => 'DONNA DI DIO',
        'rubro_economico' => 'Alquileres', 'rubro' => 'Shoppings',
        'centro_costos' => 'Locales', 'forma_pago' => 'TRANSFERENCIA',
        'plazo_pago' => '30 DIAS', 'criterio_distrib' => '100% LOCALES'
    ], $extra);
}

/* ================================================================
   LAS CINCO LISTAS SON LA UNICA DEFINICION

   De TIPOS salen la pantalla de Parametros, el mapeo campo -> lista del
   formulario y el validador. Tres listas distintas se desincronizan en el
   primer cambio, que es exactamente lo que le paso a FORMAS_PAGO.
   ================================================================ */
seccion('las cinco listas estan declaradas en un solo lugar');

$tipos = ProveedoresOpciones::TIPOS;

chequear('son cinco', 5, count($tipos));

foreach (['RUBRO_ECONOMICO', 'RUBRO', 'CENTRO_COSTOS', 'PLAZO', 'CRITERIO_DISTRIB'] as $t) {
    chequear('esta ' . $t, true, isset($tipos[$t]));
}

// Cada una dice a que campo de la importacion corresponde: es lo que hace que
// el validador no tenga su propio mapeo escrito aparte.
foreach ($tipos as $tipo => $def) {
    chequear($tipo . ' declara su campo', true, !empty($def['campo']));
    chequear($tipo . ' declara su columna', true, !empty($def['columna']));
}

// El PLAZO es la unica que el sistema usa para calcular.
chequear('el tipo del plazo es PLAZO', 'PLAZO', ProveedoresOpciones::TIPO_PLAZO);

/* ================================================================
   PERTENECER A UNA LISTA

   La comparacion es TOLERANTE -ignora mayusculas, acentos y espacios- y usa
   el mismo normalizador que las formas de pago. 'alquileres' y 'ALQUILERES '
   son el mismo rubro.
   ================================================================ */
seccion('un valor de la lista se encuentra');

$listas = listasDePrueba();

$r = ProveedoresOpciones::buscarEnLista('Alquileres', $listas['RUBRO_ECONOMICO']);

chequear('esta', 'Alquileres', $r['valor']);

seccion('y se encuentra aunque venga escrito distinto');

foreach (['ALQUILERES', 'alquileres', ' Alquileres ', 'Alquíleres'] as $variante) {
    $r = ProveedoresOpciones::buscarEnLista($variante, $listas['RUBRO_ECONOMICO']);

    chequear('"' . $variante . '" matchea', 'Alquileres', $r === null ? null : $r['valor']);
}

seccion('lo que no esta, no esta');

chequear('un valor ajeno', null,
    ProveedoresOpciones::buscarEnLista('Logistica', $listas['RUBRO_ECONOMICO']));

// EL CASO REAL DE LA PLANILLA: '50% ECOMMERC' por '50% ECOMMERCE'. Antes se
// detectaba a posteriori comparando parecidos, porque no habia lista.
chequear('el typo de ECOMMERC no matchea', null,
    ProveedoresOpciones::buscarEnLista('50% ECOMMERC / 50% VENTAS',
        $listas['CRITERIO_DISTRIB']));

seccion('un valor vacio no esta fuera de lista: esta vacio');

// Son dos cosas distintas y se cuentan aparte. En la planilla real hay 84 filas
// sin rubro economico y 765 sin plazo: marcarlas como valor invalido ahogaria
// el aviso de las que si tienen un typo.
chequear('vacio da null', null,
    ProveedoresOpciones::buscarEnLista('', $listas['RUBRO_ECONOMICO']));
chequear('solo espacios tambien', null,
    ProveedoresOpciones::buscarEnLista('   ', $listas['RUBRO_ECONOMICO']));

/* ================================================================
   UN VALOR FUERA DE LISTA ES UN ERROR Y LA FILA NO SE CARGA

   ESTO SE INVIRTIO. Era una advertencia: la fila se importaba igual y se
   guardaba con lo que vino, marcada. El argumento era que un rubro raro
   clasifica -crea su propia serie- mientras que un codigo inexistente no
   clasifica nada, y era cierto: lo que no alcanzaba era el resultado, porque la
   serie del tablero quedaba creada igual y el aviso se leia despues.

   Lo que NO cambio: el alcance sigue siendo POR FILA, y el valor se sigue
   guardando tal como vino en la fila que se muestra.
   ================================================================ */
seccion('una fila con todo en lista no se marca');

$f = ProveedoresCategorias::normalizarFila(filaMaestro(), $listas);

chequear('nada fuera de lista', 0, count($f['fuera_lista']));
chequear('y el estado no cambia', 'ALTA', $f['estado']);

seccion('un valor fuera de lista deja la fila en ERROR');

$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'Logistica']), $listas);

chequear('se marca el campo', true, isset($f['fuera_lista']['RUBRO_ECONOMICO']));
chequear('con el valor que vino', 'Logistica', $f['fuera_lista']['RUBRO_ECONOMICO']);
chequear('y la fila no se carga', 'ERROR', $f['estado']);

/* EL MOTIVO TIENE QUE NOMBRAR EL CAMPO Y EL VALOR. Con cinco listas, un "hay un
   valor invalido" obliga a comparar los cinco campos contra las cinco listas
   para saber cual es. Y tiene que decir DONDE se arregla, porque el arreglo casi
   nunca es corregir la planilla: es dar de alta el valor en Parametros. */
chequear('el motivo nombra el campo', true,
    stripos($f['motivo'], 'rubro económico') !== false);
chequear('y el valor', true, strpos($f['motivo'], 'Logistica') !== false);
chequear('y dice donde se agrega', true,
    strpos($f['motivo'], 'Parámetros → Prov. Locales') !== false);

// Y EL VALOR NO SE CORRIGE NI SE PIERDE. El original es la evidencia de lo que
// hay que arreglar, y la previsualizacion lo muestra.
chequear('el valor viaja tal como vino', 'Logistica', $f['rubro_economico']);

seccion('un valor que SI matchea no es error, aunque este escrito distinto');

/* La comparacion sigue siendo tolerante: 'ALQUILERES' es 'Alquileres'. Si esto
   se rompiera, la planilla real quedaria rechazada entera por mayusculas. */
$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'ALQUILERES']), $listas);

chequear('no se marca', 0, count($f['fuera_lista']));
chequear('no es error', 'ALTA', $f['estado']);
chequear('y se guarda como vino, no como el canonico', 'ALQUILERES', $f['rubro_economico']);

seccion('un campo VACIO sigue siendo valido');

/* EL CASO QUE HARIA QUE NO SE PUEDA IMPORTAR NADA: en la planilla real hay 84
   filas sin rubro economico y 765 sin plazo. Vacio no es un valor fuera de
   lista: es vacio, y ya se cuenta aparte. */
$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => '', 'plazo_pago' => '']), $listas);

chequear('no se marca nada', 0, count($f['fuera_lista']));
chequear('y la fila se carga', 'ALTA', $f['estado']);
chequear('el rubro queda en null', null, $f['rubro_economico']);

// Ni los espacios sueltos, que es como vienen muchas celdas de Excel.
$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['centro_costos' => '   ']), $listas);

chequear('solo espacios tampoco', 'ALTA', $f['estado']);

seccion('sin listas cargadas no se valida nada');

/* null es "no hay listas contra las cuales validar" -no se corrio el script- y
   ahi el comportamiento es el de antes: texto libre. Dejar en error el maestro
   entero porque no existe la tabla contra la cual validarlo seria apagar el
   modulo por una configuracion pendiente. */
$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'Cualquier Cosa']), null);

chequear('no se marca', 0, count($f['fuera_lista']));
chequear('ni es error', 'ALTA', $f['estado']);
chequear('y el valor esta', 'Cualquier Cosa', $f['rubro_economico']);

seccion('las cinco listas se validan, no solo el rubro');

$f = ProveedoresCategorias::normalizarFila(filaMaestro([
    'rubro_economico' => 'Logistica',
    'rubro' => 'Depositos',
    'centro_costos' => 'Sucursal',
    'plazo_pago' => '45 DIAS',
    'criterio_distrib' => '100% NADA'
]), $listas);

chequear('las cinco quedan marcadas', 5, count($f['fuera_lista']));
chequear('incluido el criterio', '100% NADA', $f['fuera_lista']['CRITERIO_DISTRIB']);
chequear('y es un solo error', 'ERROR', $f['estado']);

// Con varios campos mal, el motivo los nombra a TODOS: corregir de a uno y
// volver a importar para descubrir el siguiente son cinco vueltas.
chequear('el motivo nombra los cinco valores', 5, count(array_filter(
    ['Logistica', 'Depositos', 'Sucursal', '45 DIAS', '100% NADA'],
    function ($v) use ($f) { return strpos($f['motivo'], $v) !== false; })));

seccion('un error del CODIGO le gana al de fuera de lista');

/* Los dos son errores y el motivo es uno solo, asi que hay que elegir. Gana el
   del codigo porque dice que hacer -'tiene 7 caracteres y en Tango son 6'-
   mientras que el otro discutiria el rubro de un proveedor que no puede
   existir. La marca 'fuera_lista' viaja igual, y la pantalla la muestra al
   lado. */
$f = ProveedoresCategorias::normalizarFila(filaMaestro([
    'cod_provee' => 'DEMASIADOLARGO', 'rubro_economico' => 'Logistica'
]), $listas);

chequear('es error', 'ERROR', $f['estado']);
chequear('y el motivo habla del codigo', true, strpos($f['motivo'], 'caracteres') !== false);
chequear('esta marcado como error de codigo', true, $f['error_codigo']);

/* ================================================================
   EL RESUMEN Y EL AVISO DE LA IMPORTACION
   ================================================================ */
seccion('el resumen cuenta filas, y el desglose cuenta campos');

$filaImp = function ($linea, $extra = []) {
    return array_merge(filaMaestro(['linea' => $linea, 'cod_provee' => 'PRV' . $linea]), $extra);
};

$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Logistica', 'rubro' => 'Depositos']),
    $filaImp(3, ['rubro_economico' => 'Logistica']),
    $filaImp(4)
], [], null, $listas);

// UNA FILA CUENTA UNA VEZ aunque tenga tres campos mal: el numero de arriba es
// "cuantas filas hay que arreglar".
chequear('dos filas para arreglar', 2, $c['resumen']['fuera_de_lista']);

// El desglose por lista cuenta campos, porque dice CUAL de las cinco listas
// esta incompleta.
chequear('dos rubros economicos', 2, $c['resumen']['fuera_de_lista_por_tipo']['RUBRO_ECONOMICO']);
chequear('y un rubro', 1, $c['resumen']['fuera_de_lista_por_tipo']['RUBRO']);

// EL ALCANCE ES POR FILA: las dos malas no se cargan y la buena si. Es el mismo
// criterio que ya regia para el codigo fuera de CPA01.
chequear('solo se carga la que esta bien', 1, $c['resumen']['altas']);
chequear('y las otras dos son error', 2, $c['resumen']['errores']);

seccion('los valores rechazados se juntan agrupados y sin repetir');

/* ES LO QUE HACE APLICABLE EL CAMBIO. Con la planilla real -1.223 filas-
   descubrirlos de a uno seria corregir, reimportar, encontrar el siguiente. */
$c2 = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['centro_costos' => 'Deposito Sur']),
    $filaImp(3, ['centro_costos' => 'DEPOSITO SUR']),
    $filaImp(4, ['centro_costos' => 'Deposito Norte'])
], [], null, $listas);

$ccostos = $c2['resumen']['valores_fuera_de_lista']['CENTRO_COSTOS'];

// 'Deposito Sur' y 'DEPOSITO SUR' son UN valor que dar de alta, no dos: se
// comparan igual que los compara la lista.
chequear('dos valores distintos, no tres', 2, count($ccostos));
chequear('estan los dos', true,
    in_array('Deposito Norte', $ccostos, true) && in_array('Deposito Sur', $ccostos, true));

// Pero las TRES filas se rechazan: son tres filas que no se van a cargar.
chequear('las tres filas quedan afuera', 3, $c2['resumen']['fuera_de_lista']);

/* ================================================================
   UNA FILA RECHAZADA NO ES UNA FILA AUSENTE

   Es el riesgo mas grave de este cambio, y es silencioso: las BAJAS son "el
   archivo no los trae", asi que si una fila rechazada no cuenta como traida, el
   proveedor aparece en la lista de bajas. Con el interruptor de bajas tildado y
   cientos de filas rechazadas por valores fuera de lista, eso le borra la
   clasificacion a cientos de proveedores que la planilla SI trae.
   ================================================================ */
seccion('un proveedor cuya fila se rechaza NO se propone para baja');

$maestroVigente = [
    'PRV2' => [
        'COD_PROVEE' => 'PRV2', 'NOMBRE' => 'DONNA DI DIO',
        'RUBRO_ECONOMICO' => 'Alquileres', 'RUBRO' => 'Shoppings',
        'CENTRO_COSTOS' => 'Locales', 'FORMA_PAGO' => 'TRANSFERENCIA',
        'PLAZO_PAGO' => '30 DIAS', 'CRITERIO_DISTRIB' => '100% LOCALES'
    ]
];

$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Logistica'])
], $maestroVigente, null, $listas);

chequear('la fila queda en error', 1, $c['resumen']['errores']);
chequear('pero el proveedor NO se da de baja', 0, $c['resumen']['bajas']);
chequear('y no esta en la lista de bajas', 0, count($c['bajas']));

// Y el que el archivo REALMENTE no trae si se propone: la regla sigue viva.
$c = ProveedoresCategorias::compararImportacion([], $maestroVigente, null, $listas);

chequear('el que no viene si se da de baja', 1, $c['resumen']['bajas']);

seccion('y sigue entrando al control de duplicados');

/* Su codigo es valido: lo que esta mal es un valor. Si quedara afuera del
   control, un codigo repetido donde una de las dos filas tiene un rubro
   invalido cargaria la otra sin avisar que estaba repetido. */
$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['cod_provee' => 'REP', 'rubro_economico' => 'Logistica']),
    $filaImp(3, ['cod_provee' => 'REP'])
], [], null, $listas);

chequear('las dos quedan en error', 2, $c['resumen']['errores']);
chequear('y no se carga ninguna', 0, $c['resumen']['altas']);
chequear('la segunda dice que esta repetida', true,
    strpos($c['filas'][1]['motivo'], 'repetido') !== false);

// Al reves tambien: la limpia primero y la rechazada despues.
$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['cod_provee' => 'REP']),
    $filaImp(3, ['cod_provee' => 'REP', 'rubro_economico' => 'Logistica'])
], [], null, $listas);

chequear('tambien con la limpia primero', 2, $c['resumen']['errores']);
chequear('y ninguna se carga', 0, $c['resumen']['altas']);

// Un codigo que NO existe en CPA01 no entra al control: no se puede cargar ni
// una vez, asi que "esta repetido" contesta una pregunta que ya no importa.
$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['cod_provee' => 'REP']),
    $filaImp(3, ['cod_provee' => 'REP'])
], [], [], $listas);

chequear('las dos son error por no estar en Tango', 2, $c['resumen']['no_en_tango']);
chequear('y no por estar repetidas', false,
    strpos($c['filas'][1]['motivo'], 'repetido') !== false);

seccion('el aviso nombra las listas y dice que no se agregan solas');

// Se rearma el caso de arriba: los bloques del medio pisaron $c.
$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Logistica', 'rubro' => 'Depositos']),
    $filaImp(3, ['rubro_economico' => 'Logistica']),
    $filaImp(4)
], [], null, $listas);

$avisoLista = '';

foreach ($c['avisos'] as $a) {
    if (strpos($a, 'listas de opciones') !== false) { $avisoLista = $a; }
}

chequear('hay aviso', true, $avisoLista !== '');
chequear('nombra la lista del rubro economico', true,
    strpos($avisoLista, 'Rubro económico') !== false);

// Y dice que NO SE CARGAN, que es lo que cambio: el texto decia "se importan
// igual y se guardan tal como vinieron".
chequear('dice que no se cargan', true, strpos($avisoLista, 'NO SE CARGAN') !== false);

// ALGUIEN VA A ESPERAR QUE SE AGREGUEN SOLAS. Si la importacion ampliara las
// listas, se llenarian con los typos de la planilla y dejarian de validar nada.
chequear('dice que NO se agregan solos', true,
    strpos($avisoLista, 'NO se agregan solos') !== false);

// Y avisa lo que mas importa: el rubro economico arma filas del tablero.
chequear('avisa del efecto en el tablero', true,
    strpos($avisoLista, 'fila propia en el tablero') !== false);

/* ================================================================
   "NO EXISTE LA TABLA" Y "NO SE PUDO LEER" DECIDEN COSAS OPUESTAS

   Los dos daban el mismo null hasta que las listas pasaron a ser regla. Ahora
   uno deja pasar todo y el otro no deja confirmar nada, asi que confundirlos
   seria importar una planilla entera sin haber validado ni una fila.
   ================================================================ */
seccion('sin tabla de opciones se importa igual y se puede confirmar');

// CPA01 se pudo leer y el codigo existe: lo unico en juego acá son las listas.
$enTango = ['PRV2' => 'PROVEEDOR DE PRUEBA'];

$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Cualquier Cosa'])
], [], $enTango, null);

chequear('ninguna fila marcada', 0, $c['resumen']['fuera_de_lista']);
chequear('la fila se carga', 1, $c['resumen']['altas']);
chequear('el resumen dice que no se valido contra listas', false,
    $c['resumen']['valido_contra_listas']);
chequear('pero NO es un origen caido', false, $c['resumen']['listas_ilegibles']);
chequear('y se puede confirmar', true, $c['resumen']['puede_confirmar']);

seccion('con las listas ilegibles no se puede confirmar');

$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Cualquier Cosa'])
], [], $enTango, ProveedoresCategorias::LISTAS_ILEGIBLES);

// NO SE MARCA NINGUNA FILA: no es que esten todas mal, es que no se chequeo
// ninguna. Marcarlas seria informar como malas filas que no se miraron.
chequear('ninguna fila marcada', 0, $c['resumen']['fuera_de_lista']);
chequear('se dice que no se leyeron', true, $c['resumen']['listas_ilegibles']);

// PERO NO SE PUEDE APLICAR: sin haber chequeado ninguna no hay un subconjunto
// de filas validas que dejar pasar, que es lo que supone el alcance por fila.
chequear('y no se puede confirmar', false, $c['resumen']['puede_confirmar']);
chequear('con un motivo', 1, count($c['resumen']['bloqueos']));
chequear('que nombra Parametros', true,
    strpos($c['resumen']['bloqueos'][0], 'Prov. Locales') !== false);
chequear('y manda a probar de nuevo, no a corregir la planilla', true,
    strpos($c['resumen']['bloqueos'][0], 'de nuevo en un rato') !== false);

// La previsualizacion se muestra igual: ver que cambiaria no hace daño.
chequear('la previsualizacion igual dice que traeria un alta', 1, $c['resumen']['altas']);

seccion('sin listas no hay aviso de listas');

$c = ProveedoresCategorias::compararImportacion([
    $filaImp(2, ['rubro_economico' => 'Cualquier Cosa'])
], [], null, null);

chequear('ninguna fila marcada', 0, $c['resumen']['fuera_de_lista']);
chequear('y ningun aviso', 0, count(array_filter($c['avisos'], function ($a) {
    return strpos($a, 'listas de opciones') !== false;
})));

/* ================================================================
   EL PLAZO ES LA UNICA LISTA QUE EL SISTEMA USA PARA CALCULAR

   Sus dias son el ultimo escalon de la jerarquia de resolucion de fecha de
   pago. Perder esa semantica cambiaria la fecha de pago de todos los
   proveedores con DEBITO.
   ================================================================ */
seccion('los dias del plazo salen de la lista cuando el valor esta en ella');

$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => '30 DIAS']), $listas);

chequear('treinta dias', 30, $f['plazo_dias']);
chequear('y el plazo es usable', false, $f['plazo_no_usable']);

seccion('y eso permite declarar un plazo que el texto no explica');

/* ES LA RAZON POR LA QUE LA LISTA GUARDA LOS DIAS. plazoEnDias() no sabria
   interpretar 'FIN DE MES' -no empieza con un numero, no es CONTADO ni
   DEBITO- y devolveria null. La lista lo declara en 30. */
chequear('plazoEnDias sola no puede', null, ProveedoresCategorias::plazoEnDias('FIN DE MES'));

$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => 'FIN DE MES']), $listas);

chequear('con la lista, son 30', 30, $f['plazo_dias']);
chequear('y el texto guardado no cambia', 'FIN DE MES', $f['plazo_pago']);

seccion('CONTADO son CERO dias, y eso no es lo mismo que sin plazo');

$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => 'CONTADO']), $listas);

// CERO SE USA PARA CALCULAR: se paga el dia de la factura.
chequear('cero', 0, $f['plazo_dias']);
chequear('y es usable', false, $f['plazo_no_usable']);

seccion('DEBITO no dice cuando, y sigue sin decirlo');

$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => 'DEBITO']), $listas);

// NULL HACE CAER LA JERARQUIA al escalon siguiente. Ponerle 0 cambiaria la
// fecha de pago de todos esos proveedores.
chequear('null, no cero', null, $f['plazo_dias']);
chequear('y se cuenta como no usable', true, $f['plazo_no_usable']);

seccion('un plazo fuera de lista cae al fallback de siempre, y ya no se carga');

// La lista no lo tiene, asi que manda plazoEnDias(): recalcular no inventa nada
// y tampoco pierde lo que ya se sabia interpretar. Lo que SI cambio es que la
// fila no se importa: el plazo es una de las cinco listas.
$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => '45 DIAS']), $listas);

chequear('cuarenta y cinco', 45, $f['plazo_dias']);
chequear('queda marcado fuera de lista', true, isset($f['fuera_lista']['PLAZO']));
chequear('y la fila no se carga', 'ERROR', $f['estado']);

/* ================================================================
   LA VALIDACION DE LO QUE SE CARGA EN LAS LISTAS
   ================================================================ */
seccion('un valor de lista no puede estar vacio ni ser larguisimo');

chequearLanza('vacio', function () {
    ProveedoresOpciones::validarValor('RUBRO', '');
});

chequearLanza('solo espacios', function () {
    ProveedoresOpciones::validarValor('RUBRO', '   ');
});

// 60 es el largo de la columna del maestro: uno mas largo se guardaria cortado,
// y entonces la lista ofreceria un valor que el maestro no puede contener.
chequearLanza('mas de 60 caracteres', function () {
    ProveedoresOpciones::validarValor('RUBRO', str_repeat('A', 61));
});

chequear('sesenta justos entra', 60,
    strlen(ProveedoresOpciones::validarValor('RUBRO', str_repeat('A', 60))));

seccion('los espacios de los extremos se recortan');

// Lo que NO puede pasar es que entren "Alquileres" y "Alquileres " como dos
// opciones distintas, que es justo el problema que estas listas resuelven.
chequear('se recorta', 'Alquileres', ProveedoresOpciones::validarValor('RUBRO', '  Alquileres  '));

seccion('una lista que no existe se rechaza');

chequearLanza('tipo inventado', function () {
    ProveedoresOpciones::validarValor('COLOR_FAVORITO', 'Azul');
});

seccion('los dias de un plazo: vacio NO es cero');

// ES LA DISTINCION ENTERA DE ESTE CAMPO.
chequear('vacio es null', null, ProveedoresOpciones::validarDias(''));
chequear('null es null', null, ProveedoresOpciones::validarDias(null));
chequear('cero es CERO', 0, ProveedoresOpciones::validarDias(0));
chequear('y "0" tambien', 0, ProveedoresOpciones::validarDias('0'));
chequear('treinta', 30, ProveedoresOpciones::validarDias('30'));

chequearLanza('un texto no es un plazo', function () {
    ProveedoresOpciones::validarDias('treinta');
});

chequearLanza('negativo: correria la fecha hacia atras', function () {
    ProveedoresOpciones::validarDias(-5);
});

chequearLanza('mas de un anio', function () {
    ProveedoresOpciones::validarDias(400);
});

/* ================================================================
   LOS USOS

   Es lo que hace que dar de baja un valor no sea a ciegas.
   ================================================================ */
seccion('cuantos proveedores usan cada valor');

$maestro = [
    'A' => ['RUBRO_ECONOMICO' => 'Alquileres', 'RUBRO' => 'Shoppings',
            'CENTRO_COSTOS' => 'Locales', 'PLAZO_PAGO' => 'CONTADO',
            'CRITERIO_DISTRIB' => '100% LOCALES'],
    'B' => ['RUBRO_ECONOMICO' => 'Alquileres', 'RUBRO' => 'Talleres',
            'CENTRO_COSTOS' => 'Locales', 'PLAZO_PAGO' => '30 DIAS',
            'CRITERIO_DISTRIB' => null],
    'C' => ['RUBRO_ECONOMICO' => 'Mercaderia', 'RUBRO' => '',
            'CENTRO_COSTOS' => null, 'PLAZO_PAGO' => '30 DIAS',
            'CRITERIO_DISTRIB' => '100% LOCALES']
];

$usos = ProveedoresOpciones::usos($maestro);

chequear('dos alquileres', 2, $usos['RUBRO_ECONOMICO']['Alquileres']);
chequear('una mercaderia', 1, $usos['RUBRO_ECONOMICO']['Mercaderia']);
chequear('dos a 30 dias', 2, $usos['PLAZO']['30 DIAS']);

// Un campo vacio no es un valor: no se cuenta como uso de nada.
chequear('el rubro vacio no cuenta', false, isset($usos['RUBRO']['']));
chequear('ni el centro en null', 2, array_sum($usos['CENTRO_COSTOS']));

seccion('y vienen ordenados de mayor a menor uso');

// Si la lista es larga, lo que importa es cual se usa mas: es el que NO hay que
// tocar, y el que se usa una vez es el que probablemente sea un typo.
$primero = array_keys($usos['RUBRO_ECONOMICO'])[0];

chequear('primero el mas usado', 'Alquileres', $primero);

/* ================================================================
   LA PANTALLA
   ================================================================ */
seccion('la sub-pestana esta declarada y tiene donde dibujarse');

require_once __DIR__ . '/../Class/Parametros.php';

$codigos = array_map(function ($m) { return $m['codigo']; }, Parametros::getModulos());

chequear('PROV_LOCALES es un modulo de Parametros', true,
    in_array('PROV_LOCALES', $codigos, true));

$tabParam = file_get_contents(__DIR__ . '/../Tabs/parametros.php');

/* El id del pane sale de ucfirst(strtolower($codigo)), que es lo que usa el
   <li> generado desde $modulos. Si no coinciden, la sub-pestana se ve en la
   barra y al hacer clic no muestra nada. */
chequear('el pane tiene el id que genera la lista', true,
    strpos($tabParam, 'id="paneParamProv_locales"') !== false);
chequear('y se incluye su archivo', true,
    strpos($tabParam, "include __DIR__ . '/parametros_prov_locales.php'") !== false);
chequear('y se carga su JS', true,
    strpos($tabParam, 'Js/Parametros-Prov_locales.js') !== false);

seccion('el formulario del maestro no pierde un valor fuera de lista');

/* EL BUG QUE ESTO EVITA: si al campo se le pide un valor que su lista no tiene y
   el campo lo descarta, queda vacio EN SILENCIO, y guardar el formulario le
   borraria el rubro al proveedor sin que nadie lo haya pedido. El componente lo
   agrega como opcion al final, marcada, en vez de perderlo. */
$jsProv = file_get_contents(__DIR__ . '/../Js/Proveedores-Proveedores_locales.js');

chequear('el form usa elegirValor y no setValor a secas', true,
    strpos($jsProv, "elegirValor('fpRubroEcoProv'") !== false);
chequear('que conserva el valor que la lista no tiene', true,
    strpos($jsProv, 'fueraDeLista = (valor !== \'\' && lista.indexOf(valor) === -1)') !== false);

// Y el campo se marca: el valor se conserva, pero guardar lo va a rechazar, asi
// que tiene que verse antes de apretar Guardar y no despues.
chequear('y lo marca en el campo', true,
    strpos($jsProv, "input.classList.toggle('prov-fuera-lista', fuera)") !== false);

// Y sin listas cargadas no se reemplaza nada: los campos siguen siendo texto
// libre, que es como funcionaban antes.
chequear('sin listas, no se dibujan desplegables', true,
    strpos($jsProv, 'if (!listas) { return; }') !== false);

seccion('el desplegable del alta manual busca, y no acepta texto libre');

/* NO SE AGREGA NINGUNA LIBRERIA: el patron ya estaba resuelto a mano en el
   autocomplete de Tango, en este mismo archivo. Si alguien mete Select2 o
   Tom Select, esto lo dice. */
foreach (['select2', 'tom-select', 'tomselect', 'choices.js'] as $lib) {
    chequear('no aparece ' . $lib, false, stripos($jsProv, $lib) !== false);
}

// SE COMPORTA COMO UN <select>: expone .value de lectura y de escritura, que es
// lo unico que usan valor() y setValor(). Sin eso habria que tocar abrirForm()
// y guardarProveedor() tambien.
chequear('el componente expone .value', true,
    strpos($jsProv, "Object.defineProperty(caja, 'value'") !== false);

// SOLO SE ELIGE DE LA LISTA. Seria incoherente que el alta manual acepte por
// tipeo un valor que la importacion rechaza.
chequear('sin resultados dice donde se dan de alta los valores', true,
    strpos($jsProv, 'Ningún valor coincide.') !== false
    && strpos($jsProv, 'valores se dan de alta en Parámetros → Prov. Locales.') !== false);

seccion('los desplegables van en orden alfabetico, con locale es');

/* Con sort() pelado, la Ñ y los acentos se van al final por codigo de caracter,
   que en una lista de rubros escritos en castellano es donde nadie los busca. */
chequear('el front ordena con localeCompare en es', true,
    strpos($jsProv, "localeCompare(String(b), 'es', { sensitivity: 'base' })") !== false);

// Y el backend manda el mismo orden: si el SQL mandara otro, el que lo lea va a
// creer que ese orden significa algo.
$opcFuente = file_get_contents(__DIR__ . '/../Class/ProveedoresOpciones.php');

chequear('y el backend tambien ordena', true,
    strpos($opcFuente, 'self::alfabetico($vigentes)') !== false);

seccion('el orden alfabetico del backend no se rompe con acentos ni eñes');

$crudas = [
    ['VALOR' => 'Ñandú',      'PLAZO_DIAS' => null],
    ['VALOR' => 'Zapatos',    'PLAZO_DIAS' => null],
    ['VALOR' => 'Alquileres', 'PLAZO_DIAS' => null],
    ['VALOR' => 'Óptica',     'PLAZO_DIAS' => null],
    ['VALOR' => 'Nafta',      'PLAZO_DIAS' => null]
];

chequear('la eñe va entre la N y la O, no al final',
    ['Alquileres', 'Nafta', 'Ñandú', 'Óptica', 'Zapatos'],
    array_keys(ProveedoresOpciones::alfabetico($crudas)));

seccion('la columna ORDEN sigue existiendo y la pantalla dice para que');

/* NO SE BORRA: la usa el alta de opciones -el valor nuevo va al final- y la
   edicion desde Parametros. Lo que dejo de hacer es decidir el orden de los
   desplegables del alta manual, y un control que no hace lo que parece hacer es
   peor que no tenerlo, asi que la pantalla lo aclara. */
$jsParam = file_get_contents(__DIR__ . '/../Js/Parametros-Prov_locales.js');

chequear('el orden se sigue pudiendo editar', true, strpos($jsParam, 'pplo-orden') !== false);
chequear('y la pantalla dice que NO ordena el alta manual', true,
    strpos($jsParam, 'alfabético') !== false);
