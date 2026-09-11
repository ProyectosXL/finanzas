<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Ingresos.php';
require_once __DIR__ . '/../Cotizacion.php';

/**
 * ExportacionesProvider
 * Alimenta el tablero con la cobranza de las facturas de exportacion a Tasky.
 *
 * Tasky es la razon social del grupo en Uruguay: mismo grupo, otra empresa. Se
 * le factura en DOLARES y sus facturas pendientes de GVA12 son cobranza a
 * proyectar. Sirve un solo codigo del registro:
 *   EXPORTACIONES -> serie COBRANZA, ubicada en la fecha de cobro estimada
 *                    (FECHA_EMIS + exportaciones_tasky_dias_cobro).
 *
 * LA CONVERSION VIVE ACA, NO EN EL MOTOR
 * --------------------------------------
 * El motor nunca ve dolares: todos los proveedores le entregan pesos. Ingresos
 * transporta dolares y este proveedor los convierte, igual que ComexProvider
 * con los pagos al exterior.
 *
 * TODAS LAS FACTURAS SE VALUAN A DOLAR DE HOY. ES DELIBERADO.
 * -----------------------------------------------------------
 * La cotizacion es UNA sola para toda la serie: el cierre del mes en curso de
 * RO_V_DOLAR_OFICIAL_BCRA, o sea la ultima disponible
 * (Cotizacion::delMes(anio actual, mes actual)). NO se valua cada factura al
 * tipo de cambio del mes en que se va a cobrar.
 *
 * Esto SE APARTA de la doctrina de Class/Cotizacion.php -"cada mes se valua a
 * su propio tipo de cambio"- y de lo que hace OtrosIngresosProvider. Esa
 * doctrina aplica a series de venta HISTORICA, donde cada mes ya ocurrio y
 * tiene su cotizacion de cierre. Aca la deuda esta fija en dolares y el cobro
 * es FUTURO: no hay cotizacion de ese mes, y proyectar una seria suponer una
 * devaluacion. Valuar a hoy es no suponerla, que es el criterio conservador
 * que se pidio. Si alguien cambia esto a "cotizacion del mes de cobro", esta
 * metiendo una hipotesis de devaluacion en el tablero. No lo "arregles".
 *
 * SIN COTIZACION, LA FILA VA EN CERO Y SE AVISA
 * ---------------------------------------------
 * No se asume ningun valor -ni el del mes anterior ni un parametro-: el aviso
 * dice cuantos dolares hay sin valuar. Mostrar la plata sin decir a cuanto se
 * valuo es peor que no mostrarla.
 *
 * LAS FACTURAS VENCIDAS ENTRAN, EN HOY, Y SE MARCAN
 * -------------------------------------------------
 * Una factura cuya fecha estimada ya paso es una factura vencida sin cobrar:
 * informacion, no un error a esconder. Ingresos la ubica en hoy -el primer dia
 * del eje- y este proveedor la anota en 'detalle' sobre esa celda, para que el
 * tablero distinga lo que de verdad se estima cobrar hoy de lo que ya deberia
 * haber entrado. Ademas deja un aviso con el total.
 *
 * COTIZ e IMPORTE de GVA12 (como se facturo) no se usan para nada: son
 * referencia historica de la pestana.
 */
class ExportacionesProvider extends CashflowProvider {

    protected function calcular($h) {
        if ($this->codigo() !== 'EXPORTACIONES') {
            $this->avisar('Exportaciones: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        return ['COBRANZA' => $this->cobranza($h)];
    }

    /**
     * De donde salen los datos. Es una costura para las pruebas: la regla de
     * valuacion y la de las vencidas se prueban con un Ingresos falso que
     * devuelve filas conocidas, sin base. Misma idea que el Horizonte
     * inyectado del motor.
     *
     * @return Ingresos
     */
    protected function ingresos() {
        return new Ingresos();
    }

    /**
     * La cobranza estimada por fecha, convertida de dolares a pesos de hoy.
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function cobranza($h) {
        $ingresos = $this->ingresos();
        $cotiz = $ingresos->getCotizacionHoy();
        $filas = $ingresos->getExportacionesTaskyTotales();

        $usdTotal = 0.0;
        $usdVencidas = 0.0;
        $compVencidos = 0;

        foreach ($filas as $f) {
            $usdTotal += floatval($f['IMPORTE_USD']);
            $usdVencidas += floatval($f['VENCIDAS_USD']);
            $compVencidos += intval($f['COMP_VENCIDOS']);
        }

        if ($cotiz === null) {
            if ($usdTotal != 0) {
                $this->avisar('Exportaciones Tasky: no hay cotizacion del dolar oficial BCRA para el '
                    . 'mes en curso (' . Cotizacion::VISTA . '), asi que la fila se muestra en cero. '
                    . 'Hay ' . self::usd($usdTotal) . ' pendientes de cobro; lo que falta es a '
                    . 'cuanto valuarlos. No se asume ningun tipo de cambio.');
            }

            return [
                'dias' => [],
                'meses' => [],
                'moneda_origen' => 'USD',
                'tipo_cambio' => null
            ];
        }

        $serie = $h->agrupar($filas, 'FECHA', 'IMPORTE_USD', $cotiz);
        $serie['moneda_origen'] = 'USD';
        $serie['tipo_cambio'] = $cotiz;
        $serie['detalle'] = $this->detalleVencidas($h, $filas, $cotiz);

        if ($compVencidos > 0) {
            $this->avisar('Exportaciones Tasky: ' . $compVencidos . ' factura'
                . ($compVencidos === 1 ? '' : 's') . ' por ' . self::usd($usdVencidas)
                . ' ' . ($compVencidos === 1 ? 'tiene' : 'tienen') . ' la fecha de cobro estimada '
                . 'ya vencida y se ' . ($compVencidos === 1 ? 'ubica' : 'ubican')
                . ' en el primer dia del eje. ' . ($compVencidos === 1 ? 'Es una factura vencida' : 'Son facturas vencidas')
                . ' sin cobrar, no cobranza estimada para hoy.');
        }

        return $serie;
    }

    /**
     * Que parte del importe de cada columna corresponde a facturas VENCIDAS,
     * ubicadas en hoy porque su fecha estimada ya paso.
     *
     * Se agrupa con el MISMO Horizonte::agrupar() y el mismo factor que el
     * importe, asi que la parte vencida cae siempre en la misma columna que el
     * total al que anota. Es el mismo mecanismo que usa IngresosProvider para
     * la cobranza pactada a mano.
     *
     * @param Horizonte $h
     * @param array $filas Filas de Ingresos::getExportacionesTaskyTotales()
     * @param float $cotiz
     * @return array Mapa columna => ['importe', 'nota']
     */
    private function detalleVencidas($h, $filas, $cotiz) {
        $importes = $h->agrupar($filas, 'FECHA', 'VENCIDAS_USD', $cotiz);
        $comprobantes = $h->agrupar($filas, 'FECHA', 'COMP_VENCIDOS');

        $detalle = [];

        foreach (['dias' => 'DIA|', 'meses' => 'MES|'] as $rama => $prefijo) {
            foreach ($importes[$rama] as $clave => $importe) {
                if ($importe == 0) {
                    continue;
                }

                $cant = intval($comprobantes[$rama][$clave]);

                $detalle[$prefijo . $clave] = [
                    'importe' => $importe,
                    'nota' => self::plata($importe) . ' de esta celda (' . $cant . ' factura'
                        . ($cant === 1 ? '' : 's') . ' a Tasky) corresponden a facturas cuya fecha '
                        . 'de cobro estimada YA VENCIO: se ubican aca por ser el primer dia del eje, '
                        . 'no porque se estime cobrarlas hoy. Ver Ingresos -> Exportaciones Tasky.'
                ];
            }
        }

        return $detalle;
    }

    /** Formato de importe en pesos para las notas */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** Formato de importe en dolares para los avisos */
    private static function usd($n) {
        return 'USD ' . number_format(floatval($n), 2, ',', '.');
    }
}
