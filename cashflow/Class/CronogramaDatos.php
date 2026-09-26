<?php

require_once __DIR__ . '/CronogramaPagos.php';

/**
 * CronogramaDatos
 * La parte del cronograma de pagos que toca la base: el calendario bancario y
 * los overrides.
 *
 * POR QUE ESTA SEPARADA DE CronogramaPagos
 * -----------------------------------------
 * Mismo criterio que ComprasProyectadas / ComprasProyectadasDatos y que
 * DolarFuturo: la regla -que viernes son, hacia donde se corren, cual gana- es
 * lo delicado y se prueba sin base. Lo que hay aca son dos consultas y dos
 * escrituras, que sin base no se pueden probar y que tampoco tienen nada que
 * decidir.
 *
 * EL CALENDARIO SE LEE DE UN SOLO LUGAR
 * --------------------------------------
 * De Ventas::getDiasHabiles(), que es la unica lectura de RO_T_CALENDARIO del
 * modulo. La tabla vive en la conexion 'power' y esta poblada hasta 2027; una
 * segunda consulta aca seria una segunda definicion de "dia habil" esperando a
 * desincronizarse. Lo que NO se comparte es el corrimiento: Ventas corre al
 * proximo dia habil y un pago se corre al anterior. Ver el encabezado de
 * CronogramaPagos.
 *
 * Y NO ES EL CRONOGRAMA EL UNICO QUE PIDE EL CALENDARIO. El vencimiento del
 * resumen de una tarjeta tambien lo necesita, corriendose hacia ADELANTE
 * (Class/TarjetasVencimiento.php), y pide un rango distinto: dias DESPUES del
 * fin del eje en vez de un mes antes del principio. Por eso habilesEntre() es
 * publica y habiles() es un caso suyo. Un rango por consumidor sobre la misma
 * consulta no es una segunda definicion de nada; una segunda consulta si.
 *
 * SI EL CALENDARIO NO SE PUEDE LEER, SE SIGUE
 * --------------------------------------------
 * La conexion 'power' es otro servidor. Si no responde, el cronograma se
 * resuelve con el fallback de lunes a viernes -el mismo que usa Ventas cuando
 * falta una fecha- y se avisa. Sin eso, una caida de ese servidor dejaria la
 * pestana entera de Parametros y la fila de Logistica del tablero en blanco por
 * no poder decidir si un viernes es feriado.
 *
 * LOS OVERRIDES NO SE BORRAN
 * ---------------------------
 * Volver al valor calculado marca VIGENTE = 0 y deja la fila. Con un DELETE,
 * "esta fecha nunca se toco" y "se toco y se volvio atras" son indistinguibles
 * despues del hecho. Mismo criterio que RO_T_CASHFLOW_COMEX_FECHA_EDIT y que
 * RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE.
 */
class CronogramaDatos {

    /** La tabla de overrides, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT';

    /**
     * Cuantos dias se puede mover un pago a mano respecto de su fecha teorica.
     *
     * NO ES UNA REGLA DE NEGOCIO, es una red contra el dedazo. Un ano mal
     * tipeado -2027 por 2026- es una fecha perfectamente valida que corre el
     * pago doce meses y cae en otra columna del tablero sin que nada avise. Un
     * mes entero de margen alcanza de sobra para cualquier adelanto o atraso
     * que alguien quiera pactar.
     */
    const DIAS_MARGEN = 31;

    /** @var Conexion */
    private $conn;

    /** @var Ventas|null Puerta al calendario bancario; la resuelve ventas() */
    private $ventas = null;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    /** @var array Avisos acumulados en la ultima resolucion */
    private $avisos = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LA RESOLUCION COMPLETA
       ==================================================================== */

    /**
     * El cronograma de todo el horizonte, con el calendario y los overrides ya
     * aplicados.
     *
     * NO LANZA POR EL CALENDARIO NI POR LOS OVERRIDES: los dos pueden fallar y
     * el cronograma se resuelve igual, peor pero explicado. Lo que si lanza es
     * un horizonte invalido, que es un error de programacion.
     *
     * @param Horizonte $h
     * @return array ['pagos' => [...], 'avisos' => [...], 'tabla_creada' => bool]
     */
    public function paraHorizonte($h) {
        $this->avisos = [];

        $habiles = $this->habiles($h);
        $overrides = $this->overrides();

        $crono = CronogramaPagos::paraHorizonte($h, $habiles, $overrides);

        foreach (CronogramaPagos::avisosCalendario($crono['faltan']) as $a) {
            $this->avisos[] = $a;
        }

        if (!$this->tablaCreada()) {
            $this->avisos[] = $this->avisoSinTabla();
        }

        return [
            'pagos' => $crono['pagos'],
            'avisos' => $this->avisos,
            'tabla_creada' => $this->tablaCreada()
        ];
    }

    /** @return array Avisos de la ultima resolucion */
    public function avisos() {
        return $this->avisos;
    }

    /* ====================================================================
       EL CALENDARIO BANCARIO
       ==================================================================== */

    /**
     * Mapa 'Y-m-d' => bool de dias habiles para todo el horizonte.
     *
     * Devuelve un mapa VACIO si el calendario no se puede leer, y deja el
     * motivo en los avisos: con el mapa vacio, CronogramaPagos aplica su
     * fallback de lunes a viernes. Es el mismo criterio de DolarFuturo::curva().
     *
     * @param Horizonte $h
     * @return array
     */
    public function habiles($h) {
        $rango = CronogramaPagos::rangoCalendario($h);

        return $this->habilesEntre($rango['desde'], $rango['hasta']);
    }

    /**
     * Mapa 'Y-m-d' => bool de dias habiles entre dos fechas.
     *
     * POR QUE ES PUBLICA Y APARTE DE habiles(): porque el cronograma de pagos no
     * es el unico que necesita el calendario, y el rango que necesita cada uno es
     * distinto. El cronograma corre los pagos hacia ATRAS, asi que pide un mes
     * ANTES del eje (CronogramaPagos::rangoCalendario()); el vencimiento de una
     * tarjeta corre hacia ADELANTE, asi que pide dias DESPUES del fin del eje
     * (TarjetasVencimiento::rangoCalendario()).
     *
     * Lo que NO se duplica es la lectura: los dos pasan por acá, y acá por
     * Ventas::getDiasHabiles(), que es la unica lectura de RO_T_CALENDARIO del
     * modulo. Dos consultas serian dos definiciones de "dia habil" esperando a
     * desincronizarse; dos RANGOS sobre la misma consulta no son nada.
     *
     * DEVUELVE UN MAPA VACIO SI EL CALENDARIO NO SE PUEDE LEER, y deja el motivo
     * en los avisos: con el mapa vacio, quien corre una fecha aplica su fallback
     * de lunes a viernes. Es el mismo criterio de DolarFuturo::curva().
     *
     * @param string $desde 'Y-m-d'
     * @param string $hasta 'Y-m-d'
     * @return array
     */
    public function habilesEntre($desde, $hasta) {
        try {
            return $this->ventas()->getDiasHabiles($desde, $hasta);
        } catch (Throwable $e) {
            $this->avisos[] = 'No se pudo leer el calendario bancario (' . $e->getMessage()
                . '). Las fechas se calculan asumiendo hábiles los días de lunes a viernes, '
                . 'así que un feriado no corre ninguna fecha.';

            return [];
        }
    }

    /**
     * Puerta al calendario bancario.
     *
     * El require va aca y no en la cabecera para no pagar la carga de Ventas
     * -que arrastra Echeqs y Cotizacion- cuando nadie pide el cronograma.
     *
     * @return Ventas
     */
    private function ventas() {
        require_once __DIR__ . '/Ventas.php';

        if ($this->ventas === null) {
            $this->ventas = new Ventas();
        }

        return $this->ventas;
    }

    /* ====================================================================
       LOS OVERRIDES
       ==================================================================== */

    /** @return bool Si la tabla de overrides existe */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                $this->tabla = false;

                return false;
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
            'Todavía no existe ' . self::TABLA . ': corré '
            . 'sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto '
            . 'las fechas del cronograma se calculan y se ven, pero no se pueden editar.';
    }

    /**
     * Los overrides vigentes, indexados por mes y numero de pago.
     *
     * @return array Mapa 'Y-m' => [nro => ['fecha', 'motivo', 'usuario', 'fecha_alta',
     *                                      'fecha_calculada']]
     */
    public function overrides() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MES, NRO_PAGO, FECHA, FECHA_CALCULADA, MOTIVO, USUARIO, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1
             ORDER BY MES, NRO_PAGO");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los overrides del cronograma'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[trim((string) $row['MES'])][intval($row['NRO_PAGO'])] = [
                'fecha' => self::dia($row['FECHA']),
                'fecha_calculada' => self::dia($row['FECHA_CALCULADA']),
                'motivo' => $row['MOTIVO'],
                'usuario' => $row['USUARIO'],
                'fecha_alta' => self::momento($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * El historial completo de un pago: los overrides que tuvo, vigentes y
     * dados de baja.
     *
     * @param string $mes 'Y-m'
     * @param int $nro 1 o 2
     * @return array
     */
    public function historial($mes, $nro) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT FECHA, FECHA_CALCULADA, MOTIVO, VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE MES = ? AND NRO_PAGO = ?
             ORDER BY ID DESC",
            [self::validarMes($mes), self::validarNro($nro)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial del pago'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'fecha' => self::dia($row['FECHA']),
                'fecha_calculada' => self::dia($row['FECHA_CALCULADA']),
                'motivo' => $row['MOTIVO'],
                'vigente' => (intval($row['VIGENTE']) === 1),
                'usuario' => $row['USUARIO'],
                'fecha_alta' => self::momento($row['FECHA_ALTA']),
                'fecha_baja' => self::momento($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /**
     * Guarda un override: da de baja el vigente e inserta uno nuevo.
     *
     * LA FECHA CALCULADA SE GUARDA JUNTO CON LA PUESTA A MANO. Es contra que se
     * compara el override, y si no se guardara habria que recalcularla con el
     * calendario de HOY para mostrar de cuanto fue la correccion: un feriado
     * agregado despues cambiaria retroactivamente lo que dice el historial.
     *
     * LA VALIDACION QUE VALE ES ESTA. La pantalla acota lo que se puede elegir,
     * pero lo que manda el navegador es un pedido y no una autorizacion.
     *
     * @param string $mes 'Y-m'
     * @param int $nro 1 o 2
     * @param string $fecha 'Y-m-d'
     * @param string|null $fechaCalculada 'Y-m-d', lo que daba el calculo
     * @param string|null $motivo
     * @param string|null $usuario
     * @return array ['mes', 'nro', 'fecha', 'reemplazo' => bool]
     */
    public function guardar($mes, $nro, $fecha, $fechaCalculada = null, $motivo = null,
                            $usuario = null) {
        $this->exigirTabla();

        $m = self::validarMes($mes);
        $n = self::validarNro($nro);
        $f = self::validarFecha($fecha);
        $calc = ($fechaCalculada === null || $fechaCalculada === '')
            ? null : self::validarFecha($fechaCalculada);

        /* Contra la TEORICA y no contra la calculada: la calculada la manda el
           navegador y podria venir corrida. La teorica sale del calendario y de
           nada mas. */
        $partes = explode('-', $m);
        $teorica = CronogramaPagos::teoricasDelMes(intval($partes[0]), intval($partes[1]))[$n];
        $distancia = abs((strtotime($f) - strtotime($teorica)) / 86400);

        if ($distancia > self::DIAS_MARGEN) {
            throw new Exception('La fecha ' . $f . ' está a ' . intval($distancia) . ' días del '
                . ($n === 1 ? '2do' : '4to') . ' viernes de ' . $m . ' (' . $teorica . '). '
                . 'Un pago no se puede mover más de ' . self::DIAS_MARGEN . ' días: '
                . 'revisá el año y el mes.');
        }

        $cid = $this->conectar();
        $bajas = $this->darDeBaja($cid, $m, $n, $usuario);

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA . "
                (MES, NRO_PAGO, FECHA, FECHA_CALCULADA, MOTIVO, USUARIO)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$m, $n, $f, $calc, $motivo, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la fecha del cronograma'));
        }

        sqlsrv_free_stmt($stmt);

        return ['mes' => $m, 'nro' => $n, 'fecha' => $f, 'reemplazo' => ($bajas > 0)];
    }

    /**
     * Vuelve un pago a su fecha calculada: da de baja el override vigente.
     *
     * NO BORRA NADA. La fila queda con su FECHA_BAJA, que es lo que despues
     * explica por que hubo una semana en la que ese pago figuraba otro dia.
     *
     * @param string $mes 'Y-m'
     * @param int $nro 1 o 2
     * @param string|null $usuario
     * @return array ['habia' => bool]
     */
    public function volverACalculado($mes, $nro, $usuario = null) {
        $this->exigirTabla();

        $bajas = $this->darDeBaja($this->conectar(),
            self::validarMes($mes), self::validarNro($nro), $usuario);

        return ['habia' => ($bajas > 0)];
    }

    /**
     * Marca VIGENTE = 0 el override vigente de un pago.
     *
     * @return int Cuantas filas se dieron de baja
     */
    private function darDeBaja($cid, $mes, $nro, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE(), USUARIO = ISNULL(?, USUARIO)
             WHERE MES = ? AND NRO_PAGO = ? AND VIGENTE = 1",
            [$usuario, $mes, $nro]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la fecha anterior'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return intval($filas);
    }

    /* ====================================================================
       VALIDACION
       ==================================================================== */

    /** @return string 'Y-m' */
    public static function validarMes($mes) {
        $m = trim((string) $mes);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new Exception("Mes inválido: '$mes'. Se esperaba el formato YYYY-MM.");
        }

        return $m;
    }

    /** @return int 1 o 2 */
    public static function validarNro($nro) {
        $n = intval($nro);

        if (!isset(CronogramaPagos::ORDINALES[$n])) {
            throw new Exception("Número de pago inválido: '$nro'. Sólo hay dos pagos por mes.");
        }

        return $n;
    }

    /** @return string 'Y-m-d' */
    public static function validarFecha($fecha) {
        $f = substr(trim((string) $fecha), 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $f);

        if (!$d || $d->format('Y-m-d') !== $f) {
            throw new Exception("Fecha inválida: '$fecha'. Se esperaba el formato YYYY-MM-DD.");
        }

        return $f;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Lanza si la tabla no existe */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** Lleva a 'Y-m-d' lo que devuelve sqlsrv para una columna DATE */
    private static function dia($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d');
        }

        return ($valor === null || $valor === '') ? null : substr((string) $valor, 0, 10);
    }

    /** Lleva a 'Y-m-d H:i:s' lo que devuelve sqlsrv para un DATETIME */
    private static function momento($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d H:i:s');
        }

        return ($valor === null || $valor === '') ? null : (string) $valor;
    }

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
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
