<?php
/**
 * LogisticaProvider: la fila Logistica del tablero.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. UNA FILA DE EGRESOS EN CERO SIN EXPLICACION. Es el modo de falla propio
 *      de este proveedor: falta el script, no hay fleteros, o los que hay no
 *      tienen horas. En los tres casos la fila muestra cero, y un cero en un
 *      egreso se lee como "no hay que pagar nada", que es lo contrario de lo
 *      que pasa. El tablero no falla ni avisa por su cuenta: el aviso lo tiene
 *      que poner el proveedor.
 *
 *   2. UN PROVEEDOR QUE TUMBA EL TABLERO. calcular() puede lanzar y series() lo
 *      envuelve, pero eso solo funciona si la subclase no rompe el contrato.
 *
 *   3. LA FILA SE SUMA DOS VECES. LOGISTICA es un universo propio: sus pagos NO
 *      son componentes de PROV_LOCALES ni de ninguna otra serie. Si alguna vez
 *      se declararan como componentes, el mismo egreso entraria dos veces y el
 *      cuadro cerraria igual.
 *
 *   4. EL MENU PROMETE UNA PESTANA QUE NO EXISTE. El estado 'datos' es una
 *      declaracion; el archivo tiene que haber dejado de ser un placeholder.
 *
 * El proveedor se prueba con un DOBLE del modulo, igual que
 * CobElectronicosProvider: los casos que hay que verificar -tabla sin crear,
 * ningun fletero- no se pueden montar en una base real sin borrar la tabla.
 */

require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/Menu.php';
require_once __DIR__ . '/../Class/Providers/LogisticaProvider.php';
require_once __DIR__ . '/../Class/CronogramaPagos.php';

/* ================================================================
   EL REGISTRO
   ================================================================ */
seccion('el proveedor esta registrado y disponible');

chequear('LOGISTICA existe en el registro', true, CashflowRegistry::existe('LOGISTICA'));
chequear('y ya esta disponible', true, CashflowRegistry::disponible('LOGISTICA'));
chequear('no figura como retirado', false, CashflowRegistry::retirado('LOGISTICA'));

$meta = CashflowRegistry::meta('LOGISTICA');

chequear('sirve la serie PAGOS', true, CashflowRegistry::serieExiste('LOGISTICA', 'PAGOS'));
chequear('y solo esa', ['PAGOS'], array_keys($meta['series']));
chequear('en pesos: no hay nada que convertir', 'ARS', $meta['moneda']);
chequear('apunta a su pestana', 'logistica_local', $meta['tab']);

/* NO DECLARA COMPONENTES. Sus pagos no son parte de ninguna otra serie: son un
   universo propio. Declararlos como componentes de PROV_LOCALES haria que el
   validador aceptara las dos filas activas a la vez, y el mismo egreso entraria
   dos veces. */
chequear('no declara componentes', false, isset($meta['componentes']));

chequear('se puede instanciar', 'LogisticaProvider',
    get_class(CashflowRegistry::instanciar('LOGISTICA')));

/* ================================================================
   EL DOBLE DEL MODULO
   ================================================================ */

/**
 * Un LogisticaProvider con el modulo reemplazado por lo que se le pase.
 *
 * Reemplaza modulo() y no la lectura de base: asi se prueba el proveedor
 * entero -el reparto en el eje, los avisos, el caso cero- sin SQL Server.
 */
class LogisticaProviderDoble extends LogisticaProvider {

    private $doble;

    public function __construct($codigo, $doble) {
        parent::__construct($codigo);
        $this->doble = $doble;
    }

    protected function modulo() {
        return $this->doble;
    }
}

/** Un modulo Logistica falso: devuelve la planilla que se le arme */
class LogisticaDoble {

    public $datos;

    public function __construct($datos) {
        $this->datos = $datos;
    }

    public function planilla($h) {
        return $this->datos;
    }
}

/** Arma lo que devuelve Logistica::planilla() a partir de unos fleteros */
function planillaDoble($fleteros, $h, $inflacion = [], $tablaCreada = true, $avisos = []) {
    require_once __DIR__ . '/../Class/LogisticaPlanilla.php';

    $habiles = [];
    $cursor = new DateTime('2026-08-01');

    while ($cursor <= new DateTime('2027-12-31')) {
        $habiles[$cursor->format('Y-m-d')] = (intval($cursor->format('N')) <= 5);
        $cursor->modify('+1 day');
    }

    $crono = CronogramaPagos::paraHorizonte($h, $habiles);
    $planilla = LogisticaPlanilla::calcular($fleteros, $h, $crono['pagos'], $inflacion);

    return [
        'planilla' => $planilla,
        'cronograma' => $crono['pagos'],
        'avisos' => array_merge($planilla['avisos'], $avisos),
        'tabla_creada' => $tablaCreada
    ];
}

$hPr = new Horizonte(28, 12, [], new DateTime('2026-09-25'));

require_once __DIR__ . '/../Class/Inflacion.php';

$inflaPr = [];

for ($i = 0; $i < 24; $i++) {
    $inflaPr[Inflacion::mesMas('2026-07', $i)] = 2.0;
}

/* ================================================================
   LA SERIE
   ================================================================ */
seccion('la serie PAGOS contra el eje');

$fleteros = [[
    'COD_PROVEE' => 'OGGIBA', 'NOMBRE' => 'BARONE GIANLUCA',
    'HORAS_MES' => 100, 'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09'
]];

$prov = new LogisticaProviderDoble('LOGISTICA',
    new LogisticaDoble(planillaDoble($fleteros, $hPr, $inflaPr)));

$series = $prov->series($hPr);

chequear('devuelve una sola serie', ['PAGOS'], array_keys($series));

$s = $series['PAGOS'];

chequear('la moneda de origen es ARS', 'ARS', $s['moneda_origen']);
chequear('y no hay tipo de cambio que auditar', null, $s['tipo_cambio']);
chequear('nada quedo sin fecha', 0.0, floatval($s['sin_fecha']));

/* EL REPARTO EN EL EJE. Es la misma propiedad que prueba
   test_logistica_planilla, pero verificada DESPUES de normalizar(): las claves
   que un proveedor cuelga y no estan en la lista cerrada de la clase base se
   pierden en silencio, y eso ya paso una vez en este modulo. */
chequear('el pago del 9/10 esta en su columna diaria', 50000.0, $s['dias']['2026-10-09']);
chequear('y Oct-26 lleva solo la otra mitad', 50000.0, $s['meses']['2026-10']);
chequear('noviembre, fuera del tramo, lleva su mes completo', 100000.0, $s['meses']['2026-11']);

/* LA COLUMNA DE HOY NUNCA RECIBE NADA, aunque el tramo diario arranque hoy: el
   pago de hoy ya se hizo. */
chequear('la columna de hoy queda vacia', 0.0, floatval($s['dias']['2026-09-25']));
chequear('y la de septiembre tambien', 0.0, floatval($s['meses']['2026-09']));

seccion('un proveedor sin problemas no avisa de lo ya pagado');

/* Lo excluido por fecha NO se avisa: no es plata que el tablero informe de
   menos, es plata que ya salio. Avisarla todos los dias seria ruido sobre algo
   que no hay que hacer. Mismo criterio que CobElectronicosProvider. */
$textos = implode(' ', $prov->warnings());

chequear('no avisa de los pagos ya hechos', false, strpos($textos, 'ya se hizo') !== false);
chequear('ni de nada: la fila tiene datos', [], $prov->warnings());

/* ================================================================
   LA FILA EN CERO SIEMPRE SE EXPLICA
   ================================================================ */
seccion('sin el script, la fila va en cero Y lo dice');

$sinTabla = new LogisticaProviderDoble('LOGISTICA',
    new LogisticaDoble(planillaDoble([], $hPr, $inflaPr, false)));

$sT = $sinTabla->series($hPr)['PAGOS'];

chequear('la serie va en cero', 0.0, array_sum($sT['dias']) + array_sum($sT['meses']));

$avisosSinTabla = implode(' ', $sinTabla->warnings());

chequear('hay un aviso', true, count($sinTabla->warnings()) > 0);
chequear('que dice que la fila va en CERO', true,
    strpos($avisosSinTabla, 'CERO') !== false);
chequear('y que un cero no significa que no haya que pagar', true,
    strpos($avisosSinTabla, 'no significa') !== false);

seccion('sin fleteros cargados, la fila va en cero Y lo dice');

$sinFleteros = new LogisticaProviderDoble('LOGISTICA',
    new LogisticaDoble(planillaDoble([], $hPr, $inflaPr, true)));

$sF = $sinFleteros->series($hPr)['PAGOS'];

chequear('la serie va en cero', 0.0, array_sum($sF['dias']) + array_sum($sF['meses']));
chequear('y el aviso manda a darlos de alta', true,
    strpos(implode(' ', $sinFleteros->warnings()), 'Parámetros') !== false);

seccion('con fleteros pero sin horas, la fila va en cero Y lo dice');

$sinHoras = new LogisticaProviderDoble('LOGISTICA',
    new LogisticaDoble(planillaDoble([
        ['COD_PROVEE' => 'OGDARI', 'NOMBRE' => 'RIOS', 'HORAS_MES' => null,
         'VALOR_HORA_BASE' => 1000, 'MES_BASE' => '2026-09']
    ], $hPr, $inflaPr, true)));

$sH = $sinHoras->series($hPr)['PAGOS'];
$avisosSinHoras = implode(' ', $sinHoras->warnings());

chequear('la serie va en cero', 0.0, array_sum($sH['dias']) + array_sum($sH['meses']));
chequear('el aviso cuenta cuantos fleteros hay', true,
    strpos($avisosSinHoras, '1 fletero') !== false);
chequear('nombra al que le falta algo', true, strpos($avisosSinHoras, 'RIOS') !== false);
chequear('y aclara que NO significa que no haya que pagarles', true,
    strpos($avisosSinHoras, 'NO significa') !== false);

/* ================================================================
   NUNCA TUMBA EL TABLERO
   ================================================================ */
seccion('un modulo que lanza no tumba el tablero');

class LogisticaQueLanza {
    public function planilla($h) {
        throw new Exception('la base no responde');
    }
}

$roto = new LogisticaProviderDoble('LOGISTICA', new LogisticaQueLanza());
$sR = $roto->series($hPr);

chequear('series() no lanza y devuelve un mapa', true, is_array($sR));
chequear('sin ninguna serie: calcular() no llego a devolver nada', [], array_keys($sR));
chequear('y el aviso dice que paso', true,
    strpos(implode(' ', $roto->warnings()), 'la base no responde') !== false);
chequear('nombrando al proveedor', true,
    strpos(implode(' ', $roto->warnings()), 'LOGISTICA') !== false);

/* ================================================================
   LO QUE CAE FUERA DEL HORIZONTE SE INFORMA
   ================================================================ */
seccion('un pago posterior al horizonte se informa, no se descarta');

/* Un horizonte de UN solo mes: los once meses restantes del cronograma no
   existen, asi que la planilla no genera pagos para ellos. Lo que se verifica
   es lo contrario: que con el horizonte completo no quede nada afuera, porque
   el cronograma se arma contra el mismo eje. */
$hCorto = new Horizonte(5, 1, [], new DateTime('2026-09-25'));
$provCorto = new LogisticaProviderDoble('LOGISTICA',
    new LogisticaDoble(planillaDoble($fleteros, $hCorto, $inflaPr)));

$sC = $provCorto->series($hCorto)['PAGOS'];

chequear('con el eje de un mes no queda nada afuera', 0.0, floatval($sC['fuera_horizonte']));
chequear('porque el cronograma se arma contra el mismo eje',
    0.0, array_sum($sC['dias']) + array_sum($sC['meses']));

/* ================================================================
   EL MENU
   ================================================================ */
seccion('la pestana dejo de ser un placeholder');

chequear('logistica_local ya no es un placeholder', false,
    Menu::esPlaceholder('logistica_local'));
chequear('y el menu la declara con datos', Menu::DATOS,
    Menu::estado('logistica_local', Menu::DATOS));

$porTabLog = [];

foreach (Menu::estructuraCompleta()['categorias'] as $cat) {
    foreach ($cat['items'] as $i) {
        $porTabLog[$i['tab']] = $i;
        $catDe[$i['tab']] = $cat;
    }
}

chequear('esta en la categoria Proveedores', 'Proveedores',
    $catDe['logistica_local']['codigo']);
chequear('con estado datos', Menu::DATOS, $porTabLog['logistica_local']['estado']);
chequear('y sin marca: el caso normal no se marca', '',
    $porTabLog['logistica_local']['icono_estado']);
chequear('la categoria pasa a tener sus 2 pestanas con datos',
    2, $catDe['logistica_local']['con_datos']);
