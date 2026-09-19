<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Fondos.php';
require_once __DIR__ . '/../Cotizacion.php';

/**
 * FondosProvider
 * Alimenta las filas de stock de la seccion Cobertura con el saldo de las
 * cuentas de fondo del catalogo de Saldos.
 *
 * Sirve DOS codigos del registro, uno por clase de cuenta, igual que
 * SaldosProvider sirve SALDOS y CAJA_LOCALES:
 *   FONDO_INVERSION -> serie STOCK, la suma de las cuentas de clase INVERSION
 *   FONDO_COMITENTE -> serie STOCK, la suma de las cuentas de clase COMITENTE
 *
 * Van separados porque son dos filas del tablero -"Inversiones disponibles" y
 * "Dolares en cuenta comitente"- y el tablero tiene que poder mostrar cada
 * una con su moneda y su cotizacion. Cual es cual lo decide CLASE, no el
 * nombre de la cuenta: no hay ninguna cuenta escrita en el codigo.
 *
 * EL STOCK ES EL SALDO A HOY DE CADA CUENTA
 * -----------------------------------------
 *     saldo inicial + suscripciones - rescates, con FECHA <= hoy
 *
 * La cuenta la hace Fondos::saldoA(), que es la misma que usa la pestana
 * Saldos -> Fondos, asi que el tablero y la pantalla no pueden discrepar. Un
 * movimiento con fecha futura no entra y se avisa: hoy la plata todavia esta
 * en el fondo.
 *
 * EL IMPORTE VA EN EL PRIMER DIA DEL EJE y no significa "entra ese dia": el
 * motor vacia todas las columnas de una fila STOCK_COBERTURA y muestra el
 * importe solo en la columna Total. Esta ahi para que llegue al motor por el
 * mismo camino que cualquier otra serie. Ver Cashflow::calcularTotales().
 *
 * CADA CUENTA ES UN FONDO, Y LA SERIE LO DICE
 * -------------------------------------------
 * Ademas del total, la serie lleva 'por_fondo' -cuanto stock aporta cada
 * cuenta, por su clave- y 'fondos' -el nombre de cada una-. Es lo que el motor
 * necesita para descontar de cada cuenta lo que se aplico desde ella y avisar
 * POR FONDO cuando se aplica de mas. Antes el fondo era una constante del
 * registro ('origen_cobertura'); con cuentas que da de alta el usuario, tiene
 * que viajar con los datos. Ver Cashflow::resolverCobertura().
 *
 * LOS DOLARES SE VALUAN COMO SIEMPRE
 * ----------------------------------
 * El motor nunca ve dolares. Una cuenta en USD se convierte con la ULTIMA
 * COTIZACION OFICIAL CONOCIDA A HOY, punta VENDEDORA: es el mismo criterio con
 * el que se valuaba la foto de la cuenta comitente (ver Class/Cotizacion.php y
 * README-otros-ingresos.md), y es la misma punta con la que se valuan las
 * aplicaciones en dolares, asi que consumir todo el saldo lo deja en cero.
 * Sin cotizacion, esa cuenta NO entra y se avisa el importe en dolares: un
 * cero se leeria como "no hay dolares".
 *
 * La moneda la dice la CUENTA, no la clase: una cuenta de inversion en dolares
 * se valua igual que una comitente. 'moneda' en el registro es informativa.
 *
 * NUNCA TUMBA EL TABLERO
 * ----------------------
 * calcular() puede lanzar; series() lo envuelve. Si el script del modulo no
 * se corrio, la fila va en cero con un aviso que dice cual.
 */
class FondosProvider extends CashflowProvider {

    /** Que clase de cuenta sirve cada codigo del registro */
    const CLASE_POR_CODIGO = [
        'FONDO_INVERSION' => 'INVERSION',
        'FONDO_COMITENTE' => 'COMITENTE'
    ];

    /**
     * Las dos dependencias con base van por fabrica, para que una prueba pueda
     * reemplazarlas por una subclase sin abrir conexion. Es la misma costura que
     * Cobertura::valuarAplicaciones() con su Cotizacion inyectable: lo delicado
     * de este proveedor -que reparte por fondo, que valua en dolares, que deja
     * afuera lo futuro- tiene que poder verificarse con cuentas de mentira.
     */
    protected function fondos() {
        return new Fondos();
    }

    protected function cotizacion() {
        return new Cotizacion();
    }

    protected function calcular($h) {
        if (!isset(self::CLASE_POR_CODIGO[$this->codigo()])) {
            $this->avisar('Fondos: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        return ['STOCK' => $this->stock($h, self::CLASE_POR_CODIGO[$this->codigo()])];
    }

    /**
     * El stock de una clase de cuenta: la suma del saldo a hoy de sus cuentas,
     * en pesos, con el detalle por cuenta.
     *
     * @param Horizonte $h
     * @param string $clase INVERSION | COMITENTE
     * @return array Serie
     */
    private function stock($h, $clase) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;
        $serie['por_fondo'] = [];
        $serie['fondos'] = [];

        $rotulo = Fondos::CLASES[$clase];
        $fondos = $this->fondos();

        if (!$fondos->creado()) {
            $this->avisar($rotulo . ': todavia no existen las cuentas de fondo, asi que el stock '
                . 'se muestra en cero. Corre sql/cashflow_saldos_cuentas_fondo.sql.');

            return $serie;
        }

        $cuentas = array_values(array_filter($fondos->getCuentasFondo(true, $h->hoy()),
            function ($c) use ($clase) {
                return $c['CLASE'] === $clase;
            }));

        if (empty($cuentas)) {
            $this->avisar($rotulo . ': no hay ninguna cuenta de esa clase dada de alta, asi que '
                . 'el stock va en cero. Se cargan desde Parametros -> Saldos.');

            return $serie;
        }

        $cotizacion = null;
        $errorCotizacion = null;
        $usdSinCotizar = 0;
        $tiposUsados = [];
        $sinInicial = [];
        $posteriores = 0;
        $todasUsd = true;
        $total = 0;

        foreach ($cuentas as $c) {
            if ($c['SALDO_INICIAL'] === null) {
                $sinInicial[] = $c['NOMBRE'];
            }

            $posteriores += intval($c['posteriores']);

            $ars = floatval($c['saldo']);

            if (strtoupper((string) $c['MONEDA']) === 'USD') {
                $ars = null;

                if ($errorCotizacion === null) {
                    try {
                        if ($cotizacion === null) {
                            $cotizacion = $this->cotizacion();
                        }

                        $ult = $cotizacion->ultimaHasta($h->hoy(), Cotizacion::VENDEDOR);

                        if ($ult !== null) {
                            $ars = round(floatval($c['saldo']) * $ult['valor'], 2);
                            $tiposUsados[$ult['fecha']] = $ult['valor'];
                        }
                    } catch (Throwable $e) {
                        $errorCotizacion = $e->getMessage();
                    }
                }

                if ($ars === null) {
                    $usdSinCotizar += floatval($c['saldo']);
                    continue;
                }
            } else {
                $todasUsd = false;
            }

            $serie['por_fondo'][$c['clave_fondo']] = $ars;
            $serie['fondos'][$c['clave_fondo']] = $c['NOMBRE'];
            $total += $ars;
        }

        if ($errorCotizacion !== null) {
            $this->avisar($rotulo . ': no se pudo leer el tipo de cambio oficial ('
                . Cotizacion::VISTA_DIARIA . '), asi que las cuentas en dolares no entran al '
                . 'stock. Los dolares estan, lo que falta es a cuanto valuarlos.');
        } elseif ($usdSinCotizar != 0) {
            $this->avisar($rotulo . ': USD ' . number_format($usdSinCotizar, 2, ',', '.')
                . ' no se pueden valuar porque no hay ninguna cotizacion oficial anterior a hoy, '
                . 'asi que no entran al stock.');
        }

        if (!empty($sinInicial)) {
            $this->avisar($rotulo . ': ' . count($sinInicial) . ' cuenta(s) no tienen saldo '
                . 'inicial cargado (' . implode(', ', $sinInicial) . '): su stock arranca de '
                . 'cero y solo cuenta los movimientos. Cargalo desde Parametros -> Saldos.');
        }

        if ($posteriores > 0) {
            $this->avisar($rotulo . ': ' . $posteriores . ' movimiento(s) tienen fecha posterior '
                . 'a hoy y no entran al stock, que es el saldo de hoy. Se ven en Saldos -> Fondos.');
        }

        // Con una sola cotizacion usada se informa cual: es la que hay que
        // poder auditar. Con varias no puede pasar -es una sola fecha-, pero
        // el contrato lo prevé igual.
        if (count($tiposUsados) === 1) {
            $serie['tipo_cambio'] = reset($tiposUsados);
        }

        if ($todasUsd && !empty($serie['por_fondo'])) {
            $serie['moneda_origen'] = 'USD';
        }

        if ($total != 0) {
            $h->acumular($serie, $h->hoy(), $total);
        }

        return $serie;
    }
}
