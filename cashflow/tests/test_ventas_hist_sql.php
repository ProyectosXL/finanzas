<?php
/**
 * El historico de ventas: los dos SP que lo cargan
 * (sql/SJ_CASHFLOW_VENTAS_HIST.sql y sql/RO_SP_CASHFLOW_VENTAS_HIST_DIA.sql)
 * y la tabla de lo que queda fuera (sql/cashflow_ventas_excluidas.sql).
 *
 * SE PRUEBA LEYENDO LOS SCRIPTS, porque la base es viva y el DDL lo corre el
 * usuario. Lo que se verifica es lo que hace que la venta cierre contra el
 * libro IVA ventas de contabilidad:
 *
 *   libro (neto + no gravado + exento) = venta del cashflow + excluidas
 *
 * Eso se sostiene solo si (1) los dos SP calculan la venta igual, (2) los
 * canales son los acordados y (3) la parte E es el complemento EXACTO de los
 * canales: un cliente que no esta en un lado tiene que estar en el otro.
 */

$leerSql = function ($archivo) {
    return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../sql/' . $archivo));
};

$mensual = $leerSql('SJ_CASHFLOW_VENTAS_HIST.sql');
$diario  = $leerSql('RO_SP_CASHFLOW_VENTAS_HIST_DIA.sql');
$excl    = $leerSql('cashflow_ventas_excluidas.sql');

/** Sin los comentarios: lo que de verdad corre */
$sinComentarios = function ($sql) {
    return preg_replace('#/\*.*?\*/#s', '', $sql);
};

/**
 * El SELECT de una parte (A, C, D, E): desde su cabecera hasta el ';'. Se
 * normalizan las diferencias deliberadas entre los dos SP -el nombre de la
 * temporal y el rango, que en el mensual se expande a meses completos- para
 * que lo que quede sea la regla de negocio.
 */
$parte = function ($sql, $letra) use ($sinComentarios) {
    // La cabecera "PARTE X:" vive en un comentario: se ubica en el texto
    // original y los comentarios se sacan recien del cuerpo.
    $i = strpos($sql, 'PARTE ' . $letra . ':');

    if ($i === false) {
        return '';
    }

    $desde = strpos($sql, '*/', $i) + 2;
    $cuerpo = $sinComentarios(substr($sql, $desde, strpos($sql, ';', $desde) - $desde));
    $cuerpo = substr($cuerpo, strpos($cuerpo, 'SELECT'));
    $cuerpo = str_replace(['@DesdeMes', '@HastaMes'], ['@Desde', '@Hasta'], $cuerpo);

    return preg_replace('/\s+/', ' ', trim($cuerpo));
};

$codMensual = $mensual;
$codDiario  = $diario;

seccion('los dos SP calculan la venta igual');

foreach (['A', 'C', 'D'] as $p) {
    $m = $parte($codMensual, $p);
    chequear('la parte ' . $p . ' esta en el mensual', true, $m !== '');
    chequear('la parte ' . $p . ' es la misma en el diario', $m, $parte($codDiario, $p));
}

chequear('la parte E (excluidas) es solo del mensual', ['', true],
    [$parte($codDiario, 'E'), $parte($codMensual, 'E') !== '']);

seccion('los canales');

$a = $parte($codMensual, 'A');

chequear('FR es Franquicias', true, strpos($a, "WHEN A.COD_CLIENT LIKE 'FR%' THEN 'FRANQUICIAS'") !== false);
chequear('L (XL Lomas) es Franquicias', true, strpos($a, "WHEN A.COD_CLIENT LIKE 'L%' THEN 'FRANQUICIAS'") !== false);
chequear('MA es Mayoristas', true, strpos($a, "WHEN A.COD_CLIENT LIKE 'MA%' THEN 'MAYORISTAS'") !== false);
chequear('el WHERE toma exactamente esos tres prefijos', true,
    strpos($a, "AND (A.COD_CLIENT LIKE 'FR%' OR A.COD_CLIENT LIKE 'L%' OR A.COD_CLIENT LIKE 'MA%')") !== false);
chequear('ya no queda el [FM][RA] que dejaba afuera a LALOMA', 0, preg_match('/\[FM\]\[RA\]/', $a));
chequear('se conservan los filtros de articulo: los kits (PROMOCION = 1) duplicarian a sus componentes',
    [true, true], [strpos($a, "B.COD_ARTICU LIKE '[XO*]%'") !== false, strpos($a, 'B.PROMOCION = 0') !== false]);

seccion('ecommerce: neto del renglon, con el signo de la NC');

$d = $parte($codMensual, 'D');

chequear('usa IMP_NETO_P', true,
    strpos($d, "CASE WHEN A.T_COMP LIKE 'NC%' THEN B.IMP_NETO_P * -1 ELSE B.IMP_NETO_P END") !== false);
chequear('ya no usa PRECIO_NET: es antes de la bonificacion', 0, preg_match('/PRECIO_NET/', $d));
chequear("el importe ya no tiene un WHEN '*' antes del signo: las NC de envios sumaban", 1,
    substr_count($d, "WHEN B.COD_ARTICU LIKE '*%'"));
chequear('sigue siendo el cliente 000000', true, strpos($d, "A.COD_CLIENT = '000000'") !== false);

seccion('lo que queda fuera: el complemento exacto de los canales');

$e = $parte($codMensual, 'E');

foreach (["NOT LIKE 'FR%'", "NOT LIKE 'L%'", "NOT LIKE 'MA%'", "<> '000000'"] as $cond) {
    chequear('excluye ' . $cond, true, strpos($e, 'A.COD_CLIENT ' . $cond) !== false);
}

chequear('los mismos tipos de comprobante que la parte A', true,
    strpos($e, "A.T_COMP IN ('FAC','NCP','NCR','NDP','NDR')") !== false
    && strpos($a, "A.T_COMP IN ('FAC','NCP','NCR','NDP','NDR')") !== false);
chequear('lee el encabezado, como el libro IVA: sin renglones', 0, preg_match('/GVA53/', $e));
chequear('en pesos: la moneda extranjera se convierte por COTIZ', true,
    strpos($e, 'CASE WHEN A.MON_CTE = 0 THEN A.COTIZ ELSE 1 END') !== false);
chequear('con el signo de la NC', true, strpos($e, "CASE WHEN A.T_COMP LIKE 'NC%' THEN -1 ELSE 1 END") !== false);

$persist = $sinComentarios($mensual);
$persist = substr($persist, strpos($persist, 'BEGIN TRANSACTION'));
$persist = substr($persist, 0, strpos($persist, 'COMMIT TRANSACTION'));

chequear('se graba en la misma transaccion que la venta', true,
    strpos($persist, 'INSERT INTO dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS') !== false);
chequear('reejecutable: borra el mismo rango expandido antes de insertar', true,
    strpos($persist, "DELETE FROM dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS\n                WHERE DATEFROMPARTS(ANIO, MES, 1) BETWEEN @DesdeMes AND @HastaMes") !== false);
chequear('si la tabla no esta, la venta se carga igual', true,
    strpos($persist, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS', 'U') IS NOT NULL") !== false);
chequear('el motivo sale del prefijo, con SIN CANAL para lo desconocido', [1, 1, 1], [
    substr_count($persist, "WHEN 'OT' THEN 'OTROS INGRESOS'"),
    substr_count($persist, "WHEN 'EX' THEN 'EXPORTACION'"),
    substr_count($persist, "ELSE 'SIN CANAL' END"),
]);
chequear('el neto sin IVA es gravado + exento', true,
    strpos($persist, 'SUM(E.NETO_GRAVADO + E.EXENTO)') !== false);

seccion('la tabla de excluidas');

$codExcl = $sinComentarios($excl);

chequear('dice en que base corre', true, strpos($excl, 'Base    : central') !== false);
chequear('se crea solo si no existe', 1,
    substr_count($codExcl, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_EXCLUIDAS', 'U') IS NULL"));
chequear('sin DROP, DELETE ni cargas: nace vacia y la llena el SP', 0,
    preg_match('/\b(DROP|DELETE|INSERT|UPDATE|MERGE)\b/i', $codExcl));
chequear('una fila por mes y cliente', true,
    strpos($codExcl, 'PRIMARY KEY CLUSTERED (ANIO, MES, COD_CLIENT)') !== false);
chequear('los mismos motivos que escribe el SP', true,
    strpos($codExcl, "CHECK (MOTIVO IN ('OTROS INGRESOS', 'EXPORTACION', 'SIN CANAL'))") !== false);
chequear('se reescribe en cada corrida: lleva USUARIO_ALTA / FECHA_ALTA, como el historico', [1, 1, 0], [
    preg_match_all('/^\s+USUARIO_ALTA\s/m', $codExcl),
    preg_match_all('/^\s+FECHA_ALTA\s/m', $codExcl),
    preg_match_all('/^\s+(USUARIO_MODIF|FECHA_MODIF|USUARIO_BAJA|FECHA_BAJA)\s/m', $codExcl),
]);
