<?php
include_once __DIR__."/../class/remitoEquis.php";

function traerTodos ($campo = null){

    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->traerTodos($campo);
    return $result;
    

}    

function traerDetalle($codCliente){
    
    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->traerDetalle($codCliente);
    return $result;


}

function traerCheques (){
 
    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->traerTodosLosCheques();
    return $result;

}

function traerReporteDeCheques ($inputBuscar, $selectEstado, $desde, $hasta){
 
    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->traerReporteTodosLosCheques($inputBuscar, $selectEstado, $desde, $hasta);
    return $result;

}

function traerEfectivoCheque (){
 
    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->traerEfectivoCheque();
    return $result;

}

function listarRemitos ( $desde, $hasta, $selectEstado, $inputBuscar , $selectTalonario){
    
    $remitoEquis = new RemitoEquis();
    $result = $remitoEquis->listarDetalleRemitos( $desde, $hasta, $selectEstado, $inputBuscar, $selectTalonario );
    return $result;
}

?>