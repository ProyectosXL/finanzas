<?php
include_once __DIR__."/../class/remitoEquis.php";

$dataDelCheque = $_POST;

$remitoEquis = new RemitoEquis();

$remitoEquis->actualizarCheque($dataDelCheque);

echo true;    


?>