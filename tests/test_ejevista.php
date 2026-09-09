<?php
/**
 * EjeVista: el criterio de las tres vistas.
 *
 * Es el archivo que fija lo que ANTES estaba mal en tres pestanas: la vista de
 * dias mostraba los dias del mes en curso y no los proximos N, un importe del
 * mes en curso se contaba en las dos vistas, la ventana era fija e ignoraba los
 * parametros, y lo que caia afuera se descartaba sin avisar.
 *
 * No toca la base.
 */

require_once __DIR__ . '/../cashflow/Class/EjeVista.php';

/** 3 dias + 3 meses desde el 6/9/2026: eje diario 06, 07, 08 de septiembre */
$h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

/* ================================================================
   El eje y las tres vistas
   ================================================================ */
seccion('las tres vistas y sus columnas');

$eje = EjeVista::eje($h);

chequear('las vistas son siempre tres', ['dias', 'meses', 'completo'],
    array_keys($eje['vistas']));
chequear('la vista de dias tiene las 3 columnas diarias',
    3, count($eje['vistas']['dias']['columnas']));

// La columna del mes en curso SI entra: contiene del 9 al 30 de septiembre, que
// son posteriores al tramo diario.
chequear('la vista de meses tiene las 3 columnas mensuales',
    3, count($eje['vistas']['meses']['columnas']));
chequear('la vista completa tiene las 6',
    6, count($eje['vistas']['completo']['columnas']));

chequear('la primera columna diaria es HOY, no el dia 1 del mes',
    'DIA|2026-09-06', $eje['vistas']['dias']['columnas'][0]);
chequear('y la ultima es hoy + horizonte_dias - 1',
    'DIA|2026-09-08', $eje['vistas']['dias']['columnas'][2]);

// El defecto que tenian las tres pestanas: mostraban los dias del mes en curso,
// del 1 al 31, incluidos los que ya pasaron, mientras el encabezado decia
// "Proximos 28 Dias". Con el eje de Horizonte eso no puede pasar.
$sinDiasViejos = true;

foreach ($eje['vistas']['dias']['columnas'] as $col) {
    if ($col < 'DIA|2026-09-06') {
        $sinDiasViejos = false;
    }
}

chequear('ninguna columna diaria es anterior a hoy', true, $sinDiasViejos);

seccion('el horizonte sale de los parametros, no de una ventana fija');

// Antes eran 12 meses fijos y los dias del mes en curso, sin mirar
// horizonte_dias ni horizonte_meses.
$hLargo = new Horizonte(28, 12, [], new DateTime('2026-09-06'));
$ejeLargo = EjeVista::eje($hLargo);

chequear('28 dias dan 28 columnas diarias',
    28, count($ejeLargo['vistas']['dias']['columnas']));
chequear('el payload informa el horizonte que se uso', 28, $ejeLargo['horizonte_dias']);
chequear('el eje declara los 12 meses', 12, count($ejeLargo['meses']));

// Pero la VISTA de meses muestra 11, no 12: con 28 dias desde el 6/9 el tramo
// diario llega al 3/10, asi que de septiembre solo quedan los dias 1 al 5, que
// ya pasaron. Esa columna no representa ningun dia futuro y no entra en la
// vista. Es la trampa que el tablero enuncia y que las pestanas viejas no
// veian: la vista de meses NO cubre el horizonte completo.
chequear('la vista de meses deja afuera el mes que el tramo se comio',
    11, count($ejeLargo['vistas']['meses']['columnas']));
chequear('y arranca en el mes siguiente al tramo',
    'MES|2026-10', $ejeLargo['vistas']['meses']['columnas'][0]);
chequear('el mes cubierto igual esta en el eje, marcado fuera de secuencia',
    false, $ejeLargo['meses'][0]['en_secuencia']);

// Con el tramo diario arrancando hoy, la columna del mes en curso NO representa
// ningun dia futuro cuando el tramo se lo come entero: queda fuera de la
// secuencia y por lo tanto fuera de la vista.
$hCome = new Horizonte(28, 2, [], new DateTime('2026-09-06'));
$ejeCome = EjeVista::eje($hCome);

chequear('un mes cubierto por el tramo diario no aparece en la vista de meses',
    1, count($ejeCome['vistas']['meses']['columnas']));
chequear('y la que queda es la del mes siguiente',
    'MES|2026-10', $ejeCome['vistas']['meses']['columnas'][0]);

seccion('los rotulos dicen que periodo se esta midiendo');

chequear('dias: del primero al ultimo',
    'Del 6/9 al 8/9', $eje['vistas']['dias']['periodo']);
chequear('meses: aclara que arranca despues del tramo diario',
    true, strpos($eje['vistas']['meses']['periodo'], 'después del tramo diario') !== false);
chequear('completo: aclara que es todo el horizonte',
    true, strpos($eje['vistas']['completo']['periodo'], 'todo el horizonte') !== false);
chequear('sin columnas no se inventa un periodo',
    'Sin columnas', EjeVista::rotuloPeriodo([], 'dias'));

seccion('el mes recortado queda marcado');

// Un mes cuya columna cubre solo parte del mes -porque el resto de sus dias
// esta en el tramo diario- tiene que decirlo: un importe mas chico son menos
// dias, no una caida.
chequear('el mes en curso esta marcado como parcial', true, $eje['meses'][0]['parcial']);
chequear('un mes completo no es parcial', false, $eje['meses'][1]['parcial']);

/* ================================================================
   Armado de un payload de pestana
   ================================================================ */
seccion('un importe va a un dia O a un mes, nunca a los dos');

$items = [
    // Dentro del tramo diario
    ['ID' => 1, 'FECHA' => '2026-09-07', 'IMPORTE' => 100],
    // Mismo mes, pero FUERA del tramo diario: va a la columna del mes
    ['ID' => 2, 'FECHA' => '2026-09-20', 'IMPORTE' => 200],
    // Mes siguiente
    ['ID' => 3, 'FECHA' => '2026-10-05', 'IMPORTE' => 300],
];

$p = EjeVista::armar($h, $items, 'FECHA', 'IMPORTE');

chequear('una fila por item', 3, count($p['filas']));
chequear('el item del tramo esta en su columna diaria',
    100.0, $p['filas'][0]['dias']['2026-09-07']);
chequear('y NO esta en la columna de su mes',
    0.0, floatval($p['filas'][0]['meses']['2026-09']));

// Este es el doble conteo que tenian las tres pestanas: el importe del mes en
// curso aparecia en la vista de dias Y en la columna del mes, asi que las dos
// vistas no reconciliaban.
chequear('el total de la vista dias cuenta solo el tramo',
    100.0, $p['totales']['total_tramo']);
chequear('el total de la vista meses cuenta solo lo de afuera del tramo',
    500.0, $p['totales']['total_meses']);
chequear('y el total del horizonte es la suma de los dos, sin repetir',
    600.0, $p['totales']['total_horizonte']);

chequear('el dia 20 cae en la columna de su mes',
    200.0, $p['totales']['meses']['2026-09']);
chequear('y octubre en la suya', 300.0, $p['totales']['meses']['2026-10']);

seccion('lo que cae afuera se informa, no se descarta');

$conDescartes = [
    ['ID' => 1, 'FECHA' => '2026-09-07', 'IMPORTE' => 100],
    ['ID' => 2, 'FECHA' => '2028-01-01', 'IMPORTE' => 700],   // fuera del eje
    ['ID' => 3, 'FECHA' => null,         'IMPORTE' => 900],   // sin fecha
];

$pd = EjeVista::armar($h, $conDescartes, 'FECHA', 'IMPORTE');

chequear('lo posterior al eje se informa', 700.0,
    floatval($pd['descartes']['fuera_horizonte']));
chequear('lo que no tiene fecha tambien', 900.0,
    floatval($pd['descartes']['sin_fecha']));
chequear('y no se suman a ninguna vista', 100.0, $pd['totales']['total_horizonte']);
chequear('con un aviso por cada caso', 2, count($pd['warnings']));
chequear('el aviso del horizonte dice el importe',
    true, strpos($pd['warnings'][0], '700,00') !== false);

seccion('el factor multiplica, para las pestanas en dolares');

$pf = EjeVista::armar($h, [['FECHA' => '2026-09-07', 'IMPORTE' => 100]],
    'FECHA', 'IMPORTE', 1500);

chequear('el importe se convierte', 150000.0, $pf['totales']['total_tramo']);

seccion('la fila conserva sus campos originales');

chequear('el payload no pierde los datos del item', 1, $p['filas'][0]['ID']);
chequear('y le agrega los tres totales', 100.0, $p['filas'][0]['total_horizonte']);

seccion('lectura de una celda');

chequear('valor de una columna diaria',
    100.0, EjeVista::valor($p['filas'][0], 'DIA|2026-09-07'));
chequear('valor de una columna mensual',
    300.0, EjeVista::valor($p['filas'][2], 'MES|2026-10'));
chequear('una columna que la fila no tiene da cero, no rompe',
    0.0, EjeVista::valor($p['filas'][0], 'MES|2099-01'));
chequear('una columna null da cero', 0.0, EjeVista::valor($p['filas'][0], null));

seccion('rotulos de columna');

chequear('una columna diaria', '7/9', EjeVista::rotulo('DIA|2026-09-07'));
chequear('una columna mensual', 'Oct-26', EjeVista::rotulo('MES|2026-10'));

seccion('un payload vacio no rompe');

$vacio = EjeVista::armar($h, [], 'FECHA', 'IMPORTE');

chequear('sin items no hay filas', 0, count($vacio['filas']));
chequear('los totales van en cero', 0.0, $vacio['totales']['total_horizonte']);
chequear('el eje se describe igual', 3, count($vacio['vistas']['dias']['columnas']));
chequear('y no hay avisos que dar', 0, count($vacio['warnings']));
chequear('items que no son lista tampoco rompen',
    0, count(EjeVista::armar($h, null, 'FECHA', 'IMPORTE')['filas']));
