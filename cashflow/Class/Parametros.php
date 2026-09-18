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
     * Parametros que ya nadie lee y que la pantalla no muestra.
     *
     * LA FILA NO SE BORRA de RO_T_CASHFLOW_PARAMETROS: queda el valor que
     * alguien habia cargado, por si hace falta reconstruir con que numero se
     * proyecto en su momento. Lo que se saca es el campo editable, porque un
     * campo que se puede tocar y que no cambia nada es peor que no tenerlo.
     *
     *   'dias_prechequeado' -> los dias de pre-chequeado pasaron a ser POR
     *   CLIENTE, en RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE.DIAS_PRECHEQUEADO. Un
     *   unico numero global obligaba a elegir cual de todos los clientes
     *   quedaba bien calculado. Ver README-ventas.md.
     *
     *   'comex_tipo_cambio_usd' -> los pagos a proveedores del exterior pasaron
     *   a valuarse con la CURVA DE DOLAR FUTURO ROFEX, segun el mes de la fecha
     *   estimada de pago de cada contenedor. Un unico tipo de cambio global
     *   convertia por igual el pago del mes que viene y el de dentro de once
     *   meses, que es la cuenta que el encabezado de Cotizacion describe como
     *   incorrecta: no proyecta, reexpresa toda la serie a moneda de hoy.
     *
     *   EL DOLAR FUTURO ES EL UNICO CRITERIO Y POR ESO ESTE PARAMETRO SE
     *   RETIRA. Dejarlo editable con la curva ya funcionando seria peor que
     *   borrarlo: un campo que se puede tocar, que parece decidir la valuacion
     *   de Comex y que no cambia nada. Ver Class/DolarFuturo.php.
     */
    const RETIRADOS = ['dias_prechequeado', 'comex_tipo_cambio_usd'];

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
        'PROV_LOCALES' => [
            'nombre' => 'Prov. Locales',
            'icono' => 'fa-file-invoice-dollar',
            'descripcion' => 'Las listas de valores con las que se clasifica a cada proveedor: '
                . 'rubro económico, rubro, centro de costos, plazo de pago y criterio de '
                . 'distribución. Alimentan el alta manual del maestro de Proveedores Locales '
                . 'y la validación de su importación',
            'secciones' => ['prov_locales_opciones']
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
                        $config = $this->getCobranzasClientesConfig();
                        $modulo['cobranzas_clientes'] = $config['grupos'];

                        foreach ($config['avisos'] as $a) {
                            $modulo['avisos'][] = $a;
                        }
                    } catch (Throwable $e) {
                        $modulo['cobranzas_clientes'] = [];
                        $modulo['avisos'][] = 'No se pudieron leer los parámetros de Cobranzas: '
                            . $e->getMessage();
                    }
                } elseif ($seccion === 'prov_locales_opciones') {
                    /* Mismo criterio que Saldos, Cob. Electrónicos y
                       Pre-chequeado: la tabla es del módulo Proveedores Locales
                       y la lee su propia clase. Va dentro de un try porque su
                       script puede no haberse corrido todavía, y eso no puede
                       tumbar la pestaña entera de Parámetros.

                       LOS USOS VIAJAN CON LAS LISTAS. Sin ellos, dar de baja un
                       valor es a ciegas: no hay forma de saber si saca una
                       opción que no usa nadie o una que tienen doscientos
                       proveedores, que van a quedar todos fuera de lista. */
                    try {
                        $modulo[$seccion] = $this->provLocalesOpciones();

                        foreach ($modulo[$seccion]['avisos'] as $a) {
                            $modulo['avisos'][] = $a;
                        }
                    } catch (Throwable $e) {
                        $modulo[$seccion] = ['tipos' => [], 'listas' => [], 'usos' => [],
                                             'avisos' => [], 'tabla_creada' => false];
                        $modulo['avisos'][] = 'No se pudieron leer las listas de opciones de '
                            . 'Proveedores Locales: ' . $e->getMessage();
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
     * Las cinco listas de opciones del maestro de Proveedores Locales, con sus
     * avisos y con CUANTOS proveedores usan cada valor.
     *
     * LOS USOS SALEN DEL MAESTRO y por eso esta pantalla lo lee: sin ese
     * numero, dar de baja un valor es a ciegas. No hay forma de saber si se
     * saca una opcion que no usa nadie o una que tienen doscientos proveedores,
     * que van a quedar todos marcados como fuera de lista.
     *
     * Si el maestro no se puede leer, las listas se muestran igual y los usos
     * van vacios: un control que falla no puede llevarse puesta la pantalla que
     * administra las listas.
     *
     * @return array ['tipos', 'listas', 'usos', 'avisos', 'tabla_creada']
     */
    private function provLocalesOpciones() {
        require_once __DIR__ . '/ProveedoresOpciones.php';
        require_once __DIR__ . '/ProveedoresCategorias.php';

        $opciones = new ProveedoresOpciones();
        $usos = [];

        try {
            $usos = ProveedoresOpciones::usos((new ProveedoresCategorias())->mapa());
        } catch (Throwable $e) {
            $usos = [];
        }

        return [
            'tipos' => ProveedoresOpciones::TIPOS,
            'tipo_plazo' => ProveedoresOpciones::TIPO_PLAZO,
            'listas' => $opciones->listas(),
            'usos' => $usos,
            'avisos' => $opciones->getAvisos(),
            'tabla_creada' => $opciones->tablaCreada()
        ];
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

            if (in_array($row['CLAVE'], self::RETIRADOS, true)) {
                continue;
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

    /* ====================================================================
       GESTION DE COBRANZA FRANQUICIAS: HELPERS PUROS

       La tarjeta de Parametros -> Cobranzas agrupa a los clientes por grupo
       empresario y solo lista las franquicias habilitadas en el direccionario
       de sucursales. Las dos decisiones estan aca, sin base, para poder
       probarlas; las lecturas SQL solo juntan los datos.
       ==================================================================== */

    /**
     * Lleva las filas del direccionario de sucursales a un mapa por cliente.
     *
     * Un cliente con mas de una sucursal habilitada queda en UNA entrada, con
     * los numeros y las descripciones concatenados: la tarjeta es por cliente,
     * no por local. Una fila sin COD_CLIENT no se puede cruzar y se ignora.
     *
     * @param array $filas Filas NRO_SUCURSAL, COD_CLIENT, DESC_SUCURSAL
     * @return array Mapa COD_CLIENT => ['nro_sucursal', 'desc_sucursal', 'cant_sucursales']
     */
    public static function mapaSucursales($filas) {
        $mapa = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $cod = strtoupper(trim((string) (isset($f['COD_CLIENT']) ? $f['COD_CLIENT'] : '')));

            if ($cod === '') {
                continue;
            }

            $nro = trim((string) (isset($f['NRO_SUCURSAL']) ? $f['NRO_SUCURSAL'] : ''));
            $desc = trim((string) (isset($f['DESC_SUCURSAL']) ? $f['DESC_SUCURSAL'] : ''));

            if (!isset($mapa[$cod])) {
                $mapa[$cod] = ['nro_sucursal' => [], 'desc_sucursal' => [], 'cant_sucursales' => 0];
            }

            if ($nro !== '') {
                $mapa[$cod]['nro_sucursal'][] = $nro;
            }

            if ($desc !== '') {
                $mapa[$cod]['desc_sucursal'][] = $desc;
            }

            $mapa[$cod]['cant_sucursales']++;
        }

        foreach ($mapa as $cod => $m) {
            $mapa[$cod]['nro_sucursal'] = implode(', ', $m['nro_sucursal']);
            $mapa[$cod]['desc_sucursal'] = implode(' / ', $m['desc_sucursal']);
        }

        return $mapa;
    }

    /**
     * Se queda con los clientes que tienen una sucursal habilitada en el
     * direccionario, y les cuelga la sucursal.
     *
     * INFORMAR DE MAS ANTES QUE VACIO: si el direccionario no se pudo leer
     * (null) o no devolvio ninguna franquicia (vacio, que es un problema de la
     * consulta y no un hecho), se devuelven TODOS los clientes con un aviso.
     * Una tarjeta en blanco sin explicacion dejaria sin editar el PPP de todo
     * el mundo por una caida del servidor de locales.
     *
     * Los descartados se cuentan y no se avisan: son las franquicias dadas de
     * baja, y decirlo en cada carga seria ruido sobre algo que es asi a
     * proposito. La pantalla lo muestra como nota al pie.
     *
     * @param array $pppPorCliente Mapa COD_CLIENT => datos, de Ingresos::getPPPClientes()
     * @param array|null $mapa Mapa de mapaSucursales(), o null si no se pudo leer
     * @return array ['clientes' => mapa filtrado, 'avisos' => [...], 'descartados' => int]
     */
    public static function filtrarFranquiciasActivas($pppPorCliente, $mapa) {
        $pppPorCliente = is_array($pppPorCliente) ? $pppPorCliente : [];
        $avisos = [];

        if ($mapa === null) {
            $avisos[] = 'No se pudo leer el directorio de sucursales (servidor \'locales\'): se '
                . 'muestran todas las franquicias de Tango, también las dadas de baja.';
        } elseif (empty($mapa)) {
            $avisos[] = 'El directorio de sucursales no devolvió ninguna franquicia habilitada: se '
                . 'muestran todas las franquicias de Tango, también las dadas de baja.';
        }

        if (!empty($avisos)) {
            foreach ($pppPorCliente as $cod => $c) {
                $pppPorCliente[$cod]['nro_sucursal'] = '';
                $pppPorCliente[$cod]['desc_sucursal'] = '';
            }

            return ['clientes' => $pppPorCliente, 'avisos' => $avisos, 'descartados' => 0];
        }

        $clientes = [];
        $descartados = 0;

        foreach ($pppPorCliente as $cod => $c) {
            if (!isset($mapa[$cod])) {
                $descartados++;
                continue;
            }

            $c['nro_sucursal'] = $mapa[$cod]['nro_sucursal'];
            $c['desc_sucursal'] = $mapa[$cod]['desc_sucursal'];
            $clientes[$cod] = $c;
        }

        return ['clientes' => $clientes, 'avisos' => [], 'descartados' => $descartados];
    }

    /**
     * Junta los clientes en UNA FILA POR AGRUPADOR (grupo empresario, o el
     * cliente si no tiene grupo), que es como se edita el PPP.
     *
     * El PPP calculado y el manual son del grupo, asi que se muestran una vez.
     * El efectivo del grupo se calcula sin DIAS_PP_MAX -ese respaldo es por
     * cliente-; el de cada cliente va en su fila, y solo difiere del grupo
     * cuando el grupo no tiene ni manual ni calculado.
     *
     * @param array $pppPorCliente Mapa COD_CLIENT => datos (con sucursal si se cruzo)
     * @return array Lista de grupos ordenada por nombre, cada uno con 'clientes'
     */
    public static function agruparPorAgrupador($pppPorCliente) {
        require_once __DIR__ . '/Ingresos.php';

        $grupos = [];

        foreach ((is_array($pppPorCliente) ? $pppPorCliente : []) as $c) {
            $agrup = $c['cod_agrup'];

            if (!isset($grupos[$agrup])) {
                $grupos[$agrup] = [
                    'cod_agrup' => $agrup,
                    'nombre_agrup' => $c['nombre_agrup'],
                    'es_grupo' => !empty($c['es_grupo']),
                    'ppp_calculado' => intval($c['ppp_calculado']),
                    'cant_recibos' => intval($c['cant_recibos']),
                    'cant_clientes_ppp' => intval($c['cant_clientes_ppp']),
                    'ppp_manual' => $c['ppp_manual'],
                    'ppp_efectivo' => Ingresos::pppEfectivo($c['ppp_manual'], $c['ppp_calculado'], null),
                    'clientes' => []
                ];
            }

            $grupos[$agrup]['clientes'][] = [
                'cod_cliente' => $c['cod_cliente'],
                'razon_social' => $c['razon_social'],
                'nro_sucursal' => isset($c['nro_sucursal']) ? $c['nro_sucursal'] : '',
                'desc_sucursal' => isset($c['desc_sucursal']) ? $c['desc_sucursal'] : '',
                'medio_pago_default' => isset($c['medio_pago_default']) ? $c['medio_pago_default'] : 'ECHEQ',
                'dias_pp_max' => intval($c['dias_pp_max']),
                'desc_pp_max' => isset($c['desc_pp_max']) ? floatval($c['desc_pp_max']) : 0,
                'ppp_efectivo' => intval($c['ppp_efectivo'])
            ];
        }

        foreach ($grupos as $agrup => $g) {
            usort($grupos[$agrup]['clientes'], function ($a, $b) {
                return strcmp($a['cod_cliente'], $b['cod_cliente']);
            });
        }

        $lista = array_values($grupos);

        usort($lista, function ($a, $b) {
            $n = strcasecmp($a['nombre_agrup'], $b['nombre_agrup']);

            return ($n !== 0) ? $n : strcmp($a['cod_agrup'], $b['cod_agrup']);
        });

        return $lista;
    }

    /**
     * Las franquicias habilitadas del direccionario de sucursales, desde el
     * servidor 'locales'.
     *
     * Es la unica lectura de Parametros fuera de 'central', y va con el prefijo
     * de Conexion::prefijoLocales() por el mismo motivo que en Saldos: en DEV
     * la tabla se alcanza por linked server. Cualquier falla devuelve null y
     * la decide filtrarFranquiciasActivas(), que no deja la tarjeta vacia.
     *
     * @return array|null Mapa de mapaSucursales(), o null si no se pudo leer
     */
    private function getFranquiciasActivas() {
        $cid = $this->conn->conectar('locales');

        if (!$cid) {
            error_log('Parametros: no se pudo conectar a locales para leer las franquicias');

            return null;
        }

        $p = method_exists($this->conn, 'prefijoLocales') ? $this->conn->prefijoLocales() : '';

        $sql = "SELECT NRO_SUCURSAL, COD_CLIENT, DESC_SUCURSAL
                FROM {$p}SUCURSALES_LAKERS
                WHERE CANAL = 'FRANQUICIAS' AND HABILITADO = 1 AND NRO_SUC_MADRE IS NULL
                ORDER BY COD_CLIENT, NRO_SUCURSAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            error_log('Parametros: ' . $this->errorSql('Error al leer las franquicias del direccionario'));

            return null;
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return self::mapaSucursales($filas);
    }

    /**
     * Todo lo que necesita la tarjeta "Gestion de Cobranza Franquicias":
     * los grupos empresarios con su PPP y sus clientes habilitados.
     *
     * El PPP viene de Ingresos::getPPPClientes() -ya por grupo-; aca solo se
     * cruza contra el direccionario y se agrupa. Los avisos dicen si falta
     * correr el script del PPP o si el direccionario no se pudo leer.
     *
     * @return array ['grupos', 'avisos', 'total_clientes', 'total_grupos', 'descartados']
     */
    public function getCobranzasClientesConfig() {
        require_once __DIR__ . '/Ingresos.php';
        $ingresos = new Ingresos();

        $ppps = $ingresos->getPPPClientes();
        $params = $ingresos->getParametrosClientes();

        // El medio de pago es informativo (la escala de descuento es general)
        // pero describe como opera el cliente, asi que se sigue mostrando.
        foreach ($ppps as $cod => $c) {
            $ppps[$cod]['medio_pago_default'] = isset($params[$cod]) ? $params[$cod]['medio_pago'] : 'ECHEQ';
            $ppps[$cod]['desc_pp_max'] = isset($params[$cod]) ? $params[$cod]['desc_pp_max'] : 0;
        }

        $filtrado = self::filtrarFranquiciasActivas($ppps, $this->getFranquiciasActivas());
        $grupos = self::agruparPorAgrupador($filtrado['clientes']);

        return [
            'grupos' => $grupos,
            'avisos' => array_merge($ingresos->getAvisosPPP(), $filtrado['avisos']),
            'total_clientes' => count($filtrado['clientes']),
            'total_grupos' => count($grupos),
            'descartados' => $filtrado['descartados']
        ];
    }

    /**
     * Guarda el PPP manual de un GRUPO EMPRESARIO en
     * RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO.
     *
     * Es un UPSERT sobre COD_AGRUP. Un valor vacio o <= 0 se guarda como NULL
     * -vuelve al calculado- y la fila queda, con quien y cuando lo dejo asi.
     * Reemplaza al PPP manual por cliente de RO_T_PARAMETROS_DESC_CLIENTES,
     * que ya no se lee.
     *
     * @param string $codAgrup Grupo empresario, o el cliente si no tiene grupo
     * @param int|null $pppManual Valor, o null/vacio para volver al calculado
     * @param string|null $usuario
     * @return bool
     */
    public function savePPPManualGrupo($codAgrup, $pppManual, $usuario = null) {
        $cid = $this->conn->conectar('central');
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        $agrup = strtoupper(trim((string) $codAgrup));

        if ($agrup === '') {
            throw new Exception('Falta el grupo empresario al que aplicar el PPP');
        }

        $val = ($pppManual !== null && $pppManual !== '' && intval($pppManual) > 0)
            ? intval($pppManual) : null;

        $stmtCheck = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO', 'U') AS T");
        $existeTabla = ($stmtCheck !== false)
            && ($row = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC)) && $row['T'] !== null;
        if ($stmtCheck !== false) sqlsrv_free_stmt($stmtCheck);

        if (!$existeTabla) {
            throw new Exception('No existe la tabla RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO. Corré '
                . 'sql/cashflow_cobranzas_ppp_grupo.sql contra la base central.');
        }

        $stmtCheck = sqlsrv_query($cid,
            "SELECT COD_AGRUP FROM RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO WHERE COD_AGRUP = ?", [$agrup]);
        $exists = ($stmtCheck !== false) ? sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC) : false;
        if ($stmtCheck !== false) sqlsrv_free_stmt($stmtCheck);

        if ($exists) {
            $sql = "UPDATE RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO
                    SET PPP_MANUAL = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE COD_AGRUP = ?";
            $params = [$val, $usuario, $agrup];
        } else {
            $sql = "INSERT INTO RO_T_CASHFLOW_COBRANZAS_PPP_GRUPO (COD_AGRUP, PPP_MANUAL, FECHA_UPDATE, USUARIO)
                    VALUES (?, ?, GETDATE(), ?)";
            $params = [$agrup, $val, $usuario];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);
        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el PPP manual del grupo ' . $agrup));
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

    /* ====================================================================
       ESCALA DE DESCUENTO GENERAL

       Es UNA escala para todos los clientes, sin medio de pago. Antes habia
       una por cliente y por medio en RO_T_CASHFLOW_COBRANZAS_PARAM_DESC: esa
       tabla queda con sus datos pero ya no se lee. Ver README-cobranzas-fr.md.

       Toda la escala se guarda de una sola vez y no tramo por tramo. Es la
       unica forma de poder validar que no se solape ni deje huecos: un tramo
       aislado no dice nada, la escala completa si. Va en una transaccion
       porque el guardado reemplaza los tramos, y una escala a medio escribir
       dejaria facturas sin descuento sin que nadie se entere.
       ==================================================================== */

    /**
     * La escala general, tal como la muestra el editor.
     *
     * @return array Lista de tramos ordenados por dias_desde
     */
    public function getEscalaDescuentoGeneral() {
        require_once __DIR__ . '/Ingresos.php';

        $ingresos = new Ingresos();

        return $ingresos->getEscalasDescuento();
    }

    /**
     * Reemplaza la escala general completa.
     *
     * VALIDA EN EL SERVIDOR. El JS espeja la validacion para poder bloquear el
     * boton y explicar por que, pero el endpoint es alcanzable sin pasar por la
     * pantalla: es el mismo criterio del editor de estructura del tablero.
     *
     * @param array $tramos Lista con dias_desde, dias_hasta, porcentaje_desc
     * @param string|null $usuario
     * @return array La escala guardada
     */
    public function saveEscalaDescuentoGeneral($tramos, $usuario = null) {
        require_once __DIR__ . '/Ingresos.php';

        $lista = is_array($tramos) ? array_values($tramos) : [];

        if (empty($lista)) {
            throw new Exception('La escala no puede quedar vacía: sin tramos, '
                . 'todas las facturas irían con 0% de descuento.');
        }

        $errores = Ingresos::validarEscala($lista);

        if (!empty($errores)) {
            throw new Exception(implode(' ', $errores));
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        usort($lista, function ($a, $b) {
            return intval($a['dias_desde']) - intval($b['dias_desde']);
        });

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción de la escala'));
        }

        try {
            // Baja logica de lo que habia: la escala vieja queda para poder
            // auditar con que porcentajes se proyecto hasta hoy.
            $stmt = sqlsrv_query($cid,
                "UPDATE RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC
                 SET ACTIVO = 0, USUARIO = ?, FECHA_MOD = GETDATE()
                 WHERE ACTIVO = 1",
                [$usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de baja la escala anterior'));
            }

            sqlsrv_free_stmt($stmt);

            foreach ($lista as $t) {
                $stmt = sqlsrv_query($cid,
                    "INSERT INTO RO_T_CASHFLOW_COBRANZAS_ESCALA_DESC
                        (DIAS_DESDE, DIAS_HASTA, PORCENTAJE_DESC, ACTIVO, USUARIO, FECHA_MOD)
                     VALUES (?, ?, ?, 1, ?, GETDATE())",
                    [intval($t['dias_desde']), intval($t['dias_hasta']),
                     floatval($t['porcentaje_desc']), $usuario]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al guardar un tramo de la escala'));
                }

                sqlsrv_free_stmt($stmt);
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $this->getEscalaDescuentoGeneral();
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
