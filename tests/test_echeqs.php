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

/* Ventas se carga aca arriba y no mas abajo, donde empieza su seccion, porque
   la regla de visibilidad de la sub-pestana y la de descarte del neteo son la
   MISMA funcion: la prueba que las ata necesita las dos clases juntas. */
require_once __DIR__ . '/../cashflow/Class/Ventas.php';

/** Eje de referencia: 28 dias desde el 6/9/2026 + 12 meses */
$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));

/**
 * El dia contra el que corta la sub-pestana en estas pruebas.
 *
 * Es el MISMO dia que abre el eje $h, y no es casualidad: Horizonte arma el
 * tramo diario empezando en hoy, asi que "antes del primer dia del eje" y
 * "antes de hoy" son la misma fecha. La sub-pestana corta contra hoy y el
 * neteo contra el inicio del eje; que coincidan es lo que hace que la pantalla
 * muestre exactamente los cheques que el tablero netea.
 *
 * Va explicito para que las pruebas no dependan del dia en que corren: sin
 * esto, cada cheque de prueba se volveria invisible al pasar su fecha.
 */
define('HOY', '2026-09-06');

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

$filas = Echeqs::cruzarPrechequeado($cheques, $maestro, [], HOY);

chequear('solo aparecen los cheques de clientes del maestro', 2, count($filas));

$ids = array_column($filas, 'ID_SBA14');
sort($ids);

chequear('y son los que corresponden', [1, 2], $ids);

// EL CASO QUE NO PUEDE FALLAR NUNCA: con el maestro vacio, el listado es vacio.
// Devolver el universo completo mostraria una pantalla que por defecto tilda los
// cheques de todos los clientes, y esos tildes netearian ventas que nadie
// prepago.
chequear('con el maestro vacio no se muestra NADA',
    [], Echeqs::cruzarPrechequeado($cheques, [], [], HOY));
chequear('un maestro que no es una lista tampoco abre la puerta',
    [], Echeqs::cruzarPrechequeado($cheques, null, [], HOY));

// Un cliente dado de baja no aparece, AUNQUE tenga una excepcion cargada: la
// excepcion queda en la tabla por si vuelve, pero no lo revive.
$conBaja = [
    ['CLIENTE' => 'FRCAST', 'ACTIVO' => 1],
    ['CLIENTE' => 'FRMDG', 'ACTIVO' => 0]
];

$filas = Echeqs::cruzarPrechequeado($cheques, $conBaja,
    [['ID_SBA14' => 2, 'MARCADO' => 1]], HOY);

chequear('un cliente inhabilitado desaparece del listado', 1, count($filas));
chequear('y el que queda es el activo', 1, $filas[0]['ID_SBA14']);

seccion('la marca por defecto y de donde viene');

$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $maestro, [], HOY);

// Estar en el maestro es haber optado por la modalidad: el tilde viene puesto.
chequear('sin excepcion, el cheque entra MARCADO', 1, $filas[0]['MARCADO']);
chequear('y la marca se declara heredada del cliente',
    Echeqs::ORIGEN_CLIENTE, $filas[0]['ORIGEN_MARCA']);
chequear('nadie la toco, asi que no hay usuario', null, $filas[0]['MARCA_USUARIO']);

$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $maestro, [
    ['ID_SBA14' => 1, 'MARCADO' => 0, 'FECHA_UPDATE' => '2026-09-09 10:00:00',
     'USUARIO' => 'silvina']
], HOY);

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
], HOY);

chequear('un re-tilde a mano queda marcado', 1, $filas[0]['MARCADO']);
chequear('pero sigue siendo una marca manual',
    Echeqs::ORIGEN_CHEQUE, $filas[0]['ORIGEN_MARCA']);

seccion('lo que ya se cobro no se muestra');

/* LA REGLA ES UNA SOLA FUNCION, Echeqs::ventaYaCobrada(), y la usan los dos
   lados: cruzarPrechequeado() para decidir que cheques muestra la sub-pestana,
   y Ventas::repartirNeteo() para decidir cuales netea. Si fueran dos
   implementaciones, la pantalla podria mostrar un cheque que el tablero no
   netea y el usuario tildaria algo que no mueve nada, sin ninguna pantalla
   donde notarlo. */

chequear('una venta teorica anterior al corte ya esta cobrada',
    true, Echeqs::ventaYaCobrada('2026-09-05', HOY));
chequear('la del dia del corte NO: el corte es el primer dia que cuenta',
    false, Echeqs::ventaYaCobrada('2026-09-06', HOY));
chequear('y una futura tampoco',
    false, Echeqs::ventaYaCobrada('2026-09-20', HOY));

// Un cheque sin fecha no se puede ubicar en ninguna columna, ni de la grilla ni
// del eje del tablero: mostrarlo seria una fila sin una sola celda con importe.
chequear('sin fecha estimada queda afuera', true, Echeqs::ventaYaCobrada(null, HOY));
chequear('una fecha vacia tambien', true, Echeqs::ventaYaCobrada('', HOY));

// El cheque cae el 10 pero el cliente adelanta 10 dias: la venta teorica es el
// 31/8, anterior al corte. El cheque NO se muestra, y es el mismo importe que
// repartirNeteo() descarta.
$adelantados = [['CLIENTE' => 'FRCAST', 'ACTIVO' => 1, 'DIAS_PRECHEQUEADO' => 10]];

$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')], $adelantados, [], HOY);

chequear('el cheque cuya venta teorica quedo en el pasado no aparece', 0, count($filas));

// Y no es la fecha del CHEQUE la que decide: el mismo cheque, con un cliente
// que no adelanta nada, si se ve.
$filas = Echeqs::cruzarPrechequeado([cheque(1, 'FRCAST')],
    [['CLIENTE' => 'FRCAST', 'ACTIVO' => 1, 'DIAS_PRECHEQUEADO' => 0]], [], HOY);

chequear('decide la fecha teorica de venta, no la del cheque', 1, count($filas));

// SALE DE TODOS LADOS, no solo de la tabla: los KPIs del encabezado y el pie
// salen de resumenPrechequeado() sobre estas mismas filas. Filtrar en el JS
// habria dejado la tabla corta y los totales largos, y la pantalla se
// contradeciria a si misma.
$mezcla = [
    cheque(1, 'FRCAST', 'C', 100, '2026-09-20'),   // venta teorica 10/9: entra
    cheque(2, 'FRCAST', 'C', 700, '2026-09-12')    // venta teorica 2/9: no entra
];

$resumen = Echeqs::resumenPrechequeado(
    Echeqs::cruzarPrechequeado($mezcla, $adelantados, [], HOY));

chequear('el KPI de cheques no cuenta los escondidos', 1, $resumen['cheques']);
chequear('ni el de marcados', 1, $resumen['marcados']);
chequear('ni el importe a netear', 100.0, $resumen['importe_marcado']);
chequear('ni el total del listado', 100.0, $resumen['importe_total']);

// EL INVARIANTE QUE ATA LAS DOS PANTALLAS: lo que la sub-pestana muestra es
// exactamente lo que el neteo reparte. Con los mismos cheques y el mismo corte,
// los dos caminos tienen que dar el mismo importe.
$n = Ventas::repartirNeteo([
    ['FECHA_CHEQUE' => '2026-09-20', 'COD_CLIENTE' => 'FRCAST',
     'ESTADO' => 'C', 'IMPORTE' => 100, 'CHEQUES' => 1],
    ['FECHA_CHEQUE' => '2026-09-12', 'COD_CLIENTE' => 'FRCAST',
     'ESTADO' => 'C', 'IMPORTE' => 700, 'CHEQUES' => 1]
], array_column($h->dias(), 'fecha'), array_column($h->meses(), 'clave'),
   ['FRCAST' => 10]);

chequear('el neteo reparte exactamente lo que la pantalla muestra',
    $resumen['importe_marcado'], $n['total']);

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

$filas = Echeqs::cruzarPrechequeado($muertos, $maestro, $marcados, HOY);
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
        [['ID_SBA14' => 2, 'MARCADO' => 0]], HOY));

chequear('un destildado sigue en el listado', 2, $resumen['cheques']);
chequear('pero no se netea', 1, $resumen['marcados']);
chequear('ni suma al importe a netear', 100.0, $resumen['importe_marcado']);
chequear('el total del listado si lo incluye', 200.0, $resumen['importe_total']);

/* ================================================================
   El reparto del neteo contra el eje
   ================================================================ */

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

seccion('la fecha estimada de venta');

$ejeDias = array_column($h->dias(), 'fecha');     // 6/9 a 3/10 de 2026
$ejeMeses = array_column($h->meses(), 'clave');   // 2026-09 a 2027-08

/** Una fila de las que devuelve Echeqs::getPrechequeadoTotales() */
function marcado($fecha, $importe, $codigo = 'FRCAST', $estado = 'C') {
    return ['FECHA_CHEQUE' => $fecha, 'COD_CLIENTE' => $codigo,
            'ESTADO' => $estado, 'IMPORTE' => $importe, 'CHEQUES' => 1];
}

// Un cliente sin dias cargados no desplaza nada: el importe cae en la fecha
// del cheque. NO hay valor global de respaldo.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 500)], $ejeDias, $ejeMeses, []);

chequear('un cliente sin dias netea en la fecha del cheque',
    500.0, $n['dias']['2026-09-10']);
chequear('y en ninguna otra columna', 500.0, $n['total']);

/* LAS DOS FECHAS HACEN DOS COSAS DISTINTAS, Y ESTA ES LA PRUEBA QUE LO FIJA.
   Antes los dias de pre-chequeado CORRIAN el importe cinco columnas a la
   izquierda, porque el neteo se ubicaba por la fecha teorica de venta. Ahora los
   dias no mueven nada: solo deciden si el cheque sobrevive al filtro. El importe
   cae SIEMPRE en la fecha del cheque, que es cuando entra la plata. */
$n = Ventas::repartirNeteo([marcado('2026-09-20', 500)], $ejeDias, $ejeMeses,
    ['FRCAST' => 5]);

chequear('con 5 dias de pre-chequeado el importe NO se corre: cae en la fecha del cheque',
    500.0, $n['dias']['2026-09-20']);
chequear('y la columna de la venta teorica queda vacia', 0, $n['dias']['2026-09-15']);

// El mismo cheque con 5 dias y con 0 cae en la MISMA columna: los dias son del
// filtro, no del reparto.
$conDias = Ventas::repartirNeteo([marcado('2026-09-20', 500)], $ejeDias, $ejeMeses,
    ['FRCAST' => 5]);
$sinDias = Ventas::repartirNeteo([marcado('2026-09-20', 500)], $ejeDias, $ejeMeses, []);

chequear('los dias del cliente no cambian en que columna cae el importe',
    $sinDias['dias'], $conDias['dias']);

// Un cheque posterior al tramo diario va a la columna de su mes.
$n = Ventas::repartirNeteo([marcado('2026-11-20', 300)], $ejeDias, $ejeMeses, []);

chequear('lo posterior al tramo diario va a la columna del mes',
    300.0, $n['meses']['2026-11']);

seccion('lo que cae antes del eje se descarta callado');

// EL CASO QUE ESTE HELPER EXISTE PARA CUBRIR. Con muchos dias de
// pre-chequeado, la fecha estimada cae en los primeros dias del mes EN CURSO:
// Horizonte::ubicar() le encontraria la columna del mes, que existe en la serie
// pero no representa ningun dia futuro y la pantalla ni la dibuja. Restar ahi
// haria desaparecer el importe en una columna que nadie ve.
//
// Y ADEMAS SE DESCARTA EN SILENCIO, que es la excepcion deliberada a la regla
// del modulo: esa venta ya se facturo y ya se cobro, asi que no esta en la
// cobranza proyectada y no hay nada de donde restarla. No es plata que al
// tablero le falte mostrar, es plata que al tablero no le toca.
//
// OJO CON ESTE CASO: la fecha del CHEQUE -08/09- si tiene columna en el eje. Lo
// que lo descarta es la teorica -29/08-, que es la que decide. Si algun dia el
// filtro se moviera a la fecha del cheque, esta prueba lo agarra.
$n = Ventas::repartirNeteo([marcado('2026-09-08', 700)], $ejeDias, $ejeMeses,
    ['FRCAST' => 10]);

chequear('una fecha estimada anterior al inicio del eje no se resta de ninguna columna',
    0.0, $n['total']);
chequear('aunque la fecha del cheque si tenga columna', true,
    array_key_exists('2026-09-08', $n['dias']));
chequear('y la columna del mes en curso queda intacta', 0, $n['meses']['2026-09']);
chequear('no se cuenta como fuera_horizonte: no es plata que falte mostrar',
    0, $n['fuera_horizonte']);
chequear('y no deja ningun aviso', 0, count(Ventas::avisosNeteo($n)));

seccion('lo que cae DESPUES del eje si se informa');

/* ES EL CASO QUE NACIO CON EL CAMBIO DE CRITERIO, y no es el mismo que el de
   arriba. Antes, si el importe se ubicaba mas alla del ultimo mes, la venta
   teorica tambien estaba afuera: su cobranza proyectada no estaba en el cuadro,
   asi que no habia columna que netear y se descartaba callado.

   Ubicando por la fecha del cheque eso deja de valer: la fecha del cheque es
   POSTERIOR O IGUAL a la teorica, asi que la venta puede estar ADENTRO del eje
   -con su cobranza proyectada dibujada- y el cheque caer AFUERA. Ahi el cuadro
   muestra una cobranza que este importe deberia restar: es plata que el tablero
   deberia mostrar y no muestra, que es lo que 'fuera_horizonte' significa en
   este modulo. Ver README-cashflow.md. */
$n = Ventas::repartirNeteo([marcado('2030-01-01', 900)], $ejeDias, $ejeMeses, []);

chequear('lo posterior al horizonte no se resta de ninguna columna', 0.0, $n['total']);
chequear('pero se informa en fuera_horizonte', 900.0, $n['fuera_horizonte']);
chequear('y deja aviso', 1, count(Ventas::avisosNeteo($n)));
chequear('que dice que la cobranza del cuadro esta de mas', true,
    strpos(implode(' ', Ventas::avisosNeteo($n)), 'está de más') !== false);

// El caso exacto: venta teorica ADENTRO del eje, cheque AFUERA. Con 60 dias -el
// mayor del maestro real-, un cheque del 15/10/2027 tiene su venta en el
// 16/08/2027, que cae en el ultimo mes del eje.
$n = Ventas::repartirNeteo([marcado('2027-10-15', 1200)], $ejeDias, $ejeMeses,
    ['FRCAST' => 60]);

chequear('la venta teorica cae adentro del eje', ['meses', '2027-08'],
    Horizonte::ubicar(['dias' => array_fill_keys($ejeDias, 0),
                       'meses' => array_fill_keys($ejeMeses, 0)], '2027-08-16'));
chequear('pero el cheque vence afuera y no netea nada', 0.0, $n['total']);
chequear('asi que ese importe se informa', 1200.0, $n['fuera_horizonte']);

// El aviso SOLO aparece cuando hay algo que avisar: un aviso permanente tapa a
// los que piden hacer algo. Medido sobre la cartera real al hacer el cambio,
// este importe da 0,00.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 100)], $ejeDias, $ejeMeses, []);

chequear('sin nada fuera del eje no hay aviso', 0, count(Ventas::avisosNeteo($n)));
chequear('y el contador queda en cero', 0, $n['fuera_horizonte']);

seccion('solo se netea lo que cae dentro del eje');

$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100),      // tramo diario
    marcado('2026-11-20', 200),      // columna mensual
    marcado('2026-09-01', 400)       // antes del eje: no entra
], $ejeDias, $ejeMeses, []);

$repartido = array_sum($n['dias']) + array_sum($n['meses']);

chequear('el total es exactamente lo repartido en columnas', $repartido, $n['total']);
chequear('y lo repartido es solo lo que cayo dentro del eje', 300.0, $repartido);
chequear('los 400 de antes del eje no aparecen en ningun total', 300.0, $n['total']);

seccion('el neteo se imputa al canal del cliente');

// Es lo que arregla el desvio entre la fila total de cobranza y su apertura por
// canal: mientras el neteo era cero no se notaba, y en cuanto deja de serlo, un
// tablero armado con las filas por canal mostraria cobranza de mas.
$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100, 'FRCAST'),
    marcado('2026-09-11', 250, 'LMDQ01')
], $ejeDias, $ejeMeses, []);

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
$n = Ventas::repartirNeteo([marcado('2026-09-10', 60, 'MAY001')], $ejeDias, $ejeMeses, []);

chequear('un codigo sin canal se resta del total igual', 60.0, $n['total']);
chequear('pero se informa', 60.0, $n['sin_canal']);
chequear('y el aviso lo dice', true,
    strpos(implode(' ', Ventas::avisosNeteo($n)), 'no reconcilian') !== false);

seccion('netea lo tildado, sin mirar el estado del cheque');

// Decision de negocio: quien tilda es quien sabe si esa venta esta prepagada.
// Los pre-chequeados estan tipicamente en 'A' -ya aplicados-, justamente porque
// el cheque se recibio y se uso antes de facturar. Filtrar por 'C' dejaria
// afuera casi todo el neteo.
$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100, 'FRCAST', 'C'),
    marcado('2026-09-11', 400, 'FRCAST', 'A')
], $ejeDias, $ejeMeses, []);

chequear('un cheque ya aplicado netea igual que uno en cartera', 500.0, $n['total']);

// El dato sigue disponible para la pantalla, que muestra los marcados abiertos
// por estado: es donde se decide que tildar.
chequear('lo que ya salio de cartera queda contado aparte', 400.0, $n['fuera_de_cartera']);

// Pero NO genera aviso: los avisos son para lo excepcional. Uno que aparece
// siempre deja de leerse.
chequear('y no genera ningun aviso, porque es el caso normal',
    0, count(Ventas::avisosNeteo($n)));

/* ================================================================
   LOS DIAS DE PRE-CHEQUEADO SON POR CLIENTE, Y DECIDEN VISIBILIDAD

   Antes eran UN parametro global aplicado a todas las filas. Con un solo
   numero habia que elegir cual de todos los clientes quedaba bien
   calculado. Eso no cambio: cada cliente usa SU plazo.

   LO QUE SI CAMBIO ES PARA QUE SIRVEN. Antes los dias CORRIAN el importe a la
   izquierda, porque el neteo se ubicaba en la fecha teorica de venta. Ahora el
   importe cae en la fecha del cheque y los dias deciden otra cosa: SI ESE
   CHEQUE ENTRA O NO. Un cliente que adelanta mucho tiene ventas teoricas mas
   viejas, y las que ya pasaron salen del cashflow.
   ================================================================ */
seccion('cada cliente aplica sus propios dias');

// FRCAST adelanta 10 dias, LMDQ01 adelanta 3, y FRSIN no tiene nada cargado.
$diasPorCliente = ['FRCAST' => 10, 'LMDQ01' => 3];

$n = Ventas::repartirNeteo([
    marcado('2026-09-20', 100, 'FRCAST'),
    marcado('2026-09-20', 200, 'LMDQ01'),
    marcado('2026-09-20', 300, 'FRSIN')
], $ejeDias, $ejeMeses, $diasPorCliente);

// Tres cheques del mismo dia con tres plazos distintos caen TODOS en la misma
// columna: la del cheque. Los dias no mueven el importe.
chequear('los tres caen en la fecha del cheque, sin importar sus dias',
    600.0, $n['dias']['2026-09-20']);
chequear('y ninguna columna anterior recibe nada', 0.0,
    $n['dias']['2026-09-10'] + $n['dias']['2026-09-17']);
chequear('nada se pierde en el camino', 600.0, $n['total']);

// PARA LO QUE SI SIRVEN LOS DIAS. El mismo cheque, el 09/09, con dos clientes:
// el que adelanta 10 dias tiene su venta el 30/08 -antes del eje, ya cobrada- y
// queda afuera; el que adelanta 3 la tiene el 06/09, que es el primer dia del
// eje, y entra. Los dos cheques caen -o caerian- en la MISMA columna.
$n = Ventas::repartirNeteo([
    marcado('2026-09-09', 100, 'FRCAST'),
    marcado('2026-09-09', 200, 'LMDQ01')
], $ejeDias, $ejeMeses, $diasPorCliente);

chequear('el cliente que adelanta mucho ya cobro esa venta y no netea',
    200.0, $n['total']);
chequear('y lo que netea el otro cae en la fecha del cheque',
    200.0, $n['dias']['2026-09-09']);
chequear('sin contarse como fuera del eje: la venta ya se cobro',
    0, $n['fuera_horizonte']);

seccion('la resolucion de los dias es una sola funcion');

// La pantalla y el neteo tienen que resolver los dias por el MISMO camino: si
// aplicaran plazos distintos, el tablero dejaria de cerrar y no habria ninguna
// pantalla donde se notara.
chequear('un cliente del mapa devuelve sus dias',
    10, Echeqs::diasDeCliente($diasPorCliente, 'FRCAST'));
chequear('uno que no esta en el mapa devuelve cero',
    0, Echeqs::diasDeCliente($diasPorCliente, 'FRSIN'));
chequear('un mapa vacio devuelve cero',
    0, Echeqs::diasDeCliente([], 'FRCAST'));
chequear('un mapa que no es lista tampoco rompe',
    0, Echeqs::diasDeCliente(null, 'FRCAST'));

// Un valor negativo correria la venta HACIA ADELANTE del cheque, que es lo
// contrario de pre-chequear. Se trata como cero.
chequear('un valor negativo se trata como cero',
    0, Echeqs::diasDeCliente(['FRCAST' => -5], 'FRCAST'));

chequear('la fecha estimada resta los dias',
    '2026-09-10', Echeqs::fechaVentaEstimada('2026-09-20', 10));
chequear('con cero dias es la del cheque',
    '2026-09-20', Echeqs::fechaVentaEstimada('2026-09-20', 0));
chequear('cruza el mes sin problema',
    '2026-08-31', Echeqs::fechaVentaEstimada('2026-09-05', 5));
chequear('sin fecha de cheque devuelve null',
    null, Echeqs::fechaVentaEstimada(null, 5));

seccion('la validacion de los dias');

chequear('cero es valido: significa no desplazar', 0, Echeqs::validarDias(0));
chequear('un entero positivo es valido', 45, Echeqs::validarDias(45));
chequear('un numero como texto tambien', 30, Echeqs::validarDias('30'));

chequearLanza('un negativo se rechaza', function () {
    Echeqs::validarDias(-1);
});

chequearLanza('un plazo absurdo se rechaza', function () {
    Echeqs::validarDias(500);
});

chequearLanza('que falte se rechaza: es parte de configurar al cliente', function () {
    Echeqs::validarDias(null);
});

chequearLanza('un texto que no es numero se rechaza', function () {
    Echeqs::validarDias('quince');
});

seccion('el signo del neteo');

// Los importes se devuelven POSITIVOS: quien consume es el que resta
// (VentasProvider::cobranzaNeta y el pie de Js/Ingresos-Ventas.js). Invertirlo
// aca sumaria la cobranza en vez de restarla, y en silencio.
$n = Ventas::repartirNeteo([marcado('2026-09-10', 100)], $ejeDias, $ejeMeses, []);

chequear('el neteo es positivo', true, $n['dias']['2026-09-10'] > 0);
chequear('sin cheques marcados, el neteo es cero y no rompe nada',
    0, Ventas::repartirNeteo([], $ejeDias, $ejeMeses, [])['total']);
chequear('y devuelve el eje completo igual',
    count($ejeDias), count(Ventas::repartirNeteo([], $ejeDias, $ejeMeses, [])['dias']));

/* ================================================================
   EL NETEO ES UNA FILA DEL TABLERO, NO UN DESCUENTO DENTRO DE LA COBRANZA

   Antes VentasProvider devolvia COBRANZA y las cuatro COBRANZA_<CANAL> ya
   netas. Ahora las series vuelven a bruto y el neteo sale por una serie
   propia, NETEO_PRECHEQUEADO, en NEGATIVO: la fila del tablero es de tipo
   INGRESO y el motor suma los ingresos, asi que un negativo resta.

   Invertir mal el signo es el unico error que esta serie puede tener, y es
   invisible: el tablero sumaria el neteo en vez de restarlo y el cuadro
   seguiria dando un numero razonable. Por eso la inversion vive en una
   funcion estatica y por eso estas pruebas existen.
   ================================================================ */
seccion('el signo de la fila del tablero');

require_once __DIR__ . '/../cashflow/Class/Providers/VentasProvider.php';

chequear('lo que Ventas da en positivo, la serie lo da en negativo',
    ['2026-09-10' => -500.0], VentasProvider::enNegativo(['2026-09-10' => 500.0]));

// Una columna sin neteo tiene que mostrarse vacia. -0 no rompe ninguna cuenta
// pero viaja tal cual al JSON y se ve en pantalla.
chequear('el cero queda en cero y no en -0',
    ['2026-09-11' => 0], VentasProvider::enNegativo(['2026-09-11' => 0]));
chequear('y no se cuela un cero negativo', false,
    strpos(json_encode(VentasProvider::enNegativo(['a' => 0])), '-0') !== false);

chequear('un mapa vacio devuelve un mapa vacio', [], VentasProvider::enNegativo([]));

// La serie tiene que llevar el TOTAL, no la rama de un canal: hoy todo el
// neteo es de franquicias, pero eso es un hecho del padron de clientes y no
// una regla del modulo. Si maniana un mayorista entrega cheques adelantados,
// su neteo tiene que entrar en la fila sin que nadie toque codigo.
$n = Ventas::repartirNeteo([
    marcado('2026-09-10', 100, 'FRCAST'),
    marcado('2026-09-11', 250, 'LMDQ01')
], $ejeDias, $ejeMeses, []);

$fila = VentasProvider::enNegativo($n['dias']);

chequear('la fila lleva los dos canales, no solo franquicias',
    -350.0, array_sum($fila));
chequear('y es exactamente el total del neteo, dado vuelta',
    -$n['total'], array_sum($fila) + array_sum(VentasProvider::enNegativo($n['meses'])));

// El registro tiene que declarar la serie, y NO como componente de COBRANZA:
// no es una apertura de la cobranza sino una fila que convive con ella, y
// declararla componente haria que el validador rechace el tablero normal.
$metaVentas = CashflowRegistry::meta('VENTAS');

chequear('el registro declara la serie del neteo', true,
    isset($metaVentas['series']['NETEO_PRECHEQUEADO']));

$componentesDeclarados = [];

foreach ($metaVentas['componentes'] as $hijas) {
    $componentesDeclarados = array_merge($componentesDeclarados, $hijas);
}

chequear('y NO la declara componente de ninguna serie total', false,
    in_array('NETEO_PRECHEQUEADO', $componentesDeclarados, true));
chequear('NETEO_PRECHEQUEADO tampoco tiene componentes propios', false,
    isset($metaVentas['componentes']['NETEO_PRECHEQUEADO']));

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

/* TRES SERIES, Y LAS TRES SALEN DE CARTERA. La sub-pestana de venta cobrada
   anticipada sigue sin aportar ninguna: si algun dia aparece una cuarta serie
   alimentada por el pre-chequeado, este chequeo es el que lo va a frenar. Ver
   el encabezado de EcheqsProvider. */
chequear('el registro declara las tres series de cartera', 3, count($meta['series']));
chequear('la que usa la fila del tablero', true, isset($meta['series']['A_COBRAR']));
chequear('la de los excluidos a mano', true, isset($meta['series']['A_COBRAR_EXCLUIDOS']));
chequear('y el universo', true, isset($meta['series']['A_COBRAR_TODO']));
chequear('la pestana a la que enlaza el tablero', 'echeqs', $meta['tab']);
chequear('y la moneda', 'ARS', $meta['moneda']);

/* EL TOTAL ES A_COBRAR_TODO, NO A_COBRAR, y el registro tiene que decirlo: es
   contra el universo que se mide el doble conteo. Si alguien declarara
   A_COBRAR como total -que es lo que era antes de la exclusion-, el validador
   dejaria activar la fila de excluidos al lado de la de cobrables creyendo que
   son total y parte. */
chequear('el total declarado es el universo', ['A_COBRAR', 'A_COBRAR_EXCLUIDOS'],
    $meta['componentes']['A_COBRAR_TODO']);
chequear('y A_COBRAR no se declara total de nada', false,
    isset($meta['componentes']['A_COBRAR']));

/* UN SOLO CORTE, asi que NO se declaran 'particiones'. Sin declararlas el
   validador toma todas las partes como el mismo corte, que es exactamente lo
   que son: las dos mitades pueden convivir en el tablero. Declarar un corte
   aca seria copiar el caso de Proveedores Locales, que tiene tres. */
chequear('no declara particiones: hay un solo corte', false, isset($meta['particiones']));

/* ================================================================
   EXCLUIR CHEQUES DE CARTERA DEL CASHFLOW

   El tilde saca el importe de la fila del tablero. Lo que no puede pasar nunca
   es que ese importe DESAPAREZCA: tiene que quedar en su propia serie, y las
   dos partes tienen que seguir cerrando contra el universo.

   Las reglas van primero sin base, sobre el reparto del proveedor, que es
   estatico y puro por este motivo. Despues se verifican contra los datos
   reales, en la seccion de la base.
   ================================================================ */
seccion('el motivo de la exclusion es obligatorio');

/* SE VALIDA EN EL BACK y no en la pantalla: el endpoint es alcanzable sin
   pasar por la grilla. Un cheque sacado del cashflow sin motivo no lo explica
   nadie tres meses despues. */
chequearLanza('un motivo vacio se rechaza',
    function() { Echeqs::validarMotivoExclusion(''); });
chequearLanza('y uno de solo espacios tambien -un motivo en blanco no es un motivo-',
    function() { Echeqs::validarMotivoExclusion('    '); });
chequearLanza('null tampoco alcanza',
    function() { Echeqs::validarMotivoExclusion(null); });

chequear('un motivo real se acepta y se limpia',
    'El cliente avisó que no lo cubre',
    Echeqs::validarMotivoExclusion('  El cliente avisó que no lo cubre  '));

// Se recorta al largo de la columna, que es 200. Un motivo mas largo entraria
// truncado por la base y nadie sabria donde se corto.
chequear('se recorta al largo de la columna', 200,
    mb_strlen(Echeqs::validarMotivoExclusion(str_repeat('á', 300))));

// El mensaje tiene que hablar del gesto que se estaba haciendo: "este cheque"
// cuando es uno y "estos cheques" cuando son varios.
chequearLanza('el mensaje de uno habla de un cheque',
    function() { Echeqs::validarMotivoExclusion('', 1); },
    'Poné el motivo por el que este cheque no se va a poder cobrar. Sin motivo, '
    . 'dentro de tres meses nadie va a poder explicar por qué falta ese importe en el '
    . 'disponible.');

seccion('el corte del proveedor: la plata no desaparece');

require_once __DIR__ . '/../cashflow/Class/Providers/EcheqsProvider.php';

/** Una fila agregada de las que devuelve Echeqs::getEcheqsCarteraTotales() */
function carteraTotal($fecha, $importe, $excluido = false) {
    return ['FECHA_PAGO' => $fecha, 'EXCLUIDO' => $excluido, 'IMPORTE' => $importe];
}

/** El total de una serie, incluyendo lo que quedo fuera del eje */
function totalSerie($s) {
    return array_sum($s['dias']) + array_sum($s['meses'])
        + $s['fuera_horizonte'] + $s['sin_fecha'];
}

/* EL REPARTO SE CORRE SOBRE FILAS ARMADAS A MANO. Es estatico y puro para
   esto: los casos que importan -toda la cartera excluida, un excluido con fecha
   fuera del horizonte- hoy no existen en la base y van a existir en cuanto
   alguien tilde. Es el criterio de ProveedoresProvider::seriesDeItem(). */

chequear('un cheque normal va al universo y a la fila del tablero',
    ['A_COBRAR_TODO', 'A_COBRAR'],
    EcheqsProvider::seriesDeItem(carteraTotal('2026-09-10', 100.0)));
chequear('uno excluido va al universo y a la de excluidos',
    ['A_COBRAR_TODO', 'A_COBRAR_EXCLUIDOS'],
    EcheqsProvider::seriesDeItem(carteraTotal('2026-09-10', 100.0, true)));

// SIEMPRE DOS DESTINOS: el universo y UNA de las dos mitades. Nunca las dos
// mitades, que seria contar el importe dos veces adentro del mismo corte.
chequear('siempre va a exactamente dos series', 2,
    count(EcheqsProvider::seriesDeItem(carteraTotal('2026-09-10', 100.0, true))));

$s = EcheqsProvider::repartir($h, [
    carteraTotal('2026-09-10', 1000.0),
    carteraTotal('2026-09-10', 400.0, true),
    carteraTotal('2026-11-15', 2000.0),
    carteraTotal('2026-11-15', 600.0, true)
]);

chequear('la fila del tablero trae solo lo cobrable',
    3000.0, totalSerie($s[EcheqsProvider::SERIE_COBRABLE]));
chequear('lo excluido queda en su propia serie',
    1000.0, totalSerie($s[EcheqsProvider::SERIE_EXCLUIDOS]));
chequear('y el universo es el de siempre',
    4000.0, totalSerie($s[EcheqsProvider::SERIE_TODO]));

/* EL INVARIANTE QUE IMPORTA, y el unico que no puede romperse nunca: el importe
   excluido no desaparece, cambia de serie. Si esto falla, alguien hizo que la
   exclusion RESTE en vez de MOVER, y el cuadro deja de cerrar contra si mismo
   sin que ninguna pantalla lo note. */
chequear('las dos partes cierran contra el universo',
    totalSerie($s[EcheqsProvider::SERIE_TODO]),
    totalSerie($s[EcheqsProvider::SERIE_COBRABLE])
        + totalSerie($s[EcheqsProvider::SERIE_EXCLUIDOS]));

// Y cierra COLUMNA POR COLUMNA, no solo en el total: una exclusion que moviera
// el importe de fecha daria el mismo total y un cuadro distinto.
$porColumna = true;

foreach (['dias', 'meses'] as $rama) {
    foreach ($s[EcheqsProvider::SERIE_TODO][$rama] as $k => $v) {
        if (abs($v - ($s[EcheqsProvider::SERIE_COBRABLE][$rama][$k]
                + $s[EcheqsProvider::SERIE_EXCLUIDOS][$rama][$k])) > 0.001) {
            $porColumna = false;
        }
    }
}

chequear('y cierran columna por columna, no solo en el total', true, $porColumna);

seccion('los casos de borde del reparto');

// SIN NADA EXCLUIDO, la fila del tablero vale lo mismo que el universo. Es el
// estado del dia que se corre el script: el tablero no se mueve ni un peso.
$s = EcheqsProvider::repartir($h, [carteraTotal('2026-09-10', 1000.0)]);

chequear('sin exclusiones, la fila es el universo entero',
    totalSerie($s[EcheqsProvider::SERIE_TODO]),
    totalSerie($s[EcheqsProvider::SERIE_COBRABLE]));
chequear('y la serie de excluidos existe igual, en cero',
    0.0, totalSerie($s[EcheqsProvider::SERIE_EXCLUIDOS]));

/* LA SERIE VACIA TIENE QUE EXISTIR AUNQUE NO HAYA NADA EXCLUIDO. Si no, una
   fila del tablero configurada contra ella se dibujaria como "sin datos" -con
   el icono de que su modulo no devolvio nada- en vez de mostrar un cero limpio,
   que es lo cierto: no hay nada excluido. Es el criterio de
   ProveedoresProvider::repartir(). */
chequear('las tres series existen siempre', 3, count($s));

// TODO EXCLUIDO: la fila del tablero va en cero y el universo no se mueve.
$s = EcheqsProvider::repartir($h, [carteraTotal('2026-09-10', 1000.0, true)]);

chequear('con todo excluido, la fila del tablero va en cero',
    0.0, totalSerie($s[EcheqsProvider::SERIE_COBRABLE]));
chequear('y el universo sigue siendo el mismo',
    1000.0, totalSerie($s[EcheqsProvider::SERIE_TODO]));

/* LO QUE CAE FUERA DEL EJE SE INFORMA UNA SOLA VEZ, en el universo. Contarlo
   tambien en la parte haria que el aviso del tablero saliera repetido diciendo
   dos veces el mismo importe. */
$s = EcheqsProvider::repartir($h, [carteraTotal('2040-01-01', 500.0, true)]);

chequear('lo de fuera del horizonte se informa en el universo',
    500.0, $s[EcheqsProvider::SERIE_TODO]['fuera_horizonte']);
chequear('y NO se repite en la parte',
    0, $s[EcheqsProvider::SERIE_EXCLUIDOS]['fuera_horizonte']);

seccion('lo excluido se informa, aunque no se vea');

/* LOS EXCLUIDOS SE ESCONDEN POR DEFECTO EN LA PANTALLA, asi que el resumen es
   lo unico que hace notar que falta un importe. Y los totales de las tarjetas
   salen del backend: el front no resta nada. */

/** Una fila del payload, ya con sus totales por vista resueltos */
function filaCartera($id, $importe, $excluido = false, $motivo = null) {
    return [
        'ID_SBA14' => $id,
        'IMPORTE' => $importe,
        'EXCLUIDO' => $excluido,
        'MOTIVO_EXCLUSION' => $motivo,
        'total_tramo' => $importe,
        'total_meses' => 0,
        'total_horizonte' => $importe
    ];
}

$r = Echeqs::resumenExcluidos([
    filaCartera(1, 1000.0),
    filaCartera(2, 400.0, true, 'Cliente en concurso'),
    filaCartera(3, 600.0, true, 'Cliente en concurso'),
    filaCartera(4, 250.0, true, 'Judicializado')
]);

chequear('cuenta los cheques excluidos', 3, $r['cheques']);
chequear('y suma su importe', 1250.0, $r['importe']);
chequear('los totales por vista salen de las filas, no del navegador',
    1250.0, $r['total_horizonte']);

// Los motivos se nombran en el aviso, y repetirlos no dice nada nuevo: dos
// cheques con el mismo motivo son un motivo.
chequear('los motivos no se repiten', ['Cliente en concurso', 'Judicializado'],
    $r['motivos']);

chequear('sin nada excluido el resumen es cero', 0,
    Echeqs::resumenExcluidos([filaCartera(1, 1000.0)])['cheques']);
chequear('y una lista vacia tampoco rompe', 0, Echeqs::resumenExcluidos([])['cheques']);

/* LAS TARJETAS MUESTRAN EL NETO. El total con los excluidos adentro diria que
   esa plata entra, que es justamente lo que el tilde niega. */
$netos = Echeqs::totalesNetos(
    ['total_tramo' => 2250.0, 'total_meses' => 0.0, 'total_horizonte' => 2250.0], $r);

chequear('el neto del tramo descuenta lo excluido', 1000.0, $netos['total_tramo']);
chequear('y el del horizonte tambien', 1000.0, $netos['total_horizonte']);

// Sin exclusiones, el neto es el bruto: el dia que se corre el script la
// pantalla muestra exactamente lo que mostraba antes.
$sinNada = Echeqs::totalesNetos(
    ['total_tramo' => 2250.0, 'total_meses' => 0.0, 'total_horizonte' => 2250.0],
    Echeqs::resumenExcluidos([]));

chequear('sin exclusiones el neto es el bruto', 2250.0, $sinNada['total_horizonte']);

seccion('el cableado de la pantalla de exclusion');

/* SE VERIFICA LEYENDO LOS ARCHIVOS, igual que test_tablas_controles.php y por
   el mismo motivo: lo que se rompe en silencio de esta pantalla no es su
   logica sino el CABLEADO. Un id que no coincide entre la pestana y el JS no
   tira error, no avisa: el interruptor se tilda y no pasa nada, y desde la
   pantalla es indistinguible de que no haya nada excluido. */

$tabEcheqs = file_get_contents(__DIR__ . '/../cashflow/Tabs/echeqs.php');
$jsEcheqs = file_get_contents(__DIR__ . '/../cashflow/Js/Ingresos-Echeqs.js');
$ctrlEcheqs = file_get_contents(__DIR__ . '/../cashflow/Controller/EcheqsController.php');

foreach (['verExcluidosEch', 'selTodosEch', 'barraSelEch', 'selResumenEch',
          'btnExcluirSelEch', 'btnIncluirSelEch', 'btnLimpiarSelEch',
          'excluidosEch'] as $id) {
    chequear('la pestana tiene #' . $id, true, strpos($tabEcheqs, 'id="' . $id . '"') !== false);
    chequear('y el JS lo usa', true, strpos($jsEcheqs, "'" . $id . "'") !== false);
}

// La accion del controller y la que pide el JS tienen que ser la misma cadena.
chequear('el JS pide la accion de excluir', true,
    strpos($jsEcheqs, "action=excluirCheques") !== false);
chequear('y el controller la atiende', true,
    strpos($ctrlEcheqs, "case 'excluirCheques':") !== false);

/* LOS EXCLUIDOS ARRANCAN ESCONDIDOS. Si el interruptor naciera tildado, la
   pantalla mostraria por defecto plata que ya se decidio que no entra, y el
   total del pie no coincidiria con la fila del tablero. */
chequear('el interruptor de ver excluidos NO nace tildado', false,
    (bool) preg_match('/id="verExcluidosEch"[^>]*checked/', $tabEcheqs));

/* EL TILDE DE CARTERA NO ES EL DE PRE-CHEQUEADO. Son dos decisiones distintas
   sobre el mismo cheque y cada una tiene su accion: si alguien las uniera, el
   endpoint de una empezaria a mover la tabla de la otra. */
chequear('el controller sigue teniendo la accion de marcar, aparte', true,
    strpos($ctrlEcheqs, "case 'marcarCheques':") !== false);

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

/* Las dos consultas de cartera no se pueden desincronizar en silencio: la del
   detalle y la agregada tienen que dar el mismo total, y tienen que decir lo
   mismo sobre QUE esta excluido. Desde que la agregada abre por EXCLUIDO, las
   dos aplican ese corte y un WHERE distinto en cualquiera de las dos dejaria la
   pantalla mostrando una cosa y el tablero otra. */
$sumaDetalle = 0;
$sumaDetalleExcluida = 0;

foreach ($cartera as $c) {
    $sumaDetalle += $c['IMPORTE'];

    if (!empty($c['EXCLUIDO'])) {
        $sumaDetalleExcluida += $c['IMPORTE'];
    }
}

$sumaAgregado = 0;
$sumaAgregadoExcluida = 0;

foreach ($echeqs->getEcheqsCarteraTotales() as $t) {
    $sumaAgregado += $t['IMPORTE'];

    if (!empty($t['EXCLUIDO'])) {
        $sumaAgregadoExcluida += $t['IMPORTE'];
    }
}

chequear('getEcheqsCarteraTotales da el mismo total que getEcheqsCartera',
    round($sumaDetalle, 2), round($sumaAgregado, 2));
chequear('y las dos coinciden en cuanto esta excluido',
    round($sumaDetalleExcluida, 2), round($sumaAgregadoExcluida, 2));

seccion('la serie del tablero sale SOLO de cartera');

$hReal = Horizonte::desdeParametros(new Parametros());
$serieReal = CashflowRegistry::instanciar('ECHEQS')->series($hReal);

/** El total de una serie real, con lo que quedo fuera del eje incluido */
function totalReal($s) {
    return array_sum($s['dias']) + array_sum($s['meses'])
        + $s['fuera_horizonte'] + $s['sin_fecha'];
}

// EL INVARIANTE QUE IMPORTA: tildar cheques en la sub-pestana de venta cobrada
// anticipada no puede mover estos numeros. Se verifica por construccion -el
// universo tiene que ser exactamente el total de cartera- porque las marcas
// viven en una tabla que esta consulta no toca. Si algun dia esto falla, o la
// serie dejo de salir de cartera o alguien la conecto al maestro.
chequear('A_COBRAR_TODO es exactamente el total de cheques en cartera',
    round($sumaDetalle, 2), round(totalReal($serieReal['A_COBRAR_TODO']), 2));

/* Y ES EL MISMO NUMERO QUE DABA A_COBRAR ANTES DE QUE EXISTIERA LA EXCLUSION.
   El dia que se corre el script no hay nada excluido, asi que el tablero no se
   mueve ni un peso; el dia que alguien tilde, lo que cambia es de que serie
   sale, no cuanta plata hay. Esta prueba es la que fija esa promesa contra los
   datos reales. */
chequear('la fila del tablero es la cartera MENOS lo excluido',
    round($sumaDetalle - $sumaDetalleExcluida, 2),
    round(totalReal($serieReal['A_COBRAR']), 2));
chequear('y lo excluido esta entero en su propia serie',
    round($sumaDetalleExcluida, 2),
    round(totalReal($serieReal['A_COBRAR_EXCLUIDOS']), 2));

/* LA PARTICION CIERRA CONTRA LOS DATOS REALES, que es lo que este circuito no
   puede romper nunca: el importe excluido no desaparece del cuadro, cambia de
   serie. */
chequear('las dos partes suman el universo, con los datos de hoy',
    round(totalReal($serieReal['A_COBRAR_TODO']), 2),
    round(totalReal($serieReal['A_COBRAR'])
        + totalReal($serieReal['A_COBRAR_EXCLUIDOS']), 2));

// Y columna por columna: una exclusion que moviera el importe de fecha daria el
// mismo total y un cuadro distinto.
$cierraPorColumna = true;

foreach (['dias', 'meses'] as $rama) {
    foreach ($serieReal['A_COBRAR_TODO'][$rama] as $k => $v) {
        if (abs($v - ($serieReal['A_COBRAR'][$rama][$k]
                + $serieReal['A_COBRAR_EXCLUIDOS'][$rama][$k])) > 0.001) {
            $cierraPorColumna = false;
        }
    }
}

chequear('y cierran columna por columna', true, $cierraPorColumna);

/* El resumen que alimenta el aviso del tablero tiene que decir lo mismo que la
   serie. Son dos consultas distintas -una agrupa por fecha, la otra por
   motivo- y si dejaran de coincidir, el aviso diria un importe y el cuadro
   mostraria otro. */
chequear('el resumen de exclusiones coincide con la serie',
    round($sumaDetalleExcluida, 2),
    round($echeqs->getExclusionesEnCartera()['importe'], 2));

if ($sumaDetalleExcluida == 0) {
    Pruebas::saltear('hoy no hay ningun cheque excluido: el corte se verifica en cero, '
        . 'que es el estado del dia que se corre el script');
}

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

// LA PANTALLA NO MUESTRA LO QUE YA SE COBRO. Si la fecha estimada de venta
// quedo antes de hoy, esa venta ya se facturo y ya se cobro: el cheque no esta
// en la grilla ni en los KPIs. La regla la aplica cruzarPrechequeado().
$vencidos = 0;

foreach ($pre as $f) {
    if (Echeqs::ventaYaCobrada($f['FECHA_VENTA_EST'])) {
        $vencidos++;
    }
}

chequear('ningun cheque del listado tiene la venta teorica en el pasado', 0, $vencidos);

// LA VISTA SQL Y EL CRUCE DE PHP SIGUEN SIENDO LAS DOS CARAS DE LA MISMA REGLA,
// pero ya no da un igual a secas: la vista es el ORIGEN AUDITABLE y trae todo lo
// tildado, incluido lo que quedo en el pasado; la pantalla aplica encima el
// corte por fecha. Para atarlas hay que aplicarle a la vista el MISMO corte, con
// la MISMA funcion. Si alguna de las dos reglas cambia sola, esto se rompe.
$diasPorCliente = $echeqs->getDiasPrechequeadoPorCliente();
$marcadoPhp = Echeqs::resumenPrechequeado($pre)['importe_marcado'];
$marcadoVista = 0;
$marcadoVistaVigente = 0;

foreach ($echeqs->getPrechequeadoTotales() as $t) {
    $marcadoVista += $t['IMPORTE'];

    $teorica = Echeqs::fechaVentaEstimada(
        $t['FECHA_CHEQUE'], Echeqs::diasDeCliente($diasPorCliente, $t['COD_CLIENTE']));

    if (!Echeqs::ventaYaCobrada($teorica)) {
        $marcadoVistaVigente += $t['IMPORTE'];
    }
}

chequear('la vista y el cruce de PHP dan el mismo total marcado vigente',
    round($marcadoPhp, 2), round($marcadoVistaVigente, 2));

// Y el origen sigue trayendo TODO: la vista no filtra, filtra quien la lee. Es
// lo que permite auditar desde SQL cuanto se tildo, incluido lo que ya se cobro.
chequear('la vista trae al menos lo mismo que la pantalla, y puede traer mas',
    true, round($marcadoVista, 2) >= round($marcadoPhp, 2));

seccion('el neteo llega a Ventas ubicado por la fecha del cheque');

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

// El neteo no puede netear mas de lo marcado. No tiene por que netearlo TODO:
// lo que queda antes del eje se descarta a proposito -es venta ya cobrada- y
// por eso la igualdad es un <=, no un igual.
$repartido = array_sum($neteo['dias']) + array_sum($neteo['meses']);

chequear('el total del neteo es lo repartido en columnas',
    round($repartido, 2), round($neteo['total'], 2));
chequear('y nunca netea mas de lo que esta marcado', true,
    round($repartido, 2) <= round($marcadoVista, 2));

// Lo que queda DESPUES del eje no se descarta callado, y esto lo verifica sobre
// los datos reales: el aviso existe si y solo si hay importe. Medido al hacer el
// cambio de criterio, este importe da 0,00 -el cheque mas lejano vence 166 dias
// antes del borde del eje- asi que hoy no se ve ningun aviso.
chequear('el neteo informa cuanto quedo fuera del horizonte', true,
    array_key_exists('fuera_horizonte', $neteo));
chequear('nunca negativo', true, $neteo['fuera_horizonte'] >= 0);
chequear('y el aviso aparece exactamente cuando hay algo que avisar',
    $neteo['fuera_horizonte'] > 0,
    strpos(implode(' ', Ventas::avisosNeteo($neteo)), 'último mes del cuadro') !== false);

// Lo repartido mas lo que quedo afuera del horizonte tampoco puede superar lo
// marcado: si lo superara, algun importe se estaria contando dos veces.
chequear('lo neteado mas lo que quedo afuera no supera lo marcado', true,
    round($repartido + $neteo['fuera_horizonte'], 2) <= round($marcadoVista, 2));

// La apertura por canal tiene que sumar el total, o el tablero no reconcilia
// entre la fila total de cobranza y sus filas por canal.
$porCanal = 0;

foreach ($neteo['canales'] as $serieCanal) {
    $porCanal += array_sum($serieCanal['dias']) + array_sum($serieCanal['meses']);
}

chequear('los cuatro canales suman exactamente el neteo total',
    round($neteo['total'], 2), round($porCanal, 2));
