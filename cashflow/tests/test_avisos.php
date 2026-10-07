<?php
/**
 * Avisos del tablero: la gravedad de cada aviso, a que pestana pertenece y en
 * que orden se muestran.
 *
 * Lo que se prueba es lo que el panel "Sobre estos numeros" promete: que un
 * aviso critico no quede tapado entre los informativos, que cada aviso caiga
 * en el grupo de la pestana que lo explica y que las pestanas sigan
 * recibiendo exactamente los mismos textos que antes. Todo sin base: el motor
 * se corre con proveedores falsos inyectados por Cashflow::instanciar().
 */

require_once __DIR__ . '/../Class/Aviso.php';
require_once __DIR__ . '/../Class/Cashflow.php';
require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/TarjetasCorporativas.php';

/* ---- Dobles de prueba ------------------------------------------------ */

/**
 * Un proveedor que emite los avisos que se le dicen. Cada aviso es
 * [texto] -sin nivel, para probar el defecto- o [texto, nivel, seccion].
 */
class ProveedorAvisosPrueba extends CashflowProvider {
    public $emitir = [];
    public $series = [];
    public $lanza = null;

    protected function calcular($h) {
        if ($this->lanza !== null) {
            throw new Exception($this->lanza);
        }

        foreach ($this->emitir as $a) {
            if (count($a) === 1) {
                $this->avisar($a[0]);
            } else {
                $this->avisar($a[0], $a[1], isset($a[2]) ? $a[2] : null);
            }
        }

        return $this->series;
    }
}

class EstructuraAvisos {
    public $secciones = [];
    public $filas = [];
    public function getAvisos() { return []; }
    public function getEstructura($soloActivas = false) {
        return ['secciones' => $this->secciones, 'filas' => $this->filas];
    }
}

class ParametrosAvisos extends Parametros {
    public function __construct() { /* a proposito: no abre conexion */ }
    public function getParametrosMap() { return ['horizonte_dias' => 3, 'horizonte_meses' => 3]; }
    public function getFeriadosComercio($map = null) { return []; }
}

/** Corre pedirSeries() de verdad, con los proveedores que se le inyectan. */
class CashflowAvisos extends Cashflow {
    public $provs = [];

    protected function instanciar($codigo) {
        return array_key_exists($codigo, $this->provs) ? $this->provs[$codigo] : null;
    }
}

function filaAv($id, $codigo, $seccion, $tipo, $orden, $prov = null, $serie = null) {
    return ['ID' => $id, 'CODIGO' => $codigo, 'NOMBRE' => $codigo, 'SECCION' => $seccion,
            'TIPO' => $tipo, 'COMPUTA' => 1, 'ORIGEN_PROVIDER' => $prov,
            'ORIGEN_SERIE' => $serie, 'ORDEN' => $orden, 'ACTIVO' => 1];
}

function seccionAv($codigo, $rol, $orden) {
    return ['CODIGO' => $codigo, 'NOMBRE' => $codigo, 'ROL' => $rol,
            'ID_PADRE' => null, 'ORDEN' => $orden, 'ACTIVO' => 1];
}

function provAv($codigo, $emitir = [], $series = [], $lanza = null) {
    $p = new ProveedorAvisosPrueba($codigo);
    $p->emitir = $emitir;
    $p->series = $series;
    $p->lanza = $lanza;

    return $p;
}

/** Los grupos del tablero indexados por 'grupo' */
function gruposAv($tablero) {
    $v = [];

    foreach ($tablero['avisos'] as $g) {
        $v[$g['grupo']] = $g;
    }

    return $v;
}

/** Los avisos de un grupo indexados por texto */
function porTextoAv($grupo) {
    $v = [];

    foreach ($grupo['avisos'] as $a) {
        $v[$a['texto']] = $a;
    }

    return $v;
}

$hAv = new Horizonte(3, 3, [], new DateTime('2026-09-06'));

/* ================================================================
   Aviso: el nivel
   ================================================================ */
seccion('Aviso: tres niveles, y uno invalido es atencion');

chequear('danger es valido', 'danger', Aviso::nivel('danger'));
chequear('warning es valido', 'warning', Aviso::nivel('warning'));
chequear('info es valido', 'info', Aviso::nivel('info'));
// Ni info -lo esconderia entre los que no piden nada- ni danger -abriria el
// panel por un error de tipeo-.
chequear('un nivel mal escrito es warning', 'warning', Aviso::nivel('critico'));
chequear('null es warning', 'warning', Aviso::nivel(null));
chequear('un texto suelto se toma como warning',
    ['nivel' => 'warning', 'texto' => 'hola', 'seccion' => null], Aviso::normalizar('hola'));
chequear('una seccion vacia es null', null, Aviso::nuevo('info', 'x', '')['seccion']);
chequear('textos() acepta las dos formas', ['a', 'b'],
    Aviso::textos(['a', Aviso::nuevo('danger', 'b')]));

/* ================================================================
   CashflowProvider::avisar(), warnings() y avisos()
   ================================================================ */
seccion('avisar() con y sin nivel, y warnings() sigue devolviendo textos');

$pa = provAv('ECHEQS', [
    ['sin nivel'],
    ['con nivel', Aviso::INFO],
    ['nivel roto', 'grave'],
    ['con seccion', Aviso::DANGER, 'Sub']
]);
$pa->series($hAv);

chequear('warnings() devuelve solo los textos, en orden',
    ['sin nivel', 'con nivel', 'nivel roto', 'con seccion'], $pa->warnings());
chequear('avisos() devuelve los niveles', ['warning', 'info', 'warning', 'danger'],
    array_column($pa->avisos(), 'nivel'));
chequear('y la seccion', [null, null, null, 'Sub'], array_column($pa->avisos(), 'seccion'));

$roto = provAv('ECHEQS', [], [], 'se corto la luz');
$roto->series($hAv);

chequear('la falla atrapada es critica', 'danger', $roto->avisos()[0]['nivel']);
chequear('y ya no empieza con el codigo', false, strpos($roto->warnings()[0], 'ECHEQS') === 0);
chequear('dice el motivo', true, strpos($roto->warnings()[0], 'se corto la luz') !== false);

/* ================================================================
   normalizar(): el 'warnings' de la serie acepta las dos formas
   ================================================================ */
seccion('el warnings de una serie acepta textos y avisos con nivel');

$ps = provAv('ECHEQS', [], ['A_COBRAR' => ['dias' => [], 'meses' => [], 'warnings' => [
    'texto suelto',
    ['nivel' => 'info', 'texto' => 'estructurado', 'seccion' => 'Sec'],
    ['nivel' => 'inventado', 'texto' => 'nivel roto']
]]]);
$ss = $ps->series($hAv);

chequear('sale siempre con nivel', [
    ['nivel' => 'warning', 'texto' => 'texto suelto', 'seccion' => null],
    ['nivel' => 'info', 'texto' => 'estructurado', 'seccion' => 'Sec'],
    ['nivel' => 'warning', 'texto' => 'nivel roto', 'seccion' => null]
], $ss['A_COBRAR']['warnings']);

/* ================================================================
   El motor: cada aviso a su pestana
   ================================================================ */
seccion('el motor asigna el grupo de cada aviso');

$estAv = new EstructuraAvisos();
$estAv->secciones = [
    seccionAv('DISP', 'SALDO', 10),
    seccionAv('MOV', 'MOVIMIENTO', 20),
    seccionAv('RES', 'DERIVADO', 30)
];
$estAv->filas = [
    filaAv(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    filaAv(2, 'CAJA', 'MOV', 'INGRESO', 10, 'CAJA_LOCALES', 'DEPOSITOS'),
    filaAv(3, 'ECHEQS_FILA', 'MOV', 'INGRESO', 20, 'ECHEQS', 'A_COBRAR'),
    filaAv(4, 'PAGOS_EXT', 'MOV', 'EGRESO', 30, 'COMEX_PROV_EXT', 'PAGOS'),
    filaAv(5, 'NAC', 'MOV', 'EGRESO', 40, 'COMEX_NAC', 'NACIONALIZACION'),
    filaAv(6, 'HABERES', 'MOV', 'EGRESO', 50, 'HABERES', 'PAGOS'),
    filaAv(7, 'RARO', 'MOV', 'EGRESO', 60, 'NO_EXISTE', 'X'),
    filaAv(8, 'USO', 'MOV', 'USO_COBERTURA', 70, 'COBERTURA', 'APLICACION'),
    filaAv(9, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 10)
];

$motorAv = new CashflowAvisos($estAv, new ParametrosAvisos(), $hAv);
$motorAv->provs = [
    'SALDOS' => provAv('SALDOS', [['del saldo', Aviso::INFO, 'Saldo Inicial']],
        ['DISPONIBLE' => ['dias' => ['2026-09-06' => 1000], 'meses' => []]]),
    'CAJA_LOCALES' => provAv('CAJA_LOCALES', [['de la caja', Aviso::WARNING, 'Caja Locales']],
        ['DEPOSITOS' => ['dias' => [], 'meses' => []]]),
    'ECHEQS' => provAv('ECHEQS', [['propio de echeqs', Aviso::INFO]],
        ['A_COBRAR' => ['dias' => [], 'meses' => [], 'fuera_horizonte' => 100, 'sin_fecha' => 50,
                        'warnings' => ['de la serie', Aviso::nuevo('danger', 'de la serie critico')]]]),
    'COMEX_PROV_EXT' => provAv('COMEX_PROV_EXT', [], [], 'la base no responde'),
    // COMEX_NAC no esta: instanciar() devuelve null, "no se pudo cargar".
    'COBERTURA' => provAv('COBERTURA', [['de cobertura', Aviso::WARNING]],
        ['APLICACION' => ['dias' => [], 'meses' => []]])
];

$tAv = $motorAv->proyectar();
$gAv = gruposAv($tAv);

chequear('la respuesta ya no trae la lista plana', false, array_key_exists('warnings', $tAv));

// Dos proveedores con el mismo 'tab': un solo grupo, con la seccion de cada uno.
$saldos = porTextoAv($gAv['saldos']);
chequear('Saldos y Caja Locales van al mismo grupo', true,
    isset($saldos['del saldo']) && isset($saldos['de la caja']));
chequear('que se llama como la pestana', Menu::tituloTab('saldos'), $gAv['saldos']['nombre']);
chequear('cada uno con su seccion', ['Saldo Inicial', 'Caja Locales'],
    [$saldos['del saldo']['seccion'], $saldos['de la caja']['seccion']]);
chequear('y con su origen', ['SALDOS', 'CAJA_LOCALES'],
    [$saldos['del saldo']['origen'], $saldos['de la caja']['origen']]);

$ech = porTextoAv($gAv['echeqs']);
chequear('el aviso propio del proveedor va a su pestana', 'info', $ech['propio de echeqs']['nivel']);
chequear('el de la serie tambien, y un texto suelto es warning', 'warning',
    $ech['de la serie']['nivel']);
chequear('el de la serie con nivel conserva el suyo', 'danger', $ech['de la serie critico']['nivel']);

$descartes = [];

foreach ($gAv['echeqs']['avisos'] as $a) {
    if (strpos($a['texto'], 'ECHEQS_FILA:') === 0) {
        $descartes[$a['nivel']] = $a['texto'];
    }
}

/* El orden es el del panel y no el de emision: dentro de un grupo,
   Aviso::agrupar() pone primero lo mas grave. Se emiten "fuera del horizonte"
   (info) y despues "sin fecha" (warning), y se leen al reves. */
chequear('los descartes de la fila van a la pestana de su proveedor', ['warning', 'info'],
    array_keys($descartes));
chequear('fuera del horizonte es informativo', true,
    isset($descartes['info']) && strpos($descartes['info'], 'fuera del horizonte') !== false);
chequear('sin fecha es atencion', true,
    isset($descartes['warning']) && strpos($descartes['warning'], 'sin fecha') !== false);

chequear('el modulo que falla va a su pestana, critico', 'danger',
    $gAv['proveedores_exterior']['avisos'][0]['nivel']);
chequear('el que no se pudo cargar tambien', true,
    strpos($gAv['crono_nacionalizacion']['avisos'][0]['texto'], 'No se pudo cargar') === 0);

$tab = porTextoAv($gAv[Aviso::GRUPO_TABLERO]);
chequear('el proveedor sin tab va a Tablero', true, isset($tab['de cobertura']));
chequear('con su nombre como seccion', 'Cobertura', $tab['de cobertura']['seccion']);

$motorTextos = implode(' | ', array_keys($tab));
chequear('los modulos sin construir van a Tablero', true,
    strpos($motorTextos, 'todavía no están construidos') !== false);
chequear('el modulo no registrado va a Tablero', true,
    strpos($motorTextos, '"NO_EXISTE" no está registrado') !== false);
chequear('Tablero no lleva link', false, $gAv[Aviso::GRUPO_TABLERO]['link']);
chequear('una pestana si', true, $gAv['echeqs']['link']);

// El orden de los grupos sale del de mas grave: nunca un grupo con un critico
// debajo de uno que no lo tiene.
$pesos = array_map(function ($g) { return Aviso::PESO[$g['nivel']]; }, $tAv['avisos']);
$ordenado = $pesos;
rsort($ordenado);
chequear('los grupos van de mas grave a menos', $ordenado, $pesos);

seccion('la apertura en cero va a Tablero, critica');

$estCero = new EstructuraAvisos();
$estCero->secciones = [seccionAv('DISP', 'SALDO', 10), seccionAv('RES', 'DERIVADO', 20)];
$estCero->filas = [
    filaAv(1, 'DISPONIBLE', 'DISP', 'SALDO_INICIAL', 10, 'SALDOS', 'DISPONIBLE'),
    filaAv(2, 'SALDO_FIN', 'RES', 'SALDO_FINAL', 10)
];

$motorCero = new CashflowAvisos($estCero, new ParametrosAvisos(), $hAv);
// SALDOS no devuelve la serie: la fila de saldo queda sin datos.
$motorCero->provs = ['SALDOS' => provAv('SALDOS')];
$tCero = $motorCero->proyectar();
$gCero = gruposAv($tCero);

$apertura = null;

foreach ($gCero[Aviso::GRUPO_TABLERO]['avisos'] as $a) {
    if (strpos($a['texto'], 'se muestra en CERO') !== false) {
        $apertura = $a;
    }
}

chequear('esta en Tablero', true, $apertura !== null);
chequear('y es critica', 'danger', $apertura === null ? null : $apertura['nivel']);
chequear('Tablero arriba de todo cuando tiene un critico', Aviso::GRUPO_TABLERO,
    $tCero['avisos'][0]['grupo']);

/* ================================================================
   Aviso::agrupar(): el orden
   ================================================================ */
seccion('el orden de los grupos y de los avisos');

$menu = ['cashflow', 'ventas', 'saldos', 'echeqs'];
$titulos = ['ventas' => 'Ventas', 'saldos' => 'Saldos', 'echeqs' => 'Echeqs', 'zzz' => 'Otra'];
$av = function ($nivel, $texto, $grupo) {
    $a = Aviso::nuevo($nivel, $texto);
    $a['grupo'] = $grupo;
    $a['origen'] = null;

    return $a;
};

$grupos = Aviso::agrupar([
    $av('info', 'v1', 'ventas'),
    $av('warning', 'e1', 'echeqs'),
    $av('warning', 'z1', 'zzz'),
    $av('warning', 't1', Aviso::GRUPO_TABLERO),
    $av('danger', 's1', 'saldos'),
    $av('info', 's2', 'saldos'),
    $av('warning', 'v2', 'ventas'),
    $av('info', 't2', Aviso::GRUPO_TABLERO),
    $av('danger', 'e2', 'echeqs')
], $menu, $titulos);

// Con critico: saldos y echeqs, en el orden del menu. Con atencion: Tablero
// primero, despues ventas (menu) y zzz (fuera del menu, al final).
chequear('primero los criticos, despues atencion; Tablero primero en su nivel; despues el menu',
    ['saldos', 'echeqs', 'tablero', 'ventas', 'zzz'], array_column($grupos, 'grupo'));
chequear('con el nombre de cada uno', ['Saldos', 'Echeqs', 'Tablero', 'Ventas', 'Otra'],
    array_column($grupos, 'nombre'));
chequear('el nivel del grupo es el de su aviso mas grave',
    ['danger', 'danger', 'warning', 'warning', 'warning'], array_column($grupos, 'nivel'));
chequear('dentro del grupo, de mas grave a menos', ['v2', 'v1'],
    array_column($grupos[3]['avisos'], 'texto'));
chequear('y la cuenta por nivel', ['danger' => 1, 'warning' => 1, 'info' => 0],
    $grupos[1]['cuenta']);

$iguales = Aviso::agrupar([
    $av('info', 'a', 'ventas'),
    $av('danger', 'b', 'ventas'),
    $av('warning', 'c', 'ventas'),
    $av('info', 'd', 'ventas'),
    $av('danger', 'e', 'ventas')
], $menu, $titulos);

chequear('a igual gravedad, en el orden en que se emitieron', ['b', 'e', 'c', 'a', 'd'],
    array_column($iguales[0]['avisos'], 'texto'));

chequear('solo informativos: al final aunque esten primero en el menu',
    ['echeqs', 'ventas'],
    array_column(Aviso::agrupar([$av('info', 'x', 'ventas'), $av('warning', 'y', 'echeqs')],
        $menu, $titulos), 'grupo'));
chequear('sin avisos no hay grupos', [], Aviso::agrupar([], $menu, $titulos));

/* ================================================================
   Los envoltorios: la pestana recibe los mismos textos
   ================================================================ */
seccion('cada envoltorio devuelve exactamente los textos de la lista con nivel');

$filasVenc = [
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-07-10', 'IMPORTE_ARS' => 100.0],
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-09-02', 'IMPORTE_ARS' => 50.0]
];
$vencNivel = Comex::avisosVencidosConNivel($filasVenc, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago', $hAv);
chequear('Comex::avisosVencidos()', Aviso::textos($vencNivel),
    Comex::avisosVencidos($filasVenc, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
        'fecha estimada de pago', $hAv));
chequear('lo que no suma es atencion', 'warning', $vencNivel[0]['nivel']);

$filasVal = [
    ['COTIZ_USD' => null, 'VALOR_FOB_DOLAR' => 10.0],
    ['COTIZ_USD' => 1000.0, 'COTIZ_ORIGEN' => DolarFuturo::ORIGEN_APROXIMADA],
    ['COTIZ_USD' => 1000.0, 'COTIZ_ORIGEN' => DolarFuturo::ORIGEN_OVERRIDE]
];
$valNivel = Comex::avisosValuacionConNivel($filasVal, '2027-03');
chequear('Comex::avisosValuacion()', Aviso::textos($valNivel),
    Comex::avisosValuacion($filasVal, '2027-03'));
chequear('sin valuar es atencion; aproximada y corregida a mano, informativo',
    ['warning', 'info', 'info'], array_column($valNivel, 'nivel'));

$items = [
    ['IMPORTE_PENDIENTE' => 10, 'EXCLUIDO' => false, 'ORIGEN_FECHA' => 'VENCIMIENTO',
     'SIN_FECHA_CARGADA' => true, 'PAGO_CRONO' => true],
    ['IMPORTE_PENDIENTE' => 20, 'EXCLUIDO' => false, 'ORIGEN_FECHA' => 'VENCIMIENTO',
     'SIN_FECHA_CARGADA' => true, 'PAGO_CRONO' => false],
    ['IMPORTE_PENDIENTE' => 30, 'EXCLUIDO' => false, 'ORIGEN_FECHA' => 'SIN_FECHA'],
    ['IMPORTE_PENDIENTE' => 40, 'EXCLUIDO' => true, 'ORIGEN_FECHA' => 'VENCIMIENTO']
];
$pendNivel = Proveedores::avisosPendientesConNivel($items);
chequear('Proveedores::avisosPendientes()', Aviso::textos($pendNivel),
    Proveedores::avisosPendientes($items));
chequear('los vencidos y sin fecha piden accion; el rubro excluido informa',
    ['warning', 'warning', 'warning', 'info'], array_column($pendNivel, 'nivel'));

$filasCorp = [
    ['MOTIVO' => TarjetasCorporativas::SIN_TARJETA, 'IMPORTE' => 10.0, 'VENCIDA' => true,
     'MOTIVO_EXCLUSION_TARJETAS' => ''],
    ['MOTIVO' => TarjetasCorporativas::EXCLUIDA, 'IMPORTE' => 20.0,
     'MOTIVO_EXCLUSION_TARJETAS' => 'la paga otro'],
    ['MOTIVO' => TarjetasCorporativas::CUBIERTA, 'IMPORTE' => 30.0,
     'MOTIVO_EXCLUSION_TARJETAS' => ''],
    ['MOTIVO' => 'OK', 'IMPORTE' => 40.0, 'VINCULO_ROTO' => true,
     'MOTIVO_EXCLUSION_TARJETAS' => '']
];
$corpNivel = TarjetasCorporativas::avisosConNivel($filasCorp);
chequear('TarjetasCorporativas::avisos()', Aviso::textos($corpNivel),
    TarjetasCorporativas::avisos($filasCorp));
chequear('sin vincular, excluidas, cubiertas y vinculo roto',
    ['warning', 'info', 'info', 'danger'], array_column($corpNivel, 'nivel'));

/* Los que leen la base no se pueden correr aca: se verifica que el envoltorio
   sea UNA linea que devuelve los textos de la version con nivel. Asi no hay
   forma de que la pestana y el tablero digan cosas distintas. */
$envoltorios = [
    ['CashflowEstructura.php', 'getAvisos', 'Aviso::textos($this->avisosConNivel())'],
    ['ComprasProyectadasDatos.php', 'avisosInsumos',
     'Aviso::textos($this->avisosInsumosConNivel($aniosCuota, $hoy, $pais))'],
    ['ComprasProyectadasDatos.php', 'avisosInsumosDe',
     'Aviso::textos(self::avisosInsumosDeConNivel($e, $aniosCuota, $ahora, $oficiales))'],
    ['CronogramaDatos.php', 'avisos', 'Aviso::textos($this->avisos)'],
    ['Proveedores.php', 'getAvisos', 'Aviso::textos($this->getAvisosConNivel())'],
    ['Proveedores.php', 'avisosCronograma', 'Aviso::textos($this->avisosCrono)'],
    ['ProveedoresCategorias.php', 'getAvisos', 'Aviso::textos($this->getAvisosConNivel())'],
    ['Tarjetas.php', 'avisos', 'Aviso::textos($this->avisosConNivel())'],
    ['PagosTarjetas.php', 'avisos', 'Aviso::textos($this->avisos)']
];

foreach ($envoltorios as $e) {
    $src = file_get_contents(__DIR__ . '/../Class/' . $e[0]);
    $patron = '/function ' . $e[1] . '\([^)]*\)\s*\{\s*return ' . preg_quote($e[2], '/') . ';\s*\}/';

    chequear($e[0] . ' ' . $e[1] . '() es un envoltorio de una linea', 1, preg_match($patron, $src));
}

/* Los que devuelven las dos listas en el mismo arreglo: 'avisos' (o
   'warnings') son los textos de 'avisos_con_nivel' (o 'avisos'). */
$pares = [
    ['ComprasProyectadas.php', "'warnings' => Aviso::textos(\$warnings),\n            'avisos' => \$warnings"],
    ['Logistica.php', "'avisos' => Aviso::textos(\$avisos),\n            'avisos_con_nivel' => \$avisos"],
    ['CronogramaDatos.php', "'avisos' => Aviso::textos(\$this->avisos),\n            'avisos_con_nivel' => \$this->avisos"],
    ['Saldos.php', "'avisos' => Aviso::textos(\$avisos),\n                'avisos_con_nivel' => \$avisos"],
    ['Saldos.php', "'avisos' => Aviso::textos(\$avisos),\n            'avisos_con_nivel' => \$avisos"],
    ['Ventas.php', "'warnings' => Aviso::textos(\$this->warnings),\n            'avisos_con_nivel' => Aviso::lista(\$this->warnings)"],
    ['PagosTarjetas.php', "\$bloque['avisos'] = Aviso::textos(\$conNivel);"]
];

foreach ($pares as $p) {
    $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../Class/' . $p[0]));

    chequear($p[0] . ': los textos salen de la lista con nivel', true, strpos($src, $p[1]) !== false);
}

/* ================================================================
   Todos los proveedores del registro: todo aviso con nivel valido
   ================================================================ */
seccion('todo proveedor del registro emite avisos con un nivel valido');

$ref = new ReflectionProperty('CashflowRegistry', 'providers');
$ref->setAccessible(true);
$registro = $ref->getValue();

/* SIN BASE: cada avisar() de cada proveedor dice su nivel con una constante
   de Aviso. Un avisar() sin nivel caeria en 'warning' por defecto, que puede
   ser correcto, pero tiene que ser una decision escrita y no un olvido. */
$archivos = [];

foreach ($registro as $codigo => $meta) {
    if (!empty($meta['archivo'])) {
        $archivos[$meta['archivo']] = true;
    }
}

foreach (array_keys($archivos) as $archivo) {
    $src = file_get_contents(__DIR__ . '/../Class/' . $archivo);
    preg_match_all('/->avisar\((.*?)\);/s', $src, $m);

    $sinNivel = array_values(array_filter($m[1], function ($args) {
        return strpos($args, 'Aviso::') === false;
    }));

    chequear($archivo . ': todo avisar() dice su nivel', [], $sinNivel);
}

/* CON BASE: se corre cada proveedor de verdad y se mira lo que emite. Solo
   lee: series() no escribe nada. */
if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base');
} else {
    $hReal = new Horizonte(5, 3);

    foreach ($registro as $codigo => $meta) {
        if (empty($meta['disponible']) || empty($meta['clase'])) {
            continue;
        }

        $prov = CashflowRegistry::instanciar($codigo);

        if ($prov === null) {
            continue;
        }

        $prov->series($hReal);

        $malos = array_values(array_filter($prov->avisos(), function ($a) {
            return !isset(Aviso::PESO[$a['nivel']]) || trim($a['texto']) === '';
        }));

        chequear($codigo . ': ' . count($prov->avisos()) . ' aviso(s), todos con nivel y texto',
            [], $malos);
    }
}
