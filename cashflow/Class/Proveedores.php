<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Ingresos.php';
require_once __DIR__ . '/ProveedoresCategorias.php';
require_once __DIR__ . '/ProveedoresExclusion.php';
require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/CronogramaPagos.php';
require_once __DIR__ . '/ProveedoresRetenciones.php';

/**
 * Proveedores
 * Las cuentas a pagar locales: cuanto se debe, cuando vence y cuando se paga.
 *
 * DE DONDE SALEN LOS PENDIENTES
 * -----------------------------
 * De Tango, con la consulta depurada de getPendientes(). El original que genera
 * Tango para su reporte de composicion de saldos esta guardado, sin ejecutar, en
 * sql/_referencia_tango_pendientes.sql, y el encabezado de ese archivo explica
 * que se saco y por que.
 *
 * LA CONSULTA REPRODUCE A TANGO AL CENTAVO. Aplicandole sus mismos filtros
 * -solo lo no vencido, sin excluir el exterior- da exactamente sus 158 filas y
 * sus $521.858.835,68. Esa verificacion es lo unico que permite afirmar que el
 * numero del tablero es el mismo que el del reporte.
 *
 * UNA FILA POR VENCIMIENTO, NO POR COMPROBANTE
 * --------------------------------------------
 * Un comprobante puede tener varios vencimientos -hay uno con doce cuotas- y
 * cada cuota cae en una columna distinta del eje. Agrupar por comprobante
 * pondria las doce en la fecha de la primera.
 *
 * EL PENDIENTE NO ES EL IMPORTE DEL VENCIMIENTO
 * ---------------------------------------------
 * Hay que restarle lo imputado, y ahi esta la parte que es facil hacer mal:
 * CPA05 no guarda solo pagos, guarda TODO lo que se imputa contra el
 * comprobante, y no todo lo cancela.
 *
 *     T_COMP_CAN = 'REC'   -> RESTA (aunque CPA21 diga que REC es 'D')
 *     CPA21.CRE_DEB = 'D'  -> SUMA   (una nota de debito es deuda NUEVA)
 *     cualquier otro caso  -> RESTA  (O/P, notas de credito)
 *
 * Sin esa tabla de signos una nota de debito imputada se resta como si fuera un
 * pago y el pendiente da NEGATIVO. Pasa de verdad: la FAC A0000500001731 de
 * DONNA DI DIO tiene un vencimiento de 14.614.380, una O/P que lo cancela entero
 * y una nota de debito de 3.342.062,08 imputada encima. Lo que se debe son esos
 * 3.342.062,08; restando todo da -3.342.062,08.
 *
 * QUE QUEDA AFUERA Y POR QUE
 * --------------------------
 * - COD_PROVEE LIKE 'Z%': son los proveedores del EXTERIOR, no proveedores de
 *   sistema. Y no es solo que no correspondan a un listado local: YA ENTRAN AL
 *   TABLERO por COMEX_PROV_EXT, asi que incluirlos los contaria dos veces. Son
 *   8.023 de los 9.344 millones pendientes totales.
 * - CPA01.CLAUSULA = 1: proveedores con clausula de moneda extranjera. Es el
 *   criterio del "Total Pendiente (CTE)" de Tango, que es el pendiente EN PESOS.
 *   Hoy deja afuera $20.938 de un transportista local de 2022 y 2023, que
 *   ademas tiene el importe en moneda extranjera en cero: casi seguro un error
 *   de maestro.
 *
 * LO VENCIDO ENTRA, SIN TECHO DE ANTIGUEDAD
 * -----------------------------------------
 * Cobranzas descarta lo vencido hace mas de Ingresos::DIAS_COBRO_VENCIDO dias.
 * ACA NO, y la diferencia no es un descuido: una factura vieja sin COBRAR puede
 * ser incobrable, pero una vieja sin PAGAR sigue siendo deuda. Con el techo de
 * 180 dias quedaban afuera $346,8 millones, el 29% del total, y no eran basura
 * administrativa: la mayor parte es un plan de cuotas vigente.
 *
 * La herramienta para redistribuir lo vencido es cargarle una fecha de pago, no
 * un filtro por antiguedad. Por eso getPendientes() informa aparte cuanto hay
 * VENCIDO SIN FECHA CARGADA: apilarlo en el primer dia del eje sin decirlo seria
 * mostrar que se paga todo hoy.
 *
 * DOS FORMAS DE PAGO QUE NO SON LA MISMA COSA
 * -------------------------------------------
 * Cada fila viaja con dos, y confundirlas fue un bug:
 *
 *   FORMA_PAGO_MAESTRO  como se le paga a ESE PROVEEDOR, segun el maestro. Es
 *                       una regla, y es lo UNICO que decide si el comprobante
 *                       entra al cronograma -al filtro de la pestaña y a la
 *                       serie PAGOS del tablero-.
 *   FORMA_PAGO          por que via salio o va a salir ESTE PAGO, si hay uno
 *                       registrado. Es un hecho, y solo se muestra.
 *
 * EL FILTRO MIRA LA REGLA, NO EL HECHO. Si lo decidiera la fila de pago, cargar
 * una fecha desde la grilla moveria la deuda de serie -la grilla manda la fecha
 * y nada mas, asi que la forma llegaria vacia- y un pago hecho por una via
 * distinta de la habitual sacaria al proveedor del cronograma sin que nadie lo
 * haya decidido.
 *
 * Que las dos difieran no es un error: es informacion. Significa que a ese
 * proveedor se le pago por una via distinta de la habitual, y lo que
 * eventualmente hay que corregir es el maestro.
 */
class Proveedores {

    /**
     * Prefijo de los proveedores del exterior.
     *
     * No son proveedores de sistema: son los de China, Hong Kong, India y las
     * razones sociales del grupo en el exterior. Quedan afuera porque ya entran
     * al tablero por COMEX_PROV_EXT.
     */
    const PREFIJO_EXTERIOR = 'Z';

    /** Tabla de fechas de pago, en la base central */
    const TABLA_PAGO = 'RO_T_CASHFLOW_PROV_LOCALES_PAGO';

    /**
     * La apertura de un comprobante pagado con DOS MEDIOS: una fila por medio.
     * Ver sql/cashflow_prov_locales_pago_medio.sql y abrirEnMedios().
     */
    const TABLA_MEDIO = 'RO_T_CASHFLOW_PROV_LOCALES_PAGO_MEDIO';

    /** El script que la crea, para nombrarlo en los avisos y en los motivos */
    const SCRIPT_MEDIO = 'sql/cashflow_prov_locales_pago_medio.sql';

    /**
     * A lo sumo dos medios por comprobante. Una tercera fila no es un tercer
     * medio: casi siempre es un comprobante copiado de mas, y aceptarla
     * obligaria a elegir cual de las tres sobra.
     */
    const MAX_MEDIOS = 2;

    /**
     * Cuanto se tolera entre un IMPORTE de la planilla y el pendiente de Tango:
     * un peso. Es el redondeo de quien arma la planilla a mano, no un descuento;
     * mas que eso es otro numero y la fila queda en error con la diferencia.
     */
    const TOLERANCIA_IMPORTE = 1.0;

    /** @var Conexion */
    private $conn;

    /** @var ProveedoresCategorias */
    private $categorias;

    /** @var bool|null Cache del chequeo de existencia de la tabla de pagos */
    private $tabla = null;

    /** @var bool|null Cache del chequeo de existencia de la tabla de medios */
    private $tablaMedio = null;

    /** @var array Cache de que columnas de override tiene la tabla de pagos */
    private $columnasPago = [];

    /** @var ProveedoresExclusion */
    private $exclusion;

    /** @var array Avisos del cronograma de la ultima lectura. Ver avisosCronograma() */
    private $avisosCrono = [];

    /** @var ProveedoresRetenciones */
    private $retenciones;

    /**
     * @param ProveedoresCategorias|null $categorias Se puede inyectar para poder
     *        probar la clasificacion sin base.
     * @param ProveedoresExclusion|null $exclusion Idem, para los excluidos por
     *        proveedor
     * @param ProveedoresRetenciones|null $retenciones Idem, para las alicuotas
     */
    function __construct($categorias = null, $exclusion = null, $retenciones = null) {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->categorias = ($categorias === null) ? new ProveedoresCategorias() : $categorias;

        /* Comparte el lector de CPA01 con el maestro: los dos validan codigos
           contra el mismo CPA01, y una sola instancia es un solo cache. */
        $this->exclusion = ($exclusion === null)
            ? new ProveedoresExclusion($this->categorias->tango())
            : $exclusion;

        $this->retenciones = ($retenciones === null) ? new ProveedoresRetenciones() : $retenciones;
    }

    /**
     * @return ProveedoresRetenciones Las alicuotas de retencion. Una instancia
     *         por pedido: su config() se lee una vez y la comparten la grilla,
     *         la importacion y los avisos.
     */
    public function retenciones() {
        return $this->retenciones;
    }

    /** @return ProveedoresCategorias El resolutor de categorias */
    public function categorias() {
        return $this->categorias;
    }

    /** @return ProveedoresExclusion Los proveedores excluidos por modulo */
    public function exclusion() {
        return $this->exclusion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_prov_locales.sql */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $cid = $this->conectar();
        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA_PAGO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de pagos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Si ya se corrio sql/cashflow_prov_locales_pago_medio.sql.
     *
     * Sin la tabla de pagos tampoco cuenta: una apertura sin la fila del
     * comprobante en la de pagos no tendria donde guardar su fecha mas tardia.
     *
     * @return bool
     */
    public function tablaMedioCreada() {
        if ($this->tablaMedio !== null) {
            return $this->tablaMedio;
        }

        if (!$this->tablaCreada()) {
            $this->tablaMedio = false;

            return false;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT OBJECT_ID('dbo." . self::TABLA_MEDIO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de medios de pago'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaMedio = ($row && $row['T'] !== null);

        return $this->tablaMedio;
    }

    /** @return array Avisos de configuracion pendiente (textos) */
    public function getAvisos() {
        return Aviso::textos($this->getAvisosConNivel());
    }

    /**
     * Los mismos avisos de getAvisos(), con su gravedad. Lo lee el tablero; la
     * pestana lee los textos.
     *
     * Sin la tabla de fechas todo cae en el vencimiento y nadie lo decidio:
     * critico. Las columnas y la tabla de exclusion que faltan no cambian
     * ningun numero de hoy, pero alguien tiene que correr el script: atencion.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function getAvisosConNivel() {
        $avisos = [];

        if (!$this->tablaCreada()) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central. '
                . 'Mientras tanto, todo se proyecta a la fecha de vencimiento y no se '
                . 'puede cargar ninguna fecha.');
        } elseif (!$this->tieneColumnaPago('FUENTE_FECHA')) {
            /* No bloquea nada: la columna Fecha de pago distingue igual la
               planilla de la carga manual, leyendo ORIGEN. Lo que se pierde es
               que esa marca siga siendo cierta el dia que alguien excluya una
               factura con fecha importada. Ver fuenteFecha(). */
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Falta '
                . 'sql/cashflow_prov_locales_fuente_fecha.sql. La columna Fecha de '
                . 'pago distingue igual lo importado de lo cargado a mano, pero una factura con '
                . 'fecha de la planilla que después se excluya o cambie de forma va a figurar '
                . 'como cargada a mano.');
        }

        /* Sin la tabla de medios no cambia ningun numero de hoy: lo unico que
           no se puede es cargar un comprobante en dos medios, y la importacion
           lo rechaza nombrando el script. Atencion, como los demas scripts
           que faltan. */
        if ($this->tablaCreada() && !$this->tablaMedioCreada()) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Falta ' . self::SCRIPT_MEDIO . '. Mientras '
                . 'tanto un comprobante no se puede pagar con dos medios: la importación rechaza '
                . 'las filas de dos medios, y todo lo demás funciona igual.');
        }

        $sinExclusion = $this->exclusion->avisoSinTabla();

        if ($sinExclusion !== '') {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $sinExclusion);
        }

        /* Sin el script de retenciones, o si no se pudieron leer, se proyecta el
           total: es el numero de antes, y se dice. */
        $avisos = array_merge($avisos, ProveedoresRetenciones::avisosConfig($this->retenciones->config()));

        return array_merge($avisos, $this->categorias->getAvisosConNivel());
    }

    /**
     * Los avisos del cronograma PROV_LOCALES de la ultima lectura de pendientes:
     * la configuracion por defecto, el calendario que no responde. Van aparte de
     * getAvisos() porque solo existen despues de getPendientes() con horizonte.
     *
     * @return array Textos
     */
    public function avisosCronograma() {
        return Aviso::textos($this->avisosCrono);
    }

    /**
     * Los mismos avisos de avisosCronograma(), con su gravedad y la seccion
     * "Cronograma". Lo lee el tablero.
     *
     * EL PREFIJO "Cronograma de Proveedores Locales:" SE SACO DEL TEXTO. En el
     * tablero el grupo ya dice la pestana y la seccion dice que son del
     * cronograma; en la pestana el usuario ya esta ahi, y cada texto nombra
     * el cronograma o el calendario por si solo.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function avisosCronogramaConNivel() {
        return $this->avisosCrono;
    }

    /**
     * Los pagos del cronograma PROV_LOCALES del horizonte MAS UN MES: una factura
     * que vence despues del ultimo pago del eje tiene que ir a un pago que existe,
     * aunque quede fuera del horizonte -y se informa como tal-. Ver
     * resolverFechaPago().
     *
     * SI NO SE PUEDE RESOLVER, null: las facturas quedan con su fecha de antes y
     * se avisa. Un cronograma roto no puede dejar la pestaña sin fechas.
     *
     * @param Horizonte $h
     * @return array|null
     */
    private function leerCronograma($h) {
        $this->avisosCrono = [];

        try {
            require_once __DIR__ . '/CronogramaDatos.php';

            $crono = (new CronogramaDatos())->paraHorizonte($h, 'PROV_LOCALES', 1);

            foreach ($crono['avisos_con_nivel'] as $a) {
                $this->avisosCrono[] = Aviso::nuevo($a['nivel'], $a['texto'], 'Cronograma');
            }

            return $crono['pagos'];
        } catch (Throwable $e) {
            $this->avisosCrono[] = Aviso::nuevo(Aviso::DANGER,
                'No se pudo resolver el cronograma de pagos de Proveedores '
                . 'Locales (' . $e->getMessage() . '). Las facturas se proyectan en su '
                . 'vencimiento, y lo vencido en el primer día del eje.', 'Cronograma');

            return null;
        }
    }

    /* ====================================================================
       LOS PENDIENTES
       ==================================================================== */

    /**
     * Las cuentas a pagar locales, una fila por vencimiento.
     *
     * Cada fila viene con su categoria YA RESUELTA -rubro economico, rubro,
     * centro de costos, si esta excluido y a que serie del tablero va-, con su
     * fecha de pago cargada si la tiene, y con la fecha resuelta con la que
     * entra al eje. Quien consume no vuelve a mirar el maestro ni a resolver
     * fechas: eso pasa una sola vez, aca.
     *
     * CON EL HORIZONTE, LAS FACTURAS DEL CRONOGRAMA VAN A SUS DIAS DE PAGO: se lee
     * el cronograma PROV_LOCALES de Parametros -> Generales y cada una se ubica en
     * su proximo pago (ver resolverFechaPago()). Sin el horizonte no se lee -lo
     * pide Tarjetas Pagos Corporativos, que usa FECHA_VTO y no esta fecha- y
     * 'Pago' queda como antes. FECHA_VTO es SIEMPRE el vencimiento crudo de
     * Tango, con o sin cronograma.
     *
     * @param string|null $hoy 'Y-m-d'; por defecto el dia de hoy. Se inyecta
     *        para poder probar sin que las pruebas caduquen.
     * @param Horizonte|null $h El eje, para resolver el cronograma
     * @return array Listado de vencimientos
     */
    public function getPendientes($hoy = null, $h = null) {
        $cid = $this->conectar();
        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $pagosCrono = ($h === null) ? null : $this->leerCronograma($h);

        /* LA SUBCONSULTA DE IMPUTACIONES ES LA PARTE DELICADA. Ver la tabla de
           signos en el encabezado de la clase y en
           sql/_referencia_tango_pendientes.sql.

           El join con CPA54 va por ID_CPA04 y no por (COD_PROVEE, T_COMP,
           N_COMP) como hace Tango: esa terna NO es unica en CPA04 -hay 16
           repetidas, todas del proveedor generico 000000- y multiplicaria filas.
           Hoy ninguna de esas 16 esta pendiente, asi que las dos formas dan lo
           mismo; ID_CPA04 esta poblado en las tres tablas y no depende de eso. */
        $sql = "
            SELECT
                a.COD_PROVEE,
                p.NOM_PROVEE,
                a.T_COMP,
                a.N_COMP,
                a.LEYENDA,
                CAST(a.FECHA_EMIS AS DATE) AS FECHA_EMIS,
                CAST(a.FECHA_CONT AS DATE) AS FECHA_CONT,
                CAST(v.FECHA_VTO  AS DATE) AS FECHA_VTO,
                CAST(v.IMPORT_VTO AS FLOAT) AS IMPORTE_VTO,
                CAST(ISNULL(im.IMPUTACIONES, 0) AS FLOAT) AS IMPUTACIONES,
                CAST(v.IMPORT_VTO + ISNULL(im.IMPUTACIONES, 0) AS FLOAT) AS IMPORTE_PENDIENTE
            FROM CPA04 a
            INNER JOIN CPA01 p ON p.COD_PROVEE = a.COD_PROVEE
            INNER JOIN CPA54 v ON v.ID_CPA04   = a.ID_CPA04
            LEFT  JOIN (
                    SELECT i.ID_CPA04, i.FECHA_VTO,
                           SUM(CASE i.T_COMP_CAN
                                    WHEN 'REC' THEN -(i.IMPORT_CAN)
                                    ELSE CASE tc.CRE_DEB
                                             WHEN 'D' THEN +(i.IMPORT_CAN)
                                             ELSE -(i.IMPORT_CAN)
                                         END
                               END) AS IMPUTACIONES
                    FROM CPA05 i
                    LEFT JOIN CPA21 tc ON tc.T_COMP = i.T_COMP_CAN
                    GROUP BY i.ID_CPA04, i.FECHA_VTO
                 ) im ON im.ID_CPA04 = v.ID_CPA04 AND im.FECHA_VTO = v.FECHA_VTO
            WHERE a.ESTADO      = 'PEN'
              AND v.ESTADO_VTO <> 'PAG'
              AND a.COD_PROVEE NOT LIKE '" . self::PREFIJO_EXTERIOR . "%'
              AND p.CLAUSULA   <> 1
              AND v.IMPORT_VTO + ISNULL(im.IMPUTACIONES, 0) <> 0
            ORDER BY v.FECHA_VTO, a.COD_PROVEE, a.N_COMP
        ";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al consultar las cuentas a pagar'));
        }

        $pagos = $this->getPagos();

        // Una consulta para todo el listado. Vacio si falta el script.
        $excluidosProv = $this->exclusion->vigentes(ProveedoresExclusion::MODULO_PROV_LOCALES);
        $items = [];

        /* Las alicuotas, leidas una vez para todo el listado. Sin el script -o
           si no se pueden leer- 'disponible' viene en false y el neto queda
           igual al pendiente: como antes. */
        $retCfg = $this->retenciones->config();

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = Planilla::codigo($row['COD_PROVEE']);
            $tComp = Planilla::codigo($row['T_COMP']);
            $nComp = Planilla::codigo($row['N_COMP']);
            $clave = self::clavePago($cod, $tComp, $nComp);

            $cat = $this->categorias->categoria($cod);
            $pago = isset($pagos[$clave]) ? $pagos[$clave] : null;

            $fechaVto = Horizonte::normalizarFecha($row['FECHA_VTO']);
            $fechaEmis = Horizonte::normalizarFecha($row['FECHA_EMIS']);

            /* La forma vigente decide si la factura se paga en el cronograma, y
               con eso si su fecha va al proximo dia de pago. */
            $enCronograma = ProveedoresCategorias::esDelCronograma(
                self::formaDelCronograma($cat, $pago));

            $resuelta = self::resolverFechaPago(
                ($pago === null) ? null : $pago['FECHA_PAGO'],
                $fechaVto,
                $fechaEmis,
                $cat['plazo_dias'],
                $hoyStr,
                $pagosCrono,
                $enCronograma
            );

            $forma = self::formaQueSeMuestra($cat, $pago);
            $excluidaManual = ($pago !== null && !empty($pago['EXCLUIDA']));
            $exclProv = isset($excluidosProv[$cod]) ? $excluidosProv[$cod] : null;

            $items[] = [
                'COD_PROVEE' => $cod,
                'RAZON_SOC' => trim((string) $row['NOM_PROVEE']),
                'T_COMP' => $tComp,
                'N_COMP' => $nComp,
                'LEYENDA' => trim((string) $row['LEYENDA']),
                'FECHA_EMIS' => $fechaEmis,
                'FECHA_CONT' => Horizonte::normalizarFecha($row['FECHA_CONT']),
                'FECHA_VTO' => $fechaVto,
                'IMPORTE_VTO' => round(floatval($row['IMPORTE_VTO']), 2),
                'IMPUTACIONES' => round(floatval($row['IMPUTACIONES']), 2),
                'IMPORTE_PENDIENTE' => round(floatval($row['IMPORTE_PENDIENTE']), 2),

                // La fecha con la que entra al eje, y de donde salio.
                'Pago' => $resuelta['fecha'],
                'PAGO_ORIGINAL' => $resuelta['original'],
                'ORIGEN_FECHA' => $resuelta['origen'],

                /* SI LA FECHA LA PUSO EL CRONOGRAMA: la del proximo dia de pago a
                   partir de PAGO_BASE -el vencimiento, la emision + plazo o la
                   fecha cargada vencida-. La columna Fecha de pago la marca
                   "crono" y el tooltip dice de donde sale la base. */
                'PAGO_CRONO' => $resuelta['crono'],
                'PAGO_BASE' => $resuelta['base'],
                'PAGO_CRONO_NOMBRE' => $resuelta['pago_nombre'],
                'PAGO_CRONO_A_MANO' => $resuelta['pago_override'],

                /* ORIGEN_FECHA con la carga desdoblada: MANUAL / ARCHIVO /
                   VENCIMIENTO / PLAZO / SIN_FECHA. Es lo que la columna Fecha
                   de pago muestra. ORIGEN_FECHA queda como estaba -CARGADA o
                   no- porque es lo que miran los indicadores, el fechado
                   masivo y los avisos, y para ellos las dos cargas son lo
                   mismo. Ver fuenteFecha(). */
                'FUENTE_FECHA' => self::fuenteFecha($resuelta['origen'], $pago),
                'VENCIDA' => $resuelta['vencida'],
                'SIN_FECHA_CARGADA' => $resuelta['sin_fecha_cargada'],

                // Lo cargado a mano, si hay.
                'FECHA_PAGO' => ($pago === null) ? null : $pago['FECHA_PAGO'],

                /* COMO SE LE PAGA A ESTE PROVEEDOR, segun el maestro. Es lo
                   UNICO que decide el filtro y la serie del tablero: ver
                   CRONOGRAMA, mas abajo. */
                'FORMA_PAGO_MAESTRO' => $cat['forma_pago'],

                /* La forma del pago REGISTRADO, que es otra cosa: es un hecho
                   sobre este comprobante, no una regla sobre el proveedor. Se
                   muestra en la columna Forma; si difiere de la del maestro,
                   eso es un dato para mirar -y capaz para corregir el maestro-,
                   no un motivo para cambiar lo que se filtra.

                   Sin pago registrado, o con uno que no dice la forma, se
                   muestra la del maestro: es lo mas cierto que se sabe. El
                   ORIGINAL sale de la MISMA fuente que el normalizado, porque
                   es el original DE ESE valor y no de otro. */
                'FORMA_PAGO' => $forma['normalizado'],
                'FORMA_PAGO_ORIG' => $forma['original'],
                'OBSERVACION' => ($pago === null) ? null : $pago['OBSERVACION'],
                'ESTADO_PAGO' => ($pago === null) ? null : $pago['ESTADO'],

                // La categoria, ya resuelta.
                'EN_MAESTRO' => $cat['en_maestro'],
                'RUBRO_ECONOMICO' => $cat['rubro_economico'],
                'RUBRO' => $cat['rubro'],
                'CENTRO_COSTOS' => $cat['centro_costos'],
                /* EXCLUIDO junta los dos motivos: el rubro del maestro -por
                   proveedor- y el tilde de esta factura. Los dos sacan el
                   importe de PAGOS_CRONO_OPERATIVOS, asi que quien pregunta
                   "esto va al cuadro operativo" tiene una sola respuesta.

                   EXCLUIDA_MANUAL viaja aparte porque NO hacen lo mismo en el
                   otro corte -sólo el tilde sale de PAGOS- y porque la pantalla
                   y los avisos tienen que poder decir cuanto es de cada uno. */
                'EXCLUIDO' => ($cat['excluido'] || $excluidaManual || $exclProv !== null),
                'EXCLUIDA_MANUAL' => $excluidaManual,
                'MOTIVO_EXCLUSION' => ($pago === null) ? null : $pago['MOTIVO_EXCLUSION'],

                /* Quien toco por ultima vez los overrides de este comprobante -la
                   fecha, la forma, la exclusion- y cuando. Es una fila por
                   comprobante, asi que es UNA modificacion para las tres. */
                'PAGO_USUARIO' => ($pago === null) ? null : $pago['USUARIO'],
                'PAGO_FECHA_MODIF' => ($pago === null) ? null : $pago['FECHA_MODIF'],

                /* EL PROVEEDOR ENTERO ESTA EXCLUIDO de Proveedores Locales,
                   porque su deuda ya se considera en otra pestana. Alcanza a
                   toda su deuda. Entra en EXCLUIDO -el segundo corte- igual que
                   la exclusion por factura, y en el primer corte va a su propia
                   serie. Si ademas la factura esta excluida a mano, gana el
                   proveedor: el importe se cuenta una vez. Ver
                   ProveedoresProvider::seriesDeItem() y ProveedoresExclusion. */
                'EXCLUIDO_PROVEEDOR' => ($exclProv !== null),
                'MOTIVO_EXCLUSION_PROVEEDOR' => ($exclProv === null) ? null : $exclProv['MOTIVO'],
                'SERIE' => $cat['serie'],

                /* EL OVERRIDE DE ESTA FACTURA, si alguien lo puso. Es una REGLA
                   -con que forma hay que tratar a este comprobante- puesta a
                   mano, y no toca el maestro: las otras facturas del mismo
                   proveedor siguen como estaban. */
                'FORMA_PAGO_CRONOGRAMA' => ($pago === null)
                    ? null : $pago['FORMA_PAGO_CRONOGRAMA'],

                /* LA QUE DECIDE, ya resuelta: el override si lo hay, y si no la
                   del maestro. Viaja calculada y no se rehace en el navegador
                   por el mismo motivo que todo lo demas de esta fila: la regla
                   se escribe una vez, en formaDelCronograma(), y la grilla
                   muestra lo que decide en vez de una aproximacion suya. */
                'FORMA_PAGO_VIGENTE' => self::formaDelCronograma($cat, $pago),

                /* Si se gestiona desde el cronograma de pagos -echeq,
                   transferencia, o forma desconocida-. Es lo que decide si
                   entra a la fila del tablero y lo que la pestaña filtra por
                   defecto. Ver ProveedoresCategorias::esDelCronograma().

                   SALE DE UNA REGLA, NO DE UN HECHO, y eso no cambio. Lo que
                   cambio es que la regla tiene dos escalones: el override de
                   ESTA factura si lo hay, y si no la forma del maestro, que es
                   una propiedad del PROVEEDOR.

                   NO SALE de FORMA_PAGO. Esa es el HECHO -por que via salio ese
                   pago- y la escribe la importacion de la planilla de pagos en
                   todas sus filas: si decidiera, importar reclasificaria
                   comprobantes dentro y fuera del cashflow sin que nadie lo
                   pida. Ver Proveedores::formaDelCronograma(). */
                'CRONOGRAMA' => $enCronograma,

                // La apertura en dos medios, si la hay: ver abrirEnMedios().
                'APERTURA' => null,
                'APERTURA_DESCUADRADA' => null
            ];

            /* LA RETENCION Y EL NETO A PAGAR, con la fecha con la que cae en el
               eje. Ver conRetencion(). */
            $items[count($items) - 1] = self::conRetencion($items[count($items) - 1], $retCfg, $hoyStr);
        }

        sqlsrv_free_stmt($stmt);

        /* LOS COMPROBANTES PAGADOS CON DOS MEDIOS SE PARTEN, Y SOLO CON EL
           HORIZONTE. Partirlos es decir en que columna del eje cae cada parte,
           que es lo que piden la grilla y el tablero. Sin el horizonte -Tarjetas
           Pagos Corporativos y la previsualizacion de la importacion- se sigue
           devolviendo una fila por vencimiento, como siempre: Tarjetas arma su
           clave de cuota con comprobante + vencimiento y dos filas con la misma
           clave le romperian el circuito, y la previsualizacion necesita el
           pendiente del comprobante entero. Mismo criterio que el cronograma,
           que tampoco se resuelve sin el horizonte. */
        if ($h !== null) {
            $items = self::abrirEnMedios($items, $this->getAperturas(), $hoyStr, $pagosCrono, $retCfg);
        }

        return $items;
    }

    /**
     * Le agrega a una fila de getPendientes() su retencion y su NETO A PAGAR.
     * Es el unico lugar donde una fila recibe esos campos: lo usan
     * getPendientes() y abrirEnMedios().
     *
     *   RETENCION              lo que devolvio ProveedoresRetenciones::calcular():
     *                          base, cada impuesto con su alicuota y de donde
     *                          sale, el motivo si no lleva ninguna, y el neto
     *   RETENIDO               el total retenido EN ESTA FILA
     *   RETENIDO_POR_IMPUESTO  codigo => importe, de esta fila
     *   IMPORTE_A_PAGAR        lo que sale de la caja: el neto donde hay
     *                          retencion, el pendiente donde no
     *
     * IMPORTE_PENDIENTE NO CAMBIA: sigue siendo el total que debe la factura,
     * y lo siguen leyendo Tarjetas Pagos Corporativos, la importacion y las
     * columnas Importe y Pendiente. Lo que va al eje y al tablero es
     * IMPORTE_A_PAGAR.
     *
     * LA FECHA DE LA ALICUOTA ES LA DE PAGO, la misma con la que la fila cae en
     * el eje ('Pago'): un cambio de alicuota rige desde una fecha. Sin fecha
     * -SIN_FECHA- se usa la de hoy, que es lo mas cierto que se sabe.
     *
     * Estatica y pura.
     *
     * @param array $item
     * @param array|null $config De ProveedoresRetenciones::config(); null es
     *        "sin retenciones", el comportamiento de antes
     * @param string $hoy 'Y-m-d'
     * @param string|null $fecha La fecha de la alicuota, si no es 'Pago'
     * @return array La fila con los cuatro campos
     */
    public static function conRetencion($item, $config, $hoy, $fecha = null) {
        if ($fecha === null) {
            $fecha = !empty($item['Pago']) ? $item['Pago'] : $hoy;
        }

        $r = ProveedoresRetenciones::calcular($item['IMPORTE_PENDIENTE'], $item['COD_PROVEE'],
            $item['T_COMP'], !empty($item['CRONOGRAMA']), $fecha,
            ($config === null) ? ['disponible' => false] : $config);

        $porImpuesto = [];

        foreach ($r['impuestos'] as $codigo => $i) {
            $porImpuesto[$codigo] = $i['retencion'];
        }

        $item['RETENCION'] = $r;
        $item['RETENIDO'] = $r['retenido'];
        $item['RETENIDO_POR_IMPUESTO'] = $porImpuesto;
        $item['IMPORTE_A_PAGAR'] = $r['neto'];

        return $item;
    }

    /**
     * Parte en sus medios los comprobantes que se pagan con dos. ES LA REGLA
     * DE COMO ENTRA AL EJE UNA APERTURA, escrita una sola vez.
     *
     * UNA FILA POR PARTE, con IMPORTE_PENDIENTE = el importe de la parte y su
     * fecha resuelta con la jerarquia de siempre (resolverFechaPago()), cada
     * una por separado: una parte con fecha futura se respeta tal cual, y una
     * que ya paso y es del cronograma va al proximo dia de pago. Que entre al
     * cronograma lo sigue decidiendo la forma VIGENTE del comprobante
     * (CRONOGRAMA, que ya viene resuelto en la fila), no la de la parte.
     *
     * LAS SERIES SIGUEN CERRANDO AL CENTAVO. La planilla tolera un peso entre
     * la suma de las partes y el pendiente; lo que se proyecta, no. La ultima
     * parte se lleva el resto -pendiente menos las anteriores-, asi que el
     * comprobante suma exactamente lo mismo que sin apertura y lo unico que
     * cambia es en que columna cae cada peso. IMPORTE_VTO e IMPUTACIONES se
     * reparten en la misma proporcion, con el mismo criterio, para que el pie
     * de "Importe" de la grilla tampoco se mueva.
     *
     * TODO LO DEMAS DE LA FILA SE COPIA: la exclusion, el override de forma,
     * la observacion y el rubro son del comprobante. Excluirlo excluye las dos
     * partes.
     *
     * SI LA APERTURA YA NO DESCRIBE EL COMPROBANTE, NO SE PARTE. Pasa cuando
     * en Tango se imputo un pago parcial -el pendiente dejo de coincidir con
     * la suma, con mas de un peso de diferencia- o cuando el comprobante paso
     * a tener mas de un vencimiento. Partir en ese caso proyectaria importes
     * que ya no se deben. Se deja la fila como esta: su fecha cargada es la
     * MAS TARDIA de las dos partes (ver camposDeImportacion()), asi que todo lo
     * que queda pendiente se proyecta ahi, con la jerarquia de siempre. Y se
     * marca, para el aviso de avisosPendientesConNivel() y para la grilla.
     *
     * LAS PARTES SON IMPORTES NETOS. La planilla trae lo que se paga por cada
     * medio, y eso es despues de retenciones: las dos partes suman el NETO A
     * PAGAR del comprobante, no su pendiente. Por eso:
     *
     *   - la retencion se calcula sobre el COMPROBANTE ENTERO, una sola vez,
     *     a la fecha MAS TARDIA de las dos partes (la que guarda la tabla de
     *     pagos): una orden de pago retiene sobre la factura, no sobre cada
     *     medio;
     *   - el descuadre se mide contra el neto: un pago parcial en Tango baja el
     *     pendiente, la retencion se recalcula sobre el pendiente nuevo y la
     *     apertura deja de coincidir;
     *   - la ultima parte se lleva el resto -neto menos las anteriores-, asi
     *     que el cuadro cierra al centavo;
     *   - LA RETENCION VA EN LA PRIMERA PARTE, entera. El pendiente de la
     *     primera es su neto mas la retencion, y el de la segunda, su neto: las
     *     dos siguen sumando el pendiente, y los totales por impuesto no se
     *     duplican.
     *
     * Sin alicuotas el neto es el pendiente y todo funciona como antes.
     *
     * Estatica y pura.
     *
     * @param array $items Filas de getPendientes()
     * @param array $aperturas Mapa de getAperturas(): clave => medios
     * @param string $hoy 'Y-m-d'
     * @param array|null $pagosCrono Pagos del cronograma PROV_LOCALES
     * @param array|null $retCfg De ProveedoresRetenciones::config(); null = sin
     *        retenciones
     * @return array Las filas, con las abiertas reemplazadas por sus partes
     */
    public static function abrirEnMedios($items, $aperturas, $hoy, $pagosCrono = null, $retCfg = null) {
        if (empty($aperturas)) {
            return $items;
        }

        $porClave = [];

        foreach ($items as $i => $item) {
            $porClave[self::clavePago($item['COD_PROVEE'], $item['T_COMP'], $item['N_COMP'])][] = $i;
        }

        $reemplazo = [];

        foreach ($aperturas as $clave => $medios) {
            if (!isset($porClave[$clave]) || count($medios) < 2) {
                continue;
            }

            $indices = $porClave[$clave];
            $pendiente = 0.0;

            foreach ($indices as $i) {
                $pendiente += floatval($items[$i]['IMPORTE_PENDIENTE']);
            }

            $suma = 0.0;
            $ultimaFecha = null;

            foreach ($medios as $m) {
                $suma += floatval($m['importe']);

                if ($ultimaFecha === null || $m['fecha'] > $ultimaFecha) {
                    $ultimaFecha = $m['fecha'];
                }
            }

            $info = self::infoApertura($medios);

            /* La retencion del comprobante entero, a la fecha mas tardia. Con
               mas de un vencimiento no se parte, y cada cuota conserva la suya. */
            $entero = (count($indices) === 1)
                ? self::conRetencion($items[$indices[0]], $retCfg, $hoy, $ultimaFecha)
                : null;
            $neto = ($entero === null) ? $pendiente : floatval($entero['IMPORTE_A_PAGAR']);

            if ($entero === null
                || abs(round($neto - $suma, 2)) > self::TOLERANCIA_IMPORTE) {
                foreach ($indices as $i) {
                    $items[$i]['APERTURA_DESCUADRADA'] = array_merge($info, [
                        'suma' => round($suma, 2),
                        'pendiente' => round($pendiente, 2),
                        'neto' => round($neto, 2),
                        'vencimientos' => count($indices)
                    ]);
                }

                continue;
            }

            $fila = $entero;
            $retenido = floatval($entero['RETENIDO']);
            $partes = [];
            $acumulado = ['IMPORTE_PENDIENTE' => 0.0, 'IMPORTE_A_PAGAR' => 0.0, 'IMPORTE_VTO' => 0.0,
                          'IMPUTACIONES' => 0.0];
            $n = count($medios);

            foreach (array_values($medios) as $k => $m) {
                $parte = $fila;
                $ultima = ($k === $n - 1);
                $aPagar = $ultima
                    ? round($neto - $acumulado['IMPORTE_A_PAGAR'], 2)
                    : round(floatval($m['importe']), 2);

                /* El pendiente de la parte: su neto, y en la primera ademas la
                   retencion entera. La ultima se lleva el resto, asi que las
                   partes suman el pendiente al centavo. */
                $importe = $ultima
                    ? round($pendiente - $acumulado['IMPORTE_PENDIENTE'], 2)
                    : round($aPagar + ($k === 0 ? $retenido : 0.0), 2);
                $proporcion = ($pendiente != 0) ? $importe / $pendiente : 0.0;

                $parte['IMPORTE_A_PAGAR'] = $aPagar;
                $parte['RETENIDO'] = ($k === 0) ? $retenido : 0.0;
                $parte['RETENIDO_POR_IMPUESTO'] = ($k === 0) ? $entero['RETENIDO_POR_IMPUESTO'] : [];
                $acumulado['IMPORTE_A_PAGAR'] += $aPagar;

                foreach (['IMPORTE_VTO', 'IMPUTACIONES'] as $col) {
                    $parte[$col] = $ultima
                        ? round(floatval($fila[$col]) - $acumulado[$col], 2)
                        : round(floatval($fila[$col]) * $proporcion, 2);
                    $acumulado[$col] += $parte[$col];
                }

                $parte['IMPORTE_PENDIENTE'] = $importe;
                $acumulado['IMPORTE_PENDIENTE'] += $importe;

                $r = self::resolverFechaPago($m['fecha'], $fila['FECHA_VTO'], $fila['FECHA_EMIS'],
                    null, $hoy, $pagosCrono, !empty($fila['CRONOGRAMA']));

                $parte['Pago'] = $r['fecha'];
                $parte['PAGO_ORIGINAL'] = $r['original'];
                $parte['ORIGEN_FECHA'] = $r['origen'];
                $parte['PAGO_CRONO'] = $r['crono'];
                $parte['PAGO_BASE'] = $r['base'];
                $parte['PAGO_CRONO_NOMBRE'] = $r['pago_nombre'];
                $parte['PAGO_CRONO_A_MANO'] = $r['pago_override'];
                $parte['VENCIDA'] = $r['vencida'];
                $parte['SIN_FECHA_CARGADA'] = $r['sin_fecha_cargada'];
                $parte['FECHA_PAGO'] = $m['fecha'];
                $parte['FUENTE_FECHA'] = 'ARCHIVO';
                $parte['APERTURA'] = array_merge($info, ['parte' => $k + 1, 'de' => $n]);

                $partes[] = $parte;
            }

            $reemplazo[$indices[0]] = $partes;
        }

        if (empty($reemplazo)) {
            return $items;
        }

        $salida = [];

        foreach ($items as $i => $item) {
            if (isset($reemplazo[$i])) {
                foreach ($reemplazo[$i] as $p) {
                    $salida[] = $p;
                }
            } else {
                $salida[] = $item;
            }
        }

        return $salida;
    }

    /**
     * Lo que la grilla dice de una apertura: los medios -fecha, forma,
     * importe-, quien la cargo y cuando. Va igual en las dos partes.
     *
     * @param array $medios
     * @return array
     */
    private static function infoApertura($medios) {
        $lista = [];
        $usuario = null;
        $fecha = null;

        foreach ($medios as $m) {
            $lista[] = ['orden' => $m['orden'], 'fecha' => $m['fecha'], 'forma' => $m['forma'],
                        'importe' => round(floatval($m['importe']), 2)];
            $usuario = isset($m['usuario']) ? $m['usuario'] : $usuario;
            $fecha = isset($m['fecha_modif']) ? $m['fecha_modif'] : $fecha;
        }

        return ['medios' => $lista, 'usuario' => $usuario, 'fecha_modif' => $fecha];
    }

    /**
     * Si una fila de getPendientes() CUENTA COMO UN VENCIMIENTO en los
     * contadores. Las dos partes de un comprobante abierto son un solo
     * vencimiento partido en dos columnas: la segunda suma su importe pero no
     * se cuenta. Es la regla de los indicadores de la pestaña, de los avisos y
     * de la selección, escrita una vez.
     *
     * Estatica y pura.
     *
     * @param array $item
     * @return bool
     */
    public static function cuentaComoVencimiento($item) {
        return empty($item['APERTURA']) || intval($item['APERTURA']['parte']) === 1;
    }

    /**
     * Las aperturas vigentes, indexadas por comprobante. Vacio sin la tabla.
     *
     * Cada una viene ORDENADA por ORDEN, que es el que se decidio al importar:
     * la fecha mas temprana primero y, con la misma fecha, la fila que venia
     * antes en el archivo.
     *
     * @return array Mapa 'COD|T|N' => [['orden', 'fecha', 'forma', 'forma_orig',
     *               'importe', 'usuario', 'fecha_modif'], ...]
     */
    public function getAperturas() {
        if (!$this->tablaMedioCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, T_COMP, N_COMP, ORDEN, FECHA_PAGO, FORMA_PAGO, FORMA_PAGO_ORIG,
                    CAST(IMPORTE AS FLOAT) AS IMPORTE, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA_MEDIO . "
             WHERE VIGENTE = 1
             ORDER BY COD_PROVEE, T_COMP, N_COMP, ORDEN");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los pagos con dos medios'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[self::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP'])][] = [
                'orden' => intval($row['ORDEN']),
                'fecha' => Horizonte::normalizarFecha($row['FECHA_PAGO']),
                'forma' => $row['FORMA_PAGO'],
                'forma_orig' => $row['FORMA_PAGO_ORIG'],
                'importe' => round(floatval($row['IMPORTE']), 2),
                'usuario' => $row['USUARIO_MODIF'],
                'fecha_modif' => ($row['FECHA_MODIF'] instanceof DateTime)
                    ? $row['FECHA_MODIF']->format('Y-m-d H:i:s') : $row['FECHA_MODIF']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Con que forma de pago se decide si este comprobante entra al cronograma.
     *
     * SON DOS ESCALONES DE LA MISMA REGLA, y el orden importa:
     *
     *   1. el OVERRIDE de esta factura, si alguien lo puso a mano
     *   2. si no, la forma del MAESTRO, que es como se le paga a ese proveedor
     *
     * NO ENTRA FORMA_PAGO. Esa es un HECHO -por que via salio o va a salir ese
     * pago- y la escribe la importacion de la planilla en todas sus filas. Si
     * decidiera, importar la planilla de pagos pasaria a mover comprobantes
     * dentro y fuera del cashflow sin que nadie lo haya pedido. El filtro mira
     * una regla, no un hecho; lo que se agrego es un escalon mas fino de la
     * regla, no un cambio de criterio.
     *
     * EL OVERRIDE NO TOCA EL MAESTRO: las otras facturas del mismo proveedor
     * siguen clasificandose por la forma del maestro.
     *
     * Estatica y pura.
     *
     * @param array $cat Lo que devolvio ProveedoresCategorias::categoria()
     * @param array|null $pago La fila de override, si hay
     * @return string|null La forma con la que hay que decidir, o null si no se
     *         sabe -y entonces el comprobante entra igual y se marca-
     */
    public static function formaDelCronograma($cat, $pago) {
        if ($pago !== null && !empty($pago['FORMA_PAGO_CRONOGRAMA'])) {
            return $pago['FORMA_PAGO_CRONOGRAMA'];
        }

        return $cat['forma_pago'];
    }

    /**
     * Que forma de pago se MUESTRA en la columna Forma, y con que original.
     *
     * OJO: esto NO decide nada. Lo que entra al cronograma lo decide la forma
     * del maestro y solo esa -ver la seccion "DOS FORMAS DE PAGO QUE NO SON LA
     * MISMA COSA" del encabezado-. Esto es la columna de la grilla.
     *
     * Manda el pago registrado si lo hay, porque es un hecho sobre ESTE
     * comprobante; si no hay, o si el pago no dice la forma, se muestra la del
     * maestro, que es lo mas cierto que se sabe.
     *
     * EL ORIGINAL SALE DE LA MISMA FUENTE QUE EL NORMALIZADO. Mezclarlos
     * -normalizado del pago, original del maestro- mostraria el original de un
     * valor que no es el que se esta mostrando.
     *
     * SI EL NORMALIZADO ES null, EL ORIGINAL ES UN TYPO Y NO OTRA COSA. Antes
     * habia un segundo caso -una forma valida que habia quedado sin normalizar
     * porque el maestro se importo antes de que estuviera declarada- y habia
     * que distinguirlo, porque se arreglaba reimportando y no corrigiendo la
     * planilla. Ese caso ya no existe: la normalizacion se calcula al leer
     * contra la lista de hoy. Ver ProveedoresCategorias::mapa().
     *
     * Estatica y pura.
     *
     * @param array $cat Lo que devolvio ProveedoresCategorias::categoria()
     * @param array|null $pago La fila de pago, si hay
     * @return array ['normalizado', 'original']
     */
    public static function formaQueSeMuestra($cat, $pago) {
        $delPago = ($pago !== null && $pago['FORMA_PAGO'] !== null);

        $normalizado = $delPago ? $pago['FORMA_PAGO'] : $cat['forma_pago'];
        $original = $delPago
            ? $pago['FORMA_PAGO_ORIG']
            : (isset($cat['forma_pago_orig']) ? $cat['forma_pago_orig'] : null);

        /* Un pago que no dice la forma pero si trae su original -un valor que
           no matcheo al importar la planilla de pagos- conserva ese original:
           es lo que hay que mostrar para poder corregirlo. */
        if (!$delPago && $pago !== null && $pago['FORMA_PAGO_ORIG'] !== null
            && trim((string) $pago['FORMA_PAGO_ORIG']) !== '') {
            $original = $pago['FORMA_PAGO_ORIG'];
        }

        return [
            'normalizado' => $normalizado,
            'original' => $original
        ];
    }

    /* ====================================================================
       LA JERARQUIA DE LA FECHA DE PAGO
       ==================================================================== */

    /**
     * Con que fecha entra un vencimiento al eje. ES LA JERARQUIA, escrita una
     * sola vez:
     *
     *   1. la FECHA DE PAGO CARGADA, si hay una para ese comprobante
     *   2. si no, la FECHA DE VENCIMIENTO de Tango
     *   3. si no hay vencimiento usable, EMISION + PLAZO del maestro
     *   4. si nada de eso alcanza, sin fecha
     *
     * Y ENCIMA DE ESO, EL CRONOGRAMA DE PAGOS (PROV_LOCALES de Parametros ->
     * Generales, por defecto el 2do y el 4to miercoles), solo para las facturas
     * cuya forma de pago vigente es del cronograma (esDelCronograma(): echeq,
     * transferencia, o forma desconocida):
     *
     *   - sin fecha cargada (VENCIMIENTO o PLAZO), y
     *   - con fecha cargada que YA PASO
     *
     * se pagan en el PRIMER PAGO DEL CRONOGRAMA con fecha efectiva >= max(fecha
     * base, hoy). La fecha base es el vencimiento, la emision + plazo, o la fecha
     * cargada vencida. Lo resuelve CronogramaPagos::proximoPago(), que es puro y
     * recibe los pagos ya resueltos -corridos al habil anterior y con los
     * overrides aplicados-: una factura que vence entre el pago corrido y el
     * teorico va al siguiente, y eso es correcto.
     *
     * NO SE GRABA NADA POR FACTURA. Las facturas se asignan a un PAGO, no a una
     * fecha guardada: mover ese pago a mano en Parametros mueve todas las
     * facturas que caen en el, y esto se calcula de nuevo en cada lectura.
     *
     * UNA FECHA CARGADA FUTURA SE RESPETA TAL CUAL. La cargo una persona para un
     * dia que todavia no llego; moverla al dia de pago seria pisar su decision.
     * Una cargada que ya paso, en cambio, describe un pago que no ocurrio -la
     * factura sigue pendiente en Tango- y queda en el proximo dia de pago.
     *
     * LO VENCIDO YA NO SE APILA EN EL PRIMER DIA DEL EJE, salvo que esa factura
     * no sea del cronograma: los debitos automaticos, la caja y TARJETA CORP no se
     * pagan en un dia de pago, conservan su fecha, y su vencido sigue en el
     * primer dia del eje como antes (Ingresos::ubicarCobroVencido()). Nada DEL
     * CRONOGRAMA cae en el primer dia del eje salvo que ese dia sea de pago.
     *
     * EL PAGO DE HOY CUENTA, y eso es distinto de Logistica, donde el pago de hoy
     * no se proyecta porque ya se hizo. Aca la factura sigue pendiente en Tango,
     * asi que todavia no se pago: cuando se pague, sale de pendientes y deja de
     * estar en el eje.
     *
     * SI NO HAY NINGUN PAGO DESPUES DE LA FECHA BASE se sigue como antes: la
     * fecha queda donde estaba. Los pagos traen un mes despues del horizonte
     * (CronogramaDatos::paraHorizonte(..., 1)), asi que una factura que vence
     * despues del ultimo pago del eje recibe uno que queda fuera del horizonte y
     * se informa como tal. Que no haya ninguno solo pasa con una base mas alla de
     * ese mes extra, que ya esta fuera del horizonte.
     *
     * $pagosCrono null ES "SIN CRONOGRAMA": el comportamiento de antes, para quien
     * no lo necesita (Tarjetas Pagos Corporativos lee FECHA_VTO y no esta fecha).
     *
     * LO QUE NO CAMBIA: 'origen' sigue diciendo CARGADA / VENCIMIENTO / PLAZO /
     * SIN_FECHA, y 'sin_fecha_cargada' sigue siendo "vencido sin que nadie haya
     * dicho cuando se paga". Los indicadores, el fechado masivo y la conciliacion
     * preguntan "¿alguien decidio una fecha?", y esa respuesta no depende de en
     * que columna cae el importe.
     *
     * EL VENCIMIENTO VA ANTES QUE EL PLAZO, y no al reves. El vencimiento es un
     * dato del comprobante; el plazo es una costumbre del proveedor. Cuando los
     * dos existen, el que describe a ESTA factura es el vencimiento. El plazo
     * esta para cuando no hay vencimiento utilizable, que en esta base es raro
     * -Tango usa 1800-01-01 como centinela de "sin fecha"- pero pasa.
     *
     * Ademas el plazo esta vacio en el 62% del maestro y cuando esta no siempre
     * es un numero: CONTADO, DEBITO, '30 DIAS'. Ver
     * ProveedoresCategorias::plazoEnDias(), que devuelve null cuando el plazo no
     * permite calcular una fecha, y null hace caer al escalon siguiente.
     *
     * NO HAY TECHO DE ANTIGUEDAD, a diferencia de cobranzas. Ver el encabezado
     * de la clase.
     *
     * Estatica y pura: es la regla mas facil de romper del modulo.
     *
     * @param string|null $fechaPago Fecha cargada a mano, 'Y-m-d'
     * @param string|null $fechaVto Vencimiento de Tango, 'Y-m-d'
     * @param string|null $fechaEmis Emision, 'Y-m-d'
     * @param int|null $plazoDias Plazo del maestro en dias, o null
     * @param string $hoy Primer dia del eje, 'Y-m-d'
     * @param array|null $pagosCrono Pagos del cronograma PROV_LOCALES ya
     *        resueltos (CronogramaPagos::paraHorizonte()['pagos']), o null
     * @param bool $delCronograma Si la forma de pago vigente es del cronograma
     * @return array ['fecha', 'original', 'origen', 'vencida', 'sin_fecha_cargada',
     *                'crono' => bool, 'base', 'pago_nombre', 'pago_override']
     */
    public static function resolverFechaPago($fechaPago, $fechaVto, $fechaEmis,
                                             $plazoDias, $hoy, $pagosCrono = null,
                                             $delCronograma = false) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $cargada = self::fechaUtil($fechaPago);
        $usaCrono = ($pagosCrono !== null && $delCronograma);

        /* 1. La fecha cargada manda. Si ya paso y la factura es del cronograma,
              va al proximo pago; si es futura, se respeta tal cual. */
        if ($cargada !== null) {
            $r = [
                'fecha' => $cargada,
                'original' => $cargada,
                'origen' => 'CARGADA',
                'vencida' => ($cargada < $hoyStr),
                'sin_fecha_cargada' => false
            ];

            return ($usaCrono && $r['vencida'])
                ? self::alCronograma($r, $cargada, $pagosCrono, $hoyStr)
                : self::sinCronograma($r, $cargada);
        }

        /* 2. El vencimiento de Tango. */
        $base = self::fechaUtil($fechaVto);
        $origen = 'VENCIMIENTO';

        /* 3. Emision + plazo del maestro, solo si no hubo vencimiento usable. */
        if ($base === null) {
            $emis = self::fechaUtil($fechaEmis);

            if ($emis !== null && $plazoDias !== null) {
                $d = new DateTime($emis);
                $d->setTime(0, 0, 0);
                $d->modify('+' . intval($plazoDias) . ' days');
                $base = $d->format('Y-m-d');
                $origen = 'PLAZO';
            }
        }

        /* 4. Sin nada con que ubicarlo. Se transporta asi y el eje lo informa en
              'sin_fecha' en lugar de perderlo. Igual con o sin cronograma: sin
              fecha base no hay "proximo pago" de nada. */
        if ($base === null) {
            return self::sinCronograma([
                'fecha' => null,
                'original' => null,
                'origen' => 'SIN_FECHA',
                'vencida' => false,
                'sin_fecha_cargada' => true
            ], null);
        }

        /* Fuera del cronograma, lo vencido se ubica en el primer dia del eje SIN
           TECHO de antiguedad: se reusa la regla de las pestanas de cobranza con
           $diasAtras en null, que es como la usa Exportaciones Tasky. Ver
           Ingresos::ubicarCobroVencido(). */
        $ubic = Ingresos::ubicarCobroVencido($base, $hoyStr, null, false);

        $r = [
            'fecha' => $ubic['fecha'],
            'original' => $ubic['original'],
            'origen' => $origen,
            'vencida' => $ubic['vencida'],
            // Lo que alimenta el indicador: vencido y sin que nadie haya dicho
            // cuando se paga. No cambia con el cronograma.
            'sin_fecha_cargada' => $ubic['vencida']
        ];

        return $usaCrono
            ? self::alCronograma($r, $base, $pagosCrono, $hoyStr)
            : self::sinCronograma($r, $base);
    }

    /**
     * Lleva una fecha resuelta al proximo pago del cronograma. Si no hay ninguno
     * despues de la base, la deja como estaba (ver resolverFechaPago()).
     *
     * @return array
     */
    private static function alCronograma($r, $base, $pagosCrono, $hoy) {
        $pago = CronogramaPagos::proximoPago($base, $pagosCrono, $hoy);

        if ($pago === null) {
            return self::sinCronograma($r, $base);
        }

        $r['fecha'] = $pago['fecha'];
        $r['crono'] = true;
        $r['base'] = $base;
        $r['pago_nombre'] = isset($pago['nombre']) ? $pago['nombre'] : null;
        $r['pago_override'] = !empty($pago['override']);

        return $r;
    }

    /** Completa una fecha resuelta que no paso por el cronograma. @return array */
    private static function sinCronograma($r, $base) {
        $r['crono'] = false;
        $r['base'] = $base;
        $r['pago_nombre'] = null;
        $r['pago_override'] = false;

        return $r;
    }

    /** De donde puede salir una fecha CARGADA */
    const FUENTES_CARGA = ['MANUAL', 'ARCHIVO'];

    /**
     * De donde sale la fecha que muestra la columna Fecha de pago.
     *
     * Es ORIGEN_FECHA con la carga desdoblada en sus dos caminos:
     *
     *   MANUAL       la tipeo una persona, en la grilla o con el fechado masivo
     *   ARCHIVO      vino de la planilla de pagos importada
     *   VENCIMIENTO  nadie cargo nada: es el vencimiento de Tango
     *   PLAZO        sin vencimiento usable: emision + plazo del maestro
     *   SIN_FECHA    no hay con que ubicarlo
     *
     * NO REEMPLAZA A ORIGEN_FECHA, que sigue diciendo CARGADA para las dos
     * primeras. Los indicadores, el fechado masivo, el aviso de vencidos sin
     * fecha y la conciliacion preguntan "¿alguien decidio una fecha?", y para
     * esa pregunta una fecha tipeada y una importada son lo mismo. Partir
     * CARGADA en dos habria obligado a cambiar cada uno de esos lugares para que
     * sigan diciendo lo que ya dicen.
     *
     * LA CARGA SALE DE FUENTE_FECHA, Y SI NO ESTA, DE ORIGEN. FUENTE_FECHA es la
     * columna que se escribe junto con la fecha y nada mas; ORIGEN describe la
     * ultima escritura sobre la fila, que puede haber sido excluir la factura o
     * cambiarle la forma. Sin el script la unica fuente es ORIGEN, que hoy es
     * exacta. Un valor que no es ninguno de los dos se muestra como MANUAL: una
     * fecha que nadie sabe de donde salio no se puede atribuir a la planilla.
     *
     * Estatica y pura.
     *
     * @param string $origen El 'origen' de resolverFechaPago()
     * @param array|null $pago La fila de overrides, si hay
     * @return string
     */
    public static function fuenteFecha($origen, $pago) {
        if ($origen !== 'CARGADA') {
            return $origen;
        }

        foreach (['FUENTE_FECHA', 'ORIGEN'] as $campo) {
            if ($pago !== null && isset($pago[$campo])
                && in_array($pago[$campo], self::FUENTES_CARGA, true)) {
                return $pago[$campo];
            }
        }

        return 'MANUAL';
    }

    /**
     * Normaliza una fecha y descarta los centinelas de Tango.
     *
     * Tango usa 1800-01-01 para decir "sin fecha", y la consulta de referencia
     * lo convierte a NULL con un CASE. Aca se hace lo mismo pero en un solo
     * lugar: una fecha del ano 1800 no es una fecha de pago, es la ausencia de
     * una.
     *
     * @param mixed $valor
     * @return string|null 'Y-m-d'
     */
    public static function fechaUtil($valor) {
        $f = Horizonte::normalizarFecha($valor);

        if ($f === null || $f <= '1900-01-01') {
            return null;
        }

        return $f;
    }

    /* ====================================================================
       AVISOS
       ==================================================================== */

    /**
     * Lo que NO se ve en los numeros y hay que decir.
     *
     * EL PRIMERO ES EL MAS IMPORTANTE DEL MODULO: cuanto hay vencido sin que
     * nadie haya dicho cuando se paga. Va en DOS PARTES desde que el cronograma
     * de Proveedores Locales reubica lo vencido: lo del cronograma ya no se apila
     * en el primer dia del eje -va al proximo dia de pago- y lo de las otras
     * formas si, como antes. Sin decir cual es cual, el importe del primer dia se
     * leeria como "hoy se pagan ochocientos millones" o se buscaria donde no esta.
     *
     * CUENTAN TODO LO PENDIENTE, Y LO DICEN. Los usan los dos lados -la pestaña
     * y el proveedor del tablero- y los dos muestran MENOS que eso: la pestaña
     * abre filtrada por forma de pago y la fila del tablero usa PAGOS, que trae
     * solo el cronograma. Que cuenten el universo esta bien -son la
     * contrapartida de lo que no se ve- pero un numero que no coincide con el
     * que esta en pantalla tiene que decir a que se refiere, o se lee como un
     * error del sistema.
     *
     * Estatica y pura.
     *
     * @param array $items Filas de getPendientes()
     * @return array Lista de mensajes
     */
    public static function avisosPendientes($items) {
        return Aviso::textos(self::avisosPendientesConNivel($items));
    }

    /**
     * Los mismos avisos de avisosPendientes(), con su gravedad. Lo lee el
     * tablero; la pestana lee los textos.
     *
     * Los vencidos sin fecha y lo que no tiene vencimiento se arreglan
     * cargando la fecha: atencion. Los del rubro excluido son una decision ya
     * tomada en el maestro: informativo.
     *
     * @param array $items Filas de getPendientes()
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosPendientesConNivel($items) {
        $avisos = [];

        $vencidoSinFecha = 0.0;
        $compVencidos = 0;
        $vencidoCrono = 0.0;
        $compVencidosCrono = 0;
        $sinFecha = 0.0;
        $compSinFecha = 0;
        $excluido = 0.0;
        $compExcluidos = 0;
        $descuadrados = [];

        /* LOS DEL CRONOGRAMA QUE SE PROYECTAN SIN RETENER porque un impuesto "a
           todos" no tiene alicuota general vigente a su fecha de pago -y el
           proveedor tampoco tiene una propia-. No es una retencion de 0: falta
           el dato, y el neto que se proyecta es mas alto que el real. */
        $sinGeneral = 0;
        $importeSinGeneral = 0.0;
        $impuestosSinGeneral = [];

        foreach ($items as $i) {
            $importe = floatval($i['IMPORTE_PENDIENTE']);

            /* Las dos partes de un comprobante abierto son UN vencimiento: la
               segunda suma su importe pero no se cuenta. */
            $cuenta = self::cuentaComoVencimiento($i) ? 1 : 0;

            if ($cuenta === 1 && !empty($i['RETENCION']['sin_general'])) {
                $sinGeneral++;
                $importeSinGeneral += floatval($i['RETENCION']['base']);

                foreach ($i['RETENCION']['sin_general'] as $codigo) {
                    $impuestosSinGeneral[$codigo] = true;
                }
            }

            if (!empty($i['APERTURA_DESCUADRADA'])) {
                $descuadrados[self::clavePago($i['COD_PROVEE'], $i['T_COMP'], $i['N_COMP'])] = true;
            }

            if (!empty($i['EXCLUIDO'])) {
                $excluido += $importe;
                $compExcluidos += $cuenta;
            }

            if ($i['ORIGEN_FECHA'] === 'SIN_FECHA') {
                $sinFecha += $importe;
                $compSinFecha++;
                continue;
            }

            if (!empty($i['SIN_FECHA_CARGADA'])) {
                if (!empty($i['PAGO_CRONO'])) {
                    $vencidoCrono += $importe;
                    $compVencidosCrono++;
                } else {
                    $vencidoSinFecha += $importe;
                    $compVencidos++;
                }
            }
        }

        /* SOBRE TODO LO PENDIENTE. Se dice en cada uno y no una vez al final:
           los avisos se leen sueltos -van en una lista- y el que se lea sin el
           de al lado tiene que seguir diciendo a que se refiere. */
        $alcance = ' Es sobre TODAS las cuentas a pagar, así que no coincide con lo que '
            . 'muestra la pantalla si hay un filtro puesto.';

        /* UNA APERTURA QUE YA NO DESCRIBE EL COMPROBANTE. Lo mas probable es un
           pago parcial en Tango: el pendiente dejo de ser la suma de las dos
           partes. Se proyecta todo lo que queda en la fecha mas tardia -ver
           abrirEnMedios()- y eso es una suposicion, asi que se dice, y se dice
           como arreglarlo. */
        if (!empty($descuadrados)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($descuadrados) . ' comprobante(s) '
                . 'abiertos en dos medios ya no coinciden con su neto a pagar: se proyecta el saldo '
                . 'en la fecha más tardía. Reimportá para corregirlo.');
        }

        if ($sinGeneral > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $sinGeneral . ' comprobante(s) del cronograma '
                . 'por ' . self::plata($importeSinGeneral) . ' se proyectan sin retener '
                . implode(', ', array_keys($impuestosSinGeneral)) . ' porque no tienen alícuota '
                . 'general vigente a su fecha de pago: falta cargar las retenciones en Parámetros › '
                . 'Prov. Locales. Mientras tanto se proyecta el total, más de lo que va a salir de '
                . 'la caja.' . $alcance, 'Retenciones');
        }

        if ($compVencidosCrono > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $compVencidosCrono . ' vencimiento(s) por ' . self::plata($vencidoCrono)
                . ' del cronograma de pagos ya vencieron y NO tienen fecha de pago cargada. '
                . 'Se proyectan en el próximo día de pago del cronograma de Proveedores '
                . 'Locales (Parámetros › Generales), no en el primer día del eje. Si se van a '
                . 'pagar otro día, cargales la fecha.' . $alcance);
        }

        if ($compVencidos > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $compVencidos . ' vencimiento(s) por ' . self::plata($vencidoSinFecha)
                . ' que no van a un día de pago del cronograma (débito, caja, tarjeta) ya vencieron '
                . 'y NO tienen fecha de pago cargada. Se muestran en el primer día del eje '
                . 'porque no se pagan en un día de pago, pero eso no significa que se paguen '
                . 'hoy: cargales la fecha, de a uno en la grilla o importando la planilla de '
                . 'pagos.' . $alcance);
        }

        if ($compSinFecha > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $compSinFecha . ' vencimiento(s) por ' . self::plata($sinFecha)
                . ' no tienen fecha de vencimiento en Tango ni plazo de pago en el maestro, '
                . 'así que no se pueden ubicar en el eje.' . $alcance);
        }

        if ($compExcluidos > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $compExcluidos . ' vencimiento(s) por ' . self::plata($excluido)
                . ' son de proveedores con rubro "' . ProveedoresCategorias::RUBRO_EXCLUIDOS
                . '" en el maestro. Se listan acá pero su fila del tablero se puede '
                . 'inhabilitar desde Parámetros, o apuntarla a la serie que ya los deja '
                . 'afuera sin perder el criterio del cronograma.' . $alcance);
        }

        return $avisos;
    }

    /* ====================================================================
       FECHAS DE PAGO CARGADAS
       ==================================================================== */

    /**
     * Las fechas de pago cargadas, indexadas por comprobante.
     *
     * LA CLAVE INCLUYE AL PROVEEDOR, y es lo contrario de cobranzas: en compras
     * el numero de comprobante lo pone el proveedor, no nosotros. Hay 10.293
     * pares (T_COMP, N_COMP) repetidos entre proveedores locales. Ver el
     * encabezado de sql/cashflow_prov_locales.sql.
     *
     * @return array Mapa 'COD|T_COMP|N_COMP' => fila
     */
    public function getPagos() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $cid = $this->conectar();

        $sql = "SELECT COD_PROVEE, T_COMP, N_COMP, FECHA_PAGO, FORMA_PAGO, FORMA_PAGO_ORIG,
                       OBSERVACION, ESTADO, FECHA_CANCELADO, ORIGEN, USUARIO_MODIF, FECHA_MODIF, "
                       . $this->overrideSql('FORMA_PAGO_CRONOGRAMA') . " AS FORMA_PAGO_CRONOGRAMA, "
                       . ($this->tieneColumnaPago('EXCLUIDA') ? 'EXCLUIDA' : '0') . " AS EXCLUIDA, "
                       . $this->overrideSql('MOTIVO_EXCLUSION') . " AS MOTIVO_EXCLUSION, "
                       . $this->overrideSql('FUENTE_FECHA') . " AS FUENTE_FECHA
                FROM dbo." . self::TABLA_PAGO;

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las fechas de pago'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = self::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);

            $mapa[$clave] = [
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'T_COMP' => Planilla::codigo($row['T_COMP']),
                'N_COMP' => Planilla::codigo($row['N_COMP']),
                'FECHA_PAGO' => Horizonte::normalizarFecha($row['FECHA_PAGO']),

                /* Normalizada contra la lista DE HOY y no contra la del dia que
                   se importo. Misma regla que el maestro y por el mismo motivo:
                   es un valor derivado del original, que esta guardado al lado.
                   Ver ProveedoresCategorias::mapa(). */
                'FORMA_PAGO' => ProveedoresCategorias::formaVigente(
                    $row['FORMA_PAGO_ORIG'], $row['FORMA_PAGO']),
                'FORMA_PAGO_ORIG' => $row['FORMA_PAGO_ORIG'],

                /* EL OVERRIDE DE ESTA FACTURA, que es otra cosa que FORMA_PAGO:
                   es una REGLA -con que forma hay que tratar a este
                   comprobante- y no un hecho. Es lo unico de esta tabla que
                   decide si el importe entra al cashflow. Ver la nota de
                   CRONOGRAMA en getPendientes(). */
                'FORMA_PAGO_CRONOGRAMA' => $row['FORMA_PAGO_CRONOGRAMA'],

                /* El tercer override: esta factura NO va al cashflow. Es por
                   COMPROBANTE, a diferencia del rubro "Excluidos" del maestro,
                   que es por proveedor. Ver seriesDeItem(). */
                'EXCLUIDA' => (intval($row['EXCLUIDA']) === 1),
                'MOTIVO_EXCLUSION' => $row['MOTIVO_EXCLUSION'],
                'OBSERVACION' => $row['OBSERVACION'],
                'ESTADO' => $row['ESTADO'],
                'FECHA_CANCELADO' => Horizonte::normalizarFecha($row['FECHA_CANCELADO']),
                'ORIGEN' => $row['ORIGEN'],

                /* De donde salio LA FECHA, que no es lo mismo que ORIGEN: ese
                   describe la ultima escritura sobre la fila. NULL sin el
                   script, y ahi fuenteFecha() cae a ORIGEN. Ver
                   sql/cashflow_prov_locales_fuente_fecha.sql. */
                'FUENTE_FECHA' => $row['FUENTE_FECHA'],
                'USUARIO' => $row['USUARIO_MODIF'],
                'FECHA_MODIF' => ($row['FECHA_MODIF'] instanceof DateTime)
                    ? $row['FECHA_MODIF']->format('Y-m-d H:i:s') : $row['FECHA_MODIF']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Como se pide una columna de override en un SELECT: la columna si ya
     * existe, y NULL si el script que la agrega todavia no se corrio.
     *
     * SE PREGUNTA en vez de darla por hecha porque las cuentas a pagar se
     * siguen pudiendo LEER sin ella: el listado sale de Tango y una instalacion
     * que no corrio el script tiene que ver su pestana, no un error de SQL. NULL
     * es ademas el valor correcto: sin la columna no hay ningun override, que es
     * exactamente lo que NULL significa.
     *
     * @param string $columna
     * @return string
     */
    private function overrideSql($columna) {
        return $this->tieneColumnaPago($columna) ? $columna : 'NULL';
    }

    /**
     * Si la tabla de overrides ya tiene una columna. Se pregunta una vez por
     * columna y por pedido.
     *
     * @param string $columna
     * @return bool
     */
    public function tieneColumnaPago($columna) {
        if (isset($this->columnasPago[$columna])) {
            return $this->columnasPago[$columna];
        }

        if (!$this->tablaCreada()) {
            return false;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COL_LENGTH('dbo." . self::TABLA_PAGO . "', ?) AS C", [$columna]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la columna ' . $columna));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->columnasPago[$columna] = ($row && $row['C'] !== null);

        return $this->columnasPago[$columna];
    }

    /**
     * La clave de un pago: proveedor + comprobante.
     *
     * Estatica y publica porque la usan la lectura, el guardado y el diff de la
     * importacion, y las tres tienen que armarla igual.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @return string
     */
    public static function clavePago($codProvee, $tComp, $nComp) {
        return Planilla::codigo($codProvee) . '|'
             . Planilla::codigo($tComp) . '|'
             . Planilla::codigo($nComp);
    }

    /* ====================================================================
       IMPORTACION DE LOS PAGOS

       La planilla con la que se decide CUANDO se paga cada factura. Es lo que
       disuelve los ochocientos millones apilados en el primer dia del eje: sin
       ella, todo lo vencido se dibuja hoy porque no hay otro lugar donde
       ponerlo.

       Mismo circuito que el maestro y que Cob. Electronicos: plantilla en
       Excel, el Excel leido en el navegador, previsualizacion del diff, y nada
       se escribe hasta confirmar.
       ==================================================================== */

    /**
     * Las columnas de la planilla de pagos, con sus sinonimos.
     *
     * EL TIPO DE COMPROBANTE NO ES OBLIGATORIO, y es a proposito: quien arma la
     * planilla mira una factura y copia su numero, no su tipo. Cuando falta se
     * deduce de los pendientes del proveedor. Si ese numero existe con dos tipos
     * distintos -una FAC y una NDI con el mismo numero-, la fila queda en error
     * pidiendo que se aclare, en lugar de elegir una.
     *
     * @return array
     */
    public static function columnasImportacion() {
        return [
            'cod_provee' => [
                'titulo' => 'COD_PROVEEDOR',
                'obligatoria' => true,
                'tipo' => 'texto',
                'ayuda' => 'Código del proveedor en Tango. Hace falta: el número de factura '
                    . 'solo no identifica un comprobante, porque lo emite el proveedor y dos '
                    . 'proveedores distintos repiten numeración.',
                'sinonimos' => ['CODPROVEEDOR', 'COD_PROVEE', 'CODIGO', 'COD_PROVEEDOR',
                                'PROVEEDOR', 'CODIGOPROVEEDOR']
            ],
            'n_comp' => [
                'titulo' => 'NRO_FACTURA',
                'obligatoria' => true,
                'tipo' => 'texto',
                'ayuda' => 'Número de comprobante tal como figura en Tango, por ejemplo '
                    . 'A0000500001731.',
                'sinonimos' => ['NROFACTURA', 'N_COMP', 'NCOMP', 'NUMERO', 'NROCOMPROBANTE',
                                'FACTURA', 'COMPROBANTE']
            ],
            'fecha_pago' => [
                'titulo' => 'FECHA_PAGO',
                'obligatoria' => true,
                'tipo' => 'fecha',
                'ayuda' => 'Cuándo se piensa pagar. dd/mm/aaaa o aaaa-mm-dd.',
                'sinonimos' => ['FECHAPAGO', 'FECHA_PAGO', 'FECHA', 'PAGO', 'FECHADEPAGO']
            ],
            'forma_pago' => [
                'titulo' => 'FORMA_PAGO',
                'obligatoria' => false,
                'tipo' => 'texto',
                'ayuda' => 'Opcional. Si no viene, se usa la forma habitual del proveedor '
                    . 'según el maestro. Válidos: '
                    . implode(', ', ProveedoresCategorias::FORMAS_PAGO) . '.',
                'sinonimos' => ['FORMAPAGO', 'FORMA_PAGO', 'FORMA', 'MEDIODEPAGO',
                                'FORMADEPAGO']
            ],
            /* EL IMPORTE ES OPCIONAL CON UN MEDIO Y OBLIGATORIO CON DOS, y es
               NETO DE RETENCIONES: lo que sale de la caja, que es lo que pone
               quien arma la planilla. Con una sola fila por comprobante, vacio
               quiere decir "el neto a pagar"; si viene, tiene que coincidir con
               el neto -con un peso de tolerancia-, porque otro numero es otro
               comprobante o un pago parcial que la planilla no sabe. Con dos
               filas es lo que dice cuanto va por cada medio, y suman el neto.
               Sin retenciones el neto es el pendiente. Ver
               compararImportacion(). */
            'importe' => [
                'titulo' => 'IMPORTE',
                'obligatoria' => false,
                'tipo' => 'importe',
                'ayuda' => 'Neto de retenciones. Opcional con un solo medio: vacío es el neto a pagar, '
                    . 'y si viene tiene que coincidir con el neto. Con dos medios —dos filas del '
                    . 'mismo comprobante, cada una ECHEQ o TRANSFERENCIA— es obligatorio en las dos, '
                    . 'y tienen que sumar el neto a pagar. Sin separador de miles.',
                'sinonimos' => ['IMPORTE', 'MONTO', 'IMPORTE_PAGO', 'IMPORTEPAGO', 'MONTO_PAGO',
                                'MONTOPAGO']
            ],
            't_comp' => [
                'titulo' => 'TIPO_COMP',
                'obligatoria' => false,
                'tipo' => 'texto',
                'ayuda' => 'Opcional (FAC, NDI, NCP...). Si no viene se deduce de los '
                    . 'pendientes del proveedor.',
                'sinonimos' => ['TIPOCOMP', 'T_COMP', 'TCOMP', 'TIPO', 'TIPOCOMPROBANTE']
            ],
            'observacion' => [
                'titulo' => 'OBSERVACION',
                'obligatoria' => false,
                'tipo' => 'texto',
                'ayuda' => 'Opcional, hasta 200 caracteres.',
                'sinonimos' => ['OBSERVACION', 'OBSERVACIONES', 'NOTAS', 'COMENTARIOS']
            ]
        ];
    }

    /**
     * La plantilla que se descarga, con filas de ejemplo cargadas. El .xlsx lo
     * arma el navegador: ver Planilla::plantilla().
     *
     * Las fechas de ejemplo se calculan sobre hoy para que nunca se vean viejas.
     *
     * @param string|null $ejemploFecha 'Y-m-d'
     * @return array Lo que devuelve Planilla::plantilla()
     */
    public static function plantilla($ejemploFecha = null) {
        $fecha = ($ejemploFecha === null) ? date('Y-m-d') : substr((string) $ejemploFecha, 0, 10);
        $enUnaSemana = date('Y-m-d', strtotime($fecha . ' +7 day'));
        $enDosSemanas = date('Y-m-d', strtotime($fecha . ' +14 day'));

        /* El tercer y el cuarto ejemplo son UN comprobante pagado con dos
           medios: dos filas, cada una con su fecha, su forma y su importe. Sin
           el ejemplo, nadie adivina que dos medios se cargan asi. */
        return Planilla::plantilla(self::columnasImportacion(), [
            ['MTDODI', 'A0000500001731', $enUnaSemana, 'TRANSFERENCIA', '', '',
             'Ejemplo: un medio, todo lo pendiente'],
            ['OGCOAN', 'A0000300001234', $enDosSemanas, 'ECHEQ', '', 'FAC',
             'Ejemplo: con tipo de comprobante'],
            ['SPEDEN', 'A0000100000001', $enUnaSemana, 'ECHEQ', 150000.00, '',
             'Ejemplo: dos medios, primera parte'],
            ['SPEDEN', 'A0000100000001', $enDosSemanas, 'TRANSFERENCIA', 50000.00, '',
             'Ejemplo: dos medios, segunda parte']
        ], 'plantilla_pagos_proveedores');
    }

    /**
     * Compara lo que trae el archivo contra los pendientes y contra lo ya
     * cargado, y dice QUE CAMBIARIA. No escribe nada.
     *
     * Helper PURO: recibe las filas parseadas, los pendientes, los pagos y las
     * aperturas cargadas. Se prueba entero sin base y sin archivos.
     *
     * ESTADOS
     *   ALTA         el comprobante no tenia fecha cargada
     *   CAMBIO       la tenia y es otra; 'antes' dice cual
     *   SIN_CAMBIOS  ya estaba con esa fecha y esa forma -o con esa apertura-
     *   YA_PAGADA    ADVERTENCIA: el comprobante ya se pago en Tango. No se
     *                carga, no frena la confirmacion y se cuenta aparte
     *   ERROR        no se puede cargar; 'motivo' dice por que
     *
     * LO QUE NO MATCHEA CONTRA NINGUN PENDIENTE SE MIRA EN TANGO ($enTango, solo
     * esos comprobantes): si ya se pago es YA_PAGADA, con la fecha real; si no
     * existe para ese proveedor es un ERROR -numero mal tipeado o proveedor
     * equivocado-. Ver sinPendiente().
     *
     * EL ESTADO ES DEL COMPROBANTE, NO DE LA FILA
     * -------------------------------------------
     * Las filas se juntan por comprobante y se deciden juntas:
     *
     *   1 fila       un medio, el caso de siempre. IMPORTE es opcional: vacio
     *                es el NETO A PAGAR, y si viene tiene que coincidir con el
     *                neto con TOLERANCIA_IMPORTE. La forma es opcional y no
     *                decide nada, como siempre.
     *   2 filas      DOS MEDIOS. Cada fila con su fecha, su forma -ECHEQ o
     *                TRANSFERENCIA, obligatoria- y su importe -obligatorio,
     *                mayor a cero-, y las dos tienen que sumar el neto.
     *
     * EL NETO A PAGAR ES EL TOTAL MENOS LAS RETENCIONES, resuelto con la regla
     * de siempre -ProveedoresRetenciones::calcular()- a la fecha de pago de la
     * planilla (la mas tardia, con dos medios). Un comprobante sin retencion
     * -otra forma, nota de credito, proveedor sin impuestos- tiene neto = total
     * y la regla es la de antes. Cada fila vuelve con su total, sus
     * retenciones y el neto contra el que se comparo.
     *                Puede ser el mismo medio dos veces: dos echeqs de fechas
     *                distintas.
     *   3 o mas      error en TODAS, nombrando las lineas.
     *
     * CON DOS MEDIOS, SE CARGAN LAS DOS O NINGUNA. Si una de las dos filas esta
     * mal, la otra tampoco se carga: cargar media apertura dejaria el
     * comprobante partido a medias, con una parte en una fecha y la otra en el
     * aire. Por eso el motivo es UNO para el comprobante y va en las dos filas.
     *
     * UN COMPROBANTE EN CUOTAS NO SE PARTE en dos medios: con varios
     * vencimientos no hay forma de decir que parte de cada cuota va por cada
     * medio. Se carga con uno solo.
     *
     * LA FORMA DE LA PLANILLA SIGUE SIN DECIDIR EL CRONOGRAMA. Si la forma
     * vigente del comprobante no es del cronograma -CAJA en el maestro, por
     * ejemplo- y se lo abre en echeq + transferencia, se acepta: es un hecho
     * sobre como va a salir la plata. Pero se avisa, porque el comprobante se
     * va a seguir tratando con su forma vigente y eso puede no ser lo que
     * quien arma la planilla espera.
     *
     * REIMPORTAR REEMPLAZA LA APERTURA ENTERA. Un comprobante que estaba en dos
     * medios y ahora viene en una fila deja de estar partido; si viene igual
     * que estaba, es SIN_CAMBIOS.
     *
     * Las filas salen agrupadas: las dos partes de un comprobante, juntas. La
     * previsualizacion las muestra asi.
     *
     * @param array $filasArchivo Filas de Planilla::parsear()
     * @param array $pendientes Filas de getPendientes(), SIN horizonte: una por
     *        vencimiento, no partidas
     * @param array $pagos Mapa de getPagos()
     * @param string|null $hoy 'Y-m-d' para validar que la fecha no sea absurda
     * @param array $aperturas Mapa de getAperturas()
     * @param bool $conMedios Si existe la tabla de medios. Sin ella, dos filas
     *        de un comprobante son un error que nombra el script
     * @param array|null $retCfg De ProveedoresRetenciones::config(): el IMPORTE
     *        se compara contra el NETO a pagar. null = sin retenciones, y el
     *        neto es el pendiente
     * @param array|null $enTango De estadoComprobantesEnTango(): el estado en
     *        Tango de los que no cruzaron. null = no se consulto
     * @return array ['filas', 'resumen', 'avisos']
     */
    public static function compararImportacion($filasArchivo, $pendientes, $pagos, $hoy = null,
                                               $aperturas = [], $conMedios = true, $retCfg = null,
                                               $enTango = null) {
        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        /* Los pendientes, indexados de dos formas: por clave completa -cuando la
           planilla trae el tipo- y por (proveedor, numero) para poder deducirlo
           cuando no viene. */
        $porClave = [];
        $porNumero = [];

        foreach (is_array($pendientes) ? $pendientes : [] as $p) {
            $cod = Planilla::codigo($p['COD_PROVEE']);
            $tComp = Planilla::codigo($p['T_COMP']);
            $nComp = Planilla::codigo($p['N_COMP']);

            $porClave[self::clavePago($cod, $tComp, $nComp)] = $p;
            $porNumero[$cod . '|' . $nComp][] = $p;
        }

        $pagos = is_array($pagos) ? $pagos : [];
        $aperturas = is_array($aperturas) ? $aperturas : [];

        /* 1. Cada fila por su cuenta: la forma de la celda y el cruce contra
              los pendientes. 2. Cada comprobante entero. */
        $grupos = [];

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $cruda) {
            $fila = self::filaPago($cruda, $porClave, $porNumero, $hoyStr, $enTango);
            $grupos[self::grupoDeFila($fila, $porNumero)][] = $fila;
        }

        $filas = [];

        $resumen = [
            'altas' => 0, 'cambios' => 0, 'sin_cambios' => 0, 'errores' => 0,
            'importe_con_fecha' => 0.0, 'vencidos_resueltos' => 0,
            'forma_desconocida' => 0, 'dos_medios' => 0, 'dos_medios_fuera_cronograma' => 0,
            'ya_pagadas' => 0
        ];

        foreach ($grupos as $grupo) {
            /* UN COMPROBANTE YA PAGADO EN TANGO ES ADVERTENCIA EN TODAS SUS
               FILAS: dos filas de una factura ya pagada no son un error del
               comprobante -no hay nada que cargar-, y cada una se cuenta. */
            $yaPagadas = array_filter($grupo, function ($f) { return $f['estado'] === 'YA_PAGADA'; });

            if (count($yaPagadas) === count($grupo)) {
                foreach ($grupo as $fila) {
                    $filas[] = $fila;
                    $resumen['ya_pagadas']++;
                }

                continue;
            }

            if (count($grupo) === 1) {
                $grupo = [self::unMedio($grupo[0], $pagos, $aperturas, $retCfg)];
            } elseif (count($grupo) === self::MAX_MEDIOS) {
                $grupo = self::dosMedios($grupo, $pagos, $aperturas, $conMedios, $retCfg);
            } else {
                $grupo = self::demasiadasFilas($grupo);
            }

            foreach ($grupo as $fila) {
                $filas[] = $fila;

                if ($fila['estado'] === 'ERROR') {
                    $resumen['errores']++;
                    continue;
                }

                if (!empty($fila['forma_desconocida'])) {
                    $resumen['forma_desconocida']++;
                }

                /* LO QUE SE CUENTA POR COMPROBANTE SE CUENTA UNA VEZ: en la
                   primera parte. Las altas, los cambios y lo que se reubica
                   son del comprobante; los errores, de la fila. */
                if ($fila['parte'] !== 1) {
                    continue;
                }

                /* Lo que se reubica es lo que sale de la caja: el neto. */
                $resumen['importe_con_fecha'] += ($fila['neto'] !== null) ? $fila['neto']
                    : $fila['importe_pendiente'];

                if ($fila['estaba_vencido']) {
                    $resumen['vencidos_resueltos']++;
                }

                if ($fila['partes'] === self::MAX_MEDIOS) {
                    $resumen['dos_medios']++;

                    if ($fila['aviso'] !== null) {
                        $resumen['dos_medios_fuera_cronograma']++;
                    }
                }

                switch ($fila['estado']) {
                    case 'ALTA': $resumen['altas']++; break;
                    case 'CAMBIO': $resumen['cambios']++; break;
                    case 'SIN_CAMBIOS': $resumen['sin_cambios']++; break;
                }
            }
        }

        return [
            'filas' => $filas,
            'resumen' => $resumen,
            'avisos' => self::avisosImportacionPagos($resumen)
        ];
    }

    /**
     * Con que comprobante va una fila, para juntar las dos partes de un pago
     * con dos medios.
     *
     * Una fila en error tambien se agrupa si se sabe de que comprobante es: con
     * dos medios, una fila mala hace que la otra tampoco se cargue, y para eso
     * hay que saber que son del mismo. El tipo, si no vino, se deduce igual que
     * en el cruce; si no se puede deducir, la fila queda sola, que es lo que ya
     * dice su propio motivo.
     *
     * @param array $fila Lo que devolvio filaPago()
     * @param array $porNumero Pendientes por (proveedor, numero)
     * @return string
     */
    private static function grupoDeFila($fila, $porNumero) {
        $sola = 'LINEA|' . $fila['linea'];

        if ($fila['cod_provee'] === '' || $fila['n_comp'] === '') {
            return $sola;
        }

        $t = $fila['t_comp'];

        if ($t === '') {
            $tipos = [];

            $clave = $fila['cod_provee'] . '|' . $fila['n_comp'];

            foreach (isset($porNumero[$clave]) ? $porNumero[$clave] : [] as $c) {
                $tipos[Planilla::codigo($c['T_COMP'])] = true;
            }

            if (count($tipos) !== 1) {
                return $sola;
            }

            $t = key($tipos);
        }

        return self::clavePago($fila['cod_provee'], $t, $fila['n_comp']);
    }

    /**
     * Un comprobante en UNA fila: el caso de siempre, mas el IMPORTE opcional.
     *
     * @param array $fila
     * @param array $pagos
     * @param array $aperturas
     * @return array La fila, con su estado y su motivo
     */
    private static function unMedio($fila, $pagos, $aperturas, $retCfg = null) {
        if ($fila['estado'] === 'ERROR') {
            return $fila;
        }

        /* EL NETO A PAGAR, a la fecha de pago de la planilla: es la que va a
           quedar cargada, y con la que la grilla va a resolver la alicuota. */
        $fila = self::conNeto($fila, $retCfg, $fila['fecha_pago']);

        /* CON UN MEDIO, EL IMPORTE TIENE QUE SER EL NETO A PAGAR: lo que sale de
           la caja, que es lo que pone quien arma la planilla. Otro numero es
           otro comprobante, o un pago parcial que la planilla sabe y Tango
           todavia no: cargarle la fecha a todo el pendiente diria una cosa que
           la planilla no dice. Sin retenciones el neto es el pendiente, y la
           regla es la de siempre. */
        if ($fila['importe'] !== null) {
            $dif = round($fila['importe'] - $fila['neto'], 2);

            if (abs($dif) > self::TOLERANCIA_IMPORTE) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = self::motivoImporte('El importe (' . self::plata($fila['importe']) . ')',
                    $fila['importe'], $fila)
                    . ' Con un solo medio, el IMPORTE puede quedar vacío y se toma el neto a pagar.';

                return $fila;
            }
        }

        $clave = self::clavePago($fila['cod_provee'], $fila['t_comp'], $fila['n_comp']);
        $existente = isset($pagos[$clave]) ? $pagos[$clave] : null;
        $apertura = isset($aperturas[$clave]) ? $aperturas[$clave] : null;

        if ($existente === null && $apertura === null) {
            $fila['estado'] = 'ALTA';
            $fila['motivo'] = 'No tenía fecha de pago cargada.';
        } elseif ($apertura !== null) {
            /* Reimportar reemplaza la apertura entera: una fila sola la
               deshace, aunque la fecha coincida con una de las dos partes. */
            $fila['estado'] = 'CAMBIO';
            $fila['antes'] = [
                'fecha_pago' => $existente === null ? null : $existente['FECHA_PAGO'],
                'forma_pago' => null,
                'medios' => $apertura
            ];
            $fila['motivo'] = 'Deja de estar pagado con dos medios (' . self::textoMedios($apertura)
                . '): vuelve a ser un comprobante con una sola fecha.';
        } elseif ($existente['FECHA_PAGO'] === $fila['fecha_pago']
            && trim((string) $existente['FORMA_PAGO']) === trim((string) $fila['forma_pago'])) {
            $fila['estado'] = 'SIN_CAMBIOS';
            $fila['motivo'] = 'Ya estaba cargado igual.';
        } else {
            $fila['estado'] = 'CAMBIO';
            $fila['antes'] = [
                'fecha_pago' => $existente['FECHA_PAGO'],
                'forma_pago' => $existente['FORMA_PAGO']
            ];
            $fila['motivo'] = 'Cambia la fecha cargada'
                . ($existente['FECHA_PAGO'] !== null
                    ? ' (estaba en ' . self::formatoCorto($existente['FECHA_PAGO']) . ')'
                    : '') . '.';
        }

        return self::marcarConciliado($fila, $existente);
    }

    /**
     * Le agrega a una fila de la planilla la retencion y el neto a pagar de su
     * comprobante, con la regla de siempre (ProveedoresRetenciones::calcular())
     * sobre el pendiente del comprobante entero.
     *
     * @param array $fila
     * @param array|null $retCfg
     * @param string $fecha 'Y-m-d' de pago
     * @return array La fila con 'retencion', 'retenido', 'retenciones' y 'neto'
     */
    private static function conNeto($fila, $retCfg, $fecha) {
        $r = ProveedoresRetenciones::calcular($fila['importe_pendiente'], $fila['cod_provee'],
            $fila['t_comp'], $fila['cronograma'], $fecha,
            ($retCfg === null) ? ['disponible' => false] : $retCfg);

        $fila['retencion'] = $r;
        $fila['retenido'] = $r['retenido'];
        $fila['retenciones'] = [];

        foreach ($r['impuestos'] as $codigo => $i) {
            $fila['retenciones'][$codigo] = ['nombre' => $i['nombre'], 'alicuota' => $i['alicuota'],
                                             'retencion' => $i['retencion']];
        }

        $fila['neto'] = $r['neto'];

        return $fila;
    }

    /**
     * El motivo de un importe que no coincide con el neto. AYUDA A CORREGIR:
     *
     *   - si coincide con el TOTAL sin retenciones, lo dice, dice cual es el
     *     neto y cuanto es cada retencion, y como se hace si a ese proveedor no
     *     se le retiene: es el error que va a aparecer el primer dia;
     *   - si no, muestra total, retenciones, neto y diferencia;
     *   - sin retenciones, el texto de siempre, contra el pendiente.
     *
     * @param string $sujeto "El importe ($ X)" o "Los importes suman $ X"
     * @param float $importe
     * @param array $fila Con 'importe_pendiente', 'retenido', 'retenciones', 'neto'
     * @return string
     */
    private static function motivoImporte($sujeto, $importe, $fila) {
        $total = $fila['importe_pendiente'];
        $neto = $fila['neto'];
        $dif = round($importe - $neto, 2);

        if ($fila['retenido'] <= 0) {
            return $sujeto . ' no coincide con el pendiente del comprobante (' . self::plata($total)
                . '): la diferencia es de ' . self::plata($dif) . '.';
        }

        $desglose = [];

        foreach ($fila['retenciones'] as $r) {
            if ($r['retencion'] > 0) {
                $desglose[] = $r['nombre'] . ' ' . self::plata($r['retencion']);
            }
        }

        if (abs(round($importe - $total, 2)) <= self::TOLERANCIA_IMPORTE) {
            return $sujeto . ' coincide con el total de la factura sin retenciones (' . self::plata($total)
                . '). El neto a pagar con retenciones es ' . self::plata($neto) . ' ('
                . implode(', ', $desglose) . '). Si a este proveedor no se le retiene, cargale '
                . 'alícuota 0 en el Maestro.';
        }

        return $sujeto . ' no coincide con el neto a pagar del comprobante: total ' . self::plata($total)
            . ', retenciones ' . self::plata($fila['retenido']) . ' (' . implode(', ', $desglose)
            . '), neto ' . self::plata($neto) . '. La diferencia es de ' . self::plata($dif) . '.';
    }

    /**
     * Un comprobante en DOS filas: dos medios. Se cargan las dos o ninguna.
     * Ver compararImportacion().
     *
     * LAS DOS PARTES SON NETAS: tienen que sumar el NETO A PAGAR del
     * comprobante. La retencion se calcula sobre el comprobante entero a la
     * fecha mas tardia de las dos, la misma con la que abrirEnMedios() lo va a
     * proyectar.
     *
     * @param array $grupo Las dos filas, en el orden del archivo
     * @param array $pagos
     * @param array $aperturas
     * @param bool $conMedios
     * @param array|null $retCfg
     * @return array Las dos filas, con el mismo estado y el mismo motivo
     */
    private static function dosMedios($grupo, $pagos, $aperturas, $conMedios, $retCfg = null) {
        $lineas = 'líneas ' . $grupo[0]['linea'] . ' y ' . $grupo[1]['linea'];

        foreach ($grupo as $i => $f) {
            $grupo[$i]['grupo'] = $grupo[0]['linea'];
            $grupo[$i]['partes'] = self::MAX_MEDIOS;
            $grupo[$i]['parte'] = $i + 1;
        }

        /* SIN LA TABLA, NO HAY DONDE GUARDAR LA APERTURA. Se dice antes que
           cualquier otro problema: es lo unico que hay que arreglar fuera de la
           planilla, y nombrar el script es lo que dice como. */
        if (!$conMedios) {
            return self::enError($grupo, 'Este comprobante viene en dos filas (' . $lineas . '), que '
                . 'es como se carga un pago con dos medios, y para eso hace falta correr '
                . self::SCRIPT_MEDIO . ' contra la base central. Mientras tanto, dejá una sola fila.');
        }

        /* UNA FILA MALA SE LLEVA A LA OTRA. Cada una conserva su propio motivo
           si lo tiene; la que estaba bien dice por que no se carga. */
        $malas = [];

        foreach ($grupo as $f) {
            if ($f['estado'] === 'ERROR') {
                $malas[] = $f['linea'];
            }
        }

        if (!empty($malas)) {
            foreach ($grupo as $i => $f) {
                $grupo[$i]['estado'] = 'ERROR';
                $grupo[$i]['motivo'] = ($f['estado'] === 'ERROR' ? $f['motivo'] . ' ' : '')
                    . 'Con dos medios se cargan las dos filas o ninguna (' . $lineas . '), para no '
                    . 'dejar el comprobante partido a medias'
                    . ($f['estado'] === 'ERROR'
                        ? '.'
                        : ': la línea ' . implode(' y la ', $malas) . ' no se puede cargar.');
                $grupo[$i]['parte'] = 0;
            }

            return $grupo;
        }

        $ultimaFecha = max($grupo[0]['fecha_pago'], $grupo[1]['fecha_pago']);

        foreach ($grupo as $i => $f) {
            $grupo[$i] = self::conNeto($f, $retCfg, $ultimaFecha);
        }

        $base = $grupo[0];
        $problemas = [];

        if ($base['vencimientos'] > 1) {
            $problemas[] = 'es un comprobante en cuotas: cargalo con un solo medio';
        }

        $formas = [];
        $importes = [];
        $suma = 0.0;
        $importesOk = true;

        foreach ($grupo as $f) {
            if ($f['forma_pago_orig'] === '') {
                $formas[] = 'la línea ' . $f['linea'] . ' no trae forma';
            } elseif (!in_array($f['forma_pago'], ProveedoresCategorias::FORMAS_CRONOGRAMA, true)) {
                $formas[] = 'la línea ' . $f['linea'] . ' trae «' . $f['forma_pago_orig'] . '»';
            }

            if ($f['importe'] === null) {
                $importes[] = 'la línea ' . $f['linea'] . ' no trae IMPORTE';
                $importesOk = false;
            } elseif ($f['importe'] <= 0) {
                $importes[] = 'la línea ' . $f['linea'] . ' trae un importe de '
                    . self::plata($f['importe']);
                $importesOk = false;
            } else {
                $suma += $f['importe'];
            }
        }

        if (!empty($formas)) {
            $problemas[] = 'cada fila tiene que ser ' . implode(' o ', ProveedoresCategorias::FORMAS_CRONOGRAMA)
                . ', y ' . implode('; ', $formas);
        }

        if (!empty($importes)) {
            $problemas[] = 'cada fila tiene que traer su IMPORTE, mayor a cero, y '
                . implode('; ', $importes);
        }

        if ($importesOk) {
            $dif = round($suma - $base['neto'], 2);

            if (abs($dif) > self::TOLERANCIA_IMPORTE) {
                $problemas[] = ($base['retenido'] > 0)
                    ? lcfirst(rtrim(self::motivoImporte('Los importes suman ' . self::plata($suma), $suma, $base), '.'))
                    : 'los importes suman ' . self::plata($suma) . ' y el pendiente es '
                        . self::plata($base['importe_pendiente']) . ': la diferencia es de '
                        . self::plata($dif);
            }
        }

        if (!empty($problemas)) {
            $grupo = self::enError($grupo, 'Comprobante en dos medios (' . $lineas . '): '
                . implode('; ', $problemas) . '. No se carga ninguna de las dos filas.');

            return $grupo;
        }

        /* EL ORDEN SALE DE LA FECHA: 1/2 es la parte que se paga primero. Con
           la misma fecha -dos echeqs del mismo dia-, desempata el orden de las
           filas en el archivo, que es el que quien la armo eligio. */
        usort($grupo, function ($a, $b) {
            $porFecha = strcmp($a['fecha_pago'], $b['fecha_pago']);

            return ($porFecha !== 0) ? $porFecha : ($a['linea'] - $b['linea']);
        });

        $medios = [];

        foreach ($grupo as $i => $f) {
            $grupo[$i]['parte'] = $i + 1;
            $medios[] = ['orden' => $i + 1, 'fecha' => $f['fecha_pago'], 'forma' => $f['forma_pago'],
                         'importe' => round($f['importe'], 2)];
        }

        $clave = self::clavePago($base['cod_provee'], $base['t_comp'], $base['n_comp']);
        $existente = isset($pagos[$clave]) ? $pagos[$clave] : null;
        $antes = isset($aperturas[$clave]) ? $aperturas[$clave] : null;

        if ($antes !== null && self::mismaApertura($antes, $medios)) {
            $estado = 'SIN_CAMBIOS';
            $motivo = 'Ya estaba pagado con estos dos medios.';
        } elseif ($antes !== null) {
            $estado = 'CAMBIO';
            $motivo = 'Cambia la apertura en dos medios (estaba: ' . self::textoMedios($antes) . '). '
                . 'Queda: ' . self::textoMedios($medios) . '.';
        } elseif ($existente !== null) {
            $estado = 'CAMBIO';
            $motivo = 'Pasa a pagarse con dos medios: ' . self::textoMedios($medios) . '.'
                . ($existente['FECHA_PAGO'] !== null
                    ? ' Tenía una sola fecha, el ' . self::formatoCorto($existente['FECHA_PAGO']) . '.'
                    : '');
        } else {
            $estado = 'ALTA';
            $motivo = 'Se paga con dos medios: ' . self::textoMedios($medios) . '.';
        }

        /* LA FORMA VIGENTE SIGUE DECIDIENDO. Se acepta, pero se dice: quien
           abrio el comprobante en echeq y transferencia probablemente espera
           que entre al cronograma, y no va a entrar. */
        $aviso = null;

        if (!$base['cronograma']) {
            $aviso = 'Se sigue tratando como ' . ($base['forma_vigente'] ?: 'sin forma')
                . '; para que entre al cronograma, cambiá su forma en la grilla o el maestro.';
        }

        foreach ($grupo as $i => $f) {
            $grupo[$i]['estado'] = $estado;
            $grupo[$i]['motivo'] = $motivo;
            $grupo[$i]['medios'] = $medios;
            $grupo[$i]['aviso'] = $aviso;
            $grupo[$i]['antes'] = ($antes === null && $existente === null) ? null : [
                'fecha_pago' => $existente === null ? null : $existente['FECHA_PAGO'],
                'forma_pago' => $existente === null ? null : $existente['FORMA_PAGO'],
                'medios' => $antes
            ];
            $grupo[$i] = self::marcarConciliado($grupo[$i], $existente);
        }

        return $grupo;
    }

    /**
     * Tres filas o mas del mismo comprobante: error en TODAS. No hay tercer
     * medio, y elegir cuales dos valen seria decidir por quien armo la
     * planilla.
     *
     * @param array $grupo
     * @return array
     */
    private static function demasiadasFilas($grupo) {
        $lineas = array_column($grupo, 'linea');
        $ultima = array_pop($lineas);

        return self::enError($grupo, 'Este comprobante aparece en ' . count($grupo) . ' filas (líneas '
            . implode(', ', $lineas) . ' y ' . $ultima . '). Se puede pagar con dos medios como '
            . 'máximo, una fila por medio: dejá una o dos y volvé a importar.');
    }

    /** Todas las filas de un comprobante en error, con el mismo motivo */
    private static function enError($grupo, $motivo) {
        foreach ($grupo as $i => $f) {
            $grupo[$i]['estado'] = 'ERROR';
            $grupo[$i]['motivo'] = $motivo;
            $grupo[$i]['grupo'] = $grupo[0]['linea'];
            $grupo[$i]['partes'] = count($grupo);
            $grupo[$i]['parte'] = 0;
        }

        return $grupo;
    }

    /**
     * Pisar una conciliada se permite -puede ser una correccion- pero se
     * marca: Tango ya dijo que ese comprobante se pago.
     */
    private static function marcarConciliado($fila, $existente) {
        if ($fila['estado'] === 'CAMBIO' && $existente !== null
            && $existente['ESTADO'] === 'CONCILIADO') {
            $fila['motivo'] .= ' OJO: este comprobante ya figura CONCILIADO contra Tango, así que '
                . 'se pagó de verdad. Cambiarle la fecha prevista no cambia eso.';
        }

        return $fila;
    }

    /**
     * Si dos aperturas son la misma: los mismos medios, en el mismo orden, con
     * la misma fecha, la misma forma y el mismo importe al centavo.
     *
     * Estatica y pura.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    public static function mismaApertura($a, $b) {
        if (count($a) !== count($b)) {
            return false;
        }

        $a = array_values($a);
        $b = array_values($b);

        foreach ($a as $i => $m) {
            if ($m['fecha'] !== $b[$i]['fecha'] || $m['forma'] !== $b[$i]['forma']
                || round(floatval($m['importe']), 2) !== round(floatval($b[$i]['importe']), 2)) {
                return false;
            }
        }

        return true;
    }

    /** "ECHEQ $ 150.000,00 el 20/10/2026 y TRANSFERENCIA $ 50.000,00 el 27/10/2026" */
    private static function textoMedios($medios) {
        $partes = [];

        foreach ($medios as $m) {
            $partes[] = $m['forma'] . ' ' . self::plata($m['importe']) . ' el '
                . self::formatoCorto($m['fecha']);
        }

        return implode(' y ', $partes);
    }

    /**
     * Que pasa con una fila cuyo comprobante NO esta en los pendientes. Antes
     * era el mismo error exista o no en Tango; ahora se distingue, porque se
     * arreglan distinto -o no hay nada que arreglar-:
     *
     *   YA_PAGADA   existe en Tango para ese proveedor, no esta pendiente y
     *               tiene una cancelacion (CAN o PAG, con MAX(CPA05.F_COMP_CAN),
     *               el mismo criterio que la conciliacion). ADVERTENCIA: no se
     *               carga, no frena la confirmacion y se cuenta aparte. Sin
     *               TIPO_COMP, vale si TODOS los candidatos con ese numero estan
     *               cancelados.
     *   ERROR       no existe en Tango para ese proveedor: numero mal tipeado o
     *               proveedor equivocado. El motivo ya no dice "si ya se pago".
     *   ERROR       existe pero sin pago registrado y sin estar pendiente
     *               (estado *** de CPA04, sin imputaciones): no figura en cuenta
     *               corriente, asi que no se paga con orden de pago.
     *   ERROR       sigue PEN en Tango pero el listado lo deja afuera (clausula,
     *               saldo cero): como hoy, con el motivo de siempre.
     *
     * Sin $enTango -no se pudo consultar- queda el motivo de siempre.
     *
     * @param array $fila
     * @param array|null $enTango Mapa 'COD|N_COMP' => [['T_COMP', 'ESTADO',
     *        'FECHA_CANCELADO'], ...] de los comprobantes que no cruzaron
     * @param string $motivoDeSiempre
     * @return array
     */
    private static function sinPendiente($fila, $enTango, $motivoDeSiempre) {
        $fila['estado'] = 'ERROR';
        $fila['motivo'] = $motivoDeSiempre;

        if (!is_array($enTango)) {
            return $fila;
        }

        $cod = $fila['cod_provee'];
        $n = $fila['n_comp'];
        $t = $fila['t_comp'];
        $candidatos = [];

        foreach (isset($enTango[$cod . '|' . $n]) ? $enTango[$cod . '|' . $n] : [] as $c) {
            if ($t === '' || Planilla::codigo($c['T_COMP']) === $t) {
                $candidatos[] = $c;
            }
        }

        $comp = ($t === '' ? '' : $t . ' ') . $n;

        if (empty($candidatos)) {
            $fila['motivo'] = ($t === ''
                    ? 'El proveedor ' . $cod . ' no tiene ningún comprobante ' . $n . ' en Tango. '
                    : 'No existe el comprobante ' . $comp . ' del proveedor ' . $cod . ' en Tango. ')
                . 'Revisá el número' . ($t === '' ? '' : ', el tipo') . ' y el código del proveedor: '
                . 'puede estar mal tipeado o ser de otro proveedor.';

            return $fila;
        }

        $pagados = 0;
        $fechaReal = null;
        $tipos = [];

        foreach ($candidatos as $c) {
            $tipos[Planilla::codigo($c['T_COMP'])] = true;

            if ($c['ESTADO'] !== 'PEN' && $c['FECHA_CANCELADO'] !== null) {
                $pagados++;

                if ($fechaReal === null || $c['FECHA_CANCELADO'] > $fechaReal) {
                    $fechaReal = $c['FECHA_CANCELADO'];
                }
            }
        }

        if ($pagados === count($candidatos)) {
            $fila['estado'] = 'YA_PAGADA';
            $fila['fecha_pagada'] = $fechaReal;

            if ($t === '' && count($tipos) === 1) {
                $fila['t_comp'] = key($tipos);
            }

            $fila['motivo'] = 'Ya se pagó en Tango el ' . self::formatoCorto($fechaReal) . ' ('
                . implode('/', array_keys($tipos)) . ' ' . $n . '). No se carga y no hace falta: '
                . 'ya no está pendiente.';

            return $fila;
        }

        foreach ($candidatos as $c) {
            if ($c['ESTADO'] === 'PEN') {
                return $fila;
            }
        }

        $fila['motivo'] = 'El comprobante ' . $comp . ' del proveedor ' . $cod . ' está en Tango pero no '
            . 'figura en cuenta corriente: no tiene pagos imputados ni queda pendiente, así que no se '
            . 'paga con una orden de pago. Revisá si es el comprobante correcto.';

        return $fila;
    }

    /**
     * De las filas de una planilla, que comprobantes NO cruzan contra los
     * pendientes: son los unicos que hay que ir a mirar a Tango para saber si
     * ya se pagaron. Pura.
     *
     * NO SE CONSULTA TODA LA HISTORIA DE CPA04: la pregunta es por los que no
     * cruzaron, que son pocos.
     *
     * @param array $filasArchivo
     * @param array $pendientes
     * @return array Lista de ['cod', 'n']
     */
    public static function sinCruce($filasArchivo, $pendientes) {
        $hay = [];

        foreach (is_array($pendientes) ? $pendientes : [] as $p) {
            $hay[Planilla::codigo($p['COD_PROVEE']) . '|' . Planilla::codigo($p['N_COMP'])] = true;
        }

        $v = [];

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $f) {
            $cod = Planilla::codigo(isset($f['cod_provee']) ? $f['cod_provee'] : '');
            $n = Planilla::codigo(isset($f['n_comp']) ? $f['n_comp'] : '');

            if ($cod !== '' && $n !== '' && !isset($hay[$cod . '|' . $n])) {
                $v[$cod . '|' . $n] = ['cod' => $cod, 'n' => $n];
            }
        }

        return array_values($v);
    }

    /**
     * El estado en Tango de los comprobantes que no cruzaron: ESTADO de CPA04 y
     * la fecha real del pago, MAX(CPA05.F_COMP_CAN) -el criterio de la
     * conciliacion-.
     *
     * Se filtra por proveedor Y por numero, con dos IN, y el par exacto se
     * cruza en PHP: es la regla del repo, y con los dos filtros la consulta
     * trae un punado de filas. La fecha se pide en una segunda consulta, solo
     * para esos ID: correlacionada sobre CPA05, la primera tardaba el doble.
     *
     * Devuelve null si no se pudo consultar: la previsualizacion sigue, con el
     * motivo de siempre.
     *
     * @param array $pares De sinCruce()
     * @return array|null Mapa 'COD|N_COMP' => [['T_COMP', 'ESTADO', 'FECHA_CANCELADO']]
     */
    public function estadoComprobantesEnTango($pares) {
        if (empty($pares)) {
            return [];
        }

        try {
            $cid = $this->conectar();
            $cods = array_values(array_unique(array_column($pares, 'cod')));
            $nums = array_values(array_unique(array_column($pares, 'n')));
            $buscados = [];

            foreach ($pares as $p) {
                $buscados[$p['cod'] . '|' . $p['n']] = true;
            }

            $stmt = sqlsrv_query($cid,
                "SELECT a.ID_CPA04, a.COD_PROVEE, a.T_COMP, a.N_COMP, a.ESTADO
                 FROM CPA04 a
                 WHERE a.COD_PROVEE IN (" . implode(',', array_fill(0, count($cods), '?')) . ")
                   AND a.N_COMP IN (" . implode(',', array_fill(0, count($nums), '?')) . ")",
                array_merge($cods, $nums));

            if ($stmt === false) {
                return null;
            }

            $filas = [];

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $clave = Planilla::codigo($row['COD_PROVEE']) . '|' . Planilla::codigo($row['N_COMP']);

                if (isset($buscados[$clave])) {
                    $filas[intval($row['ID_CPA04'])] = ['clave' => $clave, 'T_COMP' => Planilla::codigo($row['T_COMP']),
                                                        'ESTADO' => strtoupper(trim((string) $row['ESTADO']))];
                }
            }

            sqlsrv_free_stmt($stmt);

            $fechas = [];

            if (!empty($filas)) {
                $ids = array_keys($filas);
                $stmt = sqlsrv_query($cid,
                    "SELECT ID_CPA04, MAX(F_COMP_CAN) AS F FROM CPA05
                     WHERE ID_CPA04 IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                     GROUP BY ID_CPA04", $ids);

                if ($stmt === false) {
                    return null;
                }

                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $fechas[intval($row['ID_CPA04'])] = Horizonte::normalizarFecha($row['F']);
                }

                sqlsrv_free_stmt($stmt);
            }

            $mapa = [];

            foreach ($filas as $id => $f) {
                $mapa[$f['clave']][] = ['T_COMP' => $f['T_COMP'], 'ESTADO' => $f['ESTADO'],
                                        'FECHA_CANCELADO' => isset($fechas[$id]) ? $fechas[$id] : null];
            }

            return $mapa;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Normaliza y valida una fila de la planilla de pagos contra los pendientes.
     *
     * Decide lo que se puede decidir mirando LA FILA SOLA: la fecha, el cruce y
     * que el importe sea un numero. Lo que depende de las otras filas del mismo
     * comprobante -si son dos medios, si suman el pendiente- lo decide
     * compararImportacion() despues.
     *
     * @param array $cruda
     * @param array $porClave Pendientes por clave completa
     * @param array $porNumero Pendientes por (proveedor, numero)
     * @param string $hoy
     * @param array|null $enTango Ver sinPendiente()
     * @return array
     */
    private static function filaPago($cruda, $porClave, $porNumero, $hoy, $enTango = null) {
        $valor = function ($campo) use ($cruda) {
            return isset($cruda[$campo]) ? trim((string) $cruda[$campo]) : '';
        };

        $fila = [
            'linea' => isset($cruda['linea']) ? intval($cruda['linea']) : 0,
            'cod_provee' => '',
            't_comp' => '',
            'n_comp' => '',
            'fecha_pago' => null,
            'forma_pago' => null,
            'forma_pago_orig' => '',
            'forma_desconocida' => false,
            'importe' => null,
            'observacion' => null,
            'razon_social' => '',
            'importe_pendiente' => 0.0,
            // Si ya se pago en Tango: la fecha real. Ver sinPendiente().
            'fecha_pagada' => null,
            /* La retencion y el NETO A PAGAR del comprobante, contra el que se
               compara el IMPORTE. Las pone conNeto() una vez cruzado. */
            'retencion' => null,
            'retenido' => 0.0,
            'retenciones' => [],
            'neto' => null,
            'fecha_vto' => null,
            'estaba_vencido' => false,
            // Cuantos vencimientos pendientes tiene: con mas de uno no se parte.
            'vencimientos' => 0,
            // La forma que decide, del pendiente: ver el aviso de dosMedios().
            'cronograma' => true,
            'forma_vigente' => null,
            // Una fila sola es la parte 1 de 1; dosMedios() lo cambia.
            'grupo' => isset($cruda['linea']) ? intval($cruda['linea']) : 0,
            'parte' => 1,
            'partes' => 1,
            'medios' => null,
            'aviso' => null,
            'estado' => 'ALTA',
            'motivo' => '',
            'antes' => null,
            /* LO QUE VINO EN EL ARCHIVO, tal cual. Es lo que el navegador
               devuelve al confirmar y lo que aplicarImportacion() vuelve a
               comparar contra la base: la confirmacion manda la entrada, no la
               decision. */
            'crudo' => [
                'linea' => isset($cruda['linea']) ? intval($cruda['linea']) : 0,
                'cod_provee' => $valor('cod_provee'),
                'n_comp' => $valor('n_comp'),
                'fecha_pago' => $valor('fecha_pago'),
                'forma_pago' => $valor('forma_pago'),
                'importe' => $valor('importe'),
                't_comp' => $valor('t_comp'),
                'observacion' => $valor('observacion')
            ]
        ];

        $cod = Planilla::codigo($valor('cod_provee'));
        $nComp = Planilla::codigo($valor('n_comp'));
        $tComp = Planilla::codigo($valor('t_comp'));

        $fila['cod_provee'] = $cod;
        $fila['n_comp'] = $nComp;
        $fila['t_comp'] = $tComp;

        if ($cod === '' || $nComp === '') {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'Falta el código de proveedor o el número de comprobante.';

            return $fila;
        }

        /* La fecha va antes que el cruce: sin fecha no hay nada que cargar,
           aunque el comprobante exista. */
        $fecha = Planilla::fecha($valor('fecha_pago'));

        if ($fecha === null) {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'La fecha de pago no se entiende: "' . $valor('fecha_pago')
                . '". Usá dd/mm/aaaa o aaaa-mm-dd.';

            return $fila;
        }

        $fila['fecha_pago'] = $fecha;

        /* EL CRUCE CONTRA LOS PENDIENTES. Lo que no cruza se mira en Tango, y
           se distingue porque se arregla distinto: ver sinPendiente(). */
        $pendiente = null;

        if ($tComp !== '') {
            $clave = self::clavePago($cod, $tComp, $nComp);
            $pendiente = isset($porClave[$clave]) ? $porClave[$clave] : null;

            if ($pendiente === null) {
                return self::sinPendiente($fila, $enTango, 'No hay ningún comprobante pendiente '
                    . $tComp . ' ' . $nComp . ' del proveedor ' . $cod . '. Revisá el tipo, el número '
                    . 'y el código; si ya se pagó, no hace falta cargarle fecha.');
            }
        } else {
            $candidatos = isset($porNumero[$cod . '|' . $nComp]) ? $porNumero[$cod . '|' . $nComp] : [];

            /* Un comprobante en cuotas tiene VARIOS vencimientos y aparece
               varias veces en los pendientes, pero es UN comprobante: eso no es
               ambiguedad. Lo que si lo es son dos TIPOS distintos con el mismo
               numero. */
            $tipos = [];

            foreach ($candidatos as $c) {
                $tipos[Planilla::codigo($c['T_COMP'])] = true;
            }

            if (count($tipos) === 0) {
                return self::sinPendiente($fila, $enTango, 'El proveedor ' . $cod . ' no tiene ningún '
                    . 'comprobante ' . $nComp . ' pendiente. Puede ser un número mal tipeado, un '
                    . 'proveedor equivocado, o una factura que ya se pagó.');
            }

            if (count($tipos) > 1) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = 'El proveedor ' . $cod . ' tiene el comprobante ' . $nComp
                    . ' con más de un tipo (' . implode(', ', array_keys($tipos)) . '). '
                    . 'Agregá la columna TIPO_COMP para aclarar cuál es.';

                return $fila;
            }

            $fila['t_comp'] = key($tipos);
            $pendiente = $candidatos[0];
        }

        $fila['razon_social'] = isset($pendiente['RAZON_SOC']) ? $pendiente['RAZON_SOC'] : '';
        $fila['fecha_vto'] = isset($pendiente['FECHA_VTO']) ? $pendiente['FECHA_VTO'] : null;
        $fila['cronograma'] = isset($pendiente['CRONOGRAMA']) ? !empty($pendiente['CRONOGRAMA']) : true;
        $fila['forma_vigente'] = isset($pendiente['FORMA_PAGO_VIGENTE'])
            ? $pendiente['FORMA_PAGO_VIGENTE'] : null;

        /* El importe del comprobante es la SUMA de sus vencimientos pendientes:
           la fecha de pago se carga por comprobante, no por cuota, asi que lo
           que se esta reubicando es todo lo que se le debe. */
        $candidatos = isset($porNumero[$cod . '|' . $nComp]) ? $porNumero[$cod . '|' . $nComp] : [$pendiente];

        foreach ($candidatos as $c) {
            if (Planilla::codigo($c['T_COMP']) !== $fila['t_comp']) {
                continue;
            }

            $fila['importe_pendiente'] += floatval($c['IMPORTE_PENDIENTE']);
            $fila['vencimientos']++;

            if (!empty($c['SIN_FECHA_CARGADA'])) {
                $fila['estaba_vencido'] = true;
            }
        }

        $fila['importe_pendiente'] = round($fila['importe_pendiente'], 2);

        /* La forma de pago: la de la planilla, y si no vino, la habitual del
           proveedor segun el maestro. Ver la nota de columnasImportacion(). */
        $forma = ProveedoresCategorias::normalizarFormaPago($valor('forma_pago'));

        if ($forma['original'] === '') {
            $fila['forma_pago'] = isset($pendiente['FORMA_PAGO']) ? $pendiente['FORMA_PAGO'] : null;
        } else {
            $fila['forma_pago'] = $forma['normalizado'];
            $fila['forma_pago_orig'] = $forma['original'];
            $fila['forma_desconocida'] = ($forma['normalizado'] === null);
        }

        $obs = $valor('observacion');
        $fila['observacion'] = ($obs === '') ? null : mb_substr($obs, 0, 200);

        /* UN IMPORTE QUE NO ES UN NUMERO ES UN ERROR, y no "vacio": vacio
           quiere decir "todo lo pendiente", y tomar el pendiente porque la
           celda decia "mil pesos" seria decidir por quien armo la planilla. */
        if ($valor('importe') !== '') {
            $importe = Planilla::numero($valor('importe'));

            if ($importe === null) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = 'El importe no se entiende: "' . $valor('importe') . '". Va sin '
                    . 'separador de miles, por ejemplo 1069326,50.';

                return $fila;
            }

            $fila['importe'] = round($importe, 2);
        }

        return $fila;
    }

    /**
     * Los avisos del resumen de importacion de pagos.
     *
     * EL PRIMERO ES EL QUE IMPORTA: cuanto de lo vencido queda resuelto. Es la
     * razon de ser de esta importacion, y verlo antes de confirmar es lo que
     * permite saber si la planilla cubrio lo que tenia que cubrir.
     *
     * Estaticos y puros.
     *
     * @param array $resumen
     * @return array
     */
    private static function avisosImportacionPagos($resumen) {
        $avisos = [];

        if ($resumen['vencidos_resueltos'] > 0) {
            $avisos[] = $resumen['vencidos_resueltos'] . ' comprobante(s) que hoy están '
                . 'apilados en el primer día del eje por estar vencidos sin fecha pasan a '
                . 'tener una. Es lo que esta importación viene a resolver.';
        }

        if ($resumen['dos_medios'] > 0) {
            $avisos[] = $resumen['dos_medios'] . ' comprobante(s) quedan pagados con dos medios: '
                . 'cada parte entra al eje con su importe y su fecha.'
                . ($resumen['dos_medios_fuera_cronograma'] > 0
                    ? ' ' . $resumen['dos_medios_fuera_cronograma'] . ' de ellos no son del '
                        . 'cronograma por su forma vigente y se siguen tratando con esa forma: '
                        . 'mirá la marca en cada uno.'
                    : '');
        }

        if ($resumen['errores'] > 0) {
            $avisos[] = $resumen['errores'] . ' fila(s) no se pueden cargar y quedan afuera. '
                . 'El resto se importa igual: mirá el motivo de cada una, porque no todas '
                . 'fallan por lo mismo. Un comprobante en dos medios con una fila mala no carga '
                . 'ninguna de las dos.';
        }

        if ($resumen['ya_pagadas'] > 0) {
            $avisos[] = $resumen['ya_pagadas'] . ' fila(s) son de facturas que ya se pagaron en Tango: '
                . 'no se cargan, y no hace falta. No frenan la importación del resto; cada una dice la '
                . 'fecha real del pago.';
        }

        if ($resumen['forma_desconocida'] > 0) {
            $avisos[] = $resumen['forma_desconocida'] . ' fila(s) traen una forma de pago que '
                . 'no está en la lista de válidas. Se guarda tal como vino.';
        }

        if ($resumen['altas'] === 0 && $resumen['cambios'] === 0 && $resumen['errores'] === 0
            && $resumen['ya_pagadas'] === 0) {
            $avisos[] = 'El archivo no cambia nada: todo lo que trae ya estaba cargado igual.';
        }

        return $avisos;
    }

    /**
     * Que se escribe para un comprobante importado: la fila de la tabla de
     * pagos y, si se paga con dos medios, su apertura. ES LA REGLA, escrita una
     * vez y pura, para poder probarla sin base.
     *
     * UN MEDIO: la fecha, la forma y la observacion, como siempre. Sin
     * apertura -null-, y eso es lo que da de baja la que hubiera.
     *
     * DOS MEDIOS: en la tabla de pagos va LA FECHA MAS TARDIA de las dos
     * partes. No es una copia de mas, y es lo que hace funcionar tres cosas sin
     * codigo nuevo:
     *
     *   - la conciliacion compara la fecha prevista contra la real, y para un
     *     comprobante pagado en dos veces el desvio se mide contra la ultima;
     *   - si el pendiente deja de coincidir con la apertura -un pago parcial
     *     en Tango-, abrirEnMedios() no lo parte y todo lo que queda se
     *     proyecta en esa fecha, con la jerarquia de siempre;
     *   - el comprobante sigue figurando como "con fecha cargada" para los
     *     indicadores y el fechado masivo.
     *
     * La forma va en NULL en la tabla de pagos: los medios viven en la
     * apertura, uno por parte. Las observaciones de las dos filas se juntan.
     *
     * NUNCA ESCRIBE FORMA_PAGO_CRONOGRAMA. Esa columna es una REGLA que alguien
     * puso a mano para una factura, y la planilla trae HECHOS -por que via sale
     * cada pago-. Si la importacion la escribiera, subir la planilla
     * reclasificaria comprobantes dentro y fuera del cashflow sin que nadie lo
     * haya pedido. Ver formaDelCronograma(). Hay prueba.
     *
     * Estatica y pura.
     *
     * @param array $filas Las filas de UN comprobante, de compararImportacion()
     * @return array ['pago' => mapa columna => valor, 'medios' => array|null]
     */
    public static function camposDeImportacion($filas) {
        $filas = array_values($filas);

        if (count($filas) === 1) {
            $f = $filas[0];

            return [
                'pago' => [
                    'FECHA_PAGO' => $f['fecha_pago'],
                    'FORMA_PAGO' => $f['forma_pago'],
                    'FORMA_PAGO_ORIG' => ($f['forma_pago_orig'] === '') ? null : $f['forma_pago_orig'],
                    'OBSERVACION' => $f['observacion']
                ],
                'medios' => null
            ];
        }

        usort($filas, function ($a, $b) { return $a['parte'] - $b['parte']; });

        $medios = [];
        $ultima = null;
        $obs = [];

        foreach ($filas as $f) {
            $medios[] = [
                'orden' => $f['parte'],
                'fecha' => $f['fecha_pago'],
                'forma' => $f['forma_pago'],
                'forma_orig' => ($f['forma_pago_orig'] === '') ? null : $f['forma_pago_orig'],
                'importe' => round(floatval($f['importe']), 2)
            ];

            if ($ultima === null || $f['fecha_pago'] > $ultima) {
                $ultima = $f['fecha_pago'];
            }

            if ($f['observacion'] !== null && !in_array($f['observacion'], $obs, true)) {
                $obs[] = $f['observacion'];
            }
        }

        return [
            'pago' => [
                'FECHA_PAGO' => $ultima,
                'FORMA_PAGO' => null,
                'FORMA_PAGO_ORIG' => null,
                'OBSERVACION' => empty($obs) ? null : mb_substr(implode(' · ', $obs), 0, 200)
            ],
            'medios' => $medios
        ];
    }

    /**
     * En que difiere lo que se previsualizo de lo que hoy daria la misma
     * planilla. Vacio si es lo mismo.
     *
     * Se compara el ESTADO de cada linea, que es lo que la persona vio y
     * aprobo: si una fila que se mostro como ALTA hoy da ERROR -porque entre
     * la previsualizacion y la confirmacion se pago una parte en Tango, o
     * alguien cargo otra fecha-, lo que se confirmaria no es lo que se miro.
     *
     * Estatica y pura.
     *
     * @param array $vistas Filas de la comparacion que vino del navegador
     * @param array $actuales Filas de la comparacion recalculada
     * @return array Textos, uno por linea que cambio
     */
    public static function diferenciasConLoVisto($vistas, $actuales) {
        $antes = [];
        $ahora = [];
        $netoAntes = [];

        foreach (is_array($vistas) ? $vistas : [] as $f) {
            $l = intval(isset($f['linea']) ? $f['linea'] : 0);
            $antes[$l] = isset($f['estado']) ? $f['estado'] : '';
            $netoAntes[$l] = isset($f['neto']) ? $f['neto'] : null;
        }

        foreach ($actuales as $f) {
            $ahora[intval($f['linea'])] = $f['estado'];
        }

        $difs = [];

        foreach ($actuales as $f) {
            $linea = intval($f['linea']);
            $visto = isset($antes[$linea]) ? $antes[$linea] : null;

            if ($visto !== $f['estado']) {
                $difs[] = 'línea ' . $linea . ': ' . ($visto === null ? 'no estaba' : $visto)
                    . ' → ' . $f['estado'];
                continue;
            }

            /* EL MISMO ESTADO CON OTRO NETO TAMBIEN ES OTRA COSA: si entre la
               previsualizacion y la confirmacion cambio una alicuota, lo que se
               confirmaria no es lo que se miro. */
            $na = isset($netoAntes[$linea]) ? $netoAntes[$linea] : null;

            if ($f['estado'] !== 'ERROR' && $na !== null && $f['neto'] !== null
                && abs(round(floatval($na) - floatval($f['neto']), 2)) > 0.004) {
                $difs[] = 'línea ' . $linea . ': el neto a pagar pasó de ' . self::plata($na) . ' a '
                    . self::plata($f['neto']) . ' (cambió una alícuota de retención)';
            }
        }

        foreach ($antes as $linea => $estado) {
            if (!isset($ahora[$linea])) {
                $difs[] = 'línea ' . $linea . ': ya no está';
            }
        }

        return $difs;
    }

    /**
     * Aplica una importacion de pagos ya confirmada.
     *
     * LO QUE VINO DEL NAVEGADOR ES UN PEDIDO, NO UNA AUTORIZACION. Antes se le
     * creia al diff que se reenviaba; desde que la planilla puede abrir un
     * comprobante en dos medios -y la regla de que las partes sumen el
     * pendiente no puede depender de la pantalla- se vuelve a calcular el diff
     * con la entrada del archivo ('crudo') contra los pendientes, los pagos y
     * las aperturas de AHORA, antes de abrir nada. Si alguna linea da distinto
     * de lo que se previsualizo, NO SE APLICA NADA: aplicar "la parte que
     * sobrevive" seria aplicar algo que nadie miro. Mismo criterio que el
     * maestro (ProveedoresCategorias::aplicarImportacion()) y que Cob.
     * Electronicos.
     *
     * TODO EN UNA TRANSACCION, y solo los comprobantes ALTA y CAMBIO: los que
     * estan en error no se tocan. Importar lo que se pueda es mejor que parar
     * todo por una fila mala, que obligaria a corregir la planilla entera antes
     * de poder cargar las buenas. Un comprobante en dos medios escribe su fila
     * de pagos y su apertura juntas, en la misma transaccion.
     *
     * @param array $comparacion Lo que devolvio compararImportacion(), tal como
     *        volvio del navegador
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'
     * @return array ['altas', 'cambios'] por comprobante
     */
    public function aplicarImportacion($comparacion, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $vistas = isset($comparacion['filas']) && is_array($comparacion['filas'])
            ? $comparacion['filas'] : [];
        $crudas = [];

        foreach ($vistas as $f) {
            if (!isset($f['crudo']) || !is_array($f['crudo'])) {
                throw new Exception('La previsualización que llegó no trae lo que decía el archivo. '
                    . 'Volvé a subirlo y a previsualizar.');
            }

            $crudas[] = $f['crudo'];
        }

        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $conMedios = $this->tablaMedioCreada();

        /* CON LAS ALICUOTAS DE AHORA: si alguna cambio desde la
           previsualizacion, el neto de esa linea cambio y no se aplica nada. */
        $this->retenciones->olvidar();
        $pendientes = $this->getPendientes($hoyStr);
        $actual = self::compararImportacion($crudas, $pendientes, $this->getPagos(),
            $hoyStr, $this->getAperturas(), $conMedios, $this->retenciones->config(),
            $this->estadoComprobantesEnTango(self::sinCruce($crudas, $pendientes)));

        $difs = self::diferenciasConLoVisto($vistas, $actual['filas']);

        if (!empty($difs)) {
            throw new Exception('Algo cambió desde que se previsualizó la planilla ('
                . implode('; ', array_slice($difs, 0, 5))
                . (count($difs) > 5 ? '; y ' . (count($difs) - 5) . ' más' : '')
                . '), así que no se importó nada. Volvé a previsualizar.');
        }

        // Las filas de cada comprobante, juntas.
        $porComprobante = [];

        foreach ($actual['filas'] as $fila) {
            if ($fila['estado'] !== 'ALTA' && $fila['estado'] !== 'CAMBIO') {
                continue;
            }

            $porComprobante[self::clavePago($fila['cod_provee'], $fila['t_comp'], $fila['n_comp'])][]
                = $fila;
        }

        $cid = $this->conectar();
        $aplicadas = ['altas' => 0, 'cambios' => 0];

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($porComprobante as $filas) {
                $f = $filas[0];
                $campos = self::camposDeImportacion($filas);

                /* LA IMPORTACION NO TOCA FORMA_PAGO_CRONOGRAMA: ver
                   camposDeImportacion(). */
                $this->guardarPago($cid, $f['cod_provee'], $f['t_comp'], $f['n_comp'],
                    $campos['pago'], 'ARCHIVO', $usuario);

                /* La apertura va con la fila, en la misma transaccion. Con un
                   medio se manda null, y eso da de baja la que hubiera: es lo
                   que hace que reimportar con una fila deshaga los dos medios. */
                if ($conMedios) {
                    $this->guardarApertura($cid, $f['cod_provee'], $f['t_comp'], $f['n_comp'],
                        $campos['medios'], $usuario);
                }

                $aplicadas[$f['estado'] === 'ALTA' ? 'altas' : 'cambios']++;
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la importación'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $aplicadas;
    }

    /**
     * Escribe la apertura en dos medios de un comprobante. ES EL UNICO LUGAR
     * DEL MODULO QUE TOCA LA TABLA DE MEDIOS, igual que guardarPago() con la de
     * pagos, y corre siempre dentro de la transaccion de quien llama.
     *
     * REEMPLAZA LA APERTURA ENTERA, sin bajas fisicas: las partes vigentes
     * pasan a VIGENTE = 0 con quien y cuando, y se insertan las nuevas. Con
     * $medios en null o vacio solo se da de baja: es lo que hacen reimportar
     * con una fila y el boton de volver al vencimiento.
     *
     * @param resource $cid Conexion con la transaccion abierta
     * @param string $cod
     * @param string $t
     * @param string $n
     * @param array|null $medios De camposDeImportacion()
     * @param string $usuario
     */
    private function guardarApertura($cid, $cod, $t, $n, $medios, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_MEDIO . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND VIGENTE = 1",
            [$usuario, $usuario, $cod, $t, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la apertura en dos medios'));
        }

        sqlsrv_free_stmt($stmt);

        foreach (is_array($medios) ? $medios : [] as $m) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA_MEDIO . "
                    (COD_PROVEE, T_COMP, N_COMP, ORDEN, FECHA_PAGO, FORMA_PAGO, FORMA_PAGO_ORIG,
                     IMPORTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$cod, $t, $n, intval($m['orden']), $m['fecha'], $m['forma'],
                 isset($m['forma_orig']) ? $m['forma_orig'] : null,
                 round(floatval($m['importe']), 2), $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar la apertura en dos medios'));
            }

            sqlsrv_free_stmt($stmt);
        }
    }

    /**
     * Saca de un lote los comprobantes pagados con dos medios.
     *
     * El fechado masivo pone UNA fecha, y un comprobante abierto tiene dos: su
     * fecha se cambia reimportando o con el boton de volver al vencimiento. Se
     * dice en el dialogo antes de confirmar, y se vuelve a filtrar aca porque
     * el endpoint se alcanza sin pasar por la pantalla.
     *
     * Estatica y pura.
     *
     * @param array $claves Las de normalizarClaves()
     * @param array $aperturas Mapa de getAperturas()
     * @return array [claves que se escriben, cantidad omitida]
     */
    public static function sinAbiertos($claves, $aperturas) {
        $quedan = [];
        $omitidos = 0;

        foreach ($claves as $clave => $c) {
            if (isset($aperturas[$clave])) {
                $omitidos++;
            } else {
                $quedan[$clave] = $c;
            }
        }

        return [$quedan, $omitidos];
    }

    /**
     * Guarda la fecha de pago de un comprobante, de a uno.
     *
     * Es lo que usa la edicion fila por fila de la grilla. La importacion masiva
     * pasa por el mismo metodo privado, asi que las dos escriben igual.
     *
     * LO QUE NO SE MANDA NO SE PISA. La grilla edita UNA celda -la fecha- y
     * manda solo esa; si los campos que no viajaron se escribieran igual, cada
     * carga de fecha borraria la forma de pago y la observacion que habia
     * dejado la importacion de la planilla. Un endpoint que recibe un campo y
     * escribe cuatro no esta guardando una edicion, esta reemplazando la fila.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param string $fecha 'Y-m-d'
     * @param string|null $formaPago Si no viene, la que haya queda como esta
     * @param string|null $observacion Idem
     * @param string $usuario
     * @return array ['fecha', 'forma']
     */
    public function savePago($codProvee, $tComp, $nComp, $fecha, $formaPago, $observacion,
                             $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $cod = Planilla::codigo($codProvee);
        $t = Planilla::codigo($tComp);
        $n = Planilla::codigo($nComp);

        if ($cod === '' || $t === '' || $n === '') {
            throw new Exception('Falta el proveedor o el comprobante al que corresponde la '
                . 'fecha de pago.');
        }

        $f = self::validarFechaPago($fecha);
        $forma = ProveedoresCategorias::normalizarFormaPago($formaPago);
        $obs = ($observacion === null) ? '' : trim((string) $observacion);

        /* UN COMPROBANTE PAGADO CON DOS MEDIOS TIENE DOS FECHAS, y una fecha
           tipeada en la celda no dice a cual de las dos reemplaza. La grilla no
           ofrece el campo para esas filas; esto es para quien llegue al
           endpoint por su cuenta. */
        if (isset($this->getAperturas()[self::clavePago($cod, $t, $n)])) {
            throw new Exception('Este comprobante está pagado con dos medios, así que tiene dos '
                . 'fechas. Se cambian reimportando la planilla de pagos, o con el botón de volver '
                . 'al vencimiento, que borra la apertura entera.');
        }

        /* Ver la nota del docblock de guardarPago(): lo que no entra al mapa no
           se escribe. Vacio y ausente son lo mismo acá porque la grilla no tiene
           con que mandar un vacio a proposito: edita la fecha y nada mas. */
        $campos = ['FECHA_PAGO' => $f];

        if ($forma['original'] !== '') {
            $campos['FORMA_PAGO'] = $forma['normalizado'];
            $campos['FORMA_PAGO_ORIG'] = $forma['original'];
        }

        if ($obs !== '') {
            $campos['OBSERVACION'] = mb_substr($obs, 0, 200);
        }

        $this->guardarPago($this->conectar(), $cod, $t, $n, $campos, 'MANUAL', $usuario);

        return ['fecha' => $f, 'forma' => $forma['normalizado']];
    }

    /**
     * Pone LA MISMA FECHA DE PAGO a varias facturas, en UNA transaccion.
     *
     * ES EL MISMO GESTO QUE LA EXCLUSION MASIVA y existe por el mismo motivo: el
     * caso real no es una factura, son las ocho de un proveedor a las que se les
     * decide una fecha de una vez. Cargarlas de a una es justo el trabajo que la
     * barra de seleccion viene a sacar, y es ademas lo que hay que hacer
     * cientos de veces para vaciar el indicador de vencido sin fecha.
     *
     * UNA SOLA FECHA PARA TODAS, y no es una simplificacion de la pantalla:
     * "a este proveedor le pagamos el 30" es UNA decision. Cuando las fechas son
     * distintas son decisiones distintas, y esas se cargan celda por celda, que
     * es lo que la grilla ya hacia y se sigue pudiendo hacer.
     *
     * SOLO ESCRIBE LA FECHA. La forma del cronograma, la exclusion y la
     * observacion de cada factura son otras decisiones: un gesto que recibe una
     * fecha y escribe cuatro columnas no esta guardando una edicion, esta
     * reemplazando la fila. Es la misma regla que ya defiende guardarPago(), y
     * aca pesa mas porque son muchas filas de una.
     *
     * NO DESCONCILIA NADA. Si alguna de las elegidas ya estaba conciliada contra
     * Tango sigue estandolo, con su fecha real intacta: Tango es la verdad sobre
     * el pago y esto es una prevision. La pantalla dice cuantas de las
     * seleccionadas ya tenian fecha antes de que se confirme.
     *
     * LA FECHA SE VALIDA UNA VEZ Y ANTES DE ABRIR LA TRANSACCION, igual que las
     * claves: es la misma para todas, asi que una fecha invalida no puede
     * descubrirse con diez facturas ya escritas.
     *
     * NO SE VALIDA CONTRA LOS PENDIENTES DE HOY, por lo mismo que la exclusion
     * masiva: la fila de override vive por comprobante y puede existir para uno
     * que hoy no esta pendiente.
     *
     * LOS COMPROBANTES PAGADOS CON DOS MEDIOS NO SE TOCAN: tienen dos fechas, y
     * una sola para todas no dice a cual reemplaza. Se saltean y se devuelve
     * cuantos fueron; la pantalla lo dice antes de confirmar. Ver sinAbiertos().
     *
     * @param array $comprobantes Filas con 'cod_provee', 't_comp', 'n_comp'
     * @param mixed $fecha 'Y-m-d'
     * @param string $usuario
     * @return array ['fecha' => string, 'tocados' => int, 'omitidos' => int]
     */
    public function saveFechaMasiva($comprobantes, $fecha, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $claves = self::normalizarClaves($comprobantes, 'fechar');
        $f = self::validarFechaPago($fecha);

        list($claves, $omitidos) = self::sinAbiertos($claves, $this->getAperturas());

        if (empty($claves)) {
            throw new Exception('Los ' . $omitidos . ' comprobante(s) elegidos están pagados con dos '
                . 'medios: sus fechas se cambian reimportando la planilla de pagos.');
        }

        $this->guardarLote($claves, ['FECHA_PAGO' => $f], 'MANUAL', $usuario);

        return ['fecha' => $f, 'tocados' => count($claves), 'omitidos' => $omitidos];
    }

    /**
     * Pone o saca el OVERRIDE DE FORMA DE PAGO de un comprobante.
     *
     * ES UNA REGLA POR FACTURA, no un hecho: dice con que forma hay que tratar a
     * ESTE comprobante para decidir si entra al cronograma del cashflow. Pisa a
     * la del maestro solo para el, y NO TOCA EL MAESTRO: las otras facturas del
     * mismo proveedor siguen como estaban.
     *
     * VA EN SU PROPIA COLUMNA y no en FORMA_PAGO, que es el hecho que escribe la
     * importacion de la planilla en todas sus filas. Ver formaDelCronograma().
     *
     * SACARLO ES MANDAR VACIO, y entonces vuelve a decidir el maestro. No borra
     * la fila: la fecha de pago y la observacion que esa fila tenga siguen
     * estando, porque son otra cosa.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param string|null $forma Una de FORMAS_PAGO, o vacio para sacar el override
     * @param string $usuario
     * @return array ['forma' => string|null]
     */
    public function saveFormaCronograma($codProvee, $tComp, $nComp, $forma, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de overrides por comprobante. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        if (!$this->tieneColumnaPago('FORMA_PAGO_CRONOGRAMA')) {
            throw new Exception('Todavía no se puede fijar la forma de pago por factura. '
                . 'Corré sql/cashflow_prov_locales_forma_por_factura.sql contra la base '
                . 'central.');
        }

        $cod = Planilla::codigo($codProvee);
        $t = Planilla::codigo($tComp);
        $n = Planilla::codigo($nComp);

        if ($cod === '' || $t === '' || $n === '') {
            throw new Exception('Falta el proveedor o el comprobante.');
        }

        $v = trim((string) $forma);
        $normalizada = null;

        /* SE VALIDA CONTRA LA LISTA y no se acepta cualquier texto: esta forma
           DECIDE si el importe entra al cashflow, asi que una que no este en
           FORMAS_PAGO no decidiria nada y quedaria como un override que parece
           puesto y no hace nada. Es lo contrario de FORMA_PAGO, que guarda lo
           que diga la planilla porque describe un hecho.

           SE NORMALIZA CON normalizarFormaPago() y no con un mb_strtoupper y un
           isset: FORMAS_PAGO es una LISTA -no un mapa- asi que indexarla por
           nombre da siempre false y rechazaba todos los overrides. Y ademas esa
           es la unica normalizacion del modulo: la que ignora mayusculas,
           acentos y espacios, para que 'echeq' y 'Mercado Pago' matcheen igual
           que en la importacion. */
        if ($v !== '') {
            $norm = ProveedoresCategorias::normalizarFormaPago($v);

            if ($norm['normalizado'] === null) {
                throw new Exception('"' . $v . '" no es una forma de pago conocida. Las '
                    . 'válidas son: '
                    . implode(', ', ProveedoresCategorias::FORMAS_PAGO) . '.');
            }

            $normalizada = $norm['normalizado'];
        }

        $this->guardarPago($this->conectar(), $cod, $t, $n,
            ['FORMA_PAGO_CRONOGRAMA' => $normalizada], 'MANUAL', $usuario);

        return ['forma' => $normalizada];
    }

    /**
     * Borra la fecha de pago de un comprobante: vuelve a valer el vencimiento.
     *
     * BORRA LA FECHA, NO LA FILA. Esto CAMBIO. La tabla dejo de ser "las fechas
     * de pago" y paso a ser "los overrides de este comprobante" -la fecha, la
     * forma con la que se lo trata, si se lo excluye y con que motivo, y lo que
     * trajo la planilla de pagos-, y el boton "Volver a proyectar al
     * vencimiento" hacia un DELETE de la fila entera: se llevaba puesta la
     * exclusion con su motivo, el override de forma y la observacion, que son
     * decisiones que nadie pidio deshacer.
     *
     * Ahora son dos pasos, en una transaccion:
     *
     *   1. FECHA_PAGO y FUENTE_FECHA a NULL. Eso solo ya hace caer al
     *      comprobante al vencimiento: resolverFechaPago() no ve fecha cargada.
     *   2. Si a la fila no le queda NADA -ver condicionFilaVacia()-, se borra.
     *      Una fila vacia no es un dato: es un override que ya no dice nada, y
     *      dejarla seria indistinguible de no tenerla. Ahi si hay borrado
     *      fisico, como en la fecha manual de Cobranzas FR.
     *
     * SIN sql/cashflow_prov_locales_forma_por_factura.sql FECHA_PAGO NO ADMITE
     * NULL, y el paso 1 no se puede dar. Ahi se hace lo de antes, el DELETE
     * directo: sin ese script tampoco existen la forma por factura ni la
     * exclusion -dependen de el-, asi que lo unico que se pierde es la forma y
     * la observacion de la planilla, que es exactamente lo que se perdia antes.
     *
     * UN COMPROBANTE PAGADO CON DOS MEDIOS SE DESARMA ENTERO: su apertura se
     * da de baja en la misma transaccion (guardarApertura() con null), y el
     * comprobante vuelve a su vencimiento o al cronograma. La exclusion, el
     * override de forma y la observacion quedan, igual que con una fecha sola.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param string $usuario Firma el UPDATE. El DELETE de la fila vacia no deja
     *        rastro de quien: no queda fila donde anotarlo
     * @return bool Si habia una fecha cargada
     */
    public function deletePago($codProvee, $tComp, $nComp, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago.');
        }

        $clave = [Planilla::codigo($codProvee), Planilla::codigo($tComp),
                  Planilla::codigo($nComp)];
        $cid = $this->conectar();
        $conMedios = $this->tablaMedioCreada();

        // La columna la crea el mismo script que hace nullable a FECHA_PAGO.
        $sinNullable = !$this->tieneColumnaPago('FORMA_PAGO_CRONOGRAMA');

        $opcionales = $sinNullable ? [] : array_values(array_filter(
            ['FORMA_PAGO_CRONOGRAMA', 'EXCLUIDA', 'MOTIVO_EXCLUSION', 'FUENTE_FECHA'],
            [$this, 'tieneColumnaPago']));

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            /* UN COMPROBANTE PAGADO CON DOS MEDIOS VUELVE ENTERO. La apertura se
               da de baja en la misma transaccion que la fecha: con la fecha
               borrada y la apertura viva, el comprobante quedaria partido sin
               fecha en la tabla de pagos. La exclusion, el override de forma y
               la observacion no se tocan: viven en la tabla de pagos y este
               boton borra la fecha, no la fila. */
            if ($conMedios) {
                $this->guardarApertura($cid, $clave[0], $clave[1], $clave[2], null, $usuario);
            }

            if ($sinNullable) {
                $stmt = sqlsrv_query($cid,
                    "DELETE FROM dbo." . self::TABLA_PAGO . "
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?", $clave);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al borrar la fecha de pago'));
                }

                $filas = sqlsrv_rows_affected($stmt);
                sqlsrv_free_stmt($stmt);

                if (sqlsrv_commit($cid) === false) {
                    throw new Exception($this->errorSql('No se pudo confirmar el borrado'));
                }

                return ($filas > 0);
            }

            $stmt = sqlsrv_query($cid,
                "UPDATE dbo." . self::TABLA_PAGO . "
                 SET FECHA_PAGO = NULL"
                    . (in_array('FUENTE_FECHA', $opcionales, true) ? ', FUENTE_FECHA = NULL' : '')
                    . ", " . Auditoria::SET_MODIF . "
                 WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?
                   AND FECHA_PAGO IS NOT NULL", array_merge([$usuario], $clave));

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al borrar la fecha de pago'));
            }

            $habia = (sqlsrv_rows_affected($stmt) > 0);
            sqlsrv_free_stmt($stmt);

            $stmt = sqlsrv_query($cid,
                "DELETE FROM dbo." . self::TABLA_PAGO . "
                 WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?
                   AND " . self::condicionFilaVacia($opcionales), $clave);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al limpiar la fila sin datos'));
            }

            sqlsrv_free_stmt($stmt);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el borrado'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $habia;
    }

    /**
     * La condicion SQL de "a esta fila de overrides no le queda nada".
     *
     * Es lo que decide si deletePago() borra la fila despues de sacarle la
     * fecha. NADA quiere decir ninguna de estas cosas:
     *
     *   FECHA_PAGO                 la fecha, que se acaba de sacar
     *   FORMA_PAGO / _ORIG         la forma con la que salio el pago, segun la
     *                              planilla: un HECHO, no se tira
     *   OBSERVACION                lo que dijo la planilla o una persona
     *   ESTADO = 'CONCILIADO'      Tango ya dijo que se pago: es la prevision
     *                              contra la realidad, que es para lo que se
     *                              concilia
     *   FORMA_PAGO_CRONOGRAMA      el override de forma de esta factura
     *   EXCLUIDA / MOTIVO          la exclusion de esta factura
     *
     * LAS TRES ULTIMAS SON DE SCRIPTS POSTERIORES y entran solo si la columna
     * existe: preguntar por una que no esta seria un error de SQL. FUENTE_FECHA
     * no se pregunta: sin fecha no tiene nada que decir, y deletePago() la acaba
     * de poner en NULL.
     *
     * Estatica y pura: devuelve texto, no toca la base. Los nombres son
     * constantes del codigo, nunca algo que mande el cliente.
     *
     * @param array $opcionales Columnas opcionales que la tabla tiene
     * @return string
     */
    public static function condicionFilaVacia($opcionales) {
        $conds = [
            'FECHA_PAGO IS NULL',
            'FORMA_PAGO IS NULL',
            'FORMA_PAGO_ORIG IS NULL',
            'OBSERVACION IS NULL',
            "ESTADO <> 'CONCILIADO'"
        ];

        if (in_array('FORMA_PAGO_CRONOGRAMA', $opcionales, true)) {
            $conds[] = 'FORMA_PAGO_CRONOGRAMA IS NULL';
        }

        if (in_array('EXCLUIDA', $opcionales, true)) {
            $conds[] = 'EXCLUIDA = 0';
        }

        if (in_array('MOTIVO_EXCLUSION', $opcionales, true)) {
            $conds[] = 'MOTIVO_EXCLUSION IS NULL';
        }

        return '(' . implode(' AND ', $conds) . ')';
    }

    /**
     * Excluye una factura del cashflow, o la vuelve a incluir.
     *
     * ES POR COMPROBANTE, a diferencia del rubro "Excluidos" del maestro, que es
     * por proveedor. Una factura duplicada, una en disputa o una que se pago por
     * fuera de Tango no son un problema del proveedor: son un problema de esa
     * factura.
     *
     * EL IMPORTE SALE DE LA FILA DEL TABLERO pero NO desaparece: va a la serie
     * PAGOS_EXCLUIDOS_FACTURA, que es la tercera parte del corte "por como se
     * paga". El proveedor avisa cuanto es y con que motivos. Ver
     * ProveedoresProvider::SERIE_EXCLUIDOS_FACTURA.
     *
     * EL MOTIVO ES OBLIGATORIO, y se valida aca y no en la pantalla: una factura
     * sacada del cashflow sin motivo no la explica nadie tres meses despues, y
     * el endpoint es alcanzable sin pasar por la grilla.
     *
     * DESTILDAR BORRA EL MOTIVO, porque ya no describe nada: dejarlo haria que
     * una factura incluida arrastre el texto de cuando estuvo afuera, y el
     * proximo que lo lea va a creer que sigue excluida.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param bool $excluida
     * @param string|null $motivo Obligatorio si $excluida es true
     * @param string $usuario
     * @return array ['excluida' => bool, 'motivo' => string|null]
     */
    public function saveExclusion($codProvee, $tComp, $nComp, $excluida, $motivo, $usuario) {
        $r = $this->saveExclusionMasiva(
            [['cod_provee' => $codProvee, 't_comp' => $tComp, 'n_comp' => $nComp]],
            $excluida, $motivo, $usuario);

        return ['excluida' => $r['excluida'], 'motivo' => $r['motivo']];
    }

    /**
     * Excluye -o vuelve a incluir- VARIAS facturas de una, con UN SOLO MOTIVO.
     *
     * ES UNA SOLA TRANSACCION -la abre guardarLote(), que es donde vive esa
     * mecanica desde que el gesto de fechar en masa la necesita igual-, por el
     * mismo motivo que el tildado masivo de Echeqs: sacar del cashflow las ocho
     * facturas de un proveedor con ocho llamadas deja la puerta abierta a que la
     * quinta falle y el tablero quede a mitad de camino sin que nadie se entere.
     * O entran todas o ninguna.
     *
     * EL MOTIVO ES UNO PARA TODAS, y eso no es una simplificacion de la
     * pantalla: excluir ocho facturas del mismo proveedor es UNA decision, y
     * ocho motivos distintos para una decision son ocho oportunidades de que
     * digan cosas distintas.
     *
     * NO SE VALIDA CONTRA LOS PENDIENTES DE HOY, a diferencia de
     * Echeqs::marcarCheques(). Aca la fila de override vive por comprobante y
     * puede existir para uno que hoy no esta pendiente -se pago, o se anulo-:
     * borrarla o rechazarla perderia la decision el dia que el comprobante
     * vuelva. Lo que si se valida es que el comprobante este identificado.
     *
     * @param array $comprobantes Filas con 'cod_provee', 't_comp', 'n_comp'
     * @param bool $excluida
     * @param string|null $motivo Obligatorio si $excluida es true
     * @param string $usuario
     * @return array ['excluida', 'motivo', 'tocados' => int]
     */
    public function saveExclusionMasiva($comprobantes, $excluida, $motivo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de overrides por comprobante. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        if (!$this->tieneColumnaPago('EXCLUIDA')) {
            throw new Exception('Todavía no se pueden excluir facturas. '
                . 'Corré sql/cashflow_prov_locales_excluir_factura.sql contra la base central.');
        }

        // Se resuelven TODAS antes de abrir la transacción: ver normalizarClaves().
        $claves = self::normalizarClaves($comprobantes, 'excluir');

        $excluir = !empty($excluida);
        $texto = ($motivo === null) ? '' : trim((string) $motivo);

        if ($excluir && $texto === '') {
            throw new Exception('Poné el motivo por el que ' . (count($claves) === 1
                    ? 'esta factura no entra' : 'estas facturas no entran')
                . ' al cashflow. Sin motivo, dentro de tres meses nadie va a poder explicar '
                . 'por qué falta ese importe.');
        }

        $campos = [
            'EXCLUIDA' => $excluir ? 1 : 0,
            'MOTIVO_EXCLUSION' => $excluir ? mb_substr($texto, 0, 200) : null
        ];

        $this->guardarLote($claves, $campos, 'MANUAL', $usuario);

        return [
            'excluida' => $excluir,
            'motivo' => $campos['MOTIVO_EXCLUSION'],
            'tocados' => count($claves)
        ];
    }

    /**
     * Valida y normaliza una fecha de pago.
     *
     * A DIFERENCIA DE LA FECHA DE COBRO DE COBRANZAS FR, ACA SI SE ACEPTAN
     * FECHAS PASADAS. Alla una fecha vieja hacia desaparecer la factura del
     * listado sin aviso; aca el listado muestra todo lo pendiente sin techo de
     * antiguedad, asi que una fecha de la semana pasada es una decision
     * legitima -se penso pagar y no se pago- y la factura sigue a la vista,
     * marcada como vencida.
     *
     * Estatica y pura.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFechaPago($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha de pago no es una fecha válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha de pago no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Las claves de un lote de comprobantes, normalizadas y sin repetidos.
     *
     * SE RESUELVE ENTERO ANTES DE ABRIR NINGUNA TRANSACCION: un comprobante mal
     * identificado en la fila once no puede descubrirse con diez ya escritas.
     *
     * Esta escrito una vez porque lo comparten los dos gestos masivos de la
     * grilla -excluir y fechar-, y los dos tienen que rechazar exactamente lo
     * mismo. Dos copias de esto divergen en la primera correccion, y lo que
     * quedaria distinto es QUE se considera una factura identificada.
     *
     * @param array $comprobantes Filas con 'cod_provee', 't_comp', 'n_comp'
     * @param string $gesto Que se iba a hacer con ellas, para el mensaje
     * @return array clave => [cod, t, n]
     */
    private static function normalizarClaves($comprobantes, $gesto) {
        $claves = [];

        foreach (is_array($comprobantes) ? $comprobantes : [] as $c) {
            $cod = Planilla::codigo(isset($c['cod_provee']) ? $c['cod_provee'] : '');
            $t = Planilla::codigo(isset($c['t_comp']) ? $c['t_comp'] : '');
            $n = Planilla::codigo(isset($c['n_comp']) ? $c['n_comp'] : '');

            if ($cod === '' || $t === '' || $n === '') {
                throw new Exception('Falta el proveedor o el comprobante en uno de los '
                    . 'renglones.');
            }

            // Indexado por la clave: la misma factura mandada dos veces es una.
            $claves[self::clavePago($cod, $t, $n)] = [$cod, $t, $n];
        }

        if (empty($claves)) {
            throw new Exception('No llegó ninguna factura para ' . $gesto . '.');
        }

        return $claves;
    }

    /**
     * Escribe el MISMO mapa de campos en varios comprobantes, en UNA transaccion.
     *
     * O ENTRAN TODOS O NINGUNO, igual que el tildado masivo de Echeqs y por el
     * mismo motivo: tocar ocho facturas con ocho llamadas deja la puerta abierta
     * a que la quinta falle y el tablero quede a mitad de camino sin que nadie
     * se entere.
     *
     * LA TRANSACCION ESTA ESCRITA UNA SOLA VEZ. Es lo que los dos gestos
     * masivos tienen igual -lo que cambia entre ellos es QUE columnas se
     * escriben, y eso viaja en $campos-, y es ademas lo que no puede quedar
     * escrito dos veces: una copia sin rollback no se ve hasta el dia que algo
     * falla en el medio.
     *
     * Escribe por guardarPago(), que sigue siendo el unico lugar del modulo que
     * toca la tabla de overrides.
     *
     * @param array $claves Las de normalizarClaves()
     * @param array $campos Mapa columna => valor, el mismo para todas
     * @param string $origen 'MANUAL' o 'ARCHIVO'
     * @param string $usuario
     */
    private function guardarLote($claves, $campos, $origen, $usuario) {
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($claves as $c) {
                $this->guardarPago($cid, $c[0], $c[1], $c[2], $campos, $origen, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el guardado'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }
    }

    /**
     * Escribe una fecha de pago. Un UPDATE que no toca nada y despues un INSERT:
     * la unicidad es (COD_PROVEE, T_COMP, N_COMP), asi que no puede duplicar.
     *
     * NO PISA EL ESTADO NI LA FECHA REAL DE CANCELACION. Si el comprobante ya
     * estaba conciliado, cambiarle la prevision no lo desconcilia: Tango es la
     * verdad sobre el pago y esto es una prevision.
     *
     * TAMPOCO PISA LO QUE EL LLAMADOR NO TRAJO, y ahora eso es estructural en
     * vez de un booleano por campo: $campos es un mapa columna => valor con
     * EXACTAMENTE lo que hay que escribir, y lo que no esta en el mapa no entra
     * ni al UPDATE ni al INSERT.
     *
     * Con un flag por campo, agregar un override cuarto obligaba a agregar un
     * cuarto flag y a que todos los llamadores lo pasaran bien. Un endpoint que
     * recibe un campo y escribe cuatro no esta guardando una edicion: esta
     * reemplazando la fila, y asi se borraban la forma y la observacion que
     * habia dejado la importacion de la planilla.
     */
    private function guardarPago($cid, $cod, $t, $n, $campos, $origen, $usuario) {
        if (empty($campos)) {
            throw new Exception('No hay nada que guardar para ese comprobante.');
        }

        /* LA FUENTE VIAJA CON LA FECHA, y solo con ella. ORIGEN -mas abajo-
           describe esta escritura, sea cual sea; FUENTE_FECHA describe la fecha,
           asi que se escribe cuando se escribe FECHA_PAGO y nada mas. Excluir
           una factura o cambiarle la forma no la toca. Ver fuenteFecha() y
           sql/cashflow_prov_locales_fuente_fecha.sql. */
        if (array_key_exists('FECHA_PAGO', $campos) && $this->tieneColumnaPago('FUENTE_FECHA')) {
            $campos['FUENTE_FECHA'] = ($campos['FECHA_PAGO'] === null) ? null : $origen;
        }

        $sets = [];
        $params = [];

        foreach ($campos as $col => $valor) {
            $sets[] = $col . ' = ?';
            $params[] = $valor;
        }

        $sets[] = 'ORIGEN = ?';
        $sets[] = Auditoria::SET_MODIF;
        $params[] = $origen;
        $params[] = $usuario;

        $sql = "UPDATE dbo." . self::TABLA_PAGO . "
                SET " . implode(', ', $sets) . "
                WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?";

        $stmt = sqlsrv_query($cid, $sql,
            array_merge($params, [$cod, $t, $n]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el comprobante'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas > 0) {
            return;
        }

        /* No habia fila. Lo que el llamador no trajo queda en su default, que es
           NULL para los tres overrides: "no hay decision tomada sobre esto". */
        $cols = array_merge(['COD_PROVEE', 'T_COMP', 'N_COMP'], array_keys($campos));
        $vals = array_merge([$cod, $t, $n], array_values($campos));

        $cols[] = 'ESTADO';
        $cols[] = 'ORIGEN';
        $cols[] = 'USUARIO_ALTA';
        $cols[] = 'USUARIO_MODIF';
        $vals[] = 'PREVISTO';
        $vals[] = $origen;
        $vals[] = $usuario;
        $vals[] = $usuario;

        $sql = "INSERT INTO dbo." . self::TABLA_PAGO . "
                    (" . implode(', ', $cols) . ")
                VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";

        $stmt = sqlsrv_query($cid, $sql, $vals);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el comprobante'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** dd/mm/aaaa, para los mensajes */
    private static function formatoCorto($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        return ($f === null) ? '' : date('d/m/Y', strtotime($f));
    }

    /* ====================================================================
       CONCILIACION CONTRA TANGO, AUTOMATICA

       TANGO ES LA VERDAD SOBRE EL PAGO. Lo que se carga en la pestana es una
       PREVISION: cuando se piensa pagar. Cuando el comprobante aparece
       cancelado en Tango, dejo de ser una prevision y paso a ser un hecho, y
       deja de proyectarse -sale del listado de pendientes por si solo, porque
       ESTADO ya no es 'PEN'-.

       LA PREVISION NO SE BORRA AL CONCILIAR. Queda marcada como CONCILIADA con
       la fecha REAL de cancelacion al lado, sin pisar la prevista. Es lo unico
       que permite despues comparar lo que se planifico contra lo que paso: si
       se borrara, la unica pregunta que esta tabla puede contestar bien -"le
       acertamos a la fecha?"- quedaria sin respuesta para siempre.

       CORRE SOLA, y no es una decision de nadie: es copiar lo que dice Tango.
       Antes era un boton con previsualizacion; ahora corre cada vez que la
       pestana carga los pendientes, para cualquier usuario que pueda verla, y
       firma con un usuario fijo (usuarioConciliacion()).
       ==================================================================== */

    /**
     * Quien firma la conciliacion automatica: SISTEMA:Proveedores.conciliarAutomatica.
     * Es la convencion del modulo para un efecto lateral del codigo (ver
     * AuthCashflow::ORIGEN_SISTEMA): no fue una persona, y NULL ya significa
     * "fila historica".
     *
     * @return string
     */
    public static function usuarioConciliacion() {
        return AuthCashflow::origen('Proveedores.conciliarAutomatica');
    }

    /**
     * Concilia contra Tango las previsiones cargadas, usando los pendientes que
     * la pestana YA LEYO. Escribe solo si hay algo que cambiar.
     *
     * INCREMENTAL, Y POR ESO BARATA. Una prevision cuyo comprobante sigue en los
     * pendientes no cambio, y no hace falta preguntarle nada a Tango. Una
     * conciliada que volvio a los pendientes se reabre sin consultar. Solo las
     * PREVISTAS QUE SALIERON de los pendientes van a Tango, filtradas por
     * proveedor y numero: y eso pasa solo cuando efectivamente se pago algo.
     * La consulta completa de antes -todo CPA04 de los proveedores con
     * prevision- tardaba entre 1,6 y 1,9 s en cada carga.
     *
     * UN CASO QUE NO CUBRE, a proposito: una conciliada cuyo comprobante vuelve
     * a PEN en Tango pero queda afuera del listado (clausula de moneda
     * extranjera, saldo cero) no se reabre, porque no se la consulta. Hoy no
     * hay ninguna; ver el README.
     *
     * NO LANZA SI NO HAY TABLA: devuelve vacio. Si falla al leer o al escribir,
     * LANZA, y quien llama decide: la pestana carga igual con un aviso.
     *
     * @param array $items Filas de getPendientes()
     * @return array ['concilia' => [...], 'reabre' => [...], 'avisos' => [...]]
     */
    public function conciliarAutomatica($items) {
        $vacio = ['concilia' => [], 'reabre' => [], 'avisos' => []];

        if (!$this->tablaCreada()) {
            return $vacio;
        }

        $pagos = $this->getPagos();

        if (empty($pagos)) {
            return $vacio;
        }

        /* PRIMERO SIN TANGO: de que previstas hay que preguntar. */
        $plan = self::planConciliacion($pagos, $items, []);
        $enTango = [];

        if (!empty($plan['consultar'])) {
            $enTango = $this->estadoComprobantesEnTango($plan['consultar']);

            if ($enTango === null) {
                throw new Exception('no se pudo leer el estado de los comprobantes en Tango');
            }

            $plan = self::planConciliacion($pagos, $items, $enTango);
        }

        if (empty($plan['concilia']) && empty($plan['reabre'])) {
            return $vacio;
        }

        $hecho = $this->escribirConciliacion($plan['concilia'], $plan['reabre'], self::usuarioConciliacion());

        return [
            'concilia' => $hecho['concilia'],
            'reabre' => $hecho['reabre'],
            'avisos' => self::avisosConciliacion($hecho['concilia'], $hecho['reabre'])
        ];
    }

    /**
     * QUE CAMBIA, sin escribir nada. Estatica y pura.
     *
     *   CONCILIA   estaba PREVISTO, salio de los pendientes y en Tango figura
     *              sin estar pendiente y con fecha de cancelacion: se pago.
     *   REABRE     estaba CONCILIADO y volvio a estar en los pendientes. Pasa
     *              cuando se anula una orden de pago. Sin esto, esa deuda
     *              quedaria marcada como pagada para siempre aunque el tablero
     *              la vuelva a mostrar.
     *
     * UN COMPROBANTE QUE NO APARECE EN TANGO NO SE TOCA: pudo haberse anulado,
     * depurado, o cargado con un codigo que despues cambio. Marcarlo como pagado
     * seria inventar un hecho.
     *
     * @param array $pagos Mapa de getPagos()
     * @param array $items Filas de getPendientes()
     * @param array $enTango De estadoComprobantesEnTango(); vacio en la primera
     *        pasada
     * @return array ['consultar' => pares cod/n, 'concilia', 'reabre']
     */
    public static function planConciliacion($pagos, $items, $enTango) {
        $pendientes = [];

        foreach (is_array($items) ? $items : [] as $i) {
            $clave = self::clavePago($i['COD_PROVEE'], $i['T_COMP'], $i['N_COMP']);

            if (!isset($pendientes[$clave])) {
                $pendientes[$clave] = ['RAZON_SOC' => $i['RAZON_SOC'], 'IMPORTE' => 0.0];
            }

            $pendientes[$clave]['IMPORTE'] += floatval($i['IMPORTE_PENDIENTE']);
        }

        $plan = ['consultar' => [], 'concilia' => [], 'reabre' => []];

        foreach ($pagos as $clave => $pago) {
            $sigue = isset($pendientes[$clave]);

            if ($pago['ESTADO'] === 'CONCILIADO' && $sigue) {
                $plan['reabre'][] = [
                    'cod_provee' => $pago['COD_PROVEE'], 't_comp' => $pago['T_COMP'],
                    'n_comp' => $pago['N_COMP'], 'razon_social' => $pendientes[$clave]['RAZON_SOC'],
                    'importe' => round($pendientes[$clave]['IMPORTE'], 2),
                    'fecha_prevista' => $pago['FECHA_PAGO'], 'fecha_cancelado' => $pago['FECHA_CANCELADO']
                ];

                continue;
            }

            if ($pago['ESTADO'] !== 'PREVISTO' || $sigue) {
                continue;
            }

            $par = $pago['COD_PROVEE'] . '|' . $pago['N_COMP'];

            if (!isset($enTango[$par])) {
                $plan['consultar'][$par] = ['cod' => $pago['COD_PROVEE'], 'n' => $pago['N_COMP']];
                continue;
            }

            foreach ($enTango[$par] as $c) {
                if (Planilla::codigo($c['T_COMP']) !== $pago['T_COMP']) {
                    continue;
                }

                if ($c['ESTADO'] !== 'PEN' && $c['FECHA_CANCELADO'] !== null) {
                    $plan['concilia'][] = [
                        'cod_provee' => $pago['COD_PROVEE'], 't_comp' => $pago['T_COMP'],
                        'n_comp' => $pago['N_COMP'], 'fecha_prevista' => $pago['FECHA_PAGO'],
                        'fecha_cancelado' => $c['FECHA_CANCELADO'],
                        // Cuantos dias antes o despues de lo previsto se pago. Es
                        // el dato por el que existe guardar las dos fechas.
                        'desvio_dias' => self::desvioDias($pago['FECHA_PAGO'], $c['FECHA_CANCELADO'])
                    ];
                }
            }
        }

        $plan['consultar'] = array_values($plan['consultar']);

        return $plan;
    }

    /**
     * Escribe la conciliacion, en UNA transaccion.
     *
     * IDEMPOTENTE Y SEGURA CON DOS USUARIOS A LA VEZ: cada UPDATE toca la fila
     * solo si sigue en el estado de partida (PREVISTO para conciliar,
     * CONCILIADO para reabrir). Si otra carga de la pestana ya la cambio, el
     * UPDATE no afecta nada y no se cuenta: el aviso dice lo que hizo ESTA
     * carga, no lo que hizo la otra.
     *
     * Protegida para que una prueba pueda reemplazar la escritura.
     *
     * @param array $concilia
     * @param array $reabre
     * @param string $usuario
     * @return array ['concilia', 'reabre'] los que efectivamente cambiaron
     */
    protected function escribirConciliacion($concilia, $reabre, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hecho = ['concilia' => [], 'reabre' => []];
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($concilia as $c) {
                /* La fecha PREVISTA no se pisa: al lado se guarda la real. */
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_PAGO . "
                     SET ESTADO = 'CONCILIADO', FECHA_CANCELADO = ?, FECHA_CONCILIA = GETDATE(),
                         " . Auditoria::SET_MODIF . "
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ESTADO = 'PREVISTO'",
                    [$c['fecha_cancelado'], $usuario, $c['cod_provee'], $c['t_comp'], $c['n_comp']]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al conciliar ' . $c['t_comp'] . ' '
                        . $c['n_comp']));
                }

                if (sqlsrv_rows_affected($stmt) > 0) {
                    $hecho['concilia'][] = $c;
                }

                sqlsrv_free_stmt($stmt);
            }

            foreach ($reabre as $r) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_PAGO . "
                     SET ESTADO = 'PREVISTO', FECHA_CANCELADO = NULL, FECHA_CONCILIA = NULL,
                         " . Auditoria::SET_MODIF . "
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ESTADO = 'CONCILIADO'",
                    [$usuario, $r['cod_provee'], $r['t_comp'], $r['n_comp']]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al reabrir ' . $r['t_comp'] . ' '
                        . $r['n_comp']));
                }

                if (sqlsrv_rows_affected($stmt) > 0) {
                    $hecho['reabre'][] = $r;
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la conciliación'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $hecho;
    }

    /**
     * Cuantos dias antes (negativo) o despues (positivo) de lo previsto se pago.
     *
     * Es el dato por el que vale la pena guardar las dos fechas en lugar de
     * pisar una con la otra: contesta si la prevision sirve o si siempre se paga
     * mas tarde de lo que se dice.
     *
     * Estatica y pura.
     *
     * @param string|null $prevista
     * @param string|null $real
     * @return int|null null si falta alguna de las dos
     */
    public static function desvioDias($prevista, $real) {
        $p = Horizonte::normalizarFecha($prevista);
        $r = Horizonte::normalizarFecha($real);

        if ($p === null || $r === null) {
            return null;
        }

        $a = new DateTime($p);
        $b = new DateTime($r);
        $a->setTime(0, 0, 0);
        $b->setTime(0, 0, 0);

        return intval($a->diff($b)->format('%r%a'));
    }

    /**
     * Los avisos de la conciliacion automatica, para el panel de la pestana.
     * SI NO CAMBIO NADA NO HAY AVISO: la conciliacion corre en cada carga, y un
     * "nada cambio" en cada una seria ruido.
     *
     *   INFO      cuantas previsiones se conciliaron, y con cinco o mas el
     *             desvio promedio en dias: con menos no significa nada.
     *   ATENCION  las que volvieron a estar pendientes -una orden de pago
     *             anulada-, con proveedor, comprobante e importe: es deuda que
     *             vuelve a la caja.
     *
     * Estaticos y puros.
     *
     * @param array $concilia
     * @param array $reabre
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosConciliacion($concilia, $reabre) {
        $avisos = [];

        if (!empty($concilia)) {
            $texto = 'Se conciliaron ' . count($concilia) . ' previsión(es) con Tango: ya figuran '
                . 'pagadas. Su fecha prevista queda, con la real al lado.';

            $conDesvio = [];

            foreach ($concilia as $c) {
                if ($c['desvio_dias'] !== null) {
                    $conDesvio[] = $c['desvio_dias'];
                }
            }

            if (count($conDesvio) >= 5) {
                $promedio = array_sum($conDesvio) / count($conDesvio);

                $texto .= ' En promedio se pagó ' . abs(round($promedio, 1)) . ' día(s) '
                    . ($promedio >= 0 ? 'DESPUÉS' : 'ANTES') . ' de lo previsto, sobre '
                    . count($conDesvio) . ' comprobante(s).';
            }

            $avisos[] = Aviso::nuevo(Aviso::INFO, $texto, 'Conciliación');
        }

        if (!empty($reabre)) {
            $detalle = [];

            foreach (array_slice($reabre, 0, 10) as $r) {
                $detalle[] = $r['razon_social'] . ' (' . $r['cod_provee'] . ') ' . $r['t_comp'] . ' '
                    . $r['n_comp'] . ' ' . self::plata($r['importe']);
            }

            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($reabre) . ' factura(s) conciliadas volvieron '
                . 'a estar pendientes (orden de pago anulada) y se proyectan de nuevo con su fecha '
                . 'prevista: ' . implode('; ', $detalle)
                . (count($reabre) > 10 ? '; y ' . (count($reabre) - 10) . ' más' : '') . '.',
                'Conciliación');
        }

        return $avisos;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** $ 1.234,56, para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ': ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
