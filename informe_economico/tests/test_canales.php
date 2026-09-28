<?php
require_once __DIR__ . '/../Class/Canales.php';

seccion('Canal de cada sucursal');
chequear('100 Franquicias', Canales::FRANQUICIAS, Canales::canal(100));
chequear('101 Mayoristas', Canales::MAYORISTAS, Canales::canal(101));
chequear('102 Ecommerce (la historica)', Canales::ECOMMERCE, Canales::canal(102));
chequear('301 Ecommerce VTEX', Canales::ECOMMERCE, Canales::canal(301));
chequear('302 Ecommerce ML', Canales::ECOMMERCE, Canales::canal(302));
chequear('303 Ecommerce ICBC', Canales::ECOMMERCE, Canales::canal(303));
chequear('103 Otros ingresos', Canales::OTROS, Canales::canal(103));
chequear('2 es local propio', Canales::LOCALES, Canales::canal(2));
chequear('304 (no declarada) cae en locales', Canales::LOCALES, Canales::canal(304));
chequear('NULL es Sin sucursal', Canales::SIN_SUCURSAL, Canales::canal(null));
chequear('0 es Sin sucursal, NO Ecommerce', Canales::SIN_SUCURSAL, Canales::canal(0));
chequear('"102" como texto', Canales::ECOMMERCE, Canales::canal('102'));

seccion('Nombre de sucursal');
$n = Canales::resolverNombres([
    ['NRO_SUCURSAL' => 33, 'PERIODO' => '12-2025', 'DESC_SUCURSAL' => 'MDP ALDREY'],
    ['NRO_SUCURSAL' => 33, 'PERIODO' => '2-2026', 'DESC_SUCURSAL' => 'Paseo Aldrey'],
    ['NRO_SUCURSAL' => 33, 'PERIODO' => '3-2026', 'DESC_SUCURSAL' => null],
    ['NRO_SUCURSAL' => 301, 'PERIODO' => '2-2026', 'DESC_SUCURSAL' => ''],
    ['NRO_SUCURSAL' => 88, 'PERIODO' => '2-2026', 'DESC_SUCURSAL' => null],
    ['NRO_SUCURSAL' => null, 'PERIODO' => '2-2026', 'DESC_SUCURSAL' => null]
]);
chequear('El DESC no nulo del periodo mas reciente', 'Paseo Aldrey', $n[33]['nombre']);
chequear('Sin DESC: 301 por defecto', 'Ecommerce VTEX', $n[301]['nombre']);
chequear('Sin DESC: marca de defecto', true, $n[301]['defecto']);
chequear('Sin DESC y desconocida: Sucursal N', 'Sucursal 88', $n[88]['nombre']);
chequear('Sucursal N lleva aviso', true, $n[88]['aviso']);
chequear('Sin sucursal', 'Sin sucursal', $n[Canales::CLAVE_SIN]['nombre']);
chequear('Defectos de 100, 101, 102, 302, 303 y 103', ['Franquicias', 'Mayoristas', 'Ecommerce', 'Ecommerce ML', 'Ecommerce ICBC', 'Otros ingresos'],
    array_map(function ($k) { return Canales::nombreDefecto($k)['nombre']; }, [100, 101, 102, 302, 303, 103]));

seccion('Local cerrado');
$m = [2 => 1, 16 => 0, 70 => null];
chequear('HABILITADO 1 abierto', Canales::ABIERTO, Canales::estadoLocal(2, $m));
chequear('HABILITADO 0 cerrado', Canales::CERRADO, Canales::estadoLocal(16, $m));
chequear('HABILITADO NULL: se trata como abierto, con aviso', Canales::SIN_ESTADO, Canales::estadoLocal(70, $m));
chequear('No esta en el maestro', Canales::NO_ESTA, Canales::estadoLocal(84, $m));
