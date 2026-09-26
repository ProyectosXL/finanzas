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
 *
 * MODULOS RETIRADOS
 * -----------------
 * Un modulo puede dejar de ser la fuente de un dato porque otro circuito lo
 * reemplazo. No se borra del registro -las filas que lo apunten se volverian
 * invalidas y el validador las rechazaria- ni se marca 'disponible' => false,
 * que diria "todavia no construido" sobre algo que existe y funciona. Lleva
 * 'retirado' => 'por que, y que lo reemplaza'. El proveedor sigue sirviendo
 * lo que tenga cargado, el motor avisa que ese dato ya no se mantiene, y el
 * editor de estructura lo muestra marcado. Es el caso de Otros Ingresos desde
 * que el stock de cobertura sale de las cuentas de fondo de Saldos.
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
            /* EL CORTE ES POR QUE SALE DE LA PROYECCION, y son DOS cosas
               distintas. Mismo criterio que la exclusion de cheques de cartera:
               el importe que sale NO DESAPARECE, cambia de serie.

                   PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO

               PAGOS_PAGADOS lo saca el TILDE de la pestana: una afirmacion del
               cashflow sobre su propia proyeccion, que se pone y se saca desde
               acá. PAGOS_COMEX lo sacan los PAGOS CARGADOS EN COMERCIO
               EXTERIOR, que este modulo solo lee. Con una sola serie para las
               dos, el tablero baja y nadie puede contestar cual de las dos
               cosas lo bajo.

               PAGOS cambia de significado y NO de codigo, a proposito: es el
               que la fila del tablero ya tiene configurado, asi que el circuito
               entra sin repuntar ninguna fila ni tocar Parametros. Desde
               feature/comex-saldo-pendiente mide LO QUE FALTA PAGAR y no el FOB
               entero; mientras no haya pagos cargados ni nada marcado vale
               exactamente lo mismo que antes.

               Y PAGOS_TODO PASA A SER EL FOB COMPLETO, que es lo que esta fila
               proyectaba antes de esa rama: quien quiera ver el antes y el
               despues del cambio lo tiene en el mismo tablero. */
            'series' => [
                'PAGOS' => 'Lo que falta pagar a proveedores del exterior',
                'PAGOS_PAGADOS' => 'Solo lo tildado como ya hecho desde el cashflow',
                'PAGOS_COMEX' => 'Lo que Comercio Exterior ya registro como pagado',
                'PAGOS_TODO' => 'Pagos al exterior, TODO: el FOB completo de lo listado'
            ],
            'componentes' => [
                'PAGOS_TODO' => ['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX']
            ]
        ],

        'COMEX_NAC' => [
            'nombre' => 'Nacionalizaciones',
            'descripcion' => 'Cronograma de gastos de nacionalizacion',
            'archivo' => 'Providers/ComexProvider.php',
            'clase' => 'ComexProvider',
            /* EN DOLARES, igual que los pagos al exterior. Decia 'ARS' hasta
               feature/comex-nac-usd, y era la misma afirmacion equivocada que
               tenia el proveedor: los conceptos 3 a 10 de la estimacion se
               calculan sobre el CIF, que arranca en el FOB en dolares. El
               proveedor los convierte con la curva ROFEX antes de devolverlos. */
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'crono_nacionalizacion',
            /* Mismo corte que COMEX_PROV_EXT, sobre el otro pago del mismo
               contenedor: son plata distinta y se marcan por separado. */
            'series' => [
                'NACIONALIZACION' => 'Gastos de nacionalizacion pendientes',
                'NACIONALIZACION_PAGADAS' => 'Solo las nacionalizaciones marcadas como pagadas',
                'NACIONALIZACION_TODO' => 'Nacionalizaciones, TODAS: pendientes y ya pagadas'
            ],
            'componentes' => [
                'NACIONALIZACION_TODO' => ['NACIONALIZACION', 'NACIONALIZACION_PAGADAS']
            ]
        ],

        /* LA PARTE PROYECTADA DE LOS DOS EGRESOS DE COMERCIO EXTERIOR.
           Es un proveedor SEPARADO de COMEX_PROV_EXT y COMEX_NAC, y tiene que
           serlo: miden dos universos que no se pisan.

             COMEX_*        lo que YA tiene contenedor cargado, ubicado por las
                            fechas del maestro, contenedor por contenedor.
             COMPRAS_PROY   lo que TODAVIA NO, repartido por la cuota historica
                            sobre lo que falta comprar del presupuesto oficial
                            de la app de compras.

           SUS SERIES NUNCA SE SUMAN A LAS DE COMEX, y por eso no hay
           'componentes' que las relacione: no son partes de un mismo total sino
           dos universos disjuntos. Lo que garantiza que no se pisen no es una
           regla del registro sino la cuenta misma: la estimacion de cada mes ya
           viene NETA del pendiente de los contenedores cuya orden se emitio
           despues de la fecha de calculo del presupuesto, y lo anterior a esa
           fecha ya esta descontado adentro del presupuesto.

           EN EL TABLERO CADA PAR VA EN UN GRUPO -PROV_EXTERIOR y
           NACIONALIZACIONES-, con la fila de Comex como parte REAL y esta como
           PROYECTADO. Eso es presentacion y lo arma sql/cashflow_compras_
           proyectadas.sql; el motor no sabe que los grupos existen. */
        'COMPRAS_PROY' => [
            'nombre' => 'Compras Exterior',
            'descripcion' => 'Pagos de FOB y nacionalizacion de las compras del exterior que '
                . 'todavia no tienen contenedor cargado, segun el presupuesto oficial de compras',
            'archivo' => 'Providers/ComprasProyectadasProvider.php',
            'clase' => 'ComprasProyectadasProvider',
            /* En dolares, como las dos de Comex: el presupuesto da el FOB
               unitario en U$S y el proveedor lo valua con la curva ROFEX. */
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'compras_proyectadas',
            'series' => [
                'PAGOS_PROYECTADOS' => 'Pagos de FOB estimados de lo que falta comprar',
                'NACIONALIZACION_PROYECTADA' => 'Nacionalizacion estimada de lo que falta comprar'
            ]
        ],

        /* SaldosProvider sirve dos codigos, igual que ComexProvider: cada
           instancia corre solo la consulta de su serie. Van separados porque
           leen dos servidores distintos, y asi una caida del servidor de
           locales no se lleva puesto el disponible bancario. */
        /* TODO LO QUE APORTA ES PROYECCION, y por eso su fila NO esta partida
           en REAL y PROYECTADO como las dos de Comex: los pagos a fleteros no
           salen de ningun comprobante, se calculan con las horas del mes y el
           valor hora. No hay parte real que conciliar.

           La fila LOGISTICA ya existe en RO_T_CASHFLOW_CONF_FILA desde
           sql/cashflow_estructura.sql, apuntada a este par. Lo que cambio es
           que el proveedor existe, asi que dejo de rendir cero. */
        'LOGISTICA' => [
            'nombre' => 'Logistica',
            'descripcion' => 'Pagos proyectados a los fleteros: horas por mes por el valor hora '
                . 'del mes, ajustado cada tres meses por inflacion, repartido mitad y mitad en '
                . 'el 2do y el 4to viernes. Sin IVA ni otros conceptos',
            'archivo' => 'Providers/LogisticaProvider.php',
            'clase' => 'LogisticaProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'logistica_local',
            'series' => ['PAGOS' => 'Pagos proyectados a fleteros']
        ],

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

        /* EL STOCK DE COBERTURA SALE DE LAS CUENTAS DE FONDO DEL CATALOGO DE
           SALDOS: las de clase INVERSION y las de clase COMITENTE, cada una con
           su cuenta corriente (saldo inicial + suscripciones - rescates).
           FondosProvider sirve los dos codigos, uno por clase, igual que
           SaldosProvider sirve SALDOS y CAJA_LOCALES.

           NO ENTRAN EN DISPONIBILIDADES. Su unico rol en el tablero es ser
           stock de la seccion Cobertura; si entraran a los dos lados la misma
           plata se contaria dos veces. SaldosProvider las deja afuera.

           CADA CUENTA ES UN FONDO. La serie trae 'por_fondo' con el stock de
           cada cuenta, por su clave, y es con eso que el motor descuenta lo
           aplicado desde cada una. No hay ninguna cuenta escrita en el codigo:
           se dan de alta desde Parametros -> Saldos.

           'moneda' es informativa: la moneda la dice cada cuenta y el
           proveedor valua las que estan en dolares con la ultima cotizacion
           oficial a hoy, punta vendedora, como se valuaba la foto de la cuenta
           comitente. */
        'FONDO_INVERSION' => [
            'nombre' => 'Cuentas de inversión',
            'descripcion' => 'Saldo de las cuentas de inversión del catálogo de Saldos: saldo '
                . 'inicial más suscripciones menos rescates. Es stock de cobertura',
            'archivo' => 'Providers/FondosProvider.php',
            'clase' => 'FondosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'saldos',
            'subtab' => 'fondos',
            'series' => ['STOCK' => 'Saldo a hoy de las cuentas de inversión']
        ],

        'FONDO_COMITENTE' => [
            'nombre' => 'Cuentas comitente',
            'descripcion' => 'Saldo de las cuentas comitente del catálogo de Saldos, en dólares '
                . 'valuados a hoy. Es stock de cobertura',
            'archivo' => 'Providers/FondosProvider.php',
            'clase' => 'FondosProvider',
            'moneda' => 'USD',
            'disponible' => true,
            'tab' => 'saldos',
            'subtab' => 'fondos',
            'series' => ['STOCK' => 'Saldo a hoy de las cuentas comitente']
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

        /* Las series salen UNICAMENTE de los cheques en cartera. La sub-pestana
           Venta Cobrada Anticipada de esa misma pantalla no aporta ninguna
           serie: su efecto es restar de la cobranza proyectada de Ventas. Ver
           el encabezado de Providers/EcheqsProvider.php.

           UN SOLO CORTE: A_COBRAR + A_COBRAR_EXCLUIDOS = A_COBRAR_TODO.

           OJO CON 'A_COBRAR': YA NO TRAE TODO. Trae la cartera que se va a
           poder cobrar, o sea sin los cheques excluidos a mano. Es el codigo
           que la fila del tablero ya tenia configurado y por eso no cambio
           -asi el circuito de exclusion entro sin repuntar ninguna fila-, pero
           su significado si: lo que A_COBRAR era hasta entonces es hoy
           A_COBRAR_TODO. Mientras no haya ningun cheque excluido los dos valen
           lo mismo. */
        'ECHEQS' => [
            'nombre' => 'Echeqs',
            'descripcion' => 'Echeqs en cartera pendientes de acreditacion',
            'archivo' => 'Providers/EcheqsProvider.php',
            'clase' => 'EcheqsProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'echeqs',
            'series' => [
                'A_COBRAR' => 'Echeqs a cobrar (sin los excluidos a mano)',
                'A_COBRAR_EXCLUIDOS' => 'Solo los echeqs excluidos a mano, uno por uno',
                'A_COBRAR_TODO' => 'Echeqs en cartera, TODOS: cobrables y excluidos'
            ],
            /* El total es A_COBRAR_TODO, igual que en Proveedores Locales: la
               fila del tablero usa A_COBRAR porque asi se decidio, pero el
               universo contra el que se mide el doble conteo es el otro.

               NO HACE FALTA declarar 'particiones': hay un solo corte, y sin
               declaracion el validador toma todas las partes como el mismo
               corte, que es exactamente lo que son. Las dos pueden convivir
               -son las dos mitades- y cualquiera de ellas junto al total, no. */
            'componentes' => [
                'A_COBRAR_TODO' => ['A_COBRAR', 'A_COBRAR_EXCLUIDOS']
            ]
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
                'PAGOS_EXCLUIDOS_FACTURA' => 'Solo las facturas excluidas a mano, una por una',
                'PAGOS_EXCLUIDOS_PROVEEDOR' => 'Solo los proveedores excluidos de Proveedores '
                    . 'Locales porque ya se consideran en otra pestaña'
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
                                 'PAGOS_SIN_RUBRO', 'PAGOS_EXCLUIDOS_FACTURA',
                                 'PAGOS_EXCLUIDOS_PROVEEDOR']
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
                    /* Y CUATRO desde la exclusion por proveedor, por el mismo
                       motivo: un proveedor excluido de este modulo tiene que
                       salir de PAGOS, y solo una serie de este corte lo saca.
                       Ver ProveedoresProvider::SERIE_EXCLUIDOS_PROVEEDOR. */
                    'por cómo se paga' => ['PAGOS', 'PAGOS_FUERA_CRONOGRAMA',
                                           'PAGOS_EXCLUIDOS_FACTURA',
                                           'PAGOS_EXCLUIDOS_PROVEEDOR'],
                    'por si está excluido' => ['PAGOS_OPERATIVOS', 'PAGOS_EXCLUIDOS'],
                    'por rubro' => ['PAGOS_SIN_RUBRO']
                ]
            ],
            'particion_extra' => 'por rubro'
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

        /* ---- Pagos con Tarjetas y Otros -------------------------------------
           Las tres partes de la pestana Financiero -> Pagos con Tarjetas y Otros:
           los gastos con tarjeta de las supervisoras, las facturas de Tango que se
           pagan con tarjeta corporativa, y las tarjetas de los socios.

           LA FILA DEL TABLERO USA 'TOTAL', UNA SOLA. Las tres partes quedan
           declaradas y sin usar, para poder partir la fila desde Parametros el dia
           que se quiera, sin tocar codigo. Ver sql/cashflow_tarjetas_fila.sql.

           NO SE TOCA LA ENTRADA 'FINANCIERO', que sigue apuntando a la pestana
           prestamos: es otro circuito -prestamos y movimientos financieros- y su
           fila del tablero vive en la seccion AJUSTES, hoy inhabilitada. Meter las
           tarjetas ahi adentro habria mezclado dos cosas que se cargan y se miran
           por separado. */
        'TARJETAS' => [
            'nombre' => 'Pagos con Tarjetas y Otros',
            'descripcion' => 'Los gastos con tarjeta y en efectivo de las supervisoras '
                . '(promedio de los ultimos 3 meses, ajustado por inflacion), las facturas '
                . 'pendientes de Tango de proveedores con forma de pago TARJETA CORP con su '
                . 'cobertura, y las tarjetas de los socios (promedio de los ultimos 3 resumenes, '
                . 'con el componente en U$S convertido). Un resumen cargado pisa la estimacion '
                . 'del mes',
            'archivo' => 'Providers/TarjetasProvider.php',
            'clase' => 'TarjetasProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'tab' => 'pagos_tarjetas',
            'series' => [
                'TOTAL' => 'Las tres partes juntas (es la que usa la fila del tablero)',
                'SUPERVISORAS' => 'Gastos de supervision: efectivo + tarjeta',
                'CORPORATIVAS' => 'Facturas de tarjeta corporativa + cobertura, o el resumen',
                'SOCIOS' => 'Tarjetas de socios, en pesos y con el U$S ya convertido',
                'CORPORATIVAS_EXCLUIDAS' => 'Solo las facturas excluidas a mano de esta pestana '
                    . '(informativa, FUERA del total)'
            ],

            /* EL TOTAL Y SUS TRES PARTES NO PUEDEN CONVIVIR: activar una fila con
               SUPERVISORAS al lado de la que usa TOTAL contaria dos veces el mismo
               peso, y el validador lo rechaza. Para partir la fila hay que
               inhabilitar la del total y activar las tres.

               CORPORATIVAS_EXCLUIDAS NO ENTRA ACA a proposito: su importe NO esta
               en el total -por eso es informativa- asi que puede convivir con la
               fila del total sin duplicar nada.

               NO HAY SERIE DE UNIVERSO, a diferencia de PAGOS_TODO y de
               A_COBRAR_TODO: CORPORATIVAS no es solo facturas -lleva la cobertura y
               puede quedar reemplazada por el resumen- asi que un "TODO" seria la
               suma de cosas de distinta naturaleza y no la particion de nada. Ver
               el encabezado de TarjetasProvider. */
            'componentes' => [
                'TOTAL' => ['SUPERVISORAS', 'CORPORATIVAS', 'SOCIOS']
            ]
        ],

        'FINANCIERO' => [
            'nombre' => 'Financiero',
            'descripcion' => 'Prestamos y movimientos financieros',
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

        /* ---- Otros Ingresos: RETIRADOS ---------------------------------------
           En el Excel original estas filas las tipeaba una persona: una foto
           del saldo de inversiones (en pesos) y otra de los dolares de la
           cuenta comitente. Primero fueron INGRESOS, despues STOCK DE
           COBERTURA, y desde sql/cashflow_saldos_cuentas_fondo.sql el stock
           sale de las CUENTAS DE FONDO del catalogo de Saldos (FONDO_INVERSION
           y FONDO_COMITENTE, mas arriba), que llevan cuenta corriente en vez
           de foto.

           QUEDAN DECLARADOS, CON LA MARCA DE RETIRADOS. Borrarlos dejaria
           invalida cualquier fila que todavia los apunte, y ponerlos en
           'disponible' => false diria "sin construir" sobre algo que existe:
           el proveedor sigue leyendo sus tablas, que no se borran por el
           historico. Lo que cambia es que el dato ya no se mantiene, y el
           motor lo avisa en cada fila que siga leyendo de aca.

           NO DECLARAN 'tab': las dos pestanas de Otros Ingresos se eliminaron
           para que no confundan -una pantalla que abre y guarda, y cuyo
           numero no va a ningun lado-. Una fila que los apunte no queda como
           enlace, igual que la de Cobertura.

           YA NO DECLARAN 'origen_cobertura': el fondo dejo de ser una constante
           del registro. Cada cuenta de fondo es un fondo, y el reparto viaja
           con la serie ('por_fondo'). Un stock de estos, si alguien vuelve a
           apuntarle una fila, suma al total de cobertura y a ningun fondo.

           Para volver atras desde Parametros -> Cashflow: apuntar las filas de
           stock de nuevo a estos codigos, serie STOCK. Las series INGRESO
           siguen declaradas por el mismo motivo de siempre. */
        'DOLARES_COMITENTE' => [
            'nombre' => 'Dolares Cuenta Comitente',
            'descripcion' => 'Foto de los dolares de la cuenta comitente, cargada a mano. '
                . 'RETIRADO: lo reemplazan las cuentas comitente de Saldos',
            'archivo' => 'Providers/OtrosIngresosProvider.php',
            'clase' => 'OtrosIngresosProvider',
            'moneda' => 'USD',
            'disponible' => true,
            'retirado' => 'los dólares de la cuenta comitente ahora son una cuenta de Saldos '
                . '(clase Cuenta comitente) con cuenta corriente propia, y el stock de '
                . 'cobertura sale de ahí.',
            'series' => [
                'STOCK' => 'Ultima foto de los dolares (retirado)',
                'INGRESO' => 'Dolares cuenta comitente como ingreso (criterio viejo, en desuso)'
            ],
            'componentes' => [
                'STOCK' => ['INGRESO'],
                'INGRESO' => ['STOCK']
            ]
        ],

        'SALDO_INVERSIONES' => [
            'nombre' => 'Saldo de Inversiones',
            'descripcion' => 'Foto del saldo de inversiones en pesos, cargada a mano. '
                . 'RETIRADO: lo reemplazan las cuentas de inversión de Saldos',
            'archivo' => 'Providers/OtrosIngresosProvider.php',
            'clase' => 'OtrosIngresosProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'retirado' => 'el saldo de inversiones ahora son cuentas de Saldos (clase '
                . 'Inversión) con cuenta corriente propia, y el stock de cobertura sale de ahí.',
            'series' => [
                'STOCK' => 'Ultima foto del saldo invertido (retirado)',
                'INGRESO' => 'Saldo de inversiones como ingreso (criterio viejo, en desuso)'
            ],
            'componentes' => [
                'STOCK' => ['INGRESO'],
                'INGRESO' => ['STOCK']
            ]
        ],

        /* LA APLICACION DE LA COBERTURA: cuanto del saldo invertido se usa en
           cada fecha para tapar un bache del flujo.

           UNA SERIE POR CLASE DE FONDO, porque hay una fila del tablero por
           clase: "Uso de Inversiones" y "Uso de Dolares comitente". Lo que
           sale del proveedor es SOLO lo cargado a mano; lo demas lo calcula
           el motor en cada carga y lo suma a esas mismas filas. APLICACION es
           el total de antes de la apertura: queda para poder volver atras, y
           'componentes' impide tenerla activa junto con una de las partes,
           que contaria dos veces lo manual.

           NO DECLARA 'tab' A PROPOSITO. La fila se edita desde el tablero
           mismo, que es donde se ven los saldos negativos; un enlace a otra
           pantalla obligaria a ir y volver comparando columnas, que es
           justamente el trabajo que esta fila existe para evitar. Ver el
           encabezado de Providers/CoberturaProvider.php. */
        'COBERTURA' => [
            'nombre' => 'Cobertura',
            'descripcion' => 'Uso de las cuentas de inversión y comitente para cubrir los días '
                . 'con saldo negativo. Lo calcula el motor; lo cargado a mano desde el '
                . 'tablero lo pisa',
            'archivo' => 'Providers/CoberturaProvider.php',
            'clase' => 'CoberturaProvider',
            'moneda' => 'ARS',
            'disponible' => true,
            'series' => [
                'USO_INVERSION' => 'Uso de las cuentas de inversión (manual + calculado)',
                'USO_COMITENTE' => 'Uso de las cuentas comitente (manual + calculado)',
                'APLICACION' => 'Uso de todos los fondos juntos (una sola fila)'
            ],
            'componentes' => [
                'APLICACION' => ['USO_INVERSION', 'USO_COMITENTE']
            ]
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
     * Si el modulo fue retirado: sigue sirviendo, pero su dato ya no se
     * mantiene porque lo reemplazo otro circuito. Ver el encabezado.
     *
     * @param string $codigo
     * @return bool
     */
    public static function retirado($codigo) {
        return !empty(self::$providers[$codigo]['retirado']);
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
