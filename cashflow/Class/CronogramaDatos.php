<?php

require_once __DIR__ . '/CronogramaPagos.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * CronogramaDatos
 * La parte del cronograma de pagos que toca la base: la configuracion de cada
 * concepto, el calendario bancario y los overrides.
 *
 * POR QUE ESTA SEPARADA DE CronogramaPagos
 * -----------------------------------------
 * Mismo criterio que ComprasProyectadas / ComprasProyectadasDatos y que
 * DolarFuturo: la regla -que dias son, hacia donde se corren, cual gana, como
 * se reparte- es lo delicado y se prueba sin base. Lo que hay aca son consultas
 * y escrituras, que sin base no se pueden probar y que tampoco tienen nada que
 * decidir.
 *
 * TODO ES POR CONCEPTO
 * --------------------
 * PROV_LOCALES, LOGISTICA y SUPERVISORAS tienen cada uno su dia, su frecuencia y
 * sus overrides (RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT.TIPO). Quien pide el
 * cronograma dice de que concepto: paraHorizonte($h, 'LOGISTICA'). No hay un
 * cronograma "por defecto" que se pueda pedir sin decir de quien es, porque eso
 * es exactamente como un consumidor nuevo terminaria pagando el dia de otro.
 *
 * LA CONFIGURACION SON SEIS PARAMETROS (cronograma_<concepto>_dia y
 * _frecuencia, GRUPO 'CRONOGRAMA' de RO_T_CASHFLOW_PARAMETROS). Se leen aca con
 * una consulta propia y no con Parametros::getParametrosMap(), que trae todos
 * los parametros del modulo: el cronograma lo piden cuatro pantallas y el
 * tablero, y ninguna necesita el resto. Sin el script, cada concepto usa su
 * valor por defecto con un aviso (CronogramaPagos::configDesdeMapa()).
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
 * pestana entera de Parametros y las filas del tablero que dependen del
 * cronograma en blanco por no poder decidir si un dia es feriado.
 *
 * LOS OVERRIDES NO SE BORRAN
 * ---------------------------
 * Volver al valor calculado marca VIGENTE = 0 y deja la fila. Con un DELETE,
 * "esta fecha nunca se toco" y "se toco y se volvio atras" son indistinguibles
 * despues del hecho. Mismo criterio que RO_T_CASHFLOW_COMEX_FECHA_EDIT y que
 * RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE.
 *
 * SIN LA COLUMNA TIPO (falta sql/cashflow_cronograma_conceptos.sql) los
 * overrides que hay son todos de Logistica -era el unico cronograma con overrides
 * editables- y se siguen aplicando solo a Logistica. Los otros dos conceptos se
 * calculan y se ven, pero no se pueden mover a mano.
 */
class CronogramaDatos {

    /** La tabla de overrides, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT';

    /** El script de esta entrega, para los avisos */
    const SCRIPT = 'sql/cashflow_cronograma_conceptos.sql';

    /** El concepto de los overrides que ya existian antes de la columna TIPO */
    const TIPO_HISTORICO = 'LOGISTICA';

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

    /** @var bool|null Cache del chequeo de la columna TIPO */
    private $tipo = null;

    /** @var array|null Cache de los parametros del cronograma */
    private $parametros = null;

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
     * El cronograma de un concepto en todo el horizonte, con la configuracion,
     * el calendario y los overrides ya aplicados.
     *
     * NO LANZA POR LA CONFIGURACION, EL CALENDARIO NI LOS OVERRIDES: los tres
     * pueden fallar y el cronograma se resuelve igual, peor pero explicado. Lo
     * que si lanza es un concepto o un horizonte invalido, que son errores de
     * programacion.
     *
     * @param Horizonte $h
     * @param string $concepto PROV_LOCALES, LOGISTICA o SUPERVISORAS
     * @param int $mesesExtra Meses despues del horizonte. Ver CronogramaPagos::paraHorizonte()
     * @return array ['pagos', 'avisos', 'config', 'tabla_creada', 'editable']
     */
    public function paraHorizonte($h, $concepto, $mesesExtra = 0) {
        $concepto = CronogramaPagos::validarConcepto($concepto);
        $this->avisos = [];

        $config = $this->config($concepto);

        if ($config['aviso'] !== null) {
            $this->avisos[] = $config['aviso'];
        }

        $rango = CronogramaPagos::rangoCalendario($h, $mesesExtra);
        $habiles = $this->habilesEntre($rango['desde'], $rango['hasta']);

        $overrides = [];

        try {
            $overrides = $this->overrides($concepto);
        } catch (Throwable $e) {
            $this->avisos[] = 'No se pudieron leer los pagos movidos a mano del cronograma de '
                . CronogramaPagos::CONCEPTOS[$concepto]['nombre'] . ' (' . $e->getMessage()
                . '). Se usan las fechas calculadas.';
        }

        $crono = CronogramaPagos::paraHorizonte($h, $config, $habiles, $overrides, $mesesExtra);

        foreach (CronogramaPagos::avisosCalendario($crono['faltan']) as $a) {
            $this->avisos[] = $a;
        }

        if (!$this->tablaCreada()) {
            $this->avisos[] = $this->avisoSinTabla();
        }

        return [
            'pagos' => $crono['pagos'],
            'avisos' => $this->avisos,
            'config' => $config,
            'tabla_creada' => $this->tablaCreada(),
            'editable' => $this->editable($concepto)
        ];
    }

    /** @return array Avisos de la ultima resolucion */
    public function avisos() {
        return $this->avisos;
    }

    /* ====================================================================
       LA CONFIGURACION DE CADA CONCEPTO
       ==================================================================== */

    /**
     * La configuracion de un concepto: la de los parametros, o el defecto con
     * su aviso.
     *
     * @param string $concepto
     * @return array ['dia', 'frecuencia', 'defecto', 'aviso', 'descripcion']
     */
    public function config($concepto) {
        $mapa = [];

        foreach ($this->parametros() as $clave => $p) {
            $mapa[$clave] = $p['valor'];
        }

        $cfg = CronogramaPagos::configDesdeMapa($mapa, $concepto);
        $cfg['descripcion'] = CronogramaPagos::describir($cfg);

        return $cfg;
    }

    /**
     * Los tres conceptos con su configuracion y quien la cambio por ultima vez,
     * para la tabla de configuracion de Parametros -> Generales.
     *
     * EL QUIEN Y CUANDO ES EL DEL ULTIMO DE LOS DOS PARAMETROS que se toco: el dia
     * y la frecuencia se guardan juntos, asi que es una sola modificacion.
     *
     * @return array Lista de ['concepto', 'nombre', 'usa', 'dia', 'frecuencia',
     *               'descripcion', 'defecto', 'usuario', 'fecha_modif']
     */
    public function configuraciones() {
        $v = [];
        $params = $this->parametros();

        foreach (CronogramaPagos::CONCEPTOS as $concepto => $meta) {
            $cfg = $this->config($concepto);
            $claves = CronogramaPagos::clavesParametro($concepto);
            $usuario = null;
            $fecha = null;

            foreach ($claves as $clave) {
                if (isset($params[$clave]) && $params[$clave]['fecha'] !== null
                    && ($fecha === null || $params[$clave]['fecha'] > $fecha)) {
                    $fecha = $params[$clave]['fecha'];
                    $usuario = $params[$clave]['usuario'];
                }
            }

            $v[] = [
                'concepto' => $concepto,
                'nombre' => $meta['nombre'],
                'usa' => $meta['usa'],
                'dia' => $cfg['dia'],
                'frecuencia' => $cfg['frecuencia'],
                'descripcion' => $cfg['descripcion'],
                'defecto' => $cfg['defecto'],
                'editable' => $this->editable($concepto),
                'configurable' => $this->parametrosCreados($concepto),
                'usuario' => $usuario,
                'fecha_modif' => $fecha
            ];
        }

        return $v;
    }

    /**
     * Si los dos parametros de un concepto existen: sin ellos no hay donde
     * guardar un cambio de dia, y la pantalla dibuja los desplegables apagados.
     *
     * @param string $concepto
     * @return bool
     */
    public function parametrosCreados($concepto) {
        $params = $this->parametros();

        foreach (CronogramaPagos::clavesParametro($concepto) as $clave) {
            if (!isset($params[$clave])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Los parametros del cronograma, leidos una vez.
     *
     * SI LA LECTURA FALLA DEVUELVE VACIO y cada concepto cae a su defecto, con el
     * aviso de configDesdeMapa(): un parametro ilegible no puede dejar a tres
     * consumidores sin fechas de pago.
     *
     * @return array Mapa CLAVE => ['valor', 'usuario', 'fecha']
     */
    private function parametros() {
        if ($this->parametros !== null) {
            return $this->parametros;
        }

        $this->parametros = [];

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT CLAVE, VALOR, USUARIO_MODIF, FECHA_MODIF
                 FROM dbo.RO_T_CASHFLOW_PARAMETROS
                 WHERE CLAVE LIKE 'cronograma[_]%'");

            if ($stmt === false) {
                return $this->parametros;
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $this->parametros[trim((string) $row['CLAVE'])] = [
                    'valor' => $row['VALOR'],
                    'usuario' => $row['USUARIO_MODIF'],
                    'fecha' => self::momento($row['FECHA_MODIF'])
                ];
            }

            sqlsrv_free_stmt($stmt);
        } catch (Throwable $e) {
            $this->parametros = [];
        }

        return $this->parametros;
    }

    /**
     * Cuantos overrides vigentes se darian de baja si cambia la configuracion de
     * un concepto: los de mes >= el actual.
     *
     * Es lo que la pantalla muestra en la confirmacion, ANTES de guardar. Un
     * cambio de dia que se lleva puestos tres pagos movidos a mano sin decirlo es
     * un cambio que nadie decidio entero.
     *
     * @param string $concepto
     * @param string|null $mes 'Y-m'; por defecto el actual
     * @return int
     */
    public function contarOverridesABajar($concepto, $mes = null) {
        $concepto = CronogramaPagos::validarConcepto($concepto);

        if (!$this->editable($concepto)) {
            return 0;
        }

        $mes = ($mes === null) ? CronogramaPagos::desdeMesBaja(date('Y-m-d')) : self::validarMes($mes);
        list($where, $params) = $this->filtroTipo($concepto);

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COUNT(*) AS N FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1 AND MES >= ?" . $where,
            array_merge([$mes], $params));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al contar los pagos movidos a mano'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($row['N']);
    }

    /**
     * Cambia el dia y la frecuencia de un concepto, y da de baja sus overrides
     * vigentes desde el mes actual.
     *
     * POR QUE LA BAJA: el override se guarda contra (concepto, mes, numero de
     * pago). Con otro dia o frecuencia el numero de pago apunta a otra fecha, y el
     * override quedaria colgado de un pago que ya no es el mismo. Los de meses
     * anteriores se dejan: describen lo que ya paso.
     *
     * TODO EN UNA TRANSACCION: un dia cambiado con los overrides viejos vigentes
     * es exactamente el estado que esta funcion existe para evitar.
     *
     * SI NO CAMBIA NADA NO ESCRIBE NADA, y no da de baja ningun override: volver a
     * guardar la misma configuracion no puede llevarse puestos los pagos movidos.
     *
     * @param string $concepto
     * @param mixed $dia 1..7
     * @param string $frecuencia
     * @param string $usuario
     * @return array ['concepto', 'config', 'cambio' => bool, 'bajas' => int]
     */
    public function guardarConfig($concepto, $dia, $frecuencia, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $concepto = CronogramaPagos::validarConcepto($concepto);
        $nueva = CronogramaPagos::config($dia, $frecuencia);

        if (!$this->parametrosCreados($concepto)) {
            throw new Exception('Todavía no existen los parámetros del cronograma de '
                . CronogramaPagos::CONCEPTOS[$concepto]['nombre'] . ': corré ' . self::SCRIPT
                . ' contra la base central.');
        }

        $actual = $this->config($concepto);

        if (!CronogramaPagos::requiereBaja($actual, $nueva)) {
            return ['concepto' => $concepto, 'config' => $nueva, 'cambio' => false, 'bajas' => 0];
        }

        $cid = $this->conectar();
        $claves = CronogramaPagos::clavesParametro($concepto);

        if (!sqlsrv_begin_transaction($cid)) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach (['dia' => strval($nueva['dia']), 'frecuencia' => $nueva['frecuencia']]
                     as $campo => $valor) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
                     SET VALOR = ?, " . Auditoria::SET_MODIF . "
                     WHERE CLAVE = ?",
                    [$valor, $usuario, $claves[$campo]]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al guardar el cronograma'));
                }

                sqlsrv_free_stmt($stmt);
            }

            $bajas = 0;

            if ($this->editable($concepto)) {
                list($where, $params) = $this->filtroTipo($concepto);

                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA . "
                     SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                     WHERE VIGENTE = 1 AND MES >= ?" . $where,
                    array_merge([$usuario, $usuario, CronogramaPagos::desdeMesBaja(date('Y-m-d'))],
                        $params));

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al dar de baja los pagos movidos'));
                }

                $bajas = intval(sqlsrv_rows_affected($stmt));
                sqlsrv_free_stmt($stmt);
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->parametros = null;

        return ['concepto' => $concepto, 'config' => $nueva, 'cambio' => true, 'bajas' => $bajas];
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
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T,
                        COL_LENGTH('dbo." . self::TABLA . "', 'TIPO') AS TIPO");

            if ($stmt === false) {
                $this->tabla = false;
                $this->tipo = false;

                return false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
            $this->tipo = ($row && $row['TIPO'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
            $this->tipo = false;
        }

        return $this->tabla;
    }

    /** @return bool Si la tabla de overrides ya tiene la columna TIPO */
    public function tieneTipo() {
        $this->tablaCreada();

        return (bool) $this->tipo;
    }

    /**
     * Si los pagos de un concepto se pueden mover a mano.
     *
     * Sin la columna TIPO solo Logistica: los overrides que hay son suyos, y uno
     * nuevo de otro concepto se guardaria sin decir de quien es.
     *
     * @param string $concepto
     * @return bool
     */
    public function editable($concepto) {
        return $this->tablaCreada()
            && ($this->tieneTipo() || $concepto === self::TIPO_HISTORICO);
    }

    /** @return string El aviso de script faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe ' . self::TABLA . ': corré '
            . 'sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto '
            . 'las fechas del cronograma se calculan y se ven, pero no se pueden editar.';
    }

    /** @return string El aviso de la columna TIPO faltante, o '' */
    public function avisoSinTipo() {
        return (!$this->tablaCreada() || $this->tieneTipo()) ? '' :
            'Los pagos movidos a mano todavía no distinguen concepto: corré ' . self::SCRIPT
            . ' contra la base central. Mientras tanto sólo se pueden mover los pagos de '
            . 'Logística Local, que son los que ya había.';
    }

    /**
     * El filtro por concepto de las consultas a la tabla de overrides.
     *
     * Sin la columna TIPO no hay filtro, y solo se llega aca con LOGISTICA (ver
     * editable()), que es de quien son todos.
     *
     * @return array [string $where, array $params]
     */
    private function filtroTipo($concepto) {
        return $this->tieneTipo() ? [' AND TIPO = ?', [$concepto]] : ['', []];
    }

    /**
     * Los overrides vigentes de un concepto, indexados por mes y numero de pago.
     *
     * @param string $concepto
     * @return array Mapa 'Y-m' => [nro => ['fecha', 'motivo', 'usuario', 'fecha_alta',
     *                                      'fecha_calculada']]
     */
    public function overrides($concepto) {
        $concepto = CronogramaPagos::validarConcepto($concepto);

        if (!$this->editable($concepto)) {
            return [];
        }

        list($where, $params) = $this->filtroTipo($concepto);

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MES, NRO_PAGO, FECHA, FECHA_CALCULADA, MOTIVO, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1" . $where . "
             ORDER BY MES, NRO_PAGO", $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los overrides del cronograma'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[trim((string) $row['MES'])][intval($row['NRO_PAGO'])] = [
                'fecha' => self::dia($row['FECHA']),
                'fecha_calculada' => self::dia($row['FECHA_CALCULADA']),
                'motivo' => $row['MOTIVO'],
                'usuario' => $row['USUARIO_ALTA'],
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
     * @param string $concepto
     * @param string $mes 'Y-m'
     * @param int $nro 1..5
     * @return array
     */
    public function historial($concepto, $mes, $nro) {
        $concepto = CronogramaPagos::validarConcepto($concepto);

        if (!$this->editable($concepto)) {
            return [];
        }

        list($where, $params) = $this->filtroTipo($concepto);

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT FECHA, FECHA_CALCULADA, MOTIVO, VIGENTE, USUARIO_ALTA, FECHA_ALTA,
                    USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE MES = ? AND NRO_PAGO = ?" . $where . "
             ORDER BY ID DESC",
            array_merge([self::validarMes($mes), self::validarNro($nro)], $params));

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
                'usuario' => $row['USUARIO_ALTA'],
                'fecha_alta' => self::momento($row['FECHA_ALTA']),
                'usuario_baja' => $row['USUARIO_BAJA'],
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
     * pero lo que manda el navegador es un pedido y no una autorizacion. El
     * numero de pago se valida contra la configuracion VIGENTE del concepto: el
     * 5to lunes de un mes de cuatro no existe.
     *
     * @param string $concepto
     * @param string $mes 'Y-m'
     * @param int $nro
     * @param string $fecha 'Y-m-d'
     * @param string|null $fechaCalculada 'Y-m-d', lo que daba el calculo
     * @param string|null $motivo
     * @param string $usuario
     * @return array ['concepto', 'mes', 'nro', 'fecha', 'nombre', 'reemplazo' => bool]
     */
    public function guardar($concepto, $mes, $nro, $fecha, $fechaCalculada, $motivo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $concepto = CronogramaPagos::validarConcepto($concepto);
        $this->exigirEditable($concepto);

        $m = self::validarMes($mes);
        $n = self::validarNro($nro);
        $f = self::validarFecha($fecha);
        $calc = ($fechaCalculada === null || $fechaCalculada === '')
            ? null : self::validarFecha($fechaCalculada);

        /* Contra la TEORICA y no contra la calculada: la calculada la manda el
           navegador y podria venir corrida. La teorica sale del calendario y de
           la configuracion, y de nada mas. */
        $config = $this->config($concepto);
        $partes = explode('-', $m);
        $teoricas = CronogramaPagos::teoricasDelMes(intval($partes[0]), intval($partes[1]), $config);

        if (!isset($teoricas[$n])) {
            throw new Exception($m . ' tiene ' . count($teoricas) . ' pago(s) de '
                . CronogramaPagos::CONCEPTOS[$concepto]['nombre'] . ' ('
                . CronogramaPagos::describir($config) . '): no hay un pago ' . $n . '.');
        }

        $teorica = $teoricas[$n];
        $nombre = CronogramaPagos::nombrePago($n, $config);
        $distancia = abs((strtotime($f) - strtotime($teorica)) / 86400);

        if ($distancia > self::DIAS_MARGEN) {
            throw new Exception('La fecha ' . $f . ' está a ' . intval($distancia) . ' días del '
                . $nombre . ' de ' . $m . ' (' . $teorica . '). '
                . 'Un pago no se puede mover más de ' . self::DIAS_MARGEN . ' días: '
                . 'revisá el año y el mes.');
        }

        $cid = $this->conectar();
        $bajas = $this->darDeBaja($cid, $concepto, $m, $n, $usuario);

        if ($this->tieneTipo()) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (TIPO, MES, NRO_PAGO, FECHA, FECHA_CALCULADA, MOTIVO, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$concepto, $m, $n, $f, $calc, $motivo, $usuario, $usuario]);
        } else {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (MES, NRO_PAGO, FECHA, FECHA_CALCULADA, MOTIVO, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$m, $n, $f, $calc, $motivo, $usuario, $usuario]);
        }

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la fecha del cronograma'));
        }

        sqlsrv_free_stmt($stmt);

        return ['concepto' => $concepto, 'mes' => $m, 'nro' => $n, 'fecha' => $f,
                'nombre' => $nombre, 'reemplazo' => ($bajas > 0)];
    }

    /**
     * Vuelve un pago a su fecha calculada: da de baja el override vigente.
     *
     * NO BORRA NADA. La fila queda con su FECHA_BAJA, que es lo que despues
     * explica por que hubo una semana en la que ese pago figuraba otro dia.
     *
     * @param string $concepto
     * @param string $mes 'Y-m'
     * @param int $nro
     * @param string $usuario
     * @return array ['habia' => bool]
     */
    public function volverACalculado($concepto, $mes, $nro, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $concepto = CronogramaPagos::validarConcepto($concepto);
        $this->exigirEditable($concepto);

        $bajas = $this->darDeBaja($this->conectar(), $concepto,
            self::validarMes($mes), self::validarNro($nro), $usuario);

        return ['habia' => ($bajas > 0)];
    }

    /**
     * Marca VIGENTE = 0 el override vigente de un pago.
     *
     * La baja va en sus propias columnas: el historial no puede terminar
     * diciendo que la fecha la puso quien la saco.
     *
     * @return int Cuantas filas se dieron de baja
     */
    private function darDeBaja($cid, $concepto, $mes, $nro, $usuario) {
        list($where, $params) = $this->filtroTipo($concepto);

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE MES = ? AND NRO_PAGO = ? AND VIGENTE = 1" . $where,
            array_merge([$usuario, $usuario, $mes, $nro], $params));

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

    /**
     * El rango de un numero de pago, sin mirar el mes: 1..5. Si ese mes tiene
     * ese pago lo decide guardar(), que sabe la configuracion.
     *
     * @return int
     */
    public static function validarNro($nro) {
        $n = filter_var($nro, FILTER_VALIDATE_INT);

        if ($n === false || $n < 1 || $n > CronogramaPagos::MAX_PAGOS) {
            throw new Exception("Número de pago inválido: '$nro'. Va de 1 a "
                . CronogramaPagos::MAX_PAGOS . '.');
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

    /** Lanza si los pagos de ese concepto no se pueden mover */
    private function exigirEditable($concepto) {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        if (!$this->editable($concepto)) {
            throw new Exception($this->avisoSinTipo());
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
