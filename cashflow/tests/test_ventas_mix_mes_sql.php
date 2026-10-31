<?php
/**
 * El mix de cobro por mes: el script que crea sus tres tablas
 * (sql/cashflow_ventas_mix_mes.sql).
 *
 * SE PRUEBA LEYENDO EL SCRIPT, porque la base es viva y el DDL lo corre el
 * usuario. Lo que se verifica es lo que, si se rompe, rompe el invariante -
 * "apenas se publica, con todo en constante, la proyeccion da exactamente lo
 * mismo"- o lo vuelve peligroso de reejecutar: que nada se borre ni se pise,
 * que las tablas nazcan vacias y que tengan su auditoria.
 */

$scriptMes = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_ventas_mix_mes.sql'));

/** El CREATE TABLE de una tabla, hasta su PRINT */
function createMes($script, $tabla) {
    $i = strpos($script, 'CREATE TABLE dbo.' . $tabla . ' (');

    if ($i === false) {
        return '';
    }

    return substr($script, $i, strpos($script, 'PRINT', $i) - $i);
}

/** Sin los comentarios: lo que de verdad corre */
$codigoMes = preg_replace('#/\*.*?\*/#s', '', $scriptMes);

$tablasMes = ['RO_T_CASHFLOW_VENTAS_MIX_CANAL', 'RO_T_CASHFLOW_VENTAS_MIX_MES',
              'RO_T_CASHFLOW_VENTAS_MIX_NODO_MES'];

seccion('el script: reejecutable y sin tocar nada de lo que hay');

chequear('dice en que base corre', true, strpos($scriptMes, 'Base    : central') !== false);

foreach ($tablasMes as $t) {
    chequear($t . ' se crea solo si no existe', 1,
        substr_count($codigoMes, "IF OBJECT_ID('dbo." . $t . "', 'U') IS NULL"));
    chequear($t . ' lleva las seis columnas de auditoria', 6, preg_match_all(
        '/^\s+(USUARIO_ALTA|FECHA_ALTA|USUARIO_MODIF|FECHA_MODIF|USUARIO_BAJA|FECHA_BAJA)\s/m',
        createMes($scriptMes, $t)));
}

chequear('sin DROP', 0, preg_match('/\bDROP\b/i', $codigoMes));
chequear('sin DELETE: no hay bajas fisicas', 0, preg_match('/\bDELETE\b/i', $codigoMes));
chequear('sin INSERT ni UPDATE: las tablas nacen vacias, y vacias no cambian la proyeccion', 0,
    preg_match('/\b(INSERT|UPDATE|MERGE)\b/i', $codigoMes));
chequear('no toca la tabla del arbol: solo la referencia', 0,
    preg_match('/ALTER TABLE dbo\.RO_T_CASHFLOW_VENTAS_MIX_NODO\b/i', $codigoMes));
chequear('corta si falta el arbol, que es a donde apuntan las FK', true,
    strpos($codigoMes, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NULL\nBEGIN\n    RAISERROR") !== false);
chequear('y vuelve a ejecutar al final', true, strpos($codigoMes, 'SET NOEXEC OFF;') !== false);

seccion('el modo de cada canal');

$canalDdl = createMes($scriptMes, 'RO_T_CASHFLOW_VENTAS_MIX_CANAL');

chequear('uno por canal', true, strpos($canalDdl, 'PRIMARY KEY CLUSTERED (CANAL)') !== false);
chequear('constante o variable', true, strpos($canalDdl, "CHECK (MODO IN ('CONSTANTE', 'VARIABLE'))") !== false);
chequear('por defecto, constante', true, strpos($canalDdl, "DEFAULT ('CONSTANTE')") !== false);

seccion('los meses y sus valores');

$mesDdl = createMes($scriptMes, 'RO_T_CASHFLOW_VENTAS_MIX_MES');
$valDdl = createMes($scriptMes, 'RO_T_CASHFLOW_VENTAS_MIX_NODO_MES');

chequear('mes del anio, no mes puntual: de 1 a 12 y sin anio', [true, false],
    [strpos($mesDdl, 'CHECK (MES BETWEEN 1 AND 12)') !== false, strpos($mesDdl, 'ANIO') !== false]);
chequear('un mes por canal, sin mirar ACTIVO: darlo de baja y recargarlo reactiva la misma fila', true,
    strpos($mesDdl, 'UNIQUE (CANAL, MES)') !== false);
chequear('un valor por nodo y mes', true, strpos($valDdl, 'UNIQUE (ID_MIX_MES, ID_NODO)') !== false);
chequear('los valores apuntan al nodo del arbol: la estructura no se duplica', true,
    strpos($valDdl, 'FOREIGN KEY (ID_NODO) REFERENCES dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO (ID)') !== false);
chequear('ni nombre, ni nivel, ni padre en el mes', 0,
    preg_match('/^\s+(NOMBRE|NIVEL|ID_PADRE|ORDEN)\s/m', $valDdl));
chequear('los mismos rangos que el arbol', [1, 1, 1, 1], [
    substr_count($valDdl, 'CHECK (PORCENTAJE >= 0 AND PORCENTAJE <= 1)'),
    substr_count($valDdl, 'CHECK (COSTO IS NULL OR (COSTO >= 0 AND COSTO <= 1))'),
    substr_count($valDdl, 'CHECK (TASA IS NULL OR (TASA >= 0 AND TASA <= 1))'),
    substr_count($valDdl, 'CHECK (DIAS_ACREDITACION IS NULL OR DIAS_ACREDITACION >= 0)')
]);
chequear('activo varia por mes', 1, preg_match('/^\s+ACTIVO\s+BIT/m', $valDdl));
