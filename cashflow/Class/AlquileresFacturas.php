<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/Aviso.php';

/**
 * AlquileresFacturas
 * Que factura de Tango es de alquiler, y a que pestaña va.
 *
 * ES LA UNICA REGLA. Proveedores Locales (la grilla, sus tarjetas, exportar y
 * la planilla de pagos), Tarjetas Pagos Corporativos, la pestaña Alquileres y
 * los dos proveedores del tablero preguntan aca, y una prueba fija que todos
 * digan lo mismo. Si un lado decidiera por su cuenta, la misma factura podria
 * sumar en dos filas o en ninguna.
 *
 * ESTATICA Y PURA. Lo que hace falta saber -que razones estan dadas de alta,
 * quien esta excluido, si la fila del tablero existe- entra como un CONTEXTO ya
 * leido (lo arma AlquileresUniverso). Asi la regla se prueba sin base.
 *
 * QUE ES UNA FACTURA DE ALQUILER
 * ------------------------------
 * La de un proveedor DADO DE ALTA (vigente) en Parametros -> Alquileres ->
 * Razones sociales. El rubro ALQUILER de la columna RUBRO del maestro NO define
 * nada: solo acota la lista desde donde se da de alta, y con ese rubro hay
 * proveedores que no son alquileres de locales (Mercado Libre, Sploder). Un
 * proveedor con el rubro que no esta dado de alta sigue en Proveedores Locales.
 * Dar de baja la razon social devuelve sus facturas a Proveedores Locales.
 *
 * LA CONDICION DE SEGURIDAD: SALEN SOLO SI HAY DONDE CAER
 * -------------------------------------------------------
 * Sacar las facturas de Proveedores Locales antes de que Alquileres las sume en
 * el tablero las haria desaparecer del cuadro. Por eso salen SOLO si se cumplen
 * las tres cosas:
 *
 *   - el modulo ALQUILERES esta disponible en CashflowRegistry;
 *   - hay una fila ACTIVA del tablero que lee ALQUILERES con REAL o PAGOS (las
 *     dos series que llevan las facturas imputadas);
 *   - existen las tablas de sql/cashflow_alquileres_facturas.sql: sin ellas no
 *     se puede imputar nada, y una factura que no se puede imputar no suma.
 *
 * Si falta cualquiera, se quedan en Proveedores Locales como siempre y el aviso
 * critico de avisoCondicion() dice cual falta. "Se quedan" es para TODOS: la
 * pestaña Alquileres tampoco las toma como suyas, para que ninguna se cuente en
 * las dos.
 *
 * EXCLUIDA NO ES "VUELVE A PROVEEDORES LOCALES"
 * ---------------------------------------------
 * Excluir de Alquileres a un proveedor dado de alta (ProveedoresExclusion,
 * modulo ALQUILERES) lo saca de esa pestaña y del tablero a proposito: sus
 * facturas no entran, no se imputan, no suman y TAMPOCO vuelven a Proveedores
 * Locales. Para pasar un proveedor de Alquileres a Proveedores Locales se lo da
 * de baja en Parametros -> Alquileres, no se lo excluye.
 */
class AlquileresFacturas {

    /** El script de las imputaciones y los vencimientos editados */
    const SCRIPT = 'sql/cashflow_alquileres_facturas.sql';

    /**
     * La tabla que dice que el script ya se corrio. Es la cabecera de las
     * imputaciones: sin ella no hay donde guardar una.
     */
    const TABLA_IMPUTACION = 'RO_T_CASHFLOW_ALQ_IMPUTACION';

    /** El modulo del registro y las series que llevan las facturas imputadas */
    const MODULO = 'ALQUILERES';
    const SERIES_CON_FACTURAS = ['REAL', 'PAGOS'];

    /** A donde va una factura */
    const A_ALQUILERES = 'ALQUILERES';
    const A_EXCLUIDA = 'ALQUILERES_EXCLUIDA';
    const A_PROV_LOCALES = 'PROV_LOCALES';

    /** Por que la condicion de seguridad no se cumple */
    const SIN_MODULO = 'SIN_MODULO';
    const SIN_FILA = 'SIN_FILA';
    const SIN_TABLAS = 'SIN_TABLAS';

    /* ====================================================================
       LA CONDICION DE SEGURIDAD
       ==================================================================== */

    /**
     * Si las facturas de alquiler pueden salir de Proveedores Locales.
     *
     * Estatica y pura.
     *
     * @param bool $disponible CashflowRegistry::disponible('ALQUILERES')
     * @param string[] $seriesActivas ORIGEN_SERIE de las filas ACTIVAS del
     *        tablero con ORIGEN_PROVIDER = 'ALQUILERES'
     * @param bool $tablas Si existen las tablas del script
     * @return array ['cumple' => bool, 'faltan' => [SIN_*]]
     */
    public static function condicion($disponible, $seriesActivas, $tablas) {
        $faltan = [];

        if (!$disponible) {
            $faltan[] = self::SIN_MODULO;
        }

        $series = array_map(function ($s) { return strtoupper(trim((string) $s)); },
            is_array($seriesActivas) ? $seriesActivas : []);

        if (empty(array_intersect($series, self::SERIES_CON_FACTURAS))) {
            $faltan[] = self::SIN_FILA;
        }

        if (!$tablas) {
            $faltan[] = self::SIN_TABLAS;
        }

        return ['cumple' => empty($faltan), 'faltan' => $faltan];
    }

    /**
     * Un contexto armado: lo que destino() necesita.
     *
     * Estatica y pura. Los codigos se normalizan aca, una vez, con el mismo
     * Planilla::codigo() que getPendientes(): si no, un espacio de mas haria que
     * una factura de IRSA no sea de IRSA.
     *
     * @param string[] $razones Codigos dados de alta en Parametros -> Alquileres
     * @param string[] $excluidos Codigos excluidos del modulo ALQUILERES
     * @param array $condicion Lo de condicion()
     * @return array ['razones' => [cod => true], 'excluidos' => [cod => true],
     *                'condicion' => array]
     */
    public static function contexto($razones, $excluidos, $condicion) {
        $set = function ($lista) {
            $s = [];

            foreach (is_array($lista) ? $lista : [] as $cod) {
                $c = Planilla::codigo($cod);

                if ($c !== '') {
                    $s[$c] = true;
                }
            }

            return $s;
        };

        return [
            'razones' => $set($razones),
            'excluidos' => $set($excluidos),
            'condicion' => $condicion
        ];
    }

    /* ====================================================================
       LA REGLA
       ==================================================================== */

    /**
     * Si el proveedor esta dado de alta en Parametros -> Alquileres.
     *
     * NO MIRA LA CONDICION DE SEGURIDAD: dice que ES la factura, no a donde va.
     * La usan los avisos (cuanto queda en Proveedores Locales esperando la
     * fila) y Parametros. Para decidir donde se cuenta, destino().
     *
     * @param string $codProvee
     * @param array $ctx Lo de contexto()
     * @return bool
     */
    public static function esDeAlquiler($codProvee, $ctx) {
        return isset($ctx['razones'][Planilla::codigo($codProvee)]);
    }

    /**
     * A que pestaña va una fila de Proveedores::getPendientes().
     *
     *   A_PROV_LOCALES  no es de alquiler, o lo es pero la condicion de
     *                   seguridad no se cumple: se queda donde estuvo siempre
     *   A_EXCLUIDA      es de alquiler y el proveedor esta excluido de
     *                   Alquileres: no entra a ningun lado, a proposito
     *   A_ALQUILERES    es de alquiler: la gestiona la pestaña Alquileres
     *
     * @param array $item
     * @param array $ctx Lo de contexto()
     * @return string
     */
    public static function destino($item, $ctx) {
        $cod = Planilla::codigo(isset($item['COD_PROVEE']) ? $item['COD_PROVEE'] : '');

        if (empty($ctx['condicion']['cumple']) || !isset($ctx['razones'][$cod])) {
            return self::A_PROV_LOCALES;
        }

        return isset($ctx['excluidos'][$cod]) ? self::A_EXCLUIDA : self::A_ALQUILERES;
    }

    /**
     * Parte una lista de pendientes por destino, sin cambiar el orden.
     *
     * @param array $items
     * @param array $ctx
     * @return array [A_ALQUILERES => [], A_EXCLUIDA => [], A_PROV_LOCALES => []]
     */
    public static function partir($items, $ctx) {
        $out = [self::A_ALQUILERES => [], self::A_EXCLUIDA => [], self::A_PROV_LOCALES => []];

        foreach (is_array($items) ? $items : [] as $item) {
            $out[self::destino($item, $ctx)][] = $item;
        }

        return $out;
    }

    /**
     * Los pendientes que se quedan en Proveedores Locales: la grilla, sus
     * tarjetas, exportar, las series del tablero y el universo de Tarjetas.
     *
     * @param array $items
     * @param array $ctx
     * @return array
     */
    public static function deProveedoresLocales($items, $ctx) {
        return self::partir($items, $ctx)[self::A_PROV_LOCALES];
    }

    /* ====================================================================
       EL NETO GRAVADO
       ==================================================================== */

    /**
     * El neto gravado de UNA CUOTA, para sugerir la retencion.
     *
     * Tango guarda el neto gravado por COMPROBANTE (CPA04.SUBT_GRAVA) y el
     * vencimiento por cuota (CPA54), asi que el de la cuota es la parte del neto
     * que le toca por su importe. Las facturas de alquiler tienen hoy una sola
     * cuota (10/11/2026) y ahi da el neto entero.
     *
     * NULL SI NO HAY DATO, nunca 0: un neto en cero es "Tango no lo trae", y
     * con null quien sugiere la retencion sabe que tiene que deducirlo del
     * importe con el IVA y la percepcion de los locales.
     *
     * Estatica y pura.
     *
     * @param array $item Fila de getPendientes() con NETO_GRAVADO_COMP,
     *        IMPORTE_COMP e IMPORTE_VTO
     * @return float|null
     */
    public static function netoDeCuota($item) {
        $neto = isset($item['NETO_GRAVADO_COMP']) ? $item['NETO_GRAVADO_COMP'] : null;
        $total = isset($item['IMPORTE_COMP']) ? $item['IMPORTE_COMP'] : null;
        $vto = isset($item['IMPORTE_VTO']) ? $item['IMPORTE_VTO'] : null;

        if ($neto === null || $total === null || $vto === null
            || floatval($neto) <= 0 || floatval($total) <= 0) {
            return null;
        }

        return round(floatval($neto) * floatval($vto) / floatval($total), 2);
    }

    /* ====================================================================
       LOS AVISOS
       ==================================================================== */

    /**
     * El aviso de Proveedores Locales -la pestaña y su proveedor del tablero-
     * por lo que ya no muestra: las facturas de alquiler, y aparte las de un
     * proveedor excluido de Alquileres, que no estan en ningun lado a proposito.
     *
     * INFORMATIVO: no es plata que falte, es plata que se cuenta en otra
     * pestaña. Sin el, quien compara el total de hoy con el de ayer no sabe por
     * que bajo.
     *
     * Estatica y pura.
     *
     * @param array $partes Lo de partir()
     * @return array Lista de avisos
     */
    public static function avisoFueraDeProvLocales($partes) {
        $suma = function ($items) {
            return array_sum(array_map(function ($i) { return floatval($i['IMPORTE_PENDIENTE']); }, $items));
        };
        $plata = function ($n) { return '$ ' . number_format($n, 2, ',', '.'); };

        $alq = $partes[self::A_ALQUILERES];
        $exc = $partes[self::A_EXCLUIDA];

        if (empty($alq) && empty($exc)) {
            return [];
        }

        $texto = (count($alq) + count($exc)) . ' factura(s) de alquiler por ' . $plata($suma($alq) + $suma($exc))
            . ' no se muestran acá ni suman en Cuentas a Pagar Locales: son de razones sociales dadas de alta '
            . 'en Parámetros › Alquileres y se gestionan en la pestaña Alquileres.';

        if (!empty($exc)) {
            $texto .= ' De esas, ' . count($exc) . ' por ' . $plata($suma($exc)) . ' son de proveedores '
                . 'excluidos de Alquileres y no suman en ningún lado, a propósito.';
        }

        return [Aviso::nuevo(Aviso::INFO, $texto)];
    }

    /**
     * El aviso critico de una condicion de seguridad que no se cumple, si hay
     * facturas de alquiler esperandola. [] si se cumple o si no hay ninguna.
     *
     * ES CRITICO Y NO INFORMATIVO porque en ese estado las facturas de alquiler
     * siguen sumando en Proveedores Locales, y si alguien activa la proyeccion
     * de Alquileres sin la fila de lo real el mismo alquiler entra dos veces.
     *
     * @param array $ctx
     * @param array $items Pendientes, para decir cuantas y cuanto
     * @return array Lista de avisos (Aviso::nuevo)
     */
    public static function avisoCondicion($ctx, $items) {
        if (!empty($ctx['condicion']['cumple'])) {
            return [];
        }

        $cuantas = 0;
        $total = 0.0;

        foreach (is_array($items) ? $items : [] as $item) {
            if (self::esDeAlquiler(isset($item['COD_PROVEE']) ? $item['COD_PROVEE'] : '', $ctx)) {
                $cuantas++;
                $total += floatval($item['IMPORTE_PENDIENTE']);
            }
        }

        if ($cuantas === 0) {
            return [];
        }

        $motivos = [
            self::SIN_MODULO => 'el módulo Alquileres no está disponible en el tablero',
            self::SIN_FILA => 'no hay una fila activa del tablero que lea Alquileres con REAL o PAGOS '
                . '(corré sql/cashflow_alquileres_fila.sql o activala en Parámetros › Cashflow)',
            self::SIN_TABLAS => 'falta correr ' . self::SCRIPT . ' contra la base central'
        ];

        $porque = array_map(function ($m) use ($motivos) { return $motivos[$m]; },
            $ctx['condicion']['faltan']);

        return [Aviso::nuevo(Aviso::DANGER, $cuantas . ' factura(s) de alquiler por $ '
            . number_format($total, 2, ',', '.') . ' siguen en Proveedores Locales porque '
            . implode('; ', $porque) . '. Hasta que eso se resuelva, la pestaña Alquileres no las '
            . 'toma: sacarlas antes las haría desaparecer del tablero.')];
    }
}
