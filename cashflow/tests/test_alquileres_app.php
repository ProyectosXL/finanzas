<?php
/**
 * La lectura de la app de alquileres (Class/AlquileresApp.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL CASHFLOW ESCRIBE EN LA APP. Las tablas son de administracion: un
 *      UPDATE "inocente" en RO_T_DETALLE_ALQUILERES cambiaria lo que la app
 *      muestra como cerrado. La clase no tiene que tener ni una sentencia de
 *      escritura, y se lee el fuente para fijarlo.
 *
 *   2. LOS PERIODOS SE CONVIERTEN MAL. '9-2026' y '2026-09' tienen que ser el
 *      mismo mes; '1-2026' no puede ser noviembre (el LIKE de la app hace eso).
 *
 *   3. UN PORCENTAJE CON COMA SE LEE COMO OTRO NUMERO. La app hace
 *      str_replace(',', '.') antes del floatval; sin eso, '6,47' es 6.
 *
 *   4. UN PERIODO ABIERTO SE LEE. Con el periodo abierto los conceptos 6 y 7
 *      estan guardados sin restar el VMA. La ultima seccion, contra la base,
 *      verifica que detalleCerrados() no traiga ningun mes abierto y que en un
 *      mes cerrado el 6 y el 7 ya tengan restado el 8.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresApp.php';
require_once __DIR__ . '/../Class/Inflacion.php';

seccion('AlquileresApp: los periodos');

chequear("'9-2026' es 2026-09", '2026-09', AlquileresApp::mesDePeriodo('9-2026'));
chequear("'09-2026' tambien", '2026-09', AlquileresApp::mesDePeriodo('09-2026'));
chequear("'1-2026' es enero, no noviembre", '2026-01', AlquileresApp::mesDePeriodo('1-2026'));
chequear("'11-2026' es noviembre", '2026-11', AlquileresApp::mesDePeriodo(' 11-2026 '));
chequear('un mes 13 no se adivina', null, AlquileresApp::mesDePeriodo('13-2026'));
chequear('un formato ajeno no se adivina', null, AlquileresApp::mesDePeriodo('2026-09'));
chequear('ida y vuelta: 2026-09 es 9-2026', '9-2026', AlquileresApp::periodoDeMes('2026-09'));
chequear('ida y vuelta: 2026-12 es 12-2026', '12-2026', AlquileresApp::periodoDeMes('2026-12'));

seccion('AlquileresApp: los porcentajes, con el parseo de la app');

chequear('un float queda igual', 6.47, AlquileresApp::porcentaje(6.47));
chequear('con coma decimal', 6.47, AlquileresApp::porcentaje('6,47'));
chequear('con punto decimal', 0.84, AlquileresApp::porcentaje('0.84'));
chequear('un 0 es un porcentaje, no un faltante', 0.0, AlquileresApp::porcentaje('0'));
chequear('vacio es null', null, AlquileresApp::porcentaje(' '));
chequear('NULL es null', null, AlquileresApp::porcentaje(null));

seccion('AlquileresApp: solo lee');

$fuente = file_get_contents(__DIR__ . '/../Class/AlquileresApp.php');
/* Se sacan los comentarios: el encabezado nombra las palabras para explicar
   por que no estan. */
$codigo = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $fuente);
chequear('ninguna sentencia de escritura en el codigo', 0,
    preg_match_all('/\b(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|EXEC|DROP|ALTER|CREATE)\b/i', $codigo));

seccion('AlquileresApp: contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $app = new AlquileresApp();
    chequear('estan las tablas de la app', [], $app->tablasFaltantes());

    $cerrados = $app->mesesCerrados();
    chequear('hay meses cerrados', true, count($cerrados) > 0);

    $desde = Inflacion::mesMas(end($cerrados), -2);
    $detalle = $app->detalleCerrados($cerrados, $desde);
    $abiertos = 0;

    foreach ($detalle as $meses) {
        foreach (array_keys($meses) as $mes) {
            if (!in_array($mes, $cerrados, true)) {
                $abiertos++;
            }
        }
    }

    chequear('detalleCerrados() no trae ningun mes abierto', 0, $abiertos);

    /* DE UN PERIODO ABIERTO SOLO LOS FIJOS (1, 2, 3 y 8), mayores que 0, y
       ningun mes cerrado. */
    $siguientes = [Inflacion::mesMas(end($cerrados), 1), Inflacion::mesMas(end($cerrados), 2)];
    $ab = $app->detalleAbiertos(array_merge([end($cerrados)], $siguientes), $cerrados);
    $malos = [];

    foreach ($ab as $nro => $meses) {
        foreach ($meses as $m => $ids) {
            foreach ($ids as $id => $v) {
                if (in_array($m, $cerrados, true) || !in_array($id, AlquileresApp::FIJOS_ABIERTOS, true) || $v <= 0) {
                    $malos[] = "$nro/$m/$id";
                }
            }
        }
    }

    chequear('detalleAbiertos(): solo fijos > 0 de meses abiertos', [], $malos);

    /* EL VMA DE NOVIEMBRE DE 2026 DE AVELLANEDA, cargado con el periodo
       abierto: $ 10.209.263. Solo mientras noviembre siga abierto. */
    if (in_array('2026-11', $cerrados, true)) {
        Pruebas::saltear('noviembre de 2026 ya se cerró: el VMA de Avellaneda se lee como cerrado');
    } else {
        $nov = $app->detalleAbiertos(['2026-11'], $cerrados);
        chequear('Avellaneda, noviembre abierto: el VMA es el de la app', 10209263.0, $nov[6]['2026-11'][8] ?? null);
    }

    /* Con el mes cerrado, 6 = max(0, bruta x %6 - c8) y 7 = max(0, neta x %7
       - c8): la resta ya esta hecha. Se mide en todo local que tenga el
       porcentaje y la venta, con un peso de tolerancia porque la app trunca a
       pesos enteros. */
    $mes = end($cerrados);
    $pct = $app->porcentajes();
    $netas = $app->ventasNetas($mes);
    $brutas = $app->ventasBrutas($mes);
    $medidos = 0;
    $distintos = [];

    foreach ($detalle as $nro => $meses) {
        if (!isset($meses[$mes])) {
            continue;
        }

        $c = $meses[$mes];

        foreach ([6 => $brutas, 7 => $netas] as $id => $venta) {
            if (!isset($pct[$nro][$id], $venta[$nro][$mes])) {
                continue;
            }

            $esperado = max(0.0, $venta[$nro][$mes] * $pct[$nro][$id] / 100 - ($c[8] ?? 0.0));
            $medidos++;

            if (abs($esperado - ($c[$id] ?? 0.0)) > 1.5) {
                $distintos[] = "$nro/$id";
            }
        }
    }

    chequear('en el ultimo cerrado se pudo medir el 6 o el 7 en algun local', true, $medidos > 0);
    chequear('en el ultimo cerrado el 6 y el 7 ya tienen restado el VMA', [], $distintos);
}

seccion('AlquileresApp: los contratos con sus importes (Llaves y Renovaciones)');

$fila = ['ID' => 79, 'VIG_DESDE' => new DateTime('2026-01-15'), 'VIG_HASTA' => new DateTime('2029-01-14'),
         'ID_CA' => '4', 'IMPORTE' => '75000000.00', 'ID_CA_2' => '5', 'IMPORTE_2' => 29452304, 'ID_CA_3' => '18',
         'IMPORTE_3' => 7500000];
chequear('una fila de la app: vigencia e importes por concepto',
    ['id' => 79, 'desde' => '2026-01-15', 'hasta' => '2029-01-14', 'llave' => 75000000.0, 'comisiones' => 29452304.0,
     'fpc' => 7500000.0], AlquileresApp::contratoDeFila($fila));
chequear('la llave = 1 se devuelve tal cual (la marca la interpreta LlavesCalculo)', 1.0,
    AlquileresApp::contratoDeFila(['IMPORTE' => 1] + $fila)['llave']);
chequear('un importe vacio es null, no 0', null, AlquileresApp::contratoDeFila(['IMPORTE_3' => null] + $fila)['fpc']);
chequear('un importe guardado con otro concepto no se toma como llave', null,
    AlquileresApp::contratoDeFila(['ID_CA' => '7'] + $fila)['llave']);
chequear('una vigencia al reves no es un contrato', null,
    AlquileresApp::contratoDeFila(['VIG_HASTA' => new DateTime('2025-01-01')] + $fila));

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $app = new AlquileresApp();
    $con = $app->contratosConImportes();
    $vig = $app->contratos();
    chequear('contratosConImportes() trae los mismos locales que contratos()', array_keys($vig), array_keys($con));
    /* DOT (66) renovo el 20/10/2026 con llave 80 M (contrato 91). Solo
       mientras administracion no lo corrija. */
    $dot = array_values(array_filter($con[66] ?? [], function ($c) { return $c['id'] === 91; }));
    chequear('DOT #91: llave 80.000.000 desde el 20/10/2026', [80000000.0, '2026-10-20'],
        empty($dot) ? null : [$dot[0]['llave'], $dot[0]['desde']]);
}
