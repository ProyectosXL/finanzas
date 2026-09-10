<?php

/**
 * Parametros
 * Acceso a la tabla clave/valor RO_T_CASHFLOW_PARAMETROS y al mix de cobro.
 *
 * Esta clase es el unico lugar donde se leen los valores de negocio del modulo.
 * Ninguna formula debe llevar constantes hardcodeadas: alicuota de IVA, plazos
 * de acreditacion, porcentajes de mix, feriados de comercio y participaciones
 * de respaldo salen todos de aca.
 *
 * Esta pensada para ir absorbiendo los parametros del resto de los modulos,
 * por eso cada parametro tiene un GRUPO.
 */
class Parametros {

    /** Canales del modelo, en el orden en que se muestran */
    const CANALES = ['LOCALES', 'FRANQUICIAS', 'MAYORISTAS', 'ECOMMERCE'];

    /**
     * Modulos que expone la pestana Parametros, en el orden de las sub-pestanas.
     *
     * Cada parametro declara a que MODULO pertenece, asi se ve de un vistazo que
     * pestana afecta cada valor. 'secciones' dice que bloques renderiza el front
     * para ese modulo.
     *
     * PARA AGREGAR UN MODULO: sumar la entrada aca, cargar sus parametros con
     * ese MODULO en RO_T_CASHFLOW_PARAMETROS y agregar el tab-pane en
     * Tabs/parametros.php. El <li> de la sub-pestana ya se genera solo a partir
     * de esta lista.
     *
     * Un modulo puede traer sus datos por su cuenta en vez de declarar
     * 'secciones': es lo que hace CASHFLOW, que pide su estructura a su propio
     * endpoint. Con 'secciones' vacio, getModulosConDatos() no resuelve nada
     * para el, y asi esta clase no tiene que saber nada del Cashflow.
     */
    private static $modulos = [
        'VENTAS' => [
            'nombre' => 'Ventas',
            'icono' => 'fa-arrow-trend-up',
            'descripcion' => 'Alimentan la proyección de ventas y cobranzas de la pestaña Ventas',
            'secciones' => ['generales', 'mix', 'respaldo']
        ],
        'SALDOS' => [
            'nombre' => 'Saldos',
            'icono' => 'fa-wallet',
            'descripcion' => 'Bancos y cuentas, otros saldos y la gestión de caja de cada local. '
                . 'Alimentan la pestaña Saldos y las filas Saldo Inicial y Caja Locales del tablero',
            'secciones' => ['generales', 'cuentas', 'sucursales']
        ],
        'COB_ELECTRONICOS' => [
            'nombre' => 'Cob. Electrónicos',
            'icono' => 'fa-credit-card',
            'descripcion' => 'Procesadoras de pago y las alícuotas de retención con las que se '
                . 'calcula el importe neto de cada acreditación. Alimentan la pestaña '
                . 'Cob. Electrónicos y la fila Cobranzas Pagos Electrónicos del tablero',
            'secciones' => ['procesadoras', 'alicuotas']
        ],
        'PRECHEQUEADO' => [
            'nombre' => 'Pre-chequeado',
            'icono' => 'fa-money-check-dollar',
            'descripcion' => 'Qué clientes operan con venta cobrada anticipada. Acota el listado '
                . 'de Echeqs → Venta Cobrada Anticipada, que es de donde sale el neteo de '
                . 'cheques adelantados de la cobranza proyectada de Ventas',
            'secciones' => ['prechequeado']
        ],
        'COBRANZAS' => [
            'nombre' => 'Cobranzas',
            'icono' => 'fa-hand-holding-dollar',
            'descripcion' => 'Plazos promedio de pago (PPP) calculados y editables, y escalas de descuento por cliente',
            'secciones' => ['cobranzas_clientes']
        ],
        'CASHFLOW' => [
            'nombre' => 'Cashflow',
            'icono' => 'fa-table-cells',
            'descripcion' => 'Definen qué secciones y qué filas tiene el tablero de Cashflow, '
                . 'y de qué módulo saca sus datos cada fila',
            'secciones' => [],
            'endpoint' => 'Controller/CashflowEstructuraController.php'
        ]
    ];

    /**
     * Devuelve los modulos declarados, con su codigo incluido
     * @return array Lista de modulos
     */
    public static function getModulos() {
        $v = [];

        foreach (self::$modulos as $codigo => $modulo) {
            $modulo['codigo'] = $codigo;
            $v[] = $modulo;
        }

        return $v;
    }

    /**
     * Devuelve los modulos con los datos de cada una de sus secciones, listo
     * para que el front pinte una sub-pestana por modulo.
     *
     * @return array Lista de modulos con sus secciones resueltas
     */
    public function getModulosConDatos() {
        $modulos = [];

        foreach (self::getModulos() as $modulo) {
            $codigo = $modulo['codigo'];
            $modulo['avisos'] = [];

            foreach ($modulo['secciones'] as $seccion) {
                if ($seccion === 'generales') {
                    $modulo['generales'] = $this->getParametros('GENERAL', $codigo);
                } elseif ($seccion === 'respaldo') {
                    $modulo['respaldo'] = $this->getParametros('RESPALDO', $codigo);
                } elseif ($seccion === 'mix') {
                    // El mix vive en su propia tabla, no en la de clave/valor
                    $mix = $this->getMixCobro();
                    $modulo['mix'] = $mix;
                    $modulo['mix_validacion'] = self::validarMix($mix);
                } elseif ($seccion === 'cuentas' || $seccion === 'sucursales') {
                    // Las dos tablas de parametros de Saldos las lee su propia
                    // clase: son tablas del modulo Saldos y tener una segunda
                    // consulta aca las dejaria desincronizadas. Van dentro de un
                    // try porque su script puede no haberse corrido todavia, y
                    // eso no puede tumbar la pestana entera de Parametros.
                    try {
                        $modulo[$seccion] = ($seccion === 'cuentas')
                            ? $this->saldos()->getCuentas(false)
                            : array_values($this->saldos()->getParametrosSucursales(false));
                    } catch (Throwable $e) {
                        $modulo[$seccion] = [];
                        $modulo['avisos'][] = 'No se pudieron leer los parámetros de Saldos: '
                            . $e->getMessage();
                    }
                } elseif ($seccion === 'procesadoras' || $seccion === 'alicuotas') {
                    // Mismo criterio que Saldos: las tablas son del modulo
                    // Cob. Electronicos y las lee su propia clase.
                    //
                    // Las alicuotas se piden con el historico completo (no solo
                    // las activas): la vigencia vieja es lo que explica por que
                    // un movimiento de la semana pasada tiene otra tasa, y
                    // esconderla haria que ese neto pareciera un error.
                    try {
                        $modulo[$seccion] = ($seccion === 'procesadoras')
                            ? $this->cobElectronicos()->getProcesadoras(false)
                            : $this->cobElectronicos()->getAlicuotas(false);

                        if ($seccion === 'procesadoras') {
                            foreach ($this->cobElectronicos()->getAvisos() as $a) {
                                $modulo['avisos'][] = $a;
                            }
                        }
                    } catch (Throwable $e) {
                        $modulo[$seccion] = [];
                        $modulo['avisos'][] = 'No se pudieron leer los parámetros de '
                            . 'Cob. Electrónicos: ' . $e->getMessage();
                    }
                } elseif ($seccion === 'prechequeado') {
                    // Mismo criterio que los dos anteriores: la tabla es del
                    // modulo Echeqs y la lee su propia clase. Se piden TODOS los
                    // clientes, tambien los inhabilitados, porque el editor tiene
                    // que poder reactivar una baja.
                    try {
                        $modulo[$seccion] = $this->echeqs()->getClientesPrechequeado(false);

                        foreach ($this->echeqs()->getAvisos() as $a) {
                            $modulo['avisos'][] = $a;
                        }
                    } catch (Throwable $e) {
                        $modulo[$seccion] = [];
                        $modulo['avisos'][] = 'No se pudieron leer los clientes pre-chequeados: '
                            . $e->getMessage();
                    }
                } elseif ($seccion === 'cobranzas_clientes') {
                    try {
                        $modulo['cobranzas_clientes'] = $this->getCobranzasClientesConfig();
                    } catch (Throwable $e) {
                        $modulo['cobranzas_clientes'] = [];
                        $modulo['avisos'][] = 'No se pudieron leer los parámetros de Cobranzas: '
                            . $e->getMessage();
                    }
                }
            }

            $modulos[] = $modulo;
        }

        return $modulos;
    }

    /**
     * Puerta al modulo Saldos, para las secciones de Parametros que administran
     * sus tablas.
     *
     * El require va aca y no arriba porque Saldos ya requiere esta clase: con
     * los dos requires en la cabecera habria un ciclo de carga. Lazy tambien
     * evita pagar la carga del modulo cuando la pestana solo mira Ventas.
     *
     * @return Saldos
     */
    private function saldos() {
        require_once __DIR__ . '/Saldos.php';

        if ($this->saldos === null) {
            $this->saldos = new Saldos();
        }

        return $this->saldos;
    }

    /**
     * Puerta al modulo Cob. Electronicos, para las secciones de Parametros que
     * administran sus tablas. Mismo criterio -y mismo motivo del require lazy-
     * que saldos().
     *
     * @return CobElectronicos
     */
    private function cobElectronicos() {
        require_once __DIR__ . '/CobElectronicos.php';

        if ($this->cobElectronicos === null) {
            $this->cobElectronicos = new CobElectronicos();
        }

        return $this->cobElectronicos;
    }

    /**
     * Puerta al modulo Echeqs, para la sub-pestana que administra el maestro de
     * clientes pre-chequeados. Mismo criterio -y mismo motivo del require lazy-
     * que saldos() y cobElectronicos().
     *
     * @return Echeqs
     */
    private function echeqs() {
        require_once __DIR__ . '/Echeqs.php';

        if ($this->echeqs === null) {
            $this->echeqs = new Echeqs();
        }

        return $this->echeqs;
    }

    /**
     * Cache del chequeo de la columna MODULO.
     * null = todavia no se consulto, true/false = resultado.
     */
    private $tieneModulo = null;

    /** @var Saldos|null Puerta al modulo Saldos; la resuelve saldos() */
    private $saldos = null;

    /** @var CobElectronicos|null Puerta al modulo; la resuelve cobElectronicos() */
    private $cobElectronicos = null;

    /** @var Echeqs|null Puerta al modulo Echeqs; la resuelve echeqs() */
    private $echeqs = null;

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Indica si RO_T_CASHFLOW_PARAMETROS ya tiene la columna MODULO.
     *
     * La columna se agrega con sql/migracion_parametros_modulo.sql. Mientras no
     * este, el modulo sigue funcionando: se asume que todos los parametros son
     * de VENTAS (que es lo que son hoy) y se avisa por pantalla. Sin esto la
     * pestana moria con un 'Invalid column name' de ODBC.
     *
     * @param resource $cid Conexion abierta a central
     * @return bool True si la columna existe
     */
    private function tieneColumnaModulo($cid) {
        if ($this->tieneModulo !== null) {
            return $this->tieneModulo;
        }

        $sql = "SELECT COL_LENGTH('dbo.RO_T_CASHFLOW_PARAMETROS', 'MODULO') AS LARGO";
        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la columna MODULO'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tieneModulo = ($row !== null && $row !== false && $row['LARGO'] !== null);

        return $this->tieneModulo;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en la pestana
     * @return array Lista de mensajes
     */
    public function getAvisos() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $avisos = [];

        if (!$this->tieneColumnaModulo($cid)) {
            $avisos[] = 'Falta la columna MODULO en RO_T_CASHFLOW_PARAMETROS. '
                      . 'Los parámetros se están mostrando todos como Ventas. '
                      . 'Corré sql/migracion_parametros_modulo.sql para agruparlos por módulo.';
        }

        return $avisos;
    }

    /**
     * Devuelve los parametros, opcionalmente filtrados por grupo y/o modulo
     * @param string|null $grupo Grupo a filtrar (GENERAL, RESPALDO, ...)
     * @param string|null $modulo Modulo a filtrar (VENTAS, ...)
     * @return array Listado de parametros
     */
    public function getParametros($grupo = null, $modulo = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Mientras la columna MODULO no exista, se consulta sin ella y se asume
        // VENTAS: asi la pestana funciona igual antes de correr la migracion.
        $tieneModulo = $this->tieneColumnaModulo($cid);

        $sql = "SELECT CLAVE, VALOR, TIPO_DATO, DESCRIPCION, "
             . ($tieneModulo ? "MODULO, " : "")
             . "GRUPO, FECHA_UPDATE, USUARIO
                FROM RO_T_CASHFLOW_PARAMETROS";
        $where = [];
        $params = [];

        if ($grupo !== null) {
            $where[] = "GRUPO = ?";
            $params[] = $grupo;
        }

        if ($modulo !== null && $tieneModulo) {
            $where[] = "MODULO = ?";
            $params[] = $modulo;
        }

        if (count($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY " . ($tieneModulo ? "MODULO, " : "") . "GRUPO, CLAVE";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los parametros'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['FECHA_UPDATE']) && $row['FECHA_UPDATE'] instanceof DateTime) {
                $row['FECHA_UPDATE'] = $row['FECHA_UPDATE']->format('Y-m-d');
            }

            if (!$tieneModulo) {
                // Todos los parametros que existen hoy son del modulo de ventas
                $row['MODULO'] = 'VENTAS';
            }

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Devuelve los parametros como mapa CLAVE => VALOR, listo para las formulas
     * @return array Mapa asociativo de parametros
     */
    public function getParametrosMap() {
        $map = [];

        foreach ($this->getParametros() as $row) {
            $map[$row['CLAVE']] = $row['VALOR'];
        }

        return $map;
    }

    /**
     * Lee un parametro numerico del mapa
     * @param array $map Mapa devuelto por getParametrosMap()
     * @param string $clave Clave del parametro
     * @return float Valor numerico
     */
    public static function num($map, $clave) {
        if (!isset($map[$clave])) {
            throw new Exception("Falta el parametro '$clave' en RO_T_CASHFLOW_PARAMETROS");
        }

        return floatval($map[$clave]);
    }

    /**
     * Lee un parametro entero del mapa
     * @param array $map Mapa devuelto por getParametrosMap()
     * @param string $clave Clave del parametro
     * @return int Valor entero
     */
    public static function ent($map, $clave) {
        return intval(self::num($map, $clave));
    }

    /**
     * Guarda (o crea) un parametro
     * @param string $clave Clave del parametro
     * @param string $valor Valor a guardar
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveParametro($clave, $valor, $usuario = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sqlCheck = "SELECT CLAVE FROM RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$clave]);

        if ($stmtCheck === false) {
            throw new Exception($this->errorSql('Error al verificar el parametro'));
        }

        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        if (!$exists) {
            throw new Exception("El parametro '$clave' no existe");
        }

        $sql = "UPDATE RO_T_CASHFLOW_PARAMETROS
                SET VALOR = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE CLAVE = ?";

        $stmt = sqlsrv_query($cid, $sql, [$valor, $usuario, $clave]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el parametro'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Devuelve el mix de medios de cobro y plazos de acreditacion por canal
     * @param bool $soloActivos Si true, devuelve solo los medios activos
     * @return array Listado ordenado del mix
     */
    public function getMixCobro($soloActivos = false) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT ID, CANAL, MEDIO_PAGO, PORCENTAJE, DIAS_ACREDITACION, ACTIVO, ORDEN
                FROM RO_T_CASHFLOW_VENTAS_MIX";

        if ($soloActivos) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY ORDEN, CANAL, MEDIO_PAGO";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el mix de cobro'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['PORCENTAJE'] = floatval($row['PORCENTAJE']);
            $row['DIAS_ACREDITACION'] = intval($row['DIAS_ACREDITACION']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $row['ORDEN'] = intval($row['ORDEN']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Guarda una fila del mix de cobro
     * @param int $id ID de la fila en RO_T_CASHFLOW_VENTAS_MIX
     * @param float $porcentaje Porcentaje del mix (0 a 1)
     * @param int $diasAcreditacion Dias hasta la acreditacion
     * @param bool $activo Si el medio se usa en la proyeccion
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return bool True si se guardo correctamente
     */
    public function saveMixCobro($id, $porcentaje, $diasAcreditacion, $activo = true, $usuario = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "UPDATE RO_T_CASHFLOW_VENTAS_MIX
                SET PORCENTAJE = ?, DIAS_ACREDITACION = ?, ACTIVO = ?,
                    FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ?";

        $params = [
            floatval($porcentaje),
            intval($diasAcreditacion),
            $activo ? 1 : 0,
            $usuario,
            intval($id)
        ];

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el mix de cobro'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Agrega un medio de pago nuevo al mix de un canal.
     *
     * Entra desactivado y en cero: activarlo obliga a reacomodar los
     * porcentajes del canal para que vuelvan a sumar 100%, y esa validacion
     * corre al guardar. Asi agregar un medio nunca deja el mix invalido.
     *
     * @param string $canal Canal del modelo
     * @param string $medioPago Nombre del medio de pago
     * @param int $diasAcreditacion Dias hasta la acreditacion
     * @param string|null $usuario Usuario que edita (todavia no hay login)
     * @return int ID de la fila creada
     */
    public function addMixCobro($canal, $medioPago, $diasAcreditacion, $usuario = null) {
        $canal = trim($canal);
        $medioPago = trim($medioPago);

        if (!in_array($canal, self::CANALES)) {
            throw new Exception('Canal invalido: ' . $canal);
        }

        if ($medioPago === '') {
            throw new Exception('El medio de pago no puede estar vacio');
        }

        if (mb_strlen($medioPago) > 30) {
            throw new Exception('El medio de pago no puede superar los 30 caracteres');
        }

        $dias = intval($diasAcreditacion);

        if ($dias < 0) {
            throw new Exception('Los dias de acreditacion no pueden ser negativos');
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // La tabla tiene UNIQUE (CANAL, MEDIO_PAGO): se chequea antes para dar
        // un mensaje entendible en vez del error del indice.
        $sqlCheck = "SELECT ID, ACTIVO FROM RO_T_CASHFLOW_VENTAS_MIX
                     WHERE CANAL = ? AND MEDIO_PAGO = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$canal, $medioPago]);

        if ($stmtCheck === false) {
            throw new Exception($this->errorSql('Error al verificar el medio de pago'));
        }

        $existe = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        if ($existe) {
            $estado = intval($existe['ACTIVO']) === 1 ? 'activo' : 'inhabilitado';
            throw new Exception(
                'El canal ' . $canal . ' ya tiene el medio de pago "' . $medioPago
                . '" (' . $estado . ')'
            );
        }

        $sqlOrden = "SELECT ISNULL(MAX(ORDEN), 0) + 1 AS SIGUIENTE FROM RO_T_CASHFLOW_VENTAS_MIX";
        $stmtOrden = sqlsrv_query($cid, $sqlOrden);

        if ($stmtOrden === false) {
            throw new Exception($this->errorSql('Error al calcular el orden'));
        }

        $filaOrden = sqlsrv_fetch_array($stmtOrden, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtOrden);

        $orden = intval($filaOrden['SIGUIENTE']);

        $sql = "INSERT INTO RO_T_CASHFLOW_VENTAS_MIX
                    (CANAL, MEDIO_PAGO, PORCENTAJE, DIAS_ACREDITACION, ACTIVO, ORDEN,
                     FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, ?, 0, ?, 0, ?, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [$canal, $medioPago, $dias, $orden, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al agregar el medio de pago'));
        }

        $nuevo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($nuevo['ID']);
    }

    /**
     * Valida que el mix de cada canal sume 100%.
     *
     * Cuenta UNICAMENTE los medios activos: son los unicos que el motor usa
     * para convertir venta en cobranza. Un medio inhabilitado no suma, sin
     * importar que porcentaje tenga guardado.
     *
     * Un canal sin ningun medio activo tambien es invalido: su venta no se
     * convertiria en cobranza y el importe desapareceria del cashflow.
     *
     * @param array $mix Listado devuelto por getMixCobro()
     * @return array Mapa CANAL => ['suma', 'valido', 'activos']
     */
    public static function validarMix($mix) {
        $sumas = [];
        $activos = [];

        // Se parte de los cuatro canales del modelo: un canal que quedo sin
        // ninguna fila tiene que salir invalido, no ausente del resultado.
        foreach (self::CANALES as $canal) {
            $sumas[$canal] = 0;
            $activos[$canal] = 0;
        }

        foreach ($mix as $row) {
            $canal = $row['CANAL'];

            if (!isset($sumas[$canal])) {
                $sumas[$canal] = 0;
                $activos[$canal] = 0;
            }

            if (intval($row['ACTIVO']) !== 1) {
                continue;
            }

            $sumas[$canal] += floatval($row['PORCENTAJE']);
            $activos[$canal]++;
        }

        $resultado = [];

        foreach ($sumas as $canal => $suma) {
            $resultado[$canal] = [
                'suma' => $suma,
                'activos' => $activos[$canal],
                'valido' => ($activos[$canal] > 0) && (abs($suma - 1) < 0.000001)
            ];
        }

        return $resultado;
    }

    /**
     * Devuelve las participaciones fijas de respaldo como mapa CANAL => porcentaje
     * Se usan cuando el mes del anio anterior no tiene datos o su venta es cero.
     * @param array|null $map Mapa de parametros ya leido, o null para leerlo
     * @return array Mapa CANAL => porcentaje
     */
    public function getParticipacionRespaldo($map = null) {
        if ($map === null) {
            $map = $this->getParametrosMap();
        }

        $respaldo = [];

        foreach (self::CANALES as $canal) {
            $respaldo[$canal] = self::num($map, 'respaldo_' . strtolower($canal));
        }

        return $respaldo;
    }

    /**
     * Devuelve los feriados de comercio como lista de 'MM-DD'
     * Son los unicos dias del anio sin venta estimada.
     * @param array|null $map Mapa de parametros ya leido, o null para leerlo
     * @return array Lista de strings 'MM-DD'
     */
    public function getFeriadosComercio($map = null) {
        if ($map === null) {
            $map = $this->getParametrosMap();
        }

        if (!isset($map['feriados_comercio'])) {
            throw new Exception("Falta el parametro 'feriados_comercio' en RO_T_CASHFLOW_PARAMETROS");
        }

        $feriados = [];

        foreach (explode(',', $map['feriados_comercio']) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $feriados[] = $item;
            }
        }

        return $feriados;
    }

    /**
     * Devuelve la lista completa de clientes franquicia con su PPP calculado,
     * su PPP manual/editable, su PPP efectivo y sus escalas de descuento configuradas.
     * 
     * @return array Listado de configuración por cliente
     */
    public function getCobranzasClientesConfig() {
        require_once __DIR__ . '/Ingresos.php';
        $ingresos = new Ingresos();

        $ppps = $ingresos->getPPPClientes();
        $escalas = $ingresos->getEscalasDescuento();
        $paramsClientes = $ingresos->getParametrosClientes();

        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        // Obtener todas las franquicias desde GVA14
        $sql = "SELECT COD_CLIENT, RAZON_SOCI FROM GVA14 WHERE COD_CLIENT LIKE 'FR%' ORDER BY COD_CLIENT";
        $stmt = sqlsrv_query($cid, $sql);
        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer clientes de GVA14'));
        }

        $clientes = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = strtoupper(trim($row['COD_CLIENT']));
            $razon = trim($row['RAZON_SOCI']);

            $pppInfo = $ppps[$cod] ?? null;
            $paramInfo = $paramsClientes[$cod] ?? null;
            $escalasCli = $escalas[$cod] ?? [];

            // Aplanar escalas para la vista
            $escalasLista = [];
            foreach ($escalasCli as $medio => $tramos) {
                foreach ($tramos as $t) {
                    $t['medio_pago'] = $medio;
                    $escalasLista[] = $t;
                }
            }

            $pppCalc = $pppInfo ? intval($pppInfo['ppp_calculado']) : 0;
            $pppMan = ($pppInfo && $pppInfo['ppp_manual'] !== null) ? intval($pppInfo['ppp_manual']) : ($paramInfo['ppp_manual'] ?? null);
            $cantCobros = $pppInfo ? intval($pppInfo['cant_cobros']) : 0;
            $pppEfectivo = ($pppMan !== null && $pppMan > 0) ? $pppMan : ($pppCalc > 0 ? $pppCalc : ($paramInfo['dias_pp_max'] ?? 30));

            $clientes[] = [
                'cod_cliente' => $cod,
                'razon_social' => $razon,
                'ppp_calculado' => $pppCalc,
                'ppp_manual' => $pppMan,
                'ppp_efectivo' => $pppEfectivo,
                'cant_cobros' => $cantCobros,
                'medio_pago_default' => $paramInfo['medio_pago'] ?? 'ECHEQ',
                'dias_pp_max' => $paramInfo['dias_pp_max'] ?? 0,
                'desc_pp_max' => $paramInfo['desc_pp_max'] ?? 0,
                'escalas' => $escalasLista
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $clientes;
    }

    /**
     * Guarda o actualiza el PPP manual de un cliente en RO_T_PARAMETROS_DESC_CLIENTES.
     * 
     * @param string $codCliente Código de cliente
     * @param int|null $pppManual Valor del PPP manual (o null para volver al calculado)
     * @param string|null $usuario Usuario que realiza la acción
     * @return bool True si se guardó
     */
    public function savePPPManual($codCliente, $pppManual, $usuario = null) {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $cod = strtoupper(trim($codCliente));
        $val = ($pppManual !== null && $pppManual !== '' && intval($pppManual) > 0) ? intval($pppManual) : null;

        // Verificar si existe en RO_T_PARAMETROS_DESC_CLIENTES
        $sqlCheck = "SELECT ID FROM RO_T_PARAMETROS_DESC_CLIENTES WHERE COD_CLIENT = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$cod]);
        $exists = ($stmtCheck !== false) ? sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC) : false;
        if ($stmtCheck !== false) sqlsrv_free_stmt($stmtCheck);

        if ($exists) {
            $sql = "UPDATE RO_T_PARAMETROS_DESC_CLIENTES 
                    SET PPP_MANUAL = ?, FECHA_MOD = GETDATE() 
                    WHERE COD_CLIENT = ?";
            $params = [$val, $cod];
        } else {
            $sql = "INSERT INTO RO_T_PARAMETROS_DESC_CLIENTES (COD_CLIENT, PPP_MANUAL, DIAS_PP_MAX, DESC_PP_MAX, MEDIO_PAGO_DEFAULT, FECHA_MOD) 
                    VALUES (?, ?, 30, 0.08, 'ECHEQ', GETDATE())";
            $params = [$cod, $val];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);
        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el PPP manual'));
        }
        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Guarda o actualiza el medio de pago por defecto de un cliente en RO_T_PARAMETROS_DESC_CLIENTES.
     * 
     * @param string $codCliente Código de cliente
     * @param string $medioPago Medio de pago ('ECHEQ', 'TRANSFERENCIA')
     * @param string|null $usuario Usuario que realiza la acción
     * @return bool True si se guardó
     */
    public function saveMedioPagoCliente($codCliente, $medioPago, $usuario = null) {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $cod = strtoupper(trim($codCliente));
        $medio = strtoupper(trim($medioPago ?: 'ECHEQ'));

        // Verificar si existe en RO_T_PARAMETROS_DESC_CLIENTES
        $sqlCheck = "SELECT ID FROM RO_T_PARAMETROS_DESC_CLIENTES WHERE COD_CLIENT = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$cod]);
        $exists = ($stmtCheck !== false) ? sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC) : false;
        if ($stmtCheck !== false) sqlsrv_free_stmt($stmtCheck);

        if ($exists) {
            $sql = "UPDATE RO_T_PARAMETROS_DESC_CLIENTES 
                    SET MEDIO_PAGO_DEFAULT = ?, FECHA_MOD = GETDATE() 
                    WHERE COD_CLIENT = ?";
            $params = [$medio, $cod];
        } else {
            $sql = "INSERT INTO RO_T_PARAMETROS_DESC_CLIENTES (COD_CLIENT, PPP_MANUAL, DIAS_PP_MAX, DESC_PP_MAX, MEDIO_PAGO_DEFAULT, FECHA_MOD) 
                    VALUES (?, NULL, 30, 0.08, ?, GETDATE())";
            $params = [$cod, $medio];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);
        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el medio de pago'));
        }
        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Guarda o crea un tramo de escala de descuento para un cliente.
     * 
     * @param int|null $id ID del tramo (0 para nuevo)
     * @param string $codCliente Código de cliente
     * @param string $medioPago Medio de pago ('ECHEQ', 'TRANSFERENCIA')
     * @param int $diasDesde Días inicio del tramo
     * @param int $diasHasta Días fin del tramo
     * @param float $porcentajeDesc Porcentaje de descuento (ej: 8.00)
     * @param string|null $usuario Usuario que realiza la acción
     * @return int ID de la escala
     */
    public function saveEscalaDescuento($id, $codCliente, $medioPago, $diasDesde, $diasHasta, $porcentajeDesc, $usuario = null) {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $cod = strtoupper(trim($codCliente));
        $medio = strtoupper(trim($medioPago ?: 'ECHEQ'));
        $dDesde = intval($diasDesde);
        $dHasta = intval($diasHasta);
        $porc = floatval($porcentajeDesc);

        if ($dDesde < 0 || $dHasta < $dDesde) {
            throw new Exception('El rango de días no es válido: Días Desde debe ser >= 0 y <= Días Hasta');
        }

        if ($porc < 0 || $porc > 100) {
            throw new Exception('El porcentaje de descuento debe estar entre 0% y 100%');
        }

        if ($id && intval($id) > 0) {
            $sql = "UPDATE RO_T_CASHFLOW_COBRANZAS_PARAM_DESC 
                    SET COD_CLIENT = ?, MEDIO_PAGO = ?, DIAS_DESDE = ?, DIAS_HASTA = ?, PORCENTAJE_DESC = ?, ACTIVO = 1, FECHA_UPDATE = GETDATE(), USUARIO = ? 
                    WHERE ID = ?";
            $params = [$cod, $medio, $dDesde, $dHasta, $porc, $usuario, intval($id)];
            $stmt = sqlsrv_query($cid, $sql, $params);
            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al actualizar escala de descuento'));
            }
            sqlsrv_free_stmt($stmt);
            return intval($id);
        } else {
            $sql = "INSERT INTO RO_T_CASHFLOW_COBRANZAS_PARAM_DESC (COD_CLIENT, MEDIO_PAGO, DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC, ACTIVO, FECHA_UPDATE, USUARIO) 
                    OUTPUT INSERTED.ID
                    VALUES (?, ?, ?, ?, ?, 1, GETDATE(), ?)";
            $params = [$cod, $medio, $dDesde, $dHasta, $porc, $usuario];
            $stmt = sqlsrv_query($cid, $sql, $params);
            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al insertar escala de descuento'));
            }
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            return intval($row['ID']);
        }
    }

    /**
     * Elimina (baja lógica o física) una escala de descuento.
     * 
     * @param int $id ID de la escala
     * @return bool True si se eliminó
     */
    public function deleteEscalaDescuento($id) {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $sql = "DELETE FROM RO_T_CASHFLOW_COBRANZAS_PARAM_DESC WHERE ID = ?";
        $stmt = sqlsrv_query($cid, $sql, [intval($id)]);
        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al eliminar escala de descuento'));
        }
        sqlsrv_free_stmt($stmt);
        return true;
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
