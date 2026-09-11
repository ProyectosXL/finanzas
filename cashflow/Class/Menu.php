<?php

/**
 * Menu
 * Estructura del menu lateral y el ESTADO de cada pestana.
 *
 * POR QUE EXISTE
 * --------------
 * El menu estaba escrito a mano en Components/sidebar.php, con veintiseis
 * enlaces iguales entre si. Eso hacia imposible lo que la pantalla mas
 * necesitaba: distinguir de un vistazo las pestanas que ya tienen datos de las
 * que todavia no se desarrollaron. Con la lista como dato, el estado se resuelve
 * una vez y la vista solo dibuja.
 *
 * LOS TRES ESTADOS, Y POR QUE SON TRES
 * ------------------------------------
 *   'datos'     -> la pestana lee del sistema. Se puede confiar en lo que muestra.
 *   'maqueta'   -> DIBUJA PERO LOS NUMEROS SON DE EJEMPLO. Es el caso del
 *                  Dashboard: no tiene una sola llamada al servidor.
 *   'pendiente' -> todavia no se desarrollo; la pestana muestra el aviso de
 *                  "en construccion".
 *
 * El estado del medio es el importante y es el que faltaba. Un placeholder es
 * HONESTO: dice que no esta hecho. Una maqueta es peor, porque tiene la forma de
 * una pantalla terminada y numeros que parecen reales, asi que sin marcarla se
 * lee como un dato del negocio. Meterla en la misma bolsa que las pestanas con
 * datos seria justamente el error caro que este modulo trata de evitar en todos
 * lados.
 *
 * EL PLACEHOLDER SE DETECTA, NO SE DECLARA
 * ----------------------------------------
 * 'datos' y 'maqueta' son un juicio sobre la pestana y van declarados. Pero si
 * el archivo de la pestana todavia incluye Components/tab_placeholder.php, el
 * estado se BAJA a 'pendiente' sin importar lo declarado.
 *
 * La guarda va en esa direccion a proposito: lo que hay que evitar es que el
 * menu prometa datos que no existen. Asi, una declaracion que quedo vieja se
 * corrige sola, y lo peor que puede pasar es que una pestana recien terminada
 * siga figurando como pendiente hasta que alguien actualice la lista -que es un
 * error visible y sin consecuencias-.
 */
class Menu {

    /** La pestana lee del sistema */
    const DATOS = 'datos';

    /** Dibuja, pero los numeros son de ejemplo */
    const MAQUETA = 'maqueta';

    /** Todavia no se desarrollo */
    const PENDIENTE = 'pendiente';

    /**
     * Que dice el tooltip de cada estado.
     * 'datos' no lleva nada: es el caso normal y un cartel en cada item seria
     * ruido.
     */
    private static $titulos = [
        self::DATOS => '',
        self::MAQUETA => 'Maqueta: la pantalla está dibujada pero los números son '
            . 'de ejemplo, todavía no salen del sistema',
        self::PENDIENTE => 'Todavía no desarrollada: la pestaña muestra un aviso de '
            . 'sección en construcción'
    ];

    /** Icono con el que se marca cada estado. 'datos' no se marca. */
    private static $iconos = [
        self::DATOS => '',
        self::MAQUETA => 'fa-pen-ruler',
        self::PENDIENTE => 'fa-hard-hat'
    ];

    /**
     * Pestanas de nivel raiz, arriba de las categorias.
     * Cashflow va primera porque es la que carga index.php por defecto.
     */
    private static $principales = [
        ['tab' => 'cashflow',  'nombre' => 'Cashflow',  'icono' => 'fa-table-cells', 'estado' => self::DATOS],
        ['tab' => 'dashboard', 'nombre' => 'Dashboard', 'icono' => 'fa-chart-pie',   'estado' => self::MAQUETA]
    ];

    /**
     * Pestanas de nivel raiz que van AL PIE, debajo de todas las categorias.
     *
     * Parametros vive abajo porque no es un modulo de datos como los demas: es
     * la configuracion de todos ellos. Arriba competia por atencion con el
     * tablero, que es la pantalla que se abre para trabajar.
     */
    private static $pie = [
        ['tab' => 'parametros', 'nombre' => 'Parámetros', 'icono' => 'fa-sliders', 'estado' => self::DATOS]
    ];

    /** Las categorias, en el orden en que se muestran */
    private static $categorias = [
        [
            'codigo' => 'Ingresos',
            'nombre' => 'Ingresos',
            'icono' => 'fa-arrow-trend-up',
            'abierta' => true,
            'items' => [
                ['tab' => 'ventas',           'nombre' => 'Ventas',             'icono' => 'fa-cart-shopping',       'estado' => self::DATOS],
                ['tab' => 'saldos',           'nombre' => 'Saldos',             'icono' => 'fa-wallet',              'estado' => self::DATOS],
                ['tab' => 'echeqs',           'nombre' => 'Echeqs',             'icono' => 'fa-money-check-dollar',  'estado' => self::DATOS],
                /* 'encabezado' es el titulo de la pagina, cuando el nombre del
                   menu va abreviado para que entre en el ancho del sidebar. */
                ['tab' => 'cobranzas_fr',     'nombre' => 'Cobranzas FR',       'icono' => 'fa-hand-holding-dollar', 'estado' => self::DATOS, 'encabezado' => 'Cobranzas Franquicias'],
                ['tab' => 'cobranzas_may',    'nombre' => 'Cobranzas May',      'icono' => 'fa-warehouse',           'estado' => self::DATOS, 'encabezado' => 'Cobranzas Mayoristas'],
                ['tab' => 'cob_electronicos', 'nombre' => 'Cob. Electrónicos',  'icono' => 'fa-credit-card',         'estado' => self::DATOS, 'encabezado' => 'Cobranzas Electrónicas'],
                ['tab' => 'exportaciones_tasky', 'nombre' => 'Exportaciones Tasky', 'icono' => 'fa-file-export',   'estado' => self::DATOS]
            ]
        ],
        /* Otros Ingresos va DESPUES de Ingresos y aparte: Ingresos agrupa lo
           que sale de un circuito del sistema -ventas, cobranzas, echeqs- y
           aca va lo que se tipea. La diferencia importa al leer un numero: en
           una fila de Ingresos un cero es "no hay movimientos", y en una de
           estas es "nadie cargo nada todavia".

           Arranca cerrada porque hoy tiene un solo item; queda armada para que
           sumar un concepto nuevo sea agregar una pestana. */
        [
            'codigo' => 'OtrosIngresos',
            'nombre' => 'Otros Ingresos',
            'icono' => 'fa-coins',
            'abierta' => false,
            'items' => [
                ['tab' => 'dolares_comitente', 'nombre' => 'Dólares Cuenta Comitente', 'icono' => 'fa-dollar-sign', 'estado' => self::DATOS]
            ]
        ],
        [
            'codigo' => 'Comex',
            'nombre' => 'Comercio Exterior',
            'icono' => 'fa-ship',
            'abierta' => false,
            'items' => [
                ['tab' => 'proveedores_exterior',  'nombre' => 'Proveedores Exterior',   'icono' => 'fa-earth-americas',       'estado' => self::DATOS],
                ['tab' => 'crono_nacionalizacion', 'nombre' => 'Crono Nacionalización',  'icono' => 'fa-file-invoice-dollar',  'estado' => self::DATOS]
            ]
        ],
        [
            'codigo' => 'Proveedores',
            'nombre' => 'Proveedores',
            'icono' => 'fa-truck',
            'abierta' => false,
            'items' => [
                ['tab' => 'proveedores_locales', 'nombre' => 'Proveedores Locales', 'icono' => 'fa-store',         'estado' => self::PENDIENTE],
                ['tab' => 'cronograma',          'nombre' => 'Cronograma',          'icono' => 'fa-calendar-days', 'estado' => self::PENDIENTE],
                ['tab' => 'logistica_local',     'nombre' => 'Logística Local',     'icono' => 'fa-truck-fast',    'estado' => self::PENDIENTE]
            ]
        ],
        [
            'codigo' => 'RRHH',
            'nombre' => 'RRHH y Operativos',
            'icono' => 'fa-users',
            'abierta' => false,
            'items' => [
                ['tab' => 'haberes',      'nombre' => 'Haberes',                  'icono' => 'fa-users-gear',     'estado' => self::PENDIENTE],
                ['tab' => 'impuestos',    'nombre' => 'Impuestos',                'icono' => 'fa-file-invoice',   'estado' => self::PENDIENTE],
                ['tab' => 'alquileres',   'nombre' => 'Alquileres',               'icono' => 'fa-building',       'estado' => self::PENDIENTE],
                ['tab' => 'seguros',      'nombre' => 'Seguros',                  'icono' => 'fa-shield-halved',  'estado' => self::PENDIENTE],
                ['tab' => 'llaves_renov', 'nombre' => 'Llaves y Renov. Contratos','icono' => 'fa-key',            'estado' => self::PENDIENTE]
            ]
        ],
        [
            'codigo' => 'Financiero',
            'nombre' => 'Financiero',
            'icono' => 'fa-landmark',
            'abierta' => false,
            'items' => [
                ['tab' => 'pagos_tarjetas', 'nombre' => 'Pagos con Tarjetas y Otros', 'icono' => 'fa-money-check',          'estado' => self::PENDIENTE],
                ['tab' => 'otros_socios',   'nombre' => 'Otros Socios y No Prog.',    'icono' => 'fa-handshake',            'estado' => self::PENDIENTE],
                ['tab' => 'bopreal',        'nombre' => 'Bopreal',                    'icono' => 'fa-certificate',          'estado' => self::PENDIENTE],
                ['tab' => 'prestamos',      'nombre' => 'Préstamos',                  'icono' => 'fa-money-bill-trend-up',  'estado' => self::PENDIENTE],
                ['tab' => 'pagos_div',      'nombre' => 'Pagos Div. Marzo',           'icono' => 'fa-money-bill-transfer',  'estado' => self::PENDIENTE]
            ]
        ]
    ];

    /**
     * El menu completo, con el estado de cada pestana ya resuelto y el conteo
     * por categoria.
     *
     * @return array ['principales' => [...], 'categorias' => [...], 'pie' => [...]]
     */
    public static function estructura() {
        return [
            'principales' => self::resolverItems(self::$principales),
            'categorias' => array_map(function ($cat) {
                $cat['items'] = self::resolverItems($cat['items']);
                $cat['con_datos'] = self::contarConDatos($cat['items']);
                $cat['total'] = count($cat['items']);

                return $cat;
            }, self::$categorias),
            'pie' => self::resolverItems(self::$pie)
        ];
    }

    /**
     * Resuelve el estado real de una lista de items y le agrega lo que la vista
     * necesita para dibujar la marca.
     *
     * @param array $items
     * @return array
     */
    private static function resolverItems($items) {
        return array_map(function ($item) {
            $estado = self::estado($item['tab'], $item['estado']);

            $item['estado'] = $estado;
            $item['titulo'] = self::$titulos[$estado];
            $item['icono_estado'] = self::$iconos[$estado];

            // El titulo de la pagina sale de aca y no de una lista aparte en
            // el JS: esa lista se desactualizaba sola y el encabezado
            // terminaba mostrando el codigo de la pestana con guion bajo.
            if (empty($item['encabezado'])) {
                $item['encabezado'] = $item['nombre'];
            }

            return $item;
        }, $items);
    }

    /**
     * Estado efectivo de una pestana.
     *
     * Lo declarado manda, con UNA excepcion: si el archivo sigue siendo un
     * placeholder, el estado baja a 'pendiente'. Ver la nota del encabezado
     * sobre por que la guarda va en esa direccion y no en la otra.
     *
     * @param string $tab Codigo de la pestana
     * @param string $declarado Estado declarado en la lista
     * @return string
     */
    public static function estado($tab, $declarado) {
        if (!isset(self::$titulos[$declarado])) {
            $declarado = self::PENDIENTE;
        }

        return self::esPlaceholder($tab) ? self::PENDIENTE : $declarado;
    }

    /**
     * Si la pestana todavia es un placeholder.
     *
     * Se mira el archivo y no una lista aparte: una lista se desactualiza y esta
     * comprobacion no puede. Los archivos son de unos pocos cientos de bytes y
     * el menu se dibuja una vez por carga de pagina.
     *
     * Un archivo que no existe tambien cuenta como pendiente: TabController cae
     * al placeholder cuando no lo encuentra, asi que es lo que el usuario ve.
     *
     * @param string $tab
     * @return bool
     */
    public static function esPlaceholder($tab) {
        static $cache = [];

        if (isset($cache[$tab])) {
            return $cache[$tab];
        }

        $ruta = __DIR__ . '/../Tabs/' . $tab . '.php';

        if (!file_exists($ruta)) {
            $cache[$tab] = true;

            return true;
        }

        $contenido = file_get_contents($ruta);

        $cache[$tab] = ($contenido !== false
            && strpos($contenido, 'tab_placeholder') !== false);

        return $cache[$tab];
    }

    /**
     * Cuantos items de una categoria tienen datos del sistema.
     *
     * Es lo que muestra el contador de la categoria: sirve para ver el avance
     * sin tener que abrirla.
     *
     * @param array $items Items ya resueltos
     * @return int
     */
    public static function contarConDatos($items) {
        $n = 0;

        foreach ($items as $item) {
            if ($item['estado'] === self::DATOS) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Todos los codigos de pestana que el menu ofrece.
     * Sirve para verificar que no haya un enlace a una pestana que
     * TabController rechaza.
     *
     * @return array
     */
    public static function tabs() {
        $tabs = [];

        foreach (self::$principales as $i) {
            $tabs[] = $i['tab'];
        }

        foreach (self::$categorias as $cat) {
            foreach ($cat['items'] as $i) {
                $tabs[] = $i['tab'];
            }
        }

        foreach (self::$pie as $i) {
            $tabs[] = $i['tab'];
        }

        return $tabs;
    }
}
