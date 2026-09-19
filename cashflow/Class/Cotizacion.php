<?php

/**
 * Cotizacion
 * Punto de acceso al tipo de cambio para todo el cashflow.
 *
 * POR QUE ES UNA CLASE APARTE
 * ---------------------------
 * El tipo de cambio no es un dato de Ventas: lo va a necesitar cualquier bloque
 * del cashflow que muestre importes en dolares. Vive aca para que exista UNA
 * sola lectura del origen y un solo criterio, en vez de un metodo privado por
 * pestana que despues se desincroniza.
 *
 * DE DONDE SALE, Y POR QUE SON DOS VISTAS
 * ---------------------------------------
 * De dos vistas de la base 'central', que responden dos preguntas distintas:
 *
 *   RO_V_DOLAR_OFICIAL_BCRA         una fila por anio/mes, con la cotizacion
 *   (mapaMensual, delMes)           del ultimo dia cargado de ese mes: el tipo
 *                                   de cambio de CIERRE.
 *                                   -> "cuanto valio el dolar en ese mes"
 *
 *   RO_V_DOLAR_OFICIAL_BCRA_DIARIO  la serie diaria completa, sin colapsar.
 *   (ultimaHasta)                   -> "cuanto vale hoy lo que tengo"
 *
 * LAS DOS SON CORRECTAS Y NINGUNA REEMPLAZA A LA OTRA. Ventas valua mes a mes,
 * porque lo que describe es lo que se vendio en cada mes. Otros Ingresos valua
 * un saldo que esta en una cuenta hoy, y para eso el cierre del mes no sirve:
 * el 15 de septiembre no existe todavia el cierre de septiembre, y usar el del
 * mes de la carga puede valuar con una cotizacion de hace semanas sin que nada
 * lo diga.
 *
 * CADA MES SE VALUA A SU PROPIO TIPO DE CAMBIO
 * --------------------------------------------
 * Quien convierta una serie de meses tiene que valuar CADA MES a SU tipo de
 * cambio de cierre y sumar recien despues. Dividir el acumulado en pesos por un
 * unico tipo de cambio es otra cuenta: es reexpresar toda la serie a moneda de
 * hoy. Con inflacion, las dos cuentas no se parecen, y la primera es la que
 * describe lo que efectivamente se vendio en cada mes.
 *
 * LA COTIZACION VIAJA CON SU FECHA Y CON SU PUNTA
 * -----------------------------------------------
 * ultimaHasta() devuelve el valor, el dia del que salio Y con que punta se
 * leyo. Las dos cosas son parte del dato: un importe en pesos que no se puede
 * atar a una cotizacion fechada no se puede auditar contra nada, y en este pais
 * la diferencia entre el dolar de hace tres semanas y el de hoy no es un
 * detalle.
 *
 * LA PUNTA LA ELIGE EL LLAMADOR, Y EL DEFAULT ES COMPRADOR
 * --------------------------------------------------------
 * El origen publica las dos: COMPRADOR (lo que el banco paga por un dolar) y
 * VENDEDOR (lo que cobra). Casi todo el cashflow -Ventas, Saldos,
 * Exportaciones Tasky, Comex- valua con comprador, y por eso ese es el default:
 * agregar el parametro no movio ni una pantalla.
 *
 * Dolares Cuenta Comitente es la unica que pide VENDEDOR, y es deliberado. La
 * consecuencia hay que tenerla presente: ESA PANTALLA NO CIERRA CONTRA LAS
 * OTRAS, a proposito. Por eso el valor viaja con su punta y la grilla la
 * muestra: un importe valuado a vendedor que no diga que es a vendedor se
 * compara contra el BCRA comprador y parece estar mal.
 *
 * mapaMensual() y delMes() NO tienen el parametro. No es un olvido: hoy nadie
 * les pide otra punta, y el metodo que se puede llamar con un argumento que
 * nadie usa es el que un dia alguien llama sin entender que cambia. Agregarlo
 * cuando haga falta es esta misma linea.
 *
 * UN MES SIN COTIZACION DEVUELVE null, NO CERO
 * --------------------------------------------
 * Un cero se leeria como "el dolar valia cero" y arrastraria importes absurdos
 * -o una division por cero-. Con null el front puede mostrar un guion, que es
 * la diferencia entre "no hay dato" y "el dato es cero".
 *
 * SI LA VISTA NO EXISTE
 * ---------------------
 * Los metodos lanzan Exception. El llamador la envuelve y sigue sin la parte en
 * dolares, mismo criterio que ya usa Ventas::getAnalisisVentas() con el
 * historico diario: una pantalla que ya funcionaba no se cae por un origen
 * nuevo que todavia no se creo en el entorno.
 */
class Cotizacion {

    /** Vista de cierre mensual del dolar oficial BCRA, en la base 'central' */
    const VISTA = 'RO_V_DOLAR_OFICIAL_BCRA';

    /** Vista DIARIA del mismo origen, sin colapsar por mes */
    const VISTA_DIARIA = 'RO_V_DOLAR_OFICIAL_BCRA_DIARIO';

    /**
     * Las dos puntas, con el nombre de su columna en las vistas.
     *
     * Son el nombre de la columna y no un codigo aparte: asi no hay un mapa que
     * mantener y lo que se intercala en el SQL sale de esta lista y de ningun
     * otro lado. Ver punta().
     */
    const COMPRADOR = 'TCC';
    const VENDEDOR = 'TCV';

    /** Como se nombra cada punta de cara al usuario */
    const NOMBRES = [self::COMPRADOR => 'comprador', self::VENDEDOR => 'vendedor'];

    /** @var Conexion */
    private $conn;

    /** @var array Cache de ultimaHasta(), indexado por fecha pedida */
    private $ultimas = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Tipo de cambio de cierre de cada mes de un rango, punta COMPRADORA.
     *
     * Los meses sin cotizacion NO estan en el mapa: la clave ausente es lo que
     * distingue "no hay dato" de "el dato es cero".
     *
     * La punta no es elegible acá: ninguno de sus llamadores -Ventas,
     * SaldosProvider- valua con otra. Ver el encabezado de la clase.
     *
     * @param string $desde Mes inicial 'YYYY-MM' (tambien acepta 'YYYY-MM-DD')
     * @param string $hasta Mes final 'YYYY-MM' (tambien acepta 'YYYY-MM-DD')
     * @return array Mapa 'YYYY-MM' => float
     */
    public function mapaMensual($desde, $hasta) {
        $rango = self::rango($desde, $hasta);
        $cid = $this->conectar();

        // El anio y el mes se derivan de la FECHA en vez de leer las columnas
        // Anio/Mes de la vista: en el origen esos nombres llevan tilde y el SQL
        // de este modulo es ASCII. Como la vista tiene una sola fila por mes, la
        // derivacion da exactamente lo mismo.
        $sql = "SELECT YEAR(Fecha) AS ANIO, MONTH(Fecha) AS MES, TCC
                FROM " . self::VISTA . "
                WHERE Fecha BETWEEN ? AND ?
                ORDER BY Fecha";

        $stmt = sqlsrv_query($cid, $sql, [$rango['desde'], $rango['hasta']]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el tipo de cambio'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tcc = self::valor($row['TCC']);

            if ($tcc === null) {
                continue;
            }

            $mapa[self::clave($row['ANIO'], $row['MES'])] = $tcc;
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Tipo de cambio de cierre de un mes puntual, punta COMPRADORA.
     *
     * Misma nota que mapaMensual(): sus llamadores -Exportaciones Tasky,
     * Ingresos- valuan con comprador y la punta no es elegible acá.
     *
     * @param int $anio Anio de cuatro digitos
     * @param int $mes Mes 1..12
     * @return float|null Cotizacion de cierre, o null si el mes no tiene dato
     */
    public function delMes($anio, $mes) {
        $anio = intval($anio);
        $mes = intval($mes);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mes invalido: ' . $mes);
        }

        $cid = $this->conectar();

        $sql = "SELECT TCC
                FROM " . self::VISTA . "
                WHERE YEAR(Fecha) = ? AND MONTH(Fecha) = ?";

        $stmt = sqlsrv_query($cid, $sql, [$anio, $mes]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el tipo de cambio'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? self::valor($row['TCC']) : null;
    }

    /**
     * La ultima cotizacion conocida a una fecha: la mas reciente cuyo dia sea
     * ANTERIOR O IGUAL al pedido, junto con el dia del que salio y la punta con
     * la que se leyo.
     *
     * ES EL CRITERIO PARA VALUAR ALGO QUE ESTA EN UNA CUENTA. El cierre del mes
     * no sirve para eso: el mes en curso no lo tiene todavia, y el de un mes
     * viejo valuaria con una cotizacion de hace semanas sin decirlo.
     *
     * DEVUELVE LA FECHA JUNTO CON EL VALOR, y no es un extra: sin ella el
     * importe en pesos que muestra el tablero no se puede explicar. Un sabado
     * devuelve la cotizacion del viernes CON la fecha del viernes; no se
     * rellenan los dias sin dato ni se los hace pasar por el dia pedido.
     *
     * DEVUELVE null SI NO HAY NINGUNA COTIZACION ANTERIOR, que es distinto de
     * devolver cero: no se asume ningun tipo de cambio. Es el mismo criterio de
     * mapaMensual() y el de todo el modulo.
     *
     * UNA CONSULTA POR FECHA, CACHEADA. Las fechas distintas que hay que valuar
     * son pocas -son las cargas de una pantalla de carga manual- y cada una
     * necesita su propia busqueda hacia atras. Traerse las 5.800 filas de la
     * serie diaria para resolverlo en PHP seria mas lento y mucho mas fragil.
     * El cache se indexa por PUNTA Y FECHA: la misma fecha leida con las dos
     * puntas son dos cotizaciones distintas.
     *
     * LA CLAVE DEL VALOR SE LLAMA 'valor' Y NO 'tcc'. Con la punta elegible,
     * 'tcc' nombraria al comprador en un array que puede traer al vendedor: el
     * llamador leeria una clave que dice una cosa y contiene otra. El nombre de
     * la punta va en 'punta', aparte, para que el importe se pueda explicar.
     *
     * @param string $fecha 'Y-m-d' (tambien acepta un DateTime o 'Y-m-d H:i:s')
     * @param string $punta self::COMPRADOR (default) o self::VENDEDOR
     * @return array|null ['fecha' => 'Y-m-d', 'valor' => float, 'punta' => 'TCC'|'TCV'],
     *                    o null si no hay ninguna cotizacion anterior
     */
    public function ultimaHasta($fecha, $punta = self::COMPRADOR) {
        $f = self::dia($fecha);
        $col = self::punta($punta);
        $cache = $col . '|' . $f;

        if (array_key_exists($cache, $this->ultimas)) {
            return $this->ultimas[$cache];
        }

        $cid = $this->conectar();

        // $col sale de punta(), que sólo devuelve una de las dos constantes: no
        // es entrada del usuario y no hay nada que parametrizar.
        $sql = "SELECT TOP 1 Fecha, " . $col . " AS COTIZACION
                FROM " . self::VISTA_DIARIA . "
                WHERE Fecha <= ?
                ORDER BY Fecha DESC";

        $stmt = sqlsrv_query($cid, $sql, [$f]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el tipo de cambio diario',
                self::VISTA_DIARIA));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $cot = $row ? self::valor($row['COTIZACION']) : null;

        $this->ultimas[$cache] = ($cot === null) ? null : [
            'fecha' => ($row['Fecha'] instanceof DateTime)
                ? $row['Fecha']->format('Y-m-d')
                : substr((string) $row['Fecha'], 0, 10),
            'valor' => $cot,
            'punta' => $col
        ];

        return $this->ultimas[$cache];
    }

    /**
     * ultimaHasta() para MUCHAS fechas de una vez: el tablero necesita la
     * cotizacion de cada columna del eje -cuarenta fechas- para saber a cuanto
     * vende los dolares de la cobertura automatica en cada una, y cuarenta
     * consultas por carga es demasiado.
     *
     * COMO LO RESUELVE EN DOS CONSULTAS. La "ultima hasta" de la fecha mas
     * chica sale de ultimaHasta(), una busqueda hacia atras. De ahi en adelante
     * alcanza con las filas que hay ENTRE la fecha mas chica y la mas grande
     * (entreFechas()), que en el caso normal -columnas futuras- son cero: nadie
     * carga el dolar de la semana que viene. Se avanza sobre las fechas en
     * orden llevando la ultima fila vista. Cada resultado queda en el mismo
     * cache que ultimaHasta(), asi que preguntar despues por una de esas fechas
     * no vuelve a consultar.
     *
     * Lo que devuelve para cada fecha es EXACTAMENTE lo que devolveria
     * ultimaHasta() para esa fecha: valor, fecha de la que salio y punta, o
     * null si no hay nada anterior. No hay dos criterios.
     *
     * @param array $fechas 'Y-m-d', en cualquier orden y con repetidas
     * @param string $punta self::COMPRADOR (default) o self::VENDEDOR
     * @return array Mapa fecha => (lo mismo que ultimaHasta())
     */
    public function ultimasHasta($fechas, $punta = self::COMPRADOR) {
        $col = self::punta($punta);
        $lista = [];

        foreach ($fechas as $f) {
            $lista[self::dia($f)] = true;
        }

        if (empty($lista)) {
            return [];
        }

        $lista = array_keys($lista);
        sort($lista);

        $min = $lista[0];
        $max = $lista[count($lista) - 1];

        $actual = $this->ultimaHasta($min, $punta);
        $entre = ($max > $min) ? $this->entreFechas($min, $max, $punta) : [];
        $p = 0;
        $out = [];

        foreach ($lista as $f) {
            while ($p < count($entre) && $entre[$p]['fecha'] <= $f) {
                $actual = $entre[$p];
                $p++;
            }

            $out[$f] = $actual;
            $this->ultimas[$col . '|' . $f] = $actual;
        }

        return $out;
    }

    /**
     * Las cotizaciones diarias con fecha en (desde, hasta], ascendentes, en la
     * forma de ultimaHasta(). Es la segunda consulta de ultimasHasta(); va
     * separada para que una prueba pueda reemplazarla sin base.
     *
     * @param string $desde 'Y-m-d', exclusivo
     * @param string $hasta 'Y-m-d', inclusivo
     * @param string $punta
     * @return array Lista de ['fecha', 'valor', 'punta']
     */
    protected function entreFechas($desde, $hasta, $punta) {
        $col = self::punta($punta);
        $cid = $this->conectar();

        $sql = "SELECT Fecha, " . $col . " AS COTIZACION
                FROM " . self::VISTA_DIARIA . "
                WHERE Fecha > ? AND Fecha <= ?
                ORDER BY Fecha";

        $stmt = sqlsrv_query($cid, $sql, [self::dia($desde), self::dia($hasta)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el tipo de cambio diario',
                self::VISTA_DIARIA));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cot = self::valor($row['COTIZACION']);

            if ($cot === null) {
                continue;
            }

            $v[] = [
                'fecha' => ($row['Fecha'] instanceof DateTime)
                    ? $row['Fecha']->format('Y-m-d')
                    : substr((string) $row['Fecha'], 0, 10),
                'valor' => $cot,
                'punta' => $col
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * Valida y normaliza un dia a 'YYYY-MM-DD'
     *
     * @param mixed $valor DateTime, 'Y-m-d' o 'Y-m-d H:i:s'
     * @return string 'YYYY-MM-DD'
     */
    public static function dia($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d');
        }

        $d = substr((string) $valor, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            throw new Exception("Fecha invalida: '$valor'. Se espera 'YYYY-MM-DD'");
        }

        list($a, $m, $dd) = array_map('intval', explode('-', $d));

        if (!checkdate($m, $dd, $a)) {
            throw new Exception("Fecha inexistente en el calendario: '$d'");
        }

        return $d;
    }

    /**
     * Valida una punta y devuelve el nombre de su columna.
     *
     * Lanza en vez de caer en el default: una punta mal escrita valuaria con
     * comprador en silencio, y el sintoma seria un importe en pesos apenas mas
     * chico que nadie va a poder explicar.
     *
     * @param string $punta self::COMPRADOR o self::VENDEDOR
     * @return string Nombre de la columna en las vistas
     */
    public static function punta($punta) {
        if (!isset(self::NOMBRES[$punta])) {
            throw new Exception("Punta de cotizacion invalida: '$punta'. Se espera "
                . self::COMPRADOR . ' o ' . self::VENDEDOR);
        }

        return $punta;
    }

    /**
     * Como se nombra una punta de cara al usuario: 'comprador' o 'vendedor'.
     *
     * Vive aca y no en la pantalla porque lo usan el back -para los avisos- y
     * el front -para la grilla-, y dos listas se desincronizan.
     *
     * @param string $punta
     * @return string
     */
    public static function nombrePunta($punta) {
        return self::NOMBRES[self::punta($punta)];
    }

    /**
     * Clave de mes que usan los mapas del modulo
     * @param int $anio Anio de cuatro digitos
     * @param int $mes Mes 1..12
     * @return string 'YYYY-MM'
     */
    public static function clave($anio, $mes) {
        return sprintf('%04d-%02d', intval($anio), intval($mes));
    }

    /**
     * Lleva un rango de meses a fechas: del dia 1 del mes inicial al ULTIMO dia
     * del mes final. Sin expandir el final, un rango pedido por mes se cortaria
     * en el dia 1 y se perderia justamente la cotizacion de cierre.
     *
     * @param string $desde Mes inicial 'YYYY-MM' o 'YYYY-MM-DD'
     * @param string $hasta Mes final 'YYYY-MM' o 'YYYY-MM-DD'
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rango($desde, $hasta) {
        $ini = self::mes($desde);
        $fin = self::mes($hasta);

        if ($fin < $ini) {
            throw new Exception("Rango de meses invalido: $ini es posterior a $fin");
        }

        return [
            'desde' => $ini . '-01',
            'hasta' => (new DateTime($fin . '-01'))
                ->modify('last day of this month')
                ->format('Y-m-d')
        ];
    }

    /**
     * Valida y normaliza un mes a 'YYYY-MM'
     * @param string $valor Mes 'YYYY-MM' o fecha 'YYYY-MM-DD'
     * @return string 'YYYY-MM'
     */
    private static function mes($valor) {
        $mes = substr((string) $valor, 0, 7);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            throw new Exception("Mes invalido: '$valor'. Se espera 'YYYY-MM'");
        }

        return $mes;
    }

    /**
     * Normaliza una cotizacion leida del origen.
     *
     * Un TCC nulo o no positivo se trata como AUSENTE: multiplicar por cero
     * borraria el mes y dividir por cero directamente revienta. Es el mismo
     * criterio de null que el resto del modulo.
     *
     * @param mixed $tcc Valor crudo de la columna
     * @return float|null
     */
    private static function valor($tcc) {
        if ($tcc === null) {
            return null;
        }

        $v = floatval($tcc);

        return ($v > 0) ? $v : null;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        return $cid;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors()
     *
     * El origen se nombra porque hay DOS vistas y el mensaje tiene que decir
     * cual falta: el aviso es lo unico que le dice a alguien que script correr.
     *
     * @param string $contexto Descripcion de la operacion que fallo
     * @param string|null $vista Origen que fallo; por defecto el mensual
     * @return string Mensaje de error completo
     */
    private function errorSql($contexto, $vista = null) {
        $errors = sqlsrv_errors();
        $errorMsg = $contexto . ' (' . ($vista === null ? self::VISTA : $vista) . '): ';

        if ($errors) {
            foreach ($errors as $error) {
                $errorMsg .= $error['message'] . ' ';
            }
        }

        return $errorMsg;
    }
}
