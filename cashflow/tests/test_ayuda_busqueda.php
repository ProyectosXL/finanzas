<?php
/**
 * La busqueda de la ayuda: sin acentos ni mayusculas, el orden de los
 * resultados y el resaltado. Pura: articulos armados aca.
 */

require_once __DIR__ . '/../Class/AyudaBusqueda.php';

function artBusqueda($titulo, $categoria, $cuerpo, $bajada = '') {
    return [
        'art' => AyudaArticulo::parsear("---\ntitulo: $titulo\ncategoria: $categoria\nbajada: $bajada\n---\n" . $cuerpo),
        'categoria' => $categoria
    ];
}

$articulos = [
    'proveedores_exterior' => artBusqueda('Proveedores Exterior', 'Comercio Exterior',
        "## Para qué sirve\nLos pagos al exterior, valuados con el **dólar** futuro.\n"
        . "## Preguntas frecuentes\n:::pregunta ¿Con qué dólar se valúa?\nCon la curva.\n:::\n"),
    'crono_nacionalizacion' => artBusqueda('Crono Nacionalización', 'Comercio Exterior',
        "## Para qué sirve\nLa nacionalización, al DOLAR divisa.\n### El Dólar divisa\nDel día hábil anterior.\n"),
    'general_tablero' => artBusqueda('Cómo leer el tablero', 'General',
        "## Ingresos y egresos\nNada de monedas.\n", 'El dólar no aparece acá… sí aparece.'),
    'llaves_renov' => artBusqueda('Llaves y Renov. Contratos', 'RRHH y Operativos',
        "## Para qué sirve\nLas cuotas de la llave.\n## Glosario\nLlave: lo que se paga al renovar.\n")
];

$indice = AyudaBusqueda::indice($articulos);

seccion('el indice');

chequear('una unidad por articulo y una por cada ancla', 4 + 3 + 2 + 1 + 2, count($indice));
$preg = array_values(array_filter($indice, function ($u) { return $u['tipo'] === 'pregunta'; }))[0];
chequear('la pregunta es una unidad, con su ancla y su texto', ['con-que-dolar-se-valua', 'Con la curva.'],
    [$preg['ancla'], $preg['texto']]);
chequear('y sabe de que seccion es', 'Preguntas frecuentes', $preg['seccion']);

seccion('sin acentos ni mayusculas');

$sin = AyudaBusqueda::buscar($indice, 'dolar');
$con = AyudaBusqueda::buscar($indice, 'DÓLAR');
chequear('"dolar" encuentra "dólar" y "DOLAR"', true, count($sin) >= 4);
chequear('y da lo mismo que "DÓLAR"', array_column($sin, 'enlace'), array_column($con, 'enlace'));
chequear('aparecen las dos de Comercio Exterior', true,
    in_array('proveedores_exterior', array_column($sin, 'articulo'), true)
    && in_array('crono_nacionalizacion', array_column($sin, 'articulo'), true));

chequear('una letra sola no busca', [], AyudaBusqueda::buscar($indice, 'a'));
chequear('ni un termino vacio', [], AyudaBusqueda::buscar($indice, '   '));
chequear('lo que no esta, no da nada', [], AyudaBusqueda::buscar($indice, 'inexistente'));
chequear('se busca la frase, no cada palabra', 0, count(AyudaBusqueda::buscar($indice, 'llave dólar')));

seccion('el orden');

/* Titulo del articulo, despues titulo de seccion, despues texto. Adentro de
   cada nivel, por titulo del articulo. */
$r = AyudaBusqueda::buscar($indice, 'dolar');
chequear('primero los titulos de seccion, alfabetico por articulo; despues el texto',
    [
        [2, 'crono_nacionalizacion', 'el-dolar-divisa'],
        [2, 'proveedores_exterior', 'con-que-dolar-se-valua'],
        [3, 'general_tablero', ''],
        [3, 'crono_nacionalizacion', 'para-que-sirve'],
        [3, 'proveedores_exterior', 'para-que-sirve']
    ],
    array_map(function ($x) { return [$x['nivel'], $x['articulo'], $x['ancla']]; }, $r));

/* "Cómo leer el tablero" ordena por C y "Crono" tambien: la clave no tiene
   acentos, asi que "Cómo" va antes que "Crono" como lo lee cualquiera. */

$r = AyudaBusqueda::buscar($indice, 'llave');
chequear('el titulo del articulo va antes que todo', [1, 'llaves_renov', ''],
    [$r[0]['nivel'], $r[0]['articulo'], $r[0]['ancla']]);
chequear('despues el texto, en el orden del articulo', ['para-que-sirve', 'glosario'],
    array_column(array_slice($r, 1), 'ancla'));

seccion('cada resultado');

$r = AyudaBusqueda::buscar($indice, 'cuotas')[0];
chequear('la ruta: categoria, articulo y seccion',
    ['RRHH y Operativos', 'Llaves y Renov. Contratos', 'Para qué sirve'], $r['ruta']);
chequear('el titulo de la seccion', 'Para qué sirve', $r['titulo']);
chequear('el fragmento con el termino resaltado', 'Las <mark>cuotas</mark> de la llave.', $r['fragmento']);
chequear('y el enlace al Centro, en la seccion', '#ayuda/llaves_renov/para-que-sirve', $r['enlace']);

$r = AyudaBusqueda::buscar($indice, 'divisa');
chequear('el titulo tambien se resalta', 'El Dólar <mark>divisa</mark>', $r[0]['titulo_html']);

seccion('el fragmento');

$largo = str_repeat('palabra ', 30) . 'el término buscado ' . str_repeat('otra ', 30);
$f = AyudaBusqueda::fragmento($largo, 'termino');
chequear('se recorta alrededor, con "…" de los dos lados', true,
    strpos($f, '… ') === 0 && substr($f, -4) === ' …' && strpos($f, '<mark>término</mark>') !== false);
chequear('sin cortar palabras: empieza y termina en una entera', [true, true],
    [strpos($f, '… palabra ') === 0, substr($f, -strlen('otra …')) === 'otra …']);
chequear('el texto se escapa', '&lt;b&gt; <mark>hola</mark>', AyudaBusqueda::fragmento('<b> hola', 'hola'));
chequear('si el termino no esta en el texto, el principio', 'Del día', AyudaBusqueda::fragmento('Del día', 'zzz'));

chequear('las busquedas frecuentes son cuatro', 4, count(AyudaBusqueda::FRECUENTES));
