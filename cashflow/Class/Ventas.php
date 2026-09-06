<?php

require_once __DIR__ . '/Parametros.php';

/**
 * Ventas
 * Proyeccion de ventas y su conversion en cobranzas.
 *
 * MODELO DE NEGOCIO
 * -----------------
 * Toda la venta de este modulo es ESTIMADA. El historico de Tango
 * (RO_T_CASHFLOW_VENTAS_HIST, que puebla el SP SJ_CASHFLOW_VENTAS_HIST) se usa
 * unicamente como base de calculo:
 *
 *   VentaNetaProyectada(M) = VentaNetaReal(M, anio anterior) * (1 + indice_M)
 *   VentaConIVA(M)         = VentaNetaProyectada(M) * (1 + alicuota_iva)
 *   VentaCanal(c, M)       = VentaConIVA(M) * %Participacion(c, M)
 *   VentaDiaria(c, d)      = VentaCanal(c, M(d)) / (dias_del_mes - feriados_comercio)
 *   Monto(c, mp, d)        = VentaDiaria(c, d) * %Mix(c, mp)
 *   FechaAcreditacion      = d + DiasAcreditacion(c, mp), corrida al proximo
 *                            dia bancario habil si cae en no habil
 *
 * LOS DOS CALENDARIOS
 * -------------------
 * No se mezclan nunca:
 *   Comercial -> estimacion de venta. Todos los dias del anio (sabados y
 *                domingos incluidos, las sucursales abren) excepto los feriados
 *                de comercio del parametro 'feriados_comercio'.
 *   Bancario  -> acreditacion de cobranza. RO_T_CALENDARIO.DIA_LABORAL = 1 en
 *                la conexion 'power'. Excluye sabados, domingos y feriados
 *                nacionales.
 *
 * Como el divisor de la venta diaria descuenta los feriados de comercio, el
 * total mensual se conserva. Como los sabados y domingos de cobranza se corren
 * al lunes, la cobranza se concentra los lunes: es un efecto del corrimiento,
 * no una regla aparte.
 *
 * RELACION CON LAS COBRANZAS REALES
 * ---------------------------------
 * Las pestanas Cobranzas FR y Cobranzas May traen cobranza real de facturas ya
 * emitidas. Este modulo proyecta cobranza de ventas futuras y no se cruza con
 * ellas. En el cashflow consolidado:
 *   Cobranza total = cobranza real (facturas emitidas) + cobranza estimada.
 *
 * NINGUN VALOR DE NEGOCIO ESTA HARDCODEADO: alicuota, horizonte, feriados,
 * participaciones de respaldo, mix y plazos salen de las tablas de parametros.
 */
class Ventas {

    /** Abreviaturas de mes para los rotulos de columna */
    private static $mesesAbrev = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',  5 => 'May',  6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
    ];

    /** Avisos no fatales acumulados durante el calculo (se devuelven en el JSON) */
    private $warnings = [];

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->parametros = new Parametros;
    }

    /* ====================================================================
       LECTURAS DE BASE
       ==================================================================== */

    /**
     * Historico de ventas por mes y canal. Solo TIPO_COMPROBANTE = 'FACTURA':
     * es lo unico que entra en la proyeccion.
     * @param int|null $anioDesde Anio minimo a devolver, o null para todo
     * @return array Listado de filas del historico
     */
    public function getHistoricoVentas($anioDesde = null) {
        return $this->leerHistorico('FACTURA', $anioDesde);
    }

    /**
     * Historico de remitos por mes y canal. Solo TIPO_COMPROBANTE = 'REMITO'.
     * NO entra en la proyeccion: existe unicamente como bloque de control para
     * contrastar el total contra el tablero.
     * @param int|null $anioDesde Anio minimo a devolver, o null para todo
     * @return array Listado de filas de remitos
     */
    public function getRemitosControl($anioDesde = null) {
        return $this->leerHistorico('REMITO', $anioDesde);
    }

    /**
     * Lee RO_T_CASHFLOW_VENTAS_HIST filtrando por tipo de comprobante
     * @param string $tipo 'FACTURA' o 'REMITO'
     * @param int|null $anioDesde Anio minimo a devolver, o null para todo
     * @return array Listado de filas
     */
    private function leerHistorico($tipo, $anioDesde = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT ANIO, MES, CANAL, TIPO_COMPROBANTE, IMPORTE_NETO, CANTIDAD
                FROM RO_T_CASHFLOW_VENTAS_HIST
                WHERE TIPO_COMPROBANTE = ?";
        $params = [$tipo];

        if ($anioDesde !== null) {
            $sql .= " AND ANIO >= ?";
            $params[] = intval($anioDesde);
        }

        $sql .= " ORDER BY ANIO, MES, CANAL";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historico de ventas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ANIO'] = intval($row['ANIO']);
            $row['MES'] = intval($row['MES']);
            $row['IMPORTE_NETO'] = floatval($row['IMPORTE_NETO']);
            $row['CANTIDAD'] = floatval($row['CANTIDAD']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Indices de variacion por mes
     * @return array Listado de indices
     */
    public function getIndices() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT ANIO, MES, INDICE, FECHA_UPDATE, USUARIO
                FROM RO_T_CASHFLOW_VENTAS_INDICE
                ORDER BY ANIO, MES";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los indices de variacion'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['FECHA_UPDATE']) && $row['FECHA_UPDATE'] instanceof DateTime) {
                $row['FECHA_UPDATE'] = $row['FECHA_UPDATE']->format('Y-m-d');
            }

            $row['ANIO'] = intval($row['ANIO']);
            $row['MES'] = intval($row['MES']);
            $row['INDICE'] = floatval($row['INDICE']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Guarda el indice de variacion de un mes (upsert)
     * @param int $anio Anio del mes
     * @param int $mes Numero de mes (1-12)
     * @param float $indice Indice de variacion (0.10 = +10%)
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveIndice($anio, $mes, $indice, $usuario = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $anio = intval($anio);
        $mes = intval($mes);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mes invalido: ' . $mes);
        }

        $sqlCheck = "SELECT ANIO FROM RO_T_CASHFLOW_VENTAS_INDICE WHERE ANIO = ? AND MES = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$anio, $mes]);

        if ($stmtCheck === false) {
            throw new Exception($this->errorSql('Error al verificar el indice'));
        }

        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        if ($exists) {
            $sql = "UPDATE RO_T_CASHFLOW_VENTAS_INDICE
                    SET INDICE = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE ANIO = ? AND MES = ?";
            $params = [floatval($indice), $usuario, $anio, $mes];
        } else {
            $sql = "INSERT INTO RO_T_CASHFLOW_VENTAS_INDICE
                        (ANIO, MES, INDICE, FECHA_UPDATE, USUARIO)
                    VALUES (?, ?, ?, GETDATE(), ?)";
            $params = [$anio, $mes, floatval($indice), $usuario];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el indice'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Participaciones guardadas (calculadas + editadas)
     * @param string|null $tipo 'TRAMO28', 'MENSUAL' o null para ambos
     * @return array Listado de participaciones
     */
    public function getParticipacion($tipo = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT TIPO, ANIO, MES, CANAL, PORCENTAJE_CALC, PORCENTAJE_EDIT,
                       FECHA_UPDATE, USUARIO
                FROM RO_T_CASHFLOW_VENTAS_PARTIC";
        $params = [];

        if ($tipo !== null) {
            $sql .= " WHERE TIPO = ?";
            $params[] = $tipo;
        }

        $sql .= " ORDER BY TIPO, ANIO, MES, CANAL";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las participaciones'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['FECHA_UPDATE']) && $row['FECHA_UPDATE'] instanceof DateTime) {
                $row['FECHA_UPDATE'] = $row['FECHA_UPDATE']->format('Y-m-d');
            }

            $row['ANIO'] = intval($row['ANIO']);
            $row['MES'] = intval($row['MES']);
            $row['PORCENTAJE_CALC'] = floatval($row['PORCENTAJE_CALC']);
            $row['PORCENTAJE_EDIT'] = ($row['PORCENTAJE_EDIT'] === null)
                ? null
                : floatval($row['PORCENTAJE_EDIT']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Guarda las participaciones de un periodo completo (los cuatro canales
     * juntos), validando en el servidor que sumen 100%.
     *
     * Se guarda siempre el calculado junto al editado, mismo criterio
     * _ORIG / _EDIT que usa Comex.
     *
     * @param string $tipo 'TRAMO28' o 'MENSUAL'
     * @param int $anio Anio de anclaje
     * @param int $mes Mes de anclaje
     * @param array $valores Mapa CANAL => ['calc' => float, 'edit' => float|null]
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveParticipacion($tipo, $anio, $mes, $valores, $usuario = null) {
        if (!in_array($tipo, ['TRAMO28', 'MENSUAL'])) {
            throw new Exception('Tipo de participacion invalido: ' . $tipo);
        }

        if (!is_array($valores) || count($valores) === 0) {
            throw new Exception('No se recibieron participaciones para guardar');
        }

        $anio = intval($anio);
        $mes = intval($mes);

        if ($mes < 1 || $mes > 12) {
            throw new Exception('Mes invalido: ' . $mes);
        }

        // La validacion tambien corre en el front, pero no se confia en el cliente
        $suma = 0;

        foreach (Parametros::CANALES as $canal) {
            if (!isset($valores[$canal])) {
                throw new Exception('Falta la participacion del canal ' . $canal);
            }

            $edit = $valores[$canal]['edit'];
            $calc = floatval($valores[$canal]['calc']);
            $suma += ($edit === null || $edit === '') ? $calc : floatval($edit);
        }

        if (abs($suma - 1) >= 0.000001) {
            throw new Exception(
                'La suma de las participaciones debe ser 100%. Suma recibida: '
                . number_format($suma * 100, 4, ',', '.') . '%'
            );
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        foreach (Parametros::CANALES as $canal) {
            $calc = floatval($valores[$canal]['calc']);
            $edit = $valores[$canal]['edit'];
            $edit = ($edit === null || $edit === '') ? null : floatval($edit);

            $sqlCheck = "SELECT CANAL FROM RO_T_CASHFLOW_VENTAS_PARTIC
                         WHERE TIPO = ? AND ANIO = ? AND MES = ? AND CANAL = ?";
            $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$tipo, $anio, $mes, $canal]);

            if ($stmtCheck === false) {
                throw new Exception($this->errorSql('Error al verificar la participacion'));
            }

            $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmtCheck);

            if ($exists) {
                $sql = "UPDATE RO_T_CASHFLOW_VENTAS_PARTIC
                        SET PORCENTAJE_CALC = ?, PORCENTAJE_EDIT = ?,
                            FECHA_UPDATE = GETDATE(), USUARIO = ?
                        WHERE TIPO = ? AND ANIO = ? AND MES = ? AND CANAL = ?";
                $params = [$calc, $edit, $usuario, $tipo, $anio, $mes, $canal];
            } else {
                $sql = "INSERT INTO RO_T_CASHFLOW_VENTAS_PARTIC
                            (TIPO, ANIO, MES, CANAL, PORCENTAJE_CALC, PORCENTAJE_EDIT,
                             FECHA_UPDATE, USUARIO)
                        VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?)";
                $params = [$tipo, $anio, $mes, $canal, $calc, $edit, $usuario];
            }

            $stmt = sqlsrv_query($cid, $sql, $params);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar la participacion'));
            }

            sqlsrv_free_stmt($stmt);
        }

        return true;
    }

    /**
     * Mix de medios de cobro y plazos por canal
     * @param bool $soloActivos Si true, devuelve solo los medios activos
     * @return array Listado del mix
     */
    public function getMixCobro($soloActivos = false) {
        return $this->parametros->getMixCobro($soloActivos);
    }

    /**
     * Guarda una fila del mix de cobro
     * @param int $id ID de la fila
     * @param float $porcentaje Porcentaje del mix (0 a 1)
     * @param int $diasAcreditacion Dias hasta la acreditacion
     * @param bool $activo Si el medio se usa en la proyeccion
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveMixCobro($id, $porcentaje, $diasAcreditacion, $activo = true, $usuario = null) {
        return $this->parametros->saveMixCobro($id, $porcentaje, $diasAcreditacion, $activo, $usuario);
    }

    /**
     * Parametros generales del modulo
     * @param string|null $grupo Grupo a filtrar
     * @return array Listado de parametros
     */
    public function getParametros($grupo = null) {
        return $this->parametros->getParametros($grupo);
    }

    /**
     * Guarda un parametro
     * @param string $clave Clave del parametro
     * @param string $valor Valor a guardar
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveParametro($clave, $valor, $usuario = null) {
        return $this->parametros->saveParametro($clave, $valor, $usuario);
    }

    /**
     * Mapa de dias bancarios habiles.
     *
     * Unica lectura del modulo fuera de 'central': RO_T_CALENDARIO vive en la
     * conexion 'power' (host de apps, base DATABASE_POWER).
     *
     * La tabla esta poblada hasta 2027 y se sigue extendiendo. Si el motor pide
     * una fecha que no existe, no se rompe: proximoHabil() asume habil de lunes
     * a viernes y registra un warning.
     *
     * @param string $desde Fecha inicial 'Y-m-d'
     * @param string $hasta Fecha final 'Y-m-d'
     * @return array Mapa 'Y-m-d' => bool
     */
    public function getDiasHabiles($desde, $hasta) {
        $cid = $this->conn->conectar('power');

        if (!$cid) {
            // conectar() devuelve false y manda el detalle al error_log. Se
            // rescata aca para que el motivo real (login, base inexistente,
            // servidor inaccesible) llegue al front en vez de un mensaje
            // generico que no permite diagnosticar nada.
            throw new Exception($this->errorSql(
                'No se pudo conectar al calendario bancario (conexion "power")'
            ));
        }

        $sql = "SELECT FECHA, DIA_LABORAL
                FROM RO_T_CALENDARIO
                WHERE FECHA BETWEEN ? AND ?";

        $stmt = sqlsrv_query($cid, $sql, [$desde, $hasta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el calendario bancario'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fecha = ($row['FECHA'] instanceof DateTime)
                ? $row['FECHA']->format('Y-m-d')
                : substr((string)$row['FECHA'], 0, 10);

            $mapa[$fecha] = (intval($row['DIA_LABORAL']) === 1);
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Neteo de cheques adelantados.
     *
     * CABLEADO Y APAGADO A PROPOSITO: la vista origen todavia no existe, asi que
     * RO_T_CASHFLOW_VENTAS_PRECHEQ esta vacia y esto devuelve siempre cero. El
     * circuito completo (tabla, parametro, metodo, case del controller y fila
     * del front) ya esta armado: cuando exista la vista solo se enchufa el
     * origen de datos.
     *
     * LOGICA FUTURA
     * Se toma la fecha del cheque, se le restan 'dias_prechequeado' dias para
     * obtener la fecha teorica de la factura, y el importe se resta de la
     * cobranza proyectada de esa fecha (tramo diario) o de ese mes (tramo
     * mensual). Es para no duplicar cobranza de echeqs ya recibidos por ventas
     * anteriores.
     *
     * @param array $dias Lista de fechas 'Y-m-d' del tramo diario
     * @param array $meses Lista de claves 'Y-m' del tramo mensual
     * @return array ['dias' => mapa, 'meses' => mapa, 'total' => float]
     */
    public function getNeteoPrechequeado($dias = [], $meses = []) {
        $neteo = [
            'dias' => [],
            'meses' => [],
            'total' => 0
        ];

        foreach ($dias as $fecha) {
            $neteo['dias'][$fecha] = 0;
        }

        foreach ($meses as $clave) {
            $neteo['meses'][$clave] = 0;
        }

        // Sin origen de datos todavia: la tabla esta vacia y el neteo es cero.
        // Cuando exista la vista, aca se leera RO_T_CASHFLOW_VENTAS_PRECHEQ
        // agrupando por FECHA_TEORICA_FACTURA y se restara de cada bucket.

        return $neteo;
    }

    /* ====================================================================
       MOTOR DE PROYECCION
       ==================================================================== */

    /**
     * Grilla de venta proyectada: 28 dias + 12 meses.
     * @return array Grilla resuelta lista para el front
     */
    public function proyectarVentas() {
        return $this->calcular(false);
    }

    /**
     * Grilla de cobranza proyectada: aplica mix, plazos y corrimiento a dia
     * bancario habil sobre la venta diaria.
     * @return array Grilla resuelta lista para el front
     */
    public function proyectarCobranzas() {
        return $this->calcular(true);
    }

    /**
     * Motor completo. El calculo pesado vive aca, en PHP: el front recibe la
     * grilla ya resuelta.
     *
     * @param bool $conCobranza Si true tambien resuelve la cobranza
     * @return array Estructura completa de la proyeccion
     */
    private function calcular($conCobranza) {
        $this->warnings = [];

        /* ---- 1. Parametros ------------------------------------------------ */
        $map = $this->parametros->getParametrosMap();

        $horizonteDias  = Parametros::ent($map, 'horizonte_dias');
        $horizonteMeses = Parametros::ent($map, 'horizonte_meses');
        $alicuotaIva    = Parametros::num($map, 'alicuota_iva');
        $feriadosMMDD   = $this->parametros->getFeriadosComercio($map);
        $respaldo       = $this->parametros->getParticipacionRespaldo($map);

        if ($horizonteDias < 1 || $horizonteMeses < 1) {
            throw new Exception('El horizonte de proyeccion debe ser mayor a cero');
        }

        /* ---- 2. Ejes temporales ------------------------------------------ */
        $hoy = new DateTime('today');

        // Tramo diario: hoy .. hoy + horizonteDias - 1
        $dias = [];
        $diasSet = [];
        $cursor = clone $hoy;

        for ($i = 0; $i < $horizonteDias; $i++) {
            $fecha = $cursor->format('Y-m-d');
            $esFeriado = in_array($cursor->format('m-d'), $feriadosMMDD);

            $dias[] = [
                'fecha' => $fecha,
                'label' => intval($cursor->format('j')) . '/' . intval($cursor->format('n')),
                'mes_clave' => $cursor->format('Y-m'),
                'feriado_comercio' => $esFeriado
            ];

            $diasSet[$fecha] = true;
            $cursor->modify('+1 day');
        }

        // Tramo mensual: mes actual + los siguientes (horizonteMeses - 1)
        $meses = $this->ejeMeses($horizonteMeses);

        // Ventana de generacion de venta: desde hoy hasta el fin del ultimo mes
        $ultimoMes = $meses[count($meses) - 1];
        $ventanaFin = (new DateTime($ultimoMes['clave'] . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');

        /* ---- 3. Historico y ediciones ------------------------------------- */
        $hist = [];

        foreach ($this->getHistoricoVentas() as $row) {
            $hist[$row['ANIO']][$row['MES']][$row['CANAL']] = $row['IMPORTE_NETO'];
        }

        $indices = [];

        foreach ($this->getIndices() as $row) {
            $indices[sprintf('%04d-%02d', $row['ANIO'], $row['MES'])] = $row['INDICE'];
        }

        $particEdit = [];

        foreach ($this->getParticipacion() as $row) {
            $clave = sprintf('%04d-%02d', $row['ANIO'], $row['MES']);
            $particEdit[$row['TIPO']][$clave][$row['CANAL']] = $row;
        }

        /* ---- 4. Base mensual y participacion por mes ---------------------- */
        // La base mensual la calcula el mismo helper que usa getAnalisisVentas(),
        // para que la venta proyectada del Analisis y la de la grilla de
        // Proyeccion no se puedan desincronizar.
        $baseMensual = $this->baseMensual($meses, $hist, $indices, $alicuotaIva, $feriadosMMDD);
        $particMensual = [];

        foreach ($meses as $m) {
            $clave = $m['clave'];
            $anioAnt = $m['anio'] - 1;
            $porCanalAnt = isset($hist[$anioAnt][$m['mes']]) ? $hist[$anioAnt][$m['mes']] : [];
            $totalAnt = $baseMensual[$clave]['neto_anio_anterior'];

            // Fallback: sin datos del anio anterior o venta total no positiva.
            // Un total negativo (mes dominado por notas de credito) produce
            // participaciones sin sentido, asi que dispara el mismo respaldo.
            $calc = $baseMensual[$clave]['estimado']
                ? $respaldo
                : $this->participacionDesde($porCanalAnt, $totalAnt);

            $particMensual[$clave] = $this->aplicarOverride(
                $calc,
                isset($particEdit['MENSUAL'][$clave]) ? $particEdit['MENSUAL'][$clave] : []
            );
        }

        /* ---- 5. Participacion del tramo de 28 dias ------------------------ */
        // La participacion del tramo se calcula sobre el MISMO PERIODO del anio
        // anterior. El historico esta al grano de mes, asi que cada mes que toca
        // el tramo pondera por la fraccion de dias del mes que el tramo cubre.
        $pesoTramo = [];

        foreach ($dias as $d) {
            if (!isset($pesoTramo[$d['mes_clave']])) {
                $pesoTramo[$d['mes_clave']] = 0;
            }

            $pesoTramo[$d['mes_clave']]++;
        }

        $porCanalTramo = [];
        $totalTramo = 0;

        foreach (Parametros::CANALES as $canal) {
            $porCanalTramo[$canal] = 0;
        }

        foreach ($pesoTramo as $clave => $cantDias) {
            $ref = new DateTime($clave . '-01');
            $anioAnt = intval($ref->format('Y')) - 1;
            $mesNum = intval($ref->format('n'));
            $diasDelMes = intval($ref->format('t'));
            $peso = $cantDias / $diasDelMes;

            foreach (Parametros::CANALES as $canal) {
                $importe = isset($hist[$anioAnt][$mesNum][$canal]) ? $hist[$anioAnt][$mesNum][$canal] : 0;
                $porCanalTramo[$canal] += $peso * $importe;
                $totalTramo += $peso * $importe;
            }
        }

        $tramoEstimado = ($totalTramo <= 0);

        $calcTramo = $tramoEstimado
            ? $respaldo
            : $this->participacionDesde($porCanalTramo, $totalTramo);

        $claveAncla = $dias[0]['mes_clave'];

        $particTramo = $this->aplicarOverride(
            $calcTramo,
            isset($particEdit['TRAMO28'][$claveAncla]) ? $particEdit['TRAMO28'][$claveAncla] : []
        );

        /* ---- 6. Venta diaria --------------------------------------------- */
        // Cada dia toma la tasa de SU PROPIO mes: si el tramo cruza de septiembre
        // a octubre, los dias de septiembre usan la diaria de septiembre y los de
        // octubre la de octubre.
        //
        // Dentro del tramo de 28 dias rige la participacion del tramo (que el
        // usuario edita); fuera del tramo rige la participacion mensual. Como
        // ambas suman 100%, el total del mes se conserva igual.
        $ventaDiaria = [];
        $cursor = clone $hoy;
        $fin = new DateTime($ventanaFin);

        while ($cursor <= $fin) {
            $fecha = $cursor->format('Y-m-d');
            $claveMes = $cursor->format('Y-m');

            if (!isset($baseMensual[$claveMes])) {
                $cursor->modify('+1 day');
                continue;
            }

            $base = $baseMensual[$claveMes];
            $esFeriado = in_array($cursor->format('m-d'), $feriadosMMDD);

            $ventaDiaria[$fecha] = [];

            foreach (Parametros::CANALES as $canal) {
                if ($esFeriado || $base['dias_vendibles'] <= 0) {
                    // Feriado de comercio: no se estima venta
                    $ventaDiaria[$fecha][$canal] = 0;
                    continue;
                }

                $porc = isset($diasSet[$fecha])
                    ? $particTramo[$canal]['efectivo']
                    : $particMensual[$claveMes][$canal]['efectivo'];

                $ventaDiaria[$fecha][$canal] =
                    ($base['con_iva'] * $porc) / $base['dias_vendibles'];
            }

            $cursor->modify('+1 day');
        }

        /* ---- 7. Agregacion de la venta ----------------------------------- */
        // Los dias del tramo se muestran en columnas diarias; la columna del mes
        // acumula UNICAMENTE los dias que quedaron fuera del tramo. Nada se
        // cuenta dos veces.
        $venta = $this->agregar($ventaDiaria, $dias, $meses, $diasSet);

        $resultado = [
            'generado' => $hoy->format('Y-m-d'),
            'canales' => Parametros::CANALES,
            'horizonte_dias' => $horizonteDias,
            'horizonte_meses' => $horizonteMeses,
            'alicuota_iva' => $alicuotaIva,
            'dias' => $dias,
            'meses' => $meses,
            'base_mensual' => array_values($baseMensual),
            'participacion' => [
                'tramo28' => $particTramo,
                'tramo28_estimado' => $tramoEstimado,
                'tramo28_ancla' => $claveAncla,
                'mensual' => $particMensual
            ],
            'venta' => $venta,
            'kpi' => [
                'venta_tramo' => $venta['total_tramo'],
                'venta_horizonte' => $venta['total_horizonte']
            ],
            'warnings' => $this->warnings
        ];

        if (!$conCobranza) {
            return $resultado;
        }

        /* ---- 8. Cobranza -------------------------------------------------- */
        $mix = $this->getMixCobro(true);

        if (count($mix) === 0) {
            throw new Exception('No hay medios de cobro activos en RO_T_CASHFLOW_VENTAS_MIX');
        }

        $maxDias = 0;

        foreach ($mix as $m) {
            if ($m['DIAS_ACREDITACION'] > $maxDias) {
                $maxDias = $m['DIAS_ACREDITACION'];
            }
        }

        // Se pide el calendario con margen: el plazo maximo mas holgura para el
        // corrimiento a habil (fines de semana largos).
        $calFin = (new DateTime($ventanaFin))
            ->modify('+' . ($maxDias + 30) . ' days')
            ->format('Y-m-d');

        $habiles = $this->getDiasHabiles($hoy->format('Y-m-d'), $calFin);

        $cobranza = $this->calcularCobranza(
            $ventaDiaria, $mix, $habiles, $dias, $meses, $diasSet
        );

        $cobranza['neteo_prechequeado'] = $this->getNeteoPrechequeado(
            array_column($dias, 'fecha'),
            array_column($meses, 'clave')
        );

        $resultado['cobranza'] = $cobranza;
        $resultado['mix'] = $mix;
        $resultado['kpi']['cobranza_tramo'] = $cobranza['total_tramo'];
        $resultado['kpi']['cobranza_horizonte'] = $cobranza['total_horizonte'];
        $resultado['warnings'] = $this->warnings;

        return $resultado;
    }

    /**
     * Eje de meses del horizonte: mes ACTUAL + los siguientes (horizonte - 1).
     * Con horizonte 12 son el mes actual mas 11.
     *
     * @param int $horizonteMeses Cantidad de meses del horizonte
     * @return array Lista de meses con clave, anio, mes y label
     */
    private function ejeMeses($horizonteMeses) {
        $hoy = new DateTime('today');
        $meses = [];

        for ($i = 0; $i < $horizonteMeses; $i++) {
            $ref = new DateTime($hoy->format('Y-m-01'));
            $ref->modify("+$i month");

            $mes = intval($ref->format('n'));

            $meses[] = [
                'clave' => $ref->format('Y-m'),
                'anio' => intval($ref->format('Y')),
                'mes' => $mes,
                'label' => self::$mesesAbrev[$mes] . '-' . $ref->format('y')
            ];
        }

        return $meses;
    }

    /**
     * Base mensual de la proyeccion, sin apertura por canal:
     *
     *   neto_anio_anterior = venta neta real del MISMO MES del anio anterior
     *   neto_proyectado    = neto_anio_anterior * (1 + indice)
     *   con_iva            = neto_proyectado * (1 + alicuota_iva)
     *
     * Es la unica implementacion de la formula: la usan tanto la grilla de
     * Proyeccion como la tabla de Analisis de Ventas.
     *
     * @param array $meses Eje devuelto por ejeMeses()
     * @param array $hist Historico indexado [anio][mes][canal] => importe
     * @param array $indices Mapa 'Y-m' => indice
     * @param float $alicuotaIva Alicuota de IVA
     * @param array $feriadosMMDD Feriados de comercio 'MM-DD'
     * @return array Mapa 'Y-m' => base del mes
     */
    private function baseMensual($meses, $hist, $indices, $alicuotaIva, $feriadosMMDD) {
        $base = [];

        foreach ($meses as $m) {
            $clave = $m['clave'];
            $anioAnt = $m['anio'] - 1;
            $anioPrev = $m['anio'] - 2;

            $totalAnt = $this->totalMes($hist, $anioAnt, $m['mes']);
            $totalPrev = $this->totalMes($hist, $anioPrev, $m['mes']);

            $indice = isset($indices[$clave]) ? $indices[$clave] : 0;
            $netoProy = $totalAnt * (1 + $indice);

            $base[$clave] = [
                'clave' => $clave,
                'anio' => $m['anio'],
                'mes' => $m['mes'],
                'label' => $m['label'],
                'anio_anterior' => $anioAnt,
                'label_anio_anterior' => self::$mesesAbrev[$m['mes']] . '-' . substr((string)$anioAnt, 2),
                'neto_anio_anterior' => $totalAnt,
                'anio_previo' => $anioPrev,
                'label_anio_previo' => self::$mesesAbrev[$m['mes']] . '-' . substr((string)$anioPrev, 2),
                'neto_anio_previo' => $totalPrev,
                // Variacion interanual entre los dos anios reales: cuanto crecio
                // el anio base contra el anterior a el.
                'variacion' => ($totalPrev > 0) ? (($totalAnt / $totalPrev) - 1) : null,
                'indice' => $indice,
                'neto_proyectado' => $netoProy,
                'con_iva' => $netoProy * (1 + $alicuotaIva),
                'estimado' => ($totalAnt <= 0),
                'dias_vendibles' => $this->diasVendibles($m['anio'], $m['mes'], $feriadosMMDD)
            ];
        }

        return $base;
    }

    /**
     * Suma la venta neta de los cuatro canales de un mes del historico
     * @param array $hist Historico indexado [anio][mes][canal]
     * @param int $anio Anio a leer
     * @param int $mes Mes a leer
     * @return float Total del mes
     */
    private function totalMes($hist, $anio, $mes) {
        $total = 0;

        foreach (Parametros::CANALES as $canal) {
            $total += isset($hist[$anio][$mes][$canal]) ? $hist[$anio][$mes][$canal] : 0;
        }

        return $total;
    }

    /**
     * Convierte importes por canal en participaciones que suman 1.
     * Los canales con importe negativo (mes dominado por notas de credito) se
     * llevan a cero y el resto se renormaliza, para no proyectar venta negativa.
     *
     * @param array $porCanal Mapa CANAL => importe
     * @param float $total Total de referencia
     * @return array Mapa CANAL => porcentaje
     */
    private function participacionDesde($porCanal, $total) {
        $positivos = [];
        $suma = 0;

        foreach (Parametros::CANALES as $canal) {
            $importe = isset($porCanal[$canal]) ? $porCanal[$canal] : 0;
            $importe = ($importe > 0) ? $importe : 0;
            $positivos[$canal] = $importe;
            $suma += $importe;
        }

        if ($suma <= 0) {
            $suma = $total;
        }

        $partic = [];

        foreach (Parametros::CANALES as $canal) {
            $partic[$canal] = ($suma > 0) ? ($positivos[$canal] / $suma) : 0;
        }

        return $partic;
    }

    /**
     * Combina la participacion calculada con el override manual guardado.
     * Se devuelven los tres valores para que el front pueda mostrar el calculado
     * al lado del input editable.
     *
     * @param array $calc Mapa CANAL => porcentaje calculado
     * @param array $edits Mapa CANAL => fila de RO_T_CASHFLOW_VENTAS_PARTIC
     * @return array Mapa CANAL => ['calc','edit','efectivo']
     */
    private function aplicarOverride($calc, $edits) {
        $resultado = [];

        foreach (Parametros::CANALES as $canal) {
            $edit = null;

            if (isset($edits[$canal]) && $edits[$canal]['PORCENTAJE_EDIT'] !== null) {
                $edit = floatval($edits[$canal]['PORCENTAJE_EDIT']);
            }

            $resultado[$canal] = [
                'calc' => isset($calc[$canal]) ? $calc[$canal] : 0,
                'edit' => $edit,
                'efectivo' => ($edit === null) ? (isset($calc[$canal]) ? $calc[$canal] : 0) : $edit
            ];
        }

        return $resultado;
    }

    /**
     * Dias vendibles de un mes: todos los dias del mes menos los feriados de
     * comercio que caen en el. Sabados y domingos SI cuentan: las sucursales
     * abren. Como el divisor los descuenta, el total mensual se conserva.
     *
     * @param int $anio Anio del mes
     * @param int $mes Numero de mes
     * @param array $feriadosMMDD Lista de feriados 'MM-DD'
     * @return int Cantidad de dias con venta estimada
     */
    private function diasVendibles($anio, $mes, $feriadosMMDD) {
        $ref = new DateTime(sprintf('%04d-%02d-01', $anio, $mes));
        $diasDelMes = intval($ref->format('t'));
        $vendibles = $diasDelMes;

        foreach ($feriadosMMDD as $mmdd) {
            if (intval(substr($mmdd, 0, 2)) === $mes) {
                $dia = intval(substr($mmdd, 3, 2));

                if ($dia >= 1 && $dia <= $diasDelMes) {
                    $vendibles--;
                }
            }
        }

        return $vendibles;
    }

    /**
     * Agrega una serie diaria por canal en columnas de dias del tramo y columnas
     * de meses, sin superposicion: la columna de un mes acumula solo los dias
     * que quedaron fuera del tramo diario.
     *
     * @param array $serieDiaria Mapa 'Y-m-d' => [CANAL => monto]
     * @param array $dias Eje de dias del tramo
     * @param array $meses Eje de meses del horizonte
     * @param array $diasSet Set de fechas del tramo
     * @return array Grilla agregada
     */
    private function agregar($serieDiaria, $dias, $meses, $diasSet) {
        $grilla = [
            'dias' => [],
            'meses' => [],
            'total_dias' => [],
            'total_meses' => [],
            'total_tramo' => 0,
            'total_horizonte' => 0
        ];

        $mesesClaves = array_column($meses, 'clave');

        foreach (Parametros::CANALES as $canal) {
            $grilla['dias'][$canal] = [];
            $grilla['meses'][$canal] = [];

            foreach ($dias as $d) {
                $grilla['dias'][$canal][$d['fecha']] = 0;
            }

            foreach ($mesesClaves as $clave) {
                $grilla['meses'][$canal][$clave] = 0;
            }
        }

        foreach ($dias as $d) {
            $grilla['total_dias'][$d['fecha']] = 0;
        }

        foreach ($mesesClaves as $clave) {
            $grilla['total_meses'][$clave] = 0;
        }

        foreach ($serieDiaria as $fecha => $porCanal) {
            $enTramo = isset($diasSet[$fecha]);
            $claveMes = substr($fecha, 0, 7);
            $enMes = in_array($claveMes, $mesesClaves, true);

            if (!$enTramo && !$enMes) {
                continue;
            }

            foreach ($porCanal as $canal => $monto) {
                if (!isset($grilla['dias'][$canal])) {
                    continue;
                }

                if ($enTramo) {
                    $grilla['dias'][$canal][$fecha] += $monto;
                    $grilla['total_dias'][$fecha] += $monto;
                    $grilla['total_tramo'] += $monto;
                } else {
                    $grilla['meses'][$canal][$claveMes] += $monto;
                    $grilla['total_meses'][$claveMes] += $monto;
                }

                $grilla['total_horizonte'] += $monto;
            }
        }

        return $grilla;
    }

    /**
     * Resuelve la cobranza: para cada dia de venta, cada canal y cada medio de
     * pago se calcula el monto y la fecha real de acreditacion, corriendo al
     * proximo dia bancario habil. Recien despues se agrega por dia y por mes.
     *
     * NO se usa el prorrateo lineal n/r del Excel: la fecha se resuelve dia por
     * dia. Que la cobranza se concentre los lunes es un efecto del corrimiento
     * (acumula sabado, domingo y lunes), no una regla aparte.
     *
     * @param array $ventaDiaria Mapa 'Y-m-d' => [CANAL => monto]
     * @param array $mix Mix de cobro activo
     * @param array $habiles Mapa 'Y-m-d' => bool de dias bancarios habiles
     * @param array $dias Eje de dias del tramo
     * @param array $meses Eje de meses del horizonte
     * @param array $diasSet Set de fechas del tramo
     * @return array Grilla de cobranza por canal y por canal x medio de pago
     */
    private function calcularCobranza($ventaDiaria, $mix, $habiles, $dias, $meses, $diasSet) {
        $mesesClaves = array_column($meses, 'clave');
        $mesesSet = array_flip($mesesClaves);

        // Mix indexado por canal
        $mixPorCanal = [];

        foreach ($mix as $m) {
            $mixPorCanal[$m['CANAL']][] = $m;
        }

        // Estructura de salida: una fila por canal x medio de pago (aunque el
        // porcentaje sea cero, la fila se muestra igual) y subtotales por canal
        $filas = [];

        foreach (Parametros::CANALES as $canal) {
            if (!isset($mixPorCanal[$canal])) {
                continue;
            }

            foreach ($mixPorCanal[$canal] as $m) {
                $filas[] = [
                    'canal' => $canal,
                    'medio_pago' => $m['MEDIO_PAGO'],
                    'porcentaje' => $m['PORCENTAJE'],
                    'dias_acreditacion' => $m['DIAS_ACREDITACION'],
                    'clave' => $canal . '|' . $m['MEDIO_PAGO']
                ];
            }
        }

        $grilla = [
            'filas' => $filas,
            'dias' => [],
            'meses' => [],
            'subtotal_dias' => [],
            'subtotal_meses' => [],
            'total_dias' => [],
            'total_meses' => [],
            'total_tramo' => 0,
            'total_horizonte' => 0
        ];

        foreach ($filas as $f) {
            $grilla['dias'][$f['clave']] = [];
            $grilla['meses'][$f['clave']] = [];

            foreach ($dias as $d) {
                $grilla['dias'][$f['clave']][$d['fecha']] = 0;
            }

            foreach ($mesesClaves as $clave) {
                $grilla['meses'][$f['clave']][$clave] = 0;
            }
        }

        foreach (Parametros::CANALES as $canal) {
            $grilla['subtotal_dias'][$canal] = [];
            $grilla['subtotal_meses'][$canal] = [];

            foreach ($dias as $d) {
                $grilla['subtotal_dias'][$canal][$d['fecha']] = 0;
            }

            foreach ($mesesClaves as $clave) {
                $grilla['subtotal_meses'][$canal][$clave] = 0;
            }
        }

        foreach ($dias as $d) {
            $grilla['total_dias'][$d['fecha']] = 0;
        }

        foreach ($mesesClaves as $clave) {
            $grilla['total_meses'][$clave] = 0;
        }

        // Cache de corrimiento a habil: muchas fechas de venta distintas caen en
        // la misma fecha de acreditacion teorica.
        $cacheHabil = [];

        foreach ($ventaDiaria as $fecha => $porCanal) {
            foreach ($porCanal as $canal => $ventaDia) {
                if ($ventaDia == 0 || !isset($mixPorCanal[$canal])) {
                    continue;
                }

                foreach ($mixPorCanal[$canal] as $m) {
                    $monto = $ventaDia * $m['PORCENTAJE'];

                    if ($monto == 0) {
                        continue;
                    }

                    $teorica = date('Y-m-d', strtotime($fecha . ' +' . $m['DIAS_ACREDITACION'] . ' days'));

                    if (!isset($cacheHabil[$teorica])) {
                        $cacheHabil[$teorica] = $this->proximoHabil($teorica, $habiles);
                    }

                    $acred = $cacheHabil[$teorica];
                    $claveFila = $canal . '|' . $m['MEDIO_PAGO'];

                    if (isset($diasSet[$acred])) {
                        $grilla['dias'][$claveFila][$acred] += $monto;
                        $grilla['subtotal_dias'][$canal][$acred] += $monto;
                        $grilla['total_dias'][$acred] += $monto;
                        $grilla['total_tramo'] += $monto;
                        $grilla['total_horizonte'] += $monto;
                        continue;
                    }

                    $claveMes = substr($acred, 0, 7);

                    if (isset($mesesSet[$claveMes])) {
                        $grilla['meses'][$claveFila][$claveMes] += $monto;
                        $grilla['subtotal_meses'][$canal][$claveMes] += $monto;
                        $grilla['total_meses'][$claveMes] += $monto;
                        $grilla['total_horizonte'] += $monto;
                    }

                    // Fuera del horizonte: se descarta.
                }
            }
        }

        return $grilla;
    }

    /**
     * Corre una fecha al proximo dia bancario habil.
     * Si la fecha no existe en RO_T_CALENDARIO (la tabla esta poblada hasta 2027
     * y se sigue extendiendo) no revienta: asume habil de lunes a viernes y
     * registra un warning.
     *
     * @param string $fecha Fecha teorica 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return string Fecha habil 'Y-m-d'
     */
    private function proximoHabil($fecha, $habiles) {
        $cursor = $fecha;

        // Tope defensivo: ningun feriado encadena mas de 30 dias no habiles
        for ($i = 0; $i < 30; $i++) {
            if (isset($habiles[$cursor])) {
                if ($habiles[$cursor]) {
                    return $cursor;
                }
            } else {
                $this->warnCalendario($cursor);

                // Fallback: lunes a viernes se consideran habiles
                if (intval(date('N', strtotime($cursor))) <= 5) {
                    return $cursor;
                }
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return $cursor;
    }

    /**
     * Registra un warning de calendario faltante, deduplicado por mes para no
     * inundar la respuesta.
     * @param string $fecha Fecha ausente en RO_T_CALENDARIO
     * @return void
     */
    private function warnCalendario($fecha) {
        $mes = substr($fecha, 0, 7);
        $msg = 'RO_T_CALENDARIO no tiene datos para ' . $mes
             . '. Se asumen habiles los dias de lunes a viernes.';

        if (!in_array($msg, $this->warnings)) {
            $this->warnings[] = $msg;
        }
    }

    /* ====================================================================
       ANALISIS DE VENTAS
       ==================================================================== */

    /**
     * Datos de la sub-pestana Analisis de Ventas.
     *
     * Devuelve dos bloques, ninguno abierto por canal:
     *
     * 1. PROYECCION POR MES (12 meses: el actual + 11). Por cada mes:
     *      anio previo    -> venta neta real del mismo mes, dos anios atras
     *      anio anterior  -> venta neta real del mismo mes, un anio atras.
     *                        Es la BASE de la proyeccion.
     *      variacion      -> cuanto crecio el anio anterior contra el previo
     *      indice         -> editable, se guarda contra (anio, mes) del mes
     *                        proyectado
     *      proyectada     -> neto_anio_anterior * (1 + indice) * (1 + IVA)
     *
     * 2. CONTROL DE FACTURACION: por mes, el total facturado y el total
     *    remitido con su suma. Es solo un bloque de control para contrastar
     *    contra el tablero; los remitos NO entran en la proyeccion.
     *
     * @param int $anioDesde Anio minimo del bloque de control de facturacion
     * @return array Estructura para el front
     */
    public function getAnalisisVentas($anioDesde) {
        $anioDesde = intval($anioDesde);

        $map = $this->parametros->getParametrosMap();
        $horizonteMeses = Parametros::ent($map, 'horizonte_meses');
        $alicuotaIva = Parametros::num($map, 'alicuota_iva');
        $feriadosMMDD = $this->parametros->getFeriadosComercio($map);

        // Historico completo: la variacion interanual necesita dos anios hacia
        // atras respecto del mes proyectado.
        $hist = [];

        foreach ($this->getHistoricoVentas() as $row) {
            $hist[$row['ANIO']][$row['MES']][$row['CANAL']] = $row['IMPORTE_NETO'];
        }

        $indices = [];

        foreach ($this->getIndices() as $row) {
            $indices[sprintf('%04d-%02d', $row['ANIO'], $row['MES'])] = $row['INDICE'];
        }

        /* ---- 1. Proyeccion por mes --------------------------------------- */
        $meses = $this->ejeMeses($horizonteMeses);
        $base = $this->baseMensual($meses, $hist, $indices, $alicuotaIva, $feriadosMMDD);

        $filas = [];
        $totales = [
            'neto_anio_previo' => 0,
            'neto_anio_anterior' => 0,
            'con_iva' => 0
        ];

        foreach ($meses as $m) {
            $b = $base[$m['clave']];

            $filas[] = [
                'clave' => $b['clave'],
                'anio' => $b['anio'],
                'mes' => $b['mes'],
                'label' => $b['label'],
                'anio_previo' => $b['anio_previo'],
                'label_anio_previo' => $b['label_anio_previo'],
                'neto_anio_previo' => $b['neto_anio_previo'],
                'anio_anterior' => $b['anio_anterior'],
                'label_anio_anterior' => $b['label_anio_anterior'],
                'neto_anio_anterior' => $b['neto_anio_anterior'],
                'variacion' => $b['variacion'],
                'indice' => $b['indice'],
                'indice_editado' => isset($indices[$b['clave']]),
                // Paso intermedio: ya tiene el indice aplicado pero todavia no
                // el IVA. Es lo que hace visible de donde sale la diferencia
                // entre el neto del anio anterior y la venta proyectada.
                'neto_proyectado' => $b['neto_proyectado'],
                'venta_proyectada' => $b['con_iva'],
                'estimado' => $b['estimado']
            ];

            $totales['neto_anio_previo'] += $b['neto_anio_previo'];
            $totales['neto_anio_anterior'] += $b['neto_anio_anterior'];
            $totales['con_iva'] += $b['con_iva'];
        }

        /* ---- 2. Control de facturacion ----------------------------------- */
        // Facturas y remitos por mes, sin apertura por canal.
        $control = [];

        foreach ($this->getHistoricoVentas($anioDesde) as $row) {
            $clave = sprintf('%04d-%02d', $row['ANIO'], $row['MES']);

            if (!isset($control[$clave])) {
                $control[$clave] = $this->filaControlVacia($row['ANIO'], $row['MES']);
            }

            $control[$clave]['facturas'] += $row['IMPORTE_NETO'];
        }

        foreach ($this->getRemitosControl($anioDesde) as $row) {
            $clave = sprintf('%04d-%02d', $row['ANIO'], $row['MES']);

            if (!isset($control[$clave])) {
                $control[$clave] = $this->filaControlVacia($row['ANIO'], $row['MES']);
            }

            $control[$clave]['remitos'] += $row['IMPORTE_NETO'];
        }

        ksort($control);

        $totalesControl = ['facturas' => 0, 'remitos' => 0, 'total' => 0];

        foreach ($control as $clave => $fila) {
            $control[$clave]['total'] = $fila['facturas'] + $fila['remitos'];
            $totalesControl['facturas'] += $fila['facturas'];
            $totalesControl['remitos'] += $fila['remitos'];
            $totalesControl['total'] += $control[$clave]['total'];
        }

        return [
            'anio_desde' => $anioDesde,
            'horizonte_meses' => $horizonteMeses,
            'alicuota_iva' => $alicuotaIva,
            'proyeccion' => $filas,
            'proyeccion_totales' => $totales,
            'facturacion' => array_values($control),
            'facturacion_totales' => $totalesControl
        ];
    }

    /**
     * Arma una fila vacia del bloque de control de facturacion
     * @param int $anio Anio de la fila
     * @param int $mes Mes de la fila
     * @return array Fila inicializada
     */
    private function filaControlVacia($anio, $mes) {
        return [
            'clave' => sprintf('%04d-%02d', $anio, $mes),
            'anio' => $anio,
            'mes' => $mes,
            'label' => self::$mesesAbrev[$mes] . '-' . substr((string)$anio, 2),
            'facturas' => 0,
            'remitos' => 0,
            'total' => 0
        ];
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors()
     * @param string $contexto Descripcion de la operacion que fallo
     * @return string Mensaje de error completo
     */
    private function errorSql($contexto) {
        $errors = sqlsrv_errors();
        $errorMsg = $contexto . ': ';

        if ($errors) {
            foreach ($errors as $error) {
                $errorMsg .= $error['message'] . ' ';
            }
        }

        return $errorMsg;
    }
}
