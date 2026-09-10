<?php
/**
 * Otros Ingresos: Dolares Cuenta Comitente.
 *
 * Lo que se prueba sin base son las reglas de validacion -que cero sea valido
 * y un negativo no- y el cableado del registro y del menu. La carga en si
 * necesita la base y se saltea sola.
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
   Exportaciones Tasky: solo el item de menu
   ================================================================ */
seccion('Exportaciones Tasky');

$exp = CashflowRegistry::meta('EXPORTACIONES');

chequear('se llama Exportaciones Tasky', 'Exportaciones Tasky', $exp['nombre']);
chequear('enlaza a su propia pestana', 'exportaciones_tasky', $exp['tab']);
chequear('sigue sin modulo: rinde cero y el tablero avisa',
    false, CashflowRegistry::disponible('EXPORTACIONES'));
chequear('la descripcion aclara que Tasky es la razon social en Uruguay', true,
    mb_stripos($exp['descripcion'], 'Uruguay') !== false);

// El estado NO se declara: se detecta por el include del placeholder. Asi una
// declaracion que quedo vieja se corrige sola.
chequear('el menu la detecta como placeholder',
    true, Menu::esPlaceholder('exportaciones_tasky'));
chequear('y la pestana de dolares NO lo es',
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
