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
 * como parametros. Quien lee es AlquileresImputaciones; quien arma, Alquileres.
 *
 * QUE ES UNA IMPUTACION
 * ---------------------
 * UN GRUPO de cuotas pendientes -de una o varias razones sociales- repartido en
 * RENGLONES. Cada renglon tiene conceptos, locales, un MES QUE CUBRE (el mes de
 * pago cuya proyeccion reemplaza) y un importe con IVA y percepciones. La suma
 * de los renglones tiene que dar el pendiente de las cuotas (+-TOLERANCIA). La
 * retencion se carga por factura.
 *
 * QUE REEMPLAZA
 * -------------
 * Cada renglon saca de la proyeccion los pagos de SUS conceptos (o de los
 * extraordinarios, con EXTRA), en SUS locales, cuyo MES DE PAGO es el que
 * cubre. Va sobre la lista de pagos y no sobre el calculo del concepto
 * (AlquileresCalculo::conceptosDelMes()) porque reemplaza por mes de pago y con
 * el importe final: los variables de septiembre de IRSA se pagan en noviembre,
 * y una factura de variables con mes que cubre noviembre es la que los pisa.
 *
 *   - MANDA LO REAL: menor o mayor que lo proyectado, no queda saldo.
 *   - OTROS (no proyectados) suma en real y no reemplaza nada.
 *   - LO QUE NO SE IMPUTO SIGUE PROYECTADO: una factura de los fijos de un local
 *     deja sus expensas y variables como estaban.
 *
 * CUANTO Y CUANDO
 * ---------------
 * Cada cuota pendiente se paga en su VENCIMIENTO A CONSIDERAR (el editado, o el
 * de Tango), corrido al siguiente habil, y si ya paso, el siguiente habil desde
 * hoy: nunca apilado en el primer dia del eje. Se paga por su a pagar, pendiente
 * menos retencion. La proyeccion reemplazada deja de pagarse en SU fecha: el
 * importe real va en la de la factura.
 *
 * Para la grilla por local, cada cuota se reparte en PIEZAS (renglon x local):
 *   - entre renglones, por su importe;
 *   - dentro de un renglon con varios locales, por lo que tenia proyectado cada
 *     local para esos conceptos en ese mes; si lo proyectado da 0, en partes
 *     iguales, con aviso;
 *   - la retencion de la cuota, con los mismos pesos.
 * Los centavos se reparten por resto mayor (repartirCentavos()): cada cuota
 * suma exacto su pendiente y su retencion.
 *
 * CUANDO TANGO LA CANCELA
 * -----------------------
 * La cuota sale de pendientes. Si Tango la tiene con fecha de cancelacion queda
 * PAGADA: deja de pagarse (la plata ya salio) y SIGUE REEMPLAZANDO, para que el
 * mes no vuelva a proyectarse. Si no aparece pagada (anulada) o Tango no se pudo
 * leer, deja de reemplazar y la proyeccion vuelve, con aviso: es el lado que
 * muestra la salida de caja, no el que la esconde. En un grupo de varias
 * facturas, el grupo deja de reemplazar solo si TODAS quedaron asi.
 *
 * Si el pendiente cambia sin cancelarse (nota de credito, pago parcial en
 * Tango), se paga el pendiente nuevo menos la retencion y se avisa.
 */
class AlquileresImputacion {

    const EXTRA = 'EXTRA';
    const OTROS = 'OTROS';

    /**
     * Los conceptos que puede tener un renglon. LA LISTA DEL CHECK de
     * RO_T_CASHFLOW_ALQ_IMP_RENGLON_CONCEPTO Y ESTA CAMBIAN JUNTAS.
     */
    const CONCEPTOS = ['1', '2', '3', '6', '7', '8', '9', '11', '14', '16', '17', self::EXTRA, self::OTROS];

    /** Cuanto puede diferir la suma de los renglones del pendiente de las facturas */
    const TOLERANCIA = 1.0;

    /** Los grupos de la grilla de Proyeccion para las piezas que no caen en uno solo */
    const GRUPO_VARIOS = 'FACTURA_VARIOS';
    const GRUPO_OTROS = 'OTROS';

    /** El estado de una cuota en la sub-pestaña Facturas */
    const SIN_IMPUTAR = 'SIN_IMPUTAR';
    const IMPUTADA = 'IMPUTADA';
    const PARCIAL = 'PARCIAL';
    const PAGADA = 'PAGADA';
    const ANULADA = 'ANULADA';
    const SIN_DATO = 'SIN_DATO';
    const SUSPENDIDA = 'SUSPENDIDA';

    const NOMBRE_ESTADO = [
        self::SIN_IMPUTAR => 'Sin imputar',
        self::IMPUTADA => 'Imputada',
        self::PARCIAL => 'Imputada parcialmente',
        self::PAGADA => 'Pagada',
        self::ANULADA => 'Ya no está en Tango',
        self::SIN_DATO => 'Sin dato de Tango',
        self::SUSPENDIDA => 'Imputación suspendida'
    ];

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
     * El vencimiento a considerar de una cuota: el editado, o el de Tango.
     *
     * @param array $cuota Fila de getPendientes()
     * @param array $vtos claveCuota => ['FECHA_VTO', ...]
     * @return string 'Y-m-d'
     */
    public static function vtoAConsiderar($cuota, $vtos) {
        $c = self::claveCuota($cuota);

        return isset($vtos[$c]) ? $vtos[$c]['FECHA_VTO'] : substr((string) $cuota['FECHA_VTO'], 0, 10);
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
       CONCEPTOS Y GRUPOS
       ==================================================================== */

    /**
     * Si un pago de la proyeccion es de alguno de estos conceptos.
     *
     * @param array $pago De AlquileresPago::pagosLocal()
     * @param string[] $conceptos
     * @return bool
     */
    public static function cubre($pago, $conceptos) {
        foreach ($conceptos as $c) {
            if ($c === self::OTROS) {
                continue;
            }

            if ($c === self::EXTRA ? $pago['grupo'] === AlquileresConceptos::EXTRA
                                   : (string) $pago['concepto'] === (string) $c) {
                return true;
            }
        }

        return false;
    }

    /**
     * El grupo de la grilla de Proyeccion donde va un renglon: el de sus
     * conceptos si son todos de uno, OTROS, o uno aparte si abarca varios.
     *
     * @param string[] $conceptos
     * @return string
     */
    public static function grupoDeRenglon($conceptos) {
        if (in_array(self::OTROS, $conceptos, true)) {
            return self::GRUPO_OTROS;
        }

        $grupos = [];

        foreach ($conceptos as $c) {
            $grupos[$c === self::EXTRA ? AlquileresConceptos::EXTRA : AlquileresConceptos::grupo($c)] = true;
        }

        return (count($grupos) === 1) ? key($grupos) : self::GRUPO_VARIOS;
    }

    /** Los atajos de la pantalla: un grupo entero de conceptos */
    public static function atajos() {
        return [
            AlquileresConceptos::FIJOS => array_map('strval', AlquileresConceptos::delGrupo(AlquileresConceptos::FIJOS)),
            AlquileresConceptos::FONDO => array_map('strval', AlquileresConceptos::delGrupo(AlquileresConceptos::FONDO)),
            AlquileresConceptos::EXPENSAS => array_map('strval', AlquileresConceptos::delGrupo(AlquileresConceptos::EXPENSAS)),
            AlquileresConceptos::VARIABLES => array_map('strval', AlquileresConceptos::delGrupo(AlquileresConceptos::VARIABLES))
        ];
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

    /**
     * Cuanto le toca a cada local de un renglon: lo que tenia proyectado para
     * esos conceptos en el mes que cubre. Si da 0 -o es OTROS, que no tiene
     * proyeccion-, partes iguales.
     *
     * @param array $renglon ['conceptos', 'locales', 'mes']
     * @param array $pagos La proyeccion ORIGINAL, sin reemplazos
     * @return array ['pesos' => [nro => float], 'proyectado' => [nro => float],
     *                'iguales' => bool]
     */
    public static function pesosLocales($renglon, $pagos) {
        $proy = array_fill_keys(array_map('intval', $renglon['locales']), 0.0);

        foreach ($pagos as $p) {
            if (isset($proy[(int) $p['nro']]) && $p['mes_pago'] === $renglon['mes']
                && self::cubre($p, $renglon['conceptos'])) {
                $proy[(int) $p['nro']] += (float) $p['a_pagar'];
            }
        }

        $suma = array_sum($proy);
        $n = count($proy);

        if ($suma <= 0 || $n === 0) {
            return ['pesos' => $n ? array_fill_keys(array_keys($proy), 1 / $n) : [],
                    'proyectado' => $proy, 'iguales' => true];
        }

        return ['pesos' => array_map(function ($v) use ($suma) { return $v / $suma; }, $proy),
                'proyectado' => $proy, 'iguales' => false];
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
     *     'facturas' => [['cod_provee', 't_comp', 'n_comp', 'vto_tango', 'pendiente' (el visto),
     *                     'retencion' (float|null)]],
     *     'renglones' => [['conceptos' => [string], 'locales' => [nro], 'todos_de' => cod|null,
     *                      'mes' => 'Y-m', 'importe' => float]]
     * ]
     * @param array $e [
     *     'pendientes' => claveCuota => fila de getPendientes() (solo las de destino A_ALQUILERES),
     *     'imputaciones' => las vigentes (AlquileresImputaciones::vigentes()),
     *     'pagos' => la proyeccion ORIGINAL,
     *     'locales' => nro => ['cod_provee', 'nombre', 'pct_iva', 'pct_percepcion', 'pct_retencion']
     * ]
     * @return array ['ok', 'errores', 'facturas', 'renglones', 'total_facturas', 'total_renglones',
     *                'diferencia', 'reemplaza' => [id], 'avisos']
     */
    public static function validar($b, $e) {
        $errores = [];
        $avisos = [];
        $facturas = [];
        $razones = [];

        foreach (is_array($b['facturas'] ?? null) ? $b['facturas'] : [] as $f) {
            $cuota = ['COD_PROVEE' => $f['cod_provee'] ?? '', 'T_COMP' => $f['t_comp'] ?? '',
                      'N_COMP' => $f['n_comp'] ?? '', 'FECHA_VTO' => $f['vto_tango'] ?? ''];
            $clave = self::claveCuota($cuota);
            $nombre = $cuota['T_COMP'] . ' ' . $cuota['N_COMP'] . ' (vence ' . self::corta($cuota['FECHA_VTO']) . ')';

            if (isset($facturas[$clave])) {
                $errores[] = 'La factura ' . $nombre . ' está dos veces.';
                continue;
            }

            if (!isset($e['pendientes'][$clave])) {
                $errores[] = 'La factura ' . $nombre . ' ya no está pendiente en Tango, o no es de alquiler: '
                    . 'volvé a cargar la pestaña.';
                continue;
            }

            $p = $e['pendientes'][$clave];
            $pend = round((float) $p['IMPORTE_PENDIENTE'], 2);

            if (abs($pend - round((float) ($f['pendiente'] ?? 0), 2)) > 0.005) {
                $errores[] = 'La factura ' . $nombre . ' cambió de pendiente en Tango mientras armabas la '
                    . 'imputación (' . self::plata((float) ($f['pendiente'] ?? 0)) . ' → ' . self::plata($pend)
                    . '): volvé a cargar la pestaña y armala de nuevo.';
            }

            $ret = ($f['retencion'] ?? null);
            $ret = ($ret === null || $ret === '') ? null : round((float) $ret, 2);

            if ($ret !== null && ($ret < 0 || $ret > $pend)) {
                $errores[] = 'La retención de ' . $nombre . ' tiene que estar entre $ 0 y su pendiente.';
            }

            $facturas[$clave] = ['clave' => $clave, 'fila' => $p, 'pendiente' => $pend, 'retencion' => $ret,
                                 'neto' => AlquileresFacturas::netoDeCuota($p)];
            $razones[Planilla::codigo($p['COD_PROVEE'])] = true;
        }

        if (empty($facturas) && empty($errores)) {
            $errores[] = 'Tildá al menos una factura.';
        }

        /* LOS RENGLONES */
        $renglones = [];
        $locales = $e['locales'] ?? [];

        foreach (is_array($b['renglones'] ?? null) ? $b['renglones'] : [] as $i => $r) {
            $n = 'Renglón ' . ($i + 1);
            $conceptos = array_values(array_unique(array_map('strval', is_array($r['conceptos'] ?? null)
                ? $r['conceptos'] : [])));
            $malos = array_diff($conceptos, self::CONCEPTOS);

            if (empty($conceptos)) {
                $errores[] = $n . ': elegí al menos un concepto.';
            } elseif (!empty($malos)) {
                $errores[] = $n . ': concepto no válido (' . implode(', ', $malos) . ').';
            } elseif (in_array(self::OTROS, $conceptos, true) && count($conceptos) > 1) {
                $errores[] = $n . ': «Otros (no proyectados)» va solo en su renglón: no reemplaza nada, y '
                    . 'mezclado con conceptos que sí reemplazan no se sabría qué parte es cuál.';
            }

            $todos = Planilla::codigo($r['todos_de'] ?? '');
            $lista = [];

            if ($todos !== '') {
                if (!isset($razones[$todos])) {
                    $errores[] = $n . ': «todos los locales de ' . $todos . '» tiene que ser de una razón '
                        . 'social de las facturas tildadas.';
                }

                foreach ($locales as $nro => $l) {
                    if (($l['cod_provee'] ?? null) === $todos) {
                        $lista[] = (int) $nro;
                    }
                }
            } else {
                $lista = array_values(array_unique(array_map('intval', is_array($r['locales'] ?? null)
                    ? $r['locales'] : [])));
            }

            if (empty($lista)) {
                $errores[] = $n . ': elegí al menos un local.';
            }

            foreach ($lista as $nro) {
                if (!isset($locales[$nro])) {
                    $errores[] = $n . ': el local ' . $nro . ' no existe en la app de alquileres.';
                } elseif (!isset($razones[$locales[$nro]['cod_provee'] ?? ''])) {
                    $errores[] = $n . ': ' . $locales[$nro]['nombre'] . ' no es de ninguna de las razones '
                        . 'sociales de las facturas tildadas.';
                }
            }

            $mes = substr(trim((string) ($r['mes'] ?? '')), 0, 7);
            $d = DateTime::createFromFormat('Y-m-d', $mes . '-01');

            if (!$d || $d->format('Y-m') !== $mes) {
                $errores[] = $n . ': el mes que cubre no es válido.';
            }

            $importe = round((float) ($r['importe'] ?? 0), 2);

            if ($importe <= 0) {
                $errores[] = $n . ': el importe tiene que ser mayor que cero.';
            }

            $renglones[] = ['orden' => $i + 1, 'conceptos' => $conceptos, 'locales' => $lista,
                            'todos_de' => ($todos === '') ? null : $todos, 'mes' => $mes, 'importe' => $importe];
        }

        if (empty($renglones)) {
            $errores[] = 'Agregá al menos un renglón.';
        }

        $totalF = round(array_sum(array_column($facturas, 'pendiente')), 2);
        $totalR = round(array_sum(array_column($renglones, 'importe')), 2);
        $dif = round($totalR - $totalF, 2);

        if (!empty($facturas) && !empty($renglones) && abs($dif) > self::TOLERANCIA) {
            $errores[] = 'Los renglones suman ' . self::plata($totalR) . ' y las facturas ' . self::plata($totalF)
                . ': la diferencia es ' . self::plata($dif) . '. Tiene que dar el total de las facturas (±$ 1).';
        }

        /* QUE REEMPLAZA, por renglon y local, con la proyeccion original. */
        foreach ($renglones as $k => $r) {
            $pl = self::pesosLocales($r, $e['pagos'] ?? []);
            $renglones[$k]['pesos'] = $pl['pesos'];
            $renglones[$k]['proyectado'] = $pl['proyectado'];
            $renglones[$k]['iguales'] = $pl['iguales'];

            if ($pl['iguales'] && !in_array(self::OTROS, $r['conceptos'], true) && count($r['locales']) > 1) {
                $avisos[] = Aviso::nuevo(Aviso::INFO, 'Renglón ' . $r['orden'] . ': esos locales no tenían nada '
                    . 'proyectado de esos conceptos en ' . $r['mes'] . ', así que el importe se reparte en partes '
                    . 'iguales entre ellos.');
            }
        }

        /* LA RETENCION SUGERIDA: la alicuota de retencion de los locales,
           ponderada por como se reparte la imputacion, sobre el neto. El neto es
           el de Tango; si no lo trae, el importe sin el IVA ni la percepcion de
           los locales. */
        $pond = self::ponderados($renglones, $locales);

        foreach ($facturas as $c => $f) {
            $neto = $f['neto'];

            if ($neto === null && $pond['iva'] !== null && $pond['percepcion'] !== null) {
                $neto = round($f['pendiente'] / (1 + ($pond['iva'] + $pond['percepcion']) / 100), 2);
            }

            $facturas[$c]['neto_usado'] = $neto;
            $facturas[$c]['neto_de'] = ($f['neto'] !== null) ? 'TANGO' : (($neto === null) ? null : 'LOCALES');
            $facturas[$c]['retencion_sugerida'] = ($neto === null || $pond['retencion'] === null)
                ? null : round($neto * $pond['retencion'] / 100, 2);
        }

        /* QUE IMPUTACIONES REEMPLAZA: las vigentes que tienen alguna de estas
           cuotas. Las otras cuotas de esas imputaciones vuelven a Sin imputar. */
        $reemplaza = [];

        foreach ($e['imputaciones'] ?? [] as $imp) {
            foreach ($imp['facturas'] as $fi) {
                if (isset($facturas[self::claveCuota($fi)])) {
                    $reemplaza[$imp['id']] = true;
                }
            }
        }

        foreach (array_keys($reemplaza) as $id) {
            $otras = [];

            foreach ($e['imputaciones'] as $imp) {
                if ($imp['id'] === $id) {
                    foreach ($imp['facturas'] as $fi) {
                        if (!isset($facturas[self::claveCuota($fi)])) {
                            $otras[] = $fi['T_COMP'] . ' ' . $fi['N_COMP'];
                        }
                    }
                }
            }

            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Reemplaza la imputación ' . $id . '.'
                . (empty($otras) ? '' : ' Sus otras facturas (' . implode(', ', $otras) . ') vuelven a Sin imputar.'));
        }

        return [
            'ok' => empty($errores),
            'errores' => array_values(array_unique($errores)),
            'facturas' => array_values($facturas),
            'renglones' => $renglones,
            'total_facturas' => $totalF,
            'total_renglones' => $totalR,
            'diferencia' => $dif,
            'reemplaza' => array_keys($reemplaza),
            'avisos' => $avisos
        ];
    }

    /**
     * El % de IVA, percepcion y retencion de los locales, ponderado por el peso
     * de cada local en la imputacion (importe del renglon x su peso). Null si
     * ningun local tiene el dato.
     */
    private static function ponderados($renglones, $locales) {
        $out = [];

        foreach (['iva' => 'pct_iva', 'percepcion' => 'pct_percepcion', 'retencion' => 'pct_retencion'] as $k => $campo) {
            $suma = 0.0;
            $peso = 0.0;

            foreach ($renglones as $r) {
                foreach ($r['pesos'] ?? [] as $nro => $w) {
                    $v = $locales[$nro][$campo] ?? null;

                    if ($v !== null) {
                        $suma += $r['importe'] * $w * (float) $v;
                        $peso += $r['importe'] * $w;
                    }
                }
            }

            $out[$k] = ($peso > 0) ? $suma / $peso : null;
        }

        return $out;
    }

    /* ====================================================================
       APLICAR: LA LISTA DE PAGOS CON LAS FACTURAS
       ==================================================================== */

    /**
     * La proyeccion con las facturas imputadas: lo que la pestaña agrupa por mes
     * y el tablero acumula por dia. Y la grilla de Facturas.
     *
     * @param array $e [
     *     'pagos' => la proyeccion (Alquileres::calcular()), con 'nro',
     *     'meses_pago' => los meses del horizonte,
     *     'pendientes' => [filas de getPendientes() con destino A_ALQUILERES],
     *     'excluidas' => [las de destino A_EXCLUIDA],
     *     'vtos' => claveCuota => ['FECHA_VTO', 'MOTIVO', 'USUARIO_ALTA', 'FECHA_ALTA'],
     *     'imputaciones' => las vigentes (ver AlquileresImputaciones::vigentes()),
     *     'en_tango' => Proveedores::estadoComprobantesEnTango() de las imputadas que
     *                   ya no estan pendientes; null si no se pudo leer,
     *     'ctx' => AlquileresFacturas::contexto(),
     *     'habiles' => 'Y-m-d' => bool, 'hoy' => 'Y-m-d',
     *     'nombres' => nro => nombre del local
     * ]
     * @return array ['pagos', 'reemplazados', 'facturas', 'imputaciones', 'pagadas_nuevas',
     *                'fuera_horizonte', 'kpis', 'avisos']
     */
    public static function aplicar($e) {
        $hoy = $e['hoy'];
        $avisos = [];
        $pend = [];

        foreach ($e['pendientes'] as $p) {
            $pend[self::claveCuota($p)] = $p;
        }

        $enHorizonte = array_flip($e['meses_pago']);
        $ctx = $e['ctx'];

        /* 1. EL ESTADO DE CADA CUOTA IMPUTADA, y que grupos se leen. */
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

        /* 2. LOS PESOS DE CADA RENGLON, con la proyeccion ORIGINAL. */
        foreach ($grupos as $id => $g) {
            foreach ($g['renglones'] as $k => $r) {
                $pl = self::pesosLocales($r, $e['pagos']);
                $grupos[$id]['renglones'][$k]['pesos'] = $pl['pesos'];
                $grupos[$id]['renglones'][$k]['iguales'] = $pl['iguales'];
            }
        }

        /* 3. EL REEMPLAZO: fuera de la proyeccion lo que cubre algun renglon
              de un grupo que reemplaza. */
        $proyeccion = [];
        $reemplazados = [];

        foreach ($e['pagos'] as $p) {
            $por = null;

            foreach ($grupos as $id => $g) {
                if (!$g['reemplaza']) {
                    continue;
                }

                foreach ($g['renglones'] as $r) {
                    if ($r['mes'] === $p['mes_pago'] && in_array((int) $p['nro'], array_map('intval', $r['locales']), true)
                        && self::cubre($p, $r['conceptos'])) {
                        $por = ['imputacion' => $id, 'renglon' => $r['orden']];
                        break 2;
                    }
                }
            }

            if ($por === null) {
                $proyeccion[] = $p;
            } else {
                $reemplazados[] = $p + ['reemplazado_por' => $por];
            }
        }

        /* 4. LAS PIEZAS: cada cuota pendiente de un grupo que se lee, repartida
              en renglon x local, en la fecha de la cuota. */
        $piezas = [];
        $fuera = 0.0;
        $iguales = [];

        foreach ($grupos as $id => $g) {
            if ($g['suspendida']) {
                continue;
            }

            foreach ($g['facturas'] as $k => $f) {
                if ($g['estados'][$k]['estado'] !== self::IMPUTADA) {
                    continue;
                }

                $fila = $pend[self::claveCuota($f)];
                $total = round((float) $fila['IMPORTE_PENDIENTE'], 2);
                $ret = min(round((float) $f['RETENCION'], 2), $total);
                $dia = self::fechaDePago(self::vtoAConsiderar($fila, $e['vtos']), $e['habiles'], $hoy);
                $nota = self::notaFecha($dia);
                $pesos = [];

                foreach ($g['renglones'] as $r) {
                    foreach ($r['pesos'] as $nro => $w) {
                        $pesos[$r['orden'] . '|' . $nro] = $r['importe'] * $w;
                    }

                    if ($r['iguales'] && $r['conceptos'] !== [self::OTROS] && count($r['locales']) > 1) {
                        $iguales[$id . '|' . $r['orden']] = 'imputación ' . $id . ', renglón ' . $r['orden']
                            . ' (' . $r['mes'] . ')';
                    }
                }

                $importes = self::repartirCentavos($total, $pesos);
                $retenciones = self::repartirCentavos($ret, $pesos);
                $porOrden = array_column($g['renglones'], null, 'orden');

                foreach ($importes as $pk => $imp) {
                    list($orden, $nro) = explode('|', $pk);
                    $r = $porOrden[(int) $orden];
                    $rt = $retenciones[$pk];
                    $mesPago = substr($dia['fecha'], 0, 7);
                    $pago = [
                        'nro' => (int) $nro,
                        'mes_pago' => $mesPago,
                        'fecha' => $dia['fecha'],
                        'concepto' => 'FACTURA:' . $id . ':' . $orden,
                        'grupo' => self::grupoDeRenglon($r['conceptos']),
                        'mes_concepto' => $r['mes'],
                        'origen' => AlquileresCalculo::FACTURA,
                        'vencimiento' => $dia['vencimiento'],
                        'corrida' => $dia['corrida'],
                        'vencido' => $dia['vencido'],
                        'faltan_calendario' => $dia['faltan_calendario'],
                        'nota_dia' => $nota,
                        /* EL NETO DE UNA PIEZA ES EL IMPORTE DE LA FACTURA, con IVA y
                           percepciones: la factura no se abre en impuestos por
                           local. 'con_iva' se lo dice al tooltip. */
                        'neto' => $imp,
                        'iva' => 0.0,
                        'percepcion' => 0.0,
                        'retencion' => $rt,
                        'a_pagar' => round($imp - $rt, 2),
                        'detalle' => [
                            'imputacion' => $id,
                            'renglon' => (int) $orden,
                            'conceptos' => $r['conceptos'],
                            'mes_cubre' => $r['mes'],
                            'factura' => $fila['T_COMP'] . ' ' . $fila['N_COMP'],
                            'cod_provee' => $fila['COD_PROVEE'],
                            'razon' => $fila['RAZON_SOC'] ?? '',
                            'reparto' => $r['iguales'] ? 'IGUAL' : 'PROYECTADO',
                            'peso' => $r['pesos'][(int) $nro],
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
        }

        /* 5. LA GRILLA DE FACTURAS. */
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

            $pendiente = round((float) $p['IMPORTE_PENDIENTE'], 2);

            if ($estado === self::SIN_IMPUTAR || $estado === self::SUSPENDIDA) {
                $sinImputar['n']++;
                $sinImputar['importe'] += $pendiente;
            }

            $filas[] = self::filaFactura($p, $e['vtos'], $e['habiles'], $hoy, $estado, $g, $f);
        }

        /* Las imputadas que ya no estan pendientes: pagadas (con "Ver pagadas"),
           anuladas o sin dato de Tango. */
        foreach ($grupos as $id => $g) {
            foreach ($g['facturas'] as $k => $f) {
                $s = $g['estados'][$k];

                if ($s['estado'] === self::IMPUTADA) {
                    continue;
                }

                $filas[] = self::filaFactura(['COD_PROVEE' => $f['COD_PROVEE'], 'RAZON_SOC' => $f['RAZON_SOC'] ?? '',
                    'T_COMP' => $f['T_COMP'], 'N_COMP' => $f['N_COMP'], 'FECHA_VTO' => $f['FECHA_VTO_TANGO'],
                    'FECHA_EMIS' => null, 'IMPORTE_VTO' => null, 'IMPORTE_PENDIENTE' => 0.0, 'LEYENDA' => ''],
                    $e['vtos'], $e['habiles'], $hoy, $s['estado'], $g, $f) + ['fecha_pagada' => $s['fecha']];
            }
        }

        /* 6. LOS AVISOS. */
        if ($sinImputar['n'] > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $sinImputar['n'] . ' factura(s) de alquiler sin imputar por '
                . self::plata($sinImputar['importe']) . ': no suman hasta que se imputen.', 'Facturas');
        }

        $cambios = [];
        $anuladas = [];
        $sinDato = [];
        $suspendidas = [];

        foreach ($grupos as $id => $g) {
            if ($g['suspendida']) {
                $suspendidas[] = $id;
            }

            foreach ($g['facturas'] as $k => $f) {
                $s = $g['estados'][$k];
                $nom = $f['T_COMP'] . ' ' . $f['N_COMP'];

                if ($s['estado'] === self::IMPUTADA && $s['cambio'] !== null) {
                    $cambios[] = $nom . ' (' . self::plata((float) $f['PENDIENTE']) . ' → ' . self::plata($s['cambio']) . ')';
                } elseif ($s['estado'] === self::ANULADA) {
                    $anuladas[] = $nom . ' (imputación ' . $id . ($g['reemplaza'] ? '' : ', ya no reemplaza') . ')';
                } elseif ($s['estado'] === self::SIN_DATO) {
                    $sinDato[] = $nom;
                }
            }
        }

        if (!empty($cambios)) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Cambió en Tango el pendiente de ' . count($cambios)
                . ' factura(s) imputada(s) sin cancelarse: ' . implode('; ', $cambios) . '. Se proyecta el '
                . 'pendiente nuevo menos la retención; revisá la imputación.', 'Facturas');
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

        if (!empty($iguales)) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Sin nada proyectado para repartir, se repartió en partes '
                . 'iguales entre los locales: ' . implode('; ', $iguales) . '.', 'Facturas');
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
            'imputaciones' => array_values(array_map(function ($g) {
                return array_intersect_key($g, array_flip(['id', 'usuario', 'fecha', 'observacion',
                    'facturas', 'renglones', 'suspendida', 'reemplaza']));
            }, $grupos)),
            'pagadas_nuevas' => $pagadasNuevas,
            'fuera_horizonte' => round($fuera, 2),
            'kpis' => [
                'sin_imputar_n' => $sinImputar['n'],
                'sin_imputar_importe' => round($sinImputar['importe'], 2),
                'imputadas_n' => count($imputadas),
                'imputadas_a_pagar' => round(array_sum(array_column($imputadas, 'a_pagar')), 2)
            ],
            'avisos' => $avisos
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
                    'cambio' => (abs($ahora - round((float) $f['PENDIENTE'], 2)) > 0.005) ? $ahora : null];
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

    /** Una fila de la grilla de Facturas */
    private static function filaFactura($p, $vtos, $habiles, $hoy, $estado, $g, $f) {
        $c = self::claveCuota($p);
        $vto = self::vtoAConsiderar($p, $vtos);
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
            'fecha_pago' => ($dia === null) ? null : $dia['fecha'],
            'nota_fecha' => ($dia === null) ? '' : self::notaFecha($dia),
            'importe' => isset($p['IMPORTE_VTO']) ? $p['IMPORTE_VTO'] : null,
            'pendiente' => $pendiente,
            'neto_gravado' => AlquileresFacturas::netoDeCuota($p),
            'retencion' => $ret,
            'a_pagar' => round($pendiente - ($ret ?? 0.0), 2),
            'estado' => $estado,
            'imputacion' => ($g === null) ? null : $g['id'],
            'imputacion_usuario' => ($g === null) ? null : ($g['usuario'] ?? null),
            'imputacion_fecha' => ($g === null) ? null : ($g['fecha'] ?? null),
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
}
