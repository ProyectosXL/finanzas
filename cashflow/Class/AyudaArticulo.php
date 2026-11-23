<?php

require_once __DIR__ . '/ProveedoresOpciones.php';

/**
 * AyudaArticulo
 * Lee un articulo de la ayuda funcional (Ayuda/*.md): su encabezado de datos,
 * sus secciones y sus anclas, y dice que tiene mal.
 *
 * POR QUE LOS ARTICULOS SON ARCHIVOS DEL REPO Y NO FILAS DE UNA TABLA
 * -------------------------------------------------------------------
 * La ayuda la escribe y la mantiene quien cambia el codigo, en la misma rama.
 * Asi un cambio que mueve un boton y el texto que lo explica viajan juntos, se
 * revisan juntos y una prueba puede negarse a dejar pasar uno sin el otro. Una
 * tabla editable desde la app separaria las dos cosas, y una ayuda que dice lo
 * que la pantalla hacia el mes pasado es peor que no tener ayuda.
 *
 * TODO ES PURO
 * ------------
 * Recibe el texto ya leido; leer el disco es de Class/Ayuda.php. Asi el
 * formato, las validaciones y las anclas se prueban con un string, sin
 * archivos ni conexion (tests/test_ayuda_articulos.php).
 *
 * EL FORMATO
 * ----------
 *   ---
 *   titulo: Llaves y Renov. Contratos
 *   pestana: llaves_renov
 *   categoria: RRHH y Operativos
 *   estado: en_revision
 *   actualizado: 2026-11-23
 *   usuarios: Tesoreria, Administracion
 *   bajada: Una linea bajo el titulo.
 *   ---
 *   ## Para que sirve
 *   ...
 *
 * El encabezado es "clave: valor", uno por renglon, sin YAML: son siete campos
 * de texto y una libreria entera para leerlos seria una dependencia nueva para
 * nada. El cuerpo es Markdown reducido; lo que se acepta lo dice AyudaRender.
 */
class AyudaArticulo {

    const COMPLETA = 'completa';
    const EN_REVISION = 'en_revision';
    const PENDIENTE = 'pendiente';

    /** Los estados de un articulo, con la etiqueta que se ve en pantalla */
    const ESTADOS = [
        self::COMPLETA => 'Completa',
        self::EN_REVISION => 'En revisión',
        self::PENDIENTE => 'Pendiente'
    ];

    /** Los campos del encabezado. 'pestana' va vacio en los generales. */
    const CAMPOS = ['titulo', 'pestana', 'categoria', 'estado', 'actualizado', 'usuarios', 'bajada'];

    /** Los que no pueden faltar ni ir vacios */
    const OBLIGATORIOS = ['titulo', 'categoria', 'estado', 'actualizado', 'usuarios'];

    /** La categoria de los articulos que no son de una pestana */
    const GENERAL = 'General';

    /**
     * Quien responde por la ayuda. Va en el codigo y no en cada archivo: es
     * uno solo para todos, y siete copias se desincronizan.
     */
    const RESPONSABLE = 'Proyectos e Innovación';

    /**
     * LA PLANTILLA: las nueve secciones de todo articulo de pestana, en este
     * orden y con estos titulos exactos.
     *
     * Fijas para que quien abre la ayuda de una pestana nueva sepa donde buscar
     * sin leerla entera, y para que el panel lateral pueda mostrar "Para que
     * sirve", "De donde salen los datos" y "La pantalla" de cualquiera sin
     * saber nada de ella.
     */
    const SECCIONES_PESTANA = [
        'Para qué sirve',
        'De dónde salen los datos',
        'Cómo se calcula',
        'La pantalla',
        'Tareas frecuentes',
        'Parámetros',
        'Avisos y qué hacer',
        'Preguntas frecuentes',
        'Glosario'
    ];

    /** Las que muestra el panel lateral, completas (indices de la plantilla) */
    const SECCIONES_PANEL = [0, 1, 3];

    /** La seccion cuyas preguntas se listan en el panel */
    const SECCION_PREGUNTAS = 'Preguntas frecuentes';

    /**
     * El texto de una seccion que no aplica. La seccion va igual -la plantilla
     * no se saltea- y abajo una linea con el porque: "no aplica" solo, sin
     * razon, no le dice nada a quien vino a buscar justamente eso.
     */
    const NO_APLICA = 'No aplica en esta pestaña.';

    /**
     * Separa el encabezado de datos y las secciones.
     *
     * NUNCA LANZA. Un articulo mal escrito se tiene que poder mostrar igual,
     * con un aviso: lo que no se pudo leer va a 'errores' y la pantalla sigue.
     *
     * @param string $texto El archivo entero
     * @return array [
     *   'meta' => campo => valor (los que vinieron),
     *   'secciones' => [['titulo', 'linea', 'lineas' => [[numero, texto], ...]]],
     *   'errores' => [string]
     * ]
     */
    public static function parsear($texto) {
        $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);

        // Un BOM al principio haria que el primer renglon no fuera '---'.
        if (strncmp($texto, "\xEF\xBB\xBF", 3) === 0) {
            $texto = substr($texto, 3);
        }

        $lineas = explode("\n", $texto);
        $meta = [];
        $errores = [];
        $i = 0;

        if (trim($lineas[0] ?? '') !== '---') {
            $errores[] = 'Falta el encabezado de datos: el archivo tiene que empezar con "---".';
        } else {
            $cerrado = false;

            for ($i = 1; $i < count($lineas); $i++) {
                $l = trim($lineas[$i]);

                if ($l === '---') {
                    $cerrado = true;
                    $i++;
                    break;
                }

                if ($l === '') {
                    continue;
                }

                $p = strpos($l, ':');

                if ($p === false) {
                    $errores[] = 'Renglón ' . ($i + 1) . ': en el encabezado cada renglón es "campo: valor".';
                    continue;
                }

                $clave = trim(substr($l, 0, $p));
                $valor = trim(substr($l, $p + 1));

                if (!in_array($clave, self::CAMPOS, true)) {
                    $errores[] = 'Renglón ' . ($i + 1) . ': el campo "' . $clave . '" no existe.';
                    continue;
                }

                if (isset($meta[$clave])) {
                    $errores[] = 'Renglón ' . ($i + 1) . ': el campo "' . $clave . '" está repetido.';
                }

                $meta[$clave] = $valor;
            }

            if (!$cerrado) {
                // Sin cierre no se sabe donde termina: se toma todo como
                // encabezado y el articulo queda sin cuerpo, con el aviso.
                $errores[] = 'El encabezado de datos no cierra: falta el segundo "---".';
            }
        }

        $secciones = [];
        $actual = null;
        $previo = false;

        for (; $i < count($lineas); $i++) {
            $l = $lineas[$i];

            if (preg_match('/^## (.+)$/', $l, $m)) {
                if ($actual !== null) {
                    $secciones[] = $actual;
                }

                $actual = ['titulo' => trim($m[1]), 'linea' => $i + 1, 'lineas' => []];
                continue;
            }

            if ($actual === null) {
                if (trim($l) !== '' && !$previo) {
                    $previo = true;
                    $errores[] = 'Renglón ' . ($i + 1) . ': hay texto antes de la primera sección (##). '
                        . 'La bajada va en el encabezado.';
                }

                continue;
            }

            $actual['lineas'][] = [$i + 1, $l];
        }

        if ($actual !== null) {
            $secciones[] = $actual;
        }

        return ['meta' => $meta, 'secciones' => $secciones, 'errores' => $errores];
    }

    /**
     * Si un articulo es de una pestana (lleva la plantilla) o general.
     *
     * @param array $art De parsear()
     * @return bool
     */
    public static function esDePestana($art) {
        return trim((string) ($art['meta']['pestana'] ?? '')) !== '';
    }

    /**
     * Lo que esta mal en un articulo, mas alla del formato del texto.
     *
     * @param array $art De parsear()
     * @param string $nombre El nombre del archivo, sin .md
     * @param array $tabs Pestana => nombre de su categoria en el menu, de las
     *                    que estan en una categoria. Ver Ayuda::categoriasDeTabs().
     * @param string|null $hoy 'Y-m-d'. Si se pasa, una fecha posterior es error.
     * @return array Los errores, como texto
     */
    public static function validar($art, $nombre, $tabs, $hoy = null) {
        $meta = $art['meta'];
        $errores = [];

        foreach (self::OBLIGATORIOS as $campo) {
            if (trim((string) ($meta[$campo] ?? '')) === '') {
                $errores[] = 'Falta el campo "' . $campo . '".';
            }
        }

        if (isset($meta['estado']) && $meta['estado'] !== '' && !isset(self::ESTADOS[$meta['estado']])) {
            $errores[] = 'Estado desconocido: "' . $meta['estado'] . '". Tiene que ser '
                . implode(', ', array_keys(self::ESTADOS)) . '.';
        }

        if (isset($meta['actualizado']) && $meta['actualizado'] !== '') {
            if (!self::fechaValida($meta['actualizado'])) {
                $errores[] = 'La fecha "' . $meta['actualizado'] . '" no es válida: va AAAA-MM-DD.';
            } elseif ($hoy !== null && $meta['actualizado'] > $hoy) {
                $errores[] = 'La fecha de actualización (' . $meta['actualizado'] . ') es posterior a hoy.';
            }
        }

        if (!preg_match('/^[a-z0-9_]+$/', (string) $nombre)) {
            $errores[] = 'El nombre del archivo va en minúsculas, números y guión bajo.';
        }

        if (self::esDePestana($art)) {
            $tab = $meta['pestana'];

            if (!isset($tabs[$tab])) {
                $errores[] = 'La pestaña "' . $tab . '" no está en ninguna categoría del menú.';
            } elseif (($meta['categoria'] ?? '') !== $tabs[$tab]) {
                $errores[] = 'La categoría es "' . $tabs[$tab] . '", como en el menú, no "'
                    . ($meta['categoria'] ?? '') . '".';
            }

            // El nombre del archivo es la pestana: es como el panel la encuentra.
            if ($nombre !== $tab) {
                $errores[] = 'El archivo de la pestaña "' . $tab . '" se tiene que llamar ' . $tab . '.md.';
            }

            $titulos = array_map(function ($s) { return $s['titulo']; }, $art['secciones']);

            if ($titulos !== self::SECCIONES_PESTANA) {
                $errores[] = 'Las secciones no son las nueve de la plantilla, en orden. Tiene: '
                    . implode(' / ', $titulos) . '.';
            }

            foreach ($art['secciones'] as $s) {
                $texto = trim(implode("\n", array_map(function ($l) { return $l[1]; }, $s['lineas'])));

                if ($texto === '') {
                    $errores[] = 'La sección "' . $s['titulo'] . '" está vacía. Si no aplica, va "'
                        . self::NO_APLICA . '" y el porqué.';
                } elseif ($texto === self::NO_APLICA) {
                    $errores[] = 'La sección "' . $s['titulo'] . '" dice que no aplica pero no dice por qué.';
                }
            }
        } else {
            if (($meta['categoria'] ?? '') !== self::GENERAL) {
                $errores[] = 'Un artículo sin pestaña es de la categoría "' . self::GENERAL . '".';
            }

            if (strpos((string) $nombre, 'general_') !== 0) {
                $errores[] = 'El nombre de un artículo general empieza con "general_".';
            }
        }

        return $errores;
    }

    /**
     * Si un texto es una fecha 'AAAA-MM-DD' que existe.
     *
     * @param string $f
     * @return bool
     */
    public static function fechaValida($f) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $f, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * El criterio de comparacion de la ayuda: sin acentos y sin mayusculas.
     *
     * Es ProveedoresOpciones::claveOrden(), el mismo del resto del modulo, y no
     * uno propio: dos normalizadores distintos harian que "dolar" encuentre una
     * cosa en una lista y otra en la ayuda. Su docblock explica por que ese y
     * no normalizarTitulo(): no cambia el largo, asi que se puede resaltar.
     *
     * @param string $texto
     * @return string
     */
    public static function normalizar($texto) {
        return ProveedoresOpciones::claveOrden($texto);
    }

    /**
     * El ancla de un titulo: sin acentos, en minusculas y con guiones.
     * "¿Por qué no estima llave?" -> "por-que-no-estima-llave".
     *
     * @param string $titulo
     * @return string
     */
    public static function slug($titulo) {
        $s = strtolower(self::normalizar($titulo));
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');

        return $s === '' ? 'seccion' : $s;
    }

    /**
     * Las anclas de un articulo, en el orden en que aparecen: las secciones
     * (##), las subsecciones (###) y las preguntas (:::pregunta).
     *
     * UNA SOLA FUNCION LAS ASIGNA y el renderizador las toma de aca por numero
     * de renglon. Si cada uno las calculara por su lado, un titulo repetido
     * -que lleva "-2"- podria quedar con un ancla en el HTML y otra en el
     * indice de busqueda, y el enlace no llevaria a ningun lado.
     *
     * Un ### adentro de un bloque no es un titulo (el bloque lo muestra como
     * texto); por eso se sigue que bloque esta abierto, con la misma regla que
     * AyudaRender: ":::tipo" abre si no hay uno abierto, ":::" solo cierra.
     *
     * @param array $art De parsear()
     * @return array [['ancla', 'titulo', 'tipo' (seccion|subseccion|pregunta),
     *                 'linea', 'seccion' => indice de la seccion]]
     */
    public static function anclas($art) {
        $usadas = [];
        $out = [];

        foreach ($art['secciones'] as $idx => $s) {
            $out[] = [
                'ancla' => self::anclaUnica($s['titulo'], $usadas),
                'titulo' => $s['titulo'],
                'tipo' => 'seccion',
                'linea' => $s['linea'],
                'seccion' => $idx
            ];

            $abierto = false;

            foreach ($s['lineas'] as $par) {
                list($n, $l) = $par;

                if (preg_match('/^:::\s*$/', $l)) {
                    $abierto = false;
                    continue;
                }

                if (preg_match('/^:::([a-z]+)\s*(.*)$/', $l, $m)) {
                    if (!$abierto && $m[1] === 'pregunta' && trim($m[2]) !== '') {
                        $out[] = [
                            'ancla' => self::anclaUnica(trim($m[2]), $usadas),
                            'titulo' => trim($m[2]),
                            'tipo' => 'pregunta',
                            'linea' => $n,
                            'seccion' => $idx
                        ];
                    }

                    $abierto = true;
                    continue;
                }

                if (!$abierto && preg_match('/^### (.+)$/', $l, $m)) {
                    $out[] = [
                        'ancla' => self::anclaUnica(trim($m[1]), $usadas),
                        'titulo' => trim($m[1]),
                        'tipo' => 'subseccion',
                        'linea' => $n,
                        'seccion' => $idx
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Las preguntas frecuentes de un articulo: las de la seccion "Preguntas
     * frecuentes" en uno de pestana, y todas en un general (no tienen
     * plantilla, y el del tablero las pone donde le sirven).
     *
     * @param array $art De parsear()
     * @return array [['ancla', 'titulo']]
     */
    public static function preguntas($art) {
        $deSeccion = null;

        if (self::esDePestana($art)) {
            foreach ($art['secciones'] as $idx => $s) {
                if ($s['titulo'] === self::SECCION_PREGUNTAS) {
                    $deSeccion = $idx;
                }
            }

            if ($deSeccion === null) {
                return [];
            }
        }

        $out = [];

        foreach (self::anclas($art) as $a) {
            if ($a['tipo'] === 'pregunta' && ($deSeccion === null || $a['seccion'] === $deSeccion)) {
                $out[] = ['ancla' => $a['ancla'], 'titulo' => $a['titulo']];
            }
        }

        return $out;
    }

    /**
     * Un ancla que todavia no se uso en el articulo: la segunda "Ejemplo" es
     * "ejemplo-2".
     *
     * @param string $titulo
     * @param array $usadas Ancla => true, se actualiza
     * @return string
     */
    private static function anclaUnica($titulo, &$usadas) {
        $base = self::slug($titulo);
        $ancla = $base;
        $n = 2;

        while (isset($usadas[$ancla])) {
            $ancla = $base . '-' . $n++;
        }

        $usadas[$ancla] = true;

        return $ancla;
    }

    /**
     * La fecha para mostrar: '2026-11-23' -> '23/11/2026'.
     *
     * @param string $f
     * @return string La original si no es una fecha valida
     */
    public static function fechaLegible($f) {
        return self::fechaValida($f) ? implode('/', array_reverse(explode('-', $f))) : (string) $f;
    }
}
