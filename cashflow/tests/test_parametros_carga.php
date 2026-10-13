<?php
/**
 * Parametros carga cada sub-pestaña recien cuando se abre (Js/parametros-carga.js),
 * y getTodo resuelve solo el modulo que se le pide.
 *
 * Lo que se rompe en silencio es el cableado: una sub-pestaña que vuelve a
 * pedir getTodo entero al arrancar no tira ningun error, solo vuelve lenta la
 * pantalla. Por eso se fija leyendo los archivos, como test_tablas_controles.php,
 * y lo del backend contra la base cuando hay una.
 */

require_once __DIR__ . '/../Class/Parametros.php';

$leer = function ($ruta) {
    return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $ruta));
};

seccion('getTodo con modulo resuelve solo ese');

chequear('un codigo declarado es un modulo', true, Parametros::esModulo('SALDOS'));
chequear('uno inventado no', false, Parametros::esModulo('INVENTADO'));
chequear('ni en minuscula: el controller lo pasa a mayuscula antes', false, Parametros::esModulo('saldos'));

$ctrl = $leer('Controller/ParametrosController.php');

chequear('el controller lee el modulo pedido y lo pasa', true,
    strpos($ctrl, "\$parametros->getModulosConDatos(\$moduloPedido)") !== false);
chequear('pedir un modulo exige poder ver Parametros, con su 401 o su 403', true,
    preg_match("/if \\(\\\$moduloPedido !== null\\) \\{\\s*autorizarLectura\\('parametros'\\);/", $ctrl) === 1);

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin base: getModulosConDatos() contra los datos');
} else {
    $p = new Parametros();

    $uno = $p->getModulosConDatos('COMPRAS_PROY');
    chequear('con modulo devuelve solo ese', ['COMPRAS_PROY'], array_column($uno, 'codigo'));
    chequear('con sus secciones resueltas', true, array_key_exists('generales', $uno[0]));

    $todos = $p->getModulosConDatos();
    chequear('sin modulo devuelve todos, como siempre',
        array_column(Parametros::getModulos(), 'codigo'), array_column($todos, 'codigo'));

    // El rescate de los generales que todavia figuran como de Ventas no
    // depende de que se resuelva Ventas: vive en la rama de GENERALES.
    $gen = $p->getModulosConDatos('GENERALES');
    chequear('Generales solo trae sus parametros (o los rescata de Ventas)', true,
        !empty($gen[0]['generales']));

    chequearLanza('un modulo que no existe es un error', function () use ($p) {
        $p->getModulosConDatos('INVENTADO');
    });
}

$fuenteParam = $leer('Class/Parametros.php');

chequear('el rescate de los generales de Ventas sigue en la rama de GENERALES', true,
    strpos($fuenteParam, "if (\$codigo === 'GENERALES' && empty(\$modulo['generales'])) {") !== false
    && strpos($fuenteParam, "\$this->getParametros('GENERAL', 'VENTAS')") !== false);

seccion('pedir un modulo sin permiso de lectura es un error');

// Sin sesion -como corren las pruebas- no hay usuario: la negativa es 401.
chequearLanza('sin usuario no se puede leer', function () {
    AuthCashflow::exigirLectura('parametros');
});
chequear('la lectura de Parametros es la de la pestaña', true,
    AuthCashflow::permiteVer(['cashflow.tab.parametros' => true], false, 'parametros'));
chequear('sin esa clave, no', false,
    AuthCashflow::permiteVer(['cashflow.editar.parametros.saldos' => true], false, 'parametros'));
chequear('los avisos generales solos son una lectura declarada', true,
    in_array('getAvisosGenerales', AuthCashflow::LECTURAS['Parametros'], true));

seccion('ninguna sub-pestaña pide datos al cargar la pagina salvo la primera');

$modulosJs = [
    'GENERALES' => 'Js/Parametros-Generales.js',
    'VENTAS' => 'Js/Parametros.js',
    'SALDOS' => 'Js/Parametros-Saldos.js',
    'COB_ELECTRONICOS' => 'Js/Parametros-Cob-Electronicos.js',
    'PRECHEQUEADO' => 'Js/Parametros-Prechequeado.js',
    'COBRANZAS' => 'Js/Parametros-Cobranzas.js',
    'COMPRAS_PROY' => 'Js/Parametros-Compras_proy.js',
    'LOGISTICA' => 'Js/Parametros-Logistica.js',
    'PROV_LOCALES' => 'Js/Parametros-Prov_locales.js',
    'TARJETAS' => 'Js/Parametros-Tarjetas.js',
    'CASHFLOW' => 'Js/Parametros-Estructura.js'
];

chequear('estan todas las sub-pestañas', array_column(Parametros::getModulos(), 'codigo'),
    array_keys($modulosJs));

foreach ($modulosJs as $codigo => $archivo) {
    $js = $leer($archivo);

    chequear($codigo . ': se registra en la carga diferida', 1,
        substr_count($js, "ParametrosCarga.registrar('" . $codigo . "', "));
    chequear($codigo . ': y no pide getTodo entero', 0, preg_match_all("/action=getTodo'/", $js));

    // El arranque no carga: registra. Se busca el cuerpo del arranque.
    $arranque = preg_match('/    function (inicializar|iniciar)\(\) \{.*?\n    \}\n/s', $js, $m) ? $m[0] : '';

    chequear($codigo . ': el arranque no llama a cargar', 0,
        preg_match('/^        cargar(PlazosGlobales|Escala|Clientes)?\(\);$/m', $arranque));
}

chequear('las que leen getTodo piden su modulo con ParametrosCarga.pedir()', 9,
    array_sum(array_map(function ($c) use ($modulosJs, $leer) {
        return substr_count($leer($modulosJs[$c]), "ParametrosCarga.pedir('" . $c . "')");
    }, array_keys($modulosJs))));

seccion('el helper: la primera visible, una vez, y la pantalla aparece con ella');

$carga = $leer('Js/parametros-carga.js');

chequear('carga ahora la que esta activa en el DOM', true,
    strpos($carga, "if (b.classList.contains('active')) {\n            asegurar(codigo);") !== false);
chequear('y las demas al abrirse', true,
    strpos($carga, "b.addEventListener('shown.bs.tab', function() { asegurar(codigo); });") !== false);
chequear('la segunda vez no pide nada', true,
    strpos($carga, 'return (r && r.cargado) ? Promise.resolve() : cargar(codigo);') !== false);
chequear('dos clics seguidos son un solo pedido', true,
    strpos($carga, "if (r.pendiente) {\n            return r.pendiente;") !== false);
chequear('una carga que falla se reintenta al volver', true,
    strpos($carga, 'r.cargado = (resultado !== false);') !== false);
chequear('pide getTodo con el modulo', true,
    strpos($carga, "'?action=getTodo&modulo=' + encodeURIComponent(codigo)") !== false);
chequear('la pantalla aparece cuando termina la primera, bien o mal', true,
    preg_match('/var fin = function\(\) \{.*?mostrarPestana\(\);/s', $carga) === 1);
chequear('y los avisos generales se pintan con cualquier carga', true,
    strpos($carga, 'if (!avisosTraidos) { refrescarAvisos(); }') !== false);

$tab = $leer('Tabs/parametros.php');

chequear('el helper se carga antes que las sub-pestañas', true,
    strpos($tab, 'Js/parametros-carga.js') < strpos($tab, "include __DIR__ . '/parametros_generales.php'"));
chequear('Ventas ya no maneja el wrapper de toda la pestaña', 0,
    substr_count($leer('Js/Parametros.js'), 'wrapperParametros'));
chequear('Cobranzas refresca los avisos generales con su Actualizar', true,
    strpos($leer('Js/Parametros-Cobranzas.js'), 'ParametrosCarga.refrescarAvisos();') !== false);
