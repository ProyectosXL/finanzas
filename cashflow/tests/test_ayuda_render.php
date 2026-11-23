<?php
/**
 * El renderizador de la ayuda: el Markdown reducido, los bloques especiales y,
 * sobre todo, que nada escrito en un articulo llegue al HTML sin escapar.
 *
 * Puro: articulos armados aca, sin disco.
 */

require_once __DIR__ . '/../Class/AyudaRender.php';

/** Renderiza un cuerpo (sin encabezado) y devuelve [html, errores, resultado] */
function render($cuerpo, $op = []) {
    $art = AyudaArticulo::parsear("---\ntitulo: x\n---\n" . $cuerpo);
    $r = AyudaRender::articulo($art, $op);

    return [implode('', array_column($r['secciones'], 'html')), $r['errores'], $r];
}

function tiene($html, $aguja) {
    return strpos($html, $aguja) !== false;
}

seccion('la seguridad: todo sale escapado');

list($h) = render("## A\n<script>alert(1)</script>\n\n<b onclick=\"x()\">hola</b>\n");
chequear('un <script> escrito sale como texto', true, tiene($h, '&lt;script&gt;alert(1)&lt;/script&gt;'));
chequear('y no queda ninguna etiqueta script', false, stripos($h, '<script') !== false);
chequear('un atributo con comillas tampoco', true,
    tiene($h, '&lt;b onclick=&quot;x()&quot;&gt;') && !tiene($h, '<b '));

list($h) = render("## <img src=x onerror=alert(1)>\nx\n");
chequear('el titulo de una seccion tambien se escapa', false, stripos($h, '<img src=x') !== false);

list($h, $e) = render("## A\n[clic](javascript:alert(1))\n");
chequear('un enlace javascript: sale como texto', [false, 1],
    [stripos($h, 'href="javascript') !== false, count($e)]);

list($h, $e) = render("## A\n[x](data:text/html,hola) [y](/etc/passwd)\n");
chequear('data: y rutas sueltas tampoco son enlaces', [false, 2], [tiene($h, '<a '), count($e)]);

list($h) = render("## A\n[ok\"><script>](https://xl.com.ar/\"onmouseover=\")\n");
chequear('una URL con comillas no rompe el atributo', false, stripos($h, '<script') !== false);

list($h, $e) = render("## A\n![x](../../index.php) ![y](https://otro.com/a.png)\n");
chequear('una imagen fuera de img/ no se dibuja', [false, 2], [tiene($h, '<img'), count($e)]);

list($h) = render("## A\n`<i>código</i>` **<u>negrita</u>**\n");
chequear('el codigo y la negrita tambien escapan su contenido', true,
    tiene($h, '<code>&lt;i&gt;código&lt;/i&gt;</code>') && tiene($h, '<strong>&lt;u&gt;negrita&lt;/u&gt;</strong>'));

list($h) = render("## A\n:::aviso atencion\ntexto: <script>x</script>\nsignifica: a\ncorrige: b\n:::\n");
chequear('adentro de un bloque, igual', false, stripos($h, '<script') !== false);

list($h) = render("## A\nhola <script>\n", ['resaltar' => 'script']);
chequear('el resaltado no abre otra puerta', true, tiene($h, '&lt;<mark>script</mark>&gt;'));

seccion('el texto');

list($h, $e) = render("## Para qué sirve\nUna **negrita**, una *itálica* y `1.450,75`.\n\nOtro párrafo\nque sigue.\n");
chequear('titulo con su ancla', true, tiene($h, '<h2 id="para-que-sirve">Para qué sirve</h2>'));
chequear('negrita, italica y codigo', true,
    tiene($h, '<strong>negrita</strong>') && tiene($h, '<em>itálica</em>') && tiene($h, '<code>1.450,75</code>'));
chequear('dos parrafos, y los renglones seguidos se juntan', 2, substr_count($h, '<p>'));
chequear('sin errores', [], $e);
chequear('25 % × alquiler no es italica', false, tiene(render("## A\n25 % × alquiler × 6\n")[0], '<em>'));

list($h, $e) = render("## A\nuna **sin cerrar\n");
chequear('una negrita sin cerrar se ve y se avisa', [true, 1], [tiene($h, '**sin cerrar'), count($e)]);

list($h) = render("## A\n### Sub\n- uno\n- dos\n  sigue\n\n1. primero\n2. segundo\n");
chequear('###, lista con viñetas y numerada', true,
    tiene($h, '<h3 id="sub">Sub</h3>') && tiene($h, '<ul><li>uno</li><li>dos sigue</li></ul>')
    && tiene($h, '<ol><li>primero</li><li>segundo</li></ol>'));

list($h, $e) = render("## A\n| Parámetro | Valor |\n| --- | ---: |\n| Cuotas | 4 |\n");
chequear('una tabla, con la columna alineada a la derecha', true,
    tiene($h, '<th>Parámetro</th><th class="text-end">Valor</th>') && tiene($h, '<td class="text-end">4</td>'));
chequear('que el orden por encabezado no toma', true, tiene($h, 'data-orden="no"'));

list($h, $e) = render("## A\n| a | b |\n| c | d |\n");
chequear('una tabla sin separador se ve como texto y se avisa', [true, 1], [tiene($h, 'ayuda-roto'), count($e)]);

list($h) = render("## A\n<!-- Acá va la captura de la grilla -->\ntexto\n<!--\nvarios\nrenglones -->\n");
chequear('los comentarios no se ven', false, tiene($h, 'captura') || tiene($h, 'varios'));

seccion('los enlaces');

$dest = ['llaves_renov' => ['como-se-calcula'], 'general_tablero' => []];
list($h, $e, $r) = render("## A\n[ver](ayuda:llaves_renov#como-se-calcula) y [tablero](ayuda:general_tablero)\n",
    ['destinos' => $dest]);
chequear('un enlace de la ayuda va a la direccion del Centro', true,
    tiene($h, 'href="?ayuda=llaves_renov#como-se-calcula"') && tiene($h, 'href="?ayuda=general_tablero"'));
chequear('y se informa para validar', 2, count($r['enlaces']));
chequear('sin errores si existen', [], $e);

list(, $e) = render("## A\n[x](ayuda:no_existe) [y](ayuda:llaves_renov#no-existe)\n", ['destinos' => $dest]);
chequear('un articulo o una seccion que no existen se avisan', 2, count($e));

list($h) = render("## A\n[XL](https://www.xl.com.ar)\n");
chequear('uno externo abre aparte', true,
    tiene($h, 'href="https://www.xl.com.ar" target="_blank" rel="noopener noreferrer"'));

seccion('las imagenes');

list($h, $e, $r) = render("## A\n![La grilla con las marcas A y B](img/llaves_renov/grilla.png)\n");
chequear('una imagen de img/ se dibuja con su texto alternativo', true,
    tiene($h, '<img class="ayuda-img" src="Ayuda/img/llaves_renov/grilla.png" alt="La grilla con las marcas A y B"'));
chequear('y se informa para ver que exista', 'img/llaves_renov/grilla.png', $r['imagenes'][0]['ruta']);

list($h, $e) = render("## A\n![](img/llaves_renov/grilla.png)\n");
chequear('sin texto alternativo no se dibuja y se avisa', [false, 1], [tiene($h, '<img'), count($e)]);

seccion('los bloques especiales');

list($h, $e) = render("## A\n:::formula\nllave = % × alquiler × meses\n:::\n");
chequear('formula', true, tiene($h, '<div class="ayuda-formula">llave = % × alquiler × meses</div>'));

list($h, $e) = render("## A\n:::ejemplo ilustrativo\nUn local que paga **$ 1.000.000**.\n\n| a | b |\n| --- | --- |\n| 1 | 2 |\n:::\n");
chequear('ejemplo, con su titulo y lo que lleva adentro', true,
    tiene($h, 'ayuda-ejemplo-titulo">Ejemplo') && tiene($h, '<strong>$ 1.000.000</strong>') && tiene($h, '<table'));
chequear('sin errores', [], $e);

list(, $e) = render("## A\n:::ejemplo\nx\n:::\n:::ejemplo real 2026-11-23\nx\n:::\n");
chequear('un ejemplo que no dice "ilustrativo" se avisa: no van numeros reales', 2, count($e));

list($h, $e) = render("## A\n:::diagrama\nfuente: La app de Alquileres | los contratos\nfuente: Tango | las facturas\n"
    . "pestana: Llaves | calcula\ntablero: Fila Llaves | real y proyectado\nalt: De Alquileres y Tango a la pestaña y al tablero\n:::\n");
chequear('diagrama: tres columnas y dos flechas', [3, 2],
    [substr_count($h, 'ayuda-diagrama-col'), substr_count($h, 'ayuda-diagrama-flecha')]);
chequear('con su texto alternativo', true,
    tiene($h, 'aria-label="De Alquileres y Tango a la pestaña y al tablero"'));
chequear('sin errores', [], $e);

list(, $e) = render("## A\n:::diagrama\nfuente: a\npestana: b\n:::\n");
chequear('un diagrama sin alt se avisa', 1, count($e));

list($h, $e) = render("## A\n:::pasos\n1. Abrí **Contratos**.\n2. Tocá **Ya pagada**.\nResultado: la renovación sale de la proyección.\n:::\n");
chequear('pasos con circulos numerados y el resultado', true,
    tiene($h, '<span class="ayuda-paso-num" aria-hidden="true">2</span>')
    && tiene($h, '<strong>Resultado:</strong> la renovación sale'));

list(, $e) = render("## A\n:::pasos\n1. Uno.\n:::\n");
chequear('unos pasos sin resultado se avisan', 1, count($e));

// Con su captura antes: sin ella no se dibujan (ver el final de este archivo).
list($h, $e) = render("## A\n![La pantalla](img/x/pantalla.png)\n\n:::marcas\nA: Las solapas.\nB: Las tarjetas.\n:::\n");
chequear('marcas', true, tiene($h, '<span class="ayuda-marca" aria-hidden="true">B</span>'));
list(, $e) = render("## A\n:::marcas\nA: uno\nC: tres\n:::\n");
chequear('marcas fuera de orden se avisan', 1, count($e));

list($h, $e) = render("## A\n:::aviso atencion\ntexto: Contrato vencido sin renovar.\nsignifica: Se estima igual.\ncorrige: En la app.\n:::\n\n"
    . ":::aviso info\ntexto: Vence en el horizonte.\nsignifica: a\ncorrige: b\n:::\n:::aviso critico\ntexto: Falta un script.\nsignifica: a\ncorrige: b\n:::\n\nDespués.\n");
chequear('tres avisos seguidos son una sola tabla', 1, substr_count($h, '<table'));
chequear('con los colores y las etiquetas del panel de avisos', true,
    tiene($h, 'ayuda-nivel aviso-warning') && tiene($h, '</i> Atención</span>')
    && tiene($h, 'ayuda-nivel aviso-info') && tiene($h, 'ayuda-nivel aviso-danger') && tiene($h, '</i> Crítico</span>'));
chequear('y el texto de despues va aparte', true, tiene($h, '</table></div><p>Después.</p>'));
chequear('sin errores', [], $e);

list(, $e) = render("## A\n:::aviso grave\ntexto: x\n:::\n");
chequear('un nivel desconocido y los campos que faltan se avisan', 3, count($e));

list($h, $e) = render("## Preguntas frecuentes\n:::pregunta ¿Qué es un contrato puente?\nUno **corto** sin llave.\n:::\n");
chequear('pregunta: un desplegable con su ancla', true,
    tiene($h, '<details class="ayuda-pregunta" id="que-es-un-contrato-puente"><summary>¿Qué es un contrato puente?</summary>')
    && tiene($h, '<strong>corto</strong>'));

seccion('lo roto se ve como texto y se avisa');

list($h, $e) = render("## A\n:::formula\nsin cerrar\n\nmás texto\n");
chequear('un bloque sin cerrar', [true, true, 1], [tiene($h, 'ayuda-roto'), tiene($h, 'sin cerrar'), count($e)]);

list($h, $e) = render("## A\n:::grafico\nx\n:::\n");
chequear('un bloque que no existe', [true, 1], [tiene($h, ':::grafico'), count($e)]);

list($h, $e) = render("## A\n:::ejemplo ilustrativo\n:::formula\n:::\n");
chequear('un bloque dentro de otro', true, count($e) >= 1);

list(, $e) = render("## A\n:::\n");
chequear('un ":::" suelto', 1, count($e));
chequear('el error dice el renglon', true, strpos($e[0], 'Renglón 5') === 0);

seccion('el buscador del panel');

list($h, , $r) = render("## Para qué sirve\nLas cuotas de la llave.\n## Glosario\nNada que ver.\n", ['resaltar' => 'CUOTAS']);
chequear('marca el termino sin mayusculas', true, tiene($h, 'Las <mark>cuotas</mark> de la llave.'));
chequear('y dice que secciones coinciden', [true, false], array_column($r['secciones'], 'coincide'));

chequear('sin acentos, y el original queda como estaba', 'el <mark>dólar</mark> BCRA',
    AyudaRender::resaltado('el dólar BCRA', 'DOLAR'));
chequear('todas las veces', 2, substr_count(AyudaRender::resaltado('cuota y cuotas', 'cuota'), '<mark>'));
chequear('el texto plano saca la sintaxis', 'La llave y Tango. ¿Por qué?',
    AyudaRender::textoPlano([[1, '**La** [llave](ayuda:x) y `Tango`.'], [2, ':::pregunta ¿Por qué?'], [3, ':::']]));
chequear('enLinea escapa y marca', '<strong>a</strong> &lt;b&gt;', AyudaRender::enLinea('**a** <b>'));

seccion('las marcas sin su captura no se muestran');

/* Son las letras que senalan zonas de una imagen: sin la imagen no senalan
   nada. Se muestran solo si hay una imagen antes, en la misma seccion. */
list($h, $e) = render("## La pantalla\n<!-- Acá va la captura -->\n:::marcas\nA: Los avisos.\nB: Las tarjetas.\n:::\n");
chequear('sin captura, la lista no se dibuja', false, tiene($h, 'ayuda-marcas'));
chequear('y no es un error: queda lista para cuando llegue la captura', [], $e);

list($h) = render("## La pantalla\n![La pantalla con las marcas](img/x/pantalla.png)\n\n:::marcas\nA: Los avisos.\n:::\n");
chequear('con la captura antes, se dibuja', true, tiene($h, '<ol class="ayuda-marcas">'));

list($h) = render("## La pantalla\n:::marcas\nA: Los avisos.\n:::\n\n![Otra](img/x/otra.png)\n");
chequear('una captura despues no cuenta', false, tiene($h, 'ayuda-marcas'));

list($h) = render("## Para qué sirve\n![Una imagen](img/x/a.png)\n## La pantalla\n:::marcas\nA: Los avisos.\n:::\n");
chequear('ni una de otra seccion', false, tiene($h, 'ayuda-marcas'));

list(, $e) = render("## La pantalla\n:::marcas\nA: uno\nC: tres\n:::\n");
chequear('escondida, se valida igual', 1, count($e));

chequear('el buscador no encuentra lo que no se ve', 'Texto.',
    AyudaRender::textoPlano([[1, 'Texto.'], [2, ':::marcas'], [3, 'A: Los avisos.'], [4, ':::']]));
chequear('con la captura, si', true, strpos(AyudaRender::textoPlano([[1, '![Alt](img/x/a.png)'], [2, ':::marcas'],
    [3, 'A: Los avisos.'], [4, ':::']]), 'Los avisos.') !== false);
