<?php

class Ingresos {

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Obtiene los datos de Cobranzas FR (Franquicias)
     * Basado en las propuestas de pago de la app de cobranzas
     * @param bool $summary Si es true, agrupa por cliente y fecha para un reporte más rápido
     * @return array Listado de comprobantes en propuestas
     */
    public function getCobranzasFR($summary = false){
        $cid_apps = $this->conn->conectar('apps');
        $cid_central = $this->conn->conectar('central');
        
        if (!$cid_apps || !$cid_central) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        if ($summary) {
            $sql_items = "SELECT 
                            p.cod_cliente as COD_CLI,
                            p.fecha_propuesta_pago as Cobro,
                            SUM(CASE WHEN i.t_comp_factura LIKE '%NC%' THEN -i.importe_bruto ELSE i.importe_bruto END) as importe_bruto,
                            SUM(CASE WHEN i.t_comp_factura LIKE '%NC%' THEN -i.importe_neto ELSE i.importe_neto END) as importe_neto,
                            'RESUMEN' as T_COMP,
                            'VARIOS' as N_COMP,
                            'N/A' as FECHA,
                            '0' as Dias,
                            '0%' as [Desc]
                        FROM FP_propuestas_pago p
                        INNER JOIN FP_propuestas_pago_items i ON p.id = i.id_propuesta
                        WHERE p.estado NOT IN ('RECHAZADA', 'CANCELADA', 'PAGADO', 'VENCIDA')
                        AND p.fecha_propuesta_pago >= CAST(GETDATE() AS DATE)
                        GROUP BY p.cod_cliente, p.fecha_propuesta_pago
                        ORDER BY p.fecha_propuesta_pago ASC";
        } else {
            $sql_items = "SELECT 
                            p.cod_cliente as COD_CLI,
                            p.fecha_propuesta_pago as Cobro,
                            i.t_comp_factura as T_COMP,
                            i.n_comp_factura as N_COMP,
                            i.porcentaje_descuento as [Desc],
                            i.importe_bruto,
                            i.importe_neto
                        FROM FP_propuestas_pago p
                        INNER JOIN FP_propuestas_pago_items i ON p.id = i.id_propuesta
                        WHERE p.estado NOT IN ('RECHAZADA', 'CANCELADA', 'PAGADO', 'VENCIDA')
                        AND p.fecha_propuesta_pago >= CAST(GETDATE() AS DATE)
                        ORDER BY p.fecha_propuesta_pago ASC";
        }

        $stmt = sqlsrv_query($cid_apps, $sql_items);
        $data = [];

        if ($stmt === false) {
            throw new Exception("Error en consulta apps: " . print_r(sqlsrv_errors(), true));
        }

        while ($item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatear Cobro
            if ($item['Cobro'] instanceof DateTime) {
                $item['Cobro'] = $item['Cobro']->format('Y-m-d');
            }

            // Obtener Razón Social de Central
            $cod_cli = trim($item['COD_CLI']);
            $sql_rs = "SELECT RAZON_SOCI FROM GVA14 WHERE COD_CLIENT = ?";
            $stmt_rs = sqlsrv_query($cid_central, $sql_rs, [$cod_cli]);
            if ($stmt_rs && $row_rs = sqlsrv_fetch_array($stmt_rs, SQLSRV_FETCH_ASSOC)) {
                $item['RAZON_SOC'] = $row_rs['RAZON_SOCI'];
                sqlsrv_free_stmt($stmt_rs);
            } else {
                $item['RAZON_SOC'] = 'Cliente no encontrado';
            }

            if (!$summary) {
                // Obtener detalle de comprobante solo en modo detalle (Deep Dive)
                $sql_f = "SELECT TOP 1 FECHA_EMIS FROM GVA12 WHERE T_COMP = ? AND N_COMP = ?";
                $stmt_f = sqlsrv_query($cid_central, $sql_f, [trim($item['T_COMP']), trim($item['N_COMP'])]);
                
                $isNC = (strpos(trim($item['T_COMP']), 'NC') !== false);
                $multiplicador = $isNC ? -1 : 1;

                if ($stmt_f && $row_f = sqlsrv_fetch_array($stmt_f, SQLSRV_FETCH_ASSOC)) {
                    $f_emis = $row_f['FECHA_EMIS'];
                    if ($f_emis instanceof DateTime) {
                        $item['FECHA'] = $f_emis->format('Y-m-d');
                        $cobro_dt = new DateTime($item['Cobro']);
                        $diff = $cobro_dt->diff($f_emis);
                        $item['Dias'] = $diff->days;
                    } else {
                        $item['FECHA'] = 'N/A';
                        $item['Dias'] = 0;
                    }
                    sqlsrv_free_stmt($stmt_f);
                } else {
                    $item['FECHA'] = 'N/A';
                    $item['Dias'] = 0;
                }
                
                $item['importe_bruto'] = (float)$item['importe_bruto'] * $multiplicador;
                $item['importe_neto'] = (float)$item['importe_neto'] * $multiplicador;
                $item['Desc'] = (float)$item['Desc'] . '%';
            } else {
                // En resumen los montos ya vienen sumados con signo desde SQL
                $item['importe_bruto'] = (float)$item['importe_bruto'];
                $item['importe_neto'] = (float)$item['importe_neto'];
            }

            $data[] = $item;
        }

        return $data;
    }

    /**
     * Cobranza de franquicias agregada por fecha de cobro, para el tablero de
     * Cashflow.
     *
     * POR QUE NO REUSA getCobranzasFR($summary = true)
     * Ese metodo agrupa por cliente y fecha, y despues por CADA fila consulta
     * la razon social en GVA14, en otra conexion. Son N consultas para traer un
     * dato que el tablero no muestra: el Cashflow solo necesita fecha e
     * importe. Aca eso es un unico GROUP BY sin salir de la conexion 'apps'.
     *
     * getCobranzasFR se deja intacto: la pestana Cobranzas FR depende de su
     * forma actual.
     *
     * Mismos filtros que la pestana: propuestas no rechazadas ni canceladas ni
     * pagadas ni vencidas, y con fecha de pago desde hoy. Las notas de credito
     * restan.
     *
     * @return array Filas ['FECHA' => 'Y-m-d', 'IMPORTE' => float]
     */
    public function getCobranzasFRTotales() {
        $cid = $this->conn->conectar('apps');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos de apps');
        }

        $sql = "SELECT
                    p.fecha_propuesta_pago AS FECHA,
                    SUM(CASE WHEN i.t_comp_factura LIKE '%NC%'
                             THEN -i.importe_neto ELSE i.importe_neto END) AS IMPORTE
                FROM FP_propuestas_pago p
                INNER JOIN FP_propuestas_pago_items i ON p.id = i.id_propuesta
                WHERE p.estado NOT IN ('RECHAZADA', 'CANCELADA', 'PAGADO', 'VENCIDA')
                AND p.fecha_propuesta_pago >= CAST(GETDATE() AS DATE)
                GROUP BY p.fecha_propuesta_pago
                ORDER BY p.fecha_propuesta_pago ASC";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception('Error al leer la cobranza de franquicias: ' . print_r(sqlsrv_errors(), true));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['FECHA'] instanceof DateTime) {
                $row['FECHA'] = $row['FECHA']->format('Y-m-d');
            }

            $row['IMPORTE'] = floatval($row['IMPORTE']);

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }
}
