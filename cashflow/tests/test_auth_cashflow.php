<?php
/**
 * Permisos de escritura y origen de los procesos automaticos.
 *
 * No toca la base ni la sesion: las reglas se prueban con permiteVer() y
 * permiteEditar(), que reciben el juego de permisos como argumento, y el mapa
 * de acciones se contrasta contra el TEXTO de los controllers.
 */

require_once __DIR__ . '/../Class/AuthCashflow.php';
require_once __DIR__ . '/../Class/Menu.php';
require_once __DIR__ . '/../Class/Parametros.php';

/* ================================================================
   origen()
   ================================================================ */
seccion('origen de los procesos automaticos');

chequear('SISTEMA por defecto, con Clase.metodo',
    'SISTEMA:Saldos.sincronizar', AuthCashflow::origen('Saldos.sincronizar'));

chequear('JOB cuando se pide',
    'JOB:COMEX_PRESUP', AuthCashflow::origen('COMEX_PRESUP', AuthCashflow::ORIGEN_JOB));

chequear('se recortan los espacios del proceso',
    'SISTEMA:X.y', AuthCashflow::origen('  X.y  '));

$largo = AuthCashflow::origen(str_repeat('a', 80));

chequear('se recorta a 50, que es el largo de las columnas', 50, mb_strlen($largo));
chequear('y conserva el prefijo', 'SISTEMA:aaa', substr($largo, 0, 11));

chequearLanza('un proceso vacio no es un origen', function () {
    AuthCashflow::origen('   ');
});

chequearLanza('un tipo que no existe tampoco', function () {
    AuthCashflow::origen('X', 'CRON');
});

seccion('los origenes JOB declarados');

$malos = [];

foreach (AuthCashflow::ORIGENES as $clave => $nombre) {
    if (strpos($clave, AuthCashflow::ORIGEN_JOB . ':') !== 0
        || mb_strlen($clave) > AuthCashflow::LARGO_USUARIO || trim($nombre) === '') {
        $malos[] = $clave;
    }
}

chequear('todos empiezan con JOB:, entran en 50 y tienen nombre', [], $malos);

seccion('recortarUsuario');

chequear('vacio es null', null, AuthCashflow::recortarUsuario('  '));
chequear('null es null', null, AuthCashflow::recortarUsuario(null));
chequear('se recorta a 50', 50, mb_strlen(AuthCashflow::recortarUsuario(str_repeat('ñ', 70))));
chequear('sin espacios alrededor', 'rorozco', AuthCashflow::recortarUsuario(' rorozco '));

/* ================================================================
   puedeEditar(), con permisos simulados
   ================================================================ */
seccion('permiteEditar con permisos simulados');

$soloVer = ['cashflow.tab.saldos' => true];
$verYEditar = ['cashflow.tab.saldos' => true, 'cashflow.editar.saldos' => true];
$editarSinVer = ['cashflow.editar.saldos' => true];

chequear('admin edita cualquier pestaña', true,
    AuthCashflow::permiteEditar([], true, 'proveedores_locales'));
chequear('admin edita cualquier sub-pestaña', true,
    AuthCashflow::permiteEditar([], true, 'parametros', 'SALDOS'));

chequear('ver no es editar', false, AuthCashflow::permiteEditar($soloVer, false, 'saldos'));
chequear('con las dos claves edita', true, AuthCashflow::permiteEditar($verYEditar, false, 'saldos'));
chequear('pero solo esa pestaña', false, AuthCashflow::permiteEditar($verYEditar, false, 'echeqs'));

// Escribir implica leer: una clave de edicion suelta no habilita nada.
chequear('editar sin ver no edita', false, AuthCashflow::permiteEditar($editarSinVer, false, 'saldos'));
chequear('y tampoco ve', false, AuthCashflow::permiteVer($editarSinVer, false, 'saldos'));

chequear('sin ningun permiso no edita', false, AuthCashflow::permiteEditar([], false, 'saldos'));

seccion('sub-pestañas de Parametros');

$param = [
    'cashflow.tab.parametros' => true,
    'cashflow.editar.parametros.prov_locales' => true
];

chequear('la clave de la sub-pestaña va en minuscula',
    'cashflow.editar.parametros.prov_locales', AuthCashflow::claveEdicion('parametros', 'PROV_LOCALES'));
chequear('una pestaña comun no lleva sub',
    'cashflow.editar.saldos', AuthCashflow::claveEdicion('saldos'));

chequear('edita la sub-pestaña que tiene', true,
    AuthCashflow::permiteEditar($param, false, 'parametros', 'PROV_LOCALES'));
chequear('no la que no tiene', false,
    AuthCashflow::permiteEditar($param, false, 'parametros', 'SALDOS'));
chequear('ni Parametros "entero": no hay clave de edicion general', false,
    AuthCashflow::permiteEditar($param, false, 'parametros'));

unset($param['cashflow.tab.parametros']);

chequear('sin ver Parametros no edita ninguna sub-pestaña', false,
    AuthCashflow::permiteEditar($param, false, 'parametros', 'PROV_LOCALES'));

seccion('sin sesion, todo se deniega');

// Por linea de comandos no hay sesion: es el usuario anonimo.
if (AuthCashflow::estaAutenticado()) {
    saltear('hay una sesion con usuario; estas pruebas miden el caso anonimo');
} else {
    chequear('no hay username', null, AuthCashflow::username());
    chequear('puedeEditar deniega', false, AuthCashflow::puedeEditar('saldos'));

    chequearLanza('exigirUsuario lanza la excepcion del 401', function () {
        try {
            AuthCashflow::exigirUsuario();
        } catch (AuthCashflowSinUsuario $e) {
            throw new Exception('401');
        }
    }, '401');

    chequearLanza('una accion de escritura tambien termina en 401, no en 403', function () {
        try {
            AuthCashflow::exigirAccion('Saldos', 'guardarCargaSaldos');
        } catch (AuthCashflowSinUsuario $e) {
            throw new Exception('401');
        }
    }, '401');

    chequear('una de lectura no exige nada', null, AuthCashflow::exigirAccion('Saldos', 'getSaldos'));
}

/* ================================================================
   El mapa accion -> permiso
   ================================================================ */
seccion('destinosDe');

chequear('una lectura no tiene destinos', null, AuthCashflow::destinosDe('Comex', 'getHistorialFecha'));
chequear('la fecha de Comex se edita desde dos pestañas',
    [['proveedores_exterior', null], ['crono_nacionalizacion', null]],
    AuthCashflow::destinosDe('Comex', 'updateFecha'));
chequear('saveParametro toma la sub-pestaña que se le pasa',
    [['parametros', 'SALDOS']], AuthCashflow::destinosDe('Parametros', 'saveParametro', 'SALDOS'));

chequearLanza('saveParametro sin sub-pestaña se rechaza', function () {
    AuthCashflow::destinosDe('Parametros', 'saveParametro');
});

chequearLanza('una accion que no esta en el mapa se rechaza', function () {
    AuthCashflow::destinosDe('Saldos', 'borrarTodo');
});

seccion('el mapa cubre todos los case de los controllers');

/**
 * Las acciones de un controller, leidas de su texto: los case del switch y los
 * if ($action === '...') que algunos resuelven antes de entrar al switch.
 */
function accionesDelController($archivo) {
    $texto = file_get_contents($archivo);
    preg_match_all("/case\\s+['\"]([A-Za-z_]+)['\"]\\s*:/", $texto, $m1);
    preg_match_all("/\\\$action\\s*===\\s*['\"]([A-Za-z_]+)['\"]/", $texto, $m2);

    return array_values(array_unique(array_merge($m1[1], $m2[1])));
}

$sinMapear = [];
$sobrantes = [];
$controllers = [];

foreach (glob(__DIR__ . '/../Controller/*Controller.php') as $archivo) {
    $nombre = basename($archivo, 'Controller.php');

    // TabController no tiene acciones: carga pestañas y ya pide puede().
    if ($nombre === 'Tab') {
        continue;
    }

    $controllers[] = $nombre;
    $acciones = accionesDelController($archivo);
    $declaradas = array_merge(
        array_keys(AuthCashflow::ESCRITURAS[$nombre] ?? []),
        AuthCashflow::LECTURAS[$nombre] ?? []);

    foreach ($acciones as $a) {
        if (!in_array($a, $declaradas, true)) {
            $sinMapear[] = $nombre . '::' . $a;
        }
    }

    foreach ($declaradas as $a) {
        if (!in_array($a, $acciones, true)) {
            $sobrantes[] = $nombre . '::' . $a;
        }
    }
}

chequear('se leyeron los catorce controllers', 14, count($controllers));
chequear('ninguna accion sin declarar como lectura o escritura', [], $sinMapear);
chequear('ninguna accion declarada que ya no exista', [], $sobrantes);

$dobles = [];

foreach (AuthCashflow::LECTURAS as $c => $lecturas) {
    foreach ($lecturas as $a) {
        if (isset(AuthCashflow::ESCRITURAS[$c][$a])) {
            $dobles[] = $c . '::' . $a;
        }
    }
}

chequear('ninguna accion es lectura y escritura a la vez', [], $dobles);

seccion('el mapa nombra pestañas y sub-pestañas que existen');

$tabs = Menu::tabs();
$subs = array_map(function ($m) { return $m['codigo']; }, Parametros::getModulos());
$tabsMalas = [];
$subsMalas = [];

foreach (AuthCashflow::ESCRITURAS as $c => $acciones) {
    foreach ($acciones as $a => $destinos) {
        foreach ($destinos as $d) {
            if (!in_array($d[0], $tabs, true)) {
                $tabsMalas[] = $c . '::' . $a . ' -> ' . $d[0];
            }

            if ($d[1] !== null && $d[1] !== AuthCashflow::SUB_DEL_PARAMETRO
                && !in_array($d[1], $subs, true)) {
                $subsMalas[] = $c . '::' . $a . ' -> ' . $d[1];
            }

            if ($d[1] !== null && $d[0] !== 'parametros') {
                $subsMalas[] = $c . '::' . $a . ' -> sub fuera de Parametros';
            }
        }
    }
}

chequear('todas las pestañas estan en el menu', [], $tabsMalas);
chequear('todas las sub-pestañas estan en Parametros::$modulos', [], $subsMalas);

seccion('el script de permisos da de alta exactamente las claves que pide el codigo');

/* Las claves que el codigo puede llegar a pedir: una por pestaña con
   escrituras y una por cada sub-pestaña de Parametros -todas, tenga hoy o no
   una escritura propia: saveParametro llega a cualquiera-. */
$esperadas = [];

foreach (AuthCashflow::ESCRITURAS as $acciones) {
    foreach ($acciones as $destinos) {
        foreach ($destinos as $d) {
            if ($d[0] !== 'parametros') {
                $esperadas[] = AuthCashflow::claveEdicion($d[0]);
            }
        }
    }
}

foreach ($subs as $s) {
    $esperadas[] = AuthCashflow::claveEdicion('parametros', $s);
}

$esperadas = array_values(array_unique($esperadas));
sort($esperadas);

preg_match_all("/\('(cashflow\.editar\.[a-z_.]+)'/",
    file_get_contents(__DIR__ . '/../sql/cashflow_permisos_edicion.sql'), $m);
$enScript = array_values(array_unique($m[1]));
sort($enScript);

chequear('las mismas claves en el script y en el codigo', $esperadas, $enScript);
chequear('ninguna pestaña de solo lectura lleva clave', false,
    in_array('cashflow.editar.dashboard', $enScript, true));
