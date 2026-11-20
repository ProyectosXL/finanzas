<?php
/**
 * La exclusion de proveedores por modulo: Proveedores Locales, Tarjetas Pagos
 * Corporativos y Alquileres (Class/ProveedoresExclusion.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. MODULOS Y EL CHECK SE DESINCRONIZAN. Un modulo que esta en el PHP y no
 *      en el CHECK falla al guardar; uno que esta en el CHECK y no en el PHP no
 *      lo puede escribir nadie.
 *
 *   2. UNA COLUMNA SE EDITA CON EL PERMISO EQUIVOCADO. Quien maneja Alquileres
 *      podria sacar a un proveedor de Proveedores Locales, o al reves.
 *
 *   3. EXCLUIR DE TARJETAS APAGA LA EXCLUSION POR FACTURA, o al reves: son dos
 *      decisiones distintas y conviven.
 *
 *   4. EL MOTIVO DEJA DE SER OBLIGATORIO en el servidor.
 *
 *   5. UNA FACTURA DE ALQUILER EXCLUIDA A MANO (un pago de contrato) SUMA EN
 *      ALGUN LADO, vuelve a Proveedores Locales, o se imputa.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/ProveedoresExclusion.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';
require_once __DIR__ . '/../Class/TarjetasCorporativas.php';
require_once __DIR__ . '/../Class/AlquileresFacturas.php';
require_once __DIR__ . '/../Class/Proveedores.php';

seccion('MODULOS y el CHECK dicen lo mismo');

$esperados = ['PROV_LOCALES', 'TARJETAS_CORP', 'ALQUILERES'];
chequear('los tres modulos, en orden', $esperados, array_keys(ProveedoresExclusion::MODULOS));
chequear('cada uno con su pestaña', $esperados, array_keys(ProveedoresExclusion::PESTANA));

$listaCheck = function ($archivo) {
    $sql = file_get_contents(__DIR__ . '/../sql/' . $archivo);
    preg_match_all("/CHECK \(MODULO IN \(([^)]*)\)\)/", $sql, $m);
    return array_map(function ($l) {
        preg_match_all("/'([A-Z_]+)'/", $l, $v);
        return $v[1];
    }, $m[1]);
};
chequear('el CHECK del script que amplia es MODULOS', [$esperados],
    $listaCheck('cashflow_prov_exclusion_modulos_ampliar.sql'));
chequear('el de una instalacion nueva tambien', [$esperados], $listaCheck('cashflow_prov_exclusion_modulo.sql'));
chequear('el script nombrado en la clase existe', true,
    file_exists(__DIR__ . '/../' . ProveedoresExclusion::SCRIPT_MODULOS));
chequear('el script es reejecutable: pregunta antes de reemplazar', true,
    strpos(file_get_contents(__DIR__ . '/../' . ProveedoresExclusion::SCRIPT_MODULOS),
        "definition LIKE '%TARJETAS_CORP%'") !== false);

chequear('un modulo desconocido se rechaza', true, (function () {
    try { ProveedoresExclusion::validarModulo('COMEX'); return false; } catch (Exception $e) { return true; }
})());
chequear('el modulo se normaliza', 'ALQUILERES', ProveedoresExclusion::validarModulo(' alquileres '));
chequear('el motivo es obligatorio en el servidor', true, (function () {
    try { ProveedoresExclusion::validarMotivo('   '); return false; } catch (Exception $e) { return true; }
})());

seccion('Cada columna se edita con el permiso de su modulo');

chequear('excluir pide la pestaña del modulo, no una fija', [[AuthCashflow::TAB_DEL_MODULO, null]],
    AuthCashflow::ESCRITURAS['Proveedores']['excluirProveedor']);
chequear('incluir tambien', [[AuthCashflow::TAB_DEL_MODULO, null]],
    AuthCashflow::ESCRITURAS['Proveedores']['incluirProveedor']);
chequear('el controller necesita el modulo para autorizar', true,
    AuthCashflow::necesitaTabDelModulo('Proveedores', 'excluirProveedor'));
chequear('Alquileres -> alquileres', [['alquileres', null]],
    AuthCashflow::destinosDe('Proveedores', 'excluirProveedor', ProveedoresExclusion::pestanaDe('ALQUILERES')));
chequear('Tarjetas -> pagos_tarjetas', [['pagos_tarjetas', null]],
    AuthCashflow::destinosDe('Proveedores', 'incluirProveedor', ProveedoresExclusion::pestanaDe('TARJETAS_CORP')));
chequearLanza('sin el modulo no se autoriza nada', function () {
    AuthCashflow::destinosDe('Proveedores', 'excluirProveedor', null);
});

/* Quien ve el Maestro (Proveedores Locales) y maneja Alquileres, pero no edita
   Proveedores Locales ni Tarjetas. */
$permisos = ['cashflow.tab.proveedores_locales' => true, 'cashflow.tab.alquileres' => true,
             'cashflow.editar.alquileres' => true, 'cashflow.tab.pagos_tarjetas' => true];
$puede = function ($mod) use ($permisos) {
    return AuthCashflow::permiteEditar($permisos, false, ProveedoresExclusion::pestanaDe($mod));
};
chequear('con el permiso de Alquileres edita la columna de Alquileres', true, $puede('ALQUILERES'));
chequear('pero no la de Proveedores Locales', false, $puede('PROV_LOCALES'));
chequear('ni la de Tarjetas', false, $puede('TARJETAS_CORP'));

$fuenteCtrl = file_get_contents(__DIR__ . '/../Controller/ProveedoresController.php');
chequear('el controller autoriza con la pestaña del modulo', true,
    strpos($fuenteCtrl, "autorizar('Proveedores', \$action, \$pestanaDinamica)") !== false);
chequear('y manda a la pantalla si se puede editar cada columna', true,
    strpos($fuenteCtrl, "'puede_editar' => AuthCashflow::puedeEditar(ProveedoresExclusion::PESTANA[\$mod])") !== false);
$js = file_get_contents(__DIR__ . '/../Js/Proveedores-Proveedores_locales.js');
chequear('la pantalla dibuja los botones con el permiso de esa columna', true,
    strpos($js, '!m.puede_editar ? \'\'') !== false);
chequear('y manda el modulo al excluir', true,
    strpos($js, '{ cod_provee: cod, motivo: motivo, modulo: modulo }') !== false);
$tab = file_get_contents(__DIR__ . '/../Tabs/proveedores_locales.php');
chequear('el Maestro tiene las tres columnas', [1, 1, 1], [substr_count($tab, '>PROV. LOCALES</th>'),
    substr_count($tab, '>TARJETAS</th>'), substr_count($tab, '>ALQUILERES</th>')]);

seccion('Tarjetas: el proveedor sale del universo; la exclusion por factura sigue');

$tc = function ($cod, $n, $imp) {
    return ['COD_PROVEE' => $cod, 'RAZON_SOC' => 'P ' . $cod, 'T_COMP' => 'FAC', 'N_COMP' => $n,
            'FECHA_VTO' => '2026-11-20', 'IMPORTE_PENDIENTE' => $imp,
            'FORMA_PAGO_VIGENTE' => TarjetasCorporativas::FORMA, 'FORMA_PAGO_MAESTRO' => TarjetasCorporativas::FORMA];
};
$pend = [$tc('OGAAA', 'A1', 100.0), $tc('OGBBB', 'B1', 50.0), $tc('OGBBB', 'B2', 25.0)];
$excl = ['OGBBB' => ['MOTIVO' => 'Se paga desde otra tarjeta', 'USUARIO' => 'X', 'FECHA_ALTA' => null]];

chequear('el excluido de Tarjetas no entra al universo', ['A1'],
    array_column(TarjetasCorporativas::universo($pend, null, $excl), 'N_COMP'));
chequear('sin excluidos entran todos', 3, count(TarjetasCorporativas::universo($pend)));
$r = TarjetasCorporativas::resolver(TarjetasCorporativas::universo($pend, null, $excl), [],
    [Proveedores::clavePago('OGAAA', 'FAC', 'A1') => ['MOTIVO' => 'duplicada']], [], [],
    [], ['2026-11'], '2026-11-10');
chequear('la exclusion por factura sigue marcando la suya', true, $r['filas'][0]['EXCLUIDA_TARJETAS']);
$av = TarjetasCorporativas::avisoExcluidosProveedor($pend, $excl);
chequear('el aviso: informativo, cuanto y donde se revisa', [Aviso::INFO, true, true],
    [$av[0]['nivel'], strpos($av[0]['texto'], '$ 75,00') !== false, strpos($av[0]['texto'], 'Maestro') !== false]);
chequear('sin excluidos, sin aviso', [], TarjetasCorporativas::avisoExcluidosProveedor($pend, []));

seccion('Alquileres: excluido no entra ni vuelve a Proveedores Locales');

$ctx = AlquileresFacturas::contexto(['SAPALA'], ['SAPALA'],
    AlquileresFacturas::condicion(true, ['REAL'], true));
$f = ['COD_PROVEE' => 'SAPALA', 'IMPORTE_PENDIENTE' => 10.0];
chequear('excluido de Alquileres: no va a la pestaña', AlquileresFacturas::A_EXCLUIDA,
    AlquileresFacturas::destino($f, $ctx));
chequear('ni vuelve a Proveedores Locales', [], AlquileresFacturas::deProveedoresLocales([$f], $ctx));


seccion('Alquileres: una factura suelta que no se paga por aca (pagos de contrato)');

require_once __DIR__ . '/../Class/AlquileresFacturaExclusion.php';
require_once __DIR__ . '/../Class/AlquileresImputacion.php';

$cuotaX = ['COD_PROVEE' => 'SAPALA', 'RAZON_SOC' => 'IRSA', 'T_COMP' => 'FAC', 'N_COMP' => 'K1', 'FECHA_VTO' => '2026-11-20',
           'FECHA_EMIS' => '2026-11-02', 'IMPORTE_VTO' => 5000.0, 'IMPORTE_PENDIENTE' => 5000.0, 'LEYENDA' => 'LLAVE'];
$cuotaY = ['N_COMP' => 'K2', 'IMPORTE_PENDIENTE' => 700.0, 'IMPORTE_VTO' => 700.0] + $cuotaX;
$claveX = AlquileresFacturas::claveCuota($cuotaX);
$exclX = [$claveX => ['MOTIVO' => 'Pago de contrato: lo paga Legales', 'USUARIO_ALTA' => 'ANA', 'FECHA_ALTA' => '2026-11-19 09:00']];
$ctxF = AlquileresFacturas::contexto(['SAPALA'], [], AlquileresFacturas::condicion(true, ['REAL'], true), $exclX);

chequear('la cuota excluida va a su destino: ni Alquileres ni Proveedores Locales',
    [AlquileresFacturas::A_EXCLUIDA_FACTURA, AlquileresFacturas::A_ALQUILERES],
    [AlquileresFacturas::destino($cuotaX, $ctxF), AlquileresFacturas::destino($cuotaY, $ctxF)]);
chequear('no vuelve a Proveedores Locales', [], AlquileresFacturas::deProveedoresLocales([$cuotaX], $ctxF));
$partesF = AlquileresFacturas::partir([$cuotaX, $cuotaY], $ctxF);
chequear('partir la separa', [1, 1, 0], [count($partesF[AlquileresFacturas::A_EXCLUIDA_FACTURA]),
    count($partesF[AlquileresFacturas::A_ALQUILERES]), count($partesF[AlquileresFacturas::A_PROV_LOCALES])]);
chequear('Proveedores Locales dice por que no la muestra', true,
    strpos(AlquileresFacturas::avisoFueraDeProvLocales($partesF)[0]['texto'], 'se excluyeron a mano en Alquileres') !== false);
$ctxSinCond = AlquileresFacturas::contexto(['SAPALA'], [], AlquileresFacturas::condicion(true, [], true), $exclX);
chequear('sin la condicion de seguridad todo queda en Proveedores Locales, tambien la excluida',
    AlquileresFacturas::A_PROV_LOCALES, AlquileresFacturas::destino($cuotaX, $ctxSinCond));

$hab = [];
for ($d = '2026-11-01'; $d <= '2027-01-31'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $hab[$d] = date('N', strtotime($d)) <= 5; }
$apl = AlquileresImputacion::aplicar(['pagos' => [], 'meses_pago' => ['2026-11'], 'pendientes' => [$cuotaY],
    'excluidas' => [], 'excluidas_factura' => [$cuotaX], 'exclusiones' => $exclX, 'vtos' => [], 'imputaciones' => [],
    'en_tango' => [], 'ctx' => $ctxF, 'habiles' => $hab, 'hoy' => '2026-11-10']);
$filaX = array_values(array_filter($apl['facturas'], function ($f) { return $f['n_comp'] === 'K1'; }))[0];
chequear('en la grilla dice Excluida, con motivo, usuario y fecha, y no suma', [AlquileresImputacion::EXCLUIDA,
    'Pago de contrato: lo paga Legales', 'ANA', 0.0],
    [$filaX['estado'], $filaX['excluida']['motivo'], $filaX['excluida']['usuario'], $filaX['a_pagar']]);
chequear('no cuenta como sin imputar; la tarjeta dice cuantas y cuanto', [1, 700.0, 1, 5000.0],
    [$apl['kpis']['sin_imputar_n'], $apl['kpis']['sin_imputar_importe'], $apl['kpis']['excluidas_n'], $apl['kpis']['excluidas_importe']]);
chequear('y un aviso informativo', true, (bool) array_filter($apl['avisos'], function ($a) {
    return $a['nivel'] === Aviso::INFO && strpos($a['texto'], 'excluidas a mano') !== false; }));

$imp = AlquileresImputacion::validar(['facturas' => [['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'K1',
    'vto_tango' => '2026-11-20', 'pendiente' => 5000.0]], 'todos' => true, 'mes' => '2026-11'],
    ['pendientes' => [], 'imputaciones' => [], 'locales' => [1 => ['nombre' => 'ABASTO', 'razones' => ['SAPALA']]],
     'tipos' => [], 'meses' => ['2026-11'], 'excluidas' => $exclX]);
chequear('una excluida no se imputa', [false, true], [$imp['ok'], strpos(implode(' ', $imp['errores']), 'está excluida') !== false]);

$eX = ['imputables' => [AlquileresFacturas::claveCuota($cuotaY) => $cuotaY], 'imputadas' => [], 'excluidas' => $exclX];
$cY = ['cod_provee' => 'SAPALA', 't_comp' => 'FAC', 'n_comp' => 'K2', 'vto_tango' => '2026-11-20'];
$cX = ['n_comp' => 'K1'] + $cY;
chequear('excluir con motivo', [true, 1], [AlquileresFacturaExclusion::validar('EXCLUIR', [$cY], 'Pago de contrato', $eX)['ok'],
    count(AlquileresFacturaExclusion::validar('EXCLUIR', [$cY, $cY], 'Pago de contrato', $eX)['cuotas'])]);
chequear('el motivo es obligatorio', false, AlquileresFacturaExclusion::validar('EXCLUIR', [$cY], '   ', $eX)['ok']);
chequear('no se excluye una imputada: primero se deshace', [false, true], (function () use ($cY, $eX) {
    $v = AlquileresFacturaExclusion::validar('EXCLUIR', [$cY], 'x', ['imputadas' => [AlquileresFacturas::claveCuota(
        ['COD_PROVEE' => 'SAPALA', 'T_COMP' => 'FAC', 'N_COMP' => 'K2', 'FECHA_VTO' => '2026-11-20']) => 12]] + $eX);
    return [$v['ok'], strpos(implode(' ', $v['errores']), 'deshacé primero') !== false];
})());
chequear('ni dos veces la misma', false, AlquileresFacturaExclusion::validar('EXCLUIR', [$cX], 'x', $eX)['ok']);
chequear('volver a incluir una excluida, sin motivo', true, AlquileresFacturaExclusion::validar('INCLUIR', [$cX], null, $eX)['ok']);
chequear('una que no esta excluida no se incluye', false, AlquileresFacturaExclusion::validar('INCLUIR', [$cY], null, $eX)['ok']);

$s61 = file_get_contents(__DIR__ . '/../' . AlquileresFacturaExclusion::SCRIPT);
chequear('el script 61: la tabla, el motivo obligatorio y una vigente por cuota', [true, true, true, false], [
    strpos($s61, 'CREATE TABLE dbo.' . AlquileresFacturaExclusion::TABLA) !== false,
    strpos($s61, 'CHECK (LEN(LTRIM(RTRIM(MOTIVO))) > 0)') !== false,
    (bool) preg_match('/UX_RO_T_CF_ALQFEX_VIGENTE\s+ON dbo\.RO_T_CASHFLOW_ALQ_FACTURA_EXCLUIDA \(COD_PROVEE, T_COMP, N_COMP, FECHA_VTO_TANGO\)\s+WHERE VIGENTE = 1/', $s61),
    (bool) preg_match('/\b(DROP|DELETE|TRUNCATE)\b/i', $s61)]);
$clsX = file_get_contents(__DIR__ . '/../Class/AlquileresFacturaExclusion.php');
chequear('volver a incluir es baja logica, y escribe con usuarioDeEscritura', [true, false, 2], [
    strpos($clsX, 'SET VIGENTE = 0, " . Auditoria::SET_BAJA') !== false, (bool) preg_match('/DELETE\s+FROM/i', $clsX),
    substr_count($clsX, '$usuario = AuthCashflow::usuarioDeEscritura($usuario);')]);

$ctrlA = file_get_contents(__DIR__ . '/../Controller/AlquileresController.php');
$jsFa = file_get_contents(__DIR__ . '/../Js/Alquileres-Facturas.js');
$tabAl = file_get_contents(__DIR__ . '/../Tabs/alquileres.php');
foreach (['excluirFacturas', 'incluirFacturas'] as $accion) {
    chequear($accion . ' pide cashflow.editar.alquileres, el controller la atiende y el JS la pide',
        [[['alquileres', null]], 1, true],
        [AuthCashflow::ESCRITURAS['Alquileres'][$accion] ?? null, substr_count($ctrlA, "case '" . $accion . "':"),
         strpos($jsFa, "'" . $accion . "'") !== false]);
}
chequear('la pantalla: Ver excluidas, Excluir masivo y la tarjeta', [true, true, true], [
    strpos($tabAl, 'id="alqFactVerExcluidas"') !== false, strpos($tabAl, 'id="btnAlqExcluir"') !== false,
    strpos($tabAl, 'id="alqFactKpiExcl"') !== false]);
chequear('sin permiso o sin el script 61, no se dibuja', true,
    strpos($jsFa, "return puedeEditar() && !!datos.tabla_exclusion;") !== false);
