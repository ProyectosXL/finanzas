<?php
/**
 * CashflowEstructura: el validador de la configuracion del tablero.
 *
 * Es puro y no toca la base, asi que se prueba entero sin conexion.
 * Los proveedores se inyectan para no depender del registro real.
 */

require_once __DIR__ . '/../Class/CashflowEstructura.php';

/** Proveedores de mentira, para no atarse al registro real */
$PROVS = [
    'VENTAS' => [
        'codigo' => 'VENTAS', 'nombre' => 'Ventas', 'disponible' => true,
        'series' => ['COBRANZA' => 'Cobranza', 'VENTA' => 'Venta',
                     'COBRANZA_LOCALES' => 'Cobranza Locales',
                     'COBRANZA_FRANQUICIAS' => 'Cobranza Franquicias'],
        'componentes' => ['COBRANZA' => ['COBRANZA_LOCALES', 'COBRANZA_FRANQUICIAS']]
    ],
    'SALDOS' => [
        'codigo' => 'SALDOS', 'nombre' => 'Saldos', 'disponible' => false,
        'series' => ['DISPONIBLE' => 'Disponible']
    ]
];

function sec($codigo, $rol, $orden, $activo = 1, $padre = null) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $codigo, 'ROL' => $rol,
            'ID_PADRE' => $padre, 'ORDEN' => $orden, 'ACTIVO' => $activo];
}

function fil($id, $codigo, $seccion, $tipo, $prov = null, $serie = null,
             $activo = 1, $computa = 1) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
            'TIPO' => $tipo, 'COMPUTA' => $computa, 'ORIGEN_PROVIDER' => $prov,
            'ORIGEN_SERIE' => $serie, 'ORDEN' => 10, 'ACTIVO' => $activo];
}

/** Devuelve true si algun error contiene el texto */
function hayError($resultado, $texto) {
    foreach ($resultado['errores'] as $e) {
        if (mb_stripos($e, $texto) !== false) {
            return true;
        }
    }

    return false;
}

function hayAdvertencia($resultado, $texto) {
    foreach ($resultado['advertencias'] as $a) {
        if (mb_stripos($a, $texto) !== false) {
            return true;
        }
    }

    return false;
}

/* ================================================================
   Una estructura valida no reporta errores
   ================================================================ */
seccion('estructura valida');

$secciones = [sec('DISP', 'SALDO', 10), sec('ING', 'MOVIMIENTO', 20), sec('RES', 'DERIVADO', 30)];
$filas = [
    fil(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 'SALDOS', 'DISPONIBLE'),
    fil(2, 'COBROS', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA'),
    fil(3, 'SUB_ING', 'ING', 'SUBTOTAL'),
    fil(4, 'FLUJO', 'RES', 'FLUJO_NETO'),
];

$r = CashflowEstructura::validar($secciones, $filas, $PROVS);

chequear('es valida', true, $r['valido']);
chequear('sin errores', 0, count($r['errores']));
chequear('siembra una entrada por fila', 4, count($r['por_fila']));
chequear('siembra una entrada por seccion', 3, count($r['por_seccion']));
chequear('avisa que el modulo Saldos no esta construido',
    true, hayAdvertencia($r, 'todavía no está construido'));

/* ================================================================
   Errores que bloquean
   ================================================================ */
seccion('codigos repetidos e invalidos');

$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'COBROS', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA'),
     fil(2, 'COBROS', 'ING', 'INGRESO', 'VENTAS', 'VENTA')],
    $PROVS
);
chequear('codigo de fila repetido es error', true, hayError($r, 'está repetido'));
chequear('y la estructura queda invalida', false, $r['valido']);

$r = CashflowEstructura::validar(
    [sec('DISP', 'SALDO', 10), sec('DISP', 'MOVIMIENTO', 20)], [], $PROVS
);
chequear('codigo de seccion repetido es error', true, hayError($r, 'está repetido'));

$r = CashflowEstructura::validar(
    $secciones, [fil(1, '2COBROS', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA')], $PROVS
);
chequear('un codigo que empieza con numero es invalido', true, hayError($r, 'no es válido'));

seccion('seccion inexistente o inhabilitada');

$r = CashflowEstructura::validar(
    $secciones, [fil(1, 'COBROS', 'NO_EXISTE', 'INGRESO', 'VENTAS', 'COBRANZA')], $PROVS
);
chequear('fila activa en una seccion que no existe', true, hayError($r, 'que no existe'));

$r = CashflowEstructura::validar(
    [sec('ING', 'MOVIMIENTO', 20, 0)],
    [fil(1, 'COBROS', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA')],
    $PROVS
);
chequear('fila activa cuya seccion quedaria inhabilitada',
    true, hayError($r, 'desaparecería del tablero'));

seccion('origen de datos');

$r = CashflowEstructura::validar($secciones, [fil(1, 'COBROS', 'ING', 'INGRESO')], $PROVS);
chequear('fila activa sin origen', true, hayError($r, 'no tiene origen'));

$r = CashflowEstructura::validar(
    $secciones, [fil(1, 'COBROS', 'ING', 'INGRESO', 'INVENTADO', 'X')], $PROVS
);
chequear('proveedor no registrado', true, hayError($r, 'no está registrado'));

$r = CashflowEstructura::validar(
    $secciones, [fil(1, 'COBROS', 'ING', 'INGRESO', 'VENTAS', 'NO_EXISTE')], $PROVS
);
chequear('serie que el proveedor no ofrece', true, hayError($r, 'no ofrece'));

$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'A', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA'),
     fil(2, 'B', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA')],
    $PROVS
);
chequear('dos filas activas con el mismo origen: doble conteo',
    true, hayError($r, 'se contaría dos veces'));

// Si una de las dos esta inhabilitada, no hay doble conteo
$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'A', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA'),
     fil(2, 'B', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA', 0)],
    $PROVS
);
chequear('una inhabilitada no cuenta para el doble conteo',
    false, hayError($r, 'se contaría dos veces'));

// Una fila inhabilitada sin origen tampoco molesta
$r = CashflowEstructura::validar($secciones, [fil(1, 'A', 'ING', 'INGRESO', null, null, 0)], $PROVS);
chequear('fila inhabilitada sin origen no es error', false, hayError($r, 'no tiene origen'));

seccion('total y apertura por canal a la vez');

// La regla de origen repetido no ve esto, porque son series DISTINTAS: hace
// falta la relacion total-componentes que declara el registro.
$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'TOTAL', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA'),
     fil(2, 'LOCALES', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA_LOCALES')],
    $PROVS
);
chequear('el total y una de sus partes activas a la vez', true, hayError($r, 'sus partes'));
chequear('y eso invalida la estructura', false, $r['valido']);

// Los canales entre si NO se pisan
$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'LOC', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA_LOCALES'),
     fil(2, 'FR', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA_FRANQUICIAS')],
    $PROVS
);
chequear('dos canales distintos no se pisan', false, hayError($r, 'sus partes'));
chequear('la estructura por canal es valida', true, $r['valido']);

// Si el total esta inhabilitado, no hay conflicto
$r = CashflowEstructura::validar(
    $secciones,
    [fil(1, 'TOTAL', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA', 0),
     fil(2, 'LOCALES', 'ING', 'INGRESO', 'VENTAS', 'COBRANZA_LOCALES')],
    $PROVS
);
chequear('un total inhabilitado no entra en conflicto', false, hayError($r, 'sus partes'));

seccion('reglas de conjunto');

$r = CashflowEstructura::validar(
    [sec('DISP', 'SALDO', 10)],
    [fil(1, 'A', 'DISP', 'SALDO_INICIAL', 'SALDOS', 'DISPONIBLE'),
     fil(2, 'B', 'DISP', 'SALDO_INICIAL', 'SALDOS', 'DISPONIBLE')],
    $PROVS
);
chequear('mas de un saldo inicial activo', true, hayError($r, 'más de una fila de saldo inicial'));
// Que el mensaje aparezca no alcanza: si no baja la bandera 'valido', el error
// se muestra pero guardar() lo deja pasar igual. Paso exactamente eso.
chequear('y ademas invalida la estructura', false, $r['valido']);

$r = CashflowEstructura::validar(
    [sec('ING', 'MOVIMIENTO', 10)],
    [fil(1, 'SUB', 'ING', 'SUBTOTAL')],
    $PROVS
);
chequear('subtotal sin nada que sumar', true, hayError($r, 'mostraría cero'));

$r = CashflowEstructura::validar(
    [sec('ING', 'MOVIMIENTO', 10)], [], $PROVS
);
chequear('seccion activa sin filas es advertencia, no error',
    true, hayAdvertencia($r, 'se dibuja vacía'));
chequear('y no invalida la estructura', true, $r['valido']);

seccion('tipos y roles desconocidos');

$r = CashflowEstructura::validar($secciones, [fil(1, 'A', 'ING', 'INVENTADO')], $PROVS);
chequear('tipo de fila desconocido', true, hayError($r, 'tipo desconocido'));

$r = CashflowEstructura::validar([sec('X', 'INVENTADO', 10)], [], $PROVS);
chequear('rol de seccion desconocido', true, hayError($r, 'rol desconocido'));

seccion('jerarquia de secciones');

$r = CashflowEstructura::validar(
    [sec('A', 'MOVIMIENTO', 10, 1, 'B'), sec('B', 'MOVIMIENTO', 20, 1, 'A')], [], $PROVS
);
chequear('un ciclo entre secciones padre', true, hayError($r, 'ciclo'));

$r = CashflowEstructura::validar([sec('A', 'MOVIMIENTO', 10, 1, 'NO_EXISTE')], [], $PROVS);
chequear('padre inexistente', true, hayError($r, 'que no existe'));

// descendientes(): un subtotal en la madre abarca a las hijas
$arbol = [
    sec('EGRESOS', 'MOVIMIENTO', 10),
    sec('MERCA', 'MOVIMIENTO', 20, 1, 'EGRESOS'),
    sec('DIRECTOS', 'MOVIMIENTO', 30, 1, 'EGRESOS'),
    sec('OTRA', 'MOVIMIENTO', 40),
];
$desc = CashflowEstructura::descendientes($arbol, 'EGRESOS');
sort($desc);
chequear('descendientes incluye la raiz y las hijas',
    ['DIRECTOS', 'EGRESOS', 'MERCA'], $desc);
chequear('una seccion sin hijas se devuelve sola',
    ['OTRA'], CashflowEstructura::descendientes($arbol, 'OTRA'));

// Un subtotal en la madre SI encuentra que sumar en las hijas
$r = CashflowEstructura::validar(
    $arbol,
    [fil(1, 'SUB', 'EGRESOS', 'SUBTOTAL'),
     fil(2, 'PAGOS', 'MERCA', 'EGRESO', 'VENTAS', 'COBRANZA')],
    $PROVS
);
chequear('un subtotal en la seccion madre suma las hijas',
    false, hayError($r, 'mostraría cero'));

/* ================================================================
   Helpers de tipo y de codigo
   ================================================================ */
seccion('helpers');

chequear('signo de un ingreso', 1, CashflowEstructura::signo('INGRESO'));
chequear('signo de un egreso', -1, CashflowEstructura::signo('EGRESO'));
chequear('una fila calculada no tiene signo', 0, CashflowEstructura::signo('SUBTOTAL'));
chequear('el subtotal es derivado', true, CashflowEstructura::esDerivada('SUBTOTAL'));
chequear('el ingreso no es derivado', false, CashflowEstructura::esDerivada('INGRESO'));
chequear('el saldo final es fila de saldo', true, CashflowEstructura::esSaldo('SALDO_FINAL'));
chequear('el flujo neto no es fila de saldo', false, CashflowEstructura::esSaldo('FLUJO_NETO'));

chequear('slug con acentos y espacios', 'COSTO_DE_MERCADERIA',
    CashflowEstructura::slug('Costo de Mercadería'));
chequear('slug colapsa separadores', 'A_B', CashflowEstructura::slug('a---b'));
chequear('slug recorta los bordes', 'HOLA', CashflowEstructura::slug('  ¡hola!  '));
chequear('slug de la enie', 'ANO', CashflowEstructura::slug('Año'));
chequear('slug corta en 30', 30, strlen(CashflowEstructura::slug(str_repeat('a', 50))));
chequear('slug de algo sin letras queda vacio', '', CashflowEstructura::slug('!!!'));

chequear('codigo valido', true, CashflowEstructura::codigoValido('COBROS_VENTAS'));
chequear('codigo que empieza con numero', false, CashflowEstructura::codigoValido('1COBROS'));
chequear('codigo en minusculas', false, CashflowEstructura::codigoValido('cobros'));
chequear('codigo vacio', false, CashflowEstructura::codigoValido(''));
chequear('codigo con espacio', false, CashflowEstructura::codigoValido('A B'));
