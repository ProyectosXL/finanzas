<?php
/**
 * Tarjetas Socios: la base de tres resumenes, el ajuste y la regla del dolar.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL COMPONENTE EN U$S SE AJUSTA POR INFLACION. Su conversion con dolar
 *      futuro YA incorpora la devaluacion esperada, asi que ajustarlo ademas
 *      cuenta dos veces el mismo efecto. Con los numeros de hoy -curva +22,1 % e
 *      inflacion compuesta +24,0 % en once meses- el componente en dolares saldria
 *      multiplicado por 1,51 en vez de por 1,22.
 *
 *   2. SE DIVIDE POR TRES CUANDO HAY DOS RESUMENES. Un resumen que falta NO es un
 *      mes sin consumos: es un resumen que no se cargo. Dividir por tres afirma
 *      algo que nadie dijo y proyecta de menos. Es la regla CONTRARIA a la de
 *      Gastos Supervisoras, y las dos estan bien por motivos distintos.
 *
 *   3. TODO SE CONVIERTE CON EL MISMO DOLAR. El proximo vencimiento va a dolar de
 *      hoy -es inminente- y los siguientes a futuro. Con un solo criterio, o se
 *      proyecta de menos a doce meses o se infla un pago de dos semanas.
 *
 *   4. EL PROXIMO VENCIMIENTO SE BUSCA SOLO ENTRE LAS ESTIMACIONES. Un resumen
 *      cargado del mes en curso que vence en tres dias es el proximo pago, y
 *      saltearlo corre la regla del BCRA un mes entero.
 *
 *   5. SIN COTIZACION SE TOMA CERO. Se leeria como "ese mes no hay que pagar
 *      dolares", que es lo contrario de lo que pasa.
 *
 *   6. EL TOTAL SUMA UN null COMO CERO. Con el componente en pesos sin resolver,
 *      un total que solo trae los dolares es mas chico que el real y no lo dice.
 *
 * Todo lo de aca es PURO: los resumenes, la inflacion, la curva y la cotizacion
 * del BCRA se arman a mano.
 */

require_once __DIR__ . '/../Class/TarjetasSocios.php';
require_once __DIR__ . '/../Class/TarjetasVencimiento.php';

/** Mapa de dias habiles de lunes a viernes */
function calendarioSoc($desde, $hasta, $feriados = []) {
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

/** Un resumen vigente */
function resumenSoc($id, $mes, $ars, $usd, $fecha, $pagado = true) {
    return ['ID' => $id, 'MES' => $mes, 'IMPORTE_ARS' => $ars, 'IMPORTE_USD' => $usd,
            'FECHA_VENCIMIENTO' => $fecha, 'PAGADO' => $pagado, 'ORIGEN' => 'HISTORICO'];
}

$HABILES = calendarioSoc('2026-01-01', '2028-12-31');
$MESES = ['2026-09', '2026-10', '2026-11', '2026-12'];
$HOY = '2026-09-26';

/* La tarjeta de un socio: vence el 15, sin cobertura. */
$TARJETA = ['ID' => 3, 'TIPO' => 'SOCIO', 'PCT_COBERTURA' => 0.0, 'DIA_VENCIMIENTO' => 15,
            'ULTIMOS_4' => '9876', 'NOMBRE_USUARIO' => 'DAN MORGENSTERN'];

/* Inflacion al 2 % constante, que es lo que hay cargado hoy. */
$INFLACION = [];

foreach (['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
          '2027-01'] as $m) {
    $INFLACION[$m] = 2;
}

/* La curva de dolar futuro, con los valores reales de hoy. */
$CURVA = [
    '2026-09' => ['clave' => '2026-09', 'simbolo' => 'DLR/SEP26', 'cotizacion' => 1521.5],
    '2026-10' => ['clave' => '2026-10', 'simbolo' => 'DLR/OCT26', 'cotizacion' => 1547.5],
    '2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26', 'cotizacion' => 1575.0],
    '2026-12' => ['clave' => '2026-12', 'simbolo' => 'DLR/DIC26', 'cotizacion' => 1604.0]
];

/* El BCRA de hoy: 1485 al 21/09/2026, punta compradora. */
$BCRA = ['fecha' => '2026-09-21', 'valor' => 1485.0, 'punta' => Cotizacion::COMPRADOR];

/* Tres resumenes de base: jun, jul y ago de 2026. */
$BASE3 = [
    '2026-06' => resumenSoc(1, '2026-06', 300000, 1000, '2026-06-15'),
    '2026-07' => resumenSoc(2, '2026-07', 330000, 1200, '2026-07-15'),
    '2026-08' => resumenSoc(3, '2026-08', 270000, 800, '2026-08-17')
];

/* ================================================================
   LA BASE
   ================================================================ */
seccion('la base son los ultimos tres resumenes');

$b = TarjetasSocios::base($BASE3, '2026-09');

chequear('son tres', 3, $b['cantidad']);
chequear('el mes base es el mas reciente', '2026-08', $b['mes_base']);
chequear('el promedio en pesos', 300000.0, $b['promedio_ars']);
chequear('el promedio en dolares', 1000.0, $b['promedio_usd']);
chequear('la base esta completa', TarjetasSocios::OK, $b['motivo']);

seccion('solo los de periodo ANTERIOR al mes en curso');

/* EL RESUMEN DEL MES EN CURSO puede estar cargado y no vencido: usarlo como base
   seria estimar el mes con su propio dato. */
$conActual = $BASE3;
$conActual['2026-09'] = resumenSoc(4, '2026-09', 900000, 5000, '2026-09-15', false);

$b2 = TarjetasSocios::base($conActual, '2026-09');

chequear('el del mes en curso no entra en la base', 3, $b2['cantidad']);
chequear('el mes base sigue siendo agosto', '2026-08', $b2['mes_base']);
chequear('y el promedio no se mueve', 300000.0, $b2['promedio_ars']);

seccion('con mas de tres, se toman los tres ULTIMOS');

$conCuatro = $BASE3;
$conCuatro['2026-05'] = resumenSoc(0, '2026-05', 9999999, 99999, '2026-05-15');

$b3 = TarjetasSocios::base($conCuatro, '2026-09');

chequear('son tres', 3, $b3['cantidad']);
chequear('el mas viejo queda afuera', false, isset($b3['resumenes']['2026-05']));
chequear('y el promedio no se contamina', 300000.0, $b3['promedio_ars']);

seccion('con menos de tres se divide por los que hay, NO por tres');

/* ES LA REGLA CONTRARIA A GASTOS SUPERVISORAS, y las dos estan bien:
     - alla la ventana son TRES MESES CALENDARIO y los tres existen, asi que un
       mes sin gastos es un dato y se divide por tres;
     - aca la base son LOS ULTIMOS TRES RESUMENES, y si hay dos el tercero no es
       un mes sin consumos: es un resumen que NO SE CARGO.
   Dividir por tres afirmaria algo que nadie dijo y proyectaria de menos. */
$dos = ['2026-07' => $BASE3['2026-07'], '2026-08' => $BASE3['2026-08']];
$b4 = TarjetasSocios::base($dos, '2026-09');

chequear('son dos', 2, $b4['cantidad']);
chequear('el promedio se divide por DOS', 300000.0, $b4['promedio_ars']);
chequear('y no por tres, que daria 200.000', true, $b4['promedio_ars'] !== 200000.0);
chequear('y el promedio en dolares tambien', 1000.0, $b4['promedio_usd']);
chequear('se marca la base incompleta', TarjetasSocios::BASE_INCOMPLETA, $b4['motivo']);

$aviso = TarjetasSocios::avisoBase($b4, '•••• 9876');

chequear('el aviso dice cuantos hay', true, strpos($aviso, '2 resumen(es)') !== false);
chequear('y explica por que no se divide por 3', true,
    strpos($aviso, 'proyectaría de menos') !== false);

seccion('sin ningun resumen no hay con que estimar');

$b5 = TarjetasSocios::base([], '2026-09');

chequear('cero resumenes', 0, $b5['cantidad']);
chequear('el promedio es null y no cero', null, $b5['promedio_ars']);
chequear('el de dolares tambien', null, $b5['promedio_usd']);
chequear('no hay mes base', null, $b5['mes_base']);
chequear('el motivo es SIN_BASE', TarjetasSocios::SIN_BASE, $b5['motivo']);

chequear('y el aviso manda a cargar la base historica', true,
    strpos(TarjetasSocios::avisoBase($b5, '•••• 9876'), 'Cargá la base histórica') !== false);

seccion('un resumen sin importe en una moneda cuenta como cero en esa moneda');

/* EL RESUMEN EXISTE Y NO TUVO CONSUMOS EN DOLARES ESE MES: eso es un dato, y es
   distinto de que el resumen falte. */
$sinUsd = [
    '2026-06' => resumenSoc(1, '2026-06', 300000, 1500, '2026-06-15'),
    '2026-07' => resumenSoc(2, '2026-07', 300000, null, '2026-07-15'),
    '2026-08' => resumenSoc(3, '2026-08', 300000, 1500, '2026-08-17')
];

$b6 = TarjetasSocios::base($sinUsd, '2026-09');

chequear('siguen siendo tres resumenes', 3, $b6['cantidad']);
chequear('el promedio en dolares divide por tres, no por dos', 1000.0, $b6['promedio_usd']);
chequear('y el de pesos no cambia', 300000.0, $b6['promedio_ars']);

/* ================================================================
   LA ESTIMACION: LA INFLACION AJUSTA SOLO EL COMPONENTE EN PESOS
   ================================================================ */
seccion('la inflacion ajusta SOLO el componente en pesos');

$e = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, [], $HABILES, $CURVA,
    $BCRA, $HOY);

/* Octubre: dos meses de inflacion desde agosto. */
$oct = $e['meses']['2026-10'];

chequear('el componente en pesos se ajusta', 300000 * 1.0404, $oct['ars']);

/* EL COMPONENTE EN DOLARES NO SE AJUSTA. Es la decision central de esta clase. */
chequear('el componente en dolares NO se ajusta', 1000.0, $oct['usd']);
chequear('y no vale 1.040,40', true, $oct['usd'] !== 1040.4);

/* Noviembre: tres meses, compuestos. */
chequear('en noviembre el de pesos lleva tres meses compuestos', 300000 * 1.061208,
    $e['meses']['2026-11']['ars']);
chequear('y el de dolares sigue igual', 1000.0, $e['meses']['2026-11']['usd']);

/* EL DOBLE CONTEO QUE ESTO EVITA, con los numeros reales: la curva sube de 1521,5
   a 1604 entre sep-26 y dic-26 (+5,4 %) y la inflacion compone +8,24 % en los
   mismos meses. Si el componente en U$S se ajustara, diciembre saldria a
   1000 x 1,0824 x 1604 en vez de 1000 x 1604. */
$dic = $e['meses']['2026-12'];

chequear('diciembre convierte 1000 dolares y no 1082,4', 1000 * 1604.0, $dic['usd_en_pesos']);

seccion('la cobertura se aplica a los DOS componentes');

/* A DIFERENCIA DE CORPORATIVAS, donde la base son facturas reales y la cobertura
   va como renglon aparte, aca la base es una estimacion y el % la multiplica. */
$conCobertura = array_merge($TARJETA, ['PCT_COBERTURA' => 10.0]);
$e2 = TarjetasSocios::estimar($conCobertura, $b, $MESES, $INFLACION, [], $HABILES, $CURVA,
    $BCRA, $HOY);

chequear('el de dolares lleva el 10 %', 1100.0, $e2['meses']['2026-10']['usd']);
chequear('y el de pesos tambien, ademas de la inflacion', 300000 * 1.1 * 1.0404,
    $e2['meses']['2026-10']['ars']);

/* ================================================================
   LA REGLA DEL DOLAR
   ================================================================ */
seccion('el proximo vencimiento va a dolar BCRA');

/* La tarjeta vence el 15. Hoy es 26/09, asi que el 15/09 ya paso y el proximo es
   el 15/10/2026. */
$prox = $e['proximo'];

chequear('el proximo vencimiento es el de octubre', '2026-10', $prox['mes']);
chequear('el 15 de octubre', '2026-10-15', $prox['fecha']);

$oct = $e['meses']['2026-10'];

chequear('se marca como el proximo', true, $oct['es_proximo']);
chequear('el tipo de cambio es el del BCRA', 1485.0, $oct['tc']);
chequear('el origen lo dice', TarjetasSocios::TC_BCRA, $oct['tc_origen']);
chequear('y el detalle trae la fecha y la punta', true,
    strpos($oct['tc_detalle'], '2026-09-21') !== false
    && strpos($oct['tc_detalle'], 'comprador') !== false);
chequear('los dolares se convierten a ese valor', 1000 * 1485.0, $oct['usd_en_pesos']);

seccion('los siguientes van a dolar futuro del mes de su vencimiento');

$nov = $e['meses']['2026-11'];

chequear('noviembre NO es el proximo', false, $nov['es_proximo']);
chequear('usa el futuro de noviembre', 1575.0, $nov['tc']);
chequear('el origen lo dice', TarjetasSocios::TC_FUTURO, $nov['tc_origen']);
chequear('y el detalle nombra el simbolo', true,
    strpos($nov['tc_detalle'], 'DLR/NOV26') !== false);
chequear('los dolares se convierten a futuro', 1000 * 1575.0, $nov['usd_en_pesos']);

/* LAS DOS REGLAS DAN DISTINTO, y esa diferencia es el punto: con un solo criterio,
   o se proyecta de menos a doce meses o se infla un pago de dos semanas. */
chequear('el proximo y el siguiente usan tipos de cambio distintos', true,
    $oct['tc'] !== $nov['tc']);

seccion('el total en pesos suma las dos partes');

chequear('total = pesos + dolares convertidos',
    $nov['ars'] + $nov['usd_en_pesos'], $nov['total_ars']);

seccion('un resumen cargado del mes en curso ES el proximo vencimiento');

/* SI LA REGLA MIRARA SOLO LAS ESTIMACIONES, el proximo seria el 15/10 y el resumen
   de septiembre -que vence en tres dias- se convertiria a dolar futuro. */
$conResumenSep = [
    '2026-09' => resumenSoc(9, '2026-09', 400000, 900, '2026-09-29', false)
];

$e3 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, $conResumenSep, $HABILES,
    $CURVA, $BCRA, $HOY);

chequear('el proximo es el resumen de septiembre', '2026-09-29', $e3['proximo']['fecha']);
chequear('de origen resumen', 'RESUMEN', $e3['proximo']['origen']);

$sep = $e3['meses']['2026-09'];

chequear('el resumen manda el importe en pesos', 400000.0, $sep['ars']);
chequear('y el de dolares', 900.0, $sep['usd']);
chequear('y la fecha', '2026-09-29', $sep['fecha']);
chequear('se convierte con el BCRA, por ser el proximo', 1485.0, $sep['tc']);
chequear('y octubre ya NO es el proximo', false, $e3['meses']['2026-10']['es_proximo']);
chequear('asi que octubre va a futuro', TarjetasSocios::TC_FUTURO,
    $e3['meses']['2026-10']['tc_origen']);

/* EL RESUMEN NO LLEVA COBERTURA NI INFLACION: es lo que el banco va a debitar. */
$e4 = TarjetasSocios::estimar($conCobertura, $b, $MESES, $INFLACION, $conResumenSep,
    $HABILES, $CURVA, $BCRA, $HOY);

chequear('el resumen no lleva cobertura', 900.0, $e4['meses']['2026-09']['usd']);
chequear('ni ajuste por inflacion', 400000.0, $e4['meses']['2026-09']['ars']);
chequear('y no tiene factor', null, $e4['meses']['2026-09']['factor']);

seccion('un resumen pagado sale del horizonte');

$pagado = ['2026-09' => resumenSoc(9, '2026-09', 400000, 900, '2026-09-29', true)];
$e5 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, $pagado, $HABILES, $CURVA,
    $BCRA, $HOY);

chequear('no proyecta', false, $e5['meses']['2026-09']['proyecta']);
chequear('se marca pagado', true, $e5['meses']['2026-09']['pagado']);
chequear('el importe se sigue informando', 400000.0, $e5['meses']['2026-09']['ars']);

/* Y SE SALTEA COMO PROXIMO PAGO: ya salio, asi que el proximo es el de octubre y
   ese vuelve a ser el que va a dolar BCRA. */
chequear('el proximo vuelve a ser octubre', '2026-10', $e5['proximo']['mes']);
chequear('y octubre va a BCRA', TarjetasSocios::TC_BCRA, $e5['meses']['2026-10']['tc_origen']);

/* ================================================================
   SIN DATO, null Y AVISO
   ================================================================ */
seccion('sin cotizacion del BCRA, el proximo queda en null');

$e6 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, [], $HABILES, $CURVA,
    null, $HOY);
$oct = $e6['meses']['2026-10'];

chequear('el total en pesos es null y no cero', null, $oct['total_ars']);
chequear('no proyecta', false, $oct['proyecta']);
chequear('el motivo es SIN_COTIZACION', TarjetasSocios::SIN_COTIZACION, $oct['motivo']);
chequear('y se informa que mes quedo sin valuar', true,
    in_array('2026-10', $e6['faltan_cotizacion'], true));

/* LOS DEMAS MESES SE RESUELVEN IGUAL: el BCRA solo lo necesita el proximo. */
chequear('noviembre se resuelve igual, con futuro', 1575.0, $e6['meses']['2026-11']['tc']);

seccion('sin curva de futuros, los siguientes quedan en null');

$e7 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, [], $HABILES, [], $BCRA, $HOY);

chequear('el proximo se resuelve igual, con BCRA', 1485.0, $e7['meses']['2026-10']['tc']);
chequear('pero noviembre queda sin valuar', null, $e7['meses']['2026-11']['total_ars']);
chequear('y no proyecta', false, $e7['meses']['2026-11']['proyecta']);

seccion('una tarjeta sin consumos en dolares no necesita ninguna cotizacion');

/* SIN COMPONENTE EN DOLARES NO SE PIDE NINGUNA COTIZACION: una tarjeta que solo
   tiene consumos en pesos no tiene por que quedar sin proyectar porque falte la
   curva. */
$soloPesos = [
    '2026-06' => resumenSoc(1, '2026-06', 300000, null, '2026-06-15'),
    '2026-07' => resumenSoc(2, '2026-07', 300000, null, '2026-07-15'),
    '2026-08' => resumenSoc(3, '2026-08', 300000, null, '2026-08-17')
];

$bSoloPesos = TarjetasSocios::base($soloPesos, '2026-09');
$e8 = TarjetasSocios::estimar($TARJETA, $bSoloPesos, $MESES, $INFLACION, [], $HABILES,
    [], null, $HOY);

chequear('el promedio en dolares es cero', 0.0, $bSoloPesos['promedio_usd']);
chequear('no se pide ninguna cotizacion', null, $e8['meses']['2026-10']['tc']);
chequear('el total es el componente en pesos', 300000 * 1.0404,
    $e8['meses']['2026-10']['total_ars']);
chequear('y proyecta igual', true, $e8['meses']['2026-10']['proyecta']);

seccion('sin inflacion, el componente en pesos queda en null y el total tambien');

/* EL TOTAL NO SUMA UN null COMO CERO: con el componente en pesos sin resolver, un
   total que solo trae los dolares es mas chico que el real y no lo dice. */
$sinOct = $INFLACION;
unset($sinOct['2026-10']);

$e9 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $sinOct, [], $HABILES, $CURVA,
    $BCRA, $HOY);
$oct = $e9['meses']['2026-10'];

chequear('el componente en pesos es null', null, $oct['ars']);

/* EL DE DOLARES SE CALCULA IGUAL Y SE INFORMA: es correcto y util, y lo que falta
   es la otra mitad. Mismo criterio que Logistica, que muestra el valor hora aunque
   falten las horas. */
chequear('el de dolares se calcula igual', 1000.0, $oct['usd']);
chequear('y se convierte igual', 1000 * 1485.0, $oct['usd_en_pesos']);

chequear('pero el TOTAL es null, no solo los dolares', null, $oct['total_ars']);
chequear('el motivo es SIN_INFLACION', TarjetasSocios::SIN_INFLACION, $oct['motivo']);
chequear('y se informa que mes cargar', true, in_array('2026-10', $e9['faltan_inflacion'], true));

seccion('sin base, ningun mes proyecta');

$e10 = TarjetasSocios::estimar($TARJETA, $b5, $MESES, $INFLACION, [], $HABILES, $CURVA,
    $BCRA, $HOY);

chequear('el motivo de la tarjeta es SIN_BASE', TarjetasSocios::SIN_BASE, $e10['motivo']);
chequear('ningun mes tiene pesos', null, $e10['meses']['2026-10']['ars']);
chequear('ni dolares', null, $e10['meses']['2026-10']['usd']);
chequear('ninguno proyecta', false, $e10['meses']['2026-10']['proyecta']);

/* PERO LA FECHA SI SE CALCULA: es un dato del calendario y de la tarjeta, no del
   historico, y la pantalla tiene que poder mostrar cuando vencería. */
chequear('la fecha se calcula igual', '2026-10-15', $e10['meses']['2026-10']['fecha']);

seccion('una estimacion cuyo vencimiento ya paso no se proyecta');

/* El 15/09 ya paso y no hay resumen de septiembre: hay un dato que falta, no un
   pago que no va a salir. */
chequear('septiembre no proyecta', false, $e['meses']['2026-09']['proyecta']);
chequear('el motivo es VENCIDA', 'VENCIDA', $e['meses']['2026-09']['motivo']);
chequear('y la nota dice que falta el resumen', true,
    strpos($e['meses']['2026-09']['nota'], 'todavía no se cargó el resumen') !== false);

/* ================================================================
   LOS TEXTOS

   Siempre se dice que dolar se uso: un importe en pesos que salio de una
   conversion y no dice con que se convirtio no se puede verificar contra nada.
   ================================================================ */
seccion('el tooltip siempre dice con que dolar se convirtio');

$texto = TarjetasSocios::explicar($e['meses']['2026-10'], $b);

chequear('trae el promedio', true, strpos($texto, '300.000,00') !== false);
chequear('dice que el de dolares NO se ajusta', true,
    strpos($texto, 'NO se ajusta') !== false);
chequear('explica por que', true, strpos($texto, 'dos veces el mismo efecto') !== false);
chequear('y dice con que dolar se convirtio', true, strpos($texto, 'BCRA') !== false);
chequear('aclarando que es el proximo vencimiento', true,
    strpos($texto, 'próximo vencimiento') !== false);

$texto = TarjetasSocios::explicar($e['meses']['2026-11'], $b);

chequear('en los siguientes nombra el dolar futuro', true,
    strpos($texto, 'Dólar futuro') !== false);
chequear('con su simbolo', true, strpos($texto, 'DLR/NOV26') !== false);

seccion('el cartel explica la regla completa');

$cartel = TarjetasSocios::explicarDolar($BCRA, $CURVA);

chequear('nombra el BCRA con su valor', true, strpos($cartel, '1.485,00') !== false);
chequear('y su fecha', true, strpos($cartel, '2026-09-21') !== false);
chequear('dice hasta donde llega la curva', true, strpos($cartel, '2026-12') !== false);
chequear('y explica por que la inflacion no toca los dolares', true,
    strpos($cartel, 'contaría dos veces') !== false);

chequear('sin BCRA lo dice en vez de mostrar un valor', true,
    strpos(TarjetasSocios::explicarDolar(null, $CURVA), 'no se puede leer') !== false);

/* ================================================================
   EL DOLAR APROXIMADO

   Cuando la curva no llega al mes de vencimiento se usa el mas cercano, y la celda
   lo dice: aproximar en silencio seria mostrar un numero que nadie puede explicar.
   ================================================================ */
seccion('un mes fuera de la curva se marca como aproximado');

$corta = ['2026-10' => $CURVA['2026-10']];

$e11 = TarjetasSocios::estimar($TARJETA, $b, $MESES, $INFLACION, [], $HABILES, $corta,
    $BCRA, $HOY);

chequear('diciembre usa el unico mes de la curva', 1547.5, $e11['meses']['2026-12']['tc']);
chequear('y se marca como aproximado', true,
    strpos($e11['meses']['2026-12']['tc_detalle'], 'APROXIMADO') !== false);
chequear('diciendo hasta donde llega', true,
    strpos($e11['meses']['2026-12']['tc_detalle'], '2026-12') !== false);
