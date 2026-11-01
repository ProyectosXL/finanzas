<?php
/**
 * Tarjetas Socios por banco.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LAS TARJETAS DE SOCIO VIEJAS SIGUEN PROYECTANDO al lado del banco que ya
 *      las incluye: el mismo consumo se cuenta dos veces.
 *
 *   2. UN BANCO SE CARGA DOS VECES, o una tarjeta se vuelve banco: dos filas
 *      estimando el mismo resumen.
 *
 *   3. UN BANCO SIN BASE DESAPARECE DEL TABLERO SIN QUE NADIE LO VEA: el aviso
 *      tiene que decir cuanto proyectaban sus tarjetas.
 *
 * Todo lo de aca es PURO, salvo la lectura de los scripts y del codigo.
 */

require_once __DIR__ . '/../Class/Tarjetas.php';
require_once __DIR__ . '/../Class/TarjetasSocios.php';
require_once __DIR__ . '/../Class/Cotizacion.php';
require_once __DIR__ . '/../Class/DolarFuturo.php';

function lanzaConSB($nombre, $fn, $fragmento) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $fragmento) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
}

function resumenSB($id, $mes, $ars, $usd, $fecha) {
    return ['ID' => $id, 'MES' => $mes, 'IMPORTE_ARS' => $ars, 'IMPORTE_USD' => $usd,
            'FECHA_VENCIMIENTO' => $fecha, 'PAGADO' => true, 'ORIGEN' => 'HISTORICO'];
}

$HABSB = [];

for ($d = new DateTime('2026-08-01'); $d <= new DateTime('2027-01-31'); $d->modify('+1 day')) {
    $HABSB[$d->format('Y-m-d')] = intval($d->format('N')) <= 5;
}

$MSB = ['2026-11', '2026-12'];
$HSB = '2026-11-01';
$INFSB = ['2026-08' => 2, '2026-09' => 2, '2026-10' => 2, '2026-11' => 2, '2026-12' => 2];
$CURSB = ['2026-11' => ['clave' => '2026-11', 'simbolo' => 'DLR/NOV26', 'cotizacion' => 1600.0],
          '2026-12' => ['clave' => '2026-12', 'simbolo' => 'DLR/DIC26', 'cotizacion' => 1650.0]];
$BCRASB = ['fecha' => '2026-10-30', 'valor' => 1500.0, 'punta' => Cotizacion::COMPRADOR];

/* Un banco de socios: sin usuario, vence el 15, 10 % de cobertura. */
$BANCO = ['ID' => 30, 'TIPO' => Tarjetas::SOCIOS_BANCO, 'COD_BANCO' => '007',
          'DESC_BANCO' => 'DE GALICIA Y BS.AS.', 'ID_USUARIO' => null, 'NOMBRE_USUARIO' => '',
          'ULTIMOS_4' => null, 'PCT_COBERTURA' => 10.0, 'DIA_VENCIMIENTO' => 15, 'ACTIVA' => true];

/* ================================================================
   EL MAESTRO
   ================================================================ */
seccion('un banco de socios en el maestro');

chequear('se nombra por el banco, sin tarjeta ni socio', 'Socios · DE GALICIA Y BS.AS.',
    Tarjetas::rotulo($BANCO));
chequear('el tipo existe', 'Socios (por banco)', Tarjetas::TIPOS[Tarjetas::SOCIOS_BANCO]);

lanzaConSB('no se da de alta una tarjeta de socio con nombre', function () {
    Tarjetas::validarCambioDeTipo('SOCIO', null);
}, 'ahora van por banco');

lanzaConSB('una tarjeta no se vuelve banco', function () {
    Tarjetas::validarCambioDeTipo(Tarjetas::SOCIOS_BANCO, ['TIPO' => 'CORPORATIVA']);
}, 'no se convierte en un banco');

lanzaConSB('un banco no se vuelve tarjeta', function () {
    Tarjetas::validarCambioDeTipo('CORPORATIVA', ['TIPO' => Tarjetas::SOCIOS_BANCO]);
}, 'no se convierte en una tarjeta');

lanzaConSB('una tarjeta de socio vieja no se edita', function () {
    Tarjetas::validarCambioDeTipo('SOCIO', ['TIPO' => 'SOCIO']);
}, 'historial');

Tarjetas::validarCambioDeTipo(Tarjetas::SOCIOS_BANCO, null);
Tarjetas::validarCambioDeTipo(Tarjetas::SOCIOS_BANCO, ['TIPO' => Tarjetas::SOCIOS_BANCO]);
Tarjetas::validarCambioDeTipo('SUPERVISORA', ['TIPO' => 'CORPORATIVA']);
chequear('alta de un banco, correccion de un banco y Supervisora/Corporativa: se permiten', true, true);

/* ================================================================
   LA ESTIMACION POR BANCO, CON 0, 1, 2 Y 3 RESUMENES
   ================================================================ */
seccion('la estimacion por banco, con 0, 1, 2 y 3 resumenes');

$tres = [
    '2026-08' => resumenSB(1, '2026-08', 300000, 100, '2026-08-17'),
    '2026-09' => resumenSB(2, '2026-09', 330000, null, '2026-09-15'),
    '2026-10' => resumenSB(3, '2026-10', 270000, 200, '2026-10-15')
];

$casos = [
    0 => [],
    1 => array_slice($tres, 2, 1, true),
    2 => array_slice($tres, 1, 2, true),
    3 => $tres
];

$esperado = [
    0 => [TarjetasSocios::SIN_BASE, null, null],
    1 => [TarjetasSocios::BASE_INCOMPLETA, 270000.0, 200.0],
    2 => [TarjetasSocios::BASE_INCOMPLETA, 300000.0, 100.0],
    3 => [TarjetasSocios::OK, 300000.0, 100.0]
];

foreach ($casos as $n => $res) {
    $b = TarjetasSocios::base($res, '2026-11');

    chequear("con $n resumen(es): motivo y promedio, divididos por los que hay", $esperado[$n],
        [$b['motivo'], $b['promedio_ars'], $b['promedio_usd']]);
}

$b0 = TarjetasSocios::base([], '2026-11');
$e0 = TarjetasSocios::estimar($BANCO, $b0, $MSB, $INFSB, [], $HABSB, $CURSB, $BCRASB, $HSB);

chequear('sin resumenes no proyecta y queda en blanco, no en cero', [false, null],
    [$e0['meses']['2026-11']['proyecta'], $e0['meses']['2026-11']['total_ars']]);
chequear('y el aviso dice que cargues la base historica', true,
    strpos(TarjetasSocios::avisoBase($b0, Tarjetas::rotulo($BANCO)), 'Cargá la base histórica') !== false);

$b3 = TarjetasSocios::base($tres, '2026-11');
$e3 = TarjetasSocios::estimar($BANCO, $b3, $MSB, $INFSB, $tres, $HABSB, $CURSB, $BCRASB, $HSB);
$nov = $e3['meses']['2026-11'];
$dic = $e3['meses']['2026-12'];

chequear('el banco vence el dia que tiene cargado', '2026-11-16', $nov['fecha']);
chequear('pesos con cobertura e inflacion compuesta', round(300000 * 1.10 * 1.02, 6), round($nov['ars'], 6));
chequear('dolares con cobertura y sin inflacion', round(100 * 1.10, 6), round($nov['usd'], 6));
chequear('el proximo vencimiento va a dolar BCRA', ['BCRA', 1500.0], [$nov['tc_origen'], $nov['tc']]);
chequear('los siguientes a dolar futuro', ['FUTURO', 1650.0], [$dic['tc_origen'], $dic['tc']]);

chequear('totalProyectado suma lo que proyecta en pesos',
    round($nov['total_ars'] + $dic['total_ars'], 4),
    round(TarjetasSocios::totalProyectado($BANCO, $tres, $MSB, $INFSB, $HABSB, $CURSB, $BCRASB, $HSB), 4));
chequear('y sin base es cero, para el aviso', 0.0,
    TarjetasSocios::totalProyectado($BANCO, [], $MSB, $INFSB, $HABSB, $CURSB, $BCRASB, $HSB));

/* ================================================================
   LA PESTANA: LAS VIEJAS NO PROYECTAN, Y AVISAN
   ================================================================ */
seccion('la pestana proyecta los bancos, y las tarjetas viejas solo avisan');

$fuentePT = file_get_contents(__DIR__ . '/../Class/PagosTarjetas.php');

chequear('proyecta las filas de banco activas', true,
    strpos($fuentePT, "if (\$t['TIPO'] === Tarjetas::SOCIOS_BANCO && \$t['ACTIVA']) {") !== false);
chequear('las viejas se juntan por banco, solo para el aviso', true,
    strpos($fuentePT, "\$viejas[\$t['COD_BANCO']][\$id] = \$t;") !== false);
chequear('un banco sin base dice cuanto proyectaban sus tarjetas', true,
    strpos($fuentePT, "' Sus tarjetas de socio proyectaban '") !== false);
chequear('un banco viejo sin dar de alta tambien avisa, de atencion', true,
    strpos($fuentePT, "Aviso::nuevo(Aviso::WARNING, 'Socios · ' . \$nombre . ': sus '") !== false);

/* ================================================================
   EL SCRIPT
   ================================================================ */
seccion('el script de socios por banco');

$sqlSB = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_socios_banco.sql');

chequear('pregunta antes de cambiar las columnas', true,
    strpos($sqlSB, "COLUMNPROPERTY(OBJECT_ID('dbo.RO_T_CASHFLOW_TARJETAS'), 'ID_USUARIO', 'AllowsNull') = 0") !== false);
chequear('vuelve a crear la identidad igual', true,
    strpos($sqlSB, 'ON dbo.RO_T_CASHFLOW_TARJETAS (COD_BANCO, ID_USUARIO, ULTIMOS_4);') !== false);
chequear('el banco no tiene usuario y la tarjeta si', true,
    strpos($sqlSB, "CHECK ((TIPO = 'SOCIOS_BANCO' AND ID_USUARIO IS NULL") !== false);
chequear('las tarjetas de socio quedan de baja con usuario MIGRACION', true,
    strpos($sqlSB, "SET ACTIVA = 0, USUARIO_BAJA = 'MIGRACION'") !== false
    && strpos($sqlSB, "WHERE TIPO = 'SOCIO' AND ACTIVA = 1;") !== false);
chequear('los resumenes no se migran ni se tocan', false,
    stripos($sqlSB, 'RO_T_CASHFLOW_TARJETAS_RESUMEN') !== false);
chequear('en una transaccion, con XACT_ABORT', true,
    strpos($sqlSB, 'SET XACT_ABORT ON;') !== false && strpos($sqlSB, 'BEGIN TRANSACTION;') !== false);

foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
    chequear("no tiene $prohibido", false, stripos($sqlSB, $prohibido) !== false);
}
