<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * EjeVista
 * Las TRES VISTAS de una pantalla con eje temporal: Dias, Meses y Periodo
 * completo. Es el criterio unico de todo el modulo.
 *
 * POR QUE EXISTE
 * --------------
 * El eje ya lo define Horizonte. Lo que faltaba era el criterio de que columnas
 * muestra cada vista, que total le corresponde y que periodo esta midiendo, y
 * eso vivia escrito CUATRO VECES y de tres formas distintas:
 *
 *   - Cashflow: bien, con las tres vistas sobre el eje de Horizonte, en metodos
 *     privados del motor.
 *   - Proveedores Exterior, Crono Nacionalizacion y Cobranzas FR: cada una con
 *     su propio procesar*PorPeriodo() copiado y pegado, agrupando por DIA DEL
 *     MES (1..31) del mes en curso y por doce meses fijos.
 *
 * Ese segundo criterio esta mal de tres maneras, y las tres son silenciosas:
 *
 *   1. La vista de dias mostraba los dias DEL MES EN CURSO, incluidos los que ya
 *      pasaron, mientras el encabezado decia "Proximos 28 Dias". El titulo no
 *      describia la tabla.
 *   2. Un importe del mes en curso se contaba en la vista de dias Y en la
 *      columna de su mes. Las dos vistas no reconciliaban entre si.
 *   3. La ventana era fija -el mes actual y doce meses- e ignoraba
 *      horizonte_dias y horizonte_meses, que son parametros editables. Y lo que
 *      caia afuera se descartaba SIN AVISAR.
 *
 * LA REGLA, UNA SOLA VEZ
 * ----------------------
 * Un importe va a una columna diaria O a la columna de su mes, NUNCA a las dos:
 * la columna de un mes acumula unicamente los dias de ese mes que quedaron
 * fuera del tramo diario. La implementa Horizonte::agrupar() y es lo que hace
 * que las tres vistas sean sumables entre si.
 *
 *   'dias'     -> las columnas diarias
 *   'meses'    -> las columnas mensuales, que cubren SOLO los dias de fuera del
 *                 tramo diario, asi que su total NO es el del horizonte
 *   'completo' -> las dos ramas, en orden cronologico real
 *
 * El total de cada vista suma exactamente sus propias columnas. Un indicador
 * calculado sobre el tramo diario mientras la pantalla muestra los meses no
 * describe nada de lo que hay en pantalla.
 *
 * LO QUE CAE AFUERA SE INFORMA SIEMPRE
 * ------------------------------------
 * 'descartes' trae lo que quedo fuera del eje y lo que no tenia fecha
 * utilizable. Una pantalla que muestra de menos sin decirlo es peor que una que
 * falla.
 *
 * PARA USARLA EN UNA PESTANA NUEVA
 * --------------------------------
 *   $h = Horizonte::desdeParametros(new Parametros());
 *   $payload = EjeVista::armar($h, $items, 'FECHA_PAGO', 'IMPORTE');
 *
 * y en el front, Js/eje-vistas.js dibuja los botones, los encabezados y los
 * totales a partir de ese payload. No hay que reimplementar nada.
 */
class EjeVista {

    /** Las tres vistas, en el orden en que se muestran */
    const VISTAS = ['dias', 'meses', 'completo'];

    /**
     * El eje y las tres vistas, sin filas.
     *
     * Es lo que consume el front para dibujar encabezados y botones. Lo usa
     * tambien el motor del Cashflow, para que el tablero y las pestanas de
     * detalle no puedan describir el eje de dos formas distintas.
     *
     * @param Horizonte $h
     * @return array
     */
    public static function eje($h) {
        $secuencia = $h->secuencia();

        return [
            'generado' => $h->hoy(),
            'horizonte_dias' => $h->cantidadDias(),
            'horizonte_meses' => $h->cantidadMeses(),
            'dias' => self::ejeDias($h),
            'meses' => self::ejeMeses($h),
            'secuencia' => $secuencia,
            'vistas' => self::vistas($h)
        ];
    }

    /**
     * Payload completo de una pestana: el eje, una fila por item con sus
     * importes por columna, los totales y lo que quedo afuera.
     *
     * Cada fila conserva los campos originales del item y le suma 'dias',
     * 'meses' y los tres totales, con los mismos nombres que usa el tablero.
     * Asi el front es el mismo para las dos cosas.
     *
     * @param Horizonte $h
     * @param array $items Registros crudos
     * @param string $campoFecha Campo con la fecha ('Y-m-d', DateTime o timestamp)
     * @param string $campoImporte Campo con el importe
     * @param float $factor Multiplicador, por ejemplo un tipo de cambio
     * @return array
     */
    public static function armar($h, $items, $campoFecha, $campoImporte, $factor = 1) {
        $payload = self::eje($h);

        $filas = [];
        $descartes = ['fuera_horizonte' => 0, 'sin_fecha' => 0];

        // Los totales del pie se acumulan sobre una serie propia y no sumando
        // las filas columna por columna: es la misma cuenta con la mitad de
        // recorridos, y no se puede desincronizar de las filas porque sale del
        // mismo agrupador.
        $totales = $h->serieVacia();

        foreach (is_array($items) ? $items : [] as $item) {
            $serie = $h->agrupar([$item], $campoFecha, $campoImporte, $factor);

            $descartes['fuera_horizonte'] += $serie['fuera_horizonte'];
            $descartes['sin_fecha'] += $serie['sin_fecha'];

            foreach ($serie['dias'] as $k => $v) {
                $totales['dias'][$k] += $v;
            }

            foreach ($serie['meses'] as $k => $v) {
                $totales['meses'][$k] += $v;
            }

            $filas[] = array_merge($item, self::conTotales($serie['dias'], $serie['meses']));
        }

        $payload['filas'] = $filas;
        $payload['totales'] = self::conTotales($totales['dias'], $totales['meses']);
        $payload['descartes'] = $descartes;
        $payload['warnings'] = self::avisosDescartes($descartes);

        return $payload;
    }

    /**
     * Agrega a un par de mapas dias/meses los tres totales por vista.
     *
     * El total de la vista Meses NO es el del horizonte: las columnas mensuales
     * cubren solo los dias que quedaron fuera del tramo diario. Por eso son tres
     * numeros y no uno.
     *
     * @param array $dias Mapa 'Y-m-d' => importe
     * @param array $meses Mapa 'Y-m' => importe
     * @return array
     */
    public static function conTotales($dias, $meses) {
        $tramo = array_sum(array_map('floatval', $dias));
        $mensual = array_sum(array_map('floatval', $meses));

        return [
            'dias' => $dias,
            'meses' => $meses,
            'total_tramo' => $tramo,
            'total_meses' => $mensual,
            'total_horizonte' => $tramo + $mensual
        ];
    }

    /**
     * Que columnas muestra cada vista y que periodo esta midiendo.
     *
     * Solo entran las columnas de la SECUENCIA: las que no representan ningun
     * dia futuro -la del mes en curso cuando el tramo diario arranca hoy- no
     * aportan nada y ademas el tablero les pone null.
     *
     * @param Horizonte $h
     * @return array Mapa vista => ['columnas' => [...], 'periodo' => string, 'label' => string]
     */
    public static function vistas($h) {
        $enSecuencia = array_fill_keys($h->secuencia(), true);

        $colsDias = [];
        $colsMeses = [];

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

        $completo = array_merge($colsDias, $colsMeses);

        return [
            'dias' => [
                'label' => 'Días',
                'columnas' => $colsDias,
                'periodo' => self::rotuloPeriodo($colsDias, 'dias')
            ],
            'meses' => [
                'label' => 'Meses',
                'columnas' => $colsMeses,
                'periodo' => self::rotuloPeriodo($colsMeses, 'meses')
            ],
            'completo' => [
                'label' => 'Período completo',
                'columnas' => $completo,
                'periodo' => self::rotuloPeriodo($completo, 'completo')
            ]
        ];
    }

    /**
     * Rotulo del periodo que cubre un conjunto de columnas, para que la
     * pantalla diga sobre que esta midiendo.
     *
     * @param array $cols
     * @param string $vista
     * @return string
     */
    public static function rotuloPeriodo($cols, $vista) {
        if (empty($cols)) {
            return 'Sin columnas';
        }

        $desde = self::rotulo($cols[0]);
        $hasta = self::rotulo($cols[count($cols) - 1]);

        if ($vista === 'dias') {
            return 'Del ' . $desde . ' al ' . $hasta;
        }

        if ($vista === 'meses') {
            // Se aclara que el tramo mensual arranca DESPUES del diario: la
            // primera columna mensual acumula solo los dias del mes que quedan
            // fuera del tramo, asi que este numero no es el del horizonte.
            return 'De ' . $desde . ' a ' . $hasta . ', después del tramo diario';
        }

        return 'Del ' . $desde . ' a ' . $hasta . ', todo el horizonte';
    }

    /**
     * Avisos de lo que no entro en la pantalla. Se informa SIEMPRE.
     *
     * @param array $descartes
     * @return array
     */
    public static function avisosDescartes($descartes) {
        $avisos = [];

        if (!empty($descartes['fuera_horizonte'])) {
            $avisos[] = self::plata($descartes['fuera_horizonte'])
                . ' con fecha fuera del horizonte no se muestran en la tabla.';
        }

        if (!empty($descartes['sin_fecha'])) {
            $avisos[] = self::plata($descartes['sin_fecha'])
                . ' sin fecha asignada no se pueden ubicar en el tiempo y quedan fuera de la tabla.';
        }

        return $avisos;
    }

    /* ====================================================================
       COLUMNAS
       ==================================================================== */

    /** 'DIA|2026-09-06' => ['dias', '2026-09-06'] */
    public static function partir($col) {
        if (strpos($col, 'DIA|') === 0) {
            return ['dias', substr($col, 4)];
        }

        return ['meses', substr($col, 4)];
    }

    /**
     * Valor de una fila en una columna. Devuelve 0 si la columna no existe, para
     * que quien suma no tenga que preguntar.
     *
     * @param array $fila Fila con 'dias' y 'meses'
     * @param string|null $col
     * @return float
     */
    public static function valor($fila, $col) {
        if ($col === null) {
            return 0;
        }

        list($rama, $clave) = self::partir($col);

        return isset($fila[$rama][$clave]) ? floatval($fila[$rama][$clave]) : 0;
    }

    /** Rotulo corto de una columna: '6/9' o 'Sep-26' */
    public static function rotulo($col) {
        list($rama, $clave) = self::partir($col);

        if ($rama === 'dias') {
            $t = strtotime($clave);

            return intval(date('j', $t)) . '/' . intval(date('n', $t));
        }

        $partes = explode('-', $clave);

        return Horizonte::labelMes(intval($partes[0]), intval($partes[1]));
    }

    /* ====================================================================
       EL EJE, CON LAS MARCAS QUE NECESITA EL FRONT
       ==================================================================== */

    /** Eje de dias con la marca de si entra en la secuencia cronologica */
    public static function ejeDias($h) {
        $enSecuencia = array_fill_keys($h->secuencia(), true);
        $v = [];

        foreach ($h->dias() as $d) {
            $d['en_secuencia'] = isset($enSecuencia['DIA|' . $d['fecha']]);
            $v[] = $d;
        }

        return $v;
    }

    /**
     * Eje de meses. 'parcial' marca los meses cuya columna acumula solo una
     * parte del mes, porque el resto de sus dias esta en el tramo diario. Sin
     * esa marca, un mes recortado se lee como una caida de importe.
     */
    public static function ejeMeses($h) {
        $enSecuencia = array_fill_keys($h->secuencia(), true);
        $diasPorMes = [];

        foreach ($h->dias() as $d) {
            if (!isset($diasPorMes[$d['mes_clave']])) {
                $diasPorMes[$d['mes_clave']] = 0;
            }

            $diasPorMes[$d['mes_clave']]++;
        }

        $v = [];

        foreach ($h->meses() as $m) {
            $m['en_secuencia'] = isset($enSecuencia['MES|' . $m['clave']]);
            $m['parcial'] = isset($diasPorMes[$m['clave']]);
            $v[] = $m;
        }

        return $v;
    }

    /** Formato de importe para los mensajes de aviso */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }
}
