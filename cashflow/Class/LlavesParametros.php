<?php

require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Planilla.php';

/**
 * LlavesParametros
 * Parametros -> Llaves y Renovaciones: lo que el cashflow necesita para
 * proyectar llaves, renovaciones, obras y aperturas y la app de alquileres no
 * tiene. Las tablas son las de sql/cashflow_llaves.sql (script 66).
 *
 * UNA FILA VIGENTE POR CLAVE, CON HISTORIAL, como Parametros -> Alquileres.
 * Cambiar un dato es dar de baja la fila vigente y dar de alta otra, en una
 * transaccion; las obras, aperturas, compromisos y "ya pagada" no se editan:
 * baja y alta, para que el historial explique los meses ya proyectados.
 *
 * LA VALIDACION MANDA ACA. Los validadores son estaticos y puros, y la pantalla
 * solo explica y bloquea botones.
 *
 * UN DATO QUE FALTA ES NULL. Vacio en el % de un operador es "usa el general";
 * vacio en el escribano o en el % de impuestos es "sin dato" (avisa), y solo
 * un 0 cargado es 0. Por eso los validadores distinguen null de 0 en todos
 * lados.
 *
 * QUE SIGNIFICA CADA COSA LO DECIDE LlavesCalculo: aca solo se guarda y se
 * valida la forma.
 */
class LlavesParametros {

    const SCRIPT = 'sql/cashflow_llaves.sql';

    const T_GENERAL = 'RO_T_CASHFLOW_LLAVES_GENERAL';
    const T_OPERADOR = 'RO_T_CASHFLOW_LLAVES_OPERADOR';
    const T_LOCAL = 'RO_T_CASHFLOW_LLAVES_LOCAL';
    const T_PAGADA = 'RO_T_CASHFLOW_LLAVES_PAGADA';
    const T_OBRA = 'RO_T_CASHFLOW_LLAVES_OBRA';
    const T_APERTURA = 'RO_T_CASHFLOW_LLAVES_APERTURA';
    const T_COMPROMISO = 'RO_T_CASHFLOW_LLAVES_COMPROMISO';

    const TABLAS = [self::T_GENERAL, self::T_OPERADOR, self::T_LOCAL, self::T_PAGADA, self::T_OBRA,
                    self::T_APERTURA, self::T_COMPROMISO];

    /** Las claves de Generales, con su nombre y su rango. Es el CHECK de la tabla. */
    const GENERALES = [
        'PCT_LLAVE' => ['nombre' => '% de llave sobre el alquiler', 'tipo' => 'pct'],
        'CUOTAS' => ['nombre' => 'Cuotas de la llave', 'tipo' => 'entero', 'min' => 1, 'max' => 60],
        'DIA_PAGO' => ['nombre' => 'Día de pago de la obra y la apertura', 'tipo' => 'entero', 'min' => 1, 'max' => 28],
        'CORTE_CHICO' => ['nombre' => 'Hasta cuántos m² es chico', 'tipo' => 'importe'],
        'CORTE_MEDIANO' => ['nombre' => 'Hasta cuántos m² es mediano', 'tipo' => 'importe'],
        'USD_OBRA_CHICO' => ['nombre' => 'Obra de imagen, chico (U$S)', 'tipo' => 'importe'],
        'USD_OBRA_MEDIANO' => ['nombre' => 'Obra de imagen, mediano (U$S)', 'tipo' => 'importe'],
        'USD_OBRA_GRANDE' => ['nombre' => 'Obra de imagen, grande (U$S)', 'tipo' => 'importe'],
        'USD_APERTURA_CHICO' => ['nombre' => 'Apertura, chico (U$S)', 'tipo' => 'importe'],
        'USD_APERTURA_MEDIANO' => ['nombre' => 'Apertura, mediano (U$S)', 'tipo' => 'importe'],
        'USD_APERTURA_GRANDE' => ['nombre' => 'Apertura, grande (U$S)', 'tipo' => 'importe']
    ];

    const SEGMENTOS = ['CHICO' => 'Chico', 'MEDIANO' => 'Mediano', 'GRANDE' => 'Grande'];

    /** Lo que se marca como "ya pagada": la llave o los otros gastos de una renovacion */
    const CONCEPTOS_PAGADA = ['LLAVE' => 'Llave', 'OTROS' => 'Otros gastos'];

    const FORMAS = ['ECHEQ' => 'Echeq', 'TRANSFERENCIA' => 'Transferencia', 'VALORES' => 'Valores', 'AL_DIA' => 'Al día'];

    const TIPO_ALQUILERES = 'ALQUILERES';
    const TIPO_PROVEEDOR = 'PROVEEDOR';

    /** Topes de las columnas */
    const LARGO_TEXTO = 300;
    const LARGO_CONCEPTO = 100;
    const LARGO_COMPROBANTE = 40;
    const MAX_CUOTAS_SERIE = 60;
    const CORRIMIENTO_MAX = 24;

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
     * Un numero >= 0 con hasta dos decimales, con coma o punto decimal y sin
     * separador de miles. Vacio = null si se permite.
     *
     * @return float|null
     * @throws InvalidArgumentException
     */
    public static function numero($valor, $nombre, $vacioPermitido = true, $max = null) {
        if ($valor === null || trim((string) $valor) === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        $t = str_replace(',', '.', trim((string) $valor));

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
            throw new InvalidArgumentException("$nombre tiene que ser un número mayor o igual a 0, con hasta dos decimales.");
        }

        $v = (float) $t;

        if ($max !== null && $v > $max) {
            throw new InvalidArgumentException("$nombre no puede ser mayor que " . $max . '.');
        }

        return $v;
    }

    /**
     * Un entero dentro de un rango. Vacio = null si se permite.
     *
     * @return int|null
     * @throws InvalidArgumentException
     */
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

    /** 'YYYY-MM' valido, o null si vino vacio y se permite */
    public static function mes($valor, $nombre, $vacioPermitido = true) {
        $t = trim((string) $valor);

        if ($t === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        if (!preg_match('/^(\d{4})-(\d{2})$/', $t, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new InvalidArgumentException("$nombre tiene que ser un mes (año y mes).");
        }

        return $t;
    }

    /** 'Y-m-d' valido */
    public static function fecha($valor, $nombre) {
        $t = substr(trim((string) $valor), 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $t);

        if ($d === false || $d->format('Y-m-d') !== $t) {
            throw new InvalidArgumentException("Falta $nombre, o no es una fecha válida.");
        }

        return $t;
    }

    /** Un texto recortado; null si queda vacio y se permite */
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

    /**
     * Un valor de Generales. Null = vaciarlo (baja de la fila vigente).
     *
     * LOS DOS CORTES SE VALIDAN JUNTOS: el de mediano tiene que ser mayor que
     * el de chico, o un local no tendria segmento. Por eso recibe el otro corte
     * vigente.
     *
     * @param string $clave
     * @param mixed $valor
     * @param array $vigentes clave => valor (para los cortes)
     * @return float|null
     * @throws InvalidArgumentException
     */
    public static function validarGeneral($clave, $valor, $vigentes = []) {
        if (!isset(self::GENERALES[$clave])) {
            throw new InvalidArgumentException('Ese parámetro no existe.');
        }

        $def = self::GENERALES[$clave];
        /* Los tres defaults no se vacian: sin ellos no hay regla. */
        $obligatorio = in_array($clave, ['PCT_LLAVE', 'CUOTAS', 'DIA_PAGO'], true);

        if ($def['tipo'] === 'entero') {
            $v = self::entero($valor, $def['min'], $def['max'], $def['nombre'], !$obligatorio);
            $v = ($v === null) ? null : (float) $v;
        } else {
            $v = self::numero($valor, $def['nombre'], !$obligatorio, ($def['tipo'] === 'pct') ? 1000 : null);
        }

        $chico = ($clave === 'CORTE_CHICO') ? $v : ($vigentes['CORTE_CHICO'] ?? null);
        $mediano = ($clave === 'CORTE_MEDIANO') ? $v : ($vigentes['CORTE_MEDIANO'] ?? null);

        if (in_array($clave, ['CORTE_CHICO', 'CORTE_MEDIANO'], true) && $chico !== null && $mediano !== null
            && $mediano <= $chico) {
            throw new InvalidArgumentException('El corte de mediano tiene que ser mayor que el de chico ('
                . self::m2($chico) . ' m²): si no, ningún local sería mediano.');
        }

        return $v;
    }

    /**
     * Los datos de un operador, normalizados. Vacio = null en todos.
     *
     * @param array $d
     * @return array ['pct_llave', 'pct_otros', 'escribano', 'pct_impuestos', 'cuotas', 'corrimiento']
     */
    public static function validarOperador($d) {
        return [
            'pct_llave' => self::numero($d['pct_llave'] ?? null, 'El % de llave', true, 1000),
            'pct_otros' => self::numero($d['pct_otros'] ?? null, 'El % de otros gastos', true, 1000),
            'escribano' => self::numero($d['escribano'] ?? null, 'El escribano y otros'),
            'pct_impuestos' => self::numero($d['pct_impuestos'] ?? null, 'El % de impuestos', true, 1000),
            'cuotas' => self::entero($d['cuotas'] ?? null, 1, 60, 'Las cuotas'),
            'corrimiento' => self::entero($d['corrimiento'] ?? null, -self::CORRIMIENTO_MAX, self::CORRIMIENTO_MAX,
                'El corrimiento')
        ];
    }

    /**
     * Los datos de un local para la renovacion de un contrato. No renueva
     * pide motivo; con no renueva, lo demas no importa pero se guarda igual.
     *
     * @param array $d
     * @return array
     */
    public static function validarLocal($d) {
        $no = !empty($d['no_renueva']) && $d['no_renueva'] !== '0' && $d['no_renueva'] !== 'false';
        $motivo = self::texto($d['motivo_no_renueva'] ?? null, self::LARGO_TEXTO, 'El motivo de que no renueva', !$no);

        return [
            'no_renueva' => $no,
            'motivo_no_renueva' => $no ? $motivo : null,
            'llave_manual' => self::numero($d['llave_manual'] ?? null, 'El monto de la llave'),
            'pct_llave' => self::numero($d['pct_llave'] ?? null, 'El % de llave', true, 1000),
            'meses' => self::entero($d['meses'] ?? null, 1, 240, 'Los meses del contrato nuevo'),
            'cuotas' => self::entero($d['cuotas'] ?? null, 1, 60, 'Las cuotas'),
            'mes_primera_cuota' => self::mes($d['mes_primera_cuota'] ?? null, 'El mes de la primera cuota')
        ];
    }

    /**
     * "Ya pagada": la llave o los otros gastos de la renovacion de un contrato,
     * que ya salieron de caja. La fecha no puede ser futura: lo que todavia no
     * se pago no esta pagado.
     *
     * @param array $d
     * @param string $hoy 'Y-m-d'
     * @return array
     */
    public static function validarPagada($d, $hoy) {
        $concepto = strtoupper(trim((string) ($d['concepto'] ?? '')));

        if (!isset(self::CONCEPTOS_PAGADA[$concepto])) {
            throw new InvalidArgumentException('Elegí si lo que ya se pagó es la llave o los otros gastos.');
        }

        $fecha = self::fecha($d['fecha'] ?? '', 'la fecha del pago');

        if ($fecha > $hoy) {
            throw new InvalidArgumentException('La fecha del pago no puede ser posterior a hoy: lo que todavía no '
                . 'se pagó sigue proyectado.');
        }

        $monto = self::numero($d['monto'] ?? null, 'El monto pagado', false);

        if ($monto <= 0) {
            throw new InvalidArgumentException('El monto pagado tiene que ser mayor que 0.');
        }

        return [
            'concepto' => $concepto,
            'fecha' => $fecha,
            'monto' => $monto,
            'comprobante' => self::texto($d['comprobante'] ?? null, self::LARGO_COMPROBANTE, 'El comprobante'),
            'observacion' => self::texto($d['observacion'] ?? null, self::LARGO_TEXTO, 'La observación')
        ];
    }

    /**
     * "Ya pagada" de UNO O VARIOS conceptos de la misma renovacion: la fecha,
     * el comprobante y la observacion son comunes, y cada concepto lleva su
     * monto. Lo normal es marcar la renovacion entera (la llave y los otros
     * gastos): marcar solo la llave deja los otros gastos proyectados sin que
     * nadie lo note (Abasto, 23/11/2026). Por eso la pantalla ofrece los dos
     * tildados, y aca se validan juntos.
     *
     * Acepta tambien el formato de un solo concepto ('concepto' y 'monto').
     *
     * @param array $d ['conceptos' => [['concepto', 'monto']], 'fecha', 'comprobante', 'observacion']
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha', 'comprobante', 'observacion', 'conceptos' => [['concepto', 'monto']]]
     */
    public static function validarPagadas($d, $hoy) {
        $lista = (isset($d['conceptos']) && is_array($d['conceptos'])) ? $d['conceptos']
            : [['concepto' => $d['concepto'] ?? '', 'monto' => $d['monto'] ?? null]];
        $out = null;
        $conceptos = [];

        foreach ($lista as $c) {
            $v = self::validarPagada(['concepto' => $c['concepto'] ?? '', 'monto' => $c['monto'] ?? null] + $d, $hoy);

            if (isset($conceptos[$v['concepto']])) {
                throw new InvalidArgumentException('Ese concepto vino dos veces.');
            }

            $conceptos[$v['concepto']] = ['concepto' => $v['concepto'], 'monto' => $v['monto']];
            $out = $out ?? ['fecha' => $v['fecha'], 'comprobante' => $v['comprobante'], 'observacion' => $v['observacion']];
        }

        if (empty($conceptos)) {
            throw new InvalidArgumentException('Elegí qué se pagó: la llave, los otros gastos o los dos.');
        }

        return $out + ['conceptos' => array_values($conceptos)];
    }

    /**
     * Una obra de imagen. El segmento es opcional (vacio = el de los m2); el
     * costo propio tambien (vacio = el del segmento).
     *
     * @param array $d
     * @param array $locales NRO_SUCURS => ... los locales de Alquileres
     * @return array
     */
    public static function validarObra($d, $locales) {
        $nro = self::entero($d['nro'] ?? null, 1, 99999, 'El local', false);

        if (!isset($locales[$nro])) {
            throw new InvalidArgumentException('Ese local no está en la app de alquileres.');
        }

        return [
            'nro' => $nro,
            'mes' => self::mes($d['mes'] ?? null, 'El mes estimado', false),
            'segmento' => self::segmento($d['segmento'] ?? null, true),
            'costo_usd' => self::costo($d['costo_usd'] ?? null),
            'observacion' => self::texto($d['observacion'] ?? null, self::LARGO_TEXTO, 'La observación')
        ];
    }

    /**
     * Una apertura. El segmento es obligatorio: el local todavia no tiene m2.
     *
     * @param array $d
     * @param array $locales NRO_SUCURS => ... para validar el vinculo, si viene
     * @return array
     */
    public static function validarApertura($d, $locales) {
        $nro = self::entero($d['nro'] ?? null, 1, 99999, 'El número de sucursal');

        if ($nro !== null && !isset($locales[$nro])) {
            throw new InvalidArgumentException('Ese número de sucursal no está en la app de alquileres: dejalo vacío '
                . 'hasta que exista.');
        }

        return [
            'nombre' => self::texto($d['nombre'] ?? null, self::LARGO_CONCEPTO, 'El nombre', false),
            'nro' => $nro,
            'mes' => self::mes($d['mes'] ?? null, 'El mes estimado', false),
            'segmento' => self::segmento($d['segmento'] ?? null, false),
            'costo_usd' => self::costo($d['costo_usd'] ?? null),
            'observacion' => self::texto($d['observacion'] ?? null, self::LARGO_TEXTO, 'La observación')
        ];
    }

    /**
     * Un compromiso a mano, suelto o en serie: N cuotas mensuales del mismo
     * importe desde una fecha, el mismo dia de cada mes (el ultimo del mes si
     * ese dia no existe: el 31 de enero sigue el 28 de febrero).
     *
     * EL OPERADOR SE VALIDA CONTRA SU UNIVERSO: una razon social dada de alta
     * en Alquileres o un proveedor del maestro de Proveedores Locales.
     *
     * @param array $d
     * @param array $razones COD_PROVEE => ... las dadas de alta en Alquileres
     * @param array $proveedores COD_PROVEE => ... el maestro de Proveedores Locales
     * @param array $locales NRO_SUCURS => ...
     * @return array ['tipo', 'cod_provee', 'nro', 'concepto', 'forma', 'observacion', 'cuotas' => [['fecha', 'monto', 'nro']]]
     */
    public static function validarCompromiso($d, $razones, $proveedores, $locales) {
        $tipo = strtoupper(trim((string) ($d['tipo'] ?? '')));
        $cod = Planilla::codigo($d['cod_provee'] ?? '');

        if ($tipo === self::TIPO_ALQUILERES) {
            if (!isset($razones[$cod])) {
                throw new InvalidArgumentException('Esa razón social no está dada de alta en Parámetros › Alquileres.');
            }
        } elseif ($tipo === self::TIPO_PROVEEDOR) {
            if (!isset($proveedores[$cod])) {
                throw new InvalidArgumentException('Ese proveedor no está en el maestro de Proveedores Locales.');
            }
        } else {
            throw new InvalidArgumentException('Elegí el operador: una razón social de Alquileres o un proveedor.');
        }

        $nro = self::entero($d['nro'] ?? null, 1, 99999, 'El local');

        if ($nro !== null && !isset($locales[$nro])) {
            throw new InvalidArgumentException('Ese local no está en la app de alquileres.');
        }

        $forma = strtoupper(trim((string) ($d['forma'] ?? '')));

        if ($forma !== '' && !isset(self::FORMAS[$forma])) {
            throw new InvalidArgumentException('Esa forma de pago no existe.');
        }

        $fecha = self::fecha($d['fecha'] ?? '', 'la fecha');
        $monto = self::numero($d['monto'] ?? null, 'El monto', false);

        if ($monto <= 0) {
            throw new InvalidArgumentException('El monto tiene que ser mayor que 0.');
        }

        $n = self::entero($d['cuotas'] ?? null, 1, self::MAX_CUOTAS_SERIE, 'La cantidad de cuotas') ?? 1;

        return [
            'tipo' => $tipo,
            'cod_provee' => $cod,
            'nro' => $nro,
            'concepto' => self::texto($d['concepto'] ?? null, self::LARGO_CONCEPTO, 'El concepto', false),
            'forma' => ($forma === '') ? null : $forma,
            'observacion' => self::texto($d['observacion'] ?? null, self::LARGO_TEXTO, 'La observación'),
            'cuotas' => self::serie($fecha, $monto, $n)
        ];
    }

    /**
     * N cuotas mensuales del mismo importe desde una fecha, el mismo dia de
     * cada mes o el ultimo si ese dia no existe. Pura.
     *
     * @return array [['nro', 'fecha', 'monto']]
     */
    public static function serie($fecha, $monto, $n) {
        $out = [];
        $dia = (int) substr($fecha, 8, 2);
        $base = substr($fecha, 0, 7) . '-01';

        for ($i = 0; $i < $n; $i++) {
            $mes = date('Y-m', strtotime($base . ' +' . $i . ' month'));
            $ult = (int) date('t', strtotime($mes . '-01'));
            $out[] = ['nro' => $i + 1, 'fecha' => $mes . '-' . sprintf('%02d', min($dia, $ult)), 'monto' => $monto];
        }

        return $out;
    }

    private static function segmento($v, $vacioPermitido) {
        $s = strtoupper(trim((string) $v));

        if ($s === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException('Falta el segmento.');
        }

        if (!isset(self::SEGMENTOS[$s])) {
            throw new InvalidArgumentException('El segmento es chico, mediano o grande.');
        }

        return $s;
    }

    private static function costo($v) {
        $c = self::numero($v, 'El costo en U$S');

        if ($c !== null && $c <= 0) {
            throw new InvalidArgumentException('El costo en U$S tiene que ser mayor que 0, o quedar vacío para usar el '
                . 'del segmento.');
        }

        return $c;
    }

    private static function m2($n) {
        return number_format((float) $n, 2, ',', '.');
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return string[] Las tablas del script que faltan */
    public function tablasFaltantes() {
        $faltan = [];

        foreach (self::TABLAS as $t) {
            $r = $this->fila("SELECT OBJECT_ID(?, 'U') AS ID", ['dbo.' . $t], 'Error al verificar las tablas de Llaves');

            if ($r === null || $r['ID'] === null) {
                $faltan[] = $t;
            }
        }

        return $faltan;
    }

    /** @return bool Si el script 66 ya se corrio. Nunca lanza. */
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

    public function avisoSinTabla() {
        return 'Faltan las tablas de Parámetros › Llaves y Renovaciones: hay que correr ' . self::SCRIPT
            . ' contra la base central. Hasta entonces no se estima ninguna llave, obra ni apertura, y no se '
            . 'cargan compromisos; los contratos de la app se ven igual.';
    }

    /** @return array clave => float (solo las que tienen fila vigente), + '_meta' => clave => [usuario, fecha] */
    public function generales() {
        $out = [];
        $meta = [];

        foreach ($this->filas("SELECT CLAVE, VALOR, USUARIO_ALTA, FECHA_ALTA FROM dbo." . self::T_GENERAL
                              . " WHERE VIGENTE = 1", [], 'Error al leer los generales de Llaves') as $r) {
            $out[$r['CLAVE']] = (float) $r['VALOR'];
            $meta[$r['CLAVE']] = ['usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])];
        }

        return $out + ['_meta' => $meta];
    }

    /** @return array COD_PROVEE => ['pct_llave', 'pct_otros', 'escribano', 'pct_impuestos', 'cuotas', 'corrimiento', 'usuario', 'fecha'] */
    public function operadores() {
        $out = [];

        foreach ($this->filas("SELECT * FROM dbo." . self::T_OPERADOR . " WHERE VIGENTE = 1", [],
                              'Error al leer los operadores de Llaves') as $r) {
            $out[Planilla::codigo($r['COD_PROVEE'])] = [
                'pct_llave' => self::num($r['PCT_LLAVE']), 'pct_otros' => self::num($r['PCT_OTROS']),
                'escribano' => self::num($r['ESCRIBANO']), 'pct_impuestos' => self::num($r['PCT_IMPUESTOS']),
                'cuotas' => ($r['CUOTAS'] === null) ? null : (int) $r['CUOTAS'],
                'corrimiento' => ($r['CORRIMIENTO'] === null) ? null : (int) $r['CORRIMIENTO'],
                'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])
            ];
        }

        return $out;
    }

    /** @return array 'NRO|ID_CONTRATO' => fila normalizada */
    public function locales() {
        $out = [];

        foreach ($this->filas("SELECT * FROM dbo." . self::T_LOCAL . " WHERE VIGENTE = 1", [],
                              'Error al leer los locales de Llaves') as $r) {
            $out[(int) $r['NRO_SUCURS'] . '|' . (int) $r['ID_CONTRATO']] = [
                'nro' => (int) $r['NRO_SUCURS'], 'id_contrato' => (int) $r['ID_CONTRATO'],
                'no_renueva' => (bool) $r['NO_RENUEVA'], 'motivo_no_renueva' => $r['MOTIVO_NO_RENUEVA'],
                'llave_manual' => self::num($r['LLAVE_MANUAL']), 'pct_llave' => self::num($r['PCT_LLAVE']),
                'meses' => ($r['MESES'] === null) ? null : (int) $r['MESES'],
                'cuotas' => ($r['CUOTAS'] === null) ? null : (int) $r['CUOTAS'],
                'mes_primera_cuota' => ($r['MES_PRIMERA_CUOTA'] === null) ? null : trim($r['MES_PRIMERA_CUOTA']),
                'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])
            ];
        }

        return $out;
    }

    /** @return array Lista de "ya pagada" vigentes */
    public function pagadas() {
        return array_map(function ($r) {
            return ['id' => (int) $r['ID'], 'nro' => (int) $r['NRO_SUCURS'], 'id_contrato_ant' => (int) $r['ID_CONTRATO_ANT'],
                    'concepto' => trim($r['CONCEPTO']), 'fecha' => self::dia($r['FECHA']), 'monto' => (float) $r['MONTO'],
                    'comprobante' => $r['COMPROBANTE'], 'observacion' => $r['OBSERVACION'],
                    'usuario' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA'])];
        }, $this->filas("SELECT * FROM dbo." . self::T_PAGADA . " WHERE VIGENTE = 1 ORDER BY NRO_SUCURS, FECHA", [],
                        'Error al leer las renovaciones ya pagadas'));
    }

    /** @return array Lista de obras vigentes */
    public function obras() {
        return array_map(function ($r) {
            return ['id' => (int) $r['ID'], 'nro' => (int) $r['NRO_SUCURS'], 'mes' => trim($r['MES']),
                    'segmento' => ($r['SEGMENTO'] === null) ? null : trim($r['SEGMENTO']),
                    'costo_usd' => self::num($r['COSTO_USD']), 'observacion' => $r['OBSERVACION'],
                    'usuario' => $r['USUARIO_ALTA'], 'fecha' => self::momento($r['FECHA_ALTA'])];
        }, $this->filas("SELECT * FROM dbo." . self::T_OBRA . " WHERE VIGENTE = 1 ORDER BY MES, NRO_SUCURS", [],
                        'Error al leer las obras de imagen'));
    }

    /** @return array Lista de aperturas vigentes */
    public function aperturas() {
        return array_map(function ($r) {
            return ['id' => (int) $r['ID'], 'nombre' => $r['NOMBRE'],
                    'nro' => ($r['NRO_SUCURS'] === null) ? null : (int) $r['NRO_SUCURS'], 'mes' => trim($r['MES']),
                    'segmento' => trim($r['SEGMENTO']), 'costo_usd' => self::num($r['COSTO_USD']),
                    'observacion' => $r['OBSERVACION'], 'usuario' => $r['USUARIO_ALTA'],
                    'fecha' => self::momento($r['FECHA_ALTA'])];
        }, $this->filas("SELECT * FROM dbo." . self::T_APERTURA . " WHERE VIGENTE = 1 ORDER BY MES, NOMBRE", [],
                        'Error al leer las aperturas'));
    }

    /** @return array Lista de cuotas de compromisos vigentes */
    public function compromisos() {
        return array_map(function ($r) {
            return ['id' => (int) $r['ID'], 'tipo' => trim($r['TIPO_OPERADOR']), 'cod_provee' => Planilla::codigo($r['COD_PROVEE']),
                    'nro' => ($r['NRO_SUCURS'] === null) ? null : (int) $r['NRO_SUCURS'], 'concepto' => $r['CONCEPTO'],
                    'fecha' => self::dia($r['FECHA']), 'monto' => (float) $r['MONTO'],
                    'forma' => ($r['FORMA_PAGO'] === null) ? null : trim($r['FORMA_PAGO']),
                    'observacion' => $r['OBSERVACION'],
                    'id_serie' => ($r['ID_SERIE'] === null) ? null : (int) $r['ID_SERIE'],
                    'cuota_nro' => ($r['CUOTA_NRO'] === null) ? null : (int) $r['CUOTA_NRO'],
                    'cuotas' => ($r['CUOTAS'] === null) ? null : (int) $r['CUOTAS'],
                    'usuario' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA'])];
        }, $this->filas("SELECT * FROM dbo." . self::T_COMPROMISO . " WHERE VIGENTE = 1 ORDER BY FECHA, ID", [],
                        'Error al leer los compromisos'));
    }

    /**
     * Todo lo de las tablas, de una vez, como lo pide LlavesCalculo. Sin el
     * script, todo vacio y 'tablas_ok' en false.
     *
     * @return array
     */
    public function todo() {
        if (!$this->tablaCreada()) {
            return ['tablas_ok' => false, 'generales' => ['_meta' => []], 'operadores' => [], 'locales' => [],
                    'pagadas' => [], 'obras' => [], 'aperturas' => [], 'compromisos' => []];
        }

        return ['tablas_ok' => true, 'generales' => $this->generales(), 'operadores' => $this->operadores(),
                'locales' => $this->locales(), 'pagadas' => $this->pagadas(), 'obras' => $this->obras(),
                'aperturas' => $this->aperturas(), 'compromisos' => $this->compromisos()];
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Un valor de Generales. Null lo vacia (solo los que no son obligatorios).
     *
     * @return array ['clave', 'valor']
     */
    public function guardarGeneral($clave, $valor, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $vig = $this->generales();
        $v = self::validarGeneral($clave, $valor, $vig);

        $this->enTransaccion(function () use ($clave, $v, $usuario) {
            $this->darDeBaja(self::T_GENERAL, 'CLAVE = ? AND VIGENTE = 1', [$clave], $usuario);

            if ($v !== null) {
                $this->ejecutar("INSERT INTO dbo." . self::T_GENERAL . " (CLAVE, VALOR, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                                 VALUES (?, ?, 1, ?, ?)", [$clave, $v, $usuario, $usuario], 'Error al guardar el parámetro');
            }
        });

        return ['clave' => $clave, 'valor' => $v];
    }

    /**
     * Los datos de un operador. Tiene que ser una razon social dada de alta en
     * Parametros -> Alquileres: es lo que la proyeccion usa como operador.
     *
     * @param array $razones COD_PROVEE => ... las vigentes de Alquileres
     * @return array ['cod'] + validarOperador()
     */
    public function guardarOperador($cod, $datos, $razones, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $c = Planilla::codigo($cod);

        if (!isset($razones[$c])) {
            throw new InvalidArgumentException('El operador tiene que ser una razón social dada de alta en Parámetros › '
                . 'Alquileres.');
        }

        $v = self::validarOperador($datos);

        $this->enTransaccion(function () use ($c, $v, $usuario) {
            $this->darDeBaja(self::T_OPERADOR, 'COD_PROVEE = ? AND VIGENTE = 1', [$c], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_OPERADOR . "
                    (COD_PROVEE, PCT_LLAVE, PCT_OTROS, ESCRIBANO, PCT_IMPUESTOS, CUOTAS, CORRIMIENTO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$c, $v['pct_llave'], $v['pct_otros'], $v['escribano'], $v['pct_impuestos'], $v['cuotas'],
                 $v['corrimiento'], $usuario, $usuario], 'Error al guardar el operador');
        });

        return ['cod' => $c] + $v;
    }

    /**
     * Los datos de un local para la renovacion de un contrato. El contrato
     * tiene que ser de ese local (lo dice la app): se valida con los contratos
     * leidos.
     *
     * @param array $contratosDelLocal [['id', ...]] de AlquileresApp::contratosConImportes()
     * @return array ['nro', 'id_contrato'] + validarLocal()
     */
    public function guardarLocal($nro, $idContrato, $datos, $contratosDelLocal, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $n = self::entero($nro, 1, 99999, 'El local', false);
        $id = self::entero($idContrato, 1, PHP_INT_MAX, 'El contrato', false);

        if (!in_array($id, array_column(is_array($contratosDelLocal) ? $contratosDelLocal : [], 'id'), true)) {
            throw new InvalidArgumentException('Ese contrato no es de ese local en la app de alquileres: volvé a cargar '
                . 'la pantalla.');
        }

        $v = self::validarLocal($datos);

        $this->enTransaccion(function () use ($n, $id, $v, $usuario) {
            $this->darDeBaja(self::T_LOCAL, 'NRO_SUCURS = ? AND ID_CONTRATO = ? AND VIGENTE = 1', [$n, $id], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_LOCAL . "
                    (NRO_SUCURS, ID_CONTRATO, NO_RENUEVA, MOTIVO_NO_RENUEVA, LLAVE_MANUAL, PCT_LLAVE, MESES, CUOTAS,
                     MES_PRIMERA_CUOTA, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$n, $id, $v['no_renueva'] ? 1 : 0, $v['motivo_no_renueva'], $v['llave_manual'], $v['pct_llave'],
                 $v['meses'], $v['cuotas'], $v['mes_primera_cuota'], $usuario, $usuario], 'Error al guardar el local');
        });

        return ['nro' => $n, 'id_contrato' => $id] + $v;
    }

    /**
     * Marca como ya pagados uno o varios conceptos (llave, otros gastos) de la
     * renovacion del contrato $idContratoAnt, en una transaccion. Una sola
     * marca vigente por concepto: el que ya estaba marcado no se toca y se
     * informa en 'ya'; si estaban todos, no se guarda nada y se dice. Para
     * corregir una marca, se da de baja y se carga otra.
     *
     * @return array ['ids', 'marcados' => [concepto], 'ya' => [concepto]] + validarPagadas()
     */
    public function altaPagada($nro, $idContratoAnt, $datos, $contratosDelLocal, $hoy, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $n = self::entero($nro, 1, 99999, 'El local', false);
        $id = self::entero($idContratoAnt, 1, PHP_INT_MAX, 'El contrato que se renueva', false);

        if (!in_array($id, array_column(is_array($contratosDelLocal) ? $contratosDelLocal : [], 'id'), true)) {
            throw new InvalidArgumentException('Ese contrato no es de ese local en la app de alquileres: volvé a cargar '
                . 'la pantalla.');
        }

        $v = self::validarPagadas($datos, $hoy);
        $ids = [];
        $marcados = [];
        $ya = [];

        $this->enTransaccion(function () use ($n, $id, $v, $usuario, &$ids, &$marcados, &$ya) {
            foreach ($v['conceptos'] as $c) {
                $hay = $this->fila("SELECT ID FROM dbo." . self::T_PAGADA . " WITH (UPDLOCK, HOLDLOCK)
                                    WHERE NRO_SUCURS = ? AND ID_CONTRATO_ANT = ? AND CONCEPTO = ? AND VIGENTE = 1",
                    [$n, $id, $c['concepto']], 'Error al verificar la marca');

                if ($hay !== null) {
                    $ya[] = $c['concepto'];
                    continue;
                }

                $r = $this->fila("INSERT INTO dbo." . self::T_PAGADA . "
                        (NRO_SUCURS, ID_CONTRATO_ANT, CONCEPTO, FECHA, MONTO, COMPROBANTE, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                    [$n, $id, $c['concepto'], $v['fecha'], $c['monto'], $v['comprobante'], $v['observacion'], $usuario, $usuario],
                    'Error al marcar como pagada');
                $ids[] = (int) $r['ID'];
                $marcados[] = $c['concepto'];
            }

            if (empty($marcados)) {
                throw new InvalidArgumentException('Esa renovación ya tiene marcado como pagado todo lo elegido: dalo de baja '
                    . 'primero para cargar otra marca.');
            }
        });

        return ['ids' => $ids, 'marcados' => $marcados, 'ya' => $ya] + $v;
    }
    /** Baja logica de una fila por ID en una de las tablas de altas sueltas. @return bool Si estaba vigente */
    public function baja($tabla, $id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();

        if (!in_array($tabla, [self::T_PAGADA, self::T_OBRA, self::T_APERTURA], true)) {
            throw new InvalidArgumentException('Eso no se da de baja por acá.');
        }

        $i = self::entero($id, 1, PHP_INT_MAX, 'El registro', false);
        $n = 0;

        $this->enTransaccion(function () use ($tabla, $i, $usuario, &$n) {
            $n = $this->darDeBaja($tabla, 'ID = ? AND VIGENTE = 1', [$i], $usuario);
        });

        return $n > 0;
    }

    /** @return array ['id'] + validarObra() */
    public function altaObra($datos, $locales, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarObra($datos, $locales);
        $nuevo = null;

        $this->enTransaccion(function () use ($v, $usuario, &$nuevo) {
            $hay = $this->fila("SELECT ID FROM dbo." . self::T_OBRA . " WITH (UPDLOCK, HOLDLOCK)
                                WHERE NRO_SUCURS = ? AND MES = ? AND VIGENTE = 1", [$v['nro'], $v['mes']],
                'Error al verificar la obra');

            if ($hay !== null) {
                throw new InvalidArgumentException('Ese local ya tiene una obra en ese mes: dala de baja para cargar otra.');
            }

            $r = $this->fila("INSERT INTO dbo." . self::T_OBRA . "
                    (NRO_SUCURS, MES, SEGMENTO, COSTO_USD, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, 1, ?, ?)",
                [$v['nro'], $v['mes'], $v['segmento'], $v['costo_usd'], $v['observacion'], $usuario, $usuario],
                'Error al guardar la obra');
            $nuevo = (int) $r['ID'];
        });

        return ['id' => $nuevo] + $v;
    }

    /** @return array ['id'] + validarApertura() */
    public function altaApertura($datos, $locales, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarApertura($datos, $locales);
        $nuevo = null;

        $this->enTransaccion(function () use ($v, $usuario, &$nuevo) {
            $r = $this->fila("INSERT INTO dbo." . self::T_APERTURA . "
                    (NOMBRE, NRO_SUCURS, MES, SEGMENTO, COSTO_USD, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$v['nombre'], $v['nro'], $v['mes'], $v['segmento'], $v['costo_usd'], $v['observacion'], $usuario, $usuario],
                'Error al guardar la apertura');
            $nuevo = (int) $r['ID'];
        });

        return ['id' => $nuevo] + $v;
    }

    /**
     * Un compromiso, suelto o en serie, en una transaccion: o se cargan todas
     * las cuotas, o ninguna. La serie lleva el ID de la primera.
     *
     * @return array ['id', 'n'] + validarCompromiso()
     */
    public function altaCompromiso($datos, $razones, $proveedores, $locales, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarCompromiso($datos, $razones, $proveedores, $locales);
        $primero = null;
        $n = count($v['cuotas']);

        $this->enTransaccion(function () use ($v, $n, $usuario, &$primero) {
            foreach ($v['cuotas'] as $c) {
                $r = $this->fila("INSERT INTO dbo." . self::T_COMPROMISO . "
                        (TIPO_OPERADOR, COD_PROVEE, NRO_SUCURS, CONCEPTO, FECHA, MONTO, FORMA_PAGO, OBSERVACION, ID_SERIE,
                         CUOTA_NRO, CUOTAS, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                    [$v['tipo'], $v['cod_provee'], $v['nro'], $v['concepto'], $c['fecha'], $c['monto'], $v['forma'],
                     $v['observacion'], $primero, ($n > 1) ? $c['nro'] : null, ($n > 1) ? $n : null, $usuario, $usuario],
                    'Error al guardar el compromiso');

                if ($primero === null) {
                    $primero = (int) $r['ID'];

                    if ($n > 1) {
                        $this->ejecutar("UPDATE dbo." . self::T_COMPROMISO . " SET ID_SERIE = ID WHERE ID = ?", [$primero],
                            'Error al guardar la serie');
                    }
                }
            }
        });

        return ['id' => $primero, 'n' => $n] + $v;
    }

    /**
     * Baja de un compromiso. Con $serie, todas las cuotas vigentes de su serie.
     *
     * @return int Cuantas cuotas se dieron de baja
     */
    public function bajaCompromiso($id, $serie, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $i = self::entero($id, 1, PHP_INT_MAX, 'El compromiso', false);
        $n = 0;

        $this->enTransaccion(function () use ($i, $serie, $usuario, &$n) {
            if ($serie) {
                $f = $this->fila("SELECT ID_SERIE FROM dbo." . self::T_COMPROMISO . " WHERE ID = ?", [$i],
                    'Error al leer el compromiso');

                if ($f !== null && $f['ID_SERIE'] !== null) {
                    $n = $this->darDeBaja(self::T_COMPROMISO, 'ID_SERIE = ? AND VIGENTE = 1', [(int) $f['ID_SERIE']], $usuario);

                    return;
                }
            }

            $n = $this->darDeBaja(self::T_COMPROMISO, 'ID = ? AND VIGENTE = 1', [$i], $usuario);
        });

        return $n;
    }

    /* ====================================================================
       AUXILIARES
       ==================================================================== */

    private function exigirTablas() {
        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException($this->avisoSinTabla());
        }
    }

    /** Baja logica: VIGENTE = 0 con quien y cuando. Devuelve las filas tocadas. */
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
            if (strpos($e['message'], 'UX_RO_T_CF_LL') !== false) {
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
