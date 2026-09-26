<?php

/**
 * TarjetasVencimiento
 * Cuando vence el resumen de una tarjeta: el dia del mes que tiene cargado,
 * acotado al ultimo dia si el mes es mas corto, y corrido al primer dia habil
 * SIGUIENTE si no es habil.
 *
 * POR QUE ES UNA CLASE APARTE, Y NO UN METODO DE CronogramaPagos
 * -------------------------------------------------------------
 * Porque el corrimiento va para el otro lado, y eso no es un detalle de
 * implementacion: es la identidad de cada una de las dos reglas.
 *
 *   CronogramaPagos    un pago a un fletero se corre al dia habil ANTERIOR: el
 *                      compromiso es con una persona que cobra esa semana, asi
 *                      que si el viernes es feriado se le paga antes.
 *
 *   esta clase         el vencimiento de un resumen se corre al dia habil
 *                      SIGUIENTE: el banco no puede debitar un dia que no
 *                      opera, asi que el debito cae despues.
 *
 * El encabezado de CronogramaPagos dice explicitamente que las dos direcciones
 * no comparten codigo, y agregarle la de adelante lo convertiria en "las fechas
 * de pago, para los dos lados", que ya no describe una regla sino un cajon.
 *
 * ES LA MISMA DIRECCION QUE VENTAS, Y TAMPOCO COMPARTE CODIGO CON ELLA
 * --------------------------------------------------------------------
 * Ventas::proximoHabil() corre una acreditacion de cobranza al proximo dia
 * habil, por un motivo distinto -el banco no acredita antes de poder hacerlo- y
 * es privado. Que dos reglas coincidan hoy en la direccion no las hace la misma
 * regla: si manana el debito de una tarjeta pasa a adelantarse, una sola
 * funcion compartida moveria tambien las acreditaciones de Ventas.
 *
 * LO QUE SI SE COMPARTE ES EL CALENDARIO, y eso es lo que no puede estar escrito
 * dos veces: los dias habiles salen de Ventas::getDiasHabiles() -la unica
 * lectura de RO_T_CALENDARIO del modulo- por CronogramaDatos::habilesEntre().
 * Dos lecturas serian dos definiciones de "dia habil" esperando a
 * desincronizarse.
 *
 * NO TOCA LA BASE
 * ---------------
 * Recibe el mapa de dias habiles ya leido y devuelve fechas. Es lo que permite
 * probar entera la parte delicada -los meses cortos, el fin de semana, el
 * feriado encadenado, el cruce de mes y de ano- sin montar un calendario en SQL
 * Server. Mismo criterio que CronogramaPagos e Inflacion.
 *
 * SOLO SE CORRE LA ESTIMACION, NUNCA UN RESUMEN CARGADO
 * -----------------------------------------------------
 * La fecha de un resumen se tipea leyendola del papel: es un hecho, y correrla
 * seria contradecir a quien la leyo. Esta clase resuelve la fecha de la
 * ESTIMACION, que es la que el codigo calcula para los meses sin resumen.
 *
 * SI FALTA LA FECHA EN EL CALENDARIO, EL MISMO FALLBACK QUE EL RESTO DEL MODULO
 * ----------------------------------------------------------------------------
 * RO_T_CALENDARIO esta poblada hasta el 31/12/2027. Una fecha que no este no
 * rompe: se asume habil de lunes a viernes y se deja un aviso, deduplicado por
 * mes. Es literalmente lo que hacen Ventas::proximoHabil() y
 * CronogramaPagos::habilAnterior(), y tiene que ser lo mismo: dos fallbacks
 * distintos pondrian las fechas en calendarios que no coinciden justo en los
 * meses en los que no hay dato.
 */
class TarjetasVencimiento {

    /** Rango valido del dia del mes. El 31 vale: lo acota el mes, no el campo */
    const DIA_MIN = 1;
    const DIA_MAX = 31;

    /**
     * Tope defensivo del corrimiento.
     *
     * Ningun feriado encadena treinta dias no habiles. Si se llega al tope el
     * mapa de habiles esta mal, y devolver la fecha del tope escondería el
     * problema detras de una fecha plausible. Mismo valor y mismo criterio que
     * CronogramaPagos::MAX_CORRIMIENTO.
     */
    const MAX_CORRIMIENTO = 30;

    /**
     * Cuantos dias despues de fin() puede caer un vencimiento corrido.
     *
     * El corrimiento de un dia 31 del ultimo mes del eje puede terminar en el
     * mes siguiente, que ya esta fuera del horizonte. Pedirle al calendario ese
     * mes de mas no cuesta nada y evita que el fallback se dispare por un rango
     * corto, que dejaria un aviso de calendario faltante que no describe ningun
     * problema real.
     */
    const DIAS_COLA = self::MAX_CORRIMIENTO;

    /* ====================================================================
       VALIDACION
       ==================================================================== */

    /**
     * El dia del mes, validado.
     *
     * LA VALIDACION QUE VALE ES ESTA, no la del <input type="number">: el
     * endpoint es alcanzable sin pasar por la pantalla. El CHECK de la tabla es
     * la tercera red.
     *
     * Estatica y pura.
     *
     * @param mixed $dia
     * @return int 1..31
     * @throws Exception si no es un entero del rango
     */
    public static function validarDia($dia) {
        if ($dia === null || $dia === '' || !is_numeric($dia)) {
            throw new Exception('El día de vencimiento del resumen tiene que ser un número '
                . 'del 1 al ' . self::DIA_MAX . '.');
        }

        $d = intval($dia);

        if ($d != floatval($dia) || $d < self::DIA_MIN || $d > self::DIA_MAX) {
            throw new Exception('El día de vencimiento tiene que estar entre ' . self::DIA_MIN
                . ' y ' . self::DIA_MAX . '. Se recibió ' . $dia . '.');
        }

        return $d;
    }

    /* ====================================================================
       LA REGLA
       ==================================================================== */

    /**
     * Corre una fecha al primer dia habil SIGUIENTE, si no es habil.
     *
     * Hermana de CronogramaPagos::habilAnterior() y deliberadamente separada de
     * ella: ver el encabezado.
     *
     * @param string $fecha 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array ['fecha' => 'Y-m-d', 'corrida' => bool, 'faltan' => ['Y-m']]
     */
    public static function habilSiguiente($fecha, $habiles) {
        $cursor = substr((string) $fecha, 0, 10);
        $faltan = [];

        for ($i = 0; $i < self::MAX_CORRIMIENTO; $i++) {
            if (isset($habiles[$cursor])) {
                if ($habiles[$cursor]) {
                    return ['fecha' => $cursor, 'corrida' => ($i > 0), 'faltan' => $faltan];
                }
            } else {
                $mes = substr($cursor, 0, 7);

                if (!in_array($mes, $faltan, true)) {
                    $faltan[] = $mes;
                }

                // Fallback: lunes a viernes se consideran habiles, igual que
                // Ventas y que el cronograma de pagos.
                if (intval(date('N', strtotime($cursor))) <= 5) {
                    return ['fecha' => $cursor, 'corrida' => ($i > 0), 'faltan' => $faltan];
                }
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        throw new Exception('No se encontró ningún día hábil en los ' . self::MAX_CORRIMIENTO
            . ' días siguientes a ' . $fecha . '. Revisá RO_T_CALENDARIO.');
    }

    /**
     * Cuando vence el resumen de una tarjeta en un mes.
     *
     * LA REGLA COMPLETA, EN DOS PASOS Y EN ESTE ORDEN:
     *
     *   1. el dia del mes que tiene cargado la tarjeta, ACOTADO al ultimo dia
     *      del mes si el mes no lo tiene (el 31 en febrero es el 28 o el 29)
     *   2. corrido al primer dia habil SIGUIENTE, si ese dia no es habil
     *
     * El orden importa: acotar primero y correr despues es lo unico que hace que
     * un 31 de abril termine en el 30 -o en el 2 de mayo si el 30 es feriado- y
     * no en un 1 de mayo que sale de una fecha que no existe.
     *
     * DEVUELVE SIEMPRE LA MISMA ESTRUCTURA, con las tres fechas y no solo la que
     * vale: la teorica explica de donde sale la final -"el 31 de febrero es el
     * 28, que es sabado"- y sin ella la pantalla muestra un dia que nadie puede
     * justificar. Mismo criterio que CronogramaPagos::delMes().
     *
     * Estatica y pura.
     *
     * @param int $dia Dia del mes de la tarjeta, 1..31
     * @param string $mes 'Y-m'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array ['mes', 'dia_pedido', 'teorica', 'fecha', 'corrida',
     *                'ultimo_dia' => bool, 'faltan' => ['Y-m']]
     */
    public static function delMes($dia, $mes, $habiles) {
        $d = self::validarDia($dia);
        $m = self::validarMes($mes);

        $partes = explode('-', $m);
        $enElMes = intval(date('t', mktime(0, 0, 0, intval($partes[1]), 1, intval($partes[0]))));

        /* EL MES ACOTA, NO EL CAMPO. Una tarjeta que vence el 31 vence el ultimo
           dia de los meses que no tienen 31, que es lo que hace el banco. */
        $efectivo = min($d, $enElMes);
        $teorica = sprintf('%s-%02d', $m, $efectivo);

        $corrida = self::habilSiguiente($teorica, $habiles);

        return [
            'mes' => $m,
            'dia_pedido' => $d,
            'teorica' => $teorica,
            'fecha' => $corrida['fecha'],
            'corrida' => $corrida['corrida'],
            'ultimo_dia' => ($efectivo < $d),
            'faltan' => $corrida['faltan']
        ];
    }

    /**
     * El vencimiento de una tarjeta en cada uno de varios meses.
     *
     * @param int $dia
     * @param array $meses Lista de 'Y-m'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array ['fechas' => ['Y-m' => (lo de delMes())], 'faltan' => ['Y-m']]
     */
    public static function paraMeses($dia, $meses, $habiles) {
        $fechas = [];
        $faltan = [];

        foreach (is_array($meses) ? $meses : [] as $mes) {
            $r = self::delMes($dia, $mes, $habiles);
            $fechas[$r['mes']] = $r;

            foreach ($r['faltan'] as $f) {
                if (!in_array($f, $faltan, true)) {
                    $faltan[] = $f;
                }
            }
        }

        return ['fechas' => $fechas, 'faltan' => $faltan];
    }

    /**
     * El PROXIMO vencimiento de una tarjeta posterior a una fecha.
     *
     * PARA QUE EXISTE: una factura de tarjeta corporativa ya vencida no se apila
     * en el primer dia del eje. Una tarjeta se paga UNA VEZ POR MES, asi que esa
     * factura sale en el proximo vencimiento de su tarjeta, y no en una fecha
     * inventada que ademas seria hoy. Ver README-pagos-tarjetas.md.
     *
     * DEVUELVE null SI NINGUNO DEL HORIZONTE ES POSTERIOR. No se estira el
     * calendario hacia adelante para encontrar uno: si el eje se termina antes,
     * ese importe cae fuera del horizonte y eso es lo que hay que informar.
     *
     * Estatica y pura.
     *
     * @param int $dia
     * @param string $hoy 'Y-m-d'
     * @param array $meses Lista de 'Y-m' EN ORDEN CRONOLOGICO (los del eje ya lo estan)
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array|null Lo que devuelve delMes(), o null
     */
    public static function proximoDesde($dia, $hoy, $meses, $habiles) {
        $hoyStr = substr((string) $hoy, 0, 10);

        foreach (is_array($meses) ? $meses : [] as $mes) {
            $r = self::delMes($dia, $mes, $habiles);

            /* ESTRICTAMENTE POSTERIOR. Un vencimiento que cae hoy ya se debito:
               es el mismo criterio con el que Logistica no proyecta el pago de
               hoy y CobElectronicos corta sus pendientes. */
            if ($r['fecha'] > $hoyStr) {
                return $r;
            }
        }

        return null;
    }

    /**
     * El PROXIMO PAGO de una tarjeta: la primera fecha posterior a hoy, sea la de
     * un resumen ya cargado o la de una estimacion.
     *
     * PARA QUE EXISTE, Y POR QUE MIRA LAS DOS COSAS
     * ---------------------------------------------
     * Dos partes del modulo necesitan exactamente esta pregunta, y tienen que
     * contestarla igual:
     *
     *   - Tarjetas Pagos Corporativos, para ubicar una factura YA VENCIDA: una
     *     tarjeta se paga una vez por mes, asi que esa factura sale en el proximo
     *     pago de su tarjeta y no apilada en el primer dia del eje.
     *
     *   - Tarjetas Socios, para decidir con que dolar se convierte el componente
     *     en U$S: el proximo vencimiento se valua con el dolar oficial del BCRA y
     *     los siguientes con dolar futuro.
     *
     * MIRA LOS RESUMENES Y LAS ESTIMACIONES JUNTOS porque el proximo pago real
     * puede ser cualquiera de los dos: si hay un resumen cargado para el mes en
     * curso que vence dentro de tres dias, ESE es el proximo pago, y no el
     * vencimiento estimado del mes que viene. Mirar solo las estimaciones correria
     * la fecha un mes entero.
     *
     * UN RESUMEN PAGADO NO CUENTA. Ya salio: no es un pago que venga, y tomarlo
     * como el proximo dejaria afuera al que si viene.
     *
     * DEVUELVE null SI NINGUNO ES POSTERIOR. No se estira el calendario para
     * encontrar uno: si el eje se termina antes, eso es lo que hay que informar.
     *
     * Estatica y pura.
     *
     * @param int $dia Dia de vencimiento de la tarjeta
     * @param array $resumenes Mapa 'Y-m' => resumen vigente de esa tarjeta
     * @param array $meses Lista de 'Y-m' EN ORDEN CRONOLOGICO
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param string $hoy 'Y-m-d'
     * @return array|null ['mes', 'fecha', 'origen' => 'RESUMEN'|'ESTIMACION',
     *                     'vencimiento' => (lo de delMes()) | null]
     */
    public static function proximoPago($dia, $resumenes, $meses, $habiles, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $mejor = null;

        foreach (is_array($meses) ? $meses : [] as $mes) {
            $r = isset($resumenes[$mes]) ? $resumenes[$mes] : null;

            if ($r !== null) {
                /* UN RESUMEN PAGADO NO ES UN PAGO QUE VENGA. Se saltea el mes
                   entero: el resumen pisa la estimacion, asi que tampoco hay una
                   estimacion que cobrar en ese mes. */
                if (!empty($r['PAGADO'])) {
                    continue;
                }

                $fecha = $r['FECHA_VENCIMIENTO'];
                $origen = 'RESUMEN';
                $vencimiento = null;
            } else {
                $v = self::delMes($dia, $mes, $habiles);
                $fecha = $v['fecha'];
                $origen = 'ESTIMACION';
                $vencimiento = $v;
            }

            if ($fecha === null || $fecha <= $hoyStr) {
                continue;
            }

            /* EL MAS TEMPRANO GANA, y no el primero que aparece: la fecha de un
               resumen se tipea, asi que puede caer antes que la estimacion de un
               mes anterior. Recorrer los meses en orden no alcanza. */
            if ($mejor === null || $fecha < $mejor['fecha']) {
                $mejor = ['mes' => $mes, 'fecha' => $fecha, 'origen' => $origen,
                          'vencimiento' => $vencimiento];
            }
        }

        return $mejor;
    }

    /* ====================================================================
       PARA LA PANTALLA
       ==================================================================== */

    /**
     * De donde sale una fecha, en una linea, para el tooltip de la celda.
     *
     * LO ARMA EL BACKEND porque es la explicacion de una cuenta que hace el
     * backend. Con el texto en el front, cambiar la regla obligaria a cambiarla
     * en dos lados y el segundo se olvida. Mismo criterio que
     * LogisticaValorHora::explicar().
     *
     * @param array $r Lo que devolvio delMes()
     * @return string
     */
    public static function explicar($r) {
        $texto = 'Vence el ' . self::corto($r['fecha']) . '.';

        if ($r['ultimo_dia']) {
            $texto .= ' La tarjeta vence el ' . $r['dia_pedido'] . ' y ' . $r['mes']
                . ' no tiene ese día, así que se usa el último: ' . self::corto($r['teorica'])
                . '.';
        }

        if ($r['corrida']) {
            $texto .= ' ' . self::corto($r['teorica']) . ' no es día hábil, así que el débito '
                . 'pasa al primer hábil siguiente.';
        }

        if (!$r['ultimo_dia'] && !$r['corrida']) {
            $texto .= ' Es el día ' . $r['dia_pedido'] . ' de la tarjeta, y es hábil.';
        }

        return $texto;
    }

    /**
     * El aviso de calendario faltante, con la MISMA redaccion que usan Ventas y
     * el cronograma de pagos.
     *
     * Misma redaccion a proposito: es el mismo hecho -RO_T_CALENDARIO no llega
     * hasta ahi- y dos textos distintos para la misma causa se leen como dos
     * problemas.
     *
     * @param array $meses Lista de 'Y-m'
     * @return array Lista de mensajes
     */
    public static function avisosCalendario($meses) {
        $avisos = [];

        foreach ($meses as $mes) {
            $avisos[] = 'RO_T_CALENDARIO no tiene datos para ' . $mes
                . '. Se asumen hábiles los días de lunes a viernes.';
        }

        return $avisos;
    }

    /**
     * Desde y hasta que dia hay que leer el calendario para resolver un
     * horizonte.
     *
     * TERMINA DESPUES DE fin(), al contrario de
     * CronogramaPagos::rangoCalendario(), que empieza antes del principio: el
     * corrimiento va hacia adelante, asi que lo que puede quedar fuera del rango
     * es la cola y no la cabeza. Un dia 31 del ultimo mes del eje corrido por un
     * feriado termina en el mes siguiente.
     *
     * @param Horizonte $h
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoCalendario($h) {
        $meses = $h->meses();

        return [
            'desde' => $meses[0]['clave'] . '-01',
            'hasta' => date('Y-m-d', strtotime($h->fin() . ' +' . self::DIAS_COLA . ' day'))
        ];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * Valida un mes 'Y-m'.
     *
     * @param mixed $mes
     * @return string
     */
    public static function validarMes($mes) {
        $m = trim((string) $mes);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new Exception("Mes inválido: '$mes'. Se esperaba el formato YYYY-MM.");
        }

        return $m;
    }

    /** 'Y-m-d' => 'd/m' */
    private static function corto($fecha) {
        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2);
    }
}
