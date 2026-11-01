<?php

require_once __DIR__ . '/Planilla.php';

/**
 * TarjetasMensual
 * Las facturas que se marcaban como mensuales (abonos) en Tarjetas Pagos
 * Corporativos. SOLO LECTURA: es historial.
 *
 * Desde feature/tarjetas-resumenes-seguros lo que proyecta es el PROVEEDOR
 * (TarjetasProvProyecta), y sql/cashflow_tarjetas_prov_proyecta.sql migro los
 * proveedores de estas marcas. La tabla queda como estaba, para poder explicar
 * con que se estimaba antes, y nadie la escribe ni la usa para proyectar.
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
