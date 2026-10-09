<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Ventas.php';
require_once __DIR__ . '/../Parametros.php';

/**
 * VentasProvider
 * Alimenta el tablero de Cashflow con las series del modulo de Ventas.
 *
 * SERIES
 *   COBRANZA            -> la caja: cobranza estimada sobre ventas futuras, ya
 *                          con el mix de medios de pago, los plazos de
 *                          acreditacion y el corrimiento a dia bancario habil
 *                          aplicados. BRUTA.
 *   COBRANZA_<CANAL>    -> lo mismo, abierto por canal, y TAMBIEN bruta. Las
 *                          cuatro siguen sumando exactamente el total, que es
 *                          lo que hace que el tablero de igual armado con la
 *                          fila total o con la apertura.
 *   NETEO_PRECHEQUEADO  -> el neteo de cheques adelantados, EN NEGATIVO y como
 *                          fila propia del tablero. Ver mas abajo.
 *   COSTO_COBRO         -> el costo de cobrar esa cobranza -comisiones de
 *                          marketplace y procesadora, y tasas de cuotas-, EN
 *                          NEGATIVO y como fila propia del tablero. Ver mas
 *                          abajo.
 *   COSTO_COBRO_<CANAL> -> lo mismo, abierto por canal, tambien en negativo.
 *                          Franquicias y Mayoristas hoy dan cero: su mix no
 *                          tiene costos cargados.
 *   VENTA               -> la venta proyectada con IVA. NO es caja: se muestra
 *                          como fila informativa (COMPUTA=0) para poder leer el
 *                          cuadro contra el Excel sin contarla dos veces.
 *   VENTA_<CANAL>       -> lo mismo, abierto por canal
 *
 * EL NETEO ES UNA FILA, NO UN DESCUENTO DENTRO DE LAS SERIES
 * Antes COBRANZA y las cuatro COBRANZA_<CANAL> salian NETAS: el neteo se
 * restaba adentro de cada serie. Ahora sale por su propia serie, en negativo, y
 * las series de cobranza volvieron a bruto.
 *
 * ESTO ES CRITICO Y ES EL UNICO MOTIVO POR EL QUE ESTE PARRAFO EXISTE: las dos
 * cosas a la vez restarian el neteo DOS VECES. Si alguien vuelve a netear
 * adentro de COBRANZA -o de las series por canal- teniendo la fila
 * NETEO_PRECHEQUEADO activa, el tablero va a mostrar de menos exactamente el
 * importe del neteo, y no hay ninguna validacion que lo detecte: las dos
 * series son legitimas por separado. La regla es una sola: el neteo se resta en
 * un solo lugar, y ese lugar es la fila.
 *
 * Se movio ahi porque el neteo es informacion que el tablero tiene que mostrar,
 * no una correccion que tenga que esconder: restado adentro de la cobranza, la
 * unica forma de saber cuanto se neteo era abrir otra pantalla.
 *
 * EL COSTO DE COBRO SIGUE EXACTAMENTE EL MISMO CRITERIO, Y POR EL MISMO
 * MOTIVO. El mix de cobro es un arbol con costo y tasa por nodo (ver
 * Class/MixCobro.php), y lo que la procesadora y el marketplace se quedan es
 * informacion que el cuadro tiene que mostrar. COBRANZA y las COBRANZA_<CANAL>
 * siguen BRUTAS y el costo sale por COSTO_COBRO, en negativo.
 *
 * Y TIENE EL MISMO PELIGRO: RESTAR EL COSTO ADENTRO DE LA COBRANZA TENIENDO
 * ADEMAS LA FILA COSTOS_COBRO ACTIVA LO DESCONTARIA DOS VECES. El tablero
 * mostraria de menos exactamente el costo de cobro, y no hay validacion que
 * lo detecte: las dos series son legitimas por separado. El costo se resta en
 * un solo lugar, y ese lugar es la fila. Por eso la pestana Ventas, que si
 * muestra el neto, lo muestra al lado del bruto y no en lugar de el.
 *
 * OJO CON MEZCLAR TOTALES Y CANALES: poner en el tablero la fila del total y
 * las de los canales al mismo tiempo cuenta dos veces el mismo importe. El
 * registro declara esa relacion en 'componentes' y el validador de la
 * estructura lo rechaza. NETEO_PRECHEQUEADO NO entra en esa relacion: no es un
 * componente de la cobranza sino una fila independiente, y declararla como
 * componente haria que el validador rechace la combinacion normal del tablero.
 * COSTO_COBRO tampoco es componente de COBRANZA, por lo mismo; SUS cuatro
 * series por canal si son componentes de ELLA.
 *
 * UNA SOLA PASADA
 * Ventas::proyectarCobranzas() resuelve venta y cobranza en el mismo recorrido,
 * asi que todas las series salen de una unica llamada. Es un calculo caro (lee
 * el historico, los indices, la participacion y el calendario bancario, y
 * recorre dia x canal x medio de pago), y por eso el motor pide las series de
 * un proveedor una sola vez por pedido y no una vez por fila.
 *
 * Se le inyecta el eje del tablero para que las series caigan exactamente en
 * las mismas columnas sobre las que consolida el resto del Cashflow.
 *
 * NO se cruza con COBRANZAS_FR: ese modulo trae cobranza REAL de facturas ya
 * emitidas y este proyecta cobranza de ventas FUTURAS. Se suman a proposito.
 * El invariante esta enunciado en el encabezado de Class/Ventas.php:
 *   Cobranza total = cobranza real + cobranza sobre ventas estimadas
 */
class VentasProvider extends CashflowProvider {

    protected function calcular($h) {
        $ventas = new Ventas();

        // El eje se inyecta: ver la nota del encabezado.
        $p = $ventas->proyectarCobranzas($h);

        // Los avisos del motor de Ventas (por ejemplo una fecha que falta en el
        // calendario bancario) tienen que llegar al tablero: si se perdieran,
        // el usuario veria numeros sin saber que se estimaron.
        //
        // Con el nivel que les puso Ventas y sin el "Ventas:" de antes: el
        // tablero los agrupa por pestana.
        $this->avisarTodos(isset($p['avisos_con_nivel']) ? $p['avisos_con_nivel']
            : (isset($p['warnings']) ? $p['warnings'] : []));

        return self::seriesDesde($p);
    }

    /**
     * Las series del tablero a partir del payload de Ventas::proyectarCobranzas().
     *
     * Estatica y pura, separada de calcular() para poder probar los signos y
     * las aperturas sin base: es donde un error no se ve en el cuadro.
     *
     * @param array $p Payload de Ventas::proyectarCobranzas()
     * @return array Mapa serie => ['dias' => [...], 'meses' => [...]]
     */
    public static function seriesDesde($p) {
        $series = [
            'COBRANZA' => [
                'dias' => $p['cobranza']['total_dias'],
                'meses' => $p['cobranza']['total_meses']
            ],
            'NETEO_PRECHEQUEADO' => self::neteo($p),
            'COSTO_COBRO' => self::costo($p, null),
            'VENTA' => [
                'dias' => $p['venta']['total_dias'],
                'meses' => $p['venta']['total_meses']
            ]
        ];

        /* ---- Apertura por canal ------------------------------------------ */
        // La cobranza por canal sale de los subtotales que ya calcula el motor
        // de Ventas; la venta por canal, de su grilla por canal.
        $canales = isset($p['canales']) ? $p['canales'] : Parametros::CANALES;

        foreach ($canales as $canal) {
            // BRUTA, igual que el total: el neteo sale por su propia fila. Si
            // se restara aca ademas de en NETEO_PRECHEQUEADO se contaria dos
            // veces, y la apertura por canal dejaria de sumar el total.
            $series['COBRANZA_' . $canal] = [
                'dias' => self::porCanal($p['cobranza'], 'subtotal_dias', $canal),
                'meses' => self::porCanal($p['cobranza'], 'subtotal_meses', $canal)
            ];

            $series['COSTO_COBRO_' . $canal] = self::costo($p, $canal);

            $series['VENTA_' . $canal] = [
                'dias' => self::porCanal($p['venta'], 'dias', $canal),
                'meses' => self::porCanal($p['venta'], 'meses', $canal)
            ];
        }

        return $series;
    }

    /**
     * @param array $grilla Bloque 'venta' o 'cobranza' del payload
     * @param string $rama Nombre de la rama por canal
     * @param string $canal
     * @return array Mapa clave => importe, o vacio si el canal no esta
     */
    private static function porCanal($grilla, $rama, $canal) {
        return isset($grilla[$rama][$canal]) ? $grilla[$rama][$canal] : [];
    }

    /**
     * El neteo de cheques adelantados, listo para ser una fila del tablero.
     *
     * EL ORIGEN: los cheques marcados en Echeqs -> Venta Cobrada Anticipada.
     * Son ventas que el cliente ya pago con echeqs entregados por adelantado, y
     * que por lo tanto no se van a volver a cobrar. Ver
     * Ventas::getNeteoPrechequeado().
     *
     * EL SIGNO SE INVIERTE ACA. Ventas devuelve los importes en POSITIVO -es
     * "cuanto hay que restar"- y esta serie los devuelve en NEGATIVO, porque la
     * fila del tablero es de TIPO='INGRESO' y el motor suma los ingresos. Una
     * fila de ingreso con importe negativo resta; una con importe positivo
     * sumaria el neteo a la cobranza, que es exactamente al reves. El signo se
     * da vuelta en un solo lugar, que es este.
     *
     * LLEVA EL TOTAL DE TODOS LOS CANALES, no solo el de franquicias. Hoy todo
     * el neteo es de franquicias -son las que operan con pre-chequeado- pero eso
     * es un hecho del padron de clientes, no una regla del modulo: en cuanto un
     * mayorista entregue cheques por adelantado, su neteo tiene que aparecer en
     * esta fila sin que nadie toque codigo. Por eso se toma el total que reparte
     * Ventas::repartirNeteo() y no la rama de un canal.
     *
     * Y por eso mismo NO hay una serie NETEO_PRECHEQUEADO_<CANAL>: la fila es
     * una sola, va abajo del bloque de Ventas y corrige el bloque entero. Si
     * alguna vez hace falta abrirla por canal, el dato ya viaja en
     * $p['cobranza']['neteo_prechequeado']['canales'].
     *
     * @param array $p Payload de Ventas::proyectarCobranzas()
     * @return array ['dias' => [...], 'meses' => [...]]
     */
    private static function neteo($p) {
        $neteo = isset($p['cobranza']['neteo_prechequeado'])
            ? $p['cobranza']['neteo_prechequeado']
            : ['dias' => [], 'meses' => []];

        return [
            'dias' => self::enNegativo($neteo['dias']),
            'meses' => self::enNegativo($neteo['meses'])
        ];
    }

    /**
     * El costo de cobro, listo para ser una fila del tablero: el total o el de
     * un canal.
     *
     * EL SIGNO SE INVIERTE ACA, con enNegativo(), por el mismo motivo que el
     * neteo: Ventas lo devuelve POSITIVO -es "cuanto se queda quien cobra"- y
     * la fila es de TIPO='INGRESO', asi que tiene que llegar negativa para
     * restar. Ver neteo().
     *
     * SIN COSTO EN EL PAYLOAD, CERO Y NO UN HUECO: la fila tiene que dar cero,
     * no faltar.
     *
     * @param array $p Payload de Ventas::proyectarCobranzas()
     * @param string|null $canal null para el total
     * @return array ['dias' => [...], 'meses' => [...]]
     */
    private static function costo($p, $canal) {
        if (!isset($p['cobranza']['costo'])) {
            return ['dias' => [], 'meses' => []];
        }

        $costo = $p['cobranza']['costo'];

        if ($canal === null) {
            return [
                'dias' => self::enNegativo($costo['total_dias']),
                'meses' => self::enNegativo($costo['total_meses'])
            ];
        }

        return [
            'dias' => self::enNegativo(self::porCanal($costo, 'subtotal_dias', $canal)),
            'meses' => self::enNegativo(self::porCanal($costo, 'subtotal_meses', $canal))
        ];
    }

    /**
     * Da vuelta el signo de un mapa clave => importe.
     *
     * Va publica y estatica -como Ventas::repartirNeteo()- por el mismo motivo:
     * es lo unico delicado de esta serie. Invertir mal el signo hace que el
     * tablero SUME el neteo en vez de restarlo, y el cuadro sigue dando un
     * numero razonable, asi que no hay forma de que se note mirando. Probarlo
     * sin esto obligaria a tener base con cheques marcados.
     *
     * El cero se deja en cero y no en -0: un -0 no rompe ninguna cuenta, pero
     * llega tal cual al JSON y se ve en pantalla, y una columna sin neteo tiene
     * que mostrarse vacia, no con un cero negativo.
     *
     * @param array $mapa
     * @return array
     */
    public static function enNegativo($mapa) {
        foreach ($mapa as $clave => $monto) {
            $mapa[$clave] = ($monto == 0) ? 0 : -$monto;
        }

        return $mapa;
    }
}
