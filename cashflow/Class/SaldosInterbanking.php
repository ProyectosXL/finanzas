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

    /** Respaldo manual de una cuenta que Interbanking no trae. Mismo script. */
    const TABLA_MANUAL = 'RO_T_CASHFLOW_SALDOS_BANCO_MANUAL';

    /** Las cuentas ya marcadas como vistas: la que no esta, es nueva. Mismo script. */
    const TABLA_VISTAS = 'RO_T_CASHFLOW_SALDOS_BANCO_CUENTA';

    /** De donde salio el saldo de una fila bancaria */
    const ORIGEN_INTERBANKING = 'INTERBANKING';
    const ORIGEN_RESPALDO = 'RESPALDO';

    /**
     * Los origenes que se esperan DEL DIA. Un saldo viejo de estos no pide
     * "actualiza la carga" -no hay carga que actualizar- sino que avisa que
     * no llego el de hoy. Ver Saldos::armarSerieDisponible().
     *
     * El respaldo esta aca y no con las cargas: tapa a Interbanking, y lo que
     * se espera de el es lo mismo, el saldo del dia.
     */
    const ORIGENES_DEL_DIA = [self::ORIGEN_INTERBANKING, self::ORIGEN_RESPALDO];

    /** Largo maximo de la observacion de un respaldo: el de la columna */
    const OBSERVACION_MAX = 500;

    /** Seccion de los avisos en el tablero: van con los del disponible */
    const SECCION = 'Saldo Inicial';

    /** Largo maximo del alias: el de la columna */
    const ALIAS_MAX = 100;

    /** El script que crea las tablas propias de esta clase */
    const SCRIPT = 'sql/cashflow_saldos_interbanking.sql';

    /**
     * La constancia de que se borraron las cuentas bancarias manuales que
     * pasaron a Interbanking. La crea, en modo real, SCRIPT_DEPURACION.
     */
    const TABLA_DEPURACION = 'RO_T_CASHFLOW_SALDOS_DEPURACION';
    const SCRIPT_DEPURACION = 'sql/cashflow_saldos_borrar_bancos_manuales.sql';

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

    /**
     * Un respaldo manual validado, antes de abrir la transaccion.
     *
     * LA FECHA NO PUEDE SER POSTERIOR A HOY: un saldo es lo que hay en la
     * cuenta, y un saldo "de manana" es una proyeccion. Ademas ganaria contra
     * el dato de Interbanking de hoy por ser mas nuevo, y lo taparia.
     *
     * EL IMPORTE ES OBLIGATORIO y puede ser negativo -una cuenta en descubierto
     * tiene saldo contable negativo-, pero no vacio: un vacio tomado como cero
     * diria "esta cuenta no tiene plata" sin que nadie lo haya dicho.
     *
     * @param array $d ['nro_banco', 'nro_cuenta', 'moneda', 'fecha_saldo', 'saldo', 'observacion']
     * @param string $hoy 'Y-m-d'
     * @return array Los mismos campos, normalizados
     */
    public static function validarRespaldo($d, $hoy) {
        $d = is_array($d) ? $d : [];
        $campo = function ($k) use ($d) {
            return isset($d[$k]) ? trim((string) $d[$k]) : '';
        };

        $banco = $campo('nro_banco');
        $cuenta = $campo('nro_cuenta');
        $moneda = strtoupper($campo('moneda'));

        if ($banco === '' || $cuenta === '' || $moneda === '') {
            throw new Exception('Falta el banco, la cuenta o la moneda del saldo manual.');
        }

        $fecha = $campo('fecha_saldo');
        $f = DateTime::createFromFormat('!Y-m-d', $fecha);

        if ($f === false || $f->format('Y-m-d') !== $fecha) {
            throw new Exception('La fecha del saldo manual no es válida.');
        }

        if ($fecha > substr((string) $hoy, 0, 10)) {
            throw new Exception('La fecha del saldo manual no puede ser posterior a hoy: un saldo es '
                . 'lo que hay en la cuenta, no lo que va a haber.');
        }

        $saldo = $campo('saldo');

        if ($saldo === '' || !is_numeric($saldo)) {
            throw new Exception('El saldo manual necesita un importe.');
        }

        $obs = $campo('observacion');

        if (mb_strlen($obs) > self::OBSERVACION_MAX) {
            throw new Exception('La observación no puede superar los ' . self::OBSERVACION_MAX
                . ' caracteres.');
        }

        return [
            'nro_banco' => $banco,
            'nro_cuenta' => $cuenta,
            'moneda' => $moneda,
            'fecha_saldo' => $fecha,
            'saldo' => round(floatval($saldo), 2),
            'observacion' => ($obs === '') ? null : $obs
        ];
    }

    /** Un alias ya guardado, llevado a la misma forma que validarAlias() sin lanzar */
    private static function validarAliasGuardado($alias) {
        $alias = trim((string) $alias);

        return ($alias === '') ? null : $alias;
    }

    /**
     * Si el saldo de una fila no es de hoy.
     *
     * LA REGLA ES ESTRICTA: el proceso de BI integra todos los dias, asi que
     * el saldo tiene que ser del dia. Sin registro de hoy se marca, tambien a
     * la manana antes de que corra el proceso y los fines de semana. Una
     * tolerancia por hora o por dia habil esconderia justo el dia en que el
     * proceso no corrio. Una fila sin dato no se marca: ya tiene su propio
     * aviso, y "no es de hoy" de algo que no existe no dice nada.
     *
     * @param string|null $fecha 'Y-m-d' del saldo usado
     * @param string $hoy 'Y-m-d'
     * @return bool
     */
    public static function noEsDeHoy($fecha, $hoy) {
        return $fecha !== null && $fecha !== '' && substr((string) $fecha, 0, 10) < $hoy;
    }

    /**
     * El aviso de la pestana que lista las cuentas cuyo saldo no es de hoy,
     * cada una con su fecha. Null si todas son de hoy.
     *
     * Va SOLO en la pestana: el tablero tiene el suyo, que arma
     * Saldos::armarSerieDisponible() con cuantas cuentas, cuanta plata y la
     * fecha mas vieja, porque ahi lo que importa es cuanto del Saldo Inicial
     * no es de hoy y no el detalle de cada cuenta.
     *
     * @param array $filas Filas bancarias con 'no_es_de_hoy', 'nombre' y 'fecha_saldo'
     * @return array|null Aviso
     */
    public static function avisoNoEsDeHoy($filas) {
        $lista = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            if (!empty($f['no_es_de_hoy'])) {
                $lista[] = $f['nombre'] . ' (' . self::diaMes($f['fecha_saldo']) . ')';
            }
        }

        if (empty($lista)) {
            return null;
        }

        return Aviso::nuevo(Aviso::WARNING, 'No hay saldo de hoy de ' . count($lista)
            . ' cuenta(s) bancaria(s); se muestra el último que hay: ' . implode(', ', $lista) . '.',
            self::SECCION);
    }

    /**
     * Si una cuenta es nueva: viene en Interbanking y nadie la marco como
     * vista. Sin el script ($vistas null) ninguna lo es: sin la tabla no hay
     * forma de saber cuales se vieron, y marcarlas todas taparia las que de
     * verdad son nuevas el dia que se corra. Una cuenta que solo existe por
     * su respaldo no es nueva: no vino de Interbanking.
     *
     * @param string $clave
     * @param array $c La cuenta agrupada, con 'registros'
     * @param array|null $vistas
     * @return bool
     */
    public static function esNueva($clave, $c, $vistas) {
        return is_array($vistas) && !empty($c['registros']) && !isset($vistas[$clave]);
    }

    /**
     * Las claves de las cuentas nuevas de un banco, para marcarlas como
     * vistas. Las mismas que marca armarCuentasBancarias(): la misma regla.
     *
     * @param array $ultimos Lo que devolvio leerUltimos()
     * @param array $vistas
     * @param string $nro NRO_BANCO
     * @return array [['nro_banco', 'nro_cuenta', 'moneda'], ...]
     */
    public static function cuentasNuevasDe($ultimos, $vistas, $nro) {
        $nro = trim((string) $nro);
        $v = [];

        foreach (self::agruparPorCuenta($ultimos)['cuentas'] as $clave => $c) {
            if ($c['nro_banco'] === $nro && self::esNueva($clave, $c, is_array($vistas) ? $vistas : [])) {
                $v[] = ['nro_banco' => $c['nro_banco'], 'nro_cuenta' => $c['nro_cuenta'],
                        'moneda' => $c['moneda']];
            }
        }

        return $v;
    }

    /**
     * El aviso de cuando todavia no se corrio la depuracion de las cuentas
     * bancarias manuales. Ver getCuentasBancarias().
     *
     * Es critico: mientras tanto el disponible lleva los saldos manuales
     * viejos de los bancos y no los de Interbanking.
     *
     * @return array Aviso
     */
    public static function avisoSinDepurar() {
        return Aviso::nuevo(Aviso::DANGER, 'Los saldos bancarios todavía no se leen de Interbanking: '
            . 'falta correr ' . self::SCRIPT_DEPURACION . ' contra la base central. Hasta entonces '
            . 'los bancos salen de la carga manual, para no sumar dos veces el mismo banco.',
            self::SECCION);
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
     * Que saldo se usa para una cuenta: el de Interbanking o el respaldo
     * manual.
     *
     * GANA LA FECHA MAS NUEVA, Y A IGUAL FECHA GANA INTERBANKING, que es la
     * fuente oficial. Asi, cuando BI se arregla y vuelve a traer el contable,
     * manda solo, sin que nadie tenga que quitar el respaldo. Es la regla
     * inversa a la caja de los locales -alla gana el manual a igual fecha-
     * porque aca el manual no corrige un dato, tapa uno que falta.
     *
     * @param array|null $interbanking El registro elegido por elegirRegistro()
     * @param array|null $respaldo El respaldo vigente, con FECHA_SALDO
     * @return string|null ORIGEN_INTERBANKING, ORIGEN_RESPALDO, o null si no hay ninguno
     */
    public static function resolverSaldo($interbanking, $respaldo) {
        if ($respaldo === null) {
            return ($interbanking === null) ? null : self::ORIGEN_INTERBANKING;
        }

        if ($interbanking === null) {
            return self::ORIGEN_RESPALDO;
        }

        return ((string) $respaldo['FECHA_SALDO'] > (string) $interbanking['FECHA_OPERACION'])
            ? self::ORIGEN_RESPALDO : self::ORIGEN_INTERBANKING;
    }

    /**
     * Las filas bancarias de la pestana y del tablero, con sus avisos.
     *
     * ES EL UNICO LUGAR DONDE SE DECIDE QUE SALDO TIENE UNA CUENTA. La pestana
     * y el proveedor lo reciben por Saldos::getFilasDisponible(); si cada uno
     * resolviera por su lado, algun dia no sumarian lo mismo.
     *
     * LAS CUENTAS SON LAS DE INTERBANKING MAS LAS QUE TIENEN RESPALDO VIGENTE.
     * Un respaldo existe porque esa plata existe aunque BI no la traiga: si la
     * lista saliera solo de BI, con la lectura caida -o con una cuenta que BI
     * dejo de informar- el respaldo no entraria justo cuando hace falta.
     *
     * $ultimos === null significa que la lectura de BI fallo: va un aviso
     * critico y entran solo los respaldos, sin avisos por cuenta: el critico
     * ya dice que Interbanking no esta.
     *
     * UN BANCO INACTIVO NO EXISTE PARA LA PESTANA NI PARA EL TABLERO: sus
     * cuentas no salen, no usan su respaldo y no avisan nada, ni siquiera que
     * vinieron sin moneda. Inactivarlo es decir "este banco ya no se opera", y
     * un aviso sobre el seria ruido que tapa los que importan. Parametros lo
     * sigue mostrando.
     *
     * @param array|null $ultimos Lo que devolvio leerUltimos(), o null
     * @param array $ctx 'hoy' => 'Y-m-d', 'tango' => mapa NRO_BANCO => DESC_BANCO,
     *        'bancos' => mapa NRO_BANCO => ['ALIAS', 'ACTIVO'] (vacio sin script),
     *        'respaldos' => mapa clave => respaldo vigente (vacio sin script),
     *        'vistas' => mapa clave => fila de las cuentas vistas, o null sin script
     *        (null: ninguna se marca como nueva),
     *        'error' => texto de la falla de lectura
     * @return array ['filas' => [...], 'avisos' => [Aviso]]
     */
    public static function armarCuentasBancarias($ultimos, $ctx) {
        $vistas = (isset($ctx['vistas']) && is_array($ctx['vistas'])) ? $ctx['vistas'] : null;
        $tango = isset($ctx['tango']) && is_array($ctx['tango']) ? $ctx['tango'] : [];
        $bancos = isset($ctx['bancos']) && is_array($ctx['bancos']) ? $ctx['bancos'] : [];
        $respaldos = isset($ctx['respaldos']) && is_array($ctx['respaldos']) ? $ctx['respaldos'] : [];
        $hoy = isset($ctx['hoy']) ? substr((string) $ctx['hoy'], 0, 10) : date('Y-m-d');
        $leido = ($ultimos !== null);
        $avisos = [];
        $filas = [];

        if (!$leido) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los saldos bancarios de '
                . 'Interbanking' . (empty($ctx['error']) ? '' : ' (' . $ctx['error'] . ')')
                . '; el disponible no los incluye.', self::SECCION);
        }

        $agrupadas = self::agruparPorCuenta($leido ? $ultimos : []);
        $cuentas = $agrupadas['cuentas'];

        // Las cuentas con respaldo que BI no trajo, o que no se pudo leer
        foreach ($respaldos as $clave => $r) {
            if (!isset($cuentas[$clave])) {
                $cuentas[$clave] = [
                    'nro_banco' => trim((string) $r['NRO_BANCO']),
                    'nro_cuenta' => trim((string) $r['NRO_CUENTA']),
                    'moneda' => strtoupper(trim((string) $r['MONEDA'])),
                    'tipo_cuenta' => null,
                    'registros' => []
                ];
            }
        }

        ksort($cuentas);
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

        foreach ($cuentas as $clave => $c) {
            if (!self::bancoActivo($c['nro_banco'], $bancos)) {
                continue;
            }

            $banco = self::nombreBanco($c['nro_banco'], $tango, self::aliasDe($c['nro_banco'], $bancos));

            // Con alias el nombre ya no depende de Tango: no hay nada que avisar.
            if (!$banco['en_tango'] && trim((string) self::aliasDe($c['nro_banco'], $bancos)) === '') {
                $fueraDeTango[$c['nro_banco']] = true;
            }

            $eleccion = self::elegirRegistro($c['registros']);
            $respaldo = isset($respaldos[$clave]) ? $respaldos[$clave] : null;
            $fuente = self::resolverSaldo($eleccion['usado'], $respaldo);
            $nombre = $banco['nombre'] . ' · ' . $c['nro_cuenta'];

            if ($fuente === null && $leido) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $nombre . ': Interbanking nunca trajo su '
                    . 'saldo contable y no tiene carga manual de respaldo, así que no suma al '
                    . 'disponible. Cargalo desde Saldos › Saldos.', self::SECCION);
            } elseif ($fuente === self::ORIGEN_RESPALDO && $leido) {
                // Se acusa la falla de la integracion mientras se usa el
                // respaldo: es atencion, porque la plata esta, pero alguien
                // tiene que ver por que BI no la trae.
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Interbanking no trae el saldo contable '
                    . 'de ' . $nombre . '; se usa la carga manual del '
                    . self::diaMes($respaldo['FECHA_SALDO']) . '.', self::SECCION);
            } elseif ($fuente === self::ORIGEN_INTERBANKING && $eleccion['tapado']) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, $nombre . ': el registro del '
                    . self::diaMes($eleccion['mas_nuevo']['FECHA_OPERACION']) . ' vino sin saldo '
                    . 'contable; se muestra el del '
                    . self::diaMes($eleccion['usado']['FECHA_OPERACION']) . '.', self::SECCION);
            }

            $fila = self::fila($clave, $c, $banco, $eleccion, $respaldo, $fuente, $hoy);
            $fila['nueva'] = self::esNueva($clave, $c, $vistas);

            // Entra al tablero igual que cualquier otra: es plata que existe.
            // Lo que pide es que alguien la mire.
            if ($fila['nueva']) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Cuenta nueva en Interbanking: ' . $nombre
                    . ' (' . ($fila['cargada'] ? self::plataEn($fila['saldo'], $fila['moneda'])
                        : 'sin saldo contable') . '); revisala en Parámetros › Saldos.',
                    self::SECCION);
            }

            $filas[] = $fila;
        }

        foreach (array_keys($fueraDeTango) as $nro) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'El banco ' . $nro . ' de Interbanking no '
                . 'está en la tabla BANCO de Tango: se muestra como "Banco ' . $nro . '". '
                . 'Ponele un alias en Parámetros › Saldos.', self::SECCION);
        }

        return ['filas' => $filas, 'avisos' => $avisos];
    }

    /**
     * La lista de bancos de Parametros -> Saldos -> Bancos de Interbanking.
     *
     * UNA FILA POR BANCO: los que vienen en Interbanking mas los que tienen
     * fila de parametros aunque ya no vengan. Un banco que dejo de venir se
     * sigue mostrando para poder ver su alias y reactivarlo o no; sacarlo de
     * la lista dejaria una fila de parametros que nadie puede ver.
     *
     * A DIFERENCIA DE armarCuentasBancarias(), ACA ESTAN TODOS, activos e
     * inactivos: es la pantalla donde se decide eso. Por lo mismo no hay
     * saldos: es la lista de que cuentas trae cada banco, no cuanto tienen.
     *
     * @param array|null $ultimos Lo que devolvio leerUltimos(), o null si fallo
     * LA MARCA "NUEVA" ES LA MISMA QUE EN LA PESTANA (esNueva()), y como alla
     * no va en un banco inactivo: de un banco que no se opera no hay nada que
     * revisar. 'nuevas' cuenta las de cada banco, para el boton "Marcar como
     * vistas".
     *
     * @param array $ctx 'tango', 'bancos', 'respaldos', 'vistas' (ver armarCuentasBancarias())
     * @return array Lista ordenada por NRO_BANCO de ['nro_banco', 'desc_banco',
     *         'alias', 'activo', 'usuario_modif', 'fecha_modif', 'con_fila',
     *         'ultimo_dato', 'con_respaldo', 'nuevas', 'cuentas' => [['nro_cuenta',
     *         'tipo_cuenta', 'moneda', 'con_respaldo', 'ultimo_dato', 'nueva',
     *         'visto_por', 'visto_el'], ...]]
     */
    public static function armarBancos($ultimos, $ctx) {
        $vistas = (isset($ctx['vistas']) && is_array($ctx['vistas'])) ? $ctx['vistas'] : null;
        $tango = isset($ctx['tango']) && is_array($ctx['tango']) ? $ctx['tango'] : [];
        $bancos = isset($ctx['bancos']) && is_array($ctx['bancos']) ? $ctx['bancos'] : [];
        $respaldos = isset($ctx['respaldos']) && is_array($ctx['respaldos']) ? $ctx['respaldos'] : [];

        $agrupadas = self::agruparPorCuenta(is_array($ultimos) ? $ultimos : []);
        $lista = [];

        $banco = function ($nro) use (&$lista, $tango, $bancos) {
            if (!isset($lista[$nro])) {
                $p = isset($bancos[$nro]) ? $bancos[$nro] : null;
                $n = self::nombreBanco($nro, $tango);

                $lista[$nro] = [
                    'nro_banco' => $nro,
                    'desc_banco' => $n['desc_banco'],
                    'alias' => ($p === null) ? null : self::validarAliasGuardado($p['ALIAS']),
                    'activo' => self::bancoActivo($nro, $bancos),
                    'con_fila' => ($p !== null),
                    'usuario_modif' => ($p === null) ? null : $p['USUARIO_MODIF'],
                    'fecha_modif' => ($p === null) ? null : $p['FECHA_MODIF'],
                    'ultimo_dato' => null,
                    'con_respaldo' => false,
                    'nuevas' => 0,
                    'cuentas' => []
                ];
            }
        };

        foreach ($agrupadas['cuentas'] as $clave => $c) {
            $banco($c['nro_banco']);
            $eleccion = self::elegirRegistro($c['registros']);
            $ultimo = ($eleccion['mas_nuevo'] === null) ? null : $eleccion['mas_nuevo']['FECHA_OPERACION'];

            $nueva = $lista[$c['nro_banco']]['activo'] && self::esNueva($clave, $c, $vistas);
            $vista = (is_array($vistas) && isset($vistas[$clave])) ? $vistas[$clave] : null;

            $lista[$c['nro_banco']]['cuentas'][$clave] = [
                'clave' => $clave,
                'nro_cuenta' => $c['nro_cuenta'],
                'tipo_cuenta' => $c['tipo_cuenta'],
                'moneda' => $c['moneda'],
                'con_respaldo' => isset($respaldos[$clave]),
                'ultimo_dato' => $ultimo,
                'nueva' => $nueva,
                'visto_por' => ($vista === null) ? null : $vista['USUARIO_ALTA'],
                'visto_el' => ($vista === null) ? null : $vista['FECHA_ALTA']
            ];

            if ($nueva) {
                $lista[$c['nro_banco']]['nuevas']++;
            }

            if ($ultimo !== null && $ultimo > (string) $lista[$c['nro_banco']]['ultimo_dato']) {
                $lista[$c['nro_banco']]['ultimo_dato'] = $ultimo;
            }
        }

        // Una cuenta sin moneda se lista igual: es una cuenta del banco, y es
        // justamente la que hay que mirar.
        foreach ($agrupadas['sin_moneda'] as $c) {
            $banco($c['nro_banco']);
            $lista[$c['nro_banco']]['cuentas'][$c['nro_banco'] . '|' . $c['nro_cuenta'] . '|'] = [
                'clave' => null,
                'nro_cuenta' => $c['nro_cuenta'],
                'tipo_cuenta' => null,
                'moneda' => null,
                'con_respaldo' => false,
                'ultimo_dato' => null,
                'nueva' => false,
                'visto_por' => null,
                'visto_el' => null
            ];
        }

        foreach ($respaldos as $clave => $r) {
            $nro = trim((string) $r['NRO_BANCO']);
            $banco($nro);

            if (!isset($lista[$nro]['cuentas'][$clave])) {
                $lista[$nro]['cuentas'][$clave] = [
                    'clave' => $clave,
                    'nro_cuenta' => trim((string) $r['NRO_CUENTA']),
                    'tipo_cuenta' => null,
                    'moneda' => strtoupper(trim((string) $r['MONEDA'])),
                    'con_respaldo' => true,
                    'ultimo_dato' => null,
                    'nueva' => false,
                    'visto_por' => null,
                    'visto_el' => null
                ];
            }

            $lista[$nro]['con_respaldo'] = true;
        }

        foreach (array_keys($bancos) as $nro) {
            $banco(trim((string) $nro));
        }

        ksort($lista);

        foreach ($lista as $nro => $b) {
            ksort($b['cuentas']);
            $lista[$nro]['cuentas'] = array_values($b['cuentas']);
        }

        return array_values($lista);
    }

    /**
     * Una fila bancaria, con las mismas claves que Saldos::filaSaldo() para
     * que la pestana, los totales y la serie la traten igual que a una cuenta
     * manual. 'cargada' en false es "sin dato": el saldo va null, no cero.
     *
     * 'respaldo' es el vigente aunque no se este usando: el dialogo lo muestra
     * para reemplazarlo o quitarlo. 'interbanking' es lo que trajo BI, para
     * el tooltip cuando manda el respaldo.
     */
    private static function fila($clave, $c, $banco, $eleccion, $respaldo, $fuente, $hoy) {
        $ib = $eleccion['usado'];

        if ($fuente === self::ORIGEN_RESPALDO) {
            $saldo = floatval($respaldo['SALDO_CONTABLE']);
            $fecha = $respaldo['FECHA_SALDO'];
            $cargadoEl = $respaldo['FECHA_ALTA'];
            $cargadoPor = $respaldo['USUARIO_ALTA'];
        } elseif ($fuente === self::ORIGEN_INTERBANKING) {
            $saldo = floatval($ib['SALDO_CONTABLE']);
            $fecha = $ib['FECHA_OPERACION'];
            $cargadoEl = $ib['CREATED_AT'];
            $cargadoPor = null;
        } else {
            $saldo = null;
            $fecha = null;
            $cargadoEl = null;
            $cargadoPor = null;
        }

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
            'origen' => $fuente,
            'origen_cuenta' => self::ORIGEN_INTERBANKING,
            'cargada' => ($fuente !== null),
            'saldo' => $saldo,
            'fecha_saldo' => $fecha,
            'no_es_de_hoy' => self::noEsDeHoy($fecha, $hoy),
            'fecha_carga' => $cargadoEl,
            'usuario_carga' => $cargadoPor,
            'ultimo_registro' => ($eleccion['mas_nuevo'] === null)
                ? null : $eleccion['mas_nuevo']['FECHA_OPERACION'],
            'interbanking' => ($ib === null) ? null
                : ['fecha' => $ib['FECHA_OPERACION'], 'saldo' => floatval($ib['SALDO_CONTABLE'])],
            'respaldo' => ($respaldo === null) ? null : [
                'id' => intval($respaldo['ID']),
                'fecha_saldo' => $respaldo['FECHA_SALDO'],
                'saldo' => floatval($respaldo['SALDO_CONTABLE']),
                'observacion' => $respaldo['OBSERVACION'],
                'usuario' => $respaldo['USUARIO_ALTA'],
                'fecha_alta' => $respaldo['FECHA_ALTA']
            ]
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
     * SIN LA CONSTANCIA DE DEPURACION NO SE LEE INTERBANKING. El catalogo de
     * cuentas manuales no tiene NRO_BANCO, asi que el codigo no puede saber si
     * una cuenta manual TIPO 'BANCO' es un banco que ya viene por Interbanking:
     * si se leyeran los dos, ese banco sumaria dos veces. Mientras no se corra
     * SCRIPT_DEPURACION en modo real -que borra esas cuentas y deja la
     * constancia- todo sigue como antes, con las cuentas manuales, y un aviso
     * critico dice que falta. Asi el orden en que se publiquen el codigo y el
     * script no puede inflar el disponible.
     *
     * @param string $hoy 'Y-m-d'
     * @return array ['filas' => [...], 'avisos' => [Aviso], 'respaldo_creado' => bool,
     *               'depurado' => bool]
     */
    public function getCuentasBancarias($hoy) {
        try {
            $depurado = $this->depurado();
        } catch (Throwable $e) {
            return ['filas' => [], 'respaldo_creado' => false, 'depurado' => false,
                    'avisos' => [Aviso::nuevo(Aviso::DANGER, 'No se pudo verificar si ya se '
                        . 'depuraron las cuentas bancarias manuales (' . $e->getMessage() . '): '
                        . 'los saldos de Interbanking no se incluyen.', self::SECCION)]];
        }

        if (!$depurado) {
            return ['filas' => [], 'avisos' => [self::avisoSinDepurar()], 'respaldo_creado' => false,
                    'depurado' => false];
        }

        $ctx = ['hoy' => substr((string) $hoy, 0, 10), 'tango' => [], 'error' => null];
        $ultimos = null;

        try {
            $ultimos = $this->leerUltimos();
        } catch (Throwable $e) {
            $ctx['error'] = $e->getMessage();
        }

        $avisosExtra = [];

        // Los nombres se leen aunque BI no responda: los respaldos entran
        // igual, y sin nombres se verian como "Banco <nro>", con un aviso de
        // "no esta en Tango" que no seria cierto.
        try {
            $ctx['tango'] = $this->leerBancosTango();
        } catch (Throwable $e) {
            // Sin los nombres se muestra "Banco <nro>": el saldo sigue
            // siendo correcto, asi que es atencion y no critico.
            $avisosExtra[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer los nombres '
                . 'de los bancos de Tango (' . $e->getMessage() . ').', self::SECCION);
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

        try {
            $ctx['vistas'] = $this->leerVistas();
        } catch (Throwable $e) {
            // Sin poder leerlas no se marca ninguna: el saldo no cambia.
            $ctx['vistas'] = null;
            $avisosExtra[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo saber qué cuentas de '
                . 'Interbanking son nuevas (' . $e->getMessage() . ').', self::SECCION);
        }

        try {
            $ctx['respaldos'] = $this->leerRespaldos();
        } catch (Throwable $e) {
            $ctx['respaldos'] = [];
            $avisosExtra[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los saldos '
                . 'manuales de respaldo (' . $e->getMessage() . '): las cuentas que Interbanking '
                . 'no trae no suman al disponible.', self::SECCION);
        }

        $r = self::armarCuentasBancarias($ultimos, $ctx);
        $r['avisos'] = array_merge($avisosExtra, $r['avisos']);

        // Lo que la pestana necesita para saber si puede ofrecer el respaldo.
        try {
            $r['respaldo_creado'] = $this->tablas()['manual'];
        } catch (Throwable $e) {
            $r['respaldo_creado'] = false;
        }

        $r['depurado'] = true;

        return $r;
    }

    /**
     * Lo que necesita Parametros -> Saldos -> Bancos de Interbanking: la
     * lista de armarBancos() y que se puede hacer.
     *
     * Nunca lanza: sin BI la lista sale de los bancos con fila, y el aviso
     * dice por que faltan los demas. Sin el script los controles se apagan y
     * se dice cual correr.
     *
     * @return array ['bancos' => [...], 'avisos' => [string], 'bancos_creado' => bool,
     *                'vistas_creado' => bool, 'depurado' => bool]
     */
    public function getBancosParametros() {
        $avisos = [];
        $ctx = ['tango' => [], 'bancos' => [], 'respaldos' => [], 'vistas' => null];
        $tablas = ['banco' => false, 'manual' => false, 'vistas' => false];
        $depurado = false;

        try {
            $tablas = $this->tablas();
            $depurado = $this->depurado();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron verificar las tablas de Interbanking: ' . $e->getMessage();
        }

        if (!$tablas['banco'] || !$tablas['manual'] || !$tablas['vistas']) {
            $avisos[] = 'Todavía no existen las tablas de los bancos de Interbanking: corré '
                . self::SCRIPT . ' contra la base central. Mientras tanto todos los bancos cuentan '
                . 'como activos y sin alias, no se pueden editar, ninguna cuenta se marca como nueva '
                . 'y no se puede cargar un saldo manual de respaldo.';
        }

        if (!$depurado) {
            $avisos[] = self::avisoSinDepurar()['texto'];
        }

        $ultimos = null;

        try {
            $ultimos = $this->leerUltimos();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer las cuentas de Interbanking (' . $e->getMessage()
                . '): se listan solo los bancos que ya tienen alias o estado.';
        }

        foreach (['tango' => 'leerBancosTango', 'bancos' => 'leerBancos',
                  'respaldos' => 'leerRespaldos', 'vistas' => 'leerVistas'] as $k => $metodo) {
            try {
                $ctx[$k] = $this->$metodo();
            } catch (Throwable $e) {
                $avisos[] = $e->getMessage();
            }
        }

        return [
            'bancos' => self::armarBancos($ultimos, $ctx),
            'avisos' => $avisos,
            'bancos_creado' => $tablas['banco'],
            'vistas_creado' => $tablas['vistas'],
            'depurado' => $depurado
        ];
    }

    /**
     * Si ya se corrio la depuracion en modo real: la tabla existe y tiene al
     * menos una constancia. La tabla vacia no cuenta: la crea el script justo
     * antes de borrar, y vacia significaria que el borrado no termino.
     *
     * @return bool
     */
    public function depurado() {
        $cid = $this->conectar('central');
        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_DEPURACION . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la depuración'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row || $row['T'] === null) {
            return false;
        }

        $stmt = sqlsrv_query($cid, "SELECT TOP 1 ID FROM dbo." . self::TABLA_DEPURACION);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la constancia de depuración'));
        }

        $hay = is_array(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));
        sqlsrv_free_stmt($stmt);

        return $hay;
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
     * @return array ['banco' => bool, 'manual' => bool, 'vistas' => bool]
     */
    public function tablas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT OBJECT_ID('dbo." . self::TABLA_BANCO . "', 'U') AS B,
                    OBJECT_ID('dbo." . self::TABLA_MANUAL . "', 'U') AS M,
                    OBJECT_ID('dbo." . self::TABLA_VISTAS . "', 'U') AS V");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de Interbanking'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = [
            'banco' => ($row && $row['B'] !== null),
            'manual' => ($row && $row['M'] !== null),
            'vistas' => ($row && $row['V'] !== null)
        ];

        return $this->tablas;
    }

    /**
     * El respaldo VIGENTE de cada cuenta. Hay uno como maximo: lo garantiza
     * el indice unico filtrado del script. Vacio sin el script.
     *
     * @return array Mapa clave de cuenta => fila
     */
    public function leerRespaldos() {
        if (!$this->tablas()['manual']) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT ID, NRO_BANCO, NRO_CUENTA, MONEDA, FECHA_SALDO, SALDO_CONTABLE, OBSERVACION,
                    ID_REEMPLAZA, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA_MANUAL . "
             WHERE VIGENTE = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los saldos manuales de respaldo'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ID'] = intval($row['ID']);
            $row['FECHA_SALDO'] = Horizonte::normalizarFecha($row['FECHA_SALDO']);
            $row['SALDO_CONTABLE'] = floatval($row['SALDO_CONTABLE']);
            $row['FECHA_ALTA'] = self::fechaHora($row['FECHA_ALTA']);
            $v[self::claveCuenta($row['NRO_BANCO'], $row['NRO_CUENTA'], $row['MONEDA'])] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Las cuentas marcadas como vistas, o null sin el script (ninguna es
     * nueva).
     *
     * @return array|null Mapa clave de cuenta => ['USUARIO_ALTA', 'FECHA_ALTA']
     */
    public function leerVistas() {
        if (!$this->tablas()['vistas']) {
            return null;
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT NRO_BANCO, NRO_CUENTA, MONEDA, USUARIO_ALTA, FECHA_ALTA FROM dbo." . self::TABLA_VISTAS);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cuentas vistas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[self::claveCuenta($row['NRO_BANCO'], $row['NRO_CUENTA'], $row['MONEDA'])] = [
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
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

    /**
     * Marca como vistas TODAS las cuentas nuevas de un banco.
     *
     * Las cuentas se vuelven a leer de Interbanking: no se aceptan del
     * navegador, que podria mandar una que no existe y dejarla vista antes de
     * que llegue. Es por banco y no por cuenta porque es como se revisa: se
     * mira el banco en Interbanking y se confirma lo que trajo. En una
     * transaccion, con el usuario de la sesion como alta.
     *
     * @param string $nroBanco
     * @param string $usuario
     * @return int Cuantas cuentas se marcaron
     */
    public function marcarVistas($nroBanco, $usuario) {
        require_once __DIR__ . '/AuthCashflow.php';

        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablas()['vistas']) {
            throw new Exception('Todavía no existe la tabla de cuentas vistas: corré ' . self::SCRIPT
                . ' contra la base central.');
        }

        $nuevas = self::cuentasNuevasDe($this->leerUltimos(), $this->leerVistas(), $nroBanco);

        if (empty($nuevas)) {
            return 0;
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            foreach ($nuevas as $c) {
                // El NOT EXISTS evita chocar con la PK si otro la marco recien.
                $ok = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA_VISTAS . "
                        (NRO_BANCO, NRO_CUENTA, MONEDA, USUARIO_ALTA, USUARIO_MODIF)
                     SELECT ?, ?, ?, ?, ?
                     WHERE NOT EXISTS (SELECT 1 FROM dbo." . self::TABLA_VISTAS . "
                                       WHERE NRO_BANCO = ? AND NRO_CUENTA = ? AND MONEDA = ?)",
                    [$c['nro_banco'], $c['nro_cuenta'], $c['moneda'], $usuario, $usuario,
                     $c['nro_banco'], $c['nro_cuenta'], $c['moneda']]);

                if ($ok === false) {
                    throw new Exception($this->errorSql('Error al marcar la cuenta ' . $c['nro_cuenta']));
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudieron confirmar las cuentas vistas'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return count($nuevas);
    }

    /**
     * Carga, o reemplaza, el respaldo manual de una cuenta de Interbanking.
     *
     * REEMPLAZAR NO ES UN UPDATE: da de baja el vigente (VIGENTE = 0, con
     * usuario y fecha de baja) e inserta el nuevo apuntando al anterior por
     * ID_REEMPLAZA, en UNA transaccion. Si solo insertara, el anterior seguiria
     * vigente y una correccion con fecha anterior perderia contra el. El
     * indice unico filtrado del script es la segunda red: dos vigentes de la
     * misma cuenta no pueden existir.
     *
     * Se valida en el servidor que la cuenta exista -que venga en
     * Interbanking o que ya tenga respaldo- y que su banco este activo: un
     * banco inactivo no usa el respaldo, asi que cargarlo no tendria efecto y
     * nada lo diria.
     *
     * @param array $datos ['nro_banco', 'nro_cuenta', 'moneda', 'fecha_saldo', 'saldo', 'observacion']
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'; null es hoy
     * @return array ['id' => int, 'reemplaza' => int|null]
     */
    public function guardarRespaldo($datos, $usuario, $hoy = null) {
        require_once __DIR__ . '/AuthCashflow.php';
        require_once __DIR__ . '/Auditoria.php';

        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablas()['manual']) {
            throw new Exception('Todavía no existe la tabla de saldos manuales de respaldo: corré '
                . self::SCRIPT . ' contra la base central.');
        }

        // Sin depurar, Interbanking no se lee y el respaldo no tendria efecto.
        if (!$this->depurado()) {
            throw new Exception(self::avisoSinDepurar()['texto']);
        }

        $d = self::validarRespaldo($datos, ($hoy === null) ? date('Y-m-d') : $hoy);
        $clave = self::claveCuenta($d['nro_banco'], $d['nro_cuenta'], $d['moneda']);

        if (!self::bancoActivo($d['nro_banco'], $this->leerBancos())) {
            throw new Exception('El banco ' . $d['nro_banco'] . ' está inhabilitado: su saldo no se '
                . 'usa, así que no se le carga un respaldo. Reactivalo en Parámetros › Saldos.');
        }

        $conocida = isset($this->leerRespaldos()[$clave]);

        if (!$conocida) {
            try {
                $conocida = isset(self::agruparPorCuenta($this->leerUltimos())['cuentas'][$clave]);
            } catch (Throwable $e) {
                throw new Exception('No se pudo verificar la cuenta en Interbanking ('
                    . $e->getMessage() . '): el respaldo no se guardó.');
            }
        }

        if (!$conocida) {
            throw new Exception('La cuenta ' . $d['nro_banco'] . ' · ' . $d['nro_cuenta'] . ' en '
                . $d['moneda'] . ' no viene en Interbanking.');
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            // El vigente, bloqueado hasta el final: dos guardados simultaneos
            // no pueden dar de baja el mismo y dejar dos nuevos.
            $stmt = sqlsrv_query($cid,
                "SELECT ID FROM dbo." . self::TABLA_MANUAL . " WITH (UPDLOCK, HOLDLOCK)
                 WHERE NRO_BANCO = ? AND NRO_CUENTA = ? AND MONEDA = ? AND VIGENTE = 1",
                [$d['nro_banco'], $d['nro_cuenta'], $d['moneda']]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer el respaldo vigente'));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            $anterior = $row ? intval($row['ID']) : null;

            if ($anterior !== null) {
                $ok = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_MANUAL . "
                     SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                     WHERE ID = ? AND VIGENTE = 1",
                    [$usuario, $usuario, $anterior]);

                if ($ok === false) {
                    throw new Exception($this->errorSql('Error al dar de baja el respaldo anterior'));
                }
            }

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA_MANUAL . "
                    (NRO_BANCO, NRO_CUENTA, MONEDA, FECHA_SALDO, SALDO_CONTABLE, OBSERVACION,
                     VIGENTE, ID_REEMPLAZA, USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)",
                [$d['nro_banco'], $d['nro_cuenta'], $d['moneda'], $d['fecha_saldo'], $d['saldo'],
                 $d['observacion'], $anterior, $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el saldo manual'));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el saldo manual'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return ['id' => intval($row['ID']), 'reemplaza' => $anterior];
    }

    /**
     * Quita el respaldo vigente: lo da de baja y no inserta nada. La fila
     * queda, con quien y cuando la quito, como historial. Es lo que se hace
     * cuando BI ya trae el dato y el respaldo sobra -aunque no hace falta: un
     * Interbanking mas nuevo ya gana solo-, o cuando se cargo por error.
     *
     * @param int $id
     * @param string $usuario
     * @return bool
     */
    public function quitarRespaldo($id, $usuario) {
        require_once __DIR__ . '/AuthCashflow.php';
        require_once __DIR__ . '/Auditoria.php';

        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablas()['manual']) {
            throw new Exception('Todavía no existe la tabla de saldos manuales de respaldo: corré '
                . self::SCRIPT . ' contra la base central.');
        }

        $stmt = sqlsrv_query($this->conectar('central'),
            "UPDATE dbo." . self::TABLA_MANUAL . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al quitar el saldo manual'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas !== 1) {
            throw new Exception('Ese saldo manual ya no está vigente: alguien lo reemplazó o lo '
                . 'quitó. Actualizá la pantalla.');
        }

        return true;
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

    /** Formato de importe para los avisos, en su moneda: no se convierte nada */
    private static function plataEn($n, $moneda) {
        return (strtoupper((string) $moneda) === 'USD' ? 'US$ ' : '$ ')
            . number_format(floatval($n), 2, ',', '.');
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
