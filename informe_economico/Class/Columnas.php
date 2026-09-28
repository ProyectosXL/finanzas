<?php
/**
 * Columnas
 * Que columnas tiene cada pestana del informe. Puro.
 *
 * Una columna es un conjunto de sucursales y un conjunto de periodos, mas un
 * tipo que decide las bases de las formulas (ver Formulas). Todo lo demas
 * -subtotales, total, mes, comparativo- sale de agregar esos dos conjuntos:
 * no hay subtotales calculados "sumando columnas", que es como se desfasan.
 *
 * LA COLUMNA "SIN SUCURSAL" NO SUMA AL TOTAL GENERAL
 * --------------------------------------------------
 * Los registros con NRO_SUCURSAL NULL o 0 son registros viejos que no
 * pertenecen a ninguna sucursal. Se muestran en su propia columna (en IE por
 * Canales y por Locales) para no esconderlos, pero NO entran al Total general
 * ni a ningun canal: asi el total coincide con el del Excel, que no los tiene.
 */
require_once __DIR__ . '/Canales.php';
require_once __DIR__ . '/Formulas.php';
require_once __DIR__ . '/Periodo.php';

class Columnas {

    /** Las sucursales fijas de cada canal que no es de locales */
    public static function sucursalesCanal($canal) {
        switch ($canal) {
            case Canales::FRANQUICIAS: return [Canales::FRANQUICIA];
            case Canales::MAYORISTAS: return [Canales::MAYORISTA];
            case Canales::ECOMMERCE: return Canales::APERTURAS_ECOMMERCE;
            case Canales::OTROS: return [Canales::OTROS_INGRESOS];
        }

        return [];
    }

    /**
     * Los locales propios que entran al subtotal LOCALES.
     *
     * Con el toggle de cerradas apagado, los cerrados no se muestran ni suman
     * (y la pantalla avisa cuantos quedaron afuera y cuanto venden). Como el
     * Total general es la suma de los canales, tampoco suman ahi: el total
     * tiene que ser la suma de lo que se ve.
     *
     * @param array $presentes claves de sucursal con registros en el rango
     * @param array $cerradas [nro => true]
     */
    public static function localesIncluidos(array $presentes, array $cerradas, $incluirCerradas) {
        $out = [];

        foreach ($presentes as $k) {
            if ($k === Canales::CLAVE_SIN || !Canales::esLocal($k)) {
                continue;
            }

            if (!$incluirCerradas && isset($cerradas[$k])) {
                continue;
            }

            $out[] = (int) $k;
        }

        sort($out);

        return $out;
    }

    /** Todas las sucursales del Total general: locales incluidos + los canales */
    public static function sucursalesTotal(array $localesIncl) {
        return array_merge(
            $localesIncl,
            [Canales::FRANQUICIA, Canales::MAYORISTA],
            Canales::APERTURAS_ECOMMERCE,
            [Canales::OTROS_INGRESOS]
        );
    }

    private static function col($clave, $etiqueta, $tipo, array $sucs, array $periodos, $editable, $extra = []) {
        return array_merge([
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'sub' => '',
            'tipo' => $tipo,
            'sucursales' => array_values($sucs),
            'periodos' => array_values($periodos),
            'editable' => $editable,
            'cerrada' => false,
            'fueraTotal' => false,
            'grupo' => ''
        ], $extra);
    }

    /**
     * IE por Canales: LOCALES, Franquicias, Mayoristas, Ecommerce (102 + 301 +
     * 302 + 303), Otros ingresos, Total general y, si hay, Sin sucursal.
     *
     * Aca LOCALES es un canal y no un subtotal de columnas visibles: una celda
     * de LOCALES abre el detalle de todos sus registros (cada local x cada mes).
     * El Total general no se edita.
     */
    public static function canales(array $presentes, array $cerradas, $incluirCerradas, array $periodos) {
        $loc = self::localesIncluidos($presentes, $cerradas, $incluirCerradas);
        $cols = [
            self::col('LOCALES', 'LOCALES', Formulas::LOCALES, $loc, $periodos, true, ['grupo' => 'CANAL']),
            self::col('FRANQUICIAS', 'Franquicias', Formulas::FRANQUICIAS, self::sucursalesCanal(Canales::FRANQUICIAS), $periodos, true, ['grupo' => 'CANAL']),
            self::col('MAYORISTAS', 'Mayoristas', Formulas::MAYORISTAS, self::sucursalesCanal(Canales::MAYORISTAS), $periodos, true, ['grupo' => 'CANAL']),
            self::col('ECOMMERCE', 'Ecommerce', Formulas::ECOMMERCE, self::sucursalesCanal(Canales::ECOMMERCE), $periodos, true, ['grupo' => 'CANAL']),
            self::col('OTROS', 'Otros ingresos', Formulas::OTROS, self::sucursalesCanal(Canales::OTROS), $periodos, true, ['grupo' => 'CANAL']),
            self::col('TOTAL', 'Total general', Formulas::TOTAL, self::sucursalesTotal($loc), $periodos, false, ['grupo' => 'TOTAL'])
        ];

        if (in_array(Canales::CLAVE_SIN, $presentes, true)) {
            $cols[] = self::colSin($periodos);
        }

        return $cols;
    }

    private static function colSin(array $periodos) {
        return self::col('SIN', 'Sin sucursal', Formulas::SIN_SUCURSAL, [Canales::CLAVE_SIN], $periodos, true,
            ['grupo' => 'SIN', 'fueraTotal' => true, 'sub' => 'No suma al total']);
    }

    /**
     * IE por Locales, en este orden: cada local propio con datos (por numero),
     * subtotal LOCALES, Franquicias, Mayoristas, las aperturas de Ecommerce con
     * datos + subtotal ECOMMERCE, Otros ingresos, Total general y Sin sucursal.
     *
     * Los subtotales y el total no se editan: sus registros se editan desde la
     * columna de cada local o apertura, que es donde se ve cual es cual.
     *
     * @param array $nombres [clave => ['nombre']]
     */
    public static function locales(array $presentes, array $nombres, array $cerradas, $incluirCerradas, array $periodos) {
        $loc = self::localesIncluidos($presentes, $cerradas, $incluirCerradas);
        $cols = [];

        foreach ($loc as $n) {
            $cols[] = self::col('S' . $n, $nombres[$n]['nombre'] ?? ('Sucursal ' . $n), Formulas::LOCAL, [$n], $periodos, true,
                ['sub' => (string) $n, 'grupo' => 'LOCAL', 'cerrada' => isset($cerradas[$n]), 'nro' => $n]);
        }

        $cols[] = self::col('LOCALES', 'LOCALES', Formulas::LOCALES, $loc, $periodos, false, ['grupo' => 'SUBTOTAL']);
        $cols[] = self::col('FRANQUICIAS', 'Franquicias', Formulas::FRANQUICIAS, [Canales::FRANQUICIA], $periodos, true, ['grupo' => 'CANAL', 'nro' => Canales::FRANQUICIA]);
        $cols[] = self::col('MAYORISTAS', 'Mayoristas', Formulas::MAYORISTAS, [Canales::MAYORISTA], $periodos, true, ['grupo' => 'CANAL', 'nro' => Canales::MAYORISTA]);

        foreach (Canales::APERTURAS_ECOMMERCE as $n) {
            if (in_array($n, $presentes, true)) {
                $cols[] = self::col('S' . $n, $nombres[$n]['nombre'] ?? Canales::NOMBRES_DEFECTO[$n], Formulas::ECOMMERCE_AP, [$n], $periodos, true,
                    ['sub' => (string) $n, 'grupo' => 'ECOM_AP', 'nro' => $n]);
            }
        }

        $cols[] = self::col('ECOMMERCE', 'ECOMMERCE', Formulas::ECOMMERCE, Canales::APERTURAS_ECOMMERCE, $periodos, false, ['grupo' => 'SUBTOTAL']);
        $cols[] = self::col('OTROS', 'Otros ingresos', Formulas::OTROS, [Canales::OTROS_INGRESOS], $periodos, true, ['grupo' => 'CANAL', 'nro' => Canales::OTROS_INGRESOS]);
        $cols[] = self::col('TOTAL', 'Total general', Formulas::TOTAL, self::sucursalesTotal($loc), $periodos, false, ['grupo' => 'TOTAL']);

        if (in_array(Canales::CLAVE_SIN, $presentes, true)) {
            $cols[] = self::colSin($periodos);
        }

        return $cols;
    }

    /** Canales del filtro de Evolucion Mensual */
    const CANALES_MENSUAL = ['TODOS', Canales::LOCALES, Canales::FRANQUICIAS, Canales::MAYORISTAS, Canales::ECOMMERCE, Canales::OTROS];

    /**
     * Evolucion Mensual: una columna por mes del rango y el total, sin separar
     * por canal. El filtro Canal decide las sucursales y el tipo, asi las bases
     * son las mismas que en IE por Canales (Locales rinde sobre 1.5, etc).
     */
    public static function mensual(array $presentes, array $cerradas, $incluirCerradas, array $periodos, $canal) {
        $loc = self::localesIncluidos($presentes, $cerradas, $incluirCerradas);

        switch ($canal) {
            case Canales::LOCALES: $sucs = $loc; $tipo = Formulas::LOCALES; break;
            case Canales::FRANQUICIAS: $sucs = self::sucursalesCanal($canal); $tipo = Formulas::FRANQUICIAS; break;
            case Canales::MAYORISTAS: $sucs = self::sucursalesCanal($canal); $tipo = Formulas::MAYORISTAS; break;
            case Canales::ECOMMERCE: $sucs = self::sucursalesCanal($canal); $tipo = Formulas::ECOMMERCE; break;
            case Canales::OTROS: $sucs = self::sucursalesCanal($canal); $tipo = Formulas::OTROS; break;
            default: $sucs = self::sucursalesTotal($loc); $tipo = Formulas::TOTAL;
        }

        $cols = [];

        foreach ($periodos as $p) {
            $cols[] = self::col('M' . $p, Periodo::corto($p), $tipo, $sucs, [$p], true, ['grupo' => 'MES', 'periodo' => $p]);
        }

        $cols[] = self::col('TOTAL', 'Total', $tipo, $sucs, $periodos, false, ['grupo' => 'TOTAL']);

        return $cols;
    }
}
