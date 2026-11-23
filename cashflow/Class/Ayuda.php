<?php

require_once __DIR__ . '/AyudaArticulo.php';
require_once __DIR__ . '/AyudaRender.php';
require_once __DIR__ . '/AyudaBusqueda.php';
require_once __DIR__ . '/Menu.php';

/**
 * Ayuda
 * La ayuda funcional del cashflow: lee los articulos de Ayuda/ y arma lo que
 * piden el Centro de ayuda y el panel lateral de cada pestana.
 *
 * DOS MITADES, COMO EL RESTO DEL MODULO
 * -------------------------------------
 *   - leerArchivos() / cargar(): lo unico que toca el disco.
 *   - todo lo demas es estatico y puro: recibe los articulos ya leidos y el
 *     menu, y devuelve arrays. Se prueba sin disco (test_ayuda_centro.php).
 *
 * QUIEN VE QUE
 * ------------
 * Todos los que entran al cashflow ven todos los articulos, tengan o no
 * permiso sobre la pestana: la ayuda explica como funciona una pantalla, no
 * muestra sus datos. Por eso los ejemplos llevan numeros inventados (ver
 * AyudaRender::EJEMPLO_TIPO). El permiso es el de la pestana 'ayuda', que es
 * de todos (AuthCashflow::PARA_TODOS).
 *
 * LA AYUDA NO SE ESCRIBE DESDE LA APP: no hay tabla ni pantalla de edicion.
 * Los articulos los mantiene quien cambia el codigo, en la misma rama; la
 * regla esta en README-cashflow.md, "La ayuda funcional".
 */
class Ayuda {

    /** Donde estan los articulos */
    const DIR = __DIR__ . '/../Ayuda';

    /**
     * Las pestanas que no estan en una categoria y cuya ayuda es un articulo
     * general: el tablero es la pantalla que explica "Como leer el tablero", y
     * Parametros es la mitad de "Permisos y parametros". El resto de las
     * pestanas busca un archivo con su mismo nombre.
     */
    const ARTICULO_DE_TAB = [
        'cashflow' => 'general_tablero',
        'parametros' => 'general_permisos_parametros'
    ];

    /** En el Centro de ayuda no hay boton de ayuda: ya se esta ahi */
    const SIN_PANEL = ['ayuda'];

    /** Los generales, en el orden de "Primeros pasos" */
    const GENERALES = [
        'general_tablero',
        'general_real_proyectado',
        'general_avisos',
        'general_permisos_parametros'
    ];

    /* ====================================================================
       EL DISCO
       ==================================================================== */

    /**
     * El texto de cada articulo, por nombre.
     *
     * Solo nombres en minusculas, numeros y guion bajo: el nombre llega del
     * navegador y termina en una ruta, asi que nada de ".." ni barras.
     *
     * @param string|null $dir Otra carpeta (pruebas)
     * @return array nombre => texto
     */
    public static function leerArchivos($dir = null) {
        $out = [];

        foreach (glob(($dir ?: self::DIR) . '/*.md') ?: [] as $ruta) {
            $nombre = basename($ruta, '.md');

            if (self::nombreValido($nombre)) {
                $out[$nombre] = (string) file_get_contents($ruta);
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Los articulos leidos y parseados, con la categoria con la que se muestran.
     *
     * @param string|null $dir
     * @return array nombre => ['art' => AyudaArticulo::parsear(), 'categoria']
     */
    public static function cargar($dir = null) {
        $out = [];

        foreach (self::leerArchivos($dir) as $nombre => $texto) {
            $art = AyudaArticulo::parsear($texto);
            $out[$nombre] = ['art' => $art, 'categoria' => (string) ($art['meta']['categoria'] ?? '')];
        }

        return $out;
    }

    /* ====================================================================
       PURO
       ==================================================================== */

    /**
     * Si un texto puede ser el nombre de un articulo.
     *
     * @param string $nombre
     * @return bool
     */
    public static function nombreValido($nombre) {
        return (bool) preg_match('/^[a-z0-9_]{1,60}$/', (string) $nombre);
    }

    /**
     * El articulo de una pestana, o null si no tiene.
     *
     * @param string $tab
     * @param array $articulos nombre => lo que sea (solo se miran las claves)
     * @return string|null
     */
    public static function articuloDeTab($tab, $articulos) {
        if (in_array($tab, self::SIN_PANEL, true)) {
            return null;
        }

        $nombre = self::ARTICULO_DE_TAB[$tab] ?? $tab;

        return (self::nombreValido($nombre) && isset($articulos[$nombre])) ? $nombre : null;
    }

    /**
     * Pestana => nombre de su categoria, de las que estan en una categoria del
     * menu. Es contra lo que se valida la 'categoria' de un articulo.
     *
     * @param array $menu Menu::estructuraCompleta()
     * @return array
     */
    public static function categoriasDeTabs($menu) {
        $out = [];

        foreach ($menu['categorias'] as $cat) {
            foreach ($cat['items'] as $item) {
                $out[$item['tab']] = $cat['nombre'];
            }
        }

        return $out;
    }

    /**
     * Las pestanas en 'datos' de todo el menu -principales, categorias y pie-:
     * las que tienen que tener ayuda. La prueba de cobertura recorre esta
     * lista. Va el Centro de ayuda tambien, para que la prueba lo vea y lo
     * saltee a proposito (SIN_PANEL), no por omision.
     *
     * @param array $menu Menu::estructuraCompleta()
     * @return array [tab]
     */
    public static function tabsConDatos($menu) {
        $items = array_merge($menu['principales'], $menu['pie']);

        foreach ($menu['categorias'] as $cat) {
            $items = array_merge($items, $cat['items']);
        }

        $out = [];

        foreach ($items as $item) {
            if ($item['estado'] === Menu::DATOS) {
                $out[] = $item['tab'];
            }
        }

        return $out;
    }

    /**
     * Todos los errores de un articulo: los del formato, los de la plantilla y
     * los del renderizado (con los enlaces validados contra los demas).
     *
     * @param string $nombre
     * @param array $art
     * @param array $articulos Todos, para validar los enlaces
     * @param array $tabs De categoriasDeTabs()
     * @param string|null $hoy
     * @return array
     */
    public static function errores($nombre, $art, $articulos, $tabs, $hoy = null) {
        $r = AyudaRender::articulo($art, ['destinos' => self::destinos($articulos)]);

        return array_merge($art['errores'], AyudaArticulo::validar($art, $nombre, $tabs, $hoy), $r['errores']);
    }

    /**
     * Las anclas de cada articulo: a donde puede apuntar un enlace "ayuda:".
     *
     * @param array $articulos
     * @return array nombre => [ancla]
     */
    public static function destinos($articulos) {
        $out = [];

        foreach ($articulos as $nombre => $a) {
            $out[$nombre] = array_column(AyudaArticulo::anclas($a['art']), 'ancla');
        }

        return $out;
    }

    /**
     * Lo que muestra la pantalla de inicio del Centro de ayuda.
     *
     * "Ayuda por modulo" son las categorias del menu, como en el menu, con
     * cada pestana en 'datos' y el estado de su articulo. Una pestana sin
     * archivo es "Pendiente" y no se abre. Se usa el menu COMPLETO, sin
     * filtrar por permisos: todos ven todos los articulos.
     *
     * @param array $menu Menu::estructuraCompleta()
     * @param array $articulos De cargar()
     * @return array ['modulos', 'generales', 'preguntas', 'frecuentes']
     */
    public static function centro($menu, $articulos) {
        $modulos = [];

        foreach ($menu['categorias'] as $cat) {
            $items = [];

            foreach ($cat['items'] as $item) {
                if ($item['estado'] !== Menu::DATOS) {
                    continue;
                }

                $nombre = self::articuloDeTab($item['tab'], $articulos);
                $items[] = [
                    'tab' => $item['tab'],
                    'nombre' => $item['nombre'],
                    'icono' => $item['icono'],
                    'articulo' => $nombre
                ] + self::estadoDe($nombre !== null ? $articulos[$nombre]['art'] : null);
            }

            if ($items) {
                $modulos[] = ['nombre' => $cat['nombre'], 'icono' => $cat['icono'], 'items' => $items];
            }
        }

        $generales = [];
        $orden = array_merge(self::GENERALES, array_keys($articulos));

        foreach (array_unique($orden) as $nombre) {
            if (!isset($articulos[$nombre]) || AyudaArticulo::esDePestana($articulos[$nombre]['art'])) {
                continue;
            }

            $meta = $articulos[$nombre]['art']['meta'];
            $generales[] = [
                'articulo' => $nombre,
                'titulo' => (string) ($meta['titulo'] ?? $nombre),
                'bajada' => AyudaRender::enLinea($meta['bajada'] ?? '')
            ] + self::estadoDe($articulos[$nombre]['art']);
        }

        $preguntas = [];

        if (isset($articulos['general_tablero'])) {
            foreach (AyudaArticulo::preguntas($articulos['general_tablero']['art']) as $p) {
                $preguntas[] = $p + ['enlace' => AyudaRender::direccion('general_tablero', $p['ancla'])];
            }
        }

        return [
            'modulos' => $modulos,
            'generales' => $generales,
            'preguntas' => $preguntas,
            'frecuentes' => AyudaBusqueda::FRECUENTES
        ];
    }

    /**
     * El estado de un articulo para mostrar: la etiqueta, y si va a la vista.
     * Sin articulo, "Pendiente".
     *
     * @param array|null $art
     * @return array ['estado', 'etiqueta', 'actualizado']
     */
    public static function estadoDe($art) {
        if ($art === null) {
            return ['estado' => AyudaArticulo::PENDIENTE, 'etiqueta' => 'Pendiente', 'actualizado' => ''];
        }

        $estado = $art['meta']['estado'] ?? '';
        $estado = isset(AyudaArticulo::ESTADOS[$estado]) ? $estado : AyudaArticulo::EN_REVISION;

        return [
            'estado' => $estado,
            'etiqueta' => AyudaArticulo::ESTADOS[$estado],
            'actualizado' => AyudaArticulo::fechaLegible($art['meta']['actualizado'] ?? '')
        ];
    }

    /**
     * Lo que muestra el panel lateral de una pestana.
     *
     * De un articulo de pestana, las secciones "Para que sirve", "De donde
     * salen los datos" y "La pantalla", completas, y los titulos de las
     * preguntas frecuentes. De un general, todas sus secciones: no tienen
     * plantilla, y son los cortos.
     *
     * Con $q, cada seccion y cada pregunta dice si contiene el texto, y el
     * HTML llega con el texto marcado: el buscador del panel esconde lo que
     * no coincide. El criterio es el del servidor -uno solo, probado-.
     *
     * @param string $tab
     * @param array $menu Menu::estructuraCompleta()
     * @param array $articulos De cargar()
     * @param array $tabs De categoriasDeTabs()
     * @param string $q
     * @param string|null $hoy
     * @return array
     */
    public static function panel($tab, $menu, $articulos, $tabs, $q = '', $hoy = null) {
        $nombre = self::articuloDeTab($tab, $articulos);

        if ($nombre === null) {
            return [
                'encontrado' => false,
                'titulo' => Menu::tituloTab($tab),
                'centro' => AyudaRender::direccion('')
            ];
        }

        $art = $articulos[$nombre]['art'];
        $r = AyudaRender::articulo($art, ['resaltar' => $q]);
        $secciones = $r['secciones'];

        if (AyudaArticulo::esDePestana($art)) {
            $secciones = array_values(array_filter($secciones, function ($s) {
                $i = array_search($s['titulo'], AyudaArticulo::SECCIONES_PESTANA, true);

                return $i !== false && in_array($i, AyudaArticulo::SECCIONES_PANEL, true);
            }));
        }

        $preguntas = array_map(function ($p) use ($nombre, $q) {
            return $p + [
                'enlace' => AyudaRender::direccion($nombre, $p['ancla']),
                'titulo_html' => AyudaRender::resaltado($p['titulo'], $q),
                'coincide' => trim($q) === '' || AyudaRender::contiene($p['titulo'], $q)
            ];
        }, AyudaArticulo::preguntas($art));

        // Una pregunta que esta entre las secciones mostradas (un general) ya
        // se ve ahi: listarla de nuevo abajo seria repetirla.
        if (!AyudaArticulo::esDePestana($art)) {
            $preguntas = [];
        }

        return [
            'encontrado' => true,
            'articulo' => $nombre,
            'titulo' => (string) ($art['meta']['titulo'] ?? $nombre),
            'secciones' => array_map(function ($s) {
                return ['ancla' => $s['ancla'], 'titulo' => $s['titulo'], 'html' => $s['html'], 'coincide' => $s['coincide']];
            }, $secciones),
            'preguntas' => $preguntas,
            'errores' => self::errores($nombre, $art, $articulos, $tabs, $hoy),
            'completo' => AyudaRender::direccion($nombre),
            'centro' => AyudaRender::direccion('')
        ] + self::estadoDe($art);
    }

    /**
     * El articulo completo, para el Centro de ayuda.
     *
     * @param string $nombre
     * @param array $articulos De cargar()
     * @param array $tabs De categoriasDeTabs()
     * @param string|null $hoy
     * @return array|null null si no existe
     */
    public static function completo($nombre, $articulos, $tabs, $hoy = null) {
        if (!self::nombreValido($nombre) || !isset($articulos[$nombre])) {
            return null;
        }

        $art = $articulos[$nombre]['art'];
        $meta = $art['meta'];
        $r = AyudaRender::articulo($art);

        $indice = [];

        foreach (AyudaArticulo::anclas($art) as $a) {
            if ($a['tipo'] !== 'pregunta') {
                $indice[] = ['ancla' => $a['ancla'], 'titulo' => $a['titulo'], 'nivel' => $a['tipo'] === 'seccion' ? 2 : 3];
            }
        }

        return [
            'articulo' => $nombre,
            'titulo' => (string) ($meta['titulo'] ?? $nombre),
            'bajada' => AyudaRender::enLinea($meta['bajada'] ?? ''),
            'categoria' => (string) ($meta['categoria'] ?? ''),
            'usuarios' => (string) ($meta['usuarios'] ?? ''),
            'responsable' => AyudaArticulo::RESPONSABLE,
            'indice' => $indice,
            'secciones' => array_map(function ($s) {
                return ['ancla' => $s['ancla'], 'titulo' => $s['titulo'], 'html' => $s['html']];
            }, $r['secciones']),
            'errores' => self::errores($nombre, $art, $articulos, $tabs, $hoy),
            'direccion' => AyudaRender::direccion($nombre)
        ] + self::estadoDe($art);
    }
}
