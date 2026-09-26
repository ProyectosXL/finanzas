<?php
/**
 * Las cuentas de inversion y comitente: clase de cuenta, cuenta corriente del
 * fondo, el proveedor que lleva su saldo al tablero como stock de cobertura,
 * el ABM de Parametros y la migracion.
 *
 * Casi todo va sin base. Los criterios viven en helpers puros de Fondos y de
 * Cobertura, y el proveedor acepta cuentas de mentira por una subclase: lo
 * delicado no son las consultas sino las reglas -que fondo va de que lado del
 * tablero, que movimiento entra al saldo, en que moneda se aplica-.
 */

require_once __DIR__ . '/../Class/Fondos.php';
require_once __DIR__ . '/../Class/Cobertura.php';
require_once __DIR__ . '/../Class/CashflowRegistry.php';
require_once __DIR__ . '/../Class/CashflowEstructura.php';
require_once __DIR__ . '/../Class/Providers/FondosProvider.php';
require_once __DIR__ . '/../Class/Menu.php';

/* ================================================================
   LA CLASE DE UNA CUENTA
   ================================================================ */
seccion('las clases de cuenta, y cuales son un fondo');

chequear('hay cuatro clases', ['CTA_CORRIENTE', 'CAJA_AHORRO', 'INVERSION', 'COMITENTE'],
    array_keys(Fondos::CLASES));
chequear('dos son fondos', ['INVERSION', 'COMITENTE'], Fondos::CLASES_FONDO);
chequear('la clase por defecto es cuenta corriente', 'CTA_CORRIENTE', Fondos::CLASE_DEFECTO);

// ES LA UNICA REGLA que decide de que lado del tablero va una cuenta: fondo es
// stock de cobertura, a la vista es disponibilidad.
chequear('una inversion es un fondo', true, Fondos::esFondo('INVERSION'));
chequear('una cuenta comitente tambien', true, Fondos::esFondo('COMITENTE'));
chequear('una cuenta corriente no', false, Fondos::esFondo('CTA_CORRIENTE'));
chequear('una caja de ahorro tampoco', false, Fondos::esFondo('CAJA_AHORRO'));
chequear('se normaliza', true, Fondos::esFondo(' inversion '));
chequear('null no es un fondo', false, Fondos::esFondo(null));

chequear('validarClase normaliza', 'COMITENTE', Fondos::validarClase(' comitente '));
chequear('vacio toma la de defecto', 'CTA_CORRIENTE', Fondos::validarClase(''));
chequear('null tambien', 'CTA_CORRIENTE', Fondos::validarClase(null));
chequearLanza('una clase inventada se rechaza', function () { Fondos::validarClase('CRIPTO'); });

seccion('cambiar de clase: solo dentro del mismo grupo');

/* Cruzar de grupo dejaria el historico de la cuenta -fotos en un caso,
   movimientos en el otro- leido como lo que no es. Si quedo mal, se inhabilita
   y se crea otra, igual que con el TIPO. */
chequear('cuenta corriente <-> caja de ahorro', true,
    Fondos::cambioDeClasePermitido('CTA_CORRIENTE', 'CAJA_AHORRO'));
chequear('inversion <-> comitente', true,
    Fondos::cambioDeClasePermitido('INVERSION', 'COMITENTE'));
chequear('la misma clase, obvio', true,
    Fondos::cambioDeClasePermitido('INVERSION', 'INVERSION'));
chequear('de a la vista a fondo NO', false,
    Fondos::cambioDeClasePermitido('CTA_CORRIENTE', 'INVERSION'));
chequear('de fondo a la vista tampoco', false,
    Fondos::cambioDeClasePermitido('COMITENTE', 'CAJA_AHORRO'));

/* ================================================================
   LOS MOVIMIENTOS
   ================================================================ */
seccion('el tipo del movimiento pone el signo');

chequear('suscripcion suma', 1, Fondos::TIPOS_MOV['SUSCRIPCION']);
chequear('rescate resta', -1, Fondos::TIPOS_MOV['RESCATE']);
chequear('se normaliza', 'RESCATE', Fondos::validarTipoMovimiento(' rescate '));
chequearLanza('otro tipo se rechaza', function () { Fondos::validarTipoMovimiento('AJUSTE'); });

seccion('el importe es siempre positivo');

// "Un rescate de -1000" no significa nada, y aceptarlo dejaria dos formas de
// decir lo mismo que un dia se contradicen.
chequear('un positivo pasa', 1500.5, Fondos::validarImporte('1500.5'));
chequear('se redondea a dos decimales', 10.57, Fondos::validarImporte(10.567));
chequearLanza('el cero se rechaza', function () { Fondos::validarImporte(0); });
chequearLanza('un negativo se rechaza', function () { Fondos::validarImporte(-5); });
chequearLanza('un texto se rechaza', function () { Fondos::validarImporte('mil'); });
chequearLanza('vacio se rechaza', function () { Fondos::validarImporte(''); });

seccion('la fecha');

chequear('una fecha valida se normaliza', '2026-09-20', Fondos::validarFecha('2026-09-20'));
chequear('se acepta una fecha futura: un rescate previsto es un dato real',
    '2030-01-01', Fondos::validarFecha('2030-01-01'));
chequearLanza('una fecha inventada se rechaza', function () { Fondos::validarFecha('2026-02-30'); });
chequearLanza('un texto se rechaza', function () { Fondos::validarFecha('manana'); });

seccion('el saldo inicial va con su fecha');

chequear('los dos juntos pasan', ['saldo' => 1000.0, 'fecha' => '2026-09-14'],
    Fondos::validarSaldoInicial('1000', '2026-09-14'));
chequear('ninguno de los dos es "no tiene"', null, Fondos::validarSaldoInicial(null, null));
chequear('vacios tambien', null, Fondos::validarSaldoInicial('', ''));
chequear('el cero es un saldo inicial valido: un fondo recien abierto',
    0.0, Fondos::validarSaldoInicial('0', '2026-09-14')['saldo']);
chequearLanza('saldo sin fecha se rechaza', function () { Fondos::validarSaldoInicial('1000', ''); });
chequearLanza('fecha sin saldo se rechaza', function () { Fondos::validarSaldoInicial('', '2026-09-14'); });
chequearLanza('un negativo se rechaza', function () { Fondos::validarSaldoInicial('-1', '2026-09-14'); });

seccion('la clave de fondo de una cuenta');

/* Es lo que se guarda en RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN y lo que el motor
   cruza. Con prefijo, para que en una consulta a mano se entienda que es una
   cuenta y no se confunda con las claves viejas. */
chequear('lleva prefijo y el id', 'CTA_13', Fondos::claveFondo(13));
chequear('y se lee de vuelta', 13, Fondos::idDeClave('CTA_13'));
chequear('en minusculas tambien', 13, Fondos::idDeClave('cta_13'));
chequear('una clave vieja no es de ninguna cuenta', null, Fondos::idDeClave('INVERSIONES'));
chequear('ni un prefijo sin numero', null, Fondos::idDeClave('CTA_'));
chequear('ni null', null, Fondos::idDeClave(null));

/* ================================================================
   LA CUENTA CORRIENTE: saldo inicial + suscripciones - rescates
   ================================================================ */
seccion('el saldo a una fecha');

$cuenta = ['SALDO_INICIAL' => 1000, 'FECHA_SALDO_INICIAL' => '2026-09-10'];
$movs = [
    ['FECHA' => '2026-09-05', 'TIPO' => 'SUSCRIPCION', 'IMPORTE' => 999, 'VIGENTE' => 1], // antes del inicial
    ['FECHA' => '2026-09-10', 'TIPO' => 'RESCATE', 'IMPORTE' => 999, 'VIGENTE' => 1],     // el dia del inicial
    ['FECHA' => '2026-09-12', 'TIPO' => 'SUSCRIPCION', 'IMPORTE' => 500, 'VIGENTE' => 1],
    ['FECHA' => '2026-09-15', 'TIPO' => 'RESCATE', 'IMPORTE' => 200, 'VIGENTE' => 1],
    ['FECHA' => '2026-09-15', 'TIPO' => 'RESCATE', 'IMPORTE' => 700, 'VIGENTE' => 0],     // dado de baja
    ['FECHA' => '2026-09-25', 'TIPO' => 'RESCATE', 'IMPORTE' => 300, 'VIGENTE' => 1],     // futuro
];

$r = Fondos::saldoA($cuenta, $movs, '2026-09-18');

chequear('saldo = 1000 + 500 - 200', 1300.0, $r['saldo']);
chequear('las suscripciones que entraron', 500.0, $r['suscripciones']);
chequear('los rescates que entraron', 200.0, $r['rescates']);
chequear('dos movimientos cuentan', 2, $r['movimientos']);

/* EL SALDO INICIAL ES AL CIERRE DE SU FECHA: lo de esa fecha o anterior ya esta
   incluido. Sin esta regla, fijar un saldo inicial nuevo despues de haber
   cargado movimientos los contaria dos veces. */
chequear('los anteriores o iguales a la fecha del inicial no se suman', 2, $r['incluidos']);

// EL STOCK DEL TABLERO ES EL SALDO A HOY: lo futuro se cuenta aparte.
chequear('los posteriores a la fecha pedida no se suman', 1, $r['posteriores']);

// Un no vigente no existe para esta cuenta: ni suma ni se cuenta.
chequear('un dado de baja no se cuenta en nada', 2 + 2 + 1, $r['movimientos'] + $r['incluidos'] + $r['posteriores']);

// Pedido a otra fecha, el futuro entra.
chequear('a una fecha posterior el rescate futuro ya entro',
    1000.0, Fondos::saldoA($cuenta, $movs, '2026-09-30')['saldo']);

// Sin saldo inicial se arranca de cero, y ningun movimiento queda "incluido".
$sinInicial = Fondos::saldoA(['SALDO_INICIAL' => null, 'FECHA_SALDO_INICIAL' => null], $movs, '2026-09-18');

chequear('sin saldo inicial arranca de cero: 999 - 999 + 500 - 200', 300.0, $sinInicial['saldo']);
chequear('y nada queda incluido', 0, $sinInicial['incluidos']);

chequear('sin movimientos el saldo es el inicial', 1000.0, Fondos::saldoA($cuenta, [], '2026-09-18')['saldo']);
chequear('y con basura tampoco falla', 1000.0, Fondos::saldoA($cuenta, null, '2026-09-18')['saldo']);

// Un tipo desconocido o una fecha inutil se ignoran, no revientan.
chequear('un movimiento sin fecha o con tipo raro se ignora', 1000.0,
    Fondos::saldoA($cuenta, [['FECHA' => null, 'TIPO' => 'SUSCRIPCION', 'IMPORTE' => 5],
                             ['FECHA' => '2026-09-12', 'TIPO' => 'AJUSTE', 'IMPORTE' => 5]],
        '2026-09-18')['saldo']);

/* ================================================================
   LOS FONDOS COMO ORIGENES DE COBERTURA
   ================================================================ */
seccion('cada cuenta de fondo es un fondo de cobertura');

$origenes = [
    'CTA_20' => ['id' => 20, 'nombre' => 'Fondo viejo', 'moneda' => 'ARS', 'clase' => 'INVERSION', 'activo' => false],
    'CTA_14' => ['id' => 14, 'nombre' => 'Cuenta comitente', 'moneda' => 'USD', 'clase' => 'COMITENTE', 'activo' => true],
    'CTA_13' => ['id' => 13, 'nombre' => 'Inversiones', 'moneda' => 'ARS', 'clase' => 'INVERSION', 'activo' => true]
];

// El defecto es la primera ACTIVA en PESOS por orden: ni la inhabilitada que
// va primera, ni la de dolares que va segunda.
chequear('el origen por defecto saltea inhabilitadas y dolares', 'CTA_13',
    Cobertura::origenDefectoDe($origenes));
chequear('la moneda es la de la cuenta', 'USD', Cobertura::monedaDeOrigenEn('CTA_14', $origenes));
chequear('validar acepta una activa', 'CTA_14', Cobertura::validarOrigenEn('cta_14', $origenes));
chequearLanza('y rechaza una inhabilitada', function () use ($origenes) {
    Cobertura::validarOrigenEn('CTA_20', $origenes);
});

/* ================================================================
   EL PROVEEDOR, CON CUENTAS DE MENTIRA
   ================================================================ */
seccion('el proveedor lleva el saldo de las cuentas al tablero como stock');

class FondosDePrueba extends Fondos {
    public $creado = true;
    public $cuentas = [];
    public function __construct() { /* a proposito: no abre conexion */ }
    public function creado() { return $this->creado; }
    public function getCuentasFondo($soloActivas = true, $hoy = null, $conMovimientos = false) { return $this->cuentas; }
}

class CotizacionDePrueba extends Cotizacion {
    public $valor = 1500;
    public $lanza = false;
    public function __construct() { /* idem */ }
    public function ultimaHasta($fecha, $punta = self::COMPRADOR) {
        if ($this->lanza) { throw new Exception('sin vista'); }
        if ($this->valor === null) { return null; }
        return ['fecha' => '2026-09-05', 'valor' => $this->valor, 'punta' => $punta];
    }
    // La segunda consulta de ultimasHasta(): sin base no hay filas entre las
    // fechas, que es ademas el caso real para columnas futuras.
    protected function entreFechas($desde, $hasta, $punta) { return []; }
}

class FondosProviderDePrueba extends FondosProvider {
    public $fondosFalsos;
    public $cotizacionFalsa;
    protected function fondos() { return $this->fondosFalsos; }
    protected function cotizacion() { return $this->cotizacionFalsa; }
}

$h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

$cuentaFalsa = function ($id, $clase, $moneda, $saldo, $inicial = 1, $posteriores = 0) {
    return ['ID' => $id, 'CLASE' => $clase, 'MONEDA' => $moneda, 'NOMBRE' => 'Cuenta ' . $id,
            'SALDO_INICIAL' => $inicial, 'FECHA_SALDO_INICIAL' => '2026-09-01',
            'saldo' => $saldo, 'posteriores' => $posteriores, 'clave_fondo' => Fondos::claveFondo($id)];
};

$armar = function ($codigo, $cuentas, $cotizacion = null, $creado = true) use ($cuentaFalsa) {
    $p = new FondosProviderDePrueba($codigo);
    $p->fondosFalsos = new FondosDePrueba();
    $p->fondosFalsos->creado = $creado;
    $p->fondosFalsos->cuentas = $cuentas;
    $p->cotizacionFalsa = ($cotizacion === null) ? new CotizacionDePrueba() : $cotizacion;
    return $p;
};

$cuentas = [
    $cuentaFalsa(1, 'INVERSION', 'ARS', 3000),
    $cuentaFalsa(2, 'INVERSION', 'ARS', 2000),
    $cuentaFalsa(3, 'COMITENTE', 'USD', 10),
];

$sInv = $armar('FONDO_INVERSION', $cuentas)->series($h);

chequear('FONDO_INVERSION devuelve la serie STOCK', ['STOCK'], array_keys($sInv));
chequear('el stock es la suma de las cuentas de ESA clase, en el primer dia del eje',
    5000.0, $sInv['STOCK']['dias']['2026-09-06']);
chequear('y nada en los otros dias', 0.0, $sInv['STOCK']['dias']['2026-09-07']);
chequear('el reparto va por cuenta', ['CTA_1' => 3000.0, 'CTA_2' => 2000.0], $sInv['STOCK']['por_fondo']);
chequear('con el nombre de cada una', ['CTA_1' => 'Cuenta 1', 'CTA_2' => 'Cuenta 2'], $sInv['STOCK']['fondos']);
chequear('en pesos no hay tipo de cambio', null, $sInv['STOCK']['tipo_cambio']);
chequear('moneda de origen pesos', 'ARS', $sInv['STOCK']['moneda_origen']);

// LA COMITENTE NO ESTA EN LA SERIE DE INVERSION, aunque sea un fondo: cual es
// cual lo decide la clase, y son dos filas del tablero.
chequear('la cuenta comitente no entra en FONDO_INVERSION', false, isset($sInv['STOCK']['por_fondo']['CTA_3']));

seccion('los dolares se valuan a la ultima cotizacion a hoy, punta vendedora');

$provCom = $armar('FONDO_COMITENTE', $cuentas);
$sCom = $provCom->series($h);

chequear('10 dolares a 1500 son 15000 pesos', 15000.0, $sCom['STOCK']['dias']['2026-09-06']);
chequear('el reparto ya va en pesos', ['CTA_3' => 15000.0], $sCom['STOCK']['por_fondo']);
chequear('se informa el tipo de cambio', 1500.0, $sCom['STOCK']['tipo_cambio']);
chequear('y la moneda de origen', 'USD', $sCom['STOCK']['moneda_origen']);

// La punta es la VENDEDORA: la misma con la que se valuan las aplicaciones en
// dolares, asi consumir todo el saldo lo deja en cero.
$fuente = file_get_contents(__DIR__ . '/../Class/Providers/FondosProvider.php');

chequear('valua a la punta vendedora', true,
    strpos($fuente, 'ultimaHasta($h->hoy(), Cotizacion::VENDEDOR)') !== false);

// Sin cotizacion NO se inventa nada: la cuenta queda afuera y se avisa en dolares.
$sinCot = new CotizacionDePrueba();
$sinCot->valor = null;
$provSin = $armar('FONDO_COMITENTE', $cuentas, $sinCot);
$sSin = $provSin->series($h);

chequear('sin cotizacion el stock va en cero', 0.0, $sSin['STOCK']['dias']['2026-09-06']);
chequear('y la cuenta no reparte', [], $sSin['STOCK']['por_fondo']);
chequear('con un aviso que dice cuantos dolares son', true,
    strpos(implode(' ', $provSin->warnings()), 'USD 10,00') !== false);

// Si la vista no existe, tampoco: avisa y no tumba.
$rota = new CotizacionDePrueba();
$rota->lanza = true;
$provRoto = $armar('FONDO_COMITENTE', $cuentas, $rota);

chequear('con la vista caida no lanza', 0.0, $provRoto->series($h)['STOCK']['dias']['2026-09-06']);
chequear('y avisa', true, strpos(implode(' ', $provRoto->warnings()), 'tipo de cambio') !== false);

seccion('lo que el proveedor avisa');

$provAvisos = $armar('FONDO_INVERSION', [
    $cuentaFalsa(1, 'INVERSION', 'ARS', 3000, null),   // sin saldo inicial
    $cuentaFalsa(2, 'INVERSION', 'ARS', 2000, 1, 2)    // dos movimientos futuros
]);
$provAvisos->series($h);
$avisos = implode(' | ', $provAvisos->warnings());

chequear('avisa la cuenta sin saldo inicial, por nombre', true, strpos($avisos, 'Cuenta 1') !== false);
chequear('y los movimientos futuros que no entran', true, strpos($avisos, '2 movimiento(s)') !== false);

$provVacio = $armar('FONDO_COMITENTE', [$cuentaFalsa(1, 'INVERSION', 'ARS', 3000)]);
$provVacio->series($h);

chequear('sin cuentas de esa clase, avisa y va en cero', true,
    strpos(implode(' ', $provVacio->warnings()), 'no hay ninguna cuenta') !== false);

$provSinDdl = $armar('FONDO_INVERSION', $cuentas, null, false);
$sDdl = $provSinDdl->series($h);

chequear('sin el script, cero', 0.0, $sDdl['STOCK']['dias']['2026-09-06']);
chequear('y el aviso nombra el script', true,
    strpos(implode(' ', $provSinDdl->warnings()), 'cashflow_saldos_cuentas_fondo.sql') !== false);

$provOtro = new FondosProviderDePrueba('NO_ES_MIO');
$provOtro->fondosFalsos = new FondosDePrueba();

chequear('un codigo desconocido no devuelve series', [], $provOtro->series($h));

seccion('el proveedor real se instancia desde el registro');

foreach (['FONDO_INVERSION', 'FONDO_COMITENTE'] as $codigo) {
    $prov = CashflowRegistry::instanciar($codigo);

    chequear($codigo . ' se instancia', true, $prov instanceof FondosProvider);
    chequear($codigo . ' conoce su codigo', $codigo, $prov->codigo());
    chequear($codigo . ' sirve la clase que dice', true,
        isset(FondosProvider::CLASE_POR_CODIGO[$codigo])
        && Fondos::esFondo(FondosProvider::CLASE_POR_CODIGO[$codigo]));
}

/* ================================================================
   LA ESTRUCTURA: las filas de stock validan contra el registro real
   ================================================================ */
seccion('las filas de stock apuntadas a los fondos validan');

$reglas = CashflowEstructura::validar(
    [['CODIGO' => 'COB', 'NOMBRE' => 'Cobertura', 'ROL' => 'DERIVADO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'STOCK_INVERSIONES', 'NOMBRE' => 'Inversiones disponibles',
      'SECCION' => 'COB', 'TIPO' => 'STOCK_COBERTURA', 'COMPUTA' => 0,
      'ORIGEN_PROVIDER' => 'FONDO_INVERSION', 'ORIGEN_SERIE' => 'STOCK', 'ORDEN' => 10, 'ACTIVO' => 1],
     ['ID' => 2, 'CODIGO' => 'STOCK_DOLARES_COMITENTE', 'NOMBRE' => 'Dolares en cuenta comitente',
      'SECCION' => 'COB', 'TIPO' => 'STOCK_COBERTURA', 'COMPUTA' => 0,
      'ORIGEN_PROVIDER' => 'FONDO_COMITENTE', 'ORIGEN_SERIE' => 'STOCK', 'ORDEN' => 15, 'ACTIVO' => 1]]
);

chequear('las dos filas validan', true, $reglas['valido']);
chequear('sin advertencias', [], $reglas['advertencias']);

// Una fila que siga leyendo del proveedor retirado valida -no queda invalida-
// pero con advertencia: el dato ya no se mantiene.
$reglasRet = CashflowEstructura::validar(
    [['CODIGO' => 'COB', 'NOMBRE' => 'Cobertura', 'ROL' => 'DERIVADO',
      'ID_PADRE' => null, 'ORDEN' => 10, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'STOCK_INVERSIONES', 'NOMBRE' => 'Inversiones disponibles',
      'SECCION' => 'COB', 'TIPO' => 'STOCK_COBERTURA', 'COMPUTA' => 0,
      'ORIGEN_PROVIDER' => 'SALDO_INVERSIONES', 'ORIGEN_SERIE' => 'STOCK', 'ORDEN' => 10, 'ACTIVO' => 1]]
);

chequear('una fila sobre el proveedor retirado sigue siendo valida', true, $reglasRet['valido']);
chequear('pero con advertencia', 1, count($reglasRet['advertencias']));
chequear('que dice que esta retirado', true,
    strpos($reglasRet['advertencias'][0], 'retirado') !== false);

/* ================================================================
   EL SCRIPT Y LA PANTALLA
   ================================================================ */
seccion('el script de migracion');

$sql = __DIR__ . '/../sql/cashflow_saldos_cuentas_fondo.sql';

chequear('existe', true, file_exists($sql));

$txt = file_get_contents($sql);

chequear('agrega CLASE solo si no esta', true,
    strpos($txt, "COL_LENGTH('dbo.RO_T_CASHFLOW_SALDOS_CUENTA', 'CLASE') IS NULL") !== false);
chequear('con cuenta corriente por defecto para las que ya estan', true,
    strpos($txt, "DEFAULT ('CTA_CORRIENTE')") !== false);
chequear('y el CHECK con las cuatro clases', true,
    strpos($txt, "CHECK (CLASE IN ('CTA_CORRIENTE', 'CAJA_AHORRO', 'INVERSION', 'COMITENTE'))") !== false);
chequear('el saldo inicial va con su fecha, los dos o ninguno', true,
    strpos($txt, 'SALDO_INICIAL IS NOT NULL AND FECHA_SALDO_INICIAL IS NOT NULL') !== false);
chequear('crea la tabla de movimientos solo si no existe', true,
    strpos($txt, "OBJECT_ID('dbo.RO_T_CASHFLOW_SALDOS_FONDO_MOV', 'U') IS NULL") !== false);
chequear('el importe del movimiento es positivo por CHECK', true,
    strpos($txt, 'CHECK (IMPORTE > 0)') !== false);
chequear('la cadena del historial es ID_REEMPLAZA', true,
    strpos($txt, 'ID_REEMPLAZA') !== false);

// La migracion toma la ULTIMA carga vigente -mayor fecha, desempate por ID-,
// que es exactamente lo que el proveedor viejo tomaba como stock.
chequear('migra la ultima carga vigente de inversiones', true,
    preg_match('/FROM dbo\.RO_T_CASHFLOW_SALDO_INVERSIONES\s+WHERE VIGENTE = 1\s+ORDER BY FECHA DESC, ID DESC/', $txt) === 1);
chequear('y la de dolares', true,
    preg_match('/FROM dbo\.RO_T_CASHFLOW_DOLARES_COMITENTE\s+WHERE VIGENTE = 1\s+ORDER BY FECHA DESC, ID DESC/', $txt) === 1);
chequear('se guarda por clase: no migra si ya hay una cuenta de esa clase', true,
    strpos($txt, "WHERE CLASE = 'INVERSION')") !== false
    && strpos($txt, "WHERE CLASE = 'COMITENTE')") !== false);

// Las aplicaciones cambian de clave a la de la cuenta migrada, TODAS.
chequear('reescribe las aplicaciones de INVERSIONES a la cuenta', true,
    strpos($txt, "WHERE ORIGEN = 'INVERSIONES'") !== false);
chequear('y las de DOLARES', true, strpos($txt, "WHERE ORIGEN = 'DOLARES'") !== false);
chequear('sin filtrar por vigente: el historial tambien', false,
    preg_match("/WHERE ORIGEN = 'INVERSIONES'\s+AND VIGENTE/", $txt) === 1);
chequear('saca el default viejo del origen', true,
    strpos($txt, 'DROP CONSTRAINT DF_RO_T_CF_COBAP_ORIGEN') !== false);

// Las filas del tablero se REAPUNTAN, no se dan de baja y crean.
chequear('reapunta la fila de inversiones', true,
    preg_match("/SET ORIGEN_PROVIDER = 'FONDO_INVERSION'.*WHERE CODIGO = 'STOCK_INVERSIONES' AND ORIGEN_PROVIDER = 'SALDO_INVERSIONES'/s", $txt) === 1);
chequear('y la de dolares', true,
    preg_match("/SET ORIGEN_PROVIDER = 'FONDO_COMITENTE'.*WHERE CODIGO = 'STOCK_DOLARES_COMITENTE' AND ORIGEN_PROVIDER = 'DOLARES_COMITENTE'/s", $txt) === 1);
chequear('no borra nada', false, preg_match('/^\s*(DELETE|DROP TABLE)\b/mi', $txt) === 1);

seccion('la pestana y los parametros');

$tabSaldos = file_get_contents(__DIR__ . '/../Tabs/saldos.php');
$jsSaldos = file_get_contents(__DIR__ . '/../Js/Saldos.js');
$tabParam = file_get_contents(__DIR__ . '/../Tabs/parametros_saldos.php');
$jsParam = file_get_contents(__DIR__ . '/../Js/Parametros-Saldos.js');
$ctrl = file_get_contents(__DIR__ . '/../Controller/SaldosController.php');

chequear('Saldos tiene la sub-pestana Fondos', true, strpos($tabSaldos, 'id="paneFondos"') !== false);
chequear('con la tabla de fondos y su exportacion', true,
    strpos($tabSaldos, 'id="tablaFondos"') !== false && strpos($tabSaldos, 'data-exportar="tablaFondos"') !== false);
chequear('y el modal de movimientos', true, strpos($tabSaldos, 'id="modalMovimientos"') !== false);
chequear('el JS pide los fondos a su propia accion', true, strpos($jsSaldos, 'action=getFondos') !== false);
chequear('y guarda movimientos con id_reemplaza, no con update', true,
    strpos($jsSaldos, 'id_reemplaza') !== false);
chequear('el controller atiende las cuatro acciones de fondos', true,
    strpos($ctrl, "case 'getFondos':") !== false
    && strpos($ctrl, "case 'getMovimientosFondo':") !== false
    && strpos($ctrl, "case 'guardarMovimientoFondo':") !== false
    && strpos($ctrl, "case 'bajaMovimientoFondo':") !== false);
chequear('el enlace del tablero abre esa sub-pestana', true,
    strpos($jsSaldos, "fondos: 'tabFondosBtn'") !== false);

chequear('Parametros -> Saldos tiene la seccion de fondos', true,
    strpos($tabParam, 'id="tablaSpFondos"') !== false);
chequear('con clase en el alta de bancos', true,
    strpos($tabParam, 'sp-nueva-clase" data-tipo="BANCO"') !== false);
chequear('y el JS manda saldo inicial y fecha juntos', true,
    strpos($jsParam, 'saldo_inicial') !== false && strpos($jsParam, 'fecha_saldo_inicial') !== false);

// Las dos pestanas de Otros Ingresos se eliminaron: ni archivo, ni menu, ni
// TabController, ni enlace desde el tablero. Sus tablas quedan.
$tabCtrl = file_get_contents(__DIR__ . '/../Controller/TabController.php');

foreach (['dolares_comitente', 'saldo_inversiones'] as $tab) {
    chequear($tab . ' ya no tiene archivo de pestana', false,
        file_exists(__DIR__ . '/../Tabs/' . $tab . '.php'));
    chequear($tab . ' ya no esta en TabController', false, strpos($tabCtrl, "'" . $tab . "'") !== false);
}

foreach (['SALDO_INVERSIONES', 'DOLARES_COMITENTE'] as $codigo) {
    chequear($codigo . ' ya no declara pestana: su fila no queda como enlace roto',
        false, isset(CashflowRegistry::meta($codigo)['tab']));
}

/* ================================================================
   CONTRA LA BASE
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$fondos = new Fondos();

if (!$fondos->creado()) {
    Pruebas::saltear('falta correr sql/cashflow_saldos_cuentas_fondo.sql');
    return;
}

chequear('sin avisos pendientes', [], $fondos->getAvisos());

$cuentasReales = $fondos->getCuentasFondo(false);

chequear('getCuentasFondo devuelve una lista', true, is_array($cuentasReales));

// Todas las cuentas de fondo son fondos y ninguna a la vista se colo.
$noFondo = array_filter($cuentasReales, function ($c) { return !Fondos::esFondo($c['CLASE']); });

chequear('solo devuelve cuentas de fondo', 0, count($noFondo));

// Y ninguna cuenta de fondo entra en disponibilidades.
require_once __DIR__ . '/../Class/Saldos.php';

$idsFondo = array_map(function ($c) { return $c['ID']; }, $cuentasReales);
$idsDisponible = array_map(function ($f) { return $f['id_cuenta']; }, (new Saldos())->getSaldosActuales());

chequear('ninguna cuenta de fondo esta en el disponible inicial',
    [], array_values(array_intersect($idsFondo, $idsDisponible)));

// El saldo de cada cuenta es el que da saldoA() sobre sus movimientos: la
// pantalla y el tablero usan la misma cuenta.
$coinciden = true;

foreach ($cuentasReales as $c) {
    $calc = Fondos::saldoA($c, $fondos->getMovimientos($c['ID']), $c['fecha_saldo']);

    if (abs($calc['saldo'] - $c['saldo']) > 0.001) {
        $coinciden = false;
    }
}

chequear('el saldo listado coincide con saldoA() sobre el historial', true, $coinciden);

// Toda aplicacion de cobertura vigente sale de una cuenta que existe.
$cob = new Cobertura();

if ($cob->tablaCreada()) {
    $origenesReales = $cob->origenes();
    $huerfanas = 0;

    foreach ($cob->getAplicaciones() as $a) {
        if (!isset($origenesReales[$a['ORIGEN']])) {
            $huerfanas++;
        }
    }

    chequear('ninguna aplicacion vigente quedo sin cuenta de fondo', 0, $huerfanas);
}
