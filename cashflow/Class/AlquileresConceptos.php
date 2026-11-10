<?php

/**
 * AlquileresConceptos
 * Que concepto de la app de alquileres va en que grupo de la proyeccion.
 *
 * ES LA UNICA LISTA. La pestaña, el calculo, la validacion de los pagos
 * extraordinarios y el tablero la leen de aca. Va por el numero de concepto de
 * la app (RO_T_CONCEPTOS_ALQUILERES.ID_CA) y no por su nombre: el nombre lo
 * edita administracion y la app misma se rompe cuando cambia (busca el 7 por
 * "Porc. S/ventas netas").
 *
 * LOS GRUPOS Y POR QUE CADA CONCEPTO ESTA DONDE ESTA
 * --------------------------------------------------
 *   FIJOS        1 Alquiler, 2 Complementario, 3 Baulera, 8 Valor minimo
 *                mensual (VMA). Importes de contrato: el del ultimo periodo
 *                cerrado, ajustado por inflacion segun la frecuencia del local.
 *   FONDO        9 Fondo de promocion. NO ES UN IMPORTE FIJO, aunque la ayuda de
 *                la app lo diga: la app lo calcula como VMA x %9 de la tabla de
 *                porcentajes (cargaAlquileres.js, totalizar()). Se proyecta igual.
 *   EXPENSAS     11 Expensas + impuestos expensables. Varian todos los meses y
 *                no siguen a la inflacion del contrato: se proyectan con su
 *                propia variacion historica.
 *   VARIABLES    6, 7, 14, 16, 17. Porcentajes sobre la venta del local, con las
 *                formulas de la app.
 *
 *   NO SE PROYECTAN   10 publicidad, 12 diferencia acuerdo, 13 gastos
 *                     administrativos s/VMA, 15 derecho de admision. Son
 *                     puntuales o hoy casi no tienen importe. Tampoco se toman
 *                     de un mes cerrado: la pestaña muestra siempre los mismos
 *                     conceptos, y un importe que aparece solo cuando el mes
 *                     cierra no tendria grupo donde verse.
 *   FUERA             4 Renovacion de contrato (llave), 5 Comisiones, 18 FPC
 *                     Lanzamiento. Son de otro circuito (Llaves y Renovaciones)
 *                     y no entran al cashflow por esta pestaña, ni siquiera
 *                     cuando el mes esta cerrado.
 */
class AlquileresConceptos {

    const FIJOS = 'FIJOS';
    const FONDO = 'FONDO';
    const EXPENSAS = 'EXPENSAS';
    const VARIABLES = 'VARIABLES';
    const NO_PROYECTA = 'NO_PROYECTA';
    const FUERA = 'FUERA';

    /** El grupo de los pagos extraordinarios: no es un concepto de la app */
    const EXTRA = 'EXTRA';

    /** ID_CA => grupo. LA lista. */
    const GRUPO = [
        1 => self::FIJOS,
        2 => self::FIJOS,
        3 => self::FIJOS,
        4 => self::FUERA,
        5 => self::FUERA,
        6 => self::VARIABLES,
        7 => self::VARIABLES,
        8 => self::FIJOS,
        9 => self::FONDO,
        10 => self::NO_PROYECTA,
        11 => self::EXPENSAS,
        12 => self::NO_PROYECTA,
        13 => self::NO_PROYECTA,
        14 => self::VARIABLES,
        15 => self::NO_PROYECTA,
        16 => self::VARIABLES,
        17 => self::VARIABLES,
        18 => self::FUERA
    ];

    /** Los nombres de los grupos, en el orden en que se muestran */
    const NOMBRE_GRUPO = [
        self::FIJOS => 'Fijos',
        self::FONDO => 'Fondo de promoción',
        self::EXPENSAS => 'Expensas',
        self::VARIABLES => 'Variables',
        self::EXTRA => 'Extraordinarios'
    ];

    /**
     * Los nombres de los conceptos, para la pantalla. Son los de la app al
     * 10/11/2026; si la app los cambia, aca no se rompe nada, porque nada se
     * busca por nombre.
     */
    const NOMBRE = [
        1 => 'Alquiler',
        2 => 'Complementario',
        3 => 'Baulera',
        6 => '% s/ventas brutas',
        7 => '% s/ventas netas',
        8 => 'Valor mínimo mensual (VMA)',
        9 => 'Fondo de promoción (% VMA)',
        10 => 'Gastos publicidad',
        11 => 'Expensas',
        12 => 'Diferencia acuerdo',
        13 => 'Gastos administrativos (s/VMA)',
        14 => 'Gastos administrativos (s/ventas netas)',
        15 => 'Derecho de admisión mensual',
        16 => 'Fondo de promoción (s/ventas brutas)',
        17 => 'Fondo de promoción (s/ventas netas)'
    ];

    /** Las ventas que usa cada variable, segun la app */
    const VENTA_DE = [6 => 'bruta', 7 => 'neta', 14 => 'neta', 16 => 'bruta', 17 => 'neta'];

    /**
     * Los conceptos de un grupo, en orden.
     *
     * @param string $grupo
     * @return int[]
     */
    public static function delGrupo($grupo) {
        return array_keys(array_filter(self::GRUPO, function ($g) use ($grupo) {
            return $g === $grupo;
        }));
    }

    /**
     * Los conceptos que se proyectan: los de los cuatro grupos.
     *
     * @return int[]
     */
    public static function proyectados() {
        return array_keys(array_filter(self::GRUPO, function ($g) {
            return in_array($g, [self::FIJOS, self::FONDO, self::EXPENSAS, self::VARIABLES], true);
        }));
    }

    /**
     * Los que pueden ser base de un pago extraordinario: los que se proyectan.
     * Un extraordinario sobre el 4 sumaria al cashflow un concepto que esta
     * fuera de el.
     *
     * @return int[]
     */
    public static function basesExtra() {
        return self::proyectados();
    }

    /**
     * El grupo de un concepto, o null si no es de la app.
     *
     * @param int $id
     * @return string|null
     */
    public static function grupo($id) {
        return self::GRUPO[(int) $id] ?? null;
    }

    /**
     * Si un concepto se paga diferido cuando la razon social difiere: los
     * variables, que se calculan sobre la venta de un mes que todavia no
     * cerro cuando vence el alquiler.
     *
     * @param int $id
     * @return bool
     */
    public static function seDifiere($id) {
        return self::grupo($id) === self::VARIABLES;
    }
}
