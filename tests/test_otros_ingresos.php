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
