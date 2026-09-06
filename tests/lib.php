<?php
/**
 * Arnes minimo de pruebas.
 *
 * El proyecto no usa Composer ni ningun framework, asi que las pruebas tampoco:
 * son PHP plano que se corre con `php tests/run.php`. La idea es que la logica
 * mas delicada del modulo (el eje temporal, el arrastre del saldo y el
 * validador de la estructura) se pueda verificar de forma repetible, sin tener
 * que abrir el navegador y sin depender de lo que haya cargado en las tablas.
 */

class Pruebas {

    public static $ok = 0;
    public static $fallas = 0;
    public static $detalle = [];
    public static $archivo = '';

    /** Titulo de un bloque de pruebas */
    public static function seccion($titulo) {
        echo PHP_EOL . '  -- ' . $titulo . ' --' . PHP_EOL;
    }

    /**
     * Compara esperado contra obtenido.
     *
     * Los flotantes se comparan con tolerancia, porque un importe calculado
     * arrastra error de punto flotante. null se compara de forma estricta: la
     * diferencia entre null y 0 es justamente lo que distingue "no hay dato" de
     * "el dato es cero", y no puede quedar tapada por una comparacion floja.
     */
    public static function chequear($nombre, $esperado, $obtenido) {
        if ($esperado === null || $obtenido === null) {
            $iguales = ($esperado === $obtenido);
        } elseif (is_float($esperado) || is_float($obtenido)) {
            $iguales = abs(floatval($esperado) - floatval($obtenido)) < 0.001;
        } else {
            $iguales = ($esperado === $obtenido);
        }

        if ($iguales) {
            self::$ok++;
            echo '    OK    ' . $nombre . PHP_EOL;

            return true;
        }

        self::$fallas++;
        self::$detalle[] = self::$archivo . ': ' . $nombre;

        echo '    FALLA ' . $nombre . PHP_EOL;
        echo '          esperado: ' . self::mostrar($esperado) . PHP_EOL;
        echo '          obtenido: ' . self::mostrar($obtenido) . PHP_EOL;

        return false;
    }

    /** Verifica que una llamada lance, y con que mensaje */
    public static function chequearLanza($nombre, $fn, $mensajeEsperado = null) {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($mensajeEsperado === null) {
                return self::chequear($nombre, true, true);
            }

            return self::chequear($nombre, $mensajeEsperado, $e->getMessage());
        }

        return self::chequear($nombre, 'que lance una excepcion', 'no lanzo');
    }

    private static function mostrar($v) {
        if (is_array($v)) {
            return preg_replace('/\s+/', ' ', var_export($v, true));
        }

        return var_export($v, true);
    }

    /** Si hay conexion a la base, para poder saltear las pruebas que la necesitan */
    public static function hayBase() {
        static $hay = null;

        if ($hay !== null) {
            return $hay;
        }

        try {
            require_once __DIR__ . '/../class/conexion.php';
            $conn = new Conexion;
            $hay = (bool) @$conn->conectar('central');
        } catch (Throwable $e) {
            $hay = false;
        }

        return $hay;
    }

    public static function saltear($motivo) {
        echo '    (salteado: ' . $motivo . ')' . PHP_EOL;
    }
}

function seccion($titulo) {
    Pruebas::seccion($titulo);
}

function chequear($nombre, $esperado, $obtenido) {
    return Pruebas::chequear($nombre, $esperado, $obtenido);
}

function chequearLanza($nombre, $fn, $mensajeEsperado = null) {
    return Pruebas::chequearLanza($nombre, $fn, $mensajeEsperado);
}
