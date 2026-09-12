<?php
/**
 * Contrato de proveedor y registro.
 *
 * La primera parte no toca la base. La segunda si, y se saltea sola si no hay
 * conexion, para que el corredor sirva igual en una maquina sin SQL Server.
 */

require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';

/* ================================================================
   Registro
   ================================================================ */
seccion('registro de proveedores');

chequear('Ventas esta registrado', true, CashflowRegistry::existe('VENTAS'));
chequear('y disponible', true, CashflowRegistry::disponible('VENTAS'));
chequear('Echeqs esta registrado', true, CashflowRegistry::existe('ECHEQS'));
chequear('y construido', true, CashflowRegistry::disponible('ECHEQS'));
chequear('Cobranzas May esta registrado', true, CashflowRegistry::existe('COBRANZAS_MAY'));
chequear('y tambien construido', true, CashflowRegistry::disponible('COBRANZAS_MAY'));
chequear('Haberes esta registrado pero no construido',
    false, CashflowRegistry::disponible('HABERES'));
chequear('un proveedor inventado no existe', false, CashflowRegistry::existe('NO_EXISTE'));
chequear('serie valida', true, CashflowRegistry::serieExiste('VENTAS', 'COBRANZA'));
chequear('serie que no ofrece', false, CashflowRegistry::serieExiste('VENTAS', 'CUALQUIERA'));
chequear('serie de un proveedor inexistente', false, CashflowRegistry::serieExiste('NADIE', 'X'));

$todos = CashflowRegistry::todos();

chequear('todos() incorpora el codigo a cada entrada', 'VENTAS', $todos[0]['codigo']);
chequear('todos() no expone el archivo interno', false, isset($todos[0]['archivo']));
chequear('todos() no expone la clase interna', false, isset($todos[0]['clase']));

$disponibles = array_values(array_filter($todos, function ($p) { return $p['disponible']; }));

// Ventas, Cobranzas FR, Cobranzas May, Proveedores Exterior, Nacionalizaciones,
// Saldos, Caja Locales, Cobranzas Electronicas, Echeqs, Dolares Cuenta
// Comitente, Exportaciones Tasky y Saldo de Inversiones.
chequear('hay 12 modulos con datos reales', 12, count($disponibles));
chequear('un modulo sin construir no se instancia',
    null, CashflowRegistry::instanciar('HABERES'));
chequear('un modulo inexistente tampoco', null, CashflowRegistry::instanciar('NO_EXISTE'));

// Toda entrada del registro tiene que estar completa: si falta un dato, el
// editor de estructura arma un desplegable roto.
$completos = true;

foreach ($todos as $p) {
    if (empty($p['nombre']) || empty($p['series']) || !isset($p['disponible'])) {
        $completos = false;
    }
}

chequear('todas las entradas del registro estan completas', true, $completos);

/* ================================================================
   La garantia de que un proveedor no puede tumbar el tablero
   ================================================================ */
seccion('un proveedor que falla no lanza');

class ProveedorQueExplota extends CashflowProvider {
    protected function calcular($h) {
        throw new Exception('base caida');
    }
}

class ProveedorQueDevuelveBasura extends CashflowProvider {
    protected function calcular($h) {
        return ['X' => [
            'dias' => ['2030-01-01' => 500],       // clave que no pertenece al eje
            'meses' => 'esto no es un array'
        ]];
    }
}

class ProveedorQueDevuelveNada extends CashflowProvider {
    protected function calcular($h) { return null; }
}

$h = new Horizonte(28, 12, [], new DateTime('2026-09-06'));

$roto = new ProveedorQueExplota('ROTO');
$series = $roto->series($h);

chequear('no lanza y no devuelve series', [], $series);
chequear('deja un aviso', 1, count($roto->warnings()));
chequear('el aviso nombra al proveedor', true, strpos($roto->warnings()[0], 'ROTO') === 0);
chequear('y explica la consecuencia', true, strpos($roto->warnings()[0], 'en cero') !== false);

$basura = new ProveedorQueDevuelveBasura('BASURA');
$s = $basura->series($h)['X'];

chequear('completa las claves diarias del eje', 28, count($s['dias']));
chequear('completa las claves mensuales', 12, count($s['meses']));
chequear('una rama que no es un array queda en cero', 0, array_sum($s['meses']));
chequear('una clave fuera del eje no se descarta en silencio', 500.0, $s['fuera_horizonte']);
chequear('moneda por defecto', 'ARS', $s['moneda_origen']);
chequear('tipo de cambio por defecto', null, $s['tipo_cambio']);

chequear('sin detalle, el mapa de anotaciones va vacio', [], $s['detalle']);

$nada = new ProveedorQueDevuelveNada('NADA');
chequear('devolver null no rompe', [], $nada->series($h));

/* ================================================================
   ANOTACIONES POR COLUMNA ('detalle')

   Dicen que UNA PARTE del importe de una celda tiene algo que contar. No
   son un importe mas: no entran en ninguna suma y no generan avisos. Ver
   el encabezado de CashflowProvider.
   ================================================================ */
seccion('anotaciones por columna');

class ProveedorQueAnota extends CashflowProvider {
    protected function calcular($h) {
        return ['X' => [
            'dias' => ['2026-09-10' => 1000],
            'detalle' => [
                'DIA|2026-09-10' => ['importe' => 400, 'nota' => 'pactado a mano'],
                'MES|2026-10'    => ['importe' => 250, 'nota' => 'otra cosa'],
                // Columnas que NO existen en el eje: no se pueden ver, asi que
                // no tienen que sobrevivir.
                'DIA|2030-01-01' => ['importe' => 999, 'nota' => 'fuera del eje'],
                'basura'         => ['importe' => 777, 'nota' => 'id invalido']
            ]
        ]];
    }
}

$anota = new ProveedorQueAnota('ANOTA');
$s = $anota->series($h)['X'];

chequear('sobreviven solo las columnas que existen en el eje',
    ['DIA|2026-09-10', 'MES|2026-10'], array_keys($s['detalle']));
chequear('con su importe', 400.0, $s['detalle']['DIA|2026-09-10']['importe']);
chequear('y su nota', 'pactado a mano', $s['detalle']['DIA|2026-09-10']['nota']);

// LO IMPORTANTE: anotar no suma. Si el detalle entrara en la serie, el
// tablero contaria dos veces la parte anotada.
chequear('la anotacion NO se suma al importe de la celda',
    1000.0, $s['dias']['2026-09-10']);
chequear('ni al total de la serie',
    1000.0, array_sum($s['dias']) + array_sum($s['meses']));
chequear('ni se informa como importe fuera del horizonte', 0, $s['fuera_horizonte']);
chequear('ni genera avisos', [], $s['warnings']);

/* ================================================================
   Contra datos reales
   ================================================================ */
seccion('proveedores reales');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
    return;
}

$hReal = Horizonte::desdeParametros(new Parametros());

foreach ($disponibles as $meta) {
    $codigo = $meta['codigo'];
    $prov = CashflowRegistry::instanciar($codigo);

    if (!chequear("$codigo se instancia", true, $prov instanceof CashflowProvider)) {
        continue;
    }

    chequear("$codigo conoce su codigo", $codigo, $prov->codigo());

    $series = $prov->series($hReal);

    // Se compara el CONJUNTO y no el orden: el motor busca las series por
    // clave, asi que el orden en que el proveedor las arma no importa.
    $declaradas = array_keys($meta['series']);
    $devueltas = array_keys($series);
    sort($declaradas);
    sort($devueltas);

    chequear("$codigo devuelve exactamente las series que declara el registro",
        $declaradas, $devueltas);

    foreach ($series as $nombre => $s) {
        chequear("$codigo/$nombre: claves diarias completas",
            $hReal->cantidadDias(), count($s['dias']));
        chequear("$codigo/$nombre: claves mensuales completas",
            $hReal->cantidadMeses(), count($s['meses']));

        $noNumericos = array_filter($s['dias'], function ($v) {
            return !is_int($v) && !is_float($v);
        });

        chequear("$codigo/$nombre: todos los importes son numeros", 0, count($noNumericos));
    }

    // La segunda llamada tiene que salir de cache: el motor pide las series una
    // vez por pedido, pero si alguien llama de nuevo no puede recalcular.
    $t0 = microtime(true);
    $prov->series($hReal);
    $ms = (microtime(true) - $t0) * 1000;

    chequear("$codigo memoiza el resultado", true, $ms < 5);
}

seccion('el eje inyectado manda');

$prov = CashflowRegistry::instanciar('VENTAS');
$series = $prov->series($hReal);

chequear('la primera clave diaria es la del eje del tablero',
    $hReal->dias()[0]['fecha'], array_keys($series['COBRANZA']['dias'])[0]);
chequear('la primera clave mensual tambien',
    $hReal->meses()[0]['clave'], array_keys($series['COBRANZA']['meses'])[0]);
chequear('nada de Ventas queda fuera del horizonte',
    0.0, floatval($series['COBRANZA']['fuera_horizonte']));

seccion('el agregado de cobranzas coincide con el resumen existente');

require_once __DIR__ . '/../cashflow/Class/Ingresos.php';

$ing = new Ingresos();

$sumaResumen = 0;

foreach ($ing->getCobranzasFR(true) as $x) {
    $sumaResumen += floatval($x['importe_neto']);
}

$sumaAgregado = 0;

foreach ($ing->getCobranzasFRTotales() as $x) {
    $sumaAgregado += $x['IMPORTE'];
}

chequear('getCobranzasFRTotales da el mismo total que getCobranzasFR(true)',
    round($sumaResumen, 2), round($sumaAgregado, 2));
