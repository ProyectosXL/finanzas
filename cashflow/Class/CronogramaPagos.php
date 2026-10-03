<?php

/**
 * CronogramaPagos
 * Cuando se paga cada concepto: un dia de la semana con una frecuencia, corrido
 * al dia habil anterior si ese dia no lo es, y con override manual por pago.
 *
 * UN CRONOGRAMA POR CONCEPTO
 * --------------------------
 * Hasta feature/cronogramas-tarjetas-corporativas habia uno solo -el 2do y el
 * 4to viernes- escrito en el codigo, y lo compartian Logistica Local y la parte
 * en efectivo de Gastos Supervisoras. Ahora cada concepto tiene el suyo, con su
 * dia de la semana y su frecuencia configurables en Parametros -> Generales:
 *
 *   PROV_LOCALES  miercoles, quincenal   Cuentas a Pagar Locales
 *   LOGISTICA     viernes,   quincenal   Logistica Local
 *   SUPERVISORAS  lunes,     semanal     efectivo de Gastos Supervisoras
 *
 * Esos tres pares son SOLO EL VALOR POR DEFECTO (CONCEPTOS), el mismo que siembra
 * sql/cashflow_cronograma_conceptos.sql: lo que se aplica sale de
 * RO_T_CASHFLOW_PARAMETROS. Estan aca porque sin el script la pantalla tiene que
 * seguir pagando en algun dia, y ese dia tiene que ser el mismo que el script
 * va a sembrar: dos defaults distintos moverian los pagos el dia que se corre.
 *
 * LAS DOS FRECUENCIAS
 * -------------------
 *   QUINCENAL  el 2do y el 4to de ese dia de la semana en el mes. SIEMPRE SON
 *              DOS PAGOS, tambien en un mes con cinco: cualquier dia de la
 *              semana aparece por lo menos cuatro veces en un mes -el mas corto
 *              posible, febrero de 28 dias, tiene exactamente cuatro de cada
 *              uno- asi que el 2do y el 4to siempre existen. El quinto, cuando
 *              lo hay, no es un pago: el acuerdo es quincenal, no semanal.
 *   SEMANAL    TODOS los de ese dia en el mes: cuatro o cinco pagos.
 *
 * POR QUE ES UNA CLASE APARTE, Y POR QUE ES PURA
 * ----------------------------------------------
 * El cronograma no es de ningun consumidor: es cuando la empresa paga, y vive en
 * Parametros -> Generales porque cualquier egreso que se pague con el mismo
 * calendario lo va a pedir.
 *
 * NO TOCA LA BASE. Recibe la configuracion, el mapa de dias habiles y el de
 * overrides ya leidos, y devuelve fechas. Es lo que permite probar entera la
 * parte delicada -el mes con cinco lunes, el viernes feriado, el cruce de ano-
 * sin montar un calendario en SQL Server.
 *
 * LAS FECHAS NO SE GUARDAN, SOLO LOS OVERRIDES
 * ---------------------------------------------
 * El 2do miercoles o el 3er lunes son una funcion del calendario, y el
 * corrimiento tambien. Materializarlos seria tener una copia que se desactualiza
 * sola el dia que cambia un feriado en RO_T_CALENDARIO. Lo unico que se guarda es
 * lo que una persona decidio distinto, que es lo unico que no se puede recalcular.
 *
 * EL CORRIMIENTO VA HACIA ATRAS, Y ESO NO ES UN DETALLE
 * -----------------------------------------------------
 * Ventas corre una acreditacion al PROXIMO dia habil: una cobranza que cae
 * sabado entra el lunes, porque el banco no acredita antes de poder hacerlo.
 * Un pago es al reves: si el dia de pago es feriado se paga el dia habil
 * ANTERIOR, porque el compromiso es con alguien que cobra esa semana. Las dos
 * reglas son correctas y van en direcciones opuestas, asi que no comparten
 * codigo: lo que comparten es el origen del calendario (RO_T_CALENDARIO, via
 * Ventas::getDiasHabiles), que es lo que no puede estar escrito dos veces.
 *
 * LA REGLA ES LA MISMA PARA LOS TRES CONCEPTOS. Lo que cambia entre ellos es el
 * dia y la frecuencia, no hacia donde se corre.
 *
 * UNA CONSECUENCIA: UN PAGO PUEDE CAER EN EL MES ANTERIOR. El 1er lunes de un mes
 * que arranca lunes es el dia 1; si es feriado, el pago se corre al viernes del
 * mes anterior. Es correcto -ese dia sale la plata- y el eje lo ubica en la
 * columna de esa fecha. Por eso el calendario se lee desde un mes antes del eje.
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
 * EL NUMERO DE PAGO ES LA IDENTIDAD, NO LA FECHA
 * -----------------------------------------------
 * Un override se guarda contra (concepto, mes, nro de pago) y no contra la fecha
 * calculada. Si se guardara contra la fecha, el dia que se agrega un feriado el
 * calculo daria otro dia y el override quedaria colgado de una fecha que ya no
 * existe en el cronograma.
 *
 * Y POR ESO CAMBIAR EL DIA O LA FRECUENCIA DA DE BAJA LOS OVERRIDES: con otra
 * configuracion el pago 2 de un mes apunta a otra fecha -el 4to viernes pasa a
 * ser el 4to miercoles, o el 2do lunes-, y el override quedaria pegado a un pago
 * que ya no es el mismo. Lo hace CronogramaDatos::guardarConfig().
 *
 * EL REPARTO DE UN IMPORTE MENSUAL ES EN PARTES IGUALES
 * ------------------------------------------------------
 * Entre TODOS los pagos del mes, tambien los que ya pasaron: mitad y mitad en
 * quincenal, un cuarto o un quinto en semanal. Ver parte(). Quien proyecta
 * descarta despues los pagos con fecha <= hoy; la parte no cambia por eso.
 */
class CronogramaPagos {

    /** Las dos frecuencias */
    const QUINCENAL = 'QUINCENAL';
    const SEMANAL = 'SEMANAL';
    const FRECUENCIAS = [self::QUINCENAL, self::SEMANAL];

    /** Que ordinal del dia de la semana es cada pago en la frecuencia quincenal */
    const ORDINALES_QUINCENAL = [1 => 2, 2 => 4];

    /**
     * El mayor numero de pago posible: el 5to de un dia de la semana en un mes
     * con cinco. Es el tope del CHECK de NRO_PAGO en la tabla de overrides.
     */
    const MAX_PAGOS = 5;

    /** Los dias de la semana, 1..7 ISO ('N' de date()), como se nombran */
    const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
                  5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * Los conceptos, con su VALOR POR DEFECTO y quien lo usa.
     *
     * El defecto es el de sql/cashflow_cronograma_conceptos.sql y se usa SOLO si
     * el parametro no esta: ver el encabezado. El orden es el de la pantalla.
     */
    const CONCEPTOS = [
        'PROV_LOCALES' => ['nombre' => 'Proveedores Locales', 'dia' => 3,
                           'frecuencia' => self::QUINCENAL,
                           'usa' => 'Cuentas a Pagar Locales'],
        'LOGISTICA' => ['nombre' => 'Logística Local', 'dia' => 5,
                        'frecuencia' => self::QUINCENAL,
                        'usa' => 'Logística Local'],
        'SUPERVISORAS' => ['nombre' => 'Supervisoras', 'dia' => 1,
                           'frecuencia' => self::SEMANAL,
                           'usa' => 'la parte en efectivo de Gastos Supervisoras']
    ];

    /** Tope defensivo: ningun feriado encadena mas de 30 dias no habiles */
    const MAX_CORRIMIENTO = 30;

    /* ====================================================================
       LA CONFIGURACION
       ==================================================================== */

    /**
     * Valida un concepto y lo devuelve normalizado.
     *
     * @param string $concepto
     * @return string
     */
    public static function validarConcepto($concepto) {
        $c = strtoupper(trim((string) $concepto));

        if (!isset(self::CONCEPTOS[$c])) {
            throw new Exception("Concepto de cronograma inválido: '$concepto'. Son "
                . implode(', ', array_keys(self::CONCEPTOS)) . '.');
        }

        return $c;
    }

    /**
     * Valida un dia y una frecuencia, y los devuelve normalizados.
     *
     * LA VALIDACION QUE VALE ES ESTA: la pantalla ofrece un desplegable, pero lo
     * que manda el navegador es un pedido, y el endpoint es alcanzable sin ella.
     *
     * @param mixed $dia 1..7 ISO
     * @param string $frecuencia
     * @return array ['dia' => int, 'frecuencia' => string]
     */
    public static function config($dia, $frecuencia) {
        $d = filter_var($dia, FILTER_VALIDATE_INT);

        if ($d === false || !isset(self::DIAS[$d])) {
            throw new Exception("Día de la semana inválido: '$dia'. Va de 1 (lunes) a 7 (domingo).");
        }

        $f = strtoupper(trim((string) $frecuencia));

        if (!in_array($f, self::FRECUENCIAS, true)) {
            throw new Exception("Frecuencia inválida: '$frecuencia'. Es "
                . implode(' o ', self::FRECUENCIAS) . '.');
        }

        return ['dia' => $d, 'frecuencia' => $f];
    }

    /**
     * Las claves de RO_T_CASHFLOW_PARAMETROS de un concepto.
     *
     * @param string $concepto
     * @return array ['dia' => clave, 'frecuencia' => clave]
     */
    public static function clavesParametro($concepto) {
        $c = strtolower(self::validarConcepto($concepto));

        return ['dia' => 'cronograma_' . $c . '_dia',
                'frecuencia' => 'cronograma_' . $c . '_frecuencia'];
    }

    /**
     * La configuracion de un concepto a partir del mapa de parametros.
     *
     * SI FALTA O ES INVALIDA, VA EL DEFECTO Y SE DICE. Un cronograma que no se
     * puede resolver deja a su consumidor sin fechas, y sin fechas no hay pagos:
     * la fila del tablero quedaria en cero sin que nada lo explique. Con el
     * defecto la pantalla sigue funcionando, con el mismo dia que el script va a
     * sembrar, y el aviso dice que falta.
     *
     * Estatica y pura.
     *
     * @param array $map Mapa CLAVE => VALOR de Parametros::getParametrosMap()
     * @param string $concepto
     * @return array ['dia', 'frecuencia', 'defecto' => bool, 'aviso' => string|null]
     */
    public static function configDesdeMapa($map, $concepto) {
        $c = self::validarConcepto($concepto);
        $claves = self::clavesParametro($c);
        $defecto = self::CONCEPTOS[$c];
        $nombre = $defecto['nombre'];

        if (!isset($map[$claves['dia']]) || !isset($map[$claves['frecuencia']])) {
            return ['dia' => $defecto['dia'], 'frecuencia' => $defecto['frecuencia'],
                    'defecto' => true,
                    'aviso' => 'Falta configurar el cronograma de ' . $nombre . ': corré '
                        . 'sql/cashflow_cronograma_conceptos.sql contra la base central. '
                        . 'Mientras tanto se paga ' . self::describir($defecto) . ', que es el '
                        . 'valor por defecto.'];
        }

        try {
            $cfg = self::config($map[$claves['dia']], $map[$claves['frecuencia']]);
        } catch (Exception $e) {
            return ['dia' => $defecto['dia'], 'frecuencia' => $defecto['frecuencia'],
                    'defecto' => true,
                    'aviso' => 'El cronograma de ' . $nombre . ' tiene un valor que no se '
                        . 'entiende (' . $e->getMessage() . '). Se usa el valor por defecto, '
                        . self::describir($defecto) . '. Corregilo en Parámetros › Generales.'];
        }

        return ['dia' => $cfg['dia'], 'frecuencia' => $cfg['frecuencia'],
                'defecto' => false, 'aviso' => null];
    }

    /**
     * Como se dice una configuracion: "el 2do y el 4to miércoles" o "todos los
     * lunes". Es el texto que reemplaza al "2do y 4to viernes" que estaba escrito
     * en las pantallas.
     *
     * @param array $config ['dia', 'frecuencia']
     * @return string
     */
    public static function describir($config) {
        $dia = self::DIAS[intval($config['dia'])];

        return ($config['frecuencia'] === self::SEMANAL)
            ? 'todos los ' . self::plural($dia)
            : 'el 2do y el 4to ' . $dia;
    }

    /**
     * Como se llama un pago: "2do miércoles", "5to lunes".
     *
     * @param int $nro
     * @param array $config
     * @return string
     */
    public static function nombrePago($nro, $config) {
        $ordinal = ($config['frecuencia'] === self::SEMANAL)
            ? intval($nro)
            : (isset(self::ORDINALES_QUINCENAL[intval($nro)])
                ? self::ORDINALES_QUINCENAL[intval($nro)] : intval($nro));

        return self::ordinal($ordinal) . ' ' . self::DIAS[intval($config['dia'])];
    }

    /** 1 => '1er', 2 => '2do', 3 => '3er', 4 => '4to', 5 => '5to' */
    public static function ordinal($n) {
        $sufijos = [1 => 'er', 2 => 'do', 3 => 'er', 4 => 'to', 5 => 'to'];

        return $n . (isset($sufijos[$n]) ? $sufijos[$n] : '°');
    }

    /** 'miércoles' => 'miércoles', 'lunes' => 'lunes', 'sábado' => 'sábados' */
    private static function plural($dia) {
        return (substr($dia, -1) === 's') ? $dia : $dia . 's';
    }

    /* ====================================================================
       LAS FECHAS TEORICAS
       ==================================================================== */

    /**
     * Todos los dias de un dia de la semana en un mes, en orden.
     *
     * @param int $anio
     * @param int $mes 1..12
     * @param int $dia 1..7 ISO
     * @return array Lista de 'Y-m-d'
     */
    public static function diasDelMes($anio, $mes, $dia) {
        $anio = intval($anio);
        $mes = intval($mes);
        $dia = intval($dia);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mes invalido: ' . $mes);
        }

        if (!isset(self::DIAS[$dia])) {
            throw new Exception('Dia de la semana invalido: ' . $dia);
        }

        $cursor = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
        $fechas = [];

        /* Se salta directo al primero de ese dia en vez de recorrer el mes dia
           por dia: 'N' es 1..7 ISO, igual que $dia. */
        $cursor->modify('+' . (($dia - intval($cursor->format('N')) + 7) % 7) . ' day');

        while (intval($cursor->format('n')) === $mes) {
            $fechas[] = $cursor->format('Y-m-d');
            $cursor->modify('+7 day');
        }

        return $fechas;
    }

    /**
     * Las fechas TEORICAS de un mes, antes del corrimiento y del override.
     *
     * @param int $anio
     * @param int $mes 1..12
     * @param array $config ['dia', 'frecuencia']
     * @return array Mapa nroPago => 'Y-m-d', con nro desde 1
     */
    public static function teoricasDelMes($anio, $mes, $config) {
        $dias = self::diasDelMes($anio, $mes, $config['dia']);

        if ($config['frecuencia'] === self::SEMANAL) {
            $fechas = [];

            foreach ($dias as $i => $f) {
                $fechas[$i + 1] = $f;
            }

            return $fechas;
        }

        $fechas = [];

        foreach (self::ORDINALES_QUINCENAL as $nro => $ordinal) {
            /* Un mes siempre tiene por lo menos cuatro de cada dia; la guarda
               esta igual porque un ordinal ausente que se lea como null seria una
               fecha nula viajando hasta el reparto de importes. */
            if (!isset($dias[$ordinal - 1])) {
                throw new Exception('El mes ' . sprintf('%04d-%02d', $anio, $mes)
                    . ' no tiene ' . $ordinal . ' ' . self::DIAS[$config['dia']]
                    . ', que no deberia poder pasar.');
            }

            $fechas[$nro] = $dias[$ordinal - 1];
        }

        return $fechas;
    }

    /* ====================================================================
       EL CORRIMIENTO
       ==================================================================== */

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

    /* ====================================================================
       LOS PAGOS RESUELTOS
       ==================================================================== */

    /**
     * Los pagos de un mes, ya resueltos: teorica, corrida y override.
     *
     * CADA FILA TRAE LAS TRES FECHAS y no solo la que vale. La teorica explica
     * de donde sale la calculada -"el 4to viernes era el 25, que es feriado"- y
     * la calculada es contra que se compara el override. Sin las tres, la
     * pantalla muestra un dia que nadie puede justificar.
     *
     * 'cantidad' VIAJA EN CADA PAGO: es en cuantas partes se reparte el importe
     * del mes, y el consumidor que tiene un pago suelto no tiene que volver a
     * contar los del mes para saberlo.
     *
     * @param string $mes 'Y-m'
     * @param array $config ['dia', 'frecuencia']
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $overrides Mapa 'Y-m' => [nro => ['fecha' => 'Y-m-d', ...]]
     * @return array Lista de ['nro', 'mes', 'teorica', 'calculada', 'fecha', 'corrida',
     *                         'override' => bool, 'motivo', 'cantidad', 'nombre', 'faltan']
     */
    public static function delMes($mes, $config, $habiles, $overrides = []) {
        $m = explode('-', (string) $mes);

        if (count($m) < 2) {
            throw new Exception("Mes invalido: '$mes'. Se esperaba 'YYYY-MM'.");
        }

        $teoricas = self::teoricasDelMes(intval($m[0]), intval($m[1]), $config);
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
                'nombre' => self::nombrePago($nro, $config),
                'teorica' => $teorica,
                'calculada' => $corrida['fecha'],
                'fecha' => ($ov && !empty($ov['fecha'])) ? $ov['fecha'] : $corrida['fecha'],
                'corrida' => $corrida['corrida'],
                'override' => (bool) ($ov && !empty($ov['fecha'])),
                'motivo' => ($ov && isset($ov['motivo'])) ? $ov['motivo'] : null,
                'cantidad' => count($teoricas),

                // Quien movio la fecha y cuando, para la pantalla (Js/auditoria.js)
                'usuario' => ($ov && isset($ov['usuario'])) ? $ov['usuario'] : null,
                'fecha_alta' => ($ov && isset($ov['fecha_alta'])) ? $ov['fecha_alta'] : null,
                'faltan' => $corrida['faltan']
            ];
        }

        return $pagos;
    }

    /**
     * El cronograma de TODOS los meses de un horizonte.
     *
     * SE CALCULAN TODOS LOS MESES, tambien los que caen enteros fuera del tramo
     * diario. No es de mas: es lo que decide en que columna va cada parte de un
     * importe mensual. Un mes partido por el final del tramo -octubre, con el
     * pago del 9 adentro y el del 23 afuera- solo se puede repartir sabiendo
     * todas sus fechas. Lo que SI se acota a las fechas del tramo es la edicion:
     * fuera de el, correr un pago tres dias no mueve ningun numero del tablero,
     * porque la columna del mes es la misma.
     *
     * $mesesExtra AGREGA MESES DESPUES DEL HORIZONTE. Lo pide Proveedores Locales:
     * una factura que vence despues del ultimo pago del eje tiene que ir a un pago
     * que existe -aunque quede fuera del horizonte, y entonces se informa como
     * tal-, y sin el mes siguiente caeria en su propia fecha, adentro del eje. Los
     * pagos extra llevan 'en_horizonte' => false; ninguna pantalla los ofrece.
     *
     * @param Horizonte $h
     * @param array $config ['dia', 'frecuencia']
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $overrides Mapa 'Y-m' => [nro => ['fecha', 'motivo']]
     * @param int $mesesExtra
     * @return array ['pagos' => [...], 'faltan' => ['Y-m']]
     */
    public static function paraHorizonte($h, $config, $habiles, $overrides = [], $mesesExtra = 0) {
        $pagos = [];
        $faltan = [];
        $diasSet = $h->diasSet();

        foreach (self::mesesDe($h, $mesesExtra) as $mes => $enHorizonte) {
            foreach (self::delMes($mes, $config, $habiles, $overrides) as $p) {
                /* En que rama del eje cae: es lo unico que la pantalla de
                   Parametros necesita para saber que fechas ofrece editar, y lo
                   que el proveedor usa para explicar el reparto. El importe lo
                   ubica Horizonte::acumular(), que aplica la misma regla. */
                $p['en_tramo'] = isset($diasSet[$p['fecha']]);
                $p['en_horizonte'] = $enHorizonte;
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
     * Los meses del horizonte mas los extra.
     *
     * @return array Mapa 'Y-m' => bool (true si es un mes del horizonte)
     */
    private static function mesesDe($h, $mesesExtra) {
        $meses = [];

        foreach ($h->meses() as $m) {
            $meses[$m['clave']] = true;
        }

        $ultimo = new DateTime($h->meses()[count($h->meses()) - 1]['clave'] . '-01');

        for ($i = 1; $i <= intval($mesesExtra); $i++) {
            $ref = clone $ultimo;
            $ref->modify('+' . $i . ' month');
            $meses[$ref->format('Y-m')] = false;
        }

        return $meses;
    }

    /**
     * La parte de un importe mensual que le toca a un pago.
     *
     * EN PARTES IGUALES ENTRE TODOS LOS PAGOS DEL MES: mitad y mitad en quincenal,
     * un cuarto o un quinto en semanal segun cuantos tenga el mes. NO SE REDONDEA:
     * el redondeo es presentacion, y redondear aca haria que las partes no sumen
     * el importe del mes en los centavos, todos los meses.
     *
     * UN null SIGUE SIENDO null: un importe que falta no se reparte como cero.
     *
     * @param float|null $importe El importe del mes
     * @param int $cantidad Cuantos pagos tiene el mes
     * @return float|null
     */
    public static function parte($importe, $cantidad) {
        if ($importe === null || intval($cantidad) < 1) {
            return null;
        }

        return $importe / intval($cantidad);
    }

    /**
     * El primer pago con fecha EFECTIVA >= max(fecha base, hoy).
     *
     * Es la regla de Proveedores Locales: una factura del cronograma se paga en el
     * proximo dia de pago a partir de que vence -o de hoy, si ya vencio-.
     *
     * LA FECHA EFECTIVA, NUNCA LA TEORICA: la ya corrida al habil anterior, o la
     * del override. Que una factura que vence entre la corrida y la teorica -el
     * jueves, cuando el miercoles era feriado y se pago el martes- vaya al pago
     * siguiente es correcto: ese pago ya se hizo cuando la factura vencio. Si no
     * es lo que se quiere, se mueve el pago a mano.
     *
     * POR FECHA Y NO POR NUMERO DE PAGO: un override puede adelantar el 2do pago
     * de un mes a antes que el 1ro, y el proximo es el que llega primero. Por eso
     * se ordena aca en vez de confiar en el orden de la lista.
     *
     * EL MISMO DIA CUENTA: una factura que vence el dia de pago se paga ese dia,
     * y un pago que es hoy se hace hoy. Ver README-proveedores-locales.md, que
     * explica por que esto es distinto de Logistica.
     *
     * Estatica y pura: recibe los pagos ya resueltos (con el calendario y los
     * overrides aplicados).
     *
     * @param string $fechaBase 'Y-m-d'
     * @param array $pagos Lista de pagos de paraHorizonte()
     * @param string $hoy 'Y-m-d'
     * @return array|null El pago, o null si no hay ninguno despues
     */
    public static function proximoPago($fechaBase, $pagos, $hoy) {
        $desde = max(substr((string) $fechaBase, 0, 10), substr((string) $hoy, 0, 10));
        $mejor = null;

        foreach ($pagos as $p) {
            if (empty($p['fecha']) || $p['fecha'] < $desde) {
                continue;
            }

            if ($mejor === null || $p['fecha'] < $mejor['fecha']) {
                $mejor = $p;
            }
        }

        return $mejor;
    }

    /* ====================================================================
       EL CALENDARIO
       ==================================================================== */

    /**
     * Desde y hasta que dia hay que leer el calendario para resolver un
     * horizonte.
     *
     * ARRANCA UN MES ANTES del primer mes del eje: el corrimiento va hacia
     * atras, y el 1er lunes de un mes termina en el mes anterior si el dia 1 es
     * feriado. Pedir un mes de mas a una tabla de fechas no cuesta nada y evita
     * que el fallback se dispare por un rango corto.
     *
     * Y TERMINA $mesesExtra MESES DESPUES del fin del eje, por los meses extra de
     * paraHorizonte().
     *
     * @param Horizonte $h
     * @param int $mesesExtra
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoCalendario($h, $mesesExtra = 0) {
        $meses = $h->meses();
        $primero = new DateTime($meses[0]['clave'] . '-01');
        $primero->modify('-1 month');

        $hasta = $h->fin();

        if (intval($mesesExtra) > 0) {
            $fin = new DateTime($meses[count($meses) - 1]['clave'] . '-01');
            $fin->modify('+' . intval($mesesExtra) . ' month');
            $fin->modify('last day of this month');

            if ($fin->format('Y-m-d') > $hasta) {
                $hasta = $fin->format('Y-m-d');
            }
        }

        return ['desde' => $primero->format('Y-m-d'), 'hasta' => $hasta];
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
