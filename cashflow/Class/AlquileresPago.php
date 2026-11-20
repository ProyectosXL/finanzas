<?php

require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/Planilla.php';
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
 *     social QUE LOS FACTURA. Por eso para pagar dentro del horizonte hacen
 *     falta meses de venta ANTERIORES al horizonte (con diferimiento 2, la
 *     venta de hace dos meses): mesesDeConcepto() los incluye.
 *   - Extraordinarios: el mes base + su diferimiento.
 *   - El dia: el de vencimiento de la razon social que factura el concepto (o
 *     el extraordinario), o el general. Un local con dos razones sociales paga
 *     cada concepto en el dia de la suya (conceptosPorRazon()). Si cae en fin de
 *     semana o feriado, el siguiente dia habil (DiasHabiles::siguiente()).
 *   - Si ese dia YA PASO dentro del mes en curso -vencio y no hay factura-, el
 *     pago va al siguiente dia habil desde hoy, nunca apilado en el primer dia
 *     del eje. Es provisorio: una factura imputada que cubre ese mes lo
 *     reemplaza y se paga en su propia fecha (AlquileresImputacion).
 *
 * QUE ES UN ALQUILER
 * ------------------
 * Una razon social es de alquiler porque esta DADA DE ALTA en Parametros ->
 * Alquileres, no por su rubro: el rubro (RUBRO = ALQUILER en el maestro) solo
 * arma la lista desde donde se la da de alta. Es el mismo criterio que decide
 * que factura de Tango es de alquiler: AlquileresFacturas::esDeAlquiler().
 */
class AlquileresPago {

    /** Los estados de un local */
    const PROYECTA = 'PROYECTA';
    const INCOMPLETO = 'INCOMPLETO';
    const CERRADO_LAKERS = 'CERRADO_LAKERS';
    /** Un local que proyecta segun VIGENCIA cuyo contrato ya vencio (vigente()). */
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
     *   FINALIZADO      proyecta segun la VIGENCIA y todos sus contratos de la
     *                   app vencieron en un mes anterior al actual. Tampoco es
     *                   un incompleto. (Segun APERTURA el vencimiento no corta.)
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

        /* SEGUN VIGENCIA: sin contrato en la app no hay que proyectar (falta el
           dato); con contratos que ya vencieron todos, termino. */
        if ($local !== null && self::porVigencia($local)) {
            $contratos = $local['contratos'] ?? [];

            if (empty($contratos)) {
                return ['estado' => self::INCOMPLETO,
                        'faltan' => ['contrato en la app de alquileres (proyecta según la vigencia)']];
            }

            if (max(array_map(function ($c) { return substr($c['hasta'], 0, 7); }, $contratos)) < $mesActual) {
                return ['estado' => self::FINALIZADO, 'faltan' => []];
            }
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

        /* CADA RAZON SOCIAL QUE FACTURA CONCEPTOS PAGA EN SU DIA: una adicional
           sin dia propio y sin general deja el local incompleto, igual que la
           principal. Una adicional que no factura nada no necesita dia. */
        if ($local !== null && $cod !== null && $cod !== '' && isset($razones[$cod])) {
            $deQuien = self::conceptosPorRazon($local, $razones);

            foreach (array_unique(array_values($deQuien['por_concepto'])) as $c) {
                if ($c !== Planilla::codigo($cod) && ($razones[$c]['dia'] ?? null) === null && $diaGeneral === null) {
                    $faltan[] = 'día de vencimiento de ' . $c . ' (factura conceptos de este local; ni la razón '
                        . 'social ni el general)';
                }
            }
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

    /** Si el local proyecta segun la vigencia del contrato (no segun la apertura) */
    public static function porVigencia($local) {
        return ($local['modo'] ?? null) === 'VIGENCIA';
    }

    /**
     * Si un mes esta dentro de lo que proyecta el local. Segun APERTURA (el de
     * siempre) todos: el vencimiento del contrato no corta, y el cierre lo da
     * Lakers. Segun VIGENCIA, solo los meses cubiertos por ALGUNO de sus
     * contratos de la app (RO_T_CONTRATOS_ALQUILERES), de su mes de inicio a su
     * mes de vencimiento, ambos incluidos. Sin contratos, ninguno.
     *
     * @param array|null $local ['modo', 'contratos' => [['desde', 'hasta']]]
     * @param string $mes 'Y-m'
     * @return bool
     */
    public static function vigente($local, $mes) {
        if ($local === null || !self::porVigencia($local)) {
            return true;
        }

        foreach ($local['contratos'] ?? [] as $c) {
            if ($mes >= substr($c['desde'], 0, 7) && $mes <= substr($c['hasta'], 0, 7)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El contrato que se muestra: el que cubre hoy; si no hay, el proximo; si
     * no, el ultimo que vencio. Null sin contratos.
     *
     * @param array $contratos [['desde', 'hasta']]
     * @param string $hoy 'Y-m-d'
     * @return array|null ['desde', 'hasta', 'cuando' => 'VIGENTE'|'FUTURO'|'VENCIDO']
     */
    public static function contratoActual($contratos, $hoy) {
        $futuro = null;
        $ultimo = null;

        foreach ($contratos as $c) {
            if ($c['desde'] <= $hoy && $c['hasta'] >= $hoy) {
                return $c + ['cuando' => 'VIGENTE'];
            }

            if ($c['desde'] > $hoy && ($futuro === null || $c['desde'] < $futuro['desde'])) {
                $futuro = $c;
            }

            if ($c['hasta'] < $hoy && ($ultimo === null || $c['hasta'] > $ultimo['hasta'])) {
                $ultimo = $c;
            }
        }

        return ($futuro !== null) ? $futuro + ['cuando' => 'FUTURO']
            : (($ultimo !== null) ? $ultimo + ['cuando' => 'VENCIDO'] : null);
    }

    /**
     * Las razones sociales de un local: la principal primero y despues las
     * adicionales, sin repetir. Cada una factura sus conceptos
     * (conceptosPorRazon()) y esos se pagan con SU dia de vencimiento y SU
     * diferimiento; sus facturas se imputan a sus locales y sus extraordinarios
     * los alcanzan.
     *
     * @param array|null $local La fila de Parametros (['cod_provee', 'adicionales'])
     * @return string[]
     */
    public static function razonesDelLocal($local) {
        if ($local === null) {
            return [];
        }

        $out = [];

        foreach (array_merge([$local['cod_provee'] ?? null], $local['adicionales'] ?? []) as $c) {
            $c = Planilla::codigo($c);

            if ($c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * De que razon social es cada concepto proyectado de un local.
     *
     * POR DEFECTO TODO ES DE LA PRINCIPAL. Una adicional factura los conceptos
     * que se le marcaron en Parametros (script 62), y esos SALEN de la
     * principal: San Justo, SAUCCS el 6, 7, 11, 16 y 17 y SASANJ el resto. Es lo
     * que decide con que dia y diferimiento se paga cada concepto, bajo que
     * razon social se ve y que pisa una factura imputada de esa razon.
     *
     * UNA ADICIONAL DADA DE BAJA en Razones sociales no se lleva sus conceptos:
     * vuelven a la principal, con su dia y su diferimiento, y quedan en
     * 'devueltos' para el aviso. Un alquiler no puede desaparecer del flujo
     * porque se dio de baja una razon social.
     *
     * Si dos adicionales dijeran el mismo concepto (el servidor no lo deja
     * guardar, pero la tabla no lo impide), gana la primera que se agrego.
     *
     * @param array|null $local La fila de Parametros (['cod_provee', 'adicionales',
     *        'conceptos_razon' => [cod => [ID_CA]]])
     * @param array $razones COD_PROVEE => ... de las dadas de alta
     * @return array ['por_concepto' => [ID_CA => cod], 'devueltos' => [cod => [ID_CA]],
     *                'sin_conceptos' => [cod] (adicionales dadas de alta que no facturan nada)]
     */
    public static function conceptosPorRazon($local, $razones) {
        $out = ['por_concepto' => [], 'devueltos' => [], 'sin_conceptos' => []];
        $todas = self::razonesDelLocal($local);

        if (empty($todas)) {
            return $out;
        }

        $alta = [];

        foreach (array_keys($razones) as $c) {
            $alta[Planilla::codigo($c)] = true;
        }

        $principal = $todas[0];
        $proyectados = AlquileresConceptos::proyectados();

        foreach ($proyectados as $id) {
            $out['por_concepto'][$id] = $principal;
        }

        $marcados = [];

        foreach ($local['conceptos_razon'] ?? [] as $c => $ids) {
            $marcados[Planilla::codigo($c)] = array_values(array_intersect(array_map('intval', $ids), $proyectados));
        }

        foreach (array_slice($todas, 1) as $cod) {
            $ids = $marcados[$cod] ?? [];

            if (!isset($alta[$cod])) {
                if (!empty($ids)) {
                    $out['devueltos'][$cod] = $ids;
                }
                continue;
            }

            if (empty($ids)) {
                $out['sin_conceptos'][] = $cod;
                continue;
            }

            foreach ($ids as $id) {
                if ($out['por_concepto'][$id] === $principal) {
                    $out['por_concepto'][$id] = $cod;
                }
            }
        }

        return $out;
    }

    /**
     * Si un extraordinario alcanza a un local.
     *
     * Con "todos los locales", alcanza a todo local que HOY tenga esa razon
     * social -principal o adicional-, incluido uno que se le asigno despues de
     * cargar el extraordinario.
     *
     * @param array $extra ['cod_provee', 'todos', 'locales' => [nro]]
     * @param int $nro
     * @param string|string[]|null $codLocal Las razones sociales del local (razonesDelLocal())
     * @return bool
     */
    public static function alcanza($extra, $nro, $codLocal) {
        $cods = array_map(['Planilla', 'codigo'], array_filter((array) $codLocal, function ($c) { return $c !== null; }));

        if (!in_array(Planilla::codigo($extra['cod_provee']), $cods, true)) {
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
     *     'local' => ['pct_iva', 'pct_percepcion', 'pct_retencion', 'cod_provee', 'modo',
     *                 'contratos']. Segun APERTURA el vencimiento del contrato no corta
     *                (se asume la renovacion; un cierre real es HABILITADO = 0 en
     *                Lakers); segun VIGENCIA solo se proyectan los meses de sus
     *                contratos de la app (vigente()),
     *     'razon' => ['dia' => int|null, 'diferimiento' => int]  la principal,
     *     'razones' => COD_PROVEE => ['dia', 'diferimiento']  opcional: las dadas de
     *                  alta. Sin el, todo se paga con 'razon',
     *     'concepto_razon' => ID_CA => COD_PROVEE  opcional: conceptosPorRazon()['por_concepto'].
     *                  Sin el, todo es de la principal,
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
     *         'mes_concepto', 'origen', 'cod_provee' (la razon que lo factura),
     *         + importes() + diaPago() + 'detalle']
     */
    public static function pagosLocal($e) {
        /* CADA CONCEPTO SE PAGA CON EL DIA Y EL DIFERIMIENTO DE LA RAZON SOCIAL
           QUE LO FACTURA, no con los de la principal: en San Justo las
           expensas son de SAUCCS y se pagan cuando vence SAUCCS. */
        $principal = Planilla::codigo($e['local']['cod_provee'] ?? '');
        $razones = [];

        foreach ($e['razones'] ?? [] as $c => $r) {
            $razones[Planilla::codigo($c)] = $r;
        }

        $razones[$principal] = $razones[$principal] ?? $e['razon'];
        $deConcepto = $e['concepto_razon'] ?? [];
        $enHorizonte = array_flip($e['meses_pago']);
        $meses = $e['proyeccion']['meses'];
        $dias = [];
        $pagos = [];

        $razonDe = function ($cod) use ($razones, $principal) {
            return isset($razones[$cod]) ? $cod : $principal;
        };

        $diaDe = function ($mesPago, $cod) use (&$dias, $razones, $e) {
            if (!isset($dias[$cod][$mesPago])) {
                $dia = $razones[$cod]['dia'] ?? $e['dia_general'];
                $dias[$cod][$mesPago] = self::diaPago($mesPago, $dia, $e['habiles'], $e['hoy']);
            }
            return $dias[$cod][$mesPago];
        };

        foreach ($meses as $mesConcepto => $m) {
            /* Segun VIGENCIA, solo los meses del contrato. Un variable de un mes
               vigente que se paga diferido despues si se paga: la venta existio. */
            if (!self::vigente($e['local'], $mesConcepto)) {
                continue;
            }

            foreach ($m['conceptos'] as $id => $c) {
                $grupo = AlquileresConceptos::grupo($id);
                $cod = $razonDe($deConcepto[$id] ?? $principal);
                $difer = (int) $razones[$cod]['diferimiento'];
                $mesPago = AlquileresConceptos::seDifiere($id) ? Inflacion::mesMas($mesConcepto, $difer) : $mesConcepto;

                if (!isset($enHorizonte[$mesPago])) {
                    continue;
                }

                $detalle = $c;
                unset($detalle['importe']);

                $pagos[] = ['cod_provee' => $cod] + self::pago($mesPago, $diaDe($mesPago, $cod), $id, $grupo,
                    $mesConcepto, $c['origen'] ?? $m['origen'], $c['importe'], $e['local'], $detalle);
            }
        }

        foreach ($e['extras'] as $x) {
            foreach ($e['meses_pago'] as $mesPago) {
                $mesBase = Inflacion::mesMas($mesPago, -(int) $x['diferimiento']);

                if ((int) substr($mesBase, 5, 2) !== (int) $x['mes_base'] || !self::vigente($e['local'], $mesBase)) {
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

                /* EL EXTRAORDINARIO ES DE SU RAZON SOCIAL: se paga en su dia y
                   lo pisa una factura de ella, no de la principal. */
                $cod = $razonDe(Planilla::codigo($x['cod_provee'] ?? $principal));

                $pagos[] = ['cod_provee' => $cod] + self::pago($mesPago, $diaDe($mesPago, $cod), 'EXTRA:' . $x['id'],
                    AlquileresConceptos::EXTRA, $mesBase, $base['origen'], $suma * (float) $x['pct'] / 100, $e['local'],
                    ['extra' => $x['id'], 'pct' => (float) $x['pct'], 'base' => $partes, 'base_total' => $suma]);
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
