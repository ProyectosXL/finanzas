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
