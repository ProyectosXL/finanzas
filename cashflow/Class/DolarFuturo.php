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

    /* Por que NO se pudo valuar una fila. Tambien viaja al front. */
    const SIN_FECHA = 'SIN_FECHA';
    const SIN_CURVA = 'SIN_CURVA';

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
     *   2. La cotizacion del mes de la fecha efectiva de pago.
     *   3. La del mes mas cercano de la curva, marcada como aproximada.
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
     * @return array ['cotizacion', 'simbolo', 'origen', 'mes_pago', 'mes_curva', 'motivo']
     */
    public static function resolver($curva, $fechaPago, $override = null) {
        $r = [
            'cotizacion' => null,
            'simbolo' => null,
            'origen' => null,
            'mes_pago' => null,
            'mes_curva' => null,
            'motivo' => null
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
                    : ', que para ' . $resuelto['mes_curva'] . $simbolo . ' dice otra cosa.');
        }

        if ($resuelto['origen'] === self::ORIGEN_APROXIMADA) {
            return 'El mes de pago (' . $resuelto['mes_pago'] . ') no está en la curva, '
                . 'así que se usó el mes más cercano que hay: ' . $resuelto['mes_curva']
                . $simbolo . '.';
        }

        return 'Dólar futuro ROFEX de ' . $resuelto['mes_curva'] . $simbolo . '.';
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
