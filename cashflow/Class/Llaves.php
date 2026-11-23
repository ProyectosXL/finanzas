<?php

require_once __DIR__ . '/AlquileresApp.php';
require_once __DIR__ . '/AlquileresParametros.php';
require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/LlavesParametros.php';
require_once __DIR__ . '/LlavesCalculo.php';
require_once __DIR__ . '/LlavesSugeridos.php';
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
                'impuestos' => $impuestos[$cod] ?? null
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
            'avisos' => $avisos
        ];
    }

    /**
     * Las renovaciones de cada local que todavia pueden tener algo que
     * parametrizar o marcar como pagado: la del vigente que vence dentro del
     * horizonte o ya vencio (sin renovacion cargada), y las de los contratos
     * cargados que empezaron en el ultimo año o empiezan despues (sus cuotas
     * pueden estar por delante).
     *
     * @return array
     */
    protected function renovacionesEnCurso($base, $par) {
        $out = [];
        $ultimoMes = Inflacion::mesMas(substr($this->hoy, 0, 7), 11);
        $haceUnAnio = date('Y-m-d', strtotime($this->hoy . ' -12 month'));

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
                    $conLlave = LlavesCalculo::ultimoConLlave($ks, $vig);
                    $sig = date('Y-m-d', strtotime($vig['hasta'] . ' +1 day'));
                    $out[] = ['nro' => $nro, 'local' => $l['nombre'], 'operador' => $l['operador'], 'tipo' => LlavesCalculo::ESTIMADA,
                              'estado' => $estado, 'nombre_estado' => LlavesCalculo::NOMBRE_ESTADO[$estado],
                              'ant' => $vig['id'], 'contrato' => null, 'vigente' => $vig, 'tiene_llave' => LlavesCalculo::tieneLlave($vig),
                              'meses_defecto' => LlavesCalculo::meses(($conLlave ?? $vig)['desde'], ($conLlave ?? $vig)['hasta']),
                              'mes1_defecto' => ($vig['hasta'] < $this->hoy) ? substr($this->hoy, 0, 7) : substr($sig, 0, 7),
                              'parametros' => $locP];
                }
            }

            foreach ($ks as $c) {
                $ant = LlavesCalculo::anteriorDe($ks, $c);

                if ($ant !== null && $c['desde'] >= $haceUnAnio
                    && (LlavesCalculo::tieneLlave($c) || ((float) $c['comisiones'] + (float) $c['fpc']) > 0)) {
                    $out[] = ['nro' => $nro, 'local' => $l['nombre'], 'operador' => $l['operador'], 'tipo' => LlavesCalculo::CARGADA,
                              'estado' => null, 'nombre_estado' => 'Contrato #' . $c['id'] . ' cargado',
                              'ant' => $ant['id'], 'contrato' => $c, 'vigente' => $ant, 'tiene_llave' => LlavesCalculo::tieneLlave($c),
                              'meses_defecto' => LlavesCalculo::meses($c['desde'], $c['hasta']), 'mes1_defecto' => substr($c['desde'], 0, 7),
                              'parametros' => $par['locales'][$nro . '|' . $ant['id']] ?? null];
                }
            }
        }

        usort($out, function ($a, $b) { return strcmp($a['local'], $b['local']) ?: strcmp($a['tipo'], $b['tipo']); });

        return $out;
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
