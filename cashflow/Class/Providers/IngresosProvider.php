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

        $filasProy = $ingresos->getCobranzasFRTotales('proyectado');

        $proy = $h->agrupar($filasProy, 'FECHA', 'IMPORTE');
        $proy['moneda_origen'] = 'ARS';
        $proy['detalle'] = $this->detallePactado($h, $filasProy);

        $filasTotal = $ingresos->getCobranzasFRTotales('todos');

        $total = $h->agrupar($filasTotal, 'FECHA', 'IMPORTE');
        $total['moneda_origen'] = 'ARS';
        $total['detalle'] = $this->detallePactado($h, $filasTotal);

        return [
            'COBRANZA' => $total,
            'COBRANZA_REAL' => $real,
            'COBRANZA_PROYECTADA' => $proy,
            'COBRANZA_TOTAL' => $total
        ];
    }

    /**
     * Que parte del importe de cada columna tiene la fecha de cobro PACTADA A
     * MANO, en lugar de estimada con el PPP del cliente.
     *
     * POR QUE EL TABLERO TIENE QUE MOSTRARLO
     * Una factura con fecha manual no esta en ninguna propuesta de la app de
     * cobranzas -si lo estuviera, seria cobranza real-, pero su fecha tampoco
     * es una estimacion: Tesoreria la hablo con el cliente y la acordo por
     * fuera. En el tablero los dos casos se ven igual, y no significan lo
     * mismo: uno es un promedio estadistico y el otro es un compromiso
     * conversado. Quien mira el numero para tomar una decision necesita poder
     * distinguirlos.
     *
     * Se agrupa con el MISMO Horizonte::agrupar() que el importe, asi que la
     * parte pactada cae siempre en la misma columna que el total al que anota:
     * repartirla con otra regla la pondria en una celda donde no hay nada que
     * anotar.
     *
     * @param Horizonte $h
     * @param array $filas Filas de Ingresos::getCobranzasFRTotales()
     * @return array Mapa columna => ['importe', 'nota']
     */
    private function detallePactado($h, $filas) {
        $importes = $h->agrupar($filas, 'FECHA', 'IMPORTE_PACTADO');
        $comprobantes = $h->agrupar($filas, 'FECHA', 'COMP_PACTADOS');

        $detalle = [];

        foreach (['dias' => 'DIA|', 'meses' => 'MES|'] as $rama => $prefijo) {
            foreach ($importes[$rama] as $clave => $importe) {
                if ($importe == 0) {
                    continue;
                }

                $cant = intval($comprobantes[$rama][$clave]);

                $detalle[$prefijo . $clave] = [
                    'importe' => $importe,
                    'nota' => self::plata($importe) . ' de esta celda ('
                        . $cant . ' comprobante' . ($cant === 1 ? '' : 's')
                        . ') tienen fecha de cobro PACTADA con el cliente, cargada a mano en '
                        . 'Cobranzas FR. No estan en ninguna propuesta de la app: Tesoreria las '
                        . 'acordo por fuera. El resto de la celda sale del PPP, que es una '
                        . 'estimacion.'
                ];
            }
        }

        return $detalle;
    }

    /** Formato de importe para las notas */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }
}
