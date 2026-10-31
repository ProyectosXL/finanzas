<?php

require_once __DIR__ . '/AuthCashflow.php';

/**
 * TarjetasProvProyecta
 * Los proveedores de Tarjetas Pagos Corporativos cuyas facturas se repiten como
 * estimacion hasta el fin del horizonte. Se marcan en Parametros -> Tarjetas.
 *
 * REEMPLAZA A "MARCAR MENSUAL". Antes se marcaba UNA factura y se guardaba una
 * copia (RO_T_CASHFLOW_TARJETAS_MENSUAL); ahora se marca el proveedor y la regla
 * mira sus facturas pendientes. La tabla de marcas queda como historial y deja
 * de leerse. La regla vive en TarjetasCorporativas::estimacionesPorProveedor(),
 * que es pura.
 *
 * SIN BAJAS FISICAS: VIGENTE = 0 con quien y cuando. Volver a marcar un
 * proveedor crea una fila nueva, y el historial muestra las dos.
 */
class TarjetasProvProyecta {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_PROV_PROYECTA';

    /** El script que la crea */
    const SCRIPT = 'sql/cashflow_tarjetas_prov_proyecta.sql';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * El codigo de proveedor, normalizado. Que exista en el universo de la
     * pestana lo valida quien llama, que es quien lo tiene.
     *
     * Estatica y pura.
     *
     * @param mixed $cod
     * @return string
     */
    public static function validarCodigo($cod) {
        $c = trim((string) $cod);

        if ($c === '' || strlen($c) > 6) {
            throw new Exception('El código de proveedor "' . $cod . '" no es válido.');
        }

        return $c;
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
            . 'central. Mientras tanto ningún proveedor proyecta, y Tarjetas Pagos Corporativos '
            . 'no tiene estimaciones.';
    }

    /**
     * Los proveedores vigentes. VACIO SI FALTA EL SCRIPT, sin lanzar.
     *
     * @return array Mapa COD_PROVEE => ['COD_PROVEE', 'RAZON_SOC', 'USUARIO_ALTA',
     *               'FECHA_ALTA']
     */
    public function vigentes() {
        return $this->leer('WHERE VIGENTE = 1', true);
    }

    /**
     * Todas las marcas, vigentes y de baja, de la mas nueva a la mas vieja.
     *
     * @return array Lista
     */
    public function historial() {
        return array_values($this->leer('', false));
    }

    private function leer($where, $porCodigo) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, COD_PROVEE, RAZON_SOC, VIGENTE, USUARIO_ALTA, FECHA_ALTA,
                    USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . " " . $where . "
             ORDER BY FECHA_ALTA DESC, ID DESC");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los proveedores que proyectan'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $f = [
                'ID' => intval($row['ID']),
                'COD_PROVEE' => trim((string) $row['COD_PROVEE']),
                'RAZON_SOC' => $row['RAZON_SOC'],
                'VIGENTE' => (intval($row['VIGENTE']) === 1),
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => self::momento($row['FECHA_BAJA'])
            ];

            if ($porCodigo) {
                $v[$f['COD_PROVEE']] = $f;
            } else {
                $v[] = $f;
            }
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Marca un proveedor. Si ya estaba vigente, no hace nada y lo dice.
     *
     * @param string $cod
     * @param string|null $razon
     * @param string $usuario
     * @return array ['cod', 'nuevo' => bool]
     */
    public function marcar($cod, $razon, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $c = self::validarCodigo($cod);

        $stmt = sqlsrv_query($this->conectar(),
            "IF NOT EXISTS (SELECT 1 FROM dbo." . self::TABLA . " WHERE COD_PROVEE = ? AND VIGENTE = 1)
             BEGIN
                 INSERT INTO dbo." . self::TABLA . " (COD_PROVEE, RAZON_SOC, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, 1, ?, ?);
                 SELECT 1 AS NUEVO;
             END
             ELSE SELECT 0 AS NUEVO;",
            [$c, $c, ($razon === null) ? null : mb_substr((string) $razon, 0, 100), $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al marcar el proveedor'));
        }

        do {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        } while ($row === null && sqlsrv_next_result($stmt));

        sqlsrv_free_stmt($stmt);

        return ['cod' => $c, 'nuevo' => ($row && intval($row['NUEVO']) === 1)];
    }

    /**
     * Da de baja la marca vigente de un proveedor. No borra nada.
     *
     * @param string $cod
     * @param string $usuario
     * @return array ['cod']
     */
    public function desmarcar($cod, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $c = self::validarCodigo($cod);

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE COD_PROVEE = ? AND VIGENTE = 1",
            [$usuario, $usuario, $c]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al desmarcar el proveedor'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('El proveedor ' . $c . ' no estaba marcado como que proyecta.');
        }

        return ['cod' => $c];
    }

    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
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
