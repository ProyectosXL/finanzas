<?php

require_once __DIR__ . '/TarjetasVencimiento.php';
require_once __DIR__ . '/TarjetasCorporativas.php';
require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Aviso.php';

/**
 * TarjetasSeguros
 * Los seguros de Tarjetas Pagos Corporativos: quien es un proveedor de seguros,
 * que se valida de una poliza y que pagos genera.
 *
 * TODO LO DE ACA ES PURO. Recibe el maestro de proveedores, las polizas, las
 * tarjetas, los resumenes, el calendario, la inflacion y la curva ya leidos, y
 * devuelve numeros. La lectura y la escritura viven en TarjetasPoliza.
 *
 * QUIEN ES UN SEGURO
 * ------------------
 * El proveedor que en el maestro de Proveedores Locales tiene RUBRO = SEGUROS y
 * forma de pago TARJETA CORP. Las DOS cosas en el maestro, y no la forma
 * vigente de alguna factura: el proveedor tiene que seguir siendo un seguro
 * cuando no le queda ninguna factura pendiente, porque sus polizas siguen.
 *
 * SUS FACTURAS NO SE USAN. Salen de la grilla de Corporativas, de su total y de
 * la serie del tablero, y el seguro se proyecta SOLO CON LAS POLIZAS: las
 * facturas de un seguro en cuotas y la poliza describirian el mismo pago dos
 * veces. Una linea informativa dice cuantas hay pendientes y por cuanto.
 *
 * LA POLIZA
 * ---------
 *   cuota    = total / (vigencia - gracia)
 *   pagos    = vigencia - gracia, uno por mes, CONSECUTIVOS desde el mes del
 *              primer pago. La gracia son los meses del FINAL de la vigencia.
 *   la fecha = el debito de la tarjeta de la poliza en ese mes (el resumen
 *              cargado si lo hay, si no TarjetasVencimiento::delMes()). El mes
 *              del primer pago solo fija el mes.
 *   dolares  = cada pago, con la curva ROFEX del mes de su fecha
 *              (DolarFuturo::resolver(), con su mes aproximado y su aviso).
 *
 * Cada pago se comporta como una factura vinculada: genera cobertura con el %
 * de su tarjeta, lo pisa el resumen cargado de su mes y no se proyecta si su
 * fecha es hoy o antes.
 *
 * LA CONTINUACION
 * ---------------
 * Las vigencias no se pisan: termina una poliza y se carga la siguiente.
 * Mientras la siguiente no este, se repite el ciclo hasta el fin del horizonte
 * -misma vigencia, misma gracia, el primer pago a la misma distancia del inicio-:
 *
 *   en dolares  el mismo importe en U$S cada ciclo
 *   en pesos    el total del ciclo anterior x (1 + SUMA de la inflacion de los
 *               meses de la vigencia anterior / 100), sumada y no compuesta
 *               (Inflacion::sumada()), como el ajuste del valor hora de
 *               Logistica. Si falta un mes, ese ciclo y los siguientes quedan en
 *               null con aviso.
 *
 * La poliza SIGUIENTE -mismo proveedor y mismo numero, con inicio igual o
 * posterior al fin de esta- corta la continuacion desde su inicio.
 */
class TarjetasSeguros {

    const RUBRO = 'SEGUROS';
    const FORMA = 'TARJETA CORP';

    const ARS = 'ARS';
    const USD = 'USD';

    const MONEDAS = [self::ARS => '$', self::USD => 'U$S'];

    /** Tope defensivo de la vigencia: diez anos */
    const VIGENCIA_MAX = 120;

    /** Tope de la observacion y de la poliza, los de las columnas */
    const LARGO_POLIZA = 100;
    const LARGO_OBSERVACION = 500;

    /* El estado de cada pago. Los cuatro primeros son los de una estimacion de
       Corporativas; los dos ultimos, de un importe que no se pudo calcular. */
    const PROYECTA = TarjetasCorporativas::EST_PROYECTA;
    const CUBIERTA = TarjetasCorporativas::EST_CUBIERTA;
    const PASADA = TarjetasCorporativas::EST_PASADA;
    const SIN_TARJETA = TarjetasCorporativas::EST_SIN_TARJETA;
    const SIN_INFLACION = 'SIN_INFLACION';
    const SIN_COTIZACION = 'SIN_COTIZACION';

    /* ====================================================================
       QUIEN ES UN SEGURO
       ==================================================================== */

    /**
     * Si una fila del maestro de Proveedores Locales es un seguro.
     *
     * @param string|null $rubro
     * @param string|null $forma La forma de pago del maestro, ya normalizada
     * @return bool
     */
    public static function esSeguro($rubro, $forma) {
        return mb_strtoupper(trim((string) $rubro), 'UTF-8') === self::RUBRO
            && trim((string) $forma) === self::FORMA;
    }

    /**
     * Los proveedores de seguros del maestro, ordenados por nombre.
     *
     * @param array $mapa Lo que devolvio ProveedoresCategorias::mapa()
     * @return array Mapa COD_PROVEE => ['COD_PROVEE', 'RAZON_SOC']
     */
    public static function proveedores($mapa) {
        $v = [];

        foreach (is_array($mapa) ? $mapa : [] as $cod => $m) {
            if (self::esSeguro(isset($m['RUBRO']) ? $m['RUBRO'] : null,
                               isset($m['FORMA_PAGO']) ? $m['FORMA_PAGO'] : null)) {
                $v[trim((string) $cod)] = ['COD_PROVEE' => trim((string) $cod),
                    'RAZON_SOC' => trim((string) (isset($m['NOMBRE']) ? $m['NOMBRE'] : $cod))];
            }
        }

        uasort($v, function ($a, $b) { return strnatcasecmp($a['RAZON_SOC'], $b['RAZON_SOC']); });

        return $v;
    }

    /**
     * Las facturas pendientes de los seguros: cuantas y por cuanto. Es la linea
     * informativa debajo de la seccion: NO SUMAN.
     *
     * @param array $facturas Las filas del universo que son de seguros
     * @return array ['cuantas', 'comprobantes', 'importe', 'por_proveedor' => [cod => importe]]
     */
    public static function resumenFacturas($facturas) {
        $comp = [];
        $importe = 0.0;
        $porProv = [];

        foreach ($facturas as $f) {
            $comp[TarjetasCorporativas::clave($f)] = true;
            $i = floatval(isset($f['IMPORTE_PENDIENTE']) ? $f['IMPORTE_PENDIENTE'] : 0);
            $importe += $i;
            $porProv[$f['COD_PROVEE']] = (isset($porProv[$f['COD_PROVEE']]) ? $porProv[$f['COD_PROVEE']] : 0) + $i;
        }

        return ['cuantas' => count($facturas), 'comprobantes' => count($comp),
                'importe' => round($importe, 2), 'por_proveedor' => $porProv];
    }

    /* ====================================================================
       LA POLIZA
       ==================================================================== */

    /**
     * El dia siguiente al ultimo dia de la vigencia: inicio + N meses, con el dia
     * acotado al ultimo del mes. Las vigencias se encadenan asi: la siguiente
     * empieza el dia que devuelve esta funcion.
     *
     * @param string $inicio 'Y-m-d'
     * @param int $meses
     * @return string 'Y-m-d'
     */
    public static function inicioSiguiente($inicio, $meses) {
        $mes = Inflacion::mesMas(substr($inicio, 0, 7), intval($meses));
        $ultimo = intval((new DateTime($mes . '-01'))->format('t'));

        return $mes . '-' . sprintf('%02d', min(intval(substr($inicio, 8, 2)), $ultimo));
    }

    /**
     * El ultimo dia de la vigencia.
     *
     * @param string $inicio 'Y-m-d'
     * @param int $meses
     * @return string 'Y-m-d'
     */
    public static function finVigencia($inicio, $meses) {
        return (new DateTime(self::inicioSiguiente($inicio, $meses)))->modify('-1 day')->format('Y-m-d');
    }

    /** La clave que identifica la serie de una poliza: proveedor y numero */
    public static function claveSerie($cod, $poliza) {
        return trim((string) $cod) . '|' . mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $poliza)), 'UTF-8');
    }

    /**
     * Una poliza, validada entera. La validacion que vale es esta, no la del
     * formulario.
     *
     * @param array $d proveedor, poliza, id_tarjeta, importe_total, moneda,
     *                 fecha_inicio, vigencia_meses, gracia_meses, mes_primer_pago,
     *                 observacion
     * @param array $seguros Lo que devolvio proveedores()
     * @param array $tarjetas Mapa ID => tarjeta, todas
     * @param array $otras Las polizas vigentes, para la superposicion
     * @param int|null $id La que se esta corrigiendo, o null en un alta
     * @return array Los campos de la tabla
     */
    public static function validar($d, $seguros, $tarjetas, $otras, $id = null) {
        $v = function ($k) use ($d) { return isset($d[$k]) ? $d[$k] : null; };

        $cod = trim((string) $v('proveedor'));

        if (!isset($seguros[$cod])) {
            throw new Exception('El proveedor "' . $cod . '" no es un seguro: en el maestro de '
                . 'Proveedores Locales tiene que tener rubro SEGUROS y forma de pago TARJETA CORP.');
        }

        $poliza = trim(preg_replace('/\s+/', ' ', (string) $v('poliza')));

        if ($poliza === '') {
            throw new Exception('Falta el número o la descripción de la póliza: con el proveedor, '
                . 'es lo que identifica la póliza siguiente.');
        }

        if (mb_strlen($poliza) > self::LARGO_POLIZA) {
            throw new Exception('El número o la descripción de la póliza tiene más de '
                . self::LARGO_POLIZA . ' caracteres.');
        }

        $idTarjeta = intval($v('id_tarjeta'));
        $t = isset($tarjetas[$idTarjeta]) ? $tarjetas[$idTarjeta] : null;

        if ($t === null || $t['TIPO'] !== 'CORPORATIVA' || empty($t['ACTIVA'])) {
            throw new Exception('Elegí una tarjeta corporativa activa: de ella salen el día del '
                . 'débito y el % de cobertura.');
        }

        $importe = $v('importe_total');

        if ($importe === null || $importe === '' || !is_numeric($importe) || floatval($importe) <= 0) {
            throw new Exception('El importe total de la vigencia tiene que ser mayor a cero.');
        }

        $moneda = strtoupper(trim((string) $v('moneda')));

        if (!isset(self::MONEDAS[$moneda])) {
            throw new Exception('La moneda tiene que ser $ o U$S.');
        }

        $inicio = substr(trim((string) $v('fecha_inicio')), 0, 10);
        $dt = DateTime::createFromFormat('Y-m-d', $inicio);

        if (!$dt || $dt->format('Y-m-d') !== $inicio) {
            throw new Exception('El inicio de la vigencia no es una fecha válida.');
        }

        $vigencia = self::entero($v('vigencia_meses'), 'La vigencia');

        if ($vigencia < 1 || $vigencia > self::VIGENCIA_MAX) {
            throw new Exception('La vigencia va en meses, entre 1 y ' . self::VIGENCIA_MAX . '.');
        }

        $gracia = ($v('gracia_meses') === null || $v('gracia_meses') === '')
            ? 0 : self::entero($v('gracia_meses'), 'El período de gracia');

        if ($gracia < 0 || $gracia >= $vigencia) {
            throw new Exception('El período de gracia va en meses: de 0 a ' . ($vigencia - 1)
                . ', menos que la vigencia. Con ' . $vigencia . ' de vigencia y ' . $gracia
                . ' de gracia no quedaría ningún pago.');
        }

        $primer = trim((string) $v('mes_primer_pago'));

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $primer)) {
            throw new Exception('Falta el mes del primer pago, aaaa-mm.');
        }

        if ($primer < substr($inicio, 0, 7)) {
            throw new Exception('El primer pago no puede ser anterior al mes de inicio de la '
                . 'vigencia (' . substr($inicio, 0, 7) . ').');
        }

        /* EL ULTIMO PAGO NO PUEDE QUEDAR DESPUES DEL FIN DE LA VIGENCIA. Se mira
           el debito TEORICO -el dia de la tarjeta acotado al mes, sin correr al
           habil-, que no depende de tener calendario mas alla del horizonte. */
        $pagos = $vigencia - $gracia;
        $fin = self::finVigencia($inicio, $vigencia);
        $ultimoMes = Inflacion::mesMas($primer, $pagos - 1);
        $teorico = self::debitoTeorico($t['DIA_VENCIMIENTO'], $ultimoMes);

        if ($teorico > $fin) {
            throw new Exception('Con ' . $vigencia . ' mes(es) de vigencia y ' . $gracia . ' de '
                . 'gracia son ' . $pagos . ' pago(s), uno por mes desde ' . $primer . ': el último '
                . 'cae en ' . $ultimoMes . ' (débito del ' . self::corto($teorico) . ') y la '
                . 'vigencia termina el ' . self::corto($fin) . '. Adelantá el mes del primer pago '
                . 'o revisá la gracia.');
        }

        /* LAS VIGENCIAS NO SE PISAN: dos polizas del mismo proveedor y numero con
           vigencias superpuestas se rechazan. La siguiente empieza el dia que
           termina la anterior, o despues. */
        $serie = self::claveSerie($cod, $poliza);

        foreach ($otras as $o) {
            if (($id !== null && intval($o['ID']) === intval($id))
                || self::claveSerie($o['COD_PROVEE'], $o['POLIZA']) !== $serie) {
                continue;
            }

            $oFin = self::finVigencia($o['FECHA_INICIO'], $o['VIGENCIA_MESES']);

            if ($inicio <= $oFin && $o['FECHA_INICIO'] <= $fin) {
                throw new Exception('La vigencia se superpone con la de otra póliza ' . $poliza
                    . ' de este proveedor (del ' . self::corto($o['FECHA_INICIO']) . ' al '
                    . self::corto($oFin) . '). Las vigencias no se pisan: la siguiente empieza '
                    . 'cuando termina la anterior.');
            }
        }

        $obs = trim((string) $v('observacion'));

        return [
            'COD_PROVEE' => $cod,
            'RAZON_SOC' => mb_substr($seguros[$cod]['RAZON_SOC'], 0, 100),
            'POLIZA' => $poliza,
            'ID_TARJETA' => $idTarjeta,
            'IMPORTE_TOTAL' => round(floatval($importe), 2),
            'MONEDA' => $moneda,
            'FECHA_INICIO' => $inicio,
            'VIGENCIA_MESES' => $vigencia,
            'GRACIA_MESES' => $gracia,
            'MES_PRIMER_PAGO' => $primer,
            'OBSERVACION' => ($obs === '') ? null : mb_substr($obs, 0, self::LARGO_OBSERVACION)
        ];
    }

    /** La cuota de un ciclo: total / (vigencia - gracia) */
    public static function cuota($total, $vigencia, $gracia) {
        return ($total === null) ? null : floatval($total) / (intval($vigencia) - intval($gracia));
    }

    /**
     * El inicio de la poliza siguiente de cada poliza: la del mismo proveedor y
     * numero que empieza en el fin de esta o despues. Corta la continuacion.
     *
     * @param array $polizas
     * @return array Mapa ID => 'Y-m-d' | null
     */
    public static function siguientes($polizas) {
        $v = [];

        foreach ($polizas as $p) {
            $finP = self::finVigencia($p['FECHA_INICIO'], $p['VIGENCIA_MESES']);
            $serie = self::claveSerie($p['COD_PROVEE'], $p['POLIZA']);
            $sig = null;

            foreach ($polizas as $o) {
                if (intval($o['ID']) === intval($p['ID'])
                    || self::claveSerie($o['COD_PROVEE'], $o['POLIZA']) !== $serie
                    || $o['FECHA_INICIO'] <= $finP) {
                    continue;
                }

                if ($sig === null || $o['FECHA_INICIO'] < $sig) {
                    $sig = $o['FECHA_INICIO'];
                }
            }

            $v[intval($p['ID'])] = $sig;
        }

        return $v;
    }

    /**
     * Los pagos de una poliza dentro del horizonte: los de su vigencia y, si
     * termina antes, los de la continuacion hasta el fin o hasta la poliza
     * siguiente.
     *
     * @param array $p La poliza
     * @param array|null $tarjeta Su tarjeta, o null si ya no existe
     * @param array $resumenes Mapa 'Y-m' => resumen vigente de esa tarjeta
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $meses Los meses del horizonte, en orden
     * @param string $hoy 'Y-m-d'
     * @param array $inflacion Mapa 'Y-m' => float
     * @param array $curva La curva de dolar futuro
     * @param string|null $corte El inicio de la poliza siguiente, o null
     * @return array ['pagos' => [...], 'faltan_inflacion' => ['Y-m'],
     *                'faltan_cotizacion' => ['Y-m'], 'faltan_calendario' => ['Y-m'],
     *                'ciclos' => int]
     */
    public static function pagos($p, $tarjeta, $resumenes, $habiles, $meses, $hoy, $inflacion,
                                 $curva, $corte = null) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $salida = ['pagos' => [], 'faltan_inflacion' => [], 'faltan_cotizacion' => [],
                   'faltan_calendario' => [], 'ciclos' => 0];

        if (empty($meses)) {
            return $salida;
        }

        $enHorizonte = array_fill_keys($meses, true);
        $ultimoMes = $meses[count($meses) - 1];
        $vig = intval($p['VIGENCIA_MESES']);
        $gracia = intval($p['GRACIA_MESES']);
        $n = $vig - $gracia;
        $usd = ($p['MONEDA'] === self::USD);
        $tarjetaOk = ($tarjeta !== null && !empty($tarjeta['ACTIVA'])
                      && $tarjeta['TIPO'] === 'CORPORATIVA');
        $mesCorte = ($corte === null) ? null : substr($corte, 0, 7);

        $total = floatval($p['IMPORTE_TOTAL']);
        $ajuste = null;

        for ($k = 0; ; $k++) {
            $inicioK = self::inicioSiguiente($p['FECHA_INICIO'], $k * $vig);
            $primerK = Inflacion::mesMas($p['MES_PRIMER_PAGO'], $k * $vig);

            /* SE TERMINA CUANDO EL CICLO EMPIEZA A PAGAR DESPUES DEL HORIZONTE, o
               cuando la continuacion llega a la poliza siguiente. */
            if ($primerK > $ultimoMes || ($k > 0 && $mesCorte !== null && $primerK >= $mesCorte)) {
                break;
            }

            /* EL TOTAL DE LA CONTINUACION. En dolares, el mismo. En pesos, el del
               ciclo anterior por la inflacion sumada de su vigencia. */
            if ($k > 0 && !$usd && $total !== null) {
                $inicioAnterior = self::inicioSiguiente($p['FECHA_INICIO'], ($k - 1) * $vig);
                $ajuste = Inflacion::sumada($inflacion, substr($inicioAnterior, 0, 7), $vig);

                if ($ajuste['pct'] === null) {
                    foreach ($ajuste['faltan'] as $m) {
                        if (!in_array($m, $salida['faltan_inflacion'], true)) {
                            $salida['faltan_inflacion'][] = $m;
                        }
                    }

                    $total = null;
                } else {
                    $total = $total * (1 + $ajuste['pct'] / 100);
                }
            }

            $cuota = self::cuota($total, $vig, $gracia);
            $salida['ciclos']++;

            for ($j = 0; $j < $n; $j++) {
                $mes = Inflacion::mesMas($primerK, $j);

                if ($mes > $ultimoMes) {
                    break;
                }

                if (!isset($enHorizonte[$mes])) {
                    continue;
                }

                if ($k > 0 && $mesCorte !== null && $mes >= $mesCorte) {
                    break;
                }

                $resumen = isset($resumenes[$mes]) ? $resumenes[$mes] : null;
                $fecha = null;

                if ($resumen !== null) {
                    $fecha = $resumen['FECHA_VENCIMIENTO'];
                } elseif ($tarjeta !== null) {
                    $vt = TarjetasVencimiento::delMes($tarjeta['DIA_VENCIMIENTO'], $mes, $habiles);
                    $fecha = $vt['fecha'];

                    foreach ($vt['faltan'] as $m) {
                        if (!in_array($m, $salida['faltan_calendario'], true)) {
                            $salida['faltan_calendario'][] = $m;
                        }
                    }
                }

                $pago = [
                    'mes' => $mes,
                    'fecha' => $fecha,
                    'ciclo' => $k,
                    'continuacion' => ($k > 0),
                    'cuota_nro' => $j + 1,
                    'cuotas' => $n,
                    'inicio_ciclo' => $inicioK,
                    'usd' => $usd ? $cuota : null,
                    'tc' => null,
                    'tc_detalle' => null,
                    'importe' => $usd ? null : $cuota,
                    'ajuste' => ($k > 0 && !$usd) ? $ajuste : null
                ];

                if ($usd && $fecha !== null) {
                    $r = DolarFuturo::resolver($curva, $fecha);

                    if ($r['cotizacion'] !== null) {
                        $pago['tc'] = $r['cotizacion'];
                        $pago['importe'] = $cuota * $r['cotizacion'];
                        $pago['tc_detalle'] = 'Dólar futuro ' . $r['simbolo'] . ' (' . $r['mes_curva'] . ')'
                            . (($r['origen'] === DolarFuturo::ORIGEN_APROXIMADA)
                                ? ', APROXIMADO: la curva no llega a ' . $r['mes_pago']
                                  . ' y se usa el mes más cercano.' : '.');
                    } elseif (!in_array($mes, $salida['faltan_cotizacion'], true)) {
                        $salida['faltan_cotizacion'][] = $mes;
                    }
                }

                if (!$tarjetaOk) {
                    $estado = self::SIN_TARJETA;
                } elseif ($fecha !== null && $fecha <= $hoyStr) {
                    $estado = self::PASADA;
                } elseif ($resumen !== null) {
                    $estado = self::CUBIERTA;
                } elseif ($pago['importe'] === null) {
                    $estado = $usd ? self::SIN_COTIZACION : self::SIN_INFLACION;
                } else {
                    $estado = self::PROYECTA;
                }

                $pago['estado'] = $estado;
                $pago['proyecta'] = ($estado === self::PROYECTA);
                $pago['tooltip'] = self::explicarPago($p, $pago);

                $salida['pagos'][] = $pago;
            }
        }

        return $salida;
    }

    /**
     * Los pagos que proyectan, como filas que TarjetasCorporativas::cobertura()
     * entiende: se comportan como una factura vinculada de su tarjeta y su mes.
     *
     * @param array $polizas Las polizas con su 'resueltos' => lo de pagos()
     * @return array
     */
    public static function comoFilas($polizas) {
        $v = [];

        foreach ($polizas as $p) {
            foreach ($p['resueltos']['pagos'] as $pago) {
                if ($pago['proyecta']) {
                    $v[] = ['PROYECTA' => true, 'ID_TARJETA' => intval($p['ID_TARJETA']),
                            'MES_PAGO' => $pago['mes'], 'IMPORTE' => $pago['importe'],
                            'ESTIMACION' => true];
                }
            }
        }

        return $v;
    }

    /**
     * El tooltip de un pago: de donde sale el numero.
     *
     * @return string
     */
    public static function explicarPago($p, $pago) {
        $t = 'Póliza ' . $p['POLIZA'] . ', cuota ' . $pago['cuota_nro'] . ' de ' . $pago['cuotas']
            . ' (' . ($p['MONEDA'] === self::USD ? 'U$S' : '$') . ' total ÷ '
            . $pago['cuotas'] . ').';

        if ($pago['continuacion']) {
            $t .= ' ESTIMADA: la vigencia cargada terminó y todavía no está la póliza siguiente, '
                . 'así que se repite el ciclo que empieza el ' . self::corto($pago['inicio_ciclo']) . '.';

            if ($p['MONEDA'] === self::USD) {
                $t .= ' En dólares se repite el mismo importe.';
            } elseif ($pago['ajuste'] !== null && $pago['ajuste']['pct'] !== null) {
                $t .= ' En pesos, el ciclo anterior más ' . number_format($pago['ajuste']['pct'], 2, ',', '.')
                    . ' % de inflación sumada (' . implode(' + ', array_map(function ($m, $v) {
                        return $m . ' ' . number_format($v, 2, ',', '.');
                    }, array_keys($pago['ajuste']['meses']), $pago['ajuste']['meses'])) . ').';
            }
        }

        if ($pago['tc'] !== null) {
            $t .= ' U$S ' . number_format($pago['usd'], 2, ',', '.') . ' × '
                . number_format($pago['tc'], 2, ',', '.') . '. ' . $pago['tc_detalle'];
        }

        $motivos = [
            self::CUBIERTA => ' La tarjeta tiene resumen cargado en ' . $pago['mes'] . ': ya lo incluye, así que no suma aparte.',
            self::PASADA => ' El débito ya pasó: no se proyecta.',
            self::SIN_TARJETA => ' La tarjeta de la póliza está inactiva o ya no es corporativa: no se proyecta.',
            self::SIN_INFLACION => ' Falta la inflación de algún mes de la vigencia anterior, así que no se puede ajustar: queda en blanco y NO en cero.',
            self::SIN_COTIZACION => ' No hay dólar futuro para este mes: queda en blanco y NO en cero.'
        ];

        return $t . (isset($motivos[$pago['estado']]) ? $motivos[$pago['estado']] : '');
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** El debito teorico de un mes: el dia de la tarjeta acotado al mes */
    private static function debitoTeorico($dia, $mes) {
        $ultimo = intval((new DateTime($mes . '-01'))->format('t'));

        return $mes . '-' . sprintf('%02d', min(max(1, intval($dia)), $ultimo));
    }

    private static function entero($valor, $que) {
        if ($valor === null || $valor === '' || !is_numeric($valor) || intval($valor) != floatval($valor)) {
            throw new Exception($que . ' tiene que ser un número entero de meses.');
        }

        return intval($valor);
    }

    /** 'Y-m-d' => 'd/m/Y' */
    private static function corto($fecha) {
        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4);
    }
}
