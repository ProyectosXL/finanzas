<?php
/**
 * Comercio Exterior: contenedores desde una fecha de movimiento.
 *
 * Proveedores Exterior, el descuento de lo ya cargado de Compras Proyectadas y
 * Crono Nacionalizacion traen solo los contenedores cuyo FECHA_MOV de la OC
 * principal es igual o posterior al parametro comex_fecha_mov_desde (por
 * defecto 01/01/2025). Ver Comex::sqlDesdeFechaMov().
 *
 * Las reglas se prueban sin base. Lo que necesita base -que el SQL liste lo
 * mismo que la regla pura- esta en tests/test_comex_visibilidad.php.
 */

require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Parametros.php';

seccion('el corte: justo en la fecha, antes, despues, y una hija');

$desde = '2025-01-01';

chequear('un contenedor justo en la fecha entra', true,
    Comex::entraPorFechaMov('2025-01-01', '2025-01-01', $desde));
chequear('uno del dia anterior no', false,
    Comex::entraPorFechaMov('2024-12-31', '2024-12-31', $desde));
chequear('uno posterior si', true,
    Comex::entraPorFechaMov('2025-06-15', '2025-06-15', $desde));
chequear('una hija posterior cuya principal es anterior NO entra: va con su principal', false,
    Comex::entraPorFechaMov('2024-11-30', '2025-03-01', $desde));
chequear('y una hija anterior cuya principal es posterior SI entra', true,
    Comex::entraPorFechaMov('2025-03-01', '2024-11-30', $desde));
chequear('si la principal no esta (FK colgada), cuenta la propia', true,
    Comex::entraPorFechaMov(null, '2025-03-01', $desde));
chequear('una fecha con hora se compara por el dia', true,
    Comex::entraPorFechaMov('2025-01-01 00:00:00', null, $desde));

seccion('el parametro');

chequear('sin el parametro se usa 01/01/2025', '2025-01-01', Comex::fechaMovDesde(null));
chequear('vacio, tambien', '2025-01-01', Comex::fechaMovDesde(''));
chequear('un valor que no es una fecha, tambien', '2025-01-01', Comex::fechaMovDesde('01/06/2025'));
chequear('una fecha que no existe, tambien', '2025-01-01', Comex::fechaMovDesde('2025-02-30'));
chequear('una fecha valida manda', '2025-06-01', Comex::fechaMovDesde(' 2025-06-01 '));
chequear('el corte usa el parametro', false,
    Comex::entraPorFechaMov('2025-05-31', null, '2025-06-01'));
chequear('el valor inicial es el del script', true,
    strpos(file_get_contents(__DIR__ . '/../sql/cashflow_comex_fecha_mov_desde.sql'),
        "''comex_fecha_mov_desde'', ''" . Comex::FECHA_MOV_DESDE_INICIAL . "''") !== false);

seccion('al guardarlo, el servidor valida');

chequear('una fecha real y no futura se acepta', '2025-06-01',
    Comex::validarFechaMovDesde('2025-06-01', '2026-10-20'));
chequear('hoy tambien', '2026-10-20', Comex::validarFechaMovDesde('2026-10-20', '2026-10-20'));
chequearLanza('una futura no', function () {
    Comex::validarFechaMovDesde('2026-10-21', '2026-10-20');
}, '"Contenedores desde" no puede ser una fecha futura: dejaría afuera todos los contenedores.');
chequearLanza('ni algo que no es una fecha', function () {
    Comex::validarFechaMovDesde('01/06/2025', '2026-10-20');
}, '"Contenedores desde" tiene que ser una fecha (aaaa-mm-dd).');

$srcParam = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro pasa esta clave por la validacion de Comex', true,
    strpos($srcParam, 'if ($clave === Comex::PARAM_FECHA_MOV_DESDE) {') !== false
    && strpos($srcParam, '$valor = Comex::validarFechaMovDesde($valor, date(\'Y-m-d\'));') !== false);

seccion('un solo ayudante, en los dos padrones y en Crono');

$srcComex = file_get_contents(__DIR__ . '/../Class/Comex.php');
$srcDatos = file_get_contents(__DIR__ . '/../Class/ComprasProyectadasDatos.php');

$sql = Comex::sqlDesdeFechaMov('2025-01-01');
chequear('el SQL mira el FECHA_MOV de la OC principal', true,
    strpos($sql, 'WHERE FMV.ID = ' . Comex::OC_PRINCIPAL) !== false
    && strpos($sql, ">= '2025-01-01'") !== false);
chequear('y un valor raro no llega al SQL: vale el inicial', true,
    strpos(Comex::sqlDesdeFechaMov("2025-01-01' OR 1=1 --"), ">= '2025-01-01'") !== false
    && strpos(Comex::sqlDesdeFechaMov("2025-01-01' OR 1=1 --"), 'OR 1=1') === false);

$cuerpoPadron = substr($srcComex, strpos($srcComex, 'public function filtroPadron() {'));
$cuerpoPadron = substr($cuerpoPadron, 0, strpos($cuerpoPadron, "\n    }"));
chequear('filtroPadron() lo usa', true,
    strpos($cuerpoPadron, 'self::sqlDesdeFechaMov($this->fechaMovDesdeVigente())') !== false);
chequear('Compras Proyectadas descuenta sobre ese mismo padron', true,
    strpos($srcDatos, 'WHERE " . $this->comex->filtroPadron();') !== false);

$cuerpoCrono = substr($srcComex, strpos($srcComex, 'public function getCronoNacionalizacion('));
$cuerpoCrono = substr($cuerpoCrono, 0, strpos($cuerpoCrono, 'ORDER BY'));
chequear('getCronoNacionalizacion() usa el mismo', true,
    strpos($cuerpoCrono, 'self::sqlDesdeFechaMov($this->fechaMovDesdeVigente())') !== false);
chequear('y nadie mas escribe el corte a mano', 1,
    substr_count($srcComex . $srcDatos, 'FMV.FECHA_MOV'));

seccion('lo que queda afuera no se avisa');

/* Ni en las pestanas ni en el tablero: esa informacion no se considera. El
   aviso de que falta el script va solo en Parametros. */
$srcCtl = file_get_contents(__DIR__ . '/../Controller/ComexController.php');
$srcProv = file_get_contents(__DIR__ . '/../Class/Providers/ComexProvider.php');
chequear('ni el controller ni el proveedor hablan de la fecha de movimiento', 0,
    preg_match_all('/fechaMovDesde|FECHA_MOV/', $srcCtl . $srcProv));
chequear('Parametros dice que script falta', true,
    strpos(file_get_contents(__DIR__ . '/../Js/Parametros-Compras_proy.js'),
        'Corré sql/cashflow_comex_fecha_mov_desde.sql contra la base central.') !== false);
