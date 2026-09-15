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
 * OtrosIngresosProvider con su serie STOCK, porque el dato es el saldo de
 * inversiones y se carga en Otros Ingresos -> Saldo de Inversiones. Asi la fila
 * de stock del tablero enlaza a la pantalla donde ese numero se puede auditar,
 * en vez de a una pantalla que lo repetiria.
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

        $cobertura = new Cobertura();

        foreach ($cobertura->getAvisos() as $aviso) {
            $this->avisar($aviso);
        }

        foreach ($cobertura->getAplicaciones() as $a) {
            $importe = floatval($a['IMPORTE']);

            if ($importe == 0) {
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
