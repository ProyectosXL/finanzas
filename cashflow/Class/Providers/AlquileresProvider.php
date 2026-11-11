<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Alquileres.php';

/**
 * AlquileresProvider
 * Los alquileres de los locales, por dia de pago, para el tablero.
 *
 * Codigo ALQUILERES, en pesos, tres series:
 *
 *   REAL        las facturas de Tango imputadas en la pestaña, en su fecha de
 *               pago (el vencimiento a considerar, o el siguiente habil desde
 *               hoy si ya paso) y por su a pagar (pendiente - retencion)
 *   PROYECTADO  la proyeccion por local que ninguna factura reemplazo
 *   PAGOS       las dos juntas: la serie de la fila de antes del split
 *
 * La fila del tablero es AGRUPADA: REAL y PROYECTADO en un renglon que se abre
 * (sql/cashflow_alquileres_fila.sql).
 *
 * LAS SERIES SON LA LISTA DE PAGOS DE LA PESTAÑA
 * ----------------------------------------------
 * Alquileres::calcular() devuelve UNA lista de pagos, con las facturas ya
 * aplicadas (AlquileresImputacion::aplicar()). La pestaña la agrupa por mes y
 * este proveedor la acumula por dia, partida por origen: FACTURA es lo real, el
 * resto lo proyectado. Ninguno recalcula nada, y una prueba fija que sumen lo
 * mismo, por dia. Un pago que se corrio de dia lleva su nota en 'detalle'.
 *
 * LAS FACTURAS SIN IMPUTAR NO ESTAN EN NINGUNA SERIE: contarian dos veces contra
 * la proyeccion del local. El aviso de atencion las nombra con su importe.
 *
 * NUNCA TUMBA EL TABLERO: calcular() puede lanzar y series() lo envuelve.
 */
class AlquileresProvider extends CashflowProvider {

    const SERIE_REAL = 'REAL';
    const SERIE_PROYECTADO = 'PROYECTADO';
    const SERIE_TOTAL = 'PAGOS';

    /** El nombre con el que la parte A llamaba a la unica serie que tenia */
    const SERIE = self::SERIE_PROYECTADO;

    protected function calcular($h) {
        $alq = $this->modulo($h);
        $datos = $alq->calcular($h, $alq->insumos($h));
        $this->avisarTodos($datos['avisos']);

        return self::armarSeries($h, $datos['pagos']);
    }

    /**
     * Costura para probar con un doble.
     *
     * @param Horizonte $h
     * @return Alquileres
     */
    protected function modulo($h) {
        return new Alquileres($h->hoy());
    }

    /**
     * Las tres series a partir de la lista de pagos. Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos Lo de Alquileres::calcular()['pagos']
     * @return array codigoSerie => serie
     */
    public static function armarSeries($h, $pagos) {
        $reales = array_values(array_filter($pagos, [self::class, 'esReal']));
        $proyectados = array_values(array_filter($pagos, function ($p) { return !self::esReal($p); }));

        return [
            self::SERIE_REAL => self::serie($h, $reales, 'Facturas de alquiler'),
            self::SERIE_PROYECTADO => self::serie($h, $proyectados),
            self::SERIE_TOTAL => self::serie($h, $pagos)
        ];
    }

    /** Si un pago es una factura imputada */
    public static function esReal($p) {
        return ($p['origen'] ?? null) === AlquileresCalculo::FACTURA;
    }

    /**
     * Una serie por dia de pago, con la nota de los pagos que se movieron de
     * dia. Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos
     * @param string $quien Como se llaman en la nota
     * @return array Serie con 'dias', 'meses', 'fuera_horizonte', 'detalle'
     */
    public static function serie($h, $pagos, $quien = 'Alquileres') {
        $s = $h->serieVacia();
        $s['fuera_horizonte'] = 0;
        $s['detalle'] = [];
        $notas = [];
        $fechasEje = array_column($h->dias(), 'fecha');

        foreach ($pagos as $p) {
            if (!$h->acumular($s, $p['fecha'], $p['a_pagar'])) {
                $s['fuera_horizonte'] += $p['a_pagar'];
                continue;
            }

            if ($p['nota_dia'] !== '') {
                $clave = in_array($p['fecha'], $fechasEje, true)
                    ? 'DIA|' . $p['fecha'] : 'MES|' . substr($p['fecha'], 0, 7);
                if (!isset($notas[$clave])) {
                    $notas[$clave] = ['importe' => 0.0, 'textos' => []];
                }

                $notas[$clave]['importe'] += $p['a_pagar'];
                $notas[$clave]['textos'][$p['nota_dia']] = true;
            }
        }

        /* El importe es el de los pagos que se movieron de dia, no el de la
           celda entera: 'detalle' anota UNA PARTE de lo que la celda suma. */
        foreach ($notas as $clave => $n) {
            $s['detalle'][$clave] = [
                'importe' => round($n['importe'], 2),
                'nota' => $quien . ' corridos de día: ' . implode(' ', array_keys($n['textos']))
            ];
        }

        $s['moneda_origen'] = 'ARS';

        return $s;
    }
}
