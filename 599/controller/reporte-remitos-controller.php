
<?php
// /599/reporte/controller/reporte-remitos-controller.php

require_once __DIR__ . '/../class/ReporteRemitos.php';

class ReporteRemitosController {
    
    private $reporteRemitos;
    
    public function __construct() {
        $this->reporteRemitos = new ReporteRemitos();
    }
    
    public function obtenerDatos() {
        try {
            return $this->reporteRemitos->obtenerDatosReporte();
        } catch (Exception $e) {
            error_log("Error en obtenerDatos: " . $e->getMessage());
            return [];
        }
    }
    
    public function obtenerDetalle($mesDesde = null, $anioDesde = null, $mesHasta = null, $anioHasta = null) {
        try {
            return $this->reporteRemitos->obtenerDetalleRemitos($mesDesde, $anioDesde, $mesHasta, $anioHasta);
        } catch (Exception $e) {
            error_log("Error en obtenerDetalle: " . $e->getMessage());
            return [];
        }
    }
}