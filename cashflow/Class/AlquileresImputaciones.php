<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/AlquileresImputacion.php';

/**
 * AlquileresImputaciones
 * Lee y escribe las imputaciones de facturas de alquiler por razon social
 * (sql/cashflow_alquileres_imputacion_razon.sql, el script 60).
 *
 * NO DECIDE NADA. Que es valido, que reemplaza y cuanto se paga es
 * AlquileresImputacion, que es pura; guardar() recibe lo que ella ya valido.
 *
 * SIN BAJAS FISICAS. Deshacer es VIGENTE = 0 con quien y cuando, en la
 * cabecera y en sus cuotas; para corregir una imputacion se deshace y se arma
 * otra. El indice unico filtrado de las cuotas frena a dos pantallas
 * imputando la misma cuota a la vez: la segunda falla.
 *
 * DOS IMPUTACIONES NO PISAN LO MISMO (misma razon, mismo mes, algun local en
 * comun). Lo valida AlquileresImputacion::validar() con lo que leyo; guardar()
 * lo vuelve a mirar DENTRO de la transaccion, con las cabeceras de esa razon y
 * ese mes bloqueadas (UPDLOCK, HOLDLOCK): entre validar e insertar otra
 * pantalla pudo haber guardado.
 *
 * LAS CONSULTAS SON POR TABLA Y LOS CRUCES EN PHP, como el resto del modulo.
 *
 * SIN EL SCRIPT, vigentes() devuelve vacio -no hay ninguna imputada, que es lo
 * cierto- y lo que falla, con el nombre del script, es guardar. Las tablas del
 * modelo de renglones, si siguen ahi, no se leen.
 */
class AlquileresImputaciones {

    const SCRIPT = 'sql/cashflow_alquileres_imputacion_razon.sql';

    const T_IMP = 'RO_T_CASHFLOW_ALQ_IMPUTACION_RS';
    const T_CUOTA = 'RO_T_CASHFLOW_ALQ_IMPRS_CUOTA';
    const T_LOCAL = 'RO_T_CASHFLOW_ALQ_IMPRS_LOCAL';
    const T_RETENCION = 'RO_T_CASHFLOW_ALQ_IMPRS_RETENCION';

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

    /** @return bool Si ya se corrio el script 60 (mira las cuatro tablas de la imputacion) */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $row = $this->fila("SELECT OBJECT_ID('dbo." . self::T_IMP . "', 'U') AS A, OBJECT_ID('dbo." . self::T_CUOTA
                . "', 'U') AS B, OBJECT_ID('dbo." . self::T_LOCAL . "', 'U') AS C, OBJECT_ID('dbo." . self::T_RETENCION
                . "', 'U') AS D", [], 'Error al verificar las tablas');
            $this->tabla = ($row && $row['A'] !== null && $row['B'] !== null && $row['C'] !== null && $row['D'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' : 'Todavía no se pueden imputar facturas de alquiler: falta correr '
            . self::SCRIPT . ' contra la base central. Mientras tanto las facturas se ven pero no suman, y la '
            . 'proyección no se reemplaza con nada.';
    }

    /**
     * Las imputaciones vigentes, armadas.
     *
     * @return array Lista de ['id', 'cod_provee', 'mes', 'todos', 'locales' => [nro], 'observacion',
     *               'total_facturas', 'total_retenciones', 'usuario', 'fecha',
     *               'facturas' => [['ID', 'COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_VTO_TANGO', 'PENDIENTE',
     *                               'RETENCION', 'PAGADA_AL_IMPUTAR', 'ESTADO', 'FECHA_PAGADA']],
     *               'retenciones' => [['tipo', 'importe']]]
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $imps = [];

        foreach ($this->filas("SELECT ID, COD_PROVEE, MES_CUBRE, TODOS_LOCALES, OBSERVACION, TOTAL_FACTURAS,
                                      TOTAL_RETENCIONES, USUARIO_ALTA, FECHA_ALTA
                               FROM dbo." . self::T_IMP . " WHERE VIGENTE = 1 ORDER BY ID", [],
                              'Error al leer las imputaciones') as $r) {
            $imps[(int) $r['ID']] = [
                'id' => (int) $r['ID'], 'cod_provee' => Planilla::codigo($r['COD_PROVEE']),
                'mes' => trim((string) $r['MES_CUBRE']), 'todos' => ((int) $r['TODOS_LOCALES']) === 1,
                'locales' => [], 'observacion' => $r['OBSERVACION'],
                'total_facturas' => (float) $r['TOTAL_FACTURAS'], 'total_retenciones' => (float) $r['TOTAL_RETENCIONES'],
                'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA']),
                'facturas' => [], 'retenciones' => []
            ];
        }

        if (empty($imps)) {
            return [];
        }

        $ids = array_keys($imps);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        foreach ($this->filas("SELECT ID, ID_IMPUTACION, COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, PENDIENTE,
                                      RETENCION, PAGADA_AL_IMPUTAR, ESTADO, FECHA_PAGADA
                               FROM dbo." . self::T_CUOTA . " WHERE VIGENTE = 1 ORDER BY ID", [],
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
                    'RETENCION' => (float) $r['RETENCION'],
                    'PAGADA_AL_IMPUTAR' => ((int) $r['PAGADA_AL_IMPUTAR']) === 1,
                    'ESTADO' => trim((string) $r['ESTADO']),
                    'FECHA_PAGADA' => self::dia($r['FECHA_PAGADA'])
                ];
            }
        }

        foreach ($this->filas("SELECT ID_IMPUTACION, NRO_LOCAL FROM dbo." . self::T_LOCAL
                              . " WHERE ID_IMPUTACION IN ($marcas) ORDER BY NRO_LOCAL", $ids,
                              'Error al leer los locales de las imputaciones') as $r) {
            $imps[(int) $r['ID_IMPUTACION']]['locales'][] = (int) $r['NRO_LOCAL'];
        }

        foreach ($this->filas("SELECT ID_IMPUTACION, ID_TIPO, IMPORTE FROM dbo." . self::T_RETENCION
                              . " WHERE ID_IMPUTACION IN ($marcas)", $ids,
                              'Error al leer las retenciones de las imputaciones') as $r) {
            $imps[(int) $r['ID_IMPUTACION']]['retenciones'][] = ['tipo' => (int) $r['ID_TIPO'],
                                                                 'importe' => (float) $r['IMPORTE']];
        }

        return array_values($imps);
    }

    /**
     * Guarda una imputacion ya validada (AlquileresImputacion::validar() con los
     * pendientes de AHORA). Una transaccion.
     *
     * @param array $v Lo de validar(), con 'ok' = true
     * @param string|null $observacion
     * @param string $usuario
     * @return array ['id']
     */
    public function guardar($v, $observacion, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException($this->avisoSinTabla());
        }

        if (empty($v['ok'])) {
            throw new InvalidArgumentException('La imputación no es válida: ' . implode(' ', $v['errores'] ?? []));
        }

        $obs = trim((string) $observacion);
        $obs = ($obs === '') ? null : mb_substr($obs, 0, self::LARGO_OBSERVACION);
        $id = null;

        $this->transaccion(function () use ($v, $obs, $usuario, &$id) {
            /* LA SUPERPOSICION, OTRA VEZ, CON LAS CABECERAS BLOQUEADAS: otra
               pantalla pudo guardar entre la validacion y este momento. */
            $ids = array_map('intval', array_column($this->filas("SELECT ID FROM dbo." . self::T_IMP
                . " WITH (UPDLOCK, HOLDLOCK) WHERE VIGENTE = 1 AND COD_PROVEE = ? AND MES_CUBRE = ?",
                [$v['cod_provee'], $v['mes']], 'Error al verificar las imputaciones del mes'), 'ID'));

            if (!empty($ids)) {
                $marcas = implode(',', array_fill(0, count($ids), '?'));

                foreach ($this->filas("SELECT ID_IMPUTACION, NRO_LOCAL FROM dbo." . self::T_LOCAL
                                      . " WHERE ID_IMPUTACION IN ($marcas)", $ids,
                                      'Error al verificar los locales del mes') as $o) {
                    if (in_array((int) $o['NRO_LOCAL'], array_map('intval', $v['locales']), true)) {
                        throw new InvalidArgumentException('Mientras armabas esta, otra pantalla imputó '
                            . $v['cod_provee'] . ' en ' . $v['mes'] . ' a un local en común (imputación #'
                            . $o['ID_IMPUTACION'] . '). Volvé a cargar la pestaña. No se guardó nada.');
                    }
                }
            }

            $row = $this->fila("INSERT INTO dbo." . self::T_IMP . "
                    (COD_PROVEE, MES_CUBRE, TODOS_LOCALES, OBSERVACION, TOTAL_FACTURAS, TOTAL_RETENCIONES, VIGENTE,
                     USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$v['cod_provee'], $v['mes'], $v['todos'] ? 1 : 0, $obs, $v['total_facturas'], $v['total_retenciones'],
                 $usuario, $usuario], 'Error al guardar la imputación');
            $id = (int) $row['ID'];

            foreach ($v['facturas'] as $f) {
                $p = $f['fila'];
                $this->ejecutar("INSERT INTO dbo." . self::T_CUOTA . "
                        (ID_IMPUTACION, COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, PENDIENTE, RETENCION,
                         PAGADA_AL_IMPUTAR, VIGENTE, ESTADO, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'PENDIENTE', ?, ?)",
                    [$id, Planilla::codigo($p['COD_PROVEE']), Planilla::codigo($p['T_COMP']),
                     Planilla::codigo($p['N_COMP']), substr((string) $p['FECHA_VTO'], 0, 10), $f['pendiente'],
                     $f['retencion'], $f['pagada'] ? 1 : 0, $usuario, $usuario],
                    'Error al guardar una factura de la imputación');
            }

            foreach ($v['locales'] as $nro) {
                $this->ejecutar("INSERT INTO dbo." . self::T_LOCAL . " (ID_IMPUTACION, NRO_LOCAL) VALUES (?, ?)",
                    [$id, (int) $nro], 'Error al guardar un local de la imputación');
            }

            foreach ($v['retenciones'] as $r) {
                $this->ejecutar("INSERT INTO dbo." . self::T_RETENCION . " (ID_IMPUTACION, ID_TIPO, IMPORTE) VALUES (?, ?, ?)",
                    [$id, $r['tipo'], $r['importe']], 'Error al guardar una retención');
            }
        });

        return ['id' => $id];
    }

    /**
     * Deshace una imputacion: baja logica de la cabecera y de sus cuotas. Las
     * cuotas vuelven a Sin imputar y la proyeccion que reemplazaba reaparece.
     *
     * @return bool Si estaba vigente
     */
    public function deshacer($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException($this->avisoSinTabla());
        }

        $habia = false;

        $this->transaccion(function () use ($id, $usuario, &$habia) {
            $habia = $this->ejecutar("UPDATE dbo." . self::T_IMP . "
                 SET VIGENTE = 0, MOTIVO_BAJA = 'Deshecha', " . Auditoria::SET_BAJA . "
                 WHERE ID = ? AND VIGENTE = 1",
                [$usuario, $usuario, (int) $id], 'Error al deshacer la imputación') > 0;

            $this->ejecutar("UPDATE dbo." . self::T_CUOTA . " SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                 WHERE ID_IMPUTACION = ? AND VIGENTE = 1",
                [$usuario, $usuario, (int) $id], 'Error al deshacer las facturas de la imputación');
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
            $n += $this->ejecutar("UPDATE dbo." . self::T_CUOTA . "
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
            if (strpos($e['message'], 'UX_RO_T_CF_ALQIRC_VIGENTE') !== false) {
                return 'Alguna de esas facturas ya se imputó desde otra pantalla mientras armabas esta. '
                    . 'Volvé a cargar la pestaña. No se guardó nada.';
            }

            $msg .= $e['message'] . ' ';
        }

        return $msg;
    }
}
