<?php
/**
 * BaseIE
 * La puerta a la base del modulo: una conexion por request y cuatro helpers.
 *
 * POR QUE EXISTE
 * --------------
 * Cada clase de rentabilidad_rubro y de Control de Gastos arma su propia
 * conexion y su propio manejo de errores (o ninguno: gasto.php hace
 * print_r del error y sigue). Aca las consultas fallidas LANZAN, siempre
 * parametrizadas, y las tablas del modulo se preguntan con OBJECT_ID antes de
 * usarlas: si falta correr un script la pantalla avisa y sigue, nunca se cae.
 */
require_once __DIR__ . '/../../class/conexion.php';

class BaseIE {

    /** @var array Conexiones abiertas, por nombre de base */
    private static $conexiones = [];

    /** @var Conexion|null */
    private static $conexion = null;

    /**
     * Si la sesion es de Uruguay.
     *
     * Por ahora el informe es SOLO para Argentina: en uy la base (TASKY_SA) no
     * tiene RO_V_DOLAR_OFICIAL_BCRA ni un maestro de sucursales propio, y el
     * control de cerradas leeria el de Argentina. La pantalla muestra "no
     * disponible para Uruguay" y no consulta nada. Ver README, pendientes.
     */
    public static function esUruguay() {
        return isset($_SESSION['entorno']) && $_SESSION['entorno'] === 'uy';
    }

    /** La instancia de la conexion compartida del repo */
    public static function conexion() {
        if (self::$conexion === null) {
            self::$conexion = new Conexion();
        }

        return self::$conexion;
    }

    /**
     * Conexion a una base: 'central' para todo el informe, 'locales' para
     * SUCURSALES_LAKERS.
     */
    public static function conectar($base = 'central') {
        if (isset(self::$conexiones[$base])) {
            return self::$conexiones[$base];
        }

        $cid = @self::conexion()->conectar($base);

        if (!$cid) {
            throw new RuntimeException('No se pudo conectar a la base de datos (' . $base . ').');
        }

        self::$conexiones[$base] = $cid;

        return $cid;
    }

    /** Si existe una tabla o vista, sin fallar si no */
    public static function existe($cid, $objeto) {
        $st = sqlsrv_query($cid, 'SELECT OBJECT_ID(?) AS ID', [$objeto]);

        if ($st === false) {
            return false;
        }

        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($st);

        return $r && $r['ID'] !== null;
    }

    /** Todas las filas de una consulta, como arrays asociativos */
    public static function filas($cid, $sql, array $params = []) {
        $st = sqlsrv_query($cid, $sql, $params);

        if ($st === false) {
            throw new RuntimeException(self::error('Error al consultar la base'));
        }

        $out = [];

        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            foreach ($r as $k => $v) {
                if ($v instanceof DateTime) {
                    $r[$k] = $v->format('Y-m-d H:i:s');
                }
            }

            $out[] = $r;
        }

        sqlsrv_free_stmt($st);

        return $out;
    }

    /** Ejecuta y devuelve las filas afectadas */
    public static function ejecutar($cid, $sql, array $params = []) {
        $st = sqlsrv_query($cid, $sql, $params);

        if ($st === false) {
            throw new RuntimeException(self::error('Error al escribir en la base'));
        }

        $n = sqlsrv_rows_affected($st);
        sqlsrv_free_stmt($st);

        return $n;
    }

    /** '?, ?, ?' para un IN con N parametros */
    public static function marcas($n) {
        return implode(', ', array_fill(0, max(1, (int) $n), '?'));
    }

    public static function error($prefijo) {
        $e = sqlsrv_errors();
        $msg = $e ? ($e[0]['message'] ?? '') : '';

        return $prefijo . ($msg !== '' ? ': ' . $msg : '');
    }
}
