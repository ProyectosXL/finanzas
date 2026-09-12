<?php

// Horizonte::normalizarFecha() es la unica implementacion del modulo para
// llevar a 'Y-m-d' lo que devuelve sqlsrv, que para una columna DATE entrega un
// DateTime y no un string.
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Cotizacion.php';

/**
 * Ingresos
 * Cobranza de franquicias (real y proyectada), de mayoristas y de las
 * exportaciones a Tasky.
 *
 * EXPORTACIONES TASKY
 * -------------------
 * Tasky es la razon social del grupo en Uruguay: mismo grupo, otra empresa.
 * Se le factura en DOLARES y sus facturas pendientes de GVA12 son cobranza a
 * proyectar. El importe que vale es IMPORTE_EX (dolares); COTIZ e IMPORTE son
 * la cotizacion y el importe en pesos AL MOMENTO DE FACTURAR, que se muestran
 * como referencia historica y NO entran en ningun calculo. Todas las facturas
 * se valuan a dolar de HOY: ver getExportacionesTasky() y el encabezado de
 * Providers/ExportacionesProvider.php.
 *
 * LA INVARIANTE ENTRE REAL Y PROYECTADO
 * -------------------------------------
 * Las dos pestanas de Cobranzas FR se reparten el mismo universo de facturas y
 * la particion tiene que ser EXACTA: cada comprobante esta en una o en la otra,
 * nunca en las dos y nunca en ninguna.
 *
 *   Real a Cobrar        -> comprobantes de propuestas en estado 'ACEPTADA'
 *   Pendientes Proyectados -> todo lo demas, o sea las facturas PEN de Tango
 *                             MENOS las que ya cuenta Real ('ACEPTADA') y
 *                             menos las ya cobradas ('PAGADO')
 *
 * Los estados reales de FP_propuestas_pago son exactamente tres: 'ACEPTADA',
 * 'PAGADO' y 'PENDIENTE_APROBACION_CLIENTE'. Una factura en una propuesta que
 * todavia espera la aprobacion del cliente NO es cobranza comprometida, asi que
 * no la cuenta Real; y por eso mismo TIENE que volver a la proyeccion. Si las
 * dos consultas no son complementarias, esas facturas desaparecen de las dos
 * pestanas y la plata se evapora en silencio.
 *
 * La invariante vive repartida entre getCobranzasFR() / getCobranzasFRTotales()
 * (lo que cuenta Real) y getCobranzasFRPendientesProyectadas() (lo que excluye
 * la proyeccion). Estan comentadas de los dos lados a proposito: son dos
 * consultas separadas que se tienen que mover juntas.
 *
 * getPPPClientes() usa 'PAGADO' y no participa de esto: es el historico con el
 * que se calcula el plazo promedio, no el universo a cobrar.
 */
class Ingresos {

    /**
     * Estados de propuesta que cuenta la cobranza REAL.
     * El complemento de esta lista es lo que vuelve a la proyeccion; ver
     * $estadosYaContados y el encabezado de la clase.
     */
    const ESTADOS_REAL = ['ACEPTADA'];

    /**
     * Estados de propuesta cuyos comprobantes NO tienen que volver a la
     * proyeccion: 'ACEPTADA' porque ya la cuenta Real, 'PAGADO' porque ya se
     * cobro. Es el complemento exacto de ESTADOS_REAL mas lo ya cobrado.
     */
    const ESTADOS_YA_CONTADOS = ['ACEPTADA', 'PAGADO'];

    /**
     * Codigos de cliente de GVA12 cuyas facturas son exportaciones. Hoy es
     * uno solo, Tasky; si manana aparece otra exportadora, se agrega aca y no
     * dentro del SQL.
     */
    const CLIENTES_EXPORTACION = ['EXTASK'];

    /**
     * Hasta cuantos dias para atras entra una factura cuya fecha probable de
     * cobro ya paso. Ver ubicarCobroVencido(), que es donde se aplica.
     *
     * El numero es un techo, no una preferencia: sin techo, una cartera con
     * anios de facturas incobrables entraria entera en la columna de hoy y el
     * primer dia del eje mostraria una cobranza que nadie espera cobrar. Seis
     * meses es lo que Tesoreria todavia gestiona.
     */
    const DIAS_COBRO_VENCIDO = 180;

    /** Clave del plazo de cobro de exportaciones en RO_T_CASHFLOW_PARAMETROS */
    const PARAM_EXPORTACIONES_DIAS = 'exportaciones_tasky_dias_cobro';

    /** Plazo por defecto, el mismo que siembra sql/cashflow_exportaciones_tasky.sql */
    const EXPORTACIONES_DIAS_DEFAULT = 30;

    private $conn;

    /** @var float|null|false Cache de getCotizacionHoy(); false = todavia no se pidio */
    private $cotizacionHoy = false;

    /** @var int|null Cache de getDiasCobroExportaciones() */
    private $diasCobroExportaciones = null;

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Lista de estados lista para intercalar en un IN (...) de SQL.
     * Las dos constantes son literales del codigo, no entrada del usuario: no
     * hay nada que parametrizar. Existe para que la lista este escrita una sola
     * vez y las consultas de Real y de la proyeccion no se puedan desalinear.
     *
     * @param array $estados
     * @return string
     */
    private static function inSql($estados) {
        return "'" . implode("', '", $estados) . "'";
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
     * La escala de descuento GENERAL, desde RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC.
     *
     * Es UNA SOLA para todos los clientes y NO depende del medio de pago. Antes
     * habia una escala por cliente y por medio en
     * RO_T_CASHFLOW_COBRANZAS_PARAM_DESC; esa tabla sigue existiendo con sus
     * datos, pero ya no se lee. Ver README-cobranzas-fr.md.
     *
     * Devuelve los tramos ordenados por DIAS_DESDE. Una lista vacia no es un
     * error: significa que la escala todavia no se cargo, y el descuento da 0%.
     *
     * @return array Lista de tramos ['id', 'dias_desde', 'dias_hasta', 'porcentaje_desc']
     */
    public function getEscalasDescuento() {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            return [];
        }

        $sql = "SELECT ID, DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC
                FROM RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC
                WHERE ACTIVO = 1
                ORDER BY DIAS_DESDE ASC";

        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt === false) {
            return [];
        }

        $escala = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $escala[] = [
                'id' => intval($row['ID']),
                'dias_desde' => intval($row['DIAS_DESDE']),
                'dias_hasta' => intval($row['DIAS_HASTA']),
                'porcentaje_desc' => floatval($row['PORCENTAJE_DESC'])
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $escala;
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
     * Porcentaje de descuento que corresponde a una cantidad de dias.
     *
     * Ya no recibe cliente ni medio de pago: la escala es una sola y general.
     * La firma quedo con un solo dato de negocio porque eso es lo que hoy
     * determina el descuento, y una firma que siga pidiendo el cliente
     * sugeriria que todavia influye.
     *
     * @param int $dias Dias entre la emision y la fecha de cobro
     * @param array|null $escala Escala pre-cargada; si es null la va a buscar
     * @return float Porcentaje (ej: 8.0 para 8%)
     */
    public function calcularDescuentoPorDias($dias, $escala = null) {
        if ($escala === null) {
            $escala = $this->getEscalasDescuento();
        }

        return self::descuentoDeEscala($escala, $dias);
    }

    /**
     * El tramo que contiene una cantidad de dias, y su porcentaje.
     *
     * Va estatica y pura -sin base de datos- para poder probarla: es la regla
     * que decide cuanta plata se descuenta de cada factura.
     *
     * Un dia que no cae en ningun tramo devuelve 0%. Es lo unico razonable, y
     * por eso mismo la semilla de la escala llega hasta 9999: asi el cero
     * siempre es un tramo cargado y no un hueco de configuracion.
     *
     * @param array $escala Lista de tramos con dias_desde, dias_hasta y porcentaje_desc
     * @param int $dias
     * @return float
     */
    public static function descuentoDeEscala($escala, $dias) {
        $dias = intval($dias);

        foreach (is_array($escala) ? $escala : [] as $tramo) {
            if ($dias >= intval($tramo['dias_desde']) && $dias <= intval($tramo['dias_hasta'])) {
                return floatval($tramo['porcentaje_desc']);
            }
        }

        return 0.0;
    }

    /**
     * Valida que una escala cubra los dias de punta a punta sin solaparse.
     *
     * Los dos defectos que busca se ven distinto y los dos son caros:
     * un SOLAPAMIENTO hace que el descuento dependa del orden de los tramos, y
     * un HUECO hace que un dia caiga en el 0% de "no hay tramo" en vez del que
     * alguien penso. Ninguno se nota mirando la grilla: se nota en el importe.
     *
     * Es estatica y pura para que la valide el servidor y la pueda probar el
     * arnes, sin depender de que el navegador la haya chequeado antes.
     *
     * @param array $tramos Lista con dias_desde, dias_hasta
     * @return array Lista de mensajes de error; vacia si la escala es valida
     */
    public static function validarEscala($tramos) {
        $errores = [];
        $lista = [];

        foreach (is_array($tramos) ? $tramos : [] as $t) {
            $desde = intval($t['dias_desde']);
            $hasta = intval($t['dias_hasta']);
            $porc = isset($t['porcentaje_desc']) ? floatval($t['porcentaje_desc']) : 0;

            if ($desde < 0) {
                $errores[] = 'El tramo que arranca en ' . $desde . ' días tiene un desde negativo.';
            }

            if ($hasta < $desde) {
                $errores[] = 'El tramo ' . $desde . '-' . $hasta . ' termina antes de empezar.';
            }

            if ($porc < 0 || $porc > 100) {
                $errores[] = 'El descuento del tramo ' . $desde . '-' . $hasta
                    . ' tiene que estar entre 0% y 100%.';
            }

            $lista[] = ['desde' => $desde, 'hasta' => $hasta];
        }

        if (empty($lista)) {
            return $errores;
        }

        usort($lista, function ($a, $b) {
            return $a['desde'] - $b['desde'];
        });

        if ($lista[0]['desde'] !== 0) {
            $errores[] = 'La escala tiene que arrancar en 0 días: hoy arranca en '
                . $lista[0]['desde'] . ' y las facturas más nuevas quedarían sin descuento.';
        }

        for ($i = 1; $i < count($lista); $i++) {
            $anterior = $lista[$i - 1];
            $actual = $lista[$i];

            if ($actual['desde'] <= $anterior['hasta']) {
                $errores[] = 'Los tramos ' . $anterior['desde'] . '-' . $anterior['hasta']
                    . ' y ' . $actual['desde'] . '-' . $actual['hasta'] . ' se superponen: '
                    . 'un mismo plazo tendría dos descuentos.';
            } elseif ($actual['desde'] > $anterior['hasta'] + 1) {
                $errores[] = 'Entre ' . $anterior['hasta'] . ' y ' . $actual['desde']
                    . ' días no hay tramo: esas facturas irían con 0% sin que nadie lo haya decidido.';
            }
        }

        return $errores;
    }

    /* ====================================================================
       FECHA DE COBRO MANUAL POR COMPROBANTE
       ==================================================================== */

    /**
     * Las fechas de cobro cargadas a mano, indexadas por comprobante.
     *
     * @return array Mapa 'T_COMP|N_COMP' => ['fecha' => 'Y-m-d', 'usuario' => ...]
     */
    public function getFechasManualesFR() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT COD_CLIENTE, T_COMP, N_COMP, FECHA_COBRO, USUARIO
                FROM RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            // La tabla puede no existir todavia: sin fechas manuales la
            // proyeccion funciona igual, con el PPP. No es motivo para tumbar
            // la pestana.
            return [];
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = strtoupper(trim($row['T_COMP'])) . '|' . strtoupper(trim($row['N_COMP']));

            $mapa[$clave] = [
                'fecha' => Horizonte::normalizarFecha($row['FECHA_COBRO']),
                'cod_cliente' => strtoupper(trim($row['COD_CLIENTE'])),
                'usuario' => $row['USUARIO']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Guarda (o pisa) la fecha de cobro manual de un comprobante.
     *
     * VALIDA LA FECHA EN EL SERVIDOR. El input del navegador lleva `min` en el
     * dia de hoy, pero lo que manda el navegador es un pedido, no una
     * autorizacion: el endpoint es alcanzable sin pasar por la pantalla.
     *
     * @param string $codCliente
     * @param string $tComp
     * @param string $nComp
     * @param string $fecha 'Y-m-d'
     * @param string|null $usuario
     * @return string La fecha guardada, normalizada
     */
    public function saveFechaManualFR($codCliente, $tComp, $nComp, $fecha, $usuario = null) {
        $cod = strtoupper(trim($codCliente));
        $t = strtoupper(trim($tComp));
        $n = strtoupper(trim($nComp));
        $f = self::validarFechaCobroManual($fecha);

        if ($t === '' || $n === '') {
            throw new Exception('Falta el comprobante al que corresponde la fecha de cobro.');
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        // Un UPDATE que no toca ninguna fila y despues un INSERT: la unicidad
        // esta en (T_COMP, N_COMP), asi que no puede quedar duplicado.
        $sql = "UPDATE RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL
                SET FECHA_COBRO = ?, COD_CLIENTE = ?, USUARIO = ?, FECHA_MOD = GETDATE()
                WHERE T_COMP = ? AND N_COMP = ?";

        $stmt = sqlsrv_query($cid, $sql, [$f, $cod, $usuario, $t, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlIngresos('Error al guardar la fecha de cobro'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas > 0) {
            return $f;
        }

        $sql = "INSERT INTO RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL
                    (COD_CLIENTE, T_COMP, N_COMP, FECHA_COBRO, USUARIO)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = sqlsrv_query($cid, $sql, [$cod, $t, $n, $f, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlIngresos('Error al guardar la fecha de cobro'));
        }

        sqlsrv_free_stmt($stmt);

        return $f;
    }

    /**
     * Borra la fecha manual de un comprobante: vuelve a valer FECHA_EMIS + PPP.
     *
     * Aca SI hay borrado fisico, a diferencia del resto del modulo, y es a
     * proposito: la fila no es un dato de negocio historico sino un override
     * puntual, y su baja logica seria indistinguible de no tenerla. Lo que el
     * modulo no borra son los importes y la configuracion del tablero.
     *
     * @param string $tComp
     * @param string $nComp
     * @return bool
     */
    public function deleteFechaManualFR($tComp, $nComp) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $sql = "DELETE FROM RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL
                WHERE T_COMP = ? AND N_COMP = ?";

        $stmt = sqlsrv_query($cid, $sql, [strtoupper(trim($tComp)), strtoupper(trim($nComp))]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlIngresos('Error al borrar la fecha de cobro'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Normaliza y valida una fecha de cobro cargada a mano.
     *
     * NO SE ACEPTAN FECHAS PASADAS. La pestana solo muestra cobros de hoy en
     * adelante, asi que una fecha de ayer haria desaparecer la factura de la
     * grilla sin ningun aviso: el usuario veria que su edicion "borro" la fila.
     *
     * Estatica y pura, para poder probarla y para que la use el endpoint sin
     * necesitar conexion.
     *
     * @param mixed $fecha
     * @param string|null $hoy 'Y-m-d'; por defecto el dia de hoy
     * @return string 'Y-m-d'
     * @throws Exception si la fecha no es valida o es anterior a hoy
     */
    public static function validarFechaCobroManual($fecha, $hoy = null) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha de cobro no es una fecha válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha de cobro no existe en el calendario.');
        }

        $referencia = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        if ($f < $referencia) {
            throw new Exception('La fecha de cobro no puede ser anterior a hoy ('
                . self::formatoCorto($referencia) . '). Una factura con fecha pasada '
                . 'desaparecería del listado de pendientes.');
        }

        return $f;
    }

    /**
     * Resuelve la fecha de cobro de un comprobante y los dias que le
     * corresponden. ES LA JERARQUIA, escrita una sola vez:
     *
     *   1. la fecha manual, si hay una cargada para ese comprobante
     *   2. si no, FECHA_EMIS + PPP del cliente
     *
     * Y los dias salen SIEMPRE de la fecha resuelta, no del PPP: con fecha
     * manual el plazo real es otro, y con el plazo cambia el tramo de la escala
     * y por lo tanto el importe neto. Devolver el PPP como "dias" cuando hay
     * fecha manual dejaria el descuento calculado sobre un plazo que no existe.
     *
     * Estatica y pura: es la regla mas facil de romper de la pestana.
     *
     * @param string $fechaEmis 'Y-m-d'
     * @param int $pppDias
     * @param string|null $fechaManual 'Y-m-d' o null
     * @return array ['fecha' => 'Y-m-d', 'dias' => int, 'manual' => bool]
     */
    public static function resolverFechaCobro($fechaEmis, $pppDias, $fechaManual = null) {
        $emis = new DateTime($fechaEmis);
        $emis->setTime(0, 0, 0);

        if ($fechaManual !== null && $fechaManual !== '') {
            $cobro = new DateTime(substr((string) $fechaManual, 0, 10));
            $cobro->setTime(0, 0, 0);
            $manual = true;
        } else {
            $cobro = clone $emis;
            $cobro->modify('+' . intval($pppDias) . ' days');
            $manual = false;
        }

        // Diferencia con signo: una fecha manual anterior a la emision da
        // negativa, y eso tiene que llegar asi a la escala en vez de aparecer
        // como un plazo positivo. DateTime::diff()->days es siempre positivo.
        $dias = intval($emis->diff($cobro)->format('%r%a'));

        return [
            'fecha' => $cobro->format('Y-m-d'),
            'dias' => $dias,
            'manual' => $manual
        ];
    }

    /**
     * Donde se ubica en el eje una factura cuya fecha probable de cobro YA
     * PASO, y si hay que dejarla afuera.
     *
     * ES LA REGLA UNICA DE LAS TRES PESTANAS DE COBRANZA PROYECTADA, y hasta
     * ahora eran dos criterios opuestos:
     *
     *   Cobranzas FR y Mayoristas   -> la descartaban con un `continue`. Esa
     *                                  plata desaparecia de la pantalla y nada
     *                                  lo decia.
     *   Exportaciones Tasky         -> la ubicaba en HOY y avisaba.
     *
     * Vale la de Exportaciones: una factura con la fecha estimada en el pasado
     * es UNA FACTURA VENCIDA SIN COBRAR, que es informacion y no un error a
     * esconder. Lo que se conserva del criterio viejo es el techo: mas atras de
     * $diasAtras la factura queda afuera igual.
     *
     * LA FECHA MANUAL MANDA Y NO SE REUBICA. Es una fecha que Tesoreria pacto
     * con el cliente; moverla a hoy seria pisar una decision tomada con una
     * regla automatica, y el usuario veria su propia carga en otra columna. Se
     * marca vencida -eso es un hecho- pero se muestra donde esta. Tampoco se
     * descarta: si cayo fuera del eje, lo informa EjeVista.
     *
     * LOS DIAS DEL DESCUENTO NO SALEN DE ACA. Quien llama conserva los dias de
     * resolverFechaCobro(), que son los del plazo pactado: el tramo de la
     * escala lo decide ese plazo y no la columna en la que se dibuja el
     * importe.
     *
     * Estatica y pura: es la pieza que reparte la plata entre "se ve" y "no se
     * ve", asi que es la que mas conviene probar sin base.
     *
     * @param string|null $fechaProbable Fecha de cobro resuelta, 'Y-m-d'
     * @param string $hoy Primer dia del eje, 'Y-m-d'
     * @param int|null $diasAtras Dias hacia atras que se aceptan. null = sin
     *                            techo, que es lo que usa Exportaciones Tasky
     * @param bool $manual Si la fecha la cargo una persona
     * @return array ['fecha', 'original', 'vencida', 'descartar']
     */
    public static function ubicarCobroVencido($fechaProbable, $hoy, $diasAtras, $manual = false) {
        $f = Horizonte::normalizarFecha($fechaProbable);

        if ($f === null) {
            // Sin fecha no hay nada que ubicar: se transporta asi y
            // Horizonte::agrupar() la informa en 'sin_fecha' en vez de
            // perderla.
            return ['fecha' => null, 'original' => null, 'vencida' => false, 'descartar' => false];
        }

        $hoyStr = substr((string) $hoy, 0, 10);

        if ($f >= $hoyStr) {
            return ['fecha' => $f, 'original' => $f, 'vencida' => false, 'descartar' => false];
        }

        if ($manual) {
            return ['fecha' => $f, 'original' => $f, 'vencida' => true, 'descartar' => false];
        }

        if ($diasAtras !== null) {
            $limite = new DateTime($hoyStr);
            $limite->setTime(0, 0, 0);
            $limite->modify('-' . intval($diasAtras) . ' days');

            if ($f < $limite->format('Y-m-d')) {
                return ['fecha' => $f, 'original' => $f, 'vencida' => true, 'descartar' => true];
            }
        }

        return ['fecha' => $hoyStr, 'original' => $f, 'vencida' => true, 'descartar' => false];
    }

    /**
     * Los avisos de una grilla de cobranza proyectada sobre sus facturas
     * vencidas: lo que NO se ve en los numeros y hay que decir.
     *
     * Son dos avisos y no uno porque son dos cosas distintas: una factura
     * reubicada esta en la columna de hoy sin que se estime cobrarla hoy, y una
     * con fecha pactada vencida esta en la columna de su fecha, que ya paso. Un
     * solo mensaje para las dos haria pensar que estan todas en el mismo lugar.
     *
     * Misma redaccion que avisosExportaciones(), que es de donde sale el
     * criterio. Estatica y pura, para que las dos pestanas digan lo mismo.
     *
     * @param array $items Filas con 'VENCIDA', 'FECHA_MANUAL' e importe
     * @param string $campoImporte Campo con el importe a informar
     * @return array Lista de mensajes
     */
    public static function avisosCobranzasVencidas($items, $campoImporte = 'importe_neto') {
        $reubicadas = 0;
        $pactadas = 0;
        $impReubicadas = 0.0;
        $impPactadas = 0.0;

        foreach (is_array($items) ? $items : [] as $it) {
            if (empty($it['VENCIDA'])) {
                continue;
            }

            $importe = isset($it[$campoImporte]) ? floatval($it[$campoImporte]) : 0.0;

            if (!empty($it['FECHA_MANUAL'])) {
                $pactadas++;
                $impPactadas += $importe;
            } else {
                $reubicadas++;
                $impReubicadas += $importe;
            }
        }

        $avisos = [];

        if ($reubicadas > 0) {
            $avisos[] = $reubicadas . ' factura' . ($reubicadas === 1 ? '' : 's') . ' por '
                . self::plata($impReubicadas) . ' ' . ($reubicadas === 1 ? 'tiene' : 'tienen')
                . ' la fecha probable de cobro ya vencida: se '
                . ($reubicadas === 1 ? 'ubica' : 'ubican') . ' en el primer día del eje. '
                . ($reubicadas === 1 ? 'Es una factura vencida' : 'Son facturas vencidas')
                . ' sin cobrar, no cobranza estimada para hoy.';
        }

        if ($pactadas > 0) {
            $avisos[] = $pactadas . ' factura' . ($pactadas === 1 ? '' : 's') . ' por '
                . self::plata($impPactadas) . ' ' . ($pactadas === 1 ? 'tiene' : 'tienen')
                . ' la fecha de cobro pactada a mano y ya vencida: se '
                . ($pactadas === 1 ? 'muestra' : 'muestran')
                . ' en su fecha, sin reubicar, porque la cargó una persona.';
        }

        return $avisos;
    }

    /** dd/mm/aaaa, para los mensajes de error */
    private static function formatoCorto($fecha) {
        $p = explode('-', substr((string) $fecha, 0, 10));

        return (count($p) === 3) ? $p[2] . '/' . $p[1] . '/' . $p[0] : $fecha;
    }

    /** Mensaje de error de sqlsrv, con contexto */
    private function errorSqlIngresos($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto;

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= ': ' . $e['message'];
            }
        }

        return $msg;
    }

    /**
     * Los comprobantes pendientes proyectados: FAC en estado PEN que no cuenta
     * la cobranza real ni estan ya cobrados.
     *
     * Devuelve SIEMPRE una fila por comprobante, con su fecha de cobro. El
     * resumen por cliente lo hace EjeVista::armarAgrupado(), que suma las
     * series contra el eje y por lo tanto NO pierde la fecha de cada factura:
     * es lo que ubica cada importe en su columna de la grilla. Antes esta
     * funcion tenia un modo resumen que agrupaba por cliente + fecha, y eso
     * obligaba a que un cliente con cobros en tres fechas ocupara tres filas.
     *
     * LAS VENCIDAS ENTRAN. Antes, la factura cuya fecha probable de cobro ya
     * habia pasado se descartaba con un `continue` y su importe desaparecia de
     * la pantalla sin que nada lo dijera. Ahora entra si la fecha cae dentro de
     * los ultimos DIAS_COBRO_VENCIDO dias, se ubica en el primer dia del eje y
     * viaja marcada con VENCIDA y COBRO_ORIGINAL para que la pantalla la
     * distinga. La regla completa esta en ubicarCobroVencido().
     *
     * @return array Listado de comprobantes proyectados
     */
    public function getCobranzasFRPendientesProyectadas() {
        $cid_apps = $this->conn->conectar('apps');
        $cid_central = $this->conn->conectar('central');

        if (!$cid_apps || !$cid_central) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // 1. Comprobantes que NO tienen que volver a la proyeccion.
        //    INVARIANTE: esta exclusion es el complemento exacto de lo que
        //    cuenta la cobranza real. Real cuenta ESTADOS_REAL ('ACEPTADA'), y
        //    aca se excluye eso mas 'PAGADO', que ya se cobro. Una factura en
        //    una propuesta PENDIENTE_APROBACION_CLIENTE no la cuenta Real, asi
        //    que TIENE que aparecer aca: si las dos consultas se desalinean,
        //    esas facturas desaparecen de las dos pestanas y la plata se
        //    evapora en silencio. Ver getCobranzasFR() y el encabezado.
        $sql_prop = "SELECT items.t_comp_factura, items.n_comp_factura
                     FROM FP_propuestas_pago_items items
                     JOIN FP_propuestas_pago propuestas ON items.id_propuesta = propuestas.id
                     WHERE propuestas.estado IN (" . self::inSql(self::ESTADOS_YA_CONTADOS) . ")";
        $stmt_prop = sqlsrv_query($cid_apps, $sql_prop);
        $en_propuestas = [];
        if ($stmt_prop !== false) {
            while ($r = sqlsrv_fetch_array($stmt_prop, SQLSRV_FETCH_ASSOC)) {
                $key = strtoupper(trim($r['t_comp_factura'])) . '|' . strtoupper(trim($r['n_comp_factura']));
                $en_propuestas[$key] = true;
            }
            sqlsrv_free_stmt($stmt_prop);
        }

        // 2. PPP por cliente, escala general de descuento y fechas manuales
        $ppps = $this->getPPPClientes();
        $escala = $this->getEscalasDescuento();
        $fechasManuales = $this->getFechasManualesFR();

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

            // Fecha de cobro: manda la manual; si no hay, F. Emis + PPP. Y los
            // dias salen de la fecha resuelta, no del PPP, porque son los que
            // deciden el tramo de descuento. Ver resolverFechaCobro().
            $manual = isset($fechasManuales[$key]) ? $fechasManuales[$key]['fecha'] : null;
            $cobro = self::resolverFechaCobro($fEmisStr, $pppDias, $manual);

            // Una factura cuya fecha probable ya pasó NO se descarta: se ubica
            // en el primer día del eje y queda marcada como vencida, salvo que
            // la fecha la haya cargado una persona. Sólo se deja afuera lo más
            // viejo que DIAS_COBRO_VENCIDO. Ver ubicarCobroVencido().
            $ubic = self::ubicarCobroVencido($cobro['fecha'], $hoy->format('Y-m-d'),
                self::DIAS_COBRO_VENCIDO, $cobro['manual']);

            if ($ubic['descartar']) {
                continue;
            }

            // Los días del descuento salen del plazo pactado y no de la columna
            // en la que se dibuja el importe: reubicar una vencida en hoy no le
            // cambia el tramo de la escala.
            $diasDesc = $cobro['dias'];

            // Descuento segun la escala GENERAL: no depende del cliente ni del
            // medio de pago.
            $porcDesc = self::descuentoDeEscala($escala, $diasDesc);
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
                'Cobro' => $ubic['fecha'],
                'COBRO_ORIGINAL' => $ubic['original'],
                'VENCIDA' => $ubic['vencida'],
                'FECHA_MANUAL' => $cobro['manual'],
                'TIPO_REGISTRO' => 'PROYECCION' // Distintivo para pintar en amarillo
            ];
        }
        sqlsrv_free_stmt($stmt_fac);

        return $itemsProyectados;
    }

    /**
     * Obtiene los datos de Cobranzas FR (Franquicias).
     * Permite filtrar por tipo de origen:
     *   - 'todos': Propuestas reales + Facturas pendientes proyectadas
     *   - 'real': Solo propuestas reales
     *   - 'proyectado': Solo facturas pendientes proyectadas con PPP
     * 
     * Devuelve SIEMPRE una fila por comprobante, con su fecha de cobro. El
     * resumen por cliente lo hace EjeVista::armarAgrupado() en el controller.
     *
     * $summary ya no agrupa: lo unico que decide es cuanto detalle se trae. En
     * resumen no hace falta la fecha de emision de cada comprobante, y esa es
     * la parte cara -una consulta a GVA12 por fila-, asi que se saltea. Ver el
     * pendiente conocido en README-cashflow.md.
     *
     * @param bool $summary Si es true, se omite el detalle por comprobante
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

            // INVARIANTE: Real cuenta UNICAMENTE las propuestas ACEPTADAS. El
            // complemento -lo que espera aprobacion del cliente- vuelve a la
            // proyeccion; la exclusion esta en
            // getCobranzasFRPendientesProyectadas() y las dos se mueven juntas.
            $estadosReal = self::inSql(self::ESTADOS_REAL);

            // La misma consulta para los dos modos: una fila por comprobante,
            // con su fecha de cobro. El resumen ya no agrupa aca -lo hace
            // EjeVista::armarAgrupado()-, porque un GROUP BY por cliente +
            // fecha obliga a que un cliente con cobros en tres fechas ocupe
            // tres filas del resumen.
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
                        WHERE p.estado IN ($estadosReal)
                        AND p.fecha_propuesta_pago >= CAST(GETDATE() AS DATE)
                        ORDER BY p.fecha_propuesta_pago ASC";

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
                $item['COD_CLI'] = $cod_cli;
                $item['RAZON_SOC'] = $razonesSociales[$cod_cli] ?? 'Cliente no encontrado';
                $item['TIPO_REGISTRO'] = 'REAL';

                // Las notas de credito restan, en los dos modos. Antes el signo
                // se aplicaba en el deep dive y en el resumen lo resolvia el
                // CASE del GROUP BY; sin ese GROUP BY tiene que aplicarse acá
                // siempre, o el resumen sumaria las NC en vez de restarlas.
                $multiplicador = (strpos(trim($item['T_COMP']), 'NC') !== false) ? -1 : 1;

                $item['importe_bruto'] = (float) $item['importe_bruto'] * $multiplicador;
                $item['importe_neto'] = (float) $item['importe_neto'] * $multiplicador;
                $item['Desc'] = (float) $item['Desc'] . '%';

                if ($summary) {
                    // El resumen no muestra ni la emision ni los dias, y traer
                    // la emision cuesta una consulta a GVA12 POR FILA.
                    $item['FECHA'] = 'N/A';
                    $item['Dias'] = 0;
                } else {
                    $sql_f = "SELECT TOP 1 FECHA_EMIS FROM GVA12 WHERE T_COMP = ? AND N_COMP = ?";
                    $stmt_f = sqlsrv_query($cid_central, $sql_f, [trim($item['T_COMP']), trim($item['N_COMP'])]);

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
                }

                $data[] = $item;
            }
            sqlsrv_free_stmt($stmt);
        }

        // 2. Cargar pendientes proyectados si corresponde
        if ($origen === 'todos' || $origen === 'proyectado') {
            $proyectados = $this->getCobranzasFRPendientesProyectadas();
            $data = array_merge($data, $proyectados);
        }

        return $data;
    }

    /**
     * Cobranza de franquicias agregada por fecha de cobro, para el tablero de
     * Cashflow.
     *
     * ADEMAS DEL IMPORTE, DEVUELVE QUE PARTE DE EL ESTA PACTADA A MANO.
     *
     * Una factura con fecha de cobro manual es una que Tesoreria hablo con el
     * cliente y acordo por fuera de la app de cobranzas: no esta en ninguna
     * propuesta, pero la fecha no es una estimacion del PPP sino algo que
     * alguien pacto. Para quien mira el tablero son dos cosas distintas con la
     * misma pinta, y sin distinguirlas el numero de una columna no dice si es
     * una proyeccion estadistica o un compromiso conversado.
     *
     * Va en la misma pasada y no en un metodo aparte a proposito:
     * getCobranzasFRPendientesProyectadas() recorre todas las facturas PEN, y
     * pedirle el detalle por separado seria correr esa consulta una vez mas
     * por cada carga del tablero.
     *
     * Y TAMBIEN QUE PARTE ESTA VENCIDA: son las facturas que se ubicaron en hoy
     * por tener la fecha probable en el pasado. El tablero tiene que poder
     * distinguirlas de una cobranza que de verdad se estima para hoy, igual que
     * ya hace con las exportaciones vencidas.
     *
     * @param string $origen 'todos', 'real', o 'proyectado'
     * @return array Filas ['FECHA', 'IMPORTE', 'IMPORTE_PACTADO', 'COMP_PACTADOS',
     *                      'IMPORTE_VENCIDO', 'COMP_VENCIDOS']
     */
    public function getCobranzasFRTotales($origen = 'todos') {
        $totalesPorFecha = [];
        $pactadoPorFecha = [];
        $compPorFecha = [];
        $vencidoPorFecha = [];
        $compVencidosPorFecha = [];

        // 1. Cobranza Real
        if ($origen === 'todos' || $origen === 'real') {
            $cid = $this->conn->conectar('apps');
            if ($cid) {
                // Mismo criterio que getCobranzasFR(): solo propuestas
                // ACEPTADAS. Ver la invariante en el encabezado de la clase.
                $estadosReal = self::inSql(self::ESTADOS_REAL);

                $sql = "SELECT
                            p.fecha_propuesta_pago AS FECHA,
                            SUM(CASE WHEN i.t_comp_factura LIKE '%NC%'
                                     THEN -i.importe_neto ELSE i.importe_neto END) AS IMPORTE
                        FROM FP_propuestas_pago p
                        INNER JOIN FP_propuestas_pago_items i ON p.id = i.id_propuesta
                        WHERE p.estado IN ($estadosReal)
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
            $proy = $this->getCobranzasFRPendientesProyectadas();

            foreach ($proy as $p) {
                $f = $p['Cobro'];
                $importe = floatval($p['importe_neto']);

                $totalesPorFecha[$f] = ($totalesPorFecha[$f] ?? 0.0) + $importe;

                // Solo la proyeccion puede tener fecha pactada: la cobranza
                // real ya sale de una propuesta, o sea que su fecha siempre
                // esta acordada y marcarla no distinguiria nada.
                if (!empty($p['FECHA_MANUAL'])) {
                    $pactadoPorFecha[$f] = ($pactadoPorFecha[$f] ?? 0.0) + $importe;
                    $compPorFecha[$f] = ($compPorFecha[$f] ?? 0) + 1;
                }

                if (!empty($p['VENCIDA'])) {
                    $vencidoPorFecha[$f] = ($vencidoPorFecha[$f] ?? 0.0) + $importe;
                    $compVencidosPorFecha[$f] = ($compVencidosPorFecha[$f] ?? 0) + 1;
                }
            }
        }

        $resultado = [];
        ksort($totalesPorFecha);

        foreach ($totalesPorFecha as $f => $imp) {
            $resultado[] = [
                'FECHA' => $f,
                'IMPORTE' => round($imp, 2),
                'IMPORTE_PACTADO' => round($pactadoPorFecha[$f] ?? 0.0, 2),
                'COMP_PACTADOS' => $compPorFecha[$f] ?? 0,
                'IMPORTE_VENCIDO' => round($vencidoPorFecha[$f] ?? 0.0, 2),
                'COMP_VENCIDOS' => $compVencidosPorFecha[$f] ?? 0
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
     * Las facturas pendientes de Mayoristas (Camino 1), proyectadas a fecha de
     * emision + dias de plazo.
     *
     * Devuelve SIEMPRE una fila por comprobante, con su fecha de cobro. El
     * resumen por cliente lo hace EjeVista::armarAgrupado() en el controller:
     * asi cada importe conserva su fecha, que es lo que lo ubica en la grilla,
     * y un cliente con cobros en tres fechas sigue siendo UNA fila.
     *
     * LAS VENCIDAS ENTRAN, igual que en Cobranzas FR y por el mismo motivo:
     * antes se descartaban con un `continue` y la plata desaparecia de la
     * pantalla. Ver ubicarCobroVencido().
     *
     * @return array Listado de comprobantes proyectados
     */
    public function getCobranzasMay() {
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

            // Las vencidas no se descartan: van al primer día del eje y se
            // marcan, con el mismo techo de días que Cobranzas FR. Mayoristas
            // no tiene fecha manual, así que nunca hay nada que respetar.
            // Ver ubicarCobroVencido().
            $ubic = self::ubicarCobroVencido($fProbCobroStr, $hoyStr,
                self::DIAS_COBRO_VENCIDO);

            if ($ubic['descartar']) {
                continue;
            }

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
                'Cobro' => $ubic['fecha'],
                'COBRO_ORIGINAL' => $ubic['original'],
                'VENCIDA' => $ubic['vencida'],
                'TIPO_REGISTRO' => 'PROYECCION',
                'PLAZO' => $diasPlazo
            ];
        }
        sqlsrv_free_stmt($stmt_fac);

        return $items;
    }

    /**
     * Cobranza mayorista agregada por fecha probable de cobro para el tablero de Cashflow.
     *
     * Informa aparte la parte VENCIDA, por el mismo motivo que
     * getCobranzasFRTotales(): son facturas ubicadas en hoy por tener la fecha
     * probable en el pasado, no cobranza estimada para hoy.
     *
     * @return array Filas ['FECHA', 'IMPORTE', 'IMPORTE_VENCIDO', 'COMP_VENCIDOS']
     */
    public function getCobranzasMayTotales() {
        $items = $this->getCobranzasMay();
        $totalesPorFecha = [];
        $vencidoPorFecha = [];
        $compVencidosPorFecha = [];

        foreach ($items as $item) {
            $f = $item['Cobro'];
            $importe = floatval($item['importe_neto']);

            $totalesPorFecha[$f] = ($totalesPorFecha[$f] ?? 0.0) + $importe;

            if (!empty($item['VENCIDA'])) {
                $vencidoPorFecha[$f] = ($vencidoPorFecha[$f] ?? 0.0) + $importe;
                $compVencidosPorFecha[$f] = ($compVencidosPorFecha[$f] ?? 0) + 1;
            }
        }

        $resultado = [];
        ksort($totalesPorFecha);
        foreach ($totalesPorFecha as $f => $imp) {
            $resultado[] = [
                'FECHA' => $f,
                'IMPORTE' => round($imp, 2),
                'IMPORTE_VENCIDO' => round($vencidoPorFecha[$f] ?? 0.0, 2),
                'COMP_VENCIDOS' => $compVencidosPorFecha[$f] ?? 0
            ];
        }

        return $resultado;
    }

    /* ====================================================================
       EXPORTACIONES TASKY
       ==================================================================== */

    /**
     * Dias de plazo para estimar el cobro de las facturas a Tasky.
     * Lee 'exportaciones_tasky_dias_cobro' (default 30), igual que
     * getDiasPlazoMayoristas() con el plazo de mayoristas.
     *
     * @return int Dias de plazo
     */
    public function getDiasCobroExportaciones() {
        if ($this->diasCobroExportaciones !== null) {
            return $this->diasCobroExportaciones;
        }

        $this->diasCobroExportaciones = self::EXPORTACIONES_DIAS_DEFAULT;

        $cid = $this->conn->conectar('central');
        if (!$cid) return $this->diasCobroExportaciones;

        $sql = "SELECT VALOR FROM RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = ?";
        $stmt = sqlsrv_query($cid, $sql, [self::PARAM_EXPORTACIONES_DIAS]);
        if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            sqlsrv_free_stmt($stmt);
            $val = intval($row['VALOR']);
            if ($val > 0) {
                $this->diasCobroExportaciones = $val;
            }
        }

        return $this->diasCobroExportaciones;
    }

    /**
     * El dolar oficial BCRA de HOY: el cierre del mes en curso de
     * RO_V_DOLAR_OFICIAL_BCRA, que para el mes en curso es la ultima
     * cotizacion cargada.
     *
     * Devuelve null si no hay cotizacion o si la vista no esta disponible.
     * NO lanza y NO asume un valor: quien la use tiene que avisar y dejar la
     * columna en pesos vacia, que es distinto de mostrar cero.
     *
     * Se resuelve una sola vez por instancia: la pestana la necesita para las
     * filas y para el encabezado, y el proveedor para la serie.
     *
     * @return float|null
     */
    public function getCotizacionHoy() {
        if ($this->cotizacionHoy !== false) {
            return $this->cotizacionHoy;
        }

        try {
            $this->cotizacionHoy = (new Cotizacion())->delMes(intval(date('Y')), intval(date('n')));
        } catch (Throwable $e) {
            $this->cotizacionHoy = null;
        }

        return $this->cotizacionHoy;
    }

    /**
     * Las facturas pendientes a Tasky, una fila por comprobante, con su fecha
     * de cobro estimada y su valuacion a dolar de hoy.
     *
     * La consulta es la de GVA12 con el cliente de CLIENTES_EXPORTACION y estado
     * PEN. Los importes van a FLOAT y las fechas a DATE en el SELECT, como el
     * resto de las consultas sobre GVA12 del modulo.
     *
     * Lo que sale de la consulta lo transforma proyectarExportaciones(), que
     * es estatica y pura: ahi vive la regla de la fecha estimada, la de las
     * facturas vencidas y la de la conversion. Ver esa funcion.
     *
     * @return array Listado de comprobantes
     */
    public function getExportacionesTasky() {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $sql = "
            SELECT
                CAST(A.FECHA_EMIS AS DATE) AS FECHA_EMIS,
                A.COD_CLIENT,
                B.RAZON_SOCI,
                A.T_COMP,
                A.N_COMP,
                CAST(A.IMPORTE_EX AS FLOAT) AS IMPORTE_EX,
                CAST(A.COTIZ AS FLOAT) AS COTIZ,
                CAST(A.IMPORTE AS FLOAT) AS IMPORTE
            FROM GVA12 A
            INNER JOIN GVA14 B ON A.COD_CLIENT = B.COD_CLIENT
            WHERE A.T_COMP = 'FAC'
              AND A.COD_CLIENT IN (" . self::inSql(self::CLIENTES_EXPORTACION) . ")
              AND A.ESTADO = 'PEN'
            ORDER BY A.FECHA_EMIS ASC, A.N_COMP ASC
        ";

        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt === false) {
            throw new Exception("Error al consultar facturas pendientes de exportacion: " . print_r(sqlsrv_errors(), true));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        return self::proyectarExportaciones(
            $filas,
            $this->getDiasCobroExportaciones(),
            $this->getCotizacionHoy()
        );
    }

    /**
     * Transforma las filas crudas de GVA12 en las filas de la pestana.
     *
     * ES LA REGLA DE LA PESTANA, escrita una sola vez y sin base de datos,
     * para poder probarla:
     *
     *   Fecha de cobro estimada = FECHA_EMIS + dias
     *
     *   Una factura cuya fecha estimada YA PASO se ubica en HOY -el primer dia
     *   del eje- y queda marcada como VENCIDA. Es una factura vencida sin
     *   cobrar: informacion, no un error a esconder, y por eso no se descarta
     *   como hace Mayoristas. Quien la muestre tiene que avisar.
     *
     *   Importe en pesos de hoy = IMPORTE_USD x cotizacion de hoy
     *
     *   TODAS las facturas se valuan a la MISMA cotizacion, la de hoy, y no a
     *   la del mes en que se van a cobrar. La deuda esta fija en dolares y
     *   valuarla a hoy es no suponer devaluacion: el criterio conservador que
     *   se pidio. Sin cotizacion, el importe en pesos queda en null -no en
     *   cero- y los dolares siguen estando.
     *
     * COTIZ e IMPORTE (la cotizacion y los pesos al facturar) se copian tal
     * cual como referencia historica. No entran en ninguna cuenta.
     *
     * @param array $filas Filas crudas: FECHA_EMIS, COD_CLIENT, RAZON_SOCI,
     *                     T_COMP, N_COMP, IMPORTE_EX, COTIZ, IMPORTE
     * @param int $dias Plazo de cobro
     * @param float|null $cotizHoy Dolar de hoy, o null si no hay
     * @param string|null $hoy 'Y-m-d'; por defecto el dia de hoy
     * @return array Filas de la pestana
     */
    public static function proyectarExportaciones($filas, $dias, $cotizHoy, $hoy = null) {
        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $dias = intval($dias);
        $items = [];

        foreach (is_array($filas) ? $filas : [] as $row) {
            $fEmis = Horizonte::normalizarFecha(isset($row['FECHA_EMIS']) ? $row['FECHA_EMIS'] : null);
            $usd = isset($row['IMPORTE_EX']) ? round(floatval($row['IMPORTE_EX']), 2) : 0.0;

            $cobro = self::estimarCobroExportacion($fEmis, $dias, $hoyStr);

            $items[] = [
                'FECHA_EMIS' => $fEmis,
                'COD_CLIENT' => strtoupper(trim(isset($row['COD_CLIENT']) ? $row['COD_CLIENT'] : '')),
                'RAZON_SOCI' => trim(isset($row['RAZON_SOCI']) ? $row['RAZON_SOCI'] : ''),
                'T_COMP' => strtoupper(trim(isset($row['T_COMP']) ? $row['T_COMP'] : 'FAC')),
                'N_COMP' => strtoupper(trim(isset($row['N_COMP']) ? $row['N_COMP'] : '')),
                'IMPORTE_USD' => $usd,
                // Referencia historica: como se facturo. No se usa para nada mas.
                'COTIZ_FACT' => isset($row['COTIZ']) ? floatval($row['COTIZ']) : null,
                'IMPORTE_PESOS_FACT' => isset($row['IMPORTE']) ? round(floatval($row['IMPORTE']), 2) : null,
                // Valuacion de hoy: es la que va a la grilla y al tablero.
                'COTIZ_HOY' => $cotizHoy,
                'IMPORTE_PESOS_HOY' => self::valuarHoy($usd, $cotizHoy),
                'DIAS' => $dias,
                'Cobro' => $cobro['fecha'],
                'COBRO_ORIGINAL' => $cobro['original'],
                'VENCIDA' => $cobro['vencida']
            ];
        }

        return $items;
    }

    /**
     * Fecha de cobro estimada de una factura de exportacion.
     *
     * La ubicacion en el eje la resuelve ubicarCobroVencido(), que es la misma
     * regla que usan Cobranzas FR y Mayoristas. Aca se pasa SIN TECHO de dias
     * hacia atras, y esa es la unica diferencia con las otras dos: son pocas
     * facturas de un solo cliente y todas se gestionan, asi que no hay nada que
     * dejar afuera por antiguedad.
     *
     * @param string|null $fechaEmis 'Y-m-d'
     * @param int $dias Plazo de cobro
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha' => 'Y-m-d'|null, 'original' => 'Y-m-d'|null, 'vencida' => bool]
     */
    public static function estimarCobroExportacion($fechaEmis, $dias, $hoy) {
        if ($fechaEmis === null || $fechaEmis === '') {
            // Sin fecha de emision no hay de donde estimar: queda sin fecha y
            // Horizonte::agrupar() la informa en 'sin_fecha'.
            return ['fecha' => null, 'original' => null, 'vencida' => false];
        }

        $cobro = new DateTime(substr((string) $fechaEmis, 0, 10));
        $cobro->setTime(0, 0, 0);
        $cobro->modify('+' . intval($dias) . ' days');

        $ubic = self::ubicarCobroVencido($cobro->format('Y-m-d'), $hoy, null);

        return [
            'fecha' => $ubic['fecha'],
            'original' => $ubic['original'],
            'vencida' => $ubic['vencida']
        ];
    }

    /**
     * Dolares a pesos de hoy. Sin cotizacion devuelve null, no cero: un cero
     * se leeria como "la factura vale cero pesos".
     *
     * @param float $usd
     * @param float|null $cotizHoy
     * @return float|null
     */
    public static function valuarHoy($usd, $cotizHoy) {
        if ($cotizHoy === null || floatval($cotizHoy) <= 0) {
            return null;
        }

        return round(floatval($usd) * floatval($cotizHoy), 2);
    }

    /**
     * Los avisos de la pestana y del tablero sobre las exportaciones: lo que
     * NO se ve en los numeros y hay que decir.
     *
     *   - Sin cotizacion de hoy: la columna en pesos queda vacia y la grilla
     *     no muestra importes. Los dolares estan; lo que falta es a cuanto
     *     valuarlos. No se asume ningun valor.
     *   - Facturas vencidas: se ubicaron en el primer dia del eje. Son
     *     facturas vencidas sin cobrar.
     *
     * Estatica y pura para que la pestana y el proveedor digan lo mismo.
     *
     * @param array $items Filas de proyectarExportaciones()
     * @param float|null $cotizHoy
     * @return array Lista de mensajes
     */
    public static function avisosExportaciones($items, $cotizHoy) {
        $avisos = [];
        $usdTotal = 0.0;
        $usdVencidas = 0.0;
        $vencidas = 0;

        foreach (is_array($items) ? $items : [] as $it) {
            $usdTotal += floatval($it['IMPORTE_USD']);

            if (!empty($it['VENCIDA'])) {
                $vencidas++;
                $usdVencidas += floatval($it['IMPORTE_USD']);
            }
        }

        if ($cotizHoy === null && count($items) > 0) {
            $avisos[] = 'No hay cotización del dólar oficial BCRA para el mes en curso ('
                . Cotizacion::VISTA . '): los ' . self::usd($usdTotal) . ' pendientes no se '
                . 'pueden valuar, así que la columna en pesos queda vacía y no entran a la '
                . 'grilla ni al tablero. No se asume ningún tipo de cambio.';
        }

        if ($vencidas > 0) {
            $avisos[] = $vencidas . ' factura' . ($vencidas === 1 ? '' : 's') . ' por '
                . self::usd($usdVencidas) . ' ' . ($vencidas === 1 ? 'tiene' : 'tienen')
                . ' la fecha de cobro estimada ya vencida: se '
                . ($vencidas === 1 ? 'ubica' : 'ubican') . ' en el primer día del eje. '
                . ($vencidas === 1 ? 'Es una factura vencida' : 'Son facturas vencidas')
                . ' sin cobrar.';
        }

        return $avisos;
    }

    /**
     * Las exportaciones agregadas por fecha de cobro estimada, EN DOLARES,
     * para el tablero de Cashflow. La conversion a pesos la hace el proveedor,
     * como ComexProvider: aca se transportan dolares.
     *
     * Ademas del importe, devuelve que parte de el esta VENCIDA: son las
     * facturas que se ubicaron en hoy por tener la fecha estimada en el
     * pasado, y el tablero tiene que poder distinguirlas de una cobranza que
     * de verdad se estima para hoy.
     *
     * @return array Filas ['FECHA', 'IMPORTE_USD', 'VENCIDAS_USD', 'COMP_VENCIDOS']
     */
    public function getExportacionesTaskyTotales() {
        return self::agruparExportacionesPorFecha($this->getExportacionesTasky());
    }

    /**
     * El agregado de getExportacionesTaskyTotales(), separado y estatico para
     * poder probarlo sin base.
     *
     * @param array $items Filas de proyectarExportaciones()
     * @return array
     */
    public static function agruparExportacionesPorFecha($items) {
        $usdPorFecha = [];
        $vencidasPorFecha = [];
        $compPorFecha = [];

        foreach (is_array($items) ? $items : [] as $it) {
            $f = isset($it['Cobro']) ? $it['Cobro'] : null;

            // Sin fecha se transporta igual, con clave vacia: el agrupador del
            // horizonte la informa en 'sin_fecha' en vez de perderla.
            $clave = ($f === null) ? '' : $f;
            $usd = floatval($it['IMPORTE_USD']);

            $usdPorFecha[$clave] = ($usdPorFecha[$clave] ?? 0.0) + $usd;

            if (!empty($it['VENCIDA'])) {
                $vencidasPorFecha[$clave] = ($vencidasPorFecha[$clave] ?? 0.0) + $usd;
                $compPorFecha[$clave] = ($compPorFecha[$clave] ?? 0) + 1;
            }
        }

        $resultado = [];
        ksort($usdPorFecha);

        foreach ($usdPorFecha as $f => $usd) {
            $resultado[] = [
                'FECHA' => ($f === '') ? null : $f,
                'IMPORTE_USD' => round($usd, 2),
                'VENCIDAS_USD' => round($vencidasPorFecha[$f] ?? 0.0, 2),
                'COMP_VENCIDOS' => $compPorFecha[$f] ?? 0
            ];
        }

        return $resultado;
    }

    /** USD 1.234,56, para los avisos */
    private static function usd($n) {
        return 'USD ' . number_format(floatval($n), 2, ',', '.');
    }

    /** $ 1.234,56, para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }
}
