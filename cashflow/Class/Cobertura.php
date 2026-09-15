<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * Cobertura
 * La aplicacion de las inversiones para tapar los baches del flujo.
 *
 * QUE RESUELVE
 * ------------
 * El tablero proyecta el saldo dia por dia y en algunas columnas da negativo o
 * queda muy justo. La plata para cubrir eso existe -esta invertida-, pero hasta
 * ahora el tablero no tenia donde decir CUANDO se la piensa usar.
 *
 * Esta tabla guarda esa decision: cuanto se aplica y en que fecha.
 *
 * NO ES UN INGRESO Y NO SE MODELA COMO TAL
 * ----------------------------------------
 * Aplicar cobertura no genera plata: la pasa de una inversion a la cuenta. Por
 * eso la fila del tablero es de tipo USO_COBERTURA y no INGRESO, y por eso no
 * suma en el indicador de Ingresos. Lo que si hace es entrar al arrastre del
 * saldo, porque la plata efectivamente se mueve. Ver
 * CashflowEstructura::TIPOS_MOVIMIENTO y Cashflow::kpiDe().
 *
 * EL IMPORTE PUEDE SER NEGATIVO
 * -----------------------------
 * Un importe negativo es lo contrario: sacar plata de la cuenta y volver a
 * invertirla, que es lo que se hace en una columna con saldo de sobra. Es la
 * misma fila y el mismo circuito; lo unico que cambia es el signo del dato. Por
 * eso validarImporte() rechaza el cero y no los negativos: cero no es una
 * aplicacion, es no tener ninguna, y para eso esta borrar().
 *
 * UNA APLICACION VIGENTE POR FECHA
 * --------------------------------
 * La fila del tablero es una sola, asi que la pregunta que contesta esta tabla
 * es "cuanta cobertura se aplica el dia X". ORIGEN dice de que fondo sale, y es
 * un dato de la aplicacion, no parte de su identidad: dos aplicaciones el mismo
 * dia desde dos fondos distintos son una sola decision de tesoreria y se cargan
 * como un solo importe con el origen que corresponda.
 *
 * EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA
 * ---------------------------------------------------
 * Mismo circuito que Dolares Cuenta Comitente y Saldo de Inversiones, y a
 * proposito: cargar una fecha que ya existe NO hace UPDATE, marca VIGENTE = 0
 * las anteriores e inserta una fila nueva, en UNA transaccion. Borrar tampoco
 * borra: marca VIGENTE = 0 y no inserta nada.
 *
 * El historial es lo unico que explica por que el saldo proyectado de ayer era
 * otro. Con un UPDATE, corregir un dedazo y cambiar de plan son
 * indistinguibles despues del hecho.
 *
 * SI LA TABLA NO ESTA, NO SE ROMPE
 * --------------------------------
 * Las lecturas devuelven vacio y getAvisos() dice que hay que correr el script.
 * La fila del tablero muestra cero, que es lo correcto: no hay ninguna
 * cobertura aplicada.
 */
class Cobertura {

    /** Tabla de aplicaciones, en la base central */
    const TABLA = 'RO_T_CASHFLOW_COBERTURA_APLIC';

    /**
     * De donde puede salir la plata.
     *
     * Son los tres bloques de stock del Excel. La lista esta aca -y no como
     * texto libre- por el mismo motivo que las series de CashflowRegistry: un
     * campo libre termina con 'Alyc', 'ALYC' y 'Fondo Alyc' conviviendo, y
     * despues no hay forma de sumar por origen. Agregar uno es agregar una
     * entrada aca.
     *
     * OJO: el origen es DESCRIPTIVO. Hoy el unico stock que el tablero conoce
     * es el saldo de inversiones en pesos, asi que el origen no limita cuanto
     * se puede aplicar; dice de donde se piensa sacar.
     */
    const ORIGENES = [
        'INVERSIONES' => 'Inversiones (Fondo Alyc)',
        'SUSCRIPCION' => 'Suscripción',
        'DOLARES' => 'Dólares'
    ];

    /** Origen que se asume si no mandan ninguno */
    const ORIGEN_DEFECTO = 'INVERSIONES';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si ya se corrio sql/cashflow_cobertura.sql.
     *
     * @return bool
     */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de cobertura'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en pantalla.
     *
     * @return array
     */
    public function getAvisos() {
        if (!$this->tablaCreada()) {
            return ['Todavía no existe la tabla de aplicación de cobertura. '
                . 'Corré sql/cashflow_cobertura.sql contra la base central. '
                . 'Mientras tanto, la fila de cobertura del tablero se muestra en cero '
                . 'y no se puede editar.'];
        }

        return [];
    }

    /* ====================================================================
       LECTURAS
       ==================================================================== */

    /**
     * Las aplicaciones vigentes, por fecha.
     *
     * VERSIONES cuenta TODAS las cargas de esa fecha, vigentes y pisadas: es lo
     * que le dice a la pantalla que hay historial para abrir. Mismo criterio que
     * OtrosIngresos::leerVigentes().
     *
     * @return array Filas ['FECHA', 'IMPORTE', 'ORIGEN', 'OBSERVACION', ...]
     */
    public function getAplicaciones() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $cid = $this->conectar();

        $sql = "SELECT a.FECHA, a.IMPORTE, a.ORIGEN, a.OBSERVACION, a.USUARIO, a.FECHA_ALTA,
                       (SELECT COUNT(*)
                          FROM dbo." . self::TABLA . " h
                         WHERE h.FECHA = a.FECHA) AS VERSIONES
                FROM dbo." . self::TABLA . " a
                WHERE a.VIGENTE = 1
                ORDER BY a.FECHA";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las aplicaciones de cobertura'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'ORIGEN' => (string) $row['ORIGEN'],
                'OBSERVACION' => (string) $row['OBSERVACION'],
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'VERSIONES' => intval($row['VERSIONES'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Todas las cargas de una fecha, de la mas nueva a la mas vieja.
     *
     * @param string $fecha 'Y-m-d'
     * @return array
     */
    public function getHistorial($fecha) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $f = self::validarFecha($fecha);
        $cid = $this->conectar();

        $sql = "SELECT ID, FECHA, IMPORTE, ORIGEN, OBSERVACION, VIGENTE, USUARIO, FECHA_ALTA
                FROM dbo." . self::TABLA . "
                WHERE FECHA = ?
                ORDER BY ID DESC";

        $stmt = sqlsrv_query($cid, $sql, [$f]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de cobertura'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'ORIGEN' => (string) $row['ORIGEN'],
                'OBSERVACION' => (string) $row['OBSERVACION'],
                'VIGENTE' => intval($row['VIGENTE']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       ESCRITURAS
       ==================================================================== */

    /**
     * Carga la cobertura que se aplica en una fecha.
     *
     * NO HACE UPDATE. Marca VIGENTE = 0 las cargas anteriores de esa fecha e
     * inserta una fila nueva, LAS DOS COSAS EN UNA TRANSACCION: si la baja
     * confirmara y el alta fallara, la fecha se quedaria sin importe vigente y
     * el saldo proyectado cambiaria sin que nadie lo hubiera pedido.
     *
     * @param string $fecha 'Y-m-d'
     * @param mixed $importe Puede ser negativo; no puede ser cero
     * @param string|null $origen Clave de self::ORIGENES
     * @param string|null $observacion
     * @param string|null $usuario
     * @return array ['fecha', 'importe', 'origen', 'piso' => bool]
     */
    public function guardar($fecha, $importe, $origen = null, $observacion = null,
                           $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de aplicación de cobertura. '
                . 'Corré sql/cashflow_cobertura.sql contra la base central.');
        }

        $f = self::validarFecha($fecha);
        $monto = self::validarImporte($importe);
        $org = self::validarOrigen($origen);
        $obs = self::normalizarObservacion($observacion);

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $piso = $this->bajaVigentes($cid, $f);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                     (FECHA, IMPORTE, ORIGEN, OBSERVACION, VIGENTE, USUARIO)
                 VALUES (?, ?, ?, ?, 1, ?)",
                [$f, $monto, $org, $obs, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar la cobertura'));
            }

            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['fecha' => $f, 'importe' => $monto, 'origen' => $org, 'piso' => $piso];
    }

    /**
     * Saca la cobertura de una fecha.
     *
     * NO BORRA LA FILA: marca VIGENTE = 0 y no inserta ninguna nueva, con lo que
     * esa fecha queda sin aplicacion vigente. Es la baja logica del resto del
     * modulo, y aca importa especialmente: una aplicacion es una decision de
     * tesoreria, y por que se dio de baja se explica solo con el historial.
     *
     * A diferencia de Ingresos::deleteFechaManual(), que si borra fisicamente,
     * esto no es un override puntual de un calculo sino un importe. Los
     * importes de este modulo no se borran.
     *
     * @param string $fecha 'Y-m-d'
     * @return bool Si habia algo que dar de baja
     */
    public function borrar($fecha) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de aplicación de cobertura.');
        }

        $f = self::validarFecha($fecha);

        return $this->bajaVigentes($this->conectar(), $f);
    }

    /** Marca VIGENTE = 0 las cargas vigentes de una fecha. @return bool si habia alguna */
    private function bajaVigentes($cid, $fecha) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE FECHA = ? AND VIGENTE = 1",
            [$fecha]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la cobertura anterior'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return ($filas > 0);
    }

    /* ====================================================================
       HELPERS PUROS
       Estaticos y sin base: son las reglas que conviene poder probar sueltas.
       ==================================================================== */

    /**
     * Normaliza y valida la fecha de una aplicacion.
     *
     * SE ACEPTAN FECHAS PASADAS, igual que en Otros Ingresos y a diferencia de
     * la fecha de cobro manual de Cobranzas FR. Una aplicacion con fecha de
     * ayer es una que ya se hizo, y lo que el eje del tablero haga con una
     * fecha fuera del horizonte es asunto del eje, que ya lo informa.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFecha($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha de la cobertura no es válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha de la cobertura no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Normaliza y valida el importe de una aplicacion.
     *
     * EL NEGATIVO ES VALIDO y el cero no. Un negativo es devolver plata a la
     * inversion, que es una decision tan real como aplicarla. Un cero no es una
     * aplicacion de cero pesos: es no tener ninguna, y eso se expresa dando de
     * baja la fecha, que ademas deja rastro en el historial.
     *
     * @param mixed $importe
     * @return float
     */
    public static function validarImporte($importe) {
        if ($importe === null || $importe === '' || !is_numeric($importe)) {
            throw new Exception('El importe de la cobertura tiene que ser un número.');
        }

        $v = round(floatval($importe), 2);

        if ($v == 0) {
            throw new Exception('El importe no puede ser cero. Para sacar la cobertura de '
                . 'esta fecha, borrala: así queda el registro de que se dio de baja.');
        }

        return $v;
    }

    /**
     * Normaliza el origen del fondo contra self::ORIGENES.
     *
     * Un origen desconocido NO se guarda como vino ni se descarta en silencio:
     * se rechaza. Es una clave, y una clave que nadie declaro no se puede
     * agrupar ni mostrar con su nombre.
     *
     * @param mixed $origen null usa ORIGEN_DEFECTO
     * @return string
     */
    public static function validarOrigen($origen) {
        if ($origen === null || $origen === '') {
            return self::ORIGEN_DEFECTO;
        }

        $o = strtoupper(trim((string) $origen));

        if (!isset(self::ORIGENES[$o])) {
            throw new Exception('El origen "' . $origen . '" no está declarado. '
                . 'Los válidos son: ' . implode(', ', array_keys(self::ORIGENES)) . '.');
        }

        return $o;
    }

    /** Recorta la observacion al largo de la columna @return string|null */
    public static function normalizarObservacion($observacion) {
        $o = trim((string) $observacion);

        return ($o === '') ? null : mb_substr($o, 0, 200);
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** Un DATETIME de SQL Server como texto, o null */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
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
