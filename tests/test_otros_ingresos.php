<?php
/**
 * Otros Ingresos: Dolares Cuenta Comitente y Saldo de Inversiones.
 *
 * Lo que se prueba sin base son las reglas de validacion -que cero sea valido
 * y un negativo no- y el cableado del registro y del menu. La carga en si
 * necesita la base y se saltea sola.
 *
 * Los dos conceptos comparten la validacion y la plomeria; lo que NO comparten
 * es la moneda, y esa es la parte que las pruebas fijan: el saldo de
 * inversiones se guarda en pesos y su serie no se convierte.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../cashflow/Class/OtrosIngresos.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/CashflowEstructura.php';
require_once __DIR__ . '/../cashflow/Class/Menu.php';

/* ================================================================
   Validaciones de la carga
   ================================================================ */
seccion('el importe en dolares');

// Cero es VALIDO: significa que ese dia no habia dolares en la cuenta, y es un
// dato distinto de no haber cargado nada.
chequear('cero es valido', 0.0, OtrosIngresos::validarImporte(0));
chequear('un importe positivo tambien', 1500.5, OtrosIngresos::validarImporte(1500.5));
chequear('un numero como texto tambien', 250.0, OtrosIngresos::validarImporte('250'));
chequear('se redondea a dos decimales', 10.13, OtrosIngresos::validarImporte(10.1289));

chequearLanza('un negativo se rechaza', function () {
    OtrosIngresos::validarImporte(-100);
});

chequear('y el mensaje dice por que importa', true, (function () {
    try {
        OtrosIngresos::validarImporte(-100);
    } catch (Throwable $e) {
        return mb_stripos($e->getMessage(), 'restaría') !== false;
    }

    return false;
})());

chequearLanza('un texto que no es numero se rechaza', function () {
    OtrosIngresos::validarImporte('mil dolares');
});

chequearLanza('un importe vacio se rechaza', function () {
    OtrosIngresos::validarImporte('');
});

seccion('la fecha de la carga');

chequear('una fecha valida pasa', '2026-09-10', OtrosIngresos::validarFecha('2026-09-10'));

// A diferencia de la fecha de cobro manual de Cobranzas FR, aca SI se aceptan
// fechas pasadas: la carga describe cuantos dolares habia un dia dado, y ese
// dia pudo haber sido la semana pasada.
chequear('una fecha pasada tambien: describe un dia que ya paso',
    '2020-01-15', OtrosIngresos::validarFecha('2020-01-15'));

chequear('acepta un DateTime, que es lo que devuelve sqlsrv',
    '2026-10-05', OtrosIngresos::validarFecha(new DateTime('2026-10-05')));

chequearLanza('una fecha que no existe se rechaza', function () {
    OtrosIngresos::validarFecha('2026-02-30');
});

chequearLanza('un texto que no es fecha se rechaza', function () {
    OtrosIngresos::validarFecha('el martes');
});

/* ================================================================
   El registro
   ================================================================ */
seccion('DOLARES_COMITENTE esta enchufado al tablero');

$meta = CashflowRegistry::meta('DOLARES_COMITENTE');

chequear('esta registrado', true, $meta !== null);
chequear('y ya esta disponible', true, CashflowRegistry::disponible('DOLARES_COMITENTE'));
chequear('la serie es INGRESO, no DISPONIBLE', true,
    CashflowRegistry::serieExiste('DOLARES_COMITENTE', 'INGRESO'));
chequear('la serie vieja ya no existe', false,
    CashflowRegistry::serieExiste('DOLARES_COMITENTE', 'DISPONIBLE'));
chequear('enlaza a su propia pestana', 'dolares_comitente', $meta['tab']);
chequear('la moneda de origen es USD', 'USD', $meta['moneda']);

// El proveedor tiene que poder instanciarse: si el archivo o la clase no
// estuvieran, el registro devuelve null y la fila iria en cero sin decir por que.
$prov = CashflowRegistry::instanciar('DOLARES_COMITENTE');

chequear('el proveedor se instancia', true, $prov instanceof CashflowProvider);
chequear('y sabe con que codigo lo instanciaron', 'DOLARES_COMITENTE', $prov->codigo());

seccion('un proveedor de Otros Ingresos no puede tumbar el tablero');

require_once __DIR__ . '/../cashflow/Class/Providers/OtrosIngresosProvider.php';

class OtrosIngresosProviderRoto extends OtrosIngresosProvider {
    protected function calcular($h) {
        throw new Exception('base caida');
    }
}

$h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));
$roto = new OtrosIngresosProviderRoto('DOLARES_COMITENTE');

chequear('con la base caida no lanza', [], $roto->series($h));
chequear('deja un aviso', 1, count($roto->warnings()));
chequear('que nombra al modulo', true,
    strpos($roto->warnings()[0], 'DOLARES_COMITENTE') === 0);

// Un codigo que el proveedor no sirve no revienta: avisa y devuelve vacio.
$otro = new OtrosIngresosProvider('NO_ES_MIO');

chequear('un codigo desconocido no devuelve series', [], $otro->series($h));
chequear('y lo avisa', true,
    mb_stripos(implode(' ', $otro->warnings()), 'no tiene serie definida') !== false);

/* ================================================================
   La fila del tablero
   ================================================================ */
seccion('la fila valida contra el registro real');

$provs = [];

foreach (CashflowRegistry::todos() as $p) {
    $provs[$p['codigo']] = $p;
}

$r = CashflowEstructura::validar(
    [['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'DOLARES_COMITENTE', 'NOMBRE' => 'Dolares Cuenta Comitente',
      'SECCION' => 'ING', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'DOLARES_COMITENTE', 'ORIGEN_SERIE' => 'INGRESO',
      'ORDEN' => 65, 'ACTIVO' => 1]],
    $provs);

chequear('la fila es valida', true, $r['valido']);
chequear('y ya no avisa que el modulo no esta construido', false, (function () use ($r) {
    foreach ($r['advertencias'] as $a) {
        if (mb_stripos($a, 'todavía no está construido') !== false) {
            return true;
        }
    }

    return false;
})());

// La serie vieja quedaria colgada: es lo que arregla el UPDATE del script.
$r = CashflowEstructura::validar(
    [['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'DOLARES_COMITENTE', 'NOMBRE' => 'Dolares Cuenta Comitente',
      'SECCION' => 'ING', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'DOLARES_COMITENTE', 'ORIGEN_SERIE' => 'DISPONIBLE',
      'ORDEN' => 65, 'ACTIVO' => 1]],
    $provs);

chequear('apuntada a la serie vieja, la fila es invalida', false, $r['valido']);

/* ================================================================
   SALDO DE INVERSIONES

   Mismo circuito que los dolares, otra moneda. Lo que se prueba es
   justamente eso: que la moneda no se haya copiado junto con el resto.
   ================================================================ */
seccion('el saldo de inversiones se carga en pesos');

// La validacion del importe es LA MISMA para los dos conceptos: cero valido,
// negativo rechazado. Lo unico que cambia es como se nombra la moneda en el
// mensaje, asi que un validador por moneda serian dos copias de la misma
// cuenta.
chequear('cero es valido', 0.0, OtrosIngresos::validarImporte(0, 'pesos'));
chequear('un importe positivo tambien', 3500000.0,
    OtrosIngresos::validarImporte(3500000, 'pesos'));
chequear('se redondea a dos decimales', 10.13,
    OtrosIngresos::validarImporte(10.1289, 'pesos'));

chequearLanza('un negativo se rechaza', function () {
    OtrosIngresos::validarImporte(-1, 'pesos');
});

chequear('y el mensaje habla de pesos, no de dolares', true, (function () {
    try {
        OtrosIngresos::validarImporte(-1, 'pesos');
    } catch (Throwable $e) {
        return mb_stripos($e->getMessage(), 'en pesos') !== false;
    }

    return false;
})());

// Y el default sigue siendo dolares, asi que las llamadas viejas no cambian.
chequear('sin moneda, el mensaje sigue siendo el de dolares', true, (function () {
    try {
        OtrosIngresos::validarImporte(-1);
    } catch (Throwable $e) {
        return mb_stripos($e->getMessage(), 'en dólares') !== false;
    }

    return false;
})());

// La tabla y el campo del importe estan declarados una sola vez, y el campo es
// el que fija la moneda del concepto: si alguien lo cambiara a IMPORTE_USD sin
// tocar el resto, la pantalla seguiria diciendo pesos y el tablero convertiria.
chequear('la tabla del concepto es la del script',
    'RO_T_CASHFLOW_SALDO_INVERSIONES', OtrosIngresos::INVERSIONES['tabla']);
chequear('y el campo del importe es en PESOS',
    'IMPORTE_ARS', OtrosIngresos::INVERSIONES['campo']);
chequear('la de dolares sigue en USD',
    'IMPORTE_USD', OtrosIngresos::DOLARES['campo']);

seccion('SALDO_INVERSIONES esta enchufado al tablero');

$metaInv = CashflowRegistry::meta('SALDO_INVERSIONES');

chequear('esta registrado', true, $metaInv !== null);
chequear('y esta disponible', true, CashflowRegistry::disponible('SALDO_INVERSIONES'));
chequear('la serie es INGRESO', true,
    CashflowRegistry::serieExiste('SALDO_INVERSIONES', 'INGRESO'));
chequear('enlaza a su propia pestana', 'saldo_inversiones', $metaInv['tab']);

// La moneda del registro es ARS y no USD: es lo que dice que esta serie NO se
// convierte. Es informativo para el tablero, pero si dijera USD, quien lea el
// registro para entender la fila entendería otra cosa.
chequear('la moneda de origen es ARS', 'ARS', $metaInv['moneda']);

// Es el MISMO proveedor que los dolares: es un concepto mas de la categoria, no
// otro modelo de datos.
chequear('usa el mismo proveedor que los dolares',
    $meta['clase'], $metaInv['clase']);

$provInv = CashflowRegistry::instanciar('SALDO_INVERSIONES');

chequear('el proveedor se instancia', true, $provInv instanceof CashflowProvider);
chequear('y sabe con que codigo lo instanciaron', 'SALDO_INVERSIONES', $provInv->codigo());

seccion('la fila de inversiones valida contra el registro real');

$r = CashflowEstructura::validar(
    [['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'SALDO_INVERSIONES', 'NOMBRE' => 'Saldo de Inversiones',
      'SECCION' => 'ING', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'SALDO_INVERSIONES', 'ORIGEN_SERIE' => 'INGRESO',
      'ORDEN' => 66, 'ACTIVO' => 1]],
    $provs);

chequear('la fila es valida', true, $r['valido']);

// Las dos filas juntas tambien: son series distintas de proveedores distintos,
// asi que la regla de origen repetido no las toca.
$r = CashflowEstructura::validar(
    [['CODIGO' => 'ING', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'DOLARES_COMITENTE', 'NOMBRE' => 'Dolares Cuenta Comitente',
      'SECCION' => 'ING', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'DOLARES_COMITENTE', 'ORIGEN_SERIE' => 'INGRESO',
      'ORDEN' => 65, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'SALDO_INVERSIONES', 'NOMBRE' => 'Saldo de Inversiones',
      'SECCION' => 'ING', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'SALDO_INVERSIONES', 'ORIGEN_SERIE' => 'INGRESO',
      'ORDEN' => 66, 'ACTIVO' => 1]],
    $provs);

chequear('las dos filas de Otros Ingresos conviven', true, $r['valido']);

seccion('el proveedor de inversiones no puede tumbar el tablero');

class InversionesProviderRoto extends OtrosIngresosProvider {
    protected function calcular($h) {
        throw new Exception('base caida');
    }
}

$rotoInv = new InversionesProviderRoto('SALDO_INVERSIONES');

chequear('con la base caida no lanza', [], $rotoInv->series($h));
chequear('deja un aviso', 1, count($rotoInv->warnings()));
chequear('que nombra al modulo', true,
    strpos($rotoInv->warnings()[0], 'SALDO_INVERSIONES') === 0);

seccion('la pestana de inversiones esta en el menu y en el controller');

chequear('no es un placeholder', false, Menu::esPlaceholder('saldo_inversiones'));

// El archivo de la pestana y su JS tienen que existir: si faltaran, el menu
// prometeria una pantalla que no carga.
chequear('existe el archivo de la pestana', true,
    file_exists(__DIR__ . '/../cashflow/Tabs/saldo_inversiones.php'));
chequear('existe su JS', true,
    file_exists(__DIR__ . '/../cashflow/Js/OtrosIngresos-Saldo_inversiones.js'));
chequear('existe su CSS', true,
    file_exists(__DIR__ . '/../cashflow/Css/OtrosIngresos-Saldo_inversiones.css'));
chequear('existe el script SQL', true,
    file_exists(__DIR__ . '/../sql/cashflow_saldo_inversiones.sql'));

// Sin la entrada en $validTabs, TabController devuelve 400 y la pestana no
// abre, aunque el menu la muestre.
chequear('esta en los tabs validos del controller', true,
    strpos(file_get_contents(__DIR__ . '/../cashflow/Controller/TabController.php'),
        "'saldo_inversiones'") !== false);

// Y el item del menu de Otros Ingresos: la categoria ahora tiene dos, y el
// contador n/m del sidebar cuenta las dos como pestanas con datos.
$catOtros = null;

foreach (Menu::estructura()['categorias'] as $c) {
    if ($c['codigo'] === 'OtrosIngresos') {
        $catOtros = $c;
    }
}

chequear('la categoria Otros Ingresos existe', true, $catOtros !== null);
chequear('y ahora tiene dos items', 2, $catOtros['total']);
chequear('las dos cuentan como pestanas con datos', 2, $catOtros['con_datos']);
chequear('el tab de la segunda es saldo_inversiones',
    'saldo_inversiones', $catOtros['items'][1]['tab']);

/* ================================================================
   Exportaciones Tasky ya tiene modulo: el detalle esta en
   test_exportaciones_tasky.php. Aca solo que dejo de ser placeholder.
   ================================================================ */
seccion('Exportaciones Tasky');

chequear('ya tiene modulo: no es placeholder',
    false, Menu::esPlaceholder('exportaciones_tasky'));
chequear('y la pestana de dolares tampoco',
    false, Menu::esPlaceholder('dolares_comitente'));

/* ================================================================
   Contra la base
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$otros = new OtrosIngresos();

if (!$otros->tablaCreada()) {
    Pruebas::saltear('falta correr sql/cashflow_dolares_comitente.sql');
    return;
}

$filas = $otros->getDolaresComitente();

chequear('getDolaresComitente devuelve un array', true, is_array($filas));

// Una fecha no puede tener dos importes vigentes: es lo que garantiza que la
// fila del tablero no cuente la misma plata dos veces.
$porFecha = [];

foreach ($filas as $f) {
    $porFecha[$f['FECHA']] = isset($porFecha[$f['FECHA']]) ? $porFecha[$f['FECHA']] + 1 : 1;
}

chequear('ninguna fecha tiene dos importes vigentes',
    [], array_keys(array_filter($porFecha, function ($n) { return $n > 1; })));

/* ================================================================
   LA VALUACION DE LOS DOLARES: LA CUENTA ABIERTA

   El criterio cambio: antes se valuaba con el CIERRE DEL MES de cada carga y
   ahora con la ULTIMA COTIZACION CONOCIDA A SU FECHA. El motivo es que el saldo
   en pesos del tablero tiene que poder atarse a una cotizacion real y fechada;
   el cierre del mes en curso no existe todavia, y el de un mes viejo valua con
   una cotizacion de semanas despues.

   valuarDolares() es la UNICA cuenta: la usan el proveedor -para el tablero- y
   la pestana -para la grilla-. Si cada uno multiplicara por su cuenta, los dos
   totales podrian discrepar y no habria forma de saber cual esta mal.
   ================================================================ */
seccion('valuacion de dolares: se inyecta la cotizacion, sin base');

/** Cotizacion de mentira: una serie diaria con agujeros, como la real. */
class CotizacionFalsa extends Cotizacion {
    /** Fecha => TCC. Faltan dias a proposito: sabados, domingos y feriados. */
    public $serie = [
        '2026-09-01' => 1485.0,
        '2026-09-03' => 1490.0,
        '2026-09-06' => 1480.0
    ];

    public function __construct() { /* a proposito: no abre conexion */ }

    public function ultimaHasta($fecha) {
        $f = self::dia($fecha);
        $mejor = null;

        foreach ($this->serie as $dia => $tcc) {
            if ($dia <= $f && ($mejor === null || $dia > $mejor)) {
                $mejor = $dia;
            }
        }

        return ($mejor === null) ? null : ['fecha' => $mejor, 'tcc' => $this->serie[$mejor]];
    }
}

$oi = new OtrosIngresos();

$cargas = [
    ['FECHA' => '2026-09-04', 'IMPORTE_USD' => 1000.0],   // sabado: vale la del 03
    ['FECHA' => '2026-09-08', 'IMPORTE_USD' => 2000.0],   // posterior a la ultima
    ['FECHA' => '2020-01-01', 'IMPORTE_USD' => 500.0]     // anterior a toda la serie
];

$v = $oi->valuarDolares($cargas, new CotizacionFalsa());

chequear('devuelve una fila por carga', 3, count($v['filas']));

// Un sabado no inventa una cotizacion: usa la del viernes Y DICE que es del
// viernes. Sin la fecha, el numero en pesos no se puede explicar.
chequear('un dia sin cotizacion toma la anterior', 1490.0, $v['filas'][0]['TC']);
chequear('y dice de que dia salio', '2026-09-03', $v['filas'][0]['TC_FECHA']);
chequear('la cuenta es USD x cotizacion', 1490000.0, $v['filas'][0]['IMPORTE_ARS']);

// Una carga posterior a la ultima cotizacion cargada usa esa ultima, que es
// justamente lo que el criterio de cierre mensual no podia contestar.
chequear('una carga futura usa la ultima conocida', 1480.0, $v['filas'][1]['TC']);
chequear('con su fecha', '2026-09-06', $v['filas'][1]['TC_FECHA']);
chequear('y su cuenta', 2960000.0, $v['filas'][1]['IMPORTE_ARS']);

// Sin ninguna cotizacion anterior NO se asume nada: null, no cero. Un cero se
// leeria como "esos dolares valen cero pesos".
chequear('sin cotizacion anterior, el tipo de cambio es null', null, $v['filas'][2]['TC']);
chequear('la fecha tambien', null, $v['filas'][2]['TC_FECHA']);
chequear('y el importe en pesos, null y no cero', null, $v['filas'][2]['IMPORTE_ARS']);
chequear('esos dolares se informan aparte', 500.0, $v['sin_cotizacion']);
chequear('y no hubo error de origen', null, $v['error']);

chequear('sin cargas no hay nada que valuar y no se toca la base',
    ['filas' => [], 'sin_cotizacion' => 0.0, 'error' => null], $oi->valuarDolares([]));

seccion('normalizacion de un dia en Cotizacion');

chequear('acepta Y-m-d', '2026-09-15', Cotizacion::dia('2026-09-15'));
chequear('y recorta la hora', '2026-09-15', Cotizacion::dia('2026-09-15 13:45:00'));
chequear('y acepta un DateTime', '2026-09-15', Cotizacion::dia(new DateTime('2026-09-15')));
chequearLanza('una fecha inexistente se rechaza', function () { Cotizacion::dia('2026-02-30'); });
chequearLanza('un texto cualquiera se rechaza', function () { Cotizacion::dia('ayer'); });

/* ================================================================
   EL SALDO DE INVERSIONES YA NO ES UN INGRESO: ES STOCK DE COBERTURA
   ================================================================ */
seccion('el saldo de inversiones pasa a ser stock');

$metaInvStock = CashflowRegistry::meta('SALDO_INVERSIONES');

chequear('ofrece la serie STOCK',
    true, CashflowRegistry::serieExiste('SALDO_INVERSIONES', 'STOCK'));

// La serie vieja queda declarada para poder volver atras desde Parametros, pero
// son el MISMO dinero: activar las dos filas lo mostraria dos veces, y por eso
// van relacionadas en 'componentes'.
chequear('la serie vieja sigue declarada',
    true, CashflowRegistry::serieExiste('SALDO_INVERSIONES', 'INGRESO'));
chequear('y las dos estan declaradas como incompatibles',
    ['INGRESO'], $metaInvStock['componentes']['STOCK']);

$reglas = CashflowEstructura::validar(
    [['CODIGO' => 'COB', 'NOMBRE' => 'Cobertura', 'ROL' => 'DERIVADO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'STOCK_INV', 'NOMBRE' => 'Inversiones disponibles',
      'SECCION' => 'COB', 'TIPO' => 'STOCK_COBERTURA', 'COMPUTA' => 0,
      'ORIGEN_PROVIDER' => 'SALDO_INVERSIONES', 'ORIGEN_SERIE' => 'STOCK',
      'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'SALDO_INV', 'NOMBRE' => 'Saldo de Inversiones',
      'SECCION' => 'COB', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'SALDO_INVERSIONES', 'ORIGEN_SERIE' => 'INGRESO',
      'ORDEN' => 20, 'ACTIVO' => 1]]
);

chequear('tener las dos activas es un error de configuracion', false, $reglas['valido']);
