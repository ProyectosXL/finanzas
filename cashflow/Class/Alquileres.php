<?php

require_once __DIR__ . '/AlquileresApp.php';
require_once __DIR__ . '/AlquileresParametros.php';
require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/AlquileresVenta.php';
require_once __DIR__ . '/AlquileresPago.php';
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
