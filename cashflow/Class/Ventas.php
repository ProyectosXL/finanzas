<?php

require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/EjeVista.php';
require_once __DIR__ . '/Cotizacion.php';
require_once __DIR__ . '/Echeqs.php';

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
 * EL NETEO DE CHEQUES ADELANTADOS ES LA EXCEPCION a esa independencia, y va en
 * el otro sentido: hay clientes que entregan los echeqs ANTES de que se les
 * facture, asi que esa venta futura ya esta cobrada y no se puede proyectar de
 * nuevo. getNeteoPrechequeado() devuelve cuanto restar, por columna del eje y
 * por canal. Lo que hay que netear sale de Echeqs -> Venta Cobrada Anticipada.
 *
 * QUIEN LO CONSUME ES EL TABLERO, NO ESTA PANTALLA. El neteo es una fila propia
 * del cashflow -serie VENTAS.NETEO_PRECHEQUEADO, en negativo- y las series de
 * cobranza de este modulo salen BRUTAS. La pestana Ventas tambien muestra
 * cobranza bruta: si la restara en el pie, habria dos lugares que tienen que
 * dar lo mismo y ninguna garantia de que lo hagan.
 *
 * NINGUN VALOR DE NEGOCIO ESTA HARDCODEADO: alicuota, horizonte, feriados,
 * participaciones de respaldo, mix y plazos salen de las tablas de parametros.
 */
class Ventas {

    /* El eje temporal y los rotulos de mes viven en Horizonte, que es el mismo
       eje que consolida el Cashflow: ver Horizonte::labelMes(). */

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
     * Ultima fecha cargada en el historico diario.
     *
     * Es el dia de corte del bloque de tendencias. Se lee del dato y no se
     * calcula como "ayer" a proposito: el origen se actualiza de madrugada, asi
     * que en condiciones normales da ayer, pero si el job no corrio el bloque
     * tiene que decir hasta cuando llega de verdad en vez de comparar un mes
     * entero contra los pocos dias que si se cargaron.
     *
     * @return DateTime|null Ultima fecha con venta, o null si no hay historico
     */
    private function ultimaFechaHistoricoDia() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT MAX(FECHA) AS ULTIMA
                FROM RO_T_CASHFLOW_VENTAS_HIST_DIA
                WHERE TIPO_COMPROBANTE = ?";

        $stmt = sqlsrv_query($cid, $sql, ['FACTURA']);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historico diario de ventas'));
        }

        $ultima = null;
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($row && $row['ULTIMA'] instanceof DateTime) {
            $ultima = $row['ULTIMA'];
        }

        sqlsrv_free_stmt($stmt);

        return $ultima;
    }

    /**
     * Venta neta diaria de un rango, ya sumada sobre los cuatro canales.
     *
     * El bloque de tendencias no abre por canal, asi que se suma en la consulta
     * y el resto del calculo trabaja sobre una serie plana 'Y-m-d' => importe.
     *
     * @param string $desde Fecha inicial 'Y-m-d'
     * @param string $hasta Fecha final 'Y-m-d'
     * @return array Mapa 'Y-m-d' => importe neto del dia
     */
    private function serieDiariaHistorica($desde, $hasta) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT FECHA, SUM(IMPORTE_NETO) AS NETO
                FROM RO_T_CASHFLOW_VENTAS_HIST_DIA
                WHERE TIPO_COMPROBANTE = ?
                  AND FECHA BETWEEN ? AND ?
                GROUP BY FECHA
                ORDER BY FECHA";

        $stmt = sqlsrv_query($cid, $sql, ['FACTURA', $desde, $hasta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historico diario de ventas'));
        }

        $serie = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['FECHA'] instanceof DateTime) {
                $serie[$row['FECHA']->format('Y-m-d')] = floatval($row['NETO']);
            }
        }

        sqlsrv_free_stmt($stmt);

        return $serie;
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
     * @param string|null $modulo Modulo a filtrar
     * @return array Listado de parametros
     */
    public function getParametros($grupo = null, $modulo = null) {
        return $this->parametros->getParametros($grupo, $modulo);
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
     * QUE RESUELVE
     * Hay clientes que entregan los cheques ANTES de que se les facture. Esa
     * cobranza ya esta en la casa, asi que cuando este motor proyecta la cobranza
     * de la venta futura de ese cliente la estaria contando de nuevo. Lo que
     * devuelve este metodo es lo que hay que RESTAR para que no pase.
     *
     * EL ORIGEN
     * dbo.RO_V_CASHFLOW_VENTAS_PRECHEQ, que trae los cheques efectivamente
     * marcados en Echeqs -> Venta Cobrada Anticipada. Se crea con
     * sql/echeqs_prechequeado.sql. Reemplaza como origen a la tabla
     * RO_T_CASHFLOW_VENTAS_PRECHEQ, que queda sin uso.
     *
     * LA REGLA
     *     FECHA_TEORICA_FACTURA = FECHA_CHEQUE - dias del CLIENTE
     * y el importe cae en el bucket diario de esa fecha, o en el mensual si quedo
     * fuera del tramo diario. El reparto lo decide Horizonte::ubicar(), que es la
     * misma regla "dia O mes, nunca las dos" que usa todo el modulo.
     *
     * LOS DIAS SON POR CLIENTE Y NO HAY VALOR GLOBAL. Antes salian del parametro
     * 'dias_prechequeado', uno solo para todos: cada cliente negocia su propio
     * adelanto, asi que un unico numero obliga a elegir cual de todos queda bien
     * calculado. Ahora salen de RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE y los
     * resuelve Echeqs::diasDeCliente(), LA MISMA funcion que usa la sub-pestana:
     * si la pantalla y el neteo aplicaran plazos distintos, el tablero dejaria de
     * cerrar y no habria ninguna pantalla donde se notara. Un cliente en cero no
     * desplaza nada.
     *
     * LO QUE CAE ANTES DEL EJE SE DESCARTA, Y SE DESCARTA CALLADO. Con dias > 0
     * la fecha teorica puede quedar antes del inicio del eje: esa venta ya se
     * facturo y ya se cobro, asi que no esta en la cobranza proyectada y no hay
     * nada de que restarla. Ver repartirNeteo(), donde esta el razonamiento
     * completo y por que no va a 'fuera_horizonte'.
     *
     * NETEA TODO LO TILDADO, SIN MIRAR EL ESTADO DEL CHEQUE. Es una decision de
     * negocio: quien tilda es quien sabe si esa venta esta prepagada, y los
     * cheques pre-chequeados estan tipicamente en 'A' -ya aplicados- justamente
     * porque se recibieron y se usaron antes de facturar. Filtrar por ESTADO = 'C'
     * dejaria afuera casi todo el neteo. Lo unico que se excluye son 'X' y 'R',
     * anulado y rechazado, que no son plata: eso lo hace la vista origen.
     *
     * NO LANZA SI EL ORIGEN NO ESTA. Este metodo lo llama proyectarCobranzas(),
     * que dibuja la pestana Ventas entera: una vista que todavia no se creo tiene
     * que dejar el neteo en cero y avisar, no tumbar la pantalla.
     *
     * EL SIGNO: los importes se devuelven POSITIVOS. Quien consume es el que
     * resta o invierte el signo (VentasProvider, que arma la fila
     * NETEO_PRECHEQUEADO del tablero en negativo).
     *
     * @param array $dias Lista de fechas 'Y-m-d' del tramo diario
     * @param array $meses Lista de claves 'Y-m' del tramo mensual
     * @return array ['dias' => mapa, 'meses' => mapa, 'total' => float,
     *                'canales' => mapa canal => ['dias','meses'],
     *                'sin_canal' => float, 'fuera_de_cartera' => float]
     */
    public function getNeteoPrechequeado($dias = [], $meses = []) {
        $diasPorCliente = [];
        $filas = [];

        try {
            $echeqs = new Echeqs();

            // Los dos salen del mismo maestro y en la misma pasada: el plazo
            // que se aplica acá tiene que ser el que muestra la sub-pestaña.
            $diasPorCliente = $echeqs->getDiasPrechequeadoPorCliente();
            $filas = $echeqs->getPrechequeadoTotales();
        } catch (Throwable $e) {
            $this->warnings[] = 'No se pudo leer el neteo de cheques adelantados ('
                . $e->getMessage() . '). La cobranza se muestra sin netear.';

            return self::repartirNeteo([], $dias, $meses, []);
        }

        $neteo = self::repartirNeteo($filas, $dias, $meses, $diasPorCliente);

        foreach (self::avisosNeteo($neteo) as $aviso) {
            $this->warnings[] = $aviso;
        }

        return $neteo;
    }

    /**
     * Reparte los cheques marcados contra las columnas del eje. Es la regla del
     * neteo, sin base de datos.
     *
     * Va aparte y estatica porque lo unico delicado de este calculo es el
     * reparto, y sobre todo QUE SE DESCARTA: probarlo obligaria a tener cheques
     * cargados con fechas conocidas, que es justamente lo que no se puede pedir
     * de una tabla de Tango.
     *
     * LO QUE CAE ANTES DEL INICIO DEL EJE NO SE NETEA, Y SE DESCARTA CALLADO.
     * Es la excepcion deliberada a la regla "nunca se descarta en silencio" del
     * modulo, y el motivo es que aca no se descarta plata: ESA VENTA YA ESTA
     * COBRADA. Si la fecha teorica de venta quedo antes del eje, la factura ya
     * se emitio y el cheque ya entro; el motor de Ventas proyecta cobranza de
     * ventas FUTURAS, asi que esa venta no esta en ninguna columna de la
     * proyeccion y no hay nada de donde restarla. Un neteo sin contrapartida no
     * es plata que al tablero le falte: es plata que al tablero no le toca.
     *
     * El corte lo decide Echeqs::ventaYaCobrada(), que es LA MISMA funcion con
     * la que la sub-pestana Echeqs -> Venta Cobrada Anticipada decide que
     * cheques muestra. Una sola implementacion, dos llamadores: asi la pantalla
     * y el neteo no se pueden desalinear.
     *
     * Por eso NO va a 'fuera_horizonte' ni deja aviso. 'fuera_horizonte' tiene
     * un significado preciso en este modulo -cuanta plata el tablero DEBERIA
     * mostrar y no muestra, ver README-cashflow.md- y este importe no es eso.
     * Es el mismo criterio con el que CobElectronicos trata lo ya acreditado.
     * Avisarlo seria un aviso que aparece todos los dias, sobre algo que ya
     * paso y sobre lo que no hay ninguna accion posible, y un aviso permanente
     * tapa a los que si piden hacer algo. La sub-pestana Echeqs -> Venta
     * Cobrada Anticipada aplica esta misma regla y tampoco muestra esos cheques.
     *
     * EL CORTE ES CONTRA EL PRIMER DIA DEL EJE, no contra hoy escrito a mano: no
     * alcanza con preguntarle a Horizonte::ubicar() si encontro columna, porque
     * una fecha teorica de los primeros dias del mes EN CURSO cae en la columna
     * de ese mes, que existe en la serie pero no representa ningun dia futuro
     * -la pestana ni siquiera la dibuja-. Restar ahi seria hacer desaparecer el
     * importe en una columna que nadie ve.
     *
     * LO POSTERIOR AL HORIZONTE se descarta por el mismo motivo y de la misma
     * forma: si la venta cae mas alla del ultimo mes del eje, su cobranza
     * proyectada tampoco esta en el cuadro, asi que no hay columna que netear.
     *
     * LOS DIAS LLEGAN POR CLIENTE, en un mapa. Antes era un unico entero
     * aplicado a todas las filas. El mapa se resuelve con
     * Echeqs::diasDeCliente(), que es la misma funcion que usa la sub-pestana
     * para mostrar la fecha estimada de venta: si los dos aplicaran plazos
     * distintos, la pantalla mostraria una fecha y el tablero netearia en otra.
     *
     * @param array $filas Filas de Echeqs::getPrechequeadoTotales()
     * @param array $dias Lista de fechas 'Y-m-d' del tramo diario
     * @param array $meses Lista de claves 'Y-m' del tramo mensual
     * @param array $diasPorCliente Mapa codigo de cliente => dias
     * @return array
     */
    public static function repartirNeteo($filas, $dias, $meses, $diasPorCliente = []) {
        $neteo = [
            'dias' => [],
            'meses' => [],
            'total' => 0,
            'canales' => [],
            'fuera_de_cartera' => 0,
            'sin_canal' => 0
        ];

        foreach (is_array($dias) ? $dias : [] as $fecha) {
            $neteo['dias'][$fecha] = 0;
        }

        foreach (is_array($meses) ? $meses : [] as $clave) {
            $neteo['meses'][$clave] = 0;
        }

        // La apertura por canal arranca completa y en cero: una fila del tablero
        // que apunte a un canal sin cheques tiene que ver ceros, no una clave
        // ausente.
        foreach (Parametros::CANALES as $canal) {
            $neteo['canales'][$canal] = [
                'dias' => $neteo['dias'],
                'meses' => $neteo['meses']
            ];
        }

        // El primer dia del eje. Sin tramo diario -el Analisis de Ventas arma un
        // horizonte solo mensual- se cae a hoy, que es donde arranca el eje de
        // todos modos.
        $inicio = !empty($neteo['dias']) ? min(array_keys($neteo['dias'])) : date('Y-m-d');

        foreach (is_array($filas) ? $filas : [] as $fila) {
            $importe = floatval($fila['IMPORTE']);

            if ($importe == 0) {
                continue;
            }

            // La misma funcion que usa la sub-pestana para mostrar la fecha
            // estimada de venta. No hay respaldo global: un cliente en cero
            // deja el cheque en su propia fecha.
            $teorica = Echeqs::fechaVentaEstimada(
                $fila['FECHA_CHEQUE'],
                Echeqs::diasDeCliente($diasPorCliente, $fila['COD_CLIENTE']));

            // LA MISMA funcion que decide que cheques muestra la sub-pestana
            // Echeqs -> Venta Cobrada Anticipada. Es una sola por diseno: si
            // fueran dos implementaciones, la pantalla podria mostrar un cheque
            // que el tablero no netea -o al reves- y el usuario tildaria algo
            // que no mueve nada, sin ninguna pantalla donde notarlo.
            //
            // El corte que se le pasa es el PRIMER DIA DEL EJE y no 'hoy': en
            // la practica son el mismo dia, pero el neteo tiene que cortar
            // contra el eje que efectivamente recibio.
            $destino = Echeqs::ventaYaCobrada($teorica, $inicio)
                ? null
                : Horizonte::ubicar($neteo, $teorica);

            // Fuera del eje no hay nada que netear: esa venta ya se cobro (si
            // quedo atras) o su cobranza proyectada tampoco esta en el cuadro
            // (si quedo adelante). Se descarta sin avisar, a proposito.
            if ($destino === null) {
                continue;
            }

            $neteo[$destino[0]][$destino[1]] += $importe;
            $neteo['total'] += $importe;

            // El canal sale del prefijo del codigo de cliente. Sin esto el neteo
            // solo se podria restar del total, y la fila total del tablero
            // dejaria de reconciliar con su apertura por canal.
            $canal = Echeqs::canalDeCliente($fila['COD_CLIENTE']);

            if ($canal !== null && isset($neteo['canales'][$canal])) {
                $neteo['canales'][$canal][$destino[0]][$destino[1]] += $importe;
            } else {
                $neteo['sin_canal'] += $importe;
            }

            if ($fila['ESTADO'] !== Echeqs::ESTADO_CARTERA) {
                $neteo['fuera_de_cartera'] += $importe;
            }
        }

        return $neteo;
    }

    /**
     * Los avisos del neteo. Describen plata por la que el cuadro no cierra, y un
     * cuadro que no cierra sin decirlo es peor que uno que falla.
     *
     * NO SE AVISA POR EL ESTADO DEL CHEQUE, y es una decision de negocio tomada:
     * netea lo que este TILDADO, sin importar si el cheque sigue en cartera o ya
     * se aplico. Quien tilda es quien sabe si esa venta esta prepagada, y esa es
     * exactamente la funcion de la sub-pestana. Un aviso por cada cheque
     * aplicado seria ruido permanente sobre el caso normal -los pre-chequeados
     * estan tipicamente en 'A'-, y un aviso que aparece siempre deja de leerse.
     *
     * El dato sigue estando: 'fuera_de_cartera' viaja en el neteo y el pie de la
     * sub-pestana muestra los marcados abiertos por estado, que es donde se
     * decide que tildar.
     *
     * TAMPOCO SE AVISA POR LO QUE CAE FUERA DEL EJE. Habia un aviso por eso y se
     * saco: ese importe es venta YA COBRADA, no plata que al tablero le falte
     * mostrar, asi que no hay ninguna accion detras del aviso. El razonamiento
     * completo esta en repartirNeteo(). El de 'sin_canal' se queda porque ahi el
     * cuadro efectivamente no cierra.
     *
     * @param array $neteo Resultado de repartirNeteo()
     * @return array
     */
    public static function avisosNeteo($neteo) {
        $avisos = [];

        if ($neteo['sin_canal'] > 0) {
            $avisos[] = 'Neteo de cheques adelantados: $ '
                . number_format($neteo['sin_canal'], 2, ',', '.') . ' no se pudieron imputar a '
                . 'ningún canal y sólo se restan del total. La fila total de cobranza y su '
                . 'apertura por canal no reconcilian por ese importe.';
        }

        return $avisos;
    }

    /* ====================================================================
       MOTOR DE PROYECCION
       ==================================================================== */

    /**
     * Grilla de venta proyectada: 28 dias + 12 meses.
     *
     * @param Horizonte|null $horizonte Eje a usar; null lo arma de parametros
     * @return array Grilla resuelta lista para el front
     */
    public function proyectarVentas($horizonte = null) {
        return $this->calcular(false, $horizonte);
    }

    /**
     * Grilla de cobranza proyectada: aplica mix, plazos y corrimiento a dia
     * bancario habil sobre la venta diaria.
     *
     * @param Horizonte|null $horizonte Eje a usar; null lo arma de parametros
     * @return array Grilla resuelta lista para el front
     */
    public function proyectarCobranzas($horizonte = null) {
        return $this->calcular(true, $horizonte);
    }

    /**
     * Motor completo. El calculo pesado vive aca, en PHP: el front recibe la
     * grilla ya resuelta.
     *
     * @param bool $conCobranza Si true tambien resuelve la cobranza
     * @param Horizonte|null $horizonte Eje a usar. La pestana Ventas no lo pasa
     *        y se arma de parametros; el Cashflow SI lo pasa, para que la serie
     *        de Ventas caiga exactamente en las mismas columnas sobre las que
     *        el resto del tablero consolida.
     * @return array Estructura completa de la proyeccion
     */
    private function calcular($conCobranza, $horizonte = null) {
        $this->warnings = [];

        /* ---- 1. Parametros ------------------------------------------------ */
        $map = $this->parametros->getParametrosMap();

        $horizonteDias  = Parametros::ent($map, 'horizonte_dias');
        $horizonteMeses = Parametros::ent($map, 'horizonte_meses');
        $alicuotaIva    = Parametros::num($map, 'alicuota_iva');
        $feriadosMMDD   = $this->parametros->getFeriadosComercio($map);
        $respaldo       = $this->parametros->getParticipacionRespaldo($map);

        /* ---- 2. Ejes temporales ------------------------------------------ */
        // El eje lo arma Horizonte, que es exactamente el mismo eje sobre el
        // que consolida el Cashflow. Tener dos implementaciones las haria
        // desincronizarse. Ademas resuelve el dia de referencia UNA sola vez
        // para las dos ramas del eje.
        if ($horizonte instanceof Horizonte) {
            // Eje inyectado por el Cashflow: manda el suyo, para que la serie
            // caiga en las mismas columnas que el resto del tablero. Sin esto,
            // un pedido que cruzara la medianoche armaria dos ejes corridos un
            // dia entre si.
            $horizonteDias  = $horizonte->cantidadDias();
            $horizonteMeses = $horizonte->cantidadMeses();
        } else {
            if ($horizonteDias < 1 || $horizonteMeses < 1) {
                throw new Exception('El horizonte de proyeccion debe ser mayor a cero');
            }

            $horizonte = new Horizonte($horizonteDias, $horizonteMeses, $feriadosMMDD);
        }

        $hoy     = new DateTime($horizonte->hoy());
        $dias    = $horizonte->dias();
        $diasSet = $horizonte->diasSet();
        $meses   = $horizonte->meses();

        // Ventana de generacion de venta: desde hoy hasta el fin del eje
        $ventanaFin = $horizonte->fin();

        /* ---- 3. Historico y ediciones ------------------------------------- */
        $hist = $this->historicoIndexado();
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
            // Las tres vistas del eje, con sus columnas y su rotulo de periodo.
            // Salen de EjeVista, el mismo criterio que el tablero y el resto de
            // las pestanas: asi la grilla de proyeccion no puede ofrecer vistas
            // distintas ni medir un periodo distinto del que enuncia.
            'vistas' => EjeVista::vistas($horizonte),
            'secuencia' => $horizonte->secuencia(),
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
     * Base mensual de la proyeccion, sin apertura por canal:
     *
     *   neto_anio_anterior = venta neta real del MISMO MES del anio anterior
     *   neto_proyectado    = neto_anio_anterior * (1 + indice)
     *   con_iva            = neto_proyectado * (1 + alicuota_iva)
     *
     * Es la unica implementacion de la formula: la usan tanto la grilla de
     * Proyeccion como la tabla de Analisis de Ventas.
     *
     * @param array $meses Eje devuelto por Horizonte::meses()
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

            $totalAnt = self::totalMes($hist, $anioAnt, $m['mes']);
            $totalPrev = self::totalMes($hist, $anioPrev, $m['mes']);

            $indice = isset($indices[$clave]) ? $indices[$clave] : 0;
            $netoProy = $totalAnt * (1 + $indice);

            $base[$clave] = [
                'clave' => $clave,
                'anio' => $m['anio'],
                'mes' => $m['mes'],
                'label' => $m['label'],
                'anio_anterior' => $anioAnt,
                'label_anio_anterior' => Horizonte::labelMes($anioAnt, $m['mes']),
                'neto_anio_anterior' => $totalAnt,
                'anio_previo' => $anioPrev,
                'label_anio_previo' => Horizonte::labelMes($anioPrev, $m['mes']),
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
    private static function totalMes($hist, $anio, $mes) {
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
        $hist = $this->historicoIndexado();
        $indices = [];

        foreach ($this->getIndices() as $row) {
            $indices[sprintf('%04d-%02d', $row['ANIO'], $row['MES'])] = $row['INDICE'];
        }

        /* ---- 1. Proyeccion por mes --------------------------------------- */
        // Analisis es solo mensual: se pide el eje con el tramo diario en cero
        // para no exigir 'horizonte_dias', que esta pantalla no usa.
        $meses = (new Horizonte(0, $horizonteMeses, $feriadosMMDD))->meses();
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

        /* ---- 3. Tendencia de los ultimos meses --------------------------- */
        // Viaja en el mismo payload para que el front siga haciendo una sola
        // llamada. Se aisla en un try porque se apoya en una tabla nueva: si el
        // SP RO_SP_CASHFLOW_VENTAS_HIST_DIA todavia no corrio en el entorno, el
        // bloque sale vacio pero la pantalla, que ya funcionaba, no se cae.
        try {
            $tendencias = $this->getTendencias();
        } catch (Exception $e) {
            $tendencias = self::tendenciasVacias();
            $this->warnings[] = 'No se pudo leer el historico diario de ventas: ' . $e->getMessage();
        }

        return [
            'anio_desde' => $anioDesde,
            'horizonte_meses' => $horizonteMeses,
            'alicuota_iva' => $alicuotaIva,
            'proyeccion' => $filas,
            'proyeccion_totales' => $totales,
            'facturacion' => array_values($control),
            'facturacion_totales' => $totalesControl,
            'tendencias' => $tendencias['filas'],
            'tendencias_totales' => $tendencias['totales'],
            'tendencias_dia_corte' => $tendencias['dia_corte']
        ];
    }

    /**
     * Tendencia de los ultimos meses cerrados contra el mismo mes del anio
     * anterior, sin apertura por canal.
     *
     * POR QUE NECESITA EL HISTORICO DIARIO
     * El mes en curso esta incompleto. Contra un mes entero del anio anterior
     * la variacion sale siempre hundida, porque enfrenta los dias transcurridos
     * contra treinta. Con RO_T_CASHFLOW_VENTAS_HIST_DIA el anio anterior se
     * recorta a los MISMOS dias y la comparacion es pareja.
     *
     * La ventana se ancla en el mes de la ultima fecha cargada, no en el mes de
     * hoy: asi toda fila que se muestra tiene dato. El 1 de mes, cuando el corte
     * todavia cae en el mes anterior, salen seis meses cerrados y ninguna fila
     * parcial.
     *
     * Los importes son NETOS sin IVA, igual que las columnas historicas de la
     * tabla de proyeccion.
     *
     * @param int $cantidadMeses Cantidad de meses a mostrar
     * @return array dia_corte, filas y totales
     */
    public function getTendencias($cantidadMeses = 6) {
        $cantidadMeses = max(1, intval($cantidadMeses));
        $corte = $this->ultimaFechaHistoricoDia();

        if ($corte === null) {
            return self::tendenciasVacias();
        }

        // Se lee desde el dia 1 del mes mas viejo mostrado MENOS UN ANIO, que es
        // lo que necesita la comparacion interanual, y hasta el corte.
        $desde = new DateTime($corte->format('Y-m-01'));
        $desde->modify('-' . ($cantidadMeses - 1) . ' month');
        $desde->modify('-1 year');

        $serie = $this->serieDiariaHistorica(
            $desde->format('Y-m-d'),
            $corte->format('Y-m-d')
        );

        return self::armarTendencias($serie, $cantidadMeses, $corte->format('Y-m-d'));
    }

    /**
     * Arma el bloque de tendencias a partir de una serie diaria plana.
     *
     * Separado de la lectura para poder verificarlo sin base: el recorte del mes
     * parcial y el criterio de "sin dato" son justamente lo delicado.
     *
     * Un mes CERRADO se compara contra el mes completo del anio anterior. El mes
     * PARCIAL se compara contra los dias 1..corte de ese mismo mes del anio
     * anterior, acotando el corte a los dias que ese mes realmente tuvo: un
     * corte el 31 de marzo contra febrero pediria un dia inexistente.
     *
     * La variacion es null -no cero- cuando el anio anterior no es positivo. Es
     * el mismo criterio de baseMensual(), y es lo que hace que el front pinte un
     * guion en lugar de un 0% que se leeria como "no cambio".
     *
     * @param array $serieDia Mapa 'Y-m-d' => importe neto del dia
     * @param int $cantidadMeses Cantidad de meses a mostrar
     * @param string $fechaCorte Ultima fecha con dato, 'Y-m-d'
     * @return array dia_corte, filas y totales
     */
    public static function armarTendencias($serieDia, $cantidadMeses, $fechaCorte) {
        $corte = new DateTime($fechaCorte);
        $diaCorte = intval($corte->format('j'));

        $ancla = new DateTime($corte->format('Y-m-01'));
        $primero = clone $ancla;
        $primero->modify('-' . ($cantidadMeses - 1) . ' month');

        $filas = [];
        $totales = ['neto' => 0, 'neto_anio_anterior' => 0, 'variacion' => null];

        for ($i = 0; $i < $cantidadMeses; $i++) {
            $ref = clone $primero;
            $ref->modify("+$i month");

            $anio = intval($ref->format('Y'));
            $mes = intval($ref->format('n'));
            $diasDelMes = intval($ref->format('t'));

            // Parcial solo el mes del corte, y solo si el corte no llego al
            // ultimo dia: un corte el 31 cierra el mes.
            $esAncla = ($ref->format('Y-m') === $ancla->format('Y-m'));
            $parcial = ($esAncla && $diaCorte < $diasDelMes);
            $hasta = $parcial ? $diaCorte : $diasDelMes;

            $anioAnt = $anio - 1;
            $diasMesAnt = intval(
                (new DateTime(sprintf('%04d-%02d-01', $anioAnt, $mes)))->format('t')
            );
            $hastaAnt = $parcial ? min($hasta, $diasMesAnt) : $diasMesAnt;

            $neto = self::sumarDias($serieDia, $anio, $mes, $hasta);
            $netoAnt = self::sumarDias($serieDia, $anioAnt, $mes, $hastaAnt);

            $filas[] = [
                'clave' => sprintf('%04d-%02d', $anio, $mes),
                'anio' => $anio,
                'mes' => $mes,
                'label' => Horizonte::labelMes($anio, $mes),
                'neto' => $neto,
                'anio_anterior' => $anioAnt,
                'label_anio_anterior' => Horizonte::labelMes($anioAnt, $mes),
                'neto_anio_anterior' => $netoAnt,
                'variacion' => ($netoAnt > 0) ? (($neto / $netoAnt) - 1) : null,
                'parcial' => $parcial,
                // Dias efectivamente comparados de cada lado. En un mes parcial
                // son los que explican por que el importe es mas chico.
                'dias' => $hasta,
                'dias_anio_anterior' => $hastaAnt
            ];

            $totales['neto'] += $neto;
            $totales['neto_anio_anterior'] += $netoAnt;
        }

        if ($totales['neto_anio_anterior'] > 0) {
            $totales['variacion'] = ($totales['neto'] / $totales['neto_anio_anterior']) - 1;
        }

        return [
            'dia_corte' => $corte->format('Y-m-d'),
            'filas' => $filas,
            'totales' => $totales
        ];
    }

    /**
     * Suma la serie diaria del dia 1 al dia $hasta de un mes
     * @param array $serieDia Mapa 'Y-m-d' => importe
     * @param int $anio Anio a sumar
     * @param int $mes Mes a sumar
     * @param int $hasta Ultimo dia inclusive
     * @return float Total del tramo
     */
    private static function sumarDias($serieDia, $anio, $mes, $hasta) {
        $total = 0;

        for ($d = 1; $d <= $hasta; $d++) {
            $clave = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $total += isset($serieDia[$clave]) ? $serieDia[$clave] : 0;
        }

        return $total;
    }

    /** @return array Bloque de tendencias sin datos */
    private static function tendenciasVacias() {
        return [
            'dia_corte' => null,
            'filas' => [],
            'totales' => ['neto' => 0, 'neto_anio_anterior' => 0, 'variacion' => null]
        ];
    }

    /* ====================================================================
       VENTA ACUMULADA (anio calendario, real, neta, pesos y dolares)
       ==================================================================== */

    /**
     * Venta REAL acumulada del anio calendario, NETA SIN IVA, en pesos y en
     * dolares.
     *
     * DE DONDE SALE CADA MES
     * ----------------------
     *   meses cerrados -> RO_T_CASHFLOW_VENTAS_HIST, solo FACTURA. Los remitos
     *                     no entran, igual que en toda la proyeccion.
     *   mes en curso   -> RO_T_CASHFLOW_VENTAS_HIST_DIA recortado a la ultima
     *                     fecha cargada, porque en la tabla mensual esta
     *                     incompleto. La fila lo dice con el badge 'parcial'.
     *
     * Igual que el bloque de tendencias, el mes en curso se muestra SOLO si el
     * historico diario llego a el: si el corte todavia cae en el mes anterior,
     * la fila no se dibuja en vez de mostrar un cero que se leeria como "no
     * vendimos nada".
     *
     * LOS DOLARES
     * -----------
     * Cada mes se valua a SU propio tipo de cambio de cierre y el acumulado en
     * dolares es la SUMA de los meses ya valuados. No es el acumulado en pesos
     * dividido por un tipo de cambio: eso seria reexpresar la serie a moneda de
     * hoy, otra cuenta. Un mes sin cotizacion queda en null -no en cero- y no
     * corta el acumulado de los meses que si la tienen.
     *
     * @param int|null $anio Anio calendario; null = el anio en curso
     * @return array Estructura para el front
     */
    public function getVentaAcumulada($anio = null) {
        $this->warnings = [];

        $hoy = new DateTime('today');
        $anioActual = intval($hoy->format('Y'));
        $anio = ($anio === null) ? $anioActual : intval($anio);

        if ($anio > $anioActual) {
            throw new Exception('El anio pedido todavia no empezo: ' . $anio);
        }

        /* ---- 1. Meses cerrados: tabla mensual ---------------------------- */
        $hist = $this->historicoIndexado($anio);
        $netoMensual = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $netoMensual[$mes] = self::totalMes($hist, $anio, $mes);
        }

        /* ---- 2. Mes en curso: historico diario recortado al corte -------- */
        // Un anio ya cerrado no tiene mes en curso: sale entero de la mensual.
        $mesActual = ($anio === $anioActual) ? intval($hoy->format('n')) : null;
        $mesParcial = null;
        $fechaCorte = null;
        $serieDia = [];

        if ($mesActual !== null) {
            // Se aisla en un try por la misma razon que getTendencias(): se
            // apoya en la tabla diaria, que es nueva. Si no esta, la pantalla
            // muestra los meses cerrados y avisa.
            try {
                $corte = $this->ultimaFechaHistoricoDia();

                if ($corte !== null && $corte->format('Y-m') === $hoy->format('Y-m')) {
                    $mesParcial = $mesActual;
                    $fechaCorte = $corte->format('Y-m-d');
                    $serieDia = $this->serieDiariaHistorica(
                        $hoy->format('Y-m-01'),
                        $fechaCorte
                    );
                }
            } catch (Exception $e) {
                $this->warnings[] = 'No se pudo leer el historico diario de ventas: '
                    . $e->getMessage() . ' El mes en curso no se muestra.';
            }
        }

        $mesHasta = ($mesActual === null)
            ? 12
            : ($mesParcial === null ? $mesActual - 1 : $mesActual);

        /* ---- 3. Tipo de cambio de cierre de cada mes --------------------- */
        // Mismo criterio que el bloque de tendencias con la tabla diaria: si la
        // vista todavia no existe en el entorno, la pantalla sale sin la parte
        // en dolares y lo avisa, en vez de caerse.
        $tc = [];

        if ($mesHasta >= 1) {
            try {
                $tc = (new Cotizacion())->mapaMensual(
                    Cotizacion::clave($anio, 1),
                    Cotizacion::clave($anio, $mesHasta)
                );

                if (count($tc) === 0) {
                    $this->warnings[] = 'La vista ' . Cotizacion::VISTA
                        . ' no tiene cotizaciones para ' . $anio
                        . '. La tabla sale sin la parte en dolares.';
                }
            } catch (Exception $e) {
                $this->warnings[] = 'No se pudo leer el tipo de cambio: '
                    . $e->getMessage() . ' La tabla sale sin la parte en dolares.';
            }
        }

        $armado = self::armarVentaAcumulada(
            $anio, $mesHasta, $netoMensual, $serieDia, $mesParcial, $fechaCorte, $tc
        );

        $armado['warnings'] = $this->warnings;

        return $armado;
    }

    /**
     * Arma la tabla de venta acumulada a partir de datos ya leidos.
     *
     * Separado de la lectura para poder verificarlo sin base, igual que
     * armarTendencias(): lo delicado es justamente la valuacion mes a mes y el
     * tratamiento de un mes sin cotizacion.
     *
     * @param int $anio Anio calendario
     * @param int $mesHasta Ultimo mes a mostrar (0 = ninguno)
     * @param array $netoMensual Mapa mes(int) => neto real del mes cerrado
     * @param array $serieDia Mapa 'Y-m-d' => neto del dia, para el mes en curso
     * @param int|null $mesParcial Mes que sale de la serie diaria, o null
     * @param string|null $fechaCorte Ultima fecha con dato diario, 'Y-m-d'
     * @param array $tc Mapa 'YYYY-MM' => tipo de cambio de cierre
     * @return array Estructura para el front
     */
    public static function armarVentaAcumulada(
        $anio, $mesHasta, $netoMensual, $serieDia, $mesParcial, $fechaCorte, $tc
    ) {
        $anio = intval($anio);
        $mesHasta = max(0, min(12, intval($mesHasta)));
        $mesParcial = ($mesParcial === null) ? null : intval($mesParcial);

        $diaCorte = ($fechaCorte === null)
            ? 0
            : intval((new DateTime($fechaCorte))->format('j'));

        $filas = [];
        $acum = 0;
        $acumUsd = 0;
        $valuados = 0;
        $sinTc = 0;

        for ($mes = 1; $mes <= $mesHasta; $mes++) {
            $clave = Cotizacion::clave($anio, $mes);
            $parcial = ($mesParcial !== null && $mesParcial === $mes);

            // El mes en curso sale de la serie diaria recortada al corte; los
            // cerrados, de la tabla mensual.
            $neto = $parcial
                ? self::sumarDias($serieDia, $anio, $mes, $diaCorte)
                : (isset($netoMensual[$mes]) ? floatval($netoMensual[$mes]) : 0);

            $acum += $neto;

            $tcMes = (isset($tc[$clave]) && floatval($tc[$clave]) > 0)
                ? floatval($tc[$clave])
                : null;

            // Cada mes a SU tipo de cambio, y el acumulado en dolares es la suma
            // de los meses valuados. Un mes sin cotizacion queda en null y el
            // acumulado sigue con los que si tienen.
            $netoUsd = ($tcMes === null) ? null : ($neto / $tcMes);

            if ($netoUsd === null) {
                $sinTc++;
            } else {
                $acumUsd += $netoUsd;
                $valuados++;
            }

            $filas[] = [
                'clave' => $clave,
                'anio' => $anio,
                'mes' => $mes,
                'label' => Horizonte::labelMes($anio, $mes),
                'neto' => $neto,
                'acumulado' => $acum,
                'tc' => $tcMes,
                'neto_usd' => $netoUsd,
                // Antes del primer mes valuado el acumulado en dolares no es
                // cero: no hay dato. Un cero se leeria como "no vendimos nada".
                'acumulado_usd' => ($valuados > 0) ? $acumUsd : null,
                'parcial' => $parcial,
                'dias' => $parcial ? $diaCorte : 0
            ];
        }

        return [
            'anio' => $anio,
            'mes_hasta' => $mesHasta,
            'dia_corte' => $fechaCorte,
            'filas' => $filas,
            'totales' => [
                'neto' => $acum,
                'neto_usd' => ($valuados > 0) ? $acumUsd : null,
                'meses' => $mesHasta,
                'meses_sin_tc' => $sinTc
            ],
            // Sin ninguna cotizacion la pantalla se dibuja sin las columnas de
            // dolares en vez de mostrar una columna entera de guiones.
            'usd_disponible' => ($valuados > 0)
        ];
    }

    /* ====================================================================
       VENTA BALANCE (1/8 al 31/7, real + proyectado, con IVA)
       ==================================================================== */

    /**
     * Venta del anio balance en curso -1/8 al 31/7-, doce meses de agosto a
     * julio, TODO CON IVA.
     *
     *   meses cerrados        -> venta real neta * (1 + alicuota_iva), 'REAL'
     *   mes en curso y los
     *   que siguen            -> venta proyectada de baseMensual(), que ya
     *                            viene con IVA, 'PROYECTADO'
     *
     * POR QUE TODO CON IVA
     * Es lo que hace sumables las dos mitades. Mezclar un tramo neto con un
     * tramo con IVA daria un total que no es ninguna de las dos cosas.
     *
     * POR QUE EL EJE NO USA 'horizonte_meses'
     * Ese parametro es editable: si alguien lo baja a 6, el balance saldria
     * cortado a la mitad. El eje se ancla al inicio del balance y son siempre
     * doce meses. La venta proyectada igual sale de baseMensual(), el MISMO
     * helper que usa la pestana Cashflow, asi que las dos no se pueden
     * desincronizar.
     *
     * @return array Estructura para el front
     */
    public function getVentaBalance() {
        $this->warnings = [];

        $map = $this->parametros->getParametrosMap();
        $alicuotaIva = Parametros::num($map, 'alicuota_iva');
        $feriadosMMDD = $this->parametros->getFeriadosComercio($map);

        $hoy = new DateTime('today');
        $inicio = self::inicioBalance($hoy);

        // Doce meses fijos anclados al 1/8 del balance en curso.
        $horizonte = new Horizonte(0, 12, $feriadosMMDD, new DateTime($inicio));
        $meses = $horizonte->meses();

        $hist = $this->historicoIndexado();
        $indices = [];

        foreach ($this->getIndices() as $row) {
            $indices[Cotizacion::clave($row['ANIO'], $row['MES'])] = $row['INDICE'];
        }

        $base = $this->baseMensual($meses, $hist, $indices, $alicuotaIva, $feriadosMMDD);
        $netoReal = [];

        foreach ($meses as $m) {
            $netoReal[$m['clave']] = self::totalMes($hist, $m['anio'], $m['mes']);
        }

        $armado = self::armarVentaBalance(
            $meses, $base, $netoReal, $alicuotaIva, $hoy->format('Y-m')
        );

        $armado['alicuota_iva'] = $alicuotaIva;
        $armado['generado'] = $hoy->format('Y-m-d');
        $armado['warnings'] = $this->warnings;

        return $armado;
    }

    /**
     * Primer dia del anio balance en curso.
     *
     * El balance corre del 1/8 al 31/7. En agosto o despues, el balance en
     * curso arranco el 1/8 de este anio; antes de agosto, el 1/8 del anterior.
     *
     * @param DateTime $hoy Dia de referencia
     * @return string '1 de agosto' del anio que corresponda, 'Y-m-d'
     */
    public static function inicioBalance($hoy) {
        $anio = intval($hoy->format('Y'));
        $mes = intval($hoy->format('n'));

        return sprintf('%04d-08-01', ($mes >= 8) ? $anio : ($anio - 1));
    }

    /**
     * Arma la tabla del balance a partir de datos ya leidos.
     *
     * Separado de la lectura para poder verificarlo sin base: el corte entre
     * real y proyectado es lo que decide el total del balance.
     *
     * @param array $meses Eje devuelto por Horizonte::meses(), agosto a julio
     * @param array $base Base mensual devuelta por baseMensual(), con IVA
     * @param array $netoReal Mapa 'Y-m' => venta real NETA del mes
     * @param float $alicuotaIva Alicuota para grosar la parte real
     * @param string $mesActualClave Mes en curso, 'Y-m'
     * @return array Estructura para el front
     */
    public static function armarVentaBalance($meses, $base, $netoReal, $alicuotaIva, $mesActualClave) {
        $alicuotaIva = floatval($alicuotaIva);

        $filas = [];
        $acum = 0;
        $totales = [
            'venta' => 0,
            'real' => 0,
            'proyectado' => 0,
            'meses_real' => 0,
            'meses_proyectado' => 0
        ];

        foreach ($meses as $m) {
            $clave = $m['clave'];

            // MES CERRADO = estrictamente anterior al mes actual. El mes en
            // curso va SIEMPRE proyectado, aunque el historico ya tenga venta
            // cargada: un mes a medio facturar sumado contra once meses
            // completos hunde el total del balance y nadie lo nota.
            // Las claves son 'Y-m', asi que la comparacion de strings ordena
            // igual que las fechas.
            $cerrado = ($clave < $mesActualClave);

            if ($cerrado) {
                // La venta real viene NETA: se la grosa con la alicuota que
                // llega por parametro para que sea sumable con la proyectada.
                $neto = isset($netoReal[$clave]) ? floatval($netoReal[$clave]) : 0;
                $venta = $neto * (1 + $alicuotaIva);
                $origen = 'REAL';
                $estimado = false;
            } else {
                // baseMensual() ya devuelve el mes con IVA.
                $neto = isset($base[$clave]) ? floatval($base[$clave]['neto_proyectado']) : 0;
                $venta = isset($base[$clave]) ? floatval($base[$clave]['con_iva']) : 0;
                $origen = 'PROYECTADO';
                $estimado = isset($base[$clave]) ? (bool) $base[$clave]['estimado'] : false;
            }

            $acum += $venta;

            $filas[] = [
                'clave' => $clave,
                'anio' => $m['anio'],
                'mes' => $m['mes'],
                'label' => $m['label'],
                'origen' => $origen,
                'neto' => $neto,
                'venta' => $venta,
                'acumulado' => $acum,
                'estimado' => $estimado
            ];

            $totales['venta'] += $venta;

            if ($cerrado) {
                $totales['real'] += $venta;
                $totales['meses_real']++;
            } else {
                $totales['proyectado'] += $venta;
                $totales['meses_proyectado']++;
            }
        }

        $hayMeses = (count($meses) > 0);
        $ultimo = $hayMeses ? $meses[count($meses) - 1] : null;

        return [
            'inicio' => $hayMeses ? ($meses[0]['clave'] . '-01') : null,
            'fin' => $hayMeses
                ? (new DateTime($ultimo['clave'] . '-01'))
                    ->modify('last day of this month')
                    ->format('Y-m-d')
                : null,
            'mes_actual' => $mesActualClave,
            'filas' => $filas,
            'totales' => $totales
        ];
    }

    /**
     * Historico de facturas indexado [anio][mes][canal] => importe neto.
     * Es la forma en la que lo consumen baseMensual() y totalMes().
     *
     * @param int|null $anioDesde Anio minimo a leer, o null para todo
     * @return array Historico indexado
     */
    private function historicoIndexado($anioDesde = null) {
        $hist = [];

        foreach ($this->getHistoricoVentas($anioDesde) as $row) {
            $hist[$row['ANIO']][$row['MES']][$row['CANAL']] = $row['IMPORTE_NETO'];
        }

        return $hist;
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
            'label' => Horizonte::labelMes($anio, $mes),
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
