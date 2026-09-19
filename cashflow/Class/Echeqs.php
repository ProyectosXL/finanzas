<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Parametros.php';

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

    /** @var bool|null Cache del chequeo de tablas creadas */
    private $tablas = null;

    /**
     * @var bool|null Cache del chequeo de la tabla de exclusiones.
     * VA APARTE de $tablas y no junto con las otras dos: la exclusion de cartera
     * y el maestro de pre-chequeado son dos scripts independientes, y quien
     * corrio uno solo tiene que perder unicamente esa funcion.
     */
    private $tablaExcluido = null;

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
                . 'Cargalos en Parámetros → Pre-chequeado: sin clientes, esa sub-pestaña se '
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
     * Cheques de terceros en cartera, uno por fila.
     *
     * FECHA_PAGO se selecciona por separado aunque hoy sea el mismo campo que
     * FECHA_CHEQUE. Es el campo por el que agrupa el eje temporal: si algun dia
     * aparece una regla de acreditacion -que el dinero se cobre N dias despues de
     * la fecha del cheque-, el cambio es solo aca y no en la pantalla ni en el
     * proveedor.
     *
     * LOS EXCLUIDOS VIENEN EN LA LISTA, MARCADOS. No se filtran aca: quien los
     * esconde es la pantalla, y con un interruptor que se puede apagar. Una
     * consulta que los sacara dejaria la exclusion sin ninguna pantalla donde
     * revisarla, que es exactamente lo contrario de lo que este circuito busca.
     *
     * @return array Filas normalizadas
     */
    public function getEcheqsCartera() {
        $cid = $this->conectar('central');

        /* >= CAST(GETDATE() AS DATE) y no >= GETDATE(): comparar contra fecha Y
           HORA deja fuera los cheques del dia a partir de las 00:00:01. Es el
           criterio del resto del modulo, ver Ingresos::getCobranzasFRTotales().

           No hace falta ESTADO NOT IN ('X','R'): es redundante con ESTADO = 'C'.

           Se seleccionan solo las columnas que la pantalla muestra. Un s.* infla
           la respuesta y expone sesenta columnas de Tango que nadie usa. */
        /* El JOIN de la exclusion se arma solo si la tabla existe: sin el script
           corrido, la consulta es exactamente la de antes. Las columnas viajan
           igual -en cero y en null- para que la pantalla y el proveedor no
           tengan que preguntar si el script se corrio antes de leer una fila. */
        $excl = $this->excluirCreada();

        $sql = "SELECT s.ID_SBA14,
                       CAST(s.N_CHEQUE AS BIGINT)  AS N_CHEQUE,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_CHEQUE,
                       b.DESC_BANCO                AS BANCO,
                       CAST(s.IMPORTE_CH AS FLOAT) AS IMPORTE,
                       s.RAZON_EMIS                AS CLIENTE,
                       s.CLIENTE                   AS COD_CLIENTE,
                       CAST(s.FECHA_CHEQ AS DATE)  AS FECHA_PAGO,
                       " . ($excl ? 'x.MOTIVO'     : 'CAST(NULL AS VARCHAR(200))')
                       . " AS MOTIVO_EXCLUSION,
                       " . ($excl ? 'x.USUARIO'    : 'CAST(NULL AS VARCHAR(50))')
                       . " AS EXCLUSION_USUARIO,
                       " . ($excl ? 'x.FECHA_ALTA' : 'CAST(NULL AS DATETIME)')
                       . " AS EXCLUSION_FECHA
                FROM dbo.SBA14 AS s
                LEFT JOIN dbo.BANCO AS b ON s.ID_BANCO = b.ID_BANCO
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
                  AND s.ESTADO = ?
                  AND s.CLIENTE LIKE '[FL]%'
                ORDER BY s.FECHA_CHEQ";

        $stmt = sqlsrv_query($cid, $sql, [self::ESTADO_CARTERA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los echeqs en cartera'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = $this->filaCheque($row);
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Los mismos cheques agregados por fecha de pago, para el tablero.
     *
     * POR QUE NO REUSA getEcheqsCartera()
     * El Cashflow no necesita banco, cliente ni numero de cheque: solo fecha e
     * importe. Un GROUP BY en el motor evita recorrer N filas en PHP para
     * descartar la mayor parte de cada una. Es el mismo criterio de
     * Ingresos::getCobranzasFRTotales(), y tests/test_echeqs.php verifica que los
     * dos metodos dan el mismo total para que no se puedan desincronizar en
     * silencio.
     *
     * ABRE POR EXCLUIDO, y por eso la clave de agrupacion son DOS campos y no
     * uno. El proveedor necesita repartir el mismo universo en tres series
     * -cobrable, excluido y todo-, y resolver eso con dos consultas distintas
     * seria dos WHERE que tienen que decir lo mismo para siempre. Con el corte
     * adentro del GROUP BY, el universo se lee UNA vez y las tres series salen
     * de la misma lectura, que es lo que hace que cierren por construccion.
     *
     * @return array Filas ['FECHA_PAGO' => 'Y-m-d', 'EXCLUIDO' => bool,
     *         'IMPORTE' => float]
     */
    public function getEcheqsCarteraTotales() {
        $cid = $this->conectar('central');

        /* Sin el script de la exclusion, EXCLUIDO sale siempre en 0 y el
           agrupado queda como el de antes: una fila por fecha.

           EL CERO VA EN EL SELECT PERO NO EN EL GROUP BY. SQL Server rechaza
           agrupar por una constante -"Each GROUP BY expression must contain at
           least one column that is not an outer reference"-, asi que meter el
           flag en los dos lados sin preguntar hacia fallar la pestana entera
           justo en la instalacion que todavia no corrio el script, que es el
           caso que este modulo se compromete a no romper. */
        $excl = $this->excluirCreada();
        $flag = $excl ? 'CASE WHEN x.ID IS NULL THEN 0 ELSE 1 END' : '0';

        $sql = "SELECT CAST(s.FECHA_CHEQ AS DATE)       AS FECHA_PAGO,
                       " . $flag . "                    AS EXCLUIDO,
                       SUM(CAST(s.IMPORTE_CH AS FLOAT)) AS IMPORTE
                FROM dbo.SBA14 AS s
                " . ($excl
                    ? 'LEFT JOIN dbo.' . self::TABLA_EXCLUIDO . ' AS x
                              ON x.ID_SBA14 = s.ID_SBA14 AND x.VIGENTE = 1'
                    : '') . "
                WHERE s.FECHA_CHEQ >= CAST(GETDATE() AS DATE)
                  AND s.ESTADO = ?
                  AND s.CLIENTE LIKE '[FL]%'
                GROUP BY CAST(s.FECHA_CHEQ AS DATE)"
                    . ($excl ? ', ' . $flag : '') . "
                ORDER BY CAST(s.FECHA_CHEQ AS DATE)";

        $stmt = sqlsrv_query($cid, $sql, [self::ESTADO_CARTERA]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el total de echeqs en cartera'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'FECHA_PAGO' => Horizonte::normalizarFecha($row['FECHA_PAGO']),
                'EXCLUIDO' => (intval($row['EXCLUIDO']) === 1),
                'IMPORTE' => floatval($row['IMPORTE'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
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
     * @param string|null $usuario
     * @return array ['excluidos' => bool, 'motivo', 'tocados' => int,
     *         'rechazados' => [...], 'filas' => [...]]
     */
    public function excluirCheques($ids, $excluir, $motivo = null, $usuario = null) {
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
                $this->bajaExclusiones($cid, $tanda);

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
     */
    private function bajaExclusiones($cid, $ids) {
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_EXCLUIDO . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE VIGENTE = 1 AND ID_SBA14 IN (" . $marcas . ")",
            array_map('intval', $ids));

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
     * @param string|null $usuario
     */
    private function insertarExclusiones($cid, $ids, $motivo, $usuario) {
        $filas = implode(', ', array_fill(0, count($ids), '(?, ?, 1, ?)'));
        $params = [];

        foreach ($ids as $id) {
            $params[] = intval($id);
            $params[] = $motivo;
            $params[] = $usuario;
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA_EXCLUIDO . " (ID_SBA14, MOTIVO, VIGENTE, USUARIO)
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

        $sql = "SELECT ID_SBA14, MOTIVO, USUARIO, FECHA_ALTA
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
            "SELECT ID, MOTIVO, VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA
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
                'FECHA_BAJA' => $this->fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
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
     * @param string|null $usuario
     * @return array ['tocados' => int, 'filas' => [...]] con el estado efectivo
     */
    public function marcarCheques($ids, $marcado, $usuario = null) {
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
     * @param string|null $usuario
     */
    private function mergeMarcas($cid, $ids, $valor, $usuario) {
        $filas = implode(', ', array_fill(0, count($ids), '(?)'));

        $sql = "MERGE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ AS T
                USING (VALUES $filas) AS S (ID_SBA14)
                    ON T.ID_SBA14 = S.ID_SBA14
                WHEN MATCHED THEN
                    UPDATE SET MARCADO = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHEN NOT MATCHED BY TARGET THEN
                    INSERT (ID_SBA14, MARCADO, FECHA_UPDATE, USUARIO)
                    VALUES (S.ID_SBA14, ?, GETDATE(), ?);";

        $params = array_merge($ids, [$valor, $usuario, $valor, $usuario]);

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
                       c.ACTIVO, c.FECHA_UPDATE, c.USUARIO,
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
     * @param string|null $usuario
     * @return int Los dias guardados
     */
    public function guardarDiasCliente($codigo, $dias, $usuario = null) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $codigo = self::normalizarCodigo($codigo);
        $dias = self::validarDias($dias);

        $cid = $this->conectar('central');

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
             SET DIAS_PRECHEQUEADO = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
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
     * @param string|null $usuario
     * @return array ['cliente', 'razon_social', 'dias_prechequeado', 'reactivado',
     *                'cheques_vivos']
     */
    public function guardarClientePrechequeado($codigo, $dias = null, $usuario = null) {
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
            // fila conserva su historia.
            $sql = "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                    SET ACTIVO = 1, RAZON_SOCIAL = ?, DIAS_PRECHEQUEADO = ?,
                        FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE CLIENTE = ?";
            $params = [$cliente['RAZON_SOCI'], $dias, $usuario, $codigo];
        } else {
            $sql = "INSERT INTO dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                        (CLIENTE, RAZON_SOCIAL, DIAS_PRECHEQUEADO, ACTIVO, FECHA_UPDATE, USUARIO)
                    VALUES (?, ?, ?, 1, GETDATE(), ?)";
            $params = [$codigo, $cliente['RAZON_SOCI'], $dias, $usuario];
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
     * @param string|null $usuario
     * @return bool
     */
    public function bajaClientePrechequeado($codigo, $usuario = null) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas de venta cobrada anticipada. '
                . 'Corré sql/echeqs_prechequeado.sql.');
        }

        $codigo = self::normalizarCodigo($codigo);
        $cid = $this->conectar('central');

        $sql = "UPDATE dbo.RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE
                SET ACTIVO = 0, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE CLIENTE = ?";

        $stmt = sqlsrv_query($cid, $sql, [$usuario, $codigo]);

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

        $sql = "SELECT ID_SBA14, MARCADO, FECHA_UPDATE, USUARIO
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
