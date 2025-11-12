<?php
include_once __DIR__."/../class/remitoEquis.php";

$codClient = $_POST['codClient'];

$remitoEquis = new RemitoEquis();

$result = $remitoEquis->traerChequeNumInterno($codClient);

echo json_encode($result);


?>