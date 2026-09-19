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

/* LOS FONDOS SON LAS CUENTAS DE FONDO DE SALDOS, no una lista del codigo. Los
   helpers reciben la lista -como la devuelve Cobertura::origenes()- para poder
   probarse sin base. Esta lista imita dos cuentas activas, una en pesos y una en
   dolares, y una inhabilitada que va PRIMERA para verificar que no la elige el
   defecto. El detalle de las reglas esta en tests/test_fondos.php. */
$origenesPrueba = [
    'CTA_9' => ['id' => 9, 'nombre' => 'Fondo viejo', 'moneda' => 'ARS', 'clase' => 'INVERSION',
                'activo' => false],
    'CTA_13' => ['id' => 13, 'nombre' => 'Inversiones', 'moneda' => 'ARS', 'clase' => 'INVERSION',
                 'activo' => true],
    'CTA_14' => ['id' => 14, 'nombre' => 'Cuenta comitente', 'moneda' => 'USD',
                 'clase' => 'COMITENTE', 'activo' => true]
];

chequear('sin origen se asume la primera cuenta activa en pesos',
    'CTA_13', Cobertura::validarOrigenEn(null, $origenesPrueba));
chequear('vacio tambien', 'CTA_13', Cobertura::validarOrigenEn('', $origenesPrueba));
chequear('se normaliza a mayusculas', 'CTA_14', Cobertura::validarOrigenEn(' cta_14 ', $origenesPrueba));

// Un origen que no es ninguna cuenta no se guarda como vino ni se descarta en
// silencio: es una clave, y una clave desconocida no descuenta de nadie.
chequearLanza('un origen que no es una cuenta se rechaza',
    function () use ($origenesPrueba) { Cobertura::validarOrigenEn('CRIPTO', $origenesPrueba); });

// Las claves viejas tampoco: la migracion las reescribio, y aceptarlas volveria
// a crear aplicaciones sin fondo.
chequearLanza('la clave vieja INVERSIONES ya no vale',
    function () use ($origenesPrueba) { Cobertura::validarOrigenEn('INVERSIONES', $origenesPrueba); });

// Una cuenta inhabilitada esta en la lista para leer el historial, no para
// cargar cosas nuevas.
chequearLanza('una cuenta inhabilitada no sirve para aplicar',
    function () use ($origenesPrueba) { Cobertura::validarOrigenEn('CTA_9', $origenesPrueba); });

chequearLanza('sin ninguna cuenta en pesos, sin origen se rechaza diciendolo',
    function () use ($origenesPrueba) {
        Cobertura::validarOrigenEn(null, ['CTA_14' => $origenesPrueba['CTA_14']]);
    });
chequear('y con la lista vacia el defecto es null',
    null, Cobertura::origenDefectoDe([]));

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

seccion('cuanto queda de cobertura');

// El dato lo resuelve el motor y lo llevan LAS DOS filas de la seccion, para
// que cualquiera de las dos pueda explicarlo sin que el front tenga que cruzar
// filas. Ver Cashflow::resolverCobertura().
chequear('la fila de stock lleva el saldo', true, is_array($pc['STOCK']['cobertura']));
chequear('y la de uso tambien', true, is_array($pc['USO']['cobertura']));
chequear('las dos dicen lo mismo', $pc['STOCK']['cobertura'], $pc['USO']['cobertura']);

chequear('cuanto hay invertido', 5000.0, $pc['STOCK']['cobertura']['stock']);
chequear('cuanto se aplico', 400.0, $pc['STOCK']['cobertura']['aplicado']);
chequear('cuanto queda', 4600.0, $pc['STOCK']['cobertura']['disponible']);
chequear('y que hay una fila de stock de donde sacarlo',
    true, $pc['STOCK']['cobertura']['hay_stock']);

// El resto de las filas NO lo lleva: null es distinto de un bloque con ceros.
chequear('una fila cualquiera no lo lleva', null, $pc['COBROS']['cobertura']);
chequear('ni el flujo neto', null, $pc['FLUJO']['cobertura']);

seccion('el saldo de cobertura se mide sobre TODO el horizonte, no sobre la vista');

// El stock es un stock: no cambia porque uno mire el tramo diario en vez del
// mensual. Si lo aplicado se midiera por vista, el disponible cambiaria al
// tocar un boton -la misma plata, dos numeros distintos- y una aplicacion
// cargada en un mes de mas adelante no se descontaria mientras se mira la
// vista Dias, que es justo cuando se decide aplicar mas.
$motorMes = new CashflowCobertura($ec, new ParametrosCobertura(), $hc);
$motorMes->series = array_merge($motorC->series, [
    // 400 en el tramo diario MAS 600 en una columna mensual.
    'COBERTURA' => ['APLICACION' => $serie($hc, ['2026-09-07' => 400], ['2026-10' => 600])]
]);

$tMes = $motorMes->proyectar();
$pMes = [];
foreach ($tMes['filas'] as $f) { $pMes[$f['codigo']] = $f; }

chequear('lo aplicado suma los dos tramos', 1000.0, $pMes['STOCK']['cobertura']['aplicado']);
chequear('y el disponible los descuenta a los dos', 4000.0,
    $pMes['STOCK']['cobertura']['disponible']);

// El total del tramo diario de la fila de uso es SOLO 400: el saldo de
// cobertura no puede salir de ahi, y esa es la diferencia que se esta fijando.
chequear('aunque el total del tramo diario sea otro', 400.0, $pMes['USO']['total_tramo']);

seccion('una devolucion a la inversion suma al disponible');

// Un importe negativo es sacar plata de la cuenta y volver a invertirla. Sale
// gratis: es la misma resta, con el signo del dato.
$motorNeg = new CashflowCobertura($ec, new ParametrosCobertura(), $hc);
$motorNeg->series = array_merge($motorC->series, [
    'COBERTURA' => ['APLICACION' => $serie($hc, ['2026-09-07' => 400, '2026-09-08' => -100])]
]);

$tNeg = $motorNeg->proyectar();
$pNeg = [];
foreach ($tNeg['filas'] as $f) { $pNeg[$f['codigo']] = $f; }

chequear('lo aplicado es el neto', 300.0, $pNeg['STOCK']['cobertura']['aplicado']);
chequear('y quedan 4700', 4700.0, $pNeg['STOCK']['cobertura']['disponible']);

seccion('aplicar mas de lo que hay avisa, pero no se bloquea');

// Que alguien planifique cubrir con plata que todavia no esta puede ser
// deliberado -un rescate en camino-, asi que la app no lo impide. Lo que no
// puede pasar es que el tablero tape un saldo final con plata inexistente sin
// decirlo.
$motorEx = new CashflowCobertura($ec, new ParametrosCobertura(), $hc);
$motorEx->series = array_merge($motorC->series, [
    'COBERTURA' => ['APLICACION' => $serie($hc, ['2026-09-07' => 8000])]
]);

$tEx = $motorEx->proyectar();
$pEx = [];
foreach ($tEx['filas'] as $f) { $pEx[$f['codigo']] = $f; }

chequear('el disponible queda negativo', -3000.0, $pEx['STOCK']['cobertura']['disponible']);
chequear('la cobertura se aplica igual', 8000.0, $pEx['USO']['dias']['2026-09-07']);

$avisoExceso = null;
foreach ($tEx['warnings'] as $w) {
    if (strpos($w, 'Cobertura:') === 0) { $avisoExceso = $w; }
}

chequear('hay un aviso', true, $avisoExceso !== null);
chequear('que dice cuanto falta', true,
    $avisoExceso !== null && strpos($avisoExceso, '3.000,00') !== false);

// Sin exceso NO se avisa: un aviso que aparece siempre deja de leerse.
$sinAviso = true;
foreach ($tc['warnings'] as $w) {
    if (strpos($w, 'Cobertura:') === 0) { $sinAviso = false; }
}

chequear('y no aparece cuando alcanza', true, $sinAviso);

seccion('sin fila de stock no se inventa un disponible');

// Puede estar inhabilitada, o su modulo puede no haber devuelto nada. Contestar
// cero seria decir que no hay plata cuando lo que pasa es que no se sabe.
$ecSinStock = new EstructuraCobertura();
$ecSinStock->secciones = $ec->secciones;
$ecSinStock->filas = array_values(array_filter($ec->filas, function ($f) {
    return $f['CODIGO'] !== 'STOCK';
}));

$motorSinStock = new CashflowCobertura($ecSinStock, new ParametrosCobertura(), $hc);
$motorSinStock->series = $motorC->series;

$tSin = $motorSinStock->proyectar();
$pSin = [];
foreach ($tSin['filas'] as $f) { $pSin[$f['codigo']] = $f; }

chequear('la fila de uso sigue llevando el bloque', true, is_array($pSin['USO']['cobertura']));
chequear('pero avisa que no hay stock de donde sacarlo',
    false, $pSin['USO']['cobertura']['hay_stock']);

$avisoSinStock = false;
foreach ($tSin['warnings'] as $w) {
    if (strpos($w, 'Cobertura:') === 0) { $avisoSinStock = true; }
}

chequear('y no avisa de un exceso que no puede calcular', false, $avisoSinStock);

/* ================================================================
   EL DISPONIBLE SE LLEVA POR FONDO, Y LOS FONDOS SON CUENTAS

   Cada serie de stock trae 'por_fondo' -cuanto aporta cada cuenta- y la de
   uso cuanto se aplico desde cada una, con la misma clave. El motor cruza por
   clave y avisa por fondo: un fondo puede estar sobregirado mientras el total
   cierra, y ese es el caso que el pozo unico no veia. Ninguna clave esta
   escrita en el motor.
   ================================================================ */
seccion('el disponible se lleva por fondo');

$ecFondos = new EstructuraCobertura();
$ecFondos->secciones = $ec->secciones;
$ecFondos->filas = array_map(function ($f) {
    if ($f['CODIGO'] === 'STOCK') {
        $f['ORIGEN_PROVIDER'] = 'FONDO_INVERSION';
    }

    return $f;
}, $ec->filas);

// Dos cuentas de inversion: 3000 y 2000. Se aplican 2500 de la primera y 100
// de la segunda: el total (2600 de 5000) cierra, pero la primera NO.
$stockFondos = $serie($hc, ['2026-09-06' => 5000]);
$stockFondos['por_fondo'] = ['CTA_1' => 3000, 'CTA_2' => 2000];
$stockFondos['fondos'] = ['CTA_1' => 'Fondo Alyc', 'CTA_2' => 'Fondo Banco'];

$usoFondos = $serie($hc, ['2026-09-07' => 2600]);
$usoFondos['por_fondo'] = ['CTA_1' => 2500, 'CTA_2' => 100];

$motorF = new CashflowCobertura($ecFondos, new ParametrosCobertura(), $hc);
$motorF->series = array_merge($motorC->series, [
    'FONDO_INVERSION' => ['STOCK' => $stockFondos],
    'COBERTURA' => ['APLICACION' => $usoFondos]
]);

$tF = $motorF->proyectar();
$pF = [];
foreach ($tF['filas'] as $f) { $pF[$f['codigo']] = $f; }

$fondosF = $pF['STOCK']['cobertura']['fondos'];

chequear('el total sigue siendo el de siempre', 5000.0, $pF['STOCK']['cobertura']['stock']);
chequear('y el disponible total tambien', 2400.0, $pF['STOCK']['cobertura']['disponible']);
chequear('hay un fondo por cuenta', ['CTA_1', 'CTA_2'], array_keys($fondosF));
chequear('cada uno con su stock', 3000.0, $fondosF['CTA_1']['stock']);
chequear('y con lo que se aplico desde el', 2500.0, $fondosF['CTA_1']['aplicado']);
chequear('el disponible por fondo es la resta', 500.0, $fondosF['CTA_1']['disponible']);
chequear('y el nombre es el de la cuenta, no la clave', 'Fondo Alyc', $fondosF['CTA_1']['nombre']);

// Sin exceso en ningun fondo ni en el total: ningun aviso de cobertura.
$avisosF = array_values(array_filter($tF['warnings'], function ($w) {
    return strpos($w, 'Cobertura:') === 0;
}));

chequear('con todos los fondos alcanzando, no hay aviso', [], $avisosF);

// Ahora 3500 de la primera: el total (3600 de 5000) SIGUE cerrando, pero la
// primera queda sobregirada en 500. Es el aviso que el pozo unico no daba.
$usoExceso = $serie($hc, ['2026-09-07' => 3600]);
$usoExceso['por_fondo'] = ['CTA_1' => 3500, 'CTA_2' => 100];

$motorFE = new CashflowCobertura($ecFondos, new ParametrosCobertura(), $hc);
$motorFE->series = array_merge($motorF->series, ['COBERTURA' => ['APLICACION' => $usoExceso]]);

$tFE = $motorFE->proyectar();
$avisosFE = array_values(array_filter($tFE['warnings'], function ($w) {
    return strpos($w, 'Cobertura:') === 0;
}));

chequear('un fondo sobregirado avisa aunque el total cierre', 1, count($avisosFE));
chequear('y el aviso nombra la cuenta', true,
    count($avisosFE) === 1 && strpos($avisosFE[0], '"Fondo Alyc"') !== false);
chequear('con cuanto falta de ese fondo', true,
    count($avisosFE) === 1 && strpos($avisosFE[0], '500,00') !== false);

/* UNA APLICACION CON UNA CLAVE QUE NO ES DE NINGUNA CUENTA -una vieja que la
   migracion no pudo mover- suma al total y a un fondo sin stock, para que se
   vea. Y un stock que NO reparte -una fila que siga leyendo de Otros Ingresos-
   suma al total y a ningun fondo. */
$usoHuerfano = $serie($hc, ['2026-09-07' => 400]);
$usoHuerfano['por_fondo'] = ['SUSCRIPCION' => 400];

$stockSinReparto = $serie($hc, ['2026-09-06' => 5000]);

$motorH = new CashflowCobertura($ecFondos, new ParametrosCobertura(), $hc);
$motorH->series = array_merge($motorC->series, [
    'FONDO_INVERSION' => ['STOCK' => $stockSinReparto],
    'COBERTURA' => ['APLICACION' => $usoHuerfano]
]);

$tH = $motorH->proyectar();
$pH = [];
foreach ($tH['filas'] as $f) { $pH[$f['codigo']] = $f; }

chequear('el total no depende del reparto', 4600.0, $pH['STOCK']['cobertura']['disponible']);
chequear('un stock sin reparto no abre ningun fondo', ['SUSCRIPCION'],
    array_keys($pH['STOCK']['cobertura']['fondos']));
chequear('y la clave huerfana queda con stock cero y su aplicado', [0, 400.0],
    [$pH['STOCK']['cobertura']['fondos']['SUSCRIPCION']['stock'],
     $pH['STOCK']['cobertura']['fondos']['SUSCRIPCION']['aplicado']]);

seccion('el reparto por fondo sobrevive al contrato del proveedor');

/* ESTO YA SE ROMPIO UNA VEZ. CashflowProvider::normalizar() arma la serie con
   una lista cerrada de claves, y el reparto -entonces 'por_origen'- no estaba:
   el proveedor lo colgaba, el motor lo esperaba, y en el medio se perdia. Los
   avisos por fondo nunca llegaron al tablero real; solo esta prueba, que
   reemplaza pedirSeries() y se saltea normalizar(), los veia andar. Por eso
   esta prueba pasa por series(), que es el camino real. */
class ProveedorConReparto extends CashflowProvider {
    protected function calcular($h) {
        $s = $h->serieVacia();
        $s['dias'][$h->hoy()] = 5000;
        $s['por_fondo'] = ['CTA_1' => '3000', 'CTA_2' => 2000];
        $s['fondos'] = ['CTA_1' => 'Fondo Alyc'];

        return ['STOCK' => $s];
    }
}

$conReparto = (new ProveedorConReparto('X'))->series($hc);

chequear('por_fondo llega al motor', ['CTA_1' => 3000.0, 'CTA_2' => 2000.0],
    $conReparto['STOCK']['por_fondo']);
chequear('como numeros', true, is_float($conReparto['STOCK']['por_fondo']['CTA_1']));
chequear('y los nombres tambien', ['CTA_1' => 'Fondo Alyc'], $conReparto['STOCK']['fondos']);

class ProveedorSinReparto extends CashflowProvider {
    protected function calcular($h) {
        return ['STOCK' => $h->serieVacia()];
    }
}

$sinReparto = (new ProveedorSinReparto('X'))->series($hc);

// Vacio y no ausente: el motor lee la clave sin preguntar si existe.
chequear('una serie que no reparte llega con el reparto vacio', [], $sinReparto['STOCK']['por_fondo']);
chequear('y sin nombres', [], $sinReparto['STOCK']['fondos']);

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

$origenesBase = $cob->origenes();

foreach ($cob->getAplicaciones() as $a) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a['FECHA'])
        || !isset($origenesBase[$a['ORIGEN']])) {
        $formaOk = false;
    }
}

// El origen tiene que ser una cuenta de fondo: la migracion reescribio las
// claves viejas, y una aplicacion con una clave que no es de ninguna cuenta no
// descuenta de nadie. Si esto falla, hay aplicaciones huerfanas que reasignar.
chequear('las aplicaciones vigentes tienen fecha valida y salen de una cuenta de fondo',
    true, $formaOk);

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

/* ================================================================
   LA COBERTURA SE CONSUME POR FONDO

   El origen era DESCRIPTIVO: habia UN pozo, el tablero sumaba todos los stocks
   y todos los usos, y se podian aplicar trescientos millones "de dolares"
   mientras el total alcanzara. Desde que hay DOS fondos, el origen decide de
   cual se descuenta.
   ================================================================ */
seccion('cada fondo tiene su moneda, y la decide el fondo');

/* LA MONEDA NO ES UN PARAMETRO SUELTO: es la de la CUENTA de la que se aplica.
   Recibirla aparte permitiria guardar un importe en dolares diciendo que sale
   de inversiones, y ese importe se valuaria dos veces o ninguna sin que nada lo
   dijera. La lista es la misma de mas arriba: dos cuentas activas, una en cada
   moneda. */
chequear('de la cuenta comitente se aplican DOLARES',
    'USD', Cobertura::monedaDeOrigenEn('CTA_14', $origenesPrueba));
chequear('de la cuenta de inversion, pesos',
    'ARS', Cobertura::monedaDeOrigenEn('CTA_13', $origenesPrueba));
chequear('se normaliza', 'USD', Cobertura::monedaDeOrigenEn(' cta_14 ', $origenesPrueba));

// Un origen desconocido es pesos: es lo que era todo antes de que hubiera un
// stock en otra moneda, y valuar de mas es peor que no valuar.
chequear('un origen que no existe cae en pesos',
    'ARS', Cobertura::monedaDeOrigenEn('LO QUE SEA', $origenesPrueba));
chequear('y null tambien', 'ARS', Cobertura::monedaDeOrigenEn(null, $origenesPrueba));

seccion('los fondos son las cuentas, y el stock viaja repartido por cuenta');

/* YA NO HAY LISTA FIJA. Cobertura::ORIGENES y MONEDA_POR_FONDO desaparecieron: si
   alguien las vuelve a escribir, volvio a haber una cuenta escrita en el codigo.
   Lo mismo con 'origen_cobertura' en el registro. */
chequear('Cobertura no tiene una lista fija de origenes',
    false, defined('Cobertura::ORIGENES'));
chequear('ni una tabla fija de monedas por fondo',
    false, defined('Cobertura::MONEDA_POR_FONDO'));
chequear('ni un origen por defecto escrito',
    false, defined('Cobertura::ORIGEN_DEFECTO'));

$conOrigenFijo = [];

foreach (CashflowRegistry::todos() as $p) {
    if (isset($p['origen_cobertura'])) { $conOrigenFijo[] = $p['codigo']; }
}

chequear('ningun proveedor declara un fondo fijo en el registro', [], $conOrigenFijo);

/* EL STOCK LO SIRVEN LAS CUENTAS DE FONDO, un codigo por clase. Ver
   tests/test_fondos.php para el proveedor en detalle. */
foreach (['FONDO_INVERSION', 'FONDO_COMITENTE'] as $codigo) {
    $mf = CashflowRegistry::meta($codigo);

    chequear($codigo . ' esta registrado y disponible',
        true, CashflowRegistry::disponible($codigo));
    chequear($codigo . ' ofrece la serie STOCK',
        true, CashflowRegistry::serieExiste($codigo, 'STOCK'));
    chequear($codigo . ' enlaza a Saldos -> Fondos',
        'saldos/fondos', $mf['tab'] . '/' . $mf['subtab']);
}

/* Y LOS DE OTROS INGRESOS ESTAN RETIRADOS: siguen declarados y disponibles
   -una fila que los apunte no queda invalida- pero marcados. */
foreach (['SALDO_INVERSIONES', 'DOLARES_COMITENTE'] as $codigo) {
    chequear($codigo . ' sigue disponible', true, CashflowRegistry::disponible($codigo));
    chequear($codigo . ' esta retirado', true, CashflowRegistry::retirado($codigo));
}

chequear('los proveedores vivos no estan retirados', false,
    CashflowRegistry::retirado('FONDO_INVERSION') || CashflowRegistry::retirado('COBERTURA'));

seccion('el script que habilita el consumo por fondo');

$sqlFondo = __DIR__ . '/../sql/cashflow_cobertura_por_fondo.sql';

chequear('el script existe', true, file_exists($sqlFondo));

$txtFondo = file_get_contents($sqlFondo);

chequear('agrega la moneda solo si no esta', true,
    strpos($txtFondo, "COL_LENGTH('dbo.RO_T_CASHFLOW_COBERTURA_APLIC', 'MONEDA') IS NULL")
        !== false);

// Lo que ya estaba es en pesos: es el dato cierto, no un relleno.
chequear('lo que ya estaba queda en pesos', true,
    strpos($txtFondo, "SET MONEDA = 'ARS'") !== false);
chequear('y el default tambien', true, strpos($txtFondo, "DEFAULT ('ARS')") !== false);

/* SIN EL SCRIPT NO SE PUEDE APLICAR EN DOLARES, y se dice por que: guardar un
   importe en dolares en una tabla que no sabe la moneda lo dejaria leyendose
   como pesos, que es un error de dos ordenes de magnitud. */
$cuerpoGuardar = (function () {
    $r = new ReflectionMethod('Cobertura', 'guardar');

    return implode('', array_slice(file(__DIR__ . '/../cashflow/Class/Cobertura.php'),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
})();

chequear('la moneda sale del origen y no de un parametro', true,
    strpos($cuerpoGuardar, '$mon = $this->monedaDeOrigen($org);') !== false);
chequear('sin la columna, aplicar en dolares se rechaza', true,
    strpos($cuerpoGuardar, "if (\$mon === 'USD' && !\$this->tieneMoneda())") !== false);
chequear('y el mensaje dice que se leeria como pesos', true,
    strpos($cuerpoGuardar, 'leería como pesos') !== false);
chequear('nombrando el script que falta', true,
    strpos($cuerpoGuardar, 'sql/cashflow_cobertura_por_fondo.sql') !== false);
