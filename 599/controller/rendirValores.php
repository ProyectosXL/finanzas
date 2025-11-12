<?php 
include_once __DIR__."/../class/remitoEquis.php";

$remitoEquis = new RemitoEquis();

$cobros = $_POST['cobros'];
$userName = $_POST['userName'];

foreach ($cobros as $cobro) {
    $remitoEquis->rendirCobro($cobro,$userName);

}

return true;

?>