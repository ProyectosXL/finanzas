
<?php
// /599/reporte/class/ReporteRemitos.php

class ReporteRemitos {
    
    private $conn;
    
    public function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $conexion = new Conexion();
        $this->conn = $conexion->conectar('apps');
    }
    
    public function obtenerDatosReporte() {
        $sql = "
        ;WITH VentasMes AS (
            SELECT 
                EOMONTH(A.FECHA) AS FECHA,
                SUM(A.IMPORTE) AS IMPORTE_VENTA
            FROM POWER_BI_CONTROL.dbo.BI_SALES_LAKERS A
            GROUP BY EOMONTH(A.FECHA)
        ),
        UltimaCotizacionMes AS (  
            SELECT  
                YEAR(Fecha) AS Anio,  
                MONTH(Fecha) AS Mes,  
                MAX(Fecha) AS FechaUltima  
            FROM dolar_oficial_bcra  
            GROUP BY YEAR(Fecha), MONTH(Fecha)  
        ),  
        DolarCierreMes AS (  
            SELECT  
                u.Anio,  
                u.Mes,  
                d.Comprador  
            FROM dolar_oficial_bcra d  
            INNER JOIN UltimaCotizacionMes u  
                ON d.Fecha = u.FechaUltima  
        ),
        RemitosAgrupados AS (
            SELECT 
                EOMONTH(A.FECHA_MOV) AS FECHA,
                SUM(CAST(A.IMPORTE_TO AS FLOAT)) AS IMPORTE_REMITOS,
                SUM(CAST(A.IMPORTE_TO AS FLOAT) / D.Comprador) AS IMPORTE_USD
            FROM [XL-TANGO].LAKER_SA.DBO.SJ_VIEW_STA14 A    
            LEFT JOIN DolarCierreMes D  
                ON D.Anio = YEAR(A.FECHA_MOV)  
               AND D.Mes  = MONTH(A.FECHA_MOV)  
            WHERE     
                A.T_COMP = 'REM'    
                AND A.FECHA_MOV BETWEEN '2022-01-01' AND GETDATE()-1    
                AND A.N_COMP LIKE 'X%'    
                AND A.ESTADO_MOV != 'A'
            GROUP BY EOMONTH(A.FECHA_MOV)
        )
        SELECT 
            YEAR(V.FECHA) AS anio,
            MONTH(V.FECHA) AS mes,
            DATENAME(MONTH, V.FECHA) AS mes_nombre,
            CAST(ISNULL(V.IMPORTE_VENTA, 0) AS FLOAT) AS imp_venta,
            CAST(ISNULL(R.IMPORTE_REMITOS, 0) AS FLOAT) AS imp_remitos,
            CAST(ISNULL(V.IMPORTE_VENTA, 0) AS FLOAT) AS venta_pesos,
            CAST(ISNULL(R.IMPORTE_USD, 0) AS FLOAT) AS venta_usd
        FROM VentasMes V
        LEFT JOIN RemitosAgrupados R
            ON V.FECHA = R.FECHA
        WHERE V.FECHA >= DATEADD(MONTH, -64, GETDATE())
        ORDER BY V.FECHA ASC
        ";
        
        $stmt = sqlsrv_query($this->conn, $sql);
        
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        
        $datos = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $datos[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $datos;
    }
    
    public function obtenerDetalleRemitos($mesDesde = null, $anioDesde = null, $mesHasta = null, $anioHasta = null) {
        $whereClause = "A.FECHA_MOV BETWEEN '2022-01-01' AND GETDATE()-1";
        
        if ($mesDesde && $anioDesde && $mesHasta && $anioHasta) {
            $fechaDesde = sprintf("%04d-%02d-01", $anioDesde, $mesDesde);
            $fechaHasta = sprintf("%04d-%02d-01", $anioHasta, $mesHasta);
            $whereClause = "A.FECHA_MOV BETWEEN '$fechaDesde' AND EOMONTH('$fechaHasta')";
        }
        
        $sql = "
        WITH UltimaCotizacionMes AS (  
            SELECT  
                YEAR(Fecha) AS Anio,  
                MONTH(Fecha) AS Mes,  
                MAX(Fecha) AS FechaUltima  
            FROM dolar_oficial_bcra  
            GROUP BY YEAR(Fecha), MONTH(Fecha)  
        ),  
        DolarCierreMes AS (  
            SELECT  
                u.Anio,  
                u.Mes,  
                d.Comprador  
            FROM dolar_oficial_bcra d  
            INNER JOIN UltimaCotizacionMes u  
                ON d.Fecha = u.FechaUltima  
        )  
        SELECT     
            CAST(A.FECHA_MOV AS DATE) AS FECHA,    
            DATEFROMPARTS(    
                CASE     
                    WHEN MONTH(A.FECHA_MOV) >= 8 THEN YEAR(A.FECHA_MOV) + 1    
                    ELSE YEAR(A.FECHA_MOV)    
                END,    
                7,    
                31    
            ) AS EJERCICIO,    
            A.COD_PRO_CL,    
            CASE     
                WHEN B.NOM_COM = '' THEN B.RAZON_SOCI     
                ELSE B.NOM_COM     
            END AS CLIENTE,    
            A.N_COMP AS N_REMITO,    
            CAST(A.IMPORTE_TO AS FLOAT) AS IMPORTE_PESOS,    
            CAST(A.IMPORTE_TO AS FLOAT) / D.Comprador AS IMPORTE_USD    
        FROM [XL-TANGO].LAKER_SA.DBO.SJ_VIEW_STA14 A    
        INNER JOIN [XL-TANGO].LAKER_SA.DBO.SJ_VIEW_GVA14 B     
            ON A.COD_PRO_CL = B.COD_CLIENT    
        LEFT JOIN DolarCierreMes D  
            ON D.Anio = YEAR(A.FECHA_MOV)  
           AND D.Mes  = MONTH(A.FECHA_MOV)  
        WHERE     
            A.T_COMP = 'REM'    
            AND $whereClause
            AND A.N_COMP LIKE 'X%'    
            AND A.ESTADO_MOV != 'A'
        ORDER BY A.FECHA_MOV DESC
        ";
        
        $stmt = sqlsrv_query($this->conn, $sql);
        
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        
        $datos = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $datos[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $datos;
    }
}