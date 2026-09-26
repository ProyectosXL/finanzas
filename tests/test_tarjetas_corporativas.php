<?php
/**
 * Tarjetas Pagos Corporativos: que factura entra, con que fecha, cuanta cobertura
 * genera y cual reemplaza un resumen.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LO VENCIDO SE APILA EN EL PRIMER DIA DEL EJE. Es lo que hace Proveedores
 *      Locales, y aca esta MAL: una tarjeta se paga una vez por mes, asi que una
 *      factura vencida sale en el proximo pago de su tarjeta. Al 26/09/2026 son 90
 *      de 135 vencimientos, asi que el error no seria un caso de borde: seria la
 *      mayoria de la pestana en la columna de hoy.
 *
 *   2. UNA VENCIDA SIN TARJETA SE UBICA EN ALGUN LADO. Sin tarjeta no hay fecha de
 *      pago: ponerla en el dia uno afirma que se paga hoy, y ponerla en su
 *      vencimiento la pone en una columna que ya paso.
 *
 *   3. LA COBERTURA MULTIPLICA LAS FACTURAS. Son deuda real de Tango: alterarlas
 *      inventa deuda sobre un comprobante que existe. La cobertura es un renglon
 *      aparte.
 *
 *   4. EL RESUMEN SUMA ADEMAS DE LAS FACTURAS. El mes se cuenta dos veces. O al
 *      reves: el resumen reemplaza tambien las facturas NO vinculadas, y entonces
 *      desaparece deuda que el resumen no incluye.
 *
 *   5. LA EXCLUSION SACA LA FACTURA DEL UNIVERSO. Tiene que sacarla de la SERIE y
 *      dejarla en la grilla: una factura que desaparece del listado no se puede
 *      volver a incluir ni controlar.
 *
 *   6. LA COBERTURA SE CALCULA SOBRE FACTURAS QUE NO SUMAN. Una factura excluida o
 *      cubierta por un resumen no esta en la fila, asi que no necesita cobertura:
 *      calcularla sumaria un importe que acompaña a nada.
 *
 * Todo lo de aca es PURO. Las facturas, los vinculos, las exclusiones, las
 * tarjetas y los resumenes se arman a mano.
 */

require_once __DIR__ . '/../cashflow/Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../cashflow/Class/TarjetasVencimiento.php';
require_once __DIR__ . '/../cashflow/Class/Proveedores.php';

/** Mapa de dias habiles de lunes a viernes, con los feriados que se le pasen */
function calendarioTC($desde, $hasta, $feriados = []) {
    $mapa = [];
    $cursor = new DateTime($desde);
    $fin = new DateTime($hasta);

    while ($cursor <= $fin) {
        $f = $cursor->format('Y-m-d');
        $mapa[$f] = (intval($cursor->format('N')) <= 5) && !in_array($f, $feriados, true);
        $cursor->modify('+1 day');
    }

    return $mapa;
}

/** Una fila de getPendientes() con lo que esta pestana mira */
function facturaTC($cod, $n, $vto, $importe, $forma = 'TARJETA CORP') {
    return [
        'COD_PROVEE' => $cod,
        'RAZON_SOC' => 'PROVEEDOR ' . $cod,
        'T_COMP' => 'FAC',
        'N_COMP' => $n,
        'FECHA_VTO' => $vto,
        'IMPORTE_PENDIENTE' => $importe,
        'FORMA_PAGO_VIGENTE' => $forma,
        'FORMA_PAGO_MAESTRO' => $forma
    ];
}

$HABILES = calendarioTC('2026-01-01', '2028-12-31');
$MESES = ['2026-09', '2026-10', '2026-11', '2026-12'];
$HOY = '2026-09-26';

/* Una tarjeta corporativa con 10 % de cobertura que vence el 10. */
$TARJETAS = [
    7 => ['ID' => 7, 'TIPO' => 'CORPORATIVA', 'PCT_COBERTURA' => 10.0,
          'DIA_VENCIMIENTO' => 10, 'ULTIMOS_4' => '1234', 'COD_BANCO' => '025',
          'DESC_BANCO' => 'SANTANDER S.A.', 'NOMBRE_USUARIO' => 'SILVIA FREIRE']
];

/* ================================================================
   EL UNIVERSO: SOLO LA FORMA VIGENTE 'TARJETA CORP'
   ================================================================ */
seccion('el universo sale de la forma de pago VIGENTE');

$pendientes = [
    facturaTC('OGAAA', 'A001', '2026-10-05', 100000, 'TARJETA CORP'),
    facturaTC('OGBBB', 'A002', '2026-10-06', 200000, 'ECHEQ'),
    facturaTC('OGCCC', 'A003', '2026-10-07', 300000, 'TRANSFERENCIA'),
    facturaTC('OGDDD', 'A004', '2026-10-08', 400000, 'TARJETA CORP'),
    facturaTC('OGEEE', 'A005', '2026-10-09', 500000, null)
];

$u = TarjetasCorporativas::universo($pendientes);

chequear('solo las dos de tarjeta corporativa', 2, count($u));
chequear('y son las que corresponden', ['OGAAA', 'OGDDD'], array_column($u, 'COD_PROVEE'));

/* UNA FORMA VACIA NO ES TARJETA CORP. En Proveedores Locales una forma desconocida
   entra al cronograma -el filtro es permisivo a proposito- pero aca no hay nada
   permisivo que hacer: sin forma no se sabe que es tarjeta corporativa. */
chequear('una forma nula no entra', 0,
    count(TarjetasCorporativas::universo([facturaTC('OGX', 'A9', '2026-10-01', 1, null)])));

/* LA COMPARACION ES EXACTA, y eso es correcto porque el valor ya viene normalizado
   por los dos caminos: el maestro al leer y el override al escribir. Una variante
   sin normalizar en la base seria un dato roto, no un caso a tolerar. */
chequear('la constante es la de FORMAS_PAGO', true,
    in_array(TarjetasCorporativas::FORMA, ProveedoresCategorias::FORMAS_PAGO, true));

/* Y NO ES UNA FORMA DEL CRONOGRAMA: por eso estas facturas viven hoy en
   PROV_LOCALES / PAGOS_FUERA_CRONOGRAMA, que NO es la serie que usa la fila del
   tablero. Es lo que hace que no haya doble conteo con la configuracion de hoy. */
chequear('TARJETA CORP no es una forma del cronograma', false,
    ProveedoresCategorias::esDelCronograma(TarjetasCorporativas::FORMA));

/* ================================================================
   LA CLAVE ES LA MISMA QUE PROVEEDORES LOCALES
   ================================================================ */
seccion('la clave del comprobante');

$f = facturaTC('OGAAA', 'A001', '2026-10-05', 100000);

chequear('es la de Proveedores::clavePago()',
    Proveedores::clavePago('OGAAA', 'FAC', 'A001'), TarjetasCorporativas::clave($f));

/* NO INCLUYE EL VENCIMIENTO: la tarjeta con la que se paga una factura es una
   propiedad de la factura y no de cada cuota. Dos cuotas del mismo comprobante dan
   la misma clave. */
$cuota1 = facturaTC('OGRSA', 'A010', '2026-10-05', 100000);
$cuota2 = facturaTC('OGRSA', 'A010', '2026-11-05', 100000);

chequear('dos cuotas del mismo comprobante dan la misma clave',
    TarjetasCorporativas::clave($cuota1), TarjetasCorporativas::clave($cuota2));

/* ================================================================
   LA FECHA: EL VENCIMIENTO DE TANGO
   ================================================================ */
seccion('una factura no vencida entra por su vencimiento de Tango');

$facturas = [facturaTC('OGAAA', 'A001', '2026-10-05', 100000)];
$r = TarjetasCorporativas::resolver($facturas, [], [], $TARJETAS, [], $HABILES, $MESES, $HOY);
$fila = $r['filas'][0];

chequear('la fecha es el vencimiento', '2026-10-05', $fila['FECHA']);
chequear('el origen lo dice', TarjetasCorporativas::FECHA_VTO, $fila['FECHA_ORIGEN']);
chequear('no esta vencida', false, $fila['VENCIDA']);
chequear('no se reubico', false, $fila['REUBICADA']);
chequear('entra al flujo', true, $fila['PROYECTA']);
chequear('el mes de pago es el del vencimiento', '2026-10', $fila['MES_PAGO']);

/* SIN VINCULAR ENTRA IGUAL. Vincular no decide si entra: decide si genera
   cobertura y si un resumen la puede reemplazar. */
chequear('sin tarjeta vinculada entra igual', null, $fila['ID_TARJETA']);
chequear('y proyecta', true, $fila['PROYECTA']);

/* UNA QUE VENCE HOY NO ESTA VENCIDA, y entra en la columna de hoy: mismo criterio
   que Ingresos::ubicarCobroVencido(), donde vencida es fecha < hoy. */
$r = TarjetasCorporativas::resolver([facturaTC('OGAAA', 'A001', $HOY, 100000)],
    [], [], $TARJETAS, [], $HABILES, $MESES, $HOY);

chequear('una que vence hoy no esta vencida', false, $r['filas'][0]['VENCIDA']);
chequear('y entra en la columna de hoy', $HOY, $r['filas'][0]['FECHA']);

/* ================================================================
   LO VENCIDO NO SE APILA EN EL DIA UNO
   ================================================================ */
seccion('una vencida VINCULADA sale en el proximo pago de su tarjeta');

/* ES LA DIFERENCIA MAS GRANDE CON PROVEEDORES LOCALES. Vencio el 15/08 y hoy es
   26/09: el proximo vencimiento de la tarjeta (dia 10) posterior a hoy es el
   12/10/2026 -el 10 cae sabado y se corre al lunes-. */
$vencida = [facturaTC('OGAAA', 'A001', '2026-08-15', 500000)];
$vinculos = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];

$r = TarjetasCorporativas::resolver($vencida, $vinculos, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);
$fila = $r['filas'][0];

chequear('esta vencida', true, $fila['VENCIDA']);
chequear('se reubico', true, $fila['REUBICADA']);
chequear('la fecha es el proximo vencimiento de la tarjeta', '2026-10-12', $fila['FECHA']);
chequear('el origen lo dice', TarjetasCorporativas::FECHA_REUBICADA, $fila['FECHA_ORIGEN']);
chequear('el mes de pago es octubre, no agosto', '2026-10', $fila['MES_PAGO']);
chequear('y entra al flujo', true, $fila['PROYECTA']);

/* NO SE APILA EN EL PRIMER DIA DEL EJE. Es el error que esta prueba atrapa. */
chequear('y NO cae en la columna de hoy', false, $fila['FECHA'] === $HOY);

seccion('una vencida SIN vincular no se proyecta');

/* SIN TARJETA NO HAY FECHA DE PAGO. Ponerla en el dia uno afirma que se paga hoy;
   dejarla en su vencimiento la pone en una columna que ya paso. */
$r = TarjetasCorporativas::resolver($vencida, [], [], $TARJETAS, [], $HABILES, $MESES, $HOY);
$fila = $r['filas'][0];

chequear('no proyecta', false, $fila['PROYECTA']);
chequear('el motivo es VENCIDA_SIN_TARJETA',
    TarjetasCorporativas::VENCIDA_SIN_TARJETA, $fila['MOTIVO']);
chequear('el importe se sigue informando, para poder decir cuanto queda afuera',
    500000.0, $fila['IMPORTE']);
chequear('y el texto dice que hacer', true,
    strpos(TarjetasCorporativas::explicar($fila), 'Vinculala a una tarjeta') !== false);

/* ================================================================
   LA EXCLUSION SACA DE LA SERIE, NO DEL UNIVERSO
   ================================================================ */
seccion('una factura excluida sigue en la grilla y no suma');

$facturas = [
    facturaTC('OGAAA', 'A001', '2026-10-05', 100000),
    facturaTC('OGBBB', 'A002', '2026-10-06', 200000)
];

$excluidas = [
    Proveedores::clavePago('OGBBB', 'FAC', 'A002') => ['MOTIVO' => 'Ya está en Supervisoras']
];

$r = TarjetasCorporativas::resolver($facturas, [], $excluidas, $TARJETAS, [], $HABILES,
    $MESES, $HOY);

/* SIGUE EN EL UNIVERSO: son dos filas. Una factura que desaparece del listado no
   se puede volver a incluir ni controlar. */
chequear('las dos siguen en la grilla', 2, count($r['filas']));

chequear('la excluida no proyecta', false, $r['filas'][1]['PROYECTA']);
chequear('el motivo es EXCLUIDA', TarjetasCorporativas::EXCLUIDA, $r['filas'][1]['MOTIVO']);
chequear('y el motivo del usuario viaja con ella', 'Ya está en Supervisoras',
    $r['filas'][1]['MOTIVO_EXCLUSION_TARJETAS']);
chequear('la otra sigue proyectando', true, $r['filas'][0]['PROYECTA']);

/* EL TEXTO ACLARA QUE NO ES LA EXCLUSION DE PROVEEDORES LOCALES: son dos
   decisiones distintas sobre la misma factura, y confundirlas haria pensar que
   excluir aca sacó la deuda del otro lado. */
chequear('el texto aclara que no toca Proveedores Locales', true,
    strpos(TarjetasCorporativas::explicar($r['filas'][1]),
        'NO está excluida de Cuentas a Pagar Locales') !== false);

/* ================================================================
   LA COBERTURA
   ================================================================ */
seccion('la cobertura es un renglon aparte, no un multiplicador');

$facturas = [
    facturaTC('OGAAA', 'A001', '2026-10-05', 100000),
    facturaTC('OGBBB', 'A002', '2026-10-20', 300000)
];

$vinculos = [
    Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7,
    Proveedores::clavePago('OGBBB', 'FAC', 'A002') => 7
];

$r = TarjetasCorporativas::resolver($facturas, $vinculos, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);

/* LAS FACTURAS ENTRAN POR SU IMPORTE, SIN TOCAR. Son deuda real de Tango. */
chequear('la primera entra por su importe exacto', 100000.0, $r['filas'][0]['IMPORTE']);
chequear('la segunda tambien', 300000.0, $r['filas'][1]['IMPORTE']);

$cob = TarjetasCorporativas::cobertura($r['filas'], $TARJETAS, [], $HABILES, $MESES, $HOY);

chequear('hay una sola fila de cobertura: una tarjeta, un mes', 1, count($cob));
chequear('la base es la suma de las dos facturas', 400000.0, $cob[0]['base']);
chequear('el importe es el 10 % de esa base', 40000.0, $cob[0]['importe']);
chequear('cuenta las facturas', 2, $cob[0]['facturas']);

/* SE UBICA EN EL DIA_VENCIMIENTO DE LA TARJETA y no en la fecha de cada factura:
   es un gasto de la tarjeta, que se debita una vez por mes. */
chequear('la fecha es el vencimiento de la tarjeta, no de las facturas',
    '2026-10-12', $cob[0]['fecha']);
chequear('y no la de ninguna de las dos', true,
    $cob[0]['fecha'] !== '2026-10-05' && $cob[0]['fecha'] !== '2026-10-20');

seccion('las no vinculadas no generan cobertura');

/* SIN TARJETA NO SE SABE QUE % APLICAR. Entran al flujo igual, por su vencimiento,
   pero no hay cobertura que calcular. */
$cob = TarjetasCorporativas::cobertura(
    TarjetasCorporativas::resolver($facturas, [], [], $TARJETAS, [], $HABILES, $MESES,
        $HOY)['filas'],
    $TARJETAS, [], $HABILES, $MESES, $HOY);

chequear('sin vinculo no hay cobertura', 0, count($cob));

seccion('una tarjeta con 0 % no genera ninguna fila de cobertura');

/* Y NO UNA FILA EN CERO: una fila de cobertura en cero se lee como "esta tarjeta
   tiene cobertura y este mes no la usa", que es otra cosa. */
$sinCob = [7 => array_merge($TARJETAS[7], ['PCT_COBERTURA' => 0.0])];

chequear('con 0 % no hay fila', 0,
    count(TarjetasCorporativas::cobertura($r['filas'], $sinCob, [], $HABILES, $MESES, $HOY)));

seccion('la cobertura no cuenta las facturas que no suman');

/* UNA FACTURA EXCLUIDA NO ESTA EN LA FILA, asi que no necesita cobertura:
   calcularla sumaria un importe que acompaña a nada. */
$excluidas = [Proveedores::clavePago('OGBBB', 'FAC', 'A002') => ['MOTIVO' => 'Duplicada']];

$conExcluida = TarjetasCorporativas::resolver($facturas, $vinculos, $excluidas, $TARJETAS,
    [], $HABILES, $MESES, $HOY);
$cob = TarjetasCorporativas::cobertura($conExcluida['filas'], $TARJETAS, [], $HABILES,
    $MESES, $HOY);

chequear('la base excluye la factura excluida', 100000.0, $cob[0]['base']);
chequear('y la cobertura tambien', 10000.0, $cob[0]['importe']);
chequear('y cuenta una sola factura', 1, $cob[0]['facturas']);

/* ================================================================
   EL RESUMEN REEMPLAZA LAS VINCULADAS DE SU MES
   ================================================================ */
seccion('el resumen reemplaza las facturas vinculadas de su mes');

$resumenes = [
    7 => ['2026-10' => ['ID' => 55, 'MES' => '2026-10', 'IMPORTE_ARS' => 450000.0,
                        'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2026-10-12',
                        'PAGADO' => false]]
];

$r = TarjetasCorporativas::resolver($facturas, $vinculos, [], $TARJETAS, $resumenes,
    $HABILES, $MESES, $HOY);

chequear('las dos siguen en la grilla', 2, count($r['filas']));
chequear('pero ninguna suma', false, $r['filas'][0]['PROYECTA']);
chequear('la segunda tampoco', false, $r['filas'][1]['PROYECTA']);
chequear('el motivo es CUBIERTA', TarjetasCorporativas::CUBIERTA, $r['filas'][0]['MOTIVO']);
chequear('se marca cual resumen las cubre', 55, $r['filas'][0]['ID_RESUMEN']);
chequear('y el texto lo dice', true,
    strpos(TarjetasCorporativas::explicar($r['filas'][0]), 'Cubierta por el resumen') !== false);

/* Y SU COBERTURA TAMBIEN SE REEMPLAZA: el resumen ya es el importe real que el
   banco va a debitar. */
chequear('el resumen tambien reemplaza la cobertura de ese mes', 0,
    count(TarjetasCorporativas::cobertura($r['filas'], $TARJETAS, $resumenes, $HABILES,
        $MESES, $HOY)));

seccion('el resumen entra al flujo con su importe y su fecha');

$res = TarjetasCorporativas::resumenesAProyectar($TARJETAS, $resumenes, $MESES, $HOY);

chequear('es uno', 1, count($res));
chequear('con el importe del resumen', 450000.0, $res[0]['importe']);
chequear('en la fecha del resumen', '2026-10-12', $res[0]['fecha']);
chequear('y proyecta', true, $res[0]['proyecta']);

/* EL TOTAL CIERRA: donde antes habia 400.000 de facturas + 40.000 de cobertura,
   ahora hay 450.000 de resumen. La diferencia es el dato real contra la
   estimacion, que es exactamente para lo que sirve cargar el resumen. */
chequear('el resumen reemplaza a las facturas Y a su cobertura', 450000.0,
    $res[0]['importe']);

seccion('un resumen pagado sale del horizonte');

$pagado = [7 => ['2026-10' => array_merge($resumenes[7]['2026-10'], ['PAGADO' => true])]];
$res = TarjetasCorporativas::resumenesAProyectar($TARJETAS, $pagado, $MESES, $HOY);

chequear('no proyecta', false, $res[0]['proyecta']);
chequear('se marca pagado', true, $res[0]['pagado']);
chequear('el importe se sigue informando', 450000.0, $res[0]['importe']);
chequear('y el motivo lo explica', true, strpos($res[0]['motivo'], 'saldo bancario') !== false);

/* LAS FACTURAS SIGUEN CUBIERTAS igual: el resumen existe, asi que su importe las
   incluye, y que ya se haya pagado no las devuelve al flujo. Si volvieran, la
   misma plata se proyectaria despues de haber salido. */
$r = TarjetasCorporativas::resolver($facturas, $vinculos, [], $TARJETAS, $pagado, $HABILES,
    $MESES, $HOY);

chequear('con el resumen pagado, las facturas siguen cubiertas', false,
    $r['filas'][0]['PROYECTA']);
chequear('y el motivo sigue siendo CUBIERTA',
    TarjetasCorporativas::CUBIERTA, $r['filas'][0]['MOTIVO']);

seccion('las NO vinculadas del mismo mes no las toca el resumen');

/* SOLO LAS VINCULADAS. El resumen no puede saber que hay adentro de una factura que
   nadie le asigno, asi que esa deuda sigue entrando. Lo que se hace es AVISAR. */
$soloUna = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];

$r = TarjetasCorporativas::resolver($facturas, $soloUna, [], $TARJETAS, $resumenes,
    $HABILES, $MESES, $HOY);

chequear('la vinculada queda cubierta', false, $r['filas'][0]['PROYECTA']);
chequear('la NO vinculada sigue entrando', true, $r['filas'][1]['PROYECTA']);

$avisos = TarjetasCorporativas::avisos($r['filas'], $resumenes);
$texto = implode(' | ', $avisos);

chequear('y se avisa del posible doble conteo', true,
    strpos($texto, 'POSIBLE DOBLE CONTEO') !== false);
chequear('nombrando el mes', true, strpos($texto, '2026-10') !== false);
chequear('y el importe', true, strpos($texto, '300.000,00') !== false);

/* ================================================================
   LA EXCLUSION GANA SOBRE LA COBERTURA POR RESUMEN

   Una factura excluida Y cubierta se contaria en la serie informativa Y adentro
   del resumen. Gana la exclusion, que es la decision explicita.
   ================================================================ */
seccion('excluida gana sobre cubierta');

$excluidas = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => ['MOTIVO' => 'Duplicada']];

$r = TarjetasCorporativas::resolver($facturas, $vinculos, $excluidas, $TARJETAS, $resumenes,
    $HABILES, $MESES, $HOY);

chequear('el motivo es EXCLUIDA y no CUBIERTA',
    TarjetasCorporativas::EXCLUIDA, $r['filas'][0]['MOTIVO']);
chequear('y no proyecta igual', false, $r['filas'][0]['PROYECTA']);

/* ================================================================
   UN VINCULO A UNA TARJETA QUE YA NO ESTA
   ================================================================ */
seccion('un vinculo roto se trata como sin vincular, y se avisa');

/* LA FK LO IMPIDE, asi que solo puede pasar si alguien borro una tarjeta a mano.
   Confundirlo con "sin vincular" esconderia una base inconsistente. */
$roto = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 999];

$r = TarjetasCorporativas::resolver([$facturas[0]], $roto, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);

chequear('se marca el vinculo roto', true, $r['filas'][0]['VINCULO_ROTO']);
chequear('la tarjeta queda en null', null, $r['filas'][0]['ID_TARJETA']);
chequear('la factura entra igual, por su vencimiento', true, $r['filas'][0]['PROYECTA']);

chequear('y se avisa', true,
    strpos(implode(' ', TarjetasCorporativas::avisos($r['filas'])),
        'vinculadas a una tarjeta que ya no existe') !== false);

/* ================================================================
   LOS AVISOS
   ================================================================ */
seccion('cada aviso describe un hecho distinto');

$mezcla = [
    facturaTC('OGAAA', 'A001', '2026-08-01', 111111),   // vencida sin tarjeta
    facturaTC('OGBBB', 'A002', '2026-10-06', 222222),   // entra sin vincular
    facturaTC('OGCCC', 'A003', '2026-10-07', 333333)    // excluida
];

$excl = [Proveedores::clavePago('OGCCC', 'FAC', 'A003') => ['MOTIVO' => 'Ya está en otra pestaña']];

$r = TarjetasCorporativas::resolver($mezcla, [], $excl, $TARJETAS, [], $HABILES, $MESES, $HOY);
$avisos = TarjetasCorporativas::avisos($r['filas']);
$texto = implode(' | ', $avisos);

chequear('avisa por las vencidas sin vincular', true,
    strpos($texto, 'no están vinculadas a ninguna tarjeta') !== false);
chequear('con su importe', true, strpos($texto, '111.111,00') !== false);

chequear('avisa por las que entran sin vincular', true,
    strpos($texto, 'no generan cobertura') !== false);
chequear('con su importe', true, strpos($texto, '222.222,00') !== false);

chequear('avisa por las excluidas', true, strpos($texto, 'están excluidas') !== false);
chequear('con su importe', true, strpos($texto, '333.333,00') !== false);
chequear('y con el motivo', true, strpos($texto, 'Ya está en otra pestaña') !== false);

/* SON TRES AVISOS Y NO UNO. Juntarlos haria que el importe total no se pudiera
   atribuir a ninguna causa, que es justamente lo que un aviso tiene que permitir. */
chequear('son tres avisos separados', 3, count($avisos));

seccion('sin nada que avisar, no hay avisos');

$limpias = [facturaTC('OGAAA', 'A001', '2026-10-05', 100000)];
$conVinculo = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];

$r = TarjetasCorporativas::resolver($limpias, $conVinculo, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);

chequear('ninguno', [], TarjetasCorporativas::avisos($r['filas']));

/* ================================================================
   EL PROXIMO PAGO DE UNA TARJETA

   Lo comparten esta sub-pestana -para reubicar una vencida- y Tarjetas Socios
   -para decidir con que dolar convertir-. Tienen que contestar igual.
   ================================================================ */
seccion('el proximo pago mira resumenes Y estimaciones');

/* SIN RESUMENES, es el proximo DIA_VENCIMIENTO posterior a hoy. */
$p = TarjetasVencimiento::proximoPago(10, [], $MESES, $HABILES, $HOY);

chequear('el proximo es el de octubre', '2026-10-12', $p['fecha']);
chequear('de origen estimacion', 'ESTIMACION', $p['origen']);

/* CON UN RESUMEN DEL MES EN CURSO QUE VENCE DENTRO DE TRES DIAS, ESE es el proximo
   pago. Mirar solo las estimaciones correria la fecha un mes entero. */
$conResumenSep = [
    '2026-09' => ['ID' => 1, 'MES' => '2026-09', 'IMPORTE_ARS' => 100.0,
                  'FECHA_VENCIMIENTO' => '2026-09-29', 'PAGADO' => false]
];

$p = TarjetasVencimiento::proximoPago(10, $conResumenSep, $MESES, $HABILES, $HOY);

chequear('el resumen del mes en curso gana', '2026-09-29', $p['fecha']);
chequear('y el origen lo dice', 'RESUMEN', $p['origen']);

/* UN RESUMEN PAGADO NO CUENTA: ya salio. Tomarlo como el proximo dejaria afuera al
   que si viene. */
$pagadoSep = ['2026-09' => array_merge($conResumenSep['2026-09'], ['PAGADO' => true])];
$p = TarjetasVencimiento::proximoPago(10, $pagadoSep, $MESES, $HABILES, $HOY);

chequear('un resumen pagado no es el proximo pago', '2026-10-12', $p['fecha']);
chequear('y se saltea el mes entero, sin caer en su estimacion', 'ESTIMACION', $p['origen']);
chequear('que es la de octubre', '2026-10', $p['mes']);

/* EL MAS TEMPRANO GANA, Y NO EL PRIMERO QUE APARECE. Recorrer los meses en orden
   no alcanza, porque la fecha de un resumen SE TIPEA y puede caer antes que la
   estimacion de un mes anterior.

   El caso: la tarjeta vence el 5. La estimacion de septiembre (05/09) ya paso; la
   de octubre es el 05/10. Y hay un resumen cargado para NOVIEMBRE que vence el
   02/10 —el periodo de noviembre con vencimiento adelantado, que es algo que el
   banco hace y que alguien tipeo asi—. El proximo pago real es el 02/10, aunque
   aparezca recorriendo un mes posterior. */
$adelantado = [
    '2026-11' => ['ID' => 2, 'MES' => '2026-11', 'IMPORTE_ARS' => 100.0,
                  'FECHA_VENCIMIENTO' => '2026-10-02', 'PAGADO' => false]
];

$p = TarjetasVencimiento::proximoPago(5, $adelantado, $MESES, $HABILES, $HOY);

chequear('la estimacion de octubre seria el 5', '2026-10-05',
    TarjetasVencimiento::delMes(5, '2026-10', $HABILES)['fecha']);
chequear('pero el resumen de noviembre vence antes, el 2, y gana',
    '2026-10-02', $p['fecha']);
chequear('y dice de que mes es', '2026-11', $p['mes']);
chequear('de origen resumen', 'RESUMEN', $p['origen']);

/* SIN NINGUNO POSTERIOR, null. No se estira el calendario. */
chequear('sin ninguno posterior, null',
    null, TarjetasVencimiento::proximoPago(10, [], ['2026-08'], $HABILES, $HOY));

/* ================================================================
   LA VENCIDA REUBICADA ENTRA EN LA COBERTURA DE SU MES DE PAGO

   Es lo que cierra el circuito: se reubica, y a partir de ahi se comporta como
   cualquier factura de ese mes.
   ================================================================ */
seccion('una vencida reubicada suma a la cobertura de su mes nuevo');

$vencidaVinculada = [
    facturaTC('OGAAA', 'A001', '2026-08-15', 500000),   // vencida, se reubica a octubre
    facturaTC('OGBBB', 'A002', '2026-10-20', 100000)    // vence en octubre
];

$dos = [
    Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7,
    Proveedores::clavePago('OGBBB', 'FAC', 'A002') => 7
];

$r = TarjetasCorporativas::resolver($vencidaVinculada, $dos, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);
$cob = TarjetasCorporativas::cobertura($r['filas'], $TARJETAS, [], $HABILES, $MESES, $HOY);

chequear('una sola fila de cobertura, la de octubre', 1, count($cob));
chequear('la base suma las dos, incluida la reubicada', 600000.0, $cob[0]['base']);
chequear('y la cobertura es el 10 %', 60000.0, $cob[0]['importe']);

/* Y SI SE CARGA EL RESUMEN DE OCTUBRE, la reubicada tambien queda cubierta: ya se
   comporta como cualquier factura de ese mes. */
$r = TarjetasCorporativas::resolver($vencidaVinculada, $dos, [], $TARJETAS, $resumenes,
    $HABILES, $MESES, $HOY);

chequear('con el resumen de octubre, la reubicada queda cubierta', false,
    $r['filas'][0]['PROYECTA']);
chequear('y su motivo es CUBIERTA', TarjetasCorporativas::CUBIERTA, $r['filas'][0]['MOTIVO']);

/* ================================================================
   LA EXPLICACION VIAJA CON LA FILA

   Es el tooltip de la grilla, y lo arma el backend porque describe una decision
   que toma el backend. Con el texto en el front, cambiar la regla obligaria a
   cambiarla en dos lados y el segundo se olvida.
   ================================================================ */
seccion('cada fila trae su explicacion ya armada');

$mezcla = [
    facturaTC('OGAAA', 'A001', '2026-08-01', 111111),   // vencida sin tarjeta
    facturaTC('OGBBB', 'A002', '2026-10-06', 222222),   // entra por su vencimiento
    facturaTC('OGCCC', 'A003', '2026-10-07', 333333)    // excluida
];

$excl = [Proveedores::clavePago('OGCCC', 'FAC', 'A003') => ['MOTIVO' => 'Ya está en Supervisoras']];
$r = TarjetasCorporativas::resolver($mezcla, [], $excl, $TARJETAS, [], $HABILES, $MESES, $HOY);

$sinExplicacion = 0;

foreach ($r['filas'] as $f) {
    if (!isset($f['EXPLICACION']) || trim($f['EXPLICACION']) === '') {
        $sinExplicacion++;
    }
}

chequear('ninguna fila queda sin explicacion', 0, $sinExplicacion);

chequear('la que entra dice por que fecha', true,
    strpos($r['filas'][1]['EXPLICACION'], 'fecha de vencimiento de Tango') !== false);
chequear('la excluida trae su motivo', true,
    strpos($r['filas'][2]['EXPLICACION'], 'Ya está en Supervisoras') !== false);
chequear('y la vencida sin tarjeta dice que hacer', true,
    strpos($r['filas'][0]['EXPLICACION'], 'Vinculala a una tarjeta') !== false);

/* Una reubicada explica las DOS fechas: cuando vencio y cuando sale. Con una
   sola, el numero de la columna no se puede relacionar con la factura. */
$vinc = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];
$r = TarjetasCorporativas::resolver([$mezcla[0]], $vinc, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY);

chequear('una reubicada nombra su vencimiento', true,
    strpos($r['filas'][0]['EXPLICACION'], '01/08/2026') !== false);
chequear('y la fecha en la que sale', true,
    strpos($r['filas'][0]['EXPLICACION'], '12/10/2026') !== false);
