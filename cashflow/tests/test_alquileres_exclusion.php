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
