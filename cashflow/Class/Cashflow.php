<?php

require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/EjeVista.php';
require_once __DIR__ . '/CashflowRegistry.php';
require_once __DIR__ . '/CashflowEstructura.php';
require_once __DIR__ . '/CoberturaAutomatica.php';

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
     * Lo que calculo CoberturaAutomatica en este pedido, o null si no habia
     * ningun fondo del que rescatar. Lo llena resolverUsoCobertura() y lo lee
     * resolverCobertura() para armar el resumen por fondo y los avisos.
     */
    private $automatico = null;

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

        /* ---- 6b. Cuanto queda de cobertura ------------------------------- */
        // Va DESPUES de los totales porque necesita el total de las dos filas:
        // el stock ya no esta en ninguna columna a esta altura.
        $this->resolverCobertura($resueltas);

        /* ---- 7. Salida --------------------------------------------------- */
        // El eje y las tres vistas los describe EjeVista, que es el criterio
        // compartido con las pestanas de detalle: el tablero y la pestana que
        // explica una de sus filas no pueden describir el eje de dos formas
        // distintas ni medir periodos distintos.
        return array_merge(EjeVista::eje($h), [
            'secciones' => $this->salidaSecciones($secciones),
            'filas' => $this->salidaFilas($resueltas),
            'kpi' => $this->calcularKpi($h, $resueltas),
            'warnings' => $this->warnings
        ]);
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

            /* UN MODULO RETIRADO SIGUE SIRVIENDO, PERO SE AVISA. Retirado no es
               "sin construir": el proveedor existe y lee su tabla, solo que ese
               dato ya no se mantiene porque lo reemplazo otro circuito. Una
               fila que siga apuntando ahi muestra el ultimo numero que se
               cargo, que puede ser viejo, y sin el aviso se leeria como de
               hoy. Es lo que pasa con Otros Ingresos desde que el stock sale
               de las cuentas de fondo. */
            if (!empty($meta['retirado'])) {
                $this->warnings[] = 'El módulo ' . $meta['nombre'] . ' está retirado: '
                    . $meta['retirado'] . ' Las filas que todavía lo usan muestran lo último '
                    . 'que se cargó ahí, que ya no se mantiene.';
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
                /* AGRUPAMIENTO: se pasan tal cual y el motor no hace NADA con
                   ellos. Las filas de un grupo calculan, computan y entran en
                   los subtotales por separado, exactamente como si el grupo no
                   existiera; la fila agrupada la arma el front sumando lo que
                   dibuja. Si algún día el motor empieza a mirar estos campos,
                   dejó de ser presentación. Ver CashflowEstructura::grupos(). */
                'grupo' => isset($f['GRUPO']) && $f['GRUPO'] !== '' ? $f['GRUPO'] : null,
                'naturaleza' => isset($f['NATURALEZA']) && $f['NATURALEZA'] !== ''
                    ? $f['NATURALEZA'] : null,
                'grupo_nombre' => isset($f['GRUPO_NOMBRE']) && $f['GRUPO_NOMBRE'] !== ''
                    ? $f['GRUPO_NOMBRE'] : null,
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
                // Anotaciones por columna que deja el proveedor: que parte del
                // importe de una celda tiene algo que contar. NO es un importe
                // mas y no entra en ninguna suma; ver CashflowProvider.
                'detalle' => [],
                // Cuanto hay invertido, cuanto se aplico y cuanto queda. Solo lo
                // llevan las filas de Cobertura; en el resto queda en null,
                // que es distinto de un bloque con ceros. Ver resolverCobertura().
                'cobertura' => null,
                // Solo en las filas de USO: que fondos aplica esta fila, y en
                // cada columna cuanto es cargado a mano y cuanto calculado por
                // el motor. Es lo que le permite al front distinguir los dos
                // sin volver a calcular nada. Ver resolverUsoCobertura().
                'fondos_fila' => [],
                'cobertura_columnas' => [],
                // Y el total de la fila sobre el horizonte, partido en los
                // dos: es lo que la celda de Concepto muestra al lado del
                // nombre. Lo suma el motor, no el front.
                'cobertura_totales' => null,
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

            /* COMO SE REPARTE ESTA SERIE ENTRE LOS FONDOS DE COBERTURA. En una
               fila de stock es cuanto aporta cada cuenta de fondo; en la de
               uso, cuanto se aplico desde cada una. Viaja con la serie y no
               sale del registro: los fondos son cuentas que da de alta el
               usuario, asi que ninguna constante del codigo puede decir cual
               es cual. resolverCobertura() lo cruza por la clave sin consultar
               la base: el motor arma el cuadro con lo que los proveedores le
               dieron. */
            $fila['por_fondo'] = isset($s['por_fondo']) ? $s['por_fondo'] : [];
            $fila['fondos'] = isset($s['fondos']) ? $s['fondos'] : [];

            /* Y LO QUE NECESITA LA COBERTURA AUTOMATICA: el saldo de cada fondo
               columna por columna (en la serie de stock) y lo cargado a mano
               desde cada uno (en la de uso). Mismo criterio: viajan con la
               serie, el motor no consulta. No salen en el JSON del tablero -son
               entradas del calculo, no resultado-; ver salidaFilas(). */
            $fila['fondos_tope'] = isset($s['fondos_tope']) ? $s['fondos_tope'] : [];
            $fila['fondos_manual'] = isset($s['fondos_manual']) ? $s['fondos_manual'] : [];

            /* Las anotaciones viajan tal cual. Las filas DERIVADAS -subtotales,
               flujo neto, saldo final- no las heredan y se quedan con el arreglo
               vacio: una anotacion dice algo sobre el origen de un importe, y el
               de un subtotal es la suma de varias filas, no ese origen.

               EL isset() VA POR EL MISMO MOTIVO QUE EN LAS SEIS LINEAS DE ARRIBA, y
               era la unica de las siete que no lo tenia. En produccion la clave
               siempre esta -CashflowProvider::normalizar() la crea en toda serie-
               pero quien reemplaza pedirSeries() para probar el motor inyecta series
               armadas a mano, y ahi faltaba: eran 134 warnings por corrida de la
               suite. Un warning conocido esconde al proximo que sea real. */
            $fila['detalle'] = isset($s['detalle']) ? $s['detalle'] : [];

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

        /* ---- 0. Uso de cobertura ----------------------------------------- */
        // Va ANTES del arrastre porque lo modifica: el motor calcula cuanto
        // rescatar de cada fondo para que el saldo acumulado no quede abajo
        // de cero, y eso se escribe en las filas de uso, que son filas de
        // movimiento. Recien con eso puesto el arrastre de abajo es la
        // posicion proyectada de verdad.
        $this->resolverUsoCobertura($h, $columnas, $resueltas);

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
        //
        // FLUJO_NETO SUMA TAMBIEN EL SALDO QUE SE MUESTRA MAS ARRIBA. La
        // definicion es Ingresos - Egresos, y los Ingresos del Excel arrancan en
        // el Disponible, que incluye el saldo en bancos: D38 = D13 + D37. Antes
        // sumaba solo los movimientos, con lo que el Flujo Neto de un dia con
        // saldo inicial daba la variacion de caja y no los ingresos menos los
        // egresos, que es lo que el rotulo promete.
        //
        // ES EL SALDO MOSTRADO, NO EL ARRASTRE: 'apertura' no entra. Eso es
        // exactamente lo que distingue FLUJO_NETO de SALDO_FINAL, que es el
        // ARRASTRE de lo que tiene por encima. Por eso tampoco se le suma a
        // SALDO_FINAL: ahi el saldo ya entro como 'aporte' y contarlo de nuevo
        // lo duplicaria.
        //
        // SALDO_FINAL ARRASTRA SOLO LO QUE TIENE POR ENCIMA, columna a columna
        // desde el principio del horizonte. Antes era "apertura de la columna
        // (con TODO) + movimientos por encima", y con una sola fila al final
        // del cuadro es lo mismo. La diferencia aparece con dos: una fila de
        // saldo puesta ARRIBA de la cobertura tiene que ser la posicion SIN
        // cobertura -el rojo que dispara el rescate-, y con la apertura global
        // mostraria un hibrido: los rescates de ayer si, el de hoy no. Es la
        // misma regla posicional de FLUJO_NETO, aplicada al arrastre. La fila
        // del final sigue dando exactamente el cierre global, y el invariante
        // de abajo lo verifica.
        foreach ($resueltas as $i => $f) {
            if ($f['tipo'] !== 'FLUJO_NETO' && $f['tipo'] !== 'SALDO_FINAL') {
                continue;
            }

            $mapa = [];
            $acumulado = 0;

            foreach ($columnas as $col) {
                $hasta = $this->sumarMovimientos($resueltas, $col, null, $i);

                if ($f['tipo'] === 'FLUJO_NETO') {
                    $mapa[$col] = $hasta + $this->sumarSaldoMostrado($resueltas, $col, null, $i);
                } else {
                    $acumulado += $this->sumarAporteSaldo($resueltas, $col, $i) + $hasta;
                    $mapa[$col] = $acumulado;
                }
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

        // Y el segundo invariante: la cobertura automatica arrastro el saldo
        // por su cuenta, con el mismo flujo, y tiene que haber llegado al mismo
        // cierre. Si no, o el flujo que se le dio no era el completo, o lo que
        // se escribio en las filas de uso no es lo que calculo. Las dos cosas
        // dejarian un saldo final tapado con plata que no se ve en ninguna
        // fila, que es exactamente lo que este cuadro no puede hacer.
        if ($this->automatico !== null) {
            foreach ($columnas as $col) {
                if (abs($cierre[$col] - $this->automatico['saldo'][$col]) > 0.01) {
                    $this->warnings[] = 'La cobertura automática no cierra con el arrastre en '
                        . $this->rotulo($col) . ': el saldo final puede no reflejar lo que se '
                        . 'rescató. Avisá a Sistemas.';
                    break;
                }
            }
        }
    }

    /* ====================================================================
       COBERTURA AUTOMATICA: CUANTO SE RESCATA DE CADA FONDO Y CUANDO
       ==================================================================== */

    /**
     * Calcula el uso de cobertura y lo escribe en las filas de uso.
     *
     * Antes el uso se cargaba a mano, celda por celda, y habia que rehacerlo
     * cada vez que se movia un vencimiento. Ahora lo calcula el motor en cada
     * pedido, sin persistir nada: el algoritmo esta en CoberturaAutomatica,
     * que es una funcion pura; aca solo se arman sus entradas con lo que los
     * proveedores dieron y se reparte su resultado en las filas.
     *
     * QUE FILAS PARTICIPAN. Las de tipo USO_COBERTURA con COMPUTA = 1: son
     * las que entran al arrastre, y un rescate que no entrara al saldo no
     * cubriria nada. Una fila de uso informativa se queda con lo cargado a
     * mano, sin calculo.
     *
     * QUE FONDOS. Los que tienen tope -lo dice la serie de stock, en
     * 'fondos_tope'- Y ademas alguna fila de uso los nombra, en 'fondos'.
     * Hay UNA FILA POR CLASE DE FONDO ("Uso de Inversiones", "Uso de Dolares
     * comitente") y cada una aplica las cuentas de su clase; el motor no sabe
     * cual es cual, lo lee de la serie. Un fondo con stock que ninguna fila
     * aplica no se toca -no habria donde mostrar el rescate- y se avisa: es lo
     * que pasa mientras no se corra sql/cashflow_cobertura_automatica.sql.
     *
     * EL FLUJO QUE RECIBE EL ALGORITMO ES EL DE ANTES DE TODA COBERTURA: el
     * aporte de saldo mas los movimientos que computan, sin las filas de uso.
     * Lo cargado a mano en esas filas viaja aparte, en pesos por columna, para
     * que tenga precedencia y el motor cubra solo lo que siga faltando.
     *
     * LO QUE QUEDA EN CADA FILA. El valor de cada columna pasa a ser lo manual
     * mas lo automatico de sus fondos, en pesos, y 'cobertura_columnas' guarda
     * el desglose -manual y calculado, por fondo y en su moneda- para que el
     * front pueda distinguirlos y editar lo manual sin recalcular nada.
     *
     * @param Horizonte $h
     * @param array $columnas Secuencia cronologica
     * @param array $resueltas Por referencia
     */
    private function resolverUsoCobertura($h, $columnas, &$resueltas) {
        $this->automatico = null;

        /* ---- 1. Las filas de uso y los fondos que cada una aplica --------- */
        $filasUso = [];

        foreach ($resueltas as $i => $f) {
            if ($f['tipo'] !== 'USO_COBERTURA') {
                continue;
            }

            $claves = array_values(array_unique(array_merge(
                array_keys($f['fondos']),
                array_keys($f['por_fondo']),
                array_keys($f['fondos_manual'])
            )));

            $resueltas[$i]['fondos_fila'] = $claves;

            if ($f['computa']) {
                $filasUso[$i] = $claves;
            }
        }

        if (empty($filasUso)) {
            return;
        }

        // Un fondo nombrado por dos filas se aplica en la primera. No deberia
        // pasar -cada fila trae una clase- pero si pasa, contar el rescate en
        // las dos lo sumaria dos veces al saldo.
        $filaDeFondo = [];

        foreach ($filasUso as $i => $claves) {
            foreach ($claves as $clave) {
                if (!isset($filaDeFondo[$clave])) {
                    $filaDeFondo[$clave] = $i;
                }
            }
        }

        /* ---- 2. Los topes, de las filas de stock ------------------------- */
        $topes = [];
        $nombres = [];

        foreach ($resueltas as $f) {
            if ($f['tipo'] !== 'STOCK_COBERTURA') {
                continue;
            }

            foreach ($f['fondos_tope'] as $clave => $d) {
                if (!isset($topes[$clave])) {
                    $topes[$clave] = $d;
                }
            }

            foreach ($f['fondos'] as $clave => $nombre) {
                $nombres[$clave] = $nombre;
            }
        }

        $fondos = [];
        $sinFila = [];

        foreach ($topes as $clave => $d) {
            if (!isset($filaDeFondo[$clave])) {
                $sinFila[] = isset($nombres[$clave]) ? $nombres[$clave] : $clave;
                continue;
            }

            $manual = [];
            $fm = $resueltas[$filaDeFondo[$clave]]['fondos_manual'];

            if (isset($fm[$clave])) {
                foreach ($fm[$clave] as $col => $v) {
                    $manual[$col] = $v['importe'];
                }
            }

            $fondos[] = [
                'clave' => $clave,
                'moneda' => $d['moneda'],
                'clase' => $d['clase'],
                'orden' => $d['orden'],
                'tope' => $d['tope'],
                'tc' => $d['tc'],
                'manual' => $manual
            ];
        }

        // Sin ningun fondo del que rescatar no hay calculo, y la seccion se
        // resuelve como antes: lo manual contra el stock a hoy. Lo unico que
        // queda por decir es si habia stock que ninguna fila aplica.
        if (empty($fondos)) {
            $this->avisarSinFila($sinFila);

            return;
        }

        $fondos = CoberturaAutomatica::ordenDeConsumo($fondos);

        /* ---- 3. El flujo sin cobertura y lo manual, por columna ---------- */
        $flujo = [];
        $manual = [];

        foreach ($columnas as $col) {
            $flujo[$col] = $this->sumarAporteSaldo($resueltas, $col)
                + $this->sumarMovimientos($resueltas, $col, null, null, 'USO_COBERTURA');

            $m = 0;

            foreach ($filasUso as $i => $claves) {
                $m += $this->valor($resueltas[$i], $col);
            }

            $manual[$col] = $m;
        }

        $r = CoberturaAutomatica::calcular($columnas, $flujo, $fondos, $manual);

        $r['fondos'] = [];

        foreach ($fondos as $f) {
            $r['fondos'][$f['clave']] = $f;
        }

        $r['sin_fila'] = $sinFila;
        $this->automatico = $r;

        /* ---- 4. Se vuelca en las filas ----------------------------------- */
        foreach ($filasUso as $i => $claves) {
            $desglose = [];
            $totales = ['manual' => 0.0, 'automatico' => 0.0];

            foreach ($columnas as $col) {
                $info = [
                    'manual' => $this->valor($resueltas[$i], $col),
                    'automatico' => 0.0,
                    'fondos' => []
                ];

                foreach ($claves as $clave) {
                    $mi = isset($resueltas[$i]['fondos_manual'][$clave][$col])
                        ? $resueltas[$i]['fondos_manual'][$clave][$col] : null;
                    $ai = isset($r['automatico'][$col][$clave]) ? $r['automatico'][$col][$clave] : null;

                    if ($mi === null && $ai === null) {
                        continue;
                    }

                    $info['fondos'][$clave] = [
                        'manual' => ($mi === null) ? 0.0 : $mi['importe'],
                        'manual_ars' => ($mi === null) ? 0.0 : $mi['ars'],
                        'automatico' => ($ai === null) ? 0.0 : $ai['importe'],
                        'automatico_ars' => ($ai === null) ? 0.0 : $ai['ars']
                    ];

                    if ($ai !== null) {
                        $info['automatico'] += $ai['ars'];
                    }
                }

                if ($info['manual'] == 0 && $info['automatico'] == 0) {
                    continue;
                }

                $info['automatico'] = round($info['automatico'], 2);
                $desglose[$col] = $info;
                $totales['manual'] += $info['manual'];
                $totales['automatico'] += $info['automatico'];

                if ($info['automatico'] != 0) {
                    $resueltas[$i] = $this->ponerValor($resueltas[$i], $col,
                        round($info['manual'] + $info['automatico'], 2));
                }
            }

            $resueltas[$i]['cobertura_columnas'] = $desglose;
            $resueltas[$i]['cobertura_totales'] = [
                'manual' => round($totales['manual'], 2),
                'automatico' => round($totales['automatico'], 2)
            ];
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
     * @param string|null $salvoTipo Un tipo de movimiento que se deja afuera.
     *        Lo usa la cobertura automatica para medir el flujo SIN las filas
     *        de uso, que son justamente lo que va a calcular.
     * @return float
     */
    private function sumarMovimientos($resueltas, $col, $alcance, $limite = null, $salvoTipo = null) {
        $total = 0;

        foreach ($resueltas as $pos => $f) {
            if ($limite !== null && $pos >= $limite) {
                break;
            }

            if (!in_array($f['tipo'], CashflowEstructura::TIPOS_MOVIMIENTO, true)) {
                continue;
            }

            if ($salvoTipo !== null && $f['tipo'] === $salvoTipo) {
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
     * @param int|null $limite Indice tope: solo las filas ANTERIORES a esa
     *        posicion, para el arrastre posicional de SALDO_FINAL. null para
     *        el arrastre global.
     * @return float
     */
    private function sumarAporteSaldo($resueltas, $col, $limite = null) {
        $total = 0;

        foreach ($resueltas as $pos => $f) {
            if ($limite !== null && $pos >= $limite) {
                break;
            }

            if ($f['tipo'] === 'SALDO_INICIAL') {
                $total += $this->valor($f, $col);
            }
        }

        return $total;
    }

    /**
     * Suma lo que MUESTRAN las filas de saldo inicial. Tiene dos usuarios y cada
     * uno la acota de una forma distinta:
     *
     *   SUBTOTAL   -> por ALCANCE de seccion, sin limite posicional. Es lo que
     *                 reproduce el "Disponible" del Excel, que es el saldo en
     *                 bancos mas las cobranzas del dia, todo en una seccion.
     *   FLUJO_NETO -> por POSICION, sin alcance. Flujo Neto es
     *                 Ingresos - Egresos, y los Ingresos incluyen el saldo
     *                 inicial: en el Excel D38 = D13 + D37, y D13 es el
     *                 Disponible, que arranca en el saldo en bancos.
     *
     * Los dos filtros son independientes a proposito: un subtotal abarca toda su
     * seccion este donde este puesto dentro de ella, y un flujo neto abarca todo
     * lo que tiene por encima sin importar de que seccion sea.
     *
     * SALDO_FINAL NO LA USA, Y NO ES UN OLVIDO: ese ya suma el saldo por otro
     * lado -entra al arrastre como 'aporte'-, asi que sumarlo aca lo contaria
     * dos veces. Esa es justamente la diferencia entre las dos filas: FLUJO_NETO
     * muestra el saldo que esta dibujado mas arriba, SALDO_FINAL lo arrastra.
     *
     * @param array $resueltas
     * @param string $col
     * @param array|null $alcance Codigos de seccion a considerar, o null para todas
     * @param int|null $limite Indice tope: solo las filas ANTERIORES a esa
     *        posicion. null para no limitar.
     * @return float
     */
    private function sumarSaldoMostrado($resueltas, $col, $alcance, $limite = null) {
        $total = 0;

        foreach ($resueltas as $pos => $f) {
            if ($limite !== null && $pos >= $limite) {
                break;
            }

            if ($f['tipo'] !== 'SALDO_INICIAL') {
                continue;
            }

            if ($alcance !== null && !isset($alcance[$f['seccion']])) {
                continue;
            }

            $total += $this->valor($f, $col);
        }

        return $total;
    }

    /* ====================================================================
       COBERTURA: CUANTO HAY, CUANTO SE USO Y CUANTO QUEDA
       ==================================================================== */

    /**
     * Resuelve el saldo de cobertura y se lo cuelga a las filas que lo
     * explican.
     *
     * POR QUE LO CALCULA EL MOTOR Y NO EL FRONT. Es una resta entre dos filas
     * del cuadro, y el front de este modulo no calcula nada: pinta lo que el
     * motor ya resolvio. Ademas la cuenta tiene una sutileza que no conviene
     * dejar suelta en el navegador -ver el parrafo del horizonte-.
     *
     * SE MIDE SOBRE TODO EL HORIZONTE, NO SOBRE LA VISTA ACTIVA. El stock es un
     * stock: no cambia porque uno mire el tramo diario en vez del mensual. Si lo
     * aplicado se midiera por vista, el disponible cambiaria al tocar un boton
     * -la misma plata, dos numeros distintos- y ademas una aplicacion cargada en
     * un mes de mas adelante no se descontaria mientras se mira la vista Dias,
     * que es justo cuando se decide aplicar mas.
     *
     * UN USO NEGATIVO DEVUELVE PLATA A LA INVERSION, asi que SUMA al disponible.
     * Sale gratis: es la misma resta, con el signo del dato.
     *
     * SI NO HAY FILA DE STOCK NO SE INVENTA NINGUNO. Puede estar inhabilitada, o
     * su modulo puede no haber devuelto nada. Sin saber cuanto hay, "cuanto
     * queda" no se puede contestar, y contestar cero seria decir que no hay
     * plata cuando lo que pasa es que no se sabe.
     *
     * LO APLICADO ES MANUAL MAS AUTOMATICO, y se informan por separado. Lo
     * manual por fondo lo trae la serie de uso ('por_fondo'); lo automatico
     * sale de lo que calculo resolverUsoCobertura(). El front muestra los dos
     * porque no significan lo mismo: uno es una decision cargada, el otro lo
     * que el motor rescato para que el saldo no quede en rojo.
     *
     * @param array $resueltas Por referencia
     */
    private function resolverCobertura(&$resueltas) {
        $stock = 0;
        $aplicado = 0;
        $hayStock = false;
        $hayUso = false;
        $auto = $this->automatico;

        /* EL DETALLE POR FONDO. Cada serie de stock trae cuanto aporta cada
           cuenta de fondo ('por_fondo') y la de uso cuanto se aplico desde
           cada una, con la misma clave. Con mas de un fondo el total dejo de
           alcanzar: se pueden aplicar trescientos millones "de dolares" y que
           el total cierre porque las inversiones lo tapan.

           LOS FONDOS SON CUENTAS, NO UNA LISTA DEL CODIGO. Antes cada fila de
           stock declaraba su fondo en el registro ('origen_cobertura'); ahora
           las cuentas las da de alta el usuario, asi que el reparto viaja con
           la serie y el motor no conoce ninguna. Un stock que no reparte -por
           ejemplo una fila que siga leyendo de Otros Ingresos- suma al total y
           a ningun fondo. Ver Providers/FondosProvider.php. */
        $fondos = [];

        $abrir = function ($clave, $nombre = null) use (&$fondos) {
            if (!isset($fondos[$clave])) {
                $fondos[$clave] = ['stock' => 0, 'aplicado' => 0, 'manual' => 0, 'automatico' => 0,
                                   'nombre' => $clave, 'moneda' => 'ARS', 'clase' => null,
                                   // Si el motor puede rescatar de aca: tiene tope
                                   // y una fila de uso que lo aplique.
                                   'automatizable' => false];
            }

            if ($nombre !== null && $nombre !== '') {
                $fondos[$clave]['nombre'] = $nombre;
            }
        };

        foreach ($resueltas as $f) {
            $nombres = isset($f['fondos']) ? $f['fondos'] : [];

            if ($f['tipo'] === 'STOCK_COBERTURA') {
                $stock += $f['total_horizonte'];
                $hayStock = true;

                foreach ((isset($f['por_fondo']) ? $f['por_fondo'] : []) as $o => $v) {
                    $abrir($o, isset($nombres[$o]) ? $nombres[$o] : null);
                    $fondos[$o]['stock'] += floatval($v);
                }

                foreach ((isset($f['fondos_tope']) ? $f['fondos_tope'] : []) as $o => $d) {
                    $abrir($o, isset($nombres[$o]) ? $nombres[$o] : null);
                    $fondos[$o]['moneda'] = $d['moneda'];
                    $fondos[$o]['clase'] = $d['clase'];
                }
            }

            if ($f['tipo'] === 'USO_COBERTURA' && $f['computa']) {
                $aplicado += $f['total_horizonte'];
                $hayUso = true;

                foreach ((isset($f['por_fondo']) ? $f['por_fondo'] : []) as $o => $v) {
                    $abrir($o, isset($nombres[$o]) ? $nombres[$o] : null);
                    $fondos[$o]['manual'] += floatval($v);
                }

                // Los fondos que la fila nombra sin haber aplicado nada
                // tambien se abren: el front los ofrece para cargar a mano.
                foreach ($nombres as $o => $n) {
                    $abrir($o, $n);
                }
            }
        }

        if (!$hayStock && !$hayUso) {
            return;
        }

        /* LO AUTOMATICO, POR FONDO: lo que el motor rescato menos lo que
           devolvio, en pesos, sobre todo el horizonte. */
        $automatico = 0;
        $faltante = [];

        if ($auto !== null) {
            foreach ($auto['fondos'] as $clave => $d) {
                $abrir($clave);
                $fondos[$clave]['automatizable'] = true;
            }

            foreach ($auto['automatico'] as $col => $porFondo) {
                foreach ($porFondo as $clave => $v) {
                    $abrir($clave);
                    $fondos[$clave]['automatico'] += $v['ars'];
                    $automatico += $v['ars'];
                }
            }

            $faltante = $auto['faltante'];
        }

        foreach ($fondos as $o => $d) {
            $fondos[$o]['automatico'] = round($d['automatico'], 2);
            $fondos[$o]['aplicado'] = round($d['manual'] + $d['automatico'], 2);
            $fondos[$o]['disponible'] = round($d['stock'] - $fondos[$o]['aplicado'], 2);
        }

        $info = [
            'stock' => $stock,
            'aplicado' => $aplicado,
            'manual' => round($aplicado - $automatico, 2),
            'automatico' => round($automatico, 2),
            'disponible' => $stock - $aplicado,
            // Sin fila de stock el disponible no significa nada, y el front
            // tiene que poder distinguirlo de un disponible de cero.
            'hay_stock' => $hayStock,
            // Las columnas que quedan en rojo aunque se aplique todo lo que
            // hay, con cuanto falta en cada una. Vacio = alcanzo.
            'faltante' => $faltante,
            'fondos' => $fondos
        ];

        foreach ($resueltas as $i => $f) {
            if ($f['tipo'] === 'STOCK_COBERTURA' || $f['tipo'] === 'USO_COBERTURA') {
                $resueltas[$i]['cobertura'] = $info;
            }
        }

        $this->avisarCobertura($fondos, $stock, $aplicado, $hayStock);
    }

    /**
     * Los avisos de la seccion Cobertura.
     *
     * SE AVISA CUANDO SE APLICA MAS DE LO QUE HAY, y no se bloquea. Que
     * alguien planifique cubrir con plata que todavia no esta puede ser
     * deliberado -un rescate que se va a hacer, una suscripcion en camino-,
     * asi que la app no tiene por que impedirlo. Lo que no puede pasar es
     * que el tablero muestre un saldo final tapado con plata inexistente sin
     * decirlo.
     *
     * EL AVISO ES POR FONDO. Un fondo puede estar sobregirado mientras el
     * total cierra, y ese caso -aplicar de un fondo plata que esta en el
     * otro- es el que el pozo unico no podia ver.
     *
     * PARA UN FONDO QUE EL MOTOR MANEJA, LA MEDIDA ES OTRA. Compararlo contra
     * el stock A HOY daria falsas alarmas: una suscripcion prevista sube el
     * tope de las columnas siguientes y el motor puede usarla con razon. Lo
     * que vale ahi es lo que CoberturaAutomatica informo en 'sobregirado':
     * la primera columna en la que lo aplicado -a mano o previsto en Saldos-
     * supera el saldo del fondo A ESA FECHA. Por lo mismo, cuando el motor
     * corrio no se avisa por el total: el total a hoy no es el tope de nada.
     *
     * Y SI NO ALCANZA, SE DICE CUANTO FALTA Y DONDE. Con los dos fondos
     * agotados la columna queda en rojo -eso ya lo marca el front- pero el
     * numero que le falta solo lo sabe el motor.
     */
    private function avisarCobertura($fondos, $stock, $aplicado, $hayStock) {
        $auto = $this->automatico;

        foreach ($fondos as $clave => $d) {
            if ($d['automatizable']) {
                if (!empty($auto['sobregirado'][$clave])) {
                    $col = array_key_first($auto['sobregirado'][$clave]);
                    $exceso = $auto['sobregirado'][$clave][$col];

                    $this->warnings[] = 'Cobertura: el ' . $this->rotulo($col) . ' lo aplicado de "'
                        . $d['nombre'] . '" supera en ' . $this->moneda($exceso, $d['moneda'])
                        . ' lo que hay en ese fondo a esa fecha (rescates previstos en Saldos → '
                        . 'Fondos incluidos). El motor no rescata de ahí hasta que vuelva a '
                        . 'haber saldo.';
                }

                continue;
            }

            if ($d['stock'] <= 0 && $d['aplicado'] <= 0) {
                continue;
            }

            if ($d['aplicado'] > $d['stock'] + 0.01) {
                $this->warnings[] = 'Cobertura: se aplican ' . $this->plata($d['aplicado'])
                    . ' de "' . $d['nombre'] . '" pero ahí hay ' . $this->plata($d['stock'])
                    . '. Faltan ' . $this->plata($d['aplicado'] - $d['stock'])
                    . ' de ese fondo.';
            }
        }

        if ($auto === null && $hayStock && $aplicado > $stock + 0.01) {
            $this->warnings[] = 'Cobertura: se aplican ' . $this->plata($aplicado)
                . ' pero el total disponible para cubrir es ' . $this->plata($stock)
                . '. Faltan ' . $this->plata($aplicado - $stock) . ', así que el Saldo Final '
                . 'está cubierto con plata que todavía no figura como disponible.';
        }

        if ($auto === null) {
            return;
        }

        if (!empty($auto['faltante'])) {
            $partes = [];

            foreach ($auto['faltante'] as $col => $v) {
                if (count($partes) === 3) {
                    $partes[] = (count($auto['faltante']) - 3) . ' más';
                    break;
                }

                $partes[] = $this->rotulo($col) . ' ' . $this->plata($v);
            }

            $this->warnings[] = 'Cobertura: aun aplicando todo lo invertido, el Saldo Final queda '
                . 'en rojo en ' . count($auto['faltante']) . ' columna(s). Falta: '
                . implode(', ', $partes) . '. No se inventa plata: esas columnas quedan marcadas.';
        }

        $this->avisarSinFila($auto['sin_fila']);
    }

    /**
     * Un fondo con stock que ninguna fila de uso aplica no se toca: el motor
     * no rescata de donde no puede mostrarlo. Es lo que pasa con la cuenta
     * comitente mientras no exista su fila de uso.
     *
     * @param array $nombres Los fondos en esa situacion
     */
    private function avisarSinFila($nombres) {
        if (empty($nombres)) {
            return;
        }

        $this->warnings[] = 'Cobertura: ' . implode(', ', array_map(function ($n) {
                return '"' . $n . '"';
            }, $nombres)) . ' tiene(n) stock pero ninguna fila de uso los aplica, así que el '
            . 'motor no rescata de ahí. Corré sql/cashflow_cobertura_automatica.sql, o apuntá una '
            . 'fila de tipo USO_COBERTURA a la serie de esa clase de fondo desde Parámetros → '
            . 'Cashflow.';
    }

    /** Un importe en la moneda del fondo, para los avisos */
    private function moneda($n, $moneda) {
        return ($moneda === 'USD' ? 'US$ ' : '$ ') . number_format(floatval($n), 2, ',', '.');
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
        /* EL STOCK DE COBERTURA NO VA EN NINGUNA COLUMNA DE FECHA.

           Es cuanta plata hay disponible para tapar un bache, no plata que entra
           un dia: ponerla en una columna diria que ese dia ingresa, y ademas la
           sumaria el Total de esa vista como si fuera flujo. Las columnas quedan
           en null -el front las dibuja con un guion- y el importe se muestra
           unicamente en la columna Total.

           El total es el MISMO en las tres vistas, por el mismo motivo que el de
           una fila de saldo: lo disponible no depende del tramo que se elija
           mirar. Se lee de la serie antes de vaciarla, asi que no importa en que
           columna lo haya dejado el proveedor. */
        if ($f['tipo'] === 'STOCK_COBERTURA') {
            $stock = array_sum(array_map('floatval', $f['dias']))
                + array_sum(array_map('floatval', $f['meses']));

            foreach ($f['dias'] as $k => $v) {
                $f['dias'][$k] = null;
            }

            foreach ($f['meses'] as $k => $v) {
                $f['meses'][$k] = null;
            }

            $f['total_tramo'] = $stock;
            $f['total_meses'] = $stock;
            $f['total_horizonte'] = $stock;

            return;
        }

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
            // Cuanta cobertura se aplico en el periodo. Va aparte de ingresos y
            // egresos a proposito; ver la nota del bucle.
            'cobertura' => 0,
            'saldo_cierre' => 0,
            'minimo' => null,
            'periodo' => $this->rotuloPeriodo($cols, $vista),
            'columnas' => count($cols)
        ];

        if (empty($cols)) {
            return $kpi;
        }

        $ultima = $cols[count($cols) - 1];

        // Con mas de una fila de saldo -una antes de la cobertura y otra al
        // final- el indicador es LA ULTIMA: es la que arrastra todo lo que hay,
        // o sea la posicion. Si se tomara el minimo entre las dos, el Saldo
        // Minimo mostraria el rojo que la cobertura ya tapo.
        $posSaldo = null;

        foreach ($resueltas as $pos => $f) {
            if ($f['tipo'] === 'SALDO_FINAL') {
                $posSaldo = $pos;
            }
        }

        foreach ($resueltas as $pos => $f) {
            if ($f['tipo'] === 'SALDO_FINAL') {
                if ($pos !== $posSaldo) {
                    continue;
                }

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

            /* EL SALDO MOSTRADO SUMA EN INGRESOS, por el mismo motivo por el que
               entra en el Flujo Neto: los Ingresos del cuadro arrancan en el
               Disponible, que incluye el saldo en bancos. Sin esto, la tarjeta
               de Ingresos no da lo mismo que la fila "Total Ingresos" y la de
               Flujo Neto no da lo mismo que la fila "Flujo Neto", que estan a
               dos centimetros una de otra. Un indicador que no coincide con la
               fila que tiene al lado no se puede usar para nada.

               Es el saldo MOSTRADO, no el arrastre: el arrastre ya lo informa
               saldo_cierre. Ver Cashflow::sumarSaldoMostrado(). */
            if ($f['tipo'] === 'SALDO_INICIAL') {
                foreach ($cols as $col) {
                    $kpi['ingresos'] += $this->valor($f, $col);
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
            //
            // LA COBERTURA APLICADA VA APARTE Y NO SUMA NI EN INGRESOS NI EN
            // EGRESOS. No es plata que el negocio genere ni gaste: es pasarla de
            // una inversion a la cuenta para tapar un bache. Contarla como
            // ingreso haria subir el indicador de Ingresos por haber movido
            // plata de bolsillo, y el de Flujo Neto dejaria de coincidir con la
            // fila "Flujo Neto (sin cobertura)" del cuadro, que es la que el
            // usuario esta mirando.
            //
            // Donde SI aparece es en el Saldo Final y en el Saldo Minimo: esos
            // salen del arrastre, que toma todos los movimientos. Y es lo que
            // se quiere, porque tapar el peor saldo proyectado es exactamente
            // para lo que existe la cobertura.
            if ($f['tipo'] === 'USO_COBERTURA') {
                $kpi['cobertura'] += $suma;
                continue;
            }

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
     * Rotulo del periodo que cubre un conjunto de columnas.
     * Delega en EjeVista para que el tablero y las pestanas de detalle no
     * puedan describir el mismo periodo de dos formas.
     *
     * @param array $cols
     * @param string $vista
     * @return string
     */
    private function rotuloPeriodo($cols, $vista) {
        return EjeVista::rotuloPeriodo($cols, $vista);
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
        return EjeVista::partir($col);
    }

    /** Rotulo legible de una columna, para los mensajes */
    private function rotulo($col) {
        return EjeVista::rotulo($col);
    }

    /** Formato de importe para los mensajes de aviso */
    private function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
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

    /**
     * Filas en el formato del JSON, ya ordenadas por seccion y orden.
     *
     * El tope por columna de cada fondo y lo manual por fondo no viajan: son
     * ENTRADAS de la cobertura automatica, no resultado, y el desglose que el
     * front necesita ya esta en 'cobertura_columnas'. Con 40 columnas por
     * fondo serian el bloque mas grande del JSON y nadie lo leeria.
     */
    private function salidaFilas($resueltas) {
        $v = [];

        foreach ($resueltas as $f) {
            unset($f['fondos_tope'], $f['fondos_manual']);
            $v[] = $f;
        }

        return $v;
    }
}
