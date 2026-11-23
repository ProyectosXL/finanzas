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
 * UN PERIODO ABIERTO SE LEE SOLO PARA LOS FIJOS. Cerrado es cuando el periodo
 * tiene fila en RO_T_DETALLE_ALQUILERES_ESTADO; la app lo reabre borrando esa
 * fila. detalleCerrados() devuelve los meses cerrados tal cual, y como no se
 * guarda copia, un periodo que se reabre y se vuelve a cerrar se lee como
 * quedo.
 *
 * De un periodo ABIERTO solo sirven los fijos (1, 2, 3 y 8): son importes que
 * administracion carga de la factura o del contrato, y valen lo que se ve
 * (detalleAbiertos()). LOS VARIABLES NO: con el periodo abierto la base guarda
 * el 6 y el 7 SIN restar el VMA (y el 16 y el 17 sin restar el 9) -la resta la
 * hace el JS de la app al mostrar, totalizar()-, asi que lo guardado no es lo
 * que se ve. Tampoco el 9, que la app recalcula como VMA x %9: el cashflow lo
 * calcula igual, con el VMA de la app. Las expensas (11) siguen con su tasa
 * desde el ultimo cerrado. Que hacer con un fijo abierto lo decide
 * AlquileresCalculo::fijos().
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

    /** Los fijos que se leen de un periodo abierto: alquiler, complementario, baulera y VMA */
    const FIJOS_ABIERTOS = [1, 2, 3, 8];

    /**
     * Los FIJOS cargados en los periodos ABIERTOS de unos meses: los que no
     * estan cerrados, con importe mayor que 0 (un fijo sin fila o en 0 se sigue
     * estimando). Ni variables, ni el 9, ni las expensas: ver el encabezado.
     *
     * El filtro por periodo va en SQL con la lista exacta, como
     * detalleCerrados(); que el mes no este cerrado se mira en PHP.
     *
     * @param string[] $meses 'Y-m' los meses a mirar (los posteriores al ultimo cerrado)
     * @param string[] $cerrados Los de mesesCerrados()
     * @return array NRO_SUCURS => 'Y-m' => ID_CA => float
     */
    public function detalleAbiertos($meses, $cerrados) {
        $cer = array_flip($cerrados);
        $periodos = [];

        foreach ($meses as $mes) {
            if (!isset($cer[$mes])) {
                $periodos[] = self::periodoDeMes($mes);
            }
        }

        if (empty($periodos)) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($periodos), '?'));
        $ids = implode(',', self::FIJOS_ABIERTOS);
        $sql = "SELECT NRO_SUCURS, PERIODO, ID_CA, CAST(IMPORTE AS FLOAT) AS IMPORTE
                FROM dbo.RO_T_DETALLE_ALQUILERES
                WHERE LTRIM(RTRIM(PERIODO)) IN ($marcas) AND ID_CA IN ($ids) AND IMPORTE > 0";

        $out = [];

        foreach ($this->filas($sql, $periodos, 'Error al leer los fijos de los periodos abiertos') as $r) {
            $mes = self::mesDePeriodo($r['PERIODO']);

            if ($mes === null || $r['NRO_SUCURS'] === null || isset($cer[$mes])) {
                continue;
            }

            $nro = (int) $r['NRO_SUCURS'];
            $id = (int) $r['ID_CA'];
            $out[$nro][$mes][$id] = ($out[$nro][$mes][$id] ?? 0.0) + (float) $r['IMPORTE'];
        }

        return $out;
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
     * Los contratos de cada local, de RO_T_CONTRATOS_ALQUILERES (la tabla de
     * contratos de la app): la vigencia de cada uno, VIG_DESDE a VIG_HASTA. Un
     * local tiene varios -los viejos, el vigente, a veces uno corto en el
     * medio- y cuentan todos: la vigencia de un local es la union de sus
     * contratos. No es una tabla de TABLAS: si no esta, vacio, y quien la usa
     * avisa.
     *
     * @return array NRO_SUCURS => [['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']], por desde
     */
    public function contratos() {
        $sql = "SELECT NRO_SUCURS, VIG_DESDE, VIG_HASTA
                FROM dbo.RO_T_CONTRATOS_ALQUILERES
                WHERE NRO_SUCURS IS NOT NULL AND VIG_DESDE IS NOT NULL AND VIG_HASTA IS NOT NULL
                ORDER BY NRO_SUCURS, VIG_DESDE, VIG_HASTA";
        $out = [];

        foreach ($this->filas($sql, [], 'Error al leer los contratos de los locales') as $r) {
            $d = ($r['VIG_DESDE'] instanceof DateTime) ? $r['VIG_DESDE']->format('Y-m-d') : substr((string) $r['VIG_DESDE'], 0, 10);
            $h = ($r['VIG_HASTA'] instanceof DateTime) ? $r['VIG_HASTA']->format('Y-m-d') : substr((string) $r['VIG_HASTA'], 0, 10);

            if ($d <= $h) {
                $out[(int) $r['NRO_SUCURS']][] = ['desde' => $d, 'hasta' => $h];
            }
        }

        return $out;
    }

    /** El concepto de la app que guarda cada importe del contrato (guardarContratoAlquiler()) */
    const CONTRATO_ID_LLAVE = 4;
    const CONTRATO_ID_COMISIONES = 5;
    const CONTRATO_ID_FPC = 18;

    /**
     * Los contratos de cada local CON SUS IMPORTES: valor llave (concepto 4,
     * columna IMPORTE), comisiones (5, IMPORTE_2) y FPC lanzamiento (18,
     * IMPORTE_3). Es lo que lee Llaves y Renovaciones; contratos() sigue
     * devolviendo solo la vigencia, que es lo unico que Alquileres necesita, y
     * no cambia.
     *
     * POR QUE ACA Y NO EN UNA CLASE DE LLAVES: esta es la UNICA clase que lee
     * la app de alquileres, y una prueba fija que no escribe. Un segundo lector
     * de la misma tabla tendria que repetir esa garantia, y el dia que la app
     * cambie una columna habria dos lugares que arreglar.
     *
     * EL CONCEPTO SE MIRA, NO SE SUPONE. La app guarda cada importe al lado de
     * su ID_CA (4, 5 y 18, hoy en las 52 filas). Si una fila trajera otro
     * concepto en esa columna, ese importe queda en null: un numero que no se
     * sabe que es no se proyecta como llave.
     *
     * ESTADO NO NULL ES UNA BAJA. La app no escribe la columna, pero la vista
     * RO_V_CONTRATOS_VALOR_LLAVE -la que usa Costo de Ocupacion- filtra
     * ESTADO IS NULL. Se sigue el mismo criterio: un contrato que la vista no
     * considera, aca tampoco.
     *
     * Un importe vacio es null (sin dato), no 0. LLAVE = 1 se devuelve tal
     * cual: es la marca de "tiene llave, monto desconocido" que administracion
     * carga para que Costo de Ocupacion calcule el devengado, y quien la
     * interpreta es LlavesCalculo.
     *
     * @return array NRO_SUCURS => [['id', 'desde', 'hasta', 'llave', 'comisiones', 'fpc']], por desde e id
     */
    public function contratosConImportes() {
        $sql = "SELECT ID, NRO_SUCURS, VIG_DESDE, VIG_HASTA, ID_CA, IMPORTE, ID_CA_2, IMPORTE_2, ID_CA_3, IMPORTE_3
                FROM dbo.RO_T_CONTRATOS_ALQUILERES
                WHERE NRO_SUCURS IS NOT NULL AND VIG_DESDE IS NOT NULL AND VIG_HASTA IS NOT NULL
                  AND ESTADO IS NULL
                ORDER BY NRO_SUCURS, VIG_DESDE, ID";
        $out = [];

        foreach ($this->filas($sql, [], 'Error al leer los contratos de los locales con sus importes') as $r) {
            $fila = self::contratoDeFila($r);

            if ($fila !== null) {
                $out[(int) $r['NRO_SUCURS']][] = $fila;
            }
        }

        return $out;
    }

    /**
     * Una fila de RO_T_CONTRATOS_ALQUILERES como la devuelve
     * contratosConImportes(). Pura: es la parte que se prueba sin base.
     * Null si la vigencia esta al reves.
     *
     * @param array $r
     * @return array|null
     */
    public static function contratoDeFila($r) {
        $dia = function ($v) {
            return ($v instanceof DateTime) ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
        };
        $importe = function ($id, $esperado, $valor) {
            if ($valor === null || trim((string) $valor) === '' || (int) trim((string) $id) !== $esperado) {
                return null;
            }

            return round((float) $valor, 2);
        };

        $d = $dia($r['VIG_DESDE']);
        $h = $dia($r['VIG_HASTA']);

        if ($d > $h) {
            return null;
        }

        return [
            'id' => (int) $r['ID'],
            'desde' => $d,
            'hasta' => $h,
            'llave' => $importe($r['ID_CA'] ?? null, self::CONTRATO_ID_LLAVE, $r['IMPORTE'] ?? null),
            'comisiones' => $importe($r['ID_CA_2'] ?? null, self::CONTRATO_ID_COMISIONES, $r['IMPORTE_2'] ?? null),
            'fpc' => $importe($r['ID_CA_3'] ?? null, self::CONTRATO_ID_FPC, $r['IMPORTE_3'] ?? null)
        ];
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
