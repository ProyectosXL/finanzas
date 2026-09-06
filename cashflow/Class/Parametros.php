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
     * ese MODULO en RO_T_CASHFLOW_PARAMETROS y agregar el <li> y el tab-pane en
     * Tabs/parametros.php.
     */
    private static $modulos = [
        'VENTAS' => [
            'nombre' => 'Ventas',
            'icono' => 'fa-arrow-trend-up',
            'descripcion' => 'Alimentan la proyección de ventas y cobranzas de la pestaña Ventas',
            'secciones' => ['generales', 'mix', 'respaldo']
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
                }
            }

            $modulos[] = $modulo;
        }

        return $modulos;
    }

    /**
     * Cache del chequeo de la columna MODULO.
     * null = todavia no se consulto, true/false = resultado.
     */
    private $tieneModulo = null;

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
