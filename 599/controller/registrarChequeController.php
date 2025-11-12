<?php
include_once __DIR__."/../class/remitoEquis.php";

$dataDelCheque = $_POST;

$remitoEquis = new RemitoEquis();

$remitoEquis->cargarCheque($dataDelCheque);
$idCheque = $remitoEquis->traerChequeNumInterno();
echo $idCheque;    


?>