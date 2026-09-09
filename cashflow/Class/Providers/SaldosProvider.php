<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Saldos.php';
require_once __DIR__ . '/../Cotizacion.php';

/**
 * SaldosProvider
 * Alimenta el tablero con el disponible inicial y con el deposito de la caja de
 * los locales.
 *
 * Sirve DOS codigos del registro, y cada instancia corre solo la consulta de la
 * suya, igual que ComexProvider:
 *   SALDOS       -> serie DISPONIBLE, la fila "Saldo Inicial".
 *   CAJA_LOCALES -> serie DEPOSITOS, el neto a depositar de los locales propios.
 *
 * Son dos consultas contra dos servidores distintos y con dos criterios de
 * fecha distintos, asi que instanciarlas por separado evita que una caida del
 * servidor de locales se lleve puesto el disponible bancario.
 *
 * EL SALDO NO SE REPITE EN TODAS LAS COLUMNAS
 * -------------------------------------------
 * La fila DISPONIBLE es de tipo SALDO_INICIAL, y el motor toma lo que el modulo
 * pone en CADA COLUMNA como aporte de esa columna al arrastre (ver
 * Cashflow::sumarAporteSaldo()). Devolver el mismo saldo en las 28 columnas
 * diarias sumaria la misma plata veintiocho veces. El saldo va en la columna de
 * su fecha y en cero en el resto; el criterio esta en
 * Saldos::armarSerieDisponible() y esta cubierto por las pruebas.
 *
 * LA CONVERSION DE MONEDA VIVE ACA, NO EN EL MOTOR
 * ------------------------------------------------
 * El contrato exige pesos. Los saldos en dolares se valuan con
 * Class/Cotizacion.php, que es el unico punto de acceso al tipo de cambio del
 * cashflow: lee la cotizacion de CIERRE del mes del saldo. Si la vista del tipo
 * de cambio no existe en el entorno o el mes no tiene cotizacion, la parte en
 * dolares NO entra al tablero y se avisa con el importe en su moneda. Un cero
 * silencioso se leeria como "no hay dolares".
 *
 * La pestana Saldos, en cambio, NO convierte nada: muestra un total en pesos y
 * otro en dolares, cada uno en su moneda.
 *
 * NUNCA TUMBA EL TABLERO
 * ----------------------
 * calcular() puede lanzar; series() lo envuelve. Ademas, cada una de las dos
 * series atrapa sus propios problemas para poder rendir cero CON UN AVISO QUE
 * DIGA QUE PASO, en lugar del mensaje generico de la clase base.
 */
class SaldosProvider extends CashflowProvider {

    protected function calcular($h) {
        $saldos = new Saldos();

        switch ($this->codigo()) {
            case 'SALDOS':
                return ['DISPONIBLE' => $this->disponible($h, $saldos)];

            case 'CAJA_LOCALES':
                return ['DEPOSITOS' => $this->depositosLocales($h, $saldos)];
        }

        $this->avisar('Saldos: el codigo de proveedor "' . $this->codigo()
            . '" no tiene serie definida.');

        return [];
    }

    /**
     * Disponible inicial: efectivo de tesoreria, bancos y Mercado Pago.
     *
     * Toma el ULTIMO SALDO CONOCIDO DE CADA CUENTA, con su propia fecha. No es
     * "las filas de la ultima carga": una cuenta dada de alta despues de la
     * ultima carga, o que quedo sin completar, tiene que aportar su ultimo dato
     * conocido y no un cero.
     *
     * @param Horizonte $h
     * @param Saldos $saldos
     * @return array Serie
     */
    private function disponible($h, $saldos) {
        if (!$saldos->tablasCreadas()) {
            $this->avisar('Saldo Inicial: todavia no existen las tablas del modulo Saldos, asi '
                . 'que el disponible se muestra en cero. Corre sql/cashflow_saldos.sql.');

            return ['dias' => [], 'meses' => [], 'moneda_origen' => 'ARS'];
        }

        $filas = $saldos->getSaldosActuales();

        // Una cuenta que nunca se cargo no aporta. Se cuenta aparte para poder
        // distinguir "no hay datos" de "los datos son cero", como hace
        // ComexProvider con las nacionalizaciones.
        $cargadas = [];
        $sinCargar = 0;

        foreach ($filas as $f) {
            if (empty($f['cargada'])) {
                $sinCargar++;
                continue;
            }

            $cargadas[] = $f;
        }

        if (empty($cargadas)) {
            $this->avisar('Saldo Inicial: hay ' . count($filas) . ' cuenta(s) configuradas pero '
                . 'ninguna tiene saldo cargado todavia, asi que la fila va en cero. Carga los '
                . 'saldos desde la pestana Saldos.');

            return ['dias' => [], 'meses' => [], 'moneda_origen' => 'ARS'];
        }

        if ($sinCargar > 0) {
            $this->avisar('Saldo Inicial: ' . $sinCargar . ' cuenta(s) todavia no tienen ningun '
                . 'saldo cargado y no suman al disponible.');
        }

        $armado = Saldos::armarSerieDisponible($cargadas, $h, $this->cotizaciones($h, $cargadas));

        foreach ($armado['avisos'] as $a) {
            $this->avisar($a);
        }

        return $armado['serie'];
    }

    /**
     * Cotizaciones de cierre de los meses que hacen falta para valuar los
     * saldos en dolares.
     *
     * Solo se pide si hay alguna cuenta en dolares: si no, no tiene sentido
     * pagar una consulta que ademas resuelve por linked server y puede fallar
     * en un entorno que no lo tiene.
     *
     * @param Horizonte $h
     * @param array $filas
     * @return array Mapa 'YYYY-MM' => tipo de cambio. Vacio si no hace falta o
     *         si la vista no esta disponible.
     */
    private function cotizaciones($h, $filas) {
        $hayDolares = false;

        foreach ($filas as $f) {
            if (strtoupper((string) $f['moneda']) === 'USD') {
                $hayDolares = true;
                break;
            }
        }

        if (!$hayDolares) {
            return [];
        }

        try {
            // Del mes de la primera columna al del final del eje: un saldo con
            // fecha anterior se imputa en la primera columna, asi que su mes
            // relevante es el de esa columna y no el de la fecha original.
            return (new Cotizacion())->mapaMensual(substr($h->hoy(), 0, 7), substr($h->fin(), 0, 7));
        } catch (Throwable $e) {
            $this->avisar('Saldo Inicial: no se pudo leer el tipo de cambio ('
                . $e->getMessage() . '), asi que los saldos en dolares no entran al tablero.');

            return [];
        }
    }

    /**
     * Deposito de la caja de los locales propios.
     *
     * SOLO LAS SUCURSALES EN 'DEPOSITA' Y SOLO EL NETO POSITIVO. Las dos reglas
     * viven en Saldos::armarSaldosLocales(), que es un helper puro y esta
     * cubierto por las pruebas.
     *
     * LA CONSULTA CORRE EN VIVO. Es una consulta contra Tango que se actualiza
     * sola todos los dias; leer la ultima carga guardada mostraria el dato de
     * ayer teniendo el de hoy. Si el servidor de locales no responde, la serie
     * rinde ceros y deja un aviso: nunca tumba el tablero.
     *
     * @param Horizonte $h
     * @param Saldos $saldos
     * @return array Serie
     */
    private function depositosLocales($h, $saldos) {
        try {
            $consulta = $saldos->getSaldosLocalesOrigen();
        } catch (Throwable $e) {
            $this->avisar('Caja Locales: no se pudo leer la caja de los locales ('
                . $e->getMessage() . '), asi que la fila se muestra en cero.');

            return ['dias' => [], 'meses' => [], 'moneda_origen' => 'ARS'];
        }

        $params = [];

        try {
            $params = $saldos->getParametrosSucursales(true);
        } catch (Throwable $e) {
            $this->avisar('Caja Locales: no se pudieron leer las reservas de caja por sucursal ('
                . $e->getMessage() . '), asi que se toma reserva cero y todas depositan.');
        }

        $armado = Saldos::armarSaldosLocales($consulta, $params);

        foreach ($armado['avisos'] as $a) {
            $this->avisar('Caja Locales: ' . $a);
        }

        // Un cero no dice si no hay locales o si los locales no tienen caja por
        // encima de su reserva. Se distingue, igual que en Nacionalizaciones.
        if (!empty($armado['filas']) && $armado['totales']['aporta'] == 0) {
            $this->avisar('Caja Locales: los ' . count($armado['filas']) . ' locales de la '
                . 'consulta no superan su reserva de caja o estan en Envia, asi que la fila va '
                . 'en cero.');
        }

        return Saldos::armarSerieLocales($armado['filas'], $h);
    }
}
