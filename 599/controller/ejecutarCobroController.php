<?php
include_once __DIR__."/../class/remitoEquis.php";

$remitos = $_POST['remitos'];
$cobroEfectivo = $_POST['cobroEfectivo'] > 0 ? $_POST['cobroEfectivo'] : 0;
$cobroDeposito = $_POST['cobroDeposito'] > 0 ? $_POST['cobroDeposito'] : 0;
$cobroCheque = $_POST['cobroCheque'] > 0 ? $_POST['cobroCheque'] : 0;
$saldoCobrar = $_POST['saldoCobrar'] > 0 ? $_POST['saldoCobrar'] : 0;
$montoACobrar = $_POST['montoACobrar'];
$codClient = $_POST['codClient'];
$postCheques = $_POST['idCheque'];
$nombreCliente = $_POST['nombreCliente'];
$valorDescontado = $_POST['valorDescontado'];
$username = $_POST['username'];
$importeDolares  = isset($_POST['importe_dolares'])  ? floatval($_POST['importe_dolares'])  : 0;
$cotizacionDolar = isset($_POST['cotizacion_dolar']) ? floatval($_POST['cotizacion_dolar']) : 0;

$idCheques = explode(",", $postCheques);

$equivalentePesos = round($importeDolares * $cotizacionDolar);
$importeTotal = $cobroEfectivo + $cobroDeposito + $cobroCheque + $equivalentePesos;
$montoTotal = $montoACobrar - $saldoCobrar;
$remitoEquis = new RemitoEquis();

$remitosParceados = "";

$existeCobro = $remitoEquis->buscarCobro ($codClient, $cobroEfectivo, $cobroCheque, $importeTotal) ;

if($existeCobro) {
    echo 0;
    die();
}


$idCobro = $remitoEquis->guardarCobro($codClient, $cobroEfectivo, $cobroCheque, $importeTotal, $nombreCliente, $valorDescontado, $username, $cobroDeposito, $importeDolares, $cotizacionDolar);


foreach ($idCheques as $value) {

    $remitoEquis->asignarCobroPorCheque($idCobro,$value);
    # code...
}


// Validar que existan remitos a procesar
if (empty($remitos) || count($remitos) == 0) {
    echo 0;
    die();
}

foreach ($remitos as $num => $remito) {
    // Limpiar espacios y validar que el remito no esté vacío
    $remito = trim($remito);
    if (empty($remito)) {
        continue;
    }
    
    if($num == 0){
        $remitosParceados = $remitosParceados."'$remito'";
    } else{
        $remitosParceados = $remitosParceados.",'$remito'";
    }
}

$remitosDetalle = $remitoEquis->traerRemito($remitosParceados);

// Validar que se obtuvieron remitos de la BD
if (empty($remitosDetalle) || count($remitosDetalle) == 0) {
    echo 0;
    die();
}

// Validar que la cantidad coincida
if (count($remitosDetalle) != count($remitos)) {
    // Advertencia: cantidad no coincide
}

$remitosProcessados = 0;
$remitosFallidos = 0;

foreach ($remitosDetalle as $index => $detalle) {
    
    // Verificar si ya está chequeado
    if (isset($detalle['CHEQUEADO']) && $detalle['CHEQUEADO'] == 1) {
        $remitosFallidos++;
        continue;
    }
    
    try {
        // Intentar cambiar estado
        $estadoCambiado = $remitoEquis->cambiarEstado($detalle['N_COMP']);
        
        // Intentar guardar cobro por remito
        $cobroGuardado = $remitoEquis->guardarCobroRemito($idCobro, $detalle['N_COMP']);
        
        if ($estadoCambiado && $cobroGuardado) {
            $remitosProcessados++;
            
            // Restar del monto total solo si se procesó correctamente
            $montoTotal = intval($montoTotal) - intval($detalle['IMPORTE_TO']);
        } else {
            $remitosFallidos++;
        }
        
    } catch (Exception $e) {
        $remitosFallidos++;
    }
}

echo 1;



?>