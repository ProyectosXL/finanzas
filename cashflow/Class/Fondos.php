<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * Fondos
 * Las cuentas de inversion y comitente del catalogo de Saldos, con su cuenta
 * corriente: saldo inicial + suscripciones - rescates.
 *
 * QUE RESUELVE
 * ------------
 * Hasta ahora el stock de la seccion Cobertura salia de dos FOTOS del saldo
 * cargadas en Otros Ingresos (una en pesos, otra en dolares). Una foto dice
 * cuanto habia el dia que alguien la tomo, y nada mas: no explica de donde
 * salio el numero ni permite proyectar un rescate. Ahora cada fondo es una
 * cuenta de RO_T_CASHFLOW_SALDOS_CUENTA con CLASE 'INVERSION' o 'COMITENTE',
 * y su saldo se CALCULA:
 *
 *     saldo a una fecha = saldo inicial + suscripciones - rescates
 *                         (movimientos vigentes con FECHA <= esa fecha)
 *
 * SE DAN DE ALTA VARIAS CUENTAS DE CADA CLASE y ninguna esta escrita en el
 * codigo: no existe "la cuenta de inversiones". Lo que hay es un catalogo, y
 * esta clase lo lee entero.
 *
 * LAS DOS PREGUNTAS DE UNA CUENTA: TIPO Y CLASE
 * ---------------------------------------------
 * TIPO ya existia y dice DE DONDE SALE el saldo (BANCO, MERCADO_PAGO,
 * EFECTIVO_CENTRAL, OTRO). CLASE dice QUE ES la cuenta:
 *
 *   CTA_CORRIENTE, CAJA_AHORRO  -> plata a la vista. Es DISPONIBILIDAD: se
 *                                  carga como foto y entra al Saldo Inicial.
 *   INVERSION, COMITENTE        -> un FONDO. Lleva cuenta corriente y es STOCK
 *                                  DE COBERTURA.
 *
 * Son dos ejes independientes y por eso son dos columnas; el argumento
 * completo esta en el encabezado de sql/cashflow_saldos_cuentas_fondo.sql.
 *
 * LOS FONDOS NO ENTRAN EN DISPONIBILIDADES
 * ----------------------------------------
 * Su unico rol en el tablero es ser stock de la seccion Cobertura. Si entraran
 * tambien al Saldo Inicial, la misma plata se contaria dos veces: una como
 * disponible y otra como cobertura. Saldos::getSaldosActuales() los deja
 * afuera, y esFondo() es la unica regla que decide cual es cual.
 *
 * EL SALDO INICIAL ES AL CIERRE DE SU FECHA
 * -----------------------------------------
 * Un movimiento con FECHA <= FECHA_SALDO_INICIAL ya esta incluido en el saldo
 * inicial, asi que saldoA() no lo suma. Sin esa regla, fijar un saldo inicial
 * nuevo despues de haber cargado movimientos los contaria dos veces. Por lo
 * mismo, guardarMovimiento() rechaza un movimiento anterior o igual a esa
 * fecha: cargarlo no cambiaria el saldo y nada lo diria.
 *
 * EL STOCK DEL TABLERO ES EL SALDO A HOY
 * --------------------------------------
 * Un movimiento con fecha futura -un rescate que se va a hacer la semana que
 * viene- se muestra en la pestana pero no entra al stock: hoy la plata todavia
 * esta en el fondo. El proveedor lo avisa. Como se usa ese stock para cubrir
 * el flujo es asunto de la seccion Cobertura y no cambio en esta etapa.
 *
 * NO SE REGISTRA CONTRAPARTIDA BANCARIA
 * -------------------------------------
 * Un rescate saca plata del fondo y nada mas. Lo que entra al banco se va a ver
 * en el saldo bancario, que en breve lo trae la API de Interbanking. Registrar
 * la contrapartida aca seria adelantar un dato que otro circuito ya va a medir,
 * y las dos cifras podrian discrepar.
 *
 * EDITAR UN MOVIMIENTO NO ES UN UPDATE
 * ------------------------------------
 * Mismo circuito que RO_T_CASHFLOW_COBERTURA_APLIC: se marca VIGENTE = 0 el
 * anterior y se inserta uno nuevo que apunta al que reemplaza, en UNA
 * transaccion. Dar de baja marca VIGENTE = 0 y no inserta nada. El historial
 * es lo unico que explica por que el saldo del fondo de ayer era otro.
 *
 * CADA CUENTA DE FONDO ES UN FONDO DE COBERTURA
 * ---------------------------------------------
 * Una aplicacion de cobertura dice de que fondo sale. Antes eso era una lista
 * fija (Cobertura::ORIGENES); ahora la lista son estas cuentas, y la clave de
 * cada una es 'CTA_' + ID (claveFondo()). Es la clave que guarda
 * RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN y la que el motor usa para descontar
 * lo aplicado del stock de cada cuenta. Ver Cashflow::resolverCobertura().
 *
 * SI EL SCRIPT NO SE CORRIO, NO SE ROMPE
 * --------------------------------------
 * creado() pregunta por la columna CLASE y por la tabla de movimientos. Sin
 * ellas, las lecturas devuelven vacio y getAvisos() dice que script correr;
 * la pestana Saldos sigue funcionando con sus dos sub-pestanas de siempre.
 */
class Fondos {

    /** Tabla de movimientos, en la base central */
    const TABLA_MOV = 'RO_T_CASHFLOW_SALDOS_FONDO_MOV';

    /** Tabla del catalogo de cuentas, que es de Saldos. Aca solo se lee. */
    const TABLA_CUENTA = 'RO_T_CASHFLOW_SALDOS_CUENTA';

    /**
     * Las clases de cuenta y su rotulo.
     *
     * El orden importa: es el de los desplegables. Primero las dos a la vista,
     * despues los dos fondos.
     */
    const CLASES = [
        'CTA_CORRIENTE' => 'Cuenta corriente',
        'CAJA_AHORRO' => 'Caja de ahorro',
        'INVERSION' => 'Inversión',
        'COMITENTE' => 'Cuenta comitente'
    ];

    /** Las clases que son un fondo: llevan cuenta corriente y son cobertura */
    const CLASES_FONDO = ['INVERSION', 'COMITENTE'];

    /** La clase con la que nacen las cuentas que no dicen otra cosa */
    const CLASE_DEFECTO = 'CTA_CORRIENTE';

    /** Los dos tipos de movimiento y su signo sobre el saldo */
    const TIPOS_MOV = [
        'SUSCRIPCION' => 1,
        'RESCATE' => -1
    ];

    /** Prefijo de la clave de fondo de una cuenta, en ORIGEN de las aplicaciones */
    const PREFIJO_FONDO = 'CTA_';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo del DDL */
    private $creado = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       HELPERS PUROS
       Estaticos y sin base: son las reglas, y son lo que se prueba.
       ==================================================================== */

    /**
     * Si una clase de cuenta es un fondo (cuenta corriente + cobertura) o
     * plata a la vista (foto + disponibilidad).
     *
     * ES LA UNICA REGLA que decide de que lado del tablero va una cuenta. Si
     * estuviera repetida en Saldos y en el proveedor, un dia una diria una cosa
     * y la otra otra, y la misma cuenta sumaria en los dos lados.
     *
     * @param mixed $clase
     * @return bool
     */
    public static function esFondo($clase) {
        return in_array(strtoupper(trim((string) $clase)), self::CLASES_FONDO, true);
    }

    /**
     * Normaliza y valida una clase contra la lista.
     *
     * @param mixed $clase null o vacio toma la clase por defecto
     * @return string
     */
    public static function validarClase($clase) {
        if ($clase === null || trim((string) $clase) === '') {
            return self::CLASE_DEFECTO;
        }

        $c = strtoupper(trim((string) $clase));

        if (!isset(self::CLASES[$c])) {
            throw new Exception('La clase de cuenta "' . $clase . '" no existe. Las válidas son: '
                . implode(', ', array_keys(self::CLASES)) . '.');
        }

        return $c;
    }

    /**
     * Si se puede pasar una cuenta de una clase a otra.
     *
     * SOLO DENTRO DEL MISMO GRUPO. Entre cuenta corriente y caja de ahorro no
     * cambia nada de lo que la cuenta ya tiene cargado. Entre inversion y
     * comitente tampoco: las dos llevan cuenta corriente. Lo que NO se puede es
     * cruzar de un grupo al otro: una cuenta a la vista tiene fotos del saldo y
     * un fondo tiene movimientos, y el historico de una no significa nada leido
     * como el de la otra. Si quedo mal, se inhabilita y se crea otra, igual que
     * con el TIPO.
     *
     * @param string $actual
     * @param string $nueva
     * @return bool
     */
    public static function cambioDeClasePermitido($actual, $nueva) {
        return self::esFondo($actual) === self::esFondo($nueva);
    }

    /**
     * Normaliza y valida el tipo de un movimiento.
     *
     * @param mixed $tipo
     * @return string 'SUSCRIPCION' | 'RESCATE'
     */
    public static function validarTipoMovimiento($tipo) {
        $t = strtoupper(trim((string) $tipo));

        if (!isset(self::TIPOS_MOV[$t])) {
            throw new Exception('El tipo de movimiento tiene que ser SUSCRIPCION o RESCATE.');
        }

        return $t;
    }

    /**
     * Normaliza y valida el importe de un movimiento.
     *
     * SIEMPRE POSITIVO: el signo lo pone el tipo. Un "rescate de -1000" no
     * significa nada, y aceptarlo dejaria dos formas de decir lo mismo que un
     * dia se contradicen. El cero tampoco es un movimiento.
     *
     * @param mixed $importe
     * @return float
     */
    public static function validarImporte($importe) {
        if ($importe === null || $importe === '' || !is_numeric($importe)) {
            throw new Exception('El importe del movimiento tiene que ser un número.');
        }

        $v = round(floatval($importe), 2);

        if ($v <= 0) {
            throw new Exception('El importe del movimiento tiene que ser mayor que cero: el signo '
                . 'lo pone el tipo (suscripción suma, rescate resta).');
        }

        return $v;
    }

    /**
     * Normaliza y valida una fecha.
     *
     * SE ACEPTAN FECHAS FUTURAS: un rescate previsto para la semana que viene
     * es un dato real. No entra al stock de hoy, y el proveedor lo avisa.
     *
     * @param mixed $fecha
     * @param string $que Para el mensaje
     * @return string 'Y-m-d'
     */
    public static function validarFecha($fecha, $que = 'del movimiento') {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha ' . $que . ' no es válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha ' . $que . ' no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Normaliza y valida el saldo inicial de un fondo, con su fecha.
     *
     * Van los dos o ninguno: un saldo sin fecha no se puede ubicar en la cuenta
     * corriente y una fecha sin saldo no dice nada. El cero es valido -un fondo
     * recien abierto arranca vacio-, el negativo no.
     *
     * @param mixed $saldo
     * @param mixed $fecha
     * @return array|null ['saldo' => float, 'fecha' => 'Y-m-d'], o null si no vino nada
     */
    public static function validarSaldoInicial($saldo, $fecha) {
        $sinSaldo = ($saldo === null || trim((string) $saldo) === '');
        $sinFecha = ($fecha === null || trim((string) $fecha) === '');

        if ($sinSaldo && $sinFecha) {
            return null;
        }

        if ($sinSaldo || $sinFecha) {
            throw new Exception('El saldo inicial va con su fecha: los dos o ninguno.');
        }

        if (!is_numeric($saldo)) {
            throw new Exception('El saldo inicial tiene que ser un número.');
        }

        $v = round(floatval($saldo), 2);

        if ($v < 0) {
            throw new Exception('El saldo inicial no puede ser negativo.');
        }

        return ['saldo' => $v, 'fecha' => self::validarFecha($fecha, 'del saldo inicial')];
    }

    /** Recorta la observacion al largo de la columna @return string|null */
    public static function normalizarObservacion($observacion) {
        $o = trim((string) $observacion);

        return ($o === '') ? null : mb_substr($o, 0, 200);
    }

    /**
     * La clave con la que una cuenta figura como fondo de cobertura.
     *
     * Es lo que se guarda en RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN y lo que el
     * motor usa para descontar. Lleva prefijo y no el numero pelado para que
     * leida en una consulta a mano se entienda que es una cuenta, y para que no
     * se confunda con las claves viejas ('INVERSIONES', 'DOLARES') que la
     * migracion reescribe.
     *
     * @param int $idCuenta
     * @return string
     */
    public static function claveFondo($idCuenta) {
        return self::PREFIJO_FONDO . intval($idCuenta);
    }

    /**
     * El ID de cuenta de una clave de fondo, o null si la clave no es de una
     * cuenta (una clave vieja que la migracion no pudo mover, por ejemplo).
     *
     * @param mixed $clave
     * @return int|null
     */
    public static function idDeClave($clave) {
        $c = strtoupper(trim((string) $clave));
        $p = self::PREFIJO_FONDO;

        if (strpos($c, $p) !== 0 || !ctype_digit(substr($c, strlen($p)))) {
            return null;
        }

        return intval(substr($c, strlen($p)));
    }

    /**
     * El saldo de un fondo a una fecha, y lo que quedo afuera.
     *
     * ES LA REGLA DE LA CUENTA CORRIENTE, y esta aca sola para poder probarla
     * sin base: la clase lee, el helper decide.
     *
     *   saldo = saldo inicial
     *         + los movimientos vigentes con FECHA > fecha del saldo inicial
     *                                      y FECHA <= la fecha pedida,
     *           con el signo de su tipo
     *
     * Un movimiento anterior o igual a la fecha del saldo inicial ya esta
     * incluido en ese saldo: se cuenta aparte ('incluidos') y no se suma. Uno
     * posterior a la fecha pedida todavia no paso: se cuenta en 'posteriores'.
     * Los no vigentes no existen para esta cuenta.
     *
     * Sin saldo inicial se arranca de cero. No es un invento: la pantalla lo
     * muestra como "sin saldo inicial" y el proveedor lo avisa.
     *
     * @param array $cuenta Con 'SALDO_INICIAL' y 'FECHA_SALDO_INICIAL' (pueden ser null)
     * @param array $movimientos Filas con 'FECHA', 'TIPO', 'IMPORTE', 'VIGENTE'
     * @param string $fecha 'Y-m-d' hasta la que se cuenta (inclusive)
     * @return array ['saldo' => float, 'suscripciones' => float, 'rescates' => float,
     *                'movimientos' => int, 'incluidos' => int, 'posteriores' => int]
     */
    public static function saldoA($cuenta, $movimientos, $fecha) {
        $inicial = isset($cuenta['SALDO_INICIAL']) && $cuenta['SALDO_INICIAL'] !== null
            ? floatval($cuenta['SALDO_INICIAL']) : 0.0;
        $desde = isset($cuenta['FECHA_SALDO_INICIAL'])
            ? Horizonte::normalizarFecha($cuenta['FECHA_SALDO_INICIAL']) : null;
        $hasta = Horizonte::normalizarFecha($fecha);

        $r = ['saldo' => $inicial, 'suscripciones' => 0.0, 'rescates' => 0.0,
              'movimientos' => 0, 'incluidos' => 0, 'posteriores' => 0];

        if (!is_array($movimientos)) {
            return $r;
        }

        foreach ($movimientos as $m) {
            if (isset($m['VIGENTE']) && intval($m['VIGENTE']) !== 1) {
                continue;
            }

            $f = Horizonte::normalizarFecha(isset($m['FECHA']) ? $m['FECHA'] : null);
            $tipo = strtoupper((string) (isset($m['TIPO']) ? $m['TIPO'] : ''));

            if ($f === null || !isset(self::TIPOS_MOV[$tipo])) {
                continue;
            }

            if ($desde !== null && $f <= $desde) {
                $r['incluidos']++;
                continue;
            }

            if ($hasta !== null && $f > $hasta) {
                $r['posteriores']++;
                continue;
            }

            $importe = floatval(isset($m['IMPORTE']) ? $m['IMPORTE'] : 0);

            $r['movimientos']++;
            $r['saldo'] += self::TIPOS_MOV[$tipo] * $importe;

            if ($tipo === 'SUSCRIPCION') {
                $r['suscripciones'] += $importe;
            } else {
                $r['rescates'] += $importe;
            }
        }

        return $r;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si ya se corrio sql/cashflow_saldos_cuentas_fondo.sql: la columna CLASE
     * en el catalogo y la tabla de movimientos.
     *
     * @return bool
     */
    public function creado() {
        if ($this->creado !== null) {
            return $this->creado;
        }

        $cid = $this->conectar();

        $sql = "SELECT COL_LENGTH('dbo." . self::TABLA_CUENTA . "', 'CLASE') AS C,
                       COL_LENGTH('dbo." . self::TABLA_CUENTA . "', 'SALDO_INICIAL') AS S,
                       OBJECT_ID('dbo." . self::TABLA_MOV . "', 'U') AS M";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de fondos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->creado = ($row && $row['C'] !== null && $row['S'] !== null && $row['M'] !== null);

        return $this->creado;
    }

    /** @return array Avisos de configuracion pendiente */
    public function getAvisos() {
        if (!$this->creado()) {
            return ['Todavía no existen las cuentas de inversión y comitente: corré '
                . 'sql/cashflow_saldos_cuentas_fondo.sql contra la base central. Mientras tanto '
                . 'no se pueden cargar movimientos y el stock de cobertura del tablero va en cero.'];
        }

        return [];
    }

    /* ====================================================================
       LECTURAS
       ==================================================================== */

    /**
     * Las cuentas de fondo del catalogo, cada una con su saldo A HOY y el
     * resumen de su cuenta corriente.
     *
     * Trae TODAS las clases de fondo juntas y deja que el llamador filtre: el
     * proveedor sirve una clase por codigo del registro, y la pestana las
     * muestra todas. Una consulta por clase seria la misma consulta dos veces.
     *
     * @param bool $soloActivas
     * @param string|null $hoy 'Y-m-d' a la que se calcula el saldo; null = hoy
     * @return array Filas con los campos de la cuenta mas 'saldo', 'suscripciones',
     *         'rescates', 'movimientos', 'posteriores', 'incluidos', 'clave_fondo',
     *         'ultimo_movimiento'
     */
    public function getCuentasFondo($soloActivas = true, $hoy = null) {
        if (!$this->creado()) {
            return [];
        }

        $hoy = ($hoy === null) ? date('Y-m-d') : Horizonte::normalizarFecha($hoy);
        $cid = $this->conectar();

        $sql = "SELECT ID, TIPO, CLASE, NOMBRE, MONEDA, ORIGEN_DATO, SALDO_INICIAL,
                       FECHA_SALDO_INICIAL, ORDEN, ACTIVO, FECHA_UPDATE, USUARIO
                FROM dbo." . self::TABLA_CUENTA . "
                WHERE CLASE IN (" . $this->inSql(self::CLASES_FONDO) . ")"
                . ($soloActivas ? " AND ACTIVO = 1" : "") . "
                ORDER BY ORDEN, NOMBRE";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cuentas de fondo'));
        }

        $cuentas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cuentas[intval($row['ID'])] = $this->filaCuenta($row);
        }

        sqlsrv_free_stmt($stmt);

        if (empty($cuentas)) {
            return [];
        }

        // Los movimientos vigentes de todas, en una sola pasada. El saldo lo
        // decide saldoA(); aca solo se reparte por cuenta.
        $porCuenta = [];

        foreach ($this->leerMovimientos(array_keys($cuentas), true) as $m) {
            $porCuenta[$m['ID_CUENTA']][] = $m;
        }

        $v = [];

        foreach ($cuentas as $id => $c) {
            $movs = isset($porCuenta[$id]) ? $porCuenta[$id] : [];
            $calc = self::saldoA($c, $movs, $hoy);

            $ultimo = null;

            foreach ($movs as $m) {
                if ($ultimo === null || $m['FECHA'] > $ultimo) {
                    $ultimo = $m['FECHA'];
                }
            }

            $v[] = array_merge($c, $calc, [
                'clave_fondo' => self::claveFondo($id),
                'fecha_saldo' => $hoy,
                'ultimo_movimiento' => $ultimo
            ]);
        }

        return $v;
    }

    /**
     * Los movimientos de una cuenta, TODOS: vigentes, pisados y dados de baja,
     * del mas nuevo al mas viejo.
     *
     * Es el historial de la cuenta corriente. Los no vigentes se devuelven con
     * su marca para que la pantalla los muestre tachados y no desaparezcan: un
     * rescate que se cargo mal y se corrigio es exactamente lo que explica por
     * que el saldo de la semana pasada era otro.
     *
     * @param int $idCuenta
     * @return array
     */
    public function getMovimientos($idCuenta) {
        if (!$this->creado()) {
            return [];
        }

        return $this->leerMovimientos([intval($idCuenta)], false);
    }

    /**
     * Las cuentas de fondo como origenes de cobertura: clave => datos.
     *
     * Es lo que reemplaza a la lista fija Cobertura::ORIGENES. Incluye las
     * cuentas INHABILITADAS a proposito: una aplicacion vieja puede apuntar a
     * una cuenta que despues se dio de baja, y su historial tiene que poder
     * nombrarla. Quien arma un desplegable filtra por 'activo'.
     *
     * @return array Mapa clave => ['id', 'nombre', 'moneda', 'clase', 'activo']
     */
    public function origenesCobertura() {
        $v = [];

        foreach ($this->getCuentasFondo(false) as $c) {
            $v[$c['clave_fondo']] = [
                'id' => $c['ID'],
                'nombre' => $c['NOMBRE'],
                'moneda' => $c['MONEDA'],
                'clase' => $c['CLASE'],
                'activo' => (intval($c['ACTIVO']) === 1)
            ];
        }

        return $v;
    }

    /* ====================================================================
       ESCRITURAS
       ==================================================================== */

    /**
     * Carga un movimiento, o reemplaza uno existente.
     *
     * NO HACE UPDATE. Con $idReemplaza, marca VIGENTE = 0 el anterior e
     * inserta el nuevo apuntando a el, LAS DOS COSAS EN UNA TRANSACCION: si la
     * baja confirmara y el alta fallara, el movimiento desapareceria de la
     * cuenta corriente sin que nadie lo hubiera pedido.
     *
     * LA MONEDA SALE DE LA CUENTA y se copia en la fila. No viaja como
     * parametro: un movimiento se carga en la moneda de su cuenta, y recibirla
     * suelta permitiria guardar dolares en un fondo en pesos.
     *
     * UN MOVIMIENTO ANTERIOR O IGUAL A LA FECHA DEL SALDO INICIAL SE RECHAZA:
     * ya esta incluido en ese saldo, asi que cargarlo no cambiaria nada y nada
     * lo diria. Si el saldo inicial esta mal, se corrige el saldo inicial.
     *
     * @param int $idCuenta
     * @param mixed $fecha 'Y-m-d'
     * @param mixed $tipo SUSCRIPCION | RESCATE
     * @param mixed $importe Positivo
     * @param string|null $observacion
     * @param string|null $usuario
     * @param int|null $idReemplaza El movimiento que esta version pisa
     * @return array ['id', 'fecha', 'tipo', 'importe', 'moneda', 'reemplazo' => bool]
     */
    public function guardarMovimiento($idCuenta, $fecha, $tipo, $importe, $observacion = null,
                                      $usuario = null, $idReemplaza = null) {
        if (!$this->creado()) {
            throw new Exception('Todavía no existen las cuentas de fondo. Corré '
                . 'sql/cashflow_saldos_cuentas_fondo.sql contra la base central.');
        }

        $cuenta = $this->cuentaFondo($idCuenta);
        $f = self::validarFecha($fecha);
        $t = self::validarTipoMovimiento($tipo);
        $monto = self::validarImporte($importe);
        $obs = self::normalizarObservacion($observacion);

        if ($cuenta['FECHA_SALDO_INICIAL'] !== null && $f <= $cuenta['FECHA_SALDO_INICIAL']) {
            throw new Exception('La cuenta "' . $cuenta['NOMBRE'] . '" tiene saldo inicial al '
                . self::fechaCorta($cuenta['FECHA_SALDO_INICIAL']) . ': un movimiento de esa '
                . 'fecha o anterior ya está incluido en ese saldo. Si el saldo inicial está mal, '
                . 'corregilo desde Parámetros → Saldos.');
        }

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $reemplazo = false;

            if ($idReemplaza !== null && $idReemplaza !== '') {
                $reemplazo = $this->bajaVigente($cid, intval($idReemplaza), intval($cuenta['ID']));

                if (!$reemplazo) {
                    throw new Exception('El movimiento que se quiere corregir ya no está vigente '
                        . 'o no es de esta cuenta. Recargá la pestaña.');
                }
            }

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA_MOV . "
                    (ID_CUENTA, FECHA, TIPO, IMPORTE, MONEDA, OBSERVACION, VIGENTE,
                     ID_REEMPLAZA, USUARIO)
                 OUTPUT INSERTED.ID
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [intval($cuenta['ID']), $f, $t, $monto, $cuenta['MONEDA'], $obs,
                 $reemplazo ? intval($idReemplaza) : null, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el movimiento'));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['id' => intval($row['ID']), 'fecha' => $f, 'tipo' => $t, 'importe' => $monto,
                'moneda' => $cuenta['MONEDA'], 'reemplazo' => $reemplazo];
    }

    /**
     * Da de baja un movimiento.
     *
     * NO BORRA LA FILA: marca VIGENTE = 0 y sella FECHA_BAJA. Queda en el
     * historial de la cuenta, tachado.
     *
     * @param int $idCuenta
     * @param int $idMovimiento
     * @return bool Si estaba vigente
     */
    public function bajaMovimiento($idCuenta, $idMovimiento) {
        if (!$this->creado()) {
            throw new Exception('Todavía no existen las cuentas de fondo.');
        }

        $cuenta = $this->cuentaFondo($idCuenta);

        return $this->bajaVigente($this->conectar(), intval($idMovimiento), intval($cuenta['ID']));
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * Una cuenta de fondo activa, o excepcion. Se lee de la base y no se acepta
     * del cliente: el ID es un pedido, y la moneda y la clase salen de aca.
     *
     * @param int $idCuenta
     * @return array
     */
    private function cuentaFondo($idCuenta) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, TIPO, CLASE, NOMBRE, MONEDA, SALDO_INICIAL, FECHA_SALDO_INICIAL, ACTIVO
             FROM dbo." . self::TABLA_CUENTA . " WHERE ID = ?",
            [intval($idCuenta)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la cuenta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('La cuenta ' . intval($idCuenta) . ' no existe.');
        }

        if (!self::esFondo($row['CLASE'])) {
            throw new Exception('La cuenta "' . $row['NOMBRE'] . '" no es un fondo: es '
                . self::CLASES[$row['CLASE']] . ' y su saldo se carga como foto, no con '
                . 'movimientos.');
        }

        if (intval($row['ACTIVO']) !== 1) {
            throw new Exception('La cuenta "' . $row['NOMBRE'] . '" está inhabilitada.');
        }

        return $this->filaCuenta($row);
    }

    /** Marca VIGENTE = 0 un movimiento de una cuenta. @return bool si lo estaba */
    private function bajaVigente($cid, $idMovimiento, $idCuenta) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_MOV . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE ID = ? AND ID_CUENTA = ? AND VIGENTE = 1",
            [$idMovimiento, $idCuenta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el movimiento'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return ($filas > 0);
    }

    /**
     * Lee movimientos de varias cuentas.
     *
     * @param array $ids IDs de cuenta
     * @param bool $soloVigentes
     * @return array
     */
    private function leerMovimientos($ids, $soloVigentes) {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (empty($ids)) {
            return [];
        }

        $sql = "SELECT ID, ID_CUENTA, FECHA, TIPO, IMPORTE, MONEDA, OBSERVACION, VIGENTE,
                       ID_REEMPLAZA, USUARIO, FECHA_ALTA, FECHA_BAJA
                FROM dbo." . self::TABLA_MOV . "
                WHERE ID_CUENTA IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
                . ($soloVigentes ? " AND VIGENTE = 1" : "") . "
                ORDER BY FECHA DESC, ID DESC";

        $stmt = sqlsrv_query($this->conectar(), $sql, $ids);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los movimientos'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'ID_CUENTA' => intval($row['ID_CUENTA']),
                'FECHA' => Horizonte::normalizarFecha($row['FECHA']),
                'TIPO' => (string) $row['TIPO'],
                'IMPORTE' => floatval($row['IMPORTE']),
                'MONEDA' => (string) $row['MONEDA'],
                'OBSERVACION' => $row['OBSERVACION'],
                'VIGENTE' => intval($row['VIGENTE']),
                'ID_REEMPLAZA' => ($row['ID_REEMPLAZA'] === null) ? null : intval($row['ID_REEMPLAZA']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'FECHA_BAJA' => $this->fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /** Normaliza una fila del catalogo */
    private function filaCuenta($row) {
        $row['ID'] = intval($row['ID']);
        $row['ACTIVO'] = isset($row['ACTIVO']) ? intval($row['ACTIVO']) : 1;
        $row['ORDEN'] = isset($row['ORDEN']) ? intval($row['ORDEN']) : 0;
        $row['SALDO_INICIAL'] = ($row['SALDO_INICIAL'] === null) ? null : floatval($row['SALDO_INICIAL']);
        $row['FECHA_SALDO_INICIAL'] = Horizonte::normalizarFecha($row['FECHA_SALDO_INICIAL']);

        if (isset($row['FECHA_UPDATE'])) {
            $row['FECHA_UPDATE'] = $this->fechaHora($row['FECHA_UPDATE']);
        }

        return $row;
    }

    /** Lista de constantes del codigo para un IN: no es entrada del usuario */
    private function inSql($valores) {
        return "'" . implode("','", $valores) . "'";
    }

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

    /** 'Y-m-d' => 'd/m/Y' */
    private static function fechaCorta($fecha) {
        return date('d/m/Y', strtotime(substr((string) $fecha, 0, 10)));
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::TABLA_MOV . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
