<?php

/**
 * LocalesMedidas
 * Los metros cuadrados de cada local, de FP_T_SUCURSALES_MEDIDAS (base
 * sistemas de XL-APPS). Los carga el Gestor de Locales de abastecimiento
 * (ProyectosXL/comercial, abastecimiento/gestor_locales/) y los lee tambien el
 * tablero Costo de Ocupacion de administracion (superficieService.php), por el
 * linked server [XL-APPS] desde 'central'. Aca se lee igual.
 *
 * SOLO LEE. La tabla es de otra aplicacion: ningun metodo arma un INSERT, un
 * UPDATE ni un DELETE, y lo fija una prueba, como en AlquileresApp.
 *
 * POR QUE NO ESTA EN AlquileresApp: no es una tabla de la app de alquileres,
 * es del gestor de locales. Juntarlas haria creer que las dos tienen el mismo
 * dueño y el mismo ciclo de carga.
 *
 * LOS M2 TOTALES SON VENTA + DEPOSITO + BAULERA, la misma suma que Costo de
 * Ocupacion. A diferencia de superficieService, que hace ISNULL(..., 0), un
 * local sin ninguna de las tres cargada da null y no 0: un local sin medidas no
 * es un local chico, y para Llaves esa diferencia decide si se le proyecta una
 * obra con el costo de un segmento que no se sabe.
 */
class LocalesMedidas {

    const TABLA = '[XL-APPS].sistemas.dbo.FP_T_SUCURSALES_MEDIDAS';

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Las medidas de una fila: cada parte tal cual (null si esta vacia) y el
     * total. Pura.
     *
     * @param mixed $venta
     * @param mixed $deposito
     * @param mixed $baulera
     * @return array ['venta', 'deposito', 'baulera', 'total'] con total null si las tres estan vacias
     */
    public static function medidas($venta, $deposito, $baulera) {
        $num = function ($v) {
            return ($v === null || trim((string) $v) === '') ? null : round((float) $v, 2);
        };
        $partes = ['venta' => $num($venta), 'deposito' => $num($deposito), 'baulera' => $num($baulera)];
        $cargadas = array_filter($partes, function ($v) { return $v !== null; });

        return $partes + ['total' => empty($cargadas) ? null : round(array_sum($cargadas), 2)];
    }

    /**
     * Las medidas de todos los locales que tienen fila.
     *
     * @return array NRO_SUCURS => medidas()
     */
    public function todas() {
        $stmt = sqlsrv_query($this->conectar(), "SELECT NRO_SUCURS, M2_VENTA, M2_DEPOSITO, M2_BAULERA FROM " . self::TABLA);

        if ($stmt === false) {
            $msg = 'Error al leer los m² de los locales (' . self::TABLA . '): ';

            foreach (sqlsrv_errors() ?: [] as $e) {
                $msg .= $e['message'] . ' ';
            }

            throw new Exception(trim($msg));
        }

        $out = [];

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($r['NRO_SUCURS'] !== null) {
                $out[(int) $r['NRO_SUCURS']] = self::medidas($r['M2_VENTA'], $r['M2_DEPOSITO'], $r['M2_BAULERA']);
            }
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /** @return resource Conexion a 'central', que llega a XL-APPS por el linked server */
    private function conectar() {
        if ($this->cid === null) {
            $cid = $this->conn->conectar('central');

            if (!$cid) {
                throw new Exception('No se pudo conectar a la base de datos central');
            }

            $this->cid = $cid;
        }

        return $this->cid;
    }
}
