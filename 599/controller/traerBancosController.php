<?php
include_once __DIR__."/../class/remitoEquis.php";



$remitoEquis = new RemitoEquis();

$result = $remitoEquis->traerBancos();

echo json_encode($result);


?>