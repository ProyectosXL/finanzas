<?php
/**
 * Compras proyectadas: el ajuste manual por mes.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN AJUSTE SIGUE APLICANDOSE DESPUES DE QUE CAMBIO EL PRESUPUESTO. El
 *      numero se cargo mirando otra version; si se aplica igual, el mes muestra
 *      un importe que ya no corresponde a nada y nadie se entera. Lo que lo
 *      evita es que ID_VERSION lo resuelva EL SERVIDOR: si viniera del cliente,
 *      mandar el id nuevo alcanzaria para revivir un ajuste viejo.
 *
 *   2. SE GUARDA UN AJUSTE QUE NO APLICA A NADA. Un mes fuera de la ventana, o
 *      un mes cuya temporada no tiene version oficial. El primero se guarda
 *      bien y no cambia ningun numero, asi que quien lo cargo cree haber movido
 *      algo. El segundo no tendria a que atarse: quedaria aplicandose para
 *      siempre sobre una temporada que nadie presupuesto.
 *
 *   3. EL IMPORTE PASA NEGATIVO. Un egreso negativo es un ingreso que nadie
 *      afirmo, y encima compensado en silencio contra el resto de la columna.
 *      CERO, en cambio, es un valor VALIDO: significa "este mes no se compra
 *      nada", que es distinto de no tener ajuste.
 *
 *   4. SE PIERDE EL HISTORIAL. Con un UPDATE en vez de baja + alta, un dedazo
 *      corregido a los cinco minutos y una decision que estuvo vigente tres
 *      semanas son indistinguibles despues del hecho, y un tablero de hace un
 *      mes no se puede reconstruir.
 *
 *   5. LA BAJA Y EL ALTA SE SEPARAN. El indice unico filtrado por VIGENTE
 *      prohibe dos ajustes vigentes del mismo mes. Si fueran dos escrituras
 *      sueltas y fallara la segunda, el mes quedaria SIN ajuste y con el
 *      anterior dado de baja: se perderia un importe sin que nadie lo pida.
 *
 * Las reglas puras se prueban sin base. Lo que necesita SQL Server es SOLO
 * LECTURA y se saltea solo: este archivo NO da de alta ni de baja ningun
 * ajuste contra la base real.
 */

require_once __DIR__ . '/../cashflow/Class/ComprasProyectadas.php';
require_once __DIR__ . '/../cashflow/Class/ComprasProyectadasAjustes.php';

/** El codigo de un archivo, SIN SUS COMENTARIOS */
if (!function_exists('codigoSinComentariosAJ')) {
    function codigoSinComentariosAJ($ruta) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

$VENTANA = ['2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10'];

/* ========================================================================
   LA VALIDACION, PURA
   ======================================================================== */

seccion('El mes tiene que ser un mes');

chequear('un mes bien escrito pasa',
    [], ComprasProyectadasAjustes::validar('2027-08', 100, 'porque si', $VENTANA));

foreach (['2027-8', '27-08', '2027-13', '2027-00', 'agosto', '', '2027-08-15'] as $malo) {
    $e = ComprasProyectadasAjustes::validar($malo, 100, 'x', null);

    chequear('"' . $malo . '" se rechaza', true, count($e) > 0);
}

seccion('Un mes fuera de la ventana no se puede ajustar');

/* Se guardaria bien y no cambiaria ningun importe, asi que quien lo cargo
   creeria haber movido algo. */
$e = ComprasProyectadasAjustes::validar('2026-11', 100, 'x', $VENTANA);

chequear('se rechaza', 1, count($e));
chequear('y el mensaje nombra el mes', true, strpos($e[0], '2026-11') !== false);
chequear('y dice que no cambiaria nada',
    true, strpos($e[0], 'no cambiaría ningún importe') !== false);

/* Sin la lista, no se valida contra la ventana: es lo que permite probar la
   regla del formato sola, y lo que hace que el chequeo viva en el llamador. */
chequear('sin lista de ventana, no se valida contra ella',
    [], ComprasProyectadasAjustes::validar('2026-11', 100, 'x', null));

seccion('El importe');

chequear('CERO es valido: significa que ese mes no se compra nada',
    [], ComprasProyectadasAjustes::validar('2027-08', 0, 'no se compra nada', $VENTANA));

chequear('y tambien el cero como texto',
    [], ComprasProyectadasAjustes::validar('2027-08', '0', 'x', $VENTANA));

$e = ComprasProyectadasAjustes::validar('2027-08', -1, 'x', $VENTANA);

chequear('un negativo se rechaza', 1, count($e));
chequear('y el mensaje explica por que',
    true, strpos($e[0], 'sería un ingreso') !== false);

foreach (['', 'mucho', null, []] as $malo) {
    chequear('un importe no numerico se rechaza',
        true, count(ComprasProyectadasAjustes::validar('2027-08', $malo, 'x', $VENTANA)) > 0);
}

chequear('un decimal pasa',
    [], ComprasProyectadasAjustes::validar('2027-08', 1234.56, 'x', $VENTANA));

seccion('El motivo es obligatorio');

/* MISMO CRITERIO QUE LA EXCLUSION DE CHEQUES: un ajuste reemplaza una cuenta
   que el sistema sabe hacer, y meses despues el motivo es lo unico que explica
   por que ese mes dice otra cosa. */
foreach (['', '   ', "\n"] as $vacio) {
    $e = ComprasProyectadasAjustes::validar('2027-08', 100, $vacio, $VENTANA);

    chequear('un motivo vacio se rechaza', 1, count($e));
    chequear('y el mensaje dice para que sirve',
        true, strpos($e[0], 'explica por qué') !== false);
}

seccion('Los errores se acumulan, no se cortan en el primero');

/* Quien carga el formulario tiene que poder arreglar todo de una vez. */
$e = ComprasProyectadasAjustes::validar('mal', -5, '', $VENTANA);

chequear('tres problemas, tres mensajes', 3, count($e));

/* ========================================================================
   EL CABLEADO
   ======================================================================== */

$clase = __DIR__ . '/../cashflow/Class/ComprasProyectadasAjustes.php';
$datos = __DIR__ . '/../cashflow/Class/ComprasProyectadasDatos.php';
$ctrl = __DIR__ . '/../cashflow/Controller/ComprasProyectadasController.php';
$js = __DIR__ . '/../cashflow/Js/Compras-Proyectadas.js';

$src = codigoSinComentariosAJ($clase);

seccion('La version la resuelve el SERVIDOR');

/* Es lo unico que hace que el descarte funcione. Si el id viniera del cliente,
   mandar el id nuevo alcanzaria para que un numero viejo siguiera aplicandose
   contra un presupuesto que no miro nunca. */
chequear('guardar() no recibe el id de version por parametro',
    0, preg_match_all('/function guardar\([^)]*idVersion/i', $src));

chequear('lo busca en las versiones oficiales',
    true, strpos($src, 'versionesOficiales()') !== false);

chequear('y lo toma de la temporada del mes',
    true, strpos($src, "\$versiones[\$temporada['codigo']]['id_version']") !== false);

chequear('un mes sin version oficial se rechaza con su motivo',
    true, strpos($src, 'no tendría') !== false && strpos($src, 'forma de caducar') !== false);

seccion('Sin bajas fisicas');

chequear('no hay ningun DELETE', 0, preg_match_all('/\bDELETE\b/i', $src));

chequear('la baja marca VIGENTE = 0 y deja FECHA_BAJA',
    2, preg_match_all('/SET VIGENTE = 0, FECHA_BAJA = GETDATE\(\)/i', $src));

chequear('corregir un ajuste inserta uno nuevo',
    1, preg_match_all('/INSERT INTO/i', $src));

/* LA BAJA Y EL ALTA VAN EN LA MISMA TRANSACCION: el indice unico filtrado
   prohibe dos vigentes del mismo mes, asi que separadas pueden dejar el mes
   sin ningun ajuste. */
chequear('las dos van en una transaccion',
    true, strpos($src, 'sqlsrv_begin_transaction') !== false
       && strpos($src, 'sqlsrv_rollback') !== false
       && strpos($src, 'sqlsrv_commit') !== false);

chequear('el historial devuelve tambien los NO vigentes',
    0, preg_match_all('/WHERE\s+VIGENTE\s*=\s*1[^)]*ORDER BY MES/is', $src));

seccion('La escritura vive SOLA, fuera de la clase de lectura');

/* ComprasProyectadasDatos lee tres fuentes que son de otras aplicaciones, y la
   regla de que no escribe ninguna tiene que poder verificarse de un vistazo.
   Con el alta adentro, esa prueba deja de ser posible. */
$srcDatos = codigoSinComentariosAJ($datos);

foreach (['INSERT', 'UPDATE ', 'DELETE'] as $verbo) {
    chequear('ComprasProyectadasDatos sigue sin ' . trim($verbo),
        0, preg_match_all('/\b' . trim($verbo) . '\b/i', $srcDatos));
}

chequear('y la clase de ajustes solo toca SU tabla',
    0, preg_match_all('/(INSERT INTO|UPDATE)\s+(?!.{0,40}TABLA)/is',
        preg_replace('/\s+/', ' ', $src)));

seccion('Los endpoints');

$srcCtrl = codigoSinComentariosAJ($ctrl);

foreach (['guardarAjuste', 'quitarAjuste', 'getHistorialAjustes'] as $accion) {
    chequear('el controller atiende ' . $accion,
        true, strpos($srcCtrl, "case '" . $accion . "'") !== false);
}

/* LA VENTANA LA RESUELVE EL SERVIDOR. El endpoint es alcanzable sin pasar por
   la grilla: si aceptara la lista del cliente, mandar el mes propio alcanzaria
   para saltear la validacion. */
chequear('la ventana se resuelve en el servidor',
    true, strpos($srcCtrl, 'mesesDeLaVentana($h)') !== false);

chequear('y sale del proveedor, no de una lista escrita en el controller',
    true, strpos($srcCtrl, "new ComprasProyectadasProvider('COMPRAS_PROY')") !== false);

seccion('La pantalla');

$srcJs = codigoSinComentariosAJ($js);

chequear('el JS no usa alert() ni confirm() del navegador',
    0, preg_match_all('/(^|[^.\w])(alert|confirm)\s*\(/m', $srcJs));

chequear('la baja pregunta con Notificacion.confirmar',
    true, strpos($srcJs, 'Notificacion.confirmar(') !== false);

chequear('y el guardado valida antes de viajar',
    true, strpos($srcJs, 'Notificacion.campoInvalido') !== false);

/* UN MES SIN VERSION OFICIAL NO OFRECE EL BOTON. El servidor lo rechaza igual;
   esto es para no ofrecer algo que va a fallar. */
chequear('un mes sin version no ofrece el boton',
    true, strpos($srcJs, 'if (!m.version)') !== false);

/* SE RECARGA TODO Y NO SE PARCHEA LA FILA: el ajuste cambia la estimacion, la
   nacionalizacion, los KPIs y los totales del eje. */
chequear('despues de guardar se recarga la grilla entera',
    true, preg_match('/function enviar\(.*?cargar\(\);/s', $srcJs) === 1);

/* ========================================================================
   EL DESCARTE, QUE ES LA REGLA QUE JUSTIFICA GUARDAR LA VERSION
   ======================================================================== */

seccion('El ajuste se descarta cuando cambia la version oficial');

/* Se prueba contra estimar(), que es donde vive la decision: la consulta
   devuelve TODOS los ajustes vigentes, aplicables o no, y la regla se resuelve
   comparando contra la version que el mes tiene HOY. Asi se puede verificar sin
   base, que es lo que importa: en la base real no se puede cambiar una version
   oficial a voluntad para ver que pasa. */
$ventana = ComprasProyectadas::ventana('2026-09', '2027-08-31', 6, 15, 47, 2);

$mov = [];

foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $m) {
    $mov[] = ['anio' => 2025, 'mes' => $m, 'peso' => 100];
}

$cuota = ComprasProyectadas::cuota($mov);

$presu = [
    'INV 27' => ['id_version' => 11, 'fecha_calculo' => '2026-09-23',
                 'nombre' => 'inv', 'fob_usd' => 600.0],
    'VER 27-28' => ['id_version' => 9, 'fecha_calculo' => '2026-09-22',
                    'nombre' => 'ver', 'fob_usd' => 600.0]
];

function filaAJ($r, $mes) {
    foreach ($r['meses'] as $f) {
        if ($f['mes'] === $mes) {
            return $f;
        }
    }

    return null;
}

$conAjuste = ['2027-08' => ['importe_usd' => 444.0, 'id_version' => 9,
                            'motivo' => 'compra puntual', 'usuario' => 'tesoreria']];

$r = ComprasProyectadas::estimar($ventana, $cuota, $presu, [], ['ajustes' => $conAjuste]);

chequear('con la MISMA version, el ajuste se aplica',
    'AJUSTADO', filaAJ($r, '2027-08')['estado']);
chequear('y el importe es el del ajuste', 444.0,
    round(filaAJ($r, '2027-08')['estimacion_usd'], 6));

/* La misma tabla, el mismo ajuste, y solo cambia la version oficial de VER
   27-28: de la 9 a una 12. */
$presuNueva = $presu;
$presuNueva['VER 27-28']['id_version'] = 12;
$presuNueva['VER 27-28']['fecha_calculo'] = '2026-11-05';

$r2 = ComprasProyectadas::estimar($ventana, $cuota, $presuNueva, [], ['ajustes' => $conAjuste]);

chequear('con otra version, el ajuste se descarta',
    'AJUSTE_DESCARTADO', filaAJ($r2, '2027-08')['estado']);

chequear('el ajuste viaja marcado como NO aplicado',
    false, filaAJ($r2, '2027-08')['ajuste']['aplicado']);

/* VUELVE A LA ESTIMACION AUTOMATICA Y NO A CERO: descartar el ajuste no puede
   hacer desaparecer el egreso, solo dejar de pisarlo. */
chequear('y el mes vuelve a la estimacion automatica, no a cero',
    100.0, round(filaAJ($r2, '2027-08')['estimacion_usd'], 6));

chequear('el aviso lo dice',
    true, strpos(implode(' ', $r2['warnings']), 'cambio la version oficial') !== false);

/* El ajuste de OTRA temporada no se toca: el descarte es por version, no
   global. */
$dos = $conAjuste + ['2027-06' => ['importe_usd' => 77.0, 'id_version' => 11,
                                   'motivo' => 'x']];

$r3 = ComprasProyectadas::estimar($ventana, $cuota, $presuNueva, [], ['ajustes' => $dos]);

chequear('el ajuste de la temporada que NO cambio sigue vigente',
    'AJUSTADO', filaAJ($r3, '2027-06')['estado']);
chequear('con su importe', 77.0, round(filaAJ($r3, '2027-06')['estimacion_usd'], 6));

/* ========================================================================
   CONTRA LA BASE, SOLO LECTURA
   ======================================================================== */

seccion('Contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    require_once __DIR__ . '/../cashflow/Class/ComprasProyectadasDatos.php';

    $d = new ComprasProyectadasDatos;

    /* La tabla la crea el script. Si no se corrio, el ajuste esta apagado y
       TODO LO DEMAS funciona igual: es la degradacion mas benigna del modulo,
       porque sin tabla no hay ningun ajuste cargado, que es el mismo estado que
       una instalacion donde nadie ajusto nada. */
    $hay = $d->tieneAjustes();

    chequear('la tabla de ajustes existe', true, $hay);

    if (!$hay) {
        chequear('y si no, el aviso nombra el script',
            true, strpos($d->avisoSinAjustes(), 'cashflow_compras_proyectadas.sql') !== false);
    } else {
        $a = new ComprasProyectadasAjustes($d);

        /* SOLO LECTURA: se lee el historial, no se escribe nada. */
        $historial = $a->historial();

        chequear('el historial se puede leer', true, is_array($historial));

        $vigentes = [];
        $malos = 0;

        foreach ($historial as $h) {
            if ($h['vigente']) {
                $vigentes[] = $h['mes'];
            }

            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $h['mes'])) {
                $malos++;
            }
        }

        chequear('todos los meses guardados tienen formato AAAA-MM', 0, $malos);

        /* EL INDICE UNICO FILTRADO ES LO QUE LO GARANTIZA. Si esta prueba
           fallara, hay dos ajustes vigentes del mismo mes y la consulta de
           ajustes() devolveria uno solo, en silencio. */
        chequear('no hay dos ajustes vigentes del mismo mes',
            count($vigentes), count(array_unique($vigentes)));

        /* Lo que la proyeccion lee tiene que ser exactamente lo vigente. */
        chequear('ajustes() devuelve los vigentes y solo esos',
            count(array_unique($vigentes)), count($d->ajustes()));
    }
}
