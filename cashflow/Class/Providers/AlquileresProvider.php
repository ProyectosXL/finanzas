<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Alquileres.php';

/**
 * AlquileresProvider
 * La proyeccion de alquileres de los locales, por dia de pago, para el tablero.
 *
 * Codigo ALQUILERES, serie PROYECTADO, en pesos.
 *
 * TODAVIA NO ALIMENTA NINGUNA FILA
 * --------------------------------
 * El modulo sigue con 'disponible' => false en CashflowRegistry y la fila
 * "Alquileres" del tablero (la que siembra sql/cashflow_estructura.sql) apunta
 * a ALQUILERES/PAGOS, que no existe todavia: rinde cero y avisa, como hasta
 * ahora. Es a proposito. Las facturas de alquiler siguen sumando en Cuentas a
 * Pagar Locales, y si esta proyeccion entrara al tablero el mismo alquiler se
 * contaria dos veces. La proxima entrega saca esas facturas de Proveedores
 * Locales (las de las razones sociales dadas de alta en Parametros ->
 * Alquileres), arma PAGOS con las facturas reales y lo que todavia no se
 * facturo, y da vuelta el flag.
 *
 * Mientras tanto el proveedor existe y se prueba: lo que va a sumar el
 * tablero ya es, al peso, lo que muestra la pestaña.
 *
 * LA SERIE ES LA LISTA DE PAGOS DE LA PESTAÑA
 * -------------------------------------------
 * Alquileres::calcular() devuelve UNA lista de pagos, armada con
 * AlquileresPago, que es la regla de cuanto y cuando. La pestaña la agrupa por
 * mes y este proveedor la acumula por dia: ninguno recalcula nada. Un pago que
 * se corrio al siguiente dia habil lleva su nota en 'detalle'.
 *
 * NUNCA TUMBA EL TABLERO: calcular() puede lanzar y series() lo envuelve.
 */
class AlquileresProvider extends CashflowProvider {

    const SERIE = 'PROYECTADO';

    protected function calcular($h) {
        $alq = $this->modulo($h);
        $datos = $alq->calcular($h, $alq->insumos($h));
        $this->avisarTodos($datos['avisos']);

        return [self::SERIE => self::serie($h, $datos['pagos'])];
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
     * La serie por dia de pago, con la nota de los pagos que se movieron de
     * dia. Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos Lo de Alquileres::calcular()['pagos']
     * @return array Serie con 'dias', 'meses', 'fuera_horizonte', 'detalle'
     */
    public static function serie($h, $pagos) {
        $s = $h->serieVacia();
        $s['fuera_horizonte'] = 0;
        $s['detalle'] = [];
        $notas = [];

        foreach ($pagos as $p) {
            if (!$h->acumular($s, $p['fecha'], $p['a_pagar'])) {
                $s['fuera_horizonte'] += $p['a_pagar'];
                continue;
            }

            if ($p['nota_dia'] !== '') {
                $clave = in_array($p['fecha'], array_column($h->dias(), 'fecha'), true)
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
                'nota' => 'Alquileres corridos de día: ' . implode(' ', array_keys($n['textos']))
            ];
        }

        $s['moneda_origen'] = 'ARS';

        return $s;
    }
}
