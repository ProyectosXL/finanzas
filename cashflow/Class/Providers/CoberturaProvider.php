<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Cobertura.php';
require_once __DIR__ . '/../Fondos.php';

/**
 * CoberturaProvider
 * Alimenta las filas de uso de cobertura del tablero con LO CARGADO A MANO.
 *
 * Sirve un solo codigo del registro, COBERTURA, con tres series:
 *   USO_INVERSION   lo aplicado a mano desde las cuentas de clase INVERSION
 *   USO_COMITENTE   lo aplicado a mano desde las cuentas de clase COMITENTE
 *   APLICACION      todo junto. Es la serie que habia antes de que hubiera una
 *                   fila por clase; queda declarada para poder volver atras, y
 *                   el registro la relaciona con las otras dos en 'componentes'
 *                   para que el validador no deje activar el total y las
 *                   partes a la vez.
 *
 * UNA FILA POR CLASE DE FONDO, Y ESTA ES LA MITAD MANUAL
 * ------------------------------------------------------
 * Esto CAMBIO con feature/cobertura-automatica. El uso de cobertura lo calcula
 * el motor en cada carga (Cashflow::resolverUsoCobertura, CoberturaAutomatica);
 * lo que sale de aca es solo lo que alguien piso a mano, que tiene precedencia
 * y que el motor completa. Para eso cada serie lleva, ademas del importe por
 * columna:
 *
 *   'fondos'         TODAS las cuentas activas de su clase, con nombre, hayan
 *                    aplicado algo o no. Es lo que le dice al motor QUE FONDOS
 *                    APLICA ESA FILA: un fondo con stock que ninguna fila
 *                    nombra no se toca. Y es lo que el editor del tablero
 *                    ofrece para cargar a mano.
 *   'por_fondo'      cuanto se aplico a mano desde cada cuenta, en pesos.
 *   'fondos_manual'  lo mismo, columna por columna y en las dos monedas: el
 *                    importe en la del fondo -que descuenta del tope- y en
 *                    pesos -que entra al saldo-. Ver Class/CashflowProvider.php.
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
 * UNA APLICACION CUYO ORIGEN NO ES NINGUNA CUENTA -una clave vieja que la
 * migracion no pudo mover- no tiene clase. Va a la serie de INVERSION, que es
 * la fila que existia cuando se cargo, y al total; suma al saldo, no descuenta
 * de ningun fondo, y se avisa cuanto es.
 *
 * EL STOCK NO SALE DE ACA. Cuanta plata hay disponible para cubrir lo informa
 * FondosProvider con su serie STOCK, porque el dato es el saldo de las cuentas
 * de inversion y comitente y se lleva en Saldos -> Fondos. Asi la fila de stock
 * del tablero enlaza a la pantalla donde ese numero se puede auditar, en vez de
 * a una pantalla que lo repetiria.
 */
class CoberturaProvider extends CashflowProvider {

    /**
     * Que serie sirve cada clase de fondo. Espejo de
     * FondosProvider::CLASE_POR_CODIGO, y por el mismo motivo: cual es cual lo
     * decide la CLASE de la cuenta, no su nombre.
     */
    const SERIE_POR_CLASE = [
        'INVERSION' => 'USO_INVERSION',
        'COMITENTE' => 'USO_COMITENTE'
    ];

    /** La serie que recibe lo que no tiene clase: la fila que existia primero */
    const SERIE_SIN_CLASE = 'USO_INVERSION';

    /** La serie total, la de antes de la apertura por clase */
    const SERIE_TOTAL = 'APLICACION';

    /**
     * Las dos dependencias con base van por fabrica, para que una prueba pueda
     * reemplazarlas por una subclase sin abrir conexion.
     */
    protected function cobertura() {
        return new Cobertura();
    }

    protected function calcular($h) {
        if ($this->codigo() !== 'COBERTURA') {
            $this->avisar('Cobertura: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        return $this->aplicaciones($h);
    }

    /**
     * La cobertura aplicada A MANO por fecha, repartida por clase de fondo.
     *
     * @param Horizonte $h
     * @return array Mapa codigoSerie => serie
     */
    private function aplicaciones($h) {
        $series = [];

        foreach (array_merge(array_values(self::SERIE_POR_CLASE), [self::SERIE_TOTAL]) as $codigo) {
            $s = $h->serieVacia();
            $s['moneda_origen'] = 'ARS';
            $s['tipo_cambio'] = null;
            $s['fuera_horizonte'] = 0;
            $s['sin_fecha'] = 0;
            $s['por_fondo'] = [];
            $s['fondos'] = [];
            $s['fondos_manual'] = [];
            $series[$codigo] = $s;
        }

        $cobertura = $this->cobertura();

        foreach ($cobertura->getAvisos() as $aviso) {
            $this->avisar($aviso);
        }

        $origenes = $cobertura->origenes();

        /* CADA SERIE POR CLASE NOMBRA TODAS LAS CUENTAS ACTIVAS DE SU CLASE,
           aunque no tengan nada aplicado: es lo que le dice al motor que
           fondos aplica esa fila, y sin eso la cobertura automatica no
           sabria donde mostrar un rescate. Las inhabilitadas no: no se puede
           aplicar desde ellas, y una aplicacion vieja que las nombre entra
           igual por el bloque de abajo. */
        foreach ($origenes as $clave => $o) {
            $serie = self::serieDeClase($o['clase']);

            if ($serie !== null && !empty($o['activo'])) {
                $series[$serie]['fondos'][$clave] = $o['nombre'];
            }
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

        $huerfanas = 0;

        foreach ($val['filas'] as $a) {
            $importe = $a['IMPORTE_ARS'];

            if ($importe === null || $importe == 0) {
                continue;
            }

            $clave = $a['ORIGEN'];
            $conocida = isset($origenes[$clave]);
            $serie = $conocida ? self::serieDeClase($origenes[$clave]['clase']) : null;

            if ($serie === null) {
                $serie = self::SERIE_SIN_CLASE;
            }

            if (!$conocida && $clave !== '') {
                $huerfanas += floatval($importe);
            }

            foreach ([$serie, self::SERIE_TOTAL] as $codigo) {
                $s = &$series[$codigo];

                if ($clave !== '') {
                    $s['por_fondo'][$clave] = (isset($s['por_fondo'][$clave])
                        ? $s['por_fondo'][$clave] : 0) + $importe;

                    if ($conocida) {
                        $s['fondos'][$clave] = $origenes[$clave]['nombre'];
                    }
                }

                // Una aplicacion con fecha fuera del eje se informa, no se
                // descarta en silencio: es plata que alguien decidio mover y
                // que el tablero no esta mostrando.
                if (!$h->acumular($s, $a['FECHA'], $importe)) {
                    $s['fuera_horizonte'] += $importe;
                    unset($s);
                    continue;
                }

                if ($clave !== '') {
                    $col = $h->columna($a['FECHA']);

                    if (!isset($s['fondos_manual'][$clave][$col])) {
                        $s['fondos_manual'][$clave][$col] = ['importe' => 0.0, 'ars' => 0.0];
                    }

                    $s['fondos_manual'][$clave][$col]['importe'] += floatval($a['IMPORTE']);
                    $s['fondos_manual'][$clave][$col]['ars'] += $importe;
                }

                unset($s);
            }
        }

        /* Una aplicacion desde un fondo que no es ninguna cuenta -una clave
           vieja que la migracion no pudo mover- suma al cuadro pero no
           descuenta de nadie. Se dice, porque el disponible por fondo queda
           informado de mas. */
        if ($huerfanas != 0) {
            $this->avisar('Cobertura: $ ' . number_format($huerfanas, 2, ',', '.') . ' aplicados '
                . 'salen de un origen que no es ninguna cuenta de fondo, así que no descuentan '
                . 'del saldo de ninguna. Reasignalos desde el tablero.');
        }

        return $series;
    }

    /**
     * La serie que corresponde a una clase de cuenta, o null si la clase no es
     * un fondo (no deberia pasar: origenes() solo lista fondos).
     *
     * @param mixed $clase
     * @return string|null
     */
    public static function serieDeClase($clase) {
        $c = strtoupper(trim((string) $clase));

        return isset(self::SERIE_POR_CLASE[$c]) ? self::SERIE_POR_CLASE[$c] : null;
    }
}
