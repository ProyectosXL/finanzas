<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * Retenciones
 * La tasa de retencion de una acreditacion: un juego de alicuotas por concepto,
 * con vigencias, que se suman a la fecha en que la plata entra.
 *
 *     importe neto = importe bruto * (1 - tasa de retencion vigente)
 *
 * POR QUE EXISTE
 * --------------
 * Todo esto nacio en CobElectronicos, que fue el primer modulo con retenciones:
 * las procesadoras de pago acreditan el bruto menos IIBB y SICREB. Cuando los
 * cheques depositados pasaron a acreditar tambien un neto -el banco retiene
 * sobre el deposito-, la regla era la misma y la alternativa era copiarla. Dos
 * copias de la formula del neto son dos formulas: el dia que una cambia, el
 * neto de los cheques y el de Payway se calculan distinto sin que ninguna
 * pantalla lo note.
 *
 * QUE SE COMPARTE Y QUE NO
 * ------------------------
 * Se comparte la MECANICA: que alicuota rige a que fecha, como se suman, como
 * se valida una nueva, desde cuando algo es pendiente y que pendientes cambian
 * si se recalculan. NO se comparten los DATOS: cada modulo tiene su tabla de
 * alicuotas, y por eso un cambio en la tasa de Payway no puede mover el neto de
 * un cheque ni al reves. Los helpers reciben las filas ya leidas y no saben de
 * donde salieron.
 *
 * LOS METODOS DE CobElectronicos NO CAMBIARON: siguen existiendo con el mismo
 * nombre y el mismo contrato, y delegan aca. Es el mismo criterio con el que se
 * extrajo Class/Planilla.php: quien los usa -incluidas sus pruebas, que son la
 * red de este refactor- no tiene por que enterarse de donde vive la regla.
 *
 * Estatica y pura, como DiasHabiles: no toca la base, y la fecha de hoy se
 * inyecta.
 */
class Retenciones {

    /**
     * Tolerancia para comparar dos tasas.
     * Las columnas son DECIMAL(9,6) y los valores dan la vuelta por JSON y por
     * un input numerico: una comparacion estricta reportaria cambios que no
     * existen. Es el mismo criterio que Saldos::resolverOverrides() con la
     * reserva.
     */
    const TOLERANCIA_TASA = 0.0000005;

    /** Largo maximo del concepto, el de la columna en las dos tablas */
    const CONCEPTO_MAX = 30;

    /**
     * La alicuota vigente de CADA concepto a una fecha.
     *
     * De cada CONCEPTO se toma la ultima vigencia con VIGENCIA_DESDE <= la
     * fecha. Una vigencia POSTERIOR no se toma: es un porcentaje que todavia no
     * rige, y usarlo seria calcular una acreditacion con una tasa que no
     * existia cuando entro la plata.
     *
     * EL DESEMPATE POR ID NO ES OPCIONAL. Nada impide corregir dos veces el
     * mismo porcentaje el mismo dia -es justo lo que pasa cuando alguien se
     * equivoca al tipearlo-, y dos vigencias de la misma fecha empatan. El ID es
     * un IDENTITY, asi que el mayor es siempre el insertado despues. Por eso dos
     * vigencias del mismo concepto y la misma fecha no se bloquean: no se pisan,
     * gana la ultima. Es la misma regla que Saldos::ultimaCarga().
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
     */
    private static function vigenciaPosterior($a, $b) {
        if ($a['vigencia_desde'] !== $b['vigencia_desde']) {
            return $a['vigencia_desde'] > $b['vigencia_desde'];
        }

        return $a['id'] > $b['id'];
    }

    /**
     * Tasa de retencion de un juego de alicuotas a una fecha: la SUMA de las
     * alicuotas vigentes de cada concepto.
     *
     * Devuelve tambien la cantidad de conceptos, porque cero conceptos NO es
     * tasa cero: es "no hay alicuotas vigentes a esta fecha", y con eso el alta
     * se rechaza en lugar de guardar un neto igual al bruto. Confundir las dos
     * cosas es exactamente la causa de que D6, D7 y D24 esten vacias en el
     * Excel de Cob. Electronicos.
     *
     * @param array $alicuotas Filas de UN juego de alicuotas
     * @param string $fecha 'Y-m-d' de acreditacion
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
     * El neto de una acreditacion. ES LA UNICA IMPLEMENTACION DE ESTA FORMULA
     * en todo el modulo: la usan Cob. Electronicos y el deposito de cheques.
     *
     * @param float $bruto
     * @param float $tasa Suma de las alicuotas vigentes, entre 0 y 1
     * @return float Redondeado a los 4 decimales de la columna
     */
    public static function importeNeto($bruto, $tasa) {
        return round(floatval($bruto) * (1 - floatval($tasa)), 4);
    }

    /**
     * El desglose de una tasa en una linea: "IIBB 2,5% + SICREB 0,6%".
     *
     * Es lo que se guarda junto a la tasa de un deposito y lo que muestran los
     * tooltips: una tasa sin desglose no se puede auditar contra la vigencia
     * que la explica.
     *
     * @param array $detalle El 'detalle' de tasaRetencion()
     * @return string
     */
    public static function desglose($detalle) {
        $partes = [];

        foreach (is_array($detalle) ? $detalle : [] as $v) {
            $partes[] = $v['concepto'] . ' ' . self::porcentaje($v['alicuota']);
        }

        return implode(' + ', $partes);
    }

    /**
     * Resuelve la tasa con la que hay que calcular una acreditacion, o LANZA
     * con el motivo.
     *
     * Es la puerta por la que pasa todo alta: no se puede guardar una
     * acreditacion sin alicuotas vigentes a su fecha, porque quedaria sin neto.
     *
     * LOS TEXTOS LOS PONE QUIEN LLAMA, porque cada modulo tiene que decir de
     * quien son las alicuotas y donde se cargan: "Payway" en Cob. Electronicos,
     * los cheques en Echeqs. La regla es una sola.
     *
     * @param array $alicuotas Filas del juego de alicuotas
     * @param string $fecha 'Y-m-d' de acreditacion
     * @param array $textos ['sin_vigentes' => '"Payway" no tiene',
     *        'de' => 'de "Payway"', 'que' => 'del movimiento',
     *        'donde' => 'Parámetros → Cob. Electrónicos']
     * @return array ['tasa' => float, 'conceptos' => int, 'detalle' => mapa]
     */
    public static function resolverTasa($alicuotas, $fecha, $textos) {
        $r = self::tasaRetencion($alicuotas, $fecha);

        if ($r['conceptos'] === 0) {
            throw new Exception($textos['sin_vigentes'] . ' ninguna alícuota vigente al '
                . self::fechaCorta($fecha) . ', así que no se puede calcular el importe neto '
                . $textos['que'] . '. Cargá sus alícuotas en ' . $textos['donde']
                . ' con una vigencia igual o anterior a esa fecha.');
        }

        if ($r['tasa'] >= 1) {
            throw new Exception('Las alícuotas vigentes ' . $textos['de'] . ' al '
                . self::fechaCorta($fecha) . ' suman ' . self::porcentaje($r['tasa'])
                . ', así que el neto saldría cero o negativo. Corregilas en '
                . $textos['donde'] . '.');
        }

        return $r;
    }

    /**
     * Valida una alicuota antes de guardarla. LANZA con el motivo.
     *
     * LA SUMA DE LAS VIGENTES TIENE QUE SER MENOR A 1, y se valida AL GUARDAR LA
     * ALICUOTA, no al usarla: el momento de frenar una suma imposible es cuando
     * alguien la escribe, no cuando ya hay acreditaciones mal calculadas.
     *
     * La suma se calcula sobre el ESTADO RESULTANTE -las otras vigentes mas la
     * nueva-, no sobre lo que llega del cliente: es el mismo criterio que usa
     * ParametrosController con el mix de cobro.
     *
     * @param array $alicuotas Filas del juego de alicuotas, las de ahora
     * @param string $concepto Concepto que se esta guardando
     * @param float $nueva Alicuota nueva
     * @param string $vigenciaDesde 'Y-m-d' desde la que rige
     * @return float Tasa total resultante
     */
    public static function validarAlicuota($alicuotas, $concepto, $nueva, $vigenciaDesde) {
        $concepto = trim((string) $concepto);

        if ($concepto === '') {
            throw new Exception('La alícuota necesita un concepto (por ejemplo IIBB o SICREB)');
        }

        if (mb_strlen($concepto) > self::CONCEPTO_MAX) {
            throw new Exception('El concepto no puede superar los ' . self::CONCEPTO_MAX
                . ' caracteres');
        }

        $nueva = floatval($nueva);

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
     * Desde que fecha una acreditacion cuenta como PENDIENTE: MANANA.
     *
     * HOY NO ES PENDIENTE. Lo que se acredita hoy ya esta -o va a estar al
     * cierre- en el saldo bancario que informa la pestana Saldos, asi que
     * sumarlo tambien como cobranza lo contaria dos veces. Vale igual para una
     * procesadora que para un cheque depositado: es plata que entra a la misma
     * cuenta y que el mismo saldo ya informa.
     *
     * Y ES MANANA A SECAS, NO EL PROXIMO DIA HABIL: puede haber acreditaciones
     * cualquier dia -una billetera acredita un sabado-, asi que un sabado visto
     * un viernes ES pendiente.
     *
     * ES UN SOLO CORTE PARA LOS DOS MODULOS a proposito. Si Cob. Electronicos y
     * Echeqs cortaran distinto, la misma acreditacion de hoy se contaria en una
     * fila del tablero y no en la otra, y el saldo bancario la contaria por
     * tercera vez o por ninguna.
     *
     * @param string $hoy 'Y-m-d' del dia de referencia
     * @return string 'Y-m-d'
     */
    public static function cortePendientes($hoy) {
        $hoy = Horizonte::normalizarFecha($hoy);

        if ($hoy === null) {
            $hoy = date('Y-m-d');
        }

        return date('Y-m-d', strtotime($hoy . ' +1 day'));
    }

    /**
     * Que acreditaciones PENDIENTES cambian si se recalculan con las alicuotas
     * de ahora, y con que diferencia.
     *
     * SOLO LAS PENDIENTES. Una acreditacion anterior al corte -hoy incluido,
     * ver cortePendientes()- ya entro: su plata entro con la tasa que entro, y
     * recalcularla seria reescribir la historia. Es el mismo motivo por el que
     * la tasa se persiste en la fila.
     *
     * Es un helper puro y devuelve un PLAN, no una escritura: asi el mismo
     * calculo sirve para aplicar el recalculo y para decir en la pantalla que
     * fue lo que cambio.
     *
     * CADA ITEM DICE A QUE JUEGO DE ALICUOTAS PERTENECE ('juego'): en Cob.
     * Electronicos es la procesadora, en Echeqs hay uno solo. Lo que no se pudo
     * recalcular por falta de alicuotas vuelve en 'sin_alicuota', por juego,
     * para que cada modulo lo avise con sus palabras.
     *
     * @param array $items Filas con 'id', 'juego', 'fecha_acreditacion',
     *        'importe_bruto', 'tasa_aplicada', 'importe_neto'
     * @param array $alicuotasPorJuego Mapa juego => filas de alicuotas
     * @param string $corte 'Y-m-d' desde el que una acreditacion es pendiente
     * @return array ['cambios', 'diferencia', 'pendientes', 'acreditados',
     *         'sin_alicuota' => [juego => [items]]]. Cada cambio trae el item
     *         original en 'item'
     */
    public static function planRecalculo($items, $alicuotasPorJuego, $corte) {
        $cambios = [];
        $sinAlicuota = [];
        $diferencia = 0;
        $pendientes = 0;
        $acreditados = 0;
        $corte = substr((string) $corte, 0, 10);

        $alicuotasPorJuego = is_array($alicuotasPorJuego) ? $alicuotasPorJuego : [];

        foreach (is_array($items) ? $items : [] as $m) {
            $fecha = Horizonte::normalizarFecha(
                isset($m['fecha_acreditacion']) ? $m['fecha_acreditacion'] : null);

            if ($fecha === null) {
                continue;
            }

            if ($fecha < $corte) {
                $acreditados++;
                continue;
            }

            $pendientes++;

            $juego = isset($m['juego']) ? $m['juego'] : 0;
            $alicuotas = isset($alicuotasPorJuego[$juego]) ? $alicuotasPorJuego[$juego] : [];

            $r = self::tasaRetencion($alicuotas, $fecha);

            // Sin alicuotas vigentes no se puede recalcular. La acreditacion
            // queda como esta -con su tasa vieja- y quien llama avisa: dejarla
            // en cero seria informar de menos, y calcularla sin tasa seria
            // informar el bruto como si fuera neto.
            if ($r['conceptos'] === 0 || $r['tasa'] >= 1) {
                $sinAlicuota[$juego][] = $m;
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
                'fecha_acreditacion' => $fecha,
                'importe_bruto' => floatval(isset($m['importe_bruto']) ? $m['importe_bruto'] : 0),
                'tasa_anterior' => $tasaVieja,
                'tasa_nueva' => $r['tasa'],
                'desglose_nuevo' => self::desglose($r['detalle']),
                'neto_anterior' => $netoViejo,
                'neto_nuevo' => $netoNuevo,
                'diferencia' => round($netoNuevo - $netoViejo, 4),
                'item' => $m
            ];

            $diferencia += ($netoNuevo - $netoViejo);
        }

        return [
            'cambios' => $cambios,
            'diferencia' => round($diferencia, 4),
            'pendientes' => $pendientes,
            'acreditados' => $acreditados,
            'sin_alicuota' => $sinAlicuota
        ];
    }

    /** Una tasa como porcentaje, para los mensajes: 0.031 => '3,1%' */
    public static function porcentaje($tasa) {
        return rtrim(rtrim(number_format(floatval($tasa) * 100, 4, ',', '.'), '0'), ',') . '%';
    }

    /** 'Y-m-d...' => 'd/m/Y', para los mensajes */
    public static function fechaCorta($fecha) {
        if ($fecha === null || $fecha === '') {
            return 'sin fecha';
        }

        return date('d/m/Y', strtotime(substr((string) $fecha, 0, 10)));
    }
}
