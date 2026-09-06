<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Ventas.php';

/**
 * VentasProvider
 * Alimenta el tablero de Cashflow con las series del modulo de Ventas.
 *
 * SERIES
 *   COBRANZA -> la caja: cobranza estimada sobre ventas futuras, ya con el mix
 *               de medios de pago, los plazos de acreditacion y el corrimiento
 *               a dia bancario habil aplicados. Neta del neteo de cheques
 *               adelantados.
 *   VENTA    -> la venta proyectada con IVA. NO es caja: se muestra como fila
 *               informativa (COMPUTA=0 en la configuracion) para poder leer el
 *               cuadro contra el Excel sin contarla dos veces.
 *
 * UNA SOLA PASADA
 * Ventas::proyectarCobranzas() resuelve venta y cobranza en el mismo recorrido,
 * asi que las dos series salen de una unica llamada. Es un calculo caro (lee el
 * historico, los indices, la participacion y el calendario bancario, y recorre
 * dia x canal x medio de pago), y por eso el motor pide las series de un
 * proveedor una sola vez por pedido y no una vez por fila.
 *
 * Se le inyecta el eje del tablero para que la serie caiga exactamente en las
 * mismas columnas sobre las que consolida el resto del Cashflow.
 *
 * NO se cruza con COBRANZAS_FR: ese modulo trae cobranza REAL de facturas ya
 * emitidas y este proyecta cobranza de ventas FUTURAS. Se suman a proposito.
 * El invariante esta enunciado en el encabezado de Class/Ventas.php:
 *   Cobranza total = cobranza real + cobranza sobre ventas estimadas
 */
class VentasProvider extends CashflowProvider {

    protected function calcular($h) {
        $ventas = new Ventas();

        // El eje se inyecta: ver la nota del encabezado.
        $p = $ventas->proyectarCobranzas($h);

        // Los avisos del motor de Ventas (por ejemplo una fecha que falta en el
        // calendario bancario) tienen que llegar al tablero: si se perdieran,
        // el usuario veria numeros sin saber que se estimaron.
        if (!empty($p['warnings'])) {
            foreach ($p['warnings'] as $w) {
                $this->avisar('Ventas: ' . $w);
            }
        }

        return [
            'COBRANZA' => $this->cobranzaNeta($p),
            'VENTA' => [
                'dias' => $p['venta']['total_dias'],
                'meses' => $p['venta']['total_meses']
            ]
        ];
    }

    /**
     * Cobranza menos el neteo de cheques adelantados.
     *
     * El neteo hoy devuelve siempre cero porque su vista origen todavia no
     * existe (ver Ventas::getNeteoPrechequeado). Se resta igual, cableado, para
     * que el dia que se enchufe el origen el tablero quede correcto solo, sin
     * tener que acordarse de tocar esto.
     *
     * @param array $p Payload de Ventas::proyectarCobranzas()
     * @return array ['dias' => [...], 'meses' => [...]]
     */
    private function cobranzaNeta($p) {
        $dias  = $p['cobranza']['total_dias'];
        $meses = $p['cobranza']['total_meses'];

        $neteo = isset($p['cobranza']['neteo_prechequeado'])
            ? $p['cobranza']['neteo_prechequeado']
            : ['dias' => [], 'meses' => []];

        foreach ($dias as $clave => $monto) {
            if (isset($neteo['dias'][$clave])) {
                $dias[$clave] = $monto - $neteo['dias'][$clave];
            }
        }

        foreach ($meses as $clave => $monto) {
            if (isset($neteo['meses'][$clave])) {
                $meses[$clave] = $monto - $neteo['meses'][$clave];
            }
        }

        return ['dias' => $dias, 'meses' => $meses];
    }
}
