<?php

class Comex {

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Obtiene los datos de proveedores del exterior
     * Incluye lógica de FECHA_PAGO_EDIT vs FECHA_PAGO_ORIG
     * @return array Listado de importaciones pendientes
     */
    public function getProveedoresExterior(){
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT 
                    A.ID,
                    A.PROVEEDOR, 
                    A.CONTENEDOR, 
                    A.ORDEN_COMPRA, 
                    UPPER(A.DESPACHANTE) DESPACHANTE, 
                    A.VALOR_FOB_DOLAR,  
                    ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD, 
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM, 
                    A.FECHA_ARR ETA, 
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM, 
                    A.FECHA_EST_PAGO,
                    D.FECHA_PAGO_EDIT
                FROM RO_T_IMPORTACIONES_ENCABEZADO A 
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG 
                LEFT JOIN RO_T_CASHFLOW_COMEX_CRONO_NAC D ON A.ID = D.ID_MG
                WHERE B.ID_MG IS NULL 
                AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
                ORDER BY COALESCE(D.FECHA_PAGO_EDIT, A.FECHA_EST_PAGO, A.FECHA_ARR, ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB))";

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
            if (isset($row['FECHA_PAGO_EDIT']) && $row['FECHA_PAGO_EDIT'] instanceof DateTime) {
                $row['FECHA_PAGO_EDIT'] = $row['FECHA_PAGO_EDIT']->format('Y-m-d');
            }
            
            // Determinar la fecha efectiva a utilizar para el cronograma
            $row['FECHA_PAGO_EFECTIVA'] = $row['FECHA_PAGO_EDIT'] ?? $row['FECHA_EST_PAGO'];
            
            $v[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $v;
    }
    
    /**
     * Actualiza la fecha de pago estimada editada (Proveedores Exterior)
     * @param int $idMg ID del maestro de importación
     * @param string $fechaPagoOrig Fecha original de pago
     * @param string $fechaPagoEdit Fecha editada de pago
     * @return bool True si se actualizó correctamente
     */
    public function updateFechaPago($idMg, $fechaPagoOrig, $fechaPagoEdit) {
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Verificar si ya existe un registro
        $sqlCheck = "SELECT ID FROM RO_T_CASHFLOW_COMEX_CRONO_NAC WHERE ID_MG = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$idMg]);
        
        if ($stmtCheck === false) {
            throw new Exception('Error al verificar registro existente');
        }
        
        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);
        
        if ($exists) {
            // Actualizar registro existente
            $sqlUpdate = "UPDATE RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         SET FECHA_PAGO_ORIG = ?, 
                             FECHA_PAGO_EDIT = ?, 
                             FECHA_UPDATE = GETDATE()
                         WHERE ID_MG = ?";
            $params = [$fechaPagoOrig, $fechaPagoEdit, $idMg];
            $stmt = sqlsrv_query($cid, $sqlUpdate, $params);
        } else {
            // Insertar nuevo registro (con valores por defecto para NAC)
            $sqlInsert = "INSERT INTO RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, FECHA_PAGO_ORIG, FECHA_PAGO_EDIT, FECHA_UPDATE)
                         VALUES (?, '1900-01-01', '1900-01-01', ?, ?, GETDATE())";
            $params = [$idMg, $fechaPagoOrig, $fechaPagoEdit];
            $stmt = sqlsrv_query($cid, $sqlInsert, $params);
        }
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error al guardar la fecha: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }


    /**
     * Obtiene los datos del cronograma de nacionalización
     * Aplica lógica de FECHA_NAC_EDIT vs FECHA_NAC_ORIG
     * @return array Listado de importaciones con fechas de nacionalización
     */
    public function getCronoNacionalizacion() {
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Query base
        $sql = "SELECT
                    A.ID,
                    A.FECHA_EST_EMB,
                    A.PROVEEDOR, 
                    A.CONTENEDOR, 
                    A.ORDEN_COMPRA, 
                    UPPER(A.DESPACHANTE) DESPACHANTE, 
                    C.IMPORTE_EST,  
                    A.FECHA_EMB ETD, 
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM, 
                    A.FECHA_ARR ETA, 
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM, 
                    A.FECHA_DESP_ADU FECHA_NAC,
                    D.FECHA_NAC_EDIT
                FROM RO_T_IMPORTACIONES_ENCABEZADO A 
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG 
                LEFT JOIN
                (
                    SELECT ID_MG, SUM(IMPORTE) IMPORTE_EST 
                    FROM RO_T_IMPORTACIONES_ESTIMACION_DETALLE 
                    WHERE ID_CE BETWEEN 3 AND 10
                    GROUP BY ID_MG  
                ) C ON A.ID = C.ID_MG
                LEFT JOIN RO_T_CASHFLOW_COMEX_CRONO_NAC D ON A.ID = D.ID_MG
                WHERE B.ID_MG IS NULL 
                AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
                ORDER BY COALESCE(D.FECHA_NAC_EDIT, A.FECHA_DESP_ADU, A.FECHA_ARR, A.FECHA_EMB, A.FECHA_EST_EMB)";

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
            if (isset($row['FECHA_EST_EMB']) && $row['FECHA_EST_EMB'] instanceof DateTime) {
                $row['FECHA_EST_EMB'] = $row['FECHA_EST_EMB']->format('Y-m-d');
            }
            if (isset($row['ETD']) && $row['ETD'] instanceof DateTime) {
                $row['ETD'] = $row['ETD']->format('Y-m-d');
            }
            if (isset($row['ETA']) && $row['ETA'] instanceof DateTime) {
                $row['ETA'] = $row['ETA']->format('Y-m-d');
            }
            if (isset($row['FECHA_NAC']) && $row['FECHA_NAC'] instanceof DateTime) {
                $row['FECHA_NAC'] = $row['FECHA_NAC']->format('Y-m-d');
            }
            if (isset($row['FECHA_NAC_EDIT']) && $row['FECHA_NAC_EDIT'] instanceof DateTime) {
                $row['FECHA_NAC_EDIT'] = $row['FECHA_NAC_EDIT']->format('Y-m-d');
            }
            
            // Determinar la fecha efectiva a utilizar para el cronograma
            $row['FECHA_NAC_EFECTIVA'] = $row['FECHA_NAC_EDIT'] ?? $row['FECHA_NAC'];
            
            $v[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $v;
    }

    /**
     * Actualiza la fecha de nacionalización editada
     * @param int $idMg ID del maestro de importación
     * @param string $fechaNacOrig Fecha original de nacionalización
     * @param string $fechaNacEdit Fecha editada de nacionalización
     * @return bool True si se actualizó correctamente
     */
    public function updateFechaNacPago($idMg, $fechaNacOrig, $fechaNacEdit) {
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Verificar si ya existe un registro
        $sqlCheck = "SELECT ID FROM RO_T_CASHFLOW_COMEX_CRONO_NAC WHERE ID_MG = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$idMg]);
        
        if ($stmtCheck === false) {
            throw new Exception('Error al verificar registro existente');
        }
        
        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);
        
        if ($exists) {
            // Actualizar registro existente
            $sqlUpdate = "UPDATE RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         SET FECHA_NAC_ORIG = ?, 
                             FECHA_NAC_EDIT = ?, 
                             FECHA_UPDATE = GETDATE()
                         WHERE ID_MG = ?";
            $params = [$fechaNacOrig, $fechaNacEdit, $idMg];
            $stmt = sqlsrv_query($cid, $sqlUpdate, $params);
        } else {
            // Insertar nuevo registro
            $sqlInsert = "INSERT INTO RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, FECHA_UPDATE)
                         VALUES (?, ?, ?, GETDATE())";
            $params = [$idMg, $fechaNacOrig, $fechaNacEdit];
            $stmt = sqlsrv_query($cid, $sqlInsert, $params);
        }
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error al guardar la fecha: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }

}
