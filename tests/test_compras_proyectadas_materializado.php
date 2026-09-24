<?php
/**
 * Compras Exterior: los insumos pesados que calcula un job.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL SP DEJA DE CALCULAR LO MISMO QUE LA DEFINICION. La historia tiene dos
 *      escrituras -el SP y historiaRecepcionesEnVivo()- y la cuota sale de la
 *      primera. Si un filtro cambia en una sola, el tablero reparte con una
 *      historia que nadie puede reproducir desde el codigo.
 *
 *   2. SIN EL JOB, LA FILA VA EN CERO CALLADA. Una fila de egresos en cero se
 *      lee como "no hay que pagar nada". El primer aviso tiene que decir que se
 *      proyecta DE MENOS y que job falta.
 *
 *   3. UN PRESUPUESTO VIEJO SE LEE COMO EL DE HOY. Si alguien marca una oficial
 *      y el job no corre, el tablero sigue con la anterior. Eso tiene que
 *      avisarse, por horas y contra las oficiales de hoy.
 *
 *   4. EL SP BORRA LA TABLA ANTES DE TENER EL RESULTADO. Quien lee durante la
 *      corrida veria la tabla vacia: filas en cero por un segundo, o para
 *      siempre si la corrida se cae a mitad de camino.
 *
 *   5. LA PANTALLA VUELVE A TARDAR. El punto del job es que la pestana tarde
 *      menos de 2 s. Contra la base, con las tablas llenas, se mide.
 *
 * Lo que necesita SQL Server se saltea solo, y es todo de lectura.
 */

require_once __DIR__ . '/../cashflow/Class/Horizonte.php';
require_once __DIR__ . '/../cashflow/Class/ComprasProyectadas.php';
require_once __DIR__ . '/../cashflow/Class/ComprasProyectadasDatos.php';
require_once __DIR__ . '/../cashflow/Class/Providers/ComprasProyectadasProvider.php';

if (!function_exists('codigoSinComentariosMAT')) {
    /** El codigo PHP de un archivo, sin sus comentarios */
    function codigoSinComentariosMAT($ruta) {
        $out = '';

        foreach (token_get_all(file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** Un script SQL sin sus comentarios de BLOQUE (ver README, seccion Pruebas) */
    function sqlSinBloquesMAT($ruta) {
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents($ruta));
    }

    /** Un estado de insumos completo, para las pruebas sin base */
    function estadoMAT($cambios = []) {
        $ok = function ($fin) {
            return ['inicio' => $fin, 'fin' => $fin, 'filas' => 10, 'usuario' => 'job'];
        };

        $e = [
            'error' => null,
            'log' => true,
            'contraste' => true,
            'historia' => ['tabla' => true, 'filas' => 116, 'anio_min' => 2016, 'anio_max' => 2025,
                           'ok' => $ok('2026-09-24 05:00:02'), 'ultima' => null],
            'presupuesto' => ['tabla' => true, 'filas' => 2, 'ok' => $ok('2026-09-24 08:30:01'),
                              'ultima' => null]
        ];

        foreach ($cambios as $ruta => $valor) {
            $partes = explode('.', $ruta);
            $ref = &$e;

            foreach ($partes as $p) {
                $ref = &$ref[$p];
            }

            $ref = $valor;
            unset($ref);
        }

        return $e;
    }

    /** Unas lecturas que devuelven el estado que se les diga, sin base */
    class DatosFijosMAT extends ComprasProyectadasDatos {
        public $e;

        function __construct($e) {
            $this->e = $e;
        }

        public function estadoInsumos() {
            return $this->e;
        }
    }
}

$SQL = __DIR__ . '/../sql/';
$CLS = __DIR__ . '/../cashflow/Class/';

/* ========================================================================
   LOS SCRIPTS
   ======================================================================== */

seccion('Los tres scripts existen');

$tablas = $SQL . 'cashflow_comex_materializado.sql';
$spHist = $SQL . 'RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql';
$spPres = $SQL . 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN.sql';

foreach ([$tablas, $spHist, $spPres] as $f) {
    chequear(basename($f) . ' existe', true, file_exists($f));
}

seccion('El script de tablas es reejecutable');

$srcTablas = sqlSinBloquesMAT($tablas);

foreach ([ComprasProyectadasDatos::TABLA_JOB_LOG, ComprasProyectadasDatos::TABLA_RECEP_HIST,
          ComprasProyectadasDatos::TABLA_PRESUP_RESUMEN,
          ComprasProyectadasDatos::TABLA_PRESUP_CONTRASTE] as $t) {
    chequear('crea ' . $t . ' preguntando antes si existe',
        1, preg_match('/IF OBJECT_ID\(\'dbo\.' . $t . '\', \'U\'\) IS NULL\s+BEGIN\s+CREATE TABLE dbo\.' . $t . '\b/', $srcTablas));
}

chequear('no borra ninguna tabla', 0, preg_match_all('/\bDROP\b/i', $srcTablas));

foreach (['historia' => $spHist, 'presupuesto' => $spPres] as $cual => $f) {
    seccion('El SP de ' . $cual . ': reejecutable, con log y sin vaciar la tabla antes de tiempo');

    $src = sqlSinBloquesMAT($f);
    $sp = ($cual === 'historia') ? ComprasProyectadasDatos::SP_HISTORIA
                                 : ComprasProyectadasDatos::SP_PRESUPUESTO;
    $proceso = ($cual === 'historia') ? ComprasProyectadasDatos::PROCESO_HISTORIA
                                      : ComprasProyectadasDatos::PROCESO_PRESUPUESTO;

    chequear('es CREATE OR ALTER', 1,
        preg_match('/CREATE OR ALTER PROCEDURE dbo\.' . $sp . '\b/', $src));

    chequear('se anota en el log con su PROCESO',
        true, strpos($src, "@Proceso VARCHAR(60) = '" . $proceso . "'") !== false);

    /* El intento va al log ANTES del TRY y fuera de la transaccion: si la
       corrida se cae, queda registrada igual. */
    chequear('registra el inicio antes del TRY',
        true, strpos($src, 'INSERT INTO dbo.RO_T_CASHFLOW_JOB_LOG') < strpos($src, 'BEGIN TRY'));

    chequear('tiene TRY/CATCH', true,
        strpos($src, 'BEGIN TRY') !== false && strpos($src, 'BEGIN CATCH') !== false);

    $catch = substr($src, strpos($src, 'BEGIN CATCH'));

    chequear('el CATCH deshace la transaccion', true, strpos($catch, 'ROLLBACK TRANSACTION') !== false);
    chequear('el CATCH escribe el error en el log', 1,
        preg_match('/UPDATE dbo\.RO_T_CASHFLOW_JOB_LOG\s+SET FIN = GETDATE\(\), ERROR = @Error/', $catch));
    chequear('y lo relanza, para que el job quede fallido', true, strpos($catch, 'THROW;') !== false);

    /* LA TABLA SE VACIA RECIEN CON EL RESULTADO LISTO: el DELETE va despues
       de la ultima temporal y adentro de la transaccion. */
    $tx = strpos($src, 'BEGIN TRANSACTION');
    $del = strpos($src, 'DELETE FROM dbo.RO_T_CASHFLOW_COMEX_');
    $ultimaTemporal = max(strrpos($src, 'INTO #'), strrpos($src, 'INSERT INTO #'));

    chequear('calcula en temporales antes de abrir la transaccion', true, $ultimaTemporal < $tx);
    chequear('y borra adentro de la transaccion', true, $tx < $del);
    chequear('una sola transaccion', 1, substr_count($src, 'BEGIN TRANSACTION'));

    chequear('no permite dos corridas a la vez', true, strpos($src, 'sp_getapplock') !== false);

    /* EL JOB LO CREA EL USUARIO. El script solo lo sugiere, comentado. */
    $crudo = file_get_contents($f);

    chequear('sugiere la programacion al pie', true,
        strpos($crudo, 'PROGRAMACION SUGERIDA PARA EL SQL AGENT') !== false);

    chequear('y no crea ningun job', 0,
        preg_match_all('/^(?!\s*--).*\bsp_add_job/m', sqlSinBloquesMAT($f)));
}

seccion('El SP de la historia es la MISMA definicion que la lectura en vivo');

$srcHist = sqlSinBloquesMAT($spHist);
$srcDatos = codigoSinComentariosMAT($CLS . 'ComprasProyectadasDatos.php');

chequear('proveedores del exterior: Z%', true, strpos($srcHist, "COD_PROVEE LIKE 'Z%'") !== false);
chequear('recepciones: RP', true, strpos($srcHist, "TCOMP_IN_S = 'RP'") !== false);
chequear('el importe se prorratea igual que en vivo', true,
    strpos($srcHist, 'O.TOTAL_EXT * M.CANT / NULLIF(T.CANT_OC, 0)') !== false
    && strpos($srcDatos, 'OC.TOTAL_EXT * M.CANT / NULLIF(T.CANT_OC, 0)') !== false);

/* El total de la orden suma TODAS sus recepciones: el rango de anios se
   aplica recien sobre el resultado. */
chequear('el rango de anios va despues del total de cada orden',
    true, strpos($srcHist, 'INTO #TOT') < strpos($srcHist, 'M.FECHA_MOV >= @Desde'));

chequear('una historia vacia no reemplaza la anterior',
    1, preg_match('/IF NOT EXISTS \(SELECT 1 FROM #RES\)\s+THROW/', $srcHist));

seccion('El SP del presupuesto trae lo remoto antes de la transaccion');

$srcPres = sqlSinBloquesMAT($spPres);

/* Asi la transaccion es local: sin MSDTC, y una caida del linked server corta
   antes de tocar nada. */
chequear('ningun [XL-APPS] despues de abrir la transaccion',
    false, strpos($srcPres, '[XL-APPS]', strpos($srcPres, 'BEGIN TRANSACTION')) !== false);

chequear('sin la vista no pisa nada',
    true, strpos($srcPres, 'THROW 50002') < strpos($srcPres, 'BEGIN TRANSACTION'));

/* ========================================================================
   EL CABLEADO EN PHP
   ======================================================================== */

seccion('La proyeccion lee las tablas, no las fuentes');

$srcProv = codigoSinComentariosMAT($CLS . 'Providers/ComprasProyectadasProvider.php');
$srcCtrl = codigoSinComentariosMAT(__DIR__ . '/../cashflow/Controller/ComprasProyectadasController.php');

chequear('el proveedor no llama a la lectura en vivo',
    0, substr_count($srcProv, 'historiaRecepcionesEnVivo'));

chequear('ni el controller', 0, substr_count($srcCtrl, 'historiaRecepcionesEnVivo'));

/* A POWER_BI_CONTROL por conexion propia solo van tieneVista() y
   detalleVersion(): el desglose por rubro, que se pide al abrir el modal. */
chequear('solo dos lecturas abren conexion a POWER_BI_CONTROL',
    2, substr_count($srcDatos, "conectar('power')"));

chequear('la historia se lee de su tabla',
    true, strpos($srcDatos, 'FROM " . self::TABLA_RECEP_HIST') !== false);

chequear('el presupuesto, del resumen',
    true, strpos($srcDatos, 'FROM " . self::TABLA_PRESUP_RESUMEN') !== false);

seccion('Una sola instancia de lecturas por pedido');

/* En getGrilla, que es el pedido que lee todo. getDetalleVersion es otro
   pedido y crea las suyas. */
$getGrilla = substr($srcCtrl, strpos($srcCtrl, "case 'getGrilla':"),
    strpos($srcCtrl, "case 'getDetalleVersion':") - strpos($srcCtrl, "case 'getGrilla':"));

chequear('getGrilla no crea sus propias lecturas',
    0, substr_count($getGrilla, 'new ComprasProyectadasDatos'));

chequear('usa las del proveedor', true, strpos($getGrilla, '$provider->datos()') !== false);

chequear('el proveedor tampoco crea una por calculo',
    1, substr_count($srcProv, 'new ComprasProyectadasDatos'));

seccion('Correr los SP vive aparte de la clase de lectura');

$srcJob = codigoSinComentariosMAT($CLS . 'ComprasProyectadasJob.php');

chequear('ComprasProyectadasJob corre los dos SP',
    true, strpos($srcJob, '"EXEC dbo." . $p[\'sp\']') !== false);

chequear('la clase de lectura no ejecuta ningun SP', 0, preg_match_all('/\bEXEC\b/i', $srcDatos));

/* ========================================================================
   LOS AVISOS, SIN BASE
   ======================================================================== */

seccion('Sin el job, la fila va en cero y el aviso dice DE MENOS');

$sinTablas = estadoMAT(['historia.tabla' => false, 'historia.filas' => 0, 'historia.ok' => null,
                        'presupuesto.tabla' => false, 'presupuesto.filas' => 0,
                        'presupuesto.ok' => null]);

$a = ComprasProyectadasDatos::avisoFaltaJobDe($sinTablas);

chequear('dice que se proyecta DE MENOS', true, strpos($a, 'DE MENOS') !== false);
chequear('nombra el SP de la historia', true, strpos($a, ComprasProyectadasDatos::SP_HISTORIA) !== false);
chequear('y el del presupuesto', true, strpos($a, ComprasProyectadasDatos::SP_PRESUPUESTO) !== false);
chequear('y el script de las tablas', true, strpos($a, 'cashflow_comex_materializado.sql') !== false);

$a = ComprasProyectadasDatos::avisoFaltaJobDe(estadoMAT(['historia.filas' => 0, 'historia.ok' => null]));

chequear('una historia vacia es grave', true, strpos($a, ComprasProyectadasDatos::SP_HISTORIA) !== false);
chequear('y solo nombra lo que falta', false, strpos($a, ComprasProyectadasDatos::SP_PRESUPUESTO) !== false);

/* Sin ninguna version oficial la tabla queda vacia DESPUES de una corrida
   buena: es un estado valido, y la grilla lo muestra mes por mes. */
chequear('un presupuesto vacio despues de una corrida buena NO es grave',
    '', ComprasProyectadasDatos::avisoFaltaJobDe(estadoMAT(['presupuesto.filas' => 0])));

$a = ComprasProyectadasDatos::avisoFaltaJobDe(estadoMAT([
    'presupuesto.ok' => null,
    'presupuesto.ultima' => ['inicio' => '2026-09-24 05:00:00', 'fin' => '2026-09-24 05:00:01',
                             'error' => 'Login failed for user X.', 'usuario' => 'job']]));

chequear('si nunca corrio bien, cuenta por que fallo la ultima',
    true, strpos($a, 'Login failed for user X.') !== false);

chequear('sin poder leer el estado, tambien es DE MENOS', true,
    strpos(ComprasProyectadasDatos::avisoFaltaJobDe(estadoMAT(['error' => 'sin conexion'])), 'DE MENOS') !== false);

chequear('con todo en orden no hay aviso', '', ComprasProyectadasDatos::avisoFaltaJobDe(estadoMAT()));

seccion('El proveedor degrada a cero, con el aviso PRIMERO');

$prov = (new ComprasProyectadasProvider('COMPRAS_PROY'))->conDatos(new DatosFijosMAT($sinTablas));
$series = $prov->series(new Horizonte(28, 12));
$w = $prov->warnings();

chequear('devuelve sus dos series', ['PAGOS_PROYECTADOS', 'NACIONALIZACION_PROYECTADA'], array_keys($series));

$total = 0.0;

foreach ($series as $s) {
    $total += array_sum($s['dias']) + array_sum($s['meses']);
}

chequear('en cero', 0.0, $total);
chequear('el primer aviso es el del job', true, isset($w[0]) && strpos($w[0], 'DE MENOS') !== false);

seccion('Los insumos viejos se avisan sin cambiar ningun numero');

$anios = [2023, 2024, 2025];

chequear('al dia, ningun aviso', [], ComprasProyectadasDatos::avisosInsumosDe(
    estadoMAT(), $anios, '2026-09-24 10:00:00', ['lista' => [], 'error' => null]));

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(), $anios, '2026-09-25 09:00:00', null);

chequear('el presupuesto de hace mas de 24 h se avisa',
    true, isset($v[0]) && strpos($v[0], 'hace 24 horas') !== false);

chequear('el de hace 23 h no', [], ComprasProyectadasDatos::avisosInsumosDe(
    estadoMAT(), $anios, '2026-09-25 07:30:00', null));

$oficiales = ['lista' => [['id' => 12, 'fecha_calculo' => '2026-09-24',
                           'oficial_fecha' => '2026-09-24 11:15:00']], 'error' => null];

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(), $anios, '2026-09-24 12:00:00', $oficiales);

chequear('una oficial marcada despues del calculo se avisa',
    true, isset($v[0]) && strpos($v[0], 'versión 12') !== false);

$oficiales = ['lista' => [['id' => 13, 'fecha_calculo' => '2026-09-25',
                           'oficial_fecha' => null]], 'error' => null];

chequear('una calculada un dia despues, tambien', 1, count(ComprasProyectadasDatos::avisosInsumosDe(
    estadoMAT(), $anios, '2026-09-24 12:00:00', $oficiales)));

$oficiales = ['lista' => [['id' => 11, 'fecha_calculo' => '2026-09-23',
                           'oficial_fecha' => '2026-09-23 17:40:00']], 'error' => null];

chequear('una oficial anterior al calculo no', [], ComprasProyectadasDatos::avisosInsumosDe(
    estadoMAT(), $anios, '2026-09-24 12:00:00', $oficiales));

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(), $anios, '2026-09-24 12:00:00',
    ['lista' => [], 'error' => 'linked server caido']);

chequear('si no se pueden leer las oficiales, se dice',
    true, isset($v[0]) && strpos($v[0], 'linked server caido') !== false);

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(['historia.anio_max' => 2024]),
    $anios, '2026-09-24 12:00:00', null);

chequear('a la historia le falta el ultimo anio (el 1 de enero sin job)',
    true, isset($v[0]) && strpos($v[0], 'le falta el año 2025') !== false);

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(['historia.anio_min' => 2019]),
    range(2016, 2025), '2026-09-24 12:00:00', null);

chequear('la cuota pide mas anios de los guardados',
    true, isset($v[0]) && strpos($v[0], 'arranca en 2019') !== false);

$fallida = ['inicio' => '2026-09-24 09:00:00', 'fin' => '2026-09-24 09:00:01',
            'error' => 'Timeout.', 'usuario' => 'job'];

$v = ComprasProyectadasDatos::avisosInsumosDe(estadoMAT(['presupuesto.ultima' => $fallida]),
    $anios, '2026-09-24 12:00:00', null);

chequear('una corrida que fallo despues de una buena se avisa',
    true, isset($v[0]) && strpos($v[0], 'Timeout.') !== false && strpos($v[0], '24/09 08:30') !== false);

seccion('La pantalla muestra de cuando es cada insumo');

$p = ComprasProyectadasDatos::insumosParaPantalla(estadoMAT(['presupuesto.ultima' => $fallida]));

chequear('Historia al dd/mm hh:mm', '24/09 05:00', $p['historia']['al']);
chequear('Presupuesto al dd/mm hh:mm', '24/09 08:30', $p['presupuesto']['al']);
chequear('con la falla posterior marcada', 'Timeout.', $p['presupuesto']['fallo']);
chequear('y sin falla donde no la hay', null, $p['historia']['fallo']);

/* ========================================================================
   CONTRA LA BASE, SOLO LECTURA
   ======================================================================== */

seccion('Contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $d = new ComprasProyectadasDatos;
    $e = $d->estadoInsumos();

    chequear('el estado de los insumos se puede leer', null, $e['error']);

    if (!$e['historia']['tabla'] || $e['historia']['filas'] <= 0) {
        Pruebas::saltear('la historia materializada esta vacia: falta correr '
            . ComprasProyectadasDatos::SP_HISTORIA);
    } else {
        /* LA PRUEBA DE EQUIVALENCIA. Tarda lo que tarda la lectura en vivo
           -entre 30 y 60 s-, y es la unica forma de saber que el SP sigue
           calculando lo mismo que la definicion. */
        $hoy = '2026-06-30';
        $mat = $d->historiaRecepciones(3, $hoy);
        $vivo = $d->historiaRecepcionesEnVivo(3, $hoy);

        $porMes = [];

        foreach ($mat as $m) {
            $porMes[$m['anio'] . '-' . $m['mes']] = $m;
        }

        $faltan = 0;
        $difU = 0.0;
        $difI = 0.0;

        foreach ($vivo as $v) {
            $k = $v['anio'] . '-' . $v['mes'];

            if (!isset($porMes[$k])) {
                $faltan++;

                continue;
            }

            $difU = max($difU, abs($v['unidades'] - $porMes[$k]['unidades']));
            $difI = max($difI, abs($v['importe_usd'] - $porMes[$k]['importe_usd']));
        }

        chequear('2023-2025: los mismos meses que la lectura en vivo', count($vivo), count($mat));
        chequear('ninguno falta', 0, $faltan);
        chequear('las mismas unidades', true, $difU < 0.0001);
        chequear('el mismo importe, al centavo', true, $difI < 0.01);
    }

    if ($e['historia']['ok'] === null || $e['presupuesto']['ok'] === null) {
        Pruebas::saltear('falta correr los dos SP: no se puede medir la pestana con las tablas llenas');
    } else {
        /* LA PESTANA EN MENOS DE 2 SEGUNDOS: series + grilla, que es lo que
           hace getGrilla, con un proveedor nuevo y sin nada en cache. */
        $t0 = microtime(true);
        $p = new ComprasProyectadasProvider('COMPRAS_PROY');
        $h = new Horizonte(28, 12);
        $p->series($h);
        $p->grilla($h);
        $ms = round((microtime(true) - $t0) * 1000);

        echo '    (la pestana tardo ' . $ms . ' ms)' . PHP_EOL;

        chequear('la pestana tarda menos de 2 s', true, $ms < 2000);
    }
}
