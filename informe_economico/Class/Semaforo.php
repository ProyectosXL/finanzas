<?php
/**
 * Semaforo
 * El color de un indicador del ranking segun los umbrales de RO_T_IE_UMBRAL.
 *
 * LOS BORDES VAN A LA BANDA PEOR
 * ------------------------------
 * Un valor que cae justo en un limite se pinta con la banda PEOR de las dos
 * que lo comparten. Es la unica regla que respeta, a la vez, "verde > 15%" en
 * Rentabilidad y "verde < 15%" en Comercializacion: el verde nunca incluye su
 * limite.
 *
 *   MAYOR_MEJOR (Rentabilidad): cada banda es (desde, hasta]
 *       rojo (-inf, 5%]  naranja (5%, 12%]  amarillo (12%, 15%]  verde (15%, +inf)
 *   MENOR_MEJOR (los costos):   cada banda es [desde, hasta)
 *       verde (-inf, 15%)  amarillo [15%, 20%)  rojo [20%, +inf)
 *
 * DIFERENCIA CON EL EXCEL: el Excel pinta con formato condicional, con
 * "between" inclusivo evaluado en orden, y en los limites de ROJO se va a la
 * banda de al lado: 5% de rentabilidad es naranja y 20% de comercializacion es
 * amarillo; aca los dos son rojo. En el resto de los limites coinciden. Esta
 * en el README.
 *
 * Un valor que no cae en ninguna banda definida queda SIN color (Costo de
 * mercaderia solo tiene verde < 30%: 30% o mas no se pinta).
 */
class Semaforo {

    const MAYOR_MEJOR = 'MAYOR_MEJOR';
    const MENOR_MEJOR = 'MENOR_MEJOR';

    /** De peor a mejor: el primero que contiene al valor gana */
    const ORDEN = ['ROJO', 'NARANJA', 'AMARILLO', 'VERDE'];

    /**
     * @param float|null|string $valor
     * @param array $umbral ['sentido' => , 'bandas' => ['VERDE' => ['desde' => , 'hasta' => ], ...]]
     *        Una banda sin 'desde' ni 'hasta' (los dos null) no esta definida.
     * @return string|null 'VERDE', 'AMARILLO', 'NARANJA', 'ROJO' o null
     */
    public static function color($valor, array $umbral) {
        if ($valor === null || !is_numeric($valor)) {
            return null;
        }

        $v = (float) $valor;
        $mayor = ($umbral['sentido'] ?? '') === self::MAYOR_MEJOR;

        foreach (self::ORDEN as $color) {
            $b = $umbral['bandas'][$color] ?? null;

            if (!$b || ($b['desde'] === null && $b['hasta'] === null)) {
                continue;
            }

            $d = $b['desde'];
            $h = $b['hasta'];

            if ($mayor) {
                $dentro = ($d === null || $v > (float) $d) && ($h === null || $v <= (float) $h);
            } else {
                $dentro = ($d === null || $v >= (float) $d) && ($h === null || $v < (float) $h);
            }

            if ($dentro) {
                return $color;
            }
        }

        return null;
    }
}
