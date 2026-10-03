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

require_once __DIR__ . '/../Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../Class/TarjetasVencimiento.php';
require_once __DIR__ . '/../Class/Proveedores.php';

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
seccion('una factura VINCULADA no vencida entra por su vencimiento de Tango');

$facturas = [facturaTC('OGAAA', 'A001', '2026-10-05', 100000)];
$vincA = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];
$r = TarjetasCorporativas::resolver($facturas, $vincA, [], $TARJETAS, [], $HABILES, $MESES, $HOY);
$fila = $r['filas'][0];

chequear('la fecha es el vencimiento', '2026-10-05', $fila['FECHA']);
chequear('el origen lo dice', TarjetasCorporativas::FECHA_VTO, $fila['FECHA_ORIGEN']);
chequear('no esta vencida', false, $fila['VENCIDA']);
chequear('no se reubico', false, $fila['REUBICADA']);
chequear('entra al flujo', true, $fila['PROYECTA']);
chequear('el mes de pago es el del vencimiento', '2026-10', $fila['MES_PAGO']);

seccion('una factura SIN vincular no entra al flujo, aunque no este vencida');

/* SIN TARJETA NO SE PROYECTA, vencida o no: no se sabe en que debito sale. Hasta
   feature/cronogramas-tarjetas-corporativas una no vencida entraba igual por su
   vencimiento; ahora se ve en la grilla y no suma. */
$sinV = TarjetasCorporativas::resolver($facturas, [], [], $TARJETAS, [], $HABILES, $MESES,
    $HOY)['filas'][0];

chequear('no tiene tarjeta', null, $sinV['ID_TARJETA']);
chequear('no proyecta', false, $sinV['PROYECTA']);
chequear('el motivo es SIN_TARJETA', TarjetasCorporativas::SIN_TARJETA, $sinV['MOTIVO']);
chequear('pero sigue con su fecha y su importe, para la grilla', ['2026-10-05', 100000.0],
    [$sinV['FECHA'], $sinV['IMPORTE']]);
chequear('y el texto dice que no entra y que hacer', true,
    strpos(TarjetasCorporativas::explicar($sinV), 'NO entra al flujo') !== false
    && strpos(TarjetasCorporativas::explicar($sinV), 'Vinculala') !== false);

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
chequear('el motivo es SIN_TARJETA',
    TarjetasCorporativas::SIN_TARJETA, $fila['MOTIVO']);
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

$vincAB = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7,
           Proveedores::clavePago('OGBBB', 'FAC', 'A002') => 7];

$r = TarjetasCorporativas::resolver($facturas, $vincAB, $excluidas, $TARJETAS, [], $HABILES,
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

/* SIN TARJETA NO SE SABE QUE % APLICAR, y ademas no entran al flujo: no hay nada
   que acompañar. */
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

seccion('una NO vinculada en un mes con resumen: no suma, y no hay doble conteo');

/* SOLO LAS VINCULADAS LAS CUBRE EL RESUMEN, y las no vinculadas ya no suman. Hasta
   feature/cronogramas-tarjetas-corporativas la no vinculada seguia entrando, y
   por eso habia un aviso de POSIBLE DOBLE CONTEO: podia estar adentro del resumen.
   Ese riesgo ya no existe y el aviso se fue. */
$soloUna = [Proveedores::clavePago('OGAAA', 'FAC', 'A001') => 7];

$r = TarjetasCorporativas::resolver($facturas, $soloUna, [], $TARJETAS, $resumenes,
    $HABILES, $MESES, $HOY);

chequear('la vinculada queda cubierta', false, $r['filas'][0]['PROYECTA']);
chequear('la NO vinculada no suma: SIN_TARJETA', [false, TarjetasCorporativas::SIN_TARJETA],
    [$r['filas'][1]['PROYECTA'], $r['filas'][1]['MOTIVO']]);

$texto = implode(' | ', TarjetasCorporativas::avisos($r['filas']));

chequear('ya no hay aviso de posible doble conteo', false,
    strpos($texto, 'DOBLE CONTEO') !== false);
chequear('lo que se avisa es que no entra sin vincular', true,
    strpos($texto, 'sin vincular no entran al flujo') !== false);

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
chequear('y como no tiene tarjeta, no entra', [false, TarjetasCorporativas::SIN_TARJETA],
    [$r['filas'][0]['PROYECTA'], $r['filas'][0]['MOTIVO']]);

chequear('y se avisa', true,
    strpos(implode(' ', TarjetasCorporativas::avisos($r['filas'])),
        'vinculadas a una tarjeta que ya no existe') !== false);

/* ================================================================
   LOS AVISOS
   ================================================================ */
seccion('cada aviso describe un hecho distinto');

$mezcla = [
    facturaTC('OGAAA', 'A001', '2026-08-01', 111111),   // vencida sin tarjeta
    facturaTC('OGBBB', 'A002', '2026-10-06', 222222),   // no vencida sin tarjeta
    facturaTC('OGCCC', 'A003', '2026-10-07', 333333)    // excluida
];

$excl = [Proveedores::clavePago('OGCCC', 'FAC', 'A003') => ['MOTIVO' => 'Ya está en otra pestaña']];

$r = TarjetasCorporativas::resolver($mezcla, [], $excl, $TARJETAS, [], $HABILES, $MESES, $HOY);
$avisos = TarjetasCorporativas::avisos($r['filas']);
$texto = implode(' | ', $avisos);

chequear('avisa por las sin vincular, que no entran al flujo', true,
    strpos($texto, '2 factura(s) por $ 333.333,00 sin vincular no entran al flujo: '
        . 'vinculalas a una tarjeta') !== false);
chequear('con el desglose de vencidas', true,
    strpos($texto, '1 vencida(s) por $ 111.111,00') !== false);
chequear('y de no vencidas', true, strpos($texto, '1 no vencida(s) por $ 222.222,00') !== false);

chequear('avisa por las excluidas', true, strpos($texto, 'están excluidas') !== false);
chequear('con su importe', true, strpos($texto, '333.333,00') !== false);
chequear('y con el motivo', true, strpos($texto, 'Ya está en otra pestaña') !== false);

/* SON DOS AVISOS Y NO UNO: la plata sin vincular y la excluida son dos causas
   distintas. Juntarlos haria que el importe total no se pudiera atribuir a
   ninguna, que es justamente lo que un aviso tiene que permitir. */
chequear('son dos avisos separados', 2, count($avisos));

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
// La que entra tiene que estar vinculada: sin tarjeta, nada entra.
$vincB = [Proveedores::clavePago('OGBBB', 'FAC', 'A002') => 7];
$r = TarjetasCorporativas::resolver($mezcla, $vincB, $excl, $TARJETAS, [], $HABILES, $MESES, $HOY);

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

/* ================================================================
   EL VENCIMIENTO DE TANGO, NO LA FECHA DE PROVEEDORES LOCALES
   ================================================================ */
seccion('Corporativas lee FECHA_VTO de Tango y no la fecha que reubica el cronograma');

/* Desde que Proveedores Locales proyecta sus facturas en los dias de pago del
   cronograma, cada fila de getPendientes() trae dos fechas: FECHA_VTO, el
   vencimiento crudo de Tango, y 'Pago', la del proximo dia de pago. Esta pestana
   tiene que seguir mirando la PRIMERA: el pago de una tarjeta lo fija el banco,
   no el cronograma de echeqs. Si leyera 'Pago', una factura que vence el 15/10
   saldria el 28/10 y caeria en otro resumen. */
$conPago = facturaTC('OGVTO', 'A777', '2026-10-15', 50000);
$conPago['Pago'] = '2026-10-28';
$conPago['PAGO_CRONO'] = true;
$conPago['PAGO_BASE'] = '2026-10-15';

$rVto = TarjetasCorporativas::resolver([$conPago], [], [], $TARJETAS, [], $HABILES, $MESES, $HOY);

chequear('la fecha con la que entra es el vencimiento de Tango', '2026-10-15',
    $rVto['filas'][0]['FECHA']);
chequear('y su mes de pago tambien', '2026-10', $rVto['filas'][0]['MES_PAGO']);

/* Y la lectura: PagosTarjetas pide los pendientes SIN el horizonte, que es lo que
   hace que getPendientes() ni siquiera resuelva el cronograma para esta pestana. */
$fuentePT = file_get_contents(__DIR__ . '/../Class/PagosTarjetas.php');
chequear('PagosTarjetas pide getPendientes() sin horizonte', true,
    strpos($fuentePT, '$prov->getPendientes($hoy);') !== false);
chequear('y TarjetasCorporativas no lee la fecha resuelta de Proveedores Locales', false,
    (bool) preg_match('/\[\'(Pago|PAGO_BASE|PAGO_CRONO)\'\]/',
        file_get_contents(__DIR__ . '/../Class/TarjetasCorporativas.php')));

/* ================================================================
   EL VENCIMIENTO CORREGIDO REEMPLAZA AL DE TANGO EN TODA LA LOGICA
   ================================================================ */
seccion('un vencimiento corregido reemplaza al de Tango');

/* Quien carga en Tango pone el vencimiento del RESUMEN en el que se paga. Si lo
   puso mal, se corrige en esta pestaña, y la fecha corregida manda en todo: si
   esta vencida, la reubicacion, el mes de pago, la cobertura y el resumen. */
$fv = facturaTC('OGVTO', 'B100', '2026-08-15', 200000);
$vincV = [Proveedores::clavePago('OGVTO', 'FAC', 'B100') => 7];
$vtoA = function ($fecha) use ($fv) {
    return [TarjetasCorporativas::claveCuota($fv) => [
        'COD_PROVEE' => 'OGVTO', 'T_COMP' => 'FAC', 'N_COMP' => 'B100',
        'FECHA_VTO_TANGO' => '2026-08-15', 'FECHA_VTO' => $fecha, 'MOTIVO' => 'Otro resumen',
        'USUARIO_ALTA' => 'sistemas', 'FECHA_ALTA' => '2026-09-26 10:00']];
};

$sinEd = TarjetasCorporativas::resolver([$fv], $vincV, [], $TARJETAS, [], $HABILES, $MESES,
    $HOY)['filas'][0];
chequear('sin corregir: vencida y reubicada al proximo pago', [true, true, '2026-10-12'],
    [$sinEd['VENCIDA'], $sinEd['REUBICADA'], $sinEd['FECHA']]);

$ed = TarjetasCorporativas::resolver([$fv], $vincV, [], $TARJETAS, [], $HABILES, $MESES,
    $HOY, $vtoA('2026-11-20'))['filas'][0];
chequear('corregida a una fecha futura deja de estar vencida', false, $ed['VENCIDA']);
chequear('no se reubica: entra en la fecha corregida', [false, '2026-11-20', '2026-11'],
    [$ed['REUBICADA'], $ed['FECHA'], $ed['MES_PAGO']]);
chequear('la fila dice que esta corregida, y conserva el de Tango', [true, '2026-08-15', '2026-11-20'],
    [$ed['VTO_EDITADO'], $ed['FECHA_VTO'], $ed['FECHA_VTO_VIGENTE']]);
chequear('y quien la corrigio, con el motivo', ['sistemas', 'Otro resumen'],
    [$ed['VTO_EDIT_USUARIO'], $ed['VTO_EDIT_MOTIVO']]);
chequear('el texto nombra el vencimiento de Tango', true,
    strpos($ed['EXPLICACION'], 'En Tango vence el 15/08/2026') !== false);

/* LA COBERTURA SE MUEVE CON LA FECHA: el mes de pago es el corregido. */
$cobEd = TarjetasCorporativas::cobertura([$ed], $TARJETAS, [], $HABILES, $MESES, $HOY);
chequear('la cobertura cae en el mes corregido', '2026-11', $cobEd[0]['mes']);

/* EL RESUMEN TAMBIEN: con un resumen cargado en noviembre, la cuota corregida a
   noviembre queda cubierta; sin corregir caeria en octubre y no. */
$resNov = [7 => ['2026-11' => ['ID' => 81, 'MES' => '2026-11', 'IMPORTE_ARS' => 900000.0,
                               'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2026-11-10',
                               'PAGADO' => false]]];
chequear('corregida a noviembre: cubierta por el resumen de noviembre', TarjetasCorporativas::CUBIERTA,
    TarjetasCorporativas::resolver([$fv], $vincV, [], $TARJETAS, $resNov, $HABILES, $MESES, $HOY,
        $vtoA('2026-11-20'))['filas'][0]['MOTIVO']);
chequear('sin corregir no la cubre: sale en octubre', TarjetasCorporativas::OK,
    TarjetasCorporativas::resolver([$fv], $vincV, [], $TARJETAS, $resNov, $HABILES, $MESES,
        $HOY)['filas'][0]['MOTIVO']);

/* UNA CORRECCION QUE QUEDA EN EL PASADO se comporta como un vencimiento pasado:
   vencida, y al proximo pago de la tarjeta. */
$pasada = TarjetasCorporativas::resolver([$fv], $vincV, [], $TARJETAS, [], $HABILES, $MESES,
    $HOY, $vtoA('2026-09-20'))['filas'][0];
chequear('corregida a una fecha que ya paso: vencida y reubicada', [true, true, '2026-10-12'],
    [$pasada['VENCIDA'], $pasada['REUBICADA'], $pasada['FECHA']]);

seccion('deshacer vuelve al de Tango, y la correccion es por cuota');

/* DESHACER ES DAR DE BAJA: sin la correccion vigente, la cuota vuelve sola a su
   vencimiento de Tango. */
chequear('sin la correccion, vuelve a Tango', ['2026-08-15', false],
    [$sinEd['FECHA_VTO_VIGENTE'], $sinEd['VTO_EDITADO']]);

/* POR CUOTA: dos cuotas del mismo comprobante tienen claves distintas, asi que
   corregir una no toca la otra. */
$c1 = facturaTC('OGRSA', 'A010', '2026-10-05', 100000);
$c2 = facturaTC('OGRSA', 'A010', '2026-11-05', 100000);
chequear('dos cuotas del mismo comprobante tienen claves de cuota distintas', true,
    TarjetasCorporativas::claveCuota($c1) !== TarjetasCorporativas::claveCuota($c2));

$soloC1 = [TarjetasCorporativas::claveCuota($c1) => ['COD_PROVEE' => 'OGRSA', 'T_COMP' => 'FAC',
    'N_COMP' => 'A010', 'FECHA_VTO_TANGO' => '2026-10-05', 'FECHA_VTO' => '2026-10-25',
    'MOTIVO' => null, 'USUARIO_ALTA' => 'x', 'FECHA_ALTA' => null]];
$cuotas = TarjetasCorporativas::resolver([$c1, $c2], [], [], $TARJETAS, [], $HABILES, $MESES,
    $HOY, $soloC1)['filas'];
chequear('se corrige solo la cuota pedida', ['2026-10-25', '2026-11-05'],
    [$cuotas[0]['FECHA_VTO_VIGENTE'], $cuotas[1]['FECHA_VTO_VIGENTE']]);

seccion('la fecha minima es hoy, en el backend');

chequear('hoy se acepta', $HOY, TarjetasCorporativas::validarVtoEditado($HOY, $HOY));
chequearLanza('ayer no', function () use ($HOY) {
    TarjetasCorporativas::validarVtoEditado('2026-09-25', $HOY);
});
chequearLanza('una fecha que no existe tampoco', function () use ($HOY) {
    TarjetasCorporativas::validarVtoEditado('2026-02-30', $HOY);
});

seccion('una correccion sin cuota queda inerte y se avisa');

/* TANGO CAMBIO EL VENCIMIENTO: el comprobante sigue pendiente pero ninguna cuota
   tiene el vencimiento con el que se guardo la correccion. */
$movida = facturaTC('OGVTO', 'B100', '2026-09-10', 200000);
$inertes = TarjetasCorporativas::vtosInertes([$movida], $vtoA('2026-11-20'));
chequear('una correccion cuyo vencimiento de Tango ya no esta es inerte', 1, count($inertes));
chequear('y no se aplica a la cuota nueva', '2026-09-10',
    TarjetasCorporativas::resolver([$movida], $vincV, [], $TARJETAS, [], $HABILES, $MESES, $HOY,
        $vtoA('2026-11-20'))['filas'][0]['FECHA_VTO_VIGENTE']);
chequear('el aviso nombra el comprobante', true,
    strpos(TarjetasCorporativas::avisoVtosInertes($inertes), 'FAC B100 de OGVTO') !== false);

/* UN COMPROBANTE QUE YA NO ESTA PENDIENTE (se pago) no se avisa: no hay nada que
   corregir, y el aviso creceria para siempre. */
chequear('si el comprobante ya no esta, no se avisa', 0,
    count(TarjetasCorporativas::vtosInertes([facturaTC('OGOTRO', 'Z1', '2026-10-01', 1)],
        $vtoA('2026-11-20'))));
chequear('y si la cuota sigue, no es inerte', 0,
    count(TarjetasCorporativas::vtosInertes([$fv], $vtoA('2026-11-20'))));

/* ================================================================
   LA FACTURA MENSUAL (ABONOS)
   ================================================================ */
seccion('marcar como mensual: solo una vinculada y no excluida');

/* Una factura de abono, vinculada, que vence el 31 de octubre: el dia 31 tiene
   que acotarse en los meses cortos. */
$abono = facturaTC('OGABO', 'C500', '2026-10-31', 80000);
$abono['IMPORTE_VTO'] = 100000;   // la cuota entera; el pendiente es menor
$vincAbo = [Proveedores::clavePago('OGABO', 'FAC', 'C500') => 7];

$filaAbo = TarjetasCorporativas::resolver([$abono], $vincAbo, [], $TARJETAS, [], $HABILES, $MESES,
    $HOY)['filas'][0];
$datosAbo = TarjetasCorporativas::datosMarcaMensual($filaAbo);

chequear('el importe es el de la cuota (IMPORTE_VTO), no el pendiente', 100000.0,
    $datosAbo['IMPORTE']);
chequear('el dia es el del vencimiento', 31, $datosAbo['DIA']);
chequear('y se proyecta desde el mes siguiente', '2026-11', $datosAbo['MES_DESDE']);
chequear('con la tarjeta de la factura', 7, $datosAbo['ID_TARJETA']);

chequearLanza('sin tarjeta vinculada no se puede marcar', function () use ($abono, $TARJETAS,
        $HABILES, $MESES, $HOY) {
    TarjetasCorporativas::datosMarcaMensual(TarjetasCorporativas::resolver([$abono], [], [],
        $TARJETAS, [], $HABILES, $MESES, $HOY)['filas'][0]);
});

chequearLanza('excluida tampoco', function () use ($abono, $vincAbo, $TARJETAS, $HABILES, $MESES,
        $HOY) {
    TarjetasCorporativas::datosMarcaMensual(TarjetasCorporativas::resolver([$abono], $vincAbo,
        [Proveedores::clavePago('OGABO', 'FAC', 'C500') => ['MOTIVO' => 'x']], $TARJETAS, [],
        $HABILES, $MESES, $HOY)['filas'][0]);
});

/* EL VENCIMIENTO VIGENTE: si se corrigio, el dia y el primer mes salen de la
   fecha corregida. */
$filaAboEd = TarjetasCorporativas::resolver([$abono], $vincAbo, [], $TARJETAS, [], $HABILES,
    $MESES, $HOY, [TarjetasCorporativas::claveCuota($abono) => ['COD_PROVEE' => 'OGABO',
        'T_COMP' => 'FAC', 'N_COMP' => 'C500', 'FECHA_VTO_TANGO' => '2026-10-31',
        'FECHA_VTO' => '2026-11-15', 'MOTIVO' => null, 'USUARIO_ALTA' => 'x',
        'FECHA_ALTA' => null]])['filas'][0];
$datosEd = TarjetasCorporativas::datosMarcaMensual($filaAboEd);
chequear('con el vencimiento corregido, el dia y el mes salen de la correccion', [15, '2026-12'],
    [$datosEd['DIA'], $datosEd['MES_DESDE']]);

seccion('la estimacion: desde el mes siguiente, dia acotado, sin correr al habil');

$mensualAbo = array_merge($datosAbo, ['ID' => 1, 'USUARIO_ALTA' => 'sistemas',
                                      'FECHA_ALTA' => '2026-09-26 10:00']);
$MESES6 = ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'];
$TARJ_TODAS = [7 => array_merge($TARJETAS[7], ['ACTIVA' => true])];

$est = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo], $TARJ_TODAS, [], $MESES6,
    $HOY)[0];

chequear('empieza en noviembre: septiembre y octubre no',
    ['2026-11', '2026-12', '2027-01', '2027-02'], array_column($est['meses'], 'mes'));
chequear('el 31 se acota: 30/11, 31/12 y 28/02', ['2026-11-30', '2026-12-31', '2027-02-28'],
    [$est['meses'][0]['fecha'], $est['meses'][1]['fecha'], $est['meses'][3]['fecha']]);
chequear('el 31/01/2027 es domingo y NO se corre al habil', '2027-01-31', $est['meses'][2]['fecha']);
chequear('el importe es fijo en todos los meses', [100000.0, 100000.0, 100000.0, 100000.0],
    array_column($est['meses'], 'importe'));
chequear('y todos proyectan', [true, true, true, true], array_column($est['meses'], 'proyecta'));

seccion('una factura real del mismo proveedor apaga ese mes');

/* LA FACTURA DE DICIEMBRE YA ESTA EN TANGO: la estimacion de diciembre no
   proyecta, y dice por cual. Cualquier factura del universo, aunque no sume:
   aca no esta vinculada. */
$realDic = facturaTC('OGABO', 'C777', '2026-12-31', 100000);
$filasConReal = TarjetasCorporativas::resolver([$abono, $realDic], $vincAbo, [], $TARJETAS, [],
    $HABILES, $MESES6, $HOY)['filas'];
$estR = TarjetasCorporativas::estimaciones([$mensualAbo], $filasConReal, $TARJ_TODAS, [], $MESES6,
    $HOY)[0];

chequear('diciembre queda reemplazado', [TarjetasCorporativas::EST_REEMPLAZADA, false],
    [$estR['meses'][1]['estado'], $estR['meses'][1]['proyecta']]);
chequear('por la factura real, que se nombra aunque no sume', 'FAC C777', $estR['meses'][1]['por']);
chequear('los otros meses siguen', [true, true, true],
    [$estR['meses'][0]['proyecta'], $estR['meses'][2]['proyecta'], $estR['meses'][3]['proyecta']]);

$otraDic = facturaTC('OGOTRO', 'X1', '2026-12-10', 5);
$estOtro = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo, $otraDic], $TARJ_TODAS, [],
    $MESES6, $HOY)[0];
chequear('una factura de otro proveedor no la apaga', true, $estOtro['meses'][1]['proyecta']);

seccion('la estimacion se comporta como una factura vinculada');

/* CUBIERTA POR EL RESUMEN DE SU MES: el resumen ya la incluye. */
$resEne = [7 => ['2027-01' => ['ID' => 90, 'MES' => '2027-01', 'IMPORTE_ARS' => 1.0,
                               'IMPORTE_USD' => null, 'FECHA_VENCIMIENTO' => '2027-01-11',
                               'PAGADO' => false]]];
$estC = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo], $TARJ_TODAS, $resEne,
    $MESES6, $HOY)[0];
chequear('enero, con resumen cargado, queda cubierto y no suma',
    [TarjetasCorporativas::EST_CUBIERTA, false],
    [$estC['meses'][2]['estado'], $estC['meses'][2]['proyecta']]);

/* GENERA COBERTURA con el % de la tarjeta en su mes. */
$cobEst = TarjetasCorporativas::cobertura(TarjetasCorporativas::estimacionesComoFilas([$est]),
    $TARJETAS, [], $HABILES, $MESES6, $HOY);
chequear('genera cobertura en cada mes que proyecta', 4, count($cobEst));
chequear('el 10 % de la estimacion', 10000.0, $cobEst[0]['importe']);
chequear('y se cuenta como estimacion, no como factura', [0, 1],
    [$cobEst[0]['facturas'], $cobEst[0]['estimaciones']]);

seccion('una estimacion con fecha pasada o sin tarjeta activa no proyecta');

$estHoy = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo], $TARJ_TODAS, [], $MESES6,
    '2026-11-30')[0];
chequear('la del mismo dia de hoy ya no proyecta', [TarjetasCorporativas::EST_PASADA, false],
    [$estHoy['meses'][0]['estado'], $estHoy['meses'][0]['proyecta']]);

$inactiva = [7 => array_merge($TARJETAS[7], ['ACTIVA' => false])];
$estIn = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo], $inactiva, [], $MESES6,
    $HOY)[0];
chequear('con la tarjeta inactiva no proyecta ningun mes', [false, false, false, false],
    array_column($estIn['meses'], 'proyecta'));
chequear('y se avisa', 1, count(TarjetasCorporativas::avisosEstimaciones([$estIn])));

$estSinT = TarjetasCorporativas::estimaciones([$mensualAbo], [$filaAbo], [], [], $MESES6, $HOY)[0];
chequear('una tarjeta que ya no existe, igual', false, $estSinT['TARJETA_OK']);

/* LA FACTURA DE ORIGEN YA PAGADA: la estimacion sigue viva, que es para lo que se
   guarda una copia. */
$sinOrigen = TarjetasCorporativas::estimaciones([$mensualAbo], [], $TARJ_TODAS, [], $MESES6, $HOY)[0];
chequear('con la factura de origen ya pagada, sigue proyectando', [false, true],
    [$sinOrigen['ORIGEN_PENDIENTE'], $sinOrigen['meses'][0]['proyecta']]);

seccion('una sola estimacion vigente por proveedor');

/* LA RED ES EL INDICE UNICO FILTRADO del script; lo que se fija aca es que la
   clase lo respete: marcar da de baja la anterior DEL MISMO PROVEEDOR, en la misma
   transaccion, antes de insertar. */
$fuenteMen = file_get_contents(__DIR__ . '/../Class/TarjetasMensual.php');
$posBaja = strpos($fuenteMen, 'WHERE COD_PROVEE = ? AND VIGENTE = 1');
chequear('marcar da de baja la vigente del proveedor antes de insertar', true,
    $posBaja !== false && $posBaja < strpos($fuenteMen, 'INSERT INTO dbo.'));

$scriptMen = str_replace("\r\n", "\n",
    file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_vto_mensual.sql'));
chequear('y el script declara una vigente por proveedor', true,
    strpos($scriptMen, "ON dbo.RO_T_CASHFLOW_TARJETAS_MENSUAL (COD_PROVEE)\n        WHERE VIGENTE = 1")
        !== false);
