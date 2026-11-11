<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/AlquileresImputacion.php';

/**
 * AlquileresImputaciones
 * Lee y escribe las imputaciones de facturas de alquiler
 * (sql/cashflow_alquileres_facturas.sql).
 *
 * NO DECIDE NADA. Que es valido, que reemplaza y cuanto se paga es
 * AlquileresImputacion, que es pura; guardar() recibe lo que ella ya valido.
 *
 * SIN BAJAS FISICAS. Reimputar da de baja la anterior (VIGENTE = 0,
 * MOTIVO_BAJA, ID_REEMPLAZO) e inserta la nueva en UNA transaccion; deshacer es
 * la misma baja sin una nueva. El indice unico filtrado de IMP_FACTURA frena a
 * dos pantallas imputando la misma cuota a la vez: la segunda falla.
 *
 * LAS CONSULTAS SON POR TABLA Y LOS CRUCES EN PHP, como el resto del modulo.
 *
 * SIN EL SCRIPT, vigentes() devuelve vacio -no hay ninguna imputada, que es lo
 * cierto- y lo que falla, con el nombre del script, es guardar.
 */
class AlquileresImputaciones {

    const T_IMP = 'RO_T_CASHFLOW_ALQ_IMPUTACION';
    const T_FACTURA = 'RO_T_CASHFLOW_ALQ_IMP_FACTURA';
    const T_RENGLON = 'RO_T_CASHFLOW_ALQ_IMP_RENGLON';
    const T_CONCEPTO = 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO';
    const T_LOCAL = 'RO_T_CASHFLOW_ALQ_IMP_RENGLON_LOCAL';

    const LARGO_OBSERVACION = 300;

    /** @var Conexion */
    private $conn;

    /** @var bool|null */
    private $tabla = null;

    /** @var resource|null */
    private $cid = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /** @return bool Si ya se corrio el script (la cabecera es la que manda: AlquileresFacturas::TABLA_IMPUTACION) */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $row = $this->fila("SELECT OBJECT_ID('dbo." . self::T_IMP . "', 'U') AS T", [], 'Error al verificar las tablas');
            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' : 'Todavía no se pueden imputar facturas de alquiler: falta correr '
            . AlquileresFacturas::SCRIPT . ' contra la base central.';
    }

    /**
     * Las imputaciones vigentes, armadas.
     *
     * @return array Lista de ['id', 'usuario', 'fecha', 'observacion',
     *               'facturas' => [['ID', 'COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_VTO_TANGO', 'PENDIENTE',
     *                               'NETO_GRAVADO', 'RETENCION', 'RETENCION_SUGERIDA', 'ESTADO', 'FECHA_PAGADA']],
     *               'renglones' => [['orden', 'mes', 'importe', 'todos_de', 'conceptos' => [], 'locales' => []]]]
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $imps = [];

        foreach ($this->filas("SELECT ID, OBSERVACION, USUARIO_ALTA, FECHA_ALTA FROM dbo." . self::T_IMP
                              . " WHERE VIGENTE = 1 ORDER BY ID", [], 'Error al leer las imputaciones') as $r) {
            $imps[(int) $r['ID']] = ['id' => (int) $r['ID'], 'usuario' => $r['USUARIO_ALTA'],
                                     'fecha' => self::momento($r['FECHA_ALTA']), 'observacion' => $r['OBSERVACION'],
                                     'facturas' => [], 'renglones' => []];
        }

        if (empty($imps)) {
            return [];
        }

        foreach ($this->filas("SELECT ID, ID_IMPUTACION, COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, PENDIENTE,
                                      NETO_GRAVADO, RETENCION, RETENCION_SUGERIDA, ESTADO, FECHA_PAGADA
                               FROM dbo." . self::T_FACTURA . " WHERE VIGENTE = 1", [],
                              'Error al leer las facturas imputadas') as $r) {
            $id = (int) $r['ID_IMPUTACION'];

            if (isset($imps[$id])) {
                $imps[$id]['facturas'][] = [
                    'ID' => (int) $r['ID'],
                    'COD_PROVEE' => Planilla::codigo($r['COD_PROVEE']),
                    'T_COMP' => Planilla::codigo($r['T_COMP']),
                    'N_COMP' => Planilla::codigo($r['N_COMP']),
                    'FECHA_VTO_TANGO' => self::dia($r['FECHA_VTO_TANGO']),
                    'PENDIENTE' => (float) $r['PENDIENTE'],
                    'NETO_GRAVADO' => ($r['NETO_GRAVADO'] === null) ? null : (float) $r['NETO_GRAVADO'],
                    'RETENCION' => (float) $r['RETENCION'],
                    'RETENCION_SUGERIDA' => ($r['RETENCION_SUGERIDA'] === null) ? null : (float) $r['RETENCION_SUGERIDA'],
                    'ESTADO' => trim((string) $r['ESTADO']),
                    'FECHA_PAGADA' => self::dia($r['FECHA_PAGADA'])
                ];
            }
        }

        $ids = array_keys($imps);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $renglones = [];

        foreach ($this->filas("SELECT ID, ID_IMPUTACION, ORDEN, MES_CUBRE, IMPORTE, TODOS_DE_RAZON FROM dbo."
                              . self::T_RENGLON . " WHERE ID_IMPUTACION IN ($marcas) ORDER BY ID_IMPUTACION, ORDEN",
                              $ids, 'Error al leer los renglones') as $r) {
            $renglones[(int) $r['ID']] = ['id_imputacion' => (int) $r['ID_IMPUTACION'], 'orden' => (int) $r['ORDEN'],
                                          'mes' => trim((string) $r['MES_CUBRE']), 'importe' => (float) $r['IMPORTE'],
                                          'todos_de' => ($r['TODOS_DE_RAZON'] === null) ? null
                                              : Planilla::codigo($r['TODOS_DE_RAZON']),
                                          'conceptos' => [], 'locales' => []];
        }

        if (!empty($renglones)) {
            $idsR = array_keys($renglones);
            $marcasR = implode(',', array_fill(0, count($idsR), '?'));

            foreach ($this->filas("SELECT ID_RENGLON, CONCEPTO FROM dbo." . self::T_CONCEPTO
                                  . " WHERE ID_RENGLON IN ($marcasR)", $idsR, 'Error al leer los conceptos') as $r) {
                $renglones[(int) $r['ID_RENGLON']]['conceptos'][] = trim((string) $r['CONCEPTO']);
            }

            foreach ($this->filas("SELECT ID_RENGLON, NRO_LOCAL FROM dbo." . self::T_LOCAL
                                  . " WHERE ID_RENGLON IN ($marcasR)", $idsR, 'Error al leer los locales') as $r) {
                $renglones[(int) $r['ID_RENGLON']]['locales'][] = (int) $r['NRO_LOCAL'];
            }
        }

        foreach ($renglones as $r) {
            $id = $r['id_imputacion'];
            unset($r['id_imputacion']);
            $orden = array_values(array_intersect(AlquileresImputacion::CONCEPTOS, $r['conceptos']));
            $r['conceptos'] = $orden;
            sort($r['locales']);
            $imps[$id]['renglones'][] = $r;
        }

        return array_values($imps);
    }

    /**
     * Guarda una imputacion ya validada (AlquileresImputacion::validar() con los
     * pendientes de AHORA), y da de baja las que reemplaza. Una transaccion.
     *
     * @param array $v Lo de validar(), con 'ok' = true
     * @param string|null $observacion
     * @param string $usuario
     * @return array ['id', 'reemplazadas' => [id]]
     */
    public function guardar($v, $observacion, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        if (empty($v['ok'])) {
            throw new InvalidArgumentException('La imputación no es válida: ' . implode(' ', $v['errores'] ?? []));
        }

        $obs = trim((string) $observacion);
        $obs = ($obs === '') ? null : mb_substr($obs, 0, self::LARGO_OBSERVACION);
        $id = null;

        $this->transaccion(function () use ($v, $obs, $usuario, &$id) {
            $row = $this->fila("INSERT INTO dbo." . self::T_IMP . " (OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                                OUTPUT INSERTED.ID VALUES (?, 1, ?, ?)", [$obs, $usuario, $usuario],
                               'Error al guardar la imputación');
            $id = (int) $row['ID'];

            foreach ($v['reemplaza'] as $viejo) {
                $this->darDeBaja((int) $viejo, 'Reemplazada por la imputación ' . $id, $id, $usuario);
            }

            foreach ($v['facturas'] as $f) {
                $p = $f['fila'];
                $this->ejecutar("INSERT INTO dbo." . self::T_FACTURA . "
                        (ID_IMPUTACION, COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, PENDIENTE, NETO_GRAVADO,
                         RETENCION, RETENCION_SUGERIDA, VIGENTE, ESTADO, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'PENDIENTE', ?, ?)",
                    [$id, Planilla::codigo($p['COD_PROVEE']), Planilla::codigo($p['T_COMP']),
                     Planilla::codigo($p['N_COMP']), substr((string) $p['FECHA_VTO'], 0, 10), $f['pendiente'],
                     $f['neto_usado'], ($f['retencion'] === null) ? 0.0 : $f['retencion'],
                     $f['retencion_sugerida'], $usuario, $usuario],
                    'Error al guardar una factura de la imputación');
            }

            foreach ($v['renglones'] as $r) {
                $ren = $this->fila("INSERT INTO dbo." . self::T_RENGLON . "
                        (ID_IMPUTACION, ORDEN, MES_CUBRE, IMPORTE, TODOS_DE_RAZON, USUARIO_ALTA)
                     OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?)",
                    [$id, $r['orden'], $r['mes'], $r['importe'], $r['todos_de'], $usuario],
                    'Error al guardar un renglón');
                $idR = (int) $ren['ID'];

                foreach ($r['conceptos'] as $c) {
                    $this->ejecutar("INSERT INTO dbo." . self::T_CONCEPTO . " (ID_RENGLON, CONCEPTO) VALUES (?, ?)",
                        [$idR, $c], 'Error al guardar un concepto');
                }

                foreach ($r['locales'] as $nro) {
                    $this->ejecutar("INSERT INTO dbo." . self::T_LOCAL . " (ID_RENGLON, NRO_LOCAL) VALUES (?, ?)",
                        [$idR, $nro], 'Error al guardar un local');
                }
            }
        });

        return ['id' => $id, 'reemplazadas' => array_values($v['reemplaza'])];
    }

    /**
     * Deshace una imputacion: baja logica. Sus cuotas vuelven a Sin imputar y la
     * proyeccion que reemplazaba reaparece.
     *
     * @return bool Si estaba vigente
     */
    public function deshacer($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        $habia = false;

        $this->transaccion(function () use ($id, $usuario, &$habia) {
            $habia = $this->darDeBaja((int) $id, 'Deshecha', null, $usuario) > 0;
        });

        return $habia;
    }

    /**
     * Marca PAGADA las cuotas que Tango ya cancelo. La escribe el sistema al
     * cargar la pestaña, como la conciliacion de Proveedores Locales: no es una
     * decision de nadie, es copiar lo que dice Tango.
     *
     * @param array $lista De AlquileresImputacion::aplicar()['pagadas_nuevas']
     * @return int Cuantas
     */
    public function marcarPagadas($lista) {
        if (empty($lista) || !$this->tablaCreada()) {
            return 0;
        }

        $usuario = AuthCashflow::usuarioDeEscritura(self::usuarioSistema());
        $n = 0;

        foreach ($lista as $p) {
            $n += $this->ejecutar("UPDATE dbo." . self::T_FACTURA . "
                 SET ESTADO = 'PAGADA', FECHA_PAGADA = ?, FECHA_DETECTADA = GETDATE(), " . Auditoria::SET_MODIF . "
                 WHERE ID = ? AND VIGENTE = 1 AND ESTADO <> 'PAGADA'",
                [$p['fecha'], $usuario, (int) $p['id']], 'Error al marcar una factura pagada');
        }

        return $n;
    }

    /** El usuario con el que el sistema marca las pagadas */
    public static function usuarioSistema() {
        return AuthCashflow::origen('AlquileresImputaciones.marcarPagadas');
    }

    /** Baja de la cabecera y de sus cuotas. @return int filas de cabecera */
    private function darDeBaja($id, $motivo, $reemplazo, $usuario) {
        $n = $this->ejecutar("UPDATE dbo." . self::T_IMP . "
             SET VIGENTE = 0, MOTIVO_BAJA = ?, ID_REEMPLAZO = ?, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND VIGENTE = 1",
            [$motivo, $reemplazo, $usuario, $usuario, $id], 'Error al dar de baja la imputación');

        $this->ejecutar("UPDATE dbo." . self::T_FACTURA . " SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE ID_IMPUTACION = ? AND VIGENTE = 1",
            [$usuario, $usuario, $id], 'Error al dar de baja las facturas de la imputación');

        return $n;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

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

    /**
     * UNA CONEXION POR INSTANCIA: la transaccion de guardar() y sus INSERT
     * tienen que ir por el mismo handle, o el rollback no deshace nada.
     */
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
        $msg = $contexto . ': ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            /* El indice unico: otra pantalla imputo la misma cuota. */
            if (strpos($e['message'], 'UX_RO_T_CF_ALQIMPF_VIGENTE') !== false) {
                return 'Alguna de esas facturas ya se imputó desde otra pantalla mientras armabas esta. '
                    . 'Volvé a cargar la pestaña. No se guardó nada.';
            }

            $msg .= $e['message'] . ' ';
        }

        return $msg;
    }
}
