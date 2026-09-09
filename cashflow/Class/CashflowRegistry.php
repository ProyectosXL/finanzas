<?php

require_once __DIR__ . '/CashflowProvider.php';

/**
 * CashflowRegistry
 * Registro de los proveedores de datos del tablero de Cashflow.
 *
 * Es la unica lista de origenes de datos que existe. Alimenta:
 *   - al motor, que resuelve cada fila configurada buscando su proveedor aca
 *   - al editor de estructura de Parametros, que arma con esto los dos
 *     desplegables encadenados (proveedor -> serie)
 *   - al validador, que rechaza una fila que apunte a un origen inexistente
 *
 * PARA AGREGAR UN MODULO AL TABLERO hacen falta dos cosas:
 *
 *   1. Escribir una subclase de CashflowProvider en Class/Providers/, que
 *      resuelva sus series contra el Horizonte.
 *   2. Agregar su entrada en $providers, con 'disponible' => true.
 *
 * Y despues, desde la pantalla y sin tocar codigo, apuntar una fila del
 * tablero a ese par (proveedor, serie).
 *
 * El motor no se modifica nunca.
 *
 * MODULOS QUE TODAVIA NO EXISTEN
 * ------------------------------
 * Van igual en la lista, con 'disponible' => false y sin 'clase'. Una fila que
 * apunte a uno de ellos se muestra EN CERO y el tablero avisa, en vez de
 * desaparecer del cuadro. Asi la pantalla tiene desde el primer dia la forma
 * completa del Excel y se ve que falta.
 *
 * Es el mismo criterio que RO_T_CASHFLOW_VENTAS_PRECHEQ: cablear el camino
 * completo devolviendo cero y dejar documentado el pendiente. Cuando el modulo
 * exista, alcanza con escribir su proveedor y poner 'disponible' => true: la
 * fila ya esta configurada y se llena sola.
 *
 * 'moneda' es la moneda en la que el modulo maneja sus importes. El proveedor
 * los convierte a pesos antes de devolverlos; el dato queda para poder mostrar
 * y auditar la conversion.
 */
class CashflowRegistry {

    private static $providers = [

        /* ---- Con datos reales hoy ------------------------------------------ */

        'VENTAS' => [
            'nombre' => 'Ventas',
            'descripcion' => 'Proyeccion de venta y su conversion en cobranza',
            'archivo' => 'Providers/VentasProvider.php',
            'clase' => 'VentasProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'ventas',
            'series' => [
                'COBRANZA' => 'Cobranza estimada, total',
                'COBRANZA_LOCALES' => 'Cobranza estimada - Locales',
                'COBRANZA_FRANQUICIAS' => 'Cobranza estimada - Franquicias',
                'COBRANZA_MAYORISTAS' => 'Cobranza estimada - Mayoristas',
                'COBRANZA_ECOMMERCE' => 'Cobranza estimada - Ecommerce',
                'VENTA' => 'Venta proyectada, total (no es caja)',
                'VENTA_LOCALES' => 'Venta proyectada - Locales (no es caja)',
                'VENTA_FRANQUICIAS' => 'Venta proyectada - Franquicias (no es caja)',
                'VENTA_MAYORISTAS' => 'Venta proyectada - Mayoristas (no es caja)',
                'VENTA_ECOMMERCE' => 'Venta proyectada - Ecommerce (no es caja)'
            ],
            // Una serie total y sus componentes NO pueden estar activas a la
            // vez: seria contar dos veces el mismo importe. El validador de la
            // estructura lo rechaza a partir de esto.
            'componentes' => [
                'COBRANZA' => ['COBRANZA_LOCALES', 'COBRANZA_FRANQUICIAS',
                               'COBRANZA_MAYORISTAS', 'COBRANZA_ECOMMERCE'],
                'VENTA' => ['VENTA_LOCALES', 'VENTA_FRANQUICIAS',
                            'VENTA_MAYORISTAS', 'VENTA_ECOMMERCE']
            ]
        ],

        'COBRANZAS_FR' => [
            'nombre' => 'Cobranzas Franquicias',
            'descripcion' => 'Cobranza real de facturas ya emitidas a franquicias',
            'archivo' => 'Providers/IngresosProvider.php',
            'clase' => 'IngresosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'cobranzas_fr',
            'series' => [
                'COBRANZA' => 'Cobranza real de franquicias'
            ]
        ],

        'COMEX_PROV_EXT' => [
            'nombre' => 'Proveedores Exterior',
            'descripcion' => 'Pagos a proveedores del exterior por importaciones',
            'archivo' => 'Providers/ComexProvider.php',
            'clase' => 'ComexProvider',
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'proveedores_exterior',
            'series' => [
                'PAGOS' => 'Pagos a proveedores del exterior'
            ]
        ],

        'COMEX_NAC' => [
            'nombre' => 'Nacionalizaciones',
            'descripcion' => 'Cronograma de gastos de nacionalizacion',
            'archivo' => 'Providers/ComexProvider.php',
            'clase' => 'ComexProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'crono_nacionalizacion',
            'series' => [
                'NACIONALIZACION' => 'Gastos de nacionalizacion'
            ]
        ],

        /* SaldosProvider sirve dos codigos, igual que ComexProvider: cada
           instancia corre solo la consulta de su serie. Van separados porque
           leen dos servidores distintos, y asi una caida del servidor de
           locales no se lleva puesto el disponible bancario. */
        'SALDOS' => [
            'nombre' => 'Saldos',
            'descripcion' => 'Disponible inicial en bancos, Mercado Pago y efectivo de tesoreria',
            'archivo' => 'Providers/SaldosProvider.php',
            'clase' => 'SaldosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'saldos',
            'series' => ['DISPONIBLE' => 'Disponible al inicio']
        ],

        'CAJA_LOCALES' => [
            'nombre' => 'Caja Locales',
            'descripcion' => 'Deposito de la recaudacion de los locales propios',
            'archivo' => 'Providers/SaldosProvider.php',
            'clase' => 'SaldosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'saldos',
            // La pestana Saldos tiene dos sub-pestanas y este proveedor alimenta
            // la SEGUNDA. Sin esto, el enlace del tablero abre Saldos en la
            // primera y el usuario no encuentra el detalle del numero que
            // acababa de clickear.
            'subtab' => 'locales',
            'series' => ['DEPOSITOS' => 'Depositos de caja de locales']
        ],

        /* ---- Modulos que todavia no existen -------------------------------- */
        /* Rinden cero y el tablero avisa. Ver la nota del encabezado. */

        'ECHEQS' => [
            'nombre' => 'Echeqs',
            'descripcion' => 'Echeqs en cartera pendientes de acreditacion',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'echeqs',
            'series' => ['A_COBRAR' => 'Echeqs a cobrar']
        ],

        'COBRANZAS_MAY' => [
            'nombre' => 'Cobranzas Mayoristas',
            'descripcion' => 'Cobranza real de facturas ya emitidas a mayoristas',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'cobranzas_may',
            'series' => ['COBRANZA' => 'Cobranza real de mayoristas']
        ],

        'COB_ELECTRONICOS' => [
            'nombre' => 'Cobranzas Electronicas',
            'descripcion' => 'Acreditaciones de medios electronicos de pago',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'cob_electronicos',
            'series' => ['COBRANZA' => 'Cobranzas electronicas']
        ],

        'PROV_LOCALES' => [
            'nombre' => 'Proveedores Locales',
            'descripcion' => 'Pagos a proveedores del mercado local',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'proveedores_locales',
            'series' => ['PAGOS' => 'Pagos a proveedores locales']
        ],

        'LOGISTICA' => [
            'nombre' => 'Logistica',
            'descripcion' => 'Fletes y servicios logisticos locales',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'logistica_local',
            'series' => ['PAGOS' => 'Pagos de logistica']
        ],

        'HABERES' => [
            'nombre' => 'Haberes',
            'descripcion' => 'Sueldos, cargas sociales y conceptos de nomina',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'haberes',
            'series' => ['PAGOS' => 'Haberes y cargas']
        ],

        'ALQUILERES' => [
            'nombre' => 'Alquileres',
            'descripcion' => 'Alquileres de locales y depositos',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'alquileres',
            'series' => ['PAGOS' => 'Alquileres']
        ],

        'LLAVES_RENOV' => [
            'nombre' => 'Llaves y Renovaciones',
            'descripcion' => 'Llaves de locales y obras de renovacion',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'llaves_renov',
            'series' => ['PAGOS' => 'Llaves y renovaciones']
        ],

        'IMPUESTOS' => [
            'nombre' => 'Impuestos',
            'descripcion' => 'Vencimientos impositivos',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'impuestos',
            'series' => ['PAGOS' => 'Impuestos']
        ],

        'FINANCIERO' => [
            'nombre' => 'Financiero',
            'descripcion' => 'Prestamos, tarjetas y movimientos financieros',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'prestamos',
            'series' => ['MOVIMIENTOS' => 'Movimientos financieros']
        ],

        'OTROS' => [
            'nombre' => 'Otros',
            'descripcion' => 'Movimientos varios y aportes de socios',
            'moneda' => 'ARS',
            'disponible' => false,
            'tab' => 'otros_socios',
            'series' => ['MOVIMIENTOS' => 'Otros movimientos']
        ],

        /* ---- Filas del Excel que hoy se cargan a mano ----------------------
           En el Excel original estas filas las tipea una persona (Tesoreria,
           Silvina, Dan, Alejandro). El modulo resuelve el origen de datos
           unicamente por proveedor, asi que hasta que exista el modulo que las
           alimente se muestran en cero y el tablero avisa. Quedan declaradas
           para que la estructura del cuadro este completa. */

        'DOLARES_COMITENTE' => [
            'nombre' => 'Dolares Cuenta Comitente',
            'descripcion' => 'Dolares disponibles en la cuenta comitente',
            'moneda' => 'USD',
            'disponible' => false,
            'tab' => 'saldos',
            'series' => ['DISPONIBLE' => 'Dolares en cuenta comitente']
        ],

        'EXPORTACIONES' => [
            'nombre' => 'Exportaciones',
            'descripcion' => 'Cobranza de exportaciones',
            'moneda' => 'USD',
            'disponible' => false,
            'tab' => 'saldos',
            'series' => ['COBRANZA' => 'Cobranza de exportaciones']
        ]
    ];

    /**
     * Todos los proveedores, con su codigo incorporado a cada entrada.
     * Es lo que consume el editor de estructura para armar los desplegables.
     *
     * @return array Lista de entradas, cada una con 'codigo'
     */
    public static function todos() {
        $v = [];

        foreach (self::$providers as $codigo => $meta) {
            $meta['codigo'] = $codigo;
            unset($meta['archivo'], $meta['clase']);   // detalle interno
            $v[] = $meta;
        }

        return $v;
    }

    /**
     * @param string $codigo
     * @return bool
     */
    public static function existe($codigo) {
        return isset(self::$providers[$codigo]);
    }

    /**
     * @param string $codigo
     * @return array|null Metadatos del proveedor, con 'codigo'
     */
    public static function meta($codigo) {
        if (!isset(self::$providers[$codigo])) {
            return null;
        }

        $meta = self::$providers[$codigo];
        $meta['codigo'] = $codigo;

        return $meta;
    }

    /**
     * Si el par (proveedor, serie) es un origen de datos valido.
     *
     * @param string $codigo
     * @param string $serie
     * @return bool
     */
    public static function serieExiste($codigo, $serie) {
        return isset(self::$providers[$codigo]['series'][$serie]);
    }

    /**
     * Si el modulo ya esta construido. Un proveedor no disponible rinde cero.
     *
     * @param string $codigo
     * @return bool
     */
    public static function disponible($codigo) {
        return !empty(self::$providers[$codigo]['disponible']);
    }

    /**
     * Instancia el proveedor. Devuelve null si no esta registrado, si el modulo
     * todavia no existe, o si la clase no se puede cargar.
     *
     * NO lanza: quien llama trata el null como "sin datos" y avisa.
     *
     * @param string $codigo
     * @return CashflowProvider|null
     */
    public static function instanciar($codigo) {
        if (!self::disponible($codigo) || empty(self::$providers[$codigo]['clase'])) {
            return null;
        }

        $meta = self::$providers[$codigo];

        try {
            if (!empty($meta['archivo'])) {
                $ruta = __DIR__ . '/' . $meta['archivo'];

                if (!file_exists($ruta)) {
                    return null;
                }

                require_once $ruta;
            }

            if (!class_exists($meta['clase'])) {
                return null;
            }

            $instancia = new $meta['clase']($codigo);

            return ($instancia instanceof CashflowProvider) ? $instancia : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
