<?php
/**
 * CashflowController.php
 * Controlador principal para operaciones del Cashflow
 */

require_once __DIR__ . '/../../class/conexion.php';

class CashflowController {
    
    private $conexion;
    
    public function __construct() {
        $this->conexion = new Conexion();
    }
    
    /**
     * Obtiene la conexión a la base de datos central
     * @return resource Conexión SQL Server
     */
    protected function getConexionCentral() {
        return $this->conexion->conectar('central');
    }
    
    /**
     * Obtiene la conexión a la base de datos de apps
     * @return resource Conexión SQL Server
     */
    protected function getConexionApps() {
        return $this->conexion->conectar('apps');
    }
    
    /**
     * Ejecuta una consulta y retorna los resultados como array
     * @param string $sql Query SQL
     * @param resource $conn Conexión
     * @param array $params Parámetros opcionales
     * @return array Resultados
     */
    protected function executeQuery($sql, $conn, $params = []) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            return ['error' => sqlsrv_errors()];
        }
        
        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $results;
    }
    
    /**
     * Formatea un valor como moneda
     * @param float $value Valor
     * @param string $currency Moneda (ARS, USD)
     * @return string Valor formateado
     */
    public static function formatCurrency($value, $currency = 'ARS') {
        $symbol = ($currency === 'USD') ? 'U$S ' : '$ ';
        return $symbol . number_format($value, 2, ',', '.');
    }
    
    /**
     * Formatea una fecha
     * @param mixed $date Fecha
     * @param string $format Formato de salida
     * @return string Fecha formateada
     */
    public static function formatDate($date, $format = 'd/m/Y') {
        if ($date instanceof DateTime) {
            return $date->format($format);
        }
        return date($format, strtotime($date));
    }
}
