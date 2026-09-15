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
 * LA COTIZACION VIAJA CON SU FECHA
 * --------------------------------
 * ultimaHasta() devuelve el valor Y el dia del que salio. La fecha es parte del
 * dato: un importe en pesos que no se puede atar a una cotizacion fechada no se
 * puede auditar contra nada, y en este pais la diferencia entre el dolar de
 * hace tres semanas y el de hoy no es un detalle.
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

    /** @var Conexion */
    private $conn;

    /** @var array Cache de ultimaHasta(), indexado por fecha pedida */
    private $ultimas = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Tipo de cambio de cierre de cada mes de un rango.
     *
     * Los meses sin cotizacion NO estan en el mapa: la clave ausente es lo que
     * distingue "no hay dato" de "el dato es cero".
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
     * Tipo de cambio de cierre de un mes puntual.
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
     * ANTERIOR O IGUAL al pedido, junto con el dia del que salio.
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
     *
     * @param string $fecha 'Y-m-d' (tambien acepta un DateTime o 'Y-m-d H:i:s')
     * @return array|null ['fecha' => 'Y-m-d', 'tcc' => float], o null si no hay
     */
    public function ultimaHasta($fecha) {
        $f = self::dia($fecha);

        if (array_key_exists($f, $this->ultimas)) {
            return $this->ultimas[$f];
        }

        $cid = $this->conectar();

        $sql = "SELECT TOP 1 Fecha, TCC
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

        $tcc = $row ? self::valor($row['TCC']) : null;

        $this->ultimas[$f] = ($tcc === null) ? null : [
            'fecha' => ($row['Fecha'] instanceof DateTime)
                ? $row['Fecha']->format('Y-m-d')
                : substr((string) $row['Fecha'], 0, 10),
            'tcc' => $tcc
        ];

        return $this->ultimas[$f];
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
