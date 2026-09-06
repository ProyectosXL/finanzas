<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Ventas.php';
require_once __DIR__ . '/../Parametros.php';

/**
 * VentasProvider
 * Alimenta el tablero de Cashflow con las series del modulo de Ventas.
 *
 * SERIES
 *   COBRANZA            -> la caja: cobranza estimada sobre ventas futuras, ya
 *                          con el mix de medios de pago, los plazos de
 *                          acreditacion y el corrimiento a dia bancario habil
 *                          aplicados. Neta del neteo de cheques adelantados.
 *   COBRANZA_<CANAL>    -> lo mismo, abierto por canal
 *   VENTA               -> la venta proyectada con IVA. NO es caja: se muestra
 *                          como fila informativa (COMPUTA=0) para poder leer el
 *                          cuadro contra el Excel sin contarla dos veces.
 *   VENTA_<CANAL>       -> lo mismo, abierto por canal
 *
 * OJO CON MEZCLAR TOTALES Y CANALES: poner en el tablero la fila del total y
 * las de los canales al mismo tiempo cuenta dos veces el mismo importe. El
 * registro declara esa relacion en 'componentes' y el validador de la
 * estructura lo rechaza.
 *
 * UNA SOLA PASADA
 * Ventas::proyectarCobranzas() resuelve venta y cobranza en el mismo recorrido,
 * asi que las diez series salen de una unica llamada. Es un calculo caro (lee
 * el historico, los indices, la participacion y el calendario bancario, y
 * recorre dia x canal x medio de pago), y por eso el motor pide las series de
 * un proveedor una sola vez por pedido y no una vez por fila.
 *
 * Se le inyecta el eje del tablero para que las series caigan exactamente en
 * las mismas columnas sobre las que consolida el resto del Cashflow.
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

        $series = [
            'COBRANZA' => $this->cobranzaNeta($p),
            'VENTA' => [
                'dias' => $p['venta']['total_dias'],
                'meses' => $p['venta']['total_meses']
            ]
        ];

        /* ---- Apertura por canal ------------------------------------------ */
        // La cobranza por canal sale de los subtotales que ya calcula el motor
        // de Ventas; la venta por canal, de su grilla por canal.
        $canales = isset($p['canales']) ? $p['canales'] : Parametros::CANALES;

        foreach ($canales as $canal) {
            $series['COBRANZA_' . $canal] = [
                'dias' => $this->porCanal($p['cobranza'], 'subtotal_dias', $canal),
                'meses' => $this->porCanal($p['cobranza'], 'subtotal_meses', $canal)
            ];

            $series['VENTA_' . $canal] = [
                'dias' => $this->porCanal($p['venta'], 'dias', $canal),
                'meses' => $this->porCanal($p['venta'], 'meses', $canal)
            ];
        }

        return $series;
    }

    /**
     * @param array $grilla Bloque 'venta' o 'cobranza' del payload
     * @param string $rama Nombre de la rama por canal
     * @param string $canal
     * @return array Mapa clave => importe, o vacio si el canal no esta
     */
    private function porCanal($grilla, $rama, $canal) {
        return isset($grilla[$rama][$canal]) ? $grilla[$rama][$canal] : [];
    }

    /**
     * Cobranza total menos el neteo de cheques adelantados.
     *
     * El neteo hoy devuelve siempre cero porque su vista origen todavia no
     * existe (ver Ventas::getNeteoPrechequeado). Se resta igual, cableado, para
     * que el dia que se enchufe el origen el tablero quede correcto solo, sin
     * tener que acordarse de tocar esto.
     *
     * SOLO SE APLICA A LA SERIE TOTAL. El neteo no viene abierto por canal, asi
     * que las series COBRANZA_<CANAL> son brutas. Hoy da lo mismo porque el
     * neteo es cero; cuando exista el origen habra que decidir como se
     * distribuye entre canales, y ese criterio es de negocio y no del tablero.
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
