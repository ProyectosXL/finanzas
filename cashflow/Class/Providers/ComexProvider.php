<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Parametros.php';
require_once __DIR__ . '/../Comex.php';
require_once __DIR__ . '/../DolarFuturo.php';

/**
 * ComexProvider
 * Alimenta el tablero con los egresos de Comercio Exterior.
 *
 * Sirve DOS codigos del registro, y cada instancia corre solo la consulta de la
 * suya:
 *   COMEX_PROV_EXT -> serie PAGOS, pagos a proveedores del exterior. En DOLARES.
 *   COMEX_NAC      -> serie NACIONALIZACION, gastos de nacionalizacion. En pesos.
 *
 * LA CONVERSION DE MONEDA YA NO VIVE ACA: VIVE EN Comex
 * -----------------------------------------------------
 * El motor sigue sin ver dolares -todos los proveedores le entregan pesos- pero
 * la multiplicacion se movio a Comex::valuar(), y no es un detalle de
 * organizacion.
 *
 * Antes se valuaba con UN parametro global, 'comex_tipo_cambio_usd', aplicado a
 * la serie entera de una: un multiplicador unico en la llamada a agrupar().
 * Mientras el criterio era un numero, daba lo mismo donde estuviera escrito.
 *
 * Ahora cada fila se valua con la CURVA DE DOLAR FUTURO ROFEX segun el mes de
 * SU fecha de pago, asi que ya no hay un multiplicador: hay una cotizacion por
 * fila. Y esa misma cotizacion es la que la pestana Proveedores Exterior
 * necesita mostrar. Con la cuenta en los dos lados, el tablero y la pestana
 * podrian valuar distinto el mismo contenedor; con la cuenta en el getter,
 * los dos leen el mismo IMPORTE_ARS. Ver el encabezado de Comex y el de
 * DolarFuturo.
 *
 * EL PARAMETRO GLOBAL SE RETIRO, Y NO CONVIVE
 * -------------------------------------------
 * 'comex_tipo_cambio_usd' esta en Parametros::RETIRADOS: la fila sigue en la
 * base -este modulo no borra parametros historicos- y el formulario ya no la
 * muestra. Dos criterios de valuacion conviviendo es la peor opcion posible,
 * porque el tablero y la pestana muestran dos numeros para el mismo contenedor
 * y nadie puede decir cual es cual.
 *
 * Si la curva no se puede leer, la fila va en CERO con un aviso que nombra la
 * tabla, igual que antes hacia con el parametro faltante: un proveedor no puede
 * tumbar el tablero.
 *
 * YA NO SE FILTRA POR FECHA DE EMBARQUE, Y EL TABLERO SE MUEVE
 * ------------------------------------------------------------
 * Las dos consultas de origen cortaban con FECHA_EMB >= hoy, y este proveedor
 * avisaba en cada carga que por eso un pago pendiente de un contenedor ya
 * embarcado no aparecia. El aviso era correcto y no alcanzaba: lo que el filtro
 * escondia -al 19/09/2026- eran 42 de 76 contenedores, entre ellos 10 con la
 * fecha de pago todavia por delante y 18 con la nacionalizacion por delante.
 * Eran egresos reales que el tablero informaba de menos.
 *
 * El filtro se fue y el aviso con el. Lo que decide es la FECHA EFECTIVA de
 * cada fila, que es la que ubica el importe en el eje.
 *
 * LO VENCIDO SE AVISA APARTE, y no se reubica en la columna de hoy. El porque
 * -y por que esto se aparta de Ingresos::ubicarCobroVencido()- esta en el
 * encabezado de Comex. Lo que importa aca es que el motor ya informa lo que
 * cayo 'fuera del horizonte' pero no distingue si cayo antes o despues del eje,
 * y esas dos cosas se arreglan distinto: una cargando una fecha nueva, la otra
 * alargando el horizonte.
 *
 * NO SE REUSAN procesarDatosPorPeriodo() NI procesarCronoNacPorPeriodo()
 * Esos dos metodos agrupan por dia del mes (1..31) con una ventana fija del mes
 * actual mas once, y descartan en silencio todo lo que cae afuera. El tablero
 * necesita claves 'Y-m-d' sobre el eje configurable y necesita SABER lo que
 * quedo afuera. Se agrupa desde los getters crudos con Horizonte::agrupar() y
 * no se toca el resto de Comex.php, del que dependen dos pestanas que funcionan.
 */
class ComexProvider extends CashflowProvider {

    protected function calcular($h) {
        $comex = new Comex();

        switch ($this->codigo()) {
            case 'COMEX_PROV_EXT':
                return $this->pagosExterior($h, $comex);

            case 'COMEX_NAC':
                return $this->nacionalizaciones($h, $comex);
        }

        $this->avisar('Comex: el codigo de proveedor "' . $this->codigo() . '" no tiene serie definida.');

        return [];
    }

    /**
     * Pagos a proveedores del exterior, ya convertidos a pesos fila por fila.
     *
     * SE AGRUPA POR IMPORTE_ARS Y SIN MULTIPLICADOR. Cada fila llega con su
     * propia valuacion resuelta -la cotizacion del mes de su fecha de pago, o
     * el override que alguien le cargo- y lo unico que queda por hacer es
     * ubicarla en el eje. El multiplicador unico de agrupar() ya no sirve para
     * esto: no hay UN tipo de cambio.
     *
     * La fecha que manda es FECHA_PAGO_EFECTIVA, que desde
     * feature/comex-fecha-maestra es FECHA_EST_PAGO del maestro y nada mas: lo
     * que se edita desde el cashflow se escribe ahi. Puede venir nula, y ahi
     * hay algo importante: esas filas NO se pueden convertir
     * -sin mes no hay cotizacion- asi que su IMPORTE_ARS es null y no entran a
     * 'sin_fecha', que es un acumulador EN PESOS. Se cuentan aparte y se
     * informan en dolares. Mezclar las dos monedas en el mismo campo daria un
     * numero que no significa nada.
     *
     * @param Horizonte $h
     * @param Comex $comex
     * @return array Serie
     */
    private function pagosExterior($h, $comex) {
        $dolar = $comex->dolarFuturo();

        /* La curva es el criterio de valuacion entero: sin ella no hay ningun
           pago que se pueda expresar en pesos. La fila va en cero con el aviso
           que nombra la tabla, igual que antes con el parametro faltante. */
        if (!$dolar->disponible()) {
            $this->avisar('Proveedores Exterior: no se pudo leer la curva de dólar futuro ROFEX ('
                . DolarFuturo::ORIGEN . '), así que los pagos al exterior van en cero. '
                . 'Los importes en dólares están: lo que falta es a cuánto convertirlos. '
                . ($dolar->error() === null ? '' : $dolar->error()));

            $vacia = [
                'dias' => [],
                'meses' => [],
                'moneda_origen' => 'USD',
                'tipo_cambio' => null
            ];

            return ['PAGOS' => $vacia, 'PAGOS_PAGADOS' => $vacia, 'PAGOS_TODO' => $vacia];
        }

        $filas = $comex->getProveedoresExterior();

        /* LAS TRES SERIES DEL CORTE, y cual campo usa cada una NO es un
           detalle: es lo que hace que el invariante cierre columna por columna.

             PAGOS         IMPORTE_EJE sobre TODAS las filas. Ese campo ya vale
                           cero para lo pagado Y para lo vencido.
             PAGOS_PAGADOS IMPORTE_PROYECTABLE sobre las MARCADAS. Ese campo
                           vale cero solo para lo vencido.
             PAGOS_TODO    IMPORTE_PROYECTABLE sobre todas.

           Con eso, PAGOS + PAGOS_PAGADOS = PAGOS_TODO: para una fila no
           marcada los dos campos valen lo mismo y aporta a PAGOS; para una
           marcada, IMPORTE_EJE es cero y aporta a PAGOS_PAGADOS. Ver
           Comex::aporteAlEje(). */
        $serie = $h->agrupar($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_EJE');

        $serie['moneda_origen'] = 'USD';

        /* CON QUE COTIZACION SE CONVIRTIO. El contrato de la serie tiene UN
           escalar, y la valuacion ahora es por fila: solo se informa cuando
           todas las filas se valuaron con el mismo numero, que es el mismo
           criterio que OtrosIngresosProvider usa para los dolares comitente.
           Con varias, el detalle por fila lo tiene la pestana, y el tablero
           dibuja la marca de "valuado con la curva". */
        $usadas = [];

        foreach ($filas as $f) {
            if ($f['COTIZ_USD'] !== null) {
                $usadas[(string) $f['COTIZ_USD']] = floatval($f['COTIZ_USD']);
            }
        }

        $serie['tipo_cambio'] = (count($usadas) === 1) ? reset($usadas) : null;

        /* Los mismos avisos que muestra la pestana, escritos una sola vez en
           Comex::avisosValuacion(). Se les antepone el nombre de la fila
           porque en el tablero conviven los avisos de todos los modulos y un
           mensaje suelto no dice de cual es. */
        foreach (Comex::avisosValuacion($filas, $dolar->ultimoMes()) as $aviso) {
            $this->avisar('Proveedores Exterior: ' . $aviso);
        }

        /* Lo vencido no suma, y eso hay que decirlo con su importe: sin el
           aviso, esa plata desaparece del tablero sin que nada lo explique.
           Se informa IMPORTE_ARS -lo que valen- y no IMPORTE_EJE, que para
           estas filas es cero por definicion.

           SIN PASARLE EL EJE: aca no hay nada que repartir, porque ninguna
           vencida entra en ninguna columna. Nacionalizaciones si se lo pasa,
           porque alla la regla no aplica y las del mes en curso entran. */
        foreach (Comex::avisosVencidos($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
                 'fecha estimada de pago') as $aviso) {
            $this->avisar('Proveedores Exterior: ' . $aviso);
        }

        foreach (Comex::avisosPagados($filas, 'IMPORTE_PROYECTABLE', 'pago') as $aviso) {
            $this->avisar('Proveedores Exterior: ' . $aviso);
        }

        $marcadas = self::soloPagadas($filas);

        return [
            'PAGOS' => $serie,
            'PAGOS_PAGADOS' => $this->conMoneda(
                $h->agrupar($marcadas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_PROYECTABLE'), 'USD'),
            'PAGOS_TODO' => $this->conMoneda(
                $h->agrupar($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_PROYECTABLE'), 'USD')
        ];
    }

    /**
     * Las filas marcadas como pagadas.
     *
     * Estatica y pura, para poder verificar el corte sin depender de que haya
     * algo marcado en la base. Mismo criterio que EcheqsProvider::repartir().
     *
     * @param array $filas
     * @return array
     */
    public static function soloPagadas($filas) {
        $v = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (!empty($f['PAGADO'])) {
                $v[] = $f;
            }
        }

        return $v;
    }

    /**
     * Le pone la moneda de origen a una serie derivada.
     *
     * Las tres series del corte describen la misma plata, asi que informan la
     * misma moneda: una que dijera otra cosa haria que el tablero dibujara la
     * marca de conversion en unas filas si y en otras no, sobre los mismos
     * contenedores.
     *
     * @param array $serie
     * @param string $moneda
     * @return array
     */
    private function conMoneda($serie, $moneda) {
        $serie['moneda_origen'] = $moneda;

        return $serie;
    }

    /**
     * Gastos de nacionalizacion. Ya estan en pesos, no hay conversion.
     *
     * IMPORTE_EST viene de un LEFT JOIN sobre la estimacion, asi que puede ser
     * nulo cuando el contenedor todavia no tiene gastos estimados; esos casos
     * suman cero y no distorsionan.
     *
     * @param Horizonte $h
     * @param Comex $comex
     * @return array Serie
     */
    private function nacionalizaciones($h, $comex) {
        $filas = $comex->getCronoNacionalizacion();

        /* Sobre IMPORTE_EJE, igual que los pagos al exterior: una
           nacionalizacion con la fecha ya vencida no suma. Ver
           Comex::aporteAlEje(). */
        $serie = $h->agrupar($filas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_EJE');

        $serie['moneda_origen'] = 'ARS';

        /* Sin pasarle el eje: ninguna vencida entra en ninguna columna, asi que
           no hay nada que repartir. Se informa IMPORTE_EST -lo que valen- y no
           IMPORTE_EJE, que para estas filas es cero por definicion. */
        foreach (Comex::avisosVencidos($filas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_EST',
                 'fecha de nacionalización') as $aviso) {
            $this->avisar('Nacionalizaciones: ' . $aviso);
        }

        foreach (Comex::avisosPagados($filas, 'IMPORTE_PROYECTABLE', 'nacionalización')
                 as $aviso) {
            $this->avisar('Nacionalizaciones: ' . $aviso);
        }

        /* Un cero no dice si no hay contenedores o si los hay sin importe
           cargado. La estimacion sale de un LEFT JOIN sobre
           RO_T_IMPORTACIONES_ESTIMACION_DETALLE (conceptos 3 a 10) y puede
           venir nula. Se avisa para que el cero se pueda interpretar.

           SE MIDE SOBRE EL IMPORTE DE ORIGEN Y NO SOBRE LA SERIE, y eso cambio
           con la regla de los vencidos: ahora la serie puede dar cero porque
           TODOS los contenedores estan vencidos, que es otra cosa
           completamente. Midiendo la serie, ese caso diria "ninguno tiene
           gastos cargados" sobre contenedores que si los tienen. */
        if (count($filas) > 0 && self::totalImporte($filas, 'IMPORTE_EST') == 0) {
            $this->avisar(
                'Nacionalizaciones: hay ' . count($filas) . ' contenedores en el cronograma pero '
                . 'ninguno tiene gastos de nacionalizacion estimados cargados, asi que la fila va '
                . 'en cero. Es lo mismo que muestra la pestana Crono Nacionalizacion.'
            );
        }

        $marcadas = self::soloPagadas($filas);

        return [
            'NACIONALIZACION' => $serie,
            'NACIONALIZACION_PAGADAS' => $this->conMoneda(
                $h->agrupar($marcadas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_PROYECTABLE'), 'ARS'),
            'NACIONALIZACION_TODO' => $this->conMoneda(
                $h->agrupar($filas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_PROYECTABLE'), 'ARS')
        ];
    }

    /**
     * Cuanto suman las filas crudas en un campo, sin pasar por el eje.
     *
     * REEMPLAZA A totalSerie(), que sumaba las cuatro bolsas de la serie. Desde
     * que una fila vencida aporta CERO al eje, la serie ya no sirve para
     * contestar "¿hay datos cargados?": puede dar cero porque no hay gastos
     * estimados o porque todos los contenedores estan vencidos, y son dos cosas
     * distintas que necesitan dos avisos distintos.
     *
     * @param array $filas
     * @param string $campo
     * @return float
     */
    private static function totalImporte($filas, $campo) {
        $total = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            $total += isset($f[$campo]) ? floatval($f[$campo]) : 0.0;
        }

        return $total;
    }

}
