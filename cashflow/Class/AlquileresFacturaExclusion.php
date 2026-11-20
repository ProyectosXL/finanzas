<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/AlquileresFacturas.php';

/**
 * AlquileresFacturaExclusion
 * Las facturas de alquiler que NO SE PAGAN POR ESTE CIRCUITO (los pagos de
 * contrato), excluidas a mano en Alquileres -> Facturas, con su motivo. La
 * tabla es del script 61 (sql/cashflow_alquileres_excluir_factura.sql).
 *
 * QUE PASA CON UNA EXCLUIDA LO DECIDE AlquileresFacturas::destino(): con la
 * cuota en esta tabla va a A_EXCLUIDA_FACTURA, y no suma en Alquileres, ni en
 * el tablero, ni vuelve a Proveedores Locales. Esta clase solo lee y escribe, y
 * valida (validar(), pura) que se puede excluir y que se puede volver a incluir.
 *
 * POR CUOTA Y MASIVA. Se excluye una cuota, o todas las tildadas con un mismo
 * motivo, en una transaccion: o se excluyen todas, o ninguna.
 *
 * VOLVER A INCLUIR ES BAJA LOGICA (VIGENTE = 0, con quien y cuando), como la
 * exclusion de Proveedores Locales y la de Echeqs. El indice unico filtrado
 * impide dos exclusiones vigentes de la misma cuota.
 *
 * SIN EL SCRIPT no hay ninguna excluida -que es lo cierto- y lo que falla, con
 * el nombre del script, es excluir.
 */
class AlquileresFacturaExclusion {

    const TABLA = 'RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA';
    const SCRIPT = 'sql/cashflow_alquileres_excluir_factura.sql';
    const LARGO_MOTIVO = 300;

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    /** @var bool|null */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       PURAS
       ==================================================================== */

    /**
     * Valida excluir o volver a incluir unas cuotas. Pura.
     *
     *   EXCLUIR: cada cuota tiene que ser de alquiler y estar en la grilla
     *   (pendiente o pagada del mes sin imputar), no estar imputada (primero
     *   se deshace) ni ya excluida. El motivo es obligatorio.
     *   INCLUIR: cada cuota tiene que estar excluida.
     *
     * @param string $accion 'EXCLUIR' | 'INCLUIR'
     * @param array $cuotas [['cod_provee', 't_comp', 'n_comp', 'vto_tango']]
     * @param string|null $motivo
     * @param array $e ['imputables' => claveCuota => fila (pendientes de alquiler y pagadas
     *                  del mes), 'imputadas' => claveCuota => id de imputacion,
     *                  'excluidas' => claveCuota => fila de la exclusion]
     * @return array ['ok', 'errores', 'cuotas' => [['clave', 'fila']], 'motivo']
     */
    public static function validar($accion, $cuotas, $motivo, $e) {
        $errores = [];
        $out = [];
        $m = trim((string) $motivo);

        if ($accion === 'EXCLUIR' && $m === '') {
            $errores[] = 'Falta el motivo: una factura que se saca del cashflow tiene que decir por qué.';
        }

        if (mb_strlen($m, 'UTF-8') > self::LARGO_MOTIVO) {
            $errores[] = 'El motivo puede tener hasta ' . self::LARGO_MOTIVO . ' caracteres.';
        }

        foreach (is_array($cuotas) ? $cuotas : [] as $c) {
            $fila = ['COD_PROVEE' => $c['cod_provee'] ?? '', 'T_COMP' => $c['t_comp'] ?? '', 'N_COMP' => $c['n_comp'] ?? '',
                     'FECHA_VTO' => $c['vto_tango'] ?? ''];
            $clave = AlquileresFacturas::claveCuota($fila);
            $nombre = trim($fila['T_COMP'] . ' ' . $fila['N_COMP']);

            if (isset($out[$clave])) {
                continue;
            }

            if ($accion === 'EXCLUIR') {
                if (isset($e['excluidas'][$clave])) {
                    $errores[] = 'La factura ' . $nombre . ' ya está excluida.';
                } elseif (isset($e['imputadas'][$clave])) {
                    $errores[] = 'La factura ' . $nombre . ' está imputada en la #' . $e['imputadas'][$clave]
                        . ': deshacé primero la imputación para excluirla.';
                } elseif (!isset($e['imputables'][$clave])) {
                    $errores[] = 'La factura ' . $nombre . ' ya no está en Alquileres › Facturas: volvé a cargar la pestaña.';
                } else {
                    $out[$clave] = ['clave' => $clave, 'fila' => $e['imputables'][$clave]];
                }
            } else {
                if (!isset($e['excluidas'][$clave])) {
                    $errores[] = 'La factura ' . $nombre . ' no está excluida.';
                } else {
                    $out[$clave] = ['clave' => $clave, 'fila' => $fila];
                }
            }
        }

        if (empty($out) && empty($errores)) {
            $errores[] = 'Elegí al menos una factura.';
        }

        return ['ok' => empty($errores), 'errores' => array_values(array_unique($errores)),
                'cuotas' => array_values($out), 'motivo' => $m];
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio el script 61 */
    public function tablaCreada() {
        if ($this->tabla === null) {
            try {
                $r = $this->fila("SELECT OBJECT_ID(?, 'U') AS ID", ['dbo.' . self::TABLA], 'Error al verificar la tabla');
                $this->tabla = ($r !== null && $r['ID'] !== null);
            } catch (Throwable $e) {
                $this->tabla = false;
            }
        }

        return $this->tabla;
    }

    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' : 'Todavía no se pueden excluir facturas de alquiler: falta correr '
            . self::SCRIPT . ' contra la base central.';
    }

    /**
     * Las exclusiones vigentes, por cuota.
     *
     * @return array claveCuota => ['COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_VTO_TANGO', 'IMPORTE', 'MOTIVO',
     *                              'USUARIO_ALTA', 'FECHA_ALTA']
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $out = [];

        foreach ($this->filas("SELECT COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, IMPORTE, MOTIVO, USUARIO_ALTA, FECHA_ALTA
                               FROM dbo." . self::TABLA . " WHERE VIGENTE = 1", [],
                              'Error al leer las facturas de alquiler excluidas') as $r) {
            $f = ['COD_PROVEE' => Planilla::codigo($r['COD_PROVEE']), 'T_COMP' => Planilla::codigo($r['T_COMP']),
                  'N_COMP' => Planilla::codigo($r['N_COMP']), 'FECHA_VTO_TANGO' => self::dia($r['FECHA_VTO_TANGO']),
                  'IMPORTE' => ($r['IMPORTE'] === null) ? null : (float) $r['IMPORTE'], 'MOTIVO' => $r['MOTIVO'],
                  'USUARIO_ALTA' => $r['USUARIO_ALTA'], 'FECHA_ALTA' => self::momento($r['FECHA_ALTA'])];
            $out[AlquileresFacturas::claveCuota($f)] = $f;
        }

        return $out;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Excluye las cuotas ya validadas (validar('EXCLUIR')), todas con el mismo
     * motivo, en una transaccion.
     *
     * @return int Cuantas
     */
    public function excluir($v, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        if (empty($v['ok'])) {
            throw new InvalidArgumentException('No se excluyó: ' . implode(' ', $v['errores'] ?? []));
        }

        $this->transaccion(function () use ($v, $usuario) {
            foreach ($v['cuotas'] as $c) {
                $f = $c['fila'];
                $importe = isset($f['IMPORTE_PENDIENTE']) && (float) $f['IMPORTE_PENDIENTE'] > 0
                    ? (float) $f['IMPORTE_PENDIENTE'] : (isset($f['IMPORTE_VTO']) ? (float) $f['IMPORTE_VTO'] : null);

                $this->ejecutar("INSERT INTO dbo." . self::TABLA . "
                        (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, IMPORTE, MOTIVO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                    [Planilla::codigo($f['COD_PROVEE']), Planilla::codigo($f['T_COMP']), Planilla::codigo($f['N_COMP']),
                     substr((string) $f['FECHA_VTO'], 0, 10), $importe, mb_substr($v['motivo'], 0, self::LARGO_MOTIVO),
                     $usuario, $usuario], 'Error al excluir la factura');
            }
        });

        return count($v['cuotas']);
    }

    /** Vuelve a incluir las cuotas ya validadas (validar('INCLUIR')): baja logica. @return int Cuantas */
    public function incluir($v, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        if (empty($v['ok'])) {
            throw new InvalidArgumentException('No se incluyó: ' . implode(' ', $v['errores'] ?? []));
        }

        $n = 0;

        $this->transaccion(function () use ($v, $usuario, &$n) {
            foreach ($v['cuotas'] as $c) {
                $f = $c['fila'];
                $n += $this->ejecutar("UPDATE dbo." . self::TABLA . " SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND FECHA_VTO_TANGO = ? AND VIGENTE = 1",
                    [$usuario, $usuario, Planilla::codigo($f['COD_PROVEE']), Planilla::codigo($f['T_COMP']),
                     Planilla::codigo($f['N_COMP']), substr((string) $f['FECHA_VTO'], 0, 10)],
                    'Error al volver a incluir la factura');
            }
        });

        return $n;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException($this->avisoSinTabla());
        }
    }

    private function transaccion($fn) {
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $fn();

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }
    }

    private function filas($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        $out = [];

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = $r;
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    private function fila($sql, $params, $contexto) {
        $f = $this->filas($sql, $params, $contexto);

        return $f[0] ?? null;
    }

    private function ejecutar($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        $n = intval(sqlsrv_rows_affected($stmt));
        sqlsrv_free_stmt($stmt);

        return $n;
    }

    private static function dia($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d') : (($v === null || $v === '') ? null : substr((string) $v, 0, 10));
    }

    private static function momento($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : (($v === null || $v === '') ? null : substr((string) $v, 0, 16));
    }

    /** UNA CONEXION POR INSTANCIA: la transaccion y sus INSERT van por el mismo handle. */
    private function conectar() {
        if ($this->cid === null) {
            $cid = $this->conn->conectar('central');

            if (!$cid) {
                throw new Exception('No se pudo conectar a la base de datos central');
            }

            $this->cid = $cid;
        }

        return $this->cid;
    }

    private function errorSql($contexto) {
        foreach (sqlsrv_errors() ?: [] as $e) {
            if (strpos($e['message'], 'UX_RO_T_CF_ALQFEX_VIGENTE') !== false) {
                return 'Alguna de esas facturas ya se excluyó desde otra pantalla. Volvé a cargar la pestaña. No se guardó nada.';
            }
        }

        $msg = $contexto . ': ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
