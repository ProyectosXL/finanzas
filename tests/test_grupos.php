<?php
/**
 * Filas agrupadas: la regla de agrupamiento y lo que el validador avisa.
 *
 * CashflowEstructura::grupos() es pura y no toca la base, asi que se prueba
 * entera sin conexion. Es la regla que decide que filas forman un grupo, y lo
 * que se fija aca es que sea POSICIONAL: nada de referencias fila->fila.
 *
 * Lo que NO se prueba aca porque no cambia: que los subtotales, el flujo neto,
 * el saldo final y los KPIs den lo mismo con grupos y sin grupos. Eso lo fija
 * test_cashflow.php, que corre sobre el motor, y el punto es justamente que el
 * motor no sabe que los grupos existen. Si alguna de esas pruebas se cae por
 * un cambio de esta rama, el agrupamiento dejo de ser presentacion.
 */

require_once __DIR__ . '/../cashflow/Class/CashflowEstructura.php';

/* Nombres propios y no los de test_estructura.php: el corredor incluye todos
   los archivos en el MISMO proceso, asi que dos helpers con el mismo nombre se
   pisan, y con un filtro puesto ni siquiera esta el otro archivo. */

function gSec($codigo, $orden, $rol = 'MOVIMIENTO', $activo = 1) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $codigo, 'ROL' => $rol,
            'ID_PADRE' => null, 'ORDEN' => $orden, 'ACTIVO' => $activo];
}

function gFil($id, $codigo, $seccion, $orden, $extra = []) {
    return array_merge([
        'ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
        'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV',
        'ORIGEN_SERIE' => 'S' . $id, 'ORDEN' => $orden, 'ACTIVO' => 1,
        'GRUPO' => null, 'NATURALEZA' => null, 'GRUPO_NOMBRE' => null
    ], $extra);
}

function gError($r, $texto) {
    foreach ($r['errores'] as $e) {
        if (mb_stripos($e, $texto) !== false) return true;
    }

    return false;
}

function gAdv($r, $texto) {
    foreach ($r['advertencias'] as $a) {
        if (mb_stripos($a, $texto) !== false) return true;
    }

    return false;
}

/** Un proveedor de mentira con todas las series que usan estas pruebas */
$GPROVS = [
    'PROV' => [
        'codigo' => 'PROV', 'nombre' => 'Proveedor', 'disponible' => true,
        'series' => [
            'S1' => 'Serie 1', 'S2' => 'Serie 2', 'S3' => 'Serie 3', 'S4' => 'Serie 4',
            'S5' => 'Serie 5', 'S6' => 'Serie 6', 'S7' => 'Serie 7',
            'TOTAL' => 'El total', 'REAL' => 'La parte real', 'PROY' => 'La parte proyectada'
        ],
        'componentes' => ['TOTAL' => ['REAL', 'PROY']]
    ]
];

/* ================================================================
   LA REGLA: FILAS CONSECUTIVAS DE LA MISMA SECCION Y DEL MISMO TIPO
   ================================================================ */
seccion('dos filas seguidas con el mismo grupo son un grupo');

$secs = [gSec('DISP', 10)];
$filas = [
    gFil(1, 'OTRA', 'DISP', 10),
    gFil(2, 'REAL', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'GRUPO_NOMBRE' => 'Cobranzas']),
    gFil(3, 'PROY', 'DISP', 30, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('hay un grupo', 1, count($g));
chequear('con sus dos filas', 2, count($g['COB']['filas']));
chequear('en una sola corrida', 1, count($g['COB']['corridas']));
chequear('y es consecutivo', true, $g['COB']['consecutivo']);
chequear('el nombre sale de la fila que lo declara', 'Cobranzas', $g['COB']['nombre']);
chequear('una real', 1, $g['COB']['naturalezas']['REAL']);
chequear('y una proyectada', 1, $g['COB']['naturalezas']['PROYECTADO']);

seccion('sin nombre declarado, el nombre es el codigo');

$filas = [
    gFil(1, 'A', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'B', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('el codigo hace de nombre', 'COB', $g['COB']['nombre']);

seccion('gana el primer nombre declarado, en orden de dibujo');

$filas = [
    gFil(1, 'A', 'DISP', 10, ['GRUPO' => 'COB', 'GRUPO_NOMBRE' => 'Primero']),
    gFil(2, 'B', 'DISP', 20, ['GRUPO' => 'COB', 'GRUPO_NOMBRE' => 'Segundo'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('el de la primera fila', 'Primero', $g['COB']['nombre']);
chequear('y los dos quedan a la vista', 2, count($g['COB']['nombres']));

/* El orden del arreglo no es el orden de dibujo: lo decide (seccion, orden).
   Sin esto, "consecutivas" significaria una cosa distinta segun como venga
   armado el arreglo, y el editor manda las filas en el orden de la pantalla
   mientras la base las devuelve por ORDEN. */
seccion('el orden lo deciden SECCION y ORDEN, no como venga el arreglo');

$filas = [
    gFil(3, 'PROY', 'DISP', 30, ['GRUPO' => 'COB', 'GRUPO_NOMBRE' => 'Cobranzas']),
    gFil(1, 'OTRA', 'DISP', 10),
    gFil(2, 'REAL', 'DISP', 20, ['GRUPO' => 'COB', 'GRUPO_NOMBRE' => 'Real primero'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('igual quedan consecutivas', true, $g['COB']['consecutivo']);
chequear('y el primero es el de ORDEN menor', 'Real primero', $g['COB']['nombre']);

seccion('una fila en el medio parte el grupo');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'INTRUSA', 'DISP', 20),
    gFil(3, 'PROY', 'DISP', 30, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('dos corridas', 2, count($g['COB']['corridas']));
chequear('no es consecutivo', false, $g['COB']['consecutivo']);
chequear('pero las filas siguen contadas', 2, count($g['COB']['filas']));

/* ESTE ES EL CASO REAL DE HOY: COBRANZAS_FR quedo inhabilitada entre
   COB_ELECTRONICOS y las dos filas que hay que agrupar. Contarla las dejaria
   "no consecutivas" cuando en pantalla estan pegadas. */
seccion('una fila INHABILITADA en el medio no parte nada');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'VIEJA', 'DISP', 20, ['ACTIVO' => 0]),
    gFil(3, 'PROY', 'DISP', 30, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('una sola corrida', 1, count($g['COB']['corridas']));
chequear('y es consecutivo', true, $g['COB']['consecutivo']);

seccion('una fila inhabilitada CON grupo no cuenta como parte del grupo');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'PROY', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO']),
    gFil(3, 'APAGADA', 'DISP', 30, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL', 'ACTIVO' => 0])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('dos filas y no tres', 2, count($g['COB']['filas']));
chequear('sigue siendo consecutivo', true, $g['COB']['consecutivo']);

seccion('dos secciones distintas no son un grupo');

$secs2 = [gSec('DISP', 10), gSec('VTAS', 20)];
$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'PROY', 'VTAS', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'])
];

$g = CashflowEstructura::grupos($secs2, $filas);

chequear('no es consecutivo', false, $g['COB']['consecutivo']);
chequear('y se ve que son dos secciones', 2, count($g['COB']['secciones']));

seccion('no se agrupa un ingreso con un egreso');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL']),
    gFil(2, 'PROY', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                 'TIPO' => 'EGRESO'])
];

$g = CashflowEstructura::grupos($secs, $filas);

chequear('no es consecutivo', false, $g['COB']['consecutivo']);
chequear('y se ven los dos tipos', 2, count($g['COB']['tipos']));

seccion('sin ninguna fila agrupada no hay grupos');

chequear('vacio', 0, count(CashflowEstructura::grupos($secs, [gFil(1, 'A', 'DISP', 10)])));

/* ================================================================
   LO QUE EL VALIDADOR AVISA
   ================================================================ */
seccion('un grupo bien declarado no dice nada');

$secs3 = [gSec('DISP', 10), gSec('RES', 20, 'DERIVADO')];
$base = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'GRUPO_NOMBRE' => 'Cobranzas', 'ORIGEN_SERIE' => 'REAL']),
    gFil(2, 'PROY', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                 'ORIGEN_SERIE' => 'PROY']),
    gFil(3, 'FLUJO', 'RES', 10, ['TIPO' => 'FLUJO_NETO', 'COMPUTA' => 0,
                                 'ORIGEN_PROVIDER' => null, 'ORIGEN_SERIE' => null])
];

$r = CashflowEstructura::validar($secs3, $base, $GPROVS);

chequear('valida', true, $r['valido']);
chequear('sin advertencias de grupo', false, gAdv($r, 'grupo'));

seccion('las filas no consecutivas se avisan, y no bloquean el guardado');

$filas = $base;
$filas[] = gFil(4, 'INTRUSA', 'DISP', 15, ['ORIGEN_SERIE' => 'S4']);

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('sigue siendo valida', true, $r['valido']);
chequear('avisa que no quedan una al lado de la otra',
    true, gAdv($r, 'no quedan una al lado de la otra'));
chequear('y dice que las dibuja sueltas', true, gAdv($r, 'dibuja sueltas'));
chequear('el aviso baja a las dos filas del grupo',
    2, count($r['por_fila'][1]['advertencias']) + count($r['por_fila'][2]['advertencias']));

seccion('secciones distintas: el aviso dice por que');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'ORIGEN_SERIE' => 'REAL']),
    gFil(2, 'PROY', 'RES', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                'ORIGEN_SERIE' => 'PROY'])
];

$r = CashflowEstructura::validar([gSec('DISP', 10), gSec('RES', 20)], $filas, $GPROVS);

chequear('nombra las secciones', true, gAdv($r, 'más de una sección'));

seccion('tipos distintos: el aviso dice por que');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'ORIGEN_SERIE' => 'REAL']),
    gFil(2, 'PROY', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                 'TIPO' => 'EGRESO', 'ORIGEN_SERIE' => 'PROY'])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('no se agrupa un ingreso con un egreso',
    true, gAdv($r, 'no se agrupa un ingreso con un egreso'));

seccion('un grupo sin parte proyectada es valido y se avisa');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'ORIGEN_SERIE' => 'REAL']),
    gFil(2, 'REAL2', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                  'ORIGEN_SERIE' => 'PROY'])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('valida', true, $r['valido']);
chequear('lo avisa', true, gAdv($r, 'no tiene ninguna fila con la parte proyectada'));
chequear('y dice que puede ser transitorio', true, gAdv($r, 'transitorio'));

seccion('y uno sin parte real, tambien');

$filas[0]['NATURALEZA'] = 'PROYECTADO';
$filas[1]['NATURALEZA'] = 'PROYECTADO';

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('valida', true, $r['valido']);
chequear('lo avisa', true, gAdv($r, 'no tiene ninguna fila con la parte real'));

seccion('dos nombres distintos para el mismo grupo se avisan');

$filas = [
    gFil(1, 'REAL', 'DISP', 10, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'GRUPO_NOMBRE' => 'Uno', 'ORIGEN_SERIE' => 'REAL']),
    gFil(2, 'PROY', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                 'GRUPO_NOMBRE' => 'Dos', 'ORIGEN_SERIE' => 'PROY'])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('valida', true, $r['valido']);
chequear('dice cual se muestra', true, gAdv($r, 'Se muestra el de la primera'));

/* ================================================================
   LO QUE EL VALIDADOR RECHAZA
   ================================================================ */
seccion('una fila derivada no puede llevar grupo');

foreach (['SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL', 'STOCK_COBERTURA', 'USO_COBERTURA'] as $tipo) {
    $filas = [
        gFil(1, 'MOV', 'DISP', 10, ['ORIGEN_SERIE' => 'S1']),
        gFil(2, 'DERIVADA', 'DISP', 20, ['TIPO' => $tipo, 'GRUPO' => 'COB',
             'ORIGEN_PROVIDER' => 'PROV', 'ORIGEN_SERIE' => 'S2'])
    ];

    $r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

    chequear($tipo . ' con grupo es un error', true, gError($r, 'no se puede agrupar'));
    chequear($tipo . ' invalida la estructura', false, $r['valido']);
}

seccion('y el error marca la fila, no solo el resumen');

chequear('el mensaje baja a la fila', 1,
    count(array_filter($r['por_fila'][2]['errores'], function ($e) {
        return mb_stripos($e, 'no se puede agrupar') !== false;
    })));

seccion('un codigo de grupo invalido es un error');

$filas = [
    gFil(1, 'A', 'DISP', 10, ['GRUPO' => '2 cobranzas', 'ORIGEN_SERIE' => 'S1']),
    gFil(2, 'B', 'DISP', 20, ['GRUPO' => '2 cobranzas', 'ORIGEN_SERIE' => 'S2'])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('lo rechaza', false, $r['valido']);
chequear('y explica que tiene que ser', true, gError($r, 'no es un código válido'));

seccion('una naturaleza desconocida es un error');

$filas = [gFil(1, 'A', 'DISP', 10, ['NATURALEZA' => 'ESTIMADO', 'ORIGEN_SERIE' => 'S1'])];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('lo rechaza', false, $r['valido']);
chequear('nombra los dos valores', true, gError($r, 'Sólo vale REAL o PROYECTADO'));

seccion('la naturaleza de una fila derivada se ignora, y se avisa');

$filas = [
    gFil(1, 'MOV', 'DISP', 10, ['ORIGEN_SERIE' => 'S1']),
    gFil(2, 'SUB', 'DISP', 20, ['TIPO' => 'SUBTOTAL', 'NATURALEZA' => 'REAL',
         'ORIGEN_PROVIDER' => null, 'ORIGEN_SERIE' => null])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('no bloquea', true, $r['valido']);
chequear('avisa que se ignora', true, gAdv($r, 'su naturaleza se ignora'));

seccion('un nombre de grupo sin grupo se avisa');

$filas = [gFil(1, 'A', 'DISP', 10, ['GRUPO_NOMBRE' => 'Cobranzas', 'ORIGEN_SERIE' => 'S1'])];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('no bloquea', true, $r['valido']);
chequear('avisa que no se muestra', true, gAdv($r, 'no está en ningún grupo'));

/* ================================================================
   AGRUPAR NO HABILITA CONTAR DOS VECES
   La regla de siempre -total y partes activos a la vez- no se toca, y este es
   justo el escenario donde alguien podria pensar que agrupar la reemplaza: el
   grupo muestra un renglon con la suma, igual que la fila total, pero uno es
   presentacion y el otro es un importe mas en el cuadro.
   ================================================================ */
seccion('el total y sus partes siguen sin poder estar activos a la vez');

$filas = [
    gFil(1, 'TOTAL', 'DISP', 10, ['ORIGEN_SERIE' => 'TOTAL']),
    gFil(2, 'REAL', 'DISP', 20, ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL',
                                 'ORIGEN_SERIE' => 'REAL']),
    gFil(3, 'PROY', 'DISP', 30, ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO',
                                 'ORIGEN_SERIE' => 'PROY'])
];

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('lo rechaza', false, $r['valido']);
chequear('por doble conteo', true, gError($r, 'se contaría dos veces'));

seccion('con el total inhabilitado, la estructura es valida');

$filas[0]['ACTIVO'] = 0;

$r = CashflowEstructura::validar($secs3, $filas, $GPROVS);

chequear('valida', true, $r['valido']);
chequear('y el grupo queda consecutivo', true,
    CashflowEstructura::grupos($secs3, $filas)['COB']['consecutivo']);
