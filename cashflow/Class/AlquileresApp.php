<?php

/**
 * AlquileresApp
 * La lectura de la app de alquileres de administracion
 * (administracion/impuestos/alquileres): locales, periodos cerrados, importes
 * por concepto, porcentajes de cada local y su venta.
 *
 * SOLO LEE. Las tablas son de la app y el cashflow no escribe nada en ellas:
 * ningun metodo de esta clase arma un INSERT, un UPDATE ni un DELETE, y lo fija
 * una prueba. Viven en 'central', la misma conexion que el resto del cashflow.
 *
 * SOLO ARGENTINA. La app tiene un entorno Uruguay (la base 'uy', elegido por
 * sesion); el cashflow no lo mira.
 *
 * LOS DOS FORMATOS DE PERIODO DE LA APP
 * -------------------------------------
 *   RO_T_DETALLE_ALQUILERES.PERIODO, RO_T_DETALLE_ALQUILERES_ESTADO.periodo y
 *   RO_V_VENTAS_BRUTAS_IE.PERIODO  -> 'M-YYYY', sin cero a la izquierda ('9-2026')
 *   RO_T_RENTABILIDAD_BRUTA.FECHA  -> DATE, el ultimo dia del mes
 * Aca todo se pasa a 'Y-m', el formato del resto del cashflow, con mesDePeriodo().
 *
 * LA APP FILTRA LA VENTA CON LIKE '%M-YYYY%', y en enero y febrero eso levanta
 * tambien noviembre y diciembre ('%1-2026%' encuentra '11-2026'). Aca se
 * compara exacto, en PHP, despues de convertir. Ver "Pendientes conocidos" en
 * README-alquileres.md.
 *
 * UN PERIODO ABIERTO NUNCA SE LEE. Con el periodo abierto la base guarda los
 * conceptos 6 y 7 SIN restar el valor minimo (la resta la hace el JS de la app
 * al mostrar), asi que lo guardado no es lo que se ve. Cerrado es cuando el
 * periodo tiene fila en RO_T_DETALLE_ALQUILERES_ESTADO; la app lo reabre
 * borrando esa fila. detalleCerrados() solo devuelve meses cerrados, y como no
 * se guarda copia, un periodo que se reabre y se vuelve a cerrar se lee como
 * quedo.
 *
 * LOS CRUCES SE HACEN EN PHP: cada metodo lee UNA tabla (o la vista de la app)
 * y devuelve mapas por local y mes. Quien combina es AlquileresCalculo, que es
 * puro.
 */
class AlquileresApp {

    /** Las tablas y la vista de la app que el cashflow lee */
    const TABLAS = [
        'RO_T_DETALLE_ALQUILERES',
        'RO_T_DETALLE_ALQUILERES_ESTADO',
        'RO_T_PORC_CONCEPTOS_ALQUILERES',
        'RO_T_RENTABILIDAD_BRUTA',
        'RO_V_VENTAS_BRUTAS_IE',
        'RO_V_SUCURSALES_ALQUILERES'
    ];

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       FUNCIONES PURAS
       ==================================================================== */

    /**
     * 'M-YYYY' (o 'MM-YYYY') de la app a 'Y-m'. Null si no tiene esa forma:
     * un periodo mal escrito no se adivina.
     *
     * @param string $periodo
     * @return string|null
     */
    public static function mesDePeriodo($periodo) {
        if (!preg_match('/^\s*(\d{1,2})-(\d{4})\s*$/', (string) $periodo, $m)) {
            return null;
        }

        $mes = (int) $m[1];

        if ($mes < 1 || $mes > 12) {
            return null;
        }

        return $m[2] . '-' . str_pad((string) $mes, 2, '0', STR_PAD_LEFT);
    }

    /**
     * 'Y-m' a 'M-YYYY', el formato de la app.
     *
     * @param string $mes
     * @return string
     */
    public static function periodoDeMes($mes) {
        return ((int) substr($mes, 5, 2)) . '-' . substr($mes, 0, 4);
    }

    /**
     * Un porcentaje de RO_T_PORC_CONCEPTOS_ALQUILERES, con el MISMO parseo que
     * la app: floatval(str_replace(',', '.', ...)). La columna es FLOAT hoy,
     * pero la app la trata como texto con coma decimal y el cashflow no puede
     * leer un numero distinto del que la app usa para el mismo local.
     *
     * Null si viene vacio: un local sin porcentaje no proyecta ese concepto, y
     * eso es distinto de un porcentaje en 0.
     *
     * @param mixed $valor
     * @return float|null
     */
    public static function porcentaje($valor) {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        return floatval(str_replace(',', '.', $texto));
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Las tablas de la app que no existen. Vacio = estan todas.
     *
     * @return string[]
     */
    public function tablasFaltantes() {
        $faltan = [];

        foreach (self::TABLAS as $tabla) {
            $stmt = sqlsrv_query($this->conectar(), "SELECT OBJECT_ID(?) AS ID", ['dbo.' . $tabla]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al verificar las tablas de la app de alquileres'));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            if ($row === null || $row['ID'] === null) {
                $faltan[] = $tabla;
            }
        }

        return $faltan;
    }

    /**
     * El universo de locales: los mismos que la app de alquileres en su modo
     * historico (getSucursalesAlquileres() sin periodo, en
     * administracion/impuestos/alquileres/Class/Sucursal.php): la vista con
     * VISIBLE_FINAL = 1. La vista no filtra las ocultas a proposito, para que
     * los parametros de la app puedan revertirlas, y el filtro lo pone quien la
     * lee; aca se copia ese filtro.
     *
     * HABILITADO es el de SUCURSALES_LAKERS (la fila que la vista eligio). Con
     * 0 el local esta cerrado en Lakers: no proyecta, pero sus meses cerrados
     * se siguen viendo. Ver AlquileresCalculo::estadoLocal().
     *
     * @return array NRO_SUCURS => ['nro' => int, 'nombre' => string, 'habilitado' => bool]
     */
    public function locales() {
        $sql = "SELECT V.NRO_SUCURSAL, V.DESC_SUCURSAL, V.HABILITADO
                FROM dbo.RO_V_SUCURSALES_ALQUILERES V
                WHERE V.VISIBLE_FINAL = 1
                ORDER BY V.ORDEN, V.DESC_SUCURSAL";

        $locales = [];

        foreach ($this->filas($sql, [], 'Error al leer los locales de la app de alquileres') as $r) {
            $nro = (int) $r['NRO_SUCURSAL'];

            if (!isset($locales[$nro])) {
                $locales[$nro] = [
                    'nro' => $nro,
                    'nombre' => trim((string) $r['DESC_SUCURSAL']),
                    'habilitado' => ((int) $r['HABILITADO']) === 1
                ];
            }
        }

        return $locales;
    }

    /**
     * Los meses cerrados en la app, en 'Y-m', ordenados.
     *
     * @return string[]
     */
    public function mesesCerrados() {
        $meses = [];

        foreach ($this->filas("SELECT periodo FROM dbo.RO_T_DETALLE_ALQUILERES_ESTADO", [],
                              'Error al leer el estado de los periodos de alquileres') as $r) {
            $mes = self::mesDePeriodo($r['periodo']);

            if ($mes !== null) {
                $meses[$mes] = true;
            }
        }

        $meses = array_keys($meses);
        sort($meses);

        return $meses;
    }

    /**
     * Los importes por concepto de los meses CERRADOS desde $desde ('Y-m').
     * Los abiertos no se leen (ver el encabezado).
     *
     * El filtro por periodo va en SQL con la lista exacta de periodos cerrados,
     * en el formato de la app: no es un cruce, es no traer de mas.
     *
     * @param string[] $cerrados Los de mesesCerrados()
     * @param string $desde 'Y-m'
     * @return array NRO_SUCURS => 'Y-m' => ID_CA => float
     */
    public function detalleCerrados($cerrados, $desde) {
        $periodos = [];

        foreach ($cerrados as $mes) {
            if ($mes >= $desde) {
                $periodos[] = self::periodoDeMes($mes);
            }
        }

        if (empty($periodos)) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($periodos), '?'));
        $sql = "SELECT NRO_SUCURS, PERIODO, ID_CA, CAST(IMPORTE AS FLOAT) AS IMPORTE
                FROM dbo.RO_T_DETALLE_ALQUILERES
                WHERE LTRIM(RTRIM(PERIODO)) IN ($marcas)";

        $detalle = [];

        foreach ($this->filas($sql, $periodos, 'Error al leer el detalle de alquileres') as $r) {
            $mes = self::mesDePeriodo($r['PERIODO']);

            if ($mes === null || $r['NRO_SUCURS'] === null) {
                continue;
            }

            $nro = (int) $r['NRO_SUCURS'];
            $id = (int) $r['ID_CA'];
            $detalle[$nro][$mes][$id] = ($detalle[$nro][$mes][$id] ?? 0.0) + (float) $r['IMPORTE'];
        }

        return $detalle;
    }

    /**
     * Los porcentajes de cada local, con el parseo de la app.
     *
     * Si la tabla tuviera dos filas para el mismo local y concepto, gana la
     * ultima que se lee, que es lo mismo que hace la app (recorre todas y pisa).
     *
     * @return array NRO_SUCURS => ID_CA => float
     */
    public function porcentajes() {
        $sql = "SELECT NRO_SUCURS, ID_CA, PORCENTAJE
                FROM dbo.RO_T_PORC_CONCEPTOS_ALQUILERES
                ORDER BY ID_PA";

        $pct = [];

        foreach ($this->filas($sql, [], 'Error al leer los porcentajes de alquileres') as $r) {
            $valor = self::porcentaje($r['PORCENTAJE']);

            if ($valor !== null && $r['NRO_SUCURS'] !== null) {
                $pct[(int) $r['NRO_SUCURS']][(int) $r['ID_CA']] = $valor;
            }
        }

        return $pct;
    }

    /**
     * La venta NETA (sin IVA) por local y mes, de RO_T_RENTABILIDAD_BRUTA: la
     * misma que usa la app para los conceptos 7 y 17.
     *
     * SE SUMA POR LOCAL Y MES: hay meses con dos filas para el mismo local.
     * Las filas sin local (ajustes del canal) y las de los canales que no son
     * locales (100 franquicias, 101 mayoristas, 30x ecommerce) no son de
     * ningun local de la app y se descartan solas, porque no estan en el
     * universo; aca solo se sacan las sin local.
     *
     * @param string $desde 'Y-m'
     * @return array NRO_SUCURS => 'Y-m' => float
     */
    public function ventasNetas($desde) {
        $sql = "SELECT NRO_SUCURS, YEAR(FECHA) AS ANIO, MONTH(FECHA) AS MES, SUM(VENTA) AS VENTA
                FROM dbo.RO_T_RENTABILIDAD_BRUTA
                WHERE NRO_SUCURS IS NOT NULL
                  AND FECHA >= ?
                GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)";

        $ventas = [];

        foreach ($this->filas($sql, [$desde . '-01'], 'Error al leer la venta neta de los locales') as $r) {
            $mes = $r['ANIO'] . '-' . str_pad((string) $r['MES'], 2, '0', STR_PAD_LEFT);
            $ventas[(int) $r['NRO_SUCURS']][$mes] = (float) $r['VENTA'];
        }

        return $ventas;
    }

    /**
     * La venta BRUTA (con IVA) por local y mes, de RO_V_VENTAS_BRUTAS_IE: la
     * misma que usa la app para los conceptos 6 y 16. El periodo se compara
     * exacto en PHP (ver el encabezado sobre el LIKE de la app).
     *
     * @param string $desde 'Y-m'
     * @return array NRO_SUCURS => 'Y-m' => float
     */
    public function ventasBrutas($desde) {
        $sql = "SELECT PERIODO, NRO_SUCURSAL, SUM(IMPORTE) AS IMPORTE
                FROM dbo.RO_V_VENTAS_BRUTAS_IE
                WHERE NRO_SUCURSAL IS NOT NULL
                GROUP BY PERIODO, NRO_SUCURSAL";

        $ventas = [];

        foreach ($this->filas($sql, [], 'Error al leer la venta bruta de los locales') as $r) {
            $mes = self::mesDePeriodo($r['PERIODO']);

            if ($mes === null || $mes < $desde) {
                continue;
            }

            $nro = (int) $r['NRO_SUCURSAL'];
            $ventas[$nro][$mes] = ($ventas[$nro][$mes] ?? 0.0) + (float) $r['IMPORTE'];
        }

        return $ventas;
    }

    /* ====================================================================
       AUXILIARES
       ==================================================================== */

    /**
     * Corre un SELECT y devuelve todas las filas.
     *
     * @return array[]
     */
    private function filas($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /** @return resource Conexion a 'central' */
    private function conectar() {
        if ($this->cid === null) {
            $cid = $this->conn->conectar('central');

            if (!$cid) {
                throw new Exception('No se pudo conectar a la base de datos central');
            }

            $this->cid = $cid;
        }

        return $this->cid;
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $msg = $contexto . ': ';

        foreach ((array) sqlsrv_errors() as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
