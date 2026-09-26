<?php
/**
 * Compras proyectadas: la estimacion mes a mes y los estados de cobertura.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. SE DESCUENTA LO QUE YA ESTABA DESCONTADO. El presupuesto de una version
 *      ya es NETO de las ordenes pendientes al momento de calcularlo: el
 *      stock_proyectado de la app de compras incluye CANT_PEND_OC. Restar un
 *      contenedor cuya orden se emitio ANTES de esa fecha lo cuenta dos veces y
 *      el tablero proyecta de menos. Y como la ventana cruza dos o tres
 *      temporadas, una sola fecha de corte global equivoca a todas menos una.
 *
 *   2. UN EXCESO SE COMPENSA CONTRA OTROS MESES. Si lo cargado supera a lo
 *      proyectado y la resta pasa con signo, ese mes aporta un EGRESO NEGATIVO
 *      -o sea un ingreso que nadie afirmo- que ademas tapa en silencio parte
 *      del egreso de los otros meses de la columna.
 *
 *   3. UN MES SIN VERSION OFICIAL SE VE IGUAL QUE UNO ESTIMADO EN CERO. Los dos
 *      muestran cero, y significan cosas opuestas: uno es "no hay que comprar"
 *      y el otro es "nadie presupuesto esto todavia". Sin el estado y sin el
 *      aviso, el tablero afirma lo primero cuando pasa lo segundo.
 *
 *   4. UN AJUSTE MANUAL SIGUE APLICANDOSE DESPUES DE QUE CAMBIO EL PRESUPUESTO.
 *      El numero se cargo mirando otra version; si se aplica igual, el mes
 *      muestra un importe que ya no corresponde a nada y nadie se entera. Mismo
 *      criterio que Comex::descartaCotizacion() con el override de cotizacion.
 *
 *   5. LO CARGADO DE UN MES DE PAGO SE DESCUENTA DOS VECES. Pasa cuando dos
 *      meses de recepcion comparten mes de pago, que depende de D y de X. Los
 *      dos meses quedarian en cero sin que nada lo explique.
 *
 * TODO LO DE ARRIBA SE PRUEBA SIN BASE, con la grilla armada a mano. En la base
 * real hoy NO SE PUEDE producir ninguno de esos casos: las dos versiones
 * oficiales se calcularon el 22 y el 23/09/2026 y la orden de compra mas nueva
 * cargada en Comex es del 13/08/2026, asi que el descuento da cero. Que de cero
 * por el motivo correcto es justamente lo que estas pruebas fijan.
 *
 * Lo que necesita SQL Server va al final y se saltea solo.
 */

require_once __DIR__ . '/../Class/ComprasProyectadas.php';
require_once __DIR__ . '/../Class/ComprasProyectadasDatos.php';

/* ========================================================================
   ESCENARIO BASE
   ======================================================================== */

/**
 * Ventana de seis meses que cruza las dos temporadas, con los parametros
 * reales: D = 15, X = 47, Y = 2, eje hasta el 31/08/2027. El ultimo mes es
 * 2027-10, derivado del pago.
 *
 *   recepcion   temporada    mes de pago
 *   2027-05     INV 27       2027-03
 *   2027-06     INV 27       2027-04
 *   2027-07     INV 27       2027-05
 *   2027-08     VER 27-28    2027-06
 *   2027-09     VER 27-28    2027-07
 *   2027-10     VER 27-28    2027-08
 *
 * EL MES DE PAGO NO ES EL MES DE RECEPCION, y en estas pruebas importa: un
 * contenedor que descuenta en el mes de recepcion 2027-07 se carga en el mes de
 * pago 2027-05, que es donde lo ubica su FECHA_EST_PAGO.
 */
function ventanaCP($M = 6) {
    return ComprasProyectadas::ventana('2026-09', '2027-08-31', $M, 15, 47, 2);
}

/** Cuota pareja: cada mes de cada temporada se lleva un sexto. */
function cuotaParejaCP() {
    $mov = [];

    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $mes) {
        $mov[] = ['anio' => 2025, 'mes' => $mes, 'peso' => 100];
    }

    return ComprasProyectadas::cuota($mov);
}

/**
 * Dos versiones oficiales con FOB de 600 cada una: con la cuota pareja, cada
 * mes proyecta exactamente 100. Los numeros son feos a proposito: que la cuenta
 * se pueda hacer de cabeza es lo que hace que una falla se lea sola.
 */
function presupuestosCP($fechaVer = '2026-09-22', $fechaInv = '2026-09-23') {
    return [
        'INV 27' => ['id_version' => 11, 'fecha_calculo' => $fechaInv,
                     'nombre' => 'invierno', 'fob_usd' => 600.0],
        'VER 27-28' => ['id_version' => 9, 'fecha_calculo' => $fechaVer,
                        'nombre' => 'verano', 'fob_usd' => 600.0]
    ];
}

/** Un contenedor cargado, con lo minimo que la cuenta necesita */
function contenedorCP($mesPago, $emision, $pendiente, $id = 1) {
    return ['id' => $id, 'contenedor' => 'CONT' . $id, 'mes_pago' => $mesPago,
            'fec_emisio' => $emision, 'pendiente_usd' => $pendiente];
}

/** La fila de un mes dentro del resultado */
function mesCP($r, $mes) {
    foreach ($r['meses'] as $f) {
        if ($f['mes'] === $mes) {
            return $f;
        }
    }

    return null;
}

/** Si algun aviso DEL TABLERO contiene un texto */
function avisaCP($r, $texto) {
    foreach ($r['warnings'] as $w) {
        if (strpos($w, $texto) !== false) {
            return true;
        }
    }

    return false;
}

/** Si alguna nota de reconciliacion contiene un texto */
function notaCP($r, $texto) {
    foreach ($r['notas'] as $n) {
        if (strpos($n, $texto) !== false) {
            return true;
        }
    }

    return false;
}

/* ========================================================================
   LO PROYECTADO
   ======================================================================== */

seccion('Lo proyectado es la cuota sobre el FOB de SU temporada');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(), []);

chequear('la ventana devuelve seis meses', 6, count($r['meses']));

chequear('julio proyecta un sexto del invierno', 100.0,
    round(mesCP($r, '2027-07')['proyectado_usd'], 6));

chequear('agosto proyecta un sexto del verano', 100.0,
    round(mesCP($r, '2027-08')['proyectado_usd'], 6));

chequear('cada mes nombra la version de SU temporada',
    [11, 11, 11, 9, 9, 9], array_column(array_column($r['meses'], 'version'), 'id_version'));

chequear('y sin cargado, la estimacion es lo proyectado', 600.0,
    round($r['totales']['estimacion_usd'], 6));

chequear('los seis quedan ESTIMADO',
    array_fill(0, 6, 'ESTIMADO'), array_column($r['meses'], 'estado'));

/* ========================================================================
   EL DESCUENTO, TEMPORADA POR TEMPORADA
   ======================================================================== */

seccion('Cada temporada descuenta contra la fecha de SU version');

/* Un contenedor en el mes de pago de JULIO (2027-05), emitido el 01/10/2026:
   despues de la fecha de calculo del invierno (23/09/2026), asi que descuenta. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 40.0)]);

chequear('el contenedor descuenta en julio', 40.0,
    round(mesCP($r, '2027-07')['cargado_usd'], 6));

chequear('y la estimacion baja a 60', 60.0,
    round(mesCP($r, '2027-07')['estimacion_usd'], 6));

chequear('los otros meses no se enteran', 100.0,
    round(mesCP($r, '2027-08')['estimacion_usd'], 6));

/* EL MISMO contenedor, emitido ANTES de la fecha de calculo: ya esta adentro
   del presupuesto, asi que NO descuenta. Es el caso 1 del encabezado. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-08-13', 40.0)]);

chequear('emitido ANTES de la fecha de calculo, no descuenta', 0.0,
    round(mesCP($r, '2027-07')['cargado_usd'], 6));

chequear('y la estimacion queda entera', 100.0,
    round(mesCP($r, '2027-07')['estimacion_usd'], 6));

/* PERO SE INFORMA. "Ya comprado" en cero con un contenedor real en ese mes se
   lee como que el cashflow no lo vio: la fila dice cuanto no desconto y por
   que, y una nota lo resume. Informativo: no entra en ninguna cuenta. */
chequear('la fila informa lo que no desconto', 40.0,
    round(mesCP($r, '2027-07')['previos_usd'], 6));

chequear('con el contenedor y el corte que lo dejo afuera', '2026-09-23',
    mesCP($r, '2027-07')['previos'][0]['corte']);

$nota = implode(' ', $r['notas']);

chequear('una nota dice que es anterior al presupuesto', true,
    strpos($nota, 'anterior al calculo del presupuesto oficial') !== false);

chequear('y cuanto es', true, strpos($nota, '1 contenedor por U$S 40,00') !== false);

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 40.0)]);

chequear('uno que SI descuenta no figura como previo', 0.0,
    round(mesCP($r, '2027-07')['previos_usd'], 6));

/* EL BORDE: emitido EL MISMO DIA que se calculo la version. El corte es
   estrictamente posterior -la version vio lo de ese dia- asi que no descuenta. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-09-23', 40.0)]);

chequear('emitido el mismo dia del calculo, no descuenta', 0.0,
    round(mesCP($r, '2027-07')['cargado_usd'], 6));

seccion('Dos temporadas con fechas de calculo DISTINTAS');

/* EL CASO QUE JUSTIFICA QUE EL CORTE SEA POR TEMPORADA. Dos contenedores
   emitidos el 15/10/2026, uno en el mes de pago de julio (INV 27, calculada el
   30/11/2026) y otro en el de agosto (VER 27-28, calculada el 22/09/2026).
   Con el corte de SU temporada: el de julio NO descuenta -su version es
   posterior a la orden- y el de agosto SI.
   Con una fecha de corte global, los dos harian lo mismo y uno estaria mal. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(),
    presupuestosCP('2026-09-22', '2026-11-30'),
    [contenedorCP('2027-05', '2026-10-15', 40.0, 1),
     contenedorCP('2027-06', '2026-10-15', 40.0, 2)]);

chequear('el mes de la temporada con version POSTERIOR no descuenta', 0.0,
    round(mesCP($r, '2027-07')['cargado_usd'], 6));

chequear('el de la temporada con version ANTERIOR si descuenta', 40.0,
    round(mesCP($r, '2027-08')['cargado_usd'], 6));

chequear('y las estimaciones quedan distintas',
    [100.0, 60.0], [round(mesCP($r, '2027-07')['estimacion_usd'], 6),
                    round(mesCP($r, '2027-08')['estimacion_usd'], 6)]);

/* ========================================================================
   EL EXCESO
   ======================================================================== */

seccion('Un exceso no se compensa: la estimacion nunca es negativa');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 250.0)]);

$julio = mesCP($r, '2027-07');

chequear('la estimacion es CERO y no -150', 0.0, round($julio['estimacion_usd'], 6));
chequear('el exceso se informa aparte', 150.0, round($julio['exceso_usd'], 6));
chequear('y el mes queda CUBIERTO', 'CUBIERTO', $julio['estado']);

chequear('los otros meses NO se ven afectados por el exceso',
    [100.0, 100.0], [round(mesCP($r, '2027-08')['estimacion_usd'], 6),
                     round(mesCP($r, '2027-09')['estimacion_usd'], 6)]);

chequear('el total de la ventana es 500 y no 350', 500.0,
    round($r['totales']['estimacion_usd'], 6));

chequear('y el exceso se avisa', true, avisaCP($r, 'supera a lo proyectado'));

seccion('Lo cargado justo alcanza');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 100.0)]);

chequear('estimacion cero', 0.0, round(mesCP($r, '2027-07')['estimacion_usd'], 6));
chequear('sin exceso', 0.0, round(mesCP($r, '2027-07')['exceso_usd'], 6));
chequear('y CUBIERTO igual', 'CUBIERTO', mesCP($r, '2027-07')['estado']);

/* ========================================================================
   LO CARGADO SE CONSUME UNA SOLA VEZ
   ======================================================================== */

seccion('Dos meses de recepcion que comparten mes de pago');

/* Con D = 28 y X = 58, dos meses de recepcion caen en el mismo mes de pago.
   Lo cargado de ese mes tiene que alcanzar para los dos juntos, no para cada
   uno: si cada mes restara el total, los dos irian a cero. */
$vChoque = ComprasProyectadas::ventana('2027-01', '2027-12-31', 12, 28, 58, 2);
$compartido = null;

foreach (ComprasProyectadas::porMesDePago($vChoque) as $mesPago => $recepciones) {
    if (count($recepciones) > 1) {
        $compartido = ['pago' => $mesPago, 'meses' => $recepciones];

        break;
    }
}

chequear('el escenario existe', true, $compartido !== null);

$presuTodas = [];

foreach ($vChoque['meses'] as $m) {
    $presuTodas[$m['temporada']['codigo']] = ['id_version' => 1,
        'fecha_calculo' => '2020-01-01', 'nombre' => 't', 'fob_usd' => 600.0];
}

$r = ComprasProyectadas::estimar($vChoque, cuotaParejaCP(), $presuTodas,
    [contenedorCP($compartido['pago'], '2026-10-01', 150.0)]);

$a = mesCP($r, $compartido['meses'][0]);
$b = mesCP($r, $compartido['meses'][1]);

chequear('el primero ve los 150 disponibles', 150.0, round($a['cargado_usd'], 6));
chequear('pero solo consume lo que su proyeccion permite', 100.0, round($a['consumido_usd'], 6));
chequear('y queda en cero', 0.0, round($a['estimacion_usd'], 6));

chequear('al segundo le queda el resto, no el total otra vez',
    50.0, round($b['cargado_usd'], 6));

chequear('asi que el segundo NO queda en cero', 50.0, round($b['estimacion_usd'], 6));

chequear('entre los dos consumieron 150, no 300',
    150.0, round($a['consumido_usd'] + $b['consumido_usd'], 6));

chequear('y el total de la grilla totaliza lo consumido, no lo disponible',
    150.0, round($r['totales']['cargado_usd'], 6));

/* ========================================================================
   LO CARGADO QUE NO SE PUEDE UBICAR
   ======================================================================== */

seccion('Un contenedor sin fecha estimada de pago no descuenta');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP(null, '2026-10-01', 40.0)]);

chequear('no descuenta en ningun mes', 0.0, round($r['totales']['cargado_usd'], 6));
chequear('la estimacion queda entera', 600.0, round($r['totales']['estimacion_usd'], 6));
chequear('y se avisa', true, avisaCP($r, 'sin fecha estimada de pago'));

seccion('Un contenedor sin orden de compra en Tango no descuenta');

/* Sin FEC_EMISIO no se puede afirmar que la orden se emitio despues del
   presupuesto, y descontarla sin poder fecharla restaria algo que el
   presupuesto quizas ya tenia neto. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', null, 40.0)]);

chequear('no descuenta', 0.0, round($r['totales']['cargado_usd'], 6));
chequear('y se avisa nombrando Tango', true, avisaCP($r, 'no esta en Tango'));

seccion('Un contenedor que se paga fuera de la ventana');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2026-12', '2026-10-01', 40.0)]);

chequear('no descuenta de ningun mes', 0.0, round($r['totales']['cargado_usd'], 6));

/* VA COMO NOTA Y NO COMO AVISO DEL TABLERO, y eso es deliberado: contra la base
   real son 67 de 71 contenedores, o sea que este texto aparece SIEMPRE. Subido
   al tablero junto a los avisos que si importan, ensenia a ignorar el bloque. */
chequear('se informa como nota de reconciliacion', true,
    notaCP($r, 'se pagan fuera de la ventana'));

chequear('y NO como aviso del tablero', false,
    avisaCP($r, 'se pagan fuera de la ventana'));

chequear('un contenedor con pendiente cero se ignora sin avisar',
    [], ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
        [contenedorCP('2027-05', '2026-10-01', 0.0)])['warnings']);

/* ========================================================================
   LOS ESTADOS
   ======================================================================== */

seccion('Un mes cuya temporada no tiene version oficial');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(),
    ['VER 27-28' => presupuestosCP()['VER 27-28']], []);

chequear('queda SIN_PRESUPUESTO', 'SIN_PRESUPUESTO', mesCP($r, '2027-07')['estado']);
chequear('proyecta cero', 0.0, round(mesCP($r, '2027-07')['proyectado_usd'], 6));
chequear('y no inventa una version', null, mesCP($r, '2027-07')['version']);

chequear('el aviso NOMBRA la temporada', true, avisaCP($r, 'INV 27'));
chequear('y dice que se proyecta de MENOS', true, avisaCP($r, 'de MENOS'));

chequear('los meses que SI tienen version siguen estimando', 300.0,
    round($r['totales']['estimacion_usd'], 6));

seccion('Un mes sin historia en la cuota');

/* Cuota sin julio: no hay ni un registro de ese mes calendario. */
$movSinJulio = [];

foreach ([1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12] as $mes) {
    $movSinJulio[] = ['anio' => 2025, 'mes' => $mes, 'peso' => 100];
}

$r = ComprasProyectadas::estimar(ventanaCP(), ComprasProyectadas::cuota($movSinJulio),
    presupuestosCP(), []);

chequear('queda SIN_HISTORIA', 'SIN_HISTORIA', mesCP($r, '2027-07')['estado']);
chequear('su cuota es null y no cero', null, mesCP($r, '2027-07')['cuota_pct']);
chequear('no proyecta nada', 0.0, round(mesCP($r, '2027-07')['proyectado_usd'], 6));
chequear('y se avisa', true, avisaCP($r, 'Sin historia de recepciones'));

seccion('Un mes sin cotizacion en la curva');

/* La curva de futuros llega hasta 2027-08. El pago de septiembre cae en
   2027-07 y el de agosto en 2027-06: los dos estan. El de julio cae en
   2027-05, que en este escenario se saca de la curva. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(), [],
    ['meses_cotizacion' => ['2027-06' => true, '2027-07' => true]]);

chequear('el mes cuyo pago no esta en la curva queda SIN_COTIZACION',
    'SIN_COTIZACION', mesCP($r, '2027-07')['estado']);

chequear('pero la estimacion NO se pierde: se valua con el mes mas cercano',
    100.0, round(mesCP($r, '2027-07')['estimacion_usd'], 6));

chequear('los meses con cotizacion siguen ESTIMADO',
    ['ESTIMADO', 'ESTIMADO'], [mesCP($r, '2027-08')['estado'], mesCP($r, '2027-09')['estado']]);

/* SIN_COTIZACION no gana sobre CUBIERTO: un mes cubierto vale cero, y cero por
   cualquier cotizacion es cero, asi que no hay aproximacion que informar. */
$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 500.0)],
    ['meses_cotizacion' => ['2027-06' => true, '2027-07' => true]]);

chequear('un mes cubierto y sin cotizacion sigue siendo CUBIERTO',
    'CUBIERTO', mesCP($r, '2027-07')['estado']);

chequear('y no arrastra la marca, porque no hay importe que valuar',
    [], mesCP($r, '2027-07')['marcas']);

/* ========================================================================
   EL AJUSTE MANUAL
   ======================================================================== */

seccion('Un ajuste manual reemplaza la estimacion');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(), [],
    ['ajustes' => ['2027-08' => ['importe_usd' => 555.0, 'id_version' => 9,
                                 'usuario' => 'tesoreria', 'fecha' => '2026-09-24']]]);

$ago = mesCP($r, '2027-08');

chequear('el mes queda AJUSTADO', 'AJUSTADO', $ago['estado']);
chequear('la estimacion es la del ajuste', 555.0, round($ago['estimacion_usd'], 6));
chequear('y el ajuste viaja marcado como aplicado', true, $ago['ajuste']['aplicado']);

chequear('lo proyectado se sigue informando, para poder comparar',
    100.0, round($ago['proyectado_usd'], 6));

chequear('los otros meses no cambian',
    [100.0, 100.0], [round(mesCP($r, '2027-07')['estimacion_usd'], 6),
                     round(mesCP($r, '2027-09')['estimacion_usd'], 6)]);

seccion('El ajuste se descarta si cambio la version oficial');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(), [],
    ['ajustes' => ['2027-08' => ['importe_usd' => 555.0, 'id_version' => 7,
                                 'usuario' => 'tesoreria', 'fecha' => '2026-08-01']]]);

$ago = mesCP($r, '2027-08');

chequear('el mes queda AJUSTE_DESCARTADO', 'AJUSTE_DESCARTADO', $ago['estado']);
chequear('el importe NO se aplica', false, $ago['ajuste']['aplicado']);

/* SE VUELVE A LA ESTIMACION AUTOMATICA y no a cero: descartar el ajuste no
   puede hacer desaparecer el egreso, solo dejar de pisarlo. */
chequear('vuelve a la estimacion automatica, no a cero',
    100.0, round($ago['estimacion_usd'], 6));

chequear('y se avisa', true, avisaCP($r, 'Se descartaron 1 ajuste'));

seccion('Un ajuste sobre un mes sin version no resucita el mes');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(),
    ['VER 27-28' => presupuestosCP()['VER 27-28']], [],
    ['ajustes' => ['2027-07' => ['importe_usd' => 999.0, 'id_version' => 11]]]);

chequear('SIN_PRESUPUESTO gana sobre el ajuste',
    'SIN_PRESUPUESTO', mesCP($r, '2027-07')['estado']);

chequear('y el importe del ajuste no entra', 0.0,
    round(mesCP($r, '2027-07')['estimacion_usd'], 6));

seccion('El ajuste gana sobre CUBIERTO y sobre SIN_HISTORIA');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 500.0)],
    ['ajustes' => ['2027-07' => ['importe_usd' => 70.0, 'id_version' => 11]]]);

chequear('un mes cubierto con ajuste queda AJUSTADO',
    'AJUSTADO', mesCP($r, '2027-07')['estado']);

chequear('y vale lo que dice el ajuste', 70.0,
    round(mesCP($r, '2027-07')['estimacion_usd'], 6));

/* ========================================================================
   LA NACIONALIZACION
   ======================================================================== */

seccion('La nacionalizacion se deriva del FOB YA DESCONTADO');

$r = ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-05', '2026-10-01', 40.0)], ['nac_pct' => 89.0]);

chequear('sobre la estimacion y no sobre lo proyectado',
    53.4, round(mesCP($r, '2027-07')['nacionalizacion_usd'], 6));

chequear('un mes sin descuento nacionaliza sobre lo proyectado entero',
    89.0, round(mesCP($r, '2027-08')['nacionalizacion_usd'], 6));

chequear('un mes cubierto no nacionaliza nada', 0.0,
    round(mesCP(ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(),
        [contenedorCP('2027-05', '2026-10-01', 500.0)],
        ['nac_pct' => 89.0]), '2027-07')['nacionalizacion_usd'], 6));

/* El ajuste vale 200 y lo proyectado 100, a proposito: con un ajuste de 100 la
   nacionalizacion daria lo mismo por los dos caminos y la prueba no probaria
   nada. Sobre el ajuste da 178; sobre lo proyectado daria 89. */
chequear('un mes ajustado nacionaliza sobre el ajuste y no sobre lo proyectado',
    178.0, round(mesCP(ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(),
        presupuestosCP(), [],
        ['nac_pct' => 89.0,
         'ajustes' => ['2027-08' => ['importe_usd' => 200.0, 'id_version' => 9]]]
    ), '2027-08')['nacionalizacion_usd'], 6));

/* El porcentaje llega por parametro y no escrito en el codigo: es la decision
   de no usar inc_fob, que da 41 % contra el 89 % de los contenedores reales. */
chequear('el porcentaje llega por parametro', 41.0,
    round(mesCP(ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), presupuestosCP(), [],
        ['nac_pct' => 41.0]), '2027-05')['nacionalizacion_usd'], 6));

/* ========================================================================
   LOS BORDES
   ======================================================================== */

seccion('Los bordes de la cuenta');

$vacia = ComprasProyectadas::estimar(['ultimo' => null, 'meses' => []],
    cuotaParejaCP(), presupuestosCP(), []);

chequear('una ventana vacia no rompe', [], $vacia['meses']);
chequear('y sus totales son cero', 0.0, $vacia['totales']['estimacion_usd']);

chequear('sin ningun presupuesto, todos los meses quedan SIN_PRESUPUESTO',
    array_fill(0, 6, 'SIN_PRESUPUESTO'),
    array_column(ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), [], [])['meses'],
                 'estado'));

chequear('y avisa por cada temporada, no por cada mes',
    2, count(ComprasProyectadas::estimar(ventanaCP(), cuotaParejaCP(), [], [])['warnings']));

seccion('Los totales cierran con las filas');

$r = ComprasProyectadas::estimar(ventanaCP(6), cuotaParejaCP(), presupuestosCP(),
    [contenedorCP('2027-03', '2026-10-01', 30.0, 1),
     contenedorCP('2027-05', '2026-10-01', 250.0, 2)], ['nac_pct' => 89.0]);

$sumaEst = 0.0;
$sumaNac = 0.0;

foreach ($r['meses'] as $f) {
    $sumaEst += $f['estimacion_usd'];
    $sumaNac += $f['nacionalizacion_usd'];
}

chequear('el total de estimacion es la suma de las filas',
    round($sumaEst, 6), round($r['totales']['estimacion_usd'], 6));

chequear('el total de nacionalizacion tambien',
    round($sumaNac, 6), round($r['totales']['nacionalizacion_usd'], 6));

chequear('y el conteo por estado suma la cantidad de meses',
    6, array_sum($r['totales']['por_estado']));

seccion('Los siete estados estan declarados y en orden');

chequear('la lista de estados es la del pedido',
    ['SIN_PRESUPUESTO', 'AJUSTE_DESCARTADO', 'AJUSTADO', 'SIN_HISTORIA',
     'CUBIERTO', 'SIN_COTIZACION', 'ESTIMADO'],
    ComprasProyectadas::ESTADOS);

/* ========================================================================
   EL CABLEADO: QUE ESTE MODULO NO ESCRIBA NADA
   ======================================================================== */

seccion('El modulo no escribe en tablas de compras ni de Comercio Exterior');

$fuentes = [
    'ComprasProyectadas.php' => __DIR__ . '/../Class/ComprasProyectadas.php',
    'ComprasProyectadasDatos.php' => __DIR__ . '/../Class/ComprasProyectadasDatos.php'
];

foreach ($fuentes as $nombre => $ruta) {
    /* Se lee SIN COMENTARIOS, por lo mismo que en las pruebas de Comex: estos
       archivos explican en prosa lo que NO hacen, y buscar sobre el texto
       entero daria positivo en la nota que dice que no lo hacen. */
    $src = file_get_contents($ruta);
    $src = preg_replace('#/\*.*?\*/#s', '', $src);
    $src = preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);

    foreach (['INSERT', 'UPDATE ', 'DELETE', 'MERGE', 'DROP', 'TRUNCATE'] as $verbo) {
        chequear($nombre . ' no tiene ' . trim($verbo),
            0, preg_match_all('/\b' . trim($verbo) . '\b/i', $src));
    }
}

$datos = preg_replace('#/\*.*?\*/#s', '',
    file_get_contents(__DIR__ . '/../Class/ComprasProyectadasDatos.php'));

chequear('el presupuesto se lee por la conexion power',
    1, preg_match_all("/conectar\('power'\)/", $datos) > 0 ? 1 : 0);

chequear('y la vista se pregunta antes de nombrarla en una consulta',
    true, strpos($datos, "OBJECT_ID('dbo.\" . self::VISTA_PRESUPUESTO . \"', 'V')") !== false);

chequear('el pendiente sale de la regla de Comex y no se reimplementa',
    true, strpos($datos, 'Comex::saldoPendiente(') !== false);

chequear('lo cargado se ubica por FECHA_EST_PAGO',
    true, strpos($datos, 'A.FECHA_EST_PAGO') !== false);

chequear('la deduplicacion de OC hijas no se perdio',
    true, strpos($datos, 'DUPLICA_GRUPO') !== false);

chequear('la historia de recepciones prorratea el importe',
    true, strpos($datos, 'OC.TOTAL_EXT * M.CANT / NULLIF(T.CANT_OC, 0)') !== false);

/* ========================================================================
   CONTRA LA BASE, SOLO LECTURA
   ======================================================================== */

seccion('Contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $d = new ComprasProyectadasDatos;

    /* La vista se sigue leyendo en vivo para el detalle por rubro. */
    chequear('la vista del presupuesto existe', true, $d->tieneVista());

    /* EL PRESUPUESTO Y LA HISTORIA SE LEEN DE LAS TABLAS MATERIALIZADAS: sin
       una corrida buena de sus SP no hay nada que verificar aca. */
    $estado = $d->estadoInsumos();
    $hayPresupuesto = ($estado['presupuesto']['ok'] !== null);
    $hayHistoria = ($estado['historia']['filas'] > 0);

    if (!$hayPresupuesto) {
        Pruebas::saltear('el presupuesto materializado no tiene ninguna corrida buena: falta correr '
            . ComprasProyectadasDatos::SP_PRESUPUESTO);
    }

    if ($hayPresupuesto) {
        $versiones = $d->versionesOficiales();

        chequear('hay al menos una version oficial vigente', true, count($versiones) > 0);

        $sinCodigo = 0;
        $sinFecha = 0;
        $sinFob = 0;

        foreach ($versiones as $codigo => $v) {
            /* EL CODIGO DE TEMPORADA ES LA CLAVE CONTRA LA APP DE COMPRAS: si
               el formato de la vista dejara de coincidir con el que arma
               ComprasProyectadas::armarTemporada(), ningun mes encontraria su
               presupuesto y todos irian a SIN_PRESUPUESTO en silencio. */
            $t = ComprasProyectadas::temporada(substr($v['desde'], 0, 7));

            if ($t === null || $t['codigo'] !== $codigo) {
                $sinCodigo++;
            }

            if (empty($v['fecha_calculo'])) {
                $sinFecha++;
            }

            if ($v['fob_usd'] <= 0) {
                $sinFob++;
            }
        }

        chequear('el codigo de temporada de la vista coincide con el que arma el modulo',
            0, $sinCodigo);
        chequear('todas las versiones tienen fecha de calculo', 0, $sinFecha);
        chequear('y todas tienen FOB', 0, $sinFob);

        $contraste = $d->contrasteVista();

        chequear('el contraste contra las tablas se puede leer',
            true, isset($contraste['filas_vista']));

        /* La vista tiene hoy un filtro propio que sus tablas no: la diferencia
           TIENE que ser visible desde aca, y este es el control que lo hace. */
        if (isset($contraste['filas_vista'])) {
            chequear('la vista nunca devuelve MAS filas que sus tablas',
                true, $contraste['filas_vista'] <= $contraste['filas_tablas']);
        }
    }

    if (!$hayHistoria) {
        Pruebas::saltear('la historia materializada esta vacia: falta correr '
            . ComprasProyectadasDatos::SP_HISTORIA);
    }

    $historia = $hayHistoria ? $d->historiaRecepciones(3) : [];

    if ($hayHistoria) {
        chequear('la historia de recepciones trae datos', true, count($historia) > 0);

        $mesesRaros = 0;
        $negativos = 0;

        foreach ($historia as $h) {
            if ($h['mes'] < 1 || $h['mes'] > 12) {
                $mesesRaros++;
            }

            if ($h['unidades'] < 0 || $h['importe_usd'] < 0) {
                $negativos++;
            }
        }

        chequear('con meses entre 1 y 12', 0, $mesesRaros);
        chequear('y sin importes ni unidades negativas', 0, $negativos);

        chequear('cubre a lo sumo tres anios calendario',
            true, count(array_unique(array_column($historia, 'anio'))) <= 3);

        $cuotaReal = ComprasProyectadas::cuota(ComprasProyectadasDatos::conPeso($historia));

        foreach (['VER', 'INV'] as $tipo) {
            $suma = 0.0;

            foreach ($cuotaReal[$tipo]['pct'] as $p) {
                $suma += floatval($p);
            }

            chequear('la cuota real de ' . $tipo . ' suma 100', 100.0, round($suma, 6));
        }
    }

    /* EL PADRON TIENE QUE SER EL MISMO QUE EL DE PROVEEDORES EXTERIOR. Si los
       dos se separan, el tablero descontaria un numero que esa pestana no
       muestra, y la diferencia no se podria explicar desde ninguna pantalla. */
    $cargado = $d->cargado();
    $comex = new Comex;
    $padron = $comex->getProveedoresExterior();

    $vivos = [];

    foreach ($padron as $p) {
        if (empty($p['DUPLICA_GRUPO'])) {
            $vivos[] = $p;
        }
    }

    chequear('lo cargado trae la misma cantidad de contenedores que la pestana',
        count($vivos), count($cargado));

    $pendienteCargado = 0.0;

    foreach ($cargado as $c) {
        $pendienteCargado += $c['pendiente_usd'];
    }

    $pendientePestana = 0.0;

    foreach ($vivos as $p) {
        $pendientePestana += floatval($p['PENDIENTE_USD']);
    }

    chequear('y el mismo pendiente en dolares',
        round($pendientePestana, 2), round($pendienteCargado, 2));

    $sinFechaPago = 0;

    foreach ($cargado as $c) {
        if ($c['mes_pago'] === null) {
            $sinFechaPago++;
        }
    }

    chequear('los contenedores sin fecha de pago vienen con mes_pago en null y no en cero',
        true, $sinFechaPago >= 0);

    /* La cuenta entera contra la base, con los parametros reales. No se fija un
       importe -cambia con cada version oficial- sino los invariantes. */
    if ($hayHistoria && $hayPresupuesto) {
        $hoy = date('Y-m-d');
        $v = ComprasProyectadas::ventana(substr($hoy, 0, 7),
            date('Y-m-t', strtotime(substr($hoy, 0, 7) . '-01 +11 month')), 6, 15, 47, 2);

        $r = ComprasProyectadas::estimar($v, $cuotaReal, $d->versionesOficiales(), $cargado,
            ['nac_pct' => 89.0]);

        chequear('la ventana real devuelve seis meses', 6, count($r['meses']));

        $negativas = 0;
        $sinEstado = 0;

        foreach ($r['meses'] as $f) {
            if ($f['estimacion_usd'] < 0) {
                $negativas++;
            }

            if (!in_array($f['estado'], ComprasProyectadas::ESTADOS, true)) {
                $sinEstado++;
            }
        }

        chequear('ninguna estimacion es negativa', 0, $negativas);
        chequear('y todos los meses tienen un estado declarado', 0, $sinEstado);

        chequear('la nacionalizacion es el 89 % de la estimacion',
            round($r['totales']['estimacion_usd'] * 0.89, 4),
            round($r['totales']['nacionalizacion_usd'], 4));
    }
}
