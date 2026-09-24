<?php

require_once __DIR__ . '/ComprasProyectadasDatos.php';

/**
 * ComprasProyectadasJob
 * Corre, desde la pantalla, los dos SP que materializan los insumos pesados de
 * Compras Exterior. Es lo que hace el boton "Actualizar ahora" de la pestana
 * Comercio Exterior > Proyeccion.
 *
 * LOS SP SON LOS MISMOS QUE CORRE EL JOB DEL SQL AGENT, y registran la corrida
 * igual en RO_T_CASHFLOW_JOB_LOG. La unica diferencia es quien la pidio: aca
 * va el usuario de la pantalla, y alla el login del Agent. Un calculo hecho a
 * mano y uno programado se leen en el log exactamente igual.
 *
 * POR QUE VIVE APARTE DE ComprasProyectadasDatos: esa clase no escribe nada, y
 * hay una prueba que lo verifica sobre el archivo entero. Correr un SP que
 * reemplaza dos tablas es escribir. Mismo criterio que
 * ComprasProyectadasAjustes.
 *
 * LOS DOS POR SEPARADO, y no un solo metodo que corra ambos: la pantalla los
 * pide de a uno para poder marcar cada paso en el spinner, y si el del
 * presupuesto falla -el linked server es el eslabon mas fragil- la historia
 * ya quedo actualizada.
 */
class ComprasProyectadasJob {

    /** Los dos procesos que se pueden correr desde la pantalla */
    const PROCESOS = [
        'historia' => [
            'sp' => ComprasProyectadasDatos::SP_HISTORIA,
            'nombre' => 'la historia de recepciones'
        ],
        'presupuesto' => [
            'sp' => ComprasProyectadasDatos::SP_PRESUPUESTO,
            'nombre' => 'el presupuesto oficial'
        ]
    ];

    /**
     * Cuanto puede tardar un SP antes de que el pedido se corte. Hoy la
     * historia tarda ~1 s y el presupuesto ~0,5 s; esto es para el dia en que
     * el linked server tarde en responder, no para el caso normal.
     */
    const TIMEOUT_SEGUNDOS = 300;

    /** @var Conexion */
    private $conn;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Corre uno de los dos SP y devuelve como quedo.
     *
     * NO SE TRAGA EL ERROR: si el SP falla, lanza con su mensaje. El SP ya lo
     * dejo en el log, y la pantalla lo muestra en el paso que fallo.
     *
     * @param string $proceso 'historia' o 'presupuesto'
     * @param string|null $usuario Quien lo pidio
     * @return array ['proceso','segundos','insumo']
     */
    public function correr($proceso, $usuario) {
        if (!isset(self::PROCESOS[$proceso])) {
            throw new Exception('No hay ningún proceso "' . $proceso . '" para actualizar.');
        }

        $p = self::PROCESOS[$proceso];

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base central');
        }

        /* Se pregunta antes de nombrarlo: sin el script, EXEC falla con un
           "Could not find stored procedure" que no dice que hacer. */
        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . $p['sp'] . "', 'P') AS P");
        $row = ($stmt === false) ? null : sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if (!$row || $row['P'] === null) {
            throw new Exception('No existe ' . $p['sp'] . '. Hay que correr sql/'
                . $p['sp'] . '.sql contra la base central (y antes '
                . 'sql/cashflow_comex_materializado.sql).');
        }

        $usuario = ($usuario === null || trim((string) $usuario) === '')
            ? 'Pantalla (sin usuario)' : mb_substr(trim((string) $usuario), 0, 128);

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIMEOUT_SEGUNDOS + 30);
        }

        $t0 = microtime(true);

        $stmt = sqlsrv_query($cid, "EXEC dbo." . $p['sp'] . " @Usuario = ?", [$usuario],
            ['QueryTimeout' => self::TIMEOUT_SEGUNDOS]);

        if ($stmt === false) {
            throw new Exception('No se pudo actualizar ' . $p['nombre'] . ': '
                . self::mensajeSql());
        }

        /* Se consumen todos los resultados: con sqlsrv, un error que el SP
           lanza despues de una sentencia que ya devolvio algo recien aparece
           al avanzar. */
        while (($r = sqlsrv_next_result($stmt)) === true) {
        }

        if ($r === false) {
            throw new Exception('No se pudo actualizar ' . $p['nombre'] . ': '
                . self::mensajeSql());
        }

        sqlsrv_free_stmt($stmt);

        $segundos = round(microtime(true) - $t0, 1);

        /* Como quedo, leido del log: una instancia nueva, sin cache. */
        $datos = new ComprasProyectadasDatos;
        $insumo = ComprasProyectadasDatos::insumosParaPantalla($datos->estadoInsumos());

        /* EL SP PUEDE TERMINAR SIN ERROR Y SIN HABER HECHO NADA: si ya habia
           otra corrida en curso, deja el aviso en el log y sale. Eso se dice. */
        if ($insumo[$proceso]['fallo'] !== null) {
            throw new Exception('No se actualizó ' . $p['nombre'] . ': '
                . $insumo[$proceso]['fallo']);
        }

        return [
            'proceso' => $proceso,
            'segundos' => $segundos,
            'insumo' => $insumo[$proceso]
        ];
    }

    /** @return string */
    private static function mensajeSql() {
        $msg = [];

        foreach ((array) sqlsrv_errors() as $e) {
            if (isset($e['message'])) {
                /* Sin el prefijo del driver: la pantalla lo muestra entero. */
                $msg[] = preg_replace('/^(\[[^\]]+\])+/', '', $e['message']);
            }
        }

        return empty($msg) ? 'error desconocido' : implode(' ', array_unique($msg));
    }
}
