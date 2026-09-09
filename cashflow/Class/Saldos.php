<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Parametros.php';

/**
 * Saldos
 * Disponible inicial de la empresa: efectivo de tesoreria de casa central,
 * saldos bancarios, Mercado Pago y la caja de los locales propios.
 *
 * LAS DOS PESTANAS SON DOS COSAS DISTINTAS
 * ----------------------------------------
 *   Pestana 1 "Saldos"        -> alimenta la fila DISPONIBLE (Saldo Inicial).
 *                                Carga PERIODICA (hoy, los lunes) y en parte
 *                                manual. El historico lo construye este modulo.
 *   Pestana 2 "Saldos Locales" -> alimenta la fila CAJA_LOCALES. Sale de una
 *                                consulta contra el servidor 'locales' que corre
 *                                todos los dias.
 *
 * Por eso las cargas llevan TIPO: son dos procesos con dos cadencias, y "la
 * ultima carga" tiene que poder responderse por separado para cada uno.
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
 * SI LAS TABLAS NO EXISTEN
 * ------------------------
 * Las lecturas devuelven vacio y getAvisos() dice que hay que correr
 * sql/cashflow_saldos.sql, en vez de romper. Mismo criterio que
 * CashflowEstructura.
 */
class Saldos {

    /** Tipos de carga. Separan las dos pestanas dentro de la misma cabecera. */
    const CARGA_SALDOS = 'SALDOS';
    const CARGA_LOCALES = 'LOCALES';

    /** Gestion del efectivo de una sucursal */
    const DEPOSITA = 'DEPOSITA';
    const ENVIA = 'ENVIA';

    /** Cuenta contable de SBA05 con el efectivo de tesoreria de casa central */
    const PARAM_CTA_TESORERIA = 'saldos_cta_tesoreria';

    /** Dias desde la ultima carga a partir de los cuales se avisa */
    const PARAM_DIAS_ALERTA = 'saldos_dias_alerta_carga';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de las tablas */
    private $tablas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
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
     *        FECHA, COD_CTA_CUENTA_TESORERIA, SALDO_MONEDA
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
            $cta = trim((string) (isset($f['COD_CTA_CUENTA_TESORERIA'])
                ? $f['COD_CTA_CUENTA_TESORERIA'] : ''));
            $saldo = floatval(isset($f['SALDO_MONEDA']) ? $f['SALDO_MONEDA'] : 0);

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
     * @param array $filasConsulta Filas crudas de la consulta de locales
     * @param array $params Mapa NRO_SUCURSAL => ['GESTION' => ..., 'RESERVA' => ...]
     * @return array ['filas' => [...], 'totales' => [...], 'avisos' => [...]]
     */
    public static function armarSaldosLocales($filasConsulta, $params) {
        $agrupadas = self::agruparPorSucursal($filasConsulta);
        $params = is_array($params) ? $params : [];

        $filas = [];
        $avisos = [];
        $sinParametro = [];

        $totales = [
            'saldo' => 0,
            'reserva' => 0,
            'neto' => 0,
            'aporta' => 0,
            'sucursales' => 0,
            'depositan' => 0,
            'envian' => 0
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
                $avisos[] = 'El local ' . $nro . ' ' . $s['desc_sucursal'] . ' tiene la caja por '
                    . 'debajo de su reserva (' . self::plata($neto) . '): no aporta al cashflow, '
                    . 'pero tampoco resta.';
            }

            $filas[] = [
                'nro_sucursal' => $nro,
                'desc_sucursal' => $s['desc_sucursal'],
                'local' => $nro . ' ' . $s['desc_sucursal'],
                'fecha_saldo' => $s['fecha_saldo'],
                'cod_cta' => implode(', ', $s['cod_cta']),
                'cuentas' => $s['cuentas'],
                'saldo' => $s['saldo'],
                'gestion' => $gestion,
                'reserva' => $reserva,
                'neto' => $neto,
                // Lo que efectivamente entra a la serie del Cashflow. Se expone
                // aparte de 'neto' para que la pantalla pueda mostrar los dos y
                // se vea POR QUE un neto de -50.000 aporta cero.
                'aporta' => $aporta,
                'sin_parametro' => !$tieneParam
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
        }

        // Orden estable por numero de sucursal, como la consulta de origen
        usort($filas, function ($a, $b) {
            return $a['nro_sucursal'] - $b['nro_sucursal'];
        });

        if (!empty($sinParametro)) {
            sort($sinParametro);

            $avisos[] = 'Estos locales todavía no tienen gestión ni reserva configuradas y se '
                . 'están tomando como Deposita con reserva cero: ' . implode(', ', $sinParametro)
                . '. Configuralos en Parámetros → Saldos.';
        }

        if ($totales['envian'] > 0) {
            $avisos[] = $totales['envian'] . ' local(es) están en Envía: se muestran en la tabla '
                . 'pero su efectivo no entra al cashflow, porque no llega al banco por esta vía.';
        }

        return ['filas' => $filas, 'totales' => $totales, 'avisos' => $avisos];
    }

    /**
     * Serie del Cashflow para la caja de locales.
     *
     * LA FECHA DE IMPUTACION ES LA FECHA DEL SALDO, SIN CORRIMIENTOS. No hay
     * regla de dia habil ni calendario de feriados: cuando la sucursal deposita,
     * el movimiento queda registrado en Tango, y como la consulta corre todos
     * los dias el dato se actualiza solo. Un saldo de domingo se imputa el
     * domingo; correrlo al lunes inventaria una fecha que el sistema ya conoce.
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
     * Reubicar NO es el corrimiento que el relevamiento prohibe: eso era mover
     * una fecha DENTRO del eje a otra por dia habil o feriado, y no se hace. El
     * aviso dice de que fecha es el saldo, para que nadie lo lea como de hoy.
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
            $serie['warnings'][] = 'Caja Locales: ' . self::plata($reubicado) . ' salen del último '
                . 'saldo de caja registrado, del ' . self::fechaCorta($fechaMasVieja) . ', y se '
                . 'imputan en la primera columna del horizonte. Es plata que todavía está en el '
                . 'local y que no llegó al banco.';
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

            $gestion = strtoupper(trim((string) (isset($o['gestion']) ? $o['gestion'] : '')));

            if ($gestion !== self::DEPOSITA && $gestion !== self::ENVIA) {
                throw new Exception('Gestión inválida para el local ' . $nro . ': "' . $gestion
                    . '". Sólo puede ser Deposita o Envía.');
            }

            $reserva = floatval(isset($o['reserva']) ? $o['reserva'] : 0);

            if ($reserva < 0) {
                throw new Exception('La reserva del local ' . $nro . ' no puede ser negativa');
            }

            $antes = isset($params[$nro]) ? $params[$nro] : null;

            $distinto = ($antes === null)
                || (strtoupper(trim((string) $antes['GESTION'])) !== $gestion)
                || (abs(floatval($antes['RESERVA']) - $reserva) > 0.0001);

            $params[$nro] = [
                'NRO_SUCURSAL' => $nro,
                'GESTION' => $gestion,
                'RESERVA' => $reserva
            ];

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
     * Columna del eje en la que hay que imputar un importe fechado.
     *
     * El eje arranca HOY, asi que una fecha anterior no tiene columna propia.
     * Las dos series de este modulo describen PLATA QUE EXISTE AHORA -un saldo
     * bancario, el efectivo de un cajon-, no movimientos ya ocurridos, asi que
     * una fecha pasada significa "esto ya es cierto hoy" y va a la apertura del
     * horizonte. Descartarla mostraria cero teniendo el dato.
     *
     * Una fecha que SI cae dentro del eje no se toca nunca: no hay corrimiento
     * a dia habil ni tratamiento de feriados en ninguna de las dos series.
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

        if ($reubicado != 0) {
            $avisos[] = 'Saldo Inicial: ' . self::plata($reubicado) . ' corresponden a saldos '
                . 'cargados el ' . self::fechaCorta($fechaMasVieja) . ' o antes y se muestran en '
                . 'la primera columna, que es la apertura del horizonte. Actualizá la carga de '
                . 'saldos para que el disponible sea el de hoy.';
        }

        if ($usdSinCotizar != 0) {
            $avisos[] = 'Saldo Inicial: US$ ' . number_format($usdSinCotizar, 2, ',', '.')
                . ' no se pudieron valuar porque falta la cotización del mes, así que no entran '
                . 'al tablero. La pestaña Saldos los muestra igual, en dólares.';
        }

        // 'tipo_cambio' informa con cual se convirtio. Con un solo mes en juego
        // -que es el caso normal, porque el saldo es de una fecha- es ese; con
        // varios se deja null y el detalle queda en el aviso, en vez de mostrar
        // uno cualquiera como si hubiera sido el unico.
        if (count($tiposUsados) === 1) {
            $serie['tipo_cambio'] = array_values($tiposUsados)[0];
        } elseif (count($tiposUsados) > 1) {
            $avisos[] = 'Saldo Inicial: los saldos en dólares se valuaron con la cotización de '
                . 'cierre de cada mes (' . implode(', ', array_keys($tiposUsados)) . ').';
        }

        return self::resultadoDisponible($serie, $avisos, $reubicado, $usdSinCotizar);
    }

    /** Empaqueta el resultado de armarSerieDisponible() */
    private static function resultadoDisponible($serie, $avisos, $reubicado, $usdSinCotizar) {
        return [
            'serie' => $serie,
            'avisos' => $avisos,
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
     * @return array Filas listas para la pantalla
     */
    public function getSaldosActuales() {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "WITH Ultimo AS (
                    SELECT D.ID_CUENTA, D.FECHA_SALDO, D.MONEDA, D.COUNTABLE_BALANCE,
                           D.INITIAL_OPERATING_BALANCE, D.CURRENT_OPERATING_BALANCE,
                           D.PROJECTED_BALANCE_24HS, D.PROJECTED_BALANCE_48HS,
                           D.DAY_BALANCE, D.TOTAL_DEBITS, D.TOTAL_CREDITS,
                           D.MESSAGE, D.ORIGEN_DATO,
                           C.ID AS ID_CARGA, C.FECHA_CARGA, C.USUARIO AS USUARIO_CARGA,
                           ROW_NUMBER() OVER (
                               PARTITION BY D.ID_CUENTA
                               ORDER BY D.FECHA_SALDO DESC, C.FECHA_CARGA DESC, D.ID DESC
                           ) AS RN
                    FROM RO_T_CASHFLOW_SALDOS_DETALLE D
                    INNER JOIN RO_T_CASHFLOW_SALDOS_CARGA C
                        ON C.ID = D.ID_CARGA AND C.ACTIVO = 1 AND C.TIPO = ?
                )
                SELECT CU.ID, CU.TIPO, CU.NOMBRE, CU.MONEDA, CU.ORIGEN_DATO AS ORIGEN_CUENTA,
                       CU.BANK_ID, CU.BANK_NAME, CU.ACCOUNT_NUMBER, CU.ACCOUNT_TYPE,
                       CU.CBU, CU.ACCOUNT_LABEL, CU.ORDEN,
                       U.FECHA_SALDO, U.COUNTABLE_BALANCE, U.INITIAL_OPERATING_BALANCE,
                       U.CURRENT_OPERATING_BALANCE, U.PROJECTED_BALANCE_24HS,
                       U.PROJECTED_BALANCE_48HS, U.DAY_BALANCE, U.TOTAL_DEBITS,
                       U.TOTAL_CREDITS, U.MESSAGE, U.ORIGEN_DATO,
                       U.ID_CARGA, U.FECHA_CARGA, U.USUARIO_CARGA
                FROM RO_T_CASHFLOW_SALDOS_CUENTA CU
                LEFT JOIN Ultimo U ON U.ID_CUENTA = CU.ID AND U.RN = 1
                WHERE CU.ACTIVO = 1
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

        $sql = "SELECT TOP (?) ID, TIPO, FECHA_CARGA, ORIGEN, OBSERVACIONES, USUARIO
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
     * Devuelve una fila por (sucursal, cuenta de tesoreria) con el ultimo saldo
     * de cada una. La consolidacion por sucursal la hace agruparPorSucursal().
     *
     * @return array Filas crudas de la consulta
     */
    public function getSaldosLocalesOrigen() {
        $cid = $this->conectar('locales');
        $p = $this->prefijoLocales();

        $sql = "WITH SaldoLocales AS (
                    SELECT A.ID, A.FECHA, A.NRO_SUCURSAL, B.DESC_SUCURSAL,
                           A.COD_CTA_CUENTA_TESORERIA, A.SALDO_MONEDA,
                           ROW_NUMBER() OVER (
                               PARTITION BY A.NRO_SUCURSAL, A.COD_CTA_CUENTA_TESORERIA
                               ORDER BY A.FECHA DESC, A.ID DESC
                           ) AS RN
                    FROM {$p}RO_T_SALDO_CAJA_SUCURSALES A
                    INNER JOIN {$p}SUCURSALES_LAKERS B ON A.NRO_SUCURSAL = B.NRO_SUCURSAL
                    WHERE B.CANAL = 'PROPIOS' AND B.HABILITADO = 1
                )
                SELECT ID, FECHA, NRO_SUCURSAL, DESC_SUCURSAL,
                       COD_CTA_CUENTA_TESORERIA, SALDO_MONEDA
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
     * Parametros de gestion y reserva por sucursal, indexados por numero.
     *
     * @param bool $soloActivas
     * @return array Mapa NRO_SUCURSAL => fila
     */
    public function getParametrosSucursales($soloActivas = true) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT NRO_SUCURSAL, DESC_SUCURSAL, GESTION, RESERVA, ACTIVO,
                       FECHA_UPDATE, USUARIO
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

        $sql = "SELECT ID, TIPO, NOMBRE, MONEDA, ORIGEN_DATO,
                       BANK_ID, BANK_NAME, ACCOUNT_NUMBER, ACCOUNT_TYPE, CBU, ACCOUNT_LABEL,
                       ORDEN, ACTIVO, FECHA_UPDATE, USUARIO
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
     * @param int $id
     * @param string $nombre
     * @param string $moneda ARS o USD
     * @param bool $activo
     * @param string|null $usuario
     * @return bool
     */
    public function saveCuenta($id, $nombre, $moneda, $activo = true, $usuario = null) {
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

        $sql = "UPDATE RO_T_CASHFLOW_SALDOS_CUENTA
                SET NOMBRE = ?, MONEDA = ?, ACTIVO = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ?";

        $stmt = sqlsrv_query($cid, $sql,
            [$nombre, $moneda, ($activo ? 1 : 0), $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la cuenta'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
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
     * @param string $tipo BANCO, MERCADO_PAGO, EFECTIVO_CENTRAL u OTRO
     * @param string $nombre Nombre del banco o de la billetera
     * @param string $moneda ARS o USD
     * @param string|null $usuario
     * @return int ID de la cuenta creada
     */
    public function addCuenta($tipo, $nombre, $moneda, $usuario = null) {
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

        $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_CUENTA
                    (TIPO, NOMBRE, MONEDA, ORIGEN_DATO, ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?,
                    (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_SALDOS_CUENTA),
                    1, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [$tipo, $nombre, $moneda, $origen, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la cuenta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($row['ID']);
    }

    /**
     * Edita la gestion y la reserva de una sucursal.
     *
     * No hay alta: la lista de locales sale del origen y se pone al dia con
     * sincronizarSucursales(). Inventar una sucursal a mano crearia una fila que
     * la consulta nunca va a llenar.
     *
     * @param int $nroSucursal
     * @param string $gestion DEPOSITA o ENVIA
     * @param float $reserva Minimo que la sucursal conserva en caja
     * @param string|null $usuario
     * @return bool
     */
    public function saveSucursal($nroSucursal, $gestion, $reserva, $usuario = null) {
        $gestion = strtoupper(trim((string) $gestion));

        if ($gestion !== self::DEPOSITA && $gestion !== self::ENVIA) {
            throw new Exception('Gestión inválida: "' . $gestion . '". '
                . 'Sólo puede ser Deposita o Envía.');
        }

        $reserva = floatval($reserva);

        if ($reserva < 0) {
            throw new Exception('La reserva de caja no puede ser negativa');
        }

        $cid = $this->conectar('central');

        $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                SET GESTION = ?, RESERVA = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE NRO_SUCURSAL = ?";

        $stmt = sqlsrv_query($cid, $sql, [$gestion, $reserva, $usuario, intval($nroSucursal)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la sucursal'));
        }

        sqlsrv_free_stmt($stmt);

        return true;
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

        $armado = self::armarSaldosLocales($consulta, $params);

        $cargas = $this->getCargas(self::CARGA_LOCALES);
        $ultima = self::ultimaCarga($cargas);

        return [
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
     * @param string|null $usuario
     * @return int ID de la carga creada
     */
    public function guardarCargaSaldos($filas, $observaciones, $usuario = null) {
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
                         ORIGEN_DATO, FECHA_UPDATE, USUARIO)
                    VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?)";

            foreach ($aInsertar as $d) {
                $params = [
                    $idCarga, $d['id_cuenta'], $d['fecha_saldo'], $d['moneda'],
                    $d['saldo'], $d['origen'], $usuario
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
     * Los saldos y las fechas NO vienen del cliente: se vuelven a leer de la
     * consulta en el momento de guardar. Del cliente se aceptan unicamente la
     * gestion y la reserva, que son los dos valores editables.
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
     * o al revés. Por eso el parametro se escribe con el $cid de la transaccion
     * y no llamando a saveSucursal(), que abre su propia conexion: mismo
     * criterio que CashflowEstructura::guardar().
     *
     * @param array $overrides [['nro_sucursal' => int, 'gestion' => str, 'reserva' => float], ...]
     * @param string|null $observaciones
     * @param string|null $usuario
     * @return array ['id' => int, 'filas' => int, 'parametros' => int]
     */
    public function guardarCargaLocales($overrides, $observaciones, $usuario = null) {
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

        $armado = self::armarSaldosLocales($consulta, $params);

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

            $idCarga = $this->insertarCabecera($cid, self::CARGA_LOCALES, 'CONSULTA',
                $observaciones, $usuario);

            $sql = "INSERT INTO RO_T_CASHFLOW_SALDOS_LOCAL
                        (ID_CARGA, NRO_SUCURSAL, DESC_SUCURSAL, FECHA_SALDO,
                         COD_CTA_CUENTA_TESORERIA, CUENTAS, SALDO_MONEDA,
                         GESTION, RESERVA, NETO_DEPOSITAR, FECHA_UPDATE, USUARIO)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)";

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
                    $usuario
                ];

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
            'parametros' => count($cambios)
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
     * No reusa saveSucursal() a proposito: ese metodo abre su propia conexion
     * (Conexion::conectar() abre una nueva en cada llamada) y quedaria FUERA de
     * la transaccion de la carga.
     *
     * @param resource $cid Conexion con la transaccion abierta
     * @param int $nro
     * @param string $gestion Ya validada
     * @param float $reserva Ya validada
     * @param string $descripcion Nombre del local, para el caso de alta
     * @param string|null $usuario
     */
    private function guardarSucursalEnTransaccion($cid, $nro, $gestion, $reserva,
                                                  $descripcion, $usuario) {
        $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                SET GESTION = ?, RESERVA = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE NRO_SUCURSAL = ?";

        $stmt = sqlsrv_query($cid, $sql, [$gestion, $reserva, $usuario, $nro]);

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
                     FECHA_UPDATE, USUARIO)
                VALUES (?, ?, ?, ?, 1, GETDATE(), ?)";

        if (sqlsrv_query($cid, $sql,
            [$nro, $descripcion, $gestion, $reserva, $usuario]) === false) {
            throw new Exception($this->errorSql('Error al crear el parámetro del local ' . $nro));
        }
    }

    /**
     * Sincroniza el parametro por sucursal con la lista de locales propios.
     *
     * NO PISA GESTION NI RESERVA de una sucursal que ya existe: son valores que
     * cargo una persona. Y no borra: una sucursal que desaparece del origen
     * queda con ACTIVO = 0 y su historico intacto.
     *
     * @param string|null $usuario
     * @return array ['altas' => int, 'bajas' => int, 'reactivadas' => int]
     */
    public function sincronizarSucursales($usuario = null) {
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
                             FECHA_UPDATE, USUARIO)
                        VALUES (?, ?, ?, 0, 1, GETDATE(), ?)";

                if (sqlsrv_query($cid, $sql,
                    [$nro, $s['DESC_SUCURSAL'], self::DEPOSITA, $usuario]) === false) {
                    throw new Exception($this->errorSql('Error al dar de alta la sucursal ' . $nro));
                }

                $resultado['altas']++;
                continue;
            }

            // Solo se refrescan la descripcion y la reactivacion. GESTION y
            // RESERVA son del usuario y no se tocan nunca.
            $reactiva = (intval($actuales[$nro]['ACTIVO']) !== 1);

            $sql = "UPDATE RO_T_CASHFLOW_SALDOS_SUCURSAL
                    SET DESC_SUCURSAL = ?, ACTIVO = 1, FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE NRO_SUCURSAL = ?";

            if (sqlsrv_query($cid, $sql, [$s['DESC_SUCURSAL'], $usuario, $nro]) === false) {
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
                    SET ACTIVO = 0, FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE NRO_SUCURSAL = ?";

            if (sqlsrv_query($cid, $sql, [$usuario, $nro]) === false) {
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
                    (TIPO, FECHA_CARGA, ORIGEN, OBSERVACIONES, ACTIVO, FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, GETDATE(), ?, ?, 1, GETDATE(), ?)";

        $obs = trim((string) $observaciones);

        $stmt = sqlsrv_query($cid, $sql, [
            $tipo, $origen, ($obs === '' ? null : substr($obs, 0, 500)), $usuario
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
