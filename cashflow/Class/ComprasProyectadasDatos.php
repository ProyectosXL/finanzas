<?php

require_once __DIR__ . '/ComprasProyectadas.php';
require_once __DIR__ . '/Comex.php';

/**
 * ComprasProyectadasDatos
 * Las TRES lecturas de la proyeccion de compras del exterior, y nada mas.
 *
 * Todo lo que decide donde cae un importe vive en ComprasProyectadas, que es
 * pura. Esta clase solo va a buscar los datos y los devuelve con la forma que
 * esa otra espera. La division no es estetica: es lo que permite probar la
 * cuenta entera sin SQL Server, que es la unica forma de fijar los casos que en
 * la base real no se pueden producir a voluntad.
 *
 * LAS TRES FUENTES, Y SON DE TRES DUENIOS DISTINTOS
 * -------------------------------------------------
 *
 *   1. EL PRESUPUESTO OFICIAL        conexion 'power'  (POWER_BI_CONTROL)
 *      RO_V_COMPRA_PROYECTADA_VIGENTE, la vista de la app de compras. Es la
 *      unica de las tres que puede NO EXISTIR, y su ausencia cambia numeros:
 *      sin ella no hay nada que proyectar y la fila va en cero. Ver
 *      avisoSinVista().
 *
 *   2. LA HISTORIA DE RECEPCIONES    conexion 'central' (Tango)
 *      CPA35 + STA20. De aca sale la cuota: cuanto entra en cada mes
 *      calendario. Es historia, asi que no cambia de un dia para el otro.
 *
 *   3. LO YA COMPRADO                conexion 'central' (maestro de Comex)
 *      RO_T_IMPORTACIONES_ENCABEZADO, el MISMO padron que arma la pestana
 *      Proveedores Exterior, con el mismo pendiente. Ver cargado().
 *
 * ESTA CLASE NO ESCRIBE NADA. Ni en las tablas de compras, ni en las de
 * Comercio Exterior, ni en Tango. Las tres son de otras aplicaciones y las tres
 * se leen y se dejan como estan. Hay una prueba que lo verifica buscando
 * INSERT, UPDATE y DELETE sobre este archivo.
 */
class ComprasProyectadasDatos {

    /** La vista de la app de compras, en POWER_BI_CONTROL */
    const VISTA_PRESUPUESTO = 'RO_V_COMPRA_PROYECTADA_VIGENTE';

    /** Las tres tablas de las que sale la vista, para poder contrastarla */
    const TABLA_CABECERA = 'RO_T_HISTORIAL_COMPRAS_PROYECTADAS_CABECERA';
    const TABLA_DETALLE = 'RO_T_HISTORIAL_COMPRAS_PROYECTADAS_PRESUPUESTO';
    const TABLA_TRAMO = 'RO_T_HISTORIAL_COMPRAS_PROYECTADAS_TRAMO';

    /** El maestro de Comercio Exterior */
    const TABLA_MAESTRO = 'RO_T_IMPORTACIONES_ENCABEZADO';

    /** El detalle: un contenedor CON detalle ya cerro y sale del padron */
    const TABLA_DETALLE_COMEX = 'RO_T_IMPORTACIONES_DETALLE';

    /** El encabezado de compras de Tango, de donde sale la fecha de emision */
    const TABLA_OC = 'CPA35';

    /** Los movimientos de stock de Tango, de donde salen las recepciones */
    const TABLA_MOV = 'STA20';

    /**
     * Los proveedores del exterior empiezan con Z en Tango.
     *
     * ES EL CRITERIO DE IMPORTACION, y no hay otro: no existe un flag de
     * "importado" en CPA35. Verificado contra la base: las 1.628 ordenes que lo
     * cumplen tienen sus 29.764 movimientos de stock con TCOMP_IN_S = 'RP' y
     * TIPO_MOV = 'E', o sea que TODOS son recepciones. No hay ningun otro tipo
     * de comprobante del que haya que distinguirlas.
     */
    const PREFIJO_PROVEEDOR_EXTERIOR = 'Z';

    /** El comprobante de recepcion en STA20: remito de proveedor */
    const COMPROBANTE_RECEPCION = 'RP';

    /** @var Conexion */
    private $conn;

    /** @var Comex Para reutilizar la regla del saldo pendiente */
    private $comex;

    /** @var bool|null Cache de si la vista existe */
    private $vista = null;

    /** @var string Ultimo error de lectura del presupuesto */
    private $errorPresupuesto = '';

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->comex = new Comex;
    }

    /* ====================================================================
       1. EL PRESUPUESTO OFICIAL
       ==================================================================== */

    /**
     * Si la vista de la app de compras existe y se puede leer.
     *
     * SE PREGUNTA ANTES DE NOMBRARLA, igual que Comex::tienePagosComex() con la
     * tabla de pagos: nombrar una vista ausente rompe la consulta entera con
     * "Invalid object name", que es un error que no se ve hasta que alguien
     * abre la pantalla. Y aca hay ademas otra base y otro servidor de por
     * medio, asi que la conexion misma puede no estar.
     *
     * @return bool
     */
    public function tieneVista() {
        if ($this->vista !== null) {
            return $this->vista;
        }

        $this->vista = false;

        $cid = @$this->conn->conectar('power');

        if (!$cid) {
            $this->errorPresupuesto = 'No se pudo conectar a POWER_BI_CONTROL';

            return false;
        }

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::VISTA_PRESUPUESTO . "', 'V') AS V");

        if ($stmt === false) {
            $this->errorPresupuesto = 'No se pudo verificar la vista del presupuesto';

            return false;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->vista = ($row && $row['V'] !== null);

        if (!$this->vista) {
            $this->errorPresupuesto = 'La vista ' . self::VISTA_PRESUPUESTO . ' no existe';
        }

        return $this->vista;
    }

    /**
     * El aviso de que no hay presupuesto que leer, o cadena vacia.
     *
     * VA PRIMERO Y DICE QUE SE PROYECTA DE MENOS. Es la misma clase de
     * degradacion que la de la tabla de pagos de Comex: no apaga un boton,
     * cambia los numeros. Sin presupuesto la fila entera va en cero, y una fila
     * en cero en un tablero de egresos se lee como "no hay que pagar nada", que
     * es lo contrario de lo que pasa.
     *
     * @return string
     */
    public function avisoSinVista() {
        if ($this->tieneVista()) {
            return '';
        }

        return 'No se puede leer el presupuesto de compras (' . $this->errorPresupuesto
            . '). Las filas de compras proyectadas van en CERO: se está proyectando de '
            . 'MENOS, no de más. La vista la crea el bloque 4 de '
            . 'presupuestos/sql/05_baja_logica_versiones.sql en el repo de compras, contra '
            . 'POWER_BI_CONTROL.';
    }

    /**
     * Las versiones oficiales vigentes, una por temporada, con el FOB del tramo
     * objetivo ya valorizado.
     *
     * SOLO EL TRAMO OBJETIVO (es_objetivo = 1). Los demas tramos de una version
     * son CONTROL: cada temporada la aporta su propia version oficial, y sumar
     * el tramo intermedio de una version contaria esa temporada dos veces. Es
     * la regla de la app de compras y esta escrita en su README.
     *
     *     FOB U$S = compra (unidades) x costo_prom
     *
     * COSTO_PROM ES EL FOB UNITARIO EN DOLARES, verificado contra la base: el
     * presupuesto implica 7,24 U$S por unidad y las ordenes de compra reales
     * dan entre 5,66 y 7,71 segun el anio. La vista lo guarda copiado al
     * momento de calcular la version, porque FP_T_COSTOS_PARAMETROS se pisa en
     * el lugar y sin la copia la version dejaria de ser reproducible.
     *
     * LAS FILAS SIN COSTO SE CUENTAN APARTE Y NO SE MULTIPLICAN POR CERO. En la
     * base hay dos filas por version con costo_prom en NULL y sin
     * categoria_padre; son residuos de redondeo, pero un NULL tratado como cero
     * es una afirmacion ("esa mercaderia no cuesta nada") que nadie hizo.
     *
     * INC_FOB NO SE USA PARA LA NACIONALIZACION, y esa es una decision medida.
     * Toma dos valores en toda la version -0 y 50- y da un 41 % ponderado,
     * contra el 89 % que dan los contenedores reales (cociente
     * nacionalizacion/FOB entre 0,71 y 1,06 sobre 59 contenedores). Con
     * inc_fob, el 30 % del FOB proyectaria nacionalizacion CERO. Se lee igual y
     * se devuelve para poder mostrar el contraste en la pantalla, pero el
     * porcentaje que se aplica es un parametro del cashflow.
     *
     * @param string $pais
     * @return array Mapa codigo de temporada => version
     */
    public function versionesOficiales($pais = 'argentina') {
        if (!$this->tieneVista()) {
            return [];
        }

        $cid = @$this->conn->conectar('power');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT
                    temporada_codigo,
                    MAX(id_version)                    id_version,
                    MAX(nombre_presupuesto)            nombre,
                    MAX(solapa)                        solapa,
                    MAX(fecha_calculo)                 fecha_calculo,
                    MAX(tramos_estado)                 tramos_estado,
                    MIN(temporada_desde)               temporada_desde,
                    MAX(temporada_hasta)               temporada_hasta,
                    COUNT(*)                           filas,
                    SUM(CASE WHEN costo_prom IS NULL THEN 1 ELSE 0 END) filas_sin_costo,
                    SUM(ISNULL(compra, 0))             unidades,
                    SUM(CASE WHEN costo_prom IS NULL THEN ISNULL(compra, 0) ELSE 0 END)
                                                       unidades_sin_costo,
                    SUM(ISNULL(compra, 0) * ISNULL(costo_prom, 0))  fob_usd,
                    SUM(ISNULL(compra, 0) * ISNULL(costo_prom, 0) * ISNULL(inc_fob, 0) / 100.0)
                                                       nac_segun_inc_fob,
                    SUM(ISNULL(compra_deficit_cobertura, 0)) deficit_cobertura
                FROM " . self::VISTA_PRESUPUESTO . "
                WHERE es_objetivo = 1 AND pais = ?
                GROUP BY temporada_codigo";

        $stmt = sqlsrv_query($cid, $sql, [$pais]);

        if ($stmt === false) {
            $this->errorPresupuesto = 'No se pudo leer ' . self::VISTA_PRESUPUESTO;

            return [];
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fob = floatval($row['fob_usd']);
            $codigo = trim((string) $row['temporada_codigo']);

            $out[$codigo] = [
                'temporada' => $codigo,
                'id_version' => intval($row['id_version']),
                'nombre' => (string) $row['nombre'],
                'solapa' => (string) $row['solapa'],
                'fecha_calculo' => self::aFecha($row['fecha_calculo']),
                'tramos_estado' => (string) $row['tramos_estado'],
                'desde' => self::aFecha($row['temporada_desde']),
                'hasta' => self::aFecha($row['temporada_hasta']),
                'filas' => intval($row['filas']),
                'filas_sin_costo' => intval($row['filas_sin_costo']),
                'unidades' => floatval($row['unidades']),
                'unidades_sin_costo' => floatval($row['unidades_sin_costo']),
                'fob_usd' => $fob,
                'nac_segun_inc_fob' => floatval($row['nac_segun_inc_fob']),
                'deficit_cobertura' => floatval($row['deficit_cobertura']),
                /* El inc_fob ponderado de la version, SOLO para poder mostrar
                   el contraste contra el parametro del cashflow. No se aplica. */
                'inc_fob_pct' => ($fob > 0)
                    ? floatval($row['nac_segun_inc_fob']) / $fob * 100.0 : 0.0
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /**
     * El detalle por rubro y categoria de una version, para auditar.
     *
     * @param int $idVersion
     * @return array
     */
    public function detalleVersion($idVersion) {
        if (!$this->tieneVista()) {
            return [];
        }

        $cid = @$this->conn->conectar('power');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT rubro, categoria_padre, compra, costo_prom, inc_fob, vcosto,
                       ISNULL(compra, 0) * ISNULL(costo_prom, 0) fob_usd,
                       compra_deficit_cobertura
                FROM " . self::VISTA_PRESUPUESTO . "
                WHERE es_objetivo = 1 AND id_version = ?
                ORDER BY ISNULL(compra, 0) * ISNULL(costo_prom, 0) DESC";

        $stmt = sqlsrv_query($cid, $sql, [intval($idVersion)]);

        if ($stmt === false) {
            return [];
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'rubro' => (string) $row['rubro'],
                'categoria' => (string) $row['categoria_padre'],
                'compra' => ($row['compra'] === null) ? null : floatval($row['compra']),
                'costo_prom' => ($row['costo_prom'] === null) ? null : floatval($row['costo_prom']),
                'inc_fob' => ($row['inc_fob'] === null) ? null : floatval($row['inc_fob']),
                'vcosto' => ($row['vcosto'] === null) ? null : floatval($row['vcosto']),
                'fob_usd' => floatval($row['fob_usd']),
                'deficit' => floatval($row['compra_deficit_cobertura'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /**
     * Cuantas filas del tramo objetivo deja afuera la vista respecto de sus
     * tablas de origen.
     *
     * POR QUE EXISTE ESTE CONTROL. La vista tiene hoy un filtro propio que el
     * script del repo de compras NO tiene -deja afuera los rubros de cuero, por
     * decision del area- y desde el cashflow ese filtro es INVISIBLE: la vista
     * devuelve 55 filas y nada dice que en las tablas hay 72. Son U$S 2,2
     * millones de FOB entre las dos temporadas.
     *
     * El dia que alguien vuelva a correr el script, el filtro se borra -usa
     * CREATE OR ALTER- y el tablero empieza a proyectar esos millones sin que
     * nadie lo decida. Al reves tambien: un filtro nuevo bajaria la proyeccion
     * en silencio. Este control no opina sobre el filtro; solo hace que la
     * diferencia se vea.
     *
     * @return array ['filas_vista','filas_tablas','fob_vista','fob_tablas'] o []
     */
    public function contrasteVista($pais = 'argentina') {
        if (!$this->tieneVista()) {
            return [];
        }

        $cid = @$this->conn->conectar('power');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT
                    (SELECT COUNT(*) FROM " . self::VISTA_PRESUPUESTO . "
                     WHERE es_objetivo = 1 AND pais = ?) filas_vista,
                    (SELECT SUM(ISNULL(compra,0) * ISNULL(costo_prom,0))
                     FROM " . self::VISTA_PRESUPUESTO . "
                     WHERE es_objetivo = 1 AND pais = ?) fob_vista,
                    (SELECT COUNT(*)
                     FROM " . self::TABLA_CABECERA . " c
                     JOIN " . self::TABLA_DETALLE . " d ON d.id_cabecera = c.id
                     JOIN " . self::TABLA_TRAMO . " t ON t.id_detalle = d.id
                     WHERE c.es_oficial = 1 AND c.eliminada = 0
                       AND t.es_objetivo = 1 AND c.pais = ?) filas_tablas,
                    (SELECT SUM(ISNULL(t.compra,0) * ISNULL(d.costo_prom,0))
                     FROM " . self::TABLA_CABECERA . " c
                     JOIN " . self::TABLA_DETALLE . " d ON d.id_cabecera = c.id
                     JOIN " . self::TABLA_TRAMO . " t ON t.id_detalle = d.id
                     WHERE c.es_oficial = 1 AND c.eliminada = 0
                       AND t.es_objetivo = 1 AND c.pais = ?) fob_tablas";

        $stmt = sqlsrv_query($cid, $sql, [$pais, $pais, $pais, $pais]);

        if ($stmt === false) {
            return [];
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            return [];
        }

        return [
            'filas_vista' => intval($row['filas_vista']),
            'filas_tablas' => intval($row['filas_tablas']),
            'fob_vista' => floatval($row['fob_vista']),
            'fob_tablas' => floatval($row['fob_tablas'])
        ];
    }

    /**
     * El aviso de que la vista filtra parte del presupuesto, o cadena vacia.
     *
     * NO DICE QUE ESTE MAL. El filtro puede ser deliberado -hoy lo es- y en ese
     * caso lo que hace falta es que el numero quede a la vista para poder
     * explicar por que el tablero no coincide con el presupuesto que muestra la
     * app de compras.
     *
     * @param array $contraste Lo que devuelve contrasteVista()
     * @return string
     */
    public static function avisoContraste($contraste) {
        if (empty($contraste) || $contraste['filas_tablas'] <= 0) {
            return '';
        }

        $filas = $contraste['filas_tablas'] - $contraste['filas_vista'];
        $fob = $contraste['fob_tablas'] - $contraste['fob_vista'];

        if ($filas <= 0 && abs($fob) < 1) {
            return '';
        }

        return 'La vista del presupuesto deja afuera ' . $filas . ' fila'
            . ($filas === 1 ? '' : 's') . ' del tramo objetivo, por U$S '
            . number_format($fob, 2, ',', '.') . ' de FOB. Es un filtro propio de la vista, '
            . 'no del cashflow: hoy excluye los rubros de cuero. Lo que se proyecta es lo '
            . 'que la vista devuelve.';
    }

    /* ====================================================================
       2. LA HISTORIA DE RECEPCIONES, DE LA QUE SALE LA CUOTA
       ==================================================================== */

    /**
     * Las recepciones de importacion agrupadas por anio y mes calendario, con
     * el importe FOB PRORRATEADO.
     *
     * EL EJE ES FECHA_MOV, que es cuando la mercaderia entro efectivamente en
     * casa central. Es el hecho que la cuota mide: en que mes del anio llega lo
     * que se compro.
     *
     * POR QUE SE PRORRATEA, Y NO SE REPITE EL TOTAL DE LA ORDEN. Una orden de
     * compra se recibe en varias tandas: medido contra la base, 55 ordenes de
     * 1.317 tienen mas de una fecha de movimiento, y una llega a tener cinco.
     * Si cada fecha arrastrara el TOTAL_EXT entero de su orden -que es lo que
     * pasa al agregar FECHA_MOV a un GROUP BY sin repartir- el importe se
     * contaria una vez por tanda: sobre 2023-2025 son 18,50 % de mas, y no
     * repartido parejo sino concentrado en las ordenes grandes, que son
     * justamente las que mas mueven la cuota. Mayo pasaria de pesar 7,78 % a
     * 12,99 %.
     *
     * Asi que el importe de cada movimiento es
     *
     *     TOTAL_EXT de la orden  x  cantidad del movimiento / cantidad total
     *
     * y la suma sobre todos los movimientos vuelve a dar el TOTAL_EXT de la
     * orden, una sola vez.
     *
     * TOTAL_EXT ESTA EN DOLARES. Verificado: el cociente TOTAL_CTE/TOTAL_EXT
     * sigue la cotizacion del dolar anio por anio (263 en 2023, 1.220 en 2025).
     *
     * SE DEVUELVEN TAMBIEN LAS UNIDADES, para poder repartir por cantidad en
     * vez de por importe: es un parametro del modulo y las dos cuotas difieren
     * hasta 4,5 puntos en un mes.
     *
     * @param int $anios Cuantos anios calendario COMPLETOS hacia atras
     * @param string|null $hoy 'Y-m-d'. Inyectable para las pruebas
     * @return array Lista de ['anio','mes','peso','unidades','importe_usd']
     */
    public function historiaRecepciones($anios = 3, $hoy = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base central');
        }

        $anios = max(1, intval($anios));
        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        /* LOS ANIOS SON CALENDARIO COMPLETOS Y NO UNA VENTANA MOVIL. El anio en
           curso esta a medias, y un anio incompleto metido en la cuota le da a
           los meses ya transcurridos un peso que los que faltan no pueden
           compensar. Con 3 anios y hoy en 2026, se toman 2023, 2024 y 2025. */
        $ultimo = intval(substr($hoy, 0, 4)) - 1;
        $primero = $ultimo - $anios + 1;

        $desde = sprintf('%04d-01-01', $primero);
        $hasta = sprintf('%04d-12-31', $ultimo);

        $sql = "WITH OC AS (
                    SELECT A.N_ORDEN_CO, MAX(A.TOTAL_EXT) TOTAL_EXT
                    FROM " . self::TABLA_OC . " A
                    WHERE A.COD_PROVEE LIKE ?
                    GROUP BY A.N_ORDEN_CO
                ), MOV AS (
                    SELECT B.N_ORDEN_CO, B.FECHA_MOV, SUM(B.CANTIDAD) CANT
                    FROM " . self::TABLA_MOV . " B
                    WHERE B.TCOMP_IN_S = ?
                      AND B.N_ORDEN_CO IN (SELECT N_ORDEN_CO FROM OC)
                    GROUP BY B.N_ORDEN_CO, B.FECHA_MOV
                ), TOT AS (
                    SELECT N_ORDEN_CO, SUM(CANT) CANT_OC FROM MOV GROUP BY N_ORDEN_CO
                )
                SELECT YEAR(M.FECHA_MOV) anio,
                       MONTH(M.FECHA_MOV) mes,
                       SUM(M.CANT) unidades,
                       SUM(OC.TOTAL_EXT * M.CANT / NULLIF(T.CANT_OC, 0)) importe_usd
                FROM MOV M
                JOIN OC ON OC.N_ORDEN_CO = M.N_ORDEN_CO
                JOIN TOT T ON T.N_ORDEN_CO = M.N_ORDEN_CO
                WHERE M.FECHA_MOV >= ? AND M.FECHA_MOV <= ?
                GROUP BY YEAR(M.FECHA_MOV), MONTH(M.FECHA_MOV)
                ORDER BY anio, mes";

        $stmt = sqlsrv_query($cid, $sql,
            [self::PREFIJO_PROVEEDOR_EXTERIOR . '%', self::COMPROBANTE_RECEPCION,
             $desde, $hasta . ' 23:59:59']);

        if ($stmt === false) {
            throw new Exception($this->errorSql('No se pudo leer la historia de recepciones'));
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'anio' => intval($row['anio']),
                'mes' => intval($row['mes']),
                'unidades' => floatval($row['unidades']),
                'importe_usd' => floatval($row['importe_usd'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /**
     * Los anios calendario completos que usa la cuota.
     *
     * @param int $anios
     * @param string|null $hoy
     * @return array Lista de anios
     */
    public static function aniosDeLaCuota($anios = 3, $hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $anios = max(1, intval($anios));
        $ultimo = intval(substr($hoy, 0, 4)) - 1;

        $out = [];

        for ($a = $ultimo - $anios + 1; $a <= $ultimo; $a++) {
            $out[] = $a;
        }

        return $out;
    }

    /**
     * Le pone a cada movimiento el 'peso' con el que la cuota va a repartir.
     *
     * @param array $historia Lo que devuelve historiaRecepciones()
     * @param string $modo 'IMPORTE' o 'UNIDADES'
     * @return array
     */
    public static function conPeso($historia, $modo = 'IMPORTE') {
        $campo = (strtoupper($modo) === 'UNIDADES') ? 'unidades' : 'importe_usd';
        $out = [];

        foreach ($historia as $h) {
            $out[] = $h + ['peso' => floatval($h[$campo])];
        }

        return $out;
    }

    /* ====================================================================
       3. LO YA COMPRADO
       ==================================================================== */

    /**
     * Los contenedores del maestro de Comercio Exterior que todavia no cerraron,
     * ubicados por su FECHA ESTIMADA DE PAGO, con su FOB pendiente y la fecha en
     * que se emitio su orden de compra.
     *
     * ES EL MISMO PADRON QUE LA PESTANA PROVEEDORES EXTERIOR, y tiene que
     * seguir siendolo: el mismo corte por detalle cargado, el mismo FOB de la
     * OC PRINCIPAL y el mismo pendiente de Comex::saldoPendiente(). Si los dos
     * se separan, el tablero descontaria un numero que la pestana no muestra, y
     * la diferencia no se podria explicar desde ninguna pantalla. Hay una
     * prueba que compara los dos padrones contra la base.
     *
     * LA FECHA QUE UBICA ES FECHA_EST_PAGO Y NO LA RECEPCION ESTIMADA. Es la
     * que usa esa pestana, y es la unica que sirve: lo proyectado nace en el
     * eje de recepcion pero se descuenta en el de pago, y no hay forma de
     * convertir uno en otro. Medido contra la base, la distancia entre
     * FECHA_EST_PAGO y FECHA_DESP_ADU va de -60 a +68 dias -13 contenedores se
     * nacionalizan ANTES de pagarse- asi que la cadena de Comex (45 dias) es
     * solo el valor por defecto y las fechas estan editadas a mano.
     *
     * SIN FECHA_EST_PAGO EL CONTENEDOR NO SE UBICA, y viene con mes_pago en
     * null para que la cuenta lo informe en vez de mandarlo a un mes cualquiera.
     * En la base hay 5, todos de 2024 o anteriores.
     *
     * LA FECHA DE EMISION SALE DE CPA35 Y NO DEL MAESTRO. Es la de Tango, la de
     * la orden de compra real, y es la que hay que comparar contra la fecha de
     * calculo del presupuesto. FECHA_INGRESO, que es la otra candidata, es
     * auditoria de carga: difiere en 288 de 1.628 ordenes y tiene 311 nulos.
     *
     * @return array Lista de contenedores
     */
    public function cargado() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base central');
        }

        /* La tabla de pagos es de la otra plataforma: se pregunta antes de
           nombrarla. Sin ella el pendiente vale el FOB completo, que descuenta
           de MAS -lo contrario que en la pestana, donde proyecta de mas- y por
           eso avisoSinPagos() lo dice con esas palabras. */
        $conPagos = $this->comex->tienePagosComex();

        $pagado = $conPagos
            ? "ISNULL(PG.MONTO, 0)"
            : "CAST(0 AS DECIMAL(18,2))";

        $applyPagos = $conPagos
            ? "OUTER APPLY (SELECT ISNULL(SUM(PG0.MONTO), 0) MONTO
                            FROM " . Comex::TABLA_PAGOS . " PG0
                            WHERE PG0.ID_ENCABEZADO = ISNULL(A.ID_PADRE, A.ID)) PG"
            : "";

        $sql = "SELECT
                    A.ID,
                    A.CONTENEDOR,
                    A.ORDEN_COMPRA,
                    ISNULL(OC.VALOR_FOB_DOLAR, A.VALOR_FOB_DOLAR) VALOR_FOB_DOLAR,
                    " . $pagado . " PAGADO_USD,
                    A.FECHA_EST_PAGO,
                    A.FECHA_DESP_ADU,
                    C.FEC_EMISIO,
                    ISNULL(A.ID_PADRE, A.ID) GRUPO_ID,
                    CASE WHEN ROW_NUMBER() OVER (
                              PARTITION BY ISNULL(A.ID_PADRE, A.ID)
                              ORDER BY CASE WHEN A.ID_PADRE IS NULL THEN 0 ELSE 1 END, A.ID) = 1
                         THEN 0 ELSE 1 END DUPLICA_GRUPO
                FROM " . self::TABLA_MAESTRO . " A
                LEFT JOIN " . self::TABLA_DETALLE_COMEX . " B ON A.ID = B.ID_MG
                LEFT JOIN " . self::TABLA_OC . " C ON C.N_ORDEN_CO = A.ORDEN_COMPRA
                OUTER APPLY (SELECT OC0.VALOR_FOB_DOLAR
                             FROM " . self::TABLA_MAESTRO . " OC0
                             WHERE OC0.ID = ISNULL(A.ID_PADRE, A.ID)) OC
                " . $applyPagos . "
                WHERE B.ID_MG IS NULL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('No se pudo leer el maestro de Comercio Exterior'));
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            /* UNA FILA QUE REPITE UN CONTENEDOR NO DESCUENTA. El FOB y los
               pagos salen de la OC principal, asi que dos ordenes del mismo
               contenedor valen lo mismo: sin el corte, ese contenedor
               descontaria dos veces. Es la misma deduplicacion de
               Comex::duplicaGrupoSelect(), y por el mismo motivo. */
            if (intval($row['DUPLICA_GRUPO']) === 1) {
                continue;
            }

            $saldo = Comex::saldoPendiente($row['VALOR_FOB_DOLAR'], $row['PAGADO_USD']);
            $fechaPago = self::aFecha($row['FECHA_EST_PAGO']);

            $out[] = [
                'id' => intval($row['ID']),
                'contenedor' => trim((string) $row['CONTENEDOR']),
                'orden_compra' => trim((string) $row['ORDEN_COMPRA']),
                'fecha_pago' => $fechaPago,
                'mes_pago' => ($fechaPago === null) ? null : substr($fechaPago, 0, 7),
                'fecha_nacionalizacion' => self::aFecha($row['FECHA_DESP_ADU']),
                'fec_emisio' => self::aFecha($row['FEC_EMISIO']),
                'fob_usd' => $saldo['fob'],
                'pagado_usd' => $saldo['pagado'],
                'pendiente_usd' => $saldo['pendiente'],
                'estado' => $saldo['estado']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /**
     * El aviso de que no se puede leer lo ya pagado en Comex, o cadena vacia.
     *
     * ACA LA DEGRADACION VA PARA EL OTRO LADO QUE EN LA PESTANA. Sin la tabla
     * de pagos, el pendiente de cada contenedor vale el FOB entero, y como aca
     * el pendiente se RESTA, se descuenta de mas: la estimacion queda por
     * debajo de lo que corresponde. En la pestana el mismo dato faltante hace
     * proyectar de mas. Es el mismo hueco leido desde los dos lados.
     *
     * @return string
     */
    public function avisoSinPagos() {
        if ($this->comex->tienePagosComex()) {
            return '';
        }

        return 'No se encuentra la tabla ' . Comex::TABLA_PAGOS . ' de Comercio Exterior. Lo '
            . 'ya comprado se descuenta por el FOB COMPLETO de cada contenedor en vez de por '
            . 'su saldo, así que la estimación de compras proyectadas queda de MENOS. Es una '
            . 'tabla de la otra aplicación: revisalo con quien administra la base central.';
    }

    /* ====================================================================
       UTILIDADES
       ==================================================================== */

    /**
     * Un valor de fecha de sqlsrv a 'Y-m-d', o null.
     *
     * Descarta el centinela 1900-01-01 que dejaron los guardados viejos de
     * Comex: tomarlo por una fecha valida ubicaria el contenedor en un mes que
     * no existe en ningun eje. Es el mismo centinela que documenta la seccion 1
     * de README-comex.md.
     *
     * @param mixed $v
     * @return string|null
     */
    private static function aFecha($v) {
        if ($v === null) {
            return null;
        }

        $s = ($v instanceof DateTime) ? $v->format('Y-m-d') : substr((string) $v, 0, 10);

        if ($s === '' || $s <= '1900-01-01') {
            return null;
        }

        return $s;
    }

    /**
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $msg = $contexto . '.';

        foreach ((array) sqlsrv_errors() as $e) {
            if (isset($e['message'])) {
                $msg .= ' ' . $e['message'];
            }
        }

        return $msg;
    }
}
