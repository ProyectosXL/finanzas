<?php

require_once __DIR__ . '/AlquileresApp.php';
require_once __DIR__ . '/AlquileresParametros.php';
require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/LlavesParametros.php';
require_once __DIR__ . '/LlavesCalculo.php';
require_once __DIR__ . '/LlavesSugeridos.php';
require_once __DIR__ . '/LlavesFacturas.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/Planilla.php';

/**
 * Llaves
 * Arma lo que ven Parametros -> Llaves y Renovaciones, la pestaña RRHH y
 * Operativos -> Llaves y Renov. Contratos y el proveedor del tablero: junta la
 * app de alquileres (AlquileresApp), Parametros -> Alquileres (el operador y
 * la retencion de cada local), el alquiler que proyecta Alquileres, los m2 del
 * gestor de locales, la curva del dolar, los parametros de Llaves y las
 * facturas imputadas, y se los pasa a la regla pura (LlavesCalculo).
 *
 * NO TIENE REGLAS PROPIAS. Si aca aparece un "si el local tal, entonces", esta
 * en el lugar equivocado: va en LlavesCalculo o LlavesSugeridos, con su prueba.
 *
 * NUNCA LANZA POR UN DATO QUE FALTA. Sin el script, sin la app, sin el
 * alquiler de Alquileres o sin la curva, devuelve lo que puede y un aviso que
 * dice que falta y donde se arregla. La pestaña y Parametros cargan igual, y
 * el resto del cashflow no se entera.
 */
class Llaves {

    /** Las leyendas de una factura de llave que se avisan si quedan en Proveedores Locales */
    const LEYENDAS_AVISO = ['LLAVE', 'CUOTA INICIAL', 'SERV COMERCIALIZACION', 'PRENDA'];

    /** @var string 'Y-m-d' */
    protected $hoy;

    /** @var AlquileresApp */
    protected $app;

    /** @var LlavesParametros */
    protected $par;

    /** @var AlquileresParametros */
    protected $alqPar;

    /**
     * @param string|null $hoy 'Y-m-d'; por defecto hoy. Se inyecta para las pruebas.
     */
    function __construct($hoy = null, $app = null, $par = null, $alqPar = null) {
        $this->hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $this->app = $app ?: new AlquileresApp();
        $this->par = $par ?: new LlavesParametros();
        $this->alqPar = $alqPar ?: new AlquileresParametros();
    }

    /* ====================================================================
       LO QUE SE LEE
       ==================================================================== */

    /**
     * La base comun de Parametros y la pestaña: los locales de la app con sus
     * contratos, el operador y la retencion de Parametros -> Alquileres y los
     * m2. Nunca lanza.
     *
     * @param array $avisos
     * @return array ['locales' => NRO => entrada de LlavesCalculo, 'nombres' => NRO => nombre,
     *                'razones' => COD => ['razon', ...], 'contratos' => NRO => [...], 'app_ok' => bool]
     */
    protected function base(&$avisos) {
        $out = ['locales' => [], 'nombres' => [], 'razones' => [], 'contratos' => [], 'app_ok' => false, 'm2' => []];

        try {
            $locales = $this->app->locales();
            $out['contratos'] = $this->app->contratosConImportes();
            $out['app_ok'] = true;
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los locales y contratos de la app de alquileres ('
                . $e->getMessage() . '): no hay nada que proyectar.');

            return $out;
        }

        $alqLocales = [];

        try {
            if (!$this->alqPar->tablaCreada()) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Faltan las tablas de Parámetros › Alquileres ('
                    . AlquileresParametros::SCRIPT . '): sin el operador de cada local no se proyecta ninguna llave.');
            } else {
                $alqLocales = $this->alqPar->locales();
                $out['razones'] = $this->alqPar->razonesVigentes();
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los parámetros de Alquileres ('
                . $e->getMessage() . '): sin el operador de cada local no se proyecta ninguna llave.');
        }

        try {
            require_once __DIR__ . '/LocalesMedidas.php';
            $out['m2'] = (new LocalesMedidas())->todas();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los m² de los locales (' . $e->getMessage()
                . '): no hay segmento por m² para las obras.');
        }

        foreach ($locales as $nro => $l) {
            $out['nombres'][$nro] = $l['nombre'];

            /* UN LOCAL CERRADO EN LAKERS NO RENUEVA: no entra al universo. */
            if (!$l['habilitado']) {
                continue;
            }

            $p = $alqLocales[$nro] ?? null;
            $cod = ($p === null) ? null : Planilla::codigo($p['cod_provee'] ?? '');
            $cod = ($cod !== '' && $cod !== null && isset($out['razones'][$cod])) ? $cod : null;

            $out['locales'][$nro] = [
                'nro' => $nro,
                'nombre' => $l['nombre'],
                'habilitado' => true,
                'vigencia' => ($p !== null) && AlquileresPago::porVigencia($p),
                'cod_provee' => $cod,
                'operador' => ($cod === null) ? null : ($out['razones'][$cod]['razon'] ?? $cod),
                'pct_retencion' => ($p === null) ? null : ($p['pct_retencion'] ?? null),
                'contratos' => $out['contratos'][$nro] ?? [],
                'm2' => $out['m2'][$nro]['total'] ?? null
            ];
        }

        return $out;
    }

    /**
     * Todo lo que necesita la proyeccion. Nunca lanza: cada lectura falla por
     * su lado, queda vacia y avisa.
     *
     * @param Horizonte $h
     * @return array
     */
    public function insumos($h) {
        $avisos = [];
        $base = $this->base($avisos);
        $ins = $base + ['par' => $this->par->todo(), 'alquiler' => [], 'habiles' => [], 'curva' => [],
                        'facturas' => [], 'operadores' => [], 'facturas_alq' => null, 'tabla_facturas' => false];

        /* EL ALQUILER LO PROYECTA ALQUILERES: se le pide, no se recalcula. Con
           el, el calendario y las facturas de alquiler (la misma lectura de
           Tango). */
        try {
            require_once __DIR__ . '/Alquileres.php';
            $alq = new Alquileres($h->hoy());
            $insA = $alq->insumos($h);
            $ins['habiles'] = $insA['habiles'];
            $ins['facturas_alq'] = $insA['facturas'] ?? null;
            $ins['alquiler'] = $alq->alquilerMensual($h, $insA);

            /* LAS FACTURAS MARCADAS COMO LLAVE: las marcas son del contexto de
               Alquileres (la misma lectura que decide su destino) y lo pendiente,
               las cuotas de destino A_LLAVES. Sin la condicion de seguridad de
               Alquileres no hay ninguna: las facturas siguen en Proveedores
               Locales y lo proyectado no se corta. */
            $f = $insA['facturas'] ?? [];

            if (!empty($f['ctx']) && !empty($f['ctx']['condicion']['cumple'])) {
                require_once __DIR__ . '/LlavesFacturas.php';

                $pend = [];

                foreach ($f['llaves_factura'] ?? [] as $p) {
                    if (empty($p['PAGADA_TANGO'])) {
                        $pend[AlquileresFacturas::claveCuota($p)] = $p;
                    }
                }

                $pc = LlavesFacturas::paraCalculo($f['ctx']['facturas_llaves'] ?? [], $pend, $f['vtos'] ?? [],
                    $f['dias'] ?? [], $ins['habiles'], $h->hoy());
                $ins['facturas'] = $pc['facturas'];
                $avisos = array_merge($avisos, $pc['avisos']);
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer el alquiler proyectado de Alquileres ('
                . $e->getMessage() . '): no se estima ninguna llave. Los contratos cargados se proyectan igual.',
                'Proyección');
        }

        try {
            require_once __DIR__ . '/DolarFuturo.php';
            $ins['curva'] = (new DolarFuturo())->curva();
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la curva del dólar futuro (' . $e->getMessage()
                . '): las obras y las aperturas no se proyectan en pesos.', 'Proyección');
        }

        try {
            require_once __DIR__ . '/ProveedoresCategorias.php';

            foreach ((new ProveedoresCategorias())->mapa() as $cod => $p) {
                $ins['operadores'][$cod] = $p['NOMBRE'];
            }
        } catch (Throwable $e) {
            /* Solo nombres para los compromisos: sin el maestro se ve el codigo. */
        }

        foreach ($base['razones'] as $cod => $r) {
            $ins['operadores'][$cod] = $r['razon'] ?? $cod;
        }

        $ins['avisos'] = $avisos;

        return $ins;
    }

    /**
     * La proyeccion: lo de LlavesCalculo::proyectar() con los avisos de las
     * lecturas y los de las facturas.
     *
     * @param Horizonte $h
     * @param array $ins Lo de insumos()
     * @return array
     */
    public function calcular($h, $ins) {
        $r = LlavesCalculo::proyectar([
            'hoy' => $h->hoy(),
            'meses' => array_column($h->meses(), 'clave'),
            'habiles' => $ins['habiles'],
            'locales' => $ins['locales'],
            'alquiler' => $ins['alquiler'],
            'par' => $ins['par'],
            'curva' => $ins['curva'],
            'facturas' => $ins['facturas'],
            'operadores' => $ins['operadores'],
            'locales_nombres' => $ins['nombres']
        ]);

        $r['avisos'] = array_merge($ins['avisos'], $r['avisos'], self::avisosFacturas($ins['facturas_alq']));
        $r['tablas_ok'] = !empty($ins['par']['tablas_ok']);

        return $r;
    }

    /**
     * Los avisos informativos de las facturas: las excluidas en Alquileres como
     * pago de contrato, que hoy no suman y pueden ser llaves, y las facturas de
     * llave (por su leyenda) que siguen en Proveedores Locales: si su concepto
     * tambien esta en Llaves, se contaria dos veces. Pura.
     *
     * @param array|null $f Lo de Alquileres::insumos()['facturas']
     * @return array
     */
    public static function avisosFacturas($f) {
        if (empty($f) || empty($f['ctx'])) {
            return [];
        }

        $out = [];
        $plata = function ($n) { return '$ ' . number_format((float) $n, 2, ',', '.'); };
        $excl = $f['excluidas_factura'] ?? [];

        if (!empty($excl)) {
            $total = array_sum(array_map(function ($p) {
                return !empty($p['PAGADA_TANGO']) ? (float) $p['IMPORTE_VTO'] : (float) $p['IMPORTE_PENDIENTE'];
            }, $excl));
            $out[] = Aviso::nuevo(Aviso::INFO, count($excl) . ' factura(s) de alquiler por ' . $plata($total)
                . ' están excluidas como pago de contrato y no suman en ningún lado: '
                . implode('; ', array_map(function ($p) {
                    return Planilla::codigo($p['COD_PROVEE']) . ' ' . trim($p['T_COMP'] . ' ' . $p['N_COMP'])
                        . (empty($p['LEYENDA']) ? '' : ' «' . trim($p['LEYENDA']) . '»');
                }, $excl)) . '. Si alguna es una llave o una renovación, volvé a incluirla y marcala «Es llave / '
                . 'renovación» en Alquileres › Facturas.', 'Facturas');
        }

        $enPl = self::leyendasEnProvLocales($f['todos'] ?? [], $f['ctx']);

        if (!empty($enPl)) {
            $out[] = Aviso::nuevo(Aviso::INFO, count($enPl) . ' factura(s) con leyenda de llave, cuota inicial, '
                . 'servicio de comercialización o prenda siguen en Cuentas a Pagar Locales: '
                . implode('; ', array_map(function ($p) use ($plata) {
                    return Planilla::codigo($p['COD_PROVEE']) . ' ' . trim($p['T_COMP'] . ' ' . $p['N_COMP']) . ' «'
                        . trim($p['LEYENDA']) . '» ' . $plata($p['IMPORTE_PENDIENTE']);
                }, $enPl)) . '. Si ese concepto también está en Llaves (contrato o estimado), se cuenta dos veces: '
                . 'marcalo «Ya pagada» o cargá el pago como compromiso, según corresponda.', 'Facturas');
        }

        return $out;
    }

    /**
     * Las pendientes de CUALQUIER proveedor que quedan en Proveedores Locales
     * con una leyenda de llave. "SERV" suelto no: trae los servicios de
     * sistemas y el service charge de un pasaje. Pura.
     *
     * @param array $pendientes Filas de getPendientes()
     * @param array $ctx El de AlquileresFacturas
     * @return array
     */
    public static function leyendasEnProvLocales($pendientes, $ctx) {
        return array_values(array_filter(is_array($pendientes) ? $pendientes : [], function ($p) use ($ctx) {
            if (AlquileresFacturas::destino($p, $ctx) !== AlquileresFacturas::A_PROV_LOCALES) {
                return false;
            }

            $ley = strtoupper((string) ($p['LEYENDA'] ?? ''));

            foreach (self::LEYENDAS_AVISO as $l) {
                if (strpos($ley, $l) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    /* ====================================================================
       PARAMETROS -> LLAVES Y RENOVACIONES
       ==================================================================== */

    /**
     * Todo lo que la sub-pestaña de Parametros necesita: los valores, lo que se
     * sugiere con la historia y que locales tienen una renovacion en curso. No
     * lee el alquiler de Alquileres (es pesado y aca no hace falta): lo que se
     * estima se ve en la pestaña.
     *
     * @return array
     */
    public function parametros() {
        $avisos = [];
        $base = $this->base($avisos);
        $tablas = $this->par->tablaCreada();
        $par = $this->par->todo();

        if (!$tablas) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, $this->par->avisoSinTabla());
        } elseif (!$this->par->ajustes()['todo']) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Falta correr ' . LlavesParametros::SCRIPT_AJUSTES . ' contra la base '
                . 'central: hasta entonces no se pueden cargar el % de comisiones ni el de FPC (las comisiones y el FPC '
                . 'estimados no se proyectan), «Ya pagada» pide monto y las facturas no se marcan como Comisiones o FPC.',
                'Por operador');
        }

        $gen = $par['generales'];

        /* LOS CORTES SE SUGIEREN con los m2 de los locales habilitados de hoy:
           los confirma una persona. */
        $m2 = [];

        foreach ($base['locales'] as $nro => $l) {
            if ($l['m2'] !== null) {
                $m2[$nro] = $l['m2'];
            }
        }

        $sinM2 = array_values(array_map(function ($l) { return $l['nombre']; },
            array_filter($base['locales'], function ($l) { return $l['m2'] === null; })));

        if (($gen['CORTE_CHICO'] ?? null) === null || ($gen['CORTE_MEDIANO'] ?? null) === null) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Faltan confirmar los cortes de segmento por m²: sin ellos ninguna '
                . 'obra sin segmento a mano se proyecta. Se sugieren los terciles de los m² de los locales de hoy.',
                'Generales');
        }

        if (!empty($sinM2)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Locales sin m² en el Gestor de Locales: ' . implode(', ', $sinM2)
                . '. Una obra en ellos necesita el segmento a mano.', 'Generales');
        }

        /* LOS SUGERIDOS POR OPERADOR: con las renovaciones de la app y las
           facturas de Tango. El alquiler del primer mes de cada contrato sale
           de la app (cerrado o el fijo cargado con el periodo abierto). */
        $operadorDe = [];

        foreach ($base['locales'] as $nro => $l) {
            if ($l['cod_provee'] !== null) {
                $operadorDe[$nro] = $l['cod_provee'];
            }
        }

        $sugeridos = [];
        $impuestos = [];

        try {
            $sugeridos = LlavesSugeridos::porOperador($operadorDe, $base['contratos'], $this->alquilerDeLaApp($base['contratos']),
                $base['nombres']);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo medir el % sugerido de cada operador ('
                . $e->getMessage() . ').', 'Por operador');
        }

        try {
            require_once __DIR__ . '/LlavesTango.php';
            $impuestos = LlavesSugeridos::impuestos((new LlavesTango())->facturasDeLlave(array_keys($base['razones']),
                date('Y-m-d', strtotime($this->hoy . ' -' . LlavesTango::MESES_HISTORIA . ' month'))),
                $base['contratos'], $base['nombres']);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer de Tango las facturas de llave ('
                . $e->getMessage() . '): no hay % de impuestos sugerido.', 'Por operador');
        }

        $operadores = [];

        foreach ($base['razones'] as $cod => $r) {
            $operadores[] = [
                'cod' => $cod, 'nombre' => $r['razon'] ?? $cod, 'valores' => $par['operadores'][$cod] ?? null,
                'locales' => array_values(array_map(function ($l) { return $l['nombre']; },
                    array_filter($base['locales'], function ($l) use ($cod) { return $l['cod_provee'] === $cod; }))),
                'sugerido' => $sugeridos[$cod] ?? null,
                'impuestos' => $impuestos[$cod] ?? null,
                /* Lo que se usa con el campo vacio: lo general (LlavesCalculo::queSeUsa()). */
                'usa' => LlavesCalculo::queSeUsa(null, null, $gen)
            ];
        }

        usort($operadores, function ($a, $b) { return count($b['locales']) <=> count($a['locales']) ?: strcmp($a['nombre'], $b['nombre']); });

        return [
            'tabla_creada' => $tablas,
            'script' => LlavesParametros::SCRIPT,
            'hoy' => $this->hoy,
            'generales' => $gen,
            'definiciones' => LlavesParametros::GENERALES,
            'cortes_sugeridos' => LlavesCalculo::terciles(array_values($m2)),
            'm2_locales' => count($m2),
            'operadores' => $operadores,
            'renovaciones' => $this->renovacionesEnCurso($base, $par),
            'pagadas' => $par['pagadas'],
            'obras' => $par['obras'],
            'aperturas' => $par['aperturas'],
            'compromisos' => $par['compromisos'],
            'locales' => array_values(array_map(function ($l) use ($gen) {
                return ['nro' => $l['nro'], 'nombre' => $l['nombre'], 'm2' => $l['m2'],
                        'segmento' => LlavesCalculo::segmento($l['m2'], $gen['CORTE_CHICO'] ?? null, $gen['CORTE_MEDIANO'] ?? null)];
            }, $base['locales'])),
            'proveedores' => $this->universoProveedores($base['razones']),
            'segmentos' => LlavesParametros::SEGMENTOS,
            'formas' => LlavesParametros::FORMAS,
            'conceptos_pagada' => LlavesParametros::CONCEPTOS_PAGADA,
            /* Que partes del script 73 ya se corrieron: sin ellas, sus campos se ven deshabilitados. */
            'ajustes' => $tablas ? $this->par->ajustes() : ['pagada_sin_monto' => false, 'pct_separados' => false,
                                                           'conceptos_factura' => false, 'todo' => false],
            'script_ajustes' => LlavesParametros::SCRIPT_AJUSTES,
            'avisos' => $avisos
        ];
    }

    /**
     * Las renovaciones de cada local que todavia pueden tener algo que
     * parametrizar o marcar como pagado: la del vigente que vence dentro del
     * horizonte o ya vencio (sin renovacion cargada), y las cargadas que
     * SIGUEN VIGENTES O SON FUTURAS y tienen alguna cuota desde el mes pasado
     * (el criterio de "vieja" de LlavesCalculo::esVieja(), con las mismas
     * fechas que proyecta: LlavesCalculo::fechasCuotas()). Una cargada
     * terminada o con todos sus pagos atras no se muestra; sus parametros
     * siguen en la base, con su historial.
     *
     * SIN EL CALENDARIO: Parametros no lo lee, asi que el corrimiento al habil
     * es de lunes a viernes. Solo cambiaria algo si un feriado cae en el borde
     * del mes de la ultima cuota.
     *
     * @return array
     */
    protected function renovacionesEnCurso($base, $par) {
        $out = [];
        $ultimoMes = Inflacion::mesMas(substr($this->hoy, 0, 7), 11);
        $gen = $par['generales'] ?? [];

        foreach ($base['locales'] as $nro => $l) {
            if ($l['vigencia'] || empty($l['contratos'])) {
                continue;
            }

            $ks = LlavesCalculo::limpiar($l['contratos']);
            $vig = LlavesCalculo::vigente($ks, $this->hoy);

            if ($vig !== null) {
                $ren = LlavesCalculo::renovacionDe($ks, $vig);
                $locP = $par['locales'][$nro . '|' . $vig['id']] ?? null;
                $estado = LlavesCalculo::estado($vig, $ren, !empty($locP['no_renueva']), $this->hoy, $ultimoMes);

                if (in_array($estado, [LlavesCalculo::VENCE, LlavesCalculo::VENCIDO, LlavesCalculo::NO_RENUEVA], true)) {
                    $conLlave = LlavesCalculo::contratoDeLlave($ks, $vig);
                    $deMeses = LlavesCalculo::ultimoConLlave($ks, $conLlave ?? $vig) ?? ($conLlave ?? $vig);
                    $r = ['tipo' => LlavesCalculo::ESTIMADA, 'contrato' => null, 'ant_contrato' => $vig];
                    $out[] = ['nro' => $nro, 'local' => $l['nombre'], 'operador' => $l['operador'], 'tipo' => LlavesCalculo::ESTIMADA,
                              'estado' => $estado, 'nombre_estado' => LlavesCalculo::NOMBRE_ESTADO[$estado],
                              'ant' => $vig['id'], 'contrato' => null, 'vigente' => $vig, 'tiene_llave' => $conLlave !== null,
                              'meses_defecto' => LlavesCalculo::meses($deMeses['desde'], $deMeses['hasta']),
                              'meses_de' => 'contrato #' . $deMeses['id']]
                        + $this->loQueSeUsa($r, $locP, $l, $par, $gen);
                }
            }

            foreach ($ks as $c) {
                $ant = LlavesCalculo::anteriorDe($ks, $c);

                if ($ant === null || $c['hasta'] < $this->hoy
                    || !(LlavesCalculo::tieneLlave($c) || ((float) $c['comisiones'] + (float) $c['fpc']) > 0)) {
                    continue;
                }

                $locP = $par['locales'][$nro . '|' . $ant['id']] ?? null;
                $opP = ($l['cod_provee'] === null) ? [] : ($par['operadores'][$l['cod_provee']] ?? []);
                $cal = LlavesCalculo::fechasCuotas(['tipo' => LlavesCalculo::CARGADA, 'contrato' => $c, 'ant_contrato' => $ant],
                    $locP, $opP, $gen, $this->hoy, []);

                if (!LlavesCalculo::esVieja($cal['fechas'], $this->hoy)) {
                    $out[] = ['nro' => $nro, 'local' => $l['nombre'], 'operador' => $l['operador'], 'tipo' => LlavesCalculo::CARGADA,
                              'estado' => null, 'nombre_estado' => 'Contrato #' . $c['id'] . ' cargado',
                              'ant' => $ant['id'], 'contrato' => $c, 'vigente' => $ant, 'tiene_llave' => LlavesCalculo::tieneLlave($c),
                              'meses_defecto' => LlavesCalculo::meses($c['desde'], $c['hasta']), 'meses_de' => 'contrato #' . $c['id']]
                        + $this->loQueSeUsa(['tipo' => LlavesCalculo::CARGADA, 'contrato' => $c, 'ant_contrato' => $ant],
                            $locP, $l, $par, $gen);
                }
            }
        }

        usort($out, function ($a, $b) { return strcmp($a['local'], $b['local']) ?: strcmp($a['tipo'], $b['tipo']); });

        return $out;
    }

    /**
     * Lo que usa una renovacion en cada campo del local que quede vacio, para
     * que Parametros lo diga debajo del campo («usa 4 (operador)»): el % de
     * llave y las cuotas del operador o las generales (LlavesCalculo::
     * queSeUsa()) y el mes de la primera cuota que calcula
     * LlavesCalculo::fechasCuotas() sin el del local. No hay reglas aca.
     *
     * @return array ['cod_provee', 'parametros', 'usa', 'mes1_defecto']
     */
    private function loQueSeUsa($r, $locP, $l, $par, $gen) {
        $opP = ($l['cod_provee'] === null) ? [] : ($par['operadores'][$l['cod_provee']] ?? []);
        $sinMes = $locP;
        unset($sinMes['mes_primera_cuota']);

        return ['cod_provee' => $l['cod_provee'], 'parametros' => $locP, 'usa' => LlavesCalculo::queSeUsa(null, $opP, $gen),
                'mes1_defecto' => LlavesCalculo::fechasCuotas($r, $sinMes, $opP, $gen, $this->hoy, [])['mes1']];
    }

    /**
     * El 1 + 2 + 8 de la app del primer mes de cada contrato: cerrado, o el
     * fijo cargado con el periodo abierto (AlquileresApp::detalleAbiertos()).
     * Es lo que mide el % sugerido; no se proyecta nada con esto.
     *
     * @return array NRO => 'Y-m' => float
     */
    protected function alquilerDeLaApp($contratos) {
        $meses = [];

        foreach ($contratos as $lista) {
            foreach ($lista as $c) {
                $meses[substr($c['desde'], 0, 7)] = true;
            }
        }

        if (empty($meses)) {
            return [];
        }

        $cerrados = $this->app->mesesCerrados();
        $desde = min(array_keys($meses));
        $out = [];
        $suma = function ($det) use (&$out) {
            foreach ($det as $nro => $ms) {
                foreach ($ms as $m => $ids) {
                    $v = ($ids[1] ?? 0.0) + ($ids[2] ?? 0.0) + ($ids[8] ?? 0.0);

                    if ($v > 0 && !isset($out[$nro][$m])) {
                        $out[$nro][$m] = round($v, 2);
                    }
                }
            }
        };

        $suma($this->app->detalleCerrados($cerrados, $desde));
        $suma($this->app->detalleAbiertos(array_keys($meses), $cerrados));

        return $out;
    }

    /** Los operadores de un compromiso: las razones de Alquileres y el maestro de Proveedores Locales. */
    protected function universoProveedores($razones) {
        $out = [];

        foreach ($razones as $cod => $r) {
            $out[] = ['tipo' => LlavesParametros::TIPO_ALQUILERES, 'cod' => $cod, 'nombre' => $r['razon'] ?? $cod];
        }

        try {
            require_once __DIR__ . '/ProveedoresCategorias.php';

            foreach ((new ProveedoresCategorias())->mapa() as $cod => $p) {
                $out[] = ['tipo' => LlavesParametros::TIPO_PROVEEDOR, 'cod' => $cod, 'nombre' => $p['NOMBRE']];
            }
        } catch (Throwable $e) {
            /* Sin el maestro, solo las razones de Alquileres. */
        }

        return $out;
    }

    /* ====================================================================
       LA PESTAÑA
       ==================================================================== */

    /**
     * Lo que la pestaña necesita: Proyeccion, Contratos, Facturas, tarjetas y
     * avisos.
     *
     * @param Horizonte $h
     * @return array
     */
    public function proyeccion($h) {
        $ins = $this->insumos($h);

        return self::armarPestana($this->calcular($h, $ins), $h, $ins);
    }

    /**
     * La grilla de Proyeccion -operador -> local -> concepto, y aparte las
     * aperturas y los compromisos-, la de Contratos, la de Facturas y las
     * tarjetas, a partir de calcular().
     *
     * NO CALCULA NADA: agrupa la lista de pagos con EjeVista (la regla dia o
     * mes de Horizonte::agrupar()). Por eso lo que la pestaña dice es lo mismo
     * que dice el tablero, que acumula la misma lista; lo fija una prueba.
     *
     * @param array $c Lo de calcular()
     * @param Horizonte $h
     * @param array $ins Lo de insumos() (nombres y facturas)
     * @return array
     */
    public static function armarPestana($c, $h, $ins = []) {
        require_once __DIR__ . '/EjeVista.php';

        $locales = $c['locales'];
        $sinOp = 'Sin razón social';
        $items = [];
        $celdas = [];
        $dias = $h->diasSet();
        $meses = $h->mesesSet();

        foreach ($c['pagos'] as $p) {
            $agrupa = in_array($p['concepto'], array_merge(LlavesCalculo::DE_RENOVACION, [LlavesCalculo::OBRA]), true)
                && $p['nro'] !== null;

            if ($agrupa) {
                /* Bajo el operador DEL LOCAL, aunque la factura sea de otro (IRSA
                   factura el FPC de DOT): la grilla es por local. */
                $op = $locales[$p['nro']]['operador'] ?? ($locales[$p['nro']]['cod_provee'] ?? null) ?? $sinOp;
                $claves = ['O|' . $op, 'L|' . $op . '|' . $p['nro'], 'C|' . $op . '|' . $p['nro'] . '|' . $p['concepto']];
            } elseif ($p['concepto'] === LlavesCalculo::APERTURA) {
                $claves = ['G|APERTURA', 'A|' . ($p['id_apertura'] ?? $p['local'])];
            } else {
                $claves = ['G|COMPROMISO', 'K|' . ($p['operador'] ?? $p['cod_provee']) . '|' . ($p['concepto_texto'] ?? '')];
            }

            $col = isset($dias[$p['fecha']]) ? 'DIA|' . $p['fecha']
                : (isset($meses[substr($p['fecha'], 0, 7)]) ? 'MES|' . substr($p['fecha'], 0, 7) : null);

            foreach ($claves as $k) {
                $items[] = ['fila' => $k, 'fecha' => $p['fecha'], 'importe' => $p['importe']];

                if ($col !== null) {
                    $celdas[$k][$col]['origenes'][$p['origen']] = true;
                    $celdas[$k][$col]['detalle'][] = LlavesCalculo::NOMBRE_ORIGEN[$p['origen']] . ' · '
                        . LlavesCalculo::plata($p['importe']) . ' · ' . trim($p['detalle']);
                }
            }
        }

        $eje = EjeVista::armarAgrupado($h, $items, 'fila', 'fecha', 'importe');
        $porClave = [];

        foreach ($eje['filas'] as $f) {
            $porClave[$f['fila']] = $f;
        }

        /* El total de la grilla: cada pago una sola vez (las filas repiten el
           importe en cada nivel). */
        $total = EjeVista::armarAgrupado($h, array_map(function ($p) {
            return ['fila' => 'TOTAL', 'fecha' => $p['fecha'], 'importe' => $p['importe']];
        }, $c['pagos']), 'fila', 'fecha', 'importe');
        $eje['filas'] = [];
        $eje['totales'] = $total['totales'];

        $filas = [];
        $agregar = function ($id, $nivel, $padre, $nombre, $sub, $tipo) use (&$filas, $porClave, $celdas) {
            if (!isset($porClave[$id])) {
                return;
            }

            $cel = [];

            foreach ($celdas[$id] ?? [] as $col => $x) {
                $orig = array_keys($x['origenes']);
                $cel[$col] = ['origen' => (count($orig) === 1) ? $orig[0] : 'MIXTO',
                              'chip' => (count($orig) === 1) ? LlavesCalculo::NOMBRE_ORIGEN[$orig[0]] : 'mixto',
                              'tooltip' => implode("\n", $x['detalle'])];
            }

            $filas[] = ['id' => $id, 'nivel' => $nivel, 'padre' => $padre, 'nombre' => $nombre, 'sub' => $sub,
                        'tipo' => $tipo, 'eje' => $porClave[$id], 'celdas' => $cel];
        };

        /* Operador -> local -> concepto, por nombre. */
        $ops = [];

        foreach (array_keys($porClave) as $k) {
            if (strpos($k, 'L|') === 0) {
                list(, $op, $nro) = explode('|', $k);
                $ops[$op][] = (int) $nro;
            }
        }

        ksort($ops);

        foreach ($ops as $op => $nros) {
            $agregar('O|' . $op, 0, null, $op, '', 'OPERADOR');
            usort($nros, function ($a, $b) use ($locales, $ins) {
                return strcmp($locales[$a]['nombre'] ?? ($ins['nombres'][$a] ?? ''), $locales[$b]['nombre'] ?? ($ins['nombres'][$b] ?? ''));
            });

            foreach ($nros as $nro) {
                $l = $locales[$nro] ?? ['nombre' => $ins['nombres'][$nro] ?? ('Local ' . $nro), 'estado' => null];
                $agregar('L|' . $op . '|' . $nro, 1, 'O|' . $op, $l['nombre'], (string) $nro, 'LOCAL');

                /* Llave, Comisiones, FPC, Escribano y otros y Obra de imagen. "Otros
                   gastos (marca anterior)" solo aparece con una factura marcada con
                   el concepto viejo: $agregar() saltea una fila sin pagos. */
                foreach (array_merge(LlavesCalculo::DE_RENOVACION, [LlavesCalculo::OBRA]) as $con) {
                    $agregar('C|' . $op . '|' . $nro . '|' . $con, 2, 'L|' . $op . '|' . $nro,
                        ($con === LlavesCalculo::OTROS) ? 'Otros gastos (marca anterior)' : LlavesCalculo::NOMBRE_CONCEPTO[$con],
                        '', 'CONCEPTO');
                }
            }
        }

        $vistos = [];
        $agregar('G|APERTURA', 0, null, 'Aperturas', '', 'GRUPO');

        foreach ($c['pagos'] as $p) {
            if ($p['concepto'] === LlavesCalculo::APERTURA && !isset($vistos['A|' . $p['id_apertura']])) {
                $vistos['A|' . $p['id_apertura']] = true;
                $agregar('A|' . $p['id_apertura'], 1, 'G|APERTURA', $p['local'], $p['segmento'] ?? '', 'APERTURA');
            }
        }

        $agregar('G|COMPROMISO', 0, null, 'Compromisos a mano', '', 'GRUPO');

        foreach ($c['pagos'] as $p) {
            $k = 'K|' . ($p['operador'] ?? $p['cod_provee']) . '|' . ($p['concepto_texto'] ?? '');

            if ($p['concepto'] === LlavesCalculo::COMPROMISO && !isset($vistos[$k])) {
                $vistos[$k] = true;
                $agregar($k, 1, 'G|COMPROMISO', $p['concepto_texto'] ?? 'Compromiso', $p['operador'] ?? $p['cod_provee'], 'COMPROMISO');
            }
        }

        /* LAS TARJETAS */
        $sum = function ($filtro) use ($c) {
            return round(array_sum(array_column(array_filter($c['pagos'], $filtro), 'importe')), 2);
        };
        $renovaciones = [];

        /* LA CANTIDAD Y EL IMPORTE MIDEN LO MISMO: los pagos de llave y otros
           gastos, sea cual sea su origen, facturas incluidas. Una factura se
           cuenta en la renovacion a la que se imputo (clave_renovacion), asi una
           renovacion con la llave facturada y los otros gastos proyectados es
           una sola. */
        foreach ($c['pagos'] as $p) {
            $clave = $p['clave_renovacion'] ?? $p['clave'] ?? null;

            if (in_array($p['concepto'], LlavesCalculo::DE_RENOVACION, true) && !empty($clave)) {
                $renovaciones[$clave] = true;
            }
        }

        $sinDato = array_unique(array_filter(array_column($c['no_proyecta'], 'nro'), function ($n) { return $n !== null; }));

        return [
            'hoy' => $h->hoy(),
            'eje' => $eje,
            'filas' => $filas,
            'kpis' => [
                'total' => $sum(function () { return true; }),
                'renovaciones_n' => count($renovaciones),
                'renovaciones_importe' => $sum(function ($p) {
                    return in_array($p['concepto'], LlavesCalculo::DE_RENOVACION, true);
                }),
                'estimado' => $sum(function ($p) { return $p['origen'] === LlavesCalculo::ESTIMADO; }),
                'firme' => $sum(function ($p) { return in_array($p['origen'], [LlavesCalculo::CONTRATO, LlavesCalculo::FACTURA], true); }),
                'manual' => $sum(function ($p) { return $p['origen'] === LlavesCalculo::MANUAL; }),
                'sin_dato_n' => count($sinDato),
                'sin_dato' => array_values(array_unique(array_column($c['no_proyecta'], 'local')))
            ],
            'contratos' => self::armarContratos($c),
            'facturas' => self::armarFacturas($ins['facturas'] ?? [], $ins['nombres'] ?? []),
            'origenes' => LlavesCalculo::NOMBRE_ORIGEN,
            'estados' => LlavesCalculo::NOMBRE_ESTADO,
            'no_proyecta' => $c['no_proyecta'],
            'tablas_ok' => $c['tablas_ok'] ?? false,
            'script' => LlavesParametros::SCRIPT,
            'avisos' => $c['avisos']
        ];
    }

    /** La solapa Contratos: lo que hoy es la hoja "Detalles de vencimientos". */
    private static function armarContratos($c) {
        $out = [];

        foreach ($c['locales'] as $nro => $l) {
            $vig = $l['vigente'];
            $ren = $l['renovacion'];
            $rens = array_values(array_filter($l['renovaciones'] ?? [], function ($r) { return empty($r['vieja']); }));
            $out[] = [
                'nro' => $nro, 'nombre' => $l['nombre'], 'operador' => $l['operador'], 'cod_provee' => $l['cod_provee'],
                'estado' => $l['estado'], 'nombre_estado' => LlavesCalculo::NOMBRE_ESTADO[$l['estado']] ?? $l['estado'],
                'vigente' => $vig, 'meses' => $l['meses_vigente'], 'renovacion' => $ren, 'm2' => $l['m2'],
                'segmento' => $l['segmento'], 'notas' => array_merge($l['notas'], ...array_map(function ($r) {
                    return $r['notas'];
                }, $rens ?: [['notas' => []]])),
                'renovaciones' => array_map(function ($r) {
                    return ['tipo' => $r['tipo'], 'ant' => $r['ant'], 'contrato' => $r['contrato'], 'inicio' => $r['inicio'],
                            'mes1' => $r['mes1'], 'cuotas' => $r['cuotas'], 'llave' => $r['llave'], 'comisiones' => $r['comisiones'] ?? null,
                            'fpc' => $r['fpc'] ?? null, 'escribano' => $r['escribano'] ?? null];
                }, $rens),
                'pagadas' => array_merge(...array_values($l['pagadas'] ?: [[]])),
                'cantidad_contratos' => count($l['contratos'])
            ];
        }

        usort($out, function ($a, $b) { return strcmp($a['nombre'], $b['nombre']); });

        return $out;
    }

    /** La solapa Facturas: las marcadas como llave, pendientes y pagadas. */
    private static function armarFacturas($facturas, $nombres) {
        return array_map(function ($f) use ($nombres) {
            return ['id' => $f['id'], 'comprobante' => $f['comprobante'], 'cod_provee' => $f['cod_provee'],
                    'nro' => $f['nro'], 'local' => $nombres[$f['nro']] ?? ('Local ' . $f['nro']), 'concepto' => $f['concepto'],
                    'nombre_concepto' => LlavesFacturas::NOMBRES[$f['concepto']] ?? $f['concepto'], 'mes' => $f['mes'],
                    'ant' => $f['ant'], 'contrato' => $f['contrato'], 'pagada' => $f['pagada'], 'pagos' => $f['pagos'],
                    'total' => round(array_sum(array_column($f['pagos'], 'importe')), 2), 'texto' => $f['texto']];
        }, is_array($facturas) ? $facturas : []);
    }

    /* ====================================================================
       MARCAR UNA FACTURA COMO LLAVE (desde Alquileres -> Facturas)
       ==================================================================== */

    /**
     * Lo que valida marcar una factura como llave (LlavesFacturas::validar()),
     * leido AHORA: las cuotas que se pueden marcar -las pendientes de alquiler,
     * las pagadas del mes sin imputar y las pagadas de los ultimos 12 meses-,
     * las imputadas en Alquileres, las excluidas, las ya marcadas, los locales
     * y sus contratos, y las cuotas por defecto de cada operador.
     *
     * Lanza si la condicion de seguridad de Alquileres no se cumple: las
     * facturas de alquiler siguen en Proveedores Locales y no se marcan.
     *
     * @param Horizonte $h
     * @return array El $e de LlavesFacturas::validar() + 'vtos', 'dias', 'habiles', 'cuotas', 'pagadas_12'
     */
    public function contextoMarcar($h) {
        require_once __DIR__ . '/Alquileres.php';
        require_once __DIR__ . '/AlquileresUniverso.php';
        require_once __DIR__ . '/AlquileresFacturaVto.php';
        require_once __DIR__ . '/LlavesFacturas.php';
        require_once __DIR__ . '/Proveedores.php';

        $alq = new Alquileres($h->hoy());
        $e = $alq->contextoImputacion($h);
        $ctx = AlquileresUniverso::delPedido();
        $imputadas = [];

        foreach ($e['imputaciones'] as $imp) {
            foreach ($imp['facturas'] as $fi) {
                $imputadas[AlquileresImputacion::claveCuota($fi)] = $imp['id'];
            }
        }

        /* LAS PAGADAS DE LOS ULTIMOS 12 MESES que nadie imputo: se marcan como
           llave para cortar la estimacion, sin sumar. */
        $pagadas12 = [];
        $desde = date('Y-m-01', strtotime(substr($h->hoy(), 0, 7) . '-01 -' . LlavesFacturas::MESES_PAGADAS . ' month'));
        $pm = AlquileresFacturas::partir((new Proveedores())->getCanceladasEmitidas(array_keys($ctx['razones']), $desde,
            date('Y-m-t', strtotime($h->hoy()))), $ctx);

        foreach ($pm[AlquileresFacturas::A_ALQUILERES] as $p) {
            $c = AlquileresImputacion::claveCuota($p);

            if (!isset($e['pendientes'][$c]) && !isset($imputadas[$c])) {
                $e['pendientes'][$c] = AlquileresImputacion::comoPendiente($p);
                $pagadas12[$c] = $e['pendientes'][$c];
            }
        }

        $avisos = [];
        $base = $this->base($avisos);
        $par = $this->par->todo();
        $cuotas = [];

        foreach ($base['razones'] as $cod => $r) {
            $cuotas[$cod] = (int) ($par['operadores'][$cod]['cuotas'] ?? $par['generales']['CUOTAS'] ?? 1);
        }

        return [
            'imputables' => $e['pendientes'], 'imputadas' => $imputadas, 'excluidas' => $e['excluidas'],
            'llaves' => $ctx['facturas_llaves'] ?? [], 'locales' => $base['nombres'], 'contratos' => $base['contratos'],
            'hoy' => $h->hoy(), 'vtos' => (new AlquileresFacturaVto())->vigentes(), 'dias' => $e['dias'],
            'cuotas' => $cuotas, 'cuotas_general' => (int) ($par['generales']['CUOTAS'] ?? 1), 'pagadas_12' => $pagadas12,
            /* Sin el script 73, Comisiones y FPC no se ofrecen ni se validan. */
            'conceptos' => (new LlavesFacturas())->conceptosMarcables()
        ];
    }

    /**
     * La previsualizacion de una marca: la validacion, la renovacion que corta
     * y, de a una factura pendiente sin pagos cargados, los pagos por defecto
     * (las cuotas del operador desde su vencimiento a considerar). Los
     * habiles salen del calendario.
     *
     * @return array Lo de validar() + 'pagos_defecto', 'vto', 'local'
     */
    public function previsualizarMarca($d, $e) {
        $v = LlavesFacturas::validar($d, $e);
        $v['pagos_defecto'] = [];
        $v['local'] = $e['locales'][(int) ($d['nro'] ?? 0)] ?? null;

        if (count($v['cuotas']) === 1 && !$v['pagada']) {
            require_once __DIR__ . '/CronogramaDatos.php';

            $f = $v['cuotas'][0];
            $vto = AlquileresImputacion::vtoAConsiderar($f, $e['vtos'], $e['dias']);
            $n = $e['cuotas'][Planilla::codigo($f['COD_PROVEE'])] ?? $e['cuotas_general'];
            $habiles = (new CronogramaDatos())->habilesEntre(substr($e['hoy'], 0, 8) . '01',
                date('Y-m-t', strtotime($vto . ' +' . ($n + 1) . ' month')));
            $v['vto'] = $vto;
            $v['pagos_defecto'] = LlavesFacturas::pagosDefecto(round($v['total'] - (float) $v['retencion'], 2), $n, $vto,
                $habiles, $e['hoy']);
        }

        return $v;
    }

    /**
     * Lo que las escrituras de Parametros validan contra la app: los locales,
     * los contratos de cada uno y las razones de Alquileres.
     *
     * @return array ['locales' => NRO => [...], 'contratos' => NRO => [...], 'razones' => COD => [...]]
     */
    public function contextoEscritura() {
        $avisos = [];
        $base = $this->base($avisos);

        if (!$base['app_ok']) {
            throw new InvalidArgumentException('No se pudo leer la app de alquileres: no se puede validar. Probá de nuevo.');
        }

        return ['locales' => $base['nombres'], 'contratos' => $base['contratos'], 'razones' => $base['razones']];
    }
}
