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
 *                                Los que Tesoreria deposita en Tango (ESTADO =
 *                                'A' con boleta BDE) alimentan "Echeqs
 *                                depositados", por el neto y el dia que
 *                                acreditan. Ver README-echeqs.md.
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
 *    los cheques sin fecha real traen esa fecha centinela. La cartera se lee
 *    hacia atras -los vencidos, hasta N dias- desde una fecha que calcula PHP,
 *    asi que el universo la deja afuera EXPLICITAMENTE (FECHA_CHEQ >=
 *    FECHA_REAL_DESDE, en sqlUniversoCartera()), y resolverFechaCartera() la
 *    vuelve a rechazar. Antes alcanzaba con >= hoy; con una ventana que algun
 *    dia se agrande, dejaria de alcanzar sin que nadie lo note. Quien vea el
 *    contador 'sin_fecha' siempre en cero no tiene que sospechar que esta
 *    roto.
 *
 * EL UNIVERSO: CARTERA SIN FILTRO DE CLIENTE, VENTA COBRADA ANTICIPADA [FL]
 * -----------------------------------------------------------------------
 * Cheques en Cartera -la cartera, los depositados y el resumen de excluidos-
 * lee TODOS los cheques de terceros de dbo.SBA14, sin mirar el cliente. Hasta
 * el 16/10/2026 filtraba por el prefijo del codigo, y cada cheque que quedaba
 * afuera era plata que no estaba en ninguna fila del tablero: primero los
 * mayoristas (586 cheques en cartera por $ 481.132.774,46) y despues los que
 * no tienen cliente (6 por $ 104.142.740,64, de GO SERVICIOS DIGITALES).
 *
 * POR QUE NO SE CUENTAN DOS VECES:
 *
 *   Los de un cliente     entran con un recibo de cobro en cuenta corriente
 *                         (T_COMP_REC = 'REC') que ya cancelo la factura: la
 *                         cobranza de Cobranzas FR y Cobranzas May proyecta
 *                         solo lo pendiente.
 *   Los sin cliente       entran por CAJA INGRESO (CAI). Hoy son de GO
 *                         ("GO CUOTAS"): el medio de pago "Go Cuotas" del mix
 *                         de cobro de Ventas (LOCALES y ECOMMERCE) esta
 *                         INACTIVO y en 0%. SI ALGUIEN LO ACTIVA, esa plata se
 *                         contaria en la cobranza proyectada de Ventas y
 *                         aca. Ver README-echeqs.md.
 *
 * Venta Cobrada Anticipada sigue en '[FL]%': es otro circuito, que netea la
 * cobranza proyectada de Ventas por canal (canalDeCliente()). La columna
 * CLIENTE es Latin1_General_BIN, o sea que esa comparacion distingue
 * mayusculas; verificado contra la base, ningun codigo empieza en minuscula.
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
     * Las marcas "ya acredito" de los cheques depositados. Una fila por marca,
     * con historial: deshacer marca VIGENTE = 0 y no borra nada.
     */
    const TABLA_ACREDITADO = 'RO_T_CASHFLOW_ECHEQ_ACREDITADO';

    /**
     * Los movimientos de las cuentas bancarias que trae Interbanking, de los
     * ultimos 60 dias. Es una tabla de BI, no nuestra: se lee y nunca se
     * escribe. Ver creditoExtracto().
     */
    const TABLA_EXTRACTO = 'BI_T_EXTRACTOS_INTERBANKING';

    /** El script de la marca, para los mensajes */
    const SCRIPT_ACREDITADO = 'sql/cashflow_echeqs_acreditado.sql';

    /**
     * Quien grabo una marca. Hoy solo MANUAL: una persona desde la pestana.
     * INTERBANKING queda en el CHECK para un proceso que grabe marcas, que ya
     * no hace falta: el extracto se lee directo (creditoExtracto()). LA REGLA
     * NO MIRA EL ORIGEN: lo dice el tooltip.
     */
    const ORIGEN_MANUAL = 'MANUAL';
    const ORIGEN_INTERBANKING = 'INTERBANKING';

    /**
     * Las alicuotas de retencion de los cheques depositados. Es un juego PROPIO
     * y no filas en la de Cob. Electronicos: un cambio en la tasa de Payway no
     * puede mover el neto de un cheque ni al reves.
     */
    const TABLA_ALICUOTA = 'RO_T_CASHFLOW_ECHEQ_ALICUOTA';

    /**
     * Los scripts del deposito, para los mensajes: el de las alicuotas y el
     * plazo, y el de la fila "Echeqs depositados" del tablero.
     */
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
     * Las fechas de acreditacion internas de los cheques vencidos en cartera.
     * Una fila por fecha, con historial: reasignar o deshacer marca VIGENTE = 0
     * y no borra nada. Solo vive en el cashflow: no se escribe en Tango.
     */
    const TABLA_FECHA_ACREDITACION = 'RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION';

    /** El script de los vencidos -la tabla y la ventana-, para los mensajes */
    const SCRIPT_VENCIDOS = 'sql/cashflow_echeqs_vencidos.sql';

    /**
     * La ventana de los vencidos, en RO_T_CASHFLOW_PARAMETROS: cuantos dias
     * corridos hacia atras, desde la fecha del cheque, un vencido en cartera
     * se sigue mostrando. Con el prefijo del modulo, como CLAVE_PLAZO.
     */
    const CLAVE_VENTANA = 'echeqs_dias_vencidos_cartera';

    /**
     * La ventana mas larga que se acepta: 30 dias, el plazo legal para
     * presentar un cheque al cobro. Un cheque mas viejo ya no se puede
     * depositar, asi que mostrarlo pediria una fecha que no puede llegar.
     */
    const VENTANA_MAX = 30;

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
     * @var bool|null Cache del chequeo de la tabla de alicuotas. Aparte de las
     * otras dos por el mismo motivo: es un script independiente, y quien no lo
     * corrio pierde solo el neto de los depositados.
     */
    private $tablaAlicuota = null;

    /** @var bool|null Cache del chequeo de la tabla de la marca "ya acredito" */
    private $tablaAcreditado = null;

    /** @var bool|null Cache del chequeo de la tabla del extracto de Interbanking */
    private $tablaExtracto = null;

    /** @var bool|null Cache del chequeo de la tabla de fechas de los vencidos */
    private $tablaFechaVencidos = null;

    /** @var array|null Cache de contextoCartera(): el calendario se lee una vez */
    private $ctxCartera = null;

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
     * El plazo de acreditacion que sirve, a partir de lo que hay en la base.
     *
     * UN PLAZO QUE FALTA O ESTA ROTO ES null CON EL MOTIVO, nunca un 2 de
     * respaldo: el plazo es un valor de negocio y vive en Parametros. Un
     * respaldo escrito aca proyectaria los depositados con un numero que nadie
     * eligio y que en la pantalla se veria igual que uno configurado. Sin
     * plazo, los depositados no se proyectan y avisan como critico.
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
     * Valida la ventana de los vencidos. LANZA con el motivo.
     *
     * UN ENTERO DE 1 A VENTANA_MAX. Con cero no habria vencidos que mostrar, y
     * eso se dice apagando la funcion, no con un parametro que la deja muda.
     * El tope es el plazo legal para presentar un cheque: despues de eso el
     * cheque no se puede depositar.
     *
     * @param mixed $dias
     * @return int
     */
    public static function validarVentana($dias) {
        $texto = trim((string) $dias);

        if ($texto === '' || !preg_match('/^\d+$/', $texto)) {
            throw new Exception('Los días hacia atrás para los cheques vencidos tienen que ser un '
                . 'número entero de días corridos.');
        }

        $n = intval($texto);

        if ($n < 1 || $n > self::VENTANA_MAX) {
            throw new Exception('Los días hacia atrás para los cheques vencidos tienen que ir de 1 '
                . 'a ' . self::VENTANA_MAX . ': ' . self::VENTANA_MAX . ' es el plazo legal para '
                . 'presentar un cheque al cobro.');
        }

        return $n;
    }

    /**
     * La ventana que sirve, a partir de lo que hay en la base.
     *
     * UNA VENTANA QUE FALTA O ESTA ROTA ES null CON EL MOTIVO, nunca un 30 de
     * respaldo, por lo mismo que plazoDesdeValor(): un respaldo escrito aca
     * mostraria vencidos con un numero que nadie eligio. Sin ventana no se
     * leen vencidos, y quien llama avisa.
     *
     * @param string|null $valor Lo que dice la fila, o null si no existe
     * @return array ['dias' => int|null, 'error' => string|null]
     */
    public static function ventanaDesdeValor($valor) {
        if ($valor === null) {
            return ['dias' => null, 'error' => 'Falta el parámetro ' . self::CLAVE_VENTANA
                . ': corré ' . self::SCRIPT_VENCIDOS . ' contra la base central. Mientras tanto '
                . 'los cheques vencidos en cartera no se leen.'];
        }

        try {
            return ['dias' => self::validarVentana($valor), 'error' => null];
        } catch (Exception $e) {
            return ['dias' => null, 'error' => 'Los días hacia atrás para los cheques vencidos ("'
                . trim((string) $valor) . '") no sirven: ' . $e->getMessage()
                . ' Mientras tanto los cheques vencidos en cartera no se leen. Corregilo en '
                . 'Parámetros → Cheques.'];
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
     * un 0% se leeria como "el banco no retiene nada", y sin tasa un
     * depositado no tiene neto.
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
       CARTERA: DONDE CAE UN CHEQUE

       UNA SOLA REGLA, resolverFechaCartera(), para la grilla y el tablero: si
       la pestana ubicara un cheque con un criterio y el proveedor con otro, el
       cheque se veria en una columna y el tablero lo sumaria en otra. La parte
       del corrimiento -correrAHabil()- la usan tambien Venta Cobrada
       Anticipada y el neteo de Ventas, por el mismo motivo.

       Puros: el calendario y la fecha de hoy se inyectan.
       ==================================================================== */

    /** El cheque esta en cartera y su dia habil es de hoy en adelante: suma ahi */
    const CARTERA_VIGENTE = 'VIGENTE';

    /**
     * Vencido -su dia habil ya paso y sigue en cartera- con una fecha de
     * acreditacion interna de hoy en adelante: suma en esa fecha, por el bruto.
     */
    const CARTERA_VENCIDO_CON_FECHA = 'VENCIDO_CON_FECHA';

    /**
     * Vencido sin fecha asignada: NO SUMA. Nadie dijo cuando entra esa plata,
     * y ponerla en una fecha inventada seria informar de mas. Avisa.
     */
    const CARTERA_VENCIDO_SIN_FECHA = 'VENCIDO_SIN_FECHA';

    /**
     * Vencido cuya fecha asignada ya paso y sigue en cartera: la fecha no se
     * cumplio. No suma, como uno sin fecha, y avisa aparte: hay que
     * reasignarla.
     */
    const CARTERA_FECHA_PASADA = 'FECHA_PASADA';

    /**
     * Quedo afuera de la lectura: vencido mas alla de la ventana -o sin
     * ventana-, o con la centinela. Quien llama no lo lista ni lo suma, y no
     * avisa: un cheque mas viejo que la ventana ya no se puede depositar.
     */
    const CARTERA_FUERA_VENTANA = 'FUERA_VENTANA';

    /** Los estados de cartera que suman en el tablero */
    const CARTERA_SUMAN = [self::CARTERA_VIGENTE, self::CARTERA_VENCIDO_CON_FECHA];

    /** Los estados de un vencido: los que aceptan una fecha asignada */
    const CARTERA_VENCIDOS = [self::CARTERA_VENCIDO_CON_FECHA, self::CARTERA_VENCIDO_SIN_FECHA,
                              self::CARTERA_FECHA_PASADA];

    /** Por que se corrio la fecha de un cheque */
    const CORRIDA_FIN_DE_SEMANA = 'FIN_DE_SEMANA';
    const CORRIDA_FERIADO = 'FERIADO';

    /**
     * La fecha de un cheque corrida al primer dia habil igual o posterior: es
     * donde entra la plata, porque el banco no acredita un sabado, un domingo
     * ni un feriado.
     *
     * EL MOTIVO SALE DE LA FECHA ORIGINAL: sabado o domingo es fin de semana,
     * cualquier otro dia no habil es feriado. Un feriado que cae en fin de
     * semana se informa como fin de semana: es lo que alcanza para explicar
     * el corrimiento, y "feriado" ahi se leeria como un dato de mas.
     *
     * DESPUES DEL TOPE NO SE CORRE: mas alla del fin del eje la fecha no
     * cambia nada -el importe va a 'fuera_horizonte' igual- y correrla
     * pediria calendario de meses que RO_T_CALENDARIO puede no tener, con su
     * aviso de calendario faltante por nada.
     *
     * SIN HABIL EN EL TOPE LA FECHA NO SE CORRE: queda la original con
     * 'sin_habil', y quien llama avisa como critico (DiasHabiles::avisoSinHabil()).
     * Nunca se inventa una fecha.
     *
     * La mecanica es DiasHabiles::siguiente(), con el respaldo de lunes a
     * viernes y sus meses faltantes.
     *
     * @param string|null $fecha 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool de RO_T_CALENDARIO
     * @param string|null $tope 'Y-m-d'. Lo posterior no se corre; null, sin tope
     * @return array ['fecha' => 'Y-m-d'|null, 'corrida' => bool, 'motivo' => string|null,
     *                'faltan' => ['Y-m'], 'sin_habil' => bool]
     */
    public static function correrAHabil($fecha, $habiles, $tope = null) {
        $f = Horizonte::normalizarFecha($fecha);
        $r = ['fecha' => $f, 'corrida' => false, 'motivo' => null, 'faltan' => [], 'sin_habil' => false];

        if ($f === null || ($tope !== null && $f > $tope)) {
            return $r;
        }

        $s = DiasHabiles::siguiente($f, $habiles);
        $r['faltan'] = $s['faltan'];

        if ($s['sin_habil']) {
            $r['sin_habil'] = true;

            return $r;
        }

        if ($s['corrida']) {
            $r['fecha'] = $s['fecha'];
            $r['corrida'] = true;
            $r['motivo'] = (intval(date('N', strtotime($f))) >= 6)
                ? self::CORRIDA_FIN_DE_SEMANA : self::CORRIDA_FERIADO;
        }

        return $r;
    }

    /**
     * La fecha de cheque mas vieja cuyo dia habil todavia es hoy o despues: el
     * dia siguiente al ultimo habil antes de hoy.
     *
     * Un lunes es el sabado: un cheque del sabado o del domingo cae hoy, como
     * uno fechado hoy. Despues de un viernes feriado, el viernes. Cualquier
     * otro dia, hoy. Es desde donde se lee la cartera que suma sin fecha
     * asignada.
     *
     * @param string $hoy 'Y-m-d'
     * @param array $habiles
     * @return string 'Y-m-d'
     */
    public static function primerDiaCartera($hoy, $habiles) {
        $cursor = $hoy;

        for ($i = 0; $i < DiasHabiles::MAX_CORRIMIENTO; $i++) {
            $antes = date('Y-m-d', strtotime($cursor . ' -1 day'));
            $s = DiasHabiles::siguiente($antes, $habiles);

            // Habil: siguiente() lo devuelve sin correrlo.
            if (!$s['corrida'] && !$s['sin_habil']) {
                break;
            }

            $cursor = $antes;
        }

        return $cursor;
    }

    /**
     * Donde cae un cheque de cartera, y por que. ES LA REGLA, y la usan la
     * grilla (ubicarEnEje()) y el proveedor del tablero.
     *
     *   Su dia habil -la fecha del cheque corrida con correrAHabil()- es de
     *   hoy en adelante: VIGENTE, y suma en ese dia, por el bruto.
     *
     *   Si no, es un VENCIDO, y solo se lista dentro de la ventana: con la
     *   fecha del cheque desde hoy - N dias corridos (N, el parametro). Fuera
     *   de la ventana, o sin ventana, FUERA_VENTANA: no se lista ni avisa.
     *   Dentro:
     *     sin fecha asignada            VENCIDO_SIN_FECHA, no suma.
     *     con una fecha anterior a hoy  FECHA_PASADA, no suma: no se cumplio.
     *     con una de hoy en adelante    VENCIDO_CON_FECHA, suma en esa fecha,
     *                                   por el bruto. Hoy cae hoy, como un
     *                                   cheque fechado hoy.
     *
     * EL DIA HABIL DECIDE QUE ES UN VENCIDO, no la fecha escrita: un cheque del
     * sabado leido el lunes cae hoy, como uno fechado hoy, porque es el dia en
     * que se puede depositar. LA VENTANA SE CUENTA DESDE LA FECHA ESCRITA: es
     * el plazo para presentar el cheque.
     *
     * LA FECHA ASIGNADA NO SE CORRE: ya se exige habil al asignarla
     * (validarFechaAsignada()). Y a un vigente no se le aplica: solo un
     * vencido acepta una fecha.
     *
     * @param string|null $fechaCheque 'Y-m-d'
     * @param string $hoy 'Y-m-d'
     * @param array $habiles
     * @param string|null $tope El fin del eje: ver correrAHabil()
     * @param string|null $asignada La fecha de acreditacion interna vigente, o null
     * @param int|null $ventana Dias corridos hacia atras; null, sin vencidos
     * @return array ['fecha_cheque', 'fecha_eje', 'estado', 'fecha_corrida', 'corrida',
     *                'motivo_corrida', 'faltan', 'sin_habil', 'fecha_asignada',
     *                'muestra_hasta', 'dias_vencido']
     */
    public static function resolverFechaCartera($fechaCheque, $hoy, $habiles, $tope, $asignada = null,
                                                $ventana = null) {
        $f = Horizonte::normalizarFecha($fechaCheque);
        $r = [
            'fecha_cheque' => $f,
            'fecha_eje' => null,
            'estado' => self::CARTERA_FUERA_VENTANA,
            'fecha_corrida' => null,
            'corrida' => false,
            'motivo_corrida' => null,
            'faltan' => [],
            'sin_habil' => false,
            'fecha_asignada' => null,
            'muestra_hasta' => null,
            'dias_vencido' => null
        ];

        if ($f === null || $f < self::FECHA_REAL_DESDE) {
            return $r;
        }

        $c = self::correrAHabil($f, $habiles, $tope);

        $r['fecha_corrida'] = $c['fecha'];
        $r['corrida'] = $c['corrida'];
        $r['motivo_corrida'] = $c['motivo'];
        $r['faltan'] = $c['faltan'];
        $r['sin_habil'] = $c['sin_habil'];

        if ($c['fecha'] >= $hoy) {
            $r['estado'] = self::CARTERA_VIGENTE;
            $r['fecha_eje'] = $c['fecha'];

            return $r;
        }

        // Vencido. Fuera de la ventana -o sin ella- no se lista.
        if ($ventana === null || $f < self::sumarDias($hoy, -intval($ventana))) {
            return $r;
        }

        $r['muestra_hasta'] = self::sumarDias($f, intval($ventana));
        $r['dias_vencido'] = intval(round((strtotime($hoy) - strtotime($f)) / 86400));
        $a = Horizonte::normalizarFecha($asignada);
        $r['fecha_asignada'] = $a;

        if ($a === null) {
            $r['estado'] = self::CARTERA_VENCIDO_SIN_FECHA;
        } elseif ($a < $hoy) {
            $r['estado'] = self::CARTERA_FECHA_PASADA;
        } else {
            $r['estado'] = self::CARTERA_VENCIDO_CON_FECHA;
            $r['fecha_eje'] = $a;
        }

        return $r;
    }

    /** 'Y-m-d' mas (o menos) N dias corridos */
    private static function sumarDias($fecha, $dias) {
        return date('Y-m-d', strtotime($fecha . ' ' . (($dias < 0) ? '' : '+') . intval($dias) . ' day'));
    }

    /**
     * Los avisos de la cartera, de lo que se lista:
     *
     *   Vencidos sin fecha       ATENCION: esa plata no esta en ninguna fila
     *                            hasta que alguien diga cuando entra.
     *   Fecha asignada pasada    ATENCION: la fecha no se cumplio y el cheque
     *                            sigue en cartera; hay que reasignarla.
     *   Sin habil en el tope     CRITICO: el cheque quedo en su fecha, que no
     *                            es habil.
     *   Calendario faltante      ATENCION: el dia se calculo con el respaldo
     *                            de lunes a viernes.
     *
     * UN VENCIDO EXCLUIDO NO AVISA: ya se decidio que esa plata no entra, asi
     * que no se le pide una fecha.
     *
     * Recibe filas de la grilla o items del tablero: los dos traen CARTERA,
     * IMPORTE, EXCLUIDO y, los agregados, CHEQUES. Asi la pestana y el tablero
     * dicen lo mismo.
     *
     * @param array $items
     * @return array [['nivel', 'texto']]
     */
    public static function avisosCartera($items) {
        $faltan = [];
        $sinHabil = [];
        $sinFecha = [0, 0.0];
        $pasada = [0, 0.0];

        foreach (is_array($items) ? $items : [] as $it) {
            $c = isset($it['CARTERA']) ? $it['CARTERA'] : null;

            if (!$c || $c['estado'] === self::CARTERA_FUERA_VENTANA) {
                continue;
            }

            $n = isset($it['CHEQUES']) ? intval($it['CHEQUES']) : 1;

            if (empty($it['EXCLUIDO']) && $c['estado'] === self::CARTERA_VENCIDO_SIN_FECHA) {
                $sinFecha[0] += $n;
                $sinFecha[1] += floatval($it['IMPORTE']);
            } elseif (empty($it['EXCLUIDO']) && $c['estado'] === self::CARTERA_FECHA_PASADA) {
                $pasada[0] += $n;
                $pasada[1] += floatval($it['IMPORTE']);
            }

            foreach ($c['faltan'] as $mes) {
                $faltan[$mes] = true;
            }

            if ($c['sin_habil']) {
                $k = $c['fecha_cheque'];

                if (!isset($sinHabil[$k])) {
                    $sinHabil[$k] = [0, 0.0];
                }

                $sinHabil[$k][0] += isset($it['CHEQUES']) ? intval($it['CHEQUES']) : 1;
                $sinHabil[$k][1] += floatval($it['IMPORTE']);
            }
        }

        $avisos = [];

        if ($sinFecha[0] > 0) {
            $avisos[] = ['nivel' => 'warning', 'texto' => $sinFecha[0] . ' cheque(s) vencidos en '
                . 'cartera sin fecha de acreditación por $ ' . number_format($sinFecha[1], 2, ',', '.')
                . ' no se proyectan. Asignales una fecha en Ingresos › Echeqs.'];
        }

        if ($pasada[0] > 0) {
            $avisos[] = ['nivel' => 'warning', 'texto' => $pasada[0] . ' cheque(s) vencidos por $ '
                . number_format($pasada[1], 2, ',', '.') . ' tenían fecha de acreditación ya pasada '
                . 'y siguen en cartera: reasignales la fecha en Ingresos › Echeqs.'];
        }

        foreach ($sinHabil as $fecha => $x) {
            $avisos[] = ['nivel' => 'danger', 'texto' => $x[0] . ' cheque(s) en cartera por $ '
                . number_format($x[1], 2, ',', '.') . ' quedan en su fecha sin correrse a un día '
                . 'hábil: ' . DiasHabiles::avisoSinHabil($fecha)];
        }

        ksort($faltan);

        foreach (DiasHabiles::avisosCalendario(array_keys($faltan)) as $t) {
            $avisos[] = ['nivel' => 'warning', 'texto' => 'Echeqs en cartera: ' . $t];
        }

        return $avisos;
    }

    /** Los dias de la semana, para decir por que una fecha no es habil */
    const DIAS_SEMANA = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
                         5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * Valida la fecha de acreditacion interna de un vencido. LANZA con el
     * motivo.
     *
     *   De MANANA en adelante  hoy ya esta -o va a estar al cierre- en el saldo
     *                          si se acredita; y una fecha pasada no dice
     *                          cuando entra la plata.
     *   Hasta el TOPE          el menor entre el fin del horizonte -mas alla no
     *                          hay columna- y el ultimo dia en que el cheque se
     *                          sigue mostrando, su fecha + N: despues sale de
     *                          la lectura y la fecha nunca llegaria a sumar. El
     *                          motivo dice cual de los dos aplico.
     *   Un dia HABIL           la fecha asignada NUNCA SE CORRE SOLA: un sabado
     *                          o un feriado se rechazan diciendo por que. Un
     *                          mes que no esta en RO_T_CALENDARIO usa el
     *                          respaldo de lunes a viernes, y el mes vuelve en
     *                          'faltan' para avisarlo.
     *
     * Estatica y pura: el calendario y hoy se inyectan.
     *
     * @param mixed $fecha Lo que llega del navegador, 'Y-m-d'
     * @param string $fechaCheque 'Y-m-d'
     * @param string $hoy 'Y-m-d'
     * @param string $fin El fin del horizonte, 'Y-m-d'
     * @param int $ventana Los dias de la ventana de vencidos
     * @param array $habiles
     * @return array ['fecha' => 'Y-m-d', 'faltan' => ['Y-m']]
     */
    public static function validarFechaAsignada($fecha, $fechaCheque, $hoy, $fin, $ventana, $habiles) {
        $t = trim((string) $fecha);
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? Horizonte::normalizarFecha($t) : null;

        if ($f === null || $f !== $t) {
            throw new Exception('La fecha de acreditación no es una fecha válida.');
        }

        if ($f <= $hoy) {
            throw new Exception('La fecha de acreditación tiene que ser de mañana en adelante: el '
                . self::ddmm($f) . ($f === $hoy ? ' es hoy.' : ' ya pasó.'));
        }

        $hasta = self::sumarDias($fechaCheque, intval($ventana));

        if ($hasta < $fin && $f > $hasta) {
            throw new Exception('El ' . self::ddmm($f) . ' es posterior al ' . self::ddmm($hasta)
                . ', el último día en que el cheque se sigue mostrando (su fecha más ' . intval($ventana)
                . ' días): después sale de Echeqs y esa fecha no llegaría a sumar.');
        }

        if ($f > $fin) {
            throw new Exception('El ' . self::ddmm($f) . ' es posterior al fin del horizonte ('
                . self::ddmm($fin) . '): no hay columna donde sumarlo.');
        }

        $s = DiasHabiles::siguiente($f, $habiles);

        if ($s['corrida'] || $s['sin_habil']) {
            $dia = intval(date('N', strtotime($f)));

            throw new Exception('El ' . self::ddmm($f) . ' es '
                . ($dia >= 6 ? self::DIAS_SEMANA[$dia] : 'feriado') . ': elegí un día hábil.');
        }

        return ['fecha' => $f, 'faltan' => $s['faltan']];
    }

    /** 'Y-m-d' -> 'dd/mm' */
    private static function ddmm($fecha) {
        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2);
    }

    /**
     * Que cheques de un pedido aceptan una fecha de acreditacion. ES TODO O
     * NADA, como la exclusion y "ya acredito": con uno solo que no cumple, no
     * se graba ninguno.
     *
     * SOLO LOS VENCIDOS EN CARTERA -con o sin fecha, o con la fecha pasada-.
     * Uno vigente ya tiene su dia; uno depositado entra con la regla de los
     * depositados; uno que no esta en la lectura -rechazado, anulado, fuera
     * de la ventana- no tiene donde sumar. Se valida contra la lectura de la
     * base, no contra lo que manda el navegador: un id no es una autorizacion.
     *
     * @param array $ids
     * @param array $filas Las de la grilla: cartera y depositados, resueltos
     * @return array ['filas' => [...], 'rechazos' => [['id', 'n_cheque', 'motivo']],
     *         'error' => string|null]
     */
    public static function validarSeleccionVencidos($ids, $filas) {
        $porId = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $porId[intval($f['ID_SBA14'])] = $f;
        }

        $validas = [];
        $rechazos = [];

        foreach (self::idsPedidos($ids) as $id) {
            $f = isset($porId[$id]) ? $porId[$id] : null;
            $motivo = null;

            if ($f === null) {
                $motivo = 'no está en cartera: puede que se haya depositado, rechazado o anulado, o '
                    . 'que haya salido de la ventana de vencidos';
            } elseif (!empty($f['DEPOSITO'])) {
                $motivo = 'está depositado en Tango: entra en "Echeqs depositados" con su propia regla';
            } elseif (!isset($f['CARTERA']) || !in_array($f['CARTERA']['estado'], self::CARTERA_VENCIDOS, true)) {
                $motivo = 'no está vencido: su día hábil es de hoy en adelante y ya suma ahí';
            }

            if ($motivo !== null) {
                $rechazos[] = ['id' => $id, 'n_cheque' => $f ? $f['N_CHEQUE'] : null, 'motivo' => $motivo];
                continue;
            }

            $validas[] = $f;
        }

        $error = null;

        if (empty($validas) && empty($rechazos)) {
            $error = 'No llegó ningún cheque.';
        } elseif (!empty($rechazos)) {
            $error = 'No se asignó ninguna fecha: ' . count($rechazos) . ' de los '
                . (count($rechazos) + count($validas)) . ' cheques no son vencidos en cartera. '
                . self::textoRechazos($rechazos) . '.';
        }

        return ['filas' => empty($rechazos) ? $validas : [], 'rechazos' => $rechazos, 'error' => $error];
    }

    /** 'N° 123 motivo; N° 456 motivo' */
    private static function textoRechazos($rechazos) {
        return implode('; ', array_map(function ($r) {
            return 'N° ' . ($r['n_cheque'] === null ? $r['id'] : $r['n_cheque']) . ' ' . $r['motivo'];
        }, $rechazos));
    }

    /**
     * Valida la fecha para cada cheque de una seleccion ya aceptada. TODO O
     * NADA: el tope depende de cada cheque -su fecha + N-, asi que una fecha
     * puede servir para unos y no para otros, y entonces no se graba ninguna.
     *
     * @param mixed $fecha
     * @param array $filas Las de validarSeleccionVencidos()
     * @param string $hoy
     * @param string $fin
     * @param int $ventana
     * @param array $habiles
     * @return array ['fecha' => 'Y-m-d'|null, 'faltan' => [...], 'error' => string|null]
     */
    public static function validarFechaParaSeleccion($fecha, $filas, $hoy, $fin, $ventana, $habiles) {
        $rechazos = [];
        $faltan = [];
        $ok = null;

        foreach ($filas as $f) {
            try {
                $v = self::validarFechaAsignada($fecha, $f['FECHA_CHEQUE'], $hoy, $fin, $ventana, $habiles);
                $ok = $v['fecha'];
                $faltan = array_values(array_unique(array_merge($faltan, $v['faltan'])));
            } catch (Exception $e) {
                $rechazos[] = ['id' => intval($f['ID_SBA14']), 'n_cheque' => $f['N_CHEQUE'],
                               'motivo' => rtrim($e->getMessage(), '.')];
            }
        }

        if (empty($rechazos)) {
            return ['fecha' => $ok, 'faltan' => $faltan, 'error' => null];
        }

        // Si la fecha no sirve para ninguno por el mismo motivo -es hoy, es
        // sabado-, se dice una vez y no una por cheque.
        $motivos = array_values(array_unique(array_column($rechazos, 'motivo')));

        return ['fecha' => null, 'faltan' => [], 'error' => 'No se asignó ninguna fecha: '
            . ((count($motivos) === 1 && count($rechazos) === count($filas))
                ? $motivos[0]
                : count($rechazos) . ' de los ' . count($filas) . ' cheques no la aceptan. '
                  . self::textoRechazos($rechazos)) . '.'];
    }

    /**
     * Que fechas de un pedido se pueden deshacer. TODO O NADA: o todos los
     * cheques tienen una fecha vigente, o no se deshace ninguna.
     *
     * @param array $ids
     * @param array $vigentes Mapa ID_SBA14 => fecha vigente
     * @return array ['ids' => [...], 'error' => string|null]
     */
    public static function validarDeshacerFecha($ids, $vigentes) {
        $pedidos = self::idsPedidos($ids);

        if (empty($pedidos)) {
            return ['ids' => [], 'error' => 'No llegó ningún cheque.'];
        }

        $sin = array_values(array_filter($pedidos, function ($id) use ($vigentes) {
            return !isset($vigentes[$id]);
        }));

        if (!empty($sin)) {
            return ['ids' => [], 'error' => 'No se deshizo ninguna fecha: ' . count($sin) . ' de los '
                . count($pedidos) . ' cheque(s) no tienen fecha de acreditación asignada. Puede que la '
                . 'pantalla haya quedado vieja: actualizala y volvé a intentar.'];
        }

        return ['ids' => $pedidos, 'error' => null];
    }

    /**
     * Los parametros del INSERT de fechas asignadas, 6 por cheque, en el orden
     * de las columnas de asignarFechaVencidos(): id, fecha, observacion, la
     * fecha que reemplaza -o null- y el usuario dos veces.
     *
     * Estatica y pura.
     *
     * @param array $ids
     * @param string $fecha 'Y-m-d', ya validada
     * @param string|null $obs Ya validada
     * @param array $reemplaza Mapa ID_SBA14 => ID de la fecha vigente que se da de baja
     * @param string $usuario
     * @return array
     */
    public static function paramsInsertFecha($ids, $fecha, $obs, $reemplaza, $usuario) {
        $params = [];

        foreach ($ids as $id) {
            $id = intval($id);
            array_push($params, $id, $fecha, $obs, isset($reemplaza[$id]) ? intval($reemplaza[$id]) : null,
                $usuario, $usuario);
        }

        return $params;
    }

    /**
     * Cuanto hay vencido en lo que se muestra, para la tarjeta, el pie y el
     * interruptor de la pestana. SIN LOS EXCLUIDOS: esos tienen su propio
     * resumen, y no se les pide una fecha.
     *
     *   con_fecha   suman en su fecha asignada: estan DENTRO de "Echeqs en
     *               cartera". Con sus totales por vista.
     *   sin_fecha   no suman: los sin fecha y los de fecha pasada.
     *   pasada      de esos, los de fecha pasada.
     *   ocultables  los que esconde el interruptor: los sin fecha. Los de
     *               fecha pasada se ven siempre, porque piden una accion.
     *
     * Estatica y pura: recibe las filas ya armadas por EjeVista.
     *
     * @param array $filas
     * @return array
     */
    public static function resumenVencidos($filas) {
        $r = [
            'cheques' => 0, 'bruto' => 0,
            'con_fecha' => ['cheques' => 0, 'bruto' => 0, 'total_tramo' => 0, 'total_meses' => 0,
                            'total_horizonte' => 0],
            'sin_fecha' => ['cheques' => 0, 'bruto' => 0],
            'pasada' => ['cheques' => 0, 'bruto' => 0],
            'ocultables' => ['cheques' => 0, 'bruto' => 0]
        ];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $estado = isset($f['ESTADO_CARTERA']) ? $f['ESTADO_CARTERA'] : null;

            if (!empty($f['EXCLUIDO']) || !in_array($estado, self::CARTERA_VENCIDOS, true)) {
                continue;
            }

            $importe = floatval($f['IMPORTE']);
            $r['cheques']++;
            $r['bruto'] += $importe;

            if ($estado === self::CARTERA_VENCIDO_CON_FECHA) {
                $r['con_fecha']['cheques']++;
                $r['con_fecha']['bruto'] += $importe;

                foreach (['total_tramo', 'total_meses', 'total_horizonte'] as $t) {
                    $r['con_fecha'][$t] += isset($f[$t]) ? floatval($f[$t]) : 0;
                }

                continue;
            }

            $r['sin_fecha']['cheques']++;
            $r['sin_fecha']['bruto'] += $importe;

            $k = ($estado === self::CARTERA_FECHA_PASADA) ? 'pasada' : 'ocultables';
            $r[$k]['cheques']++;
            $r[$k]['bruto'] += $importe;
        }

        return $r;
    }

    /* ====================================================================
       DEPOSITO: LAS REGLAS

       EL DEPOSITO SE LEE DE TANGO. Tesoreria lo registra en Tango en el mismo
       momento en que deposita el cheque en el banco -con una boleta de
       deposito, BDE-, a proposito: asi nadie le asigna ese cheque a un pago.
       El cheque pasa a ESTADO = 'A' y sale de la lectura de cartera. Antes el
       cashflow esperaba que alguien marcara el deposito mientras el cheque
       seguia en cartera, y eso no pasaba nunca: durante las 48 hs habiles
       hasta que acreditaba, la plata no estaba en ninguna fila del tablero.

       NADA DEL DEPOSITO SE GUARDA. La fecha de la boleta es la de Tango; la
       acreditacion, la tasa y el neto se calculan AL LEER con el plazo y las
       alicuotas de Parametros -> Cheques. Un cambio en cualquiera de los dos
       se ve solo en los cheques que todavia no acreditaron, sin recalcular
       nada.

       Cada regla tiene UNA funcion, y la usan la pestana y el proveedor: si
       dos caminos resolvieran la acreditacion o el estado de un deposito con
       criterios distintos, el cheque se veria en una columna y el tablero lo
       sumaria en otra.
       ==================================================================== */

    /** El deposito se proyecta: acredita de manana en adelante y nadie dijo que ya acredito */
    const DEPOSITO_PROYECTA = 'PROYECTA';

    /**
     * El deposito ya acredito: el extracto de Interbanking trae el credito
     * (ACREDITADO_POR_EXTRACTO), llego su dia (ACREDITADO_POR_PLAZO) o alguien
     * lo marco "ya acredito" (ACREDITADO_POR_MARCA). La plata ya esta en el
     * saldo bancario y no suma a ninguna fila.
     */
    const DEPOSITO_ACREDITADO = 'ACREDITADO';

    /**
     * Al deposito le falta la fecha de acreditacion -plazo roto o ningun habil
     * en el tope- o el neto -sin alicuotas vigentes a su acreditacion-. No se
     * proyecta y AVISA como critico: un dato que falta no se reemplaza por un
     * cero ni por el bruto.
     */
    const DEPOSITO_SIN_DATOS = 'SIN_DATOS';

    /** Por que un deposito cuenta como acreditado */
    const ACREDITADO_POR_PLAZO = 'PLAZO';
    const ACREDITADO_POR_MARCA = 'MARCA';

    /**
     * El extracto de Interbanking trae un credito con el numero y el importe
     * del cheque: el banco ya lo acredito. Ver creditoExtracto().
     */
    const ACREDITADO_POR_EXTRACTO = 'EXTRACTO';

    /** El estado de dbo.SBA14 de un cheque que salio de cartera: aplicado */
    const ESTADO_APLICADO = 'A';

    /**
     * El comprobante de salida que es un deposito: la boleta de deposito de
     * Tesoreria ("BANCO: DEPOSITO" en dbo.SBA02).
     *
     * SE IDENTIFICA POR EL CODIGO, T_COMP_SAL, y no por ID_SBA02_SAL. Las dos
     * columnas coinciden siempre -verificado el 16/10/2026: las 27.627 filas
     * con 'BDE' tienen ID_SBA02_SAL = 11-, pero el codigo es la misma clave
     * con la que se lee la boleta en dbo.SBA05 (COD_COMP) y un id interno
     * puede cambiar entre instalaciones de Tango. Un 'O/P' es una orden de
     * pago: el cheque se endoso a un proveedor y no entra al banco.
     */
    const COMPROBANTE_DEPOSITO = 'BDE';

    /**
     * dbo.SBA01.TIPO de una cuenta bancaria. Una boleta de deposito tambien se
     * usa para mover cheques a cuentas que no son un banco -la cuenta
     * comitente de Santander Valores, la caja-; esa plata no acredita a las
     * 48 hs en el saldo de Interbanking.
     */
    const TIPO_CUENTA_BANCO = 'B';

    /**
     * FECHA_SAL, como FECHA_CHEQ, trae la centinela '1800-01-01' cuando no hay
     * fecha. Todo lo anterior a esto no es una fecha real.
     */
    const FECHA_REAL_DESDE = '1900-01-01';

    /**
     * Si una fila de dbo.SBA14 es un deposito.
     *
     * LAS CUATRO CONDICIONES, y por que:
     *
     *   ESTADO = 'A'          salio de cartera. Uno que despues se rechaza o se
     *                         anula pasa a 'R' o 'X' y deja de leerse solo.
     *   T_COMP_SAL = 'BDE'    salio por una boleta de deposito y no por una
     *                         orden de pago (endoso a un proveedor).
     *   FECHA_SAL real        no la centinela 1800-01-01.
     *   CUENTA BANCARIA       la cuenta que la boleta debita en dbo.SBA05 es un
     *                         banco (dbo.SBA01.TIPO = 'B'). Quedan afuera el
     *                         endoso a Santander Valores -va a la cuenta
     *                         comitente- y las devoluciones -van a la caja-.
     *
     * TIPO_SAL NO DECIDE NADA. 'D' trae la cuenta en CTA_DESTIN y 'O' la deja
     * en 0, pero las boletas de los dos tienen la misma forma en dbo.SBA05:
     * una linea al debe contra la cuenta destino y una al haber contra
     * CHEQUES ELECTRONICOS. Verificado el 16/10/2026 sobre 120 dias de
     * boletas: ninguna tiene dos lineas al debe.
     *
     * SIN LA LINEA DE LA BOLETA -$cuenta null- CUENTA COMO DEPOSITO. Es un dato
     * que falta, no uno que diga que no entra al banco, y la regla por
     * defecto es que una boleta de deposito es un deposito. Quien llama lo
     * avisa.
     *
     * EL CLIENTE NO SE MIRA: Cheques en Cartera lee todos los cheques, tambien
     * los que no tienen cliente. Ver el encabezado.
     *
     * Estatica y pura. La consulta aplica las tres primeras como filtro; la
     * cuarta necesita la boleta, que se lee aparte.
     *
     * @param array $fila Con ESTADO, T_COMP_SAL y FECHA_SAL
     * @param array|null $cuenta ['cod_cta', 'descripcion', 'tipo'] de la linea
     *        al debe de la boleta, o null si no se encontro
     * @return bool
     */
    public static function esDepositoTango($fila, $cuenta) {
        $fecha = Horizonte::normalizarFecha(isset($fila['FECHA_SAL']) ? $fila['FECHA_SAL'] : null);

        if (!isset($fila['ESTADO']) || trim((string) $fila['ESTADO']) !== self::ESTADO_APLICADO
            || !isset($fila['T_COMP_SAL'])
            || trim((string) $fila['T_COMP_SAL']) !== self::COMPROBANTE_DEPOSITO
            || $fecha === null || $fecha < self::FECHA_REAL_DESDE) {
            return false;
        }

        if ($cuenta === null) {
            return true;
        }

        return trim((string) $cuenta['tipo']) === self::TIPO_CUENTA_BANCO;
    }

    /**
     * La fecha de acreditacion de un deposito: el N-esimo dia habil bancario
     * POSTERIOR al de la boleta, con N = el plazo de Parametros.
     *
     * LA FECHA DEL CHEQUE NO INTERVIENE, y es una decision de negocio: el banco
     * acredita a las 48 hs habiles de recibir el deposito, sea cual sea la
     * fecha escrita en el cheque. Un cheque fechado dentro de veinte dias
     * depositado hoy acredita el miercoles, no dentro de veinte dias. Vale
     * tambien para un descuento de echeqs: si el banco acredita antes, se
     * marca "ya acredito".
     *
     * Con 2: lunes -> miercoles, viernes -> martes, sabado o domingo -> martes.
     * Un deposito hecho un feriado cuenta desde el habil siguiente. La mecanica
     * es DiasHabiles::habilesDespues(), con el respaldo de lunes a viernes del
     * resto del modulo y su aviso de calendario faltante.
     *
     * SIN NINGUN HABIL EN EL TOPE LA FECHA ES null: un deposito no puede
     * acreditar en una fecha inventada. Quien llama no lo proyecta y avisa.
     *
     * @param string $fechaDeposito 'Y-m-d' de la boleta
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
     * Desde que fecha de boleta hay que leer los depositos, y hasta que dia el
     * calendario.
     *
     * LO NECESARIO Y NADA MAS. Cada dia habil del plazo puede correrse hasta
     * DiasHabiles::MAX_CORRIMIENTO dias, asi que un deposito con la boleta
     * anterior a hoy - plazo x MAX_CORRIMIENTO ya acredito seguro y no
     * aporta nada. El calendario va de ahi hasta lo mismo para adelante: pedir
     * de menos haria que el respaldo de lunes a viernes se disparara por un
     * rango corto y no por un calendario que falta. Mismo criterio que
     * Saldos::rangoCalendarioAcreditacion().
     *
     * SIN PLAZO -parametro roto o faltante- no hay acreditacion que calcular:
     * se leen los depositos de un solo tramo de corrimiento, para poder
     * avisar cuantos quedaron sin proyectar, y no los de treinta.
     *
     * @param string $hoy 'Y-m-d'
     * @param int|null $plazo
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoDepositos($hoy, $plazo) {
        $dias = max(1, intval($plazo)) * DiasHabiles::MAX_CORRIMIENTO;

        return [
            'desde' => date('Y-m-d', strtotime($hoy . ' -' . $dias . ' day')),
            'hasta' => date('Y-m-d', strtotime($hoy . ' +' . $dias . ' day'))
        ];
    }

    /**
     * El credito del extracto de Interbanking que dice que el banco ya
     * acredito este cheque, o null.
     *
     * EL CRUCE ES POR NUMERO E IMPORTE, y nada mas: NRO_COMPROBANTE = el numero
     * del cheque, IMPORTE = el bruto -el banco acredita el cheque entero y
     * debita la retencion aparte-, un credito (TIPO_MOV = 'C') y con fecha
     * entre la boleta y hoy. Si hay varios, vale el mas viejo. El tope de hoy
     * es el de la fecha inyectada: un credito posterior no puede decir que ya
     * acredito.
     *
     * POR QUE NO POR IMPORTE SOLO. Galicia informa el numero del cheque en
     * NRO_COMPROBANTE; Frances acredita cada cheque por el importe exacto pero
     * con un numero del banco, y Santander no detalla los echeqs: solo trae el
     * total del dia. Cruzar por importe solo pondria a Frances, pero tambien
     * cualquier otro credito del mismo importe. Decision de Tesoreria: lo que
     * no cruza sigue por plazo o con la marca manual. Verificado el 16/10/2026.
     *
     * LA AUSENCIA NO DICE NADA. El extracto guarda 60 dias y no todos los
     * bancos detallan los echeqs: que no haya credito no quiere decir que no
     * acredito. Solo un credito encontrado da el cheque por acreditado.
     *
     * Estatica y pura.
     *
     * @param array $fila Con N_CHEQUE, IMPORTE y FECHA_SAL
     * @param array $creditos Los de leerCreditosExtracto()
     * @param array $usados Mapa id del movimiento => true: un movimiento
     *        acredita un solo cheque
     * @param string|null $hoy 'Y-m-d'. Sin fecha, no hay tope
     * @return array|null El credito: ['id', 'fecha', 'nro_banco', 'banco',
     *         'nro_cuenta', 'comprobante', 'importe', 'descripcion']
     */
    public static function creditoExtracto($fila, $creditos, $usados = [], $hoy = null) {
        $numero = isset($fila['N_CHEQUE']) ? intval($fila['N_CHEQUE']) : 0;
        $importe = round(floatval($fila['IMPORTE']), 2);
        $desde = Horizonte::normalizarFecha(isset($fila['FECHA_SAL']) ? $fila['FECHA_SAL'] : null);
        $elegido = null;

        if ($numero <= 0 || $desde === null) {
            return null;
        }

        foreach (is_array($creditos) ? $creditos : [] as $c) {
            if (isset($usados[$c['id']])
                || (isset($c['tipo_mov']) && $c['tipo_mov'] !== 'C')
                || intval($c['comprobante']) !== $numero
                || abs(round(floatval($c['importe']), 2) - $importe) >= 0.005
                || $c['fecha'] === null || $c['fecha'] < $desde
                || ($hoy !== null && $c['fecha'] > $hoy)) {
                continue;
            }

            if ($elegido === null || $c['fecha'] < $elegido['fecha']
                || ($c['fecha'] === $elegido['fecha'] && $c['id'] < $elegido['id'])) {
                $elegido = $c;
            }
        }

        return $elegido;
    }

    /**
     * Todo lo que se sabe de un deposito, hoy: acreditacion, tasa, neto y en
     * que estado esta. ES LA REGLA QUE DECIDE DONDE CAE UN CHEQUE DEPOSITADO
     * Y POR CUANTO, y la usan la pestana y el proveedor.
     *
     *   Acreditacion  fechaAcreditacion(FECHA_SAL, plazo).
     *   Tasa          la suma de las alicuotas vigentes A LA FECHA DE
     *                 ACREDITACION, como Cob. Electronicos.
     *   Neto          Retenciones::importeNeto() sobre el bruto del cheque.
     *
     * EL ESTADO, en este orden:
     *
     *   1. acredita ANTES de hoy        ACREDITADO por plazo, y no se lista:
     *                                   ya esta en el saldo y no hay nada que
     *                                   seguir.
     *   2. con credito en el extracto   ACREDITADO por EXTRACTO: el banco ya
     *                                   lo acredito (creditoExtracto()). Se
     *                                   lista hasta su fecha por plazo, como
     *                                   la marca.
     *   3. acredita HOY                 ACREDITADO por plazo, y se lista ese
     *                                   dia sin sumar. Es el corte de Cob.
     *                                   Electronicos (cortePendientes()): lo
     *                                   que acredita hoy ya esta -o va a estar
     *                                   al cierre- en el saldo de Interbanking,
     *                                   y contarlo aca lo contaria dos veces.
     *   4. marcado "ya acredito"        ACREDITADO por marca, y se lista hasta
     *                                   su fecha de acreditacion. LA MARCA NO
     *                                   DEPENDE DEL ORIGEN: una manual y una de
     *                                   Interbanking dicen lo mismo.
     *   5. sin fecha o sin neto         SIN_DATOS: no se proyecta, se lista y
     *                                   se avisa como critico.
     *   6. lo demas                     PROYECTA, en su acreditacion y por el
     *                                   neto.
     *
     * Estatica y pura: recibe todo lo leido. El plazo es el de
     * plazoDesdeValor(), con su motivo si falta.
     *
     * @param array $fila Con FECHA_SAL, IMPORTE, N_COMP_SAL, TIPO_SAL y CTA_DESTIN
     * @param array|null $cuenta La de la linea al debe de la boleta
     * @param array $plazo ['dias' => int|null, 'error' => string|null]
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $alicuotas Las de los cheques
     * @param array|null $marca La marca "ya acredito" vigente, o null
     * @param string $hoy 'Y-m-d'
     * @param array|null $credito El de creditoExtracto(), o null
     * @return array El deposito, con 'estado', 'motivo', 'listar' y 'error'
     */
    public static function resolverDeposito($fila, $cuenta, $plazo, $habiles, $alicuotas, $marca, $hoy,
                                            $credito = null) {
        $bruto = round(floatval($fila['IMPORTE']), 4);
        $d = [
            'boleta' => isset($fila['N_COMP_SAL']) ? trim((string) $fila['N_COMP_SAL']) : null,
            'fecha_deposito' => Horizonte::normalizarFecha($fila['FECHA_SAL']),
            'tipo_sal' => isset($fila['TIPO_SAL']) ? trim((string) $fila['TIPO_SAL']) : null,
            'cta_destin' => isset($fila['CTA_DESTIN']) ? $fila['CTA_DESTIN'] : null,
            'cuenta' => $cuenta,
            'dias_plazo' => $plazo['dias'],
            'fecha_acreditacion' => null,
            'faltan' => [],
            'sin_habil' => false,
            'bruto' => $bruto,
            'tasa' => null,
            'desglose' => '',
            'neto' => null,
            'marca' => $marca,
            'extracto' => $credito,
            'estado' => null,
            'motivo' => null,
            'listar' => true,
            'error' => null
        ];

        if ($plazo['dias'] === null) {
            $d['error'] = $plazo['error'];
        } else {
            $acr = self::fechaAcreditacion($d['fecha_deposito'], $plazo['dias'], $habiles);
            $d['fecha_acreditacion'] = $acr['fecha'];
            $d['faltan'] = $acr['faltan'];
            $d['sin_habil'] = $acr['sin_habil'];

            if ($acr['fecha'] === null) {
                $d['error'] = DiasHabiles::avisoSinHabil($d['fecha_deposito']);
            } else {
                try {
                    $r = Retenciones::resolverTasa($alicuotas, $acr['fecha'], self::TEXTOS_TASA);
                    $d['tasa'] = $r['tasa'];
                    $d['desglose'] = Retenciones::desglose($r['detalle']);
                    $d['neto'] = Retenciones::importeNeto($bruto, $r['tasa']);
                } catch (Exception $e) {
                    $d['error'] = $e->getMessage();
                }
            }
        }

        $acredita = $d['fecha_acreditacion'];

        if ($acredita !== null && $acredita < $hoy) {
            $d['estado'] = self::DEPOSITO_ACREDITADO;
            $d['motivo'] = self::ACREDITADO_POR_PLAZO;
            $d['listar'] = false;
        } elseif (!empty($credito)) {
            $d['estado'] = self::DEPOSITO_ACREDITADO;
            $d['motivo'] = self::ACREDITADO_POR_EXTRACTO;
        } elseif ($acredita !== null && $acredita < Retenciones::cortePendientes($hoy)) {
            $d['estado'] = self::DEPOSITO_ACREDITADO;
            $d['motivo'] = self::ACREDITADO_POR_PLAZO;
        } elseif (!empty($marca)) {
            $d['estado'] = self::DEPOSITO_ACREDITADO;
            $d['motivo'] = self::ACREDITADO_POR_MARCA;
        } elseif ($acredita === null || $d['neto'] === null) {
            $d['estado'] = self::DEPOSITO_SIN_DATOS;
        } else {
            $d['estado'] = self::DEPOSITO_PROYECTA;
        }

        return $d;
    }

    /**
     * Los depositos de una lectura de Tango, resueltos: cuales lo son, cuales
     * se listan, y los avisos. Es lo que devuelve getDepositadosTango() a la
     * pestana y al proveedor, para que los dos sumen lo mismo.
     *
     * LOS AVISOS SON DE LO QUE SE LISTA. Un deposito que ya acredito no se
     * proyecta de todos modos, y avisar que le faltan las alicuotas a algo
     * que ya entro al banco pediria una accion que no cambia nada.
     *
     *   Sin plazo, sin habil, sin alicuotas   CRITICO: el cheque no esta en
     *                                         ninguna fila y la plata existe.
     *   Calendario faltante                   ATENCION: la fecha se calculo
     *                                         con el respaldo de lunes a
     *                                         viernes y puede estar corrida.
     *   Boleta sin cuenta                     ATENCION: se cuenta como
     *                                         deposito, pero nadie verifico que
     *                                         vaya a un banco.
     *
     * Estatica y pura.
     *
     * @param array $filas Las de la consulta, normalizadas
     * @param array $cuentas Mapa N_COMP_SAL => cuenta de la linea al debe
     * @param array $plazo El de plazoDesdeValor()
     * @param array $habiles
     * @param array $alicuotas
     * @param array $marcas Mapa ID_SBA14 => marca "ya acredito" vigente
     * @param string $hoy
     * @param array $creditos Los del extracto de Interbanking (leerCreditosExtracto()).
     *        Cada movimiento acredita un solo cheque: se asignan en el orden de
     *        las filas -por fecha de boleta-, asi el deposito mas viejo toma el
     *        credito mas viejo
     * @return array ['filas' => [...], 'avisos' => [['nivel', 'texto']]]
     */
    public static function resolverDepositos($filas, $cuentas, $plazo, $habiles, $alicuotas, $marcas, $hoy,
                                             $creditos = []) {
        $listadas = [];
        $sinDatos = [];
        $faltan = [];
        $sinCuenta = [];
        $usados = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $boleta = isset($f['N_COMP_SAL']) ? trim((string) $f['N_COMP_SAL']) : '';
            $cuenta = isset($cuentas[$boleta]) ? $cuentas[$boleta] : null;

            if (!self::esDepositoTango($f, $cuenta)) {
                continue;
            }

            $id = intval($f['ID_SBA14']);
            $credito = self::creditoExtracto($f, $creditos, $usados, $hoy);

            if ($credito !== null) {
                $usados[$credito['id']] = true;
            }

            $f['DEPOSITO'] = self::resolverDeposito($f, $cuenta, $plazo, $habiles, $alicuotas,
                isset($marcas[$id]) ? $marcas[$id] : null, $hoy, $credito);

            if (!$f['DEPOSITO']['listar']) {
                continue;
            }

            if ($f['DEPOSITO']['estado'] === self::DEPOSITO_SIN_DATOS) {
                $sinDatos[$f['DEPOSITO']['error']][] = $f;
            }

            foreach ($f['DEPOSITO']['faltan'] as $mes) {
                $faltan[$mes] = true;
            }

            if ($cuenta === null) {
                $sinCuenta[$boleta] = true;
            }

            $listadas[] = self::ubicarEnEje($f);
        }

        $avisos = [];

        foreach ($sinDatos as $motivo => $grupo) {
            $avisos[] = ['nivel' => 'danger', 'texto' => count($grupo)
                . ' cheque(s) depositado(s) en Tango por $ '
                . number_format(array_sum(array_column($grupo, 'IMPORTE')), 2, ',', '.')
                . ' brutos no se proyectan en "Echeqs depositados": ' . $motivo];
        }

        foreach (DiasHabiles::avisosCalendario(array_keys($faltan)) as $t) {
            $avisos[] = ['nivel' => 'warning', 'texto' => 'Echeqs depositados: ' . $t];
        }

        if (!empty($sinCuenta)) {
            $avisos[] = ['nivel' => 'warning', 'texto' => 'No se encontró en Tango la cuenta que '
                . 'debita la boleta de depósito ' . implode(', ', array_keys($sinCuenta))
                . ': sus cheques se cuentan como depositados en un banco, sin poder verificarlo.'];
        }

        return ['filas' => $listadas, 'avisos' => $avisos];
    }

    /**
     * Donde cae una fila de la grilla en el eje, y con que importe.
     *
     *   De cartera              donde lo dejo resolverFechaCartera() -su dia
     *                           habil-, por el bruto. Es la misma regla del
     *                           proveedor.
     *   Depositada, PROYECTA    la fecha de ACREDITACION, por el NETO.
     *   Acreditada o sin datos  en ninguna columna. Se ve en la grilla con su
     *                           marca.
     *
     * ACREDITA es donde cae el importe: en uno de cartera, su dia habil -la
     * fecha del cheque, o la corrida si cae un fin de semana o un feriado-; en
     * un depositado, su acreditacion. Retencion y Neto van vacias -null- en
     * las de cartera: no son cero, no hay deposito. En una sin neto, van
     * vacias tambien.
     *
     * UNA FILA DE CARTERA SIN RESOLVER NO CAE EN NINGUNA COLUMNA: no hay una
     * fecha de respaldo. Toda lectura de cartera la resuelve antes.
     *
     * @param array $fila Con IMPORTE, y DEPOSITO (resuelto o null) o CARTERA
     *        (la de resolverFechaCartera())
     * @return array La fila con ESTADO_DEPOSITO, ESTADO_CARTERA, FECHA_EJE,
     *         IMPORTE_EJE, ACREDITA, RETENCION y NETO
     */
    public static function ubicarEnEje($fila) {
        $d = isset($fila['DEPOSITO']) ? $fila['DEPOSITO'] : null;
        $c = (!$d && isset($fila['CARTERA'])) ? $fila['CARTERA'] : null;
        $estado = $d ? $d['estado'] : null;

        $fila['ESTADO_DEPOSITO'] = $estado;
        $fila['ESTADO_CARTERA'] = $c ? $c['estado'] : null;
        $fila['ACREDITA'] = $d ? $d['fecha_acreditacion'] : ($c ? $c['fecha_eje'] : null);
        $fila['NETO'] = ($d && $d['neto'] !== null) ? $d['neto'] : null;
        $fila['RETENCION'] = ($fila['NETO'] !== null) ? round($d['bruto'] - $d['neto'], 4) : null;

        if ($estado === self::DEPOSITO_PROYECTA) {
            $fila['FECHA_EJE'] = $d['fecha_acreditacion'];
            $fila['IMPORTE_EJE'] = $d['neto'];
        } elseif ($c && $c['fecha_eje'] !== null) {
            $fila['FECHA_EJE'] = $c['fecha_eje'];
            $fila['IMPORTE_EJE'] = floatval($fila['IMPORTE']);
        } else {
            $fila['FECHA_EJE'] = null;
            $fila['IMPORTE_EJE'] = 0.0;
        }

        return $fila;
    }

    /**
     * La observacion de una marca "ya acredito": opcional. Vacia es null, no
     * un texto en blanco que despues se muestre como si dijera algo.
     *
     * @param mixed $texto
     * @return string|null
     */
    public static function validarObservacion($texto) {
        $t = ($texto === null) ? '' : trim((string) $texto);

        return ($t === '') ? null : mb_substr($t, 0, self::OBSERVACION_MAX);
    }

    /**
     * Cuanto hay depositado en lo que se muestra, para las tarjetas, el pie y
     * los avisos de la pestana. Cada cheque en una sola parte:
     *
     *   los que se proyectan   con bruto, retencion y neto, y sus totales por
     *                          vista: es la fila "Echeqs depositados".
     *   los acreditados        aparte: por plazo o marcados. No suman.
     *   los sin datos          aparte: les falta la fecha o el neto. No suman.
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
            'acreditados' => ['cheques' => 0, 'bruto' => 0, 'neto' => 0],
            'sin_datos' => ['cheques' => 0, 'bruto' => 0]
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
                $r['acreditados']['neto'] += floatval($f['DEPOSITO']['neto']);
            } elseif ($estado === self::DEPOSITO_SIN_DATOS) {
                $r['sin_datos']['cheques']++;
                $r['sin_datos']['bruto'] += $f['DEPOSITO']['bruto'];
            }
        }

        $r['retencion'] = round($r['bruto'] - $r['neto'], 4);

        return $r;
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
     * Si ya se corrio sql/cashflow_echeqs_deposito.sql: la tabla de alicuotas.
     *
     * MIRA SOLO LAS ALICUOTAS, y no RO_T_CASHFLOW_ECHEQ_DEPOSITO, que es la
     * tabla del circuito viejo: el script ya no la crea, asi que una
     * instalacion nueva no la tiene y no le hace falta.
     *
     * SIN ELLA LA PESTANA NO SE ROMPE: la cartera se lee como siempre, los
     * depositados se listan sin neto -no se proyectan- con un aviso critico
     * que nombra el script, y Parametros -> Cheques avisa. Mismo criterio que
     * excluirCreada().
     *
     * @return bool
     */
    public function alicuotasCreadas() {
        if ($this->tablaAlicuota !== null) {
            return $this->tablaAlicuota;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_ALICUOTA . "', 'U') AS A");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de alícuotas de los cheques'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaAlicuota = ($row && $row['A'] !== null);

        return $this->tablaAlicuota;
    }

    /**
     * Si ya se corrio sql/cashflow_echeqs_acreditado.sql.
     *
     * SIN ELLA LA PESTANA NO SE ROMPE: ningun depositado esta marcado, se
     * proyectan hasta que vence su plazo, el boton "Ya acredito" no se dibuja
     * y la pestana avisa que script falta. Mismo criterio que excluirCreada().
     *
     * @return bool
     */
    public function acreditadoCreado() {
        if ($this->tablaAcreditado !== null) {
            return $this->tablaAcreditado;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_ACREDITADO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de la marca "ya acreditó"'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaAcreditado = ($row && $row['T'] !== null);

        return $this->tablaAcreditado;
    }

    /**
     * Si ya se corrio sql/cashflow_echeqs_vencidos.sql: la tabla de las fechas
     * asignadas.
     *
     * SIN ELLA LA PESTANA NO SE ROMPE: los vencidos no se leen -la ventana
     * tampoco existe-, la pestana avisa que script falta, y la cartera de hoy
     * en adelante, el corrimiento y los depositados andan igual. Mismo
     * criterio que excluirCreada().
     *
     * @return bool
     */
    public function fechaVencidosCreada() {
        if ($this->tablaFechaVencidos !== null) {
            return $this->tablaFechaVencidos;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_FECHA_ACREDITACION . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de fechas de los vencidos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaFechaVencidos = ($row && $row['T'] !== null);

        return $this->tablaFechaVencidos;
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

       Alimenta las filas "Echeqs en cartera" -los cheques en cartera, de
       dbo.SBA14 con ESTADO = 'C'- y "Echeqs depositados" -los que Tesoreria
       deposito en Tango, que ya no estan en cartera-. Son dos lecturas
       distintas porque son dos universos distintos: un cheque esta en uno o en
       el otro, nunca en los dos.
       ==================================================================== */

    /**
     * La consulta de la cartera, fila por fila. Estatica y pura: arma el texto
     * y no lo corre, para poder verificarla sin base.
     *
     * DESDE UNA FECHA QUE CALCULA PHP, y no desde GETDATE(): un cheque del
     * sabado leido el lunes todavia cae hoy -ver primerDiaCartera()-, y el
     * motor no sabe de dias habiles. Por eso la fecha viaja como parametro.
     *
     * No hace falta ESTADO NOT IN ('X','R'): es redundante con ESTADO = 'C'.
     *
     * EL LEFT JOIN DE LA EXCLUSION ES LA EXCEPCION A "LOS CRUCES SE HACEN EN
     * PHP": es una marca nuestra SOBRE dbo.SBA14, una por cheque, y va en la
     * misma lectura para que la pestana y el tablero repartan el mismo
     * universo. El de dbo.BANCO es el nombre del banco emisor. Se une con
     * VIGENTE = 1 en la condicion de la union: una exclusion dada de baja no
     * trae nada.
     *
     * Sin el script de la exclusion, la consulta es la de antes. Las columnas
     * viajan igual -en null- para que la pantalla y el proveedor no pregunten
     * si el script se corrio antes de leer una fila.
     *
     * CON $soloAnteriores, SOLO LA FECHA ANTERIOR A HOY (un parametro mas): es
     * la parte del universo que el tablero lee fila por fila, porque los
     * vencidos tienen cada uno su fecha asignada. Ver getEcheqsCarteraTotales().
     *
     * @param bool $excl Si existe la tabla de exclusiones
     * @param bool $soloAnteriores
     * @return string
     */
    public static function sqlCartera($excl, $soloAnteriores = false) {
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
                       " . ($excl ? 'x.FECHA_ALTA' : 'CAST(NULL AS DATETIME)') . " AS EXCLUSION_FECHA
                FROM dbo.SBA14 AS s
                LEFT JOIN dbo.BANCO AS b ON s.ID_BANCO = b.ID_BANCO
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                WHERE " . self::sqlUniversoCartera()
                    . ($soloAnteriores ? "\n                  AND s.FECHA_CHEQ < ?" : '') . "
                ORDER BY s.FECHA_CHEQ";
    }

    /**
     * El WHERE del universo de cartera, el mismo para la lectura fila por fila,
     * la agregada y el resumen de excluidos: si dijeran cosas distintas, la
     * pestana y el tablero mirarian dos carteras.
     *
     * Dos parametros: la fecha desde (contextoCartera(): el primer dia que
     * todavia cae hoy, o hoy - N para los vencidos) y el estado. Lo que entra
     * lo decide despues resolverFechaCartera(), cheque por cheque o fecha por
     * fecha: la consulta solo no trae lo que seguro queda afuera.
     *
     * LA CENTINELA 1800-01-01 SE DEJA AFUERA EXPLICITAMENTE: ver la trampa 3
     * del encabezado.
     *
     * UN CHEQUE DEPOSITADO NO ESTA ACA: Tango lo pasa a ESTADO = 'A' en el
     * momento del deposito. Lo trae getDepositadosTango().
     *
     * @return string
     */
    private static function sqlUniversoCartera() {
        return "s.FECHA_CHEQ >= ?
                  AND s.FECHA_CHEQ >= '" . self::FECHA_REAL_DESDE . "'
                  AND s.ESTADO = ?";
    }

    /** Los parametros de sqlUniversoCartera() */
    private static function paramsUniversoCartera($ctx) {
        return [$ctx['desde'], self::ESTADO_CARTERA];
    }

    /**
     * Lo que necesita la regla de la cartera, leido una vez por pedido: hoy,
     * el fin del eje, el calendario, la ventana de los vencidos y desde que
     * fecha se lee.
     *
     * LA VENTANA SOLO SI EL SCRIPT DE LOS VENCIDOS ESTA CORRIDO. Sin la tabla
     * de fechas no hay vencidos: no se leen -la ventana tampoco existe- y la
     * cartera es la de hoy en adelante, con el corrimiento. Con la tabla y la
     * ventana rota, tampoco: 'ventana' trae el motivo y avisosVentana() lo
     * dice como critico.
     *
     * EL CALENDARIO, POR LA UNICA LECTURA DEL MODULO (diasHabiles() ->
     * Ventas::getDiasHabiles()), desde lo mas viejo que se lee -la ventana, o
     * un tramo de corrimiento para saber cual fue el ultimo habil- hasta un
     * tramo despues del fin del eje -donde se corre lo ultimo que se corre-.
     *
     * @param Horizonte|null $h El eje del pedido. Sin eje, el de Parametros
     * @return array ['hoy', 'tope', 'habiles', 'desde', 'tabla', 'ventana' => ['dias', 'error']]
     */
    private function contextoCartera($h = null) {
        $h = ($h === null) ? Horizonte::desdeParametros(new Parametros()) : $h;
        $hoy = $h->hoy();
        $tope = $h->fin();

        if ($this->ctxCartera !== null && $this->ctxCartera['hoy'] === $hoy
            && $this->ctxCartera['tope'] === $tope) {
            return $this->ctxCartera;
        }

        $tabla = $this->fechaVencidosCreada();
        $ventana = $tabla ? $this->getVentana() : ['dias' => null, 'error' => null];
        $atras = max(DiasHabiles::MAX_CORRIMIENTO, intval($ventana['dias']));

        $habiles = $this->diasHabiles(self::sumarDias($hoy, -$atras),
            self::sumarDias($tope, DiasHabiles::MAX_CORRIMIENTO));

        $desde = self::primerDiaCartera($hoy, $habiles);

        if ($ventana['dias'] !== null) {
            $desde = min($desde, self::sumarDias($hoy, -$ventana['dias']));
        }

        $this->ctxCartera = [
            'hoy' => $hoy,
            'tope' => $tope,
            'habiles' => $habiles,
            'desde' => $desde,
            'tabla' => $tabla,
            'ventana' => ['dias' => $ventana['dias'], 'error' => $ventana['error']]
        ];

        return $this->ctxCartera;
    }

    /**
     * Los avisos de la configuracion de los vencidos, que no dependen de
     * ningun cheque:
     *
     *   sin el script   ATENCION, y SOLO EN LA PESTANA: los vencidos no se
     *                   leen y todo lo demas anda como siempre; el tablero no
     *                   tiene nada que decir.
     *   ventana rota    CRITICO, en la pestana y en el tablero: el script esta
     *   o faltante      corrido y los vencidos no se leen por un dato roto.
     *
     * @param Horizonte|null $h
     * @param bool $paraTablero
     * @return array [['nivel', 'texto']]
     */
    public function avisosVentana($h = null, $paraTablero = false) {
        $ctx = $this->contextoCartera($h);

        if (!$ctx['tabla']) {
            return $paraTablero ? [] : [['nivel' => 'warning', 'texto' => 'Los cheques vencidos en '
                . 'cartera no se leen: falta correr ' . self::SCRIPT_VENCIDOS . ' contra la base '
                . 'central. Lo demás de la pestaña funciona igual.']];
        }

        return ($ctx['ventana']['error'] !== null)
            ? [['nivel' => 'danger', 'texto' => $ctx['ventana']['error']]] : [];
    }

    /**
     * Para la pantalla: si se pueden asignar fechas, hasta cuando se muestra
     * un vencido, y los extremos del selector de fecha. El servidor valida
     * igual: esto solo explica.
     *
     * @param Horizonte|null $h
     * @return array ['creada', 'ventana', 'hoy', 'manana', 'fin']
     */
    public function datosVencidos($h = null) {
        $ctx = $this->contextoCartera($h);

        return [
            'creada' => $ctx['tabla'],
            'ventana' => $ctx['ventana']['dias'],
            'hoy' => $ctx['hoy'],
            'manana' => self::sumarDias($ctx['hoy'], 1),
            'fin' => $ctx['tope']
        ];
    }

    /**
     * Cheques de terceros en cartera, uno por fila, con su exclusion y donde
     * cae cada uno.
     *
     * CADA FILA SE RESUELVE CON resolverFechaCartera() -CARTERA- y se ubica
     * con ubicarEnEje(), que deja FECHA_EJE e IMPORTE_EJE: la misma regla del
     * proveedor. Lo que queda fuera de la ventana no se lista. Los depositados
     * no estan aca: ver getDepositadosTango().
     *
     * LOS VENCIDOS TRAEN SU FECHA ASIGNADA VIGENTE -FECHA_VENCIDO- Y LAS
     * ANTERIORES -HISTORIAL_FECHA-, para la marca y su tooltip. La fecha se
     * cruza en PHP, por la regla del repo.
     *
     * LOS EXCLUIDOS VIENEN EN LA LISTA, MARCADOS. No se filtran aca: quien los
     * esconde es la pantalla, con un interruptor que se puede apagar. Lo mismo
     * los vencidos sin fecha.
     *
     * @param Horizonte|null $h El eje del pedido: da hoy y el fin del corrimiento
     * @return array Filas normalizadas
     */
    public function getEcheqsCartera($h = null) {
        return $this->leerCartera($this->contextoCartera($h), false);
    }

    /**
     * La lectura fila por fila: toda la cartera, o solo lo anterior a hoy.
     *
     * @param array $ctx El de contextoCartera()
     * @param bool $soloAnteriores
     * @return array Filas resueltas y ubicadas, sin las de fuera de la ventana
     */
    private function leerCartera($ctx, $soloAnteriores) {
        $cid = $this->conectar('central');
        $params = self::paramsUniversoCartera($ctx);

        if ($soloAnteriores) {
            $params[] = $ctx['hoy'];
        }

        $stmt = sqlsrv_query($cid, self::sqlCartera($this->excluirCreada(), $soloAnteriores), $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los echeqs en cartera'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fila = $this->filaCheque($row);
            $fila['DEPOSITO'] = null;
            $filas[] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        // Las fechas asignadas, solo de los que pueden ser vencidos: los de
        // fecha anterior a hoy. Son pocos.
        $anteriores = [];

        foreach ($filas as $f) {
            if ($f['FECHA_CHEQUE'] < $ctx['hoy']) {
                $anteriores[] = $f['ID_SBA14'];
            }
        }

        $fechas = $this->getFechasAsignadas($anteriores);
        $v = [];

        foreach ($filas as $fila) {
            $id = $fila['ID_SBA14'];
            $vig = isset($fechas['vigentes'][$id]) ? $fechas['vigentes'][$id] : null;

            $fila['CARTERA'] = self::resolverFechaCartera($fila['FECHA_CHEQUE'], $ctx['hoy'],
                $ctx['habiles'], $ctx['tope'], $vig ? $vig['fecha'] : null, $ctx['ventana']['dias']);

            if ($fila['CARTERA']['estado'] === self::CARTERA_FUERA_VENTANA) {
                continue;
            }

            $fila['FECHA_VENCIDO'] = $vig;
            $fila['HISTORIAL_FECHA'] = isset($fechas['historial'][$id]) ? $fechas['historial'][$id] : [];

            $v[] = self::ubicarEnEje($fila);
        }

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
     * ABRE POR EXCLUIDO adentro del GROUP BY: el proveedor reparte el mismo
     * universo en cobrable y excluido, y las dos series salen de UNA lectura.
     * Los depositados van aparte, fila por fila: ver getDepositadosTango().
     *
     * EL CORRIMIENTO SE APLICA DESPUES DEL GROUP BY, fecha por fecha: depende
     * solo de la fecha, asi que todos los cheques de un grupo se corren al
     * mismo dia. Es resolverFechaCartera(), la misma regla de la grilla.
     *
     * LA LECTURA VA PARTIDA EN DOS, sobre el MISMO universo:
     *
     *   de hoy en adelante  agregada, por fecha y excluido. Son casi todos, y
     *                       ninguno puede tener una fecha asignada.
     *   antes de hoy        fila por fila (leerCartera()): los que todavia
     *                       caen hoy por el corrimiento y los vencidos, cada
     *                       uno con su fecha asignada. Son pocos.
     *
     * Las dos pasan por resolverFechaCartera(), la regla de la grilla, y la
     * prueba con datos reales verifica que juntas suman lo mismo que
     * getEcheqsCartera().
     *
     * @param Horizonte|null $h El eje del pedido
     * @return array Filas ['FECHA_PAGO', 'EXCLUIDO' => bool, 'IMPORTE', 'CHEQUES', 'CARTERA']
     */
    public function getEcheqsCarteraTotales($h = null) {
        $ctx = $this->contextoCartera($h);
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid, self::sqlCarteraTotales($this->excluirCreada()),
            array_merge(self::paramsUniversoCartera($ctx), [$ctx['hoy']]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el total de echeqs en cartera'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fecha = Horizonte::normalizarFecha($row['FECHA_PAGO']);
            $c = self::resolverFechaCartera($fecha, $ctx['hoy'], $ctx['habiles'], $ctx['tope']);

            if ($c['estado'] === self::CARTERA_FUERA_VENTANA) {
                continue;
            }

            $v[] = [
                'FECHA_PAGO' => $fecha,
                'EXCLUIDO' => (intval($row['EXCLUIDO']) === 1),
                'IMPORTE' => floatval($row['IMPORTE']),
                'CHEQUES' => intval($row['CHEQUES']),
                'CARTERA' => $c
            ];
        }

        sqlsrv_free_stmt($stmt);

        foreach ($this->leerCartera($ctx, true) as $f) {
            $v[] = [
                'FECHA_PAGO' => $f['FECHA_PAGO'],
                'EXCLUIDO' => !empty($f['EXCLUIDO']),
                'IMPORTE' => floatval($f['IMPORTE']),
                'CHEQUES' => 1,
                'CARTERA' => $f['CARTERA']
            ];
        }

        return $v;
    }

    /**
     * La consulta agregada de la cartera. Estatica y pura, como sqlCartera().
     *
     * SIN EL SCRIPT DE LA EXCLUSION, EL FLAG VA EN EL SELECT PERO NO EN EL
     * GROUP BY. SQL Server rechaza agrupar por una constante -"Each GROUP BY
     * expression must contain at least one column that is not an outer
     * reference"-, asi que meterlo en los dos lados sin preguntar haria fallar
     * la pestana entera justo en la instalacion que todavia no corrio el
     * script, que es el caso que este modulo se compromete a no romper.
     *
     * SOLO DE HOY EN ADELANTE (un parametro mas, hoy): lo anterior va fila por
     * fila. Ver getEcheqsCarteraTotales().
     *
     * @param bool $excl
     * @return string
     */
    public static function sqlCarteraTotales($excl) {
        $flag = $excl ? 'CASE WHEN x.ID IS NULL THEN 0 ELSE 1 END' : '0';
        $grupo = 'CAST(s.FECHA_CHEQ AS DATE)' . ($excl ? ', ' . $flag : '');

        return "SELECT CAST(s.FECHA_CHEQ AS DATE)       AS FECHA_PAGO,
                       " . $flag . "                    AS EXCLUIDO,
                       SUM(CAST(s.IMPORTE_CH AS FLOAT)) AS IMPORTE,
                       COUNT(*)                         AS CHEQUES
                FROM dbo.SBA14 AS s
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                WHERE " . self::sqlUniversoCartera() . "
                  AND s.FECHA_CHEQ >= ?
                GROUP BY " . $grupo . "
                ORDER BY CAST(s.FECHA_CHEQ AS DATE)";
    }

    /* ====================================================================
       LOS DEPOSITADOS, LEIDOS DE TANGO

       Tres lecturas chicas, cruzadas en PHP por la regla del repo: los
       cheques de dbo.SBA14, la linea al debe de cada boleta en dbo.SBA05 con
       su cuenta de dbo.SBA01, y las marcas "ya acredito". Despues, la regla
       pura -resolverDepositos()- decide que es un deposito, donde cae y por
       cuanto. Del 16/10/2026: 225 cheques en 60 dias.
       ==================================================================== */

    /**
     * La consulta de los cheques depositados. Estatica y pura.
     *
     * APLICA EL MISMO FILTRO QUE esDepositoTango(), menos la cuenta -que esta
     * en la boleta y se lee aparte-, como optimizacion: la regla que decide
     * es la de PHP.
     *
     * SOLO LO NECESARIO: las boletas desde rangoDepositos()['desde']. Esa
     * fecha deja afuera tambien la centinela 1800-01-01. El LEFT JOIN de
     * dbo.BANCO es el de sqlCartera(): el nombre del banco emisor.
     *
     * @return string Con tres parametros: estado, comprobante y fecha desde
     */
    public static function sqlDepositados() {
        return "SELECT s.ID_SBA14,
                       CAST(s.N_CHEQUE AS BIGINT)   AS N_CHEQUE,
                       CAST(s.FECHA_CHEQ AS DATE)   AS FECHA_CHEQUE,
                       b.ID_BANCO                   AS ID_BANCO,
                       b.DESC_BANCO                 AS BANCO,
                       CAST(s.IMPORTE_CH AS FLOAT)  AS IMPORTE,
                       s.RAZON_EMIS                 AS CLIENTE,
                       s.CLIENTE                    AS COD_CLIENTE,
                       CAST(s.FECHA_CHEQ AS DATE)   AS FECHA_PAGO,
                       s.ESTADO,
                       s.T_COMP_SAL,
                       s.N_COMP_SAL,
                       s.TIPO_SAL,
                       CAST(s.CTA_DESTIN AS BIGINT) AS CTA_DESTIN,
                       CAST(s.FECHA_SAL AS DATE)    AS FECHA_SAL
                FROM dbo.SBA14 AS s
                LEFT JOIN dbo.BANCO AS b ON s.ID_BANCO = b.ID_BANCO
                WHERE s.ESTADO = ?
                  AND s.T_COMP_SAL = ?
                  AND s.FECHA_SAL >= ?
                ORDER BY s.FECHA_SAL, s.ID_SBA14";
    }

    /**
     * Los cheques depositados en Tango que hoy hay que mostrar, resueltos
     * con la regla de resolverDepositos(), y sus avisos. ES LA UNICA LECTURA
     * DE LOS DEPOSITADOS: la usan la pestana y el proveedor, asi que los dos
     * suman lo mismo.
     *
     * NO DEPENDE DE NINGUN SCRIPT PARA LEER. Sin el de las alicuotas, los
     * depositados no tienen neto y lo avisan como critico; sin el de la marca
     * "ya acredito" (acreditadoCreado()), se proyectan hasta que vence el plazo.
     *
     * NO LEE RO_T_CASHFLOW_ECHEQ_DEPOSITO: es la tabla del circuito viejo, en
     * desuso. Ver README-echeqs.md.
     *
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array ['filas' => [...], 'avisos' => [['nivel', 'texto']]]
     */
    public function getDepositadosTango($hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;
        $plazo = $this->getPlazo();
        $rango = self::rangoDepositos($hoy, $plazo['dias']);

        $filas = $this->leerDepositados($rango['desde']);

        if (empty($filas)) {
            return ['filas' => [], 'avisos' => []];
        }

        $boletas = array_values(array_unique(array_column($filas, 'N_COMP_SAL')));
        $marcas = $this->getMarcasAcreditado(array_column($filas, 'ID_SBA14'));

        $extracto = $this->extractoCreado();

        $r = self::resolverDepositos(
            $filas,
            $this->leerCuentasBoletas($boletas),
            $plazo,
            ($plazo['dias'] === null) ? [] : $this->diasHabiles($rango['desde'], $rango['hasta']),
            $this->getAlicuotas(true),
            $marcas['vigentes'],
            $hoy,
            $extracto ? $this->leerCreditosExtracto(array_column($filas, 'N_CHEQUE')) : []
        );

        // Sin el extracto no se rompe nada: los depositados van por plazo o
        // con la marca manual, como antes de leerlo.
        if (!$extracto) {
            $r['avisos'][] = ['nivel' => 'info', 'texto' => 'No se encontró la tabla '
                . self::TABLA_EXTRACTO . ': los cheques depositados se dan por acreditados por '
                . 'plazo o con la marca "ya acreditó", sin confirmarlo con el banco.'];
        }

        // Las marcas deshechas, para el tooltip: quien la puso y quien la
        // saco. No deciden nada.
        foreach ($r['filas'] as $i => $f) {
            $r['filas'][$i]['HISTORIAL_ACREDITADO'] = isset($marcas['historial'][$f['ID_SBA14']])
                ? $marcas['historial'][$f['ID_SBA14']] : [];
        }

        return $r;
    }

    /** Los cheques de sqlDepositados(), normalizados */
    private function leerDepositados($desde) {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid, self::sqlDepositados(),
            [self::ESTADO_APLICADO, self::COMPROBANTE_DEPOSITO, $desde]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los echeqs depositados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fila = $this->filaCheque($row);

            // La exclusion es de cartera: un cheque excluido que despues se
            // deposita se proyecta igual, porque el deposito dice que la
            // plata entra. La exclusion queda en su historial, sin efecto.
            $fila['EXCLUIDO'] = false;
            $fila['MOTIVO_EXCLUSION'] = null;
            $fila['EXCLUSION_USUARIO'] = null;
            $fila['EXCLUSION_FECHA'] = null;

            $fila['ESTADO'] = trim((string) $row['ESTADO']);
            $fila['T_COMP_SAL'] = trim((string) $row['T_COMP_SAL']);
            $fila['N_COMP_SAL'] = trim((string) $row['N_COMP_SAL']);
            $fila['TIPO_SAL'] = trim((string) $row['TIPO_SAL']);
            $fila['CTA_DESTIN'] = ($row['CTA_DESTIN'] === null) ? null : intval($row['CTA_DESTIN']);
            $fila['FECHA_SAL'] = Horizonte::normalizarFecha($row['FECHA_SAL']);

            $v[] = $fila;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * La cuenta que debita cada boleta de deposito: la linea al debe de
     * dbo.SBA05, con su codigo, descripcion y tipo de dbo.SBA01.
     *
     * ES LA CUENTA REAL, Y NO CTA_DESTIN. Los cheques con TIPO_SAL = 'O' traen
     * CTA_DESTIN en 0, pero su boleta debita una cuenta igual que la de los
     * 'D'. La descripcion es la que muestra el tooltip, y el tipo decide si es
     * un banco (esDepositoTango()).
     *
     * DOS CONSULTAS CRUZADAS EN PHP, por la regla del repo. Una boleta tiene
     * una sola linea al debe -verificado el 16/10/2026-; si alguna trajera
     * dos, vale la primera en el orden del renglon.
     *
     * @param array $boletas N_COMP_SAL
     * @return array Mapa N_COMP_SAL => ['cod_cta', 'descripcion', 'tipo']
     */
    private function leerCuentasBoletas($boletas) {
        $boletas = array_values(array_filter($boletas, function ($b) { return $b !== ''; }));
        $lineas = [];

        if (empty($boletas)) {
            return [];
        }

        $cid = $this->conectar('central');

        foreach (array_chunk($boletas, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT N_COMP, CAST(COD_CTA AS BIGINT) AS COD_CTA, ID_SBA01
                 FROM dbo.SBA05
                 WHERE COD_COMP = ? AND D_H = 'D'
                   AND N_COMP IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")
                 ORDER BY N_COMP, RENGLON",
                array_merge([self::COMPROBANTE_DEPOSITO], $tanda));

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer las boletas de depósito'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $n = trim((string) $row['N_COMP']);

                if (!isset($lineas[$n])) {
                    $lineas[$n] = ['cod_cta' => intval($row['COD_CTA']),
                                   'id_sba01' => intval($row['ID_SBA01'])];
                }
            }

            sqlsrv_free_stmt($stmt);
        }

        if (empty($lineas)) {
            return [];
        }

        $ids = array_values(array_unique(array_column($lineas, 'id_sba01')));
        $stmt = sqlsrv_query($cid,
            "SELECT ID_SBA01, DESCRIPCIO, TIPO FROM dbo.SBA01
             WHERE ID_SBA01 IN (" . implode(', ', array_fill(0, count($ids), '?')) . ")",
            $ids);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cuentas de tesorería'));
        }

        $cuentas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cuentas[intval($row['ID_SBA01'])] = ['descripcion' => trim((string) $row['DESCRIPCIO']),
                                                  'tipo' => trim((string) $row['TIPO'])];
        }

        sqlsrv_free_stmt($stmt);

        $v = [];

        foreach ($lineas as $n => $l) {
            $c = isset($cuentas[$l['id_sba01']]) ? $cuentas[$l['id_sba01']] : null;

            // Una linea cuya cuenta no esta en dbo.SBA01 no dice a donde fue la
            // plata: es lo mismo que no tener la linea.
            if ($c !== null) {
                $v[$n] = ['cod_cta' => $l['cod_cta'], 'descripcion' => $c['descripcion'],
                          'tipo' => $c['tipo']];
            }
        }

        return $v;
    }

    /* ====================================================================
       LA MARCA "YA ACREDITO"

       Cuando Tesoreria ve que un cheque depositado ya acredito antes de su
       plazo, lo marca y sale de "Echeqs depositados": el saldo de
       Interbanking ya lo tiene, y contarlo en la fila tambien lo contaria dos
       veces. Se puede deshacer. Vive en RO_T_CASHFLOW_ECHEQ_ACREDITADO, con
       ORIGEN = MANUAL. LA REGLA NO MIRA EL ORIGEN: ver resolverDeposito().

       ES PARA LO QUE EL EXTRACTO NO CONFIRMA. Un cheque con su credito en el
       extracto de Interbanking ya sale solo -ver creditoExtracto()-, sin
       marca y sin proceso: hoy cruzan los de Galicia. Los de Frances,
       Santander o ICBC van por plazo o con esta marca.

       Del navegador llegan solo los ids de los cheques, y la seleccion se
       vuelve a validar contra la lectura de la base.
       ==================================================================== */

    /**
     * Si existe la tabla del extracto de Interbanking. No es nuestra -la carga
     * BI-, asi que puede faltar en una base de prueba: sin ella, los
     * depositados van por plazo y la pestana lo avisa.
     *
     * @return bool
     */
    public function extractoCreado() {
        if ($this->tablaExtracto !== null) {
            return $this->tablaExtracto;
        }

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_EXTRACTO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el extracto de Interbanking'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablaExtracto = ($row && $row['T'] !== null);

        return $this->tablaExtracto;
    }

    /**
     * Los creditos del extracto de Interbanking cuyo comprobante es el numero
     * de alguno de estos cheques. El cruce -numero, importe, fecha- lo hace
     * creditoExtracto(), en PHP.
     *
     * EL LEFT JOIN DE dbo.BANCO es solo el nombre del banco para el tooltip,
     * la misma excepcion que la cartera. Se pide TIPO_MOV = 'C' aca para no
     * traer los debitos de la retencion, que llevan el mismo numero.
     *
     * @param array $numeros N_CHEQUE
     * @return array
     */
    private function leerCreditosExtracto($numeros) {
        $numeros = array_values(array_unique(array_filter(array_map('intval', $numeros),
            function ($n) { return $n > 0; })));
        $v = [];

        if (empty($numeros)) {
            return $v;
        }

        $cid = $this->conectar('central');

        foreach (array_chunk($numeros, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT A.ID, A.FECHA_OPERACION, A.NRO_BANCO, B.DESC_BANCO, A.NRO_CUENTA,
                        A.NRO_COMPROBANTE, CAST(A.IMPORTE AS FLOAT) AS IMPORTE, A.DESC_OPERACION,
                        A.TIPO_MOV
                 FROM dbo." . self::TABLA_EXTRACTO . " AS A
                 LEFT JOIN dbo.BANCO AS B ON A.NRO_BANCO = B.NRO_BANCO
                 WHERE A.TIPO_MOV = 'C'
                   AND A.NRO_COMPROBANTE IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")",
                $tanda);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer el extracto de Interbanking'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $v[] = [
                    'id' => intval($row['ID']),
                    'fecha' => Horizonte::normalizarFecha($row['FECHA_OPERACION']),
                    'nro_banco' => trim((string) $row['NRO_BANCO']),
                    'banco' => trim((string) $row['DESC_BANCO']),
                    'nro_cuenta' => trim((string) $row['NRO_CUENTA']),
                    'comprobante' => intval($row['NRO_COMPROBANTE']),
                    'importe' => floatval($row['IMPORTE']),
                    'descripcion' => trim((string) $row['DESC_OPERACION']),
                    'tipo_mov' => trim((string) $row['TIPO_MOV'])
                ];
            }

            sqlsrv_free_stmt($stmt);
        }

        return $v;
    }

    /**
     * Las marcas de una lista de cheques: la vigente de cada uno, y todas
     * -vigentes y deshechas- para el tooltip.
     *
     * Sin el script devuelve vacio y no lanza: la pregunta es "que cheques
     * estan marcados", y la respuesta honesta es "ninguno".
     *
     * @param array $ids
     * @return array ['vigentes' => mapa ID_SBA14 => marca,
     *         'historial' => mapa ID_SBA14 => lista de marcas, de la mas nueva a la mas vieja]
     */
    public function getMarcasAcreditado($ids) {
        $ids = self::idsPedidos($ids);
        $r = ['vigentes' => [], 'historial' => []];

        if (empty($ids) || !$this->acreditadoCreado()) {
            return $r;
        }

        $cid = $this->conectar('central');

        foreach (array_chunk($ids, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT ID, ID_SBA14, FECHA_ACREDITACION, ORIGEN, REFERENCIA, OBSERVACION,
                        VIGENTE, ID_REEMPLAZA, USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
                 FROM dbo." . self::TABLA_ACREDITADO . "
                 WHERE ID_SBA14 IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")
                 ORDER BY ID DESC",
                $tanda);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer las marcas "ya acreditó"'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $id = intval($row['ID_SBA14']);
                $m = [
                    'id' => intval($row['ID']),
                    'fecha_acreditacion' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION']),
                    'origen' => trim((string) $row['ORIGEN']),
                    'referencia' => $row['REFERENCIA'],
                    'observacion' => $row['OBSERVACION'],
                    'vigente' => intval($row['VIGENTE']),
                    'id_reemplaza' => ($row['ID_REEMPLAZA'] === null) ? null : intval($row['ID_REEMPLAZA']),
                    'usuario' => $row['USUARIO_ALTA'],
                    'fecha_alta' => $this->fechaHora($row['FECHA_ALTA']),
                    'usuario_baja' => $row['USUARIO_BAJA'],
                    'fecha_baja' => $this->fechaHora($row['FECHA_BAJA'])
                ];

                $r['historial'][$id][] = $m;

                if ($m['vigente'] === 1) {
                    $r['vigentes'][$id] = $m;
                }
            }

            sqlsrv_free_stmt($stmt);
        }

        return $r;
    }

    /**
     * Que cheques de un pedido se pueden marcar "ya acredito". ES TODO O NADA,
     * como la exclusion: con uno solo que no cumple, no se marca ninguno.
     *
     * SOLO LOS DEPOSITADOS QUE SE PROYECTAN. Uno en cartera no esta en el
     * banco; uno marcado ya esta marcado; uno que acredita hoy ya no suma; uno
     * sin datos no suma; y uno que no esta en la lectura -endosado, rechazado o
     * con el plazo vencido- no tiene nada que sacar. Se valida contra la
     * lectura de la base, no contra lo que manda el navegador: un id no es una
     * autorizacion.
     *
     * @param array $ids
     * @param array $filas Las de la grilla: cartera y depositados, resueltos
     * @return array ['ids' => [...], 'rechazos' => [['id', 'n_cheque', 'motivo']],
     *         'error' => string|null]
     */
    public static function validarSeleccionAcreditado($ids, $filas) {
        $porId = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $porId[intval($f['ID_SBA14'])] = $f;
        }

        $validos = [];
        $rechazos = [];

        foreach (self::idsPedidos($ids) as $id) {
            $f = isset($porId[$id]) ? $porId[$id] : null;
            $d = ($f && !empty($f['DEPOSITO'])) ? $f['DEPOSITO'] : null;
            $motivo = null;

            if ($f === null) {
                $motivo = 'no está entre los depositados que se proyectan: puede que ya haya vencido '
                    . 'su plazo, o que en Tango no sea un depósito';
            } elseif ($d === null) {
                $motivo = 'está en cartera: todavía no se depositó en Tango';
            } elseif ($d['estado'] === self::DEPOSITO_ACREDITADO
                && $d['motivo'] === self::ACREDITADO_POR_EXTRACTO) {
                $motivo = 'ya acreditó según el extracto de Interbanking';
            } elseif ($d['estado'] === self::DEPOSITO_ACREDITADO
                && $d['motivo'] === self::ACREDITADO_POR_MARCA) {
                $motivo = 'ya está marcado como acreditado';
            } elseif ($d['estado'] === self::DEPOSITO_ACREDITADO) {
                $motivo = 'acredita hoy: ya no suma a ninguna fila';
            } elseif ($d['estado'] !== self::DEPOSITO_PROYECTA) {
                $motivo = 'no se proyecta: le falta la fecha de acreditación o el neto';
            }

            if ($motivo !== null) {
                $rechazos[] = ['id' => $id, 'n_cheque' => $f ? $f['N_CHEQUE'] : null,
                               'motivo' => $motivo];
                continue;
            }

            $validos[] = $id;
        }

        $error = null;

        if (empty($validos) && empty($rechazos)) {
            $error = 'No llegó ningún cheque para marcar.';
        } elseif (!empty($rechazos)) {
            $error = 'No se marcó ningún cheque: ' . count($rechazos) . ' de los '
                . (count($rechazos) + count($validos)) . ' no se pueden marcar como acreditados. '
                . implode('; ', array_map(function ($r) {
                    return 'N° ' . ($r['n_cheque'] === null ? $r['id'] : $r['n_cheque'])
                        . ' ' . $r['motivo'];
                }, $rechazos)) . '.';
        }

        return ['ids' => empty($rechazos) ? $validos : [], 'rechazos' => $rechazos, 'error' => $error];
    }

    /**
     * Que marcas de un pedido se pueden deshacer. TODO O NADA: o todos los
     * cheques tienen una marca vigente, o no se deshace ninguna.
     *
     * NO IMPORTA EL ORIGEN: una marca con ORIGEN = INTERBANKING se deshace
     * igual que una manual, y un proceso no la podria volver a grabar porque
     * su REFERENCIA queda en la tabla, dada de baja (ver
     * sql/cashflow_echeqs_acreditado.sql). El credito del extracto no es una
     * marca: no se deshace aca.
     *
     * @param array $ids
     * @param array $vigentes Mapa ID_SBA14 => marca vigente
     * @return array ['ids' => [...], 'error' => string|null]
     */
    public static function validarDeshacerAcreditado($ids, $vigentes) {
        $pedidos = self::idsPedidos($ids);

        if (empty($pedidos)) {
            return ['ids' => [], 'error' => 'No llegó ningún cheque para deshacer.'];
        }

        $sin = array_values(array_filter($pedidos, function ($id) use ($vigentes) {
            return !isset($vigentes[$id]);
        }));

        if (!empty($sin)) {
            return ['ids' => [], 'error' => 'No se deshizo ninguna marca: ' . count($sin)
                . ' de los ' . count($pedidos) . ' cheque(s) no están marcados como acreditados. '
                . 'Puede que la pantalla haya quedado vieja: actualizala y volvé a intentar.'];
        }

        return ['ids' => $pedidos, 'error' => null];
    }

    /**
     * Los parametros del INSERT de marcas manuales, 5 por cheque, en el orden
     * de las columnas de marcarAcreditados().
     *
     * LA FECHA DE ACREDITACION ES HOY, el dia en que se marca: es cuando
     * Tesoreria vio la plata en el banco. El ORIGEN es MANUAL y no viaja del
     * navegador: las marcas de Interbanking las graba su proceso.
     *
     * Estatica y pura.
     *
     * @param array $ids
     * @param string $hoy 'Y-m-d'
     * @param string|null $obs Ya validada
     * @param string $usuario
     * @return array
     */
    public static function paramsInsertAcreditado($ids, $hoy, $obs, $usuario) {
        $params = [];

        foreach ($ids as $id) {
            array_push($params, intval($id), $hoy, $obs, $usuario, $usuario);
        }

        return $params;
    }

    /**
     * Marca cheques depositados como "ya acredito". TODO O NADA, EN UNA
     * TRANSACCION.
     *
     * Se valida TODO antes de abrir la transaccion, contra la lectura de hoy:
     * la cartera y los depositados, resueltos con la misma regla que la
     * pantalla. DOS PESTANAS AL MISMO TIEMPO: el indice unico de marcas
     * vigentes hace fallar la segunda, y la transaccion entera vuelve atras.
     *
     * @param array $ids Ids de dbo.SBA14. Es lo unico que llega del navegador
     * @param string|null $observacion Opcional, la misma para todos
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array ['marcados' => int]
     */
    public function marcarAcreditados($ids, $observacion, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;

        if (!$this->acreditadoCreado()) {
            throw new Exception('Todavía no se puede marcar un cheque como acreditado. Corré '
                . self::SCRIPT_ACREDITADO . ' contra la base central.');
        }

        $dep = $this->getDepositadosTango($hoy);
        $v = self::validarSeleccionAcreditado($ids, array_merge($this->getEcheqsCartera(), $dep['filas']));

        if ($v['error'] !== null) {
            throw new Exception($v['error']);
        }

        $obs = self::validarObservacion($observacion);
        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción de la marca'));
        }

        try {
            /* 5 parametros por fila: una tanda de 300 son 1500, debajo del
               tope de 2100. Todas dentro de la MISMA transaccion. */
            foreach (array_chunk($v['ids'], 300) as $tanda) {
                $stmt = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA_ACREDITADO . "
                         (ID_SBA14, FECHA_ACREDITACION, ORIGEN, OBSERVACION, VIGENTE,
                          USUARIO_ALTA, USUARIO_MODIF)
                     VALUES " . implode(', ', array_fill(0, count($tanda),
                        "(?, ?, '" . self::ORIGEN_MANUAL . "', ?, 1, ?, ?)")),
                    self::paramsInsertAcreditado($tanda, $hoy, $obs, $usuario));

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al guardar la marca. Si otro usuario '
                        . 'marcó alguno de estos cheques recién, actualizá la pantalla'));
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la marca'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['marcados' => count($v['ids'])];
    }

    /**
     * Deshace marcas "ya acredito" vigentes: BAJA LOGICA, con quien y cuando.
     *
     * El cheque vuelve a proyectarse si su acreditacion todavia es de manana
     * en adelante; si no, sigue sin sumar, por plazo. La fila dada de baja
     * queda, y es la que el tooltip muestra como historial.
     *
     * TODO O NADA, en una transaccion.
     *
     * @param array $ids
     * @param string $usuario
     * @return array ['deshechos' => int]
     */
    public function deshacerAcreditados($ids, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->acreditadoCreado()) {
            throw new Exception('No hay marcas que deshacer: corré ' . self::SCRIPT_ACREDITADO
                . ' contra la base central.');
        }

        $v = self::validarDeshacerAcreditado($ids, $this->getMarcasAcreditado($ids)['vigentes']);

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
                    "UPDATE dbo." . self::TABLA_ACREDITADO . "
                     SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                     WHERE VIGENTE = 1 AND ID_SBA14 IN ("
                        . implode(', ', array_fill(0, count($tanda), '?')) . ")",
                    array_merge([$usuario, $usuario], $tanda));

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al deshacer la marca'));
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

    /* ====================================================================
       LA FECHA DE ACREDITACION INTERNA DE UN VENCIDO

       Solo vive en el cashflow: no se escribe nada en Tango. Del navegador
       llegan los ids, la fecha y una observacion; todo se vuelve a validar
       contra la lectura de la base, todo o nada. Sin bajas fisicas:
       reasignar da de baja la vigente e inserta la nueva con ID_REEMPLAZA, y
       deshacer marca VIGENTE = 0.
       ==================================================================== */

    /** La tabla de las fechas, con el esquema. Protected: las pruebas la cambian por una #temporal */
    protected function tablaFechas() {
        return 'dbo.' . self::TABLA_FECHA_ACREDITACION;
    }

    /** La conexion de las fechas. Protected por el mismo motivo */
    protected function conexionFechas() {
        return $this->conectar('central');
    }

    /**
     * Las fechas asignadas de una lista de cheques: la vigente de cada uno, y
     * todas -vigentes y dadas de baja- para el tooltip.
     *
     * Sin el script devuelve vacio y no lanza: la pregunta es "que cheques
     * tienen fecha", y la respuesta honesta es "ninguno".
     *
     * @param array $ids
     * @return array ['vigentes' => mapa ID_SBA14 => fecha,
     *         'historial' => mapa ID_SBA14 => lista, de la mas nueva a la mas vieja]
     */
    public function getFechasAsignadas($ids) {
        $ids = self::idsPedidos($ids);
        $r = ['vigentes' => [], 'historial' => []];

        if (empty($ids) || !$this->fechaVencidosCreada()) {
            return $r;
        }

        $cid = $this->conexionFechas();

        foreach (array_chunk($ids, 500) as $tanda) {
            $stmt = sqlsrv_query($cid,
                "SELECT ID, ID_SBA14, FECHA_ACREDITACION, OBSERVACION, VIGENTE, ID_REEMPLAZA,
                        USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
                 FROM " . $this->tablaFechas() . "
                 WHERE ID_SBA14 IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")
                 ORDER BY ID DESC",
                $tanda);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer las fechas de acreditación de los vencidos'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $id = intval($row['ID_SBA14']);
                $f = [
                    'id' => intval($row['ID']),
                    'fecha' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION']),
                    'observacion' => $row['OBSERVACION'],
                    'vigente' => intval($row['VIGENTE']),
                    'id_reemplaza' => ($row['ID_REEMPLAZA'] === null) ? null : intval($row['ID_REEMPLAZA']),
                    'usuario' => $row['USUARIO_ALTA'],
                    'fecha_alta' => $this->fechaHora($row['FECHA_ALTA']),
                    'usuario_baja' => $row['USUARIO_BAJA'],
                    'fecha_baja' => $this->fechaHora($row['FECHA_BAJA'])
                ];

                $r['historial'][$id][] = $f;

                if ($f['vigente'] === 1) {
                    $r['vigentes'][$id] = $f;
                }
            }

            sqlsrv_free_stmt($stmt);
        }

        return $r;
    }

    /**
     * Asigna una fecha de acreditacion interna a uno o varios vencidos. TODO O
     * NADA, EN UNA TRANSACCION.
     *
     * Se valida TODO antes de abrir la transaccion, contra la lectura de hoy
     * -la cartera y los depositados, resueltos con la misma regla que la
     * pantalla-: que todos sean vencidos en cartera
     * (validarSeleccionVencidos()) y que la fecha sirva para cada uno
     * (validarFechaParaSeleccion(): de manana en adelante, habil, y hasta el
     * menor entre el fin del eje y su fecha + N).
     *
     * REASIGNAR ES DAR DE BAJA LA VIGENTE E INSERTAR LA NUEVA, con
     * ID_REEMPLAZA. DOS PESTANAS AL MISMO TIEMPO: el indice unico de fechas
     * vigentes hace fallar la segunda, y la transaccion entera vuelve atras.
     *
     * @param array $ids Ids de dbo.SBA14
     * @param mixed $fecha 'Y-m-d'
     * @param string|null $observacion Opcional, la misma para todos
     * @param string $usuario
     * @param Horizonte|null $h El eje: da hoy y el fin del horizonte
     * @return array ['asignados', 'reemplazados', 'fecha', 'avisos']
     */
    public function asignarFechaVencidos($ids, $fecha, $observacion, $usuario, $h = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->fechaVencidosCreada()) {
            throw new Exception('Todavía no se pueden asignar fechas a los cheques vencidos. Corré '
                . self::SCRIPT_VENCIDOS . ' contra la base central.');
        }

        $ctx = $this->contextoCartera($h);

        if ($ctx['ventana']['dias'] === null) {
            throw new Exception($ctx['ventana']['error']);
        }

        $dep = $this->getDepositadosTango($ctx['hoy']);
        $v = self::validarSeleccionVencidos($ids, array_merge($this->getEcheqsCartera($h), $dep['filas']));

        if ($v['error'] !== null) {
            throw new Exception($v['error']);
        }

        $vf = self::validarFechaParaSeleccion($fecha, $v['filas'], $ctx['hoy'], $ctx['tope'],
            $ctx['ventana']['dias'], $ctx['habiles']);

        if ($vf['error'] !== null) {
            throw new Exception($vf['error']);
        }

        $elegidos = array_column($v['filas'], 'ID_SBA14');
        $reemplaza = [];

        foreach ($this->getFechasAsignadas($elegidos)['vigentes'] as $id => $vig) {
            $reemplaza[$id] = $vig['id'];
        }

        $this->escribirFechas($elegidos, $vf['fecha'], self::validarObservacion($observacion),
            $reemplaza, $usuario);

        return [
            'asignados' => count($elegidos),
            'reemplazados' => count($reemplaza),
            'fecha' => $vf['fecha'],
            'avisos' => DiasHabiles::avisosCalendario($vf['faltan'])
        ];
    }

    /**
     * La escritura de asignarFechaVencidos(), ya validada: da de baja las
     * vigentes de esos cheques e inserta las nuevas, en una transaccion.
     *
     * @param array $ids
     * @param string $fecha
     * @param string|null $obs
     * @param array $reemplaza Mapa ID_SBA14 => ID de la vigente
     * @param string $usuario Ya resuelto por usuarioDeEscritura()
     */
    protected function escribirFechas($ids, $fecha, $obs, $reemplaza, $usuario) {
        $cid = $this->conexionFechas();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción de la fecha'));
        }

        try {
            /* 6 parametros por fila: una tanda de 300 son 1800, debajo del
               tope de 2100. Todas dentro de la MISMA transaccion. */
            foreach (array_chunk($ids, 300) as $tanda) {
                $this->bajaFechas($cid, $tanda, $usuario);

                $stmt = sqlsrv_query($cid,
                    "INSERT INTO " . $this->tablaFechas() . "
                         (ID_SBA14, FECHA_ACREDITACION, OBSERVACION, ID_REEMPLAZA, VIGENTE,
                          USUARIO_ALTA, USUARIO_MODIF)
                     VALUES " . implode(', ', array_fill(0, count($tanda), "(?, ?, ?, ?, 1, ?, ?)")),
                    self::paramsInsertFecha($tanda, $fecha, $obs, $reemplaza, $usuario));

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al guardar la fecha. Si otro usuario '
                        . 'le asignó una fecha a alguno de estos cheques recién, actualizá la pantalla'));
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la fecha'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }
    }

    /**
     * Deshace la fecha asignada de uno o varios vencidos: BAJA LOGICA, con
     * quien y cuando. El cheque vuelve a "vencido sin fecha" y deja de sumar.
     * TODO O NADA: todos tienen que tener una fecha vigente.
     *
     * NO EXIGE QUE EL CHEQUE SIGA VENCIDO: tambien es como se limpia una fecha
     * que quedo sin efecto. Lo que se valida es que la fecha exista.
     *
     * @param array $ids
     * @param string $usuario
     * @return array ['deshechos' => int]
     */
    public function deshacerFechaVencidos($ids, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->fechaVencidosCreada()) {
            throw new Exception('No hay fechas que deshacer: corré ' . self::SCRIPT_VENCIDOS
                . ' contra la base central.');
        }

        $v = self::validarDeshacerFecha($ids, $this->getFechasAsignadas($ids)['vigentes']);

        if ($v['error'] !== null) {
            throw new Exception($v['error']);
        }

        $cid = $this->conexionFechas();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach (array_chunk($v['ids'], 500) as $tanda) {
                $this->bajaFechas($cid, $tanda, $usuario);
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
     * Da de baja las fechas vigentes de una tanda: VIGENTE = 0 con quien y
     * cuando. No borra: la fila dada de baja es el historial del tooltip.
     *
     * @param resource $cid Con la transaccion abierta
     * @param array $ids
     * @param string $usuario
     */
    private function bajaFechas($cid, $ids, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE " . $this->tablaFechas() . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE VIGENTE = 1 AND ID_SBA14 IN (" . implode(', ', array_fill(0, count($ids), '?')) . ")",
            array_merge([$usuario, $usuario], array_map('intval', $ids)));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la fecha anterior'));
        }

        sqlsrv_free_stmt($stmt);
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
     * es otra parte del mismo corte -la tercera es DEPOSITADOS-. Las partes
     * cierran contra A_COBRAR_TODO y el proveedor avisa cuánto es y con qué
     * motivos. Ver
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
     * cheque esté en cartera: uno cuyo depósito ya registró Tango, o uno
     * rechazado, ya salió de la serie por su estado, y guardar una exclusión para él sería una decisión que no
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

        /* UN CHEQUE DEPOSITADO NO ESTA ACA: Tango lo saco de cartera al
           depositarlo, asi que un id suyo vuelve en 'rechazados'. La
           exclusion es de cartera; un deposito dice que la plata entra. */
        foreach ($this->getEcheqsCartera() as $fila) {
            $permitidos[intval($fila['ID_SBA14'])] = true;
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
     * Va desde la tabla de exclusiones y no desde la cartera porque el universo
     * de cartera son cientos de filas y las exclusiones son un punado: traer
     * las primeras para filtrar las segundas es al reves.
     *
     * EL MISMO UNIVERSO QUE LA CARTERA: sqlUniversoCartera(), con la fecha
     * desde de contextoCartera(). Y LA MISMA REGLA: cada cheque se resuelve
     * con resolverFechaCartera() y cuenta solo si suma en una serie. Un
     * vencido excluido SIN fecha no esta en A_COBRAR_EXCLUIDOS -no esta en
     * ninguna serie-, asi que no cuenta: con otra regla el aviso diria un
     * importe y la serie otro.
     *
     * @param Horizonte|null $h El eje del pedido
     * @return array ['cheques' => int, 'importe' => float, 'motivos' => [...]]
     */
    public function getExclusionesEnCartera($h = null) {
        $vacio = ['cheques' => 0, 'importe' => 0.0, 'motivos' => []];

        if (!$this->excluirCreada()) {
            return $vacio;
        }

        $ctx = $this->contextoCartera($h);
        $cid = $this->conectar('central');

        $sql = "SELECT s.ID_SBA14,
                       x.MOTIVO,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_CHEQUE,
                       CAST(s.IMPORTE_CH AS FLOAT) AS IMPORTE
                FROM dbo." . self::TABLA_EXCLUIDO . " AS x
                INNER JOIN dbo.SBA14 AS s ON s.ID_SBA14 = x.ID_SBA14
                WHERE x.VIGENTE = 1
                  AND " . self::sqlUniversoCartera();

        $stmt = sqlsrv_query($cid, $sql, self::paramsUniversoCartera($ctx));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al resumir las exclusiones'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'ID_SBA14' => intval($row['ID_SBA14']),
                'MOTIVO' => trim((string) $row['MOTIVO']),
                'FECHA_CHEQUE' => Horizonte::normalizarFecha($row['FECHA_CHEQUE']),
                'IMPORTE' => floatval($row['IMPORTE'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        $fechas = $this->getFechasAsignadas(array_column($filas, 'ID_SBA14'));
        $r = $vacio;
        $porMotivo = [];

        foreach ($filas as $f) {
            $vig = isset($fechas['vigentes'][$f['ID_SBA14']]) ? $fechas['vigentes'][$f['ID_SBA14']] : null;
            $c = self::resolverFechaCartera($f['FECHA_CHEQUE'], $ctx['hoy'], $ctx['habiles'], $ctx['tope'],
                $vig ? $vig['fecha'] : null, $ctx['ventana']['dias']);

            if (!in_array($c['estado'], self::CARTERA_SUMAN, true)) {
                continue;
            }

            $r['cheques']++;
            $r['importe'] += $f['IMPORTE'];

            if ($f['MOTIVO'] !== '') {
                $porMotivo[$f['MOTIVO']] = (isset($porMotivo[$f['MOTIVO']]) ? $porMotivo[$f['MOTIVO']] : 0)
                    + $f['IMPORTE'];
            }
        }

        // Ordenados por importe, que es como el aviso tiene que nombrarlos:
        // los tres primeros son los tres que mas plata explican.
        arsort($porMotivo);
        $r['motivos'] = array_keys($porMotivo);

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
       LA FILA DEL TABLERO Y EL CALENDARIO
       ==================================================================== */

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
     * El calendario bancario, por la unica lectura de RO_T_CALENDARIO del
     * modulo: Ventas::getDiasHabiles(). Protected: las pruebas inyectan uno
     * armado a mano.
     */
    protected function diasHabiles($desde, $hasta) {
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
     * MUEVE TAMBIEN LOS QUE TODAVIA NO ACREDITARON: la fecha de acreditacion
     * no se guarda, se calcula al leer desde la boleta de Tango. Es la
     * decision de Tesoreria: el plazo es el del banco hoy.
     *
     * Tiene su propio camino y no el saveParametro() generico porque ese guarda
     * cualquier texto, y un plazo de cero o de "dos" dejaria a los depositados
     * sin proyectar. saveParametro() rechaza esta clave por el mismo motivo.
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
     * La ventana de los vencidos: el valor que sirve, o null con el motivo, y
     * quien la toco por ultima vez. Se lee directo, como getPlazo().
     *
     * @return array ['dias' => int|null, 'error' => string|null, 'valor' => string|null,
     *                'usuario' => string|null, 'fecha' => string|null]
     */
    public function getVentana() {
        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "SELECT VALOR, USUARIO_MODIF, FECHA_MODIF
             FROM dbo.RO_T_CASHFLOW_PARAMETROS
             WHERE CLAVE = ?",
            [self::CLAVE_VENTANA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los días de los cheques vencidos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $r = self::ventanaDesdeValor($row ? $row['VALOR'] : null);

        $r['valor'] = $row ? trim((string) $row['VALOR']) : null;
        $r['usuario'] = $row ? $row['USUARIO_MODIF'] : null;
        $r['fecha'] = $row ? $this->fechaHora($row['FECHA_MODIF']) : null;

        return $r;
    }

    /**
     * Guarda la ventana de los vencidos.
     *
     * Tiene su propio camino y no el saveParametro() generico por lo mismo que
     * guardarPlazo(): ese guarda cualquier texto, y una ventana de cero o de
     * "treinta" dejaria de leer los vencidos. saveParametro() rechaza la clave.
     *
     * ACHICARLA SACA VENCIDOS DE LA LECTURA, sin aviso: un cheque fuera de la
     * ventana sale solo, y su fecha asignada deja de aplicar y queda en el
     * historial.
     *
     * @param mixed $dias
     * @param string $usuario
     * @return int La ventana guardada
     */
    public function guardarVentana($dias, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $dias = self::validarVentana($dias);

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo.RO_T_CASHFLOW_PARAMETROS
             SET VALOR = ?, " . Auditoria::SET_MODIF . "
             WHERE CLAVE = ?",
            [(string) $dias, $usuario, self::CLAVE_VENTANA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar los días de los cheques vencidos'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas < 1) {
            throw new Exception('Falta el parámetro ' . self::CLAVE_VENTANA . ': corré '
                . self::SCRIPT_VENCIDOS . ' contra la base central.');
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
        if (!$this->alicuotasCreadas()) {
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
     * Carga una vigencia nueva de una alicuota.
     *
     * UNA ALICUOTA NO SE EDITA: se inserta una vigencia nueva con su
     * VIGENCIA_DESDE. La anterior queda, y es lo que explica la tasa de un
     * deposito de ese periodo. Las validaciones son las de Cob. Electronicos
     * -Retenciones::validarAlicuota()-, sobre el estado resultante.
     *
     * NO RECALCULA NADA: ningun neto de un cheque depositado esta guardado. Se
     * calcula al leer, con las alicuotas vigentes a su acreditacion, asi que
     * los pendientes toman la vigencia nueva en la proxima lectura y los que
     * ya acreditaron no se leen mas.
     *
     * @param string $concepto
     * @param mixed $alicuota Fraccion: 2,5% es 0,025
     * @param string $vigenciaDesde 'Y-m-d'
     * @param string $usuario
     * @return array ['id', 'tasa_total']
     */
    public function addAlicuota($concepto, $alicuota, $vigenciaDesde, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->alicuotasCreadas()) {
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
            'tasa_total' => $tasaTotal
        ];
    }

    /**
     * Baja LOGICA de una vigencia de alicuota.
     *
     * SE PUEDE DAR DE BAJA LA ULTIMA, a diferencia de Cob. Electronicos. Alla
     * el invariante es "una procesadora ACTIVA tiene alicuotas", y aca no hay
     * procesadora que activar. Sin alicuotas vigentes a su acreditacion, un
     * cheque depositado queda con el neto en null: no se proyecta, y la
     * pestana y el tablero lo avisan como critico. Un neto igual al bruto
     * informaria de mas sin decirlo.
     *
     * @param int $id
     * @param string $usuario
     */
    public function bajaAlicuota($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->alicuotasCreadas()) {
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
    }

    /**
     * Lo que dibuja la seccion "Deposito de cheques" de Parametros -> Cheques.
     *
     * LA TASA DE HOY LA CALCULA EL SERVIDOR, con la misma regla que el deposito:
     * si la pantalla la resolviera por su cuenta, podria mostrar una tasa que el
     * deposito no usa.
     *
     * @param string|null $hoy 'Y-m-d'. Por defecto, hoy
     * @return array ['tablas_creadas', 'plazo', 'plazo_max', 'vencidos_creada', 'ventana',
     *         'ventana_max', 'alicuotas', 'tasa_hoy', 'hoy', 'avisos']
     */
    public function datosParametrosDeposito($hoy = null) {
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;

        if (!$this->alicuotasCreadas()) {
            return [
                'tablas_creadas' => false,
                'plazo' => ['dias' => null, 'error' => null, 'valor' => null,
                            'usuario' => null, 'fecha' => null],
                'plazo_max' => self::PLAZO_MAX,
                'alicuotas' => [],
                'tasa_hoy' => self::tasaParaPantalla([], $hoy),
                'hoy' => $hoy,
                'avisos' => ['Todavía no existe la tabla de alícuotas de los cheques. Corré '
                    . self::SCRIPT_DEPOSITO . ' contra la base central. Mientras tanto los '
                    . 'cheques depositados en Tango no tienen neto y no se proyectan; la cartera '
                    . 'se ve y se proyecta como siempre.']
            ];
        }

        $alicuotas = $this->getAlicuotas(false);
        $plazo = $this->getPlazo();
        $tasa = self::tasaParaPantalla($alicuotas, $hoy);
        $vencidos = $this->fechaVencidosCreada();
        $ventana = $this->getVentana();
        $avisos = [];

        if ($plazo['error'] !== null) {
            $avisos[] = $plazo['error'];
        }

        // Sin el script de los vencidos, el motivo de la ventana ya nombra el
        // script: es un solo aviso y no dos.
        if ($ventana['error'] !== null) {
            $avisos[] = $ventana['error'];
        } elseif (!$vencidos) {
            $avisos[] = 'Falta la tabla ' . self::TABLA_FECHA_ACREDITACION . ': corré '
                . self::SCRIPT_VENCIDOS . ' contra la base central. Mientras tanto los cheques '
                . 'vencidos en cartera no se leen.';
        }

        if ($tasa['tasa'] === null) {
            $avisos[] = 'Los cheques no tienen ninguna alícuota de retención vigente hoy, así que '
                . 'los depositados no tienen neto y no se proyectan: un neto igual al bruto '
                . 'informaría de más. Cargalas abajo, en Depósito de cheques.';
        }

        return [
            'tablas_creadas' => true,
            'plazo' => $plazo,
            'plazo_max' => self::PLAZO_MAX,
            'vencidos_creada' => $vencidos,
            'ventana' => $ventana,
            'ventana_max' => self::VENTANA_MAX,
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
                  -- Venta cobrada anticipada: franquicias y locales. Cheques
                  -- en Cartera no filtra el cliente: ver el encabezado.
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

        /* EL BANCO SE COMPARA POR ID, no por el nombre: es como se agrupa el
           resto del modulo. Sale de dbo.BANCO y no de SBA14, asi que un cheque
           cuyo ID_BANCO no esta en el maestro queda en null, igual que uno sin
           banco: es un dato roto -el banco es obligatorio en Tango-. */
        if (array_key_exists('ID_BANCO', $row)) {
            $fila['ID_BANCO'] = ($row['ID_BANCO'] === null) ? null : intval($row['ID_BANCO']);
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
