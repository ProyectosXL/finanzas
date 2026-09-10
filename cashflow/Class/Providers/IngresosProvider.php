<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Ingresos.php';

/**
 * IngresosProvider
 * Alimenta el tablero con la cobranza REAL de franquicias.
 *
 * SERIE
 *   COBRANZA -> importe neto de las propuestas de pago vigentes, ubicado en la
 *               fecha de cobro propuesta. Las notas de credito restan.
 *
 * ES COBRANZA REAL, NO PROYECTADA. No se pisa con la serie COBRANZA de
 * VentasProvider: aquella proyecta cobranza de ventas FUTURAS y esta trae
 * cobranza de facturas YA EMITIDAS. Se suman a proposito. El invariante esta
 * enunciado en el encabezado de Class/Ventas.php:
 *   Cobranza total = cobranza real + cobranza sobre ventas estimadas
 *
 * Usa Ingresos::getCobranzasFRTotales(), que resuelve el agregado en una sola
 * consulta. La pestana Cobranzas FR sigue usando getCobranzasFR(), que trae
 * razon social y detalle de comprobante a costa de una consulta por fila: datos
 * que el tablero no muestra.
 */
class IngresosProvider extends CashflowProvider {

    protected function calcular($h) {
        $ingresos = new Ingresos();

        if ($this->codigo() === 'COBRANZAS_MAY') {
            $cobranzaMay = $h->agrupar($ingresos->getCobranzasMayTotales(), 'FECHA', 'IMPORTE');
            $cobranzaMay['moneda_origen'] = 'ARS';

            return [
                'COBRANZA' => $cobranzaMay
            ];
        }

        $real = $h->agrupar($ingresos->getCobranzasFRTotales('real'), 'FECHA', 'IMPORTE');
        $real['moneda_origen'] = 'ARS';

        $proy = $h->agrupar($ingresos->getCobranzasFRTotales('proyectado'), 'FECHA', 'IMPORTE');
        $proy['moneda_origen'] = 'ARS';

        $total = $h->agrupar($ingresos->getCobranzasFRTotales('todos'), 'FECHA', 'IMPORTE');
        $total['moneda_origen'] = 'ARS';

        return [
            'COBRANZA' => $total,
            'COBRANZA_REAL' => $real,
            'COBRANZA_PROYECTADA' => $proy,
            'COBRANZA_TOTAL' => $total
        ];
    }
}
