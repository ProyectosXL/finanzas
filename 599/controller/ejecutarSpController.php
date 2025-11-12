<?php

function cargarEquisTable(){
    try {
        
        require_once __DIR__.'/../../class/conexion.php';
        $cid = new Conexion();
        $cid_central = $cid->conectar('central');
        
        $sql = "EXEC SJ_EQUIS_SP";
        
        $stmt = sqlsrv_query($cid_central, $sql);
    

    } catch (Exception $e) {
        echo 'Excepción capturada: ',  $e->getMessage(), "\n";
    }
}

?>