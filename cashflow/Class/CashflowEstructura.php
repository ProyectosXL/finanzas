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
 *                  secciones hijas (en cascada por ID_PADRE), mas las filas de
 *                  saldo que caigan en ese alcance
 *   FLUJO_NETO  -> todas las filas de movimiento y de saldo que esten POR
 *                  ENCIMA de ella, de cualquier seccion
 *   SALDO_FINAL -> los movimientos que esten por encima, mas el arrastre del
 *                  saldo acumulado columna a columna
 *
 * EL ROL DE LA SECCION NO PARTICIPA DE NINGUNA DE LAS TRES: quien decide como
 * suma una fila es su TIPO. El ROL quedo para agrupar y para los avisos del
 * validador. Ver Cashflow::sumarMovimientos().
 *
 * QUE UNA FILA SE PUEDA EXCLUIR DE UN CALCULO MOVIENDOLA ES LA IDEA, no un
 * efecto colateral: la fila de uso de cobertura queda DEBAJO del Flujo Neto
 * (sin cobertura) y ARRIBA del Flujo Neto (con cobertura), y con eso las dos
 * filas dan lo que su nombre promete sin ninguna regla nueva en el motor.
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

    /**
     * Tipos de fila validos.
     *
     * STOCK_COBERTURA y USO_COBERTURA son los dos de la seccion Cobertura y no
     * significan lo mismo:
     *
     *   STOCK_COBERTURA -> cuanta plata hay disponible para cubrir un bache. NO
     *                      va en ninguna columna de fecha: es un stock, no un
     *                      flujo, y ponerlo en un dia diria que ese dia entra.
     *   USO_COBERTURA   -> cuanto de ese stock se aplica en cada fecha. Es una
     *                      fila de movimiento como cualquier otra, y puede ser
     *                      negativa (devolver plata a la inversion).
     *
     * ESTA LISTA Y EL CHECK DEL DDL CAMBIAN JUNTOS. Ver la restriccion
     * CK_RO_T_CASHFLOW_CONF_FILA_TIPO en sql/cashflow_estructura.sql y su
     * ampliacion en sql/cashflow_cobertura.sql.
     */
    const TIPOS = [
        'SALDO_INICIAL', 'INGRESO', 'EGRESO', 'SUBTOTAL', 'FLUJO_NETO', 'SALDO_FINAL',
        'STOCK_COBERTURA', 'USO_COBERTURA'
    ];

    /** Roles de seccion validos */
    const ROLES = ['SALDO', 'MOVIMIENTO', 'DERIVADO'];

    /**
     * Tipos que aportan flujo y por lo tanto llevan signo.
     *
     * USO_COBERTURA entra aca A PROPOSITO: la cobertura aplicada es plata que
     * efectivamente se mueve, asi que tiene que entrar al arrastre del saldo
     * como cualquier otro movimiento. Lo que la distingue de un INGRESO es que
     * no es plata que el negocio genera -es pasarla de una inversion a la
     * cuenta-, y por eso no suma en el indicador de Ingresos. Ver
     * Cashflow::kpiDe().
     */
    const TIPOS_MOVIMIENTO = ['INGRESO', 'EGRESO', 'USO_COBERTURA'];

    /** Tipos que necesitan un origen de datos */
    const TIPOS_CON_ORIGEN = [
        'SALDO_INICIAL', 'INGRESO', 'EGRESO', 'STOCK_COBERTURA', 'USO_COBERTURA'
    ];

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
     * USO_COBERTURA suma, igual que un INGRESO: aplicar cobertura es traer plata
     * a la cuenta. Lo que la hace poder restar es el IMPORTE, que se carga
     * negativo cuando se devuelve plata a la inversion; el signo del tipo no
     * cambia. Es la misma regla que el resto: el signo lo pone el tipo, la
     * direccion del movimiento la pone el dato.
     *
     * @param string $tipo
     * @return int 1, -1 o 0
     */
    public static function signo($tipo) {
        if ($tipo === 'INGRESO' || $tipo === 'USO_COBERTURA') {
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
                    } elseif (!empty($providers[$prov]['retirado'])) {
                        // Advertencia y no error: la fila sigue funcionando con
                        // lo ultimo que se cargo ahi, y bloquear el guardado
                        // impediria justamente corregirla. Ver CashflowRegistry.
                        self::advertenciaFila($r, $id, 'La fila "' . $f['NOMBRE'] . '" lee del '
                            . 'módulo ' . $providers[$prov]['nombre'] . ', que está retirado: '
                            . $providers[$prov]['retirado'] . ' Apuntala al módulo que lo '
                            . 'reemplaza.');
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
            self::error($r, 'Hay más de una fila de saldo inicial activa ('
                . implode(', ', $saldosIniciales) . '): el arrastre del saldo tomaría dos '
                . 'aperturas distintas. Dejá una sola.');
        }

        // Una serie total y sus componentes activas al mismo tiempo cuentan dos
        // veces el mismo importe, y la regla de origen repetido no lo ve, porque
        // son series distintas. El registro declara la relacion en
        // 'componentes'; por ejemplo la cobranza total de Ventas contra sus
        // cuatro canales.
        foreach ($origenesUsados as $par => $nombreFila) {
            list($prov, $serie) = explode('|', $par, 2);

            if (empty($providers[$prov]['componentes'][$serie])) {
                continue;
            }

            foreach ($providers[$prov]['componentes'][$serie] as $componente) {
                $parComponente = $prov . '|' . $componente;

                if (isset($origenesUsados[$parComponente])) {
                    self::error($r, 'La fila "' . $nombreFila . '" trae el total de '
                        . $providers[$prov]['nombre'] . ' y "' . $origenesUsados[$parComponente]
                        . '" trae una de sus partes: el importe se contaría dos veces. '
                        . 'Dejá activo el total o la apertura, no los dos.');
                }
            }
        }

        self::validarCortes($r, $providers, $origenesUsados);

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
     * DOS PARTES DEL MISMO UNIVERSO QUE VIENEN DE CORTES DISTINTOS SE PISAN.
     *
     * La regla de arriba mira el TOTAL contra una de sus partes. Esta mira las
     * partes ENTRE SI, que es el agujero que quedaba: un mismo universo se
     * puede cortar de varias maneras, y dos cortes distintos no son dos mitades
     * -se solapan casi enteros-.
     *
     * El caso real: Proveedores Locales parte sus pendientes por COMO SE PAGA
     * -PAGOS + PAGOS_FUERA_CRONOGRAMA- y tambien por QUE RUBRO ES
     * -PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS-. Cada par cierra contra el universo,
     * asi que activar los dos de UN par es correcto y esta previsto; pero
     * activar PAGOS junto a PAGOS_OPERATIVOS contaba dos veces $1.297 millones
     * sin que nada lo dijera.
     *
     * COMO SE DECLARA. El registro pone 'particiones' en la entrada del
     * proveedor: un mapa total => corte => series. Dos series del MISMO corte
     * pueden convivir; dos de cortes distintos, no. Una serie que no esta en
     * ningun corte -la interseccion de dos cortes, como
     * PAGOS_CRONO_OPERATIVOS- no puede convivir con ninguna otra parte: se
     * solapa con todas.
     *
     * SIN 'particiones' DECLARADAS NO CAMBIA NADA. Si el proveedor no las
     * declara, todas sus partes se toman como un unico corte, que es lo que ya
     * pasaba: los cuatro canales de Ventas siguen pudiendo estar los cuatro
     * activos.
     *
     * @param array $r Resultado de la validacion, se modifica
     * @param array $providers El registro
     * @param array $origenesUsados Mapa 'PROV|SERIE' => nombre de la fila activa
     */
    private static function validarCortes(&$r, $providers, $origenesUsados) {
        foreach ($providers as $prov => $meta) {
            if (empty($meta['componentes'])) {
                continue;
            }

            foreach ($meta['componentes'] as $total => $partes) {
                /* Las partes activas de este universo, con el corte al que
                   pertenece cada una. */
                $activas = [];

                foreach ($partes as $parte) {
                    if (isset($origenesUsados[$prov . '|' . $parte])) {
                        $activas[$parte] = self::corteDe($meta, $total, $parte);
                    }
                }

                if (count($activas) < 2) {
                    continue;
                }

                $codigos = array_keys($activas);

                for ($i = 0; $i < count($codigos); $i++) {
                    for ($j = $i + 1; $j < count($codigos); $j++) {
                        $a = $codigos[$i];
                        $b = $codigos[$j];

                        // Mismo corte: son dos partes de la misma division y no
                        // se pisan. Es el caso previsto de PAGOS junto a
                        // PAGOS_FUERA_CRONOGRAMA.
                        if ($activas[$a] !== null && $activas[$a] === $activas[$b]) {
                            continue;
                        }

                        self::error($r, 'Las filas "' . $origenesUsados[$prov . '|' . $a]
                            . '" y "' . $origenesUsados[$prov . '|' . $b] . '" cortan la misma '
                            . 'deuda de ' . $meta['nombre'] . ' de dos maneras distintas ('
                            . self::nombreCorte($activas[$a], $a) . ' contra '
                            . self::nombreCorte($activas[$b], $b) . '), así que se superponen y '
                            . 'el importe se contaría dos veces. Elegí un solo corte.');
                    }
                }
            }
        }
    }

    /**
     * A que corte del universo pertenece una serie, o null si a ninguno.
     *
     * Null significa "se solapa con todo lo demas": es el caso de una serie que
     * cruza dos cortes, como el cronograma sin excluidos.
     */
    private static function corteDe($meta, $total, $serie) {
        if (empty($meta['particiones'][$total])) {
            // Sin declaracion, todas las partes son el mismo corte. Es como se
            // comportaba antes y es lo correcto para los cuatro canales de
            // Ventas.
            return '(único)';
        }

        foreach ($meta['particiones'][$total] as $nombre => $series) {
            if (in_array($serie, $series, true)) {
                return $nombre;
            }
        }

        return null;
    }

    /** El corte, para el mensaje. Una serie sin corte se nombra por si misma. */
    private static function nombreCorte($corte, $serie) {
        return ($corte === null) ? $serie : $corte;
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

    /**
     * Error que no pertenece a una fila ni a una seccion en particular.
     *
     * Va por aca y no empujando a $r['errores'] a mano: escribir el mensaje sin
     * bajar la bandera 'valido' deja un error que se muestra pero no bloquea el
     * guardado, que es justamente lo contrario de lo que se quiere.
     */
    private static function error(&$r, $mensaje) {
        $r['valido'] = false;
        $r['errores'][] = $mensaje;
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

    /* ====================================================================
       ESCRITURA

       Sobre crear filas: que Parametros::saveParametro se niegue a crear no es
       un precedente a imitar aca. Esa negativa existe porque cada fila de
       RO_T_CASHFLOW_PARAMETROS tiene codigo que la lee (Parametros::num lanza
       si falta la clave), asi que una clave inventada por el usuario seria dato
       muerto. Las filas de CONF_FILA son lo contrario: son datos puros, sin
       codigo detras. Crearlas es justamente el punto de todo el modulo.

       Lo que NO existe es la baja: se inhabilita con ACTIVO = 0, igual que en
       todo el resto del sistema.
       ==================================================================== */

    /**
     * Alta de una seccion. Entra INHABILITADA y ultima.
     *
     * @param string $nombre
     * @param string $rol SALDO, MOVIMIENTO o DERIVADO
     * @param string|null $usuario
     * @return string El codigo asignado
     */
    public function addSeccion($nombre, $rol, $usuario = null) {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            throw new Exception('La sección necesita un nombre');
        }

        if (!in_array($rol, self::ROLES, true)) {
            throw new Exception('El rol "' . $rol . '" no es válido');
        }

        $codigo = self::slug($nombre);

        if (!self::codigoValido($codigo)) {
            throw new Exception('Con el nombre "' . $nombre . '" no se puede armar un código '
                . 'interno válido. Usá al menos una letra.');
        }

        $cid = $this->conexion();

        // El UNIQUE lo garantiza la clave primaria, pero se chequea antes para
        // dar un mensaje entendible en lugar del error del indice.
        $stmt = sqlsrv_query($cid,
            "SELECT NOMBRE, ACTIVO FROM RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = ?", [$codigo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la sección'));
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($existe) {
            throw new Exception('Ya existe una sección con el código ' . $codigo
                . ' ("' . $existe['NOMBRE'] . '", '
                . (intval($existe['ACTIVO']) === 1 ? 'activa' : 'inhabilitada') . ')');
        }

        $sql = "INSERT INTO RO_T_CASHFLOW_CONF_SECCION
                    (CODIGO, NOMBRE, ROL, ID_PADRE, ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
                VALUES (?, ?, ?, NULL,
                    (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_CONF_SECCION),
                    0, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [$codigo, $nombre, $rol, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la sección'));
        }

        sqlsrv_free_stmt($stmt);

        return $codigo;
    }

    /**
     * Alta de una fila. Entra INHABILITADA y ultima de su seccion.
     *
     * Que entre inhabilitada no es un detalle: una fila inhabilitada NO puede
     * invalidar la estructura, asi que el alta no necesita validar el arbol
     * completo ni puede romper un tablero que estaba bien. Es el mismo criterio
     * con el que Parametros::addMixCobro da de alta un medio de pago en 0% e
     * inhabilitado.
     *
     * @param string $nombre
     * @param string $seccion Codigo de seccion
     * @param string $tipo
     * @param string|null $usuario
     * @return array ['id' => int, 'codigo' => string]
     */
    public function addFila($nombre, $seccion, $tipo, $usuario = null) {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            throw new Exception('La fila necesita un nombre');
        }

        if (!in_array($tipo, self::TIPOS, true)) {
            throw new Exception('El tipo "' . $tipo . '" no es válido');
        }

        $codigo = self::slug($nombre);

        if (!self::codigoValido($codigo)) {
            throw new Exception('Con el nombre "' . $nombre . '" no se puede armar un código '
                . 'interno válido. Usá al menos una letra.');
        }

        $cid = $this->conexion();

        $stmt = sqlsrv_query($cid,
            "SELECT CODIGO FROM RO_T_CASHFLOW_CONF_SECCION WHERE CODIGO = ?", [$seccion]);

        if ($stmt === false || !sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            throw new Exception('La sección "' . $seccion . '" no existe');
        }

        sqlsrv_free_stmt($stmt);

        $stmt = sqlsrv_query($cid,
            "SELECT NOMBRE, ACTIVO FROM RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = ?", [$codigo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la fila'));
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($existe) {
            throw new Exception('Ya existe una fila con el código ' . $codigo
                . ' ("' . $existe['NOMBRE'] . '", '
                . (intval($existe['ACTIVO']) === 1 ? 'activa' : 'inhabilitada') . ')');
        }

        $sql = "INSERT INTO RO_T_CASHFLOW_CONF_FILA
                    (CODIGO, NOMBRE, SECCION, TIPO, COMPUTA, ORIGEN_PROVIDER, ORIGEN_SERIE,
                     ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?, 1, NULL, NULL,
                    (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_CONF_FILA WHERE SECCION = ?),
                    0, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [$codigo, $nombre, $seccion, $tipo, $seccion, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la fila'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return ['id' => intval($row['ID']), 'codigo' => $codigo];
    }

    /**
     * Guarda la estructura completa.
     *
     * EL ORDEN LO DEFINE LA POSICION EN EL ARREGLO, no un campo que mande el
     * cliente: el servidor renumera desde cero con (indice+1)*10. Renumerar
     * siempre hace que el orden se repare solo, y elimina de raiz los ordenes
     * duplicados, los huecos y toda la logica de intercambio.
     *
     * ES LA PRIMERA TRANSACCION DEL PROYECTO, y es a proposito. En el resto del
     * codigo las escrituras de varias filas van sueltas, y ademas
     * Conexion::conectar() abre una conexion NUEVA en cada llamada, asi que
     * cada fila confirma por separado. Para un porcentaje eso es una molestia
     * recuperable; para un renumerado de estructura no lo es: una falla a mitad
     * de camino dejaria ordenes duplicados y secciones renombradas con filas
     * huerfanas. Por eso aca se abre UNA conexion y todo va en una transaccion.
     *
     * @param array $secciones En el orden en que se muestran
     * @param array $filas En el orden en que se muestran
     * @param string|null $usuario
     * @return array El resultado de validar(), con las advertencias
     */
    public function guardar($secciones, $filas, $usuario = null) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de estructura. '
                . 'Corré sql/cashflow_estructura.sql.');
        }

        $actualSecciones = $this->getSecciones(false);
        $actualFilas = $this->getFilas(false);

        $this->verificarSincronia($actualSecciones, $actualFilas, $secciones, $filas);

        // Se valida el estado RESULTANTE, no lo que manda el cliente sin
        // contrastar: un envio parcial no puede colar una estructura invalida.
        $simSecciones = $this->simularSecciones($actualSecciones, $secciones);
        $simFilas = $this->simularFilas($actualFilas, $filas);

        $val = self::validar($simSecciones, $simFilas);

        if (!$val['valido']) {
            throw new Exception(implode(' ', $val['errores']));
        }

        $cid = $this->conexion();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            $orden = 0;

            foreach ($secciones as $s) {
                $orden += 10;

                $sql = "UPDATE RO_T_CASHFLOW_CONF_SECCION
                        SET NOMBRE = ?, ROL = ?, ID_PADRE = ?, ORDEN = ?, ACTIVO = ?,
                            FECHA_UPDATE = GETDATE(), USUARIO = ?
                        WHERE CODIGO = ?";

                $params = [
                    trim($s['nombre']),
                    $s['rol'],
                    empty($s['id_padre']) ? null : $s['id_padre'],
                    $orden,
                    !empty($s['activo']) ? 1 : 0,
                    $usuario,
                    $s['codigo']
                ];

                if (sqlsrv_query($cid, $sql, $params) === false) {
                    throw new Exception($this->errorSql('Error al guardar la sección '
                        . $s['codigo']));
                }
            }

            // El orden de las filas se renumera DENTRO de cada seccion
            $ordenPorSeccion = [];

            foreach ($filas as $f) {
                $seccion = $f['seccion'];

                if (!isset($ordenPorSeccion[$seccion])) {
                    $ordenPorSeccion[$seccion] = 0;
                }

                $ordenPorSeccion[$seccion] += 10;

                // CODIGO no se actualiza nunca: es la clave con la que se
                // referencia la fila. Si quedo mal, se inhabilita y se crea otra.
                $sql = "UPDATE RO_T_CASHFLOW_CONF_FILA
                        SET NOMBRE = ?, SECCION = ?, TIPO = ?, COMPUTA = ?,
                            ORIGEN_PROVIDER = ?, ORIGEN_SERIE = ?, ORDEN = ?, ACTIVO = ?,
                            FECHA_UPDATE = GETDATE(), USUARIO = ?
                        WHERE ID = ?";

                $derivada = self::esDerivada($f['tipo']);

                $params = [
                    trim($f['nombre']),
                    $seccion,
                    $f['tipo'],
                    !empty($f['computa']) ? 1 : 0,
                    ($derivada || empty($f['origen_provider'])) ? null : $f['origen_provider'],
                    ($derivada || empty($f['origen_serie'])) ? null : $f['origen_serie'],
                    $ordenPorSeccion[$seccion],
                    !empty($f['activo']) ? 1 : 0,
                    $usuario,
                    intval($f['id'])
                ];

                if (sqlsrv_query($cid, $sql, $params) === false) {
                    throw new Exception($this->errorSql('Error al guardar la fila '
                        . $f['nombre']));
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el guardado'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return $val;
    }

    /**
     * Rechaza el guardado si alguien agrego o quito filas desde que se cargo la
     * pantalla. Sin esto, un alta hecha en otra pestana se quedaria sin orden o
     * se perderia.
     */
    private function verificarSincronia($actualSecciones, $actualFilas, $secciones, $filas) {
        $idsDb = [];

        foreach ($actualFilas as $f) {
            $idsDb[intval($f['ID'])] = true;
        }

        $idsIn = [];

        foreach ($filas as $f) {
            $idsIn[intval($f['id'])] = true;
        }

        $codsDb = [];

        foreach ($actualSecciones as $s) {
            $codsDb[$s['CODIGO']] = true;
        }

        $codsIn = [];

        foreach ($secciones as $s) {
            $codsIn[$s['codigo']] = true;
        }

        if (count(array_diff_key($idsDb, $idsIn)) > 0
            || count(array_diff_key($idsIn, $idsDb)) > 0
            || count(array_diff_key($codsDb, $codsIn)) > 0
            || count(array_diff_key($codsIn, $codsDb)) > 0) {
            throw new Exception('La pantalla está desactualizada: alguien agregó o quitó '
                . 'filas o secciones. Recargá antes de guardar.');
        }
    }

    /** Superpone las secciones que llegan sobre las de la base */
    private function simularSecciones($actual, $entrantes) {
        $porCodigo = [];

        foreach ($entrantes as $s) {
            $porCodigo[$s['codigo']] = $s;
        }

        $sim = [];
        $orden = 0;

        foreach ($entrantes as $e) {
            $orden += 10;

            foreach ($actual as $a) {
                if ($a['CODIGO'] !== $e['codigo']) {
                    continue;
                }

                $a['NOMBRE'] = trim($e['nombre']);
                $a['ROL'] = $e['rol'];
                $a['ID_PADRE'] = empty($e['id_padre']) ? null : $e['id_padre'];
                $a['ORDEN'] = $orden;
                $a['ACTIVO'] = !empty($e['activo']) ? 1 : 0;
                $sim[] = $a;
                break;
            }
        }

        return $sim;
    }

    /** Superpone las filas que llegan sobre las de la base */
    private function simularFilas($actual, $entrantes) {
        $porId = [];

        foreach ($actual as $a) {
            $porId[intval($a['ID'])] = $a;
        }

        $sim = [];
        $ordenPorSeccion = [];

        foreach ($entrantes as $e) {
            $id = intval($e['id']);

            if (!isset($porId[$id])) {
                continue;
            }

            $a = $porId[$id];
            $derivada = self::esDerivada($e['tipo']);

            if (!isset($ordenPorSeccion[$e['seccion']])) {
                $ordenPorSeccion[$e['seccion']] = 0;
            }

            $ordenPorSeccion[$e['seccion']] += 10;

            $a['NOMBRE'] = trim($e['nombre']);
            $a['SECCION'] = $e['seccion'];
            $a['TIPO'] = $e['tipo'];
            $a['COMPUTA'] = !empty($e['computa']) ? 1 : 0;
            $a['ORIGEN_PROVIDER'] = ($derivada || empty($e['origen_provider']))
                ? null : $e['origen_provider'];
            $a['ORIGEN_SERIE'] = ($derivada || empty($e['origen_serie']))
                ? null : $e['origen_serie'];
            $a['ORDEN'] = $ordenPorSeccion[$e['seccion']];
            $a['ACTIVO'] = !empty($e['activo']) ? 1 : 0;

            $sim[] = $a;
        }

        return $sim;
    }

    /** Conexion a central, con el error ya traducido */
    private function conexion() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        return $cid;
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
