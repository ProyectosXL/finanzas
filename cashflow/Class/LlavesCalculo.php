<?php

require_once __DIR__ . '/AlquileresPago.php';
require_once __DIR__ . '/AlquileresImputacion.php';
require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/LlavesParametros.php';

/**
 * LlavesCalculo
 * CUANTO SE PAGA Y CUANDO de llaves, renovaciones de contrato, obras de imagen,
 * aperturas y compromisos a mano. Es LA regla: la usan la pestaña Llaves y
 * Renovaciones y el proveedor del tablero (Providers/LlavesProvider.php), y una
 * prueba fija que digan lo mismo, columna por columna.
 *
 * ESTATICA Y PURA. La fecha de hoy, el calendario, los contratos de la app,
 * el alquiler mensual que proyecta Alquileres, los m2, la curva del dolar, los
 * parametros y las facturas entran ya leidos. Asi las pruebas no caducan y la
 * regla se prueba entera sin base.
 *
 * SE APOYA EN LO QUE YA EXISTE; NO LO RECALCULA
 * ---------------------------------------------
 *   - Los contratos (vigencia, llave, comisiones, FPC) son los de la app de
 *     alquileres (AlquileresApp::contratosConImportes()).
 *   - El alquiler del mes (conceptos 1 + 2 + 8, neto, sin IVA) es el que
 *     proyecta Alquileres (Alquileres::alquilerMensual()). Aca no se ajusta
 *     nada por inflacion: si Alquileres no tiene ese mes, no hay estimacion.
 *   - El operador de un local es su razon social principal en Parametros ->
 *     Alquileres, y su % de retencion es el del local de ahi.
 *   - El dolar es DolarFuturo::resolver(), como en Seguros.
 *
 * LAS RENOVACIONES DE UN LOCAL
 * ----------------------------
 * El contrato VIGENTE es el que cubre hoy, o el ultimo que vencio. La
 * RENOVACION es el contrato que empieza despues del fin del vigente. Cada
 * renovacion se identifica por EL CONTRATO QUE RENUEVA ('ant'): es la clave de
 * los parametros por local, de "ya pagada" y de las facturas imputadas, y por
 * eso sigue valiendo cuando administracion carga el contrato nuevo.
 *
 *   CARGADA   un contrato de la app (con su anterior): lo pagado sale de el.
 *             La llave en N cuotas mensuales iguales y consecutivas desde su
 *             inicio (+ el corrimiento del operador), cada una corrida al habil
 *             siguiente; comisiones + FPC en un pago, el dia de la primera
 *             cuota (la firma). Vale para cualquier contrato con cuotas por
 *             delante, tambien el vigente si empezo hace poco.
 *   ESTIMADA  el vigente vence dentro del horizonte (o ya vencio) y no tiene
 *             renovacion cargada:
 *                 llave = % x (1 + 2 + 8 del mes de la primera cuota) x meses
 *             con el % del local, si no el del operador, si no el general; los
 *             meses del local, si no los del ultimo contrato con llave. Solo
 *             si el vigente TIENE LLAVE (> 0): el criterio de Costo de
 *             Ocupacion (verificarValorLlaveNegocio()). Los otros gastos:
 *                 % de otros gastos del operador x llave + escribano
 *             en un pago, el dia de la primera cuota. Vencido sin renovar: la
 *             primera cuota va al primer habil despues de hoy.
 *
 * LLAVE = 1 ES UNA MARCA, NO UN IMPORTE: administracion la carga para que
 * Costo de Ocupacion calcule el devengado ("tiene llave, monto desconocido").
 * Nunca se pagan $ 1: se estima, tambien cuando el 1 esta en una renovacion
 * cargada.
 *
 * IMPUESTOS Y RETENCION: neto x (1 + % de impuestos del OPERADOR) - neto x %
 * de retencion del LOCAL, con AlquileresPago::importes() -la misma regla de
 * redondeo que Alquileres- pasandole el % de impuestos como la unica alicuota
 * (ver importes()). El escribano es un importe final: no lleva ni impuestos ni
 * retencion. Sin % de impuestos o sin % de retencion, la llave y los otros
 * gastos de ese local NO SE PROYECTAN y se avisa con el neto que falta.
 *
 * QUE CORTA LO PROYECTADO
 * -----------------------
 *   - "No renueva" (por local): no se estima nada de esa renovacion.
 *   - "Ya pagada" (por local, renovacion y concepto): ese concepto no se
 *     proyecta; ya salio de caja.
 *   - Una FACTURA imputada a un local y un concepto: reemplaza todo lo
 *     proyectado de ese concepto en esa renovacion -manda lo real, como en
 *     Alquileres (parte 13)- y paga lo suyo; si ya esta pagada en Tango, no
 *     suma y corta igual. Una de obra reemplaza la obra de ese local en ese mes.
 *
 * Los pagos con fecha de hoy o anterior no se proyectan. Una cuota de un
 * contrato firmado que ya paso, del mes en curso o el anterior, sin factura ni
 * marca de pagada, se avisa: puede estar sin pagar o sin factura.
 *
 * UN DATO QUE FALTA ES NULL MAS UN AVISO, NUNCA UN 0: cada faltante deja el
 * pago afuera, lo anota en 'no_proyecta' (con el importe, cuando se puede
 * calcular) y genera un aviso que dice que falta y donde cargarlo.
 */
class LlavesCalculo {

    /** Los conceptos de la pestaña y del tablero */
    const LLAVE = 'LLAVE';
    const OTROS = 'OTROS';
    const OBRA = 'OBRA';
    const APERTURA = 'APERTURA';
    const COMPROMISO = 'COMPROMISO';

    const NOMBRE_CONCEPTO = [
        self::LLAVE => 'Llave',
        self::OTROS => 'Otros gastos',
        self::OBRA => 'Obra de imagen',
        self::APERTURA => 'Apertura',
        self::COMPROMISO => 'Compromiso'
    ];

    /** El origen de cada pago: el chip de la celda */
    const CONTRATO = 'CONTRATO';
    const ESTIMADO = 'ESTIMADO';
    const MANUAL = 'MANUAL';
    const FACTURA = 'FACTURA';

    const NOMBRE_ORIGEN = [
        self::CONTRATO => 'Contrato cargado',
        self::ESTIMADO => 'Estimado',
        self::MANUAL => 'Manual',
        self::FACTURA => 'Factura'
    ];

    /** El estado del contrato de un local (la solapa Contratos) */
    const VIGENTE = 'VIGENTE';
    const VENCE = 'VENCE';
    const VENCIDO = 'VENCIDO';
    const RENOVADO = 'RENOVADO';
    const NO_RENUEVA = 'NO_RENUEVA';
    const SIN_CONTRATO = 'SIN_CONTRATO';
    const POR_VIGENCIA = 'POR_VIGENCIA';

    const NOMBRE_ESTADO = [
        self::VIGENTE => 'Vigente',
        self::VENCE => 'Vence en el horizonte',
        self::VENCIDO => 'Vencido sin renovar',
        self::RENOVADO => 'Renovado',
        self::NO_RENUEVA => 'No renueva',
        self::SIN_CONTRATO => 'Sin contrato en la app',
        self::POR_VIGENCIA => 'Temporal (proyecta según vigencia)'
    ];

    /** La renovacion: cargada en la app o estimada */
    const CARGADA = 'CARGADA';
    const ESTIMADA = 'ESTIMADA';

    const SEGMENTOS = ['CHICO', 'MEDIANO', 'GRANDE'];

    /** El largo medio de un mes, para pasar una vigencia en dias a meses enteros */
    const DIAS_MES = 30.4375;

    /* ====================================================================
       LOS CONTRATOS
       ==================================================================== */

    /**
     * Los meses enteros de una vigencia: los dias (desde y hasta incluidos)
     * sobre el largo medio de un mes, redondeado. 01/01/2027 a 31/12/2029 da
     * 36; 20/10/2026 a 19/10/2029 tambien. Minimo 1.
     *
     * @return int
     */
    public static function meses($desde, $hasta) {
        $dias = (strtotime($hasta) - strtotime($desde)) / 86400 + 1;

        return max(1, (int) round($dias / self::DIAS_MES));
    }

    /** Si el contrato tiene llave (> 0, la marca de 1 incluida): el criterio de Costo de Ocupacion */
    public static function tieneLlave($c) {
        return $c !== null && $c['llave'] !== null && $c['llave'] > 0;
    }

    /** Si la llave es la marca de 1 ("tiene llave, monto desconocido") */
    public static function esMarca($c) {
        return $c !== null && $c['llave'] !== null && abs($c['llave'] - 1.0) < 0.005;
    }

    /**
     * Los contratos ordenados por inicio, sin los duplicados exactos (misma
     * vigencia y mismos importes: MDP Aldrey tiene dos, #25 y #26). Se queda
     * el de menor ID.
     *
     * @param array $contratos [['id', 'desde', 'hasta', 'llave', 'comisiones', 'fpc']]
     * @return array
     */
    public static function limpiar($contratos) {
        $lista = array_values(is_array($contratos) ? $contratos : []);
        usort($lista, function ($a, $b) { return strcmp($a['desde'], $b['desde']) ?: ($a['id'] <=> $b['id']); });
        $vistos = [];
        $out = [];

        foreach ($lista as $c) {
            $k = $c['desde'] . '|' . $c['hasta'] . '|' . json_encode([$c['llave'], $c['comisiones'], $c['fpc']]);

            if (!isset($vistos[$k])) {
                $vistos[$k] = true;
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * El contrato vigente: el que cubre hoy (si se superponen, el que empezo
     * ultimo); si ninguno, el ultimo que vencio. Null si todos son futuros o
     * no hay.
     *
     * @return array|null
     */
    public static function vigente($contratos, $hoy) {
        $cubre = null;
        $ultimo = null;

        foreach ($contratos as $c) {
            if ($c['desde'] <= $hoy && $c['hasta'] >= $hoy && ($cubre === null || $c['desde'] >= $cubre['desde'])) {
                $cubre = $c;
            }

            if ($c['hasta'] < $hoy && ($ultimo === null || $c['hasta'] > $ultimo['hasta'])) {
                $ultimo = $c;
            }
        }

        return $cubre ?? $ultimo;
    }

    /** La renovacion de un contrato: el primero que empieza despues de su fin. Null si no hay. */
    public static function renovacionDe($contratos, $c) {
        foreach ($contratos as $x) {
            if ($x['desde'] > $c['hasta']) {
                return $x;
            }
        }

        return null;
    }

    /** El contrato que renueva uno dado: el que vencio ultimo antes de su inicio. Null si es el primero. */
    public static function anteriorDe($contratos, $c) {
        $ant = null;

        foreach ($contratos as $x) {
            if ($x['id'] !== $c['id'] && $x['hasta'] < $c['desde'] && ($ant === null || $x['hasta'] > $ant['hasta'])) {
                $ant = $x;
            }
        }

        return $ant;
    }

    /** El ultimo contrato con llave (> 0 o la marca) que empezo hasta el inicio de $c, $c incluido. */
    public static function ultimoConLlave($contratos, $c) {
        $out = null;

        foreach ($contratos as $x) {
            if ($x['desde'] <= $c['desde'] && self::tieneLlave($x)) {
                $out = $x;
            }
        }

        return $out;
    }

    /**
     * El estado del contrato de un local, para la solapa Contratos.
     *
     * @param array|null $vigente
     * @param array|null $renovacion
     * @param bool $noRenueva
     * @param string $hoy
     * @param string $ultimoMes 'Y-m' del horizonte
     * @return string
     */
    public static function estado($vigente, $renovacion, $noRenueva, $hoy, $ultimoMes) {
        if ($vigente === null) {
            return $renovacion !== null ? self::RENOVADO : self::SIN_CONTRATO;
        }

        if ($renovacion !== null) {
            return self::RENOVADO;
        }

        if ($noRenueva) {
            return self::NO_RENUEVA;
        }

        if ($vigente['hasta'] < $hoy) {
            return self::VENCIDO;
        }

        return (substr($vigente['hasta'], 0, 7) <= $ultimoMes) ? self::VENCE : self::VIGENTE;
    }

    /* ====================================================================
       EL SEGMENTO
       ==================================================================== */

    /**
     * El segmento de un local por sus m2 totales: hasta el corte de chico,
     * CHICO; hasta el de mediano, MEDIANO; mas, GRANDE. Los bordes son del
     * segmento de abajo (84 m2 con corte 84 es chico). Null sin m2 o sin los
     * dos cortes: un local sin medidas no es chico.
     *
     * @param float|null $m2
     * @param float|null $corteChico
     * @param float|null $corteMediano
     * @return string|null
     */
    public static function segmento($m2, $corteChico, $corteMediano) {
        if ($m2 === null || $corteChico === null || $corteMediano === null) {
            return null;
        }

        if ($m2 <= $corteChico) {
            return 'CHICO';
        }

        return ($m2 <= $corteMediano) ? 'MEDIANO' : 'GRANDE';
    }

    /**
     * Los terciles de una lista de m2: los dos cortes que la parten en tres
     * partes iguales, con interpolacion lineal entre los dos valores vecinos.
     * Es lo que se SUGIERE en Parametros; los cortes los confirma una persona.
     *
     * @param float[] $valores
     * @return array ['chico', 'mediano'] o null con menos de 3 valores
     */
    public static function terciles($valores) {
        $v = array_values(array_filter(is_array($valores) ? $valores : [], function ($x) { return $x !== null; }));
        sort($v);
        $n = count($v);

        if ($n < 3) {
            return null;
        }

        $q = function ($p) use ($v, $n) {
            $i = ($n - 1) * $p;
            $lo = (int) floor($i);
            $hi = min($n - 1, $lo + 1);

            return round($v[$lo] + ($v[$hi] - $v[$lo]) * ($i - $lo), 2);
        };

        return ['chico' => $q(1 / 3), 'mediano' => $q(2 / 3)];
    }

    /* ====================================================================
       CUANTO Y CUANDO
       ==================================================================== */

    /**
     * Los importes de un pago de llave u otros gastos.
     *
     * POR QUE NO SE TOCA AlquileresPago::importes(): esa regla tiene dos
     * alicuotas por local (IVA y percepcion) y aca hay una sola por operador
     * (lo que el operador factura de mas sobre el contrato: IRSA 31 %). Se le
     * pasa el % de impuestos como la unica alicuota y la percepcion en 0: la
     * suma, la retencion sobre el neto y el redondeo a centavos de cada parte
     * siguen siendo los de Alquileres, escritos una sola vez, sin agregarle a
     * esa funcion un caso que Alquileres no tiene.
     *
     * @param float $neto
     * @param float $pctImpuestos
     * @param float $pctRetencion
     * @return array ['neto', 'impuestos', 'bruto', 'retencion', 'a_pagar']
     */
    public static function importes($neto, $pctImpuestos, $pctRetencion) {
        $i = AlquileresPago::importes($neto, ['pct_iva' => $pctImpuestos, 'pct_percepcion' => 0,
                                             'pct_retencion' => $pctRetencion]);

        return ['neto' => $i['neto'], 'impuestos' => $i['iva'], 'bruto' => $i['bruto'], 'retencion' => $i['retencion'],
                'a_pagar' => $i['a_pagar']];
    }

    /**
     * Un total en N partes iguales, al centavo: las partes suman exacto el
     * total. Es AlquileresImputacion::repartirCentavos() con pesos iguales.
     *
     * @return float[]
     */
    public static function cuotas($total, $n) {
        return array_values(AlquileresImputacion::repartirCentavos($total, array_fill(0, max(1, (int) $n), 1.0)));
    }

    /**
     * El dia de pago de un mes: el dia pedido (o el ultimo del mes, si no
     * existe), corrido al habil siguiente.
     *
     * @return array Lo de DiasHabiles::siguiente() + 'pedido' => 'Y-m-d'
     */
    public static function diaDelMes($mes, $dia, $habiles) {
        $ult = (int) date('t', strtotime($mes . '-01'));
        $pedido = $mes . '-' . sprintf('%02d', min(max(1, (int) $dia), $ult));

        return DiasHabiles::siguiente($pedido, $habiles) + ['pedido' => $pedido];
    }

    /** El primer habil DESPUES de hoy: los pagos de hoy no se proyectan. */
    public static function despuesDeHoy($hoy, $habiles) {
        return DiasHabiles::siguiente(date('Y-m-d', strtotime($hoy . ' +1 day')), $habiles);
    }

    /* ====================================================================
       LA PROYECCION
       ==================================================================== */

    /**
     * Todos los pagos del horizonte, con lo que explica cada uno.
     *
     * @param array $e [
     *   'hoy' => 'Y-m-d',
     *   'meses' => ['Y-m'] los del horizonte,
     *   'habiles' => ['Y-m-d' => bool],
     *   'locales' => NRO => ['nro', 'nombre', 'habilitado' => bool, 'vigencia' => bool (proyecta segun
     *                vigencia en Alquileres), 'cod_provee' => principal dada de alta | null,
     *                'operador' => nombre | null, 'pct_retencion' => float|null,
     *                'contratos' => [...], 'm2' => float|null],
     *   'alquiler' => NRO => 'Y-m' => float  (1 + 2 + 8 neto, de Alquileres),
     *   'par' => LlavesParametros::todo(),
     *   'curva' => la de DolarFuturo,
     *   'facturas' => [['nro', 'concepto', 'ant', 'contrato', 'mes', 'pagada' => bool, 'cod_provee',
     *                   'comprobante', 'pagos' => [['fecha', 'importe', 'nota']], 'texto']],
     *   'operadores' => COD => nombre (de compromisos con proveedores),
     *   'locales_nombres' => NRO => nombre (todos los de la app, para obras y aperturas)
     * ]
     * @return array ['pagos', 'locales', 'no_proyecta', 'avisos', 'cortados']
     */
    public static function proyectar($e) {
        $hoy = $e['hoy'];
        $meses = array_values($e['meses']);
        $enHorizonte = array_flip($meses);
        $ultimoMes = end($meses);
        $mesActual = substr($hoy, 0, 7);
        $mesAnterior = Inflacion::mesMas($mesActual, -1);
        $habiles = $e['habiles'] ?? [];
        $par = $e['par'];
        $gen = $par['generales'] ?? [];
        $ops = $par['operadores'] ?? [];
        $facturas = $e['facturas'] ?? [];

        $s = [
            'pagos' => [], 'locales' => [], 'no_proyecta' => [], 'cortados' => [],
            'f' => ['vencidos' => [], 'vencen' => [], 'sin_razon' => [], 'sin_alquiler' => [], 'sin_pct_otros' => [],
                    'sin_escribano' => [], 'sin_impuestos' => [], 'sin_retencion' => [], 'pasadas' => [],
                    'sin_contrato' => [], 'meses_cortos' => [], 'sin_segmento' => [], 'sin_cortes' => false,
                    'sin_costo' => [], 'sin_dolar' => [], 'sin_tablas' => empty($par['tablas_ok'])]
        ];

        /* Las marcas de "ya pagada" y las facturas, por local y concepto, para
           saber que renovacion cortan. */
        $pagadas = [];

        foreach ($par['pagadas'] ?? [] as $p) {
            $pagadas[$p['nro']][$p['concepto']][] = $p;
        }

        foreach ($e['locales'] as $nro => $l) {
            $s['locales'][$nro] = self::proyectarLocal($l, $e, $gen, $ops, $pagadas[$nro] ?? [], $facturas, $s);
        }

        self::obras($e, $gen, $facturas, $s);
        self::aperturas($e, $gen, $s);
        self::compromisos($e, $s);
        self::pagosDeFacturas($e, $s);

        /* Solo lo que cae en el horizonte, despues de hoy. Las facturas ya
           vienen con su fecha de pago resuelta (un vencimiento pasado va al
           siguiente habil desde hoy, como en Alquileres). */
        $s['pagos'] = array_values(array_filter($s['pagos'], function ($p) use ($hoy, $enHorizonte) {
            $ok = ($p['origen'] === self::FACTURA) ? $p['fecha'] >= $hoy : $p['fecha'] > $hoy;

            return $ok && isset($enHorizonte[substr($p['fecha'], 0, 7)]);
        }));

        usort($s['pagos'], function ($a, $b) {
            return strcmp($a['fecha'], $b['fecha']) ?: strcmp((string) $a['nro'], (string) $b['nro'])
                ?: strcmp($a['concepto'], $b['concepto']);
        });

        return [
            'pagos' => $s['pagos'],
            'locales' => $s['locales'],
            'no_proyecta' => $s['no_proyecta'],
            'cortados' => $s['cortados'],
            'avisos' => self::avisos($s['f'], $hoy)
        ];
    }

    /**
     * Las renovaciones de un local y sus pagos. Devuelve lo que la solapa
     * Contratos muestra del local.
     */
    private static function proyectarLocal($l, $e, $gen, $ops, $pagadas, $facturas, &$s) {
        $hoy = $e['hoy'];
        $meses = array_values($e['meses']);
        $ultimoMes = end($meses);
        $contratos = self::limpiar($l['contratos'] ?? []);
        $vig = self::vigente($contratos, $hoy);
        $ren = ($vig === null) ? null : self::renovacionDe($contratos, $vig);
        $cod = $l['cod_provee'] ?? null;
        $par = $e['par'];
        $locP = ($vig === null) ? null : ($par['locales'][$l['nro'] . '|' . $vig['id']] ?? null);
        $noRenueva = !empty($locP['no_renueva']);

        $info = [
            'nro' => $l['nro'], 'nombre' => $l['nombre'], 'cod_provee' => $cod, 'operador' => $l['operador'] ?? null,
            'm2' => $l['m2'] ?? null,
            'segmento' => self::segmento($l['m2'] ?? null, $gen['CORTE_CHICO'] ?? null, $gen['CORTE_MEDIANO'] ?? null),
            'vigente' => $vig, 'renovacion' => $ren, 'contratos' => $contratos,
            'meses_vigente' => ($vig === null) ? null : self::meses($vig['desde'], $vig['hasta']),
            'parametros' => $locP, 'pagadas' => $pagadas, 'estado' => null, 'renovaciones' => [], 'notas' => []
        ];

        if (!empty($l['vigencia'])) {
            $info['estado'] = self::POR_VIGENCIA;
            $info['notas'][] = 'Proyecta según la vigencia de su contrato en Alquileres (local temporal): no se estima '
                . 'su renovación.';

            return $info;
        }

        if (empty($contratos)) {
            $info['estado'] = self::SIN_CONTRATO;
            $s['f']['sin_contrato'][] = $l['nombre'];

            return $info;
        }

        /* Todos sus contratos son futuros: el primero todavia no empezo, no hay nada que renovar. */
        $info['estado'] = ($vig === null) ? self::VIGENTE : self::estado($vig, $ren, $noRenueva, $hoy, $ultimoMes);

        if ($info['estado'] === self::VENCIDO) {
            $s['f']['vencidos'][] = ['nombre' => $l['nombre'], 'hasta' => $vig['hasta']];
        } elseif ($info['estado'] === self::VENCE) {
            $s['f']['vencen'][] = ['nombre' => $l['nombre'], 'hasta' => $vig['hasta']];
        }

        /* LAS RENOVACIONES CARGADAS: todo contrato de la app con algo que pagar,
           con su anterior como clave. Las viejas no generan nada en el horizonte
           y salen solas con el filtro de fechas. */
        $renovaciones = [];

        foreach ($contratos as $c) {
            if (self::tieneLlave($c) || ((float) $c['comisiones'] + (float) $c['fpc']) > 0) {
                $ant = self::anteriorDe($contratos, $c);
                $renovaciones[] = ['tipo' => self::CARGADA, 'contrato' => $c, 'ant' => ($ant === null) ? null : $ant['id'],
                                   'ant_contrato' => $ant];
            }
        }

        if ($vig !== null && $ren === null && !$noRenueva
            && in_array($info['estado'], [self::VENCIDO, self::VENCE], true)) {
            $renovaciones[] = ['tipo' => self::ESTIMADA, 'contrato' => null, 'ant' => $vig['id'], 'ant_contrato' => $vig];
        }

        if ($noRenueva) {
            $info['notas'][] = 'No renueva: ' . ($locP['motivo_no_renueva'] ?? '') . '. No se estima nada.';
        }

        /* SIN OPERADOR NO HAY CON QUE PROYECTAR: ni sus impuestos, ni su %, ni
           sus cuotas. Se avisa solo si hay algo que proyectar. */
        if ($cod === null) {
            $hayAlgo = false;

            foreach ($renovaciones as $r) {
                if ($r['tipo'] === self::ESTIMADA || self::ultimaFecha($r, $gen) >= Inflacion::mesMas(substr($hoy, 0, 7), -1) . '-01') {
                    $hayAlgo = true;
                }
            }

            if ($hayAlgo) {
                $s['f']['sin_razon'][] = $l['nombre'];
                $s['no_proyecta'][] = ['nro' => $l['nro'], 'local' => $l['nombre'], 'concepto' => self::LLAVE,
                                       'motivo' => 'sin razón social en Parámetros › Alquileres', 'importe' => null];
            }

            return $info;
        }

        foreach ($renovaciones as $r) {
            $info['renovaciones'][] = self::proyectarRenovacion($r, $l, $e, $gen, $ops[$cod] ?? [], $contratos,
                $pagadas, $facturas, $s);
        }

        return $info;
    }

    /**
     * Hasta cuando puede tener pagos una renovacion cargada sin operador (sin
     * su corrimiento ni sus cuotas): el ultimo dia del mes de la ultima cuota
     * general. Solo decide si vale la pena avisar que le falta el operador.
     */
    private static function ultimaFecha($r, $gen) {
        if ($r['contrato'] === null) {
            return '9999-12-31';
        }

        $mes = Inflacion::mesMas(substr($r['contrato']['desde'], 0, 7), max(1, (int) ($gen['CUOTAS'] ?? 1)) - 1);

        return date('Y-m-t', strtotime($mes . '-01'));
    }

    /**
     * Los pagos de llave y otros gastos de una renovacion.
     *
     * @return array Lo que se muestra de la renovacion: tipo, clave, primera cuota y el detalle
     */
    private static function proyectarRenovacion($r, $l, $e, $gen, $opP, $contratos, $pagadas, $facturas, &$s) {
        $hoy = $e['hoy'];
        $habiles = $e['habiles'] ?? [];
        $nro = $l['nro'];
        $nombre = $l['nombre'];
        $par = $e['par'];
        $locP = ($r['ant'] === null) ? null : ($par['locales'][$nro . '|' . $r['ant']] ?? null);
        $c = $r['contrato'];
        $vig = $r['ant_contrato'];
        $corr = (int) ($opP['corrimiento'] ?? 0);
        $n = (int) ($locP['cuotas'] ?? $opP['cuotas'] ?? $gen['CUOTAS'] ?? 0);
        $estimada = ($r['tipo'] === self::ESTIMADA);
        $vencido = $estimada && $vig['hasta'] < $hoy;

        /* EL INICIO: el del contrato nuevo, o el dia siguiente al fin del
           vigente. La primera cuota va en su mes + el corrimiento del operador,
           salvo que el local diga otro mes; vencido sin renovar, al mes en curso. */
        $inicio = $estimada ? date('Y-m-d', strtotime($vig['hasta'] . ' +1 day')) : $c['desde'];
        $dia = (int) substr($inicio, 8, 2);

        if (!empty($locP['mes_primera_cuota'])) {
            $mes1 = $locP['mes_primera_cuota'];
        } elseif ($vencido) {
            $mes1 = substr($hoy, 0, 7);
        } else {
            $mes1 = Inflacion::mesMas(substr($inicio, 0, 7), $corr);
        }

        $clave = $nro . '|' . ($r['ant'] ?? ('C' . $c['id']));
        $out = ['tipo' => $r['tipo'], 'clave' => $clave, 'ant' => $r['ant'], 'contrato' => ($c === null) ? null : $c['id'],
                'inicio' => $inicio, 'mes1' => $mes1, 'cuotas' => $n, 'llave' => null, 'otros' => null, 'notas' => []];

        /* Las fechas de las N cuotas. */
        $fechas = [];

        for ($i = 0; $i < max(1, $n); $i++) {
            if ($vencido && $i === 0 && empty($locP['mes_primera_cuota'])) {
                $d = self::despuesDeHoy($hoy, $habiles) + ['pedido' => $hoy];
            } else {
                $d = self::diaDelMes(Inflacion::mesMas($mes1, $i), $dia, $habiles);
            }

            $fechas[] = $d;
        }

        /* UN CONTRATO VIEJO NO DICE NADA: si su ultimo pago es anterior al mes
           pasado, no proyecta ni entra en el aviso de cuotas pasadas, y no tiene
           sentido pedirle impuestos ni operador. */
        if (!$estimada && max(array_column($fechas, 'fecha')) < Inflacion::mesMas(substr($hoy, 0, 7), -1) . '-01') {
            $out['notas'][] = 'Sus pagos son anteriores al mes pasado.';
            $out['vieja'] = true;

            return $out;
        }

        $pctRet = $l['pct_retencion'] ?? null;
        $pctImp = $opP['pct_impuestos'] ?? null;
        $quien = $nombre . ($estimada ? ' (renovación de ' . self::corta($vig['hasta']) . ')'
            : ' (contrato #' . $c['id'] . ' del ' . self::corta($c['desde']) . ')');

        /* ---- LA LLAVE ---- */
        $llave = null;
        $origenLlave = null;
        $cuenta = '';

        if ($estimada) {
            if (!self::tieneLlave($vig)) {
                $out['notas'][] = 'El contrato vigente no tiene llave: no se estima (el criterio de Costo de Ocupación).';
            } elseif (($locP['llave_manual'] ?? null) !== null) {
                $llave = (float) $locP['llave_manual'];
                $origenLlave = self::MANUAL;
                $cuenta = 'Llave cargada a mano en Parámetros: ' . self::plata($llave);
            } else {
                $est = self::estimarLlave($nro, $vig, $mes1, $locP, $opP, $gen, $contratos, $e, $nombre, $s);
                $llave = $est['llave'];
                $cuenta = $est['cuenta'];
                $origenLlave = self::ESTIMADO;
            }
        } elseif (self::esMarca($c)) {
            $est = self::estimarLlave($nro, $c, $mes1, $locP, $opP, $gen, $contratos, $e, $nombre, $s, true);
            $llave = $est['llave'];
            $cuenta = 'La app tiene llave = 1 (tiene llave, monto desconocido): se estima. ' . $est['cuenta'];
            $origenLlave = self::ESTIMADO;
        } elseif (self::tieneLlave($c)) {
            $llave = (float) $c['llave'];
            $origenLlave = self::CONTRATO;
            $cuenta = 'Llave del contrato #' . $c['id'] . ' en la app: ' . self::plata($llave);
        }

        $out['llave'] = $llave;

        /* ---- LOS OTROS GASTOS ---- */
        $otros = null;
        $escribano = null;
        $cuentaOtros = '';

        if ($estimada) {
            $pctOtros = $opP['pct_otros'] ?? null;
            $escribano = $opP['escribano'] ?? null;

            if ($pctOtros !== null && $llave !== null) {
                $otros = round($pctOtros / 100 * $llave, 2);
                $cuentaOtros = self::pct($pctOtros) . ' de otros gastos del operador × llave ' . self::plata($llave)
                    . ' = ' . self::plata($otros);
            } elseif ($llave !== null && $llave > 0) {
                $s['f']['sin_pct_otros'][$l['operador'] ?? $l['cod_provee']] = true;
            }

            if ($escribano === null) {
                $s['f']['sin_escribano'][$l['operador'] ?? $l['cod_provee']] = true;
            }
        } else {
            $suma = (float) $c['comisiones'] + (float) $c['fpc'];

            if ($suma > 0) {
                $otros = round($suma, 2);
                $cuentaOtros = 'Comisiones ' . self::plata((float) $c['comisiones']) . ' + FPC lanzamiento '
                    . self::plata((float) $c['fpc']) . ' del contrato #' . $c['id'] . ' = ' . self::plata($otros);
            }
        }

        $out['otros'] = $otros;

        /* ---- LO QUE CORTA: YA PAGADA Y FACTURAS ---- */
        foreach ([self::LLAVE, self::OTROS] as $concepto) {
            $marca = self::marcaDe($pagadas[$concepto] ?? [], $r);
            $fact = self::facturasDe($facturas, $nro, $concepto, $r);

            if ($marca !== null || !empty($fact)) {
                $out['notas'][] = self::NOMBRE_CONCEPTO[$concepto] . ': ' . ($marca !== null
                    ? 'ya pagada el ' . self::corta($marca['fecha']) . ' (' . self::plata($marca['monto']) . ')'
                    : 'la reemplaza la factura ' . implode(', ', array_column($fact, 'comprobante')))
                    . '; no se proyecta.';
                $s['cortados'][] = ['nro' => $nro, 'concepto' => $concepto, 'clave' => $clave,
                                    'por' => ($marca !== null) ? 'PAGADA' : 'FACTURA'];

                if ($concepto === self::LLAVE) {
                    $llave = null;
                } else {
                    $otros = null;
                    $escribano = null;
                }
            }
        }

        if ($llave === null && $otros === null && $escribano === null) {
            return $out;
        }

        /* ---- IMPUESTOS Y RETENCION: sin alguno, no se proyecta ---- */
        $netoFalta = (float) $llave + (float) $otros;

        if (($llave !== null || $otros !== null) && ($pctImp === null || $pctRet === null)) {
            if ($pctImp === null) {
                $s['f']['sin_impuestos'][$l['operador'] ?? $l['cod_provee']][] = $nombre;
            }

            if ($pctRet === null) {
                $s['f']['sin_retencion'][] = $nombre;
            }

            $s['no_proyecta'][] = ['nro' => $nro, 'local' => $nombre, 'concepto' => self::LLAVE,
                'motivo' => ($pctImp === null) ? 'sin % de impuestos del operador' : 'sin % de retención del local',
                'importe' => $netoFalta > 0 ? round($netoFalta, 2) : null];
            $llave = null;
            $otros = null;
        }

        $origenRen = $estimada ? self::ESTIMADO : self::CONTRATO;

        if ($llave !== null && $llave > 0 && $n > 0) {
            $partes = self::cuotas($llave, $n);

            foreach ($partes as $i => $neto) {
                $imp = self::importes($neto, $pctImp, $pctRet);
                $s['pagos'][] = self::pago(self::LLAVE, $origenLlave, $l, $fechas[$i]['fecha'], $imp['a_pagar'], [
                    'neto' => $imp['neto'], 'impuestos' => $imp['impuestos'], 'retencion' => $imp['retencion'],
                    'cuota' => $i + 1, 'cuotas' => $n, 'clave' => $clave, 'renovacion' => $r['tipo'],
                    'detalle' => $cuenta . ', cuota ' . ($i + 1) . ' de ' . $n . '. ' . self::lineaImpuestos($imp, $pctImp, $pctRet)
                        . self::notaFecha($fechas[$i])
                ]);
            }

            if (!$estimada) {
                self::pasadas($s, $l, $partes, $fechas, $e, $pctImp, $pctRet, $quien);
            }
        }

        if ($otros !== null || $escribano !== null) {
            $imp = ($otros !== null && $otros > 0) ? self::importes($otros, $pctImp, $pctRet)
                : ['neto' => 0.0, 'impuestos' => 0.0, 'retencion' => 0.0, 'a_pagar' => 0.0];
            $total = round($imp['a_pagar'] + (float) $escribano, 2);

            if ($total > 0) {
                $s['pagos'][] = self::pago(self::OTROS, $origenRen, $l, $fechas[0]['fecha'], $total, [
                    'neto' => $imp['neto'], 'impuestos' => $imp['impuestos'], 'retencion' => $imp['retencion'],
                    'escribano' => $escribano, 'cuota' => 1, 'cuotas' => 1, 'clave' => $clave, 'renovacion' => $r['tipo'],
                    'detalle' => trim(($cuentaOtros !== '' ? $cuentaOtros . '. ' . self::lineaImpuestos($imp, $pctImp, $pctRet) : '')
                        . ($escribano !== null ? ' + escribano y otros ' . self::plata($escribano) . ' (sin impuestos) = '
                            . self::plata($total) . '.' : '')
                        . ' Un pago, en la firma. ' . self::notaFecha($fechas[0]))
                ]);
            }
        }

        return $out;
    }

    /**
     * La llave estimada: % x (1 + 2 + 8 del mes de la primera cuota) x meses.
     *
     * @param array $base El contrato del que salen los meses por defecto (el vigente, o la renovacion con la marca)
     * @param bool $propios Si los meses son los de $base (una renovacion cargada con la marca) en vez de los del
     *        ultimo contrato con llave
     * @return array ['llave' => float|null, 'cuenta' => string]
     */
    private static function estimarLlave($nro, $base, $mes1, $locP, $opP, $gen, $contratos, $e, $nombre, &$s, $propios = false) {
        $pct = $locP['pct_llave'] ?? $opP['pct_llave'] ?? $gen['PCT_LLAVE'] ?? null;
        $origenPct = (($locP['pct_llave'] ?? null) !== null) ? 'del local'
            : ((($opP['pct_llave'] ?? null) !== null) ? 'del operador' : 'general');

        if (($locP['meses'] ?? null) !== null) {
            $meses = (int) $locP['meses'];
            $deMeses = 'meses cargados en Parámetros';
        } else {
            $conLlave = $propios ? $base : self::ultimoConLlave($contratos, $base);
            $deMeses = ($conLlave === null) ? 'meses del contrato vigente' : 'meses del contrato #' . $conLlave['id'];
            $meses = self::meses(($conLlave ?? $base)['desde'], ($conLlave ?? $base)['hasta']);

            if ($conLlave === null && $meses < 12) {
                $s['f']['meses_cortos'][] = $nombre . ' (' . $meses . ')';
            }
        }

        /* EL ALQUILER ES EL QUE PROYECTA ALQUILERES PARA ESE MES: si no lo
           tiene (fuera de su horizonte, o el local no proyecta), no hay
           estimacion. No se ajusta nada aca. */
        $alq = $e['alquiler'][$nro][$mes1] ?? null;

        if ($alq === null) {
            $s['f']['sin_alquiler'][] = $nombre . ' (' . AlquileresImputacion::mesLargo($mes1) . ')';
            $s['no_proyecta'][] = ['nro' => $nro, 'local' => $nombre, 'concepto' => self::LLAVE,
                                   'motivo' => 'sin alquiler proyectado en Alquileres para ' . $mes1, 'importe' => null];

            return ['llave' => null, 'cuenta' => ''];
        }

        if ($pct === null) {
            return ['llave' => null, 'cuenta' => ''];
        }

        $llave = round($pct / 100 * $alq * $meses, 2);

        return ['llave' => $llave, 'cuenta' => self::pct($pct) . ' (' . $origenPct . ') × ' . self::plata($alq)
            . ' (1 + 2 + 8 de ' . AlquileresImputacion::mesLargo($mes1) . ', como lo proyecta Alquileres, neto) × '
            . $meses . ' meses (' . $deMeses . ') = ' . self::plata($llave)];
    }

    /** La marca de "ya pagada" que corresponde a una renovacion, o null. */
    private static function marcaDe($marcas, $r) {
        foreach ($marcas as $m) {
            if ($r['ant'] !== null && (int) $m['id_contrato_ant'] === (int) $r['ant']) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Las facturas imputadas a un local y un concepto que caen en una
     * renovacion: por el contrato que renueva o por el contrato nuevo.
     */
    public static function facturasDe($facturas, $nro, $concepto, $r) {
        return array_values(array_filter(is_array($facturas) ? $facturas : [], function ($f) use ($nro, $concepto, $r) {
            if ((int) $f['nro'] !== (int) $nro || $f['concepto'] !== $concepto) {
                return false;
            }

            return ($r['ant'] !== null && ($f['ant'] ?? null) !== null && (int) $f['ant'] === (int) $r['ant'])
                || ($r['contrato'] !== null && ($f['contrato'] ?? null) !== null
                    && (int) $f['contrato'] === (int) $r['contrato']['id']);
        }));
    }

    /**
     * Las cuotas de un contrato firmado que ya pasaron, del mes en curso o el
     * anterior: puede que esten sin pagar o sin factura.
     */
    private static function pasadas(&$s, $l, $partes, $fechas, $e, $pctImp, $pctRet, $quien) {
        $mesActual = substr($e['hoy'], 0, 7);
        $mesAnterior = Inflacion::mesMas($mesActual, -1);

        foreach ($partes as $i => $neto) {
            $f = $fechas[$i]['fecha'];

            if ($f <= $e['hoy'] && in_array(substr($f, 0, 7), [$mesActual, $mesAnterior], true)) {
                $s['f']['pasadas'][] = $quien . ', cuota ' . ($i + 1) . ' del ' . self::corta($f) . ' por '
                    . self::plata(self::importes($neto, $pctImp, $pctRet)['a_pagar']);
            }
        }
    }

    /* ====================================================================
       OBRAS, APERTURAS, COMPROMISOS Y FACTURAS
       ==================================================================== */

    /**
     * Las obras de imagen: costo en U$S (el propio o el del segmento) x el
     * dolar de su fecha, el dia de pago general del mes, corrido al habil.
     */
    private static function obras($e, $gen, $facturas, &$s) {
        foreach ($e['par']['obras'] ?? [] as $o) {
            $l = $e['locales'][$o['nro']] ?? ['nro' => $o['nro'], 'nombre' => $e['locales_nombres'][$o['nro']] ?? ('Local ' . $o['nro']),
                                              'm2' => null, 'cod_provee' => null, 'operador' => null];
            $cortada = array_filter(is_array($facturas) ? $facturas : [], function ($f) use ($o) {
                return $f['concepto'] === self::OBRA && (int) $f['nro'] === (int) $o['nro'] && ($f['mes'] ?? null) === $o['mes'];
            });

            if (!empty($cortada)) {
                $s['cortados'][] = ['nro' => $o['nro'], 'concepto' => self::OBRA, 'clave' => $o['nro'] . '|' . $o['mes'],
                                    'por' => 'FACTURA'];
                continue;
            }

            $segM2 = self::segmento($l['m2'] ?? null, $gen['CORTE_CHICO'] ?? null, $gen['CORTE_MEDIANO'] ?? null);
            $seg = $o['segmento'] ?? $segM2;

            if ($seg === null) {
                if (($l['m2'] ?? null) !== null && (($gen['CORTE_CHICO'] ?? null) === null || ($gen['CORTE_MEDIANO'] ?? null) === null)) {
                    $s['f']['sin_cortes'] = true;
                } else {
                    $s['f']['sin_segmento'][] = $l['nombre'];
                }

                $s['no_proyecta'][] = ['nro' => $o['nro'], 'local' => $l['nombre'], 'concepto' => self::OBRA,
                                       'motivo' => 'sin segmento', 'importe' => null];
                continue;
            }

            self::enDolares($s, self::OBRA, $l, $o, $seg, $o['segmento'] !== null ? 'cargado a mano'
                : ('por sus ' . self::m2($l['m2']) . ' m²'), $gen, $e);
        }
    }

    private static function aperturas($e, $gen, &$s) {
        foreach ($e['par']['aperturas'] ?? [] as $a) {
            $l = ['nro' => $a['nro'], 'nombre' => $a['nombre'], 'cod_provee' => null, 'operador' => null, 'apertura' => $a['id']];
            self::enDolares($s, self::APERTURA, $l, $a, $a['segmento'], 'cargado a mano', $gen, $e);
        }
    }

    /** Un pago en dolares (obra o apertura), con la curva del mes de su fecha. */
    private static function enDolares(&$s, $concepto, $l, $x, $seg, $deSeg, $gen, $e) {
        $tipo = ($concepto === self::OBRA) ? 'OBRA' : 'APERTURA';
        $usd = $x['costo_usd'] ?? ($gen['USD_' . $tipo . '_' . $seg] ?? null);
        $fecha = self::diaDelMes($x['mes'], (int) ($gen['DIA_PAGO'] ?? 15), $e['habiles'] ?? []);

        if ($usd === null) {
            $s['f']['sin_costo'][$tipo . ' ' . strtolower($seg)] = true;
            $s['no_proyecta'][] = ['nro' => $l['nro'], 'local' => $l['nombre'], 'concepto' => $concepto,
                                   'motivo' => 'segmento sin costo en U$S', 'importe' => null];

            return;
        }

        if ($fecha['fecha'] <= $e['hoy']) {
            return;
        }

        $r = DolarFuturo::resolver($e['curva'] ?? [], $fecha['fecha']);

        if ($r['cotizacion'] === null) {
            $s['f']['sin_dolar'][] = $l['nombre'] . ' (' . self::NOMBRE_CONCEPTO[$concepto] . ', ' . $x['mes'] . ', U$S '
                . number_format($usd, 2, ',', '.') . ')';
            $s['no_proyecta'][] = ['nro' => $l['nro'], 'local' => $l['nombre'], 'concepto' => $concepto,
                                   'motivo' => 'sin dólar futuro para ' . $x['mes'], 'importe' => null, 'usd' => $usd];

            return;
        }

        $importe = round($usd * $r['cotizacion'], 2);
        $aprox = ($r['origen'] === DolarFuturo::ORIGEN_APROXIMADA);
        $s['pagos'][] = self::pago($concepto, self::ESTIMADO, $l, $fecha['fecha'], $importe, [
            'usd' => $usd, 'tc' => $r['cotizacion'], 'tc_aproximado' => $aprox, 'segmento' => $seg,
            'clave' => ($concepto === self::OBRA) ? $l['nro'] . '|' . $x['mes'] : 'A' . $x['id'],
            'id_obra' => ($concepto === self::OBRA) ? $x['id'] : null, 'id_apertura' => ($concepto === self::APERTURA) ? $x['id'] : null,
            'detalle' => self::NOMBRE_CONCEPTO[$concepto] . ' ' . ($l['nombre']) . ': U$S ' . number_format($usd, 2, ',', '.')
                . (($x['costo_usd'] ?? null) !== null ? ' (costo propio)' : ' (segmento ' . strtolower($seg) . ', ' . $deSeg . ')')
                . ' × dólar futuro ' . $r['simbolo'] . ' ' . number_format($r['cotizacion'], 2, ',', '.') . ' (' . $r['mes_curva'] . ')'
                . ($aprox ? ', APROXIMADO: la curva no llega a ' . $r['mes_pago'] . ' y se usa el mes más cercano' : '')
                . ' = ' . self::plata($importe) . '. ' . self::notaFecha($fecha)
                . (empty($x['observacion']) ? '' : ' ' . $x['observacion'])
        ]);
    }

    /** Los compromisos a mano: su fecha y su monto, tal cual. */
    private static function compromisos($e, &$s) {
        foreach ($e['par']['compromisos'] ?? [] as $c) {
            $nombre = ($c['nro'] !== null) ? ($e['locales_nombres'][$c['nro']] ?? ('Local ' . $c['nro'])) : null;
            $op = $e['operadores'][$c['cod_provee']] ?? $c['cod_provee'];
            $l = ['nro' => $c['nro'], 'nombre' => $nombre, 'cod_provee' => $c['cod_provee'], 'operador' => $op];
            $s['pagos'][] = self::pago(self::COMPROMISO, self::MANUAL, $l, $c['fecha'], round($c['monto'], 2), [
                'id_compromiso' => $c['id'], 'id_serie' => $c['id_serie'], 'cuota' => $c['cuota_nro'], 'cuotas' => $c['cuotas'],
                'concepto_texto' => $c['concepto'], 'forma' => $c['forma'], 'clave' => 'C' . $c['id'],
                'detalle' => $c['concepto'] . ' (' . $op . ($nombre !== null ? ', ' . $nombre : '') . ')'
                    . ($c['cuotas'] ? ', cuota ' . $c['cuota_nro'] . ' de ' . $c['cuotas'] : '') . ': '
                    . self::plata($c['monto']) . ' cargado a mano'
                    . ($c['forma'] ? ', ' . (LlavesParametros::FORMAS[$c['forma']] ?? $c['forma']) : '') . '.'
                    . (empty($c['observacion']) ? '' : ' ' . $c['observacion'])
            ]);
        }
    }

    /** Los pagos de las facturas imputadas que siguen pendientes. Las pagadas no suman. */
    private static function pagosDeFacturas($e, &$s) {
        foreach ($e['facturas'] ?? [] as $f) {
            if (!empty($f['pagada'])) {
                continue;
            }

            $l = $e['locales'][$f['nro']] ?? ['nro' => $f['nro'], 'nombre' => $e['locales_nombres'][$f['nro']] ?? ('Local ' . $f['nro'])];
            $l += ['cod_provee' => $f['cod_provee'], 'operador' => null];
            $l['cod_provee'] = $f['cod_provee'];
            $n = count($f['pagos']);

            foreach (array_values($f['pagos']) as $i => $p) {
                $s['pagos'][] = self::pago($f['concepto'], self::FACTURA, $l, $p['fecha'], round($p['importe'], 2), [
                    'cuota' => $i + 1, 'cuotas' => $n, 'clave' => 'F' . $f['id'], 'id_factura' => $f['id'],
                    'detalle' => 'Factura ' . $f['comprobante'] . ' (' . $f['cod_provee'] . ') imputada a '
                        . self::NOMBRE_CONCEPTO[$f['concepto']] . ($n > 1 ? ', pago ' . ($i + 1) . ' de ' . $n : '') . ': '
                        . self::plata($p['importe']) . '. ' . ($p['nota'] ?? '') . ' ' . ($f['texto'] ?? '')
                ]);
            }
        }
    }

    /* ====================================================================
       LOS AVISOS
       ==================================================================== */

    /** Cada hecho, un aviso aparte. */
    private static function avisos($f, $hoy) {
        $out = [];
        $lista = function ($xs) { return implode(', ', $xs); };

        if ($f['sin_tablas']) {
            $out[] = Aviso::nuevo(Aviso::DANGER, 'Faltan las tablas de Parámetros › Llaves y Renovaciones: hay que correr '
                . 'sql/cashflow_llaves.sql contra la base central. Sin ellas se proyectan sólo las llaves de los contratos '
                . 'cargados en la app si el operador tiene % de impuestos, que tampoco se puede cargar: no se proyecta nada.',
                'Parámetros');
        }

        foreach ($f['vencidos'] as $v) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'El contrato de ' . $v['nombre'] . ' venció el ' . self::corta($v['hasta'])
                . '; si se renovó, cargá el contrato nuevo en la app de alquileres. Mientras tanto se estima su renovación '
                . 'con la primera cuota en el mes en curso (si su contrato tiene llave), o marcalo como «No renueva» en '
                . 'Parámetros › Llaves y Renovaciones.', 'Contratos');
        }

        if (!empty($f['vencen'])) {
            $out[] = Aviso::nuevo(Aviso::INFO, 'Contratos que vencen dentro del horizonte: ' . $lista(array_map(function ($v) {
                return $v['nombre'] . ' (' . self::corta($v['hasta']) . ')';
            }, $f['vencen'])) . '. Se estima su renovación hasta que se cargue el contrato nuevo en la app.', 'Contratos');
        }

        if (!empty($f['sin_contrato'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Sin ningún contrato en la app de alquileres: ' . $lista($f['sin_contrato'])
                . '. No se proyecta su llave ni su renovación; se carga el contrato en la app de alquileres.', 'Contratos');
        }

        if (!empty($f['sin_razon'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Sin razón social en Parámetros › Alquileres: ' . $lista($f['sin_razon'])
                . '. Sin operador no se proyecta su llave ni sus otros gastos: se carga en Parámetros › Alquileres › Locales.',
                'Contratos');
        }

        if (!empty($f['sin_alquiler'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Sin alquiler proyectado en Alquileres para el mes de la primera cuota: '
                . $lista($f['sin_alquiler']) . '. No se estima esa llave: el mes cae fuera del horizonte de Alquileres o el '
                . 'local no proyecta ahí (revisalo en Parámetros › Alquileres), o se carga la llave a mano en Parámetros › '
                . 'Llaves y Renovaciones.', 'Proyección');
        }

        foreach ($f['sin_impuestos'] as $op => $locales) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'El operador ' . $op . ' no tiene % de impuestos sobre llave y otros '
                . 'gastos: no se proyecta la llave ni los otros gastos de ' . $lista(array_unique($locales)) . '. Se carga '
                . 'en Parámetros › Llaves y Renovaciones › Por operador.', 'Parámetros');
        }

        if (!empty($f['sin_retencion'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Sin % de retención en Parámetros › Alquileres: '
                . $lista(array_unique($f['sin_retencion'])) . '. No se proyecta su llave ni sus otros gastos.', 'Parámetros');
        }

        if (!empty($f['sin_pct_otros'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Operador sin % de otros gastos sobre la llave: '
                . $lista(array_keys($f['sin_pct_otros'])) . '. Sus otros gastos estimados no suman ese %: se carga en '
                . 'Parámetros › Llaves y Renovaciones › Por operador.', 'Parámetros');
        }

        if (!empty($f['sin_escribano'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Operador sin escribano y otros por renovación: '
                . $lista(array_keys($f['sin_escribano'])) . '. Se proyecta la parte del % y el escribano no se suma; si es '
                . '0, cargá 0 en Parámetros › Llaves y Renovaciones › Por operador.', 'Parámetros');
        }

        if (!empty($f['meses_cortos'])) {
            $out[] = Aviso::nuevo(Aviso::INFO, 'Sin un contrato anterior con llave, la renovación se estima con los meses '
                . 'del contrato vigente, que son menos de 12: ' . $lista($f['meses_cortos']) . '. Si el contrato nuevo es '
                . 'más largo, cargá los meses en Parámetros › Llaves y Renovaciones › Por local.', 'Proyección');
        }

        if (!empty($f['pasadas'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Cuotas de contratos firmados que ya pasaron sin factura ni marca de '
                . 'pagada: ' . implode('; ', $f['pasadas']) . '. Pueden estar sin pagar o sin factura: si ya se pagó, '
                . 'marcalo «Ya pagada» en Parámetros o imputá la factura como llave en Alquileres › Facturas.', 'Proyección');
        }

        if ($f['sin_cortes']) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Faltan confirmar los cortes de segmento por m² en Parámetros › Llaves y '
                . 'Renovaciones › Generales: las obras sin segmento a mano no se proyectan.', 'Parámetros');
        }

        if (!empty($f['sin_segmento'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Local sin m² ni segmento: ' . $lista(array_unique($f['sin_segmento']))
                . '. Su obra no se proyecta: cargá los m² en el Gestor de Locales o el segmento a mano en la obra.',
                'Parámetros');
        }

        if (!empty($f['sin_costo'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Segmento sin costo en U$S: ' . $lista(array_keys($f['sin_costo']))
                . '. Esas obras o aperturas no se proyectan: se carga en Parámetros › Llaves y Renovaciones › Generales, o '
                . 'un costo propio en cada una.', 'Parámetros');
        }

        if (!empty($f['sin_dolar'])) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Sin dólar futuro para el mes: ' . $lista($f['sin_dolar'])
                . '. No se proyectan en pesos.', 'Proyección');
        }

        return $out;
    }

    /* ====================================================================
       AUXILIARES
       ==================================================================== */

    /** Un pago, con la forma que leen la pestaña y el tablero. */
    private static function pago($concepto, $origen, $l, $fecha, $importe, $extra) {
        return $extra + [
            'concepto' => $concepto,
            'origen' => $origen,
            'nro' => $l['nro'] ?? null,
            'local' => $l['nombre'] ?? null,
            'cod_provee' => $l['cod_provee'] ?? null,
            'operador' => $l['operador'] ?? null,
            'fecha' => $fecha,
            'mes' => substr($fecha, 0, 7),
            'importe' => $importe,
            'neto' => null, 'impuestos' => null, 'retencion' => null, 'escribano' => null,
            'cuota' => null, 'cuotas' => null
        ];
    }

    private static function lineaImpuestos($imp, $pctImp, $pctRet) {
        return 'Neto ' . self::plata($imp['neto']) . ' + ' . self::pct($pctImp) . ' de impuestos del operador '
            . self::plata($imp['impuestos']) . ' − ' . self::pct($pctRet) . ' de retención del local '
            . self::plata($imp['retencion']) . ' = a pagar ' . self::plata($imp['a_pagar']) . '.';
    }

    private static function notaFecha($d) {
        if (!empty($d['corrida'])) {
            return ' El ' . self::corta($d['pedido']) . ' no es hábil: pasa al ' . self::corta($d['fecha']) . '.';
        }

        return '';
    }

    public static function plata($n) {
        return '$ ' . number_format((float) $n, 0, ',', '.');
    }

    private static function pct($n) {
        return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',') . '%';
    }

    private static function m2($n) {
        return number_format((float) $n, 2, ',', '.');
    }

    public static function corta($fecha) {
        $f = substr((string) $fecha, 0, 10);

        return (strlen($f) === 10) ? substr($f, 8, 2) . '/' . substr($f, 5, 2) . '/' . substr($f, 0, 4) : $f;
    }
}
