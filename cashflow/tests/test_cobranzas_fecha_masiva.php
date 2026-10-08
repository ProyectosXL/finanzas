<?php
/**
 * La fecha de cobro manual de Cobranzas FR y May, de a una y DE A MUCHAS.
 *
 * El gesto masivo es el de Proveedores Locales: se seleccionan facturas, se
 * elige una fecha y se escriben todas o ninguna. Lo que estas pruebas cuidan
 * es lo que se rompe callado:
 *
 *   - que la fecha pasada se rechace EN EL SERVIDOR, tambien en el masivo;
 *   - que el lote vacio no llegue a abrir nada;
 *   - que la transaccion este escrita una sola vez y que la usen los cuatro
 *     gestos -guardar y borrar, de a una y de a muchas-;
 *   - que sea de verdad todo o nada, con una falla simulada en el medio;
 *   - que la edicion de a una siga funcionando igual.
 *
 * LA PRUEBA DE "TODO O NADA" NO ESCRIBE EN NINGUNA TABLA REAL. La base es la
 * de produccion, asi que la subclase de abajo reemplaza la conexion y las dos
 * escrituras por comprobante: escribe solo en una #temporal de la sesion, que
 * la propia prueba crea. Y se verifica a si misma: si alguien le agrega el
 * nombre de una tabla dbo o una llamada al metodo original, la prueba falla.
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../Class/Ingresos.php';
require_once __DIR__ . '/../Class/AuthCashflow.php';

$fuenteIngresos = file_get_contents(__DIR__ . '/../Class/Ingresos.php');

/** El cuerpo de un metodo de Ingresos, para los chequeos de cableado */
$cuerpoIngresos = function ($metodo) {
    $r = new ReflectionMethod('Ingresos', $metodo);

    return implode('', array_slice(file(__DIR__ . '/../Class/Ingresos.php'),
        $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
};

$MANIANA = date('Y-m-d', strtotime('+1 day'));
$AYER = date('Y-m-d', strtotime('-1 day'));

// ============================================================================
// Las claves: que se considera una factura identificada
// ============================================================================

seccion('las claves del lote se normalizan antes de abrir nada');

$claves = Ingresos::normalizarClavesCobro([
    ['cod_cliente' => ' frabc ', 't_comp' => 'fac', 'n_comp' => ' a0001-00000123 '],
    ['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'A0001-00000124']
], 'probar');

chequear('la clave es T_COMP|N_COMP, en mayusculas y sin espacios',
    ['FAC|A0001-00000123', 'FAC|A0001-00000124'], array_keys($claves));
chequear('el cliente viaja al lado, normalizado', 'FRABC', $claves['FAC|A0001-00000123']['cod']);

// La misma factura dos veces es una: la clave de la tabla no incluye al cliente.
chequear('la misma factura repetida se escribe una vez', 1, count(Ingresos::normalizarClavesCobro([
    ['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'X1'],
    ['cod_cliente' => 'frabc', 't_comp' => 'fac', 'n_comp' => 'x1']
], 'probar')));

// El cliente no identifica nada, y la edicion de a una nunca lo exigio.
chequear('el cliente es opcional', '', Ingresos::normalizarClavesCobro(
    [['t_comp' => 'FAC', 'n_comp' => 'X1']], 'probar')['FAC|X1']['cod']);

chequearLanza('un renglon sin numero de comprobante se rechaza', function () {
    Ingresos::normalizarClavesCobro([['t_comp' => 'FAC', 'n_comp' => '']], 'probar');
}, 'Falta el comprobante al que corresponde la fecha de cobro.');

chequearLanza('el lote vacio se rechaza y dice para que era', function () {
    Ingresos::normalizarClavesCobro([], 'ponerles la fecha de cobro');
}, 'No llegó ninguna factura para ponerles la fecha de cobro.');

chequearLanza('algo que no es una lista tambien', function () {
    Ingresos::normalizarClavesCobro('FAC|X1', 'probar');
});

// ============================================================================
// El servidor rechaza antes de conectarse
//
// Estas llamadas no tocan la base: la validacion va ANTES de abrir la
// conexion, que es justamente lo que se prueba. Si alguna llegara a conectar,
// fallaria por otra cosa y el mensaje no coincidiria.
// ============================================================================

seccion('el masivo rechaza la fecha pasada y el lote vacio en el servidor');

$uno = [['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'X1']];

chequearLanza('una fecha pasada se rechaza en el masivo', function () use ($uno, $AYER) {
    (new Ingresos())->saveFechaManualMasiva($uno, $AYER, 'pruebas');
}, 'La fecha de cobro no puede ser anterior a hoy (' . date('d/m/Y')
    . '). Una factura con fecha pasada desaparecería del listado de pendientes.');

chequearLanza('una fecha que no existe tambien', function () use ($uno) {
    (new Ingresos())->saveFechaManualMasiva($uno, '2030-02-30', 'pruebas');
}, 'La fecha de cobro no existe en el calendario.');

chequearLanza('el lote vacio no se escribe', function () use ($MANIANA) {
    (new Ingresos())->saveFechaManualMasiva([], $MANIANA, 'pruebas');
}, 'No llegó ninguna factura para ponerles la fecha de cobro.');

chequearLanza('ni se borra', function () {
    (new Ingresos())->deleteFechaManualMasiva([]);
}, 'No llegó ninguna factura para volverlas a la fecha calculada.');

chequearLanza('sin usuario no se escribe nada', function () use ($uno, $MANIANA) {
    (new Ingresos())->saveFechaManualMasiva($uno, $MANIANA, '');
});

$cuerpoMasiva = $cuerpoIngresos('saveFechaManualMasiva');

chequear('la fecha se valida antes de abrir el lote', true,
    strpos($cuerpoMasiva, 'self::validarFechaCobroManual($fecha)')
        < strpos($cuerpoMasiva, '$this->guardarLoteFechas('));
chequear('y las claves tambien', true,
    strpos($cuerpoMasiva, 'self::normalizarClavesCobro(')
        < strpos($cuerpoMasiva, '$this->guardarLoteFechas('));

// ============================================================================
// La transaccion, escrita una sola vez
// ============================================================================

seccion('la transaccion esta escrita una sola vez y la usan los cuatro gestos');

$cuerpoLote = $cuerpoIngresos('enLoteFechas');

chequear('el lote abre una transaccion', true,
    strpos($cuerpoLote, 'sqlsrv_begin_transaction($cid)') !== false);
chequear('y si algo falla no queda nada escrito', true,
    strpos($cuerpoLote, 'sqlsrv_rollback($cid)') !== false);

// Una segunda copia de la transaccion es la que se olvida el rollback.
chequear('ningun otro metodo de Ingresos abre una transaccion', 1,
    substr_count($fuenteIngresos, 'sqlsrv_begin_transaction('));

chequear('guardar pasa por el lote', true,
    strpos($cuerpoIngresos('guardarLoteFechas'), '$this->enLoteFechas(') !== false);
chequear('borrar tambien', true,
    strpos($cuerpoIngresos('borrarLoteFechas'), '$this->enLoteFechas(') !== false);

foreach (['saveFechaManual' => 'guardarLoteFechas', 'saveFechaManualMasiva' => 'guardarLoteFechas',
          'deleteFechaManual' => 'borrarLoteFechas', 'deleteFechaManualMasiva' => 'borrarLoteFechas']
         as $metodo => $lote) {
    $cuerpo = $cuerpoIngresos($metodo);

    chequear($metodo . ' escribe por ' . $lote, true, strpos($cuerpo, '$this->' . $lote . '(') !== false);
    chequear($metodo . ' no escribe contra la tabla por su cuenta', false,
        strpos($cuerpo, 'sqlsrv_query') !== false);
}

// La tabla se escribe en DOS lugares y nada mas: uno guarda, el otro borra.
chequear('solo dos metodos escriben en la tabla de fechas manuales', 2,
    preg_match_all('/(INSERT INTO|DELETE FROM) RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL/',
        $fuenteIngresos));
chequear('el INSERT y el UPDATE viven en escribirFechaManual', 2,
    preg_match_all('/(INSERT INTO|UPDATE) RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL/',
        $cuerpoIngresos('escribirFechaManual')));
chequear('el DELETE vive en borrarFechaManual', 1,
    substr_count($cuerpoIngresos('borrarFechaManual'), 'DELETE FROM RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL'));

// Toda escritura deja quien y cuando.
chequear('la escritura usa las columnas de auditoria', true,
    strpos($cuerpoIngresos('escribirFechaManual'), 'Auditoria::SET_MODIF') !== false
    && strpos($cuerpoIngresos('escribirFechaManual'), 'USUARIO_ALTA, USUARIO_MODIF') !== false);

// "Volver a la fecha calculada" cuenta lo que se borro de verdad.
chequear('el borrado cuenta solo las que tenian fecha manual', true,
    strpos($cuerpoIngresos('borrarFechaManual'), 'sqlsrv_rows_affected($stmt)') !== false);

// ============================================================================
// La edicion de a una sigue igual
// ============================================================================

seccion('la edicion de a una sigue funcionando igual');

$firma = function ($metodo) {
    return array_map(function ($p) { return $p->getName(); },
        (new ReflectionMethod('Ingresos', $metodo))->getParameters());
};

chequear('saveFechaManual recibe lo mismo que antes',
    ['codCliente', 'tComp', 'nComp', 'fecha', 'usuario'], $firma('saveFechaManual'));
chequear('deleteFechaManual tambien', ['tComp', 'nComp'], $firma('deleteFechaManual'));

chequearLanza('de a una, la fecha pasada se sigue rechazando', function () use ($AYER) {
    (new Ingresos())->saveFechaManual('FRABC', 'FAC', 'X1', $AYER, 'pruebas');
});

chequearLanza('y el comprobante faltante con el mismo mensaje de siempre', function () use ($MANIANA) {
    (new Ingresos())->saveFechaManual('FRABC', 'FAC', '', $MANIANA, 'pruebas');
}, 'Falta el comprobante al que corresponde la fecha de cobro.');

$ctrl = file_get_contents(__DIR__ . '/../Controller/IngresosController.php');

foreach (['saveFechaCobroManual', 'deleteFechaCobroManual'] as $accion) {
    chequear('el endpoint ' . $accion . ' sigue estando', true,
        strpos($ctrl, "case '" . $accion . "':") !== false);
}

// ============================================================================
// Los endpoints masivos y su permiso
// ============================================================================

seccion('los endpoints masivos existen y piden el mismo permiso que la edicion de a una');

foreach (['saveFechaCobroManualMasiva', 'deleteFechaCobroManualMasiva'] as $accion) {
    chequear('el endpoint ' . $accion . ' existe', true,
        strpos($ctrl, "case '" . $accion . "':") !== false);

    chequear($accion . ' esta en el mapa de permisos, con los destinos de la de a una',
        AuthCashflow::ESCRITURAS['Ingresos']['saveFechaCobroManual'],
        AuthCashflow::ESCRITURAS['Ingresos'][$accion] ?? null);
}

chequear('el masivo exige la fecha, que la pantalla no puede garantizar', true,
    strpos($ctrl, 'Falta la fecha de cobro que hay que ponerles.') !== false);

// ============================================================================
// El dialogo de la fecha valida el minimo
//
// Las dos pestanas piden la fecha con Notificacion.pedirFecha() y `min` en hoy.
// El `min` del input solo limita el calendario: una fecha pasada tipeada a mano
// pasaba y el error aparecia recien en el servidor, con el dialogo ya cerrado.
// La validacion que vale sigue siendo la del servidor -arriba-; esto explica y
// bloquea.
// ============================================================================

seccion('el dialogo de fecha no deja confirmar una fecha anterior al minimo');

$notiJs = file_get_contents(__DIR__ . '/../Js/notificaciones.js');

chequear('al confirmar compara contra el minimo', true,
    preg_match('/if \(opciones\.min && v < opciones\.min\) \{.*?return undefined;/s', $notiJs) === 1);
chequear('y dice por que no cierra', true,
    strpos($notiJs, "'La fecha no puede ser anterior al '") !== false);
chequear('el respaldo sin Bootstrap tampoco la devuelve', true,
    strpos($notiJs, '!(opciones.min && previo < opciones.min)') !== false);

// ============================================================================
// Todo o nada, con una falla simulada en el medio
// ============================================================================

/**
 * El lote real con la conexion y las dos escrituras reemplazadas.
 *
 * NO ESCRIBE EN NINGUNA TABLA REAL: la conexion es la de la prueba, donde vive
 * la #temporal, y las escrituras por comprobante van SOLO a esa #temporal y no
 * llaman al metodo original. Lo unico que corre del codigo de produccion es
 * enLoteFechas() -abrir, confirmar o deshacer la transaccion-, que es lo que
 * se prueba.
 */
class IngresosLotePrueba extends Ingresos {
    public $cid;
    public $fallarEn = null;
    public $llamadas = 0;

    protected function conexionFechas() {
        return $this->cid;
    }

    protected function escribirFechaManual($cid, $c, $fecha, $usuario) {
        $this->llamadas++;

        if ($this->fallarEn === $this->llamadas) {
            throw new Exception('falla simulada en el comprobante ' . $this->llamadas);
        }

        if (sqlsrv_query($cid, 'INSERT INTO #LOTE_PRUEBA (T_COMP, N_COMP, FECHA) VALUES (?, ?, ?)',
                [$c['t'], $c['n'], $fecha]) === false) {
            throw new Exception('no se pudo escribir en la temporal');
        }

        return 1;
    }

    protected function borrarFechaManual($cid, $c) {
        $this->llamadas++;

        if ($this->fallarEn === $this->llamadas) {
            throw new Exception('falla simulada en el comprobante ' . $this->llamadas);
        }

        $stmt = sqlsrv_query($cid, 'DELETE FROM #LOTE_PRUEBA WHERE T_COMP = ? AND N_COMP = ?',
            [$c['t'], $c['n']]);

        return $stmt === false ? 0 : (int) sqlsrv_rows_affected($stmt);
    }
}

seccion('la prueba de todo o nada no escribe en ninguna tabla real');

$rc = new ReflectionClass('IngresosLotePrueba');
$fuenteSubclase = implode('', array_slice(file(__FILE__),
    $rc->getStartLine() - 1, $rc->getEndLine() - $rc->getStartLine() + 1));

// La garantia de que la prueba es segura contra la base de produccion, escrita
// como prueba: si alguien la cambia, falla aca antes de escribir nada.
chequear('la subclase no nombra ninguna tabla real', 0,
    preg_match('/RO_T_|dbo\./i', $fuenteSubclase));
chequear('y no llama a las escrituras originales', false,
    strpos($fuenteSubclase, 'parent::') !== false);
chequear('reemplaza la conexion y las dos escrituras', ['borrarFechaManual', 'conexionFechas',
    'escribirFechaManual'], (function () use ($rc) {
        $propios = [];

        foreach ($rc->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === 'IngresosLotePrueba') {
                $propios[] = $m->getName();
            }
        }

        sort($propios);

        return $propios;
    })());

seccion('todo o nada: una falla en el medio no deja nada escrito');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('sin conexion a la base, no hay donde crear la #temporal');
} else {
    $conexion = new Conexion;
    $cidPrueba = $conexion->conectar('central');

    sqlsrv_query($cidPrueba, 'CREATE TABLE #LOTE_PRUEBA (T_COMP VARCHAR(10), N_COMP VARCHAR(20), FECHA DATE)');

    $contar = function () use ($cidPrueba) {
        $s = sqlsrv_query($cidPrueba, 'SELECT COUNT(*) AS N FROM #LOTE_PRUEBA');

        return (int) sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)['N'];
    };

    $tres = [
        ['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'P1'],
        ['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'P2'],
        ['cod_cliente' => 'FRABC', 't_comp' => 'FAC', 'n_comp' => 'P3']
    ];

    $lote = new IngresosLotePrueba();
    $lote->cid = $cidPrueba;
    $lote->fallarEn = 2;

    chequearLanza('si el segundo comprobante falla, el lote lanza', function () use ($lote, $tres, $MANIANA) {
        $lote->saveFechaManualMasiva($tres, $MANIANA, 'pruebas');
    }, 'falla simulada en el comprobante 2');

    // El primero llego a escribirse adentro de la transaccion: si el rollback
    // no estuviera, quedaria aca.
    chequear('y el primero, que ya se habia escrito, no queda', 0, $contar());

    $lote = new IngresosLotePrueba();
    $lote->cid = $cidPrueba;
    $lote->saveFechaManualMasiva($tres, $MANIANA, 'pruebas');

    chequear('sin falla se escriben las tres', 3, $contar());

    $lote = new IngresosLotePrueba();
    $lote->cid = $cidPrueba;
    $lote->fallarEn = 2;

    chequearLanza('volver a la calculada tambien es todo o nada', function () use ($lote, $tres) {
        $lote->deleteFechaManualMasiva($tres);
    });
    chequear('y no borro la primera', 3, $contar());

    $lote = new IngresosLotePrueba();
    $lote->cid = $cidPrueba;

    chequear('volver a la calculada cuenta solo las que tenian fecha', ['borradas' => 2],
        $lote->deleteFechaManualMasiva([
            ['t_comp' => 'FAC', 'n_comp' => 'P1'],
            ['t_comp' => 'FAC', 'n_comp' => 'P2'],
            ['t_comp' => 'FAC', 'n_comp' => 'SIN-FECHA']
        ]));

    // La edicion de a una pasa por el mismo lote: un lote de uno.
    $lote = new IngresosLotePrueba();
    $lote->cid = $cidPrueba;

    chequear('de a una escribe por el mismo lote', $MANIANA,
        $lote->saveFechaManual('FRABC', 'FAC', 'P9', $MANIANA, 'pruebas'));
    chequear('y deja su fila', 2, $contar());
    chequear('y borra por el mismo lote', true, $lote->deleteFechaManual('FAC', 'P9'));
    chequear('y saca su fila', 1, $contar());

    sqlsrv_query($cidPrueba, 'DROP TABLE #LOTE_PRUEBA');
}
