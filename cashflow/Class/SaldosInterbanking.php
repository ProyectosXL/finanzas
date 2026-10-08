<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Aviso.php';

/**
 * SaldosInterbanking
 * Los saldos bancarios que trae Interbanking, leidos en vivo de
 * BI_T_SALDOS_INTERBANKING.
 *
 * QUE RESUELVE
 * ------------
 * Hasta ahora el saldo de cada banco se tipeaba a mano en la pestana Saldos,
 * una vez por semana, y el disponible del tablero arrancaba con un dato de
 * hace dias. Un proceso externo a este repo ya trae todos los dias, por cuenta,
 * lo que informa Interbanking y lo deja en BI_T_SALDOS_INTERBANKING. Esta
 * clase lo lee y lo entrega listo para la pestana y para el tablero.
 *
 * SE LEE EN VIVO, NO SE COPIA
 * ---------------------------
 * Igual que el efectivo de tesoreria con SBA05: cada vez que se dibuja la
 * pestana o se calcula el tablero. La tabla de BI ya guarda el historico, asi
 * que copiarlo a RO_T_CASHFLOW_SALDOS_DETALLE seria tener el mismo dato en dos
 * lugares, y dos copias del mismo dato terminan discrepando. Por lo mismo no
 * se usan las cargas (RO_T_CASHFLOW_SALDOS_CARGA) para estas cuentas.
 *
 * UNA FILA POR CUENTA: NRO_BANCO + NRO_CUENTA + MONEDA
 * ----------------------------------------------------
 * La moneda es parte de la identidad porque una misma cuenta podria venir en
 * dos monedas, y sumarlas daria un numero que no es ni una cosa ni la otra.
 * Una cuenta que llega SIN moneda no se toma: asumir pesos seria inventar el
 * dato, y el aviso es critico porque es plata que existe y no se suma.
 *
 * EL IMPORTE ES EL SALDO CONTABLE, EL ULTIMO NO NULO
 * --------------------------------------------------
 * Los otros siete saldos de la tabla no se muestran ni se usan. "El ultimo" se
 * ordena por FECHA_OPERACION, despues por CREATED_AT y despues por ID: nada
 * impide que el proceso deje dos registros del mismo dia, y sin desempate la
 * cuenta mostraria uno u otro segun el orden en que la base entregue las
 * filas. Un registro nuevo con el contable en NULL NO tapa al anterior: se
 * toma el ultimo no nulo y se avisa. Una cuenta que nunca trajo contable se
 * muestra "sin dato" -no cero- y el aviso es critico.
 *
 * LOS CRUCES SE HACEN EN PHP
 * --------------------------
 * Con la tabla BANCO de Tango (el nombre) y con las tablas propias, como el
 * resto del modulo. La unica consulta con logica es la del ultimo saldo, y va
 * contra la tabla de BI sola: ver leerUltimos().
 *
 * LAS REGLAS SON HELPERS PUROS
 * ----------------------------
 * Que registro se toma, que nombre se muestra y que se avisa lo deciden
 * metodos estaticos que no leen la base, con hoy inyectado, para poder
 * probarlos sin conexion. Las lecturas solo juntan los datos.
 *
 * NUNCA TUMBA LA PESTANA NI EL TABLERO
 * ------------------------------------
 * Si la tabla de BI no existe o la consulta falla, no hay filas de Interbanking
 * y va un aviso critico. El efectivo, Mercado Pago y las cargas manuales siguen
 * entrando por su camino.
 */
class SaldosInterbanking {

    /** La tabla que alimenta el proceso de BI. Aca solo se lee. */
    const TABLA_BI = 'BI_T_SALDOS_INTERBANKING';

    /** Alias y estado de cada banco. La crea sql/cashflow_saldos_interbanking.sql */
    const TABLA_BANCO = 'RO_T_CASHFLOW_SALDOS_BANCO';

    /** De donde salio el saldo de una fila bancaria */
    const ORIGEN_INTERBANKING = 'INTERBANKING';

    /** Seccion de los avisos en el tablero: van con los del disponible */
    const SECCION = 'Saldo Inicial';

    /** Largo maximo del alias: el de la columna */
    const ALIAS_MAX = 100;

    /** El script que crea las tablas propias de esta clase */
    const SCRIPT = 'sql/cashflow_saldos_interbanking.sql';

    /** @var Conexion */
    private $conn;

    /** @var array|null Cache de que tablas propias existen. Ver tablas(). */
    private $tablas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * La clave de una cuenta: banco, cuenta y moneda, sin espacios.
     *
     * El trim no es cosmetico: BANCO.NRO_BANCO es char(3) y la tabla de BI usa
     * varchar(10). Un espacio de mas haria que la misma cuenta fueran dos.
     *
     * @return string 'NRO_BANCO|NRO_CUENTA|MONEDA'
     */
    public static function claveCuenta($banco, $cuenta, $moneda) {
        return trim((string) $banco) . '|' . trim((string) $cuenta) . '|'
            . strtoupper(trim((string) $moneda));
    }

    /**
     * Si el registro $a va antes que $b: fecha de operacion, despues
     * CREATED_AT y despues ID, todos de mas nuevo a mas viejo.
     *
     * Un CREATED_AT nulo ordena como el mas viejo, igual que en SQL Server
     * con ORDER BY ... DESC: asi el helper y la consulta eligen lo mismo.
     */
    private static function masNuevo($a, $b) {
        $fa = (string) $a['FECHA_OPERACION'];
        $fb = (string) $b['FECHA_OPERACION'];

        if ($fa !== $fb) {
            return $fa > $fb;
        }

        $ca = isset($a['CREATED_AT']) ? (string) $a['CREATED_AT'] : '';
        $cb = isset($b['CREATED_AT']) ? (string) $b['CREATED_AT'] : '';

        if ($ca !== $cb) {
            return $ca > $cb;
        }

        return intval($a['ID']) > intval($b['ID']);
    }

    /**
     * Elige, entre los registros de UNA cuenta, el que se usa.
     *
     * La consulta ya trae como maximo dos -el mas nuevo de todos y el mas
     * nuevo con contable-, pero la regla se vuelve a aplicar aca sobre lo que
     * llegue, para que la prueba la fije sin base y para que una consulta que
     * algun dia traiga mas registros no cambie el resultado.
     *
     * @param array $registros Filas con ID, FECHA_OPERACION 'Y-m-d',
     *        CREATED_AT 'Y-m-d H:i:s'|null y SALDO_CONTABLE float|null
     * @return array ['usado' => registro|null, 'mas_nuevo' => registro|null,
     *                'tapado' => bool] 'tapado' dice que el mas nuevo vino sin
     *                contable y se uso uno anterior
     */
    public static function elegirRegistro($registros) {
        $masNuevo = null;
        $usado = null;

        foreach ((is_array($registros) ? $registros : []) as $r) {
            if ($masNuevo === null || self::masNuevo($r, $masNuevo)) {
                $masNuevo = $r;
            }

            if ($r['SALDO_CONTABLE'] !== null && ($usado === null || self::masNuevo($r, $usado))) {
                $usado = $r;
            }
        }

        return [
            'usado' => $usado,
            'mas_nuevo' => $masNuevo,
            'tapado' => ($usado !== null && $masNuevo !== null
                && intval($usado['ID']) !== intval($masNuevo['ID']))
        ];
    }

    /**
     * El nombre con el que se muestra un banco.
     *
     * El alias manda: es el nombre que usa el negocio, y existe justamente
     * porque Tango tiene la razon social o un nombre viejo ("RIO DE LA PLATA
     * S.A." es Santander). Sin alias, DESC_BANCO. Un banco que no esta en
     * Tango se muestra "Banco <nro>" y no se descarta: su plata existe igual.
     *
     * @param string $nro NRO_BANCO
     * @param array $tango Mapa NRO_BANCO (con trim) => DESC_BANCO
     * @param string|null $alias
     * @return array ['nombre' => string, 'en_tango' => bool, 'desc_banco' => string|null]
     */
    public static function nombreBanco($nro, $tango, $alias = null) {
        $nro = trim((string) $nro);
        $desc = (is_array($tango) && isset($tango[$nro])) ? trim((string) $tango[$nro]) : null;
        $alias = ($alias === null) ? '' : trim((string) $alias);

        if ($desc === '') {
            $desc = null;
        }

        if ($alias !== '') {
            $nombre = $alias;
        } elseif ($desc !== null) {
            $nombre = $desc;
        } else {
            $nombre = 'Banco ' . $nro;
        }

        return ['nombre' => $nombre, 'en_tango' => ($desc !== null), 'desc_banco' => $desc];
    }

    /**
     * Si un banco esta activo segun sus parametros.
     *
     * SIN FILA ES ACTIVO. Un banco que aparece por primera vez en Interbanking
     * tiene que entrar al disponible sin que nadie lo de de alta: si
     * dependiera de un alta, quedaria afuera en silencio. Sin el script, todos
     * activos, que es lo que eran.
     *
     * @param string $nro
     * @param array $bancos Mapa NRO_BANCO => ['ALIAS', 'ACTIVO', ...]
     * @return bool
     */
    public static function bancoActivo($nro, $bancos) {
        $nro = trim((string) $nro);

        return !(is_array($bancos) && isset($bancos[$nro]) && intval($bancos[$nro]['ACTIVO']) === 0);
    }

    /** El alias de un banco segun sus parametros, o null */
    private static function aliasDe($nro, $bancos) {
        $nro = trim((string) $nro);

        return (is_array($bancos) && isset($bancos[$nro])) ? $bancos[$nro]['ALIAS'] : null;
    }

    /**
     * El alias validado: sin espacios alrededor, null si queda vacio, y no
     * mas largo que la columna. Lo valida el servidor; el maxlength del input
     * es solo una ayuda.
     *
     * @param mixed $alias
     * @return string|null
     */
    public static function validarAlias($alias) {
        $alias = trim((string) $alias);

        if ($alias === '') {
            return null;
        }

        if (mb_strlen($alias) > self::ALIAS_MAX) {
            throw new Exception('El alias "' . $alias . '" supera los ' . self::ALIAS_MAX
                . ' caracteres.');
        }

        return $alias;
    }

    /**
     * Que bancos cambiaron en la grilla de Parametros.
     *
     * SOLO SE ESCRIBEN LOS QUE CAMBIARON, con el mismo criterio que
     * Saldos::resolverParametrosLocales(): la pantalla manda todos los bancos
     * en cada guardado, y sin el diff cada guardado sellaria a todos como
     * editados por quien apreto el boton, y la columna "Ultima edicion"
     * dejaria de significar algo.
     *
     * Un banco sin fila vale lo mismo que uno con alias null y activo: si se
     * reenvia asi, no es un cambio y no se le crea la fila.
     *
     * Valida todo antes de que se abra la transaccion. Un banco que no viene en
     * Interbanking ni tiene fila es un error: la pantalla solo lista esos, y
     * crearle una fila a un numero inventado ensuciaria la tabla.
     *
     * @param array $actuales Mapa NRO_BANCO => ['ALIAS', 'ACTIVO']
     * @param array $conocidos Los NRO_BANCO que se pueden editar
     * @param array $filas [['nro_banco', 'alias', 'activo'], ...]
     * @return array [['nro_banco', 'alias', 'activo', 'existe'], ...]
     */
    public static function resolverBancos($actuales, $conocidos, $filas) {
        $actuales = is_array($actuales) ? $actuales : [];
        $conocidos = array_map(function ($n) { return trim((string) $n); },
            is_array($conocidos) ? $conocidos : []);
        $cambios = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $nro = trim((string) (isset($f['nro_banco']) ? $f['nro_banco'] : ''));

            if ($nro === '') {
                throw new Exception('Falta el número de un banco');
            }

            if (!isset($actuales[$nro]) && !in_array($nro, $conocidos, true)) {
                throw new Exception('El banco ' . $nro . ' no viene en Interbanking. '
                    . 'Actualizá la pantalla y volvé a guardar.');
            }

            $existe = isset($actuales[$nro]);
            $aliasAntes = $existe ? self::validarAliasGuardado($actuales[$nro]['ALIAS']) : null;
            $activoAntes = $existe ? (intval($actuales[$nro]['ACTIVO']) === 1) : true;

            $alias = array_key_exists('alias', $f)
                ? self::validarAlias($f['alias']) : $aliasAntes;
            $activo = array_key_exists('activo', $f)
                ? filter_var($f['activo'], FILTER_VALIDATE_BOOLEAN) : $activoAntes;

            if ($alias !== $aliasAntes || $activo !== $activoAntes) {
                $cambios[] = ['nro_banco' => $nro, 'alias' => $alias, 'activo' => $activo,
                              'existe' => $existe];
            }
        }

        return $cambios;
    }

    /** Un alias ya guardado, llevado a la misma forma que validarAlias() sin lanzar */
    private static function validarAliasGuardado($alias) {
        $alias = trim((string) $alias);

        return ($alias === '') ? null : $alias;
    }

    /**
     * Agrupa por cuenta lo que devolvio leerUltimos().
     *
     * Las cuentas sin moneda quedan aparte: no tienen clave valida, y se
     * informan en vez de tomarse en pesos.
     *
     * @param array $ultimos
     * @return array ['cuentas' => clave => ['nro_banco', 'nro_cuenta', 'moneda',
     *                'tipo_cuenta', 'registros' => [...]], 'sin_moneda' => [...]]
     */
    public static function agruparPorCuenta($ultimos) {
        $cuentas = [];
        $sinMoneda = [];

        foreach ((is_array($ultimos) ? $ultimos : []) as $r) {
            $banco = trim((string) $r['NRO_BANCO']);
            $cuenta = trim((string) $r['NRO_CUENTA']);
            $moneda = strtoupper(trim((string) (isset($r['MONEDA']) ? $r['MONEDA'] : '')));

            if ($moneda === '') {
                $sinMoneda[$banco . '|' . $cuenta] = ['nro_banco' => $banco, 'nro_cuenta' => $cuenta];
                continue;
            }

            $clave = self::claveCuenta($banco, $cuenta, $moneda);

            if (!isset($cuentas[$clave])) {
                $cuentas[$clave] = [
                    'nro_banco' => $banco,
                    'nro_cuenta' => $cuenta,
                    'moneda' => $moneda,
                    'tipo_cuenta' => null,
                    'registros' => []
                ];
            }

            $cuentas[$clave]['registros'][] = $r;

            if (!empty($r['TIPO_CUENTA'])) {
                $cuentas[$clave]['tipo_cuenta'] = trim((string) $r['TIPO_CUENTA']);
            }
        }

        ksort($cuentas);

        return ['cuentas' => $cuentas, 'sin_moneda' => array_values($sinMoneda)];
    }

    /**
     * Las filas bancarias de la pestana y del tablero, con sus avisos.
     *
     * ES EL UNICO LUGAR DONDE SE DECIDE QUE SALDO TIENE UNA CUENTA. La pestana
     * y el proveedor lo reciben por Saldos::getFilasDisponible(); si cada uno
     * resolviera por su lado, algun dia no sumarian lo mismo.
     *
     * $ultimos === null significa que la lectura de BI fallo: no hay filas de
     * Interbanking y el aviso es critico.
     *
     * UN BANCO INACTIVO NO EXISTE PARA LA PESTANA NI PARA EL TABLERO: sus
     * cuentas no salen y no avisan nada, ni siquiera que vinieron sin moneda.
     * Inactivarlo es decir "este banco ya no se opera", y un aviso sobre el
     * seria ruido que tapa los que importan. Parametros lo sigue mostrando.
     *
     * @param array|null $ultimos Lo que devolvio leerUltimos(), o null
     * @param array $ctx 'hoy' => 'Y-m-d', 'tango' => mapa NRO_BANCO => DESC_BANCO,
     *        'bancos' => mapa NRO_BANCO => ['ALIAS', 'ACTIVO'] (vacio sin script),
     *        'error' => texto de la falla de lectura
     * @return array ['filas' => [...], 'avisos' => [Aviso]]
     */
    public static function armarCuentasBancarias($ultimos, $ctx) {
        $tango = isset($ctx['tango']) && is_array($ctx['tango']) ? $ctx['tango'] : [];
        $bancos = isset($ctx['bancos']) && is_array($ctx['bancos']) ? $ctx['bancos'] : [];
        $avisos = [];
        $filas = [];

        if ($ultimos === null) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los saldos bancarios de '
                . 'Interbanking' . (empty($ctx['error']) ? '' : ' (' . $ctx['error'] . ')')
                . '; el disponible no los incluye.', self::SECCION);

            return ['filas' => [], 'avisos' => $avisos];
        }

        $agrupadas = self::agruparPorCuenta($ultimos);
        $fueraDeTango = [];

        foreach ($agrupadas['sin_moneda'] as $c) {
            if (!self::bancoActivo($c['nro_banco'], $bancos)) {
                continue;
            }

            $banco = self::nombreBanco($c['nro_banco'], $tango, self::aliasDe($c['nro_banco'], $bancos));
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'La cuenta ' . $banco['nombre'] . ' · '
                . $c['nro_cuenta'] . ' llegó de Interbanking sin moneda, así que no se suma al '
                . 'disponible: no se puede saber si son pesos o dólares.', self::SECCION);
        }

        foreach ($agrupadas['cuentas'] as $clave => $c) {
            if (!self::bancoActivo($c['nro_banco'], $bancos)) {
                continue;
            }

            $banco = self::nombreBanco($c['nro_banco'], $tango, self::aliasDe($c['nro_banco'], $bancos));

            // Con alias el nombre ya no depende de Tango: no hay nada que avisar.
            if (!$banco['en_tango'] && trim((string) self::aliasDe($c['nro_banco'], $bancos)) === '') {
                $fueraDeTango[$c['nro_banco']] = true;
            }

            $eleccion = self::elegirRegistro($c['registros']);
            $usado = $eleccion['usado'];
            $nombre = $banco['nombre'] . ' · ' . $c['nro_cuenta'];

            if ($usado === null) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $nombre . ': Interbanking nunca trajo su '
                    . 'saldo contable, así que no suma al disponible.', self::SECCION);
            } elseif ($eleccion['tapado']) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, $nombre . ': el registro del '
                    . self::diaMes($eleccion['mas_nuevo']['FECHA_OPERACION']) . ' vino sin saldo '
                    . 'contable; se muestra el del ' . self::diaMes($usado['FECHA_OPERACION']) . '.',
                    self::SECCION);
            }

            $filas[] = self::fila($clave, $c, $banco, $eleccion);
        }

        foreach (array_keys($fueraDeTango) as $nro) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'El banco ' . $nro . ' de Interbanking no '
                . 'está en la tabla BANCO de Tango: se muestra como "Banco ' . $nro . '". '
                . 'Ponele un alias en Parámetros › Saldos.', self::SECCION);
        }

        return ['filas' => $filas, 'avisos' => $avisos];
    }

    /**
     * Una fila bancaria, con las mismas claves que Saldos::filaSaldo() para
     * que la pestana, los totales y la serie la traten igual que a una cuenta
     * manual. 'cargada' en false es "sin dato": el saldo va null, no cero.
     */
    private static function fila($clave, $c, $banco, $eleccion) {
        $usado = $eleccion['usado'];

        return [
            'id_cuenta' => null,
            'clave' => $clave,
            'tipo' => 'BANCO',
            'nombre' => $banco['nombre'] . ' · ' . $c['nro_cuenta'],
            'banco' => $banco['nombre'],
            'desc_banco' => $banco['desc_banco'],
            'nro_banco' => $c['nro_banco'],
            'nro_cuenta' => $c['nro_cuenta'],
            'tipo_cuenta' => $c['tipo_cuenta'],
            'moneda' => $c['moneda'],
            'origen' => ($usado === null) ? null : self::ORIGEN_INTERBANKING,
            'origen_cuenta' => self::ORIGEN_INTERBANKING,
            'cargada' => ($usado !== null),
            'saldo' => ($usado === null) ? null : floatval($usado['SALDO_CONTABLE']),
            'fecha_saldo' => ($usado === null) ? null : $usado['FECHA_OPERACION'],
            'fecha_carga' => ($usado === null) ? null : $usado['CREATED_AT'],
            'usuario_carga' => null,
            'ultimo_registro' => ($eleccion['mas_nuevo'] === null)
                ? null : $eleccion['mas_nuevo']['FECHA_OPERACION']
        ];
    }

    /* ====================================================================
       LECTURAS
       ==================================================================== */

    /**
     * Las filas bancarias, ya resueltas. Ver armarCuentasBancarias().
     *
     * Nunca lanza: una falla de lectura de BI es un aviso critico y cero filas
     * de Interbanking, no una pestana caida.
     *
     * @param string $hoy 'Y-m-d'
     * @return array ['filas' => [...], 'avisos' => [Aviso]]
     */
    public function getCuentasBancarias($hoy) {
        $ctx = ['hoy' => substr((string) $hoy, 0, 10), 'tango' => [], 'error' => null];
        $ultimos = null;

        try {
            $ultimos = $this->leerUltimos();
        } catch (Throwable $e) {
            $ctx['error'] = $e->getMessage();
        }

        $avisosExtra = [];

        if ($ultimos !== null) {
            try {
                $ctx['tango'] = $this->leerBancosTango();
            } catch (Throwable $e) {
                // Sin los nombres se muestra "Banco <nro>": el saldo sigue
                // siendo correcto, asi que es atencion y no critico.
                $avisosExtra[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los nombres '
                    . 'de los bancos de Tango (' . $e->getMessage() . ').', self::SECCION);
            }
        }

        try {
            $ctx['bancos'] = $this->leerBancos();
        } catch (Throwable $e) {
            // Sin los parametros todos los bancos cuentan como activos: es lo
            // que pasa sin el script. Puede sumar un banco inhabilitado, asi
            // que es critico.
            $ctx['bancos'] = [];
            $avisosExtra[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer el alias y el '
                . 'estado de los bancos (' . $e->getMessage() . '): se toman todos como activos.',
                self::SECCION);
        }

        $r = self::armarCuentasBancarias($ultimos, $ctx);
        $r['avisos'] = array_merge($avisosExtra, $r['avisos']);

        return $r;
    }

    /**
     * Los registros candidatos de cada cuenta: el mas nuevo de todos y el mas
     * nuevo con saldo contable. Como maximo dos por cuenta.
     *
     * ES LA UNICA CONSULTA CON LOGICA, Y VA CONTRA LA TABLA DE BI SOLA. Traer
     * todo el historico a PHP para quedarse con dos filas por cuenta seria
     * mover la tabla entera en cada dibujado de la pestana y en cada calculo
     * del tablero. El ROW_NUMBER() no cruza nada: ordena dentro de una tabla.
     * La eleccion final la vuelve a hacer elegirRegistro().
     *
     * El segundo ROW_NUMBER() particiona ademas por "tiene contable o no", asi
     * que RN_GRUPO = 1 con contable es el mas nuevo no nulo de la cuenta.
     *
     * @return array
     */
    public function leerUltimos() {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA_BI . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de Interbanking'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row || $row['T'] === null) {
            throw new Exception('no existe la tabla ' . self::TABLA_BI);
        }

        $sql = "WITH R AS (
                    SELECT ID, FECHA_OPERACION, NRO_BANCO, NRO_CUENTA, TIPO_CUENTA, MONEDA,
                           SALDO_CONTABLE, CREATED_AT,
                           ROW_NUMBER() OVER (
                               PARTITION BY NRO_BANCO, NRO_CUENTA, MONEDA
                               ORDER BY FECHA_OPERACION DESC, CREATED_AT DESC, ID DESC
                           ) AS RN_TODOS,
                           ROW_NUMBER() OVER (
                               PARTITION BY NRO_BANCO, NRO_CUENTA, MONEDA,
                                            CASE WHEN SALDO_CONTABLE IS NULL THEN 0 ELSE 1 END
                               ORDER BY FECHA_OPERACION DESC, CREATED_AT DESC, ID DESC
                           ) AS RN_GRUPO
                    FROM dbo." . self::TABLA_BI . "
                )
                SELECT ID, FECHA_OPERACION, NRO_BANCO, NRO_CUENTA, TIPO_CUENTA, MONEDA,
                       SALDO_CONTABLE, CREATED_AT
                FROM R
                WHERE RN_TODOS = 1 OR (SALDO_CONTABLE IS NOT NULL AND RN_GRUPO = 1)";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los saldos de Interbanking'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'FECHA_OPERACION' => Horizonte::normalizarFecha($row['FECHA_OPERACION']),
                'NRO_BANCO' => trim((string) $row['NRO_BANCO']),
                'NRO_CUENTA' => trim((string) $row['NRO_CUENTA']),
                'TIPO_CUENTA' => $row['TIPO_CUENTA'] === null ? null : trim($row['TIPO_CUENTA']),
                'MONEDA' => $row['MONEDA'] === null ? null : trim($row['MONEDA']),
                'SALDO_CONTABLE' => $row['SALDO_CONTABLE'] === null
                    ? null : floatval($row['SALDO_CONTABLE']),
                'CREATED_AT' => self::fechaHora($row['CREATED_AT'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Que tablas propias existen. Se preguntan por separado y no "el script si
     * o no": cada una apaga una parte distinta de la pantalla, y la que existe
     * tiene que seguir funcionando aunque falte otra.
     *
     * @return array ['banco' => bool]
     */
    public function tablas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT OBJECT_ID('dbo." . self::TABLA_BANCO . "', 'U') AS B");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de Interbanking'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ['banco' => ($row && $row['B'] !== null)];

        return $this->tablas;
    }

    /**
     * Alias y estado de cada banco con fila. Vacio sin el script: todos
     * activos y sin alias.
     *
     * @return array Mapa NRO_BANCO => ['ALIAS', 'ACTIVO', 'USUARIO_MODIF', 'FECHA_MODIF']
     */
    public function leerBancos() {
        if (!$this->tablas()['banco']) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT NRO_BANCO, ALIAS, ACTIVO, USUARIO_MODIF, FECHA_MODIF FROM dbo." . self::TABLA_BANCO);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los bancos de Interbanking'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $nro = trim((string) $row['NRO_BANCO']);
            $v[$nro] = [
                'ALIAS' => $row['ALIAS'],
                'ACTIVO' => intval($row['ACTIVO']),
                'USUARIO_MODIF' => $row['USUARIO_MODIF'],
                'FECHA_MODIF' => self::fechaHora($row['FECHA_MODIF'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Los nombres de los bancos de Tango, por NRO_BANCO con trim. Se lee la
     * tabla entera (dos centenares de filas) y se cruza en PHP.
     *
     * @return array Mapa NRO_BANCO => DESC_BANCO
     */
    public function leerBancosTango() {
        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT NRO_BANCO, DESC_BANCO FROM BANCO WHERE NRO_BANCO IS NOT NULL");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los bancos de Tango'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[trim((string) $row['NRO_BANCO'])] = trim((string) $row['DESC_BANCO']);
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       ESCRITURAS
       ==================================================================== */

    /**
     * Guarda alias y estado de los bancos de la grilla de Parametros.
     *
     * Solo los que cambiaron (resolverBancos()) y en UNA transaccion: una
     * falla a mitad de camino no deja la mitad guardada. Un banco sin fila se
     * crea (UPSERT): es el primer momento en que alguien dijo algo sobre el.
     *
     * Inactivar sella la baja con el mismo criterio que el resto del modulo
     * (Auditoria::sqlBajaSegunEstado()), y reactivar la limpia.
     *
     * @param array $filas [['nro_banco', 'alias', 'activo'], ...]
     * @param string $usuario
     * @return int Cuantos bancos se escribieron
     */
    public function guardarBancos($filas, $usuario) {
        require_once __DIR__ . '/AuthCashflow.php';
        require_once __DIR__ . '/Auditoria.php';

        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablas()['banco']) {
            throw new Exception('Todavía no existe la tabla de bancos: corré ' . self::SCRIPT
                . ' contra la base central.');
        }

        // Se pueden editar los bancos que vienen en Interbanking y los que ya
        // tienen fila aunque hayan dejado de venir. Si BI no responde, solo
        // los segundos: no se crea una fila a ciegas.
        $conocidos = [];

        try {
            foreach ($this->leerUltimos() as $r) {
                $conocidos[] = $r['NRO_BANCO'];
            }
        } catch (Throwable $e) {
            $conocidos = [];
        }

        $cambios = self::resolverBancos($this->leerBancos(), array_unique($conocidos), $filas);

        if (empty($cambios)) {
            return 0;
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            foreach ($cambios as $c) {
                $this->escribirBanco($cid, $c, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudieron confirmar los bancos'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return count($cambios);
    }

    /** UPDATE si el banco tiene fila; si no, INSERT. Dentro de la transaccion de $cid. */
    private function escribirBanco($cid, $c, $usuario) {
        $activo = $c['activo'] ? 1 : 0;

        if ($c['existe']) {
            $sql = "UPDATE dbo." . self::TABLA_BANCO . "
                    SET ALIAS = ?, " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", ACTIVO = ?, "
                        . Auditoria::SET_MODIF . "
                    WHERE NRO_BANCO = ?";
            $params = array_merge([$c['alias']], Auditoria::paramsBajaSegunEstado($activo, $usuario),
                [$activo, $usuario, $c['nro_banco']]);
        } else {
            // Un banco que se inactiva en su primer guardado nace con la baja
            // sellada: es la misma informacion que dejaria un UPDATE.
            $sql = "INSERT INTO dbo." . self::TABLA_BANCO . "
                        (NRO_BANCO, ALIAS, ACTIVO, USUARIO_ALTA, USUARIO_MODIF, USUARIO_BAJA, FECHA_BAJA)
                    VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 0 THEN GETDATE() END)";
            $params = [$c['nro_banco'], $c['alias'], $activo, $usuario, $usuario,
                       ($activo ? null : $usuario), $activo];
        }

        if (sqlsrv_query($cid, $sql, $params) === false) {
            throw new Exception($this->errorSql('Error al guardar el banco ' . $c['nro_banco']));
        }
    }

    /* ====================================================================
       UTILIDADES
       ==================================================================== */

    /** @param string $servidor @return resource Conexion, con el error ya traducido */
    protected function conectar($servidor) {
        $cid = $this->conn->conectar($servidor);

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos (' . $servidor . ')');
        }

        return $cid;
    }

    /** @return string|null 'Y-m-d H:i:s' de lo que devuelve sqlsrv para un DATETIME */
    private static function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
    }

    /** 'Y-m-d' => 'dd/mm', para los avisos */
    private static function diaMes($fecha) {
        return substr((string) $fecha, 8, 2) . '/' . substr((string) $fecha, 5, 2);
    }

    /** Formato de importe para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    private function errorSql($contexto) {
        $errors = sqlsrv_errors();
        $msg = $contexto . ': ';

        if ($errors) {
            foreach ($errors as $error) {
                $msg .= $error['message'] . ' ';
            }
        }

        return $msg;
    }
}
