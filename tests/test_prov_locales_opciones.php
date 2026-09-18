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

require_once __DIR__ . '/../cashflow/Class/ProveedoresOpciones.php';
require_once __DIR__ . '/../cashflow/Class/ProveedoresCategorias.php';

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
   LA VALIDACION EN LA IMPORTACION ES ADVERTENCIA, NO ERROR

   La fila se importa igual y se guarda con lo que vino. Es el mismo criterio
   que este modulo ya aplica a las formas de pago: la planilla viene sucia y
   eso se MUESTRA, no se arregla.
   ================================================================ */
seccion('una fila con todo en lista no se marca');

$f = ProveedoresCategorias::normalizarFila(filaMaestro(), $listas);

chequear('nada fuera de lista', 0, count($f['fuera_lista']));
chequear('y el estado no cambia', 'ALTA', $f['estado']);

seccion('un valor fuera de lista se marca, pero la fila se importa');

$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'Logistica']), $listas);

chequear('se marca el campo', true, isset($f['fuera_lista']['RUBRO_ECONOMICO']));
chequear('con el valor que vino', 'Logistica', $f['fuera_lista']['RUBRO_ECONOMICO']);

// NO ES UN ERROR: no clasifica mal, clasifica en una serie que quiza no tenia
// que existir, y eso lo decide una persona.
chequear('pero NO es un error', 'ALTA', $f['estado']);

// Y EL VALOR NO SE CORRIGE. Pisarlo al canonico cambiaria en silencio la serie
// del tablero de ese proveedor, y el original es la evidencia del typo.
chequear('el valor se guarda tal como vino', 'Logistica', $f['rubro_economico']);

seccion('el valor tampoco se corrige cuando SI matchea');

$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'ALQUILERES']), $listas);

chequear('no se marca', 0, count($f['fuera_lista']));
chequear('pero se guarda como vino, no como el canonico', 'ALQUILERES', $f['rubro_economico']);

seccion('sin listas cargadas no se marca nada');

// null es "no hay listas contra las cuales validar" -el script no se corrio- y
// ahi el comportamiento es el de antes: texto libre.
$f = ProveedoresCategorias::normalizarFila(
    filaMaestro(['rubro_economico' => 'Cualquier Cosa']), null);

chequear('no se marca', 0, count($f['fuera_lista']));
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
// "cuantas filas hay que mirar".
chequear('dos filas para mirar', 2, $c['resumen']['fuera_de_lista']);

// El desglose por lista cuenta campos, porque dice CUAL de las cinco listas
// esta incompleta.
chequear('dos rubros economicos', 2, $c['resumen']['fuera_de_lista_por_tipo']['RUBRO_ECONOMICO']);
chequear('y un rubro', 1, $c['resumen']['fuera_de_lista_por_tipo']['RUBRO']);
chequear('la que esta bien no cuenta', 3, $c['resumen']['altas']);
chequear('y nada de esto es un error', 0, $c['resumen']['errores']);

seccion('el aviso nombra las listas y dice que no se agregan solas');

$avisoLista = '';

foreach ($c['avisos'] as $a) {
    if (strpos($a, 'listas de opciones') !== false) { $avisoLista = $a; }
}

chequear('hay aviso', true, $avisoLista !== '');
chequear('nombra la lista del rubro economico', true,
    strpos($avisoLista, 'Rubro económico') !== false);

// ALGUIEN VA A ESPERAR QUE SE AGREGUEN SOLAS. Si la importacion ampliara las
// listas, se llenarian con los typos de la planilla y dejarian de validar nada.
chequear('dice que NO se agregan solas', true,
    strpos($avisoLista, 'NO se agregan solos') !== false);

// Y avisa lo que mas importa: el rubro economico arma filas del tablero.
chequear('avisa del efecto en el tablero', true,
    strpos($avisoLista, 'fila propia en el tablero') !== false);

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

seccion('un plazo fuera de lista cae al fallback de siempre');

// La lista no lo tiene, asi que manda plazoEnDias(): recalcular no inventa nada
// y tampoco pierde lo que ya se sabia interpretar.
$f = ProveedoresCategorias::normalizarFila(filaMaestro(['plazo_pago' => '45 DIAS']), $listas);

chequear('cuarenta y cinco', 45, $f['plazo_dias']);
chequear('pero queda marcado fuera de lista', true, isset($f['fuera_lista']['PLAZO']));

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

require_once __DIR__ . '/../cashflow/Class/Parametros.php';

$codigos = array_map(function ($m) { return $m['codigo']; }, Parametros::getModulos());

chequear('PROV_LOCALES es un modulo de Parametros', true,
    in_array('PROV_LOCALES', $codigos, true));

$tabParam = file_get_contents(__DIR__ . '/../cashflow/Tabs/parametros.php');

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

/* EL BUG QUE ESTO EVITA: si a un <select> se le pide un valor que no tiene,
   queda vacio EN SILENCIO, y guardar el formulario le borraria el rubro al
   proveedor sin que nadie lo haya pedido. elegirValor() agrega el valor como
   opcion marcada en vez de perderlo. */
$jsProv = file_get_contents(__DIR__ . '/../cashflow/Js/Proveedores-Proveedores_locales.js');

chequear('el form usa elegirValor y no setValor a secas', true,
    strpos($jsProv, "elegirValor('fpRubroEcoProv'") !== false);
chequear('que agrega la opcion faltante', true,
    strpos($jsProv, "opt.dataset.fueraLista = '1';") !== false);

// Y sin listas cargadas no se reemplaza nada: los campos siguen siendo texto
// libre, que es como funcionaban antes.
chequear('sin listas, no se dibujan desplegables', true,
    strpos($jsProv, 'if (!listas) { return; }') !== false);
