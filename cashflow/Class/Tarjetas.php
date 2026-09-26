<?php

require_once __DIR__ . '/TarjetasVencimiento.php';

/**
 * Tarjetas
 * El maestro de tarjetas: quien la tiene, de que banco es, que porcentaje de
 * cobertura lleva y que dia del mes vence su resumen.
 *
 * DE DONDE SALE CADA DATO, Y QUE SE PUEDE TIPEAR
 * ----------------------------------------------
 *   TIPO             se elige de tres. Decide EN QUE SUB-PESTANA aparece.
 *   COD_BANCO        se elige de BANCO (Tango). No se tipea.
 *   ID_USUARIO       se elige de RO_V_CASHFLOW_USUARIOS_TARJETAS. No se tipea.
 *   NOMBRE_USUARIO   sale de la vista. NO SE TIPEA, se guarda como copia.
 *   ULTIMOS_4        se tipea. Optativo, salvo el caso de abajo.
 *   PCT_COBERTURA    se tipea, en puntos.
 *   DIA_VENCIMIENTO  se tipea. Obligatorio.
 *
 * EL NOMBRE SE GUARDA IGUAL, COMO COPIA. La fila tiene que seguir siendo
 * legible el dia que ese usuario deje de estar activo en la vista -y entonces
 * desaparezca de ella-, porque un ID suelto no le dice nada a nadie. La pantalla
 * muestra el de la vista cuando lo encuentra, asi que un cambio de nombre se ve
 * enseguida. Mismo criterio y misma razon que NOM_PROVEE en el maestro de
 * fleteros.
 *
 * EL TIPO NO ES QUIEN ES EL USUARIO
 * ---------------------------------
 * La vista une directores y supervisoras sin columna de tipo, y el TIPO de la
 * tarjeta es una decision independiente: la gerenta de administracion y finanzas
 * figura al lado de las supervisoras y su tarjeta es CORPORATIVA. Solo las de
 * tipo SUPERVISORA se cruzan por nombre contra el maestro de supervisoras; para
 * los otros dos el nombre es descriptivo y no cruza contra nada.
 *
 * LA IDENTIDAD: BANCO + USUARIO + ULTIMOS 4
 * -----------------------------------------
 * ULTIMOS_4 es optativo en general y OBLIGATORIO cuando el mismo usuario ya
 * tiene otra tarjeta en el mismo banco: sin eso, las dos filas son
 * indistinguibles y nadie puede saber a cual pertenece un resumen.
 *
 * SE VALIDA ACA Y NO SOLO EN LA PANTALLA, porque lo que manda el navegador es un
 * pedido y no una autorizacion. El indice unico de la tabla es la tercera red, y
 * lo que hace este chequeo es dar el mensaje que explica el problema antes de que
 * la base tire un error de indice que no lo explica.
 *
 * UNA TARJETA NO TIENE MONEDA
 * ---------------------------
 * La misma tarjeta puede tener consumos en pesos y en dolares, asi que la moneda
 * vive en el resumen. Ver Class/TarjetasResumen.php.
 *
 * SIN BAJAS FISICAS
 * -----------------
 * ACTIVA = 0 en vez de DELETE. Una tarjeta inactiva deja de proyectar y CONSERVA
 * SUS RESUMENES, que es lo que hace que el historico siga sirviendo para
 * explicar con que numero se proyecto en su momento. Reactivar es volver a
 * guardarla, igual que un fletero.
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * Sin la tabla, getTarjetas() devuelve vacio -no hay ninguna tarjeta, que es lo
 * cierto- y lo que falla, con el motivo, es guardar. Sin la vista de usuarios no
 * se puede dar de alta, porque el nombre sale de ahi. Mismo patron que el resto
 * del modulo.
 *
 * UNA CONSULTA A LA VEZ, Y NO ES UN DETALLE DE ESTILO
 * --------------------------------------------------
 * El driver sqlsrv usa POOL DE CONEXIONES, asi que Conexion::conectar() puede
 * devolver la MISMA conexion fisica que ya tiene un statement con resultados
 * pendientes. Lanzar otra consulta en el medio lo invalida, y el fetch siguiente
 * falla con "supplied resource is not a valid ss_sqlsrv_stmt resource".
 *
 * Por eso getTarjetas() vacia su statement ANTES de pedir los bancos y los
 * usuarios. Es el mismo orden que usa Logistica::getFleteros() con los nombres de
 * CPA01, y el sintoma es de los peores: aparece solo cuando la segunda consulta
 * existe, asi que un metodo que funcionaba se rompe al agregarle una lectura.
 */
class Tarjetas {

    /** El maestro, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS';

    /**
     * La vista de usuarios. NO LA CREA ESTE REPO: existe y se mantiene aparte.
     * Sus columnas son ID_DIRECTOR y NOMBRE -el nombre de la primera lo pone el
     * primer SELECT del UNION, que es el de directores- y valen para los dos
     * tipos de usuario.
     */
    const VISTA_USUARIOS = 'RO_V_CASHFLOW_USUARIOS_TARJETAS';

    /**
     * Los tres tipos, con su nombre para la pantalla y la sub-pestana en la que
     * se ven.
     *
     * TIENE QUE COINCIDIR CON EL CHECK de la tabla. Un tipo que este aca y no
     * alla lo rechaza la base al guardar; uno que este alla y no aca no lo puede
     * cargar nadie. Mismo criterio que ProveedoresExclusion::MODULOS.
     */
    const TIPOS = [
        'SUPERVISORA' => 'Supervisora',
        'CORPORATIVA' => 'Corporativa',
        'SOCIO' => 'Socio'
    ];

    /** Tope del % de cobertura. Ver validarPct() */
    const PCT_MAX = 100;

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    /** @var bool|null Cache del chequeo de la vista */
    private $vista = null;

    /** @var array|null Cache de usuarios(): una lectura por pedido */
    private $usuarios = null;

    /** @var array|null Cache de bancos() */
    private $bancos = null;

    /** @var array Avisos acumulados */
    private $avisos = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LO PURO: VALIDACION
       Se prueba sin base, que es donde vive lo que se puede romper.
       ==================================================================== */

    /**
     * El tipo, validado contra TIPOS.
     *
     * Estatica y pura. Un tipo desconocido es un pedido armado a mano: se rechaza
     * antes de llegar a la base, con la lista de los validos.
     *
     * @param mixed $tipo
     * @return string
     */
    public static function validarTipo($tipo) {
        $t = strtoupper(trim((string) $tipo));

        if (!isset(self::TIPOS[$t])) {
            throw new Exception('"' . $tipo . '" no es un tipo de tarjeta. Los válidos son: '
                . implode(', ', array_keys(self::TIPOS)) . '.');
        }

        return $t;
    }

    /**
     * Los ultimos cuatro digitos, validados. Devuelve null cuando no se cargaron.
     *
     * EXACTAMENTE CUATRO DIGITOS, y se guarda como texto: '0012' no es lo mismo
     * que 12, y un entero perderia el cero de adelante, que es parte del numero
     * impreso en la tarjeta.
     *
     * Estatica y pura.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function validarUltimos4($valor) {
        $v = trim((string) $valor);

        if ($v === '') {
            return null;
        }

        if (!preg_match('/^\d{4}$/', $v)) {
            throw new Exception('Los últimos 4 dígitos tienen que ser exactamente cuatro '
                . 'números. Se recibió "' . $valor . '".');
        }

        return $v;
    }

    /**
     * El % de cobertura, validado. En PUNTOS: 5 es 5 %.
     *
     * EL RANGO NO ES DECORATIVO. Un 500 tipeado de mas -o un 5 donde se quiso
     * poner 0,5- no falla en la base: es un numero valido, y el egreso proyectado
     * sale multiplicado por seis sin que nada avise. Mismo criterio que
     * Inflacion::validar().
     *
     * CERO ES VALIDO y significa lo que dice: sin cobertura. A diferencia de las
     * horas de un fletero, donde un cero era indistinguible de un olvido, acá la
     * mayoria de las tarjetas no lleva cobertura, asi que cero es el caso normal
     * y no un dato que falta.
     *
     * Estatica y pura.
     *
     * @param mixed $valor
     * @return float
     */
    public static function validarPct($valor) {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        if (!is_numeric($valor)) {
            throw new Exception('El % de cobertura tiene que ser un número. Se recibió "'
                . $valor . '".');
        }

        $pct = floatval($valor);

        if ($pct < 0 || $pct > self::PCT_MAX) {
            throw new Exception('El % de cobertura tiene que estar entre 0 y ' . self::PCT_MAX
                . '. Se recibió ' . $pct . '.');
        }

        return $pct;
    }

    /**
     * El codigo de banco, normalizado. La validacion CONTRA BANCO la hace
     * guardarTarjeta(), que es donde hay conexion.
     *
     * Estatica y pura.
     *
     * @param mixed $cod
     * @return string
     */
    public static function validarBanco($cod) {
        $c = trim((string) $cod);

        if ($c === '') {
            throw new Exception('Falta el banco de la tarjeta.');
        }

        return $c;
    }

    /**
     * Como se muestra una tarjeta en una linea: '•••• 1234 · SANTANDER S.A.'
     *
     * ESTA ESCRITO UNA VEZ Y EN EL BACKEND porque lo usan la grilla de
     * Parametros, las tres sub-pestanas, los avisos del proveedor y el tooltip de
     * las celdas del tablero. Cinco lugares armando el mismo rotulo divergen en
     * la primera correccion, y lo que quedaria distinto es como se nombra la
     * misma tarjeta en dos pantallas.
     *
     * SIN LOS ULTIMOS 4 SE DICE QUE NO ESTAN, y no se deja el espacio vacio: una
     * tarjeta sin identificar y una con los cuatro digitos en blanco se leen
     * igual, y no son lo mismo.
     *
     * Estatica y pura.
     *
     * @param array $t Fila de tarjeta
     * @return string
     */
    public static function rotulo($t) {
        $partes = [];

        $partes[] = empty($t['ULTIMOS_4']) ? '•••• (sin identificar)' : '•••• ' . $t['ULTIMOS_4'];

        $banco = isset($t['DESC_BANCO']) && trim((string) $t['DESC_BANCO']) !== ''
            ? trim((string) $t['DESC_BANCO'])
            : ('banco ' . (isset($t['COD_BANCO']) ? $t['COD_BANCO'] : '?'));

        $partes[] = $banco;

        if (!empty($t['NOMBRE_USUARIO'])) {
            $partes[] = trim((string) $t['NOMBRE_USUARIO']);
        }

        return implode(' · ', $partes);
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_tarjetas.sql */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                return $this->tabla = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    /** @return string El aviso de script faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe ' . self::TABLA . ': corré sql/cashflow_tarjetas.sql contra la '
            . 'base central. Mientras tanto no se pueden dar de alta tarjetas, así que las tres '
            . 'sub-pestañas se ven vacías y la fila del tablero va en cero.';
    }

    /**
     * Si la vista de usuarios existe. SE PREGUNTA Y NO SE CREA: la vista se
     * mantiene fuera de este repo.
     *
     * @return bool
     */
    public function vistaUsuariosCreada() {
        if ($this->vista !== null) {
            return $this->vista;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::VISTA_USUARIOS . "') AS V");

            if ($stmt === false) {
                return $this->vista = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->vista = ($row && $row['V'] !== null);
        } catch (Throwable $e) {
            $this->vista = false;
        }

        return $this->vista;
    }

    /** @return string El aviso de vista faltante, o '' */
    public function avisoSinVista() {
        return $this->vistaUsuariosCreada() ? '' :
            'No existe ' . self::VISTA_USUARIOS . ', que es de donde salen los usuarios de las '
            . 'tarjetas. Esta vista NO la crea ningún script de este repo: se mantiene aparte, '
            . 'y hay que pedir que la creen. Mientras falte no se pueden dar de alta tarjetas; '
            . 'las que ya están cargadas se ven con el nombre guardado.';
    }

    /**
     * Los usuarios que pueden tener una tarjeta: directores y supervisoras
     * activos.
     *
     * DEVUELVE VACIO SI LA VISTA NO EXISTE, sin lanzar: es el mismo criterio de
     * DolarFuturo::curva(). Quien consume avisa con avisoSinVista().
     *
     * @return array Mapa ID => nombre
     */
    public function usuarios() {
        if ($this->usuarios !== null) {
            return $this->usuarios;
        }

        $this->usuarios = [];

        if (!$this->vistaUsuariosCreada()) {
            return $this->usuarios;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID_DIRECTOR, NOMBRE FROM dbo." . self::VISTA_USUARIOS
          . " ORDER BY NOMBRE");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los usuarios de tarjetas'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $this->usuarios[intval($row['ID_DIRECTOR'])] = trim((string) $row['NOMBRE']);
        }

        sqlsrv_free_stmt($stmt);

        return $this->usuarios;
    }

    /**
     * Los bancos de Tango, para el desplegable del alta.
     *
     * SE TRAEN TODOS y no solo los habilitados: una tarjeta cargada contra un
     * banco que despues se deshabilito tiene que seguir mostrando su
     * descripcion, y filtrar aca la dejaria sin nombre.
     *
     * @return array Mapa COD_BANCO => DESC_BANCO
     */
    public function bancos() {
        if ($this->bancos !== null) {
            return $this->bancos;
        }

        $this->bancos = [];

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT COD_BANCO, DESC_BANCO FROM dbo.BANCO ORDER BY DESC_BANCO");

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer los bancos'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $this->bancos[trim((string) $row['COD_BANCO'])] = trim((string) $row['DESC_BANCO']);
            }

            sqlsrv_free_stmt($stmt);
        } catch (Throwable $e) {
            /* BANCO caido no tumba la pantalla: las tarjetas se ven con el codigo
               en vez de la descripcion, y se avisa. Mismo criterio que
               ProveedoresTango::disponible(). */
            $this->avisos[] = 'No se pudo leer el maestro de bancos de Tango (' . $e->getMessage()
                . '). Las tarjetas se ven con el código de banco en vez de su nombre, y no se '
                . 'pueden dar de alta: el código no tiene contra qué validarse.';
            $this->bancos = [];
        }

        return $this->bancos;
    }

    /** @return bool Si el maestro de bancos se pudo leer */
    public function bancosDisponibles() {
        return !empty($this->bancos());
    }

    /**
     * Las tarjetas cargadas, con la descripcion de su banco y si su usuario
     * sigue en la vista.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar.
     *
     * @param bool $soloActivas
     * @param string|null $tipo Filtra por tipo, o null para todos
     * @return array Lista de filas
     */
    public function getTarjetas($soloActivas = false, $tipo = null) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $where = [];
        $params = [];

        if ($soloActivas) {
            $where[] = 'ACTIVA = 1';
        }

        if ($tipo !== null) {
            $where[] = 'TIPO = ?';
            $params[] = self::validarTipo($tipo);
        }

        $sql = "SELECT ID, TIPO, COD_BANCO, ID_USUARIO, NOMBRE_USUARIO, ULTIMOS_4,
                       PCT_COBERTURA, DIA_VENCIMIENTO, ACTIVA,
                       USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF
                FROM dbo." . self::TABLA
              . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where))
              . " ORDER BY TIPO, NOMBRE_USUARIO, ULTIMOS_4, ID";

        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las tarjetas'));
        }

        /* SE VACIA EL STATEMENT ANTES DE PEDIR NADA MAS, y no es prolijidad: el
           driver sqlsrv usa POOL DE CONEXIONES, asi que Conexion::conectar()
           puede devolver la MISMA conexion que ya tiene este statement pendiente.
           Cualquier consulta en el medio -los bancos, los usuarios- lo invalida, y
           el fetch siguiente falla con "supplied resource is not a valid
           ss_sqlsrv_stmt resource".
           Es el mismo orden que usa Logistica::getFleteros() con los nombres de
           CPA01, y por el mismo motivo. */
        $crudas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $crudas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        // Recien ahora, con el statement cerrado, se pueden leer las dos fuentes.
        $bancos = $this->bancos();
        $usuarios = $this->usuarios();
        $filas = [];

        foreach ($crudas as $row) {
            $filas[] = $this->fila($row, $bancos, $usuarios);
        }

        return $filas;
    }

    /**
     * Una tarjeta por su ID, o null si no esta.
     *
     * DEVOLVER null NO ES UN ERROR: es el resultado de una busqueda, y es lo que
     * guardarTarjeta() usa para decidir si es alta o correccion.
     *
     * @param mixed $id
     * @return array|null
     */
    public function getTarjeta($id) {
        if (!$this->tablaCreada()) {
            return null;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, TIPO, COD_BANCO, ID_USUARIO, NOMBRE_USUARIO, ULTIMOS_4,
                    PCT_COBERTURA, DIA_VENCIMIENTO, ACTIVA,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA . " WHERE ID = ?",
            [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la tarjeta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? $this->fila($row, $this->bancos(), $this->usuarios()) : null;
    }

    /**
     * Las tarjetas activas indexadas por ID, para quien tiene que resolver
     * muchos resumenes o muchos vinculos.
     *
     * @param string|null $tipo
     * @return array Mapa ID => fila
     */
    public function porId($soloActivas = true, $tipo = null) {
        $mapa = [];

        foreach ($this->getTarjetas($soloActivas, $tipo) as $t) {
            $mapa[$t['ID']] = $t;
        }

        return $mapa;
    }

    /**
     * Normaliza una fila cruda y le pega lo que la pantalla necesita.
     *
     * @param array $row
     * @param array $bancos Mapa COD => DESC
     * @param array $usuarios Mapa ID => nombre
     * @return array
     */
    private function fila($row, $bancos, $usuarios) {
        $cod = trim((string) $row['COD_BANCO']);
        $idUsuario = intval($row['ID_USUARIO']);

        $f = [
            'ID' => intval($row['ID']),
            'TIPO' => trim((string) $row['TIPO']),
            'TIPO_NOMBRE' => isset(self::TIPOS[trim((string) $row['TIPO'])])
                ? self::TIPOS[trim((string) $row['TIPO'])] : trim((string) $row['TIPO']),
            'COD_BANCO' => $cod,
            'DESC_BANCO' => isset($bancos[$cod]) ? $bancos[$cod] : null,
            'ID_USUARIO' => $idUsuario,

            /* EL NOMBRE GUARDADO Y EL DE LA VISTA VIAJAN LOS DOS. El de la vista
               es la fuente y manda cuando esta; el guardado es el respaldo. Que
               difieran no es un error: es que el usuario cambio de nombre, y eso
               se ve. */
            'NOMBRE_USUARIO' => trim((string) $row['NOMBRE_USUARIO']),
            'NOMBRE_VISTA' => isset($usuarios[$idUsuario]) ? $usuarios[$idUsuario] : null,

            /* EL USUARIO YA NO ESTA EN LA VISTA: se marca y NO se da de baja
               sola. Puede ser una supervisora que dejo de estar activa, y
               decidir que hacer con su tarjeta es de una persona. Mismo criterio
               que un fletero cuyo codigo ya no esta en CPA01. */
            'USUARIO_EN_VISTA' => isset($usuarios[$idUsuario]),

            'ULTIMOS_4' => ($row['ULTIMOS_4'] === null) ? null : trim((string) $row['ULTIMOS_4']),
            'PCT_COBERTURA' => floatval($row['PCT_COBERTURA']),
            'DIA_VENCIMIENTO' => intval($row['DIA_VENCIMIENTO']),
            'ACTIVA' => (intval($row['ACTIVA']) === 1),
            'USUARIO_ALTA' => $row['USUARIO_ALTA'],
            'USUARIO_MODIF' => $row['USUARIO_MODIF'],
            'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
            'FECHA_MODIF' => self::momento($row['FECHA_MODIF'])
        ];

        /* El nombre de la vista MANDA cuando esta: asi un cambio de nombre se ve
           enseguida sin tener que reguardar la tarjeta. */
        if ($f['NOMBRE_VISTA'] !== null) {
            $f['NOMBRE_USUARIO'] = $f['NOMBRE_VISTA'];
        }

        $f['ROTULO'] = self::rotulo($f);

        return $f;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Da de alta una tarjeta, o corrige los datos de una existente.
     *
     * SIRVE TAMBIEN PARA REACTIVAR UNA BAJA, y la respuesta dice cual de los tres
     * casos fue: una accion "reactivar" aparte obligaria a la pantalla a saber de
     * antemano en que estado estaba. Mismo criterio que
     * Logistica::guardarFletero().
     *
     * EL BANCO Y EL USUARIO SE VUELVEN A VALIDAR CONTRA SU FUENTE. Que el
     * desplegable los haya ofrecido no autoriza nada.
     *
     * SIN LA VISTA DE USUARIOS NO SE DA DE ALTA: el nombre SALE de ahi, asi que
     * sin ella no hay nada que guardar salvo un ID que nadie puede verificar. Es
     * lo mismo que hace el alta de un fletero cuando CPA01 no responde.
     *
     * @param int|null $id null para un alta
     * @param array $datos tipo, cod_banco, id_usuario, ultimos_4, pct_cobertura,
     *                     dia_vencimiento
     * @param string|null $usuario Usuario de la sesion, para la auditoria
     * @return array ['id', 'rotulo', 'nuevo' => bool, 'reactivada' => bool]
     */
    public function guardarTarjeta($id, $datos, $usuario = null) {
        $this->exigirTabla();

        $tipo = self::validarTipo(isset($datos['tipo']) ? $datos['tipo'] : null);
        $banco = self::validarBanco(isset($datos['cod_banco']) ? $datos['cod_banco'] : null);
        $ultimos = self::validarUltimos4(isset($datos['ultimos_4']) ? $datos['ultimos_4'] : null);
        $pct = self::validarPct(isset($datos['pct_cobertura']) ? $datos['pct_cobertura'] : null);
        $dia = TarjetasVencimiento::validarDia(
            isset($datos['dia_vencimiento']) ? $datos['dia_vencimiento'] : null);

        $idUsuario = intval(isset($datos['id_usuario']) ? $datos['id_usuario'] : 0);

        if (!$this->vistaUsuariosCreada()) {
            throw new Exception($this->avisoSinVista());
        }

        $usuarios = $this->usuarios();

        if (!isset($usuarios[$idUsuario])) {
            throw new Exception('El usuario con ID ' . $idUsuario . ' no está en '
                . self::VISTA_USUARIOS . '. Elegilo de la lista: son los directores y las '
                . 'supervisoras activos.');
        }

        $nombre = $usuarios[$idUsuario];

        /* EL BANCO CONTRA BANCO. Si el maestro no se pudo leer no se da de alta:
           un codigo de banco que nadie verifico deja una tarjeta que la pantalla
           muestra sin nombre para siempre. */
        $bancos = $this->bancos();

        if (empty($bancos)) {
            throw new Exception('No se pudo leer el maestro de bancos de Tango, así que no hay '
                . 'contra qué validar el banco. Probá de nuevo en un rato.');
        }

        if (!isset($bancos[$banco])) {
            throw new Exception('El código de banco "' . $banco . '" no existe en el maestro de '
                . 'bancos de Tango. Elegilo de la lista.');
        }

        /* LOS ULTIMOS 4 PASAN A SER OBLIGATORIOS cuando ya hay otra tarjeta del
           mismo usuario en el mismo banco. Ver el encabezado: el indice unico es
           la red, esto es el mensaje que la explica. */
        $this->exigirUltimos4($banco, $idUsuario, $ultimos, $id);

        $cid = $this->conectar();
        $actual = ($id === null || $id === '') ? null : $this->getTarjeta($id);

        if ($actual === null) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (TIPO, COD_BANCO, ID_USUARIO, NOMBRE_USUARIO, ULTIMOS_4,
                     PCT_COBERTURA, DIA_VENCIMIENTO, ACTIVA, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?);
                 SELECT CAST(SCOPE_IDENTITY() AS INT) AS ID;",
                [$tipo, $banco, $idUsuario, $nombre, $ultimos, $pct, $dia, $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de alta la tarjeta'));
            }

            /* El INSERT y el SELECT van en el mismo lote: SCOPE_IDENTITY() es por
               conexion y por ambito, y Conexion::conectar() abre una conexion
               nueva en cada llamada, asi que una segunda consulta podria caer en
               otra y devolver NULL. */
            sqlsrv_next_result($stmt);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $nuevoId = ($row && $row['ID'] !== null) ? intval($row['ID']) : null;

            return [
                'id' => $nuevoId,
                'rotulo' => self::rotulo(['ULTIMOS_4' => $ultimos, 'COD_BANCO' => $banco,
                                          'DESC_BANCO' => $bancos[$banco],
                                          'NOMBRE_USUARIO' => $nombre]),
                'nuevo' => true,
                'reactivada' => false
            ];
        }

        /* REACTIVA SI ESTABA DE BAJA. A diferencia de un fletero, NO se limpia
           ninguna fecha de baja: esta tabla no la tiene, porque una tarjeta que
           se reactiva no pierde nada -sus resumenes siguen ahi- y FECHA_MODIF
           alcanza para saber cuando se la toco. */
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET TIPO = ?, COD_BANCO = ?, ID_USUARIO = ?, NOMBRE_USUARIO = ?, ULTIMOS_4 = ?,
                 PCT_COBERTURA = ?, DIA_VENCIMIENTO = ?, ACTIVA = 1,
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE ID = ?",
            [$tipo, $banco, $idUsuario, $nombre, $ultimos, $pct, $dia, $usuario,
             intval($actual['ID'])]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la tarjeta'));
        }

        sqlsrv_free_stmt($stmt);

        return [
            'id' => intval($actual['ID']),
            'rotulo' => self::rotulo(['ULTIMOS_4' => $ultimos, 'COD_BANCO' => $banco,
                                      'DESC_BANCO' => $bancos[$banco],
                                      'NOMBRE_USUARIO' => $nombre]),
            'nuevo' => false,
            'reactivada' => !$actual['ACTIVA']
        ];
    }

    /**
     * Da de baja o reactiva una tarjeta.
     *
     * NO BORRA NADA: una tarjeta inactiva deja de proyectar y conserva sus
     * resumenes, que son el historico con el que se explica con que numero se
     * proyecto en su momento.
     *
     * @param mixed $id
     * @param bool $activa
     * @param string|null $usuario
     * @return array ['id', 'activa', 'rotulo']
     */
    public function activarTarjeta($id, $activa, $usuario = null) {
        $this->exigirTabla();

        $actual = $this->getTarjeta($id);

        if ($actual === null) {
            throw new Exception('La tarjeta ' . $id . ' no está cargada.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET ACTIVA = ?, USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE ID = ?",
            [$activa ? 1 : 0, $usuario, intval($actual['ID'])]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al cambiar el estado de la tarjeta'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id' => intval($actual['ID']), 'activa' => (bool) $activa,
                'rotulo' => $actual['ROTULO']];
    }

    /**
     * Exige los ultimos 4 digitos cuando el usuario ya tiene otra tarjeta en el
     * mismo banco.
     *
     * DA EL MENSAJE QUE EL INDICE UNICO NO PUEDE DAR. La base rechaza la fila
     * -los NULL cuentan como iguales en un UNIQUE- pero con un error de indice
     * que no explica nada. Esto explica el problema y dice como resolverlo.
     *
     * MIRA TAMBIEN LAS INACTIVAS, igual que el indice: una tarjeta de baja sigue
     * ocupando su identidad, porque reactivarla es volver a guardarla y sus
     * resumenes siguen colgando de ella.
     *
     * @param string $banco
     * @param int $idUsuario
     * @param string|null $ultimos
     * @param mixed $id La tarjeta que se esta guardando, para excluirse
     */
    private function exigirUltimos4($banco, $idUsuario, $ultimos, $id) {
        if ($ultimos !== null) {
            return;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COUNT(*) AS N FROM dbo." . self::TABLA . "
             WHERE COD_BANCO = ? AND ID_USUARIO = ? AND ID <> ?",
            [$banco, $idUsuario, ($id === null || $id === '') ? 0 : intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tarjetas del usuario'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($row && intval($row['N']) > 0) {
            $nombre = isset($this->usuarios()[$idUsuario])
                ? $this->usuarios()[$idUsuario] : ('el usuario ' . $idUsuario);

            throw new Exception($nombre . ' ya tiene otra tarjeta en este banco, así que hacen '
                . 'falta los últimos 4 dígitos para poder distinguirlas: sin ellos no habría '
                . 'forma de saber a cuál corresponde cada resumen.');
        }
    }

    /* ====================================================================
       AVISOS
       ==================================================================== */

    /**
     * Los avisos del maestro: el script que falta, la vista que falta, los
     * bancos que no se pudieron leer y las tarjetas cuyo usuario o banco ya no
     * existen.
     *
     * @return array Lista de mensajes
     */
    public function avisos() {
        $avisos = [];

        $sinTabla = $this->avisoSinTabla();

        if ($sinTabla !== '') {
            $avisos[] = $sinTabla;

            // Sin la tabla no hay tarjetas que revisar: los demas avisos no
            // tendrian sobre que hablar.
            return $avisos;
        }

        $sinVista = $this->avisoSinVista();

        if ($sinVista !== '') {
            $avisos[] = $sinVista;
        }

        // Los que dejo bancos() al fallar.
        foreach ($this->avisos as $a) {
            $avisos[] = $a;
        }

        $tarjetas = $this->getTarjetas(false);
        $sinUsuario = [];
        $sinBanco = [];

        foreach ($tarjetas as $t) {
            if (!$t['USUARIO_EN_VISTA'] && $this->vistaUsuariosCreada()) {
                $sinUsuario[] = $t['NOMBRE_USUARIO'] . ' (' . $t['ROTULO'] . ')';
            }

            if ($t['DESC_BANCO'] === null && $this->bancosDisponibles()) {
                $sinBanco[] = $t['ROTULO'];
            }
        }

        /* SE NOMBRAN, hasta tres. Son pocas y es lo que alguien va a querer
           saber; un conteo suelto obliga a buscarlas en la grilla igual. */
        if (!empty($sinUsuario)) {
            $avisos[] = count($sinUsuario) . ' tarjeta(s) tienen un usuario que ya no está '
                . 'activo en ' . self::VISTA_USUARIOS . ': ' . self::primeros($sinUsuario)
                . '. Se siguen viendo con el nombre guardado y NO se dan de baja solas: si esa '
                . 'persona dejó la empresa, la tarjeta se da de baja a mano.';
        }

        if (!empty($sinBanco)) {
            $avisos[] = count($sinBanco) . ' tarjeta(s) tienen un código de banco que ya no está '
                . 'en el maestro de Tango: ' . self::primeros($sinBanco) . '. Se ven con el '
                . 'código en vez del nombre.';
        }

        return $avisos;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Los primeros tres de una lista, con cuantos quedan */
    private static function primeros($lista) {
        $primeros = array_slice($lista, 0, 3);

        return implode('; ', $primeros)
            . (count($lista) > 3 ? '; y ' . (count($lista) - 3) . ' más' : '');
    }

    /** Lanza si la tabla no existe. Guardar sin tabla no es un caso a tolerar */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** Lleva a 'Y-m-d H:i' lo que devuelve sqlsrv para un DATETIME */
    private static function momento($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 16);
    }

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::TABLA . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
