<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/DiasHabiles.php';
require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/AlquileresFacturas.php';

/**
 * AlquileresImputacion
 * Que reemplaza una factura de alquiler imputada, cuanto se paga y cuando.
 *
 * ES LA UNICA REGLA de "que reemplaza esta imputacion". La usan la pestaña
 * Alquileres (Proyeccion y Facturas) y el proveedor del tablero, que suman la
 * MISMA lista de pagos que devuelve aplicar(): una por mes y la otra por dia.
 *
 * ESTATICA Y PURA. Los pendientes de Tango, las imputaciones guardadas, los
 * vencimientos editados, la proyeccion, el calendario y la fecha de hoy entran
 * como parametros. Quien lee y escribe es AlquileresImputaciones; quien arma,
 * Alquileres.
 *
 * QUE ES UNA IMPUTACION
 * ---------------------
 * Una o varias cuotas de UNA RAZON SOCIAL, imputadas a TODOS sus locales (los
 * que la tienen como principal o como adicional) o a UNO, en UN MES DE PAGO
 * (el mes cuya proyeccion se reemplaza), con sus RETENCIONES POR TIPO. El total
 * a pagar es el pendiente de las cuotas menos las retenciones. No se eligen
 * conceptos: una factura de alquiler es de una razon social y paga todo lo que
 * ella factura en esos locales ese mes. Antes se abria en renglones de
 * conceptos x locales, y armarlos era trabajo que no cambiaba lo que se paga.
 *
 * QUE REEMPLAZA
 * -------------
 * TODO LO PROYECTADO DE ESA RAZON SOCIAL, EN ESOS LOCALES, EN ESE MES DE PAGO:
 * fijos, fondo, expensas, variables y extraordinarios. "De esa razon social" es
 * el cod_provee de cada pago, que dice quien factura cada concepto
 * (AlquileresPago::conceptosPorRazon()) y de quien es cada extraordinario. Va
 * sobre la lista de pagos y no sobre el calculo del concepto porque reemplaza
 * por mes de pago: los variables de septiembre de IRSA se pagan en noviembre, y
 * una factura con mes noviembre es la que los pisa.
 *
 *   - MANDA LO REAL: menor o mayor que lo proyectado, no queda saldo.
 *   - LO QUE NO SE IMPUTO SIGUE PROYECTADO: los otros meses, los otros locales
 *     (si se eligio uno) y lo que otra razon social factura en el mismo local
 *     (San Justo: una factura de SAUCCS no pisa el VMA de SASANJ).
 *   - DOS IMPUTACIONES VIGENTES NO PISAN LO MISMO: misma razon social, mismo
 *     mes y algun local en comun no se puede guardar. Con locales distintos si
 *     (una factura por local).
 *   - UN MES FUERA DEL HORIZONTE (el anterior) no pisa nada que se vea: la
 *     factura se paga igual en su fecha.
 *
 * CUANTO Y CUANDO
 * ---------------
 * Cada cuota pendiente se paga en su VENCIMIENTO A CONSIDERAR (el editado, o el
 * dia de Parametros de su razon social en el mes del vencimiento de Tango:
 * vtoAConsiderar()), corrido al siguiente habil, y si ya paso, el siguiente
 * habil desde hoy: nunca apilado en el primer dia del eje. Paga su pendiente
 * menos SU PARTE DE LAS RETENCIONES: el total de retenciones se reparte entre
 * las cuotas en proporcion a su pendiente, por resto mayor al centavo
 * (repartirCentavos()), y las partes suman exacto el total a pagar.
 *
 * SIN REPARTO ENTRE LOCALES. La factura es de la razon social: en la grilla se
 * ve como una linea "Facturas imputadas" al final de la razon social, y cada
 * pieza va con nro null. Abrirla por local era inventar un reparto que la
 * factura no dice.
 *
 * CUANDO TANGO LA CANCELA
 * -----------------------
 * La cuota sale de pendientes. Si Tango la tiene con fecha de cancelacion queda
 * PAGADA: deja de pagarse (la plata ya salio) y SIGUE REEMPLAZANDO, para que el
 * mes no vuelva a proyectarse. Si no aparece pagada (anulada) o Tango no se pudo
 * leer, deja de reemplazar y la proyeccion vuelve, con aviso: es el lado que
 * muestra la salida de caja, no el que la esconde. Con varias facturas, la
 * imputacion deja de reemplazar solo si TODAS quedaron asi.
 *
 * Si el pendiente cambia sin cancelarse (nota de credito, pago parcial en
 * Tango), se paga el pendiente nuevo menos su retencion y se avisa.
 *
 * PARA CORREGIR UNA IMPUTACION SE DESHACE Y SE ARMA DE NUEVO: no se edita, y
 * una cuota ya imputada no se puede imputar otra vez.
 */
class AlquileresImputacion {

    /** Lo que puede diferir un importe visto del de ahora, y las retenciones del total */
    const CENTAVO = 0.005;

    /** El grupo de la grilla de la linea "Facturas imputadas" */
    const GRUPO_FACTURAS = 'FACTURAS';

    /** El estado de una cuota en la sub-pestaña Facturas */
    const SIN_IMPUTAR = 'SIN_IMPUTAR';
    const IMPUTADA = 'IMPUTADA';
    const PARCIAL = 'PARCIAL';
    const PAGADA = 'PAGADA';
    const ANULADA = 'ANULADA';
    const SIN_DATO = 'SIN_DATO';
    const SUSPENDIDA = 'SUSPENDIDA';

    /**
     * Emitida en el mes en curso y ya cancelada en Tango, SIN IMPUTAR. No genera
     * pago (ya salio la plata), pero la proyeccion del mes sigue en el flujo
     * hasta que se impute: imputada y pagada, la reemplaza.
     */
    const PAGADA_SIN_IMPUTAR = 'PAGADA_SIN_IMPUTAR';

    const NOMBRE_ESTADO = [
        self::SIN_IMPUTAR => 'Sin imputar',
        self::IMPUTADA => 'Imputada',
        self::PARCIAL => 'Imputada parcialmente',
        self::PAGADA => 'Pagada',
        self::ANULADA => 'Ya no está en Tango',
        self::SIN_DATO => 'Sin dato de Tango',
        self::SUSPENDIDA => 'Imputación suspendida',
        self::PAGADA_SIN_IMPUTAR => 'Pagada, sin imputar'
    ];

    /** De donde sale el vencimiento a considerar de una cuota (origenVto()) */
    const VTO_EDITADO = 'EDITADO';
    const VTO_PARAMETRO = 'PARAMETRO';
    const VTO_TANGO = 'TANGO';

    /* ====================================================================
       CLAVES Y FECHAS
       ==================================================================== */

    /**
     * La clave de una cuota: comprobante + vencimiento de Tango. Acepta una fila
     * de getPendientes() (FECHA_VTO) o de las tablas (FECHA_VTO_TANGO).
     *
     * @param array $f
     * @return string 'COD|T|N|Y-m-d'
     */
    public static function claveCuota($f) {
        $vto = isset($f['FECHA_VTO_TANGO']) ? $f['FECHA_VTO_TANGO'] : (isset($f['FECHA_VTO']) ? $f['FECHA_VTO'] : '');

        return Planilla::codigo($f['COD_PROVEE'] ?? '') . '|' . Planilla::codigo($f['T_COMP'] ?? '') . '|'
            . Planilla::codigo($f['N_COMP'] ?? '') . '|' . substr((string) $vto, 0, 10);
    }

    /**
     * Una cuota ya pagada en Tango (Proveedores::getCanceladasEmitidas()) vista
     * como pendiente para validar su imputacion: lo que se imputa es el importe
     * de la cuota. PAGADA_TANGO la distingue: no se le cambia el vencimiento y no
     * se paga.
     */
    public static function comoPendiente($pagada) {
        return ['IMPORTE_PENDIENTE' => round((float) $pagada['IMPORTE_VTO'], 2), 'PAGADA_TANGO' => true] + $pagada;
    }

    /**
     * El dia de vencimiento de Parametros de cada razon social: el suyo, o el
     * general. Una razon sin dia propio y sin general no esta en el mapa.
     *
     * @param array $razones COD_PROVEE => ['dia' => int|null, ...] (las vigentes)
     * @param int|null $diaGeneral
     * @return array COD_PROVEE => int
     */
    public static function diasDeVencimiento($razones, $diaGeneral) {
        $out = [];

        foreach ($razones as $cod => $r) {
            $d = ($r['dia'] ?? null) ?? $diaGeneral;

            if ($d !== null) {
                $out[Planilla::codigo($cod)] = (int) $d;
            }
        }

        return $out;
    }

    /**
     * El vencimiento de una cuota segun Parametros: el dia de su razon social
     * (o el general) EN EL MES DEL VENCIMIENTO DE TANGO. Sin dia en
     * Parametros, el de Tango. Los dias van de 1 a 28: existen en todo mes.
     *
     * @param array $cuota Fila de getPendientes()
     * @param array $dias COD_PROVEE => dia (diasDeVencimiento())
     * @return string 'Y-m-d'
     */
    public static function vtoDeParametro($cuota, $dias) {
        $tango = substr((string) $cuota['FECHA_VTO'], 0, 10);
        $cod = Planilla::codigo($cuota['COD_PROVEE'] ?? '');

        if (!isset($dias[$cod]) || strlen($tango) !== 10) {
            return $tango;
        }

        return substr($tango, 0, 8) . sprintf('%02d', $dias[$cod]);
    }

    /**
     * El vencimiento a considerar de una cuota. LA JERARQUIA: la fecha cargada
     * a mano en la grilla, y si no, la de Parametros (vtoDeParametro()). El de
     * Tango solo queda si la razon social no tiene dia ni hay general.
     *
     * @param array $cuota Fila de getPendientes()
     * @param array $vtos claveCuota => ['FECHA_VTO', ...]
     * @param array $dias COD_PROVEE => dia (diasDeVencimiento())
     * @return string 'Y-m-d'
     */
    public static function vtoAConsiderar($cuota, $vtos, $dias = []) {
        $c = self::claveCuota($cuota);

        return isset($vtos[$c]) ? $vtos[$c]['FECHA_VTO'] : self::vtoDeParametro($cuota, $dias);
    }

    /** De donde sale el vencimiento a considerar: EDITADO, PARAMETRO o TANGO */
    public static function origenVto($cuota, $vtos, $dias = []) {
        if (isset($vtos[self::claveCuota($cuota)])) {
            return self::VTO_EDITADO;
        }

        return isset($dias[Planilla::codigo($cuota['COD_PROVEE'] ?? '')]) ? self::VTO_PARAMETRO : self::VTO_TANGO;
    }

    /**
     * El dia en que se paga una cuota. Misma forma que AlquileresPago::diaPago().
     *
     * SI YA PASO -y no solo en el mes en curso: una factura vencida hace dos
     * meses se debe hoy-, el siguiente habil DESDE HOY. Nunca el primer dia del
     * eje con todo lo vencido apilado.
     *
     * @param string $vto 'Y-m-d'
     * @param array $habiles 'Y-m-d' => bool
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha', 'vencimiento', 'corrida', 'vencido', 'faltan_calendario']
     */
    public static function fechaDePago($vto, $habiles, $hoy) {
        $sig = DiasHabiles::siguiente($vto, $habiles);
        $fecha = $sig['fecha'];
        $faltan = $sig['faltan'];
        $vencido = false;

        if ($fecha < $hoy) {
            $d = DiasHabiles::siguiente($hoy, $habiles);
            $fecha = $d['fecha'];
            $faltan = array_values(array_unique(array_merge($faltan, $d['faltan'])));
            $vencido = true;
        }

        return ['fecha' => $fecha, 'vencimiento' => $vto, 'corrida' => $sig['corrida'],
                'vencido' => $vencido, 'faltan_calendario' => $faltan];
    }

    /** La nota de una factura que se movio de dia, para 'detalle'. '' si no se movio. */
    public static function notaFecha($dia) {
        if ($dia['vencido']) {
            return 'La factura vencía el ' . self::corta($dia['vencimiento']) . ': se ubica el siguiente día '
                . 'hábil desde hoy (' . self::corta($dia['fecha']) . ').';
        }

        if ($dia['corrida']) {
            return 'La factura vence el ' . self::corta($dia['vencimiento']) . ', que no es hábil: pasa al '
                . self::corta($dia['fecha']) . '.';
        }

        return '';
    }

    /* ====================================================================
       QUE CUBRE
       ==================================================================== */

    /**
     * Si un pago de la proyeccion lo pisa una imputacion: de SU razon social,
     * en uno de SUS locales, en SU mes de pago. Las facturas imputadas (nro
     * null) no se pisan entre si.
     *
     * @param array $pago De AlquileresPago::pagosLocal(), con 'nro' y 'cod_provee'
     * @param array $imp ['cod_provee', 'mes', 'locales' => [nro]]
     * @return bool
     */
    public static function cubre($pago, $imp) {
        if (($pago['nro'] ?? null) === null || ($pago['origen'] ?? null) === AlquileresCalculo::FACTURA) {
            return false;
        }

        return $pago['mes_pago'] === $imp['mes']
            && Planilla::codigo($pago['cod_provee'] ?? '') === Planilla::codigo($imp['cod_provee'])
            && in_array((int) $pago['nro'], array_map('intval', $imp['locales']), true);
    }

    /**
     * Los locales de una razon social: donde es principal o adicional. Es la
     * lista de "todos los locales de X" y contra la que se valida un local.
     *
     * @param string $cod
     * @param array $locales nro => ['razones' => [cod]] (AlquileresPago::razonesDelLocal())
     * @return int[]
     */
    public static function localesDeRazon($cod, $locales) {
        $c = Planilla::codigo($cod);
        $out = [];

        foreach ($locales as $nro => $l) {
            if (in_array($c, array_map(['Planilla', 'codigo'], $l['razones'] ?? []), true)) {
                $out[] = (int) $nro;
            }
        }

        sort($out);

        return $out;
    }

    /* ====================================================================
       EL REPARTO
       ==================================================================== */

    /**
     * Reparte un total en partes proporcionales a unos pesos, a centavos, por
     * resto mayor: las partes suman EXACTO el total redondeado. Con pesos que
     * suman 0, en partes iguales.
     *
     * @param float $total
     * @param array $pesos clave => float >= 0
     * @return array clave => float
     */
    public static function repartirCentavos($total, $pesos) {
        if (empty($pesos)) {
            return [];
        }

        $cent = (int) round($total * 100);
        $suma = array_sum($pesos);

        if ($suma <= 0) {
            $pesos = array_fill_keys(array_keys($pesos), 1.0);
            $suma = count($pesos);
        }

        $partes = [];
        $restos = [];
        $asignado = 0;

        foreach ($pesos as $k => $p) {
            $exacto = $cent * $p / $suma;
            $partes[$k] = (int) floor($exacto);
            $restos[$k] = $exacto - $partes[$k];
            $asignado += $partes[$k];
        }

        arsort($restos);

        foreach (array_keys($restos) as $k) {
            if ($asignado >= $cent) {
                break;
            }

            $partes[$k]++;
            $asignado++;
        }

        return array_map(function ($c) { return round($c / 100, 2) + 0.0; }, $partes);
    }

    /* ====================================================================
       LA VALIDACION DE UNA IMPUTACION
       ==================================================================== */

    /**
     * Valida un borrador de imputacion contra lo que hay AHORA, y dice que va a
     * pasar. Es la previsualizacion y es tambien el chequeo de guardar: el
     * servidor la vuelve a correr con los pendientes recien leidos, y si una
     * factura cambio de importe o ya no esta pendiente entre que se armo y se
     * guardo, frena.
     *
     * @param array $b [
     *     'facturas' => [['cod_provee', 't_comp', 'n_comp', 'vto_tango', 'pendiente' (el visto)]],
     *     'todos' => bool  todos los locales de la razon social,
     *     'local' => nro   si no es todos,
     *     'mes' => 'Y-m'   el mes de pago que reemplaza,
     *     'retenciones' => [['tipo' => ID, 'importe' => float]]
     * ]
     * @param array $e [
     *     'pendientes' => claveCuota => fila de getPendientes() (de destino A_ALQUILERES, y
     *                     las pagadas del mes vistas con comoPendiente()),
     *     'imputaciones' => las vigentes (AlquileresImputaciones::vigentes()),
     *     'locales' => nro => ['nombre', 'razones' (AlquileresPago::razonesDelLocal())],
     *     'tipos' => ID => nombre, los VIGENTES: los que se pueden elegir,
     *     'meses' => ['Y-m'] los que se pueden imputar (el anterior y los del horizonte),
     *     'excluidas' => claveCuota => fila de la exclusion (opcional),
     *     'pagos' => la proyeccion ORIGINAL (opcional: para decir cuanto pisa)
     * ]
     * @return array ['ok', 'errores', 'cod_provee', 'razon', 'todos', 'locales', 'mes', 'facturas',
     *                'retenciones', 'total_facturas', 'total_retenciones', 'total_a_pagar',
     *                'proyectado', 'pisa_n', 'avisos']
     */
    public static function validar($b, $e) {
        $errores = [];
        $avisos = [];
        $facturas = [];
        $razones = [];
        $yaImputadas = [];

        foreach ($e['imputaciones'] ?? [] as $imp) {
            foreach ($imp['facturas'] as $fi) {
                $yaImputadas[self::claveCuota($fi)] = $imp['id'];
            }
        }

        foreach (is_array($b['facturas'] ?? null) ? $b['facturas'] : [] as $f) {
            $cuota = ['COD_PROVEE' => $f['cod_provee'] ?? '', 'T_COMP' => $f['t_comp'] ?? '',
                      'N_COMP' => $f['n_comp'] ?? '', 'FECHA_VTO' => $f['vto_tango'] ?? ''];
            $clave = self::claveCuota($cuota);
            $nombre = $cuota['T_COMP'] . ' ' . $cuota['N_COMP'] . ' (vence ' . self::corta($cuota['FECHA_VTO']) . ')';

            if (isset($facturas[$clave])) {
                $errores[] = 'La factura ' . $nombre . ' está dos veces.';
                continue;
            }

            if (isset($yaImputadas[$clave])) {
                $errores[] = 'La factura ' . $nombre . ' ya está imputada en la #' . $yaImputadas[$clave]
                    . ': deshacé primero la #' . $yaImputadas[$clave] . '.';
                continue;
            }

            if (isset($e['excluidas'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' está excluida (no se paga por acá): volvé a incluirla '
                    . 'para imputarla.';
                continue;
            }

            if (!isset($e['pendientes'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' ya no está pendiente en Tango, o no es de alquiler: '
                    . 'volvé a cargar la pestaña.';
                continue;
            }

            $p = $e['pendientes'][$clave];
            $pend = round((float) $p['IMPORTE_PENDIENTE'], 2);

            if (abs($pend - round((float) ($f['pendiente'] ?? 0), 2)) > self::CENTAVO) {
                $errores[] = 'La factura ' . $nombre . ' cambió de pendiente en Tango mientras armabas la '
                    . 'imputación (' . self::plata((float) ($f['pendiente'] ?? 0)) . ' → ' . self::plata($pend)
                    . '): volvé a cargar la pestaña y armala de nuevo.';
            }

            $facturas[$clave] = ['clave' => $clave, 'fila' => $p, 'pendiente' => $pend,
                                 'pagada' => !empty($p['PAGADA_TANGO']), 'nombre' => $nombre];
            $razones[Planilla::codigo($p['COD_PROVEE'])] = $p['RAZON_SOC'] ?? '';
        }

        if (empty($facturas) && empty($errores)) {
            $errores[] = 'Tildá al menos una factura.';
        }

        /* UNA SOLA RAZON SOCIAL: lo que se pisa es lo que ELLA factura. */
        if (count($razones) > 1) {
            $errores[] = 'Las facturas tienen que ser de una sola razón social, y hay de '
                . implode(', ', array_keys($razones)) . ': imputá cada razón social por separado.';
        }

        $cod = (count($razones) === 1) ? key($razones) : null;
        $locales = [];
        $todos = !empty($b['todos']);

        /* LOS LOCALES: todos los de la razon social, o uno de ellos. */
        if ($cod !== null) {
            $deLaRazon = self::localesDeRazon($cod, $e['locales'] ?? []);

            if ($todos) {
                $locales = $deLaRazon;

                if (empty($locales)) {
                    $errores[] = $cod . ' no es razón social de ningún local (ni principal ni adicional): asignásela '
                        . 'en Parámetros › Alquileres › Locales.';
                }
            } else {
                $nro = (int) ($b['local'] ?? 0);

                if (!isset($e['locales'][$nro])) {
                    $errores[] = 'Elegí «Todos los locales» o un local.';
                } elseif (!in_array($nro, $deLaRazon, true)) {
                    $errores[] = $e['locales'][$nro]['nombre'] . ' no es un local de ' . $cod . '.';
                } else {
                    $locales = [$nro];
                }
            }
        }

        /* EL MES: el anterior o uno del horizonte. */
        $mes = substr(trim((string) ($b['mes'] ?? '')), 0, 7);

        if (!in_array($mes, $e['meses'] ?? [], true)) {
            $errores[] = 'Elegí el mes que cubre: el mes anterior o uno del horizonte.';
        }

        /* LAS RETENCIONES: por tipo, sin repetir, cada una mayor que 0. */
        $retenciones = [];

        foreach (is_array($b['retenciones'] ?? null) ? $b['retenciones'] : [] as $i => $r) {
            $tipo = (int) ($r['tipo'] ?? 0);
            $imp = round((float) str_replace(',', '.', (string) ($r['importe'] ?? 0)), 2);

            if (!isset($e['tipos'][$tipo])) {
                $errores[] = 'Retención ' . ($i + 1) . ': elegí un tipo de retención vigente.';
                continue;
            }

            if (isset($retenciones[$tipo])) {
                $errores[] = 'La retención «' . $e['tipos'][$tipo] . '» está dos veces: un tipo va una sola vez.';
                continue;
            }

            if ($imp <= 0) {
                $errores[] = 'La retención «' . $e['tipos'][$tipo] . '» tiene que ser mayor que cero (si no lleva, quitala).';
            }

            $retenciones[$tipo] = ['tipo' => $tipo, 'nombre' => $e['tipos'][$tipo], 'importe' => $imp];
        }

        $totalF = round(array_sum(array_column($facturas, 'pendiente')), 2);
        $totalR = round(array_sum(array_column($retenciones, 'importe')), 2);

        if ($totalR > $totalF + self::CENTAVO && !empty($facturas)) {
            $errores[] = 'Las retenciones (' . self::plata($totalR) . ') no pueden superar el total de las facturas ('
                . self::plata($totalF) . ').';
        }

        /* DOS IMPUTACIONES NO PISAN LO MISMO: misma razon, mismo mes y algun
           local en comun. Con locales distintos, si. */
        foreach ($e['imputaciones'] ?? [] as $imp) {
            if ($cod === null || Planilla::codigo($imp['cod_provee']) !== $cod || $imp['mes'] !== $mes) {
                continue;
            }

            $comunes = array_values(array_intersect(array_map('intval', $imp['locales']), $locales));

            if (!empty($comunes)) {
                $errores[] = 'Se superpone con la imputación #' . $imp['id'] . ' (' . $cod . ', ' . self::mesLargo($mes)
                    . ', la cargó ' . ($imp['usuario'] ?: '—') . ' el ' . $imp['fecha'] . '): tienen en común '
                    . implode(', ', array_map(function ($n) use ($e) { return $e['locales'][$n]['nombre'] ?? $n; }, $comunes))
                    . '. Deshacela primero, o elegí un local que no cubra.';
            }
        }

        /* LAS RETENCIONES SE REPARTEN ENTRE LAS CUOTAS por su pendiente, al
           centavo: cada una paga su pendiente menos su parte. */
        $partes = self::repartirCentavos($totalR, array_map(function ($f) { return $f['pendiente']; }, $facturas));

        foreach ($facturas as $c => $f) {
            $facturas[$c]['retencion'] = $partes[$c] ?? 0.0;
            $facturas[$c]['a_pagar'] = round($f['pendiente'] - ($partes[$c] ?? 0.0), 2);
        }

        /* CUANTO PISA, para que quien imputa vea contra que se compara. */
        $proyectado = 0.0;
        $pisa = 0;
        $imp = ['cod_provee' => $cod ?? '', 'mes' => $mes, 'locales' => $locales];

        foreach ($e['pagos'] ?? [] as $p) {
            if ($cod !== null && self::cubre($p, $imp)) {
                $proyectado += $p['a_pagar'];
                $pisa++;
            }
        }

        if ($cod !== null && empty($errores) && $pisa === 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'No hay nada proyectado de ' . $cod . ' en esos locales en '
                . self::mesLargo($mes) . ' (o es un mes fuera del horizonte): la factura se paga en su fecha y no '
                . 'pisa nada.');
        }

        return [
            'ok' => empty($errores),
            'errores' => array_values(array_unique($errores)),
            'cod_provee' => $cod,
            'razon' => ($cod === null) ? null : $razones[$cod],
            'todos' => $todos,
            'locales' => $locales,
            'mes' => $mes,
            'facturas' => array_values($facturas),
            'retenciones' => array_values($retenciones),
            'total_facturas' => $totalF,
            'total_retenciones' => $totalR,
            'total_a_pagar' => round($totalF - $totalR, 2),
            'proyectado' => round($proyectado, 2),
            'pisa_n' => $pisa,
            'avisos' => $avisos
        ];
    }

    /* ====================================================================
       APLICAR: LA LISTA DE PAGOS CON LAS FACTURAS
       ==================================================================== */

    /**
     * La proyeccion con las facturas imputadas: lo que la pestaña agrupa por mes
     * y el tablero acumula por dia. Y la grilla de Facturas.
     *
     * @param array $e [
     *     'pagos' => la proyeccion (Alquileres::calcular()), con 'nro' y 'cod_provee',
     *     'meses_pago' => los meses del horizonte,
     *     'pendientes' => [filas de getPendientes() con destino A_ALQUILERES],
     *     'excluidas' => [las de destino A_EXCLUIDA: proveedores excluidos de Alquileres],
     *     'vtos' => claveCuota => ['FECHA_VTO', 'MOTIVO', 'USUARIO_ALTA', 'FECHA_ALTA'],
     *     'dias' => COD_PROVEE => dia de vencimiento de Parametros (diasDeVencimiento()),
     *               opcional: sin el, el de Tango,
     *     'imputaciones' => las vigentes (ver AlquileresImputaciones::vigentes()),
     *     'en_tango' => Proveedores::estadoComprobantesEnTango() de las imputadas que
     *                   ya no estan pendientes; null si no se pudo leer,
     *     'ctx' => AlquileresFacturas::contexto(),
     *     'habiles' => 'Y-m-d' => bool, 'hoy' => 'Y-m-d',
     *     'pagadas_mes' => las cuotas del mes ya canceladas en Tango (opcional),
     *     'tipos' => ID => nombre de TODOS los tipos de retencion (opcional),
     *     'nombres_locales' => nro => nombre (opcional)
     * ]
     * @return array ['pagos', 'reemplazados', 'facturas', 'imputaciones', 'pagadas_nuevas',
     *                'fuera_horizonte', 'kpis', 'avisos']
     */
    public static function aplicar($e) {
        $hoy = $e['hoy'];
        $dias = $e['dias'] ?? [];
        $tipos = $e['tipos'] ?? [];
        $nombresLoc = $e['nombres_locales'] ?? [];
        $avisos = [];
        $pend = [];

        foreach ($e['pendientes'] as $p) {
            $pend[self::claveCuota($p)] = $p;
        }

        $enHorizonte = array_flip($e['meses_pago']);
        $ctx = $e['ctx'];

        /* 1. EL ESTADO DE CADA CUOTA IMPUTADA, y que imputaciones se leen. */
        $grupos = [];
        $cuotaImp = [];
        $pagadasNuevas = [];

        foreach ($e['imputaciones'] as $imp) {
            $g = $imp + ['estados' => [], 'suspendida' => false, 'reemplaza' => false];

            foreach ($imp['facturas'] as $k => $f) {
                $c = self::claveCuota($f);
                $cod = Planilla::codigo($f['COD_PROVEE']);
                $estado = self::estadoImputada($f, $pend, $e['en_tango']);

                if ($estado['estado'] === self::PAGADA && $f['ESTADO'] !== self::PAGADA) {
                    $pagadasNuevas[] = ['id' => $f['ID'], 'clave' => $c, 'fecha' => $estado['fecha']];
                }

                if ($f['ESTADO'] !== self::PAGADA
                    && (!isset($ctx['razones'][$cod]) || isset($ctx['excluidos'][$cod]))) {
                    $g['suspendida'] = true;
                }

                $g['estados'][$k] = $estado;
                $cuotaImp[$c] = $imp['id'];
            }

            $vivas = array_filter($g['estados'], function ($s) {
                return in_array($s['estado'], [self::IMPUTADA, self::PAGADA], true);
            });
            $g['reemplaza'] = !$g['suspendida'] && !empty($vivas);
            $grupos[$imp['id']] = $g;
        }

        /* 2. EL REEMPLAZO: fuera de la proyeccion lo que cubre una imputacion
              que reemplaza. */
        $proyeccion = [];
        $reemplazados = [];

        foreach ($e['pagos'] as $p) {
            $por = null;

            foreach ($grupos as $id => $g) {
                if ($g['reemplaza'] && self::cubre($p, $g)) {
                    $por = $id;
                    break;
                }
            }

            if ($por === null) {
                $proyeccion[] = $p;
            } else {
                $reemplazados[] = $p + ['reemplazado_por' => $por];
            }
        }

        /* 3. LAS PIEZAS: una por cuota pendiente de una imputacion que se lee,
              en la fecha de la cuota, por su pendiente menos su retencion. */
        $piezas = [];
        $fuera = 0.0;
        $resumen = [];

        foreach ($grupos as $id => $g) {
            $resumen[$id] = self::resumen($g, $tipos, $nombresLoc, $enHorizonte);

            if ($g['suspendida']) {
                continue;
            }

            foreach ($g['facturas'] as $k => $f) {
                $fila = $pend[self::claveCuota($f)] ?? null;
                $dia = ($fila === null) ? null
                    : self::fechaDePago(self::vtoAConsiderar($fila, $e['vtos'], $dias), $e['habiles'], $hoy);
                $resumen[$id]['facturas'][$k]['fecha_pago'] = ($dia === null) ? null : $dia['fecha'];
                $resumen[$id]['facturas'][$k]['vto_considerar'] = ($fila === null) ? null
                    : self::vtoAConsiderar($fila, $e['vtos'], $dias);

                if ($g['estados'][$k]['estado'] !== self::IMPUTADA) {
                    continue;
                }

                $total = round((float) $fila['IMPORTE_PENDIENTE'], 2);
                $ret = min(round((float) $f['RETENCION'], 2), $total);
                $mesPago = substr($dia['fecha'], 0, 7);
                $pago = [
                    'nro' => null,
                    'cod_provee' => Planilla::codigo($g['cod_provee']),
                    'mes_pago' => $mesPago,
                    'fecha' => $dia['fecha'],
                    'concepto' => 'FACTURA:' . $id . ':' . self::claveCuota($f),
                    'grupo' => self::GRUPO_FACTURAS,
                    'mes_concepto' => $g['mes'],
                    'origen' => AlquileresCalculo::FACTURA,
                    'vencimiento' => $dia['vencimiento'],
                    'corrida' => $dia['corrida'],
                    'vencido' => $dia['vencido'],
                    'faltan_calendario' => $dia['faltan_calendario'],
                    'nota_dia' => self::notaFecha($dia),
                    /* EL NETO DE UNA FACTURA ES SU IMPORTE, con IVA y
                       percepciones: no se abre en impuestos. 'con_iva' se lo
                       dice al tooltip. */
                    'neto' => $total,
                    'iva' => 0.0,
                    'percepcion' => 0.0,
                    'retencion' => $ret,
                    'a_pagar' => round($total - $ret, 2),
                    'detalle' => [
                        'imputacion' => $id,
                        'factura' => $f['T_COMP'] . ' ' . $f['N_COMP'],
                        'cod_provee' => Planilla::codigo($g['cod_provee']),
                        'mes_cubre' => $g['mes'],
                        'con_iva' => true
                    ]
                ];

                if (!isset($enHorizonte[$mesPago])) {
                    $fuera += $pago['a_pagar'];
                    continue;
                }

                $piezas[] = $pago;
            }
        }

        /* 4. LA GRILLA DE FACTURAS. */
        $filas = [];
        $sinImputar = ['n' => 0, 'importe' => 0.0];
        $porComp = [];

        foreach ($pend as $c => $p) {
            $porComp[Planilla::codigo($p['COD_PROVEE']) . '|' . $p['T_COMP'] . '|' . $p['N_COMP']][] = $c;
        }

        foreach ($pend as $c => $p) {
            $id = $cuotaImp[$c] ?? null;
            $g = ($id === null) ? null : $grupos[$id];
            $f = null;

            if ($g !== null) {
                foreach ($g['facturas'] as $fi) {
                    if (self::claveCuota($fi) === $c) {
                        $f = $fi;
                    }
                }
            }

            if ($g === null) {
                $estado = self::SIN_IMPUTAR;
            } elseif ($g['suspendida']) {
                $estado = self::SUSPENDIDA;
            } else {
                $estado = self::IMPUTADA;
                $comp = $porComp[Planilla::codigo($p['COD_PROVEE']) . '|' . $p['T_COMP'] . '|' . $p['N_COMP']];

                foreach ($comp as $otra) {
                    if (!isset($cuotaImp[$otra])) {
                        $estado = self::PARCIAL;
                    }
                }
            }

            if ($estado === self::SIN_IMPUTAR || $estado === self::SUSPENDIDA) {
                $sinImputar['n']++;
                $sinImputar['importe'] += round((float) $p['IMPORTE_PENDIENTE'], 2);
            }

            $filas[] = self::filaFactura($p, $e['vtos'], $dias, $e['habiles'], $hoy, $estado, $g, $f,
                ($id === null) ? null : $resumen[$id]);
        }

        /* Las ya pagadas del mes, por cuota: con ellas se completan las filas de
           las imputadas pagadas (emision, importe, leyenda). */
        $pagadasMes = [];

        foreach ($e['pagadas_mes'] ?? [] as $p) {
            $pagadasMes[self::claveCuota($p)] = $p;
        }

        /* Las imputadas que ya no estan pendientes: pagadas (las del mes se ven
           siempre; las anteriores, con "Ver pagadas"), anuladas o sin dato de
           Tango. */
        foreach ($grupos as $id => $g) {
            foreach ($g['facturas'] as $k => $f) {
                $s = $g['estados'][$k];

                if ($s['estado'] === self::IMPUTADA) {
                    continue;
                }

                $c = self::claveCuota($f);
                $fila = isset($pagadasMes[$c]) ? ['IMPORTE_PENDIENTE' => 0.0] + $pagadasMes[$c]
                    : ['COD_PROVEE' => $f['COD_PROVEE'], 'RAZON_SOC' => $resumen[$id]['razon'] ?? '',
                       'T_COMP' => $f['T_COMP'], 'N_COMP' => $f['N_COMP'], 'FECHA_VTO' => $f['FECHA_VTO_TANGO'],
                       'FECHA_EMIS' => null, 'IMPORTE_VTO' => null, 'IMPORTE_PENDIENTE' => 0.0, 'LEYENDA' => ''];

                $filas[] = array_merge(self::filaFactura($fila, $e['vtos'], $dias, $e['habiles'], $hoy, $s['estado'],
                    $g, $f, $resumen[$id]), ['fecha_pagada' => $s['fecha']]);
            }
        }

        /* LAS PAGADAS DEL MES QUE NADIE IMPUTO: no se pagan (la plata ya salio),
           pero la proyeccion de su mes sigue en el flujo. Se imputan por el
           importe de la cuota; imputadas y pagadas, la reemplazan. */
        $pagadasSin = ['n' => 0, 'importe' => 0.0];

        foreach ($pagadasMes as $c => $p) {
            if (isset($cuotaImp[$c]) || isset($pend[$c])) {
                continue;
            }

            $pagadasSin['n']++;
            $pagadasSin['importe'] += (float) $p['IMPORTE_VTO'];
            $filas[] = array_merge(self::filaFactura($p, $e['vtos'], $dias, $e['habiles'], $hoy,
                self::PAGADA_SIN_IMPUTAR, null, null, null), ['fecha_pagada' => $p['FECHA_CANCELADO'] ?? null]);
        }

        /* 5. LOS AVISOS. */
        if ($sinImputar['n'] > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $sinImputar['n'] . ' factura(s) de alquiler sin imputar por '
                . self::plata($sinImputar['importe']) . ': no suman hasta que se imputen.', 'Facturas');
        }

        if ($pagadasSin['n'] > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $pagadasSin['n'] . ' factura(s) de alquiler de este mes ya '
                . 'pagadas en Tango y sin imputar, por ' . self::plata($pagadasSin['importe']) . ': la proyección '
                . 'de lo que cubren sigue en el flujo hasta que se imputen (Alquileres › Facturas, estado «Pagada, '
                . 'sin imputar»).', 'Facturas');
        }

        $cambios = [];
        $anuladas = [];
        $sinDato = [];
        $suspendidas = [];

        foreach ($grupos as $id => $g) {
            if ($g['suspendida']) {
                $suspendidas[] = '#' . $id;
            }

            foreach ($g['facturas'] as $k => $f) {
                $s = $g['estados'][$k];
                $nom = $f['T_COMP'] . ' ' . $f['N_COMP'];

                if ($s['estado'] === self::IMPUTADA && $s['cambio'] !== null) {
                    $cambios[] = $nom . ' (' . self::plata((float) $f['PENDIENTE']) . ' → ' . self::plata($s['cambio']) . ')';
                } elseif ($s['estado'] === self::ANULADA) {
                    $anuladas[] = $nom . ' (imputación #' . $id . ($g['reemplaza'] ? '' : ', ya no reemplaza') . ')';
                } elseif ($s['estado'] === self::SIN_DATO) {
                    $sinDato[] = $nom;
                }
            }
        }

        if (!empty($cambios)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Cambió en Tango el pendiente de ' . count($cambios)
                . ' factura(s) imputada(s) sin cancelarse: ' . implode('; ', $cambios) . '. Se proyecta el '
                . 'pendiente nuevo menos su retención; revisá la imputación.', 'Facturas');
        }

        if (!empty($anuladas)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, count($anuladas) . ' factura(s) imputada(s) ya no están '
                . 'pendientes y no figuran pagadas en Tango: ' . implode('; ', $anuladas) . '. Una imputación '
                . 'cuyas facturas desaparecieron todas deja de reemplazar y la proyección vuelve. Imputá la '
                . 'factura nueva.', 'Facturas');
        }

        if (!empty($sinDato)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer en Tango qué pasó con ' . count($sinDato)
                . ' factura(s) imputada(s) que ya no están pendientes (' . implode(', ', $sinDato) . '): '
                . 'mientras tanto se toman como no pagadas y, si no queda ninguna, la proyección vuelve.', 'Facturas');
        }

        if (!empty($suspendidas)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Las imputaciones ' . implode(', ', $suspendidas)
                . ' tienen facturas de un proveedor dado de baja o excluido de Alquileres: no se leen y sus '
                . 'facturas no suman. Deshacelas.', 'Facturas');
        }

        if (!empty($e['excluidas'])) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, count($e['excluidas']) . ' factura(s) por '
                . self::plata(array_sum(array_map(function ($p) { return (float) $p['IMPORTE_PENDIENTE']; },
                    $e['excluidas'])))
                . ' son de proveedores excluidos de Alquileres (' . implode(', ', array_unique(array_column(
                    $e['excluidas'], 'COD_PROVEE'))) . '): no entran ni suman, a propósito. Se revisan en '
                . 'Proveedores Locales › Maestro, columna Alquileres.', 'Facturas');
        }

        $imputadas = array_filter($filas, function ($f) {
            return in_array($f['estado'], [self::IMPUTADA, self::PARCIAL], true);
        });

        return [
            'pagos' => array_merge($proyeccion, $piezas),
            'reemplazados' => $reemplazados,
            'facturas' => $filas,
            'imputaciones' => array_values($resumen),
            'pagadas_nuevas' => $pagadasNuevas,
            'fuera_horizonte' => round($fuera, 2),
            'kpis' => [
                'sin_imputar_n' => $sinImputar['n'],
                'sin_imputar_importe' => round($sinImputar['importe'], 2),
                'imputadas_n' => count($imputadas),
                'imputadas_a_pagar' => round(array_sum(array_column($imputadas, 'a_pagar')), 2),
                'pagadas_sin_imputar_n' => $pagadasSin['n'],
                'pagadas_sin_imputar_importe' => round($pagadasSin['importe'], 2),
                'reemplazado_a_pagar' => round(array_sum(array_column($reemplazados, 'a_pagar')), 2)
            ],
            'avisos' => $avisos
        ];
    }

    /**
     * Lo que se muestra de una imputacion: en la lista de vigentes, en el
     * tooltip de "Facturas imputadas" y en la grilla de Facturas.
     */
    private static function resumen($g, $tipos, $nombresLoc, $enHorizonte) {
        $facturas = [];

        foreach ($g['facturas'] as $k => $f) {
            $facturas[$k] = ['comprobante' => $f['T_COMP'] . ' ' . $f['N_COMP'], 'pendiente' => (float) $f['PENDIENTE'],
                             'retencion' => (float) $f['RETENCION'], 'estado' => $g['estados'][$k]['estado'],
                             'fecha_pago' => null, 'vto_considerar' => null];
        }

        $totalF = round((float) ($g['total_facturas'] ?? array_sum(array_column($g['facturas'], 'PENDIENTE'))), 2);
        $totalR = round((float) ($g['total_retenciones'] ?? array_sum(array_column($g['facturas'], 'RETENCION'))), 2);

        return [
            'id' => $g['id'],
            'cod_provee' => Planilla::codigo($g['cod_provee']),
            'razon' => $g['razon'] ?? '',
            'mes' => $g['mes'],
            'fuera_horizonte' => !isset($enHorizonte[$g['mes']]),
            'todos' => !empty($g['todos']),
            'locales' => array_map(function ($n) use ($nombresLoc) {
                return ['nro' => (int) $n, 'nombre' => $nombresLoc[(int) $n] ?? (string) $n];
            }, $g['locales']),
            'retenciones' => array_map(function ($r) use ($tipos) {
                return ['tipo' => (int) $r['tipo'], 'nombre' => $tipos[(int) $r['tipo']] ?? ('tipo ' . $r['tipo']),
                        'importe' => (float) $r['importe']];
            }, $g['retenciones'] ?? []),
            'total_facturas' => $totalF,
            'total_retenciones' => $totalR,
            'total_a_pagar' => round($totalF - $totalR, 2),
            'usuario' => $g['usuario'] ?? null,
            'fecha' => $g['fecha'] ?? null,
            'observacion' => $g['observacion'] ?? null,
            'facturas' => $facturas,
            'suspendida' => $g['suspendida'],
            'reemplaza' => $g['reemplaza']
        ];
    }

    /**
     * El estado de una cuota imputada.
     *
     * @return array ['estado' => IMPUTADA|PAGADA|ANULADA|SIN_DATO, 'fecha' => 'Y-m-d'|null,
     *                'cambio' => float|null (el pendiente nuevo, si cambio)]
     */
    public static function estadoImputada($f, $pend, $enTango) {
        $c = self::claveCuota($f);

        if (isset($pend[$c])) {
            $ahora = round((float) $pend[$c]['IMPORTE_PENDIENTE'], 2);

            return ['estado' => self::IMPUTADA, 'fecha' => null,
                    'cambio' => (abs($ahora - round((float) $f['PENDIENTE'], 2)) > self::CENTAVO) ? $ahora : null];
        }

        if (($f['ESTADO'] ?? null) === self::PAGADA) {
            return ['estado' => self::PAGADA, 'fecha' => $f['FECHA_PAGADA'] ?? null, 'cambio' => null];
        }

        if ($enTango === null) {
            return ['estado' => self::SIN_DATO, 'fecha' => null, 'cambio' => null];
        }

        $clave = Planilla::codigo($f['COD_PROVEE']) . '|' . Planilla::codigo($f['N_COMP']);

        foreach ($enTango[$clave] ?? [] as $t) {
            if ($t['T_COMP'] === Planilla::codigo($f['T_COMP']) && !empty($t['FECHA_CANCELADO'])) {
                return ['estado' => self::PAGADA, 'fecha' => $t['FECHA_CANCELADO'], 'cambio' => null];
            }
        }

        return ['estado' => self::ANULADA, 'fecha' => null, 'cambio' => null];
    }

    /**
     * Las imputaciones que impiden dar de baja una razon social en Parametros
     * -> Alquileres: las vigentes con alguna cuota de ese proveedor que SIGUE
     * PENDIENTE en Tango. Dar de baja devuelve sus facturas a Proveedores
     * Locales, y una imputacion de una factura que alli se va a pagar por otro
     * lado dejaria la plata contada en los dos. Primero se deshacen.
     *
     * LAS DE FACTURAS YA PAGADAS NO BLOQUEAN: quedan con su historial.
     *
     * @param string $codProvee
     * @param array $imputaciones Las vigentes
     * @param array $pendientes Claves de cuota pendientes en Tango (claveCuota => true),
     *        de TODO el universo, no solo las de alquiler
     * @return array Lista de ['imputacion', 'factura', 'meses', 'usuario', 'fecha']
     */
    public static function bloqueanBaja($codProvee, $imputaciones, $pendientes) {
        $cod = Planilla::codigo($codProvee);
        $out = [];

        foreach ($imputaciones as $imp) {
            foreach ($imp['facturas'] as $f) {
                if (Planilla::codigo($f['COD_PROVEE']) === $cod && isset($pendientes[self::claveCuota($f)])) {
                    $out[] = ['imputacion' => $imp['id'], 'factura' => $f['T_COMP'] . ' ' . $f['N_COMP'],
                              'meses' => [$imp['mes']], 'usuario' => $imp['usuario'], 'fecha' => $imp['fecha']];
                }
            }
        }

        return $out;
    }

    /** El mensaje de una baja bloqueada: que imputaciones hay que deshacer */
    public static function mensajeBajaBloqueada($cod, $bloquean) {
        return 'No se puede dar de baja ' . $cod . ': tiene imputaciones de facturas todavía pendientes, que '
            . 'volverían a Proveedores Locales. Deshacelas primero en Alquileres › Facturas: '
            . implode('; ', array_map(function ($b) {
                return 'imputación #' . $b['imputacion'] . ' — ' . $b['factura'] . ', cubre '
                    . implode(', ', $b['meses']) . ', la cargó ' . ($b['usuario'] ?: '—') . ' el ' . $b['fecha'];
            }, $bloquean)) . '.';
    }

    /** Las cuotas imputadas que ya no estan pendientes ni marcadas pagadas: las que hay que mirar en Tango */
    public static function aConsultarEnTango($imputaciones, $pendientes) {
        $pend = [];

        foreach ($pendientes as $p) {
            $pend[self::claveCuota($p)] = true;
        }

        $pares = [];

        foreach ($imputaciones as $imp) {
            foreach ($imp['facturas'] as $f) {
                if (!isset($pend[self::claveCuota($f)]) && ($f['ESTADO'] ?? null) !== self::PAGADA) {
                    $pares[Planilla::codigo($f['COD_PROVEE']) . '|' . Planilla::codigo($f['N_COMP'])] =
                        ['cod' => Planilla::codigo($f['COD_PROVEE']), 'n' => Planilla::codigo($f['N_COMP'])];
                }
            }
        }

        return array_values($pares);
    }

    /**
     * Una fila de la grilla de Facturas. La retencion es LA PARTE DE ESTA
     * CUOTA del total de retenciones de su imputacion; el tooltip abre los
     * tipos con lo de 'imputacion_resumen'.
     */
    private static function filaFactura($p, $vtos, $dias, $habiles, $hoy, $estado, $g, $f, $resumen) {
        $c = self::claveCuota($p);
        $vto = self::vtoAConsiderar($p, $vtos, $dias);
        $pendiente = round((float) $p['IMPORTE_PENDIENTE'], 2);
        $ret = ($f === null) ? null : round((float) $f['RETENCION'], 2);
        $dia = in_array($estado, [self::SIN_IMPUTAR, self::IMPUTADA, self::PARCIAL, self::SUSPENDIDA], true)
            ? self::fechaDePago($vto, $habiles, $hoy) : null;

        return [
            'clave' => $c,
            'cod_provee' => Planilla::codigo($p['COD_PROVEE']),
            'razon' => $p['RAZON_SOC'] ?? '',
            't_comp' => $p['T_COMP'],
            'n_comp' => $p['N_COMP'],
            'leyenda' => $p['LEYENDA'] ?? '',
            'emision' => $p['FECHA_EMIS'] ?? null,
            'vto_tango' => substr((string) $p['FECHA_VTO'], 0, 10),
            'vto_considerar' => $vto,
            'vto_editado' => isset($vtos[$c]) ? $vtos[$c] : null,
            'vto_parametro' => self::vtoDeParametro($p, $dias),
            'vto_origen' => self::origenVto($p, $vtos, $dias),
            'fecha_pago' => ($dia === null) ? null : $dia['fecha'],
            'nota_fecha' => ($dia === null) ? '' : self::notaFecha($dia),
            'importe' => isset($p['IMPORTE_VTO']) ? $p['IMPORTE_VTO'] : null,
            'pendiente' => $pendiente,
            /* Lo que se imputa: el pendiente, o el importe de una cuota ya pagada. */
            'a_imputar' => ($estado === self::PAGADA_SIN_IMPUTAR) ? round((float) $p['IMPORTE_VTO'], 2) : $pendiente,
            /* Emitida en el mes en curso: las pagadas del mes se ven sin "Ver pagadas". */
            'del_mes' => substr((string) ($p['FECHA_EMIS'] ?? ''), 0, 7) === substr($hoy, 0, 7),
            'retencion' => $ret,
            'a_pagar' => round($pendiente - min($ret ?? 0.0, $pendiente), 2),
            'estado' => $estado,
            'imputacion' => ($g === null) ? null : $g['id'],
            'imputacion_usuario' => ($g === null) ? null : ($g['usuario'] ?? null),
            'imputacion_fecha' => ($g === null) ? null : ($g['fecha'] ?? null),
            'imputacion_resumen' => ($resumen === null) ? null : array_diff_key($resumen, ['facturas' => true]),
            'pendiente_al_imputar' => ($f === null) ? null : round((float) $f['PENDIENTE'], 2),
            'fecha_pagada' => null
        ];
    }

    private static function plata($n) {
        return '$ ' . number_format((float) $n, 2, ',', '.');
    }

    private static function corta($fecha) {
        $f = substr((string) $fecha, 0, 10);

        return (strlen($f) === 10) ? substr($f, 8, 2) . '/' . substr($f, 5, 2) . '/' . substr($f, 0, 4) : $f;
    }

    /** '2026-11' -> 'noviembre de 2026' */
    public static function mesLargo($mes) {
        $nombres = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre',
                    'octubre', 'noviembre', 'diciembre'];
        $n = (int) substr((string) $mes, 5, 2);

        return ($n >= 1 && $n <= 12) ? $nombres[$n - 1] . ' de ' . substr((string) $mes, 0, 4) : (string) $mes;
    }
}
