<?php
/**
 * Proveedores Locales: cuentas a pagar, categorias, importacion y conciliacion.
 *
 * Lo que se prueba sin base es lo que decide QUE NUMERO sale y DONDE cae: la
 * jerarquia de la fecha de pago, la interpretacion del plazo, el diff de las dos
 * importaciones y el desvio de la conciliacion. Todo eso es texto de una
 * planilla y reglas puras, asi que se verifica sin base y sin archivos.
 *
 * La parte que toca la base se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../cashflow/Class/Proveedores.php';

/* ================================================================
   LA JERARQUIA DE LA FECHA DE PAGO

   fecha cargada -> vencimiento -> emision + plazo -> sin fecha.
   Es la regla mas facil de romper del modulo.
   ================================================================ */
seccion('la fecha cargada manda y no se reubica');

$hoy = '2026-09-15';

$r = Proveedores::resolverFechaPago('2026-10-20', '2026-08-01', '2026-07-01', 30, $hoy);

chequear('gana sobre el vencimiento', '2026-10-20', $r['fecha']);
chequear('y lo dice', 'CARGADA', $r['origen']);
chequear('no esta vencida', false, $r['vencida']);
chequear('ni cuenta como pendiente de fechar', false, $r['sin_fecha_cargada']);

// La cargo una persona: moverla a hoy seria pisar su decision con una regla
// automatica y mostrarle su propia carga en otra columna.
$r = Proveedores::resolverFechaPago('2026-08-10', '2026-08-01', '2026-07-01', 30, $hoy);

chequear('una fecha cargada vencida se muestra DONDE ESTA', '2026-08-10', $r['fecha']);
chequear('se marca vencida, que es un hecho', true, $r['vencida']);
chequear('pero NO cuenta como pendiente de fechar: alguien ya decidio',
    false, $r['sin_fecha_cargada']);

seccion('sin fecha cargada manda el vencimiento');

$r = Proveedores::resolverFechaPago(null, '2026-10-05', '2026-09-01', 30, $hoy);

chequear('se proyecta al vencimiento', '2026-10-05', $r['fecha']);
chequear('y lo dice', 'VENCIMIENTO', $r['origen']);

// EL VENCIMIENTO VA ANTES QUE EL PLAZO. El vencimiento es un dato de ESTA
// factura; el plazo es una costumbre del proveedor.
$r = Proveedores::resolverFechaPago(null, '2026-10-05', '2026-09-01', 7, $hoy);

chequear('el plazo del maestro NO le gana al vencimiento', '2026-10-05', $r['fecha']);

seccion('un vencimiento pasado se ubica en el primer dia del eje, y se marca');

$r = Proveedores::resolverFechaPago(null, '2023-08-03', '2023-07-01', null, $hoy);

chequear('se dibuja hoy porque no hay otro lugar', $hoy, $r['fecha']);
chequear('pero conserva cual era su fecha', '2023-08-03', $r['original']);
chequear('queda marcada vencida', true, $r['vencida']);

// ESTA MARCA ES LA QUE ALIMENTA EL INDICADOR de la pestana. Sin ella el tablero
// mostraria ochocientos millones cayendo hoy como si estuviera decidido pagarlos
// hoy.
chequear('y cuenta como pendiente de fechar', true, $r['sin_fecha_cargada']);

// NO HAY TECHO DE ANTIGUEDAD, a diferencia de cobranzas: una factura vieja sin
// COBRAR puede ser incobrable, una vieja sin PAGAR sigue siendo deuda.
$r = Proveedores::resolverFechaPago(null, '2019-01-01', '2018-12-01', null, $hoy);

chequear('ni siquiera una de hace siete anios se descarta', $hoy, $r['fecha']);
chequear('conservando su fecha', '2019-01-01', $r['original']);

seccion('sin vencimiento usable cae al plazo del maestro');

// Tango usa 1800-01-01 como centinela de "sin fecha". Hoy no hay ninguno en
// CPA54, pero la consulta de referencia lo contempla, asi que el codigo tambien.
$r = Proveedores::resolverFechaPago(null, '1800-01-01', '2026-09-01', 30, $hoy);

chequear('el centinela de Tango no es una fecha', '2026-10-01', $r['fecha']);
chequear('y se proyecta con el plazo', 'PLAZO', $r['origen']);

// CONTADO son CERO dias, no "sin plazo": con emision futura se ve directo.
$r = Proveedores::resolverFechaPago(null, null, '2026-09-20', 0, $hoy);

chequear('CONTADO paga el dia de la emision', '2026-09-20', $r['fecha']);
chequear('y sale del plazo', 'PLAZO', $r['origen']);

// Con emision pasada, el resultado tambien es pasado y se reubica en hoy como
// cualquier vencido. Lo que importa es que el plazo CERO se USO -si se hubiera
// tratado como null, no habria fecha y el origen seria SIN_FECHA-.
$r = Proveedores::resolverFechaPago(null, null, '2026-09-10', 0, $hoy);

chequear('una emision pasada con CONTADO se ubica hoy', $hoy, $r['fecha']);
chequear('pero conserva la fecha que calculo', '2026-09-10', $r['original']);
chequear('y el plazo se uso: cero no es null', 'PLAZO', $r['origen']);

seccion('sin nada con que ubicarlo, sin fecha');

$r = Proveedores::resolverFechaPago(null, null, '2026-09-01', null, $hoy);

chequear('no se inventa una fecha', null, $r['fecha']);
chequear('y lo dice', 'SIN_FECHA', $r['origen']);
chequear('cuenta como pendiente de fechar', true, $r['sin_fecha_cargada']);

// Sin emision tampoco se puede, aunque haya plazo.
$r = Proveedores::resolverFechaPago(null, null, null, 30, $hoy);

chequear('sin emision el plazo no alcanza', null, $r['fecha']);

seccion('el centinela de Tango no es una fecha');

chequear('1800-01-01 se descarta', null, Proveedores::fechaUtil('1800-01-01'));
chequear('y 1900-01-01 tambien', null, Proveedores::fechaUtil('1900-01-01'));
chequear('una fecha real pasa', '2026-09-15', Proveedores::fechaUtil('2026-09-15'));
chequear('null sigue siendo null', null, Proveedores::fechaUtil(null));

/* ================================================================
   EL PLAZO DEL MAESTRO NO ES UN NUMERO

   En la planilla los valores son CONTADO, 7 DIAS, 30 DIAS, DEBITO, y esta
   vacio en 765 de 1.223 filas.
   ================================================================ */
seccion('interpretacion del plazo de pago');

chequear('CONTADO son cero dias', 0, ProveedoresCategorias::plazoEnDias('CONTADO'));
chequear('en minuscula tambien', 0, ProveedoresCategorias::plazoEnDias('contado'));
chequear('30 DIAS son 30', 30, ProveedoresCategorias::plazoEnDias('30 DIAS'));
chequear('sin espacio tambien', 15, ProveedoresCategorias::plazoEnDias('15DIAS'));
chequear('con acento tambien', 7, ProveedoresCategorias::plazoEnDias('7 DÍAS'));
chequear('un numero pelado', 60, ProveedoresCategorias::plazoEnDias('60'));

// DEVOLVER null NO ES LO MISMO QUE DEVOLVER 0. Cero es "se paga hoy"; null es
// "este plazo no dice cuando" y hace caer al escalon siguiente.
chequear('DEBITO no dice cuando: null, no cero',
    null, ProveedoresCategorias::plazoEnDias('DEBITO'));
chequear('DEBITO AUTOMATICO tampoco',
    null, ProveedoresCategorias::plazoEnDias('DEBITO AUTOMATICO'));
chequear('vacio es null', null, ProveedoresCategorias::plazoEnDias(''));
chequear('null es null', null, ProveedoresCategorias::plazoEnDias(null));
chequear('un texto cualquiera es null', null, ProveedoresCategorias::plazoEnDias('a convenir'));

/* ================================================================
   LA PLANILLA VIENE SUCIA Y ESO SE MUESTRA, NO SE ARREGLA
   ================================================================ */
seccion('normalizacion de la forma de pago');

$f = ProveedoresCategorias::normalizarFormaPago('TRANSFERENCIA');
chequear('un valor limpio matchea', 'TRANSFERENCIA', $f['normalizado']);

// El caso real de la planilla: 'echeq' en minuscula.
$f = ProveedoresCategorias::normalizarFormaPago('echeq');
chequear('en minuscula tambien', 'ECHEQ', $f['normalizado']);
chequear('y conserva el original', 'echeq', $f['original']);

// Un valor desconocido NO se descarta en silencio ni se arregla: vuelve con el
// normalizado en null y su original, para poder mostrarlo.
$f = ProveedoresCategorias::normalizarFormaPago('eqheck');
chequear('un typo no matchea', null, $f['normalizado']);
chequear('pero no se pierde', 'eqheck', $f['original']);

$f = ProveedoresCategorias::normalizarFormaPago('');
chequear('vacio no es un typo', null, $f['normalizado']);
chequear('ni tiene original', '', $f['original']);

seccion('el typo del criterio de distribucion se detecta sin lista declarada');

/* No hay una lista de criterios validos y no se inventa una: son texto que
   escribe administracion. Lo que SI se puede afirmar es que un valor que
   aparece dos veces y se parece mucho a otro que aparece doscientas es
   sospechoso. Es el caso real de '50% ECOMMERC'. */
$sosp = ProveedoresCategorias::criteriosSospechosos([
    '50% ECOMMERCE / 50% VENTAS' => 200,
    '50% ECOMMERC / 50% VENTAS' => 2,
    '100% VENTAS' => 400,
    '100% LOCALES' => 150
]);

chequear('detecta uno solo', 1, count($sosp));
chequear('y es el typo', '50% ECOMMERC / 50% VENTAS', $sosp[0]['criterio']);
chequear('diciendo a que se parece', '50% ECOMMERCE / 50% VENTAS', $sosp[0]['parecido_a']);
chequear('y cuantas veces aparece el bueno', 200, $sosp[0]['veces_parecido']);

// UN CRITERIO POCO USADO NO ES UN TYPO. Sin algo parecido y mas frecuente, no
// hay nada que afirmar, y avisar de todos los raros seria ruido.
$sosp = ProveedoresCategorias::criteriosSospechosos([
    '100% VENTAS' => 400,
    '100% LOCALES' => 150,
    '33% CADA CANAL' => 1
]);

chequear('un criterio raro pero distinto no se marca', 0, count($sosp));

seccion('las formas de pago son las de la planilla, no las que parecen razonables');

/* La primera version de esta lista se escribio a ojo y ninguno de los cinco
   valores inventados existe en el maestro real; los que si existian faltaban.
   Resultado: 639 de 1.173 proveedores -el 54%- quedaron sin normalizar. */
chequear('son las seis de la planilla',
    ['TRANSFERENCIA', 'ECHEQ', 'CAJA', 'TARJETA CORP', 'DEBITO', 'MERCADO PAGO'],
    ProveedoresCategorias::FORMAS_PAGO);

chequear('CAJA esta', 'CAJA', ProveedoresCategorias::normalizarFormaPago('CAJA')['normalizado']);
chequear('TARJETA CORP tambien, con espacio', 'TARJETA CORP',
    ProveedoresCategorias::normalizarFormaPago('tarjeta corp')['normalizado']);
chequear('y sin el espacio', 'TARJETA CORP',
    ProveedoresCategorias::normalizarFormaPago('TARJETACORP')['normalizado']);
chequear('MERCADO PAGO tambien', 'MERCADO PAGO',
    ProveedoresCategorias::normalizarFormaPago('Mercado Pago')['normalizado']);

// Y los que se habian inventado ya no estan: no existen en la planilla.
chequear('CHEQUE no es una forma declarada', null,
    ProveedoresCategorias::normalizarFormaPago('CHEQUE')['normalizado']);
chequear('EFECTIVO tampoco', null,
    ProveedoresCategorias::normalizarFormaPago('EFECTIVO')['normalizado']);

seccion('que se gestiona desde el cronograma de pagos');

/* Entra lo que se paga DECIDIENDO CUANDO. Un debito automatico se debita solo y
   la caja se paga en el mostrador: no se planifican de la misma manera. */
chequear('un echeq entra', true, ProveedoresCategorias::esDelCronograma('ECHEQ'));
chequear('una transferencia tambien', true,
    ProveedoresCategorias::esDelCronograma('TRANSFERENCIA'));

chequear('un debito automatico NO', false, ProveedoresCategorias::esDelCronograma('DEBITO'));
chequear('la caja tampoco', false, ProveedoresCategorias::esDelCronograma('CAJA'));
chequear('ni la tarjeta corporativa', false,
    ProveedoresCategorias::esDelCronograma('TARJETA CORP'));
chequear('ni mercado pago', false, ProveedoresCategorias::esDelCronograma('MERCADO PAGO'));

/* LO QUE NO SE SABE, ENTRA. Una forma en null no es una forma que quedo afuera
   del criterio: es un dato que falta. Esconder deuda por un dato que falta es
   la peor razon para esconderla, y ademas garantiza que nadie lo complete
   nunca, porque deja de verse. */
chequear('sin forma conocida ENTRA, y se marca en la grilla',
    true, ProveedoresCategorias::esDelCronograma(null));
chequear('una forma vacia tambien', true, ProveedoresCategorias::esDelCronograma(''));

// Una forma que la planilla trajo mal escrita llega como null a este metodo
// -normalizarFormaPago no la reconocio- asi que entra por la misma razon.
$forma = ProveedoresCategorias::normalizarFormaPago('eqheck');
chequear('y una forma no reconocida tambien', true,
    ProveedoresCategorias::esDelCronograma($forma['normalizado']));

chequear('las dos formas del cronograma estan declaradas',
    ['ECHEQ', 'TRANSFERENCIA'], ProveedoresCategorias::FORMAS_CRONOGRAMA);

seccion('lo que no se manda a savePago no se pisa');

/* EL BUG QUE ESTO FIJA: la grilla edita UNA celda -la fecha- y manda solo esa.
   guardarPago escribia igual FORMA_PAGO y OBSERVACION, asi que cargar una fecha
   borraba la forma y la observacion que habia dejado la importacion de la
   planilla. Un endpoint que recibe un campo y escribe cuatro no guarda una
   edicion: reemplaza la fila.

   No hace falta base para verificarlo: se mira que el UPDATE que arma no
   nombre las columnas que el llamador no trajo. */
$m = new ReflectionMethod('Proveedores', 'guardarPago');
$params = [];

foreach ($m->getParameters() as $p) { $params[] = $p->getName(); }

chequear('guardarPago sabe que campos tocar', true,
    in_array('tocarForma', $params, true) && in_array('tocarObs', $params, true));
chequear('y por defecto los toca: la importacion los trae siempre', true,
    $m->getParameters()[10]->getDefaultValue() === true
    && $m->getParameters()[11]->getDefaultValue() === true);

// savePago es quien decide: si el campo no vino, no entra al UPDATE.
$rs = new ReflectionMethod('Proveedores', 'savePago');
$cuerpo = implode('', array_slice(file(__DIR__ . '/../cashflow/Class/Proveedores.php'),
    $rs->getStartLine() - 1, $rs->getEndLine() - $rs->getStartLine() + 1));

chequear('savePago pasa false cuando no vino la forma', true,
    strpos($cuerpo, "\$forma['original'] !== ''") !== false);
chequear('y cuando no vino la observacion', true,
    strpos($cuerpo, "\$obs !== ''") !== false);

seccion('el filtro mira el maestro, no la fila de pago');

/* LA REGLA: el criterio es una propiedad del PROVEEDOR -a este se le paga por
   transferencia, a aquel por caja-, no de un comprobante suelto. Si lo
   decidiera la fila de pago, cargar una fecha desde la grilla cambiaria de
   serie la deuda, porque la grilla manda la fecha y nada mas. */
$fuente = file_get_contents(__DIR__ . '/../cashflow/Class/Proveedores.php');

chequear('CRONOGRAMA se calcula sobre la forma del maestro', true,
    strpos($fuente, "'CRONOGRAMA' => ProveedoresCategorias::esDelCronograma(\$cat['forma_pago'])")
    !== false);

// Y las dos formas viajan por separado: una decide, la otra se muestra.
chequear('la forma del maestro viaja aparte', true,
    strpos($fuente, "'FORMA_PAGO_MAESTRO' => \$cat['forma_pago']") !== false);

/* LA TABLA DE SIGNOS NO SE TOCA. Al sacar la columna CRE_DEB del SELECT quedo
   un solo uso de CPA21 en la consulta, y es el que importa: sin el, una nota de
   debito imputada se resta como si fuera un pago y el pendiente da NEGATIVO.
   Esta escrito aca para que un "limpiemos los joins de CPA21" no se lo lleve. */
chequear('la tabla de signos de las imputaciones sigue en su lugar', true,
    strpos($fuente, "ELSE CASE tc.CRE_DEB") !== false
    && strpos($fuente, "LEFT JOIN CPA21 tc ON tc.T_COMP = i.T_COMP_CAN") !== false);

/* Un proveedor de CAJA al que le cargaron una fecha sigue estando FUERA del
   cronograma: la fila de pago no cambia el criterio. */
chequear('un proveedor de CAJA no entra aunque tenga pago cargado',
    false, ProveedoresCategorias::esDelCronograma('CAJA'));

/* Y uno de ECHEQ sigue adentro aunque el pago se haya registrado por otra via:
   que las dos difieran es informacion, no un motivo para recategorizar. */
chequear('y uno de ECHEQ sigue adentro',
    true, ProveedoresCategorias::esDelCronograma('ECHEQ'));

seccion('la forma que se muestra sale de la misma fuente que su original');

/* EL BUG QUE ESTO FIJA: categoria() no devolvia el FORMA_PAGO_ORIG del maestro,
   asi que un proveedor cuyo maestro dice TARJETA CORP se dibujaba "sin forma"
   en gris. La rama naranja del JS -que existe para exactamente este caso- no se
   ejecutaba nunca. */
$f = Proveedores::formaQueSeMuestra(
    ['forma_pago' => null, 'forma_pago_orig' => 'eqheck'], null);

chequear('sin pago cargado se muestra lo que dice el maestro', 'eqheck', $f['original']);
chequear('sin normalizar, porque no matchea contra nada', null, $f['normalizado']);

// El pago registrado manda sobre el maestro EN LA COLUMNA -es un hecho sobre
// este comprobante- pero no sobre el filtro, que ya se verifico arriba.
$f = Proveedores::formaQueSeMuestra(
    ['forma_pago' => 'ECHEQ', 'forma_pago_orig' => 'echeq'],
    ['FORMA_PAGO' => 'TRANSFERENCIA', 'FORMA_PAGO_ORIG' => null]);

chequear('con pago cargado manda el del pago', 'TRANSFERENCIA', $f['normalizado']);

/* EL ORIGINAL SALE DE LA MISMA FUENTE QUE EL NORMALIZADO: si se mezclaran, esta
   fila mostraria 'TRANSFERENCIA' con el original 'echeq' del maestro al lado. */
chequear('y el original es el de ese mismo valor, no el del maestro',
    null, $f['original']);

// Un pago que no dice la forma no borra lo que el maestro si sabe.
$f = Proveedores::formaQueSeMuestra(
    ['forma_pago' => 'ECHEQ', 'forma_pago_orig' => 'echeq'],
    ['FORMA_PAGO' => null, 'FORMA_PAGO_ORIG' => null]);

chequear('un pago sin forma no tapa la del maestro', 'ECHEQ', $f['normalizado']);

// Pero un pago que trajo un valor que no matcheo SI conserva su original: es lo
// que hay que mostrar para poder corregirlo.
$f = Proveedores::formaQueSeMuestra(
    ['forma_pago' => null, 'forma_pago_orig' => null],
    ['FORMA_PAGO' => null, 'FORMA_PAGO_ORIG' => 'transfer.']);

chequear('y el valor raro de la planilla de pagos no se pierde',
    'transfer.', $f['original']);

// Un proveedor que no esta en el maestro no tiene ni una ni otra.
$f = Proveedores::formaQueSeMuestra(
    ['forma_pago' => null, 'forma_pago_orig' => null], null);

chequear('sin maestro y sin pago no hay nada que mostrar', null, $f['original']);

seccion('la forma de pago se normaliza al LEER, no al importar');

/* EL PROBLEMA QUE ESTO RESUELVE: FORMA_PAGO es un valor DERIVADO -sale de pasar
   el original por FORMAS_PAGO, que vive en el codigo-. Calcularlo al importar lo
   congelaba contra la lista de ese dia: agregar una forma nueva no arreglaba
   ninguna de las filas ya cargadas y obligaba a REIMPORTAR el maestro entero.

   Y reimportar no es recalcular: hace el diff completo, necesita el Excel
   vigente -si no es el mismo, aplica cambios que nadie pidio-, propone bajas y
   escribe historial. Era correr una operacion de DATOS, con efectos
   colaterales, para arreglar la consecuencia de un cambio de CODIGO. */
chequear('una forma que hoy esta declarada se reconoce aunque se haya guardado en null',
    'TARJETA CORP', ProveedoresCategorias::formaVigente('TARJETA CORP', null));
chequear('y en minuscula tambien', 'CAJA', ProveedoresCategorias::formaVigente('caja', null));

// Lo que sigue sin matchear, sigue sin matchear: recalcular no inventa nada.
chequear('un typo sigue sin normalizar', null,
    ProveedoresCategorias::formaVigente('eqheck', null));

/* RECALCULAR NUNCA PUEDE BORRAR UN DATO. Si una fila tuviera la normalizada sin
   su original -hoy no hay ninguna, pero una correccion a mano sobre la base
   podria dejarla asi- se respeta lo que este guardado en lugar de perderlo. */
chequear('sin original se respeta lo guardado', 'ECHEQ',
    ProveedoresCategorias::formaVigente(null, 'ECHEQ'));
chequear('y un original vacio es lo mismo que no tenerlo', 'ECHEQ',
    ProveedoresCategorias::formaVigente('   ', 'ECHEQ'));
chequear('sin ninguna de las dos, null', null,
    ProveedoresCategorias::formaVigente(null, null));

seccion('renormalizar al leer no ensucia el diff de la importacion');

/* EL RIESGO DEL CAMBIO: si las lecturas renormalizan y el diff comparara contra
   la columna cruda, el proximo preview mostraria como CAMBIO las 639 filas que
   en realidad ya quedaron bien. No pasa porque el diff compara contra mapa(),
   que es justamente lo que se renormaliza. */
$existenteViejo = ['MTDODI' => [
    'COD_PROVEE' => 'MTDODI', 'NOMBRE' => 'N MTDODI',
    'RUBRO_ECONOMICO' => 'Mercaderia', 'RUBRO' => null, 'CENTRO_COSTOS' => null,
    // Como lo devuelve mapa() para una fila importada con la lista vieja:
    // guardada en null, pero reconocida al leer.
    'FORMA_PAGO' => ProveedoresCategorias::formaVigente('TARJETA CORP', null),
    'FORMA_PAGO_ORIG' => 'TARJETA CORP',
    'PLAZO_PAGO' => '30 DIAS', 'CRITERIO_DISTRIB' => null
]];

$c = ProveedoresCategorias::compararImportacion([[
    'linea' => 2, 'cod_provee' => 'MTDODI', 'nombre' => 'N MTDODI',
    'rubro_economico' => 'Mercaderia', 'rubro' => '', 'centro_costos' => '',
    'forma_pago' => 'TARJETA CORP', 'plazo_pago' => '30 DIAS', 'criterio_distrib' => ''
]], $existenteViejo);

chequear('la misma planilla no propone ningun cambio falso',
    'SIN_CAMBIOS', $c['filas'][0]['estado']);
chequear('ni cuenta la fila como forma desconocida', 0,
    $c['resumen']['forma_desconocida']);

seccion('los indicadores miden lo que la grilla muestra');

/* EL BUG QUE ESTO FIJA: indicadores() se calculaba sobre TODOS los items y la
   grilla sobre las filas visibles. Con el filtro por forma de pago prendido -que
   es el default- la tarjeta decia 549 vencimientos arriba de una tabla que
   mostraba 294, y ni el buscador ni el interruptor de vencidos la movian.

   Se verifica sobre el JS porque los tres filtros son del navegador: solo ahi se
   sabe que filas se estan viendo. Es el mismo lugar donde ya se calculaba el pie
   de TOTALES. */
$js = file_get_contents(__DIR__ . '/../cashflow/Js/Proveedores-Proveedores_locales.js');

chequear('los indicadores se pintan desde pintarGrilla, con las filas visibles', true,
    strpos($js, 'pintarIndicadores(filas);') !== false);

// Y NO desde cargar(): ahi solo se pintarian una vez y no se moverian con los
// filtros, que es exactamente el bug.
chequear('y no una sola vez al cargar', false, strpos($js, 'pintarIndicadores();') !== false);

chequear('se suman sobre las filas que se le pasan', true,
    strpos($js, 'function calcularIndicadores(filas)') !== false);

/* LO QUE EL FILTRO ESCONDE NO SE PIERDE: cuando lo visible difiere del universo,
   el pie de la tarjeta dice el total. Misma regla que el cartel del periodo. */
chequear('y cuando difieren del universo se dice cuanto es el universo', true,
    strpos($js, 'function deTotal(') !== false);

/* La tarjeta roja se apaga en verde por el UNIVERSO y no por lo visible:
   apagarla porque el filtro escondio lo que falta fechar diria que no hay
   trabajo por hacer justo cuando lo hay. */
chequear('la tarjeta de vencidos se apaga por el universo', true,
    strpos($js, "toggle('prov-kpi-ok', !u.n_vencido_sin_fecha)") !== false);

seccion('el archivo exportado dice que filtro estaba puesto');

/* Bajar lo que se ve esta bien -es la tabla que el usuario mira- pero sin rastro
   del recorte, dentro de una semana nadie sabe si el archivo trae todo o una
   parte. Y aca el caso NORMAL es el recortado: el filtro viene prendido. */
chequear('el nombre se arma con los filtros', true,
    strpos($js, 'function nombreExport()') !== false);
chequear('y no es una constante', false,
    strpos($js, "exportarTabla('tablaProveedores', 'Cuentas_a_Pagar')") !== false);

seccion('el rubro Excluidos');

chequear('lo detecta', true, ProveedoresCategorias::esExcluido('Excluidos'));
chequear('en mayusculas tambien', true, ProveedoresCategorias::esExcluido('EXCLUIDOS'));
chequear('y en minusculas', true, ProveedoresCategorias::esExcluido('excluidos'));
chequear('otro rubro no', false, ProveedoresCategorias::esExcluido('Mercaderia'));
chequear('vacio no', false, ProveedoresCategorias::esExcluido(''));
chequear('null no', false, ProveedoresCategorias::esExcluido(null));

seccion('el rubro se convierte en un codigo de serie estable');

// El rubro lo escribe una persona en una planilla; el codigo de serie es una
// clave que viaja al registro y a CONF_FILA, y esa tiene que ser segura.
chequear('sin espacios ni acentos', 'RUBRO_LOGISTICA',
    ProveedoresCategorias::serieDeRubro('Logística'));
chequear('ni barras', 'RUBRO_IMPUESTOSYTASAS',
    ProveedoresCategorias::serieDeRubro('Impuestos y Tasas'));
chequear('un rubro vacio es SIN_RUBRO', ProveedoresCategorias::SERIE_SIN_RUBRO,
    ProveedoresCategorias::serieDeRubro(''));
chequear('y null tambien', ProveedoresCategorias::SERIE_SIN_RUBRO,
    ProveedoresCategorias::serieDeRubro(null));

// CONF_FILA acota el codigo a 30 caracteres.
$largo = ProveedoresCategorias::serieDeRubro(str_repeat('MERCADERIA', 8));
chequear('un rubro larguisimo no desborda la clave', true, strlen($largo) <= 30);

/* ================================================================
   EL DIFF DEL MAESTRO
   ================================================================ */
seccion('el codigo repetido deja en error LAS DOS filas');

$fila = function ($linea, $cod, $extra = []) {
    return array_merge([
        'linea' => $linea, 'cod_provee' => $cod, 'nombre' => 'N ' . $cod,
        'rubro_economico' => 'Mercaderia', 'rubro' => '', 'centro_costos' => '',
        'forma_pago' => 'TRANSFERENCIA', 'plazo_pago' => '30 DIAS', 'criterio_distrib' => ''
    ], $extra);
};

$c = ProveedoresCategorias::compararImportacion([
    $fila(2, 'MTDODI'),
    $fila(3, 'SAPALA'),
    $fila(4, 'MTDODI')
], []);

$porLinea = [];
foreach ($c['filas'] as $f) { $porLinea[$f['linea']] = $f; }

// Quedarse con la ultima elegiria por el usuario y nadie se enteraria de que hay
// un duplicado en la planilla.
chequear('la primera queda en error', 'ERROR', $porLinea[2]['estado']);
chequear('y la segunda tambien', 'ERROR', $porLinea[4]['estado']);
chequear('cada una nombra a la otra', true,
    strpos($porLinea[2]['motivo'], 'línea 4') !== false);
chequear('la que no se repite se carga igual', 'ALTA', $porLinea[3]['estado']);
chequear('se cuentan las dos como error', 2, $c['resumen']['errores']);
chequear('y queda un alta', 1, $c['resumen']['altas']);

seccion('un codigo mas largo que el de Tango no se carga');

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'DEMASIADOLARGO')], []);

chequear('queda en error', 'ERROR', $c['filas'][0]['estado']);
chequear('y explica por que', true,
    strpos($c['filas'][0]['motivo'], 'no va a cruzar') !== false);

seccion('un codigo con enie NO es un codigo largo');

/* EL BUG QUE ESTO FIJA: strlen() cuenta BYTES, y en UTF-8 la eñe ocupa dos.
   'OGNUÑE' daba 7 y quedaba rechazado siendo un proveedor real de Tango
   -existe en CPA01 con LEN 6-. En el maestro hay 27 proveedores con caracteres
   no ASCII en el codigo. */
chequear('la enie cuenta como UN caracter', 6, Planilla::largo('OGNUÑE'));
chequear('y strlen contaria siete', 7, strlen('OGNUÑE'));

$c = ProveedoresCategorias::compararImportacion([
    $fila(2, 'OGNUÑE'),
    $fila(3, 'OGMAGÑ'),
    $fila(4, 'OGÑAND'),
    $fila(5, 'OGA&S')
], []);

chequear('los cuatro se cargan', 4, $c['resumen']['altas']);
chequear('y ninguno queda en error', 0, $c['resumen']['errores']);

// Siete caracteres DE VERDAD si se rechazan: el limite sigue existiendo.
$c = ProveedoresCategorias::compararImportacion([$fila(2, 'OGNUÑEZ')], []);

chequear('siete caracteres reales si se rechazan', 'ERROR', $c['filas'][0]['estado']);
chequear('y el mensaje dice SIETE, que es lo que el usuario ve', true,
    strpos($c['filas'][0]['motivo'], 'tiene 7 caracteres') !== false);

seccion('un codigo en minuscula con enie sube entero');

/* EL OTRO BUG, mas silencioso: strtoupper() trabaja byte a byte y deja la eñe
   intacta -'ognuñe' -> 'OGNUñE'-. Ese codigo NO matchea contra el 'OGNUÑE' de
   Tango, y la fila quedaria sin cruzar sin que nadie entienda por que. */
chequear('mb_strtoupper sube la enie', 'OGNUÑE', Planilla::codigo('ognuñe'));
chequear('y strtoupper la dejaria abajo', 'OGNUñE', strtoupper('ognuñe'));

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'ognuñe')], []);

chequear('el codigo queda normalizado entero', 'OGNUÑE', $c['filas'][0]['cod_provee']);

// Y la clave de un pago tambien: es la que cruza contra las cuentas a pagar.
chequear('la clave de pago normaliza la enie',
    Proveedores::clavePago('OGNUÑE', 'FAC', 'A0001'),
    Proveedores::clavePago('ognuñe', 'fac', 'a0001'));

// PERO LOS ACENTOS NO SE SACAN: un codigo es un identificador, y 'OGNUNE' y
// 'OGNUÑE' pueden ser dos proveedores distintos. Eso lo distingue de
// normalizarTitulo(), que si los saca porque compara titulos de columna.
chequear('OGNUNE y OGNUÑE NO son el mismo codigo', true,
    Planilla::codigo('OGNUNE') !== Planilla::codigo('OGNUÑE'));

seccion('las filas en error no ensucian las estadisticas de calidad');

// Una fila que fallo por el codigo ni siquiera llego a leer el rubro: contarla
// como "sin rubro" seria un falso positivo.
$c = ProveedoresCategorias::compararImportacion([
    $fila(2, ''),
    $fila(3, 'SAPALA', ['rubro_economico' => ''])
], []);

chequear('solo cuenta el sin rubro de la fila que si se carga', 1, $c['resumen']['sin_rubro']);

seccion('el diff dice QUE campo cambia, no solo que cambio');

$existentes = ['MTDODI' => [
    'COD_PROVEE' => 'MTDODI', 'NOMBRE' => 'DONNA DI DIO',
    'RUBRO_ECONOMICO' => 'Mercaderia', 'RUBRO' => '', 'CENTRO_COSTOS' => '',
    'FORMA_PAGO' => 'CHEQUE', 'PLAZO_PAGO' => '30 DIAS', 'CRITERIO_DISTRIB' => ''
]];

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'MTDODI')], $existentes);
$f = $c['filas'][0];

chequear('es un cambio', 'CAMBIO', $f['estado']);
chequear('y son dos campos', 2, count($f['cambios']));

$campos = array_map(function ($x) { return $x['campo']; }, $f['cambios']);
sort($campos);

chequear('el nombre y la forma de pago', ['FORMA_PAGO', 'NOMBRE'], $campos);

// Un "cambio" sin decir cual obliga a abrir las dos versiones para entender si
// es el que se esperaba.
foreach ($f['cambios'] as $cam) {
    if ($cam['campo'] === 'FORMA_PAGO') {
        chequear('dice como estaba', 'CHEQUE', $cam['antes']);
        chequear('y como queda', 'TRANSFERENCIA', $cam['ahora']);
    }
}

seccion('lo que el archivo no trae se propone dar de baja, no se borra solo');

$existentes['SAPALA'] = ['COD_PROVEE' => 'SAPALA', 'NOMBRE' => 'IRSA',
    'RUBRO_ECONOMICO' => 'Alquileres', 'RUBRO' => '', 'CENTRO_COSTOS' => '',
    'FORMA_PAGO' => '', 'PLAZO_PAGO' => '', 'CRITERIO_DISTRIB' => ''];

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'MTDODI')], $existentes);

chequear('se propone una baja', 1, $c['resumen']['bajas']);
chequear('y se dice cual', 'SAPALA', $c['bajas'][0]['cod_provee']);

// UNA BAJA MASIVA CASI SIEMPRE ES UNA PLANILLA RECORTADA: si el archivo trae
// menos de la mitad de lo cargado, lo mas probable es que alguien exporto un
// filtro.
$muchos = [];
for ($i = 0; $i < 20; $i++) {
    $muchos['PRV' . $i] = ['COD_PROVEE' => 'PRV' . $i, 'NOMBRE' => '', 'RUBRO_ECONOMICO' => '',
        'RUBRO' => '', 'CENTRO_COSTOS' => '', 'FORMA_PAGO' => '', 'PLAZO_PAGO' => '',
        'CRITERIO_DISTRIB' => ''];
}

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'PRV0')], $muchos);
$avisoMasivo = false;

foreach ($c['avisos'] as $a) {
    if (strpos($a, 'ATENCIÓN') === 0) { $avisoMasivo = true; }
}

chequear('una baja masiva avisa distinto', true, $avisoMasivo);

/* ================================================================
   EL MAESTRO SE PUEDE CARGAR A MANO

   La planilla SIGUE MANDANDO: una edicion manual es una version mas y la
   proxima importacion la pisa. Eso es lo que evita tener dos maestros en
   paralelo, que es la decision que este modulo ya tomo cuando descarto
   CPA01.COD_RUBRO.

   Lo que estas pruebas fijan es lo otro: que pisar trabajo manual NO sea
   invisible, y que una carga a mano se normalice igual que una importada.
   ================================================================ */
seccion('una carga manual se normaliza igual que una importada');

/* ES LA MISMA FUNCION, y por eso se puede afirmar. Si la pantalla normalizara
   por su cuenta, el mismo proveedor quedaria clasificado distinto segun por
   donde entro, y no habria ninguna pantalla donde notarlo. */
$aMano = ProveedoresCategorias::normalizarFila([
    'linea' => 0,
    'cod_provee' => 'ognuñe',          // minuscula y con enie
    'nombre' => '  Proveedor Nuevo  ',
    'rubro_economico' => 'Logistica',
    'forma_pago' => 'echeq',           // minuscula
    'plazo_pago' => '30 DIAS'
]);

chequear('el codigo sube entero, con la enie', 'OGNUÑE', $aMano['cod_provee']);
chequear('la forma de pago se normaliza', 'ECHEQ', $aMano['forma_pago']);
chequear('y conserva lo que se tipeo', 'echeq', $aMano['forma_pago_orig']);
chequear('el plazo se lleva a dias', 30, $aMano['plazo_dias']);
chequear('y la fila queda lista para cargar', 'ALTA', $aMano['estado']);

// Las mismas validaciones: un codigo que no cruza contra Tango no se carga ni
// a mano ni por planilla.
$largo = ProveedoresCategorias::normalizarFila(['linea' => 0, 'cod_provee' => 'DEMASIADO']);

chequear('un codigo mas largo que el de Tango tampoco entra a mano',
    'ERROR', $largo['estado']);
chequear('y el motivo lo explica', true,
    strpos($largo['motivo'], 'no va a cruzar') !== false);

seccion('reimportar avisa antes de pisar una carga manual');

/* La planilla manda, asi que el CAMBIO se aplica igual. Lo que se agrega es
   poder VERLO: entre trescientos cambios, los que borran trabajo manual son los
   unicos que alguien querria revisar. */
$manual = ['MTDODI' => [
    'COD_PROVEE' => 'MTDODI', 'NOMBRE' => 'DONNA DI DIO',
    'RUBRO_ECONOMICO' => 'Mercaderia', 'RUBRO' => '', 'CENTRO_COSTOS' => '',
    'FORMA_PAGO' => 'CHEQUE', 'PLAZO_PAGO' => '30 DIAS', 'CRITERIO_DISTRIB' => '',
    'ORIGEN' => 'MANUAL'
]];

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'MTDODI')], $manual);

chequear('el cambio se aplica igual: la planilla manda', 'CAMBIO', $c['filas'][0]['estado']);
chequear('pero la fila queda marcada', true, $c['filas'][0]['pisa_manual']);
chequear('y el resumen lo cuenta', 1, $c['resumen']['pisa_manuales']);

$avisoManual = false;

foreach ($c['avisos'] as $a) {
    if (strpos($a, 'editado a mano') !== false) { $avisoManual = true; }
}

chequear('el aviso lo dice antes de confirmar', true, $avisoManual);

// Una fila que viene de la planilla no se marca: marcar todo seria no marcar
// nada.
$importada = $manual;
$importada['MTDODI']['ORIGEN'] = 'IMPORT';

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'MTDODI')], $importada);

chequear('pisar una fila importada no se marca', false, $c['filas'][0]['pisa_manual']);
chequear('ni se cuenta', 0, $c['resumen']['pisa_manuales']);

// Y una fila que no cambia tampoco: no hay nada que pisar. El maestro tiene
// exactamente lo que el archivo trae, y ademas esta marcado como MANUAL.
$igual = ['MTDODI' => [
    'COD_PROVEE' => 'MTDODI', 'NOMBRE' => 'N MTDODI',
    'RUBRO_ECONOMICO' => 'Mercaderia', 'RUBRO' => '', 'CENTRO_COSTOS' => '',
    'FORMA_PAGO' => 'TRANSFERENCIA', 'PLAZO_PAGO' => '30 DIAS', 'CRITERIO_DISTRIB' => '',
    'ORIGEN' => 'MANUAL'
]];

$c = ProveedoresCategorias::compararImportacion([$fila(2, 'MTDODI')], $igual);

chequear('sin cambios no hay nada que pisar', 'SIN_CAMBIOS', $c['filas'][0]['estado']);
chequear('asi que no se marca', 0, $c['resumen']['pisa_manuales']);

seccion('el script que habilita la carga manual');

$sqlManual = __DIR__ . '/../sql/cashflow_prov_locales_maestro_manual.sql';

chequear('el script existe', true, file_exists($sqlManual));

$txtManual = file_get_contents($sqlManual);

chequear('agrega ORIGEN solo si no esta', true,
    strpos($txtManual, "COL_LENGTH('dbo.RO_T_CASHFLOW_PROV_LOCALES_CATEG', 'ORIGEN') IS NULL")
        !== false);

// Lo que ya hay entro por la planilla: es el dato cierto, no un relleno.
chequear('lo que ya estaba queda como IMPORT', true,
    strpos($txtManual, "SET ORIGEN = 'IMPORT'") !== false);

/* ================================================================
   EL DIFF DE LOS PAGOS
   ================================================================ */
seccion('el tipo de comprobante se deduce cuando no viene');

$pend = function ($cod, $t, $n, $importe = 1000, $vencido = false) {
    return ['COD_PROVEE' => $cod, 'T_COMP' => $t, 'N_COMP' => $n, 'RAZON_SOC' => 'X',
            'IMPORTE_PENDIENTE' => $importe, 'FECHA_VTO' => '2026-08-01',
            'SIN_FECHA_CARGADA' => $vencido, 'FORMA_PAGO' => null];
};

$pendientes = [
    $pend('MTDODI', 'FAC', 'A0000500001731', 5000, true),
    $pend('OGCOAN', 'FAC', 'A0000300001234', 2000),
    // Un comprobante en cuotas: MISMO tipo y numero, dos vencimientos.
    $pend('OGRSA', 'FAC', 'A0010000247110', 1000),
    $pend('OGRSA', 'FAC', 'A0010000247110', 3000),
    // Dos TIPOS distintos con el mismo numero: eso si es ambiguo.
    $pend('SPEDEN', 'FAC', 'A0000100000001', 700),
    $pend('SPEDEN', 'NDI', 'A0000100000001', 300)
];

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '20/10/2026', 'forma_pago' => 'TRANSFERENCIA', 't_comp' => '',
     'observacion' => '']
], $pendientes, [], $hoy);

chequear('se deduce el tipo', 'FAC', $c['filas'][0]['t_comp']);
chequear('y queda como alta', 'ALTA', $c['filas'][0]['estado']);
chequear('trayendo el importe del comprobante', 5000.0, $c['filas'][0]['importe_pendiente']);

// EL AVISO QUE IMPORTA: cuanto de lo vencido queda resuelto.
chequear('cuenta el vencido que se resuelve', 1, $c['resumen']['vencidos_resueltos']);

seccion('un comprobante en cuotas NO es ambiguo');

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'OGRSA', 'n_comp' => 'A0010000247110',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => '', 'observacion' => '']
], $pendientes, [], $hoy);

chequear('se resuelve igual', 'ALTA', $c['filas'][0]['estado']);

// La fecha de pago se carga por COMPROBANTE, no por cuota, asi que lo que se
// esta reubicando es todo lo que se le debe.
chequear('y suma los dos vencimientos', 4000.0, $c['filas'][0]['importe_pendiente']);

seccion('dos TIPOS con el mismo numero si son ambiguos, y se pide aclarar');

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'SPEDEN', 'n_comp' => 'A0000100000001',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => '', 'observacion' => '']
], $pendientes, [], $hoy);

chequear('queda en error', 'ERROR', $c['filas'][0]['estado']);
chequear('no elige una por su cuenta', true,
    strpos($c['filas'][0]['motivo'], 'más de un tipo') !== false);

// Con el tipo aclarado, se resuelve.
$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'SPEDEN', 'n_comp' => 'A0000100000001',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => 'NDI', 'observacion' => '']
], $pendientes, [], $hoy);

chequear('aclarando el tipo se carga', 'ALTA', $c['filas'][0]['estado']);
chequear('y es el que se pidio', 300.0, $c['filas'][0]['importe_pendiente']);

seccion('lo que no cruza es un error VISIBLE, con su motivo');

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'NO-EXISTE',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => '', 'observacion' => ''],
    ['linea' => 3, 'cod_provee' => 'NOEXIS', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => '', 'observacion' => ''],
    ['linea' => 4, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => 'el jueves', 'forma_pago' => '', 't_comp' => '', 'observacion' => '']
], $pendientes, [], $hoy);

chequear('las tres fallan', 3, $c['resumen']['errores']);

// Los tres casos se arreglan distinto, asi que el motivo los distingue.
chequear('un numero que no existe lo dice', true,
    strpos($c['filas'][0]['motivo'], 'ningún comprobante') !== false);
chequear('un proveedor que no existe tambien', true,
    strpos($c['filas'][1]['motivo'], 'ningún comprobante') !== false);
chequear('y una fecha ilegible dice que decia la celda', true,
    strpos($c['filas'][2]['motivo'], 'el jueves') !== false);

seccion('dos filas para el mismo comprobante no se colapsan');

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '20/10/2026', 'forma_pago' => '', 't_comp' => 'FAC', 'observacion' => ''],
    ['linea' => 3, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '25/12/2026', 'forma_pago' => '', 't_comp' => 'FAC', 'observacion' => '']
], $pendientes, [], $hoy);

chequear('la primera se carga', 'ALTA', $c['filas'][0]['estado']);
chequear('la segunda queda en error', 'ERROR', $c['filas'][1]['estado']);
chequear('nombrando a la otra', true, strpos($c['filas'][1]['motivo'], 'línea 2') !== false);

seccion('cambiar una fecha ya cargada dice cual era');

$pagos = [Proveedores::clavePago('MTDODI', 'FAC', 'A0000500001731') => [
    'COD_PROVEE' => 'MTDODI', 'T_COMP' => 'FAC', 'N_COMP' => 'A0000500001731',
    'FECHA_PAGO' => '2026-09-30', 'FORMA_PAGO' => 'TRANSFERENCIA', 'ESTADO' => 'PREVISTO'
]];

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '20/10/2026', 'forma_pago' => 'TRANSFERENCIA', 't_comp' => 'FAC',
     'observacion' => '']
], $pendientes, $pagos, $hoy);

chequear('es un cambio', 'CAMBIO', $c['filas'][0]['estado']);
chequear('y dice como estaba', '2026-09-30', $c['filas'][0]['antes']['fecha_pago']);
chequear('lo dice tambien en el motivo', true,
    strpos($c['filas'][0]['motivo'], '30/09/2026') !== false);

// Lo mismo que ya estaba no es un cambio.
$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '30/09/2026', 'forma_pago' => 'TRANSFERENCIA', 't_comp' => 'FAC',
     'observacion' => '']
], $pendientes, $pagos, $hoy);

chequear('lo que ya estaba igual no cambia', 'SIN_CAMBIOS', $c['filas'][0]['estado']);

seccion('pisar un comprobante ya conciliado se permite, pero se marca');

$pagos[Proveedores::clavePago('MTDODI', 'FAC', 'A0000500001731')]['ESTADO'] = 'CONCILIADO';

$c = Proveedores::compararImportacion([
    ['linea' => 2, 'cod_provee' => 'MTDODI', 'n_comp' => 'A0000500001731',
     'fecha_pago' => '20/10/2026', 'forma_pago' => 'TRANSFERENCIA', 't_comp' => 'FAC',
     'observacion' => '']
], $pendientes, $pagos, $hoy);

chequear('se permite: puede ser una correccion', 'CAMBIO', $c['filas'][0]['estado']);
chequear('pero avisa que Tango ya dijo que se pago', true,
    strpos($c['filas'][0]['motivo'], 'CONCILIADO') !== false);

/* ================================================================
   LA CONCILIACION
   ================================================================ */
seccion('el desvio entre lo previsto y lo real');

// Es el dato por el que vale la pena guardar las dos fechas en lugar de pisar
// una con la otra: contesta si la prevision sirve.
chequear('pagar despues de lo previsto da positivo',
    5, Proveedores::desvioDias('2026-09-10', '2026-09-15'));
chequear('pagar antes da negativo',
    -3, Proveedores::desvioDias('2026-09-18', '2026-09-15'));
chequear('el mismo dia da cero',
    0, Proveedores::desvioDias('2026-09-15', '2026-09-15'));
chequear('sin fecha real no se puede calcular',
    null, Proveedores::desvioDias('2026-09-15', null));
chequear('ni sin prevista',
    null, Proveedores::desvioDias(null, '2026-09-15'));

/* ================================================================
   LA CLAVE INCLUYE AL PROVEEDOR, Y ES LO CONTRARIO DE COBRANZAS
   ================================================================ */
seccion('la clave de un pago');

// En ventas el comprobante lo emitimos nosotros y (T_COMP, N_COMP) alcanza. En
// compras lo emite el proveedor: hay 10.293 pares repetidos entre proveedores
// locales, asi que sin el codigo la fecha de una factura se le aplicaria a otra.
$a = Proveedores::clavePago('MTDODI', 'FAC', 'A0000100000001');
$b = Proveedores::clavePago('OGCOAN', 'FAC', 'A0000100000001');

chequear('dos proveedores con el mismo comprobante dan claves distintas', true, $a !== $b);
chequear('se normaliza a mayusculas', $a, Proveedores::clavePago('mtdodi', 'fac', 'a0000100000001'));
chequear('y se ignoran los espacios', $a, Proveedores::clavePago(' MTDODI ', ' FAC ', ' A0000100000001 '));

seccion('validacion de la fecha de pago');

chequear('una fecha valida se normaliza', '2026-10-20',
    Proveedores::validarFechaPago('2026-10-20'));

// A DIFERENCIA DE COBRANZAS FR, aca SI se aceptan fechas pasadas: el listado
// muestra todo lo pendiente sin techo de antiguedad, asi que "se penso pagar y
// no se pago" es una decision legitima y la factura sigue a la vista.
chequear('se acepta una fecha pasada', '2020-01-15',
    Proveedores::validarFechaPago('2020-01-15'));
chequearLanza('una fecha inventada se rechaza',
    function () { Proveedores::validarFechaPago('2026-02-30'); });
chequearLanza('un texto cualquiera se rechaza',
    function () { Proveedores::validarFechaPago('el jueves'); });

/* ================================================================
   LOS AVISOS DEL LISTADO
   ================================================================ */
seccion('el aviso mas importante del modulo');

$items = [
    ['IMPORTE_PENDIENTE' => 100, 'ORIGEN_FECHA' => 'VENCIMIENTO',
     'SIN_FECHA_CARGADA' => true, 'EXCLUIDO' => false],
    ['IMPORTE_PENDIENTE' => 250, 'ORIGEN_FECHA' => 'VENCIMIENTO',
     'SIN_FECHA_CARGADA' => true, 'EXCLUIDO' => false],
    ['IMPORTE_PENDIENTE' => 500, 'ORIGEN_FECHA' => 'CARGADA',
     'SIN_FECHA_CARGADA' => false, 'EXCLUIDO' => false],
    ['IMPORTE_PENDIENTE' => 70, 'ORIGEN_FECHA' => 'SIN_FECHA',
     'SIN_FECHA_CARGADA' => true, 'EXCLUIDO' => false],
    ['IMPORTE_PENDIENTE' => 30, 'ORIGEN_FECHA' => 'VENCIMIENTO',
     'SIN_FECHA_CARGADA' => false, 'EXCLUIDO' => true]
];

$avisos = Proveedores::avisosPendientes($items);
$texto = implode(' | ', $avisos);

// Ese importe se dibuja en el primer dia del eje porque no hay otro lugar donde
// ponerlo, y sin este aviso se leeria como "hoy se pagan 350 pesos".
chequear('dice cuanto hay vencido sin fecha', true, strpos($texto, '350,00') !== false);
chequear('y cuantos comprobantes son', true, strpos($texto, '2 vencimiento') !== false);

// Lo que no se puede ubicar en el eje es OTRA cosa y va aparte.
chequear('lo que no se puede ubicar va aparte', true, strpos($texto, '70,00') !== false);

chequear('y lo excluido tambien', true, strpos($texto, '30,00') !== false);

/* LOS AVISOS CUENTAN EL UNIVERSO, Y TIENEN QUE DECIRLO. Los usan los dos lados
   -la pestaña, que abre filtrada por forma de pago, y el proveedor del tablero,
   cuya fila usa PAGOS- y los dos muestran MENOS que esto. Contar el universo
   esta bien: son la contrapartida de lo que no se ve. Lo que no puede es no
   decirlo, porque un numero que no coincide con el de la pantalla se lee como
   un error del sistema.

   Se dice en CADA aviso y no una vez al final: van en una lista y se leen
   sueltos. */
foreach ($avisos as $a) {
    chequear('cada aviso dice sobre que se calcula', true,
        strpos($a, 'TODAS las cuentas a pagar') !== false);
}

// Sin nada que decir, no se dice nada: un aviso que aparece siempre deja de
// leerse.
chequear('sin nada pendiente no hay avisos', [], Proveedores::avisosPendientes([
    ['IMPORTE_PENDIENTE' => 100, 'ORIGEN_FECHA' => 'CARGADA',
     'SIN_FECHA_CARGADA' => false, 'EXCLUIDO' => false]
]));

/* ================================================================
   EL REGISTRO
   ================================================================ */
seccion('PROV_LOCALES esta enchufado al tablero');

require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';

chequear('esta registrado', true, CashflowRegistry::existe('PROV_LOCALES'));
chequear('y ya esta construido', true, CashflowRegistry::disponible('PROV_LOCALES'));
// OJO: 'PAGOS' NO trae todo. Es la que usa la fila del tablero y trae solo lo
// que se gestiona por cronograma. El universo completo es PAGOS_TODO.
chequear('ofrece la serie del cronograma', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS'));
chequear('y la del universo completo', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_TODO'));
chequear('y la de lo que queda afuera', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_FUERA_CRONOGRAMA'));

// Con estas tres, sacar a los socios del tablero es apuntar la fila a
// PAGOS_OPERATIVOS desde Parametros: configuracion, no codigo.
chequear('y la de sin excluidos', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_OPERATIVOS'));
chequear('y la de solo excluidos', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_EXCLUIDOS'));
chequear('y la de los que faltan en el maestro', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_SIN_RUBRO'));

/* LOS DOS CRITERIOS A LA VEZ. Sin esta serie no habia forma de sacar a los
   socios del tablero sin perder el criterio del cronograma: apuntar la fila a
   PAGOS_OPERATIVOS se lleva tambien los debitos automaticos. */
chequear('y la de los dos criterios juntos', true,
    CashflowRegistry::serieExiste('PROV_LOCALES', 'PAGOS_CRONO_OPERATIVOS'));

seccion('a que series va cada vencimiento');

require_once __DIR__ . '/../cashflow/Class/Providers/ProveedoresProvider.php';

$item = function ($crono, $excluido, $enMaestro = true, $serie = 'RUBRO_MERCADERIA') {
    return ['CRONOGRAMA' => $crono, 'EXCLUIDO' => $excluido,
            'EN_MAESTRO' => $enMaestro, 'SERIE' => $serie];
};

/* Los cuatro cuadrantes. El que faltaba poder aislar es el primero: cronograma
   Y operativo. */
$d = ProveedoresProvider::seriesDeItem($item(true, false));

chequear('cronograma y operativo: va al cronograma', true, in_array('PAGOS', $d, true));
chequear('a los operativos', true, in_array('PAGOS_OPERATIVOS', $d, true));
chequear('y a los dos juntos', true, in_array('PAGOS_CRONO_OPERATIVOS', $d, true));

/* EL CASO QUE MOTIVA LA SERIE: un socio que cobra por transferencia. Entra al
   cronograma -asi se le paga- pero no es deuda comercial. Hoy son $109,6
   millones de un solo proveedor dentro de la fila del tablero. */
$d = ProveedoresProvider::seriesDeItem($item(true, true));

chequear('un excluido que cobra por transferencia entra al cronograma', true,
    in_array('PAGOS', $d, true));
chequear('y a los excluidos', true, in_array('PAGOS_EXCLUIDOS', $d, true));
chequear('pero NO a la serie de los dos criterios', false,
    in_array('PAGOS_CRONO_OPERATIVOS', $d, true));

// Un debito automatico operativo: queda fuera del cronograma, asi que tampoco.
$d = ProveedoresProvider::seriesDeItem($item(false, false));

chequear('un debito operativo queda fuera del cronograma', true,
    in_array('PAGOS_FUERA_CRONOGRAMA', $d, true));
chequear('y tampoco va a la serie de los dos criterios', false,
    in_array('PAGOS_CRONO_OPERATIVOS', $d, true));

$d = ProveedoresProvider::seriesDeItem($item(false, true));

chequear('un excluido fuera del cronograma tampoco', false,
    in_array('PAGOS_CRONO_OPERATIVOS', $d, true));

// El universo se lleva las cuatro, siempre.
foreach ([[true, true], [true, false], [false, true], [false, false]] as $q) {
    chequear('el universo se lleva el cuadrante ' . json_encode($q), true,
        in_array('PAGOS_TODO', ProveedoresProvider::seriesDeItem($item($q[0], $q[1])), true));
}

// Y la serie del rubro no depende de ninguno de los dos criterios: describe QUE
// es la deuda, no como se paga ni si esta excluida.
$d = ProveedoresProvider::seriesDeItem($item(false, false, true, 'RUBRO_LOGISTICA'));

chequear('la serie del rubro va igual', true, in_array('RUBRO_LOGISTICA', $d, true));

$d = ProveedoresProvider::seriesDeItem($item(true, false, false,
    ProveedoresCategorias::SERIE_SIN_RUBRO));

chequear('sin maestro va a PAGOS_SIN_RUBRO', true, in_array('PAGOS_SIN_RUBRO', $d, true));
chequear('y no inventa una serie de rubro', false,
    in_array(ProveedoresCategorias::SERIE_SIN_RUBRO, $d, true));

$meta = CashflowRegistry::meta('PROV_LOCALES');

/* EL TOTAL CONTRA EL QUE SE MIDE EL DOBLE CONTEO ES PAGOS_TODO, no PAGOS: la
   fila del tablero usa PAGOS -solo el cronograma- pero el universo es
   PAGOS_TODO, y activar el universo junto a cualquiera de sus partes contaria
   dos veces lo mismo. */
chequear('las aperturas son partes de PAGOS_TODO', true,
    in_array('PAGOS_OPERATIVOS', $meta['componentes']['PAGOS_TODO'], true));
chequear('y la del cronograma tambien', true,
    in_array('PAGOS', $meta['componentes']['PAGOS_TODO'], true));

// 'series_extra' es detalle interno: COMO el registro consigue las series por
// rubro, no algo que el editor de estructura tenga que ver. todos() es lo que
// viaja al navegador, asi que ahi no puede aparecer -igual que 'archivo' y
// 'clase'-. meta() es de uso interno del motor y los expone los tres.
$entradaEditor = null;

foreach (CashflowRegistry::todos() as $p) {
    if ($p['codigo'] === 'PROV_LOCALES') { $entradaEditor = $p; }
}

chequear('el editor recibe la entrada', true, $entradaEditor !== null);
chequear('sin el detalle interno', false, isset($entradaEditor['series_extra']));
chequear('ni el archivo', false, isset($entradaEditor['archivo']));
chequear('ni la clase', false, isset($entradaEditor['clase']));

// Pero SI con las series ya resueltas: es lo que el editor pone en el
// desplegable.
chequear('y con las series resueltas', true, isset($entradaEditor['series']['PAGOS']));

seccion('el validador rechaza el total conviviendo con una apertura');

require_once __DIR__ . '/../cashflow/Class/CashflowEstructura.php';

$val = CashflowEstructura::validar(
    [['CODIGO' => 'EGR', 'NOMBRE' => 'Egresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'PL_TOTAL', 'NOMBRE' => 'Cuentas a Pagar', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS_TODO', 'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'PL_OPER', 'NOMBRE' => 'Sin excluidos', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS_OPERATIVOS', 'ORDEN' => 20, 'ACTIVO' => 1]]
);

chequear('activar el universo y una apertura es un error', false, $val['valido']);

/* Y el universo junto al cronograma tambien: PAGOS es una PARTE de PAGOS_TODO,
   no otra cosa. */
$val = CashflowEstructura::validar(
    [['CODIGO' => 'EGR', 'NOMBRE' => 'Egresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'PL_TODO', 'NOMBRE' => 'Todas', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS_TODO', 'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'PL_CRON', 'NOMBRE' => 'Cronograma', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS', 'ORDEN' => 20, 'ACTIVO' => 1]]
);

chequear('y el universo junto al cronograma tambien', false, $val['valido']);

/* PERO EL CRONOGRAMA Y LO QUE QUEDA AFUERA SI PUEDEN CONVIVIR: son las dos
   mitades del universo, no un total con una de sus partes. Es justamente la
   forma de meter al tablero los 141 millones que hoy quedan fuera de la fila,
   sin tocar codigo: dos filas, una por mitad. */
$val = CashflowEstructura::validar(
    [['CODIGO' => 'EGR', 'NOMBRE' => 'Egresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'PL_CRON', 'NOMBRE' => 'Cronograma', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS', 'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'PL_FUERA', 'NOMBRE' => 'Otras formas', 'SECCION' => 'EGR',
      'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
      'ORIGEN_SERIE' => 'PAGOS_FUERA_CRONOGRAMA', 'ORDEN' => 20, 'ACTIVO' => 1]]
);

chequear('las dos mitades si pueden convivir', true, $val['valido']);

seccion('el validador tambien ve los solapes entre cortes distintos');

/* EL AGUJERO QUE ESTO TAPA: la regla anterior miraba el TOTAL contra una de sus
   partes y nunca las partes ENTRE SI. PAGOS y PAGOS_OPERATIVOS son dos partes
   de PAGOS_TODO, ninguna es el total, y se solapan en $1.297 millones: el 89%
   del universo contado dos veces, sin un solo aviso. */
$filaProv = function ($id, $cod, $serie) {
    return ['ID' => $id, 'CODIGO' => $cod, 'NOMBRE' => $cod, 'SECCION' => 'EGR',
            'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'PROV_LOCALES',
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $id * 10, 'ACTIVO' => 1];
};

$seccionEgr = [['CODIGO' => 'EGR', 'NOMBRE' => 'Egresos', 'ROL' => 'MOVIMIENTO',
                'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]];

$val = CashflowEstructura::validar($seccionEgr, [
    $filaProv(1, 'PL_CRON', 'PAGOS'),
    $filaProv(2, 'PL_OPER', 'PAGOS_OPERATIVOS')
]);

chequear('el cronograma junto a los operativos ya no pasa', false, $val['valido']);

// Y el mensaje dice POR QUE, que es lo que permite arreglarlo: son dos cortes
// distintos de la misma deuda, no un total con una parte.
$texto = implode(' | ', $val['errores']);

chequear('nombrando los dos cortes', true,
    strpos($texto, 'por cómo se paga') !== false
    && strpos($texto, 'por si está excluido') !== false);

/* La serie de los dos criterios es la interseccion de una mitad de cada corte:
   se solapa con las cuatro y no puede convivir con ninguna. */
$val = CashflowEstructura::validar($seccionEgr, [
    $filaProv(1, 'PL_CRON', 'PAGOS'),
    $filaProv(2, 'PL_CO', 'PAGOS_CRONO_OPERATIVOS')
]);

chequear('ni los dos criterios junto al cronograma', false, $val['valido']);

$val = CashflowEstructura::validar($seccionEgr, [
    $filaProv(1, 'PL_EXCL', 'PAGOS_EXCLUIDOS'),
    $filaProv(2, 'PL_CO', 'PAGOS_CRONO_OPERATIVOS')
]);

chequear('ni junto a los excluidos', false, $val['valido']);

/* LO QUE SI TIENE QUE SEGUIR PASANDO. Las dos mitades de un MISMO corte son la
   forma prevista de meter al tablero lo que hoy queda fuera de la fila. */
$val = CashflowEstructura::validar($seccionEgr, [
    $filaProv(1, 'PL_OPER', 'PAGOS_OPERATIVOS'),
    $filaProv(2, 'PL_EXCL', 'PAGOS_EXCLUIDOS')
]);

chequear('las dos mitades del corte por rubro siguen conviviendo', true, $val['valido']);

/* Y UNA SOLA FILA NUNCA ES UN SOLAPE, sea cual sea la serie. */
$val = CashflowEstructura::validar($seccionEgr, [
    $filaProv(1, 'PL_CO', 'PAGOS_CRONO_OPERATIVOS')
]);

chequear('una sola fila no se pisa con nada', true, $val['valido']);

/* SIN CORTES DECLARADOS NO CAMBIA NADA. Los cuatro canales de Ventas son un
   unico corte y los cuatro pueden estar activos: si esta regla los rechazara,
   habria roto el tablero de todos los demas modulos. */
$val = CashflowEstructura::validar(
    [['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'V_LOC', 'NOMBRE' => 'Locales', 'SECCION' => 'ING',
      'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'VENTAS',
      'ORIGEN_SERIE' => 'COBRANZA_LOCALES', 'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'V_FR', 'NOMBRE' => 'Franquicias', 'SECCION' => 'ING',
      'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'VENTAS',
      'ORIGEN_SERIE' => 'COBRANZA_FRANQUICIAS', 'ORDEN' => 20, 'ACTIVO' => 1],
     ['ID' => 3, 'CODIGO' => 'V_MAY', 'NOMBRE' => 'Mayoristas', 'SECCION' => 'ING',
      'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'VENTAS',
      'ORIGEN_SERIE' => 'COBRANZA_MAYORISTAS', 'ORDEN' => 30, 'ACTIVO' => 1],
     ['ID' => 4, 'CODIGO' => 'V_ECO', 'NOMBRE' => 'Ecommerce', 'SECCION' => 'ING',
      'TIPO' => 'INGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'VENTAS',
      'ORIGEN_SERIE' => 'COBRANZA_ECOMMERCE', 'ORDEN' => 40, 'ACTIVO' => 1]]
);

chequear('los cuatro canales de Ventas siguen pudiendo convivir', true, $val['valido']);

/* UNA FILA INACTIVA NO PISA NADA: el corte se mira sobre lo que computa. */
$inactiva = $filaProv(2, 'PL_OPER', 'PAGOS_OPERATIVOS');
$inactiva['ACTIVO'] = 0;

$val = CashflowEstructura::validar($seccionEgr, [$filaProv(1, 'PL_CRON', 'PAGOS'), $inactiva]);

chequear('una fila inhabilitada no cuenta como solape', true, $val['valido']);

/* ================================================================
   CONTRA LA BASE
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$prov = new Proveedores();

if (!$prov->tablaCreada()) {
    Pruebas::saltear('falta correr sql/cashflow_prov_locales.sql');
    return;
}

$items = $prov->getPendientes();

chequear('getPendientes devuelve un array', true, is_array($items));

if (empty($items)) {
    Pruebas::saltear('no hay cuentas a pagar pendientes');
    return;
}

/* NINGUN PENDIENTE PUEDE SER NEGATIVO. Un negativo significa que las
   imputaciones se restaron mal, que es exactamente el error que tenia la
   consulta antes de aplicarle la tabla de signos de Tango: una nota de debito
   imputada es deuda NUEVA, no un pago. */
$negativos = 0;
$sinFecha = 0;
$formaOk = true;

foreach ($items as $i) {
    if ($i['IMPORTE_PENDIENTE'] < 0) { $negativos++; }
    if ($i['Pago'] === null) { $sinFecha++; }

    if ($i['FORMA_PAGO'] !== null
        && !in_array($i['FORMA_PAGO'], ProveedoresCategorias::FORMAS_PAGO, true)) {
        $formaOk = false;
    }
}

chequear('ningun pendiente es negativo', 0, $negativos);
chequear('toda forma de pago normalizada esta declarada', true, $formaOk);

/* EL FILTRO SALE DEL MAESTRO Y SOLO DEL MAESTRO. Con datos reales: para toda
   fila, CRONOGRAMA tiene que ser exactamente esDelCronograma() de la forma del
   maestro, tenga o no fecha de pago cargada. Si alguna difiriera, seria una
   fila que entro o salio del cashflow por como se registro un pago y no por
   como se le paga al proveedor. */
$discrepan = 0;
$sinFormaMaestro = 0;

foreach ($items as $i) {
    if (!array_key_exists('FORMA_PAGO_MAESTRO', $i)) { $sinFormaMaestro++; continue; }

    if ($i['CRONOGRAMA']
        !== ProveedoresCategorias::esDelCronograma($i['FORMA_PAGO_MAESTRO'])) {
        $discrepan++;
    }
}

chequear('toda fila trae la forma del maestro aparte', 0, $sinFormaMaestro);
chequear('y el filtro sale de esa y no de la del pago', 0, $discrepan);

/* CRE_DEB viajaba en cada fila y no lo consumia nadie, ni el JS ni el provider.
   Y ademas no distinguia nada: es una funcion de T_COMP via CPA21, y T_COMP ya
   es una columna de la grilla. */
$conCreDeb = 0;

foreach ($items as $i) {
    if (array_key_exists('CRE_DEB', $i)) { $conCreDeb++; }
}

chequear('CRE_DEB ya no viaja en el payload', 0, $conCreDeb);

// Los del exterior entran al tablero por COMEX_PROV_EXT: incluirlos aca los
// contaria dos veces.
$delExterior = 0;

foreach ($items as $i) {
    if (strpos($i['COD_PROVEE'], Proveedores::PREFIJO_EXTERIOR) === 0) { $delExterior++; }
}

chequear('no entra ningun proveedor del exterior', 0, $delExterior);

// Toda fila tiene su categoria resuelta, aunque el maestro este vacio: por eso
// categoria() devuelve siempre la misma forma y no null.
$sinCategoria = 0;

foreach ($items as $i) {
    if (!array_key_exists('SERIE', $i) || !array_key_exists('EN_MAESTRO', $i)) {
        $sinCategoria++;
    }
}

chequear('toda fila viene con su categoria resuelta', 0, $sinCategoria);

seccion('el proveedor del tablero');

$h = Horizonte::desdeParametros(new Parametros());
$provTablero = CashflowRegistry::instanciar('PROV_LOCALES');

chequear('se instancia', true, $provTablero instanceof CashflowProvider);

$series = $provTablero->series($h);

chequear('devuelve la serie del cronograma', true, isset($series['PAGOS']));
chequear('la del universo completo', true, isset($series['PAGOS_TODO']));
chequear('la de lo que queda afuera', true, isset($series['PAGOS_FUERA_CRONOGRAMA']));
chequear('y las tres aperturas fijas', true,
    isset($series['PAGOS_OPERATIVOS']) && isset($series['PAGOS_EXCLUIDOS'])
    && isset($series['PAGOS_SIN_RUBRO']));

chequear('la serie del cronograma tiene los dias del horizonte',
    $h->cantidadDias(), count($series['PAGOS']['dias']));

$suma = function ($s) {
    return round(array_sum($s['dias']) + array_sum($s['meses']), 2);
};

/* LAS DOS PARTICIONES DEL UNIVERSO TIENEN QUE DAR LO MISMO. Son dos formas de
   cortar la misma deuda: por COMO se paga y por QUE rubro es. Si una de las dos
   no cerrara, algun comprobante se estaria yendo a la serie equivocada. */
chequear('cronograma + fuera = universo',
    $suma($series['PAGOS_TODO']),
    $suma($series['PAGOS']) + $suma($series['PAGOS_FUERA_CRONOGRAMA']));

chequear('operativos + excluidos = universo',
    $suma($series['PAGOS_TODO']),
    $suma($series['PAGOS_OPERATIVOS']) + $suma($series['PAGOS_EXCLUIDOS']));

/* LA TERCERA SERIE NO ES UNA PARTICION: es la interseccion de una mitad de cada
   una, asi que no cierra contra nada. Lo que si tiene que valer siempre es que
   no sea mayor que ninguna de las dos mitades que la contienen -si lo fuera,
   estaria contando algo que no pertenece a ninguna de las dos-. */
chequear('los dos criterios juntos no superan al cronograma', true,
    $suma($series['PAGOS_CRONO_OPERATIVOS']) <= $suma($series['PAGOS']));
chequear('ni a los operativos', true,
    $suma($series['PAGOS_CRONO_OPERATIVOS']) <= $suma($series['PAGOS_OPERATIVOS']));

/* Y lo que le saca al cronograma es exactamente lo excluido que se paga por
   cronograma, que es el importe que hasta ahora no se podia sacar de la fila. */
$excluidoDelCronograma = 0.0;

foreach ($items as $i) {
    if (!empty($i['CRONOGRAMA']) && !empty($i['EXCLUIDO'])
        && in_array('PAGOS_CRONO_OPERATIVOS', ProveedoresProvider::seriesDeItem($i), true)) {
        $excluidoDelCronograma++;   // no deberia entrar ninguno
    }
}

chequear('ningun excluido se cuela en la serie de los dos criterios',
    0.0, $excluidoDelCronograma);

// La fila del tablero trae MENOS que el universo: esa es la decision de negocio.
chequear('el cronograma no puede ser mayor que el universo', true,
    $suma($series['PAGOS']) <= $suma($series['PAGOS_TODO']));

// El proveedor devuelve importes POSITIVOS: el signo lo pone el TIPO de la fila.
chequear('los importes van en positivo', true, $suma($series['PAGOS_TODO']) >= 0);

seccion('el registro declara exactamente las series que el proveedor devuelve');

$declaradas = array_keys(CashflowRegistry::meta('PROV_LOCALES')['series']);
$devueltas = array_keys($series);
sort($declaradas);
sort($devueltas);

// Si el registro declarara menos, el validador rechazaria una fila configurada
// contra un rubro del maestro.
chequear('coinciden', $declaradas, $devueltas);
