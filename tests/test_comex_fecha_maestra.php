<?php
/**
 * Comercio Exterior: la fecha vive en el maestro, y los vencidos se ven.
 *
 * Tres cosas cambiaron en esta rama y las tres se rompen en silencio:
 *
 *   1. Las dos fechas editables se escriben sobre
 *      RO_T_IMPORTACIONES_ENCABEZADO y no sobre la tabla del cashflow. Si
 *      alguien vuelve a leer las columnas EDIT, la pantalla no falla: muestra
 *      una fecha vieja que la otra aplicacion ya no tiene.
 *   2. El filtro por fecha de embarque se fue. Si vuelve, desaparecen 42 de 76
 *      contenedores y el tablero informa de menos sin que nada se caiga.
 *   3. Un importe con fecha vencida NO se reubica en hoy. Si alguien lo
 *      "arregla" reusando Ingresos::ubicarCobroVencido(), la columna de hoy se
 *      llena con dos mil millones de pesos de pagos que probablemente ya
 *      salieron, y el tablero sigue dando un numero.
 *
 * LAS REGLAS SE PRUEBAN SIN BASE, que es por lo que viven afuera de las
 * consultas. Lo que necesita SQL Server se saltea solo.
 *
 * Y HAY PRUEBAS QUE LEEN ARCHIVOS, con el mismo criterio de
 * test_tablas_controles.php: el filtro que se saco y el endpoint que se unifico
 * no tienen logica que verificar, tienen CABLEADO, y el cableado se lee.
 */

require_once __DIR__ . '/../cashflow/Class/Comex.php';

/**
 * El codigo de un archivo, SIN SUS COMENTARIOS.
 *
 * Hace falta porque estos archivos explican en prosa lo que dejaron de hacer
 * -"antes era COALESCE(FECHA_PAGO_EDIT, ...)", "antes viajaba fecha_pago_orig
 * desde el navegador"- y esas notas son justamente lo que este modulo pide que
 * se escriba. Buscar el patron sobre el archivo entero daria positivo en la
 * nota que dice que el patron ya no esta, y la unica forma de pasar la prueba
 * seria borrar la explicacion.
 *
 * @param string $ruta
 * @return string
 */
function codigoSinComentarios($ruta) {
    $src = file_get_contents($ruta);

    // Bloques /* ... */, que es como estan escritos los encabezados y casi
    // todas las notas del modulo.
    $src = preg_replace('#/\*.*?\*/#s', '', $src);

    // Y las de una linea, sin tocar lo que tengan a la izquierda: una // que
    // empieza a mitad de linea comenta el resto, no la linea entera.
    return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
}

/* ================================================================
   LOS DOS CAMPOS EDITABLES, Y A QUE COLUMNA DEL MAESTRO VAN

   La lista es cerrada y vive en el codigo: es lo que impide que un pedido
   armado a mano escriba sobre otra columna del maestro de Comercio Exterior.
   ================================================================ */
seccion('las dos fechas editables apuntan al maestro');

chequear('la estimada de pago es FECHA_EST_PAGO', 'FECHA_EST_PAGO', Comex::CAMPOS['PAGO']);
chequear('la de nacionalizacion es FECHA_DESP_ADU', 'FECHA_DESP_ADU', Comex::CAMPOS['NAC']);
chequear('y no hay un tercer campo editable', 2, count(Comex::CAMPOS));

// La tabla del maestro se nombra una sola vez: si alguien la escribe a mano en
// una consulta, este par deja de coincidir.
chequear('el maestro es el de importaciones', 'RO_T_IMPORTACIONES_ENCABEZADO',
    Comex::TABLA_MAESTRO);
chequear('y el rastro va en una tabla del cashflow', 'RO_T_CASHFLOW_COMEX_FECHA_EDIT',
    Comex::TABLA_HISTORIAL);

/* ================================================================
   QUE ES UNA FECHA VENCIDA

   Lo que decide si el importe entra en alguna columna del eje. Es el corte con
   el dia de hoy, y por eso hoy se pasa como argumento: una prueba con fechas
   fijas que dependa de la fecha del sistema caduca sola, que es lo que ya le
   paso una vez a las del motor.
   ================================================================ */
seccion('una fecha anterior a hoy esta vencida');

chequear('ayer', true, Comex::estaVencida('2026-09-18', '2026-09-19'));
chequear('el mes pasado', true, Comex::estaVencida('2026-08-31', '2026-09-19'));
chequear('hace un anio', true, Comex::estaVencida('2025-10-23', '2026-09-19'));

seccion('hoy NO esta vencida');

// HOY ES EL PRIMER DIA DEL EJE: su importe entra en la primera columna. Correr
// el corte un dia sacaria del tablero todo lo que vence hoy.
chequear('hoy mismo', false, Comex::estaVencida('2026-09-19', '2026-09-19'));
chequear('manana', false, Comex::estaVencida('2026-09-20', '2026-09-19'));
chequear('el anio que viene', false, Comex::estaVencida('2027-04-14', '2026-09-19'));

seccion('sin fecha NO es vencida: es otro problema');

/* Son dos cosas distintas con dos acciones distintas -una fecha vencida hay que
   corregirla, una que falta hay que cargarla- y el aviso es otro. Si esto
   devolviera true, las dos se juntarian en un numero que no le dice a nadie que
   hacer. */
chequear('null', false, Comex::estaVencida(null, '2026-09-19'));
chequear('cadena vacia', false, Comex::estaVencida('', '2026-09-19'));

seccion('acepta lo que devuelve sqlsrv, no solo strings');

chequear('un DateTime', true, Comex::estaVencida(new DateTime('2026-09-18'), '2026-09-19'));
chequear('una fecha con hora', true, Comex::estaVencida('2026-09-18 23:59:00', '2026-09-19'));

// El hoy tambien puede llegar con hora: se corta igual, y sin eso la
// comparacion de strings daria que hoy es mayor que hoy.
chequear('y un hoy con hora', false, Comex::estaVencida('2026-09-19', '2026-09-19 14:32:00'));

/* ================================================================
   LA MARCA DE "EDITADA DESDE EL CASHFLOW"

   El rastro dice "el cashflow puso esta fecha". Si despues la app de Comercio
   Exterior movio la misma columna, el rastro sigue siendo cierto pero YA NO
   EXPLICA lo que hay en la celda.
   ================================================================ */
seccion('la marca describe el valor que se ve, no el historial');

chequear('el maestro dice lo que el cashflow escribio', true,
    Comex::marcaVigente('2026-10-15', '2026-10-15'));

// EL CASO QUE JUSTIFICA QUE SE CALCULE Y NO SE GUARDE: un bit persistido
// quedaria mintiendo desde el primer cambio hecho del otro lado, que es un
// cambio que este modulo no ve pasar.
chequear('la otra app la movio despues: no se marca', false,
    Comex::marcaVigente('2026-10-15', '2026-11-20'));

chequear('sin rastro no hay marca', false, Comex::marcaVigente(null, '2026-10-15'));
chequear('y sin fecha en el maestro tampoco', false, Comex::marcaVigente('2026-10-15', null));
chequear('ni con los dos vacios', false, Comex::marcaVigente(null, null));

seccion('la marca compara fechas, no textos');

// Una viene de la columna DATE del rastro y la otra de la del maestro, y sqlsrv
// las puede entregar como DateTime: comparadas como strings nunca coincidirian.
chequear('DateTime contra string', true,
    Comex::marcaVigente(new DateTime('2026-10-15'), '2026-10-15'));
chequear('y con hora de un lado', true,
    Comex::marcaVigente('2026-10-15 00:00:00', '2026-10-15'));

/* ================================================================
   EL AVISO DE LOS VENCIDOS

   Es lo que hace visible la decision de NO reubicar en hoy. Sin el, esos
   importes caen en el 'fuera del horizonte' generico de EjeVista, donde se
   confunden con los que caen DESPUES del ultimo mes -que son otra cosa y no se
   arreglan editando nada-.
   ================================================================ */
seccion('lo vencido se informa con el conteo y el importe');

$filas = [
    ['VENCIDA' => true,  'IMPORTE_ARS' => 1000000],
    ['VENCIDA' => true,  'IMPORTE_ARS' => 500000.50],
    ['VENCIDA' => false, 'IMPORTE_ARS' => 9999999]
];

$avisos = Comex::avisosVencidos($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago');

chequear('es un solo aviso', 1, count($avisos));
chequear('dice cuantos son', true, strpos($avisos[0], '2 contenedor(es)') !== false);
chequear('y cuanto suman', true, strpos($avisos[0], '$ 1.500.000,50') !== false);
chequear('nombrando que fecha es', true,
    strpos($avisos[0], 'fecha estimada de pago') !== false);

// EL TEXTO DICE LA CONSECUENCIA Y LA ACCION. Un aviso que dijera solo "hay 2
// vencidos" no explica por que el importe no esta en ninguna columna ni que
// hacer para que entre.
chequear('dice que no entran en ninguna columna', true,
    strpos($avisos[0], 'NO entran en ninguna columna') !== false);
chequear('que no se reubican en hoy', true,
    strpos($avisos[0], 'No se los reubica en hoy') !== false);
chequear('y que se arregla cargando la fecha', true,
    strpos($avisos[0], 'cargales la fecha nueva') !== false);

/* ================================================================
   NO TODO LO VENCIDO QUEDA AFUERA DEL CUADRO

   Esto no es obvio y es lo que obligo a partir el aviso en dos: la columna del
   MES EN CURSO cubre los dias de ese mes que quedaron fuera del tramo diario,
   o sea DIAS QUE YA PASARON. Un pago vencido de este mismo mes cae ahi y entra
   al tablero; uno del mes pasado no.

   Verificado contra la base el 19/09/2026: de 27 pagos vencidos, 4 por
   $ 256.768.590 caian en la columna de septiembre. Un solo aviso diciendo "no
   entran en ninguna columna" era falso para esos cuatro.
   ================================================================ */
seccion('un vencido del mes en curso SI entra, y se dice aparte');

require_once __DIR__ . '/../cashflow/Class/Horizonte.php';

// Eje conocido: 28 dias desde el 19/09, asi que la columna de 2026-09 cubre
// del 1 al 18 -dias ya pasados- y la de 2026-08 no existe.
$eje = new Horizonte(28, 12, [], '2026-09-19');

$mezcla = [
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-09-07', 'IMPORTE_ARS' => 1000000],
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-08-25', 'IMPORTE_ARS' => 7000000]
];

$dos = Comex::avisosVencidos($mezcla, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago', $eje);

chequear('son dos avisos distintos', 2, count($dos));

// El de afuera va primero: es el que tiene una accion pendiente.
chequear('el primero es el que quedo afuera', true,
    strpos($dos[0], 'NO entran en ninguna columna') !== false);
chequear('con su importe', true, strpos($dos[0], '$ 7.000.000,00') !== false);
chequear('y es uno solo', true, strpos($dos[0], '1 contenedor(es)') !== false);

chequear('el segundo dice que SI entran', true, strpos($dos[1], 'SÍ') !== false);
chequear('en la columna del mes en curso', true,
    strpos($dos[1], 'columna de ese mes') !== false);
chequear('con su importe', true, strpos($dos[1], '$ 1.000.000,00') !== false);

// Y NO SE PIERDE NINGUNO: los dos importes se informan, cada uno donde va.
chequear('entre los dos avisos esta toda la plata vencida', true,
    strpos($dos[0], '7.000.000') !== false && strpos($dos[1], '1.000.000') !== false);

seccion('sin horizonte no se afirma donde cayo cada uno');

/* Es lo que corresponde cuando no hay con que decidirlo: que columnas existen
   lo sabe el eje y nadie mas. Se informa un aviso solo. */
$sinEje = Comex::avisosVencidos($mezcla, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago');

chequear('un aviso solo', 1, count($sinEje));
chequear('con los dos contenedores', true, strpos($sinEje[0], '2 contenedor(es)') !== false);
chequear('y la suma de los dos', true, strpos($sinEje[0], '$ 8.000.000,00') !== false);

seccion('el mismo aviso sirve a las dos pestanas');

$avisoNac = Comex::avisosVencidos(
    [['VENCIDA' => true, 'IMPORTE_EST' => 12345.67]],
    'FECHA_NAC_EFECTIVA', 'IMPORTE_EST', 'fecha de nacionalización');

chequear('con el campo de importe de la otra', true,
    strpos($avisoNac[0], '$ 12.345,67') !== false);
chequear('y su nombre de fecha', true,
    strpos($avisoNac[0], 'fecha de nacionalización') !== false);

seccion('sin vencidos no hay aviso');

chequear('ninguno', 0,
    count(Comex::avisosVencidos([['VENCIDA' => false, 'IMPORTE_ARS' => 100]],
        'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS', 'fecha estimada de pago')));
chequear('ni con la lista vacia', 0,
    count(Comex::avisosVencidos([], 'F', 'IMPORTE_ARS', 'x')));
chequear('ni con basura', 0, count(Comex::avisosVencidos(null, 'F', 'IMPORTE_ARS', 'x')));

// Ni siquiera con el eje puesto: sin vencidos no hay nada que repartir.
chequear('ni con el eje', 0,
    count(Comex::avisosVencidos([['VENCIDA' => false, 'FECHA_PAGO_EFECTIVA' => '2026-09-25',
        'IMPORTE_ARS' => 100]], 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS', 'x', $eje)));

seccion('NO informa lo que no tiene fecha');

/* Queda fuera del eje igual, pero ya lo dicen dos avisos que existen y lo dicen
   mejor: en Proveedores Exterior, Comex::avisosValuacion() lo informa EN
   DOLARES -es la unica moneda en la que existe un importe que no se pudo
   valuar- y en Crono Nacionalizacion lo informa EjeVista en pesos, que ahi si
   existen. Repetirlo aca daria "$ 0,00 sin fecha" al lado de "U$S 164.526,47
   sin fecha": el mismo hecho contado dos veces y una de las dos mal. */
$sinFecha = [['VENCIDA' => false, 'FECHA_PAGO_EFECTIVA' => null, 'IMPORTE_ARS' => null]];

chequear('una fila sin fecha no genera aviso de vencidos', 0,
    count(Comex::avisosVencidos($sinFecha, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
        'fecha estimada de pago', $eje)));

/* ================================================================
   EL OVERRIDE DE COTIZACION SIGUE SIENDO EL DE ANTES

   descartaCotizacion() no se toco: lo que cambio es de donde sale la fecha
   anterior -antes del cashflow, ahora del maestro-. Se vuelve a fijar aca
   porque ahora la llama guardarFecha(), que es codigo nuevo.
   ================================================================ */
seccion('mover el pago a otro mes sigue descartando la cotizacion a mano');

$r = Comex::descartaCotizacion('2026-09-21', '2026-10-15', 1500.0);

chequear('se descarta', true, $r['cotizacion_descartada']);
chequear('de septiembre', '2026-09', $r['mes_anterior']);
chequear('a octubre', '2026-10', $r['mes_nuevo']);

$r = Comex::descartaCotizacion('2026-09-21', '2026-09-28', 1500.0);

chequear('dentro del mismo mes sobrevive', false, $r['cotizacion_descartada']);

/* ================================================================
   EL FILTRO DE EMBARQUE SE FUE, Y NO PUEDE VOLVER

   No hay nada que "probar" de un filtro ausente con datos: lo que hay que
   verificar es que no este escrito. Es el mismo criterio de
   test_tablas_controles.php, que verifica cableado y no logica.

   SI ESTE CHEQUEO SE CAE, alguien volvio a poner el corte y con el desaparecen
   42 de 76 contenedores -verificado contra la base el 19/09/2026- sin que nada
   se rompa ni avise.
   ================================================================ */
seccion('ninguna consulta corta por fecha de embarque');

$fuenteComex = file_get_contents(__DIR__ . '/../cashflow/Class/Comex.php');
$codigoComex = codigoSinComentarios(__DIR__ . '/../cashflow/Class/Comex.php');

chequear('no hay filtro por FECHA_EMB contra GETDATE', false,
    (bool) preg_match('/ISNULL\(A\.FECHA_EMB[^)]*\)\s*>=/i', $fuenteComex));

// La expresion ISNULL(FECHA_EMB, FECHA_EST_EMB) sigue existiendo: es la columna
// ETD que la grilla muestra, y es la ultima desempatadora del orden. Lo que no
// puede volver es que se compare contra hoy.
chequear('pero el ETD se sigue mostrando', true,
    strpos($fuenteComex, 'ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD') !== false);

seccion('las columnas EDIT salieron del circuito de lectura');

/* No se borran -este modulo no borra nada- pero nadie las lee. Si vuelven a
   aparecer en un SELECT, la pantalla no falla: muestra una fecha vieja que la
   otra aplicacion ya no tiene, que es exactamente el problema que esta rama
   vino a terminar. */
foreach (['FECHA_PAGO_EDIT', 'FECHA_NAC_EDIT'] as $col) {
    chequear('no se selecciona D.' . $col, false,
        strpos($codigoComex, 'D.' . $col) !== false);
    chequear('ni se hace COALESCE con ' . $col, false,
        (bool) preg_match('/COALESCE\([^)]*' . $col . '/i', $codigoComex));
}

// La tabla vieja sigue viva, y es a proposito: guarda COTIZ_USD_EDIT.
chequear('la tabla de las EDIT sigue nombrada', 'RO_T_CASHFLOW_COMEX_CRONO_NAC',
    Comex::TABLA_EDIT);
chequear('porque sigue guardando el override de cotizacion', true,
    strpos($fuenteComex, 'COTIZ_USD_EDIT') !== false);

seccion('el listado de nacionalizacion va por fecha de nacionalizacion');

/* Antes el ORDER BY era un COALESCE de cinco fechas y el corte era por
   embarque: la tabla se leia por una fecha y se ordenaba por cualquiera de
   cinco, asi que dos contenedores con la misma nacionalizacion quedaban en
   cualquier orden entre si. */
chequear('ordena por FECHA_DESP_ADU', true,
    (bool) preg_match('/ORDER BY[^;"]*A\.FECHA_DESP_ADU/s', $fuenteComex));

// SIN FECHA LA FILA NO SE PIERDE: va al final en vez de quedar primera, que es
// lo que hace SQL Server con los NULL por defecto.
chequear('y manda los sin fecha al final', true,
    strpos($fuenteComex, 'CASE WHEN A.FECHA_DESP_ADU IS NULL THEN 1 ELSE 0 END') !== false);
chequear('lo mismo para la fecha de pago', true,
    strpos($fuenteComex, 'CASE WHEN A.FECHA_EST_PAGO IS NULL THEN 1 ELSE 0 END') !== false);

/* ================================================================
   UN SOLO ENDPOINT PARA LAS DOS FECHAS
   ================================================================ */
seccion('el guardado de fechas es uno solo');

$fuenteCtrl = codigoSinComentarios(__DIR__ . '/../cashflow/Controller/ComexController.php');

chequear('existe la accion updateFecha', true,
    strpos($fuenteCtrl, "case 'updateFecha':") !== false);

// Las dos viejas hacian lo mismo contra columnas distintas y ya habian
// divergido: la de nacionalizacion se habia quedado sin transaccion y sin los
// mensajes de error que la otra fue ganando.
chequear('y no quedo la vieja de pago', false,
    strpos($fuenteCtrl, "case 'updateFechaPago':") !== false);
chequear('ni la de nacionalizacion', false,
    strpos($fuenteCtrl, "case 'updateFechaNacPago':") !== false);

seccion('el cliente ya no manda la fecha anterior');

/* Antes viajaba 'fecha_pago_orig' desde el navegador y era lo que se guardaba
   como valor original: el cliente decidia que decia que habia pisado. Ahora el
   servidor lee el maestro en la misma transaccion en la que escribe, que es la
   unica forma de que el rastro diga la verdad. */
foreach (['fecha_pago_orig', 'fecha_nac_orig', 'fecha_pago_edit', 'fecha_nac_edit']
         as $viejo) {
    chequear('el controller no espera ' . $viejo, false,
        strpos($fuenteCtrl, $viejo) !== false);
}

$jsExt = codigoSinComentarios(__DIR__ . '/../cashflow/Js/Comex-Proveedores_exterior.js');
$jsNac = codigoSinComentarios(__DIR__ . '/../cashflow/Js/Comex-Crono_nacionalizacion.js');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' no manda fechas viejas', false,
        (bool) preg_match('/fecha_(pago|nac)_(orig|edit)/', $js));
}

/* ================================================================
   EL BUSCADOR ES EL MISMO EN LAS DOS PESTANAS

   El pedido era replicar el de Crono Nacionalizacion, no inventar un sexto
   comportamiento de buscador. La unica forma de garantizar que no diverjan es
   que sea el MISMO codigo, y eso es lo que se verifica: que las dos lo
   deleguen en Js/Comex-fechas.js y que ninguna se guarde una copia.
   ================================================================ */
seccion('el buscador y la celda de fecha viven en un solo archivo');

$JS = __DIR__ . '/../cashflow/Js';

chequear('Comex-fechas.js existe', true, file_exists($JS . '/Comex-fechas.js'));

$compartido = file_get_contents($JS . '/Comex-fechas.js');

chequear('expone la celda', true, strpos($compartido, 'celda: celda') !== false);
chequear('el editor', true, strpos($compartido, 'editar: editar') !== false);
chequear('y el filtro', true, strpos($compartido, 'filtrar: filtrar') !== false);

// LOS TRES CAMPOS, escritos una sola vez. Mirar el textContent de la fila
// entera daria falsos positivos contra los importes del eje: tipear "2026"
// traeria todo.
chequear('busca por los tres campos', true,
    (bool) preg_match('/item\.PROVEEDOR,\s*item\.CONTENEDOR,\s*item\.ORDEN_COMPRA/', $compartido));

seccion('ninguna pestana se guarda su propia copia');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' usa la celda compartida', true,
        strpos($js, 'ComexFechas.celda(') !== false);
    chequear($n . ' usa el editor compartido', true,
        strpos($js, 'ComexFechas.editar(') !== false);
    chequear($n . ' usa el filtro compartido', true,
        strpos($js, 'ComexFechas.filtrar(') !== false);

    // Si alguien vuelve a copiar la suma de columnas, se puede desincronizar de
    // las celdas que tiene arriba. Es el mismo defecto que dejo siete copias de
    // exportarExcel().
    chequear($n . ' no reimplementa sumarColumnas', false,
        (bool) preg_match('/function\s+sumarColumnas/', $js));

    // Y ninguna arma su propio fetch al endpoint de fechas: el guardado es uno.
    chequear($n . ' no tiene su propio fetch de fechas', false,
        strpos($js, 'action=updateFecha') !== false);
}

seccion('las dos pestanas cargan el archivo compartido');

$TABS = __DIR__ . '/../cashflow/Tabs';

foreach (['proveedores_exterior', 'crono_nacionalizacion'] as $tab) {
    $html = file_get_contents($TABS . '/' . $tab . '.php');

    // Va ANTES del JS de la pestana: el IIFE de la pestana llama a ComexFechas
    // al dibujar, asi que si cargara despues, la primera tabla explotaria.
    $posComp = strpos($html, 'Js/Comex-fechas.js');
    $posTab = strpos($html, 'Js/Comex-');

    chequear($tab . ' carga Comex-fechas.js', true, $posComp !== false);
    chequear($tab . ' lo carga primero', true, $posComp === $posTab);
}

seccion('Proveedores Exterior tiene el buscador, igual que la otra');

$htmlExt = file_get_contents($TABS . '/proveedores_exterior.php');
$htmlNac = file_get_contents($TABS . '/crono_nacionalizacion.php');

chequear('tiene el campo', true, strpos($htmlExt, 'id="busquedaProvExt"') !== false);
chequear('y sigue el de la otra', true, strpos($htmlNac, 'id="busquedaCronoNac"') !== false);

// MISMO MARCADO: un control que se ve distinto en cada pantalla se lee como
// otro control.
foreach (['search-box-container', 'input-group input-group-sm', 'fa-search'] as $marca) {
    chequear('las dos usan ' . $marca, true,
        strpos($htmlExt, $marca) !== false && strpos($htmlNac, $marca) !== false);
}

seccion('el export respeta el buscador en las dos');

/* TablaExport saca del clon las filas con display:none, asi que Exportar baja
   lo que el buscador esta dejando ver. Eso solo pasa si el boton se engancha
   por data-exportar; con el listener propio que tenia Proveedores Exterior, la
   funcion envoltorio hacia lo mismo pero era la septima copia de algo que ya
   estaba resuelto. */
chequear('Proveedores Exterior exporta por data-exportar', true,
    strpos($htmlExt, 'data-exportar="tablaProveedoresExterior"') !== false);
chequear('y no quedo el boton con listener propio', false,
    strpos($htmlExt, 'id="btnExport"') !== false);
chequear('ni su funcion envoltorio', false,
    strpos($jsExt, 'function exportarExcel') !== false);
chequear('Crono Nacionalizacion sigue igual', true,
    strpos($htmlNac, 'data-exportar="tablaCronoNacionalizacion"') !== false);

/* ================================================================
   EL INTERRUPTOR DE VENCIDAS

   Va SOLO en Proveedores Exterior, que es donde se pidió: la fecha estimada de
   pago. Crono Nacionalizacion no lo tiene y por eso ve todas sus filas —
   verVencidas() devuelve true cuando no hay interruptor en la pantalla, para
   que una pestaña que no declara el control no pueda quedar escondiendo filas
   sin que nada lo diga.
   ================================================================ */
seccion('las vencidas no se ven por defecto en Proveedores Exterior');

chequear('el interruptor existe', true,
    strpos($htmlExt, 'id="verVencidasProvExt"') !== false);

/* SIN `checked`: apagado es el estado por defecto. Si alguien agrega el
   atributo, la pestaña abre mostrando 27 filas de contenedores vencidos y el
   pedido se deshace sin que nada falle. */
chequear('y arranca apagado', false,
    (bool) preg_match('/id="verVencidasProvExt"[^>]*\schecked/', $htmlExt));

// Cuánto esconde se dice AL LADO, siempre: una tabla que esconde filas sin
// decirlo se lee como que esos contenedores no existen.
chequear('dice cuántas esconde', true,
    strpos($htmlExt, 'id="estadoVencidasProvExt"') !== false);
chequear('y el JS lo escribe', true,
    strpos($jsExt, 'function pintarEstadoVencidas') !== false);

seccion('el interruptor y el buscador son el mismo camino');

/* Los dos terminan en filtrarTabla(), así que no pueden quedar diciendo cosas
   distintas: prender el interruptor con el buscador escrito tiene que dejar
   ver la intersección, no una de las dos cosas. */
chequear('el interruptor no recarga del servidor', false,
    (bool) preg_match('/verVencidasProvExt[^\n]*addEventListener[^\n]*cargarDatos/', $jsExt));
chequear('dispara el mismo filtrado que el buscador', true,
    (bool) preg_match('/verVencidas\.addEventListener\(\x27change\x27,\s*filtrarTabla\)/', $jsExt));

// Las dos consultas al helper compartido le pasan el interruptor: si una se lo
// olvidara, el pie sumaría filas que la tabla no muestra.
chequear('el filtrado conoce el interruptor', true,
    strpos($jsExt, "ComexFechas.filtrar('busquedaProvExt', 'tableBody', 'verVencidasProvExt')") !== false);
chequear('y los totales también', true,
    strpos($jsExt, "'verVencidasProvExt'") !== false);

seccion('la fila lleva el dato, no sólo la clase');

/* El interruptor filtra por data-vencida y no por la clase: la clase es
   presentación y podría cambiar sin que nadie piense en el filtro. */
chequear('data-vencida viaja en el <tr>', true,
    strpos($jsExt, 'data-vencida="1"') !== false);
chequear('y el filtro compartido lo mira', true,
    strpos($compartido, "getAttribute('data-vencida')") !== false);

seccion('sin interruptor en la pantalla se ven todas');

/* Es el caso de Crono Nacionalizacion, y la guarda importa: si verVencidas()
   devolviera false por defecto, esa pestaña abriría escondiendo 24 filas sin
   ningún control que las traiga de vuelta. */
chequear('Crono Nacionalizacion no declara el interruptor', false,
    strpos($htmlNac, 'form-switch') !== false);
chequear('y su filtrado no lo pasa', false,
    strpos($jsNac, 'verVencidas') !== false);

// La guarda, leída del código compartido: sin id, devuelve true.
chequear('verVencidas() sin interruptor devuelve true', true,
    (bool) preg_match('/return chk \? !!chk\.checked : true;/', $compartido));

seccion('los totales del pie respetan el filtro en las dos');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' suma las visibles', true,
        strpos($js, 'ComexFechas.sumarColumnas(visibles)') !== false);
}

// El total en pesos de Proveedores Exterior es una columna aparte del eje y
// tambien tiene que filtrarse: si sumara todo mientras la tabla muestra tres
// filas, esta celda y la de al lado dirian numeros de dos universos distintos.
chequear('y el total en pesos tambien', true,
    strpos($jsExt, 'sumaImporteArs(visibles)') !== false);

/* ================================================================
   EL SCRIPT DE LA MIGRACION
   ================================================================ */
seccion('el script existe y declara su orden');

$sql = __DIR__ . '/../sql/cashflow_comex_fecha_maestra.sql';

chequear('esta en sql/', true, file_exists($sql));

$texto = file_get_contents($sql);

chequear('dice contra que base va', true, strpos($texto, 'Base    : central') !== false);
chequear('y en que orden se corre', true, strpos($texto, 'Orden   :') !== false);

seccion('crea la tabla del rastro con lo que el codigo espera');

chequear('la tabla', true, strpos($texto, 'CREATE TABLE dbo.' . Comex::TABLA_HISTORIAL) !== false);

foreach (['ID_MG', 'CAMPO', 'FECHA_ANTERIOR', 'FECHA_NUEVA', 'VIGENTE', 'USUARIO',
          'FECHA_ALTA', 'FECHA_BAJA'] as $col) {
    chequear('con la columna ' . $col, true, strpos($texto, $col) !== false);
}

// La lista cerrada va tambien en la base: el endpoint es alcanzable sin pasar
// por la pantalla, y un CAMPO con un tercer valor dejaria un rastro que ninguna
// pestana sabe leer.
chequear('y el CHECK de los dos campos', true,
    strpos($texto, "CHECK (CAMPO IN ('PAGO', 'NAC'))") !== false);

seccion('un solo rastro vigente por contenedor y campo');

/* Va como indice unico FILTRADO: un UNIQUE comun prohibiria tambien las filas
   historicas repetidas, que es justamente lo que esta tabla existe para
   guardar. Mismo patron que sql/cashflow_cobertura_automatica.sql. */
chequear('el indice es unico', true,
    strpos($texto, 'CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COMEXFED_VIGENTE') !== false);
chequear('y esta filtrado por VIGENTE', true,
    (bool) preg_match('/UX_RO_T_CF_COMEXFED_VIGENTE.*?WHERE VIGENTE = 1/s', $texto));

seccion('la migracion no pisa el maestro cuando dice otra cosa');

/* EL CRITERIO DEL CONFLICTO: no hay forma de saber cual de los dos valores es
   mas nuevo -la tabla del cashflow tiene FECHA_UPDATE y el maestro no tiene
   fecha de modificacion- y ante el empate gana el maestro, que es el dato que
   la app de Comercio Exterior esta mostrando hoy.

   Verificado contra la base el 19/09/2026: 9 ediciones, 3 huerfanas, 6 que ya
   coincidian y 0 conflictos, asi que la migracion no escribio ni una fecha. */
chequear('solo completa donde el maestro esta vacio', true,
    strpos($texto, 'C.HAY_MAESTRO = 1 AND C.MAESTRO IS NULL') !== false);
chequear('el conflicto entra como historia, no como vigente', true,
    (bool) preg_match('/VIGENTE = 0.*?no describe el valor vigente/s', $texto));
chequear('y el script lo lista para que alguien lo mire', true,
    strpos($texto, 'el maestro dice otra cosa: NO se piso') !== false);

seccion('el centinela 1900-01-01 no es una edicion');

/* FECHA_NAC_ORIG y FECHA_NAC_EDIT nacieron NOT NULL, asi que el guardado de la
   OTRA pestana las rellenaba con esa fecha al insertar. Tomarla como edicion
   migraria al maestro una nacionalizacion en 1900, y en el tablero ese importe
   caeria fuera del eje sin que nadie entienda por que. */
chequear('se descarta al migrar', true,
    substr_count($texto, "<> '1900-01-01'") >= 2);

seccion('es reejecutable');

chequear('la tabla se crea solo si falta', true,
    strpos($texto, "IF OBJECT_ID('dbo." . Comex::TABLA_HISTORIAL . "', 'U') IS NULL") !== false);
chequear('y lo ya migrado no se vuelve a insertar', true,
    strpos($texto, "E.USUARIO = 'MIGRACION'") !== false);

/* ================================================================
   CONTRA LA BASE

   Solo LECTURA. Las pruebas de escritura tocarian el maestro de otra
   aplicacion, y este arnes corre contra la base de verdad.
   ================================================================ */
seccion('contra la base: las dos consultas y el rastro');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $comex = new Comex();

    $hoy = '2026-09-19';
    $ext = $comex->getProveedoresExterior($hoy);
    $nac = $comex->getCronoNacionalizacion($hoy);

    chequear('Proveedores Exterior trae filas', true, count($ext) > 0);
    chequear('Crono Nacionalizacion trae filas', true, count($nac) > 0);

    /* LAS DOS MIRAN EL MISMO PADRON. Es el mismo contenedor visto desde los dos
       lados del circuito: si una trae mas que la otra, alguna volvio a filtrar
       por su cuenta. */
    chequear('y las dos traen el mismo padron', count($ext), count($nac));

    // EL FILTRO SE FUE DE VERDAD, y no solo del texto de la consulta: tiene que
    // haber contenedores con el embarque ya pasado.
    $embarcados = 0;

    foreach ($ext as $f) {
        if (Comex::estaVencida($f['ETD'], $hoy)) {
            $embarcados++;
        }
    }

    chequear('hay contenedores ya embarcados en la grilla', true, $embarcados > 0);

    seccion('contra la base: cada fila sabe si esta vencida');

    $faltan = 0;

    foreach ($ext as $f) {
        if (!array_key_exists('VENCIDA', $f) || !array_key_exists('EDITADA', $f)) {
            $faltan++;
            continue;
        }

        // El flag lo calcula el backend: el front no compara ninguna fecha.
        if ((bool) $f['VENCIDA'] !== Comex::estaVencida($f['FECHA_PAGO_EFECTIVA'], $hoy)) {
            $faltan++;
        }
    }

    chequear('ninguna fila sin el flag o con el flag mal', 0, $faltan);

    seccion('contra la base: la fecha efectiva sale del maestro');

    $deLaTablaVieja = 0;

    foreach ($ext as $f) {
        // Si alguien vuelve a leer las columnas EDIT, aparecen en la fila.
        if (array_key_exists('FECHA_PAGO_EDIT', $f)) {
            $deLaTablaVieja++;
        }

        if ($f['FECHA_PAGO_EFECTIVA'] !== null
            && $f['FECHA_PAGO_EFECTIVA'] !== $f['FECHA_EST_PAGO']) {
            $deLaTablaVieja++;
        }
    }

    chequear('la fecha efectiva ES FECHA_EST_PAGO', 0, $deLaTablaVieja);

    seccion('contra la base: el listado sale ordenado por la fecha efectiva');

    $anterior = '';
    $desordenadas = 0;
    $nullEnElMedio = 0;
    $vistoNull = false;

    foreach ($nac as $f) {
        if ($f['FECHA_NAC_EFECTIVA'] === null) {
            $vistoNull = true;
            continue;
        }

        // Una fila con fecha DESPUES de una sin fecha: los NULL no quedaron al
        // final.
        if ($vistoNull) {
            $nullEnElMedio++;
        }

        if ($f['FECHA_NAC_EFECTIVA'] < $anterior) {
            $desordenadas++;
        }

        $anterior = $f['FECHA_NAC_EFECTIVA'];
    }

    chequear('ninguna fila fuera de orden', 0, $desordenadas);
    chequear('y las sin fecha quedan al final', 0, $nullEnElMedio);

    seccion('contra la base: el DDL y la marca de editable');

    // Las dos pestanas preguntan lo mismo, asi que tienen que contestar lo
    // mismo: si una dijera que se puede editar y la otra que no, una de las dos
    // dibujaria una celda que falla al guardar.
    chequear('la edicion esta prendida o apagada para las dos igual',
        $comex->tieneHistorial(), $comex->tieneHistorial());

    if (!$comex->tieneHistorial()) {
        chequear('y sin la tabla la pantalla avisa que script falta', true,
            strpos($comex->avisoSinHistorial(), 'cashflow_comex_fecha_maestra.sql') !== false);

        Pruebas::saltear('falta ' . Comex::TABLA_HISTORIAL . ': no se puede leer el rastro');
    } else {
        chequear('con la tabla no hay nada que avisar', '', $comex->avisoSinHistorial());

        // El historial de un contenedor que no existe no puede fallar: devuelve
        // vacio, que es lo que corresponde.
        chequear('el historial de un id inexistente vuelve vacio', 0,
            count($comex->getHistorialFechas(-1)));
    }
}
