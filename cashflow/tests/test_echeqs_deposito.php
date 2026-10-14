<?php
/**
 * Echeqs: el deposito masivo de cheques de cartera.
 *
 * Como el resto del modulo, lo delicado son CRITERIOS y no consultas: cuando
 * acredita un deposito, con que tasa, que cheques se pueden depositar juntos,
 * cual se proyecta y en que serie cae cada peso. Viven en helpers estaticos
 * puros y se verifican aca sin base, con la fecha de hoy inyectada para que
 * las pruebas no caduquen.
 *
 * Nada de este archivo escribe en la base.
 */

require_once __DIR__ . '/../Class/Echeqs.php';

// Sin los CR: git puede bajar el script con fin de linea de Windows.
$SQL_DEPOSITO = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_echeqs_deposito.sql'));

/* ================================================================
   El script
   ================================================================ */
seccion('el script del deposito');

chequear('crea la tabla de depositos', true,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_DEPOSITO') !== false);
chequear('y la de alicuotas propia, no filas en la de Cob. Electronicos', true,
    strpos($SQL_DEPOSITO, 'CREATE TABLE dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA') !== false
    && strpos($SQL_DEPOSITO, 'INSERT INTO dbo.RO_T_CASHFLOW_COBEL') === false);
chequear('un solo deposito vigente por cheque: indice unico filtrado', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQDEP_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_DEPOSITO \(ID_SBA14\)\s+WHERE VIGENTE = 1/',
        $SQL_DEPOSITO));

foreach (['ID_SBA14', 'ID_BANCO', 'DESC_BANCO', 'FECHA_DEPOSITO', 'FECHA_ACREDITACION',
          'DIAS_PLAZO', 'IMPORTE_BRUTO', 'TASA_APLICADA', 'IMPORTE_NETO', 'OBSERVACION',
          'VIGENTE', 'USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF',
          'USUARIO_BAJA', 'FECHA_BAJA'] as $col) {
    chequear('el deposito guarda ' . $col, 1,
        preg_match('/\n\s+' . $col . '\s+[A-Z]/', $SQL_DEPOSITO));
}

chequear('la tasa y el neto no pueden quedar en null', 2,
    preg_match_all('/(TASA_APLICADA|IMPORTE_NETO)\s+DECIMAL\([0-9,]+\)\s+NOT NULL/', $SQL_DEPOSITO));
chequear('no siembra ninguna alicuota: las carga Tesoreria', false,
    strpos($SQL_DEPOSITO, 'INSERT INTO dbo.RO_T_CASHFLOW_ECHEQ_ALICUOTA') !== false);
chequear('siembra el plazo en 2', true,
    strpos($SQL_DEPOSITO, "''echeqs_dias_acreditacion_deposito'', ''2''") !== false
    && strpos($SQL_DEPOSITO, "('echeqs_dias_acreditacion_deposito', '2'") !== false);
chequear('en el modulo PRECHEQUEADO, que es el codigo de la sub-pestana Cheques', true,
    strpos($SQL_DEPOSITO, "''DEPOSITO'', ''PRECHEQUEADO''") !== false);
chequear('y si ya existe no le pisa el valor', true,
    strpos($SQL_DEPOSITO, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'echeqs_dias_acreditacion_deposito')") !== false
    && strpos($SQL_DEPOSITO, 'UPDATE dbo.RO_T_CASHFLOW_PARAMETROS') === false);
chequear('es reejecutable: cada tabla pregunta si existe', 2,
    preg_match_all("/IF OBJECT_ID\('dbo\.RO_T_CASHFLOW_ECHEQ_(DEPOSITO|ALICUOTA)', 'U'\) IS NULL/", $SQL_DEPOSITO));
chequear('no tiene bajas fisicas', false, stripos($SQL_DEPOSITO, 'DELETE ') !== false);
