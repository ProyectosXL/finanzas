<?php
/**
 * El valor hora de Logistica Local: el ajuste trimestral por inflacion.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL AJUSTE SE COMPONE. "Suma sin componer" y "capitalizacion mensual" dan
 *      6,00 % y 6,12 % sobre 2 % mensual: la diferencia no se ve en un mes, se
 *      ve a los cuatro trimestres, y para entonces nadie recuerda cual de las
 *      dos cuentas se escribio.
 *
 *   2. EL AJUSTE SE APLICA SIEMPRE SOBRE EL VALOR BASE ORIGINAL. La base SE
 *      CORRE: cada ajuste va sobre el valor del trimestre anterior. Sin correr
 *      la base, el valor crece lineal en vez de multiplicativo y el error se
 *      agranda con cada trimestre.
 *
 *   3. EL AJUSTE USA LOS TRES MESES EQUIVOCADOS. dic-26 suma oct + nov + dic, no
 *      dic + ene + feb ni nov + dic + ene. Con inflacion constante las tres
 *      cuentas dan lo mismo, asi que el error SOLO aparece con inflacion
 *      variable, que es justo el caso que nadie prueba a mano.
 *
 *   4. UN MES SIN VALOR RINDE CERO. Un cero en el valor hora se lee como "esa
 *      hora no se paga", y multiplicado por las horas del mes mete un cero en
 *      el tablero que parece un dato.
 *
 *   5. EL VALOR BASE SE ESTIRA HACIA ATRAS. Un fletero con MES_BASE dic-26 no
 *      tiene ningun valor pactado para septiembre: proyectarlo con la base
 *      seria afirmar algo que nadie cargo.
 *
 * No toca la base: el mapa de inflacion se arma aca.
 */

require_once __DIR__ . '/../cashflow/Class/LogisticaValorHora.php';
require_once __DIR__ . '/../cashflow/Class/Inflacion.php';

/**
 * Compara importes con tolerancia de UN CENTAVO.
 *
 * chequear() compara flotantes con 0,001, que es la tolerancia correcta para un
 * calculo contra si mismo. El historico real de mas abajo NO es eso: son valores
 * que se pactaron y se escribieron redondeados a dos decimales en cada
 * trimestre, asi que la formula los reproduce al centavo y no al milesimo. Pedir
 * 0,001 seria exigirle a la cuenta que reproduzca el redondeo de un papel.
 */
function chequearCentavo($nombre, $esperado, $obtenido) {
    if ($obtenido !== null && abs(floatval($esperado) - floatval($obtenido)) < 0.011) {
        return chequear($nombre, true, true);
    }

    return chequear($nombre, $esperado, $obtenido);
}

/** Mapa de inflacion con el mismo % en todos los meses de un rango */
function inflacionConstante($desde, $hasta, $pct) {
    $mapa = [];
    $mes = $desde;

    while (Inflacion::distancia($mes, $hasta) >= 0) {
        $mapa[$mes] = $pct;
        $mes = Inflacion::mesMas($mes, 1);
    }

    return $mapa;
}

/* ================================================================
   LA ARITMETICA DE MESES
   ================================================================ */
seccion('correr y restar meses cruza de anio');

chequear('dic-26 + 3 = mar-27', '2027-03', Inflacion::mesMas('2026-12', 3));
chequear('ene-27 - 2 = nov-26', '2026-11', Inflacion::mesMas('2027-01', -2));
chequear('sep-26 + 12 = sep-27', '2027-09', Inflacion::mesMas('2026-09', 12));
chequear('de sep-26 a mar-27 hay 6 meses', 6, Inflacion::distancia('2026-09', '2027-03'));
chequear('y al reves da -6', -6, Inflacion::distancia('2027-03', '2026-09'));
chequear('el mismo mes da 0', 0, Inflacion::distancia('2026-09', '2026-09'));

chequearLanza('un mes sin guion lanza', function () { Inflacion::mesMas('202609', 1); });

/* ================================================================
   QUE TRES MESES SUMA UN AJUSTE
   ================================================================ */
seccion('un ajuste suma SU mes y los DOS anteriores');

/* EL CASO QUE LA SPEC NOMBRA: dic-26 = oct + nov + dic. Con inflacion VARIABLE,
   para que cada mes se distinga del otro. */
$variable = [
    '2026-08' => 1.0, '2026-09' => 1.5, '2026-10' => 2.0, '2026-11' => 3.0,
    '2026-12' => 4.0, '2027-01' => 5.0, '2027-02' => 6.0, '2027-03' => 7.0
];

$acumDic = Inflacion::acumulada($variable, '2026-12');

chequear('el ajuste de dic-26 suma 2 + 3 + 4 = 9', 9.0, $acumDic['pct']);
chequear('y nombra los tres meses en orden cronologico',
    ['2026-10', '2026-11', '2026-12'], array_keys($acumDic['meses']));
chequear('no falta ninguno', [], $acumDic['faltan']);

/* NO ES dic + ene + feb (4 + 5 + 6 = 15) ni nov + dic + ene (3 + 4 + 5 = 12).
   Con inflacion constante las tres darian lo mismo. */
chequear('NO suma hacia adelante (15)', true, $acumDic['pct'] != 15.0);
chequear('NI corrido un mes (12)', true, $acumDic['pct'] != 12.0);

$acumMar = Inflacion::acumulada($variable, '2027-03');

chequear('el ajuste de mar-27 suma ene + feb + mar = 18', 18.0, $acumMar['pct']);

seccion('si falta un mes, el ajuste no se calcula');

$incompleta = ['2026-10' => 2.0, '2026-12' => 4.0];
$acumFalta = Inflacion::acumulada($incompleta, '2026-12');

chequear('el porcentaje queda en null y no en 6', null, $acumFalta['pct']);
chequear('y se dice que mes falta', ['2026-11'], $acumFalta['faltan']);
chequear('los que si estan viajan igual, para el tooltip',
    [2.0, null, 4.0], array_values($acumFalta['meses']));

/* ================================================================
   INFLACION CONSTANTE
   ================================================================ */
seccion('con 2 % mensual constante');

/* La cuenta que enuncia la spec: dic-26 = sep-26 x 1,06 y mar-27 = dic-26 x 1,06.
   Base 1.000 para que el numero se lea. */
$dosPorCiento = inflacionConstante('2026-07', '2027-12', 2.0);
$meses = [];

for ($i = 0; $i < 13; $i++) {
    $meses[] = Inflacion::mesMas('2026-09', $i);
}

$serie = LogisticaValorHora::serie('2026-09', 1000.0, $meses, $dosPorCiento);

chequear('sep-26 es el valor base', 1000.0, $serie['2026-09']['valor']);
chequear('oct-26 sigue siendo el base', 1000.0, $serie['2026-10']['valor']);
chequear('nov-26 tambien', 1000.0, $serie['2026-11']['valor']);
chequear('dic-26 ajusta: 1000 x 1,06', 1060.0, $serie['2026-12']['valor']);
chequear('ene-27 conserva el valor de dic', 1060.0, $serie['2027-01']['valor']);
chequear('feb-27 tambien', 1060.0, $serie['2027-02']['valor']);
chequear('mar-27 ajusta sobre el de dic: 1060 x 1,06', 1123.6, $serie['2027-03']['valor']);
chequear('jun-27 ajusta otra vez', 1191.016, $serie['2027-06']['valor']);
chequear('sep-27 ajusta una cuarta vez', 1262.47696, $serie['2027-09']['valor']);

/* LA BASE SE CORRE. Sin correrla, mar-27 daria 1000 x 1,12 = 1120 en vez de
   1123,60: la diferencia es chica al segundo trimestre y crece con cada uno. */
chequear('NO es el base por la suma de los ajustes (1120)',
    true, abs($serie['2027-03']['valor'] - 1120.0) > 1);

seccion('que mes ajusta y cual no');

chequear('sep-26 no ajusta: es el trimestre base', false, $serie['2026-09']['ajusta']);
chequear('nov-26 tampoco', false, $serie['2026-11']['ajusta']);
chequear('dic-26 si', true, $serie['2026-12']['ajusta']);
chequear('ene-27 no', false, $serie['2027-01']['ajusta']);
chequear('mar-27 si', true, $serie['2027-03']['ajusta']);

chequear('los meses de ajuste del horizonte',
    ['2026-12', '2027-03', '2027-06', '2027-09'],
    LogisticaValorHora::mesesDeAjuste('2026-09', '2026-09', '2027-09'));

seccion('el valor ajustado rige desde el mes del ajuste, para LOS DOS pagos');

/* No hay una regla aparte para esto: el valor hora es por MES, asi que los dos
   pagos del mes usan el mismo. Lo que se verifica es que el ajuste no arranque
   a mitad de mes, o sea que dic-26 entero valga lo ajustado. */
chequear('dic-26 vale lo ajustado desde su primer dia', 1060.0, $serie['2026-12']['valor']);
chequear('y rige_desde lo dice', '2026-12', $serie['2026-12']['rige_desde']);
chequear('ene-27 sigue rigiendo el ajuste de dic', '2026-12', $serie['2027-01']['rige_desde']);

/* ================================================================
   INFLACION VARIABLE
   ================================================================ */
seccion('con inflacion distinta por mes');

/* Base sep-26 = 1.000. dic-26 ajusta oct+nov+dic = 2 + 3 + 4 = 9 %, mar-27
   ajusta ene+feb+mar = 5 + 6 + 7 = 18 %. */
$serieVar = LogisticaValorHora::serie('2026-09', 1000.0,
    ['2026-09', '2026-12', '2027-01', '2027-03'], $variable);

chequear('dic-26 ajusta 9 %: 1000 x 1,09', 1090.0, $serieVar['2026-12']['valor']);
chequear('y su pct lo dice', 9.0, $serieVar['2026-12']['pct']);
chequear('mar-27 ajusta 18 % sobre 1090', 1286.2, $serieVar['2027-03']['valor']);
chequear('y su pct lo dice', 18.0, $serieVar['2027-03']['pct']);

chequear('el tooltip nombra el ajuste y sus tres meses',
    true, strpos(LogisticaValorHora::explicar($serieVar['2026-12']),
        '2026-10: 2,00 % + 2026-11: 3,00 % + 2026-12: 4,00 %') !== false);

/* ================================================================
   EL HISTORICO REAL
   ================================================================ */
seccion('el historico real de los tres fleteros');

/* Tres cadenas de ajustes trimestrales, cada uno sobre el anterior:
     mar-26 -> jun-26 -> sep-26, con +10 % y +4 %.

   ES LA PRUEBA QUE MAS VALE DE TODO ESTE ARCHIVO, porque son numeros que
   existieron: si la formula no los reproduce, la formula esta mal por mas
   coherente que se vea contra si misma.

   Los valores se pactaron redondeados a dos decimales en cada trimestre, asi
   que la comparacion es al centavo. Ver chequearCentavo(). */
$inflaHist = [
    // jun-26 = abr + may + jun = 10 %
    '2026-04' => 3.0, '2026-05' => 3.0, '2026-06' => 4.0,
    // sep-26 = jul + ago + sep = 4 %
    '2026-07' => 1.0, '2026-08' => 1.5, '2026-09' => 1.5
];

$historico = [
    ['base' => 13856.12, 'jun' => 15241.74, 'sep' => 15851.41],
    ['base' => 12338.95, 'jun' => 13572.85, 'sep' => 14115.76],
    ['base' => 18032.70, 'jun' => 19835.97, 'sep' => 20629.41]
];

chequear('el ajuste de jun-26 da 10 %', 10.0, Inflacion::acumulada($inflaHist, '2026-06')['pct']);
chequear('el de sep-26 da 4 %', 4.0, Inflacion::acumulada($inflaHist, '2026-09')['pct']);

foreach ($historico as $i => $f) {
    $s = LogisticaValorHora::serie('2026-03', $f['base'],
        ['2026-03', '2026-05', '2026-06', '2026-08', '2026-09'], $inflaHist);

    $n = $i + 1;

    chequearCentavo("fletero $n: mar-26 es la base", $f['base'], $s['2026-03']['valor']);
    chequearCentavo("fletero $n: may-26 sigue en la base", $f['base'], $s['2026-05']['valor']);
    chequearCentavo("fletero $n: jun-26 ajusta +10 %", $f['jun'], $s['2026-06']['valor']);
    chequearCentavo("fletero $n: ago-26 conserva el de jun", $f['jun'], $s['2026-08']['valor']);
    chequearCentavo("fletero $n: sep-26 ajusta +4 % sobre el de jun", $f['sep'], $s['2026-09']['valor']);
}

/* ================================================================
   LO QUE NO SE PUEDE CALCULAR
   ================================================================ */
seccion('un mes anterior al MES_BASE no se proyecta');

$tardio = LogisticaValorHora::serie('2026-12', 1000.0,
    ['2026-09', '2026-11', '2026-12'], $dosPorCiento);

chequear('sep-26 con base dic-26 queda en null', null, $tardio['2026-09']['valor']);
chequear('y dice por que', LogisticaValorHora::ANTES_DE_BASE, $tardio['2026-09']['motivo']);
chequear('nov-26 tambien', null, $tardio['2026-11']['valor']);
chequear('dic-26 ya tiene el base', 1000.0, $tardio['2026-12']['valor']);
chequear('el texto lo explica', true,
    strpos(LogisticaValorHora::explicar($tardio['2026-09']), 'anterior al mes base') !== false);

seccion('un fletero sin valor hora base no se proyecta');

foreach ([null, 0, 0.0, ''] as $sinBase) {
    $s = LogisticaValorHora::delMes('2026-09', $sinBase, '2026-12', $dosPorCiento);

    chequear('sin base el valor es null, nunca 0 (' . var_export($sinBase, true) . ')',
        null, $s['valor']);
    chequear('y el motivo lo dice', LogisticaValorHora::SIN_BASE, $s['motivo']);
}

seccion('si falta la inflacion de un ajuste, se corta ahi');

/* Falta noviembre: el ajuste de dic-26 no se puede calcular, asi que dic en
   adelante queda sin valor. Lo ANTERIOR al ajuste sigue valiendo: el trimestre
   base no depende de ninguna inflacion. */
$sinNov = ['2026-10' => 2.0, '2026-12' => 2.0, '2027-01' => 2.0,
           '2027-02' => 2.0, '2027-03' => 2.0];

$corte = LogisticaValorHora::serie('2026-09', 1000.0,
    ['2026-09', '2026-11', '2026-12', '2027-03'], $sinNov);

chequear('sep-26 vale igual: el trimestre base no usa inflacion',
    1000.0, $corte['2026-09']['valor']);
chequear('nov-26 tambien', 1000.0, $corte['2026-11']['valor']);
chequear('dic-26 queda en null, no en 0', null, $corte['2026-12']['valor']);
chequear('y dice que falta noviembre', ['2026-11'], $corte['2026-12']['faltan']);
chequear('con su motivo', LogisticaValorHora::SIN_INFLACION, $corte['2026-12']['motivo']);

/* MAR-27 TAMBIEN SE CAE, aunque sus tres meses (ene, feb, mar) esten cargados:
   su valor se apoya en el de dic-26, que no existe. Un mar-27 calculado sobre
   la base sin ajustar seria un numero que nadie pidio. */
chequear('mar-27 tambien queda en null, porque se apoya en dic-26',
    null, $corte['2027-03']['valor']);
chequear('y senala el ajuste que fallo', '2026-12', $corte['2027-03']['rige_desde']);

chequear('el texto dice que mes cargar', true,
    strpos(LogisticaValorHora::explicar($corte['2026-12']), '2026-11') !== false);

/* ================================================================
   LA VENTANA DE INFLACION QUE OFRECE LA PANTALLA
   ================================================================ */
seccion('la ventana de meses editables');

$ventana = Inflacion::ventana('2026-09-25');

chequear('son catorce meses', 14, count($ventana));
chequear('arranca dos meses antes del actual', '2026-07', $ventana[0]['mes']);
chequear('el mes en curso esta', '2026-09', $ventana[2]['mes']);
chequear('y llega once meses adelante', '2027-08', $ventana[13]['mes']);

/* LOS ONCE QUE PIDE LA SPEC son los marcados 'proyecta'; los tres primeros
   estan para que un MES_BASE anterior a hoy pueda resolver su primer ajuste. */
$proyectan = array_values(array_filter($ventana, function ($m) { return $m['proyecta']; }));

chequear('once meses se proyectan', 11, count($proyectan));
chequear('desde oct-26', '2026-10', $proyectan[0]['mes']);
chequear('hasta ago-27', '2027-08', $proyectan[10]['mes']);

seccion('la validacion de lo que se guarda');

chequear('un 2 valido pasa', 2.0, Inflacion::validar('2'));
chequear('un negativo chico tambien: la deflacion existe', -1.5, Inflacion::validar('-1.5'));
chequearLanza('un texto no', function () { Inflacion::validar('dos'); });
chequearLanza('un vacio tampoco', function () { Inflacion::validar(''); });
chequearLanza('un 2000 tipeado de mas se rechaza', function () { Inflacion::validar(2000); });
chequearLanza('un -80 tambien', function () { Inflacion::validar(-80); });

chequear('un mes bien formado pasa', '2026-09', Inflacion::validarMes(' 2026-09 '));
chequearLanza('el mes 13 no', function () { Inflacion::validarMes('2026-13'); });
chequearLanza('ni el mes 00', function () { Inflacion::validarMes('2026-00'); });
chequearLanza('ni una fecha completa', function () { Inflacion::validarMes('2026-09-25'); });

chequear('CONSTANTE se reconoce', Inflacion::CONSTANTE, Inflacion::modalidad('constante'));
chequear('cualquier otra cosa cae en VARIABLE',
    Inflacion::VARIABLE, Inflacion::modalidad('lo que sea'));
