<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * CobElectronicos
 * Acreditaciones que las procesadoras de pago (Payway, Mercado Pago) van a
 * depositar en nuestro banco: importe bruto informado, fecha de acreditacion, y
 * el neto que efectivamente entra despues de las retenciones impositivas de la
 * procesadora.
 *
 *     importe neto = importe bruto * (1 - tasa de retencion vigente)
 *
 * La tasa sale de la suma de las alicuotas vigentes de esa procesadora A LA
 * FECHA DE ACREDITACION del movimiento, no a la fecha de carga.
 *
 * EL NETO NUNCA SE TIPEA
 * ----------------------
 * Lo calcula el servidor al guardar y queda PERSISTIDO junto con la
 * TASA_APLICADA. Un movimiento no puede quedar sin neto: si la procesadora no
 * tiene alicuotas vigentes a esa fecha, el alta se RECHAZA con el motivo.
 *
 * Es el arreglo de las dos mitades del problema del Excel:
 *
 *   1. En la hoja, D8:D43 tienen *0.969 ESCRITO A MANO y solo las filas vacias
 *      usan $D$3, asi que cambiar D3 no recalcula ni un movimiento. Y D6, D7 y
 *      D24 no tienen formula: nunca calcularon neto, con 1.648.264,10 /
 *      1.144.017,00 / 43.150.368,26 de bruto.
 *   2. Editar un porcentaje no reescribe lo ya guardado: los movimientos
 *      conservan la tasa con la que se calcularon.
 *
 * EDITAR UNA ALICUOTA ES INSERTAR UNA VIGENCIA, NO UN UPDATE
 * ----------------------------------------------------------
 * Cerrar la vigente e insertar una nueva con VIGENCIA_DESDE es lo que permite
 * cambiar un porcentaje sin cambiar retroactivamente el neto de todo lo ya
 * informado.
 *
 * Los movimientos PENDIENTES -fecha de acreditacion futura- si se recalculan al
 * guardar la alicuota nueva, por decision del negocio. Los YA ACREDITADOS no se
 * tocan nunca: su plata ya entro con la tasa que entro. Ver
 * README-cob-electronicos.md.
 *
 * ESTA FILA NO SE CRUZA CON VENTAS
 * --------------------------------
 * Por decision del negocio, la fila "Cobranzas Pagos Electronicos" NO se solapa
 * ni se ajusta contra "Cobros s/ ventas estimadas" de Ventas. No hay ninguna
 * deduccion, prorrateo ni exclusion cruzada entre las dos filas, y no es un
 * pendiente ni un bug: es como se decidio medirlo.
 *
 * SI LAS TABLAS NO EXISTEN
 * ------------------------
 * Las lecturas devuelven vacio y getAvisos() dice que hay que correr
 * sql/cashflow_cob_electronicos.sql, en vez de romper. Mismo criterio que
 * Saldos y CashflowEstructura.
 */
class CobElectronicos {

    /** Origenes del dato. Hoy solo se produce MANUAL; los otros dos son la importacion. */
    const ORIGEN_MANUAL = 'MANUAL';
    const ORIGEN_ARCHIVO = 'ARCHIVO';
    const ORIGEN_API = 'API';

    /**
     * Tolerancia para comparar dos tasas.
     * Las columnas son DECIMAL(9,6) y los valores dan la vuelta por JSON y por
     * un input numerico: una comparacion estricta reportaria cambios que no
     * existen. Es el mismo criterio que Saldos::resolverOverrides() con la
     * reserva.
     */
    const TOLERANCIA_TASA = 0.0000005;

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
       Saldos::armarSaldosLocales(): lo delicado de este modulo no son las
       consultas sino los criterios -que alicuota rige a que fecha, que pasa con
       un movimiento cuya procesadora se quedo sin alicuotas, en que columna del
       eje cae una acreditacion-, y asi se pueden verificar sin base.

       Aca vive la UNICA implementacion de la formula del neto.
       ==================================================================== */

    /**
     * La alicuota vigente de CADA concepto a una fecha.
     *
     * De cada CONCEPTO se toma la ultima vigencia con VIGENCIA_DESDE <= la
     * fecha. Una vigencia POSTERIOR no se toma: es un porcentaje que todavia no
     * rige, y usarlo seria calcular un movimiento con una tasa que no existia
     * cuando se acredito.
     *
     * EL DESEMPATE POR ID NO ES OPCIONAL. Nada impide corregir dos veces el
     * mismo porcentaje el mismo dia -es justo lo que pasa cuando alguien se
     * equivoca al tipearlo-, y dos vigencias de la misma fecha empatan. El ID es
     * un IDENTITY, asi que el mayor es siempre el insertado despues. Es la misma
     * regla que Saldos::ultimaCarga().
     *
     * @param array $alicuotas Filas con CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ID
     *        y opcionalmente ACTIVO
     * @param string $fecha 'Y-m-d' contra la que se resuelve la vigencia
     * @return array Mapa CONCEPTO => ['concepto', 'alicuota', 'vigencia_desde', 'id']
     */
    public static function alicuotasVigentes($alicuotas, $fecha) {
        $vigentes = [];

        if (!is_array($alicuotas) || $fecha === null || $fecha === '') {
            return $vigentes;
        }

        $fecha = substr((string) $fecha, 0, 10);

        foreach ($alicuotas as $a) {
            // Una alicuota inhabilitada no rige. Si la fila no trae ACTIVO se
            // asume vigente: los helpers tambien se llaman con datos armados a
            // mano en las pruebas.
            if (array_key_exists('ACTIVO', $a) && intval($a['ACTIVO']) !== 1) {
                continue;
            }

            $concepto = trim((string) (isset($a['CONCEPTO']) ? $a['CONCEPTO'] : ''));

            if ($concepto === '') {
                continue;
            }

            $desde = Horizonte::normalizarFecha(
                isset($a['VIGENCIA_DESDE']) ? $a['VIGENCIA_DESDE'] : null);

            if ($desde === null || $desde > $fecha) {
                continue;
            }

            $id = intval(isset($a['ID']) ? $a['ID'] : 0);

            $candidata = [
                'concepto' => $concepto,
                'alicuota' => floatval(isset($a['ALICUOTA']) ? $a['ALICUOTA'] : 0),
                'vigencia_desde' => $desde,
                'id' => $id
            ];

            if (!isset($vigentes[$concepto])
                || self::vigenciaPosterior($candidata, $vigentes[$concepto])) {
                $vigentes[$concepto] = $candidata;
            }
        }

        ksort($vigentes);

        return $vigentes;
    }

    /**
     * Si la vigencia $a es posterior a $b: primero por fecha, y si empatan, por
     * ID.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function vigenciaPosterior($a, $b) {
        if ($a['vigencia_desde'] !== $b['vigencia_desde']) {
            return $a['vigencia_desde'] > $b['vigencia_desde'];
        }

        return $a['id'] > $b['id'];
    }

    /**
     * Tasa de retencion de una procesadora a una fecha: la SUMA de las alicuotas
     * vigentes de cada concepto.
     *
     * Devuelve tambien la cantidad de conceptos, porque cero conceptos NO es
     * tasa cero: es "esta procesadora no tiene alicuotas vigentes a esta
     * fecha", y con eso el alta de un movimiento se rechaza en lugar de guardar
     * un neto igual al bruto. Confundir las dos cosas es exactamente la causa de
     * que D6, D7 y D24 esten vacias en el Excel.
     *
     * @param array $alicuotas Filas de alicuotas de UNA procesadora
     * @param string $fecha 'Y-m-d' de acreditacion del movimiento
     * @return array ['tasa' => float, 'conceptos' => int, 'detalle' => mapa]
     */
    public static function tasaRetencion($alicuotas, $fecha) {
        $vigentes = self::alicuotasVigentes($alicuotas, $fecha);
        $tasa = 0;

        foreach ($vigentes as $v) {
            $tasa += $v['alicuota'];
        }

        return [
            'tasa' => $tasa,
            'conceptos' => count($vigentes),
            'detalle' => $vigentes
        ];
    }

    /**
     * El neto de una acreditacion. ES LA UNICA IMPLEMENTACION DE ESTA FORMULA.
     *
     * @param float $bruto
     * @param float $tasa Suma de las alicuotas vigentes, entre 0 y 1
     * @return float Redondeado a los 4 decimales de la columna
     */
    public static function importeNeto($bruto, $tasa) {
        return round(floatval($bruto) * (1 - floatval($tasa)), 4);
    }

    /**
     * Resuelve la tasa con la que hay que calcular un movimiento, o LANZA con el
     * motivo.
     *
     * Es la puerta por la que pasa todo alta y toda edicion: no se puede guardar
     * un movimiento cuya procesadora no tiene alicuotas vigentes a la fecha de
     * acreditacion, porque quedaria sin neto.
     *
     * @param array $alicuotas Filas de alicuotas de la procesadora
     * @param string $fecha 'Y-m-d' de acreditacion
     * @param string $procesadora Razon social, para el mensaje
     * @return array ['tasa' => float, 'conceptos' => int, 'detalle' => mapa]
     */
    public static function resolverTasa($alicuotas, $fecha, $procesadora = '') {
        $r = self::tasaRetencion($alicuotas, $fecha);
        $nombre = trim((string) $procesadora);
        $nombre = ($nombre === '') ? 'La procesadora' : ('"' . $nombre . '"');

        if ($r['conceptos'] === 0) {
            throw new Exception($nombre . ' no tiene ninguna alícuota vigente al '
                . self::fechaCorta($fecha) . ', así que no se puede calcular el importe neto del '
                . 'movimiento. Cargá sus alícuotas en Parámetros → Cob. Electrónicos con una '
                . 'vigencia igual o anterior a esa fecha.');
        }

        if ($r['tasa'] >= 1) {
            throw new Exception('Las alícuotas vigentes de ' . $nombre . ' al '
                . self::fechaCorta($fecha) . ' suman ' . self::porcentaje($r['tasa'])
                . ', así que el neto saldría cero o negativo. Corregilas en '
                . 'Parámetros → Cob. Electrónicos.');
        }

        return $r;
    }

    /**
     * Valida una alicuota antes de guardarla. LANZA con el motivo.
     *
     * LA SUMA DE LAS VIGENTES TIENE QUE SER MENOR A 1, y se valida AL GUARDAR LA
     * ALICUOTA, no al usarla: el momento de frenar una suma imposible es cuando
     * alguien la escribe, no cuando ya hay movimientos mal calculados.
     *
     * La suma se calcula sobre el ESTADO RESULTANTE -las otras vigentes mas la
     * nueva-, no sobre lo que llega del cliente: es el mismo criterio que usa
     * ParametrosController con el mix de cobro.
     *
     * @param array $alicuotas Filas de alicuotas de la procesadora, las de ahora
     * @param string $concepto Concepto que se esta guardando
     * @param float $nueva Alicuota nueva
     * @param string $vigenciaDesde 'Y-m-d' desde la que rige
     * @return float Tasa total resultante
     */
    public static function validarAlicuota($alicuotas, $concepto, $nueva, $vigenciaDesde) {
        $concepto = trim((string) $concepto);
        $nueva = floatval($nueva);

        if ($concepto === '') {
            throw new Exception('La alícuota necesita un concepto (por ejemplo IIBB o SICREB)');
        }

        if (mb_strlen($concepto) > 30) {
            throw new Exception('El concepto no puede superar los 30 caracteres');
        }

        if ($nueva < 0) {
            throw new Exception('La alícuota no puede ser negativa');
        }

        if ($nueva >= 1) {
            throw new Exception('Una alícuota de ' . self::porcentaje($nueva) . ' dejaría el '
                . 'importe neto en cero o negativo. La alícuota se carga como fracción: '
                . '2,5% es 0,025.');
        }

        $fecha = Horizonte::normalizarFecha($vigenciaDesde);

        if ($fecha === null) {
            throw new Exception('La alícuota necesita una fecha de vigencia');
        }

        // El estado resultante: las vigentes a esa fecha, con la nueva
        // reemplazando a la de su propio concepto.
        $vigentes = self::alicuotasVigentes($alicuotas, $fecha);
        $vigentes[$concepto] = ['alicuota' => $nueva];

        $suma = 0;

        foreach ($vigentes as $v) {
            $suma += floatval($v['alicuota']);
        }

        if ($suma >= 1) {
            throw new Exception('Las alícuotas vigentes sumarían ' . self::porcentaje($suma)
                . ' con este cambio, así que el importe neto de las acreditaciones saldría cero o '
                . 'negativo: una cobranza restaría plata del tablero. Revisá los otros conceptos '
                . 'antes de guardar este.');
        }

        return $suma;
    }

    /**
     * Normaliza la razon social de una procesadora.
     *
     * Solo trim: la comparacion case-insensitive la hace el collation por
     * defecto de SQL Server, asi que "payway" y "Payway" no pueden convivir como
     * dos procesadoras. Si convivieran, cada una tendria su propio juego de
     * alicuotas y los movimientos se repartirian entre las dos.
     *
     * @param string $razonSocial
     * @return string
     */
    public static function normalizarRazonSocial($razonSocial) {
        return trim(preg_replace('/\s+/u', ' ', (string) $razonSocial));
    }

    /**
     * Donde cae un movimiento respecto del eje del tablero.
     *
     * UN MOVIMIENTO CON FECHA ANTERIOR AL EJE **NO** ABRE EL HORIZONTE. Es lo
     * inverso a lo que hace Saldos::destinoEnEje(), y es a proposito:
     *
     *   - Un SALDO describe plata que EXISTE AHORA, asi que una fecha pasada
     *     significa "esto ya es cierto hoy" y se reubica en la primera columna.
     *   - Una ACREDITACION con fecha pasada es un MOVIMIENTO YA OCURRIDO: esa
     *     plata ya esta en la cuenta y ya la informa el saldo bancario de la
     *     pestana Saldos. Reubicarla en la apertura del horizonte la contaria
     *     DOS VECES.
     *
     * Y NO SE AVISA NI SE CUENTA COMO IMPORTE FUERA DEL HORIZONTE. Una
     * acreditacion ya ocurrida no es plata que el tablero informe de menos: es
     * plata que el tablero informa POR OTRA FILA, la del saldo bancario. Avisar
     * de ella todos los dias seria ruido sobre algo que ya paso y que no hay que
     * hacer. Por eso queda fuera del alcance del modulo, sin aviso.
     *
     * Tampoco se delega la decision a Horizonte::acumular(): una fecha del mes
     * en curso anterior a hoy caeria en la columna de su mes, que es una columna
     * que el tablero ni siquiera incluye en el arrastre. El corte por fecha es
     * explicito para que no dependa de como quede armado el eje.
     *
     * Una fecha POSTERIOR al eje SI se informa y SI va a 'fuera_horizonte': esa
     * plata todavia no entro a ninguna cuenta y ninguna otra fila del tablero la
     * muestra, asi que callarla seria informar de menos.
     *
     * @param string $fecha 'Y-m-d' de acreditacion
     * @param Horizonte $h
     * @return string 'DENTRO', 'ANTERIOR' o 'POSTERIOR'
     */
    public static function ubicacionEnEje($fecha, $h) {
        $fecha = Horizonte::normalizarFecha($fecha);

        if ($fecha === null) {
            return 'POSTERIOR';
        }

        if ($fecha < $h->hoy()) {
            return 'ANTERIOR';
        }

        return ($h->columna($fecha) === null) ? 'POSTERIOR' : 'DENTRO';
    }

    /**
     * Arma la tabla de la pantalla a partir de los movimientos leidos.
     *
     * Marca cada fila con su ubicacion en el eje y deja los avisos de los dos
     * casos que hay que ver sin recorrer la tabla:
     *
     *   - movimientos POSTERIORES al horizonte, con el importe y la fecha
     *   - dos movimientos de la misma procesadora y la misma fecha, que se
     *     AVISAN y NO se bloquean: puede haber dos liquidaciones el mismo dia, y
     *     el aviso alcanza para detectar el pegado doble
     *
     * Los movimientos YA ACREDITADOS -fecha anterior al eje- se marcan en su
     * fila pero NO generan aviso: ya pasaron, y su importe ya esta informado en
     * el saldo bancario. Ver ubicacionEnEje().
     *
     * @param array $movimientos Filas normalizadas por filaMovimiento()
     * @param Horizonte $h
     * @return array ['filas', 'totales', 'por_procesadora', 'avisos']
     */
    public static function armarMovimientos($movimientos, $h) {
        $filas = [];
        $porProcesadora = [];

        $totales = [
            'bruto' => 0,
            'neto' => 0,
            'retenido' => 0,
            'movimientos' => 0,
            // 'fuera_eje' es SOLO lo posterior al horizonte: es lo unico que el
            // tablero deja de mostrar teniendo que mostrarlo. Lo ya acreditado
            // se cuenta aparte y no se avisa.
            'fuera_eje' => 0,
            'fuera_eje_movimientos' => 0,
            'ya_acreditado' => 0,
            'ya_acreditado_movimientos' => 0
        ];

        $fueraPosterior = 0;
        $duplicados = [];
        $vistos = [];

        foreach (is_array($movimientos) ? $movimientos : [] as $m) {
            $fecha = Horizonte::normalizarFecha(
                isset($m['fecha_acreditacion']) ? $m['fecha_acreditacion'] : null);

            $bruto = floatval(isset($m['importe_bruto']) ? $m['importe_bruto'] : 0);
            $neto = floatval(isset($m['importe_neto']) ? $m['importe_neto'] : 0);
            $ubicacion = self::ubicacionEnEje($fecha, $h);

            $fila = $m;
            $fila['fecha_acreditacion'] = $fecha;
            $fila['ubicacion_eje'] = $ubicacion;
            $fila['entra_al_tablero'] = ($ubicacion === 'DENTRO');
            $fila['retenido'] = round($bruto - $neto, 4);

            $filas[] = $fila;

            $totales['bruto'] += $bruto;
            $totales['neto'] += $neto;
            $totales['retenido'] += $fila['retenido'];
            $totales['movimientos']++;

            if ($ubicacion === 'ANTERIOR') {
                $totales['ya_acreditado'] += $neto;
                $totales['ya_acreditado_movimientos']++;
            } elseif ($ubicacion === 'POSTERIOR') {
                $totales['fuera_eje'] += $neto;
                $totales['fuera_eje_movimientos']++;
                $fueraPosterior += $neto;
            }

            $nombre = (string) (isset($m['procesadora']) ? $m['procesadora'] : '');

            if (!isset($porProcesadora[$nombre])) {
                $porProcesadora[$nombre] = [
                    'procesadora' => $nombre,
                    'id_procesadora' => intval(isset($m['id_procesadora']) ? $m['id_procesadora'] : 0),
                    'bruto' => 0,
                    'neto' => 0,
                    'retenido' => 0,
                    'movimientos' => 0
                ];
            }

            $porProcesadora[$nombre]['bruto'] += $bruto;
            $porProcesadora[$nombre]['neto'] += $neto;
            $porProcesadora[$nombre]['retenido'] += $fila['retenido'];
            $porProcesadora[$nombre]['movimientos']++;

            // Dos liquidaciones de la misma procesadora el mismo dia son
            // posibles. Se avisan igual: es la forma de ver un pegado doble.
            $clave = $nombre . '|' . $fecha;

            if (isset($vistos[$clave])) {
                $duplicados[$clave] = $nombre . ' el ' . self::fechaCorta($fecha);
            }

            $vistos[$clave] = true;
        }

        ksort($porProcesadora);

        $avisos = [];

        // Lo YA ACREDITADO no genera aviso: ya paso, ya entro a la cuenta y ya
        // esta informado en el saldo bancario. La fila igual queda marcada.
        if ($fueraPosterior != 0) {
            $avisos[] = 'Hay ' . self::plata($fueraPosterior) . ' de acreditaciones con fecha '
                . 'posterior al final del horizonte: se ven en la tabla pero el tablero todavía '
                . 'no las muestra, porque su fecha no tiene columna.';
        }

        if (!empty($duplicados)) {
            sort($duplicados);

            $avisos[] = 'Hay más de un movimiento para la misma procesadora y la misma fecha ('
                . implode('; ', $duplicados) . '). Puede ser correcto —dos liquidaciones el mismo '
                . 'día— pero también es lo que se ve cuando una carga quedó pegada dos veces.';
        }

        return [
            'filas' => $filas,
            'totales' => $totales,
            'por_procesadora' => array_values($porProcesadora),
            'avisos' => $avisos
        ];
    }

    /**
     * Acreditaciones por dia: SUMA DE NETOS agrupada por fecha de acreditacion.
     *
     * Reemplaza a lo que en el Excel hacian las columnas F:Y y la fila 56. Ese
     * bloque NO se migra: AA5 es #!REF!, AB6:AB43 comparan dos veces contra
     * $AB$2 en lugar de $AB$3 y las columnas mensuales devuelven FALSO por un IF
     * sin rama else. Son tres errores que desaparecen por construccion al
     * agrupar sobre el dato en vez de sobre una matriz de formulas.
     *
     * SON NETOS, NUNCA BRUTOS: es lo que efectivamente entra a la cuenta.
     *
     * @param array $filas Filas de armarMovimientos()['filas']
     * @return array Lista de ['fecha', 'neto', 'bruto', 'movimientos', 'entra_al_tablero']
     */
    public static function agruparPorDia($filas) {
        $porDia = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $fecha = Horizonte::normalizarFecha(
                isset($f['fecha_acreditacion']) ? $f['fecha_acreditacion'] : null);

            if ($fecha === null) {
                continue;
            }

            if (!isset($porDia[$fecha])) {
                $porDia[$fecha] = [
                    'fecha' => $fecha,
                    'neto' => 0,
                    'bruto' => 0,
                    'movimientos' => 0,
                    'entra_al_tablero' => !empty($f['entra_al_tablero'])
                ];
            }

            $porDia[$fecha]['neto'] += floatval(isset($f['importe_neto']) ? $f['importe_neto'] : 0);
            $porDia[$fecha]['bruto'] += floatval(isset($f['importe_bruto']) ? $f['importe_bruto'] : 0);
            $porDia[$fecha]['movimientos']++;
        }

        ksort($porDia);

        return array_values($porDia);
    }

    /**
     * Acreditaciones por mes: la misma suma de netos, agrupada por el anio-mes de
     * la fecha de acreditacion. Reemplaza a las columnas Z:AK del Excel.
     *
     * @param array $filas Filas de armarMovimientos()['filas']
     * @return array Lista de ['mes', 'label', 'neto', 'bruto', 'movimientos']
     */
    public static function agruparPorMes($filas) {
        $porMes = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $fecha = Horizonte::normalizarFecha(
                isset($f['fecha_acreditacion']) ? $f['fecha_acreditacion'] : null);

            if ($fecha === null) {
                continue;
            }

            $mes = substr($fecha, 0, 7);

            if (!isset($porMes[$mes])) {
                $partes = explode('-', $mes);

                $porMes[$mes] = [
                    'mes' => $mes,
                    'label' => Horizonte::labelMes(intval($partes[0]), intval($partes[1])),
                    'neto' => 0,
                    'bruto' => 0,
                    'movimientos' => 0
                ];
            }

            $porMes[$mes]['neto'] += floatval(isset($f['importe_neto']) ? $f['importe_neto'] : 0);
            $porMes[$mes]['bruto'] += floatval(isset($f['importe_bruto']) ? $f['importe_bruto'] : 0);
            $porMes[$mes]['movimientos']++;
        }

        ksort($porMes);

        return array_values($porMes);
    }

    /**
     * Serie del Cashflow: la suma de NETOS en la fecha de acreditacion.
     *
     * LA FECHA DE IMPUTACION ES LA FECHA DE ACREDITACION, SIN CORRIMIENTOS. No
     * hay regla de dia habil ni tratamiento de feriados: la fecha que informa la
     * procesadora es la fecha en que el dinero entra, y correrla al lunes
     * inventaria una fecha que el dato ya trae.
     *
     * LO POSTERIOR AL HORIZONTE va a 'fuera_horizonte' CON AVISO: esa plata
     * todavia no entro a ninguna cuenta y ninguna otra fila del tablero la
     * muestra, asi que callarla seria informar de menos.
     *
     * LO YA ACREDITADO -fecha anterior al eje- queda fuera del alcance, sin
     * aviso y SIN sumar a 'fuera_horizonte'. No es plata que el tablero informe
     * de menos: es plata que el tablero informa por otra fila, la del saldo
     * bancario de la pestana Saldos. Contarla en 'fuera_horizonte' haria que el
     * tablero avisara todos los dias por algo que ya paso y que no hay que
     * hacer. Ver ubicacionEnEje() para por que, a diferencia de Saldos, una
     * fecha pasada tampoco abre el horizonte.
     *
     * 'ya_acreditado' se devuelve igual, para que quien quiera mostrarlo pueda:
     * es un escalar informativo y el contrato lo ignora.
     *
     * @param array $filas Filas de armarMovimientos()['filas']
     * @param Horizonte $h
     * @return array Serie del contrato de CashflowProvider
     */
    public static function armarSerie($filas, $h) {
        $serie = $h->serieVacia();
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['warnings'] = [];
        $serie['ya_acreditado'] = 0;

        $posterior = 0;
        $fechaMasNueva = null;

        foreach (is_array($filas) ? $filas : [] as $f) {
            // Siempre el NETO. El bruto no llega nunca a la cuenta.
            $importe = floatval(isset($f['importe_neto']) ? $f['importe_neto'] : 0);

            if ($importe == 0) {
                continue;
            }

            $fecha = Horizonte::normalizarFecha(
                isset($f['fecha_acreditacion']) ? $f['fecha_acreditacion'] : null);

            if ($fecha === null) {
                $serie['sin_fecha'] += $importe;
                continue;
            }

            $ubicacion = self::ubicacionEnEje($fecha, $h);

            // Ya acreditado: fuera del alcance del modulo, sin aviso y sin sumar
            // a fuera_horizonte. Lo informa el saldo bancario.
            if ($ubicacion === 'ANTERIOR') {
                $serie['ya_acreditado'] += $importe;
                continue;
            }

            if ($ubicacion === 'POSTERIOR' || !$h->acumular($serie, $fecha, $importe)) {
                $serie['fuera_horizonte'] += $importe;
                $posterior += $importe;

                if ($fechaMasNueva === null || $fecha > $fechaMasNueva) {
                    $fechaMasNueva = $fecha;
                }
            }
        }

        if ($posterior != 0) {
            $serie['warnings'][] = 'Cobranzas Pagos Electronicos: ' . self::plata($posterior)
                . ' netos tienen fecha de acreditacion posterior al final del horizonte (la mas '
                . 'lejana, del ' . self::fechaCorta($fechaMasNueva) . '), asi que no tienen '
                . 'columna donde mostrarse. Se ven igual en la pestana Cob. Electronicos.';
        }

        return $serie;
    }

    /**
     * Que movimientos PENDIENTES cambian si se recalculan con las alicuotas de
     * ahora, y con que diferencia.
     *
     * SOLO LOS PENDIENTES. Un movimiento con fecha de acreditacion anterior a
     * hoy ya se acredito: su plata entro con la tasa que entro, y recalcularlo
     * seria reescribir la historia. Es el mismo motivo por el que la tasa se
     * persiste en la fila.
     *
     * Es un helper puro y devuelve un PLAN, no una escritura: asi el mismo
     * calculo sirve para aplicar el recalculo y para poder decir en la pantalla
     * que fue lo que cambio.
     *
     * @param array $movimientos Filas normalizadas por filaMovimiento()
     * @param array $alicuotasPorProcesadora Mapa id_procesadora => filas de alicuotas
     * @param string $hoy 'Y-m-d'
     * @return array ['cambios', 'diferencia', 'pendientes', 'acreditados', 'avisos']
     */
    public static function planRecalculo($movimientos, $alicuotasPorProcesadora, $hoy) {
        $cambios = [];
        $avisos = [];
        $diferencia = 0;
        $pendientes = 0;
        $acreditados = 0;
        $sinAlicuota = [];

        $alicuotasPorProcesadora = is_array($alicuotasPorProcesadora)
            ? $alicuotasPorProcesadora : [];

        foreach (is_array($movimientos) ? $movimientos : [] as $m) {
            $fecha = Horizonte::normalizarFecha(
                isset($m['fecha_acreditacion']) ? $m['fecha_acreditacion'] : null);

            if ($fecha === null) {
                continue;
            }

            if ($fecha < substr((string) $hoy, 0, 10)) {
                $acreditados++;
                continue;
            }

            $pendientes++;

            $idProc = intval(isset($m['id_procesadora']) ? $m['id_procesadora'] : 0);
            $alicuotas = isset($alicuotasPorProcesadora[$idProc])
                ? $alicuotasPorProcesadora[$idProc] : [];

            $r = self::tasaRetencion($alicuotas, $fecha);

            // Sin alicuotas vigentes no se puede recalcular. El movimiento queda
            // como esta -con su tasa vieja- y se avisa: dejarlo en cero seria
            // informar de menos, y calcularlo sin tasa seria informar el bruto
            // como si fuera neto.
            if ($r['conceptos'] === 0 || $r['tasa'] >= 1) {
                $sinAlicuota[] = (string) (isset($m['procesadora']) ? $m['procesadora'] : $idProc);
                continue;
            }

            $tasaVieja = floatval(isset($m['tasa_aplicada']) ? $m['tasa_aplicada'] : 0);

            if (abs($tasaVieja - $r['tasa']) <= self::TOLERANCIA_TASA) {
                continue;
            }

            $netoViejo = floatval(isset($m['importe_neto']) ? $m['importe_neto'] : 0);
            $netoNuevo = self::importeNeto(
                isset($m['importe_bruto']) ? $m['importe_bruto'] : 0, $r['tasa']);

            $cambios[] = [
                'id' => intval(isset($m['id']) ? $m['id'] : 0),
                'procesadora' => isset($m['procesadora']) ? $m['procesadora'] : '',
                'fecha_acreditacion' => $fecha,
                'importe_bruto' => floatval(isset($m['importe_bruto']) ? $m['importe_bruto'] : 0),
                'tasa_anterior' => $tasaVieja,
                'tasa_nueva' => $r['tasa'],
                'neto_anterior' => $netoViejo,
                'neto_nuevo' => $netoNuevo,
                'diferencia' => round($netoNuevo - $netoViejo, 4)
            ];

            $diferencia += ($netoNuevo - $netoViejo);
        }

        if (!empty($sinAlicuota)) {
            $sinAlicuota = array_values(array_unique($sinAlicuota));
            sort($sinAlicuota);

            $avisos[] = 'Hay movimientos pendientes de ' . implode(', ', $sinAlicuota)
                . ' que no se pudieron recalcular porque esa procesadora no tiene alícuotas '
                . 'vigentes utilizables a su fecha de acreditación. Conservan la tasa con la que '
                . 'se cargaron.';
        }

        return [
            'cambios' => $cambios,
            'diferencia' => round($diferencia, 4),
            'pendientes' => $pendientes,
            'acreditados' => $acreditados,
            'avisos' => $avisos
        ];
    }

    /* ====================================================================
       IMPORTACION DESDE PLANILLA

       POR QUE EXISTE: la carga a mano de veinte o treinta acreditaciones por
       semana hace que la pantalla se actualice poco, y una pantalla que se
       actualiza poco muestra un tablero viejo. Con el importador, el archivo de
       la procesadora se sube completo y el modulo DICE QUE CAMBIO, en lugar de
       obligar a comparar fila por fila contra lo que ya estaba cargado.

       POR QUE CSV Y NO .XLSX: leer un .xlsx sin librerias necesita la extension
       zip de PHP, que en este servidor esta instalada pero NO habilitada
       (extension=zip comentada en php.ini). Habilitarla es tocar la
       configuracion del servidor y reiniciar Apache, y ademas dejaria el modulo
       dependiendo de que ese cambio este hecho en cada entorno. Excel abre y
       guarda CSV nativamente -Archivo -> Guardar como -> CSV UTF-8-, asi que el
       costo para el usuario es un paso y el modulo no depende de nada. Si suben
       un .xlsx igual, el parser lo detecta por su firma y lo dice.

       Todo el criterio vive en helpers PUROS: el parseo de la planilla y el
       diff contra lo cargado se prueban sin base ni archivos.
       ==================================================================== */

    /** Separadores que puede traer un CSV exportado de Excel */
    const SEPARADORES = [';', ',', "\t", '|'];

    /**
     * Las columnas de la planilla, con sus sinonimos aceptados.
     *
     * Es la UNICA definicion: de aca salen la plantilla que se descarga, el
     * mapeo del encabezado al parsear y la ayuda de la pantalla. Con tres
     * listas distintas, la plantilla y el parser se desincronizan en el primer
     * cambio.
     *
     * Los sinonimos incluyen los nombres que usa la hoja original (RAZON_SOC,
     * Importe, Cobro) para que se pueda pegar una columna del Excel viejo sin
     * renombrar nada.
     *
     * @return array Mapa campo => ['titulo', 'obligatoria', 'ayuda', 'sinonimos']
     */
    public static function columnasImportacion() {
        return [
            'procesadora' => [
                'titulo' => 'PROCESADORA',
                'obligatoria' => true,
                'ayuda' => 'Razon social, tal como figura en Parametros. Tiene que estar activa.',
                'sinonimos' => ['PROCESADORA', 'RAZON_SOCIAL', 'RAZON_SOC', 'RAZONSOCIAL']
            ],
            'importe_bruto' => [
                'titulo' => 'IMPORTE_BRUTO',
                'obligatoria' => true,
                'ayuda' => 'Importe bruto informado, sin separador de miles. Ej: 1069326,00',
                'sinonimos' => ['IMPORTE_BRUTO', 'IMPORTE', 'BRUTO', 'IMPORTEBRUTO']
            ],
            'fecha_acreditacion' => [
                'titulo' => 'FECHA_ACREDITACION',
                'obligatoria' => true,
                'ayuda' => 'Fecha en que se acredita. dd/mm/aaaa o aaaa-mm-dd.',
                'sinonimos' => ['FECHA_ACREDITACION', 'FECHA', 'COBRO', 'FECHA_COBRO',
                                'FECHAACREDITACION']
            ],
            'id_externo' => [
                'titulo' => 'ID_EXTERNO',
                'obligatoria' => false,
                'ayuda' => 'Numero de liquidacion de la procesadora. Opcional, pero es lo que '
                    . 'permite reconocer un movimiento ya cargado y distinguir dos '
                    . 'liquidaciones del mismo dia.',
                'sinonimos' => ['ID_EXTERNO', 'LIQUIDACION', 'ID_LIQUIDACION', 'IDEXTERNO',
                                'NRO_LIQUIDACION']
            ],
            'observaciones' => [
                'titulo' => 'OBSERVACIONES',
                'obligatoria' => false,
                'ayuda' => 'Opcional, hasta 200 caracteres.',
                'sinonimos' => ['OBSERVACIONES', 'OBSERVACION', 'NOTAS', 'COMENTARIOS']
            ]
        ];
    }

    /**
     * La plantilla que se descarga, con dos filas de ejemplo.
     *
     * Va con BOM de UTF-8 y separador ';': es lo que Excel en espanol abre en
     * columnas sin preguntar nada. Sin el BOM, Excel muestra los acentos rotos;
     * con coma como separador, mete todo en una sola columna.
     *
     * Las dos filas de ejemplo se cargan: son datos validos con la forma
     * esperada. Una plantilla con la fila de ejemplo comentada obliga a
     * adivinar el formato del numero y de la fecha, que es justo donde falla una
     * importacion.
     *
     * @param string $ejemploFecha 'Y-m-d' de la primera fila de ejemplo
     * @return string Contenido del archivo
     */
    public static function plantillaCsv($ejemploFecha = null) {
        $columnas = self::columnasImportacion();
        $titulos = [];

        foreach ($columnas as $c) {
            $titulos[] = $c['titulo'];
        }

        $fecha = ($ejemploFecha === null) ? date('Y-m-d') : substr((string) $ejemploFecha, 0, 10);
        $manana = date('d/m/Y', strtotime($fecha . ' +1 day'));
        $pasado = date('d/m/Y', strtotime($fecha . ' +2 day'));

        $filas = [
            $titulos,
            ['Payway', '1069326,00', $manana, '', 'Ejemplo: borrar esta fila'],
            ['Mercado Pago', '35257406,00', $pasado, 'LIQ-00123',
             'Ejemplo: con numero de liquidacion']
        ];

        $csv = "\xEF\xBB\xBF";   // BOM, para que Excel respete los acentos

        foreach ($filas as $fila) {
            $csv .= implode(';', $fila) . "\r\n";
        }

        return $csv;
    }

    /**
     * Lee el contenido de una planilla CSV y devuelve las filas crudas.
     *
     * Detecta el separador y acepta los formatos de numero y de fecha que
     * exporta Excel en cualquiera de los dos idiomas: el usuario no tiene que
     * saber en que configuracion regional esta su Excel.
     *
     * NO valida contra la base: eso es compararImportacion(). Aca solo se
     * resuelve la forma del archivo.
     *
     * @param string $contenido Contenido del archivo subido
     * @return array ['filas' => [...], 'errores' => [...], 'separador' => string]
     */
    public static function parsearPlanilla($contenido) {
        $contenido = (string) $contenido;

        // Un .xlsx es un ZIP: empieza con 'PK'. Se detecta para poder decir que
        // hacer, en lugar de fallar con un archivo lleno de bytes binarios.
        if (substr($contenido, 0, 2) === 'PK') {
            throw new Exception('El archivo es un .xlsx y este servidor no puede leerlo. '
                . 'Abrilo en Excel y guardalo como CSV (Archivo → Guardar como → '
                . 'CSV UTF-8 delimitado por comas). El contenido es el mismo.');
        }

        if (substr($contenido, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new Exception('El archivo es un .xls antiguo y este servidor no puede leerlo. '
                . 'Abrilo en Excel y guardalo como CSV UTF-8.');
        }

        // BOM de UTF-8: si queda, el primer titulo no matchea con nada.
        if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
            $contenido = substr($contenido, 3);
        }

        // Excel en Windows guarda en la codificacion del sistema si no se elige
        // CSV UTF-8. Se convierte para que una razon social con acento no quede
        // como basura y no matchee con la procesadora.
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);
        $lineas = array_values(array_filter($lineas, function ($l) {
            return trim($l) !== '';
        }));

        if (empty($lineas)) {
            throw new Exception('El archivo está vacío');
        }

        $separador = self::detectarSeparador($lineas[0]);
        $mapa = self::mapearEncabezado(str_getcsv($lineas[0], $separador));

        $filas = [];
        $errores = [];

        for ($i = 1; $i < count($lineas); $i++) {
            $celdas = str_getcsv($lineas[$i], $separador);
            $fila = ['linea' => $i + 1];
            $vacia = true;

            foreach ($mapa as $campo => $indice) {
                $valor = isset($celdas[$indice]) ? trim((string) $celdas[$indice]) : '';
                $fila[$campo] = $valor;

                if ($valor !== '') {
                    $vacia = false;
                }
            }

            // Una fila con separadores y nada mas es lo que deja Excel debajo de
            // los datos: se saltea en silencio, no es un error del usuario.
            if ($vacia) {
                continue;
            }

            $filas[] = $fila;
        }

        if (empty($filas)) {
            throw new Exception('El archivo no tiene ninguna fila de datos. La primera fila es '
                . 'el encabezado y abajo van los movimientos.');
        }

        return ['filas' => $filas, 'errores' => $errores, 'separador' => $separador];
    }

    /**
     * Separador de un CSV: el que mas veces aparece en el encabezado.
     *
     * Excel en espanol exporta con ';' y en ingles con ','. Adivinarlo es mas
     * barato que hacer que el usuario lo declare, y si se equivoca el
     * encabezado no matchea y el error lo dice.
     *
     * @param string $encabezado
     * @return string
     */
    private static function detectarSeparador($encabezado) {
        $mejor = ';';
        $max = -1;

        foreach (self::SEPARADORES as $sep) {
            $n = substr_count($encabezado, $sep);

            if ($n > $max) {
                $max = $n;
                $mejor = $sep;
            }
        }

        return $mejor;
    }

    /**
     * Empareja los titulos del archivo con los campos del modulo.
     *
     * La comparacion es sin acentos, sin espacios y sin mayusculas, y acepta los
     * sinonimos de columnasImportacion(): el usuario no tiene que escribir el
     * titulo exacto, y una columna de mas no molesta.
     *
     * @param array $titulos Celdas de la primera fila
     * @return array Mapa campo => indice de columna
     */
    private static function mapearEncabezado($titulos) {
        $columnas = self::columnasImportacion();
        $mapa = [];

        foreach ($titulos as $i => $titulo) {
            $normalizado = self::normalizarTitulo($titulo);

            foreach ($columnas as $campo => $def) {
                if (isset($mapa[$campo])) {
                    continue;
                }

                foreach ($def['sinonimos'] as $sinonimo) {
                    if ($normalizado === self::normalizarTitulo($sinonimo)) {
                        $mapa[$campo] = $i;
                        break 2;
                    }
                }
            }
        }

        $faltan = [];

        foreach ($columnas as $campo => $def) {
            if ($def['obligatoria'] && !isset($mapa[$campo])) {
                $faltan[] = $def['titulo'];
            }
        }

        if (!empty($faltan)) {
            throw new Exception('Al archivo le faltan columnas obligatorias: '
                . implode(', ', $faltan) . '. Descargá la plantilla y usá sus encabezados. '
                . 'Se encontraron: ' . implode(', ', array_map('strval', $titulos)) . '.');
        }

        return $mapa;
    }

    /** Titulo de columna comparable: sin acentos, sin espacios, en mayusculas */
    private static function normalizarTitulo($titulo) {
        $t = mb_strtoupper(trim((string) $titulo), 'UTF-8');

        $t = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $t
        );

        return preg_replace('/[^A-Z0-9]/', '', $t);
    }

    /**
     * Lleva a float un importe tipeado en una planilla.
     *
     * Acepta lo que exporta Excel en las dos configuraciones regionales:
     * '1069326,00' y '1069326.00'. Con los DOS separadores presentes, el que
     * este mas a la derecha es el decimal y el otro es de miles ('3.757.900,50').
     * Con UN solo separador se toma como decimal, salvo que aparezca mas de una
     * vez, que solo puede ser separador de miles ('1.648.264').
     *
     * Un solo punto o coma con tres decimales -'1.648'- es genuinamente
     * ambiguo, asi que la plantilla pide el importe SIN separador de miles.
     *
     * @param string $valor
     * @return float|null null si no es un numero
     */
    public static function numeroDesdePlanilla($valor) {
        $v = trim((string) $valor);

        // Simbolos de moneda, espacios y espacios finos que pega Excel
        $v = str_replace(['$', ' ', "\xc2\xa0", "\xe2\x80\xaf", 'ARS', 'AR$'], '', $v);

        if ($v === '') {
            return null;
        }

        $negativo = (strpos($v, '-') !== false) || (strpos($v, '(') !== false);
        $v = preg_replace('/[^0-9.,]/', '', $v);

        if ($v === '' || !preg_match('/[0-9]/', $v)) {
            return null;
        }

        $puntos = substr_count($v, '.');
        $comas = substr_count($v, ',');

        if ($puntos > 0 && $comas > 0) {
            $decimal = (strrpos($v, '.') > strrpos($v, ',')) ? '.' : ',';
            $miles = ($decimal === '.') ? ',' : '.';
            $v = str_replace($miles, '', $v);
            $v = str_replace($decimal, '.', $v);
        } elseif ($comas > 1) {
            $v = str_replace(',', '', $v);
        } elseif ($puntos > 1) {
            $v = str_replace('.', '', $v);
        } elseif ($comas === 1) {
            $v = str_replace(',', '.', $v);
        }

        if (!is_numeric($v)) {
            return null;
        }

        return $negativo ? -abs(floatval($v)) : floatval($v);
    }

    /**
     * Lleva a 'Y-m-d' una fecha tipeada en una planilla.
     *
     * Acepta 'dd/mm/aaaa', 'dd-mm-aaaa', 'aaaa-mm-dd', 'dd/mm/aa' y el SERIAL de
     * Excel. El serial se acepta acotado -del 1954 al 2064- porque una columna
     * que quedo con formato numero exporta '46265' en lugar de la fecha, y sin
     * esto la importacion falla con un mensaje que no ayuda. La hoja original
     * trae fechas reales, asi que este camino es una red y no la norma.
     *
     * Una fecha ambigua NO se adivina: 'dd/mm' sin anio devuelve null y la fila
     * queda como error, con su numero de linea.
     *
     * @param string $valor
     * @return string|null 'Y-m-d' o null
     */
    public static function fechaDesdePlanilla($valor) {
        $v = trim((string) $valor);

        if ($v === '') {
            return null;
        }

        // aaaa-mm-dd o aaaa/mm/dd, con hora opcional
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $v, $m)) {
            return self::armarFecha($m[1], $m[2], $m[3]);
        }

        // dd/mm/aaaa, dd-mm-aaaa, dd.mm.aaaa y su version de dos digitos
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $v, $m)) {
            $anio = intval($m[3]);

            if ($anio < 100) {
                $anio += ($anio < 70) ? 2000 : 1900;
            }

            return self::armarFecha($anio, $m[2], $m[1]);
        }

        // Serial de Excel. La base es 1899-12-30 por el bug del anio 1900 que
        // Excel conserva a proposito.
        if (preg_match('/^\d{5}$/', $v)) {
            $serial = intval($v);

            if ($serial >= 20000 && $serial <= 60000) {
                return date('Y-m-d', strtotime('1899-12-30 +' . $serial . ' day'));
            }
        }

        return null;
    }

    /** Valida y arma 'Y-m-d'. Devuelve null si la fecha no existe */
    private static function armarFecha($anio, $mes, $dia) {
        $anio = intval($anio);
        $mes = intval($mes);
        $dia = intval($dia);

        if (!checkdate($mes, $dia, $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /**
     * Compara lo que trae el archivo contra lo que ya esta cargado y dice QUE
     * CAMBIARIA. No escribe nada.
     *
     * Es el corazon del importador y es un helper PURO, por el mismo motivo que
     * planRecalculo(): el diff se tiene que poder mostrar antes de confirmar y
     * volver a calcular al confirmar, y las dos veces tiene que dar lo mismo.
     * Si el diff viviera dentro de la escritura, la pantalla mostraria una cosa
     * y el servidor haria otra.
     *
     * LA CLAVE CON LA QUE SE RECONOCE UN MOVIMIENTO YA CARGADO:
     *
     *   - Si la fila trae ID_EXTERNO, es (procesadora, ID_EXTERNO). Es la clave
     *     de verdad: el numero de liquidacion de la procesadora.
     *   - Si no, es (procesadora, fecha de acreditacion). Alcanza para el caso
     *     normal -una liquidacion por dia y por procesadora- y es lo que
     *     permite importar un archivo que no trae el numero.
     *
     * DOS FILAS CON LA MISMA CLAVE EN EL ARCHIVO SON UN ERROR, no un aviso. Dos
     * liquidaciones del mismo dia son legitimas, pero sin ID_EXTERNO no hay
     * forma de saber cual de las dos corresponde a cual de las cargadas: el
     * error pide llenar ID_EXTERNO, que es la solucion real.
     *
     * LAS FILAS YA ACREDITADAS -fecha anterior al inicio del eje- NO SE
     * IMPORTAN. El archivo de la procesadora siempre va a traer el historico, y
     * cargarlo no aporta nada: esa plata ya esta informada en el saldo bancario.
     * Se cuentan aparte para que el resumen no mienta sobre el tamano del
     * archivo.
     *
     * EL PERIODO QUE CUBRE EL ARCHIVO SE PUEDE DECLARAR, y si no se declara se
     * infiere de las fechas que traen sus filas. La diferencia se ve cuando la
     * procesadora da de baja la PRIMERA o la ULTIMA acreditacion del periodo:
     * esa fecha desaparece del archivo, asi que el rango inferido se encoge y el
     * movimiento cargado queda justo afuera de la ventana. Declarar el periodo
     * -que el usuario conoce, es el que exporto- es lo que permite detectarla.
     * Nunca se adivina: sin declararlo, esa baja simplemente no se propone.
     *
     * @param array $filasArchivo Filas de parsearPlanilla()['filas']
     * @param array $existentes Movimientos activos normalizados (filaMovimiento())
     * @param array $procesadoras Filas de getProcesadoras(false)
     * @param array $alicuotas Mapa id_procesadora => filas de alicuotas
     * @param string $inicioEje 'Y-m-d' del primer dia del eje del tablero
     * @param array $periodo ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d'] declarado
     * @return array Diff completo
     */
    public static function compararImportacion($filasArchivo, $existentes, $procesadoras,
                                               $alicuotas, $inicioEje, $periodo = []) {
        $porNombre = [];

        foreach (is_array($procesadoras) ? $procesadoras : [] as $p) {
            $porNombre[self::claveNombre($p['RAZON_SOCIAL'])] = $p;
        }

        // Los cargados, indexados por las dos claves posibles.
        $porExterno = [];
        $porFecha = [];

        foreach (is_array($existentes) ? $existentes : [] as $m) {
            if (!empty($m['id_externo'])) {
                $porExterno[$m['id_procesadora'] . '|' . trim((string) $m['id_externo'])] = $m;
            }

            $porFecha[$m['id_procesadora'] . '|' . $m['fecha_acreditacion']][] = $m;
        }

        $filas = [];
        $vistas = [];
        $tocados = [];
        $inicioEje = substr((string) $inicioEje, 0, 10);

        $resumen = [
            'altas' => 0, 'cambios' => 0, 'sin_cambios' => 0,
            'ya_acreditadas' => 0, 'errores' => 0,
            'neto_altas' => 0, 'neto_diferencia' => 0
        ];

        $minFecha = null;
        $maxFecha = null;

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $cruda) {
            $fila = self::filaImportacion($cruda, $porNombre, $alicuotas, $inicioEje);

            if ($fila['estado'] !== 'ERROR' && $fila['estado'] !== 'YA_ACREDITADA') {
                $clave = self::claveImportacion($fila);

                if (isset($vistas[$clave])) {
                    $fila['estado'] = 'ERROR';
                    $fila['motivo'] = 'Esta fila repite la clave de la línea '
                        . $vistas[$clave] . ' (misma procesadora y misma fecha). Si son dos '
                        . 'liquidaciones distintas del mismo día, completá ID_EXTERNO en las dos '
                        . 'para poder diferenciarlas.';
                } else {
                    $vistas[$clave] = $fila['linea'];

                    $existente = self::buscarExistente($fila, $porExterno, $porFecha, $tocados);

                    if ($existente === null) {
                        $fila['estado'] = 'ALTA';
                        $fila['motivo'] = 'No estaba cargada.';
                    } else {
                        $tocados[$existente['id']] = true;
                        $fila = self::compararContraExistente($fila, $existente);
                    }
                }
            }

            // El rango describe lo que el archivo trae PARA IMPORTAR, asi que no
            // incluye las filas ya acreditadas. Importa doble: es lo que se
            // muestra en el resumen y es la ventana dentro de la cual se pueden
            // proponer bajas, y una ventana estirada hacia el pasado propondria
            // dar de baja algo que el archivo no estaba mirando.
            if ($fila['estado'] !== 'ERROR' && $fila['estado'] !== 'YA_ACREDITADA'
                && $fila['fecha_acreditacion'] !== null) {
                if ($minFecha === null || $fila['fecha_acreditacion'] < $minFecha) {
                    $minFecha = $fila['fecha_acreditacion'];
                }

                if ($maxFecha === null || $fila['fecha_acreditacion'] > $maxFecha) {
                    $maxFecha = $fila['fecha_acreditacion'];
                }
            }

            switch ($fila['estado']) {
                case 'ALTA':
                    $resumen['altas']++;
                    $resumen['neto_altas'] += $fila['importe_neto'];
                    break;
                case 'CAMBIO':
                    $resumen['cambios']++;
                    $resumen['neto_diferencia'] += $fila['diferencia'];
                    break;
                case 'SIN_CAMBIOS':
                    $resumen['sin_cambios']++;
                    break;
                case 'YA_ACREDITADA':
                    $resumen['ya_acreditadas']++;
                    break;
                default:
                    $resumen['errores']++;
            }

            $filas[] = $fila;
        }

        // El periodo declarado manda sobre el inferido: el usuario sabe que
        // exporto, y el archivo solo puede mostrar las fechas que quedaron.
        $periodo = is_array($periodo) ? $periodo : [];

        $ventanaDesde = isset($periodo['desde'])
            ? Horizonte::normalizarFecha($periodo['desde']) : null;
        $ventanaHasta = isset($periodo['hasta'])
            ? Horizonte::normalizarFecha($periodo['hasta']) : null;

        $declarado = ($ventanaDesde !== null || $ventanaHasta !== null);

        if ($ventanaDesde === null) {
            $ventanaDesde = $minFecha;
        }

        if ($ventanaHasta === null) {
            $ventanaHasta = $maxFecha;
        }

        $bajas = self::bajasCandidatas($existentes, $filas, $tocados, $ventanaDesde,
            $ventanaHasta, $inicioEje);

        $resumen['bajas'] = count($bajas);
        $resumen['neto_bajas'] = 0;

        foreach ($bajas as $b) {
            $resumen['neto_bajas'] += $b['importe_neto'];
        }

        $resumen['neto_altas'] = round($resumen['neto_altas'], 4);
        $resumen['neto_diferencia'] = round($resumen['neto_diferencia'], 4);
        $resumen['neto_bajas'] = round($resumen['neto_bajas'], 4);
        $resumen['filas'] = count($filas);

        return [
            'filas' => $filas,
            'bajas' => $bajas,
            'resumen' => $resumen,
            'rango' => ['desde' => $minFecha, 'hasta' => $maxFecha],
            // La ventana dentro de la cual se pueden proponer bajas, y si la
            // declaro el usuario o se infirio del archivo. Se devuelve para que
            // la pantalla lo pueda decir: es lo que explica por que una baja
            // aparece o no aparece.
            'ventana' => [
                'desde' => $ventanaDesde,
                'hasta' => $ventanaHasta,
                'declarada' => $declarado
            ],
            // Con un solo error NO se importa nada. El archivo es la fuente de
            // verdad, y una importacion a medias deja un estado que el proximo
            // diff no puede explicar: aparecerian como altas las filas que
            // quedaron afuera, mezcladas con las nuevas de verdad.
            'puede_importar' => ($resumen['errores'] === 0
                && ($resumen['altas'] + $resumen['cambios'] + $resumen['bajas']) > 0),
            'avisos' => self::avisosImportacion($resumen)
        ];
    }

    /**
     * Valida una fila del archivo y le calcula la tasa y el neto.
     *
     * El neto se calcula ACA con la misma formula de siempre: una importacion no
     * es una excepcion a la regla de que el neto no se acepta de afuera. Si el
     * archivo trajera una columna de neto, se ignoraria.
     *
     * @return array Fila con 'estado' ERROR / YA_ACREDITADA, o lista para clasificar
     */
    private static function filaImportacion($cruda, $porNombre, $alicuotas, $inicioEje) {
        $fila = [
            'linea' => isset($cruda['linea']) ? intval($cruda['linea']) : 0,
            // Los valores TAL COMO VINIERON en el archivo. Se devuelven para que
            // la confirmacion pueda mandar exactamente la misma entrada y el
            // servidor vuelva a calcular el mismo diff -incluidas las filas con
            // problemas, que es lo que hace que el "todo o nada" no dependa de
            // que el navegador las filtre-.
            'crudo' => [
                'procesadora' => (string) (isset($cruda['procesadora'])
                    ? $cruda['procesadora'] : ''),
                'importe_bruto' => (string) (isset($cruda['importe_bruto'])
                    ? $cruda['importe_bruto'] : ''),
                'fecha_acreditacion' => (string) (isset($cruda['fecha_acreditacion'])
                    ? $cruda['fecha_acreditacion'] : ''),
                'id_externo' => (string) (isset($cruda['id_externo'])
                    ? $cruda['id_externo'] : ''),
                'observaciones' => (string) (isset($cruda['observaciones'])
                    ? $cruda['observaciones'] : '')
            ],
            'procesadora' => trim((string) (isset($cruda['procesadora'])
                ? $cruda['procesadora'] : '')),
            'id_procesadora' => 0,
            'importe_bruto' => 0,
            'fecha_acreditacion' => null,
            'id_externo' => trim((string) (isset($cruda['id_externo'])
                ? $cruda['id_externo'] : '')),
            'observaciones' => trim((string) (isset($cruda['observaciones'])
                ? $cruda['observaciones'] : '')),
            'tasa_aplicada' => 0,
            'importe_neto' => 0,
            'id' => null,
            'bruto_anterior' => null,
            'fecha_anterior' => null,
            'neto_anterior' => null,
            'diferencia' => 0,
            'estado' => 'ALTA',
            'motivo' => ''
        ];

        $error = function ($fila, $motivo) {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = $motivo;

            return $fila;
        };

        if ($fila['procesadora'] === '') {
            return $error($fila, 'Falta la procesadora.');
        }

        $clave = self::claveNombre($fila['procesadora']);

        if (!isset($porNombre[$clave])) {
            return $error($fila, 'La procesadora "' . $fila['procesadora'] . '" no está cargada. '
                . 'Dala de alta en Parámetros → Cob. Electrónicos o corregí el nombre en el '
                . 'archivo.');
        }

        $procesadora = $porNombre[$clave];
        $fila['id_procesadora'] = intval($procesadora['ID']);
        // Se guarda la razon social CANONICA, la de la base, y no la que vino en
        // el archivo: asi la pantalla no muestra dos escrituras del mismo nombre.
        $fila['procesadora'] = $procesadora['RAZON_SOCIAL'];

        if (intval($procesadora['ACTIVO']) !== 1) {
            return $error($fila, 'La procesadora "' . $fila['procesadora'] . '" está '
                . 'inhabilitada, así que no admite movimientos nuevos.');
        }

        $bruto = self::numeroDesdePlanilla(isset($cruda['importe_bruto'])
            ? $cruda['importe_bruto'] : '');

        if ($bruto === null) {
            return $error($fila, 'El importe bruto "' . (isset($cruda['importe_bruto'])
                ? $cruda['importe_bruto'] : '') . '" no es un número.');
        }

        if ($bruto <= 0) {
            return $error($fila, 'El importe bruto tiene que ser mayor a cero.');
        }

        $fila['importe_bruto'] = $bruto;

        $fecha = self::fechaDesdePlanilla(isset($cruda['fecha_acreditacion'])
            ? $cruda['fecha_acreditacion'] : '');

        if ($fecha === null) {
            return $error($fila, 'La fecha de acreditación "' . (isset($cruda['fecha_acreditacion'])
                ? $cruda['fecha_acreditacion'] : '') . '" no se entiende. Usá dd/mm/aaaa o '
                . 'aaaa-mm-dd.');
        }

        $fila['fecha_acreditacion'] = $fecha;

        if (mb_strlen($fila['id_externo']) > 60) {
            return $error($fila, 'El ID_EXTERNO no puede superar los 60 caracteres.');
        }

        if (mb_strlen($fila['observaciones']) > 200) {
            $fila['observaciones'] = mb_substr($fila['observaciones'], 0, 200);
        }

        // Ya acreditada: no se importa y no es un error. Ver la nota de
        // compararImportacion().
        if ($fecha < $inicioEje) {
            $fila['estado'] = 'YA_ACREDITADA';
            $fila['motivo'] = 'Se acreditó el ' . self::fechaCorta($fecha)
                . ', antes del inicio del horizonte: ya está informada en el saldo bancario, '
                . 'así que no se importa.';

            return $fila;
        }

        $r = self::tasaRetencion(
            isset($alicuotas[$fila['id_procesadora']]) ? $alicuotas[$fila['id_procesadora']] : [],
            $fecha
        );

        if ($r['conceptos'] === 0) {
            return $error($fila, '"' . $fila['procesadora'] . '" no tiene ninguna alícuota '
                . 'vigente al ' . self::fechaCorta($fecha) . ', así que no se puede calcular el '
                . 'importe neto.');
        }

        if ($r['tasa'] >= 1) {
            return $error($fila, 'Las alícuotas vigentes de "' . $fila['procesadora'] . '" al '
                . self::fechaCorta($fecha) . ' suman ' . self::porcentaje($r['tasa'])
                . ': el neto saldría cero o negativo.');
        }

        $fila['tasa_aplicada'] = $r['tasa'];
        $fila['importe_neto'] = self::importeNeto($bruto, $r['tasa']);

        return $fila;
    }

    /** La clave con la que el archivo identifica una fila. Ver compararImportacion() */
    private static function claveImportacion($fila) {
        return ($fila['id_externo'] !== '')
            ? ('E|' . $fila['id_procesadora'] . '|' . $fila['id_externo'])
            : ('F|' . $fila['id_procesadora'] . '|' . $fila['fecha_acreditacion']);
    }

    /**
     * Busca el movimiento ya cargado que corresponde a una fila del archivo.
     *
     * Con ID_EXTERNO manda el ID_EXTERNO -incluso si la fecha cambio, que es
     * justamente el caso en que la procesadora reprograma una acreditacion-. Sin
     * el, se busca por (procesadora, fecha) y se saltean los ya emparejados, por
     * si hay mas de uno cargado ese dia.
     *
     * @param array $fila
     * @param array $porExterno
     * @param array $porFecha
     * @param array $tocados Mapa id => true de los ya emparejados
     * @return array|null
     */
    private static function buscarExistente($fila, $porExterno, $porFecha, $tocados) {
        if ($fila['id_externo'] !== '') {
            $clave = $fila['id_procesadora'] . '|' . $fila['id_externo'];

            if (isset($porExterno[$clave])) {
                return $porExterno[$clave];
            }
        }

        $clave = $fila['id_procesadora'] . '|' . $fila['fecha_acreditacion'];

        if (!isset($porFecha[$clave])) {
            return null;
        }

        foreach ($porFecha[$clave] as $m) {
            if (isset($tocados[$m['id']])) {
                continue;
            }

            // Un cargado que YA tiene otro ID_EXTERNO no es este movimiento: es
            // otra liquidacion del mismo dia.
            if ($fila['id_externo'] !== '' && !empty($m['id_externo'])
                && trim((string) $m['id_externo']) !== $fila['id_externo']) {
                continue;
            }

            return $m;
        }

        return null;
    }

    /**
     * Decide si una fila del archivo cambia algo del movimiento ya cargado.
     *
     * Se comparan el bruto y la fecha, que son los dos datos de entrada. La tasa
     * y el neto NO se comparan: son derivados, y si cambiaron sin que cambie el
     * bruto es porque cambio la alicuota, y eso lo resuelve el recalculo de
     * pendientes y no una importacion.
     *
     * El importe se compara con tolerancia de un centavo: la columna es
     * DECIMAL(19,4) y el valor da la vuelta por un CSV.
     */
    private static function compararContraExistente($fila, $existente) {
        $fila['id'] = $existente['id'];
        $fila['bruto_anterior'] = $existente['importe_bruto'];
        $fila['fecha_anterior'] = $existente['fecha_acreditacion'];
        $fila['neto_anterior'] = $existente['importe_neto'];
        $fila['diferencia'] = round($fila['importe_neto'] - $existente['importe_neto'], 4);

        $cambioImporte = (abs($existente['importe_bruto'] - $fila['importe_bruto']) > 0.005);
        $cambioFecha = ($existente['fecha_acreditacion'] !== $fila['fecha_acreditacion']);

        if (!$cambioImporte && !$cambioFecha) {
            $fila['estado'] = 'SIN_CAMBIOS';
            $fila['motivo'] = 'Ya estaba cargado igual.';

            return $fila;
        }

        $motivos = [];

        if ($cambioImporte) {
            $motivos[] = 'el importe bruto pasa de ' . self::plata($existente['importe_bruto'])
                . ' a ' . self::plata($fila['importe_bruto']);
        }

        if ($cambioFecha) {
            $motivos[] = 'la fecha pasa del ' . self::fechaCorta($existente['fecha_acreditacion'])
                . ' al ' . self::fechaCorta($fila['fecha_acreditacion']);
        }

        $fila['estado'] = 'CAMBIO';
        $fila['motivo'] = ucfirst(implode(' y ', $motivos)) . '.';

        return $fila;
    }

    /**
     * Los movimientos cargados que el archivo NO trae, y que por lo tanto la
     * procesadora ya no informa.
     *
     * ES LO QUE HACE QUE NO HAYA QUE COMPARAR A MANO. Sin esto, una acreditacion
     * que la procesadora dio de baja se queda para siempre en el tablero, porque
     * ninguna importacion la menciona.
     *
     * EL ALCANCE ES ACOTADO A PROPOSITO, y esto es lo delicado de la funcion:
     * solo se consideran los movimientos de las procesadoras que vienen en el
     * archivo, con fecha DENTRO de la ventana que el archivo cubre, y desde el
     * inicio del eje. Un archivo parcial -una sola procesadora, una sola semana-
     * no puede proponer dar de baja lo que no estaba mirando.
     *
     * Y la baja NUNCA se aplica sola: se ofrece y hay que confirmarla.
     *
     * @param string|null $desde Inicio de la ventana que cubre el archivo
     * @param string|null $hasta Fin de la ventana
     * @return array Movimientos candidatos, con el motivo
     */
    private static function bajasCandidatas($existentes, $filas, $tocados, $desde, $hasta,
                                            $inicioEje) {
        if ($desde === null || $hasta === null) {
            return [];
        }

        $procesadorasArchivo = [];

        foreach ($filas as $f) {
            if ($f['estado'] !== 'ERROR' && $f['id_procesadora'] > 0) {
                $procesadorasArchivo[$f['id_procesadora']] = true;
            }
        }

        $bajas = [];

        foreach (is_array($existentes) ? $existentes : [] as $m) {
            if (isset($tocados[$m['id']])) {
                continue;
            }

            if (!isset($procesadorasArchivo[$m['id_procesadora']])) {
                continue;
            }

            $fecha = $m['fecha_acreditacion'];

            if ($fecha === null || $fecha < $inicioEje
                || $fecha < $desde || $fecha > $hasta) {
                continue;
            }

            $m['motivo'] = 'Está cargado pero el archivo no lo trae, y su fecha cae dentro del '
                . 'período que el archivo cubre (' . self::fechaCorta($desde) . ' al '
                . self::fechaCorta($hasta) . ').';

            $bajas[] = $m;
        }

        return $bajas;
    }

    /** Avisos del resumen de una importacion */
    private static function avisosImportacion($resumen) {
        $avisos = [];

        if ($resumen['errores'] > 0) {
            $avisos[] = 'Hay ' . $resumen['errores'] . ' fila(s) con problemas, así que no se '
                . 'importa nada hasta corregirlas. Un archivo importado a medias deja un estado '
                . 'que la próxima importación no puede explicar.';
        }

        if ($resumen['ya_acreditadas'] > 0) {
            $avisos[] = $resumen['ya_acreditadas'] . ' fila(s) del archivo ya se acreditaron y no '
                . 'se importan: esa plata ya está informada en el saldo bancario de la pestaña '
                . 'Saldos.';
        }

        if ($resumen['bajas'] > 0) {
            $avisos[] = 'Hay ' . $resumen['bajas'] . ' movimiento(s) cargados que el archivo no '
                . 'trae. Se pueden dar de baja, pero hay que marcarlo expresamente: si el archivo '
                . 'era parcial, esas acreditaciones siguen siendo válidas.';
        }

        if ($resumen['errores'] === 0 && $resumen['altas'] === 0 && $resumen['cambios'] === 0
            && $resumen['bajas'] === 0) {
            $avisos[] = 'El archivo no cambia nada de lo que ya está cargado.';
        }

        return $avisos;
    }

    /** Razon social comparable: sin espacios repetidos, sin acentos y en mayusculas */
    private static function claveNombre($razonSocial) {
        return self::normalizarTitulo(self::normalizarRazonSocial($razonSocial));
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

        $sql = "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_PROCESADORA', 'U') AS P,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_ALICUOTA', 'U')    AS A,
                       OBJECT_ID('dbo.RO_T_CASHFLOW_COBEL_MOVIMIENTO', 'U')  AS M";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar las tablas de Cob. Electronicos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablas = ($row && $row['P'] !== null && $row['A'] !== null && $row['M'] !== null);

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
            $avisos[] = 'Todavía no existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql contra la base central.';

            return $avisos;
        }

        $procesadoras = $this->getProcesadoras(false);

        if (empty($procesadoras)) {
            $avisos[] = 'Todavía no hay ninguna procesadora cargada. Cargalas en '
                . 'Parámetros → Cob. Electrónicos: sin procesadora no se puede dar de alta '
                . 'ningún movimiento.';

            return $avisos;
        }

        $activas = 0;
        $sinAlicuota = [];
        $hoy = date('Y-m-d');
        $alicuotas = $this->getAlicuotasPorProcesadora();

        foreach ($procesadoras as $p) {
            if (intval($p['ACTIVO']) !== 1) {
                continue;
            }

            $activas++;

            $r = self::tasaRetencion(
                isset($alicuotas[$p['ID']]) ? $alicuotas[$p['ID']] : [], $hoy);

            if ($r['conceptos'] === 0) {
                $sinAlicuota[] = $p['RAZON_SOCIAL'];
            }
        }

        if ($activas === 0) {
            $avisos[] = 'Ninguna procesadora está activa, así que no se puede dar de alta ningún '
                . 'movimiento. Una procesadora se activa desde Parámetros → Cob. Electrónicos '
                . 'una vez que tiene al menos una alícuota vigente.';
        }

        if (!empty($sinAlicuota)) {
            $avisos[] = 'Estas procesadoras están activas pero no tienen alícuotas vigentes a hoy: '
                . implode(', ', $sinAlicuota) . '. Sus movimientos no van a poder calcular el '
                . 'importe neto hasta que se les cargue una.';
        }

        return $avisos;
    }

    /**
     * Procesadoras de pago.
     *
     * @param bool $soloActivas true para la pantalla de carga, false para el
     *        editor de Parametros, que tiene que poder ver las inactivas para
     *        activarlas
     * @return array
     */
    public function getProcesadoras($soloActivas = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT ID, RAZON_SOCIAL, ORDEN, ACTIVO, FECHA_UPDATE, USUARIO
                FROM RO_T_CASHFLOW_COBEL_PROCESADORA";

        if ($soloActivas) {
            $sql .= " WHERE ACTIVO = 1";
        }

        $sql .= " ORDER BY ORDEN, RAZON_SOCIAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las procesadoras'));
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
     * Alicuotas, de la vigencia mas nueva a la mas vieja.
     *
     * Devuelve el HISTORICO completo, no solo lo vigente: la vigencia vieja es
     * lo que explica por que un movimiento de la semana pasada tiene otra tasa,
     * y esconderla haria que ese neto pareciera un error.
     *
     * @param bool $soloActivas
     * @return array
     */
    public function getAlicuotas($soloActivas = false) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $cid = $this->conectar('central');

        $sql = "SELECT A.ID, A.ID_PROCESADORA, P.RAZON_SOCIAL, A.CONCEPTO, A.ALICUOTA,
                       A.VIGENCIA_DESDE, A.ACTIVO, A.FECHA_UPDATE, A.USUARIO
                FROM RO_T_CASHFLOW_COBEL_ALICUOTA A
                INNER JOIN RO_T_CASHFLOW_COBEL_PROCESADORA P ON P.ID = A.ID_PROCESADORA";

        if ($soloActivas) {
            $sql .= " WHERE A.ACTIVO = 1";
        }

        $sql .= " ORDER BY P.ORDEN, P.RAZON_SOCIAL, A.CONCEPTO,
                           A.VIGENCIA_DESDE DESC, A.ID DESC";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las alicuotas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['ID'] = intval($row['ID']);
            $row['ID_PROCESADORA'] = intval($row['ID_PROCESADORA']);
            $row['ALICUOTA'] = floatval($row['ALICUOTA']);
            $row['VIGENCIA_DESDE'] = Horizonte::normalizarFecha($row['VIGENCIA_DESDE']);
            $row['ACTIVO'] = intval($row['ACTIVO']);
            $row['FECHA_UPDATE'] = $this->fechaHora($row['FECHA_UPDATE']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Las alicuotas ACTIVAS agrupadas por procesadora, que es la forma en la que
     * las consumen los helpers de calculo.
     *
     * @return array Mapa ID_PROCESADORA => filas
     */
    public function getAlicuotasPorProcesadora() {
        $porProcesadora = [];

        foreach ($this->getAlicuotas(true) as $a) {
            $porProcesadora[$a['ID_PROCESADORA']][] = $a;
        }

        return $porProcesadora;
    }

    /**
     * Movimientos activos, con su procesadora.
     *
     * Devuelve la tasa y el neto TAL COMO ESTAN GUARDADOS, sin recalcular: es
     * lo que efectivamente se informo, y recalcularlo al leer haria que editar
     * una alicuota cambiara la historia en la pantalla aunque no la cambie en la
     * base.
     *
     * @param array $filtros ['id_procesadora' => int, 'desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     * @return array Filas normalizadas
     */
    public function getMovimientos($filtros = []) {
        if (!$this->tablasCreadas()) {
            return [];
        }

        $filtros = is_array($filtros) ? $filtros : [];

        $cid = $this->conectar('central');

        $sql = "SELECT M.ID, M.ID_PROCESADORA, P.RAZON_SOCIAL, M.IMPORTE_BRUTO,
                       M.FECHA_ACREDITACION, M.TASA_APLICADA, M.IMPORTE_NETO,
                       M.ORIGEN_DATO, M.ID_EXTERNO, M.ARCHIVO_ORIGEN, M.OBSERVACIONES,
                       M.FECHA_ALTA, M.FECHA_UPDATE, M.USUARIO
                FROM RO_T_CASHFLOW_COBEL_MOVIMIENTO M
                INNER JOIN RO_T_CASHFLOW_COBEL_PROCESADORA P ON P.ID = M.ID_PROCESADORA
                WHERE M.ACTIVO = 1";

        $params = [];

        if (!empty($filtros['id_procesadora'])) {
            $sql .= " AND M.ID_PROCESADORA = ?";
            $params[] = intval($filtros['id_procesadora']);
        }

        $desde = isset($filtros['desde']) ? Horizonte::normalizarFecha($filtros['desde']) : null;
        $hasta = isset($filtros['hasta']) ? Horizonte::normalizarFecha($filtros['hasta']) : null;

        if ($desde !== null) {
            $sql .= " AND M.FECHA_ACREDITACION >= ?";
            $params[] = $desde;
        }

        if ($hasta !== null) {
            $sql .= " AND M.FECHA_ACREDITACION <= ?";
            $params[] = $hasta;
        }

        $sql .= " ORDER BY M.FECHA_ACREDITACION, P.ORDEN, P.RAZON_SOCIAL, M.ID";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los movimientos'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = $this->filaMovimiento($row);
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Normaliza una fila de movimiento para la pantalla y para los helpers.
     *
     * @param array $row
     * @return array
     */
    private function filaMovimiento($row) {
        return [
            'id' => intval($row['ID']),
            'id_procesadora' => intval($row['ID_PROCESADORA']),
            'procesadora' => $row['RAZON_SOCIAL'],
            'importe_bruto' => floatval($row['IMPORTE_BRUTO']),
            'fecha_acreditacion' => Horizonte::normalizarFecha($row['FECHA_ACREDITACION']),
            'tasa_aplicada' => floatval($row['TASA_APLICADA']),
            'importe_neto' => floatval($row['IMPORTE_NETO']),
            'origen_dato' => $row['ORIGEN_DATO'],
            'id_externo' => $row['ID_EXTERNO'],
            'archivo_origen' => $row['ARCHIVO_ORIGEN'],
            'observaciones' => $row['OBSERVACIONES'],
            'fecha_alta' => $this->fechaHora($row['FECHA_ALTA']),
            'fecha_update' => $this->fechaHora($row['FECHA_UPDATE']),
            'usuario' => $row['USUARIO']
        ];
    }

    /* ====================================================================
       PAYLOAD DE LA PESTANA
       ==================================================================== */

    /**
     * Todo lo que necesita la pestana para dibujarse.
     *
     * El eje se arma con el mismo Horizonte que el tablero, para que la marca de
     * "no entra al tablero" de cada fila describa exactamente el eje que el
     * tablero esta usando y no otro.
     *
     * POR DEFECTO NO SE MUESTRAN LAS ACREDITACIONES YA OCURRIDAS. Ya pasaron, ya
     * entraron a la cuenta y estan informadas en el saldo bancario: no hay nada
     * que hacer con ellas, y tenerlas en la tabla todos los dias solo hace que
     * el total de la pantalla no coincida con el del tablero. Se ven con el
     * filtro 'incluir_acreditadas', que la pantalla ofrece como un switch.
     *
     * @param array $filtros ['id_procesadora', 'desde', 'hasta', 'incluir_acreditadas']
     * @return array
     */
    public function getPestana($filtros = [], $h = null) {
        $avisos = [];

        try {
            $avisos = $this->getAvisos();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudo verificar la configuración del módulo: ' . $e->getMessage();
        }

        require_once __DIR__ . '/Parametros.php';

        // El eje se resuelve ANTES de leer los movimientos, porque es el que
        // define desde cuando se muestran.
        if ($h === null) {
            try {
                $h = Horizonte::desdeParametros(new Parametros());
            } catch (Throwable $e) {
                // Sin el horizonte no se puede decir que entra al tablero, pero la
                // tabla de movimientos si se puede mostrar. Se usa un eje de
                // respaldo y se avisa, en vez de dejar la pantalla en blanco.
                $avisos[] = 'No se pudo leer el horizonte del tablero (' . $e->getMessage() . '), '
                    . 'así que la marca de qué movimientos entran al tablero puede no coincidir con '
                    . 'el tablero.';
                $h = new Horizonte(28, 12);
            }
        }

        // Sin filtro explicito, la tabla arranca en el inicio del eje: lo
        // anterior ya se acredito y no hay nada que hacer con eso. El switch
        // 'incluir_acreditadas' lo trae de vuelta cuando alguien quiere verlo.
        $incluirAcreditadas = !empty($filtros['incluir_acreditadas']);

        $desde = isset($filtros['desde']) ? Horizonte::normalizarFecha($filtros['desde']) : null;
        $hasta = isset($filtros['hasta']) ? Horizonte::normalizarFecha($filtros['hasta']) : null;

        if ($desde === null && !$incluirAcreditadas) {
            $desde = $h->hoy();
        }

        $consulta = [
            'id_procesadora' => isset($filtros['id_procesadora'])
                ? intval($filtros['id_procesadora']) : 0,
            'desde' => $desde,
            'hasta' => $hasta
        ];

        $procesadoras = [];
        $alicuotas = [];
        $movimientos = [];

        try {
            $procesadoras = $this->getProcesadoras(false);
            $alicuotas = $this->getAlicuotas(true);
            $movimientos = $this->getMovimientos($consulta);
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer los movimientos: ' . $e->getMessage();
        }

        $armado = self::armarMovimientos($movimientos, $h);

        // La tasa vigente a hoy de cada procesadora, para que la pantalla pueda
        // mostrar con que se va a calcular el proximo movimiento antes de
        // cargarlo.
        $hoy = date('Y-m-d');
        $porProcesadora = $this->indexarAlicuotas($alicuotas);
        $tasas = [];

        foreach ($procesadoras as $p) {
            $r = self::tasaRetencion(
                isset($porProcesadora[$p['ID']]) ? $porProcesadora[$p['ID']] : [], $hoy);

            $tasas[] = [
                'id' => $p['ID'],
                'razon_social' => $p['RAZON_SOCIAL'],
                'activo' => $p['ACTIVO'],
                'tasa' => $r['tasa'],
                'conceptos' => $r['conceptos'],
                'detalle' => array_values($r['detalle'])
            ];
        }

        return [
            'hoy' => $hoy,
            'eje' => [
                'desde' => $h->hoy(),
                'hasta' => $h->fin()
            ],
            'procesadoras' => $tasas,
            'alicuotas' => $alicuotas,
            'filas' => $armado['filas'],
            'totales' => $armado['totales'],
            'por_procesadora' => $armado['por_procesadora'],
            'por_dia' => self::agruparPorDia($armado['filas']),
            'por_mes' => self::agruparPorMes($armado['filas']),
            // Se devuelve el filtro EFECTIVO, incluido el 'desde' que puso el
            // servidor: si la pantalla mostrara el campo vacio, el usuario
            // leeria la tabla como si fueran todos los movimientos.
            'filtros' => [
                'id_procesadora' => $consulta['id_procesadora'],
                'desde' => $consulta['desde'],
                'hasta' => $consulta['hasta'],
                'incluir_acreditadas' => $incluirAcreditadas,
                'desde_por_defecto' => ($desde === $h->hoy()
                    && !isset($filtros['desde']) && !$incluirAcreditadas)
            ],
            'avisos' => array_merge($avisos, $armado['avisos'])
        ];
    }

    /** Agrupa una lista de alicuotas por ID_PROCESADORA */
    private function indexarAlicuotas($alicuotas) {
        $v = [];

        foreach (is_array($alicuotas) ? $alicuotas : [] as $a) {
            $v[intval($a['ID_PROCESADORA'])][] = $a;
        }

        return $v;
    }

    /* ====================================================================
       IMPORTACION: PREVISUALIZAR Y CONFIRMAR

       SON DOS PASOS Y NO UNO. El primero no escribe nada: lee el archivo, lo
       compara con lo cargado y devuelve que cambiaria. El segundo aplica.

       El segundo paso NO confia en lo que muestra la pantalla: vuelve a leer la
       base, vuelve a validar cada fila y vuelve a calcular el diff con el mismo
       helper puro. El cliente manda los DATOS DE ENTRADA del archivo -los mismos
       que tipearia a mano-, y la tasa y el neto los sigue calculando el
       servidor. Si entre la previsualizacion y la confirmacion cambio algo -otro
       usuario cargo un movimiento, alguien edito una alicuota-, lo que se aplica
       es el diff contra el estado real, no contra el que se dibujo.
       ==================================================================== */

    /**
     * Lee un archivo subido y devuelve que cambiaria, sin escribir nada.
     *
     * @param string $contenido Contenido del archivo
     * @param string $archivo Nombre del archivo, para el resumen
     * @param array $periodo Periodo que cubre el archivo, si se declaro
     * @return array Diff de compararImportacion(), con el nombre del archivo
     */
    public function previsualizarImportacion($contenido, $archivo = '', $periodo = []) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql.');
        }

        $parseado = self::parsearPlanilla($contenido);
        $diff = $this->diffImportacion($parseado['filas'], $periodo);

        $diff['archivo'] = (string) $archivo;
        $diff['separador'] = ($parseado['separador'] === "\t") ? 'tabulacion'
            : $parseado['separador'];

        return $diff;
    }

    /**
     * Aplica una importacion: altas, cambios y -si se pide- las bajas.
     *
     * TODO EN UNA TRANSACCION. A mitad de camino quedarian algunas
     * acreditaciones actualizadas y otras no, y la proxima importacion mostraria
     * un diff que no describe ni el archivo viejo ni el nuevo.
     *
     * CON UN SOLO ERROR NO SE IMPORTA NADA. El archivo es la fuente de verdad:
     * importar la mitad deja un estado que el proximo diff no puede explicar,
     * porque las filas que quedaron afuera aparecerian como altas nuevas.
     *
     * @param array $filas Filas del archivo, tal como las devolvio la
     *        previsualizacion (los datos de entrada, no la tasa ni el neto)
     * @param string $archivo Nombre del archivo, se guarda en ARCHIVO_ORIGEN
     * @param bool $aplicarBajas Si se dan de baja los cargados que el archivo
     *        no trae. Por defecto NO: un archivo parcial no puede borrar nada
     * @param string|null $usuario
     * @param array $periodo Periodo que cubre el archivo, si se declaro. Tiene
     *        que ser el MISMO que se uso al previsualizar, o las bajas que se
     *        aplican no serian las que se mostraron
     * @return array El diff aplicado, con lo que efectivamente se escribio
     */
    public function importar($filas, $archivo = '', $aplicarBajas = false, $usuario = null,
                             $periodo = []) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql.');
        }

        if (!is_array($filas) || empty($filas)) {
            throw new Exception('No llegó ninguna fila para importar');
        }

        // Se vuelve a calcular el diff contra el estado REAL de la base.
        $diff = $this->diffImportacion($filas, $periodo);

        if ($diff['resumen']['errores'] > 0) {
            throw new Exception('El archivo tiene ' . $diff['resumen']['errores'] . ' fila(s) con '
                . 'problemas, así que no se importó nada. Corregilas y volvé a previsualizar.');
        }

        if (!$diff['puede_importar'] && !($aplicarBajas && $diff['resumen']['bajas'] > 0)) {
            throw new Exception('El archivo no cambia nada de lo que ya está cargado.');
        }

        $archivo = substr(trim((string) $archivo), 0, 120);
        $archivo = ($archivo === '') ? null : $archivo;

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        $aplicado = ['altas' => 0, 'cambios' => 0, 'bajas' => 0];

        try {
            $sqlAlta = "INSERT INTO RO_T_CASHFLOW_COBEL_MOVIMIENTO
                            (ID_PROCESADORA, IMPORTE_BRUTO, FECHA_ACREDITACION, TASA_APLICADA,
                             IMPORTE_NETO, ORIGEN_DATO, ID_EXTERNO, ARCHIVO_ORIGEN,
                             OBSERVACIONES, ACTIVO, FECHA_ALTA, FECHA_UPDATE, USUARIO)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?)";

            $sqlCambio = "UPDATE RO_T_CASHFLOW_COBEL_MOVIMIENTO
                          SET IMPORTE_BRUTO = ?, FECHA_ACREDITACION = ?, TASA_APLICADA = ?,
                              IMPORTE_NETO = ?, ORIGEN_DATO = ?, ID_EXTERNO = ?,
                              ARCHIVO_ORIGEN = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                          WHERE ID = ? AND ACTIVO = 1";

            foreach ($diff['filas'] as $f) {
                if ($f['estado'] === 'ALTA') {
                    $ok = sqlsrv_query($cid, $sqlAlta, [
                        $f['id_procesadora'], $f['importe_bruto'], $f['fecha_acreditacion'],
                        $f['tasa_aplicada'], $f['importe_neto'], self::ORIGEN_ARCHIVO,
                        ($f['id_externo'] === '' ? null : $f['id_externo']), $archivo,
                        ($f['observaciones'] === '' ? null : $f['observaciones']), $usuario
                    ]);

                    if ($ok === false) {
                        throw new Exception($this->errorSql('Error al importar la línea '
                            . $f['linea']));
                    }

                    $aplicado['altas']++;
                    continue;
                }

                // SIN_CAMBIOS no se escribe: pisarle FECHA_UPDATE a todo lo que
                // el archivo repite dejaria la columna diciendo que se edito
                // todo en cada importacion, igual que el diff de las sucursales
                // de Saldos.
                if ($f['estado'] !== 'CAMBIO') {
                    continue;
                }

                $ok = sqlsrv_query($cid, $sqlCambio, [
                    $f['importe_bruto'], $f['fecha_acreditacion'], $f['tasa_aplicada'],
                    $f['importe_neto'], self::ORIGEN_ARCHIVO,
                    ($f['id_externo'] === '' ? null : $f['id_externo']), $archivo,
                    $usuario, $f['id']
                ]);

                if ($ok === false) {
                    throw new Exception($this->errorSql('Error al actualizar el movimiento '
                        . $f['id'] . ' (línea ' . $f['linea'] . ')'));
                }

                $aplicado['cambios']++;
            }

            if ($aplicarBajas) {
                $sqlBaja = "UPDATE RO_T_CASHFLOW_COBEL_MOVIMIENTO
                            SET ACTIVO = 0, FECHA_UPDATE = GETDATE(), USUARIO = ?
                            WHERE ID = ? AND ACTIVO = 1";

                foreach ($diff['bajas'] as $b) {
                    if (sqlsrv_query($cid, $sqlBaja, [$usuario, $b['id']]) === false) {
                        throw new Exception($this->errorSql('Error al dar de baja el movimiento '
                            . $b['id']));
                    }

                    $aplicado['bajas']++;
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la importación'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        $diff['aplicado'] = $aplicado;
        $diff['archivo'] = $archivo;

        return $diff;
    }

    /**
     * El diff de una importacion contra el estado actual de la base.
     *
     * Lo usan la previsualizacion y la confirmacion, para que las dos comparen
     * con la misma regla. La decision de que cambia vive en el helper puro
     * compararImportacion(); esto solo junta los datos que necesita.
     *
     * @param array $filasCrudas Filas del archivo
     * @param array $periodo Periodo declarado que cubre el archivo
     * @return array
     */
    private function diffImportacion($filasCrudas, $periodo = []) {
        return self::compararImportacion(
            $filasCrudas,
            $this->getMovimientos(),
            $this->getProcesadoras(false),
            $this->getAlicuotasPorProcesadora(),
            $this->inicioEje(),
            $periodo
        );
    }

    /**
     * Primer dia del eje del tablero: es el corte a partir del cual una
     * acreditacion todavia no ocurrio.
     *
     * Si el horizonte no se puede leer se usa hoy, que es el mismo dia con el
     * que arranca el eje en el caso normal: es preferible a no poder importar.
     *
     * @return string 'Y-m-d'
     */
    private function inicioEje() {
        require_once __DIR__ . '/Parametros.php';

        try {
            return Horizonte::desdeParametros(new Parametros())->hoy();
        } catch (Throwable $e) {
            return date('Y-m-d');
        }
    }

    /* ====================================================================
       ESCRITURAS DE MOVIMIENTOS

       LA TASA Y EL NETO NO SE ACEPTAN DEL NAVEGADOR NUNCA. Del cliente vienen
       la procesadora, el importe bruto, la fecha y las observaciones; el resto
       lo resuelve el servidor. Aceptar el neto permitiria guardar cualquier
       numero como si fuera el calculado, que es la version informatica del
       *0.969 escrito a mano en el Excel.
       ==================================================================== */

    /**
     * Alta de un movimiento.
     *
     * @param int $idProcesadora
     * @param float $bruto Importe bruto informado por la procesadora
     * @param string $fecha 'Y-m-d' de acreditacion
     * @param string|null $observaciones
     * @param string|null $usuario
     * @return array ['id', 'tasa', 'neto', 'avisos']
     */
    public function addMovimiento($idProcesadora, $bruto, $fecha, $observaciones = null,
                                  $usuario = null) {
        $datos = $this->prepararMovimiento($idProcesadora, $bruto, $fecha);
        $cid = $this->conectar('central');

        $obs = trim((string) $observaciones);

        $sql = "INSERT INTO RO_T_CASHFLOW_COBEL_MOVIMIENTO
                    (ID_PROCESADORA, IMPORTE_BRUTO, FECHA_ACREDITACION, TASA_APLICADA,
                     IMPORTE_NETO, ORIGEN_DATO, OBSERVACIONES, ACTIVO,
                     FECHA_ALTA, FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [
            $datos['id_procesadora'],
            $datos['bruto'],
            $datos['fecha'],
            $datos['tasa'],
            $datos['neto'],
            self::ORIGEN_MANUAL,
            ($obs === '' ? null : substr($obs, 0, 200)),
            $usuario
        ]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el movimiento'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return [
            'id' => intval($row['ID']),
            'tasa' => $datos['tasa'],
            'neto' => $datos['neto'],
            'avisos' => $datos['avisos']
        ];
    }

    /**
     * Edicion de un movimiento: bruto y fecha de acreditacion.
     *
     * LA TASA SE VUELVE A RESOLVER, porque se resuelve contra la FECHA DE
     * ACREDITACION: si la fecha cambia, la tasa que corresponde puede ser otra.
     * Dejar la tasa vieja con una fecha nueva guardaria un neto que ninguna
     * vigencia justifica.
     *
     * @param int $id
     * @param float $bruto
     * @param string $fecha
     * @param string|null $observaciones
     * @param string|null $usuario
     * @return array ['tasa', 'neto', 'avisos']
     */
    public function saveMovimiento($id, $bruto, $fecha, $observaciones = null, $usuario = null) {
        $id = intval($id);
        $actual = $this->movimientoPorId($id);

        if ($actual === null) {
            throw new Exception('El movimiento ' . $id . ' no existe o está dado de baja');
        }

        // La procesadora NO se exige activa para editar. Inhabilitarla frena las
        // altas nuevas, no la correccion de un importe ya informado: si se
        // exigiera, un movimiento de una procesadora dada de baja quedaria sin
        // salida -no se puede editar, y tampoco se puede dar de baja y volver a
        // cargar, porque el alta si exige la procesadora activa-.
        $datos = $this->prepararMovimiento($actual['id_procesadora'], $bruto, $fecha, false);
        $cid = $this->conectar('central');

        $obs = trim((string) $observaciones);

        $sql = "UPDATE RO_T_CASHFLOW_COBEL_MOVIMIENTO
                SET IMPORTE_BRUTO = ?, FECHA_ACREDITACION = ?, TASA_APLICADA = ?,
                    IMPORTE_NETO = ?, OBSERVACIONES = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ? AND ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql, [
            $datos['bruto'],
            $datos['fecha'],
            $datos['tasa'],
            $datos['neto'],
            ($obs === '' ? null : substr($obs, 0, 200)),
            $usuario,
            $id
        ]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el movimiento ' . $id));
        }

        sqlsrv_free_stmt($stmt);

        return [
            'tasa' => $datos['tasa'],
            'neto' => $datos['neto'],
            'avisos' => $datos['avisos']
        ];
    }

    /**
     * Baja LOGICA de un movimiento. No hay baja fisica: el movimiento deja de
     * sumar al tablero y de verse en la pantalla, pero la fila queda.
     *
     * @param int $id
     * @param string|null $usuario
     * @return bool
     */
    public function bajaMovimiento($id, $usuario = null) {
        $cid = $this->conectar('central');

        $sql = "UPDATE RO_T_CASHFLOW_COBEL_MOVIMIENTO
                SET ACTIVO = 0, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ? AND ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql, [$usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el movimiento ' . $id));
        }

        $afectadas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($afectadas < 1) {
            throw new Exception('El movimiento ' . intval($id) . ' no existe o ya estaba dado '
                . 'de baja');
        }

        return true;
    }

    /**
     * Valida lo que llega del cliente y resuelve la tasa y el neto.
     *
     * Todo lo que puede rechazar un alta pasa por aca, para que el alta y la
     * edicion no puedan validar distinto.
     *
     * La alicuota vigente se exige SIEMPRE -sin ella no hay neto que calcular-,
     * pero que la procesadora este activa solo en el alta: ver la nota de
     * saveMovimiento().
     *
     * @param int $idProcesadora
     * @param float $bruto
     * @param string $fecha
     * @param bool $exigirActiva Si la procesadora tiene que estar habilitada
     * @return array ['id_procesadora', 'bruto', 'fecha', 'tasa', 'neto', 'avisos']
     */
    private function prepararMovimiento($idProcesadora, $bruto, $fecha, $exigirActiva = true) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql.');
        }

        $idProcesadora = intval($idProcesadora);
        $bruto = floatval($bruto);
        $fecha = Horizonte::normalizarFecha($fecha);

        if ($bruto <= 0) {
            throw new Exception('El importe bruto tiene que ser mayor a cero');
        }

        if ($fecha === null) {
            throw new Exception('La fecha de acreditación es obligatoria: sin ella el movimiento '
                . 'no se puede ubicar en el tablero.');
        }

        $procesadora = $this->procesadoraPorId($idProcesadora);

        if ($procesadora === null) {
            throw new Exception('La procesadora ' . $idProcesadora . ' no existe');
        }

        if ($exigirActiva && intval($procesadora['ACTIVO']) !== 1) {
            throw new Exception('La procesadora "' . $procesadora['RAZON_SOCIAL'] . '" está '
                . 'inhabilitada, así que no admite movimientos nuevos.');
        }

        $alicuotas = $this->getAlicuotasPorProcesadora();

        // Puede lanzar: es la regla de que un movimiento no puede quedar sin
        // neto. Ver resolverTasa().
        $r = self::resolverTasa(
            isset($alicuotas[$idProcesadora]) ? $alicuotas[$idProcesadora] : [],
            $fecha,
            $procesadora['RAZON_SOCIAL']
        );

        $avisos = [];

        // Dos movimientos de la misma procesadora y la misma fecha se AVISAN, no
        // se bloquean: puede haber dos liquidaciones el mismo dia.
        $mismoDia = $this->contarMovimientos($idProcesadora, $fecha);

        if ($mismoDia > 0) {
            $avisos[] = 'Ya había ' . $mismoDia . ' movimiento(s) de "'
                . $procesadora['RAZON_SOCIAL'] . '" con fecha ' . self::fechaCorta($fecha)
                . '. Puede ser correcto —dos liquidaciones el mismo día— pero revisá que no sea '
                . 'una carga repetida.';
        }

        return [
            'id_procesadora' => $idProcesadora,
            'bruto' => $bruto,
            'fecha' => $fecha,
            'tasa' => $r['tasa'],
            'neto' => self::importeNeto($bruto, $r['tasa']),
            'avisos' => $avisos
        ];
    }

    /* ====================================================================
       ABM DE LOS PARAMETROS DEL MODULO

       Sigue el patron de Saldos: un getter con filtro de activos, un save que no
       crea, un add que chequea la clave natural antes de insertar para dar un
       mensaje entendible en vez del error del indice, y NINGUNA baja fisica.

       Vive en esta clase y no en Parametros porque las tablas son del modulo y
       este mismo archivo ya las lee para calcular; una segunda copia de las
       consultas en otra clase termina desincronizada. Parametros las expone en
       su pestana delegando aca.
       ==================================================================== */

    /**
     * Alta de una procesadora.
     *
     * ENTRA INACTIVA. Es un criterio DISTINTO al de una cuenta de Saldos, que
     * nace activa, y la diferencia es que aca hay un invariante que se puede
     * romper: una procesadora activa sin alicuotas vigentes habilita altas de
     * movimientos que despues no pueden calcular neto, que es exactamente la
     * causa de que D6, D7 y D24 esten vacias en el Excel. Una cuenta de Saldos
     * no rompe nada al nacer vacia, porque su saldo se muestra como "sin cargar"
     * y no como cero.
     *
     * @param string $razonSocial
     * @param string|null $usuario
     * @return int ID de la procesadora creada
     */
    public function addProcesadora($razonSocial, $usuario = null) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql.');
        }

        $razonSocial = self::normalizarRazonSocial($razonSocial);

        if ($razonSocial === '') {
            throw new Exception('La procesadora necesita una razón social');
        }

        if (mb_strlen($razonSocial) > 80) {
            throw new Exception('La razón social no puede superar los 80 caracteres');
        }

        $cid = $this->conectar('central');

        // Se chequea antes de insertar para poder decir que la procesadora
        // existe PERO ESTA INHABILITADA, que es el caso en el que hay que
        // activarla y no crear otra.
        $stmt = sqlsrv_query($cid,
            "SELECT ID, ACTIVO FROM RO_T_CASHFLOW_COBEL_PROCESADORA WHERE RAZON_SOCIAL = ?",
            [$razonSocial]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la procesadora'));
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($existe) {
            throw new Exception('Ya existe una procesadora "' . $razonSocial . '" ('
                . (intval($existe['ACTIVO']) === 1 ? 'activa' : 'inhabilitada') . ')');
        }

        $sql = "INSERT INTO RO_T_CASHFLOW_COBEL_PROCESADORA
                    (RAZON_SOCIAL, ORDEN, ACTIVO, FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?,
                    (SELECT ISNULL(MAX(ORDEN), 0) + 10 FROM RO_T_CASHFLOW_COBEL_PROCESADORA),
                    0, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql, [$razonSocial, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al crear la procesadora'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($row['ID']);
    }

    /**
     * Edita una procesadora: razon social y habilitacion.
     *
     * NO SE PUEDE ACTIVAR UNA PROCESADORA SIN ALICUOTAS VIGENTES. Es el mismo
     * invariante que el alta, verificado del otro lado: si se pudiera activar
     * vacia, el alta de movimientos quedaria habilitada para una procesadora que
     * no puede calcular neto.
     *
     * @param int $id
     * @param string $razonSocial
     * @param bool $activo
     * @param string|null $usuario
     * @return bool
     */
    public function saveProcesadora($id, $razonSocial, $activo = true, $usuario = null) {
        $id = intval($id);
        $razonSocial = self::normalizarRazonSocial($razonSocial);

        if ($razonSocial === '') {
            throw new Exception('La procesadora necesita una razón social');
        }

        if (mb_strlen($razonSocial) > 80) {
            throw new Exception('La razón social no puede superar los 80 caracteres');
        }

        $procesadora = $this->procesadoraPorId($id);

        if ($procesadora === null) {
            throw new Exception('La procesadora ' . $id . ' no existe');
        }

        if ($activo) {
            $alicuotas = $this->getAlicuotasPorProcesadora();

            $r = self::tasaRetencion(
                isset($alicuotas[$id]) ? $alicuotas[$id] : [], date('Y-m-d'));

            if ($r['conceptos'] === 0) {
                throw new Exception('"' . $razonSocial . '" no se puede activar porque no tiene '
                    . 'ninguna alícuota vigente: sus movimientos no podrían calcular el importe '
                    . 'neto. Cargale una alícuota primero, en la sección de abajo.');
            }
        }

        $cid = $this->conectar('central');

        $sql = "UPDATE RO_T_CASHFLOW_COBEL_PROCESADORA
                SET RAZON_SOCIAL = ?, ACTIVO = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ?";

        $stmt = sqlsrv_query($cid, $sql, [$razonSocial, ($activo ? 1 : 0), $usuario, $id]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la procesadora ' . $id));
        }

        sqlsrv_free_stmt($stmt);

        return true;
    }

    /**
     * Carga una alicuota: SIEMPRE INSERTA UNA VIGENCIA NUEVA.
     *
     * Editar un porcentaje no es un UPDATE sobre la fila vieja. La vigencia
     * anterior queda: es lo que explica con que tasa se calculo un movimiento ya
     * informado, y pisarla haria que ese neto pareciera un error de calculo.
     *
     * DESPUES DE GUARDAR SE RECALCULAN LOS MOVIMIENTOS PENDIENTES de esa
     * procesadora, por decision del negocio. Los ya acreditados NO se tocan.
     * El resultado del recalculo vuelve en la respuesta para que la pantalla
     * pueda decir cuantos movimientos cambiaron y por cuanta plata: un recalculo
     * que no se informa es un cambio de importes en silencio.
     *
     * @param int $idProcesadora
     * @param string $concepto 'IIBB', 'SICREB' o el que aparezca
     * @param float $alicuota Fraccion: 2,5% es 0.025
     * @param string $vigenciaDesde 'Y-m-d'
     * @param string|null $usuario
     * @return array ['id', 'tasa_total', 'recalculo']
     */
    public function addAlicuota($idProcesadora, $concepto, $alicuota, $vigenciaDesde,
                                $usuario = null) {
        if (!$this->tablasCreadas()) {
            throw new Exception('No existen las tablas del módulo Cob. Electrónicos. '
                . 'Corré sql/cashflow_cob_electronicos.sql.');
        }

        $idProcesadora = intval($idProcesadora);
        $procesadora = $this->procesadoraPorId($idProcesadora);

        if ($procesadora === null) {
            throw new Exception('La procesadora ' . $idProcesadora . ' no existe');
        }

        $concepto = trim((string) $concepto);
        $vigencia = Horizonte::normalizarFecha($vigenciaDesde);

        $alicuotas = $this->getAlicuotasPorProcesadora();

        // Valida sobre el ESTADO RESULTANTE: la suma de las vigentes con esta
        // alicuota reemplazando a la de su concepto. Puede lanzar.
        $tasaTotal = self::validarAlicuota(
            isset($alicuotas[$idProcesadora]) ? $alicuotas[$idProcesadora] : [],
            $concepto,
            $alicuota,
            $vigencia
        );

        $cid = $this->conectar('central');

        $sql = "INSERT INTO RO_T_CASHFLOW_COBEL_ALICUOTA
                    (ID_PROCESADORA, CONCEPTO, ALICUOTA, VIGENCIA_DESDE, ACTIVO,
                     FECHA_UPDATE, USUARIO)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?, 1, GETDATE(), ?)";

        $stmt = sqlsrv_query($cid, $sql,
            [$idProcesadora, $concepto, floatval($alicuota), $vigencia, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la alicuota'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        // El cache de alicuotas de esta instancia quedo viejo: se relee en
        // recalcularPendientes().
        return [
            'id' => intval($row['ID']),
            'tasa_total' => $tasaTotal,
            'recalculo' => $this->recalcularPendientes($idProcesadora, $usuario)
        ];
    }

    /**
     * Baja LOGICA de una vigencia de alicuota.
     *
     * NO SE PUEDE DEJAR UNA PROCESADORA ACTIVA SIN NINGUNA ALICUOTA VIGENTE: es
     * el mismo invariante de saveProcesadora(), verificado en el tercer extremo.
     * Si hay que sacarle todas las retenciones a una procesadora, primero se
     * inhabilita la procesadora.
     *
     * @param int $id
     * @param string|null $usuario
     * @return array ['recalculo' => array]
     */
    public function bajaAlicuota($id, $usuario = null) {
        $id = intval($id);
        $alicuota = null;

        foreach ($this->getAlicuotas(false) as $a) {
            if ($a['ID'] === $id) {
                $alicuota = $a;
                break;
            }
        }

        if ($alicuota === null) {
            throw new Exception('La alícuota ' . $id . ' no existe');
        }

        if (intval($alicuota['ACTIVO']) !== 1) {
            throw new Exception('La alícuota ' . $id . ' ya estaba dada de baja');
        }

        $idProcesadora = $alicuota['ID_PROCESADORA'];
        $procesadora = $this->procesadoraPorId($idProcesadora);

        if ($procesadora !== null && intval($procesadora['ACTIVO']) === 1) {
            // Se simula el estado resultante, no se confia en lo que quedaria:
            // mismo criterio que el validador de la estructura del tablero.
            $restantes = [];

            foreach ($this->getAlicuotasPorProcesadora() as $idp => $filas) {
                if ($idp !== $idProcesadora) {
                    continue;
                }

                foreach ($filas as $f) {
                    if ($f['ID'] !== $id) {
                        $restantes[] = $f;
                    }
                }
            }

            $r = self::tasaRetencion($restantes, date('Y-m-d'));

            if ($r['conceptos'] === 0) {
                throw new Exception('"' . $procesadora['RAZON_SOCIAL'] . '" quedaría activa sin '
                    . 'ninguna alícuota vigente, y sus movimientos no podrían calcular el importe '
                    . 'neto. Inhabilitá la procesadora antes de darle de baja la última alícuota.');
            }
        }

        $cid = $this->conectar('central');

        $sql = "UPDATE RO_T_CASHFLOW_COBEL_ALICUOTA
                SET ACTIVO = 0, FECHA_UPDATE = GETDATE(), USUARIO = ?
                WHERE ID = ? AND ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql, [$usuario, $id]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la alicuota ' . $id));
        }

        sqlsrv_free_stmt($stmt);

        return ['recalculo' => $this->recalcularPendientes($idProcesadora, $usuario)];
    }

    /**
     * Recalcula el neto de los movimientos PENDIENTES de una procesadora con las
     * alicuotas vigentes de ahora.
     *
     * Es lo que corre solo al guardar o dar de baja una alicuota, por decision
     * del negocio. Solo toca movimientos con FECHA_ACREDITACION >= hoy: los ya
     * acreditados conservan su tasa, porque su plata ya entro con esa tasa.
     *
     * Que movimientos cambian lo decide planRecalculo(), que es un helper puro y
     * esta cubierto por las pruebas. Aca solo se escribe lo que ese plan dice, y
     * en UNA transaccion: a mitad de camino quedaria una parte de los pendientes
     * con la tasa nueva y otra con la vieja, sin ninguna forma de saber cual es
     * cual.
     *
     * @param int $idProcesadora
     * @param string|null $usuario
     * @return array El plan aplicado, con 'aplicados'
     */
    public function recalcularPendientes($idProcesadora, $usuario = null) {
        $idProcesadora = intval($idProcesadora);
        $hoy = date('Y-m-d');

        $movimientos = $this->getMovimientos(['id_procesadora' => $idProcesadora]);
        $plan = self::planRecalculo($movimientos, $this->getAlicuotasPorProcesadora(), $hoy);

        $plan['aplicados'] = 0;

        if (empty($plan['cambios'])) {
            return $plan;
        }

        $cid = $this->conectar('central');

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo iniciar la transacción'));
        }

        try {
            $sql = "UPDATE RO_T_CASHFLOW_COBEL_MOVIMIENTO
                    SET TASA_APLICADA = ?, IMPORTE_NETO = ?, FECHA_UPDATE = GETDATE(), USUARIO = ?
                    WHERE ID = ? AND ACTIVO = 1";

            foreach ($plan['cambios'] as $c) {
                if (sqlsrv_query($cid, $sql,
                    [$c['tasa_nueva'], $c['neto_nuevo'], $usuario, $c['id']]) === false) {
                    throw new Exception($this->errorSql('Error al recalcular el movimiento '
                        . $c['id']));
                }

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

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @return array|null Procesadora por ID, con ACTIVO y RAZON_SOCIAL */
    private function procesadoraPorId($id) {
        $id = intval($id);

        foreach ($this->getProcesadoras(false) as $p) {
            if ($p['ID'] === $id) {
                return $p;
            }
        }

        return null;
    }

    /** @return array|null Movimiento activo por ID, normalizado */
    private function movimientoPorId($id) {
        $cid = $this->conectar('central');

        $sql = "SELECT M.ID, M.ID_PROCESADORA, P.RAZON_SOCIAL, M.IMPORTE_BRUTO,
                       M.FECHA_ACREDITACION, M.TASA_APLICADA, M.IMPORTE_NETO,
                       M.ORIGEN_DATO, M.ID_EXTERNO, M.ARCHIVO_ORIGEN, M.OBSERVACIONES,
                       M.FECHA_ALTA, M.FECHA_UPDATE, M.USUARIO
                FROM RO_T_CASHFLOW_COBEL_MOVIMIENTO M
                INNER JOIN RO_T_CASHFLOW_COBEL_PROCESADORA P ON P.ID = M.ID_PROCESADORA
                WHERE M.ID = ? AND M.ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql, [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el movimiento ' . $id));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? $this->filaMovimiento($row) : null;
    }

    /** Cuantos movimientos activos hay de una procesadora en una fecha */
    private function contarMovimientos($idProcesadora, $fecha) {
        $cid = $this->conectar('central');

        $sql = "SELECT COUNT(*) AS N FROM RO_T_CASHFLOW_COBEL_MOVIMIENTO
                WHERE ID_PROCESADORA = ? AND FECHA_ACREDITACION = ? AND ACTIVO = 1";

        $stmt = sqlsrv_query($cid, $sql, [intval($idProcesadora), $fecha]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar movimientos repetidos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? intval($row['N']) : 0;
    }

    /** @param string $servidor @return resource Conexion, con el error ya traducido */
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

    /** Formato de importe para los mensajes de aviso */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** Una tasa como porcentaje, para los mensajes: 0.031 => '3,1%' */
    private static function porcentaje($tasa) {
        return rtrim(rtrim(number_format(floatval($tasa) * 100, 4, ',', '.'), '0'), ',') . '%';
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
