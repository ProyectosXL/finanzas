<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../PagosTarjetas.php';

/**
 * TarjetasProvider
 * Alimenta la fila "Pagos con Tarjetas y Otros" (seccion COSTOS INDIRECTOS) con las
 * tres partes de la pestana Financiero -> Pagos con Tarjetas y Otros.
 *
 * Codigo TARJETAS, moneda de origen ARS. TODO SE DEVUELVE EN PESOS y con importes
 * POSITIVOS: el signo lo pone el TIPO de la fila y no el dato, igual que todos los
 * demas proveedores de egresos.
 *
 * CINCO SERIES, Y LA FILA USA UNA SOLA
 * ------------------------------------
 *   TOTAL                   la suma de las tres partes. Es la que usa la fila.
 *   SUPERVISORAS            efectivo + tarjeta de los gastos de supervision
 *   CORPORATIVAS            facturas no excluidas + cobertura, o el resumen
 *   SOCIOS                  en pesos, con el componente en U$S ya convertido
 *   CORPORATIVAS_EXCLUIDAS  solo informativa, FUERA del total
 *
 * EL TOTAL Y SUS PARTES NO PUEDEN CONVIVIR, y el registro lo declara en
 * 'componentes': si alguien activa una fila con SUPERVISORAS al lado de la que usa
 * TOTAL, el validador de Parametros -> Cashflow lo rechaza, porque el mismo peso
 * entraria dos veces. Para partir la fila en sus tres partes hay que inhabilitar la
 * del total y activar las tres.
 *
 * CORPORATIVAS_EXCLUIDAS NO ES COMPONENTE DEL TOTAL, y por eso SI puede convivir
 * con la fila del total: su importe no esta en el total, justamente porque se
 * decidio que no entre. Es lo mismo que pasa con PAGOS_EXCLUIDOS_FACTURA de
 * Proveedores Locales, con una diferencia: alla existe una serie de universo
 * (PAGOS_TODO) contra la cual medir las partes, y aca NO.
 *
 * NO HAY SERIE DE UNIVERSO, Y ES DELIBERADO
 * -----------------------------------------
 * Echeqs declara A_COBRAR_TODO y Proveedores Locales PAGOS_TODO: el universo contra
 * el que el validador mide el doble conteo. Aca no se puede declarar uno honesto,
 * porque CORPORATIVAS no es solo facturas: tambien lleva la cobertura, y puede
 * quedar reemplazada por el resumen. Un "CORPORATIVAS_TODO" seria la suma de cosas
 * de distinta naturaleza -deuda real, un porcentaje estimado y un resumen que
 * reemplaza a los dos- y no la particion de nada.
 *
 * Lo que si se mantiene es el invariante que importa, y lo fija la prueba:
 * TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS, columna por columna.
 *
 * LO QUE NO ENTRA SE AVISA SIEMPRE
 * --------------------------------
 * Es la mitad de lo que este proveedor hace. Lo que queda afuera es plata real que
 * el tablero no muestra, y en esta pestana es MUCHA: al 26/09/2026, sin ninguna
 * tarjeta cargada, quedan afuera $ 60,1 millones de parte tarjeta de supervisoras y
 * $ 45,0 millones de facturas vencidas sin vincular. Un tablero que informa de menos
 * en silencio es peor que uno que falla.
 *
 * Los avisos salen de PagosTarjetas y de las clases puras -ya vienen con el nombre
 * de la supervisora, el conteo de facturas y el importe- y se levantan tal cual: son
 * lo que explica un total mas chico de lo esperado sin tener que abrir la pestana.
 *
 * EL DETALLE DE LAS CELDAS CON RESUMEN CARGADO
 * -------------------------------------------
 * Un resumen cargado y su estimacion caen casi siempre en la MISMA columna -entre
 * las dos fechas hay unos cinco dias- asi que no son dos filas: son la misma, y la
 * celda queda anotada con 'detalle', la anotacion de celda que ya existe en el
 * contrato. Una serie aparte sumaria un importe que la fila ya suma.
 *
 * NUNCA TUMBA EL TABLERO
 * ----------------------
 * calcular() puede lanzar y series() lo envuelve. Ademas se atrapan los casos
 * propios para poder rendir cero CON UN AVISO QUE DIGA QUE PASO, en lugar del
 * mensaje genérico de la clase base.
 */
class TarjetasProvider extends CashflowProvider {

    /** La serie que usa la fila del tablero: la suma de las tres partes */
    const SERIE_TOTAL = 'TOTAL';

    /** Las tres partes */
    const SERIE_SUPERVISORAS = 'SUPERVISORAS';
    const SERIE_CORPORATIVAS = 'CORPORATIVAS';
    const SERIE_SOCIOS = 'SOCIOS';

    /** Solo informativa: FUERA del total */
    const SERIE_EXCLUIDAS = 'CORPORATIVAS_EXCLUIDAS';

    /** @var array|null Cache de los datos, para no calcular dos veces por pedido */
    private $datos = null;

    protected function calcular($h) {
        $datos = $this->modulo()->calcular($h);
        $this->datos = $datos;

        /* LOS AVISOS SE LEVANTAN TAL CUAL. Ya vienen con el nombre, el conteo y el
           importe: rearmarlos aca los dejaria diciendo menos que en la pestana. */
        foreach ($datos['avisos'] as $a) {
            $this->avisar('Pagos con Tarjetas: ' . $a);
        }

        $series = [
            self::SERIE_SUPERVISORAS => $this->serieDe($h, $datos['supervisoras']['pagos'],
                $datos['supervisoras']['avisos'], 'Gastos Supervisoras'),
            self::SERIE_CORPORATIVAS => $this->serieDe($h, $datos['corporativas']['pagos'],
                $datos['corporativas']['avisos'], 'Tarjetas Pagos Corporativos'),
            self::SERIE_SOCIOS => $this->serieDe($h, $datos['socios']['pagos'],
                $datos['socios']['avisos'], 'Tarjetas Socios'),

            /* LAS EXCLUIDAS NO GENERAN AVISOS PROPIOS ACA: los de Corporativas ya
               dicen cuantas son, por cuanto y con que motivo. Repetirlos seria el
               mismo texto dos veces. */
            self::SERIE_EXCLUIDAS => $this->serieDe($h, $datos['corporativas']['pagos_excluidos'],
                [], null)
        ];

        /* EL TOTAL SE ARMA SUMANDO LAS TRES PARTES, columna por columna, y NO
           reagrupando los pagos otra vez: sumar las series es la unica forma de
           que el invariante TOTAL = SUPERVISORAS + CORPORATIVAS + SOCIOS no pueda
           romperse por un pago que se cuente en una y no en el otro.

           LAS EXCLUIDAS QUEDAN AFUERA, que es lo que las hace informativas. */
        $series[self::SERIE_TOTAL] = $this->sumar($h, [
            $series[self::SERIE_SUPERVISORAS],
            $series[self::SERIE_CORPORATIVAS],
            $series[self::SERIE_SOCIOS]
        ]);

        /* EL DETALLE VA SOLO EN EL TOTAL Y EN CORPORATIVAS/SUPERVISORAS/SOCIOS
           segun de donde salga: una anotacion sobre una celda dice algo del origen
           de ese importe, y el total la hereda porque es la fila que se dibuja. */
        $detalle = $this->detalle($h, $datos);

        $series[self::SERIE_TOTAL]['detalle'] = $detalle;

        if (array_sum($series[self::SERIE_TOTAL]['dias']) == 0
            && array_sum($series[self::SERIE_TOTAL]['meses']) == 0) {
            $this->avisarCero($datos);
        }

        return $series;
    }

    /**
     * El modulo del que salen los datos.
     *
     * Es una costura para poder probar: lo delicado de este proveedor son los casos
     * en los que tiene que rendir CERO CON UN AVISO -tablas sin crear, ninguna
     * tarjeta, todo sin vincular- y esos casos no se pueden montar en una base real
     * sin borrar tablas. Con el metodo separado, la prueba pasa un doble y verifica
     * el aviso sin base.
     *
     * Es la misma idea que LogisticaProvider::modulo() y
     * CobElectronicosProvider::modulo().
     *
     * @return PagosTarjetas
     */
    protected function modulo() {
        return new PagosTarjetas();
    }

    /**
     * Una serie a partir de una lista de pagos con fecha e importe.
     *
     * EL EJE DECIDE LA COLUMNA. Acá sólo se entrega fecha e importe, y lo que caiga
     * fuera del eje se informa: un tablero de consolidacion que informa de menos sin
     * decirlo es peor que uno que falla.
     *
     * @param Horizonte $h
     * @param array $pagos
     * @param array $avisos Avisos de esa parte
     * @param string|null $nombre Como se la nombra en los avisos, o null para no avisar
     * @return array Serie
     */
    private function serieDe($h, $pagos, $avisos, $nombre) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        foreach ($pagos as $p) {
            $importe = isset($p['importe']) ? floatval($p['importe']) : 0.0;

            if ($importe == 0) {
                continue;
            }

            if (empty($p['fecha'])) {
                $serie['sin_fecha'] += $importe;
                continue;
            }

            if (!$h->acumular($serie, $p['fecha'], $importe)) {
                $serie['fuera_horizonte'] += $importe;
            }
        }

        if ($nombre !== null) {
            foreach ($avisos as $a) {
                $this->avisar($nombre . ': ' . $a);
            }

            if ($serie['fuera_horizonte'] > 0) {
                $this->avisar($nombre . ': hay pagos proyectados por $ '
                    . number_format($serie['fuera_horizonte'], 2, ',', '.') . ' con fecha '
                    . 'posterior al final del horizonte, así que no entran en ninguna columna. '
                    . 'Se ven igual en la pestaña.');
            }

            if ($serie['sin_fecha'] > 0) {
                $this->avisar($nombre . ': hay $ '
                    . number_format($serie['sin_fecha'], 2, ',', '.') . ' sin una fecha con la '
                    . 'que entrar al eje. Se ven en la pestaña, con el motivo.');
            }
        }

        return $serie;
    }

    /**
     * Suma varias series columna por columna.
     *
     * LOS ESCALARES TAMBIEN SE SUMAN: si una parte informa $ 1.000 fuera del
     * horizonte, el total tiene que informarlos tambien, porque es la fila que se
     * dibuja y es donde el aviso tiene que poder explicarse.
     *
     * @param Horizonte $h
     * @param array $series
     * @return array
     */
    private function sumar($h, $series) {
        $total = $h->serieVacia();
        $total['moneda_origen'] = 'ARS';
        $total['fuera_horizonte'] = 0;
        $total['sin_fecha'] = 0;

        foreach ($series as $s) {
            foreach ($s['dias'] as $k => $v) {
                $total['dias'][$k] += $v;
            }

            foreach ($s['meses'] as $k => $v) {
                $total['meses'][$k] += $v;
            }

            $total['fuera_horizonte'] += $s['fuera_horizonte'];
            $total['sin_fecha'] += $s['sin_fecha'];
        }

        return $total;
    }

    /**
     * Las anotaciones de celda: donde hay un resumen cargado, cuanto es y cuando
     * vence.
     *
     * POR QUE ES 'detalle' Y NO UNA SERIE. El resumen y la estimacion que reemplaza
     * caen casi siempre en la misma columna -entre las dos fechas hay unos cinco
     * dias- asi que van en la MISMA fila del tablero. Una serie aparte seria una
     * fila nueva que sumaria un importe que la fila original ya suma: doble conteo.
     * 'detalle' es metadato SOBRE el mismo importe y no entra en ninguna cuenta.
     *
     * SE ANOTA LO QUE PISA UNA ESTIMACION, no todo resumen: un resumen de una
     * tarjeta corporativa sin facturas vinculadas no reemplaza nada, asi que no hay
     * nada que distinguir en esa celda.
     *
     * @param Horizonte $h
     * @param array $datos
     * @return array Mapa columna => ['importe', 'nota']
     */
    private function detalle($h, $datos) {
        $detalle = [];

        // Los resumenes de las tarjetas corporativas.
        foreach ($datos['corporativas']['resumenes'] as $r) {
            if (!$r['proyecta'] || $r['fecha'] === null) {
                continue;
            }

            self::anotar($detalle, $h->columna($r['fecha']), $r['importe'],
                'Resumen cargado de ' . $r['mes'] . ': $ '
                . number_format($r['importe'], 2, ',', '.') . ', vence el '
                . self::corto($r['fecha']) . '. Reemplaza a las facturas vinculadas de ese mes '
                . 'y a su cobertura.');
        }

        // La parte tarjeta de las supervisoras que salio de un resumen.
        foreach ($datos['supervisoras']['pagos'] as $p) {
            if ($p['parte'] !== 'TARJETA' || !isset($p['origen']) || $p['origen'] !== 'RESUMEN') {
                continue;
            }

            self::anotar($detalle, $h->columna($p['fecha']), $p['importe'],
                'Resumen cargado de ' . $p['mes'] . ' (' . $p['supervisora'] . '): $ '
                . number_format($p['importe'], 2, ',', '.') . ', vence el '
                . self::corto($p['fecha']) . '. Pisa la estimación de ese mes.');
        }

        // Y las de los socios.
        foreach ($datos['socios']['pagos'] as $p) {
            if (!isset($p['origen']) || $p['origen'] !== 'RESUMEN') {
                continue;
            }

            self::anotar($detalle, $h->columna($p['fecha']), $p['importe'],
                'Resumen cargado de ' . $p['mes'] . ': $ '
                . number_format($p['importe'], 2, ',', '.') . ' en pesos, vence el '
                . self::corto($p['fecha']) . '. Pisa la estimación de ese mes.');
        }

        return $detalle;
    }

    /**
     * Acumula una anotacion en una columna, juntando las que caen en la misma.
     *
     * DOS RESUMENES PUEDEN CAER EN LA MISMA COLUMNA -dos tarjetas que vencen la
     * misma semana- y el contrato admite UNA anotacion por celda. Se suman los
     * importes y se juntan las notas: quedarse con la primera escondería la
     * segunda, y es el sintoma que la nota de `detalle` del README advierte.
     */
    private static function anotar(&$detalle, $columna, $importe, $nota) {
        if ($columna === null || $importe === null) {
            return;
        }

        if (!isset($detalle[$columna])) {
            $detalle[$columna] = ['importe' => 0.0, 'nota' => ''];
        }

        $detalle[$columna]['importe'] += floatval($importe);
        $detalle[$columna]['nota'] .= ($detalle[$columna]['nota'] === '' ? '' : ' | ') . $nota;
    }

    /**
     * Por que la fila va en cero.
     *
     * UN EGRESO EN CERO SE LEE COMO "no hay que pagar nada", que es lo contrario de
     * lo que pasa. Los avisos de arriba ya dicen QUE falta; este dice la
     * CONSECUENCIA sobre el cuadro, que es lo que se ve en el tablero.
     *
     * @param array $datos
     */
    private function avisarCero($datos) {
        if (!$datos['tablas']['tarjetas']) {
            $this->avisar('Pagos con Tarjetas: la fila va en CERO porque todavía no existen las '
                . 'tablas del módulo. Corré sql/cashflow_tarjetas.sql y '
                . 'sql/cashflow_tarjetas_facturas.sql contra la base central. Un egreso en cero '
                . 'no significa que no haya que pagar nada.');

            return;
        }

        $partes = [];

        if (empty($datos['supervisoras']['filas'])) {
            $partes[] = 'no hay supervisoras con gastos autorizados en la ventana';
        }

        if (empty($datos['corporativas']['filas'])) {
            $partes[] = 'no hay facturas pendientes con forma de pago TARJETA CORP';
        }

        if (empty($datos['socios']['filas'])) {
            $partes[] = 'no hay tarjetas de socios activas';
        }

        $this->avisar('Pagos con Tarjetas: la fila va en CERO'
            . (empty($partes) ? ', y los avisos de arriba dicen por qué'
                              : ' porque ' . implode(', ' , $partes))
            . '. NO significa que no haya que pagar nada.');
    }

    /** 'Y-m-d' => 'd/m' */
    private static function corto($fecha) {
        return ($fecha === null) ? '—' : (substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2));
    }
}
