<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/TarjetasCorporativas.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * TarjetasFacturaVto
 * El vencimiento corregido de una cuota en Tarjetas Pagos Corporativos.
 *
 * POR QUE EXISTE
 * --------------
 * Quien carga en Tango ya no pone en FECHA_VTO el vencimiento real de la factura
 * sino el del RESUMEN de la tarjeta en el que se va a pagar. Si lo cargo mal, la
 * cuota cae en otro debito. Corregirlo aca es decir en que debito cae SIN TOCAR
 * TANGO, y SOLO VALE EN ESTA PESTANA: Cuentas a Pagar Locales no se entera.
 *
 * LA CLAVE ES LA CUOTA (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO), no el
 * comprobante: una factura en cuotas tiene varias filas y cada una se corrige
 * por separado. Ver TarjetasCorporativas::claveCuota().
 *
 * LA REGLA VIVE EN TarjetasCorporativas, que es pura: la fecha minima
 * (validarVtoEditado()), como reemplaza al vencimiento de Tango (resolver()) y
 * cuando una correccion queda inerte (vtosInertes()). Aca hay lecturas y
 * escrituras, que no tienen nada que decidir.
 *
 * SIN BAJAS FISICAS: deshacer marca VIGENTE = 0 con usuario y fecha de baja.
 * Con un DELETE, "esta cuota nunca se corrigio" y "se corrigio y se volvio atras"
 * son indistinguibles despues del hecho. Mismo criterio que la exclusion.
 */
class TarjetasFacturaVto {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_FACTURA_VTO';

    /** El script que la crea, para los avisos */
    const SCRIPT = 'sql/cashflow_tarjetas_vto_mensual.sql';

    /** Tope del motivo, el de la columna */
    const LARGO_MOTIVO = 300;

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
            'Todavía no se puede corregir el vencimiento de una factura: falta la tabla '
            . self::TABLA . '. Corré ' . self::SCRIPT . ' contra la base central. Mientras '
            . 'tanto todas las cuotas usan el vencimiento de Tango.';
    }

    /**
     * Las correcciones vigentes, por cuota.
     *
     * VACIO SI LA TABLA NO EXISTE, sin lanzar: ninguna cuota esta corregida, que
     * es lo cierto.
     *
     * @return array Mapa claveCuota => ['COD_PROVEE', 'T_COMP', 'N_COMP',
     *               'FECHA_VTO_TANGO', 'FECHA_VTO', 'MOTIVO', 'USUARIO_ALTA', 'FECHA_ALTA']
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, FECHA_VTO, MOTIVO,
                    USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los vencimientos corregidos'));
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

            $v[TarjetasCorporativas::claveCuota($fila)] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Corrige el vencimiento de una cuota: da de baja la correccion vigente e
     * inserta la nueva, en una transaccion.
     *
     * LA FECHA MINIMA ES HOY (TarjetasCorporativas::validarVtoEditado()). Que la
     * cuota exista en la pestana lo verifica quien llama contra el universo, que
     * es lo que esta clase no lee.
     *
     * CORREGIR A LA MISMA FECHA DE TANGO ES DESHACER: guardar una correccion que
     * no corrige nada dejaria una marca de "editada" sobre una fecha que es la
     * original.
     *
     * @return array ['fecha', 'reemplazo' => bool, 'deshecha' => bool]
     */
    public function guardar($codProvee, $tComp, $nComp, $vtoTango, $fecha, $motivo, $usuario, $hoy) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $c = self::clave($codProvee, $tComp, $nComp, $vtoTango);
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
                throw new Exception($this->errorSql('Error al guardar el vencimiento corregido'));
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
     * Vuelve una cuota al vencimiento de Tango: da de baja la correccion vigente.
     *
     * NO EXIGE QUE LA CUOTA SIGA EN LA PESTANA: es tambien como se da de baja una
     * correccion inerte, la de una cuota cuyo vencimiento Tango cambio.
     *
     * @return array ['habia' => bool]
     */
    public function deshacer($codProvee, $tComp, $nComp, $vtoTango, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $bajas = $this->darDeBaja($this->conectar(),
            self::clave($codProvee, $tComp, $nComp, $vtoTango), $usuario);

        return ['habia' => ($bajas > 0)];
    }

    /** @return int Cuantas filas se dieron de baja */
    private function darDeBaja($cid, $c, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND FECHA_VTO_TANGO = ?
               AND VIGENTE = 1",
            [$usuario, $usuario, $c[0], $c[1], $c[2], $c[3]]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el vencimiento corregido'));
        }

        $n = intval(sqlsrv_rows_affected($stmt));
        sqlsrv_free_stmt($stmt);

        return $n;
    }

    /**
     * La clave de la cuota, normalizada y validada.
     *
     * @return array [cod, t, n, 'Y-m-d']
     */
    public static function clave($codProvee, $tComp, $nComp, $vtoTango) {
        $cod = Planilla::codigo($codProvee);
        $t = Planilla::codigo($tComp);
        $n = Planilla::codigo($nComp);
        $v = substr(trim((string) $vtoTango), 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $v);

        if ($cod === '' || $t === '' || $n === '') {
            throw new Exception('Falta el proveedor, el tipo o el número de comprobante.');
        }

        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new Exception("El vencimiento de Tango de la cuota es inválido: '$vtoTango'.");
        }

        return [$cod, $t, $n, $v];
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
