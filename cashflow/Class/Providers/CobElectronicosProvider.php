<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../CobElectronicos.php';

/**
 * CobElectronicosProvider
 * Alimenta la fila "Cobranzas Pagos Electronicos" (seccion DISPONIBILIDADES) con
 * las acreditaciones que las procesadoras de pago van a depositar en el banco.
 *
 * Codigo COB_ELECTRONICOS, serie COBRANZA, moneda de origen ARS y sin tipo de
 * cambio: los importes ya estan en pesos, asi que no hay ninguna conversion que
 * hacer ni que auditar.
 *
 * SUMA NETOS, NUNCA BRUTOS
 * ------------------------
 * El bruto es lo que la procesadora informa; el neto es lo que entra a la
 * cuenta. La diferencia son las retenciones impositivas de la procesadora, que
 * nunca llegan al banco. Un tablero que sume brutos proyecta plata que no va a
 * existir. La formula vive en CobElectronicos::importeNeto() y el neto viaja
 * PERSISTIDO en el movimiento, con la tasa con la que se calculo.
 *
 * UN MOVIMIENTO CON FECHA ANTERIOR AL EJE NO ABRE EL HORIZONTE
 * ------------------------------------------------------------
 * Es lo inverso a lo que hace SaldosProvider, y es a proposito.
 * Saldos::destinoEnEje() reubica un saldo viejo en la primera columna porque un
 * saldo describe PLATA QUE EXISTE AHORA. Una acreditacion con fecha pasada es lo
 * contrario: es un movimiento YA OCURRIDO, esa plata ya esta en la cuenta y ya
 * la informa el saldo bancario de la pestana Saldos. Reubicarla en la apertura
 * del horizonte la contaria dos veces.
 *
 * Entonces queda FUERA del eje, se suma a 'fuera_horizonte' y se avisa con el
 * importe y la fecha; nunca se descarta en silencio. Igual se ve en la pantalla,
 * en su fila, marcada. El criterio esta en CobElectronicos::ubicacionEnEje().
 *
 * ESTA FILA NO SE CRUZA CON VENTAS
 * --------------------------------
 * Por decision del negocio no se solapa ni se ajusta contra "Cobros s/ ventas
 * estimadas". No hay deduccion, prorrateo ni exclusion cruzada entre las dos
 * filas.
 *
 * NUNCA TUMBA EL TABLERO
 * ----------------------
 * calcular() puede lanzar; series() lo envuelve. Ademas se atrapan los casos
 * propios para poder rendir cero CON UN AVISO QUE DIGA QUE PASO, en lugar del
 * mensaje generico de la clase base: el script SQL sin correr, ninguna
 * procesadora cargada, procesadoras que perdieron sus alicuotas vigentes y
 * movimientos fuera del horizonte.
 */
class CobElectronicosProvider extends CashflowProvider {

    protected function calcular($h) {
        return ['COBRANZA' => $this->cobranza($h, $this->modulo())];
    }

    /**
     * El modulo del que salen los datos.
     *
     * Es una costura para poder probar: lo delicado de este proveedor son los
     * casos en los que tiene que rendir CERO CON UN AVISO -tablas sin crear,
     * ninguna procesadora, ningun movimiento- y esos casos no se pueden montar
     * en una base real sin borrar las tablas. Con el metodo separado, la prueba
     * pasa un doble y verifica el aviso sin base.
     *
     * Es la misma idea que el Horizonte inyectado de Cashflow: sin la costura,
     * la parte mas facil de romper en silencio se queda sin red.
     *
     * @return CobElectronicos
     */
    protected function modulo() {
        return new CobElectronicos();
    }

    /**
     * Serie COBRANZA: suma de netos por fecha de acreditacion.
     *
     * @param Horizonte $h
     * @param CobElectronicos $modulo
     * @return array Serie
     */
    private function cobranza($h, $modulo) {
        if (!$modulo->tablasCreadas()) {
            $this->avisar('Cobranzas Pagos Electronicos: todavia no existen las tablas del '
                . 'modulo, asi que la fila se muestra en cero. Corre '
                . 'sql/cashflow_cob_electronicos.sql.');

            return $this->vacia();
        }

        $procesadoras = $modulo->getProcesadoras(false);

        if (empty($procesadoras)) {
            $this->avisar('Cobranzas Pagos Electronicos: todavia no hay ninguna procesadora '
                . 'cargada, asi que la fila va en cero. Cargalas en Parametros -> '
                . 'Cob. Electronicos.');

            return $this->vacia();
        }

        $movimientos = $modulo->getMovimientos();

        // Un cero no dice si no hay movimientos o si los que hay no entran al
        // eje. Se distingue, igual que hace ComexProvider con las
        // nacionalizaciones.
        if (empty($movimientos)) {
            $this->avisar('Cobranzas Pagos Electronicos: hay ' . count($procesadoras)
                . ' procesadora(s) configuradas pero ningun movimiento cargado todavia, asi que '
                . 'la fila va en cero. Cargalos en la pestana Cob. Electronicos.');

            return $this->vacia();
        }

        $this->avisarSinAlicuotas($modulo, $movimientos);

        $armado = CobElectronicos::armarMovimientos($movimientos, $h);
        $serie = CobElectronicos::armarSerie($armado['filas'], $h);

        // Los avisos de la serie ya vienen con el importe y la fecha de lo que
        // quedo afuera: se levantan al tablero para que un total mas chico que
        // el de la pestana tenga explicacion en la misma pantalla.
        foreach ($serie['warnings'] as $w) {
            $this->avisar($w);
        }

        $serie['warnings'] = [];

        if (array_sum($serie['dias']) == 0 && array_sum($serie['meses']) == 0) {
            $this->avisar('Cobranzas Pagos Electronicos: los ' . count($movimientos)
                . ' movimientos cargados quedan todos fuera del horizonte, asi que la fila va en '
                . 'cero. Los que ya se acreditaron estan informados en el saldo bancario de la '
                . 'pestana Saldos.');
        }

        return $serie;
    }

    /**
     * Avisa si algun movimiento pertenece a una procesadora que perdio sus
     * alicuotas vigentes.
     *
     * El movimiento igual aporta su neto persistido -que es el que se informo- y
     * eso esta bien; lo que hay que saber es que a partir de ahora esa
     * procesadora no puede calcular nada nuevo, porque si no el error aparece
     * recien cuando alguien intenta cargar un movimiento.
     *
     * @param CobElectronicos $modulo
     * @param array $movimientos
     */
    private function avisarSinAlicuotas($modulo, $movimientos) {
        try {
            $alicuotas = $modulo->getAlicuotasPorProcesadora();
        } catch (Throwable $e) {
            $this->avisar('Cobranzas Pagos Electronicos: no se pudieron leer las alicuotas ('
                . $e->getMessage() . '). Los movimientos suman igual, con el neto que ya tenian '
                . 'guardado.');

            return;
        }

        $sinAlicuota = [];

        foreach ($movimientos as $m) {
            $idProc = intval($m['id_procesadora']);

            $r = CobElectronicos::tasaRetencion(
                isset($alicuotas[$idProc]) ? $alicuotas[$idProc] : [],
                $m['fecha_acreditacion']
            );

            if ($r['conceptos'] === 0) {
                $sinAlicuota[$m['procesadora']] = true;
            }
        }

        if (empty($sinAlicuota)) {
            return;
        }

        $nombres = array_keys($sinAlicuota);
        sort($nombres);

        $this->avisar('Cobranzas Pagos Electronicos: hay movimientos de ' . implode(', ', $nombres)
            . ' cuya procesadora no tiene alicuotas vigentes a su fecha de acreditacion. Suman '
            . 'con el neto que ya tenian guardado, pero no se van a poder cargar movimientos '
            . 'nuevos hasta que se le cargue una alicuota.');
    }

    /** Serie en cero. series() le completa las claves del eje y los escalares. */
    private function vacia() {
        return ['dias' => [], 'meses' => [], 'moneda_origen' => 'ARS'];
    }
}
