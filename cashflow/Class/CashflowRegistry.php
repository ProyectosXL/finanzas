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
                'NETEO_PRECHEQUEADO' => 'Neteo cheques adelantados (negativo)',
                'VENTA' => 'Venta proyectada, total (no es caja)',
                'VENTA_LOCALES' => 'Venta proyectada - Locales (no es caja)',
                'VENTA_FRANQUICIAS' => 'Venta proyectada - Franquicias (no es caja)',
                'VENTA_MAYORISTAS' => 'Venta proyectada - Mayoristas (no es caja)',
                'VENTA_ECOMMERCE' => 'Venta proyectada - Ecommerce (no es caja)'
            ],
            // Una serie total y sus componentes NO pueden estar activas a la
            // vez: seria contar dos veces el mismo importe. El validador de la
            // estructura lo rechaza a partir de esto.
            //
            // NETEO_PRECHEQUEADO NO VA ACA, y no es un olvido: no es un
            // componente de COBRANZA sino una fila independiente que convive
            // con ella. Declararla como componente haria que el validador
            // rechace la combinacion normal del tablero -las cuatro filas por
            // canal mas la del neteo-, que es justamente la que hay que armar.
            'componentes' => [
                'COBRANZA' => ['COBRANZA_LOCALES', 'COBRANZA_FRANQUICIAS',
                               'COBRANZA_MAYORISTAS', 'COBRANZA_ECOMMERCE'],
                'VENTA' => ['VENTA_LOCALES', 'VENTA_FRANQUICIAS',
                            'VENTA_MAYORISTAS', 'VENTA_ECOMMERCE']
            ]
        ],

        'COBRANZAS_FR' => [
            'nombre' => 'Cobranzas Franquicias',
            'descripcion' => 'Cobranza de facturas a franquicias (Real y Proyectada con PPP)',
            'archivo' => 'Providers/IngresosProvider.php',
            'clase' => 'IngresosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'cobranzas_fr',
            'series' => [
                'COBRANZA' => 'Cobranza total franquicias (Real + Proyectada)',
                'COBRANZA_REAL' => 'Cobranza real de franquicias',
                'COBRANZA_PROYECTADA' => 'Cobranza proyectada de pendientes (PPP)',
                'COBRANZA_TOTAL' => 'Cobranza total franquicias (Real + Proyectada)'
            ],
            'componentes' => [
                'COBRANZA_TOTAL' => ['COBRANZA_REAL', 'COBRANZA_PROYECTADA'],
                'COBRANZA' => ['COBRANZA_REAL', 'COBRANZA_PROYECTADA']
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

        /* Suma NETOS, nunca brutos: la diferencia son las retenciones de la
           procesadora, que no llegan al banco. Y a diferencia de SaldosProvider,
           un movimiento con fecha anterior al eje NO abre el horizonte: es un
           movimiento ya ocurrido y esa plata ya la informa el saldo bancario.
           Ver README-cob-electronicos.md. */
        'COB_ELECTRONICOS' => [
            'nombre' => 'Cobranzas Electronicas',
            'descripcion' => 'Acreditaciones de medios electronicos de pago, netas de retenciones',
            'archivo' => 'Providers/CobElectronicosProvider.php',
            'clase' => 'CobElectronicosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'cob_electronicos',
            'series' => ['COBRANZA' => 'Cobranzas electronicas']
        ],

        /* La serie sale UNICAMENTE de los cheques en cartera. La sub-pestana
           Venta Cobrada Anticipada de esa misma pantalla no aporta ninguna
           serie: su efecto es restar de la cobranza proyectada de Ventas. Ver
           el encabezado de Providers/EcheqsProvider.php. */
        'ECHEQS' => [
            'nombre' => 'Echeqs',
            'descripcion' => 'Echeqs en cartera pendientes de acreditacion',
            'archivo' => 'Providers/EcheqsProvider.php',
            'clase' => 'EcheqsProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'echeqs',
            'series' => ['A_COBRAR' => 'Echeqs a cobrar']
        ],

        /* ---- Modulos que todavia no existen -------------------------------- */
        /* Rinden cero y el tablero avisa. Ver la nota del encabezado. */

        'COBRANZAS_MAY' => [
            'nombre' => 'Cobranzas Mayoristas',
            'descripcion' => 'Cobranza proyectada de facturas pendientes a mayoristas (+60 días)',
            'archivo' => 'Providers/IngresosProvider.php',
            'clase' => 'IngresosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'cobranzas_may',
            'series' => ['COBRANZA' => 'Cobranza proyectada de mayoristas']
        ],

        /* Las cuentas a pagar locales, que salen de Tango (CPA04 + CPA54 + CPA01
           con las imputaciones de CPA05). NO incluye a los proveedores del
           exterior: esos entran por COMEX_PROV_EXT y contarlos aca los duplicaria.

           LAS SERIES POR RUBRO SON DINAMICAS y por eso esta entrada declara
           'series_extra'. Cuales existen depende de lo que administracion haya
           cargado en el maestro de proveedores, que es un DATO: escribirlas en
           esta lista obligaria a tocar codigo cada vez que aparece un rubro
           nuevo, que es exactamente lo que este diseño evita en todo lo demas.

           Con las series fijas ya se puede sacar a los "Excluidos" -los socios-
           del tablero apuntando la fila a PAGOS_OPERATIVOS desde Parametros. Las
           de rubro sirven para partir la fila en alquileres, impuestos,
           logistica y mercaderia cuando el maestro este cargado.

           OJO CON 'PAGOS': NO TRAE TODO. Trae solo lo que se paga por echeq o
           transferencia, que es lo que se gestiona desde el cronograma de pagos.
           Es una decision de negocio y deja fuera del tablero los debitos
           automaticos, la caja y la tarjeta corporativa, que igual salen de la
           caja: al 16/09/2026 son $51,8 millones. El proveedor avisa cuanto es
           cada vez. Para el universo completo esta PAGOS_TODO. */
        'PROV_LOCALES' => [
            'nombre' => 'Proveedores Locales',
            'descripcion' => 'Cuentas a pagar a proveedores del mercado local, con su fecha '
                . 'de pago prevista. Sale de Tango y excluye a los del exterior',
            'archivo' => 'Providers/ProveedoresProvider.php',
            'clase' => 'ProveedoresProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'proveedores_locales',
            'series' => [
                'PAGOS' => 'Cuentas a pagar por echeq o transferencia (lo del cronograma)',
                'PAGOS_TODO' => 'Cuentas a pagar locales, TODAS las formas de pago',
                'PAGOS_FUERA_CRONOGRAMA' => 'Solo lo que NO se paga por echeq ni transferencia',
                'PAGOS_OPERATIVOS' => 'Todas, sin los rubros excluidos',
                'PAGOS_EXCLUIDOS' => 'Solo los rubros excluidos (socios y no comerciales)',
                'PAGOS_CRONO_OPERATIVOS' => 'Del cronograma y sin los rubros excluidos '
                    . '(los dos criterios a la vez)',
                'PAGOS_SIN_RUBRO' => 'Solo los proveedores que no estan en el maestro',
                'PAGOS_EXCLUIDOS_FACTURA' => 'Solo las facturas excluidas a mano, una por una'
            ],
            'series_extra' => ['ProveedoresProvider', 'seriesDeRubro'],
            /* EL TOTAL ES 'PAGOS_TODO', NO 'PAGOS'. La fila del tablero usa
               PAGOS -solo el cronograma- porque asi se decidio, pero el universo
               completo es PAGOS_TODO y es contra ese que se mide el doble
               conteo: PAGOS + PAGOS_FUERA_CRONOGRAMA es lo mismo que
               PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS, y las dos particiones suman
               PAGOS_TODO. Las de rubro se agregan en resolverExtra(). */
            'componentes' => [
                'PAGOS_TODO' => ['PAGOS', 'PAGOS_FUERA_CRONOGRAMA', 'PAGOS_OPERATIVOS',
                                 'PAGOS_EXCLUIDOS', 'PAGOS_CRONO_OPERATIVOS',
                                 'PAGOS_SIN_RUBRO', 'PAGOS_EXCLUIDOS_FACTURA']
            ],

            /* LOS CORTES DEL MISMO UNIVERSO. 'componentes' dice que estas seis
               son partes de PAGOS_TODO; esto dice CUALES son partes de la misma
               division.

               Dos series del mismo corte pueden convivir -son dos mitades, y es
               justamente como se mete al tablero lo que hoy queda fuera de la
               fila-. Dos de cortes distintos NO: se solapan casi enteras.
               Activar PAGOS junto a PAGOS_OPERATIVOS contaba dos veces $1.297
               millones y el validador no lo veia, porque miraba el total contra
               sus partes y nunca las partes entre si.

               PAGOS_CRONO_OPERATIVOS no figura en ninguno a proposito: es la
               interseccion de una mitad de cada corte, asi que se solapa con
               las cuatro y no puede convivir con ninguna.

               El corte por rubro lo completa resolverExtra() con las series del
               maestro: son datos y cuales existen depende de la planilla. Dos
               rubros distintos nunca comparten un comprobante -cada uno tiene
               UN rubro- asi que todas juntas son un corte, y por eso partir la
               fila en alquileres, impuestos y logistica sigue siendo valido. */
            'particiones' => [
                'PAGOS_TODO' => [
                    /* TRES PARTES, no dos: una factura excluida a mano no va ni
                       a PAGOS ni a PAGOS_FUERA_CRONOGRAMA. Es lo que hace que el
                       tilde saque el importe de la fila del tablero, que usa
                       PAGOS. Ver ProveedoresProvider::SERIE_EXCLUIDOS_FACTURA. */
                    'por cómo se paga' => ['PAGOS', 'PAGOS_FUERA_CRONOGRAMA',
                                           'PAGOS_EXCLUIDOS_FACTURA'],
                    'por si está excluido' => ['PAGOS_OPERATIVOS', 'PAGOS_EXCLUIDOS'],
                    'por rubro' => ['PAGOS_SIN_RUBRO']
                ]
            ],
            'particion_extra' => 'por rubro'
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

        /* ---- Filas del Excel que se cargaban a mano ------------------------
           En el Excel original estas filas las tipea una persona (Tesoreria,
           Silvina, Dan, Alejandro). Dolares Cuenta Comitente tiene su pantalla
           de carga, y Exportaciones sale directo de Tango: son las facturas
           pendientes a Tasky en GVA12. */

        /* La carga es en DOLARES y la conversion a pesos la hace el proveedor
           con el oficial del BCRA, igual que ComexProvider: el motor nunca ve
           dolares. Es un INGRESO y no una disponibilidad: entra al flujo en la
           fecha que se le carga y no arrastra. */
        'DOLARES_COMITENTE' => [
            'nombre' => 'Dolares Cuenta Comitente',
            'descripcion' => 'Dolares disponibles en la cuenta comitente, cargados a mano',
            'archivo' => 'Providers/OtrosIngresosProvider.php',
            'clase' => 'OtrosIngresosProvider',
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'dolares_comitente',
            'series' => ['INGRESO' => 'Dolares cuenta comitente']
        ],

        /* El otro concepto de Otros Ingresos, mismo proveedor y mismo circuito.
           La moneda es ARS y no USD, a proposito: ese saldo se informa en pesos,
           asi que no hay nada que valuar. Ver el encabezado de
           sql/cashflow_saldo_inversiones.sql antes de cambiarlo.

           YA NO ES UN INGRESO: ES STOCK DE COBERTURA. La serie que usa el
           tablero es STOCK -cuanta plata hay invertida y disponible para tapar
           un bache-, y no entra en ninguna suma: la plata recien se mueve
           cuando alguien aplica cobertura en una fecha, y eso es el proveedor
           COBERTURA.

           INGRESO queda declarada para poder volver atras desde Parametros sin
           tocar codigo, pero son EL MISMO dinero mirado de dos formas: activar
           las dos filas mostraria el saldo dos veces. Por eso van relacionadas
           en 'componentes' y el validador rechaza la combinacion. */
        'SALDO_INVERSIONES' => [
            'nombre' => 'Saldo de Inversiones',
            'descripcion' => 'Saldo de inversiones en pesos, cargado a mano. '
                . 'Es el stock que respalda la cobertura del flujo',
            'archivo' => 'Providers/OtrosIngresosProvider.php',
            'clase' => 'OtrosIngresosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'saldo_inversiones',
            'series' => [
                'STOCK' => 'Saldo invertido disponible para cobertura',
                'INGRESO' => 'Saldo de inversiones como ingreso (criterio viejo, en desuso)'
            ],
            'componentes' => [
                'STOCK' => ['INGRESO'],
                'INGRESO' => ['STOCK']
            ]
        ],

        /* LA APLICACION DE LA COBERTURA: cuanto del saldo invertido se usa en
           cada fecha para tapar un bache del flujo.

           NO DECLARA 'tab' A PROPOSITO. La fila se edita desde el tablero
           mismo, que es donde se ven los saldos negativos; un enlace a otra
           pantalla obligaria a ir y volver comparando columnas, que es
           justamente el trabajo que esta fila existe para evitar. Ver el
           encabezado de Providers/CoberturaProvider.php. */
        'COBERTURA' => [
            'nombre' => 'Cobertura',
            'descripcion' => 'Aplicacion del saldo de inversiones para cubrir los dias con '
                . 'saldo negativo. Se carga desde el propio tablero',
            'archivo' => 'Providers/CoberturaProvider.php',
            'clase' => 'CoberturaProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'series' => ['APLICACION' => 'Cobertura aplicada']
        ],

        /* Facturas pendientes EN DOLARES a Tasky (GVA12, cliente EXTASK). Se
           valuan TODAS a dolar de hoy, a proposito: la deuda esta fija en
           dolares y valuarla a hoy es no suponer devaluacion. Ver el encabezado
           de Providers/ExportacionesProvider.php antes de cambiarlo. */
        'EXPORTACIONES' => [
            'nombre' => 'Exportaciones Tasky',
            'descripcion' => 'Cobranza de las facturas pendientes en dolares a Tasky, la razon '
                . 'social del grupo en Uruguay. Sale de GVA12 y se valua a dolar de hoy',
            'archivo' => 'Providers/ExportacionesProvider.php',
            'clase' => 'ExportacionesProvider',
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'exportaciones_tasky',
            'series' => ['COBRANZA' => 'Cobranza de exportaciones Tasky']
        ]
    ];

    /**
     * Todos los proveedores, con su codigo incorporado a cada entrada.
     * Es lo que consume el editor de estructura para armar los desplegables.
     *
     * @return array Lista de entradas, cada una con 'codigo'
     */
    /**
     * @var array|null Cache de las series dinamicas ya resueltas, por codigo de
     *      proveedor. Se resuelven una vez por pedido.
     */
    private static $extra = null;

    /**
     * SERIES QUE SON DATOS Y NO CODIGO.
     *
     * Casi todos los proveedores tienen una lista fija de series y va escrita
     * arriba. Cuentas a Pagar Locales no: sus series por rubro salen del maestro
     * de proveedores, que carga administracion desde una planilla. Escribirlas
     * en la lista obligaria a tocar codigo cada vez que aparece un rubro nuevo,
     * que es justo lo que este registro existe para evitar.
     *
     * Una entrada puede declarar 'series_extra' => [clase, metodo estatico], y
     * ese metodo devuelve el mapa codigoSerie => descripcion que corresponda
     * HOY.
     *
     * SE RESUELVE UNA SOLA VEZ POR PEDIDO. El editor de estructura y el
     * validador piden los proveedores varias veces; sin el cache, cada una
     * consultaria el maestro.
     *
     * NO PUEDE LANZAR. Si la tabla del maestro no existe todavia o la base no
     * responde, el proveedor se queda con sus series fijas y el editor sigue
     * abriendo. Un registro que revienta deja sin pantalla a doce modulos que no
     * tienen nada que ver.
     *
     * @param string $codigo
     * @param array $meta
     * @return array El meta con sus series y componentes ya completos
     */
    private static function resolverExtra($codigo, $meta) {
        if (empty($meta['series_extra'])) {
            return $meta;
        }

        if (self::$extra === null) {
            self::$extra = [];
        }

        if (!array_key_exists($codigo, self::$extra)) {
            self::$extra[$codigo] = [];

            try {
                list($clase, $metodo) = $meta['series_extra'];

                if (!empty($meta['archivo'])) {
                    $ruta = __DIR__ . '/' . $meta['archivo'];

                    if (file_exists($ruta)) {
                        require_once $ruta;
                    }
                }

                if (class_exists($clase) && method_exists($clase, $metodo)) {
                    $series = call_user_func([$clase, $metodo]);

                    if (is_array($series)) {
                        self::$extra[$codigo] = $series;
                    }
                }
            } catch (Throwable $e) {
                // Se queda con las fijas. Ver la nota de arriba.
                self::$extra[$codigo] = [];
            }
        }

        $extra = self::$extra[$codigo];

        if (empty($extra)) {
            return $meta;
        }

        $meta['series'] = array_merge($meta['series'], $extra);

        // Las aperturas nuevas tambien son partes del total: activarlas junto
        // con el no puede pasar. Ver la regla de doble conteo del validador.
        $total = array_key_first($meta['componentes']);

        if ($total !== null) {
            $meta['componentes'][$total] = array_values(array_unique(
                array_merge($meta['componentes'][$total], array_keys($extra))
            ));

            /* Y van al CORTE que el proveedor declara para ellas. Sin esto, dos
               rubros activos a la vez -que es exactamente para lo que existen-
               serian dos series sin corte y el validador las rechazaria.
               Aparecen recien aca porque cuales existen depende del maestro. */
            $corte = isset($meta['particion_extra']) ? $meta['particion_extra'] : null;

            if ($corte !== null && isset($meta['particiones'][$total][$corte])) {
                $meta['particiones'][$total][$corte] = array_values(array_unique(
                    array_merge($meta['particiones'][$total][$corte], array_keys($extra))
                ));
            }
        }

        return $meta;
    }

    public static function todos() {
        $v = [];

        foreach (self::$providers as $codigo => $meta) {
            $meta = self::resolverExtra($codigo, $meta);
            $meta['codigo'] = $codigo;
            // Detalle interno: como se instancia y de donde salen sus series.
            unset($meta['archivo'], $meta['clase'], $meta['series_extra']);
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

        $meta = self::resolverExtra($codigo, self::$providers[$codigo]);
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
        if (!isset(self::$providers[$codigo])) {
            return false;
        }

        // Pasa por meta() y no por la lista cruda: las series por rubro son
        // datos y no estan escritas arriba. Sin esto, el validador rechazaria
        // una fila configurada contra un rubro del maestro.
        $meta = self::meta($codigo);

        return isset($meta['series'][$serie]);
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
