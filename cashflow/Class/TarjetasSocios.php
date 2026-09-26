<?php

require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/TarjetasVencimiento.php';
require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Cotizacion.php';

/**
 * TarjetasSocios
 * La estimacion de las tarjetas de los socios: el promedio de los ultimos tres
 * resumenes, ajustado, y con el componente en dolares convertido a pesos.
 *
 * TODO LO DE ACA ES PURO. Recibe los resumenes, el mapa de inflacion, la curva de
 * dolar futuro y la cotizacion del BCRA ya leidos, y devuelve numeros. Es lo que
 * permite probar la regla del dolar y la base incompleta sin montar resumenes ni
 * cotizaciones en SQL Server.
 *
 * LA BASE SON LOS ULTIMOS TRES RESUMENES, DE CUALQUIER ORIGEN
 * ----------------------------------------------------------
 * Al principio se cargan tres de una con el boton "Cargar base historica"
 * (ORIGEN = HISTORICO, ya pagados). Despues, los que se van cargando mes a mes
 * (ORIGEN = CARGA) pasan a formar parte del historico y la base se corre sola: son
 * siempre los ultimos tres, con periodo anterior al mes en curso.
 *
 * ORIGEN NO PARTICIPA DEL CALCULO, y eso es deliberado: un resumen es un resumen,
 * y de donde salio importa para poder explicar por que hay tres cargados el mismo
 * dia, no para decidir si cuenta.
 *
 * EL PERIODO ANTERIOR AL MES EN CURSO, y no la fecha de vencimiento: el resumen
 * del mes en curso puede estar cargado y todavia no vencido, y usarlo como base
 * seria estimar el mes con su propio dato.
 *
 * SE DIVIDE POR LOS RESUMENES QUE HAY, NO POR TRES
 * ------------------------------------------------
 * Y ahi esta la diferencia con Gastos Supervisoras, que SIEMPRE divide por tres.
 * No es una inconsistencia: son dos cosas distintas.
 *
 *   Supervisoras  la ventana son TRES MESES CALENDARIO, y existen los tres. Un mes
 *                 sin gastos es un mes en el que no se gasto: es un dato, y por
 *                 eso divide por tres.
 *
 *   Socios        la base son LOS ULTIMOS TRES RESUMENES. Si hay dos, el tercero
 *                 no es "un mes sin consumo": es un resumen que NO SE CARGO.
 *                 Dividir por tres afirmaria que ese mes no hubo gastos, que es
 *                 algo que nadie dijo, y proyectaria de menos.
 *
 * Con menos de tres se promedia lo que hay y se avisa. Sin ninguno, null y el
 * aviso dice que cargue la base historica: no hay nada con que estimar.
 *
 * UN RESUMEN SIN IMPORTE EN UNA MONEDA CUENTA COMO CERO EN ESA MONEDA, y eso si es
 * un dato: el resumen existe y no tuvo consumos en dolares ese mes. Es distinto de
 * que el resumen falte.
 *
 * LA INFLACION AJUSTA SOLO EL COMPONENTE EN PESOS
 * -----------------------------------------------
 * Y ESA ES LA DECISION MAS IMPORTANTE DE ESTA CLASE. El componente en U$S se
 * convierte con dolar futuro, y la curva de dolar futuro YA INCORPORA la
 * devaluacion esperada: ajustarlo ademas por inflacion contaria dos veces el mismo
 * efecto.
 *
 * Con los numeros de hoy no es un matiz: la curva va de 1521,5 (sep-26) a 1857,5
 * (ago-27), o sea +22,1 % en once meses, y la inflacion cargada al 2 % mensual
 * compone +24,0 % en los mismos once meses. Aplicar las dos multiplicaria el
 * componente en dolares por 1,51 en vez de por 1,22.
 *
 * El componente en pesos no tiene esa correccion por ningun lado, asi que lo ajusta
 * la inflacion, con la MISMA funcion que Gastos Supervisoras
 * (Inflacion::compuesta()).
 *
 * CON QUE DOLAR SE CONVIERTE, Y POR QUE SON DOS REGLAS
 * ----------------------------------------------------
 *   EL PROXIMO VENCIMIENTO  -> dolar oficial BCRA, ultima cotizacion conocida.
 *                              Es un pago inminente: el tipo de cambio con el que
 *                              se va a liquidar es basicamente el de hoy, y la
 *                              curva de futuros del mes en curso ya incorpora
 *                              expectativa que para dentro de dos semanas no se
 *                              cumple.
 *
 *   LOS SIGUIENTES          -> dolar futuro del mes de vencimiento. Ahi si hay
 *                              tiempo para que la devaluacion esperada ocurra, y
 *                              valuar a dolar de hoy proyectaria de menos.
 *
 * LA MISMA REGLA VALE PARA UN RESUMEN REAL CARGADO: su componente en dolares se
 * convierte igual, porque la pregunta es la misma -a cuanto se va a liquidar- y no
 * depende de si el importe es estimado o real.
 *
 * SIEMPRE SE DICE QUE DOLAR SE USO. Un importe en pesos que salio de una
 * conversion y no dice con que se convirtio no se puede verificar contra nada. Va
 * en el tooltip de la celda y la regla completa en el cartel de la pestana.
 *
 * SIN COTIZACION, null Y AVISO, NUNCA CERO
 * ----------------------------------------
 * Un cero se leeria como "ese mes no hay que pagar dolares", que es lo contrario
 * de lo que pasa. Es el mismo criterio de Cotizacion, de DolarFuturo y de
 * Inflacion.
 */
class TarjetasSocios {

    /** Cuantos resumenes forman la base de la estimacion */
    const RESUMENES_BASE = 3;

    /* Por que una tarjeta no estima */
    const OK = 'OK';
    const SIN_BASE = 'SIN_BASE';
    const BASE_INCOMPLETA = 'BASE_INCOMPLETA';

    /* Por que un mes queda sin importe */
    const SIN_INFLACION = 'SIN_INFLACION';
    const SIN_COTIZACION = 'SIN_COTIZACION';

    /* Con que dolar se convirtio */
    const TC_BCRA = 'BCRA';
    const TC_FUTURO = 'FUTURO';

    /* ====================================================================
       LA BASE
       ==================================================================== */

    /**
     * Los ultimos tres resumenes de una tarjeta, y el promedio de cada moneda.
     *
     * SOLO LOS DE PERIODO ANTERIOR AL MES EN CURSO. Ver el encabezado: el resumen
     * del mes en curso puede estar cargado y no vencido, y usarlo como base seria
     * estimar el mes con su propio dato.
     *
     * SE DIVIDE POR LOS QUE HAY, no por tres. Ver el encabezado: con dos
     * resumenes, el tercero no es un mes sin consumo, es un resumen que no se
     * cargo.
     *
     * Estatica y pura.
     *
     * @param array $resumenes Mapa 'Y-m' => resumen vigente de la tarjeta
     * @param string $mesActual 'Y-m'
     * @return array ['resumenes' => [...], 'mes_base' => 'Y-m'|null,
     *                'cantidad' => int, 'promedio_ars' => float|null,
     *                'promedio_usd' => float|null, 'motivo' => string]
     */
    public static function base($resumenes, $mesActual) {
        $candidatos = [];

        foreach (is_array($resumenes) ? $resumenes : [] as $mes => $r) {
            if ($mes < $mesActual) {
                $candidatos[$mes] = $r;
            }
        }

        /* Del mas nuevo al mas viejo, y se toman los primeros tres: la base es
           "los ultimos tres", no "tres cualesquiera". */
        krsort($candidatos);
        $base = array_slice($candidatos, 0, self::RESUMENES_BASE, true);
        $cantidad = count($base);

        if ($cantidad === 0) {
            return ['resumenes' => [], 'mes_base' => null, 'cantidad' => 0,
                    'promedio_ars' => null, 'promedio_usd' => null,
                    'motivo' => self::SIN_BASE];
        }

        $ars = 0.0;
        $usd = 0.0;

        foreach ($base as $r) {
            /* UN RESUMEN SIN IMPORTE EN UNA MONEDA CUENTA COMO CERO EN ESA
               MONEDA. El resumen existe y no tuvo consumos en dolares ese mes:
               eso es un dato, y es distinto de que el resumen falte. */
            $ars += isset($r['IMPORTE_ARS']) ? floatval($r['IMPORTE_ARS']) : 0.0;
            $usd += isset($r['IMPORTE_USD']) ? floatval($r['IMPORTE_USD']) : 0.0;
        }

        $meses = array_keys($base);

        return [
            'resumenes' => $base,

            /* EL MES BASE ES EL DEL RESUMEN MAS RECIENTE de los que forman la
               base: el promedio esta en moneda de ese mes, asi que la inflacion se
               compone desde el siguiente. */
            'mes_base' => $meses[0],

            'cantidad' => $cantidad,

            /* NO SE REDONDEA. El redondeo es presentacion, y redondear aca lo
               arrastraria a los doce meses multiplicado por el factor. */
            'promedio_ars' => $ars / $cantidad,
            'promedio_usd' => $usd / $cantidad,

            'motivo' => ($cantidad < self::RESUMENES_BASE)
                ? self::BASE_INCOMPLETA : self::OK
        ];
    }

    /**
     * El aviso de una base incompleta o ausente.
     *
     * Estatica y pura.
     *
     * @param array $base Lo que devolvio base()
     * @param string $rotulo Como se nombra la tarjeta
     * @return string El aviso, o '' si la base esta completa
     */
    public static function avisoBase($base, $rotulo) {
        if ($base['motivo'] === self::SIN_BASE) {
            return $rotulo . ': no tiene ningún resumen cargado con período anterior al mes en '
                . 'curso, así que no hay con qué estimar. Cargá la base histórica —los últimos '
                . self::RESUMENES_BASE . ' resúmenes— con el botón de su fila. Hasta entonces '
                . 'queda en blanco y NO en cero: un cero se leería como que esa tarjeta no se '
                . 'usa.';
        }

        if ($base['motivo'] === self::BASE_INCOMPLETA) {
            return $rotulo . ': la base son ' . $base['cantidad'] . ' resumen(es) y no '
                . self::RESUMENES_BASE . ', así que el promedio es menos firme. Se divide por '
                . $base['cantidad'] . ' y no por ' . self::RESUMENES_BASE . ': un resumen que '
                . 'falta no es un mes sin consumos, y dividir por 3 proyectaría de menos.';
        }

        return '';
    }

    /* ====================================================================
       LA ESTIMACION
       ==================================================================== */

    /**
     * Lo que se estima para una tarjeta de socio en cada mes del horizonte.
     *
     * CADA MES ES UNA DE DOS COSAS: un resumen cargado -que manda, con su importe
     * y su fecha- o la estimacion, con la cobertura y el ajuste aplicados. Y en
     * los dos casos el componente en dolares se convierte con la misma regla.
     *
     * @param array $tarjeta La tarjeta
     * @param array $base Lo que devolvio base()
     * @param array $meses Los meses del horizonte, 'Y-m'
     * @param array $inflacion Mapa 'Y-m' => float, en puntos
     * @param array $resumenes Mapa 'Y-m' => resumen vigente de la tarjeta
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $curva La curva de dolar futuro
     * @param array|null $bcra Lo que devolvio Cotizacion::ultimaHasta()
     * @param string $hoy 'Y-m-d'
     * @return array ['meses' => ['Y-m' => celda], 'proximo' => ..., 'motivo',
     *                'faltan_inflacion' => [...], 'faltan_calendario' => [...]]
     */
    public static function estimar($tarjeta, $base, $meses, $inflacion, $resumenes,
                                   $habiles, $curva, $bcra, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);

        /* EL PROXIMO PAGO SE RESUELVE UNA VEZ, y es lo que decide con que dolar se
           convierte CADA mes: el proximo va a dolar BCRA y los demas a futuro. La
           misma funcion que usa Tarjetas Pagos Corporativos para reubicar una
           factura vencida, asi que las dos contestan igual. */
        $proximo = TarjetasVencimiento::proximoPago(
            $tarjeta['DIA_VENCIMIENTO'], $resumenes, $meses, $habiles, $hoyStr);

        $factores = ($base['mes_base'] === null)
            ? ['factores' => [], 'faltan' => []]
            : Inflacion::compuestaParaMeses($inflacion, $base['mes_base'], $meses);

        $salida = [
            'meses' => [],
            'proximo' => $proximo,
            'motivo' => $base['motivo'],
            'faltan_inflacion' => [],
            'faltan_calendario' => [],
            'faltan_cotizacion' => []
        ];

        foreach ($meses as $mes) {
            $resumen = isset($resumenes[$mes]) ? $resumenes[$mes] : null;
            $esProximo = ($proximo !== null && $proximo['mes'] === $mes);

            $celda = ($resumen !== null)
                ? self::celdaResumen($resumen, $mes)
                : self::celdaEstimada($tarjeta, $base, $mes, $factores, $habiles, $hoyStr,
                                      $salida);

            /* LA CONVERSION VA DESPUES Y ES LA MISMA para los dos casos: la
               pregunta -a cuanto se va a liquidar ese dolar- no depende de si el
               importe es estimado o real. */
            $celda = self::convertir($celda, $esProximo, $curva, $bcra);

            if ($celda['motivo'] === self::SIN_COTIZACION) {
                $salida['faltan_cotizacion'][] = $mes;
            }

            $salida['meses'][$mes] = $celda;
        }

        return $salida;
    }

    /**
     * La celda de un mes con resumen cargado.
     *
     * EL RESUMEN MANDA EN LAS TRES COSAS: importe en pesos, importe en dolares y
     * fecha. No lleva cobertura ni ajuste por inflacion: es lo que el banco va a
     * debitar, y corregirlo seria corregir un hecho.
     */
    private static function celdaResumen($resumen, $mes) {
        $pagado = !empty($resumen['PAGADO']);

        return [
            'mes' => $mes,
            'origen' => 'RESUMEN',
            'id_resumen' => $resumen['ID'],
            'fecha' => $resumen['FECHA_VENCIMIENTO'],
            'usd' => isset($resumen['IMPORTE_USD']) ? $resumen['IMPORTE_USD'] : null,
            'ars' => isset($resumen['IMPORTE_ARS']) ? $resumen['IMPORTE_ARS'] : null,
            'factor' => null,
            'pagado' => $pagado,
            'proyecta' => !$pagado,
            'motivo' => $pagado ? 'PAGADO' : self::OK,
            'nota' => $pagado
                ? 'Resumen cargado y marcado como pagado: sale del horizonte, porque esa plata '
                  . 'ya está reflejada en el saldo bancario.'
                : 'Resumen cargado: pisa la estimación de este mes, con su importe y su fecha.'
        ];
    }

    /**
     * La celda de un mes sin resumen: la estimacion.
     *
     * LA COBERTURA SE APLICA A LOS DOS COMPONENTES y la inflacion SOLO AL DE
     * PESOS. Ver el encabezado de la clase.
     *
     * @param array $salida Se modifica: se acumulan los meses que faltan
     */
    private static function celdaEstimada($tarjeta, $base, $mes, $factores, $habiles, $hoy,
                                          &$salida) {
        $vto = TarjetasVencimiento::delMes($tarjeta['DIA_VENCIMIENTO'], $mes, $habiles);

        foreach ($vto['faltan'] as $m) {
            if (!in_array($m, $salida['faltan_calendario'], true)) {
                $salida['faltan_calendario'][] = $m;
            }
        }

        $celda = [
            'mes' => $mes,
            'origen' => 'ESTIMACION',
            'id_resumen' => null,
            'fecha' => $vto['fecha'],
            'usd' => null,
            'ars' => null,
            'factor' => null,
            'pagado' => false,
            'proyecta' => false,
            'motivo' => $base['motivo'],
            'nota' => TarjetasVencimiento::explicar($vto)
        ];

        if ($base['motivo'] === self::SIN_BASE) {
            return $celda;
        }

        $cobertura = 1 + floatval($tarjeta['PCT_COBERTURA']) / 100;

        /* EL COMPONENTE EN DOLARES NO LLEVA INFLACION. Su conversion con dolar
           futuro ya incorpora la devaluacion esperada, y ajustarlo ademas contaria
           dos veces el mismo efecto. Ver el encabezado. */
        $celda['usd'] = $base['promedio_usd'] * $cobertura;

        $f = isset($factores['factores'][$mes]) ? $factores['factores'][$mes] : null;

        if ($f === null || $f['factor'] === null) {
            /* SIN INFLACION NO HAY COMPONENTE EN PESOS, y el mes queda en null: el
               de dolares se calcula igual y se informa, porque es correcto y util.
               Mismo criterio que Logistica con SIN_HORAS, donde el valor hora se
               muestra aunque falten las horas. */
            $celda['motivo'] = self::SIN_INFLACION;
            $celda['nota'] = 'Falta la inflación esperada de '
                . (($f !== null && !empty($f['faltan'])) ? implode(', ', $f['faltan'])
                                                         : 'algún mes')
                . ', así que el componente en pesos no se puede llevar a moneda de este mes. '
                . 'Queda en blanco y NO en cero.';

            if ($f !== null) {
                foreach ($f['faltan'] as $m) {
                    if (!in_array($m, $salida['faltan_inflacion'], true)) {
                        $salida['faltan_inflacion'][] = $m;
                    }
                }
            }

            return $celda;
        }

        $celda['factor'] = $f['factor'];
        $celda['ars'] = $base['promedio_ars'] * $cobertura * $f['factor'];

        /* UNA ESTIMACION CUYA FECHA YA PASO no se proyecta: el débito de ese mes ya
           ocurrió y el resumen todavía no se cargó, así que hay un dato que falta. */
        $vencida = ($vto['fecha'] <= $hoy);
        $celda['proyecta'] = !$vencida;
        $celda['motivo'] = $vencida ? 'VENCIDA' : self::OK;

        if ($vencida) {
            $celda['nota'] = 'El vencimiento de este mes ya pasó y todavía no se cargó el '
                . 'resumen, así que no se proyecta: hay un dato que falta.';
        }

        return $celda;
    }

    /**
     * Convierte el componente en dolares a pesos, con la regla de las dos puntas.
     *
     * EL PROXIMO VENCIMIENTO VA A DOLAR BCRA y los siguientes a dolar futuro. Ver
     * el encabezado de la clase.
     *
     * SIN COMPONENTE EN DOLARES NO SE PIDE NINGUNA COTIZACION, y eso importa: una
     * tarjeta que solo tiene consumos en pesos no tiene por que quedar sin
     * proyectar porque falte la curva de futuros.
     *
     * Estatica y pura.
     *
     * @param array $celda
     * @param bool $esProximo Si este mes es el proximo vencimiento
     * @param array $curva La curva de dolar futuro
     * @param array|null $bcra Lo que devolvio Cotizacion::ultimaHasta()
     * @return array La celda con la conversion resuelta
     */
    public static function convertir($celda, $esProximo, $curva, $bcra) {
        $celda['es_proximo'] = $esProximo;
        $celda['tc'] = null;
        $celda['tc_origen'] = null;
        $celda['tc_detalle'] = null;
        $celda['usd_en_pesos'] = null;
        $celda['total_ars'] = $celda['ars'];

        if ($celda['usd'] === null || $celda['usd'] == 0) {
            return $celda;
        }

        if ($esProximo) {
            /* EL PROXIMO VENCIMIENTO: dolar oficial BCRA, ultima cotizacion
               conocida, punta COMPRADORA -la que usa todo el cashflow-. */
            if ($bcra === null || empty($bcra['valor'])) {
                $celda['motivo'] = self::SIN_COTIZACION;
                $celda['total_ars'] = null;
                $celda['proyecta'] = false;
                $celda['nota'] = 'No hay ninguna cotización del dólar oficial del BCRA hasta '
                    . 'hoy, así que el componente en dólares de este vencimiento no se puede '
                    . 'valuar. Queda en blanco y NO en cero.';

                return $celda;
            }

            $celda['tc'] = floatval($bcra['valor']);
            $celda['tc_origen'] = self::TC_BCRA;
            $celda['tc_detalle'] = 'Dólar oficial BCRA del ' . $bcra['fecha'] . ', punta '
                . Cotizacion::nombrePunta($bcra['punta']) . '.';
        } else {
            /* LOS SIGUIENTES: dolar futuro del mes de vencimiento, con la regla del
               mes mas cercano de DolarFuturo::resolver(). */
            $r = DolarFuturo::resolver($curva, $celda['fecha']);

            if ($r['cotizacion'] === null) {
                $celda['motivo'] = self::SIN_COTIZACION;
                $celda['total_ars'] = null;
                $celda['proyecta'] = false;
                $celda['nota'] = 'No hay dólar futuro para ' . ($r['mes_pago'] ?: 'este mes')
                    . ', así que el componente en dólares no se puede valuar. Queda en blanco y '
                    . 'NO en cero.';

                return $celda;
            }

            $celda['tc'] = $r['cotizacion'];
            $celda['tc_origen'] = self::TC_FUTURO;
            $celda['tc_detalle'] = 'Dólar futuro ' . $r['simbolo'] . ' (' . $r['mes_curva'] . ')'
                . (($r['origen'] === DolarFuturo::ORIGEN_APROXIMADA)
                    ? ', APROXIMADO: la curva no llega a ' . $r['mes_pago']
                      . ' y se usa el mes más cercano.'
                    : '.');
        }

        $celda['usd_en_pesos'] = $celda['usd'] * $celda['tc'];

        /* EL TOTAL EN PESOS SOLO EXISTE SI LAS DOS PARTES EXISTEN. Con el
           componente en pesos en null -falta la inflacion- el total tambien es
           null: sumarle cero daria un total mas chico que el real. */
        $celda['total_ars'] = ($celda['ars'] === null)
            ? null : ($celda['ars'] + $celda['usd_en_pesos']);

        return $celda;
    }

    /* ====================================================================
       PARA LA PANTALLA
       ==================================================================== */

    /**
     * El tooltip de una celda: de donde sale el numero.
     *
     * LO ARMA EL BACKEND, por el mismo motivo que los demas textos del modulo.
     * LLEVA SIEMPRE CON QUE DOLAR SE CONVIRTIO: un importe en pesos que salio de
     * una conversion y no dice con que se convirtio no se puede verificar contra
     * nada.
     *
     * Estatica y pura.
     *
     * @param array $celda
     * @param array $base
     * @return string
     */
    public static function explicar($celda, $base) {
        $partes = [];

        if ($celda['origen'] === 'RESUMEN') {
            $partes[] = $celda['nota'];
        } else {
            if ($celda['usd'] !== null || $celda['ars'] !== null) {
                $partes[] = 'Promedio de ' . $base['cantidad'] . ' resumen(es) hasta '
                    . $base['mes_base'] . ': ' . self::plata($base['promedio_ars']) . ' y '
                    . self::dolares($base['promedio_usd']) . ' por mes.';
            }

            if ($celda['factor'] !== null) {
                $partes[] = 'El componente en pesos se ajusta x'
                    . number_format($celda['factor'], 4, ',', '.') . ' por inflación compuesta '
                    . 'desde ' . $base['mes_base'] . '. El de dólares NO se ajusta: su '
                    . 'conversión con dólar futuro ya incorpora la devaluación esperada, y '
                    . 'ajustarlo también contaría dos veces el mismo efecto.';
            } else {
                $partes[] = $celda['nota'];
            }
        }

        if ($celda['tc'] !== null) {
            $partes[] = self::dolares($celda['usd']) . ' x '
                . number_format($celda['tc'], 2, ',', '.') . ' = '
                . self::plata($celda['usd_en_pesos']) . '. ' . $celda['tc_detalle']
                . ($celda['es_proximo']
                    ? ' Es el próximo vencimiento, así que va a dólar de hoy y no a futuro: un '
                      . 'pago inminente se liquida al tipo de cambio actual.'
                    : '');
        } elseif ($celda['motivo'] === self::SIN_COTIZACION) {
            $partes[] = $celda['nota'];
        }

        return implode(' ', $partes);
    }

    /**
     * El cartel que explica la regla del dolar.
     *
     * Estatica y pura.
     *
     * @param array|null $bcra
     * @param array $curva
     * @return string
     */
    public static function explicarDolar($bcra, $curva) {
        $texto = 'El componente en U$S se convierte a pesos con dos reglas: el PRÓXIMO '
            . 'vencimiento de cada tarjeta va a dólar oficial del BCRA —'
            . ($bcra === null
                ? 'que hoy no se puede leer'
                : number_format($bcra['valor'], 2, ',', '.') . ' del ' . $bcra['fecha']
                  . ', punta ' . Cotizacion::nombrePunta($bcra['punta']))
            . '— porque es un pago inminente y se liquida al tipo de cambio actual; los '
            . 'siguientes van a dólar futuro del mes de su vencimiento';

        if (!empty($curva)) {
            $claves = array_keys($curva);
            $texto .= ', y la curva llega hasta ' . $claves[count($claves) - 1];
        }

        return $texto . '. La inflación ajusta SOLO el componente en pesos: la curva de futuros '
            . 'ya incorpora la devaluación esperada, así que ajustar también el de dólares '
            . 'contaría dos veces el mismo efecto.';
    }

    /** Un importe en pesos */
    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }

    /** Un importe en dolares */
    private static function dolares($n) {
        return ($n === null) ? '—' : ('US$ ' . number_format($n, 2, ',', '.'));
    }
}
