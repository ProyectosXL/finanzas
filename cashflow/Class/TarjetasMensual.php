<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * TarjetasMensual
 * Las facturas de Tarjetas Pagos Corporativos marcadas como mensuales (abonos),
 * y lo que se guarda de ellas para estimar los meses siguientes.
 *
 * UNA COPIA, NO UN VINCULO
 * ------------------------
 * Se guarda el proveedor con su razon social, el comprobante de origen, la
 * tarjeta, el importe, el dia y el primer mes. Es una copia a proposito: la
 * factura sale de pendientes cuando se paga, y la estimacion tiene que seguir
 * viva. Lo que se guarda lo arma TarjetasCorporativas::datosMarcaMensual(), que
 * es pura y es la que valida que la factura este vinculada y no excluida.
 *
 * UNA SOLA VIGENTE POR PROVEEDOR: marcar otra factura del mismo proveedor da de
 * baja la anterior en la misma transaccion. La pantalla lo confirma antes; el
 * indice unico filtrado es la red.
 *
 * SIN BAJAS FISICAS: desmarcar es VIGENTE = 0 con usuario y fecha de baja, y se
 * puede hacer tambien cuando la factura de origen ya se pago.
 *
 * EXCLUIR LA FACTURA DE ORIGEN NO TOCA LA ESTIMACION: son decisiones distintas, y
 * la estimacion se desmarca explicitamente.
 */
class TarjetasMensual {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_MENSUAL';

    /** El script que la crea, para los avisos */
    const SCRIPT = 'sql/cashflow_tarjetas_vto_mensual.sql';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /** @return bool Si ya se corrio el script */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                return $this->tabla = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    /** @return string El aviso de script faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no se puede marcar una factura como mensual: falta la tabla ' . self::TABLA
            . '. Corré ' . self::SCRIPT . ' contra la base central.';
    }

    /**
     * Las estimaciones vigentes. VACIO SI LA TABLA NO EXISTE, sin lanzar: no hay
     * ninguna, que es lo cierto.
     *
     * @return array Lista de filas
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, COD_PROVEE, RAZON_SOC, T_COMP, N_COMP, FECHA_VTO_TANGO,
                    FECHA_VTO_ORIGEN, ID_TARJETA, IMPORTE, DIA, MES_DESDE,
                    USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1
             ORDER BY RAZON_SOC, COD_PROVEE");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las estimaciones mensuales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'RAZON_SOC' => $row['RAZON_SOC'],
                'T_COMP' => Planilla::codigo($row['T_COMP']),
                'N_COMP' => Planilla::codigo($row['N_COMP']),
                'FECHA_VTO_TANGO' => self::dia($row['FECHA_VTO_TANGO']),
                'FECHA_VTO_ORIGEN' => self::dia($row['FECHA_VTO_ORIGEN']),
                'ID_TARJETA' => intval($row['ID_TARJETA']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'DIA' => intval($row['DIA']),
                'MES_DESDE' => trim((string) $row['MES_DESDE']),
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Marca una factura como mensual. Si el proveedor ya tenia una estimacion
     * vigente, la da de baja en la misma transaccion.
     *
     * @param array $datos Lo que devolvio TarjetasCorporativas::datosMarcaMensual()
     * @param string $usuario
     * @return array ['reemplazo' => int, 'datos' => $datos]
     */
    public function marcar($datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            /* UNA POR PROVEEDOR: la anterior se da de baja, no se pisa. */
            $stmt = sqlsrv_query($cid,
                "UPDATE dbo." . self::TABLA . "
                 SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                 WHERE COD_PROVEE = ? AND VIGENTE = 1",
                [$usuario, $usuario, $datos['COD_PROVEE']]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de baja la estimación anterior'));
            }

            $reemplazo = intval(sqlsrv_rows_affected($stmt));
            sqlsrv_free_stmt($stmt);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (COD_PROVEE, RAZON_SOC, T_COMP, N_COMP, FECHA_VTO_TANGO, FECHA_VTO_ORIGEN,
                     ID_TARJETA, IMPORTE, DIA, MES_DESDE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$datos['COD_PROVEE'], $datos['RAZON_SOC'], $datos['T_COMP'], $datos['N_COMP'],
                 $datos['FECHA_VTO_TANGO'], $datos['FECHA_VTO_ORIGEN'], $datos['ID_TARJETA'],
                 $datos['IMPORTE'], $datos['DIA'], $datos['MES_DESDE'], $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al marcar la factura como mensual'));
            }

            sqlsrv_free_stmt($stmt);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la marca'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['reemplazo' => $reemplazo, 'datos' => $datos];
    }

    /**
     * Desmarca una estimacion: VIGENTE = 0. Tambien cuando la factura de origen ya
     * se pago -por eso va por ID y no por comprobante-.
     *
     * @param int $id
     * @param string $usuario
     * @return array ['habia' => bool]
     */
    public function desmarcar($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $n = filter_var($id, FILTER_VALIDATE_INT);

        if ($n === false || $n < 1) {
            throw new Exception("Estimación inválida: '$id'.");
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al desmarcar la estimación'));
        }

        $filas = intval(sqlsrv_rows_affected($stmt));
        sqlsrv_free_stmt($stmt);

        return ['habia' => ($filas > 0)];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    private static function dia($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 10);
    }

    private static function momento($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 16);
    }

    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::TABLA . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
