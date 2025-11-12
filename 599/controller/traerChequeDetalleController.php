<?php
include_once __DIR__."/../class/remitoEquis.php";

$idCheque = $_POST['idCheque'];

$remitoEquis = new RemitoEquis();

$result = $remitoEquis->traerChequeDetalle($idCheque);

echo json_encode($result);


?>