<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/Retenciones.php';
require_once __DIR__ . '/DiasHabiles.php';

/**
 * Echeqs
 * Cheques de terceros: los que estan en cartera y los que ya cobraron una venta
 * por adelantado.
 *
 * LAS DOS MITADES DEL MODULO, Y POR QUE NO SE PISAN
 * -------------------------------------------------
 *   Cheques en Cartera        -> ESTADO = 'C'. Es plata que va a entrar, asi que
 *                                alimenta la fila "Echeqs en cartera" de
 *                                DISPONIBILIDADES a traves de EcheqsProvider.
 *   Venta Cobrada Anticipada  -> cheques de clientes que pre-chequean. NO
 *                                alimentan ninguna serie del tablero: su efecto
 *                                es RESTAR de la cobranza proyectada de Ventas,
 *                                porque esa venta ya se cobro.
 *
 * UN MISMO CHEQUE PUEDE ESTAR EN LAS DOS PANTALLAS y eso da el numero justo:
 *
 *     + importe   en "Echeqs en cartera"    (la plata existe, va a entrar)
 *     - importe   en la cobranza de Ventas  (la venta que prepago no se cobra
 *                                            de nuevo)
 *     ----------------------------------------------------------------------
 *     = contado una sola vez
 *
 * Es lo primero que alguien va a querer "arreglar" al ver el cheque repetido.
 *
 * LOS DOS TILDES DE ESTE MODULO NO SON EL MISMO, Y NO SE CRUZAN
 * -------------------------------------------------------------
 * Cada sub-pestana tiene un tilde y contestan preguntas distintas:
 *
 *   Cartera        EXCLUIR   "esta plata, ¿va a entrar?"
 *                            Un cheque que no se va a poder cobrar sale de la
 *                            serie del tablero. Vive en
 *                            RO_T_CASHFLOW_ECHEQ_EXCLUIDO.
 *
 *   Prechequeado   MARCAR    "esta venta, ¿ya se cobro?"
 *                            Decide que se resta de la cobranza proyectada de
 *                            Ventas. Vive en RO_T_CASHFLOW_ECHEQ_PRECHEQ.
 *
 * UN MISMO CHEQUE PUEDE TENER LOS DOS Y NO SE PISAN: que no vaya a entrar no
 * dice nada sobre si la venta que prepago hay que netearla. Por eso no hay
 * ningun join entre las dos tablas y por eso la exclusion NO toca el
 * pre-chequeado. Ver el encabezado de sql/cashflow_echeqs_excluir.sql.
 *
 * DOS FECHAS, DOS FUNCIONES DISTINTAS
 * -----------------------------------
 * En Venta Cobrada Anticipada cada cheque tiene dos fechas, y NO hacen lo mismo:
 *
 *   FECHA_VENTA_EST   decide QUE se muestra y que netea.
 *   (cheque - dias)   Si la venta teorica quedo antes de hoy, esa venta ya se
 *                     facturo y ya se cobro: el cheque no esta en la lista y no
 *                     netea nada. Lo resuelve ventaYaCobrada(), la unica
 *                     funcion, usada por cruzarPrechequeado() y por
 *                     Ventas::repartirNeteo().
 *
 *   FECHA_CHEQUE      decide DONDE cae el importe, en la grilla y en el
 *                     tablero. Es cuando entra la plata.
 *
 * ANTES LAS DOS COSAS LAS HACIA LA FECHA TEORICA. El criterio era que el neteo
 * cayera donde esta la cobranza proyectada de esa venta; se cambio porque lo que
 * interesa es cuando entra la plata del cheque. FECHA_VENTA_EST sigue viajando
 * en la fila y sigue siendo columna visible: es el dato que explica por que ese
 * cheque esta en la lista.
 *
 * LA PANTALLA Y EL NETEO SE MUEVEN JUNTOS. EcheqsController arma el eje de la
 * sub-pestana con 'FECHA_CHEQUE' y Ventas::repartirNeteo() ubica con
 * $fila['FECHA_CHEQUE']. Si solo cambiara uno, el usuario tildaria un cheque en
 * una columna y el tablero lo restaria en otra, sin ninguna pantalla donde
 * notarlo. Es el mismo motivo por el que ventaYaCobrada() es una sola funcion.
 *
 * CONSECUENCIA: la fecha del cheque es siempre POSTERIOR O IGUAL a la teorica,
 * asi que un cheque puede tener su venta adentro del eje y su fecha afuera. Ese
 * importe ya no se descarta callado: va a 'fuera_horizonte' y deja aviso. Ver
 * el encabezado de Ventas::repartirNeteo().
 *
 * TRES TRAMPAS DEL ESQUEMA DE dbo.SBA14
 * -------------------------------------
 * 1. LA PK ES ID_SBA14. N_CHEQUE no identifica nada: se repite entre bancos y
 *    entre anios. Toda marca y todo endpoint de esta clase van por ID_SBA14.
 * 2. N_CHEQUE es ENTEROXL_TG, un alias de float(53). Sin CAST(... AS BIGINT) el
 *    numero de cheque sale en notacion cientifica en la pantalla.
 * 3. FECHA_CHEQ es nullable pero su default es '1800/01/01' y no NULL, asi que
 *    los cheques sin fecha real traen esa fecha centinela. El filtro
 *    >= CAST(GETDATE() AS DATE) ya los deja afuera y por eso no hay ninguna
 *    condicion extra: quien vea el contador 'sin_fecha' siempre en cero no tiene
 *    que sospechar que esta roto.
 *
 * EL UNIVERSO ES [FL]: FRANQUICIAS Y LOCALES
 * ------------------------------------------
 * Todas las consultas filtran CLIENTE LIKE '[FL]%'. La columna es
 * Latin1_General_BIN, o sea que la comparacion distingue mayusculas; verificado
 * contra la base, los codigos empiezan en 'F' o 'M' y ninguno en minuscula. Los
 * 'M' (mayoristas) quedan fuera del modulo a proposito.
 */
class Echeqs {

    /** Cheque de terceros todavia en cartera: es el que suma al disponible */
    const ESTADO_CARTERA = 'C';

    /**
     * Estados que no representan plata: 'X' anulado y 'R' rechazado.
     * Un cheque que pasa a uno de estos deja de netear SOLO, sin que nadie tenga
     * que acordarse de destildarlo.
     */
    const ESTADOS_MUERTOS = ['X', 'R'];

    /** El tilde lo hereda el cheque por estar su cliente en el maestro */
    const ORIGEN_CLIENTE = 'cliente';

    /** El tilde -o el destilde- lo puso una persona sobre ese cheque */
    const ORIGEN_CHEQUE = 'cheque';

    /**
     * Exclusiones de cartera. Una fila por decision, con historial: volver a
     * incluir marca VIGENTE = 0 y no borra nada.
     */
    const TABLA_EXCLUIDO = 'RO_T_CASHFLOW_ECHEQ_EXCLUIDO';

    /** Largo util del motivo, el mismo de la columna */
    const MOTIVO_MAX = 200;

    /**
     * Depositos de cheques de cartera. Una fila por deposito, con historial:
     * deshacer marca VIGENTE = 0 y no borra nada.
     */
    const TABLA_DEPOSITO = 'RO_T_CASHFLOW_ECHEQ_DEPOSITO';

    /**
     * Las alicuotas de retencion de los cheques depositados. Es un juego PROPIO
     * y no filas en la de Cob. Electronicos: un cambio en la tasa de Payway no
     * puede mover el neto de un cheque ni al reves.
     */
    const TABLA_ALICUOTA = 'RO_T_CASHFLOW_ECHEQ_ALICUOTA';

    /** Los dos scripts del circuito de deposito, para los mensajes */
    const SCRIPT_DEPOSITO = 'sql/cashflow_echeqs_deposito.sql';
    const SCRIPT_FILA_DEPOSITADOS = 'sql/cashflow_estructura_echeqs_depositados.sql';

    /**
     * El plazo de acreditacion, en RO_T_CASHFLOW_PARAMETROS.
     *
     * Lleva el prefijo del modulo, como 'exportaciones_tasky_dias_cobro': la
     * tabla clave/valor es una sola para todo el cashflow, y una clave como
     * 'dias_acreditacion' a secas se leeria como de cualquier pestana.
     */
    const CLAVE_PLAZO = 'echeqs_dias_acreditacion_deposito';

    /**
     * El plazo mas largo que se acepta. No es una regla del banco: es el tope
     * de un error de tipeo. Coincide con DiasHabiles::MAX_CORRIMIENTO, y un
     * plazo mas largo que eso no se distingue de un calendario roto.
     */
    const PLAZO_MAX = 30;

    /** Largo util de la observacion de un deposito, el de la columna */
    const OBSERVACION_MAX = 200;

    /**
     * Los textos de la tasa de los cheques, para Retenciones::resolverTasa().
     * La regla es la de Cob. Electronicos; lo que cambia es de quien son las
     * alicuotas y donde se cargan.
     */
    const TEXTOS_TASA = [
        'sin_vigentes' => 'Los cheques no tienen',
        'de' => 'de los cheques',
        'que' => 'del depósito',
        'donde' => 'Parámetros → Cheques'
    ];

    /** @var bool|null Cache del chequeo de tablas creadas */
    private $tablas = null;

    /**
     * @var bool|null Cache del chequeo de la tabla de exclusiones.
     * VA APARTE de $tablas y no junto con las otras dos: la exclusion de cartera
     * y el maestro de pre-chequeado son dos scripts independientes, y quien
     * corrio uno solo tiene que perder unicamente esa funcion.
     */
    private $tablaExcluido = null;

    /**
     * @var bool|null Cache del chequeo de las tablas del deposito. Aparte de
     * las otras dos por el mismo motivo: es un script independiente, y quien
     * no lo corrio pierde solo el deposito.
     */
    private $tablasDeposito = null;

    /** @var Conexion */
    private $conn;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       HELPERS PUROS

       Van estaticos y sin tocar la base para poder probarlos sin SQL Server.
       ==================================================================== */

    /**
     * Canal del modelo al que pertenece un codigo de cliente.
     *
     * El prefijo del codigo es el unico dato de canal que hay en dbo.SBA14, y es
     * confiable porque es la convencion con la que Tango los da de alta: 'FR...'
     * es una franquicia y 'L...' un local propio.
     *
     * Existe para poder imputar el neteo de cheques adelantados al canal que
     * corresponde en vez de restarlo solo del total: sin esto, la fila total de
     * Ventas y su apertura por canal dejan de reconciliar en cuanto el neteo
     * deja de ser cero.
     *
     * @param string $codigo Codigo de cliente de dbo.SBA14.CLIENTE
     * @return string|null Canal de Parametros::CANALES, o null si no se puede
     *         derivar
     */
    public static function canalDeCliente($codigo) {
        $inicial = strtoupper(substr(trim((string) $codigo), 0, 1));

        if ($inicial === 'F') {
            return 'FRANQUICIAS';
        }

        if ($inicial === 'L') {
            return 'LOCALES';
        }

        return null;
    }

    /**
     * Valida y normaliza el motivo de una exclusion.
     *
     * ES OBLIGATORIO, Y SE VALIDA EN EL BACK. El endpoint es alcanzable sin
     * pasar por la grilla, y un cheque sacado del cashflow sin motivo no lo
     * explica nadie tres meses despues. Es la misma regla que
     * Proveedores::saveExclusionMasiva() y por el mismo motivo.
     *
     * Estatica y pura.
     *
     * @param mixed $motivo
     * @param int $cuantos Cuantos cheques, para que el mensaje sea el del gesto
     * @return string
     */
    public static function validarMotivoExclusion($motivo, $cuantos = 1) {
        $texto = ($motivo === null) ? '' : trim((string) $motivo);

        if ($texto === '') {
            throw new Exception('Poné el motivo por el que ' . ($cuantos === 1
                    ? 'este cheque no se va a poder cobrar'
                    : 'estos cheques no se van a poder cobrar')
                . '. Sin motivo, dentro de tres meses nadie va a poder explicar por qué '
                . 'falta ese importe en el disponible.');
        }

        return mb_substr($texto, 0, self::MOTIVO_MAX);
    }

    /**
     * Cuanto de lo que se muestra esta excluido, y con que motivos.
     *
     * LO QUE SE EXCLUYE NO DESAPARECE, SE INFORMA. Los excluidos se esconden por
     * defecto en la pantalla, asi que sin este resumen la unica forma de notar
     * que hay plata afuera seria acordarse de prender el interruptor. Es el
     * mismo criterio de ProveedoresProvider::avisarExcluidasAMano().
     *
     * LOS TOTALES POR VISTA SALEN DE ACA Y NO DEL NAVEGADOR. Las filas ya traen
     * sus importes por columna resueltos por EjeVista, asi que las tres
     * tarjetas de la pestana pueden mostrar el neto -lo que de verdad entra al
     * cashflow- sin que el front reste nada. El front no calcula.
     *
     * Estatica y pura: recibe las filas ya armadas.
     *
     * @param array $filas Filas del payload de EjeVista, con 'EXCLUIDO'
     * @return array
     */
    public static function resumenExcluidos($filas) {
        $r = [
            'cheques' => 0,
            'importe' => 0,
            'total_tramo' => 0,
            'total_meses' => 0,
            'total_horizonte' => 0,
            'motivos' => []
        ];

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['EXCLUIDO'])) {
                continue;
            }

            $r['cheques']++;
            $r['importe'] += floatval($f['IMPORTE']);

            foreach (['total_tramo', 'total_meses', 'total_horizonte'] as $t) {
                $r[$t] += isset($f[$t]) ? floatval($f[$t]) : 0;
            }

            $m = trim((string) (isset($f['MOTIVO_EXCLUSION']) ? $f['MOTIVO_EXCLUSION'] : ''));

            if ($m !== '' && !in_array($m, $r['motivos'], true)) {
                $r['motivos'][] = $m;
            }
        }

        return $r;
    }

    /**
     * Los tres totales de la pestana SIN lo excluido.
     *
     * Es lo que las tarjetas tienen que mostrar: el total con los excluidos
     * adentro diria que esa plata entra, que es justamente lo que el tilde
     * niega. La resta se hace aca y no en el JS porque en este modulo el front
     * no calcula: los numeros salen del motor.
     *
     * Estatica y pura.
     *
     * @param array $totales Los de EjeVista::armar()
     * @param array $excluidos Los de resumenExcluidos()
     * @return array Con las mismas claves que $totales
     */
    public static function totalesNetos($totales, $excluidos) {
        $netos = is_array($totales) ? $totales : [];

        foreach (['total_tramo', 'total_meses', 'total_horizonte'] as $t) {
            $netos[$t] = (isset($totales[$t]) ? floatval($totales[$t]) : 0)
                - (isset($excluidos[$t]) ? floatval($excluidos[$t]) : 0);
        }

        return $netos;
    }

    /* ====================================================================
       DEPOSITO: EL PLAZO Y LAS ALICUOTAS

       Puros: reciben lo ya leido de la base. El plazo y las alicuotas se
       editan en Parametros -> Cheques; aca se decide que valor sirve.
       ==================================================================== */

    /**
     * Valida un plazo de acreditacion. LANZA con el motivo.
     *
     * UN ENTERO DE 1 EN ADELANTE: con cero el cheque acreditaria el mismo dia
     * del deposito, que es justo lo que el plazo existe para no suponer. El
     * tope -PLAZO_MAX- no es del banco: es el de un error de tipeo, y coincide
     * con el corrimiento maximo de DiasHabiles.
     *
     * @param mixed $dias
     * @return int
     */
    public static function validarPlazo($dias) {
        $texto = trim((string) $dias);

        if ($texto === '' || !preg_match('/^\d+$/', $texto)) {
            throw new Exception('El plazo de acreditación tiene que ser un número entero de días '
                . 'hábiles: 2 son las 48 hs hábiles.');
        }

        $n = intval($texto);

        if ($n < 1) {
            throw new Exception('El plazo de acreditación tiene que ser de al menos un día hábil: '
                . 'con cero, el cheque acreditaría el mismo día en que se deposita.');
        }

        if ($n > self::PLAZO_MAX) {
            throw new Exception('El plazo de acreditación no puede superar los '
                . self::PLAZO_MAX . ' días hábiles.');
        }

        return $n;
    }

    /**
     * El plazo que sirve para depositar, a partir de lo que hay en la base.
     *
     * UN PLAZO QUE FALTA O ESTA ROTO ES null CON EL MOTIVO, nunca un 2 de
     * respaldo: el plazo es un valor de negocio y vive en Parametros. Un
     * respaldo escrito aca depositaria con un numero que nadie eligio y que en
     * la pantalla se veria igual que uno configurado.
     *
     * @param string|null $valor Lo que dice la fila, o null si no existe
     * @return array ['dias' => int|null, 'error' => string|null]
     */
    public static function plazoDesdeValor($valor) {
        if ($valor === null) {
            return ['dias' => null, 'error' => 'Falta el parámetro ' . self::CLAVE_PLAZO
                . ': corré ' . self::SCRIPT_DEPOSITO . ' contra la base central.'];
        }

        try {
            return ['dias' => self::validarPlazo($valor), 'error' => null];
        } catch (Exception $e) {
            return ['dias' => null, 'error' => 'El plazo de acreditación guardado ("'
                . trim((string) $valor) . '") no sirve: ' . $e->getMessage()
                . ' Corregilo en Parámetros → Cheques.'];
        }
    }

    /**
     * Valida el numero de una alicuota que llega del navegador. LANZA.
     *
     * Va aparte de Retenciones::validarAlicuota() porque aquella recibe un
     * numero y lo convierte sin preguntar: un texto llegaria como cero, y una
     * retencion en cero guardada por un tipeo es un neto igual al bruto que
     * nadie eligio.
     *
     * @param mixed $alicuota Fraccion: 2,5% es 0,025
     * @return float
     */
    public static function numeroAlicuota($alicuota) {
        if ($alicuota === null || $alicuota === '' || is_bool($alicuota)
            || !is_numeric($alicuota)) {
            throw new Exception('La alícuota tiene que ser un número: 2,5% se carga como 2,5 en '
                . 'la pantalla y viaja como 0,025.');
        }

        return floatval($alicuota);
    }

    /**
     * Marca en cada alicuota si es la que rige HOY para su concepto.
     *
     * La pantalla muestra las historicas a proposito -son las que explican la
     * tasa de un deposito de ese periodo- y tiene que distinguir cual manda. Lo
     * decide Retenciones::alicuotasVigentes(), la misma regla que calcula la
     * tasa: si el navegador eligiera por su cuenta, podria marcar como vigente
     * una que el servidor no usa.
     *
     * @param array $alicuotas Filas de la tabla, todas
     * @param string $hoy 'Y-m-d'
     * @return array Las mismas filas, con 'VIGENTE_HOY'
     */
    public static function marcarVigentesHoy($alicuotas, $hoy) {
        $ids = [];

        foreach (Retenciones::alicuotasVigentes($alicuotas, $hoy) as $v) {
            $ids[$v['id']] = true;
        }

        $v = [];

        foreach (is_array($alicuotas) ? $alicuotas : [] as $a) {
            $a['VIGENTE_HOY'] = isset($ids[intval($a['ID'])]);
            $v[] = $a;
        }

        return $v;
    }

    /**
     * La tasa de los cheques a una fecha, para la pantalla: el numero, el
     * desglose y cuantos conceptos.
     *
     * SIN ALICUOTAS 'tasa' ES null, NO 0. La pantalla dice "sin alícuotas":
     * un 0% se leeria como "el banco no retiene nada", y con eso no se puede
     * depositar.
     *
     * @param array $alicuotas
     * @param string $fecha 'Y-m-d'
     * @return array ['tasa' => float|null, 'conceptos' => int, 'desglose' => string]
     */
    public static function tasaParaPantalla($alicuotas, $fecha) {
        $r = Retenciones::tasaRetencion($alicuotas, $fecha);

        return [
            'tasa' => ($r['conceptos'] === 0) ? null : $r['tasa'],
            'conceptos' => $r['conceptos'],
            'desglose' => Retenciones::desglose($r['detalle'])
        ];
    }

    /* ====================================================================
       DEPOSITO: LAS REGLAS

       Un deposito se calcula en el servidor y se guarda entero: banco, fechas,
       plazo, bruto, tasa y neto. Del navegador llegan solo los ids de los
       cheques. Cada regla tiene UNA funcion, y la usan la pestana, el
       guardado, el recalculo y el proveedor: si dos caminos resolvieran la
       acreditacion o el estado de un deposito con criterios distintos, el
       cheque se veria en una columna y el tablero lo sumaria en otra.
       ==================================================================== */

    /** El deposito se proyecta: acredita de manana en adelante y sigue en cartera */
    const DEPOSITO_PROYECTA = 'PROYECTA';

    /**
     * El deposito ya acredito -hoy o antes- y el cheque sigue en cartera en
     * Tango: la plata ya esta en el banco y falta registrarlo.
     */
    const DEPOSITO_ACREDITADO = 'ACREDITADO';

    /**
     * La fecha de acreditacion de un deposito: el N-esimo dia habil bancario
     * POSTERIOR al del deposito, con N = el plazo de Parametros.
     *
     * LA FECHA DEL CHEQUE NO INTERVIENE, y es una decision de negocio: el banco
     * acredita a las 48 hs habiles de recibir el deposito, sea cual sea la
     * fecha escrita en el cheque. Un cheque fechado dentro de veinte dias
     * depositado hoy acredita el miercoles, no dentro de veinte dias.
     *
     * Con 2: lunes -> miercoles, viernes -> martes, sabado o domingo -> martes.
     * Un deposito hecho un feriado cuenta desde el habil siguiente. La mecanica
     * es DiasHabiles::habilesDespues(), con el respaldo de lunes a viernes del
     * resto del modulo y su aviso de calendario faltante.
     *
     * SIN NINGUN HABIL EN EL TOPE LA FECHA ES null: un deposito no puede
     * acreditar en una fecha inventada, y quien llama lo rechaza.
     *
     * @param string $fechaDeposito 'Y-m-d'
     * @param int $plazo Dias habiles
     * @param array $habiles Mapa 'Y-m-d' => bool de RO_T_CALENDARIO
     * @return array ['fecha' => 'Y-m-d'|null, 'faltan' => ['Y-m'], 'sin_habil' => bool,
     *                'avisos' => [...]]
     */
    public static function fechaAcreditacion($fechaDeposito, $plazo, $habiles) {
        $r = DiasHabiles::habilesDespues($fechaDeposito, $plazo, $habiles);
        $avisos = DiasHabiles::avisosCalendario($r['faltan']);

        if ($r['sin_habil']) {
            $avisos[] = DiasHabiles::avisoSinHabil($fechaDeposito);
        }

        return [
            'fecha' => $r['sin_habil'] ? null : $r['fecha'],
            'faltan' => $r['faltan'],
            'sin_habil' => $r['sin_habil'],
            'avisos' => $avisos
        ];
    }

    /**
     * Desde y hasta que dia hay que leer el calendario para un deposito de hoy.
     *
     * Cada dia habil del plazo puede correrse hasta DiasHabiles::MAX_CORRIMIENTO
     * dias. Pedir de menos haria que el respaldo de lunes a viernes se
     * disparara por un rango corto y no por un calendario que falta, con un
     * aviso que no describe nada real. Mismo criterio que
     * Saldos::rangoCalendarioAcreditacion().
     *
     * @param string $hoy 'Y-m-d'
     * @param int $plazo
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoCalendarioDeposito($hoy, $plazo) {
        $dias = max(1, intval($plazo)) * DiasHabiles::MAX_CORRIMIENTO;

        return [
            'desde' => $hoy,
            'hasta' => date('Y-m-d', strtotime($hoy . ' +' . $dias . ' day'))
        ];
    }

    /**
     * En que estado esta un deposito VIGENTE, hoy.
     *
     * UN DEPOSITO SE PROYECTA MIENTRAS SE CUMPLAN DOS COSAS:
     *
     *   1. el cheque sigue en cartera en Tango (ESTADO = 'C'), y
     *   2. acredita DE MANANA EN ADELANTE.
     *
     * Deja de proyectarse solo, sin que nadie toque la fila:
     *
     *   - SE REGISTRO EL DEPOSITO EN TANGO -el cheque salio de cartera, en
     *     cualquier estado-: ya no esta en la lectura. Es lo esperado y no
     *     avisa. Un rechazo ('R', 'X') sale por el mismo camino.
     *   - LLEGO SU DIA DE ACREDITACION Y SIGUE EN CARTERA: la plata ya esta -o
     *     va a estar al cierre- en el saldo bancario de la pestana Saldos, asi
     *     que sumarlo tambien en esta fila la contaria dos veces. Es el corte de
     *     Cob. Electronicos, Retenciones::cortePendientes(), y es el MISMO a
     *     proposito: la misma acreditacion de hoy no puede contarse en una fila
     *     y no en la otra. No suma a ninguna fila y AVISA, porque en Tango
     *     falta registrar el deposito.
     *
     * @param array|null $deposito Con 'fecha_acreditacion', o null si no hay
     * @param bool $enCartera
     * @param string $hoy 'Y-m-d'
     * @return string|null DEPOSITO_PROYECTA, DEPOSITO_ACREDITADO, o null si no
     *         hay deposito o el cheque ya salio de cartera
     */
    public static function estadoDeposito($deposito, $enCartera, $hoy) {
        if (empty($deposito) || !$enCartera) {
            return null;
        }

        $acredita = Horizonte::normalizarFecha($deposito['fecha_acreditacion']);

        if ($acredita !== null && $acredita >= Retenciones::cortePendientes($hoy)) {
            return self::DEPOSITO_PROYECTA;
        }

        return self::DEPOSITO_ACREDITADO;
    }

    /**
     * Si un cheque en cartera se lee en la pestana y en el tablero.
     *
     * LOS NO DEPOSITADOS, DESDE HOY: el filtro de siempre, que deja afuera la
     * fecha centinela 1800-01-01 sin ninguna condicion extra.
     *
     * LOS DEPOSITADOS, AUNQUE SU FECHA YA HAYA PASADO. Un cheque con fecha de
     * hoy depositado hoy acredita pasado manana: con el filtro de siempre,
     * manana saldria de la lectura y desapareceria del flujo antes de
     * acreditar. Mientras su deposito este VIGENTE se lee, y estadoDeposito()
     * decide si se proyecta o avisa.
     *
     * UN DEPOSITO DESHECHO NO CUENTA. Deshacer da de baja la fila, y el cheque
     * vuelve a la regla de los no depositados: si su fecha ya paso, no
     * reaparece. Por eso la consulta une el deposito con VIGENTE = 1 EN LA
     * CONDICION DE LA UNION y no en el WHERE -ver sqlCartera()-: en el WHERE,
     * un deposito dado de baja seguiria trayendo la fila.
     *
     * La consulta aplica el mismo filtro como optimizacion; la regla que
     * decide es esta, y esta probada.
     *
     * @param array $fila Con FECHA_CHEQUE y DEPOSITO
     * @param string $hoy 'Y-m-d'
     * @return bool
     */
    public static function visibleEnCartera($fila, $hoy) {
        if (!empty($fila['DEPOSITO'])) {
            return true;
        }

        $fecha = isset($fila['FECHA_CHEQUE']) ? $fila['FECHA_CHEQUE'] : null;

        return $fecha !== null && $fecha >= $hoy;
    }

    /**
     * Donde cae una fila de cartera en el eje, y con que importe.
     *
     *   No depositada          la fecha del cheque, por el bruto. Como siempre.
     *   Depositada, se proyecta la fecha de ACREDITACION, por el NETO.
     *   Acreditada sin registrar en ninguna columna: ya esta en el saldo
     *                          bancario. Se ve en la grilla con su marca.
     *
     * Las columnas Acredita, Retencion y Neto van vacias -null- en las no
     * depositadas: no son cero, no hay deposito.
     *
     * @param array $fila Con FECHA_PAGO, IMPORTE y DEPOSITO
     * @param string $hoy 'Y-m-d'
     * @return array La fila con ESTADO_DEPOSITO, FECHA_EJE, IMPORTE_EJE,
     *         ACREDITA, RETENCION y NETO
     */
    public static function ubicarEnEje($fila, $hoy) {
        $d = isset($fila['DEPOSITO']) ? $fila['DEPOSITO'] : null;
        $estado = self::estadoDeposito($d, true, $hoy);

        $fila['ESTADO_DEPOSITO'] = $estado;
        $fila['ACREDITA'] = $d ? $d['fecha_acreditacion'] : null;
        $fila['RETENCION'] = $d ? round($d['bruto'] - $d['neto'], 4) : null;
        $fila['NETO'] = $d ? $d['neto'] : null;

        if ($estado === self::DEPOSITO_PROYECTA) {
            $fila['FECHA_EJE'] = $d['fecha_acreditacion'];
            $fila['IMPORTE_EJE'] = $d['neto'];
        } elseif ($estado === self::DEPOSITO_ACREDITADO) {
            $fila['FECHA_EJE'] = null;
            $fila['IMPORTE_EJE'] = 0.0;
        } else {
            $fila['FECHA_EJE'] = isset($fila['FECHA_PAGO']) ? $fila['FECHA_PAGO'] : null;
            $fila['IMPORTE_EJE'] = floatval($fila['IMPORTE']);
        }

        return $fila;
    }

    /**
     * Que cheques de un pedido se pueden depositar juntos. ES TODO O NADA: con
     * un solo cheque que no cumple, no se deposita ninguno.
     *
     * LAS REGLAS, en el orden en que se informan:
     *
     *   - EN CARTERA HOY. Se valida contra la lectura de la base, no contra lo
     *     que manda el navegador: un id no es una autorizacion. Es el criterio
     *     de excluirCheques().
     *   - NO DEPOSITADO. Un deposito vigente no se vuelve a hacer: se deshace.
     *   - NO EXCLUIDO. Un cheque que se decidio que no va a entrar no se
     *     deposita: primero hay que devolverlo al cashflow.
     *   - CON BANCO. La tanda se arma por banco emisor, y sin banco no hay
     *     tanda a la que pertenezca.
     *   - CON IMPORTE. Un deposito de cero o negativo restaria del tablero.
     *   - UN SOLO BANCO EMISOR, comparado por ID_BANCO y no por el nombre: dos
     *     bancos con la misma descripcion son dos bancos.
     *
     * POR QUE TODO O NADA y no "se deposita lo que se puede": el deposito es una
     * tanda real que alguien llevo al banco. Si una parte se guarda y otra no,
     * la pantalla dice algo distinto de lo que paso en el banco.
     *
     * @param array $ids Ids de dbo.SBA14 pedidos
     * @param array $cartera Filas de getEcheqsCartera()
     * @return array ['filas' => [...], 'rechazos' => [['id', 'n_cheque', 'motivo']],
     *         'bancos' => [id => nombre], 'error' => string|null]
     */
    public static function validarSeleccionDeposito($ids, $cartera) {
        $porId = [];

        foreach (is_array($cartera) ? $cartera : [] as $f) {
            $porId[intval($f['ID_SBA14'])] = $f;
        }

        $filas = [];
        $rechazos = [];
        $bancos = [];

        foreach (self::idsPedidos($ids) as $id) {
            $f = isset($porId[$id]) ? $porId[$id] : null;
            $motivo = null;

            if ($f === null) {
                $motivo = 'ya no está en cartera en Tango';
            } elseif (!empty($f['DEPOSITO'])) {
                $motivo = 'ya está depositado';
            } elseif (!empty($f['EXCLUIDO'])) {
                $motivo = 'está excluido del cashflow: primero hay que volver a incluirlo';
            } elseif (empty($f['ID_BANCO'])) {
                $motivo = 'no tiene banco emisor';
            } elseif (floatval($f['IMPORTE']) <= 0) {
                $motivo = 'no tiene importe';
            }

            if ($motivo !== null) {
                $rechazos[] = ['id' => $id, 'n_cheque' => $f ? $f['N_CHEQUE'] : null,
                               'motivo' => $motivo];
                continue;
            }

            $bancos[intval($f['ID_BANCO'])] = $f['BANCO'];
            $filas[] = $f;
        }

        $error = null;

        if (empty($filas) && empty($rechazos)) {
            $error = 'No llegó ningún cheque para depositar.';
        } elseif (!empty($rechazos)) {
            $error = 'No se depositó ningún cheque: ' . count($rechazos) . ' de los '
                . (count($rechazos) + count($filas)) . ' no se pueden depositar. '
                . implode('; ', array_map(function ($r) {
                    return 'N° ' . ($r['n_cheque'] === null ? $r['id'] : $r['n_cheque'])
                        . ' ' . $r['motivo'];
                }, $rechazos)) . '.';
        } elseif (count($bancos) > 1) {
            $error = 'No se depositó ningún cheque: un depósito es de un solo banco emisor, y la '
                . 'selección tiene ' . count($bancos) . ': ' . implode(', ', $bancos) . '.';
        }

        return ['filas' => $filas, 'rechazos' => $rechazos, 'bancos' => $bancos,
                'error' => $error];
    }

    /**
     * Todo lo que define un deposito, calculado. LANZA si no se puede calcular.
     *
     *   Fecha de deposito   HOY. No se elige: es el dia en que se marca.
     *   Acreditacion        fechaAcreditacion(), con el plazo de Parametros.
     *   Tasa                la suma de las alicuotas vigentes A LA FECHA DE
     *                       ACREDITACION, como Cob. Electronicos: es la tasa
     *                       del dia en que la plata entra.
     *   Neto                Retenciones::importeNeto(), cheque por cheque.
     *
     * UN DEPOSITO NO PUEDE QUEDAR SIN NETO: sin alicuotas vigentes a la fecha
     * de acreditacion se rechaza con el motivo, igual que el alta de un
     * movimiento de Cob. Electronicos. Un neto igual al bruto informaria de
     * mas sin decirlo.
     *
     * EL NETO SE REDONDEA CHEQUE POR CHEQUE y el total es la suma: es lo que se
     * guarda, y el total del dialogo tiene que coincidir con lo que despues
     * suma el tablero.
     *
     * @param array $filas Las de validarSeleccionDeposito(), ya validadas
     * @param int $plazo Dias habiles
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $alicuotas Las de los cheques
     * @param string $hoy 'Y-m-d'
     * @return array
     */
    public static function calcularDeposito($filas, $plazo, $habiles, $alicuotas, $hoy) {
        if (empty($filas)) {
            throw new Exception('No llegó ningún cheque para depositar.');
        }

        $acr = self::fechaAcreditacion($hoy, $plazo, $habiles);

        if ($acr['fecha'] === null) {
            throw new Exception('No se puede calcular la fecha de acreditación: '
                . DiasHabiles::avisoSinHabil($hoy));
        }

        $r = Retenciones::resolverTasa($alicuotas, $acr['fecha'], self::TEXTOS_TASA);

        $cheques = [];
        $bruto = 0;
        $neto = 0;

        foreach ($filas as $f) {
            $b = round(floatval($f['IMPORTE']), 4);
            $n = Retenciones::importeNeto($b, $r['tasa']);

            $cheques[] = [
                'id' => intval($f['ID_SBA14']),
                'n_cheque' => $f['N_CHEQUE'],
                'cliente' => isset($f['CLIENTE']) ? $f['CLIENTE'] : '',
                'bruto' => $b,
                'neto' => $n
            ];

            $bruto += $b;
            $neto += $n;
        }

        return [
            'fecha_deposito' => $hoy,
            'fecha_acreditacion' => $acr['fecha'],
            'dias_plazo' => intval($plazo),
            'tasa' => $r['tasa'],
            'desglose' => Retenciones::desglose($r['detalle']),
            'id_banco' => intval($filas[0]['ID_BANCO']),
            'banco' => $filas[0]['BANCO'],
            'cantidad' => count($cheques),
            'bruto' => round($bruto, 4),
            'retencion' => round($bruto - $neto, 4),
            'neto' => round($neto, 4),
            'cheques' => $cheques,
            'avisos' => $acr['avisos']
        ];
    }

    /**
     * La observacion de un deposito: opcional. Vacia es null, no un texto en
     * blanco que despues se muestre como si dijera algo.
     *
     * @param mixed $texto
     * @return string|null
     */
    public static function validarObservacion($texto) {
        $t = ($texto === null) ? '' : trim((string) $texto);

        return ($t === '') ? null : mb_substr($t, 0, self::OBSERVACION_MAX);
    }

    /**
     * Que depositos de un pedido se pueden deshacer. TODO O NADA, como el
     * deposito: o todos los cheques tienen un deposito vigente, o no se
     * deshace ninguno.
     *
     * @param array $ids
     * @param array $vigentes Mapa ID_SBA14 => deposito vigente
     * @return array ['ids' => [...], 'error' => string|null]
     */
    public static function validarDeshacer($ids, $vigentes) {
        $pedidos = self::idsPedidos($ids);
        $sin = [];

        foreach ($pedidos as $id) {
            if (!isset($vigentes[$id])) {
                $sin[] = $id;
            }
        }

        if (empty($pedidos)) {
            return ['ids' => [], 'error' => 'No llegó ningún cheque para deshacer.'];
        }

        if (!empty($sin)) {
            return ['ids' => [], 'error' => 'No se deshizo ningún depósito: ' . count($sin)
                . ' de los ' . count($pedidos) . ' cheque(s) no tienen un depósito vigente. '
                . 'Puede que la pantalla haya quedado vieja: actualizala y volvé a intentar.'];
        }

        return ['ids' => $pedidos, 'error' => null];
    }

    /**
     * Cuanto hay depositado en lo que se muestra, para las tarjetas y los
     * avisos de la pestana.
     *
     * LOS QUE SE PROYECTAN, con bruto, retencion y neto, y sus totales por
     * vista: es la fila "Echeqs depositados" del tablero. LOS ACREDITADOS SIN
     * REGISTRAR, aparte: no suman a ninguna fila, y son los del aviso.
     *
     * Estatica y pura: recibe las filas ya armadas por EjeVista.
     *
     * @param array $filas
     * @return array
     */
    public static function resumenDepositos($filas) {
        $r = [
            'cheques' => 0, 'bruto' => 0, 'retencion' => 0, 'neto' => 0,
            'total_tramo' => 0, 'total_meses' => 0, 'total_horizonte' => 0,
            'acreditados' => ['cheques' => 0, 'bruto' => 0, 'neto' => 0]
        ];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $estado = isset($f['ESTADO_DEPOSITO']) ? $f['ESTADO_DEPOSITO'] : null;

            if ($estado === self::DEPOSITO_PROYECTA) {
                $r['cheques']++;
                $r['bruto'] += $f['DEPOSITO']['bruto'];
                $r['neto'] += $f['DEPOSITO']['neto'];

                foreach (['total_tramo', 'total_meses', 'total_horizonte'] as $t) {
                    $r[$t] += isset($f[$t]) ? floatval($f[$t]) : 0;
                }
            } elseif ($estado === self::DEPOSITO_ACREDITADO) {
                $r['acreditados']['cheques']++;
                $r['acreditados']['bruto'] += $f['DEPOSITO']['bruto'];
                $r['acreditados']['neto'] += $f['DEPOSITO']['neto'];
            }
        }

        $r['retencion'] = round($r['bruto'] - $r['neto'], 4);

        return $r;
    }

    /**
     * El aviso de los depositos que ya acreditaron y siguen en cartera.
     *
     * Es de ATENCION y pide una accion: en Tango falta registrar el deposito.
     * El importe ya esta en el saldo bancario, asi que no suma a ninguna fila;
     * si nadie lo registra, el cheque queda en cartera para siempre. Lo usan
     * la pestana y el proveedor, con el mismo texto.
     *
     * @param int $cheques
     * @param float $importe Neto
     * @return string|null
     */
    public static function avisoAcreditadosSinRegistrar($cheques, $importe) {
        if (intval($cheques) <= 0) {
            return null;
        }

        return intval($cheques) . ' cheque(s) depositados por $ '
            . number_format($importe, 2, ',', '.') . ' netos ya acreditaron y siguen en cartera '
            . 'en Tango: registrá el depósito. No suman a ninguna fila del tablero: la plata ya '
            . 'está en el saldo bancario.';
    }

    /** Ids enteros, positivos y sin repetir, en el orden en que llegaron */
    private static function idsPedidos($ids) {
        $v = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = intval($id);

            if ($id > 0 && !in_array($id, $v, true)) {
                $v[] = $id;
            }
        }

        return $v;
    }

    /* ====================================================================
       DIAS DE PRE-CHEQUEADO

       Cuantos dias antes de la fecha del cheque se emite la factura. De ahi
       sale la FECHA ESTIMADA DE VENTA, que es la que decide si el cheque se
       muestra y netea -no donde cae, que lo decide la fecha del cheque-:

           FECHA_VENTA_ESTIMADA = FECHA_CHEQUE - dias del cliente

       ES POR CLIENTE. Antes era un unico parametro global,
       'dias_prechequeado'; con un solo numero hay que elegir cual de todos
       los clientes queda bien calculado. Ver README-ventas.md.

       LAS DOS FUNCIONES DE ABAJO SON EL UNICO LUGAR DONDE SE RESUELVE ESTO, y
       las usan tanto la pantalla como el neteo. Si la sub-pestana y el neteo
       aplicaran plazos distintos, uno filtraria cheques que el otro no y no
       habria ninguna pantalla donde se notara.
       ==================================================================== */

    /**
     * Los dias que le corresponden a un cliente.
     *
     * NO HAY RESPALDO GLOBAL: un cliente que no esta en el mapa, o que esta en
     * cero, no desplaza nada y su cheque queda en su propia fecha. Un respaldo
     * heredaria a los clientes sin configurar un desplazamiento que nadie
     * eligio para ellos, y en pantalla seria indistinguible de uno configurado.
     *
     * @param array $mapa Mapa codigo => dias
     * @param string $codigo Codigo de cliente
     * @return int
     */
    public static function diasDeCliente($mapa, $codigo) {
        $cod = trim((string) $codigo);

        if (!is_array($mapa) || !isset($mapa[$cod])) {
            return 0;
        }

        $dias = intval($mapa[$cod]);

        // Un valor negativo correria la venta HACIA ADELANTE del cheque, que es
        // lo contrario de lo que significa pre-chequear. Se trata como cero.
        return ($dias > 0) ? $dias : 0;
    }

    /**
     * La fecha estimada de la venta: la del cheque menos los dias del cliente.
     *
     * @param string $fechaCheque 'Y-m-d'
     * @param int $dias
     * @return string 'Y-m-d'
     */
    public static function fechaVentaEstimada($fechaCheque, $dias) {
        $f = Horizonte::normalizarFecha($fechaCheque);

        if ($f === null) {
            return null;
        }

        $dias = intval($dias);

        if ($dias <= 0) {
            return $f;
        }

        return date('Y-m-d', strtotime($f . ' -' . $dias . ' days'));
    }

    /**
     * Si la venta de un cheque adelantado YA OCURRIO: su fecha estimada de venta
     * quedo antes del corte.
     *
     * ES LA REGLA DE VISIBILIDAD DE LA SUB-PESTANA Y LA REGLA DE DESCARTE DEL
     * NETEO, Y ES UNA SOLA FUNCION A PROPOSITO. La usan
     * cruzarPrechequeado() -para no mostrar esos cheques- y
     * Ventas::repartirNeteo() -para no netearlos-. Si fueran dos
     * implementaciones, la pantalla podria mostrar un cheque que el tablero no
     * netea, o al reves, y no habria ninguna pantalla donde se notara: el
     * usuario tildaria algo que no mueve nada.
     *
     * QUE SIGNIFICA: si la venta teorica quedo en el pasado, esa factura ya se
     * emitio y ese cheque ya entro. No es una venta futura, asi que no esta en
     * la cobranza proyectada y no hay nada que netear. Por eso el cheque sale
     * del cashflow entero -de la grilla, de los KPIs y del neteo- y no porque
     * "no se pueda ubicar".
     *
     * EL CORTE ES UN PARAMETRO Y NO 'hoy' ESCRITO ADENTRO. La sub-pestana no le
     * pasa nada y corta contra hoy; el neteo le pasa el PRIMER DIA DEL EJE.
     * En la practica son el mismo dia -Horizonte arma el tramo diario
     * empezando en hoy, ver Horizonte::construirDias()- pero el neteo tiene que
     * cortar contra el eje que efectivamente recibio, no contra el reloj: con
     * un eje inyectado distinto -el Analisis de Ventas arma uno solo mensual-
     * el reloj daria otra respuesta.
     *
     * SIN FECHA ESTIMADA TAMBIEN DA true. Un cheque sin fecha no se puede ubicar
     * en ninguna columna ni de la grilla ni del eje del tablero; tratarlo como
     * visible lo mostraria en una fila sin ninguna celda con importe.
     *
     * @param string|null $fechaVentaEst Fecha estimada de venta, 'Y-m-d'
     * @param string|null $corte Fecha de corte 'Y-m-d'. Por defecto, hoy
     * @return bool
     */
    public static function ventaYaCobrada($fechaVentaEst, $corte = null) {
        if ($fechaVentaEst === null || $fechaVentaEst === '') {
            return true;
        }

        $corte = ($corte === null || $corte === '') ? date('Y-m-d') : $corte;

        return $fechaVentaEst < $corte;
    }

    /**
     * Cruza los cheques con el maestro de clientes pre-chequeados y con las
     * excepciones por cheque. ES LA REGLA COMPLETA DE LA SUB-PESTANA, escrita una
     * sola vez.
     *
     * TRES DECISIONES, TODAS DELIBERADAS
     *
     * 1. EL MAESTRO ACOTA. Un cheque cuyo cliente no esta en el maestro, o esta
     *    pero inhabilitado, NO APARECE, aunque tenga una excepcion cargada. Con
     *    el maestro vacio el resultado es vacio, nunca el universo completo: una
     *    pantalla que por defecto tilda los cheques de todos los clientes
     *    netearia contra ventas que nadie prepago.
     *
     * 2. LA MARCA POR DEFECTO ES 1. Estar en el maestro es haber optado por la
     *    modalidad; el tilde viene puesto y lo que el usuario hace normalmente es
     *    destildar las excepciones. ORIGEN_MARCA distingue el tilde heredado del
     *    cliente del que alguien toco a mano, porque un tilde que el usuario no
     *    puso y no sabe de donde salio es peor que no tenerlo.
     *
     * 3. LOS ESTADOS MUERTOS NO ENTRAN. Un cheque anulado o rechazado no netea
     *    nada, y deja de hacerlo sin que su marca se toque.
     *
     * 4. LO ANTERIOR A HOY NO ENTRA. Si la fecha estimada de venta quedo en el
     *    pasado, esa venta ya se facturo y ya se cobro: esta fuera del cashflow
     *    y no hay nada que tildar. El corte lo decide ventaYaCobrada(), LA MISMA
     *    funcion que usa Ventas::repartirNeteo() para no netearlos. Que sea una
     *    sola funcion es el punto: si fueran dos, la pantalla podria mostrar un
     *    cheque que el tablero no netea y el usuario tildaria algo que no mueve
     *    nada.
     *
     *    VA ACA Y NO EN EL JS, y tampoco en la consulta. En el JS la tabla
     *    quedaria filtrada pero los KPIs del encabezado -que salen de
     *    resumenPrechequeado() sobre estas mismas filas- seguirian contando los
     *    cheques escondidos, y la pantalla se contradeciria a si misma. En la
     *    consulta quedaria lejos del resto de la regla y sin pruebas.
     *
     * La consulta de la que salen los cheques ya aplica los mismos filtros: eso
     * es una OPTIMIZACION -no traer del motor lo que se va a descartar-, no una
     * segunda copia de la regla. La regla que decide es esta.
     *
     * @param array $cheques Filas crudas de dbo.SBA14 ya normalizadas
     * @param array $clientes Filas del maestro, con CLIENTE y ACTIVO
     * @param array $excepciones Filas de excepciones, con ID_SBA14 y MARCADO
     * @param string|null $hoy Corte 'Y-m-d' para la regla 4. Por defecto, hoy.
     *                         Existe para poder probar la regla sin depender
     *                         del dia en que corren las pruebas
     * @return array Filas listas para la pantalla
     */
    public static function cruzarPrechequeado($cheques, $clientes, $excepciones, $hoy = null) {
        $activos = [];
        $dias = [];

        foreach (is_array($clientes) ? $clientes : [] as $c) {
            if (intval(isset($c['ACTIVO']) ? $c['ACTIVO'] : 1) === 1) {
                $cod = trim((string) $c['CLIENTE']);

                $activos[$cod] = $c;
                $dias[$cod] = isset($c['DIAS_PRECHEQUEADO']) ? $c['DIAS_PRECHEQUEADO'] : 0;
            }
        }

        // Sin maestro no hay pantalla. Se corta aca y no en el bucle para que
        // quede escrito que el caso vacio es una decision y no un efecto.
        if (empty($activos)) {
            return [];
        }

        $porCheque = [];

        foreach (is_array($excepciones) ? $excepciones : [] as $e) {
            $porCheque[intval($e['ID_SBA14'])] = $e;
        }

        $filas = [];

        foreach (is_array($cheques) ? $cheques : [] as $cheque) {
            $codigo = trim((string) (isset($cheque['COD_CLIENTE']) ? $cheque['COD_CLIENTE'] : ''));

            if (!isset($activos[$codigo])) {
                continue;
            }

            if (in_array(trim((string) $cheque['ESTADO']), self::ESTADOS_MUERTOS, true)) {
                continue;
            }

            $id = intval($cheque['ID_SBA14']);
            $excepcion = isset($porCheque[$id]) ? $porCheque[$id] : null;

            $cheque['MARCADO'] = ($excepcion === null) ? 1 : intval($excepcion['MARCADO']);
            $cheque['ORIGEN_MARCA'] = ($excepcion === null)
                ? self::ORIGEN_CLIENTE
                : self::ORIGEN_CHEQUE;

            // Quien y cuando toco la marca. Va vacio en las heredadas del
            // cliente: ahi no hubo nadie.
            $cheque['MARCA_FECHA'] = ($excepcion !== null && isset($excepcion['FECHA_UPDATE']))
                ? $excepcion['FECHA_UPDATE'] : null;
            $cheque['MARCA_USUARIO'] = ($excepcion !== null && isset($excepcion['USUARIO']))
                ? $excepcion['USUARIO'] : null;

            $cheque['CANAL'] = self::canalDeCliente($codigo);

            // Los dias efectivos y la fecha estimada de venta viajan en la fila
            // para que la pantalla no los recalcule. Los dias van a la vista
            // porque son de donde sale la fecha: sin ellos, un cliente en cero
            // se ve igual que uno configurado y nadie entiende por que su
            // cheque no se desplazo.
            $cheque['DIAS_PRECHEQUEADO'] = self::diasDeCliente($dias, $codigo);
            $cheque['FECHA_VENTA_EST'] = self::fechaVentaEstimada(
                $cheque['FECHA_CHEQUE'], $cheque['DIAS_PRECHEQUEADO']);

            // Regla 4: la venta ya ocurrio, el cheque esta fuera del cashflow.
            // Se descarta despues de calcular la fecha estimada porque es esa
            // fecha -y no la del cheque- la que decide.
            if (self::ventaYaCobrada($cheque['FECHA_VENTA_EST'], $hoy)) {
                continue;
            }

            $filas[] = $cheque;
        }

        // Por cliente y fecha: el flujo de la pantalla es filtrar un cliente y
        // destildar en bloque.
        usort($filas, function ($a, $b) {
            $porCliente = strcmp((string) $a['CLIENTE'], (string) $b['CLIENTE']);

            return ($porCliente !== 0)
                ? $porCliente
                : strcmp((string) $a['FECHA_CHEQUE'], (string) $b['FECHA_CHEQUE']);
        });

        return $filas;
    }

    /**
     * Resumen del pie de la sub-pestana: cuanto hay marcado y como se reparte por
     * estado.
     *
     * El corte por ESTADO no es decoracion. El neteo va por TILDE y no por
     * estado -es criterio del usuario, ver README-ventas.md-, pero los dos casos
     * no se comportan igual en el tablero: un cheque en 'C' cierra solo, porque
     * entra por la fila de cartera y sale por el neteo; uno ya aplicado resta sin
     * que ninguna fila lo sume. Este corte es lo que permite ver de cuanto se
     * esta hablando antes de tildar.
     *
     * @param array $filas Filas devueltas por cruzarPrechequeado()
     * @return array
     */
    public static function resumenPrechequeado($filas) {
        $r = [
            'cheques' => 0,
            'marcados' => 0,
            'importe_marcado' => 0,
            'importe_total' => 0,
            'por_estado' => [],
            'clientes' => []
        ];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $importe = floatval($f['IMPORTE']);
            $estado = trim((string) $f['ESTADO']);
            $marcado = !empty($f['MARCADO']);

            $r['cheques']++;
            $r['importe_total'] += $importe;

            if (!isset($r['por_estado'][$estado])) {
                $r['por_estado'][$estado] = ['cheques' => 0, 'importe' => 0];
            }

            if ($marcado) {
                $r['marcados']++;
                $r['importe_marcado'] += $importe;
                $r['por_estado'][$estado]['cheques']++;
                $r['por_estado'][$estado]['importe'] += $importe;
            }

            $codigo = trim((string) $f['COD_CLIENTE']);

            if (!isset($r['clientes'][$codigo])) {
                $r['clientes'][$codigo] = [
                    'codigo' => $codigo,
                    'nombre' => trim((string) $f['CLIENTE']),
                    'cheques' => 0
                ];
            }

            $r['clientes'][$codigo]['cheques']++;
        }

        // El desplegable de clientes se arma con los que ESTAN en el listado, no
        // con todo el maestro: un filtro que ofrece opciones que no devuelven
        // nada se lee como una pantalla rota.
        $r['clientes'] = array_values($r['clientes']);

        return $r;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si ya se corrieron las tablas del maestro de pre-chequeado.
     *
     * La sub-pestana de cartera no las necesita: sale entera de dbo.SBA14. Sin
     * ellas, lo unico que no funciona es la segunda sub-pestana, y avisa.
     *
     * @return bool
     */
    public function tablasCreadas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $cid = $this->conectar('central');

        $sql = "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE', 'U') AS C,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ', 'U')         AS E,
                       OBJECT_ID('dbo.RO_V_CASHFLOW_VENTAS_PRECHEQ', 'V')        AS V";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de Echeqs'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ($row && $row['C'] !== null && $row['E'] !== null && $row['V'] !== null);

        return $this->tablas;
    }

    /**
     * Si ya se corrio sql/cashflow_echeqs_excluir.sql.
     *
     * SIN ELLA LA PESTANA NO SE ROMPE: el listado de cartera se lee igual,
     * ningun cheque queda excluido y lo que la pantalla apaga son los botones
     * de excluir, diciendo por que. Es la misma regla que
     * Proveedores::tieneColumnaPago() y el mismo criterio de todo el modulo: si
     * el DDL no se corrio, la pantalla avisa y sigue andando.
     *
     * @return bool
     */
    public function excluirCreada() {
        if ($this->tablaExcluido !== null) {
            return $this->tablaExcluido;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_EXCLUIDO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de exclusiones'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaExcluido = ($row && $row['T'] !== null);

        return $this->tablaExcluido;
    }

    /**
     * Si ya se corrio sql/cashflow_echeqs_deposito.sql: las dos tablas, la de
     * depositos y la de alicuotas.
     *
     * SIN ELLAS LA PESTANA NO SE ROMPE: la cartera se lee como hoy, ningun
     * cheque esta depositado, el boton Depositar queda apagado nombrando el
     * script y Parametros -> Cheques avisa. Mismo criterio que excluirCreada().
     *
     * @return bool
     */
    public function depositoCreado() {
        if ($this->tablasDeposito !== null) {
            return $this->tablasDeposito;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_DEPOSITO . "', 'U') AS D,
                    OBJECT_ID('dbo." . self::TABLA_ALICUOTA . "', 'U') AS A");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas del depósito'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablasDeposito = ($row && $row['D'] !== null && $row['A'] !== null);

        return $this->tablasDeposito;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en la pestana.
     *
     * @return array Lista de mensajes
     */
    public function getAvisos() {
        $avisos = [];

        if (!$this->tablasCreadas()) {
            $avisos[] = 'Todavía no existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql contra la base central. Mientras tanto, '
                . 'Cheques en Cartera funciona igual: sale entera de Tango.';

            return $avisos;
        }

        $clientes = $this->getClientesPrechequeado(true);

        if (empty($clientes)) {
            $avisos[] = 'No hay clientes configurados para venta cobrada anticipada. '
                . 'Cargalos en Parámetros → Cheques: sin clientes, esa sub-pestaña se '
                . 'muestra vacía a propósito.';

            return $avisos;
        }

        $sinCheques = [];
        $fueraUniverso = [];

        foreach ($clientes as $c) {
            if (intval($c['CHEQUES_VIVOS']) === 0) {
                $sinCheques[] = $c['CLIENTE'];
            }

            if (self::canalDeCliente($c['CLIENTE']) === null) {
                $fueraUniverso[] = $c['CLIENTE'];
            }
        }

        if (!empty($fueraUniverso)) {
            $avisos[] = 'Estos códigos están en el maestro pero quedan fuera del módulo, que sólo '
                . 'mira franquicias y locales: ' . implode(', ', $fueraUniverso)
                . '. Sus cheques nunca van a aparecer.';
        }

        if (!empty($sinCheques)) {
            $avisos[] = 'Estos clientes están cargados pero hoy no tienen ningún cheque vivo: '
                . implode(', ', $sinCheques) . '. Puede ser normal, o puede ser un código mal '
                . 'tipeado.';
        }

        return $avisos;
    }

    /* ====================================================================
       SUB-PESTANA 1 - CHEQUES EN CARTERA

       Alimenta la fila "Echeqs en cartera" del tablero.
       ==================================================================== */

    /**
     * La consulta de la cartera, fila por fila. Estatica y pura: arma el texto
     * y no lo corre, para poder verificar la union con el deposito sin base.
     *
     * >= CAST(GETDATE() AS DATE) y no >= GETDATE(): comparar contra fecha Y
     * HORA deja fuera los cheques del dia a partir de las 00:00:01. Es el
     * criterio del resto del modulo, ver Ingresos::getCobranzasFRTotales().
     *
     * No hace falta ESTADO NOT IN ('X','R'): es redundante con ESTADO = 'C'.
     *
     * LOS DOS LEFT JOIN SON LA EXCEPCION A "LOS CRUCES SE HACEN EN PHP", y la
     * misma: la exclusion y el deposito son marcas nuestras SOBRE dbo.SBA14, y
     * el deposito ademas cambia QUE FILAS SE LEEN -un cheque depositado se lee
     * aunque su fecha ya haya pasado-. Cruzarlo en PHP obligaria a traer toda
     * la cartera historica para quedarse con los depositados.
     *
     * EL DEPOSITO SE UNE CON VIGENTE = 1 EN LA CONDICION DE LA UNION, NO EN EL
     * WHERE. Con un deposito deshecho, d.ID queda en NULL y el cheque vuelve a
     * la regla de los no depositados: si su fecha ya paso, no aparece. En el
     * WHERE, la fila historica seguiria trayendo el cheque. Ver
     * visibleEnCartera(), que es la regla que decide.
     *
     * Cada union se arma solo si su tabla existe: sin los scripts, la consulta
     * es la de antes. Las columnas viajan igual -en null- para que la pantalla
     * y el proveedor no pregunten si el script se corrio antes de leer una
     * fila.
     *
     * @param bool $excl Si existe la tabla de exclusiones
     * @param bool $dep Si existen las tablas del deposito
     * @return string
     */
    public static function sqlCartera($excl, $dep) {
        return "SELECT s.ID_SBA14,
                       CAST(s.N_CHEQUE AS BIGINT)  AS N_CHEQUE,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_CHEQUE,
                       b.ID_BANCO                  AS ID_BANCO,
                       b.DESC_BANCO                AS BANCO,
                       CAST(s.IMPORTE_CH AS FLOAT) AS IMPORTE,
                       s.RAZON_EMIS                AS CLIENTE,
                       s.CLIENTE                   AS COD_CLIENTE,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_PAGO,
                       " . ($excl ? 'x.MOTIVO' : 'CAST(NULL AS VARCHAR(200))') . " AS MOTIVO_EXCLUSION,
                       " . ($excl ? 'x.USUARIO_ALTA' : 'CAST(NULL AS VARCHAR(50))') . " AS EXCLUSION_USUARIO,
                       " . ($excl ? 'x.FECHA_ALTA' : 'CAST(NULL AS DATETIME)') . " AS EXCLUSION_FECHA,
                       " . ($dep ? 'd.ID' : 'CAST(NULL AS INT)') . " AS DEP_ID,
                       " . ($dep ? 'd.FECHA_DEPOSITO' : 'CAST(NULL AS DATE)') . " AS DEP_FECHA_DEPOSITO,
                       " . ($dep ? 'd.FECHA_ACREDITACION' : 'CAST(NULL AS DATE)') . " AS DEP_FECHA_ACREDITACION,
                       " . ($dep ? 'd.DIAS_PLAZO' : 'CAST(NULL AS INT)') . " AS DEP_DIAS_PLAZO,
                       " . ($dep ? 'CAST(d.IMPORTE_BRUTO AS FLOAT)' : 'CAST(NULL AS FLOAT)') . " AS DEP_BRUTO,
                       " . ($dep ? 'CAST(d.TASA_APLICADA AS FLOAT)' : 'CAST(NULL AS FLOAT)') . " AS DEP_TASA,
                       " . ($dep ? 'd.DETALLE_TASA' : 'CAST(NULL AS VARCHAR(200))') . " AS DEP_DESGLOSE,
                       " . ($dep ? 'CAST(d.IMPORTE_NETO AS FLOAT)' : 'CAST(NULL AS FLOAT)') . " AS DEP_NETO,
                       " . ($dep ? 'd.OBSERVACION' : 'CAST(NULL AS VARCHAR(200))') . " AS DEP_OBSERVACION,
                       " . ($dep ? 'd.DESC_BANCO' : 'CAST(NULL AS VARCHAR(60))') . " AS DEP_BANCO,
                       " . ($dep ? 'd.USUARIO_ALTA' : 'CAST(NULL AS VARCHAR(50))') . " AS DEP_USUARIO,
                       " . ($dep ? 'd.FECHA_ALTA' : 'CAST(NULL AS DATETIME)') . " AS DEP_FECHA_ALTA
                FROM dbo.SBA14 AS s
                LEFT JOIN dbo.BANCO AS b ON s.ID_BANCO = b.ID_BANCO
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                " . ($dep
                    ? 'LEFT JOIN dbo.' . self::TABLA_DEPOSITO . ' AS d
                              ON d.ID_SBA14 = s.ID_SBA14 AND d.VIGENTE = 1'
                    : '') . "
                WHERE " . self::sqlUniversoCartera($dep) . "
                ORDER BY s.FECHA_CHEQ";
    }

    /**
     * El WHERE del universo de cartera, el mismo para la lectura fila por fila
     * y la agregada: si dijeran cosas distintas, la pestana y el tablero
     * mirarian dos carteras.
     *
     * @param bool $dep Si existen las tablas del deposito
     * @return string
     */
    private static function sqlUniversoCartera($dep) {
        return ($dep
                ? '(s.FECHA_CHEQ >= CAST(GETDATE() AS DATE) OR d.ID IS NOT NULL)'
                : 's.FECHA_CHEQ >= CAST(GETDATE() AS DATE)') . "
                  AND s.ESTADO = ?
                  AND s.CLIENTE LIKE '[FL]%'";
    }

    /**
     * Cheques de terceros en cartera, uno por fila, con su exclusion y su
     * deposito vigente.
     *
     * FECHA_PAGO es la fecha del cheque, y es por la que se ubica un cheque NO
     * depositado. Uno depositado se ubica por su acreditacion y por su neto:
     * lo resuelve ubicarEnEje(), que deja FECHA_EJE e IMPORTE_EJE para el eje.
     *
     * LOS EXCLUIDOS Y LOS DEPOSITADOS VIENEN EN LA LISTA, MARCADOS. No se
     * filtran aca: quien los esconde es la pantalla, con interruptores que se
     * pueden apagar.
     *
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array Filas normalizadas
     */
    public function getEcheqsCartera($hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid, self::sqlCartera($this->excluirCreada(), $this->depositoCreado()),
            [self::ESTADO_CARTERA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los echeqs en cartera'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fila = $this->filaCheque($row);

            // La regla que decide; la consulta ya aplico la misma como
            // optimizacion.
            if (self::visibleEnCartera($fila, $hoy)) {
                $v[] = self::ubicarEnEje($fila, $hoy);
            }
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Los mismos cheques agregados, para el tablero.
     *
     * POR QUE NO REUSA getEcheqsCartera()
     * El Cashflow no necesita banco, cliente ni numero de cheque: solo fechas e
     * importes. Un GROUP BY en el motor evita recorrer N filas en PHP para
     * descartar la mayor parte de cada una. Es el mismo criterio de
     * Ingresos::getCobranzasFRTotales(), y tests/test_echeqs.php verifica que los
     * dos metodos dan el mismo total para que no se puedan desincronizar en
     * silencio.
     *
     * ABRE POR EXCLUIDO Y POR DEPOSITO, y por eso la clave de agrupacion son
     * tres campos. El proveedor reparte el mismo universo en sus series
     * -cobrable, excluido, depositado y todo-, y resolver eso con consultas
     * distintas seria varios WHERE que tienen que decir lo mismo para siempre.
     * Con el corte adentro del GROUP BY, el universo se lee UNA vez y las
     * series salen de la misma lectura, que es lo que hace que cierren por
     * construccion.
     *
     * Un deposito trae su FECHA_ACREDITACION, su NETO y su BRUTO tal como se
     * guardaron: el proveedor decide con estadoDeposito() si se proyecta.
     *
     * @return array Filas ['FECHA_PAGO', 'EXCLUIDO' => bool, 'IMPORTE',
     *         'FECHA_ACREDITACION' => 'Y-m-d'|null, 'NETO', 'BRUTO_DEPOSITO', 'CHEQUES']
     */
    public function getEcheqsCarteraTotales() {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            self::sqlCarteraTotales($this->excluirCreada(), $this->depositoCreado()),
            [self::ESTADO_CARTERA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el total de echeqs en cartera'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $acredita = Horizonte::normalizarFecha($row['FECHA_ACREDITACION']);

            $v[] = [
                'FECHA_PAGO' => Horizonte::normalizarFecha($row['FECHA_PAGO']),
                'EXCLUIDO' => (intval($row['EXCLUIDO']) === 1),
                'IMPORTE' => floatval($row['IMPORTE']),
                'FECHA_ACREDITACION' => $acredita,
                'NETO' => ($acredita === null) ? null : floatval($row['NETO']),
                'BRUTO_DEPOSITO' => ($acredita === null) ? null : floatval($row['BRUTO_DEPOSITO']),
                'CHEQUES' => intval($row['CHEQUES'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * La consulta agregada de la cartera. Estatica y pura, como sqlCartera().
     *
     * SIN LOS SCRIPTS, LAS COLUMNAS VAN EN EL SELECT PERO NO EN EL GROUP BY.
     * SQL Server rechaza agrupar por una constante -"Each GROUP BY expression
     * must contain at least one column that is not an outer reference"-, asi
     * que meterlas en los dos lados sin preguntar haria fallar la pestana
     * entera justo en la instalacion que todavia no corrio el script, que es
     * el caso que este modulo se compromete a no romper.
     *
     * @param bool $excl
     * @param bool $dep
     * @return string
     */
    public static function sqlCarteraTotales($excl, $dep) {
        $flag = $excl ? 'CASE WHEN x.ID IS NULL THEN 0 ELSE 1 END' : '0';
        $grupo = 'CAST(s.FECHA_CHEQ AS DATE)' . ($excl ? ', ' . $flag : '')
            . ($dep ? ', d.FECHA_ACREDITACION' : '');

        return "SELECT CAST(s.FECHA_CHEQ AS DATE)       AS FECHA_PAGO,
                       " . $flag . "                    AS EXCLUIDO,
                       SUM(CAST(s.IMPORTE_CH AS FLOAT)) AS IMPORTE,
                       " . ($dep ? 'd.FECHA_ACREDITACION' : 'CAST(NULL AS DATE)') . " AS FECHA_ACREDITACION,
                       " . ($dep ? 'SUM(CAST(d.IMPORTE_NETO AS FLOAT))' : 'CAST(NULL AS FLOAT)') . " AS NETO,
                       " . ($dep ? 'SUM(CAST(d.IMPORTE_BRUTO AS FLOAT))' : 'CAST(NULL AS FLOAT)') . " AS BRUTO_DEPOSITO,
                       COUNT(*)                         AS CHEQUES
                FROM dbo.SBA14 AS s
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                " . ($dep
                    ? 'LEFT JOIN dbo.' . self::TABLA_DEPOSITO . ' AS d
                              ON d.ID_SBA14 = s.ID_SBA14 AND d.VIGENTE = 1'
                    : '') . "
                WHERE " . self::sqlUniversoCartera($dep) . "
                GROUP BY " . $grupo . "
                ORDER BY CAST(s.FECHA_CHEQ AS DATE)";
    }

    /**
     * Excluye cheques de cartera del cashflow, o los vuelve a incluir.
     *
     * QUE SIGNIFICA. Que ese importe NO se va a poder cobrar: el cliente avisó
     * que no lo cubre, el cheque quedó judicializado, está en gestión de
     * cambio. El cheque sigue en cartera en Tango -no somos nosotros los que lo
     * damos de baja- y lo que cambia es de qué serie del tablero sale.
     *
     * EL IMPORTE NO DESAPARECE: sale de A_COBRAR y va a A_COBRAR_EXCLUIDOS, que
     * es la otra mitad del mismo corte. Las dos cierran contra A_COBRAR_TODO y
     * el proveedor avisa cuánto es y con qué motivos. Ver
     * EcheqsProvider::SERIE_EXCLUIDOS y el encabezado de
     * sql/cashflow_echeqs_excluir.sql.
     *
     * NO TOCA EL PRE-CHEQUEADO. Son dos preguntas distintas y tienen dos tablas
     * distintas: ver la nota del encabezado de esta clase.
     *
     * ES UNA SOLA TRANSACCION, por el mismo motivo que marcarCheques(): excluir
     * los doce cheques de un cliente con doce llamadas deja la puerta abierta a
     * que la quinta falle y el disponible quede a mitad de camino sin que nadie
     * se entere. O entran todos o no entra ninguno.
     *
     * EL MOTIVO ES UNO PARA TODOS, y eso no es una simplificación de la
     * pantalla: excluir los doce cheques de un cliente que entró en concurso es
     * UNA decisión, y doce motivos distintos para una decisión son doce
     * oportunidades de que digan cosas distintas. Es el criterio de
     * Proveedores::saveExclusionMasiva().
     *
     * SE VALIDA CONTRA LA CARTERA DE HOY, a diferencia de la exclusión de
     * facturas de Proveedores Locales, que a propósito no lo hace. Allá la fila
     * de override vive por comprobante y puede existir para uno que hoy no está
     * pendiente, así que rechazarla perdería la decisión el día que el
     * comprobante vuelva. Acá la exclusión sólo significa algo mientras el
     * cheque esté en cartera: uno depositado o rechazado ya salió de la serie
     * por su estado, y guardar una exclusión para él sería una decisión que no
     * se ve en ninguna pantalla. Es la misma razón por la que marcarCheques()
     * resuelve su universo en el servidor.
     *
     * VOLVER A EXCLUIR ALGO YA EXCLUIDO NO ES UN ERROR: da de baja la exclusión
     * anterior e inserta una nueva. Es como se corrige un motivo mal escrito, y
     * el historial guarda los dos.
     *
     * @param array $ids Ids de dbo.SBA14
     * @param bool $excluir true saca del cashflow, false devuelve
     * @param string|null $motivo Obligatorio si $excluir es true
     * @param string $usuario
     * @return array ['excluidos' => bool, 'motivo', 'tocados' => int,
     *         'rechazados' => [...], 'filas' => [...]]
     */
    public function excluirCheques($ids, $excluir, $motivo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->excluirCreada()) {
            throw new Exception('Todavía no se pueden excluir cheques. '
                . 'Corré sql/cashflow_echeqs_excluir.sql contra la base central.');
        }

        $pedidos = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $pedidos[$id] = true;
            }
        }

        if (empty($pedidos)) {
            throw new Exception('No llegó ningún cheque para excluir.');
        }

        // El universo permitido se vuelve a resolver en el servidor: lo que
        // manda el navegador es una lista de ids, no una autorizacion.
        $permitidos = [];
        $depositados = [];

        foreach ($this->getEcheqsCartera() as $fila) {
            $permitidos[intval($fila['ID_SBA14'])] = true;

            if (!empty($fila['DEPOSITO'])) {
                $depositados[intval($fila['ID_SBA14'])] = $fila['N_CHEQUE'];
            }
        }

        /* UN CHEQUE DEPOSITADO NO SE EXCLUYE. Ya esta en el banco: si no se va
           a cobrar, lo que corresponde es deshacer el deposito y despues
           excluirlo. Las dos marcas sobre el mismo cheque lo pondrian en dos
           series del tablero a la vez. Es todo o nada, como el deposito: con
           uno depositado en el pedido no se excluye ninguno. */
        $enPedido = array_intersect_key($depositados, $pedidos);

        if (!empty($excluir) && !empty($enPedido)) {
            throw new Exception('No se excluyó ningún cheque: ' . count($enPedido)
                . ' está(n) depositado(s) (N° ' . implode(', ', $enPedido) . '). Para excluirlo, '
                . 'primero deshacé el depósito.');
        }

        /* array_keys() y NO array_values(): $pedidos esta indexado por id -es
           lo que deduplica la lista que llega- y sus valores son todos true.
           Ver la misma nota en marcarCheques(). */
        $validos = array_keys(array_intersect_key($pedidos, $permitidos));
        $rechazados = array_keys(array_diff_key($pedidos, $permitidos));

        if (empty($validos)) {
            throw new Exception('Ninguno de los ' . count($pedidos) . ' cheque(s) recibidos '
                . 'está hoy en cartera. Puede que la pantalla haya quedado vieja: '
                . 'actualizala y volvé a intentar.');
        }

        $sacar = !empty($excluir);

        // El motivo se valida ANTES de abrir la transaccion: un lote rechazado
        // no puede descubrirse con la mitad de las bajas ya escritas.
        $texto = $sacar ? self::validarMotivoExclusion($motivo, count($validos)) : null;

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción de exclusión'));
        }

        try {
            /* El constructor de tabla de un IN y el de un INSERT admiten hasta
               1000 filas, asi que un lote grande se parte en tandas. Van todas
               dentro de la MISMA transaccion: la garantia es de la operacion
               completa. Mismo criterio que mergeMarcas(). */
            foreach (array_chunk($validos, 500) as $tanda) {
                /* SIEMPRE SE DA DE BAJA PRIMERO, se este excluyendo o
                   incluyendo. Es lo que hace que volver a excluir algo ya
                   excluido corrija el motivo en vez de chocar contra el indice
                   unico de exclusiones vigentes, y lo que deja el historial
                   completo en los dos casos. */
                $this->bajaExclusiones($cid, $tanda, $usuario);

                if ($sacar) {
                    $this->insertarExclusiones($cid, $tanda, $texto, $usuario);
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la exclusión'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return [
            'excluidos' => $sacar,
            'motivo' => $texto,
            'tocados' => count($validos),
            'rechazados' => $rechazados,
            // El estado efectivo de lo que quedo guardado, releido de la base:
            // asi el front no tiene que adivinar como quedaron las filas.
            'filas' => $this->getExclusiones($validos)
        ];
    }

    /**
     * Da de baja las exclusiones vigentes de una tanda de cheques.
     *
     * NO BORRA: marca VIGENTE = 0 y sella FECHA_BAJA. Con FECHA_ALTA sola no se
     * puede distinguir una exclusión que se deshizo a los cinco minutos de una
     * que estuvo vigente tres semanas, y la segunda es la que explica por qué el
     * disponible proyectado de la semana pasada era otro.
     *
     * @param resource $cid Conexion con la transaccion ya abierta
     * @param array $ids
     * @param string $usuario
     */
    private function bajaExclusiones($cid, $ids, $usuario) {
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_EXCLUIDO . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE VIGENTE = 1 AND ID_SBA14 IN (" . $marcas . ")",
            array_merge([$usuario, $usuario], array_map('intval', $ids)));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la exclusión anterior'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Inserta una exclusion vigente por cheque, todas con el mismo motivo.
     *
     * @param resource $cid Conexion con la transaccion ya abierta
     * @param array $ids
     * @param string $motivo Ya validado
     * @param string $usuario
     */
    private function insertarExclusiones($cid, $ids, $motivo, $usuario) {
        // 4 parametros por fila: una tanda de 500 son 2000, debajo del tope de 2100.
        $filas = implode(', ', array_fill(0, count($ids), '(?, ?, 1, ?, ?)'));
        $params = [];

        foreach ($ids as $id) {
            $params[] = intval($id);
            $params[] = $motivo;
            $params[] = $usuario;
            $params[] = $usuario;
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA_EXCLUIDO . "
                 (ID_SBA14, MOTIVO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
             VALUES " . $filas,
            $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la exclusión'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Las exclusiones VIGENTES, para releer el estado efectivo.
     *
     * Sin el script corrido devuelve vacio y no lanza: quien lo llama esta
     * preguntando que hay excluido, y la respuesta honesta en ese caso es
     * "nada".
     *
     * @param array|null $ids Para releer solo un subconjunto, o null para todas
     * @return array Filas ['ID_SBA14', 'MOTIVO', 'USUARIO', 'FECHA_ALTA']
     */
    public function getExclusiones($ids = null) {
        if (!$this->excluirCreada()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT ID_SBA14, MOTIVO, USUARIO_ALTA AS USUARIO, FECHA_ALTA
                FROM dbo." . self::TABLA_EXCLUIDO . "
                WHERE VIGENTE = 1";
        $params = [];

        if (is_array($ids) && !empty($ids)) {
            $sql .= " AND ID_SBA14 IN (" . implode(', ', array_fill(0, count($ids), '?')) . ")";
            $params = array_map('intval', $ids);
        }

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las exclusiones'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID_SBA14' => intval($row['ID_SBA14']),
                'MOTIVO' => trim((string) $row['MOTIVO']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Las exclusiones vigentes QUE HOY ESTAN EN CARTERA, resumidas.
     *
     * POR QUE NO ALCANZA CON getExclusiones(). Una exclusion no se limpia
     * cuando el cheque sale de cartera -no hay bajas fisicas, y el estado lo
     * maneja Tango, no nosotros-, asi que esa fila queda vigente pero deja de
     * significar algo: el cheque ya no esta en ninguna serie. Contarla en el
     * aviso del tablero diria "8 cheques excluidos" donde el importe que falta
     * es el de 5.
     *
     * Va en una consulta y no cruzando en PHP porque el universo de cartera son
     * cientos de filas y las exclusiones son un punado: traer las primeras para
     * filtrar las segundas es al reves.
     *
     * @return array ['cheques' => int, 'importe' => float, 'motivos' => [...]]
     */
    public function getExclusionesEnCartera() {
        $vacio = ['cheques' => 0, 'importe' => 0.0, 'motivos' => []];

        if (!$this->excluirCreada()) {
            return $vacio;
        }

        $cid = $this->conectar('central');

        $sql = "SELECT x.MOTIVO,
                       COUNT(*)                         AS CHEQUES,
                       SUM(CAST(s.IMPORTE_CH AS FLOAT)) AS IMPORTE
                FROM dbo." . self::TABLA_EXCLUIDO . " AS x
                INNER JOIN dbo.SBA14 AS s ON s.ID_SBA14 = x.ID_SBA14
                WHERE x.VIGENTE = 1
                  AND s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
                  AND s.ESTADO = ?
                  AND s.CLIENTE LIKE '[FL]%'
                GROUP BY x.MOTIVO
                ORDER BY SUM(CAST(s.IMPORTE_CH AS FLOAT)) DESC";

        $stmt = sqlsrv_query($cid, $sql, [self::ESTADO_CARTERA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al resumir las exclusiones'));
        }

        $r = $vacio;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $r['cheques'] += intval($row['CHEQUES']);
            $r['importe'] += floatval($row['IMPORTE']);

            $m = trim((string) $row['MOTIVO']);

            // Ordenados por importe, que es como el aviso tiene que nombrarlos:
            // los tres primeros son los tres que mas plata explican.
            if ($m !== '') {
                $r['motivos'][] = $m;
            }
        }

        sqlsrv_free_stmt($stmt);

        return $r;
    }

    /**
     * El historial completo de un cheque: las exclusiones vigentes y las dadas
     * de baja, de la mas nueva a la mas vieja.
     *
     * ES LO QUE JUSTIFICA QUE NO HAYA BAJAS FISICAS. Sin esto, la tabla guarda
     * filas que nadie puede mirar y el historial es un costo sin beneficio.
     *
     * @param int $idSba14
     * @return array
     */
    public function getHistorialExclusion($idSba14) {
        if (!$this->excluirCreada()) {
            return [];
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT ID, MOTIVO, VIGENTE, USUARIO_ALTA AS USUARIO, FECHA_ALTA, USUARIO_BAJA,
                    FECHA_BAJA
             FROM dbo." . self::TABLA_EXCLUIDO . "
             WHERE ID_SBA14 = ?
             ORDER BY ID DESC",
            [intval($idSba14)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de exclusiones'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'MOTIVO' => trim((string) $row['MOTIVO']),
                'VIGENTE' => intval($row['VIGENTE']),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => $this->fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       EL DEPOSITO MASIVO

       Se seleccionan cheques de cartera de UN banco emisor y se marcan como
       depositados. Desde ese dia corren las 48 hs habiles -el plazo de
       Parametros-, y el cheque sale de "Echeqs en cartera" y entra en
       "Echeqs depositados" el dia que acredita, por el neto.

       DEL NAVEGADOR LLEGAN SOLO LOS IDS. El banco, el importe, la fecha de
       deposito, la acreditacion, la tasa y el neto los resuelve el servidor
       con las reglas de arriba: un neto aceptado del cliente es la version
       informatica del *0.969 pegado a mano en el Excel de Cob. Electronicos.
       ==================================================================== */

    /**
     * Si hoy se puede depositar, y si no, por que.
     *
     * LA PANTALLA LO PREGUNTA en vez de suponerlo, como con la exclusion: un
     * boton que se dibuja y despues falla contra el servidor es peor que uno
     * apagado que dice que falta. Y el servidor lo vuelve a preguntar al
     * depositar.
     *
     * HACE FALTA LA FILA DEL TABLERO. Sin "Echeqs depositados" activa, un
     * cheque depositado saldria de "Echeqs en cartera" y no entraria en
     * ninguna fila: el tablero informaria de menos sin avisar. Es el hueco
     * entre los dos scripts, y se cierra aca.
     *
     * Las alicuotas NO se preguntan aca: dependen de la fecha de acreditacion,
     * y el rechazo con el motivo lo da el dialogo, que la calcula.
     *
     * @return array ['puede' => bool, 'motivo' => string|null, 'plazo' => int|null,
     *         'fila_activa' => bool]
     */
    public function estadoParaDepositar() {
        if (!$this->depositoCreado()) {
            return ['puede' => false, 'plazo' => null, 'fila_activa' => false,
                    'motivo' => 'Para depositar cheques hace falta correr '
                        . self::SCRIPT_DEPOSITO . ' contra la base central.'];
        }

        $fila = $this->filaDepositadosActiva();

        if (!$fila) {
            return ['puede' => false, 'plazo' => null, 'fila_activa' => false,
                    'motivo' => 'Falta la fila "Echeqs depositados" del tablero (ECHEQS → '
                        . 'DEPOSITADOS), o está inactiva: sin ella, un cheque depositado saldría '
                        . 'del tablero sin entrar en ninguna fila. Corré '
                        . self::SCRIPT_FILA_DEPOSITADOS . ' o activala en Parámetros → Cashflow.'];
        }

        $plazo = $this->getPlazo();

        if ($plazo['dias'] === null) {
            return ['puede' => false, 'plazo' => null, 'fila_activa' => true,
                    'motivo' => $plazo['error']];
        }

        return ['puede' => true, 'plazo' => $plazo['dias'], 'fila_activa' => true, 'motivo' => null];
    }

    /**
     * Si alguna fila ACTIVA del tablero muestra la serie ECHEQS -> DEPOSITADOS.
     *
     * Se pregunta por la serie y no por el codigo de la fila: lo que importa
     * es que esa plata tenga donde mostrarse, y una fila renombrada o con otro
     * codigo cumple igual.
     *
     * @return bool
     */
    public function filaDepositadosActiva() {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT COUNT(*) AS N FROM dbo.RO_T_CASHFLOW_CONF_FILA
             WHERE ORIGEN_PROVIDER = 'ECHEQS' AND ORIGEN_SERIE = 'DEPOSITADOS' AND ACTIVO = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar la fila de echeqs depositados'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row && intval($row['N']) > 0;
    }

    /**
     * Lo que va a pasar si se depositan estos cheques, sin escribir nada. Es lo
     * que muestra el dialogo de confirmacion.
     *
     * NO LANZA POR UNA SELECCION INVALIDA: devuelve los rechazos con su motivo,
     * porque el dialogo tiene que explicar cuales no y por que. Lo que si lanza
     * es una falla de lectura.
     *
     * El deposito, despues, NO usa este resultado: vuelve a calcular todo. Si
     * entre la vista previa y la confirmacion cambio algo -otro usuario
     * deposito uno de los cheques, paso la medianoche- lo que se guarda es lo
     * de ese momento.
     *
     * @param array $ids
     * @param string|null $hoy 'Y-m-d'
     * @return array ['ok' => bool, 'motivo' => string|null, 'rechazos' => [...],
     *         'bancos' => [...], 'calculo' => array|null]
     */
    public function previsualizarDeposito($ids, $hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;
        $estado = $this->estadoParaDepositar();

        if (!$estado['puede']) {
            return self::sinDeposito($estado['motivo'], [], []);
        }

        $sel = self::validarSeleccionDeposito($ids, $this->getEcheqsCartera($hoy));

        if ($sel['error'] !== null) {
            return self::sinDeposito($sel['error'], $sel['rechazos'], $sel['bancos']);
        }

        try {
            $calculo = $this->calcularParaHoy($sel['filas'], $estado['plazo'], $hoy);
        } catch (Exception $e) {
            return self::sinDeposito($e->getMessage(), [], $sel['bancos']);
        }

        return ['ok' => true, 'motivo' => null, 'ir_a' => null, 'rechazos' => [],
                'bancos' => $sel['bancos'], 'calculo' => $calculo];
    }

    /**
     * Una vista previa que no puede depositar, con el motivo.
     *
     * 'ir_a' dice si lo que falta se arregla en Parametros -> Cheques -las
     * alicuotas o el plazo-: el dialogo ofrece ir ahi en vez de dejar al
     * usuario buscando donde se cargan. Se decide por el texto porque es el
     * mismo texto que arman resolverTasa() y plazoDesdeValor(), que son las
     * dos causas.
     *
     * @param string $motivo
     * @param array $rechazos
     * @param array $bancos
     * @return array
     */
    public static function sinDeposito($motivo, $rechazos, $bancos) {
        return [
            'ok' => false,
            'motivo' => $motivo,
            'ir_a' => (strpos((string) $motivo, 'Parámetros → Cheques') !== false)
                ? 'PRECHEQUEADO' : null,
            'rechazos' => $rechazos,
            'bancos' => $bancos,
            'calculo' => null
        ];
    }

    /**
     * Deposita los cheques. TODO O NADA, EN UNA TRANSACCION.
     *
     * Se valida y se calcula TODO antes de abrir la transaccion -la
     * seleccion contra la cartera de hoy, el plazo, la fecha de acreditacion y
     * la tasa-: un lote rechazado no puede descubrirse con la mitad de los
     * depositos escritos.
     *
     * DOS PESTANAS AL MISMO TIEMPO: si otro usuario deposito uno de estos
     * cheques entre la lectura y el INSERT, el indice unico de depositos
     * vigentes hace fallar el segundo, y la transaccion entera vuelve atras.
     *
     * @param array $ids Ids de dbo.SBA14. Es lo unico que llega del navegador
     * @param string|null $observacion Opcional, la misma para toda la tanda
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy: la fecha de deposito no
     *        se elige
     * @return array ['depositados' => int, 'calculo' => array]
     */
    public function depositarCheques($ids, $observacion, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;

        $estado = $this->estadoParaDepositar();

        if (!$estado['puede']) {
            throw new Exception($estado['motivo']);
        }

        $sel = self::validarSeleccionDeposito($ids, $this->getEcheqsCartera($hoy));

        if ($sel['error'] !== null) {
            throw new Exception($sel['error']);
        }

        $calculo = $this->calcularParaHoy($sel['filas'], $estado['plazo'], $hoy);
        $obs = self::validarObservacion($observacion);

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción del depósito'));
        }

        try {
            /* 14 parametros por fila: una tanda de 100 son 1400, debajo del
               tope de 2100. Todas dentro de la MISMA transaccion. */
            foreach (array_chunk($calculo['cheques'], 100) as $tanda) {
                $this->insertarDepositos($cid, $tanda, $calculo, $obs, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el depósito'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['depositados' => $calculo['cantidad'], 'calculo' => $calculo];
    }

    /**
     * Calcula un deposito de hoy: lee el calendario y las alicuotas, y llama a
     * la regla pura. Lo usan la vista previa y el deposito, para que los dos
     * digan lo mismo.
     */
    private function calcularParaHoy($filas, $plazo, $hoy) {
        $rango = self::rangoCalendarioDeposito($hoy, $plazo);

        return self::calcularDeposito($filas, $plazo,
            $this->diasHabiles($rango['desde'], $rango['hasta']),
            $this->getAlicuotas(true), $hoy);
    }

    /**
     * Una tanda del INSERT de depositos.
     *
     * @param resource $cid Conexion con la transaccion ya abierta
     * @param array $cheques Los de calcularDeposito()['cheques']
     * @param array $calculo
     * @param string|null $obs
     * @param string $usuario
     */
    private function insertarDepositos($cid, $cheques, $calculo, $obs, $usuario) {
        $filas = implode(', ', array_fill(0, count($cheques),
            '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'));
        $params = [];

        foreach ($cheques as $c) {
            array_push($params,
                $c['id'],
                $c['n_cheque'],
                $calculo['id_banco'],
                mb_substr((string) $calculo['banco'], 0, 60),
                $calculo['fecha_deposito'],
                $calculo['fecha_acreditacion'],
                $calculo['dias_plazo'],
                $c['bruto'],
                $calculo['tasa'],
                mb_substr($calculo['desglose'], 0, 200),
                $c['neto'],
                $obs,
                $usuario,
                $usuario);
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA_DEPOSITO . "
                 (ID_SBA14, N_CHEQUE, ID_BANCO, DESC_BANCO, FECHA_DEPOSITO, FECHA_ACREDITACION,
                  DIAS_PLAZO, IMPORTE_BRUTO, TASA_APLICADA, DETALLE_TASA, IMPORTE_NETO,
                  OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
             VALUES " . $filas,
            $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el depósito. Si otro usuario '
                . 'depositó alguno de estos cheques recién, actualizá la pantalla'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Deshace depositos vigentes: BAJA LOGICA, con quien y cuando.
     *
     * El cheque vuelve a "Echeqs en cartera" en la fecha del cheque, si esa
     * fecha es de hoy en adelante. Si ya paso, no reaparece: un cheque vencido
     * no depositado no se lee, y deshacer el deposito no cambia eso. La fila
     * dada de baja queda, y es la que el tooltip muestra como historial.
     *
     * TODO O NADA, en una transaccion: o todos tienen un deposito vigente o no
     * se deshace ninguno.
     *
     * @param array $ids
     * @param string $usuario
     * @return array ['deshechos' => int]
     */
    public function deshacerDepositos($ids, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->depositoCreado()) {
            throw new Exception('No hay depósitos que deshacer: corré ' . self::SCRIPT_DEPOSITO
                . ' contra la base central.');
        }

        $v = self::validarDeshacer($ids, $this->getDepositosVigentes($ids));

        if ($v['error'] !== null) {
            throw new Exception($v['error']);
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach (array_chunk($v['ids'], 500) as $tanda) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_DEPOSITO . "
                     SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                     WHERE VIGENTE = 1 AND ID_SBA14 IN ("
                        . implode(', ', array_fill(0, count($tanda), '?')) . ")",
                    array_merge([$usuario, $usuario], $tanda));

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al deshacer el depósito'));
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['deshechos' => count($v['ids'])];
    }

    /**
     * Los depositos VIGENTES de una lista de cheques, por ID_SBA14.
     *
     * @param array $ids
     * @return array Mapa ID_SBA14 => ['id', 'fecha_acreditacion']
     */
    private function getDepositosVigentes($ids) {
        $ids = self::idsPedidos($ids);
        $v = [];

        if (empty($ids)) {
            return $v;
        }

        $cid = $this->conectar('central');

        foreach (array_chunk($ids, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT ID, ID_SBA14, FECHA_ACREDITACION
                 FROM dbo." . self::TABLA_DEPOSITO . "
                 WHERE VIGENTE = 1 AND ID_SBA14 IN ("
                    . implode(', ', array_fill(0, count($tanda), '?')) . ")",
                $tanda);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer los depósitos'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $v[intval($row['ID_SBA14'])] = [
                    'id' => intval($row['ID']),
                    'fecha_acreditacion' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION'])
                ];
            }

            sqlsrv_free_stmt($stmt);
        }

        return $v;
    }

    /**
     * El historial de depositos de una lista de cheques: los vigentes y los
     * deshechos, del mas nuevo al mas viejo.
     *
     * ES LO QUE JUSTIFICA QUE NO HAYA BAJAS FISICAS: el tooltip de la marca
     * dice quien deposito, cuando, y quien lo deshizo. Va en una consulta por
     * tanda y no por cheque: la grilla tiene cientos.
     *
     * @param array $ids
     * @return array Mapa ID_SBA14 => lista de depositos
     */
    public function getHistorialDepositos($ids) {
        $ids = self::idsPedidos($ids);
        $v = [];

        if (empty($ids) || !$this->depositoCreado()) {
            return $v;
        }

        $cid = $this->conectar('central');

        foreach (array_chunk($ids, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT ID, ID_SBA14, FECHA_DEPOSITO, FECHA_ACREDITACION,
                        CAST(IMPORTE_NETO AS FLOAT) AS IMPORTE_NETO, VIGENTE,
                        USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
                 FROM dbo." . self::TABLA_DEPOSITO . "
                 WHERE ID_SBA14 IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")
                 ORDER BY ID DESC",
                $tanda);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer el historial de depósitos'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $v[intval($row['ID_SBA14'])][] = [
                    'id' => intval($row['ID']),
                    'fecha_deposito' => Horizonte::normalizarFecha($row['FECHA_DEPOSITO']),
                    'fecha_acreditacion' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION']),
                    'neto' => floatval($row['IMPORTE_NETO']),
                    'vigente' => intval($row['VIGENTE']),
                    'usuario' => $row['USUARIO_ALTA'],
                    'fecha_alta' => $this->fechaHora($row['FECHA_ALTA']),
                    'usuario_baja' => $row['USUARIO_BAJA'],
                    'fecha_baja' => $this->fechaHora($row['FECHA_BAJA'])
                ];
            }

            sqlsrv_free_stmt($stmt);
        }

        return $v;
    }

    /**
     * Si hay depositos vigentes de cheques en cartera. Es lo que decide el
     * aviso de "falta la fila": sin depositos, que falte la fila no le saca
     * nada al tablero.
     *
     * @param array $filas Las de getEcheqsCartera()
     * @return bool
     */
    public static function hayDepositos($filas) {
        foreach (is_array($filas) ? $filas : [] as $f) {
            if (!empty($f['DEPOSITO'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * El calendario bancario, por la unica lectura de RO_T_CALENDARIO del
     * modulo: Ventas::getDiasHabiles().
     */
    private function diasHabiles($desde, $hasta) {
        require_once __DIR__ . '/Ventas.php';

        return (new Ventas())->getDiasHabiles($desde, $hasta);
    }

    /* ====================================================================
       PARAMETROS DEL DEPOSITO: EL PLAZO Y LAS ALICUOTAS

       Se editan en Parametros -> Cheques, pero viven aca porque las tablas son
       de este modulo: es el mismo criterio con el que el maestro de
       pre-chequeado, Saldos y Cob. Electronicos administran las suyas desde su
       propia clase. Parametros solo las expone.
       ==================================================================== */

    /**
     * El plazo de acreditacion: el valor que sirve, o null con el motivo, y
     * quien lo toco por ultima vez.
     *
     * Se lee directo de la tabla y no por Parametros::getParametrosMap(): ese
     * mapa trae los cuarenta parametros del modulo para usar uno.
     *
     * @return array ['dias' => int|null, 'error' => string|null, 'valor' => string|null,
     *                'usuario' => string|null, 'fecha' => string|null]
     */
    public function getPlazo() {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT VALOR, USUARIO_MODIF, FECHA_MODIF
             FROM dbo.RO_T_CASHFLOW_PARAMETROS
             WHERE CLAVE = ?",
            [self::CLAVE_PLAZO]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el plazo de acreditación'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $r = self::plazoDesdeValor($row ? $row['VALOR'] : null);

        $r['valor'] = $row ? trim((string) $row['VALOR']) : null;
        $r['usuario'] = $row ? $row['USUARIO_MODIF'] : null;
        $r['fecha'] = $row ? $this->fechaHora($row['FECHA_MODIF']) : null;

        return $r;
    }

    /**
     * Guarda el plazo de acreditacion.
     *
     * NO MUEVE NINGUN DEPOSITO YA HECHO: la fecha de acreditacion se calcula y
     * se guarda al depositar, porque el banco ya tomo ese cheque con el plazo
     * de ese dia. El plazo nuevo vale para los depositos que vengan.
     *
     * Tiene su propio camino y no el saveParametro() generico porque ese guarda
     * cualquier texto, y un plazo de cero o de "dos" dejaria sin poder
     * depositar. saveParametro() rechaza esta clave por el mismo motivo.
     *
     * @param mixed $dias
     * @param string $usuario
     * @return int El plazo guardado
     */
    public function guardarPlazo($dias, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $dias = self::validarPlazo($dias);

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
             SET VALOR = ?, " . Auditoria::SET_MODIF . "
             WHERE CLAVE = ?",
            [(string) $dias, $usuario, self::CLAVE_PLAZO]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el plazo de acreditación'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('Falta el parámetro ' . self::CLAVE_PLAZO . ': corré '
                . self::SCRIPT_DEPOSITO . ' contra la base central.');
        }

        return $dias;
    }

    /**
     * Las alicuotas de los cheques, con el historico completo.
     *
     * TODAS, tambien las dadas de baja y las reemplazadas: la vigencia vieja es
     * lo que explica la tasa de un deposito de ese periodo. Sin el script
     * devuelve vacio: la pregunta es "que alicuotas hay", y la respuesta honesta
     * es "ninguna".
     *
     * @param bool $soloActivas
     * @return array Filas con ID, CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO y
     *         quien la cargo
     */
    public function getAlicuotas($soloActivas = false) {
        if (!$this->depositoCreado()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT ID, CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO,
                       USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
                FROM dbo." . self::TABLA_ALICUOTA
             . ($soloActivas ? " WHERE ACTIVO = 1" : "") . "
                ORDER BY CONCEPTO, VIGENCIA_DESDE DESC, ID DESC";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las alícuotas de los cheques'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'CONCEPTO' => trim((string) $row['CONCEPTO']),
                'ALICUOTA' => floatval($row['ALICUOTA']),
                'VIGENCIA_DESDE' => Horizonte::normalizarFecha($row['VIGENCIA_DESDE']),
                'ACTIVO' => intval($row['ACTIVO']),
                'USUARIO' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => $this->fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Carga una vigencia nueva de una alicuota y recalcula los depositos
     * pendientes.
     *
     * UNA ALICUOTA NO SE EDITA: se inserta una vigencia nueva con su
     * VIGENCIA_DESDE. La anterior queda, y es lo que explica por que un deposito
     * de la semana pasada tiene otra tasa. Las validaciones son las de Cob.
     * Electronicos -Retenciones::validarAlicuota()-, sobre el estado resultante.
     *
     * @param string $concepto
     * @param mixed $alicuota Fraccion: 2,5% es 0,025
     * @param string $vigenciaDesde 'Y-m-d'
     * @param string $usuario
     * @return array ['id', 'tasa_total', 'recalculo']
     */
    public function addAlicuota($concepto, $alicuota, $vigenciaDesde, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->depositoCreado()) {
            throw new Exception('Todavía no se pueden cargar alícuotas de cheques. Corré '
                . self::SCRIPT_DEPOSITO . ' contra la base central.');
        }

        $alicuota = self::numeroAlicuota($alicuota);
        $concepto = trim((string) $concepto);
        $vigencia = Horizonte::normalizarFecha($vigenciaDesde);

        // Valida sobre el ESTADO RESULTANTE: las vigentes con esta reemplazando
        // a la de su concepto. Puede lanzar.
        $tasaTotal = Retenciones::validarAlicuota(
            $this->getAlicuotas(true), $concepto, $alicuota, $vigencia);

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA_ALICUOTA . "
                 (CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
             OUTPUT INSERTED.ID
             VALUES (?, ?, ?, 1, ?, ?)",
            [$concepto, $alicuota, $vigencia, $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la alícuota'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return [
            'id' => intval($row['ID']),
            'tasa_total' => $tasaTotal,
            'recalculo' => $this->recalcularDepositosPendientes($usuario)
        ];
    }

    /**
     * Baja LOGICA de una vigencia de alicuota.
     *
     * SE PUEDE DAR DE BAJA LA ULTIMA, a diferencia de Cob. Electronicos. Alla
     * el invariante es "una procesadora ACTIVA tiene alicuotas", y aca no hay
     * procesadora que activar. Lo que pasa sin alicuotas esta cubierto en los
     * dos extremos: los depositos pendientes CONSERVAN el neto que tienen
     * guardado -el recalculo no puede calcular uno nuevo y lo avisa-, y los
     * depositos nuevos se rechazan hasta que se cargue una. Decision de
     * Tesoreria.
     *
     * @param int $id
     * @param string $usuario
     * @return array ['recalculo' => array]
     */
    public function bajaAlicuota($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->depositoCreado()) {
            throw new Exception('Corré ' . self::SCRIPT_DEPOSITO . ' contra la base central.');
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_ALICUOTA . "
             SET ACTIVO = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND ACTIVO = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la alícuota'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('La alícuota ' . intval($id) . ' no existe o ya estaba dada de '
                . 'baja.');
        }

        return ['recalculo' => $this->recalcularDepositosPendientes($usuario)];
    }

    /**
     * Recalcula el neto de los depositos PENDIENTES con las alicuotas de ahora.
     *
     * Corre solo al cargar o dar de baja una alicuota, igual que en Cob.
     * Electronicos. Pendiente es VIGENTE y con acreditacion DE MANANA EN
     * ADELANTE -Retenciones::cortePendientes(), el mismo corte de los dos
     * modulos-: lo que ya acredito, hoy incluido, conservo su tasa, porque su
     * plata entro con esa tasa.
     *
     * Que cambia lo decide Retenciones::planRecalculo(), puro y probado; aca
     * solo se escribe lo que el plan dice, en UNA transaccion: a mitad de
     * camino quedaria una parte de los pendientes con la tasa nueva y otra con
     * la vieja, sin forma de saber cual es cual.
     *
     * QUEDA FIRMADO POR QUIEN GUARDO LA ALICUOTA: es parte de su accion, y ve en
     * la respuesta cuantos depositos cambio y por cuanto.
     *
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array El plan aplicado, con 'aplicados', 'avisos' y 'pendientes_desde'
     */
    public function recalcularDepositosPendientes($usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $corte = Retenciones::cortePendientes($hoy === null ? date('Y-m-d') : $hoy);
        $cid = $this->conectar('central');

        // Solo los pendientes: los acreditados no se recalculan nunca, y traer
        // el historial entero para descartarlo en PHP es al reves. Se cuentan
        // aparte, para poder decir que no se tocaron.
        $stmt = sqlsrv_query($cid,
            "SELECT ID, ID_SBA14, N_CHEQUE, FECHA_ACREDITACION,
                    CAST(IMPORTE_BRUTO AS FLOAT) AS IMPORTE_BRUTO,
                    CAST(TASA_APLICADA AS FLOAT) AS TASA_APLICADA,
                    CAST(IMPORTE_NETO AS FLOAT)  AS IMPORTE_NETO
             FROM dbo." . self::TABLA_DEPOSITO . "
             WHERE VIGENTE = 1 AND FECHA_ACREDITACION >= ?",
            [$corte]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los depósitos pendientes'));
        }

        $items = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Un solo juego de alicuotas: el de los cheques.
            $items[] = [
                'id' => intval($row['ID']),
                'juego' => 0,
                'n_cheque' => $row['N_CHEQUE'] === null ? null : intval($row['N_CHEQUE']),
                'fecha_acreditacion' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION']),
                'importe_bruto' => floatval($row['IMPORTE_BRUTO']),
                'tasa_aplicada' => floatval($row['TASA_APLICADA']),
                'importe_neto' => floatval($row['IMPORTE_NETO'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        $plan = Retenciones::planRecalculo($items, [0 => $this->getAlicuotas(true)], $corte);
        $plan['acreditados'] = $this->contarDepositosAcreditados($cid, $corte);
        $plan['pendientes_desde'] = $corte;
        $plan['aplicados'] = 0;
        $plan['avisos'] = [];

        if (!empty($plan['sin_alicuota'])) {
            $plan['avisos'][] = count($plan['sin_alicuota'][0]) . ' depósito(s) pendiente(s) no '
                . 'se pudieron recalcular porque los cheques no tienen alícuotas vigentes a su '
                . 'fecha de acreditación. Conservan el neto con el que se depositaron; los '
                . 'depósitos nuevos se rechazan hasta que se cargue una alícuota.';
        }

        // Lo que vuelve al navegador no lleva el item crudo: es lo que el
        // panel del resultado dibuja, nada mas.
        foreach ($plan['cambios'] as $i => $c) {
            $plan['cambios'][$i]['n_cheque'] = $c['item']['n_cheque'];
            unset($plan['cambios'][$i]['item']);
        }

        unset($plan['sin_alicuota']);

        if (empty($plan['cambios'])) {
            return $plan;
        }

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción del recálculo'));
        }

        try {
            foreach ($plan['cambios'] as $c) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_DEPOSITO . "
                     SET TASA_APLICADA = ?, DETALLE_TASA = ?, IMPORTE_NETO = ?, "
                        . Auditoria::SET_MODIF . "
                     WHERE ID = ? AND VIGENTE = 1",
                    [$c['tasa_nueva'], $c['desglose_nuevo'], $c['neto_nuevo'], $usuario, $c['id']]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al recalcular el depósito '
                        . $c['id']));
                }

                sqlsrv_free_stmt($stmt);
                $plan['aplicados']++;
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el recálculo'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $plan;
    }

    /** Cuantos depositos vigentes ya acreditaron: no se recalculan, se cuentan */
    private function contarDepositosAcreditados($cid, $corte) {
        $stmt = sqlsrv_query($cid,
            "SELECT COUNT(*) AS N FROM dbo." . self::TABLA_DEPOSITO . "
             WHERE VIGENTE = 1 AND FECHA_ACREDITACION < ?",
            [$corte]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al contar los depósitos acreditados'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? intval($row['N']) : 0;
    }

    /**
     * Lo que dibuja la seccion "Deposito de cheques" de Parametros -> Cheques.
     *
     * LA TASA DE HOY LA CALCULA EL SERVIDOR, con la misma regla que el deposito:
     * si la pantalla la resolviera por su cuenta, podria mostrar una tasa que el
     * deposito no usa.
     *
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array ['tablas_creadas', 'plazo', 'plazo_max', 'alicuotas',
     *         'tasa_hoy', 'hoy', 'avisos']
     */
    public function datosParametrosDeposito($hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;

        if (!$this->depositoCreado()) {
            return [
                'tablas_creadas' => false,
                'plazo' => ['dias' => null, 'error' => null, 'valor' => null,
                            'usuario' => null, 'fecha' => null],
                'plazo_max' => self::PLAZO_MAX,
                'alicuotas' => [],
                'tasa_hoy' => self::tasaParaPantalla([], $hoy),
                'hoy' => $hoy,
                'avisos' => ['Todavía no existen las tablas del depósito de cheques. Corré '
                    . self::SCRIPT_DEPOSITO . ' contra la base central. Mientras tanto no se '
                    . 'puede depositar, y la cartera se ve y se proyecta como hasta ahora.']
            ];
        }

        $alicuotas = $this->getAlicuotas(false);
        $plazo = $this->getPlazo();
        $tasa = self::tasaParaPantalla($alicuotas, $hoy);
        $avisos = [];

        if ($plazo['error'] !== null) {
            $avisos[] = $plazo['error'];
        }

        if ($tasa['tasa'] === null) {
            $avisos[] = 'Los cheques no tienen ninguna alícuota de retención vigente hoy, así que '
                . 'no se puede depositar: el neto saldría igual al bruto. Cargalas abajo, en '
                . 'Depósito de cheques.';
        }

        return [
            'tablas_creadas' => true,
            'plazo' => $plazo,
            'plazo_max' => self::PLAZO_MAX,
            'alicuotas' => self::marcarVigentesHoy($alicuotas, $hoy),
            'tasa_hoy' => $tasa,
            'hoy' => $hoy,
            'avisos' => $avisos
        ];
    }

    /* ====================================================================
       SUB-PESTANA 2 - VENTA COBRADA ANTICIPADA
       ==================================================================== */

    /**
     * Cheques de los clientes que operan con venta cobrada anticipada, con su
     * marca resuelta.
     *
     * Se lee en tres pasos y se cruza con cruzarPrechequeado(), que es donde vive
     * la regla. El maestro y las excepciones son tablas nuestras y chicas; el
     * unico paso caro es la lectura de dbo.SBA14, y va acotada por los codigos
     * del maestro para no traer el universo y descartarlo despues.
     *
     * @return array Filas listas para la pantalla, vacio si el maestro esta vacio
     */
    public function getEcheqsPrechequeado() {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $clientes = $this->getClientesPrechequeado(true);

        if (empty($clientes)) {
            return [];
        }

        return self::cruzarPrechequeado(
            $this->getChequesDeClientes(array_column($clientes, 'CLIENTE')),
            $clientes,
            $this->getExcepciones()
        );
    }

    /**
     * Los cheques marcados, agregados por fecha, cliente y estado. Es lo que
     * consume el neteo de Ventas.
     *
     * SALE DE LA VISTA y no del cruce de PHP: el neteo tiene que poder auditarse
     * desde SQL, y ese es el motivo de que RO_V_CASHFLOW_VENTAS_PRECHEQ exista.
     * Las dos implementaciones son las dos caras de la misma regla y
     * tests/test_echeqs.php verifica con datos reales que dan el mismo total.
     *
     * Devuelve COD_CLIENTE y ESTADO porque quien consume necesita los dos: el
     * canal se deriva del codigo -para poder imputar el neteo al canal que
     * corresponde- y el estado es lo que permite avisar cuanto del neteo sale de
     * cheques que ya no estan en cartera.
     *
     * @return array Filas ['FECHA_CHEQUE', 'COD_CLIENTE', 'ESTADO', 'IMPORTE', 'CHEQUES']
     */
    public function getPrechequeadoTotales() {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT FECHA_CHEQUE, COD_CLIENTE, ESTADO,
                       SUM(IMPORTE) AS IMPORTE, COUNT(*) AS CHEQUES
                FROM dbo.RO_V_CASHFLOW_VENTAS_PRECHEQ
                GROUP BY FECHA_CHEQUE, COD_CLIENTE, ESTADO
                ORDER BY FECHA_CHEQUE";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el neteo de cheques adelantados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'FECHA_CHEQUE' => Horizonte::normalizarFecha($row['FECHA_CHEQUE']),
                'COD_CLIENTE' => trim((string) $row['COD_CLIENTE']),
                'ESTADO' => trim((string) $row['ESTADO']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'CHEQUES' => intval($row['CHEQUES'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Marca o desmarca cheques. EL TILDADO MASIVO ES UNA SOLA TRANSACCION.
     *
     * Destildar los veinte cheques de un cliente con veinte llamadas deja la
     * puerta abierta a que la quinta falle y la proyeccion de Ventas quede a
     * mitad de camino sin que nadie se entere. Aca o entran todas las marcas o no
     * entra ninguna.
     *
     * Solo se aceptan cheques que HOY estan en el listado: un id de otro cliente,
     * de un cheque anulado o de un cliente que no esta en el maestro se rechaza,
     * en lugar de guardar una marca que despues no se ve en ningun lado.
     *
     * @param array $ids Ids de dbo.SBA14
     * @param bool $marcado
     * @param string $usuario
     * @return array ['tocados' => int, 'filas' => [...]] con el estado efectivo
     */
    public function marcarCheques($ids, $marcado, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $pedidos = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $pedidos[$id] = true;
            }
        }

        if (empty($pedidos)) {
            throw new Exception('No llegó ningún cheque para marcar');
        }

        // El universo permitido se vuelve a resolver en el servidor: lo que
        // manda el navegador es una lista de ids, no una autorizacion.
        $permitidos = [];

        foreach ($this->getEcheqsPrechequeado() as $fila) {
            $permitidos[intval($fila['ID_SBA14'])] = true;
        }

        /* array_keys() Y NO array_values(): $pedidos esta INDEXADO por id -es
           lo que deduplica la lista que llega- y sus valores son todos true.
           Con array_values() lo que viajaba a la consulta era una lista de
           true, que SQL Server convierte a 1, y las veinte marcas terminaban
           todas sobre el cheque 1. Es el mismo indexado y el mismo cuidado en
           excluirCheques(). */
        $validos = array_keys(array_intersect_key($pedidos, $permitidos));
        $rechazados = array_keys(array_diff_key($pedidos, $permitidos));

        if (empty($validos)) {
            throw new Exception('Ninguno de los ' . count($pedidos) . ' cheque(s) recibidos está '
                . 'en el listado de venta cobrada anticipada. Puede que la pantalla haya quedado '
                . 'vieja: actualizala y volvé a intentar.');
        }

        $cid = $this->conectar('central');
        $valor = $marcado ? 1 : 0;

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción de marcado'));
        }

        try {
            // El constructor de tabla de un MERGE admite hasta 1000 filas, asi
            // que un destilde muy grande se parte en tandas. Van todas dentro de
            // la MISMA transaccion: la garantia es de la operacion completa.
            foreach (array_chunk($validos, 500) as $tanda) {
                $this->mergeMarcas($cid, $tanda, $valor, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el marcado'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return [
            'tocados' => count($validos),
            'rechazados' => $rechazados,
            // El estado efectivo de lo que quedo guardado, releido de la base:
            // asi el front no tiene que adivinar como quedaron las filas.
            'filas' => $this->getExcepciones($validos)
        ];
    }

    /**
     * Una tanda del MERGE de marcas.
     *
     * MERGE y no DELETE+INSERT: la fila de excepcion guarda quien y cuando, y
     * borrarla para volver a insertarla perderia el motivo de existir de la
     * tabla, que es la trazabilidad.
     *
     * @param resource $cid Conexion con la transaccion ya abierta
     * @param array $ids
     * @param int $valor 1 o 0
     * @param string $usuario
     */
    private function mergeMarcas($cid, $ids, $valor, $usuario) {
        $filas = implode(', ', array_fill(0, count($ids), '(?)'));

        $sql = "MERGE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ AS T
                USING (VALUES $filas) AS S (ID_SBA14)
                    ON T.ID_SBA14 = S.ID_SBA14
                WHEN MATCHED THEN
                    UPDATE SET MARCADO = ?, " . Auditoria::SET_MODIF . "
                WHEN NOT MATCHED BY TARGET THEN
                    INSERT (ID_SBA14, MARCADO, USUARIO_ALTA, USUARIO_MODIF)
                    VALUES (S.ID_SBA14, ?, ?, ?);";

        $params = array_merge($ids, [$valor, $usuario, $valor, $usuario, $usuario]);

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar las marcas'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /* ====================================================================
       MAESTRO DE CLIENTES PRE-CHEQUEADOS

       Vive aca y no en Parametros porque las tablas son de este modulo, que es
       el mismo criterio con el que Saldos y Cob. Electronicos administran las
       suyas desde su propia clase. Parametros solo las expone.
       ==================================================================== */

    /**
     * Clientes del maestro, con cuantos cheques vivos tiene hoy cada uno.
     *
     * EL CONTEO NO ES DECORACION: es la unica forma de que alguien note que cargo
     * un codigo que no trae nada. Un codigo mal tipeado no da error, da una lista
     * vacia.
     *
     * @param bool $soloActivos true para la pantalla de carga, false para el
     *        editor de Parametros, que tiene que ver los inhabilitados para poder
     *        reactivarlos
     * @return array
     */
    public function getClientesPrechequeado($soloActivos = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        /* El conteo va por subconsulta y no por LEFT JOIN + GROUP BY para que un
           cliente sin cheques siga apareciendo con cero, que es justamente el
           caso que hay que poder ver. */
        $sql = "SELECT c.CLIENTE, c.RAZON_SOCIAL, c.DIAS_PRECHEQUEADO,
                       c.ACTIVO, c.FECHA_MODIF AS FECHA_UPDATE, c.USUARIO_MODIF AS USUARIO,
                       (SELECT COUNT(*)
                          FROM dbo.SBA14 s
                         WHERE s.CLIENTE = c.CLIENTE
                           AND s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
                           AND s.ESTADO NOT IN ('X', 'R')) AS CHEQUES_VIVOS
                FROM dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE c";

        if ($soloActivos) {
            $sql .= " WHERE c.ACTIVO = 1";
        }

        $sql .= " ORDER BY c.CLIENTE";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los clientes pre-chequeados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'CLIENTE' => trim((string) $row['CLIENTE']),
                'RAZON_SOCIAL' => trim((string) $row['RAZON_SOCIAL']),
                'DIAS_PRECHEQUEADO' => intval($row['DIAS_PRECHEQUEADO']),
                'ACTIVO' => intval($row['ACTIVO']),
                'FECHA_UPDATE' => $this->fechaHora($row['FECHA_UPDATE']),
                'USUARIO' => $row['USUARIO'],
                'CHEQUES_VIVOS' => intval($row['CHEQUES_VIVOS'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Mapa codigo de cliente => dias de pre-chequeado, de los clientes ACTIVOS.
     *
     * Es lo que consume el neteo de Ventas. La sub-pestana lo resuelve por el
     * mismo camino -cruzarPrechequeado() usa diasDeCliente() sobre el mismo
     * maestro-, que es lo que garantiza que la pantalla y el neteo apliquen el
     * mismo plazo.
     *
     * @return array Mapa 'FR001' => 15
     */
    public function getDiasPrechequeadoPorCliente() {
        $mapa = [];

        foreach ($this->getClientesPrechequeado(true) as $c) {
            $mapa[$c['CLIENTE']] = intval($c['DIAS_PRECHEQUEADO']);
        }

        return $mapa;
    }

    /**
     * Guarda los dias de pre-chequeado de un cliente que ya esta en el maestro.
     *
     * @param string $codigo
     * @param int $dias
     * @param string $usuario
     * @return int Los dias guardados
     */
    public function guardarDiasCliente($codigo, $dias, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $codigo = self::normalizarCodigo($codigo);
        $dias = self::validarDias($dias);

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
             SET DIAS_PRECHEQUEADO = ?, " . Auditoria::SET_MODIF . "
             WHERE CLIENTE = ?",
            [$dias, $usuario, $codigo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar los días de pre-chequeado'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('El cliente "' . $codigo . '" no está en el maestro de '
                . 'pre-chequeado.');
        }

        return $dias;
    }

    /**
     * Valida los dias de pre-chequeado. Estatica y pura.
     *
     * Cero es un valor VALIDO y significa "no desplazar": es el default del
     * alta y el estado en el que queda un cliente hasta que alguien averigua
     * su plazo. Lo que se rechaza es un negativo -que correria la venta hacia
     * adelante del cheque, o sea al reves de lo que significa pre-chequear- y
     * un plazo absurdamente largo, que en la practica es un error de tipeo.
     *
     * @param mixed $dias
     * @return int
     * @throws Exception
     */
    public static function validarDias($dias) {
        if ($dias === null || $dias === '' || !is_numeric($dias)) {
            throw new Exception('Los días de pre-chequeado tienen que ser un número entero. '
                . 'Poné 0 si todavía no se sabe: el cheque queda en su propia fecha.');
        }

        $n = intval($dias);

        if ($n < 0) {
            throw new Exception('Los días de pre-chequeado no pueden ser negativos: '
                . 'correrían la venta hacia adelante del cheque, que es lo contrario de '
                . 'pre-chequear.');
        }

        if ($n > 365) {
            throw new Exception('Los días de pre-chequeado no pueden superar 365.');
        }

        return $n;
    }

    /**
     * Busca un codigo de cliente en dbo.GVA14.
     *
     * Es la validacion del alta: sin esto, un codigo tipeado mal se guarda sin
     * quejarse y despues la sub-pestana no muestra nada, sin que haya forma de
     * saber por que.
     *
     * @param string $codigo
     * @return array|null ['COD_CLIENT', 'RAZON_SOCI'] o null si no existe
     */
    public function buscarCliente($codigo) {
        $codigo = self::normalizarCodigo($codigo);

        if ($codigo === '') {
            return null;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT COD_CLIENT, RAZON_SOCI FROM dbo.GVA14 WHERE COD_CLIENT = ?",
            [$codigo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar el cliente'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            return null;
        }

        return [
            'COD_CLIENT' => trim((string) $row['COD_CLIENT']),
            'RAZON_SOCI' => trim((string) $row['RAZON_SOCI'])
        ];
    }

    /**
     * Da de alta un cliente en el maestro, o reactiva uno que estaba de baja.
     *
     * La razon social se toma de GVA14 y no del navegador: es informativa y tiene
     * que decir lo mismo que Tango, o dos pantallas van a mostrar dos nombres
     * distintos para el mismo codigo.
     *
     * LOS DIAS SON OBLIGATORIOS EN EL ALTA. Son parte de configurar al cliente,
     * no un dato que se descubre despues: un cliente cargado sin plazo queda en
     * cero, y en la pantalla eso es indistinguible de uno que realmente opera
     * con cero dias de adelanto. Cero es una respuesta valida; que falte, no.
     *
     * En una REACTIVACION pueden venir en null, y ahi se conserva el plazo que
     * ya tenia: el switch de la grilla reactiva sin volver a preguntar nada, y
     * pisarle el plazo a cero seria una perdida silenciosa.
     *
     * @param string $codigo Codigo de cliente
     * @param int|null $dias Dias de pre-chequeado. null solo vale reactivando
     * @param string $usuario
     * @return array ['cliente', 'razon_social', 'dias_prechequeado', 'reactivado',
     *                'cheques_vivos']
     */
    public function guardarClientePrechequeado($codigo, $dias, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $codigo = self::normalizarCodigo($codigo);

        if ($codigo === '') {
            throw new Exception('Ingresá el código del cliente');
        }

        if (strlen($codigo) > 6) {
            throw new Exception('El código de cliente no puede superar los 6 caracteres');
        }

        $cliente = $this->buscarCliente($codigo);

        if ($cliente === null) {
            throw new Exception('El código "' . $codigo . '" no existe en el maestro de clientes '
                . 'de Tango. Revisá que esté bien tipeado: se distinguen mayúsculas de '
                . 'minúsculas.');
        }

        $cid = $this->conectar('central');

        $existe = null;

        foreach ($this->getClientesPrechequeado(false) as $c) {
            if ($c['CLIENTE'] === $codigo) {
                $existe = $c;
                break;
            }
        }

        if ($existe !== null && $existe['ACTIVO'] === 1) {
            throw new Exception('"' . $codigo . ' - ' . $cliente['RAZON_SOCI'] . '" ya está '
                . 'cargado y activo.');
        }

        if ($dias === null || $dias === '') {
            if ($existe === null) {
                throw new Exception('Faltan los días de pre-chequeado. Es parte de configurar '
                    . 'al cliente: poné 0 si todavía no se sabe, y el cheque queda en su '
                    . 'propia fecha.');
            }

            // Reactivacion sin dias: conserva el plazo que ya tenia.
            $dias = intval($existe['DIAS_PRECHEQUEADO']);
        }

        $dias = self::validarDias($dias);

        if ($existe !== null) {
            // Estaba de baja: se reactiva en vez de insertar de nuevo, asi la
            // fila conserva su historia. La baja se limpia: una fila activa
            // con FECHA_BAJA diria a la vez que esta y que no esta.
            $sql = "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                    SET ACTIVO = 1, RAZON_SOCIAL = ?, DIAS_PRECHEQUEADO = ?,
                        USUARIO_BAJA = NULL, FECHA_BAJA = NULL, " . Auditoria::SET_MODIF . "
                    WHERE CLIENTE = ?";
            $params = [$cliente['RAZON_SOCI'], $dias, $usuario, $codigo];
        } else {
            $sql = "INSERT INTO dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                        (CLIENTE, RAZON_SOCIAL, DIAS_PRECHEQUEADO, ACTIVO, USUARIO_ALTA,
                         USUARIO_MODIF)
                    VALUES (?, ?, ?, 1, ?, ?)";
            $params = [$codigo, $cliente['RAZON_SOCI'], $dias, $usuario, $usuario];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el cliente pre-chequeado'));
        }

        sqlsrv_free_stmt($stmt);

        $vivos = 0;

        foreach ($this->getClientesPrechequeado(true) as $c) {
            if ($c['CLIENTE'] === $codigo) {
                $vivos = $c['CHEQUES_VIVOS'];
            }
        }

        return [
            'cliente' => $codigo,
            'razon_social' => $cliente['RAZON_SOCI'],
            'dias_prechequeado' => $dias,
            'reactivado' => ($existe !== null),
            'cheques_vivos' => $vivos
        ];
    }

    /**
     * Baja LOGICA de un cliente del maestro.
     *
     * No hay DELETE: la baja tiene que poder auditarse, y ademas las excepciones
     * por cheque que se hubieran cargado siguen ahi por si el cliente vuelve.
     * Mientras esta de baja, sus cheques no aparecen en ningun lado y no netean
     * nada, aunque tengan excepcion cargada.
     *
     * @param string $codigo
     * @param string $usuario
     * @return bool
     */
    public function bajaClientePrechequeado($codigo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $codigo = self::normalizarCodigo($codigo);
        $cid = $this->conectar('central');

        $sql = "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                SET ACTIVO = 0, " . Auditoria::SET_BAJA . "
                WHERE CLIENTE = ?";

        $stmt = sqlsrv_query($cid, $sql, [$usuario, $usuario, $codigo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el cliente pre-chequeado'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('El cliente "' . $codigo . '" no está en el maestro');
        }

        return true;
    }

    /* ====================================================================
       LECTURAS INTERNAS
       ==================================================================== */

    /**
     * Cheques vivos de una lista de clientes.
     *
     * El WHERE repite los filtros que despues aplica cruzarPrechequeado(): es
     * para no traer del motor lo que se va a descartar, no una segunda copia de
     * la regla. La regla que decide es la de PHP.
     *
     * @param array $codigos Codigos de cliente
     * @return array Filas normalizadas
     */
    private function getChequesDeClientes($codigos) {
        $codigos = array_values(array_filter(array_map('trim', $codigos), 'strlen'));

        if (empty($codigos)) {
            return [];
        }

        $cid = $this->conectar('central');

        $marcadores = implode(', ', array_fill(0, count($codigos), '?'));

        /* El IN por parametros no tiene conflicto de collation: el literal toma
           la de la columna. El maestro guarda el codigo tal como lo devuelve
           GVA14, que comparte la collation binaria de dbo.SBA14.CLIENTE, asi que
           las mayusculas coinciden. */
        $sql = "SELECT s.ID_SBA14,
                       CAST(s.N_CHEQUE AS BIGINT)  AS N_CHEQUE,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_CHEQUE,
                       b.DESC_BANCO                AS BANCO,
                       CAST(s.IMPORTE_CH AS FLOAT) AS IMPORTE,
                       s.RAZON_EMIS                AS CLIENTE,
                       s.CLIENTE                   AS COD_CLIENTE,
                       s.ESTADO
                FROM dbo.SBA14 AS s
                LEFT JOIN dbo.BANCO AS b ON s.ID_BANCO = b.ID_BANCO
                WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
                  AND s.ESTADO NOT IN ('X', 'R')
                  AND s.CLIENTE LIKE '[FL]%'
                  AND s.CLIENTE IN ($marcadores)
                ORDER BY s.RAZON_EMIS, s.FECHA_CHEQ";

        $stmt = sqlsrv_query($cid, $sql, $codigos);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los cheques pre-chequeados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fila = $this->filaCheque($row);
            $fila['ESTADO'] = trim((string) $row['ESTADO']);

            $v[] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Excepciones por cheque.
     *
     * @param array|null $ids Para releer solo un subconjunto, o null para todas
     * @return array Filas ['ID_SBA14', 'MARCADO', 'FECHA_UPDATE', 'USUARIO']
     */
    private function getExcepciones($ids = null) {
        $cid = $this->conectar('central');

        $sql = "SELECT ID_SBA14, MARCADO, FECHA_MODIF AS FECHA_UPDATE, USUARIO_MODIF AS USUARIO
                FROM dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ";
        $params = [];

        if (is_array($ids) && !empty($ids)) {
            $sql .= " WHERE ID_SBA14 IN (" . implode(', ', array_fill(0, count($ids), '?')) . ")";
            $params = array_map('intval', $ids);
        }

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las marcas de pre-chequeado'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID_SBA14' => intval($row['ID_SBA14']),
                'MARCADO' => intval($row['MARCADO']),
                'FECHA_UPDATE' => $this->fechaHora($row['FECHA_UPDATE']),
                'USUARIO' => $row['USUARIO']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       UTILIDADES
       ==================================================================== */

    /**
     * Normaliza una fila cruda de dbo.SBA14.
     *
     * Las fechas vuelven como DateTime de sqlsrv y el front espera 'Y-m-d'. El
     * banco nulo -el LEFT JOIN puede no encontrarlo- se muestra como 'Sin banco'
     * y no como celda vacia: una celda vacia se lee como un error de la pantalla.
     *
     * @param array $row
     * @return array
     */
    private function filaCheque($row) {
        $fila = [
            'ID_SBA14' => intval($row['ID_SBA14']),
            'N_CHEQUE' => intval($row['N_CHEQUE']),
            'FECHA_CHEQUE' => Horizonte::normalizarFecha($row['FECHA_CHEQUE']),
            'BANCO' => trim((string) $row['BANCO']),
            'IMPORTE' => floatval($row['IMPORTE']),
            'CLIENTE' => trim((string) $row['CLIENTE']),
            'COD_CLIENTE' => trim((string) $row['COD_CLIENTE'])
        ];

        if ($fila['BANCO'] === '') {
            $fila['BANCO'] = 'Sin banco';
        }

        /* EL BANCO SE COMPARA POR ID, no por el nombre: es lo que arma la tanda
           de un deposito. Sale de dbo.BANCO y no de SBA14, asi que un cheque
           cuyo ID_BANCO no esta en el maestro queda en null, igual que uno sin
           banco: ninguno de los dos se puede depositar. */
        if (array_key_exists('ID_BANCO', $row)) {
            $fila['ID_BANCO'] = ($row['ID_BANCO'] === null) ? null : intval($row['ID_BANCO']);
        }

        /* EL DEPOSITO VIGENTE, o null. Las columnas viajan siempre -en null sin
           el script- asi que la fila siempre trae la clave y nadie tiene que
           preguntar si el circuito existe antes de leerla. */
        if (array_key_exists('DEP_ID', $row)) {
            $fila['DEPOSITO'] = ($row['DEP_ID'] === null) ? null : [
                'id' => intval($row['DEP_ID']),
                'fecha_deposito' => Horizonte::normalizarFecha($row['DEP_FECHA_DEPOSITO']),
                'fecha_acreditacion' => Horizonte::normalizarFecha($row['DEP_FECHA_ACREDITACION']),
                'dias_plazo' => intval($row['DEP_DIAS_PLAZO']),
                'bruto' => floatval($row['DEP_BRUTO']),
                'tasa' => floatval($row['DEP_TASA']),
                'desglose' => trim((string) $row['DEP_DESGLOSE']),
                'neto' => floatval($row['DEP_NETO']),
                'observacion' => $row['DEP_OBSERVACION'],
                'banco' => trim((string) $row['DEP_BANCO']),
                'usuario' => $row['DEP_USUARIO'],
                'fecha_alta' => $this->fechaHora($row['DEP_FECHA_ALTA'])
            ];
        }

        if (array_key_exists('FECHA_PAGO', $row)) {
            $fila['FECHA_PAGO'] = Horizonte::normalizarFecha($row['FECHA_PAGO']);
        }

        /* EXCLUIDO SALE DE QUE HAYA MOTIVO, y no de una columna aparte: la fila
           de exclusion vigente es la que trae el motivo, y el motivo es NOT
           NULL. Un booleano propio seria un segundo dato que puede contradecir
           al primero.

           Las tres columnas van SIEMPRE, aunque el script no se haya corrido:
           asi ni la pantalla ni el proveedor tienen que preguntar si existen
           antes de leer una fila. */
        if (array_key_exists('MOTIVO_EXCLUSION', $row)) {
            $motivo = ($row['MOTIVO_EXCLUSION'] === null)
                ? null : trim((string) $row['MOTIVO_EXCLUSION']);

            $fila['EXCLUIDO'] = ($motivo !== null && $motivo !== '');
            $fila['MOTIVO_EXCLUSION'] = $motivo;
            $fila['EXCLUSION_USUARIO'] = isset($row['EXCLUSION_USUARIO'])
                ? $row['EXCLUSION_USUARIO'] : null;
            $fila['EXCLUSION_FECHA'] = isset($row['EXCLUSION_FECHA'])
                ? $this->fechaHora($row['EXCLUSION_FECHA']) : null;
        }

        return $fila;
    }

    /**
     * Codigo de cliente tal como se compara contra dbo.SBA14: sin espacios y sin
     * cambiarle las mayusculas.
     *
     * NO se pasa a mayusculas a proposito. La columna es Latin1_General_BIN, o
     * sea que la comparacion es binaria: forzar el codigo cambiaria lo que el
     * usuario cargo y podria dejar de matchear.
     *
     * @param string $codigo
     * @return string
     */
    public static function normalizarCodigo($codigo) {
        return trim((string) $codigo);
    }

    /** @param string $servidor @return resource Conexion, con el error traducido */
    private function conectar($servidor) {
        $cid = $this->conn->conectar($servidor);

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos (' . $servidor . ')');
        }

        return $cid;
    }

    /** @return string|null 'Y-m-d H:i:s' de lo que devuelve sqlsrv para un DATETIME */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
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
