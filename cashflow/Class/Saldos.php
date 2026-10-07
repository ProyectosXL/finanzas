<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/Fondos.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/DiasHabiles.php';

/**
 * Saldos
 * Disponible inicial de la empresa: efectivo de tesoreria de casa central,
 * saldos bancarios, Mercado Pago y la caja de los locales propios.
 *
 * LAS TRES PESTANAS SON TRES COSAS DISTINTAS
 * ------------------------------------------
 *   Pestana 1 "Saldos"        -> alimenta la fila DISPONIBLE (Saldo Inicial).
 *                                Carga PERIODICA (hoy, los lunes) y en parte
 *                                manual. El historico lo construye este modulo.
 *   Pestana 2 "Saldos Locales" -> alimenta la fila CAJA_LOCALES. Sale de una
 *                                consulta contra el servidor 'locales' que corre
 *                                todos los dias.
 *   Pestana 3 "Fondos"        -> las cuentas de inversion y comitente, con su
 *                                cuenta corriente. Alimentan el STOCK DE
 *                                COBERTURA del tablero y NO el Saldo Inicial.
 *                                Viven en Class/Fondos.php.
 *
 * Por eso las cargas llevan TIPO: son dos procesos con dos cadencias, y "la
 * ultima carga" tiene que poder responderse por separado para cada uno. Los
 * fondos no tienen cargas: tienen movimientos.
 *
 * UNA CUENTA TIENE TIPO Y CLASE, Y SON DOS PREGUNTAS
 * --------------------------------------------------
 * TIPO dice de donde sale el saldo (BANCO, MERCADO_PAGO, EFECTIVO_CENTRAL,
 * OTRO). CLASE dice que es la cuenta: CTA_CORRIENTE y CAJA_AHORRO son plata a
 * la vista y entran al disponible como foto; INVERSION y COMITENTE son fondos,
 * llevan cuenta corriente y son stock de cobertura. LOS FONDOS NO ENTRAN EN
 * DISPONIBILIDADES: si entraran a los dos lados, la misma plata se contaria
 * dos veces. getSaldosActuales() los deja afuera con Fondos::esFondo(), que es
 * la unica regla que decide de que lado va cada cuenta. El argumento de por
 * que CLASE es una columna y no un valor mas de TIPO esta en el encabezado de
 * sql/cashflow_saldos_cuentas_fondo.sql.
 *
 * UNA CARGA ES UN EVENTO FECHADO, NO UN UPDATE
 * --------------------------------------------
 * Los datos NO se pisan. Cada carga inserta un juego nuevo de filas y las
 * anteriores quedan. La pantalla muestra, para cada cuenta, el ultimo saldo
 * conocido CON SU PROPIA FECHA DE CARGA: es requisito del relevamiento poder
 * ver cual es la ultima actualizacion de cada dato, y una cuenta que no entro
 * en la ultima carga tiene que verse con su fecha vieja y no confundirse con
 * una que se acaba de actualizar.
 *
 * LOS DOLARES NO SE CONVIERTEN PARA MOSTRAR
 * -----------------------------------------
 * La pantalla cierra con un total en pesos y otro en dolares, cada uno en su
 * moneda. La conversion existe solo para lo que el proveedor le entrega al
 * Cashflow, porque el contrato de CashflowProvider exige pesos, y la hace
 * SaldosProvider con Class/Cotizacion.php.
 *
 * NO SE CALCULAN IMPUESTOS SOBRE EL DEPOSITO DE LOS LOCALES
 * ---------------------------------------------------------
 * El relevamiento menciona 0,6% de impuesto al debito y 4% de IIBB. Eso quedo
 * SIN EFECTO y no esta cableado ni "por las dudas": existia porque en el Excel
 * las cajas se actualizaban una vez por semana, asi que habia que estimar
 * cuanto se iba a depositar y descontarle los impuestos a mano. Aca el saldo se
 * lee todos los dias y el importe realmente acreditado, ya neto, aparece por si
 * solo en el saldo bancario de la pestana 1. Calcularlo de nuevo seria estimar
 * un dato que el sistema ya trae medido, y ademas lo contaria dos veces.
 *
 * EL SALDO DE CAJA DE UN LOCAL SE PUEDE TIPEAR CUANDO LA CONSULTA NO LO TRAJO
 * ---------------------------------------------------------------------------
 * La consulta de locales se alimenta todos los dias, pero puede fallar -un
 * error de conexion, un cierre que no viajo- y entonces el ultimo registro del
 * local queda viejo. Para eso el saldo se puede cargar a mano desde la pestana:
 * queda en RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL como un registro fechado, y la
 * regla de cual manda es UNA y esta en aplicarSaldosManuales(): gana el mas
 * nuevo por fecha entre la consulta y el manual, y a igual fecha gana el
 * manual. Cuando la consulta vuelve a traer un cierre mas nuevo, vuelve a
 * mandar sola. La foto guarda de donde salio cada saldo (ORIGEN_DATO).
 *
 * La pantalla resalta los locales cuyo saldo NO es el de ayer, que es el
 * cierre que tendria que haber llegado: es la senal de que hay que tipearlo.
 *
 * SI LAS TABLAS NO EXISTEN
 * ------------------------
 * Las lecturas devuelven vacio y getAvisos() dice que hay que correr
 * sql/cashflow_saldos.sql, en vez de romper. Mismo criterio que
 * CashflowEstructura. La tabla de saldos manuales tiene su propio chequeo: sin
 * ella la pestana funciona igual, solo que no deja tipear el saldo y avisa que
 * script correr.
 */
class Saldos {

    /** De donde salio el saldo de caja de un local */
    const SALDO_CONSULTA = 'CONSULTA';
    const SALDO_MANUAL = 'MANUAL';

    /** Tipos de carga. Separan las dos pestanas dentro de la misma cabecera. */
    const CARGA_SALDOS = 'SALDOS';
    const CARGA_LOCALES = 'LOCALES';

    /** Gestion del efectivo de una sucursal */
    const DEPOSITA = 'DEPOSITA';
    const ENVIA = 'ENVIA';

    /**
     * Los dias de acreditacion posibles, ISO como date('N'): de lunes a
     * viernes, que es lo que permite el CHECK de la columna. Un banco no
     * acredita en fin de semana, asi que un sabado no es un dia que se pueda
     * elegir: seria siempre un lunes disfrazado.
     */
    const DIAS_ACREDITACION = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
                               5 => 'viernes'];

    /** Como se abrevia el dia en la celda. Incluye el fin de semana: es de la fecha final */
    const DIAS_ABREV = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie',
                        6 => 'Sáb', 7 => 'Dom'];

    /** Cuenta contable de SBA05 con el efectivo de tesoreria de casa central */
    const PARAM_CTA_TESORERIA = 'saldos_cta_tesoreria';

    /** Dias desde la ultima carga a partir de los cuales se avisa */
    const PARAM_DIAS_ALERTA = 'saldos_dias_alerta_carga';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de las tablas */
    private $tablas = null;

    /** @var bool|null Cache del chequeo de la tabla de saldos manuales */
    private $manuales = null;

    /** @var bool|null Cache del chequeo de las columnas del dia de acreditacion */
    private $acreditacion = null;

    /** @var Fondos|null Puerta al modulo de fondos; la resuelve fondos() */
    private $fondos = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * El modulo de fondos, para lo que este necesita de el: si su script se
     * corrio -que decide si CLASE y el saldo inicial existen como columnas- y
     * las reglas de clase. Una sola instancia, para que el chequeo del DDL se
     * haga una vez por pedido.
     *
     * @return Fondos
     */
    private function fondos() {
        if ($this->fondos === null) {
            $this->fondos = new Fondos();
        }

        return $this->fondos;
    }

    /**
     * Si ya se corrio sql/cashflow_saldos_cuentas_fondo.sql.
     *
     * Va aparte de tablasCreadas() por el mismo motivo que manualesCreados():
     * sin ese script las dos pestanas de siempre funcionan igual, solo que no
     * hay fondos y las cuentas no tienen clase. Meterlo en tablasCreadas()
     * dejaria la pestana entera en blanco por una migracion pendiente.
     *
     * @return bool
     */
    public function fondosCreados() {
        return $this->tablasCreadas() && $this->fondos()->creado();
    }

    /* ====================================================================
       HELPERS PUROS

       Estan separados de la lectura SQL a proposito, al estilo de
       Ventas::armarTendencias(): lo delicado de este modulo no es la consulta
       sino los criterios -que sucursal aporta, que pasa con un neto negativo,
       en que columna cae un saldo-, y asi se pueden verificar sin base.
       ==================================================================== */

    /**
     * La ultima carga de una lista de cabeceras.
     *
     * EL DESEMPATE POR ID NO ES OPCIONAL. La carga es semanal, pero nada impide
     * dos el mismo dia -y con FECHA_CARGA truncada a fecha, dos del mismo dia
     * empatan-. Sin desempate, "la ultima carga" devolveria cualquiera de las
     * dos segun el orden en que la base entregue las filas, y la pantalla
     * mostraria la correccion o la version corregida al azar. El ID es un
     * IDENTITY, asi que el mayor es siempre el insertado despues.
     *
     * @param array $cargas Filas con ID y FECHA_CARGA
     * @return array|null La ultima, o null si no hay ninguna
     */
    public static function ultimaCarga($cargas) {
        if (!is_array($cargas) || empty($cargas)) {
            return null;
        }

        $ultima = null;

        foreach ($cargas as $c) {
            if ($ultima === null || self::posteriorA($c, $ultima)) {
                $ultima = $c;
            }
        }

        return $ultima;
    }

    /**
     * Si la carga $a es posterior a $b: primero por fecha y hora, y si empatan,
     * por ID.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function posteriorA($a, $b) {
        $fa = self::marcaTiempo($a);
        $fb = self::marcaTiempo($b);

        if ($fa !== $fb) {
            return $fa > $fb;
        }

        return intval($a['ID']) > intval($b['ID']);
    }

    /**
     * Lleva FECHA_CARGA a un string comparable 'Y-m-d H:i:s'.
     * sqlsrv devuelve DateTime para las columnas de fecha.
     *
     * @param array $carga
     * @return string
     */
    private static function marcaTiempo($carga) {
        $v = isset($carga['FECHA_CARGA']) ? $carga['FECHA_CARGA'] : null;

        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        // Una fecha sin hora ordena igual que la misma fecha a las 00:00:00
        return str_pad(substr((string) $v, 0, 19), 19, ' ');
    }

    /**
     * Junta las filas de la consulta de locales en UNA POR SUCURSAL.
     *
     * La consulta devuelve el ultimo saldo por (sucursal, cuenta de tesoreria).
     * La reserva de caja, en cambio, es un minimo de LA SUCURSAL, asi que el
     * neto solo tiene sentido sobre el total de su caja: con dos cuentas y una
     * reserva por sucursal, restar la reserva a cada cuenta la descontaria dos
     * veces.
     *
     * La fecha que queda es la MAS RECIENTE de las cuentas de esa sucursal: es
     * la fecha en la que ese saldo consolidado es cierto.
     *
     * @param array $filas Filas de la consulta: NRO_SUCURSAL, DESC_SUCURSAL,
     *        FECHA, COD_CTA, SALDO_CIER (ver getSaldosLocalesOrigen())
     * @return array Mapa NRO_SUCURSAL => fila consolidada
     */
    public static function agruparPorSucursal($filas) {
        $porSucursal = [];

        if (!is_array($filas)) {
            return $porSucursal;
        }

        foreach ($filas as $f) {
            $nro = intval(isset($f['NRO_SUCURSAL']) ? $f['NRO_SUCURSAL'] : 0);
            $fecha = Horizonte::normalizarFecha(isset($f['FECHA']) ? $f['FECHA'] : null);
            $cta = trim((string) (isset($f['COD_CTA']) ? $f['COD_CTA'] : ''));
            // El saldo de CIERRE: es lo que quedo en la caja al terminar el dia.
            $saldo = floatval(isset($f['SALDO_CIER']) ? $f['SALDO_CIER'] : 0);

            if (!isset($porSucursal[$nro])) {
                $porSucursal[$nro] = [
                    'nro_sucursal' => $nro,
                    'desc_sucursal' => trim((string) (isset($f['DESC_SUCURSAL'])
                        ? $f['DESC_SUCURSAL'] : '')),
                    'fecha_saldo' => $fecha,
                    'cuentas' => 0,
                    'cod_cta' => [],
                    'saldo' => 0
                ];
            }

            $porSucursal[$nro]['cuentas']++;
            $porSucursal[$nro]['saldo'] += $saldo;

            if ($cta !== '') {
                $porSucursal[$nro]['cod_cta'][] = $cta;
            }

            if ($fecha !== null
                && ($porSucursal[$nro]['fecha_saldo'] === null
                    || $fecha > $porSucursal[$nro]['fecha_saldo'])) {
                $porSucursal[$nro]['fecha_saldo'] = $fecha;
            }
        }

        return $porSucursal;
    }

    /**
     * El dia anterior a una fecha. Es la fecha del cierre que la consulta
     * tendria que haber traido hoy, y la que lleva un saldo manual.
     *
     * @param string $hoy 'Y-m-d'
     * @return string 'Y-m-d'
     */
    public static function ayer($hoy) {
        return date('Y-m-d', strtotime(substr((string) $hoy, 0, 10) . ' -1 day'));
    }

    /* --------------------------------------------------------------------
       EL DIA DE ACREDITACION DE UN LOCAL

       Cada local acredita su efectivo -o lo envia, si esta en ENVIA- un dia
       fijo de la semana, y el aporte del local se imputa en su PROXIMA FECHA
       DE ACREDITACION y no en la primera columna. La regla es una sola,
       proximaFechaAcreditacion(), y la usan la pestana, el tablero y la foto.
       -------------------------------------------------------------------- */

    /**
     * El dia validado.
     *
     * LA VALIDACION QUE VALE ES ESTA, no la del <select>: el endpoint es
     * alcanzable sin pasar por la pantalla. El CHECK de la columna es la
     * tercera red.
     *
     * Vacio o null es "sin dia", y es un valor valido: es como se borra un dia
     * mal cargado, y como queda un local nuevo.
     *
     * @param mixed $dia
     * @return int|null 1..5, o null si no tiene
     * @throws Exception si no es un entero de lunes a viernes
     */
    public static function validarDiaAcreditacion($dia) {
        if ($dia === null || $dia === '') {
            return null;
        }

        if (!is_numeric($dia) || intval($dia) != floatval($dia)
            || !isset(self::DIAS_ACREDITACION[intval($dia)])) {
            throw new Exception('El día de acreditación tiene que ser de lunes (1) a viernes (5). '
                . 'Se recibió "' . $dia . '".');
        }

        return intval($dia);
    }

    /**
     * La proxima fecha de acreditacion de un local.
     *
     * LA REGLA, EN DOS PASOS Y EN ESTE ORDEN:
     *
     *   1. la primera vez que ese dia de la semana cae HOY O DESPUES. Si hoy es
     *      ese dia, es hoy: el deposito de hoy todavia no se acredito.
     *   2. si esa fecha no es habil, el primer habil SIGUIENTE.
     *
     * VA HACIA ADELANTE, como Ventas y al reves que CronogramaPagos: es una
     * acreditacion, no un pago. El banco no acredita un dia que no opera, asi
     * que la plata entra despues; un pago, en cambio, se adelanta para cumplir.
     * El corrimiento es DiasHabiles::siguiente(), el mismo de Ventas y de las
     * tarjetas, con el mismo respaldo de lunes a viernes cuando la fecha no
     * esta en RO_T_CALENDARIO: dos respaldos distintos darian dos calendarios.
     *
     * DEVUELVE LAS DOS FECHAS, la teorica y la final: la teorica explica de
     * donde sale la final -"el lunes 12/10 es feriado: pasa al martes 13/10"-
     * y sin ella la pantalla mostraria un martes que nadie puede justificar.
     * Mismo criterio que TarjetasVencimiento::delMes().
     *
     * SIN DIA DEVUELVE null, y con el mapa roto -ningun habil en el tope- la
     * fecha es null con 'sin_habil': un dato que falta es null y un aviso,
     * nunca la fecha del tope disfrazada de acreditacion.
     *
     * Estatica y pura. Hoy se inyecta, para que las pruebas no caduquen.
     *
     * @param int|null $dia 1..5, o null
     * @param string $hoy 'Y-m-d'
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @return array|null ['dia', 'teorica', 'fecha' => 'Y-m-d'|null, 'corrida',
     *                     'faltan' => ['Y-m'], 'sin_habil'], o null sin dia
     */
    public static function proximaFechaAcreditacion($dia, $hoy, $habiles) {
        $dia = self::validarDiaAcreditacion($dia);

        if ($dia === null) {
            return null;
        }

        $hoy = substr((string) $hoy, 0, 10);
        $faltanDias = ($dia - intval(date('N', strtotime($hoy))) + 7) % 7;
        $teorica = date('Y-m-d', strtotime($hoy . ' +' . $faltanDias . ' day'));

        $r = DiasHabiles::siguiente($teorica, $habiles);

        return [
            'dia' => $dia,
            'teorica' => $teorica,
            'fecha' => $r['sin_habil'] ? null : $r['fecha'],
            'corrida' => $r['sin_habil'] ? false : $r['corrida'],
            'faltan' => $r['faltan'],
            'sin_habil' => $r['sin_habil']
        ];
    }

    /**
     * Desde y hasta que dia hay que leer el calendario para resolver la
     * proxima fecha de cualquier local.
     *
     * La teorica cae como mucho seis dias despues de hoy, y el corrimiento
     * puede sumar hasta DiasHabiles::MAX_CORRIMIENTO. Pedir el rango entero
     * cuesta lo mismo y evita que el respaldo se dispare por un rango corto,
     * que dejaria un aviso de calendario faltante que no describe nada real.
     *
     * @param string $hoy 'Y-m-d'
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    public static function rangoCalendarioAcreditacion($hoy) {
        $hoy = substr((string) $hoy, 0, 10);

        return [
            'desde' => $hoy,
            'hasta' => date('Y-m-d', strtotime($hoy . ' +' . (6 + DiasHabiles::MAX_CORRIMIENTO)
                . ' day'))
        ];
    }

    /**
     * La celda de la pantalla: "Lun · 12/10", o "sin día".
     *
     * El dia que se muestra es el de la FECHA FINAL, no el cargado: si el
     * lunes es feriado la plata entra el martes, y la celda tiene que decir
     * martes. Lo cargado y el corrimiento los explica el tooltip.
     *
     * @param array|null $r Lo que devolvio proximaFechaAcreditacion()
     * @return string
     */
    public static function etiquetaAcreditacion($r) {
        if ($r === null) {
            return 'sin día';
        }

        if ($r['fecha'] === null) {
            return 'sin fecha';
        }

        $dia = intval(date('N', strtotime($r['fecha'])));

        return self::DIAS_ABREV[$dia] . ' · ' . self::diaMes($r['fecha']);
    }

    /**
     * De donde sale la fecha, en una linea, para el tooltip de la celda.
     *
     * LO ARMA EL BACKEND porque es la explicacion de una cuenta que hace el
     * backend: con el texto en el JS, cambiar la regla obligaria a cambiarla en
     * dos lados. Mismo criterio que TarjetasVencimiento::explicar().
     *
     * En ENVIA el dia es el de ENVIO y es informativo: el local no aporta al
     * cashflow. Se dice, para que nadie busque esa fecha en el tablero.
     *
     * @param array|null $r Lo que devolvio proximaFechaAcreditacion()
     * @param string $gestion DEPOSITA o ENVIA
     * @return string
     */
    public static function explicarAcreditacion($r, $gestion) {
        $envia = (strtoupper(trim((string) $gestion)) === self::ENVIA);
        $que = $envia ? 'envío' : 'acreditación';
        $informativo = $envia
            ? ' Es informativo: el local está en Envía y no aporta al cashflow.'
            : '';

        if ($r === null) {
            return 'Sin día de ' . $que . ' cargado'
                . ($envia ? '.' : ': lo que aporta se imputa hoy, en la primera columna del tablero.')
                . ' Se carga en Parámetros → Saldos → Locales.' . $informativo;
        }

        $cargado = self::DIAS_ACREDITACION[$r['dia']];

        if ($r['sin_habil']) {
            return 'El día de ' . $que . ' es el ' . $cargado . ', pero '
                . lcfirst(DiasHabiles::avisoSinHabil($r['teorica'])) . $informativo;
        }

        if ($r['corrida']) {
            $texto = 'El ' . $cargado . ' ' . self::diaMes($r['teorica']) . ' es feriado: pasa al '
                . self::DIAS_ACREDITACION[intval(date('N', strtotime($r['fecha'])))] . ' '
                . self::diaMes($r['fecha']) . '.';
        } else {
            $texto = ($envia ? 'Envía' : 'Acredita') . ' el ' . $cargado . ' '
                . self::diaMes($r['fecha']) . '.';
        }

        if (!empty($r['faltan'])) {
            $texto .= ' RO_T_CALENDARIO no tiene datos para ' . implode(', ', $r['faltan'])
                . ': se asumió hábil de lunes a viernes.';
        }

        return $texto . $informativo;
    }

    /**
     * Superpone los saldos tipeados a mano sobre lo que trajo la consulta.
     *
     * ES LA UNICA REGLA DE PRECEDENCIA, y es por fecha: para cada local gana el
     * saldo MAS NUEVO entre el de la consulta y el manual, y a igual fecha gana
     * el MANUAL. Si alguien tipeo un saldo es porque el de la consulta no
     * servia -no vino, o vino mal-, y un dato tipeado hoy no puede quedar
     * tapado por uno del sistema de la misma fecha. Cuando la consulta vuelve
     * a traer un cierre mas nuevo, vuelve a mandar sola, sin que nadie tenga
     * que borrar nada: por eso un manual no es un override permanente sino un
     * registro fechado.
     *
     * Un manual de un local que la consulta no devuelve NO inventa la fila: la
     * lista de locales la define la consulta (propios y habilitados). Se
     * ignora en silencio; no es un caso de uso, es un local que dejo de existir.
     *
     * Cada fila queda con 'origen_saldo', y conserva 'saldo_consulta' y
     * 'fecha_consulta' para que la pantalla pueda mostrar que decia la consulta
     * cuando lo que manda es un manual.
     *
     * @param array $agrupadas Mapa NRO_SUCURSAL => fila de agruparPorSucursal()
     * @param array $manuales Mapa NRO_SUCURSAL => ['FECHA_SALDO', 'SALDO_MONEDA', ...]
     *        con el ULTIMO manual de cada local
     * @return array Mapa NRO_SUCURSAL => fila, con el saldo efectivo
     */
    public static function aplicarSaldosManuales($agrupadas, $manuales) {
        $manuales = is_array($manuales) ? $manuales : [];
        $resultado = [];

        foreach ((is_array($agrupadas) ? $agrupadas : []) as $nro => $s) {
            $s['saldo_consulta'] = $s['saldo'];
            $s['fecha_consulta'] = $s['fecha_saldo'];
            $s['origen_saldo'] = self::SALDO_CONSULTA;
            $s['manual'] = null;

            $m = isset($manuales[$nro]) ? $manuales[$nro] : null;

            if ($m !== null) {
                $fechaManual = Horizonte::normalizarFecha(
                    isset($m['FECHA_SALDO']) ? $m['FECHA_SALDO'] : null);

                // A igual fecha gana el manual. Sin fecha en la consulta,
                // tambien: un saldo tipeado es mejor que uno sin fecha.
                if ($fechaManual !== null
                    && ($s['fecha_saldo'] === null || $fechaManual >= $s['fecha_saldo'])) {
                    $s['saldo'] = floatval(isset($m['SALDO_MONEDA']) ? $m['SALDO_MONEDA'] : 0);
                    $s['fecha_saldo'] = $fechaManual;
                    $s['origen_saldo'] = self::SALDO_MANUAL;
                    $s['manual'] = [
                        'fecha_saldo' => $fechaManual,
                        'saldo' => $s['saldo'],
                        'fecha_update' => isset($m['FECHA_UPDATE']) ? $m['FECHA_UPDATE'] : null,
                        'usuario' => isset($m['USUARIO']) ? $m['USUARIO'] : null
                    ];
                }
            }

            $resultado[$nro] = $s;
        }

        return $resultado;
    }

    /**
     * Que saldos tipeados en la pantalla son NUEVOS respecto de lo que ya
     * manda, y por lo tanto hay que guardar como manual.
     *
     * Es un helper puro y devuelve una lista, no una escritura, por el mismo
     * motivo que resolverOverrides(): la pantalla manda TODOS los locales en
     * cada guardado, y sin el diff cada guardado insertaria veinte manuales
     * -uno por local- aunque nadie hubiera tocado ningun saldo, y a partir de
     * ahi la consulta no volveria a mandar nunca.
     *
     * Un saldo igual al efectivo -con tolerancia de un centavo, porque el valor
     * da la vuelta por un input numerico- no es un cambio. Un saldo ausente o
     * null tampoco: es una pantalla vieja que no manda el campo.
     *
     * @param array $filas Filas de armarSaldosLocales()['filas'], con el saldo
     *        efectivo de hoy
     * @param array $overrides [['nro_sucursal', 'saldo' => float|null, ...], ...]
     * @return array Lista de ['nro_sucursal', 'saldo', 'saldo_consulta', 'fecha_consulta']
     */
    public static function saldosManualesNuevos($filas, $overrides) {
        $porSucursal = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $porSucursal[intval($f['nro_sucursal'])] = $f;
        }

        $nuevos = [];

        foreach ((is_array($overrides) ? $overrides : []) as $o) {
            $nro = intval(isset($o['nro_sucursal']) ? $o['nro_sucursal'] : 0);

            if ($nro === 0 || !isset($porSucursal[$nro])
                || !array_key_exists('saldo', $o) || $o['saldo'] === null || $o['saldo'] === '') {
                continue;
            }

            if (!is_numeric($o['saldo'])) {
                throw new Exception('El saldo en caja del local ' . $nro . ' no es un número');
            }

            $saldo = floatval($o['saldo']);

            if ($saldo < 0) {
                throw new Exception('El saldo en caja del local ' . $nro . ' no puede ser negativo');
            }

            $actual = $porSucursal[$nro];

            if (abs(floatval($actual['saldo']) - $saldo) <= 0.005) {
                continue;
            }

            $nuevos[] = [
                'nro_sucursal' => $nro,
                'saldo' => $saldo,
                'saldo_consulta' => isset($actual['saldo_consulta'])
                    ? floatval($actual['saldo_consulta']) : null,
                'fecha_consulta' => isset($actual['fecha_consulta'])
                    ? $actual['fecha_consulta'] : null
            ];
        }

        return $nuevos;
    }

    /**
     * Arma la tabla de la pestana 2 a partir de la consulta y de los parametros
     * por sucursal.
     *
     * LAS TRES REGLAS DE NEGOCIO ESTAN ACA, Y SON LAS TRES QUE MAS FACIL SALEN
     * MAL EN SILENCIO:
     *
     * 1. SOLO LAS SUCURSALES EN 'DEPOSITA' APORTAN. Las que estan en 'ENVIA' se
     *    muestran en la pantalla pero no entran a la serie: su efectivo no llega
     *    al banco por esta via, y sumarlo seria contar plata que el tablero
     *    nunca va a ver acreditada.
     *
     * 2. EL NETO ES SALDO MENOS RESERVA, SIN NINGUN AJUSTE IMPOSITIVO. Ver la
     *    nota del encabezado de la clase.
     *
     * 3. UN NETO NEGATIVO APORTA CERO, NO NEGATIVO. Que la caja este por debajo
     *    de la reserva no significa que la sucursal le saque plata al banco:
     *    significa que no manda nada. El neto negativo igual se muestra, con un
     *    aviso, porque es informacion de la sucursal.
     *
     * Una sucursal que la consulta devuelve pero que no esta en los parametros
     * se trata como DEPOSITA con reserva cero y queda avisada: es una sucursal
     * nueva a la que todavia no le configuraron la reserva, y esconderla del
     * cuadro seria informar de menos.
     *
     * EL SALDO ES EL EFECTIVO: el de la consulta o el manual, segun
     * aplicarSaldosManuales(). Y cada fila dice si esta DESACTUALIZADA: si su
     * fecha es anterior a $ayer, que es el cierre que tendria que haber
     * llegado hoy. Es la senal de que hay que tipear el saldo, y se decide aca
     * -y no en el navegador- para que el tablero pueda avisarlo tambien.
     *
     * Y CADA FILA TRAE SU PROXIMA FECHA DE ACREDITACION, de
     * proximaFechaAcreditacion(), con la etiqueta y la explicacion que
     * muestra la pantalla. Se calcula aca para que la pestana, el tablero y la
     * foto la saquen del mismo lugar. Sin $acreditacion -el script no se
     * corrio- la fila la trae en null y no se avisa nada por local: el aviso
     * de que script falta lo da contextoAcreditacion().
     *
     * Los locales en DEPOSITA sin dia se avisan TODOS, aporten o no: el dato
     * falta igual, y un local que hoy aporta cero porque tiene la caja bajo la
     * reserva puede aportar manana.
     *
     * @param array $filasConsulta Filas crudas de la consulta de locales
     * @param array $params Mapa NRO_SUCURSAL => ['GESTION', 'RESERVA', 'DIA_ACREDITACION']
     * @param array $manuales Mapa NRO_SUCURSAL => ultimo saldo manual, o vacio
     * @param string|null $ayer 'Y-m-d' del cierre esperado; null para no marcar
     * @param array|null $acreditacion ['hoy' => 'Y-m-d', 'habiles' => mapa] de
     *        contextoAcreditacion(), o null sin el script
     * @return array ['filas' => [...], 'totales' => [...], 'avisos' => [...]]
     */
    public static function armarSaldosLocales($filasConsulta, $params, $manuales = [],
                                              $ayer = null, $acreditacion = null) {
        $agrupadas = self::aplicarSaldosManuales(
            self::agruparPorSucursal($filasConsulta), $manuales);
        $params = is_array($params) ? $params : [];
        $ayer = Horizonte::normalizarFecha($ayer);

        $filas = [];
        $avisos = [];
        $sinParametro = [];
        $desactualizados = [];
        $sinDia = [];
        $sinHabil = [];
        $faltanCalendario = [];

        $totales = [
            'saldo' => 0,
            'reserva' => 0,
            'neto' => 0,
            'aporta' => 0,
            'sucursales' => 0,
            'depositan' => 0,
            'envian' => 0,
            'manuales' => 0,
            'desactualizados' => 0,
            'sin_dia' => 0
        ];

        foreach ($agrupadas as $nro => $s) {
            $tieneParam = isset($params[$nro]);

            $gestion = $tieneParam ? strtoupper(trim((string) $params[$nro]['GESTION']))
                                   : self::DEPOSITA;
            $reserva = $tieneParam ? floatval($params[$nro]['RESERVA']) : 0;

            if ($gestion !== self::DEPOSITA && $gestion !== self::ENVIA) {
                $gestion = self::DEPOSITA;
            }

            if (!$tieneParam) {
                $sinParametro[] = $nro . ' ' . $s['desc_sucursal'];
            }

            $neto = $s['saldo'] - $reserva;
            $deposita = ($gestion === self::DEPOSITA);

            // Regla 1 y regla 3, en una linea: solo deposita, y nunca negativo.
            $aporta = ($deposita && $neto > 0) ? $neto : 0;

            if ($deposita && $neto < 0) {
                $avisos[] = Aviso::nuevo(Aviso::INFO, 'El local ' . $nro . ' ' . $s['desc_sucursal'] . ' tiene la caja por '
                    . 'debajo de su reserva (' . self::plata($neto) . '): no aporta al cashflow, '
                    . 'pero tampoco resta.');
            }

            // Desactualizado: el saldo que manda no es el cierre de ayer. Sin
            // fecha tambien cuenta, porque no se sabe de cuando es.
            $desactualizado = ($ayer !== null)
                && ($s['fecha_saldo'] === null || $s['fecha_saldo'] < $ayer);

            $acred = null;

            if ($acreditacion !== null) {
                $dia = ($tieneParam && isset($params[$nro]['DIA_ACREDITACION']))
                    ? $params[$nro]['DIA_ACREDITACION'] : null;

                // Un dia invalido en la tabla no puede pasar el CHECK; si
                // llegara igual, se trata como sin dia en vez de tumbar la
                // pestana y el tablero.
                try {
                    $r = self::proximaFechaAcreditacion($dia, $acreditacion['hoy'],
                        $acreditacion['habiles']);
                } catch (Exception $e) {
                    $r = null;
                }

                $acred = [
                    'dia' => $r === null ? null : $r['dia'],
                    'fecha' => $r === null ? null : $r['fecha'],
                    'teorica' => $r === null ? null : $r['teorica'],
                    'corrida' => $r !== null && $r['corrida'],
                    'sin_habil' => $r !== null && $r['sin_habil'],
                    'etiqueta' => self::etiquetaAcreditacion($r),
                    // Las dos explicaciones: la gestion se puede cambiar en la
                    // pantalla antes de guardar, y el tooltip tiene que seguirla.
                    'explicacion' => [
                        self::DEPOSITA => self::explicarAcreditacion($r, self::DEPOSITA),
                        self::ENVIA => self::explicarAcreditacion($r, self::ENVIA)
                    ]
                ];

                if ($r === null) {
                    $totales['sin_dia']++;

                    if ($deposita) {
                        $sinDia[] = $nro . ' ' . $s['desc_sucursal'];
                    }
                } else {
                    if ($r['sin_habil']) {
                        $sinHabil[] = $nro . ' ' . $s['desc_sucursal'];
                    }

                    foreach ($r['faltan'] as $mes) {
                        $faltanCalendario[$mes] = true;
                    }
                }
            }

            $filas[] = [
                'nro_sucursal' => $nro,
                'desc_sucursal' => $s['desc_sucursal'],
                'local' => $nro . ' ' . $s['desc_sucursal'],
                'fecha_saldo' => $s['fecha_saldo'],
                'cod_cta' => implode(', ', $s['cod_cta']),
                'cuentas' => $s['cuentas'],
                'saldo' => $s['saldo'],
                'origen_saldo' => $s['origen_saldo'],
                'saldo_consulta' => $s['saldo_consulta'],
                'fecha_consulta' => $s['fecha_consulta'],
                'manual' => $s['manual'],
                'desactualizado' => $desactualizado,
                'gestion' => $gestion,
                'reserva' => $reserva,
                'neto' => $neto,
                // Lo que efectivamente entra a la serie del Cashflow. Se expone
                // aparte de 'neto' para que la pantalla pueda mostrar los dos y
                // se vea POR QUE un neto de -50.000 aporta cero.
                'aporta' => $aporta,
                'sin_parametro' => !$tieneParam,
                // null sin el script; con el, la proxima fecha y como se llego
                'acreditacion' => $acred
            ];

            $totales['saldo'] += $s['saldo'];
            $totales['reserva'] += $reserva;
            $totales['neto'] += $neto;
            $totales['aporta'] += $aporta;
            $totales['sucursales']++;

            if ($deposita) {
                $totales['depositan']++;
            } else {
                $totales['envian']++;
            }

            if ($s['origen_saldo'] === self::SALDO_MANUAL) {
                $totales['manuales']++;
            }

            if ($desactualizado) {
                $totales['desactualizados']++;
                $desactualizados[] = $nro . ' ' . $s['desc_sucursal']
                    . ' (' . ($s['fecha_saldo'] === null ? 'sin fecha'
                        : self::fechaCorta($s['fecha_saldo'])) . ')';
            }
        }

        // Orden estable por numero de sucursal, como la consulta de origen
        usort($filas, function ($a, $b) {
            return $a['nro_sucursal'] - $b['nro_sucursal'];
        });

        if (!empty($sinParametro)) {
            sort($sinParametro);

            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Estos locales todavía no tienen gestión ni reserva configuradas y se '
                . 'están tomando como Deposita con reserva cero: ' . implode(', ', $sinParametro)
                . '. Configuralos en Parámetros → Saldos.');
        }

        if ($totales['envian'] > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $totales['envian'] . ' local(es) están en Envía: se muestran en la tabla '
                . 'pero su efectivo no entra al cashflow, porque no llega al banco por esta vía.');
        }

        // Lo desactualizado se avisa con la lista: es lo que hay que ir a
        // tipear, y el aviso sube tambien al tablero.
        if (!empty($desactualizados)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($desactualizados) . ' local(es) no tienen el saldo de caja de ayer ('
                . self::fechaCorta($ayer) . '): ' . implode(', ', $desactualizados) . '. Se '
                . 'proyecta con el último saldo conocido; si la consulta no lo trajo, cargalo a '
                . 'mano en Saldos → Saldos Locales.');
        }

        // Atencion: plata que entra al tablero en otra columna que la suya, y
        // se arregla cargando el dia.
        if (!empty($sinDia)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($sinDia) . ' local(es) en Deposita no '
                . 'tienen día de acreditación cargado: ' . implode(', ', $sinDia) . '. Lo que '
                . 'aportan se imputa hoy, en la primera columna. Cargalo en Parámetros → Saldos → '
                . 'Locales.');
        }

        // Critico: es un calendario roto, no una decision de nadie. El local
        // se imputa como si no tuviera dia, y se dice.
        if (!empty($sinHabil)) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se encontró ningún día hábil en los '
                . DiasHabiles::MAX_CORRIMIENTO . ' días siguientes al día de acreditación de: '
                . implode(', ', $sinHabil) . '. Revisá RO_T_CALENDARIO; mientras tanto lo que '
                . 'aportan se imputa hoy.');
        }

        foreach (DiasHabiles::avisosCalendario(array_keys($faltanCalendario)) as $a) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $a);
        }

        return ['filas' => $filas, 'totales' => $totales, 'avisos' => Aviso::textos($avisos),
                'avisos_con_nivel' => $avisos];
    }

    /**
     * Serie del Cashflow para la caja de locales.
     *
     * CADA LOCAL VA EN SU PROXIMA FECHA DE ACREDITACION. Lo que aporta un local
     * en DEPOSITA es plata que esta en su cajon y que entra al banco el dia que
     * el local deposita: con el dia cargado, ese es el dia en que el tablero
     * tiene que verla, y no hoy. La fecha la calculo armarSaldosLocales() con
     * proximaFechaAcreditacion() -la primera vez que ese dia de la semana cae
     * hoy o despues, corrida al habil SIGUIENTE si es feriado- y viene en
     * $f['acreditacion']['fecha']: aca no se vuelve a calcular, asi la pestana
     * y el tablero no pueden discrepar. Cada local va a su columna, asi que la
     * serie se reparte en varias.
     *
     * Antes la regla era "la fecha del saldo, sin corrimientos". Dejo de valer
     * para esta serie porque la fecha del saldo dice cuando se CONTO la plata,
     * no cuando LLEGA al banco; el corrimiento por feriado es el de una
     * acreditacion, el mismo de Ventas.
     *
     * LO QUE NO CAMBIA: el importe -el aporte de armarSaldosLocales(), con el
     * neto, la reserva, el saldo manual y su precedencia- y por lo tanto el
     * total de la serie. Solo cambia la columna en la que cae.
     *
     * UN LOCAL SIN DIA -o sin el script, o con un calendario roto- SE IMPUTA
     * COMO SIEMPRE: en la fecha del saldo, y si es anterior al eje, en la
     * primera columna (destinoEnEje()). armarSaldosLocales() ya lo avisa.
     *
     * UN SALDO SIN FECHA VA A sin_fecha AUNQUE EL LOCAL TENGA DIA: el importe
     * es el mismo de siempre, y si antes no entraba, imputarlo ahora cambiaria
     * el total de la serie, que es lo que esta regla no toca.
     *
     * UN SALDO CON FECHA ANTERIOR AL EJE SE IMPUTA EN LA PRIMERA COLUMNA, igual
     * que el disponible inicial. La consulta NO devuelve depositos: devuelve el
     * SALDO DE CAJA de cada local, o sea plata que todavia esta en el cajon y
     * que no llego al banco. Que Tango la haya registrado ayer no la convierte
     * en un movimiento ya consumido: sigue estando, y va a entrar al banco. Si
     * se descartara por tener fecha de ayer, la fila se veria en cero justo
     * cuando hay plata para depositar -que es lo que pasaba-, y encima el
     * importe no aparece en ningun otro lado del tablero, porque el saldo
     * bancario de la pestana 1 recien lo va a mostrar cuando se acredite.
     *
     * El aviso dice de que fecha es el saldo, para que nadie lo lea como de hoy.
     *
     * Una fecha posterior al eje -de acreditacion o de saldo- queda en
     * fuera_horizonte y el motor la informa, igual que siempre.
     *
     * @param array $filas Filas devueltas por armarSaldosLocales()['filas']
     * @param Horizonte $h
     * @return array Serie del contrato de CashflowProvider
     */
    public static function armarSerieLocales($filas, $h) {
        $serie = $h->serieVacia();
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['warnings'] = [];

        if (!is_array($filas)) {
            return $serie;
        }

        $hoy = $h->hoy();
        $reubicado = 0;
        $fechaMasVieja = null;

        foreach ($filas as $f) {
            $importe = floatval(isset($f['aporta']) ? $f['aporta'] : 0);

            if ($importe == 0) {
                continue;
            }

            $fecha = Horizonte::normalizarFecha(isset($f['fecha_saldo']) ? $f['fecha_saldo'] : null);

            if ($fecha === null) {
                $serie['sin_fecha'] += $importe;
                continue;
            }

            $acreditacion = Horizonte::normalizarFecha(
                isset($f['acreditacion']['fecha']) ? $f['acreditacion']['fecha'] : null);

            if ($acreditacion !== null) {
                // Con dia: su proxima fecha de acreditacion. Nunca es anterior
                // a hoy, salvo que la haya calculado otro "hoy"; destinoEnEje()
                // lo cubre igual que a cualquier fecha.
                if (!$h->acumular($serie, self::destinoEnEje($acreditacion, $hoy), $importe)) {
                    $serie['fuera_horizonte'] += $importe;
                }

                continue;
            }

            $destino = self::destinoEnEje($fecha, $hoy);

            if ($destino !== $fecha) {
                $reubicado += $importe;

                if ($fechaMasVieja === null || $fecha < $fechaMasVieja) {
                    $fechaMasVieja = $fecha;
                }
            }

            if (!$h->acumular($serie, $destino, $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        if ($reubicado != 0) {
            // Informativo: explica por que la primera columna trae plata con
            // fecha vieja, y no pide nada. Con seccion: va al grupo Saldos.
            $serie['warnings'][] = Aviso::nuevo(Aviso::INFO, self::plata($reubicado)
                . ' salen del último saldo de caja registrado, del '
                . self::fechaCorta($fechaMasVieja) . ', y se imputan en la primera columna del '
                . 'horizonte. Es plata que todavía está en el local y que no llegó al banco.',
                'Caja Locales');
        }

        return $serie;
    }

    /**
     * Superpone lo que el usuario dejo en la pantalla sobre los parametros
     * vigentes, y separa QUE CAMBIO de lo que llego igual.
     *
     * La pantalla manda las 20 sucursales en cada guardado, no solo las que se
     * tocaron: sin el diff, cada guardado le pisaria FECHA_UPDATE y USUARIO a
     * todas, y la columna "Ultima edicion" de Parametros dejaria de significar
     * algo -diria que alguien edito los veinte locales el mismo segundo, todas
     * las veces-.
     *
     * La reserva se compara con tolerancia porque la columna es DECIMAL(19,4) y
     * el valor da la vuelta por JSON y por un input numerico: una comparacion
     * estricta reportaria cambios que no existen.
     *
     * Valida aca y no en el bucle de escritura para que un valor invalido corte
     * ANTES de abrir la transaccion.
     *
     * @param array $actuales Mapa NRO_SUCURSAL => fila del parametro
     * @param array $overrides [['nro_sucursal', 'gestion', 'reserva'], ...]
     * @return array ['params' => mapa resultante, 'cambios' => lista de los que cambiaron]
     */
    public static function resolverOverrides($actuales, $overrides) {
        $params = is_array($actuales) ? $actuales : [];
        $cambios = [];

        foreach ((is_array($overrides) ? $overrides : []) as $o) {
            $nro = intval(isset($o['nro_sucursal']) ? $o['nro_sucursal'] : 0);

            if ($nro === 0) {
                continue;
            }

            $gestion = self::validarGestion(isset($o['gestion']) ? $o['gestion'] : '', $nro);
            $reserva = self::validarReserva(isset($o['reserva']) ? $o['reserva'] : 0, $nro);

            $antes = isset($params[$nro]) ? $params[$nro] : null;

            $distinto = ($antes === null)
                || (strtoupper(trim((string) $antes['GESTION'])) !== $gestion)
                || (abs(floatval($antes['RESERVA']) - $reserva) > 0.0001);

            // SE PISAN SOLO LOS DOS CAMPOS QUE EDITA ESTA PANTALLA, y el resto de
            // la fila queda. Rearmarla con solo gestion y reserva le borraba el
            // dia de acreditacion -que se edita en Parametros y no aca- a todos
            // los locales que manda la pantalla, que son todos, y la foto del
            // dia se guardaba sin dia.
            $params[$nro] = array_merge(is_array($antes) ? $antes : [], [
                'NRO_SUCURSAL' => $nro,
                'GESTION' => $gestion,
                'RESERVA' => $reserva
            ]);

            if ($distinto) {
                $cambios[] = [
                    'nro_sucursal' => $nro,
                    'gestion' => $gestion,
                    'reserva' => $reserva
                ];
            }
        }

        return ['params' => $params, 'cambios' => $cambios];
    }

    /**
     * Que locales cambiaron en el guardado de Parametros -> Saldos -> Locales.
     *
     * Es el mismo diff que resolverOverrides() hace para la pestana 2, por el
     * mismo motivo: la grilla manda los veinte locales en cada guardado, y sin
     * el diff cada guardado les sellaba USUARIO_MODIF y FECHA_MODIF a todos, y
     * "Ultima edicion" decia que alguien habia editado los veinte locales el
     * mismo segundo. Aca compara tambien el dia de acreditacion, que es el
     * campo que solo se edita en esta pantalla.
     *
     * EL DIA SE DISTINGUE ENTRE AUSENTE Y VACIO. Ausente -una pantalla vieja-
     * no lo toca; vacio o null es "sin dia" y lo borra, que es como se corrige
     * uno mal cargado.
     *
     * SIN EL SCRIPT ($conDia = false) EL DIA NO SE ESCRIBE, pero el resto del
     * guardado sigue: la gestion y la reserva no tienen nada que ver con la
     * columna que falta, y rechazar el guardado entero por eso le haria perder
     * a alguien una correccion de reserva. Los locales que mandaron un dia
     * vuelven en 'dia_ignorado' para que la respuesta diga que script correr.
     *
     * Valida todo aca, antes de abrir la transaccion. Un local que no esta en
     * los parametros es un error: esta pantalla lista solo los que estan, y el
     * UPDATE no afectaria ninguna fila y la edicion se perderia en silencio.
     *
     * @param array $actuales Mapa NRO_SUCURSAL => fila de getParametrosSucursales(false)
     * @param array $filas [['nro_sucursal', 'gestion', 'reserva', 'dia_acreditacion'?], ...]
     * @param bool $conDia Si existe la columna del dia (acreditacionCreada())
     * @return array ['cambios' => [['nro_sucursal', 'desc_sucursal', 'gestion',
     *               'reserva', 'dia_acreditacion'], ...], 'dia_ignorado' => [nro, ...]]
     */
    public static function resolverParametrosLocales($actuales, $filas, $conDia) {
        $actuales = is_array($actuales) ? $actuales : [];
        $cambios = [];
        $diaIgnorado = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $nro = intval(isset($f['nro_sucursal']) ? $f['nro_sucursal'] : 0);

            if ($nro === 0) {
                throw new Exception('Falta el número de un local');
            }

            if (!isset($actuales[$nro])) {
                throw new Exception('El local ' . $nro . ' no está en los parámetros. Usá '
                    . 'Sincronizar con locales y volvé a guardar.');
            }

            $antes = $actuales[$nro];

            $gestion = self::validarGestion(
                isset($f['gestion']) ? $f['gestion'] : $antes['GESTION'], $nro);
            $reserva = self::validarReserva(
                isset($f['reserva']) ? $f['reserva'] : $antes['RESERVA'], $nro);

            $diaAntes = (isset($antes['DIA_ACREDITACION']) && $antes['DIA_ACREDITACION'] !== null)
                ? intval($antes['DIA_ACREDITACION']) : null;
            $dia = $diaAntes;

            if (array_key_exists('dia_acreditacion', $f)) {
                if ($conDia) {
                    try {
                        $dia = self::validarDiaAcreditacion($f['dia_acreditacion']);
                    } catch (Exception $e) {
                        throw new Exception('Local ' . $nro . ': ' . $e->getMessage());
                    }
                } elseif ($f['dia_acreditacion'] !== null && $f['dia_acreditacion'] !== '') {
                    $diaIgnorado[] = $nro;
                }
            }

            $distinto = (strtoupper(trim((string) $antes['GESTION'])) !== $gestion)
                || (abs(floatval($antes['RESERVA']) - $reserva) > 0.0001)
                || ($conDia && $dia !== $diaAntes);

            if ($distinto) {
                $cambios[] = [
                    'nro_sucursal' => $nro,
                    'desc_sucursal' => isset($antes['DESC_SUCURSAL']) ? $antes['DESC_SUCURSAL'] : '',
                    'gestion' => $gestion,
                    'reserva' => $reserva,
                    'dia_acreditacion' => $dia
                ];
            }
        }

        return ['cambios' => $cambios, 'dia_ignorado' => $diaIgnorado];
    }

    /**
     * La gestion validada. La comparten las dos pantallas que la editan, para
     * que las dos rechacen lo mismo con el mismo mensaje.
     *
     * @param mixed $gestion
     * @param int $nro Para el mensaje
     * @return string DEPOSITA o ENVIA
     */
    private static function validarGestion($gestion, $nro) {
        $g = strtoupper(trim((string) $gestion));

        if ($g !== self::DEPOSITA && $g !== self::ENVIA) {
            throw new Exception('Gestión inválida para el local ' . $nro . ': "' . $g
                . '". Sólo puede ser Deposita o Envía.');
        }

        return $g;
    }

    /**
     * La reserva validada, con el mismo criterio que la gestion.
     *
     * @param mixed $reserva
     * @param int $nro Para el mensaje
     * @return float
     */
    private static function validarReserva($reserva, $nro) {
        $r = floatval($reserva);

        if ($r < 0) {
            throw new Exception('La reserva del local ' . $nro . ' no puede ser negativa');
        }

        return $r;
    }

    /**
     * Columna del eje en la que hay que imputar un importe fechado.
     *
     * El eje arranca HOY, asi que una fecha anterior no tiene columna propia.
     * Las dos series de este modulo describen PLATA QUE EXISTE AHORA -un saldo
     * bancario, el efectivo de un cajon-, no movimientos ya ocurridos, asi que
     * una fecha pasada significa "esto ya es cierto hoy" y va a la apertura del
     * horizonte. Descartarla mostraria cero teniendo el dato.
     *
     * Una fecha que SI cae dentro del eje no se toca aca. El unico corrimiento
     * por feriado de este modulo es el de la acreditacion de los locales, y lo
     * hace proximaFechaAcreditacion() antes de llegar aca: esta funcion solo
     * resuelve que hacer con una fecha que ya paso.
     *
     * @param string $fecha 'Y-m-d' del dato
     * @param string $hoy 'Y-m-d', primer dia del eje
     * @return string 'Y-m-d' de la columna destino
     */
    private static function destinoEnEje($fecha, $hoy) {
        return ($fecha < $hoy) ? $hoy : $fecha;
    }

    /**
     * Serie del Cashflow para el disponible inicial.
     *
     * EL SALDO VA EN LA COLUMNA DE SU FECHA Y EN CERO EN EL RESTO. La fila
     * DISPONIBLE es de tipo SALDO_INICIAL, y lo que el modulo pone en cada
     * columna entra al arrastre como APORTE de esa columna (ver
     * Cashflow::sumarAporteSaldo()). Repetir el saldo en todas las columnas del
     * eje sumaria la misma plata todos los dias: con 157 millones y 28
     * columnas, el tablero cerraria con cuatro mil millones de caja inventada.
     *
     * UN SALDO CON FECHA ANTERIOR AL EJE SE IMPUTA EN LA PRIMERA COLUMNA, y no
     * se descarta. Es la diferencia con la serie de locales, y no es una
     * inconsistencia: un deposito del viernes pasado es un movimiento que ya
     * ocurrio y que el tablero no tiene que volver a contar, mientras que el
     * saldo del viernes pasado ES la plata que hay hoy en la cuenta. Es el
     * saldo de apertura del horizonte; dejarlo afuera arrancaria el tablero en
     * cero, que es exactamente el problema que este modulo viene a resolver. El
     * aviso dice de que fecha es el saldo, para que nadie lo lea como de hoy.
     *
     * @param array $filas Filas con 'fecha_saldo', 'moneda', 'saldo'
     * @param Horizonte $h
     * @param array $cotizaciones Mapa 'YYYY-MM' => tipo de cambio, de
     *        Cotizacion::mapaMensual(). Un mes ausente es "sin cotizacion".
     * @return array ['serie' => ..., 'avisos' => [...], 'reubicado' => float,
     *                'usd_sin_cotizar' => float]
     */
    public static function armarSerieDisponible($filas, $h, $cotizaciones = []) {
        $serie = $h->serieVacia();
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;

        $hoy = $h->hoy();
        $cotizaciones = is_array($cotizaciones) ? $cotizaciones : [];

        $avisos = [];
        $reubicado = 0;
        $fechaMasVieja = null;
        $usdSinCotizar = 0;
        $tiposUsados = [];

        if (!is_array($filas)) {
            return self::resultadoDisponible($serie, $avisos, 0, 0);
        }

        foreach ($filas as $f) {
            $importe = floatval(isset($f['saldo']) ? $f['saldo'] : 0);

            if ($importe == 0) {
                continue;
            }

            $fecha = Horizonte::normalizarFecha(isset($f['fecha_saldo']) ? $f['fecha_saldo'] : null);
            $moneda = strtoupper((string) (isset($f['moneda']) ? $f['moneda'] : 'ARS'));

            if ($fecha === null) {
                $serie['sin_fecha'] += $importe;
                continue;
            }

            // El eje arranca hoy: un saldo anterior es la apertura del horizonte
            // y va a la primera columna. Se guarda de que fecha era para
            // poder decirlo. Misma regla que la serie de locales.
            $destino = self::destinoEnEje($fecha, $hoy);

            if ($destino !== $fecha) {
                $reubicado += $importe;

                if ($fechaMasVieja === null || $fecha < $fechaMasVieja) {
                    $fechaMasVieja = $fecha;
                }
            }

            if ($moneda === 'USD') {
                $mes = substr($destino, 0, 7);
                $tc = isset($cotizaciones[$mes]) ? floatval($cotizaciones[$mes]) : 0;

                if ($tc <= 0) {
                    // Un cero se leeria como "no hay dolares". Se informa el
                    // importe en su moneda y no se convierte a nada.
                    $usdSinCotizar += $importe;
                    continue;
                }

                $importe = $importe * $tc;
                $tiposUsados[$mes] = $tc;
            }

            if (!$h->acumular($serie, $destino, $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        /* "Saldo Inicial" va como SECCION y no pegado al texto: en el tablero
           estos avisos caen en el grupo Saldos junto con los de Caja Locales y
           los de las cuentas de fondo. Niveles: el saldo desactualizado deja
           el disponible mal sin que nadie lo decida (critico); el dolar sin
           valuar se arregla cargando la cotizacion (atencion); con que
           cotizacion se valuo es un criterio (informativo). */
        if ($reubicado != 0) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, self::plata($reubicado) . ' corresponden a '
                . 'saldos cargados el ' . self::fechaCorta($fechaMasVieja) . ' o antes y se '
                . 'muestran en la primera columna, que es la apertura del horizonte. Actualizá la '
                . 'carga de saldos para que el disponible sea el de hoy.', 'Saldo Inicial');
        }

        if ($usdSinCotizar != 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'US$ '
                . number_format($usdSinCotizar, 2, ',', '.')
                . ' no se pudieron valuar porque falta la cotización del mes, así que no entran '
                . 'al tablero. La pestaña Saldos los muestra igual, en dólares.', 'Saldo Inicial');
        }

        // 'tipo_cambio' informa con cual se convirtio. Con un solo mes en juego
        // -que es el caso normal, porque el saldo es de una fecha- es ese; con
        // varios se deja null y el detalle queda en el aviso, en vez de mostrar
        // uno cualquiera como si hubiera sido el unico.
        if (count($tiposUsados) === 1) {
            $serie['tipo_cambio'] = array_values($tiposUsados)[0];
        } elseif (count($tiposUsados) > 1) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Los saldos en dólares se valuaron con la '
                . 'cotización de cierre de cada mes (' . implode(', ', array_keys($tiposUsados))
                . ').', 'Saldo Inicial');
        }

        return self::resultadoDisponible($serie, $avisos, $reubicado, $usdSinCotizar);
    }

    /**
     * Empaqueta el resultado de armarSerieDisponible(). 'avisos' son los
     * textos y 'avisos_con_nivel' la misma lista con gravedad y seccion.
     */
    private static function resultadoDisponible($serie, $avisos, $reubicado, $usdSinCotizar) {
        return [
            'serie' => $serie,
            'avisos' => Aviso::textos($avisos),
            'avisos_con_nivel' => $avisos,
            'reubicado' => $reubicado,
            'usd_sin_cotizar' => $usdSinCotizar
        ];
    }

    /**
     * Totales de la pestana 1, uno POR MONEDA.
     *
     * Los saldos en dolares NO se convierten para mostrar: la pestana cierra con
     * un total en pesos y otro en dolares, cada uno en su moneda. Mezclarlos
     * daria un numero que no es ni una cosa ni la otra.
     *
     * @param array $filas Filas con 'moneda' y 'saldo'
     * @return array Mapa moneda => ['total' => float, 'cuentas' => int]
     */
    public static function totalesPorMoneda($filas) {
        $totales = [
            'ARS' => ['total' => 0, 'cuentas' => 0],
            'USD' => ['total' => 0, 'cuentas' => 0]
        ];

        if (!is_array($filas)) {
            return $totales;
        }

        foreach ($filas as $f) {
            $moneda = strtoupper((string) (isset($f['moneda']) ? $f['moneda'] : 'ARS'));

            if (!isset($totales[$moneda])) {
                $totales[$moneda] = ['total' => 0, 'cuentas' => 0];
            }

            $totales[$moneda]['total'] += floatval(isset($f['saldo']) ? $f['saldo'] : 0);
            $totales[$moneda]['cuentas']++;
        }

        return $totales;
    }

    /**
     * Avisos sobre la antiguedad de la ultima carga.
     *
     * La regla transversal del relevamiento es que se vea la fecha de carga de
     * cada dato. El aviso es el complemento: que la pantalla lo diga sola cuando
     * el dato quedo viejo, en vez de esperar que alguien mire la columna.
     *
     * @param string|null $fechaCarga 'Y-m-d' de la ultima carga, o null si no hay
     * @param int $diasAlerta Dias a partir de los cuales se avisa
     * @param string $hoy 'Y-m-d'
     * @return array Lista de avisos
     */
    public static function avisosAntiguedad($fechaCarga, $diasAlerta, $hoy) {
        if ($fechaCarga === null || $fechaCarga === '') {
            return ['Todavía no hay ninguna carga de saldos. El Saldo Inicial del tablero se '
                . 'muestra en cero hasta que se cargue la primera.'];
        }

        $dias = (int) floor((strtotime($hoy) - strtotime(substr($fechaCarga, 0, 10))) / 86400);

        if ($diasAlerta > 0 && $dias > $diasAlerta) {
            return ['La última carga de saldos es del ' . self::fechaCorta($fechaCarga)
                . ', hace ' . $dias . ' días. El disponible que se está mostrando no es el de hoy.'];
        }

        return [];
    }

    /* ====================================================================
       LECTURAS
       ==================================================================== */

    /**
     * Si las tablas del modulo ya se crearon.
     *
     * @return bool
     */
    public function tablasCreadas() {
        if ($this->tablas !== null) {
            return $this->tablas;
        }

        $cid = $this->conectar('central');

        $sql = "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'U')   AS C,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_CARGA', 'U')    AS G,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_DETALLE', 'U')  AS D,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'U') AS S,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'U')    AS L";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de Saldos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ($row
            && $row['C'] !== null && $row['G'] !== null && $row['D'] !== null
            && $row['S'] !== null && $row['L'] !== null);

        return $this->tablas;
    }

    /**
     * Si existe la tabla de saldos manuales de locales y la columna ORIGEN_DATO
     * de la foto: las dos las crea sql/cashflow_saldos_local_manual.sql.
     *
     * Va aparte de tablasCreadas() a proposito: sin esto la pestana funciona
     * igual que antes -consulta, gestion y reserva-, solo que no deja tipear el
     * saldo y dice que script correr. Meterlo en tablasCreadas() dejaria la
     * pestana entera en blanco por una migracion pendiente.
     *
     * @return bool
     */
    public function manualesCreados() {
        if ($this->manuales !== null) {
            return $this->manuales;
        }

        if (!$this->tablasCreadas()) {
            $this->manuales = false;

            return false;
        }

        $cid = $this->conectar('central');

        $sql = "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL', 'U') AS M,
                       COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'ORIGEN_DATO') AS O";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de saldos manuales'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->manuales = ($row && $row['M'] !== null && $row['O'] !== null);

        return $this->manuales;
    }

    /**
     * Si existen las columnas del dia de acreditacion: la del parametro y las
     * dos de la foto. Las tres las crea sql/cashflow_saldos_dia_acreditacion.sql,
     * asi que se piden juntas: con una sola, el dia se podria elegir pero no
     * guardar en la foto, o al reves.
     *
     * Va aparte de tablasCreadas() por el mismo motivo que manualesCreados():
     * sin el script la pestana funciona como antes -todo a la primera columna-
     * y dice que script correr, en vez de quedar en blanco.
     *
     * @return bool
     */
    public function acreditacionCreada() {
        if ($this->acreditacion !== null) {
            return $this->acreditacion;
        }

        if (!$this->tablasCreadas()) {
            $this->acreditacion = false;

            return false;
        }

        $cid = $this->conectar('central');

        $sql = "SELECT COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_SUCURSAL', 'DIA_ACREDITACION') AS S,
                       COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'DIA_ACREDITACION')    AS D,
                       COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_LOCAL', 'FECHA_ACREDITACION')  AS F";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el día de acreditación'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->acreditacion = ($row && $row['S'] !== null && $row['D'] !== null
            && $row['F'] !== null);

        return $this->acreditacion;
    }

    /**
     * Lo que armarSaldosLocales() necesita para calcular la proxima fecha de
     * acreditacion de cada local: hoy y el calendario de dias habiles.
     *
     * ES EL UNICO CAMINO, y lo usan la pestana, el tablero y la foto: los tres
     * tienen que ver la misma fecha para el mismo local, o la pestana diria
     * martes y el tablero imputaria el lunes.
     *
     * EL CALENDARIO SE LEE POR CronogramaDatos::habilesEntre(), que es el
     * camino de Ventas::getDiasHabiles() -la unica lectura de RO_T_CALENDARIO
     * del modulo- con el mismo manejo de falla que el cronograma y las
     * tarjetas: si no se puede leer devuelve un mapa vacio y un aviso critico,
     * y la regla aplica el respaldo de lunes a viernes. Una segunda consulta
     * aca seria una segunda definicion de "dia habil".
     *
     * SIN EL SCRIPT devuelve 'acreditacion' = null y un aviso que dice cual
     * correr: armarSaldosLocales() se comporta como antes -todo a la primera
     * columna- y la pantalla no se cae. Sin el script no se lee el calendario:
     * no habria para que.
     *
     * @param string $hoy 'Y-m-d'
     * @return array ['acreditacion' => ['hoy', 'habiles']|null, 'avisos' => [Aviso]]
     */
    public function contextoAcreditacion($hoy) {
        $avisos = [];

        try {
            $creada = $this->acreditacionCreada();
        } catch (Throwable $e) {
            return ['acreditacion' => null, 'avisos' => [Aviso::nuevo(Aviso::DANGER,
                'No se pudo verificar si existe el día de acreditación de los locales ('
                . $e->getMessage() . '): la caja de los locales se imputa entera en la primera '
                . 'columna.')]];
        }

        if (!$creada) {
            if ($this->tablasCreadas()) {
                // Atencion y no critico: el tablero muestra lo mismo que antes
                // de que existiera el dia, y se arregla corriendo un script.
                $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Todavía no existe el día de '
                    . 'acreditación de los locales: corré sql/cashflow_saldos_dia_acreditacion.sql '
                    . 'contra la base central. Mientras tanto la caja de los locales se imputa entera '
                    . 'en la primera columna, como hasta ahora.');
            }

            return ['acreditacion' => null, 'avisos' => $avisos];
        }

        require_once __DIR__ . '/CronogramaDatos.php';

        $crono = new CronogramaDatos();
        $rango = self::rangoCalendarioAcreditacion($hoy);
        $habiles = $crono->habilesEntre($rango['desde'], $rango['hasta']);

        foreach ($crono->avisosConNivel() as $a) {
            $avisos[] = $a;
        }

        return ['acreditacion' => ['hoy' => substr((string) $hoy, 0, 10), 'habiles' => $habiles],
                'avisos' => $avisos];
    }

    /**
     * El ultimo saldo manual ACTIVO de cada local.
     *
     * "El ultimo" es por FECHA_SALDO y, a igual fecha, por ID: una correccion
     * del mismo dia es una fila nueva con ID mayor, igual que en ultimaCarga().
     * Cual manda contra la consulta lo decide aplicarSaldosManuales().
     *
     * @return array Mapa NRO_SUCURSAL => fila (FECHA_SALDO 'Y-m-d', SALDO_MONEDA
     *         float, FECHA_UPDATE, USUARIO). Vacio si la tabla no existe.
     */
    public function getSaldosLocalesManuales() {
        if (!$this->manualesCreados()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "WITH Ultimo AS (
                    SELECT ID, NRO_SUCURSAL, FECHA_SALDO, SALDO_MONEDA,
                           FECHA_MODIF AS FECHA_UPDATE, USUARIO_MODIF AS USUARIO,
                           ROW_NUMBER() OVER (
                               PARTITION BY NRO_SUCURSAL
                               ORDER BY FECHA_SALDO DESC, ID DESC
                           ) AS RN
                    FROM RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL
                    WHERE ACTIVO = 1
                )
                SELECT ID, NRO_SUCURSAL, FECHA_SALDO, SALDO_MONEDA, FECHA_UPDATE, USUARIO
                FROM Ultimo WHERE RN = 1";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los saldos manuales de locales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $nro = intval($row['NRO_SUCURSAL']);

            $v[$nro] = [
                'ID' => intval($row['ID']),
                'NRO_SUCURSAL' => $nro,
                'FECHA_SALDO' => Horizonte::normalizarFecha($row['FECHA_SALDO']),
                'SALDO_MONEDA' => floatval($row['SALDO_MONEDA']),
                'FECHA_UPDATE' => $this->fechaHora($row['FECHA_UPDATE']),
                'USUARIO' => $row['USUARIO']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Avisos de configuracion pendiente.
     *
     * @return array Lista de mensajes
     */
    public function getAvisos() {
        $avisos = [];

        if (!$this->tablasCreadas()) {
            $avisos[] = 'Todavía no existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql contra la base central.';
        }

        return $avisos;
    }

    /**
     * Saldo del efectivo de la caja de tesoreria de casa central.
     *
     * ES UN SALDO PUNTUAL: la consulta devuelve un solo numero, el acumulado de
     * movimientos de esa cuenta hasta el momento en que se pregunta. No tiene
     * fecha propia ni historico. Por eso la fecha del dato es la de la consulta
     * y el historico lo construye este modulo, guardando el valor en cada carga.
     *
     * La cuenta contable sale del parametro saldos_cta_tesoreria y va como
     * PARAMETRO de la consulta: es un numero de cuenta del plan contable y
     * cambiarlo no puede requerir tocar codigo.
     *
     * @param array|null $map Mapa de parametros ya leido
     * @return array ['saldo' => float, 'cuenta' => string, 'fecha' => 'Y-m-d']
     */
    public function getEfectivoCentral($map = null) {
        if ($map === null) {
            $map = (new Parametros())->getParametrosMap();
        }

        if (!isset($map[self::PARAM_CTA_TESORERIA])
            || trim((string) $map[self::PARAM_CTA_TESORERIA]) === '') {
            throw new Exception('Falta el parámetro "' . self::PARAM_CTA_TESORERIA . '" en '
                . 'RO_T_CASHFLOW_PARAMETROS: sin la cuenta contable no se puede leer el efectivo '
                . 'de tesorería.');
        }

        $cuenta = trim((string) $map[self::PARAM_CTA_TESORERIA]);

        $cid = $this->conectar('central');

        $sql = "SELECT COALESCE(SUM(CASE WHEN D_H = 'D' THEN MONTO ELSE -MONTO END), 0.00) AS SALDO
                FROM SBA05
                WHERE COD_CTA = ?";

        $stmt = sqlsrv_query($cid, $sql, [$cuenta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el efectivo de tesoreria'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return [
            'saldo' => $row ? floatval($row['SALDO']) : 0,
            'cuenta' => $cuenta,
            'fecha' => date('Y-m-d')
        ];
    }

    /**
     * Ultimo saldo conocido de CADA cuenta activa, con la fecha de la carga en
     * la que se registro.
     *
     * NO es "las filas de la ultima carga": es el ultimo saldo DE CADA CUENTA.
     * La diferencia importa cuando alguien da de alta una cuenta despues de la
     * ultima carga, o cuando una carga se hizo sin completar todas: con el
     * criterio de "la ultima carga" esas cuentas desaparecerian del cuadro o se
     * verian en cero, y un cero se lee como "esta cuenta no tiene plata". Asi,
     * cada fila muestra su propio dato con su propia fecha, que es justo lo que
     * pide el relevamiento.
     *
     * Una cuenta que nunca se cargo viene con los importes en NULL.
     *
     * SOLO LAS CUENTAS A LA VISTA. Los fondos -clase INVERSION o COMITENTE- no
     * son disponibilidad: son stock de cobertura, y entran al tablero por
     * FondosProvider. Si ademas se listaran aca, sumarian al Saldo Inicial la
     * misma plata que la seccion Cobertura ofrece para tapar baches. El corte
     * lo hace la clase con Fondos::CLASES_FONDO; sin la columna (script sin
     * correr) no hay fondos y no hay nada que cortar.
     *
     * @return array Filas listas para la pantalla
     */
    public function getSaldosActuales() {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $conClase = $this->fondosCreados();
        $colClase = $conClase ? "CU.CLASE" : "'" . Fondos::CLASE_DEFECTO . "'";
        $sinFondos = $conClase
            ? " AND CU.CLASE NOT IN ('" . implode("','", Fondos::CLASES_FONDO) . "')"
            : "";

        $sql = "WITH Ultimo AS (
                    SELECT D.ID_CUENTA, D.FECHA_SALDO, D.MONEDA, D.COUNTABLE_BALANCE,
                           D.INITIAL_OPERATING_BALANCE, D.CURRENT_OPERATING_BALANCE,
                           D.PROJECTED_BALANCE_24HS, D.PROJECTED_BALANCE_48HS,
                           D.DAY_BALANCE, D.TOTAL_DEBITS, D.TOTAL_CREDITS,
                           D.MESSAGE, D.ORIGEN_DATO,
                           C.ID AS ID_CARGA, C.FECHA_CARGA, C.USUARIO_MODIF AS USUARIO_CARGA,
                           ROW_NUMBER() OVER (
                               PARTITION BY D.ID_CUENTA
                               ORDER BY D.FECHA_SALDO DESC, C.FECHA_CARGA DESC, D.ID DESC
                           ) AS RN
                    FROM RO_T_CASHFLOW_SALDOS_DETALLE D
                    INNER JOIN RO_T_CASHFLOW_SALDOS_CARGA C
                        ON C.ID = D.ID_CARGA AND C.ACTIVO = 1 AND C.TIPO = ?
                )
                SELECT CU.ID, CU.TIPO, " . $colClase . " AS CLASE, CU.NOMBRE, CU.MONEDA,
                       CU.ORIGEN_DATO AS ORIGEN_CUENTA,
                       CU.BANK_ID, CU.BANK_NAME, CU.ACCOUNT_NUMBER, CU.ACCOUNT_TYPE,
                       CU.CBU, CU.ACCOUNT_LABEL, CU.ORDEN,
                       U.FECHA_SALDO, U.COUNTABLE_BALANCE, U.INITIAL_OPERATING_BALANCE,
                       U.CURRENT_OPERATING_BALANCE, U.PROJECTED_BALANCE_24HS,
                       U.PROJECTED_BALANCE_48HS, U.DAY_BALANCE, U.TOTAL_DEBITS,
                       U.TOTAL_CREDITS, U.MESSAGE, U.ORIGEN_DATO,
                       U.ID_CARGA, U.FECHA_CARGA, U.USUARIO_CARGA
                FROM RO_T_CASHFLOW_SALDOS_CUENTA CU
                LEFT JOIN Ultimo U ON U.ID_CUENTA = CU.ID AND U.RN = 1
                WHERE CU.ACTIVO = 1" . $sinFondos . "
                ORDER BY CU.ORDEN, CU.NOMBRE";

        $stmt = sqlsrv_query($cid, $sql, [self::CARGA_SALDOS]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los saldos'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = $this->filaSaldo($row);
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Normaliza una fila de getSaldosActuales() para la pantalla y para
     * armarSerieDisponible().
     *
     * 'cargada' distingue "esta cuenta nunca se cargo" de "esta cuenta tiene
     * cero", que es la diferencia entre un guion y un numero en la pantalla.
     *
     * @param array $row
     * @return array
     */
    private function filaSaldo($row) {
        $cargada = ($row['ID_CARGA'] !== null);

        return [
            'id_cuenta' => intval($row['ID']),
            'tipo' => $row['TIPO'],
            'clase' => $row['CLASE'],
            'nombre' => $row['NOMBRE'],
            'moneda' => $row['MONEDA'],
            'origen_cuenta' => $row['ORIGEN_CUENTA'],
            'bank_id' => $row['BANK_ID'],
            'bank_name' => $row['BANK_NAME'],
            'account_number' => $row['ACCOUNT_NUMBER'],
            'account_type' => $row['ACCOUNT_TYPE'],
            'cbu' => $row['CBU'],
            'account_label' => $row['ACCOUNT_LABEL'],
            'cargada' => $cargada,
            // El saldo que alimenta el Cashflow es el CONTABLE.
            'saldo' => $cargada ? floatval($row['COUNTABLE_BALANCE']) : 0,
            'saldo_operativo_inicial' => $this->numeroONull($row['INITIAL_OPERATING_BALANCE']),
            'saldo_operativo_actual' => $this->numeroONull($row['CURRENT_OPERATING_BALANCE']),
            'proyectado_24hs' => $this->numeroONull($row['PROJECTED_BALANCE_24HS']),
            'proyectado_48hs' => $this->numeroONull($row['PROJECTED_BALANCE_48HS']),
            'debitos' => $this->numeroONull($row['TOTAL_DEBITS']),
            'creditos' => $this->numeroONull($row['TOTAL_CREDITS']),
            'mensaje' => $row['MESSAGE'],
            'origen_dato' => $row['ORIGEN_DATO'],
            'fecha_saldo' => Horizonte::normalizarFecha($row['FECHA_SALDO']),
            'id_carga' => $cargada ? intval($row['ID_CARGA']) : null,
            'fecha_carga' => $this->fechaHora($row['FECHA_CARGA']),
            'usuario_carga' => $row['USUARIO_CARGA']
        ];
    }

    /**
     * Cabeceras de carga de un tipo, de la mas reciente a la mas vieja.
     *
     * Devuelve la lista completa (acotada) y no solo la ultima porque
     * ultimaCarga() es un helper puro y se prueba sin base: la clase lee, el
     * helper decide.
     *
     * @param string $tipo SALDOS o LOCALES
     * @param int $tope Cantidad maxima de cabeceras
     * @return array
     */
    public function getCargas($tipo, $tope = 20) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT TOP (?) ID, TIPO, FECHA_CARGA, ORIGEN, OBSERVACIONES, USUARIO_MODIF AS USUARIO
                FROM RO_T_CASHFLOW_SALDOS_CARGA
                WHERE TIPO = ? AND ACTIVO = 1
                ORDER BY FECHA_CARGA DESC, ID DESC";

        $stmt = sqlsrv_query($cid, $sql, [intval($tope), $tipo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cargas de saldos'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ID'] = intval($row['ID']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Ultimo saldo de caja de cada local propio, desde el servidor 'locales'.
     *
     * EL ORIGEN ES RO_T_SALDOS_CIERRE_SBA29 Y EL IMPORTE ES SALDO_CIER: el saldo
     * de CIERRE del dia. La tabla trae tambien SALDO_APE -la apertura-, que no
     * se usa: la plata que hay para depositar es la que quedo al cerrar. Antes
     * se leia RO_T_SALDO_CAJA_SUCURSALES.SALDO_MONEDA; se cambio de origen por
     * pedido del negocio.
     *
     * SE TOMA EL ULTIMO REGISTRO de cada (sucursal, cuenta): la fecha mas nueva
     * y, a igual fecha, el ID mas alto. El desempate no es opcional: la tabla
     * tiene dias con la misma (sucursal, cuenta, fecha) cargada dos veces, y
     * sin el ID saldrian las dos filas y el saldo se contaria doble. Es lo que
     * se muestra hoy.
     *
     * La consolidacion por sucursal la hace agruparPorSucursal(). El nombre del
     * local sale de SUCURSALES_LAKERS y no de la tabla de saldos, porque es el
     * join el que decide que locales son PROPIOS y estan HABILITADOS.
     *
     * @return array Filas crudas: ID, FECHA, NRO_SUCURSAL, DESC_SUCURSAL,
     *         COD_CTA, SALDO_CIER
     */
    public function getSaldosLocalesOrigen() {
        $cid = $this->conectar('locales');
        $p = $this->prefijoLocales();

        // COD_CTA es FLOAT en la tabla: se lleva a texto en SQL para que un
        // codigo no aparezca como 1.00102E+5 en la pantalla ni en la foto.
        $sql = "WITH SaldoLocales AS (
                    SELECT A.ID, A.FECHA, A.NRO_SUCURS AS NRO_SUCURSAL, B.DESC_SUCURSAL,
                           CAST(CAST(A.COD_CTA AS BIGINT) AS VARCHAR(20)) AS COD_CTA,
                           A.SALDO_CIER,
                           ROW_NUMBER() OVER (
                               PARTITION BY A.NRO_SUCURS, A.COD_CTA
                               ORDER BY A.FECHA DESC, A.ID DESC
                           ) AS RN
                    FROM {$p}RO_T_SALDOS_CIERRE_SBA29 A
                    INNER JOIN {$p}SUCURSALES_LAKERS B ON A.NRO_SUCURS = B.NRO_SUCURSAL
                    WHERE B.CANAL = 'PROPIOS' AND B.HABILITADO = 1
                )
                SELECT ID, FECHA, NRO_SUCURSAL, DESC_SUCURSAL, COD_CTA, SALDO_CIER
                FROM SaldoLocales WHERE RN = 1 ORDER BY NRO_SUCURSAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los saldos de caja de los locales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Locales propios habilitados, desde el servidor 'locales'.
     * Es la lista con la que se siembra el parametro por sucursal.
     *
     * @return array Filas NRO_SUCURSAL, DESC_SUCURSAL
     */
    public function getSucursalesOrigen() {
        $cid = $this->conectar('locales');
        $p = $this->prefijoLocales();

        $sql = "SELECT NRO_SUCURSAL, DESC_SUCURSAL FROM {$p}SUCURSALES_LAKERS
                WHERE CANAL = 'PROPIOS' AND HABILITADO = 1
                ORDER BY NRO_SUCURSAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las sucursales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'NRO_SUCURSAL' => intval($row['NRO_SUCURSAL']),
                'DESC_SUCURSAL' => trim((string) $row['DESC_SUCURSAL'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Parametros de gestion, reserva y dia de acreditacion por sucursal,
     * indexados por numero.
     *
     * DIA_ACREDITACION viene SIEMPRE, como entero o null: sin el script es
     * null para todos, que es lo que dice la verdad -no hay dia cargado-, y
     * quien lee no tiene que preguntar si la clave existe. Si el script falta
     * lo dice acreditacionCreada(), no la forma de la fila.
     *
     * @param bool $soloActivas
     * @return array Mapa NRO_SUCURSAL => fila
     */
    public function getParametrosSucursales($soloActivas = true) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $dia = $this->acreditacionCreada()
            ? 'DIA_ACREDITACION'
            : 'CAST(NULL AS TINYINT) AS DIA_ACREDITACION';

        $sql = "SELECT NRO_SUCURSAL, DESC_SUCURSAL, GESTION, RESERVA, ACTIVO, $dia,
                       FECHA_MODIF AS FECHA_UPDATE, USUARIO_MODIF AS USUARIO
                FROM RO_T_CASHFLOW_SALDOS_SUCURSAL";

        if ($soloActivas) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY NRO_SUCURSAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los parametros de las sucursales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['NRO_SUCURSAL'] = intval($row['NRO_SUCURSAL']);
            $row['RESERVA'] = floatval($row['RESERVA']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $row['DIA_ACREDITACION'] = ($row['DIA_ACREDITACION'] === null)
                ? null : intval($row['DIA_ACREDITACION']);
            $row['FECHA_UPDATE'] = $this->fechaHora($row['FECHA_UPDATE']);
            $v[$row['NRO_SUCURSAL']] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       ABM DE LOS PARAMETROS DEL MODULO

       Sigue el patron de Parametros::getMixCobro / saveMixCobro / addMixCobro,
       que es el ABM que ya existe en el proyecto: un getter con filtro de
       activos, un save que no crea, un add que chequea la clave natural antes
       de insertar para dar un mensaje entendible en vez del error del indice, y
       NINGUNA baja fisica.

       Vive en esta clase y no en Parametros porque las tablas son del modulo
       Saldos y este mismo archivo ya las escribe al sincronizar y al guardar
       una carga; una segunda copia de las consultas en otra clase termina
       desincronizada. Parametros las expone en su pestana delegando aca.
       ==================================================================== */

    /**
     * Cuentas del catalogo: bancos, Mercado Pago, efectivo central y otros.
     *
     * @param bool $soloActivas true para las pantallas de datos, false para el
     *        editor de Parametros, que tiene que poder ver las inhabilitadas
     *        para reactivarlas
     * @return array
     */
    public function getCuentas($soloActivas = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        // CLASE y el saldo inicial existen desde sql/cashflow_saldos_cuentas_fondo.sql.
        // Sin el script todas son cuentas a la vista, que es lo que eran.
        $colsFondo = $this->fondosCreados()
            ? "CLASE, SALDO_INICIAL, FECHA_SALDO_INICIAL"
            : "'" . Fondos::CLASE_DEFECTO . "' AS CLASE, NULL AS SALDO_INICIAL, "
                . "NULL AS FECHA_SALDO_INICIAL";

        $sql = "SELECT ID, TIPO, " . $colsFondo . ", NOMBRE, MONEDA, ORIGEN_DATO,
                       BANK_ID, BANK_NAME, ACCOUNT_NUMBER, ACCOUNT_TYPE, CBU, ACCOUNT_LABEL,
                       ORDEN, ACTIVO, FECHA_MODIF AS FECHA_UPDATE, USUARIO_MODIF AS USUARIO
                FROM RO_T_CASHFLOW_SALDOS_CUENTA";

        if ($soloActivas) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY ORDEN, NOMBRE";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cuentas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ID'] = intval($row['ID']);
            $row['ORDEN'] = intval($row['ORDEN']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $row['FECHA_UPDATE'] = $this->fechaHora($row['FECHA_UPDATE']);
            $row['SALDO_INICIAL'] = $this->numeroONull($row['SALDO_INICIAL']);
            $row['FECHA_SALDO_INICIAL'] = Horizonte::normalizarFecha($row['FECHA_SALDO_INICIAL']);
            $row['ES_FONDO'] = Fondos::esFondo($row['CLASE']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Edita una cuenta del catalogo.
     *
     * NO se puede cambiar el TIPO: es lo que decide de donde sale el saldo, y
     * cambiarlo dejaria el historico ya cargado atribuido a un origen que nunca
     * lo produjo. Si quedo mal, se inhabilita y se crea otra, igual que con el
     * codigo de una fila del tablero.
     *
     * Inhabilitar NO borra: la cuenta desaparece de la pantalla de carga y deja
     * de sumar al disponible, pero su historico queda entero.
     *
     * LA CLASE SE PUEDE CAMBIAR SOLO DENTRO DEL MISMO GRUPO (a la vista <->
     * a la vista, fondo <-> fondo). Cruzar de grupo dejaria el historico de la
     * cuenta -fotos en un caso, movimientos en el otro- leido como lo que no
     * es. La regla es Fondos::cambioDeClasePermitido().
     *
     * LA MONEDA DE UN FONDO CON MOVIMIENTOS NO SE CAMBIA. El saldo es una suma
     * y una suma en dos monedas no es nada. En una cuenta a la vista si se
     * puede, como siempre: el detalle copia la moneda en cada carga.
     *
     * EL SALDO INICIAL solo tiene sentido en un fondo. Se pasa null para no
     * tocarlo; con ['saldo', 'fecha'] se reemplaza, y es un UPDATE auditado y
     * no una fila nueva: es el punto desde el que se cuenta, no un hecho. Ver
     * el encabezado de sql/cashflow_saldos_cuentas_fondo.sql.
     *
     * @param int $id
     * @param string $nombre
     * @param string $moneda ARS o USD
     * @param bool $activo
     * @param string $usuario
     * @param string|null $clase null deja la que tiene
     * @param array|null $inicial ['saldo' => mixed, 'fecha' => mixed], o null para no tocar
     * @return bool
     */
    public function saveCuenta($id, $nombre, $moneda, $activo, $usuario, $clase = null,
                               $inicial = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $nombre = trim((string) $nombre);
        $moneda = strtoupper(trim((string) $moneda));

        if ($nombre === '') {
            throw new Exception('La cuenta necesita un nombre');
        }

        if (mb_strlen($nombre) > 80) {
            throw new Exception('El nombre de la cuenta no puede superar los 80 caracteres');
        }

        if ($moneda !== 'ARS' && $moneda !== 'USD') {
            throw new Exception('Moneda inválida: ' . $moneda);
        }

        $cid = $this->conectar('central');

        $sets = "NOMBRE = ?, MONEDA = ?, " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", ACTIVO = ?, "
            . Auditoria::SET_MODIF;
        $args = array_merge([$nombre, $moneda], Auditoria::paramsBajaSegunEstado($activo, $usuario),
            [($activo ? 1 : 0), $usuario]);

        $tocaFondo = ($clase !== null && trim((string) $clase) !== '') || $inicial !== null;

        if ($tocaFondo) {
            if (!$this->fondosCreados()) {
                throw new Exception('Todavía no existen las clases de cuenta ni el saldo inicial: '
                    . 'corré sql/cashflow_saldos_cuentas_fondo.sql contra la base central.');
            }

            $actual = $this->cuentaPorId($id);

            $claseNueva = ($clase === null || trim((string) $clase) === '')
                ? $actual['CLASE'] : Fondos::validarClase($clase);

            if (!Fondos::cambioDeClasePermitido($actual['CLASE'], $claseNueva)) {
                throw new Exception('La cuenta "' . $actual['NOMBRE'] . '" es '
                    . Fondos::CLASES[$actual['CLASE']] . ' y no puede pasar a '
                    . Fondos::CLASES[$claseNueva] . ': su histórico no significa lo mismo. '
                    . 'Inhabilitala y creá otra.');
            }

            $sets .= ", CLASE = ?";
            $args[] = $claseNueva;

            if (Fondos::esFondo($claseNueva) && $moneda !== $actual['MONEDA']
                && $this->fondoTieneMovimientos($id)) {
                throw new Exception('La cuenta "' . $actual['NOMBRE'] . '" ya tiene movimientos '
                    . 'en ' . $actual['MONEDA'] . ': no se le puede cambiar la moneda. '
                    . 'Inhabilitala y creá otra.');
            }

            if ($inicial !== null) {
                if (!Fondos::esFondo($claseNueva)) {
                    throw new Exception('El saldo inicial es de los fondos: una cuenta '
                        . Fondos::CLASES[$claseNueva] . ' se carga como foto del saldo.');
                }

                $ini = Fondos::validarSaldoInicial(
                    isset($inicial['saldo']) ? $inicial['saldo'] : null,
                    isset($inicial['fecha']) ? $inicial['fecha'] : null
                );

                $sets .= ", SALDO_INICIAL = ?, FECHA_SALDO_INICIAL = ?";
                $args[] = ($ini === null) ? null : $ini['saldo'];
                $args[] = ($ini === null) ? null : $ini['fecha'];
            }
        }

        $args[] = intval($id);

        $stmt = sqlsrv_query($cid,
            "UPDATE RO_T_CASHFLOW_SALDOS_CUENTA SET " . $sets . " WHERE ID = ?", $args);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la cuenta'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /** Una cuenta por ID, con su clase, o excepcion */
    private function cuentaPorId($id) {
        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT ID, NOMBRE, MONEDA, TIPO, CLASE FROM RO_T_CASHFLOW_SALDOS_CUENTA WHERE ID = ?",
            [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la cuenta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('La cuenta ' . intval($id) . ' no existe');
        }

        return $row;
    }

    /** Si un fondo tiene algun movimiento, vigente o no: su moneda ya esta escrita */
    private function fondoTieneMovimientos($id) {
        $stmt = sqlsrv_query($this->conectar('central'),
            "SELECT TOP 1 ID FROM " . Fondos::TABLA_MOV . " WHERE ID_CUENTA = ?", [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los movimientos de la cuenta'));
        }

        $hay = is_array(sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC));
        sqlsrv_free_stmt($stmt);

        return $hay;
    }

    /**
     * Alta de una cuenta.
     *
     * ENTRA ACTIVA, a diferencia de un medio de pago del mix. No es una
     * inconsistencia: un medio de pago nuevo rompe el 100% de su canal, asi que
     * tiene que entrar apagado. Una cuenta no puede romper ningun invariante, y
     * ademas nace SIN SALDO CARGADO, que la pantalla muestra como "sin cargar" y
     * no como cero. O sea que no puede informar de menos en silencio.
     *
     * UN FONDO NACE CON SU SALDO INICIAL, O SIN EL. Con saldo inicial y fecha,
     * la cuenta corriente arranca de ahi; sin ellos arranca de cero y la
     * pantalla lo dice ("sin saldo inicial"), que no es lo mismo que un cero
     * informado. Una cuenta a la vista no lleva saldo inicial: se carga como
     * foto, y mandarselo se rechaza para que nadie crea que lo cargo.
     *
     * @param string $tipo BANCO, MERCADO_PAGO, EFECTIVO_CENTRAL u OTRO
     * @param string $nombre Nombre del banco o de la billetera
     * @param string $moneda ARS o USD
     * @param string $usuario
     * @param string|null $clase Una de Fondos::CLASES; null es cuenta corriente
     * @param array|null $inicial ['saldo' => mixed, 'fecha' => mixed], solo para un fondo
     * @return int ID de la cuenta creada
     */
    public function addCuenta($tipo, $nombre, $moneda, $usuario, $clase = null, $inicial = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql.');
        }

        $tipo = strtoupper(trim((string) $tipo));
        $nombre = trim((string) $nombre);
        $moneda = strtoupper(trim((string) $moneda));

        $tipos = ['BANCO', 'MERCADO_PAGO', 'EFECTIVO_CENTRAL', 'OTRO'];

        if (!in_array($tipo, $tipos, true)) {
            throw new Exception('Tipo de cuenta inválido: ' . $tipo);
        }

        if ($nombre === '') {
            throw new Exception('La cuenta necesita un nombre');
        }

        if (mb_strlen($nombre) > 80) {
            throw new Exception('El nombre de la cuenta no puede superar los 80 caracteres');
        }

        if ($moneda !== 'ARS' && $moneda !== 'USD') {
            throw new Exception('Moneda inválida: ' . $moneda);
        }

        $clase = Fondos::validarClase($clase);
        $ini = null;

        if ($clase !== Fondos::CLASE_DEFECTO || $inicial !== null) {
            if (!$this->fondosCreados()) {
                throw new Exception('Todavía no existen las clases de cuenta ni el saldo inicial: '
                    . 'corré sql/cashflow_saldos_cuentas_fondo.sql contra la base central.');
            }
        }

        if ($inicial !== null) {
            if (!Fondos::esFondo($clase)) {
                throw new Exception('El saldo inicial es de los fondos: una cuenta '
                    . Fondos::CLASES[$clase] . ' se carga como foto del saldo.');
            }

            $ini = Fondos::validarSaldoInicial(
                isset($inicial['saldo']) ? $inicial['saldo'] : null,
                isset($inicial['fecha']) ? $inicial['fecha'] : null
            );
        }

        $cid = $this->conectar('central');

        // La tabla tiene UNIQUE (TIPO, NOMBRE, MONEDA). Se chequea antes para
        // dar un mensaje entendible en lugar del error del indice, y para poder
        // decir que la cuenta existe PERO ESTA INHABILITADA, que es el caso en
        // el que hay que reactivarla y no crear otra.
        $stmt = sqlsrv_query($cid,
            "SELECT ID, ACTIVO FROM RO_T_CASHFLOW_SALDOS_CUENTA
             WHERE TIPO = ? AND NOMBRE = ? AND MONEDA = ?",
            [$tipo, $nombre, $moneda]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la cuenta'));
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($existe) {
            throw new Exception('Ya existe una cuenta "' . $nombre . '" en ' . $moneda . ' ('
                . (intval($existe['ACTIVO']) === 1 ? 'activa' : 'inhabilitada') . ')');
        }

        // El origen del dato se deduce del tipo: el efectivo de tesoreria lo
        // resuelve una consulta y el resto lo tipea una persona hasta que
        // exista la integracion con Interbanking.
        $origen = ($tipo === 'EFECTIVO_CENTRAL') ? 'CONSULTA' : 'MANUAL';

        // Sin el script de fondos la tabla no tiene CLASE: se inserta como
        // antes, y la cuenta es una cuenta a la vista porque no hay otra cosa.
        if ($this->fondosCreados()) {
            $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_CUENTA
                        (TIPO, CLASE, NOMBRE, MONEDA, ORIGEN_DATO, SALDO_INICIAL,
                         FECHA_SALDO_INICIAL, ORDEN, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                    OUTPUT INSERTED.ID
                    VALUES (?, ?, ?, ?, ?, ?, ?,
                        (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_SALDOS_CUENTA),
                        1, ?, ?)";
            $args = [$tipo, $clase, $nombre, $moneda, $origen,
                     ($ini === null) ? null : $ini['saldo'],
                     ($ini === null) ? null : $ini['fecha'], $usuario, $usuario];
        } else {
            $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_CUENTA
                        (TIPO, NOMBRE, MONEDA, ORIGEN_DATO, ORDEN, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                    OUTPUT INSERTED.ID
                    VALUES (?, ?, ?, ?,
                        (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_SALDOS_CUENTA),
                        1, ?, ?)";
            $args = [$tipo, $nombre, $moneda, $origen, $usuario, $usuario];
        }

        $stmt = sqlsrv_query($cid, $sql, $args);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la cuenta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($row['ID']);
    }

    /**
     * Guarda la grilla de Parametros -> Saldos -> Locales: gestion, reserva y
     * dia de acreditacion de cada local.
     *
     * No hay alta: la lista de locales sale del origen y se pone al dia con
     * sincronizarSucursales(). Inventar una sucursal a mano crearia una fila que
     * la consulta nunca va a llenar.
     *
     * SOLO SE ESCRIBEN LOS LOCALES QUE CAMBIARON, Y EN UNA TRANSACCION. Antes
     * cada fila de la grilla era un UPDATE con su propia conexion: cada
     * guardado sellaba a los veinte locales como editados por quien apreto el
     * boton, y una falla a mitad de camino dejaba la mitad guardada. El diff es
     * resolverParametrosLocales(), y la escritura es la misma que usa la
     * pestana 2 -guardarSucursalEnTransaccion()-: un solo escritor del
     * parametro.
     *
     * Sin sql/cashflow_saldos_dia_acreditacion.sql el dia no se escribe y el
     * resto se guarda igual; 'dia_ignorado' dice de que locales se descarto,
     * para que la respuesta pida correr el script.
     *
     * @param array $filas [['nro_sucursal', 'gestion', 'reserva', 'dia_acreditacion'?], ...]
     * @param string $usuario
     * @return array ['cambios' => int, 'dia_ignorado' => [nro, ...]]
     */
    public function guardarParametrosLocales($filas, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql.');
        }

        $conDia = $this->acreditacionCreada();
        $r = self::resolverParametrosLocales($this->getParametrosSucursales(false), $filas, $conDia);

        if (!empty($r['cambios'])) {
            $cid = $this->conectar('central');

            if (sqlsrv_begin_transaction($cid) === false) {
                throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
            }

            try {
                foreach ($r['cambios'] as $c) {
                    $this->guardarSucursalEnTransaccion($cid, $c['nro_sucursal'], $c['gestion'],
                        $c['reserva'], $c['desc_sucursal'], $usuario,
                        $conDia, $c['dia_acreditacion']);
                }

                if (sqlsrv_commit($cid) === false) {
                    throw new Exception($this->errorSql('No se pudieron confirmar los locales'));
                }
            } catch (Throwable $e) {
                sqlsrv_rollback($cid);
                throw $e;
            }
        }

        return ['cambios' => count($r['cambios']), 'dia_ignorado' => $r['dia_ignorado']];
    }

    /* ====================================================================
       PAYLOADS DE LAS DOS PESTANAS
       ==================================================================== */

    /**
     * Todo lo que necesita la pestana 1 para dibujarse.
     *
     * El efectivo central se lee EN VIVO ademas del historico: es el valor que
     * va a quedar guardado en la proxima carga, y verlo antes de guardar es lo
     * que permite controlar que la consulta este devolviendo algo razonable.
     * Si esa consulta falla, la pestana igual se dibuja con lo que hay cargado y
     * deja el aviso: una pestana que ya funciona no se cae por un origen que
     * hoy no responde.
     *
     * @return array
     */
    public function getPestanaSaldos() {
        $avisos = $this->getAvisos();

        $parametros = new Parametros();
        $map = [];

        try {
            $map = $parametros->getParametrosMap();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer los parámetros: ' . $e->getMessage();
        }

        $filas = $this->tablasCreadas() ? $this->getSaldosActuales() : [];
        $cargas = $this->getCargas(self::CARGA_SALDOS);
        $ultima = self::ultimaCarga($cargas);

        $efectivo = null;

        try {
            $efectivo = $this->getEfectivoCentral($map);
        } catch (Throwable $e) {
            $avisos[] = 'No se pudo leer el efectivo de tesorería de casa central ('
                . $e->getMessage() . '). El resto de la pestaña se muestra igual.';
        }

        $diasAlerta = isset($map[self::PARAM_DIAS_ALERTA])
            ? intval($map[self::PARAM_DIAS_ALERTA]) : 0;

        if ($this->tablasCreadas()) {
            foreach (self::avisosAntiguedad(
                $ultima === null ? null : $this->fechaHora($ultima['FECHA_CARGA']),
                $diasAlerta,
                date('Y-m-d')
            ) as $a) {
                $avisos[] = $a;
            }
        }

        foreach ($filas as $f) {
            if (!empty($f['mensaje'])) {
                $avisos[] = 'La cuenta "' . $f['nombre'] . '" quedó con un error informado por el '
                    . 'origen: ' . $f['mensaje'];
            }
        }

        return [
            'filas' => $filas,
            'totales' => self::totalesPorMoneda(array_filter($filas, function ($f) {
                return $f['cargada'];
            })),
            'ultima_carga' => $ultima === null ? null : [
                'id' => $ultima['ID'],
                'fecha_carga' => $this->fechaHora($ultima['FECHA_CARGA']),
                'origen' => $ultima['ORIGEN'],
                'observaciones' => $ultima['OBSERVACIONES'],
                'usuario' => $ultima['USUARIO']
            ],
            'cargas' => array_map(function ($c) {
                return [
                    'id' => $c['ID'],
                    'fecha_carga' => $this->fechaHora($c['FECHA_CARGA']),
                    'origen' => $c['ORIGEN'],
                    'observaciones' => $c['OBSERVACIONES'],
                    'usuario' => $c['USUARIO']
                ];
            }, $cargas),
            'efectivo_central' => $efectivo,
            'avisos' => $avisos
        ];
    }

    /**
     * Todo lo que necesita la pestana 3 -Fondos- para dibujarse: cada cuenta
     * de inversion y comitente con su saldo A HOY y el resumen de su cuenta
     * corriente, mas los totales por clase y moneda.
     *
     * LOS TOTALES NO MEZCLAN MONEDAS, igual que en la pestana 1: un fondo en
     * pesos y otro en dolares se muestran cada uno en la suya. La valuacion a
     * pesos existe solo para el tablero y la hace FondosProvider. El saldo lo
     * calcula Fondos::saldoA(), que es la misma cuenta que usa el proveedor.
     *
     * @return array
     */
    public function getPestanaFondos() {
        $avisos = $this->getAvisos();

        foreach ($this->fondos()->getAvisos() as $a) {
            $avisos[] = $a;
        }

        $cuentas = $this->fondosCreados() ? $this->fondos()->getCuentasFondo(true) : [];

        $totales = [];

        foreach ($cuentas as $c) {
            $k = $c['CLASE'] . '|' . $c['MONEDA'];

            if (!isset($totales[$k])) {
                $totales[$k] = ['clase' => $c['CLASE'], 'moneda' => $c['MONEDA'],
                                'saldo' => 0.0, 'cuentas' => 0];
            }

            $totales[$k]['saldo'] += floatval($c['saldo']);
            $totales[$k]['cuentas']++;

            if ($c['SALDO_INICIAL'] === null) {
                $avisos[] = 'La cuenta "' . $c['NOMBRE'] . '" no tiene saldo inicial: su saldo '
                    . 'arranca de cero y sólo cuenta los movimientos. Cargalo desde '
                    . 'Parámetros → Saldos.';
            }

            if (intval($c['posteriores']) > 0) {
                $avisos[] = 'La cuenta "' . $c['NOMBRE'] . '" tiene ' . $c['posteriores']
                    . ' movimiento(s) con fecha posterior a hoy: se listan pero no entran al '
                    . 'saldo de hoy ni al stock del tablero.';
            }
        }

        if ($this->fondosCreados() && empty($cuentas)) {
            $avisos[] = 'Todavía no hay ninguna cuenta de inversión ni comitente dada de alta. '
                . 'Se cargan desde Parámetros → Saldos, y el stock de cobertura del tablero va '
                . 'en cero hasta entonces.';
        }

        return [
            'cuentas' => $cuentas,
            'totales' => array_values($totales),
            'clases' => Fondos::CLASES,
            'tipos_movimiento' => array_keys(Fondos::TIPOS_MOV),
            'hoy' => date('Y-m-d'),
            'creado' => $this->fondosCreados(),
            'avisos' => $avisos
        ];
    }

    /**
     * Todo lo que necesita la pestana 2 para dibujarse.
     *
     * La consulta corre EN VIVO en cada dibujado: es una consulta contra Tango
     * que se actualiza sola todos los dias, asi que mostrar la ultima carga
     * guardada en vez del dato de hoy seria mostrar informacion vieja teniendo
     * la nueva a mano. La carga guardada existe para el historico y para dejar
     * asentada la gestion y la reserva efectivas del dia.
     *
     * @return array
     */
    public function getPestanaLocales() {
        $avisos = $this->getAvisos();
        $consulta = [];

        try {
            $consulta = $this->getSaldosLocalesOrigen();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudo leer la caja de los locales (' . $e->getMessage() . '). '
                . 'La tabla se muestra vacía y la fila Caja Locales del tablero queda en cero.';
        }

        $params = [];

        try {
            $params = $this->getParametrosSucursales(true);
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer los parámetros de las sucursales ('
                . $e->getMessage() . '): se toma Deposita con reserva cero.';
        }

        // Los saldos tipeados a mano. Sin la tabla, la pestana funciona igual
        // pero no deja tipear, y dice que script correr.
        $manuales = [];
        $manualesDisponibles = false;

        try {
            $manualesDisponibles = $this->manualesCreados();
            $manuales = $this->getSaldosLocalesManuales();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer los saldos de caja cargados a mano ('
                . $e->getMessage() . '): se muestra lo que trajo la consulta.';
        }

        if ($this->tablasCreadas() && !$manualesDisponibles) {
            $avisos[] = 'Para poder cargar a mano el saldo de caja de un local cuando la consulta '
                . 'no lo trajo, hay que correr sql/cashflow_saldos_local_manual.sql contra la '
                . 'base central.';
        }

        $hoy = date('Y-m-d');
        $ayer = self::ayer($hoy);

        // La proxima fecha de acreditacion de cada local, por el mismo camino
        // que el tablero. Sin el script, null y el aviso de cual correr.
        $contexto = $this->contextoAcreditacion($hoy);

        foreach (Aviso::textos($contexto['avisos']) as $a) {
            $avisos[] = $a;
        }

        $armado = self::armarSaldosLocales($consulta, $params, $manuales, $ayer,
            $contexto['acreditacion']);

        $cargas = $this->getCargas(self::CARGA_LOCALES);
        $ultima = self::ultimaCarga($cargas);

        return [
            'hoy' => $hoy,
            // El cierre que la consulta tendria que haber traido hoy. Las filas
            // con fecha anterior vienen marcadas con 'desactualizado'.
            'ayer' => $ayer,
            'manuales_disponibles' => $manualesDisponibles,
            // Sin el script la columna de acreditacion dice que falta
            'acreditacion_creada' => $contexto['acreditacion'] !== null,
            'filas' => $armado['filas'],
            'totales' => $armado['totales'],
            'ultima_carga' => $ultima === null ? null : [
                'id' => $ultima['ID'],
                'fecha_carga' => $this->fechaHora($ultima['FECHA_CARGA']),
                'origen' => $ultima['ORIGEN'],
                'observaciones' => $ultima['OBSERVACIONES'],
                'usuario' => $ultima['USUARIO']
            ],
            'avisos' => array_merge($avisos, $armado['avisos'])
        ];
    }

    /* ====================================================================
       ESCRITURAS

       Las dos guardan una CABECERA y despues su detalle, todo en UNA
       transaccion. Es el mismo criterio de CashflowEstructura::guardar(): una
       falla a mitad de camino dejaria una cabecera sin filas, y esa cabecera
       seria "la ultima carga" y mostraria el disponible en cero.
       ==================================================================== */

    /**
     * Guarda una carga de saldos (pestana 1).
     *
     * El detalle NO pisa nada: inserta filas nuevas colgadas de una cabecera
     * nueva. Las cargas anteriores quedan enteras.
     *
     * El saldo del efectivo central no viene del cliente aunque este en la
     * pantalla: se vuelve a leer de SBA05 en el momento de guardar. Es un dato
     * del sistema, y aceptarlo del navegador permitiria guardar cualquier cosa
     * como si fuera lo que dice la contabilidad.
     *
     * @param array $filas [['id_cuenta' => int, 'saldo' => float, 'fecha_saldo' => 'Y-m-d'], ...]
     * @param string|null $observaciones
     * @param string $usuario
     * @return int ID de la carga creada
     */
    public function guardarCargaSaldos($filas, $observaciones, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql.');
        }

        if (!is_array($filas) || empty($filas)) {
            throw new Exception('La carga no tiene ninguna cuenta');
        }

        $cuentas = $this->cuentasPorId();

        // El efectivo central lo resuelve el sistema, no el navegador.
        $efectivo = null;

        try {
            $efectivo = $this->getEfectivoCentral();
        } catch (Throwable $e) {
            $efectivo = null;
        }

        $aInsertar = [];
        $hoy = date('Y-m-d');

        foreach ($filas as $f) {
            $id = intval(isset($f['id_cuenta']) ? $f['id_cuenta'] : 0);

            if (!isset($cuentas[$id])) {
                throw new Exception('La cuenta ' . $id . ' no existe o está inhabilitada');
            }

            $cuenta = $cuentas[$id];
            $esConsulta = ($cuenta['ORIGEN_DATO'] === 'CONSULTA');

            if ($esConsulta && $efectivo === null) {
                throw new Exception('No se pudo leer el saldo de "' . $cuenta['NOMBRE'] . '" de '
                    . 'su consulta de origen, así que la carga no se guarda: guardarla dejaría '
                    . 'ese saldo en cero y el disponible quedaría informado de menos.');
            }

            $saldo = $esConsulta
                ? $efectivo['saldo']
                : floatval(isset($f['saldo']) ? $f['saldo'] : 0);

            $fecha = $esConsulta
                ? $hoy
                : Horizonte::normalizarFecha(isset($f['fecha_saldo']) ? $f['fecha_saldo'] : null);

            if ($fecha === null) {
                $fecha = $hoy;
            }

            $aInsertar[] = [
                'id_cuenta' => $id,
                'moneda' => $cuenta['MONEDA'],
                'saldo' => $saldo,
                'fecha_saldo' => $fecha,
                'origen' => $esConsulta ? 'CONSULTA' : 'MANUAL'
            ];
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            $idCarga = $this->insertarCabecera($cid, self::CARGA_SALDOS, 'MIXTA',
                $observaciones, $usuario);

            $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_DETALLE
                        (ID_CARGA, ID_CUENTA, FECHA_SALDO, MONEDA, COUNTABLE_BALANCE,
                         ORIGEN_DATO, USUARIO_ALTA, USUARIO_MODIF)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            foreach ($aInsertar as $d) {
                $params = [
                    $idCarga, $d['id_cuenta'], $d['fecha_saldo'], $d['moneda'],
                    $d['saldo'], $d['origen'], $usuario, $usuario
                ];

                if (sqlsrv_query($cid, $sql, $params) === false) {
                    throw new Exception($this->errorSql('Error al guardar el saldo de la cuenta '
                        . $d['id_cuenta']));
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la carga'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return $idCarga;
    }

    /**
     * Guarda una carga de saldos de locales (pestana 2).
     *
     * Los saldos de la consulta y sus fechas NO vienen del cliente: se vuelven
     * a leer de la consulta en el momento de guardar. Del cliente se aceptan la
     * gestion, la reserva y -solo cuando difiere de lo que manda- el saldo en
     * caja tipeado a mano, que queda como registro MANUAL fechado.
     *
     * GUARDA LAS DOS COSAS: el parametro y la foto.
     *
     *   - El PARAMETRO (RO_T_CASHFLOW_SALDOS_SUCURSAL) se actualiza con lo que
     *     el usuario dejo en la pantalla, y solo en las sucursales que
     *     efectivamente cambiaron.
     *   - La FOTO (RO_T_CASHFLOW_SALDOS_LOCAL) guarda la gestion y la reserva
     *     EFECTIVAS de esta carga.
     *
     * Que el parametro se guarde aca no es redundante con Parametros -> Saldos:
     * es el mismo dato en el mismo lugar, editable desde los dos lados. Antes
     * solo se guardaba la foto, y eso hacia que editar la reserva en esta
     * pantalla no sirviera para NADA: no persistia -al recargar volvia el valor
     * viejo- y el tablero no la veia, porque SaldosProvider lee el parametro
     * vigente y no la ultima carga. Los campos editables eran un simulador
     * disfrazado de formulario.
     *
     * La foto sigue existiendo porque la reserva no esta en Tango y el parametro
     * cambia: sin ella no se puede reconstruir que mostro el tablero un dia
     * pasado, porque recalcularlo con la reserva de hoy daria un neto que nunca
     * existio.
     *
     * LAS DOS ESCRITURAS VAN EN LA MISMA TRANSACCION. Si se separaran, una falla
     * a mitad de camino dejaria la reserva cambiada sin la foto que la explica,
     * o al revés. Por eso el parametro se escribe con guardarSucursalEnTransaccion()
     * y el $cid de la transaccion, y no con un metodo que abra su propia
     * conexion: mismo criterio que CashflowEstructura::guardar().
     *
     * EL SALDO EN CAJA TAMBIEN SE PUEDE TIPEAR, para cuando la consulta no
     * trajo el cierre. Es la tercera escritura de la misma transaccion: un
     * saldo distinto del efectivo se guarda como MANUAL con fecha de AYER -el
     * cierre que no llego-, y la foto se arma con ese saldo y ORIGEN_DATO =
     * 'MANUAL'. Que saldos son nuevos lo decide saldosManualesNuevos(), que es
     * un helper puro; sin diff, cada guardado insertaria un manual por local y
     * la consulta no volveria a mandar nunca.
     *
     * @param array $overrides [['nro_sucursal' => int, 'gestion' => str, 'reserva' => float,
     *        'saldo' => float|null], ...]
     * @param string|null $observaciones
     * @param string $usuario
     * @return array ['id' => int, 'filas' => int, 'parametros' => int, 'saldos_manuales' => int]
     */
    public function guardarCargaLocales($overrides, $observaciones, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql.');
        }

        $consulta = $this->getSaldosLocalesOrigen();

        if (empty($consulta)) {
            throw new Exception('La consulta de caja de locales no devolvió ninguna fila: '
                . 'no hay nada que guardar.');
        }

        // Los parametros vigentes son la base; lo que el usuario dejo en la
        // pantalla los reemplaza, y ademas queda guardado.
        $actuales = $this->getParametrosSucursales(true);
        $resuelto = self::resolverOverrides($actuales, $overrides);

        $params = $resuelto['params'];
        $cambios = $resuelto['cambios'];

        // La descripcion para un alta de parametro sale de la consulta, que es
        // la que conoce el nombre del local.
        $descripciones = [];

        foreach (self::agruparPorSucursal($consulta) as $nro => $s) {
            $descripciones[$nro] = $s['desc_sucursal'];
        }

        // Los saldos tipeados: primero se resuelve que manda HOY (consulta o
        // manual anterior), y contra eso se ve que saldos de la pantalla son
        // nuevos. Recien despues se arma la foto, con los manuales nuevos ya
        // aplicados: la foto tiene que describir lo que el tablero va a usar.
        $manualesDisponibles = $this->manualesCreados();
        $manuales = $manualesDisponibles ? $this->getSaldosLocalesManuales() : [];
        $ayer = self::ayer(date('Y-m-d'));

        $actual = self::armarSaldosLocales($consulta, $params, $manuales, $ayer);
        $nuevos = self::saldosManualesNuevos($actual['filas'], $overrides);

        if (!empty($nuevos) && !$manualesDisponibles) {
            throw new Exception('Para guardar un saldo de caja cargado a mano hay que correr '
                . 'sql/cashflow_saldos_local_manual.sql contra la base central. La gestión y la '
                . 'reserva no se guardaron: corregí el saldo o corré el script y volvé a guardar.');
        }

        foreach ($nuevos as $n) {
            $manuales[$n['nro_sucursal']] = [
                'NRO_SUCURSAL' => $n['nro_sucursal'],
                'FECHA_SALDO' => $ayer,
                'SALDO_MONEDA' => $n['saldo'],
                'FECHA_UPDATE' => null,
                'USUARIO' => $usuario
            ];
        }

        $armado = self::armarSaldosLocales($consulta, $params, $manuales, $ayer);

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            foreach ($cambios as $c) {
                $this->guardarSucursalEnTransaccion(
                    $cid,
                    $c['nro_sucursal'],
                    $c['gestion'],
                    $c['reserva'],
                    isset($descripciones[$c['nro_sucursal']])
                        ? $descripciones[$c['nro_sucursal']] : ('Local ' . $c['nro_sucursal']),
                    $usuario
                );
            }

            $sqlManual = "INSERT INTO RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL
                              (NRO_SUCURSAL, FECHA_SALDO, SALDO_MONEDA, SALDO_CONSULTA,
                               FECHA_CONSULTA, OBSERVACIONES, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                          VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)";

            foreach ($nuevos as $n) {
                $ok = sqlsrv_query($cid, $sqlManual, [
                    $n['nro_sucursal'], $ayer, $n['saldo'], $n['saldo_consulta'],
                    $n['fecha_consulta'], $observaciones, $usuario, $usuario
                ]);

                if ($ok === false) {
                    throw new Exception($this->errorSql('Error al guardar el saldo manual del '
                        . 'local ' . $n['nro_sucursal']));
                }
            }

            $idCarga = $this->insertarCabecera($cid, self::CARGA_LOCALES,
                empty($nuevos) ? 'CONSULTA' : 'MIXTA', $observaciones, $usuario);

            // ORIGEN_DATO existe recien con la migracion de manuales. Sin ella
            // la foto se guarda como siempre: todo lo que hay es de la consulta.
            $sql = $manualesDisponibles
                ? "INSERT INTO RO_T_CASHFLOW_SALDOS_LOCAL
                       (ID_CARGA, NRO_SUCURSAL, DESC_SUCURSAL, FECHA_SALDO,
                        COD_CTA_CUENTA_TESORERIA, CUENTAS, SALDO_MONEDA,
                        GESTION, RESERVA, NETO_DEPOSITAR, USUARIO_ALTA, USUARIO_MODIF, ORIGEN_DATO)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                : "INSERT INTO RO_T_CASHFLOW_SALDOS_LOCAL
                       (ID_CARGA, NRO_SUCURSAL, DESC_SUCURSAL, FECHA_SALDO,
                        COD_CTA_CUENTA_TESORERIA, CUENTAS, SALDO_MONEDA,
                        GESTION, RESERVA, NETO_DEPOSITAR, USUARIO_ALTA, USUARIO_MODIF)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            foreach ($armado['filas'] as $f) {
                $valores = [
                    $idCarga,
                    $f['nro_sucursal'],
                    $f['desc_sucursal'],
                    $f['fecha_saldo'],
                    substr($f['cod_cta'], 0, 60),
                    $f['cuentas'],
                    $f['saldo'],
                    $f['gestion'],
                    $f['reserva'],
                    // Se guarda el neto REAL, negativo incluido: es informacion
                    // de la sucursal. El recorte a cero es del aporte al
                    // cashflow, no del dato.
                    $f['neto'],
                    $usuario,
                    $usuario
                ];

                if ($manualesDisponibles) {
                    $valores[] = $f['origen_saldo'];
                }

                if (sqlsrv_query($cid, $sql, $valores) === false) {
                    throw new Exception($this->errorSql('Error al guardar el local '
                        . $f['nro_sucursal']));
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la carga'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return [
            'id' => $idCarga,
            'filas' => count($armado['filas']),
            'parametros' => count($cambios),
            'saldos_manuales' => count($nuevos)
        ];
    }

    /**
     * Escribe el parametro de una sucursal con la conexion de una transaccion en
     * curso.
     *
     * Es un UPSERT: si la sucursal todavia no tiene fila de parametro -porque
     * nadie corrio la sincronizacion- se crea. La alternativa seria que el
     * UPDATE no afectara ninguna fila y el cambio se perdiera en silencio, que
     * es peor: el local salio de la consulta, o sea que existe.
     *
     * Es EL UNICO ESCRITOR DEL PARAMETRO: lo usan la carga de la pestana 2 y
     * el guardado de Parametros -> Saldos -> Locales. Recibe la conexion de la
     * transaccion de quien lo llama (Conexion::conectar() abre una nueva en
     * cada llamada, asi que abrir la suya lo dejaria afuera).
     *
     * EL DIA DE ACREDITACION SE ESCRIBE SOLO SI $escribirDia: la pestana 2 no
     * lo edita y no lo toca, y sin el script la columna no existe. Un alta por
     * la pestana 2 entra sin dia, igual que un local nuevo de la
     * sincronizacion.
     *
     * @param resource $cid Conexion con la transaccion abierta
     * @param int $nro
     * @param string $gestion Ya validada
     * @param float $reserva Ya validada
     * @param string $descripcion Nombre del local, para el caso de alta
     * @param string $usuario
     * @param bool $escribirDia
     * @param int|null $dia Ya validado; solo cuenta con $escribirDia
     */
    private function guardarSucursalEnTransaccion($cid, $nro, $gestion, $reserva,
                                                  $descripcion, $usuario,
                                                  $escribirDia = false, $dia = null) {
        $setDia = $escribirDia ? 'DIA_ACREDITACION = ?, ' : '';
        $valoresDia = $escribirDia ? [$dia] : [];

        $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                SET GESTION = ?, RESERVA = ?, " . $setDia . Auditoria::SET_MODIF . "
                WHERE NRO_SUCURSAL = ?";

        $stmt = sqlsrv_query($cid, $sql,
            array_merge([$gestion, $reserva], $valoresDia, [$usuario, $nro]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la gestión del local ' . $nro));
        }

        $afectadas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($afectadas > 0) {
            return;
        }

        $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_SUCURSAL
                    (NRO_SUCURSAL, DESC_SUCURSAL, GESTION, RESERVA, ACTIVO,
                     " . ($escribirDia ? 'DIA_ACREDITACION, ' : '') . "USUARIO_ALTA, USUARIO_MODIF)
                VALUES (?, ?, ?, ?, 1, " . ($escribirDia ? '?, ' : '') . "?, ?)";

        if (sqlsrv_query($cid, $sql, array_merge([$nro, $descripcion, $gestion, $reserva],
            $valoresDia, [$usuario, $usuario])) === false) {
            throw new Exception($this->errorSql('Error al crear el parámetro del local ' . $nro));
        }
    }

    /**
     * Sincroniza el parametro por sucursal con la lista de locales propios.
     *
     * NO PISA GESTION, RESERVA NI DIA_ACREDITACION de una sucursal que ya
     * existe: son valores que cargo una persona. Sus UPDATE no nombran esas
     * columnas, y el INSERT de un local nuevo no nombra el dia: entra SIN DIA,
     * porque no hay default que adivinar. Una prueba lo verifica sobre el
     * codigo. Y no borra: una
     * sucursal que desaparece del origen queda con ACTIVO = 0 y su historico
     * intacto.
     *
     * LO DISPARA UNA PERSONA desde Parametros -> Saldos, asi que las altas, las
     * bajas y las reactivaciones quedan con su usuario, no con un origen SISTEMA:.
     *
     * @param string $usuario
     * @return array ['altas' => int, 'bajas' => int, 'reactivadas' => int]
     */
    public function sincronizarSucursales($usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Saldos. '
                . 'Corré sql/cashflow_saldos.sql.');
        }

        $origen = $this->getSucursalesOrigen();

        if (empty($origen)) {
            throw new Exception('La consulta de sucursales no devolvió ninguna fila');
        }

        $actuales = $this->getParametrosSucursales(false);
        $cid = $this->conectar('central');

        $resultado = ['altas' => 0, 'bajas' => 0, 'reactivadas' => 0];
        $enOrigen = [];

        foreach ($origen as $s) {
            $nro = $s['NRO_SUCURSAL'];
            $enOrigen[$nro] = true;

            if (!isset($actuales[$nro])) {
                $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_SUCURSAL
                            (NRO_SUCURSAL, DESC_SUCURSAL, GESTION, RESERVA, ACTIVO,
                             USUARIO_ALTA, USUARIO_MODIF)
                        VALUES (?, ?, ?, 0, 1, ?, ?)";

                if (sqlsrv_query($cid, $sql,
                    [$nro, $s['DESC_SUCURSAL'], self::DEPOSITA, $usuario, $usuario]) === false) {
                    throw new Exception($this->errorSql('Error al dar de alta la sucursal ' . $nro));
                }

                $resultado['altas']++;
                continue;
            }

            // Solo se refrescan la descripcion y la reactivacion. GESTION y
            // RESERVA son del usuario y no se tocan nunca.
            $reactiva = (intval($actuales[$nro]['ACTIVO']) !== 1);

            $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                    SET DESC_SUCURSAL = ?, " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", ACTIVO = 1,
                        " . Auditoria::SET_MODIF . "
                    WHERE NRO_SUCURSAL = ?";

            if (sqlsrv_query($cid, $sql, array_merge([$s['DESC_SUCURSAL']],
                Auditoria::paramsBajaSegunEstado(true, $usuario), [$usuario, $nro])) === false) {
                throw new Exception($this->errorSql('Error al actualizar la sucursal ' . $nro));
            }

            if ($reactiva) {
                $resultado['reactivadas']++;
            }
        }

        foreach ($actuales as $nro => $a) {
            if (isset($enOrigen[$nro]) || intval($a['ACTIVO']) !== 1) {
                continue;
            }

            $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                    SET ACTIVO = 0, " . Auditoria::SET_BAJA . "
                    WHERE NRO_SUCURSAL = ?";

            if (sqlsrv_query($cid, $sql, [$usuario, $usuario, $nro]) === false) {
                throw new Exception($this->errorSql('Error al inhabilitar la sucursal ' . $nro));
            }

            $resultado['bajas']++;
        }

        return $resultado;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Inserta la cabecera de una carga y devuelve su ID */
    private function insertarCabecera($cid, $tipo, $origen, $observaciones, $usuario) {
        $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_CARGA
                    (TIPO, FECHA_CARGA, ORIGEN, OBSERVACIONES, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                OUTPUT INSERTED.ID
                VALUES (?, GETDATE(), ?, ?, 1, ?, ?)";

        $obs = trim((string) $observaciones);

        $stmt = sqlsrv_query($cid, $sql, [
            $tipo, $origen, ($obs === '' ? null : substr($obs, 0, 500)), $usuario, $usuario
        ]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la carga'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($row['ID']);
    }

    /** Cuentas activas indexadas por ID, para validar lo que llega del cliente */
    private function cuentasPorId() {
        $cid = $this->conectar('central');

        $sql = "SELECT ID, NOMBRE, MONEDA, TIPO, ORIGEN_DATO
                FROM RO_T_CASHFLOW_SALDOS_CUENTA WHERE ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las cuentas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[intval($row['ID'])] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Prefijo de las tablas del servidor de locales.
     *
     * En ENV=DEV las tablas de locales se alcanzan por linked server, con el
     * nombre de cuatro partes que arma Conexion. En PROD el prefijo es vacio y
     * la consulta va directo contra la base. Es la misma regla que ya aplica
     * class/conexion.php y que usan los SP del modulo de Ventas.
     *
     * @return string
     */
    private function prefijoLocales() {
        return method_exists($this->conn, 'prefijoLocales')
            ? $this->conn->prefijoLocales()
            : '';
    }

    /** @param string $servidor @return resource Conexion, con el error ya traducido */
    private function conectar($servidor) {
        $cid = $this->conn->conectar($servidor);

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos (' . $servidor . ')');
        }

        return $cid;
    }

    /** @return float|null Un DECIMAL de sqlsrv, o null si la columna vino nula */
    private function numeroONull($v) {
        return ($v === null) ? null : floatval($v);
    }

    /** @return string|null 'Y-m-d H:i:s' de lo que devuelve sqlsrv para un DATETIME */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
    }

    /** Formato de importe para los mensajes de aviso */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** 'Y-m-d...' => 'd/m/Y', para los mensajes */
    private static function fechaCorta($fecha) {
        if ($fecha === null || $fecha === '') {
            return 'sin fecha';
        }

        return date('d/m/Y', strtotime(substr((string) $fecha, 0, 10)));
    }

    /** 'Y-m-d' => 'dd/mm', para la celda y el tooltip de la acreditacion */
    private static function diaMes($fecha) {
        return substr((string) $fecha, 8, 2) . '/' . substr((string) $fecha, 5, 2);
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
