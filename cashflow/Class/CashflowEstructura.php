<?php

require_once __DIR__ . '/CashflowRegistry.php';

/**
 * CashflowEstructura
 * Configuracion del tablero de Cashflow: que secciones y que filas lo forman.
 *
 * Es la capa de CONFIGURACION, separada del calculo (Class/Cashflow.php) y de
 * la presentacion (Tabs/cashflow.php + Js/Cashflow.js). Ninguna fila ni seccion
 * esta escrita en el codigo: todo sale de RO_T_CASHFLOW_CONF_SECCION y
 * RO_T_CASHFLOW_CONF_FILA, y se administra desde la pestana Parametros.
 *
 * COMO SE DEFINE UNA FILA CALCULADA, SIN LENGUAJE DE FORMULAS
 * ----------------------------------------------------------
 * El alcance de las filas derivadas es POSICIONAL: sale de combinar el ROL de
 * la seccion con el TIPO de la fila, resuelto en el orden
 * (SECCION.ORDEN, FILA.ORDEN).
 *
 *   SUBTOTAL    -> las filas de movimiento de su propia seccion y de las
 *                  secciones hijas (en cascada por ID_PADRE)
 *   FLUJO_NETO  -> todas las filas de movimiento de secciones ROL='MOVIMIENTO'
 *                  que esten POR ENCIMA de ella
 *   SALDO_FINAL -> lo mismo, mas las secciones ROL='SALDO'
 *
 * No se guarda ninguna referencia fila->fila ni una lista de secciones a sumar.
 * Por eso no puede haber referencias colgadas, ni ciclos entre filas, ni una
 * formula que quede apuntando a algo que se renombro.
 *
 * SI LAS TABLAS NO EXISTEN
 * ------------------------
 * Las lecturas devuelven vacio y getAvisos() dice que hay que correr el script,
 * en vez de romper. Es un unico chequeo con OBJECT_ID; no hace falta el sondeo
 * por columna que hace Parametros::tieneColumnaModulo(), que existe solo porque
 * RO_T_CASHFLOW_PARAMETROS es anterior a su columna MODULO en bases ya
 * desplegadas. Estas tablas nacen completas.
 */
class CashflowEstructura {

    /** Tipos de fila validos */
    const TIPOS = [
        'SALDO_INICIAL', 'INGRESO', 'EGRESO', 'SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL'
    ];

    /** Roles de seccion validos */
    const ROLES = ['SALDO', 'MOVIMIENTO', 'DERIVADO'];

    /** Tipos que aportan flujo y por lo tanto llevan signo */
    const TIPOS_MOVIMIENTO = ['INGRESO', 'EGRESO'];

    /** Tipos que necesitan un origen de datos */
    const TIPOS_CON_ORIGEN = ['SALDO_INICIAL', 'INGRESO', 'EGRESO'];

    /** Tipos que calcula el motor y no traen datos de ningun modulo */
    const TIPOS_DERIVADOS = ['SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL'];

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de las tablas */
    private $tablas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       HELPERS DE TIPO
       Los usa tanto el validador como el motor.
       ==================================================================== */

    /**
     * Signo con el que la fila entra en las sumas.
     * El signo lo determina el TIPO y no una columna aparte: una columna
     * permitiria configurar "un Ingreso que resta", que no significa nada.
     *
     * @param string $tipo
     * @return int 1, -1 o 0
     */
    public static function signo($tipo) {
        if ($tipo === 'INGRESO') {
            return 1;
        }

        if ($tipo === 'EGRESO') {
            return -1;
        }

        return 0;
    }

    /** @param string $tipo @return bool Si la calcula el motor */
    public static function esDerivada($tipo) {
        return in_array($tipo, self::TIPOS_DERIVADOS, true);
    }

    /** @param string $tipo @return bool Si es una fila de saldo (su total es un cierre, no una suma) */
    public static function esSaldo($tipo) {
        return ($tipo === 'SALDO_INICIAL' || $tipo === 'SALDO_FINAL');
    }

    /**
     * Normaliza un texto a codigo interno: mayusculas, sin acentos y solo
     * [A-Z0-9_]. El codigo es una clave que referencia la configuracion, asi
     * que tiene que ser seguro; el servidor lo normaliza siempre, sin importar
     * lo que haya mandado el cliente.
     *
     * @param string $texto
     * @return string Codigo de hasta 30 caracteres, o '' si no queda nada
     */
    public static function slug($texto) {
        $t = (string) $texto;

        $t = strtr($t, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'
        ]);

        $t = strtoupper($t);
        $t = preg_replace('/[^A-Z0-9]+/', '_', $t);
        $t = preg_replace('/_+/', '_', $t);
        $t = trim($t, '_');

        return substr($t, 0, 30);
    }

    /**
     * @param string $codigo
     * @return bool Si el codigo sirve como clave interna
     */
    public static function codigoValido($codigo) {
        return is_string($codigo)
            && $codigo !== ''
            && strlen($codigo) <= 30
            && preg_match('/^[A-Z][A-Z0-9_]*$/', $codigo) === 1;
    }

    /* ====================================================================
       LECTURAS
       ==================================================================== */

    /**
     * Si las tablas de configuracion ya se crearon.
     *
     * @return bool
     */
    public function tablasCreadas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_SECCION', 'U') AS S,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') AS F";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de estructura'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ($row && $row['S'] !== null && $row['F'] !== null);

        return $this->tablas;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en pantalla.
     *
     * @return array Lista de mensajes
     */
    public function getAvisos() {
        $avisos = [];

        if (!$this->tablasCreadas()) {
            $avisos[] = 'Todavía no existen las tablas de estructura del Cashflow. '
                      . 'Corré sql/cashflow_estructura.sql contra la base central '
                      . 'para crear las secciones y filas del tablero.';
        }

        return $avisos;
    }

    /**
     * Secciones del tablero.
     *
     * @param bool $soloActivas true para el motor, false para el editor. Es el
     *        mismo criterio que Parametros::getMixCobro($soloActivos): el
     *        tablero muestra solo lo activo y el editor tiene que poder ver lo
     *        inhabilitado para reactivarlo.
     * @return array Filas de RO_T_CASHFLOW_CONF_SECCION
     */
    public function getSecciones($soloActivas = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO
                FROM RO_T_CASHFLOW_CONF_SECCION";

        if ($soloActivas) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY ORDEN, CODIGO";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las secciones del cashflow'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ORDEN'] = intval($row['ORDEN']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Filas del tablero.
     *
     * @param bool $soloActivas true para el motor, false para el editor
     * @return array Filas de RO_T_CASHFLOW_CONF_FILA
     */
    public function getFilas($soloActivas = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $sql = "SELECT ID, CODIGO, NOMBRE, SECCION, TIPO, COMPUTA,
                       ORIGEN_PROVIDER, ORIGEN_SERIE, ORDEN, ACTIVO
                FROM RO_T_CASHFLOW_CONF_FILA";

        if ($soloActivas) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY ORDEN, CODIGO";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las filas del cashflow'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ID'] = intval($row['ID']);
            $row['ORDEN'] = intval($row['ORDEN']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $row['COMPUTA'] = intval($row['COMPUTA']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Secciones y filas ordenadas como se dibujan: por orden de seccion y,
     * dentro de cada una, por orden de fila. Es el orden que usa el motor para
     * resolver las filas derivadas, que dependen de la posicion.
     *
     * @param bool $soloActivas
     * @return array ['secciones' => [...], 'filas' => [...]]
     */
    public function getEstructura($soloActivas = false) {
        $secciones = $this->getSecciones($soloActivas);
        $filas = $this->getFilas($soloActivas);

        $posSeccion = [];

        foreach ($secciones as $i => $s) {
            $posSeccion[$s['CODIGO']] = $i;
        }

        // Una fila cuya seccion no esta en la lista (inactiva o inexistente) va
        // al final: el validador ya la marca como error, pero el orden tiene que
        // ser estable igual.
        $fin = count($secciones);

        usort($filas, function ($a, $b) use ($posSeccion, $fin) {
            $pa = isset($posSeccion[$a['SECCION']]) ? $posSeccion[$a['SECCION']] : $fin;
            $pb = isset($posSeccion[$b['SECCION']]) ? $posSeccion[$b['SECCION']] : $fin;

            if ($pa !== $pb) {
                return $pa - $pb;
            }

            if ($a['ORDEN'] !== $b['ORDEN']) {
                return $a['ORDEN'] - $b['ORDEN'];
            }

            return strcmp($a['CODIGO'], $b['CODIGO']);
        });

        return ['secciones' => $secciones, 'filas' => $filas];
    }

    /* ====================================================================
       VALIDACION
       ==================================================================== */

    /**
     * Valida una estructura completa. Estatica y pura: no toca la base, asi que
     * el controller la puede correr sobre el estado RESULTANTE simulado de un
     * guardado antes de escribir nada, igual que hace
     * ParametrosController con el mix de cobro.
     *
     * Siembra primero una entrada por cada fila y seccion recibida, de modo que
     * lo que falta se informe como INVALIDO y no como ausente. Es la misma idea
     * que Parametros::validarMix().
     *
     * @param array $secciones Filas de CONF_SECCION
     * @param array $filas Filas de CONF_FILA
     * @param array|null $providers Mapa codigo => ['disponible'=>bool,'series'=>[]].
     *        null usa CashflowRegistry. Se puede inyectar para poder probar.
     * @return array ['valido','errores','advertencias','por_fila','por_seccion']
     */
    public static function validar($secciones, $filas, $providers = null) {
        if ($providers === null) {
            $providers = [];

            foreach (CashflowRegistry::todos() as $p) {
                $providers[$p['codigo']] = $p;
            }
        }

        $r = [
            'valido' => true,
            'errores' => [],
            'advertencias' => [],
            'por_fila' => [],
            'por_seccion' => []
        ];

        /* ---- Siembra ---------------------------------------------------- */
        foreach ($filas as $f) {
            $r['por_fila'][$f['ID']] = ['errores' => [], 'advertencias' => []];
        }

        foreach ($secciones as $s) {
            $r['por_seccion'][$s['CODIGO']] = ['errores' => [], 'advertencias' => []];
        }

        /* ---- Indices ---------------------------------------------------- */
        $porCodigoSeccion = [];
        $seccionesActivas = [];

        foreach ($secciones as $s) {
            $porCodigoSeccion[$s['CODIGO']] = $s;

            if (intval($s['ACTIVO']) === 1) {
                $seccionesActivas[] = $s['CODIGO'];
            }
        }

        /* ---- Secciones -------------------------------------------------- */
        $vistosSeccion = [];

        foreach ($secciones as $s) {
            $cod = $s['CODIGO'];
            $clave = strtoupper($cod);

            if (isset($vistosSeccion[$clave])) {
                self::errorSeccion($r, $cod, 'El código de sección "' . $cod . '" está repetido.');
            }

            $vistosSeccion[$clave] = true;

            if (!self::codigoValido($cod)) {
                self::errorSeccion($r, $cod, 'El código de sección "' . $cod . '" no es válido: '
                    . 'debe empezar con una letra y usar sólo letras, números y guión bajo.');
            }

            if (trim((string) $s['NOMBRE']) === '') {
                self::errorSeccion($r, $cod, 'La sección "' . $cod . '" no tiene nombre.');
            }

            if (!in_array($s['ROL'], self::ROLES, true)) {
                self::errorSeccion($r, $cod, 'La sección "' . $cod . '" tiene un rol desconocido: '
                    . '"' . $s['ROL'] . '".');
            }

            if (!empty($s['ID_PADRE']) && !isset($porCodigoSeccion[$s['ID_PADRE']])) {
                self::errorSeccion($r, $cod, 'La sección "' . $cod . '" cuelga de "'
                    . $s['ID_PADRE'] . '", que no existe.');
            }
        }

        self::detectarCiclos($r, $secciones);

        /* ---- Filas ------------------------------------------------------ */
        $vistosFila = [];
        $origenesUsados = [];
        $saldosIniciales = [];
        $movimientosPorSeccion = [];

        foreach ($filas as $f) {
            $id = $f['ID'];
            $cod = $f['CODIGO'];
            $activa = (intval($f['ACTIVO']) === 1);
            $clave = strtoupper((string) $cod);

            if (isset($vistosFila[$clave])) {
                self::errorFila($r, $id, 'El código de fila "' . $cod . '" está repetido.');
            }

            $vistosFila[$clave] = true;

            if (!self::codigoValido($cod)) {
                self::errorFila($r, $id, 'El código de fila "' . $cod . '" no es válido: debe '
                    . 'empezar con una letra y usar sólo letras, números y guión bajo.');
            }

            if (trim((string) $f['NOMBRE']) === '') {
                self::errorFila($r, $id, 'La fila "' . $cod . '" no tiene nombre.');
            }

            if (!in_array($f['TIPO'], self::TIPOS, true)) {
                self::errorFila($r, $id, 'La fila "' . $cod . '" tiene un tipo desconocido: '
                    . '"' . $f['TIPO'] . '".');
                continue;
            }

            /* Seccion */
            if (!isset($porCodigoSeccion[$f['SECCION']])) {
                if ($activa) {
                    self::errorFila($r, $id, 'La fila "' . $cod . '" está en la sección "'
                        . $f['SECCION'] . '", que no existe.');
                }

                continue;
            }

            $seccion = $porCodigoSeccion[$f['SECCION']];

            if ($activa && intval($seccion['ACTIVO']) !== 1) {
                self::errorFila($r, $id, 'La fila "' . $cod . '" está activa pero su sección "'
                    . $seccion['NOMBRE'] . '" quedaría inhabilitada: su importe desaparecería '
                    . 'del tablero.');
            }

            /* Origen de datos */
            if (in_array($f['TIPO'], self::TIPOS_CON_ORIGEN, true)) {
                $prov = $f['ORIGEN_PROVIDER'];
                $serie = $f['ORIGEN_SERIE'];

                if (empty($prov) || empty($serie)) {
                    if ($activa) {
                        self::errorFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" no tiene origen '
                            . 'de datos, así que se mostraría siempre en cero. Elegí un módulo y '
                            . 'una serie, o inhabilitala.');
                    }
                } elseif (!isset($providers[$prov])) {
                    self::errorFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" apunta al módulo "'
                        . $prov . '", que no está registrado.');
                } elseif (!isset($providers[$prov]['series'][$serie])) {
                    self::errorFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" apunta a la serie "'
                        . $serie . '", que el módulo "' . $prov . '" no ofrece.');
                } elseif ($activa) {
                    // Doble conteo: dos filas activas leyendo el mismo origen
                    $par = $prov . '|' . $serie;

                    if (isset($origenesUsados[$par])) {
                        self::errorFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" lee el mismo '
                            . 'origen que "' . $origenesUsados[$par] . '": el importe se contaría '
                            . 'dos veces.');
                    } else {
                        $origenesUsados[$par] = $f['NOMBRE'];
                    }

                    if (empty($providers[$prov]['disponible'])) {
                        self::advertenciaFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" se muestra '
                            . 'en cero: el módulo ' . $providers[$prov]['nombre'] . ' todavía no '
                            . 'está construido.');
                    }
                }
            } elseif (!empty($f['ORIGEN_PROVIDER'])) {
                self::advertenciaFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" es de tipo '
                    . $f['TIPO'] . ', que lo calcula el sistema: su origen de datos se ignora.');
            }

            if (!$activa) {
                continue;
            }

            if ($f['TIPO'] === 'SALDO_INICIAL') {
                $saldosIniciales[] = $f['NOMBRE'];
            }

            if (in_array($f['TIPO'], self::TIPOS_MOVIMIENTO, true) && intval($f['COMPUTA']) === 1) {
                if (!isset($movimientosPorSeccion[$f['SECCION']])) {
                    $movimientosPorSeccion[$f['SECCION']] = 0;
                }

                $movimientosPorSeccion[$f['SECCION']]++;
            }
        }

        /* ---- Reglas de conjunto ----------------------------------------- */
        if (count($saldosIniciales) > 1) {
            $r['errores'][] = 'Hay más de una fila de saldo inicial activa ('
                . implode(', ', $saldosIniciales) . '): el arrastre del saldo tomaría dos '
                . 'aperturas distintas. Dejá una sola.';
        }

        // Un SUBTOTAL que no suma nada muestra cero y se lee como un error del
        // sistema. Se mira la seccion y sus descendientes, porque el subtotal
        // abarca en cascada.
        foreach ($filas as $f) {
            if ($f['TIPO'] !== 'SUBTOTAL' || intval($f['ACTIVO']) !== 1) {
                continue;
            }

            if (!isset($porCodigoSeccion[$f['SECCION']])) {
                continue;
            }

            $alcance = self::descendientes($secciones, $f['SECCION']);
            $cuenta = 0;

            foreach ($alcance as $codSeccion) {
                $cuenta += isset($movimientosPorSeccion[$codSeccion])
                    ? $movimientosPorSeccion[$codSeccion] : 0;
            }

            if ($cuenta === 0) {
                self::errorFila($r, $f['ID'], 'El subtotal "' . $f['NOMBRE'] . '" no tiene '
                    . 'ninguna fila de ingreso o egreso activa para sumar en su sección: '
                    . 'mostraría cero.');
            }
        }

        // Seccion activa sin filas activas: solo dibuja un titulo vacio.
        $filasActivasPorSeccion = [];

        foreach ($filas as $f) {
            if (intval($f['ACTIVO']) === 1) {
                $filasActivasPorSeccion[$f['SECCION']] = true;
            }
        }

        foreach ($seccionesActivas as $cod) {
            if (!isset($filasActivasPorSeccion[$cod])) {
                self::advertenciaSeccion($r, $cod, 'La sección "'
                    . $porCodigoSeccion[$cod]['NOMBRE'] . '" está activa pero no tiene ninguna '
                    . 'fila activa: se dibuja vacía.');
            }
        }

        return $r;
    }

    /**
     * Codigos de una seccion y de todas sus descendientes.
     *
     * @param array $secciones
     * @param string $codigo Seccion raiz del alcance
     * @return array Codigos, incluida la raiz
     */
    public static function descendientes($secciones, $codigo) {
        $hijos = [];

        foreach ($secciones as $s) {
            if (!empty($s['ID_PADRE'])) {
                $hijos[$s['ID_PADRE']][] = $s['CODIGO'];
            }
        }

        $alcance = [$codigo];
        $pila = [$codigo];
        $vistos = [$codigo => true];

        while (!empty($pila)) {
            $actual = array_pop($pila);

            if (!isset($hijos[$actual])) {
                continue;
            }

            foreach ($hijos[$actual] as $hijo) {
                if (isset($vistos[$hijo])) {
                    continue;   // corta un ciclo; validar() ya lo reporta
                }

                $vistos[$hijo] = true;
                $alcance[] = $hijo;
                $pila[] = $hijo;
            }
        }

        return $alcance;
    }

    /** Marca como error cualquier seccion que participe de un ciclo de ID_PADRE */
    private static function detectarCiclos(&$r, $secciones) {
        $padre = [];

        foreach ($secciones as $s) {
            if (!empty($s['ID_PADRE'])) {
                $padre[$s['CODIGO']] = $s['ID_PADRE'];
            }
        }

        foreach ($secciones as $s) {
            $cursor = $s['CODIGO'];
            $vistos = [];
            $saltos = 0;

            while (isset($padre[$cursor]) && $saltos < count($secciones) + 1) {
                $cursor = $padre[$cursor];
                $saltos++;

                if (isset($vistos[$cursor]) || $cursor === $s['CODIGO']) {
                    self::errorSeccion($r, $s['CODIGO'], 'La sección "' . $s['CODIGO'] . '" '
                        . 'forma un ciclo con sus secciones padre.');
                    break;
                }

                $vistos[$cursor] = true;
            }
        }
    }

    private static function errorFila(&$r, $id, $mensaje) {
        $r['valido'] = false;
        $r['errores'][] = $mensaje;

        if (isset($r['por_fila'][$id])) {
            $r['por_fila'][$id]['errores'][] = $mensaje;
        }
    }

    private static function advertenciaFila(&$r, $id, $mensaje) {
        $r['advertencias'][] = $mensaje;

        if (isset($r['por_fila'][$id])) {
            $r['por_fila'][$id]['advertencias'][] = $mensaje;
        }
    }

    private static function errorSeccion(&$r, $codigo, $mensaje) {
        $r['valido'] = false;
        $r['errores'][] = $mensaje;

        if (isset($r['por_seccion'][$codigo])) {
            $r['por_seccion'][$codigo]['errores'][] = $mensaje;
        }
    }

    private static function advertenciaSeccion(&$r, $codigo, $mensaje) {
        $r['advertencias'][] = $mensaje;

        if (isset($r['por_seccion'][$codigo])) {
            $r['por_seccion'][$codigo]['advertencias'][] = $mensaje;
        }
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
