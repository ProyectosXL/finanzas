<?php

require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/CronogramaPagos.php';
require_once __DIR__ . '/PrestamosCalculo.php';

/**
 * PrestamosSimulador
 * "Cuanto haria falta pedir y para cuando": lo puro del simulador de la
 * pestaña Prestamos. Quien corre el tablero es Prestamos::necesidad(); aca
 * solo se mira el saldo que devolvio.
 *
 * QUE SALDO. El acumulado CON COBERTURA del tablero oficial (sin
 * simulaciones): la ultima fila SALDO_FINAL, la misma que miran los KPIs y que
 * pinta las columnas en rojo. Se recorre en el orden del TIEMPO
 * (Horizonte::secuencia()), no en el de la pantalla.
 *
 * LA CUENTA:
 *   necesidad = |minimo del horizonte|
 *   sugerido  = necesidad x (1 + margen) / (1 - % de costos iniciales)
 * Los costos iniciales son los activos de Parametros, con su IVA. El sugerido
 * NO considera las cuotas del propio prestamo: para eso esta la comparacion
 * con y sin.
 *
 * LA FECHA DEL DESEMBOLSO: el primer dia en negativo, o el habil anterior. Si
 * cae en una columna mensual (fuera del tramo diario), el primer habil de ese
 * mes, MARCADA COMO APROXIMADA: el eje no sabe que dia del mes se pone en rojo.
 */
class PrestamosSimulador {

    /**
     * El primer negativo y el minimo de un saldo por columna.
     *
     * @param string[] $secuencia Columnas en orden cronologico ('DIA|Y-m-d', 'MES|Y-m')
     * @param array $saldo columna => valor (null = la columna no es de ningun dia futuro)
     * @return array ['hay_faltante', 'primer' (col|null), 'minimo' (float|null), 'minimo_col']
     */
    public static function analizar($secuencia, $saldo) {
        $primer = null;
        $min = null;
        $minCol = null;

        foreach ($secuencia as $col) {
            $v = $saldo[$col] ?? null;

            if ($v === null) {
                continue;
            }

            if ($v < -0.005 && $primer === null) {
                $primer = $col;
            }

            if ($min === null || $v < $min) {
                $min = $v;
                $minCol = $col;
            }
        }

        return ['hay_faltante' => ($primer !== null), 'primer' => $primer,
                'minimo' => ($min === null) ? null : round($min, 2), 'minimo_col' => $minCol];
    }

    /** El % de costos iniciales activos de Parametros, con su IVA. */
    public static function pctIniciales($costosParam, $iva) {
        $pct = 0.0;

        foreach ($costosParam as $c) {
            if (!empty($c['activo']) && ($c['momento'] ?? '') === PrestamosCalculo::INICIAL && ($c['base'] ?? '') === PrestamosCalculo::BASE_CAPITAL) {
                $pct += (float) $c['pct'] * (!empty($c['iva']) ? (1 + $iva) : 1);
            }
        }

        return round($pct, 6);
    }

    /** necesidad x (1 + margen) / (1 - % de costos iniciales), en pesos enteros. */
    public static function montoSugerido($necesidad, $margenPct, $pctIniciales) {
        $div = 1 - $pctIniciales / 100;

        if ($div <= 0) {
            throw new InvalidArgumentException('Los costos iniciales suman 100 % o más: no hay monto que alcance.');
        }

        return round($necesidad * (1 + $margenPct / 100) / $div, 0);
    }

    /**
     * La fecha del desembolso simulado.
     *
     * @return array ['fecha', 'aproximada' (bool)]
     */
    public static function fechaDesembolso($col, $habiles, $hoy) {
        if (strpos($col, 'MES|') === 0) {
            $r = DiasHabiles::siguiente(substr($col, 4) . '-01', $habiles);

            return ['fecha' => max($r['fecha'], $hoy), 'aproximada' => true];
        }

        $r = CronogramaPagos::habilAnterior(substr($col, 4), $habiles);

        return ['fecha' => max($r['fecha'], $hoy), 'aproximada' => false];
    }

    /**
     * El saldo al cierre de cada mes: la ultima columna de la secuencia que
     * cae en ese mes.
     *
     * @return array 'Y-m' => valor
     */
    public static function porMes($secuencia, $saldo) {
        $out = [];

        foreach ($secuencia as $col) {
            if (($saldo[$col] ?? null) === null) {
                continue;
            }

            $out[substr($col, 4, 7)] = round($saldo[$col], 2);
        }

        return $out;
    }

    /**
     * La fila del saldo con cobertura de un tablero (la ultima SALDO_FINAL) en
     * un mapa columna => valor.
     */
    public static function saldoDelTablero($tablero) {
        $fila = null;

        foreach ($tablero['filas'] ?? [] as $f) {
            if (($f['tipo'] ?? '') === 'SALDO_FINAL') {
                $fila = $f;
            }
        }

        if ($fila === null) {
            return null;
        }

        $out = [];

        foreach ($fila['dias'] as $k => $v) {
            $out['DIA|' . $k] = $v;
        }

        foreach ($fila['meses'] as $k => $v) {
            $out['MES|' . $k] = $v;
        }

        return $out;
    }
}
