<?php
/**
 * Proveedores Locales: las RETENCIONES de las facturas que se pagan por orden
 * de pago.
 *
 * Todo sin base: la configuracion -los impuestos, las generales, las de cada
 * proveedor y los tipos de credito de CPA21- llega armada a mano, igual que la
 * leeria ProveedoresRetenciones::config(). Lo que toca la base se fija leyendo
 * el metodo, como el resto de las pruebas del modulo.
 *
 * Las reglas, en una linea cada una:
 *   - "a todos": rige la general salvo que el proveedor tenga la suya (0 = no
 *     se retiene); "solo a los asignados": solo a quien la tenga;
 *   - la alicuota se resuelve a la fecha de pago, la del proveedor rige desde
 *     el dia de la carga y dos cargas el mismo dia las desempata el ID;
 *   - ningun proveedor puede quedar con sus alicuotas sumando 100% o mas.
 */

require_once __DIR__ . '/../Class/ProveedoresRetenciones.php';

$PR = 'ProveedoresRetenciones';

/** Un impuesto, como lo lee config() */
$imp = function ($id, $codigo, $alcance, $orden, $activo = 1) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => ucfirst(strtolower($codigo)),
            'ALCANCE' => $alcance, 'ORDEN' => $orden, 'ACTIVO' => $activo];
};

/** Una alicuota general */
$gen = function ($id, $concepto, $alicuota, $desde, $activo = 1) {
    return ['ID' => $id, 'CONCEPTO' => $concepto, 'ALICUOTA' => $alicuota,
            'VIGENCIA_DESDE' => $desde, 'ACTIVO' => $activo];
};

/** Una alicuota de proveedor; null es "sin dato" */
$prop = function ($id, $concepto, $alicuota, $desde) {
    return ['ID' => $id, 'CONCEPTO' => $concepto, 'ALICUOTA' => $alicuota,
            'VIGENCIA_DESDE' => $desde, 'ORIGEN' => 'MASIVA'];
};

/**
 * La configuracion de base: IIBB 3% y Ganancias 2% desde el 1/10, a todos; SUSS
 * solo a los asignados. Un proveedor con SUSS al 2%, uno que no retiene
 * Ganancias, y uno con IIBB propia.
 */
$base = function () use ($imp, $gen, $prop) {
    return [
        'disponible' => true,
        'error' => null,
        'impuestos' => [
            'IIBB' => $imp(1, 'IIBB', 'TODOS', 1),
            'GANANCIAS' => $imp(2, 'GANANCIAS', 'TODOS', 2),
            'SUSS' => $imp(3, 'SUSS', 'ASIGNADOS', 3)
        ],
        'generales' => [
            $gen(10, 'IIBB', 0.03, '2026-10-01'),
            $gen(11, 'GANANCIAS', 0.02, '2026-10-01')
        ],
        'por_proveedor' => [
            'CONSUS' => [$prop(100, 'SUSS', 0.02, '2026-10-05')],
            'SINGAN' => [$prop(101, 'GANANCIAS', 0.0, '2026-10-05')],
            'IIBBPR' => [$prop(102, 'IIBB', 0.015, '2026-10-05')]
        ],
        'credito' => ['NCP' => true, 'AJC' => true]
    ];
};

$hoy = '2026-10-14';

/**
 * Que una llamada lance y que el mensaje DIGA algo. chequearLanza() compara el
 * mensaje entero, y aca lo que importa es que nombre lo que hay que corregir.
 */
function lanzaDiciendo($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, $fragmento,
            mb_strpos($e->getMessage(), $fragmento) !== false ? $fragmento : $e->getMessage());
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

/* ================================================================
   LA ALICUOTA EFECTIVA
   ================================================================ */
seccion('la alicuota efectiva: de donde sale cada una');

$c = $base();

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'CUALQU', $hoy);
chequear('sin dato, "a todos": la general', [true, 0.03, 'GENERAL'],
    [$e['aplica'], $e['alicuota'], $e['fuente']]);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', $hoy);
chequear('con valor propio: ese, y dice que es del proveedor', [true, 0.015, 'PROVEEDOR'],
    [$e['aplica'], $e['alicuota'], $e['fuente']]);

$e = $PR::efectiva($c, $c['impuestos']['GANANCIAS'], 'SINGAN', $hoy);
chequear('con 0: aplica, en cero, y dice "no se retiene"', [true, 0.0, 'NO_RETIENE'],
    [$e['aplica'], $e['alicuota'], $e['fuente']]);

$e = $PR::efectiva($c, $c['impuestos']['SUSS'], 'CUALQU', $hoy);
chequear('sin dato, "solo a los asignados": no se aplica', [false, false],
    [$e['aplica'], $e['sin_general']]);

$e = $PR::efectiva($c, $c['impuestos']['SUSS'], 'CONSUS', $hoy);
chequear('asignado: se aplica con la suya', [true, 0.02, 'PROVEEDOR'],
    [$e['aplica'], $e['alicuota'], $e['fuente']]);

seccion('la alicuota efectiva: sin general vigente no se retiene, y se marca');

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'CUALQU', '2026-09-30');
chequear('antes de la primera vigencia: no aplica, y sin_general', [false, true],
    [$e['aplica'], $e['sin_general']]);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', '2026-10-06');
chequear('pero un proveedor con alicuota propia no la necesita', [true, 0.015],
    [$e['aplica'], $e['alicuota']]);

/* ================================================================
   LA VIGENCIA DE LA ALICUOTA DEL PROVEEDOR
   ================================================================ */
seccion('la del proveedor rige desde el dia de la carga');

$c = $base();
$c['por_proveedor']['IIBBPR'][] = $prop(200, 'IIBB', 0.04, '2026-10-20');

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', '2026-10-19');
chequear('una fecha de pago anterior usa el valor anterior', 0.015, $e['alicuota']);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', '2026-10-20');
chequear('desde el dia de la carga, el nuevo', 0.04, $e['alicuota']);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', '2026-10-04');
chequear('antes de cualquier carga del proveedor: la general', [0.03, 'GENERAL'],
    [$e['alicuota'], $e['fuente']]);

seccion('dos cargas el mismo dia: rige la ultima');

$c = $base();
$c['por_proveedor']['DOSVEC'] = [
    $prop(300, 'IIBB', 0.05, '2026-10-14'),
    $prop(301, 'IIBB', 0.01, '2026-10-14')
];

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'DOSVEC', $hoy);
chequear('la de ID mayor', 0.01, $e['alicuota']);

seccion('volver a la general es un registro con su fecha, no un borrado');

$c = $base();
$c['por_proveedor']['IIBBPR'][] = $prop(400, 'IIBB', null, '2026-10-14');

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', $hoy);
chequear('desde ese dia usa la general', [0.03, 'GENERAL'], [$e['alicuota'], $e['fuente']]);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', '2026-10-10');
chequear('y antes sigue rigiendo la que tenia', 0.015, $e['alicuota']);

$c['por_proveedor']['CONSUS'][] = $prop(401, 'SUSS', null, '2026-10-14');
$e = $PR::efectiva($c, $c['impuestos']['SUSS'], 'CONSUS', $hoy);
chequear('en "solo a los asignados", quitar la asignacion deja de aplicarlo', false, $e['aplica']);

/* ================================================================
   LOS IMPUESTOS
   ================================================================ */
seccion('dar de baja un impuesto deja de aplicarlo y conserva el historial');

$c = $base();
$c['impuestos']['GANANCIAS']['ACTIVO'] = 0;

$r = $PR::calcular(100000, 'CUALQU', 'FAC', true, $hoy, $c);
chequear('ya no se retiene', ['IIBB'], array_keys($r['impuestos']));
chequear('sus vigencias siguen en la configuracion', 2, count($c['generales']));

$c['impuestos']['GANANCIAS']['ACTIVO'] = 1;
$r = $PR::calcular(100000, 'CUALQU', 'FAC', true, $hoy, $c);
chequear('reactivarlo lo vuelve a poner en juego', ['IIBB', 'GANANCIAS'], array_keys($r['impuestos']));

seccion('cambiar el alcance: las alicuotas propias quedan');

$c = $base();
$c['impuestos']['IIBB']['ALCANCE'] = 'ASIGNADOS';

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'CUALQU', $hoy);
chequear('a "solo a los asignados": deja de aplicar la general', false, $e['aplica']);

$e = $PR::efectiva($c, $c['impuestos']['IIBB'], 'IIBBPR', $hoy);
chequear('y el proveedor con la suya la conserva', 0.015, $e['alicuota']);

$c = $base();
$c['impuestos']['SUSS']['ALCANCE'] = 'TODOS';
$e = $PR::efectiva($c, $c['impuestos']['SUSS'], 'CUALQU', $hoy);
chequear('a "a todos" sin general: no se retiene, y se marca', [false, true],
    [$e['aplica'], $e['sin_general']]);

seccion('validarImpuesto: codigo, nombre, alcance, orden');

$v = $PR::validarImpuesto(['codigo' => ' iibb_caba ', 'nombre' => ' IIBB CABA ', 'alcance' => 'todos',
                           'orden' => '4']);
chequear('normaliza', ['IIBB_CABA', 'IIBB CABA', 'TODOS', 4],
    [$v['codigo'], $v['nombre'], $v['alcance'], $v['orden']]);

lanzaDiciendo('un codigo con espacios se rechaza', function () use ($PR) {
    $PR::validarImpuesto(['codigo' => 'IIBB CABA', 'nombre' => 'x', 'alcance' => 'TODOS']);
}, 'mayúsculas');

lanzaDiciendo('sin nombre se rechaza', function () use ($PR) {
    $PR::validarImpuesto(['codigo' => 'X', 'nombre' => ' ', 'alcance' => 'TODOS']);
}, 'nombre');

lanzaDiciendo('un alcance que no existe se rechaza', function () use ($PR) {
    $PR::validarImpuesto(['codigo' => 'X', 'nombre' => 'x', 'alcance' => 'ALGUNOS']);
}, 'alcance');

$v = $PR::validarImpuesto(['nombre' => 'Otro', 'alcance' => 'ASIGNADOS'], false);
chequear('al editar no se pide el codigo: no se edita', false, isset($v['codigo']));

chequear('los alcances de la clase son los del CHECK del script', true,
    strpos(file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_retenciones.sql'),
        "CHECK (ALCANCE IN ('" . implode("', '", array_keys($PR::ALCANCES)) . "'))") !== false);

/* ================================================================
   LA ALICUOTA GENERAL
   ================================================================ */
seccion('la general: rango');

$c = $base();

lanzaDiciendo('negativa', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IIBB', -0.01, '2026-11-01');
}, 'negativa');

lanzaDiciendo('100% o mas', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IIBB', 1, '2026-11-01');
}, 'menor a 100%');

lanzaDiciendo('no es un numero', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IIBB', 'tres', '2026-11-01');
}, 'número');

lanzaDiciendo('sin fecha de vigencia', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IIBB', 0.03, '');
}, 'vigencia');

$v = $PR::validarGeneral($c, 'IIBB', 0, '2026-11-01');
chequear('0 se acepta', 0.0, $v['alicuota']);

seccion('la general: sólo en impuestos "a todos" y activos');

lanzaDiciendo('SUSS no tiene general', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'SUSS', 0.02, '2026-11-01');
}, 'solo a los proveedores asignados');

$c2 = $base();
$c2['impuestos']['IIBB']['ACTIVO'] = 0;
lanzaDiciendo('un impuesto dado de baja no recibe alicuotas', function () use ($PR, $c2) {
    $PR::validarGeneral($c2, 'IIBB', 0.03, '2026-11-01');
}, 'dado de baja');

lanzaDiciendo('un impuesto que no existe', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IVA', 0.03, '2026-11-01');
}, 'No existe');

seccion('la general: la suma de las generales vigentes < 100%');

$c = $base();
$c['generales'][] = $gen(12, 'GANANCIAS', 0.5, '2026-11-01');

lanzaDiciendo('IIBB 50% + Ganancias 50% desde el 1/11', function () use ($PR, $c) {
    $PR::validarGeneral($c, 'IIBB', 0.5, '2026-11-01');
}, 'sumarían 100%');

$v = $PR::validarGeneral($c, 'IIBB', 0.47, '2026-11-01');
chequear('47% + 50% pasa -el de SUSS al 2% queda en 99%-, e informa la tasa total', 0.97, round($v['tasa_total'], 6));

lanzaDiciendo('una general futura de otro impuesto tambien cuenta', function () use ($PR, $base, $gen) {
    $c = $base();
    $c['generales'][] = $gen(13, 'GANANCIAS', 0.6, '2026-12-01');
    $PR::validarGeneral($c, 'IIBB', 0.45, '2026-11-01');
}, 'desde el 01/12/2026');

seccion('la general: ningún proveedor puede quedar en 100% o más');

$c = $base();
$c['por_proveedor']['ALTOSU'] = [$prop(500, 'SUSS', 0.6, '2026-10-05')];

lanzaDiciendo('con SUSS propio al 60%, IIBB al 40% lo deja en 102% y lo nombra',
    function () use ($PR, $c) {
        $PR::validarGeneral($c, 'IIBB', 0.40, '2026-11-01');
    }, 'ALTOSU');

$v = $PR::validarGeneral($c, 'IIBB', 0.37, '2026-11-01');
chequear('con 37% queda en 99% y pasa', 0.37, $v['alicuota']);

seccion('las generales: cual rige hoy lo decide la misma regla del calculo');

$gs = [$gen(1, 'IIBB', 0.03, '2026-10-01'), $gen(2, 'IIBB', 0.035, '2026-11-01'),
       $gen(3, 'GANANCIAS', 0.02, '2026-10-01', 0), $gen(4, 'GANANCIAS', 0.01, '2026-09-01')];
$m = $PR::marcarVigentesHoy($gs, $hoy);
chequear('la de hoy si, la futura no, la de baja no, la anterior a la de baja si',
    [true, false, false, true], array_column($m, 'VIGENTE_HOY'));

seccion('la pantalla: alicuota de hoy y cuantos tienen propia');

$c = $base();
$c['por_proveedor']['VOLVIO'] = [$prop(600, 'IIBB', 0.02, '2026-10-01'),
                                 $prop(601, 'IIBB', null, '2026-10-10')];
$res = [];

foreach ($PR::resumenImpuestos($c, $hoy) as $i) {
    $res[$i['CODIGO']] = [$i['vigente'] === null ? null : $i['vigente']['alicuota'], $i['con_propia']];
}

chequear('IIBB: 3% hoy, 1 proveedor con propia (el que volvio a la general no cuenta)',
    [0.03, 1], $res['IIBB']);
chequear('Ganancias: 2%, y el que no retiene si cuenta (0 es una alicuota propia)', [0.02, 1],
    $res['GANANCIAS']);
chequear('SUSS: sin general, 1 asignado', [null, 1], $res['SUSS']);

/* ================================================================
   EL SCRIPT Y LAS ESCRITURAS
   ================================================================ */
seccion('el script: reejecutable, sin alicuotas, y siembra los tres sin pisar');

$sql = file_get_contents(__DIR__ . '/../sql/cashflow_prov_locales_retenciones.sql');

foreach ([$PR::TABLA_IMPUESTO, $PR::TABLA_ALICUOTA, $PR::TABLA_PROVEEDOR] as $t) {
    chequear('crea ' . $t . ' solo si falta', true,
        strpos($sql, "IF OBJECT_ID('dbo." . $t . "', 'U') IS NULL") !== false);
}

chequear('la semilla es un MERGE sobre el codigo', true,
    strpos($sql, 'ON t.CODIGO = s.CODIGO') !== false);
chequear('que solo inserta: no pisa lo editado', [true, false],
    [strpos($sql, 'WHEN NOT MATCHED BY TARGET') !== false, strpos($sql, 'WHEN MATCHED') !== false]);
chequear('IIBB y GANANCIAS a todos, SUSS a los asignados', true,
    strpos($sql, "('IIBB',      'Ingresos Brutos', 'TODOS'") !== false
    && strpos($sql, "('GANANCIAS', 'Ganancias',       'TODOS'") !== false
    && strpos($sql, "('SUSS',      'SUSS',            'ASIGNADOS'") !== false);
chequear('no siembra ninguna alicuota', false,
    (bool) preg_match('/INSERT\s+INTO\s+dbo\.RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA/i', $sql));
chequear('la del proveedor admite NULL (sin dato) y no es lo mismo que 0', true,
    strpos($sql, 'ALICUOTA       DECIMAL(9,6)  NULL') !== false);
chequear('el codigo del proveedor con la collation de CPA01', true,
    strpos($sql, 'COD_PROVEE     VARCHAR(6)    COLLATE Latin1_General_BIN') !== false);

seccion('las escrituras: auditoria, vigencia del servidor y una transaccion');

$fuente = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/ProveedoresRetenciones.php'));

foreach (['addImpuesto', 'saveImpuesto', 'activarImpuesto', 'addGeneral', 'bajaGeneral', 'asignar'] as $met) {
    preg_match('/public function ' . $met . '\(.*?\n    }\n/s', $fuente, $m);
    $cuerpo = isset($m[0]) ? $m[0] : '';

    chequear($met . ' existe', true, $cuerpo !== '');
    chequear($met . ' valida el usuario', true,
        strpos($cuerpo, 'AuthCashflow::usuarioDeEscritura($usuario)') !== false);
}

preg_match('/public function asignar\(.*?\n    }\n/s', $fuente, $m);
$asignar = isset($m[0]) ? $m[0] : '';
chequear('asignar() abre una transaccion y la deshace si falla', [true, true],
    [strpos($asignar, 'sqlsrv_begin_transaction') !== false, strpos($asignar, 'sqlsrv_rollback') !== false]);
chequear('asignar() graba la fecha del servidor, no una que venga del pedido', true,
    strpos($asignar, '$hoy = $this->hoy();') !== false);
chequear('asignar() vuelve a calcular el plan contra la base de ahora', true,
    strpos($asignar, '$this->olvidar();') !== false
    && strpos($asignar, 'self::planAsignacion($this->config()') !== false);

chequear('ninguna escritura borra filas', false, (bool) preg_match('/\bDELETE\b/', $fuente));
chequear('la tabla de alicuotas de proveedores no se actualiza: es su propio historial', false,
    strpos($fuente, 'UPDATE dbo." . self::TABLA_PROVEEDOR') !== false);
