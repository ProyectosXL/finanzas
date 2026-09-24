<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Parametros.php';
require_once __DIR__ . '/../Comex.php';
require_once __DIR__ . '/../DolarFuturo.php';
require_once __DIR__ . '/../ComprasProyectadas.php';
require_once __DIR__ . '/../ComprasProyectadasDatos.php';

/**
 * ComprasProyectadasProvider
 * Alimenta el tablero con los pagos de las compras del exterior que TODAVIA NO
 * TIENEN CONTENEDOR cargado en Comercio Exterior.
 *
 * Sirve un solo codigo -COMPRAS_PROY- con DOS series, en dolares:
 *
 *   PAGOS_PROYECTADOS          el FOB estimado, en la fecha de pago
 *   NACIONALIZACION_PROYECTADA los gastos de nacionalizacion, en su fecha
 *
 * NUNCA SE SUMAN A LAS SERIES DE ComexProvider, Y ESO ES EL DISENIO ENTERO
 * ------------------------------------------------------------------------
 * Son dos proveedores, dos pares de series y cuatro filas del tablero, porque
 * miden dos universos que no se pisan:
 *
 *   ComexProvider           lo que YA tiene contenedor, ubicado por las fechas
 *                           del maestro de Comercio Exterior, contenedor por
 *                           contenedor.
 *   este proveedor          lo que TODAVIA NO, repartido por la cuota historica
 *                           sobre lo que falta comprar del presupuesto oficial.
 *
 * Y no se pisan porque la estimacion de cada mes ya viene NETA de lo cargado:
 * ComprasProyectadas::estimar() resta, mes de pago por mes de pago, el
 * pendiente de los contenedores del maestro cuya orden de compra se emitio
 * despues de la fecha de calculo del presupuesto. Lo anterior a esa fecha ya
 * esta descontado adentro del presupuesto mismo -el stock proyectado de la app
 * de compras incluye las OC pendientes- asi que restarlo seria contarlo dos
 * veces.
 *
 * En el tablero cada par vive en un GRUPO -PROV_EXTERIOR y NACIONALIZACIONES-
 * que dibuja un renglon con la suma y se abre en sus dos partes. Eso es
 * presentacion: el motor no sabe que los grupos existen.
 *
 * LA VALUACION ES LA DE COMEX, Y CADA SERIE MIRA SU PROPIA FECHA
 * --------------------------------------------------------------
 * Se reusa Comex::valuar() con la curva de dolar futuro ROFEX, igual que las
 * dos pestanas de Comercio Exterior. El FOB se valua con el mes de la FECHA DE
 * PAGO y la nacionalizacion con el mes de la SUYA: es la misma compra, pero los
 * dos egresos se mueven en momentos distintos, asi que les toca un punto
 * distinto de la curva. Si la nacionalizacion mirara la fecha de pago quedaria
 * valuada con el dolar de un mes en el que no se mueve.
 *
 * Los mismos avisos de mes fuera de curva, escritos una sola vez en
 * Comex::avisosValuacion().
 *
 * SI FALLA ALGO, LA FILA VA EN CERO Y EL AVISO DICE QUE ES DE MENOS
 * -----------------------------------------------------------------
 * La degradacion mas grave es que falte un insumo materializado -la historia
 * de recepciones o el presupuesto oficial, que llenan dos SP-: sin ellos no hay
 * nada que proyectar. El aviso va PRIMERO, dice que se esta proyectando de
 * MENOS, no solo que falta algo, y nombra el job que falta correr. Una fila de egresos en cero se lee como "no
 * hay que pagar nada", que es lo contrario de lo que pasa. Mismo criterio que
 * Comex::avisoSinPagosComex().
 */
class ComprasProyectadasProvider extends CashflowProvider {

    /** Solo Argentina: Uruguay tiene su propia base y su propio presupuesto */
    const PAIS = 'argentina';

    /**
     * Valores por defecto de los parametros del modulo.
     *
     * ESTAN ACA ADEMAS DE EN EL SCRIPT porque sin el script la fila tiene que
     * seguir dando un numero razonable y avisando, en vez de tumbar el modulo
     * con "Falta el parametro" -que es lo que hace Parametros::num()-. Los
     * valores son los mismos que siembra sql/cashflow_compras_proyectadas.sql y
     * el porque de cada uno esta escrito ahi.
     */
    const DEFAULTS = [
        'compras_proy_meses' => 6,
        'compras_proy_anios_cuota' => 3,
        'compras_proy_base_cuota' => 'IMPORTE',
        'compras_proy_dia_llegada' => 15,
        'compras_proy_dias_pago' => 47,
        'compras_proy_dias_nac' => 2,
        'compras_proy_nac_pct' => 89
    ];

    /** @var array Lo ultimo que calculo, para que la pestana lo pueda mostrar */
    private $ultimaGrilla = null;

    /** @var ComprasProyectadasDatos|null La unica instancia de lecturas del pedido */
    private $datos = null;

    /**
     * Las lecturas del modulo, UNA instancia por proveedor.
     *
     * ES PUBLICA PARA QUE EL CONTROLLER USE LA MISMA. Sus caches -que tablas
     * existen, el estado del log, si hay tabla de pagos o de ajustes- son por
     * instancia: con una segunda, la pestana volvia a preguntar todo. Medido
     * el 24/09/2026, el contraste y la existencia de la vista se leian dos
     * veces por pedido de la pestana, por eso.
     *
     * @return ComprasProyectadasDatos
     */
    public function datos() {
        if ($this->datos === null) {
            $this->datos = new ComprasProyectadasDatos;
        }

        return $this->datos;
    }

    /**
     * Reemplaza las lecturas. Para las pruebas, que ejercitan la degradacion
     * sin tablas sin tener que borrar ninguna.
     *
     * @param ComprasProyectadasDatos $datos
     * @return $this
     */
    public function conDatos($datos) {
        $this->datos = $datos;
        $this->ultimaGrilla = null;

        return $this;
    }

    protected function calcular($h) {
        if ($this->codigo() !== 'COMPRAS_PROY') {
            $this->avisar('Compras Exterior: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        $datos = $this->datos();

        /* --- Las degradaciones, en orden de gravedad --------------------- */

        /* SIN LOS INSUMOS MATERIALIZADOS LA FILA VA EN CERO, y el aviso va
           PRIMERO -antes incluso que el de parametros faltantes- y dice que se
           proyecta DE MENOS. No hay vuelta a la consulta en vivo: ver el
           encabezado de ComprasProyectadasDatos. */
        $aviso = $datos->avisoFaltaJob();

        if ($aviso !== '') {
            $this->avisar('Compras Exterior: ' . $aviso);

            return $this->seriesVacias();
        }

        $params = $this->parametros();

        /* Los insumos estan, pero pueden estar viejos. No cambian ningun
           numero: dicen de cuando es el que se esta mostrando. */
        foreach ($datos->avisosInsumos($params['compras_proy_anios_cuota'], $h->hoy(), self::PAIS) as $a) {
            $this->avisar('Compras Exterior: ' . $a);
        }

        $dolar = new DolarFuturo;

        if (!$dolar->disponible()) {
            $this->avisar('Compras Exterior: no se pudo leer la curva de dólar futuro ROFEX ('
                . DolarFuturo::ORIGEN . '), así que las compras proyectadas van en cero. Los '
                . 'importes en dólares están: lo que falta es a cuánto convertirlos. '
                . ($dolar->error() === null ? '' : $dolar->error()));

            return $this->seriesVacias();
        }

        foreach ([$datos->avisoSinPagos(), $datos->avisoSinAjustes()] as $a) {
            if ($a !== '') {
                $this->avisar('Compras Exterior: ' . $a);
            }
        }

        $contraste = ComprasProyectadasDatos::avisoContraste($datos->contrasteVista(self::PAIS));

        if ($contraste !== '') {
            $this->avisar('Compras Exterior: ' . $contraste);
        }

        /* --- La cuenta ---------------------------------------------------- */
        $grilla = $this->grilla($h, $datos, $params, $dolar);

        foreach ($grilla['warnings'] as $w) {
            $this->avisar('Compras Exterior: ' . $w);
        }

        /* --- Las dos series ----------------------------------------------- */
        $filas = $grilla['filas'];

        $pagos = $h->agrupar($filas, 'FECHA_PAGO', 'IMPORTE_FOB_ARS');
        $nac = $h->agrupar($filas, 'FECHA_NAC', 'IMPORTE_NAC_ARS');

        $pagos['moneda_origen'] = 'USD';
        $nac['moneda_origen'] = 'USD';

        $pagos['tipo_cambio'] = self::tipoCambioUnico($filas, 'COTIZ_FOB');
        $nac['tipo_cambio'] = self::tipoCambioUnico($filas, 'COTIZ_NAC');

        $pagos['detalle'] = $this->detalle($h, $filas, 'FECHA_PAGO', 'IMPORTE_FOB_ARS');
        $nac['detalle'] = $this->detalle($h, $filas, 'FECHA_NAC', 'IMPORTE_NAC_ARS');

        /* Los avisos de valuacion de cada serie, sobre SUS filas valuadas. Se
           cuentan MESES y no contenedores: la unidad de este modulo es un mes
           de la ventana, y el texto de Comex lo recibe por parametro. */
        foreach (Comex::avisosValuacion($grilla['filas_fob'], $dolar->ultimoMes(), 'FOB_USD',
                 'fecha de pago estimada', 'mes(es) proyectado(s)') as $a) {
            $this->avisar('Compras Exterior: ' . $a);
        }

        foreach (Comex::avisosValuacion($grilla['filas_nac'], $dolar->ultimoMes(), 'NAC_USD',
                 'fecha de nacionalización estimada', 'mes(es) proyectado(s)') as $a) {
            $this->avisar('Compras Exterior (nacionalización): ' . $a);
        }

        /* LO QUE CAE FUERA DEL EJE, NOMBRADO. El motor ya informa
           'fuera_horizonte', pero como un numero suelto al pie del tablero; acá
           hay una causa concreta y predecible que conviene decir, porque es
           estructural y no un dato mal cargado:

             la ventana se corta con el ultimo mes cuyo PAGO entra en el eje,
             y la nacionalizacion va solo Y dias antes de la recepcion -hoy 2-
             mientras que el pago va X -hoy 47-. Los ultimos meses de la ventana
             aportan su FOB y NO su nacionalizacion.

           Sin esto, la fila de nacionalizacion proyectada se ve mas chica que
           la de pagos y no hay nada en pantalla que lo explique. */
        $this->avisarFueraDelEje($pagos, 'los pagos de FOB');
        $this->avisarFueraDelEje($nac, 'la nacionalización');

        return [
            'PAGOS_PROYECTADOS' => $pagos,
            'NACIONALIZACION_PROYECTADA' => $nac
        ];
    }

    /**
     * La grilla de cobertura completa, con cada mes ya valuado.
     *
     * ES PUBLICA Y SE PUEDE PEDIR SIN EL TABLERO: la pestana muestra
     * exactamente esto, y tiene que ser el MISMO calculo. Con la cuenta en dos
     * lados, la pestana y el tablero podrian mostrar dos estimaciones distintas
     * del mismo mes, que es el problema que Comex resolvio moviendo la
     * valuacion al getter.
     *
     * @param Horizonte $h
     * @param ComprasProyectadasDatos|null $datos
     * @param array|null $params
     * @param DolarFuturo|null $dolar
     * @return array ['filas','meses','warnings','notas','totales','ventana','cuota',
     *                'versiones','parametros']
     */
    public function grilla($h, $datos = null, $params = null, $dolar = null) {
        /* SE CALCULA UNA SOLA VEZ POR PEDIDO. La pestana necesita la grilla Y
           las series -los totales del eje no se pueden sumar de la grilla,
           porque hay meses cuya nacionalizacion cae fuera del horizonte- y
           series() vuelve a pasar por aca. Sin la cache, un solo pedido leia
           dos veces el presupuesto, la historia de recepciones y el maestro de
           Comex. Es el mismo criterio que el cache de CashflowProvider::series().*/
        if ($this->ultimaGrilla !== null) {
            return $this->ultimaGrilla;
        }

        $datos = ($datos === null) ? $this->datos() : $datos;
        $params = ($params === null) ? $this->parametros() : $params;
        $dolar = ($dolar === null) ? new DolarFuturo : $dolar;

        $curva = $dolar->curva();

        /* --- La ventana, derivada del eje y de los parametros ------------- */
        $ventana = ComprasProyectadas::ventana(
            substr($h->hoy(), 0, 7),
            $h->fin(),
            $params['compras_proy_meses'],
            $params['compras_proy_dia_llegada'],
            $params['compras_proy_dias_pago'],
            $params['compras_proy_dias_nac']
        );

        /* --- La cuota, de la historia de recepciones ---------------------- */
        $historia = $datos->historiaRecepciones($params['compras_proy_anios_cuota'], $h->hoy());
        $cuota = ComprasProyectadas::cuota(
            ComprasProyectadasDatos::conPeso($historia, $params['compras_proy_base_cuota']),
            ComprasProyectadasDatos::aniosDeLaCuota($params['compras_proy_anios_cuota'], $h->hoy())
        );

        /* --- El presupuesto y lo ya comprado ------------------------------ */
        $versiones = $datos->versionesOficiales(self::PAIS);

        $r = ComprasProyectadas::estimar($ventana, $cuota, $versiones, $datos->cargado(), [
            'ajustes' => $datos->ajustes(),
            'nac_pct' => $params['compras_proy_nac_pct'],
            /* La curva dice que meses PUEDE valuar. Con eso, un mes cuyo pago
               cae fuera de la curva queda marcado SIN_COTIZACION en la grilla,
               ademas de valuarse con el mes mas cercano. */
            'meses_cotizacion' => self::mesesDeLaCurva($curva)
        ]);

        /* --- La valuacion, fila por fila y por SU fecha ------------------- */
        $filas = [];

        /* LAS DOS VALUACIONES SE GUARDAN TAMBIEN ENTERAS Y POR SEPARADO.
           Comex::avisosValuacion() lee COTIZ_USD y COTIZ_ORIGEN, que son los
           nombres que escribe Comex::valuar(); en la fila combinada esos campos
           no pueden existir dos veces, asi que si se le pasara la combinada
           contaria TODOS los meses como "no se pudieron valuar" y el aviso
           diria exactamente lo contrario de lo que paso. */
        $filasFob = [];
        $filasNac = [];

        foreach ($r['meses'] as $m) {
            $fila = $m;
            $fila['FOB_USD'] = $m['estimacion_usd'];
            $fila['NAC_USD'] = $m['nacionalizacion_usd'];
            $fila['FECHA_PAGO'] = $m['pago'];
            $fila['FECHA_NAC'] = $m['nacionalizacion'];

            /* DOS LLAMADAS Y NO UNA, con los resultados guardados en campos
               propios: Comex::valuar() escribe siempre sobre COTIZ_USD e
               IMPORTE_ARS, asi que la segunda pisaria a la primera. */
            $vFob = Comex::valuar($fila, $curva, 'FOB_USD', 'FECHA_PAGO');
            $vNac = Comex::valuar($fila, $curva, 'NAC_USD', 'FECHA_NAC');

            $fila['COTIZ_FOB'] = $vFob['COTIZ_USD'];
            $fila['COTIZ_FOB_ORIGEN'] = $vFob['COTIZ_ORIGEN'];
            $fila['COTIZ_FOB_DETALLE'] = $vFob['COTIZ_DETALLE'];
            $fila['IMPORTE_FOB_ARS'] = $vFob['IMPORTE_ARS'];

            $fila['COTIZ_NAC'] = $vNac['COTIZ_USD'];
            $fila['COTIZ_NAC_ORIGEN'] = $vNac['COTIZ_ORIGEN'];
            $fila['COTIZ_NAC_DETALLE'] = $vNac['COTIZ_DETALLE'];
            $fila['IMPORTE_NAC_ARS'] = $vNac['IMPORTE_ARS'];

            $filas[] = $fila;
            $filasFob[] = $vFob;
            $filasNac[] = $vNac;
        }

        $this->ultimaGrilla = [
            'filas' => $filas,
            'filas_fob' => $filasFob,
            'filas_nac' => $filasNac,
            'meses' => $r['meses'],
            'warnings' => $r['warnings'],
            'notas' => $r['notas'],
            'totales' => $r['totales'],
            'ventana' => $ventana,
            'cuota' => $cuota,
            'versiones' => $versiones,
            'parametros' => $params
        ];

        return $this->ultimaGrilla;
    }

    /**
     * Los parametros del modulo, con su valor por defecto si el script no se
     * corrio.
     *
     * NO LANZA. Parametros::num() lanza cuando falta una clave, y eso dejaria
     * el modulo entero en cero por un script sin correr, cuando lo unico que
     * hace falta es un numero que ya esta escrito en DEFAULTS. Se avisa y se
     * sigue: la fila proyecta con los valores iniciales y la pantalla dice que
     * falta correr el script.
     *
     * @return array
     */
    private function parametros() {
        $map = [];

        try {
            $p = new Parametros;
            $map = $p->getParametrosMap();
        } catch (Throwable $e) {
            $this->avisar('Compras Exterior: no se pudieron leer los parámetros ('
                . $e->getMessage() . '). Se usan los valores iniciales.');
        }

        $out = [];
        $faltan = [];

        foreach (self::DEFAULTS as $clave => $default) {
            if (array_key_exists($clave, $map) && $map[$clave] !== null && $map[$clave] !== '') {
                $out[$clave] = is_int($default) ? intval($map[$clave]) : $map[$clave];

                continue;
            }

            $out[$clave] = $default;
            $faltan[] = $clave;
        }

        /* El porcentaje de nacionalizacion se lee como decimal aunque su default
           sea entero: 89,5 tiene que poder cargarse. */
        $out['compras_proy_nac_pct'] = isset($map['compras_proy_nac_pct'])
            && $map['compras_proy_nac_pct'] !== ''
            ? floatval($map['compras_proy_nac_pct'])
            : floatval(self::DEFAULTS['compras_proy_nac_pct']);

        $out['compras_proy_base_cuota'] = strtoupper((string) $out['compras_proy_base_cuota']) === 'UNIDADES'
            ? 'UNIDADES' : 'IMPORTE';

        if (!empty($faltan)) {
            $this->avisar('Compras Exterior: faltan ' . count($faltan) . ' parámetro'
                . (count($faltan) === 1 ? '' : 's') . ' del módulo (' . implode(', ', $faltan)
                . '). Se proyecta con los valores iniciales. Corré '
                . 'sql/cashflow_compras_proyectadas.sql contra la base central.');
        }

        return $out;
    }

    /**
     * Avisa cuando parte de una serie cayo fuera del eje, diciendo por que.
     *
     * @param array $serie
     * @param string $queEs
     */
    private function avisarFueraDelEje($serie, $queEs) {
        if (empty($serie['fuera_horizonte'])) {
            return;
        }

        $this->avisar('Compras Exterior: $ '
            . number_format($serie['fuera_horizonte'], 2, ',', '.') . ' de ' . $queEs
            . ' caen FUERA del horizonte del tablero y no suman en ninguna columna. La ventana '
            . 'se corta con el último mes cuyo PAGO entra en el eje, y la nacionalización va '
            . 'sólo unos días antes de la recepción: los últimos meses de la ventana aportan '
            . 'su FOB y no su nacionalización. Se arregla alargando el horizonte de meses.');
    }

    /**
     * Las dos series en cero, para cuando no se puede calcular.
     *
     * @return array
     */
    private function seriesVacias() {
        $vacia = [
            'dias' => [],
            'meses' => [],
            'moneda_origen' => 'USD',
            'tipo_cambio' => null
        ];

        return [
            'PAGOS_PROYECTADOS' => $vacia,
            'NACIONALIZACION_PROYECTADA' => $vacia
        ];
    }

    /**
     * Anota cada celda con el estado de cobertura del mes que la produjo.
     *
     * POR QUE VALE LA PENA: en el tablero, un mes ESTIMADO y un mes AJUSTADO se
     * ven exactamente igual -un numero en una columna- y no significan lo
     * mismo. El detalle pone en el tooltip de que mes de recepcion sale ese
     * importe, con que version y en que estado.
     *
     * NO ENTRA EN NINGUNA CUENTA: 'detalle' es metadato sobre el mismo importe,
     * no un importe mas. Ver el encabezado de CashflowProvider.
     *
     * @param Horizonte $h
     * @param array $filas
     * @param string $campoFecha
     * @param string $campoImporte
     * @return array
     */
    private function detalle($h, $filas, $campoFecha, $campoImporte) {
        $out = [];

        foreach ($filas as $f) {
            $importe = ($f[$campoImporte] === null) ? 0.0 : floatval($f[$campoImporte]);

            if ($importe == 0 || empty($f[$campoFecha])) {
                continue;
            }

            $columna = $h->columna($f[$campoFecha]);

            if ($columna === null) {
                continue;
            }

            $nota = 'Recepción estimada de ' . $f['mes'] . ' · ' . $f['temporada']
                . ' · ' . self::textoEstado($f);

            if (isset($out[$columna])) {
                $out[$columna]['importe'] += $importe;
                $out[$columna]['nota'] .= ' | ' . $nota;

                continue;
            }

            $out[$columna] = ['importe' => $importe, 'nota' => $nota];
        }

        return $out;
    }

    /**
     * Como se lee el estado de un mes en el tooltip del tablero.
     *
     * @param array $f
     * @return string
     */
    private static function textoEstado($f) {
        switch ($f['estado']) {
            case ComprasProyectadas::AJUSTADO:
                return 'importe ajustado a mano'
                    . (empty($f['ajuste']['usuario']) ? '' : ' por ' . $f['ajuste']['usuario']);

            case ComprasProyectadas::AJUSTE_DESCARTADO:
                return 'tenía un ajuste manual que se descartó: cambió la versión oficial';

            case ComprasProyectadas::SIN_COTIZACION:
                return 'valuado con el mes más cercano de la curva';

            case ComprasProyectadas::CUBIERTO:
                return 'cubierto por lo ya comprado';

            default:
                return 'estimado con la cuota histórica sobre el presupuesto oficial';
        }
    }

    /**
     * El escalar 'tipo_cambio' de una serie: solo si TODAS las filas se
     * valuaron con el mismo numero.
     *
     * Es el mismo criterio que ComexProvider y que los dolares comitente de
     * Otros Ingresos. Con varias cotizaciones no hay un escalar que informar, y
     * el tablero dibuja la marca de "valuado con la curva".
     *
     * @param array $filas
     * @param string $campo
     * @return float|null
     */
    private static function tipoCambioUnico($filas, $campo) {
        $usadas = [];

        foreach ($filas as $f) {
            if (isset($f[$campo]) && $f[$campo] !== null) {
                $usadas[(string) $f[$campo]] = floatval($f[$campo]);
            }
        }

        return (count($usadas) === 1) ? reset($usadas) : null;
    }

    /**
     * Los meses 'Y-m' que la curva de futuros puede valuar sin aproximar.
     *
     * @param array $curva
     * @return array Mapa 'Y-m' => true
     */
    private static function mesesDeLaCurva($curva) {
        $out = [];

        foreach (array_keys((array) $curva) as $mes) {
            $out[$mes] = true;
        }

        return $out;
    }
}
