<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Proveedores.php';

/**
 * ProveedoresProvider
 * Alimenta el tablero con las cuentas a pagar locales.
 *
 * Sirve un solo codigo del registro: PROV_LOCALES.
 *
 * ES UN EGRESO, PERO DEVUELVE IMPORTES POSITIVOS. El signo lo pone el TIPO de la
 * fila y no el dato, igual que todos los demas proveedores de egresos. Ver la
 * nota de sql/cashflow_estructura.sql.
 *
 * UNA CONSULTA, VARIAS SERIES
 * ---------------------------
 * Los pendientes se leen UNA vez y se reparten en varias series. El motor pide
 * las series de un proveedor una sola vez por pedido, asi que exponer diez no
 * cuesta diez consultas.
 *
 * LAS SERIES FIJAS son las que permiten armar el cuadro sin depender del
 * maestro:
 *
 *   PAGOS              todo, sin distinguir
 *   PAGOS_OPERATIVOS   todo MENOS los proveedores marcados "Excluidos"
 *   PAGOS_EXCLUIDOS    solo los "Excluidos" (socios y movimientos que no son
 *                      deuda comercial)
 *   PAGOS_SIN_RUBRO    los que no estan en el maestro
 *
 * Con esas cuatro, sacar los Excluidos del tablero es apuntar la fila a
 * PAGOS_OPERATIVOS desde Parametros: configuracion, no codigo. Que es
 * exactamente lo que se pidio.
 *
 * LAS SERIES POR RUBRO son dinamicas: hay una por cada rubro economico que
 * exista en el maestro. No se pueden escribir en el registro porque son DATOS
 * -los escribe administracion en una planilla-, asi que el registro las pide
 * cuando las necesita. Ver CashflowRegistry y seriesDeRubro().
 *
 * Con ellas, partir la fila "Cuentas a Pagar Locales" en alquileres, impuestos,
 * logistica y mercaderia -cada una en su seccion- es agregar filas desde
 * Parametros. El motor no se toca y este proveedor tampoco.
 *
 * EL TOTAL Y SUS PARTES NO PUEDEN CONVIVIR. PAGOS trae todo y las demas son
 * aperturas suyas: activar las dos contaria dos veces el mismo importe. El
 * registro declara la relacion en 'componentes' y el validador la rechaza.
 *
 * LO VENCIDO SIN FECHA CARGADA SE INFORMA, NO SE ESCONDE
 * ------------------------------------------------------
 * Hoy son 366 vencimientos por mas de 839 millones. Entran al eje en su primer
 * dia -no hay otro lugar donde ponerlos- y el aviso dice cuanto es, para que
 * nadie lea esa columna como "hoy se paga todo esto". Ver
 * Proveedores::avisosPendientes().
 */
class ProveedoresProvider extends CashflowProvider {

    /** La serie con todo, sin abrir */
    const SERIE_TOTAL = 'PAGOS';

    /** Las aperturas fijas, que no dependen de que el maestro este cargado */
    const SERIE_OPERATIVOS = 'PAGOS_OPERATIVOS';
    const SERIE_EXCLUIDOS = 'PAGOS_EXCLUIDOS';
    const SERIE_SIN_RUBRO = 'PAGOS_SIN_RUBRO';

    protected function calcular($h) {
        if ($this->codigo() !== 'PROV_LOCALES') {
            $this->avisar('Proveedores Locales: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        $prov = new Proveedores();

        foreach ($prov->getAvisos() as $aviso) {
            $this->avisar($aviso);
        }

        $items = $prov->getPendientes($h->hoy());

        foreach (Proveedores::avisosPendientes($items) as $aviso) {
            $this->avisar($aviso);
        }

        // Un proveedor con deuda que no esta en el maestro no se clasifica, y
        // eso hay que decirlo ACA y no solo en la pestana: quien mira el tablero
        // tiene que saber que parte del importe no esta pudiendo abrirse.
        $this->avisarFaltantes($prov, $items);

        return $this->repartir($h, $items);
    }

    /**
     * Reparte los vencimientos en las series.
     *
     * @param Horizonte $h
     * @param array $items
     * @return array Mapa codigoSerie => serie
     */
    private function repartir($h, $items) {
        $series = [
            self::SERIE_TOTAL => $this->serieVacia($h),
            self::SERIE_OPERATIVOS => $this->serieVacia($h),
            self::SERIE_EXCLUIDOS => $this->serieVacia($h),
            self::SERIE_SIN_RUBRO => $this->serieVacia($h)
        ];

        foreach ($items as $item) {
            $importe = floatval($item['IMPORTE_PENDIENTE']);

            if ($importe == 0) {
                continue;
            }

            $destinos = [self::SERIE_TOTAL];

            if (!empty($item['EXCLUIDO'])) {
                $destinos[] = self::SERIE_EXCLUIDOS;
            } else {
                $destinos[] = self::SERIE_OPERATIVOS;
            }

            if (empty($item['EN_MAESTRO'])) {
                $destinos[] = self::SERIE_SIN_RUBRO;
            }

            // La serie del rubro. Se crea al vuelo: cuales existen depende de lo
            // que haya en el maestro, que es un dato.
            $rubro = $item['SERIE'];

            if ($rubro !== ProveedoresCategorias::SERIE_SIN_RUBRO) {
                if (!isset($series[$rubro])) {
                    $series[$rubro] = $this->serieVacia($h);
                }

                $destinos[] = $rubro;
            }

            foreach ($destinos as $destino) {
                // Lo que cae fuera del eje se informa, no se descarta en
                // silencio. Solo se cuenta una vez, en la serie total: las
                // aperturas informan lo mismo y el aviso saldria repetido.
                if (!$h->acumular($series[$destino], $item['Pago'], $importe)) {
                    if ($destino === self::SERIE_TOTAL) {
                        if ($item['Pago'] === null) {
                            $series[$destino]['sin_fecha'] += $importe;
                        } else {
                            $series[$destino]['fuera_horizonte'] += $importe;
                        }
                    }
                }
            }
        }

        return $series;
    }

    /** Una serie vacia con los escalares en su valor por defecto */
    private function serieVacia($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        return $serie;
    }

    /**
     * Avisa cuando hay deuda de proveedores que no estan en el maestro.
     *
     * Se nombran los tres mas grandes y se dice cuantos son en total: la lista
     * completa puede tener cien y en un aviso no se lee. El detalle esta en la
     * pestana.
     *
     * @param Proveedores $prov
     * @param array $items
     */
    private function avisarFaltantes($prov, $items) {
        $faltan = $prov->categorias()->faltantesEnMaestro($items);

        if (empty($faltan)) {
            return;
        }

        $total = 0;

        foreach ($faltan as $f) {
            $total += $f['IMPORTE'];
        }

        $nombres = [];

        foreach (array_slice($faltan, 0, 3) as $f) {
            $nombres[] = $f['RAZON_SOC'];
        }

        $this->avisar('Cuentas a Pagar Locales: ' . count($faltan) . ' proveedor(es) con deuda '
            . 'por $ ' . number_format($total, 2, ',', '.') . ' no están en el maestro, así que '
            . 'su importe no se puede abrir por rubro (' . implode(', ', $nombres)
            . (count($faltan) > 3 ? ' y otros' : '') . '). Importá la hoja "Maestro proveedores" '
            . 'actualizada.');
    }

    /**
     * Los codigos de serie por rubro que existen HOY en el maestro.
     *
     * Lo usa el registro para poder declararlas: son datos, no codigo, asi que
     * no se pueden escribir en una constante. Si el maestro no esta cargado
     * devuelve vacio y el tablero funciona igual con las series fijas.
     *
     * NO LANZA: si la tabla no existe o la base no responde, el editor de
     * estructura tiene que seguir abriendo.
     *
     * @return array Mapa codigoSerie => descripcion
     */
    public static function seriesDeRubro() {
        try {
            $categorias = new ProveedoresCategorias();
            $series = [];

            foreach ($categorias->mapa() as $m) {
                $rubro = trim((string) $m['RUBRO_ECONOMICO']);

                if ($rubro === '') {
                    continue;
                }

                $codigo = ProveedoresCategorias::serieDeRubro($rubro);

                if ($codigo === ProveedoresCategorias::SERIE_SIN_RUBRO) {
                    continue;
                }

                $series[$codigo] = 'Pagos - ' . $rubro;
            }

            ksort($series);

            return $series;
        } catch (Throwable $e) {
            return [];
        }
    }
}
