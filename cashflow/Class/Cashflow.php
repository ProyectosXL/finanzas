<?php

require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/CashflowRegistry.php';
require_once __DIR__ . '/CashflowEstructura.php';

/**
 * Cashflow
 * Motor de consolidacion del tablero de Flujo de Fondos.
 *
 * QUE HACE Y QUE NO
 * -----------------
 * Toma la estructura configurada (CashflowEstructura), le pide a cada modulo
 * sus importes a traves del contrato comun (CashflowProvider) y resuelve las
 * filas calculadas y el arrastre del saldo. No sabe como calcula sus numeros
 * ningun modulo, y no tiene ninguna fila, seccion ni categoria escrita en el
 * codigo: si el tablero tiene una fila de Haberes es porque esta configurada,
 * no porque el motor la conozca.
 *
 * Lo unico que el motor sabe es el SIGNIFICADO de los seis tipos de fila, que
 * es exactamente lo que reemplaza a un lenguaje de formulas.
 *
 * NUNCA DEJA LA PANTALLA EN BLANCO
 * --------------------------------
 * Un modulo que falla, un origen mal configurado o una tabla que no existe
 * producen ceros y un aviso, no una excepcion. El tablero consolida muchos
 * modulos: si uno pudiera tumbarlo, la pantalla seria inutilizable cada vez que
 * cualquier modulo tiene un problema.
 *
 * UNA LLAMADA POR MODULO
 * ----------------------
 * Las filas se agrupan por proveedor y a cada uno se le pide series() UNA vez,
 * aunque varias filas lo usen. Ventas, por ejemplo, lee el historico, los
 * indices, la participacion y el calendario bancario y recorre dia x canal x
 * medio de pago: llamarlo una vez por fila multiplicaria todo eso.
 */
class Cashflow {

    /** @var Parametros */
    private $parametros;

    /** @var CashflowEstructura */
    private $estructura;

    /** @var Horizonte|null Eje inyectado. null = se arma desde los parametros */
    private $horizonte;

    /** @var array Avisos no fatales que se devuelven en el JSON */
    private $warnings = [];

    /** Arrastre resuelto, indexado por columna. Lo llena resolverDerivadas(). */
    private $apertura = [];
    private $aporte = [];
    private $cierre = [];

    /**
     * Las dependencias se pueden inyectar para poder probar el arrastre del
     * saldo y las filas calculadas con una estructura controlada, sin depender
     * de lo que haya cargado en las tablas. En uso normal no se pasa nada.
     *
     * EL EJE TAMBIEN SE PUEDE INYECTAR, y hace falta para poder probar. El
     * arrastre depende de QUE DIA ES HOY: el eje arranca hoy, asi que un
     * escenario con importes en fechas fijas deja de tener sentido en cuanto
     * pasa esa fecha. Sin esta costura las pruebas del motor caducaban solas -y
     * de hecho caducaron-, con lo cual la parte mas delicada del modulo se
     * quedaba sin red justo cuando mas se la necesita.
     *
     * Es la misma costura que ya tienen Ventas::proyectarVentas() y
     * proyectarCobranzas(), que aceptan un Horizonte opcional para que el
     * Cashflow pueda consolidarlas sobre sus mismas columnas.
     *
     * @param CashflowEstructura|null $estructura
     * @param Parametros|null $parametros
     * @param Horizonte|null $horizonte Eje a usar. null lo arma desde los
     *        parametros, que es el uso normal.
     */
    function __construct($estructura = null, $parametros = null, $horizonte = null) {
        $this->parametros = ($parametros === null) ? new Parametros() : $parametros;
        $this->estructura = ($estructura === null) ? new CashflowEstructura() : $estructura;
        $this->horizonte = ($horizonte instanceof Horizonte) ? $horizonte : null;
    }

    /**
     * Tablero completo, listo para dibujar.
     *
     * @return array Estructura descrita en README-cashflow.md
     */
    public function proyectar() {
        $this->warnings = [];

        /* ---- 1. Eje temporal --------------------------------------------- */
        // Si lo inyectaron, manda el inyectado: es lo que permite fijar el dia
        // de referencia en las pruebas. En uso normal sale de los parametros.
        $h = ($this->horizonte !== null)
            ? $this->horizonte
            : Horizonte::desdeParametros($this->parametros);

        /* ---- 2. Estructura configurada ----------------------------------- */
        foreach ($this->estructura->getAvisos() as $aviso) {
            $this->warnings[] = $aviso;
        }

        $conf = $this->estructura->getEstructura(true);
        $secciones = $conf['secciones'];
        $filas = $conf['filas'];

        // La configuracion se valida pero NO se bloquea el tablero: un error de
        // configuracion se muestra como aviso y las filas afectadas van en
        // cero. El editor de Parametros es el que impide guardar algo invalido.
        //
        // Se reenvian solo los ERRORES. Las advertencias del validador son
        // para el editor, que las muestra por fila y en su contexto; aca serian
        // ruido duplicado, porque el motor ya emite sus propios avisos
        // operativos y agrupados (los modulos sin construir, por ejemplo, van
        // en un unico aviso y no en uno por fila).
        $val = CashflowEstructura::validar($secciones, $filas);

        foreach ($val['errores'] as $e) {
            $this->warnings[] = 'Configuración: ' . $e;
        }

        /* ---- 3. Series de los modulos ------------------------------------ */
        $series = $this->pedirSeries($h, $filas);

        /* ---- 4. Valores por fila ----------------------------------------- */
        $resueltas = $this->resolverOrigenes($h, $filas, $series);

        /* ---- 5. Filas calculadas y arrastre del saldo -------------------- */
        $this->resolverDerivadas($h, $secciones, $resueltas);

        $this->avisarAperturaEnCero($resueltas);

        /* ---- 6. Totales -------------------------------------------------- */
        $columnas = $h->secuencia();
        $ultimaDia = $this->ultimaColumnaDiaria($columnas);
        $ultima = empty($columnas) ? null : $columnas[count($columnas) - 1];

        foreach ($resueltas as &$f) {
            $this->calcularTotales($h, $f, $ultimaDia, $ultima);
        }

        unset($f);

        /* ---- 7. Salida --------------------------------------------------- */
        return [
            'generado' => $h->hoy(),
            'horizonte_dias' => $h->cantidadDias(),
            'horizonte_meses' => $h->cantidadMeses(),
            'dias' => $this->ejeDias($h),
            'meses' => $this->ejeMeses($h),
            'secuencia' => $columnas,
            'secciones' => $this->salidaSecciones($secciones),
            'filas' => $this->salidaFilas($resueltas),
            'kpi' => $this->calcularKpi($h, $resueltas),
            'warnings' => $this->warnings
        ];
    }

    /* ====================================================================
       ORIGENES DE DATOS
       ==================================================================== */

    /**
     * Pide las series a cada proveedor que alguna fila necesite. Una llamada
     * por proveedor, no por fila.
     *
     * Es protected para poder sustituirla en las pruebas por series fijas y
     * verificar el arrastre con numeros conocidos.
     *
     * @param Horizonte $h
     * @param array $filas Filas configuradas activas
     * @return array Mapa codigoProveedor => (codigoSerie => serie)
     */
    protected function pedirSeries($h, $filas) {
        $necesarios = [];

        foreach ($filas as $f) {
            if (in_array($f['TIPO'], CashflowEstructura::TIPOS_CON_ORIGEN, true)
                && !empty($f['ORIGEN_PROVIDER'])) {
                $necesarios[$f['ORIGEN_PROVIDER']] = true;
            }
        }

        $series = [];
        $sinConstruir = [];

        foreach (array_keys($necesarios) as $codigo) {
            $meta = CashflowRegistry::meta($codigo);

            if ($meta === null) {
                $this->warnings[] = 'El módulo "' . $codigo . '" no está registrado como origen '
                    . 'de datos: las filas que lo usan se muestran en cero.';
                continue;
            }

            if (empty($meta['disponible'])) {
                $sinConstruir[] = $meta['nombre'];
                continue;
            }

            $prov = CashflowRegistry::instanciar($codigo);

            if ($prov === null) {
                $this->warnings[] = 'No se pudo cargar el módulo ' . $meta['nombre']
                    . ': sus filas se muestran en cero.';
                continue;
            }

            // series() ya garantiza no lanzar; el try es defensa en profundidad
            // por si una subclase futura rompe el contrato de otra forma.
            try {
                $series[$codigo] = $prov->series($h);

                foreach ($prov->warnings() as $w) {
                    $this->warnings[] = $w;
                }
            } catch (Throwable $e) {
                $this->warnings[] = 'El módulo ' . $meta['nombre'] . ' no pudo calcularse ('
                    . $e->getMessage() . '): sus filas se muestran en cero.';
            }
        }

        // Un aviso agrupado en lugar de uno por fila: son doce modulos y la
        // lista suelta taparia los avisos que si requieren accion.
        if (!empty($sinConstruir)) {
            sort($sinConstruir);

            $this->warnings[] = 'Estos módulos todavía no están construidos y sus filas se '
                . 'muestran en cero: ' . implode(', ', $sinConstruir) . '.';
        }

        return $series;
    }

    /**
     * Convierte cada fila configurada en una fila con valores.
     *
     * Las filas con origen toman los importes del proveedor tal como vienen (el
     * signo lo aporta el TIPO, no el importe). Las derivadas quedan en cero y
     * las resuelve resolverDerivadas().
     *
     * @param Horizonte $h
     * @param array $filas
     * @param array $series
     * @return array Filas con 'dias', 'meses' y metadatos
     */
    private function resolverOrigenes($h, $filas, $series) {
        $vacia = $h->serieVacia();
        $resueltas = [];

        foreach ($filas as $f) {
            $tipo = $f['TIPO'];

            $fila = [
                'id' => $f['ID'],
                'codigo' => $f['CODIGO'],
                'nombre' => $f['NOMBRE'],
                'seccion' => $f['SECCION'],
                'orden' => $f['ORDEN'],
                'tipo' => $tipo,
                'signo' => CashflowEstructura::signo($tipo),
                'computa' => (intval($f['COMPUTA']) === 1),
                'derivada' => CashflowEstructura::esDerivada($tipo),
                'es_saldo' => CashflowEstructura::esSaldo($tipo),
                // Si lo que la fila MUESTRA lo calcula el arrastre y no su
                // proveedor. Lo marca resolverDerivadas(). Sin esto, una fila
                // de saldo sin modulo de origen aparece con numeros y con el
                // icono de "sin datos" al mismo tiempo, que se contradicen.
                'arrastre' => false,
                'origen' => null,
                'tab' => null,
                // Sub-pestana dentro de 'tab', para los modulos que tienen mas
                // de una vista. Sin esto el enlace del tablero abre la pestana
                // en su primera vista, que puede no ser la que produjo el numero.
                'subtab' => null,
                'moneda_origen' => null,
                'tipo_cambio' => null,
                'dias' => $vacia['dias'],
                'meses' => $vacia['meses'],
                'fuera_horizonte' => 0,
                'sin_datos' => false
            ];

            if (!in_array($tipo, CashflowEstructura::TIPOS_CON_ORIGEN, true)) {
                $resueltas[] = $fila;
                continue;
            }

            $prov = $f['ORIGEN_PROVIDER'];
            $serie = $f['ORIGEN_SERIE'];

            if (empty($prov) || empty($serie)) {
                $fila['sin_datos'] = true;
                $resueltas[] = $fila;
                continue;
            }

            $fila['origen'] = $prov . '|' . $serie;

            $meta = CashflowRegistry::meta($prov);

            if ($meta !== null) {
                $fila['tab'] = isset($meta['tab']) ? $meta['tab'] : null;
                $fila['subtab'] = isset($meta['subtab']) ? $meta['subtab'] : null;
            }

            if (!isset($series[$prov][$serie])) {
                // El proveedor no existe, no esta construido o fallo. El aviso
                // ya lo dejo pedirSeries(); aca solo se marca la fila.
                $fila['sin_datos'] = true;
                $resueltas[] = $fila;
                continue;
            }

            $s = $series[$prov][$serie];

            $fila['dias'] = $s['dias'];
            $fila['meses'] = $s['meses'];
            $fila['moneda_origen'] = $s['moneda_origen'];
            $fila['tipo_cambio'] = $s['tipo_cambio'];
            $fila['fuera_horizonte'] = $s['fuera_horizonte'];

            foreach ($s['warnings'] as $w) {
                $this->warnings[] = $w;
            }

            $this->avisarDescartes($fila['nombre'], $s);

            $resueltas[] = $fila;
        }

        return $resueltas;
    }

    /**
     * Avisa cuando el horizonte arranca sin saldo de apertura.
     *
     * Es el aviso mas importante del tablero mientras no exista el modulo de
     * Saldos: sin el, los saldos que se ven no son plata en el banco sino la
     * caja que se va acumulando con los ingresos proyectados. Leer esos numeros
     * como disponibilidad real seria un error caro.
     *
     * @param array $resueltas
     */
    private function avisarAperturaEnCero($resueltas) {
        foreach ($resueltas as $f) {
            if ($f['tipo'] !== 'SALDO_INICIAL') {
                continue;
            }

            if (!$f['sin_datos']) {
                continue;
            }

            $this->warnings[] = 'La fila "' . $f['nombre'] . '" se muestra en CERO porque el '
                . 'módulo que la alimenta todavía no está desarrollado. Como no hay saldo de '
                . 'apertura, el Saldo Final arranca de cero: muestra la caja que generan los '
                . 'ingresos proyectados, no la posición real de los bancos.';

            return;
        }
    }

    /**
     * Avisa de los importes que no entraron en el tablero. Se informa siempre:
     * un tablero de consolidacion que muestra de menos sin decirlo es peor que
     * uno que falla.
     *
     * @param string $nombreFila
     * @param array $s Serie normalizada
     */
    private function avisarDescartes($nombreFila, $s) {
        if (!empty($s['fuera_horizonte'])) {
            $this->warnings[] = $nombreFila . ': ' . $this->plata($s['fuera_horizonte'])
                . ' con fecha fuera del horizonte proyectado no se muestran en el tablero.';
        }

        if (!empty($s['sin_fecha'])) {
            $this->warnings[] = $nombreFila . ': ' . $this->plata($s['sin_fecha'])
                . ' sin fecha asignada no se pueden ubicar en el tablero.';
        }
    }

    /* ====================================================================
       FILAS CALCULADAS Y ARRASTRE DEL SALDO
       ==================================================================== */

    /**
     * Resuelve subtotales, flujo neto, saldo inicial y saldo final.
     *
     * EL ORDEN DE LAS COLUMNAS NO ES EL ORDEN DEL TIEMPO, y por eso el arrastre
     * recorre Horizonte::secuencia() y no las columnas como se dibujan. Ver la
     * explicacion en Class/Horizonte.php.
     *
     * Las columnas que no representan ningun dia futuro (la del mes en curso
     * cuando el tramo diario arranca hoy) reciben null en las filas que
     * dependen del arrastre, y NO cero: un cero en Saldo Final se leeria como
     * "proyectamos cero pesos de caja", que es mentira.
     *
     * @param Horizonte $h
     * @param array $secciones
     * @param array $resueltas Por referencia
     */
    private function resolverDerivadas($h, $secciones, &$resueltas) {
        $columnas = $h->secuencia();
        $enSecuencia = array_fill_keys($columnas, true);
        $todas = $this->todasLasColumnas($h);

        /* ---- 1. Arrastre del saldo --------------------------------------- */
        // Va PRIMERO porque los subtotales pueden abarcar la fila de saldo
        // inicial, y para eso necesitan que su valor ya este resuelto.
        //
        // El arrastre usa el flujo COMPLETO de cada columna: es el movimiento
        // real de caja del periodo, independiente de donde esten puestas las
        // filas de resultado.
        $saldo = 0;
        $apertura = [];
        $cierre = [];
        $aporte = [];

        foreach ($columnas as $col) {
            $flujoTotal = $this->sumarMovimientos($resueltas, $col, null, null);
            $aporte[$col] = $this->sumarAporteSaldo($resueltas, $col);

            $apertura[$col] = $saldo;
            $saldo += $aporte[$col] + $flujoTotal;
            $cierre[$col] = $saldo;
        }

        /* ---- 2. Subtotales ----------------------------------------------- */
        // Alcance: su seccion y las secciones hijas, sin limite posicional (un
        // subtotal abarca toda su seccion, este donde este puesto dentro de
        // ella).
        //
        // Suman los movimientos Y las filas de saldo inicial que caigan en su
        // alcance. Eso es lo que reproduce el "Disponible" del Excel, que es el
        // saldo en bancos mas las cobranzas del dia, todo en una seccion.
        //
        // La fila de saldo NO se toca: muestra lo que devolvio su modulo de
        // origen. Es una fila de DATOS, no un calculo (ver la nota del paso 3).
        foreach ($resueltas as $i => $f) {
            if ($f['tipo'] !== 'SUBTOTAL') {
                continue;
            }

            $alcance = array_fill_keys(
                CashflowEstructura::descendientes($secciones, $f['seccion']),
                true
            );

            foreach ($todas as $col) {
                $valor = $this->sumarMovimientos($resueltas, $col, $alcance, null)
                    + $this->sumarSaldoMostrado($resueltas, $col, $alcance);

                $resueltas[$i] = $this->ponerValor($resueltas[$i], $col, $valor);
            }
        }

        /* ---- 3. Flujo neto y saldo final --------------------------------- */
        // La fila SALDO_INICIAL es una fila de DATOS: muestra lo que devuelve su
        // modulo de origen (la pestana Saldos) y nada mas. Antes mostraba el
        // arrastre, y eso estaba mal: una fila alimentada por un modulo que
        // todavia no existe tiene que verse en cero, no con la caja acumulada.
        // El Excel lo confirma, el 1/9 tiene Saldo Inicial en cero justo despues
        // de un Disponible de 118 millones: si fuera arrastre ahi habria 118
        // millones.
        //
        // El arrastre sigue existiendo, pero solo lo muestra SALDO_FINAL, que es
        // la posicion proyectada. Y los saldos que cargue el modulo de Saldos
        // entran a ese arrastre como aporte, asi que cuando exista, la posicion
        // arranca del dinero real.
        // Suman lo que esta POR ENCIMA de ellas. Con una sola fila de resultado
        // al final del cuadro eso equivale al total, pero es lo que permite
        // poner un resultado intermedio (por ejemplo un "Resultado Operativo"
        // antes de los ajustes) desde la configuracion y que de bien.
        foreach ($resueltas as $i => $f) {
            if ($f['tipo'] !== 'FLUJO_NETO' && $f['tipo'] !== 'SALDO_FINAL') {
                continue;
            }

            $mapa = [];

            foreach ($columnas as $col) {
                $hasta = $this->sumarMovimientos($resueltas, $col, null, $i);

                $mapa[$col] = ($f['tipo'] === 'FLUJO_NETO')
                    ? $hasta
                    : $apertura[$col] + $aporte[$col] + $hasta;
            }

            $resueltas[$i] = $this->volcar($resueltas[$i], $todas, $mapa, $enSecuencia);

            if ($f['tipo'] === 'SALDO_FINAL') {
                $resueltas[$i]['arrastre'] = true;
            }
        }

        $this->apertura = $apertura;
        $this->aporte = $aporte;
        $this->cierre = $cierre;

        /* ---- Chequeo del invariante -------------------------------------- */
        // El cierre de una columna tiene que ser la apertura de la siguiente. Si
        // no da, es un aviso y no una excepcion: mejor un tablero con una nota
        // que ninguno.
        for ($i = 0; $i < count($columnas) - 1; $i++) {
            $a = $cierre[$columnas[$i]];
            $b = $apertura[$columnas[$i + 1]];

            if (abs($a - $b) > 0.01) {
                $this->warnings[] = 'El arrastre del saldo no cierra entre '
                    . $this->rotulo($columnas[$i]) . ' y ' . $this->rotulo($columnas[$i + 1]) . '.';
                break;
            }
        }
    }

    /**
     * Suma el flujo de una columna: las filas de movimiento con COMPUTA = 1.
     *
     * EL ROL DE LA SECCION NO PARTICIPA. Antes se exigia que la seccion fuera
     * ROL='MOVIMIENTO', y eso impedia armar la estructura del Excel, donde la
     * seccion de disponibilidades contiene a la vez la fila de saldo en bancos y
     * las filas de cobranzas. Quien decide como participa una fila es su TIPO;
     * el ROL de la seccion quedo sólo para agrupar y para los avisos del
     * validador.
     *
     * @param array $resueltas
     * @param string $col Id de columna
     * @param array|null $alcance Codigos de seccion a considerar, o null para todas
     * @param int|null $limite Indice tope: solo las filas ANTERIORES a esa
     *        posicion. null para no limitar. Es lo que hace posicional el
     *        alcance de FLUJO_NETO y SALDO_FINAL.
     * @return float
     */
    private function sumarMovimientos($resueltas, $col, $alcance, $limite = null) {
        $total = 0;

        foreach ($resueltas as $pos => $f) {
            if ($limite !== null && $pos >= $limite) {
                break;
            }

            if (!in_array($f['tipo'], CashflowEstructura::TIPOS_MOVIMIENTO, true)) {
                continue;
            }

            if (!$f['computa']) {
                continue;
            }

            if ($alcance !== null && !isset($alcance[$f['seccion']])) {
                continue;
            }

            $total += $f['signo'] * $this->valor($f, $col);
        }

        return $total;
    }

    /**
     * Aporte de saldo de una columna: lo que devolvieron los PROVEEDORES de las
     * filas SALDO_INICIAL, que es distinto de lo que la fila termina mostrando.
     *
     * @param array $resueltas
     * @param string $col
     * @return float
     */
    private function sumarAporteSaldo($resueltas, $col) {
        $total = 0;

        foreach ($resueltas as $f) {
            if ($f['tipo'] === 'SALDO_INICIAL') {
                $total += $this->valor($f, $col);
            }
        }

        return $total;
    }

    /**
     * Suma lo que MUESTRAN las filas de saldo inicial de un alcance. Se usa para
     * los subtotales, que en el Excel incluyen el saldo en bancos.
     *
     * @param array $resueltas
     * @param string $col
     * @param array $alcance
     * @return float
     */
    private function sumarSaldoMostrado($resueltas, $col, $alcance) {
        $total = 0;

        foreach ($resueltas as $f) {
            if ($f['tipo'] !== 'SALDO_INICIAL') {
                continue;
            }

            if (!isset($alcance[$f['seccion']])) {
                continue;
            }

            $total += $this->valor($f, $col);
        }

        return $total;
    }

    /* ====================================================================
       TOTALES Y KPI
       ==================================================================== */

    /**
     * Totales de una fila.
     *
     * Para las filas de saldo NO son sumas: sumar saldos de apertura o de
     * cierre no significa nada. El total del tramo es el saldo al final del
     * tramo y el del horizonte el saldo al final de todo.
     *
     * @param Horizonte $h
     * @param array $f Por referencia
     * @param string|null $ultimaDia Ultima columna diaria de la secuencia
     * @param string|null $ultima Ultima columna de la secuencia
     */
    private function calcularTotales($h, &$f, $ultimaDia, $ultima) {
        if ($f['es_saldo']) {
            if ($f['tipo'] === 'SALDO_INICIAL') {
                // La apertura del horizonte: con que saldo se arranca. Es el
                // mismo numero en las tres vistas.
                $columnas = $h->secuencia();
                $primera = empty($columnas) ? null : $columnas[0];

                $f['total_tramo'] = $this->valor($f, $primera);
                $f['total_meses'] = $f['total_tramo'];
                $f['total_horizonte'] = $f['total_tramo'];
            } else {
                $f['total_tramo'] = $this->valor($f, $ultimaDia);
                $f['total_meses'] = $this->valor($f, $ultima);
                $f['total_horizonte'] = $this->valor($f, $ultima);
            }

            return;
        }

        $f['total_tramo'] = array_sum(array_map('floatval', $f['dias']));
        $f['total_meses'] = array_sum(array_map('floatval', $f['meses']));
        $f['total_horizonte'] = $f['total_tramo'] + $f['total_meses'];
    }

    /**
     * Indicadores de cabecera, UNO POR VISTA.
     *
     * Los indicadores tienen que medir exactamente las columnas que se estan
     * mirando: si la pantalla muestra los meses, un indicador calculado sobre el
     * tramo diario no describe nada de lo que hay en pantalla.
     *
     *   'dias'     -> las columnas diarias
     *   'meses'    -> las columnas mensuales (que acumulan sólo los dias fuera
     *                 del tramo, asi que es un tramo distinto y no el total)
     *   'completo' -> todas
     *
     * El saldo de apertura es el UNICO que no varia: es con cuanto se arranca
     * hoy, un hecho del presente y no del periodo que se elige mirar.
     *
     * @param Horizonte $h
     * @param array $resueltas
     * @return array Mapa vista => indicadores
     */
    private function calcularKpi($h, $resueltas) {
        $secuencia = $h->secuencia();
        $enSecuencia = array_fill_keys($secuencia, true);

        $colsDias = [];
        $colsMeses = [];

        // Solo las columnas que representan dias futuros: las otras no aportan
        // nada y ademas tienen null.
        foreach ($h->dias() as $d) {
            if (isset($enSecuencia['DIA|' . $d['fecha']])) {
                $colsDias[] = 'DIA|' . $d['fecha'];
            }
        }

        foreach ($h->meses() as $m) {
            if (isset($enSecuencia['MES|' . $m['clave']])) {
                $colsMeses[] = 'MES|' . $m['clave'];
            }
        }

        $apertura = $this->aperturaHorizonte($resueltas, $secuencia);

        return [
            'dias' => $this->kpiDe($resueltas, $colsDias, $apertura, 'dias'),
            'meses' => $this->kpiDe($resueltas, $colsMeses, $apertura, 'meses'),
            'completo' => $this->kpiDe($resueltas, array_merge($colsDias, $colsMeses),
                $apertura, 'completo')
        ];
    }

    /** Con cuanto arranca el horizonte. No depende de la vista. */
    private function aperturaHorizonte($resueltas, $secuencia) {
        $primera = empty($secuencia) ? null : $secuencia[0];

        foreach ($resueltas as $f) {
            if ($f['tipo'] === 'SALDO_INICIAL') {
                return $this->valor($f, $primera);
            }
        }

        return 0;
    }

    /**
     * Indicadores de un conjunto de columnas.
     *
     * @param array $resueltas
     * @param array $cols Columnas del periodo, en orden cronologico
     * @param float $apertura Saldo con el que arranca el horizonte
     * @param string $vista Para el rotulo
     * @return array
     */
    private function kpiDe($resueltas, $cols, $apertura, $vista) {
        $kpi = [
            'saldo_apertura' => $apertura,
            'ingresos' => 0,
            'egresos' => 0,
            'flujo' => 0,
            'saldo_cierre' => 0,
            'minimo' => null,
            'periodo' => $this->rotuloPeriodo($cols, $vista),
            'columnas' => count($cols)
        ];

        if (empty($cols)) {
            return $kpi;
        }

        $ultima = $cols[count($cols) - 1];

        foreach ($resueltas as $f) {
            if ($f['tipo'] === 'SALDO_FINAL') {
                $kpi['saldo_cierre'] = $this->valor($f, $ultima);

                // El peor saldo proyectado del periodo y cuando ocurre. Es el
                // dato mas util de un tablero de tesoreria y sale gratis del
                // arrastre.
                foreach ($cols as $col) {
                    $v = $this->valor($f, $col);

                    if ($kpi['minimo'] === null || $v < $kpi['minimo']['valor']) {
                        $kpi['minimo'] = [
                            'columna' => $col,
                            'label' => $this->rotulo($col),
                            'valor' => $v
                        ];
                    }
                }

                continue;
            }

            if (!$f['computa'] || !in_array($f['tipo'], CashflowEstructura::TIPOS_MOVIMIENTO, true)) {
                continue;
            }

            $suma = 0;

            foreach ($cols as $col) {
                $suma += $this->valor($f, $col);
            }

            // Se acumulan como MAGNITUDES positivas, que es como se leen en una
            // tarjeta ("Egresos: $ 289 M"). El signo lo pone el flujo.
            if ($f['tipo'] === 'INGRESO') {
                $kpi['ingresos'] += $suma;
            } else {
                $kpi['egresos'] += $suma;
            }
        }

        $kpi['flujo'] = $kpi['ingresos'] - $kpi['egresos'];

        return $kpi;
    }

    /**
     * Rotulo del periodo que cubre un conjunto de columnas, para que el
     * indicador diga sobre que esta midiendo.
     *
     * @param array $cols
     * @param string $vista
     * @return string
     */
    private function rotuloPeriodo($cols, $vista) {
        if (empty($cols)) {
            return 'Sin columnas';
        }

        $desde = $this->rotulo($cols[0]);
        $hasta = $this->rotulo($cols[count($cols) - 1]);

        if ($vista === 'dias') {
            return 'Del ' . $desde . ' al ' . $hasta;
        }

        if ($vista === 'meses') {
            // Se aclara que el tramo mensual arranca DESPUES del diario: la
            // primera columna mensual acumula sólo los dias del mes que quedan
            // fuera del tramo, asi que este numero no es el del horizonte
            // completo.
            return 'De ' . $desde . ' a ' . $hasta . ', despues del tramo diario';
        }

        return 'Del ' . $desde . ' a ' . $hasta . ', todo el horizonte';
    }

    /* ====================================================================
       AYUDANTES
       ==================================================================== */

    /** Todas las columnas del eje, en el orden en que se dibujan */
    private function todasLasColumnas($h) {
        $cols = [];

        foreach ($h->dias() as $d) {
            $cols[] = 'DIA|' . $d['fecha'];
        }

        foreach ($h->meses() as $m) {
            $cols[] = 'MES|' . $m['clave'];
        }

        return $cols;
    }

    /** Ultima columna DIA de la secuencia, o null si el eje no tiene tramo diario */
    private function ultimaColumnaDiaria($columnas) {
        $ultima = null;

        foreach ($columnas as $col) {
            if (strpos($col, 'DIA|') === 0) {
                $ultima = $col;
            }
        }

        return $ultima;
    }

    /**
     * Valor de una fila en una columna. Devuelve 0 si la columna no existe o
     * tiene null, para que quien suma no tenga que preguntar.
     */
    private function valor($f, $col) {
        if ($col === null) {
            return 0;
        }

        list($rama, $clave) = $this->partir($col);

        return isset($f[$rama][$clave]) ? floatval($f[$rama][$clave]) : 0;
    }

    /** Escribe un valor en la columna indicada */
    private function ponerValor($f, $col, $valor) {
        list($rama, $clave) = $this->partir($col);

        if (array_key_exists($clave, $f[$rama])) {
            $f[$rama][$clave] = $valor;
        }

        return $f;
    }

    /**
     * Vuelca un mapa columna => valor sobre una fila, poniendo null en las
     * columnas que no representan dias futuros.
     */
    private function volcar($f, $todas, $mapa, $enSecuencia) {
        foreach ($todas as $col) {
            $valor = isset($enSecuencia[$col])
                ? (isset($mapa[$col]) ? $mapa[$col] : 0)
                : null;

            $f = $this->ponerValor($f, $col, $valor);
        }

        return $f;
    }

    /** 'DIA|2026-09-06' => ['dias', '2026-09-06'] */
    private function partir($col) {
        if (strpos($col, 'DIA|') === 0) {
            return ['dias', substr($col, 4)];
        }

        return ['meses', substr($col, 4)];
    }

    /** Rotulo legible de una columna, para los mensajes */
    private function rotulo($col) {
        list($rama, $clave) = $this->partir($col);

        if ($rama === 'dias') {
            $t = strtotime($clave);

            return intval(date('j', $t)) . '/' . intval(date('n', $t));
        }

        $partes = explode('-', $clave);

        return Horizonte::labelMes(intval($partes[0]), intval($partes[1]));
    }

    /** Formato de importe para los mensajes de aviso */
    private function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** Eje de dias con la marca de si entra en la secuencia cronologica */
    private function ejeDias($h) {
        $enSecuencia = array_fill_keys($h->secuencia(), true);
        $v = [];

        foreach ($h->dias() as $d) {
            $d['en_secuencia'] = isset($enSecuencia['DIA|' . $d['fecha']]);
            $v[] = $d;
        }

        return $v;
    }

    /**
     * Eje de meses. 'parcial' marca los meses cuya columna acumula solo una
     * parte del mes, porque el resto de sus dias esta en el tramo diario.
     */
    private function ejeMeses($h) {
        $enSecuencia = array_fill_keys($h->secuencia(), true);
        $diasPorMes = [];

        foreach ($h->dias() as $d) {
            if (!isset($diasPorMes[$d['mes_clave']])) {
                $diasPorMes[$d['mes_clave']] = 0;
            }

            $diasPorMes[$d['mes_clave']]++;
        }

        $v = [];

        foreach ($h->meses() as $m) {
            $m['en_secuencia'] = isset($enSecuencia['MES|' . $m['clave']]);
            $m['parcial'] = isset($diasPorMes[$m['clave']]);
            $v[] = $m;
        }

        return $v;
    }

    /** Secciones en el formato del JSON */
    private function salidaSecciones($secciones) {
        $v = [];

        foreach ($secciones as $s) {
            $v[] = [
                'codigo' => $s['CODIGO'],
                'nombre' => $s['NOMBRE'],
                'rol' => $s['ROL'],
                'orden' => $s['ORDEN'],
                'id_padre' => $s['ID_PADRE']
            ];
        }

        return $v;
    }

    /** Filas en el formato del JSON, ya ordenadas por seccion y orden */
    private function salidaFilas($resueltas) {
        return array_values($resueltas);
    }
}
