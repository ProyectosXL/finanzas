<?php
/**
 * El corte de fin de semana del mix de cobro: el script que agrega la columna
 * y deja Fiserv en "Desde el viernes" (sql/cashflow_ventas_mix_corte_finde.sql).
 *
 * SE PRUEBA LEYENDO EL SCRIPT, porque la base es viva y el DDL lo corre el
 * usuario. Lo que se verifica es lo que lo vuelve peligroso de reejecutar si
 * se rompe: que no pise un corte ya cargado desde Parametros, que no borre
 * nada, que deje la auditoria, y que su lista de valores sea la del codigo.
 */

require_once __DIR__ . '/../Class/MixCobro.php';

$scriptCorte = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_ventas_mix_corte_finde.sql'));

/** Sin los comentarios de bloque: lo que de verdad corre */
$codigoCorte = preg_replace('#/\*.*?\*/#s', '', $scriptCorte);

seccion('el script del corte: reejecutable y sin pisar lo cargado');

chequear('dice en que base corre', true, strpos($scriptCorte, 'Base    : central') !== false);
chequear('la columna se agrega solo si no existe', 1,
    substr_count($codigoCorte, "AND COL_LENGTH('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'CORTE_FINDE') IS NULL"));
chequear('nullable y sin default: un default seria un corte que nadie eligio', true,
    strpos($codigoCorte, 'ADD CORTE_FINDE VARCHAR(10) NULL;') !== false);
chequear('el CHECK se crea solo si no existe', true,
    strpos($codigoCorte, "WHERE name = 'CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_CORTE')") !== false);
chequear('sin DROP', 0, preg_match('/\bDROP\b/i', $codigoCorte));
chequear('sin DELETE: no hay bajas fisicas', 0, preg_match('/\bDELETE\b/i', $codigoCorte));
chequear('sin INSERT en el arbol: no crea nodos', 0,
    preg_match('/INSERT\s+INTO\s+dbo\.RO_T_CASHFLOW_VENTAS_MIX_NODO\b/i', $codigoCorte));
chequear('un solo UPDATE', 1, preg_match_all('/\bUPDATE\b/i', $codigoCorte));
chequear('no toca las tablas del mix por mes: el corte no se abre por mes', 0,
    preg_match('/RO_T_CASHFLOW_VENTAS_MIX_(NODO_MES|MES|CANAL)\b/', $codigoCorte));

seccion('el CHECK y MixCobro::CORTES cambian juntas');

preg_match("/CORTE_FINDE IN \(([^)]*)\)/", $codigoCorte, $m);
chequear('la lista del CHECK es la del codigo', array_keys(MixCobro::CORTES),
    isset($m[1]) ? array_map(function ($s) { return trim($s, " '"); }, explode(',', $m[1])) : []);

seccion('Fiserv en "Desde el viernes"');

$update = '';

if (preg_match('/UPDATE dbo\.RO_T_CASHFLOW_VENTAS_MIX_NODO.*?;/s', $codigoCorte, $u)) {
    $update = $u[0];
}

chequear('pone VIERNES', true, strpos($update, "SET CORTE_FINDE = ''VIERNES''") !== false);
chequear('solo en los nodos de nivel Procesadora', true, strpos($update, "WHERE NIVEL = ''PROCESADORA''") !== false);
chequear('que se llamen Fiserv, sin espacios de mas (mayusculas y acentos los ignora la collation)', true,
    strpos($update, "AND LTRIM(RTRIM(NOMBRE)) = ''Fiserv''") !== false);
chequear('SIN PISAR un corte ya cargado', true, strpos($update, 'AND CORTE_FINDE IS NULL;') !== false);
chequear('en cualquier canal y activo o no: no filtra por CANAL ni por ACTIVO', [false, false],
    [strpos($update, 'CANAL') !== false, strpos($update, 'ACTIVO') !== false]);
chequear('la auditoria: el nombre del script y cuando corre', [true, true, true],
    [strpos($codigoCorte, "@Usuario VARCHAR(50) = ''sql/cashflow_ventas_mix_corte_finde.sql''") !== false,
     strpos($update, 'USUARIO_MODIF = @Usuario') !== false,
     strpos($update, 'FECHA_MODIF = GETDATE()') !== false]);
chequear('el nombre del script entra en USUARIO_MODIF (50)', true,
    strlen('sql/cashflow_ventas_mix_corte_finde.sql') <= 50);
chequear('dice que nodos toco', true, strpos($update, 'OUTPUT INSERTED.ID INTO @Tocados') !== false
    && strpos($codigoCorte, 'PRINT @Linea;') !== false);
chequear('el nombre del script es el que nombra la pantalla', 'sql/cashflow_ventas_mix_corte_finde.sql',
    MixCobro::SCRIPT_CORTE);
