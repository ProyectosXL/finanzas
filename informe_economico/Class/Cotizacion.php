<?php
/**
 * Cotizacion
 * El TCC de cierre de cada mes de un rango, de RO_V_DOLAR_OFICIAL_BCRA (una
 * fila por mes, el ultimo dia cargado).
 *
 * CADA MES SE VALUA A SU PROPIO CIERRE
 * ------------------------------------
 * No hay un TCC unico del rango: el importe de enero se divide por el cierre
 * de enero, el de febrero por el de febrero, y la columna suma dolares ya
 * convertidos. Un promedio del rango mezcla cotizaciones de meses que no
 * tienen nada que ver con el importe.
 *
 * LOS IMPORTES SE TOMAN TAL COMO ESTAN EN LA TABLA: ya pasaron por el paso 8
 * de Control de Gastos (coeficiente de ajuste). Queda por confirmar si ese
 * importe es nominal o ajustado; esta en el README.
 *
 * Si falta la cotizacion de ALGUN mes, no se muestra USD y se avisa cuales
 * faltan: un mes sin convertir sumado a meses en dolares es un numero
 * inventado.
 */
require_once __DIR__ . '/BaseIE.php';

class Cotizacion {

    /**
     * @return array ['tcc' => [periodo => float]|null, 'faltantes' => [periodo, ...]]
     */
    public static function porMes($cid, array $periodos) {
        if (!$periodos) {
            return ['tcc' => null, 'faltantes' => []];
        }

        if (!BaseIE::existe($cid, 'RO_V_DOLAR_OFICIAL_BCRA')) {
            return ['tcc' => null, 'faltantes' => $periodos];
        }

        $cond = [];
        $params = [];

        foreach ($periodos as $p) {
            list($m, $a) = array_map('intval', explode('-', $p));
            $cond[] = '(Año = ? AND Mes = ?)';
            $params[] = $a;
            $params[] = $m;
        }

        $filas = BaseIE::filas($cid, 'SELECT Año AS ANIO, Mes AS MES, TCC FROM RO_V_DOLAR_OFICIAL_BCRA WHERE '
            . implode(' OR ', $cond), $params);

        $porMes = [];

        foreach ($filas as $f) {
            if ($f['TCC'] !== null) {
                $porMes[(int) $f['MES'] . '-' . (int) $f['ANIO']] = (float) $f['TCC'];
            }
        }

        return self::completar($periodos, $porMes);
    }

    /** Puro: el cierre de cada mes si estan todos; si no, null y la lista */
    public static function completar(array $periodos, array $porMes) {
        $faltantes = [];
        $tcc = [];

        foreach ($periodos as $p) {
            if (!isset($porMes[$p]) || $porMes[$p] <= 0) {
                $faltantes[] = $p;
            } else {
                $tcc[$p] = $porMes[$p];
            }
        }

        if ($faltantes) {
            return ['tcc' => null, 'faltantes' => $faltantes];
        }

        return ['tcc' => $tcc, 'faltantes' => []];
    }
}
