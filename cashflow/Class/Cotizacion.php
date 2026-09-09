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
 * DE DONDE SALE
 * -------------
 * De la vista RO_V_DOLAR_OFICIAL_BCRA, en la base 'central'. Tiene UNA fila por
 * anio/mes con la cotizacion del ULTIMO DIA CARGADO de ese mes, o sea el tipo
 * de cambio de cierre. Para el mes en curso eso da la ultima cotizacion
 * disponible, que es exactamente lo que se necesita: no hace falta un
 * tratamiento aparte.
 *
 * CADA MES SE VALUA A SU PROPIO TIPO DE CAMBIO
 * --------------------------------------------
 * Quien convierta una serie de meses tiene que valuar CADA MES a SU tipo de
 * cambio de cierre y sumar recien despues. Dividir el acumulado en pesos por un
 * unico tipo de cambio es otra cuenta: es reexpresar toda la serie a moneda de
 * hoy. Con inflacion, las dos cuentas no se parecen, y la primera es la que
 * describe lo que efectivamente se vendio en cada mes.
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

    /** @var Conexion */
    private $conn;

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

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

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
     * @param string $contexto Descripcion de la operacion que fallo
     * @return string Mensaje de error completo
     */
    private function errorSql($contexto) {
        $errors = sqlsrv_errors();
        $errorMsg = $contexto . ' (' . self::VISTA . '): ';

        if ($errors) {
            foreach ($errors as $error) {
                $errorMsg .= $error['message'] . ' ';
            }
        }

        return $errorMsg;
    }
}
