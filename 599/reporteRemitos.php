
<?php
// /599/reporte/index.php

require_once __DIR__ . '/../class/conexion.php';
require_once __DIR__ . '/controller/reporte-remitos-controller.php';

$reporteController = new ReporteRemitosController();
$datos = $reporteController->obtenerDatos();

// Mapeo de nombres de meses en español
$mesesEspanol = [
    'January' => 'Enero',
    'February' => 'Febrero',
    'March' => 'Marzo',
    'April' => 'Abril',
    'May' => 'Mayo',
    'June' => 'Junio',
    'July' => 'Julio',
    'August' => 'Agosto',
    'September' => 'Septiembre',
    'October' => 'Octubre',
    'November' => 'Noviembre',
    'December' => 'Diciembre'
];

// Convertir nombres de meses a español
foreach ($datos as &$registro) {
    if (isset($registro['mes_nombre']) && isset($mesesEspanol[$registro['mes_nombre']])) {
        $registro['mes_nombre'] = $mesesEspanol[$registro['mes_nombre']];
    }
}
unset($registro);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Remitos</title>
    <link rel="icon" type="image/jpeg" href="../../images/logo.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/reporte.css">
</head>
<body>

<div class="container-fluid p-4">
    <div class="main-card">
        <div class="card-header-section">
            <i class="bi bi-graph-up-arrow"></i>
            <h3>Reporte de Remitos</h3>
        </div>

        <!-- Filtros -->
        <div class="filters-section">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Desde</label>
                    <div class="input-group">
                        <select class="form-select" id="mesDesde">
                            <option value="1">Enero</option>
                            <option value="2">Febrero</option>
                            <option value="3">Marzo</option>
                            <option value="4">Abril</option>
                            <option value="5">Mayo</option>
                            <option value="6">Junio</option>
                            <option value="7">Julio</option>
                            <option value="8">Agosto</option>
                            <option value="9">Septiembre</option>
                            <option value="10">Octubre</option>
                            <option value="11">Noviembre</option>
                            <option value="12">Diciembre</option>
                        </select>
                        <input type="number" class="form-control" id="anioDesde" placeholder="Año" min="2020" max="2030" value="<?php echo date('Y') - 1; ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Hasta</label>
                    <div class="input-group">
                        <select class="form-select" id="mesHasta">
                            <option value="1">Enero</option>
                            <option value="2">Febrero</option>
                            <option value="3">Marzo</option>
                            <option value="4">Abril</option>
                            <option value="5">Mayo</option>
                            <option value="6">Junio</option>
                            <option value="7">Julio</option>
                            <option value="8">Agosto</option>
                            <option value="9">Septiembre</option>
                            <option value="10">Octubre</option>
                            <option value="11">Noviembre</option>
                            <option value="12" selected>Diciembre</option>
                        </select>
                        <input type="number" class="form-control" id="anioHasta" placeholder="Año" min="2020" max="2030" value="<?php echo date('Y'); ?>">
                    </div>
                </div>
                <div class="col-md-6 d-flex align-items-end gap-2">
                    <button class="btn btn-dark" id="btnAplicarFiltros">
                        <i class="bi bi-funnel-fill"></i> Aplicar Filtros
                    </button>
                    <button class="btn btn-outline-dark" id="btnLimpiarFiltros">
                        <i class="bi bi-x-circle"></i> Limpiar
                    </button>
                    <button class="btn btn-outline-dark" id="btnExportar">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpis-section">
            <div class="row g-3">
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon venta">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">
                                Venta Total $
                                <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="Suma total de ventas en pesos"></i>
                            </div>
                            <div class="kpi-value" id="kpiVentaTotal">$0</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon remitos">
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">
                                Remitos Total $
                                <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="Suma total de remitos en pesos"></i>
                            </div>
                            <div class="kpi-value" id="kpiRemitosTotal">$0</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon porcentaje">
                            <i class="bi bi-percent"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">
                                % Total Remitido
                                <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="Porcentaje de remitos sobre ventas totales"></i>
                            </div>
                            <div class="kpi-value" id="kpiPorcentajeTotal">0%</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="kpi-card">
                        <div class="kpi-icon usd">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">
                                Venta Total remitos U$S
                                <i class="bi bi-info-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="Suma total de ventas en dólares"></i>
                            </div>
                            <div class="kpi-value" id="kpiVentaUSD">U$S 0</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla -->
        <div class="table-section">
            <div class="table-responsive">
                <table class="table table-hover" id="tablaReporte">
                    <thead>
                        <tr>
                            <th>Año</th>
                            <th>Mes</th>
                            <th>Imp. Venta $</th>
                            <th>Imp. Remitos $</th>
                            <th>% Remitos</th>
                            <th>Venta Remitos U$S</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyReporte">
                        <!-- Generado por JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/jquery.table2excel.js"></script>
<script>
    const datosReporte = <?php echo json_encode($datos); ?>;
</script>
<script src="js/reporte.js"></script>
</body>
</html>