<?php

require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * AlquileresTiposRetencion
 * Los tipos de retencion con los que se carga una imputacion de facturas de
 * alquiler (Ganancias, IIBB Santa Fe, IIBB CABA, IIBB Bs As...). Se editan en
 * Parametros -> Alquileres -> Tipos de retencion. La tabla es del script 60
 * (sql/cashflow_alquileres_imputacion_razon.sql).
 *
 * POR QUE TIPOS Y NO UN IMPORTE SUELTO. La retencion de una factura de
 * alquiler no sale de un % del local: la calcula quien paga, por impuesto, y
 * es lo que despues se concilia contra los certificados. Una linea por tipo
 * dice cuanto es de que, y que un tipo no se repita en la misma imputacion lo
 * garantiza la clave de la tabla de retenciones.
 *
 * UNA FILA VIGENTE POR NOMBRE, CON HISTORIAL. Renombrar es dar de baja la fila
 * y dar de alta otra (ID_REEMPLAZO dice cual): una imputacion vieja guarda el
 * ID del tipo con el que se cargo y sigue mostrando ESE nombre. Dar de baja
 * saca el tipo del desplegable de las imputaciones nuevas; las que ya lo usan
 * lo siguen mostrando, porque nombres() lee tambien los dados de baja.
 *
 * EL NOMBRE ES UNICO ENTRE LOS VIGENTES SIN DISTINGUIR MAYUSCULAS NI ACENTOS.
 * Lo valida validarNombre(), que es pura, y lo vuelve a frenar el indice unico
 * filtrado (la columna es CI_AI) si dos pantallas guardan a la vez.
 *
 * SIN EL SCRIPT no hay tipos: la seccion de Parametros avisa nombrando el
 * script y la imputacion no se puede guardar (tampoco existen sus tablas).
 */
class AlquileresTiposRetencion {

    const T_TIPO = 'RO_T_CASHFLOW_ALQ_TIPO_RETENCION';
    const SCRIPT = 'sql/cashflow_alquileres_imputacion_razon.sql';
    const LARGO_NOMBRE = 60;

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
     * Un nombre para comparar: sin acentos, en minusculas y con un solo
     * espacio. Es la misma comparacion que hace la collation CI_AI de la
     * columna, para que la pantalla diga lo mismo que el indice.
     */
    public static function clave($nombre) {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $nombre)), 'UTF-8');

        return strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                          'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }

    /**
     * Valida el nombre de un tipo. Pura.
     *
     * @param string $nombre
     * @param array $vigentes Lista de ['id', 'nombre'] de los vigentes
     * @param int|null $propio El ID que se esta renombrando: no choca consigo mismo
     * @return string El nombre limpio
     * @throws InvalidArgumentException
     */
    public static function validarNombre($nombre, $vigentes, $propio = null) {
        $n = trim(preg_replace('/\s+/u', ' ', (string) $nombre));

        if ($n === '') {
            throw new InvalidArgumentException('Falta el nombre del tipo de retención.');
        }

        if (mb_strlen($n, 'UTF-8') > self::LARGO_NOMBRE) {
            throw new InvalidArgumentException('El nombre puede tener hasta ' . self::LARGO_NOMBRE . ' caracteres.');
        }

        foreach ($vigentes as $t) {
            if ((int) $t['id'] !== (int) $propio && self::clave($t['nombre']) === self::clave($n)) {
                throw new InvalidArgumentException('Ya hay un tipo de retención «' . $t['nombre'] . '»: el nombre no '
                    . 'distingue mayúsculas ni acentos.');
            }
        }

        return $n;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio el script 60 */
    public function tablaCreada() {
        if ($this->tabla === null) {
            try {
                $r = $this->fila("SELECT OBJECT_ID(?, 'U') AS ID", ['dbo.' . self::T_TIPO], 'Error al verificar la tabla');
                $this->tabla = ($r !== null && $r['ID'] !== null);
            } catch (Throwable $e) {
                $this->tabla = false;
            }
        }

        return $this->tabla;
    }

    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' : 'Todavía no hay tipos de retención: falta correr ' . self::SCRIPT
            . ' contra la base central. Sin eso tampoco se pueden imputar facturas de alquiler.';
    }

    /**
     * Todos los tipos, vigentes y dados de baja.
     *
     * @return array Lista de ['id', 'nombre', 'vigente', 'id_reemplazo', + auditoria], los vigentes primero
     */
    public function todos() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $out = [];

        foreach ($this->filas("SELECT ID, NOMBRE, VIGENTE, ID_REEMPLAZO, USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF,
                                      FECHA_MODIF, USUARIO_BAJA, FECHA_BAJA
                               FROM dbo." . self::T_TIPO . " ORDER BY VIGENTE DESC, NOMBRE, ID", [],
                              'Error al leer los tipos de retención') as $r) {
            $out[] = [
                'id' => (int) $r['ID'],
                'nombre' => $r['NOMBRE'],
                'vigente' => ((int) $r['VIGENTE']) === 1,
                'id_reemplazo' => ($r['ID_REEMPLAZO'] === null) ? null : (int) $r['ID_REEMPLAZO'],
                'usuario_alta' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA']),
                'usuario_modif' => $r['USUARIO_MODIF'], 'fecha_modif' => self::momento($r['FECHA_MODIF']),
                'usuario_baja' => $r['USUARIO_BAJA'], 'fecha_baja' => self::momento($r['FECHA_BAJA'])
            ];
        }

        return $out;
    }

    /** @return array Lista de los vigentes: los que se ofrecen para imputar */
    public function vigentes() {
        return array_values(array_filter($this->todos(), function ($t) { return $t['vigente']; }));
    }

    /** @return array ID => nombre, de TODOS: una imputacion vieja muestra el tipo con el que se cargo */
    public function nombres() {
        return array_column($this->todos(), 'nombre', 'id');
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    public function alta($nombre, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();
        $n = self::validarNombre($nombre, $this->vigentes());

        $r = $this->fila("INSERT INTO dbo." . self::T_TIPO . " (NOMBRE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                          OUTPUT INSERTED.ID VALUES (?, 1, ?, ?)", [$n, $usuario, $usuario],
                         'Error al dar de alta el tipo de retención');

        return ['id' => (int) $r['ID'], 'nombre' => $n];
    }

    /**
     * Renombra un tipo: baja de la fila y alta de otra, en una transaccion.
     * Las imputaciones que ya lo usan siguen con el nombre viejo; las nuevas
     * eligen el nuevo.
     */
    public function renombrar($id, $nombre, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();
        $i = (int) $id;
        $vig = $this->vigentes();

        if (!in_array($i, array_column($vig, 'id'), true)) {
            throw new InvalidArgumentException('El tipo de retención ' . $i . ' no está vigente.');
        }

        $n = self::validarNombre($nombre, $vig, $i);

        if ($n === array_column($vig, 'nombre', 'id')[$i]) {
            return ['id' => $i, 'nombre' => $n, 'igual' => true];
        }

        $nuevo = null;

        $this->transaccion(function () use ($i, $n, $usuario, &$nuevo) {
            $this->ejecutar("UPDATE dbo." . self::T_TIPO . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
                            . " WHERE ID = ? AND VIGENTE = 1", [$usuario, $usuario, $i], 'Error al renombrar');
            $r = $this->fila("INSERT INTO dbo." . self::T_TIPO . " (NOMBRE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                              OUTPUT INSERTED.ID VALUES (?, 1, ?, ?)", [$n, $usuario, $usuario], 'Error al renombrar');
            $nuevo = (int) $r['ID'];
            $this->ejecutar("UPDATE dbo." . self::T_TIPO . " SET ID_REEMPLAZO = ? WHERE ID = ?", [$nuevo, $i],
                            'Error al renombrar');
        });

        return ['id' => $nuevo, 'nombre' => $n, 'igual' => false];
    }

    public function baja($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        if ($this->ejecutar("UPDATE dbo." . self::T_TIPO . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
                            . " WHERE ID = ? AND VIGENTE = 1", [$usuario, $usuario, (int) $id],
                            'Error al dar de baja el tipo de retención') < 1) {
            throw new InvalidArgumentException('El tipo de retención ' . (int) $id . ' no estaba vigente.');
        }

        return ['id' => (int) $id];
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

    private static function momento($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : (($v === null || $v === '') ? null : substr((string) $v, 0, 16));
    }

    /** UNA CONEXION POR INSTANCIA: la transaccion de renombrar va por el mismo handle. */
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
            /* El indice unico: otra pantalla dio de alta el mismo nombre. */
            if (strpos($e['message'], 'UX_RO_T_CF_ALQTRET_VIGENTE') !== false) {
                return 'Ya hay un tipo de retención con ese nombre (se cargó desde otra pantalla). Volvé a cargar.';
            }
        }

        $msg = $contexto . ': ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
