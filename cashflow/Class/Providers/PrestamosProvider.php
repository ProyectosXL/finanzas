<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Prestamos.php';

/**
 * PrestamosProvider
 * Los prestamos, por dia de pago, para la seccion Financiamiento del tablero.
 *
 * Codigo FINANCIERO (la entrada reservada para la pestaña Prestamos), en pesos,
 * tres conceptos x tres partes:
 *
 *   DESEMBOLSO_* / CAPITAL_* / INTERESES_*    con *_REAL, *_PROY y *_SIM
 *
 *   REAL   lo que se paga HOY. Lo anterior no va: ya esta en el Saldo Inicial,
 *          como en Llaves
 *   PROY   lo que viene, de los prestamos vigentes
 *   SIM    lo de los simulados. EN CERO salvo con el interruptor "Incluir
 *          simulaciones" del tablero (opcion 'simulaciones' del pedido: false,
 *          true o una lista de ids)
 *
 * Y el total de cada concepto (las tres partes juntas). 'componentes' en el
 * registro impide que convivan.
 *
 * LAS SERIES SON LA LISTA DE PAGOS DE LA PESTAÑA (Prestamos::pagosTablero()).
 * La pestaña la agrupa con EjeVista y este proveedor la acumula por dia con
 * Horizonte::acumular(): la misma regla dia o mes. Una prueba fija que sumen
 * lo mismo, columna por columna. Los importes van POSITIVOS: el signo lo pone
 * el TIPO de la fila (INGRESO el desembolso, EGRESO lo demas).
 *
 * LOS AVISOS DE LA PESTAÑA LLEGAN AL TABLERO: los de las lecturas, los de las
 * tasas y los de cada prestamo que entra (los de un simulado, solo con el
 * interruptor).
 *
 * NUNCA TUMBA EL TABLERO: calcular() puede lanzar y series() lo envuelve. Sin
 * el script, la pestaña avisa en critico y esto rinde cero.
 */
class PrestamosProvider extends CashflowProvider {

    const CONCEPTOS = [PrestamosCalculo::DESEMBOLSO, PrestamosCalculo::CAPITAL, PrestamosCalculo::INTERESES];
    const PARTES = ['REAL', 'PROY', 'SIM'];

    protected function calcular($h) {
        $sim = $this->opcion('simulaciones', false);
        $pr = $this->modulo($h);
        $calc = $pr->calcular($pr->insumos());

        $this->avisarTodos(Prestamos::avisosTablero($calc, $sim));

        return self::armarSeries($h, Prestamos::pagosTablero($calc['resultados'], $h->hoy(), $sim));
    }

    /**
     * Costura para probar con un doble.
     *
     * @param Horizonte $h
     * @return Prestamos
     */
    protected function modulo($h) {
        return new Prestamos($h->hoy());
    }

    /** La parte de un pago: SIM si es de un simulado, REAL si es de hoy, PROY si viene. */
    public static function parte($p) {
        if (!empty($p['simulado'])) {
            return 'SIM';
        }

        return !empty($p['real']) ? 'REAL' : 'PROY';
    }

    /**
     * Las doce series a partir de la lista de pagos. Estatica y pura.
     *
     * @param Horizonte $h
     * @param array $pagos Lo de Prestamos::pagosTablero()
     * @return array codigoSerie => serie
     */
    public static function armarSeries($h, $pagos) {
        $out = [];

        foreach (self::CONCEPTOS as $c) {
            $out[$c] = self::serieVacia($h);

            foreach (self::PARTES as $parte) {
                $out[$c . '_' . $parte] = self::serieVacia($h);
            }
        }

        // En desuso: la fila vieja de Ajustes (inactiva). Siempre en cero.
        $out['MOVIMIENTOS'] = self::serieVacia($h);

        foreach ($pagos as $p) {
            $c = $p['concepto'];

            if (!isset($out[$c])) {
                continue;
            }

            $k = $c . '_' . self::parte($p);

            if ($h->acumular($out[$k], $p['fecha'], $p['importe'])) {
                $h->acumular($out[$c], $p['fecha'], $p['importe']);
            } else {
                $out[$k]['fuera_horizonte'] += $p['importe'];
                $out[$c]['fuera_horizonte'] += $p['importe'];
            }
        }

        return $out;
    }

    private static function serieVacia($h) {
        $s = $h->serieVacia();
        $s['fuera_horizonte'] = 0;
        $s['moneda_origen'] = 'ARS';

        return $s;
    }
}
