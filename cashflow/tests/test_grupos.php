<?php
/**
 * Filas agrupadas: la regla de agrupamiento y lo que el validador avisa.
 *
 * CashflowEstructura::grupos() es pura y no toca la base, asi que se prueba
 * entera sin conexion. Es la regla que decide que filas forman un grupo, y lo
 * que se fija aca es que sea POSICIONAL: nada de referencias fila->fila.
 *
 * La segunda mitad del archivo prueba el principio de diseño entero: el mismo
 * escenario corrido por el motor DOS VECES, con los grupos declarados y sin
 * declarar, tiene que dar dos tableros identicos. Si alguna vez deja de darlos,
 * el agrupamiento dejo de ser presentacion.
 *
 * LO QUE ESTE ARCHIVO NO PRUEBA, y conviene saberlo: el JavaScript que arma la
 * fila agrupada. No hay corredor de JS en el proyecto. Lo que si esta cubierto
 * es la aritmetica que ese codigo tiene que reproducir -contra el motor de
 * verdad, mas abajo- y el cableado que se rompe en silencio, en
 * test_tablas_controles.php. Lo demas se verifica en pantalla.
 */

require_once __DIR__ . '/../Class/CashflowEstructura.php';

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

/* ================================================================
   EL MOTOR NO SE ENTERA DE QUE LOS GRUPOS EXISTEN

   Es el principio de diseño de toda esta etapa y el unico que, si se rompe,
   se rompe en silencio: la fila agrupada es PRESENTACION, asi que subtotales,
   Flujo Neto, Saldo Final y KPIs tienen que dar EXACTAMENTE lo mismo con los
   grupos declarados y sin declarar.

   Se prueba de la forma mas dura que se puede: el mismo escenario dos veces,
   una con GRUPO y NATURALEZA puestos y otra sin nada, y los dos tableros
   tienen que ser identicos salvo por esos tres campos. Si manana alguien hace
   que el motor mire 'grupo' -para sumar, para saltear, para lo que sea- esta
   prueba se cae, y esa es toda su razon de ser.
   ================================================================ */

require_once __DIR__ . '/../Class/Cashflow.php';

/* Dobles propios: el corredor incluye todos los archivos en el mismo proceso y
   los de test_cashflow.php ya ocupan sus nombres. */

class EstructuraDeGrupos {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosDeGrupos extends Parametros {
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() {
        return ['horizonte_dias' => 3, 'horizonte_meses' => 3];
    }
    public function getFeriadosComercio($map = null) { return []; }
}

class CashflowDeGrupos extends Cashflow {
    public $series = [];
    protected function pedirSeries($h, $filas) { return $this->series; }
}

function gSerie($h, $dias = [], $meses = []) {
    $s = $h->serieVacia();

    foreach ($dias as $k => $v)  { $s['dias'][$k] = $v; }
    foreach ($meses as $k => $v) { $s['meses'][$k] = $v; }

    $s['moneda_origen'] = 'ARS';
    $s['tipo_cambio'] = null;
    $s['fuera_horizonte'] = 0;
    $s['sin_fecha'] = 0;
    $s['warnings'] = [];
    $s['detalle'] = [];

    return $s;
}

function gConf($id, $codigo, $seccion, $tipo, $orden, $prov = null, $serie = null, $extra = []) {
    return array_merge([
        'ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
        'TIPO' => $tipo, 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => $prov,
        'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1,
        'GRUPO' => null, 'NATURALEZA' => null, 'GRUPO_NOMBRE' => null
    ], $extra);
}

/** El tablero del escenario, con o sin los grupos declarados */
function gTablero($conGrupos) {
    $marcaReal = $conGrupos
        ? ['GRUPO' => 'COB', 'NATURALEZA' => 'REAL', 'GRUPO_NOMBRE' => 'Cobranzas'] : [];
    $marcaProy = $conGrupos
        ? ['GRUPO' => 'COB', 'NATURALEZA' => 'PROYECTADO'] : [];

    $est = new EstructuraDeGrupos();
    $est->secciones = [
        ['CODIGO' => 'DISP', 'NOMBRE' => 'Disponible', 'ROL' => 'SALDO',
         'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1],
        ['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
         'ID_PADRE' => null, 'ORDEN' => 20, 'ACTIVO' => 1],
        ['CODIGO' => 'RES', 'NOMBRE' => 'Resultados', 'ROL' => 'DERIVADO',
         'ID_PADRE' => null, 'ORDEN' => 30, 'ACTIVO' => 1]
    ];
    $est->filas = [
        gConf(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
        gConf(2, 'COB_REAL', 'ING', 'INGRESO', 10, 'COBR', 'REAL', $marcaReal),
        gConf(3, 'COB_PROY', 'ING', 'INGRESO', 20, 'COBR', 'PROY', $marcaProy),
        gConf(4, 'SUB_ING', 'ING', 'SUBTOTAL', 30),
        gConf(5, 'FLUJO', 'RES', 'FLUJO_NETO', 10),
        gConf(6, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 20)
    ];

    $h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

    $motor = new CashflowDeGrupos($est, new ParametrosDeGrupos(), $h);
    $motor->series = [
        'SALDOS' => ['DISPONIBLE' => gSerie($h, ['2026-09-06' => 1000])],
        'COBR' => [
            // La parte real tiene importe el 6 y el 7; la proyectada, el 7 y en
            // octubre. El 8 no tiene ninguna de las dos: esa columna es la que
            // fija que la suma de un grupo sin datos sea cero y no otra cosa.
            'REAL' => gSerie($h, ['2026-09-06' => 100, '2026-09-07' => 200]),
            'PROY' => gSerie($h, ['2026-09-07' => 50], ['2026-10' => 500])
        ]
    ];

    return $motor->proyectar();
}

/** Saca de cada fila los tres campos de agrupamiento */
function gSinMarcas($tablero) {
    foreach ($tablero['filas'] as $i => $f) {
        unset($tablero['filas'][$i]['grupo'], $tablero['filas'][$i]['naturaleza'],
              $tablero['filas'][$i]['grupo_nombre']);
    }

    return $tablero;
}

seccion('el tablero es identico con grupos y sin grupos');

$con = gTablero(true);
$sin = gTablero(false);

chequear('todo el tablero, campo por campo', gSinMarcas($sin), gSinMarcas($con));

seccion('y los campos de agrupamiento viajan en el payload');

$porCod = [];
foreach ($con['filas'] as $f) { $porCod[$f['codigo']] = $f; }

chequear('el grupo de la parte real', 'COB', $porCod['COB_REAL']['grupo']);
chequear('su naturaleza', 'REAL', $porCod['COB_REAL']['naturaleza']);
chequear('el nombre del grupo', 'Cobranzas', $porCod['COB_REAL']['grupo_nombre']);
chequear('la naturaleza de la otra parte', 'PROYECTADO', $porCod['COB_PROY']['naturaleza']);
chequear('la proyectada no repite el nombre', null, $porCod['COB_PROY']['grupo_nombre']);
chequear('una fila sin grupo lo trae en null', null, $porCod['DISPONIBLE']['grupo']);
chequear('y una derivada tambien', null, $porCod['SUB_ING']['grupo']);

/* ================================================================
   LO QUE TIENE QUE MOSTRAR LA FILA AGRUPADA

   La suma la hace el front, pero el numero que le tiene que dar sale de estas
   filas. Se fija aca -contra el motor de verdad- para que la aritmetica que
   Js/Cashflow.js reproduce este escrita en algun lado que se ejecuta.
   ================================================================ */
seccion('la suma del grupo es la suma de sus partes, columna a columna');

$real = $porCod['COB_REAL'];
$proy = $porCod['COB_PROY'];

chequear('el 6, solo la real', 100.0, $real['dias']['2026-09-06'] + $proy['dias']['2026-09-06']);
chequear('el 7, las dos', 250.0, $real['dias']['2026-09-07'] + $proy['dias']['2026-09-07']);
chequear('el 8, ninguna', 0.0, $real['dias']['2026-09-08'] + $proy['dias']['2026-09-08']);
chequear('octubre, solo la proyectada', 500.0,
    $real['meses']['2026-10'] + $proy['meses']['2026-10']);

seccion('y el subtotal ya daba eso, que es el punto');

chequear('el 7 el subtotal coincide con la suma del grupo',
    250.0, $porCod['SUB_ING']['dias']['2026-09-07']);
chequear('y el 8 tambien', 0.0, $porCod['SUB_ING']['dias']['2026-09-08']);

seccion('el total del grupo es la suma de los totales de sus partes');

foreach (['total_tramo', 'total_meses', 'total_horizonte'] as $total) {
    chequear($total . ' de la real mas la proyectada es el del subtotal',
        round($porCod['SUB_ING'][$total], 2),
        round($real[$total] + $proy[$total], 2));
}
