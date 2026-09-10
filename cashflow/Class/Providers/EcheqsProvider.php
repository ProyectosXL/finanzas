<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Echeqs.php';

/**
 * EcheqsProvider
 * Alimenta la fila "Echeqs en cartera" de DISPONIBILIDADES.
 *
 * SERIE
 *   A_COBRAR -> importe de los cheques de terceros EN CARTERA (ESTADO = 'C'),
 *               ubicado en su fecha de pago.
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
 * segunda sub-pestana NO cambia el total de esta serie. Si alguna vez cambia, o
 * la serie dejo de salir de cartera o alguien la conecto al maestro.
 *
 * Es cobranza REAL de cheques ya recibidos, asi que no se pisa con la serie
 * COBRANZA de VentasProvider, que proyecta cobranza de ventas futuras.
 */
class EcheqsProvider extends CashflowProvider {

    protected function calcular($h) {
        $echeqs = new Echeqs();

        // getEcheqsCarteraTotales() y no getEcheqsCartera(): el tablero no
        // necesita banco ni cliente, y una consulta agregada no tiene por que
        // recorrer N filas para descartarlas.
        $serie = $h->agrupar($echeqs->getEcheqsCarteraTotales(), 'FECHA_PAGO', 'IMPORTE');

        $serie['moneda_origen'] = 'ARS';

        return ['A_COBRAR' => $serie];
    }
}
