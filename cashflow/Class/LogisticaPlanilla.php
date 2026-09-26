<?php

require_once __DIR__ . '/LogisticaValorHora.php';

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
 *   se paga MITAD Y MITAD en los dos pagos del mes (2do y 4to viernes)
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
 * ES PURA: no toca la base ni lee parametros. Recibe los fleteros, el eje, el
 * cronograma y el mapa de inflacion, y devuelve numeros.
 */
class LogisticaPlanilla {

    /** Cuantos pagos tiene un mes. Ver CronogramaPagos */
    const PAGOS_POR_MES = 2;

    /* Por que un mes no proyecta. Los de valor hora salen de
       LogisticaValorHora; este es el propio de la planilla. */
    const SIN_HORAS = 'SIN_HORAS';

    /**
     * La planilla completa.
     *
     * @param array $fleteros Filas con COD_PROVEE, NOMBRE, HORAS_MES,
     *                        VALOR_HORA_BASE, MES_BASE
     * @param Horizonte $h Eje temporal
     * @param array $pagos Cronograma, tal como lo devuelve
     *                     CronogramaPagos::paraHorizonte()['pagos']
     * @param array $inflacion Mapa 'Y-m' => float, en puntos porcentuales
     * @return array ['fleteros' => [...], 'totales' => [...], 'pagos' => [...],
     *                'avisos' => [...], 'meses' => ['Y-m']]
     */
    public static function calcular($fleteros, $h, $pagos, $inflacion) {
        $meses = array_column($h->meses(), 'clave');
        $porMes = self::pagosPorMes($pagos);
        $hoy = $h->hoy();

        $filas = [];
        $avisos = [];
        $todosLosPagos = [];
        $totalMeses = array_fill_keys($meses, null);
        $totalGeneral = null;

        foreach ($fleteros as $f) {
            $fila = self::unFletero($f, $meses, $porMes, $inflacion, $hoy);

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
     * Una fila de la planilla: un fletero, sus doce meses y sus veinticuatro
     * pagos.
     *
     * @param array $f Fila del fletero
     * @param array $meses Lista de 'Y-m'
     * @param array $porMes Cronograma indexado por mes
     * @param array $inflacion Mapa de inflacion
     * @param string $hoy 'Y-m-d'
     * @return array
     */
    private static function unFletero($f, $meses, $porMes, $inflacion, $hoy) {
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
            'horas_mes' => $horas,
            'valor_hora_base' => $valorBase,
            'mes_base' => ($mesBase === '' ? null : $mesBase),
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

            $celda = self::unMes($detalle, $horas, $mes, $porMes, $hoy);

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

        return $fila;
    }

    /**
     * Una celda de la planilla: el valor hora del mes, el importe y sus dos
     * pagos.
     *
     * @param array $detalle Lo que devolvio LogisticaValorHora::delMes()
     * @param float|null $horas
     * @param string $mes 'Y-m'
     * @param array $porMes Cronograma indexado por mes
     * @param string $hoy 'Y-m-d'
     * @return array
     */
    private static function unMes($detalle, $horas, $mes, $porMes, $hoy) {
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

        /* SIEMPRE SE DIBUJAN LOS DOS PAGOS, tambien cuando no hay importe: la
           fecha de pago es un dato del cronograma y no del fletero, y una
           planilla que esconde la fecha cuando falta el importe obliga a ir a
           buscarla a otra pantalla. */
        foreach ($delMes as $nro => $pago) {
            /* LA MITAD Y LA MITAD. No se redondea el medio importe: el redondeo
               es presentacion, y redondear aca haria que los dos pagos no sumen
               el importe del mes en los centavos. */
            $importe = ($importeMes === null) ? null : $importeMes / self::PAGOS_POR_MES;

            /* UN PAGO CON FECHA ANTERIOR O IGUAL A HOY YA SE HIZO. Ver el
               encabezado: no se proyecta y no se avisa, pero se ve marcado. */
            $excluido = ($pago['fecha'] <= $hoy);

            $celda['pagos'][] = [
                'mes' => $mes,
                'nro' => $nro,
                'fecha' => $pago['fecha'],
                'en_tramo' => !empty($pago['en_tramo']),
                'override' => !empty($pago['override']),
                'corrida' => !empty($pago['corrida']),
                'importe' => $importe,
                'excluido' => $excluido,
                'motivo_excluido' => $excluido
                    ? (($pago['fecha'] === $hoy) ? 'Es hoy: ese pago ya se hizo.'
                                                 : 'Ya pasó: ese pago ya se hizo.')
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

        /* Los dos pagos de un mes van EN ORDEN: la planilla los dibuja asi y el
           reparto mitad/mitad se lee mal si el 4to viernes aparece primero. */
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
