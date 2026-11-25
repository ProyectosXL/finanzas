<?php
/**
 * Las tasas de referencia de los prestamos variables (Class/PrestamosTasas.php):
 * como se proyecta la tasa base y que avisa.
 *
 * TODO CON DATOS ESCRITOS A MANO Y HOY FIJO (25/11/2026). Contra la base solo
 * se fija que la clase no escriba en [XL-APPS].
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *   1. LA TASA SE USA EN %. 23,69 como 23,69 (no 0,2369) multiplica el interes
 *      por cien.
 *   2. UN PERIODO YA EMPEZADO SE PROYECTA CON LA TASA DE HOY y no con la de
 *      su inicio.
 *   3. EL MANUAL NO GANA, o gana una correccion de otro mes.
 *   4. SIN XL-APPS SE PROYECTA EN CERO en vez de no proyectar y avisar.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/PrestamosTasas.php';

const PT_HOY = '2026-11-25';

/** TAMAR de prueba: 9 publicaciones, del 10 al 20/11 (habiles). */
function ptSerie() {
    return ['2026-11-10' => 20.0, '2026-11-11' => 21.0, '2026-11-12' => 22.0, '2026-11-13' => 23.0,
            '2026-11-16' => 24.0, '2026-11-17' => 25.0, '2026-11-18' => 26.0, '2026-11-19' => 27.0,
            '2026-11-20' => 28.0];
}

seccion('% a decimal: la unica division por 100');
chequear('23,69 % es 0,2369', 0.2369, PrestamosTasas::tnaDecimal(23.69));
chequear('null sigue null', null, PrestamosTasas::tnaDecimal(null));

seccion('promedio de los ultimos N registros');
$p = PrestamosTasas::promedio(ptSerie(), 5);
chequear('los ultimos 5: 24 a 28 -> 26', 26.0, $p['valor']);
chequear('desde el 16/11', '2026-11-16', $p['desde']);
chequear('suficiente', true, $p['suficiente']);
$p = PrestamosTasas::promedio(ptSerie(), 5, '2026-11-17');
chequear('antes del 17/11 (exclusive): 20 a 24 -> 22', 22.0, $p['valor']);
$p = PrestamosTasas::promedio(ptSerie(), 5, '2026-11-12');
chequear('antes del 12/11 hay solo 2: no alcanza', false, $p['suficiente']);
chequear('sin datos, null', null, PrestamosTasas::promedio([], 5));
chequear('el orden de entrada no importa', 26.0, PrestamosTasas::promedio(array_reverse(ptSerie(), true), 5)['valor']);

seccion('ultimo valor publicado');
chequear('el ultimo es el del 20/11', ['valor' => 28.0, 'fecha' => '2026-11-20'], PrestamosTasas::ultimo(ptSerie()));
chequear('antes del 18/11: el del 17', 25.0, PrestamosTasas::ultimo(ptSerie(), '2026-11-18')['valor']);

seccion('base(): futuro, ya empezado, manual');
$b = PrestamosTasas::base(ptSerie(), '2026-12-15', PT_HOY, PrestamosTasas::PROMEDIO, 5);
chequear('periodo futuro: el promedio vigente', 26.0, $b['valor']);
chequear('origen promedio', PrestamosTasas::O_PROMEDIO, $b['origen']);
$b = PrestamosTasas::base(ptSerie(), '2026-11-17', PT_HOY, PrestamosTasas::PROMEDIO, 5);
chequear('periodo empezado el 17/11: los 5 anteriores a su inicio', 22.0, $b['valor']);
$b = PrestamosTasas::base(ptSerie(), '2026-11-11', PT_HOY, PrestamosTasas::PROMEDIO, 5);
chequear('empezado sin datos suficientes: el promedio mas viejo (20 a 24)', 22.0, $b['valor']);
chequear('y lo marca', PrestamosTasas::O_PROMEDIO_VIEJO, $b['origen']);
chequear('con su aviso', true, $b['aviso'] !== null);
$b = PrestamosTasas::base(ptSerie(), '2026-12-15', PT_HOY, PrestamosTasas::PROMEDIO, 5, ['2026-12' => 30.5]);
chequear('el manual del mes de inicio gana', 30.5, $b['valor']);
chequear('origen manual', PrestamosTasas::O_MANUAL, $b['origen']);
$b = PrestamosTasas::base(ptSerie(), '2027-01-15', PT_HOY, PrestamosTasas::PROMEDIO, 5, ['2026-12' => 30.5]);
chequear('el manual de otro mes no', 26.0, $b['valor']);
$b = PrestamosTasas::base(ptSerie(), '2026-12-15', PT_HOY, PrestamosTasas::ULTIMO, 5);
chequear('criterio ultimo: futuro con el ultimo publicado', 28.0, $b['valor']);
$b = PrestamosTasas::base(ptSerie(), '2026-11-17', PT_HOY, PrestamosTasas::ULTIMO, 5);
chequear('criterio ultimo, empezado: el anterior al inicio', 24.0, $b['valor']);
$b = PrestamosTasas::base([], '2026-12-15', PT_HOY, PrestamosTasas::PROMEDIO, 5);
chequear('sin datos: null', null, $b['valor']);

seccion('dias habiles desde una fecha');
chequear('del 20/11 (vie) al 25/11 (mie), sin feriados: 3', 3, PrestamosTasas::habilesDesde('2026-11-20', PT_HOY, []));
chequear('con el 23/11 feriado: 2', 2, PrestamosTasas::habilesDesde('2026-11-20', PT_HOY, ['2026-11-23' => false]));

seccion('avisos');
$leido = ['series' => [PrestamosTasas::TAMAR => ptSerie(), PrestamosTasas::BADLAR => ptSerie()],
          'log' => [PrestamosTasas::TAMAR => ['estado' => 'OK', 'ejecucion' => '2026-11-25 08:00', 'ultima_fecha' => '2026-11-20', 'detalle' => ''],
                    PrestamosTasas::BADLAR => ['estado' => 'ERROR', 'ejecucion' => '2026-11-25 08:00', 'ultima_fecha' => null, 'detalle' => 'timeout']],
          'disponible' => true];
chequear('sin prestamos variables, nada', [], PrestamosTasas::avisos($leido, null, [], PT_HOY, [], 5));
$a = PrestamosTasas::avisos($leido, null, [PrestamosTasas::TAMAR], PT_HOY, [], 5);
chequear('TAMAR al dia y con log OK: nada', [], $a);
$a = PrestamosTasas::avisos($leido, null, [PrestamosTasas::BADLAR], PT_HOY, [], 5);
chequear('BADLAR con log en ERROR: un aviso de atencion', 1, count($a));
chequear('de atencion', Aviso::WARNING, $a[0]['nivel']);
chequear('con la fecha del ultimo valor', true, strpos($a[0]['texto'], '20/11/2026') !== false);
$a = PrestamosTasas::avisos($leido, null, [PrestamosTasas::TAMAR], '2026-11-27', [], 3);
chequear('dato viejo (5 habiles, se toleran 3): avisa', true, count($a) === 1 && strpos($a[0]['texto'], 'hace 5 días hábiles') !== false);
$a = PrestamosTasas::avisos(['series' => [], 'log' => [], 'disponible' => false], 'sin conexion', [PrestamosTasas::TAMAR], PT_HOY, [], 5);
chequear('XL-APPS caido: critico', Aviso::DANGER, $a[0]['nivel']);
chequear('dice que los fijos siguen', true, strpos($a[0]['texto'], 'tasa fija, sí') !== false);

seccion('el cashflow no escribe en [XL-APPS]');
$src = file_get_contents(__DIR__ . '/../Class/PrestamosTasas.php');
chequear('PrestamosTasas no tiene INSERT, UPDATE, DELETE, MERGE ni EXEC', 0,
    preg_match('/\b(INSERT\s+INTO|UPDATE\s+\S+\s+SET|DELETE\s+FROM|MERGE\s+|EXEC(UTE)?\s*\()/i', $src));
chequear('busca por IdVariable: TAMAR 44, BADLAR 7', [PrestamosTasas::TAMAR => 44, PrestamosTasas::BADLAR => 7], PrestamosTasas::ID_VARIABLE);
