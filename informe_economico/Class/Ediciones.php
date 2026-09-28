<?php
/**
 * Ediciones
 * Correccion a mano de importes de RO_T_RESUMEN_FINAL_IE, con historial.
 *
 * QUE SE EDITA Y QUE NO
 * ---------------------
 * Solo el IMPORTE de registros que ya existen, siempre por ID. No se crean ni
 * se borran registros. Las filas calculadas y las columnas de subtotal y
 * total no se editan: el front no las hace clickeables y aca todo entra por
 * ID, asi que no hay forma de "editar un total".
 *
 * LAS EDICIONES LAS VEN TODOS LOS LECTORES DE LA TABLA
 * ----------------------------------------------------
 * No es una capa de ajustes del informe: se escribe en el resumen. Impacta en
 * rentabilidad_rubro y en cualquier otro que lo lea. Y un reproceso desde
 * Control de Gastos la pisa (borra y reinserta el periodo), que es lo
 * esperado: el historial queda como referencia.
 *
 * CONCURRENCIA
 * ------------
 * El UPDATE va por ID, en una transaccion, y solo si el importe sigue siendo
 * el que vio el usuario. Si cambio (otro usuario, un reproceso) o el UPDATE no
 * afecta exactamente una fila, no se escribe nada y se avisa.
 */
require_once __DIR__ . '/BaseIE.php';
require_once __DIR__ . '/Canales.php';
require_once __DIR__ . '/Periodo.php';
require_once __DIR__ . '/HistorialEdicion.php';

class Ediciones {

    const TABLA = 'RO_T_RESUMEN_FINAL_IE';
    const HISTORIAL = 'RO_T_IE_RESUMEN_EDICION';

    private $cid;

    public function __construct($cid) {
        $this->cid = $cid;
    }

    public function disponible() {
        return BaseIE::existe($this->cid, self::HISTORIAL);
    }

    /** Si un mes tiene resumen: solo esos se ven y se editan */
    private function conResumen(array $periodos) {
        if (!$periodos) {
            return [];
        }

        $f = BaseIE::filas($this->cid, 'SELECT DISTINCT PERIODO FROM ' . self::TABLA . ' WHERE PERIODO IN ('
            . BaseIE::marcas(count($periodos)) . ') AND COD_RUBRO NOT IN (' . BaseIE::marcas(count(Periodo::RUBROS_PASO_2)) . ')',
            array_merge($periodos, Periodo::RUBROS_PASO_2));

        return array_column($f, 'PERIODO');
    }

    /** WHERE de sucursales: numeros, y/o "sin sucursal" (NULL o 0) */
    private static function filtroSucursal(array $sucursales, array &$params) {
        $nums = [];
        $sin = false;

        foreach ($sucursales as $s) {
            if ($s === Canales::CLAVE_SIN) {
                $sin = true;
            } elseif (is_numeric($s)) {
                $nums[] = (int) $s;
            }
        }

        $partes = [];

        if ($nums) {
            $partes[] = 'NRO_SUCURSAL IN (' . BaseIE::marcas(count($nums)) . ')';
            $params = array_merge($params, $nums);
        }

        if ($sin) {
            $partes[] = '(NRO_SUCURSAL IS NULL OR NRO_SUCURSAL = 0)';
        }

        return $partes ? '(' . implode(' OR ', $partes) . ')' : '1 = 0';
    }

    /**
     * El detalle de una celda: un renglon por REGISTRO de la tabla (los
     * repetidos, cada uno por separado), con su historial y su estado.
     *
     * @return array ['registros' => [...], 'perdidas' => [...], 'mesesExcluidos' => [...]]
     */
    public function detalle(array $sucursales, array $periodos, $cod) {
        $periodos = array_values(array_filter($periodos, [Periodo::class, 'valido']));
        $ok = $this->conResumen($periodos);
        $excluidos = array_values(array_diff($periodos, $ok));

        if (!$ok) {
            return ['registros' => [], 'perdidas' => [], 'mesesExcluidos' => $excluidos];
        }

        $params = array_merge([(string) $cod], $ok);
        $where = 'COD_RUBRO = ? AND PERIODO IN (' . BaseIE::marcas(count($ok)) . ') AND ' . self::filtroSucursal($sucursales, $params);

        $regs = BaseIE::filas($this->cid, 'SELECT ID, PERIODO, NRO_SUCURSAL, DESC_SUCURSAL, COD_RUBRO, RUBRO_CONTABLE, IMPORTE FROM '
            . self::TABLA . ' WHERE ' . $where, $params);

        $movs = [];

        if ($this->disponible()) {
            $paramsH = array_merge([(string) $cod], $ok);
            $whereH = 'COD_RUBRO = ? AND PERIODO IN (' . BaseIE::marcas(count($ok)) . ') AND ' . self::filtroSucursal($sucursales, $paramsH);

            foreach (BaseIE::filas($this->cid, 'SELECT ID, ID_REGISTRO, PERIODO, NRO_SUCURSAL, COD_RUBRO, TIPO, IMPORTE_ANTERIOR, IMPORTE_NUEVO, MOTIVO, USUARIO, FECHA FROM '
                . self::HISTORIAL . ' WHERE ' . $whereH . ' ORDER BY ID_REGISTRO, ID', $paramsH) as $m) {
                $movs[(int) $m['ID_REGISTRO']][] = $m;
            }
        }

        $out = [];
        $vivos = [];

        foreach ($regs as $r) {
            $id = (int) $r['ID'];
            $vivos[$id] = true;
            $h = $movs[$id] ?? [];
            $e = HistorialEdicion::estado($h, (float) $r['IMPORTE']);
            $out[] = [
                'id' => $id,
                'periodo' => $r['PERIODO'],
                'nroSucursal' => $r['NRO_SUCURSAL'],
                'descSucursal' => $r['DESC_SUCURSAL'],
                'rubro' => $r['RUBRO_CONTABLE'],
                'importe' => (float) $r['IMPORTE'],
                'historial' => $h,
                'editado' => $e['editado'],
                'pisado' => $e['pisado'],
                'original' => $e['original'],
                'deshacerA' => $e['deshacerA'],
                'restaurarA' => $e['restaurarA']
            ];
        }

        usort($out, function ($a, $b) {
            return Periodo::comparar($a['periodo'], $b['periodo'])
                ?: ((int) $a['nroSucursal'] <=> (int) $b['nroSucursal'])
                ?: ($a['id'] <=> $b['id']);
        });

        // Ediciones cuyo registro ya no existe: un reproceso borro el periodo
        // y lo volvio a insertar con otros IDs. Solo lectura.
        $perdidas = [];

        foreach ($movs as $id => $lista) {
            if (!isset($vivos[$id])) {
                foreach ($lista as $m) {
                    $perdidas[] = $m;
                }
            }
        }

        return ['registros' => $out, 'perdidas' => $perdidas, 'mesesExcluidos' => $excluidos];
    }

    /**
     * Aplica un movimiento: EDICION, DESHACER o RESTAURAR.
     *
     * @param int $id
     * @param float $visto el importe que tenia la pantalla del usuario
     * @param string|null $nuevoTexto el importe escrito (solo EDICION)
     * @param string $motivo
     * @param string $tipo
     * @return array ['importe' => el valor nuevo]
     */
    public function aplicar($id, $visto, $nuevoTexto, $motivo, $tipo, $usuario) {
        if (!$this->disponible()) {
            throw new RuntimeException('Falta correr sql/ie_resumen_edicion.sql: no se puede editar sin historial.');
        }

        if (!$usuario) {
            throw new RuntimeException('No hay usuario en la sesión: no se puede auditar el cambio.');
        }

        $err = HistorialEdicion::validarMotivo($motivo);

        if ($err) {
            throw new InvalidArgumentException($err);
        }

        if (!is_numeric($visto)) {
            throw new InvalidArgumentException('Falta el importe que se estaba viendo.');
        }

        $nuevo = null;

        if ($tipo === HistorialEdicion::EDICION) {
            $v = HistorialEdicion::validarImporte($nuevoTexto);

            if (!$v['ok']) {
                throw new InvalidArgumentException($v['error']);
            }

            $nuevo = $v['valor'];
        } elseif (!in_array($tipo, [HistorialEdicion::DESHACER, HistorialEdicion::RESTAURAR], true)) {
            throw new InvalidArgumentException('Movimiento desconocido.');
        }

        if (!sqlsrv_begin_transaction($this->cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $r = BaseIE::filas($this->cid, 'SELECT ID, PERIODO, NRO_SUCURSAL, COD_RUBRO, IMPORTE FROM ' . self::TABLA
                . ' WITH (UPDLOCK, ROWLOCK) WHERE ID = ?', [(int) $id]);

            if (count($r) !== 1) {
                throw new RuntimeException('El registro ya no existe: probablemente un reproceso regeneró el período. Recargá el informe.');
            }

            $r = $r[0];
            $actual = (float) $r['IMPORTE'];

            if (!HistorialEdicion::iguales($actual, $visto)) {
                throw new RuntimeException('El importe cambió desde que lo abriste (ahora es ' . number_format($actual, 2, ',', '.')
                    . '). No se guardó nada: recargá y volvé a intentar.');
            }

            if (!$this->conResumen([$r['PERIODO']])) {
                throw new RuntimeException('El período ' . $r['PERIODO'] . ' no tiene resumen: no se edita.');
            }

            if ($tipo !== HistorialEdicion::EDICION) {
                $movs = BaseIE::filas($this->cid, 'SELECT TIPO, IMPORTE_ANTERIOR, IMPORTE_NUEVO, USUARIO, FECHA, MOTIVO FROM '
                    . self::HISTORIAL . ' WHERE ID_REGISTRO = ? ORDER BY ID', [(int) $id]);
                $e = HistorialEdicion::estado($movs, $actual);

                if ($e['pisado']) {
                    throw new RuntimeException('Un reproceso pisó este registro: deshacer y restaurar ya no aplican.');
                }

                $nuevo = $tipo === HistorialEdicion::DESHACER ? $e['deshacerA'] : $e['restaurarA'];

                if ($nuevo === null) {
                    throw new RuntimeException($tipo === HistorialEdicion::DESHACER ? 'No hay nada para deshacer.' : 'Ya está en el valor original.');
                }
            } elseif (HistorialEdicion::iguales($nuevo, $actual)) {
                throw new InvalidArgumentException('El importe nuevo es igual al actual.');
            }

            $n = BaseIE::ejecutar($this->cid, 'UPDATE ' . self::TABLA . ' SET IMPORTE = ? WHERE ID = ? AND ABS(IMPORTE - ?) < ?',
                [$nuevo, (int) $id, $actual, HistorialEdicion::TOLERANCIA]);

            if ($n !== 1) {
                throw new RuntimeException('La actualización afectó ' . $n . ' filas en lugar de 1: no se guardó nada.');
            }

            // El primer movimiento de un ID guarda como anterior el valor del
            // proceso: es el "original" al que vuelve Restaurar.
            BaseIE::ejecutar($this->cid, 'INSERT INTO ' . self::HISTORIAL
                . ' (ID_REGISTRO, PERIODO, NRO_SUCURSAL, COD_RUBRO, IMPORTE_ANTERIOR, IMPORTE_NUEVO, MOTIVO, TIPO, USUARIO, FECHA)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())',
                [(int) $id, $r['PERIODO'], $r['NRO_SUCURSAL'], $r['COD_RUBRO'], $actual, $nuevo, trim($motivo), $tipo, $usuario]);

            sqlsrv_commit($this->cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($this->cid);
            throw $t;
        }

        return ['importe' => $nuevo];
    }
}
