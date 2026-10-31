<?php
/**
 * Los avisos del tablero, tambien en su pestana.
 *
 * Lo que se fija es la promesa de Cashflow::avisosDePestana(): que cada
 * pestana reciba EXACTAMENTE lo que el tablero muestra en su grupo -mismo
 * texto, mismo nivel, misma seccion y mismo orden- y nada del grupo Tablero.
 * Si alguien agrega un aviso al motor, o cambia uno, las dos pantallas no
 * pueden quedar diciendo cosas distintas sin que esto falle.
 *
 * Sin base: el motor se corre con proveedores falsos inyectados por
 * instanciar(), como en tests/test_avisos.php, pero con una fila por cada
 * proveedor con pestana del registro real.
 */

require_once __DIR__ . '/../Class/Aviso.php';
require_once __DIR__ . '/../Class/Cashflow.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

/** Emite los avisos que se le dicen; cada uno [texto, nivel, seccion]. */
class ProveedorPestanaPrueba extends CashflowProvider {
    public $emitir = [];
    public $series = [];
    public $lanza = null;

    protected function calcular($h) {
        if ($this->lanza !== null) {
            throw new Exception($this->lanza);
        }

        foreach ($this->emitir as $a) {
            $this->avisar($a[0], $a[1], isset($a[2]) ? $a[2] : null);
        }

        return $this->series;
    }
}

class EstructuraPestana {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return ['un aviso de la estructura']; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosPestana extends Parametros {
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() { return ['horizonte_dias' => 3, 'horizonte_meses' => 3]; }
    public function getFeriadosComercio($map = null) { return []; }
}

/**
 * Cuenta cuantas veces se instancio cada proveedor: avisosDePestana() no
 * tiene que pedirle nada a los modulos de otras pestanas. Cada pedido arma
 * proveedores nuevos, como en produccion: un proveedor guarda en cache sus
 * series y sus avisos, y reusarlo entre proyectar() y avisosDePestana()
 * esconderia justamente lo que se quiere comparar.
 */
class CashflowPestanaPrueba extends Cashflow {
    public $fabrica = [];
    public $pedidos = [];

    protected function instanciar($codigo) {
        $this->pedidos[] = $codigo;

        return isset($this->fabrica[$codigo]) ? call_user_func($this->fabrica[$codigo]) : null;
    }
}

$hPes = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

/* Una fila por cada proveedor con pestana, con la primera serie que declara
   el registro. Cada proveedor emite un aviso por nivel, uno con seccion, y su
   serie deja importes fuera del horizonte y sin fecha: son los descartes de la
   fila, que llevan el NOMBRE DE LA FILA en el texto. */
$estPes = new EstructuraPestana();
$estPes->secciones = [
    ['CODIGO' => 'MOV', 'NOMBRE' => 'MOV', 'ROL' => 'MOVIMIENTO', 'ID_PADRE' => null,
     'ORDEN' => 10, 'ACTIVO' => 1]
];

$fabricaPes = [];
$id = 0;

$todosPes = CashflowRegistry::todos();

foreach ($todosPes as $meta) {
    if (empty($meta['tab']) || empty($meta['disponible']) || !empty($meta['retirado'])) {
        continue;
    }

    $codigo = $meta['codigo'];
    $serie = array_keys($meta['series'])[0];
    $id++;

    $estPes->filas[] = ['ID' => $id, 'CODIGO' => 'F' . $id, 'NOMBRE' => 'Fila ' . $codigo,
        'SECCION' => 'MOV', 'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => $codigo,
        'ORIGEN_SERIE' => $serie, 'ORDEN' => $id * 10, 'ACTIVO' => 1];

    $fabricaPes[$codigo] = function () use ($codigo, $serie) {
        $p = new ProveedorPestanaPrueba($codigo);
        $p->emitir = [
            ['info de ' . $codigo, Aviso::INFO],
            ['critico de ' . $codigo, Aviso::DANGER],
            ['con seccion de ' . $codigo, Aviso::WARNING, 'Parte ' . $codigo]
        ];
        $p->series = [$serie => ['dias' => [], 'meses' => [], 'fuera_horizonte' => 10,
            'sin_fecha' => 5, 'warnings' => ['de la serie de ' . $codigo]]];

        return $p;
    };
}

/* Una segunda fila sobre la misma serie de Tarjetas: sus descartes y los
   avisos de su serie se emiten una vez por fila, y la pestana tiene que
   recibirlos igual que el tablero. */
$id++;
$estPes->filas[] = ['ID' => $id, 'CODIGO' => 'F' . $id, 'NOMBRE' => 'Otra fila de tarjetas',
    'SECCION' => 'MOV', 'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => 'TARJETAS',
    'ORIGEN_SERIE' => array_keys(CashflowRegistry::meta('TARJETAS')['series'])[0],
    'ORDEN' => $id * 10, 'ACTIVO' => 1];

// Los que caen en Tablero: un proveedor sin pestana y un modulo sin construir.
foreach ([['COBERTURA', 'APLICACION'], ['HABERES', 'PAGOS']] as $t) {
    $id++;
    $estPes->filas[] = ['ID' => $id, 'CODIGO' => 'F' . $id, 'NOMBRE' => 'Fila ' . $t[0],
        'SECCION' => 'MOV', 'TIPO' => 'EGRESO', 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => $t[0],
        'ORIGEN_SERIE' => $t[1], 'ORDEN' => $id * 10, 'ACTIVO' => 1];
}

$fabricaPes['COBERTURA'] = function () {
    $p = new ProveedorPestanaPrueba('COBERTURA');
    $p->emitir = [['de cobertura', Aviso::WARNING]];
    $p->series = ['APLICACION' => ['dias' => [], 'meses' => []]];

    return $p;
};

// Uno que falla al calcular y uno que no se puede cargar.
$fabricaPes['COMEX_NAC'] = function () {
    $p = new ProveedorPestanaPrueba('COMEX_NAC');
    $p->lanza = 'la base no responde';

    return $p;
};
unset($fabricaPes['EXPORTACIONES']);

$motorPes = function () use ($estPes, $hPes, $fabricaPes) {
    $m = new CashflowPestanaPrueba($estPes, new ParametrosPestana(), $hPes);
    $m->fabrica = $fabricaPes;

    return $m;
};

/** Solo lo que se ve: nivel, texto y seccion, en orden */
function vistaPes($avisos) {
    return array_map(function ($a) {
        return [$a['nivel'], $a['texto'], $a['seccion']];
    }, $avisos);
}

$tableroPes = $motorPes()->proyectar();
$gruposPes = [];

foreach ($tableroPes['avisos'] as $g) {
    $gruposPes[$g['grupo']] = $g;
}

/* ================================================================
   Las pestanas
   ================================================================ */
seccion('las pestanas con avisos del tablero son las del registro');

chequear('son las catorce', ['ventas', 'cobranzas_fr', 'proveedores_exterior',
    'crono_nacionalizacion', 'compras_proyectadas', 'logistica_local', 'gastos_locales', 'saldos',
    'cob_electronicos', 'echeqs', 'cobranzas_may', 'proveedores_locales', 'pagos_tarjetas',
    'exportaciones_tasky'], CashflowRegistry::tabsConProveedor());

seccion('cada pestana recibe exactamente su grupo del tablero');

foreach (CashflowRegistry::tabsConProveedor() as $tabPes) {
    $motor = $motorPes();
    $propios = $motor->avisosDePestana($tabPes);

    chequear($tabPes . ': mismos textos, niveles, secciones y orden',
        vistaPes($gruposPes[$tabPes]['avisos']), vistaPes($propios));

    chequear($tabPes . ': no trae nada del grupo Tablero', [],
        array_values(array_filter($propios, function ($a) use ($tabPes) {
            return $a['grupo'] !== $tabPes;
        })));

    /* Solo se piden los modulos de esa pestana. Saldos son cuatro proveedores
       con el mismo 'tab'; el resto, uno. */
    $deOtra = array_values(array_filter(array_unique($motor->pedidos), function ($c) use ($tabPes) {
        $meta = CashflowRegistry::meta($c);

        return $meta === null || $meta['tab'] !== $tabPes;
    }));
    chequear($tabPes . ': no le pide nada a los modulos de otras pestanas', [], $deOtra);
}

seccion('lo que llega a la pestana');

$saldosPes = vistaPes($motorPes()->avisosDePestana('saldos'));
$textosSaldos = array_column($saldosPes, 1);

chequear('Saldos junta sus cuatro proveedores', true,
    in_array('info de SALDOS', $textosSaldos, true)
    && in_array('info de CAJA_LOCALES', $textosSaldos, true)
    && in_array('info de FONDO_INVERSION', $textosSaldos, true)
    && in_array('info de FONDO_COMITENTE', $textosSaldos, true));
chequear('con la seccion de cada aviso', true,
    in_array(['warning', 'con seccion de CAJA_LOCALES', 'Parte CAJA_LOCALES'], $saldosPes, true));
chequear('los descartes llevan el nombre de la fila', true,
    in_array('Fila CAJA_LOCALES: $ 10,00 con fecha fuera del horizonte proyectado no se '
        . 'muestran en el tablero.', $textosSaldos, true));

$tarjPes = array_column(vistaPes($motorPes()->avisosDePestana('pagos_tarjetas')), 1);
chequear('dos filas de la misma serie: un descarte por fila, como en el tablero', 2,
    count(array_filter($tarjPes, function ($t) {
        return strpos($t, 'sin fecha asignada') !== false;
    })));

$nacPes = vistaPes($motorPes()->avisosDePestana('crono_nacionalizacion'));
chequear('el modulo que falla llega a su pestana, critico', 'danger', $nacPes[0][0]);
chequear('con el motivo', true, strpos($nacPes[0][1], 'la base no responde') !== false);

$expPes = vistaPes($motorPes()->avisosDePestana('exportaciones_tasky'));
chequear('el que no se pudo cargar tambien', true,
    strpos($expPes[0][1], 'No se pudo cargar el módulo') === 0);

$tablero = array_column(vistaPes($gruposPes[Aviso::GRUPO_TABLERO]['avisos']), 1);
$enAlguna = [];

foreach (CashflowRegistry::tabsConProveedor() as $t) {
    foreach (vistaPes($motorPes()->avisosDePestana($t)) as $a) {
        $enAlguna[] = $a[1];
    }
}

chequear('ningun aviso del grupo Tablero aparece en una pestana', [],
    array_values(array_intersect($tablero, $enAlguna)));
chequear('y el grupo Tablero tiene los suyos (estructura, sin pestana, sin construir)', true,
    in_array('un aviso de la estructura', $tablero, true)
    && in_array('de cobertura', $tablero, true));

chequear('una pestana sin filas que la usen no recibe nada', [],
    $motorPes()->avisosDePestana('haberes'));

/* ================================================================
   El pedido desde la pantalla
   ================================================================ */
seccion('la accion del controller');

$ctlPes = file_get_contents(__DIR__ . '/../Controller/CashflowController.php');
$casePes = substr($ctlPes, strpos($ctlPes, "case 'getAvisosPestana':"));
$casePes = substr($casePes, 0, strpos($casePes, 'break;'));

chequear('esta declarada como lectura en el mapa', true,
    in_array('getAvisosPestana', AuthCashflow::LECTURAS['Cashflow'], true));
chequear('valida la pestana contra el registro', true,
    strpos($casePes, 'CashflowRegistry::tabsConProveedor()') !== false);
chequear('pide la lectura de ESA pestana antes de calcular', true,
    strpos($casePes, 'autorizarLectura($tab);') !== false
    && strpos($casePes, 'autorizarLectura($tab);') < strpos($casePes, 'avisosDePestana($tab)'));
chequear('y libera la sesion despues de autorizar y antes de calcular', true,
    strpos($casePes, 'AuthCashflow::liberarSesion();') > strpos($casePes, 'autorizarLectura($tab);')
    && strpos($casePes, 'AuthCashflow::liberarSesion();') < strpos($casePes, 'avisosDePestana($tab)'));

if (AuthCashflow::estaAutenticado()) {
    saltear('hay una sesion con usuario; esto mide el caso sin permiso');
} else {
    chequearLanza('sin permiso de lectura de la pestana, se niega', function () {
        try {
            AuthCashflow::exigirLectura('proveedores_exterior');
        } catch (AuthCashflowSinUsuario $e) {
            throw new Exception('negado');
        } catch (AuthCashflowSinPermiso $e) {
            throw new Exception('negado');
        }
    }, 'negado');
}

chequear('sin cashflow.tab.<pestana> no se lee, aunque se vea el tablero', false,
    AuthCashflow::permiteVer(['cashflow.tab.cashflow' => true], false, 'proveedores_exterior'));
chequear('con el de la pestana si, aunque no se vea el tablero', true,
    AuthCashflow::permiteVer(['cashflow.tab.proveedores_exterior' => true], false,
        'proveedores_exterior'));

$conPes = file_get_contents(__DIR__ . '/../../class/conexion.php');
chequear('Conexion no vuelve a abrir una sesion liberada (las dos llamadas)', 2,
    substr_count($conPes, "&& !defined('CASHFLOW_SESION_LIBERADA')"));

/* ================================================================
   Un hecho, un texto

   La pestana muestra sus avisos y los del tablero, y saca los repetidos por
   texto. Eso solo funciona si el mismo hecho se escribe UNA vez: estas pruebas
   fijan que la pestana y el proveedor llaman a la misma funcion.
   ================================================================ */
seccion('un hecho, un texto: Cobranzas');

require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/CobElectronicos.php';

$itemsVenc = [
    ['VENCIDA' => true, 'FECHA_MANUAL' => false, 'importe_neto' => 100],
    ['VENCIDA' => true, 'FECHA_MANUAL' => false, 'importe_neto' => 50],
    ['VENCIDA' => true, 'FECHA_MANUAL' => true, 'importe_neto' => 30],
    ['VENCIDA' => false, 'FECHA_MANUAL' => false, 'importe_neto' => 999]
];

chequear('la pestana escribe el texto de la funcion compartida',
    Aviso::textos(Ingresos::avisosVencidasConNivel(2, 150, 1, 30)),
    Ingresos::avisosCobranzasVencidas($itemsVenc));
chequear('las pactadas a mano se cuentan aparte: dos avisos', 2,
    count(Ingresos::avisosVencidasConNivel(2, 150, 1, 30)));

$srcIngProv = file_get_contents(__DIR__ . '/../Class/Providers/IngresosProvider.php');
chequear('el proveedor de Cobranzas usa la misma funcion, con las pactadas aparte', true,
    strpos($srcIngProv, 'Ingresos::avisosVencidasConNivel($comprobantes - $pactados,') !== false);

$srcIng = file_get_contents(__DIR__ . '/../Class/Ingresos.php');
chequear('los dos agregadores del tablero cuentan las pactadas (FR y May)', 2,
    substr_count($srcIng, "'COMP_VENCIDOS_PACTADOS' => \$compVencidosPactadosPorFecha[\$f] ?? 0"));

seccion('un hecho, un texto: Exportaciones Tasky');

$itemsExp = [
    ['IMPORTE_USD' => 100, 'VENCIDA' => true],
    ['IMPORTE_USD' => 40, 'VENCIDA' => false]
];

chequear('con cotizacion, la pestana dice lo mismo que la funcion',
    Aviso::textos(Ingresos::avisosExportacionesConNivel(140, 1500, 1, 100)),
    Ingresos::avisosExportaciones($itemsExp, 1500));
chequear('sin cotizacion tambien, y el de la cotizacion es critico',
    ['danger', 'warning'],
    array_column(Ingresos::avisosExportacionesConNivel(140, null, 1, 100), 'nivel'));
chequear('sin dolares pendientes no hay cotizacion que pedir', [],
    Ingresos::avisosExportacionesConNivel(0, null, 0, 0));
chequear('el proveedor usa la misma funcion', true,
    strpos(file_get_contents(__DIR__ . '/../Class/Providers/ExportacionesProvider.php'),
        'Ingresos::avisosExportacionesConNivel($usdTotal, $cotiz,') !== false);

seccion('un hecho, un texto: Cob. Electronicos');

$hCob = new Horizonte(3, 3, [], new DateTime('2026-09-06'));
$movLejos = [['fecha_acreditacion' => '2027-12-20', 'importe_neto' => 500,
    'importe_bruto' => 510, 'procesadora' => 'P', 'id_procesadora' => 1]];

chequear('lo posterior al horizonte: el mismo texto en la tabla y en el tablero',
    CobElectronicos::armarSerie($movLejos, $hCob)['warnings'],
    CobElectronicos::armarMovimientos($movLejos, $hCob)['avisos']);

$srcCobProv = file_get_contents(__DIR__ . '/../Class/Providers/CobElectronicosProvider.php');
chequear('el proveedor usa los textos de la clase (tablas y procesadoras)', true,
    strpos($srcCobProv, 'CobElectronicos::avisoSinTablas()') !== false
    && strpos($srcCobProv, 'CobElectronicos::avisoSinProcesadoras()') !== false);
$srcCob = file_get_contents(__DIR__ . '/../Class/CobElectronicos.php');
chequear('y la pestana tambien', true,
    strpos($srcCob, '$avisos[] = self::avisoSinTablas();') !== false
    && strpos($srcCob, '$avisos[] = self::avisoSinProcesadoras();') !== false);

seccion('un hecho, un texto: Comercio Exterior');

$srcComexCtl = file_get_contents(__DIR__ . '/../Controller/ComexController.php');
$srcComexProv = file_get_contents(__DIR__ . '/../Class/Providers/ComexProvider.php');

chequear('Proveedores Exterior: la pestana usa la lista del tablero', true,
    strpos($srcComexCtl, '$comex->avisosExteriorConNivel($filasExt)') !== false
    && strpos($srcComexProv, '$comex->avisosExteriorConNivel($filas)') !== false);
chequear('Crono Nacionalizacion tambien', true,
    strpos($srcComexCtl, '$comex->avisosNacionalizacionConNivel($filasNac)') !== false
    && strpos($srcComexProv, '$comex->avisosNacionalizacionConNivel($filas)') !== false);
chequear('la curva caida, con el mismo texto en las cuatro llamadas', 4,
    substr_count($srcComexCtl . $srcComexProv, '->avisoSinCurva('));
chequear('y la pestana ya no arma su lista con las funciones sueltas', 0,
    preg_match_all('/Comex::avisos(Saldo|Sobrepago|Grupo|Valuacion|Vencidos)/', $srcComexCtl));

/* ================================================================
   Cada pestana pide sus avisos al tablero
   ================================================================ */
seccion('cableado: cada pestana con proveedor pide sus avisos al tablero');

require_once __DIR__ . '/../Class/EjeVista.php';
require_once __DIR__ . '/../Class/Fondos.php';
require_once __DIR__ . '/../Class/Providers/SaldosProvider.php';

$jsDePestana = [
    'ventas' => 'Ingresos-Ventas.js',
    'cobranzas_fr' => 'Ingresos-Cobranzas_fr.js',
    'cobranzas_may' => 'Ingresos-Cobranzas_may.js',
    'proveedores_exterior' => 'Comex-Proveedores_exterior.js',
    'crono_nacionalizacion' => 'Comex-Crono_nacionalizacion.js',
    'compras_proyectadas' => 'Compras-Proyectadas.js',
    'logistica_local' => 'Logistica-Local.js',
    'gastos_locales' => 'Gastos-Locales.js',
    'saldos' => 'Saldos.js',
    'cob_electronicos' => 'Cob-Electronicos.js',
    'echeqs' => 'Ingresos-Echeqs.js',
    'proveedores_locales' => 'Proveedores-Proveedores_locales.js',
    'pagos_tarjetas' => 'Financiero-Pagos_tarjetas.js',
    'exportaciones_tasky' => 'Ingresos-Exportaciones_tasky.js'
];

chequear('el mapa cubre las catorce pestanas del registro', CashflowRegistry::tabsConProveedor(),
    array_values(array_intersect(CashflowRegistry::tabsConProveedor(), array_keys($jsDePestana))));

foreach ($jsDePestana as $tabJs => $archivoJs) {
    $src = file_get_contents(__DIR__ . '/../Js/' . $archivoJs);

    chequear($tabJs . ': pide sus avisos al tablero', true,
        strpos($src, "AvisosPanel.pedirTablero('" . $tabJs . "');") !== false);
    chequear($tabJs . ': y los pinta con los suyos', true,
        strpos($src, "{ tab: '" . $tabJs . "'") !== false
        && strpos($src, 'AvisosPanel.pintarPestana(') !== false);
}

$ventasJs = file_get_contents(__DIR__ . '/../Js/Ingresos-Ventas.js');
chequear('Ventas ya no tiene su propio bloque de avisos', false,
    strpos($ventasJs, 'function pintarWarnings(') !== false);
chequear('y usa el panel en sus dos contenedores', true,
    strpos($ventasJs, "AvisosPanel.pintar('warningsVentaAnual'") !== false
    && strpos($ventasJs, "AvisosPanel.pintarPestana('warningsProyeccion'") !== false);
chequear('el cartel del tramo estimado sigue fijo', true,
    strpos(file_get_contents(__DIR__ . '/../Tabs/ventas.php'), 'id="avisoTramoEstimado" class="alert') !== false);

seccion('un panel por sub-pestana, adentro de su sub-pestana');

$panelEn = [
    'saldos.php' => ['avisosSaldos' => 'paneSaldos', 'avisosSaldosLocales' => 'paneLocales',
                     'avisosSaldosFondos' => 'paneFondos'],
    'pagos_tarjetas.php' => ['avisosTarjSup' => 'paneSup', 'avisosTarjCorp' => 'paneCorp',
                             'avisosTarjSoc' => 'paneSoc'],
    'echeqs.php' => ['avisosEcheqs' => 'paneCartera', 'avisosEcheqsPre' => 'panePrechequeado']
];

foreach ($panelEn as $archivoTab => $paneles) {
    $html = file_get_contents(__DIR__ . '/../Tabs/' . $archivoTab);
    $panes = array_values($paneles);

    foreach ($paneles as $idPanel => $pane) {
        $pPanel = strpos($html, 'id="' . $idPanel . '"');
        $pPane = strpos($html, 'id="' . $pane . '"');
        $i = array_search($pane, $panes, true);
        $pSiguiente = isset($panes[$i + 1]) ? strpos($html, 'id="' . $panes[$i + 1] . '"') : PHP_INT_MAX;

        chequear($archivoTab . ': #' . $idPanel . ' esta adentro de #' . $pane, true,
            $pPanel !== false && $pPane !== false && $pPanel > $pPane && $pPanel < $pSiguiente);
    }
}

seccion('las secciones del mapa son las de los proveedores');

$panelJs = file_get_contents(__DIR__ . '/../Js/avisos-panel.js');

/* El JS escribe la tilde como ó para que cscript la lea igual que el
   navegador; se compara contra el texto ya decodificado. */
$claveJs = function ($texto) {
    return "'" . str_replace('ó', '\u00f3', $texto) . "': ";
};

foreach ([
    [SaldosProvider::SECCION_SALDO, 'saldos'], [SaldosProvider::SECCION_LOCALES, 'locales'],
    [Fondos::CLASES['INVERSION'], 'fondos'], [Fondos::CLASES['COMITENTE'], 'fondos']
] as $par) {
    chequear('Saldos: "' . $par[0] . '" va a ' . $par[1], true,
        strpos($panelJs, $claveJs($par[0]) . "'" . $par[1] . "'") !== false);
}

$tarjProvSrc = file_get_contents(__DIR__ . '/../Class/Providers/TarjetasProvider.php');

foreach ([['Supervisoras', 'sup'], ['Corporativas', 'corp'], ['Socios', 'soc']] as $par) {
    chequear('Tarjetas: "' . $par[0] . '" es una seccion del proveedor y va a ' . $par[1], true,
        strpos($tarjProvSrc, "'" . $par[0] . "'),") !== false
        && strpos($panelJs, "'" . $par[0] . "': '" . $par[1] . "'") !== false);
}

chequear('los descartes de la grilla que el panel reconoce son los de EjeVista', true,
    strpos($panelJs, "'" . EjeVista::FIN_FUERA_HORIZONTE . "'") !== false
    && strpos($panelJs, "'" . EjeVista::FIN_SIN_FECHA . "'") !== false);
