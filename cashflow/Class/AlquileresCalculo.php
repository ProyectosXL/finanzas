<?php

require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/Inflacion.php';

/**
 * AlquileresCalculo
 * Cuanto vale cada concepto de alquiler de un local en un mes: el NETO, sin
 * impuestos ni fecha de pago. Cuanto se paga y cuando es AlquileresPago.
 *
 * TODO ES ESTATICO Y PURO. La inflacion, los meses cerrados de la app, los
 * porcentajes y la venta del local entran como parametros, y nada lee la base
 * ni la fecha de hoy: las pruebas fijan los numeros al centavo y no caducan.
 * Quien lee es AlquileresApp; quien arma la pestaña, Alquileres.
 *
 * LA JERARQUIA: QUE PISA A QUE
 * ----------------------------
 * Por local y por mes, de mayor a menor prioridad (ORDEN_ORIGEN):
 *
 *   1. FACTURA      la factura real imputada. ES DEL PROMPT B y no esta
 *                   implementada: el lugar queda en conceptosDelMes(), antes que
 *                   el periodo cerrado, y la constante ya existe para que la
 *                   pestaña y el tablero la conozcan desde ahora.
 *   2. APP_CERRADA  el periodo esta cerrado en la app: sus importes por
 *                   concepto, tal cual, incluidos los variables que ya calculo
 *                   la app. Un periodo ABIERTO nunca se lee (ver AlquileresApp).
 *   3. VENTA_REAL   el mes no esta cerrado pero ya tiene venta en la app: los
 *                   conceptos los calcula el cashflow, con esa venta.
 *   4. ESTIMADO     todo estimado: fijos ajustados, expensas proyectadas y la
 *                   venta estimada (o la manual de un local nuevo).
 *
 * FIJOS: EL AJUSTE POR INFLACION
 * ------------------------------
 * El mismo criterio que el valor hora de Logistica (LogisticaValorHora): el
 * ajuste SUMA SIN COMPONER la inflacion de los N meses de su ventana, y LA
 * BASE SE CORRE, cada ajuste va sobre el valor que dejo el anterior. Con
 * frecuencia N el local ajusta en los meses que estan a multiplos de N del mes
 * del ultimo ajuste; mensual ajusta todos los meses con la inflacion de cada
 * uno. Los ajustes empiezan el mes siguiente al ultimo cerrado, asi que uno
 * que cae entre el ultimo cerrado y hoy tambien se aplica: la base cerrada
 * puede estar atrasada.
 *
 * SI FALTA LA INFLACION DE UN MES SE APLICA LO QUE HAY y se avisa. No es lo
 * que hace Logistica (que deja el mes en blanco), y es a proposito: un
 * alquiler no deja de pagarse porque falte un dato de inflacion, y en blanco
 * desapareceria del cashflow. El aviso lo arma Inflacion::avisoFaltan().
 *
 * NADA SE REDONDEA ACA. La app trunca a pesos enteros (parseInt en su JS); el
 * cashflow redondea a centavos una sola vez, al armar el pago. Los meses
 * cerrados se leen tal cual, asi que esa diferencia no afecta a lo cerrado.
 */
class AlquileresCalculo {

    const FACTURA = 'FACTURA';
    const APP_CERRADA = 'APP_CERRADA';
    const VENTA_REAL = 'VENTA_REAL';
    const ESTIMADO = 'ESTIMADO';

    /** De mayor a menor prioridad. FACTURA es del prompt B. */
    const ORDEN_ORIGEN = [self::FACTURA, self::APP_CERRADA, self::VENTA_REAL, self::ESTIMADO];

    const NOMBRE_ORIGEN = [
        self::FACTURA => 'Factura',
        self::APP_CERRADA => 'App cerrada',
        self::VENTA_REAL => 'Venta real',
        self::ESTIMADO => 'Estimado'
    ];

    /** Las frecuencias de ajuste, en meses */
    const FRECUENCIAS = [1 => 'Mensual', 2 => 'Bimestral', 3 => 'Trimestral', 6 => 'Semestral'];

    /** La ventana de la variacion de las expensas, en meses cerrados */
    const MESES_EXPENSAS = 24;

    /** Con menos meses con dato, un local usa la tasa general */
    const MIN_MESES_EXPENSAS = 12;

    /* ====================================================================
       FIJOS
       ==================================================================== */

    /**
     * Si un local ajusta en un mes.
     *
     * Con frecuencia N y mes del ultimo ajuste A, ajusta en los meses cuyo
     * numero esta a un multiplo de N de A. Trimestral con septiembre: diciembre,
     * marzo, junio y septiembre. Como N divide a 12, el ciclo es el mismo todos
     * los años y alcanza con el mes del año.
     *
     * @param string $mes 'Y-m'
     * @param int $frecuencia 1, 2, 3 o 6
     * @param int|null $mesAjuste 1 a 12; no se usa en mensual
     * @return bool
     */
    public static function ajusta($mes, $frecuencia, $mesAjuste) {
        $n = (int) $frecuencia;

        if ($n === 1) {
            return true;
        }

        if ($n < 1 || $mesAjuste === null) {
            return false;
        }

        $distancia = (int) substr($mes, 5, 2) - (int) $mesAjuste;

        return (($distancia % $n) + $n) % $n === 0;
    }

    /**
     * Los fijos de un local mes a mes, desde el mes siguiente al ultimo
     * cerrado hasta $hasta.
     *
     * @param array $base ID_CA => importe del ultimo cerrado (solo se usan los fijos)
     * @param string $mesBase El ultimo cerrado, 'Y-m'
     * @param string $hasta El ultimo mes a proyectar, 'Y-m'
     * @param int $frecuencia
     * @param int|null $mesAjuste
     * @param array $inflacion 'Y-m' => puntos
     * @return array ['meses' => ['Y-m' => ['valores' => [ID_CA => float],
     *                'ajusta' => bool, 'pct' => float|null, 'ventana' => ['Y-m'],
     *                'faltan' => ['Y-m']]], 'faltan' => ['Y-m']]
     */
    public static function fijos($base, $mesBase, $hasta, $frecuencia, $mesAjuste, $inflacion) {
        $valores = [];

        foreach (AlquileresConceptos::delGrupo(AlquileresConceptos::FIJOS) as $id) {
            $valores[$id] = (float) ($base[$id] ?? 0.0);
        }

        $n = max(1, (int) $frecuencia);
        $meses = [];
        $faltanTodos = [];

        for ($mes = Inflacion::mesMas($mesBase, 1); $mes <= $hasta; $mes = Inflacion::mesMas($mes, 1)) {
            $ajusta = self::ajusta($mes, $n, $mesAjuste);
            $pct = null;
            $ventana = [];
            $faltan = [];

            if ($ajusta) {
                $suma = Inflacion::sumada($inflacion, Inflacion::mesMas($mes, -($n - 1)), $n);
                $ventana = array_keys($suma['meses']);
                $faltan = $suma['faltan'];
                /* Lo que hay, aunque falte algun mes: ver el encabezado. */
                $pct = array_sum(array_filter($suma['meses'], function ($v) {
                    return $v !== null;
                }));

                foreach ($valores as $id => $v) {
                    $valores[$id] = $v * (1 + $pct / 100);
                }

                $faltanTodos = array_merge($faltanTodos, $faltan);
            }

            $meses[$mes] = [
                'valores' => $valores,
                'ajusta' => $ajusta,
                'pct' => $pct,
                'ventana' => $ventana,
                'faltan' => $faltan
            ];
        }

        $faltanTodos = array_values(array_unique($faltanTodos));
        sort($faltanTodos);

        return ['meses' => $meses, 'faltan' => $faltanTodos];
    }

    /* ====================================================================
       FONDO DE PROMOCION (9)
       ==================================================================== */

    /**
     * El concepto 9 de un mes.
     *
     *   - Con %9 en la tabla de porcentajes: VMA del mes x %9, que es lo que la
     *     app calcula (cargaAlquileres.js, totalizar()). No es un importe fijo.
     *   - Sin %9 y con VMA en el ultimo cerrado: mantiene la proporcion sobre el
     *     VMA que tenia en ese cierre.
     *   - Sin %9 y con VMA 0: se actualiza como un fijo ($c9Fijo, que el
     *     llamador ajusto con la misma serie que los fijos).
     *
     * @param float $vma El VMA del mismo mes
     * @param float|null $pct9
     * @param float $c9Base El 9 del ultimo cerrado
     * @param float $vmaBase El VMA del ultimo cerrado
     * @param float $c9Fijo El 9 del ultimo cerrado actualizado como un fijo
     * @return array ['importe' => float, 'regla' => 'PCT'|'PROPORCION'|'FIJO']
     */
    public static function fondo($vma, $pct9, $c9Base, $vmaBase, $c9Fijo) {
        if ($pct9 !== null) {
            return ['importe' => $vma * $pct9 / 100, 'regla' => 'PCT'];
        }

        if ($vmaBase > 0) {
            return ['importe' => $vma * ($c9Base / $vmaBase), 'regla' => 'PROPORCION'];
        }

        return ['importe' => (float) $c9Fijo, 'regla' => 'FIJO'];
    }

    /* ====================================================================
       EXPENSAS (11)
       ==================================================================== */

    /**
     * La mediana de una lista de numeros. Null si esta vacia.
     *
     * @param float[] $valores
     * @return float|null
     */
    public static function mediana($valores) {
        $valores = array_values($valores);

        if (empty($valores)) {
            return null;
        }

        sort($valores);
        $n = count($valores);
        $medio = intdiv($n, 2);

        return ($n % 2 === 1) ? (float) $valores[$medio]
                              : ($valores[$medio - 1] + $valores[$medio]) / 2;
    }

    /**
     * La variacion mensual de las expensas de un local: la MEDIANA de las
     * variaciones mes a mes de los ultimos 24 meses cerrados.
     *
     * POR QUE LA MEDIANA. La media geometrica de los cocientes se reduce a
     * (ultimo / primero)^(1/n): solo pesan las puntas, y un mes con una
     * expensa extraordinaria en una punta cambia la proyeccion de todo el año.
     * La mediana ignora esos picos (Avellaneda va de 3,1 a 10,4 millones en la
     * ventana) y sigue siendo una variacion mensual que se compone.
     *
     * Solo cuentan los pares de meses CALENDARIO SEGUIDOS con expensa mayor
     * que cero: un cero o un mes sin dato no genera una variacion de -100 % ni
     * una infinita, y un hueco de dos meses no se cuenta como si fuera uno.
     *
     * @param array $serie 'Y-m' => importe, de los meses cerrados
     * @param string $hasta El ultimo cerrado, 'Y-m'
     * @return array ['tasa' => float|null, 'meses' => int (con dato),
     *                'variaciones' => int]
     */
    public static function tasaExpensas($serie, $hasta) {
        $desde = Inflacion::mesMas($hasta, -(self::MESES_EXPENSAS - 1));
        $variaciones = [];
        $conDato = 0;

        for ($mes = $desde; $mes <= $hasta; $mes = Inflacion::mesMas($mes, 1)) {
            $v = (float) ($serie[$mes] ?? 0.0);

            if ($v <= 0) {
                continue;
            }

            $conDato++;
            $anterior = (float) ($serie[Inflacion::mesMas($mes, -1)] ?? 0.0);

            if ($mes > $desde && $anterior > 0) {
                $variaciones[] = $v / $anterior - 1;
            }
        }

        return [
            'tasa' => self::mediana($variaciones),
            'meses' => $conDato,
            'variaciones' => count($variaciones)
        ];
    }

    /**
     * La tasa general: la mediana de las tasas de los locales con 12 meses o
     * mas de historia. Es la que usa un local con menos historia.
     *
     * @param array $tasas Lista de lo que devolvio tasaExpensas()
     * @return float|null
     */
    public static function tasaGeneral($tasas) {
        $validas = [];

        foreach ($tasas as $t) {
            if ($t['tasa'] !== null && $t['meses'] >= self::MIN_MESES_EXPENSAS) {
                $validas[] = $t['tasa'];
            }
        }

        return self::mediana($validas);
    }

    /**
     * La tasa que usa un local: la suya si tiene 12 meses o mas, si no la
     * general.
     *
     * @param array $propia Lo de tasaExpensas()
     * @param float|null $general
     * @return array ['tasa' => float|null, 'meses' => int, 'origen' => 'LOCAL'|'GENERAL']
     */
    public static function tasaDelLocal($propia, $general) {
        if ($propia['meses'] >= self::MIN_MESES_EXPENSAS && $propia['tasa'] !== null) {
            return ['tasa' => $propia['tasa'], 'meses' => $propia['meses'], 'origen' => 'LOCAL'];
        }

        return ['tasa' => $general, 'meses' => $propia['meses'], 'origen' => 'GENERAL'];
    }

    /**
     * La expensa a $n meses del ultimo cerrado, mes a mes:
     * expensa(M) = expensa(M-1) x (1 + tasa).
     *
     * Se itera en vez de usar pow() para que sea exactamente la cuenta que se
     * enuncia, mes a mes.
     *
     * @param float $base La del ultimo cerrado
     * @param float|null $tasa
     * @param int $n
     * @return float|null Null si no hay tasa
     */
    public static function expensa($base, $tasa, $n) {
        if ($tasa === null) {
            return null;
        }

        $valor = (float) $base;

        for ($i = 0; $i < $n; $i++) {
            $valor *= (1 + $tasa);
        }

        return $valor;
    }

    /* ====================================================================
       VARIABLES (6, 7, 14, 16, 17)
       ==================================================================== */

    /**
     * Los conceptos variables de un mes, con LAS FORMULAS DE LA APP
     * (AlquilerController::cargarAlquieres() y totalizar() del JS):
     *
     *   6  = max(0, venta bruta x %6  - VMA)
     *   7  = max(0, venta neta  x %7  - VMA)
     *   14 = concepto 7 (ya con el VMA restado) x %14
     *   16 = max(0, venta bruta x %16 - concepto 9)
     *   17 = max(0, venta neta  x %17 - concepto 9)
     *
     * El VMA y el 9 son los del MISMO MES de la venta.
     *
     * Un local sin porcentaje para un concepto no lo proyecta (no todos los
     * contratos lo tienen): el concepto no aparece en el resultado. El 14 con
     * %14 y sin %7 da 0, como en la app, que toma el 7 en 0. Sin la venta que
     * usa un concepto, el concepto tampoco aparece: quien llama avisa.
     *
     * @param float|null $bruta
     * @param float|null $neta
     * @param array $pct ID_CA => porcentaje
     * @param float $vma
     * @param float $c9
     * @return array ID_CA => ['importe' => float, 'venta' => float, 'pct' => float]
     */
    public static function variables($bruta, $neta, $pct, $vma, $c9) {
        $out = [];
        $resta = [6 => $vma, 7 => $vma, 16 => $c9, 17 => $c9];

        foreach ($resta as $id => $menos) {
            $venta = (AlquileresConceptos::VENTA_DE[$id] === 'bruta') ? $bruta : $neta;

            if (!isset($pct[$id]) || $venta === null) {
                continue;
            }

            $out[$id] = [
                'importe' => max(0.0, $venta * $pct[$id] / 100 - $menos),
                'venta' => (float) $venta,
                'pct' => (float) $pct[$id]
            ];
        }

        if (isset($pct[14]) && $neta !== null) {
            $c7 = isset($out[7]) ? $out[7]['importe'] : 0.0;
            $out[14] = ['importe' => $c7 * $pct[14] / 100, 'venta' => (float) $neta, 'pct' => (float) $pct[14]];
        }

        ksort($out);

        return $out;
    }

    /* ====================================================================
       LA PROYECCION DE UN LOCAL
       ==================================================================== */

    /**
     * Los conceptos de un local en cada mes pedido, con la jerarquia aplicada.
     *
     * @param array $e [
     *     'meses' => ['Y-m'],                  los meses de concepto a resolver
     *     'cerrados' => ['Y-m' => [ID_CA => float]],  sus meses cerrados en la app
     *     'frecuencia' => int, 'mes_ajuste' => int|null,
     *     'pct' => [ID_CA => float],
     *     'inflacion' => ['Y-m' => float],
     *     'tasa_expensas' => lo de tasaDelLocal(),
     *     'ventas' => ['Y-m' => ['bruta' => float, 'neta' => float,
     *                           'origen' => 'REAL'|'ESTIMADA'|'MANUAL'] | null]
     * ]
     * @return array ['meses' => ['Y-m' => ['origen' => string|null,
     *                'motivo' => string|null, 'conceptos' => [ID_CA => [...]]]],
     *                'ultimo_cerrado' => 'Y-m'|null, 'faltan_inflacion' => ['Y-m']]
     */
    public static function proyectarLocal($e) {
        $meses = $e['meses'];
        sort($meses);
        $cerrados = $e['cerrados'];
        ksort($cerrados);
        $ultimo = empty($cerrados) ? null : array_key_last($cerrados);
        $out = ['meses' => [], 'ultimo_cerrado' => $ultimo, 'faltan_inflacion' => []];

        $serie = null;

        if ($ultimo !== null && !empty($meses) && end($meses) > $ultimo) {
            $serie = self::fijos($cerrados[$ultimo], $ultimo, end($meses),
                                 $e['frecuencia'], $e['mes_ajuste'], $e['inflacion']);
            $out['faltan_inflacion'] = $serie['faltan'];
        }

        foreach ($meses as $mes) {
            $out['meses'][$mes] = self::conceptosDelMes($mes, $e, $cerrados, $ultimo, $serie);
        }

        return $out;
    }

    /**
     * Un mes de un local, segun la jerarquia del encabezado.
     */
    private static function conceptosDelMes($mes, $e, $cerrados, $ultimo, $serie) {
        /* 1. FACTURA (prompt B): aca va la factura real imputada a este local y
           mes, antes que el periodo cerrado. Todavia no existe. */

        /* 2. Periodo cerrado en la app: tal cual. */
        if (isset($cerrados[$mes])) {
            $conceptos = [];

            foreach (AlquileresConceptos::proyectados() as $id) {
                if (isset($cerrados[$mes][$id])) {
                    $conceptos[$id] = ['importe' => (float) $cerrados[$mes][$id]];
                }
            }

            return ['origen' => self::APP_CERRADA, 'motivo' => null, 'conceptos' => $conceptos];
        }

        if ($ultimo === null) {
            return ['origen' => null, 'motivo' => 'SIN_CERRADO', 'conceptos' => []];
        }

        /* Un mes anterior al ultimo cerrado que no esta cerrado (2022 tiene
           meses asi): no hay base hacia atras, y un abierto no se lee. */
        if ($mes <= $ultimo) {
            return ['origen' => null, 'motivo' => 'ABIERTO_ANTERIOR', 'conceptos' => []];
        }

        $base = $cerrados[$ultimo];
        $fijosMes = $serie['meses'][$mes];
        $conceptos = [];

        foreach ($fijosMes['valores'] as $id => $v) {
            $conceptos[$id] = [
                'importe' => $v,
                'ajusta' => $fijosMes['ajusta'],
                'pct_inflacion' => $fijosMes['pct'],
                'ventana' => $fijosMes['ventana']
            ];
        }

        $vma = $fijosMes['valores'][8];
        $vmaBase = (float) ($base[8] ?? 0.0);
        $c9Base = (float) ($base[9] ?? 0.0);
        /* El 9 actualizado como un fijo: la misma serie que el VMA. */
        $factor = ($vmaBase > 0) ? $vma / $vmaBase : self::factorFijo($serie, $mes);
        $fondo = self::fondo($vma, $e['pct'][9] ?? null, $c9Base, $vmaBase, $c9Base * $factor);
        $conceptos[9] = ['importe' => $fondo['importe'], 'regla' => $fondo['regla'],
                         'pct' => $e['pct'][9] ?? null];

        $t = $e['tasa_expensas'];
        $n = Inflacion::distancia($ultimo, $mes);
        $expensa = self::expensa((float) ($base[11] ?? 0.0), $t['tasa'], $n);

        if ($expensa !== null) {
            $conceptos[11] = ['importe' => $expensa, 'tasa' => $t['tasa'], 'meses_historia' => $t['meses'],
                              'tasa_origen' => $t['origen']];
        }

        $venta = $e['ventas'][$mes] ?? null;

        if ($venta !== null) {
            foreach (self::variables($venta['bruta'], $venta['neta'], $e['pct'], $vma, $fondo['importe']) as $id => $v) {
                $conceptos[$id] = $v + ['venta_origen' => $venta['origen'], 'mes_venta' => $mes];
            }
        }

        ksort($conceptos);
        $origen = ($venta !== null && $venta['origen'] === 'REAL') ? self::VENTA_REAL : self::ESTIMADO;

        return [
            'origen' => $origen,
            'motivo' => ($venta === null) ? 'SIN_VENTA' : null,
            'conceptos' => $conceptos
        ];
    }

    /**
     * El factor acumulado de los fijos hasta un mes, para cuando no hay VMA
     * en la base y no se puede deducir de el.
     */
    private static function factorFijo($serie, $mes) {
        $factor = 1.0;

        foreach ($serie['meses'] as $m => $datos) {
            if ($m > $mes) {
                break;
            }

            if ($datos['ajusta']) {
                $factor *= 1 + $datos['pct'] / 100;
            }
        }

        return $factor;
    }
}
