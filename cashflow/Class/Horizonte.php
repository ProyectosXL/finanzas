<?php

require_once __DIR__ . '/Parametros.php';

/**
 * Horizonte
 * Eje temporal compartido por la proyeccion de Ventas y el tablero de Cashflow.
 *
 * POR QUE EXISTE
 * --------------
 * El eje es uno solo y lo definen los parametros 'horizonte_dias' y
 * 'horizonte_meses'. Antes vivia dentro de Ventas::calcular() y de
 * Ventas::ejeMeses(); el Cashflow necesita exactamente el mismo eje para poder
 * consolidar sobre las mismas columnas, y dos implementaciones se
 * desincronizarian.
 *
 * LAS DOS RAMAS DEL EJE
 * ---------------------
 *   dias  -> tramo diario: hoy .. hoy + horizonte_dias - 1, claves 'Y-m-d'
 *   meses -> mes actual + los siguientes (horizonte_meses - 1), claves 'Y-m'
 *
 * Un importe va SIEMPRE a una columna diaria O a la columna de su mes, nunca a
 * las dos: la columna de un mes acumula unicamente los dias de ese mes que
 * quedaron FUERA del tramo diario. Eso es lo que evita contar dos veces, y es
 * la regla que implementan acumular() y agrupar().
 *
 * LAS COLUMNAS NO ESTAN EN ORDEN CRONOLOGICO
 * ------------------------------------------
 * Con horizonte_dias=28 y hoy=06/09/2026, la columna del mes '2026-09'
 * contiene solo del 1 al 5 de septiembre, que YA PASARON, mientras que la
 * columna '2026-10' contiene del 4 al 31 de octubre, o sea DESPUES de la
 * ultima columna diaria. Y el orden se invierte segun el horizonte: con
 * horizonte_dias=20 el tramo cierra el 25/09 y el resto de septiembre va
 * despues del tramo.
 *
 * Por eso cualquier calculo que dependa del orden del tiempo (el arrastre del
 * saldo del Cashflow, por ejemplo) NO puede recorrer las columnas en el orden
 * en que se dibujan: tiene que usar secuencia(), que devuelve las columnas en
 * orden cronologico real y deja afuera las que no representan ningun dia
 * futuro.
 *
 * NO tiene estado de negocio ni toca la base de datos: es solo el eje.
 */
class Horizonte {

    /** Abreviaturas de mes para los rotulos de columna */
    private static $mesesAbrev = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',  5 => 'May',  6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
    ];

    /** @var DateTime Dia de referencia. Se calcula UNA vez: ver nota en el constructor */
    private $hoy;

    private $horizonteDias;
    private $horizonteMeses;
    private $feriadosMMDD;

    /** @var array Lista de columnas diarias */
    private $dias = [];
    /** @var array Mapa 'Y-m-d' => true, para preguntar si una fecha esta en el tramo */
    private $diasSet = [];
    /** @var array Lista de columnas mensuales */
    private $meses = [];
    /** @var array Mapa 'Y-m' => true */
    private $mesesSet = [];
    /** @var array|null Cache de secuencia() */
    private $secuencia = null;

    /**
     * @param int $horizonteDias Columnas diarias. 0 = sin tramo diario (lo usa
     *                           el Analisis de Ventas, que es solo mensual)
     * @param int $horizonteMeses Columnas mensuales. Debe ser al menos 1
     * @param array $feriadosMMDD Feriados de comercio 'MM-DD', para marcar las
     *                            columnas diarias sin venta estimada
     * @param DateTime|null $hoy Dia de referencia; por defecto hoy
     */
    public function __construct($horizonteDias, $horizonteMeses, $feriadosMMDD = [], $hoy = null) {
        $this->horizonteDias  = intval($horizonteDias);
        $this->horizonteMeses = intval($horizonteMeses);
        $this->feriadosMMDD   = is_array($feriadosMMDD) ? $feriadosMMDD : [];

        if ($this->horizonteMeses < 1) {
            throw new Exception('El horizonte de meses debe ser mayor a cero');
        }

        if ($this->horizonteDias < 0) {
            throw new Exception('El horizonte de dias no puede ser negativo');
        }

        // El dia de referencia se resuelve UNA sola vez y las dos ramas del eje
        // se construyen a partir de el. Antes el tramo diario y ejeMeses()
        // hacian cada uno su propio new DateTime('today'): un pedido que cruzara
        // la medianoche entre las dos llamadas armaba los dos ejes con dias
        // distintos.
        $this->hoy = ($hoy instanceof DateTime) ? clone $hoy : new DateTime('today');

        $this->construirDias();
        $this->construirMeses();
    }

    /**
     * Arma el eje leyendo el horizonte de los parametros.
     *
     * @param Parametros $parametros Puerta de acceso a la configuracion
     * @param array|null $map Mapa CLAVE => VALOR ya leido, para no repetir la
     *                        consulta. Si es null lo pide.
     * @return Horizonte
     */
    public static function desdeParametros($parametros, $map = null) {
        if ($map === null) {
            $map = $parametros->getParametrosMap();
        }

        return new self(
            Parametros::ent($map, 'horizonte_dias'),
            Parametros::ent($map, 'horizonte_meses'),
            $parametros->getFeriadosComercio($map)
        );
    }

    /**
     * Rotulo corto de un mes: 'Sep-26'.
     * Es la unica implementacion del rotulo; la usan el eje de meses y las
     * columnas de anios anteriores del Analisis de Ventas.
     *
     * @param int $anio Anio de cuatro digitos
     * @param int $mes Mes 1..12
     * @return string
     */
    public static function labelMes($anio, $mes) {
        $mes = intval($mes);

        return self::$mesesAbrev[$mes] . '-' . substr((string) intval($anio), 2);
    }

    /* ====================================================================
       EL EJE
       ==================================================================== */

    /** Tramo diario: hoy .. hoy + horizonte_dias - 1 */
    private function construirDias() {
        $cursor = clone $this->hoy;

        for ($i = 0; $i < $this->horizonteDias; $i++) {
            $fecha = $cursor->format('Y-m-d');

            $this->dias[] = [
                'fecha' => $fecha,
                'label' => intval($cursor->format('j')) . '/' . intval($cursor->format('n')),
                'mes_clave' => $cursor->format('Y-m'),
                'feriado_comercio' => in_array($cursor->format('m-d'), $this->feriadosMMDD)
            ];

            $this->diasSet[$fecha] = true;
            $cursor->modify('+1 day');
        }
    }

    /** Eje de meses: mes ACTUAL + los siguientes (horizonte_meses - 1) */
    private function construirMeses() {
        $primero = new DateTime($this->hoy->format('Y-m-01'));

        for ($i = 0; $i < $this->horizonteMeses; $i++) {
            $ref = clone $primero;
            $ref->modify("+$i month");

            $anio = intval($ref->format('Y'));
            $mes  = intval($ref->format('n'));

            $this->meses[] = [
                'clave' => $ref->format('Y-m'),
                'anio' => $anio,
                'mes' => $mes,
                'label' => self::labelMes($anio, $mes)
            ];

            $this->mesesSet[$ref->format('Y-m')] = true;
        }
    }

    /** @return array Columnas diarias: fecha, label, mes_clave, feriado_comercio */
    public function dias() {
        return $this->dias;
    }

    /** @return array Columnas mensuales: clave, anio, mes, label */
    public function meses() {
        return $this->meses;
    }

    /** @return array Mapa 'Y-m-d' => true de los dias del tramo */
    public function diasSet() {
        return $this->diasSet;
    }

    /** @return array Mapa 'Y-m' => true de los meses del horizonte */
    public function mesesSet() {
        return $this->mesesSet;
    }

    /** @return string Dia de referencia, 'Y-m-d' */
    public function hoy() {
        return $this->hoy->format('Y-m-d');
    }

    /** @return int Cantidad de columnas diarias */
    public function cantidadDias() {
        return $this->horizonteDias;
    }

    /** @return int Cantidad de columnas mensuales */
    public function cantidadMeses() {
        return $this->horizonteMeses;
    }

    /**
     * Ultimo dia cubierto por el eje: el fin del ultimo mes o el ultimo dia del
     * tramo diario, el que caiga mas tarde. Con un horizonte de dias mas largo
     * que el de meses manda el tramo diario.
     *
     * @return string 'Y-m-d'
     */
    public function fin() {
        $ultimoMes = $this->meses[count($this->meses) - 1];

        $fin = (new DateTime($ultimoMes['clave'] . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');

        if (!empty($this->dias)) {
            $ultimoDia = $this->dias[count($this->dias) - 1]['fecha'];

            if ($ultimoDia > $fin) {
                $fin = $ultimoDia;
            }
        }

        return $fin;
    }

    /* ====================================================================
       COLUMNAS Y ORDEN CRONOLOGICO
       ==================================================================== */

    /**
     * Columna a la que corresponde una fecha, o null si cae fuera del eje.
     * Un dia del tramo gana sobre la columna de su mes: es la regla que evita
     * contar dos veces.
     *
     * @param string $fecha 'Y-m-d'
     * @return string|null 'DIA|Y-m-d', 'MES|Y-m' o null
     */
    public function columna($fecha) {
        if (isset($this->diasSet[$fecha])) {
            return 'DIA|' . $fecha;
        }

        $mes = substr($fecha, 0, 7);

        if (isset($this->mesesSet[$mes])) {
            return 'MES|' . $mes;
        }

        return null;
    }

    /**
     * Columnas en orden CRONOLOGICO real, para todo calculo que dependa del
     * paso del tiempo (el arrastre del saldo).
     *
     * Recorre el calendario desde HOY hasta fin(), ubica cada fecha en su
     * columna y junta las repeticiones consecutivas. Arrancar en hoy es lo que
     * garantiza que un mes no quede partido en dos posiciones (dias antes del
     * tramo Y dias despues): los dias del mes en curso anteriores a hoy no
     * generan movimiento, asi que su columna simplemente no entra en la
     * secuencia.
     *
     * Las columnas que NO estan en el resultado son las que no representan
     * ningun dia futuro: la del mes en curso cuando el tramo diario arranca
     * hoy. Para esas, el Cashflow muestra un guion y no un cero, porque un cero
     * en Saldo Final se leeria como "proyectamos cero pesos de caja".
     *
     * @return array Lista de ids de columna en orden cronologico
     */
    public function secuencia() {
        if ($this->secuencia !== null) {
            return $this->secuencia;
        }

        $seq = [];
        $cursor = clone $this->hoy;
        $fin = new DateTime($this->fin());

        while ($cursor <= $fin) {
            $id = $this->columna($cursor->format('Y-m-d'));

            if ($id !== null && (empty($seq) || end($seq) !== $id)) {
                $seq[] = $id;
            }

            $cursor->modify('+1 day');
        }

        $this->secuencia = $seq;

        return $this->secuencia;
    }

    /**
     * @param string $columna Id devuelto por columna()
     * @return bool Si la columna representa dias futuros
     */
    public function enSecuencia($columna) {
        return in_array($columna, $this->secuencia(), true);
    }

    /* ====================================================================
       AGRUPAMIENTO DE IMPORTES
       ==================================================================== */

    /**
     * Serie vacia con TODAS las claves del eje ya inicializadas en cero, para
     * que quien la consuma no tenga que preguntar si la clave existe.
     *
     * @return array ['dias' => ['Y-m-d' => 0], 'meses' => ['Y-m' => 0]]
     */
    public function serieVacia() {
        $serie = ['dias' => [], 'meses' => []];

        foreach ($this->dias as $d) {
            $serie['dias'][$d['fecha']] = 0;
        }

        foreach ($this->meses as $m) {
            $serie['meses'][$m['clave']] = 0;
        }

        return $serie;
    }

    /**
     * Suma un importe en la columna que le corresponde a una fecha, aplicando
     * la regla dia O mes (nunca las dos).
     *
     * @param array $serie Serie a modificar, por referencia
     * @param string $fecha 'Y-m-d'
     * @param float $importe
     * @return bool false si la fecha cae fuera del eje y el importe no se sumo
     */
    public function acumular(&$serie, $fecha, $importe) {
        $destino = self::ubicar($serie, $fecha);

        if ($destino === null) {
            return false;
        }

        $serie[$destino[0]][$destino[1]] += $importe;

        return true;
    }

    /**
     * A que rama y clave de una serie le corresponde una fecha, o null si no cae
     * en ninguna columna. ES LA REGLA "DIA O MES, NUNCA LAS DOS", escrita una
     * sola vez.
     *
     * Va estatica y sobre la serie -y no sobre el eje del objeto- porque hay un
     * consumidor que no tiene un Horizonte a mano: el neteo de cheques
     * adelantados de Ventas arma sus buckets a partir de las listas de claves que
     * recibe por parametro. Sin esto, ese metodo terminaria reimplementando la
     * regla, que es exactamente lo que este modulo evita en todos lados.
     *
     * @param array $serie Serie con las claves del eje ya inicializadas
     * @param string $fecha 'Y-m-d'
     * @return array|null ['dias'|'meses', clave] o null
     */
    public static function ubicar($serie, $fecha) {
        if (isset($serie['dias'][$fecha])) {
            return ['dias', $fecha];
        }

        $mes = substr((string) $fecha, 0, 7);

        if (isset($serie['meses'][$mes])) {
            return ['meses', $mes];
        }

        return null;
    }

    /**
     * Agrupa una lista de registros contra el eje. Es el agrupador que usan los
     * proveedores del Cashflow, para que cada uno no repita el mismo bucle.
     *
     * Informa en 'fuera_horizonte' el total que quedo afuera del eje. NO se
     * descarta en silencio: un tablero de consolidacion que informa de menos
     * sin decirlo es peor que uno que falla.
     *
     * @param array $items Registros
     * @param string $campoFecha Nombre del campo con la fecha ('Y-m-d', DateTime o timestamp)
     * @param string $campoImporte Nombre del campo con el importe
     * @param float $factor Multiplicador a aplicar (por ejemplo un tipo de cambio)
     * @return array ['dias'=>..., 'meses'=>..., 'fuera_horizonte'=>float, 'sin_fecha'=>float]
     */
    public function agrupar($items, $campoFecha, $campoImporte, $factor = 1) {
        $serie = $this->serieVacia();
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        if (!is_array($items)) {
            return $serie;
        }

        foreach ($items as $item) {
            $importe = isset($item[$campoImporte]) ? floatval($item[$campoImporte]) * $factor : 0;

            if ($importe == 0) {
                continue;
            }

            $fecha = self::normalizarFecha(isset($item[$campoFecha]) ? $item[$campoFecha] : null);

            if ($fecha === null) {
                $serie['sin_fecha'] += $importe;
                continue;
            }

            if (!$this->acumular($serie, $fecha, $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        return $serie;
    }

    /**
     * Lleva a 'Y-m-d' lo que devuelve sqlsrv, que para una columna de fecha
     * entrega un DateTime y no un string.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function normalizarFecha($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d');
        }

        if (is_string($valor) && $valor !== '') {
            // Ya viene como 'Y-m-d' o 'Y-m-d H:i:s'
            return substr($valor, 0, 10);
        }

        return null;
    }
}
