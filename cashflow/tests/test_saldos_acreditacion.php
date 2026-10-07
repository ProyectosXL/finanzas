<?php
/**
 * Saldos: el dia de acreditacion de cada local.
 *
 * Cada local propio acredita -o envia- su efectivo un dia fijo de la semana, y
 * la fila Caja Locales del tablero imputa el aporte de cada uno en su PROXIMA
 * FECHA DE ACREDITACION. Lo delicado es el calendario -hoy es el dia, el dia es
 * feriado, feriados encadenados, cruce de mes y de ano, una fecha fuera de
 * RO_T_CALENDARIO- y que la serie del tablero reparta el mismo total que antes
 * en otras columnas. Todo vive en helpers estaticos puros de Saldos y se prueba
 * aca sin base; hoy se inyecta, asi que las pruebas no caducan.
 *
 * La ultima seccion toca la base y se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../Class/Saldos.php';

/* ================================================================
   El script
   ================================================================ */
seccion('el script del dia de acreditacion');

$scriptDA = file_get_contents(__DIR__ . '/../sql/cashflow_saldos_dia_acreditacion.sql');
$completoDA = file_get_contents(__DIR__ . '/../sql/cashflow_saldos.sql');

chequear('agrega el dia al parametro del local', 1, preg_match(
    "/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_SUCURSAL\s+ADD DIA_ACREDITACION TINYINT NULL/",
    $scriptDA));
chequear('con un CHECK de lunes a viernes', 2,
    substr_count($scriptDA, 'CHECK (DIA_ACREDITACION BETWEEN 1 AND 5)'));
chequear('y la foto guarda el dia y la fecha', [1, 1], [
    preg_match("/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_LOCAL\s+ADD DIA_ACREDITACION TINYINT NULL/",
        $scriptDA),
    preg_match("/ALTER TABLE dbo\.RO_T_CASHFLOW_SALDOS_LOCAL\s+ADD FECHA_ACREDITACION DATE NULL/",
        $scriptDA)
]);

// Sin default, a proposito: un default pondria a todos los locales en el
// mismo dia y esconderia que falta cargarlo.
chequear('el dia no tiene DEFAULT', 0, preg_match('/DEFAULT/i',
    preg_replace('/\/\*.*?\*\//s', '', $scriptDA)));
chequear('es reejecutable: cada columna se agrega solo si falta', 3,
    preg_match_all("/COL_LENGTH\('dbo\.RO_T_CASHFLOW_SALDOS_(SUCURSAL|LOCAL)', "
        . "'(DIA|FECHA)_ACREDITACION'\) IS NULL/", $scriptDA));
chequear('y no borra ni pisa datos', 0,
    preg_match('/\b(DELETE|DROP|UPDATE|TRUNCATE)\b/i', preg_replace('/\/\*.*?\*\//s', '', $scriptDA)));
chequear('el mismo bloque va dentro de cashflow_saldos.sql', [1, 1, 1], [
    preg_match_all('/ADD DIA_ACREDITACION TINYINT NULL\s+CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_SUCURSAL_DIA/',
        $completoDA),
    preg_match_all('/ADD DIA_ACREDITACION TINYINT NULL\s+CONSTRAINT CK_RO_T_CASHFLOW_SALDOS_LOCAL_DIA/',
        $completoDA),
    substr_count($completoDA, 'ADD FECHA_ACREDITACION DATE NULL')
]);
