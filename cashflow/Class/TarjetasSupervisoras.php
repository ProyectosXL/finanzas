<?php

require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/TarjetasVencimiento.php';

/**
 * TarjetasSupervisoras
 * La estimacion de los gastos de supervision: cuanto gasta cada supervisora por
 * mes, cuanto de eso es efectivo y cuanto tarjeta, y cuando sale de caja cada
 * parte.
 *
 * TODO LO DE ACA ES PURO. Recibe los gastos ya leidos, el mapa de inflacion y el
 * cronograma de pagos, y devuelve numeros. La lectura vive en
 * Class/GastosSupervision.php. Es lo que permite probar la ventana dinamica, el
 * promedio, la proporcion y el reparto sin montar tres meses de gastos en SQL
 * Server.
 *
 * LA BASE ES SOLO EL HISTORICO
 * ----------------------------
 * EL PRESUPUESTO (RO_T_PRESUPUESTOS_SUPERVISION) SE IGNORA, y es una decision:
 * el presupuesto dice cuanto se AUTORIZO a gastar y el cashflow necesita cuanto
 * se VA A GASTAR. Las dos cosas difieren y la que predice la caja es la segunda.
 *
 * LOS ADELANTOS (FU_T_ADELANTOS_SUPERVISION) TAMPOCO ENTRAN: ese circuito ya no
 * se usa.
 *
 * LA VENTANA SON TRES MESES CALENDARIO COMPLETOS, Y SE CALCULA SOLA
 * ----------------------------------------------------------------
 * Los tres anteriores al mes en curso. Hoy (26/09/2026): junio, julio y agosto de
 * 2026. El mes en curso NO entra, porque esta incompleto: incluirlo bajaria el
 * promedio por los dias que todavia no pasaron, y el promedio bajaria mas cuanto
 * mas cerca del dia 1 se mirara la pantalla.
 *
 * Se calcula con el calendario y no se guarda en ningun parametro: una ventana
 * materializada se desactualiza sola el 1 de cada mes.
 *
 * SIEMPRE SE DIVIDE POR TRES
 * --------------------------
 * Tambien cuando la supervisora tiene gastos en uno o dos meses de la ventana,
 * porque UN MES SIN GASTOS TAMBIEN ES UN DATO: una supervisora que entro en
 * agosto gasta, en promedio, un tercio de lo que gasto en agosto. Dividir por los
 * meses con datos daria su gasto de un mes tipico y proyectaria de mas todos los
 * meses del horizonte.
 *
 * Igual se avisa cuantos meses con datos tiene, porque un promedio sobre un solo
 * mes es mucho menos confiable que uno sobre tres y el numero no lo dice.
 *
 * LA PROPORCION SALE DE LA VENTANA, NO DE CADA MES
 * ------------------------------------------------
 * % efectivo = total efectivo de la ventana / total de la ventana, y % tarjeta es
 * el complemento. Una proporcion por mes haria que un mes atipico -uno en el que
 * casualmente no se uso efectivo- se proyectara hacia adelante para siempre.
 *
 * QUIEN NO SE PROYECTA, Y POR QUE
 * -------------------------------
 *   SIN_SUPERVISORA   el nombre no esta en RO_T_SUPERVISORAS_COMERCIAL
 *   INACTIVA          esta pero con ACTIVA = 0: dejo de trabajar
 *   SIN_IMPORTE       tiene filas en la ventana pero suman cero
 *   SIN_INFLACION     falta el % de algun mes del camino (por mes, no por
 *                     supervisora)
 *
 * En los cuatro el importe queda en null y se avisa con el nombre y el promedio.
 * Nunca en cero: un cero se leeria como "esa supervisora no gasta", que es lo
 * contrario de lo que pasa.
 *
 * EL UNIVERSO SON LAS SUPERVISORAS CON GASTOS EN LA VENTANA
 * --------------------------------------------------------
 * Una supervisora activa SIN gastos en la ventana no genera fila: sale en un
 * aviso con su nombre. Darle una fila con promedio cero la proyectaria en cero
 * por doce meses, que es exactamente el cero que este modulo no pone.
 */
class TarjetasSupervisoras {

    /** Cuantos meses calendario completos entran en la ventana */
    const MESES_VENTANA = 3;

    /**
     * En cuantos pagos iguales sale la parte en efectivo.
     *
     * Son los dos pagos del cronograma de Parametros -> Generales, el mismo que
     * usa Logistica Local: el 2do y el 4to viernes, corridos al dia habil
     * ANTERIOR. Ver CronogramaPagos.
     */
    const PAGOS_EFECTIVO = 2;

    /* Los motivos por los que una fila no proyecta */
    const OK = 'OK';
    const SIN_SUPERVISORA = 'SIN_SUPERVISORA';
    const INACTIVA = 'INACTIVA';
    const SIN_IMPORTE = 'SIN_IMPORTE';
    const SIN_INFLACION = 'SIN_INFLACION';

    /** La parte tarjeta no tiene donde caer */
    const SIN_TARJETA = 'SIN_TARJETA';
    const VARIAS_TARJETAS = 'VARIAS_TARJETAS';

    /* ====================================================================
       LA VENTANA
       ==================================================================== */

    /**
     * Los tres meses calendario COMPLETOS anteriores al mes en curso.
     *
     * SE CALCULA CON EL CALENDARIO, no se guarda: una ventana materializada se
     * desactualiza sola el 1 de cada mes.
     *
     * Devuelve tambien 'desde' y 'hasta' como fechas, que es lo que la consulta
     * necesita, y 'base', que es el mes en cuya moneda esta el promedio y desde
     * el que se compone la inflacion.
     *
     * Estatica y pura.
     *
     * @param string|null $hoy 'Y-m-d'; por defecto hoy
     * @return array ['meses' => ['Y-m'], 'base' => 'Y-m', 'desde' => 'Y-m-d',
     *                'hasta' => 'Y-m-d', 'rotulo' => string]
     */
    public static function ventana($hoy = null) {
        $hoyStr = ($hoy === null || $hoy === '') ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $mesActual = substr($hoyStr, 0, 7);

        $meses = [];

        /* De atras hacia adelante para que salgan en orden cronologico, que es
           como se lee el cartel de la pestana ("jun-26 a ago-26"). */
        for ($i = self::MESES_VENTANA; $i >= 1; $i--) {
            $meses[] = Inflacion::mesMas($mesActual, -$i);
        }

        $primero = $meses[0];
        $ultimo = $meses[count($meses) - 1];
        $partes = explode('-', $ultimo);

        return [
            'meses' => $meses,

            /* EL MES BASE ES EL ULTIMO DE LA VENTANA. El promedio esta en moneda
               de ese mes -es el mas reciente que lo formo- asi que la inflacion
               se compone desde el mes SIGUIENTE. Tomar el primero de la ventana
               ajustaria de mas por dos meses. */
            'base' => $ultimo,

            'desde' => $primero . '-01',
            'hasta' => sprintf('%s-%02d', $ultimo,
                intval(date('t', mktime(0, 0, 0, intval($partes[1]), 1, intval($partes[0]))))),
            'rotulo' => self::rotuloMes($primero) . ' a ' . self::rotuloMes($ultimo)
        ];
    }

    /**
     * El cartel que explica la ventana y el mes base.
     *
     * LO ARMA EL BACKEND porque describe una cuenta que hace el backend. Con el
     * texto en el front, cambiar la ventana obligaria a cambiarlo en dos lados y
     * el segundo se olvida. Mismo criterio que LogisticaValorHora::explicar().
     *
     * Estatica y pura.
     *
     * @param array $ventana Lo que devolvio ventana()
     * @return string
     */
    public static function explicarVentana($ventana) {
        return 'Promedio ' . $ventana['rotulo'] . ', gastos autorizados. Siempre se divide por '
            . self::MESES_VENTANA . ' meses, también cuando la supervisora tiene gastos en menos: '
            . 'un mes sin gastos también es un dato. La inflación se compone desde '
            . $ventana['base'] . ', que es el mes en cuya moneda está el promedio.';
    }

    /* ====================================================================
       EL PROMEDIO Y LA PROPORCION
       ==================================================================== */

    /**
     * El promedio mensual y la proporcion efectivo/tarjeta de cada supervisora.
     *
     * RECIBE LOS GASTOS YA FILTRADOS: solo autorizados (ESTADO = 1) y solo de la
     * ventana. Ese filtro vive en la consulta, porque es donde se puede hacer sin
     * traer diez mil filas, y es el MISMO que usa el dashboard de supervision.
     *
     * SIEMPRE DIVIDE POR TRES. Ver el encabezado.
     *
     * Estatica y pura.
     *
     * @param array $gastos Filas con 'supervisora', 'mes' ('Y-m'), 'efectivo',
     *                      'tarjeta'
     * @param array $ventana Lo que devolvio ventana()
     * @return array Mapa nombre => ['nombre', 'efectivo', 'tarjeta', 'total',
     *               'promedio', 'meses_con_datos', 'pct_efectivo', 'pct_tarjeta',
     *               'por_mes' => ['Y-m' => total]]
     */
    public static function promedios($gastos, $ventana) {
        $enVentana = array_fill_keys($ventana['meses'], true);
        $acum = [];

        foreach (is_array($gastos) ? $gastos : [] as $g) {
            $nombre = trim((string) (isset($g['supervisora']) ? $g['supervisora'] : ''));
            $mes = trim((string) (isset($g['mes']) ? $g['mes'] : ''));

            if ($nombre === '') {
                continue;
            }

            /* SE VUELVE A ACOTAR A LA VENTANA aunque la consulta ya lo haga. No
               es desconfianza: esta funcion se prueba con listas armadas a mano y
               la tolerancia a un mes de mas la hace usable tambien para el
               detalle de la pantalla, que trae mas meses. */
            if (!isset($enVentana[$mes])) {
                continue;
            }

            if (!isset($acum[$nombre])) {
                $acum[$nombre] = [
                    'nombre' => $nombre,
                    'efectivo' => 0.0,
                    'tarjeta' => 0.0,
                    'por_mes' => array_fill_keys($ventana['meses'], 0.0)
                ];
            }

            $efectivo = isset($g['efectivo']) ? floatval($g['efectivo']) : 0.0;
            $tarjeta = isset($g['tarjeta']) ? floatval($g['tarjeta']) : 0.0;

            $acum[$nombre]['efectivo'] += $efectivo;
            $acum[$nombre]['tarjeta'] += $tarjeta;
            $acum[$nombre]['por_mes'][$mes] += $efectivo + $tarjeta;
        }

        $salida = [];

        foreach ($acum as $nombre => $a) {
            $total = $a['efectivo'] + $a['tarjeta'];

            /* CUANTOS MESES DE LA VENTANA TIENEN GASTOS. No cambia el promedio
               -siempre se divide por tres- pero es lo que dice cuanto confiar en
               el: un promedio sobre un solo mes es mucho menos firme que uno
               sobre tres, y el numero no lo dice. */
            $conDatos = 0;

            foreach ($a['por_mes'] as $v) {
                if ($v != 0) {
                    $conDatos++;
                }
            }

            /* LA PROPORCION NECESITA UN TOTAL DISTINTO DE CERO. Con total cero no
               hay proporcion que calcular -no es 0 % ni 100 %, es indefinida- y
               dividir daria un error o un NaN que viajaria hasta el tablero. */
            $pctEfectivo = ($total > 0) ? ($a['efectivo'] / $total) : null;

            $salida[$nombre] = [
                'nombre' => $nombre,
                'efectivo' => round($a['efectivo'], 2),
                'tarjeta' => round($a['tarjeta'], 2),
                'total' => round($total, 2),

                /* NO SE REDONDEA EL PROMEDIO. El redondeo es presentacion, y
                   redondear aca lo arrastraria a los doce meses multiplicado por
                   el factor de inflacion. */
                'promedio' => ($total > 0) ? ($total / self::MESES_VENTANA) : null,

                'meses_con_datos' => $conDatos,
                'pct_efectivo' => $pctEfectivo,

                /* EL COMPLEMENTO, no una segunda division: asi las dos partes
                   suman exactamente el estimado, sin un centavo perdido en el
                   redondeo de dos cocientes. */
                'pct_tarjeta' => ($pctEfectivo === null) ? null : (1 - $pctEfectivo),

                'por_mes' => $a['por_mes']
            ];
        }

        /* En orden alfabetico: es como se lee la grilla y como sale el cartel. */
        ksort($salida);

        return $salida;
    }

    /* ====================================================================
       LA ESTIMACION MES A MES
       ==================================================================== */

    /**
     * Lo que se estima para cada supervisora en cada mes del horizonte.
     *
     * estimado(m) = promedio x Π (1 + inf_k / 100), con k desde el mes siguiente
     * al base hasta m. La composicion la hace Inflacion::compuesta(), que es la
     * misma funcion que usa Tarjetas Socios.
     *
     * CADA MES SE RESUELVE SOLO. Si falta la inflacion de octubre, octubre y los
     * siguientes quedan en null y septiembre se proyecta igual: lo que se cae es
     * lo que necesita el dato que falta, no la fila entera.
     *
     * EL ESTADO DE LA SUPERVISORA MANDA SOBRE TODO. Una inactiva no proyecta
     * ningun mes, aunque la inflacion este completa.
     *
     * Estatica y pura.
     *
     * @param array $promedios Lo que devolvio promedios()
     * @param array $meses Los meses del horizonte, 'Y-m'
     * @param array $inflacion Mapa 'Y-m' => float, en puntos
     * @param array $ventana Lo que devolvio ventana()
     * @param array $estados Mapa nombre => ['activa' => bool, 'en_maestro' => bool]
     * @return array Mapa nombre => ['nombre', 'promedio', 'pct_efectivo',
     *               'pct_tarjeta', 'motivo', 'proyecta' => bool,
     *               'meses' => ['Y-m' => celda], 'faltan_inflacion' => ['Y-m']]
     */
    public static function estimar($promedios, $meses, $inflacion, $ventana, $estados = []) {
        $factores = Inflacion::compuestaParaMeses($inflacion, $ventana['base'], $meses);
        $salida = [];

        foreach ($promedios as $nombre => $p) {
            $motivo = self::motivoDe($nombre, $p, $estados);
            $proyecta = ($motivo === self::OK);

            $fila = [
                'nombre' => $nombre,
                'promedio' => $p['promedio'],
                'pct_efectivo' => $p['pct_efectivo'],
                'pct_tarjeta' => $p['pct_tarjeta'],
                'total_ventana' => $p['total'],
                'meses_con_datos' => $p['meses_con_datos'],
                'motivo' => $motivo,
                'proyecta' => $proyecta,
                'meses' => [],
                'faltan_inflacion' => []
            ];

            foreach ($meses as $mes) {
                $f = $factores['factores'][$mes];

                /* DOS MOTIVOS DISTINTOS Y NO SE MEZCLAN: la supervisora puede no
                   proyectar por su estado, o el mes puede no resolverse por la
                   inflacion. El primero gana, porque si no proyecta ningun mes,
                   decir "falta la inflacion de octubre" mandaria a cargar un dato
                   que no cambiaria nada. */
                if (!$proyecta) {
                    $fila['meses'][$mes] = self::celdaVacia($mes, $motivo, $f);
                    continue;
                }

                if ($f['factor'] === null) {
                    $fila['meses'][$mes] = self::celdaVacia($mes, self::SIN_INFLACION, $f);

                    foreach ($f['faltan'] as $m) {
                        if (!in_array($m, $fila['faltan_inflacion'], true)) {
                            $fila['faltan_inflacion'][] = $m;
                        }
                    }

                    continue;
                }

                $estimado = $p['promedio'] * $f['factor'];

                /* NO SE REDONDEA NINGUNA DE LAS TRES CIFRAS. El redondeo es
                   presentacion; redondear aca haria que efectivo + tarjeta no
                   diera exactamente el estimado en los centavos, y esa diferencia
                   se acumularia en los doce meses del horizonte. */
                $fila['meses'][$mes] = [
                    'mes' => $mes,
                    'estimado' => $estimado,
                    'efectivo' => $estimado * $p['pct_efectivo'],
                    'tarjeta' => $estimado * $p['pct_tarjeta'],
                    'factor' => $f['factor'],
                    'motivo' => self::OK,
                    'tooltip' => self::explicarCelda($p, $ventana, $f, $estimado)
                ];
            }

            $salida[$nombre] = $fila;
        }

        return $salida;
    }

    /**
     * Por que una supervisora no proyecta, o OK si proyecta.
     *
     * EL ORDEN ES EL DE LA CAUSA MAS DE FONDO: si no esta en el maestro, decir
     * que esta inactiva seria afirmar algo que no se sabe; si esta inactiva, el
     * importe cero es irrelevante.
     *
     * Estatica y pura.
     *
     * @param string $nombre
     * @param array $p La fila de promedios()
     * @param array $estados Mapa nombre => ['activa', 'en_maestro']
     * @return string
     */
    public static function motivoDe($nombre, $p, $estados) {
        $estado = isset($estados[$nombre]) ? $estados[$nombre] : null;

        if ($estado === null || empty($estado['en_maestro'])) {
            return self::SIN_SUPERVISORA;
        }

        if (empty($estado['activa'])) {
            return self::INACTIVA;
        }

        if ($p['promedio'] === null || $p['total'] <= 0) {
            return self::SIN_IMPORTE;
        }

        return self::OK;
    }

    /** Una celda sin importe, con su motivo. La estructura es la misma que la llena */
    private static function celdaVacia($mes, $motivo, $factor) {
        return [
            'mes' => $mes,
            'estimado' => null,
            'efectivo' => null,
            'tarjeta' => null,
            'factor' => $factor['factor'],
            'motivo' => $motivo,
            'tooltip' => self::explicarMotivo($motivo, $factor)
        ];
    }

    /**
     * El tooltip de una celda con importe: de donde sale el numero.
     *
     * LLEVA LAS TRES COSAS -promedio, factor de inflacion acumulado y proporcion-
     * porque son las tres que hay que poder verificar. Un importe sin eso es un
     * numero que nadie puede explicar.
     *
     * Estatica y pura.
     */
    public static function explicarCelda($p, $ventana, $factor, $estimado) {
        $texto = 'Promedio ' . $ventana['rotulo'] . ': ' . self::plata($p['promedio'])
            . ' por mes (' . self::plata($p['total']) . ' / ' . self::MESES_VENTANA . ').';

        if ($p['meses_con_datos'] < self::MESES_VENTANA) {
            $texto .= ' Tiene gastos en ' . $p['meses_con_datos'] . ' de los '
                . self::MESES_VENTANA . ' meses; igual se divide por ' . self::MESES_VENTANA
                . ', porque un mes sin gastos también es un dato.';
        }

        if (!empty($factor['meses'])) {
            $detalle = [];

            foreach ($factor['meses'] as $m => $pct) {
                $detalle[] = $m . ': ' . self::pct($pct) . ' %';
            }

            $texto .= ' Inflación acumulada desde ' . $ventana['base'] . ': x'
                . number_format($factor['factor'], 4, ',', '.')
                . ' (' . implode(' · ', $detalle) . ', compuesta).';
        } else {
            $texto .= ' Es el mes base, así que no lleva ajuste por inflación.';
        }

        $texto .= ' Estimado ' . self::plata($estimado) . ': ' . self::pct($p['pct_efectivo'] * 100)
            . ' % efectivo y ' . self::pct($p['pct_tarjeta'] * 100) . ' % tarjeta, que es la '
            . 'proporción de la ventana.';

        return $texto;
    }

    /**
     * El tooltip de una celda sin importe.
     *
     * Estatica y pura.
     */
    public static function explicarMotivo($motivo, $factor = null) {
        switch ($motivo) {
            case self::SIN_SUPERVISORA:
                return 'Este nombre no está en el maestro de supervisoras '
                    . '(RO_T_SUPERVISORAS_COMERCIAL), así que no se puede saber si sigue '
                    . 'trabajando. No se proyecta; el gasto está y se ve.';

            case self::INACTIVA:
                return 'La supervisora está inactiva en el maestro: dejó de trabajar, así que su '
                    . 'gasto histórico no describe lo que va a salir de caja. No se proyecta.';

            case self::SIN_IMPORTE:
                return 'Tiene gastos autorizados en la ventana pero suman cero, así que no hay '
                    . 'promedio ni proporción que calcular.';

            case self::SIN_INFLACION:
                return 'Falta la inflación esperada de '
                    . (($factor !== null && !empty($factor['faltan']))
                        ? implode(', ', $factor['faltan']) : 'algún mes')
                    . ', así que este mes no se puede llevar a moneda de su fecha. Queda en '
                    . 'blanco y NO en cero: un cero se leería como que no hay nada que pagar.';
        }

        return '';
    }

    /* ====================================================================
       CUANDO SALE DE CAJA
       ==================================================================== */

    /**
     * El reparto de la parte en EFECTIVO: dos pagos iguales en las fechas del
     * cronograma del mes.
     *
     * ES EL MISMO CRONOGRAMA QUE LOGISTICA LOCAL -el 2do y el 4to viernes de
     * Parametros -> Generales, con sus overrides- y por eso recibe los pagos ya
     * resueltos en vez de calcularlos: la regla vive en CronogramaPagos y una
     * segunda version aca se desincronizaria en el primer feriado nuevo.
     *
     * LAS DOS MITADES NO SE REDONDEAN. El redondeo es presentacion, y redondear
     * aca haria que un importe impar perdiera un centavo por mes, todos los meses.
     *
     * UN PAGO CON FECHA ANTERIOR O IGUAL A HOY NO SE PROYECTA. Esa plata ya salio
     * y ya esta reflejada en el saldo bancario que abre el cuadro; proyectarla
     * seria pedir dos veces la misma plata. Es el mismo criterio de Logistica
     * Local, y tiene la misma consecuencia: la columna de hoy nunca recibe nada de
     * la parte en efectivo.
     *
     * SIEMPRE SE DEVUELVEN LOS DOS PAGOS, tambien cuando no hay importe y tambien
     * los excluidos: la fecha es un dato del cronograma y no de la supervisora, y
     * esconderla cuando falta el importe obliga a buscarla en otra pantalla. Quien
     * acumula contra el eje filtra por 'proyecta'.
     *
     * Estatica y pura.
     *
     * @param float|null $importe La parte en efectivo del mes
     * @param array $delMes Los pagos del mes, mapa nro => pago de CronogramaPagos
     * @param string $hoy 'Y-m-d'
     * @return array Lista de pagos
     */
    public static function repartirEfectivo($importe, $delMes, $hoy) {
        $pagos = [];
        $hoyStr = substr((string) $hoy, 0, 10);

        foreach ($delMes as $nro => $p) {
            $mitad = ($importe === null) ? null : $importe / self::PAGOS_EFECTIVO;
            $excluido = ($p['fecha'] <= $hoyStr);

            $pagos[] = [
                'mes' => $p['mes'],
                'nro' => intval($nro),
                'fecha' => $p['fecha'],
                'importe' => $mitad,
                'en_tramo' => !empty($p['en_tramo']),
                'override' => !empty($p['override']),
                'corrida' => !empty($p['corrida']),
                'proyecta' => (!$excluido && $mitad !== null),
                'motivo' => $excluido
                    ? (($p['fecha'] === $hoyStr) ? 'Es hoy: ese pago ya se hizo.'
                                                 : 'Ya pasó: ese pago ya se hizo.')
                    : (($mitad === null) ? 'No hay importe estimado para este mes.' : null)
            ];
        }

        return $pagos;
    }

    /**
     * La fecha y el importe de la parte TARJETA de un mes.
     *
     * TRES CASOS, Y LOS TRES SON DISTINTOS:
     *
     *   1. HAY RESUMEN CARGADO para esa tarjeta y ese mes: manda el resumen, con
     *      su importe Y su fecha. La estimacion no se proyecta. Si el resumen
     *      esta PAGADO, tampoco se proyecta el resumen: ya salio.
     *
     *   2. NO HAY RESUMEN: se proyecta la estimacion, con la cobertura aplicada,
     *      en el DIA_VENCIMIENTO de la tarjeta corrido al primer habil siguiente.
     *      Si esa fecha es anterior o igual a hoy NO se proyecta y se avisa: el
     *      debito de ese mes ya paso y el resumen todavia no se cargo, asi que hay
     *      un dato que falta.
     *
     *   3. NO HAY TARJETA: no se proyecta nada y se avisa con el importe. Sin
     *      tarjeta no hay dia de vencimiento, y no se inventa una fecha.
     *
     * LA COBERTURA SE APLICA SOBRE LA ESTIMACION Y NO SOBRE EL RESUMEN. El resumen
     * es lo que el banco efectivamente va a debitar: multiplicarlo por un
     * porcentaje seria corregir un hecho.
     *
     * Estatica y pura.
     *
     * @param float|null $importe La parte tarjeta estimada del mes
     * @param array|null $tarjeta La tarjeta asociada, o null
     * @param array|null $vencimiento Lo que devolvio TarjetasVencimiento::delMes()
     * @param array|null $resumen El resumen vigente de ese mes, o null
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha', 'importe', 'proyecta' => bool, 'origen', 'motivo',
     *                'pagado' => bool]
     */
    public static function tarjetaDelMes($importe, $tarjeta, $vencimiento, $resumen, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);

        /* 1. EL RESUMEN MANDA. Importe Y fecha: las dos cosas son lo real. */
        if ($resumen !== null) {
            $pagado = !empty($resumen['PAGADO']);

            return [
                'fecha' => $resumen['FECHA_VENCIMIENTO'],
                'importe' => isset($resumen['IMPORTE_ARS']) ? $resumen['IMPORTE_ARS'] : null,
                'estimado' => $importe,
                'proyecta' => (!$pagado && !empty($resumen['IMPORTE_ARS'])),
                'origen' => 'RESUMEN',
                'pagado' => $pagado,
                'motivo' => $pagado
                    ? 'El resumen está marcado como pagado: sale del horizonte, porque esa plata '
                      . 'ya está reflejada en el saldo bancario.'
                    : null
            ];
        }

        /* 3. SIN TARJETA NO HAY FECHA. Se avisa con el importe y no se inventa
              nada: apilarlo en el primer dia del eje seria afirmar que se paga
              hoy. */
        if ($tarjeta === null) {
            return [
                'fecha' => null,
                'importe' => $importe,
                'estimado' => $importe,
                'proyecta' => false,
                'origen' => 'ESTIMACION',
                'pagado' => false,
                'motivo' => self::SIN_TARJETA
            ];
        }

        if ($importe === null || $vencimiento === null) {
            return [
                'fecha' => ($vencimiento === null) ? null : $vencimiento['fecha'],
                'importe' => null,
                'estimado' => null,
                'proyecta' => false,
                'origen' => 'ESTIMACION',
                'pagado' => false,
                'motivo' => null
            ];
        }

        /* 2. LA ESTIMACION, CON LA COBERTURA APLICADA. */
        $conCobertura = $importe * (1 + floatval($tarjeta['PCT_COBERTURA']) / 100);
        $vencida = ($vencimiento['fecha'] <= $hoyStr);

        return [
            'fecha' => $vencimiento['fecha'],
            'importe' => $conCobertura,
            'estimado' => $importe,
            'proyecta' => !$vencida,
            'origen' => 'ESTIMACION',
            'pagado' => false,
            'motivo' => $vencida
                ? 'El vencimiento de este mes ya pasó y todavía no se cargó el resumen, así que '
                  . 'no se proyecta: hay un dato que falta, no un pago que no va a salir.'
                : null
        ];
    }

    /* ====================================================================
       PARA LA PANTALLA
       ==================================================================== */

    /** 'Y-m' => 'jun-26' */
    public static function rotuloMes($mes) {
        $partes = explode('-', (string) $mes);

        if (count($partes) < 2) {
            return (string) $mes;
        }

        $abrev = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
                  7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
        $m = intval($partes[1]);

        return (isset($abrev[$m]) ? $abrev[$m] : $partes[1]) . '-' . substr($partes[0], 2);
    }

    /** Un importe con el formato del modulo */
    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }

    /** Un porcentaje con dos decimales */
    private static function pct($n) {
        return ($n === null) ? '—' : number_format($n, 2, ',', '.');
    }
}
