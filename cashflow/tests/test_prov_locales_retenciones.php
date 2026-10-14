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
require_once __DIR__ . '/../Class/ProveedoresCategorias.php';

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

/* ================================================================
   LA ASIGNACION POR PROVEEDOR (MAESTRO)
   ================================================================ */
seccion('masiva: a cuantos se aplica, cuantos ya lo tenian, cuantos se reemplazan');

$c = $base();
$c['por_proveedor']['YATIEN'] = [$prop(700, 'SUSS', 0.02, '2026-10-01')];
$c['por_proveedor']['OTROVA'] = [$prop(701, 'SUSS', 0.01, '2026-10-01')];
$maestroCods = ['NUEVO1' => 1, 'YATIEN' => 1, 'OTROVA' => 1, 'CONSUS' => 1];

$p = $PR::planAsignacion($c, ['NUEVO1', 'YATIEN', 'OTROVA', 'CONSUS'], 'SUSS', 'ALICUOTA', 0.02, $hoy,
    $maestroCods);
chequear('aplica a los que cambian', ['NUEVO1', 'OTROVA'], $p['aplicar']);
chequear('el que no tenia es nuevo', ['NUEVO1'], $p['nuevos']);
chequear('el que tenia otro valor se reemplaza', ['OTROVA'], $p['reemplaza']);
chequear('los que ya tenian ese valor no se tocan', ['YATIEN', 'CONSUS'], $p['iguales']);
chequear('nadie queda en 100%', [], $p['rechazados']);

seccion('masiva: todo o nada si alguno queda en 100% o mas');

$c = $base();
$c['por_proveedor']['ALTO50'] = [$prop(702, 'GANANCIAS', 0.5, '2026-10-01')];
$p = $PR::planAsignacion($c, ['NUEVO1', 'ALTO50'], 'IIBB', 'ALICUOTA', 0.5, $hoy, null);
chequear('rechaza y nombra al que se pasa', ['ALTO50'], array_keys($p['rechazados']));
chequear('y el resumen lo dice', true, strpos($PR::resumenPlan($p)['texto_rechazados'], 'ALTO50') === 0);

$fuenteRet = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/ProveedoresRetenciones.php'));
preg_match('/public function asignar\(.*?\n    }\n/s', $fuenteRet, $m);
chequear('asignar() no escribe nada si hay rechazados: lanza antes de abrir la transaccion', true,
    strpos($m[0], "if (!empty(\$plan['rechazados'])) {") < strpos($m[0], 'sqlsrv_begin_transaction'));

seccion('masiva: volver a la general tambien puede pasarse de 100%');

$c = $base();
$c['generales'][] = $gen(20, 'IIBB', 0.6, '2026-10-10');
$c['por_proveedor']['CERO60'] = [$prop(703, 'IIBB', 0.0, '2026-10-01'),
                                 $prop(704, 'GANANCIAS', 0.45, '2026-10-01')];
$p = $PR::planAsignacion($c, ['CERO60'], 'IIBB', 'GENERAL', null, $hoy, null);
chequear('el que no retenia IIBB vuelve al 60% y con Ganancias al 45% se pasa', ['CERO60'],
    array_keys($p['rechazados']));

seccion('masiva: las tres acciones');

$c = $base();
$p = $PR::planAsignacion($c, ['IIBBPR'], 'IIBB', 'GENERAL', null, $hoy, null);
chequear('volver a la general: el valor es NULL, no 0', [null, ['IIBBPR']], [$p['valor'], $p['aplicar']]);

$p = $PR::planAsignacion($c, ['CUALQU'], 'IIBB', 'GENERAL', null, $hoy, null);
chequear('quien ya usaba la general no se toca', ['CUALQU'], $p['iguales']);

$p = $PR::planAsignacion($c, ['CUALQU'], 'GANANCIAS', 'NO_RETIENE', null, $hoy, null);
chequear('no se retiene: 0', 0.0, $p['valor']);

lanzaDiciendo('no se retiene no existe en "solo a los asignados"', function () use ($PR, $c, $hoy) {
    $PR::planAsignacion($c, ['CUALQU'], 'SUSS', 'NO_RETIENE', null, $hoy, null);
}, 'quitarle la asignación');

$p = $PR::planAsignacion($c, ['CUALQU'], 'SUSS', 'ALICUOTA', 0, $hoy, null);
chequear('pero 0 explicito en SUSS se acepta: deja registrada la intencion', [0.0, ['CUALQU']],
    [$p['valor'], $p['aplicar']]);

lanzaDiciendo('una alicuota que no es un numero', function () use ($PR, $c, $hoy) {
    $PR::planAsignacion($c, ['CUALQU'], 'IIBB', 'ALICUOTA', 'dos', $hoy, null);
}, 'número');

lanzaDiciendo('sin proveedores', function () use ($PR, $c, $hoy) {
    $PR::planAsignacion($c, [' '], 'IIBB', 'ALICUOTA', 0.02, $hoy, null);
}, 'ningún proveedor');

lanzaDiciendo('sin el script no se asigna nada', function () use ($PR, $hoy) {
    $PR::planAsignacion(['disponible' => false], ['X'], 'IIBB', 'ALICUOTA', 0.02, $hoy, null);
}, 'cashflow_prov_locales_retenciones.sql');

seccion('masiva: solo proveedores vigentes del maestro');

$p = $PR::planAsignacion($base(), ['MTDODI', 'ZZZZZZ'], 'SUSS', 'ALICUOTA', 0.02, $hoy, ['MTDODI' => 1]);
chequear('el que no esta queda aparte, y asignar() lo rechaza entero', [['ZZZZZZ'], ['MTDODI']],
    [$p['fuera_maestro'], $p['aplicar']]);
chequear('asignar() rechaza si hay alguno fuera del maestro', true,
    strpos($m[0], "if (!empty(\$plan['fuera_maestro'])) {") < strpos($m[0], 'sqlsrv_begin_transaction'));

seccion('la columna del maestro: el valor de hoy y de donde sale');

$ef = $PR::efectivasHoy($base(), ['IIBBPR', 'SINGAN', 'CONSUS'], $hoy);
chequear('IIBB del proveedor', ['PROVEEDOR', 0.015],
    [$ef['IIBBPR']['IIBB']['fuente'], $ef['IIBBPR']['IIBB']['alicuota']]);
chequear('Ganancias: no se retiene', 'NO_RETIENE', $ef['SINGAN']['GANANCIAS']['fuente']);
chequear('SUSS no asignado: vacia', false, $ef['SINGAN']['SUSS']['aplica']);
chequear('sin el script no hay columnas', [], $PR::efectivasHoy(['disponible' => false], ['X'], $hoy));

$pm = $PR::paraMaestro($base(), ['IIBBPR'], $hoy);
chequear('el maestro recibe los impuestos activos en su orden', ['IIBB', 'GANANCIAS', 'SUSS'],
    array_column($pm['impuestos'], 'CODIGO'));

seccion('la importacion del maestro no toca las alicuotas de los proveedores');

$categ = file_get_contents(__DIR__ . '/../Class/ProveedoresCategorias.php');
chequear('ProveedoresCategorias no conoce la tabla de alicuotas', false,
    strpos($categ, 'ALICUOTA_PROV') !== false || strpos($categ, 'ProveedoresRetenciones') !== false);
chequear('ni la planilla del maestro tiene columnas de alicuotas', false,
    (bool) preg_match('/alicuota|retenc/i', json_encode(ProveedoresCategorias::columnasImportacion())));

seccion('el endpoint de la asignacion: permiso, preview y mensaje');

$ctrlProv = file_get_contents(__DIR__ . '/../Controller/ProveedoresController.php');
require_once __DIR__ . '/../Class/AuthCashflow.php';

foreach (['previewRetencionMasiva', 'asignarRetencion'] as $accion) {
    chequear($accion . ' existe en el controller', true, strpos($ctrlProv, "case '" . $accion . "':") !== false);
    chequear($accion . ' pide edicion de la pestaña', [['proveedores_locales', null]],
        AuthCashflow::ESCRITURAS['Proveedores'][$accion]);
}

chequear('el historial de un proveedor es una lectura', true,
    in_array('getRetencionesProveedor', AuthCashflow::LECTURAS['Proveedores'], true));
foreach (['previewRetencionMasiva', 'asignarRetencion'] as $accion) {
    $desde = strpos($ctrlProv, "case '" . $accion . "':");
    $bloque = substr($ctrlProv, $desde, strpos($ctrlProv, 'break;', $desde) - $desde);

    chequear($accion . ' valida contra el maestro vigente', true,
        strpos($bloque, '$prov->categorias()->mapa()') !== false);
}

/* ================================================================
   EL CALCULO: RETENCION Y NETO DE CADA COMPROBANTE
   ================================================================ */
require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Aviso.php';

seccion('el calculo: cada impuesto redondeado a 2 decimales, el neto por diferencia');

$r = $PR::calcular(100000.55, 'CUALQU', 'FAC', true, $hoy, $base());
chequear('IIBB 3% de 100.000,55 = 3.000,0165 -> 3.000,02', 3000.02, $r['impuestos']['IIBB']['retencion']);
chequear('Ganancias 2% = 2.000,011 -> 2.000,01', 2000.01, $r['impuestos']['GANANCIAS']['retencion']);
chequear('SUSS no aplica: no esta asignado', false, isset($r['impuestos']['SUSS']));
chequear('retenido = la suma de los redondeados', 5000.03, $r['retenido']);
chequear('neto = base - retenido, y cierra al centavo', [95000.52, 100000.55],
    [$r['neto'], round($r['neto'] + $r['retenido'], 2)]);
chequear('cada uno dice su alicuota y de donde sale', [0.03, 'GENERAL', '2026-10-01'],
    [$r['impuestos']['IIBB']['alicuota'], $r['impuestos']['IIBB']['fuente'],
     $r['impuestos']['IIBB']['vigencia_desde']]);

$r = $PR::calcular(50000, 'CONSUS', 'FAC', true, $hoy, $base());
chequear('el asignado a SUSS suma su retencion', [1500.0, 1000.0, 1000.0, 46500.0],
    [$r['impuestos']['IIBB']['retencion'], $r['impuestos']['GANANCIAS']['retencion'],
     $r['impuestos']['SUSS']['retencion'], $r['neto']]);

$r = $PR::calcular(50000, 'SINGAN', 'FAC', true, $hoy, $base());
chequear('el que no retiene Ganancias: aparece con 0 y "no se retiene"', [0.0, 'NO_RETIENE', 48500.0],
    [$r['impuestos']['GANANCIAS']['retencion'], $r['impuestos']['GANANCIAS']['fuente'], $r['neto']]);

seccion('el calculo: a que comprobantes no aplica');

$r = $PR::calcular(50000, 'CUALQU', 'FAC', false, $hoy, $base());
chequear('forma que no es del cronograma: sin retencion', ['FORMA', 0.0, 50000.0],
    [$r['motivo'], $r['retenido'], $r['neto']]);

$r = $PR::calcular(50000, 'CUALQU', 'NCP', true, $hoy, $base());
chequear('nota de credito (CPA21.CRE_DEB = C): sin retencion', ['NC', 50000.0], [$r['motivo'], $r['neto']]);

$r = $PR::calcular(50000, 'CUALQU', 'AJC', true, $hoy, $base());
chequear('el ajuste de credito tambien', 'NC', $r['motivo']);

$r = $PR::calcular(50000, 'CUALQU', 'FAC', true, $hoy, ['disponible' => false]);
chequear('sin el script: el total, como antes', ['SIN_SCRIPT', 50000.0], [$r['motivo'], $r['neto']]);

$r = $PR::calcular(50000, 'CUALQU', 'FAC', true, '2026-09-15', $base());
chequear('sin general vigente: no retiene, y dice cuales faltan', ['SIN_GENERAL', ['IIBB', 'GANANCIAS'], 50000.0],
    [$r['motivo'], $r['sin_general'], $r['neto']]);

$r = $PR::calcular(0, 'CUALQU', 'FAC', true, $hoy, $base());
chequear('un pendiente en cero no retiene', 'SIN_BASE', $r['motivo']);

seccion('el calculo: cambiar la forma de una factura cambia su neto');

$fila = ['COD_PROVEE' => 'CUALQU', 'T_COMP' => 'FAC', 'N_COMP' => 'A1', 'IMPORTE_PENDIENTE' => 10000.0,
         'Pago' => $hoy, 'CRONOGRAMA' => false];
$caja = Proveedores::conRetencion($fila, $base(), $hoy);
$fila['CRONOGRAMA'] = true;
$echeq = Proveedores::conRetencion($fila, $base(), $hoy);
chequear('CAJA: neto = pendiente', [10000.0, 0.0], [$caja['IMPORTE_A_PAGAR'], $caja['RETENIDO']]);
chequear('ECHEQ: aparecen las retenciones y baja el neto', [9500.0, 500.0, ['IIBB' => 300.0, 'GANANCIAS' => 200.0]],
    [$echeq['IMPORTE_A_PAGAR'], $echeq['RETENIDO'], $echeq['RETENIDO_POR_IMPUESTO']]);
chequear('el pendiente no cambia: es el total', 10000.0, $echeq['IMPORTE_PENDIENTE']);

seccion('el calculo: la alicuota se resuelve a la fecha de pago');

$c = $base();
$c['generales'][] = $gen(30, 'IIBB', 0.05, '2026-11-01');
$fila['Pago'] = '2026-10-28';
chequear('pagada antes del cambio: la vieja', 300.0, Proveedores::conRetencion($fila, $c, $hoy)['RETENIDO_POR_IMPUESTO']['IIBB']);
$fila['Pago'] = '2026-11-11';
chequear('pagada despues: la nueva', 500.0, Proveedores::conRetencion($fila, $c, $hoy)['RETENIDO_POR_IMPUESTO']['IIBB']);
$fila['Pago'] = null;
chequear('sin fecha: la de hoy', 300.0, Proveedores::conRetencion($fila, $c, $hoy)['RETENIDO_POR_IMPUESTO']['IIBB']);
chequear('sin configuracion: sin retenciones', 10000.0, Proveedores::conRetencion($fila, null, $hoy)['IMPORTE_A_PAGAR']);

seccion('dos medios: las partes son netas, suman el neto y la ultima se lleva el resto');

$itemMedio = function ($pendiente) use ($hoy, $base) {
    return Proveedores::conRetencion(['COD_PROVEE' => 'MTDODI', 'T_COMP' => 'FAC', 'N_COMP' => 'A0000500001731',
        'IMPORTE_PENDIENTE' => $pendiente, 'IMPORTE_VTO' => $pendiente, 'IMPUTACIONES' => 0.0,
        'FECHA_VTO' => '2026-10-01', 'FECHA_EMIS' => '2026-09-01', 'CRONOGRAMA' => true,
        'Pago' => '2026-10-27', 'APERTURA' => null, 'APERTURA_DESCUADRADA' => null], $base(), $hoy);
};

// 200.000 con IIBB 3% y Ganancias 2%: neto 190.000. La planilla trae 150.000 + 40.000,50.
$apertura = ['MTDODI|FAC|A0000500001731' => [
    ['orden' => 1, 'fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 150000.0],
    ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA', 'importe' => 40000.50]
]];

$partes = Proveedores::abrirEnMedios([$itemMedio(200000.0)], $apertura, $hoy, null, $base());
chequear('se parte en dos', 2, count($partes));
chequear('la primera paga lo suyo, la ultima el resto del neto (no los 40.000,50)', [150000.0, 40000.0],
    array_column($partes, 'IMPORTE_A_PAGAR'));
chequear('las dos suman el neto al centavo', 190000.0, array_sum(array_column($partes, 'IMPORTE_A_PAGAR')));
chequear('la retencion va entera en la primera parte', [10000.0, 0.0], array_column($partes, 'RETENIDO'));
chequear('y su desglose tambien', [['IIBB' => 6000.0, 'GANANCIAS' => 4000.0], []],
    array_column($partes, 'RETENIDO_POR_IMPUESTO'));
chequear('el pendiente de las partes sigue sumando el total', [160000.0, 40000.0, 200000.0],
    [$partes[0]['IMPORTE_PENDIENTE'], $partes[1]['IMPORTE_PENDIENTE'],
     $partes[0]['IMPORTE_PENDIENTE'] + $partes[1]['IMPORTE_PENDIENTE']]);

seccion('dos medios: la retencion se calcula a la fecha mas tardia');

$c = $base();
$c['generales'][] = $gen(31, 'IIBB', 0.04, '2026-10-25');
$partes = Proveedores::abrirEnMedios([$itemMedio(200000.0)], [
    'MTDODI|FAC|A0000500001731' => [
        ['orden' => 1, 'fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 150000.0],
        ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA', 'importe' => 38000.0]
    ]], $hoy, null, $c);
chequear('con la IIBB que rige el 27 (4%): neto 188.000 y se parte', [2, 12000.0],
    [count($partes), $partes[0]['RETENIDO']]);

seccion('pago parcial en Tango: la retencion se recalcula sobre el pendiente nuevo');

$parcial = Proveedores::abrirEnMedios([$itemMedio(120000.0)], $apertura, $hoy, null, $base());
chequear('la apertura ya no coincide con el neto: no se parte', [1, true],
    [count($parcial), !empty($parcial[0]['APERTURA_DESCUADRADA'])]);
chequear('y la fila se proyecta con la retencion sobre los 120.000', [6000.0, 114000.0],
    [$parcial[0]['RETENIDO'], $parcial[0]['IMPORTE_A_PAGAR']]);
chequear('el descuadre dice el neto contra el que se comparo', 114000.0,
    $parcial[0]['APERTURA_DESCUADRADA']['neto']);

seccion('dos medios sin retenciones: como antes');

$sinRet = Proveedores::abrirEnMedios([$itemMedio(200000.0)], [
    'MTDODI|FAC|A0000500001731' => [
        ['orden' => 1, 'fecha' => '2026-10-20', 'forma' => 'ECHEQ', 'importe' => 150000.0],
        ['orden' => 2, 'fecha' => '2026-10-27', 'forma' => 'TRANSFERENCIA', 'importe' => 50000.0]
    ]], $hoy, null, null);
chequear('las partes suman el pendiente', [150000.0, 50000.0], array_column($sinRet, 'IMPORTE_A_PAGAR'));

seccion('el aviso de lo que se proyecta sin retener por falta de general');

$filas = [];
foreach (['A1', 'A2'] as $n) {
    $filas[] = Proveedores::conRetencion(['COD_PROVEE' => 'CUALQU', 'T_COMP' => 'FAC', 'N_COMP' => $n,
        'IMPORTE_PENDIENTE' => 1000.0, 'Pago' => '2026-09-15', 'CRONOGRAMA' => true, 'ORIGEN_FECHA' => 'VENCIMIENTO',
        'SIN_FECHA_CARGADA' => false, 'EXCLUIDO' => false], $base(), $hoy);
}
$filas[] = Proveedores::conRetencion(['COD_PROVEE' => 'CUALQU', 'T_COMP' => 'FAC', 'N_COMP' => 'A3',
    'IMPORTE_PENDIENTE' => 1000.0, 'Pago' => '2026-09-15', 'CRONOGRAMA' => false, 'ORIGEN_FECHA' => 'VENCIMIENTO',
    'SIN_FECHA_CARGADA' => false, 'EXCLUIDO' => false], $base(), $hoy);

$avisos = Proveedores::avisosPendientesConNivel($filas);
$delAviso = array_values(array_filter($avisos, function ($a) { return $a['seccion'] === 'Retenciones'; }));
chequear('cuenta solo los del cronograma', 1, count($delAviso));
chequear('en atencion, con cuantos, cuanto y que impuestos', ['warning', true, true],
    [$delAviso[0]['nivel'], strpos($delAviso[0]['texto'], '2 comprobante(s) del cronograma por $ 2.000,00') === 0,
     strpos($delAviso[0]['texto'], 'IIBB, GANANCIAS') !== false]);
chequear('y dice donde se cargan', true, strpos($delAviso[0]['texto'], 'Parámetros › Prov. Locales') !== false);

chequear('sin el script, la pestaña avisa cual falta, en atencion', ['warning', true],
    [$PR::avisosConfig(['disponible' => false])[0]['nivel'],
     strpos($PR::avisosConfig(['disponible' => false])[0]['texto'], 'cashflow_prov_locales_retenciones.sql') !== false]);
chequear('si no se pudieron leer, critico', 'danger',
    $PR::avisosConfig(['disponible' => false, 'error' => 'x'])[0]['nivel']);

/* ================================================================
   EL TABLERO: LAS SERIES VAN EN NETO
   ================================================================ */
require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';
require_once __DIR__ . '/../Class/Horizonte.php';

seccion('el tablero: cada serie mide el importe a pagar');

$hTab = new Horizonte(30, 3, [], $hoy);
$filaTab = function ($n, $importe, $extra) use ($base, $hoy) {
    return Proveedores::conRetencion(array_merge([
        'COD_PROVEE' => 'CUALQU', 'T_COMP' => 'FAC', 'N_COMP' => $n, 'IMPORTE_PENDIENTE' => $importe,
        'Pago' => '2026-10-20', 'CRONOGRAMA' => true, 'EXCLUIDO' => false, 'EXCLUIDA_MANUAL' => false,
        'EXCLUIDO_PROVEEDOR' => false, 'EN_MAESTRO' => true, 'SERIE' => 'SIN_RUBRO'
    ], $extra), $base(), $hoy);
};

$itemsTab = [
    $filaTab('E1', 10000.0, []),                                            // echeq: 9.500
    $filaTab('E2', 20000.0, ['EXCLUIDA_MANUAL' => true, 'EXCLUIDO' => true]), // excluida a mano: 19.000
    $filaTab('C1', 5000.0, ['CRONOGRAMA' => false]),                        // caja: 5.000, sin retencion
    $filaTab('P1', 4000.0, ['EXCLUIDO_PROVEEDOR' => true, 'EXCLUIDO' => true]), // proveedor excluido: 3.800
    $filaTab('F1', 1000.0, ['Pago' => '2027-12-01'])                        // fuera del horizonte: 950
];

$prov = new ProveedoresProvider('PROV_LOCALES');
$repartir = new ReflectionMethod('ProveedoresProvider', 'repartir');
$repartir->setAccessible(true);
$series = $repartir->invoke($prov, $hTab, $itemsTab);

$totalSerie = function ($s) {
    return round(array_sum($s['dias']) + array_sum($s['meses']) + $s['fuera_horizonte'] + $s['sin_fecha'], 2);
};

chequear('PAGOS trae el neto del echeq; lo de fuera del horizonte se informa solo en PAGOS_TODO', 9500.0, $totalSerie($series['PAGOS']));
chequear('el echeq cae en su fecha por el neto', 9500.0, $series['PAGOS']['dias']['2026-10-20']);
chequear('lo de caja va por el total: no lleva retencion', 5000.0, $totalSerie($series['PAGOS_FUERA_CRONOGRAMA']));
chequear('la excluida a mano sale por su serie, en neto', 19000.0, $totalSerie($series['PAGOS_EXCLUIDOS_FACTURA']));
chequear('el proveedor excluido, en neto', 3800.0, $totalSerie($series['PAGOS_EXCLUIDOS_PROVEEDOR']));
chequear('PAGOS_TODO es el universo en neto', 38250.0, $totalSerie($series['PAGOS_TODO']));

seccion('el tablero: las dos particiones siguen cerrando contra PAGOS_TODO');

/* EN EL EJE: lo que cae fuera del horizonte se informa una sola vez, en
   PAGOS_TODO -ver repartir()-, asi que las particiones se comparan columna a
   columna, que es lo que el tablero suma. */
$enEje = function ($s) { return round(array_sum($s['dias']) + array_sum($s['meses']), 2); };

chequear('por como se paga', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS']) + $enEje($series['PAGOS_FUERA_CRONOGRAMA'])
        + $enEje($series['PAGOS_EXCLUIDOS_FACTURA']) + $enEje($series['PAGOS_EXCLUIDOS_PROVEEDOR']), 2));
chequear('por que rubro es', $enEje($series['PAGOS_TODO']),
    round($enEje($series['PAGOS_OPERATIVOS']) + $enEje($series['PAGOS_EXCLUIDOS']), 2));
chequear('y lo de fuera del horizonte queda informado en PAGOS_TODO, en neto', 950.0,
    $series['PAGOS_TODO']['fuera_horizonte']);

seccion('el tablero: universo en bruto = PAGOS_TODO + retenciones');

$aud = $series['PAGOS_TODO']['auditoria'];
chequear('el bruto es la suma de los pendientes', 40000.0, round($aud['bruto'], 2));
chequear('la retencion de cada impuesto, aparte', [1050.0, 700.0],
    [round($aud['retencion_IIBB'], 2), round($aud['retencion_GANANCIAS'], 2)]);
chequear('y cierra', round($aud['bruto'], 2),
    round($totalSerie($series['PAGOS_TODO']) + $aud['retencion_IIBB'] + $aud['retencion_GANANCIAS'], 2));

foreach ($series as $codigo => $s) {
    $ret = 0.0;

    foreach ($s['auditoria'] as $k => $v) {
        if (strpos($k, 'retencion_') === 0) { $ret += $v; }
    }

    if (!chequear('en ' . $codigo . ' tambien: bruto = serie + retenciones',
            round($s['auditoria']['bruto'], 2), round($totalSerie($s) + $ret, 2))) {
        break;
    }
}

chequear('la serie de caja no tiene retenciones', ['bruto' => 5000.0],
    $series['PAGOS_FUERA_CRONOGRAMA']['auditoria']);

seccion('los escalares de auditoria sobreviven a normalizar()');

$normalizar = new ReflectionMethod('CashflowProvider', 'normalizar');
$normalizar->setAccessible(true);
$n = $normalizar->invoke($prov, $hTab, $series['PAGOS_TODO']);
chequear('normalizar() deja pasar la auditoria', round($aud['bruto'], 2), round($n['auditoria']['bruto'], 2));
chequear('y como numeros', 'double', gettype($n['auditoria']['retencion_IIBB']));
$n = $normalizar->invoke($prov, $hTab, ['dias' => []]);
chequear('una serie sin auditoria la trae vacia', [], $n['auditoria']);

seccion('el tablero: la retencion no es un egreso de ninguna fila');

$registro = file_get_contents(__DIR__ . '/../Class/CashflowRegistry.php');
chequear('ninguna serie de PROV_LOCALES se llama retencion', false,
    (bool) preg_match("/'PAGOS_RET|'RETENCION/", $registro));

seccion('Pagos con Tarjetas: sus facturas no llevan retencion y su pendiente no cambia');

$tc = Proveedores::conRetencion(['COD_PROVEE' => 'CUALQU', 'T_COMP' => 'FAC', 'N_COMP' => 'T1',
    'IMPORTE_PENDIENTE' => 7000.0, 'Pago' => $hoy, 'CRONOGRAMA' => false,
    'FORMA_PAGO_VIGENTE' => 'TARJETA CORP'], $base(), $hoy);
chequear('TARJETA CORP no es del cronograma: neto = pendiente', [7000.0, 7000.0, 'FORMA'],
    [$tc['IMPORTE_A_PAGAR'], $tc['IMPORTE_PENDIENTE'], $tc['RETENCION']['motivo']]);

foreach (['PagosTarjetas.php', 'TarjetasCorporativas.php'] as $archivo) {
    chequear($archivo . ' no lee el neto: sigue con IMPORTE_PENDIENTE', false,
        strpos(file_get_contents(__DIR__ . '/../Class/' . $archivo), 'IMPORTE_A_PAGAR') !== false);
}

/* ================================================================
   LA IMPORTACION DE LA PLANILLA DE PAGOS: CONTRA EL NETO
   ================================================================ */
$pendImp = function ($cod, $n, $importe, $extra = []) {
    return array_merge(['COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'RAZON_SOC' => 'N ' . $cod,
        'IMPORTE_PENDIENTE' => $importe, 'FECHA_VTO' => '2026-10-01', 'SIN_FECHA_CARGADA' => true,
        'FORMA_PAGO' => 'ECHEQ', 'FORMA_PAGO_VIGENTE' => 'ECHEQ', 'CRONOGRAMA' => true], $extra);
};
$pendientesImp = [
    $pendImp('CUALQU', 'A1', 100000.0),                    // neto 95.000 (IIBB 3.000 + Ganancias 2.000)
    $pendImp('CUALQU', 'A2', 200000.0),                    // neto 190.000
    $pendImp('CAJAPR', 'C1', 1000.0, ['FORMA_PAGO_VIGENTE' => 'CAJA', 'CRONOGRAMA' => false])
];
$filaImp = function ($linea, $cod, $n, $fecha, $forma = '', $importe = '') {
    return ['linea' => $linea, 'cod_provee' => $cod, 'n_comp' => $n, 'fecha_pago' => $fecha,
            'forma_pago' => $forma, 'importe' => $importe, 't_comp' => '', 'observacion' => ''];
};
$compararImp = function ($filas, $cfg = 'base') use ($pendientesImp, $hoy, $base) {
    return Proveedores::compararImportacion($filas, $pendientesImp, [], $hoy, [], true,
        $cfg === 'base' ? $base() : $cfg);
};

seccion('importacion: un medio contra el neto');

$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '95000')]);
chequear('importe = neto: carga', 'ALTA', $c['filas'][0]['estado']);
chequear('y la fila dice total, retenciones y neto', [100000.0, 5000.0, 95000.0],
    [$c['filas'][0]['importe_pendiente'], $c['filas'][0]['retenido'], $c['filas'][0]['neto']]);
chequear('con el desglose por impuesto', [3000.0, 2000.0],
    [$c['filas'][0]['retenciones']['IIBB']['retencion'], $c['filas'][0]['retenciones']['GANANCIAS']['retencion']]);

$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '95000,80')]);
chequear('con $1 de tolerancia', 'ALTA', $c['filas'][0]['estado']);

$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '')]);
chequear('vacio: toma el neto', ['ALTA', 95000.0], [$c['filas'][0]['estado'], $c['resumen']['importe_con_fecha']]);

seccion('importacion: el total sin retenciones da un error que ayuda');

$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '100000')]);
$m = $c['filas'][0]['motivo'];
chequear('es error', 'ERROR', $c['filas'][0]['estado']);
chequear('dice que coincide con el total sin retenciones', true,
    strpos($m, 'coincide con el total de la factura sin retenciones ($ 100.000,00)') !== false);
chequear('dice el neto y cada retencion', true,
    strpos($m, 'El neto a pagar con retenciones es $ 95.000,00 (Iibb $ 3.000,00, Ganancias $ 2.000,00)') !== false);
chequear('y como se resuelve si no se le retiene', true,
    strpos($m, 'cargale alícuota 0 en el Maestro') !== false);

seccion('importacion: otro importe, con total, retenciones, neto y diferencia');

$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '90000')]);
$m = $c['filas'][0]['motivo'];
chequear('es error', 'ERROR', $c['filas'][0]['estado']);
chequear('muestra los cuatro numeros', true,
    strpos($m, 'total $ 100.000,00, retenciones $ 5.000,00') !== false
    && strpos($m, 'neto $ 95.000,00. La diferencia es de $ -5.000,00') !== false);
chequear('ya no habla del pendiente', false, strpos($m, 'no coincide con el pendiente') !== false);

seccion('importacion: comprobante sin retencion, como siempre');

$c = $compararImp([$filaImp(2, 'CAJAPR', 'C1', '20/10/2026', '', '1000')]);
chequear('CAJA: neto = total, carga', ['ALTA', 1000.0], [$c['filas'][0]['estado'], $c['filas'][0]['neto']]);
$c = $compararImp([$filaImp(2, 'CAJAPR', 'C1', '20/10/2026', '', '900')]);
chequear('y el error es el de siempre, contra el pendiente', true,
    strpos($c['filas'][0]['motivo'], 'no coincide con el pendiente del comprobante ($ 1.000,00)') !== false);
$c = $compararImp([$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '100000')], null);
chequear('sin el script: el total, como antes', 'ALTA', $c['filas'][0]['estado']);

seccion('importacion: dos medios suman el neto');

$c = $compararImp([$filaImp(2, 'CUALQU', 'A2', '20/10/2026', 'ECHEQ', '150000'),
                   $filaImp(3, 'CUALQU', 'A2', '27/10/2026', 'TRANSFERENCIA', '40000')]);
chequear('150.000 + 40.000 = neto 190.000: carga', ['ALTA', 'ALTA'], array_column($c['filas'], 'estado'));

$c = $compararImp([$filaImp(2, 'CUALQU', 'A2', '20/10/2026', 'ECHEQ', '150000'),
                   $filaImp(3, 'CUALQU', 'A2', '27/10/2026', 'TRANSFERENCIA', '50000')]);
chequear('sumando el total: error en las dos, con el motivo que ayuda', ['ERROR', 'ERROR', true],
    [$c['filas'][0]['estado'], $c['filas'][1]['estado'],
     strpos($c['filas'][0]['motivo'], 'coincide con el total de la factura sin retenciones') !== false]);

$c2 = $base();
$c2['generales'][] = $gen(40, 'IIBB', 0.04, '2026-10-25');
$c = $compararImp([$filaImp(2, 'CUALQU', 'A2', '20/10/2026', 'ECHEQ', '150000'),
                   $filaImp(3, 'CUALQU', 'A2', '27/10/2026', 'TRANSFERENCIA', '38000')], $c2);
chequear('la retencion se calcula a la fecha mas tardia de las dos', ['ALTA', 188000.0],
    [$c['filas'][0]['estado'], $c['filas'][0]['neto']]);

seccion('importacion: si una alicuota cambia entre previsualizar y confirmar, no se aplica');

$filas = [$filaImp(2, 'CUALQU', 'A1', '20/10/2026', 'ECHEQ', '')];
$vista = $compararImp($filas);
$c2 = $base();
$c2['generales'][] = $gen(41, 'IIBB', 0.035, '2026-10-14');
$ahora = $compararImp($filas, $c2);
$difs = Proveedores::diferenciasConLoVisto($vista['filas'], $ahora['filas']);
chequear('el estado es el mismo, pero el neto cambio: se detecta', 1, count($difs));
chequear('y el mensaje lo dice', true, strpos($difs[0], 'cambió una alícuota de retención') !== false);
chequear('con las mismas alicuotas no hay diferencias', [],
    Proveedores::diferenciasConLoVisto($vista['filas'], $compararImp($filas)['filas']));

$fuenteProv = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
preg_match('/public function aplicarImportacion\(.*?\n    }\n/s', $fuenteProv, $m);
chequear('aplicarImportacion() revalida con las alicuotas de ahora, no con las de la vista', true,
    strpos($m[0], '$this->retenciones->olvidar();') !== false
    && strpos($m[0], '$conMedios, $this->retenciones->config()') !== false);

$ayuda = Proveedores::columnasImportacion()['importe']['ayuda'];
chequear('la plantilla dice que el importe es neto de retenciones', true,
    strpos($ayuda, 'Neto de retenciones') === 0);

/* ================================================================
   LAS FACTURAS YA PAGADAS: ADVERTENCIA, NO ERROR
   ================================================================ */
seccion('ya pagadas: solo se consultan los que no cruzaron');

$filasYa = [$filaImp(2, 'CUALQU', 'A1', '20/10/2026'), $filaImp(3, 'CUALQU', 'Z9', '20/10/2026'),
            $filaImp(4, 'OTROPR', 'A1', '20/10/2026'), $filaImp(5, 'CUALQU', 'Z9', '21/10/2026')];
chequear('sinCruce: los que no estan en los pendientes, sin repetir',
    [['cod' => 'CUALQU', 'n' => 'Z9'], ['cod' => 'OTROPR', 'n' => 'A1']],
    Proveedores::sinCruce($filasYa, $pendientesImp));

$enTango = [
    'CUALQU|P1' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-08']],
    'CUALQU|P2' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-08'],
                    ['T_COMP' => 'NDI', 'ESTADO' => 'PAG', 'FECHA_CANCELADO' => '2026-10-10']],
    'CUALQU|P3' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-08'],
                    ['T_COMP' => 'NDI', 'ESTADO' => 'PEN', 'FECHA_CANCELADO' => null]],
    'CUALQU|S1' => [['T_COMP' => 'FAC', 'ESTADO' => '***', 'FECHA_CANCELADO' => null]]
];
$compYa = function ($filas) use ($pendientesImp, $hoy, $base, $enTango) {
    return Proveedores::compararImportacion($filas, $pendientesImp, [], $hoy, [], true, $base(), $enTango);
};

seccion('ya pagadas: cancelada en Tango -> advertencia con la fecha real');

$c = $compYa([$filaImp(2, 'CUALQU', 'P1', '20/10/2026'), $filaImp(3, 'CUALQU', 'A1', '20/10/2026')]);
chequear('el estado es YA_PAGADA', 'YA_PAGADA', $c['filas'][0]['estado']);
chequear('con la fecha real del pago', '2026-10-08', $c['filas'][0]['fecha_pagada']);
chequear('y el motivo la dice', true, strpos($c['filas'][0]['motivo'], 'Ya se pagó en Tango el 08/10/2026') === 0);
chequear('no cuenta como error y se cuenta aparte', [0, 1, 1],
    [$c['resumen']['errores'], $c['resumen']['ya_pagadas'], $c['resumen']['altas']]);
chequear('el aviso del resumen la menciona', true,
    count(array_filter($c['avisos'], function ($a) { return strpos($a, 'ya se pagaron en Tango') !== false; })) === 1);

seccion('ya pagadas: sin TIPO_COMP, todos los candidatos cancelados');

$c = $compYa([$filaImp(2, 'CUALQU', 'P2', '20/10/2026')]);
chequear('FAC cancelada y NDI pagada: advertencia, con la fecha mas tardia', ['YA_PAGADA', '2026-10-10'],
    [$c['filas'][0]['estado'], $c['filas'][0]['fecha_pagada']]);

$c = $compYa([$filaImp(2, 'CUALQU', 'P3', '20/10/2026')]);
chequear('si alguno sigue PEN, como hoy: error con el motivo de siempre', ['ERROR', true],
    [$c['filas'][0]['estado'], strpos($c['filas'][0]['motivo'], 'no tiene ningún comprobante P3 pendiente') !== false]);

seccion('ya pagadas: el que no existe sigue siendo error, sin "si ya se pago"');

$c = $compYa([$filaImp(2, 'CUALQU', 'NOEXI', '20/10/2026')]);
chequear('es error', 'ERROR', $c['filas'][0]['estado']);
chequear('dice que puede estar mal tipeado o ser de otro proveedor', true,
    strpos($c['filas'][0]['motivo'], 'puede estar mal tipeado o ser de otro proveedor') !== false);
chequear('y ya no menciona que se haya pagado', false,
    (bool) preg_match('/ya se pag|si ya se/i', $c['filas'][0]['motivo']));

$con = $filaImp(2, 'CUALQU', 'NOEXI', '20/10/2026');
$con['t_comp'] = 'FAC';
$c = $compYa([$con]);
chequear('con TIPO_COMP tambien', [false, true],
    [(bool) preg_match('/si ya se/i', $c['filas'][0]['motivo']),
     strpos($c['filas'][0]['motivo'], 'No existe el comprobante FAC NOEXI') === 0]);

seccion('ya pagadas: en Tango pero fuera de cuenta corriente (***) es otro error');

$c = $compYa([$filaImp(2, 'CUALQU', 'S1', '20/10/2026')]);
chequear('error con su motivo propio', ['ERROR', true],
    [$c['filas'][0]['estado'], strpos($c['filas'][0]['motivo'], 'está en Tango pero no figura en cuenta corriente') !== false]);

seccion('ya pagadas: dos filas de un comprobante ya pagado son dos advertencias');

$a = $filaImp(2, 'CUALQU', 'P1', '20/10/2026', 'ECHEQ', '100');
$b = $filaImp(3, 'CUALQU', 'P1', '27/10/2026', 'TRANSFERENCIA', '200');
$a['t_comp'] = 'FAC';
$b['t_comp'] = 'FAC';
$c = $compYa([$a, $b]);
chequear('las dos YA_PAGADA, no un error del comprobante', ['YA_PAGADA', 'YA_PAGADA', 0, 2],
    [$c['filas'][0]['estado'], $c['filas'][1]['estado'], $c['resumen']['errores'], $c['resumen']['ya_pagadas']]);

seccion('ya pagadas: no se cargan y no frenan');

preg_match('/public function aplicarImportacion\(.*?\n    }\n/s', $fuenteProv, $m);
chequear('aplicarImportacion() solo escribe ALTA y CAMBIO', true,
    strpos($m[0], "if (\$fila['estado'] !== 'ALTA' && \$fila['estado'] !== 'CAMBIO') {") !== false);
chequear('y vuelve a mirar en Tango los que no cruzan, como la previsualizacion', true,
    strpos($m[0], '$this->estadoComprobantesEnTango(self::sinCruce($crudas, $pendientes))') !== false);

$c = $compYa([$filaImp(2, 'CUALQU', 'P1', '20/10/2026')]);
chequear('sin $enTango -no se pudo consultar- queda el error de siempre', 'ERROR',
    Proveedores::compararImportacion([$filaImp(2, 'CUALQU', 'P1', '20/10/2026')], $pendientesImp, [], $hoy)['filas'][0]['estado']);

/* ================================================================
   LA CONCILIACION AUTOMATICA
   ================================================================ */

/** Proveedores con la base reemplazada: los pagos, Tango y la escritura en memoria */
class ProveedoresConcPrueba extends Proveedores {
    public $pagosFalsos = [];
    public $tangoFalso = [];
    public $consultas = 0;
    public $escrituras = [];

    public function tablaCreada() { return true; }
    public function getPagos() { return $this->pagosFalsos; }

    public function estadoComprobantesEnTango($pares) {
        $this->consultas++;

        return $this->tangoFalso;
    }

    protected function escribirConciliacion($concilia, $reabre, $usuario) {
        $this->escrituras[] = ['concilia' => $concilia, 'reabre' => $reabre, 'usuario' => $usuario];

        return ['concilia' => $concilia, 'reabre' => $reabre];
    }
}

$pagoConc = function ($cod, $n, $estado, $fecha = '2026-10-01') {
    return ['COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'ESTADO' => $estado,
            'FECHA_PAGO' => $fecha, 'FECHA_CANCELADO' => $estado === 'CONCILIADO' ? '2026-09-30' : null];
};
$itemConc = function ($cod, $n, $importe = 1000.0) {
    return ['COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n, 'RAZON_SOC' => 'Razon ' . $cod,
            'IMPORTE_PENDIENTE' => $importe];
};

$pagosConc = [
    'P1|FAC|SIGUE' => $pagoConc('P1', 'SIGUE', 'PREVISTO'),         // sigue pendiente: nada
    'P1|FAC|PAGADA' => $pagoConc('P1', 'PAGADA', 'PREVISTO'),       // salio y Tango la cancelo: concilia
    'P2|FAC|NOESTA' => $pagoConc('P2', 'NOESTA', 'PREVISTO'),       // salio y Tango no la tiene: no se toca
    'P3|FAC|VOLVIO' => $pagoConc('P3', 'VOLVIO', 'CONCILIADO'),     // conciliada que volvio: reabre
    'P3|FAC|CERRADA' => $pagoConc('P3', 'CERRADA', 'CONCILIADO')    // conciliada que sigue cerrada: nada
];
$itemsConc = [$itemConc('P1', 'SIGUE'), $itemConc('P3', 'VOLVIO', 2500.5)];
$tangoConc = ['P1|PAGADA' => [['T_COMP' => 'FAC', 'ESTADO' => 'CAN', 'FECHA_CANCELADO' => '2026-10-04']]];

seccion('conciliacion: solo pregunta a Tango por las previstas que salieron de los pendientes');

$plan = Proveedores::planConciliacion($pagosConc, $itemsConc, []);
chequear('consulta P1 PAGADA y P2 NOESTA, no la que sigue pendiente', ['P1|PAGADA', 'P2|NOESTA'],
    array_map(function ($p) { return $p['cod'] . '|' . $p['n']; }, $plan['consultar']));
chequear('la conciliada que volvio se reabre sin consultar', ['VOLVIO'], array_column($plan['reabre'], 'n_comp'));

seccion('conciliacion: concilia y reabre con las reglas de siempre');

$plan = Proveedores::planConciliacion($pagosConc, $itemsConc, $tangoConc);
chequear('concilia la que Tango cancelo, con la fecha real', [['PAGADA', '2026-10-04', 3]],
    array_map(function ($c) { return [$c['n_comp'], $c['fecha_cancelado'], $c['desvio_dias']]; }, $plan['concilia']));
chequear('la que no esta en Tango no se toca', false,
    in_array('NOESTA', array_merge(array_column($plan['concilia'], 'n_comp'), array_column($plan['reabre'], 'n_comp')), true));
chequear('la reabierta trae proveedor, comprobante e importe para el aviso', ['Razon P3', 2500.5],
    [$plan['reabre'][0]['razon_social'], $plan['reabre'][0]['importe']]);

$tangoPen = ['P1|PAGADA' => [['T_COMP' => 'FAC', 'ESTADO' => 'PEN', 'FECHA_CANCELADO' => null]]];
chequear('una que sigue PEN en Tango aunque no este en el listado no se concilia', [],
    Proveedores::planConciliacion($pagosConc, $itemsConc, $tangoPen)['concilia']);

seccion('conciliacion: corre al cargar, con el usuario fijo');

$pc = new ProveedoresConcPrueba();
$pc->pagosFalsos = $pagosConc;
$pc->tangoFalso = $tangoConc;
$r = $pc->conciliarAutomatica($itemsConc);
chequear('escribe una vez, con lo que cambio', [1, 1, 1],
    [count($pc->escrituras), count($pc->escrituras[0]['concilia']), count($pc->escrituras[0]['reabre'])]);
chequear('firma SISTEMA:Proveedores.conciliarAutomatica', 'SISTEMA:Proveedores.conciliarAutomatica',
    $pc->escrituras[0]['usuario']);
chequear('y devuelve los avisos: info por lo conciliado, atencion por lo reabierto', ['info', 'warning'],
    array_column($r['avisos'], 'nivel'));
chequear('el de atencion dice proveedor, comprobante e importe', true,
    strpos($r['avisos'][1]['texto'], 'Razon P3 (P3) FAC VOLVIO $ 2.500,50') !== false);
chequear('y que fue una orden de pago anulada', true,
    strpos($r['avisos'][1]['texto'], 'volvieron a estar pendientes (orden de pago anulada)') !== false);

seccion('conciliacion: si no hay nada que cambiar, no escribe ni avisa');

$pc = new ProveedoresConcPrueba();
$pc->pagosFalsos = ['P1|FAC|SIGUE' => $pagoConc('P1', 'SIGUE', 'PREVISTO')];
$r = $pc->conciliarAutomatica([$itemConc('P1', 'SIGUE')]);
chequear('ni consulta Tango, ni escribe, ni avisa', [0, 0, []], [$pc->consultas, count($pc->escrituras), $r['avisos']]);

$pc = new ProveedoresConcPrueba();
$pc->pagosFalsos = ['P2|FAC|NOESTA' => $pagoConc('P2', 'NOESTA', 'PREVISTO')];
$pc->tangoFalso = [];
$r = $pc->conciliarAutomatica([]);
chequear('lo que Tango no tiene: consulta, pero no escribe', [1, 0], [$pc->consultas, count($pc->escrituras)]);

seccion('conciliacion: si Tango no responde, lanza y la pestaña carga igual');

$pc = new ProveedoresConcPrueba();
$pc->pagosFalsos = $pagosConc;
$pc->tangoFalso = null;
lanzaDiciendo('sin Tango no inventa nada: lanza', function () use ($pc, $itemsConc) {
    $pc->conciliarAutomatica($itemsConc);
}, 'no se pudo leer');
chequear('y no escribio nada', 0, count($pc->escrituras));

$ctrl = file_get_contents(__DIR__ . '/../Controller/ProveedoresController.php');
$getPend = substr($ctrl, strpos($ctrl, "case 'getPendientes':"), 2500);
chequear('el controller la corre en getPendientes, dentro de un try', true,
    (bool) preg_match('/try \{\s*\$avisosConciliacion = \$prov->conciliarAutomatica\(\$items\)\[\'avisos\'\];/', $getPend));
chequear('y si falla, avisa en atencion que no se pudo conciliar', true,
    strpos($getPend, "Aviso::nuevo(Aviso::WARNING, 'No se pudo conciliar con Tango (") !== false);

seccion('conciliacion: corre sin permiso de edicion, y no es una accion');

chequear('getPendientes es una lectura: la ve quien puede ver la pestaña', true,
    in_array('getPendientes', AuthCashflow::LECTURAS['Proveedores'], true));
chequear('previewConciliacion y aplicarConciliacion ya no existen', [false, false, false, false],
    [isset(AuthCashflow::ESCRITURAS['Proveedores']['previewConciliacion']),
     isset(AuthCashflow::ESCRITURAS['Proveedores']['aplicarConciliacion']),
     strpos($ctrl, "case 'previewConciliacion'") !== false, strpos($ctrl, "case 'aplicarConciliacion'") !== false]);

seccion('conciliacion: idempotente y segura con dos cargas a la vez');

$fuenteProv = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
preg_match('/protected function escribirConciliacion\(.*?\n    }\n/s', $fuenteProv, $m);
chequear('en una transaccion', [true, true],
    [strpos($m[0], 'sqlsrv_begin_transaction') !== false, strpos($m[0], 'sqlsrv_rollback') !== false]);
chequear('concilia solo si sigue PREVISTO', true,
    strpos($m[0], "WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ESTADO = 'PREVISTO'") !== false);
chequear('reabre solo si sigue CONCILIADO', true,
    strpos($m[0], "WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ESTADO = 'CONCILIADO'") !== false);
chequear('cuenta solo lo que efectivamente cambio', 2, substr_count($m[0], 'sqlsrv_rows_affected($stmt) > 0'));
chequear('la prevision no se borra: no hay DELETE', false, strpos($m[0], 'DELETE') !== false);

seccion('conciliacion: el aviso informativo, con el desvio desde cinco casos');

$cinco = [];
foreach ([1, 2, 3, 4, 5] as $d) {
    $cinco[] = ['desvio_dias' => $d];
}
$a = Proveedores::avisosConciliacion($cinco, []);
chequear('con cinco dice el desvio promedio', true,
    strpos($a[0]['texto'], 'Se conciliaron 5 previsión(es) con Tango') === 0
    && strpos($a[0]['texto'], 'En promedio se pagó 3 día(s) DESPUÉS de lo previsto') !== false);
$a = Proveedores::avisosConciliacion(array_slice($cinco, 0, 4), []);
chequear('con cuatro no', false, strpos($a[0]['texto'], 'En promedio') !== false);
chequear('sin cambios, sin avisos', [], Proveedores::avisosConciliacion([], []));
