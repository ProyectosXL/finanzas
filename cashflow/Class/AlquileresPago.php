<?php

require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/Inflacion.php';

/**
 * AlquileresPago
 * CUANTO SE PAGA Y CUANDO, por local y por concepto. Es LA regla: la usa la
 * pestaña Alquileres y la usa el proveedor del tablero
 * (Providers/AlquileresProvider.php), y una prueba fija que los dos digan lo
 * mismo. Si cambia como se paga un alquiler, cambia aca y en ningun otro lado.
 *
 * ESTATICA Y PURA: la fecha de hoy, el calendario de dias habiles, los
 * parametros del local y lo que AlquileresCalculo proyecto entran como
 * parametros.
 *
 * CUANTO
 * ------
 *     neto        = importe del concepto (o del extraordinario)
 *     IVA         = neto x % IVA del local
 *     percepcion  = neto x % percepcion del local
 *     retencion   = neto x % retencion del local
 *     a pagar     = neto + IVA + percepcion - retencion
 *
 * LA RETENCION ES SOBRE EL NETO, no sobre el total con impuestos. Cada parte se
 * redondea a centavos y el a pagar se arma con las partes redondeadas, para que
 * el tooltip sume a la vista.
 *
 * CUANDO
 * ------
 *   - Fijos, fondo de promocion y expensas: el mismo mes del concepto.
 *   - Variables: el mes de la venta + los meses de diferimiento de la razon
 *     social. Por eso para pagar dentro del horizonte hacen falta meses de
 *     venta ANTERIORES al horizonte (con diferimiento 2, la venta de hace dos
 *     meses): mesesDeConcepto() los incluye.
 *   - Extraordinarios: el mes base + su diferimiento.
 *   - El dia: el de vencimiento de la razon social, o el general. Si cae en
 *     fin de semana o feriado, el siguiente dia habil (DiasHabiles::siguiente()).
 *   - Si ese dia YA PASO dentro del mes en curso -vencio y no hay factura-, el
 *     pago va al siguiente dia habil desde hoy, nunca apilado en el primer dia
 *     del eje. Es provisorio: cuando lleguen las facturas reales (prompt B), la
 *     factura lo reemplaza.
 *
 * QUE ES UN ALQUILER
 * ------------------
 * Una razon social es de alquiler porque esta DADA DE ALTA en Parametros ->
 * Alquileres, no por su rubro: el rubro (RUBRO = ALQUILER en el maestro) solo
 * arma la lista desde donde se la da de alta. Es el mismo criterio que, en el
 * prompt B, va a decidir que factura de Proveedores Locales es de alquiler.
 */
class AlquileresPago {

    /** Los estados de un local */
    const PROYECTA = 'PROYECTA';
    const INCOMPLETO = 'INCOMPLETO';
    const CERRADO_LAKERS = 'CERRADO_LAKERS';
    const FINALIZADO = 'FINALIZADO';

    const NOMBRE_ESTADO = [
        self::PROYECTA => 'Proyecta',
        self::INCOMPLETO => 'Incompleto',
        self::CERRADO_LAKERS => 'Cerrado en Lakers',
        self::FINALIZADO => 'Contrato finalizado'
    ];

    /* ====================================================================
       EL ESTADO DE UN LOCAL
       ==================================================================== */

    /**
     * Si un local proyecta, y si no, por que.
     *
     *   CERRADO_LAKERS  HABILITADO = 0 en SUCURSALES_LAKERS. No proyecta y NO es
     *                   un incompleto: no falta ningun dato, el local cerro.
     *   FINALIZADO      su fecha de fin es de un mes anterior al actual: desde el
     *                   mes siguiente a la fecha de fin no proyecta. Tampoco es
     *                   un incompleto.
     *   INCOMPLETO      le falta algun dato para proyectar; 'faltan' dice cual y
     *                   donde se carga.
     *   PROYECTA
     *
     * Percepcion y retencion son obligatorias: 0 es un valor, vacio no.
     *
     * @param array|null $local La fila de Parametros (null = nunca se cargo)
     * @param bool $habilitado El de Lakers
     * @param array $razones COD_PROVEE => razon social DADA DE ALTA
     *        (['dia' => int|null, 'diferimiento' => int])
     * @param int|null $diaGeneral
     * @param string $mesActual 'Y-m'
     * @return array ['estado' => string, 'faltan' => [string]]
     */
    public static function estadoLocal($local, $habilitado, $razones, $diaGeneral, $mesActual) {
        if (!$habilitado) {
            return ['estado' => self::CERRADO_LAKERS, 'faltan' => []];
        }

        if ($local !== null && !empty($local['fecha_fin']) && substr($local['fecha_fin'], 0, 7) < $mesActual) {
            return ['estado' => self::FINALIZADO, 'faltan' => []];
        }

        $faltan = [];
        $cod = ($local === null) ? null : ($local['cod_provee'] ?? null);

        if ($cod === null || $cod === '') {
            $faltan[] = 'razón social';
        } elseif (!isset($razones[$cod])) {
            $faltan[] = 'razón social dada de baja';
        } elseif ($razones[$cod]['dia'] === null && $diaGeneral === null) {
            $faltan[] = 'día de vencimiento (ni la razón social ni el general)';
        }

        $frecuencia = ($local === null) ? null : ($local['frecuencia'] ?? null);

        if ($frecuencia === null) {
            $faltan[] = 'frecuencia de actualización';
        } elseif ((int) $frecuencia !== 1 && ($local['mes_ajuste'] ?? null) === null) {
            $faltan[] = 'mes del último ajuste';
        }

        foreach (['pct_iva' => '% IVA', 'pct_percepcion' => '% percepción', 'pct_retencion' => '% retención'] as $campo => $nombre) {
            if ($local === null || ($local[$campo] ?? null) === null) {
                $faltan[] = $nombre;
            }
        }

        return ['estado' => empty($faltan) ? self::PROYECTA : self::INCOMPLETO, 'faltan' => $faltan];
    }

    /* ====================================================================
       CUANTO
       ==================================================================== */

    /**
     * Las partes de un pago a partir del neto y las alicuotas del local.
     *
     * @param float $neto
     * @param array $local ['pct_iva', 'pct_percepcion', 'pct_retencion'], en %
     * @return array ['neto', 'iva', 'percepcion', 'retencion', 'a_pagar']
     */
    public static function importes($neto, $local) {
        $n = round((float) $neto, 2);
        $iva = round($n * (float) $local['pct_iva'] / 100, 2);
        $perc = round($n * (float) $local['pct_percepcion'] / 100, 2);
        $ret = round($n * (float) $local['pct_retencion'] / 100, 2);

        return [
            'neto' => $n,
            'iva' => $iva,
            'percepcion' => $perc,
            'retencion' => $ret,
            'a_pagar' => round($n + $iva + $perc - $ret, 2)
        ];
    }

    /* ====================================================================
       CUANDO
       ==================================================================== */

    /**
     * Los meses de concepto que hacen falta para pagar dentro del horizonte:
     * los del horizonte y, hacia atras, tantos como el mayor diferimiento.
     *
     * @param string[] $mesesPago Los meses del horizonte, 'Y-m'
     * @param int $maxDiferimiento El mayor entre las razones sociales y los extraordinarios
     * @return string[]
     */
    public static function mesesDeConcepto($mesesPago, $maxDiferimiento) {
        if (empty($mesesPago)) {
            return [];
        }

        $meses = [];

        for ($i = (int) $maxDiferimiento; $i > 0; $i--) {
            $meses[] = Inflacion::mesMas($mesesPago[0], -$i);
        }

        return array_merge($meses, array_values($mesesPago));
    }

    /**
     * El dia en que se paga un mes.
     *
     * @param string $mesPago 'Y-m'
     * @param int $dia 1 a 28
     * @param array $habiles 'Y-m-d' => bool
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha' => 'Y-m-d', 'vencimiento' => 'Y-m-d', 'corrida' => bool,
     *                'vencido' => bool, 'faltan_calendario' => ['Y-m']]
     */
    public static function diaPago($mesPago, $dia, $habiles, $hoy) {
        $vto = $mesPago . '-' . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
        $sig = DiasHabiles::siguiente($vto, $habiles);
        $faltan = $sig['faltan'];
        $vencido = false;
        $fecha = $sig['fecha'];

        if ($fecha < $hoy && $mesPago === substr($hoy, 0, 7)) {
            $desdeHoy = DiasHabiles::siguiente($hoy, $habiles);
            $fecha = $desdeHoy['fecha'];
            $faltan = array_values(array_unique(array_merge($faltan, $desdeHoy['faltan'])));
            $vencido = true;
        }

        return [
            'fecha' => $fecha,
            'vencimiento' => $vto,
            'corrida' => $sig['corrida'],
            'vencido' => $vencido,
            'faltan_calendario' => $faltan
        ];
    }

    /**
     * La nota de una celda cuyo pago se movio de dia, para el 'detalle' del
     * tablero y el tooltip de la pestaña. '' si no se movio.
     *
     * @param array $dia Lo de diaPago()
     * @return string
     */
    public static function notaDia($dia) {
        if ($dia['vencido']) {
            return 'Vencía el ' . self::fechaCorta($dia['vencimiento']) . ' y todavía no hay factura: '
                . 'se ubica el siguiente día hábil desde hoy (' . self::fechaCorta($dia['fecha']) . ').';
        }

        if ($dia['corrida']) {
            return 'Vence el ' . self::fechaCorta($dia['vencimiento']) . ', que no es hábil: pasa al '
                . self::fechaCorta($dia['fecha']) . '.';
        }

        return '';
    }

    /* ====================================================================
       EXTRAORDINARIOS
       ==================================================================== */

    /**
     * Si un extraordinario alcanza a un local.
     *
     * Con "todos los locales", alcanza a todo local que HOY tenga esa razon
     * social, incluido uno que se le asigno despues de cargar el
     * extraordinario.
     *
     * @param array $extra ['cod_provee', 'todos', 'locales' => [nro]]
     * @param int $nro
     * @param string|null $codLocal La razon social del local
     * @return bool
     */
    public static function alcanza($extra, $nro, $codLocal) {
        if ($codLocal === null || $extra['cod_provee'] !== $codLocal) {
            return false;
        }

        return $extra['todos'] || in_array((int) $nro, array_map('intval', $extra['locales']), true);
    }

    /* ====================================================================
       LOS PAGOS DE UN LOCAL
       ==================================================================== */

    /**
     * Todos los pagos de un local que caen en el horizonte.
     *
     * @param array $e [
     *     'local' => ['pct_iva', 'pct_percepcion', 'pct_retencion', 'fecha_fin', 'cod_provee'],
     *     'razon' => ['dia' => int|null, 'diferimiento' => int],
     *     'dia_general' => int|null,
     *     'proyeccion' => lo de AlquileresCalculo::proyectarLocal(), con los
     *                     meses de mesesDeConcepto(),
     *     'extras' => [['id', 'pct', 'conceptos' => [ID_CA], 'mes_base' => 1..12,
     *                   'diferimiento' => int]]   solo los que alcanzan al local,
     *     'meses_pago' => ['Y-m'],
     *     'habiles' => ['Y-m-d' => bool],
     *     'hoy' => 'Y-m-d'
     * ]
     * @return array Lista de pagos: ['mes_pago', 'fecha', 'concepto', 'grupo',
     *         'mes_concepto', 'origen', + importes() + diaPago() + 'detalle']
     */
    public static function pagosLocal($e) {
        $dia = $e['razon']['dia'] ?? $e['dia_general'];
        $difer = (int) $e['razon']['diferimiento'];
        $enHorizonte = array_flip($e['meses_pago']);
        $fin = empty($e['local']['fecha_fin']) ? null : substr($e['local']['fecha_fin'], 0, 7);
        $meses = $e['proyeccion']['meses'];
        $dias = [];
        $pagos = [];

        $diaDe = function ($mesPago) use (&$dias, $dia, $e) {
            if (!isset($dias[$mesPago])) {
                $dias[$mesPago] = self::diaPago($mesPago, $dia, $e['habiles'], $e['hoy']);
            }
            return $dias[$mesPago];
        };

        foreach ($meses as $mesConcepto => $m) {
            if ($fin !== null && $mesConcepto > $fin) {
                continue;
            }

            foreach ($m['conceptos'] as $id => $c) {
                $grupo = AlquileresConceptos::grupo($id);
                $mesPago = AlquileresConceptos::seDifiere($id) ? Inflacion::mesMas($mesConcepto, $difer) : $mesConcepto;

                if (!isset($enHorizonte[$mesPago])) {
                    continue;
                }

                $detalle = $c;
                unset($detalle['importe']);

                $pagos[] = self::pago($mesPago, $diaDe($mesPago), $id, $grupo, $mesConcepto,
                                      $m['origen'], $c['importe'], $e['local'], $detalle);
            }
        }

        foreach ($e['extras'] as $x) {
            foreach ($e['meses_pago'] as $mesPago) {
                $mesBase = Inflacion::mesMas($mesPago, -(int) $x['diferimiento']);

                if ((int) substr($mesBase, 5, 2) !== (int) $x['mes_base'] || ($fin !== null && $mesBase > $fin)) {
                    continue;
                }

                $base = $meses[$mesBase] ?? null;

                if ($base === null || $base['origen'] === null) {
                    continue;
                }

                $suma = 0.0;
                $partes = [];

                foreach ($x['conceptos'] as $id) {
                    $v = isset($base['conceptos'][$id]) ? (float) $base['conceptos'][$id]['importe'] : 0.0;
                    $partes[$id] = $v;
                    $suma += $v;
                }

                $pagos[] = self::pago($mesPago, $diaDe($mesPago), 'EXTRA:' . $x['id'], AlquileresConceptos::EXTRA,
                                      $mesBase, $base['origen'], $suma * (float) $x['pct'] / 100, $e['local'],
                                      ['extra' => $x['id'], 'pct' => (float) $x['pct'], 'base' => $partes,
                                       'base_total' => $suma]);
            }
        }

        return $pagos;
    }

    /** Un pago: importes, dia y de donde sale. */
    private static function pago($mesPago, $dia, $concepto, $grupo, $mesConcepto, $origen, $neto, $local, $detalle) {
        return [
            'mes_pago' => $mesPago,
            'fecha' => $dia['fecha'],
            'concepto' => $concepto,
            'grupo' => $grupo,
            'mes_concepto' => $mesConcepto,
            'origen' => $origen,
            'vencimiento' => $dia['vencimiento'],
            'corrida' => $dia['corrida'],
            'vencido' => $dia['vencido'],
            'faltan_calendario' => $dia['faltan_calendario'],
            'nota_dia' => self::notaDia($dia),
            'detalle' => $detalle
        ] + self::importes($neto, $local);
    }

    /** 'Y-m-d' a 'd/m/Y' */
    private static function fechaCorta($fecha) {
        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4);
    }
}
