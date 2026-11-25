<?php

require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/PrestamosCalculo.php';
require_once __DIR__ . '/PrestamosParametros.php';
require_once __DIR__ . '/PrestamosTasas.php';

/**
 * Prestamos
 * Arma lo que piden la pestaña Financiero › Prestamos, Parametros › Prestamos
 * y el proveedor del tablero. NO TIENE REGLAS PROPIAS: la cuenta es de
 * PrestamosCalculo (puro), las tasas de PrestamosTasas y los datos de
 * PrestamosParametros. Aca solo se lee, se junta y se agrupa.
 *
 * NUNCA LANZA al leer: una lectura que falla deja un aviso y el resto sigue.
 */
class Prestamos {

    /** @var string */
    private $hoy;

    /** @var PrestamosParametros */
    private $par;

    /** @var PrestamosTasas */
    private $tasas;

    function __construct($hoy = null, $par = null, $tasas = null) {
        $this->hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $this->par = ($par === null) ? new PrestamosParametros() : $par;
        $this->tasas = ($tasas === null) ? new PrestamosTasas() : $tasas;
    }

    public function hoy() {
        return $this->hoy;
    }

    public function par() {
        return $this->par;
    }

    /* ====================================================================
       LAS CUENTAS BANCARIAS
       ==================================================================== */

    /**
     * Las cuentas bancarias para el desplegable: las mismas que muestra Saldos
     * (getFilasDisponible()): las de Interbanking y las cuentas BANCO del
     * catalogo que no son fondo. Informativo en esta etapa.
     *
     * @param array $avisos Se agregan los de la lectura
     * @return array clave => nombre
     */
    public function cuentas(&$avisos = []) {
        try {
            require_once __DIR__ . '/Saldos.php';
            $r = (new Saldos())->getFilasDisponible($this->hoy);

            return self::cuentasDe($r['filas'] ?? []);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer las cuentas bancarias de Saldos ('
                . $e->getMessage() . '): no se puede elegir el banco de un préstamo nuevo.');

            return [];
        }
    }

    /**
     * Pura: de las filas de Saldos, las bancarias. Las de Interbanking por su
     * clave; las del catalogo por su ID, si son BANCO y no son fondo.
     */
    public static function cuentasDe($filas) {
        $out = [];

        foreach ($filas as $f) {
            if (strtoupper((string) ($f['tipo'] ?? '')) !== 'BANCO') {
                continue;
            }

            if (!empty($f['clave'])) {
                $clave = 'IB|' . $f['clave'];
            } elseif (!empty($f['id_cuenta'])) {
                if (in_array(strtoupper((string) ($f['clase'] ?? '')), ['INVERSION', 'COMITENTE'], true)) {
                    continue;
                }

                $clave = 'CTA_' . (int) $f['id_cuenta'];
            } else {
                continue;
            }

            $out[$clave] = trim((string) ($f['nombre'] ?? $clave)) . (empty($f['moneda']) ? '' : ' (' . $f['moneda'] . ')');
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /* ====================================================================
       LAS TASAS DE REFERENCIA
       ==================================================================== */

    /** Lo leido de XL-APPS, el error si lo hubo, los dias del promedio y lo manual. */
    public function insumosTasas($generales, $manual) {
        $leido = $this->tasas->leer();

        return [
            'leido' => $leido,
            'error' => $this->tasas->error(),
            'calculo' => ['disponible' => !empty($leido['disponible']) && $this->tasas->error() === null,
                          'series' => $leido['series'], 'n' => (int) $generales['DIAS_PROMEDIO'], 'manual' => $manual]
        ];
    }

    /**
     * "Ultima TAMAR publicada: XX % (dd/mm)" y "Promedio ultimos N dias habiles".
     *
     * @return array ref => ['ultimo' => ['valor', 'fecha'] | null, 'promedio' => [...] | null]
     */
    public static function resumenTasas($series, $n) {
        $out = [];

        foreach (PrestamosTasas::NOMBRES as $ref => $nombre) {
            $out[$ref] = ['nombre' => $nombre, 'ultimo' => PrestamosTasas::ultimo($series[$ref] ?? []),
                          'promedio' => PrestamosTasas::promedio($series[$ref] ?? [], $n)];
        }

        return $out;
    }

    /** El calendario bancario de un rango. Sin el, lunes a viernes, con aviso. */
    public function habiles($desde, $hasta, &$avisos) {
        try {
            require_once __DIR__ . '/CronogramaDatos.php';

            return (new CronogramaDatos())->habilesEntre($desde, $hasta);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer el calendario bancario (' . $e->getMessage()
                . '): las cuotas se corren al hábil asumiendo hábiles los días de lunes a viernes.');

            return [];
        }
    }

    /* ====================================================================
       PARAMETROS › PRESTAMOS
       ==================================================================== */

    /** Todo lo que necesita la sub-pestaña de Parametros. */
    public function parametros() {
        $avisos = [];
        $tabla = $this->par->tablaCreada();
        $out = ['tabla_creada' => $tabla, 'script' => PrestamosParametros::SCRIPT, 'generales' => [], 'costos' => [],
                'tramos' => [], 'tasas_manual' => [], 'referencias' => [], 'listas' => self::listas()];

        if (!$tabla) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, PrestamosParametros::avisoSinTabla());
        }

        try {
            $out['generales'] = $this->par->generales();

            if ($tabla) {
                $out['costos'] = $this->par->costosParam();
                $out['tramos'] = $this->par->tramosParam();
                $out['tasas_manual'] = $this->par->tasasManual()['lista'];
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los parámetros de Préstamos (' . $e->getMessage() . ').');
        }

        $leido = $this->tasas->leer();
        $error = $this->tasas->error();
        $n = (int) ($out['generales']['DIAS_PROMEDIO'] ?? PrestamosTasas::DIAS_PROMEDIO_INICIAL);
        $out['referencias'] = self::resumenTasas($leido['series'], $n);
        $out['dias_promedio'] = $n;

        $habiles = $this->habiles(date('Y-m-d', strtotime($this->hoy . ' -40 days')), $this->hoy, $avisos);
        $avisos = array_merge($avisos, PrestamosTasas::avisos($leido, $error, array_keys(PrestamosTasas::NOMBRES), $this->hoy,
            $habiles, (int) ($out['generales']['ANTIGUEDAD_MAX'] ?? PrestamosTasas::ANTIGUEDAD_MAX_INICIAL)));

        $out['avisos'] = $avisos;

        return $out;
    }

    /* ====================================================================
       EL CALCULO DE TODOS LOS PRESTAMOS
       ==================================================================== */

    /**
     * Todo lo que PrestamosCalculo necesita, leido una vez: parametros,
     * prestamos, la alicuota de IVA, las tasas (si hay algun variable), el
     * calendario y la curva (si hay alguno en dolares). Nunca lanza.
     *
     * @return array
     */
    public function insumos() {
        $avisos = [];
        $ins = ['tabla_creada' => $this->par->tablaCreada(), 'generales' => [], 'prestamos' => [], 'manual' => [],
                'iva' => 0.21, 'tasas' => ['leido' => ['series' => [], 'log' => [], 'disponible' => false], 'error' => null,
                'calculo' => ['disponible' => false, 'series' => [], 'n' => PrestamosTasas::DIAS_PROMEDIO_INICIAL, 'manual' => []]],
                'habiles' => [], 'curva' => [], 'avisos' => []];

        foreach (PrestamosParametros::GENERALES as $k => $g) {
            $ins['generales'][$k] = $g['defecto'];
        }

        if (!$ins['tabla_creada']) {
            $ins['avisos'][] = Aviso::nuevo(Aviso::DANGER, PrestamosParametros::avisoSinTabla());

            return $ins;
        }

        try {
            $ins['generales'] = $this->par->generales();
            $ins['prestamos'] = $this->par->prestamos();
            $ins['manual'] = $this->par->tasasManual()['mapa'];
        } catch (Throwable $e) {
            $ins['avisos'][] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los préstamos (' . $e->getMessage() . ').');

            return $ins;
        }

        if (empty($ins['prestamos'])) {
            return $ins;
        }

        try {
            require_once __DIR__ . '/Parametros.php';
            $ins['iva'] = Parametros::num((new Parametros())->getParametrosMap(), 'alicuota_iva');
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la alícuota de IVA de Parámetros › Generales ('
                . $e->getMessage() . '): los costos con IVA se calculan con el 21 %.');
        }

        $hayVariable = false;
        $hayUsd = false;
        $desde = null;
        $hasta = null;

        foreach ($ins['prestamos'] as $d) {
            $p = $d['prestamo'];
            $hayVariable = $hayVariable || $p['tipo_tasa'] === PrestamosCalculo::VARIABLE;
            $hayUsd = $hayUsd || $p['moneda'] === 'USD';
            $fin = PrestamosCalculo::sumarMeses($p['fecha_primera'] ?: $p['fecha_desembolso'],
                $p['cuotas'] * (int) PrestamosCalculo::meses($p['frec_capital']) + 2);
            $desde = ($desde === null || $p['fecha_desembolso'] < $desde) ? $p['fecha_desembolso'] : $desde;
            $hasta = ($hasta === null || $fin > $hasta) ? $fin : $hasta;
        }

        if ($hayVariable) {
            $ins['tasas'] = $this->insumosTasas($ins['generales'], $ins['manual']);
        }

        $ins['habiles'] = $this->habiles($desde, $hasta, $avisos);

        if ($hayUsd) {
            try {
                require_once __DIR__ . '/DolarFuturo.php';
                $df = new DolarFuturo();
                $ins['curva'] = $df->curva();

                if ($df->error() !== null) {
                    $avisos[] = Aviso::nuevo(Aviso::WARNING, $df->error() . ' Los préstamos en dólares no se pasan a pesos.');
                }
            } catch (Throwable $e) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la curva del dólar futuro (' . $e->getMessage()
                    . '): los préstamos en dólares no se pasan a pesos.');
            }
        }

        $ins['avisos'] = $avisos;

        return $ins;
    }

    /**
     * Corre PrestamosCalculo para cada prestamo. Un prestamo que no se puede
     * calcular deja un aviso y no tumba a los demas.
     *
     * @param array $ins Lo de insumos()
     * @return array ['resultados' => [id => ['datos', 'r']], 'avisos' => [...], 'avisos_por' => [id => [...]]]
     */
    public function calcular($ins) {
        $out = ['resultados' => [], 'avisos' => $ins['avisos'], 'avisos_por' => []];
        $refs = [];

        foreach ($ins['prestamos'] as $id => $d) {
            try {
                $r = PrestamosCalculo::proyectar([
                    'hoy' => $this->hoy, 'prestamo' => $d['prestamo'], 'costos' => $d['costos'], 'iva' => $ins['iva'],
                    'ajustes' => $d['ajustes'], 'cancelaciones' => $d['cancelaciones'], 'tasas' => $ins['tasas']['calculo'],
                    'habiles' => $ins['habiles'], 'curva' => $ins['curva']
                ]);
            } catch (Throwable $e) {
                $r = ['cuotas' => [], 'pagos' => [], 'proyectable' => false, 'resumen' => null,
                      'avisos' => [Aviso::nuevo(Aviso::DANGER, '«' . $d['prestamo']['nombre'] . '» no se pudo calcular ('
                          . $e->getMessage() . '): no suma nada. Revisá sus datos.')]];
            }

            $out['resultados'][$id] = ['datos' => $d, 'r' => $r];
            $out['avisos_por'][$id] = $r['avisos'];

            $estado = $r['resumen']['estado'] ?? $d['prestamo']['estado'];

            if ($d['prestamo']['tipo_tasa'] === PrestamosCalculo::VARIABLE
                && in_array($estado, [PrestamosCalculo::VIGENTE, PrestamosCalculo::SIMULADO], true)) {
                $refs[$d['prestamo']['referencia']] = true;
            }
        }

        $habiles = $ins['habiles'];
        $out['avisos_tasas'] = PrestamosTasas::avisos($ins['tasas']['leido'], $ins['tasas']['error'], array_keys($refs),
            $this->hoy, $habiles, (int) ($ins['generales']['ANTIGUEDAD_MAX'] ?? PrestamosTasas::ANTIGUEDAD_MAX_INICIAL));

        return $out;
    }

    /**
     * Si un prestamo entra segun el interruptor de simulaciones: los no
     * simulados siempre; los simulados con true, o si su id esta en la lista.
     *
     * @param array $p El prestamo
     * @param bool|array $sim false, true o lista de ids
     */
    public static function incluido($p, $sim) {
        if (($p['estado'] ?? '') !== PrestamosCalculo::SIMULADO) {
            return true;
        }

        if ($sim === true) {
            return true;
        }

        return is_array($sim) && in_array((int) $p['id'], array_map('intval', $sim), true);
    }

    /**
     * Los pagos del tablero: LO ANTERIOR A HOY NO VA, porque ya esta en el
     * Saldo Inicial (como en Llaves); lo de hoy va como real. Sin importe en
     * pesos (USD sin dolar futuro) tampoco: ya lo dice un aviso.
     *
     * @param array $resultados Lo de calcular()['resultados']
     * @param bool|array $sim El interruptor
     * @return array Lista de pagos
     */
    public static function pagosTablero($resultados, $hoy, $sim) {
        $out = [];

        foreach ($resultados as $x) {
            if (!self::incluido($x['datos']['prestamo'], $sim)) {
                continue;
            }

            foreach ($x['r']['pagos'] as $pg) {
                if ($pg['fecha'] < $hoy || $pg['importe'] === null) {
                    continue;
                }

                $out[] = $pg;
            }
        }

        return $out;
    }

    /** Los avisos que llegan al tablero: los de las lecturas, las tasas y los prestamos que entran. */
    public static function avisosTablero($calc, $sim) {
        $out = array_merge($calc['avisos'], $calc['avisos_tasas'] ?? []);

        foreach ($calc['resultados'] as $id => $x) {
            if (self::incluido($x['datos']['prestamo'], $sim)) {
                $out = array_merge($out, $calc['avisos_por'][$id] ?? []);
            }
        }

        return $out;
    }

    /* ====================================================================
       LA PESTAÑA
       ==================================================================== */

    /**
     * Todo lo de la pestaña en un pedido: la lista con el cronograma de cada
     * prestamo, la proyeccion, las tarjetas y lo que necesita el formulario.
     *
     * @param Horizonte $h
     */
    public function pestana($h) {
        $ins = $this->insumos();
        $calc = $this->calcular($ins);
        $avisos = array_merge($calc['avisos'], $calc['avisos_tasas']);

        foreach ($calc['avisos_por'] as $a) {
            $avisos = array_merge($avisos, $a);
        }

        $cuentas = $this->cuentas($avisos);
        $copia = ['costos' => [], 'cancelacion' => null];

        if ($ins['tabla_creada']) {
            try {
                $copia = PrestamosParametros::copiaParaAlta($ins['generales'], $this->par->costosParam(), $this->par->tramosParam());
            } catch (Throwable $e) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los costos de Parámetros (' . $e->getMessage() . ').');
            }
        }

        $tc = self::tcHoy($ins['curva'], $this->hoy);

        return [
            'tabla_creada' => $ins['tabla_creada'],
            'script' => PrestamosParametros::SCRIPT,
            'hoy' => $this->hoy,
            'listas' => self::listas(),
            'generales' => $ins['generales'],
            'cuentas' => $cuentas,
            'alta' => $copia,
            'tasas' => self::resumenTasas($ins['tasas']['leido']['series'], (int) $ins['generales']['DIAS_PROMEDIO']),
            'prestamos' => self::armarLista($calc, $ins['generales'], $this->hoy),
            'proyeccion' => self::armarProyeccion($calc['resultados'], $h, $this->hoy),
            'kpis' => self::kpis($calc['resultados'], $h, $this->hoy, $tc),
            'avisos' => $avisos
        ];
    }

    /** El dolar de hoy (curva del mes), para expresar en pesos la deuda en dolares. */
    public static function tcHoy($curva, $hoy) {
        if (empty($curva)) {
            return null;
        }

        require_once __DIR__ . '/DolarFuturo.php';

        return DolarFuturo::resolver($curva, $hoy)['cotizacion'];
    }

    /** El texto del pie del cronograma de un prestamo variable. */
    public static function criterio($p, $n) {
        if ($p['tipo_tasa'] !== PrestamosCalculo::VARIABLE) {
            return null;
        }

        $ref = PrestamosTasas::NOMBRES[$p['referencia']] ?? $p['referencia'];

        return ($p['criterio_tasa'] === PrestamosTasas::ULTIMO)
            ? 'Proyección calculada asumiendo constante el último valor publicado de ' . $ref . '.'
            : 'Proyección calculada asumiendo constante la tasa de referencia (' . $ref . ') promedio de los últimos '
              . (int) $n . ' días hábiles.';
    }

    /** La lista de la sub-pestaña Prestamos, con el cronograma de cada uno. Pura. */
    public static function armarLista($calc, $generales, $hoy) {
        $out = [];

        foreach ($calc['resultados'] as $id => $x) {
            $p = $x['datos']['prestamo'];
            $r = $x['r'];
            $res = $r['resumen'] ?? [];
            $estado = $res['estado'] ?? $p['estado'];
            $tasa = ($p['tipo_tasa'] === PrestamosCalculo::FIJA)
                ? 'Fija ' . PrestamosCalculo::pct($p['tna'])
                : (PrestamosTasas::NOMBRES[$p['referencia']] ?? $p['referencia']) . ' + ' . PrestamosCalculo::pct($p['spread']);
            $proxima = $res['proxima'] ?? null;

            if ($proxima !== null) {
                $proxima['ars'] = null;

                foreach ($r['pagos'] as $pg) {
                    if ($pg['fecha'] === $proxima['fecha'] && $pg['concepto'] !== PrestamosCalculo::DESEMBOLSO && $pg['importe'] !== null) {
                        $proxima['ars'] = round(($proxima['ars'] ?? 0) + $pg['importe'], 2);
                    }
                }
            }

            $out[] = [
                'id' => (int) $id,
                'nombre' => $p['nombre'],
                'cuenta' => $p['cuenta_nombre'],
                'moneda' => $p['moneda'],
                'capital' => $p['capital'],
                'tasa' => $tasa,
                'sistema' => PrestamosCalculo::SISTEMAS[$p['sistema']] ?? $p['sistema'],
                'cuotas' => $p['cuotas'],
                'frecuencias' => PrestamosCalculo::NOMBRE_FRECUENCIA[$p['frec_capital']]
                    . ($p['frec_intereses'] !== $p['frec_capital'] ? ' / intereses ' . strtolower(PrestamosCalculo::NOMBRE_FRECUENCIA[$p['frec_intereses']]) : ''),
                'estado' => $estado,
                'nombre_estado' => PrestamosCalculo::NOMBRE_ESTADO[$estado] ?? $estado,
                'simulado' => ($p['estado'] === PrestamosCalculo::SIMULADO),
                'proyectable' => !empty($r['proyectable']),
                'saldo_hoy' => $res['saldo_hoy'] ?? null,
                'proxima' => $proxima,
                'tna_actual' => $res['tna_actual'] ?? null,
                'tea' => $res['tea'] ?? null,
                'cft' => $res['cft'] ?? null,
                'costos_iniciales' => $res['costos_iniciales'] ?? 0,
                'cancelada_el' => $res['cancelada_el'] ?? null,
                'puede_eliminar' => ($p['estado'] === PrestamosCalculo::SIMULADO),
                'puede_cancelar' => ($estado === PrestamosCalculo::VIGENTE),
                'puede_vigente' => ($p['estado'] === PrestamosCalculo::SIMULADO),
                'cancelaciones' => array_map(function ($c) use ($hoy) {
                    return $c + ['deshacible' => ($c['fecha'] >= $hoy)];
                }, $x['datos']['cancelaciones']),
                'costos' => $x['datos']['costos'],
                'cronograma' => $r['cuotas'],
                'criterio' => self::criterio($p, $generales['DIAS_PROMEDIO'] ?? PrestamosTasas::DIAS_PROMEDIO_INICIAL),
                'avisos' => $calc['avisos_por'][$id] ?? [],
                'datos' => $p
            ];
        }

        return $out;
    }

    /**
     * La grilla de Proyeccion: prestamo -> concepto, con el desembolso en
     * positivo y lo que se paga en negativo. Agrupa con EjeVista la MISMA
     * lista de pagos que suma el tablero (pagosTablero(), con todos los
     * simulados), asi que la pestaña y el tablero dicen lo mismo columna por
     * columna; lo fija una prueba. Trae dos totales: el oficial (sin
     * simulados) y con los simulados, para el interruptor de la pestaña.
     */
    public static function armarProyeccion($resultados, $h, $hoy) {
        require_once __DIR__ . '/EjeVista.php';

        $items = [];
        $oficial = [];
        $todos = [];

        foreach ($resultados as $id => $x) {
            $sim = ($x['datos']['prestamo']['estado'] === PrestamosCalculo::SIMULADO);

            foreach (self::pagosTablero([$id => $x], $hoy, true) as $pg) {
                $v = ($pg['concepto'] === PrestamosCalculo::DESEMBOLSO) ? $pg['importe'] : -$pg['importe'];
                $items[] = ['fila' => 'P|' . $id, 'fecha' => $pg['fecha'], 'importe' => $v];
                $items[] = ['fila' => 'C|' . $id . '|' . $pg['concepto'], 'fecha' => $pg['fecha'], 'importe' => $v];
                $t = ['fila' => 'T', 'fecha' => $pg['fecha'], 'importe' => $v];
                $todos[] = $t;

                if (!$sim) {
                    $oficial[] = $t;
                }
            }
        }

        $eje = EjeVista::armarAgrupado($h, $items, 'fila', 'fecha', 'importe');
        $porClave = [];

        foreach ($eje['filas'] as $f) {
            $porClave[$f['fila']] = $f;
        }

        $filas = [];

        foreach ($resultados as $id => $x) {
            $p = $x['datos']['prestamo'];

            if (!isset($porClave['P|' . $id])) {
                continue;
            }

            $sim = ($p['estado'] === PrestamosCalculo::SIMULADO);
            $filas[] = ['id' => 'P|' . $id, 'nivel' => 0, 'padre' => null, 'nombre' => $p['nombre'],
                        'sub' => $p['moneda'] . ($sim ? ' · simulado' : ''), 'simulado' => $sim, 'eje' => $porClave['P|' . $id]];

            foreach (array_keys(PrestamosCalculo::NOMBRE_CONCEPTO) as $c) {
                if (isset($porClave['C|' . $id . '|' . $c])) {
                    $filas[] = ['id' => 'C|' . $id . '|' . $c, 'nivel' => 1, 'padre' => 'P|' . $id,
                                'nombre' => PrestamosCalculo::NOMBRE_CONCEPTO[$c], 'sub' => '', 'simulado' => $sim,
                                'concepto' => $c, 'eje' => $porClave['C|' . $id . '|' . $c]];
                }
            }
        }

        $eje['filas'] = $filas;
        $eje['totales'] = EjeVista::armarAgrupado($h, $oficial, 'fila', 'fecha', 'importe')['totales'];
        $eje['totales_sim'] = EjeVista::armarAgrupado($h, $todos, 'fila', 'fecha', 'importe')['totales'];

        return $eje;
    }

    /** Las tarjetas. La necesidad de financiamiento se calcula a pedido (Simulador). Pura. */
    public static function kpis($resultados, $h, $hoy, $tcHoy) {
        $deuda = 0.0;
        $deudaUsdSinTc = 0.0;
        $cuotas = 0.0;
        $proxima = null;
        $simN = 0;
        $simCapital = 0.0;
        $vacia = $h->serieVacia();

        foreach ($resultados as $x) {
            $p = $x['datos']['prestamo'];
            $res = $x['r']['resumen'] ?? [];

            if ($p['estado'] === PrestamosCalculo::SIMULADO) {
                $simN++;
                $simCapital += ($p['moneda'] === 'USD') ? ($tcHoy === null ? 0 : $p['capital'] * $tcHoy) : $p['capital'];
                continue;
            }

            if (($res['estado'] ?? null) === PrestamosCalculo::VIGENTE) {
                $s = (float) ($res['saldo_hoy'] ?? 0);

                if ($p['moneda'] === 'USD') {
                    if ($tcHoy === null) {
                        $deudaUsdSinTc += $s;
                    } else {
                        $deuda += $s * $tcHoy;
                    }
                } else {
                    $deuda += $s;
                }
            }

            foreach ($x['r']['pagos'] as $pg) {
                if ($pg['concepto'] === PrestamosCalculo::DESEMBOLSO || $pg['fecha'] < $hoy || $pg['importe'] === null) {
                    continue;
                }

                if (Horizonte::ubicar($vacia, $pg['fecha']) !== null) {
                    $cuotas += $pg['importe'];
                }

                if ($proxima === null || $pg['fecha'] < $proxima['fecha']) {
                    $proxima = ['fecha' => $pg['fecha'], 'importe' => 0.0, 'nombre' => $p['nombre'], 'ids' => []];
                }

                if ($pg['fecha'] === $proxima['fecha']) {
                    $proxima['importe'] += $pg['importe'];
                    $proxima['ids'][$p['nombre']] = true;
                }
            }
        }

        if ($proxima !== null) {
            $proxima['nombre'] = implode(', ', array_keys($proxima['ids']));
            unset($proxima['ids']);
            $proxima['importe'] = round($proxima['importe'], 2);
        }

        return ['deuda' => round($deuda, 2), 'deuda_usd_sin_tc' => round($deudaUsdSinTc, 2), 'cuotas_horizonte' => round($cuotas, 2),
                'proxima' => $proxima, 'simulados_n' => $simN, 'simulados_capital' => round($simCapital, 2), 'necesidad' => null];
    }

    /* ====================================================================
       CANCELACION: SIMULAR ANTES DE REGISTRAR
       ==================================================================== */

    /**
     * Un prestamo calculado, y si se pasa una cancelacion propuesta, tambien
     * con ella (sin registrarla). Lanza si el prestamo no existe.
     *
     * @return array ['datos', 'sin', 'con', 'p' (normalizado)]
     */
    public function uno($id, $propuesta = null) {
        $ins = $this->insumos();
        $id = (int) $id;

        if (!isset($ins['prestamos'][$id])) {
            throw new InvalidArgumentException('Ese préstamo ya no existe. Volvé a cargar la pantalla.');
        }

        $d = $ins['prestamos'][$id];
        $e = ['hoy' => $this->hoy, 'prestamo' => $d['prestamo'], 'costos' => $d['costos'], 'iva' => $ins['iva'],
              'ajustes' => $d['ajustes'], 'cancelaciones' => $d['cancelaciones'], 'tasas' => $ins['tasas']['calculo'],
              'habiles' => $ins['habiles'], 'curva' => $ins['curva']];
        $sin = PrestamosCalculo::proyectar($e);
        $con = null;

        if ($propuesta !== null) {
            $e['cancelaciones'][] = $propuesta;
            $con = PrestamosCalculo::proyectar($e);
        }

        return ['datos' => $d, 'sin' => $sin, 'con' => $con, 'p' => PrestamosCalculo::normalizar($d['prestamo'])];
    }

    /**
     * Valida una cancelacion contra el prestamo calculado de hoy y, si es
     * valida, la compara con seguir pagando. No registra nada.
     *
     * @return array ['validada' => para registrar, 'comparacion' => compararCancelacion()]
     */
    public function simularCancelacion($id, $datos) {
        $base = $this->uno($id);
        $cal = PrestamosCalculo::calendario($base['p']);
        $hayTotal = false;

        foreach ($base['datos']['cancelaciones'] as $c) {
            $hayTotal = $hayTotal || $c['tipo'] === PrestamosCalculo::TOTAL;
        }

        $fecha = substr((string) ($datos['fecha'] ?? ''), 0, 10);
        $v = PrestamosParametros::validarCancelacion($datos, $base['p'], $base['sin']['resumen']['estado'] ?? null,
            self::saldoA($base['sin']['cuotas'], $base['p']['capital'], $fecha), end($cal)['fecha'], $hayTotal);
        $r = $this->uno($id, ['id' => null] + $v);

        return ['validada' => $v, 'moneda' => $base['p']['moneda'],
                'comparacion' => self::compararCancelacion($r['sin'], $r['con'], $v['fecha'])];
    }

    /** El saldo de capital de un cronograma a una fecha: lo amortizado hasta ese dia inclusive. */
    public static function saldoA($cuotas, $capital, $fecha) {
        $s = $capital;

        foreach ($cuotas as $f) {
            if ($f['tipo'] !== PrestamosCalculo::R_DESEMBOLSO && $f['vencimiento'] <= $fecha) {
                $s -= $f['capital'];
            }
        }

        return round($s, 2);
    }

    /**
     * Cancelar contra seguir pagando. Pura.
     *
     * @param array $sin El calculo sin la cancelacion propuesta
     * @param array $con El calculo con ella
     * @param string $fecha
     * @return array ['total_a_pagar', 'capital', 'devengado', 'comision', 'intereses_sin', 'intereses_con',
     *                'ahorro_intereses', 'ahorro_neto', 'por_mes' => [...], 'fila' => renglon de la cancelacion]
     */
    public static function compararCancelacion($sin, $con, $fecha) {
        $despues = function ($cuotas) use ($fecha) {
            $t = 0.0;

            foreach ($cuotas as $f) {
                if ($f['tipo'] === PrestamosCalculo::R_CUOTA && $f['vencimiento'] > $fecha) {
                    $t += $f['total'] - $f['capital'];
                }
            }

            return $t;
        };

        $fila = null;

        foreach ($con['cuotas'] as $f) {
            if ($f['tipo'] === PrestamosCalculo::R_CANCELACION && $f['vencimiento'] === $fecha) {
                $fila = $f;
            }
        }

        $comision = $fila ? round($fila['cancelacion']['comision'] + $fila['cancelacion']['iva_comision'], 2) : 0.0;
        $intSin = round($despues($sin['cuotas']), 2);
        $intCon = round($despues($con['cuotas']) + ($fila ? $fila['interes'] + $fila['total_costos'] : 0), 2);

        /* Como queda el cashflow: los pagos del prestamo (sin el desembolso), por
           mes, con y sin cancelar, desde la fecha. Positivo = el cashflow queda
           con mas plata ese mes si se cancela. */
        $porMes = [];

        foreach (['sin' => $sin, 'con' => $con] as $k => $r) {
            foreach ($r['pagos'] as $pg) {
                if ($pg['concepto'] === PrestamosCalculo::DESEMBOLSO || $pg['fecha'] < $fecha) {
                    continue;
                }

                $m = substr($pg['fecha'], 0, 7);
                $porMes[$m] = $porMes[$m] ?? ['mes' => $m, 'sin' => 0.0, 'con' => 0.0, 'sin_pesos' => false];

                if ($pg['importe'] === null) {
                    $porMes[$m]['sin_pesos'] = true;
                    continue;
                }

                $porMes[$m][$k] += $pg['importe'];
            }
        }

        ksort($porMes);
        $acum = 0.0;

        foreach ($porMes as &$m) {
            $m['sin'] = round($m['sin'], 2);
            $m['con'] = round($m['con'], 2);
            $m['diferencia'] = round($m['sin'] - $m['con'], 2);
            $acum += $m['diferencia'];
            $m['acumulado'] = round($acum, 2);
        }

        unset($m);

        return [
            'fila' => $fila,
            'total_a_pagar' => $fila ? $fila['total'] : null,
            'capital' => $fila ? $fila['capital'] : null,
            'devengado' => $fila ? $fila['interes'] : null,
            'comision' => $comision,
            'intereses_sin' => $intSin,
            'intereses_con' => $intCon,
            'ahorro_intereses' => round($intSin - $intCon, 2),
            'ahorro_neto' => round($intSin - $intCon - $comision, 2),
            'por_mes' => array_values($porMes)
        ];
    }

    /** Las listas de opciones, con sus nombres de pantalla. */
    public static function listas() {
        return [
            'monedas' => PrestamosParametros::MONEDAS,
            'sistemas' => PrestamosCalculo::SISTEMAS,
            'frecuencias' => PrestamosCalculo::NOMBRE_FRECUENCIA,
            'bases' => PrestamosParametros::BASES,
            'momentos' => PrestamosParametros::MOMENTOS,
            'referencias' => PrestamosTasas::NOMBRES,
            'criterios' => PrestamosTasas::CRITERIOS,
            'formas_pago' => PrestamosParametros::FORMAS_PAGO,
            'estados' => PrestamosCalculo::NOMBRE_ESTADO,
            'origenes_tasa' => PrestamosTasas::NOMBRE_ORIGEN,
            'conceptos' => PrestamosCalculo::NOMBRE_CONCEPTO,
            'comision' => PrestamosParametros::GENERALES['CANCEL_MODO']['lista']
        ];
    }
}
