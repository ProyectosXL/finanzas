<?php

require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/PrestamosCalculo.php';
require_once __DIR__ . '/PrestamosTasas.php';

/**
 * PrestamosParametros
 * Parametros › Prestamos y los prestamos mismos: validadores puros, lectura y
 * escritura de las tablas de sql/cashflow_prestamos.sql (script 70).
 *
 * LOS PARAMETROS SON LOS GENERALES. Al dar de alta un prestamo se le copian
 * los costos activos, los tramos y la comision de cancelacion; despues se
 * cambian en el prestamo. Un cambio posterior en Parametros NO toca a los
 * prestamos que ya existen: viven en sus propias tablas.
 *
 * VALIDA EL SERVIDOR. Todo lo que llega del navegador pasa por un validador de
 * esta clase, que tira InvalidArgumentException con un texto para el usuario.
 * El JS espeja algunas reglas para ayudar, no para autorizar.
 *
 * AUDITORIA: toda escritura recibe el usuario por
 * AuthCashflow::usuarioDeEscritura() y graba USUARIO_ALTA/MODIF/BAJA. No hay
 * bajas fisicas.
 */
class PrestamosParametros {

    const SCRIPT = 'sql/cashflow_prestamos.sql';

    const T_PARAM = 'RO_T_CASHFLOW_PRESTAMO_PARAM';
    const T_PARAM_COSTO = 'RO_T_CASHFLOW_PRESTAMO_PARAM_COSTO';
    const T_PARAM_TRAMO = 'RO_T_CASHFLOW_PRESTAMO_PARAM_TRAMO';
    const T_TASA_MANUAL = 'RO_T_CASHFLOW_PRESTAMO_TASA_MANUAL';
    const T_PRESTAMO = 'RO_T_CASHFLOW_PRESTAMO';
    const T_COSTO = 'RO_T_CASHFLOW_PRESTAMO_COSTO';
    const T_TRAMO = 'RO_T_CASHFLOW_PRESTAMO_TRAMO';
    const T_AJUSTE = 'RO_T_CASHFLOW_PRESTAMO_CUOTA_AJUSTE';
    const T_CANCELACION = 'RO_T_CASHFLOW_PRESTAMO_CANCELACION';

    const TABLAS = [self::T_PARAM, self::T_PARAM_COSTO, self::T_PARAM_TRAMO, self::T_TASA_MANUAL, self::T_PRESTAMO,
                    self::T_COSTO, self::T_TRAMO, self::T_AJUSTE, self::T_CANCELACION];

    /**
     * Los generales, con su default (el mismo que siembra el script: el codigo
     * no depende de que se haya corrido para leer) y su tipo.
     */
    const GENERALES = [
        'DIAS_PROMEDIO' => ['nombre' => 'Días hábiles del promedio de la tasa', 'tipo' => 'entero', 'min' => 1, 'max' => 30, 'defecto' => 5],
        'ANTIGUEDAD_MAX' => ['nombre' => 'Antigüedad tolerada de la tasa (días hábiles)', 'tipo' => 'entero', 'min' => 1, 'max' => 30, 'defecto' => 5],
        'MARGEN' => ['nombre' => 'Margen de seguridad (%)', 'tipo' => 'pct', 'max' => 100, 'defecto' => 10],
        'SIM_SISTEMA' => ['nombre' => 'Sistema por defecto', 'tipo' => 'lista', 'lista' => PrestamosCalculo::SISTEMAS, 'defecto' => 'FRANCES'],
        'SIM_CUOTAS' => ['nombre' => 'Cuotas por defecto', 'tipo' => 'entero', 'min' => 1, 'max' => 360, 'defecto' => 24],
        'SIM_FRECUENCIA' => ['nombre' => 'Frecuencia por defecto', 'tipo' => 'lista', 'lista' => PrestamosCalculo::NOMBRE_FRECUENCIA, 'defecto' => 'MENSUAL'],
        'SIM_TNA' => ['nombre' => 'TNA fija por defecto (%)', 'tipo' => 'pct', 'max' => 1000, 'defecto' => 38],
        'CANCEL_MODO' => ['nombre' => 'Comisión de cancelación', 'tipo' => 'lista',
                          'lista' => [PrestamosCalculo::COMISION_FIJA => 'Fija', PrestamosCalculo::COMISION_ESCALONADA => 'Escalonada por tramos'],
                          'defecto' => 'FIJA'],
        'CANCEL_PCT_TOTAL' => ['nombre' => 'Comisión por cancelación total (%)', 'tipo' => 'pct', 'max' => 100, 'defecto' => 3],
        'CANCEL_PCT_PARCIAL' => ['nombre' => 'Comisión por cancelación parcial (%)', 'tipo' => 'pct', 'max' => 100, 'defecto' => 3],
        'CANCEL_IVA' => ['nombre' => 'La comisión lleva IVA', 'tipo' => 'bool', 'defecto' => 1]
    ];

    const MONEDAS = ['ARS' => 'Pesos', 'USD' => 'Dólares'];
    const FORMAS_PAGO = ['DEBITO' => 'Débito automático', 'TRANSFERENCIA' => 'Transferencia'];
    const BASES = [PrestamosCalculo::BASE_CAPITAL => 'Capital', PrestamosCalculo::BASE_INTERESES => 'Intereses'];
    const MOMENTOS = [PrestamosCalculo::INICIAL => 'Inicial (el día del desembolso)', PrestamosCalculo::POR_CUOTA => 'Por cuota'];
    const ESTADOS_GUARDADOS = [PrestamosCalculo::SIMULADO, PrestamosCalculo::VIGENTE];

    const LARGO_NOMBRE = 150;
    const LARGO_COSTO = 100;
    const LARGO_OBS = 300;
    const MAX_CUOTAS = 360;

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    /** @var bool|null */
    private $tablas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       VALIDADORES PUROS
       ==================================================================== */

    /**
     * Un numero con coma o punto decimal, sin separador de miles, con hasta
     * $dec decimales. Vacio = null si se permite.
     */
    public static function numero($valor, $nombre, $vacioPermitido = true, $min = 0.0, $max = null, $dec = 4) {
        if ($valor === null || trim((string) $valor) === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        if (is_float($valor) || is_int($valor)) {
            $t = (string) $valor;
        } else {
            $t = str_replace(',', '.', trim((string) $valor));
        }

        if (!is_numeric($t) || !preg_match('/^-?\d+(\.\d+)?(E[-+]?\d+)?$/i', $t)) {
            throw new InvalidArgumentException("$nombre tiene que ser un número.");
        }

        $v = round((float) $t, $dec);

        if ($min !== null && $v < $min) {
            throw new InvalidArgumentException("$nombre no puede ser menor que " . self::fmt($min) . '.');
        }

        if ($max !== null && $v > $max) {
            throw new InvalidArgumentException("$nombre no puede ser mayor que " . self::fmt($max) . '.');
        }

        return $v;
    }

    public static function entero($valor, $min, $max, $nombre, $vacioPermitido = true) {
        if ($valor === null || trim((string) $valor) === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        $t = trim((string) $valor);

        if (!preg_match('/^-?\d+$/', $t) || (int) $t < $min || (int) $t > $max) {
            throw new InvalidArgumentException("$nombre tiene que ser un número entero de $min a $max.");
        }

        return (int) $t;
    }

    public static function fecha($valor, $nombre, $vacioPermitido = false) {
        $t = substr(trim((string) $valor), 0, 10);

        if ($t === '' && $vacioPermitido) {
            return null;
        }

        $d = DateTime::createFromFormat('Y-m-d', $t);

        if ($d === false || $d->format('Y-m-d') !== $t) {
            throw new InvalidArgumentException("Falta $nombre, o no es una fecha válida.");
        }

        return $t;
    }

    public static function texto($valor, $largo, $nombre, $vacioPermitido = true) {
        $t = trim((string) $valor);

        if ($t === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        if (mb_strlen($t, 'UTF-8') > $largo) {
            throw new InvalidArgumentException("$nombre puede tener hasta $largo caracteres.");
        }

        return $t;
    }

    public static function opcion($valor, $lista, $nombre, $vacioPermitido = false) {
        $t = strtoupper(trim((string) $valor));

        if ($t === '' && $vacioPermitido) {
            return null;
        }

        if (!isset($lista[$t])) {
            throw new InvalidArgumentException("Elegí $nombre.");
        }

        return $t;
    }

    public static function bool($valor) {
        return in_array($valor, [true, 1, '1', 'true', 'on', 'S', 'SI'], true);
    }

    /** Un valor de Generales, ya en su tipo. */
    public static function validarGeneral($clave, $valor) {
        if (!isset(self::GENERALES[$clave])) {
            throw new InvalidArgumentException('Ese parámetro no existe.');
        }

        $g = self::GENERALES[$clave];

        switch ($g['tipo']) {
            case 'entero':
                return self::entero($valor, $g['min'], $g['max'], $g['nombre'], false);
            case 'pct':
                return self::numero($valor, $g['nombre'], false, 0.0, $g['max']);
            case 'lista':
                return self::opcion($valor, $g['lista'], $g['nombre']);
            default:
                return self::bool($valor) ? 1 : 0;
        }
    }

    /** Un costo asociado (de Parametros o de un prestamo). */
    public static function validarCosto($d) {
        $v = [
            'nombre' => self::texto($d['nombre'] ?? '', self::LARGO_COSTO, 'el nombre del costo', false),
            'pct' => self::numero($d['pct'] ?? null, 'El % del costo', false, 0.0, 100.0),
            'base' => self::opcion($d['base'] ?? '', self::BASES, 'la base del costo (capital o intereses)'),
            'momento' => self::opcion($d['momento'] ?? '', self::MOMENTOS, 'cuándo se paga el costo'),
            'iva' => self::bool($d['iva'] ?? false),
            'activo' => array_key_exists('activo', $d) ? self::bool($d['activo']) : true
        ];

        if ($v['momento'] === PrestamosCalculo::INICIAL && $v['base'] === PrestamosCalculo::BASE_INTERESES) {
            throw new InvalidArgumentException('«' . $v['nombre'] . '»: un costo inicial no puede calcularse sobre '
                . 'los intereses, porque el día del desembolso todavía no hay intereses. Usá base capital o pasalo a por cuota.');
        }

        return $v;
    }

    /** Los tramos de la comision escalonada: desde 0 a < 100, sin repetir. */
    public static function validarTramos($lista) {
        $out = [];
        $desdes = [];

        foreach (is_array($lista) ? $lista : [] as $i => $t) {
            $desde = self::numero($t['desde'] ?? null, 'El "desde" del tramo ' . ($i + 1), false, 0.0, 99.99, 2);

            if (isset($desdes[(string) $desde])) {
                throw new InvalidArgumentException('Hay dos tramos que empiezan en ' . self::fmt($desde) . ' % del plazo.');
            }

            $desdes[(string) $desde] = true;
            $out[] = ['desde' => $desde,
                      'pct_total' => self::numero($t['pct_total'] ?? null, 'El % total del tramo ' . ($i + 1), false, 0.0, 100.0),
                      'pct_parcial' => self::numero($t['pct_parcial'] ?? null, 'El % parcial del tramo ' . ($i + 1), false, 0.0, 100.0)];
        }

        usort($out, function ($a, $b) { return $a['desde'] <=> $b['desde']; });

        return $out;
    }

    /** La comision de cancelacion: modo, % y tramos. */
    public static function validarComision($d) {
        $v = [
            'modo' => self::opcion($d['modo'] ?? PrestamosCalculo::COMISION_FIJA, self::GENERALES['CANCEL_MODO']['lista'], 'cómo es la comisión de cancelación'),
            'pct_total' => self::numero($d['pct_total'] ?? 0, 'La comisión por cancelación total', false, 0.0, 100.0),
            'pct_parcial' => self::numero($d['pct_parcial'] ?? 0, 'La comisión por cancelación parcial', false, 0.0, 100.0),
            'iva' => self::bool($d['iva'] ?? true),
            'tramos' => self::validarTramos($d['tramos'] ?? [])
        ];

        if ($v['modo'] === PrestamosCalculo::COMISION_ESCALONADA && (empty($v['tramos']) || $v['tramos'][0]['desde'] > 0)) {
            throw new InvalidArgumentException('Con la comisión escalonada, cargá los tramos empezando por el 0 % del plazo.');
        }

        return $v;
    }

    /**
     * Un prestamo, con todas las reglas de consistencia.
     *
     * @param array $d Lo que llego del navegador
     * @param array $cuentas clave => nombre: las cuentas bancarias de Saldos
     * @return array El prestamo normalizado (sin costos ni tramos)
     */
    public static function validarPrestamo($d, $cuentas) {
        $v = [];
        $v['nombre'] = self::texto($d['nombre'] ?? '', self::LARGO_NOMBRE, 'el nombre del préstamo', false);

        $clave = trim((string) ($d['cuenta_clave'] ?? ''));

        if ($clave === '' || !isset($cuentas[$clave])) {
            throw new InvalidArgumentException('Elegí el banco: una de las cuentas bancarias de Saldos.');
        }

        $v['cuenta_clave'] = $clave;
        $v['cuenta_nombre'] = mb_substr((string) $cuentas[$clave], 0, self::LARGO_NOMBRE, 'UTF-8');
        $v['moneda'] = self::opcion($d['moneda'] ?? '', self::MONEDAS, 'la moneda');
        $v['capital'] = self::numero($d['capital'] ?? null, 'El capital', false, 0.0, 1e15, 2);

        if ($v['capital'] <= 0) {
            throw new InvalidArgumentException('El capital tiene que ser mayor que cero.');
        }

        $v['fecha_desembolso'] = self::fecha($d['fecha_desembolso'] ?? '', 'la fecha de desembolso');
        $v['tipo_tasa'] = self::opcion($d['tipo_tasa'] ?? '', [PrestamosCalculo::FIJA => 1, PrestamosCalculo::VARIABLE => 1], 'si la tasa es fija o variable');

        if ($v['tipo_tasa'] === PrestamosCalculo::FIJA) {
            $v['tna'] = self::numero($d['tna'] ?? null, 'La TNA', false, 0.0, 1000.0);
            $v['referencia'] = null;
            $v['spread'] = null;
        } else {
            $v['tna'] = null;
            $v['referencia'] = self::opcion($d['referencia'] ?? '', PrestamosTasas::NOMBRES, 'la tasa de referencia (TAMAR o BADLAR)');
            $v['spread'] = self::numero($d['spread'] ?? null, 'El spread', false, -100.0, 100.0);
        }

        $v['criterio_tasa'] = self::opcion(($d['criterio_tasa'] ?? '') ?: PrestamosTasas::PROMEDIO, PrestamosTasas::CRITERIOS, 'cómo se proyecta la tasa');
        $v['sistema'] = self::opcion($d['sistema'] ?? '', PrestamosCalculo::SISTEMAS, 'el sistema de amortización');
        $v['cuotas'] = self::entero($d['cuotas'] ?? null, 1, self::MAX_CUOTAS, 'La cantidad de cuotas', false);
        $v['frec_capital'] = self::opcion($d['frec_capital'] ?? '', PrestamosCalculo::NOMBRE_FRECUENCIA, 'la frecuencia de capital');
        $v['frec_intereses'] = self::opcion(($d['frec_intereses'] ?? '') ?: $v['frec_capital'], PrestamosCalculo::NOMBRE_FRECUENCIA, 'la frecuencia de intereses');

        $fc = PrestamosCalculo::meses($v['frec_capital']);
        $fi = PrestamosCalculo::meses($v['frec_intereses']);

        if ($v['sistema'] === PrestamosCalculo::FRANCES && $fc !== $fi) {
            throw new InvalidArgumentException('El sistema francés necesita capital e intereses con la misma frecuencia. '
                . 'Con frecuencias distintas, elegí alemán o bullet.');
        }

        if (($v['cuotas'] * $fc) % $fi !== 0) {
            throw new InvalidArgumentException('El plazo (' . ($v['cuotas'] * $fc) . ' meses) tiene que ser múltiplo de la '
                . 'frecuencia de intereses (' . strtolower(PrestamosCalculo::NOMBRE_FRECUENCIA[$v['frec_intereses']]) . ').');
        }

        $v['fecha_primera'] = self::fecha($d['fecha_primera'] ?? '', 'la fecha de la primera cuota', true);

        if ($v['fecha_primera'] !== null && $v['fecha_primera'] <= $v['fecha_desembolso']) {
            throw new InvalidArgumentException('La primera cuota tiene que vencer después del desembolso.');
        }

        $v['gracia'] = self::entero(($d['gracia'] ?? '') === '' ? 0 : $d['gracia'], 0, self::MAX_CUOTAS, 'Los períodos de gracia', false);

        if ($v['gracia'] >= $v['cuotas']) {
            throw new InvalidArgumentException('Los períodos de gracia tienen que ser menos que las cuotas.');
        }

        $v['forma_pago'] = self::opcion($d['forma_pago'] ?? '', self::FORMAS_PAGO, 'la forma de pago', true);
        $v['estado'] = self::opcion(($d['estado'] ?? '') ?: PrestamosCalculo::SIMULADO,
            array_flip(self::ESTADOS_GUARDADOS), 'el estado (simulado o vigente)');
        $v['observacion'] = self::texto($d['observacion'] ?? '', self::LARGO_OBS, 'La observación');

        return $v;
    }

    /**
     * Una correccion de cuota.
     *
     * @param string $concepto CAPITAL | INTERES | TNA | COSTO:<id>
     * @param mixed $importe
     * @param int[] $costos Los ID de los costos vigentes del prestamo
     * @return array ['concepto', 'importe']
     */
    public static function validarAjuste($concepto, $importe, $costos) {
        $c = strtoupper(trim((string) $concepto));
        $esCosto = (strpos($c, PrestamosCalculo::AJ_COSTO) === 0);

        if (!in_array($c, [PrestamosCalculo::AJ_CAPITAL, PrestamosCalculo::AJ_INTERES, PrestamosCalculo::AJ_TNA], true) && !$esCosto) {
            throw new InvalidArgumentException('Eso no se corrige en una cuota.');
        }

        if ($esCosto && !in_array((int) substr($c, strlen(PrestamosCalculo::AJ_COSTO)), array_map('intval', $costos), true)) {
            throw new InvalidArgumentException('Ese costo no es de este préstamo.');
        }

        $nombre = ($c === PrestamosCalculo::AJ_TNA) ? 'La TNA' : 'El importe';
        $max = ($c === PrestamosCalculo::AJ_TNA) ? 1000.0 : 1e15;

        return ['concepto' => $c, 'importe' => self::numero($importe, $nombre, false, 0.0, $max, ($c === PrestamosCalculo::AJ_TNA) ? 4 : 2)];
    }

    /** Un valor manual de tasa por mes. Null en valor = quitarlo. */
    public static function validarTasaManual($ref, $mes, $valor) {
        $r = self::opcion($ref, PrestamosTasas::NOMBRES, 'la tasa (TAMAR o BADLAR)');
        $m = trim((string) $mes);

        if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $x) || (int) $x[2] < 1 || (int) $x[2] > 12) {
            throw new InvalidArgumentException('Elegí el mes.');
        }

        return ['referencia' => $r, 'mes' => $m, 'valor' => self::numero($valor, 'El valor de la tasa', true, 0.0, 1000.0)];
    }

    /**
     * Una cancelacion, contra el prestamo calculado.
     *
     * @param array $d ['fecha', 'tipo', 'monto', 'modo', 'observacion']
     * @param array $p El prestamo normalizado (PrestamosCalculo::normalizar())
     * @param string $estado El estado calculado
     * @param float $saldo El saldo de capital a esa fecha
     * @param string $ultima La fecha del ultimo vencimiento
     * @param bool $hayTotal Si ya tiene una cancelacion total vigente
     */
    public static function validarCancelacion($d, $p, $estado, $saldo, $ultima, $hayTotal) {
        if ($estado !== PrestamosCalculo::VIGENTE) {
            throw new InvalidArgumentException('Solo se cancela un préstamo vigente.');
        }

        if ($hayTotal) {
            throw new InvalidArgumentException('Ese préstamo ya tiene una cancelación total registrada. Deshacela para registrar otra.');
        }

        $v = [
            'fecha' => self::fecha($d['fecha'] ?? '', 'la fecha de la cancelación'),
            'tipo' => self::opcion($d['tipo'] ?? '', [PrestamosCalculo::TOTAL => 1, PrestamosCalculo::PARCIAL => 1], 'si es total o parcial'),
            'monto' => null,
            'modo' => null,
            'observacion' => self::texto($d['observacion'] ?? '', self::LARGO_OBS, 'La observación')
        ];

        if ($v['fecha'] <= $p['fecha_desembolso'] || $v['fecha'] > $ultima) {
            throw new InvalidArgumentException('La fecha tiene que estar entre el desembolso (' . PrestamosCalculo::corta($p['fecha_desembolso'])
                . ') y el último vencimiento (' . PrestamosCalculo::corta($ultima) . ').');
        }

        if ($v['tipo'] === PrestamosCalculo::PARCIAL) {
            $v['monto'] = self::numero($d['monto'] ?? null, 'El monto a adelantar', false, 0.0, null, 2);
            $v['modo'] = self::opcion($d['modo'] ?? '', [PrestamosCalculo::REDUCIR_CUOTA => 1, PrestamosCalculo::REDUCIR_PLAZO => 1],
                'si se reduce la cuota o el plazo');

            if ($v['monto'] <= 0) {
                throw new InvalidArgumentException('El monto a adelantar tiene que ser mayor que cero.');
            }

            if ($v['monto'] >= round($saldo, 2)) {
                throw new InvalidArgumentException('El monto (' . PrestamosCalculo::plata($v['monto'], $p['moneda']) . ') no es menor que el saldo a esa fecha ('
                    . PrestamosCalculo::plata($saldo, $p['moneda']) . '): para eso, registrá una cancelación total.');
            }
        }

        return $v;
    }

    /** Lo que se le copia a un prestamo nuevo: costos activos, comision y tramos. */
    public static function copiaParaAlta($generales, $costosParam, $tramosParam) {
        return [
            'costos' => array_values(array_map(function ($c) {
                return ['id_param' => $c['id'], 'nombre' => $c['nombre'], 'pct' => $c['pct'], 'base' => $c['base'],
                        'momento' => $c['momento'], 'iva' => $c['iva']];
            }, array_filter($costosParam, function ($c) { return !empty($c['activo']); }))),
            'cancelacion' => [
                'modo' => $generales['CANCEL_MODO'], 'pct_total' => (float) $generales['CANCEL_PCT_TOTAL'],
                'pct_parcial' => (float) $generales['CANCEL_PCT_PARCIAL'], 'iva' => (bool) $generales['CANCEL_IVA'],
                'tramos' => array_map(function ($t) {
                    return ['desde' => $t['desde'], 'pct_total' => $t['pct_total'], 'pct_parcial' => $t['pct_parcial']];
                }, $tramosParam)
            ]
        ];
    }

    private static function fmt($n) {
        return rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    public function tablasFaltantes() {
        $faltan = [];

        foreach (self::TABLAS as $t) {
            $r = $this->fila("SELECT OBJECT_ID(?, 'U') AS ID", ['dbo.' . $t], 'Error al verificar las tablas de Préstamos');

            if ($r === null || $r['ID'] === null) {
                $faltan[] = $t;
            }
        }

        return $faltan;
    }

    /** @return bool Si el script 70 ya se corrio. Nunca lanza. */
    public function tablaCreada() {
        if ($this->tablas === null) {
            try {
                $this->tablas = empty($this->tablasFaltantes());
            } catch (Throwable $e) {
                $this->tablas = false;
            }
        }

        return $this->tablas;
    }

    public static function avisoSinTabla() {
        return 'Faltan las tablas de Préstamos: hay que correr ' . self::SCRIPT . ' contra la base central. '
            . 'Hasta entonces no se cargan préstamos ni parámetros, y el tablero no suma nada de préstamos.';
    }

    /** @return array clave => valor en su tipo (con el default si no hay fila), + '_meta' */
    public function generales() {
        $out = [];
        $meta = [];

        foreach (self::GENERALES as $k => $g) {
            $out[$k] = $g['defecto'];
        }

        if ($this->tablaCreada()) {
            foreach ($this->filas("SELECT CLAVE, VALOR, USUARIO_ALTA, FECHA_ALTA FROM dbo." . self::T_PARAM . " WHERE VIGENTE = 1",
                                  [], 'Error al leer los parámetros de Préstamos') as $r) {
                $k = trim($r['CLAVE']);

                if (!isset(self::GENERALES[$k])) {
                    continue;
                }

                $tipo = self::GENERALES[$k]['tipo'];
                $out[$k] = ($tipo === 'lista') ? trim($r['VALOR']) : (($tipo === 'entero' || $tipo === 'bool') ? (int) $r['VALOR'] : (float) $r['VALOR']);
                $meta[$k] = ['usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])];
            }
        }

        return $out + ['_meta' => $meta];
    }

    /** @return array Lista de costos de Parametros (activos e inactivos) */
    public function costosParam() {
        return array_map([self::class, 'costoDeFila'], $this->filas("SELECT * FROM dbo." . self::T_PARAM_COSTO . " ORDER BY ID", [],
            'Error al leer los costos asociados'));
    }

    /** @return array Tramos vigentes de Parametros */
    public function tramosParam() {
        return array_map([self::class, 'tramoDeFila'], $this->filas("SELECT * FROM dbo." . self::T_PARAM_TRAMO
            . " WHERE VIGENTE = 1 ORDER BY DESDE_PCT", [], 'Error al leer los tramos'));
    }

    /** @return array ref => ['Y-m' => valor] + lista para la grilla */
    public function tasasManual() {
        $mapa = [PrestamosTasas::TAMAR => [], PrestamosTasas::BADLAR => []];
        $lista = [];

        foreach ($this->filas("SELECT * FROM dbo." . self::T_TASA_MANUAL . " WHERE VIGENTE = 1 ORDER BY REFERENCIA, MES", [],
                              'Error al leer las tasas manuales') as $r) {
            $ref = trim($r['REFERENCIA']);
            $mes = trim($r['MES']);
            $mapa[$ref][$mes] = (float) $r['VALOR'];
            $lista[] = ['id' => (int) $r['ID'], 'referencia' => $ref, 'mes' => $mes, 'valor' => (float) $r['VALOR'],
                        'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])];
        }

        return ['mapa' => $mapa, 'lista' => $lista];
    }

    /**
     * Todos los prestamos vigentes (no eliminados) con sus costos, tramos,
     * correcciones y cancelaciones, como los pide PrestamosCalculo.
     *
     * @return array id => ['prestamo', 'costos', 'ajustes', 'cancelaciones', 'lista_ajustes', 'lista_cancelaciones']
     */
    public function prestamos() {
        $out = [];

        foreach ($this->filas("SELECT * FROM dbo." . self::T_PRESTAMO . " WHERE VIGENTE = 1 ORDER BY FECHA_DESEMBOLSO, ID", [],
                              'Error al leer los préstamos') as $r) {
            $id = (int) $r['ID'];
            $out[$id] = ['prestamo' => [
                'id' => $id, 'nombre' => $r['NOMBRE'], 'cuenta_clave' => $r['CUENTA_CLAVE'], 'cuenta_nombre' => $r['CUENTA_NOMBRE'],
                'moneda' => trim($r['MONEDA']), 'capital' => (float) $r['CAPITAL'], 'fecha_desembolso' => self::dia($r['FECHA_DESEMBOLSO']),
                'tipo_tasa' => trim($r['TIPO_TASA']), 'tna' => self::num($r['TNA']),
                'referencia' => ($r['REFERENCIA'] === null) ? null : trim($r['REFERENCIA']), 'spread' => self::num($r['SPREAD']),
                'criterio_tasa' => trim($r['CRITERIO_TASA']), 'sistema' => trim($r['SISTEMA']), 'cuotas' => (int) $r['CUOTAS'],
                'frec_capital' => trim($r['FREC_CAPITAL']), 'frec_intereses' => trim($r['FREC_INTERESES']),
                'fecha_primera' => self::dia($r['FECHA_PRIMERA']), 'gracia' => (int) $r['GRACIA'],
                'forma_pago' => ($r['FORMA_PAGO'] === null) ? null : trim($r['FORMA_PAGO']),
                'estado' => trim($r['ESTADO']), 'observacion' => $r['OBSERVACION'],
                'cancelacion' => ['modo' => trim($r['CANCEL_MODO']), 'pct_total' => (float) $r['CANCEL_PCT_TOTAL'],
                                  'pct_parcial' => (float) $r['CANCEL_PCT_PARCIAL'], 'iva' => (bool) $r['CANCEL_IVA'], 'tramos' => []],
                'usuario_alta' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA']),
                'usuario_modif' => $r['USUARIO_MODIF'], 'fecha_modif' => self::momento($r['FECHA_MODIF'])
            ], 'costos' => [], 'ajustes' => [], 'cancelaciones' => [], 'lista_ajustes' => [], 'lista_cancelaciones' => []];
        }

        if (empty($out)) {
            return $out;
        }

        foreach ($this->filas("SELECT * FROM dbo." . self::T_COSTO . " WHERE VIGENTE = 1 ORDER BY ID", [], 'Error al leer los costos de los préstamos') as $r) {
            if (isset($out[(int) $r['ID_PRESTAMO']])) {
                $out[(int) $r['ID_PRESTAMO']]['costos'][] = self::costoDeFila($r);
            }
        }

        foreach ($this->filas("SELECT * FROM dbo." . self::T_TRAMO . " WHERE VIGENTE = 1 ORDER BY DESDE_PCT", [], 'Error al leer los tramos de los préstamos') as $r) {
            if (isset($out[(int) $r['ID_PRESTAMO']])) {
                $out[(int) $r['ID_PRESTAMO']]['prestamo']['cancelacion']['tramos'][] = self::tramoDeFila($r);
            }
        }

        foreach ($this->filas("SELECT * FROM dbo." . self::T_AJUSTE . " WHERE VIGENTE = 1 ORDER BY CUOTA", [], 'Error al leer las correcciones') as $r) {
            $id = (int) $r['ID_PRESTAMO'];

            if (isset($out[$id])) {
                $a = ['id' => (int) $r['ID'], 'cuota' => (int) $r['CUOTA'], 'concepto' => trim($r['CONCEPTO']),
                      'importe' => (float) $r['IMPORTE'], 'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])];
                $out[$id]['ajustes'][$a['cuota']][$a['concepto']] = $a;
                $out[$id]['lista_ajustes'][] = $a;
            }
        }

        foreach ($this->filas("SELECT * FROM dbo." . self::T_CANCELACION . " WHERE VIGENTE = 1 ORDER BY FECHA", [], 'Error al leer las cancelaciones') as $r) {
            $id = (int) $r['ID_PRESTAMO'];

            if (isset($out[$id])) {
                $c = ['id' => (int) $r['ID'], 'fecha' => self::dia($r['FECHA']), 'tipo' => trim($r['TIPO']),
                      'monto' => self::num($r['MONTO']), 'modo' => ($r['MODO'] === null) ? null : trim($r['MODO']),
                      'observacion' => $r['OBSERVACION'], 'usuario' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA'])];
                $out[$id]['cancelaciones'][] = $c;
            }
        }

        return $out;
    }

    private static function costoDeFila($r) {
        return ['id' => (int) $r['ID'], 'nombre' => $r['NOMBRE'], 'pct' => (float) $r['PCT'], 'base' => trim($r['BASE']),
                'momento' => trim($r['MOMENTO']), 'iva' => (bool) $r['LLEVA_IVA'],
                'activo' => isset($r['ACTIVO']) ? (bool) $r['ACTIVO'] : true,
                'id_param' => isset($r['ID_COSTO_PARAM']) && $r['ID_COSTO_PARAM'] !== null ? (int) $r['ID_COSTO_PARAM'] : null,
                'usuario' => $r['USUARIO_MODIF'] ?? $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_MODIF'] ?? $r['FECHA_ALTA'])];
    }

    private static function tramoDeFila($r) {
        return ['id' => (int) $r['ID'], 'desde' => (float) $r['DESDE_PCT'], 'pct_total' => (float) $r['PCT_TOTAL'],
                'pct_parcial' => (float) $r['PCT_PARCIAL']];
    }

    /* ====================================================================
       ESCRITURA: PARAMETROS
       ==================================================================== */

    public function guardarGeneral($clave, $valor, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarGeneral($clave, $valor);

        $this->enTransaccion(function () use ($clave, $v, $usuario) {
            $this->darDeBaja(self::T_PARAM, 'CLAVE = ? AND VIGENTE = 1', [$clave], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_PARAM . " (CLAVE, VALOR, VIGENTE, USUARIO_ALTA, USUARIO_MODIF) VALUES (?, ?, 1, ?, ?)",
                [$clave, (string) $v, $usuario, $usuario], 'Error al guardar el parámetro');
        });

        return ['clave' => $clave, 'valor' => $v];
    }

    /** Alta (sin id) o edicion de un costo de Parametros. */
    public function guardarCostoParam($datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarCosto($datos);
        $id = isset($datos['id']) && $datos['id'] !== '' ? self::entero($datos['id'], 1, PHP_INT_MAX, 'El costo', false) : null;

        if ($id === null) {
            $r = $this->fila("INSERT INTO dbo." . self::T_PARAM_COSTO . " (NOMBRE, PCT, BASE, MOMENTO, LLEVA_IVA, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                              OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$v['nombre'], $v['pct'], $v['base'], $v['momento'], $v['iva'] ? 1 : 0, $v['activo'] ? 1 : 0, $usuario, $usuario],
                'Error al guardar el costo');
            $id = (int) $r['ID'];
        } else {
            $stmt = $this->ejecutar("UPDATE dbo." . self::T_PARAM_COSTO . " SET NOMBRE = ?, PCT = ?, BASE = ?, MOMENTO = ?, LLEVA_IVA = ?,
                                     " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", ACTIVO = ?, " . Auditoria::SET_MODIF . " WHERE ID = ?",
                array_merge([$v['nombre'], $v['pct'], $v['base'], $v['momento'], $v['iva'] ? 1 : 0],
                    Auditoria::paramsBajaSegunEstado($v['activo'], $usuario), [$v['activo'] ? 1 : 0, $usuario, $id]),
                'Error al guardar el costo');
            $n = sqlsrv_rows_affected($stmt);

            if ((int) $n === 0) {
                throw new InvalidArgumentException('Ese costo ya no existe. Volvé a cargar la pantalla.');
            }
        }

        return ['id' => $id] + $v;
    }

    /** Los tramos de Parametros, como juego. */
    public function guardarTramosParam($lista, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $tramos = self::validarTramos($lista);

        $this->enTransaccion(function () use ($tramos, $usuario) {
            $this->darDeBaja(self::T_PARAM_TRAMO, 'VIGENTE = 1', [], $usuario);

            foreach ($tramos as $t) {
                $this->ejecutar("INSERT INTO dbo." . self::T_PARAM_TRAMO . " (DESDE_PCT, PCT_TOTAL, PCT_PARCIAL, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                                 VALUES (?, ?, ?, 1, ?, ?)", [$t['desde'], $t['pct_total'], $t['pct_parcial'], $usuario, $usuario],
                    'Error al guardar los tramos');
            }
        });

        return $tramos;
    }

    /** Un valor manual de tasa por mes. Null lo quita. */
    public function guardarTasaManual($ref, $mes, $valor, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarTasaManual($ref, $mes, $valor);

        $this->enTransaccion(function () use ($v, $usuario) {
            $this->darDeBaja(self::T_TASA_MANUAL, 'REFERENCIA = ? AND MES = ? AND VIGENTE = 1', [$v['referencia'], $v['mes']], $usuario);

            if ($v['valor'] !== null) {
                $this->ejecutar("INSERT INTO dbo." . self::T_TASA_MANUAL . " (REFERENCIA, MES, VALOR, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                                 VALUES (?, ?, ?, 1, ?, ?)", [$v['referencia'], $v['mes'], $v['valor'], $usuario, $usuario],
                    'Error al guardar la tasa');
            }
        });

        return $v;
    }

    /* ====================================================================
       ESCRITURA: PRESTAMOS
       ==================================================================== */

    /**
     * Alta de un prestamo. Si no vienen costos ni comision, se copian los de
     * Parametros (los costos activos, la comision y los tramos): es la regla
     * de "los parametros son los generales".
     *
     * @return array ['id'] + el prestamo
     */
    public function altaPrestamo($datos, $cuentas, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarPrestamo($datos, $cuentas);
        $copia = self::copiaParaAlta($this->generales(), $this->costosParam(), $this->tramosParam());
        $costos = array_key_exists('costos', $datos) && is_array($datos['costos'])
            ? array_map([self::class, 'validarCosto'], $datos['costos']) : $copia['costos'];
        $com = array_key_exists('cancelacion', $datos) && is_array($datos['cancelacion'])
            ? self::validarComision($datos['cancelacion']) : self::validarComision($copia['cancelacion']);
        $id = null;

        $this->enTransaccion(function () use ($v, $costos, $com, $usuario, &$id, $datos) {
            $r = $this->fila("INSERT INTO dbo." . self::T_PRESTAMO . "
                    (NOMBRE, CUENTA_CLAVE, CUENTA_NOMBRE, MONEDA, CAPITAL, FECHA_DESEMBOLSO, TIPO_TASA, TNA, REFERENCIA, SPREAD,
                     CRITERIO_TASA, SISTEMA, CUOTAS, FREC_CAPITAL, FREC_INTERESES, FECHA_PRIMERA, GRACIA, FORMA_PAGO,
                     CANCEL_MODO, CANCEL_PCT_TOTAL, CANCEL_PCT_PARCIAL, CANCEL_IVA, ESTADO, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                array_merge(self::paramsPrestamo($v), [$com['modo'], $com['pct_total'], $com['pct_parcial'], $com['iva'] ? 1 : 0,
                    $v['estado'], $v['observacion'], $usuario, $usuario]), 'Error al guardar el préstamo');
            $id = (int) $r['ID'];
            $this->insertarCostos($id, $costos, $datos['costos'] ?? null, $usuario);
            $this->insertarTramos($id, $com['tramos'], $usuario);
        });

        return ['id' => $id, 'costos' => $costos, 'cancelacion' => $com] + $v;
    }

    /**
     * Edicion de un prestamo (tambien el paso de simulado a vigente). Los
     * costos y la comision, si vienen, reemplazan los del prestamo como juego.
     *
     * @param array $actual El prestamo como esta guardado (de prestamos())
     */
    public function editarPrestamo($id, $datos, $cuentas, $actual, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();

        if ($actual === null) {
            throw new InvalidArgumentException('Ese préstamo ya no existe. Volvé a cargar la pantalla.');
        }

        if ($actual['prestamo']['estado'] === PrestamosCalculo::VIGENTE
            && ($datos['estado'] ?? PrestamosCalculo::VIGENTE) === PrestamosCalculo::SIMULADO) {
            throw new InvalidArgumentException('Un préstamo vigente no vuelve a simulado.');
        }

        $v = self::validarPrestamo($datos + ['estado' => $actual['prestamo']['estado']], $cuentas);
        $costos = array_key_exists('costos', $datos) && is_array($datos['costos'])
            ? array_map([self::class, 'validarCosto'], $datos['costos']) : null;
        $com = array_key_exists('cancelacion', $datos) && is_array($datos['cancelacion'])
            ? self::validarComision($datos['cancelacion']) : $actual['prestamo']['cancelacion'];

        $this->enTransaccion(function () use ($id, $v, $costos, $com, $usuario, $datos) {
            $stmt = $this->ejecutar("UPDATE dbo." . self::T_PRESTAMO . " SET
                    NOMBRE = ?, CUENTA_CLAVE = ?, CUENTA_NOMBRE = ?, MONEDA = ?, CAPITAL = ?, FECHA_DESEMBOLSO = ?, TIPO_TASA = ?,
                    TNA = ?, REFERENCIA = ?, SPREAD = ?, CRITERIO_TASA = ?, SISTEMA = ?, CUOTAS = ?, FREC_CAPITAL = ?,
                    FREC_INTERESES = ?, FECHA_PRIMERA = ?, GRACIA = ?, FORMA_PAGO = ?, CANCEL_MODO = ?, CANCEL_PCT_TOTAL = ?,
                    CANCEL_PCT_PARCIAL = ?, CANCEL_IVA = ?, ESTADO = ?, OBSERVACION = ?, " . Auditoria::SET_MODIF . "
                 WHERE ID = ? AND VIGENTE = 1",
                array_merge(self::paramsPrestamo($v), [$com['modo'], $com['pct_total'], $com['pct_parcial'], !empty($com['iva']) ? 1 : 0,
                    $v['estado'], $v['observacion'], $usuario, $id]), 'Error al guardar el préstamo');

            if ((int) sqlsrv_rows_affected($stmt) === 0) {
                throw new InvalidArgumentException('Ese préstamo ya no existe. Volvé a cargar la pantalla.');
            }

            if ($costos !== null) {
                $this->darDeBaja(self::T_COSTO, 'ID_PRESTAMO = ? AND VIGENTE = 1', [$id], $usuario);
                $this->insertarCostos($id, $costos, $datos['costos'], $usuario);
            }

            if (array_key_exists('cancelacion', $datos)) {
                $this->darDeBaja(self::T_TRAMO, 'ID_PRESTAMO = ? AND VIGENTE = 1', [$id], $usuario);
                $this->insertarTramos($id, $com['tramos'], $usuario);
            }
        });

        return ['id' => (int) $id] + $v;
    }

    /** Baja logica de un simulado. Un vigente no se elimina: se cancela. */
    public function eliminarSimulado($id, $actual, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();

        if ($actual === null) {
            throw new InvalidArgumentException('Ese préstamo ya no existe.');
        }

        if ($actual['prestamo']['estado'] !== PrestamosCalculo::SIMULADO) {
            throw new InvalidArgumentException('Un préstamo vigente no se elimina: se cancela.');
        }

        $n = 0;

        $this->enTransaccion(function () use ($id, $usuario, &$n) {
            $n = $this->darDeBaja(self::T_PRESTAMO, 'ID = ? AND VIGENTE = 1 AND ESTADO = ?', [(int) $id, PrestamosCalculo::SIMULADO], $usuario);
        });

        return $n > 0;
    }

    /** Una correccion de cuota (pisa la vigente de esa cuota y concepto). */
    public function guardarAjuste($id, $cuota, $concepto, $importe, $actual, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();

        if ($actual === null) {
            throw new InvalidArgumentException('Ese préstamo ya no existe.');
        }

        $n = self::entero($cuota, 1, 10000, 'La cuota', false);
        $v = self::validarAjuste($concepto, $importe, array_column($actual['costos'], 'id'));

        $this->enTransaccion(function () use ($id, $n, $v, $usuario) {
            $this->darDeBaja(self::T_AJUSTE, 'ID_PRESTAMO = ? AND CUOTA = ? AND CONCEPTO = ? AND VIGENTE = 1', [(int) $id, $n, $v['concepto']], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_AJUSTE . " (ID_PRESTAMO, CUOTA, CONCEPTO, IMPORTE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                             VALUES (?, ?, ?, ?, 1, ?, ?)", [(int) $id, $n, $v['concepto'], $v['importe'], $usuario, $usuario],
                'Error al guardar la corrección');
        });

        return ['cuota' => $n] + $v;
    }

    /** Deshacer una correccion: baja logica. Sin concepto, todas las de la cuota. */
    public function deshacerAjuste($id, $cuota, $concepto, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $n = self::entero($cuota, 1, 10000, 'La cuota', false);
        $c = strtoupper(trim((string) $concepto));
        $tocadas = 0;

        $this->enTransaccion(function () use ($id, $n, $c, $usuario, &$tocadas) {
            $tocadas = ($c === '')
                ? $this->darDeBaja(self::T_AJUSTE, 'ID_PRESTAMO = ? AND CUOTA = ? AND VIGENTE = 1', [(int) $id, $n], $usuario)
                : $this->darDeBaja(self::T_AJUSTE, 'ID_PRESTAMO = ? AND CUOTA = ? AND CONCEPTO = ? AND VIGENTE = 1', [(int) $id, $n, $c], $usuario);
        });

        return $tocadas;
    }

    /** Registra una cancelacion ya validada (validarCancelacion()). */
    public function registrarCancelacion($id, $v, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $nuevo = null;

        $this->enTransaccion(function () use ($id, $v, $usuario, &$nuevo) {
            $r = $this->fila("INSERT INTO dbo." . self::T_CANCELACION . " (ID_PRESTAMO, FECHA, TIPO, MONTO, MODO, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                              OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [(int) $id, $v['fecha'], $v['tipo'], $v['monto'], $v['modo'], $v['observacion'], $usuario, $usuario],
                'Error al registrar la cancelación');
            $nuevo = (int) $r['ID'];
        });

        return $nuevo;
    }

    /** Deshace una cancelacion mientras su fecha no paso. */
    public function deshacerCancelacion($idCancelacion, $hoy, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $i = self::entero($idCancelacion, 1, PHP_INT_MAX, 'La cancelación', false);
        $f = $this->fila("SELECT FECHA FROM dbo." . self::T_CANCELACION . " WHERE ID = ? AND VIGENTE = 1", [$i], 'Error al leer la cancelación');

        if ($f === null) {
            throw new InvalidArgumentException('Esa cancelación ya no está vigente.');
        }

        if (self::dia($f['FECHA']) < $hoy) {
            throw new InvalidArgumentException('Esa cancelación ya ocurrió (' . PrestamosCalculo::corta(self::dia($f['FECHA'])) . '): no se puede deshacer.');
        }

        $n = 0;

        $this->enTransaccion(function () use ($i, $usuario, &$n) {
            $n = $this->darDeBaja(self::T_CANCELACION, 'ID = ? AND VIGENTE = 1', [$i], $usuario);
        });

        return $n > 0;
    }

    /* ====================================================================
       AUXILIARES
       ==================================================================== */

    private static function paramsPrestamo($v) {
        return [$v['nombre'], $v['cuenta_clave'], $v['cuenta_nombre'], $v['moneda'], $v['capital'], $v['fecha_desembolso'],
                $v['tipo_tasa'], $v['tna'], $v['referencia'], $v['spread'], $v['criterio_tasa'], $v['sistema'], $v['cuotas'],
                $v['frec_capital'], $v['frec_intereses'], $v['fecha_primera'], $v['gracia'], $v['forma_pago']];
    }

    private function insertarCostos($id, $costos, $crudos, $usuario) {
        foreach ($costos as $i => $c) {
            $param = $c['id_param'] ?? (is_array($crudos) && isset($crudos[$i]['id_param']) && $crudos[$i]['id_param'] !== ''
                ? (int) $crudos[$i]['id_param'] : null);
            $this->ejecutar("INSERT INTO dbo." . self::T_COSTO . " (ID_PRESTAMO, ID_COSTO_PARAM, NOMBRE, PCT, BASE, MOMENTO, LLEVA_IVA, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [(int) $id, $param, $c['nombre'], $c['pct'], $c['base'], $c['momento'], !empty($c['iva']) ? 1 : 0, $usuario, $usuario],
                'Error al guardar los costos del préstamo');
        }
    }

    private function insertarTramos($id, $tramos, $usuario) {
        foreach ($tramos as $t) {
            $this->ejecutar("INSERT INTO dbo." . self::T_TRAMO . " (ID_PRESTAMO, DESDE_PCT, PCT_TOTAL, PCT_PARCIAL, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                             VALUES (?, ?, ?, ?, 1, ?, ?)", [(int) $id, $t['desde'], $t['pct_total'], $t['pct_parcial'], $usuario, $usuario],
                'Error al guardar los tramos del préstamo');
        }
    }

    private function exigirTablas() {
        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException(self::avisoSinTabla());
        }
    }

    private function darDeBaja($tabla, $where, $params, $usuario) {
        $stmt = $this->ejecutar("UPDATE dbo." . $tabla . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
            . " WHERE " . $where, array_merge([$usuario, $usuario], $params), 'Error al dar de baja');
        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return (int) $filas;
    }

    private function enTransaccion($fn) {
        $cid = $this->conectar();

        if (!sqlsrv_begin_transaction($cid)) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $fn();

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }
    }

    private function ejecutar($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        return $stmt;
    }

    private function fila($sql, $params, $contexto) {
        $f = $this->filas($sql, $params, $contexto);

        return $f[0] ?? null;
    }

    private function filas($sql, $params, $contexto) {
        $stmt = $this->ejecutar($sql, $params, $contexto);
        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    private static function num($v) {
        return ($v === null) ? null : (float) $v;
    }

    private static function dia($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d') : (($v === null || $v === '') ? null : substr((string) $v, 0, 10));
    }

    private static function momento($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : (($v === null || $v === '') ? null : substr((string) $v, 0, 16));
    }

    /** UNA CONEXION POR INSTANCIA: la transaccion y sus INSERT van por el mismo handle. */
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

    private function errorSql($contexto) {
        foreach (sqlsrv_errors() ?: [] as $e) {
            if (strpos($e['message'], 'UX_RO_T_CF_PR') !== false) {
                return 'Eso ya se cargó desde otra pantalla. Volvé a cargar la pantalla. No se guardó nada.';
            }
        }

        $msg = $contexto . ': ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
