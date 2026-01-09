<?php

class Comex {

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Obtiene los datos de proveedores del exterior
     * @return array Listado de importaciones pendientes
     */
    public function getProveedoresExterior(){
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT 
                    A.PROVEEDOR, 
                    A.CONTENEDOR, 
                    A.ORDEN_COMPRA, 
                    UPPER(A.DESPACHANTE) DESPACHANTE, 
                    A.VALOR_FOB_DOLAR,  
                    ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD, 
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM, 
                    A.FECHA_ARR ETA, 
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM, 
                    A.FECHA_EST_PAGO 
                FROM RO_T_IMPORTACIONES_ENCABEZADO A 
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG 
                WHERE B.ID_MG IS NULL 
                AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
                ORDER BY ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB)";

        $stmt = sqlsrv_query($cid, $sql);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error en la consulta SQL: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }
        
        $v = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convertir objetos DateTime a strings
            if (isset($row['ETD']) && $row['ETD'] instanceof DateTime) {
                $row['ETD'] = $row['ETD']->format('Y-m-d');
            }
            if (isset($row['ETA']) && $row['ETA'] instanceof DateTime) {
                $row['ETA'] = $row['ETA']->format('Y-m-d');
            }
            if (isset($row['FECHA_EST_PAGO']) && $row['FECHA_EST_PAGO'] instanceof DateTime) {
                $row['FECHA_EST_PAGO'] = $row['FECHA_EST_PAGO']->format('Y-m-d');
            }
            
            $v[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $v;
    }

    /**
     * Procesa los datos para agrupar por días del mes actual y totales mensuales
     * @param array $data Datos crudos de proveedores
     * @return array Datos procesados con totales
     */
    public function procesarDatosPorPeriodo($data) {
        $resultado = [
            'items' => $data,
            'totales_dias' => [],
            'totales_meses' => []
        ];

        $mesActual = date('Y-m');
        $diasEnMes = date('t');
        
        // Inicializar totales por día del mes actual
        for ($i = 1; $i <= $diasEnMes; $i++) {
            $resultado['totales_dias'][$i] = 0;
        }

        // Inicializar totales por mes (próximos 12 meses)
        for ($i = 0; $i < 12; $i++) {
            $fecha = date('Y-m', strtotime("+$i month"));
            $resultado['totales_meses'][$fecha] = 0;
        }

        // Procesar cada item
        foreach ($data as $item) {
            if (isset($item['ETD']) && $item['ETD']) {
                $fechaETD = is_string($item['ETD']) 
                    ? $item['ETD'] 
                    : $item['ETD']->format('Y-m-d');
                
                $monto = floatval($item['VALOR_FOB_DOLAR']);
                
                // Acumular en totales de días del mes actual
                if (substr($fechaETD, 0, 7) == $mesActual) {
                    $dia = intval(substr($fechaETD, 8, 2));
                    if (isset($resultado['totales_dias'][$dia])) {
                        $resultado['totales_dias'][$dia] += $monto;
                    }
                }
                
                // Acumular en totales mensuales
                $mesETD = substr($fechaETD, 0, 7);
                if (isset($resultado['totales_meses'][$mesETD])) {
                    $resultado['totales_meses'][$mesETD] += $monto;
                }
            }
        }

        return $resultado;
    }
}
