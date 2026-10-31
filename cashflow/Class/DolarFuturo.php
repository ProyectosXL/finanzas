<?php

/**
 * DolarFuturo
 * La curva de dolar futuro ROFEX: a cuanto se valua un pago en dolares segun el
 * MES en que se va a pagar.
 *
 * POR QUE EXISTE, Y POR QUE REEMPLAZA A UN PARAMETRO
 * --------------------------------------------------
 * Los pagos a proveedores del exterior se valuaban con UN parametro global
 * -'comex_tipo_cambio_usd'-, un numero que alguien cargaba a mano en Parametros
 * y con el que se convertian por igual el contenedor que se paga el mes que
 * viene y el que se paga dentro de once meses.
 *
 * Eso es exactamente la cuenta que el encabezado de Cotizacion describe como
 * incorrecta: valuar una serie de meses con un unico tipo de cambio no es
 * proyectar, es reexpresar toda la serie a moneda de hoy. Con la inflacion y la
 * devaluacion esperada que tiene este pais, las dos cuentas no se parecen, y la
 * que describe lo que efectivamente se va a pagar es la que valua CADA MES a su
 * propio tipo de cambio.
 *
 * El mercado ya publica ese numero: la curva de futuros de ROFEX es,
 * literalmente, a cuanto se puede comprar hoy un dolar de agosto del ano que
 * viene. No hay nada que estimar y no hay nada que cargar a mano.
 *
 * ES EL UNICO CRITERIO, Y ESO NO ES UN DETALLE
 * --------------------------------------------
 * 'comex_tipo_cambio_usd' NO convive con esto: quedo en RETIRADOS y la pantalla
 * de Parametros ya no lo muestra. Dos criterios de valuacion conviviendo es la
 * peor de las opciones posibles, porque el tablero y la pestana muestran dos
 * numeros distintos para el mismo contenedor y nadie tiene donde enterarse de
 * cual es cual. La fila del parametro sigue en la base -este modulo no borra
 * parametros historicos- por si hace falta reconstruir con que numero se
 * proyecto en su momento.
 *
 * DE DONDE SALE
 * -------------
 * De FP_DOLAR_FUTURO_ROFEX, en el servidor XL-APPS. Trae una fila por mes -hoy
 * doce- con su simbolo ('DLR/AGO26'), su mes, su anio y su cotizacion.
 *
 * SE LEE CON EL NOMBRE DE CUATRO PARTES DESDE 'central', y no conectando al
 * servidor 'apps'. Es la misma ruta que ya usa RO_V_DOLAR_OFICIAL_BCRA, que
 * resuelve [XL-APPS].sistemas.DBO.dolar_oficial_bcra por linked server desde
 * central: o sea que el vinculo existe y esta probado en produccion. Conectar a
 * 'apps' dependeria de que DATABASE_APPS apunte justo a 'sistemas', que ningun
 * script de este repo asume, y ademas partiria el modulo en dos conexiones para
 * una sola pantalla.
 *
 * LA TABLA ES DE SOLO LECTURA PARA ESTE MODULO. No se le escribe nunca: la
 * mantiene el proceso que baja la curva del mercado. El unico ajuste que el
 * usuario puede hacer vive en la tabla del modulo -COTIZ_USD_EDIT en
 * RO_T_CASHFLOW_COMEX_CRONO_NAC-, como override por contenedor.
 *
 * LA RESOLUCION ES PURA Y VIVE APARTE DE LA LECTURA
 * -------------------------------------------------
 * curva() toca la base; resolver() no. Todo lo que decide QUE COTIZACION LE
 * TOCA A UNA FILA -la precedencia del override, el mes de la curva, el caso
 * fuera de curva- es una funcion pura de la curva y de la fecha, asi que se
 * prueba entera sin base, que es el mismo criterio de Planilla y de los helpers
 * de ProveedoresCategorias.
 *
 * SIN COTIZACION NO SE VALUA: null Y AVISO, NUNCA CERO
 * ----------------------------------------------------
 * Una fila sin fecha de pago no se puede ubicar en ningun mes, asi que no hay
 * ninguna cotizacion que aplicarle. NO se la convierte con un valor inventado
 * -ni el del primer mes, ni el ultimo, ni un promedio-: queda con importe en
 * pesos nulo y se informa cuantas son y cuanto suman EN DOLARES. Es el mismo
 * criterio que documenta Cotizacion: un cero se leeria como "este contenedor no
 * se paga", que es una afirmacion que nadie hizo.
 *
 * FUERA DE CURVA SE APROXIMA, PERO SE DICE
 * ----------------------------------------
 * La curva llega hasta donde llega -doce meses-, y el horizonte del tablero se
 * configura desde Parametros, asi que puede pedir un mes posterior. Ahi se usa
 * la cotizacion del mes mas cercano que exista y la fila queda MARCADA, con el
 * mes que se le aplico a la vista. Aproximar en silencio seria mostrar un
 * numero que nadie puede explicar; no valuar seria esconder un pago que existe.
 *
 * LOS PAGOS DE LOS PROXIMOS DIAS VAN A DOLAR BCRA, NO A LA CURVA
 * --------------------------------------------------------------
 * Desde feature/comex-dolar-bcra-proximos-pagos. Un pago que sale dentro de los
 * proximos N dias -comex_dias_bcra, Parametros > Compras Exterior, 7 por
 * defecto- se liquida al tipo de cambio de hoy: la curva del mes en curso ya
 * incorpora una devaluacion esperada que para dentro de una semana no ocurre.
 * Es la misma regla que TarjetasSocios aplica a su proximo vencimiento, con
 * una diferencia deliberada: aca la punta es VENDEDORA, que es la que se paga
 * para girarle a un proveedor del exterior. Tarjetas Socios sigue con la
 * compradora.
 *
 * Lo VENCIDO TAMBIEN VA A BCRA: si todavia no se pago, se paga ya. En
 * Proveedores Exterior y Crono Nacionalizacion eso solo cambia lo que muestra
 * la grilla -lo vencido no entra al tablero, ver Comex::importeProyectable()-;
 * en Compras Proyectadas, un pago de este mes con fecha ya pasada si entra, en
 * la columna del mes en curso.
 *
 * El override manual sigue mandando sobre las dos. Y SIN COTIZACION BCRA ESOS
 * IMPORTES QUEDAN EN null, con aviso: valuarlos con la curva en silencio seria
 * aplicar justo el criterio que esta regla dice que no corresponde.
 *
 * La regla se arma con reglaBcra() -pura- y la lectura de los parametros y de
 * la cotizacion vive en bcra(), igual que curva() y resolver().
 */
class DolarFuturo {

    /**
     * La tabla maestra, con nombre de cuatro partes.
     *
     * Va en una constante y no interpolada en cada consulta por el mismo motivo
     * que Cotizacion::VISTA: si el origen se muda, se cambia una linea, y los
     * mensajes de error pueden nombrarlo sin repetirlo.
     */
    const ORIGEN = '[XL-APPS].sistemas.dbo.FP_DOLAR_FUTURO_ROFEX';

    /* Por que se valuo cada fila. Viaja hasta el front, que dibuja una marca
       distinta para cada uno. */
    const ORIGEN_CURVA = 'CURVA';          /* el mes de pago esta en la curva */
    const ORIGEN_APROXIMADA = 'APROXIMADA'; /* se uso el mes mas cercano */
    const ORIGEN_OVERRIDE = 'OVERRIDE';     /* la cargo una persona */
    const ORIGEN_BCRA = 'BCRA';             /* se paga en los proximos dias */

    /* Por que NO se pudo valuar una fila. Tambien viaja al front. */
    const SIN_FECHA = 'SIN_FECHA';
    const SIN_CURVA = 'SIN_CURVA';
    const SIN_BCRA = 'SIN_BCRA';

    /**
     * Los dos parametros de la regla del dolar BCRA, en
     * RO_T_CASHFLOW_PARAMETROS (sql/cashflow_comex_dolar_bcra.sql).
     *
     * LOS VALORES INICIALES ESTAN ESCRITOS DOS VECES -aca y en el script-, como
     * Comex::FECHA_MOV_DESDE_INICIAL: el script los siembra, y el codigo no
     * depende de que se haya corrido.
     */
    const PARAM_DIAS_BCRA = 'comex_dias_bcra';
    const PARAM_ANTIGUEDAD_BCRA = 'comex_bcra_antiguedad_max';
    const DIAS_BCRA_INICIAL = 7;
    const ANTIGUEDAD_BCRA_INICIAL = 3;

    /* Topes de los dos parametros. N mas alla de dos meses ya no es "un pago
       de los proximos dias", y un aviso de antiguedad a partir de un mes no
       avisa nada. */
    const DIAS_BCRA_MAX = 60;
    const ANTIGUEDAD_BCRA_MAX = 30;

    /** @var Conexion */
    private $conn;

    /** @var array|null Cache de la curva: una sola lectura por pedido */
    private $curva = null;

    /** @var string|null Por que no se pudo leer la curva, si no se pudo */
    private $error = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LECTURA DEL ORIGEN
       ==================================================================== */

    /**
     * La curva completa, indexada por mes.
     *
     * NO LANZA SI EL ORIGEN NO SE PUEDE LEER. Devuelve un mapa vacio y deja el
     * motivo en error(). Es deliberado y es el mismo criterio que
     * ProveedoresCategorias::directoresNoExcluidos() con su vista: el servidor
     * vinculado puede no estar disponible en un entorno de desarrollo, y una
     * pestana que ya funcionaba no se cae por eso. Lo que si tiene que pasar es
     * que la pantalla LO DIGA, y para eso esta error().
     *
     * SI UN MES VINIERA REPETIDO gana la fila con la fecha_actualizacion mas
     * nueva. Hoy el origen trae una fila por mes y no deberia pasar, pero
     * quedarse con la primera que devuelva el motor seria elegir al azar entre
     * dos cotizaciones distintas para el mismo mes.
     *
     * @return array Mapa 'YYYY-MM' => ['clave', 'simbolo', 'cotizacion', 'anio',
     *                                  'mes', 'actualizacion']
     */
    public function curva() {
        if ($this->curva !== null) {
            return $this->curva;
        }

        $this->curva = [];

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            $this->error = 'No se pudo conectar a la base central para leer la curva de '
                . 'dólar futuro.';

            return $this->curva;
        }

        $sql = "SELECT simbolo, mes, anio, cotizacion, fecha_actualizacion
                FROM " . self::ORIGEN . "
                ORDER BY anio, mes";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            $this->error = $this->errorSql('No se pudo leer la curva de dólar futuro');

            return $this->curva;
        }

        $actualizacion = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cotiz = self::valor($row['cotizacion']);

            /* Una cotizacion nula o no positiva se trata como AUSENTE, igual
               que en Cotizacion::valor(): multiplicar por cero borraria el
               contenedor entero sin decirlo. */
            if ($cotiz === null) {
                continue;
            }

            $anio = intval($row['anio']);
            $mes = intval($row['mes']);

            if ($mes < 1 || $mes > 12 || $anio < 2000) {
                continue;
            }

            $clave = self::clave($anio, $mes);
            $act = self::fechaHora($row['fecha_actualizacion']);

            if (isset($this->curva[$clave])
                && $act !== null && $actualizacion[$clave] !== null
                && $act < $actualizacion[$clave]) {
                continue;
            }

            $actualizacion[$clave] = $act;

            $this->curva[$clave] = [
                'clave' => $clave,
                'simbolo' => trim((string) $row['simbolo']),
                'cotizacion' => $cotiz,
                'anio' => $anio,
                'mes' => $mes,
                'actualizacion' => $act
            ];
        }

        sqlsrv_free_stmt($stmt);

        ksort($this->curva);

        return $this->curva;
    }

    /** @return bool Si la curva se pudo leer y tiene al menos un mes */
    public function disponible() {
        return !empty($this->curva());
    }

    /**
     * Por que no se pudo leer la curva, o null si se leyo bien.
     *
     * Se llama DESPUES de curva(): es el mismo patron de getAvisos() del resto
     * del modulo, donde el motivo se acumula al leer y la pantalla lo pide
     * despues.
     *
     * @return string|null
     */
    public function error() {
        $this->curva();

        return $this->error;
    }

    /**
     * Hasta que mes llega la curva, para poder decirlo en pantalla.
     *
     * @return string|null 'YYYY-MM', o null si la curva esta vacia
     */
    public function ultimoMes() {
        $curva = $this->curva();

        if (empty($curva)) {
            return null;
        }

        $claves = array_keys($curva);

        return end($claves);
    }

    /**
     * Cuando se actualizo la curva por ultima vez.
     *
     * VIAJA A LA PANTALLA junto con los importes, por el mismo motivo por el
     * que Cotizacion::ultimaHasta() devuelve la fecha con el valor: un importe
     * en pesos que no se puede atar a una cotizacion fechada no se puede
     * auditar contra nada.
     *
     * @return string|null 'Y-m-d H:i:s'
     */
    public function actualizada() {
        $ultima = null;

        foreach ($this->curva() as $m) {
            if ($m['actualizacion'] !== null
                && ($ultima === null || $m['actualizacion'] > $ultima)) {
                $ultima = $m['actualizacion'];
            }
        }

        return $ultima;
    }

    /* ====================================================================
       RESOLUCION
       Puras: deciden que cotizacion le toca a una fila, sin tocar la base.
       ==================================================================== */

    /**
     * Con que dolar se valua una fila, y por que.
     *
     * ES LA REGLA COMPLETA, ESCRITA UNA SOLA VEZ. La usan la pestana -para
     * mostrar los importes en pesos y la marca de cada fila- y el tablero -para
     * convertir antes de agrupar-. Si cada uno resolviera por su cuenta, el
     * mismo contenedor podria valuarse distinto en dos pantallas del mismo
     * modulo y nadie tendria donde notarlo.
     *
     * EL ORDEN DE PRECEDENCIA
     *   1. El override manual de la fila, si tiene uno valido. Manda sobre todo:
     *      lo cargo una persona que sabe algo que la curva no sabe.
     *   2. Si se paga dentro de los proximos N dias -o ya vencio-, el dolar
     *      oficial BCRA vendedor de la regla. Sin esa cotizacion, null con
     *      SIN_BCRA: NO se cae a la curva. Ver el encabezado de la clase.
     *   3. La cotizacion del mes de la fecha efectiva de pago.
     *   4. La del mes mas cercano de la curva, marcada como aproximada.
     *
     * SIN REGLA -$bcra null- NO HAY PASO 2, y todo va con la curva como antes
     * de feature/comex-dolar-bcra-proximos-pagos. Es lo que pasa con N = 0.
     *
     * DEVUELVE SIEMPRE LA MISMA ESTRUCTURA, con 'cotizacion' en null cuando no
     * se puede valuar y 'motivo' diciendo por que. Asi quien consume no tiene
     * que preguntar si el array existe, que es el mismo criterio de
     * ProveedoresCategorias::categoria().
     *
     * CUANDO HAY OVERRIDE TAMBIEN VIAJA EL MES DE LA CURVA que le hubiera
     * tocado. No es adorno: es contra que se compara el valor cargado a mano, y
     * sin eso la pantalla no puede decir de cuanto fue la correccion.
     *
     * Estatica y pura.
     *
     * @param array $curva Mapa 'YYYY-MM' => ['simbolo', 'cotizacion', ...]
     * @param mixed $fechaPago Fecha efectiva de pago ('Y-m-d', DateTime o null)
     * @param mixed $override Cotizacion cargada a mano para esta fila, o null
     * @param array|null $bcra Lo que devolvio reglaBcra(), o null sin regla
     * @return array ['cotizacion', 'simbolo', 'origen', 'mes_pago', 'mes_curva', 'motivo',
     *                'bcra_fecha', 'bcra_punta', 'bcra_dias']
     */
    public static function resolver($curva, $fechaPago, $override = null, $bcra = null) {
        $r = [
            'cotizacion' => null,
            'simbolo' => null,
            'origen' => null,
            'mes_pago' => null,
            'mes_curva' => null,
            'motivo' => null,
            'bcra_fecha' => null,
            'bcra_punta' => null,
            'bcra_dias' => null
        ];

        $curva = is_array($curva) ? $curva : [];
        $mesPago = self::mesDe($fechaPago);
        $ov = self::validarCotizacion($override);

        $r['mes_pago'] = $mesPago;

        /* SIN FECHA NO HAY MES, Y SIN MES NO HAY CURVA. Vale incluso con
           override cargado: el override dice a cuanto valuar, no CUANDO se
           paga, y un importe que no se puede ubicar en el tiempo no entra en
           ninguna columna del eje igual. Ver el encabezado de la clase. */
        if ($mesPago === null) {
            $r['motivo'] = self::SIN_FECHA;

            return $r;
        }

        $delMes = self::mesMasCercano($curva, $mesPago);

        if ($delMes !== null) {
            $r['mes_curva'] = $delMes['clave'];
            $r['simbolo'] = $delMes['simbolo'];
        }

        if ($ov !== null) {
            $r['cotizacion'] = $ov;
            $r['origen'] = self::ORIGEN_OVERRIDE;

            return $r;
        }

        if (self::usaBcra($bcra, $fechaPago)) {
            $r['bcra_dias'] = $bcra['dias'];

            if (empty($bcra['cotizacion'])) {
                $r['motivo'] = self::SIN_BCRA;

                return $r;
            }

            $r['cotizacion'] = floatval($bcra['cotizacion']['valor']);
            $r['origen'] = self::ORIGEN_BCRA;
            $r['bcra_fecha'] = $bcra['cotizacion']['fecha'];
            $r['bcra_punta'] = $bcra['cotizacion']['punta'];

            return $r;
        }

        if ($delMes === null) {
            $r['motivo'] = self::SIN_CURVA;

            return $r;
        }

        $r['cotizacion'] = $delMes['cotizacion'];
        $r['origen'] = ($delMes['clave'] === $mesPago)
            ? self::ORIGEN_CURVA
            : self::ORIGEN_APROXIMADA;

        return $r;
    }

    /**
     * La regla del dolar BCRA, armada: hasta que dia va a BCRA y con que
     * cotizacion.
     *
     * Es lo que recibe resolver(). Va en un array aparte, y no como cuatro
     * argumentos mas, porque se arma UNA vez por pedido -bcra() lee los
     * parametros y la cotizacion- y se aplica a cada fila.
     *
     * LOS PARAMETROS SE NORMALIZAN ACA: un valor que no es un entero valido
     * vale el inicial. Al guardarlos los valida validarDiasBcra(); esto solo
     * pasa sin el script o con un valor cargado a mano en la base.
     *
     * Estatica y pura.
     *
     * @param string $hoy 'Y-m-d'
     * @param mixed $dias N, el parametro comex_dias_bcra
     * @param mixed $antiguedad M, el parametro comex_bcra_antiguedad_max
     * @param array|null $cotizacion Lo que devolvio Cotizacion::ultimaHasta()
     * @param string|null $error Por que no se pudo leer la cotizacion, si no se pudo
     * @return array ['hoy', 'dias', 'hasta', 'antiguedad_max', 'cotizacion', 'error']
     */
    public static function reglaBcra($hoy, $dias, $antiguedad, $cotizacion, $error = null) {
        require_once __DIR__ . '/Horizonte.php';

        $hoy = Horizonte::normalizarFecha($hoy);
        $dias = self::diasBcra($dias);

        return [
            'hoy' => $hoy,
            'dias' => $dias,
            /* Con N = 0 no hay ventana: nadie va a BCRA. hasta en null lo dice
               sin que usaBcra() tenga que preguntar por el cero. */
            'hasta' => ($hoy === null || $dias === 0)
                ? null : date('Y-m-d', strtotime($hoy . ' +' . $dias . ' days')),
            'antiguedad_max' => self::antiguedadBcra($antiguedad),
            'cotizacion' => (is_array($cotizacion) && !empty($cotizacion['valor']))
                ? $cotizacion : null,
            'error' => $error
        ];
    }

    /**
     * Si un pago va a dolar BCRA: hay regla, N es mayor que cero y la fecha es
     * anterior o igual a hoy + N. Lo vencido entra: ver el encabezado.
     *
     * Estatica y pura.
     *
     * @param array|null $regla Lo que devolvio reglaBcra()
     * @param mixed $fechaPago
     * @return bool
     */
    public static function usaBcra($regla, $fechaPago) {
        if (!is_array($regla) || empty($regla['hasta'])) {
            return false;
        }

        require_once __DIR__ . '/Horizonte.php';

        $f = Horizonte::normalizarFecha($fechaPago);

        return $f !== null && $f <= $regla['hasta'];
    }

    /**
     * Si la cotizacion BCRA de la regla tiene mas de M dias corridos.
     *
     * Solo importa con N mayor que cero: si nadie va a BCRA, que tan vieja
     * sea no cambia ningun numero.
     *
     * Estatica y pura.
     *
     * @param array|null $regla
     * @return bool
     */
    public static function bcraVieja($regla) {
        if (!is_array($regla) || empty($regla['hasta']) || empty($regla['cotizacion'])) {
            return false;
        }

        $dias = (strtotime($regla['hoy']) - strtotime($regla['cotizacion']['fecha'])) / 86400;

        return round($dias) > $regla['antiguedad_max'];
    }

    /**
     * Si la curva no esta al dia: su ultima actualizacion es de un dia
     * ANTERIOR al ultimo dia habil. Un sabado, un domingo o un feriado no
     * avisan si la curva es del ultimo habil.
     *
     * SIN FECHA DE ACTUALIZACION NO SE AFIRMA NADA: false. No es lo mismo que
     * una curva vieja, y la curva que no se pudo leer ya tiene su aviso.
     *
     * Estatica y pura.
     *
     * @param string|null $actualizada 'Y-m-d H:i:s', lo que devolvio actualizada()
     * @param string|null $ultimoHabil 'Y-m-d', el ultimo habil anterior o igual a hoy
     * @return bool
     */
    public static function curvaDesactualizada($actualizada, $ultimoHabil) {
        if ($actualizada === null || $ultimoHabil === null) {
            return false;
        }

        return substr((string) $actualizada, 0, 10) < substr((string) $ultimoHabil, 0, 10);
    }

    /**
     * N, el parametro de dias con dolar BCRA, normalizado: un entero de 0 a
     * DIAS_BCRA_MAX, o el inicial.
     *
     * Estatica y pura.
     *
     * @param mixed $valor
     * @return int
     */
    public static function diasBcra($valor) {
        $n = self::enteroEntre($valor, self::DIAS_BCRA_MAX);

        return ($n === null) ? self::DIAS_BCRA_INICIAL : $n;
    }

    /**
     * M, la antiguedad maxima del dolar BCRA, normalizada: un entero de 0 a
     * ANTIGUEDAD_BCRA_MAX, o el inicial.
     *
     * Estatica y pura.
     *
     * @param mixed $valor
     * @return int
     */
    public static function antiguedadBcra($valor) {
        $n = self::enteroEntre($valor, self::ANTIGUEDAD_BCRA_MAX);

        return ($n === null) ? self::ANTIGUEDAD_BCRA_INICIAL : $n;
    }

    /**
     * La validacion de los dos parametros al guardarlos, en el servidor.
     *
     * LANZA, al reves que diasBcra(): aca el valor lo esta tipeando alguien,
     * y caer en silencio al inicial guardaria un numero que nadie pidio.
     *
     * @param string $clave PARAM_DIAS_BCRA o PARAM_ANTIGUEDAD_BCRA
     * @param mixed $valor
     * @return string El entero, como texto
     * @throws Exception Si no es un entero en el rango
     */
    public static function validarParametro($clave, $valor) {
        $esDias = ($clave === self::PARAM_DIAS_BCRA);
        $max = $esDias ? self::DIAS_BCRA_MAX : self::ANTIGUEDAD_BCRA_MAX;
        $n = self::enteroEntre($valor, $max);

        if ($n === null) {
            throw new Exception('"' . ($esDias ? 'Días con dólar BCRA' : 'Antigüedad máxima del '
                . 'dólar BCRA') . '" tiene que ser un número entero de días entre 0 y ' . $max . '.');
        }

        return (string) $n;
    }

    /**
     * Un entero de 0 a $max, o null. Acepta '7' y 7; rechaza '7,5', '-1' y ''.
     *
     * @param mixed $valor
     * @param int $max
     * @return int|null
     */
    private static function enteroEntre($valor, $max) {
        $v = is_int($valor) ? (string) $valor : trim((string) $valor);

        if (!preg_match('/^\d{1,4}$/', $v)) {
            return null;
        }

        $n = intval($v);

        return ($n <= $max) ? $n : null;
    }

    /**
     * El mes de la curva que le toca a un mes de pago: el suyo si esta, y si no
     * el mas cercano que exista.
     *
     * LA DISTANCIA SE MIDE EN MESES, no comparando textos: entre '2026-12' y
     * '2027-01' hay un mes, y una comparacion de strings diria otra cosa.
     *
     * EN CASO DE EMPATE GANA EL MES ANTERIOR. Solo puede pasar con un hueco en
     * el medio de la curva -un mes faltante entre dos que estan-, y ahi la
     * cotizacion mas chica es la mas conservadora para un EGRESO: si hay que
     * equivocarse, mejor no subestimar lo que queda en caja. El caso hacia
     * atras del extremo no existe en la practica, porque la curva arranca en el
     * mes en curso.
     *
     * Estatica y pura.
     *
     * @param array $curva
     * @param string $mes 'YYYY-MM'
     * @return array|null La entrada de la curva, o null si la curva esta vacia
     */
    public static function mesMasCercano($curva, $mes) {
        if (empty($curva)) {
            return null;
        }

        if (isset($curva[$mes])) {
            return $curva[$mes];
        }

        $mejor = null;
        $mejorDist = null;

        foreach ($curva as $clave => $entrada) {
            $dist = abs(self::distanciaMeses($mes, $clave));

            if ($mejorDist === null || $dist < $mejorDist) {
                $mejor = $entrada;
                $mejorDist = $dist;
            }
        }

        return $mejor;
    }

    /**
     * Cuantos meses hay entre dos claves 'YYYY-MM'. Positivo si la segunda es
     * posterior.
     *
     * Estatica y pura.
     *
     * @param string $desde 'YYYY-MM'
     * @param string $hasta 'YYYY-MM'
     * @return int
     */
    public static function distanciaMeses($desde, $hasta) {
        list($a1, $m1) = array_map('intval', explode('-', substr((string) $desde, 0, 7)));
        list($a2, $m2) = array_map('intval', explode('-', substr((string) $hasta, 0, 7)));

        return (($a2 * 12) + $m2) - (($a1 * 12) + $m1);
    }

    /**
     * Valida una cotizacion cargada a mano.
     *
     * DEVUELVE null EN VEZ DE LANZAR porque tiene dos llamadores con
     * necesidades distintas: resolver() la usa para decidir si hay override -y
     * un valor vacio ahi significa "no hay", que no es un error- y el endpoint
     * la usa para rechazar lo que el usuario tipeo. El mensaje de rechazo lo
     * arma el endpoint, que es el que sabe a quien se lo esta diciendo.
     *
     * ACEPTA LA COMA DECIMAL. El usuario tipea en un campo de una pantalla en
     * espanol y '1.234,50' es lo que Excel le muestra todo el dia. Se apoya en
     * Planilla::numero(), que ya resuelve los dos formatos y es lo que usan las
     * importaciones del modulo.
     *
     * Estatica y pura.
     *
     * @param mixed $valor
     * @return float|null null si esta vacio, no es numerico o no es positivo
     */
    public static function validarCotizacion($valor) {
        if ($valor === null || $valor === '' || $valor === false) {
            return null;
        }

        require_once __DIR__ . '/Planilla.php';

        $n = Planilla::numero($valor);

        if ($n === null) {
            return null;
        }

        return ($n > 0) ? floatval($n) : null;
    }

    /**
     * El mes 'YYYY-MM' de una fecha, o null si no hay fecha utilizable.
     *
     * Se apoya en Horizonte::normalizarFecha() para no reimplementar la
     * conversion del DateTime que devuelve sqlsrv.
     *
     * Estatica y pura.
     *
     * @param mixed $fecha
     * @return string|null
     */
    public static function mesDe($fecha) {
        require_once __DIR__ . '/Horizonte.php';

        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-(0[1-9]|1[0-2])/', $f)) {
            return null;
        }

        return substr($f, 0, 7);
    }

    /**
     * Clave de mes, con el mismo formato que usa todo el modulo
     * @param int $anio
     * @param int $mes
     * @return string 'YYYY-MM'
     */
    public static function clave($anio, $mes) {
        return sprintf('%04d-%02d', intval($anio), intval($mes));
    }

    /**
     * Como se nombra en pantalla el dolar que se le aplico a una fila.
     *
     * Vive aca y no en el JS porque lo usan el back -para los avisos del
     * tablero- y el front -para el tooltip de la grilla-, y dos textos se
     * desincronizan. Es el mismo motivo por el que existe
     * Cotizacion::nombrePunta().
     *
     * Estatica y pura.
     *
     * @param array $resuelto Lo que devolvio resolver()
     * @return string
     */
    public static function explicar($resuelto) {
        if (empty($resuelto['origen'])) {
            if ($resuelto['motivo'] === self::SIN_BCRA) {
                return 'Se paga en los próximos ' . $resuelto['bcra_dias'] . ' días, así que va '
                    . 'a dólar BCRA vendedor, pero no hay ninguna cotización publicada: este pago '
                    . 'no se puede valuar. No se usa la curva en su lugar.';
            }

            return ($resuelto['motivo'] === self::SIN_CURVA)
                ? 'No hay curva de dólar futuro cargada, así que este pago no se puede valuar.'
                : 'Sin fecha estimada de pago no hay mes al que pedirle cotización, '
                    . 'así que este pago no se puede valuar.';
        }

        $simbolo = empty($resuelto['simbolo']) ? '' : ' (' . $resuelto['simbolo'] . ')';

        if ($resuelto['origen'] === self::ORIGEN_OVERRIDE) {
            return 'Cotización cargada a mano para este contenedor. Manda sobre la curva'
                . ($resuelto['mes_curva'] === null
                    ? '.'
                    : ', que para ' . self::mesCorto($resuelto['mes_curva']) . $simbolo
                      . ' dice otra cosa.');
        }

        /* EL TEXTO EMPIEZA POR EL DOLAR Y SU FECHA, que es lo que se compara
           contra lo publicado por el BCRA; el porque va despues. */
        if ($resuelto['origen'] === self::ORIGEN_BCRA) {
            require_once __DIR__ . '/Cotizacion.php';

            return 'Dólar BCRA ' . Cotizacion::nombrePunta($resuelto['bcra_punta']) . ' del '
                . self::fechaCorta($resuelto['bcra_fecha']) . ': $ '
                . number_format($resuelto['cotizacion'], 2, ',', '.') . '. Se paga en los '
                . 'próximos ' . $resuelto['bcra_dias'] . ' días —o ya venció—, así que va a dólar '
                . 'de hoy y no a la curva de futuros.';
        }

        if ($resuelto['origen'] === self::ORIGEN_APROXIMADA) {
            return 'El mes de pago (' . self::mesCorto($resuelto['mes_pago']) . ') no está en la '
                . 'curva, así que se usó el mes más cercano que hay: '
                . self::mesCorto($resuelto['mes_curva']) . $simbolo . '.';
        }

        return 'Dólar futuro ROFEX de ' . self::mesCorto($resuelto['mes_curva']) . $simbolo . '.';
    }

    /**
     * El aviso de que la cotizacion BCRA tiene mas de M dias, o null.
     *
     * Atencion y no critico: la cotizacion se USA igual -es la ultima que
     * hay-, y lo que se pide es mirar si el origen se sigue cargando.
     *
     * Estatica y pura.
     *
     * @param array|null $regla
     * @return array|null ['nivel', 'texto', 'seccion']
     */
    public static function avisoBcraVieja($regla) {
        require_once __DIR__ . '/Aviso.php';

        if (!self::bcraVieja($regla)) {
            return null;
        }

        return Aviso::nuevo(Aviso::WARNING, 'La última cotización del dólar BCRA es del '
            . self::fechaCorta($regla['cotizacion']['fecha']) . '; se usa ésa para los pagos de '
            . 'los próximos ' . $regla['dias'] . ' días.');
    }

    /**
     * El aviso de que la curva no esta al dia, o null.
     *
     * Atencion: los numeros salen igual, con la ultima curva que hay. No
     * reemplaza al de "no se pudo leer la curva", que es critico y lo emite
     * Comex::avisoSinCurva().
     *
     * Estatica y pura.
     *
     * @param string|null $actualizada 'Y-m-d H:i:s'
     * @param string|null $ultimoHabil 'Y-m-d'
     * @param array|null $regla La del BCRA, para decir desde cuando rige la curva
     * @return array|null ['nivel', 'texto', 'seccion']
     */
    public static function avisoCurvaDesactualizada($actualizada, $ultimoHabil, $regla = null) {
        require_once __DIR__ . '/Aviso.php';

        if (!self::curvaDesactualizada($actualizada, $ultimoHabil)) {
            return null;
        }

        $dias = is_array($regla) ? intval($regla['dias']) : 0;

        return Aviso::nuevo(Aviso::WARNING, 'La curva de dólar futuro ROFEX se actualizó por '
            . 'última vez el ' . self::fechaCorta($actualizada) . ' '
            . substr((string) $actualizada, 11, 5) . '. '
            . ($dias > 0
                ? 'Los pagos posteriores a los próximos ' . $dias . ' días se valúan con esa curva.'
                : 'Los pagos se valúan con esa curva.'));
    }

    /**
     * Como se nombra un mes en pantalla: '2026-11' -> 'Nov-26'.
     *
     * Estatica y pura.
     *
     * @param string|null $mes 'YYYY-MM'
     * @return string
     */
    public static function mesCorto($mes) {
        $nombres = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                    'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $m = intval(substr((string) $mes, 5, 2));

        if ($m < 1 || $m > 12) {
            return (string) $mes;
        }

        return $nombres[$m - 1] . '-' . substr((string) $mes, 2, 2);
    }

    /** 'Y-m-d' (o 'Y-m-d H:i:s') -> 'dd/mm/aaaa' */
    private static function fechaCorta($fecha) {
        $f = substr((string) $fecha, 0, 10);

        return substr($f, 8, 2) . '/' . substr($f, 5, 2) . '/' . substr($f, 0, 4);
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Normaliza una cotizacion leida del origen. Ver Cotizacion::valor() */
    private static function valor($v) {
        if ($v === null) {
            return null;
        }

        $n = floatval($v);

        return ($n > 0) ? $n : null;
    }

    /** Un DATETIME de SQL Server como texto, o null */
    private static function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors().
     *
     * NOMBRA EL ORIGEN, igual que Cotizacion::errorSql(): el aviso es lo unico
     * que le dice a alguien que la tabla que falta esta en otro servidor y que
     * el problema puede ser el linked server y no esta aplicacion.
     *
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::ORIGEN . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
