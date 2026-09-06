<?php
if (!class_exists('Conexion')) {
    class Conexion
    {

        private $envVars;
        private $host_central;
        private $database_central;
        private $database_uy;
        private $database_tangobis;
        private $database_suc_uy;
        private $host_apps;
        private $database_power;
        private $database_power_franquicias;
        private $database_power_uy;
        private $database_apps;
        private $host_locales;
        private $database_locales;
        private $user;
        private $pass;
        private $pass_locales;
        private $character;
        private $env;
        private $prefix;

        function __construct()
        {
            require_once(__DIR__ . '/classEnv.php');

            $vars = new DotEnv(__DIR__ . '/../../.env');
            $this->envVars = $vars->listVars();

            $this->host_central = $this->envVars['HOST_CENTRAL'];
            $this->database_central = $this->envVars['DATABASE_CENTRAL'];
            $this->database_uy = $this->envVars['DATABASE_UY'];
            $this->database_tangobis = $this->envVars['DATABASE_TANGOBIS'];
            $this->database_suc_uy = $this->envVars['DATABASE_SUC_UY'];
            $this->host_apps = $this->envVars['HOST_APPS'];
            $this->database_power = $this->envVars['DATABASE_POWER'];
            $this->database_power_franquicias = $this->envVars['DATABASE_POWER_FRANQUICIAS'];
            $this->database_power_uy = $this->envVars['DATABASE_POWER_UY'];
            $this->database_apps = $this->envVars['DATABASE_APPS'];
            $this->host_locales = $this->envVars['HOST_LOCALES'];
            $this->database_locales = $this->envVars['DATABASE_LOCALES'];
            $this->user = $this->envVars['USER'];
            $this->pass = $this->envVars['PASS'];
            $this->pass_locales = $this->envVars['PASS_LOCALES'];
            $this->character = $this->envVars['CHARACTER'];
            $this->env = $this->envVars['ENV'];
            $this->prefix = ($this->env == 'DEV') ? '[XL-LAKERBIS].locales_lakers.dbo.' : '';
        }

        private function servidor($nameServer)
        {

            if ($nameServer == 'central') {
                return array($this->host_central, $this->database_central);
            } elseif ($nameServer == 'locales') {
                return array($this->host_locales, $this->database_locales);
            } elseif ($nameServer == 'uy') {
                return array($this->host_central, $this->database_uy);
            } elseif ($nameServer == 'tangobis') {
                return array($this->host_central, $this->database_tangobis);
            } elseif ($nameServer == 'suc_uy') {
                return array($this->host_central, $this->database_suc_uy);
            } elseif ($nameServer == 'apps') {
                return array($this->host_apps, $this->database_apps);
            } elseif ($nameServer == 'power') {
                return array($this->host_apps, $this->database_power);
            } elseif ($nameServer == 'power_uy') {
                return array($this->host_apps, $this->database_power_uy);
            } elseif ($nameServer == 'power_franquicias') {
                return array($this->host_apps, $this->database_power_franquicias);
            } else {
                // Solo usar sesión si no se especifica servidor o si se especifica uno no reconocido
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                // Verificar que las variables de sesión existen
                if (!isset($_SESSION['conexion_dns']) || !isset($_SESSION['base_nombre'])) {
                    // Si no existen, usar central por defecto
                    error_log("Variables de sesión no definidas, usando central por defecto");
                    return array($this->host_central, $this->database_central);
                }

                return array($_SESSION['conexion_dns'], $_SESSION['base_nombre']);
            }

        }

        public function conectar($nameServer = null)
        {
            try {

                $serverDB = $this->servidor($nameServer);

                error_log("Intentando conectar a: " . $serverDB[0] . " - " . $serverDB[1]);

                if ($this->env == 'PROD' && (strtolower($serverDB[0]) == strtolower('XL-LAKERBIS'))) {
                    $pass = $this->pass_locales;
                } else {
                    $pass = $this->pass;
                }

                $params = array(
                    "Database" => $serverDB[1],
                    "UID" => $this->user,
                    "PWD" => $pass,
                    "CharacterSet" => $this->character,
                    "LoginTimeout" => 10
                );

                // Log de parámetros de conexión (sin contraseña)
                $params_log = $params;
                $params_log['PWD'] = '***hidden***';
                error_log("Parámetros de conexión: " . print_r($params_log, true));

                $cid = sqlsrv_connect($serverDB[0], $params);

                if (!$cid) {
                    $errors = sqlsrv_errors();
                    error_log("Error de conexión SQL Server: " . print_r($errors, true));
                    return false;
                }

                error_log("Conexión exitosa a: " . $serverDB[0] . " - " . $serverDB[1]);

                // Iniciar sesión si no está iniciada
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                // este cid va a cambiar mil veces
                $_SESSION['cid'] = $cid;
                return $cid;

            } catch (PDOException $e) {
                echo $e->getMessage();
            }
        }

        private function buscarLocal($nameLocal)
        {

            $prefix = ($this->env == 'DEV') ? '[XL-LAKERBIS].locales_lakers.dbo.' : '';

            if ($this->env == 'DEV') {
                $database = $this->database_central;
                $pass = $this->pass;
            } else {
                $database = $this->database_locales;
                $pass = $this->pass_locales;
            }

            $sql = "select * from " . $prefix . " sucursales_lakers where cod_client = '$nameLocal'";

            $params = array(
                "Database" => $this->database_central,
                "UID" => $this->user,
                "PWD" => $this->pass,
                "CharacterSet" => $this->character
            );

            $cid = sqlsrv_connect($this->host_central, $params);

            $stmt = sqlsrv_query($cid, $sql);

            try {

                // $next_result = sqlsrv_next_result($stmt);

                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

                    $v[] = $row;

                }

                return $v[0];

            } catch (\Throwable $th) {

                print_r($th);

            }
        }
    }
}
