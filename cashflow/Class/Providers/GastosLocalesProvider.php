<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../GastosLocales.php';

/**
 * GastosLocalesProvider
 * Alimenta la fila "Gastos Locales" (seccion COSTOS INDIRECTOS) con lo que los
 * locales propios pagan con la caja, proyectado desde el promedio de los
 * ultimos 90 dias.
 *
 * Codigo GASTOS_LOCALES, serie PAGOS, en pesos y sin tipo de cambio.
 *
 * LA SERIE ES LA SUMA DE LOS LOCALES
 * ----------------------------------
 * Cada local se acumula contra el eje por su lado y el total se arma con
 * Horizonte::sumar(). No se recalcula con el promedio de todos juntos: la fila
 * del tablero tiene que ser exactamente la suma de las filas de la pestana, y
 * un calculo paralelo podria diferir en el redondeo.
 *
 * EL REPARTO EN EL EJE NO LO DECIDE ESTE PROVEEDOR
 * ------------------------------------------------
 * Cada partida trae su fecha -la del pago semanal, o el primer dia del mes
 * fuera del tramo- y Horizonte::acumular() la ubica. Ver GastosLocalesCalculo.
 *
 * SI LA CONSULTA FALLA, LA FILA VA EN CERO CON UN AVISO CRITICO
 * --------------------------------------------------------------
 * El contrato del tablero no tiene "sin dato" en una celda: un proveedor que no
 * puede calcular rinde ceros. Lo que si se puede, y se hace, es que el aviso
 * diga que ese cero no significa que los locales no gasten.
 *
 * NUNCA TUMBA EL TABLERO: calcular() puede lanzar y series() lo envuelve.
 */
class GastosLocalesProvider extends CashflowProvider {

    protected function calcular($h) {
        $datos = $this->modulo()->planilla($h);

        $this->avisarTodos(isset($datos['avisos_con_nivel'])
            ? $datos['avisos_con_nivel'] : $datos['avisos']);

        if (empty($datos['leido'])) {
            return [];
        }

        $serie = self::serie($h, $datos);

        /* Solo puede pasar con la ultima semana del tramo corrida mas alla del
           fin del eje (un horizonte de un solo mes). Se dice, como en todos. */
        if ($serie['fuera_horizonte'] > 0) {
            $this->avisar('Hay $ ' . number_format($serie['fuera_horizonte'], 2, ',', '.')
                . ' de gastos de locales con fecha posterior al final del horizonte, así que no '
                . 'entran en ninguna columna.', Aviso::INFO);
        }

        return ['PAGOS' => $serie];
    }

    /**
     * Costura para probar con un doble, como LogisticaProvider::modulo().
     *
     * @return GastosLocales
     */
    protected function modulo() {
        return new GastosLocales();
    }

    /**
     * La serie: una por local, sumadas.
     *
     * Estatica y pura, para que la pestana y la prueba la puedan pedir igual.
     *
     * @param Horizonte $h
     * @param array $datos Lo que devolvio GastosLocales::planilla()
     * @return array Serie con 'dias', 'meses', 'fuera_horizonte', 'sin_fecha'
     */
    public static function serie($h, $datos) {
        $series = [];

        foreach ($datos['locales'] as $l) {
            $s = $h->serieVacia();
            $s['fuera_horizonte'] = 0;
            $s['sin_fecha'] = 0;

            foreach ($l['partidas'] as $p) {
                if ($p['importe'] === null) {
                    continue;
                }

                if (!$h->acumular($s, $p['fecha'], $p['importe'])) {
                    $s['fuera_horizonte'] += $p['importe'];
                }
            }

            $series[] = $s;
        }

        $total = $h->sumar($series);
        $total['moneda_origen'] = 'ARS';

        return $total;
    }
}
