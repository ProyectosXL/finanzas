<?php
/**
 * InformeEconomico
 * El orquestador: lee la base UNA vez por pedido, arma el contexto y le pasa
 * todo a las funciones puras (Cascada, Formulas, Columnas, Semaforo,
 * HistorialEdicion). Aca no se hace ninguna cuenta del informe.
 *
 * AVISOS EN VEZ DE ERRORES
 * ------------------------
 * Casi nada corta el informe. Un mes sin resumen, una cotizacion que falta,
 * un local que no esta en el maestro, un rubro sin seccion: todo se informa
 * arriba de la tabla y el informe sigue con lo que hay. Lo unico que lo corta
 * es no tener estructura (sql/ie_estructura.sql), porque sin filas no hay
 * informe que mostrar.
 *
 * Cada aviso es ['nivel' => info|warning|danger, 'texto' => , 'detalle' => [],
 * 'link' => ['tab' =>, 'col' =>]].
 */
require_once __DIR__ . '/BaseIE.php';
require_once __DIR__ . '/Periodo.php';
require_once __DIR__ . '/Canales.php';
require_once __DIR__ . '/Rubros.php';
require_once __DIR__ . '/Formulas.php';
require_once __DIR__ . '/Cascada.php';
require_once __DIR__ . '/Columnas.php';
require_once __DIR__ . '/EstructuraFilas.php';
require_once __DIR__ . '/Cotizacion.php';
require_once __DIR__ . '/Sucursales.php';
require_once __DIR__ . '/HistorialEdicion.php';
require_once __DIR__ . '/ParametrosIE.php';
require_once __DIR__ . '/Semaforo.php';

class InformeEconomico {

    const TABLA = 'RO_T_RESUMEN_FINAL_IE';
    const TABLA_EDICION = 'RO_T_IE_RESUMEN_EDICION';

    private $cid;
    private $avisos = [];

    public function __construct($cid) {
        $this->cid = $cid;
    }

    private function aviso($nivel, $texto, array $detalle = [], $link = null) {
        $this->avisos[] = ['nivel' => $nivel, 'texto' => $texto, 'detalle' => $detalle, 'link' => $link];
    }

    /* ================================================================
       CONTEXTO COMUN A TODAS LAS VISTAS
       ================================================================ */

    /**
     * Lee y prepara todo lo que las vistas comparten.
     *
     * @param array $f ['desde', 'hasta', 'moneda', 'comparar', 'cerradas']
     * @param bool $conAnterior si hace falta el anio anterior (el Dashboard
     *        lo pide siempre: sus KPIs y la alerta de caida lo necesitan)
     * @return array|null el contexto, o null si no hay estructura
     */
    private function preparar(array $f, $conAnterior) {
        if (!EstructuraFilas::existe($this->cid)) {
            return null;
        }

        $periodos = Periodo::generar($f['desde'], $f['hasta']);
        $periodosAA = $conAnterior ? Periodo::anioAnterior($periodos) : [];

        $todos = array_values(array_unique(array_merge($periodos, $periodosAA)));
        $registros = BaseIE::filas($this->cid,
            'SELECT ID, PERIODO, NRO_SUCURSAL, DESC_SUCURSAL, COD_RUBRO, IMPORTE FROM ' . self::TABLA
            . ' WHERE PERIODO IN (' . BaseIE::marcas(count($todos)) . ')', $todos);

        // ---- meses con resumen: el criterio de existeResumen()
        $rubrosPorPeriodo = [];

        foreach ($registros as $r) {
            $rubrosPorPeriodo[$r['PERIODO']][$r['COD_RUBRO']] = true;
        }

        $conResumen = array_flip(Periodo::conResumen(array_map('array_keys', $rubrosPorPeriodo)));
        $okActual = array_values(array_filter($periodos, function ($p) use ($conResumen) { return isset($conResumen[$p]); }));
        $okAA = array_values(array_filter($periodosAA, function ($p) use ($conResumen) { return isset($conResumen[$p]); }));
        $faltan = array_values(array_diff($periodos, $okActual));
        $faltanAA = array_values(array_diff($periodosAA, $okAA));

        if ($faltan) {
            $this->aviso('warning', 'Meses sin resumen en el rango: no se muestran ni se editan. El resumen se genera desde Control de Gastos; este módulo no procesa nada.',
                array_map(function ($p) { return Periodo::nombre($p); }, $faltan));
        }

        if ($faltanAA) {
            $this->aviso('warning', 'Meses del año anterior sin resumen: su comparativo queda vacío ("—").',
                array_map(function ($p) { return Periodo::nombre($p); }, $faltanAA));
        }

        $actual = [];
        $anterior = [];

        // Sin elseif: con un rango de mas de 12 meses un mes esta en los dos
        // (es actual de una columna y anio anterior de otra).
        foreach ($registros as $r) {
            if (in_array($r['PERIODO'], $okActual, true)) {
                $actual[] = $r;
            }

            if (in_array($r['PERIODO'], $okAA, true)) {
                $anterior[] = $r;
            }
        }

        // ---- rubros y secciones
        $maestro = Rubros::leerMaestro($this->cid);
        $codigos = array_unique(array_merge(array_column($actual, 'COD_RUBRO'), array_column($anterior, 'COD_RUBRO')));
        $clasif = Rubros::clasificar($codigos, $maestro);

        if ($clasif['sinSeccion']) {
            $det = [];

            foreach ($clasif['sinSeccion'] as $cod) {
                $imp = 0.0;

                foreach ($actual as $r) {
                    if ($r['COD_RUBRO'] === $cod) {
                        $imp += (float) $r['IMPORTE'];
                    }
                }

                $det[] = $cod . ' ' . ($maestro[$cod]['nombre'] ?? '(no está en el maestro)') . ': ' . self::fmt($imp);
            }

            $this->aviso('danger', 'Hay rubros sin sección: se muestran en el bloque "Sin sección" y no suman a ningún total. Asignales una categoría en Parámetros.',
                $det, ['tab' => 'parametros']);
        }

        // ---- sucursales: nombres, presentes y cerradas
        $nombres = Canales::resolverNombres($actual);
        $sinNombre = [];

        foreach ($nombres as $k => $n) {
            if (!empty($n['aviso'])) {
                $sinNombre[] = $n['nombre'];
            }
        }

        // Un solo aviso para todas: uno por sucursal tapaba la tabla.
        if ($sinNombre) {
            $this->aviso('info', 'Sucursales sin DESC_SUCURSAL en ningún registro del rango: se muestran como "Sucursal N".', $sinNombre);
        }

        $presentes = array_keys($nombres);
        $cerradas = $this->cerradas($presentes, $actual, !empty($f['cerradas']));

        // ---- moneda
        $moneda = ($f['moneda'] ?? 'ARS') === 'USD' ? 'USD' : 'ARS';
        $tcc = null;
        $tccAA = null;

        if ($moneda === 'USD') {
            $c = Cotizacion::porMes($this->cid, $okActual);
            $cAA = $okAA ? Cotizacion::porMes($this->cid, $okAA) : ['tcc' => null, 'faltantes' => []];
            $falta = array_merge($c['faltantes'], $cAA['faltantes']);

            if ($c['tcc'] === null || ($okAA && $cAA['tcc'] === null)) {
                $this->aviso('danger', 'Falta la cotización (TCC) de RO_V_DOLAR_OFICIAL_BCRA para algún mes: el informe se muestra en PESOS.',
                    array_map(function ($p) { return Periodo::nombre($p); }, $falta));
                $moneda = 'ARS';
            } else {
                $tcc = $c['tcc'];
                $tccAA = $cAA['tcc'];
            }
        }

        $ix = Cascada::indexar($actual);
        $ixAA = Cascada::indexar($anterior);

        if ($moneda === 'USD') {
            $ix = Cascada::convertir($ix, $tcc);

            if ($tccAA !== null) {
                $ixAA = Cascada::convertir($ixAA, $tccAA);
            }
        }

        $this->avisosDeDatos($actual, $nombres);

        return [
            'periodos' => $okActual,
            'periodosAA' => $okAA,
            'rangoAA' => $periodosAA,
            'actual' => $actual,
            'maestro' => $maestro,
            'clasif' => $clasif,
            'nombres' => $nombres,
            'presentes' => $presentes,
            'cerradas' => $cerradas,
            'incluirCerradas' => !empty($f['cerradas']),
            'moneda' => $moneda,
            'tcc' => $tcc,
            'tccAA' => $tccAA,
            'ix' => $ix,
            'ixAA' => $ixAA
        ];
    }

    /**
     * Los locales cerrados, y los avisos del maestro de sucursales.
     *
     * @return array [nro => true]
     */
    private function cerradas(array $presentes, array $actual, $incluir) {
        $maestro = Sucursales::maestroPropios();
        $locales = array_values(array_filter($presentes, function ($k) {
            return $k !== Canales::CLAVE_SIN && Canales::esLocal($k);
        }));

        if ($maestro === null) {
            $this->aviso('warning', 'No se pudo leer SUCURSALES_LAKERS: todos los locales se tratan como abiertos.');

            return [];
        }

        $cerradas = [];
        $noEsta = [];
        $sinEstado = [];

        foreach ($locales as $n) {
            $e = Canales::estadoLocal($n, $maestro);

            if ($e === Canales::CERRADO) {
                $cerradas[$n] = true;
            } elseif ($e === Canales::NO_ESTA) {
                $noEsta[] = $n;
            } elseif ($e === Canales::SIN_ESTADO) {
                $sinEstado[] = $n;
            }
        }

        if ($noEsta) {
            $this->aviso('info', 'Locales propios que no están en SUCURSALES_LAKERS: se tratan como abiertos.', $noEsta);
        }

        if ($sinEstado) {
            $this->aviso('warning', 'Locales con el estado no cargado en SUCURSALES_LAKERS (HABILITADO vacío): se tratan como abiertos.', $sinEstado);
        }

        if ($cerradas && !$incluir) {
            $venta = 0.0;

            foreach ($actual as $r) {
                if (isset($cerradas[Canales::clave($r['NRO_SUCURSAL'])]) && in_array($r['COD_RUBRO'], ['1.5.', '1.6.', '1.7.', '1.8.'], true)) {
                    $venta += (float) $r['IMPORTE'];
                }
            }

            $this->aviso('info', count($cerradas) . ' sucursal(es) cerrada(s) con datos en el rango quedaron afuera de LOCALES y del Total general. Venden '
                . self::fmt($venta) . ' (1.9, en pesos). Activá "Incluir sucursales cerradas" para verlas.', array_keys($cerradas));
        }

        return $cerradas;
    }

    /**
     * Avisos sobre los datos del rango: Sin sucursal, costo faltante y
     * aperturas de Ecommerce con los gastos todavia en 102.
     */
    private function avisosDeDatos(array $actual, array $nombres) {
        $porMes = [];

        foreach ($actual as $r) {
            $k = Canales::clave($r['NRO_SUCURSAL']);
            $porMes[$r['PERIODO']][$k][$r['COD_RUBRO']] = ($porMes[$r['PERIODO']][$k][$r['COD_RUBRO']] ?? 0.0) + (float) $r['IMPORTE'];
        }

        // ---- Sin sucursal: importe por rubro y meses donde aparece
        $sinRubro = [];
        $sinMeses = [];

        uksort($porMes, [Periodo::class, 'comparar']);

        foreach ($porMes as $p => $sucs) {
            if (isset($sucs[Canales::CLAVE_SIN])) {
                $sinMeses[] = Periodo::nombre($p);

                foreach ($sucs[Canales::CLAVE_SIN] as $c => $v) {
                    $sinRubro[$c] = ($sinRubro[$c] ?? 0.0) + $v;
                }
            }
        }

        if ($sinRubro) {
            $det = [];

            foreach (Rubros::ordenar(array_keys($sinRubro)) as $c) {
                $det[] = $c . ': ' . self::fmt($sinRubro[$c]);
            }

            $det[] = 'Meses: ' . implode(', ', $sinMeses);
            $this->aviso('warning', 'Hay registros sin sucursal (NRO_SUCURSAL vacío o 0). Se muestran en la columna "Sin sucursal", que NO suma al Total general ni a ningún canal (igual que el Excel).',
                $det, ['tab' => 'locales', 'col' => 'SIN']);
        }

        // ---- costo faltante: vende (1.5-1.7) y no tiene rubro 2 en el mes
        $sinCosto = [];

        foreach ($porMes as $p => $sucs) {
            foreach ($sucs as $k => $rub) {
                if ($k === Canales::CLAVE_SIN || $k === Canales::OTROS_INGRESOS) {
                    continue;
                }

                $venta = ($rub['1.5.'] ?? 0) + ($rub['1.6.'] ?? 0) + ($rub['1.7.'] ?? 0);

                if (abs($venta) > 0.005 && !isset($rub['2.'])) {
                    $sinCosto[$k][] = Periodo::nombre($p);
                }
            }
        }

        if ($sinCosto) {
            $det = [];

            foreach ($sinCosto as $k => $meses) {
                $det[] = ($nombres[$k]['nombre'] ?? $k) . ' (' . $k . '): ' . implode(', ', $meses);
            }

            $this->aviso('warning', 'Costo faltante: estas sucursales tienen venta sin costo de ventas (rubro 2) en algún mes. Su mark up muestra "—" y su resultado bruto está sobrestimado.', $det);
        }

        // ---- aperturas de Ecommerce con los gastos todavia en 102
        $ecom = [];

        foreach ($porMes as $p => $sucs) {
            $r102 = $sucs[102] ?? [];
            $venta102 = ($r102['1.5.'] ?? 0) + ($r102['1.6.'] ?? 0) + ($r102['1.7.'] ?? 0) + ($r102['1.8.'] ?? 0);
            $gastos102 = 0.0;

            foreach ($r102 as $c => $v) {
                if (strpos($c, '1.') !== 0 && $c !== '2.') {
                    $gastos102 += $v;
                }
            }

            if (abs($gastos102) < 0.005 || abs($venta102) > 0.005) {
                continue;
            }

            foreach ([301, 302, 303] as $n) {
                $rn = $sucs[$n] ?? [];
                $vn = ($rn['1.5.'] ?? 0) + ($rn['1.6.'] ?? 0) + ($rn['1.7.'] ?? 0) + ($rn['1.8.'] ?? 0);

                if (abs($vn) > 0.005) {
                    $ecom[$n][] = Periodo::nombre($p);
                }
            }
        }

        if ($ecom) {
            $det = [];

            foreach ($ecom as $n => $meses) {
                $det[] = ($nombres[$n]['nombre'] ?? $n) . ' (' . $n . '): ' . implode(', ', $meses);
            }

            $this->aviso('warning', 'Aperturas de Ecommerce con venta mientras los gastos del mes siguen imputados en 102: su resultado individual no es comparable. No se reasigna nada; el subtotal ECOMMERCE sí es correcto.', $det);
        }
    }

    /** Participaciones: el 1.9 del Total general y de LOCALES en esos periodos */
    private function referencias(array $ix, array $ctx, array $periodos) {
        $loc = Columnas::localesIncluidos($ctx['presentes'], $ctx['cerradas'], $ctx['incluirCerradas']);

        return [
            'total19' => Cascada::venta19($ix, Columnas::sucursalesTotal($loc), $periodos),
            'locales19' => Cascada::venta19($ix, $loc, $periodos)
        ];
    }

    /** Calcula una columna en el rango actual y, si corresponde, en el anterior */
    private function calcularColumna(array $col, array $ctx, $conAA) {
        $res = Cascada::columna($ctx['ix'], $col, $ctx['clasif'], $this->referencias($ctx['ix'], $ctx, $col['periodos']));
        $resAA = null;

        if ($conAA) {
            $pAA = array_values(array_filter(Periodo::anioAnterior($col['periodos']), function ($p) use ($ctx) {
                return in_array($p, $ctx['periodosAA'], true);
            }));
            $colAA = $col;
            $colAA['periodos'] = $pAA;
            $resAA = Cascada::columna($ctx['ixAA'], $colAA, $ctx['clasif'], $this->referencias($ctx['ixAA'], $ctx, $pAA));
        }

        return [$res, $resAA];
    }

    /* ================================================================
       IE POR CANALES, POR LOCALES Y EVOLUCION MENSUAL
       ================================================================ */

    /**
     * @param array $f ['vista' => canales|locales|mensual, 'desde', 'hasta',
     *        'moneda', 'comparar', 'cerradas', 'canal']
     */
    public function generar(array $f) {
        $comparar = !empty($f['comparar']);
        $ctx = $this->preparar($f, $comparar);

        if ($ctx === null) {
            return $this->sinEstructura();
        }

        if (!$ctx['periodos']) {
            return $this->vacio($ctx);
        }

        switch ($f['vista']) {
            case 'locales':
                $cols = Columnas::locales($ctx['presentes'], $ctx['nombres'], $ctx['cerradas'], $ctx['incluirCerradas'], $ctx['periodos']);
                break;
            case 'mensual':
                $canal = in_array($f['canal'] ?? '', Columnas::CANALES_MENSUAL, true) ? $f['canal'] : 'TODOS';
                $cols = Columnas::mensual($ctx['presentes'], $ctx['cerradas'], $ctx['incluirCerradas'], $ctx['periodos'], $canal);
                break;
            default:
                $cols = Columnas::canales($ctx['presentes'], $ctx['cerradas'], $ctx['incluirCerradas'], $ctx['periodos']);
        }

        if ($comparar && in_array($f['vista'], ['locales', 'canales'], true)) {
            $this->aviso('info', 'Ecommerce: antes de 2026 todo estaba en 102. Lo comparable interanualmente es el subtotal ECOMMERCE; la variación de cada apertura (301-303) se muestra, pero contra un año anterior que no la tenía separada.');
        }

        $res = [];
        $resAA = [];

        foreach ($cols as $col) {
            list($res[$col['clave']], $resAA[$col['clave']]) = $this->calcularColumna($col, $ctx, $comparar);
        }

        $estr = EstructuraFilas::expandir(EstructuraFilas::leer($this->cid), $ctx['clasif'], $ctx['maestro']);

        foreach ($estr['avisos'] as $a) {
            $this->aviso('warning', $a, [], ['tab' => 'parametros']);
        }

        $filas = [];

        foreach ($estr['filas'] as $fila) {
            if ($fila['tipo'] !== 'TITULO') {
                $fila['valores'] = [];
                $esRatio = $fila['tipo'] === 'RATIO';

                foreach ($cols as $col) {
                    $k = $col['clave'];
                    $v = Cascada::valor($fila, $res[$k]);
                    $celda = ['v' => $v];

                    if (!$esRatio) {
                        $celda['pct'] = Cascada::porcentaje($v, $res[$k]['calc']['V_1_9']);
                    }

                    if ($comparar) {
                        $aa = Cascada::valor($fila, $resAA[$k]);
                        $celda['aa'] = $aa;
                        $celda['var'] = Cascada::variacion($v, $aa, $esRatio);
                    }

                    $fila['valores'][$k] = $celda;
                }
            }

            $filas[] = $fila;
        }

        $edicion = $this->marcas($ctx, $cols);

        return [
            'ok' => true,
            'vista' => $f['vista'],
            'moneda' => $ctx['moneda'],
            'tcc' => $ctx['tcc'],
            'tccAA' => $ctx['tccAA'],
            'comparar' => $comparar,
            'periodos' => $ctx['periodos'],
            'periodosAA' => $ctx['periodosAA'],
            'columnas' => array_map(function ($c) { unset($c['sucursales']); return $c; }, $cols),
            'filtros' => array_combine(array_column($cols, 'clave'), array_map(function ($c) {
                return ['sucursales' => $c['sucursales'], 'periodos' => $c['periodos']];
            }, $cols)),
            'filas' => $filas,
            'marcas' => $edicion['marcas'],
            'edicionDisponible' => $edicion['disponible'],
            'avisos' => $this->avisos
        ];
    }

    private function sinEstructura() {
        return [
            'ok' => false,
            'message' => 'Falta correr sql/ie_estructura.sql: sin la estructura de filas el informe no se puede armar.',
            'avisos' => []
        ];
    }

    private function vacio(array $ctx) {
        return ['ok' => true, 'vacio' => true, 'moneda' => $ctx['moneda'], 'columnas' => [], 'filas' => [],
                'marcas' => [], 'avisos' => $this->avisos, 'periodos' => []];
    }

    /**
     * Las marcas de celda editada.
     *
     * Una celda esta editada si alguno de sus registros tiene historial y su
     * importe actual es el nuevo del ultimo movimiento. Se recorre por
     * registro editado (son pocos) y se marca cada columna que lo contiene,
     * subtotales y total incluidos: un total que tiene adentro un importe
     * corregido a mano tiene que decirlo.
     *
     * Los importes del tooltip van siempre en pesos, que es como se editan.
     */
    private function marcas(array $ctx, array $cols) {
        if (!BaseIE::existe($this->cid, self::TABLA_EDICION)) {
            $this->aviso('info', 'Falta correr sql/ie_resumen_edicion.sql: el informe se ve completo, pero no se puede editar ningún importe.');

            return ['disponible' => false, 'marcas' => new stdClass()];
        }

        $movs = BaseIE::filas($this->cid, 'SELECT ID_REGISTRO, TIPO, IMPORTE_ANTERIOR, IMPORTE_NUEVO, MOTIVO, USUARIO, FECHA FROM '
            . self::TABLA_EDICION . ' WHERE PERIODO IN (' . BaseIE::marcas(count($ctx['periodos'])) . ') ORDER BY ID_REGISTRO, ID',
            $ctx['periodos']);

        if (!$movs) {
            return ['disponible' => true, 'marcas' => new stdClass()];
        }

        $porId = [];

        foreach ($movs as $m) {
            $porId[(int) $m['ID_REGISTRO']][] = $m;
        }

        $registros = [];

        foreach ($ctx['actual'] as $r) {
            $registros[(int) $r['ID']] = $r;
        }

        $marcas = [];

        foreach ($porId as $id => $lista) {
            if (!isset($registros[$id])) {
                continue; // pisado por un reproceso: sin marca
            }

            $r = $registros[$id];
            $e = HistorialEdicion::estado($lista, (float) $r['IMPORTE']);

            if (!$e['editado']) {
                continue;
            }

            $k = Canales::clave($r['NRO_SUCURSAL']);
            $info = [
                'id' => $id,
                'original' => $e['original'],
                'anterior' => (float) $e['ultimo']['IMPORTE_ANTERIOR'],
                'nuevo' => (float) $e['ultimo']['IMPORTE_NUEVO'],
                'usuario' => $e['ultimo']['USUARIO'],
                'fecha' => $e['ultimo']['FECHA'],
                'motivo' => $e['ultimo']['MOTIVO'],
                'tipo' => $e['ultimo']['TIPO']
            ];

            foreach ($cols as $col) {
                if (in_array($r['PERIODO'], $col['periodos'], true) && in_array($k, $col['sucursales'], true)) {
                    $marcas[$col['clave']][$r['COD_RUBRO']][] = $info;
                }
            }
        }

        return ['disponible' => true, 'marcas' => $marcas ?: new stdClass()];
    }

    /* ================================================================
       DASHBOARD
       ================================================================ */

    /**
     * KPIs, estructura de costos por canal, ranking de locales y alertas.
     *
     * Calcula SIEMPRE el anio anterior: los KPIs llevan su variacion y la
     * alerta de caida la necesita. El toggle de comparar solo muestra u oculta
     * las flechas del ranking (eso es del front).
     */
    public function dashboard(array $f) {
        $ctx = $this->preparar($f, true);

        if ($ctx === null) {
            return $this->sinEstructura();
        }

        if (!$ctx['periodos']) {
            return $this->vacio($ctx);
        }

        $canales = Columnas::canales($ctx['presentes'], $ctx['cerradas'], $ctx['incluirCerradas'], $ctx['periodos']);
        $locales = array_values(array_filter(
            Columnas::locales($ctx['presentes'], $ctx['nombres'], $ctx['cerradas'], $ctx['incluirCerradas'], $ctx['periodos']),
            function ($c) { return $c['grupo'] === 'LOCAL'; }
        ));

        $res = [];

        foreach (array_merge($canales, $locales) as $col) {
            $res[$col['clave']] = $this->calcularColumna($col, $ctx, true);
        }

        // ---- (a) KPIs del Total general
        list($t, $tAA) = $res['TOTAL'];
        $kpis = [];

        foreach ([
            'V_1_9' => ['Total ventas sin IVA', false],
            'MARGEN_BRUTO' => ['Margen bruto', true],
            'RESULTADO_OPERATIVO' => ['Resultado operativo', false],
            'CONTRIBUCION_MARGINAL' => ['Contribución marginal neta', false],
            'RESULTADO_EXPLOTACION' => ['Resultado de explotación', false],
            'RENTABILIDAD_TOTAL' => ['Rentabilidad total', true]
        ] as $clave => $d) {
            $v = $t['calc'][$clave];
            $aa = $tAA['calc'][$clave];
            $kpis[] = ['clave' => $clave, 'etiqueta' => $d[0], 'ratio' => $d[1], 'v' => $v, 'aa' => $aa,
                       'var' => Cascada::variacion($v, $aa, $d[1])];
        }

        // ---- (b) estructura de costos por canal, % sobre la venta (1.9)
        $segmentos = [
            'REL_COSTO_VENTAS' => 'CMV', 'REL_COMERCIALIZACION' => 'Comercialización', 'REL_PERSONAL' => 'Personal',
            'REL_OCUPACION' => 'Ocupación', 'REL_OTROS_OPERATIVOS' => 'Otros operativos', 'REL_BIENES_USO' => 'Bienes de uso',
            'REL_ESTRUCTURA' => 'Estructura'
        ];
        $estructura = ['segmentos' => array_values($segmentos), 'canales' => []];

        foreach (['LOCALES', 'FRANQUICIAS', 'MAYORISTAS', 'ECOMMERCE', 'OTROS'] as $k) {
            $c = $res[$k][0]['calc'];
            $vals = [];

            foreach (array_keys($segmentos) as $s) {
                $vals[] = is_numeric($c[$s]) ? $c[$s] : null;
            }

            // El resultado, sobre 1.9 en TODOS los canales para que la barra
            // cierre en 100%. Por eso no es RENTABILIDAD_TOTAL, que en
            // LOCALES va sobre 1.5.
            $resultado = Formulas::div($c['RESULTADO_EXPLOTACION'], $c['V_1_9']);
            $estructura['canales'][] = ['clave' => $k, 'etiqueta' => Canales::ETIQUETAS[$k], 'valores' => $vals,
                                        'resultado' => is_numeric($resultado) ? $resultado : null];
        }

        // ---- (c) ranking de locales
        $umbrales = ParametrosIE::umbrales($this->cid);

        if ($umbrales === null) {
            $this->aviso('info', 'Falta correr sql/ie_umbrales.sql: el ranking se muestra sin colores y sin las alertas que dependen de los umbrales.');
        }

        $indicadores = [
            'RENTABILIDAD' => 'RENTABILIDAD_TOTAL', 'COSTO_MERCADERIA' => 'REL_COSTO_VENTAS',
            'COMERCIALIZACION' => 'REL_COMERCIALIZACION', 'PERSONAL' => 'REL_PERSONAL', 'OCUPACION' => 'REL_OCUPACION'
        ];
        $ranking = [];

        foreach ($locales as $col) {
            list($r, $rAA) = $res[$col['clave']];
            $fila = ['clave' => $col['clave'], 'nro' => $col['nro'], 'nombre' => $col['etiqueta'], 'cerrada' => $col['cerrada'],
                     'valores' => [], 'colores' => [], 'rojos' => 0,
                     'partTotal' => $r['calc']['PART_TOTAL'], 'partLocales' => $r['calc']['PART_LOCALES'],
                     'resultadoExplotacion' => $r['calc']['RESULTADO_EXPLOTACION']];

            foreach ($indicadores as $ind => $clave) {
                $v = $r['calc'][$clave];
                $fila['valores'][$ind] = $v;
                $color = $umbrales && isset($umbrales[$ind]) ? Semaforo::color($v, $umbrales[$ind]) : null;
                $fila['colores'][$ind] = $color;

                if ($color === 'ROJO') {
                    $fila['rojos']++;
                }
            }

            $fila['rentabilidadAA'] = $rAA['calc']['RENTABILIDAD_TOTAL'];
            $fila['deltaRentabilidad'] = Cascada::variacion($fila['valores']['RENTABILIDAD'], $fila['rentabilidadAA'], true);
            $ranking[] = $fila;
        }

        usort($ranking, function ($a, $b) {
            $x = is_numeric($a['valores']['RENTABILIDAD']) ? $a['valores']['RENTABILIDAD'] : -INF;
            $y = is_numeric($b['valores']['RENTABILIDAD']) ? $b['valores']['RENTABILIDAD'] : -INF;

            return $y <=> $x;
        });

        // ---- (d) alertas
        $caida = ParametrosIE::caidaPp($this->cid);

        if ($caida === null) {
            $this->aviso('info', 'Falta el parámetro de caída de rentabilidad (sql/ie_umbrales.sql): esa alerta no se evalúa.');
        }

        $alertas = [];

        foreach ($ranking as $l) {
            $link = ['tab' => 'locales', 'col' => $l['clave']];

            if (is_numeric($l['resultadoExplotacion']) && $l['resultadoExplotacion'] < 0) {
                $alertas[] = ['tipo' => 'negativo', 'texto' => $l['nombre'] . ': resultado de explotación negativo.', 'link' => $link];
            }

            if ($caida !== null && is_numeric($l['deltaRentabilidad']) && $l['deltaRentabilidad'] < -$caida['valor'] / 100) {
                $alertas[] = ['tipo' => 'caida', 'texto' => $l['nombre'] . ': la rentabilidad cayó ' . number_format(-$l['deltaRentabilidad'] * 100, 1, ',', '.')
                    . ' puntos contra el año anterior (el umbral es ' . $caida['valor'] . ').', 'link' => $link];
            }

            if ($l['rojos'] >= 2) {
                $alertas[] = ['tipo' => 'rojos', 'texto' => $l['nombre'] . ': ' . $l['rojos'] . ' indicadores en rojo.', 'link' => $link];
            }
        }

        foreach ($ctx['clasif']['sinSeccion'] as $cod) {
            $alertas[] = ['tipo' => 'sinSeccion', 'texto' => 'Rubro ' . $cod . ' sin sección: no suma a ningún total.', 'link' => ['tab' => 'parametros']];
        }

        return [
            'ok' => true,
            'vista' => 'dashboard',
            'moneda' => $ctx['moneda'],
            'tcc' => $ctx['tcc'],
            'periodos' => $ctx['periodos'],
            'periodosAA' => $ctx['periodosAA'],
            'kpis' => $kpis,
            'estructura' => $estructura,
            'ranking' => $ranking,
            'umbrales' => $umbrales,
            'caidaPp' => $caida ? $caida['valor'] : null,
            'alertas' => $alertas,
            'avisos' => $this->avisos
        ];
    }

    /** Importe para un aviso: '$ 1.234.567' */
    private static function fmt($v) {
        return '$ ' . number_format((float) $v, 0, ',', '.');
    }
}
