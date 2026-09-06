<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Parametros.php';
require_once __DIR__ . '/../Comex.php';

/**
 * ComexProvider
 * Alimenta el tablero con los egresos de Comercio Exterior.
 *
 * Sirve DOS codigos del registro, y cada instancia corre solo la consulta de la
 * suya:
 *   COMEX_PROV_EXT -> serie PAGOS, pagos a proveedores del exterior. En DOLARES.
 *   COMEX_NAC      -> serie NACIONALIZACION, gastos de nacionalizacion. En pesos.
 *
 * LA CONVERSION DE MONEDA VIVE ACA, NO EN EL MOTOR
 * El motor nunca ve dolares: todos los proveedores le entregan pesos. El tipo
 * de cambio con el que se valua un pago futuro es criterio de negocio de
 * Comercio Exterior, no del tablero, y el dia que haya que usar una curva por
 * mes en lugar de un valor unico el cambio es solo aca.
 *
 * Si falta el parametro del tipo de cambio, la fila va en CERO con un aviso que
 * nombra el parametro. No se lee con Parametros::num() justamente por eso:
 * num() lanza si la clave no esta, y un proveedor no puede tumbar el tablero.
 *
 * NO SE REUSAN procesarDatosPorPeriodo() NI procesarCronoNacPorPeriodo()
 * Esos dos metodos agrupan por dia del mes (1..31) con una ventana fija del mes
 * actual mas once, y descartan en silencio todo lo que cae afuera. El tablero
 * necesita claves 'Y-m-d' sobre el eje configurable y necesita SABER lo que
 * quedo afuera. Se agrupa desde los getters crudos con Horizonte::agrupar() y
 * no se toca Comex.php, del que dependen dos pestanas que funcionan.
 */
class ComexProvider extends CashflowProvider {

    /** Clave del tipo de cambio en RO_T_CASHFLOW_PARAMETROS (MODULO='COMEX') */
    const PARAM_TIPO_CAMBIO = 'comex_tipo_cambio_usd';

    /**
     * Aviso comun a las dos series: la consulta de origen solo trae
     * contenedores cuyo embarque es de hoy en adelante.
     */
    const AVISO_FILTRO_EMBARQUE =
        'solo se incluyen contenedores con fecha de embarque desde hoy, '
        . 'asi que un pago pendiente de un contenedor ya embarcado no aparece en el tablero.';

    protected function calcular($h) {
        $comex = new Comex();

        switch ($this->codigo()) {
            case 'COMEX_PROV_EXT':
                return ['PAGOS' => $this->pagosExterior($h, $comex)];

            case 'COMEX_NAC':
                return ['NACIONALIZACION' => $this->nacionalizaciones($h, $comex)];
        }

        $this->avisar('Comex: el codigo de proveedor "' . $this->codigo() . '" no tiene serie definida.');

        return [];
    }

    /**
     * Pagos a proveedores del exterior, convertidos de dolares a pesos.
     *
     * La fecha que manda es FECHA_PAGO_EFECTIVA, que Comex resuelve como
     * FECHA_PAGO_EDIT si el usuario la corrigio y FECHA_EST_PAGO si no. Puede
     * venir nula: en ese caso el importe no se puede ubicar en el tiempo y
     * Horizonte::agrupar() lo acumula en 'sin_fecha' para que se informe.
     *
     * @param Horizonte $h
     * @param Comex $comex
     * @return array Serie
     */
    private function pagosExterior($h, $comex) {
        $tipoCambio = $this->tipoCambio();

        $this->avisar('Proveedores Exterior: ' . self::AVISO_FILTRO_EMBARQUE);

        if ($tipoCambio === null) {
            return [
                'dias' => [],
                'meses' => [],
                'moneda_origen' => 'USD',
                'tipo_cambio' => null
            ];
        }

        $serie = $h->agrupar(
            $comex->getProveedoresExterior(),
            'FECHA_PAGO_EFECTIVA',
            'VALOR_FOB_DOLAR',
            $tipoCambio
        );

        $serie['moneda_origen'] = 'USD';
        $serie['tipo_cambio'] = $tipoCambio;

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
        $this->avisar('Nacionalizaciones: ' . self::AVISO_FILTRO_EMBARQUE);

        $filas = $comex->getCronoNacionalizacion();

        $serie = $h->agrupar($filas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_EST');

        $serie['moneda_origen'] = 'ARS';

        // Un cero no dice si no hay contenedores o si los hay sin importe
        // cargado. Hoy pasa lo segundo: la estimacion sale de un LEFT JOIN
        // sobre RO_T_IMPORTACIONES_ESTIMACION_DETALLE (conceptos 3 a 10) y
        // viene nula para todos. La pestana Crono Nacionalizacion muestra el
        // mismo cero. Se avisa para que el cero se pueda interpretar.
        if (count($filas) > 0 && $this->totalSerie($serie) == 0) {
            $this->avisar(
                'Nacionalizaciones: hay ' . count($filas) . ' contenedores en el cronograma pero '
                . 'ninguno tiene gastos de nacionalizacion estimados cargados, asi que la fila va '
                . 'en cero. Es lo mismo que muestra la pestana Crono Nacionalizacion.'
            );
        }

        return $serie;
    }

    /**
     * Todo lo que trajo la serie, este dentro o fuera del eje. Sirve para
     * distinguir "no hay datos" de "los datos son cero".
     *
     * @param array $serie
     * @return float
     */
    private function totalSerie($serie) {
        return array_sum($serie['dias'])
            + array_sum($serie['meses'])
            + $serie['fuera_horizonte']
            + $serie['sin_fecha'];
    }

    /**
     * Tipo de cambio para valuar los pagos en dolares.
     *
     * @return float|null null si el parametro falta o no es positivo
     */
    private function tipoCambio() {
        $map = (new Parametros())->getParametrosMap();

        $valor = isset($map[self::PARAM_TIPO_CAMBIO])
            ? floatval($map[self::PARAM_TIPO_CAMBIO])
            : 0;

        if ($valor <= 0) {
            $this->avisar(
                'Proveedores Exterior: falta el parametro "' . self::PARAM_TIPO_CAMBIO
                . '" o esta en cero, asi que los pagos en dolares se muestran en cero. '
                . 'Cargalo en Parametros.'
            );

            return null;
        }

        return $valor;
    }
}
