<?php
/**
 * Cotizacion
 * El TCC promedio de un rango, de RO_V_DOLAR_OFICIAL_BCRA (una fila por mes,
 * el cierre). Mismo criterio que rentabilidad_rubro.
 *
 * LOS IMPORTES SE TOMAN TAL COMO ESTAN EN LA TABLA: ya pasaron por el paso 8
 * de Control de Gastos (coeficiente de ajuste). Queda por confirmar si ese
 * importe es nominal o ajustado; esta en el README.
 *
 * Diferencia con rentabilidad_rubro: alla el promedio se hace con los meses
 * que encuentre. Aca, si falta la cotizacion de ALGUN mes, no se muestra USD
 * y se avisa cuales faltan: un promedio de 11 meses presentado como de 12 es
 * un numero inventado.
 */
require_once __DIR__ . '/BaseIE.php';

class Cotizacion {

    /**
     * @return array ['tcc' => float|null, 'faltantes' => [periodo, ...]]
     */
    public static function promedio($cid, array $periodos) {
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

        return self::promediar($periodos, $porMes);
    }

    /** Puro: el promedio si estan todos los meses; si no, null y la lista */
    public static function promediar(array $periodos, array $porMes) {
        $faltantes = [];
        $suma = 0.0;

        foreach ($periodos as $p) {
            if (!isset($porMes[$p]) || $porMes[$p] <= 0) {
                $faltantes[] = $p;
            } else {
                $suma += $porMes[$p];
            }
        }

        if ($faltantes) {
            return ['tcc' => null, 'faltantes' => $faltantes];
        }

        return ['tcc' => $suma / count($periodos), 'faltantes' => []];
    }
}
