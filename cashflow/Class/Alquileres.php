<?php

require_once __DIR__ . '/AlquileresApp.php';
require_once __DIR__ . '/AlquileresParametros.php';
require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/AlquileresVenta.php';
require_once __DIR__ . '/AlquileresPago.php';
require_once __DIR__ . '/AlquileresImputacion.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Inflacion.php';

/**
 * Alquileres
 * Arma lo que ven Parametros -> Alquileres y la pestaña RRHH y Operativos ->
 * Alquileres: junta la lectura de la app (AlquileresApp), los parametros del
 * cashflow (AlquileresParametros) y el maestro de proveedores, y se los pasa a
 * las reglas puras (AlquileresCalculo, AlquileresVenta, AlquileresPago).
 *
 * NO TIENE REGLAS PROPIAS. Si aca aparece un "si el local tal, entonces", esta
 * en el lugar equivocado: va en una de las clases puras, con su prueba.
 *
 * NUNCA LANZA POR UN DATO QUE FALTA. Sin el script, sin las tablas de la app o
 * sin el maestro, devuelve lo que puede y un aviso que dice que falta y donde
 * se arregla. La pestaña y Parametros cargan igual, y el resto del cashflow
 * no se entera.
 */
class Alquileres {

    /** @var AlquileresApp */
    protected $app;

    /** @var AlquileresParametros */
    protected $par;

    /** @var string 'Y-m-d' */
    protected $hoy;

    /**
     * @param string|null $hoy 'Y-m-d'; por defecto hoy. Se inyecta para las pruebas.
     * @param AlquileresApp|null $app Se inyecta para las pruebas
     * @param AlquileresParametros|null $par Se inyecta para las pruebas
     */
    function __construct($hoy = null, $app = null, $par = null) {
        $this->app = $app ?: new AlquileresApp();
        $this->par = $par ?: new AlquileresParametros();
        $this->hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
    }

    /* ====================================================================
       PARAMETROS -> ALQUILERES
       ==================================================================== */

    /**
     * Todo lo que la sub-pestaña de Parametros necesita.
     *
     * @return array
     */
    public function parametros() {
        $avisos = [];
        $base = $this->leerBase($avisos);
        $universo = $this->universoRazones($avisos);
        $vigentes = $base['razones'];

        $razonesAlta = [];

        foreach ($vigentes as $cod => $r) {
            $razonesAlta[] = $r + [
                'nombre' => isset($universo[$cod]) ? $universo[$cod]['nombre'] : $r['razon'],
                'en_rubro' => isset($universo[$cod]),
                'locales' => count(array_filter($base['locales'], function ($l) use ($cod) {
                    return ($l['params']['cod_provee'] ?? null) === $cod;
                }))
            ];
        }

        /* LOS QUE PERDIERON EL RUBRO SIGUEN DE ALTA: darlos de baja es una
           decision. Se avisa para que alguien la tome. */
        $sinRubro = array_filter($razonesAlta, function ($r) { return !$r['en_rubro']; });

        if (!empty($sinRubro) && $universo !== null) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($sinRubro) . ' razón(es) social(es) dada(s) de alta '
                . 'ya no tienen RUBRO = ALQUILER en el maestro de Proveedores Locales: '
                . implode(', ', array_map(function ($r) { return $r['nombre'] . ' (' . $r['cod'] . ')'; }, $sinRubro))
                . '. Siguen de alta; si ya no son alquileres, dalas de baja acá.', 'Razones sociales');
        }

        $noDadas = ($universo === null) ? [] : array_diff_key($universo, $vigentes);

        if (!empty($noDadas)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, count($noDadas) . ' proveedores con rubro ALQUILER no están '
                . 'dados de alta: sus facturas siguen en Proveedores Locales. '
                . implode(', ', array_map(function ($p) { return $p['nombre'] . ' (' . $p['cod'] . ')'; }, $noDadas))
                . '.', 'Razones sociales');
        }

        $incompletos = array_filter($base['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::INCOMPLETO || !empty($l['faltan']);
        });

        if (!empty($incompletos)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($incompletos) . ' local(es) no proyectan, o no proyectan '
                . 'todo, porque les falta algún dato: ' . implode('; ', array_map(function ($l) {
                    return $l['nombre'] . ' (' . implode(', ', $l['faltan']) . ')';
                }, $incompletos)) . '. Se completan en la sección Locales; el filtro Incompletos los muestra.',
                'Locales');
        }

        return [
            'tabla_creada' => $this->par->tablaCreada(),
            'script' => AlquileresParametros::SCRIPT,
            'general' => $base['general'],
            'razones' => array_values($razonesAlta),
            'historial_razones' => $base['tablas_ok'] ? $this->par->razones(false) : [],
            'universo_razones' => array_values(array_map(function ($p) use ($vigentes) {
                return $p + ['dada_de_alta' => isset($vigentes[$p['cod']])];
            }, $universo ?? [])),
            'locales' => array_values($base['locales']),
            'extras' => $base['extras'],
            'conceptos_extra' => array_map(function ($id) {
                return ['id' => $id, 'nombre' => AlquileresConceptos::NOMBRE[$id]];
            }, AlquileresConceptos::basesExtra()),
            'frecuencias' => AlquileresCalculo::FRECUENCIAS,
            'iva_general' => $base['iva_general'],
            'dia_min' => AlquileresParametros::DIA_MIN,
            'dia_max' => AlquileresParametros::DIA_MAX,
            'avisos' => $avisos
        ];
    }

    /* ====================================================================
       LA PROYECCION
       ==================================================================== */

    /**
     * Lo que la pestaña necesita: la grilla de Proyeccion, la de Ventas, las
     * tarjetas y los avisos.
     *
     * @param Horizonte $h
     * @return array
     */
    public function proyeccion($h) {
        $c = $this->calcular($h, $this->insumos($h));

        return self::armarPestana($c, $h);
    }

    /**
     * Los insumos que no son de la app ni de Parametros -> Alquileres: la
     * inflacion, la venta del canal LOCALES, la alicuota de IVA y el
     * calendario. Cada uno puede fallar por su lado: queda vacio y avisa.
     *
     * @param Horizonte $h
     * @return array ['inflacion', 'canal', 'iva', 'habiles', 'avisos']
     */
    public function insumos($h) {
        $avisos = [];
        $ins = ['inflacion' => [], 'canal' => [], 'iva' => null, 'habiles' => [], 'avisos' => []];

        try {
            $ins['inflacion'] = (new Inflacion())->valores();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la inflación de Parámetros › Generales ('
                . $e->getMessage() . '): los fijos no se ajustan.');
        }

        try {
            require_once __DIR__ . '/Ventas.php';
            $ins['canal'] = (new Ventas())->ventaMensualCanal('LOCALES', $h);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la venta proyectada del canal LOCALES de la '
                . 'pestaña Ventas (' . $e->getMessage() . '): sin ella no hay venta estimada por local.');
        }

        try {
            $ins['iva'] = $this->ivaGeneral() / 100;
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la alícuota de IVA de Parámetros › Generales ('
                . $e->getMessage() . '): sin ella no se puede pasar la venta de bruta a neta.');
        }

        $meses = $h->meses();
        $desde = Inflacion::mesMas($meses[0]['clave'], -1) . '-01';
        $hasta = date('Y-m-t', strtotime(Inflacion::mesMas(end($meses)['clave'], 1) . '-01'));

        require_once __DIR__ . '/CronogramaDatos.php';
        $crono = new CronogramaDatos();
        $ins['habiles'] = $crono->habilesEntre($desde, $hasta);
        $ins['avisos'] = array_merge($avisos, $crono->avisosConNivel());
        $ins['facturas'] = $this->insumosFacturas($h);

        return $ins;
    }

    /**
     * Lo que hace falta para las facturas reales: el contexto de
     * AlquileresFacturas, las cuotas pendientes de alquiler (de la MISMA
     * lectura de Tango que Proveedores Locales), los vencimientos editados, las
     * imputaciones vigentes y, de las imputadas que ya no estan pendientes, que
     * dice Tango.
     *
     * SI LA CONDICION DE SEGURIDAD NO SE CUMPLE, NO SE LEE NADA MAS: las
     * facturas siguen en Proveedores Locales y aca no se toman, ni para la
     * grilla ni para reemplazar la proyeccion. El aviso critico lo dice.
     *
     * Nunca lanza: lo que no se puede leer queda vacio, con su aviso.
     *
     * @param Horizonte $h
     * @return array ['ctx', 'pendientes', 'excluidas', 'vtos', 'imputaciones', 'en_tango', 'avisos']
     */
    protected function insumosFacturas($h) {
        $out = ['ctx' => null, 'pendientes' => [], 'excluidas' => [], 'vtos' => [], 'imputaciones' => [],
                'en_tango' => [], 'avisos' => []];

        try {
            require_once __DIR__ . '/AlquileresUniverso.php';
            require_once __DIR__ . '/Proveedores.php';
            require_once __DIR__ . '/AlquileresImputaciones.php';
            require_once __DIR__ . '/AlquileresFacturaVto.php';

            $ctx = AlquileresUniverso::delPedido();
            $out['ctx'] = $ctx;
            $out['avisos'] = $ctx['avisos'];

            if (empty($ctx['razones'])) {
                return $out;
            }

            $prov = new Proveedores();
            $todos = $prov->getPendientes($h->hoy());
            $partes = AlquileresFacturas::partir($todos, $ctx);
            $out['avisos'] = array_merge($out['avisos'], AlquileresFacturas::avisoCondicion($ctx, $todos));

            if (empty($ctx['condicion']['cumple'])) {
                return $out;
            }

            $out['pendientes'] = $partes[AlquileresFacturas::A_ALQUILERES];
            $out['excluidas'] = $partes[AlquileresFacturas::A_EXCLUIDA];
            $out['vtos'] = (new AlquileresFacturaVto())->vigentes();
            $out['imputaciones'] = (new AlquileresImputaciones())->vigentes();

            $pares = AlquileresImputacion::aConsultarEnTango($out['imputaciones'], $out['pendientes']);
            $out['en_tango'] = empty($pares) ? [] : $prov->estadoComprobantesEnTango($pares);
        } catch (Throwable $e) {
            $out['avisos'][] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer las facturas de alquiler ('
                . $e->getMessage() . '): la pestaña y el tablero muestran solo la proyección, sin facturas.',
                'Facturas');
            $out['pendientes'] = [];
            $out['imputaciones'] = [];
        }

        return $out;
    }

    /**
     * Los pagos de todos los locales que caen en el horizonte, con todo lo que
     * explica cada uno. Es lo que leen la pestaña y el proveedor del tablero:
     * los dos suman ESTA lista, uno por mes y el otro por dia.
     *
     * CON LAS FACTURAS YA APLICADAS: lo que cubre una imputacion sale de la
     * lista y entra la factura, en su fecha (AlquileresImputacion::aplicar()).
     * Los pagos de origen FACTURA son la serie REAL del tablero; el resto, la
     * PROYECTADO.
     *
     * @param Horizonte $h
     * @param array $ins Lo de insumos()
     * @return array ['pagos', 'locales', 'ventas', 'meses_pago', 'meses_concepto',
     *                'faltan_inflacion', 'avisos', 'facturas', 'imputaciones',
     *                'reemplazados', 'facturas_kpis', 'pagadas_nuevas', 'fuera_horizonte_facturas',
     *                'condicion']
     */
    public function calcular($h, $ins) {
        return self::conFacturas($this->calcularProyeccion($h, $ins), $ins, $h);
    }

    /**
     * Aplica las facturas imputadas a la proyeccion. Estatica: no lee nada.
     *
     * @param array $out Lo de calcularProyeccion()
     * @param array $ins Lo de insumos(), con 'facturas' (sin el, la proyeccion sola)
     * @param Horizonte $h
     * @return array
     */
    public static function conFacturas($out, $ins, $h) {
        $out += ['facturas' => [], 'imputaciones' => [], 'reemplazados' => [], 'pagadas_nuevas' => [],
                 'facturas_kpis' => ['sin_imputar_n' => 0, 'sin_imputar_importe' => 0.0, 'imputadas_n' => 0,
                                     'imputadas_a_pagar' => 0.0],
                 'fuera_horizonte_facturas' => 0.0, 'condicion' => null];
        $f = $ins['facturas'] ?? null;

        if ($f === null) {
            return $out;
        }

        $out['avisos'] = array_merge($out['avisos'], $f['avisos']);
        $out['condicion'] = ($f['ctx'] === null) ? null : $f['ctx']['condicion'];

        if ($f['ctx'] === null || empty($f['ctx']['condicion']['cumple'])) {
            return $out;
        }

        $a = AlquileresImputacion::aplicar([
            'pagos' => $out['pagos'],
            'meses_pago' => $out['meses_pago'],
            'pendientes' => $f['pendientes'],
            'excluidas' => $f['excluidas'],
            'vtos' => $f['vtos'],
            'imputaciones' => $f['imputaciones'],
            'en_tango' => $f['en_tango'],
            'ctx' => $f['ctx'],
            'habiles' => $ins['habiles'] ?? [],
            'hoy' => $h->hoy()
        ]);

        $out['pagos'] = $a['pagos'];
        $out['reemplazados'] = $a['reemplazados'];
        $out['facturas'] = $a['facturas'];
        $out['imputaciones'] = $a['imputaciones'];
        $out['pagadas_nuevas'] = $a['pagadas_nuevas'];
        $out['facturas_kpis'] = $a['kpis'];
        $out['fuera_horizonte_facturas'] = $a['fuera_horizonte'];
        $out['avisos'] = array_merge($out['avisos'], $a['avisos']);

        return $out;
    }

    /**
     * La proyeccion sola, sin facturas: lo que se calculaba antes de que
     * existieran. Ver calcular().
     */
    protected function calcularProyeccion($h, $ins) {
        $avisos = $ins['avisos'] ?? [];
        $base = $this->leerBase($avisos);
        $universo = $this->universoRazones($avisos);
        $hoy = $h->hoy();
        $mesesPago = array_column($h->meses(), 'clave');

        $out = ['pagos' => [], 'locales' => $base['locales'], 'ventas' => null, 'meses_pago' => $mesesPago,
                'meses_concepto' => $mesesPago, 'faltan_inflacion' => [], 'avisos' => []];

        foreach ($base['razones'] as $cod => $r) {
            if ($universo !== null && !isset($universo[$cod])) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'La razón social ' . $r['razon'] . ' (' . $cod . ') ya no '
                    . 'tiene RUBRO = ALQUILER en el maestro de Proveedores Locales. Sigue de alta y sus locales '
                    . 'proyectan; si ya no es un alquiler, se da de baja en Parámetros › Alquileres.');
            }
        }

        if (!$base['app_ok'] || !$base['tablas_ok']) {
            $out['avisos'] = $avisos;
            return $out;
        }

        /* Los meses de concepto: el horizonte y, hacia atras, el mayor
           diferimiento (variables y extraordinarios). */
        $maxDifer = 0;

        foreach ($base['razones'] as $r) {
            $maxDifer = max($maxDifer, $r['diferimiento']);
        }

        foreach ($base['extras'] as $x) {
            $maxDifer = max($maxDifer, $x['diferimiento']);
        }

        $mesesConcepto = AlquileresPago::mesesDeConcepto($mesesPago, $maxDifer);
        $out['meses_concepto'] = $mesesConcepto;

        $ultimoGlobal = empty($base['cerrados']) ? $mesesConcepto[0] : end($base['cerrados']);
        $desde = min(Inflacion::mesMas($ultimoGlobal, -AlquileresCalculo::MESES_EXPENSAS), $mesesConcepto[0]);

        try {
            $detalle = $this->app->detalleCerrados($base['cerrados'], $desde);
            $pct = $this->app->porcentajes();
            $desdeVenta = min($base['ventana'][0] ?? $mesesConcepto[0], $mesesConcepto[0]);
            $netas = $this->app->ventasNetas($desdeVenta);
            $brutas = $this->app->ventasBrutas($desdeVenta);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la app de alquileres: ' . $e->getMessage());
            $out['avisos'] = $avisos;
            return $out;
        }

        /* La venta de cada local. */
        $entradaVenta = [];

        foreach ($base['locales'] as $nro => $l) {
            $p = $l['params'];
            $entradaVenta[$nro] = [
                'activo' => $l['habilitado'] && $l['estado'] !== AlquileresPago::FINALIZADO,
                'activo_hasta' => empty($p['fecha_fin']) ? null : substr($p['fecha_fin'], 0, 7),
                'manual' => $p['venta_mensual_estimada'] ?? null
            ];
        }

        $ventas = ($ins['iva'] === null) ? null : AlquileresVenta::calcular([
            'meses' => $mesesConcepto, 'locales' => $entradaVenta, 'netas' => $netas, 'brutas' => $brutas,
            'ventana' => $base['ventana'], 'canal' => $ins['canal'], 'iva' => $ins['iva']
        ]);
        $out['ventas'] = $ventas;

        foreach ($mesesConcepto as $m) {
            if (!isset($ins['canal'][$m]) && $ventas !== null) {
                $sinReal = false;

                foreach ($ventas['ventas'] as $nro => $vm) {
                    if (($vm[$m] ?? null) === null && $base['locales'][$nro]['estado'] === AlquileresPago::PROYECTA) {
                        $sinReal = true;
                    }
                }

                if ($sinReal) {
                    $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No hay venta proyectada del canal LOCALES para '
                        . $m . ' y algún local no tiene venta real: sus conceptos variables de ese mes no se proyectan.');
                }
            }
        }

        /* Las tasas de las expensas: la de cada local y la general. */
        $tasas = [];

        foreach ($detalle as $nro => $meses) {
            ksort($meses);
            $serie = [];

            foreach ($meses as $m => $c) {
                $serie[$m] = $c[11] ?? 0.0;
            }

            $tasas[$nro] = AlquileresCalculo::tasaExpensas($serie, array_key_last($meses));
        }

        $general = AlquileresCalculo::tasaGeneral($tasas);

        /* Los pagos de cada local que proyecta. */
        $diaGeneral = ($base['general'] === null) ? null : $base['general']['dia'];
        $faltanInf = [];
        $sinCerrado = [];
        $sinVenta = [];
        $sinPct = [];

        foreach ($base['locales'] as $nro => $l) {
            if ($l['estado'] !== AlquileresPago::PROYECTA) {
                continue;
            }

            $p = $l['params'];
            $razon = $base['razones'][$p['cod_provee']];
            $propia = $tasas[$nro] ?? ['tasa' => null, 'meses' => 0, 'variaciones' => 0];
            $pctLocal = $pct[$nro] ?? [];

            $proy = AlquileresCalculo::proyectarLocal([
                'meses' => $mesesConcepto,
                'cerrados' => $detalle[$nro] ?? [],
                'frecuencia' => $p['frecuencia'],
                'mes_ajuste' => $p['mes_ajuste'],
                'pct' => $pctLocal,
                'inflacion' => $ins['inflacion'],
                'tasa_expensas' => AlquileresCalculo::tasaDelLocal($propia, $general),
                'ventas' => ($ventas === null) ? [] : ($ventas['ventas'][$nro] ?? [])
            ]);

            $faltanInf = array_merge($faltanInf, $proy['faltan_inflacion']);

            if ($proy['ultimo_cerrado'] === null) {
                $sinCerrado[] = $l['nombre'];
            }

            $variables = array_intersect_key($pctLocal, array_flip(AlquileresConceptos::delGrupo(AlquileresConceptos::VARIABLES)));

            if (empty($variables)) {
                $sinPct[] = $l['nombre'];
            } else {
                foreach ($proy['meses'] as $m => $pm) {
                    if ($pm['motivo'] === 'SIN_VENTA') {
                        $sinVenta[$l['nombre']] = true;
                    }
                }
            }

            $extras = array_values(array_filter($base['extras'], function ($x) use ($nro, $p) {
                return AlquileresPago::alcanza($x, $nro, $p['cod_provee']);
            }));

            foreach (AlquileresPago::pagosLocal([
                'local' => $p,
                'razon' => $razon,
                'dia_general' => $diaGeneral,
                'proyeccion' => $proy,
                'extras' => $extras,
                'meses_pago' => $mesesPago,
                'habiles' => $ins['habiles'],
                'hoy' => $hoy
            ]) as $pago) {
                $out['pagos'][] = ['nro' => $nro] + $pago;
            }
        }

        $faltanInf = array_values(array_unique($faltanInf));
        sort($faltanInf);
        $out['faltan_inflacion'] = $faltanInf;

        if (!empty($faltanInf)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, Inflacion::avisoFaltan($faltanInf, $hoy)
                . ' En Alquileres los fijos se ajustan con lo que hay: el ajuste de esos meses queda corto.');
        }

        if (!empty($sinCerrado)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Sin ningún período cerrado en la app de alquileres: '
                . implode(', ', $sinCerrado) . '. Los fijos y las expensas recién proyectan cuando tengan uno.');
        }

        if (!empty($sinVenta)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Sin venta para algún mes: ' . implode(', ', array_keys($sinVenta))
                . '. Sus conceptos variables de esos meses no se proyectan. Un local nuevo se resuelve cargando '
                . 'su venta mensual estimada en Parámetros › Alquileres.');
        }

        if (!empty($sinPct)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Sin porcentajes de conceptos variables en la app (no todos los '
                . 'contratos los tienen): ' . implode(', ', $sinPct) . '.');
        }

        $incompletos = array_filter($base['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::INCOMPLETO || !empty($l['faltan']);
        });

        if (!empty($incompletos)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($incompletos) . ' local(es) no proyectan, o no proyectan '
                . 'todo, porque les falta algún dato: ' . implode('; ', array_map(function ($l) {
                    return $l['nombre'] . ' (' . implode(', ', $l['faltan']) . ')';
                }, $incompletos)) . '. Se completan en Parámetros › Alquileres.');
        }

        $sinCalendario = [];

        foreach ($out['pagos'] as $p) {
            $sinCalendario = array_merge($sinCalendario, $p['faltan_calendario']);
        }

        if (!empty($sinCalendario)) {
            $sinCalendario = array_values(array_unique($sinCalendario));
            sort($sinCalendario);
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'El calendario bancario no tiene ' . implode(', ', $sinCalendario)
                . ': para esos meses se toman hábiles los días de lunes a viernes.');
        }

        $out['avisos'] = $avisos;

        return $out;
    }

    /* ====================================================================
       EL ARMADO DE LA PESTAÑA
       ==================================================================== */

    /**
     * La grilla de Proyeccion (local -> grupos -> conceptos, por mes de pago),
     * la de Ventas, las tarjetas y los avisos, a partir de calcular().
     *
     * NO CALCULA NADA: agrupa la lista de pagos. Por eso lo que la pestaña dice
     * es lo mismo que dice el tablero, que suma la misma lista.
     *
     * @param array $c Lo de calcular()
     * @param Horizonte $h
     * @return array
     */
    public static function armarPestana($c, $h) {
        $meses = array_map(function ($m) {
            return ['clave' => $m['clave'], 'label' => $m['label']];
        }, $h->meses());
        $mesActual = substr($h->hoy(), 0, 7);

        /* UN CONCEPTO QUE VALE 0 TODO EL HORIZONTE NO SE MUESTRA, salvo los
           variables: el alquiler, el complementario y la baulera en 0 de un
           shopping son ruido, pero un 7 en 0 dice algo (la venta no supera el
           VMA). No cambia ningun total: suman 0. */
        $totalConcepto = [];

        foreach ($c['pagos'] as $p) {
            $k = $p['nro'] . '|' . $p['concepto'];
            $totalConcepto[$k] = ($totalConcepto[$k] ?? 0.0) + abs($p['neto']);
        }

        $porLocal = [];

        foreach ($c['pagos'] as $p) {
            if ($totalConcepto[$p['nro'] . '|' . $p['concepto']] == 0 && $p['grupo'] !== AlquileresConceptos::VARIABLES) {
                continue;
            }

            $porLocal[$p['nro']][] = $p;
        }

        $locales = [];
        $totalHorizonte = 0.0;
        $totalMesActual = 0.0;

        foreach ($c['locales'] as $nro => $l) {
            $fila = [
                'nro' => $nro,
                'nombre' => $l['nombre'],
                'cod_provee' => $l['params']['cod_provee'] ?? null,
                'razon' => $l['razon'],
                'estado' => $l['estado'],
                'faltan' => $l['faltan'],
                'nuevo' => $l['nuevo'],
                'meses' => [],
                'total' => self::celdaVacia(),
                'grupos' => []
            ];

            foreach ($porLocal[$nro] ?? [] as $p) {
                $grupo = $p['grupo'];
                $concepto = (string) $p['concepto'];
                $mes = $p['mes_pago'];

                if (!isset($fila['grupos'][$grupo])) {
                    $fila['grupos'][$grupo] = ['grupo' => $grupo, 'nombre' => self::nombresGrupo()[$grupo],
                                               'meses' => [], 'total' => self::celdaVacia(), 'conceptos' => []];
                }

                $g = &$fila['grupos'][$grupo];

                if (!isset($g['conceptos'][$concepto])) {
                    $g['conceptos'][$concepto] = ['id' => $concepto, 'nombre' => self::nombreConcepto($p),
                                                  'meses' => [], 'total' => self::celdaVacia()];
                }

                $k = &$g['conceptos'][$concepto];
                $k['meses'][$mes] = self::sumarCelda($k['meses'][$mes] ?? self::celdaVacia(), $p, true);
                $k['total'] = self::sumarCelda($k['total'], $p, false);
                $g['meses'][$mes] = self::sumarCelda($g['meses'][$mes] ?? self::celdaVacia(), $p, false);
                $g['total'] = self::sumarCelda($g['total'], $p, false);
                $fila['meses'][$mes] = self::sumarCelda($fila['meses'][$mes] ?? self::celdaVacia(), $p, false);
                $fila['total'] = self::sumarCelda($fila['total'], $p, false);
                unset($g, $k);

                $totalHorizonte += $p['a_pagar'];

                if ($mes === $mesActual) {
                    $totalMesActual += $p['a_pagar'];
                }
            }

            /* Los grupos en su orden, y cada uno con sus conceptos en orden. */
            $ordenados = [];

            foreach (array_keys(self::nombresGrupo()) as $gr) {
                if (isset($fila['grupos'][$gr])) {
                    $g = $fila['grupos'][$gr];
                    ksort($g['conceptos'], SORT_NATURAL);
                    $g['conceptos'] = array_values($g['conceptos']);
                    $ordenados[] = $g;
                }
            }

            $fila['grupos'] = $ordenados;
            $locales[] = $fila;
        }

        $incompletos = count(array_filter($c['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::INCOMPLETO || !empty($l['faltan']);
        }));
        $proyectan = count(array_filter($c['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::PROYECTA;
        }));

        return [
            'meses' => $meses,
            'mes_actual' => $mesActual,
            'locales' => $locales,
            'ventas' => self::armarVentas($c),
            'kpis' => [
                'total_horizonte' => round($totalHorizonte, 2),
                'total_mes_actual' => round($totalMesActual, 2),
                'locales_proyectan' => $proyectan,
                'locales_incompletos' => $incompletos
            ],
            'origenes' => AlquileresCalculo::NOMBRE_ORIGEN,
            'estados' => AlquileresPago::NOMBRE_ESTADO,
            'facturas' => self::armarFacturas($c),
            'avisos' => $c['avisos']
        ];
    }

    /**
     * Los grupos de la grilla de Proyeccion, en orden: los de los conceptos y
     * los dos de las facturas que no caen en uno solo.
     */
    public static function nombresGrupo() {
        return AlquileresConceptos::NOMBRE_GRUPO + [
            AlquileresImputacion::GRUPO_VARIOS => 'Facturas de varios grupos',
            AlquileresImputacion::GRUPO_OTROS => 'Otros (no proyectados)'
        ];
    }

    /**
     * La sub-pestaña Facturas: la grilla por cuota, las imputaciones, las
     * tarjetas y lo que el armado de una imputacion necesita (conceptos,
     * atajos, locales con su razon social). No calcula nada.
     */
    private static function armarFacturas($c) {
        $conceptos = [];

        foreach (AlquileresImputacion::CONCEPTOS as $id) {
            $conceptos[] = ['id' => $id, 'nombre' => ($id === AlquileresImputacion::EXTRA) ? 'Extraordinario'
                : (($id === AlquileresImputacion::OTROS) ? 'Otros (no proyectados)'
                    : $id . ' · ' . (AlquileresConceptos::NOMBRE[(int) $id] ?? ''))];
        }

        $locales = [];

        foreach ($c['locales'] as $nro => $l) {
            $locales[] = ['nro' => $nro, 'nombre' => $l['nombre'], 'cod_provee' => $l['params']['cod_provee'] ?? null,
                          'razon' => $l['razon']];
        }

        return [
            'condicion' => $c['condicion'],
            'filas' => $c['facturas'],
            'imputaciones' => $c['imputaciones'],
            'kpis' => $c['facturas_kpis'],
            'estados' => AlquileresImputacion::NOMBRE_ESTADO,
            'conceptos' => $conceptos,
            'atajos' => AlquileresImputacion::atajos(),
            'nombres_grupo' => AlquileresConceptos::NOMBRE_GRUPO,
            'locales' => $locales,
            'tolerancia' => AlquileresImputacion::TOLERANCIA,
            'reemplazados' => count($c['reemplazados'])
        ];
    }

    /**
     * La grilla de Ventas: local x mes de concepto (incluye los anteriores al
     * horizonte que pagan los variables diferidos), con el canal y la
     * diferencia al pie.
     */
    private static function armarVentas($c) {
        $v = $c['ventas'];
        $filas = [];

        foreach ($c['locales'] as $nro => $l) {
            $meses = [];

            foreach ($c['meses_concepto'] as $m) {
                $meses[$m] = ($v === null) ? null : ($v['ventas'][$nro][$m] ?? null);
            }

            $filas[] = ['nro' => $nro, 'nombre' => $l['nombre'], 'estado' => $l['estado'],
                        'participacion' => ($v === null) ? null : ($v['participacion'][$nro] ?? null),
                        'nuevo' => $l['nuevo'], 'meses' => $meses];
        }

        $pie = [];

        foreach ($c['meses_concepto'] as $m) {
            $canal = ($v === null) ? null : ($v['canal'][$m] ?? null);
            $estimadaManual = ($v === null) ? 0.0 : ($v['estimada'][$m] ?? 0.0) + ($v['manual'][$m] ?? 0.0);
            $pie[$m] = [
                'canal' => $canal,
                'estimada_manual' => round($estimadaManual, 2),
                'diferencia' => ($canal === null) ? null : round($canal - $estimadaManual, 2)
            ];
        }

        return [
            'meses' => $c['meses_concepto'],
            'horizonte_desde' => $c['meses_pago'][0] ?? null,
            'filas' => $filas,
            'pie' => $pie,
            'ventana' => ($v === null) ? [] : $v['ventana'],
            'origenes' => AlquileresVenta::NOMBRE_ORIGEN
        ];
    }

    private static function celdaVacia() {
        return ['neto' => 0.0, 'iva' => 0.0, 'percepcion' => 0.0, 'retencion' => 0.0, 'a_pagar' => 0.0];
    }

    /** Suma un pago a una celda; en la de concepto, ademas guarda el detalle. */
    private static function sumarCelda($celda, $p, $conDetalle) {
        foreach (['neto', 'iva', 'percepcion', 'retencion', 'a_pagar'] as $k) {
            $celda[$k] = round($celda[$k] + $p[$k], 2);
        }

        if ($conDetalle) {
            $celda['pagos'][] = array_intersect_key($p, array_flip([
                'fecha', 'vencimiento', 'corrida', 'vencido', 'nota_dia', 'origen', 'mes_concepto',
                'neto', 'iva', 'percepcion', 'retencion', 'a_pagar', 'detalle'
            ]));
        }

        return $celda;
    }

    private static function nombreConcepto($p) {
        /* UNA LINEA POR RENGLON DE FACTURA, dentro del grupo de sus conceptos:
           dice de que imputacion y renglon es y que conceptos cubre. */
        if ($p['origen'] === AlquileresCalculo::FACTURA) {
            $d = $p['detalle'];

            return 'Factura · imputación ' . $d['imputacion'] . ', renglón ' . $d['renglon'] . ' ('
                . implode(', ', array_map(function ($c) {
                    return ($c === AlquileresImputacion::EXTRA) ? 'extraordinario'
                        : (($c === AlquileresImputacion::OTROS) ? 'otros' : $c);
                }, $d['conceptos'])) . ')';
        }

        if ($p['grupo'] === AlquileresConceptos::EXTRA) {
            $d = $p['detalle'];
            return $d['pct'] . ' % s/ ' . implode(' + ', array_keys($d['base']));
        }

        return $p['concepto'] . ' · ' . (AlquileresConceptos::NOMBRE[$p['concepto']] ?? '');
    }

    /* ====================================================================
       LECTURA COMUN
       ==================================================================== */

    /**
     * Lo que Parametros y la pestaña comparten: los locales de la app con sus
     * datos del cashflow y su estado, las razones dadas de alta, el general y
     * los extraordinarios. Los avisos de lo que no se pudo leer se agregan a
     * $avisos.
     *
     * @param array $avisos
     * @return array
     */
    protected function leerBase(&$avisos) {
        $out = ['locales' => [], 'razones' => [], 'general' => null, 'extras' => [], 'iva_general' => null,
                'tablas_ok' => false, 'app_ok' => false, 'cerrados' => [], 'netas' => [], 'ventana' => []];

        try {
            $faltanApp = $this->app->tablasFaltantes();

            if (!empty($faltanApp)) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No están las tablas de la app de alquileres ('
                    . implode(', ', $faltanApp) . '): no hay locales que mostrar.');
                return $out;
            }

            $out['app_ok'] = true;
            $localesApp = $this->app->locales();
            $out['cerrados'] = $this->app->mesesCerrados();
            $out['ventana'] = AlquileresVenta::ventana($out['cerrados']);
            $out['netas'] = empty($out['ventana']) ? [] : $this->app->ventasNetas($out['ventana'][0]);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la app de alquileres: ' . $e->getMessage());
            return $out;
        }

        try {
            $out['iva_general'] = $this->ivaGeneral();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la alícuota de IVA de Parámetros › '
                . 'Generales (' . $e->getMessage() . ').');
        }

        $params = [];

        try {
            if (!$this->par->tablaCreada()) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $this->par->avisoSinTabla());
            } else {
                $out['tablas_ok'] = true;
                $params = $this->par->locales();
                $out['razones'] = $this->par->razonesVigentes();
                $out['general'] = $this->par->general();
                $out['extras'] = $this->par->extras();
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los parámetros de Alquileres: '
                . $e->getMessage());
        }

        $diaGeneral = ($out['general'] === null) ? null : $out['general']['dia'];

        if ($out['tablas_ok'] && $diaGeneral === null) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Falta el día de vencimiento general (Parámetros › '
                . 'Alquileres › Generales): las razones sociales sin día propio no proyectan.', 'Generales');
        }

        $mesActual = substr($this->hoy, 0, 7);

        foreach ($localesApp as $nro => $l) {
            $p = $params[$nro] ?? null;
            $estado = AlquileresPago::estadoLocal($p, $l['habilitado'], $out['razones'], $diaGeneral, $mesActual);
            $mesesVenta = 0;

            foreach ($out['ventana'] as $m) {
                if (isset($out['netas'][$nro][$m])) {
                    $mesesVenta++;
                }
            }

            $nuevo = $mesesVenta < AlquileresVenta::MESES_PARTICIPACION;

            /* UN NUEVO SIN VENTA MANUAL PROYECTA LOS FIJOS, PERO NO LOS
               VARIABLES: no es un incompleto del todo, y por eso va en
               'faltan' sin cambiar el estado. El filtro Incompletos lo muestra. */
            if ($nuevo && $estado['estado'] === AlquileresPago::PROYECTA
                && ($p['venta_mensual_estimada'] ?? null) === null) {
                $estado['faltan'][] = 'venta mensual estimada (local nuevo, sin 12 meses de venta)';
            }

            $out['locales'][$nro] = [
                'nro' => $nro,
                'nombre' => $l['nombre'],
                'habilitado' => $l['habilitado'],
                'params' => $p,
                'razon' => (isset($p['cod_provee']) && isset($out['razones'][$p['cod_provee']]))
                    ? $out['razones'][$p['cod_provee']]['razon'] : null,
                'estado' => $estado['estado'],
                'faltan' => $estado['faltan'],
                'nuevo' => $nuevo,
                'meses_venta' => $mesesVenta
            ];
        }

        return $out;
    }

    /**
     * Los proveedores del maestro con RUBRO = ALQUILER. Null si no se pudo
     * leer el maestro, con su aviso.
     *
     * @param array $avisos
     * @return array|null COD_PROVEE => ['cod', 'nombre', 'rubro_economico']
     */
    protected function universoRazones(&$avisos) {
        try {
            require_once __DIR__ . '/ProveedoresCategorias.php';

            return AlquileresParametros::universoDelMaestro((new ProveedoresCategorias())->mapa());
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer el maestro de Proveedores Locales ('
                . $e->getMessage() . '): el buscador de razones sociales no tiene qué ofrecer.', 'Razones sociales');

            return null;
        }
    }

    /** La alicuota general, en puntos (21, no 0,21) */
    protected function ivaGeneral() {
        require_once __DIR__ . '/Parametros.php';

        return Parametros::num((new Parametros())->getParametrosMap(), 'alicuota_iva') * 100;
    }
}
