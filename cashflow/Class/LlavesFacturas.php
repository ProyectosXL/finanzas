<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/AlquileresImputacion.php';
require_once __DIR__ . '/LlavesCalculo.php';
require_once __DIR__ . '/LlavesParametros.php';
require_once __DIR__ . '/Aviso.php';

/**
 * LlavesFacturas
 * Las facturas de alquiler marcadas como llave o renovacion en Alquileres ->
 * Facturas: con que local, que concepto (llave, comisiones, FPC, obra) y que mes
 * se imputan, y en cuantos pagos se pagan. Las tablas son las del script 68
 * (sql/cashflow_llaves_facturas.sql).
 *
 * A DONDE VA UNA CUOTA MARCADA LO DECIDE AlquileresFacturas::destino()
 * (A_LLAVES): sale de Alquileres y no vuelve a Proveedores Locales. QUE
 * REEMPLAZA EN LLAVES LO DECIDE LlavesCalculo (facturasDe()): todo lo
 * proyectado de ese local y ese concepto en esa renovacion, como la imputacion
 * de Alquileres (parte 13), que manda lo real.
 *
 * LA RENOVACION SE RESUELVE AL MARCAR (renovacionDeMes()) y se guarda por el
 * contrato que se renueva: la factura es un hecho de ese momento, y la marca
 * sigue valiendo cuando administracion carga el contrato nuevo.
 *
 * LA FACTURA NO ES EL PAGO. Se puede repartir en N pagos con fecha e importe
 * (por defecto, las cuotas del operador desde su vencimiento a considerar);
 * sin pagos, va entera en el vencimiento a considerar. Los pagos suman el
 * pendiente menos la retencion, al centavo. Repartir solo se puede de a UNA
 * factura: con varias tildadas, cada una va entera.
 *
 * UNA YA PAGADA (de los ultimos 12 meses) tambien se marca: no suma -ya salio
 * la plata- y corta la estimacion.
 *
 * Esta clase valida (pura), lee y escribe. Deshacer es baja logica.
 */
class LlavesFacturas {

    const T_FACTURA = 'RO_T_CASHFLOW_LLAVES_FACTURA';
    const T_PAGO = 'RO_T_CASHFLOW_LLAVES_FACTURA_PAGO';
    const SCRIPT = 'sql/cashflow_llaves_facturas.sql';

    /**
     * Los conceptos con los que se marca una factura. Comisiones y FPC
     * reemplazan solo lo suyo (script 73). OTROS ya no se ofrece: las marcas
     * viejas con ese concepto se siguen leyendo (cortan comisiones, FPC y
     * escribano) y se avisa para que se rehagan.
     */
    const CONCEPTOS = [LlavesCalculo::LLAVE => 'Llave', LlavesCalculo::COMISIONES => 'Comisiones', LlavesCalculo::FPC => 'FPC',
                       LlavesCalculo::OBRA => 'Obra'];

    /** Los que necesitan el script 73 (el CHECK de CONCEPTO) */
    const CONCEPTOS_73 = [LlavesCalculo::COMISIONES, LlavesCalculo::FPC];

    /** El nombre de cada concepto de una marca, tambien el viejo */
    const NOMBRES = self::CONCEPTOS + [LlavesCalculo::OTROS => 'Otros gastos (marca anterior)'];

    /**
     * Los conceptos que se pueden elegir hoy: sin el script 73, sin Comisiones
     * ni FPC (la base los rechazaria). Pura.
     *
     * @param bool $ajustes Si ya se corrio el script 73 (su parte de facturas)
     * @return array concepto => nombre
     */
    public static function conceptos($ajustes) {
        return $ajustes ? self::CONCEPTOS : array_diff_key(self::CONCEPTOS, array_flip(self::CONCEPTOS_73));
    }

    /** Tope de pagos por factura: una red contra un copiar y pegar, como Proveedores::MAX_PARTES */
    const MAX_PAGOS = 50;

    /** Cuantos meses de pagadas se pueden marcar */
    const MESES_PAGADAS = 12;

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    /** @var bool|null */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       PURAS
       ==================================================================== */

    /**
     * La renovacion de un local a la que se imputa una factura de llave u
     * otros gastos, por el mes que se eligio: la que empieza mas cerca de ese
     * mes (si empatan, la posterior). Las candidatas son las de LlavesCalculo:
     * cada contrato cargado con su anterior, y la estimada del vigente si no
     * tiene renovacion. Arcos, factura de agosto de 2026: su renovacion
     * estimada de enero de 2027 (el contrato #22, de 2023, queda lejos).
     *
     * @param array $contratos Los del local
     * @param string $mes 'Y-m'
     * @param string $hoy 'Y-m-d'
     * @return array|null ['ant', 'contrato', 'inicio', 'texto']
     */
    public static function renovacionDeMes($contratos, $mes, $hoy) {
        $ks = LlavesCalculo::limpiar($contratos);
        $cands = [];

        foreach ($ks as $c) {
            $ant = LlavesCalculo::anteriorDe($ks, $c);
            $cands[] = ['ant' => ($ant === null) ? null : $ant['id'], 'contrato' => $c['id'], 'inicio' => $c['desde'],
                        'texto' => 'contrato #' . $c['id'] . ' del ' . LlavesCalculo::corta($c['desde'])];
        }

        $vig = LlavesCalculo::vigente($ks, $hoy);

        if ($vig !== null && LlavesCalculo::renovacionDe($ks, $vig) === null) {
            $ini = date('Y-m-d', strtotime($vig['hasta'] . ' +1 day'));
            $cands[] = ['ant' => $vig['id'], 'contrato' => null, 'inicio' => $ini,
                        'texto' => 'renovación del contrato que vence el ' . LlavesCalculo::corta($vig['hasta'])];
        }

        $mejor = null;
        $dist = null;

        foreach ($cands as $c) {
            $d = abs(self::mesesEntre(substr($c['inicio'], 0, 7), $mes));

            if ($dist === null || $d < $dist || ($d === $dist && $c['inicio'] > $mejor['inicio'])) {
                $mejor = $c;
                $dist = $d;
            }
        }

        return $mejor;
    }

    private static function mesesEntre($a, $b) {
        return ((int) substr($b, 0, 4) - (int) substr($a, 0, 4)) * 12 + (int) substr($b, 5, 2) - (int) substr($a, 5, 2);
    }

    /**
     * Los pagos por defecto de una factura: N cuotas mensuales iguales desde
     * su vencimiento a considerar, el mismo dia de cada mes corrido al habil.
     * Un vencimiento ya pasado va al siguiente habil desde hoy. Pura.
     *
     * @return array [['fecha', 'importe']]
     */
    public static function pagosDefecto($total, $n, $vto, $habiles, $hoy) {
        $partes = LlavesCalculo::cuotas($total, max(1, (int) $n));
        $out = [];

        foreach ($partes as $i => $imp) {
            $d = LlavesCalculo::diaDelMes(Inflacion::mesMas(substr($vto, 0, 7), $i), (int) substr($vto, 8, 2), $habiles);
            $fecha = $d['fecha'];

            if ($fecha < $hoy) {
                $fecha = DiasHabiles::siguiente($hoy, $habiles)['fecha'];
            }

            $out[] = ['fecha' => $fecha, 'importe' => $imp];
        }

        return $out;
    }

    /**
     * Valida marcar unas cuotas como llave. Pura: la previsualizacion y el
     * guardado son esta misma funcion, contra lo de ahora.
     *
     * @param array $d ['cuotas' => [['cod_provee', 't_comp', 'n_comp', 'vto_tango']], 'nro', 'concepto', 'mes',
     *                  'pagos' => [['fecha', 'importe']], 'retencion', 'observacion']
     * @param array $e ['imputables' => claveCuota => fila (pendientes y pagadas), 'imputadas' => claveCuota => id
     *                  de imputacion de Alquileres, 'excluidas' => claveCuota => ..., 'llaves' => claveCuota => ...,
     *                  'locales' => NRO => nombre, 'contratos' => NRO => [...], 'hoy' => 'Y-m-d',
     *                  'conceptos' => concepto => nombre (conceptos(); por defecto, todos)]
     * @return array ['ok', 'errores', 'cuotas' => [filas], 'nro', 'concepto', 'mes', 'ant', 'contrato',
     *                'renovacion', 'pagos', 'retencion', 'observacion', 'total']
     */
    public static function validar($d, $e) {
        $errores = [];
        $filas = [];

        foreach (is_array($d['cuotas'] ?? null) ? $d['cuotas'] : [] as $c) {
            $f = ['COD_PROVEE' => $c['cod_provee'] ?? '', 'T_COMP' => $c['t_comp'] ?? '', 'N_COMP' => $c['n_comp'] ?? '',
                  'FECHA_VTO' => $c['vto_tango'] ?? ''];
            $clave = AlquileresFacturas::claveCuota($f);
            $nombre = trim($f['T_COMP'] . ' ' . $f['N_COMP']);

            if (isset($filas[$clave])) {
                continue;
            }

            if (isset($e['llaves'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' ya está marcada como llave: deshacé la marca para cambiarla.';
            } elseif (isset($e['imputadas'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' está imputada en Alquileres (#' . $e['imputadas'][$clave]
                    . '): deshacé primero la imputación.';
            } elseif (isset($e['excluidas'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' está excluida: volvé a incluirla para marcarla como llave.';
            } elseif (!isset($e['imputables'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' ya no está en Alquileres › Facturas: volvé a cargar la pestaña.';
            } else {
                $filas[$clave] = $e['imputables'][$clave];
            }
        }

        if (empty($filas) && empty($errores)) {
            $errores[] = 'Elegí al menos una factura.';
        }

        $nro = (int) ($d['nro'] ?? 0);

        if (!isset($e['locales'][$nro])) {
            $errores[] = 'Elegí el local al que se imputa.';
        }

        $concepto = strtoupper(trim((string) ($d['concepto'] ?? '')));
        $permitidos = $e['conceptos'] ?? self::CONCEPTOS;

        if (isset(self::CONCEPTOS[$concepto]) && !isset($permitidos[$concepto])) {
            $errores[] = 'Todavía no se puede marcar una factura como ' . mb_strtolower(self::CONCEPTOS[$concepto], 'UTF-8')
                . ': falta correr ' . LlavesParametros::SCRIPT_AJUSTES . ' contra la base central.';
        } elseif (!isset(self::CONCEPTOS[$concepto])) {
            $errores[] = 'Elegí el concepto: llave, comisiones, FPC u obra.';
        }

        $mes = trim((string) ($d['mes'] ?? ''));

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $errores[] = 'Elegí el mes al que se imputa.';
        }

        /* LA RENOVACION: la del mes elegido. Una obra no tiene: su clave es el
           local y el mes. */
        $ren = null;

        if (empty($errores) && $concepto !== LlavesCalculo::OBRA) {
            $ren = self::renovacionDeMes($e['contratos'][$nro] ?? [], $mes, $e['hoy']);

            if ($ren === null) {
                $errores[] = $e['locales'][$nro] . ' no tiene contratos en la app de alquileres: no hay renovación a la que '
                    . 'imputar la factura.';
            }
        }

        /* LOS PAGOS Y LA RETENCION: de a una factura, y no de una ya pagada. */
        $pagos = [];
        $ret = null;
        $total = round(array_sum(array_map(function ($f) {
            return !empty($f['PAGADA_TANGO']) ? (float) $f['IMPORTE_VTO'] : (float) $f['IMPORTE_PENDIENTE'];
        }, $filas)), 2);
        $pagada = count(array_filter($filas, function ($f) { return !empty($f['PAGADA_TANGO']); })) > 0;
        $pedidos = is_array($d['pagos'] ?? null) ? $d['pagos'] : [];
        $retTxt = trim((string) ($d['retencion'] ?? ''));

        if ((!empty($pedidos) || $retTxt !== '') && (count($filas) !== 1 || $pagada)) {
            $errores[] = $pagada ? 'Una factura ya pagada no se reparte en pagos: ya salió de caja.'
                : 'Repartir en pagos o cargar una retención se hace de a una factura: con varias, cada una va entera en su '
                  . 'vencimiento a considerar.';
        } else {
            if ($retTxt !== '') {
                try {
                    $ret = LlavesParametros::numero($retTxt, 'La retención');
                } catch (InvalidArgumentException $x) {
                    $errores[] = $x->getMessage();
                }

                if ($ret !== null && $ret > $total) {
                    $errores[] = 'La retención no puede superar el pendiente de la factura.';
                }
            }

            $neto = round($total - (float) $ret, 2);

            if (count($pedidos) > self::MAX_PAGOS) {
                $errores[] = 'Hasta ' . self::MAX_PAGOS . ' pagos por factura.';
            } else {
                foreach ($pedidos as $i => $p) {
                    try {
                        $fecha = LlavesParametros::fecha($p['fecha'] ?? '', 'la fecha del pago ' . ($i + 1));
                        $imp = LlavesParametros::numero($p['importe'] ?? null, 'El importe del pago ' . ($i + 1), false);

                        if ($fecha < $e['hoy']) {
                            $errores[] = 'El pago ' . ($i + 1) . ' tiene fecha anterior a hoy: lo que se paga, se paga de hoy en adelante.';
                        }

                        if ($imp <= 0) {
                            $errores[] = 'El pago ' . ($i + 1) . ' tiene que ser mayor que 0.';
                        }

                        $pagos[] = ['fecha' => $fecha, 'importe' => $imp];
                    } catch (InvalidArgumentException $x) {
                        $errores[] = $x->getMessage();
                    }
                }

                if (!empty($pagos) && abs(array_sum(array_column($pagos, 'importe')) - $neto) > 0.005) {
                    $errores[] = 'Los pagos suman ' . self::plata(array_sum(array_column($pagos, 'importe')))
                        . ' y tienen que sumar el pendiente menos la retención: ' . self::plata($neto) . '.';
                }
            }
        }

        try {
            $obs = LlavesParametros::texto($d['observacion'] ?? null, LlavesParametros::LARGO_TEXTO, 'La observación');
        } catch (InvalidArgumentException $x) {
            $errores[] = $x->getMessage();
            $obs = null;
        }

        return [
            'ok' => empty($errores), 'errores' => array_values(array_unique($errores)), 'cuotas' => array_values($filas),
            'nro' => $nro, 'concepto' => $concepto, 'mes' => $mes, 'ant' => $ren['ant'] ?? null,
            'contrato' => $ren['contrato'] ?? null, 'renovacion' => $ren['texto'] ?? null, 'pagos' => $pagos,
            'retencion' => $ret, 'observacion' => $obs, 'total' => $total, 'pagada' => $pagada
        ];
    }

    /**
     * Las marcas vigentes como las pide LlavesCalculo (su 'facturas'): una por
     * cuota, con sus pagos y si ya esta pagada.
     *
     *   PAGADA_AL_MARCAR, o la cuota ya no esta pendiente en Tango: pagada, no
     *     suma y corta igual. Si dejo de estar pendiente sin pagarse (anulada),
     *     tambien deja de figurar pendiente: se avisa para que se revise.
     *   pendiente con pagos: esos pagos; uno con fecha pasada va al siguiente
     *     habil desde hoy, como en Alquileres.
     *   pendiente sin pagos: entera (pendiente de hoy - retencion) en su
     *     vencimiento a considerar.
     *
     * Pura.
     *
     * @param array $marcas Lo de vigentes()
     * @param array $pendientes claveCuota => fila de getPendientes() (las de destino A_LLAVES)
     * @param array $vtos claveCuota => vencimiento editado (AlquileresFacturaVto)
     * @param array $dias COD => dia de vencimiento de Parametros -> Alquileres
     * @param array $habiles
     * @param string $hoy
     * @return array ['facturas' => [...], 'avisos' => [...]]
     */
    public static function paraCalculo($marcas, $pendientes, $vtos, $dias, $habiles, $hoy) {
        $out = [];
        $noEstan = [];
        $cambiaron = [];

        foreach ($marcas as $clave => $m) {
            $p = $pendientes[$clave] ?? null;
            $comp = trim($m['T_COMP'] . ' ' . $m['N_COMP']);
            $f = ['id' => $m['ID'], 'nro' => $m['NRO_SUCURS'], 'concepto' => $m['CONCEPTO'], 'mes' => $m['MES'],
                  'ant' => $m['ID_CONTRATO_ANT'], 'contrato' => $m['ID_CONTRATO'], 'cod_provee' => $m['COD_PROVEE'],
                  'comprobante' => $comp, 'pagada' => true, 'pagos' => [], 'clave' => $clave,
                  'texto' => 'Marcada por ' . ($m['USUARIO_ALTA'] ?? '—') . ' el ' . substr((string) $m['FECHA_ALTA'], 0, 10) . '.'];

            if (!$m['PAGADA_AL_MARCAR'] && $p === null) {
                $noEstan[] = $comp;
            }

            if (!$m['PAGADA_AL_MARCAR'] && $p !== null) {
                $f['pagada'] = false;
                $pend = round((float) $p['IMPORTE_PENDIENTE'], 2);
                $neto = round($pend - (float) $m['RETENCION'], 2);

                if (!empty($m['PAGOS'])) {
                    if (abs($pend - (float) $m['IMPORTE']) > 0.005) {
                        $cambiaron[] = $comp;
                    }

                    foreach ($m['PAGOS'] as $x) {
                        $d = AlquileresImputacion::fechaDePago($x['FECHA'], $habiles, $hoy);
                        $f['pagos'][] = ['fecha' => $d['fecha'], 'importe' => (float) $x['IMPORTE'],
                                         'nota' => AlquileresImputacion::notaFecha($d)];
                    }
                } else {
                    $vto = AlquileresImputacion::vtoAConsiderar($p, $vtos, $dias);
                    $d = AlquileresImputacion::fechaDePago($vto, $habiles, $hoy);
                    $f['pagos'][] = ['fecha' => $d['fecha'], 'importe' => $neto,
                                     'nota' => 'Entera en su vencimiento a considerar. ' . AlquileresImputacion::notaFecha($d)];
                }
            }

            $out[] = $f;
        }

        $avisos = [];

        if (!empty($noEstan)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Facturas marcadas como llave que ya no están pendientes en Tango: '
                . implode(', ', $noEstan) . '. Se toman como pagadas (no suman y siguen cortando lo proyectado). Si alguna '
                . 'se anuló, deshacé la marca.', 'Facturas');
        }

        if (!empty($cambiaron)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Facturas marcadas como llave cuyo pendiente cambió desde que se '
                . 'repartieron en pagos: ' . implode(', ', $cambiaron) . '. Los pagos siguen como se cargaron: deshacé la '
                . 'marca y volvé a repartirla.', 'Facturas');
        }

        return ['facturas' => $out, 'avisos' => $avisos];
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio el script 68. Nunca lanza. */
    public function tablaCreada() {
        if ($this->tabla === null) {
            try {
                $a = $this->fila("SELECT OBJECT_ID(?, 'U') AS A, OBJECT_ID(?, 'U') AS B",
                    ['dbo.' . self::T_FACTURA, 'dbo.' . self::T_PAGO], 'Error al verificar las tablas');
                $this->tabla = ($a !== null && $a['A'] !== null && $a['B'] !== null);
            } catch (Throwable $e) {
                $this->tabla = false;
            }
        }

        return $this->tabla;
    }

    /** @var bool|null */
    private $conceptos73 = null;

    /**
     * Los conceptos que se pueden elegir hoy (conceptos()), mirando si el
     * script 73 ya rehizo el CHECK de CONCEPTO. Nunca lanza.
     *
     * @return array concepto => nombre
     */
    public function conceptosMarcables() {
        if ($this->conceptos73 === null) {
            try {
                $r = $this->fila("SELECT COUNT(*) AS N FROM sys.check_constraints WHERE name = 'CK_RO_T_CF_LLFAC_CONCEPTO'
                                    AND definition LIKE '%COMISIONES%' AND definition LIKE '%FPC%'", [],
                    'Error al verificar los conceptos de las facturas de llave');
                $this->conceptos73 = (int) ($r['N'] ?? 0) > 0;
            } catch (Throwable $e) {
                $this->conceptos73 = false;
            }
        }

        return self::conceptos($this->conceptos73);
    }

    public function avisoSinTabla() {
        return 'Todavía no se pueden marcar facturas como llave: falta correr ' . self::SCRIPT . ' contra la base central.';
    }

    /**
     * Las marcas vigentes, por cuota, con sus pagos.
     *
     * @return array claveCuota => fila + 'PAGOS' => [['ORDEN', 'FECHA', 'IMPORTE']]
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $out = [];
        $porId = [];

        foreach ($this->filas("SELECT * FROM dbo." . self::T_FACTURA . " WHERE VIGENTE = 1", [],
                              'Error al leer las facturas marcadas como llave') as $r) {
            $f = [
                'ID' => (int) $r['ID'], 'COD_PROVEE' => Planilla::codigo($r['COD_PROVEE']), 'T_COMP' => Planilla::codigo($r['T_COMP']),
                'N_COMP' => Planilla::codigo($r['N_COMP']), 'FECHA_VTO_TANGO' => self::dia($r['FECHA_VTO_TANGO']),
                'NRO_SUCURS' => (int) $r['NRO_SUCURS'], 'CONCEPTO' => trim($r['CONCEPTO']), 'MES' => trim($r['MES']),
                'ID_CONTRATO_ANT' => ($r['ID_CONTRATO_ANT'] === null) ? null : (int) $r['ID_CONTRATO_ANT'],
                'ID_CONTRATO' => ($r['ID_CONTRATO'] === null) ? null : (int) $r['ID_CONTRATO'],
                'IMPORTE' => (float) $r['IMPORTE'], 'RETENCION' => ($r['RETENCION'] === null) ? null : (float) $r['RETENCION'],
                'PAGADA_AL_MARCAR' => (bool) $r['PAGADA_AL_MARCAR'], 'OBSERVACION' => $r['OBSERVACION'],
                'USUARIO_ALTA' => $r['USUARIO_ALTA'], 'FECHA_ALTA' => self::momento($r['FECHA_ALTA']), 'PAGOS' => []
            ];
            $clave = AlquileresFacturas::claveCuota($f);
            $out[$clave] = $f;
            $porId[$f['ID']] = $clave;
        }

        if (!empty($porId)) {
            foreach ($this->filas("SELECT ID_FACTURA, ORDEN, FECHA, IMPORTE FROM dbo." . self::T_PAGO
                                  . " WHERE VIGENTE = 1 ORDER BY ID_FACTURA, ORDEN", [],
                                  'Error al leer los pagos de las facturas de llave') as $p) {
                $id = (int) $p['ID_FACTURA'];

                if (isset($porId[$id])) {
                    $out[$porId[$id]]['PAGOS'][] = ['ORDEN' => (int) $p['ORDEN'], 'FECHA' => self::dia($p['FECHA']),
                                                    'IMPORTE' => (float) $p['IMPORTE']];
                }
            }
        }

        return $out;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Marca las cuotas ya validadas (validar()), en una transaccion: o todas o
     * ninguna.
     *
     * @return int[] Los ID de las marcas
     */
    public function marcar($v, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        if (empty($v['ok'])) {
            throw new InvalidArgumentException('No se marcó: ' . implode(' ', $v['errores'] ?? []));
        }

        $ids = [];

        $this->transaccion(function () use ($v, $usuario, &$ids) {
            foreach ($v['cuotas'] as $f) {
                $pagada = !empty($f['PAGADA_TANGO']);
                $importe = $pagada ? (float) $f['IMPORTE_VTO'] : (float) $f['IMPORTE_PENDIENTE'];
                $r = $this->fila("INSERT INTO dbo." . self::T_FACTURA . "
                        (COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO, NRO_SUCURS, CONCEPTO, MES, ID_CONTRATO_ANT, ID_CONTRATO,
                         IMPORTE, RETENCION, PAGADA_AL_MARCAR, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     OUTPUT INSERTED.ID VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                    [Planilla::codigo($f['COD_PROVEE']), Planilla::codigo($f['T_COMP']), Planilla::codigo($f['N_COMP']),
                     substr((string) $f['FECHA_VTO'], 0, 10), $v['nro'], $v['concepto'], $v['mes'], $v['ant'], $v['contrato'],
                     round($importe, 2), $v['retencion'], $pagada ? 1 : 0, $v['observacion'], $usuario, $usuario],
                    'Error al marcar la factura como llave');
                $id = (int) $r['ID'];
                $ids[] = $id;

                foreach ($v['pagos'] as $i => $p) {
                    $this->ejecutar("INSERT INTO dbo." . self::T_PAGO . " (ID_FACTURA, ORDEN, FECHA, IMPORTE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                                     VALUES (?, ?, ?, ?, 1, ?, ?)", [$id, $i + 1, $p['fecha'], $p['importe'], $usuario, $usuario],
                        'Error al guardar los pagos de la factura');
                }
            }
        });

        return $ids;
    }

    /**
     * Deshace una marca: baja logica de la cuota y de sus pagos. La cuota
     * vuelve a Sin imputar en Alquileres y lo proyectado vuelve.
     *
     * @return bool Si estaba vigente
     */
    public function deshacer($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();
        $i = (int) $id;
        $n = 0;

        $this->transaccion(function () use ($i, $usuario, &$n) {
            $n = $this->ejecutar("UPDATE dbo." . self::T_FACTURA . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
                . " WHERE ID = ? AND VIGENTE = 1", [$usuario, $usuario, $i], 'Error al deshacer la marca');
            $this->ejecutar("UPDATE dbo." . self::T_PAGO . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
                . " WHERE ID_FACTURA = ? AND VIGENTE = 1", [$usuario, $usuario, $i], 'Error al deshacer los pagos');
        });

        return $n > 0;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new InvalidArgumentException($this->avisoSinTabla());
        }
    }

    private function transaccion($fn) {
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $fn();

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }
    }

    private function filas($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        $out = [];

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = $r;
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    private function fila($sql, $params, $contexto) {
        $f = $this->filas($sql, $params, $contexto);

        return $f[0] ?? null;
    }

    private function ejecutar($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        $n = intval(sqlsrv_rows_affected($stmt));
        sqlsrv_free_stmt($stmt);

        return $n;
    }

    private static function dia($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d') : (($v === null || $v === '') ? null : substr((string) $v, 0, 10));
    }

    private static function momento($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : (($v === null || $v === '') ? null : substr((string) $v, 0, 16));
    }

    private static function plata($n) {
        return '$ ' . number_format((float) $n, 2, ',', '.');
    }

    private function conectar() {
        if ($this->cid === null) {
            $cid = $this->conn->conectar('central');

            if (!$cid) {
                throw new Exception('No se pudo conectar a la base de datos central');
            }

            $this->cid = $cid;
        }

        return $this->cid;
    }

    private function errorSql($contexto) {
        foreach (sqlsrv_errors() ?: [] as $e) {
            if (strpos($e['message'], 'UX_RO_T_CF_LLFAC_VIGENTE') !== false) {
                return 'Alguna de esas facturas ya se marcó como llave desde otra pantalla. Volvé a cargar la pestaña. No se guardó nada.';
            }
        }

        $msg = $contexto . ': ';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
