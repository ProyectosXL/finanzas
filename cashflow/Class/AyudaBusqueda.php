<?php

require_once __DIR__ . '/AyudaRender.php';

/**
 * AyudaBusqueda
 * El buscador del Centro de ayuda: arma el indice con los articulos ya leidos,
 * busca un termino y ordena los resultados.
 *
 * SIN ACENTOS Y SIN MAYUSCULAS, con el criterio del modulo
 * (AyudaArticulo::normalizar(), que es ProveedoresOpciones::claveOrden()): quien
 * busca "dolar" tiene que encontrar "Dólar". Se busca la FRASE tal cual se
 * escribio, sin partirla en palabras: "ya pagada" encuentra el boton y no cada
 * "ya" del articulo.
 *
 * QUE ES UN RESULTADO. La unidad es el pedazo de articulo que tiene ancla
 * propia: cada seccion (##), cada subseccion (###) y cada pregunta frecuente,
 * mas el articulo entero por su titulo. Asi el enlace del resultado lleva a la
 * pregunta y no al principio de "Preguntas frecuentes".
 *
 * EL ORDEN, en tres niveles y en este orden:
 *   1. el termino esta en el TITULO DEL ARTICULO;
 *   2. esta en el TITULO DE LA SECCION (o subseccion, o pregunta);
 *   3. esta solo en el TEXTO.
 * Adentro de cada nivel, por titulo del articulo y, en un mismo articulo, en
 * el orden en que aparecen. Quien busca "llaves" quiere primero el articulo de
 * Llaves, no un parrafo de otro que la nombra.
 *
 * NO SE GUARDA EL INDICE. Se arma leyendo los archivos en cada busqueda; la
 * medicion esta en README-cashflow.md, "La ayuda funcional". Un indice
 * guardado seria un segundo lugar que se desactualiza cuando alguien edita un
 * articulo y se olvida de regenerarlo.
 *
 * PURO: recibe los articulos ya leidos. Leer el disco es de Class/Ayuda.php.
 */
class AyudaBusqueda {

    /** Menos letras que esto no busca: "a" coincide con todo */
    const MINIMO = 2;

    /** Cuantos resultados se devuelven como mucho */
    const MAXIMO = 30;

    /** Letras de contexto a cada lado del termino en el fragmento */
    const CONTEXTO = 70;

    /** Las busquedas frecuentes que el Centro muestra debajo del buscador */
    const FRECUENTES = ['dólar', 'ya pagada', 'vencidas', 'cuotas'];

    /**
     * El indice: una unidad por cada pedazo con ancla propia.
     *
     * @param array $articulos nombre => ['art' => de AyudaArticulo::parsear(),
     *                         'categoria' => nombre para la ruta]
     * @return array [['articulo', 'titulo_articulo', 'categoria', 'seccion',
     *                 'titulo', 'ancla', 'tipo', 'orden', 'texto']]
     */
    public static function indice($articulos) {
        $indice = [];

        foreach ($articulos as $nombre => $a) {
            $art = $a['art'];
            $tituloArt = (string) ($art['meta']['titulo'] ?? $nombre);
            $base = [
                'articulo' => $nombre,
                'titulo_articulo' => $tituloArt,
                'categoria' => (string) ($a['categoria'] ?? ($art['meta']['categoria'] ?? ''))
            ];

            $indice[] = $base + [
                'seccion' => '',
                'titulo' => $tituloArt,
                'ancla' => '',
                'tipo' => 'articulo',
                'orden' => 0,
                'texto' => (string) ($art['meta']['bajada'] ?? '')
            ];

            $anclas = AyudaArticulo::anclas($art);

            foreach ($anclas as $k => $an) {
                $seccion = $art['secciones'][$an['seccion']];
                // Hasta la proxima ancla de la MISMA seccion, o el final de ella.
                $hasta = PHP_INT_MAX;

                if (isset($anclas[$k + 1]) && $anclas[$k + 1]['seccion'] === $an['seccion']) {
                    $hasta = $anclas[$k + 1]['linea'];
                }

                $lineas = array_values(array_filter($seccion['lineas'], function ($l) use ($an, $hasta) {
                    return $l[0] > $an['linea'] && $l[0] < $hasta;
                }));

                $indice[] = $base + [
                    'seccion' => $seccion['titulo'],
                    'titulo' => $an['titulo'],
                    'ancla' => $an['ancla'],
                    'tipo' => $an['tipo'],
                    'orden' => $k + 1,
                    'texto' => AyudaRender::textoPlano($lineas)
                ];
            }
        }

        return $indice;
    }

    /**
     * Busca un termino en el indice.
     *
     * @param array $indice De indice()
     * @param string $termino Lo que se escribio
     * @return array [['articulo', 'ancla', 'enlace', 'ruta' => [categoria,
     *                 articulo, seccion], 'titulo', 'fragmento' (HTML), 'nivel']]
     */
    public static function buscar($indice, $termino) {
        $termino = trim(preg_replace('/\s+/u', ' ', (string) $termino));
        $t = AyudaArticulo::normalizar($termino);

        if (mb_strlen($t, 'UTF-8') < self::MINIMO) {
            return [];
        }

        $hallados = [];

        foreach ($indice as $u) {
            $nivel = null;

            if ($u['tipo'] === 'articulo') {
                if (AyudaRender::contiene($u['titulo'], $termino)) {
                    $nivel = 1;
                } elseif (AyudaRender::contiene($u['texto'], $termino)) {
                    $nivel = 3;
                }
            } elseif (AyudaRender::contiene($u['titulo'], $termino)) {
                $nivel = 2;
            } elseif (AyudaRender::contiene($u['texto'], $termino)) {
                $nivel = 3;
            }

            if ($nivel === null) {
                continue;
            }

            $ruta = [$u['categoria'], $u['titulo_articulo']];

            if ($u['seccion'] !== '') {
                $ruta[] = $u['seccion'];
            }

            $hallados[] = [
                'articulo' => $u['articulo'],
                'ancla' => $u['ancla'],
                'enlace' => AyudaRender::direccion($u['articulo'], $u['ancla']),
                'ruta' => $ruta,
                'titulo' => $u['titulo'],
                'titulo_html' => AyudaRender::resaltado($u['titulo'], $termino),
                'fragmento' => self::fragmento($u['texto'], $termino),
                'nivel' => $nivel,
                '_clave' => AyudaArticulo::normalizar($u['titulo_articulo']),
                '_orden' => $u['orden']
            ];
        }

        usort($hallados, function ($a, $b) {
            return [$a['nivel'], $a['_clave'], $a['_orden']] <=> [$b['nivel'], $b['_clave'], $b['_orden']];
        });

        return array_map(function ($h) {
            unset($h['_clave'], $h['_orden']);

            return $h;
        }, array_slice($hallados, 0, self::MAXIMO));
    }

    /**
     * El pedazo de texto alrededor de la primera vez que aparece el termino,
     * escapado y con el termino marcado. Si el termino no esta en el texto (la
     * coincidencia fue en el titulo), el principio del texto.
     *
     * Se corta en un espacio para no dejar media palabra, y "…" dice que hay
     * mas de cada lado.
     *
     * @param string $texto Texto plano
     * @param string $termino
     * @return string HTML
     */
    public static function fragmento($texto, $termino) {
        $texto = (string) $texto;
        $largo = mb_strlen($texto, 'UTF-8');
        $t = AyudaArticulo::normalizar(trim((string) $termino));
        $p = $t === '' ? false : mb_strpos(AyudaArticulo::normalizar($texto), $t, 0, 'UTF-8');

        if ($p === false) {
            $desde = 0;
            $hasta = min($largo, 2 * self::CONTEXTO);
        } else {
            $desde = max(0, $p - self::CONTEXTO);
            $hasta = min($largo, $p + mb_strlen($t, 'UTF-8') + self::CONTEXTO);
        }

        if ($desde > 0) {
            $esp = mb_strpos($texto, ' ', $desde, 'UTF-8');
            $desde = ($esp !== false && ($p === false || $esp < $p)) ? $esp + 1 : $desde;
        }

        if ($hasta < $largo) {
            $esp = mb_strrpos(mb_substr($texto, 0, $hasta, 'UTF-8'), ' ', 0, 'UTF-8');
            $hasta = ($esp !== false && ($p === false || $esp > $p + mb_strlen($t, 'UTF-8'))) ? $esp : $hasta;
        }

        $pedazo = mb_substr($texto, $desde, $hasta - $desde, 'UTF-8');

        return ($desde > 0 ? '… ' : '') . AyudaRender::resaltado($pedazo, $termino) . ($hasta < $largo ? ' …' : '');
    }
}
