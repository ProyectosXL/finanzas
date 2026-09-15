<?php
/**
 * Seccion Cobertura: el stock de inversiones y su aplicacion por fecha.
 *
 * Lo que se prueba aca es que la cobertura NO necesito ninguna regla nueva en el
 * motor: la fila de uso es una fila de movimiento comun, y lo que hace que los
 * dos flujos netos digan cosas distintas es UNICAMENTE donde esta puesta. Si
 * algun dia alguien mueve esa fila, estas pruebas se caen, que es exactamente lo
 * que tienen que hacer.
 *
 * La parte que toca la base se saltea sola si no hay conexion.
 */

require_once __DIR__ . '/../cashflow/Class/Cashflow.php';
require_once __DIR__ . '/../cashflow/Class/Cobertura.php';

/* ================================================================
   LOS TIPOS NUEVOS
   La lista de PHP y el CHECK del DDL son la misma lista escrita dos veces.
   Aca se fija la de PHP; la de la base la amplia sql/cashflow_cobertura.sql.
   ================================================================ */
seccion('los dos tipos de fila de cobertura');

chequear('STOCK_COBERTURA es un tipo valido',
    true, in_array('STOCK_COBERTURA', CashflowEstructura::TIPOS, true));
chequear('USO_COBERTURA tambien',
    true, in_array('USO_COBERTURA', CashflowEstructura::TIPOS, true));

// El uso es un MOVIMIENTO: la plata se mueve de verdad y tiene que entrar al
// arrastre del saldo.
chequear('el uso de cobertura es una fila de movimiento',
    true, in_array('USO_COBERTURA', CashflowEstructura::TIPOS_MOVIMIENTO, true));
chequear('y suma con signo positivo', 1, CashflowEstructura::signo('USO_COBERTURA'));

// El stock NO. Es plata que esta, no plata que se mueve.
chequear('el stock NO es una fila de movimiento',
    false, in_array('STOCK_COBERTURA', CashflowEstructura::TIPOS_MOVIMIENTO, true));
chequear('y no lleva signo', 0, CashflowEstructura::signo('STOCK_COBERTURA'));

// Los dos traen datos de un modulo, asi que el validador les exige origen.
chequear('el stock necesita origen de datos',
    true, in_array('STOCK_COBERTURA', CashflowEstructura::TIPOS_CON_ORIGEN, true));
chequear('el uso tambien',
    true, in_array('USO_COBERTURA', CashflowEstructura::TIPOS_CON_ORIGEN, true));

// Ninguno de los dos los calcula el motor.
chequear('el stock no es derivado', false, CashflowEstructura::esDerivada('STOCK_COBERTURA'));
chequear('el uso tampoco', false, CashflowEstructura::esDerivada('USO_COBERTURA'));
chequear('el stock no es una fila de saldo', false, CashflowEstructura::esSaldo('STOCK_COBERTURA'));

/* ================================================================
   VALIDACIONES PURAS
   Son las que deciden que se puede guardar, y corren sin base.
   ================================================================ */
seccion('validacion del importe: el negativo vale, el cero no');

chequear('un importe positivo pasa', 500000.0, Cobertura::validarImporte(500000));

// El negativo es devolver plata a la inversion, que es una decision tan real
// como aplicarla.
chequear('un negativo tambien', -500000.0, Cobertura::validarImporte(-500000));
chequear('se redondea a dos decimales', 1234.57, Cobertura::validarImporte(1234.5678));

// El cero NO es una aplicacion de cero pesos: es no tener ninguna, y para eso
// esta la baja, que ademas deja rastro en el historial.
chequearLanza('el cero se rechaza', function () { Cobertura::validarImporte(0); });
chequearLanza('un texto se rechaza', function () { Cobertura::validarImporte('mucho'); });
chequearLanza('un vacio se rechaza', function () { Cobertura::validarImporte(''); });
chequearLanza('un null se rechaza', function () { Cobertura::validarImporte(null); });

seccion('validacion de la fecha');

chequear('una fecha valida se normaliza', '2026-09-17', Cobertura::validarFecha('2026-09-17'));

// A diferencia de la fecha de cobro manual de Cobranzas FR, aca SI se aceptan
// fechas pasadas: una aplicacion de ayer es una que ya se hizo.
chequear('se acepta una fecha pasada', '2020-01-15', Cobertura::validarFecha('2020-01-15'));
chequearLanza('una fecha inventada se rechaza',
    function () { Cobertura::validarFecha('2026-02-30'); });
chequearLanza('un texto cualquiera se rechaza',
    function () { Cobertura::validarFecha('manana'); });

seccion('validacion del origen del fondo');

chequear('sin origen se asume el de defecto',
    'INVERSIONES', Cobertura::validarOrigen(null));
chequear('vacio tambien', 'INVERSIONES', Cobertura::validarOrigen(''));
chequear('se normaliza a mayusculas', 'DOLARES', Cobertura::validarOrigen(' dolares '));

// Un origen que nadie declaro no se guarda como vino ni se descarta en
// silencio: es una clave, y una clave desconocida no se puede agrupar.
chequearLanza('un origen no declarado se rechaza',
    function () { Cobertura::validarOrigen('CRIPTO'); });

chequear('los tres origenes del Excel estan declarados',
    ['INVERSIONES', 'SUSCRIPCION', 'DOLARES'], array_keys(Cobertura::ORIGENES));

seccion('observacion');

chequear('una observacion vacia queda en null', null, Cobertura::normalizarObservacion('  '));
chequear('se recorta al largo de la columna',
    200, strlen(Cobertura::normalizarObservacion(str_repeat('x', 500))));

/* ================================================================
   EL MOTOR, CON LA ESTRUCTURA DE COBERTURA

   Escenario: 3 dias + 3 meses, hoy = 2026-09-06, y una estructura como la
   que deja sql/cashflow_cobertura.sql:

       Disponible    saldo inicial 1000 el 06/09
       Ingresos      cobros 100 el 06/09, 200 el 07/09
       Egresos       pagos 900 el 07/09  -> el 07/09 queda en rojo
       Resultados    Flujo Neto (sin cobertura)
       Cobertura     stock 5000 / uso 400 el 07/09 / Flujo Neto (con cobertura)
                     / Saldo Final
   ================================================================ */
seccion('el motor con la seccion Cobertura');

class EstructuraCobertura {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosCobertura extends Parametros {
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() {
        return ['horizonte_dias' => 3, 'horizonte_meses' => 3];
    }
    public function getFeriadosComercio($map = null) { return []; }
}

class CashflowCobertura extends Cashflow {
    public $series = [];
    protected function pedirSeries($h, $filas) { return $this->series; }
}

$hc = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

$sec = function ($codigo, $rol, $orden, $padre = null) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $codigo, 'ROL' => $rol,
            'ID_PADRE' => $padre, 'ORDEN' => $orden, 'ACTIVO' => 1];
};

$fil = function ($id, $codigo, $seccion, $tipo, $orden, $prov = null, $serie = null, $computa = 1) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
            'TIPO' => $tipo, 'COMPUTA' => $computa, 'ORIGEN_PROVIDER' => $prov,
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1];
};

$serie = function ($h, $dias = [], $meses = []) {
    $s = $h->serieVacia();
    foreach ($dias as $k => $v)  { $s['dias'][$k] = $v; }
    foreach ($meses as $k => $v) { $s['meses'][$k] = $v; }
    $s['moneda_origen'] = 'ARS';
    $s['tipo_cambio'] = null;
    $s['fuera_horizonte'] = 0;
    $s['sin_fecha'] = 0;
    $s['warnings'] = [];
    return $s;
};

$ec = new EstructuraCobertura();
$ec->secciones = [
    $sec('DISP', 'SALDO', 10, 'ING_TOT'),
    $sec('VTA', 'MOVIMIENTO', 20, 'ING_TOT'),
    $sec('ING_TOT', 'DERIVADO', 25),
    $sec('EGR', 'MOVIMIENTO', 30, 'EGR_TOT'),
    $sec('EGR_TOT', 'DERIVADO', 35),
    $sec('RES', 'DERIVADO', 40),
    $sec('COB', 'DERIVADO', 50),
];
$ec->filas = [
    $fil(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    $fil(2, 'COBROS', 'DISP', 'INGRESO', 20, 'VENTAS', 'COBRANZA'),
    $fil(3, 'SUB_DISP', 'DISP', 'SUBTOTAL', 30),
    $fil(4, 'VTA_CANAL', 'VTA', 'INGRESO', 10, 'VENTAS', 'COBRANZA_LOCALES'),
    $fil(5, 'SUB_VTA', 'VTA', 'SUBTOTAL', 20),
    $fil(6, 'SUB_ING', 'ING_TOT', 'SUBTOTAL', 10),
    $fil(7, 'PAGOS', 'EGR', 'EGRESO', 10, 'COMEX_PROV_EXT', 'PAGOS'),
    $fil(8, 'SUB_EGR_SEC', 'EGR', 'SUBTOTAL', 20),
    $fil(9, 'SUB_EGR', 'EGR_TOT', 'SUBTOTAL', 10),
    $fil(10, 'FLUJO', 'RES', 'FLUJO_NETO', 10),
    $fil(11, 'STOCK', 'COB', 'STOCK_COBERTURA', 10, 'SALDO_INVERSIONES', 'STOCK', 0),
    $fil(12, 'USO', 'COB', 'USO_COBERTURA', 20, 'COBERTURA', 'APLICACION'),
    $fil(13, 'FLUJO_COB', 'COB', 'FLUJO_NETO', 30),
    $fil(14, 'SALDO_FIN', 'COB', 'SALDO_FINAL', 40),
];

$motorC = new CashflowCobertura($ec, new ParametrosCobertura(), $hc);
$motorC->series = [
    'SALDOS' => ['DISPONIBLE' => $serie($hc, ['2026-09-06' => 1000])],
    'VENTAS' => [
        'COBRANZA' => $serie($hc, ['2026-09-06' => 100, '2026-09-07' => 200]),
        'COBRANZA_LOCALES' => $serie($hc, [], ['2026-10' => 300]),
    ],
    'COMEX_PROV_EXT' => ['PAGOS' => $serie($hc, ['2026-09-07' => 900])],
    'SALDO_INVERSIONES' => ['STOCK' => $serie($hc, ['2026-09-06' => 5000])],
    'COBERTURA' => ['APLICACION' => $serie($hc, ['2026-09-07' => 400])],
];

$tc = $motorC->proyectar();
$pc = [];
foreach ($tc['filas'] as $f) { $pc[$f['codigo']] = $f; }

seccion('el stock no va en ninguna columna de fecha');

// Es un stock, no un flujo. Un importe en la columna del 06/09 diria que ese
// dia entra plata, y ademas lo sumaria el Total de esa vista.
chequear('la columna del 06/09 va en null', null, $pc['STOCK']['dias']['2026-09-06']);
chequear('la del 07/09 tambien', null, $pc['STOCK']['dias']['2026-09-07']);
chequear('y las mensuales', null, $pc['STOCK']['meses']['2026-10']);

// El importe se muestra UNICAMENTE en la columna Total, y es el mismo en las
// tres vistas: lo disponible no depende del tramo que se elija mirar.
chequear('el total del tramo es el stock', 5000.0, $pc['STOCK']['total_tramo']);
chequear('el total mensual tambien', 5000.0, $pc['STOCK']['total_meses']);
chequear('y el del horizonte', 5000.0, $pc['STOCK']['total_horizonte']);

// Y no entra en ninguna suma del cuadro.
chequear('el stock no suma en el flujo neto sin cobertura',
    1100.0, $pc['FLUJO']['dias']['2026-09-06']);
chequear('ni en el saldo final', 1100.0, $pc['SALDO_FIN']['dias']['2026-09-06']);

seccion('la posicion del uso de cobertura es lo unico que separa los dos flujos');

// El 07/09: entran 200, salen 900. Sin cobertura, -700.
chequear('flujo sin cobertura del 07/09 = 200 - 900', -700.0, $pc['FLUJO']['dias']['2026-09-07']);

// El uso esta DEBAJO de esa fila, asi que el alcance posicional lo excluye solo.
chequear('el uso se muestra en su columna', 400.0, $pc['USO']['dias']['2026-09-07']);

// Y ARRIBA de la otra, que por eso lo incluye. No hay ninguna regla nueva: es
// un FLUJO_NETO comun sumando lo que tiene encima.
chequear('flujo CON cobertura del 07/09 = -700 + 400', -300.0, $pc['FLUJO_COB']['dias']['2026-09-07']);
chequear('el dia sin cobertura da igual en las dos filas',
    $pc['FLUJO']['dias']['2026-09-06'], $pc['FLUJO_COB']['dias']['2026-09-06']);

seccion('el arrastre recoge la cobertura');

// El arrastre usa TODOS los movimientos, sin limite posicional, asi que el uso
// entra aunque el saldo final este mas abajo.
chequear('saldo final del 06/09 = 1000 + 100', 1100.0, $pc['SALDO_FIN']['dias']['2026-09-06']);
chequear('saldo final del 07/09 = 1100 + 200 - 900 + 400',
    800.0, $pc['SALDO_FIN']['dias']['2026-09-07']);
chequear('sin la cobertura habria quedado en 400',
    400.0, $pc['SALDO_FIN']['dias']['2026-09-07'] - $pc['USO']['dias']['2026-09-07']);

$descuadreC = false;
foreach ($tc['warnings'] as $w) {
    if (strpos($w, 'arrastre del saldo no cierra') !== false) { $descuadreC = true; }
}

// EL INVARIANTE NO SE TOCA: cierre[n] == apertura[n+1]. Depende del aporte y de
// los movimientos, y la cobertura es un movimiento mas.
chequear('el invariante de arrastre sigue cerrando', false, $descuadreC);

seccion('la cobertura no infla los indicadores de Ingresos ni de Egresos');

$kdc = $tc['kpi']['dias'];

// Mover plata de una inversion a la cuenta no es un ingreso del negocio.
chequear('ingresos del tramo = 1000 de saldo + 300 de cobros', 1300.0, $kdc['ingresos']);
chequear('egresos del tramo', 900.0, $kdc['egresos']);
chequear('flujo = 1300 - 900, sin la cobertura', 400.0, $kdc['flujo']);
chequear('la cobertura se informa aparte', 400.0, $kdc['cobertura']);

// Pero SI aparece en el saldo de cierre y en el minimo, que salen del arrastre:
// tapar el peor saldo proyectado es para lo que existe.
chequear('el saldo de cierre si la incluye', 800.0, $kdc['saldo_cierre']);
chequear('el indicador de flujo coincide con la fila sin cobertura',
    $pc['FLUJO']['total_tramo'], $kdc['flujo']);

/* ================================================================
   SUBTOTALES ANIDADOS: EL ALCANCE SE SOLAPA Y NO SE DUPLICA NADA

   SUB_ING abarca ING_TOT + DISP + VTA, o sea que su alcance contiene a
   SUB_DISP y a SUB_VTA. Si un subtotal sumara subtotales, el Total Ingresos
   contaria todo dos veces. No lo hace porque sumarMovimientos() solo mira
   TIPOS_MOVIMIENTO, y SUBTOTAL no esta ahi.
   ================================================================ */
seccion('subtotales anidados: el total de la madre no duplica a los de las hijas');

// El 06/09: saldo 1000 + cobros 100 en DISP; nada en VTA.
chequear('subtotal de la hija incluye su saldo y sus cobros',
    1100.0, $pc['SUB_DISP']['dias']['2026-09-06']);
chequear('la otra hija no tiene nada ese dia', 0.0, $pc['SUB_VTA']['dias']['2026-09-06']);

// LO IMPORTANTE: 1100 y no 2200.
chequear('el total de la madre es 1100, no 2200',
    1100.0, $pc['SUB_ING']['dias']['2026-09-06']);
chequear('y en el mes, 300 y no 600', 300.0, $pc['SUB_ING']['meses']['2026-10']);
chequear('el subtotal de la hija mensual', 300.0, $pc['SUB_VTA']['meses']['2026-10']);

// Lo mismo del lado de los egresos, que ademas van con signo negativo.
chequear('el total de egresos no duplica al de su unica hija',
    -900.0, $pc['SUB_EGR']['dias']['2026-09-07']);
chequear('que vale lo mismo porque hay una sola',
    $pc['SUB_EGR_SEC']['dias']['2026-09-07'], $pc['SUB_EGR']['dias']['2026-09-07']);

// Y el Flujo Neto tampoco: suma filas de movimiento y de saldo, nunca
// subtotales. 1000 + 100 + 0 - 0.
chequear('el flujo neto no suma los subtotales que tiene arriba',
    1100.0, $pc['FLUJO']['dias']['2026-09-06']);

/* ================================================================
   CONTRA LA BASE
   ================================================================ */
seccion('la tabla de aplicaciones');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$cob = new Cobertura();

if (!$cob->tablaCreada()) {
    Pruebas::saltear('falta correr sql/cashflow_cobertura.sql');
    return;
}

chequear('la tabla existe y no hay avisos pendientes', [], $cob->getAvisos());
chequear('getAplicaciones devuelve una lista', true, is_array($cob->getAplicaciones()));

// Toda fila vigente tiene fecha normalizada e importe distinto de cero: es lo
// que el proveedor asume al armar la serie.
$formaOk = true;

foreach ($cob->getAplicaciones() as $a) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['FECHA'])
        || !isset(Cobertura::ORIGENES[$a['ORIGEN']])) {
        $formaOk = false;
    }
}

chequear('las aplicaciones vigentes tienen fecha y origen validos', true, $formaOk);

seccion('el proveedor de cobertura esta enchufado al tablero');

$metaCob = CashflowRegistry::meta('COBERTURA');

chequear('esta registrado', true, $metaCob !== null);
chequear('y disponible', true, CashflowRegistry::disponible('COBERTURA'));
chequear('ofrece la serie APLICACION',
    true, CashflowRegistry::serieExiste('COBERTURA', 'APLICACION'));

// NO declara 'tab' a proposito: la fila se edita desde el propio tablero.
chequear('no declara pestana propia', false, isset($metaCob['tab']));

$provCob = CashflowRegistry::instanciar('COBERTURA');

chequear('se instancia', true, $provCob instanceof CashflowProvider);
chequear('y devuelve la serie que declara',
    ['APLICACION'], array_keys($provCob->series(Horizonte::desdeParametros(new Parametros()))));
