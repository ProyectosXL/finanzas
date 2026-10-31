<?php
/**
 * El aviso de valuacion no cuenta lo que ya se pago.
 *
 * Al 31/10/2026 el aviso "no tienen fecha estimada de pago" reclamaba fecha
 * para 105 contenedores por U$S 6.794.918,65 que estaban TODOS tildados como
 * pagados: escondidos en la grilla y fuera del tablero. Se prueba la regla
 * pura -Comex::valuacionImporta()- y que las dos pestanas filtren con ella
 * antes de armar el aviso.
 */

require_once __DIR__ . '/../Class/Aviso.php';
require_once __DIR__ . '/../Class/Comex.php';

seccion('valuacionImporta(): lo pagado no cuenta');

chequear('una fila sin marcas cuenta', true, Comex::valuacionImporta([]));
chequear('PAGADO falso cuenta', true, Comex::valuacionImporta(['PAGADO' => false]));
chequear('tildada como pagada no cuenta', false, Comex::valuacionImporta(['PAGADO' => true]));
chequear('cancelada en Comercio Exterior no cuenta', false,
    Comex::valuacionImporta(['ESTADO_PAGO' => Comex::ESTADO_CANCELADO]));
chequear('con saldo pendiente cuenta', true,
    Comex::valuacionImporta(['ESTADO_PAGO' => 'PARCIAL']));

seccion('el aviso, sobre las filas filtradas');

$sinFechaPagada = ['PENDIENTE_USD' => 1000, 'COTIZ_USD' => null, 'COTIZ_ORIGEN' => null,
                   'COTIZ_MOTIVO' => 'SIN_FECHA', 'PAGADO' => true];
$sinFecha = ['PENDIENTE_USD' => 250, 'COTIZ_USD' => null, 'COTIZ_ORIGEN' => null,
             'COTIZ_MOTIVO' => 'SIN_FECHA', 'PAGADO' => false];

$filtradas = array_values(array_filter([$sinFechaPagada, $sinFecha],
    [Comex::class, 'valuacionImporta']));
$avisos = Comex::avisosValuacionConNivel($filtradas, '2027-09', 'PENDIENTE_USD');

chequear('un solo aviso', 1, count($avisos));
chequear('cuenta solo la que no esta pagada', true,
    strpos($avisos[0]['texto'], '1 contenedor(es) por U$S 250,00') !== false);

$filtradas = array_values(array_filter([$sinFechaPagada], [Comex::class, 'valuacionImporta']));
chequear('todas pagadas: ningun aviso', 0,
    count(Comex::avisosValuacionConNivel($filtradas, '2027-09', 'PENDIENTE_USD')));

seccion('las dos pestanas filtran antes de armar el aviso');

$fuente = file_get_contents(__DIR__ . '/../Class/Comex.php');

$ext = substr($fuente, strpos($fuente, 'public function avisosExteriorConNivel('));
$ext = substr($ext, 0, strpos($ext, 'avisosVencidosConNivel('));
chequear('Proveedores Exterior filtra con valuacionImporta', 1,
    preg_match("/array_filter\(.*'valuacionImporta'\]\)\),\s*\\\$this->dolarFuturo\(\)->ultimoMes\(\), 'PENDIENTE_USD'\)/s",
        $ext));

$nac = substr($fuente, strpos($fuente, 'public function avisosNacionalizacionConNivel('));
$nac = substr($nac, 0, strpos($nac, 'avisosValuacionConNivel('));
chequear('Crono Nacionalizacion filtra las estimadas con valuacionImporta', 1,
    preg_match('/\$estimadas = array_values\(array_filter\(\$filas, function \(\$f\) \{'
        . '[^}]*self::valuacionImporta\(\$f\)/s', $nac));
