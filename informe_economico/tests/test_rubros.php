<?php
require_once __DIR__ . '/../Class/Rubros.php';

seccion('Orden natural de COD_RUBRO');
chequear('7.9 < 7.10 < 7.11', ['7.1.', '7.2.', '7.9.', '7.10.', '7.11.'], Rubros::ordenar(['7.10.', '7.2.', '7.11.', '7.1.', '7.9.']));
chequear('5.2.1.5 < 5.3.1', ['5.1.7.', '5.2.1.1.', '5.2.1.5.', '5.3.1.'], Rubros::ordenar(['5.3.1.', '5.2.1.5.', '5.1.7.', '5.2.1.1.']));
chequear('4.1.1 < 4.2 y 4.3 < 4.3.1', ['4.1.1.', '4.2.', '4.3.', '4.3.1.'], Rubros::ordenar(['4.3.1.', '4.2.', '4.3.', '4.1.1.']));
chequear('Un codigo mas corto va antes', -1, Rubros::comparar('6.', '6.1.'));

seccion('Secciones y rubros sin seccion');
$maestro = [
    '1.5.' => ['nombre' => 'Ventas', 'cat' => null, 'activo' => 1],
    '2.' => ['nombre' => 'Costo', 'cat' => null, 'activo' => 1],
    '7.10.' => ['nombre' => 'Telefonia', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '7.9.' => ['nombre' => 'Movilidad', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '5.3.4.' => ['nombre' => 'Gastos varios', 'cat' => 'Otros Gastos Operativos', 'activo' => 0],
    '5.3.9.' => ['nombre' => 'Inactivo sin datos', 'cat' => 'Otros Gastos Operativos', 'activo' => 0],
    '8.1.' => ['nombre' => 'Sin categoria', 'cat' => null, 'activo' => 1]
];
$c = Rubros::clasificar(['1.5.', '2.', '7.10.', '5.3.4.', '8.1.', '9.9.'], $maestro);
chequear('Seccion ordenada por codigo, con los activos aunque no tengan datos', ['7.9.', '7.10.'], $c['porSeccion']['Gastos de Estructura']);
chequear('Un inactivo entra solo si tiene datos', ['5.3.4.'], $c['porSeccion']['Otros Gastos Operativos']);
chequear('Sin CAT o sin maestro van a Sin seccion; los fijos no', ['8.1.', '9.9.'], $c['sinSeccion']);
chequear('Los de ventas y costo no son "sin seccion" aunque tengan CAT NULL', false, in_array('2.', $c['sinSeccion'], true));
