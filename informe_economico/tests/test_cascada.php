<?php
require_once __DIR__ . '/../Class/Cascada.php';
require_once __DIR__ . '/../Class/Columnas.php';

function regIE($p, $s, $c, $i) {
    return ['PERIODO' => $p, 'NRO_SUCURSAL' => $s, 'COD_RUBRO' => $c, 'IMPORTE' => $i];
}

$registros = [
    regIE('1-2026', 2, '1.5.', 1000),
    regIE('1-2026', 2, '2.', 400),
    regIE('1-2026', 3, '1.5.', 500),
    regIE('1-2026', 16, '1.5.', 200),       // cerrada
    regIE('1-2026', 102, '5.1.1.', 70),
    regIE('1-2026', 301, '1.5.', 300),
    regIE('1-2026', 302, '1.5.', 100),
    // 103 / 1.6 repetido: se suman los tres
    regIE('1-2026', 103, '1.6.', 10),
    regIE('1-2026', 103, '1.6.', 20),
    regIE('1-2026', 103, '1.6.', 30),
    regIE('1-2026', null, '7.2.', 999),
    regIE('1-2026', 0, '7.2.', 1),
    regIE('2-2026', 2, '1.5.', 50)
];
$ix = Cascada::indexar($registros);

seccion('Registros repetidos se suman');
chequear('103 / 1.6: tres registros', 60.0, $ix['1-2026'][103]['1.6.']);
chequear('NULL y 0 van juntos a Sin sucursal', 1000.0, $ix['1-2026'][Canales::CLAVE_SIN]['7.2.']);

seccion('Subtotal LOCALES con y sin cerradas');
$presentes = [2, 3, 16, 102, 301, 302, 103, Canales::CLAVE_SIN];
$cerradas = [16 => true];
$sin = Columnas::localesIncluidos($presentes, $cerradas, false);
$con = Columnas::localesIncluidos($presentes, $cerradas, true);
chequear('Sin cerradas: 2 y 3', [2, 3], $sin);
chequear('Con cerradas: 2, 3 y 16', [2, 3, 16], $con);
chequear('1.5 LOCALES sin cerradas', 1500.0, Cascada::agregar($ix, $sin, ['1-2026'])['1.5.']);
chequear('1.5 LOCALES con cerradas', 1700.0, Cascada::agregar($ix, $con, ['1-2026'])['1.5.']);
chequear('Solo los periodos de la columna', 1550.0, Cascada::agregar($ix, $sin, ['1-2026', '2-2026'])['1.5.']);

seccion('Subtotal ECOMMERCE: 102 + 301 + 302 + 303');
$ec = Cascada::agregar($ix, Columnas::sucursalesCanal(Canales::ECOMMERCE), ['1-2026']);
chequear('1.5 de las aperturas', 400.0, $ec['1.5.']);
chequear('Los gastos de 102 entran', 70.0, $ec['5.1.1.']);

seccion('Sin sucursal no suma al Total general');
$cols = Columnas::canales($presentes, $cerradas, false, ['1-2026']);
$claves = array_column($cols, 'clave');
chequear('Columnas de IE por Canales', ['LOCALES', 'FRANQUICIAS', 'MAYORISTAS', 'ECOMMERCE', 'OTROS', 'TOTAL', 'SIN'], $claves);
$total = $cols[array_search('TOTAL', $claves)];
chequear('El total no tiene la clave SIN', false, in_array(Canales::CLAVE_SIN, $total['sucursales'], true));
chequear('7.2 del total: nada (solo estaba sin sucursal)', false, isset(Cascada::agregar($ix, $total['sucursales'], ['1-2026'])['7.2.']));
chequear('El total no se edita', false, $total['editable']);

seccion('Columnas de IE por Locales');
$nombres = [2 => ['nombre' => 'Unicenter'], 3 => ['nombre' => 'Alto Palermo']];
$cl = Columnas::locales($presentes, $nombres, $cerradas, false, ['1-2026']);
chequear('Orden: locales, LOCALES, canales, aperturas con datos, ECOMMERCE, otros, total, sin',
    ['S2', 'S3', 'LOCALES', 'FRANQUICIAS', 'MAYORISTAS', 'S102', 'S301', 'S302', 'ECOMMERCE', 'OTROS', 'TOTAL', 'SIN'],
    array_column($cl, 'clave'));
$clCon = Columnas::locales($presentes, $nombres, $cerradas, true, ['1-2026']);
chequear('Con cerradas, la 16 aparece marcada', true, $clCon[2]['cerrada']);

seccion('Una columna completa');
$clasif = ['porSeccion' => [], 'seccionDe' => [], 'sinSeccion' => []];
$res = Cascada::columna($ix, $cols[0], $clasif, ['total19' => 3000.0, 'locales19' => 1500.0]);
chequear('Resultado bruto de LOCALES', 1100.0, $res['calc']['RESULTADO_BRUTO']);
chequear('Participacion locales de LOCALES es 1', 1.0, $res['calc']['PART_LOCALES']);

seccion('Conversion a USD: los ratios no cambian');
$usd = Cascada::convertir($ix, ['1-2026' => 1000.0, '2-2026' => 1000.0]);
$resUsd = Cascada::columna($usd, $cols[0], $clasif, ['total19' => 3.0, 'locales19' => 1.5]);
chequear('El importe se divide por el TCC', 1.1, $resUsd['calc']['RESULTADO_BRUTO']);
chequear('Margen bruto igual en ARS y USD', $res['calc']['MARGEN_BRUTO'], $resUsd['calc']['MARGEN_BRUTO']);
chequear('Relacion costo igual en ARS y USD', $res['calc']['REL_COSTO_VENTAS'], $resUsd['calc']['REL_COSTO_VENTAS']);
chequear('Participacion igual en ARS y USD', $res['calc']['PART_TOTAL'], $resUsd['calc']['PART_TOTAL']);

seccion('Conversion a USD: cada mes con su cierre');
$usdMes = Cascada::convertir($ix, ['1-2026' => 1000.0, '2-2026' => 500.0]);
chequear('Enero con el cierre de enero', 1.0, $usdMes['1-2026'][2]['1.5.']);
chequear('Febrero con el cierre de febrero', 0.1, $usdMes['2-2026'][2]['1.5.']);
chequear('La columna del rango suma dolares ya convertidos', 1.1, Cascada::agregar($usdMes, [2], ['1-2026', '2-2026'])['1.5.']);

seccion('Variacion contra el anio anterior');
chequear('Subio 10%', 0.1, Cascada::variacion(110, 100));
chequear('Base 0: null', null, Cascada::variacion(110, 0));
chequear('Base null: null', null, Cascada::variacion(110, null));
chequear('Actual null: null', null, Cascada::variacion(null, 100));
chequear('Perdida que se achica: el signo dice que mejoro', 0.5, Cascada::variacion(-50, -100));
chequear('Ratio: diferencia en puntos', -0.03, Cascada::variacion(0.12, 0.15, true));
chequear('No aplica se mantiene', Formulas::NO_APLICA, Cascada::variacion(Formulas::NO_APLICA, 1));

seccion('Columnas de Evolucion Mensual');
$cm = Columnas::mensual($presentes, $cerradas, false, ['1-2026', '2-2026'], Canales::LOCALES);
chequear('Un mes por columna y el total', ['M1-2026', 'M2-2026', 'TOTAL'], array_column($cm, 'clave'));
chequear('Canal Locales: tipo LOCALES (rinde sobre 1.5)', Formulas::LOCALES, $cm[0]['tipo']);
