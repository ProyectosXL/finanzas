<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Cotizacion.php';

/**
 * OtrosIngresos
 * Los ingresos que no vienen de ningun circuito del sistema y los carga una
 * persona. Hoy hay uno solo: los dolares de la cuenta comitente.
 *
 * POR QUE ES UNA CATEGORIA Y NO UNA PESTANA MAS DE INGRESOS
 * ---------------------------------------------------------
 * Ingresos agrupa lo que sale de un circuito -ventas, cobranzas, echeqs-, y lo
 * de aca se tipea. La diferencia importa a la hora de leer un numero: en una
 * fila de Ingresos un cero significa "no hay movimientos", y en una de aca
 * significa "nadie cargo nada todavia". La categoria queda armada para que
 * sumar un concepto nuevo sea agregar una pestana y no rediseniar nada.
 *
 * SE GUARDAN DOLARES, NO PESOS
 * ----------------------------
 * La conversion la hace el proveedor con el oficial del BCRA, igual que
 * ComexProvider. Guardar pesos congelaria la valuacion al momento de la carga:
 * el dia que cambie el tipo de cambio, el tablero seguiria mostrando la
 * conversion vieja y no habria forma de notarlo.
 *
 * EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA
 * ---------------------------------------------------
 * Cargar una fecha que ya existe NO hace UPDATE: marca VIGENTE = 0 las
 * anteriores de esa fecha e inserta una fila nueva, en UNA transaccion. Nunca
 * hay baja fisica, igual que en el resto del modulo.
 *
 * El historial no es decoracion: es lo unico que explica por que el numero de
 * ayer era otro. Con un UPDATE, corregir un dedazo y cargar un dato nuevo son
 * indistinguibles despues del hecho.
 *
 * SI LA TABLA NO ESTA, NO SE ROMPE
 * --------------------------------
 * Se avisa y se devuelve vacio, igual que hace Echeqs con su maestro. La fila
 * del tablero muestra cero, que es el comportamiento correcto: la fila existe y
 * dice cero hasta que haya datos.
 */
class OtrosIngresos {

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache de tablaCreada() */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si ya se corrio sql/cashflow_dolares_comitente.sql.
     *
     * @return bool
     */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_DOLARES_COMITENTE', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de dólares'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en la pestana.
     *
     * @return array
     */
    public function getAvisos() {
        $avisos = [];

        if (!$this->tablaCreada()) {
            $avisos[] = 'Todavía no existe la tabla de dólares en cuenta comitente. '
                . 'Corré sql/cashflow_dolares_comitente.sql contra la base central. '
                . 'Mientras tanto, la fila del tablero se muestra en cero.';

            return $avisos;
        }

        if (empty($this->getDolaresComitente())) {
            $avisos[] = 'Todavía no hay ninguna carga. La fila del tablero muestra cero, '
                . 'que es lo correcto: no es que falte el dato, es que no hay dólares '
                . 'informados.';
        }

        return $avisos;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Los importes VIGENTES por fecha, en dolares.
     *
     * Es lo que consumen la grilla y el proveedor. Las cargas pisadas no salen
     * de aca: para verlas esta getHistorialFecha().
     *
     * @return array Filas ['FECHA', 'IMPORTE_USD', 'USUARIO', 'FECHA_ALTA', 'VERSIONES']
     */
    public function getDolaresComitente() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $cid = $this->conectar();

        /* VERSIONES cuenta TODAS las cargas de esa fecha, vigentes y pisadas.
           Es lo que le dice a la pantalla que hay historial para abrir: sin
           ese numero, el enlace al historial estaria siempre y la mitad de las
           veces no mostraria nada. */
        $sql = "SELECT d.FECHA, d.IMPORTE_USD, d.USUARIO, d.FECHA_ALTA,
                       (SELECT COUNT(*)
                          FROM dbo.RO_T_CASHFLOW_DOLARES_COMITENTE h
                         WHERE h.FECHA = d.FECHA) AS VERSIONES
                FROM dbo.RO_T_CASHFLOW_DOLARES_COMITENTE d
                WHERE d.VIGENTE = 1
                ORDER BY d.FECHA DESC";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los dólares en cuenta comitente'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                'IMPORTE_USD' => floatval($row['IMPORTE_USD']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'VERSIONES' => intval($row['VERSIONES'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Todas las cargas de una fecha, la vigente y las pisadas, de la mas nueva
     * a la mas vieja.
     *
     * Es lo que explica por que el numero de ayer era otro.
     *
     * @param string $fecha 'Y-m-d'
     * @return array
     */
    public function getHistorialFecha($fecha) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $f = self::validarFecha($fecha);
        $cid = $this->conectar();

        $sql = "SELECT ID, FECHA, IMPORTE_USD, VIGENTE, USUARIO, FECHA_ALTA
                FROM dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
                WHERE FECHA = ?
                ORDER BY ID DESC";

        $stmt = sqlsrv_query($cid, $sql, [$f]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                'IMPORTE_USD' => floatval($row['IMPORTE_USD']),
                'VIGENTE' => intval($row['VIGENTE']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       CARGA
       ==================================================================== */

    /**
     * Carga un importe para una fecha.
     *
     * NO HACE UPDATE. Marca VIGENTE = 0 las cargas anteriores de esa fecha e
     * inserta una fila nueva, LAS DOS COSAS EN UNA TRANSACCION: si la baja
     * confirmara y el alta fallara, la fecha se quedaria sin importe vigente y
     * la fila del tablero perderia esa plata en silencio.
     *
     * @param string $fecha 'Y-m-d'
     * @param float $importeUsd
     * @param string|null $usuario
     * @return array ['fecha', 'importe_usd', 'piso' => bool]
     */
    public function guardarDolaresComitente($fecha, $importeUsd, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('No existe la tabla de dólares en cuenta comitente. '
                . 'Corré sql/cashflow_dolares_comitente.sql.');
        }

        $f = self::validarFecha($fecha);
        $importe = self::validarImporte($importeUsd);

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $stmt = sqlsrv_query($cid,
                "UPDATE dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
                 SET VIGENTE = 0
                 WHERE FECHA = ? AND VIGENTE = 1",
                [$f]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de baja la carga anterior'));
            }

            $piso = (sqlsrv_rows_affected($stmt) > 0);
            sqlsrv_free_stmt($stmt);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo.RO_T_CASHFLOW_DOLARES_COMITENTE
                     (FECHA, IMPORTE_USD, VIGENTE, USUARIO)
                 VALUES (?, ?, 1, ?)",
                [$f, $importe, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el importe'));
            }

            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['fecha' => $f, 'importe_usd' => $importe, 'piso' => $piso];
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * Normaliza y valida una fecha de carga.
     *
     * A diferencia de la fecha de cobro manual de Cobranzas FR, ACA SI SE
     * ACEPTAN FECHAS PASADAS: la carga describe cuantos dolares habia en la
     * cuenta un dia determinado, y ese dia puede haber sido la semana pasada.
     * Lo que hace el eje del tablero con una fecha vieja es asunto del eje.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFecha($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha no es válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Valida el importe en dolares.
     *
     * CERO ES VALIDO: significa que ese dia no habia dolares en la cuenta, y es
     * un dato distinto de no haber cargado nada. Un negativo no: la cuenta
     * comitente no tiene saldo deudor en este circuito, y un signo invertido
     * restaria del tablero sin que nadie lo pida.
     *
     * @param mixed $importe
     * @return float
     */
    public static function validarImporte($importe) {
        if ($importe === null || $importe === '' || !is_numeric($importe)) {
            throw new Exception('El importe en dólares tiene que ser un número.');
        }

        $v = round(floatval($importe), 2);

        if ($v < 0) {
            throw new Exception('El importe en dólares no puede ser negativo: '
                . 'restaría del tablero en vez de sumar.');
        }

        return $v;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** 'Y-m-d H:i' de lo que devuelve sqlsrv para un DATETIME */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return $v === null ? null : (string) $v;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors()
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto;

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= ': ' . $e['message'];
            }
        }

        return $msg;
    }
}
