<?php
/**
 * Cascada
 * Del registro crudo a la grilla: indice, columnas, secciones, filas, % y
 * comparativo. Todo puro: recibe arrays y devuelve arrays.
 *
 * EL FLUJO
 * --------
 *   registros ──indexar()──> [periodo][sucursal][rubro] = suma
 *                                  │
 *   columna (sucursales+periodos) ─┴─agregar()──> [rubro] = suma
 *                                                     │
 *   Rubros::clasificar ──secciones()─────────────────┤
 *                                                     ▼
 *                                         Formulas::calcular()
 *
 * LOS REGISTROS REPETIDOS SE SUMAN
 * --------------------------------
 * Hay mas de un registro por (PERIODO, NRO_SUCURSAL, COD_RUBRO): 103 / 1.6 con
 * hasta cinco en un mes, y varios 1.1, 1.2, 1.8 y 2. El informe los suma y no
 * deduplica nada; la edicion trabaja por ID, uno por uno.
 */
require_once __DIR__ . '/Canales.php';
require_once __DIR__ . '/Formulas.php';
require_once __DIR__ . '/Rubros.php';

class Cascada {

    /**
     * @param array $registros [['PERIODO', 'NRO_SUCURSAL', 'COD_RUBRO', 'IMPORTE'], ...]
     * @return array [periodo][claveSucursal][cod] = suma
     */
    public static function indexar(array $registros) {
        $ix = [];

        foreach ($registros as $r) {
            $p = (string) $r['PERIODO'];
            $k = Canales::clave($r['NRO_SUCURSAL']);
            $c = (string) $r['COD_RUBRO'];

            if (!isset($ix[$p][$k][$c])) {
                $ix[$p][$k][$c] = 0.0;
            }

            $ix[$p][$k][$c] += (float) $r['IMPORTE'];
        }

        return $ix;
    }

    /**
     * Pasa el indice a dolares. Se divide cada importe por el TCC ANTES de la
     * cascada: asi todo importe queda en USD y todo ratio, que es un cociente
     * de dos importes divididos por el mismo numero, queda igual.
     */
    public static function convertir(array $ix, $tcc) {
        foreach ($ix as $p => $sucs) {
            foreach ($sucs as $k => $rubros) {
                foreach ($rubros as $c => $v) {
                    $ix[$p][$k][$c] = $v / $tcc;
                }
            }
        }

        return $ix;
    }

    /**
     * La suma de los registros de una columna.
     *
     * @param array $ix
     * @param array $sucursales claves de sucursal (int o Canales::CLAVE_SIN)
     * @param array $periodos
     * @return array [cod => float] solo los rubros con registros
     */
    public static function agregar(array $ix, array $sucursales, array $periodos) {
        $out = [];

        foreach ($periodos as $p) {
            if (!isset($ix[$p])) {
                continue;
            }

            foreach ($sucursales as $k) {
                if (!isset($ix[$p][$k])) {
                    continue;
                }

                foreach ($ix[$p][$k] as $c => $v) {
                    $out[$c] = ($out[$c] ?? 0.0) + $v;
                }
            }
        }

        return $out;
    }

    /** Total de cada seccion, con la suma que respeta la ausencia */
    public static function secciones(array $rubros, array $clasif) {
        $out = [];

        foreach ($clasif['porSeccion'] as $cat => $cods) {
            $vals = [];

            foreach ($cods as $c) {
                $vals[] = $rubros[$c] ?? null;
            }

            $out[$cat] = Formulas::suma(...$vals);
        }

        return $out;
    }

    /** De las sucursales de una columna, las que son locales propios */
    public static function soloLocales(array $sucursales) {
        return array_values(array_filter($sucursales, function ($k) {
            return $k !== Canales::CLAVE_SIN && Canales::esLocal($k);
        }));
    }

    /**
     * Calcula una columna completa.
     *
     * @param array $col ['tipo', 'sucursales', 'periodos']
     * @param array $ref ['total19' => , 'locales19' => ]
     * @return array ['rubros' => [cod => v], 'calc' => [clave => v]]
     */
    public static function columna(array $ix, array $col, array $clasif, array $ref) {
        $rubros = self::agregar($ix, $col['sucursales'], $col['periodos']);
        $rubrosLocales = self::agregar($ix, self::soloLocales($col['sucursales']), $col['periodos']);

        $calc = Formulas::calcular([
            'tipo' => $col['tipo'],
            'rubros' => $rubros,
            'rubrosLocales' => $rubrosLocales,
            'secciones' => self::secciones($rubros, $clasif),
            'total19' => $ref['total19'] ?? null,
            'locales19' => $ref['locales19'] ?? null
        ]);

        return ['rubros' => $rubros, 'calc' => $calc];
    }

    /**
     * El 1.9 de un conjunto de sucursales: es la referencia de las
     * participaciones.
     */
    public static function venta19(array $ix, array $sucursales, array $periodos) {
        $r = self::agregar($ix, $sucursales, $periodos);

        return Formulas::suma($r['1.5.'] ?? null, $r['1.6.'] ?? null, $r['1.7.'] ?? null, $r['1.8.'] ?? null);
    }

    /**
     * El valor de una fila de la estructura en una columna ya calculada.
     *
     * @param array $fila fila expandida: ['tipo' => RUBRO|CALCULO|RATIO, 'cod'|'clave']
     */
    public static function valor(array $fila, array $res) {
        if ($fila['tipo'] === 'RUBRO') {
            return $res['rubros'][$fila['cod']] ?? null;
        }

        return $res['calc'][$fila['clave']] ?? null;
    }

    /**
     * % sobre el 1.9 de la columna, para las filas de importe. Las RATIO no
     * llevan: ya son un %.
     */
    public static function porcentaje($v, $v19) {
        if ($v === Formulas::NO_APLICA) {
            return Formulas::NO_APLICA;
        }

        return Formulas::div($v, $v19);
    }

    /**
     * Variacion contra el anio anterior.
     *
     * Importes: (actual - anterior) / |anterior|. Se divide por el valor
     * ABSOLUTO para que el signo diga siempre si subio o bajo, aun cuando el
     * anterior era una perdida. Base 0 o null: null ("—"), nunca un infinito.
     *
     * Ratios: la diferencia en puntos porcentuales (0,15 -> 0,12 da -0,03).
     * Una "variacion %" de un porcentaje (-20%) se lee como si hubiera caido
     * la venta y confunde.
     */
    public static function variacion($actual, $anterior, $esRatio = false) {
        if ($actual === Formulas::NO_APLICA || $anterior === Formulas::NO_APLICA) {
            return Formulas::NO_APLICA;
        }

        if ($actual === null || $anterior === null) {
            return null;
        }

        if ($esRatio) {
            return (float) $actual - (float) $anterior;
        }

        if (abs((float) $anterior) < 1e-9) {
            return null;
        }

        return ((float) $actual - (float) $anterior) / abs((float) $anterior);
    }
}
