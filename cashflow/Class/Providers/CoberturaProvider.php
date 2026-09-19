<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Cobertura.php';

/**
 * CoberturaProvider
 * Alimenta la fila de aplicacion de cobertura del tablero.
 *
 * Sirve un solo codigo del registro:
 *   COBERTURA -> serie APLICACION, cuanta cobertura se aplica en cada fecha.
 *
 * NO TIENE PESTANA PROPIA, y es a proposito. La fila se edita DESDE EL TABLERO,
 * que es donde se ven los saldos negativos: un editor en otra pantalla obligaria
 * a ir y volver comparando columnas, que es exactamente el trabajo que esta
 * fila existe para evitar. Por eso su entrada en CashflowRegistry no declara
 * 'tab' y el nombre de la fila no queda como enlace.
 *
 * EL IMPORTE VIAJA CON SU SIGNO. Un negativo -devolver plata a la inversion- se
 * transporta tal cual; el signo de la fila lo pone el TIPO, igual que en todos
 * los demas proveedores, y el de esta fila es +1. Ver
 * CashflowEstructura::signo().
 *
 * EL STOCK NO SALE DE ACA. Cuanta plata hay disponible para cubrir lo informa
 * FondosProvider con su serie STOCK, porque el dato es el saldo de las cuentas
 * de inversion y comitente y se lleva en Saldos -> Fondos. Asi la fila de stock
 * del tablero enlaza a la pantalla donde ese numero se puede auditar, en vez de
 * a una pantalla que lo repetiria.
 */
class CoberturaProvider extends CashflowProvider {

    protected function calcular($h) {
        if ($this->codigo() !== 'COBERTURA') {
            $this->avisar('Cobertura: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        return ['APLICACION' => $this->aplicaciones($h)];
    }

    /**
     * La cobertura aplicada por fecha.
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function aplicaciones($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        /* CUANTO SE APLICO DE CADA FONDO. Viaja con la serie -como ya viajan
           moneda_origen y fuera_horizonte- porque el motor lo necesita para
           calcular el disponible POR FONDO y no puede ir a buscarlo a la base:
           el motor no consulta, arma el cuadro con lo que los proveedores le
           dan. La clave es la de la cuenta de fondo, la misma que usa la serie
           de stock de FondosProvider; 'fondos' lleva el nombre de cada una
           para que el aviso pueda nombrarla. Ver Cashflow::resolverCobertura().

           OJO: la clave del contrato es 'por_fondo'. Antes era 'por_origen', y
           CashflowProvider::normalizar() la descartaba en silencio, asi que
           los avisos por fondo nunca llegaron al tablero real. */
        $serie['por_fondo'] = [];
        $serie['fondos'] = [];

        $cobertura = new Cobertura();

        foreach ($cobertura->getAvisos() as $aviso) {
            $this->avisar($aviso);
        }

        /* LOS IMPORTES VIENEN YA VALUADOS. Una aplicacion en dolares se guarda en
           dolares y se convierte con la cotizacion del dia en que se aplica; la
           conversion vive en Cobertura::valuarAplicaciones(), que es tambien la
           que usa la pantalla. Si cada uno multiplicara por su cuenta, el cuadro
           y la pestaña podrian discrepar y no habria forma de saber cual esta
           mal. */
        $val = $cobertura->valuarAplicaciones();

        if ($val['error'] !== null) {
            $this->avisar('Cobertura: no se pudo leer el tipo de cambio oficial, así que las '
                . 'aplicaciones cargadas en dólares no se están mostrando. Las de pesos no '
                . 'cambian.');
        }

        if ($val['sin_cotizacion'] > 0) {
            $this->avisar('Cobertura: USD '
                . number_format($val['sin_cotizacion'], 2, ',', '.') . ' aplicados no se pueden '
                . 'valuar porque no hay cotización oficial anterior a su fecha, así que no '
                . 'entran al cuadro.');
        }

        $serie['por_fondo'] = $val['por_origen'];

        $origenes = $cobertura->origenes();

        foreach (array_keys($val['por_origen']) as $clave) {
            if (isset($origenes[$clave])) {
                $serie['fondos'][$clave] = $origenes[$clave]['nombre'];
            }
        }

        /* Una aplicacion desde un fondo que no es ninguna cuenta -una clave
           vieja que la migracion no pudo mover- suma al cuadro pero no
           descuenta de nadie. Se dice, porque el disponible por fondo queda
           informado de mas. */
        $huerfanas = 0;

        foreach ($val['por_origen'] as $clave => $ars) {
            if (!isset($origenes[$clave])) {
                $huerfanas += floatval($ars);
            }
        }

        if ($huerfanas != 0) {
            $this->avisar('Cobertura: $ ' . number_format($huerfanas, 2, ',', '.') . ' aplicados '
                . 'salen de un origen que no es ninguna cuenta de fondo, así que no descuentan '
                . 'del saldo de ninguna. Reasignalos desde el tablero.');
        }

        foreach ($val['filas'] as $a) {
            $importe = $a['IMPORTE_ARS'];

            if ($importe === null || $importe == 0) {
                continue;
            }

            // Una aplicacion con fecha fuera del eje se informa, no se descarta
            // en silencio: es plata que alguien decidio mover y que el tablero
            // no esta mostrando.
            if (!$h->acumular($serie, $a['FECHA'], $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        return $serie;
    }
}
