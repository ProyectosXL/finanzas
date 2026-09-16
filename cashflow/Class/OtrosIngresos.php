<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Cotizacion.php';

/**
 * OtrosIngresos
 * Los ingresos que no vienen de ningun circuito del sistema y los carga una
 * persona. Hoy hay dos: los dolares de la cuenta comitente y el saldo de
 * inversiones.
 *
 * POR QUE ES UNA CATEGORIA Y NO UNA PESTANA MAS DE INGRESOS
 * ---------------------------------------------------------
 * Ingresos agrupa lo que sale de un circuito -ventas, cobranzas, echeqs-, y lo
 * de aca se tipea. La diferencia importa a la hora de leer un numero: en una
 * fila de Ingresos un cero significa "no hay movimientos", y en una de aca
 * significa "nadie cargo nada todavia". La categoria queda armada para que
 * sumar un concepto nuevo sea agregar una pestana y no rediseniar nada.
 *
 * CADA CONCEPTO SE GUARDA EN LA MONEDA EN LA QUE SE INFORMA
 * ---------------------------------------------------------
 * Dolares Cuenta Comitente guarda DOLARES y la conversion la hace el proveedor
 * con el oficial del BCRA, igual que ComexProvider. Guardar pesos congelaria la
 * valuacion al momento de la carga: el dia que cambie el tipo de cambio, el
 * tablero seguiria mostrando la conversion vieja y no habria forma de notarlo.
 *
 * Saldo de Inversiones guarda PESOS, y no es una inconsistencia: ese saldo se
 * informa en pesos, asi que no hay nada que valuar. Convertirlo seria inventar
 * una moneda de origen que el dato no tiene. La decision, y como darla vuelta,
 * estan documentadas arriba de sql/cashflow_saldo_inversiones.sql.
 *
 * Los dos circuitos son por lo demas IDENTICOS -formulario minimo, sin baja
 * fisica, historial por fecha- y los metodos van en paralelo en esta misma
 * clase en vez de en una clase nueva: es un concepto mas de la categoria, no
 * otro modelo de datos.
 *
 * EL IMPORTE VIGENTE SE PISA, PERO EL HISTORIAL QUEDA
 * ---------------------------------------------------
 * Cargar una fecha que ya existe NO hace UPDATE: marca VIGENTE = 0 las
 * anteriores de esa fecha e inserta una fila nueva, en UNA transaccion. Nunca
 * hay baja fisica, igual que en el resto del modulo.
 *
 * El historial no es decoracion: es lo unico que explica por que el numero de
 * ayer era otro. Con un UPDATE, corregir un dedazo y cargar un dato nuevo son
 * indistinguibles despues del hecho.
 *
 * SI LA TABLA NO ESTA, NO SE ROMPE
 * --------------------------------
 * Se avisa y se devuelve vacio, igual que hace Echeqs con su maestro. La fila
 * del tablero muestra cero, que es el comportamiento correcto: la fila existe y
 * dice cero hasta que haya datos.
 */
class OtrosIngresos {

    /**
     * Los dos conceptos, con su tabla y el nombre de su columna de importe.
     *
     * El nombre de la tabla se intercala en el SQL, y puede: es una constante
     * del codigo y no entrada del usuario. Esta declarada aca -y no repetida en
     * cada consulta- para que la tabla y el campo esten escritos UNA vez, que es
     * lo que permite que los dos circuitos compartan la plomeria en lugar de ser
     * cinco metodos copiados con otro nombre de tabla.
     */
    const DOLARES = ['tabla' => 'RO_T_CASHFLOW_DOLARES_COMITENTE', 'campo' => 'IMPORTE_USD'];
    const INVERSIONES = ['tabla' => 'RO_T_CASHFLOW_SALDO_INVERSIONES', 'campo' => 'IMPORTE_ARS'];

    /**
     * Con que punta se valuan los dolares de la cuenta comitente.
     *
     * VENDEDOR, y es la unica pantalla del modulo que no usa comprador: estos
     * dolares se miden contra lo que costaria reponerlos. Esta escrito una sola
     * vez y aca, que es donde se hace la cuenta. Ver valuarDolares().
     */
    const PUNTA = Cotizacion::VENDEDOR;

    /** @var Conexion */
    private $conn;

    /** @var array Cache de existe(), una entrada por tabla */
    private $tablas = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si ya se corrio sql/cashflow_dolares_comitente.sql.
     *
     * @return bool
     */
    public function tablaCreada() {
        return $this->existe(self::DOLARES);
    }

    /**
     * Si ya se corrio sql/cashflow_saldo_inversiones.sql.
     *
     * @return bool
     */
    public function tablaInversionesCreada() {
        return $this->existe(self::INVERSIONES);
    }

    /**
     * Avisos de configuracion pendiente de los dolares, para la pestana.
     *
     * @return array
     */
    public function getAvisos() {
        if (!$this->tablaCreada()) {
            return ['Todavía no existe la tabla de dólares en cuenta comitente. '
                . 'Corré sql/cashflow_dolares_comitente.sql contra la base central. '
                . 'Mientras tanto, la fila del tablero se muestra en cero.'];
        }

        if (empty($this->getDolaresComitente())) {
            return ['Todavía no hay ninguna carga. La fila del tablero muestra cero, '
                . 'que es lo correcto: no es que falte el dato, es que no hay dólares '
                . 'informados.'];
        }

        return [];
    }

    /**
     * Avisos de configuracion pendiente del saldo de inversiones.
     *
     * Mismo criterio que getAvisos(): la pantalla tiene que distinguir "falta
     * correr el script" de "todavia nadie cargo nada". Los dos dejan la grilla
     * vacia y no significan lo mismo.
     *
     * @return array
     */
    public function getAvisosInversiones() {
        if (!$this->tablaInversionesCreada()) {
            return ['Todavía no existe la tabla del saldo de inversiones. '
                . 'Corré sql/cashflow_saldo_inversiones.sql contra la base central. '
                . 'Mientras tanto, la fila del tablero se muestra en cero.'];
        }

        if (empty($this->getSaldoInversiones())) {
            return ['Todavía no hay ninguna carga. La fila del tablero muestra cero, '
                . 'que es lo correcto: no es que falte el dato, es que no hay saldo '
                . 'informado.'];
        }

        return [];
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Los importes VIGENTES por fecha, en dolares.
     *
     * Es lo que consumen la grilla y el proveedor. Las cargas pisadas no salen
     * de aca: para verlas esta getHistorialFecha().
     *
     * @return array Filas ['FECHA', 'IMPORTE_USD', 'USUARIO', 'FECHA_ALTA', 'VERSIONES']
     */
    public function getDolaresComitente() {
        return $this->leerVigentes(self::DOLARES);
    }

    /**
     * Las cargas en dolares, con la cuenta de su valuacion a pesos ABIERTA:
     * cuantos dolares, a que cotizacion, de que fecha, y cuanto da en pesos.
     *
     * VIVE ACA Y NO EN EL PROVEEDOR porque lo usan los dos: el tablero, para
     * armar la serie, y la pestana, para mostrar la cuenta fila por fila. Si
     * cada uno hiciera su propia multiplicacion, el total del tablero y el de la
     * grilla podrian discrepar y no habria forma de saber cual de los dos esta
     * mal. Con una sola cuenta, el total del tablero se ata fila por fila a lo
     * que se ve en la pantalla.
     *
     * LA COTIZACION ES LA ULTIMA CONOCIDA A LA FECHA DE LA CARGA, no el cierre
     * del mes. El motivo esta en el encabezado de
     * Providers/OtrosIngresosProvider.php.
     *
     * Y ES LA PUNTA VENDEDORA. Es la unica pantalla del cashflow que no valua
     * con comprador: estos dolares estan en una cuenta y se miden contra lo que
     * costaria reponerlos, que es lo que el banco COBRA por un dolar. Ventas,
     * Saldos, Exportaciones Tasky y Comex siguen con comprador y no se movieron.
     *
     * La consecuencia es que esta pantalla NO CIERRA contra las otras, y eso es
     * deliberado. Por eso cada fila viaja con 'TC_PUNTA' y la grilla la muestra:
     * un importe a vendedor que no diga que es a vendedor se compara contra el
     * BCRA comprador y parece estar mal.
     *
     * SIN COTIZACION, TC Y IMPORTE_ARS QUEDAN EN null Y NO EN CERO. Un cero se
     * leeria como "esos dolares valen cero pesos"; null es "no hay con que
     * valuarlos", y el front dibuja un guion. Esos dolares se informan aparte en
     * 'sin_cotizacion' para que nadie tenga que sumarlos a mano.
     *
     * SI NO SE PUEDE LEER EL TIPO DE CAMBIO no lanza: deja el motivo en 'error'
     * y devuelve todas las filas sin valuar. Una pestana que ya funcionaba no se
     * cae porque falte una vista; ese es el criterio del modulo.
     *
     * @param array|null $cargas Filas de getDolaresComitente(); null las lee
     * @param Cotizacion|null $cotizacion Se puede inyectar para poder probar
     * @return array ['filas', 'sin_cotizacion' => float, 'error' => string|null].
     *               Cada fila suma 'TC', 'TC_FECHA', 'TC_PUNTA' e 'IMPORTE_ARS'
     */
    public function valuarDolares($cargas = null, $cotizacion = null) {
        $cargas = ($cargas === null) ? $this->getDolaresComitente() : $cargas;

        $salida = ['filas' => [], 'sin_cotizacion' => 0.0, 'error' => null];

        if (empty($cargas)) {
            return $salida;
        }

        $c = ($cotizacion === null) ? new Cotizacion() : $cotizacion;

        foreach ($cargas as $carga) {
            $usd = floatval($carga['IMPORTE_USD']);
            $tc = null;
            $tcFecha = null;
            $tcPunta = null;

            if ($salida['error'] === null) {
                try {
                    $ult = $c->ultimaHasta($carga['FECHA'], self::PUNTA);

                    if ($ult !== null) {
                        $tc = $ult['valor'];
                        $tcFecha = $ult['fecha'];
                        $tcPunta = Cotizacion::nombrePunta($ult['punta']);
                    }
                } catch (Throwable $e) {
                    // El origen no esta disponible. Se anota una vez y las
                    // filas siguientes ya no lo vuelven a intentar.
                    $salida['error'] = $e->getMessage();
                }
            }

            if ($tc === null && $usd != 0) {
                $salida['sin_cotizacion'] += $usd;
            }

            $salida['filas'][] = array_merge($carga, [
                'TC' => $tc,
                'TC_FECHA' => $tcFecha,
                'TC_PUNTA' => $tcPunta,
                'IMPORTE_ARS' => ($tc === null) ? null : round($usd * $tc, 2)
            ]);
        }

        return $salida;
    }

    /**
     * Los saldos de inversiones VIGENTES por fecha, EN PESOS.
     *
     * En pesos y sin conversion, a diferencia de los dolares: ese saldo se
     * informa en pesos, asi que no hay nada que valuar. La decision y como
     * darla vuelta estan arriba de sql/cashflow_saldo_inversiones.sql.
     *
     * @return array Filas ['FECHA', 'IMPORTE_ARS', 'USUARIO', 'FECHA_ALTA', 'VERSIONES']
     */
    public function getSaldoInversiones() {
        return $this->leerVigentes(self::INVERSIONES);
    }

    /**
     * Todas las cargas de una fecha, la vigente y las pisadas, de la mas nueva
     * a la mas vieja.
     *
     * Es lo que explica por que el numero de ayer era otro.
     *
     * @param string $fecha 'Y-m-d'
     * @return array
     */
    public function getHistorialFecha($fecha) {
        return $this->leerHistorial(self::DOLARES, $fecha);
    }

    /**
     * El historial de una fecha del saldo de inversiones.
     *
     * @param string $fecha 'Y-m-d'
     * @return array
     */
    public function getHistorialInversiones($fecha) {
        return $this->leerHistorial(self::INVERSIONES, $fecha);
    }

    /* ====================================================================
       CARGA
       ==================================================================== */

    /**
     * Carga un importe en dolares para una fecha.
     *
     * @param string $fecha 'Y-m-d'
     * @param float $importeUsd
     * @param string|null $usuario
     * @return array ['fecha', 'importe_usd', 'piso' => bool]
     */
    public function guardarDolaresComitente($fecha, $importeUsd, $usuario = null) {
        $r = $this->guardarCarga(self::DOLARES, $fecha, $importeUsd, $usuario, 'dólares',
            'No existe la tabla de dólares en cuenta comitente. '
            . 'Corré sql/cashflow_dolares_comitente.sql.');

        return ['fecha' => $r['fecha'], 'importe_usd' => $r['importe'], 'piso' => $r['piso']];
    }

    /**
     * Carga un saldo de inversiones EN PESOS para una fecha.
     *
     * @param string $fecha 'Y-m-d'
     * @param float $importeArs
     * @param string|null $usuario
     * @return array ['fecha', 'importe_ars', 'piso' => bool]
     */
    public function guardarSaldoInversiones($fecha, $importeArs, $usuario = null) {
        $r = $this->guardarCarga(self::INVERSIONES, $fecha, $importeArs, $usuario, 'pesos',
            'No existe la tabla del saldo de inversiones. '
            . 'Corré sql/cashflow_saldo_inversiones.sql.');

        return ['fecha' => $r['fecha'], 'importe_ars' => $r['importe'], 'piso' => $r['piso']];
    }

    /* ====================================================================
       LA PLOMERIA, UNA SOLA VEZ

       Los dos conceptos tienen el mismo modelo de datos y las mismas reglas
       -un importe vigente por fecha, sin baja fisica, historial completo-, asi
       que comparten las consultas. Lo unico que cambia es la tabla y el nombre
       de la columna del importe, y las dos vienen del concepto.

       Duplicar estos tres metodos para el concepto nuevo habria dejado dos
       transacciones que se pueden desincronizar: la del alta es la parte
       delicada y tiene que estar escrita una sola vez.
       ==================================================================== */

    /**
     * Si la tabla del concepto existe. Se pregunta una vez por tabla: la
     * pestana la consulta para los avisos y para la grilla.
     *
     * @param array $concepto DOLARES o INVERSIONES
     * @return bool
     */
    private function existe($concepto) {
        $tabla = $concepto['tabla'];

        if (isset($this->tablas[$tabla])) {
            return $this->tablas[$tabla];
        }

        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . $tabla . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla ' . $tabla));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas[$tabla] = ($row && $row['T'] !== null);

        return $this->tablas[$tabla];
    }

    /**
     * Los importes vigentes por fecha de un concepto.
     *
     * @param array $concepto
     * @return array
     */
    private function leerVigentes($concepto) {
        if (!$this->existe($concepto)) {
            return [];
        }

        $tabla = $concepto['tabla'];
        $campo = $concepto['campo'];
        $cid = $this->conectar();

        /* VERSIONES cuenta TODAS las cargas de esa fecha, vigentes y pisadas.
           Es lo que le dice a la pantalla que hay historial para abrir: sin
           ese numero, el enlace al historial estaria siempre y la mitad de las
           veces no mostraria nada. */
        $sql = "SELECT d.FECHA, d." . $campo . ", d.USUARIO, d.FECHA_ALTA,
                       (SELECT COUNT(*)
                          FROM dbo." . $tabla . " h
                         WHERE h.FECHA = d.FECHA) AS VERSIONES
                FROM dbo." . $tabla . " d
                WHERE d.VIGENTE = 1
                ORDER BY d.FECHA DESC";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer ' . $tabla));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                $campo => floatval($row[$campo]),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'VERSIONES' => intval($row['VERSIONES'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Todas las cargas de una fecha de un concepto, de la mas nueva a la mas
     * vieja.
     *
     * @param array $concepto
     * @param string $fecha
     * @return array
     */
    private function leerHistorial($concepto, $fecha) {
        if (!$this->existe($concepto)) {
            return [];
        }

        $campo = $concepto['campo'];
        $f = self::validarFecha($fecha);
        $cid = $this->conectar();

        $sql = "SELECT ID, FECHA, " . $campo . ", VIGENTE, USUARIO, FECHA_ALTA
                FROM dbo." . $concepto['tabla'] . "
                WHERE FECHA = ?
                ORDER BY ID DESC";

        $stmt = sqlsrv_query($cid, $sql, [$f]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                $campo => floatval($row[$campo]),
                'VIGENTE' => intval($row['VIGENTE']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Carga un importe para una fecha.
     *
     * NO HACE UPDATE. Marca VIGENTE = 0 las cargas anteriores de esa fecha e
     * inserta una fila nueva, LAS DOS COSAS EN UNA TRANSACCION: si la baja
     * confirmara y el alta fallara, la fecha se quedaria sin importe vigente y
     * la fila del tablero perderia esa plata en silencio.
     *
     * @param array $concepto
     * @param string $fecha 'Y-m-d'
     * @param mixed $importe
     * @param string|null $usuario
     * @param string $moneda Como se nombra la moneda en los mensajes de error
     * @param string $faltaTabla Mensaje si no se corrio el script
     * @return array ['fecha', 'importe', 'piso' => bool]
     */
    private function guardarCarga($concepto, $fecha, $importe, $usuario, $moneda, $faltaTabla) {
        if (!$this->existe($concepto)) {
            throw new Exception($faltaTabla);
        }

        $tabla = $concepto['tabla'];
        $campo = $concepto['campo'];

        $f = self::validarFecha($fecha);
        $monto = self::validarImporte($importe, $moneda);

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $stmt = sqlsrv_query($cid,
                "UPDATE dbo." . $tabla . "
                 SET VIGENTE = 0
                 WHERE FECHA = ? AND VIGENTE = 1",
                [$f]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de baja la carga anterior'));
            }

            $piso = (sqlsrv_rows_affected($stmt) > 0);
            sqlsrv_free_stmt($stmt);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . $tabla . "
                     (FECHA, " . $campo . ", VIGENTE, USUARIO)
                 VALUES (?, ?, 1, ?)",
                [$f, $monto, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el importe'));
            }

            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['fecha' => $f, 'importe' => $monto, 'piso' => $piso];
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * Normaliza y valida una fecha de carga.
     *
     * A diferencia de la fecha de cobro manual de Cobranzas FR, ACA SI SE
     * ACEPTAN FECHAS PASADAS: la carga describe cuantos dolares habia en la
     * cuenta un dia determinado, y ese dia puede haber sido la semana pasada.
     * Lo que hace el eje del tablero con una fecha vieja es asunto del eje.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFecha($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha no es válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Valida el importe de una carga.
     *
     * CERO ES VALIDO: significa que ese dia no habia nada en la cuenta, y es un
     * dato distinto de no haber cargado nada. Un negativo no: ninguno de los dos
     * conceptos tiene saldo deudor en este circuito, y un signo invertido
     * restaria del tablero sin que nadie lo pida.
     *
     * La moneda entra solo en el MENSAJE. La regla es la misma para los dos
     * conceptos, y un validador por moneda serian dos copias de la misma
     * cuenta que se pueden desincronizar.
     *
     * @param mixed $importe
     * @param string $moneda Como nombrarla en el mensaje de error
     * @return float
     */
    public static function validarImporte($importe, $moneda = 'dólares') {
        if ($importe === null || $importe === '' || !is_numeric($importe)) {
            throw new Exception('El importe en ' . $moneda . ' tiene que ser un número.');
        }

        $v = round(floatval($importe), 2);

        if ($v < 0) {
            throw new Exception('El importe en ' . $moneda . ' no puede ser negativo: '
                . 'restaría del tablero en vez de sumar.');
        }

        return $v;
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

    /** 'Y-m-d H:i' de lo que devuelve sqlsrv para un DATETIME */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return $v === null ? null : (string) $v;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors()
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto;

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= ': ' . $e['message'];
            }
        }

        return $msg;
    }
}
