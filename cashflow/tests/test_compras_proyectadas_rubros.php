<?php
/**
 * Compras proyectadas: el % importado por rubro.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL % SE APLICA DESPUES DE SUMAR, O NO SE APLICA. La vista ya no filtra
 *      el cuero: si el job no multiplica cada fila por su %, el tablero
 *      proyecta millones que nadie decidio comprar en el exterior.
 *
 *   2. UN RUBRO CONFIGURADO NO ENCUENTRA SU %. Basta un espacio de mas o una
 *      minuscula en la app de compras para que el cruce falle y el rubro entre
 *      al 100 % avisando que es nuevo. La normalizacion del SP y la de PHP
 *      tienen que ser la misma.
 *
 *   3. UN RUBRO NUEVO ENTRA AL 100 % SIN QUE NADIE LO SEPA.
 *
 *   4. SE PIERDE EL HISTORIAL, o quedan dos % vigentes del mismo rubro.
 *
 *   5. SE GUARDA UN % QUE NO SIGNIFICA NADA: 120, -5, o sin saber quien.
 *
 *   6. LA SEMILLA PISA UN % EDITADO al volver a correr el script.
 *
 *   7. EL AVISO DE LO EXCLUIDO DICE UN IMPORTE QUE NO ES LA SUMA DE LOS RUBROS.
 *
 * Las reglas puras se prueban sin base. Lo que necesita SQL Server es SOLO
 * LECTURA y se saltea solo: este archivo NO cambia ningun % contra la base.
 */

require_once __DIR__ . '/../Class/ComprasProyectadasRubros.php';
require_once __DIR__ . '/../Class/ComprasProyectadasDatos.php';

/** El codigo de un archivo, SIN SUS COMENTARIOS. En el SQL, solo los de bloque. */
if (!function_exists('codigoSinComentariosRUB')) {
    function codigoSinComentariosRUB($ruta, $sql = false) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return $sql ? $src : preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

/** Saca los espacios para comparar SQL sin depender del formato */
if (!function_exists('compactoRUB')) {
    function compactoRUB($s) {
        return preg_replace('/\s+/', '', $s);
    }
}

$SQL = __DIR__ . '/../sql/';

/* ========================================================================
   1. EL % SE APLICA FILA POR FILA, ANTES DE SUMAR
   ======================================================================== */

seccion('0 % excluye, 100 % deja igual, 60 % proyecta el 60 %');

$vigentes = [
    'CARTERAS DE CUERO' => ['pct' => 0.0, 'usuario' => 'x', 'fecha' => null],
    'CALZADOS' => ['pct' => 100.0, 'usuario' => 'x', 'fecha' => null],
    'BIJOU' => ['pct' => 60.0, 'usuario' => 'x', 'fecha' => null]
];

$filas = ComprasProyectadasRubros::aplicar([
    ['rubro' => 'CARTERAS DE CUERO', 'fob_usd' => 1000.0],
    ['rubro' => 'CALZADOS', 'fob_usd' => 2000.0],
    ['rubro' => 'BIJOU', 'fob_usd' => 500.0],
    ['rubro' => 'BIJOU', 'fob_usd' => 300.0]
], $vigentes);

chequear('0 %: el rubro queda fuera', 0.0, $filas[0]['fob_proyectado']);
chequear('100 %: el rubro entra igual', 2000.0, $filas[1]['fob_proyectado']);
chequear('60 %: se proyecta el 60 % de cada fila', 300.0, $filas[2]['fob_proyectado']);
chequear('y de la otra fila del mismo rubro', 180.0, $filas[3]['fob_proyectado']);
chequear('cada fila dice su %', [0.0, 100.0, 60.0, 60.0],
    array_column($filas, 'pct_importado'));
chequear('la suma de lo proyectado es la suma de cada fila por su %',
    2480.0, array_sum(array_column($filas, 'fob_proyectado')));

seccion('El SP multiplica cada fila por su % ADENTRO del SUM');

$sp = codigoSinComentariosRUB($SQL . 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql', true);
$spc = compactoRUB($sp);

chequear('el FOB es SUM(compra x costo x pct / 100)', true,
    strpos($spc, 'SUM(ISNULL(f.COMPRA,0)*ISNULL(f.COSTO_PROM,0)*ISNULL(f.PCT,100)/100.0)') !== false);
chequear('las unidades tambien se escalan', true,
    strpos($spc, 'SUM(ISNULL(f.COMPRA,0)*ISNULL(f.PCT,100)/100.0)') !== false);
chequear('la nacionalizacion segun inc_fob tambien', true,
    strpos($spc, '*ISNULL(f.INC_FOB,0)/100.0*ISNULL(f.PCT,100)/100.0)') !== false);
chequear('y el deficit de cobertura', true,
    strpos($spc, 'SUM(ISNULL(f.DEFICIT,0)*ISNULL(f.PCT,100)/100.0)') !== false);
chequear('el FOB sin ajustar se guarda aparte', true,
    strpos($spc, 'FOB_PRESUPUESTO_USD') !== false);
chequear('las filas cuentan solo las que se proyectan', true,
    strpos($spc, 'SUM(CASEWHENISNULL(f.PCT,100)>0THEN1ELSE0END)') !== false);
chequear('el % se cruza en local, contra la tabla del cashflow', true,
    strpos($spc, 'JOINdbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCTpONp.RUBRO=f.RUBROANDp.VIGENTE=1') !== false);
chequear('la vista se lee UNA vez', 1,
    substr_count($sp, 'POWER_BI_CONTROL.dbo.RO_V_COMPRA_PROYECTADA_VIGENTE'));
chequear('el resumen agrupa las filas ya cruzadas, no la vista', true,
    strpos($spc, 'FROM#FILASfGROUPBYf.PAIS,f.TEMPORADA;') !== false);
chequear('sin la tabla del % no recalcula', true,
    strpos($sp, "OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT', 'U') IS NULL") !== false
    && strpos($sp, 'THROW 50003') !== false);
chequear('y graba el detalle por rubro en la misma transaccion', true,
    strpos($sp, 'DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO') > strpos($sp, 'BEGIN TRANSACTION')
    && strpos($sp, 'DELETE FROM dbo.RO_T_CASHFLOW_COMEX_PRESUP_RUBRO') < strpos($sp, 'COMMIT TRANSACTION'));

/* La nacionalizacion que se PROYECTA no la calcula el SP: sale del FOB del
   resumen, que ya viene reducido. */
seccion('La nacionalizacion proyectada sale del FOB ya reducido');

$cp = codigoSinComentariosRUB(__DIR__ . '/../Class/ComprasProyectadas.php');

chequear('lo proyectado parte del fob_usd de la version', true,
    strpos($cp, "\$fila['version']['fob_usd'] * \$pct / 100.0") !== false);
chequear('y la nacionalizacion es la estimacion por el %', true,
    strpos($cp, "\$fila['estimacion_usd'] * floatval(\$nacPct) / 100.0") !== false);

/* ========================================================================
   2. EL RUBRO SIN CONFIGURAR
   ======================================================================== */

seccion('Un rubro sin configurar entra al 100 % y avisa');

$f = ComprasProyectadasRubros::aplicar([['rubro' => 'MOCHILAS', 'fob_usd' => 750.0]], $vigentes);

chequear('entra al 100 %', 750.0, $f[0]['fob_proyectado']);
chequear('y se marca sin configurar', false, $f[0]['configurado']);
chequear('el SP tambien: ISNULL(pct, 100)', true, strpos($spc, 'ISNULL(f.PCT,100)') !== false);
chequear('y el SP no lo da de alta', false,
    (bool) preg_match('/INSERT\s+INTO\s+dbo\.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT/i', $sp));

$estado = [
    'error' => null,
    'presupuesto' => ['ok' => ['inicio' => '2026-10-25 08:00:00', 'fin' => '2026-10-25 08:00:01']],
    'rubro_pct' => ['tabla' => true, 'ultimo_cambio' => '2026-10-24 10:00:00']
];

$presupuesto = [
    ['temporada' => 'INV 27', 'rubro' => 'MOCHILAS', 'fob_presupuesto' => 750.0,
     'pct_aplicado' => 100.0, 'configurado' => false, 'fob_proyectado' => 750.0],
    ['temporada' => 'INV 27', 'rubro' => 'CALZADOS', 'fob_presupuesto' => 2000.0,
     'pct_aplicado' => 100.0, 'configurado' => true, 'fob_proyectado' => 2000.0]
];

$textos = Aviso::textos(ComprasProyectadasDatos::avisosRubrosDe($estado, $presupuesto, $vigentes));

chequear('el aviso dice el texto acordado', true, in_array('Rubro nuevo sin configurar: MOCHILAS. '
    . 'Se está proyectando al 100%. Asignale su % importado en Parámetros › Compras Exterior.',
    $textos, true));
chequear('y un rubro configurado no avisa', 0, count(array_filter($textos, function ($t) {
    return strpos($t, 'CALZADOS') !== false;
})));

$niveles = array_column(ComprasProyectadasDatos::avisosRubrosDe($estado, $presupuesto, $vigentes), 'nivel');
chequear('el de sin configurar es de atencion', Aviso::WARNING, $niveles[0]);

$conMochilas = $vigentes + ['MOCHILAS' => ['pct' => 100.0, 'usuario' => 'x', 'fecha' => null]];
chequear('apenas se le asigna un %, el aviso desaparece aunque el job no haya corrido', 0,
    count(array_filter(Aviso::textos(ComprasProyectadasDatos::avisosRubrosDe($estado, $presupuesto,
        $conMochilas)), function ($t) { return strpos($t, 'sin configurar') !== false; })));

/* ========================================================================
   3. LA COMPARACION TOLERA ESPACIOS Y MAYUSCULAS
   ======================================================================== */

seccion('El rubro se compara normalizado');

chequear('mayusculas', 'COSMETICA', ComprasProyectadasRubros::normalizar('Cosmetica'));
chequear('espacios en las puntas', 'COSMETICA', ComprasProyectadasRubros::normalizar('  COSMETICA '));
chequear('espacios repetidos adentro', 'CARTERAS DE CUERO',
    ComprasProyectadasRubros::normalizar('carteras   de  cuero'));
chequear('acentos y enies en mayuscula', 'ÑANDÚ', ComprasProyectadasRubros::normalizar('ñandú'));

$f = ComprasProyectadasRubros::aplicar([['rubro' => ' Carteras  de Cuero ', 'fob_usd' => 100.0]], $vigentes);

chequear('un rubro escrito distinto encuentra su %', 0.0, $f[0]['pct_importado']);
chequear('y no se marca como nuevo', true, $f[0]['configurado']);

chequear('el SP normaliza igual: UPPER, LTRIM/RTRIM y el colapso de espacios', true,
    strpos($spc, "UPPER(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(v.rubro)),'',''+CHAR(7)),"
        . "CHAR(7)+'',''),CHAR(7),''))") !== false);

/* Y la regla de PHP produce lo mismo que esa cadena de REPLACE, simulada. */
foreach (['  carteras   de cuero ', 'Bijou', "HOME", 'a  b   c'] as $crudo) {
    $sqlSimulado = strtoupper(str_replace(chr(7), '', str_replace(chr(7) . ' ', '',
        str_replace(' ', ' ' . chr(7), trim($crudo, ' ')))));

    chequear('"' . $crudo . '" se normaliza igual en PHP y en el SP', $sqlSimulado,
        ComprasProyectadasRubros::normalizar($crudo));
}

/* ========================================================================
   4. EL HISTORIAL: UN SOLO VIGENTE POR RUBRO
   ======================================================================== */

seccion('Cambiar un % da de baja el anterior, en la misma transaccion');

$script = codigoSinComentariosRUB($SQL . 'cashflow_compras_proy_rubro_pct.sql', true);
$rub = codigoSinComentariosRUB(__DIR__ . '/../Class/ComprasProyectadasRubros.php');

chequear('el indice unico es por RUBRO y filtrado por VIGENTE = 1', true,
    (bool) preg_match('/CREATE\s+UNIQUE\s+NONCLUSTERED\s+INDEX\s+UX_RO_T_CF_CPRUB_RUBRO_VIGENTE\s+'
        . 'ON\s+dbo\.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT\s*\(RUBRO\)[^;]*WHERE\s+VIGENTE\s*=\s*1/s', $script));
chequear('la tabla tiene la auditoria completa', true,
    strpos($script, 'USUARIO_ALTA') && strpos($script, 'USUARIO_MODIF') && strpos($script, 'USUARIO_BAJA')
    && strpos($script, 'FECHA_ALTA') && strpos($script, 'FECHA_MODIF') && strpos($script, 'FECHA_BAJA'));
chequear('y un CHECK de 0 a 100', true,
    strpos($script, 'CHECK (PCT_IMPORTADO >= 0 AND PCT_IMPORTADO <= 100)') !== false);

$iBegin = strpos($rub, 'sqlsrv_begin_transaction');
$iBaja = strpos($rub, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA');
$iAlta = strpos($rub, 'INSERT INTO " . self::TABLA');
$iCommit = strpos($rub, 'sqlsrv_commit');

chequear('la baja marca VIGENTE = 0 con su auditoria', true, $iBaja !== false);
chequear('la baja va antes que el alta', true, $iBaja !== false && $iAlta !== false && $iBaja < $iAlta);
chequear('y las dos van dentro de la transaccion', true,
    $iBegin !== false && $iCommit !== false && $iBegin < $iBaja && $iAlta < $iCommit);
chequear('con rollback si algo falla', true, strpos($rub, 'sqlsrv_rollback') !== false);
chequear('la clase no borra filas', false, (bool) preg_match('/\bDELETE\b/i', $rub));
chequear('el rubro se guarda normalizado', true,
    strpos($rub, '$rubro = self::normalizar($rubro);') !== false);

seccion('ComprasProyectadasDatos sigue sin escribir');

$datosSrc = codigoSinComentariosRUB(__DIR__ . '/../Class/ComprasProyectadasDatos.php');

foreach (['INSERT', 'UPDATE', 'DELETE', 'MERGE'] as $verbo) {
    chequear('no tiene ' . $verbo, false, (bool) preg_match('/\b' . $verbo . '\s/', $datosSrc));
}

/* ========================================================================
   5. VALIDACIONES
   ======================================================================== */

seccion('Se rechaza lo que no es una parte del rubro');

foreach (['0', '100', '60', '62.5', '62,5', '33.33', 0, 100] as $bueno) {
    chequear(var_export($bueno, true) . ' pasa', [], ComprasProyectadasRubros::validar('CALZADOS', $bueno));
}

foreach (['120', '-1', '100.01', '-0.5', 'abc', '', '33.333', null] as $malo) {
    chequear(var_export($malo, true) . ' se rechaza', true,
        count(ComprasProyectadasRubros::validar('CALZADOS', $malo)) > 0);
}

chequear('sin rubro se rechaza', true, count(ComprasProyectadasRubros::validar('   ', 50)) > 0);
chequear('un rubro de mas de 100 caracteres se rechaza', true,
    count(ComprasProyectadasRubros::validar(str_repeat('A', 101), 50)) > 0);

$e = ComprasProyectadasRubros::validar('CALZADOS', '120');
chequear('el mensaje dice el rango', true, strpos($e[0], 'entre 0 y 100') !== false);

seccion('Sin usuario no se escribe');

$r = new ComprasProyectadasRubros(new ComprasProyectadasDatos);

/* Lanza ANTES de tocar la base: usuarioDeEscritura() es lo primero. */
chequearLanza('guardar sin usuario lanza', function () use ($r) {
    $r->guardar('CALZADOS', 50, null);
}, 'No se puede guardar un cambio sin saber quién lo hace: falta el usuario.');
chequearLanza('y con el usuario vacio tambien', function () use ($r) {
    $r->guardar('CALZADOS', 50, '   ');
}, 'No se puede guardar un cambio sin saber quién lo hace: falta el usuario.');
chequearLanza('un % fuera de rango lanza antes de tocar la base', function () use ($r) {
    $r->guardar('CALZADOS', 120, 'tester');
}, 'El % importado tiene que estar entre 0 y 100: 120 % no es una parte del rubro.');

seccion('Los endpoints piden el permiso de Parametros > Compras Exterior');

$acciones = (new ReflectionClass('AuthCashflow'))->getConstant('ESCRITURAS');

chequear('guardar un %', [['parametros', 'COMPRAS_PROY']],
    isset($acciones['Parametros']['saveRubroPctComprasProy'])
        ? $acciones['Parametros']['saveRubroPctComprasProy'] : null);
chequear('recalcular la proyeccion', [['parametros', 'COMPRAS_PROY']],
    isset($acciones['Parametros']['recalcularPresupuestoComprasProy'])
        ? $acciones['Parametros']['recalcularPresupuestoComprasProy'] : null);

$js = codigoSinComentariosRUB(__DIR__ . '/../Js/Parametros-Compras_proy.js');

chequear('sin permiso la tabla pasa a solo lectura', true,
    strpos($js, 'Permisos.soloLectura(cont);') !== false);
chequear('el historial queda visible sin permiso', true,
    (bool) preg_match('/pcpr-rubro-hist" data-lectura/', $js));
chequear('el boton de recalcular no se puede disparar dos veces', true,
    strpos($js, 'if (recalculando) { return; }') !== false);
chequear('el JS valida 0 a 100 antes de mandar', true,
    strpos($js, 'n < 0 || n > 100') !== false);

/* ========================================================================
   6. LA SEMILLA ES IDEMPOTENTE
   ======================================================================== */

seccion('El script no pisa un % ya editado');

chequear('la tabla se crea solo si no existe', true,
    strpos($script, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT', 'U') IS NULL") !== false);
chequear('la semilla inserta solo los rubros sin % vigente', true,
    (bool) preg_match('/WHERE\s+NOT\s+EXISTS\s*\(\s*SELECT\s+1\s+FROM\s+dbo\.RO_T_CASHFLOW_COMPRAS_PROY_RUBRO_PCT\s+p\s+'
        . 'WHERE\s+p\.RUBRO\s*=\s*s\.RUBRO\s+AND\s+p\.VIGENTE\s*=\s*1\s*\)/', $script));
chequear('no hay UPDATE ni MERGE que pueda pisar uno', false,
    (bool) preg_match('/\b(UPDATE|MERGE|DELETE)\b/', $script));
chequear('la semilla firma como SISTEMA', true,
    strpos($script, "'SISTEMA:cashflow_compras_proy_rubro_pct.sql'") !== false);

preg_match_all("/\('([A-Z ]+)',\s*(\d+)\)/", $script, $m);
$semilla = array_combine($m[1], array_map('floatval', $m[2]));

chequear('siembra los 20 rubros', 20, count($semilla));

foreach (['ACCESORIOS DE CUERO', 'BILLETERAS DE CUERO', 'CARTERAS DE CUERO', 'CINTOS DE CUERO',
          'COSMETICA', 'LENTES', 'RELOJES'] as $cero) {
    chequear($cero . ' en 0 %', 0.0, isset($semilla[$cero]) ? $semilla[$cero] : null);
}

chequear('los otros 13 en 100 %', 13, count(array_filter($semilla, function ($p) { return $p == 100; })));
chequear('y cada rubro sembrado ya esta normalizado', [],
    array_values(array_filter(array_keys($semilla), function ($r) {
        return ComprasProyectadasRubros::normalizar($r) !== $r;
    })));

/* ========================================================================
   7. EL AVISO DE LO EXCLUIDO ES LA SUMA DE LOS RUBROS AFECTADOS
   ======================================================================== */

seccion('Lo excluido coincide con la suma del FOB de los rubros afectados');

$porRubro = [
    ['temporada' => 'INV 27', 'rubro' => 'CARTERAS DE CUERO', 'fob_presupuesto' => 1000.0, 'pct_aplicado' => 0.0],
    ['temporada' => 'VER 27-28', 'rubro' => 'CARTERAS DE CUERO', 'fob_presupuesto' => 1500.0, 'pct_aplicado' => 0.0],
    ['temporada' => 'INV 27', 'rubro' => 'LENTES', 'fob_presupuesto' => 400.0, 'pct_aplicado' => 0.0],
    ['temporada' => 'INV 27', 'rubro' => 'BIJOU', 'fob_presupuesto' => 800.0, 'pct_aplicado' => 60.0],
    ['temporada' => 'INV 27', 'rubro' => 'CALZADOS', 'fob_presupuesto' => 5000.0, 'pct_aplicado' => 100.0]
];

$x = ComprasProyectadasRubros::excluido($porRubro);

/* 1000 + 1500 + 400 en 0 %, y el 40 % de 800 de BIJOU. */
chequear('el FOB excluido', 3220.0, round($x['fob'], 2));
chequear('un rubro en dos temporadas cuenta una vez', ['CARTERAS DE CUERO', 'LENTES'], $x['en_cero']);
chequear('los de % parcial', ['BIJOU'], $x['parciales']);

$aviso = ComprasProyectadasDatos::avisoExcluidoDe($porRubro);

chequear('el aviso dice el importe y los rubros', 'Por el % importado quedan fuera de la '
    . 'proyección U$S 3.220,00 de FOB (2 rubros en 0% y 1 con % parcial).', $aviso['texto']);
chequear('y es informativo', Aviso::INFO, $aviso['nivel']);
chequear('todo al 100 %: no hay aviso', null, ComprasProyectadasDatos::avisoExcluidoDe([$porRubro[4]]));

/* La tabla de Parametros suma las temporadas y coincide con el aviso. */
$tabla = ComprasProyectadasRubros::tablaParametros($porRubro, [
    'CARTERAS DE CUERO' => ['pct' => 0.0, 'usuario' => 'u', 'fecha' => '2026-10-01 10:00'],
    'LENTES' => ['pct' => 0.0, 'usuario' => 'u', 'fecha' => null],
    'BIJOU' => ['pct' => 60.0, 'usuario' => 'u', 'fecha' => null],
    'CALZADOS' => ['pct' => 100.0, 'usuario' => 'u', 'fecha' => null],
    'PARAGUAS' => ['pct' => 100.0, 'usuario' => 'u', 'fecha' => null]
]);
$porNombre = array_column($tabla, null, 'rubro');

chequear('Parametros suma las dos temporadas de un rubro', 2500.0,
    $porNombre['CARTERAS DE CUERO']['fob_presupuesto']);
chequear('con el desglose por temporada', 2, count($porNombre['CARTERAS DE CUERO']['temporadas']));
chequear('y lo que Parametros deja fuera es lo que dice el aviso', 3220.0,
    round(array_sum(array_column($tabla, 'fob_presupuesto')) - array_sum(array_column($tabla, 'fob_proyectado')), 2));
chequear('un rubro con % que no esta en el presupuesto se muestra, marcado', false,
    $porNombre['PARAGUAS']['en_presupuesto']);
chequear('y va al final', 'PARAGUAS', $tabla[count($tabla) - 1]['rubro']);

/* ========================================================================
   8. EL CONTROL DE LA VISTA Y LOS CAMBIOS SIN RECALCULAR
   ======================================================================== */

seccion('El aviso viejo de la vista ya no existe');

chequear('avisoContraste() se reemplazo', false, method_exists('ComprasProyectadasDatos', 'avisoContraste'));
chequear('vista igual a sus tablas: sin aviso', null, ComprasProyectadasDatos::avisoControlVista(
    ['filas_vista' => 72, 'filas_tablas' => 72, 'fob_vista' => 10.0, 'fob_tablas' => 10.0]));

$control = ComprasProyectadasDatos::avisoControlVista(
    ['filas_vista' => 55, 'filas_tablas' => 72, 'fob_vista' => 100.0, 'fob_tablas' => 350.5]);

chequear('vista con menos filas: avisa', true, $control !== null);
chequear('con las filas y el FOB de la diferencia', true,
    strpos($control['texto'], '17 filas menos') !== false && strpos($control['texto'], 'U$S 250,50') !== false);
chequear('con el nivel del control', ComprasProyectadasDatos::NIVEL_CONTROL_VISTA, $control['nivel']);

foreach ([$SQL . 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql', __DIR__ . '/../Class/ComprasProyectadasDatos.php',
          __DIR__ . '/../Class/Providers/ComprasProyectadasProvider.php',
          __DIR__ . '/../Controller/ComprasProyectadasController.php'] as $archivo) {
    chequear(basename($archivo) . ' ya no habla de "la vista deja afuera"', false,
        stripos(codigoSinComentariosRUB($archivo), 'deja afuera') !== false);
}

seccion('Un % cambiado despues del ultimo calculo se avisa');

chequear('cambio anterior al calculo: sin aviso', null, ComprasProyectadasDatos::avisoSinRecalcularDe($estado));

$despues = $estado;
$despues['rubro_pct']['ultimo_cambio'] = '2026-10-25 09:30:00';
$a = ComprasProyectadasDatos::avisoSinRecalcularDe($despues);

chequear('cambio posterior: avisa', true, $a !== null);
chequear('de atencion', Aviso::WARNING, $a['nivel']);
chequear('y dice como se ve', true, strpos($a['texto'], 'Recalcular la proyección') !== false);

$sinTabla = ['error' => null, 'rubro_pct' => ['tabla' => false, 'ultimo_cambio' => null]];
chequear('sin la tabla del %, se dice que script correr', true, strpos(Aviso::textos(
    ComprasProyectadasDatos::avisosRubrosDe($sinTabla, [], []))[0], 'cashflow_compras_proy_rubro_pct.sql') !== false);

/* ========================================================================
   CONTRA LA BASE, SOLO LECTURA
   ======================================================================== */

seccion('Contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $d = new ComprasProyectadasDatos;
    $e = $d->estadoInsumos();

    if (!empty($e['error'])) {
        Pruebas::saltear('no se pudo leer la base: ' . $e['error']);
    } elseif (empty($e['rubro_pct']['tabla'])) {
        Pruebas::saltear('falta correr sql/cashflow_compras_proy_rubro_pct.sql');
    } else {
        require_once __DIR__ . '/../../class/conexion.php';
        $cid = (new Conexion)->conectar('central');

        $stmt = sqlsrv_query($cid, "SELECT COUNT(*) N FROM (SELECT RUBRO FROM "
            . ComprasProyectadasDatos::TABLA_RUBRO_PCT . " WHERE VIGENTE = 1 GROUP BY RUBRO
              HAVING COUNT(*) > 1) x");
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        chequear('hay un solo % vigente por rubro', 0, intval($row['N']));

        $vig = $d->pctVigentes();

        chequear('los 20 rubros sembrados tienen %', true, count($vig) >= 20);
        chequear('ningun % vigente fuera de 0 a 100', [], array_keys(array_filter($vig, function ($v) {
            return $v['pct'] < 0 || $v['pct'] > 100;
        })));

        $porRubroBase = $d->presupuestoPorRubro();

        if (empty($porRubroBase)) {
            Pruebas::saltear('el presupuesto por rubro esta vacio: falta correr el SP');
        } else {
            /* El resumen por temporada es la suma de su detalle por rubro. */
            $sumas = [];

            foreach ($porRubroBase as $r) {
                $t = $r['temporada'];
                $sumas[$t] = (isset($sumas[$t]) ? $sumas[$t] : 0) + $r['fob_proyectado'];
            }

            foreach ($d->versionesOficiales() as $codigo => $v) {
                chequear($codigo . ': el FOB del resumen es la suma de sus rubros', true,
                    abs($v['fob_usd'] - (isset($sumas[$codigo]) ? $sumas[$codigo] : 0)) < 0.05);
            }

            $c = $d->contrasteVista();

            if (!empty($c)) {
                chequear('la vista devuelve las mismas filas que sus tablas', $c['filas_tablas'], $c['filas_vista']);
            }
        }
    }
}
