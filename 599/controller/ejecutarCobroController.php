<?php
include_once __DIR__."/../class/remitoEquis.php";

$remitos = $_POST['remitos'];
$cobroEfectivo = $_POST['cobroEfectivo'] > 0 ? $_POST['cobroEfectivo'] : 0;
$cobroCheque = $_POST['cobroCheque'] > 0 ? $_POST['cobroCheque'] : 0;
$saldoCobrar = $_POST['saldoCobrar'] > 0 ? $_POST['saldoCobrar'] : 0;
$montoACobrar = $_POST['montoACobrar'];
$codClient = $_POST['codClient'];
$postCheques = $_POST['idCheque'];
$nombreCliente = $_POST['nombreCliente'];
$valorDescontado = $_POST['valorDescontado'];
$username = $_POST['username'];

$idCheques = explode(",", $postCheques);

$importeTotal = $cobroEfectivo + $cobroCheque; 
$montoTotal = $montoACobrar - $saldoCobrar;
$remitoEquis = new RemitoEquis();

$remitosParceados = "";

$existeCobro = $remitoEquis->buscarCobro ($codClient, $cobroEfectivo, $cobroCheque, $importeTotal) ;

if($existeCobro) {
    echo 0;
    die();
}


$idCobro = $remitoEquis->guardarCobro($codClient, $cobroEfectivo, $cobroCheque, $importeTotal,$nombreCliente, $valorDescontado, $username);


foreach ($idCheques as $value) {

    $remitoEquis->asignarCobroPorCheque($idCobro,$value);
    # code...
}


foreach ($remitos as $num => $remito) {

    if($num == 0){
        $remitosParceados = $remitosParceados."'$remito'";
    } else{

        $remitosParceados = $remitosParceados.",'$remito'";
    }
    
}



$remitosDetalle = $remitoEquis->traerRemito($remitosParceados);

foreach ($remitosDetalle as $detalle) {

    if( (intval($montoTotal) + intval($valorDescontado)) >= intval($detalle['IMPORTE_TO'])){


        $remitoEquis->cambiarEstado($detalle['N_COMP']);

        $remitoEquis->guardarCobroRemito($idCobro, $detalle['N_COMP']);

        $montoTotal = intval($montoTotal) - intval($detalle['IMPORTE_TO']);
            
    }
        
        
}

echo 1;



?>