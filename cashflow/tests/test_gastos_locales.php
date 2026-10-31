<?php
/**
 * Gastos Locales: el promedio de 90 dias de la caja de los locales, llevado al
 * eje con inflacion compuesta. Una partida por semana en el tramo diario y una
 * por mes afuera.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UN DIA SE CUENTA DOS VECES. El ultimo lunes del tramo, si es feriado, se
 *      corre al martes, que puede quedar FUERA del tramo. Si el mes tambien
 *      contara ese dia, el tablero pediria dos veces la misma plata y el cuadro
 *      cerraria igual.
 *
 *   2. EL CORRIMIENTO VA PARA EL LADO DE SIEMPRE. Todos los otros cronogramas se
 *      corren al habil ANTERIOR; este, por decision del negocio, al SIGUIENTE.
 *
 *   3. UN PAGO QUE YA SALIO SE PROYECTA. Con la fecha efectiva, no la teorica:
 *      un lunes feriado que es hoy paga manana, y eso todavia no salio.
 *
 *   4. LA INFLACION QUE FALTA SE TOMA COMO CERO. Daria un egreso de menos que
 *      nadie podria ver.
 *
 *   5. LA FILA DEL TABLERO Y EL PIE DE LA PESTANA DIFIEREN. La fila tiene que
 *      ser la suma de los locales, no un calculo aparte.
 *
 * No toca la base: los gastos y el calendario se arman aca.
 */

require_once __DIR__ . '/../Class/GastosLocalesCalculo.php';
require_once __DIR__ . '/../Class/CronogramaPagos.php';
require_once __DIR__ . '/../Class/Horizonte.php';

/** Lunes a viernes habiles, con los feriados que se pasen */
function calendarioGL($desde, $hasta, $feriados = []) {
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

/** Las semanas del tramo para un hoy y unos feriados, con el cronograma por defecto */
function semanasGL($hoy, $feriados = [], $dia = 1) {
    $h = new Horizonte(28, 12, [], new DateTime($hoy));
    $cfg = CronogramaPagos::configDesdeMapa(['cronograma_gastos_locales_dia' => (string) $dia,
        'cronograma_gastos_locales_frecuencia' => 'SEMANAL'], 'GASTOS_LOCALES');
    $crono = CronogramaPagos::paraHorizonte($h, $cfg,
        calendarioGL('2026-09-01', '2027-12-31', $feriados));

    return [$h, GastosLocalesCalculo::semanas($h, $crono['pagos'])];
}

/** Inflacion constante para todos los meses de 2026-2027 */
function inflacionGL($pct, $sin = []) {
    $m = [];

    foreach (['2026', '2027'] as $a) {
        for ($i = 1; $i <= 12; $i++) {
            $k = sprintf('%s-%02d', $a, $i);

            if (!in_array($k, $sin, true)) {
                $m[$k] = $pct;
            }
        }
    }

    return $m;
}

$filasGL = [
    ['NRO_SUCURS' => 3, 'DESC_SUCURSAL' => 'ALTO PALERMO', 'COD_CTA' => '521700',
     'DESC_CUENTA' => 'COM - LIBRERIA', 'MOVIMIENTOS' => 10, 'TOTAL' => 63000.0],
    ['NRO_SUCURS' => 3, 'DESC_SUCURSAL' => 'ALTO PALERMO', 'COD_CTA' => '522450',
     'DESC_CUENTA' => 'COM - GTOS. VARIOS', 'MOVIMIENTOS' => 2, 'TOTAL' => 27000.0],
    ['NRO_SUCURS' => 7, 'DESC_SUCURSAL' => 'ABASTO', 'COD_CTA' => '521650',
     'DESC_CUENTA' => 'COM - MOVILIDAD', 'MOVIMIENTOS' => 4, 'TOTAL' => 9000.0]
];

$sucursalesGL = [
    ['NRO_SUCURSAL' => 3, 'DESC_SUCURSAL' => 'ALTO PALERMO'],
    ['NRO_SUCURSAL' => 7, 'DESC_SUCURSAL' => 'ABASTO'],
    ['NRO_SUCURSAL' => 9, 'DESC_SUCURSAL' => 'SIN GASTOS']
];

/* ================================================================ */
seccion('el gasto diario: total de los 90 dias / 90, por local');

$loc = GastosLocalesCalculo::locales($filasGL, $sucursalesGL);

chequear('una fila por local propio, en orden', [3, 7, 9], array_keys($loc));
chequear('Alto Palermo suma sus dos cuentas', 90000.0, $loc[3]['total']);
chequear('y su gasto diario es 90.000 / 90', 1000.0, $loc[3]['diario']);
chequear('Abasto: 9.000 / 90', 100.0, $loc[7]['diario']);
chequear('el detalle por cuenta, la que mas pesa primero', ['521700', '522450'],
    array_column($loc[3]['cuentas'], 'cod_cta'));
chequear('cada cuenta con su diario', 700.0, $loc[3]['cuentas'][0]['diario']);
chequear('el local sin egresos va igual, con cero y marcado', [0.0, 0.0, true],
    [$loc[9]['total'], $loc[9]['diario'], $loc[9]['sin_egresos']]);
chequear('un local con egresos no queda marcado', false, $loc[3]['sin_egresos']);

$soloFilas = GastosLocalesCalculo::locales($filasGL, []);
chequear('sin la lista de sucursales, los locales salen de las filas', [3, 7],
    array_keys($soloFilas));

/* ================================================================ */
seccion('las semanas del tramo: el lunes, corrido al habil SIGUIENTE');

// Hoy sabado 31/10/2026: el tramo va del 31/10 al 27/11.
list($hSab, $semSab) = semanasGL('2026-10-31');

chequear('el tramo termina el 27/11', '2026-11-27', GastosLocalesCalculo::finTramo($hSab));
chequear('cuatro lunes: 2, 9, 16 y 23 de noviembre',
    ['2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23'], array_column($semSab, 'fecha'));

list(, $semFer) = semanasGL('2026-10-31', ['2026-11-23']);
chequear('el lunes 23 feriado se paga el martes 24', '2026-11-24', $semFer[3]['fecha']);
chequear('marcado corrido, con la teorica a la vista', [true, '2026-11-23'],
    [$semFer[3]['corrida'], $semFer[3]['teorica']]);

list(, $semMie) = semanasGL('2026-10-31', [], 3);
chequear('con el miercoles elegido, los miercoles del tramo',
    ['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25'], array_column($semMie, 'fecha'));

/* ================================================================ */
seccion('una fecha de pago igual o anterior a hoy no se proyecta');

// Hoy lunes 02/11, habil: ese pago ya se hizo. Quedan 9, 16 y 23.
list(, $semLun) = semanasGL('2026-11-02');
chequear('el lunes de hoy ya se pago', ['2026-11-09', '2026-11-16', '2026-11-23'],
    array_column($semLun, 'fecha'));

// Hoy lunes 02/11 FERIADO: el pago se corre a manana y todavia no salio.
list(, $semLunFer) = semanasGL('2026-11-02', ['2026-11-02']);
chequear('un lunes feriado que es hoy paga manana, y se proyecta',
    ['2026-11-03', '2026-11-09', '2026-11-16', '2026-11-23'], array_column($semLunFer, 'fecha'));

/* ================================================================ */
seccion('los meses: solo los dias posteriores al tramo');

$fueraSab = GastosLocalesCalculo::diasFueraDelTramo($hSab);
chequear('octubre no tiene dias fuera del tramo (hoy ya es el 31)', 0, $fueraSab['2026-10']['dias']);
chequear('noviembre esta partido: 28, 29 y 30', [3, '2026-11-28'],
    [$fueraSab['2026-11']['dias'], $fueraSab['2026-11']['desde']]);
chequear('diciembre entero', [31, '2026-12-01'],
    [$fueraSab['2026-12']['dias'], $fueraSab['2026-12']['desde']]);
chequear('el ultimo mes del eje, septiembre de 2027, entero', 30, $fueraSab['2027-09']['dias']);

/* ================================================================ */
seccion('la proyeccion, sin inflacion: dias x diario');

$p0 = GastosLocalesCalculo::proyectar($loc, $hSab, $semSab, inflacionGL(0));
$ap = $p0['locales'][3];

chequear('una semana es 7 dias de gasto', 7000.0, $ap['partidas'][0]['importe']);
chequear('y va en la fecha de pago', ['SEMANA', '2026-11-02'],
    [$ap['partidas'][0]['tipo'], $ap['partidas'][0]['fecha']]);

$novMes = null;
$sepMes = null;

foreach ($ap['partidas'] as $pp) {
    if ($pp['tipo'] === 'MES' && $pp['mes'] === '2026-11') { $novMes = $pp; }
    if ($pp['tipo'] === 'MES' && $pp['mes'] === '2027-09') { $sepMes = $pp; }
}

chequear('noviembre partido lleva solo 3 dias', 3000.0, $novMes['importe']);
chequear('y cae el 28, que es de la columna del mes', '2026-11-28', $novMes['fecha']);
chequear('septiembre 2027, 30 dias', 30000.0, $sepMes['importe']);

/* 4 semanas (28 dias) + 3 de noviembre + 304 de dic-26 a sep-27 = 335 dias. Es
   la regla tal como se pidio: el tramo se paga por semanas enteras. */
chequear('el horizonte entero son 335 dias de gasto', 335000.0, $ap['total_proyectado']);

$r0 = GastosLocalesCalculo::resumen($p0['locales']);
chequear('la tarjeta: 90 dias de los tres locales', 99000.0, $r0['total_ventana']);
chequear('promedio mensual = diario x 30', 33000.0, $r0['mensual']);
chequear('lo proyectado es la suma de los locales', 335000.0 + 33500.0, $r0['proyectado']);
chequear('y cuenta el local sin egresos', 1, $r0['sin_egresos']);

$sumaPartidas = 0;
foreach ($p0['partidas'] as $pp) { $sumaPartidas += $pp['importe']; }
chequear('la suma de todas las partidas es el total de la tarjeta', $r0['proyectado'],
    $sumaPartidas);

chequear('el local sin egresos proyecta cero, no null', 0.0,
    $p0['locales'][9]['total_proyectado']);

/* ================================================================ */
seccion('el dia corrido fuera del tramo no se cuenta dos veces');

// Hoy martes 03/11: el tramo va hasta el lunes 30/11. Si es feriado, se paga el 01/12.
list($hMar, $semMar) = semanasGL('2026-11-03', ['2026-11-30']);
$pMar = GastosLocalesCalculo::proyectar([3 => $loc[3]], $hMar, $semMar, inflacionGL(0));

chequear('el tramo termina el lunes 30/11', '2026-11-30', GastosLocalesCalculo::finTramo($hMar));
chequear('la ultima semana se paga el martes 01/12', '2026-12-01',
    $semMar[count($semMar) - 1]['fecha']);

$serieMar = $hMar->serieVacia();
foreach ($pMar['partidas'] as $pp) { $hMar->acumular($serieMar, $pp['fecha'], $pp['importe']); }

chequear('esa semana cae en la columna de diciembre', 7000.0 + 31000.0, $serieMar['meses']['2026-12']);
chequear('y diciembre sigue contando sus 31 dias, no 30: la semana es de noviembre', 31,
    GastosLocalesCalculo::diasFueraDelTramo($hMar)['2026-12']['dias']);
chequear('noviembre no tiene columna mensual: todo el resto es del tramo', 0,
    GastosLocalesCalculo::diasFueraDelTramo($hMar)['2026-11']['dias']);

/* ================================================================ */
seccion('inflacion compuesta desde el mes en curso');

$infl = ['2026-11' => 2.0, '2026-12' => 3.0] + inflacionGL(0);
$pInf = GastosLocalesCalculo::proyectar([3 => $loc[3]], $hSab, $semSab, $infl);
$porMes = [];

foreach ($pInf['partidas'] as $pp) {
    if ($pp['tipo'] === 'MES') { $porMes[$pp['mes']] = $pp; }
}

chequear('el mes base es el mes en curso', '2026-10', $pInf['mes_base']);
chequear('una semana de noviembre lleva la de noviembre', 7000.0 * 1.02,
    $pInf['partidas'][0]['importe']);
chequear('diciembre compone: 31 x 1.000 x 1,02 x 1,03', 31000.0 * 1.02 * 1.03,
    $porMes['2026-12']['importe']);
chequear('y el tooltip dice el factor y los meses', true,
    strpos($porMes['2026-12']['tooltip'], 'x1,0506') !== false
    && strpos($porMes['2026-12']['tooltip'], '2026-11: 2,00 %') !== false);

$semDic = GastosLocalesCalculo::proyectar([3 => $loc[3]], $hMar, $semMar, $infl);
// Hoy es 03/11, asi que el mes base es noviembre: diciembre lleva solo su 3 %.
chequear('la semana pagada el 01/12 lleva la inflacion de diciembre (mes de pago)',
    7000.0 * 1.03, $semDic['partidas'][count($semMar) - 1]['importe']);
chequear('las semanas de noviembre, ninguna: noviembre es el mes base', 7000.0,
    $semDic['partidas'][0]['importe']);

/* ================================================================ */
seccion('inflacion faltante: null y aviso, nunca cero');

$pFalta = GastosLocalesCalculo::proyectar([3 => $loc[3]], $hSab, $semSab,
    inflacionGL(2, ['2027-01']));
$porMesF = [];

foreach ($pFalta['partidas'] as $pp) {
    if ($pp['tipo'] === 'MES') { $porMesF[$pp['mes']] = $pp; }
}

chequear('diciembre se proyecta igual', true, $porMesF['2026-12']['importe'] !== null);
chequear('enero queda en null', null, $porMesF['2027-01']['importe']);
chequear('y los siguientes tambien, porque se apoyan en enero', null,
    $porMesF['2027-05']['importe']);
chequear('con su motivo', 'SIN_INFLACION', $porMesF['2027-01']['motivo']);
chequear('la lista de faltantes es la que hay que cargar', ['2027-01'], $pFalta['faltan_inflacion']);
chequear('el tooltip lo explica', true,
    strpos($porMesF['2027-01']['tooltip'], 'NO en cero') !== false);
chequear('el total suma solo lo que se pudo calcular', true,
    $pFalta['locales'][3]['total_proyectado'] > 0
    && $pFalta['locales'][3]['total_proyectado'] < 335000.0 * 1.3);

/* ================================================================ */
seccion('sin tramo diario, todo va a los meses');

$hSinTramo = new Horizonte(0, 3, [], new DateTime('2026-10-31'));
chequear('ninguna semana', [], GastosLocalesCalculo::semanas($hSinTramo, $semSab));
chequear('y octubre no tiene dias despues de hoy', 0,
    GastosLocalesCalculo::diasFueraDelTramo($hSinTramo)['2026-10']['dias']);
chequear('noviembre entero va al mes', 30,
    GastosLocalesCalculo::diasFueraDelTramo($hSinTramo)['2026-11']['dias']);

/* ================================================================
   EL PROVEEDOR DEL TABLERO
   ================================================================ */
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/Providers/GastosLocalesProvider.php';

seccion('el proveedor: registrado, sin componentes, en pesos');

chequear('GASTOS_LOCALES existe y esta disponible', [true, true],
    [CashflowRegistry::existe('GASTOS_LOCALES'), CashflowRegistry::disponible('GASTOS_LOCALES')]);
$metaGL = CashflowRegistry::meta('GASTOS_LOCALES');
chequear('sirve solo PAGOS', ['PAGOS'], array_keys($metaGL['series']));
chequear('apunta a su pestana', 'gastos_locales', $metaGL['tab']);
chequear('no declara componentes: es un universo propio', false, isset($metaGL['componentes']));
chequear('se puede instanciar', 'GastosLocalesProvider',
    get_class(CashflowRegistry::instanciar('GASTOS_LOCALES')));

/** El proveedor con la lectura reemplazada por lo que se le pase */
class GastosLocalesProviderDoble extends GastosLocalesProvider {
    private $doble;
    public function __construct($codigo, $doble) { parent::__construct($codigo); $this->doble = $doble; }
    protected function modulo() { return $this->doble; }
}

class GastosLocalesDatosDoble {
    public $datos;
    public function __construct($datos) { $this->datos = $datos; }
    public function planilla($h) {
        if ($this->datos instanceof Exception) { throw $this->datos; }
        return $this->datos;
    }
}

seccion('la fila del tablero es la suma de los locales de la pestana');

$pProv = GastosLocalesCalculo::proyectar($loc, $hSab, $semSab, $infl);
$datosProv = ['leido' => true, 'locales' => $pProv['locales'], 'avisos' => [],
              'avisos_con_nivel' => [Aviso::nuevo(Aviso::WARNING, 'un aviso de la planilla')]];
$prov = new GastosLocalesProviderDoble('GASTOS_LOCALES', new GastosLocalesDatosDoble($datosProv));
$serieProv = $prov->series($hSab)['PAGOS'];

$sumaLocales = 0;
foreach ($pProv['locales'] as $l) { $sumaLocales += $l['total_proyectado']; }

chequear('el total de la fila es la suma de las filas de la pestana', $sumaLocales,
    array_sum($serieProv['dias']) + array_sum($serieProv['meses']));
chequear('el lunes 02/11 lleva las tres semanas de los locales, con la inflacion de noviembre',
    (7000.0 + 700.0 + 0.0) * 1.02, $serieProv['dias']['2026-11-02']);
chequear('la columna de hoy no recibe nada', 0, $serieProv['dias']['2026-10-31']);
chequear('noviembre mensual lleva solo los 3 dias de la cola', 3 * 1100.0 * 1.02,
    $serieProv['meses']['2026-11']);
chequear('nada fuera del horizonte', 0, $serieProv['fuera_horizonte']);
chequear('en pesos', 'ARS', $serieProv['moneda_origen']);
chequear('los avisos de la planilla suben al tablero con su nivel', true,
    in_array(Aviso::nuevo(Aviso::WARNING, 'un aviso de la planilla'), $prov->avisos(), true));

seccion('si la consulta falla, la fila va en cero con un aviso critico');

$datosFalla = ['leido' => false, 'locales' => [], 'avisos' => [],
               'avisos_con_nivel' => [Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los egresos')]];
$provFalla = new GastosLocalesProviderDoble('GASTOS_LOCALES', new GastosLocalesDatosDoble($datosFalla));

chequear('no rinde ninguna serie', [], $provFalla->series($hSab));
chequear('y el aviso es critico', Aviso::DANGER, $provFalla->avisos()[0]['nivel']);

$provLanza = new GastosLocalesProviderDoble('GASTOS_LOCALES',
    new GastosLocalesDatosDoble(new Exception('se cayo el servidor')));
chequear('un modulo que lanza no tumba el tablero', [], $provLanza->series($hSab));
chequear('y lo dice como critico', Aviso::DANGER, $provLanza->avisos()[0]['nivel']);

seccion('las cuentas excluidas: se validan en el servidor');

chequear('una lista con espacios, comas y repetidos se normaliza', ['522400', '531250'],
    GastosLocales::validarCuentas(' 531250, 522400;522400 '));
chequear('vacia vale: no se excluye ninguna', [], GastosLocales::validarCuentas(''));
chequearLanza('un codigo con letras se rechaza', function () {
    GastosLocales::validarCuentas('5224AB');
});
chequearLanza('la cuenta cero no existe', function () {
    GastosLocales::validarCuentas('000');
});

seccion('la consulta: los filtros que se confirmaron');

$fuenteGL = file_get_contents(__DIR__ . '/../Class/GastosLocales.php');
chequear('lee del servidor de locales', true, strpos($fuenteGL, "conectar('locales')") !== false);
chequear('solo locales propios y habilitados', true,
    strpos($fuenteGL, "D.CANAL = 'PROPIOS' AND D.HABILITADO = 1") !== false);
chequear('solo egresos de cuentas 5, sin la sucursal 1', true,
    strpos($fuenteGL, "A.D_H = 'D'") !== false && strpos($fuenteGL, "LIKE '5%'") !== false
    && strpos($fuenteGL, 'A.NRO_SUCURS <> 1') !== false);
chequear('la ventana son 90 dias que terminan ayer, sin hora', true,
    strpos($fuenteGL, 'A.FECHA < CAST(GETDATE() AS DATE)') !== false
    && strpos($fuenteGL, 'DATEADD(day, -" . GastosLocalesCalculo::DIAS_VENTANA') !== false);
chequear('las excluidas salen del parametro, no del codigo', true,
    strpos($fuenteGL, "NOT IN ('522400')") === false
    && strpos($fuenteGL, 'NOT IN ("') !== false);

$sqlFila = file_get_contents(__DIR__ . '/../sql/cashflow_estructura_gastos_locales.sql');
chequear('el script de la fila la crea en Costos Indirectos, orden 47, como egreso', true,
    strpos($sqlFila, "('GASTOS_LOCALES', 'Gastos Locales', 'COSTOS_INDIRECTOS', 'EGRESO', 1,") !== false
    && strpos($sqlFila, "'GASTOS_LOCALES', 'PAGOS', 47, 1)") !== false);
chequear('y es reejecutable', true,
    strpos($sqlFila, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'GASTOS_LOCALES')") !== false);
