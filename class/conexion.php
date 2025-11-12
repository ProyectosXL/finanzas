<?php

class Conexion{
    
    function __construct(){

        require_once(__DIR__.'/classEnv.php');

        $vars = new DotEnv(__DIR__ . '/../.env');
        $this->envVars = $vars->listVars();
        
        $this->host_central = $this->envVars['HOST_CENTRAL'];
        $this->database_central = $this->envVars['DATABASE_CENTRAL'];
        $this->host_locales = $this->envVars['HOST_LOCALES'];
        $this->host_apps = $this->envVars['HOST_APPS'];
        $this->database_locales = $this->envVars['DATABASE_LOCALES'];
        $this->database_tangobis = $this->envVars['DATABASE_TANGOBIS'];
        $this->database_apps = $this->envVars['DATABASE_APPS'];
        $this->user = $this->envVars['USER'];
        $this->pass = $this->envVars['PASS'];
        $this->pass_locales = $this->envVars['PASS_LOCALES'];
        $this->character = $this->envVars['CHARACTER'];
        $this->env = $this->envVars['ENV'];
        $this->prefix = ($this->env == 'DEV') ? '[LAKERBIS].locales_lakers.dbo.' : '';
        $this->database_uy = $this->envVars['DATABASE_UY'];
        $this->database_sucUy = $this->envVars['DATABASE_SUC_UY'];

    }

    private function servidor($nameServer) {
        
        if($nameServer == 'central'){
            return array($this->host_central, $this->database_central);
        }elseif($nameServer == 'locales'){
            return array($this->host_locales, $this->database_locales);
        }elseif($nameServer == 'tangoBis'){
            return array($this->host_locales, $this->database_tangobis);
        }elseif($nameServer == 'uy'){
            return array($this->host_central, $this->database_uy);
        }elseif($nameServer == 'suc_uy'){
            return array($this->host_locales, $this->database_sucUy);
        }elseif($nameServer == 'apps'){
            return array($this->host_apps, $this->database_apps);
        }else{
            return array($_SESSION['conexion_dns'], $_SESSION['base_nombre']);
        }

    }

    public function setearDnsBaseName($nroSucursal) {

        $sql = "SELECT CONEXION_DNS, BASE_NOMBRE 
                FROM [LAKERBIS].locales_lakers.dbo.SUCURSALES_LAKERS 
                WHERE NRO_SUC_MADRE IS NULL 
                AND NRO_SUCURSAL = ?";
    
     
        $conn = $this->conectar('central');
    
        if (!$conn) {
            die("Error de conexión: " . print_r(sqlsrv_errors(), true));
        }
    
      
        $params = array($nroSucursal);;
        $stmt = sqlsrv_query($conn, $sql, $params);
    
        if (!$stmt) {
            die("Error en la consulta: " . print_r(sqlsrv_errors(), true));
        }
    
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $_SESSION['conexion_dns'] = $row['CONEXION_DNS'];
            $_SESSION['base_nombre'] = $row['BASE_NOMBRE'];
            return true;
        } else {
            return false;
        }
    }

    public function conectar($nameServer = null) {
        try {

            $serverDB = $this->servidor($nameServer);

            $pass = $this->pass;
            // $pass = ($nameServer == 'locales') ? $this->pass_locales : $this->pass;

            if($nameServer == 'locales' || $nameServer == 'tangoBis' || $nameServer == "suc_uy"){

                $pass = $this->pass_locales;
                
            }
            
            $params = array( 
                "Database" => $serverDB[1], 
                "UID" => $this->user, 
                "PWD" => $pass, 
                "CharacterSet" => $this->character
            );

            $cid = sqlsrv_connect($serverDB[0], $params);

            return $cid;
            
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
    }

    private function buscarLocal($nameLocal){

        $prefix = ($this->env == 'DEV') ? '[LAKERBIS].locales_lakers.dbo.' : '';

        if($this->env == 'DEV'){
            $database = $this->database_central;
            $pass = $this->pass;
        } else {
            $database = $this->database_locales;
            $pass = $this->pass_locales;
        }

        $sql = "select * from ".$prefix."sucursales_lakers where cod_client = '$nameLocal'";

        $params = array( 
            "Database" => $database, 
            "UID" => $this->user, 
            "PWD" => $pass, 
            "CharacterSet" => $this->character
        );

        $cid = sqlsrv_connect($this->host_central, $params);

        $stmt = sqlsrv_query($cid, $sql);

        try {

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                $v[] = $row;

            }
    
            return $v[0];

        } catch (\Throwable $th) {

            print_r($th);

        }
    }
    
}
