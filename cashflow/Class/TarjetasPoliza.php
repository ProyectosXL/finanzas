<?php

require_once __DIR__ . '/TarjetasSeguros.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * TarjetasPoliza
 * Las polizas de los proveedores de seguros: lectura, alta, correccion y baja.
 *
 * LA REGLA NO VIVE ACA: que se valida y que pagos genera lo decide
 * TarjetasSeguros, que es pura. Aca se lee, se escribe y se audita.
 *
 * SIN BAJAS FISICAS: VIGENTE = 0 con quien y cuando. Corregir es un UPDATE
 * sobre la vigente, y se valida igual que un alta.
 */
class TarjetasPoliza {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_POLIZA';

    /** El script que la crea */
    const SCRIPT = 'sql/cashflow_tarjetas_polizas.sql';

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
            'Todavía no existe ' . self::TABLA . ': corré ' . self::SCRIPT . ' contra la base '
            . 'central. Mientras tanto no se pueden cargar pólizas, y los seguros no proyectan.';
    }

    /**
     * Las polizas vigentes, de la mas vieja a la mas nueva. VACIO SI FALTA EL
     * SCRIPT, sin lanzar.
     *
     * @return array Lista de filas
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, COD_PROVEE, RAZON_SOC, POLIZA, ID_TARJETA, IMPORTE_TOTAL, MONEDA,
                    FECHA_INICIO, VIGENCIA_MESES, GRACIA_MESES, MES_PRIMER_PAGO, OBSERVACION,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1
             ORDER BY COD_PROVEE, POLIZA, FECHA_INICIO, ID");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las pólizas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'COD_PROVEE' => trim((string) $row['COD_PROVEE']),
                'RAZON_SOC' => $row['RAZON_SOC'],
                'POLIZA' => trim((string) $row['POLIZA']),
                'ID_TARJETA' => intval($row['ID_TARJETA']),
                'IMPORTE_TOTAL' => round(floatval($row['IMPORTE_TOTAL']), 2),
                'MONEDA' => trim((string) $row['MONEDA']),
                'FECHA_INICIO' => self::dia($row['FECHA_INICIO']),
                'VIGENCIA_MESES' => intval($row['VIGENCIA_MESES']),
                'GRACIA_MESES' => intval($row['GRACIA_MESES']),
                'MES_PRIMER_PAGO' => trim((string) $row['MES_PRIMER_PAGO']),
                'OBSERVACION' => $row['OBSERVACION'],
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
                'USUARIO_MODIF' => $row['USUARIO_MODIF'],
                'FECHA_MODIF' => self::momento($row['FECHA_MODIF'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Da de alta o corrige una poliza, validada por TarjetasSeguros::validar().
     *
     * @param int|null $id null para un alta
     * @param array $datos Los del formulario
     * @param array $seguros TarjetasSeguros::proveedores()
     * @param array $tarjetas Mapa ID => tarjeta
     * @param string $usuario
     * @return array ['id', 'nuevo' => bool, 'poliza' => fila validada]
     */
    public function guardar($id, $datos, $seguros, $tarjetas, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $otras = $this->vigentes();
        $idNum = ($id === null || $id === '') ? null : intval($id);

        if ($idNum !== null && !in_array($idNum, array_column($otras, 'ID'), true)) {
            throw new Exception('La póliza ' . $idNum . ' no existe o está dada de baja.');
        }

        $p = TarjetasSeguros::validar($datos, $seguros, $tarjetas, $otras, $idNum);
        $params = [$p['COD_PROVEE'], $p['RAZON_SOC'], $p['POLIZA'], $p['ID_TARJETA'],
                   $p['IMPORTE_TOTAL'], $p['MONEDA'], $p['FECHA_INICIO'], $p['VIGENCIA_MESES'],
                   $p['GRACIA_MESES'], $p['MES_PRIMER_PAGO'], $p['OBSERVACION']];
        $cid = $this->conectar();

        if ($idNum === null) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (COD_PROVEE, RAZON_SOC, POLIZA, ID_TARJETA, IMPORTE_TOTAL, MONEDA,
                     FECHA_INICIO, VIGENCIA_MESES, GRACIA_MESES, MES_PRIMER_PAGO, OBSERVACION,
                     VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?);
                 SELECT CAST(SCOPE_IDENTITY() AS INT) AS ID;",
                array_merge($params, [$usuario, $usuario]));

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de alta la póliza'));
            }

            sqlsrv_next_result($stmt);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            return ['id' => ($row && $row['ID'] !== null) ? intval($row['ID']) : null,
                    'nuevo' => true, 'poliza' => $p];
        }

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET COD_PROVEE = ?, RAZON_SOC = ?, POLIZA = ?, ID_TARJETA = ?, IMPORTE_TOTAL = ?,
                 MONEDA = ?, FECHA_INICIO = ?, VIGENCIA_MESES = ?, GRACIA_MESES = ?,
                 MES_PRIMER_PAGO = ?, OBSERVACION = ?, " . Auditoria::SET_MODIF . "
             WHERE ID = ? AND VIGENTE = 1",
            array_merge($params, [$usuario, $idNum]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al corregir la póliza'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id' => $idNum, 'nuevo' => false, 'poliza' => $p];
    }

    /**
     * Da de baja una poliza. No borra nada.
     *
     * @param mixed $id
     * @param string $usuario
     * @return array ['id']
     */
    public function darDeBaja($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la póliza'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('La póliza ' . intval($id) . ' no existe o ya estaba dada de baja.');
        }

        return ['id' => intval($id)];
    }

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

    /** @return resource Conexion a 'central' */
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
