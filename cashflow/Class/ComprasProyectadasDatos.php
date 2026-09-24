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
 *   1. EL PRESUPUESTO OFICIAL        MATERIALIZADO en central
 *      RO_V_COMPRA_PROYECTADA_VIGENTE, la vista de la app de compras en
 *      POWER_BI_CONTROL, resumida por RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN en
 *      RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN. Solo el detalle por rubro de una
 *      version se sigue leyendo en vivo, de la vista.
 *
 *   2. LA HISTORIA DE RECEPCIONES    MATERIALIZADA en central
 *      CPA35 + STA20, agrupada por RO_SP_CASHFLOW_COMEX_RECEP_HIST en
 *      RO_T_CASHFLOW_COMEX_RECEP_HIST. De aca sale la cuota.
 *
 *   3. LO YA COMPRADO                EN VIVO, conexion 'central'
 *      RO_T_IMPORTACIONES_ENCABEZADO, el MISMO padron que arma la pestana
 *      Proveedores Exterior, con el mismo pendiente. Ver cargado().
 *
 * POR QUE DOS DE LAS TRES VIENEN DE UN JOB. La historia tardaba entre 38 y 57
 * segundos por pedido, y era el 95 % de lo que tardaban la pestana y la fila
 * del tablero. Es historia de anios cerrados, y el presupuesto cambia cuando
 * alguien marca una version, no en cada pedido. Lo ya comprado NO va al job:
 * un contenedor cargado tiene que descontar en el momento. Ver la seccion 10
 * de README-compras-proyectadas.md.
 *
 * SIN LAS TABLAS NO HAY VUELTA A LA CONSULTA EN VIVO. La fila va en cero y el
 * primer aviso dice que job falta correr: ver avisoFaltaJob(). Un fallback
 * silencioso escondería que el job no corre, y volveria a dejar el tablero
 * esperando cuarenta segundos.
 *
 * ESTA CLASE NO ESCRIBE NADA. Ni en las tablas de compras, ni en las de
 * Comercio Exterior, ni en Tango, ni en las materializadas: esas las llenan
 * los SP, y el boton "Actualizar ahora" los corre desde ComprasProyectadasJob.
 * Hay una prueba que lo verifica buscando los verbos de escritura sobre este
 * archivo.
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

    /**
     * Los ajustes manuales por mes, del lado del cashflow.
     *
     * ESTA CLASE SOLO LA LEE. El alta y la baja viven en
     * ComprasProyectadasAjustes, aparte, para que la regla de que este archivo
     * no escribe nada siga siendo verificable de un vistazo -y por una prueba
     * que busca los verbos de escritura sobre el archivo entero-.
     */
    const TABLA_AJUSTE = 'RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE';

    /** Lo que llenan los SP. Ver sql/cashflow_comex_materializado.sql */
    const TABLA_RECEP_HIST = 'RO_T_CASHFLOW_COMEX_RECEP_HIST';
    const TABLA_PRESUP_RESUMEN = 'RO_T_CASHFLOW_COMEX_PRESUP_RESUMEN';
    const TABLA_PRESUP_CONTRASTE = 'RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE';
    const TABLA_JOB_LOG = 'RO_T_CASHFLOW_JOB_LOG';

    /** El PROCESO con que cada SP se anota en el log */
    const PROCESO_HISTORIA = 'COMEX_RECEP_HIST';
    const PROCESO_PRESUPUESTO = 'COMEX_PRESUP_RESUMEN';

    /** Los SP, para nombrarlos en los avisos */
    const SP_HISTORIA = 'RO_SP_CASHFLOW_COMEX_RECEP_HIST';
    const SP_PRESUPUESTO = 'RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN';

    /**
     * Pasadas estas horas desde el ultimo calculo, el presupuesto se avisa como
     * viejo. El job sugerido corre cada 30 minutos en horario habil y a las
     * 05:00: un dia entero sin correr es que el job no esta corriendo.
     */
    const HORAS_PRESUPUESTO_VIEJO = 24;

    /** El linked server a POWER_BI_CONTROL desde central, el mismo de los SP */
    const POWER_REMOTO = '[XL-APPS].POWER_BI_CONTROL.dbo.';

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

    /** @var bool|null Cache de si la tabla de ajustes existe */
    private $ajustes = null;

    /** @var string Ultimo error de lectura del presupuesto */
    private $errorPresupuesto = '';

    /** @var array|null Cache de estadoInsumos(): se lee una vez por pedido */
    private $estado = null;

    /** @var array|null Cache de oficialesEnVivo() */
    private $oficiales = null;

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
     * HOY SOLO LA USA detalleVersion(), que es lo unico del presupuesto que se
     * sigue leyendo en vivo. La proyeccion lee el resumen materializado.
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
     * Las versiones oficiales vigentes, una por temporada, con el FOB del tramo
     * objetivo ya valorizado.
     *
     * SE LEEN DEL RESUMEN MATERIALIZADO, no de la vista. Lo llena
     * RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN con la misma consulta que esta
     * funcion hacia en vivo -verificado temporada por temporada el
     * 24/09/2026-, asi que las reglas de abajo siguen valiendo tal cual: lo que
     * cambio es CUANDO se aplican, no cuales son.
     *
     * Un ajuste manual guardado mientras el job no corrio se ata a la version
     * que la pantalla estaba mostrando. Es lo correcto: el numero se puso
     * mirando esa version, y cuando el job traiga la nueva el ajuste se
     * descarta con su aviso, como con cualquier cambio de oficial.
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
        $e = $this->estadoInsumos();

        if (!$e['presupuesto']['tabla']) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT
                    TEMPORADA             temporada_codigo,
                    ID_VERSION            id_version,
                    NOMBRE                nombre,
                    SOLAPA                solapa,
                    FECHA_CALCULO_VERSION fecha_calculo,
                    TRAMOS_ESTADO         tramos_estado,
                    TEMPORADA_DESDE       temporada_desde,
                    TEMPORADA_HASTA       temporada_hasta,
                    FILAS                 filas,
                    FILAS_SIN_COSTO       filas_sin_costo,
                    UNIDADES              unidades,
                    UNIDADES_SIN_COSTO    unidades_sin_costo,
                    FOB_USD               fob_usd,
                    NAC_SEGUN_INC_FOB     nac_segun_inc_fob,
                    DEFICIT_COBERTURA     deficit_cobertura
                FROM " . self::TABLA_PRESUP_RESUMEN . "
                WHERE PAIS = ?";

        $stmt = sqlsrv_query($cid, $sql, [$pais]);

        if ($stmt === false) {
            $this->errorPresupuesto = 'No se pudo leer ' . self::TABLA_PRESUP_RESUMEN;

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
     * SE LEE DE RO_T_CASHFLOW_COMEX_PRESUP_CONTRASTE, que llena el mismo SP y en
     * la misma corrida que el resumen: el contraste describe exactamente el
     * presupuesto que se esta proyectando, no uno mas nuevo.
     *
     * @return array ['filas_vista','filas_tablas','fob_vista','fob_tablas'] o []
     */
    public function contrasteVista($pais = 'argentina') {
        $e = $this->estadoInsumos();

        if (!$e['contraste']) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT FILAS_VISTA filas_vista, FOB_VISTA fob_vista,
                       FILAS_TABLAS filas_tablas, FOB_TABLAS fob_tablas
                FROM " . self::TABLA_PRESUP_CONTRASTE . "
                WHERE PAIS = ?";

        $stmt = sqlsrv_query($cid, $sql, [$pais]);

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
     * SE LEE DE RO_T_CASHFLOW_COMEX_RECEP_HIST. La llena
     * RO_SP_CASHFLOW_COMEX_RECEP_HIST con diez anios, y de ahi se toman los que
     * pide la cuota: cambiar compras_proy_anios_cuota no obliga a correr nada.
     * La definicion del calculo -lo de arriba- es la de
     * historiaRecepcionesEnVivo(), y una prueba compara las dos contra la base.
     *
     * Si a la tabla le faltan anios de los que la cuota pide, no se inventan:
     * se devuelve lo que hay y avisosInsumos() lo dice.
     *
     * @param int $anios Cuantos anios calendario COMPLETOS hacia atras
     * @param string|null $hoy 'Y-m-d'. Inyectable para las pruebas
     * @return array Lista de ['anio','mes','unidades','importe_usd']
     */
    public function historiaRecepciones($anios = 3, $hoy = null) {
        $e = $this->estadoInsumos();

        if (!$e['historia']['tabla']) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base central');
        }

        $anios = self::aniosDeLaCuota($anios, $hoy);

        $stmt = sqlsrv_query($cid,
            "SELECT ANIO anio, MES mes, UNIDADES unidades, IMPORTE_USD importe_usd
             FROM " . self::TABLA_RECEP_HIST . "
             WHERE ANIO BETWEEN ? AND ?
             ORDER BY ANIO, MES",
            [$anios[0], $anios[count($anios) - 1]]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('No se pudo leer ' . self::TABLA_RECEP_HIST));
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
     * LA DEFINICION DE LA HISTORIA, leida en vivo de CPA35 + STA20.
     *
     * EL PROVEEDOR NO LA LLAMA: tarda entre 31 y 57 segundos. Queda como la
     * referencia contra la que se verifica el SP -hay una prueba que compara
     * las dos para 2023-2025- y para poder auditar un mes puntual sin esperar
     * al job. Si se toca un filtro aca, se toca en
     * sql/RO_SP_CASHFLOW_COMEX_RECEP_HIST.sql, y al reves.
     *
     * @param int $anios
     * @param string|null $hoy
     * @return array Lista de ['anio','mes','unidades','importe_usd']
     */
    public function historiaRecepcionesEnVivo($anios = 3, $hoy = null) {
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
             $desde, $hasta . ' 23:59:59'], ['QueryTimeout' => 300]);

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
       4. LOS AJUSTES MANUALES (solo lectura)
       ==================================================================== */

    /**
     * Si la tabla de ajustes ya existe.
     *
     * Se pregunta por lo mismo que con la vista: sin el script la tabla no
     * esta, y nombrarla igual rompe la pantalla entera con "Invalid object
     * name". Aca la degradacion es la mas benigna del modulo -sin tabla no hay
     * ningun ajuste cargado, que es exactamente lo que la proyeccion automatica
     * ya supone- asi que ningun numero cambia.
     *
     * @return bool
     */
    public function tieneAjustes() {
        if ($this->ajustes !== null) {
            return $this->ajustes;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            $this->ajustes = false;

            return false;
        }

        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA_AJUSTE . "', 'U') AS T");

        if ($stmt === false) {
            $this->ajustes = false;

            return false;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->ajustes = ($row && $row['T'] !== null);

        return $this->ajustes;
    }

    /**
     * Los ajustes VIGENTES, uno por mes de recepcion.
     *
     * Devuelve el mapa con la forma que espera ComprasProyectadas::estimar():
     * ahi adentro se decide si el ajuste se aplica o se descarta, comparando
     * ID_VERSION contra la version oficial que el mes tiene HOY. Esa decision
     * no vive en la consulta a proposito -es una regla de negocio y se prueba
     * sin base-, asi que aca se devuelven todos los vigentes, aplicables o no.
     *
     * @return array Mapa 'Y-m' => ajuste
     */
    public function ajustes() {
        if (!$this->tieneAjustes()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT ID, MES, IMPORTE_USD, ID_VERSION, TEMPORADA, MOTIVO,
                       USUARIO, FECHA_ALTA
                FROM " . self::TABLA_AJUSTE . "
                WHERE VIGENTE = 1
                ORDER BY MES";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            return [];
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mes = trim((string) $row['MES']);

            $out[$mes] = [
                'id' => intval($row['ID']),
                'mes' => $mes,
                'importe_usd' => floatval($row['IMPORTE_USD']),
                'id_version' => intval($row['ID_VERSION']),
                'temporada' => ($row['TEMPORADA'] === null) ? null : trim((string) $row['TEMPORADA']),
                'motivo' => ($row['MOTIVO'] === null) ? null : (string) $row['MOTIVO'],
                'usuario' => ($row['USUARIO'] === null) ? null : (string) $row['USUARIO'],
                'fecha' => self::aFecha($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /**
     * El aviso de que el ajuste manual esta apagado, o cadena vacia.
     *
     * APAGA UNA FUNCION Y NO CAMBIA NINGUN NUMERO, a diferencia de los otros
     * dos avisos de esta clase. Sin la tabla no hay ningun ajuste cargado, que
     * es el mismo estado que tiene una instalacion donde nadie ajusto nada.
     *
     * @return string
     */
    public function avisoSinAjustes() {
        if ($this->tieneAjustes()) {
            return '';
        }

        return 'El ajuste manual por mes está apagado: falta la tabla ' . self::TABLA_AJUSTE
            . '. Corré sql/cashflow_compras_proyectadas.sql contra la base central. Todo lo '
            . 'demás funciona igual: mientras tanto no hay ningún ajuste cargado, así que '
            . 'cada mes muestra su estimación automática.';
    }

    /* ====================================================================
       5. EL ESTADO DE LOS INSUMOS MATERIALIZADOS (solo lectura)
       ==================================================================== */

    /**
     * Que tablas existen, cuanto tienen y como salieron las ultimas corridas de
     * cada SP.
     *
     * SE LEE UNA SOLA VEZ POR PEDIDO, con dos consultas: una que pregunta que
     * tablas existen y otra que lee solo las que existen. Van separadas porque
     * nombrar una tabla ausente rompe la consulta entera con "Invalid object
     * name", y eso es justamente lo que pasa antes de correr el script.
     *
     * NO LANZA. Si no se puede leer, lo dice en 'error' y avisoFaltaJob() lo
     * trata como lo que es: no se sabe si hay insumos, asi que la fila va en
     * cero avisando.
     *
     * @return array ['error','log','contraste','historia','presupuesto']
     */
    public function estadoInsumos() {
        if ($this->estado !== null) {
            return $this->estado;
        }

        $proceso = ['tabla' => false, 'filas' => 0, 'ok' => null, 'ultima' => null];

        $e = [
            'error' => null,
            'log' => false,
            'contraste' => false,
            'historia' => $proceso + ['anio_min' => null, 'anio_max' => null],
            'presupuesto' => $proceso
        ];

        $this->estado = $e;

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            $this->estado['error'] = 'no se pudo conectar a la base central';

            return $this->estado;
        }

        $stmt = sqlsrv_query($cid, "SELECT
            OBJECT_ID('dbo." . self::TABLA_JOB_LOG . "', 'U') L,
            OBJECT_ID('dbo." . self::TABLA_RECEP_HIST . "', 'U') H,
            OBJECT_ID('dbo." . self::TABLA_PRESUP_RESUMEN . "', 'U') R,
            OBJECT_ID('dbo." . self::TABLA_PRESUP_CONTRASTE . "', 'U') C");

        if ($stmt === false) {
            $this->estado['error'] = $this->errorSql('no se pudo verificar las tablas materializadas');

            return $this->estado;
        }

        $obj = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $e['log'] = ($obj['L'] !== null);
        $e['historia']['tabla'] = ($obj['H'] !== null);
        $e['presupuesto']['tabla'] = ($obj['R'] !== null);
        $e['contraste'] = ($obj['C'] !== null);

        /* Solo se nombran las que existen. */
        $partes = [];

        if ($e['historia']['tabla']) {
            $partes[] = "(SELECT COUNT(*) FROM " . self::TABLA_RECEP_HIST . ") h_filas,
                         (SELECT MIN(ANIO) FROM " . self::TABLA_RECEP_HIST . ") h_min,
                         (SELECT MAX(ANIO) FROM " . self::TABLA_RECEP_HIST . ") h_max";
        }

        if ($e['presupuesto']['tabla']) {
            $partes[] = "(SELECT COUNT(*) FROM " . self::TABLA_PRESUP_RESUMEN . ") p_filas";
        }

        if ($e['log']) {
            foreach (['h' => self::PROCESO_HISTORIA, 'p' => self::PROCESO_PRESUPUESTO] as $k => $p) {
                /* La ultima corrida BUENA -de donde sale "Historia al ..."- y la
                   ultima a secas, que puede ser una que fallo despues. */
                $ok = "FROM " . self::TABLA_JOB_LOG . " WHERE PROCESO = '" . $p . "'
                       AND FIN IS NOT NULL AND ERROR IS NULL ORDER BY INICIO DESC";
                $ul = "FROM " . self::TABLA_JOB_LOG . " WHERE PROCESO = '" . $p . "'
                       ORDER BY INICIO DESC";

                $partes[] = "(SELECT TOP 1 INICIO $ok) {$k}_ok_inicio,
                             (SELECT TOP 1 FIN $ok) {$k}_ok_fin,
                             (SELECT TOP 1 FILAS $ok) {$k}_ok_filas,
                             (SELECT TOP 1 USUARIO $ok) {$k}_ok_usuario,
                             (SELECT TOP 1 INICIO $ul) {$k}_u_inicio,
                             (SELECT TOP 1 FIN $ul) {$k}_u_fin,
                             (SELECT TOP 1 ERROR $ul) {$k}_u_error,
                             (SELECT TOP 1 USUARIO $ul) {$k}_u_usuario";
            }
        }

        if (!empty($partes)) {
            $stmt = sqlsrv_query($cid, "SELECT " . implode(",\n", $partes));

            if ($stmt === false) {
                $e['error'] = $this->errorSql('no se pudo leer el estado de las tablas materializadas');
                $this->estado = $e;

                return $this->estado;
            }

            $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            if ($e['historia']['tabla']) {
                $e['historia']['filas'] = intval($r['h_filas']);
                $e['historia']['anio_min'] = ($r['h_min'] === null) ? null : intval($r['h_min']);
                $e['historia']['anio_max'] = ($r['h_max'] === null) ? null : intval($r['h_max']);
            }

            if ($e['presupuesto']['tabla']) {
                $e['presupuesto']['filas'] = intval($r['p_filas']);
            }

            if ($e['log']) {
                foreach (['h' => 'historia', 'p' => 'presupuesto'] as $k => $cual) {
                    if ($r[$k . '_ok_inicio'] !== null) {
                        $e[$cual]['ok'] = [
                            'inicio' => self::aFechaHora($r[$k . '_ok_inicio']),
                            'fin' => self::aFechaHora($r[$k . '_ok_fin']),
                            'filas' => intval($r[$k . '_ok_filas']),
                            'usuario' => $r[$k . '_ok_usuario']
                        ];
                    }

                    if ($r[$k . '_u_inicio'] !== null) {
                        $e[$cual]['ultima'] = [
                            'inicio' => self::aFechaHora($r[$k . '_u_inicio']),
                            'fin' => self::aFechaHora($r[$k . '_u_fin']),
                            'error' => $r[$k . '_u_error'],
                            'usuario' => $r[$k . '_u_usuario']
                        ];
                    }
                }
            }
        }

        $this->estado = $e;

        return $this->estado;
    }

    /**
     * Las versiones oficiales que la app de compras tiene HOY, leidas en vivo.
     *
     * ES LA UNICA LECTURA DEL PRESUPUESTO QUE SIGUE EN CADA PEDIDO, y es a
     * proposito chica: la cabecera, sin la vista ni el detalle. Sirve para una
     * sola cosa, avisar que se marco una oficial despues del ultimo calculo.
     * Va por el linked server desde central, igual que el SP, asi que no abre
     * otra conexion.
     *
     * @param string $pais
     * @return array ['lista' => [['id','fecha_calculo','oficial_fecha']], 'error' => string|null]
     */
    public function oficialesEnVivo($pais = 'argentina') {
        if ($this->oficiales !== null) {
            return $this->oficiales;
        }

        $this->oficiales = ['lista' => [], 'error' => null];

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            $this->oficiales['error'] = 'no se pudo conectar a la base central';

            return $this->oficiales;
        }

        $stmt = sqlsrv_query($cid,
            "SELECT id, fecha_calculo, oficial_fecha
             FROM " . self::POWER_REMOTO . self::TABLA_CABECERA . "
             WHERE es_oficial = 1 AND eliminada = 0 AND pais = ?",
            [$pais], ['QueryTimeout' => 15]);

        if ($stmt === false) {
            $this->oficiales['error'] = $this->errorSql('no se pudo leer la cabecera de versiones');

            return $this->oficiales;
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $this->oficiales['lista'][] = [
                'id' => intval($row['id']),
                'fecha_calculo' => self::aFecha($row['fecha_calculo']),
                'oficial_fecha' => self::aFechaHora($row['oficial_fecha'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->oficiales;
    }

    /**
     * El aviso que deja la fila en CERO, o cadena vacia.
     *
     * @return string
     */
    public function avisoFaltaJob() {
        return self::avisoFaltaJobDe($this->estadoInsumos());
    }

    /**
     * Los avisos que NO dejan la fila en cero: una corrida que fallo despues
     * de una buena, anios que le faltan a la historia, un presupuesto viejo o
     * mas viejo que una version oficial.
     *
     * @param int $aniosCuota compras_proy_anios_cuota
     * @param string $hoy 'Y-m-d'
     * @param string $pais
     * @return array Lista de textos
     */
    public function avisosInsumos($aniosCuota, $hoy, $pais = 'argentina') {
        $e = $this->estadoInsumos();

        /* Sin un calculo bueno del presupuesto no hay contra que comparar las
           oficiales, y ya hay un aviso mas grave: no se lee la cabecera. */
        $oficiales = ($e['presupuesto']['ok'] === null) ? null : $this->oficialesEnVivo($pais);

        return self::avisosInsumosDe($e, self::aniosDeLaCuota($aniosCuota, $hoy),
            date('Y-m-d H:i:s'), $oficiales);
    }

    /**
     * EL PRIMER AVISO, cuando falta un insumo sin el cual no hay proyeccion.
     *
     * DICE QUE SE PROYECTA DE MENOS, no solo que falta algo. Una fila de
     * egresos en cero se lee como "no hay que pagar nada", que es lo contrario
     * de lo que pasa. Es el mismo criterio que tenia el aviso de la vista
     * ausente, que este reemplaza.
     *
     * Cuando falta:
     *   historia     la tabla no existe, o esta vacia. Vacia no es un estado
     *                valido: el SP no graba una historia vacia.
     *   presupuesto  la tabla no existe, o el SP nunca corrio bien. Una tabla
     *                vacia DESPUES de una corrida buena si es valida -no hay
     *                ninguna version oficial- y la grilla lo muestra mes por
     *                mes como SIN_PRESUPUESTO.
     *
     * Es estatica para poder probarla sin base.
     *
     * @param array $e Lo que devuelve estadoInsumos()
     * @return string
     */
    public static function avisoFaltaJobDe($e) {
        if (!empty($e['error'])) {
            return 'Se está proyectando DE MENOS: las filas proyectadas de compras del exterior '
                . 'van en CERO porque no se pudo leer el estado de sus insumos (' . $e['error']
                . ').';
        }

        $faltan = [];

        foreach (['historia' => ['la historia de recepciones', self::SP_HISTORIA],
                  'presupuesto' => ['el presupuesto oficial', self::SP_PRESUPUESTO]] as $cual => $d) {
            $p = $e[$cual];

            if (!$p['tabla']) {
                $faltan[] = $d[0] . ' (' . $d[1] . '; antes, correr '
                    . 'sql/cashflow_comex_materializado.sql)';

                continue;
            }

            $vacio = ($cual === 'historia') ? ($p['filas'] <= 0) : ($p['ok'] === null);

            if (!$vacio) {
                continue;
            }

            $porQue = ($p['ultima'] !== null && !empty($p['ultima']['error']))
                ? '; la última corrida, del ' . self::fechaCorta($p['ultima']['inicio'])
                    . ', falló: ' . $p['ultima']['error']
                : '; nunca corrió';

            $faltan[] = $d[0] . ' (' . $d[1] . $porQue . ')';
        }

        if (empty($faltan)) {
            return '';
        }

        return 'Se está proyectando DE MENOS: las filas proyectadas de compras del exterior van '
            . 'en CERO porque falta correr el job de ' . implode(' y el de ', $faltan) . '. Se '
            . 'corre desde Comercio Exterior › Proyección con «Actualizar ahora», o con su job '
            . 'del SQL Agent.';
    }

    /**
     * Los avisos que no dejan la fila en cero. Ver avisosInsumos().
     *
     * Es estatica para poder probarla sin base: el estado, los anios, la hora
     * y las oficiales entran por parametro.
     *
     * @param array $e Lo que devuelve estadoInsumos()
     * @param array $aniosCuota Los anios que pide la cuota, en orden
     * @param string $ahora 'Y-m-d H:i:s'
     * @param array|null $oficiales Lo que devuelve oficialesEnVivo(), o null
     *                              si no hay contra que comparar
     * @return array
     */
    public static function avisosInsumosDe($e, $aniosCuota, $ahora, $oficiales = null) {
        $out = [];

        if (!empty($e['error'])) {
            return $out;
        }

        /* UNA CORRIDA QUE FALLO DESPUES DE UNA BUENA. La pantalla sigue con el
           calculo anterior, y eso esta bien, pero tiene que decir por que no
           es el de hoy. */
        foreach (['historia' => 'la historia de recepciones',
                  'presupuesto' => 'el presupuesto oficial'] as $cual => $nombre) {
            $p = $e[$cual];

            if ($p['ok'] !== null && $p['ultima'] !== null && !empty($p['ultima']['error'])
                && $p['ultima']['inicio'] > $p['ok']['inicio']) {
                $out[] = 'La última corrida del job de ' . $nombre . ', del '
                    . self::fechaCorta($p['ultima']['inicio']) . ', falló: '
                    . $p['ultima']['error'] . ' Se sigue usando el cálculo del '
                    . self::fechaCorta($p['ok']['fin']) . '.';
            }
        }

        /* LOS ANIOS DE LA HISTORIA. El 1 de enero el anio que termino pasa a
           contar, y si el job no corrio desde entonces a la tabla le falta. */
        $h = $e['historia'];

        if ($h['tabla'] && $h['filas'] > 0 && !empty($aniosCuota)) {
            $primero = $aniosCuota[0];
            $ultimo = $aniosCuota[count($aniosCuota) - 1];

            if ($h['anio_max'] !== null && $h['anio_max'] < $ultimo) {
                $uno = ($h['anio_max'] + 1 === $ultimo);

                $out[] = 'A la historia de recepciones ' . ($uno
                        ? 'le falta el año ' . $ultimo
                        : 'le faltan los años ' . ($h['anio_max'] + 1) . ' a ' . $ultimo)
                    . ($h['ok'] !== null ? ': se calculó el ' . self::fechaCorta($h['ok']['fin']) : '')
                    . '. La cuota se arma sin ' . ($uno ? 'ese año' : 'esos años')
                    . ' hasta que el job vuelva a correr.';
            }

            if ($h['anio_min'] !== null && $h['anio_min'] > $primero) {
                $out[] = 'La historia de recepciones guardada arranca en ' . $h['anio_min']
                    . ' y la cuota pide desde ' . $primero . ' (' . count($aniosCuota)
                    . ' años): se arma con los que hay. El SP guarda diez años; para más, '
                    . 'hay que correrlo con un @Anios mayor.';
            }
        }

        /* EL PRESUPUESTO VIEJO: por horas, y contra las oficiales de hoy. */
        $p = $e['presupuesto'];

        if ($p['tabla'] && $p['ok'] !== null) {
            $fin = $p['ok']['fin'];
            $horas = (strtotime($ahora) - strtotime($fin)) / 3600;

            if ($horas > self::HORAS_PRESUPUESTO_VIEJO) {
                $out[] = 'El presupuesto oficial se calculó el ' . self::fechaCorta($fin)
                    . ', hace ' . intval(floor($horas)) . ' horas: su job no está corriendo. '
                    . 'Las filas proyectan con ese presupuesto.';
            }

            if (is_array($oficiales) && !empty($oficiales['error'])) {
                $out[] = 'No se pudo verificar si hay una versión oficial más nueva que el '
                    . 'último cálculo del presupuesto (' . $oficiales['error'] . ').';
            } elseif (is_array($oficiales)) {
                $nuevas = [];

                foreach ($oficiales['lista'] as $o) {
                    /* Marcada oficial despues del calculo, o calculada despues:
                       fecha_calculo es DATE, asi que se compara contra el dia. */
                    $marcadaDespues = ($o['oficial_fecha'] !== null && $o['oficial_fecha'] > $fin);
                    $calculadaDespues = ($o['fecha_calculo'] !== null
                        && $o['fecha_calculo'] > substr($fin, 0, 10));

                    if ($marcadaDespues || $calculadaDespues) {
                        $nuevas[] = $o['id'] . ($o['oficial_fecha'] !== null
                            ? ', marcada oficial el ' . self::fechaCorta($o['oficial_fecha']) : '');
                    }
                }

                if (!empty($nuevas)) {
                    $out[] = 'Hay ' . (count($nuevas) === 1 ? 'una versión oficial más nueva'
                            : 'versiones oficiales más nuevas') . ' que el último cálculo del '
                        . 'presupuesto, del ' . self::fechaCorta($fin) . ' (versión '
                        . implode('; versión ', $nuevas) . '). Las filas proyectan con el '
                        . 'presupuesto anterior hasta que su job vuelva a correr.';
                }
            }
        }

        return $out;
    }

    /**
     * Lo que la pestana muestra de cada insumo: "Historia al dd/mm hh:mm".
     *
     * @param array $e Lo que devuelve estadoInsumos()
     * @return array ['historia' => [...], 'presupuesto' => [...]]
     */
    public static function insumosParaPantalla($e) {
        $out = [];

        foreach (['historia', 'presupuesto'] as $cual) {
            $p = $e[$cual];

            $out[$cual] = [
                'tabla' => $p['tabla'],
                'al' => ($p['ok'] === null) ? null : self::fechaCorta($p['ok']['fin']),
                'fin' => ($p['ok'] === null) ? null : $p['ok']['fin'],
                'filas' => ($p['ok'] === null) ? null : $p['ok']['filas'],
                'usuario' => ($p['ok'] === null) ? null : $p['ok']['usuario'],
                'fallo' => ($p['ultima'] !== null && !empty($p['ultima']['error'])
                            && ($p['ok'] === null || $p['ultima']['inicio'] > $p['ok']['inicio']))
                    ? $p['ultima']['error'] : null
            ];
        }

        return $out;
    }

    /**
     * 'Y-m-d H:i:s' -> 'dd/mm hh:mm'
     *
     * @param string|null $fechaHora
     * @return string
     */
    public static function fechaCorta($fechaHora) {
        if ($fechaHora === null || $fechaHora === '') {
            return '—';
        }

        return substr($fechaHora, 8, 2) . '/' . substr($fechaHora, 5, 2) . ' '
            . substr($fechaHora, 11, 5);
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
     * Un valor de fecha y hora de sqlsrv a 'Y-m-d H:i:s', o null.
     *
     * @param mixed $v
     * @return string|null
     */
    private static function aFechaHora($v) {
        if ($v === null) {
            return null;
        }

        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i:s') : substr((string) $v, 0, 19);
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
