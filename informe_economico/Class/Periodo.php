<?php
/**
 * Periodo
 * Helpers de periodo 'M-AAAA', el formato con el que RO_T_RESUMEN_FINAL_IE
 * guarda el mes ('6-2026', sin cero adelante).
 *
 * POR QUE ES UNA CLASE APARTE
 * ---------------------------
 * rentabilidad_rubro tiene estas mismas cuentas como metodos privados de su
 * clase de informe. Cuando se migre a este modulo va a necesitar exactamente lo
 * mismo, asi que viven aca, sin base y sin estado, y se prueban solas.
 *
 * TODO ES PURO: ninguna funcion toca la base.
 */
class Periodo {

    /**
     * Los rubros que carga el paso 2 de Control de Gastos (ventas por medio de
     * pago). Un mes que SOLO tiene estos todavia no tiene resumen: es el mismo
     * criterio que gasto.php -> existeResumen() de administracion, y es el que
     * se acordo para este informe. Sin el, un mes a medio cerrar sumaria 1.1 y
     * 1.2 sin costo ni gastos e inflaria el 1.3 y el IVA.
     */
    const RUBROS_PASO_2 = ['1.1.', '1.2.', '1.6.', '1.8.'];

    private static $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];

    /**
     * Mismas validaciones que rentabilidad_rubro (validarPeriodo del controller
     * y del JS): M-AAAA, mes 1..12, anio 2000..2100.
     */
    public static function valido($p) {
        if (!is_string($p) || !preg_match('/^\d{1,2}-\d{4}$/', trim($p))) {
            return false;
        }

        list($m, $a) = array_map('intval', explode('-', trim($p)));

        return $m >= 1 && $m <= 12 && $a >= 2000 && $a <= 2100;
    }

    /** '06-2026' -> '6-2026': la tabla guarda el mes sin cero adelante */
    public static function normalizar($p) {
        list($m, $a) = array_map('intval', explode('-', trim($p)));

        return $m . '-' . $a;
    }

    /** <0, 0 o >0, como strcmp, pero cronologico */
    public static function comparar($a, $b) {
        return self::indice($a) - self::indice($b);
    }

    /** Meses desde el anio 0: sirve para comparar y para desplazar */
    private static function indice($p) {
        list($m, $a) = array_map('intval', explode('-', trim($p)));

        return $a * 12 + ($m - 1);
    }

    private static function desdeIndice($i) {
        return (($i % 12) + 1) . '-' . intdiv($i, 12);
    }

    /**
     * El mensaje de error del rango, o null si esta bien. Los textos son los de
     * rentabilidad_rubro, para que las dos pantallas digan lo mismo.
     */
    public static function validarRango($desde, $hasta) {
        if (!self::valido($desde) || !self::valido($hasta)) {
            return 'Formato de período inválido. Use M-AAAA (ej: 1-2025).';
        }

        if (self::comparar($desde, $hasta) > 0) {
            return 'El período Desde no puede ser mayor que el período Hasta.';
        }

        return null;
    }

    /** Todos los meses del rango, los dos extremos incluidos */
    public static function generar($desde, $hasta) {
        $out = [];

        for ($i = self::indice($desde); $i <= self::indice($hasta); $i++) {
            $out[] = self::desdeIndice($i);
        }

        return $out;
    }

    /** Corre un periodo N meses (negativo para atras). Cruza de anio solo. */
    public static function desplazar($p, $meses) {
        return self::desdeIndice(self::indice($p) + $meses);
    }

    /**
     * El mismo rango un anio antes: es el comparativo interanual. Se desplaza
     * mes por mes y no el anio a mano, para que 'Nov-2025 a Feb-2026' de
     * 'Nov-2024 a Feb-2025' sin casos especiales.
     */
    public static function anioAnterior(array $periodos) {
        return array_map(function ($p) {
            return self::desplazar($p, -12);
        }, $periodos);
    }

    /** 'Junio 2026' */
    public static function nombre($p) {
        list($m, $a) = array_map('intval', explode('-', trim($p)));

        return (self::$meses[$m] ?? $m) . ' ' . $a;
    }

    /** 'Jun-26', el rotulo corto de las columnas mensuales */
    public static function corto($p) {
        list($m, $a) = array_map('intval', explode('-', trim($p)));

        return substr(self::$meses[$m] ?? (string) $m, 0, 3) . '-' . substr((string) $a, 2);
    }

    /**
     * Que meses tienen resumen, a partir de los codigos de rubro de cada mes.
     *
     * @param array $rubrosPorPeriodo ['6-2026' => ['1.1.', '2.', ...], ...]
     * @return array Los periodos con resumen
     */
    public static function conResumen(array $rubrosPorPeriodo) {
        $out = [];

        foreach ($rubrosPorPeriodo as $p => $codigos) {
            foreach ($codigos as $cod) {
                if (!in_array($cod, self::RUBROS_PASO_2, true)) {
                    $out[] = (string) $p;
                    break;
                }
            }
        }

        return $out;
    }
}
