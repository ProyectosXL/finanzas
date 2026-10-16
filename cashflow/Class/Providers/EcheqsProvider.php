<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Echeqs.php';

/**
 * EcheqsProvider
 * Alimenta las filas "Echeqs en cartera" y "Echeqs depositados" de
 * DISPONIBILIDADES.
 *
 * SERIES
 *   A_COBRAR            los cheques de terceros EN CARTERA (ESTADO = 'C') que
 *                       SI se van a poder cobrar, en la fecha del cheque y por
 *                       el BRUTO. Es la fila "Echeqs en cartera".
 *   A_COBRAR_EXCLUIDOS  los que alguien marco como incobrables, con su motivo,
 *                       en bruto
 *   DEPOSITADOS         los que Tesoreria deposito en Tango (boleta BDE a una
 *                       cuenta bancaria) y todavia se proyectan, en su fecha de
 *                       ACREDITACION y por el NETO de las retenciones del banco.
 *                       Es la fila "Echeqs depositados"
 *   A_COBRAR_TODO       lo que la cartera aporta al flujo: las tres juntas
 *
 * UN SOLO CORTE, DE TRES PARTES, Y CIERRA:
 *
 *     A_COBRAR + A_COBRAR_EXCLUIDOS + DEPOSITADOS = A_COBRAR_TODO
 *
 * CADA CHEQUE ESTA EN UNA SOLA PARTE. Las dos primeras salen de la cartera
 * (ESTADO = 'C') y la tercera de los depositados (ESTADO = 'A'): un cheque no
 * puede estar en los dos estados a la vez. Un excluido que despues se deposita
 * deja la cartera y entra en DEPOSITADOS: la exclusion es de cartera.
 *
 * DONDE ENTRA LA RETENCION. Las dos primeras partes van en bruto y la tercera
 * en neto, asi que A_COBRAR_TODO NO ES EL UNIVERSO EN BRUTO: es lo que la
 * cartera aporta al flujo. La retencion de los depositados viaja como escalar
 * de la serie DEPOSITADOS -'bruto' y 'retencion', junto a 'fuera_horizonte'-,
 * y el universo en bruto se reconstruye con ella:
 *
 *     universo en bruto = A_COBRAR_TODO + DEPOSITADOS['retencion']
 *
 * Los escalares se leen de repartir(), que es donde se audita el corte -y lo
 * que fijan las pruebas-. El motor del tablero los descarta al normalizar la
 * serie (CashflowProvider::normalizar() solo conserva las claves de su
 * contrato): ninguna fila los muestra, y la pestana dice bruto, retencion y
 * neto en su propia tarjeta.
 *
 * POR QUE NO SON SERIES DEPOSITADOS_BRUTO Y DEPOSITADOS_RETENCION. Una serie
 * se puede elegir para una fila del tablero, y DEPOSITADOS_BRUTO al lado de
 * DEPOSITADOS contaria dos veces el mismo cheque; el validador de la estructura
 * no puede expresar "esta va con A_COBRAR pero no con aquella" sin cambiar
 * como entiende los cortes. Como escalares sirven para auditar el corte y no
 * se pueden usar en ninguna fila, que es lo que se pidio.
 *
 * A_COBRAR_TODO CAMBIO DE SIGNIFICADO, Y A PROPOSITO. Era "cobrable + excluido",
 * todo en bruto; ahora suma tambien los depositados, en neto. Ninguna fila lo
 * usa -verificado contra la base: la unica fila de ECHEQS es A_COBRAR- y el
 * validador sigue rechazando que se active al lado de cualquiera de sus tres
 * partes.
 *
 * A_COBRAR SIGNIFICABA "TODA LA CARTERA" Y HOY ES "LA CARTERA COBRABLE Y NO
 * DEPOSITADA". El codigo no cambio a proposito: es el que la fila del tablero
 * ya tiene configurado, asi que la exclusion primero y el deposito despues
 * entraron sin repuntar ninguna fila. Ver los encabezados de
 * sql/cashflow_echeqs_excluir.sql y sql/cashflow_echeqs_deposito.sql.
 *
 * QUE DEPOSITO SE PROYECTA, DONDE Y POR CUANTO LO DECIDE Echeqs::resolverDeposito(),
 * por la misma lectura que usa la pestana -Echeqs::getDepositadosTango()-: se
 * proyecta mientras acredite de MANANA en adelante y nadie lo haya marcado "ya
 * acredito". Lo que acredita hoy ya esta en el saldo de Interbanking -es el
 * corte de Cob. Electronicos- y sale solo, SIN AVISO: es lo esperado. Lo que
 * avisa es un deposito sin fecha o sin neto, que no esta en ninguna fila.
 *
 * LAS SERIES SE ALIMENTAN SOLO DE CHEQUES EN CARTERA Y DEPOSITADOS. La sub-pestana Venta Cobrada Anticipada
 * NO aporta ninguna serie al tablero, y es lo primero que alguien va a querer
 * "arreglar" al ver que esa pantalla mueve plata y el proveedor no la mira.
 *
 * Su efecto es el contrario: RESTAR de la cobranza proyectada de Ventas, porque
 * esa venta ya se cobro por adelantado. Sumarla aca contaria dos veces el mismo
 * cheque:
 *
 *     + importe   esta serie                  (la plata existe, va a entrar)
 *     - importe   serie COBRANZA de Ventas    (la venta que prepago no se cobra
 *                                              de nuevo)
 *     -----------------------------------------------------------------------
 *     = contado una sola vez
 *
 * EL DEPOSITO NO CAMBIA ESO: el neteo va por tilde, no por estado ni por
 * deposito. Un cheque pre-chequeado que se deposita sigue neteando la venta
 * en la fecha del cheque, y entra a "Echeqs depositados" en su acreditacion.
 *
 * COROLARIO QUE VALE LA PENA TENER PRESENTE: tildar o destildar cheques en la
 * segunda sub-pestana NO cambia el total de ninguna de estas series. El tilde
 * de la exclusion mueve importe de A_COBRAR a A_COBRAR_EXCLUIDOS sin cambiar
 * A_COBRAR_TODO; un deposito en Tango lo mueve de A_COBRAR a DEPOSITADOS, y
 * ahi A_COBRAR_TODO baja la retencion y corre la fecha. Ver el encabezado de
 * Class/Echeqs.php.
 *
 * Es cobranza REAL de cheques ya recibidos, asi que no se pisa con la serie
 * COBRANZA de VentasProvider, que proyecta cobranza de ventas futuras.
 */
class EcheqsProvider extends CashflowProvider {

    /**
     * LA SERIE DE "Echeqs en cartera": la cartera que se va a poder cobrar y
     * todavia no se deposito. Deja afuera lo excluido a mano y lo depositado.
     */
    const SERIE_COBRABLE = 'A_COBRAR';

    /**
     * LOS CHEQUES EXCLUIDOS A MANO, uno por uno.
     *
     * Existe como serie propia -y no como un importe que simplemente se
     * descuenta- por el mismo criterio que
     * ProveedoresProvider::SERIE_EXCLUIDOS_FACTURA: el importe no desaparece,
     * queda visible y auditable, y las partes siguen cerrando contra el
     * universo.
     */
    const SERIE_EXCLUIDOS = 'A_COBRAR_EXCLUIDOS';

    /**
     * LA SERIE DE "Echeqs depositados": el NETO de los depositos que se
     * proyectan, por fecha de acreditacion. Trae ademas los escalares 'bruto'
     * y 'retencion', que son los que reconstruyen el universo en bruto.
     */
    const SERIE_DEPOSITADOS = 'DEPOSITADOS';

    /**
     * Lo que la cartera aporta al flujo: cobrable y excluido en bruto,
     * depositado en neto. Ver el encabezado.
     */
    const SERIE_TODO = 'A_COBRAR_TODO';

    protected function calcular($h) {
        $echeqs = new Echeqs();

        // getEcheqsCarteraTotales() y no getEcheqsCartera(): el tablero no
        // necesita banco ni cliente, y una consulta agregada no tiene por que
        // recorrer N filas para descartarlas. Trae el corte por excluido
        // adentro del GROUP BY.
        $items = $echeqs->getEcheqsCarteraTotales();

        $this->avisarExcluidos($echeqs, $items);

        // Los depositados, por la MISMA lectura que la pestana: fila por fila,
        // porque la acreditacion y el neto se calculan en PHP con el
        // calendario y las alicuotas. Son pocos -los de las boletas de las
        // ultimas semanas-.
        $dep = $echeqs->getDepositadosTango($h->hoy());

        $this->avisarTodos($dep['avisos']);

        return self::repartir($h, array_merge($items, self::itemsDeDepositados($dep['filas'])));
    }

    /**
     * Las filas de los depositados, como items del reparto: el bruto en
     * IMPORTE y el deposito ya resuelto en DEPOSITO. No se recalcula nada: lo
     * que decidio Echeqs::resolverDeposito() es lo que se reparte.
     *
     * @param array $filas Las de Echeqs::getDepositadosTango()['filas']
     * @return array
     */
    public static function itemsDeDepositados($filas) {
        $items = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            $items[] = ['FECHA_PAGO' => $f['FECHA_PAGO'], 'EXCLUIDO' => false,
                        'IMPORTE' => floatval($f['IMPORTE']), 'DEPOSITO' => $f['DEPOSITO'],
                        'CHEQUES' => 1];
        }

        return $items;
    }

    /**
     * A que series va un item. ES LA REGLA DE REPARTO, escrita una sola vez y
     * sin tocar la base.
     *
     * UN SOLO CORTE, de tres partes: el universo y la parte que le toca. Un
     * deposito que no se proyecta -acredito o le faltan datos- no va a
     * ninguna serie.
     *
     * Estatica y pura por el mismo motivo que ProveedoresProvider::seriesDeItem():
     * asi se puede verificar que el corte cierra sin depender de que haya algo
     * excluido o depositado en la base.
     *
     * @param array $item De la cartera agregada, o de itemsDeDepositados()
     * @return array Codigos de serie
     */
    public static function seriesDeItem($item) {
        if (!empty($item['DEPOSITO'])) {
            return ($item['DEPOSITO']['estado'] === Echeqs::DEPOSITO_PROYECTA)
                ? [self::SERIE_TODO, self::SERIE_DEPOSITADOS] : [];
        }

        return [
            self::SERIE_TODO,
            empty($item['EXCLUIDO']) ? self::SERIE_COBRABLE : self::SERIE_EXCLUIDOS
        ];
    }

    /**
     * Reparte la cartera y los depositados en las cuatro series.
     *
     * ESTATICA Y PUBLICA para poder correrla sobre filas armadas a mano: los
     * casos que importan -toda la cartera excluida, un depositado con fecha
     * fuera del horizonte- no siempre existen en los datos. Ver
     * tests/test_echeqs.php y tests/test_echeqs_deposito.php.
     *
     * DONDE Y CUANTO: uno de cartera, en la fecha del cheque y por el bruto;
     * un depositado que se proyecta, en su acreditacion y por el neto.
     *
     * @param Horizonte $h
     * @param array $items De getEcheqsCarteraTotales() y de itemsDeDepositados()
     * @return array Mapa codigoSerie => serie
     */
    public static function repartir($h, $items) {
        $series = [
            self::SERIE_COBRABLE => self::serieVacia($h),
            self::SERIE_EXCLUIDOS => self::serieVacia($h),
            self::SERIE_DEPOSITADOS => self::serieVacia($h),
            self::SERIE_TODO => self::serieVacia($h)
        ];

        $dep = &$series[self::SERIE_DEPOSITADOS];
        $dep['bruto'] = 0;
        $dep['retencion'] = 0;

        foreach ($items as $item) {
            $destinos = self::seriesDeItem($item);

            if (empty($destinos)) {
                continue;
            }

            $deposito = !empty($item['DEPOSITO']);
            $importe = floatval($deposito ? $item['DEPOSITO']['neto'] : $item['IMPORTE']);
            $fecha = $deposito ? $item['DEPOSITO']['fecha_acreditacion'] : $item['FECHA_PAGO'];

            if ($deposito) {
                $dep['bruto'] += $item['DEPOSITO']['bruto'];
                $dep['retencion'] += $item['DEPOSITO']['bruto'] - $item['DEPOSITO']['neto'];
            }

            if ($importe == 0) {
                continue;
            }

            foreach ($destinos as $destino) {
                // Lo que cae fuera del eje se informa, no se descarta en
                // silencio. Va en el universo y, si es un deposito, tambien en
                // DEPOSITADOS: el tablero avisa leyendo la serie de la fila, y
                // "Echeqs depositados" tiene que decirlo. En las otras dos
                // partes no, por el criterio de siempre: el aviso saldria
                // repetido.
                if (!$h->acumular($series[$destino], $fecha, $importe)) {
                    if ($destino === self::SERIE_TODO || $destino === self::SERIE_DEPOSITADOS) {
                        if ($fecha === null) {
                            $series[$destino]['sin_fecha'] += $importe;
                        } else {
                            $series[$destino]['fuera_horizonte'] += $importe;
                        }
                    }
                }
            }
        }

        unset($dep);

        $series[self::SERIE_DEPOSITADOS]['retencion'] =
            round($series[self::SERIE_DEPOSITADOS]['retencion'], 4);

        return $series;
    }

    /**
     * Avisa cuanta cartera se saco del cashflow tildandola cheque por cheque.
     *
     * ES PLATA QUE EL TABLERO DEJA DE MOSTRAR POR UNA DECISION, y por eso se
     * avisa: el modulo entero esta construido sobre que nada desaparezca sin
     * decir por que. Un tilde puesto en marzo que nadie recuerda es exactamente
     * lo que este aviso evita. Es el mismo criterio -y casi el mismo texto- que
     * ProveedoresProvider::avisarExcluidasAMano().
     *
     * SE NOMBRAN LOS MOTIVOS, hasta tres. El motivo es obligatorio al excluir, y
     * sin traerlo hasta aca el aviso diria cuanta plata falta pero no por que,
     * que obliga a abrir la pestana igual.
     *
     * EL CONTEO Y LOS MOTIVOS SE PIDEN APARTE, con getExclusionesEnCartera(),
     * porque la consulta agregada no los puede dar: abre por SI esta excluido y
     * devuelve una fila por FECHA, asi que contarla diria "3 fechas" donde hay
     * doce cheques. Solo se pide si hay algo excluido.
     *
     * @param Echeqs $echeqs
     * @param array $items Filas de getEcheqsCarteraTotales()
     */
    private function avisarExcluidos($echeqs, $items) {
        $total = 0;

        foreach ($items as $item) {
            if (!empty($item['EXCLUIDO'])) {
                $total += floatval($item['IMPORTE']);
            }
        }

        if ($total == 0) {
            return;
        }

        $r = $echeqs->getExclusionesEnCartera();
        $motivos = $r['motivos'];
        $detalle = '';

        if (!empty($motivos)) {
            $primeros = array_slice($motivos, 0, 3);
            $detalle = ' Motivos: ' . implode('; ', $primeros)
                . (count($motivos) > 3 ? '; y ' . (count($motivos) - 3) . ' más.' : '.');
        }

        // Informativo: excluirlos fue una decision, y el aviso no pide nada.
        $this->avisar($r['cheques'] . ' cheque(s) por $ '
            . number_format($total, 2, ',', '.') . ' están excluidos a mano y no entran al '
            . 'cashflow.' . $detalle . ' Se ven en la pestaña, tildando "Ver excluidos".', Aviso::INFO);
    }

    /** Una serie vacia con los escalares en su valor por defecto */
    private static function serieVacia($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        return $serie;
    }
}
