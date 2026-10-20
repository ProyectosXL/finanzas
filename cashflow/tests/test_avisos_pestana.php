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

chequear('son las trece', ['ventas', 'cobranzas_fr', 'proveedores_exterior',
    'crono_nacionalizacion', 'compras_proyectadas', 'logistica_local', 'saldos',
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
