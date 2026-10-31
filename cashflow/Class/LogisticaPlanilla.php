<?php

require_once __DIR__ . '/LogisticaValorHora.php';
require_once __DIR__ . '/CronogramaPagos.php';

/**
 * LogisticaPlanilla
 * La planilla de Logistica Local: cuanto se le paga a cada fletero, en que
 * fecha, mes por mes.
 *
 * TODO ES PROYECCION. No hay parte real: los pagos a los fleteros no se leen de
 * ningun lado, se calculan. Por eso la fila del tablero no esta partida en real
 * y proyectado como las de Comercio Exterior, y por eso no hay nada que
 * conciliar contra Tango.
 *
 * LA CUENTA, EN TRES LINEAS
 * -------------------------
 *   importe del mes = HORAS_MES x valor hora DE ESE MES
 *   se paga EN PARTES IGUALES en los pagos del mes del cronograma LOGISTICA
 *   (con su valor por defecto, el 2do y el 4to viernes: mitad y mitad)
 *   un pago con fecha ANTERIOR O IGUAL A HOY no se proyecta
 *
 * Los importes son FINALES: sin IVA y sin ningun otro concepto. Es lo que sale
 * de la cuenta.
 *
 * POR QUE EL PAGO DE HOY NO SE PROYECTA, Y COMO CONVIVE CON EL EJE
 * ----------------------------------------------------------------
 * El tramo diario de Horizonte arranca HOY, asi que la columna de hoy existe.
 * Lo que no existe es el pago de hoy: si el cronograma lo pone hoy, ya se hizo.
 * Proyectarlo seria pedir dos veces la misma plata, porque esa salida ya esta
 * reflejada en el saldo bancario que abre el cuadro.
 *
 * La consecuencia hay que tenerla presente: LA COLUMNA DE HOY NUNCA RECIBE NADA
 * de Logistica. No es un error ni un hueco, es el criterio. Es el mismo que usa
 * CobElectronicosProvider con su corte de pendientes, y va en la misma
 * direccion: lo de hoy ya pasó por la cuenta.
 *
 * NO SE AVISA de lo excluido por fecha, por el mismo motivo que alli: no es
 * plata que el tablero informe de menos, es plata que ya salio. Avisarlo todos
 * los dias seria ruido sobre algo que no hay que hacer. Igual se ve en la
 * planilla, en su fila, marcado.
 *
 * UN MES QUE CAE PARTIDO POR EL FIN DEL TRAMO DIARIO
 * ---------------------------------------------------
 * Con el tramo del 25/09 al 22/10, octubre tiene el pago del 9 ADENTRO y el del
 * 23 AFUERA. El primero va a su columna diaria y el segundo a la columna
 * mensual de Oct-26, asi que esa columna mensual lleva MEDIO importe de octubre
 * y no el total.
 *
 * ESO NO SE PROGRAMA ACA: lo resuelve Horizonte::acumular(), que aplica la regla
 * "un importe va a un dia O a su mes, nunca a los dos". Esta clase entrega una
 * lista de pagos con fecha e importe y deja que el eje los ubique, que es lo
 * que garantiza que Logistica no tenga su propia version de la regla. Lo unico
 * que hace falta para que funcione es que el cronograma este calculado para
 * TODOS los meses del eje, no solo para los del tramo: si el pago del 23 no
 * existiera, ese medio importe se perderia.
 *
 * NUNCA UN CERO DONDE FALTA UN DATO
 * ----------------------------------
 * Un fletero sin horas, sin valor hora base o sin mes base NO se proyecta: su
 * mes queda en null con el motivo, y el aviso lo nombra. Lo mismo un mes cuyo
 * ajuste trimestral no se puede calcular porque falta la inflacion de alguno de
 * los tres meses que suma. Un cero se leeria como "ese mes no se le paga nada",
 * que es una afirmacion que nadie hizo.
 *
 * HABITUAL O NO HABITUAL
 * ---------------------
 * Un fletero HABITUAL proyecta por calculo, como siempre. Uno NO HABITUAL no
 * proyecta por calculo: solo sus PAGOS PUNTUALES, cada uno con su fecha y su
 * importe, cargados a mano. Sus horas, su valor hora y su mes base se
 * conservan -si vuelve a habitual vuelven a proyectar- pero no se usan, ni se
 * avisa que faltan. Y al reves: los pagos puntuales de un habitual se ven, pero
 * no proyectan. Un fletero sin la marca es habitual: es lo que eran todos antes
 * de que la marca existiera, y lo que hace que la planilla de siempre de lo
 * mismo.
 *
 * EL IMPORTE DEL PROXIMO PAGO SE PUEDE EDITAR
 * --------------------------------------------
 * El primer pago de un habitual con fecha posterior a hoy se puede cargar con
 * su importe REAL. La edicion vale solo para ese pago -se identifica por (mes,
 * numero de pago), como los overrides de fecha- y los siguientes se siguen
 * calculando. El pago lleva el calculado y el editado, para que la pantalla
 * muestre los dos.
 *
 * ES PURA: no toca la base ni lee parametros. Recibe los fleteros, el eje, el
 * cronograma, el mapa de inflacion, los pagos puntuales y las ediciones, y
 * devuelve numeros.
 */
class LogisticaPlanilla {

    /* Por que un mes no proyecta. Los de valor hora salen de
       LogisticaValorHora; estos son los propios de la planilla. */
    const SIN_HORAS = 'SIN_HORAS';
    const NO_HABITUAL = 'NO_HABITUAL';

    /** El texto de un pago que ya se hizo, el mismo en los calculados y los puntuales */
    const YA_PASO = 'Ya pasó: ese pago ya se hizo.';
    const ES_HOY = 'Es hoy: ese pago ya se hizo.';

    /**
     * La planilla completa.
     *
     * @param array $fleteros Filas con COD_PROVEE, NOMBRE, HORAS_MES,
     *                        VALOR_HORA_BASE, MES_BASE
     * @param Horizonte $h Eje temporal
     * @param array $pagos Cronograma, tal como lo devuelve
     *                     CronogramaPagos::paraHorizonte()['pagos']
     * @param array $inflacion Mapa 'Y-m' => float, en puntos porcentuales
     * @param array $puntuales Pagos puntuales vigentes: COD_PROVEE, ID, FECHA,
     *                         IMPORTE y la auditoria
     * @param array $ediciones Importes editados vigentes: COD_PROVEE, MES,
     *                         NRO_PAGO, IMPORTE, IMPORTE_CALCULADO, FECHA_PAGO y
     *                         la auditoria
     * @return array ['fleteros' => [...], 'totales' => [...], 'pagos' => [...],
     *                'avisos' => [...], 'meses' => ['Y-m']]
     */
    public static function calcular($fleteros, $h, $pagos, $inflacion, $puntuales = [],
                                    $ediciones = []) {
        $meses = array_column($h->meses(), 'clave');
        $porMes = self::pagosPorMes($pagos);
        $hoy = $h->hoy();
        $puntualesDe = self::indexarPuntuales($puntuales);
        $edicionesDe = self::indexarEdiciones($ediciones);

        $filas = [];
        $avisos = [];
        $todosLosPagos = [];
        $totalMeses = array_fill_keys($meses, null);
        $totalGeneral = null;

        foreach ($fleteros as $f) {
            $cod = isset($f['COD_PROVEE']) ? trim((string) $f['COD_PROVEE']) : '';
            $susPuntuales = isset($puntualesDe[$cod]) ? $puntualesDe[$cod] : [];

            $fila = self::esHabitual($f)
                ? self::unFletero($f, $meses, $porMes, $inflacion, $hoy,
                    isset($edicionesDe[$cod]) ? $edicionesDe[$cod] : [])
                : self::unNoHabitual($f, $meses, $susPuntuales, $hoy);

            /* Los puntuales viajan en las dos: en un no habitual son lo que
               proyecta; en un habitual se ven sin proyectar, para que no
               desaparezcan de la pantalla sin explicacion. */
            $fila['puntuales'] = self::listaPuntuales($susPuntuales, $hoy, $fila['habitual']);

            foreach ($fila['avisos'] as $a) {
                $avisos[] = $a;
            }

            foreach ($fila['pagos'] as $p) {
                $todosLosPagos[] = $p;
            }

            /* LOS TOTALES SUMAN SOLO LO PROYECTADO, y un total sobre filas
               incompletas sigue siendo un numero util: dice cuanto se proyecta
               HOY. Lo que no puede pasar es que un mes sin proyectar aporte
               cero y quede indistinguible de uno que proyecta cero. Por eso un
               mes en el que NINGUN fletero pudo calcularse queda en null. */
            foreach ($meses as $mes) {
                $importe = $fila['meses'][$mes]['importe_proy'];

                if ($importe === null) {
                    continue;
                }

                $totalMeses[$mes] = ($totalMeses[$mes] === null)
                    ? $importe : $totalMeses[$mes] + $importe;
                $totalGeneral = ($totalGeneral === null)
                    ? $importe : $totalGeneral + $importe;
            }

            $filas[] = $fila;
        }

        if (empty($fleteros)) {
            $avisos[] = 'No hay ningún fletero activo cargado, así que la fila Logística del '
                . 'tablero va en cero. Se dan de alta en Parámetros › Logística, buscando el '
                . 'código de proveedor en Tango.';
        }

        return [
            'meses' => $meses,
            'fleteros' => $filas,
            'pagos' => $todosLosPagos,
            'totales' => ['meses' => $totalMeses, 'total' => $totalGeneral],
            'avisos' => $avisos
        ];
    }

    /**
     * Una fila de la planilla: un fletero, sus doce meses y todos sus
     * pagos.
     *
     * @param array $f Fila del fletero
     * @param array $meses Lista de 'Y-m'
     * @param array $porMes Cronograma indexado por mes
     * @param array $inflacion Mapa de inflacion
     * @param string $hoy 'Y-m-d'
     * @param array $ediciones Mapa 'Y-m|nro' => edicion vigente
     * @return array
     */
    private static function unFletero($f, $meses, $porMes, $inflacion, $hoy, $ediciones = []) {
        $cod = isset($f['COD_PROVEE']) ? trim((string) $f['COD_PROVEE']) : '';
        $nombre = isset($f['NOMBRE']) && $f['NOMBRE'] !== null ? trim((string) $f['NOMBRE']) : '';
        $quien = $nombre !== '' ? ($nombre . ' (' . $cod . ')') : $cod;

        $horas = (isset($f['HORAS_MES']) && $f['HORAS_MES'] !== null && $f['HORAS_MES'] !== '')
            ? floatval($f['HORAS_MES']) : null;
        $valorBase = (isset($f['VALOR_HORA_BASE']) && $f['VALOR_HORA_BASE'] !== null
            && $f['VALOR_HORA_BASE'] !== '') ? floatval($f['VALOR_HORA_BASE']) : null;
        $mesBase = (isset($f['MES_BASE']) && $f['MES_BASE'] !== null)
            ? trim((string) $f['MES_BASE']) : '';

        $fila = [
            'cod_provee' => $cod,
            'nombre' => $nombre,
            'habitual' => true,
            'horas_mes' => $horas,
            'valor_hora_base' => $valorBase,
            'mes_base' => ($mesBase === '' ? null : $mesBase),

            // Quien toco por ultima vez las horas o el valor hora (Js/auditoria.js)
            'usuario' => isset($f['USUARIO']) ? $f['USUARIO'] : null,
            'fecha_modif' => isset($f['FECHA_UPDATE']) ? $f['FECHA_UPDATE'] : null,
            'meses' => [],
            'pagos' => [],
            'total' => null,
            'avisos' => []
        ];

        /* LAS TRES FALTAS SE AVISAN UNA SOLA VEZ POR FLETERO, no una por mes:
           doce avisos que dicen lo mismo esconden el resto. */
        if ($horas === null) {
            $fila['avisos'][] = $quien . ': no tiene horas por mes cargadas, así que no se '
                . 'proyecta ningún mes. Cargalas en la planilla o en Parámetros › Logística.';
        }

        if ($valorBase === null) {
            $fila['avisos'][] = $quien . ': no tiene valor hora base cargado, así que no se '
                . 'proyecta ningún mes.';
        }

        if ($mesBase === '') {
            $fila['avisos'][] = $quien . ': no tiene mes base cargado. Sin él no se sabe desde '
                . 'cuándo rige el valor hora ni cuándo toca cada ajuste trimestral, así que no '
                . 'se proyecta ningún mes.';
        }

        $faltanInflacion = [];
        $antesDeBase = [];

        foreach ($meses as $mes) {
            $detalle = ($mesBase === '')
                ? self::sinMesBase($mes)
                : LogisticaValorHora::delMes($mesBase, $valorBase, $mes, $inflacion);

            $celda = self::unMes($detalle, $horas, $mes, $porMes, $hoy, $ediciones);

            if ($celda['motivo'] === LogisticaValorHora::SIN_INFLACION) {
                foreach ($detalle['faltan'] as $m) {
                    $faltanInflacion[$m] = true;
                }
            }

            if ($celda['motivo'] === LogisticaValorHora::ANTES_DE_BASE) {
                $antesDeBase[] = $mes;
            }

            $fila['meses'][$mes] = $celda;

            foreach ($celda['pagos'] as $p) {
                $p['cod_provee'] = $cod;
                $fila['pagos'][] = $p;

                /* EL TOTAL DE LA FILA SUMA LOS PAGOS QUE SE PROYECTAN, no los
                   importes mensuales: es lo que efectivamente va al tablero, y
                   los dos numeros difieren en el mes en curso, donde un pago ya
                   ocurrido no entra. */
                if (!$p['excluido'] && $p['importe'] !== null) {
                    $fila['total'] = ($fila['total'] === null)
                        ? $p['importe'] : $fila['total'] + $p['importe'];
                }
            }
        }

        if (!empty($faltanInflacion)) {
            $m = array_keys($faltanInflacion);
            sort($m);

            $fila['avisos'][] = $quien . ': falta la inflación de ' . implode(', ', $m)
                . ', así que no se puede calcular un ajuste trimestral y los meses desde ahí '
                . 'quedan sin proyectar. Cargala en Parámetros › Generales.';
        }

        if (!empty($antesDeBase)) {
            $fila['avisos'][] = $quien . ': ' . count($antesDeBase) . ' mes(es) del horizonte '
                . 'son anteriores a su mes base (' . $mesBase . '), así que no tienen ningún '
                . 'valor hora pactado y no se proyectan.';
        }

        /* EL PROXIMO PAGO, el unico cuyo importe se edita. Se marca en la fila
           y en la celda del mes, que es lo que dibuja la pantalla. */
        $proximo = self::proximoPago($fila);
        $fila['proximo'] = $proximo === null ? null
            : ['mes' => $proximo['mes'], 'nro' => $proximo['nro'], 'fecha' => $proximo['fecha']];

        if ($proximo !== null) {
            foreach ($fila['pagos'] as $i => $p) {
                $fila['pagos'][$i]['proximo'] = ($p['mes'] === $proximo['mes']
                    && $p['nro'] === $proximo['nro']);
            }

            foreach ($fila['meses'][$proximo['mes']]['pagos'] as $i => $p) {
                $fila['meses'][$proximo['mes']]['pagos'][$i]['proximo'] = ($p['nro'] === $proximo['nro']);
            }
        }

        return $fila;
    }

    /**
     * El proximo pago de un fletero habitual: el primero, POR FECHA, con fecha
     * posterior a hoy. Por fecha y no por numero: un override puede adelantar
     * el 2do pago de un mes a antes que el 1ro.
     *
     * Estatica y pura.
     *
     * @param array $fila Una fila de calcular()
     * @return array|null El pago, o null si no tiene ninguno por delante o no es habitual
     */
    public static function proximoPago($fila) {
        if (empty($fila['habitual'])) {
            return null;
        }

        $mejor = null;

        foreach ($fila['pagos'] as $p) {
            if ($p['excluido'] || (isset($p['puntual']) && $p['puntual'])) {
                continue;
            }

            if ($mejor === null || $p['fecha'] < $mejor['fecha']) {
                $mejor = $p;
            }
        }

        return $mejor;
    }

    /**
     * Una fila de un fletero NO HABITUAL: no calcula nada, proyecta sus pagos
     * puntuales.
     *
     * LOS MESES VAN IGUAL, CON SU MOTIVO, porque la pantalla dibuja la grilla
     * sobre ellos: un mes sin pago puntual dice por que esta vacio. Un mes con
     * algun pago puntual por delante no lleva motivo, y la celda muestra el
     * importe que trae el eje.
     *
     * LAS HORAS Y EL VALOR HORA VIAJAN, pero no se usan: se conservan para el
     * dia que vuelva a ser habitual.
     *
     * @param array $f
     * @param array $meses
     * @param array $puntuales Los del fletero
     * @param string $hoy
     * @return array
     */
    private static function unNoHabitual($f, $meses, $puntuales, $hoy) {
        $fila = self::unFletero(
            array_merge($f, ['HORAS_MES' => null, 'VALOR_HORA_BASE' => null, 'MES_BASE' => null]),
            [], [], [], $hoy);

        // Lo que se conserva, para verlo y para volver a habitual
        $fila['habitual'] = false;
        $fila['horas_mes'] = (isset($f['HORAS_MES']) && $f['HORAS_MES'] !== null && $f['HORAS_MES'] !== '')
            ? floatval($f['HORAS_MES']) : null;
        $fila['valor_hora_base'] = (isset($f['VALOR_HORA_BASE']) && $f['VALOR_HORA_BASE'] !== null
            && $f['VALOR_HORA_BASE'] !== '') ? floatval($f['VALOR_HORA_BASE']) : null;
        $fila['mes_base'] = (isset($f['MES_BASE']) && $f['MES_BASE'] !== null
            && trim((string) $f['MES_BASE']) !== '') ? trim((string) $f['MES_BASE']) : null;

        // No se avisa que faltan las horas: a un no habitual no se le piden.
        $fila['avisos'] = [];
        $fila['proximo'] = null;
        $fila['pagos'] = [];

        foreach ($meses as $mes) {
            $fila['meses'][$mes] = [
                'mes' => $mes,
                'valor_hora' => null,
                'motivo' => self::NO_HABITUAL,
                'tooltip' => 'Fletero no habitual: no se proyecta por cálculo, sólo sus pagos '
                    . 'puntuales.',
                'ajusta' => false,
                'rige_desde' => '',
                'importe_mes' => null,
                'importe_proy' => null,
                'pagos' => []
            ];
        }

        foreach ($puntuales as $pp) {
            $p = self::pagoPuntual($pp, $hoy, true);
            $p['cod_provee'] = $fila['cod_provee'];
            $fila['pagos'][] = $p;

            if (isset($fila['meses'][$p['mes']])) {
                $fila['meses'][$p['mes']]['pagos'][] = $p;

                if (!$p['excluido']) {
                    $c =& $fila['meses'][$p['mes']];
                    $c['motivo'] = null;
                    $c['tooltip'] = 'Pagos puntuales cargados a mano.';
                    $c['importe_proy'] = ($c['importe_proy'] === null)
                        ? $p['importe'] : $c['importe_proy'] + $p['importe'];
                    unset($c);
                }
            }

            if (!$p['excluido']) {
                $fila['total'] = ($fila['total'] === null) ? $p['importe'] : $fila['total'] + $p['importe'];
            }
        }

        return $fila;
    }

    /**
     * Un pago puntual como pago de la planilla: la misma estructura que los
     * calculados, con 'puntual' y su id.
     *
     * @param array $pp Fila de la tabla
     * @param string $hoy
     * @param bool $proyecta Si el fletero es no habitual
     * @return array
     */
    private static function pagoPuntual($pp, $hoy, $proyecta) {
        $fecha = substr((string) $pp['FECHA'], 0, 10);
        $pasado = ($fecha <= $hoy);

        return [
            'mes' => substr($fecha, 0, 7),
            'nro' => null,
            'id' => isset($pp['ID']) ? intval($pp['ID']) : null,
            'fecha' => $fecha,
            'en_tramo' => false,
            'override' => false,
            'corrida' => false,
            'puntual' => true,
            'importe' => floatval($pp['IMPORTE']),
            'excluido' => $pasado || !$proyecta,
            'motivo_excluido' => $pasado
                ? (($fecha === $hoy) ? self::ES_HOY : self::YA_PASO)
                : ($proyecta ? null : 'El fletero es habitual: sus pagos puntuales no se proyectan.'),
            'usuario' => isset($pp['USUARIO_MODIF']) ? $pp['USUARIO_MODIF'] : null,
            'fecha_modif' => isset($pp['FECHA_MODIF']) ? $pp['FECHA_MODIF'] : null,
            'usuario_alta' => isset($pp['USUARIO_ALTA']) ? $pp['USUARIO_ALTA'] : null,
            'fecha_alta' => isset($pp['FECHA_ALTA']) ? $pp['FECHA_ALTA'] : null
        ];
    }

    /**
     * Los pagos puntuales de un fletero, en orden de fecha, para la pantalla.
     *
     * @param array $puntuales
     * @param string $hoy
     * @param bool $habitual
     * @return array
     */
    private static function listaPuntuales($puntuales, $hoy, $habitual) {
        $v = [];

        foreach ($puntuales as $pp) {
            $v[] = self::pagoPuntual($pp, $hoy, !$habitual);
        }

        usort($v, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']); });

        return $v;
    }

    /**
     * Cuantos pagos puntuales futuros dejan de proyectar si un no habitual
     * vuelve a habitual, y por cuanto. Es lo que dice la confirmacion.
     *
     * Estatica y pura.
     *
     * @param array $puntuales Filas con FECHA e IMPORTE
     * @param string $hoy
     * @return array ['cantidad' => int, 'total' => float]
     */
    public static function puntualesFuturos($puntuales, $hoy) {
        $n = 0;
        $total = 0.0;

        foreach ($puntuales as $pp) {
            if (substr((string) $pp['FECHA'], 0, 10) > $hoy) {
                $n++;
                $total += floatval($pp['IMPORTE']);
            }
        }

        return ['cantidad' => $n, 'total' => $total];
    }

    /** Un fletero sin la marca es habitual: es lo que eran todos antes de la marca */
    public static function esHabitual($f) {
        return !isset($f['HABITUAL']) || $f['HABITUAL'] === null || (bool) $f['HABITUAL'];
    }

    /** @return array Mapa COD_PROVEE => [filas] */
    private static function indexarPuntuales($puntuales) {
        $v = [];

        foreach (is_array($puntuales) ? $puntuales : [] as $p) {
            $v[trim((string) $p['COD_PROVEE'])][] = $p;
        }

        return $v;
    }

    /** @return array Mapa COD_PROVEE => ['Y-m|nro' => edicion] */
    private static function indexarEdiciones($ediciones) {
        $v = [];

        foreach (is_array($ediciones) ? $ediciones : [] as $e) {
            $v[trim((string) $e['COD_PROVEE'])][trim((string) $e['MES']) . '|' . intval($e['NRO_PAGO'])] = $e;
        }

        return $v;
    }

    /**
     * Una celda de la planilla: el valor hora del mes, el importe y sus pagos.
     *
     * @param array $detalle Lo que devolvio LogisticaValorHora::delMes()
     * @param float|null $horas
     * @param string $mes 'Y-m'
     * @param array $porMes Cronograma indexado por mes
     * @param string $hoy 'Y-m-d'
     * @param array $ediciones Mapa 'Y-m|nro' => edicion vigente del fletero
     * @return array
     */
    private static function unMes($detalle, $horas, $mes, $porMes, $hoy, $ediciones = []) {
        $valorHora = $detalle['valor'];
        $motivo = $detalle['motivo'];

        /* SIN HORAS NO HAY IMPORTE, aunque el valor hora se haya podido
           calcular. Se informan los dos por separado: el valor hora del mes es
           un dato correcto y util -se ve en la planilla- y lo que falta es
           cuantas horas multiplicarlo. */
        if ($horas === null && $motivo === LogisticaValorHora::OK) {
            $motivo = self::SIN_HORAS;
        }

        $importeMes = ($valorHora !== null && $horas !== null) ? $valorHora * $horas : null;

        $celda = [
            'mes' => $mes,
            'valor_hora' => $valorHora,
            'motivo' => $motivo,
            'tooltip' => ($motivo === self::SIN_HORAS)
                ? 'El valor hora de este mes se pudo calcular, pero el fletero no tiene horas '
                  . 'por mes cargadas, así que no hay importe.'
                : LogisticaValorHora::explicar($detalle),
            'ajusta' => $detalle['ajusta'],
            'rige_desde' => $detalle['rige_desde'],
            'importe_mes' => $importeMes,
            'importe_proy' => null,
            'pagos' => []
        ];

        $delMes = isset($porMes[$mes]) ? $porMes[$mes] : [];

        /* SIEMPRE SE DIBUJAN TODOS LOS PAGOS DEL MES, tambien cuando no hay
           importe: la fecha de pago es un dato del cronograma y no del fletero,
           y una planilla que esconde la fecha cuando falta el importe obliga a
           ir a buscarla a otra pantalla. */
        foreach ($delMes as $nro => $pago) {
            /* PARTES IGUALES ENTRE LOS PAGOS DEL MES: mitad y mitad con el
               cronograma de siempre, un cuarto o un quinto si alguien lo pasa a
               semanal. Cuantos son lo dice el cronograma de ESE mes y no una
               constante: con el viernes escrito en el codigo eran siempre dos, y
               ahora el dia y la frecuencia salen de Parametros -> Generales. No
               se redondea: ver CronogramaPagos::parte(). */
            $importe = CronogramaPagos::parte($importeMes, count($delMes));
            $calculado = $importe;

            /* UN PAGO CON FECHA ANTERIOR O IGUAL A HOY YA SE HIZO. Ver el
               encabezado: no se proyecta y no se avisa, pero se ve marcado. */
            $excluido = ($pago['fecha'] <= $hoy);

            /* EL IMPORTE REAL CARGADO A MANO, si hay uno vigente para ESTE pago.
               Solo cambia este pago: los demas del mes y los siguientes siguen
               con la cuenta. Un pago que ya paso conserva la edicion como dato,
               pero no proyecta: queda en el historial. */
            $ed = isset($ediciones[$mes . '|' . intval($nro)]) ? $ediciones[$mes . '|' . intval($nro)] : null;

            if ($ed !== null) {
                $importe = floatval($ed['IMPORTE']);
            }

            $celda['pagos'][] = [
                'mes' => $mes,
                'nro' => $nro,
                'fecha' => $pago['fecha'],
                'en_tramo' => !empty($pago['en_tramo']),
                'override' => !empty($pago['override']),
                'corrida' => !empty($pago['corrida']),
                'importe' => $importe,
                'importe_calculado' => $calculado,
                'editado' => ($ed !== null),
                'edicion' => ($ed === null) ? null : [
                    'importe' => floatval($ed['IMPORTE']),
                    'importe_calculado_al_editar' => isset($ed['IMPORTE_CALCULADO'])
                        && $ed['IMPORTE_CALCULADO'] !== null ? floatval($ed['IMPORTE_CALCULADO']) : null,
                    'usuario' => isset($ed['USUARIO_ALTA']) ? $ed['USUARIO_ALTA'] : null,
                    'fecha' => isset($ed['FECHA_ALTA']) ? $ed['FECHA_ALTA'] : null
                ],
                'proximo' => false,
                'excluido' => $excluido,
                'motivo_excluido' => $excluido
                    ? (($pago['fecha'] === $hoy) ? self::ES_HOY : self::YA_PASO)
                    : null
            ];

            if (!$excluido && $importe !== null) {
                $celda['importe_proy'] = ($celda['importe_proy'] === null)
                    ? $importe : $celda['importe_proy'] + $importe;
            }
        }

        return $celda;
    }

    /**
     * El detalle de un mes cuando el fletero no tiene MES_BASE.
     *
     * Devuelve la MISMA estructura que LogisticaValorHora::delMes() para que
     * quien consume no tenga que preguntar cual de los dos recibio. Va aparte
     * porque sin mes base no hay ninguna cadena de ajustes que recorrer: no es
     * un caso de esa funcion, es su precondicion.
     *
     * @param string $mes 'Y-m'
     * @return array
     */
    private static function sinMesBase($mes) {
        return [
            'mes' => $mes,
            'valor' => null,
            'motivo' => LogisticaValorHora::SIN_BASE,
            'ajusta' => false,
            'pct' => null,
            'componentes' => [],
            'rige_desde' => '',
            'faltan' => []
        ];
    }

    /**
     * Indexa el cronograma por mes y numero de pago.
     *
     * @param array $pagos Lista tal como la devuelve CronogramaPagos
     * @return array Mapa 'Y-m' => [nro => pago]
     */
    public static function pagosPorMes($pagos) {
        $mapa = [];

        foreach ($pagos as $p) {
            $mapa[$p['mes']][intval($p['nro'])] = $p;
        }

        /* Los pagos de un mes van EN ORDEN: la planilla los dibuja asi y el
           reparto se lee mal si el 4to pago aparece antes que el 2do. */
        foreach ($mapa as $mes => $delMes) {
            ksort($mapa[$mes]);
        }

        return $mapa;
    }

    /**
     * Los pagos que efectivamente se proyectan, listos para acumular contra el
     * eje.
     *
     * LO QUE DEVUELVE VA DERECHO A Horizonte::acumular(), que es quien decide
     * si cada importe cae en una columna diaria o en la de su mes. Esta clase
     * NO toma esa decision: escribirla acá seria una segunda version de la
     * regla del eje, y la que ya existe está probada.
     *
     * @param array $planilla Lo que devolvio calcular()
     * @return array Lista de ['fecha', 'importe', 'cod_provee', 'mes', 'nro']
     */
    public static function pagosAProyectar($planilla) {
        $v = [];

        foreach ($planilla['pagos'] as $p) {
            if ($p['excluido'] || $p['importe'] === null) {
                continue;
            }

            $v[] = $p;
        }

        return $v;
    }
}
