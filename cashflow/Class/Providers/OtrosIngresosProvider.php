<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../OtrosIngresos.php';
require_once __DIR__ . '/../Cotizacion.php';

/**
 * OtrosIngresosProvider
 * Alimenta el tablero con los ingresos que se cargan a mano.
 *
 * Sirve dos codigos del registro:
 *   DOLARES_COMITENTE -> serie INGRESO, los dolares de la cuenta comitente.
 *   SALDO_INVERSIONES -> serie STOCK, cuanta plata hay invertida y disponible
 *                        para cubrir un bache del flujo. EN PESOS.
 *
 * EL SALDO DE INVERSIONES YA NO ES UN INGRESO: ES STOCK DE COBERTURA
 * ------------------------------------------------------------------
 * Antes entraba al flujo como INGRESO en la fecha de su carga. Eso decia que
 * ese dia ingresaba plata, y no es cierto: la plata ya esta, invertida, y lo
 * que hay que decidir es CUANDO se la usa. Esa decision ahora se carga en la
 * seccion Cobertura del tablero (Class/Cobertura.php) y es la que mueve el
 * saldo; esta serie solo informa cuanto hay.
 *
 * Por eso la fila del tablero es de tipo STOCK_COBERTURA, no va en ninguna
 * columna de fecha y no entra en ninguna suma. La serie INGRESO sigue
 * declarada en el registro para poder volver atras desde Parametros, pero la
 * fila que la usaba quedo inhabilitada. Ver sql/cashflow_cobertura.sql.
 *
 * DOLARES CUENTA COMITENTE NO CAMBIA: sigue siendo un INGRESO. En el Excel esta
 * en el bloque del Disponible y no en el de inversiones, y es plata en una
 * cuenta, no un fondo invertido.
 *
 * EL STOCK ES LA ULTIMA CARGA, NO LA SUMA DE TODAS
 * ------------------------------------------------
 * Cada carga es una FOTO del saldo invertido a esa fecha, no un deposito. Dos
 * cargas de 3,5 y 3,6 millones son el mismo dinero informado dos veces, asi que
 * el stock es 3,6 y no 7,1. La serie vieja las sumaba -era un ingreso por
 * fecha- y con mas de una carga habria mostrado plata que no existe; el sintoma
 * no aparecio antes porque hasta ahora hay una sola.
 *
 * LOS DOS CONCEPTOS NO SE VALUAN IGUAL, Y ES CORRECTO
 * ---------------------------------------------------
 * Los dolares se guardan en dolares y se convierten en cada lectura. El saldo
 * de inversiones se informa EN PESOS: no hay nada que valuar, asi que su serie
 * sale tal cual. Convertirlo seria inventar una moneda de origen que el dato no
 * tiene, y eso se notaria recien cuando el numero del tablero no coincidiera con
 * el de la pantalla. La decision, y como darla vuelta si algun dia el saldo se
 * informa en dolares, esta arriba de sql/cashflow_saldo_inversiones.sql.
 *
 * LA CONVERSION DE LOS DOLARES VIVE ACA, NO EN EL MOTOR
 * -----------------------------------------------------
 * El motor nunca ve dolares: todos los proveedores le entregan pesos. Se
 * guardan los dolares y se convierten en cada lectura, igual que hace
 * ComexProvider con los pagos al exterior. Guardar pesos congelaria la
 * valuacion al momento de la carga y el dia que cambie el tipo de cambio el
 * tablero seguiria mostrando la conversion vieja, sin forma de notarlo.
 *
 * CADA CARGA SE VALUA CON LA ULTIMA COTIZACION CONOCIDA A SU FECHA
 * ----------------------------------------------------------------
 * Antes se usaba el CIERRE DEL MES de cada carga, y eso tenia dos problemas que
 * solo se veian mirando el numero de cerca. El mes en curso no tiene cierre
 * todavia -devolvia la ultima cargada, o sea otra cosa que lo que el nombre
 * decia-, y para una carga de principios de mes se valuaba con una cotizacion
 * de semanas despues. El criterio nuevo es Cotizacion::ultimaHasta(): la ultima
 * cotizacion con fecha ANTERIOR O IGUAL a la de la carga.
 *
 * EL MOTIVO ES QUE EL NUMERO TIENE QUE SER EXPLICABLE. El saldo en pesos del
 * tablero se tiene que poder atar a una cotizacion real y fechada, y por eso la
 * serie transporta 'tipo_cambio' y la pestana muestra la cuenta abierta:
 * USD x cotizacion (con su fecha) = importe en pesos.
 *
 * mapaMensual() NO QUEDO OBSOLETO: lo siguen usando Ventas y SaldosProvider
 * para valuar mes a mes, que es otra pregunta y tiene otra respuesta correcta.
 * Ver el encabezado de Class/Cotizacion.php.
 *
 * A DIFERENCIA DE COMEX, EL T/C NO ES UN PARAMETRO: es el oficial del BCRA, que
 * ya esta resuelto en las vistas. Un parametro editable tendria sentido para
 * valuar un pago futuro -que es criterio comercial-; para decir cuanto valen
 * unos dolares que ya estan en la cuenta, no.
 *
 * SI PARA UNA FECHA NO HAY COTIZACION, SE AVISA Y NO SE ASUME UN VALOR
 * --------------------------------------------------------------------
 * Ese importe queda fuera de la serie y el aviso dice cuantos dolares son. Pasa
 * solo con una carga anterior a la primera cotizacion cargada; hacia atras
 * siempre hay una. Inventar un tipo de cambio pondria en el tablero un numero
 * que nadie eligio y que nadie podria auditar: mostrar la plata sin decir a
 * cuanto se valuo es peor que no mostrarla.
 *
 * SIN CARGAS DEVUELVE CERO, y eso es correcto: la fila existe y muestra cero
 * hasta que haya datos. El aviso de la pestana distingue "no hay datos" de
 * "los datos son cero".
 */
class OtrosIngresosProvider extends CashflowProvider {

    protected function calcular($h) {
        if ($this->codigo() === 'DOLARES_COMITENTE') {
            return ['INGRESO' => $this->dolaresComitente($h)];
        }

        if ($this->codigo() === 'SALDO_INVERSIONES') {
            // Las dos series salen de la MISMA lectura y son el mismo dinero
            // mirado de dos formas: STOCK dice cuanto hay -y es la que usa el
            // tablero-, INGRESO es la forma vieja, que queda declarada para
            // poder volver atras desde Parametros sin tocar codigo. No pueden
            // estar las dos activas: el registro las relaciona en
            // 'componentes' y el validador lo rechaza.
            return [
                'STOCK' => $this->stockInversiones($h),
                'INGRESO' => $this->saldoInversiones($h)
            ];
        }

        $this->avisar('Otros Ingresos: el codigo de proveedor "' . $this->codigo()
            . '" no tiene serie definida.');

        return [];
    }

    /**
     * Cuanta plata hay invertida y disponible para cubrir, EN PESOS.
     *
     * ES LA ULTIMA CARGA, NO LA SUMA. Cada carga es una foto del saldo a esa
     * fecha; sumarlas contaria el mismo dinero tantas veces como veces se haya
     * informado. Ver el encabezado.
     *
     * EL IMPORTE VA EN EL PRIMER DIA DEL EJE y no en la fecha de la carga, que
     * puede ser vieja o futura y en los dos casos quedaria fuera del horizonte.
     * No significa "entra ese dia": el motor vacia todas las columnas de una
     * fila STOCK_COBERTURA y muestra el importe solo en la columna Total. Esta
     * ahi nada mas para que el importe llegue al motor por el mismo camino que
     * cualquier otra serie. Ver Cashflow::calcularTotales().
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function stockInversiones($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        $cargas = (new OtrosIngresos())->getSaldoInversiones();

        if (empty($cargas)) {
            return $serie;
        }

        // getSaldoInversiones() devuelve los vigentes por FECHA DESC, asi que la
        // primera es la ultima carga. Se busca el maximo igual, para no depender
        // del ORDER BY de otra clase.
        $ultima = null;

        foreach ($cargas as $carga) {
            if ($ultima === null || $carga['FECHA'] > $ultima['FECHA']) {
                $ultima = $carga;
            }
        }

        $stock = floatval($ultima['IMPORTE_ARS']);

        if ($stock != 0) {
            $h->acumular($serie, $h->hoy(), $stock);
        }

        return $serie;
    }

    /**
     * El saldo de inversiones vigente por fecha, EN PESOS. Serie EN DESUSO.
     *
     * Es la forma vieja: cada carga entraba al flujo como un ingreso en su
     * fecha. Hoy la fila del tablero usa STOCK; esta queda para poder volver
     * atras desde Parametros. Ver el encabezado antes de reactivarla: con mas de
     * una carga suma el mismo dinero varias veces.
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function saldoInversiones($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        foreach ((new OtrosIngresos())->getSaldoInversiones() as $carga) {
            $importe = floatval($carga['IMPORTE_ARS']);

            if ($importe == 0) {
                continue;
            }

            // Lo que cae fuera del eje se informa, no se descarta en silencio.
            if (!$h->acumular($serie, $carga['FECHA'], $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        return $serie;
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

        // LA CUENTA NO SE HACE ACA: la hace OtrosIngresos::valuarDolares(), que
        // es la misma que consume la pestana. Asi el total del tablero se ata
        // fila por fila a lo que se ve en la grilla, en vez de ser dos
        // multiplicaciones parecidas escritas en dos lugares.
        $v = (new OtrosIngresos())->valuarDolares();

        if ($v['error'] !== null) {
            $this->avisar('Dolares Cuenta Comitente: no se pudo leer el tipo de cambio oficial '
                . '(' . Cotizacion::VISTA_DIARIA . '), asi que la fila se muestra en cero. Los '
                . 'dolares cargados estan, lo que falta es a cuanto valuarlos.');

            return $serie;
        }

        $usados = [];

        foreach ($v['filas'] as $fila) {
            // Sin cotizacion no se asume ningun valor: esos dolares ya estan
            // contados en 'sin_cotizacion' y se avisan abajo.
            if ($fila['IMPORTE_ARS'] === null || $fila['IMPORTE_ARS'] == 0) {
                continue;
            }

            $usados[$fila['TC_FECHA']] = $fila['TC'];

            if (!$h->acumular($serie, $fila['FECHA'], $fila['IMPORTE_ARS'])) {
                $serie['fuera_horizonte'] += $fila['IMPORTE_ARS'];
            }
        }

        if ($v['sin_cotizacion'] > 0) {
            $this->avisar('Dolares Cuenta Comitente: USD '
                . number_format($v['sin_cotizacion'], 2, ',', '.') . ' no se muestran porque no '
                . 'hay ninguna cotizacion oficial anterior a su fecha. No se asume ningun tipo '
                . 'de cambio.');
        }

        // Con una sola cotizacion usada se informa cual: es la que hay que
        // poder auditar. Con varias, la cuenta por fila la tiene la pestana.
        if (count($usados) === 1) {
            $serie['tipo_cambio'] = reset($usados);
        }

        return $serie;
    }
}
