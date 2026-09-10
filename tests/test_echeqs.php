<?php
/**
 * Modulo Echeqs.
 *
 * Lo delicado de este modulo no son las consultas: son CRITERIOS. Que cheque
 * entra en el listado de venta cobrada anticipada, cual entra tildado, de que
 * canal es y en que columna del eje cae su neteo. Por eso esos criterios viven
 * en helpers estaticos puros y se verifican aca sin base, al estilo de
 * Saldos::ultimaCarga() y Ventas::armarTendencias().
 *
 * Las secciones que tocan la base se saltean solas si no hay conexion, y no
 * escriben nada: verifican invariantes sobre lo que ya esta cargado.
 */

require_once __DIR__ . '/../cashflow/Class/Echeqs.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';

/** Eje de referencia: 28 dias desde el 6/9/2026 + 12 meses */
$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));

/**
 * Un cheque de prueba, con lo minimo que mira cruzarPrechequeado().
 */
function cheque($id, $codigo, $estado = 'C', $importe = 100, $fecha = '2026-09-10') {
    return [
        'ID_SBA14' => $id,
        'N_CHEQUE' => 900000 + $id,
        'FECHA_CHEQUE' => $fecha,
        'BANCO' => 'FRANCES',
        'IMPORTE' => $importe,
        'CLIENTE' => 'CLIENTE ' . $codigo,
        'COD_CLIENTE' => $codigo,
        'ESTADO' => $estado
    ];
}

/* ================================================================
   El canal de un cheque
   ================================================================ */
seccion('de que canal es un cheque');

// Es lo que permite imputar el neteo al canal que corresponde en vez de
// restarlo solo del total. Sin esto, la fila total de Ventas y su apertura por
// canal dejan de reconciliar en cuanto el neteo deja de ser cero.
chequear('un codigo FR... es una franquicia', 'FRANQUICIAS', Echeqs::canalDeCliente('FRCAST'));
chequear('un codigo L... es un local', 'LOCALES', Echeqs::canalDeCliente('LMDQ01'));
chequear('un mayorista no es de este universo', null, Echeqs::canalDeCliente('MAY001'));
chequear('un codigo vacio tampoco', null, Echeqs::canalDeCliente(''));
chequear('ni null', null, Echeqs::canalDeCliente(null));

// El codigo NO se pasa a mayusculas: la columna de Tango es Latin1_General_BIN
// y forzarlo cambiaria lo que el usuario cargo.
chequear('el codigo se limpia de espacios pero no se altera',
    'FRCAST', Echeqs::normalizarCodigo('  FRCAST '));

/* ================================================================
   El cruce con el maestro: quien aparece y quien entra tildado
   ================================================================ */
seccion('el maestro acota el listado');

$maestro = [
    ['CLIENTE' => 'FRCAST', 'ACTIVO' => 1],
    ['CLIENTE' => 'FRMDG', 'ACTIVO' => 1]
];

$cheques = [
    cheque(1, 'FRCAST'),
    cheque(2, 'FRMDG'),
    cheque(3, 'FROTRO')       // cliente que NO esta en el maestro
];

$filas = Echeqs::cruzarPrechequeado($cheques, $maestro, []);

chequear('solo aparecen los cheques de clientes del maestro', 2, count($filas));

$ids = array_column($filas, 'ID_SBA14');
sort($ids);

chequear('y son los que corresponden', [1, 2], $ids);

// EL CASO QUE NO PUEDE FALLAR NUNCA: con el maestro vacio, el listado es vacio.
// Devolver el universo completo mostraria una pantalla que por defecto tilda los
// cheques de todos los clientes, y esos tildes netearian ventas que nadie
// prepago.
chequear('con el maestro vacio no se muestra NADA',
    [], Echeqs::cruzarPrechequeado($cheques, [], []));
chequear('un maestro que no es una lista tampoco abre la puerta',
    [], Echeqs::cruzarPrechequeado($cheques, null, []));

// Un cliente dado de baja no aparece, AUNQUE tenga una excepcion cargada: la
// excepcion queda en la tabla por si vuelve, pero no lo revive.
$conBaja = [
    ['CLIENTE' => 'FRCAST', 'ACTIVO' => 1],
    ['CLIENTE' => 'FRMDG', 'ACTIVO' => 0]
];

$filas = Echeqs::cruzarPrechequeado($cheques, $conBaja,
    [['ID_SBA14' => 2, 'MARCADO' => 1]]);

chequear('un cliente inhabilitado desaparece del listado', 1, count($filas));
chequear('y el que queda es el activo', 1, $filas[0]['ID_SBA14']);

seccion('la marca por defecto y de donde viene');

$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $maestro, []);

// Estar en el maestro es haber optado por la modalidad: el tilde viene puesto.
chequear('sin excepcion, el cheque entra MARCADO', 1, $filas[0]['MARCADO']);
chequear('y la marca se declara heredada del cliente',
    Echeqs::ORIGEN_CLIENTE, $filas[0]['ORIGEN_MARCA']);
chequear('nadie la toco, asi que no hay usuario', null, $filas[0]['MARCA_USUARIO']);

$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $maestro, [
    ['ID_SBA14' => 1, 'MARCADO' => 0, 'FECHA_UPDATE' => '2026-09-09 10:00:00',
     'USUARIO' => 'silvina']
]);

chequear('con una excepcion en 0, el cheque NO queda marcado', 0, $filas[0]['MARCADO']);
chequear('y la marca se declara puesta a mano',
    Echeqs::ORIGEN_CHEQUE, $filas[0]['ORIGEN_MARCA']);

// Un tilde que el usuario no puso y no sabe de donde salio es peor que no
// tenerlo: por eso viaja quien y cuando.
chequear('con quien la toco', 'silvina', $filas[0]['MARCA_USUARIO']);
chequear('y cuando', '2026-09-09 10:00:00', $filas[0]['MARCA_FECHA']);

// Re-tildar a mano tambien deja rastro: la fila de excepcion no se borra.
$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $maestro, [
    ['ID_SBA14' => 1, 'MARCADO' => 1, 'USUARIO' => 'dan']
]);

chequear('un re-tilde a mano queda marcado', 1, $filas[0]['MARCADO']);
chequear('pero sigue siendo una marca manual',
    Echeqs::ORIGEN_CHEQUE, $filas[0]['ORIGEN_MARCA']);

seccion('un cheque que se muere deja de netear solo');

// Es el motivo por el que las tablas de marcas NO guardan el importe: si el
// cheque se rechaza o se anula, sale del listado sin que nadie tenga que
// acordarse de destildarlo.
$muertos = [
    cheque(1, 'FRCAST', 'C'),
    cheque(2, 'FRCAST', 'A'),
    cheque(3, 'FRCAST', 'R'),
    cheque(4, 'FRCAST', 'X')
];

$marcados = [
    ['ID_SBA14' => 1, 'MARCADO' => 1],
    ['ID_SBA14' => 2, 'MARCADO' => 1],
    ['ID_SBA14' => 3, 'MARCADO' => 1],
    ['ID_SBA14' => 4, 'MARCADO' => 1]
];

$filas = Echeqs::cruzarPrechequeado($muertos, $maestro, $marcados);
$vivos = array_column($filas, 'ESTADO');
sort($vivos);

chequear('un rechazado y un anulado salen del listado aunque esten marcados',
    ['A', 'C'], $vivos);

seccion('los aplicados quedan, y se ven aparte');

// Los pre-chequeados estan tipicamente en 'A': el cheque ya salio de cartera.
// Tienen que entrar al listado -netean- pero el pie los muestra por separado,
// porque ninguna fila del tablero los suma. Ver README-ventas.md.
$resumen = Echeqs::resumenPrechequeado($filas);

chequear('el resumen cuenta los dos cheques vivos', 2, $resumen['cheques']);
chequear('los dos estan marcados', 2, $resumen['marcados']);
chequear('y el importe marcado es la suma', 200.0, $resumen['importe_marcado']);
chequear('el corte por estado separa lo que esta en cartera',
    100.0, $resumen['por_estado']['C']['importe']);
chequear('de lo que ya salio', 100.0, $resumen['por_estado']['A']['importe']);

// El desplegable se arma con los clientes PRESENTES: un filtro que ofrece
// opciones que no devuelven nada se lee como una pantalla rota.
chequear('el filtro ofrece un solo cliente', 1, count($resumen['clientes']));
chequear('y es el que tiene cheques', 'FRCAST', $resumen['clientes'][0]['codigo']);

// Un cheque destildado sigue en el listado -hay que poder volver a tildarlo-
// pero no suma a lo que se netea.
$resumen = Echeqs::resumenPrechequeado(
    Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST'), cheque(2, 'FRCAST')], $maestro,
        [['ID_SBA14' => 2, 'MARCADO' => 0]]));

chequear('un destildado sigue en el listado', 2, $resumen['cheques']);
chequear('pero no se netea', 1, $resumen['marcados']);
chequear('ni suma al importe a netear', 100.0, $resumen['importe_marcado']);
chequear('el total del listado si lo incluye', 200.0, $resumen['importe_total']);

/* ================================================================
   El reparto del neteo contra el eje
   ================================================================ */
require_once __DIR__ . '/../cashflow/Class/Ventas.php';

seccion('la regla dia O mes, nunca las dos');

// La usa el neteo tal cual, sin reimplementarla: es la misma de
// Horizonte::agrupar() que aplica todo el modulo.
$serie = $h->serieVacia();

chequear('un dia del tramo va a su columna diaria',
    ['dias', '2026-09-10'], Horizonte::ubicar($serie, '2026-09-10'));
chequear('un dia posterior al tramo va a la columna de su mes',
    ['meses', '2026-11'], Horizonte::ubicar($serie, '2026-11-15'));
chequear('una fecha posterior al horizonte no tiene columna',
    null, Horizonte::ubicar($serie, '2028-01-01'));

seccion('la fecha teorica de factura');

$ejeDias = array_column($h->dias(), 'fecha');     // 6/9 a 3/10 de 2026
$ejeMeses = array_column($h->meses(), 'clave');   // 2026-09 a 2027-08

/** Una fila de las que devuelve Echeqs::getPrechequeadoTotales() */
function marcado($fecha, $importe, $codigo = 'FRCAST', $estado = 'C') {
    return ['FECHA_CHEQUE' => $fecha, 'COD_CLIENTE' => $codigo,
            'ESTADO' => $estado, 'IMPORTE' => $importe, 'CHEQUES' => 1];
}

// Con dias_prechequeado = 0 el importe cae en la fecha del cheque.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 500)], $ejeDias, $ejeMeses, 0);

chequear('con dias_prechequeado = 0 el neteo cae en la fecha del cheque',
    500.0, $n['dias']['2026-09-10']);
chequear('y en ninguna otra columna', 500.0, $n['total']);

// Con 5, cinco dias antes. El cheque se elige bien adentro del tramo para que
// la fecha teorica siga cayendo dentro: el caso en que se sale es la seccion
// siguiente.
$n = Ventas::repartirNeteo([marcado('2026-09-20', 500)], $ejeDias, $ejeMeses, 5);

chequear('con dias_prechequeado = 5 cae cinco dias antes', 500.0, $n['dias']['2026-09-15']);
chequear('y no queda nada en la fecha del cheque', 0, $n['dias']['2026-09-20']);

// Un cheque posterior al tramo diario va a la columna de su mes.
$n = Ventas::repartirNeteo([marcado('2026-11-20', 300)], $ejeDias, $ejeMeses, 0);

chequear('lo posterior al tramo diario va a la columna del mes',
    300.0, $n['meses']['2026-11']);

seccion('lo que cae antes del eje se avisa, no se pierde');

// EL CASO QUE ESTE HELPER EXISTE PARA CUBRIR. Con dias_prechequeado alto, la
// fecha teorica cae en los primeros dias del mes EN CURSO: Horizonte::ubicar()
// le encontraria la columna del mes, que existe en la serie pero no representa
// ningun dia futuro y la pantalla ni la dibuja. Restar ahi haria desaparecer el
// importe en una columna que nadie ve.
$n = Ventas::repartirNeteo([marcado('2026-09-08', 700)], $ejeDias, $ejeMeses, 10);

chequear('una fecha teorica anterior al inicio del eje no se resta de ninguna columna',
    0.0, $n['total']);
chequear('el importe no se descarta: queda informado', 700.0, $n['fuera_horizonte']);
chequear('y la columna del mes en curso queda intacta', 0, $n['meses']['2026-09']);

$avisos = Ventas::avisosNeteo($n, 10);

chequear('deja un aviso', 1, count($avisos));
chequear('con el monto', true, strpos($avisos[0], '700,00') !== false);
chequear('y con los dias que se restaron', true, strpos($avisos[0], '10 día(s)') !== false);

// Lo mismo del otro lado del eje.
$n = Ventas::repartirNeteo([marcado('2030-01-01', 900)], $ejeDias, $ejeMeses, 0);

chequear('lo posterior al horizonte tampoco se descarta callado',
    900.0, $n['fuera_horizonte']);

seccion('nada se pierde ni se cuenta dos veces');

$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100),      // tramo diario
    marcado('2026-11-20', 200),      // columna mensual
    marcado('2026-09-01', 400)       // antes del eje
], $ejeDias, $ejeMeses, 0);

$repartido = array_sum($n['dias']) + array_sum($n['meses']);

chequear('el total es exactamente lo repartido en columnas', $repartido, $n['total']);
chequear('y lo repartido mas lo descartado es todo lo marcado',
    700.0, $repartido + $n['fuera_horizonte']);

seccion('el neteo se imputa al canal del cliente');

// Es lo que arregla el desvio entre la fila total de cobranza y su apertura por
// canal: mientras el neteo era cero no se notaba, y en cuanto deja de serlo, un
// tablero armado con las filas por canal mostraria cobranza de mas.
$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100, 'FRCAST'),
    marcado('2026-09-11', 250, 'LMDQ01')
], $ejeDias, $ejeMeses, 0);

chequear('la franquicia va a FRANQUICIAS', 100.0, $n['canales']['FRANQUICIAS']['dias']['2026-09-10']);
chequear('el local va a LOCALES', 250.0, $n['canales']['LOCALES']['dias']['2026-09-11']);
chequear('mayoristas queda en cero', 0, array_sum($n['canales']['MAYORISTAS']['dias']));
chequear('nada quedo sin canal', 0, $n['sin_canal']);

$porCanal = 0;

foreach ($n['canales'] as $serieCanal) {
    $porCanal += array_sum($serieCanal['dias']) + array_sum($serieCanal['meses']);
}

chequear('los cuatro canales suman exactamente el total', 350.0, $porCanal);
chequear('que es el total del neteo', $n['total'], $porCanal);

// Un codigo que no mapea a ningun canal se resta del total igual -esa venta se
// prepago- pero se informa, porque el cuadro no cierra por ese importe.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 60, 'MAY001')], $ejeDias, $ejeMeses, 0);

chequear('un codigo sin canal se resta del total igual', 60.0, $n['total']);
chequear('pero se informa', 60.0, $n['sin_canal']);
chequear('y el aviso lo dice', true,
    strpos(implode(' ', Ventas::avisosNeteo($n, 0)), 'no reconcilian') !== false);

seccion('netea lo tildado, sin mirar el estado del cheque');

// Decision de negocio: quien tilda es quien sabe si esa venta esta prepagada.
// Los pre-chequeados estan tipicamente en 'A' -ya aplicados-, justamente porque
// el cheque se recibio y se uso antes de facturar. Filtrar por 'C' dejaria
// afuera casi todo el neteo.
$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100, 'FRCAST', 'C'),
    marcado('2026-09-11', 400, 'FRCAST', 'A')
], $ejeDias, $ejeMeses, 0);

chequear('un cheque ya aplicado netea igual que uno en cartera', 500.0, $n['total']);

// El dato sigue disponible para la pantalla, que muestra los marcados abiertos
// por estado: es donde se decide que tildar.
chequear('lo que ya salio de cartera queda contado aparte', 400.0, $n['fuera_de_cartera']);

// Pero NO genera aviso: los avisos son para lo excepcional. Uno que aparece
// siempre deja de leerse.
chequear('y no genera ningun aviso, porque es el caso normal',
    0, count(Ventas::avisosNeteo($n, 0)));

seccion('el signo del neteo');

// Los importes se devuelven POSITIVOS: quien consume es el que resta
// (VentasProvider::cobranzaNeta y el pie de Js/Ingresos-Ventas.js). Invertirlo
// aca sumaria la cobranza en vez de restarla, y en silencio.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 100)], $ejeDias, $ejeMeses, 0);

chequear('el neteo es positivo', true, $n['dias']['2026-09-10'] > 0);
chequear('sin cheques marcados, el neteo es cero y no rompe nada',
    0, Ventas::repartirNeteo([], $ejeDias, $ejeMeses, 0)['total']);
chequear('y devuelve el eje completo igual',
    count($ejeDias), count(Ventas::repartirNeteo([], $ejeDias, $ejeMeses, 0)['dias']));

/* ================================================================
   El proveedor del tablero
   ================================================================ */
seccion('el proveedor no puede tumbar el tablero');

require_once __DIR__ . '/../cashflow/Class/Providers/EcheqsProvider.php';

/**
 * Con la conexion caida, el proveedor tiene que rendir ceros y un aviso, NO
 * lanzar: un solo modulo con problemas no puede dejar el tablero en blanco. La
 * garantia la da CashflowProvider::series(), que es final.
 */
class EcheqsProviderRoto extends EcheqsProvider {
    protected function calcular($h) {
        throw new Exception('base caida');
    }
}

$roto = new EcheqsProviderRoto('ECHEQS');
$series = $roto->series($h);

chequear('con la base caida no lanza', [], $series);
chequear('deja un aviso', 1, count($roto->warnings()));
chequear('el aviso nombra al modulo', true, strpos($roto->warnings()[0], 'ECHEQS') === 0);
chequear('y explica la consecuencia', true, strpos($roto->warnings()[0], 'en cero') !== false);

// El registro tiene que declarar UNA sola serie, y que salga de cartera. Si
// algun dia aparece una segunda serie alimentada por el pre-chequeado, este
// chequeo es el que lo va a frenar: ver el encabezado de EcheqsProvider.
$meta = CashflowRegistry::meta('ECHEQS');

chequear('el registro declara una sola serie', 1, count($meta['series']));
chequear('y es la de cartera', true, isset($meta['series']['A_COBRAR']));
chequear('la pestana a la que enlaza el tablero', 'echeqs', $meta['tab']);
chequear('y la moneda', 'ARS', $meta['moneda']);

/* ================================================================
   Contra datos reales
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$echeqs = new Echeqs();
$cartera = $echeqs->getEcheqsCartera();

// Todo lo que se muestra tiene que ser futuro: el tablero proyecta, no informa
// el pasado. Y de paso cubre la trampa de FECHA_CHEQ, cuyo default es
// '1800/01/01' y no NULL.
$hoy = date('Y-m-d');
$pasadas = 0;
$sinFecha = 0;

foreach ($cartera as $c) {
    if ($c['FECHA_PAGO'] === null) {
        $sinFecha++;
    } elseif ($c['FECHA_PAGO'] < $hoy) {
        $pasadas++;
    }
}

chequear('ningun cheque en cartera tiene fecha anterior a hoy', 0, $pasadas);
chequear('ni fecha nula: la centinela de 1800 la filtra el WHERE', 0, $sinFecha);

// Las dos consultas de cartera no se pueden desincronizar en silencio: la del
// detalle y la agregada tienen que dar el mismo total.
$sumaDetalle = 0;

foreach ($cartera as $c) {
    $sumaDetalle += $c['IMPORTE'];
}

$sumaAgregado = 0;

foreach ($echeqs->getEcheqsCarteraTotales() as $t) {
    $sumaAgregado += $t['IMPORTE'];
}

chequear('getEcheqsCarteraTotales da el mismo total que getEcheqsCartera',
    round($sumaDetalle, 2), round($sumaAgregado, 2));

seccion('la serie del tablero sale SOLO de cartera');

$hReal = Horizonte::desdeParametros(new Parametros());
$serieReal = CashflowRegistry::instanciar('ECHEQS')->series($hReal);
$totalSerie = array_sum($serieReal['A_COBRAR']['dias'])
    + array_sum($serieReal['A_COBRAR']['meses'])
    + $serieReal['A_COBRAR']['fuera_horizonte']
    + $serieReal['A_COBRAR']['sin_fecha'];

// EL INVARIANTE QUE IMPORTA: tildar cheques en la sub-pestana de venta cobrada
// anticipada no puede mover este numero. Se verifica por construccion -la serie
// tiene que ser exactamente el total de cartera- porque las marcas viven en una
// tabla que esta consulta no toca. Si algun dia esto falla, o la serie dejo de
// salir de cartera o alguien la conecto al maestro.
chequear('la serie A_COBRAR es exactamente el total de cheques en cartera',
    round($sumaDetalle, 2), round($totalSerie, 2));

seccion('venta cobrada anticipada');

if (!$echeqs->tablasCreadas()) {
    Pruebas::saltear('falta correr sql/echeqs_prechequeado.sql');
    return;
}

$clientes = $echeqs->getClientesPrechequeado(true);
$pre = $echeqs->getEcheqsPrechequeado();

if (empty($clientes)) {
    // El maestro vacio no es un error: es el estado inicial, y la pantalla lo
    // dice. Lo que si es un error seria devolver el universo completo.
    chequear('sin clientes en el maestro el listado esta vacio', [], $pre);
    Pruebas::saltear('el maestro no tiene clientes cargados todavia');

    return;
}

$fueraDelMaestro = 0;
$muertos = 0;
$codigos = array_column($clientes, 'CLIENTE');

foreach ($pre as $f) {
    if (!in_array($f['COD_CLIENTE'], $codigos, true)) {
        $fueraDelMaestro++;
    }

    if (in_array($f['ESTADO'], Echeqs::ESTADOS_MUERTOS, true)) {
        $muertos++;
    }
}

chequear('ningun cheque del listado es de un cliente fuera del maestro', 0, $fueraDelMaestro);
chequear('ningun rechazado ni anulado llega a netear', 0, $muertos);

// La vista SQL y el cruce de PHP son las dos caras de la misma regla, y esta es
// la prueba que las ata: si una cambia y la otra no, el total deja de coincidir.
$marcadoPhp = Echeqs::resumenPrechequeado($pre)['importe_marcado'];
$marcadoVista = 0;

foreach ($echeqs->getPrechequeadoTotales() as $t) {
    $marcadoVista += $t['IMPORTE'];
}

chequear('la vista del neteo y el cruce de PHP dan el mismo total marcado',
    round($marcadoPhp, 2), round($marcadoVista, 2));

seccion('el neteo llega a Ventas con la fecha teorica correcta');

$ventas = new Ventas();
$dias = array_column($hReal->dias(), 'fecha');
$meses = array_column($hReal->meses(), 'clave');
$neteo = $ventas->getNeteoPrechequeado($dias, $meses);

chequear('el neteo devuelve todas las claves diarias del eje',
    count($dias), count($neteo['dias']));
chequear('y todas las mensuales', count($meses), count($neteo['meses']));

// EL SIGNO: positivo. Quien consume es el que resta.
$negativos = array_filter($neteo['dias'], function ($v) { return $v < 0; });

chequear('los importes vienen en positivo', 0, count($negativos));

// Nada se pierde: lo que entra a las columnas mas lo que quedo fuera del eje
// tiene que ser todo lo marcado.
$repartido = array_sum($neteo['dias']) + array_sum($neteo['meses']);

chequear('el total del neteo es lo repartido en columnas',
    round($repartido, 2), round($neteo['total'], 2));
chequear('y lo marcado es lo repartido mas lo que cayo fuera del eje',
    round($marcadoVista, 2), round($repartido + $neteo['fuera_horizonte'], 2));

// La apertura por canal tiene que sumar el total, o el tablero no reconcilia
// entre la fila total de cobranza y sus filas por canal.
$porCanal = 0;

foreach ($neteo['canales'] as $serieCanal) {
    $porCanal += array_sum($serieCanal['dias']) + array_sum($serieCanal['meses']);
}

chequear('los cuatro canales suman exactamente el neteo total',
    round($neteo['total'], 2), round($porCanal, 2));
