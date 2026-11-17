<?php

require_once __DIR__ . '/Inflacion.php';

/**
 * AlquileresVenta
 * La venta de cada local, mes a mes, que usan los conceptos variables del
 * alquiler.
 *
 * ESTATICA Y PURA. La venta real de la app, la venta proyectada del canal y
 * los datos de Parametros entran como parametros.
 *
 * BRUTA ES CON IVA Y NETA ES SIN IVA. La neta estimada es la bruta dividida
 * por 1 + la alicuota de IVA de Parametros -> Generales (hoy 21 %): no se usa
 * la relacion historica bruta/neta de cada local. En la base esa relacion da
 * 1,21 en todos los locales con una diferencia de menos del 3 %.
 *
 * DE DONDE SALE, POR LOCAL Y POR MES
 * ----------------------------------
 *   REAL      el mes ya tiene venta en la app: neta de RO_T_RENTABILIDAD_BRUTA
 *             y bruta de RO_V_VENTAS_BRUTAS_IE, las mismas de la app. Si hay
 *             una y no la otra (al 10/11/2026 no pasa en ningun local), la
 *             que falta se deriva con el IVA general y la celda lo dice.
 *   ESTIMADA  sin venta real: la venta proyectada del canal LOCALES de la
 *             pestaña Ventas (con IVA, por mes completo: ver
 *             Ventas::ventaMensualCanal()), repartida por la participacion del
 *             local en la venta NETA real de los ultimos 12 meses cerrados.
 *   MANUAL    un local nuevo, sin 12 meses de venta en esa ventana, no entra
 *             al reparto: usa la venta mensual estimada de Parametros (neta;
 *             la bruta es x 1 + IVA). Sin ella no tiene venta y no proyecta
 *             conceptos variables.
 *
 *   REAL_PROYECTADA  el MES EN CURSO con venta real: la real de la app (lo
 *             vendido hasta ayer) MAS la estimada (o la manual) del mes completo
 *             por la fraccion de dias que faltan, hoy incluido:
 *             (dias del mes - dia de hoy + 1) / dias del mes. Sin eso el mes en
 *             curso tomaba como venta del mes lo vendido hasta ahora, y los
 *             conceptos variables salian chicos. Sin estimada ni manual, la
 *             real sola.
 *
 * EL REPARTO CIERRA AL CENTAVO CONTRA EL CANAL. Las manuales se restan del
 * canal antes de repartir, asi que estimadas + manuales = venta del canal. Los
 * importes se redondean a centavos y lo que sobra del redondeo va al local con
 * mas participacion: si no, la suma podria diferir en centavos y el pie de la
 * pestaña mostraria una diferencia que no es tal.
 *
 * EL DENOMINADOR ES LA VENTA DE LOS QUE SIGUEN PROYECTANDO. Un local cerrado
 * (en Lakers, o con fecha de fin ya pasada) no entra al reparto aunque haya
 * vendido en la ventana: si entrara, su parte de la venta del canal quedaria
 * asignada a un local que ya no paga alquiler, y no la pagaria nadie. Un local
 * con fecha de fin futura reparte hasta ese mes.
 */
class AlquileresVenta {

    const REAL = 'REAL';
    const ESTIMADA = 'ESTIMADA';
    const MANUAL = 'MANUAL';
    const REAL_PROYECTADA = 'REAL_PROYECTADA';

    const NOMBRE_ORIGEN = [self::REAL => 'Real', self::ESTIMADA => 'Estimada', self::MANUAL => 'Manual',
                           self::REAL_PROYECTADA => 'Real + proyectada'];

    /** Los meses de venta que hacen falta para entrar al reparto */
    const MESES_PARTICIPACION = 12;

    /**
     * La ventana de la participacion: los ultimos 12 meses cerrados en la app.
     *
     * @param string[] $cerrados 'Y-m', de AlquileresApp::mesesCerrados()
     * @return string[] Los 12 (o menos, si la app tiene menos), ordenados
     */
    public static function ventana($cerrados) {
        $cerrados = array_values($cerrados);
        sort($cerrados);

        return array_slice($cerrados, -self::MESES_PARTICIPACION);
    }

    /**
     * La venta de todos los locales en todos los meses pedidos.
     *
     * @param array $e [
     *     'meses' => ['Y-m'],
     *     'locales' => [nro => ['activo_hasta' => 'Y-m'|null (null = sin fin),
     *                           'activo' => bool, 'manual' => float|null]],
     *     'netas' => [nro => ['Y-m' => float]],
     *     'brutas' => [nro => ['Y-m' => float]],
     *     'ventana' => ['Y-m'],
     *     'canal' => ['Y-m' => float]   venta del canal LOCALES con IVA,
     *     'iva' => float                0.21,
     *     'hoy' => 'Y-m-d'              opcional: el mes en curso suma a la real la
     *                                   proyeccion de los dias que faltan (REAL_PROYECTADA)
     * ]
     * 'activo' es false para un local cerrado en Lakers: no reparte nunca.
     * 'activo_hasta' es el mes de su fecha de fin: reparte hasta ese mes.
     *
     * @return array [
     *     'ventas' => [nro => ['Y-m' => ['bruta', 'neta', 'origen', 'derivada',
     *                  'participacion', 'canal'] | null]],
     *     'participacion' => [nro => float],    sobre la ventana, sin cortar por fecha de fin
     *     'nuevos' => [nro],                    sin 12 meses de venta
     *     'canal' => ['Y-m' => float], 'manual' => ['Y-m' => float],
     *     'estimada' => ['Y-m' => float], 'ventana' => ['Y-m']
     * ]
     */
    public static function calcular($e) {
        $iva = (float) $e['iva'];
        $ventana = $e['ventana'];
        $netas = $e['netas'];
        $brutas = $e['brutas'];

        /* 1. Quien entra al reparto y con cuanto. */
        $venta12 = [];
        $nuevos = [];

        foreach ($e['locales'] as $nro => $l) {
            $meses = 0;
            $suma = 0.0;

            foreach ($ventana as $m) {
                if (isset($netas[$nro][$m])) {
                    $meses++;
                    $suma += $netas[$nro][$m];
                }
            }

            if (!$l['activo']) {
                continue;
            }

            if ($meses < self::MESES_PARTICIPACION || $suma <= 0) {
                $nuevos[] = $nro;
                continue;
            }

            $venta12[$nro] = $suma;
        }

        $totalVenta12 = array_sum($venta12);
        $participacion = [];

        foreach ($venta12 as $nro => $v) {
            $participacion[$nro] = ($totalVenta12 > 0) ? $v / $totalVenta12 : 0.0;
        }

        /* 2. Mes a mes. */
        $out = [];
        $manualMes = [];
        $estimadaMes = [];

        /* El mes en curso y la fraccion que falta vender, hoy incluido. */
        $hoy = isset($e['hoy']) ? substr((string) $e['hoy'], 0, 10) : null;
        $mesHoy = ($hoy === null) ? null : substr($hoy, 0, 7);
        $diasMes = ($hoy === null) ? null : (int) date('t', strtotime($hoy));
        $restan = ($hoy === null) ? null : $diasMes - (int) substr($hoy, 8, 2) + 1;
        $fraccion = ($hoy === null) ? 0.0 : $restan / $diasMes;

        foreach ($e['meses'] as $mes) {
            $activos = [];

            foreach ($e['locales'] as $nro => $l) {
                $activos[$nro] = $l['activo'] && ($l['activo_hasta'] === null || $mes <= $l['activo_hasta']);
            }

            /* Las manuales de los nuevos activos: van primero, porque se restan
               del canal. */
            $manualMes[$mes] = 0.0;

            foreach ($nuevos as $nro) {
                $manual = $e['locales'][$nro]['manual'];

                if ($activos[$nro] && $manual !== null && !self::tieneReal($netas, $brutas, $nro, $mes)) {
                    $out[$nro][$mes] = [
                        'bruta' => round($manual * (1 + $iva), 2),
                        'neta' => round($manual, 2),
                        'origen' => self::MANUAL,
                        'derivada' => false,
                        'participacion' => null,
                        'canal' => null
                    ];
                    $manualMes[$mes] += $out[$nro][$mes]['bruta'];
                }
            }

            /* El reparto: la participacion de los que siguen activos en este
               mes, renormalizada entre ellos. */
            $canal = $e['canal'][$mes] ?? null;
            $part = [];

            foreach ($participacion as $nro => $p) {
                if ($activos[$nro]) {
                    $part[$nro] = $p;
                }
            }

            $estimadas = ($canal === null) ? [] : self::repartir($canal - $manualMes[$mes], $part);
            $estimadaMes[$mes] = array_sum($estimadas);

            foreach ($e['locales'] as $nro => $l) {
                if (self::tieneReal($netas, $brutas, $nro, $mes)) {
                    $out[$nro][$mes] = self::real($netas[$nro][$mes] ?? null, $brutas[$nro][$mes] ?? null, $iva);

                    /* EL MES EN CURSO: la real + lo que falta vender. */
                    if ($mes === $mesHoy) {
                        $mesCompleto = isset($estimadas[$nro]) ? $estimadas[$nro]
                            : ((in_array($nro, $nuevos, true) && $activos[$nro] && $l['manual'] !== null)
                                ? round($l['manual'] * (1 + $iva), 2) : null);

                        if ($mesCompleto !== null) {
                            $out[$nro][$mes] = self::realMasProyectada($out[$nro][$mes], $mesCompleto, $fraccion,
                                $iva, isset($estimadas[$nro]) ? $part[$nro] / array_sum($part) : null,
                                isset($estimadas[$nro]) ? $canal : null) + ['dias_restantes' => $restan,
                                'dias_mes' => $diasMes];
                        }
                    }
                } elseif (isset($estimadas[$nro])) {
                    $out[$nro][$mes] = [
                        'bruta' => $estimadas[$nro],
                        'neta' => $estimadas[$nro] / (1 + $iva),
                        'origen' => self::ESTIMADA,
                        'derivada' => false,
                        'participacion' => $part[$nro] / array_sum($part),
                        'canal' => $canal
                    ];
                } elseif (!isset($out[$nro][$mes])) {
                    $out[$nro][$mes] = null;
                }
            }
        }

        return [
            'ventas' => $out,
            'participacion' => $participacion,
            'nuevos' => $nuevos,
            'canal' => array_intersect_key($e['canal'], array_flip($e['meses'])),
            'manual' => $manualMes,
            'estimada' => $estimadaMes,
            'ventana' => $ventana
        ];
    }

    /**
     * Reparte un total entre claves por participacion, a centavos, y el resto
     * del redondeo al de mayor participacion, para que la suma de exacto.
     *
     * @param float $total
     * @param array $part clave => peso (no hace falta que sumen 1)
     * @return array clave => importe
     */
    public static function repartir($total, $part) {
        $suma = array_sum($part);

        if (empty($part) || $suma <= 0) {
            return [];
        }

        $out = [];

        foreach ($part as $k => $p) {
            $out[$k] = round($total * $p / $suma, 2);
        }

        arsort($part);
        $mayor = array_key_first($part);
        $out[$mayor] = round($out[$mayor] + (round($total, 2) - array_sum($out)), 2);

        return $out;
    }

    /** Si el local tiene alguna de las dos ventas reales en el mes */
    private static function tieneReal($netas, $brutas, $nro, $mes) {
        return isset($netas[$nro][$mes]) || isset($brutas[$nro][$mes]);
    }

    /**
     * La venta del mes en curso: la real + la del mes completo (bruta) por la
     * fraccion de dias que faltan. La neta proyectada es la bruta / (1 + IVA).
     *
     * @param array $real Lo de real()
     * @param float $mesCompletoBruta La estimada (o la manual con IVA) del mes entero
     * @param float $fraccion Dias que faltan / dias del mes
     * @return array La celda, con la parte real y la proyectada por separado
     */
    public static function realMasProyectada($real, $mesCompletoBruta, $fraccion, $iva, $participacion, $canal) {
        $pb = round($mesCompletoBruta * $fraccion, 2);
        $pn = round($pb / (1 + $iva), 2);

        return [
            'bruta' => round($real['bruta'] + $pb, 2),
            'neta' => round($real['neta'] + $pn, 2),
            'origen' => self::REAL_PROYECTADA,
            'derivada' => $real['derivada'],
            'participacion' => $participacion,
            'canal' => $canal,
            'real_bruta' => $real['bruta'],
            'real_neta' => $real['neta'],
            'proyectada_bruta' => $pb,
            'proyectada_neta' => $pn,
            'mes_completo_bruta' => round($mesCompletoBruta, 2)
        ];
    }

    /** La venta real; la que falta se deriva con el IVA general. */
    private static function real($neta, $bruta, $iva) {
        $derivada = ($neta === null || $bruta === null);

        return [
            'bruta' => (float) ($bruta ?? $neta * (1 + $iva)),
            'neta' => (float) ($neta ?? $bruta / (1 + $iva)),
            'origen' => self::REAL,
            'derivada' => $derivada,
            'participacion' => null,
            'canal' => null
        ];
    }
}
