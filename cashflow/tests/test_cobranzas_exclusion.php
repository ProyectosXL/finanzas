<?php
/**
 * Excluir un cliente de Cobranzas Franquicias (Class/CobranzasExclusion.php).
 *
 * Lo que se prueba es que la plata de un cliente excluido salga de TODO lo que
 * suma -las dos solapas, el pie, los KPIs, las series del tablero- sin
 * desaparecer en silencio: queda aparte, marcada, contada en el cartel y en el
 * aviso. Y que la decision tenga motivo, deje historial y no rompa nada si
 * todavia no se corrio el script de la tabla.
 *
 * Casi todo es puro. Lo que vive en la base -el historial, el indice unico-
 * se verifica leyendo el script y la clase, como test_tablas_controles.php:
 * lo que se rompe en silencio ahi es el cableado.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/EjeVista.php';
require_once __DIR__ . '/../Class/Parametros.php';
require_once __DIR__ . '/../Class/CobranzasExclusion.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

$VIGENTES = [
    'FRJUD' => ['MOTIVO' => 'En gestion judicial', 'USUARIO' => 'tesoreria', 'FECHA_ALTA' => '2026-10-01 10:30'],
    'LAREF' => ['MOTIVO' => 'Refinancia por fuera', 'USUARIO' => 'tesoreria', 'FECHA_ALTA' => '2026-10-02 09:00']
];

// ============================================================================
// El motivo y el codigo se validan en el servidor
// ============================================================================

seccion('el motivo es obligatorio');

chequearLanza('vacio se rechaza', function () { CobranzasExclusion::validarMotivo(''); });
chequearLanza('solo espacios tambien', function () { CobranzasExclusion::validarMotivo("   \t "); });
chequearLanza('null tambien', function () { CobranzasExclusion::validarMotivo(null); });
chequear('se recorta', 'En gestion judicial', CobranzasExclusion::validarMotivo('  En gestion judicial  '));
chequear('y se acota al largo de la columna', CobranzasExclusion::LARGO_MOTIVO,
    mb_strlen(CobranzasExclusion::validarMotivo(str_repeat('x', 300))));

seccion('solo se excluyen franquicias');

chequear('el codigo se normaliza', 'FRJUD', CobranzasExclusion::validarCodigo(' frjud '));
chequear('una L entra', 'LAREF', CobranzasExclusion::validarCodigo('LAREF'));
chequearLanza('un mayorista no', function () { CobranzasExclusion::validarCodigo('MA0001'); });
chequearLanza('un codigo vacio no', function () { CobranzasExclusion::validarCodigo(''); });

// ============================================================================
// Las facturas de un excluido salen de lo que suma, marcadas
// ============================================================================

seccion('las facturas de un excluido salen de lo que suma');

$facturas = [
    ['COD_CLI' => 'FRBIEN', 'Cobro' => '2026-09-07', 'importe_neto' => 100.0],
    ['COD_CLI' => 'FRJUD', 'Cobro' => '2026-09-07', 'importe_neto' => 40.0],
    ['COD_CLI' => 'frjud ', 'Cobro' => '2026-09-08', 'importe_neto' => 60.0],
    ['COD_CLI' => 'LAREF', 'Cobro' => '2026-09-08', 'importe_neto' => 25.0],
    ['COD_CLI' => 'FROTRO', 'Cobro' => '2026-09-08', 'importe_neto' => 10.0]
];

$sep = CobranzasExclusion::separar($facturas, $VIGENTES, 'COD_CLI');

chequear('quedan las de los clientes que se cobran', ['FRBIEN', 'FROTRO'],
    array_column($sep['incluidos'], 'COD_CLI'));
chequear('las del excluido salen, sin importar mayusculas ni espacios', 3, count($sep['excluidos']));
chequear('una lista sigue siendo lista', [0, 1], array_keys($sep['incluidos']));
chequear('salen marcadas', [true, true, true], array_column($sep['excluidos'], 'EXCLUIDO'));
chequear('con el motivo', 'En gestion judicial', $sep['excluidos'][0]['MOTIVO_EXCLUSION']);
chequear('quien', 'tesoreria', $sep['excluidos'][0]['EXCLUSION_USUARIO']);
chequear('y cuando', '2026-10-01 10:30', $sep['excluidos'][0]['EXCLUSION_FECHA']);
chequear('las que quedan no se marcan', false, isset($sep['incluidos'][0]['EXCLUIDO']));
chequear('sin exclusiones, la lista no se toca', $facturas,
    CobranzasExclusion::separar($facturas, [], 'COD_CLI')['incluidos']);

// El eje y los KPIs salen de los incluidos: es lo que arma el controller.
$h = new Horizonte(3, 3, [], new DateTime('2026-09-06'));
$payload = EjeVista::armar($h, $sep['incluidos'], 'Cobro', 'importe_neto');
$payloadExcl = EjeVista::armar($h, $sep['excluidos'], 'Cobro', 'importe_neto');

chequear('el total de la pestana no cuenta al excluido', 110.0, $payload['totales']['total_horizonte']);
chequear('las excluidas se arman aparte, en las mismas columnas', 125.0,
    $payloadExcl['totales']['total_horizonte']);
chequear('y entre las dos dan el total sin excluir', 235.0,
    $payload['totales']['total_horizonte'] + $payloadExcl['totales']['total_horizonte']);

// En Resumen la fila es un cliente: el excluido es una sola fila, entera
// excluida, porque la exclusion es por cliente.
$resExcl = EjeVista::armarAgrupado($h, $sep['excluidos'], 'COD_CLI', 'Cobro', 'importe_neto');
chequear('en Resumen el excluido conserva la marca y el motivo', [true, 'En gestion judicial'],
    [$resExcl['filas'][0]['EXCLUIDO'], $resExcl['filas'][0]['MOTIVO_EXCLUSION']]);

seccion('el cartel y el aviso dicen cuanta plata quedo afuera');

$res = CobranzasExclusion::resumen($sep['excluidos'], 'COD_CLI', 'importe_neto');

chequear('facturas', 3, $res['facturas']);
chequear('clientes', 2, $res['clientes']);
chequear('importe', 125.0, $res['importe']);
chequear('un motivo por cliente', ['FRJUD: En gestion judicial', 'LAREF: Refinancia por fuera'], $res['motivos']);

// Los totales del tablero llegan por fecha y cliente: una fila son varias facturas.
$resAgr = CobranzasExclusion::resumen(
    [['COD_CLI' => 'FRJUD', 'IMPORTE' => 70.0, 'CANT' => 4, 'MOTIVO_EXCLUSION' => 'x']],
    'COD_CLI', 'IMPORTE', 'CANT');
chequear('una fila agrupada cuenta sus facturas', 4, $resAgr['facturas']);

$aviso = CobranzasExclusion::textoAviso($res);
chequear('el aviso dice cuanto', true, strpos($aviso, '3 facturas por $ 125,00') !== false);
chequear('y por que', true, strpos($aviso, 'En gestion judicial') !== false);
chequear('sin excluidos no hay aviso', null,
    CobranzasExclusion::textoAviso(CobranzasExclusion::resumen([], 'COD_CLI', 'importe_neto')));

// ============================================================================
// El circuito: las tres lecturas y las tres series
// ============================================================================

seccion('la exclusion se aplica en las dos solapas y en el tablero');

$ingSrc = file_get_contents(__DIR__ . '/../Class/Ingresos.php');
$provSrc = file_get_contents(__DIR__ . '/../Class/Providers/IngresosProvider.php');
$ctrlSrc = file_get_contents(__DIR__ . '/../Controller/IngresosController.php');

chequear('la proyeccion separa a los excluidos', true,
    preg_match("/return \\\$this->separarExcluidosFR\(\s*\\\$this->filtrarUniversoFR\(\\\$itemsProyectados/", $ingSrc) === 1);
chequear('Real a Cobrar de la pestana tambien', true,
    preg_match("/\\\$data = \\\$this->separarExcluidosFR\(\s*\\\$this->filtrarUniversoFR\(\\\$data/", $ingSrc) === 1);
chequear('y Real en los totales del tablero', true,
    preg_match("/\\\$filasReal = \\\$this->separarExcluidosFR\(\s*\\\$this->filtrarUniversoFR\(\\\$filasReal/", $ingSrc) === 1);
// Las tres series del tablero salen de getCobranzasFRTotales(): COBRANZA_REAL
// ('real'), COBRANZA_PROYECTADA ('proyectado') y COBRANZA ('todos'). No hay
// otra lectura que se pueda saltear la exclusion.
chequear('el proveedor arma las tres series con getCobranzasFRTotales()', 3,
    substr_count($provSrc, '$ingresos->getCobranzasFRTotales('));
chequear('y deja el aviso de lo excluido', true,
    strpos($provSrc, 'CobranzasExclusion::textoAviso($ingresos->resumenExcluidosFR())') !== false);
chequear('el controller arma las excluidas aparte', true,
    strpos($ctrlSrc, "\$payloadFr['filas_excluidas'] = payloadCobranzas(\$excluidas, \$summary)['filas'];") !== false);
chequear('con el resumen para el cartel', true,
    strpos($ctrlSrc, "\$payloadFr['excluidos'] = CobranzasExclusion::resumen(") !== false);

seccion('con "ver excluidos" se listan marcadas, sin sumar');

$frJs = file_get_contents(__DIR__ . '/../Js/Ingresos-Cobranzas_fr.js');
$frTab = file_get_contents(__DIR__ . '/../Tabs/cobranzas_fr.php');

chequear('el switch existe, apagado por defecto', true,
    strpos($frTab, '<input class="form-check-input" type="checkbox" id="verExcluidosCob">') !== false);
chequear('el cartel existe fuera de la tabla', true, strpos($frTab, 'id="excluidosCob"') !== false);
chequear('las filas dibujadas suman las excluidas solo si se piden', true,
    strpos($frJs, "verExcluidos() ? filas.concat(datosCobranzas.filas_excluidas || []) : filas") !== false);
chequear('la tabla se dibuja con filasDibujadas()', true,
    strpos($frJs, 'filasDibujadas().forEach(function(item) {') !== false);

// El pie suma filasFiltradas(), que sale de datosCobranzas.filas: una
// excluida no puede sumar aunque este dibujada.
$filtradas = substr($frJs, strpos($frJs, 'function filasFiltradas()'), 600);
chequear('el pie no suma las excluidas', false, strpos($filtradas, 'filas_excluidas') !== false);
chequear('y la fila excluida se atenua', true, strpos($frJs, "clases.push('fila-excluida')") !== false);
chequear('la fecha de una excluida es de solo lectura', true,
    strpos($frJs, 'if (!editable() || item.EXCLUIDO) {') !== false);
chequear('el cartel se pinta con cada carga', true, strpos($frJs, "pintarAvisos();\n                    pintarExcluidos();") !== false
    || strpos($frJs, "pintarAvisos();\r\n                    pintarExcluidos();") !== false);

// ============================================================================
// Historial, tabla faltante, permiso y PPP
// ============================================================================

seccion('volver a incluir deja historial');

$exSrc = file_get_contents(__DIR__ . '/../Class/CobranzasExclusion.php');
$exSql = file_get_contents(__DIR__ . '/../sql/cashflow_cobranzas_cliente_excluido.sql');

chequear('incluir da de baja la vigente con quien y cuando', true,
    strpos($exSrc, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA . "') !== false);
chequear('la clase no borra nunca', 0, preg_match_all('/\bDELETE\b/', $exSrc));
chequear('excluir de nuevo inserta otra fila', true,
    strpos($exSrc, 'INSERT INTO dbo." . self::TABLA') !== false);
chequear('una sola vigente por cliente: indice unico filtrado', true,
    preg_match('/CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COBEXC_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO \(COD_CLIENT\)\s+WHERE VIGENTE = 1/', $exSql) === 1);
chequear('el motivo vacio lo frena tambien la base', true,
    strpos($exSql, 'CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)') !== false);
chequear('el script es reejecutable', true,
    strpos($exSql, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO', 'U') IS NULL") !== false);
chequear('y no borra nada', 0, preg_match_all('/\b(DROP|DELETE|TRUNCATE)\b/', $exSql));

seccion('sin la tabla, nadie esta excluido');

// Una exclusion sin la tabla: la conexion no se usa, porque tablaCreada()
// ya contesta que no.
$sinTabla = new class extends CobranzasExclusion {
    public function tablaCreada() { return false; }
};

chequear('vigentes() vacio, que es lo cierto', [], $sinTabla->vigentes());
chequear('el aviso dice que script correr', true,
    strpos($sinTabla->avisoSinTabla(), 'sql/cashflow_cobranzas_cliente_excluido.sql') !== false);
chequearLanza('excluir falla con ese mismo motivo', function () use ($sinTabla) {
    $sinTabla->excluir('FRJUD', 'motivo', 'prueba');
}, CobranzasExclusion::textoSinTabla());
chequearLanza('y volver a incluir tambien', function () use ($sinTabla) {
    $sinTabla->incluir('FRJUD', 'prueba');
}, CobranzasExclusion::textoSinTabla());
chequear('la tarjeta deshabilita el switch sin la tabla', true,
    strpos(file_get_contents(__DIR__ . '/../Js/Parametros-Cobranzas.js'),
        "(exclusionDisponible ? '' : ' disabled')") !== false);

seccion('excluir pide permiso de edicion de Parametros -> Cobranzas');

foreach (['excluirClienteCobranza', 'incluirClienteCobranza'] as $accion) {
    chequear($accion . ' esta en el mapa de escrituras', [['parametros', 'COBRANZAS']],
        AuthCashflow::ESCRITURAS['Parametros'][$accion] ?? null);
}

$paramJs = file_get_contents(__DIR__ . '/../Js/Parametros-Cobranzas.js');
chequear('sin permiso, el switch no se dibuja', true,
    strpos($paramJs, 'return Permisos.segun(tbody, control, lectura);') !== false);
chequear('excluir pide el motivo en un dialogo', true,
    strpos($paramJs, 'Notificacion.pedirTexto({') !== false);
chequear('volver a incluir pide confirmacion', true,
    strpos($paramJs, 'Notificacion.confirmar({') !== false);

seccion('el PPP del grupo no cambia al excluir un cliente');

$clientesPPP = [
    'FRJUD' => ['cod_cliente' => 'FRJUD', 'razon_social' => 'JUDICIAL', 'cod_agrup' => 'GR1',
        'nombre_agrup' => 'GRUPO UNO', 'es_grupo' => true, 'ppp_calculado' => 25, 'cant_recibos' => 8,
        'cant_clientes_ppp' => 2, 'ppp_manual' => null, 'dias_pp_max' => 0, 'ppp_efectivo' => 25],
    'FRBIEN' => ['cod_cliente' => 'FRBIEN', 'razon_social' => 'AL DIA', 'cod_agrup' => 'GR1',
        'nombre_agrup' => 'GRUPO UNO', 'es_grupo' => true, 'ppp_calculado' => 25, 'cant_recibos' => 8,
        'cant_clientes_ppp' => 2, 'ppp_manual' => null, 'dias_pp_max' => 0, 'ppp_efectivo' => 25]
];

$sinExcluir = Parametros::agruparPorAgrupador(Parametros::conExclusion($clientesPPP, []));
$conExcluir = Parametros::agruparPorAgrupador(Parametros::conExclusion($clientesPPP, $VIGENTES));

foreach (['ppp_calculado', 'cant_recibos', 'cant_clientes_ppp', 'ppp_manual', 'ppp_efectivo'] as $campo) {
    chequear('el ' . $campo . ' del grupo es el mismo', $sinExcluir[0][$campo], $conExcluir[0][$campo]);
}

$judicial = array_values(array_filter($conExcluir[0]['clientes'], function ($c) {
    return $c['cod_cliente'] === 'FRJUD';
}))[0];

chequear('el excluido sigue en su grupo', 2, count($conExcluir[0]['clientes']));
chequear('con su PPP efectivo', 25, $judicial['ppp_efectivo']);
chequear('el grupo cuenta cuantos excluidos tiene', 1, $conExcluir[0]['cant_excluidos']);
chequear('sin excluidos, cero', 0, $sinExcluir[0]['cant_excluidos']);
chequear('el cliente lleva motivo, quien y cuando para el tooltip',
    ['motivo' => 'En gestion judicial', 'usuario' => 'tesoreria', 'fecha' => '2026-10-01 10:30'],
    $judicial['excluido']);
chequear('la vista del PPP no lee la tabla de exclusiones', false,
    strpos(file_get_contents(__DIR__ . '/../sql/cashflow_cobranzas_ppp_grupo.sql'),
        'COBRANZAS_CLIENTE_EXCLUIDO') !== false);
