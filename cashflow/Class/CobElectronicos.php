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
     * Tampoco se delega la decision a Horizonte::acumular(): una fecha del mes
     * en curso anterior a hoy caeria en la columna de su mes, que es una columna
     * que el tablero ni siquiera incluye en el arrastre. El corte por fecha es
     * explicito para que no dependa de como quede armado el eje.
     *
     * Una fecha POSTERIOR al eje tambien queda afuera, con su propio aviso: es
     * una fecha que el horizonte no cubre.
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
     *   - movimientos que NO entran al tablero, con el importe y la fecha
     *   - dos movimientos de la misma procesadora y la misma fecha, que se
     *     AVISAN y NO se bloquean: puede haber dos liquidaciones el mismo dia, y
     *     el aviso alcanza para detectar el pegado doble
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
            'fuera_eje' => 0,
            'fuera_eje_movimientos' => 0
        ];

        $fueraAnterior = 0;
        $fueraPosterior = 0;
        $fechaMasVieja = null;
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

            if ($ubicacion !== 'DENTRO') {
                $totales['fuera_eje'] += $neto;
                $totales['fuera_eje_movimientos']++;

                if ($ubicacion === 'ANTERIOR') {
                    $fueraAnterior += $neto;

                    if ($fecha !== null && ($fechaMasVieja === null || $fecha < $fechaMasVieja)) {
                        $fechaMasVieja = $fecha;
                    }
                } else {
                    $fueraPosterior += $neto;
                }
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

        if ($fueraAnterior != 0) {
            $avisos[] = 'Hay ' . self::plata($fueraAnterior) . ' de acreditaciones con fecha '
                . 'anterior al inicio del horizonte (la más vieja, del '
                . self::fechaCorta($fechaMasVieja) . '). No entran al tablero a propósito: son '
                . 'movimientos ya ocurridos y esa plata ya está informada en el saldo bancario '
                . 'de la pestaña Saldos. Sumarlas acá las contaría dos veces.';
        }

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
     * Lo que cae fuera del eje va a 'fuera_horizonte' CON AVISO y nunca se
     * descarta en silencio. Ver ubicacionEnEje() para por que una fecha pasada
     * no abre el horizonte, a diferencia de Saldos.
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

        $anterior = 0;
        $posterior = 0;
        $fechaMasVieja = null;
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

            if ($ubicacion === 'ANTERIOR') {
                $serie['fuera_horizonte'] += $importe;
                $anterior += $importe;

                if ($fechaMasVieja === null || $fecha < $fechaMasVieja) {
                    $fechaMasVieja = $fecha;
                }

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

        if ($anterior != 0) {
            $serie['warnings'][] = 'Cobranzas Pagos Electronicos: ' . self::plata($anterior)
                . ' netos tienen fecha de acreditacion anterior al inicio del horizonte (la mas '
                . 'vieja, del ' . self::fechaCorta($fechaMasVieja) . ') y NO entran al tablero. '
                . 'Es a proposito: ya se acreditaron, asi que esa plata ya esta informada en el '
                . 'saldo bancario de la pestana Saldos y sumarla aca la contaria dos veces.';
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
     * @param array $filtros ['id_procesadora', 'desde', 'hasta']
     * @return array
     */
    public function getPestana($filtros = []) {
        $avisos = [];

        try {
            $avisos = $this->getAvisos();
        } catch (Throwable $e) {
            $avisos[] = 'No se pudo verificar la configuración del módulo: ' . $e->getMessage();
        }

        $procesadoras = [];
        $alicuotas = [];
        $movimientos = [];

        try {
            $procesadoras = $this->getProcesadoras(false);
            $alicuotas = $this->getAlicuotas(true);
            $movimientos = $this->getMovimientos($filtros);
        } catch (Throwable $e) {
            $avisos[] = 'No se pudieron leer los movimientos: ' . $e->getMessage();
        }

        require_once __DIR__ . '/Parametros.php';

        $h = null;

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
            'filtros' => [
                'id_procesadora' => isset($filtros['id_procesadora'])
                    ? intval($filtros['id_procesadora']) : 0,
                'desde' => isset($filtros['desde'])
                    ? Horizonte::normalizarFecha($filtros['desde']) : null,
                'hasta' => isset($filtros['hasta'])
                    ? Horizonte::normalizarFecha($filtros['hasta']) : null
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
