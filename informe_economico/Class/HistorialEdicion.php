<?php
/**
 * HistorialEdicion
 * Las reglas de la edicion de importes, sin base: estado de un registro segun
 * su historial, a donde lo lleva Deshacer y a donde Restaurar, y la validacion
 * del importe que escribe el usuario.
 *
 * EL HISTORIAL ES SOLO INSERCION
 * ------------------------------
 * RO_T_IE_RESUMEN_EDICION nunca se actualiza ni se borra. Deshacer y Restaurar
 * no borran movimientos: son un movimiento mas, con su motivo. Asi el
 * historial cuenta todo lo que paso, incluso lo que se volvio atras.
 *
 * EL REPROCESO PISA LO EDITADO, Y ES LO ESPERADO
 * ----------------------------------------------
 * RO_SP_REPROCESAR_RESUMEN_FINAL_IE hace DELETE del periodo y lo vuelve a
 * insertar: los IDs cambian. Por eso "pisado" es: el ID ya no existe, o su
 * importe no coincide con el nuevo del ultimo movimiento. Un registro pisado
 * pierde la marca solo, y Deshacer / Restaurar se deshabilitan: volver atras
 * sobre un valor que ya no es el que se edito escribiria encima del proceso.
 */
class HistorialEdicion {

    const EDICION = 'EDICION';
    const DESHACER = 'DESHACER';
    const RESTAURAR = 'RESTAURAR';

    /** Tolerancia de comparacion: IMPORTE es float y se edita con 2 decimales */
    const TOLERANCIA = 0.005;

    public static function iguales($a, $b) {
        if ($a === null || $b === null) {
            return false;
        }

        return abs((float) $a - (float) $b) < self::TOLERANCIA;
    }

    /**
     * Estado de un registro.
     *
     * @param array $movs movimientos del ID en orden (fecha, id):
     *        [['TIPO', 'IMPORTE_ANTERIOR', 'IMPORTE_NUEVO', 'USUARIO', 'FECHA', 'MOTIVO'], ...]
     * @param float|null $actual IMPORTE de hoy; null si el ID ya no existe
     * @return array [
     *   'tieneHistorial', 'editado', 'pisado',
     *   'original'   => el valor que dejo el proceso (anterior del primer movimiento),
     *   'ultimo'     => el ultimo movimiento,
     *   'deshacerA'  => a que valor vuelve Deshacer, o null si no hay nada que deshacer,
     *   'restaurarA' => a que valor vuelve Restaurar, o null si ya esta en el original,
     * ]
     */
    public static function estado(array $movs, $actual) {
        if (!$movs) {
            return [
                'tieneHistorial' => false, 'editado' => false, 'pisado' => false,
                'original' => null, 'ultimo' => null, 'deshacerA' => null, 'restaurarA' => null
            ];
        }

        $ultimo = $movs[count($movs) - 1];
        $original = (float) $movs[0]['IMPORTE_ANTERIOR'];
        $pisado = ($actual === null) || !self::iguales($actual, $ultimo['IMPORTE_NUEVO']);

        $pila = self::pila($movs);
        $tope = $pila ? $pila[count($pila) - 1] : null;

        return [
            'tieneHistorial' => true,
            // Marca de celda editada: tiene historial Y el valor de hoy es el
            // que dejo el ultimo movimiento. Si un reproceso lo piso, la marca
            // desaparece sola.
            'editado' => !$pisado,
            'pisado' => $pisado,
            'original' => $original,
            'ultimo' => $ultimo,
            'deshacerA' => (!$pisado && $tope) ? (float) $tope['IMPORTE_ANTERIOR'] : null,
            'restaurarA' => (!$pisado && !self::iguales($actual, $original)) ? $original : null
        ];
    }

    /**
     * Los movimientos que Deshacer puede volver atras, como una pila.
     *
     * EDICION y RESTAURAR apilan; DESHACER desapila. Por eso deshacer dos veces
     * retrocede dos pasos, y no alterna entre los dos ultimos valores (que es
     * lo que pasaria si Deshacer volviera al "anterior" del ultimo movimiento,
     * cuando el ultimo es el propio Deshacer). Y por eso un Restaurar tambien se
     * puede deshacer.
     */
    public static function pila(array $movs) {
        $pila = [];

        foreach ($movs as $m) {
            if ($m['TIPO'] === self::DESHACER) {
                array_pop($pila);
            } else {
                $pila[] = $m;
            }
        }

        return $pila;
    }

    /**
     * Valida el importe que escribe el usuario: numerico, hasta 2 decimales.
     *
     * Acepta '1234.56' y el formato de pantalla '1.234,56': si trae coma, la
     * coma es el decimal y los puntos son de miles. Nada mas: un importe con
     * tres decimales no se redondea en silencio, se rechaza.
     *
     * @return array ['ok' => bool, 'valor' => float|null, 'error' => string|null]
     */
    public static function validarImporte($texto) {
        $t = str_replace([' ', "\u{00A0}", '$'], '', trim((string) $texto));

        if (strpos($t, ',') !== false) {
            $t = str_replace('.', '', $t);
            $t = str_replace(',', '.', $t);
        }

        if ($t === '' || !preg_match('/^-?\d+(\.\d{1,2})?$/', $t)) {
            return ['ok' => false, 'valor' => null,
                    'error' => 'El importe tiene que ser un número con hasta 2 decimales.'];
        }

        return ['ok' => true, 'valor' => (float) $t, 'error' => null];
    }

    public static function validarMotivo($motivo) {
        $m = trim((string) $motivo);

        if ($m === '') {
            return 'El motivo es obligatorio.';
        }

        if (mb_strlen($m) > 500) {
            return 'El motivo no puede tener más de 500 caracteres.';
        }

        return null;
    }
}
