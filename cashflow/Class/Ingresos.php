<?php

class Ingresos {

    private $conn;

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Obtiene el PPP (Plazo Promedio de Pago) para todos los clientes o uno específico.
     * Calcula el promedio de días de plazo de los últimos 3 cobros (propuestas en estado PAGADO).
     * Si el cliente tiene un PPP_MANUAL definido en RO_T_PARAMETROS_DESC_CLIENTES, se usa ese como efectivo.
     * 
     * @param string|null $codCliente Opcional, filtrar por cliente
     * @return array Mapa de clientes con ppp_calculado, ppp_manual, ppp_efectivo y cant_cobros
     */
    public function getPPPClientes($codCliente = null) {
        $cid_apps = $this->conn->conectar('apps');
        $cid_central = $this->conn->conectar('central');

        if (!$cid_apps || !$cid_central) {
            throw new Exception('No se pudo conectar a la base de datos para calcular PPP');
        }

        // 1. Obtener PPP calculado de los últimos 3 cobros pagados en apps
        $sql_calc = "
            WITH UltimosCobros AS (
                SELECT 
                    cod_cliente,
                    DATEDIFF(day, fecha_creacion, fecha_propuesta_pago) AS plazo,
                    ROW_NUMBER() OVER (PARTITION BY cod_cliente ORDER BY id DESC) AS rn
                FROM FP_propuestas_pago
                WHERE estado = 'PAGADO'
                " . ($codCliente ? " AND cod_cliente = ?" : "") . "
            )
            SELECT 
                cod_cliente,
                ROUND(AVG(CAST(plazo AS FLOAT)), 0) AS ppp_calculado,
                COUNT(*) AS cant_cobros
            FROM UltimosCobros
            WHERE rn <= 3
            GROUP BY cod_cliente
        ";

        $params_calc = $codCliente ? [trim($codCliente)] : [];
        $stmt_calc = sqlsrv_query($cid_apps, $sql_calc, $params_calc);

        $ppps = [];
        if ($stmt_calc !== false) {
            while ($row = sqlsrv_fetch_array($stmt_calc, SQLSRV_FETCH_ASSOC)) {
                $cod = strtoupper(trim($row['cod_cliente']));
                $ppps[$cod] = [
                    'cod_cliente' => $cod,
                    'ppp_calculado' => intval($row['ppp_calculado']),
                    'cant_cobros' => intval($row['cant_cobros']),
                    'ppp_manual' => null,
                    'ppp_efectivo' => intval($row['ppp_calculado'])
                ];
            }
            sqlsrv_free_stmt($stmt_calc);
        }

        // 2. Leer configuración y PPP_MANUAL de RO_T_PARAMETROS_DESC_CLIENTES en central
        $sql_man = "SELECT COD_CLIENT, PPP_MANUAL, DIAS_PP_MAX, DESC_PP_MAX, MEDIO_PAGO_DEFAULT FROM RO_T_PARAMETROS_DESC_CLIENTES";
        if ($codCliente) {
            $sql_man .= " WHERE COD_CLIENT = ?";
        }
        $params_man = $codCliente ? [trim($codCliente)] : [];
        $stmt_man = sqlsrv_query($cid_central, $sql_man, $params_man);

        if ($stmt_man !== false) {
            while ($row = sqlsrv_fetch_array($stmt_man, SQLSRV_FETCH_ASSOC)) {
                $cod = strtoupper(trim($row['COD_CLIENT']));
                $pppMan = ($row['PPP_MANUAL'] !== null && $row['PPP_MANUAL'] !== '') ? intval($row['PPP_MANUAL']) : null;

                if (!isset($ppps[$cod])) {
                    $ppps[$cod] = [
                        'cod_cliente' => $cod,
                        'ppp_calculado' => 0,
                        'cant_cobros' => 0,
                        'ppp_manual' => $pppMan,
                        'ppp_efectivo' => ($pppMan !== null && $pppMan > 0) ? $pppMan : intval($row['DIAS_PP_MAX'] ?: 30)
                    ];
                } else {
                    $ppps[$cod]['ppp_manual'] = $pppMan;
                    if ($pppMan !== null && $pppMan > 0) {
                        $ppps[$cod]['ppp_efectivo'] = $pppMan;
                    } elseif ($ppps[$cod]['ppp_calculado'] <= 0) {
                        $ppps[$cod]['ppp_efectivo'] = intval($row['DIAS_PP_MAX'] ?: 30);
                    }
                }
            }
            sqlsrv_free_stmt($stmt_man);
        }

        return $ppps;
    }

    /**
     * Obtiene las escalas de descuento por cliente y medio desde RO_T_CASHFLOW_COBRANZAS_PARAM_DESC.
     * @return array Mapa [COD_CLIENT][MEDIO_PAGO] => array de tramos
     */
    public function getEscalasDescuento() {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            return [];
        }

        $sql = "SELECT ID, COD_CLIENT, MEDIO_PAGO, DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC, ACTIVO 
                FROM RO_T_CASHFLOW_COBRANZAS_PARAM_DESC 
                WHERE ACTIVO = 1 
                ORDER BY COD_CLIENT, MEDIO_PAGO, DIAS_DESDE ASC";

        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt === false) {
            return [];
        }

        $escalas = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = strtoupper(trim($row['COD_CLIENT']));
            $medio = strtoupper(trim($row['MEDIO_PAGO']));
            if (!isset($escalas[$cod])) {
                $escalas[$cod] = [];
            }
            if (!isset($escalas[$cod][$medio])) {
                $escalas[$cod][$medio] = [];
            }

            $escalas[$cod][$medio][] = [
                'id' => intval($row['ID']),
                'dias_desde' => intval($row['DIAS_DESDE']),
                'dias_hasta' => intval($row['DIAS_HASTA']),
                'porcentaje_desc' => floatval($row['PORCENTAJE_DESC'])
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $escalas;
    }

    /**
     * Obtiene los parámetros generales de clientes desde RO_T_PARAMETROS_DESC_CLIENTES.
     * @return array Mapa [COD_CLIENT] => datos
     */
    public function getParametrosClientes() {
        $cid = $this->conn->conectar('central');
        if (!$cid) return [];

        $sql = "SELECT COD_CLIENT, MEDIO_PAGO_DEFAULT, DIAS_PP_MAX, DESC_PP_MAX, PPP_MANUAL FROM RO_T_PARAMETROS_DESC_CLIENTES";
        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt === false) return [];

        $params = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = strtoupper(trim($row['COD_CLIENT']));
            $params[$cod] = [
                'cod_cliente' => $cod,
                'medio_pago' => strtoupper(trim($row['MEDIO_PAGO_DEFAULT'] ?? 'ECHEQ')),
                'dias_pp_max' => intval($row['DIAS_PP_MAX'] ?? 0),
                'desc_pp_max' => floatval($row['DESC_PP_MAX'] ?? 0),
                'ppp_manual' => ($row['PPP_MANUAL'] !== null && $row['PPP_MANUAL'] !== '') ? intval($row['PPP_MANUAL']) : null
            ];
        }
        sqlsrv_free_stmt($stmt);
        return $params;
    }

    /**
     * Calcula el porcentaje de descuento aplicable según el cliente, días y medio de pago.
     * 
     * @param string $codClient Código de cliente
     * @param int $dias Días calculados (PPP o diferencia de fechas)
     * @param string $medioPago Medio de pago ('ECHEQ', 'TRANSFERENCIA', etc.)
     * @param array|null $escalas Mapa pre-cargado de escalas
     * @param array|null $paramsClientes Mapa pre-cargado de parámetros
     * @return float Porcentaje de descuento (ej: 8.0 para 8%)
     */
    public function calcularDescuentoPorDias($codClient, $dias, $medioPago = 'ECHEQ', $escalas = null, $paramsClientes = null) {
        $cod = strtoupper(trim($codClient));
        $medio = strtoupper(trim($medioPago ?: 'ECHEQ'));
        $dias = intval($dias);

        if ($escalas === null) {
            $escalas = $this->getEscalasDescuento();
        }

        // 1. Si el cliente tiene tramos configurados para ese medio (o para ECHEQ)
        if (isset($escalas[$cod])) {
            $tramos = $escalas[$cod][$medio] ?? ($escalas[$cod]['ECHEQ'] ?? null);
            if (!empty($tramos)) {
                foreach ($tramos as $tramo) {
                    if ($dias >= $tramo['dias_desde'] && $dias <= $tramo['dias_hasta']) {
                        return floatval($tramo['porcentaje_desc']);
                    }
                }
            }
        }

        // 2. Fallback a RO_T_PARAMETROS_DESC_CLIENTES si no hay tramo específico
        if ($paramsClientes === null) {
            $paramsClientes = $this->getParametrosClientes();
        }

        if (isset($paramsClientes[$cod])) {
            $param = $paramsClientes[$cod];
            if ($param['dias_pp_max'] > 0 && $dias <= $param['dias_pp_max']) {
                return round($param['desc_pp_max'] * 100, 2);
            }
        }

        return 0.0;
    }

    /**
     * Obtiene los comprobantes pendientes proyectados (FAC en estado PEN fuera de propuestas activas).
     * Calcula para cada uno la fecha probable de cobro = FECHA_EMIS + PPP, y el descuento según la escala de días.
     * 
     * @param bool $summary Si es true, agrupa por cliente y fecha probable de cobro
     * @return array Listado de comprobantes proyectados
     */
    public function getCobranzasFRPendientesProyectadas($summary = false) {
        $cid_apps = $this->conn->conectar('apps');
        $cid_central = $this->conn->conectar('central');

        if (!$cid_apps || !$cid_central) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // 1. Obtener comprobantes ya en propuestas activas para excluirlos
        $sql_prop = "SELECT items.t_comp_factura, items.n_comp_factura 
                     FROM FP_propuestas_pago_items items 
                     JOIN FP_propuestas_pago propuestas ON items.id_propuesta = propuestas.id 
                     WHERE propuestas.estado NOT IN ('RECHAZADA', 'CANCELADA', 'PAGADO', 'VENCIDA')";
        $stmt_prop = sqlsrv_query($cid_apps, $sql_prop);
        $en_propuestas = [];
        if ($stmt_prop !== false) {
            while ($r = sqlsrv_fetch_array($stmt_prop, SQLSRV_FETCH_ASSOC)) {
                $key = strtoupper(trim($r['t_comp_factura'])) . '|' . strtoupper(trim($r['n_comp_factura']));
                $en_propuestas[$key] = true;
            }
            sqlsrv_free_stmt($stmt_prop);
        }

        // 2. Cargar PPPs y escalas de descuento
        $ppps = $this->getPPPClientes();
        $escalas = $this->getEscalasDescuento();
        $paramsClientes = $this->getParametrosClientes();

        // 3. Consultar facturas FAC pendientes en Central (Tango GVA12)
        $sql_fac = "
            SELECT 
                g.COD_CLIENT, 
                c.RAZON_SOCI, 
                g.T_COMP, 
                g.N_COMP, 
                CAST(g.FECHA_EMIS AS DATE) AS FECHA_EMIS, 
                g.ESTADO, 
                CAST(g.IMPORTE AS FLOAT) AS IMPORTE
            FROM GVA12 g
            INNER JOIN GVA14 c ON g.COD_CLIENT = c.COD_CLIENT
            WHERE g.COD_CLIENT LIKE 'FR%'
              AND g.T_COMP = 'FAC'
              AND g.ESTADO = 'PEN'
            ORDER BY g.FECHA_EMIS DESC
        ";

        $stmt_fac = sqlsrv_query($cid_central, $sql_fac);
        if ($stmt_fac === false) {
            throw new Exception("Error al consultar facturas pendientes en Tango: " . print_r(sqlsrv_errors(), true));
        }

        $itemsProyectados = [];
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0);

        while ($row = sqlsrv_fetch_array($stmt_fac, SQLSRV_FETCH_ASSOC)) {
            $tComp = strtoupper(trim($row['T_COMP']));
            $nComp = strtoupper(trim($row['N_COMP']));
            $key = $tComp . '|' . $nComp;

            if (isset($en_propuestas[$key])) {
                continue; // Ya está en una propuesta activa
            }

            $codCli = strtoupper(trim($row['COD_CLIENT']));
            $razonSoci = trim($row['RAZON_SOCI']);
            $importeBruto = floatval($row['IMPORTE']);

            // Fecha de emisión
            $fEmisObj = $row['FECHA_EMIS'] instanceof DateTime ? $row['FECHA_EMIS'] : new DateTime($row['FECHA_EMIS']);
            $fEmisStr = $fEmisObj->format('Y-m-d');

            // PPP del cliente
            $pppInfo = $ppps[$codCli] ?? null;
            $pppDias = $pppInfo ? intval($pppInfo['ppp_efectivo']) : 30;
            if ($pppDias <= 0) $pppDias = 30;

            // Fecha probable de cobro = F. Emis + PPP
            $fProbCobroObj = clone $fEmisObj;
            $fProbCobroObj->modify("+{$pppDias} days");
            $fProbCobroStr = $fProbCobroObj->format('Y-m-d');

            // No traer cobros pendientes cuya fecha probable sea anterior al día actual
            $hoyStr = $hoy->format('Y-m-d');
            if ($fProbCobroStr < $hoyStr) {
                continue;
            }

            // Días para cálculo de descuento (antigüedad / plazo)
            $diasDesc = $pppDias;

            // Medio de pago configurado para el cliente
            $medioCli = isset($paramsClientes[$codCli]) ? $paramsClientes[$codCli]['medio_pago'] : 'ECHEQ';

            // Descuento según escala por cliente
            $porcDesc = $this->calcularDescuentoPorDias($codCli, $diasDesc, $medioCli, $escalas, $paramsClientes);
            $importeNeto = round($importeBruto * (1 - ($porcDesc / 100)), 2);

            $itemsProyectados[] = [
                'COD_CLI' => $codCli,
                'RAZON_SOC' => $razonSoci,
                'FECHA' => $fEmisStr,
                'T_COMP' => $tComp,
                'N_COMP' => $nComp,
                'Desc' => $porcDesc . '%',
                'Dias' => $diasDesc,
                'PPP' => $pppDias,
                'importe_bruto' => $importeBruto,
                'importe_neto' => $importeNeto,
                'Cobro' => $fProbCobroStr,
                'TIPO_REGISTRO' => 'PROYECCION' // Distintivo para pintar en amarillo
            ];
        }
        sqlsrv_free_stmt($stmt_fac);

        if (!$summary) {
            return $itemsProyectados;
        }

        // Si es modo resumen, agrupar por cliente y fecha probable de cobro
        $agrupados = [];
        foreach ($itemsProyectados as $item) {
            $grupoKey = $item['COD_CLI'] . '|' . $item['Cobro'];
            if (!isset($agrupados[$grupoKey])) {
                $agrupados[$grupoKey] = [
                    'COD_CLI' => $item['COD_CLI'],
                    'RAZON_SOC' => $item['RAZON_SOC'],
                    'FECHA' => 'N/A',
                    'T_COMP' => 'PROY',
                    'N_COMP' => 'VARIOS',
                    'Desc' => $item['Desc'],
                    'Dias' => $item['Dias'],
                    'PPP' => $item['PPP'],
                    'importe_bruto' => 0.0,
                    'importe_neto' => 0.0,
                    'Cobro' => $item['Cobro'],
                    'TIPO_REGISTRO' => 'PROYECCION'
                ];
            }

            $agrupados[$grupoKey]['importe_bruto'] += $item['importe_bruto'];
            $agrupados[$grupoKey]['importe_neto'] += $item['importe_neto'];
        }

        return array_values($agrupados);
    }

    /**
     * Obtiene los datos de Cobranzas FR (Franquicias).
     * Permite filtrar por tipo de origen:
     *   - 'todos': Propuestas reales + Facturas pendientes proyectadas
     *   - 'real': Solo propuestas reales
     *   - 'proyectado': Solo facturas pendientes proyectadas con PPP
     * 
     * @param bool $summary Si es true, agrupa por cliente y fecha
     * @param string $origen 'todos', 'real', o 'proyectado'
     * @return array Listado de filas para la grilla
     */
    public function getCobranzasFR($summary = false, $origen = 'todos') {
        $data = [];

        // 1. Cargar datos reales si corresponde
        if ($origen === 'todos' || $origen === 'real') {
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
            if ($stmt === false) {
                throw new Exception("Error en consulta apps: " . print_r(sqlsrv_errors(), true));
            }

            // Cache de razones sociales para optimizar
            $razonesSociales = [];
            $sql_all_rs = "SELECT COD_CLIENT, RAZON_SOCI FROM GVA14";
            $stmt_all_rs = sqlsrv_query($cid_central, $sql_all_rs);
            if ($stmt_all_rs !== false) {
                while ($rrs = sqlsrv_fetch_array($stmt_all_rs, SQLSRV_FETCH_ASSOC)) {
                    $razonesSociales[strtoupper(trim($rrs['COD_CLIENT']))] = trim($rrs['RAZON_SOCI']);
                }
                sqlsrv_free_stmt($stmt_all_rs);
            }

            while ($item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($item['Cobro'] instanceof DateTime) {
                    $item['Cobro'] = $item['Cobro']->format('Y-m-d');
                }

                $cod_cli = strtoupper(trim($item['COD_CLI']));
                $item['RAZON_SOC'] = $razonesSociales[$cod_cli] ?? 'Cliente no encontrado';
                $item['TIPO_REGISTRO'] = 'REAL';

                if (!$summary) {
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
                    $item['importe_bruto'] = (float)$item['importe_bruto'];
                    $item['importe_neto'] = (float)$item['importe_neto'];
                }

                $data[] = $item;
            }
            sqlsrv_free_stmt($stmt);
        }

        // 2. Cargar pendientes proyectados si corresponde
        if ($origen === 'todos' || $origen === 'proyectado') {
            $proyectados = $this->getCobranzasFRPendientesProyectadas($summary);
            $data = array_merge($data, $proyectados);
        }

        return $data;
    }

    /**
     * Cobranza de franquicias agregada por fecha de cobro, para el tablero de Cashflow.
     * 
     * @param string $origen 'todos', 'real', o 'proyectado'
     * @return array Filas ['FECHA' => 'Y-m-d', 'IMPORTE' => float]
     */
    public function getCobranzasFRTotales($origen = 'todos') {
        $totalesPorFecha = [];

        // 1. Cobranza Real
        if ($origen === 'todos' || $origen === 'real') {
            $cid = $this->conn->conectar('apps');
            if ($cid) {
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
                if ($stmt !== false) {
                    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                        $f = $row['FECHA'] instanceof DateTime ? $row['FECHA']->format('Y-m-d') : $row['FECHA'];
                        $totalesPorFecha[$f] = ($totalesPorFecha[$f] ?? 0.0) + floatval($row['IMPORTE']);
                    }
                    sqlsrv_free_stmt($stmt);
                }
            }
        }

        // 2. Cobranza Proyectada (Facturas PEN con PPP)
        if ($origen === 'todos' || $origen === 'proyectado') {
            $proy = $this->getCobranzasFRPendientesProyectadas(true);
            foreach ($proy as $p) {
                $f = $p['Cobro'];
                $totalesPorFecha[$f] = ($totalesPorFecha[$f] ?? 0.0) + floatval($p['importe_neto']);
            }
        }

        $resultado = [];
        ksort($totalesPorFecha);
        foreach ($totalesPorFecha as $f => $imp) {
            $resultado[] = [
                'FECHA' => $f,
                'IMPORTE' => round($imp, 2)
            ];
        }

        return $resultado;
    }

    /**
     * Obtiene los días de vencimiento / plazo para facturas mayoristas.
     * Lee el parámetro 'cobranzas_may_dias_vto' (default 60).
     * 
     * @return int Días de plazo
     */
    public function getDiasPlazoMayoristas() {
        $cid = $this->conn->conectar('central');
        if (!$cid) return 60;

        $sql = "SELECT VALOR FROM RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = 'cobranzas_may_dias_vto'";
        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            $val = intval($row['VALOR']);
            return $val > 0 ? $val : 60;
        }
        return 60;
    }

    /**
     * Obtiene las facturas pendientes de Mayoristas (Camino 1) proyectadas a fecha de emisión + días de plazo.
     * 
     * @param bool $summary Si es true agrupa por cliente y fecha probable de cobro
     * @return array Listado de comprobantes proyectados
     */
    public function getCobranzasMay($summary = false) {
        $cid_central = $this->conn->conectar('central');
        if (!$cid_central) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $diasPlazo = $this->getDiasPlazoMayoristas();

        $sql_fac = "
            SELECT 
                g.COD_CLIENT, 
                c.RAZON_SOCI, 
                g.T_COMP, 
                g.N_COMP, 
                CAST(g.FECHA_EMIS AS DATE) AS FECHA_EMIS, 
                g.ESTADO, 
                CAST(g.IMPORTE AS FLOAT) AS IMPORTE
            FROM GVA12 g
            INNER JOIN GVA14 c ON g.COD_CLIENT = c.COD_CLIENT
            WHERE g.COD_CLIENT LIKE 'MA%'
              AND g.T_COMP IN ('FAC', 'NDC', 'NDU', 'NC', 'NCC', 'NCU')
              AND g.ESTADO = 'PEN'
            ORDER BY g.FECHA_EMIS DESC
        ";

        $stmt_fac = sqlsrv_query($cid_central, $sql_fac);
        if ($stmt_fac === false) {
            throw new Exception("Error al consultar facturas pendientes mayoristas: " . print_r(sqlsrv_errors(), true));
        }

        $items = [];
        $resumenMap = [];
        $hoyStr = (new DateTime())->format('Y-m-d');

        while ($row = sqlsrv_fetch_array($stmt_fac, SQLSRV_FETCH_ASSOC)) {
            $tComp = strtoupper(trim($row['T_COMP']));
            $nComp = strtoupper(trim($row['N_COMP']));
            $codCli = strtoupper(trim($row['COD_CLIENT']));
            $razonSoci = trim($row['RAZON_SOCI']);
            $importe = floatval($row['IMPORTE']);

            $isNC = (strpos($tComp, 'NC') !== false);
            $multiplicador = $isNC ? -1 : 1;
            $importeReal = $importe * $multiplicador;

            $fEmisObj = $row['FECHA_EMIS'] instanceof DateTime ? $row['FECHA_EMIS'] : new DateTime($row['FECHA_EMIS']);
            $fEmisStr = $fEmisObj->format('Y-m-d');

            // Fecha probable de cobro = Fecha emisión + días de plazo
            $fProbCobroObj = clone $fEmisObj;
            $fProbCobroObj->modify("+{$diasPlazo} days");
            $fProbCobroStr = $fProbCobroObj->format('Y-m-d');

            // No traer cobros pendientes cuya fecha probable de cobro sea anterior al día de corte / hoy
            if ($fProbCobroStr < $hoyStr) {
                continue;
            }

            if ($summary) {
                $key = $codCli . '|' . $fProbCobroStr;
                if (!isset($resumenMap[$key])) {
                    $resumenMap[$key] = [
                        'COD_CLI' => $codCli,
                        'RAZON_SOC' => $razonSoci,
                        'FECHA' => 'N/A',
                        'T_COMP' => 'VARIOS',
                        'N_COMP' => 'COMPROBANTES',
                        'Desc' => '0%',
                        'Dias' => $diasPlazo,
                        'importe_bruto' => 0.0,
                        'importe_neto' => 0.0,
                        'Cobro' => $fProbCobroStr,
                        'TIPO_REGISTRO' => 'PROYECCION',
                        'PLAZO' => $diasPlazo
                    ];
                }
                $resumenMap[$key]['importe_bruto'] += $importeReal;
                $resumenMap[$key]['importe_neto'] += $importeReal;
            } else {
                $items[] = [
                    'COD_CLI' => $codCli,
                    'RAZON_SOC' => $razonSoci,
                    'FECHA' => $fEmisStr,
                    'T_COMP' => $tComp,
                    'N_COMP' => $nComp,
                    'Desc' => '0%',
                    'Dias' => $diasPlazo,
                    'importe_bruto' => round($importeReal, 2),
                    'importe_neto' => round($importeReal, 2),
                    'Cobro' => $fProbCobroStr,
                    'TIPO_REGISTRO' => 'PROYECCION',
                    'PLAZO' => $diasPlazo
                ];
            }
        }
        sqlsrv_free_stmt($stmt_fac);

        if ($summary) {
            foreach ($resumenMap as &$r) {
                $r['importe_bruto'] = round($r['importe_bruto'], 2);
                $r['importe_neto'] = round($r['importe_neto'], 2);
                $items[] = $r;
            }
        }

        return $items;
    }

    /**
     * Cobranza mayorista agregada por fecha probable de cobro para el tablero de Cashflow.
     * 
     * @return array Filas ['FECHA' => 'Y-m-d', 'IMPORTE' => float]
     */
    public function getCobranzasMayTotales() {
        $items = $this->getCobranzasMay(true);
        $totalesPorFecha = [];

        foreach ($items as $item) {
            $f = $item['Cobro'];
            $totalesPorFecha[$f] = ($totalesPorFecha[$f] ?? 0.0) + floatval($item['importe_neto']);
        }

        $resultado = [];
        ksort($totalesPorFecha);
        foreach ($totalesPorFecha as $f => $imp) {
            $resultado[] = [
                'FECHA' => $f,
                'IMPORTE' => round($imp, 2)
            ];
        }

        return $resultado;
    }
}
