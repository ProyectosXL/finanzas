<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Retenciones.php';
require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Aviso.php';

/**
 * ProveedoresRetenciones
 * Cuanto se le retiene a cada factura de proveedores locales, y cuanto queda
 * por pagar.
 *
 * QUE RESUELVE
 * ------------
 * A las facturas que se pagan por ORDEN DE PAGO -echeq o transferencia- se les
 * retienen impuestos: hoy IIBB, Ganancias y SUSS. Lo que sale de la caja es el
 * NETO, y es lo que quien arma la planilla de pagos pone como importe. El
 * cashflow proyectaba el total.
 *
 * POR QUE NO SE LEEN DE TANGO: Tango las calcula EN la orden de pago, y la
 * orden de pago cancela la factura y la saca del pendiente. Para el flujo ese
 * calculo llega tarde. Por eso las alicuotas se cargan en el cashflow.
 *
 * LOS IMPUESTOS SON DATOS
 * -----------------------
 * Ningun impuesto esta escrito en el codigo: viven en
 * RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO, los siembra el script y se editan en
 * Parametros -> Prov. Locales. Cada uno tiene un ALCANCE:
 *
 *   TODOS      alicuota GENERAL con vigencias, salvo que el proveedor tenga la
 *              suya -incluido 0, que es "a este no se le retiene"-.
 *   ASIGNADOS  sin general: se aplica solo a quien tenga una alicuota propia.
 *
 * UNA SOLA REGLA: calcular()
 * --------------------------
 * La alicuota efectiva de un comprobante, su retencion y su neto se resuelven
 * en calcular(), estatica y pura. La usan la grilla, el proveedor del tablero,
 * la importacion de la planilla de pagos y los avisos: si cada uno resolviera
 * por su cuenta, el neto del eje y el de la planilla podrian diferir en un
 * centavo y la planilla quedaria rechazada sin que nadie entienda por que.
 *
 * La mecanica de las vigencias -que alicuota rige a que fecha, con el
 * desempate por ID- NO se copia: es Retenciones::alicuotasVigentes(), la de
 * Cob. Electronicos y Echeqs. Las cuentas son Retenciones::retencionesPorConcepto().
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * Sin sql/cashflow_prov_locales_retenciones.sql, config() devuelve
 * 'disponible' en false y calcular() deja el neto igual al total: la pestaña y
 * el tablero funcionan como antes y avisan que falta el script.
 */
class ProveedoresRetenciones {

    const TABLA_IMPUESTO = 'RO_T_CASHFLOW_PROV_LOCALES_IMPUESTO';
    const TABLA_ALICUOTA = 'RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA';
    const TABLA_PROVEEDOR = 'RO_T_CASHFLOW_PROV_LOCALES_ALICUOTA_PROV';

    /** El script que las crea, para nombrarlo en los avisos */
    const SCRIPT = 'sql/cashflow_prov_locales_retenciones.sql';

    /**
     * Los alcances, con el texto con el que se muestran. TIENE QUE COINCIDIR
     * con el CHECK de la tabla de impuestos.
     */
    const ALCANCE_TODOS = 'TODOS';
    const ALCANCE_ASIGNADOS = 'ASIGNADOS';
    const ALCANCES = [
        self::ALCANCE_TODOS => 'A todos',
        self::ALCANCE_ASIGNADOS => 'Solo a los asignados'
    ];

    /** De donde sale la alicuota que se uso */
    const FUENTE_GENERAL = 'GENERAL';
    const FUENTE_PROVEEDOR = 'PROVEEDOR';
    const FUENTE_NO_RETIENE = 'NO_RETIENE';

    /**
     * Por que un comprobante NO lleva ninguna retencion. Son distintos porque
     * se arreglan distinto: el primero corriendo un script, el ultimo cargando
     * alicuotas, y los otros no se arreglan porque estan bien.
     */
    const MOTIVO_SIN_SCRIPT = 'SIN_SCRIPT';
    const MOTIVO_FORMA = 'FORMA';
    const MOTIVO_NC = 'NC';
    const MOTIVO_SIN_BASE = 'SIN_BASE';
    const MOTIVO_SIN_GENERAL = 'SIN_GENERAL';
    const MOTIVO_SIN_IMPUESTOS = 'SIN_IMPUESTOS';

    /** Las acciones de la asignacion por proveedor */
    const ACCION_ALICUOTA = 'ALICUOTA';
    const ACCION_NO_RETIENE = 'NO_RETIENE';
    const ACCION_GENERAL = 'GENERAL';

    /** De que gesto sale una alicuota de proveedor. Coincide con el CHECK. */
    const ORIGEN_MASIVA = 'MASIVA';
    const ORIGEN_INDIVIDUAL = 'INDIVIDUAL';

    const LARGO_NOMBRE = 60;

    /** @var Conexion */
    private $conn;

    /** @var bool|null */
    private $tablas = null;

    /** @var array|null Cache de config(), por pedido */
    private $config = null;

    /** @var array|null Cache de los tipos de credito de CPA21 */
    private $credito = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO
       ==================================================================== */

    /**
     * Si ya se corrio el script. Pregunta por las TRES tablas en una consulta:
     * con una sola creada a mano el calculo leeria una tabla que no esta.
     *
     * @return bool
     */
    public function tablasCreadas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT CASE WHEN OBJECT_ID('dbo." . self::TABLA_IMPUESTO . "', 'U') IS NOT NULL
                          AND OBJECT_ID('dbo." . self::TABLA_ALICUOTA . "', 'U') IS NOT NULL
                          AND OBJECT_ID('dbo." . self::TABLA_PROVEEDOR . "', 'U') IS NOT NULL
                         THEN 1 ELSE 0 END AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de retenciones'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ($row && intval($row['T']) === 1);

        return $this->tablas;
    }

    /** @return string El texto de que falta el script */
    public static function textoSinScript() {
        return 'Falta ' . self::SCRIPT . ': mientras tanto las facturas se proyectan por el '
            . 'total, sin descontar retenciones, y no se pueden cargar alícuotas. Corrélo contra '
            . 'la base central.';
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Todo lo que calcular() necesita, leido UNA vez por pedido.
     *
     * NO LANZA. Un problema al leer las alicuotas no puede tumbar la pestaña
     * ni el tablero: devuelve 'disponible' en false con el motivo en 'error', y
     * calcular() deja el neto igual al total -como antes de que existieran
     * las retenciones-. Quien llama avisa con avisosConfig().
     *
     * @return array ['disponible', 'error', 'impuestos', 'generales',
     *         'por_proveedor', 'credito']
     */
    public function config() {
        if ($this->config !== null) {
            return $this->config;
        }

        $vacia = ['disponible' => false, 'error' => null, 'impuestos' => [], 'generales' => [],
                  'por_proveedor' => [], 'credito' => []];

        try {
            if (!$this->tablasCreadas()) {
                return $this->config = $vacia;
            }

            return $this->config = [
                'disponible' => true,
                'error' => null,
                'impuestos' => $this->leerImpuestos(),
                'generales' => $this->leerGenerales(),
                'por_proveedor' => $this->leerPorProveedor(),
                'credito' => $this->tiposCredito()
            ];
        } catch (Throwable $e) {
            $vacia['error'] = $e->getMessage();

            return $this->config = $vacia;
        }
    }

    /** Olvida lo leido: lo usan las escrituras para revalidar contra lo de ahora */
    public function olvidar() {
        $this->config = null;
        $this->tablas = null;
    }

    /**
     * Los avisos de configuracion: que falta el script, o que no se pudo leer.
     * Los de "falta la alicuota general" los arma avisosPendientes(), porque
     * dependen de los comprobantes.
     *
     * Atencion y no critico: sin retenciones el numero es el de antes, y eso
     * no es un numero inventado. Critico si no se pudo leer: ahi el numero
     * cambia sin que nadie lo decida.
     *
     * @param array $config
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosConfig($config) {
        if (!empty($config['error'])) {
            return [Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer las alícuotas de retenciones ('
                . $config['error'] . '). Las facturas se proyectan por el total, sin descontar '
                . 'retenciones, hasta que se puedan leer.', 'Retenciones')];
        }

        if (empty($config['disponible'])) {
            return [Aviso::nuevo(Aviso::WARNING, self::textoSinScript(), 'Retenciones')];
        }

        return [];
    }

    /**
     * Los impuestos, TODOS -tambien los dados de baja: la pantalla los
     * muestra para poder reactivarlos-, indexados por codigo y en su orden.
     *
     * @return array
     */
    private function leerImpuestos() {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, CODIGO, NOMBRE, ALCANCE, ORDEN, ACTIVO, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA_IMPUESTO . "
             ORDER BY ORDEN, CODIGO");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los impuestos'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $codigo = trim((string) $row['CODIGO']);

            $v[$codigo] = [
                'ID' => intval($row['ID']),
                'CODIGO' => $codigo,
                'NOMBRE' => trim((string) $row['NOMBRE']),
                'ALCANCE' => trim((string) $row['ALCANCE']),
                'ORDEN' => intval($row['ORDEN']),
                'ACTIVO' => intval($row['ACTIVO']),
                'USUARIO' => $row['USUARIO_MODIF'],
                'FECHA_MODIF' => self::fechaHora($row['FECHA_MODIF'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Las alicuotas generales, con el historico completo: la vigencia vieja es
     * lo que explica el neto de una fecha de pago anterior.
     *
     * @return array Filas con la forma que lee Retenciones
     */
    private function leerGenerales() {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, CONCEPTO, CAST(ALICUOTA AS FLOAT) AS ALICUOTA, VIGENCIA_DESDE, ACTIVO,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA_ALICUOTA . "
             ORDER BY CONCEPTO, VIGENCIA_DESDE DESC, ID DESC");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las alícuotas generales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'CONCEPTO' => trim((string) $row['CONCEPTO']),
                'ALICUOTA' => floatval($row['ALICUOTA']),
                'VIGENCIA_DESDE' => Horizonte::normalizarFecha($row['VIGENCIA_DESDE']),
                'ACTIVO' => intval($row['ACTIVO']),
                'USUARIO' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => self::fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Las alicuotas de los proveedores, TODAS -es su historial-, agrupadas por
     * codigo. Una consulta para todo el listado, no una por proveedor.
     *
     * ALICUOTA viaja en null cuando es "sin dato": no es 0. Ver el encabezado
     * del script.
     *
     * @return array Mapa COD_PROVEE => filas
     */
    private function leerPorProveedor() {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, COD_PROVEE, CONCEPTO, CAST(ALICUOTA AS FLOAT) AS ALICUOTA, VIGENCIA_DESDE,
                    ORIGEN, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA_PROVEEDOR . "
             ORDER BY COD_PROVEE, CONCEPTO, VIGENCIA_DESDE DESC, ID DESC");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las alícuotas de los proveedores'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = Planilla::codigo($row['COD_PROVEE']);

            $v[$cod][] = [
                'ID' => intval($row['ID']),
                'CONCEPTO' => trim((string) $row['CONCEPTO']),
                'ALICUOTA' => ($row['ALICUOTA'] === null) ? null : floatval($row['ALICUOTA']),
                'VIGENCIA_DESDE' => Horizonte::normalizarFecha($row['VIGENCIA_DESDE']),
                'ORIGEN' => $row['ORIGEN'],
                'USUARIO' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Los tipos de comprobante de CREDITO, segun Tango: CPA21.CRE_DEB = 'C'.
     *
     * ES EL MISMO CRITERIO DE SIGNOS QUE YA USA EL MODULO para las imputaciones
     * de CPA05 (ver el encabezado de Proveedores). Hoy son NCD, NCI, NCP, NCR,
     * NIP y AJC. Una nota de credito no se paga por orden de pago: se imputa, y
     * no lleva retencion.
     *
     * SE LEE UNA VEZ Y SE CRUZA EN PHP, y no con un JOIN en la consulta de
     * pendientes: es la regla del repo, y CPA21 tiene quince filas.
     *
     * Hoy NINGUN pendiente es de credito -medido el 14/10/2026: los 558 son
     * FAC; en CPA04 las NC nunca quedan en PEN, quedan IMP o CTA-. La regla es
     * una red, no la norma.
     *
     * @return array Mapa T_COMP => true
     */
    private function tiposCredito() {
        if ($this->credito !== null) {
            return $this->credito;
        }

        $stmt = sqlsrv_query($this->conectar(), "SELECT T_COMP, CRE_DEB FROM CPA21");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los tipos de comprobante de CPA21'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (strtoupper(trim((string) $row['CRE_DEB'])) === 'C') {
                $v[Planilla::codigo($row['T_COMP'])] = true;
            }
        }

        sqlsrv_free_stmt($stmt);

        return $this->credito = $v;
    }

    /**
     * El historial de un proveedor, por impuesto: valor, desde cuando, de que
     * gesto y quien. Del mas nuevo al mas viejo.
     *
     * @param string $codProvee
     * @return array Mapa CONCEPTO => filas
     */
    public function historialProveedor($codProvee) {
        $cod = Planilla::codigo($codProvee);
        $config = $this->config();
        $v = [];

        foreach (isset($config['por_proveedor'][$cod]) ? $config['por_proveedor'][$cod] : [] as $f) {
            $v[$f['CONCEPTO']][] = $f;
        }

        return $v;
    }

    /* ====================================================================
       LA REGLA: estaticas y puras
       ==================================================================== */

    /**
     * Los impuestos que rigen en el calculo: los ACTIVOS, en su orden.
     *
     * @param array $config
     * @return array
     */
    public static function impuestosActivos($config) {
        $v = [];

        foreach (isset($config['impuestos']) ? $config['impuestos'] : [] as $codigo => $i) {
            if (intval($i['ACTIVO']) === 1) {
                $v[$codigo] = $i;
            }
        }

        return $v;
    }

    /**
     * La alicuota PROPIA de un proveedor para un impuesto, vigente a una fecha.
     *
     * La vigencia la decide Retenciones::alicuotasVigentes() -la ultima con
     * VIGENCIA_DESDE <= fecha, desempate por ID-, que es la misma regla de las
     * generales. Lo unico que se agrega es distinguir NULL de 0: aquella
     * convierte a numero, asi que se busca la fila original por ID.
     *
     * @param array $config
     * @param string $cod
     * @param string $concepto
     * @param string $fecha 'Y-m-d'
     * @return array|null null si no tiene ninguna fila vigente; si no
     *         ['alicuota' => float|null, 'vigencia_desde', 'id']. alicuota null
     *         es "volvio a la general / dejo de estar asignado"
     */
    public static function propia($config, $cod, $concepto, $fecha) {
        $filas = isset($config['por_proveedor'][$cod]) ? $config['por_proveedor'][$cod] : [];

        if (empty($filas)) {
            return null;
        }

        $delConcepto = [];

        foreach ($filas as $f) {
            if ($f['CONCEPTO'] === $concepto) {
                $delConcepto[$f['ID']] = $f;
            }
        }

        $vigentes = Retenciones::alicuotasVigentes(array_values($delConcepto), $fecha);

        if (!isset($vigentes[$concepto])) {
            return null;
        }

        $id = $vigentes[$concepto]['id'];
        $original = $delConcepto[$id]['ALICUOTA'];

        return [
            'alicuota' => ($original === null) ? null : floatval($original),
            'vigencia_desde' => $vigentes[$concepto]['vigencia_desde'],
            'id' => $id
        ];
    }

    /**
     * La alicuota general de un impuesto vigente a una fecha, o null.
     *
     * @param array $config
     * @param string $concepto
     * @param string $fecha
     * @return array|null ['alicuota', 'vigencia_desde', 'id']
     */
    public static function general($config, $concepto, $fecha) {
        $delConcepto = [];

        foreach (isset($config['generales']) ? $config['generales'] : [] as $g) {
            if ($g['CONCEPTO'] === $concepto) {
                $delConcepto[] = $g;
            }
        }

        $vigentes = Retenciones::alicuotasVigentes($delConcepto, $fecha);

        return isset($vigentes[$concepto]) ? $vigentes[$concepto] : null;
    }

    /**
     * La alicuota EFECTIVA de un impuesto para un proveedor a una fecha, y de
     * donde sale:
     *
     *   1. si el proveedor tiene una propia vigente a esa fecha, esa -0
     *      incluido: "no se le retiene"-;
     *   2. si no, y el impuesto es "a todos", la general vigente a esa fecha;
     *   3. si no, no se aplica.
     *
     * Una propia en NULL ("sin dato") es como no tener: cae al paso 2.
     *
     * @param array $config
     * @param array $impuesto Fila de impuestos
     * @param string $cod
     * @param string $fecha
     * @return array ['aplica' => bool, 'alicuota', 'fuente', 'vigencia_desde',
     *         'sin_general' => bool]
     */
    public static function efectiva($config, $impuesto, $cod, $fecha) {
        $concepto = $impuesto['CODIGO'];
        $propia = self::propia($config, $cod, $concepto, $fecha);

        if ($propia !== null && $propia['alicuota'] !== null) {
            return [
                'aplica' => true,
                'alicuota' => $propia['alicuota'],
                'fuente' => ($propia['alicuota'] == 0) ? self::FUENTE_NO_RETIENE : self::FUENTE_PROVEEDOR,
                'vigencia_desde' => $propia['vigencia_desde'],
                'sin_general' => false
            ];
        }

        if ($impuesto['ALCANCE'] !== self::ALCANCE_TODOS) {
            return ['aplica' => false, 'alicuota' => null, 'fuente' => null,
                    'vigencia_desde' => null, 'sin_general' => false];
        }

        $general = self::general($config, $concepto, $fecha);

        if ($general === null) {
            /* SIN GENERAL VIGENTE NO SE RETIENE, Y SE DICE. No es lo mismo que
               una alicuota 0: nadie decidio que no se retenga, falta el dato. */
            return ['aplica' => false, 'alicuota' => null, 'fuente' => null,
                    'vigencia_desde' => null, 'sin_general' => true];
        }

        return [
            'aplica' => true,
            'alicuota' => $general['alicuota'],
            'fuente' => self::FUENTE_GENERAL,
            'vigencia_desde' => $general['vigencia_desde'],
            'sin_general' => false
        ];
    }

    /**
     * LA RETENCION Y EL NETO DE UN COMPROBANTE. Es la regla, escrita una vez.
     *
     * A QUE COMPROBANTES APLICA: solo a los que se pagan por orden de pago, es
     * decir los de FORMA VIGENTE DEL CRONOGRAMA -$cronograma, ya resuelto con
     * Proveedores::formaDelCronograma()-. Uno sin forma conocida tambien entra:
     * para el cronograma ya cuenta como echeq, y si esta en la fila del
     * tablero tiene que estar en neto como los demas.
     *
     * NO APLICA a los de otra forma (debito, caja, tarjeta: no pasan por orden
     * de pago), ni a las notas de credito (se imputan, no se pagan).
     *
     * LA BASE ES EL PENDIENTE, no el neto sin IVA: es lo que se proyecta, y
     * sobre eso se calcula lo que sale de la caja.
     *
     * LA FECHA ES LA DE PAGO del comprobante -la misma con la que cae en el
     * eje-, porque un cambio de alicuota rige desde una fecha y una factura que
     * se paga despues tiene que usar la nueva.
     *
     * @param float $base El pendiente (o la parte, ver Proveedores::abrirEnMedios())
     * @param string $cod Codigo del proveedor
     * @param string $tComp Tipo de comprobante
     * @param bool $cronograma Si la forma vigente es del cronograma
     * @param string|null $fecha 'Y-m-d' de pago
     * @param array $config De config()
     * @return array ['base', 'aplica', 'motivo', 'impuestos' => [codigo =>
     *         ['codigo', 'nombre', 'alicuota', 'retencion', 'fuente',
     *         'vigencia_desde']], 'sin_general' => [codigos], 'retenido',
     *         'neto', 'fecha']
     */
    public static function calcular($base, $cod, $tComp, $cronograma, $fecha, $config) {
        $base = round(floatval($base), 2);

        $r = [
            'base' => $base,
            'aplica' => false,
            'motivo' => null,
            'impuestos' => [],
            'sin_general' => [],
            'retenido' => 0.0,
            'neto' => $base,
            'fecha' => $fecha
        ];

        if (empty($config['disponible'])) {
            $r['motivo'] = self::MOTIVO_SIN_SCRIPT;

            return $r;
        }

        if (!$cronograma) {
            $r['motivo'] = self::MOTIVO_FORMA;

            return $r;
        }

        if (isset($config['credito'][Planilla::codigo($tComp)])) {
            $r['motivo'] = self::MOTIVO_NC;

            return $r;
        }

        /* Un pendiente en cero o negativo no se paga con una orden de pago: no
           hay nada que retener. Hoy no hay ninguno, pero una retencion sobre
           un negativo sumaria plata al neto. */
        if ($base <= 0) {
            $r['motivo'] = self::MOTIVO_SIN_BASE;

            return $r;
        }

        $alicuotas = [];

        foreach (self::impuestosActivos($config) as $codigo => $imp) {
            $e = self::efectiva($config, $imp, $cod, $fecha);

            if ($e['sin_general']) {
                $r['sin_general'][] = $codigo;
            }

            if (!$e['aplica']) {
                continue;
            }

            $alicuotas[$codigo] = $e['alicuota'];
            $r['impuestos'][$codigo] = [
                'codigo' => $codigo,
                'nombre' => $imp['NOMBRE'],
                'alicuota' => $e['alicuota'],
                'retencion' => 0.0,
                'fuente' => $e['fuente'],
                'vigencia_desde' => $e['vigencia_desde']
            ];
        }

        if (empty($alicuotas)) {
            $r['motivo'] = empty($r['sin_general']) ? self::MOTIVO_SIN_IMPUESTOS : self::MOTIVO_SIN_GENERAL;

            return $r;
        }

        $cuentas = Retenciones::retencionesPorConcepto($base, $alicuotas);

        foreach ($cuentas['retenciones'] as $codigo => $importe) {
            $r['impuestos'][$codigo]['retencion'] = $importe;
        }

        $r['aplica'] = true;
        $r['retenido'] = $cuentas['total'];
        $r['neto'] = $cuentas['neto'];

        return $r;
    }

    /**
     * La suma de las alicuotas efectivas de un proveedor a una fecha.
     *
     * Es lo que no puede llegar a 100%: con eso, el neto saldria cero o
     * negativo y una factura restaria plata de la caja al reves.
     *
     * @param array $config
     * @param string $cod '' es un proveedor sin ninguna alicuota propia
     * @param string $fecha
     * @return float
     */
    public static function sumaEfectiva($config, $cod, $fecha) {
        $suma = 0.0;

        foreach (self::impuestosActivos($config) as $imp) {
            $e = self::efectiva($config, $imp, $cod, $fecha);

            if ($e['aplica']) {
                $suma += floatval($e['alicuota']);
            }
        }

        return $suma;
    }

    /**
     * Las fechas en las que la suma de un proveedor puede cambiar, desde una
     * fecha: esa fecha y cada vigencia posterior, general o propia.
     *
     * La suma es escalonada: solo cambia cuando empieza a regir una vigencia.
     * Mirarla en cada escalon es mirarla en todo el futuro, y no solo hoy: una
     * general cargada para el mes que viene puede dejar a un proveedor en 100%
     * a partir de ese dia.
     *
     * @param array $config
     * @param string $desde 'Y-m-d'
     * @return array Fechas ordenadas, sin repetir
     */
    public static function fechasDeCambio($config, $desde) {
        $fechas = [$desde => true];

        foreach (isset($config['generales']) ? $config['generales'] : [] as $g) {
            if ($g['VIGENCIA_DESDE'] !== null && $g['VIGENCIA_DESDE'] > $desde) {
                $fechas[$g['VIGENCIA_DESDE']] = true;
            }
        }

        foreach (isset($config['por_proveedor']) ? $config['por_proveedor'] : [] as $filas) {
            foreach ($filas as $f) {
                if ($f['VIGENCIA_DESDE'] !== null && $f['VIGENCIA_DESDE'] > $desde) {
                    $fechas[$f['VIGENCIA_DESDE']] = true;
                }
            }
        }

        $v = array_keys($fechas);
        sort($v);

        return $v;
    }

    /**
     * Que proveedores quedan con la suma de sus alicuotas en 100% o mas, desde
     * una fecha. Es la validacion de toda escritura: de una general, de un
     * cambio de alcance, de una reactivacion y de una asignacion.
     *
     * SIEMPRE SOBRE EL ESTADO RESULTANTE: quien llama arma $config con el
     * cambio ya aplicado en memoria.
     *
     * Se mira tambien a un proveedor SIN alicuotas propias -el codigo ''-: es
     * lo que valida la suma de las generales sola.
     *
     * @param array $config
     * @param array|null $cods Codigos a mirar; null = todos los que tienen alicuota propia
     * @param string $desde 'Y-m-d'
     * @return array Mapa cod => ['suma', 'fecha'] de los que se pasan
     */
    public static function excedidos($config, $cods, $desde) {
        if ($cods === null) {
            $cods = array_merge([''], array_keys(isset($config['por_proveedor'])
                ? $config['por_proveedor'] : []));
        }

        $fechas = self::fechasDeCambio($config, $desde);
        $v = [];

        foreach ($cods as $cod) {
            foreach ($fechas as $f) {
                $suma = self::sumaEfectiva($config, $cod, $f);

                if ($suma >= 1 - Retenciones::TOLERANCIA_TASA) {
                    $v[$cod] = ['suma' => $suma, 'fecha' => $f];
                    break;
                }
            }
        }

        return $v;
    }

    /** "OGNUNE (100%, desde el 01/11/2026), ..." para los mensajes */
    private static function textoExcedidos($excedidos) {
        $partes = [];

        foreach (array_slice($excedidos, 0, 10, true) as $cod => $e) {
            $partes[] = ($cod === '' ? 'las generales solas' : $cod) . ' (' . Retenciones::porcentaje($e['suma'])
                . ' desde el ' . Retenciones::fechaCorta($e['fecha']) . ')';
        }

        return implode(', ', $partes) . (count($excedidos) > 10
            ? ' y ' . (count($excedidos) - 10) . ' más' : '');
    }

    /**
     * Un impuesto, validado y existente. LANZA.
     *
     * @param array $config
     * @param string $codigo
     * @param bool $activo Si ademas tiene que estar activo
     * @return array La fila
     */
    public static function impuesto($config, $codigo, $activo = true) {
        $codigo = self::normalizarCodigo($codigo);

        if (!isset($config['impuestos'][$codigo])) {
            throw new Exception('No existe el impuesto "' . $codigo . '". Los impuestos se dan de '
                . 'alta en Parámetros › Prov. Locales.');
        }

        $imp = $config['impuestos'][$codigo];

        if ($activo && intval($imp['ACTIVO']) !== 1) {
            throw new Exception('El impuesto ' . $imp['NOMBRE'] . ' está dado de baja. Reactivalo en '
                . 'Parámetros › Prov. Locales para poder cargarle alícuotas.');
        }

        return $imp;
    }

    /**
     * El codigo de un impuesto: mayusculas, letras, numeros y guion bajo. Es
     * lo que guardan las alicuotas en CONCEPTO, asi que tiene que poder
     * escribirse igual siempre.
     *
     * @param mixed $codigo
     * @return string
     */
    public static function normalizarCodigo($codigo) {
        return mb_strtoupper(trim((string) $codigo));
    }

    /**
     * Valida un impuesto nuevo o editado. LANZA. Estatica y pura.
     *
     * @param array $datos ['codigo', 'nombre', 'alcance', 'orden']
     * @param bool $conCodigo Si se valida el codigo (en el alta; no se edita)
     * @return array Los datos normalizados
     */
    public static function validarImpuesto($datos, $conCodigo = true) {
        $v = [];

        if ($conCodigo) {
            $v['codigo'] = self::normalizarCodigo(isset($datos['codigo']) ? $datos['codigo'] : '');

            if (!preg_match('/^[A-Z0-9_]{1,' . Retenciones::CONCEPTO_MAX . '}$/', $v['codigo'])) {
                throw new Exception('El código del impuesto va en mayúsculas, sin espacios ni '
                    . 'acentos (letras, números y guion bajo), hasta ' . Retenciones::CONCEPTO_MAX
                    . ' caracteres: por ejemplo IIBB o GANANCIAS. No se puede cambiar después.');
            }
        }

        $v['nombre'] = trim((string) (isset($datos['nombre']) ? $datos['nombre'] : ''));

        if ($v['nombre'] === '') {
            throw new Exception('El impuesto necesita un nombre.');
        }

        $v['nombre'] = mb_substr($v['nombre'], 0, self::LARGO_NOMBRE);
        $v['alcance'] = strtoupper(trim((string) (isset($datos['alcance']) ? $datos['alcance'] : '')));

        if (!isset(self::ALCANCES[$v['alcance']])) {
            throw new Exception('El alcance tiene que ser "a todos" o "solo a los asignados".');
        }

        $orden = isset($datos['orden']) ? $datos['orden'] : 0;

        if ($orden === '' || $orden === null) {
            $orden = 0;
        }

        if (!is_numeric($orden) || intval($orden) != $orden) {
            throw new Exception('El orden tiene que ser un número entero.');
        }

        $v['orden'] = intval($orden);

        return $v;
    }

    /**
     * Valida una alicuota GENERAL nueva. LANZA. Estatica y pura.
     *
     *   - el impuesto existe, esta activo y es "a todos" (uno "solo a los
     *     asignados" no tiene general);
     *   - 0 <= alicuota < 1, y la suma de las generales vigentes a esa fecha
     *     < 100%: Retenciones::validarAlicuota(), la de los otros modulos;
     *   - y ningun proveedor queda con la suma de SUS alicuotas efectivas en
     *     100% o mas, desde esa fecha: una general nueva tambien cambia la
     *     suma de quien tiene alicuotas propias en los otros impuestos.
     *
     * @param array $config
     * @param string $codigo
     * @param mixed $alicuota Fraccion
     * @param string $vigencia 'Y-m-d'
     * @return array ['impuesto', 'alicuota', 'vigencia', 'tasa_total']
     */
    public static function validarGeneral($config, $codigo, $alicuota, $vigencia) {
        $imp = self::impuesto($config, $codigo);

        if ($imp['ALCANCE'] !== self::ALCANCE_TODOS) {
            throw new Exception($imp['NOMBRE'] . ' se aplica solo a los proveedores asignados, así '
                . 'que no tiene alícuota general: la alícuota se le carga a cada proveedor desde el '
                . 'Maestro.');
        }

        $alicuota = self::numeroAlicuota($alicuota);
        $fecha = Horizonte::normalizarFecha($vigencia);

        if ($fecha === null) {
            throw new Exception('La alícuota necesita una fecha de vigencia.');
        }

        $nueva = $config;
        $nueva['generales'][] = ['ID' => PHP_INT_MAX, 'CONCEPTO' => $imp['CODIGO'],
                                 'ALICUOTA' => $alicuota, 'VIGENCIA_DESDE' => $fecha, 'ACTIVO' => 1];

        /* PRIMERO LA SUMA DE LAS GENERALES SOLAS -el proveedor '' de
           excedidos(), uno sin alicuotas propias-, con el texto de este modulo:
           el de Retenciones::validarAlicuota() habla de acreditaciones y
           cobranzas, que es lo que validan los otros dos. */
        $generalesSolas = self::excedidos($nueva, [''], $fecha);

        if (!empty($generalesSolas)) {
            throw new Exception('Con esta alícuota, las generales vigentes sumarían '
                . Retenciones::porcentaje($generalesSolas['']['suma']) . ' desde el '
                . Retenciones::fechaCorta($generalesSolas['']['fecha']) . ', así que el neto de '
                . 'una factura saldría cero o negativo. Revisá los otros impuestos antes de guardar '
                . 'este.');
        }

        /* LA SUMA DE LAS GENERALES que rigen: solo las de impuestos activos "a
           todos". Con la de arriba ya validada, esto no puede lanzar por la
           suma; lo que aporta es la regla compartida de rango y de vigencia, y
           la tasa total que se informa. */
        $rigen = [];

        foreach (isset($config['generales']) ? $config['generales'] : [] as $g) {
            $i = isset($config['impuestos'][$g['CONCEPTO']]) ? $config['impuestos'][$g['CONCEPTO']] : null;

            if ($i !== null && intval($i['ACTIVO']) === 1 && $i['ALCANCE'] === self::ALCANCE_TODOS) {
                $rigen[] = $g;
            }
        }

        $tasa = Retenciones::validarAlicuota($rigen, $imp['CODIGO'], $alicuota, $fecha);

        $excedidos = self::excedidos($nueva, null, $fecha);
        unset($excedidos['']);

        if (!empty($excedidos)) {
            throw new Exception('Con esta alícuota, estos proveedores quedarían con sus retenciones '
                . 'sumando 100% o más, y su neto saldría cero o negativo: '
                . self::textoExcedidos($excedidos) . '. Corregí sus alícuotas en el Maestro antes '
                . 'de cargar esta. No se guardó nada.');
        }

        return ['impuesto' => $imp, 'alicuota' => $alicuota, 'vigencia' => $fecha, 'tasa_total' => $tasa];
    }

    /**
     * Una alicuota, como numero. LANZA si no lo es.
     *
     * @param mixed $alicuota
     * @return float
     */
    public static function numeroAlicuota($alicuota) {
        if ($alicuota === null || $alicuota === '' || is_bool($alicuota) || !is_numeric($alicuota)) {
            throw new Exception('La alícuota tiene que ser un número: 2,5% se carga como 2,5 en la '
                . 'pantalla y viaja como 0,025.');
        }

        $a = floatval($alicuota);

        if ($a < 0) {
            throw new Exception('La alícuota no puede ser negativa.');
        }

        if ($a >= 1) {
            throw new Exception('Una alícuota de ' . Retenciones::porcentaje($a) . ' dejaría el neto '
                . 'en cero o negativo. Tiene que ser menor a 100%.');
        }

        return $a;
    }

    /**
     * QUE HARIA UNA ASIGNACION, sin escribir nada. Es lo que el dialogo
     * muestra antes de confirmar y lo que asignar() vuelve a calcular contra
     * la base de ahora. Estatica y pura.
     *
     * Las tres acciones:
     *
     *   ALICUOTA     el porcentaje dado, para todos los elegidos
     *   NO_RETIENE   0: "a estos no se les retiene". Solo en "a todos": en "solo
     *                a los asignados" no tener alicuota ya es no retener
     *   GENERAL      sin dato (NULL): vuelve a la general, o deja de estar
     *                asignado. ES UN REGISTRO CON SU FECHA, no un borrado
     *
     * NO SE TOCA A QUIEN YA TENIA ESE MISMO VALOR: una fila identica en el
     * historial diria que alguien decidio algo que ya estaba decidido. Se
     * cuenta aparte -'iguales'- para que el dialogo lo diga.
     *
     * RIGE DESDE HOY: VIGENCIA_DESDE la pone el servidor, y el valor anterior
     * sigue explicando las fechas de pago anteriores.
     *
     * TODO O NADA: si el cambio deja a algun proveedor con la suma de sus
     * alicuotas en 100% o mas, 'rechazados' los nombra y no se aplica a
     * ninguno.
     *
     * @param array $config
     * @param array $cods Codigos elegidos
     * @param string $codigo Impuesto
     * @param string $accion ALICUOTA | NO_RETIENE | GENERAL
     * @param mixed $alicuota Fraccion, solo con ALICUOTA
     * @param string $hoy 'Y-m-d'
     * @param array|null $enMaestro Mapa cod => algo de los proveedores vigentes
     *        del maestro; null para no validar
     * @return array ['impuesto', 'accion', 'valor', 'aplicar', 'nuevos',
     *         'reemplaza', 'iguales', 'fuera_maestro', 'rechazados']
     */
    public static function planAsignacion($config, $cods, $codigo, $accion, $alicuota, $hoy,
                                          $enMaestro = null) {
        if (empty($config['disponible'])) {
            throw new Exception(self::textoSinScript());
        }

        $imp = self::impuesto($config, $codigo);
        $accion = strtoupper(trim((string) $accion));

        if ($accion === self::ACCION_ALICUOTA) {
            $valor = self::numeroAlicuota($alicuota);
        } elseif ($accion === self::ACCION_NO_RETIENE) {
            if ($imp['ALCANCE'] !== self::ALCANCE_TODOS) {
                throw new Exception($imp['NOMBRE'] . ' se aplica solo a los asignados: para que no '
                    . 'se le retenga a un proveedor alcanza con quitarle la asignación.');
            }

            $valor = 0.0;
        } elseif ($accion === self::ACCION_GENERAL) {
            $valor = null;
        } else {
            throw new Exception('Acción desconocida: "' . $accion . '".');
        }

        $lista = [];

        foreach (is_array($cods) ? $cods : [] as $c) {
            $c = Planilla::codigo($c);

            if ($c !== '') {
                $lista[$c] = true;
            }
        }

        if (empty($lista)) {
            throw new Exception('No llegó ningún proveedor.');
        }

        $plan = [
            'impuesto' => $imp, 'accion' => $accion, 'valor' => $valor,
            'aplicar' => [], 'nuevos' => [], 'reemplaza' => [], 'iguales' => [],
            'fuera_maestro' => [], 'rechazados' => []
        ];

        $nueva = $config;

        foreach (array_keys($lista) as $c) {
            if ($enMaestro !== null && !isset($enMaestro[$c])) {
                $plan['fuera_maestro'][] = $c;
                continue;
            }

            $actual = self::propia($config, $c, $imp['CODIGO'], $hoy);
            $antes = ($actual === null) ? null : $actual['alicuota'];

            $igual = ($antes === null && $valor === null)
                || ($antes !== null && $valor !== null
                    && abs($antes - $valor) <= Retenciones::TOLERANCIA_TASA);

            if ($igual) {
                $plan['iguales'][] = $c;
                continue;
            }

            $plan['aplicar'][] = $c;
            $plan[$antes === null ? 'nuevos' : 'reemplaza'][] = $c;

            $nueva['por_proveedor'][$c][] = ['ID' => PHP_INT_MAX, 'CONCEPTO' => $imp['CODIGO'],
                                             'ALICUOTA' => $valor, 'VIGENCIA_DESDE' => $hoy];
        }

        /* SE VALIDA CUALQUIER ACCION, no solo una alicuota mayor a cero:
           volver a la general tambien puede subir la suma, si el proveedor
           tenia 0 y la general es alta. */
        if (!empty($plan['aplicar'])) {
            $plan['rechazados'] = self::excedidos($nueva, $plan['aplicar'], $hoy);
        }

        return $plan;
    }

    /**
     * El valor efectivo HOY de cada impuesto activo para cada proveedor: la
     * columna del Maestro. Estatica y pura.
     *
     * @param array $config
     * @param array $cods
     * @param string $hoy
     * @return array Mapa cod => [codigo => ['alicuota', 'fuente', 'vigencia_desde',
     *         'sin_general']]
     */
    public static function efectivasHoy($config, $cods, $hoy) {
        $v = [];

        if (empty($config['disponible'])) {
            return $v;
        }

        $activos = self::impuestosActivos($config);

        foreach ($cods as $cod) {
            foreach ($activos as $codigo => $imp) {
                $v[$cod][$codigo] = self::efectiva($config, $imp, $cod, $hoy);
            }
        }

        return $v;
    }

    /**
     * Lo que el Maestro necesita para las columnas, el filtro y la carga: los
     * impuestos activos, en su orden, y el valor efectivo HOY de cada uno para
     * cada proveedor. Pura.
     *
     * El valor de la columna lo resuelve efectiva() -la misma regla del
     * calculo- y no el navegador: si la pantalla decidiera por su cuenta de
     * donde sale una alicuota, podria decir "general" donde el neto usa la del
     * proveedor.
     *
     * @param array $config
     * @param array $cods Los codigos del maestro
     * @param string $hoy
     * @return array ['disponible', 'error', 'impuestos', 'alcances',
     *         'alcance_todos', 'efectivas', 'hoy']
     */
    public static function paraMaestro($config, $cods, $hoy) {
        $impuestos = [];

        foreach (self::impuestosActivos($config) as $i) {
            $impuestos[] = ['CODIGO' => $i['CODIGO'], 'NOMBRE' => $i['NOMBRE'], 'ALCANCE' => $i['ALCANCE']];
        }

        return [
            'disponible' => !empty($config['disponible']),
            'error' => isset($config['error']) ? $config['error'] : null,
            'impuestos' => $impuestos,
            'alcances' => self::ALCANCES,
            'alcance_todos' => self::ALCANCE_TODOS,
            'efectivas' => self::efectivasHoy($config, $cods, $hoy),
            'hoy' => $hoy
        ];
    }

    /**
     * Un plan de asignacion como lo lee el dialogo: cuantos y cuales, sin la
     * fila del impuesto entera. Pura.
     *
     * @param array $plan De planAsignacion()
     * @return array
     */
    public static function resumenPlan($plan) {
        return [
            'impuesto' => $plan['impuesto']['CODIGO'],
            'nombre' => $plan['impuesto']['NOMBRE'],
            'accion' => $plan['accion'],
            'valor' => $plan['valor'],
            'aplicar' => count($plan['aplicar']),
            'nuevos' => count($plan['nuevos']),
            'reemplaza' => count($plan['reemplaza']),
            'iguales' => count($plan['iguales']),
            'fuera_maestro' => $plan['fuera_maestro'],
            'rechazados' => $plan['rechazados'],
            'texto_rechazados' => empty($plan['rechazados']) ? '' : self::textoExcedidos($plan['rechazados'])
        ];
    }

    /**
     * Lo que la pantalla de Parametros lista de cada impuesto: su alicuota
     * vigente hoy y cuantos proveedores tienen alicuota propia. Pura.
     *
     * "Alicuota propia" es una vigente hoy y no NULL: quien volvio a la
     * general ya no la tiene.
     *
     * @param array $config
     * @param string $hoy
     * @return array Lista de impuestos, cada uno con 'vigente' y 'con_propia'
     */
    public static function resumenImpuestos($config, $hoy) {
        $v = [];

        foreach (isset($config['impuestos']) ? $config['impuestos'] : [] as $codigo => $imp) {
            $conPropia = 0;

            foreach (array_keys(isset($config['por_proveedor']) ? $config['por_proveedor'] : []) as $cod) {
                $p = self::propia($config, $cod, $codigo, $hoy);

                if ($p !== null && $p['alicuota'] !== null) {
                    $conPropia++;
                }
            }

            $imp['vigente'] = ($imp['ALCANCE'] === self::ALCANCE_TODOS)
                ? self::general($config, $codigo, $hoy) : null;
            $imp['con_propia'] = $conPropia;
            $v[] = $imp;
        }

        return $v;
    }

    /**
     * Marca en cada alicuota general si es la que rige HOY para su concepto.
     * Lo decide Retenciones::alicuotasVigentes(), la misma regla del calculo:
     * si el navegador eligiera por su cuenta podria marcar una que el
     * servidor no usa.
     *
     * @param array $generales
     * @param string $hoy
     * @return array Las mismas filas, con 'VIGENTE_HOY'
     */
    public static function marcarVigentesHoy($generales, $hoy) {
        $ids = [];

        foreach (Retenciones::alicuotasVigentes($generales, $hoy) as $vig) {
            $ids[$vig['id']] = true;
        }

        foreach ($generales as $i => $g) {
            $generales[$i]['VIGENTE_HOY'] = isset($ids[$g['ID']]);
        }

        return $generales;
    }

    /* ====================================================================
       ESCRITURAS
       ==================================================================== */

    /**
     * Da de alta un impuesto.
     *
     * El codigo no se puede repetir ni con uno dado de baja: el historial de
     * alicuotas se cruza por codigo, y dos impuestos con el mismo se lo
     * repartirian. Para volver a usar uno dado de baja, se reactiva.
     *
     * @param array $datos
     * @param string $usuario
     * @return array La fila nueva
     */
    public function addImpuesto($datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();
        $v = self::validarImpuesto($datos, true);
        $this->olvidar();
        $config = $this->config();

        if (isset($config['impuestos'][$v['codigo']])) {
            $ya = $config['impuestos'][$v['codigo']];

            throw new Exception('Ya existe un impuesto con el código ' . $v['codigo'] . ' ('
                . $ya['NOMBRE'] . (intval($ya['ACTIVO']) === 1 ? '' : ', dado de baja: reactivalo')
                . ').');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "INSERT INTO dbo." . self::TABLA_IMPUESTO . "
                 (CODIGO, NOMBRE, ALCANCE, ORDEN, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
             VALUES (?, ?, ?, ?, 1, ?, ?)",
            [$v['codigo'], $v['nombre'], $v['alcance'], $v['orden'], $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de alta el impuesto'));
        }

        sqlsrv_free_stmt($stmt);
        $this->olvidar();

        return $v;
    }

    /**
     * Edita el nombre, el alcance y el orden de un impuesto. El codigo no.
     *
     * CAMBIAR EL ALCANCE SE PERMITE y no borra nada: las alicuotas de los
     * proveedores quedan. Pasar a "solo a los asignados" deja de aplicar la
     * general -sus vigencias quedan en el historial-; volver a "a todos" la
     * vuelve a poner en juego. Como cambia la suma de cada proveedor, se
     * valida igual que una alicuota nueva.
     *
     * @param int $id
     * @param array $datos
     * @param string $usuario
     * @return array ['impuesto', 'alcance_cambio', 'sin_general']
     */
    public function saveImpuesto($id, $datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();
        $v = self::validarImpuesto($datos, false);
        $this->olvidar();
        $config = $this->config();
        $imp = self::porId($config, $id);

        $nueva = $config;
        $nueva['impuestos'][$imp['CODIGO']]['ALCANCE'] = $v['alcance'];
        $excedidos = self::excedidos($nueva, null, $this->hoy());

        if (!empty($excedidos)) {
            throw new Exception('Con ese alcance, estos proveedores quedarían con sus retenciones '
                . 'sumando 100% o más: ' . self::textoExcedidos($excedidos) . '. No se guardó nada.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA_IMPUESTO . "
             SET NOMBRE = ?, ALCANCE = ?, ORDEN = ?, " . Auditoria::SET_MODIF . "
             WHERE ID = ?",
            [$v['nombre'], $v['alcance'], $v['orden'], $usuario, $imp['ID']]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el impuesto'));
        }

        sqlsrv_free_stmt($stmt);
        $this->olvidar();

        return [
            'impuesto' => array_merge($imp, ['NOMBRE' => $v['nombre'], 'ALCANCE' => $v['alcance'],
                                             'ORDEN' => $v['orden']]),
            'alcance_cambio' => ($v['alcance'] !== $imp['ALCANCE']),
            'sin_general' => ($v['alcance'] === self::ALCANCE_TODOS
                && self::general($config, $imp['CODIGO'], $this->hoy()) === null)
        ];
    }

    /**
     * Da de baja o reactiva un impuesto. BAJA LOGICA: deja de aplicarse en el
     * calculo desde ahora, y sus vigencias y asignaciones quedan.
     *
     * Reactivar vuelve a sumar su alicuota a la de cada proveedor, asi que se
     * valida que nadie quede en 100% o mas.
     *
     * @param int $id
     * @param bool $activo
     * @param string $usuario
     * @return array ['impuesto', 'con_propia']
     */
    public function activarImpuesto($id, $activo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();
        $this->olvidar();
        $config = $this->config();
        $imp = self::porId($config, $id);
        $activo = !empty($activo);

        if ($activo) {
            $nueva = $config;
            $nueva['impuestos'][$imp['CODIGO']]['ACTIVO'] = 1;
            $excedidos = self::excedidos($nueva, null, $this->hoy());

            if (!empty($excedidos)) {
                throw new Exception('Si se reactiva ' . $imp['NOMBRE'] . ', estos proveedores '
                    . 'quedarían con sus retenciones sumando 100% o más: '
                    . self::textoExcedidos($excedidos) . '. No se reactivó.');
            }
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA_IMPUESTO . "
             SET ACTIVO = ?, " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", " . Auditoria::SET_MODIF . "
             WHERE ID = ?",
            array_merge([$activo ? 1 : 0], Auditoria::paramsBajaSegunEstado($activo, $usuario),
                        [$usuario, $imp['ID']]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al cambiar el estado del impuesto'));
        }

        sqlsrv_free_stmt($stmt);

        $resumen = self::resumenImpuestos($config, $this->hoy());
        $conPropia = 0;

        foreach ($resumen as $r) {
            if ($r['CODIGO'] === $imp['CODIGO']) {
                $conPropia = $r['con_propia'];
            }
        }

        $this->olvidar();

        return ['impuesto' => $imp, 'con_propia' => $conPropia];
    }

    /**
     * Carga una vigencia nueva de la alicuota general de un impuesto "a todos".
     * NO SE EDITA: se inserta, y la anterior sigue rigiendo para las fechas de
     * pago anteriores a esta.
     *
     * @param string $codigo
     * @param mixed $alicuota Fraccion
     * @param string $vigenciaDesde 'Y-m-d'
     * @param string $usuario
     * @return array De validarGeneral(), con 'id'
     */
    public function addGeneral($codigo, $alicuota, $vigenciaDesde, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();
        $this->olvidar();

        $v = self::validarGeneral($this->config(), $codigo, $alicuota, $vigenciaDesde);

        $stmt = sqlsrv_query($this->conectar(),
            "INSERT INTO dbo." . self::TABLA_ALICUOTA . "
                 (CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
             OUTPUT INSERTED.ID
             VALUES (?, ?, ?, 1, ?, ?)",
            [$v['impuesto']['CODIGO'], $v['alicuota'], $v['vigencia'], $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la alícuota'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $this->olvidar();

        $v['id'] = intval($row['ID']);

        return $v;
    }

    /**
     * Baja LOGICA de una vigencia general cargada por error, como en
     * Parametros -> Cheques. La que regia antes vuelve a regir sola: es la
     * ultima activa con fecha <= la de pago.
     *
     * @param int $id
     * @param string $usuario
     * @return array La fila dada de baja
     */
    public function bajaGeneral($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA_ALICUOTA . "
             SET ACTIVO = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND ACTIVO = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la alícuota'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        $this->olvidar();

        if ($filas < 1) {
            throw new Exception('La alícuota ' . intval($id) . ' no existe o ya estaba dada de baja.');
        }

        return ['id' => intval($id)];
    }

    /**
     * Asigna una alicuota a varios proveedores -o a uno-, TODO O NADA, en una
     * transaccion.
     *
     * VUELVE A CALCULAR EL PLAN contra la base de ahora: lo que mostro el
     * dialogo es lo que se pidio, no una autorizacion. Si algun proveedor
     * quedaria en 100% o mas, o no esta en el maestro, no se aplica a ninguno.
     *
     * @param array $cods
     * @param string $codigo
     * @param string $accion
     * @param mixed $alicuota
     * @param string $origen MASIVA | INDIVIDUAL
     * @param array $enMaestro Mapa de los proveedores vigentes del maestro
     * @param string $usuario
     * @return array El plan aplicado
     */
    public function asignar($cods, $codigo, $accion, $alicuota, $origen, $enMaestro, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirScript();

        if (!in_array($origen, [self::ORIGEN_MASIVA, self::ORIGEN_INDIVIDUAL], true)) {
            throw new Exception('Origen desconocido: ' . $origen);
        }

        $this->olvidar();
        $hoy = $this->hoy();
        $plan = self::planAsignacion($this->config(), $cods, $codigo, $accion, $alicuota, $hoy,
            $enMaestro);

        if (!empty($plan['fuera_maestro'])) {
            throw new Exception('Estos proveedores no están vigentes en el maestro: '
                . implode(', ', array_slice($plan['fuera_maestro'], 0, 10))
                . '. Las retenciones se asignan a proveedores del maestro. No se aplicó a ninguno.');
        }

        if (!empty($plan['rechazados'])) {
            throw new Exception('Estos proveedores quedarían con sus retenciones sumando 100% o '
                . 'más, y su neto saldría cero o negativo: ' . self::textoExcedidos($plan['rechazados'])
                . '. No se aplicó a ninguno.');
        }

        if (empty($plan['aplicar'])) {
            return $plan;
        }

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($plan['aplicar'] as $cod) {
                $stmt = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA_PROVEEDOR . "
                         (COD_PROVEE, CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ORIGEN, USUARIO_ALTA)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$cod, $plan['impuesto']['CODIGO'], $plan['valor'], $hoy, $origen, $usuario]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al asignar la alícuota a ' . $cod));
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la asignación'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->olvidar();

        return $plan;
    }

    /** El impuesto con ese ID. LANZA si no existe. */
    private static function porId($config, $id) {
        foreach (isset($config['impuestos']) ? $config['impuestos'] : [] as $imp) {
            if ($imp['ID'] === intval($id)) {
                return $imp;
            }
        }

        throw new Exception('El impuesto ' . intval($id) . ' no existe.');
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    private function exigirScript() {
        if (!$this->tablasCreadas()) {
            throw new Exception(self::textoSinScript());
        }
    }

    /**
     * La fecha de hoy con la que se graba una vigencia y se valida. Es la del
     * servidor: "rige desde el dia de la carga" no se elige.
     *
     * Protegida para que una prueba la fije.
     *
     * @return string
     */
    protected function hoy() {
        return date('Y-m-d');
    }

    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** 'Y-m-d H:i', o null */
    private static function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null) ? null : substr((string) $v, 0, 16);
    }

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
