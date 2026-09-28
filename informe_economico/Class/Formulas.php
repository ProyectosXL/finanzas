<?php
/**
 * Formulas
 * El catalogo CERRADO de filas calculadas de la cascada, por clave.
 *
 * LA CONFIGURACION NO DECIDE LA CUENTA
 * ------------------------------------
 * RO_T_IE_ESTRUCTURA_FILA decide orden, etiqueta y visibilidad de cada fila;
 * la cuenta vive aca, en codigo y con pruebas. Una formula editable desde una
 * pantalla es una formula que un dia alguien cambia sin querer y el informe
 * sigue dando numeros, que es el peor error posible.
 *
 * TODAS SE CALCULAN SIEMPRE
 * -------------------------
 * calcular() devuelve el mapa completo aunque la estructura no muestre una
 * fila: RESULTADO_OPERATIVO necesita TOTAL_OPERATIVO aunque alguien oculte la
 * fila del total. Ocultar es presentacion, no cuenta.
 *
 * TRES VALORES POSIBLES
 * ---------------------
 *   float        el numero
 *   null         falta el dato, o una division por cero: se muestra "—"
 *   NO_APLICA    la fila no tiene sentido en esa columna (el mark up con IVA
 *                en Franquicias): se muestra "no aplica". No es lo mismo que
 *                null: null es "no se pudo calcular", esto es "no existe".
 *
 * Nunca un 0 inventado: un dato que falta es null.
 */
class Formulas {

    const NO_APLICA = 'NA';

    /* Tipos de columna. Deciden bases y "no aplica". */
    const LOCAL = 'LOCAL';                 // un local propio
    const LOCALES = 'LOCALES';             // subtotal de locales propios
    const FRANQUICIAS = 'FRANQUICIAS';
    const MAYORISTAS = 'MAYORISTAS';
    const ECOMMERCE = 'ECOMMERCE';         // el canal / subtotal
    const ECOMMERCE_AP = 'ECOMMERCE_AP';   // una apertura: 102, 301, 302, 303
    const OTROS = 'OTROS';
    const TOTAL = 'TOTAL';                 // total general
    const SIN_SUCURSAL = 'SIN_SUCURSAL';

    /* Las secciones son CAT_RUBRO_CONTABLE, tal como estan en el maestro */
    const SEC_COMERCIALIZACION = 'Gastos de Comercialización';
    const SEC_PERSONAL = 'Gastos de Personal';
    const SEC_OCUPACION = 'Gastos de Ocupación';
    const SEC_OTROS_OPERATIVOS = 'Otros Gastos Operativos';
    const SEC_BIENES_USO = 'Bienes de Uso';
    const SEC_ESTRUCTURA = 'Gastos de Estructura';

    /**
     * El catalogo: clave => tipo (CALCULO da importe, RATIO da %/coeficiente),
     * la etiqueta por defecto (la de la semilla) y el enfasis con que se dibuja.
     * 'formato' => 'coef' en los mark up: son veces, no porcentaje.
     */
    public static function catalogo() {
        return [
            'V_1_3' => ['tipo' => 'CALCULO', 'etiqueta' => '1.3 Ventas Minoristas con IVA', 'enfasis' => 'subtotal'],
            'V_1_4' => ['tipo' => 'CALCULO', 'etiqueta' => '1.4 IVA Ventas', 'enfasis' => ''],
            'V_1_9' => ['tipo' => 'CALCULO', 'etiqueta' => '1.9 TOTAL VENTAS SIN IVA', 'enfasis' => 'total'],
            'PART_TOTAL' => ['tipo' => 'RATIO', 'etiqueta' => 'Participación % Total', 'enfasis' => ''],
            'PART_LOCALES' => ['tipo' => 'RATIO', 'etiqueta' => 'Participación % Locales', 'enfasis' => ''],

            'REL_COSTO_VENTAS' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación costo sobre ventas', 'enfasis' => ''],
            'RESULTADO_BRUTO' => ['tipo' => 'CALCULO', 'etiqueta' => '3. RESULTADO BRUTO', 'enfasis' => 'resultado'],
            'REL_COSTO_VENTA_CON_IVA' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación costo / venta con IVA', 'enfasis' => ''],
            'MARKUP_CON_IVA' => ['tipo' => 'RATIO', 'etiqueta' => 'Mark up con IVA', 'enfasis' => '', 'formato' => 'coef'],
            'REL_COSTO_VENTA_SIN_IVA' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación costo / venta sin IVA', 'enfasis' => ''],
            'MARKUP_SIN_IVA' => ['tipo' => 'RATIO', 'etiqueta' => 'Mark up sin IVA', 'enfasis' => '', 'formato' => 'coef'],

            'TOTAL_COMERCIALIZACION' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL GASTOS COMERCIALIZACIÓN', 'enfasis' => 'total'],
            'REL_PROMOCIONES' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación % promociones tarjetas sobre ventas sin IVA', 'enfasis' => ''],
            'REL_COSTO_FINANCIERO' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación costo financiero sobre venta en TJ', 'enfasis' => ''],
            'REL_ARANCEL' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación arancel sobre venta en TJ', 'enfasis' => ''],
            'REL_COMERCIALIZACION' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación gastos de comercialización sobre ventas', 'enfasis' => ''],
            'RESULTADO_COMERCIAL' => ['tipo' => 'RATIO', 'etiqueta' => 'RESULTADO COMERCIAL', 'enfasis' => 'resultado'],

            'TOTAL_PERSONAL' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL GASTOS DE PERSONAL', 'enfasis' => 'total'],
            'REL_PERSONAL' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación gastos de personal sobre ventas', 'enfasis' => ''],
            'TOTAL_OCUPACION' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL GASTOS DE OCUPACIÓN', 'enfasis' => 'total'],
            'REL_OCUPACION' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación gastos de ocupación sobre ventas', 'enfasis' => ''],
            'TOTAL_OTROS_OPERATIVOS' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL OTROS GASTOS OPERATIVOS', 'enfasis' => 'total'],
            'REL_OTROS_OPERATIVOS' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación otros gastos operativos sobre ventas', 'enfasis' => ''],
            'TOTAL_OPERATIVO' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL GASTOS OPERATIVO', 'enfasis' => 'total'],
            'RESULTADO_OPERATIVO' => ['tipo' => 'CALCULO', 'etiqueta' => 'RESULTADO OPERATIVO', 'enfasis' => 'resultado'],
            'REL_RESULTADO_OPERATIVO' => ['tipo' => 'RATIO', 'etiqueta' => '% Resultado operativo sobre ventas', 'enfasis' => ''],

            'TOTAL_BIENES_USO' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL BIENES DE USO', 'enfasis' => 'total'],
            'REL_BIENES_USO' => ['tipo' => 'RATIO', 'etiqueta' => '% Bienes de uso sobre ventas', 'enfasis' => ''],
            'CONTRIBUCION_MARGINAL' => ['tipo' => 'CALCULO', 'etiqueta' => 'CONTRIBUCIÓN MARGINAL NETA', 'enfasis' => 'contribucion'],

            'TOTAL_ESTRUCTURA' => ['tipo' => 'CALCULO', 'etiqueta' => 'TOTAL GASTOS DE ESTRUCTURA', 'enfasis' => 'total'],
            'REL_ESTRUCTURA' => ['tipo' => 'RATIO', 'etiqueta' => 'Relación gastos de estructura sobre ventas', 'enfasis' => ''],
            'RESULTADO_EXPLOTACION' => ['tipo' => 'CALCULO', 'etiqueta' => 'RESULTADO EXPLOTACIÓN', 'enfasis' => 'resultado'],
            'RENTABILIDAD_TOTAL' => ['tipo' => 'RATIO', 'etiqueta' => 'RENTABILIDAD TOTAL', 'enfasis' => 'resultado'],
            'MARGEN_BRUTO' => ['tipo' => 'RATIO', 'etiqueta' => 'MARGEN BRUTO', 'enfasis' => ''],
            'SUMA_COSTOS' => ['tipo' => 'RATIO', 'etiqueta' => 'SUMA % COSTOS (EXCLUYE CMV)', 'enfasis' => '']
        ];
    }

    /* ================================================================
       ARITMETICA CON null Y NO_APLICA
       ================================================================ */

    /**
     * Suma que respeta la ausencia: null solo si TODOS son null. Un rubro sin
     * registros en una columna no es un gasto de cero inventado, pero tampoco
     * puede anular el total de su seccion.
     */
    public static function suma(...$vs) {
        $hay = false;
        $t = 0.0;

        foreach ($vs as $v) {
            if ($v === self::NO_APLICA) {
                return self::NO_APLICA;
            }

            if ($v !== null) {
                $hay = true;
                $t += (float) $v;
            }
        }

        return $hay ? $t : null;
    }

    /** a - b con la misma regla que suma() */
    public static function resta($a, $b) {
        if ($b === null || $b === self::NO_APLICA) {
            return self::suma($a, $b);
        }

        return self::suma($a, -1 * (float) $b);
    }

    /** Division: null si falta un dato o el divisor es cero ("—" en pantalla) */
    public static function div($a, $b) {
        if ($a === self::NO_APLICA || $b === self::NO_APLICA) {
            return self::NO_APLICA;
        }

        if ($a === null || $b === null || abs((float) $b) < 1e-9) {
            return null;
        }

        return (float) $a / (float) $b;
    }

    public static function esLocal($tipo) {
        return $tipo === self::LOCAL || $tipo === self::LOCALES;
    }

    /**
     * Si la columna tiene 1.3 y 1.4: los locales y el Total general, donde son
     * la suma de los locales (como L7 y L8 del Excel). En Franquicias,
     * Mayoristas, Ecommerce, Otros ingresos y Sin sucursal "no aplica".
     */
    public static function tieneVentaConIva($tipo) {
        return self::esLocal($tipo) || $tipo === self::TOTAL;
    }

    /**
     * La "venta sin IVA de mercaderia", base del mark up sin IVA.
     *
     * El 1.8 NO entra: es recupero de promociones, no venta de mercaderia.
     * Locales y Ecommerce venden por 1.5, Mayoristas por 1.6 y Franquicias
     * por 1.7. En Otros ingresos, el Total general y Sin sucursal se suman
     * los tres.
     */
    public static function ventaMercaderia($tipo, array $r) {
        $g = function ($c) use ($r) {
            return $r[$c] ?? null;
        };

        switch ($tipo) {
            case self::LOCAL:
            case self::LOCALES:
            case self::ECOMMERCE:
            case self::ECOMMERCE_AP:
                return $g('1.5.');
            case self::MAYORISTAS:
                return $g('1.6.');
            case self::FRANQUICIAS:
                return $g('1.7.');
            default:
                return self::suma($g('1.5.'), $g('1.6.'), $g('1.7.'));
        }
    }

    /* ================================================================
       LA CASCADA
       ================================================================ */

    /**
     * Todas las filas calculadas de una columna.
     *
     * @param array $ctx [
     *   'tipo'          => uno de los tipos de columna,
     *   'rubros'        => [cod => float|null] suma de los registros de la columna,
     *   'rubrosLocales' => [cod => float|null] lo mismo, solo de los locales
     *                      propios de la columna (para 1.3 y 1.4),
     *   'secciones'     => [cat => float|null] total de cada seccion,
     *   'total19'       => 1.9 del Total general (participacion % total),
     *   'locales19'     => 1.9 del subtotal LOCALES (participacion % locales),
     * ]
     * @return array [clave => float|null|NO_APLICA]
     */
    public static function calcular(array $ctx) {
        $tipo = $ctx['tipo'];
        $r = $ctx['rubros'];
        $rl = $ctx['rubrosLocales'] ?? [];
        $sec = $ctx['secciones'] ?? [];
        $g = function ($c) use ($r) {
            return $r[$c] ?? null;
        };
        $s = function ($c) use ($sec) {
            return $sec[$c] ?? null;
        };

        $o = [];

        // ---- 1. VENTAS
        if (self::tieneVentaConIva($tipo)) {
            $o['V_1_3'] = self::suma($rl['1.1.'] ?? null, $rl['1.2.'] ?? null);
            // Se muestra en negativo: es lo que se le resta al 1.3 para llegar
            // al 1.5. En el Total general es la suma de los locales porque el
            // 1.5 de Ecommerce no tiene 1.3 del que salir.
            $o['V_1_4'] = ($o['V_1_3'] === null) ? null
                : -1 * self::resta($o['V_1_3'], $rl['1.5.'] ?? null);
        } else {
            $o['V_1_3'] = self::NO_APLICA;
            $o['V_1_4'] = self::NO_APLICA;
        }

        // 1.1 y 1.2 son informativos: el total de ventas no los suma.
        $v19 = self::suma($g('1.5.'), $g('1.6.'), $g('1.7.'), $g('1.8.'));
        $o['V_1_9'] = $v19;
        $o['PART_TOTAL'] = self::div($v19, $ctx['total19'] ?? null);
        $o['PART_LOCALES'] = self::esLocal($tipo) ? self::div($v19, $ctx['locales19'] ?? null) : self::NO_APLICA;

        // ---- 2. COSTO
        $costo = $g('2.');
        $o['REL_COSTO_VENTAS'] = self::div($costo, $v19);
        $rb = self::resta($v19, $costo);
        $o['RESULTADO_BRUTO'] = $rb;

        // Mark up con IVA: solo donde hay 1.3 propio. NO se estima con 1,21:
        // hay articulos al 10,5% y el numero seria falso con apariencia de
        // exacto. El Total general tiene 1.3, pero solo de los locales, contra
        // el costo de todos los canales: por eso tambien "no aplica".
        if (self::esLocal($tipo)) {
            $o['REL_COSTO_VENTA_CON_IVA'] = self::div($costo, $o['V_1_3']);
            $o['MARKUP_CON_IVA'] = self::div($o['V_1_3'], $costo);
        } else {
            $o['REL_COSTO_VENTA_CON_IVA'] = self::NO_APLICA;
            $o['MARKUP_CON_IVA'] = self::NO_APLICA;
        }

        $merc = self::ventaMercaderia($tipo, $r);
        $o['REL_COSTO_VENTA_SIN_IVA'] = self::div($costo, $merc);
        $o['MARKUP_SIN_IVA'] = self::div($merc, $costo);

        // ---- 4. COMERCIALIZACION
        // El 1.8 va en ventas y el 4.1.2 en gastos, tal como vienen: no se
        // netea nada.
        $tc = $s(self::SEC_COMERCIALIZACION);
        $o['TOTAL_COMERCIALIZACION'] = $tc;
        $o['REL_PROMOCIONES'] = self::div($g('4.1.2.'), $v19);
        $o['REL_COSTO_FINANCIERO'] = self::div($g('4.1.3.'), $v19);
        // La etiqueta dice "venta en TJ" pero la cuenta del Excel es sobre 1.9.
        // Se mantiene la del Excel; esta anotado en el README.
        $o['REL_ARANCEL'] = self::div($g('4.1.1.'), $v19);
        $o['REL_COMERCIALIZACION'] = self::div($tc, $v19);
        // Es un %, no un importe: (costo + comercializacion) sobre la venta.
        $o['RESULTADO_COMERCIAL'] = self::div(self::suma($costo, $tc), $v19);

        // ---- 5. OPERATIVOS
        $tp = $s(self::SEC_PERSONAL);
        $to = $s(self::SEC_OCUPACION);
        $too = $s(self::SEC_OTROS_OPERATIVOS);
        $o['TOTAL_PERSONAL'] = $tp;
        $o['REL_PERSONAL'] = self::div($tp, $v19);
        $o['TOTAL_OCUPACION'] = $to;
        $o['REL_OCUPACION'] = self::div($to, $v19);
        $o['TOTAL_OTROS_OPERATIVOS'] = $too;
        $o['REL_OTROS_OPERATIVOS'] = self::div($too, $v19);
        $top = self::suma($tp, $to, $too);
        $o['TOTAL_OPERATIVO'] = $top;
        $ro = self::resta(self::resta($rb, $tc), $top);
        $o['RESULTADO_OPERATIVO'] = $ro;
        $o['REL_RESULTADO_OPERATIVO'] = self::div($ro, $v19);

        // ---- 6. BIENES DE USO
        $bu = $s(self::SEC_BIENES_USO);
        $o['TOTAL_BIENES_USO'] = $bu;
        $o['REL_BIENES_USO'] = self::div($bu, $v19);
        $cmn = self::resta($ro, $bu);
        $o['CONTRIBUCION_MARGINAL'] = $cmn;

        // ---- 7. ESTRUCTURA
        $te = $s(self::SEC_ESTRUCTURA);
        $o['TOTAL_ESTRUCTURA'] = $te;
        $o['REL_ESTRUCTURA'] = self::div($te, $v19);
        $re = self::resta($cmn, $te);
        $o['RESULTADO_EXPLOTACION'] = $re;

        // Igual que el Excel, y es una decision, no un descuido: en el
        // subtotal LOCALES la base es 1.5 (C118 = C117/C9); en cada local, en
        // cada canal y en el Total general es 1.9 (E118 = E117/E14).
        $o['RENTABILIDAD_TOTAL'] = self::div($re, $tipo === self::LOCALES ? $g('1.5.') : $v19);
        $o['MARGEN_BRUTO'] = self::div($rb, $v19);
        $o['SUMA_COSTOS'] = self::suma(
            $o['REL_COMERCIALIZACION'], $o['REL_PERSONAL'], $o['REL_OCUPACION'],
            $o['REL_OTROS_OPERATIVOS'], $o['REL_BIENES_USO'], $o['REL_ESTRUCTURA']
        );

        return $o;
    }
}
