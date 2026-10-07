<?php

/**
 * DiasHabiles
 * Correr una fecha al primer dia habil SIGUIENTE, con el respaldo de lunes a
 * viernes cuando la fecha no esta en RO_T_CALENDARIO.
 *
 * POR QUE EXISTE
 * --------------
 * La mecanica estaba escrita dos veces, letra por letra: Ventas::proximoHabil()
 * -la acreditacion de una cobranza- y TarjetasVencimiento::habilSiguiente() -el
 * debito de un resumen-. La caja de los locales iba a ser la tercera copia. Dos
 * copias de un respaldo terminan en dos calendarios: el dia que una cambia, la
 * cobranza y el debito caen en fechas que no coinciden justo en los meses sin
 * dato. Y ya se habian separado en algo: el aviso de calendario faltante de
 * Ventas salia sin tildes, asi que la misma causa se leia como dos problemas.
 *
 * QUE SE COMPARTE Y QUE NO
 * ------------------------
 * Se comparte la MECANICA de "el proximo habil", no la DECISION de correr hacia
 * adelante: cada regla sigue eligiendo su direccion por la funcion que llama.
 * Por eso CronogramaPagos::habilAnterior() -un pago se adelanta- no vive aca. Si
 * manana el debito de una tarjeta pasa a adelantarse, Tarjetas deja de llamar a
 * siguiente() y Ventas no se entera.
 *
 * NUNCA LANZA
 * -----------
 * Si en MAX_CORRIMIENTO dias no aparece ningun habil, el mapa esta mal: ningun
 * feriado encadena treinta dias. El helper no decide que hacer con eso, porque
 * los que lo llaman no pueden reaccionar igual. Devuelve la fecha del tope con
 * 'sin_habil' = true y cada uno decide: Tarjetas lanza, porque una fecha del
 * tope escondería el problema detras de una fecha plausible; Ventas sigue con
 * un aviso, porque la pantalla de Ventas y el tablero nunca se caen; Saldos
 * devuelve null y avisa. Lanzar aca obligaria a Ventas a atrapar una excepcion
 * para hacer lo que hacia antes.
 *
 * NO TOCA LA BASE
 * ---------------
 * Recibe el mapa 'Y-m-d' => bool ya leido -la unica lectura de RO_T_CALENDARIO
 * es Ventas::getDiasHabiles(), por CronogramaDatos::habilesEntre()- y devuelve
 * fechas. Estatica y pura: se prueba entera sin calendario en SQL Server.
 */
class DiasHabiles {

    /**
     * Tope del corrimiento. Mismo valor que CronogramaPagos::MAX_CORRIMIENTO,
     * que es el de la direccion contraria.
     */
    const MAX_CORRIMIENTO = 30;

    /**
     * El primer dia habil igual o posterior a una fecha.
     *
     * Una fecha que no esta en el mapa se toma como habil si cae de lunes a
     * viernes, y su mes queda en 'faltan' para que quien llama avise: el mismo
     * respaldo que tenian Ventas, Tarjetas y el cronograma de pagos.
     *
     * @param string $fecha 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array ['fecha' => 'Y-m-d', 'corrida' => bool, 'faltan' => ['Y-m'],
     *                'sin_habil' => bool]. Con 'sin_habil', 'fecha' es la del
     *                tope: MAX_CORRIMIENTO dias despues de $fecha.
     */
    public static function siguiente($fecha, $habiles) {
        $cursor = substr((string) $fecha, 0, 10);
        $habiles = is_array($habiles) ? $habiles : [];
        $faltan = [];

        for ($i = 0; $i < self::MAX_CORRIMIENTO; $i++) {
            if (isset($habiles[$cursor])) {
                if ($habiles[$cursor]) {
                    return self::resultado($cursor, $i > 0, $faltan, false);
                }
            } else {
                $mes = substr($cursor, 0, 7);

                if (!in_array($mes, $faltan, true)) {
                    $faltan[] = $mes;
                }

                // El respaldo: lunes a viernes se consideran habiles.
                if (intval(date('N', strtotime($cursor))) <= 5) {
                    return self::resultado($cursor, $i > 0, $faltan, false);
                }
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return self::resultado($cursor, true, $faltan, true);
    }

    /**
     * El aviso de calendario faltante, uno por mes.
     *
     * LA REDACCION ES UNA SOLA en todo el modulo: es el mismo hecho
     * -RO_T_CALENDARIO no llega hasta ahi- y dos textos para la misma causa se
     * leen como dos problemas. CronogramaPagos::avisosCalendario() la repite
     * porque su corrimiento va para el otro lado; el texto es identico.
     *
     * @param array $meses Lista de 'Y-m'
     * @return array Lista de mensajes
     */
    public static function avisosCalendario($meses) {
        $avisos = [];

        foreach ((is_array($meses) ? $meses : []) as $mes) {
            $avisos[] = 'RO_T_CALENDARIO no tiene datos para ' . $mes
                . '. Se asumen hábiles los días de lunes a viernes.';
        }

        return $avisos;
    }

    /**
     * El texto de "no hay ningun habil en el tope". Tarjetas lo lanza como
     * excepcion y Ventas y Saldos lo dejan como aviso: es la misma frase en los
     * tres, por el mismo motivo que avisosCalendario().
     *
     * @param string $fecha 'Y-m-d' desde la que se busco
     * @return string
     */
    public static function avisoSinHabil($fecha) {
        return 'No se encontró ningún día hábil en los ' . self::MAX_CORRIMIENTO
            . ' días siguientes a ' . substr((string) $fecha, 0, 10) . '. Revisá RO_T_CALENDARIO.';
    }

    private static function resultado($fecha, $corrida, $faltan, $sinHabil) {
        return ['fecha' => $fecha, 'corrida' => $corrida, 'faltan' => $faltan,
                'sin_habil' => $sinHabil];
    }
}
