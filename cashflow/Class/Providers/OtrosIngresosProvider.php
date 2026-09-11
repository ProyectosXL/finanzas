<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../OtrosIngresos.php';
require_once __DIR__ . '/../Cotizacion.php';

/**
 * OtrosIngresosProvider
 * Alimenta el tablero con los ingresos que se cargan a mano.
 *
 * Hoy sirve un solo codigo del registro:
 *   DOLARES_COMITENTE -> serie INGRESO, los dolares de la cuenta comitente.
 *
 * ES UN INGRESO, NO UNA DISPONIBILIDAD. El importe entra al flujo en la fecha
 * que se le carga; no es un saldo de apertura y no arrastra. Por eso la fila
 * del tablero es de tipo INGRESO y no SALDO_INICIAL.
 *
 * LA CONVERSION VIVE ACA, NO EN EL MOTOR
 * --------------------------------------
 * El motor nunca ve dolares: todos los proveedores le entregan pesos. Se
 * guardan los dolares y se convierten en cada lectura, igual que hace
 * ComexProvider con los pagos al exterior. Guardar pesos congelaria la
 * valuacion al momento de la carga y el dia que cambie el tipo de cambio el
 * tablero seguiria mostrando la conversion vieja, sin forma de notarlo.
 *
 * CADA CARGA SE VALUA AL T/C DE SU PROPIO MES
 * -------------------------------------------
 * No hay un unico tipo de cambio para toda la serie: se usa el cierre del mes
 * de cada carga, que es el criterio de Class/Cotizacion.php para todo el
 * cashflow. Dividir o multiplicar una serie entera por un solo valor es otra
 * cuenta -reexpresar todo a moneda de hoy- y con inflacion no se parece.
 *
 * A DIFERENCIA DE COMEX, EL T/C NO ES UN PARAMETRO: es el oficial del BCRA, que
 * ya esta resuelto en RO_V_DOLAR_OFICIAL_BCRA. Un parametro editable tendria
 * sentido para valuar un pago futuro -que es criterio comercial-; para decir
 * cuanto valen unos dolares que ya estan en la cuenta, no.
 *
 * SI PARA UNA FECHA NO HAY COTIZACION, SE AVISA Y NO SE ASUME UN VALOR
 * --------------------------------------------------------------------
 * Ese importe queda fuera de la serie y el aviso dice cuantos dolares son.
 * Inventar un tipo de cambio -el del mes anterior, o el ultimo conocido-
 * pondria en el tablero un numero que nadie eligio y que nadie podria auditar.
 * Mostrar la plata sin decir a cuanto se valuo es peor que no mostrarla.
 *
 * SIN CARGAS DEVUELVE CERO, y eso es correcto: la fila existe y muestra cero
 * hasta que haya datos. El aviso de la pestana distingue "no hay datos" de
 * "los datos son cero".
 */
class OtrosIngresosProvider extends CashflowProvider {

    protected function calcular($h) {
        if ($this->codigo() !== 'DOLARES_COMITENTE') {
            $this->avisar('Otros Ingresos: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        return ['INGRESO' => $this->dolaresComitente($h)];
    }

    /**
     * Los dolares vigentes por fecha, convertidos a pesos.
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function dolaresComitente($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'USD';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        $cargas = (new OtrosIngresos())->getDolaresComitente();

        if (empty($cargas)) {
            return $serie;
        }

        $mapa = $this->cotizaciones($cargas);

        if ($mapa === null) {
            $this->avisar('Dolares Cuenta Comitente: no se pudo leer el tipo de cambio oficial '
                . '(' . Cotizacion::VISTA . '), asi que la fila se muestra en cero. Los dolares '
                . 'cargados estan, lo que falta es a cuanto valuarlos.');

            return $serie;
        }

        $sinCotizacion = 0;
        $usados = [];

        foreach ($cargas as $carga) {
            $fecha = $carga['FECHA'];
            $usd = floatval($carga['IMPORTE_USD']);

            if ($usd == 0) {
                continue;
            }

            $mes = substr((string) $fecha, 0, 7);

            if (!isset($mapa[$mes])) {
                // La clave ausente es lo que distingue "no hay dato" de "el
                // dato es cero". No se asume ningun valor.
                $sinCotizacion += $usd;
                continue;
            }

            $tc = $mapa[$mes];
            $usados[$mes] = $tc;

            if (!$h->acumular($serie, $fecha, $usd * $tc)) {
                $serie['fuera_horizonte'] += $usd * $tc;
            }
        }

        if ($sinCotizacion > 0) {
            $this->avisar('Dolares Cuenta Comitente: USD '
                . number_format($sinCotizacion, 2, ',', '.') . ' no se muestran porque su mes no '
                . 'tiene cotizacion oficial cargada. No se asume ningun tipo de cambio.');
        }

        // Con una sola cotizacion usada se informa cual: es la que hay que
        // poder auditar. Con varias, el dato por fila lo tiene la pestana.
        if (count($usados) === 1) {
            $serie['tipo_cambio'] = reset($usados);
        }

        return $serie;
    }

    /**
     * Cotizaciones de cierre de los meses que hacen falta.
     *
     * Se pide UN rango y no una consulta por carga: es la misma cuenta con una
     * sola ida a la base. Devuelve null si el origen no esta disponible, que es
     * distinto de un mapa vacio -eso seria "no hay cotizaciones cargadas"-.
     *
     * @param array $cargas
     * @return array|null Mapa 'Y-m' => float
     */
    private function cotizaciones($cargas) {
        $fechas = array_filter(array_column($cargas, 'FECHA'));

        if (empty($fechas)) {
            return [];
        }

        try {
            return (new Cotizacion())->mapaMensual(min($fechas), max($fechas));
        } catch (Throwable $e) {
            return null;
        }
    }
}
