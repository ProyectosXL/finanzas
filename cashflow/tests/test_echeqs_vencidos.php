<?php
/**
 * Echeqs: los cheques vencidos en cartera y el corrimiento al dia habil.
 *
 * Lo delicado son CRITERIOS: que es un vencido, cuanto dura la ventana, que
 * fecha se puede asignar, donde cae cada cheque y en que serie. Viven en
 * helpers estaticos puros y se verifican aca sin base, con la fecha de hoy y
 * el calendario inyectados para que las pruebas no caduquen.
 *
 * Nada de este archivo escribe en la base.
 */

require_once __DIR__ . '/../Class/Echeqs.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

// Sin los CR: git puede bajar el script con fin de linea de Windows.
$SQL_VENC = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_echeqs_vencidos.sql'));
$srcEcheqsV = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/Echeqs.php'));

/* ================================================================
   El script
   ================================================================ */
seccion('el script de los vencidos');

foreach (['ID_SBA14', 'FECHA_ACREDITACION', 'OBSERVACION', 'VIGENTE', 'ID_REEMPLAZA',
          'USUARIO_ALTA', 'FECHA_ALTA', 'USUARIO_MODIF', 'FECHA_MODIF',
          'USUARIO_BAJA', 'FECHA_BAJA'] as $col) {
    chequear('la fecha asignada guarda ' . $col, 1, preg_match('/\n\s+' . $col . '\s+[A-Z]/', $SQL_VENC));
}

chequear('crea la tabla si no existe: reejecutable', 1,
    substr_count($SQL_VENC, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION', 'U') IS NULL"));
chequear('una sola fecha vigente por cheque: indice unico filtrado', 1,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_ECHEQFAC_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION \(ID_SBA14\)\s+WHERE VIGENTE = 1/', $SQL_VENC));
chequear('sin FK contra dbo.SBA14: es de Tango', false,
    strpos($SQL_VENC, 'REFERENCES dbo.SBA14') !== false);
chequear('ni bajas fisicas', false,
    stripos($SQL_VENC, 'DROP TABLE') !== false || stripos($SQL_VENC, 'DELETE ') !== false);
chequear('siembra la ventana en 30, en el modulo PRECHEQUEADO', true,
    strpos($SQL_VENC, "''echeqs_dias_vencidos_cartera'', ''30''") !== false
    && strpos($SQL_VENC, "('echeqs_dias_vencidos_cartera', '30'") !== false
    && strpos($SQL_VENC, "''DEPOSITO'', ''PRECHEQUEADO''") !== false);
chequear('y si ya existe no le pisa el valor', true,
    strpos($SQL_VENC, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'echeqs_dias_vencidos_cartera')") !== false
    && strpos($SQL_VENC, 'UPDATE dbo.RO_T_CASHFLOW_PARAMETROS') === false);
chequear('controla que la ventana sea de 1 a 30', 1,
    substr_count($SQL_VENC, 'NOT BETWEEN 1 AND 30'));
chequear('Echeqs nombra la tabla y el script del archivo', [true, true], [
    strpos($SQL_VENC, Echeqs::TABLA_FECHA_ACREDITACION) !== false,
    file_exists(__DIR__ . '/../' . Echeqs::SCRIPT_VENCIDOS)
]);

/* ================================================================
   La ventana: Parametros -> Cheques
   ================================================================ */
seccion('la ventana de los vencidos');

chequear('1 es una ventana valida', 1, Echeqs::validarVentana('1'));
chequear('30 tambien: el plazo legal', 30, Echeqs::validarVentana(30));
chequearLanza('cero no', function () { Echeqs::validarVentana(0); });
chequearLanza('31 no', function () { Echeqs::validarVentana('31'); });
chequearLanza('vacia no', function () { Echeqs::validarVentana(''); });
chequearLanza('un texto no', function () { Echeqs::validarVentana('treinta'); });
chequearLanza('ni un decimal', function () { Echeqs::validarVentana('10.5'); });

$v = Echeqs::ventanaDesdeValor(null);
chequear('sin el parametro, la ventana es null: no hay un 30 de respaldo', null, $v['dias']);
chequear('y el motivo nombra el script', true, strpos($v['error'], Echeqs::SCRIPT_VENCIDOS) !== false);

$v = Echeqs::ventanaDesdeValor('40');
chequear('una ventana rota en la base tambien es null', null, $v['dias']);
chequear('y dice donde corregirla', true, strpos($v['error'], 'Parámetros → Cheques') !== false);

chequear('una ventana bien guardada se usa', 10, Echeqs::ventanaDesdeValor('10')['dias']);

chequear('Parametros y Echeqs nombran la misma clave de la ventana',
    Echeqs::CLAVE_VENTANA, Parametros::CLAVE_VENTANA_ECHEQS);

$srcParametrosV = file_get_contents(__DIR__ . '/../Class/Parametros.php');
chequear('saveParametro() no guarda la ventana: tiene su propio camino, que valida', true,
    strpos($srcParametrosV, 'if ($clave === self::CLAVE_VENTANA_ECHEQS)') !== false);

$bloqueGuardarV = substr($srcEcheqsV, strpos($srcEcheqsV, 'public function guardarVentana('), 1400);
chequear('guardarVentana() valida y escribe con el usuario de escritura', true,
    strpos($bloqueGuardarV, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);') !== false
    && strpos($bloqueGuardarV, '$dias = self::validarVentana($dias);') !== false
    && strpos($bloqueGuardarV, 'Auditoria::SET_MODIF') !== false);

chequear('saveDiasVencidosEcheq pide editar Parametros -> Cheques', [['parametros', 'PRECHEQUEADO']],
    AuthCashflow::ESCRITURAS['Parametros']['saveDiasVencidosEcheq']);
