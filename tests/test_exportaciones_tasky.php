<?php
/**
 * Exportaciones Tasky: facturas pendientes en dolares a la razon social del
 * grupo en Uruguay, proyectadas a fecha de cobro estimada y valuadas a dolar
 * de HOY.
 *
 * Lo que se prueba sin base es la regla, que vive en funciones estaticas de
 * Ingresos: el reparto de una factura a la columna que le corresponde, la
 * factura vencida que cae antes del eje y se ubica en hoy, y la conversion a
 * pesos cuando falta la cotizacion. El proveedor se prueba con un Ingresos
 * falso. La consulta a GVA12 necesita la base y se saltea sola.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../cashflow/Class/Ingresos.php';
require_once __DIR__ . '/../cashflow/Class/Horizonte.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';
require_once __DIR__ . '/../cashflow/Class/EjeVista.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/CashflowEstructura.php';
require_once __DIR__ . '/../cashflow/Class/Menu.php';
require_once __DIR__ . '/../cashflow/Class/Providers/ExportacionesProvider.php';

// Un eje conocido: hoy 06/09/2026, 5 dias de tramo y 3 meses.
//   dias:  06/09 .. 10/09
//   meses: 2026-09 (parcial: del 11 al 30), 2026-10, 2026-11
$HOY = '2026-09-06';
$h = new Horizonte(5, 3, [], new DateTime($HOY));

// Lo que devolveria la consulta a GVA12, tal cual: IMPORTE_EX en dolares y
// COTIZ / IMPORTE como se facturo.
$CRUDAS = [
    ['FECHA_EMIS' => new DateTime('2026-08-10'), 'COD_CLIENT' => 'EXTASK', 'RAZON_SOCI' => 'TASKY S.A.',
     'T_COMP' => 'FAC', 'N_COMP' => 'E0001-00000010', 'IMPORTE_EX' => 1000.0, 'COTIZ' => 900.0, 'IMPORTE' => 900000.0],
    ['FECHA_EMIS' => new DateTime('2026-08-20'), 'COD_CLIENT' => 'EXTASK', 'RAZON_SOCI' => 'TASKY S.A.',
     'T_COMP' => 'FAC', 'N_COMP' => 'E0001-00000011', 'IMPORTE_EX' => 250.5, 'COTIZ' => 950.0, 'IMPORTE' => 237975.0],
    ['FECHA_EMIS' => new DateTime('2026-07-01'), 'COD_CLIENT' => 'EXTASK', 'RAZON_SOCI' => 'TASKY S.A.',
     'T_COMP' => 'FAC', 'N_COMP' => 'E0001-00000009', 'IMPORTE_EX' => 400.0, 'COTIZ' => 850.0, 'IMPORTE' => 340000.0]
];

/* ================================================================
   La fecha de cobro estimada
   ================================================================ */
seccion('la fecha de cobro estimada es emision + plazo');

$c = Ingresos::estimarCobroExportacion('2026-08-10', 30, $HOY);

chequear('10/08 + 30 dias = 09/09', '2026-09-09', $c['fecha']);
chequear('y no esta vencida', false, $c['vencida']);
chequear('la original es la misma', '2026-09-09', $c['original']);

$c = Ingresos::estimarCobroExportacion('2026-08-20', 30, $HOY);

chequear('20/08 + 30 dias = 19/09', '2026-09-19', $c['fecha']);

$c = Ingresos::estimarCobroExportacion('2026-09-06', 0, $HOY);

chequear('plazo cero: se cobra el dia de la emision', '2026-09-06', $c['fecha']);
chequear('y hoy no cuenta como vencida', false, $c['vencida']);

// sqlsrv devuelve un DateTime para una columna DATE: proyectarExportaciones()
// lo normaliza antes de estimar. Se verifica mas abajo, con las filas crudas.

$c = Ingresos::estimarCobroExportacion(null, 30, $HOY);

chequear('sin fecha de emision no hay fecha de cobro', null, $c['fecha']);
chequear('y no se marca como vencida: no hay de donde saberlo', false, $c['vencida']);

/* ================================================================
   La factura vencida cae antes del eje: se ubica en hoy y se marca
   ================================================================ */
seccion('una factura vencida se ubica en hoy, no se descarta');

$c = Ingresos::estimarCobroExportacion('2026-07-01', 30, $HOY);

chequear('01/07 + 30 = 31/07, que ya paso: va a hoy', $HOY, $c['fecha']);
chequear('queda marcada como vencida', true, $c['vencida']);
chequear('y conserva la fecha original para mostrarla', '2026-07-31', $c['original']);

$c = Ingresos::estimarCobroExportacion('2026-08-06', 30, $HOY);

chequear('vencida ayer: tambien va a hoy', $HOY, $c['fecha']);
chequear('y esta vencida', true, $c['vencida']);

/* ================================================================
   La conversion a pesos: todas al dolar de hoy, y sin cotizacion null
   ================================================================ */
seccion('la valuacion es a dolar de hoy');

chequear('USD 1.000 a 1.234,50 = $ 1.234.500', 1234500.0, Ingresos::valuarHoy(1000, 1234.5));
chequear('se redondea a dos decimales', 309242.25, Ingresos::valuarHoy(250.5, 1234.5));

// Sin cotizacion NO se asume un valor y NO se devuelve cero: un cero se
// leeria como "la factura vale cero pesos".
chequear('sin cotizacion: null, no cero', null, Ingresos::valuarHoy(1000, null));
chequear('una cotizacion en cero cuenta como ausente', null, Ingresos::valuarHoy(1000, 0));
chequear('una cotizacion negativa tambien', null, Ingresos::valuarHoy(1000, -5));

/* ================================================================
   Las filas de la pestana
   ================================================================ */
seccion('proyectarExportaciones arma las filas de la pestana');

$items = Ingresos::proyectarExportaciones($CRUDAS, 30, 1200.0, $HOY);

chequear('una fila por factura', 3, count($items));

$f = $items[0];

chequear('la fecha de emision queda normalizada', '2026-08-10', $f['FECHA_EMIS']);
chequear('el comprobante', 'E0001-00000010', $f['N_COMP']);
chequear('el cliente', 'EXTASK', $f['COD_CLIENT']);
chequear('los dolares son IMPORTE_EX', 1000.0, $f['IMPORTE_USD']);
chequear('la cotizacion de facturacion se copia como referencia', 900.0, $f['COTIZ_FACT']);
chequear('y los pesos de facturacion tambien', 900000.0, $f['IMPORTE_PESOS_FACT']);
chequear('la cotizacion de hoy es la misma para todas', 1200.0, $f['COTIZ_HOY']);
chequear('los pesos de hoy son USD x cotizacion de hoy, NO los de facturacion',
    1200000.0, $f['IMPORTE_PESOS_HOY']);
chequear('la fecha de cobro es emision + plazo', '2026-09-09', $f['Cobro']);
chequear('el plazo queda en la fila', 30, $f['DIAS']);
chequear('no esta vencida', false, $f['VENCIDA']);

chequear('la segunda tambien se valua a la cotizacion de hoy', 1200.0, $items[1]['COTIZ_HOY']);
chequear('aunque se haya facturado a otra', 950.0, $items[1]['COTIZ_FACT']);
chequear('USD 250,50 x 1.200', 300600.0, $items[1]['IMPORTE_PESOS_HOY']);

chequear('la tercera esta vencida', true, $items[2]['VENCIDA']);
chequear('y ubicada en hoy', $HOY, $items[2]['Cobro']);
chequear('con su fecha original a mano', '2026-07-31', $items[2]['COBRO_ORIGINAL']);

// COTIZ e IMPORTE de GVA12 no entran en ningun calculo: si cambian, los
// pesos de hoy no se mueven.
$otras = $CRUDAS;
$otras[0]['COTIZ'] = 1.0;
$otras[0]['IMPORTE'] = 1.0;

chequear('COTIZ e IMPORTE de GVA12 no mueven la valuacion de hoy',
    1200000.0, Ingresos::proyectarExportaciones($otras, 30, 1200.0, $HOY)[0]['IMPORTE_PESOS_HOY']);

$sinCotiz = Ingresos::proyectarExportaciones($CRUDAS, 30, null, $HOY);

chequear('sin cotizacion, los dolares siguen estando', 1000.0, $sinCotiz[0]['IMPORTE_USD']);
chequear('la cotizacion de hoy va en null', null, $sinCotiz[0]['COTIZ_HOY']);
chequear('y los pesos de hoy en null, no en cero', null, $sinCotiz[0]['IMPORTE_PESOS_HOY']);
chequear('la referencia de facturacion se muestra igual', 900000.0, $sinCotiz[0]['IMPORTE_PESOS_FACT']);

chequear('una lista vacia da una lista vacia', [], Ingresos::proyectarExportaciones([], 30, 1200.0, $HOY));
chequear('y algo que no es lista tambien', [], Ingresos::proyectarExportaciones(null, 30, 1200.0, $HOY));

/* ================================================================
   El reparto en la grilla: cada factura a la columna de su fecha
   ================================================================ */
seccion('cada factura va a la columna de su fecha de cobro, en pesos de hoy');

$payload = EjeVista::armar($h, $items, 'Cobro', 'IMPORTE_PESOS_HOY');

chequear('la factura que cobra el 09/09 esta en la columna diaria del 09/09',
    1200000.0, EjeVista::valor($payload['filas'][0], 'DIA|2026-09-09'));
chequear('y en ninguna otra', 0.0, EjeVista::valor($payload['filas'][0], 'MES|2026-09'));
chequear('la que cobra el 19/09 cae en la columna del mes, fuera del tramo diario',
    300600.0, EjeVista::valor($payload['filas'][1], 'MES|2026-09'));
chequear('y no en un dia', 0.0, EjeVista::valor($payload['filas'][1], 'DIA|2026-09-09'));
chequear('la vencida esta en HOY, el primer dia del eje',
    480000.0, EjeVista::valor($payload['filas'][2], 'DIA|2026-09-06'));
chequear('nada quedo fuera del horizonte', 0.0, $payload['descartes']['fuera_horizonte']);
chequear('nada quedo sin fecha', 0.0, $payload['descartes']['sin_fecha']);

// Los totales reconcilian: tramo + meses = horizonte, sin repetir nada.
chequear('total del tramo diario', 1680000.0, $payload['totales']['total_tramo']);
chequear('total del tramo mensual', 300600.0, $payload['totales']['total_meses']);
chequear('total del horizonte = tramo + meses', 1980600.0, $payload['totales']['total_horizonte']);

// Sin cotizacion la grilla queda VACIA -no en cero por accidente sino porque
// no hay valuacion- y no es EjeVista quien avisa: lo hacen los avisos de
// Ingresos. Es lo que verifica la seccion siguiente.
$payloadSin = EjeVista::armar($h, $sinCotiz, 'Cobro', 'IMPORTE_PESOS_HOY');

chequear('sin cotizacion la grilla no tiene importes', 0.0, $payloadSin['totales']['total_horizonte']);
chequear('y EjeVista no lo avisa: no es un descarte suyo', [], $payloadSin['warnings']);

/* ================================================================
   Los avisos: lo que no se ve en los numeros
   ================================================================ */
seccion('los avisos dicen lo que la grilla no muestra');

$avisos = Ingresos::avisosExportaciones($items, 1200.0);

chequear('con cotizacion y una vencida: un aviso', 1, count($avisos));
chequear('que dice que esta vencida', true, mb_stripos($avisos[0], 'vencida') !== false);
chequear('y cuantos dolares son', true, mb_stripos($avisos[0], 'USD 400,00') !== false);
chequear('y que se ubica en el primer dia del eje', true,
    mb_stripos($avisos[0], 'primer día del eje') !== false);

$avisos = Ingresos::avisosExportaciones($sinCotiz, null);

chequear('sin cotizacion: dos avisos', 2, count($avisos));
chequear('el primero es el de la cotizacion', true, mb_stripos($avisos[0], 'cotización') !== false);
chequear('que nombra la vista de origen', true, mb_stripos($avisos[0], Cotizacion::VISTA) !== false);
chequear('y dice cuantos dolares quedan sin valuar', true, mb_stripos($avisos[0], 'USD 1.650,50') !== false);
chequear('y que no se asume ningun tipo de cambio', true, mb_stripos($avisos[0], 'No se asume') !== false);

chequear('sin facturas no hay nada que avisar, ni sin cotizacion',
    [], Ingresos::avisosExportaciones([], null));

$sanas = Ingresos::proyectarExportaciones(array_slice($CRUDAS, 0, 2), 30, 1200.0, $HOY);

chequear('con cotizacion y sin vencidas: ningun aviso', [], Ingresos::avisosExportaciones($sanas, 1200.0));

/* ================================================================
   El agregado para el tablero: dolares por fecha, con lo vencido aparte
   ================================================================ */
seccion('el agregado transporta dolares por fecha');

$totales = Ingresos::agruparExportacionesPorFecha($items);

chequear('una fila por fecha de cobro', 3, count($totales));
chequear('ordenadas por fecha', $HOY, $totales[0]['FECHA']);
chequear('hoy trae los dolares de la vencida', 400.0, $totales[0]['IMPORTE_USD']);
chequear('y los marca como vencidos', 400.0, $totales[0]['VENCIDAS_USD']);
chequear('con el conteo de comprobantes', 1, $totales[0]['COMP_VENCIDOS']);
chequear('el 09/09 trae la primera', 1000.0, $totales[1]['IMPORTE_USD']);
chequear('sin nada vencido', 0.0, $totales[1]['VENCIDAS_USD']);
chequear('el 19/09 la segunda', 250.5, $totales[2]['IMPORTE_USD']);

// Dos facturas con la misma fecha se suman.
$dobles = Ingresos::proyectarExportaciones([$CRUDAS[0], $CRUDAS[0]], 30, 1200.0, $HOY);

chequear('dos facturas del mismo dia se suman en una fila',
    2000.0, Ingresos::agruparExportacionesPorFecha($dobles)[0]['IMPORTE_USD']);

// Una factura sin fecha de emision se transporta igual, sin fecha, para que
// el agrupador del horizonte la informe en 'sin_fecha' en vez de perderla.
$sinFecha = Ingresos::proyectarExportaciones(
    [['FECHA_EMIS' => null, 'COD_CLIENT' => 'EXTASK', 'RAZON_SOCI' => 'TASKY', 'T_COMP' => 'FAC',
      'N_COMP' => 'X', 'IMPORTE_EX' => 77.0, 'COTIZ' => 1, 'IMPORTE' => 77]],
    30, 1200.0, $HOY);
$agSinFecha = Ingresos::agruparExportacionesPorFecha($sinFecha);

chequear('una factura sin fecha se transporta con FECHA null', null, $agSinFecha[0]['FECHA']);
chequear('y con sus dolares', 77.0, $agSinFecha[0]['IMPORTE_USD']);

$serieSinFecha = $h->agrupar($agSinFecha, 'FECHA', 'IMPORTE_USD', 1200.0);

chequear('y el horizonte la informa en sin_fecha, no la pierde', 92400.0, $serieSinFecha['sin_fecha']);

/* ================================================================
   El proveedor
   ================================================================ */
seccion('el proveedor convierte a pesos de hoy y anota las vencidas');

/** Un Ingresos que no toca la base: devuelve las filas conocidas de arriba */
class IngresosFalsoExportaciones extends Ingresos {
    public $cotiz = 1200.0;
    public $filas = [];

    public function __construct($cotiz, $filas) {
        // No se llama al padre a proposito: no hace falta conexion.
        $this->cotiz = $cotiz;
        $this->filas = $filas;
    }

    public function getCotizacionHoy() {
        return $this->cotiz;
    }

    public function getExportacionesTaskyTotales() {
        return $this->filas;
    }
}

class ExportacionesProviderDePrueba extends ExportacionesProvider {
    private $falso;

    public function __construct($codigo, $falso) {
        parent::__construct($codigo);
        $this->falso = $falso;
    }

    protected function ingresos() {
        return $this->falso;
    }
}

$prov = new ExportacionesProviderDePrueba('EXPORTACIONES',
    new IngresosFalsoExportaciones(1200.0, $totales));
$series = $prov->series($h);

chequear('rinde la serie COBRANZA', true, isset($series['COBRANZA']));

$s = $series['COBRANZA'];

chequear('la moneda de origen es USD', 'USD', $s['moneda_origen']);
chequear('y dice con que tipo de cambio convirtio', 1200.0, $s['tipo_cambio']);
chequear('el 09/09 tiene USD 1.000 x 1.200', 1200000.0, $s['dias']['2026-09-09']);
chequear('hoy tiene la vencida', 480000.0, $s['dias']['2026-09-06']);
chequear('el mes tiene la del 19/09', 300600.0, $s['meses']['2026-09']);
chequear('nada fuera del horizonte', 0.0, $s['fuera_horizonte']);

// El total de la serie es exactamente el total de la pestana: lo que suma
// el tablero es lo que muestra la grilla, ni mas ni menos.
chequear('el total de la serie es el de la pestana',
    $payload['totales']['total_horizonte'],
    array_sum($s['dias']) + array_sum($s['meses']));

// La vencida esta anotada sobre la celda de hoy, y solo ahi.
chequear('la celda de hoy esta anotada', true, isset($s['detalle']['DIA|2026-09-06']));
chequear('con el importe vencido', 480000.0, $s['detalle']['DIA|2026-09-06']['importe']);
chequear('y la nota dice que vencio', true,
    mb_stripos($s['detalle']['DIA|2026-09-06']['nota'], 'VENCIO') !== false);
chequear('las otras celdas no estan anotadas', 1, count($s['detalle']));

chequear('y deja un aviso con las vencidas', true,
    mb_stripos(implode(' ', $prov->warnings()), 'vencida') !== false);

// La anotacion es metadato: no suma. Ver test_providers.php.
chequear('la anotacion no cambia el importe de la celda', 480000.0, $s['dias']['2026-09-06']);

seccion('sin cotizacion la fila va en cero y avisa');

$prov = new ExportacionesProviderDePrueba('EXPORTACIONES',
    new IngresosFalsoExportaciones(null, $totales));
$s = $prov->series($h)['COBRANZA'];

chequear('todo en cero', 0.0, array_sum($s['dias']) + array_sum($s['meses']));
chequear('sin tipo de cambio', null, $s['tipo_cambio']);
chequear('la moneda de origen sigue siendo USD', 'USD', $s['moneda_origen']);
chequear('sin anotaciones', [], $s['detalle']);
chequear('deja un aviso', 1, count($prov->warnings()));
chequear('que dice cuantos dolares hay sin valuar', true,
    mb_stripos($prov->warnings()[0], 'USD 1.650,50') !== false);
chequear('y que no se asume ningun tipo de cambio', true,
    mb_stripos($prov->warnings()[0], 'No se asume') !== false);

$prov = new ExportacionesProviderDePrueba('EXPORTACIONES',
    new IngresosFalsoExportaciones(null, []));
$prov->series($h);

chequear('sin cotizacion pero sin facturas no hay nada que avisar', [], $prov->warnings());

seccion('un proveedor de exportaciones no puede tumbar el tablero');

class ExportacionesProviderRoto extends ExportacionesProvider {
    protected function ingresos() {
        throw new Exception('base caida');
    }
}

$roto = new ExportacionesProviderRoto('EXPORTACIONES');

chequear('con la base caida no lanza', [], $roto->series($h));
chequear('deja un aviso', 1, count($roto->warnings()));
chequear('que nombra al modulo', true, strpos($roto->warnings()[0], 'EXPORTACIONES') === 0);

$otro = new ExportacionesProvider('NO_ES_MIO');

chequear('un codigo desconocido no devuelve series', [], $otro->series($h));
chequear('y lo avisa', true,
    mb_stripos(implode(' ', $otro->warnings()), 'no tiene serie definida') !== false);

/* ================================================================
   El registro, el menu y la fila del tablero
   ================================================================ */
seccion('EXPORTACIONES esta enchufado al tablero');

$meta = CashflowRegistry::meta('EXPORTACIONES');

chequear('esta registrado', true, $meta !== null);
chequear('se llama Exportaciones Tasky', 'Exportaciones Tasky', $meta['nombre']);
chequear('y ya esta disponible', true, CashflowRegistry::disponible('EXPORTACIONES'));
chequear('la serie es COBRANZA', true, CashflowRegistry::serieExiste('EXPORTACIONES', 'COBRANZA'));
chequear('enlaza a su propia pestana', 'exportaciones_tasky', $meta['tab']);
chequear('la moneda de origen es USD', 'USD', $meta['moneda']);
chequear('la descripcion aclara que Tasky es la razon social en Uruguay', true,
    mb_stripos($meta['descripcion'], 'Uruguay') !== false);

$inst = CashflowRegistry::instanciar('EXPORTACIONES');

chequear('el proveedor se instancia', true, $inst instanceof ExportacionesProvider);
chequear('y sabe con que codigo lo instanciaron', 'EXPORTACIONES', $inst->codigo());

chequear('el cliente de exportacion es EXTASK', ['EXTASK'], Ingresos::CLIENTES_EXPORTACION);
chequear('el plazo por defecto es 30', 30, Ingresos::EXPORTACIONES_DIAS_DEFAULT);

chequear('la pestana ya no es placeholder', false, Menu::esPlaceholder('exportaciones_tasky'));
chequear('y el menu la declara con datos', Menu::DATOS,
    Menu::estado('exportaciones_tasky', Menu::DATOS));
chequear('TabController la acepta', true, in_array('exportaciones_tasky', Menu::tabs(), true));

// La fila que siembran cashflow_estructura.sql y
// cashflow_exportaciones_tasky.sql valida contra el registro real.
$provs = [];

foreach (CashflowRegistry::todos() as $p) {
    $provs[$p['codigo']] = $p;
}

$r = CashflowEstructura::validar(
    [['CODIGO' => 'INGRESOS', 'NOMBRE' => 'Ingresos', 'ROL' => 'MOVIMIENTO',
      'ID_PADRE' => null, 'ORDEN' => 20, 'ACTIVO' => 1]],
    [['ID' => 1, 'CODIGO' => 'EXPORTACIONES', 'NOMBRE' => 'Exportaciones Tasky',
      'SECCION' => 'INGRESOS', 'TIPO' => 'INGRESO', 'COMPUTA' => 1,
      'ORIGEN_PROVIDER' => 'EXPORTACIONES', 'ORIGEN_SERIE' => 'COBRANZA',
      'ORDEN' => 70, 'ACTIVO' => 1]],
    $provs);

chequear('la fila sembrada es valida', true, $r['valido']);
chequear('y ya no avisa que el modulo no esta construido', false, (function () use ($r) {
    foreach ($r['advertencias'] as $a) {
        if (mb_stripos($a, 'todavía no está construido') !== false) {
            return true;
        }
    }

    return false;
})());

/* ================================================================
   Contra la base
   ================================================================ */
seccion('contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$ingresos = new Ingresos();
$dias = $ingresos->getDiasCobroExportaciones();

chequear('getDiasCobroExportaciones devuelve un entero mayor a 0', true, is_int($dias) && $dias > 0);

$filas = $ingresos->getExportacionesTasky();

chequear('getExportacionesTasky devuelve un array', true, is_array($filas));

foreach ($filas as $f) {
    if (!in_array($f['COD_CLIENT'], Ingresos::CLIENTES_EXPORTACION, true)) {
        chequear('solo trae clientes de exportacion', 'EXTASK', $f['COD_CLIENT']);
        break;
    }
}

if (count($filas) > 0) {
    chequear('la fila trae IMPORTE_USD', true, isset($filas[0]['IMPORTE_USD']));
    chequear('la fila trae Cobro', true, array_key_exists('Cobro', $filas[0]));
    chequear('el plazo de la fila es el del parametro', $dias, $filas[0]['DIAS']);
}

$hReal = Horizonte::desdeParametros(new Parametros());
$seriesReal = CashflowRegistry::instanciar('EXPORTACIONES')->series($hReal);

chequear('el proveedor real rinde COBRANZA', true, isset($seriesReal['COBRANZA']));
chequear('con los dias alineados al horizonte', $hReal->cantidadDias(), count($seriesReal['COBRANZA']['dias']));

// El total del tablero es exactamente el de la pestana.
$payloadReal = EjeVista::armar($hReal, $filas, 'Cobro', 'IMPORTE_PESOS_HOY');

chequear('el tablero suma exactamente lo que muestra la pestana',
    $payloadReal['totales']['total_horizonte'],
    array_sum($seriesReal['COBRANZA']['dias']) + array_sum($seriesReal['COBRANZA']['meses']));
