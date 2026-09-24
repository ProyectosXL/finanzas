<?php

/**
 * ComprasProyectadas
 * Las reglas PURAS de la proyeccion de compras del exterior: a que temporada
 * pertenece un mes, como se reparte la compra de una temporada entre sus meses
 * de recepcion, y cual es la ventana de meses que el tablero puede proyectar.
 *
 * POR QUE VIVEN SOLAS Y SIN BASE
 * ------------------------------
 * Las tres cosas que decide este archivo son las que mueven la plata de un mes
 * a otro, y ninguna se puede verificar mirando el tablero: un reparto mal
 * normalizado o una ventana corrida un mes dan un cuadro que cierra igual y
 * que dice otra cosa. Separadas y puras se prueban con datos escritos a mano,
 * sin SQL Server y sin depender de lo que haya cargado hoy.
 *
 * Mismo criterio que Comex::saldoPendiente() y que Horizonte: la logica que
 * decide DONDE cae un importe no vive adentro de una consulta.
 *
 * LAS TRES REGLAS
 * ---------------
 *
 *   1. TEMPORADA. La convencion es la de la app de compras, y es una sola en
 *      las dos aplicaciones:
 *
 *          VER AA-AA   01/08 al 31/01     VER 26-27 = 01/08/2026 a 31/01/2027
 *          INV AA      01/02 al 31/07     INV 27    = 01/02/2027 a 31/07/2027
 *
 *      Cada mes pertenece a UNA sola temporada. El verano cruza el fin de
 *      anio, asi que enero pertenece al verano que ARRANCO el agosto anterior:
 *      enero de 2027 es VER 26-27 y no VER 27-28.
 *
 *   2. CUOTA. Que porcentaje de la compra de una temporada se recibe en cada
 *      uno de sus seis meses, medido sobre la historia real de recepciones.
 *
 *   3. VENTANA. Que meses de recepcion se proyectan. El ultimo NO es el ultimo
 *      mes del horizonte: es el ultimo mes de recepcion cuyo PAGO todavia cae
 *      adentro, y eso se deriva de los parametros en vez de fijarse.
 *
 * NADA DE ESTO LEE EL RELOJ NI LA BASE. La fecha de hoy y el fin del horizonte
 * entran por parametro, asi que una prueba escrita hoy sigue valiendo el anio
 * que viene.
 */
class ComprasProyectadas {

    /** Temporada de verano: agosto a enero */
    const VERANO = 'VER';

    /** Temporada de invierno: febrero a julio */
    const INVIERNO = 'INV';

    /** Primer mes de la temporada de verano */
    const INICIO_VERANO = 8;

    /** Primer mes de la temporada de invierno */
    const INICIO_INVIERNO = 2;

    /** Meses calendario de cada temporada, en orden cronologico */
    const MESES = [
        self::VERANO   => [8, 9, 10, 11, 12, 1],
        self::INVIERNO => [2, 3, 4, 5, 6, 7]
    ];

    /* ====================================================================
       1. TEMPORADAS
       ==================================================================== */

    /**
     * La temporada a la que pertenece una fecha.
     *
     * ENERO ES LA TRAMPA, y es la unica. El verano arranca en agosto y termina
     * el 31 de enero del anio siguiente, asi que enero pertenece a la
     * temporada que empezo el anio ANTERIOR. Resolverlo por el anio calendario
     * de la fecha mandaria toda la compra de enero a la temporada equivocada
     * -la que todavia no empezo- y con ella su presupuesto, su version oficial
     * y su cotizacion.
     *
     * @param string $fecha 'Y-m-d' (alcanza con 'Y-m')
     * @return array|null ['codigo','tipo','anio','desde','hasta']
     */
    public static function temporada($fecha) {
        $fecha = (string) $fecha;

        if (!preg_match('/^(\d{4})-(\d{2})/', $fecha, $m)) {
            return null;
        }

        $anio = intval($m[1]);
        $mes = intval($m[2]);

        if ($mes < 1 || $mes > 12) {
            return null;
        }

        if ($mes >= self::INICIO_VERANO) {
            // Agosto a diciembre: el verano que arranca este anio.
            return self::armarTemporada(self::VERANO, $anio);
        }

        if ($mes < self::INICIO_INVIERNO) {
            // Enero: el verano que arranco el anio pasado.
            return self::armarTemporada(self::VERANO, $anio - 1);
        }

        // Febrero a julio.
        return self::armarTemporada(self::INVIERNO, $anio);
    }

    /**
     * Arma la temporada a partir de su tipo y del anio en que ARRANCA.
     *
     * EL CODIGO ES LA CLAVE CONTRA LA APP DE COMPRAS. Es el mismo texto que
     * guarda `temporada_codigo` en la version oficial, y con el cada mes de la
     * ventana busca su presupuesto. Un formato distinto de este lado deja a la
     * temporada sin encontrar su version y al mes entero en SIN_PRESUPUESTO,
     * sin que nada falle. Por eso se arma en un solo lugar y no se reformatea
     * en ningun otro.
     *
     * @param string $tipo self::VERANO o self::INVIERNO
     * @param int $anioInicio Anio en que empieza la temporada
     * @return array
     */
    public static function armarTemporada($tipo, $anioInicio) {
        $anioInicio = intval($anioInicio);

        if ($tipo === self::VERANO) {
            $codigo = sprintf('VER %02d-%02d', $anioInicio % 100, ($anioInicio + 1) % 100);
            $desde = sprintf('%04d-08-01', $anioInicio);
            $hasta = sprintf('%04d-01-31', $anioInicio + 1);
        } else {
            $codigo = sprintf('INV %02d', $anioInicio % 100);
            $desde = sprintf('%04d-02-01', $anioInicio);
            $hasta = sprintf('%04d-07-31', $anioInicio);
        }

        return [
            'codigo' => $codigo,
            'tipo' => $tipo,
            'anio' => $anioInicio,
            'desde' => $desde,
            'hasta' => $hasta
        ];
    }

    /**
     * Los seis meses 'Y-m' de una temporada, en orden cronologico.
     *
     * @param array $temporada Lo que devuelve temporada() o armarTemporada()
     * @return array Lista de 'Y-m'
     */
    public static function mesesDeTemporada($temporada) {
        if (!is_array($temporada) || !isset($temporada['tipo'])
            || !isset(self::MESES[$temporada['tipo']])) {
            return [];
        }

        $anio = intval($temporada['anio']);
        $out = [];

        foreach (self::MESES[$temporada['tipo']] as $mes) {
            /* El verano cruza el fin de anio: enero pertenece al anio siguiente
               al que da nombre al arranque de la temporada. */
            $a = ($temporada['tipo'] === self::VERANO && $mes < self::INICIO_VERANO)
                ? $anio + 1
                : $anio;

            $out[] = sprintf('%04d-%02d', $a, $mes);
        }

        return $out;
    }

    /* ====================================================================
       2. LA CUOTA
       ==================================================================== */

    /**
     * Como se reparte la compra de una temporada entre sus seis meses.
     *
     * QUE ENTRA: los movimientos de RECEPCION de importacion, agrupados por
     * anio y mes calendario, con su peso.
     *
     *     [['anio' => 2025, 'mes' => 3, 'peso' => 161988.0], ...]
     *
     * El peso es lo que se esta repartiendo. Hoy es el importe FOB de cada
     * recepcion, PRORRATEADO entre las tandas de una misma orden de compra;
     * puede ser unidades. Por eso entra por parametro y no como una consulta
     * escrita adentro: la funcion reparte, no decide con que se mide.
     *
     * LOS ANIOS SE SUMAN, NO SE PROMEDIAN. Promediar los porcentajes de cada
     * anio le daria el mismo peso a un anio de 760.000 unidades que a uno de
     * 1.320.000, y los anios de esta serie difieren en casi el doble. Lo que se
     * quiere saber es que fraccion del volumen entra en cada mes, y eso es la
     * suma sobre la suma. La contracara es que un anio anomalo pesa mas: por
     * eso el parametro son TRES anios y no uno. Anio por anio la cuota es
     * visiblemente inestable -abril fue 23,66 % en 2023 y 11,10 % en 2025-, y
     * recien agregada se estabiliza.
     *
     * LA NORMALIZACION ES POR TEMPORADA Y NO POR ANIO. Lo que el presupuesto da
     * es la compra de UNA temporada, asi que sus seis meses tienen que sumar
     * 100 %. Repartir con los porcentajes del anio dejaria sin asignar el 42 %
     * que se lleva la otra temporada, y esa plata desapareceria del tablero.
     *
     * UN MES SIN NINGUN MOVIMIENTO NO ES UN CERO. Si en los N anios no hay ni
     * un registro de ese mes calendario, no se sabe cuanto entra: se informa en
     * 'sin_datos' para que el mes quede marcado SIN_HISTORIA, y el reparto se
     * normaliza sobre los meses que SI tienen historia. La alternativa -dejarlo
     * en cero sin renormalizar- haria que la temporada reparta menos del 100 %
     * de su presupuesto, y el tablero proyectaria de menos sin que la suma lo
     * delate. Un mes que aparece con peso CERO si es un cero: es un mes en el
     * que efectivamente no entro nada, y eso es informacion.
     *
     * @param array $movimientos Lista de ['anio','mes','peso']
     * @param array|null $anios Anios a considerar. null = todos los que vengan
     * @return array ['VER' => ['pct'=>[mes=>float|null], 'sin_datos'=>[mes], 'base'=>float],
     *                'INV' => [...]]
     */
    public static function cuota($movimientos, $anios = null) {
        $filtro = null;

        if (is_array($anios)) {
            $filtro = [];

            foreach ($anios as $a) {
                $filtro[intval($a)] = true;
            }
        }

        // Peso acumulado por mes calendario, y si ese mes tuvo ALGUN registro.
        $peso = [];
        $visto = [];

        if (is_array($movimientos)) {
            foreach ($movimientos as $mov) {
                if (!is_array($mov) || !isset($mov['mes'])) {
                    continue;
                }

                $mes = intval($mov['mes']);

                if ($mes < 1 || $mes > 12) {
                    continue;
                }

                if ($filtro !== null) {
                    $anio = intval(isset($mov['anio']) ? $mov['anio'] : 0);

                    if (!isset($filtro[$anio])) {
                        continue;
                    }
                }

                $visto[$mes] = true;
                $peso[$mes] = (isset($peso[$mes]) ? $peso[$mes] : 0.0)
                    + floatval(isset($mov['peso']) ? $mov['peso'] : 0);
            }
        }

        $out = [];

        foreach (self::MESES as $tipo => $meses) {
            $base = 0.0;
            $sinDatos = [];

            foreach ($meses as $mes) {
                if (empty($visto[$mes])) {
                    $sinDatos[] = $mes;

                    continue;
                }

                $base += $peso[$mes];
            }

            $pct = [];

            foreach ($meses as $mes) {
                /* Un mes sin historia queda en null y NO en cero: quien lo lee
                   tiene que poder distinguir "no se sabe" de "no entra nada".
                   Es la misma regla con la que Pruebas::chequear() compara null
                   de forma estricta. */
                if (empty($visto[$mes])) {
                    $pct[$mes] = null;

                    continue;
                }

                $pct[$mes] = ($base > 0) ? ($peso[$mes] / $base * 100.0) : 0.0;
            }

            $out[$tipo] = [
                'pct' => $pct,
                'sin_datos' => $sinDatos,
                'base' => $base
            ];
        }

        return $out;
    }

    /**
     * El porcentaje que le toca a un mes 'Y-m' segun la cuota.
     *
     * @param array $cuota Lo que devuelve cuota()
     * @param string $mesClave 'Y-m'
     * @return float|null null si ese mes no tiene historia
     */
    public static function pctDelMes($cuota, $mesClave) {
        $t = self::temporada($mesClave);

        if ($t === null || !isset($cuota[$t['tipo']]['pct'])) {
            return null;
        }

        $mes = intval(substr($mesClave, 5, 2));
        $pct = $cuota[$t['tipo']]['pct'];

        return array_key_exists($mes, $pct) ? $pct[$mes] : null;
    }

    /* ====================================================================
       3. LA VENTANA
       ==================================================================== */

    /**
     * Los meses de RECEPCION que se proyectan, con sus tres fechas.
     *
     * EL ULTIMO MES NO ES EL ULTIMO DEL HORIZONTE, y esa es toda la regla. Lo
     * que el tablero muestra es el PAGO, que ocurre X dias ANTES de la
     * recepcion; con X = 47, una recepcion del 15/10 se paga el 29/08. Cortar
     * la ventana en el ultimo mes del horizonte dejaria afuera dos meses de
     * pago que el cuadro SI puede mostrar.
     *
     * Asi que el ultimo mes se BUSCA: se avanza mes a mes mientras el pago siga
     * cayendo adentro del horizonte y se corta en el primero que se pasa. Se
     * deriva de D, de X y del fin del horizonte, que son los tres editables
     * desde Parametros; fijarlo en una constante lo dejaria mintiendo el dia
     * que alguien mueva cualquiera de los tres.
     *
     * EL DIA D SE RECORTA AL LARGO DEL MES. Con D = 31 y febrero, componer la
     * fecha sin recortar daria el 2 o 3 de marzo: el mes de recepcion se
     * correria solo y con el podria cambiar la temporada, que es lo que decide
     * contra que presupuesto se proyecta.
     *
     * DOS MESES DE RECEPCION PUEDEN CAER EN EL MISMO MES DE PAGO, y tambien
     * puede saltearse uno. Depende de D y de X: con D = 15 y X = 47 el mapeo es
     * uno a uno, pero con X = 45 marzo paga el 29/01 y abril el 01/03, asi que
     * febrero no paga nada. Por eso el mes de pago viaja en cada fila y existe
     * porMesDePago(): lo que se descuenta de un mes de pago tiene que
     * consumirse UNA vez, no una por cada mes de recepcion que caiga ahi.
     *
     * @param string $primerMes 'Y-m' del primer mes del eje (el mes en curso)
     * @param string $finHorizonte 'Y-m-d', ultimo dia que cubre el eje
     * @param int $M Cuantos meses de recepcion se proyectan
     * @param int $D Dia del mes en el que se ubica la recepcion
     * @param int $X Dias entre el pago del FOB y la recepcion
     * @param int $Y Dias entre la nacionalizacion y la recepcion
     * @return array ['ultimo' => 'Y-m'|null, 'meses' => [...]]
     */
    public static function ventana($primerMes, $finHorizonte, $M, $D, $X, $Y) {
        $M = max(0, intval($M));
        $D = max(1, intval($D));
        $X = intval($X);
        $Y = intval($Y);

        $ultimo = null;

        /* El tope de la busqueda no es un limite de negocio: es el corte de un
           bucle que, con un X negativo grande cargado por error, no terminaria
           nunca. */
        for ($i = 0; $i < 600; $i++) {
            $mes = self::sumarMeses($primerMes, $i);
            $pago = self::restarDias(self::fechaEnMes($mes, $D), $X);

            if ($pago > $finHorizonte) {
                break;
            }

            $ultimo = $mes;
        }

        if ($ultimo === null || $M === 0) {
            return ['ultimo' => $ultimo, 'meses' => []];
        }

        $meses = [];

        for ($k = $M - 1; $k >= 0; $k--) {
            $mes = self::sumarMeses($ultimo, -$k);
            $recepcion = self::fechaEnMes($mes, $D);
            $pago = self::restarDias($recepcion, $X);
            $nac = self::restarDias($recepcion, $Y);

            $meses[] = [
                'mes' => $mes,
                'recepcion' => $recepcion,
                'pago' => $pago,
                'nacionalizacion' => $nac,
                'mes_pago' => substr($pago, 0, 7),
                'mes_nacionalizacion' => substr($nac, 0, 7),
                'temporada' => self::temporada($mes)
            ];
        }

        return ['ultimo' => $ultimo, 'meses' => $meses];
    }

    /**
     * Agrupa los meses de la ventana por su mes de PAGO.
     *
     * Existe por una sola razon: el descuento de lo ya comprado se hace sobre
     * el eje de PAGO -es el unico eje en el que los dos lados estan definidos,
     * porque un contenedor del maestro se ubica por su FECHA_EST_PAGO, igual
     * que en la pestana Proveedores Exterior- y lo cargado de un mes de pago
     * tiene que consumirse UNA sola vez. Si dos meses de recepcion cayeran en
     * el mismo mes de pago y cada uno restara el total cargado, el descuento se
     * aplicaria dos veces y la estimacion quedaria en cero sin que nada lo
     * explique.
     *
     * @param array $ventana Lo que devuelve ventana()
     * @return array Mapa 'Y-m' de pago => lista de meses de recepcion 'Y-m'
     */
    public static function porMesDePago($ventana) {
        $out = [];

        if (!isset($ventana['meses']) || !is_array($ventana['meses'])) {
            return $out;
        }

        foreach ($ventana['meses'] as $m) {
            $out[$m['mes_pago']][] = $m['mes'];
        }

        return $out;
    }

    /* ====================================================================
       4. LA ESTIMACION Y LOS ESTADOS DE COBERTURA
       ==================================================================== */

    /** La temporada del mes no tiene version oficial */
    const SIN_PRESUPUESTO = 'SIN_PRESUPUESTO';

    /** Tenia ajuste manual, pero cambio la version oficial de su temporada */
    const AJUSTE_DESCARTADO = 'AJUSTE_DESCARTADO';

    /** Un importe cargado a mano reemplaza a la estimacion */
    const AJUSTADO = 'AJUSTADO';

    /** La cuota no tiene datos para ese mes calendario */
    const SIN_HISTORIA = 'SIN_HISTORIA';

    /** Lo ya comprado alcanza o supera lo proyectado */
    const CUBIERTO = 'CUBIERTO';

    /** Hay estimacion, pero el mes de pago no esta en la curva de futuros */
    const SIN_COTIZACION = 'SIN_COTIZACION';

    /** Tiene version, cuota y cotizacion */
    const ESTIMADO = 'ESTIMADO';

    /**
     * EL ORDEN EN QUE SE EVALUAN LOS ESTADOS, y el orden ES la regla.
     *
     * Un mes puede cumplir varias condiciones a la vez -estar ajustado y ademas
     * valuado con el mes mas cercano- y 'estado' es uno solo, porque la grilla
     * muestra una columna. Gana el primero de esta lista; los demas viajan en
     * 'marcas', asi que no se pierde ninguno.
     *
     * POR QUE ESTE ORDEN:
     *
     *   SIN_PRESUPUESTO va primero porque sin version oficial no hay nada que
     *   calcular: ni cuota que aplicar ni cargado que descontar. Cualquier otro
     *   estado sobre ese mes describiria una cuenta que no se hizo.
     *
     *   AJUSTE_DESCARTADO va antes que AJUSTADO por lo mismo que existe: es el
     *   caso en el que alguien cargo un numero a mano y el sistema decidio no
     *   usarlo. Si quedara tapado por el estado que corresponde a la
     *   estimacion automatica, el mes se veria normal y nadie se enteraria de
     *   que su ajuste dejo de aplicarse.
     *
     *   AJUSTADO va antes que todo lo que sigue porque REEMPLAZA la
     *   estimacion: que la cuota no tenga historia o que lo cargado cubra el
     *   mes deja de importar cuando el importe lo puso una persona.
     *
     *   SIN_COTIZACION va ultimo antes de ESTIMADO, y es a proposito que no
     *   gane sobre CUBIERTO: un mes cubierto vale cero, y cero por cualquier
     *   cotizacion es cero, asi que no hay ninguna aproximacion que informar.
     */
    const ESTADOS = [
        self::SIN_PRESUPUESTO,
        self::AJUSTE_DESCARTADO,
        self::AJUSTADO,
        self::SIN_HISTORIA,
        self::CUBIERTO,
        self::SIN_COTIZACION,
        self::ESTIMADO
    ];

    /** Tolerancia en U$S para comparar lo proyectado contra lo cargado */
    const TOLERANCIA_USD = 0.01;

    /**
     * LA CUENTA ENTERA, PURA: de la ventana y el presupuesto a la grilla de
     * cobertura, mes por mes, con su estado y sus avisos.
     *
     * No lee la base, no lee el reloj y no valua: todo lo que necesita entra
     * por parametro. Es lo que permite fijar en una prueba los casos que en la
     * base real no se pueden producir a voluntad -una temporada sin oficial, un
     * exceso de lo cargado, un ajuste que se descarta- y que son justamente los
     * que el tablero no puede equivocar.
     *
     * LAS CUATRO DECISIONES QUE VIVEN ACA
     * -----------------------------------
     *
     * 1. CADA TEMPORADA SE DESCUENTA CONTRA LA FECHA DE SU PROPIA VERSION.
     *    No hay una fecha de corte global. Lo anterior a la fecha de calculo de
     *    una version ya esta descontado adentro de su presupuesto -el
     *    stock_proyectado de la app de compras incluye las OC pendientes de
     *    ingreso- asi que restarlo seria contarlo dos veces. Y como la ventana
     *    puede cruzar dos o tres temporadas, con una sola fecha de corte los
     *    meses de una temporada descontarian contenedores que su presupuesto ya
     *    tenia netos.
     *
     * 2. LA RESTA VA SOBRE EL EJE DE PAGO. Lo proyectado nace en el eje de
     *    recepcion -la cuota reparte meses de recepcion- pero un contenedor del
     *    maestro de Comex se ubica por su FECHA_EST_PAGO, que es lo que hace la
     *    pestana Proveedores Exterior. Es el unico eje en el que los dos lados
     *    estan definidos: en la base real la distancia entre FECHA_EST_PAGO y
     *    FECHA_DESP_ADU va de -60 a +68 dias, asi que no hay formula que
     *    convierta un eje en el otro.
     *
     * 3. LO CARGADO DE UN MES DE PAGO SE CONSUME UNA SOLA VEZ. Si dos meses de
     *    recepcion caen en el mismo mes de pago -pasa segun D y X, ver
     *    porMesDePago()- se reparte en orden cronologico: cada mes toma lo que
     *    puede hasta su proyectado y el siguiente recibe el resto. Es el mismo
     *    criterio con el que la app de compras consume el stock proyectado
     *    entre tramos, y evita que el descuento se aplique dos veces.
     *
     * 4. UN EXCESO NO SE COMPENSA CONTRA OTROS MESES. Si lo cargado supera a lo
     *    proyectado, la estimacion es CERO y nunca negativa, y el sobrante se
     *    informa aparte en U$S. Un egreso negativo seria un ingreso que nadie
     *    afirmo, y encima compensado en silencio contra el resto de la columna.
     *    Es la misma regla que Comex::saldoPendiente() con el sobrepago.
     *
     * @param array $ventana Lo que devuelve ventana()
     * @param array $cuota Lo que devuelve cuota()
     * @param array $presupuestos Mapa codigo de temporada => version oficial:
     *              ['id_version','fecha_calculo','fob_usd','unidades',...]
     * @param array $cargado Contenedores del maestro, uno por fila:
     *              ['id','contenedor','mes_pago'|null,'fec_emisio'|null,'pendiente_usd']
     * @param array $opciones 'ajustes', 'nac_pct', 'meses_cotizacion'
     * @return array ['meses'=>[...], 'warnings'=>[...], 'totales'=>[...]]
     */
    public static function estimar($ventana, $cuota, $presupuestos, $cargado, $opciones = []) {
        $ajustes = isset($opciones['ajustes']) && is_array($opciones['ajustes'])
            ? $opciones['ajustes'] : [];
        $nacPct = isset($opciones['nac_pct']) ? floatval($opciones['nac_pct']) : 0.0;
        $conCotiz = isset($opciones['meses_cotizacion']) && is_array($opciones['meses_cotizacion'])
            ? $opciones['meses_cotizacion'] : null;

        $meses = isset($ventana['meses']) && is_array($ventana['meses']) ? $ventana['meses'] : [];

        /* DOS LISTAS, Y LA DIFERENCIA ES A QUIEN LE HABLAN.
           'warnings' son los que el proveedor sube al tablero: dicen que la
           proyeccion esta INCOMPLETA y por que -una temporada sin presupuesto,
           un mes sin historia, un ajuste descartado, un exceso-.
           'notas' son de reconciliacion y se quedan en la pestana: explican por
           que el numero no coincide con otra pantalla. Van separadas porque la
           mas comun de las notas -los contenedores que se pagan fuera de la
           ventana- aparece SIEMPRE y con casi todo el padron adentro. Mezclada
           con los avisos, ensenia a ignorar el bloque entero, que es la unica
           forma de que un aviso que si importa pase desapercibido. */
        $warnings = [];
        $notas = [];

        if (empty($meses)) {
            return ['meses' => [], 'warnings' => $warnings, 'notas' => $notas,
                    'totales' => self::totalesVacios()];
        }

        /* Lo cargado, indexado por mes de pago Y por fecha de emision de la OC.
           La fecha de emision no se puede aplicar todavia: depende de la
           temporada de cada mes, y recien se sabe adentro del recorrido. */
        $porPago = [];
        $sinFecha = 0.0;
        $sinFechaCant = 0;
        $sinEmision = 0.0;
        $sinEmisionCant = 0;

        foreach (is_array($cargado) ? $cargado : [] as $c) {
            $pendiente = floatval(isset($c['pendiente_usd']) ? $c['pendiente_usd'] : 0);

            if ($pendiente <= 0) {
                continue;
            }

            /* SIN FECHA ESTIMADA DE PAGO NO DESCUENTA EN NINGUN MES, y no se
               reparte ni se manda al primero. Un contenedor sin fecha es un
               contenedor del que no se sabe cuando sale la plata; elegirle un
               mes seria inventar el dato que falta. Se informa y se sigue. */
            if (empty($c['mes_pago'])) {
                $sinFecha += $pendiente;
                $sinFechaCant++;

                continue;
            }

            /* SIN FECHA DE EMISION DE LA OC tampoco descuenta: el corte es
               "emitida DESPUES de la fecha de calculo", y de una OC que no
               esta en Tango no se puede afirmar eso. Descontarla sin poder
               fecharla restaria algo que el presupuesto quizas ya tenia neto. */
            if (empty($c['fec_emisio'])) {
                $sinEmision += $pendiente;
                $sinEmisionCant++;

                continue;
            }

            $porPago[$c['mes_pago']][] = [
                'id' => isset($c['id']) ? $c['id'] : null,
                'contenedor' => isset($c['contenedor']) ? $c['contenedor'] : '',
                'fec_emisio' => substr((string) $c['fec_emisio'], 0, 10),
                'pendiente_usd' => $pendiente
            ];
        }

        if ($sinFechaCant > 0) {
            $warnings[] = $sinFechaCant . ' contenedor' . ($sinFechaCant === 1 ? '' : 'es')
                . ' del maestro de Comercio Exterior sin fecha estimada de pago, por '
                . self::usd($sinFecha) . ': no descuentan en ningun mes.';
        }

        if ($sinEmisionCant > 0) {
            $warnings[] = $sinEmisionCant . ' contenedor' . ($sinEmisionCant === 1 ? '' : 'es')
                . ' cuya orden de compra no esta en Tango, por ' . self::usd($sinEmision)
                . ': no se puede saber si se emitio despues del presupuesto, asi que no descuentan.';
        }

        /* Cuanto queda por consumir de cada mes de pago. Vive afuera del
           recorrido a proposito: es lo que hace que dos meses de recepcion que
           comparten mes de pago no descuenten dos veces lo mismo. */
        $saldoCargado = [];

        $filas = [];
        $sinPresupuesto = [];
        $sinHistoria = [];
        $descartados = [];
        $excesoTotal = 0.0;

        foreach ($meses as $m) {
            $codigo = isset($m['temporada']['codigo']) ? $m['temporada']['codigo'] : null;
            $version = ($codigo !== null && isset($presupuestos[$codigo]))
                ? $presupuestos[$codigo] : null;

            $fila = [
                'mes' => $m['mes'],
                'recepcion' => $m['recepcion'],
                'pago' => $m['pago'],
                'nacionalizacion' => $m['nacionalizacion'],
                'mes_pago' => $m['mes_pago'],
                'mes_nacionalizacion' => $m['mes_nacionalizacion'],
                'temporada' => $codigo,
                'version' => null,
                'cuota_pct' => null,
                'proyectado_usd' => 0.0,
                'cargado_usd' => 0.0,
                'consumido_usd' => 0.0,
                'exceso_usd' => 0.0,
                'estimacion_usd' => 0.0,
                'nacionalizacion_usd' => 0.0,
                'ajuste' => null,
                'estado' => self::ESTIMADO,
                'marcas' => [],
                'contenedores' => []
            ];

            $ajuste = isset($ajustes[$m['mes']]) ? $ajustes[$m['mes']] : null;

            /* --- Sin version oficial: no hay nada que calcular ------------- */
            if ($version === null) {
                $fila['estado'] = self::SIN_PRESUPUESTO;
                $filas[] = $fila;

                if ($codigo !== null) {
                    $sinPresupuesto[$codigo][] = $m['mes'];
                }

                continue;
            }

            $fila['version'] = [
                'id_version' => isset($version['id_version']) ? $version['id_version'] : null,
                'fecha_calculo' => isset($version['fecha_calculo'])
                    ? substr((string) $version['fecha_calculo'], 0, 10) : null,
                'nombre' => isset($version['nombre']) ? $version['nombre'] : '',
                'fob_usd' => floatval(isset($version['fob_usd']) ? $version['fob_usd'] : 0)
            ];

            /* --- Lo proyectado: la cuota sobre el FOB de la temporada ------ */
            $pct = self::pctDelMes($cuota, $m['mes']);
            $fila['cuota_pct'] = $pct;

            if ($pct === null) {
                $sinHistoria[] = $m['mes'];
            } else {
                $fila['proyectado_usd'] = $fila['version']['fob_usd'] * $pct / 100.0;
            }

            /* --- Lo cargado: solo lo emitido DESPUES de SU fecha de calculo -
               El filtro es por temporada, no global, y por eso se aplica aca
               adentro y no al armar $porPago. */
            $corte = $fila['version']['fecha_calculo'];
            $disponible = 0.0;
            $elegibles = [];

            if ($corte !== null && isset($porPago[$m['mes_pago']])) {
                foreach ($porPago[$m['mes_pago']] as $i => $c) {
                    if ($c['fec_emisio'] <= $corte) {
                        continue;
                    }

                    $clave = $m['mes_pago'] . '#' . $i;

                    if (!array_key_exists($clave, $saldoCargado)) {
                        $saldoCargado[$clave] = $c['pendiente_usd'];
                    }

                    if ($saldoCargado[$clave] <= self::TOLERANCIA_USD) {
                        continue;
                    }

                    $elegibles[] = $clave;
                    $disponible += $saldoCargado[$clave];
                    $fila['contenedores'][] = ['clave' => $clave]
                        + $c + ['disponible_usd' => $saldoCargado[$clave]];
                }
            }

            /* 'cargado_usd' ES LO QUE ESTE MES TENIA PARA DESCONTAR, no lo que
               termino usando. Asi la formula del pedido se lee literal en la
               grilla -estimacion = MAX(0, proyectado - cargado)- y el exceso es
               la diferencia visible. Lo que efectivamente se consumio va en
               'consumido_usd', que es lo unico que se puede sumar entre meses:
               dos meses que comparten mes de pago ven el mismo contenedor, asi
               que sus 'cargado_usd' se superponen y su suma no significa nada. */
            $fila['cargado_usd'] = $disponible;

            /* --- La resta, que nunca da negativo -------------------------- */
            $estimacion = $fila['proyectado_usd'] - $disponible;
            $consumido = $disponible;

            if ($estimacion < 0) {
                $fila['exceso_usd'] = -$estimacion;
                $excesoTotal += $fila['exceso_usd'];
                $consumido = $fila['proyectado_usd'];
                $estimacion = 0.0;
            }

            $fila['consumido_usd'] = $consumido;

            /* Se descuenta del saldo lo que ESTE mes efectivamente consumio, y
               SOLO de los contenedores que este mes tenia derecho a usar. El
               sobrante queda disponible para otro mes de recepcion que comparta
               el mismo mes de pago.
               Pasarle los elegibles y no el mes de pago entero no es una
               optimizacion: dos meses de recepcion que comparten mes de pago
               pueden ser de temporadas DISTINTAS, con fechas de calculo
               distintas, asi que cada uno ve un subconjunto distinto. Consumir
               del monton dejaria a un mes gastando el cupo del otro. */
            self::consumir($saldoCargado, $elegibles, $consumido);

            $fila['estimacion_usd'] = $estimacion;

            /* --- El ajuste manual reemplaza la estimacion ------------------ */
            if ($ajuste !== null) {
                $idAjuste = isset($ajuste['id_version']) ? $ajuste['id_version'] : null;

                if ($idAjuste !== null && $idAjuste != $fila['version']['id_version']) {
                    /* LA VERSION OFICIAL CAMBIO DESDE QUE SE CARGO EL AJUSTE.
                       No se aplica: el numero se puso mirando otro presupuesto.
                       Mismo criterio que Comex::descartaCotizacion(), que tira
                       el override cuando el pago cambia de mes. */
                    $fila['estado'] = self::AJUSTE_DESCARTADO;
                    $fila['ajuste'] = $ajuste + ['aplicado' => false];
                    $descartados[] = $m['mes'];
                    $filas[] = self::conNacionalizacion($fila, $nacPct);

                    continue;
                }

                $fila['ajuste'] = $ajuste + ['aplicado' => true];
                $fila['estimacion_usd'] = floatval(
                    isset($ajuste['importe_usd']) ? $ajuste['importe_usd'] : 0);
                $fila['estado'] = self::AJUSTADO;
                $filas[] = self::conNacionalizacion($fila, $nacPct);

                continue;
            }

            /* --- El estado, en el orden de self::ESTADOS ------------------ */
            if ($pct === null) {
                $fila['estado'] = self::SIN_HISTORIA;
            } elseif ($fila['estimacion_usd'] <= self::TOLERANCIA_USD && $disponible > 0) {
                $fila['estado'] = self::CUBIERTO;
            } elseif ($conCotiz !== null && empty($conCotiz[$m['mes_pago']])) {
                $fila['estado'] = self::SIN_COTIZACION;
            }

            /* SIN_COTIZACION tambien viaja como MARCA cuando no gano el estado:
               un mes ajustado y ademas valuado con el mes mas cercano tiene las
               dos cosas que contar, y 'estado' es una sola columna. */
            if ($conCotiz !== null && empty($conCotiz[$m['mes_pago']])
                && $fila['estado'] !== self::SIN_COTIZACION
                && $fila['estimacion_usd'] > self::TOLERANCIA_USD) {
                $fila['marcas'][] = self::SIN_COTIZACION;
            }

            $filas[] = self::conNacionalizacion($fila, $nacPct);
        }

        /* --- Los avisos resumidos para el tablero ------------------------- */
        foreach ($sinPresupuesto as $codigo => $ms) {
            $warnings[] = 'La temporada ' . $codigo . ' no tiene version oficial de presupuesto: '
                . count($ms) . ' mes' . (count($ms) === 1 ? '' : 'es')
                . ' (' . implode(', ', $ms) . ') se proyectan en CERO. Se esta proyectando de MENOS.';
        }

        if (!empty($sinHistoria)) {
            $warnings[] = 'Sin historia de recepciones para ' . count($sinHistoria) . ' mes'
                . (count($sinHistoria) === 1 ? '' : 'es') . ' (' . implode(', ', $sinHistoria)
                . '): la cuota no tiene con que repartir esos meses.';
        }

        if (!empty($descartados)) {
            $warnings[] = 'Se descartaron ' . count($descartados) . ' ajuste'
                . (count($descartados) === 1 ? '' : 's') . ' manual'
                . (count($descartados) === 1 ? '' : 'es') . ' (' . implode(', ', $descartados)
                . '): cambio la version oficial de su temporada desde que se cargaron.';
        }

        if ($excesoTotal > self::TOLERANCIA_USD) {
            $warnings[] = 'Lo ya comprado supera a lo proyectado por ' . self::usd($excesoTotal)
                . '. El exceso NO se compensa contra otros meses: esos meses van en cero.';
        }

        /* LO CARGADO CUYO MES DE PAGO NO ESTA EN LA VENTANA. No es un error ni
           un exceso: son contenedores que se pagan fuera del tramo que se
           proyecta, asi que no hay nada contra lo cual restarlos. Se informa
           porque sin este numero la diferencia contra el padron de Proveedores
           Exterior no se puede explicar desde ninguna pantalla.

           NO se cuenta lo que quedo sin consumir DENTRO de la ventana: eso es
           el exceso, y ya tiene su propio aviso. */
        $mesesDePago = [];

        foreach ($meses as $m) {
            $mesesDePago[$m['mes_pago']] = true;
        }

        $afuera = 0.0;
        $afueraCant = 0;

        foreach ($porPago as $mesPago => $lista) {
            if (isset($mesesDePago[$mesPago])) {
                continue;
            }

            foreach ($lista as $c) {
                $afuera += $c['pendiente_usd'];
                $afueraCant++;
            }
        }

        if ($afueraCant > 0) {
            $notas[] = $afueraCant . ' contenedor' . ($afueraCant === 1 ? '' : 'es')
                . ' ya cargado' . ($afueraCant === 1 ? '' : 's') . ' por ' . self::usd($afuera)
                . ' se pagan fuera de la ventana: no descuentan de ningun mes proyectado.';
        }

        return [
            'meses' => $filas,
            'warnings' => $warnings,
            'notas' => $notas,
            'totales' => self::totales($filas)
        ];
    }

    /**
     * Descuenta $consumido del saldo de los contenedores indicados, en orden.
     *
     * @param array $saldoCargado Por referencia
     * @param array $claves Contenedores que ESTE mes podia usar
     * @param float $consumido
     */
    private static function consumir(&$saldoCargado, $claves, $consumido) {
        if ($consumido <= 0) {
            return;
        }

        foreach ($claves as $clave) {
            if (!isset($saldoCargado[$clave]) || $saldoCargado[$clave] <= 0) {
                continue;
            }

            $toma = min($saldoCargado[$clave], $consumido);
            $saldoCargado[$clave] -= $toma;
            $consumido -= $toma;

            if ($consumido <= 0) {
                return;
            }
        }
    }

    /**
     * La nacionalizacion se DERIVA del FOB ya descontado.
     *
     * POR QUE NO SE DESCUENTA APARTE contra la estimacion de gastos de cada
     * contenedor: verificado contra la base, de 28 contenedores ordenados
     * despues de una fecha de calculo, 27 NO tienen IMPORTE_EST cargado. La
     * estimacion se carga mas tarde en el circuito de Comercio Exterior, asi
     * que descontar contra ella restaria casi cero hoy y saltaria de golpe el
     * dia que alguien la cargue, moviendo el tablero sin que haya cambiado
     * ninguna compra.
     *
     * Derivandola del FOB descontado queda consistente: lo que Crono
     * Nacionalizacion empiece a mostrar de esos contenedores es lo mismo que
     * esta fila dejo de mostrar cuando se descontó su FOB.
     *
     * EL COSTO, Y ESTA ASUMIDO: ese pedazo de nacionalizacion queda ubicado en
     * el mes que deriva de la cuota y no en el FECHA_DESP_ADU real del
     * contenedor, que puede ser otro mes.
     *
     * @param array $fila
     * @param float $nacPct Porcentaje sobre el FOB
     * @return array
     */
    private static function conNacionalizacion($fila, $nacPct) {
        $fila['nacionalizacion_usd'] = $fila['estimacion_usd'] * floatval($nacPct) / 100.0;

        return $fila;
    }

    /** @return array Totales de la grilla */
    private static function totales($filas) {
        $t = self::totalesVacios();

        foreach ($filas as $f) {
            $t['proyectado_usd'] += $f['proyectado_usd'];
            /* Se totaliza lo CONSUMIDO y no lo disponible: ver la nota de
               'cargado_usd'. Sumar lo disponible contaria dos veces un
               contenedor que dos meses de recepcion se disputan. */
            $t['cargado_usd'] += $f['consumido_usd'];
            $t['exceso_usd'] += $f['exceso_usd'];
            $t['estimacion_usd'] += $f['estimacion_usd'];
            $t['nacionalizacion_usd'] += $f['nacionalizacion_usd'];
            $t['por_estado'][$f['estado']] = (isset($t['por_estado'][$f['estado']])
                ? $t['por_estado'][$f['estado']] : 0) + 1;
        }

        return $t;
    }

    /** @return array */
    private static function totalesVacios() {
        return [
            'proyectado_usd' => 0.0,
            'cargado_usd' => 0.0,
            'exceso_usd' => 0.0,
            'estimacion_usd' => 0.0,
            'nacionalizacion_usd' => 0.0,
            'por_estado' => []
        ];
    }

    /** Un importe en dolares, para los avisos */
    private static function usd($n) {
        return 'U$S ' . number_format(floatval($n), 2, ',', '.');
    }

    /* ====================================================================
       UTILIDADES DE FECHA
       ==================================================================== */

    /**
     * Suma meses a una clave 'Y-m'. Acepta negativos.
     *
     * La cuenta va sobre el numero de mes y no sobre una fecha: asi no hay
     * ningun desborde de fin de mes que pueda correr el resultado, que es el
     * riesgo de resolverlo con un '+N month' sobre un dia cualquiera.
     *
     * @param string $mesClave 'Y-m'
     * @param int $n
     * @return string 'Y-m'
     */
    public static function sumarMeses($mesClave, $n) {
        $anio = intval(substr($mesClave, 0, 4));
        $mes = intval(substr($mesClave, 5, 2));

        $total = $anio * 12 + ($mes - 1) + intval($n);

        return sprintf('%04d-%02d', intdiv($total, 12), ($total % 12) + 1);
    }

    /**
     * El dia D de un mes, recortado al ultimo dia si el mes es mas corto.
     *
     * @param string $mesClave 'Y-m'
     * @param int $dia
     * @return string 'Y-m-d'
     */
    public static function fechaEnMes($mesClave, $dia) {
        $anio = intval(substr($mesClave, 0, 4));
        $mes = intval(substr($mesClave, 5, 2));
        $ultimo = intval(date('t', mktime(0, 0, 0, $mes, 1, $anio)));

        return sprintf('%04d-%02d-%02d', $anio, $mes, min(max(1, intval($dia)), $ultimo));
    }

    /**
     * Resta dias corridos a una fecha. Con $dias negativo, suma.
     *
     * @param string $fecha 'Y-m-d'
     * @param int $dias
     * @return string 'Y-m-d'
     */
    public static function restarDias($fecha, $dias) {
        $d = new DateTime($fecha);
        $n = intval($dias);

        $d->modify(($n >= 0 ? '-' : '+') . abs($n) . ' day');

        return $d->format('Y-m-d');
    }
}
