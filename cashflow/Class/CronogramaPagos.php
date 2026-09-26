<?php

/**
 * CronogramaPagos
 * Cuando se paga: el 2do y el 4to viernes de cada mes, corridos al dia habil
 * anterior si el viernes no lo es, y con override manual por pago.
 *
 * POR QUE ES UNA CLASE APARTE, Y POR QUE ES PURA
 * ----------------------------------------------
 * Hoy la usa un solo modulo -Logistica Local- pero el cronograma no es de
 * Logistica: es cuando la empresa paga, y vive en Parametros -> Generales
 * porque cualquier egreso que se pague con el mismo calendario lo va a pedir.
 * Que el consumidor sea uno solo hoy no justifica esconderlo adentro de el;
 * el dia que sea el segundo, la regla ya esta en un lugar y probada.
 *
 * NO TOCA LA BASE. Recibe el mapa de dias habiles y el de overrides ya leidos,
 * y devuelve fechas. Es lo que permite probar entera la parte delicada -el mes
 * con cinco viernes, el viernes feriado, el cruce de ano- sin montar un
 * calendario en SQL Server.
 *
 * LAS FECHAS NO SE GUARDAN, SOLO LOS OVERRIDES
 * ---------------------------------------------
 * El 2do y el 4to viernes son una funcion del calendario, y el corrimiento
 * tambien. Materializarlos seria tener una copia que se desactualiza sola el
 * dia que cambia un feriado en RO_T_CALENDARIO. Lo unico que se guarda es lo
 * que una persona decidio distinto, que es lo unico que no se puede recalcular.
 *
 * EL CORRIMIENTO VA HACIA ATRAS, Y ESO NO ES UN DETALLE
 * -----------------------------------------------------
 * Ventas corre una acreditacion al PROXIMO dia habil: una cobranza que cae
 * sabado entra el lunes, porque el banco no acredita antes de poder hacerlo.
 * Un pago es al reves: si el viernes es feriado se paga el dia habil ANTERIOR,
 * porque el compromiso es con una persona que cobra esa semana. Las dos reglas
 * son correctas y van en direcciones opuestas, asi que no comparten codigo: lo
 * que comparten es el origen del calendario (RO_T_CALENDARIO, via
 * Ventas::getDiasHabiles), que es lo que no puede estar escrito dos veces.
 *
 * SI FALTA LA FECHA EN EL CALENDARIO, EL MISMO FALLBACK QUE VENTAS
 * ----------------------------------------------------------------
 * RO_T_CALENDARIO esta poblada hasta 2027 y se sigue extendiendo. Una fecha que
 * no este no rompe: se asume habil de lunes a viernes y se deja un aviso,
 * deduplicado por mes para no inundar la respuesta. Es literalmente lo que hace
 * Ventas::proximoHabil(), y tiene que ser lo mismo: dos fallbacks distintos
 * pondrian la cobranza y el pago en calendarios que no coinciden justo en los
 * meses en los que no hay dato.
 *
 * SIEMPRE SON DOS PAGOS POR MES
 * ------------------------------
 * Tambien en un mes con cinco viernes. El 2do y el 4to estan definidos en
 * cualquier mes -el mas corto posible, febrero de 28 dias, tiene cuatro- asi
 * que no hay un caso en el que falte uno. El quinto viernes, cuando existe, no
 * es un pago: el acuerdo es quincenal, no semanal.
 *
 * EL NUMERO DE PAGO ES LA IDENTIDAD, NO LA FECHA
 * -----------------------------------------------
 * Un override se guarda contra (mes, nro de pago) y no contra la fecha
 * calculada. Si se guardara contra la fecha, el dia que se agrega un feriado el
 * calculo daria otro dia y el override quedaria colgado de una fecha que ya no
 * existe en el cronograma.
 */
class CronogramaPagos {

    /** Los dos pagos del mes, por el ordinal del viernes que les toca */
    const ORDINALES = [1 => 2, 2 => 4];

    /** Tope defensivo: ningun feriado encadena mas de 30 dias no habiles */
    const MAX_CORRIMIENTO = 30;

    /**
     * Todos los viernes de un mes, en orden.
     *
     * @param int $anio
     * @param int $mes 1..12
     * @return array Lista de 'Y-m-d'
     */
    public static function viernesDelMes($anio, $mes) {
        $anio = intval($anio);
        $mes = intval($mes);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mes invalido: ' . $mes);
        }

        $cursor = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
        $viernes = [];

        /* Se salta directo al primer viernes en vez de recorrer el mes dia por
           dia: 'N' es 1..7 con el viernes en 5. */
        $cursor->modify('+' . ((5 - intval($cursor->format('N')) + 7) % 7) . ' day');

        while (intval($cursor->format('n')) === $mes) {
            $viernes[] = $cursor->format('Y-m-d');
            $cursor->modify('+7 day');
        }

        return $viernes;
    }

    /**
     * Las dos fechas TEORICAS de un mes, antes del corrimiento y del override.
     *
     * @param int $anio
     * @param int $mes 1..12
     * @return array Mapa nroPago => 'Y-m-d'
     */
    public static function teoricasDelMes($anio, $mes) {
        $viernes = self::viernesDelMes($anio, $mes);
        $fechas = [];

        foreach (self::ORDINALES as $nro => $ordinal) {
            /* Un mes siempre tiene por lo menos cuatro viernes; la guarda esta
               igual porque un ordinal ausente que se lea como null seria una
               fecha nula viajando hasta el reparto de importes. */
            if (!isset($viernes[$ordinal - 1])) {
                throw new Exception('El mes ' . sprintf('%04d-%02d', $anio, $mes)
                    . ' no tiene ' . $ordinal . ' viernes, que no deberia poder pasar.');
            }

            $fechas[$nro] = $viernes[$ordinal - 1];
        }

        return $fechas;
    }

    /**
     * Corre una fecha al dia habil ANTERIOR, si no es habil.
     *
     * @param string $fecha 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array ['fecha' => 'Y-m-d', 'corrida' => bool, 'faltan' => ['Y-m']]
     */
    public static function habilAnterior($fecha, $habiles) {
        $cursor = (string) $fecha;
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

                // Fallback: lunes a viernes se consideran habiles. Igual que Ventas.
                if (intval(date('N', strtotime($cursor))) <= 5) {
                    return ['fecha' => $cursor, 'corrida' => ($i > 0), 'faltan' => $faltan];
                }
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
        }

        /* Treinta dias no habiles seguidos no existen. Si se llega aca el mapa
           esta mal, y devolver la fecha del tope escondería el problema. */
        throw new Exception('No se encontró ningún día hábil en los '
            . self::MAX_CORRIMIENTO . ' días anteriores a ' . $fecha
            . '. Revisá RO_T_CALENDARIO.');
    }

    /**
     * Los dos pagos de un mes, ya resueltos: teorica, corrida y override.
     *
     * CADA FILA TRAE LAS TRES FECHAS y no solo la que vale. La teorica explica
     * de donde sale la calculada -"el 4to viernes era el 25, que es feriado"- y
     * la calculada es contra que se compara el override. Sin las tres, la
     * pantalla muestra un dia que nadie puede justificar.
     *
     * @param string $mes 'Y-m'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $overrides Mapa 'Y-m' => [nro => ['fecha' => 'Y-m-d', ...]]
     * @return array Lista de ['nro', 'mes', 'teorica', 'calculada', 'fecha', 'corrida',
     *                         'override' => bool, 'motivo', 'faltan']
     */
    public static function delMes($mes, $habiles, $overrides = []) {
        $m = explode('-', (string) $mes);

        if (count($m) < 2) {
            throw new Exception("Mes invalido: '$mes'. Se esperaba 'YYYY-MM'.");
        }

        $teoricas = self::teoricasDelMes(intval($m[0]), intval($m[1]));
        $delMes = isset($overrides[$mes]) && is_array($overrides[$mes]) ? $overrides[$mes] : [];
        $pagos = [];

        foreach ($teoricas as $nro => $teorica) {
            $corrida = self::habilAnterior($teorica, $habiles);
            $ov = isset($delMes[$nro]) ? $delMes[$nro] : null;

            /* EL OVERRIDE NO SE CORRE AL DIA HABIL. Lo cargo una persona que
               sabe algo que el calendario no sabe -como que ese pago se
               adelanta por vacaciones- y corregirlo seria contradecirla en
               silencio. Mismo criterio que el override de cotizacion de Comex,
               que manda sobre la curva. */
            $pagos[] = [
                'nro' => $nro,
                'mes' => (string) $mes,
                'teorica' => $teorica,
                'calculada' => $corrida['fecha'],
                'fecha' => ($ov && !empty($ov['fecha'])) ? $ov['fecha'] : $corrida['fecha'],
                'corrida' => $corrida['corrida'],
                'override' => (bool) ($ov && !empty($ov['fecha'])),
                'motivo' => ($ov && isset($ov['motivo'])) ? $ov['motivo'] : null,
                'faltan' => $corrida['faltan']
            ];
        }

        return $pagos;
    }

    /**
     * El cronograma de TODOS los meses de un horizonte.
     *
     * SE CALCULAN TODOS LOS MESES, tambien los que caen enteros fuera del tramo
     * diario. No es de mas: es lo que decide en que columna va cada mitad de un
     * importe mensual. Un mes partido por el final del tramo -octubre, con el
     * pago del 9 adentro y el del 23 afuera- solo se puede repartir sabiendo las
     * dos fechas. Lo que SI se acota a las fechas del tramo es la edicion: fuera
     * de el, correr un pago tres dias no mueve ningun numero del tablero, porque
     * la columna del mes es la misma.
     *
     * @param Horizonte $h
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $overrides Mapa 'Y-m' => [nro => ['fecha', 'motivo']]
     * @return array ['pagos' => [...], 'faltan' => ['Y-m']]
     */
    public static function paraHorizonte($h, $habiles, $overrides = []) {
        $pagos = [];
        $faltan = [];
        $diasSet = $h->diasSet();

        foreach ($h->meses() as $mes) {
            foreach (self::delMes($mes['clave'], $habiles, $overrides) as $p) {
                /* En que rama del eje cae: es lo unico que la pantalla de
                   Parametros necesita para saber que fechas ofrece editar, y lo
                   que el proveedor usa para explicar el reparto. El importe lo
                   ubica Horizonte::acumular(), que aplica la misma regla. */
                $p['en_tramo'] = isset($diasSet[$p['fecha']]);
                $pagos[] = $p;

                foreach ($p['faltan'] as $f) {
                    if (!in_array($f, $faltan, true)) {
                        $faltan[] = $f;
                    }
                }
            }
        }

        return ['pagos' => $pagos, 'faltan' => $faltan];
    }

    /**
     * Desde y hasta que dia hay que leer el calendario para resolver un
     * horizonte.
     *
     * ARRANCA UN MES ANTES del primer mes del eje: el corrimiento va hacia
     * atras, y el 2do viernes de un mes puede terminar en el mes anterior solo
     * si encadena mas de una semana de feriados. No pasa, pero pedir un mes de
     * mas a una tabla de fechas no cuesta nada y evita que el fallback se
     * dispare por un rango corto.
     *
     * @param Horizonte $h
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoCalendario($h) {
        $meses = $h->meses();
        $primero = new DateTime($meses[0]['clave'] . '-01');
        $primero->modify('-1 month');

        return ['desde' => $primero->format('Y-m-d'), 'hasta' => $h->fin()];
    }

    /**
     * El aviso de calendario faltante, con la misma redaccion que usa Ventas.
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
}
