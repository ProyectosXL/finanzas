<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/AlquileresImputacion.php';
require_once __DIR__ . '/TarjetasCorporativas.php';
/* Por TarjetasFacturaVto::clave(), que es pura: la clave de una cuota se
   normaliza y valida igual en las dos pestañas. */
require_once __DIR__ . '/TarjetasFacturaVto.php';

/**
 * AlquileresFacturaVto
 * El vencimiento a considerar de una cuota de alquiler, cuando no es el de
 * Tango.
 *
 * ES LA UNICA FECHA DE PAGO de una factura de alquiler: la planilla de pagos de
 * Proveedores Locales no se usa para estas facturas (las deja en error). Mismo
 * patron que TarjetasFacturaVto, con su propia tabla: son dos pestañas con dos
 * universos, y una correccion en una no tiene que mover la otra.
 *
 *   - LA CLAVE ES LA CUOTA (AlquileresImputacion::claveCuota()).
 *   - LA FECHA MINIMA ES HOY: la misma regla que Tarjetas,
 *     TarjetasCorporativas::validarVtoEditado(), que es pura y no se repite.
 *   - CORREGIR A LA FECHA DE TANGO ES DESHACER, y el ↺ de la pantalla tambien.
 *   - SIN BAJAS FISICAS: VIGENTE = 0 con usuario y fecha de baja.
 *
 * SIN LA TABLA, vigentes() devuelve vacio -todas usan el de Tango, que es lo
 * cierto- y lo que falla, con el script, es guardar.
 */
class AlquileresFacturaVto {

    const TABLA = 'RO_T_CASHFLOW_ALQ_FACTURA_VTO';

    const LARGO_MOTIVO = 300;

    /** @var Conexion */
    private $conn;

    /** @var bool|null */
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
            $stmt = sqlsrv_query($this->conectar(), "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

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

    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no se puede cambiar el vencimiento de una factura de alquiler: falta correr '
            . AlquileresFacturas::SCRIPT . ' contra la base central. Mientras tanto todas usan el de Tango.';
    }

    /**
     * Las correcciones vigentes.
     *
     * @return array claveCuota => ['COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_VTO_TANGO', 'FECHA_VTO',
     *               'MOTIVO', 'USUARIO_ALTA', 'FECHA_ALTA']
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, FECHA_VTO, MOTIVO, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . " WHERE VIGENTE = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los vencimientos de alquiler'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fila = [
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'T_COMP' => Planilla::codigo($row['T_COMP']),
                'N_COMP' => Planilla::codigo($row['N_COMP']),
                'FECHA_VTO_TANGO' => self::dia($row['FECHA_VTO_TANGO']),
                'FECHA_VTO' => self::dia($row['FECHA_VTO']),
                'MOTIVO' => $row['MOTIVO'],
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA'])
            ];

            $v[AlquileresImputacion::claveCuota($fila)] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Cambia el vencimiento a considerar de una cuota. Que la cuota sea una
     * factura de alquiler pendiente lo verifica quien llama, contra los
     * pendientes de ahora.
     *
     * @return array ['fecha', 'reemplazo' => bool, 'deshecha' => bool]
     */
    public function guardar($codProvee, $tComp, $nComp, $vtoTango, $fecha, $motivo, $usuario, $hoy) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $c = TarjetasFacturaVto::clave($codProvee, $tComp, $nComp, $vtoTango);
        $f = TarjetasCorporativas::validarVtoEditado($fecha, $hoy);
        $m = trim((string) $motivo);
        $m = ($m === '') ? null : mb_substr($m, 0, self::LARGO_MOTIVO);

        if ($f === $c[3]) {
            $r = $this->deshacer($codProvee, $tComp, $nComp, $vtoTango, $usuario);

            return ['fecha' => $f, 'reemplazo' => false, 'deshecha' => $r['habia']];
        }

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $bajas = $this->darDeBaja($cid, $c, $usuario);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, FECHA_VTO, MOTIVO, VIGENTE,
                     USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$c[0], $c[1], $c[2], $c[3], $f, $m, $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el vencimiento'));
            }

            sqlsrv_free_stmt($stmt);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el vencimiento'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['fecha' => $f, 'reemplazo' => ($bajas > 0), 'deshecha' => false];
    }

    /**
     * Vuelve una cuota al vencimiento de Tango (el ↺).
     *
     * @return array ['habia' => bool]
     */
    public function deshacer($codProvee, $tComp, $nComp, $vtoTango, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $bajas = $this->darDeBaja($this->conectar(),
            TarjetasFacturaVto::clave($codProvee, $tComp, $nComp, $vtoTango), $usuario);

        return ['habia' => ($bajas > 0)];
    }

    private function darDeBaja($cid, $c, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND FECHA_VTO_TANGO = ? AND VIGENTE = 1",
            [$usuario, $usuario, $c[0], $c[1], $c[2], $c[3]]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el vencimiento'));
        }

        $n = intval(sqlsrv_rows_affected($stmt));
        sqlsrv_free_stmt($stmt);

        return $n;
    }

    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    private static function dia($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d') : (($v === null || $v === '') ? null : substr((string) $v, 0, 10));
    }

    private static function momento($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : (($v === null || $v === '') ? null : substr((string) $v, 0, 16));
    }

    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    private function errorSql($contexto) {
        $msg = $contexto . ' (' . self::TABLA . '): ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return $msg;
    }
}

