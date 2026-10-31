<?php

require_once __DIR__ . '/Inflacion.php';

/**
 * GastosLocalesCalculo
 * Cuanto se proyecta de los gastos que los locales pagan con la caja, y en que
 * columna del eje cae cada parte.
 *
 * LA BASE ES EL PROMEDIO DE LOS ULTIMOS 90 DIAS
 * ---------------------------------------------
 * De los egresos de caja (CTA29, D_H = 'D') de las cuentas que empiezan con 5,
 * menos las excluidas por parametro. Se filtra por CUENTA y no por tipo de
 * comprobante: un talonario mal elegido no cambia de que cuenta sale la plata.
 *
 *   gasto diario de un local = su total de los 90 dias / 90
 *
 * Siempre se divide por 90, tambien para un local que gasto en pocos dias: un
 * dia sin gastos tambien es un dato. Es el mismo criterio que Gastos
 * Supervisoras, que divide siempre por tres meses.
 *
 * LA INFLACION SE COMPONE DESDE EL MES EN CURSO
 * ----------------------------------------------
 * factor(M) = Inflacion::compuesta(mapa, mes en curso, M). El mes en curso vale
 * 1; noviembre lleva la de noviembre; diciembre la de noviembre por la de
 * diciembre. Compuesta y no sumada: es llevar un promedio historico a moneda de
 * un mes futuro, no reproducir un ajuste pactado. Ver Inflacion.
 *
 * Sin el % de algun mes del camino, ese importe queda en null con su motivo y
 * los meses que no lo necesitan se proyectan igual. Nunca un cero: un cero se
 * leeria como que ese mes los locales no gastan.
 *
 * DOS RAMAS DEL EJE, DOS REGLAS
 * -----------------------------
 *   TRAMO DIARIO  un importe por SEMANA, el dia del cronograma GASTOS_LOCALES
 *                 (por defecto el lunes), corrido al habil SIGUIENTE:
 *                     semana = diario x 7 x factor(mes de la fecha de pago)
 *
 *   MESES         un importe por MES, sin dia:
 *                     mes = diario x dias del mes fuera del tramo x factor(M)
 *
 * NADA SE CUENTA DOS VECES, Y POR QUE
 * -----------------------------------
 * Una semana pertenece al tramo si su fecha TEORICA (antes del corrimiento) cae
 * en el tramo. Los meses suman SOLO los dias posteriores al fin del tramo, y no
 * miran los pagos semanales para nada. Asi, si el ultimo lunes del tramo es
 * feriado y el pago se corre al martes -que ya esta fuera del tramo-, ese pago
 * cae en la columna de su mes (lo decide Horizonte::acumular()) pero sigue
 * siendo la semana del tramo: el mes no la vuelve a contar, porque el mes cuenta
 * dias y no pagos.
 *
 * UNA FECHA DE PAGO IGUAL O ANTERIOR A HOY NO SE PROYECTA
 * -------------------------------------------------------
 * Esa plata ya salio y ya esta en el saldo bancario que abre el cuadro. Se mira
 * la fecha EFECTIVA, la ya corrida: si hoy es un lunes feriado, el pago se corre
 * a manana, todavia no salio y SI se proyecta. Mismo criterio que Logistica.
 *
 * ES PURA: no toca la base. Recibe los gastos ya leidos, el eje, los pagos del
 * cronograma y el mapa de inflacion, y devuelve numeros.
 */
class GastosLocalesCalculo {

    /** Cuantos dias corridos entran en el promedio */
    const DIAS_VENTANA = 90;

    /** Una semana de gasto */
    const DIAS_SEMANA = 7;

    /** Con cuantos dias se dice el promedio mensual de la tarjeta */
    const DIAS_MES = 30;

    /* Por que un importe no se proyecta */
    const OK = 'OK';
    const SIN_INFLACION = 'SIN_INFLACION';

    /* Las dos clases de importe */
    const SEMANA = 'SEMANA';
    const MES = 'MES';

    /** La leyenda de un local propio sin egresos en la ventana */
    const SIN_EGRESOS = 'sin egresos en 90 días';

    /* ====================================================================
       EL PROMEDIO POR LOCAL
       ==================================================================== */

    /**
     * Una fila por local, con su total, su gasto diario y su detalle por cuenta.
     *
     * LOS LOCALES SIN EGRESOS VAN IGUAL, CON CERO: son locales propios
     * habilitados que en 90 dias no pagaron nada con la caja. Ese cero es un
     * dato -la consulta respondio y no hay movimientos-, no un faltante, y
     * esconderlos haria creer que no estan en el universo.
     *
     * Estatica y pura.
     *
     * @param array $filas Filas de la consulta, una por (local, cuenta):
     *                     NRO_SUCURS, DESC_SUCURSAL, COD_CTA, DESC_CUENTA,
     *                     MOVIMIENTOS, TOTAL
     * @param array $sucursales Los locales propios habilitados: NRO_SUCURSAL,
     *                          DESC_SUCURSAL
     * @return array Mapa nro => ['nro', 'nombre', 'total', 'diario', 'movimientos',
     *               'sin_egresos' => bool, 'cuentas' => [...]], ordenado por nro
     */
    public static function locales($filas, $sucursales = []) {
        $v = [];

        foreach (is_array($sucursales) ? $sucursales : [] as $s) {
            $nro = intval($s['NRO_SUCURSAL']);
            $v[$nro] = self::localVacio($nro, $s['DESC_SUCURSAL']);
        }

        foreach (is_array($filas) ? $filas : [] as $f) {
            $nro = intval($f['NRO_SUCURS']);

            if (!isset($v[$nro])) {
                $v[$nro] = self::localVacio($nro, isset($f['DESC_SUCURSAL']) ? $f['DESC_SUCURSAL'] : '');
            }

            $total = floatval($f['TOTAL']);
            $movs = isset($f['MOVIMIENTOS']) ? intval($f['MOVIMIENTOS']) : 0;

            $v[$nro]['total'] += $total;
            $v[$nro]['movimientos'] += $movs;
            $v[$nro]['cuentas'][] = [
                'cod_cta' => trim((string) $f['COD_CTA']),
                'descripcion' => trim((string) (isset($f['DESC_CUENTA']) ? $f['DESC_CUENTA'] : '')),
                'movimientos' => $movs,
                'total' => $total,
                'diario' => $total / self::DIAS_VENTANA
            ];
        }

        foreach ($v as $nro => $l) {
            /* NO SE REDONDEA EL DIARIO: el redondeo es presentacion, y aca se
               multiplicaria por siete, por treinta y por la inflacion. */
            $v[$nro]['diario'] = $l['total'] / self::DIAS_VENTANA;
            $v[$nro]['sin_egresos'] = ($l['movimientos'] === 0);

            // La cuenta que mas pesa primero: es la que hay que mirar.
            usort($v[$nro]['cuentas'], function ($a, $b) {
                return ($b['total'] <=> $a['total']) ?: strcmp($a['cod_cta'], $b['cod_cta']);
            });
        }

        ksort($v);

        return $v;
    }

    private static function localVacio($nro, $nombre) {
        return ['nro' => intval($nro), 'nombre' => trim((string) $nombre), 'total' => 0.0,
                'diario' => 0.0, 'movimientos' => 0, 'sin_egresos' => true, 'cuentas' => []];
    }

    /* ====================================================================
       LAS SEMANAS DEL TRAMO
       ==================================================================== */

    /**
     * Ultimo dia del tramo diario. Sin tramo -horizonte_dias en cero- es hoy:
     * todo lo posterior va a los meses.
     *
     * @param Horizonte $h
     * @return string 'Y-m-d'
     */
    public static function finTramo($h) {
        $dias = $h->dias();

        return empty($dias) ? $h->hoy() : $dias[count($dias) - 1]['fecha'];
    }

    /**
     * Los pagos semanales que se proyectan: los del cronograma cuya fecha
     * TEORICA cae hasta el fin del tramo y cuya fecha EFECTIVA es posterior a
     * hoy.
     *
     * Ver el encabezado: la teorica decide si la semana es del tramo; la
     * efectiva, si ya se pago y en que columna cae.
     *
     * Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos Los pagos de CronogramaPagos::paraHorizonte()
     * @return array Lista de ['fecha', 'teorica', 'corrida', 'mes', 'nombre'], en orden
     */
    public static function semanas($h, $pagos) {
        $hoy = $h->hoy();
        $fin = self::finTramo($h);
        $v = [];

        if (empty($h->dias())) {
            return $v;
        }

        foreach (is_array($pagos) ? $pagos : [] as $p) {
            $teorica = isset($p['teorica']) ? $p['teorica'] : $p['fecha'];

            if ($teorica > $fin || $p['fecha'] <= $hoy) {
                continue;
            }

            $v[] = [
                'fecha' => $p['fecha'],
                'teorica' => $teorica,
                'corrida' => !empty($p['corrida']),
                'mes' => substr($p['fecha'], 0, 7),
                'nombre' => isset($p['nombre']) ? $p['nombre'] : ''
            ];
        }

        usort($v, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']); });

        return $v;
    }

    /**
     * Cuantos dias de cada mes del horizonte caen DESPUES del tramo diario (y
     * despues de hoy), hasta el fin del eje, y el primero de ellos.
     *
     * Es la mitad de la regla "un dia va a la semana o al mes, nunca a los
     * dos": un mes partido por el final del tramo cuenta solo su cola.
     *
     * Estatica y pura.
     *
     * @param Horizonte $h
     * @return array Mapa 'Y-m' => ['dias' => int, 'desde' => 'Y-m-d'|null]
     */
    public static function diasFueraDelTramo($h) {
        $desde = max(self::finTramo($h), $h->hoy());
        $fin = $h->fin();
        $v = [];

        foreach ($h->meses() as $m) {
            $v[$m['clave']] = ['dias' => 0, 'desde' => null];
        }

        $cursor = new DateTime($desde);
        $cursor->modify('+1 day');
        $tope = new DateTime($fin);

        while ($cursor <= $tope) {
            $f = $cursor->format('Y-m-d');
            $mes = substr($f, 0, 7);

            if (isset($v[$mes])) {
                $v[$mes]['dias']++;

                if ($v[$mes]['desde'] === null) {
                    $v[$mes]['desde'] = $f;
                }
            }

            $cursor->modify('+1 day');
        }

        return $v;
    }

    /* ====================================================================
       LA PROYECCION
       ==================================================================== */

    /**
     * Los importes de cada local: una partida por semana del tramo y una por
     * mes fuera del tramo.
     *
     * CADA PARTIDA TRAE SU FECHA, que es la que ubica el importe en el eje con
     * Horizonte::acumular(): la de pago para una semana, el primer dia fuera del
     * tramo para un mes (que siempre cae en la columna de ese mes).
     *
     * EL TOTAL DEL TABLERO ES LA SUMA DE ESTOS IMPORTES, local por local. No se
     * calcula aparte con el total de los locales: con un calculo paralelo, la
     * fila del tablero y el pie de la pestana podrian diferir en un centavo.
     *
     * Estatica y pura.
     *
     * @param array $locales Lo que devolvio locales()
     * @param Horizonte $h
     * @param array $semanas Lo que devolvio semanas()
     * @param array $inflacion Mapa 'Y-m' => float, en puntos
     * @return array ['locales' => mapa nro => fila con 'partidas' y 'total',
     *                'partidas' => lista de todas, 'faltan_inflacion' => ['Y-m'],
     *                'mes_base' => 'Y-m', 'factores' => mapa 'Y-m' => compuesta()]
     */
    public static function proyectar($locales, $h, $semanas, $inflacion) {
        $mesBase = substr($h->hoy(), 0, 7);
        $fuera = self::diasFueraDelTramo($h);

        $meses = array_keys($fuera);

        foreach ($semanas as $s) {
            $meses[] = $s['mes'];
        }

        $factores = Inflacion::compuestaParaMeses(is_array($inflacion) ? $inflacion : [],
            $mesBase, array_values(array_unique($meses)));

        $salida = [];
        $todas = [];

        foreach ($locales as $nro => $l) {
            $fila = $l;
            $fila['partidas'] = [];
            $fila['total_proyectado'] = null;

            foreach ($semanas as $s) {
                $fila['partidas'][] = self::partida($l, self::SEMANA, $s['fecha'], $s['mes'],
                    self::DIAS_SEMANA, $factores['factores'][$s['mes']], $s);
            }

            foreach ($fuera as $mes => $f) {
                if ($f['dias'] === 0) {
                    continue;
                }

                $fila['partidas'][] = self::partida($l, self::MES, $f['desde'], $mes,
                    $f['dias'], $factores['factores'][$mes], null);
            }

            foreach ($fila['partidas'] as $p) {
                if ($p['importe'] !== null) {
                    $fila['total_proyectado'] = ($fila['total_proyectado'] === null)
                        ? $p['importe'] : $fila['total_proyectado'] + $p['importe'];
                }

                $todas[] = $p;
            }

            $salida[$nro] = $fila;
        }

        /* Los meses que faltan, solo los que alguna partida necesitaba: el mes
           en curso no necesita ninguno. */
        $faltan = [];

        foreach ($todas as $p) {
            if ($p['motivo'] === self::SIN_INFLACION) {
                foreach ($p['faltan'] as $m) {
                    $faltan[$m] = true;
                }
            }
        }

        $faltan = array_keys($faltan);
        sort($faltan);

        return [
            'locales' => $salida,
            'partidas' => $todas,
            'faltan_inflacion' => $faltan,
            'mes_base' => $mesBase,
            'factores' => $factores['factores']
        ];
    }

    /**
     * Una partida: un local, una semana o un mes.
     *
     * @return array ['nro', 'tipo', 'fecha', 'mes', 'dias', 'factor', 'importe',
     *                'motivo', 'faltan', 'tooltip', 'teorica', 'corrida']
     */
    private static function partida($local, $tipo, $fecha, $mes, $dias, $factor, $semana) {
        $importe = ($factor['factor'] === null)
            ? null : $local['diario'] * $dias * $factor['factor'];

        $p = [
            'nro' => $local['nro'],
            'tipo' => $tipo,
            'fecha' => $fecha,
            'mes' => $mes,
            'dias' => $dias,
            'factor' => $factor['factor'],
            'importe' => $importe,
            'motivo' => ($importe === null) ? self::SIN_INFLACION : self::OK,
            'faltan' => $factor['faltan'],
            'teorica' => $semana ? $semana['teorica'] : null,
            'corrida' => $semana ? $semana['corrida'] : false
        ];

        $p['tooltip'] = self::explicar($local, $p, $factor);

        return $p;
    }

    /**
     * De donde sale el importe de una partida. LO ARMA EL BACKEND porque es la
     * explicacion de una cuenta que hace el backend: mismo criterio que
     * LogisticaValorHora::explicar().
     *
     * Estatica y pura.
     */
    public static function explicar($local, $p, $factor) {
        $base = self::plata($local['diario']) . ' por día (' . self::plata($local['total'])
            . ' / ' . self::DIAS_VENTANA . ')';

        $cuando = ($p['tipo'] === self::SEMANA)
            ? 'Semana pagada el ' . self::fechaCorta($p['fecha'])
              . ($p['corrida'] ? ' (el ' . self::fechaCorta($p['teorica'])
                  . ' no es hábil: se corre al día hábil siguiente)' : '')
              . ': ' . self::DIAS_SEMANA . ' días'
            : 'Días del mes fuera de las semanas del tramo diario: ' . $p['dias'];

        if ($p['importe'] === null) {
            return $cuando . '. Falta la inflación esperada de ' . implode(', ', $p['faltan'])
                . ', así que no se puede llevar a moneda de ese mes. Queda en blanco y NO en '
                . 'cero: un cero se leería como que los locales no gastan.';
        }

        if (empty($factor['meses'])) {
            $inf = 'Mes en curso: sin ajuste por inflación';
        } else {
            $detalle = [];

            foreach ($factor['meses'] as $m => $pct) {
                $detalle[] = $m . ': ' . number_format($pct, 2, ',', '.') . ' %';
            }

            $inf = 'Inflación compuesta desde el mes en curso: x'
                . number_format($factor['factor'], 4, ',', '.') . ' (' . implode(' · ', $detalle) . ')';
        }

        return $cuando . '. ' . $base . '. ' . $inf . '. Importe ' . self::plata($p['importe']) . '.';
    }

    /* ====================================================================
       LOS NUMEROS DE LA TARJETA
       ==================================================================== */

    /**
     * Total de los 90 dias, promedio mensual (diario x 30) y lo proyectado en el
     * horizonte, sumando todos los locales.
     *
     * Estatica y pura.
     *
     * @param array $proyectados 'locales' de proyectar()
     * @return array ['total_ventana', 'diario', 'mensual', 'proyectado', 'locales',
     *                'sin_egresos']
     */
    public static function resumen($proyectados) {
        $total = 0.0;
        $proy = null;
        $sin = 0;

        foreach ($proyectados as $l) {
            $total += $l['total'];

            if ($l['sin_egresos']) {
                $sin++;
            }

            if ($l['total_proyectado'] !== null) {
                $proy = ($proy === null) ? $l['total_proyectado'] : $proy + $l['total_proyectado'];
            }
        }

        $diario = $total / self::DIAS_VENTANA;

        return [
            'total_ventana' => $total,
            'diario' => $diario,
            'mensual' => $diario * self::DIAS_MES,
            'proyectado' => $proy,
            'locales' => count($proyectados),
            'sin_egresos' => $sin
        ];
    }

    /* ====================================================================
       FORMATO
       ==================================================================== */

    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }

    private static function fechaCorta($f) {
        return substr((string) $f, 8, 2) . '/' . substr((string) $f, 5, 2);
    }
}
