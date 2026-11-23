<?php

require_once __DIR__ . '/AyudaArticulo.php';

/**
 * AyudaRender
 * Convierte un articulo de la ayuda (ya leido por AyudaArticulo) en HTML.
 *
 * POR QUE UN SUBCONJUNTO DE MARKDOWN PROPIO Y NO UNA LIBRERIA
 * ------------------------------------------------------------
 * El modulo no agrega librerias, y lo que la ayuda necesita es poco y muy
 * concreto: titulos, parrafos, listas, tablas, enlaces, imagenes y siete
 * bloques que dibujan lo que muestra el boceto (formula, ejemplo, diagrama,
 * pasos, marcas, aviso, pregunta). Un Markdown completo traeria lo que aca
 * sobra -y, sobre todo, HTML crudo, que es justo lo que no tiene que pasar-.
 *
 * TODO EL TEXTO SE ESCAPA
 * -----------------------
 * Ningun caracter de un articulo llega al HTML sin pasar por esc(). Las
 * etiquetas las pone SOLO este archivo; un "<script>" escrito en un articulo
 * sale como texto. Los enlaces aceptan solo "ayuda:" y http(s); las imagenes,
 * solo archivos de Ayuda/img/. Lo fija tests/test_ayuda_render.php.
 *
 * UN ERROR NO ROMPE LA PAGINA
 * ---------------------------
 * Un bloque sin cerrar, un tipo que no existe o un enlace mal escrito se
 * muestran como texto y se anotan en 'errores' con su renglon. La pantalla
 * dibuja el articulo igual, con un aviso arriba, y la prueba de formato de los
 * articulos reales (test_ayuda_articulos.php) no deja pasar ninguno.
 *
 * PURO: recibe el articulo ya leido. Que una imagen exista en el disco lo
 * mira quien la lee (la prueba); aca solo se devuelve la lista.
 */
class AyudaRender {

    /** Los bloques especiales que existen */
    const BLOQUES = ['formula', 'ejemplo', 'diagrama', 'pasos', 'marcas', 'aviso', 'pregunta'];

    /**
     * Los niveles de un aviso: los tres del panel de avisos, con su clase de
     * color (aviso-danger|warning|info, la misma de Css/avisos-panel.css), su
     * etiqueta y su icono. Asi un aviso se ve igual en la ayuda que en la
     * pantalla donde aparece.
     */
    const NIVELES = [
        'critico' => ['clase' => 'danger', 'etiqueta' => 'Crítico', 'icono' => 'fa-circle-exclamation'],
        'atencion' => ['clase' => 'warning', 'etiqueta' => 'Atención', 'icono' => 'fa-triangle-exclamation'],
        'info' => ['clase' => 'info', 'etiqueta' => 'Info', 'icono' => 'fa-circle-info']
    ];

    /**
     * El unico tipo de ejemplo. Los ejemplos llevan numeros inventados y
     * redondeados, nunca importes reales de proveedores, contratos ni saldos:
     * todos los usuarios ven todos los articulos, tengan o no permiso sobre
     * la pestana. Ver "La ayuda funcional" en README-cashflow.md.
     */
    const EJEMPLO_TIPO = 'ilustrativo';

    /** Donde estan las imagenes, visto desde index.php */
    const RUTA_IMAGENES = 'Ayuda/';

    private $errores = [];
    private $anclas = [];
    private $resaltar = '';
    private $destinos = null;
    private $enlaces = [];
    private $imagenes = [];

    /**
     * Un articulo entero, seccion por seccion.
     *
     * Se devuelve por seccion y no como un solo HTML porque el panel lateral
     * muestra solo algunas y el buscador del panel esconde las que no
     * coinciden. Las anclas se asignan sobre el articulo entero (AyudaArticulo::
     * anclas()), asi que una seccion suelta tiene las mismas que en el
     * articulo completo.
     *
     * @param array $art De AyudaArticulo::parsear()
     * @param array $op 'resaltar' => texto a marcar con <mark>;
     *                  'destinos' => articulo => [anclas], para validar los
     *                  enlaces "ayuda:" (sin esto no se validan)
     * @return array [
     *   'secciones' => [['titulo', 'ancla', 'html', 'coincide']],
     *   'errores' => [string],
     *   'enlaces' => [['articulo', 'ancla', 'linea']],
     *   'imagenes' => [['ruta', 'alt', 'linea']]
     * ]
     */
    public static function articulo($art, $op = []) {
        $r = new self();
        $r->resaltar = trim((string) ($op['resaltar'] ?? ''));
        $r->destinos = $op['destinos'] ?? null;

        foreach (AyudaArticulo::anclas($art) as $a) {
            $r->anclas[$a['linea']] = $a['ancla'];
        }

        $secciones = [];

        foreach ($art['secciones'] as $s) {
            $ancla = $r->anclas[$s['linea']];
            $html = '<section class="ayuda-seccion" data-ancla="' . self::esc($ancla) . '">'
                . '<h2 id="' . self::esc($ancla) . '">' . $r->texto($s['titulo']) . '</h2>'
                . $r->bloques($s['lineas'], true)
                . '</section>';

            $secciones[] = [
                'titulo' => $s['titulo'],
                'ancla' => $ancla,
                'html' => $html,
                'coincide' => $r->resaltar === '' ? true
                    : self::contiene($s['titulo'] . ' ' . self::textoPlano($s['lineas']), $r->resaltar)
            ];
        }

        return [
            'secciones' => $secciones,
            'errores' => $r->errores,
            'enlaces' => $r->enlaces,
            'imagenes' => $r->imagenes
        ];
    }

    /**
     * Un pedazo de Markdown suelto (la bajada, un fragmento). Solo el texto en
     * linea: negrita, italica, codigo y enlaces.
     *
     * @param string $texto
     * @param string $resaltar
     * @return string
     */
    public static function enLinea($texto, $resaltar = '') {
        $r = new self();
        $r->resaltar = trim((string) $resaltar);

        return $r->inline((string) $texto, 0);
    }

    /**
     * El texto de unos renglones sin la sintaxis: lo que lee quien busca.
     *
     * Es lo que indexa la busqueda y lo que decide si una seccion "coincide"
     * en el buscador del panel. Se saca la sintaxis y no se renderiza y se
     * quitan las etiquetas, porque un fragmento tiene que poder cortarse en
     * cualquier lugar sin dejar una etiqueta a medias.
     *
     * @param array $lineas [[numero, texto], ...]
     * @return string
     */
    public static function textoPlano($lineas) {
        $partes = [];
        $comentario = false;

        foreach ($lineas as $par) {
            $l = trim($par[1]);

            if ($comentario) {
                $comentario = strpos($l, '-->') === false;
                continue;
            }

            if (strpos($l, '<!--') === 0) {
                $comentario = strpos($l, '-->') === false;
                continue;
            }

            // Separador de tabla: no es texto.
            if (preg_match('/^\|?\s*:?-{3,}/', $l)) {
                continue;
            }

            $l = preg_replace('/^:::(pregunta|[a-z]+)?\s*/', '', $l);
            $l = preg_replace('/^(aviso\s+)?(critico|atencion|info)$/', '', $l);
            $l = preg_replace('/^#{2,3}\s+/', '', $l);
            $l = preg_replace('/^([-*]|\d+\.)\s+/', '', $l);
            $l = preg_replace('/^(fuente|pestana|tablero|alt|texto|significa|corrige):\s*/', '', $l);
            $l = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $l);
            $l = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $l);
            $l = str_replace(['**', '`', '|'], ['', '', ' '], $l);
            $l = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '$1', $l);

            if ($l !== '') {
                $partes[] = $l;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $partes)));
    }

    /**
     * Si un texto contiene un termino, sin acentos ni mayusculas.
     *
     * @param string $texto
     * @param string $termino
     * @return bool
     */
    public static function contiene($texto, $termino) {
        $t = AyudaArticulo::normalizar(trim((string) $termino));

        return $t !== '' && mb_strpos(AyudaArticulo::normalizar((string) $texto), $t, 0, 'UTF-8') !== false;
    }

    /**
     * Un texto escapado, con el termino marcado con <mark> donde aparece.
     *
     * Se busca en la version normalizada y se corta el ORIGINAL en las mismas
     * posiciones: claveOrden() no cambia el largo, asi que coinciden. Si alguna
     * vez no coincidieran (una letra que al pasar a mayuscula se hace dos), no
     * se resalta nada antes que resaltar el pedazo equivocado.
     *
     * @param string $texto
     * @param string $termino
     * @return string HTML
     */
    public static function resaltado($texto, $termino) {
        $texto = (string) $texto;
        $t = AyudaArticulo::normalizar(trim((string) $termino));
        $norm = AyudaArticulo::normalizar($texto);

        if ($t === '' || mb_strlen($norm, 'UTF-8') !== mb_strlen($texto, 'UTF-8')) {
            return self::esc($texto);
        }

        $largo = mb_strlen($t, 'UTF-8');
        $out = '';
        $desde = 0;

        while (($p = mb_strpos($norm, $t, $desde, 'UTF-8')) !== false) {
            $out .= self::esc(mb_substr($texto, $desde, $p - $desde, 'UTF-8'))
                . '<mark>' . self::esc(mb_substr($texto, $p, $largo, 'UTF-8')) . '</mark>';
            $desde = $p + $largo;
        }

        return $out . self::esc(mb_substr($texto, $desde, null, 'UTF-8'));
    }

    /**
     * El escape de todo el modulo de ayuda. Comillas incluidas: el texto
     * tambien va en atributos (alt, aria-label).
     *
     * @param string $s
     * @return string
     */
    public static function esc($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * La direccion de un articulo, o de una seccion: "?ayuda=llaves_renov"
     * o "?ayuda=llaves_renov#como-se-calcula".
     *
     * POR QUE UN PARAMETRO Y NO SOLO EL HASH: el servidor ve "?ayuda=" y abre el
     * Centro de ayuda como pestaña inicial (index.php). Con un hash solo, la
     * pagina cargaria primero el tablero entero -lo que se abre por defecto- y
     * recien despues saltaria a la ayuda. La seccion si va en el hash: es lo
     * que el navegador ya sabe llevar hasta un id.
     *
     * Es la que se copia de la barra de direcciones, la de los resultados de
     * la busqueda y la de los enlaces "ayuda:" de los articulos: una sola.
     *
     * @param string $articulo
     * @param string $ancla
     * @return string
     */
    public static function direccion($articulo, $ancla = '') {
        return '?ayuda=' . rawurlencode((string) $articulo) . ($ancla !== '' ? '#' . rawurlencode((string) $ancla) : '');
    }

    /* ====================================================================
       LOS BLOQUES
       ==================================================================== */

    /**
     * Una lista de renglones a HTML: parrafos, titulos ###, listas, tablas y
     * los bloques especiales.
     *
     * @param array $lineas [[numero, texto], ...]
     * @param bool $especiales false adentro de un bloque: un bloque no va
     *                         dentro de otro, y tampoco un ###
     * @return string
     */
    private function bloques($lineas, $especiales) {
        $html = '';
        $parrafo = [];
        $avisos = [];
        $n = count($lineas);

        $cerrarParrafo = function () use (&$parrafo, &$html) {
            if ($parrafo) {
                $html .= '<p>' . implode(' ', $parrafo) . '</p>';
                $parrafo = [];
            }
        };

        // Los avisos seguidos van en UNA tabla: la seccion "Avisos y que
        // hacer" es una tabla de varios, no una pila de tablas de una fila.
        $cerrarAvisos = function () use (&$avisos, &$html) {
            if ($avisos) {
                $html .= '<div class="table-responsive"><table class="table ayuda-tabla ayuda-avisos" data-orden="no">'
                    . '<thead><tr><th>Aviso</th><th>Qué significa</th><th>Dónde se corrige</th></tr></thead>'
                    . '<tbody>' . implode('', $avisos) . '</tbody></table></div>';
                $avisos = [];
            }
        };

        for ($i = 0; $i < $n; $i++) {
            list($num, $l) = $lineas[$i];
            $t = trim($l);

            if ($t === '') {
                $cerrarParrafo();
                continue;
            }

            // Comentarios: notas para quien escribe ("aca va la captura").
            if (strpos($t, '<!--') === 0) {
                $fin = $i;

                while ($fin < $n && strpos($lineas[$fin][1], '-->') === false) {
                    $fin++;
                }

                if ($fin < $n) {
                    $i = $fin;
                    continue;
                }

                $this->error($num, 'el comentario no cierra (falta "-->").');
                // Sigue como texto: se ve, y escapado.
            }

            if ($t !== '' && strpos($t, ':::') === 0) {
                $cerrarParrafo();

                if (!preg_match('/^:::([a-z]+)\s*(.*)$/', $t, $m)) {
                    $this->error($num, 'un ":::" que no abre ningún bloque.');
                    $parrafo[] = $this->inline($t, $num);
                    continue;
                }

                if (!$especiales) {
                    $this->error($num, 'un bloque no puede ir adentro de otro.');
                    $parrafo[] = $this->inline($t, $num);
                    continue;
                }

                $fin = null;

                for ($j = $i + 1; $j < $n; $j++) {
                    if (preg_match('/^:::\s*$/', trim($lineas[$j][1]))) {
                        $fin = $j;
                        break;
                    }
                }

                $tipo = $m[1];
                $args = trim($m[2]);

                if ($fin === null) {
                    $this->error($num, 'el bloque ":::' . $tipo . '" no cierra (falta ":::").');
                    $cerrarAvisos();
                    $html .= $this->comoTexto(array_slice($lineas, $i));
                    break;
                }

                $adentro = array_slice($lineas, $i + 1, $fin - $i - 1);

                if (!in_array($tipo, self::BLOQUES, true)) {
                    $this->error($num, 'el bloque ":::' . $tipo . '" no existe.');
                    $cerrarAvisos();
                    $html .= $this->comoTexto(array_slice($lineas, $i, $fin - $i + 1));
                } elseif ($tipo === 'aviso') {
                    $avisos[] = $this->aviso($args, $adentro, $num);
                } else {
                    $cerrarAvisos();
                    $html .= $this->especial($tipo, $args, $adentro, $num);
                }

                $i = $fin;
                continue;
            }

            $cerrarAvisos();

            if (preg_match('/^### (.+)$/', $l, $m)) {
                $cerrarParrafo();

                if (!$especiales || !isset($this->anclas[$num])) {
                    $this->error($num, 'un título ### va fuera de los bloques.');
                    $html .= '<p>' . $this->inline($t, $num) . '</p>';
                    continue;
                }

                $html .= '<h3 id="' . self::esc($this->anclas[$num]) . '">' . $this->inline(trim($m[1]), $num) . '</h3>';
                continue;
            }

            if (strpos($t, '|') === 0) {
                $cerrarParrafo();
                $fin = $i;

                while ($fin + 1 < $n && strpos(trim($lineas[$fin + 1][1]), '|') === 0) {
                    $fin++;
                }

                $html .= $this->tabla(array_slice($lineas, $i, $fin - $i + 1));
                $i = $fin;
                continue;
            }

            if (preg_match('/^([-*]|\d+\.)\s+/', $t, $m)) {
                $cerrarParrafo();
                $numerada = $m[1] !== '-' && $m[1] !== '*';
                $items = [];

                for (; $i < $n; $i++) {
                    $li = $lineas[$i][1];

                    if (preg_match('/^\s*([-*]|\d+\.)\s+(.*)$/', $li, $mm)
                        && (($mm[1] !== '-' && $mm[1] !== '*') === $numerada)) {
                        $items[] = $this->inline($mm[2], $lineas[$i][0]);
                    } elseif ($items && trim($li) !== '' && preg_match('/^\s{2,}\S/', $li)) {
                        // Un renglon con sangria sigue el item de arriba.
                        $items[count($items) - 1] .= ' ' . $this->inline(trim($li), $lineas[$i][0]);
                    } else {
                        break;
                    }
                }

                $i--;
                $tag = $numerada ? 'ol' : 'ul';
                $html .= '<' . $tag . '><li>' . implode('</li><li>', $items) . '</li></' . $tag . '>';
                continue;
            }

            $parrafo[] = $this->inline($t, $num);
        }

        $cerrarParrafo();
        $cerrarAvisos();

        return $html;
    }

    /**
     * Unos renglones mostrados tal cual, escapados: lo que hace un bloque roto.
     */
    private function comoTexto($lineas) {
        $html = '';

        foreach ($lineas as $par) {
            if (trim($par[1]) !== '') {
                $html .= self::esc($par[1]) . '<br>';
            }
        }

        return '<p class="ayuda-roto">' . $html . '</p>';
    }

    /** Una tabla con | . La segunda fila es el separador; ---: alinea a la derecha. */
    private function tabla($filas) {
        $num = $filas[0][0];

        $celdas = function ($l) {
            $l = trim($l);
            $l = preg_replace('/^\|/', '', $l);
            $l = preg_replace('/\|$/', '', $l);

            return array_map('trim', explode('|', $l));
        };

        if (count($filas) < 2 || !preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?$/', trim($filas[1][1]))) {
            $this->error($num, 'la tabla no tiene la fila separadora (| --- |) debajo del encabezado.');

            return $this->comoTexto($filas);
        }

        $derecha = array_map(function ($c) {
            return substr($c, -1) === ':';
        }, $celdas($filas[1][1]));

        $cabecera = $celdas($filas[0][1]);
        $html = '<div class="table-responsive"><table class="table table-sm ayuda-tabla" data-orden="no"><thead><tr>';

        foreach ($cabecera as $k => $c) {
            $html .= '<th' . (!empty($derecha[$k]) ? ' class="text-end"' : '') . '>' . $this->inline($c, $num) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach (array_slice($filas, 2) as $f) {
            $html .= '<tr>';

            foreach ($celdas($f[1]) as $k => $c) {
                $html .= '<td' . (!empty($derecha[$k]) ? ' class="text-end"' : '') . '>' . $this->inline($c, $f[0]) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** Los bloques especiales, salvo el aviso (que se junta en tabla) */
    private function especial($tipo, $args, $lineas, $num) {
        switch ($tipo) {
            case 'formula':
                $renglones = [];

                foreach ($lineas as $par) {
                    if (trim($par[1]) !== '') {
                        $renglones[] = $this->inline(trim($par[1]), $par[0]);
                    }
                }

                if (!$renglones) {
                    $this->error($num, 'la fórmula está vacía.');
                }

                return '<div class="ayuda-formula">' . implode('<br>', $renglones) . '</div>';

            case 'ejemplo':
                if ($args !== self::EJEMPLO_TIPO) {
                    $this->error($num, 'el ejemplo va ":::ejemplo ' . self::EJEMPLO_TIPO
                        . '": los números de los ejemplos son inventados.');
                }

                return '<div class="ayuda-ejemplo"><div class="ayuda-ejemplo-titulo">Ejemplo '
                    . '<span class="ayuda-ejemplo-tipo">con números ilustrativos</span></div>'
                    . $this->bloques($lineas, false) . '</div>';

            case 'diagrama':
                return $this->diagrama($lineas, $num);

            case 'pasos':
                return $this->pasos($lineas, $num);

            case 'marcas':
                return $this->marcas($lineas, $num);

            case 'pregunta':
                if ($args === '' || !isset($this->anclas[$num])) {
                    $this->error($num, 'la pregunta va en el mismo renglón: ":::pregunta ¿…?".');
                }

                $ancla = $this->anclas[$num] ?? AyudaArticulo::slug($args);

                return '<details class="ayuda-pregunta" id="' . self::esc($ancla) . '">'
                    . '<summary>' . $this->inline($args, $num) . '</summary>'
                    . '<div class="ayuda-pregunta-cuerpo">' . $this->bloques($lineas, false) . '</div></details>';
        }

        return '';
    }

    /**
     * El diagrama de flujo: las fuentes -> la pestana -> el tablero, con cajas
     * de HTML y CSS. Sin SVG ni imagen: se lee con el zoom del navegador, se
     * traduce, se busca con Ctrl+F y no hay un archivo aparte que actualizar.
     *
     *   fuente: La app de Alquileres | los contratos y su vigencia
     *   pestana: Llaves y Renov. Contratos | calcula cuanto y cuando
     *   tablero: Fila Llaves y Renovaciones | real y proyectado
     *   alt: El texto para quien no ve el dibujo
     */
    private function diagrama($lineas, $num) {
        $cajas = ['fuente' => [], 'pestana' => [], 'tablero' => []];
        $alt = '';

        foreach ($lineas as $par) {
            $t = trim($par[1]);

            if ($t === '') {
                continue;
            }

            if (!preg_match('/^(fuente|pestana|tablero|alt):\s*(.+)$/', $t, $m)) {
                $this->error($par[0], 'en un diagrama cada renglón es fuente:, pestana:, tablero: o alt:.');
                continue;
            }

            if ($m[1] === 'alt') {
                $alt = trim($m[2]);
                continue;
            }

            $partes = array_map('trim', explode('|', $m[2], 2));
            $cajas[$m[1]][] = '<div class="ayuda-caja ayuda-caja-' . $m[1] . '"><strong>'
                . $this->inline($partes[0], $par[0]) . '</strong>'
                . (isset($partes[1]) && $partes[1] !== '' ? '<span>' . $this->inline($partes[1], $par[0]) . '</span>' : '')
                . '</div>';
        }

        if ($alt === '') {
            $this->error($num, 'el diagrama no tiene texto alternativo (alt:).');
        }

        if (!$cajas['fuente'] || count($cajas['pestana']) !== 1) {
            $this->error($num, 'el diagrama lleva al menos una fuente y una sola pestaña.');
        }

        $flecha = '<div class="ayuda-diagrama-flecha" aria-hidden="true"><i class="fas fa-arrow-right"></i></div>';
        $html = '<figure class="ayuda-diagrama" role="img" aria-label="' . self::esc($alt) . '">'
            . '<div class="ayuda-diagrama-col">' . implode('', $cajas['fuente']) . '</div>'
            . $flecha
            . '<div class="ayuda-diagrama-col">' . implode('', $cajas['pestana']) . '</div>';

        if ($cajas['tablero']) {
            $html .= $flecha . '<div class="ayuda-diagrama-col">' . implode('', $cajas['tablero']) . '</div>';
        }

        return $html . '<figcaption class="visually-hidden">' . self::esc($alt) . '</figcaption></figure>';
    }

    /** Los pasos numerados de una tarea, con el resultado al final */
    private function pasos($lineas, $num) {
        $pasos = [];
        $resultado = null;

        foreach ($lineas as $par) {
            $t = trim($par[1]);

            if ($t === '') {
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $t, $m) && $resultado === null) {
                $pasos[] = $this->inline($m[1], $par[0]);
            } elseif (preg_match('/^Resultado:\s*(.+)$/', $t, $m) && $resultado === null) {
                $resultado = $this->inline($m[1], $par[0]);
            } else {
                $this->error($par[0], 'en un bloque de pasos van "1. …" y, al final, "Resultado: …".');
            }
        }

        if (!$pasos) {
            $this->error($num, 'el bloque de pasos no tiene ningún paso.');
        }

        if ($resultado === null) {
            $this->error($num, 'el bloque de pasos no dice el resultado ("Resultado: …").');
        }

        $html = '<ol class="ayuda-pasos">';

        foreach ($pasos as $k => $p) {
            $html .= '<li><span class="ayuda-paso-num" aria-hidden="true">' . ($k + 1) . '</span><div>' . $p . '</div></li>';
        }

        return $html . '</ol>' . ($resultado !== null
            ? '<p class="ayuda-resultado"><strong>Resultado:</strong> ' . $resultado . '</p>' : '');
    }

    /** Las marcas naranjas de una captura: A, B, C… en orden */
    private function marcas($lineas, $num) {
        $html = '';
        $esperada = 'A';

        foreach ($lineas as $par) {
            $t = trim($par[1]);

            if ($t === '') {
                continue;
            }

            if (!preg_match('/^([A-Z]):\s*(.+)$/', $t, $m)) {
                $this->error($par[0], 'en un bloque de marcas cada renglón es "A: …".');
                continue;
            }

            if ($m[1] !== $esperada) {
                $this->error($par[0], 'las marcas van en orden: se esperaba la ' . $esperada . '.');
            }

            $esperada = chr(ord($m[1]) + 1);
            $html .= '<li><span class="ayuda-marca" aria-hidden="true">' . $m[1] . '</span><div>'
                . '<span class="visually-hidden">Marca ' . $m[1] . ': </span>'
                . $this->inline($m[2], $par[0]) . '</div></li>';
        }

        if ($html === '') {
            $this->error($num, 'el bloque de marcas está vacío.');
        }

        return '<ol class="ayuda-marcas">' . $html . '</ol>';
    }

    /**
     * Una fila de la tabla de avisos.
     *
     *   :::aviso atencion
     *   texto: El aviso tal como lo muestra la pantalla
     *   significa: Que quiere decir
     *   corrige: Donde se arregla
     *   :::
     */
    private function aviso($nivel, $lineas, $num) {
        if (!isset(self::NIVELES[$nivel])) {
            $this->error($num, 'el nivel del aviso es critico, atencion o info, no "' . $nivel . '".');
            $nivel = 'atencion';
        }

        $campos = ['texto' => [], 'significa' => [], 'corrige' => []];
        $ultimo = null;

        foreach ($lineas as $par) {
            $t = trim($par[1]);

            if ($t === '') {
                continue;
            }

            if (preg_match('/^(texto|significa|corrige):\s*(.*)$/', $t, $m)) {
                $ultimo = $m[1];
                $campos[$ultimo][] = $this->inline($m[2], $par[0]);
            } elseif ($ultimo !== null) {
                // Un renglon suelto sigue el campo de arriba.
                $campos[$ultimo][] = $this->inline($t, $par[0]);
            } else {
                $this->error($par[0], 'un aviso empieza con "texto:".');
            }
        }

        foreach ($campos as $c => $v) {
            if (!$v || trim(implode('', $v)) === '') {
                $this->error($num, 'al aviso le falta "' . $c . ':".');
            }
        }

        $n = self::NIVELES[$nivel];

        return '<tr><td><span class="ayuda-nivel aviso-' . $n['clase'] . '"><i class="fas ' . $n['icono']
            . '" aria-hidden="true"></i> ' . $n['etiqueta'] . '</span>'
            . '<div class="ayuda-aviso-texto">' . implode(' ', $campos['texto']) . '</div></td>'
            . '<td>' . implode(' ', $campos['significa']) . '</td>'
            . '<td>' . implode(' ', $campos['corrige']) . '</td></tr>';
    }

    /* ====================================================================
       EL TEXTO EN LINEA
       ==================================================================== */

    /**
     * Codigo, imagenes, enlaces, negrita e italica.
     *
     * Se corta primero por codigo, imagen y enlace -lo que tiene sintaxis con
     * corchetes y parentesis- y lo que queda entre medio es texto, que se
     * escapa y despues recibe la negrita y la italica. El orden importa: los
     * asteriscos no se escapan, asi que marcarlos sobre el texto ya escapado
     * no puede abrir una etiqueta que no sea las dos que se ponen aca.
     */
    private function inline($s, $num) {
        $patron = '/(`[^`]+`|!\[[^\]]*\]\([^)]*\)|\[[^\]]+\]\([^)]*\))/u';
        $partes = preg_split($patron, $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';

        foreach ($partes as $k => $p) {
            if ($p === '') {
                continue;
            }

            if ($k % 2 === 0) {
                $out .= $this->texto($p, $num);
            } elseif ($p[0] === '`') {
                $out .= '<code>' . $this->marcar(substr($p, 1, -1)) . '</code>';
            } elseif ($p[0] === '!') {
                $out .= $this->imagen($p, $num);
            } else {
                $out .= $this->enlace($p, $num);
            }
        }

        return $out;
    }

    /** Texto suelto: escapado, resaltado, con negrita e italica */
    private function texto($s, $num = 0) {
        $h = $this->marcar($s);
        $h = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $h);
        $h = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $h);

        if (strpos($h, '**') !== false) {
            $this->error($num, 'una negrita sin cerrar ("**").');
        }

        return $h;
    }

    /** Escapa y, si hay un termino buscado, lo marca */
    private function marcar($s) {
        return $this->resaltar === '' ? self::esc($s) : self::resaltado($s, $this->resaltar);
    }

    /**
     * ![texto alternativo](img/<articulo>/<archivo>)
     *
     * Solo archivos de Ayuda/img/: una ruta con ".." o una URL de afuera se
     * muestra como texto. El texto alternativo es obligatorio porque la imagen
     * casi siempre es una captura con marcas, y sin el quien no la ve se pierde
     * lo que la marca senala.
     */
    private function imagen($p, $num) {
        preg_match('/^!\[([^\]]*)\]\(([^)]*)\)$/', $p, $m);
        $alt = trim($m[1]);
        $ruta = trim($m[2]);

        if (!preg_match('#^img/[a-z0-9_]+/[A-Za-z0-9_.-]+\.(png|jpe?g|gif|webp)$#', $ruta)
            || strpos($ruta, '..') !== false) {
            $this->error($num, 'la imagen "' . $ruta . '" no está en img/<artículo>/ o no es png, jpg, gif o webp.');

            return self::esc($p);
        }

        if ($alt === '') {
            $this->error($num, 'la imagen "' . $ruta . '" no tiene texto alternativo.');

            return self::esc($p);
        }

        $this->imagenes[] = ['ruta' => $ruta, 'alt' => $alt, 'linea' => $num];

        return '<img class="ayuda-img" src="' . self::esc(self::RUTA_IMAGENES . $ruta) . '" alt="'
            . self::esc($alt) . '" loading="lazy">';
    }

    /**
     * [texto](ayuda:articulo#ancla) o [texto](https://…)
     *
     * Un enlace de la ayuda lleva a la direccion del Centro de ayuda
     * (?ayuda=articulo#ancla), la misma que se copia y se comparte: no hay un
     * segundo formato de enlace que mantener. Cualquier otro esquema
     * -javascript:, data:, una ruta relativa- se muestra como texto.
     */
    private function enlace($p, $num) {
        preg_match('/^\[([^\]]+)\]\(([^)]*)\)$/', $p, $m);
        $txt = $this->texto($m[1], $num);
        $url = trim($m[2]);

        if (preg_match('/^ayuda:([a-z0-9_]+)(?:#([a-z0-9-]+))?$/', $url, $u)) {
            $art = $u[1];
            $ancla = $u[2] ?? '';
            $this->enlaces[] = ['articulo' => $art, 'ancla' => $ancla, 'linea' => $num];

            if ($this->destinos !== null) {
                if (!isset($this->destinos[$art])) {
                    $this->error($num, 'el enlace apunta al artículo "' . $art . '", que no existe.');
                } elseif ($ancla !== '' && !in_array($ancla, $this->destinos[$art], true)) {
                    $this->error($num, 'el enlace apunta a "' . $art . '#' . $ancla . '", una sección que no existe.');
                }
            }

            return '<a class="ayuda-enlace" href="' . self::esc(self::direccion($art, $ancla))
                . '" data-articulo="' . self::esc($art) . '" data-ancla="' . self::esc($ancla) . '">' . $txt . '</a>';
        }

        if (preg_match('#^https?://[^\s"<>]+$#', $url)) {
            return '<a href="' . self::esc($url) . '" target="_blank" rel="noopener noreferrer">' . $txt
                . ' <i class="fas fa-arrow-up-right-from-square small" aria-hidden="true"></i></a>';
        }

        $this->error($num, 'el enlace "' . $url . '" no es "ayuda:…" ni http(s).');

        return self::esc($p);
    }

    private function error($num, $msg) {
        $this->errores[] = ($num ? 'Renglón ' . $num . ': ' : '') . $msg;
    }
}
