<?php

require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/PrestamosTasas.php';

/**
 * PrestamosCalculo
 * LA regla de los prestamos, pura: con el prestamo, sus costos, sus
 * correcciones, sus cancelaciones, las tasas, los dias habiles y la curva de
 * dolar ya leidos, devuelve el cronograma cuota por cuota y la LISTA DE PAGOS
 * que suman la pestaña y el tablero. Ninguno de los dos recalcula nada, y una
 * prueba fija que sumen lo mismo, columna por columna.
 *
 * LAS FECHAS (README-prestamos.md, parte 3)
 * -----------------------------------------
 * Cuotas = periodos de CAPITAL. Plazo = cuotas x frecuencia de capital, y tiene
 * que ser multiplo de la de intereses. El calendario contractual va de a la
 * frecuencia mas corta: el primer vencimiento es "la fecha de la primera
 * cuota" (por defecto, desembolso + esa frecuencia) y los siguientes suman
 * meses desde ahi, al fin de mes si el dia no existe. Cada vencimiento lleva
 * capital, intereses o los dos segun el mes en que cae.
 *
 * Un vencimiento en fin de semana o feriado SE PAGA el habil siguiente, pero
 * los dias de interes se cuentan entre las fechas CONTRACTUALES.
 *
 * LOS INTERESES
 * -------------
 * saldo x TNA x dias exactos / 365, entre el vencimiento anterior (o el
 * desembolso) y el de la cuota. Con capital mas frecuente que los intereses el
 * saldo cambia adentro del periodo: el interes se devenga por tramos y se paga
 * junto en el vencimiento de intereses. La TNA es la del periodo de intereses:
 * fija, o tasa base + spread (PrestamosTasas::base()), y una correccion de la
 * cuota gana sobre todo.
 *
 * LA AMORTIZACION
 * ---------------
 *   Aleman   capital fijo = capital / (cuotas - gracia), desde la gracia + 1.
 *   Frances  cuota constante con la tasa del periodo NOMINAL (TNA x 30, 90, 180
 *            o 365 / 365) sobre los periodos que quedan; el interes va por dias
 *            exactos y el capital es cuota - interes. A tasa variable la cuota
 *            se recalcula al inicio de cada periodo con la TNA de ese periodo.
 *   Bullet   todo el capital en la ultima cuota.
 * Gracia parcial: las primeras G cuotas pagan solo intereses. LA ULTIMA CUOTA
 * CIERRA EL CAPITAL EXACTO, salvo que su capital este corregido a mano (y ahi,
 * si no cierra, se avisa).
 *
 * LOS COSTOS
 * ----------
 * Iniciales: % del capital, el dia del desembolso. Por cuota: % del interes de
 * la cuota (base INTERESES) o del saldo al inicio del periodo (base CAPITAL).
 * Con IVA, la alicuota general sobre el costo. En el tablero van a "Intereses y
 * gastos"; el desembolso va bruto.
 *
 * LAS CANCELACIONES
 * -----------------
 * Total: saldo + intereses devengados hasta ese dia + comision + su IVA + los
 * costos por cuota sobre esos intereses; las cuotas posteriores no van. Parcial:
 * lo adelantado + devengado + comision + IVA, y el cronograma se rearma
 * reduciendo la cuota (mismo plazo) o el plazo (misma cuota). LAS CORRECCIONES
 * DE CUOTAS POSTERIORES A UNA PARCIAL NO SE APLICAN (avisa y vuelven si se
 * deshace la cancelacion).
 *
 * REAL Y PROYECTADO: un pago con fecha de pago anterior o igual a hoy es real.
 */
class PrestamosCalculo {

    const FRANCES = 'FRANCES';
    const ALEMAN = 'ALEMAN';
    const BULLET = 'BULLET';
    const SISTEMAS = [self::FRANCES => 'Francés', self::ALEMAN => 'Alemán', self::BULLET => 'Bullet'];

    /** Frecuencias, en meses */
    const FRECUENCIAS = ['MENSUAL' => 1, 'TRIMESTRAL' => 3, 'SEMESTRAL' => 6, 'ANUAL' => 12];
    const NOMBRE_FRECUENCIA = ['MENSUAL' => 'Mensual', 'TRIMESTRAL' => 'Trimestral', 'SEMESTRAL' => 'Semestral', 'ANUAL' => 'Anual'];

    /** Dias nominales del periodo, para la cuota francesa y la TEA */
    const DIAS_NOMINALES = [1 => 30, 3 => 90, 6 => 180, 12 => 365];

    const FIJA = 'FIJA';
    const VARIABLE = 'VARIABLE';

    const SIMULADO = 'SIMULADO';
    const VIGENTE = 'VIGENTE';
    const CANCELADO = 'CANCELADO';
    const FINALIZADO = 'FINALIZADO';
    const NOMBRE_ESTADO = [self::SIMULADO => 'Simulado', self::VIGENTE => 'Vigente',
                           self::CANCELADO => 'Cancelado', self::FINALIZADO => 'Finalizado'];

    /** Los conceptos del tablero */
    const DESEMBOLSO = 'DESEMBOLSO';
    const CAPITAL = 'CAPITAL';
    const INTERESES = 'INTERESES';
    const NOMBRE_CONCEPTO = [self::DESEMBOLSO => 'Desembolso', self::CAPITAL => 'Amortización de capital',
                             self::INTERESES => 'Intereses y gastos'];

    /** Los tipos de renglon del cronograma */
    const R_DESEMBOLSO = 'DESEMBOLSO';
    const R_CUOTA = 'CUOTA';
    const R_CANCELACION = 'CANCELACION';

    const INICIAL = 'INICIAL';
    const POR_CUOTA = 'POR_CUOTA';
    const BASE_CAPITAL = 'CAPITAL';
    const BASE_INTERESES = 'INTERESES';

    const TOTAL = 'TOTAL';
    const PARCIAL = 'PARCIAL';
    const REDUCIR_CUOTA = 'REDUCIR_CUOTA';
    const REDUCIR_PLAZO = 'REDUCIR_PLAZO';
    const COMISION_FIJA = 'FIJA';
    const COMISION_ESCALONADA = 'ESCALONADA';

    /** Lo que se corrige a mano en una cuota: capital, interes, TNA o un costo (COSTO:<id>) */
    const AJ_CAPITAL = 'CAPITAL';
    const AJ_INTERES = 'INTERES';
    const AJ_TNA = 'TNA';
    const AJ_COSTO = 'COSTO:';

    /* ====================================================================
       FECHAS
       ==================================================================== */

    /** Meses de una frecuencia ('MENSUAL' o 1, 3, 6, 12). */
    public static function meses($frecuencia) {
        if (isset(self::FRECUENCIAS[$frecuencia])) {
            return self::FRECUENCIAS[$frecuencia];
        }

        $n = (int) $frecuencia;

        return in_array($n, self::FRECUENCIAS, true) ? $n : null;
    }

    /** Suma N meses a una fecha y se queda con el dia $dia, o con el fin de mes si ese dia no existe. */
    public static function sumarMeses($fecha, $n, $dia = null) {
        $t = strtotime(substr((string) $fecha, 0, 10));
        $y = (int) date('Y', $t);
        $m = (int) date('n', $t) + (int) $n;
        $dia = ($dia === null) ? (int) date('j', $t) : (int) $dia;

        $y += (int) floor(($m - 1) / 12);
        $m = (($m - 1) % 12 + 12) % 12 + 1;
        $ultimo = (int) date('t', mktime(0, 0, 0, $m, 1, $y));

        return sprintf('%04d-%02d-%02d', $y, $m, min($dia, $ultimo));
    }

    /** Dias corridos entre dos fechas 'Y-m-d'. */
    public static function dias($desde, $hasta) {
        $a = new DateTime(substr((string) $desde, 0, 10));
        $b = new DateTime(substr((string) $hasta, 0, 10));
        $d = (int) $a->diff($b)->days;

        return ($b < $a) ? -$d : $d;
    }

    /**
     * El calendario contractual: un vencimiento por cada paso de la frecuencia
     * mas corta, con que lleva cada uno.
     *
     * @param array $p El prestamo (ver normalizar())
     * @return array [['k', 'fecha', 'mes' (meses desde el desembolso), 'capital' (bool),
     *                 'interes' (bool), 'j' (nro de periodo de capital o null)]]
     */
    public static function calendario($p) {
        $fc = $p['frec_capital'];
        $fi = $p['frec_intereses'];
        $min = min($fc, $fi);
        $plazo = $p['cuotas'] * $fc;
        $primera = $p['fecha_primera'] ?: self::sumarMeses($p['fecha_desembolso'], $min);
        $dia = (int) date('j', strtotime($primera));
        $out = [];

        for ($k = 1; $k * $min <= $plazo; $k++) {
            $mes = $k * $min;
            $out[] = [
                'k' => $k,
                'fecha' => ($k === 1) ? $primera : self::sumarMeses($primera, ($k - 1) * $min, $dia),
                'mes' => $mes,
                'capital' => ($mes % $fc === 0),
                'interes' => ($mes % $fi === 0),
                'j' => ($mes % $fc === 0) ? intdiv($mes, $fc) : null
            ];
        }

        return $out;
    }

    /* ====================================================================
       FORMULAS
       ==================================================================== */

    /** saldo x TNA x dias / 365, sin redondear. $tna en decimal. */
    public static function interes($saldo, $tna, $dias) {
        return $saldo * $tna * $dias / 365;
    }

    /** TEA = (1 + TNA x d / 365)^(365 / d) - 1, con d los dias nominales. $tna en decimal. */
    public static function tea($tna, $d) {
        if ($tna === null || $d <= 0) {
            return null;
        }

        return pow(1 + $tna * $d / 365, 365 / $d) - 1;
    }

    /** La cuota francesa de un saldo a una tasa por periodo, en n periodos. */
    public static function cuotaFrancesa($saldo, $i, $n) {
        if ($n <= 0) {
            return $saldo;
        }

        if (abs($i) < 1e-12) {
            return $saldo / $n;
        }

        return $saldo * $i / (1 - pow(1 + $i, -$n));
    }

    /**
     * El costo financiero total: la tasa efectiva anual que iguala el
     * desembolso con todos los pagos. Biseccion sobre el valor actual con
     * exponente dias / 365.
     *
     * @param array $flujos [['fecha', 'importe' (+ entra, - sale)]], el primero es el desembolso
     * @return float|null
     */
    public static function cft($flujos) {
        if (count($flujos) < 2) {
            return null;
        }

        $t0 = $flujos[0]['fecha'];
        $van = function ($r) use ($flujos, $t0) {
            $s = 0.0;

            foreach ($flujos as $f) {
                $s += $f['importe'] / pow(1 + $r, self::dias($t0, $f['fecha']) / 365);
            }

            return $s;
        };

        $lo = -0.99;
        $hi = 50.0;
        $vlo = $van($lo);
        $vhi = $van($hi);

        if ($vlo * $vhi > 0) {
            return null;
        }

        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $vm = $van($mid);

            if (abs($vm) < 1e-7) {
                break;
            }

            if ($vlo * $vm < 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
                $vlo = $vm;
            }
        }

        return round(($lo + $hi) / 2, 8);
    }

    /**
     * El % de la comision de cancelacion a una fecha: el fijo, o el del tramo
     * del plazo transcurrido (el de mayor "desde" que no lo supere).
     *
     * @param array $c ['modo', 'pct_total', 'pct_parcial', 'iva', 'tramos' => [['desde', 'pct_total', 'pct_parcial']]]
     * @param string $tipo TOTAL | PARCIAL
     * @param float $transcurrido % del plazo transcurrido (0 a 100)
     * @return float % (no decimal)
     */
    public static function pctComision($c, $tipo, $transcurrido) {
        $campo = ($tipo === self::TOTAL) ? 'pct_total' : 'pct_parcial';

        if (($c['modo'] ?? self::COMISION_FIJA) === self::COMISION_ESCALONADA && !empty($c['tramos'])) {
            $tramos = $c['tramos'];
            usort($tramos, function ($a, $b) { return $a['desde'] <=> $b['desde']; });
            $pct = 0.0;

            foreach ($tramos as $t) {
                if ((float) $t['desde'] <= $transcurrido + 1e-9) {
                    $pct = (float) $t[$campo];
                }
            }

            return $pct;
        }

        return (float) ($c[$campo] ?? 0);
    }

    /* ====================================================================
       NORMALIZACION DE LA ENTRADA
       ==================================================================== */

    /**
     * El prestamo con los tipos en su lugar. Los datos ya vienen validados
     * (PrestamosParametros::validarPrestamo()); esto solo guarda lo que
     * romperia la cuenta.
     */
    public static function normalizar($p) {
        $n = [
            'id' => $p['id'] ?? null,
            'nombre' => (string) ($p['nombre'] ?? ''),
            'moneda' => (strtoupper((string) ($p['moneda'] ?? 'ARS')) === 'USD') ? 'USD' : 'ARS',
            'capital' => (float) ($p['capital'] ?? 0),
            'fecha_desembolso' => substr((string) ($p['fecha_desembolso'] ?? ''), 0, 10),
            'tipo_tasa' => (strtoupper((string) ($p['tipo_tasa'] ?? self::FIJA)) === self::VARIABLE) ? self::VARIABLE : self::FIJA,
            'tna' => isset($p['tna']) && $p['tna'] !== null && $p['tna'] !== '' ? (float) $p['tna'] : null,
            'referencia' => strtoupper((string) ($p['referencia'] ?? '')),
            'spread' => (float) ($p['spread'] ?? 0),
            'criterio_tasa' => (strtoupper((string) ($p['criterio_tasa'] ?? '')) === PrestamosTasas::ULTIMO)
                ? PrestamosTasas::ULTIMO : PrestamosTasas::PROMEDIO,
            'sistema' => strtoupper((string) ($p['sistema'] ?? self::FRANCES)),
            'cuotas' => (int) ($p['cuotas'] ?? 0),
            'frec_capital' => self::meses($p['frec_capital'] ?? 1),
            'frec_intereses' => self::meses(($p['frec_intereses'] ?? null) ?: ($p['frec_capital'] ?? 1)),
            'fecha_primera' => !empty($p['fecha_primera']) ? substr((string) $p['fecha_primera'], 0, 10) : null,
            'gracia' => (int) ($p['gracia'] ?? 0),
            'estado' => strtoupper((string) ($p['estado'] ?? self::VIGENTE)),
            'cancelacion' => $p['cancelacion'] ?? ['modo' => self::COMISION_FIJA, 'pct_total' => 0, 'pct_parcial' => 0, 'iva' => false, 'tramos' => []]
        ];

        if ($n['frec_capital'] === null || $n['frec_intereses'] === null) {
            throw new InvalidArgumentException('Frecuencia inválida.');
        }

        if ($n['cuotas'] < 1 || $n['capital'] <= 0 || $n['fecha_desembolso'] === '') {
            throw new InvalidArgumentException('Préstamo incompleto: capital, desembolso y cuotas.');
        }

        if (($n['cuotas'] * $n['frec_capital']) % $n['frec_intereses'] !== 0) {
            throw new InvalidArgumentException('El plazo no es múltiplo de la frecuencia de intereses.');
        }

        if ($n['sistema'] === self::FRANCES && $n['frec_capital'] !== $n['frec_intereses']) {
            throw new InvalidArgumentException('El sistema francés pide capital e intereses con la misma frecuencia.');
        }

        return $n;
    }

    /* ====================================================================
       EL CRONOGRAMA
       ==================================================================== */

    /**
     * El cronograma y la lista de pagos de un prestamo.
     *
     * @param array $e [
     *   'hoy'           'Y-m-d'
     *   'prestamo'      ver normalizar()
     *   'costos'        [['id', 'nombre', 'pct', 'base', 'momento', 'iva' (bool)]] los del prestamo
     *   'iva'           alicuota general, decimal (0.21)
     *   'ajustes'       [nro cuota => [concepto => ['importe', 'usuario', 'fecha']]]
     *   'cancelaciones' [['id', 'fecha', 'tipo', 'monto', 'modo']] las vigentes
     *   'tasas'         ['series' => [ref => ['Y-m-d' => %]], 'disponible' => bool,
     *                    'n' => dias del promedio, 'manual' => [ref => ['Y-m' => %]]]
     *   'habiles'       'Y-m-d' => bool
     *   'curva'         la de DolarFuturo::curva(), para un prestamo en USD
     * ]
     * @return array ['cuotas', 'pagos', 'resumen', 'avisos', 'proyectable' (bool)]
     */
    public static function proyectar($e) {
        $p = self::normalizar($e['prestamo']);
        $hoy = substr((string) $e['hoy'], 0, 10);
        $iva = (float) ($e['iva'] ?? 0.21);
        $costos = array_values($e['costos'] ?? []);
        $habiles = $e['habiles'] ?? [];
        $nombre = '«' . $p['nombre'] . '»';
        $avisos = [];

        $cal = self::calendario($p);
        $ultimaFecha = end($cal)['fecha'];

        /* ---- Las cancelaciones, en orden. Lo posterior a una total no va. */
        $cancel = array_values(array_filter($e['cancelaciones'] ?? [], function ($c) use ($p, $ultimaFecha) {
            $f = substr((string) $c['fecha'], 0, 10);

            return $f > $p['fecha_desembolso'] && $f <= $ultimaFecha;
        }));
        usort($cancel, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']); });
        $hayTotal = null;

        foreach ($cancel as $i => $c) {
            if ($hayTotal !== null) {
                unset($cancel[$i]);
            } elseif (strtoupper($c['tipo']) === self::TOTAL) {
                $hayTotal = substr($c['fecha'], 0, 10);
            }
        }

        $cancel = array_values($cancel);
        $primeraParcial = null;

        foreach ($cancel as $c) {
            if (strtoupper($c['tipo']) === self::PARCIAL) {
                $primeraParcial = substr($c['fecha'], 0, 10);
                break;
            }
        }

        /* ---- Las correcciones: las de cuotas posteriores a una parcial no van. */
        $ajustes = [];
        $ignoradas = [];

        foreach ($cal as $v) {
            if (empty($e['ajustes'][$v['k']])) {
                continue;
            }

            if ($primeraParcial !== null && $v['fecha'] > $primeraParcial) {
                $ignoradas[] = $v['k'];
                continue;
            }

            $ajustes[$v['k']] = $e['ajustes'][$v['k']];
        }

        if (!empty($ignoradas)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $nombre . ': las correcciones de las cuotas '
                . implode(', ', $ignoradas) . ' no se aplican porque la cancelación parcial del '
                . self::corta($primeraParcial) . ' rearmó el cronograma. Revisalas contra el del banco; '
                . 'vuelven si se deshace la cancelación.');
        }

        /* ---- La TNA de cada periodo de intereses ------------------------ */
        $periodos = [];
        $inicio = $p['fecha_desembolso'];

        foreach ($cal as $v) {
            if ($v['interes']) {
                $periodos[] = ['inicio' => $inicio, 'fin_k' => $v['k'], 'fin' => $v['fecha']];
                $inicio = $v['fecha'];
            }
        }

        $viejos = [];

        foreach ($periodos as &$per) {
            $aj = $ajustes[$per['fin_k']][self::AJ_TNA] ?? null;

            if ($aj !== null) {
                $per['tna'] = (float) $aj['importe'];
                $per['origen'] = PrestamosTasas::O_CORREGIDA;
                $per['detalle'] = 'TNA corregida a mano';
            } elseif ($p['tipo_tasa'] === self::FIJA) {
                $per['tna'] = $p['tna'];
                $per['origen'] = PrestamosTasas::O_FIJA;
                $per['detalle'] = 'tasa fija';
            } else {
                $ref = $p['referencia'];
                $t = $e['tasas'] ?? [];
                $b = empty($t['disponible']) ? ['valor' => null]
                    : PrestamosTasas::base($t['series'][$ref] ?? [], $per['inicio'], $hoy, $p['criterio_tasa'],
                        $t['n'] ?? PrestamosTasas::DIAS_PROMEDIO_INICIAL, $t['manual'][$ref] ?? []);

                if ($b['valor'] === null) {
                    $avisos[] = Aviso::nuevo(Aviso::DANGER, $nombre . ' no se proyecta: no hay valor de '
                        . (PrestamosTasas::NOMBRES[$ref] ?? $ref) . ' para calcular su tasa.');

                    return ['cuotas' => [], 'pagos' => [], 'resumen' => self::resumenVacio($p, $hoy),
                            'avisos' => $avisos, 'proyectable' => false];
                }

                $per['tna'] = round($b['valor'] + $p['spread'], 6);
                $per['origen'] = $b['origen'];
                $per['detalle'] = (PrestamosTasas::NOMBRES[$ref] ?? $ref) . ' ' . self::pct($b['valor']) . ' (' . $b['detalle']
                    . ') + spread ' . self::pct($p['spread']);

                if ($b['aviso'] !== null) {
                    $viejos[] = $per['fin_k'];
                }
            }
        }

        unset($per);

        if (!empty($viejos)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $nombre . ': para las cuotas ' . implode(', ', $viejos)
                . ' no hay suficientes publicaciones de ' . (PrestamosTasas::NOMBRES[$p['referencia']] ?? $p['referencia'])
                . ' antes del inicio del período; se usa el promedio más viejo disponible.');
        }

        $perDeK = [];

        foreach ($cal as $v) {
            foreach ($periodos as $ix => $per) {
                if ($v['k'] <= $per['fin_k']) {
                    $perDeK[$v['k']] = $ix;
                    break;
                }
            }
        }

        /* ---- El recorrido ------------------------------------------------ */
        $fc = $p['frec_capital'];
        $dnom = self::DIAS_NOMINALES[$fc];
        $G = $p['gracia'];
        $C = $p['capital'];
        $saldo = $C;
        $prev = $p['fecha_desembolso'];
        $acum = 0.0;
        $jHecho = 0;
        $fijoAleman = round($C / max(1, $p['cuotas'] - $G), 2);
        $cuotaFr = null;
        $recalcular = false;
        $modoPlazo = false;
        $filas = [];

        // El desembolso y los costos iniciales.
        $iniciales = [];

        foreach ($costos as $c) {
            if (($c['momento'] ?? '') === self::INICIAL && ($c['base'] ?? '') === self::BASE_CAPITAL) {
                $imp = round($C * (float) $c['pct'] / 100, 2);
                $iniciales[$c['id']] = round($imp + (!empty($c['iva']) ? $imp * $iva : 0), 2);
            }
        }

        $filas[] = self::fila(self::R_DESEMBOLSO, 0, $p['fecha_desembolso'], ['fecha' => $p['fecha_desembolso'], 'corrida' => false],
            0, null, null, null, 0.0, 0.0, 0.0, $iniciales, $hoy, ['desembolso' => $C]);

        // Los eventos: vencimientos y cancelaciones, en orden. A igual fecha, el vencimiento primero.
        $eventos = [];

        foreach ($cal as $v) {
            $eventos[] = ['fecha' => $v['fecha'], 'orden' => 0, 'v' => $v];
        }

        foreach ($cancel as $c) {
            $eventos[] = ['fecha' => substr($c['fecha'], 0, 10), 'orden' => 1, 'c' => $c];
        }

        usort($eventos, function ($a, $b) {
            return strcmp($a['fecha'], $b['fecha']) ?: ($a['orden'] <=> $b['orden']);
        });

        $restantes = function () use ($cal, &$jHecho, $G) {
            $n = 0;

            foreach ($cal as $v) {
                if ($v['capital'] && $v['j'] > $jHecho && $v['j'] > $G) {
                    $n++;
                }
            }

            return $n;
        };

        $variable = ($p['tipo_tasa'] === self::VARIABLE);
        $terminado = false;

        foreach ($eventos as $ev) {
            if ($terminado || $saldo <= 0.005) {
                break;
            }

            $fecha = $ev['fecha'];

            if (isset($ev['c'])) {
                /* ---- Una cancelacion -------------------------------------- */
                $c = $ev['c'];
                $tipo = strtoupper($c['tipo']);
                $ixPer = null;

                foreach ($periodos as $ix => $per) {
                    if ($fecha <= $per['fin']) {
                        $ixPer = $ix;
                        break;
                    }
                }

                $tna = $periodos[$ixPer]['tna'];
                $dias = self::dias($prev, $fecha);
                $acum += self::interes($saldo, PrestamosTasas::tnaDecimal($tna), $dias);
                $devengado = round($acum, 2);
                $acum = 0.0;
                $saldoIni = $saldo;
                $monto = ($tipo === self::TOTAL) ? round($saldo, 2) : min(round((float) $c['monto'], 2), round($saldo, 2));
                $transc = max(0.0, min(100.0, 100 * self::dias($p['fecha_desembolso'], $fecha)
                    / max(1, self::dias($p['fecha_desembolso'], $ultimaFecha))));
                $pctCom = self::pctComision($p['cancelacion'], $tipo, $transc);
                $comision = round($monto * $pctCom / 100, 2);
                $ivaCom = !empty($p['cancelacion']['iva']) ? round($comision * $iva, 2) : 0.0;
                $cs = self::costosPorCuota($costos, $devengado, null, $iva, [], true);

                $filas[] = self::fila(self::R_CANCELACION, null, $fecha, ['fecha' => $fecha, 'corrida' => false], $dias,
                    $tna, $periodos[$ixPer]['origen'], $periodos[$ixPer]['detalle'], $saldoIni, $monto, $devengado, $cs, $hoy, [
                        'cancelacion' => ['id' => $c['id'] ?? null, 'tipo' => $tipo, 'modo' => $c['modo'] ?? null,
                                          'pct_comision' => $pctCom, 'comision' => $comision, 'iva_comision' => $ivaCom,
                                          'transcurrido' => round($transc, 2)]
                    ]);

                $saldo = round($saldo - $monto, 2);
                $prev = $fecha;

                if ($tipo === self::TOTAL) {
                    $terminado = true;
                } elseif (strtoupper((string) ($c['modo'] ?? '')) === self::REDUCIR_PLAZO) {
                    $modoPlazo = true;
                } else {
                    $modoPlazo = false;
                    $recalcular = true;
                }

                continue;
            }

            /* ---- Un vencimiento ------------------------------------------- */
            $v = $ev['v'];
            $ixPer = $perDeK[$v['k']];
            $tna = $periodos[$ixPer]['tna'];
            $dias = self::dias($prev, $v['fecha']);
            $saldoIni = $saldo;
            $acum += self::interes($saldo, PrestamosTasas::tnaDecimal($tna), $dias);
            $aj = $ajustes[$v['k']] ?? [];
            $corregido = [];

            $interes = 0.0;

            if ($v['interes']) {
                $interes = round($acum, 2);
                $acum = 0.0;

                if (isset($aj[self::AJ_INTERES])) {
                    $interes = round((float) $aj[self::AJ_INTERES]['importe'], 2);
                    $corregido[self::AJ_INTERES] = $aj[self::AJ_INTERES];
                }
            }

            $capital = 0.0;

            if ($v['capital']) {
                $j = $v['j'];
                $ultima = ($j === $p['cuotas']);

                if ($j <= $G && !$ultima) {
                    $capital = 0.0;
                } elseif ($p['sistema'] === self::BULLET) {
                    $capital = $ultima ? $saldo : 0.0;
                } elseif ($p['sistema'] === self::ALEMAN) {
                    if ($recalcular) {
                        $fijoAleman = round($saldo / max(1, $restantes()), 2);
                        $recalcular = false;
                    }

                    $capital = ($ultima || $fijoAleman >= $saldo) ? $saldo : $fijoAleman;
                } else {
                    // Frances: frecuencias sincronizadas, asi que este vencimiento tambien lleva intereses.
                    $i = PrestamosTasas::tnaDecimal($tna) * $dnom / 365;

                    if ($cuotaFr === null || $recalcular || ($variable && !$modoPlazo)) {
                        $cuotaFr = round(self::cuotaFrancesa($saldo, $i, max(1, $restantes())), 2);
                        $recalcular = false;
                    }

                    $capital = $ultima ? $saldo : max(0.0, min($saldo, $cuotaFr - $interes));
                }

                $jHecho = $j;
                $capital = round($capital, 2);

                if (isset($aj[self::AJ_CAPITAL])) {
                    $capital = round((float) $aj[self::AJ_CAPITAL]['importe'], 2);
                    $corregido[self::AJ_CAPITAL] = $aj[self::AJ_CAPITAL];
                }
            }

            $cs = self::costosPorCuota($costos, $v['interes'] ? $interes : 0.0, $saldoIni, $iva, $aj, false, $corregido);

            if (isset($aj[self::AJ_TNA])) {
                $corregido[self::AJ_TNA] = $aj[self::AJ_TNA];
            }

            $pago = DiasHabiles::siguiente($v['fecha'], $habiles);
            $filas[] = self::fila(self::R_CUOTA, $v['k'], $v['fecha'], $pago, $dias, $tna, $periodos[$ixPer]['origen'],
                $periodos[$ixPer]['detalle'], $saldoIni, $capital, $interes, $cs, $hoy, [
                    'corregido' => $corregido, 'lleva_capital' => $v['capital'], 'lleva_interes' => $v['interes']
                ]);

            $saldo = round($saldo - $capital, 2);
            $prev = $v['fecha'];
        }

        /* ---- Avisos del recorrido ---------------------------------------- */
        $tieneCapCorregido = false;
        $sumaCap = 0.0;

        foreach ($filas as $f) {
            $sumaCap += $f['capital'];
            $tieneCapCorregido = $tieneCapCorregido || isset($f['corregido'][self::AJ_CAPITAL]);
        }

        if ($tieneCapCorregido && $p['estado'] === self::VIGENTE && abs($sumaCap - $C) > 0.01) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $nombre . ': con las correcciones, el capital de las cuotas suma '
                . self::plata($sumaCap, $p['moneda']) . ' y el préstamo es de ' . self::plata($C, $p['moneda'])
                . '. Revisá las cuotas corregidas contra el cronograma del banco.');
        }

        /* ---- Los pagos, en pesos --------------------------------------- */
        $pagos = self::pagos($filas, $p, $e['curva'] ?? [], $avisos);

        return [
            'cuotas' => $filas,
            'pagos' => $pagos,
            'resumen' => self::resumen($p, $filas, $periodos, $hoy, $hayTotal),
            'avisos' => $avisos,
            'proyectable' => true
        ];
    }

    /**
     * Los costos por cuota (o los de una cancelacion: solo los de base
     * intereses). Con IVA si el costo lo lleva. Una correccion COSTO:<id>
     * reemplaza el importe del costo, IVA incluido.
     */
    private static function costosPorCuota($costos, $interes, $saldoIni, $iva, $aj, $soloIntereses, &$corregido = []) {
        $out = [];

        foreach ($costos as $c) {
            if (($c['momento'] ?? '') !== self::POR_CUOTA) {
                continue;
            }

            if ($soloIntereses && ($c['base'] ?? '') !== self::BASE_INTERESES) {
                continue;
            }

            $base = (($c['base'] ?? '') === self::BASE_CAPITAL) ? (float) $saldoIni : (float) $interes;
            $imp = round($base * (float) $c['pct'] / 100, 2);
            $imp = round($imp + (!empty($c['iva']) ? $imp * $iva : 0), 2);
            $clave = self::AJ_COSTO . $c['id'];

            if (isset($aj[$clave])) {
                $imp = round((float) $aj[$clave]['importe'], 2);
                $corregido[$clave] = $aj[$clave];
            }

            $out[$c['id']] = $imp;
        }

        return $out;
    }

    private static function fila($tipo, $k, $vto, $pago, $dias, $tna, $origen, $detalle, $saldoIni, $capital, $interes, $costos, $hoy, $extra) {
        $totCostos = round(array_sum($costos), 2);
        $cancel = $extra['cancelacion'] ?? null;
        $com = $cancel ? round($cancel['comision'] + $cancel['iva_comision'], 2) : 0.0;

        return array_merge([
            'tipo' => $tipo,
            'nro' => $k,
            'vencimiento' => $vto,
            'fecha_pago' => $pago['fecha'],
            'corrida' => !empty($pago['corrida']),
            'dias' => $dias,
            'tna' => $tna,
            'origen_tasa' => $origen,
            'detalle_tasa' => $detalle,
            'saldo_inicial' => round($saldoIni, 2),
            'capital' => round($capital, 2),
            'interes' => round($interes, 2),
            'costos' => $costos,
            'total_costos' => $totCostos,
            'total' => round($capital + $interes + $totCostos + $com, 2),
            'real' => ($pago['fecha'] <= $hoy),
            'corregido' => []
        ], $extra);
    }

    /**
     * La lista de pagos del tablero: por renglon, el desembolso, el capital y
     * los intereses y gastos, cada uno con su fecha de pago y en pesos.
     * Un prestamo en USD se pasa a pesos con DolarFuturo::resolver() por fecha
     * de pago; sin cotizacion, el pago queda con importe null y se avisa.
     */
    private static function pagos($filas, $p, $curva, &$avisos) {
        $out = [];
        $sinDolar = [];

        foreach ($filas as $f) {
            $partes = [];

            if ($f['tipo'] === self::R_DESEMBOLSO) {
                $partes[self::DESEMBOLSO] = $f['desembolso'];
                $partes[self::INTERESES] = $f['total_costos'];
                $quien = 'Desembolso';
            } else {
                $partes[self::CAPITAL] = $f['capital'];
                $partes[self::INTERESES] = round($f['total'] - $f['capital'], 2);
                $quien = ($f['tipo'] === self::R_CANCELACION)
                    ? 'Cancelación ' . strtolower(self::nombreCancel($f['cancelacion']['tipo']))
                    : 'Cuota ' . $f['nro'];
            }

            $tc = null;
            $tcOrigen = null;

            if ($p['moneda'] === 'USD') {
                $r = DolarFuturo::resolver($curva, $f['fecha_pago']);
                $tc = $r['cotizacion'];
                $tcOrigen = $r['origen'];

                if ($tc === null && array_sum($partes) > 0) {
                    $sinDolar[substr($f['fecha_pago'], 0, 7)] = true;
                }
            }

            foreach ($partes as $concepto => $imp) {
                if (abs($imp) < 0.005) {
                    continue;
                }

                $ars = ($p['moneda'] === 'USD') ? ($tc === null ? null : round($imp * $tc, 2)) : $imp;

                $out[] = [
                    'prestamo' => $p['id'],
                    'nombre' => $p['nombre'],
                    'estado' => $p['estado'],
                    'simulado' => ($p['estado'] === self::SIMULADO),
                    'concepto' => $concepto,
                    'fecha' => $f['fecha_pago'],
                    'nro' => $f['nro'],
                    'tipo' => $f['tipo'],
                    'real' => $f['real'],
                    'moneda' => $p['moneda'],
                    'importe_moneda' => $imp,
                    'tc' => $tc,
                    'tc_aproximado' => ($tcOrigen === DolarFuturo::ORIGEN_APROXIMADA),
                    'importe' => $ars,
                    'detalle' => $quien . ' de ' . $p['nombre'] . ': ' . self::NOMBRE_CONCEPTO[$concepto] . ' '
                        . self::plata($imp, $p['moneda'])
                        . ($p['moneda'] === 'USD' && $tc !== null ? ' × ' . number_format($tc, 2, ',', '.')
                            . ($tcOrigen === DolarFuturo::ORIGEN_APROXIMADA ? ' (dólar futuro aproximado)' : ' (dólar futuro)') : '')
                        . ($f['corrida'] ? '. Vence el ' . self::corta($f['vencimiento']) . ' y se paga el hábil siguiente' : '')
                ];
            }
        }

        if (!empty($sinDolar)) {
            ksort($sinDolar);
            $avisos[] = Aviso::nuevo(Aviso::WARNING, '«' . $p['nombre'] . '» (en dólares): no hay dólar futuro para '
                . implode(', ', array_keys($sinDolar)) . '. Esos pagos no se pasan a pesos y no suman en el tablero.');
        }

        return $out;
    }

    private static function resumenVacio($p, $hoy) {
        return ['estado' => self::estado($p, [], null, $hoy), 'saldo_hoy' => null, 'proxima' => null,
                'tea' => null, 'cft' => null, 'tna_actual' => null, 'costos_iniciales' => 0.0,
                'ultima_fecha' => null, 'cancelada_el' => null];
    }

    /**
     * El estado calculado. Nunca se escribe al leer: Simulado y Vigente estan
     * guardados; Cancelado sale de una cancelacion total ya ocurrida y
     * Finalizado de que paso el ultimo pago.
     */
    public static function estado($p, $filas, $total, $hoy) {
        if ($p['estado'] === self::SIMULADO) {
            return self::SIMULADO;
        }

        if ($total !== null && $total <= $hoy) {
            return self::CANCELADO;
        }

        if (!empty($filas)) {
            $ultima = end($filas)['fecha_pago'];

            if ($ultima < $hoy) {
                return self::FINALIZADO;
            }
        }

        return self::VIGENTE;
    }

    private static function resumen($p, $filas, $periodos, $hoy, $total) {
        $saldo = 0.0;
        $proxima = null;
        $flujos = [];
        $iniciales = 0.0;

        foreach ($filas as $f) {
            if ($f['tipo'] === self::R_DESEMBOLSO) {
                $iniciales = $f['total_costos'];
                $flujos[] = ['fecha' => $f['fecha_pago'], 'importe' => $f['desembolso'] - $f['total_costos']];

                if ($f['fecha_pago'] <= $hoy) {
                    $saldo += $f['desembolso'];
                }

                continue;
            }

            $flujos[] = ['fecha' => $f['fecha_pago'], 'importe' => -$f['total']];

            if ($f['fecha_pago'] <= $hoy) {
                $saldo -= $f['capital'];
            } elseif ($proxima === null) {
                $proxima = ['fecha' => $f['fecha_pago'], 'total' => $f['total'], 'nro' => $f['nro'], 'tipo' => $f['tipo']];
            }
        }

        // La TNA "actual": la del primer periodo que no termino.
        $tna = null;

        foreach ($periodos as $per) {
            $tna = $per['tna'];

            if ($per['fin'] >= $hoy) {
                break;
            }
        }

        return [
            'estado' => self::estado($p, $filas, $total, $hoy),
            'saldo_hoy' => round(max(0.0, $saldo), 2),
            'proxima' => $proxima,
            'tna_actual' => $tna,
            'tea' => ($tna === null) ? null : self::tea(PrestamosTasas::tnaDecimal($tna), self::DIAS_NOMINALES[$p['frec_intereses']]),
            'cft' => self::cft($flujos),
            'costos_iniciales' => $iniciales,
            'ultima_fecha' => empty($filas) ? null : end($filas)['fecha_pago'],
            'cancelada_el' => $total
        ];
    }

    /* ====================================================================
       FORMATO
       ==================================================================== */

    public static function nombreCancel($tipo) {
        return ($tipo === self::TOTAL) ? 'Total' : 'Parcial';
    }

    public static function plata($n, $moneda = 'ARS') {
        return ($moneda === 'USD' ? 'U$S ' : '$ ') . number_format((float) $n, 2, ',', '.');
    }

    public static function pct($n) {
        return number_format((float) $n, 2, ',', '.') . ' %';
    }

    public static function corta($fecha) {
        $t = strtotime((string) $fecha);

        return $t ? date('d/m/Y', $t) : (string) $fecha;
    }
}
