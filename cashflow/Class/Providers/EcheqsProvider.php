<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Echeqs.php';

/**
 * EcheqsProvider
 * Alimenta la fila "Echeqs en cartera" de DISPONIBILIDADES.
 *
 * SERIES
 *   A_COBRAR            los cheques de terceros EN CARTERA (ESTADO = 'C') que
 *                       SI se van a poder cobrar. Es la que usa la fila.
 *   A_COBRAR_EXCLUIDOS  los que alguien marco como incobrables, con su motivo
 *   A_COBRAR_TODO       el universo: los dos juntos
 *
 * UN SOLO CORTE, Y CIERRA:
 *
 *     A_COBRAR + A_COBRAR_EXCLUIDOS = A_COBRAR_TODO
 *
 * A_COBRAR SIGNIFICABA "TODA LA CARTERA" Y AHORA SIGNIFICA "LA CARTERA
 * COBRABLE". El codigo no cambio a proposito: es el que la fila del tablero ya
 * tiene configurado, asi que el circuito de exclusion entro sin repuntar
 * ninguna fila ni tocar Parametros. Lo que era A_COBRAR hasta entonces es hoy
 * A_COBRAR_TODO, y mientras no haya ningun cheque excluido los dos valen lo
 * mismo. Ver el encabezado de sql/cashflow_echeqs_excluir.sql.
 *
 * LA SERIE SE ALIMENTA SOLO DE CARTERA. La sub-pestana Venta Cobrada Anticipada
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
 * COROLARIO QUE VALE LA PENA TENER PRESENTE: tildar o destildar cheques en la
 * segunda sub-pestana NO cambia el total de ninguna de estas tres series. El
 * tilde de la exclusion, en cambio, mueve importe de A_COBRAR a
 * A_COBRAR_EXCLUIDOS sin cambiar A_COBRAR_TODO. Son dos tilde distintos sobre
 * dos tablas distintas: ver el encabezado de Class/Echeqs.php.
 *
 * Es cobranza REAL de cheques ya recibidos, asi que no se pisa con la serie
 * COBRANZA de VentasProvider, que proyecta cobranza de ventas futuras.
 */
class EcheqsProvider extends CashflowProvider {

    /**
     * LA SERIE QUE USA LA FILA DEL TABLERO: la cartera que se va a poder
     * cobrar. Deja afuera lo excluido a mano, que es lo que hace que el tilde
     * saque el importe del cuadro.
     */
    const SERIE_COBRABLE = 'A_COBRAR';

    /**
     * LOS CHEQUES EXCLUIDOS A MANO, uno por uno.
     *
     * Existe como serie propia -y no como un importe que simplemente se
     * descuenta- por el mismo criterio que
     * ProveedoresProvider::SERIE_EXCLUIDOS_FACTURA: el importe no desaparece,
     * queda visible y auditable, y las dos partes siguen cerrando contra el
     * universo.
     */
    const SERIE_EXCLUIDOS = 'A_COBRAR_EXCLUIDOS';

    /** El universo: toda la cartera, excluida o no. Es lo que A_COBRAR era */
    const SERIE_TODO = 'A_COBRAR_TODO';

    protected function calcular($h) {
        $echeqs = new Echeqs();

        // getEcheqsCarteraTotales() y no getEcheqsCartera(): el tablero no
        // necesita banco ni cliente, y una consulta agregada no tiene por que
        // recorrer N filas para descartarlas. Trae el corte por excluido
        // adentro del GROUP BY, asi que las tres series salen de UNA lectura y
        // cierran por construccion.
        $items = $echeqs->getEcheqsCarteraTotales();

        $this->avisarExcluidos($echeqs, $items);

        return self::repartir($h, $items);
    }

    /**
     * A que series va un vencimiento de cartera. ES LA REGLA DE REPARTO,
     * escrita una sola vez y sin tocar la base.
     *
     * UN SOLO CORTE, de dos partes: el universo y la mitad que le toca. Es la
     * version chica de ProveedoresProvider::seriesDeItem(), y va estatica y
     * pura por el mismo motivo: asi se puede verificar que el corte cierra sin
     * depender de que haya algo excluido en la base.
     *
     * @param array $item Una fila de Echeqs::getEcheqsCarteraTotales()
     * @return array Codigos de serie
     */
    public static function seriesDeItem($item) {
        return [
            self::SERIE_TODO,
            empty($item['EXCLUIDO']) ? self::SERIE_COBRABLE : self::SERIE_EXCLUIDOS
        ];
    }

    /**
     * Reparte la cartera en las tres series.
     *
     * ESTATICA Y PUBLICA para poder correrla sobre filas armadas a mano: los
     * casos que importan -toda la cartera excluida, un excluido con fecha
     * fuera del horizonte- hoy no existen en los datos y van a existir en
     * cuanto alguien tilde. Ver tests/test_echeqs.php.
     *
     * @param Horizonte $h
     * @param array $items Filas de Echeqs::getEcheqsCarteraTotales()
     * @return array Mapa codigoSerie => serie
     */
    public static function repartir($h, $items) {
        $series = [
            self::SERIE_COBRABLE => self::serieVacia($h),
            self::SERIE_EXCLUIDOS => self::serieVacia($h),
            self::SERIE_TODO => self::serieVacia($h)
        ];

        foreach ($items as $item) {
            $importe = floatval($item['IMPORTE']);

            if ($importe == 0) {
                continue;
            }

            $destinos = self::seriesDeItem($item);

            foreach ($destinos as $destino) {
                // Lo que cae fuera del eje se informa, no se descarta en
                // silencio. Solo se cuenta una vez, en el universo: las otras
                // dos informarian lo mismo y el aviso saldria repetido.
                if (!$h->acumular($series[$destino], $item['FECHA_PAGO'], $importe)) {
                    if ($destino === self::SERIE_TODO) {
                        if ($item['FECHA_PAGO'] === null) {
                            $series[$destino]['sin_fecha'] += $importe;
                        } else {
                            $series[$destino]['fuera_horizonte'] += $importe;
                        }
                    }
                }
            }
        }

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

        $this->avisar('Echeqs en cartera: ' . $r['cheques'] . ' cheque(s) por $ '
            . number_format($total, 2, ',', '.') . ' están excluidos a mano y no entran al '
            . 'cashflow.' . $detalle . ' Se ven en la pestaña, tildando "Ver excluidos".');
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
