<?php
/**
 * El mix de cobro como arbol: el script que crea la tabla y migra el mix
 * plano (sql/cashflow_ventas_mix_nodo.sql).
 *
 * SE PRUEBA LEYENDO EL SCRIPT, porque la base es viva y el DDL lo corre el
 * usuario. Lo que se verifica es lo que, si se rompe, rompe el invariante de
 * la migracion -"apenas se publica, la proyeccion da exactamente lo mismo"- o
 * la vuelve peligrosa de reejecutar: que un canal se migre una sola vez, que
 * Go Cuotas quede inhabilitado, que Vtex entre al 100% y sin dias, y que el
 * mix plano no se toque. El circuito completo se corrio contra #temporales
 * antes de publicar (ver el commit).
 */

$scriptNodo = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_ventas_mix_nodo.sql'));

/** El texto entre dos marcas del script */
function tramoNodo($script, $desde, $hasta = null) {
    $i = strpos($script, $desde);

    if ($i === false) {
        return '';
    }

    $j = $hasta === null ? false : strpos($script, $hasta, $i + strlen($desde));

    return $j === false ? substr($script, $i) : substr($script, $i, $j - $i);
}

seccion('la tabla: se crea una sola vez y con sus reglas');

chequear('se crea solo si no existe', 1,
    substr_count($scriptNodo, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'U') IS NULL"));
chequear('el nombre es unico entre hermanos, no en el canal', 1,
    substr_count($scriptNodo, 'UNIQUE (CANAL, ID_PADRE, NOMBRE)'));
chequear('el padre es un nodo de la misma tabla', 1,
    substr_count($scriptNodo, 'FOREIGN KEY (ID_PADRE) REFERENCES dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO (ID)'));
chequear('el CHECK de NIVEL tiene los cinco niveles, en orden', 1,
    substr_count($scriptNodo,
        "CHECK (NIVEL IN ('MARKETPLACE', 'MEDIO_PAGO', 'TIPO_TARJETA', 'PROCESADORA', 'CUOTAS'))"));
chequear('costo y tasa admiten NULL (vale cero)', [1, 1], [
    substr_count($scriptNodo, 'CHECK (COSTO IS NULL OR (COSTO >= 0 AND COSTO <= 1))'),
    substr_count($scriptNodo, 'CHECK (TASA IS NULL OR (TASA >= 0 AND TASA <= 1))')
]);
chequear('los dias admiten NULL (los define un nivel superior)', 1,
    substr_count($scriptNodo, 'CHECK (DIAS_ACREDITACION IS NULL OR DIAS_ACREDITACION >= 0)'));
chequear('lo nuevo entra inhabilitado por defecto', 1,
    substr_count($scriptNodo, 'ACTIVO            BIT           NOT NULL CONSTRAINT DF_CF_VTA_MIXN_ACTIVO DEFAULT (0)'));
chequear('lleva las seis columnas de auditoria', 6, preg_match_all(
    '/^\s+(USUARIO_ALTA|FECHA_ALTA|USUARIO_MODIF|FECHA_MODIF|USUARIO_BAJA|FECHA_BAJA)\s/m',
    tramoNodo($scriptNodo, 'CREATE TABLE dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO', 'PRINT')));

seccion('la migracion: una sola vez por canal');

$migracion = tramoNodo($scriptNodo, '2. MIGRACION DEL MIX PLANO');

chequear('va en una transaccion', [1, 1], [
    substr_count($migracion, 'BEGIN TRANSACTION;'),
    substr_count($migracion, 'COMMIT TRANSACTION;')
]);
chequear('Locales, Franquicias y Mayoristas: solo si el canal no tiene nodos', 1,
    substr_count($migracion,
        'AND NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO n WHERE n.CANAL = m.CANAL);'));
chequear('Ecommerce: solo si no tiene nodos', 1, substr_count($migracion,
    "IF EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_VENTAS_MIX_NODO WHERE CANAL = 'ECOMMERCE')"));
chequear('Ecommerce se migra aparte de los otros tres', 1,
    substr_count($migracion, "WHERE m.CANAL <> 'ECOMMERCE'"));

seccion('la migracion: que queda de cada medio');

chequear('Cash pasa a llamarse Efectivo', 2,
    substr_count($migracion, "WHEN 'CASH'          THEN 'Efectivo'"));
chequear('los demas, con mayuscula inicial', [2, 2, 2, 2], [
    substr_count($migracion, "THEN 'Tarjeta'"),
    substr_count($migracion, "THEN 'Go Cuotas'"),
    substr_count($migracion, "THEN 'Transferencia'"),
    substr_count($migracion, "THEN 'Echeq'")
]);
chequear('Go Cuotas queda inhabilitado en los dos INSERT', 2, substr_count($migracion,
    "CASE WHEN m.MEDIO_PAGO = 'GO CUOTAS' THEN 0 ELSE m.ACTIVO END"));
chequear('y si estaba activo con porcentaje, se avisa', 1, substr_count($migracion,
    "WHERE m.MEDIO_PAGO = 'GO CUOTAS' AND m.ACTIVO = 1 AND m.PORCENTAJE > 0"));
chequear('los medios son nodos de MEDIO_PAGO con su porcentaje y sus dias', 2, substr_count($migracion,
    "m.PORCENTAJE, NULL, NULL, m.DIAS_ACREDITACION,"));
chequear('cada nodo migrado guarda de que fila vino', 2, substr_count($migracion, 'm.ORDEN, m.ID'));

seccion('la migracion: Vtex al 100% y sin dias');

chequear('Vtex es MARKETPLACE, al 100%, sin costo, sin tasa, sin dias y activo', 1,
    substr_count($migracion, "SELECT 'ECOMMERCE', NULL, 'MARKETPLACE', 'Vtex', 1, NULL, NULL, NULL,\n               1,"));
chequear('los medios de Ecommerce cuelgan de Vtex', 1,
    substr_count($migracion, "SELECT m.CANAL, @vtex, 'MEDIO_PAGO',"));

seccion('el mix plano no se toca');

chequear('ni DELETE, ni UPDATE, ni DROP sobre RO_T_CASHFLOW_VENTAS_MIX', 0, preg_match_all(
    '/(DELETE\s+FROM|UPDATE|DROP\s+TABLE)\s+dbo\.RO_T_CASHFLOW_VENTAS_MIX\b(?!_NODO)/', $scriptNodo));
chequear('ni sobre la tabla nueva: la migracion solo inserta', 0, preg_match_all(
    '/(DELETE\s+FROM|UPDATE|DROP\s+TABLE)\s+dbo\.RO_T_CASHFLOW_VENTAS_MIX_NODO/', $scriptNodo));
chequear('sin un JOIN: los cruces del modulo van en PHP', 0, preg_match_all('/\bJOIN\b/', $scriptNodo));

/* ============================================================================
   LA FILA DEL TABLERO: sql/cashflow_estructura_costos_cobro.sql
   El circuito completo se corrio contra #temporales copiadas de las tablas
   de estructura (ver el commit): aca se verifica lo que no puede cambiar.
   ============================================================================ */

require_once __DIR__ . '/../Class/CashflowRegistry.php';

$scriptFila = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_estructura_costos_cobro.sql'));

seccion('la fila de costos: ingreso, computa y apunta a COSTO_COBRO');

chequear('INGRESO y computando, en la seccion VENTAS, con la serie del proveedor', 1, substr_count($scriptFila,
    "('COSTOS_COBRO', 'Costos de cobro (comisiones y tasas)', 'VENTAS', 'INGRESO', 1,\n                    'VENTAS', 'COSTO_COBRO', @orden)"));
chequear('la serie existe en el registro', true, CashflowRegistry::serieExiste('VENTAS', 'COSTO_COBRO'));
chequear('nunca como EGRESO: el importe ya viene negativo', 0,
    preg_match_all("/\('COSTOS_COBRO'[^)]*'EGRESO'/", $scriptFila));

seccion('la fila de costos: reejecutable y sin pisar lo editado');

chequear('MERGE sobre CODIGO', 2, substr_count($scriptFila, 'ON T.CODIGO = S.CODIGO'));
chequear('si ya existe, solo reafirma el origen', 1, substr_count($scriptFila,
    "UPDATE SET ORIGEN_PROVIDER = S.ORIGEN_PROVIDER,\n                   ORIGEN_SERIE = S.ORIGEN_SERIE,"));
chequear('ni NOMBRE ni ORDEN en ningun UPDATE', 0,
    preg_match_all('/UPDATE SET[^;]*\b(NOMBRE|ORDEN)\s*=/s', $scriptFila));

seccion('la fila de costos: el orden se calcula entre Ecommerce y Total Ventas');

chequear('lee el orden real de las dos filas', [1, 1], [
    substr_count($scriptFila, "WHERE CODIGO = 'VTA_ECOMMERCE' AND SECCION = 'VENTAS';"),
    substr_count($scriptFila, "WHERE CODIGO = 'SUB_INGRESOS_VENTA' AND SECCION = 'VENTAS';")
]);
chequear('entra en el medio del hueco', 1,
    substr_count($scriptFila, 'SET @orden = @ecommerce + (@subtotal - @ecommerce) / 2;'));
chequear('sin hueco, no inserta', 1, substr_count($scriptFila, 'ELSE IF @subtotal - @ecommerce < 2'));
chequear('con el orden ocupado, tampoco', 1,
    substr_count($scriptFila, "WHERE SECCION = 'VENTAS' AND ORDEN = @orden)"));

seccion('la fila de costos: no se agrupa');

chequear('no declara GRUPO ni NATURALEZA', 0, preg_match_all('/\b(GRUPO|NATURALEZA)\s*=/', $scriptFila));
chequear('y dice por que: cerrado repetiria Total Ventas', 1,
    substr_count($scriptFila, 'renglon cerrado daria exactamente "Total Ventas"'));
