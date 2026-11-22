<?php

require_once __DIR__ . '/AlquileresApp.php';
require_once __DIR__ . '/AlquileresParametros.php';
require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/AlquileresVenta.php';
require_once __DIR__ . '/AlquileresPago.php';
require_once __DIR__ . '/AlquileresImputacion.php';
require_once __DIR__ . '/AlquileresImputaciones.php';
require_once __DIR__ . '/AlquileresFacturaExclusion.php';
require_once __DIR__ . '/AlquileresTiposRetencion.php';
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
                })),
                'locales_adicional' => count(array_filter($base['locales'], function ($l) use ($cod) {
                    return in_array(Planilla::codigo($cod), array_map(['Planilla', 'codigo'],
                        $l['params']['adicionales'] ?? []), true);
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

        /* LOS DE RUBRO ALQUILER FUERA DE TODA FILA, con el pendiente de cada uno:
           el aviso y la lista con el link al Maestro. */
        $sinFila = [];

        try {
            require_once __DIR__ . '/Proveedores.php';
            $sinFila = $this->rubroSinFila((new Proveedores())->getPendientes($this->hoy), $vigentes, $avisos);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer las cuentas a pagar de Tango ('
                . $e->getMessage() . '): no se puede decir qué proveedores de alquiler quedan fuera del tablero.');
        }

        $avisos = array_merge($avisos, AlquileresFacturas::avisoRubroSinFila($sinFila));

        /* UNA ADICIONAL QUE NO FACTURA NINGUN CONCEPTO sigue sirviendo para sus
           extraordinarios y para imputarle facturas; se avisa por si falta
           marcarle lo que factura. Solo con el script 62: sin el, ninguna
           adicional puede facturar nada y el selector ya lo dice. */
        $conConceptos = $base['tablas_ok'] && $this->tablaConceptosRazon($avisos);

        if ($conConceptos) {
            foreach ($base['locales'] as $l) {
                foreach ($l['razones_sin_conceptos'] ?? [] as $cod) {
                    $avisos[] = Aviso::nuevo(Aviso::INFO, ($vigentes[$cod]['razon'] ?? $cod) . ' es adicional de '
                        . $l['nombre'] . ' pero no factura ningún concepto.', 'Locales');
                }
            }
        }

        $tipos = $this->tiposRetencion($avisos);

        return [
            'tipos_retencion' => $tipos['todos'],
            'tabla_tipos_retencion' => $tipos['tabla'],
            'script_imputacion' => AlquileresTiposRetencion::SCRIPT,
            'rubro_sin_fila' => $sinFila,
            'tabla_creada' => $this->par->tablaCreada(),
            'script' => AlquileresParametros::SCRIPT,
            'tabla_razones_local' => $base['tablas_ok'] && $this->tablaRazonesLocal($avisos),
            'modos' => AlquileresParametros::MODOS,
            'columnas_vigencia' => $base['tablas_ok'] && $this->columnasVigencia($avisos),
            'script_vigencia' => AlquileresParametros::SCRIPT_VIGENCIA,
            'script_razones_local' => AlquileresParametros::SCRIPT_RAZONES_LOCAL,
            'tabla_conceptos_razon' => $conConceptos,
            'script_conceptos_razon' => AlquileresParametros::SCRIPT_CONCEPTOS_RAZON,
            'conceptos_proyectados' => array_map(function ($id) {
                return ['id' => $id, 'nombre' => AlquileresConceptos::NOMBRE[$id]];
            }, AlquileresConceptos::proyectados()),
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
     * @return array ['ctx', 'pendientes', 'excluidas', 'vtos', 'dias', 'pagadas_mes', 'imputaciones',
     *                'en_tango', 'avisos']
     */
    protected function insumosFacturas($h) {
        $out = ['ctx' => null, 'pendientes' => [], 'excluidas' => [], 'vtos' => [], 'dias' => [],
                'pagadas_mes' => [], 'imputaciones' => [], 'en_tango' => [], 'avisos' => [],
                'tabla_imputacion' => false, 'tipos' => [], 'tipos_vigentes' => [], 'excluidas_factura' => [],
                'tabla_exclusion' => false];

        try {
            require_once __DIR__ . '/AlquileresUniverso.php';
            require_once __DIR__ . '/Proveedores.php';
            require_once __DIR__ . '/AlquileresImputaciones.php';
require_once __DIR__ . '/AlquileresFacturaExclusion.php';
            require_once __DIR__ . '/AlquileresFacturaVto.php';

            $ctx = AlquileresUniverso::delPedido();
            $out['ctx'] = $ctx;
            $out['avisos'] = $ctx['avisos'];

            $prov = new Proveedores();
            $todos = $prov->getPendientes($h->hoy());

            /* LOS DE RUBRO ALQUILER QUE NO ESTAN EN NINGUNA FILA: excluidos de
               Proveedores Locales y sin dar de alta aca. Va al tablero (por el
               proveedor) y a la pestaña. */
            $out['avisos'] = array_merge($out['avisos'], AlquileresFacturas::avisoRubroSinFila(
                $this->rubroSinFila($todos, $ctx['razones'], $out['avisos'])));

            if (empty($ctx['razones'])) {
                return $out;
            }

            $partes = AlquileresFacturas::partir($todos, $ctx);
            $out['avisos'] = array_merge($out['avisos'], AlquileresFacturas::avisoCondicion($ctx, $todos));

            if (empty($ctx['condicion']['cumple'])) {
                return $out;
            }

            $out['pendientes'] = $partes[AlquileresFacturas::A_ALQUILERES];
            $out['excluidas'] = $partes[AlquileresFacturas::A_EXCLUIDA];
            /* LAS EXCLUIDAS A MANO (no se pagan por aca): no suman, pero se ven
               con "Ver excluidas", con su motivo, para poder volver a incluirlas. */
            $out['excluidas_factura'] = $partes[AlquileresFacturas::A_EXCLUIDA_FACTURA];
            $out['tabla_exclusion'] = (new AlquileresFacturaExclusion())->tablaCreada();
            $out['vtos'] = (new AlquileresFacturaVto())->vigentes();
            $out['dias'] = $this->diasDeVencimiento();

            /* LAS YA PAGADAS DEL MES: emitidas en el mes en curso y canceladas en
               Tango. Se ven en Facturas para poder imputarlas: una pagada sin
               imputar deja la proyeccion de su mes en el flujo. Si no se pueden
               leer, la grilla sigue con las pendientes y se avisa. */
            try {
                $pm = AlquileresFacturas::partir($prov->getCanceladasEmitidas(
                    array_keys($ctx['razones']), substr($h->hoy(), 0, 8) . '01', date('Y-m-t', strtotime($h->hoy()))),
                    $ctx);
                $out['pagadas_mes'] = $pm[AlquileresFacturas::A_ALQUILERES];
                $out['excluidas_factura'] = array_merge($out['excluidas_factura'], array_map(function ($p) {
                    return ['IMPORTE_PENDIENTE' => 0.0, 'PAGADA_TANGO' => true] + $p;
                }, $pm[AlquileresFacturas::A_EXCLUIDA_FACTURA]));
            } catch (Throwable $e) {
                $out['avisos'][] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer de Tango las facturas de '
                    . 'alquiler ya pagadas este mes (' . $e->getMessage() . '): la grilla muestra sólo las pendientes.',
                    'Facturas');
            }
            /* SIN EL SCRIPT 60 no hay imputaciones que leer: la proyeccion no se
               reemplaza con nada y las facturas no suman hasta que se imputen.
               Se avisa en critico: hay plata real que no esta en el cuadro. */
            $imps = new AlquileresImputaciones();
            $out['tabla_imputacion'] = $imps->tablaCreada();

            if (!$out['tabla_imputacion']) {
                $out['avisos'][] = Aviso::nuevo(Aviso::DANGER, $imps->avisoSinTabla(), 'Facturas');
            }

            $out['imputaciones'] = $imps->vigentes();
            /* Sin los tipos ya avisa lo de arriba: son del mismo script. */
            $sinAviso = [];
            $tipos = $this->tiposRetencion($sinAviso);
            $out['tipos'] = $tipos['nombres'];
            $out['tipos_vigentes'] = array_column($tipos['vigentes'], 'nombre', 'id');

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
                                     'imputadas_a_pagar' => 0.0, 'reemplazado_a_pagar' => 0.0],
                 'fuera_horizonte_facturas' => 0.0, 'condicion' => null, 'tipos_vigentes' => [],
                 'tabla_imputacion' => false];
        $f = $ins['facturas'] ?? null;

        if ($f === null) {
            return $out;
        }

        $out['avisos'] = array_merge($out['avisos'], $f['avisos']);
        $out['condicion'] = ($f['ctx'] === null) ? null : $f['ctx']['condicion'];

        if ($f['ctx'] === null || empty($f['ctx']['condicion']['cumple'])) {
            return $out;
        }

        $out['tipos_vigentes'] = $f['tipos_vigentes'] ?? [];
        $out['tabla_exclusion'] = $f['tabla_exclusion'] ?? false;
        $out['tabla_imputacion'] = $f['tabla_imputacion'] ?? false;

        /* El nombre de la razon social de cada imputacion, para la grilla y el tooltip. */
        $nombres = [];

        foreach ($out['razones'] ?? [] as $cod => $r) {
            $nombres[Planilla::codigo($cod)] = $r['razon'] ?? $cod;
        }

        $imputaciones = array_map(function ($imp) use ($nombres) {
            return $imp + ['razon' => $nombres[Planilla::codigo($imp['cod_provee'])] ?? $imp['cod_provee']];
        }, $f['imputaciones']);

        $a = AlquileresImputacion::aplicar([
            'pagos' => $out['pagos'],
            'meses_pago' => $out['meses_pago'],
            'pendientes' => $f['pendientes'],
            'excluidas' => $f['excluidas'],
            'vtos' => $f['vtos'],
            'dias' => $f['dias'] ?? [],
            'pagadas_mes' => $f['pagadas_mes'] ?? [],
            'excluidas_factura' => $f['excluidas_factura'] ?? [],
            'exclusiones' => $f['ctx']['facturas_excluidas'] ?? [],
            'imputaciones' => $imputaciones,
            'en_tango' => $f['en_tango'],
            'ctx' => $f['ctx'],
            'habiles' => $ins['habiles'] ?? [],
            'hoy' => $h->hoy(),
            'tipos' => $f['tipos'] ?? [],
            'nombres_locales' => array_map(function ($l) { return $l['nombre']; }, $out['locales'] ?? [])
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
     * Lo que necesita validar o guardar una imputacion, leido AHORA: las cuotas
     * pendientes de alquiler, las imputaciones vigentes, la proyeccion ORIGINAL
     * (sin reemplazos: para decir cuanto pisa), los locales con sus razones
     * sociales, los tipos de retencion vigentes y los meses que se pueden
     * imputar. Es lo que hace que guardar frene si una factura cambio entre que
     * se armo y se guardo.
     *
     * @param Horizonte $h
     * @return array El $e de AlquileresImputacion::validar(), + 'avisos'
     * @throws InvalidArgumentException si la condicion de seguridad no se cumple
     */
    public function contextoImputacion($h) {
        $ins = $this->insumos($h);
        $f = $ins['facturas'];

        if ($f['ctx'] === null || empty($f['ctx']['condicion']['cumple'])) {
            throw new InvalidArgumentException('Las facturas de alquiler todavía están en Proveedores Locales: '
                . 'no se pueden imputar hasta que el tablero tenga la fila de Alquileres y se haya corrido '
                . AlquileresFacturas::SCRIPT . '. Ver los avisos de la pestaña.');
        }

        $proy = $this->calcularProyeccion($h, $ins);
        $pend = [];

        foreach ($f['pendientes'] as $p) {
            $pend[AlquileresImputacion::claveCuota($p)] = $p;
        }

        /* LAS YA PAGADAS DEL MES TAMBIEN SE IMPUTAN, por el importe de la cuota:
           se validan como si ese fuera su pendiente. Al guardarlas, la proxima
           carga las ve canceladas en Tango y las marca Pagadas: reemplazan la
           proyeccion del mes que cubren y no generan pago. */
        foreach ($f['pagadas_mes'] ?? [] as $p) {
            $c = AlquileresImputacion::claveCuota($p);

            if (!isset($pend[$c])) {
                $pend[$c] = AlquileresImputacion::comoPendiente($p);
            }
        }

        $locales = [];

        foreach ($proy['locales'] as $nro => $l) {
            $locales[$nro] = ['nombre' => $l['nombre'], 'razones' => AlquileresPago::razonesDelLocal($l['params'])];
        }

        return ['pendientes' => $pend, 'imputaciones' => $f['imputaciones'], 'pagos' => $proy['pagos'],
                'locales' => $locales, 'dias' => $f['dias'] ?? [], 'avisos' => $f['avisos'],
                'tipos' => $f['tipos_vigentes'] ?? [], 'meses' => self::mesesImputables($h),
                'excluidas' => $f['ctx']['facturas_excluidas'] ?? [],
                'tabla_imputacion' => $f['tabla_imputacion'] ?? false];
    }

    /**
     * Los meses que se pueden imputar: EL ANTERIOR (una factura de un mes que ya
     * paso: se paga en su fecha y no pisa nada del horizonte) y los del
     * horizonte. Por defecto, el mes en curso.
     *
     * @param Horizonte $h
     * @return string[] 'Y-m'
     */
    public static function mesesImputables($h) {
        $meses = array_column($h->meses(), 'clave');

        return array_merge([Inflacion::mesMas(substr($h->hoy(), 0, 7), -1)], $meses);
    }

    /**
     * Los contratos vencidos o que vencen dentro del horizonte (la "fecha de fin"
     * de Parametros). No cortan la proyeccion: se asume que se renuevan con las
     * mismas condiciones, y el aviso es para revisarlos. Pura.
     *
     * @param array $locales Los de leerBase()
     * @param string $hoy 'Y-m-d'
     * @param string $ultimoMes 'Y-m' del horizonte
     * @return array Avisos
     */
    public static function avisoContratos($locales, $hoy, $ultimoMes) {
        $vencidos = [];
        $porVencer = [];

        foreach ($locales as $l) {
            /* El vencimiento es el del ultimo contrato de la app: hasta el, cubre. */
            $fin = empty($l['contratos']) ? null : max(array_column($l['contratos'], 'hasta'));

            /* Un local que proyecta segun la vigencia ya corta en su contrato: no se avisa. */
            if (empty($fin) || !$l['habilitado'] || AlquileresPago::porVigencia($l['params'] ?? null)) {
                continue;
            }

            $txt = $l['nombre'] . ' (' . date('d/m/Y', strtotime($fin)) . ')';

            if ($fin < $hoy) {
                $vencidos[] = $txt;
            } elseif (substr($fin, 0, 7) <= $ultimoMes) {
                $porVencer[] = $txt;
            }
        }

        $out = [];

        if (!empty($vencidos)) {
            $out[] = Aviso::nuevo(Aviso::WARNING, 'Contrato vencido en la app de alquileres: '
                . implode(', ', $vencidos) . '. Se sigue proyectando con las condiciones de hoy; si se renovó, '
                . 'cargá el contrato nuevo en la app. Si el local cerró, se ve como cerrado cuando Lakers lo deshabilita.', 'Locales');
        }

        if (!empty($porVencer)) {
            $out[] = Aviso::nuevo(Aviso::INFO, 'Contratos que vencen dentro del horizonte: ' . implode(', ', $porVencer)
                . '. Después del vencimiento se sigue proyectando con las condiciones de hoy (se asume la renovación).',
                'Locales');
        }

        return $out;
    }

    /** Si se puede proyectar segun la vigencia del contrato (script 59). Nunca lanza. */
    protected function columnasVigencia(&$avisos) {
        try {
            return $this->par->columnasVigenciaCreadas();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron verificar las columnas de vigencia ('
                . $e->getMessage() . ').', 'Locales');

            return false;
        }
    }

    /**
     * Los tipos de retencion (script 60): todos, con los dados de baja, para
     * que Parametros muestre el historial y una imputacion vieja su nombre.
     * Nunca lanza: sin el script, vacio y el aviso que lo nombra.
     *
     * @return array ['tabla' => bool, 'todos' => [...], 'vigentes' => [...], 'nombres' => [id => nombre]]
     */
    protected function tiposRetencion(&$avisos) {
        $out = ['tabla' => false, 'todos' => [], 'vigentes' => [], 'nombres' => []];

        try {
            $t = new AlquileresTiposRetencion();
            $out['tabla'] = $t->tablaCreada();

            if (!$out['tabla']) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $t->avisoSinTabla(), 'Tipos de retención');

                return $out;
            }

            $out['todos'] = $t->todos();
            $out['vigentes'] = array_values(array_filter($out['todos'], function ($x) { return $x['vigente']; }));
            $out['nombres'] = array_column($out['todos'], 'nombre', 'id');
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los tipos de retención ('
                . $e->getMessage() . ').', 'Tipos de retención');
        }

        return $out;
    }

    /** Si se pueden marcar los conceptos de una razon social adicional (script 62). Nunca lanza. */
    protected function tablaConceptosRazon(&$avisos) {
        try {
            return $this->par->tablaConceptosRazonCreada();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo verificar la tabla de conceptos por razón social ('
                . $e->getMessage() . ').', 'Locales');

            return false;
        }
    }

    /** Si se puede agregar mas de una razon social a un local (script 58). Nunca lanza. */
    protected function tablaRazonesLocal(&$avisos) {
        try {
            return $this->par->tablaRazonesLocalCreada();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo verificar la tabla de razones sociales adicionales ('
                . $e->getMessage() . ').', 'Locales');

            return false;
        }
    }

    /**
     * El dia de vencimiento de Parametros de cada razon social dada de alta (el
     * suyo o el general): con el se arma el vencimiento a considerar de sus
     * facturas (AlquileresImputacion::vtoAConsiderar()). Vacio si no se puede
     * leer: las facturas quedan con el de Tango.
     *
     * @return array COD_PROVEE => int
     */
    protected function diasDeVencimiento() {
        if (!$this->par->tablaCreada()) {
            return [];
        }

        $general = $this->par->general();

        return AlquileresImputacion::diasDeVencimiento($this->par->razonesVigentes(),
            ($general === null) ? null : $general['dia']);
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

        $out = ['pagos' => [], 'locales' => $base['locales'], 'razones' => $base['razones'], 'ventas' => null,
                'meses_pago' => $mesesPago,
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
            /* LOS FIJOS CARGADOS CON EL PERIODO ABIERTO, de los meses posteriores
               al ultimo cerrado: los usa AlquileresCalculo::fijos(). */
            $abiertos = $this->app->detalleAbiertos(array_values(array_filter($mesesConcepto,
                function ($m) use ($ultimoGlobal) { return $m > $ultimoGlobal; })), $base['cerrados']);
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
            /* SEGUN APERTURA el vencimiento del contrato no corta: se asume la
               renovacion, y deja de repartir solo si cerro en Lakers. SEGUN
               VIGENCIA (un local temporal) reparte solo en los meses del
               contrato. */
            $vig = ($p !== null) && AlquileresPago::porVigencia($p);
            $activoEn = null;

            if ($vig) {
                $activoEn = [];

                foreach ($mesesConcepto as $m) {
                    $activoEn[$m] = AlquileresPago::vigente($p, $m);
                }
            }

            $entradaVenta[$nro] = [
                'activo' => $l['habilitado'] && $l['estado'] !== AlquileresPago::FINALIZADO,
                'activo_hasta' => null,
                'activo_en' => $activoEn,
                'manual' => $p['venta_mensual_estimada'] ?? null
            ];
        }

        $avisos = array_merge($avisos, self::avisoContratos($base['locales'], $hoy, end($mesesPago)));

        $ventas = ($ins['iva'] === null) ? null : AlquileresVenta::calcular([
            'meses' => $mesesConcepto, 'locales' => $entradaVenta, 'netas' => $netas, 'brutas' => $brutas,
            'ventana' => $base['ventana'], 'canal' => $ins['canal'], 'iva' => $ins['iva'], 'hoy' => $hoy
        ]);
        $out['ventas'] = $ventas;

        /* LOS MESES SIN VENTA PROYECTADA DEL CANAL (los anteriores al horizonte)
           se estiman con la venta real de los demas (AlquileresVenta). Quedan
           sin venta solo los que no tienen participacion ni venta manual: se
           nombran, con los meses. */
        $sinVentaMes = [];

        foreach ($mesesConcepto as $m) {
            if (!isset($ins['canal'][$m]) && $ventas !== null) {
                foreach ($ventas['ventas'] as $nro => $vm) {
                    /* Solo importa si tiene conceptos variables en la app. */
                    $tieneVariables = !empty(array_intersect_key($pct[$nro] ?? [],
                        array_flip(AlquileresConceptos::delGrupo(AlquileresConceptos::VARIABLES))));

                    if (($vm[$m] ?? null) === null && $base['locales'][$nro]['estado'] === AlquileresPago::PROYECTA
                        && $tieneVariables) {
                        $sinVentaMes[$base['locales'][$nro]['nombre']][] = $m;
                    }
                }
            }
        }

        if (!empty($sinVentaMes)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Sin venta para estimar los variables de meses sin proyección '
                . 'del canal LOCALES: ' . implode('; ', array_map(function ($n, $ms) {
                    return $n . ' (' . implode(', ', $ms) . ')';
                }, array_keys($sinVentaMes), $sinVentaMes)) . '. No tienen venta en la app ni participación: se '
                . 'resuelve cargando su venta mensual estimada en Parámetros › Alquileres.');
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
        $sinAjuste = [];
        $ajusteFuera = [];
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
                'ventas' => ($ventas === null) ? [] : ($ventas['ventas'][$nro] ?? []),
                'abiertos' => $abiertos[$nro] ?? []
            ]);

            $faltanInf = array_merge($faltanInf, $proy['faltan_inflacion']);

            foreach ($proy['avisos_app'] as $a) {
                $texto = $l['nombre'] . ' (' . AlquileresConceptos::NOMBRE[$a['concepto']] . ', '
                    . AlquileresImputacion::mesLargo($a['mes']) . ')';

                if ($a['tipo'] === 'SIN_AJUSTE') {
                    $sinAjuste[] = $texto;
                } else {
                    $ajusteFuera[] = $texto;
                }
            }

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
                return AlquileresPago::alcanza($x, $nro, AlquileresPago::razonesDelLocal($p));
            }));

            foreach (AlquileresPago::pagosLocal([
                'local' => $p,
                'razon' => $razon,
                'razones' => $base['razones'],
                'concepto_razon' => $l['concepto_razon'],
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

        /* LOS FIJOS DEL PERIODO ABIERTO QUE NO SE USARON: en un mes en que el
           local ajusta, la app tiene el valor de antes (copia el mes anterior
           al abrir el periodo). Se sigue estimando con el ajuste. */
        if (!empty($sinAjuste)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'La app de alquileres todavía no aplicó el ajuste en '
                . implode('; ', $sinAjuste) . ': tiene el mismo valor que el mes anterior, así que se sigue estimando '
                . 'con el ajuste por inflación. Cuando la app lo cargue, se toma ese.', 'Locales');
        }

        /* Y LOS QUE SE USARON EN UN MES QUE NO ES DE AJUSTE: si el mes del
           ultimo ajuste de Parametros esta mal, el proximo ajuste se suma dos veces. */
        if (!empty($ajusteFuera)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'La app de alquileres cambió un fijo en un mes que, según '
                . 'Parámetros › Alquileres, no es de ajuste: ' . implode('; ', $ajusteFuera) . '. Se usa el valor de '
                . 'la app; revisá el mes del último ajuste del local en Parámetros, o el próximo ajuste se va a sumar '
                . 'dos veces.', 'Locales');
        }

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
     * La grilla de Proyeccion (razon social -> local -> grupos -> conceptos,
     * por mes de pago: armarRazones()), la de Ventas, las tarjetas y los
     * avisos, a partir de calcular(). 'locales' sigue viniendo plano, un
     * renglon por local con todos sus pagos: es el que leen la sub-pestaña
     * Ventas y Facturas.
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
        $facturas = [];

        foreach ($c['pagos'] as $p) {
            /* LAS FACTURAS IMPUTADAS SON DE LA RAZON SOCIAL, no de un local:
               van a su linea "Facturas imputadas" (armarRazones()). */
            if (($p['origen'] ?? null) === AlquileresCalculo::FACTURA) {
                $facturas[self::razonDelPago($p)][] = $p;
                continue;
            }

            if ($totalConcepto[$p['nro'] . '|' . $p['concepto']] == 0 && $p['grupo'] !== AlquileresConceptos::VARIABLES) {
                continue;
            }

            $porLocal[$p['nro']][] = $p;
        }

        $locales = [];
        $totalHorizonte = 0.0;
        $totalMesActual = 0.0;

        foreach ($c['pagos'] as $p) {
            $totalHorizonte += $p['a_pagar'];

            if ($p['mes_pago'] === $mesActual) {
                $totalMesActual += $p['a_pagar'];
            }
        }

        foreach ($c['locales'] as $nro => $l) {
            $locales[] = self::filaLocal($nro, $l, $porLocal[$nro] ?? []);
        }

        $razones = self::armarRazones($c, $porLocal, $facturas);

        $incompletos = count(array_filter($c['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::INCOMPLETO || !empty($l['faltan']);
        }));
        $proyectan = count(array_filter($c['locales'], function ($l) {
            return $l['estado'] === AlquileresPago::PROYECTA;
        }));

        return [
            'hoy' => $h->hoy(),
            'meses' => $meses,
            'mes_actual' => $mesActual,
            'locales' => $locales,
            'razones' => $razones,
            'ventas' => self::armarVentas($c),
            'kpis' => [
                'total_horizonte' => round($totalHorizonte, 2),
                'total_mes_actual' => round($totalMesActual, 2),
                'locales_proyectan' => $proyectan,
                'locales_incompletos' => $incompletos
            ],
            'origenes' => AlquileresCalculo::NOMBRE_ORIGEN,
            /* LA LEYENDA de los chips, en el orden de la jerarquia. */
            'leyenda_origenes' => [
                ['clave' => AlquileresCalculo::APP_CERRADA, 'texto' => 'Real (cerrado)',
                 'ayuda' => 'El período está cerrado en la app de alquileres.'],
                ['clave' => AlquileresCalculo::APP_ABIERTA, 'texto' => 'Cargado en app (abierto)',
                 'ayuda' => 'El período está abierto y el fijo ya está cargado en la app.'],
                ['clave' => AlquileresCalculo::VENTA_REAL, 'texto' => 'Venta real',
                 'ayuda' => 'Variable calculado por el cashflow con la venta real del mes.'],
                ['clave' => 'INFLACION', 'texto' => 'Estimado · inflación desde [mes]',
                 'ayuda' => 'Un fijo proyectado por inflación desde el último valor tomado de la app.'],
                ['clave' => AlquileresCalculo::ESTIMADO, 'texto' => 'Estimado', 'ayuda' => 'Todo estimado.'],
                ['clave' => AlquileresCalculo::FACTURA, 'texto' => 'Factura', 'ayuda' => 'La factura imputada.'],
                ['clave' => 'MIXTO', 'texto' => 'Mixto', 'ayuda' => 'Un subtotal con pagos de distinto origen: el desglose, en el tooltip.']
            ],
            'estados' => AlquileresPago::NOMBRE_ESTADO,
            'facturas' => self::armarFacturas($c, $h),
            'avisos' => $c['avisos']
        ];
    }

    /** La clave del grupo de los locales sin razon social dada de alta */
    const SIN_RAZON = 'SIN_RAZON';

    /**
     * El renglon de un local con sus grupos y conceptos, a partir de SUS
     * pagos (todos, o los de una razon social). No calcula: suma celdas.
     */
    private static function filaLocal($nro, $l, $pagos) {
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

        foreach ($pagos as $p) {
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

        return $fila;
    }

    /** La razon social que factura un pago: la suya, o la de la factura imputada */
    private static function razonDelPago($p) {
        $cod = $p['cod_provee'] ?? ($p['detalle']['cod_provee'] ?? null);

        return ($cod === null) ? null : Planilla::codigo($cod);
    }

    /**
     * La grilla de Proyeccion: RAZON SOCIAL -> local -> grupo -> concepto.
     *
     * POR QUE EL PRIMER NIVEL ES LA RAZON SOCIAL. Es lo que se paga: una
     * factura es de una razon social, se imputa a sus locales y pisa lo que
     * ella factura. Agrupar por local mezclaba en un renglon lo que pagan dos
     * proveedores distintos (San Justo).
     *
     * UN LOCAL CON DOS RAZONES SOCIALES APARECE BAJO LAS DOS, cada una con sus
     * conceptos (AlquileresPago::conceptosPorRazon()). Bajo la principal se ve
     * siempre, aunque no proyecte -con su estado-; bajo una adicional, solo si
     * ella le factura algo en el horizonte. Los locales sin razon social dada
     * de alta van al final, en "Sin razon social", con su aviso de siempre.
     *
     * AL FINAL DE CADA RAZON SOCIAL, "FACTURAS IMPUTADAS": el total a pagar de
     * sus facturas en cada mes de pago. Sin reparto entre locales: la factura
     * es de la razon social, y en los locales que cubre ese mes queda sin
     * proyeccion. 'imputaciones' trae lo que explica el tooltip.
     *
     * NO CALCULA: reparte la misma lista de pagos que suma el tablero, y la
     * suma de las razones da el total de la pestaña al centavo.
     *
     * @param array $c Lo de calcular(), con 'razones' (cod => ['razon', ...]) e 'imputaciones'
     * @param array $porLocal nro => pagos que se muestran (los conceptos en 0 todo el horizonte, fuera)
     * @param array $facturas cod => pagos de origen FACTURA
     * @return array Lista de ['cod', 'nombre', 'sin_razon', 'meses', 'total', 'locales' => [filaLocal()],
     *               'facturas' => null | ['meses', 'total', 'imputaciones']]
     */
    private static function armarRazones($c, $porLocal, $facturas = []) {
        $nombres = [];

        foreach ($c['razones'] ?? [] as $cod => $r) {
            $nombres[Planilla::codigo($cod)] = $r['razon'] ?? $cod;
        }

        $porRazon = [];

        foreach ($c['locales'] as $nro => $l) {
            $principal = Planilla::codigo($l['params']['cod_provee'] ?? '');
            $deEl = isset($nombres[$principal]) ? $principal : self::SIN_RAZON;
            $porRazon[$deEl][$nro] = $porRazon[$deEl][$nro] ?? [];

            foreach ($porLocal[$nro] ?? [] as $p) {
                $cod = self::razonDelPago($p);
                $cod = ($cod !== null && isset($nombres[$cod])) ? $cod : $deEl;
                $porRazon[$cod][$nro][] = $p;
            }
        }

        foreach (array_keys($facturas) as $cod) {
            $porRazon[isset($nombres[$cod]) ? $cod : self::SIN_RAZON] = $porRazon[isset($nombres[$cod]) ? $cod : self::SIN_RAZON] ?? [];
        }

        $out = [];

        foreach ($porRazon as $cod => $locs) {
            $r = ['cod' => ($cod === self::SIN_RAZON) ? null : $cod,
                  'nombre' => ($cod === self::SIN_RAZON) ? 'Sin razón social' : $nombres[$cod],
                  'sin_razon' => $cod === self::SIN_RAZON, 'meses' => [], 'total' => self::celdaVacia(),
                  'locales' => [], 'facturas' => null];

            /* LA LINEA DE FACTURAS IMPUTADAS de esta razon social. */
            $suyas = [];

            foreach ($facturas as $fc => $ps) {
                if ($fc === $cod || ($cod === self::SIN_RAZON && !isset($nombres[$fc]))) {
                    $suyas = array_merge($suyas, $ps);
                }
            }
            $imps = array_values(array_filter($c['imputaciones'] ?? [], function ($i) use ($cod) {
                return Planilla::codigo($i['cod_provee']) === $cod && !$i['suspendida'];
            }));

            if (!empty($suyas) || !empty($imps)) {
                $linea = ['meses' => [], 'total' => self::celdaVacia(), 'imputaciones' => $imps];

                foreach ($suyas as $p) {
                    $linea['meses'][$p['mes_pago']] = self::sumarCelda($linea['meses'][$p['mes_pago']] ?? self::celdaVacia(), $p, true);
                    $linea['total'] = self::sumarCelda($linea['total'], $p, false);
                    $r['meses'][$p['mes_pago']] = self::sumarCelda($r['meses'][$p['mes_pago']] ?? self::celdaVacia(), $p, false);
                    $r['total'] = self::sumarCelda($r['total'], $p, false);
                }

                $r['facturas'] = $linea;
            }

            /* En el orden de la app, no en el de la lista de pagos. */
            foreach (array_keys($c['locales']) as $nro) {
                if (!array_key_exists($nro, $locs)) {
                    continue;
                }

                $r['locales'][] = self::filaLocal($nro, $c['locales'][$nro], $locs[$nro]);

                foreach ($locs[$nro] as $p) {
                    $r['meses'][$p['mes_pago']] = self::sumarCelda($r['meses'][$p['mes_pago']] ?? self::celdaVacia(), $p, false);
                    $r['total'] = self::sumarCelda($r['total'], $p, false);
                }
            }

            $out[] = $r;
        }

        usort($out, function ($a, $b) {
            return ($a['sin_razon'] <=> $b['sin_razon']) ?: strcmp($a['nombre'], $b['nombre']);
        });

        return $out;
    }

    /**
     * Los grupos de la grilla de Proyeccion, en orden. Las facturas imputadas no
     * son un grupo de un local: van en su linea al final de la razon social.
     */
    public static function nombresGrupo() {
        return AlquileresConceptos::NOMBRE_GRUPO;
    }

    /**
     * La sub-pestaña Facturas: la grilla por cuota, las imputaciones, las
     * tarjetas y lo que el armado de una imputacion necesita (las razones
     * sociales con sus locales, los tipos de retencion vigentes y los meses
     * que se pueden imputar). No calcula nada.
     */
    private static function armarFacturas($c, $h) {
        $locales = [];

        foreach ($c['locales'] as $nro => $l) {
            $locales[] = ['nro' => $nro, 'nombre' => $l['nombre'], 'cod_provee' => $l['params']['cod_provee'] ?? null,
                          'razones' => AlquileresPago::razonesDelLocal($l['params']), 'razon' => $l['razon']];
        }

        $tipos = [];

        foreach ($c['tipos_vigentes'] ?? [] as $id => $nombre) {
            $tipos[] = ['id' => (int) $id, 'nombre' => $nombre];
        }

        return [
            'condicion' => $c['condicion'],
            'filas' => $c['facturas'],
            'imputaciones' => $c['imputaciones'],
            'kpis' => $c['facturas_kpis'],
            'estados' => AlquileresImputacion::NOMBRE_ESTADO,
            'locales' => $locales,
            'tipos_retencion' => $tipos,
            'meses' => array_map(function ($m) { return ['clave' => $m, 'nombre' => AlquileresImputacion::mesLargo($m)]; },
                self::mesesImputables($h)),
            'mes_actual' => substr($h->hoy(), 0, 7),
            'tablas' => !empty($c['tabla_imputacion']),
            'tabla_exclusion' => !empty($c['tabla_exclusion']),
            'script_exclusion' => AlquileresFacturaExclusion::SCRIPT,
            'script' => AlquileresImputaciones::SCRIPT,
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
        return ['neto' => 0.0, 'iva' => 0.0, 'percepcion' => 0.0, 'bruto' => 0.0, 'retencion' => 0.0, 'a_pagar' => 0.0,
                'origenes' => []];
    }

    /**
     * El origen de un pago, como lo dice el chip de su celda. Pura.
     *
     *   FACTURA       la factura imputada (la linea "Facturas imputadas")
     *   APP_CERRADA   Real (cerrado): el periodo esta cerrado en la app
     *   APP_ABIERTA   Cargado en app (abierto): un fijo cargado con el periodo
     *                 abierto (y el 9 calculado con ese VMA)
     *   VENTA_REAL    un variable calculado con la venta real del mes
     *   INFLACION:Y-m Estimado · inflacion desde [mes]: un fijo (o el 9
     *                 actualizado como fijo) proyectado por inflacion desde el
     *                 ultimo valor tomado de la app
     *   ESTIMADO      todo estimado
     *
     * La clave lleva el mes de la inflacion: dos fijos que ajustan desde meses
     * distintos no tienen el mismo origen, y su subtotal dice "mixto".
     *
     * @param array $p Un pago de la lista
     * @return array ['clave' => string, 'texto' => string]
     */
    public static function origenDelPago($p) {
        $o = $p['origen'] ?? AlquileresCalculo::ESTIMADO;
        $desde = $p['detalle']['inflacion_desde'] ?? null;

        if ($o === AlquileresCalculo::ESTIMADO && $desde !== null) {
            return ['clave' => 'INFLACION:' . $desde, 'texto' => 'Estimado · inflación desde ' . self::mesCorto($desde)];
        }

        return ['clave' => $o, 'texto' => AlquileresCalculo::NOMBRE_ORIGEN[$o] ?? $o];
    }

    /** '2026-10' -> 'oct-26' */
    private static function mesCorto($mes) {
        $n = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        return $n[(int) substr($mes, 5, 2) - 1] . '-' . substr($mes, 2, 2);
    }

    /**
     * Suma un pago a una celda; en la de concepto, ademas guarda el detalle.
     * Toda celda lleva SUS ORIGENES (clave => texto, bruto y a pagar): con uno
     * solo, la pestaña dibuja su chip; con varios, "mixto" y el desglose, con
     * el mismo importe que muestra la fila (bruto en grupos y conceptos, a
     * pagar en local y razon social).
     */
    private static function sumarCelda($celda, $p, $conDetalle) {
        foreach (['neto', 'iva', 'percepcion', 'bruto', 'retencion', 'a_pagar'] as $k) {
            $celda[$k] = round($celda[$k] + $p[$k], 2);
        }

        $o = self::origenDelPago($p);
        $antes = $celda['origenes'][$o['clave']] ?? ['bruto' => 0.0, 'a_pagar' => 0.0];
        $celda['origenes'][$o['clave']] = ['texto' => $o['texto'],
            'bruto' => round($antes['bruto'] + $p['bruto'], 2),
            'a_pagar' => round($antes['a_pagar'] + $p['a_pagar'], 2)];

        if ($conDetalle) {
            $celda['pagos'][] = array_intersect_key($p, array_flip([
                'fecha', 'vencimiento', 'corrida', 'vencido', 'nota_dia', 'origen', 'mes_concepto',
                'neto', 'iva', 'percepcion', 'bruto', 'retencion', 'a_pagar', 'detalle'
            ]));
        }

        return $celda;
    }

    private static function nombreConcepto($p) {
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

        /* LOS CONTRATOS DE LA APP (RO_T_CONTRATOS_ALQUILERES): de ahi sale la
           vigencia. Segun APERTURA solo avisan; segun VIGENCIA deciden que
           meses proyecta el local. */
        $contratos = [];

        try {
            $contratos = $this->app->contratos();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los contratos de la app de alquileres ('
                . $e->getMessage() . '): los locales que proyectan según la vigencia quedan sin proyectar.', 'Locales');
        }

        foreach ($localesApp as $nro => $l) {
            $p = $params[$nro] ?? null;

            if ($p !== null) {
                $p['contratos'] = $contratos[$nro] ?? [];
            }
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
                && ($p['venta_mensual_estimada'] ?? null) === null
                && $mesesVenta < AlquileresVenta::MESES_MIN_PROPIA) {
                $estado['faltan'][] = 'venta mensual estimada (local nuevo, sin 12 meses de venta)';
            }

            /* DE QUE RAZON SOCIAL ES CADA CONCEPTO: con su dia y su
               diferimiento se paga, y bajo ella se ve. */
            $deQuien = AlquileresPago::conceptosPorRazon($p, $out['razones']);

            foreach ($deQuien['devueltos'] as $cod => $ids) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, $l['nombre'] . ': ' . $cod . ' está dada de baja en Razones '
                    . 'sociales y facturaba ' . implode(', ', $ids) . '. Esos conceptos se proyectan con la principal, '
                    . 'con su día y su diferimiento, hasta que se la vuelva a dar de alta o se le quiten.', 'Locales');
            }

            $out['locales'][$nro] = [
                'nro' => $nro,
                'nombre' => $l['nombre'],
                'habilitado' => $l['habilitado'],
                'params' => $p,
                'concepto_razon' => $deQuien['por_concepto'],
                'razones_sin_conceptos' => $deQuien['sin_conceptos'],
                /* La principal y, despues, las adicionales dadas de alta: es lo
                   que muestra la columna Razon social de la pestaña. */
                'razon' => (isset($p['cod_provee']) && isset($out['razones'][$p['cod_provee']]))
                    ? implode(' · ', array_map(function ($c) use ($out) { return $out['razones'][$c]['razon']; },
                        array_values(array_filter(AlquileresPago::razonesDelLocal($p), function ($c) use ($out) {
                            return isset($out['razones'][$c]);
                        }))))
                    : null,
                'estado' => $estado['estado'],
                'faltan' => $estado['faltan'],
                'nuevo' => $nuevo,
                'meses_venta' => $mesesVenta,
                /* El contrato que se muestra en Parametros y en el aviso: el de hoy, el
                   proximo o el ultimo, de la app. */
                'contrato' => AlquileresPago::contratoActual($contratos[$nro] ?? [], $this->hoy),
                'contratos' => $contratos[$nro] ?? []
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

    /**
     * Los proveedores con RUBRO = ALQUILER excluidos de Proveedores Locales y
     * sin dar de alta: AlquileresFacturas::rubroSinFila() con lo que lee.
     * Nunca lanza: si algo no se puede leer, lista vacia y aviso.
     *
     * @param array $pendientes Filas de getPendientes()
     * @param array $razones Las dadas de alta: cod => ...
     * @param array $avisos
     * @return array
     */
    protected function rubroSinFila($pendientes, $razones, &$avisos) {
        $universo = $this->universoRazones($avisos);

        if ($universo === null) {
            return [];
        }

        try {
            require_once __DIR__ . '/ProveedoresExclusion.php';
            $excl = (new ProveedoresExclusion())->vigentes(ProveedoresExclusion::MODULO_PROV_LOCALES);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los excluidos de Proveedores Locales ('
                . $e->getMessage() . '): no se puede decir qué proveedores de alquiler quedan fuera del tablero.');

            return [];
        }

        return AlquileresFacturas::rubroSinFila($universo, $excl, $razones, $pendientes);
    }

    /** La alicuota general, en puntos (21, no 0,21) */
    protected function ivaGeneral() {
        require_once __DIR__ . '/Parametros.php';

        return Parametros::num((new Parametros())->getParametrosMap(), 'alicuota_iva') * 100;
    }
}
