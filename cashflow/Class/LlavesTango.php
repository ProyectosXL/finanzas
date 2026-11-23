<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/LlavesSugeridos.php';

/**
 * LlavesTango
 * Las facturas de llave de los operadores en Tango (CPA04), para sugerir en
 * Parametros el % de impuestos de cada uno con la relacion factura / contrato
 * (LlavesSugeridos::impuestos()) y mostrarlas como referencia.
 *
 * SOLO LEE: ningun metodo arma un INSERT, un UPDATE ni un DELETE, y lo fija una
 * prueba.
 *
 * LA FACTURA NO ES EL PAGO. IRSA factura la llave como "CUOTA INICIAL" una sola
 * vez y Tesoreria la paga en cuotas con echeqs. De aca no sale ninguna fecha ni
 * ninguna cuota: solo cuanto se facturo contra cuanto dice el contrato.
 *
 * El filtro por leyenda va en SQL para no traer todas las facturas de
 * alquiler de dos años; quien decide cual es de llave y de que local es la
 * regla pura.
 */
class LlavesTango {

    /** Cuanta historia: dos años alcanzan para la ultima renovacion de cada operador */
    const MESES_HISTORIA = 24;

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Las facturas con leyenda de llave de unos proveedores, desde una fecha.
     *
     * @param string[] $cods
     * @param string $desde 'Y-m-d'
     * @return array [['COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_EMIS', 'IMPORTE', 'LEYENDA']]
     */
    public function facturasDeLlave($cods, $desde) {
        $cods = array_values(array_filter(array_map(['Planilla', 'codigo'], is_array($cods) ? $cods : [])));

        if (empty($cods)) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($cods), '?'));
        $leyendas = implode(' OR ', array_fill(0, count(LlavesSugeridos::LEYENDAS_LLAVE), 'UPPER(LEYENDA) LIKE ?'));
        $params = array_merge($cods, [$desde], array_map(function ($l) { return '%' . $l . '%'; },
            LlavesSugeridos::LEYENDAS_LLAVE));
        $sql = "SELECT COD_PROVEE, T_COMP, N_COMP, CAST(FECHA_EMIS AS DATE) AS FECHA_EMIS,
                       CAST(IMPORTE_TO AS FLOAT) AS IMPORTE, LEYENDA
                FROM CPA04
                WHERE COD_PROVEE IN ($marcas) AND FECHA_EMIS >= ? AND T_COMP = 'FAC' AND ($leyendas)
                ORDER BY FECHA_EMIS";
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            $msg = 'Error al leer las facturas de llave de Tango: ';

            foreach (sqlsrv_errors() ?: [] as $e) {
                $msg .= $e['message'] . ' ';
            }

            throw new Exception(trim($msg));
        }

        $out = [];

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'COD_PROVEE' => Planilla::codigo($r['COD_PROVEE']), 'T_COMP' => trim($r['T_COMP']),
                'N_COMP' => trim($r['N_COMP']),
                'FECHA_EMIS' => ($r['FECHA_EMIS'] instanceof DateTime) ? $r['FECHA_EMIS']->format('Y-m-d') : substr((string) $r['FECHA_EMIS'], 0, 10),
                'IMPORTE' => (float) $r['IMPORTE'], 'LEYENDA' => trim((string) $r['LEYENDA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

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
