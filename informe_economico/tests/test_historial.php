<?php
require_once __DIR__ . '/../Class/HistorialEdicion.php';

function movIE($tipo, $ant, $nue) {
    return ['TIPO' => $tipo, 'IMPORTE_ANTERIOR' => $ant, 'IMPORTE_NUEVO' => $nue,
            'USUARIO' => 'u', 'FECHA' => '2026-09-28 10:00:00', 'MOTIVO' => 'm'];
}

$E = HistorialEdicion::EDICION;
$D = HistorialEdicion::DESHACER;
$R = HistorialEdicion::RESTAURAR;

seccion('Marca de celda editada');
$sin = HistorialEdicion::estado([], 100.0);
chequear('Sin historial: sin marca', false, $sin['editado']);
$movs = [movIE($E, 100, 150), movIE($E, 150, 180)];
$e = HistorialEdicion::estado($movs, 180.0);
chequear('Importe igual al ultimo nuevo: marcada', true, $e['editado']);
chequear('El original es el anterior del primer movimiento', 100.0, $e['original']);
chequear('Tolerancia de centavos (float)', true, HistorialEdicion::estado($movs, 180.001)['editado']);

seccion('Un reproceso piso el valor');
$p = HistorialEdicion::estado($movs, 123.45);
chequear('Importe distinto: sin marca', false, $p['editado']);
chequear('Importe distinto: pisado', true, $p['pisado']);
chequear('Pisado: deshacer deshabilitado', null, $p['deshacerA']);
chequear('Pisado: restaurar deshabilitado', null, $p['restaurarA']);
$borrado = HistorialEdicion::estado($movs, null);
chequear('El ID ya no existe (el reproceso borra y reinserta): pisado', true, $borrado['pisado']);

seccion('Deshacer: un paso atras por vez');
chequear('Deshacer lleva al anterior del ultimo', 150.0, $e['deshacerA']);
$d1 = [movIE($E, 100, 150), movIE($E, 150, 180), movIE($D, 180, 150)];
chequear('Segundo deshacer: al original, no de vuelta a 180', 100.0, HistorialEdicion::estado($d1, 150.0)['deshacerA']);
$d2 = array_merge($d1, [movIE($D, 150, 100)]);
chequear('Todo deshecho: no hay nada mas que deshacer', null, HistorialEdicion::estado($d2, 100.0)['deshacerA']);
chequear('Todo deshecho: restaurar no hace falta', null, HistorialEdicion::estado($d2, 100.0)['restaurarA']);
chequear('Todo deshecho: sigue con historial y sin pisar', true, HistorialEdicion::estado($d2, 100.0)['editado']);

seccion('Restaurar original');
chequear('Restaurar lleva al valor del proceso', 100.0, $e['restaurarA']);
$r = [movIE($E, 100, 150), movIE($E, 150, 180), movIE($R, 180, 100)];
$er = HistorialEdicion::estado($r, 100.0);
chequear('Despues de restaurar ya esta en el original', null, $er['restaurarA']);
chequear('Un restaurar tambien se deshace', 180.0, $er['deshacerA']);

seccion('Validacion del importe');
chequear('Con punto decimal', 1234.56, HistorialEdicion::validarImporte('1234.56')['valor']);
chequear('Formato de pantalla', 1234.56, HistorialEdicion::validarImporte('1.234,56')['valor']);
chequear('Negativo', -10.5, HistorialEdicion::validarImporte('-10,5')['valor']);
chequear('Tres decimales se rechaza', false, HistorialEdicion::validarImporte('1.2345')['ok']);
chequear('Texto se rechaza', false, HistorialEdicion::validarImporte('abc')['ok']);
chequear('Vacio se rechaza', false, HistorialEdicion::validarImporte('')['ok']);
chequear('Motivo obligatorio', 'El motivo es obligatorio.', HistorialEdicion::validarMotivo('   '));
chequear('Motivo presente', null, HistorialEdicion::validarMotivo('Ajuste por factura mal imputada'));
