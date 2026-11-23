<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Llaves.php';

/**
 * LlavesProvider
 * Llaves, renovaciones de contrato, obras de imagen, aperturas y compromisos a
 * mano, por dia de pago, para el tablero.
 *
 * Codigo LLAVES_RENOV, en pesos, tres series:
 *
 *   REAL        las facturas de alquiler marcadas como llave en Alquileres ->
 *               Facturas, en sus pagos (o enteras en su vencimiento a
 *               considerar). Las ya pagadas no suman
 *   PROYECTADO  lo que ninguna factura reemplazo: los contratos cargados en la
 *               app, lo estimado y lo cargado a mano
 *   PAGOS       las dos juntas: la serie de la fila de antes del split
 *
 * Es un concepto con parte real y proyectada (README-cashflow.md): la fila del
 * tablero es AGRUPADA, REAL y PROYECTADO en un renglon que se abre
 * (sql/cashflow_llaves_fila.sql). PAGOS y sus partes no pueden convivir
 * activos ('componentes' en el registro).
 *
 * LAS SERIES SON LA LISTA DE PAGOS DE LA PESTAÑA. Llaves::calcular() devuelve
 * UNA lista; la pestaña la agrupa con EjeVista y este proveedor la acumula
 * por dia con Horizonte::acumular(), la misma regla dia o mes. Ninguno
 * recalcula nada, y una prueba fija que sumen lo mismo, columna por columna.
 *
 * LO ESTIMADO SE ANOTA ('detalle'): de cada celda, cuanto es estimado. No es
 * una serie aparte -seria una fila que suma lo que esta ya suma-; es un dato
 * sobre el mismo importe, para que quien mira el tablero sepa que parte es una
 * estimacion y cual esta en un contrato, una factura o un compromiso.
 *
 * NUNCA TUMBA EL TABLERO: calcular() puede lanzar y series() lo envuelve.
 */
class LlavesProvider extends CashflowProvider {

    const SERIE_REAL = 'REAL';
    const SERIE_PROYECTADO = 'PROYECTADO';
    const SERIE_TOTAL = 'PAGOS';

    protected function calcular($h) {
        $ll = $this->modulo($h);
        $datos = $ll->calcular($h, $ll->insumos($h));
        $this->avisarTodos($datos['avisos']);

        return self::armarSeries($h, $datos['pagos']);
    }

    /**
     * Costura para probar con un doble.
     *
     * @param Horizonte $h
     * @return Llaves
     */
    protected function modulo($h) {
        return new Llaves($h->hoy());
    }

    /**
     * Las tres series a partir de la lista de pagos. Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos Lo de Llaves::calcular()['pagos']
     * @return array codigoSerie => serie
     */
    public static function armarSeries($h, $pagos) {
        $reales = array_values(array_filter($pagos, [self::class, 'esReal']));
        $proyectados = array_values(array_filter($pagos, function ($p) { return !self::esReal($p); }));

        return [
            self::SERIE_REAL => self::serie($h, $reales),
            self::SERIE_PROYECTADO => self::serie($h, $proyectados),
            self::SERIE_TOTAL => self::serie($h, $pagos)
        ];
    }

    /** Si un pago es una factura marcada como llave */
    public static function esReal($p) {
        return ($p['origen'] ?? null) === LlavesCalculo::FACTURA;
    }

    /**
     * Una serie por dia de pago, con la parte estimada de cada celda anotada.
     * Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos
     * @return array Serie con 'dias', 'meses', 'fuera_horizonte', 'detalle'
     */
    public static function serie($h, $pagos) {
        $s = $h->serieVacia();
        $s['fuera_horizonte'] = 0;
        $s['detalle'] = [];
        $estimados = [];
        $dias = $h->diasSet();

        foreach ($pagos as $p) {
            if (!$h->acumular($s, $p['fecha'], $p['importe'])) {
                $s['fuera_horizonte'] += $p['importe'];
                continue;
            }

            if ($p['origen'] === LlavesCalculo::ESTIMADO) {
                $clave = isset($dias[$p['fecha']]) ? 'DIA|' . $p['fecha'] : 'MES|' . substr($p['fecha'], 0, 7);
                $estimados[$clave]['importe'] = ($estimados[$clave]['importe'] ?? 0.0) + $p['importe'];
                $estimados[$clave]['quien'][trim(($p['local'] ?? '') . ' ' . LlavesCalculo::NOMBRE_CONCEPTO[$p['concepto']])] = true;
            }
        }

        foreach ($estimados as $clave => $x) {
            $s['detalle'][$clave] = [
                'importe' => round($x['importe'], 2),
                'nota' => 'Estimado (no está en un contrato, una factura ni un compromiso): '
                    . implode(', ', array_keys($x['quien'])) . '. La cuenta, en la pestaña Llaves y Renov. Contratos.'
            ];
        }

        $s['moneda_origen'] = 'ARS';

        return $s;
    }
}
