<?php
/**
 * Que factura es de alquiler y a donde va (Class/AlquileresFacturas.php), y el
 * lector de su contexto (Class/AlquileresUniverso.php).
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. EL RUBRO DECIDE. Una factura de Mercado Libre (RUBRO = ALQUILER, no dada
 *      de alta) se iria de Proveedores Locales y no entraria en ningun lado.
 *
 *   2. LAS FACTURAS SALEN ANTES DE QUE HAYA DONDE CAER. Sin la fila de
 *      Alquileres activa, o sin las tablas de las imputaciones, una factura que
 *      sale de Proveedores Locales desaparece del tablero.
 *
 *   3. UNA FILA DE PROYECTADO SOLA ABRE LA PUERTA. La proyeccion no lleva las
 *      facturas reales: con solo esa fila, lo facturado no estaria en ningun lado.
 *
 *   4. EXCLUIDA = VUELVE A PROVEEDORES LOCALES. Es justo lo que la exclusion no
 *      es: sacar a proposito, de los dos lados.
 *
 *   5. EL NETO DE UNA CUOTA ES EL DEL COMPROBANTE, o un neto que falta es 0.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/AlquileresFacturas.php';
require_once __DIR__ . '/../Class/AlquileresUniverso.php';

$abierta = AlquileresFacturas::condicion(true, ['REAL', 'PROYECTADO'], true);
$ctx = AlquileresFacturas::contexto(['SAPALA', ' SAUNIC '], ['SAARCO'], $abierta);
$ctx['razones']['SAARCO'] = true;   // dada de alta y excluida

$f = function ($cod, $pend = 1000.0, $rubro = null) {
    return ['COD_PROVEE' => $cod, 'T_COMP' => 'FAC', 'N_COMP' => 'A0001' . $cod,
            'IMPORTE_PENDIENTE' => $pend, 'RUBRO' => $rubro];
};

seccion('La regla: dada de alta, no el rubro');

chequear('IRSA, dada de alta: va a Alquileres', AlquileresFacturas::A_ALQUILERES,
    AlquileresFacturas::destino($f('SAPALA'), $ctx));
chequear('el codigo se normaliza: " SAUNIC " es Cencosud', AlquileresFacturas::A_ALQUILERES,
    AlquileresFacturas::destino($f('SAUNIC'), $ctx));
chequear('Mercado Libre, con RUBRO = ALQUILER y sin alta: sigue en Proveedores Locales',
    AlquileresFacturas::A_PROV_LOCALES, AlquileresFacturas::destino($f('OGMERC', 1.0, 'ALQUILER'), $ctx));
chequear('y esDeAlquiler() dice lo mismo', false, AlquileresFacturas::esDeAlquiler('OGMERC', $ctx));
chequear('un proveedor cualquiera: Proveedores Locales', AlquileresFacturas::A_PROV_LOCALES,
    AlquileresFacturas::destino($f('OGXXXX'), $ctx));

$baja = AlquileresFacturas::contexto(['SAUNIC'], [], $abierta);
chequear('dar de baja a IRSA en Parametros: sus facturas vuelven a Proveedores Locales',
    AlquileresFacturas::A_PROV_LOCALES, AlquileresFacturas::destino($f('SAPALA'), $baja));

seccion('Excluida no es "vuelve a Proveedores Locales"');

chequear('dada de alta y excluida de Alquileres: no va a ningun lado', AlquileresFacturas::A_EXCLUIDA,
    AlquileresFacturas::destino($f('SAARCO'), $ctx));
$p = AlquileresFacturas::partir([$f('SAPALA'), $f('SAARCO'), $f('OGXXXX'), $f('SAUNIC')], $ctx);
chequear('partir: dos a Alquileres, en orden', ['SAPALA', 'SAUNIC'],
    array_column($p[AlquileresFacturas::A_ALQUILERES], 'COD_PROVEE'));
chequear('partir: la excluida aparte', ['SAARCO'], array_column($p[AlquileresFacturas::A_EXCLUIDA], 'COD_PROVEE'));
chequear('partir: Proveedores Locales se queda solo con la suya', ['OGXXXX'],
    array_column(AlquileresFacturas::deProveedoresLocales([$f('SAPALA'), $f('SAARCO'), $f('OGXXXX')], $ctx),
        'COD_PROVEE'));
chequear('excluir a un proveedor que NO esta dado de alta no cambia nada',
    AlquileresFacturas::A_PROV_LOCALES,
    AlquileresFacturas::destino($f('OGMERC'), AlquileresFacturas::contexto([], ['OGMERC'], $abierta)));

seccion('La condicion de seguridad');

chequear('las tres cosas: se cumple', ['cumple' => true, 'faltan' => []], $abierta);
chequear('con PAGOS (la fila de antes del split) tambien', true,
    AlquileresFacturas::condicion(true, ['PAGOS'], true)['cumple']);
chequear('la serie se compara sin mayusculas ni espacios', true,
    AlquileresFacturas::condicion(true, [' real '], true)['cumple']);
chequear('solo PROYECTADO: no se cumple, falta la fila de lo real', [AlquileresFacturas::SIN_FILA],
    AlquileresFacturas::condicion(true, ['PROYECTADO'], true)['faltan']);
chequear('modulo no disponible, sin fila y sin tablas: las tres', [AlquileresFacturas::SIN_MODULO,
    AlquileresFacturas::SIN_FILA, AlquileresFacturas::SIN_TABLAS],
    AlquileresFacturas::condicion(false, [], false)['faltan']);

$cerrada = AlquileresFacturas::contexto(['SAPALA', 'SAARCO'], ['SAARCO'],
    AlquileresFacturas::condicion(true, ['REAL'], false));
chequear('sin las tablas, IRSA se queda en Proveedores Locales', AlquileresFacturas::A_PROV_LOCALES,
    AlquileresFacturas::destino($f('SAPALA'), $cerrada));
chequear('y la excluida tambien: sin condicion, nada sale de Proveedores Locales',
    AlquileresFacturas::A_PROV_LOCALES, AlquileresFacturas::destino($f('SAARCO'), $cerrada));

seccion('El aviso critico');

$av = AlquileresFacturas::avisoCondicion($cerrada, [$f('SAPALA', 1500.5), $f('SAARCO', 500.0), $f('OGXXXX', 9.0)]);
chequear('uno, critico', [1, Aviso::DANGER], [count($av), $av[0]['nivel']]);
chequear('dice cuantas y cuanto', true, strpos($av[0]['texto'], '2 factura(s) de alquiler por $ 2.000,50') === 0);
chequear('y nombra el script que falta', true, strpos($av[0]['texto'], AlquileresFacturas::SCRIPT) !== false);
chequear('con la condicion cumplida, ninguno', [], AlquileresFacturas::avisoCondicion($ctx, [$f('SAPALA')]));
chequear('sin facturas de alquiler, ninguno', [], AlquileresFacturas::avisoCondicion($cerrada, [$f('OGXXXX')]));

seccion('El neto gravado de una cuota');

$c = ['NETO_GRAVADO_COMP' => 18944468.55, 'IMPORTE_COMP' => 25105588.63, 'IMPORTE_VTO' => 25105588.63];
chequear('una sola cuota: el neto del comprobante (Cencosud, 02/11/2026)', 18944468.55,
    AlquileresFacturas::netoDeCuota($c));
chequear('dos cuotas iguales: la mitad cada una', 500.0,
    AlquileresFacturas::netoDeCuota(['NETO_GRAVADO_COMP' => 1000.0, 'IMPORTE_COMP' => 1210.0, 'IMPORTE_VTO' => 605.0]));
chequear('sin neto en Tango: null, no 0', null,
    AlquileresFacturas::netoDeCuota(['NETO_GRAVADO_COMP' => null, 'IMPORTE_COMP' => 1210.0, 'IMPORTE_VTO' => 1210.0]));
chequear('neto en 0: tambien null', null,
    AlquileresFacturas::netoDeCuota(['NETO_GRAVADO_COMP' => 0.0, 'IMPORTE_COMP' => 1210.0, 'IMPORTE_VTO' => 1210.0]));
chequear('una fila sin los campos (de antes): null', null, AlquileresFacturas::netoDeCuota(['IMPORTE_VTO' => 10.0]));

seccion('El lector: solo lee');

$fuente = file_get_contents(__DIR__ . '/../Class/AlquileresUniverso.php');
chequear('AlquileresUniverso no tiene ni una sentencia de escritura', 0,
    preg_match_all('/\b(INSERT|UPDATE|DELETE|MERGE)\s+(INTO\s+)?dbo\./i', $fuente));

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base: el contexto real y el neto de Tango no se miran');
} else {
    require_once __DIR__ . '/../Class/CashflowRegistry.php';
    require_once __DIR__ . '/../Class/Proveedores.php';

    AlquileresUniverso::olvidar();
    $real = AlquileresUniverso::delPedido();
    chequear('el contexto real se arma sin lanzar', true, isset($real['condicion']['cumple'], $real['razones']));
    chequear('si el modulo no esta disponible, la condicion lo dice',
        !CashflowRegistry::disponible('ALQUILERES'),
        in_array(AlquileresFacturas::SIN_MODULO, $real['condicion']['faltan'], true));
    chequear('y se lee una sola vez por pedido', true, AlquileresUniverso::delPedido() === $real);

    $pend = (new Proveedores())->getPendientes(date('Y-m-d'));
    $sinCampo = array_filter($pend, function ($i) {
        return !array_key_exists('NETO_GRAVADO_COMP', $i) || !array_key_exists('IMPORTE_COMP', $i);
    });
    chequear('todos los pendientes traen el neto gravado y el total del comprobante', 0, count($sinCampo));
    $raros = array_filter($pend, function ($i) {
        return $i['NETO_GRAVADO_COMP'] !== null && $i['IMPORTE_COMP'] !== null
            && $i['NETO_GRAVADO_COMP'] > $i['IMPORTE_COMP'] + 0.01 && $i['IMPORTE_COMP'] > 0;
    });
    chequear('el neto nunca supera al total del comprobante', 0, count($raros));
}
