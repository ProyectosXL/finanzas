<?php 
/*session_start(); 
if(!isset($_GET['userName'])){
	header("Location:http://192.168.0.13:8000/");
}else{*/
    include_once "controller/traerEquis.php";
    $cheques = traerCheques();
    
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Valores A Rendir</title>
        <link rel="icon" type="image/jpeg" href="../images/logo.jpg">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/valoresRendir.css">
    </head>

    <body>

        <div class="page-wrapper">
            <div class="main-card">
                <div class="card-header-section">
                    <i class="bi bi-cash"></i>
                    <h3>Valores A Rendir</h3>
                </div>

                <div class="stats-grid">
                    <div class="stat-card efectivo">
                        <div class="stat-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Total Efectivo</div>
                            <div class="stat-card-value" id="totalEfectivo">$0</div>
                        </div>
                    </div>
                    <div class="stat-card cheque">
                        <div class="stat-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Total Cheques</div>
                            <div class="stat-card-value" id="totalCheque">$0</div>
                        </div>
                    </div>
                    <div class="stat-card dolar">
                        <div class="stat-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Total Dólares</div>
                            <div class="stat-card-value" id="totalDolares">U$S 0</div>
                        </div>
                    </div>
                </div>

                <div class="actions-section">
                    <button class="btn-modern btn-success-modern" id="btnExport" onclick="rendir()">
                        <i class="bi bi-check2-circle"></i>
                        Rendir
                    </button>
                </div>

                <div class="table-container">
                    <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                        <thead class="thead-dark">
                            <tr>
                                <th>CLIENTE</th>
                                <th>MONTO A COBRAR</th>
                                <th>MONTO COBRADO</th>
                                <th>EFECTIVO</th>
                                <th>CHEQUES</th>
                                <th>U$S</th>
                                <th>COTIZACIÓN</th>
                                <th>SELECCIONAR</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cheques as $key => $value) {
                                $descuento = isset($descuento) ? $descuento : 0;
                                $importe = $value['IMPORTE_TO'] - $descuento;
                            ?>
                                <tr data-id-cobro="<?php echo $value['ID'] ?>" data-username="<?= isset($_GET['userName']) ? $_GET['userName'] : '' ?>">
                                    <td><?php echo $value['nombre_cliente'] ?></td>
                                    <td>$<?php echo number_format($value['IMPORTE_TO'], 0, ',', '.') ?></td>
                                    <td>$<?php echo number_format($value['importe_total'], 0, ',', '.') ?></td>
                                    <td class="importeEfectivo"><?php echo $value['importe_efectivo'] ?></td>
                                    <td class="importeCheque"><?php echo $value['importe_cheque'] ?></td>
                                    <td class="importeDolares"><?php echo $value['importe_dolares'] > 0 ? $value['importe_dolares'] : 0 ?></td>
                                    <td class="cotizacionDolar"><?php echo $value['cotizacion_dolar'] > 0 ? number_format($value['cotizacion_dolar'], 2, ',', '.') : '—' ?></td>
                                    <td>
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="a" class="checkCalcularTotales modern-checkbox" onchange="calcularTotales(this)">
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="https://code.jquery.com/jquery-3.5.1.js"></script>
        <script src="https://cdn.datatables.net/1.12.1/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.12.1/js/dataTables.bootstrap4.min.js"></script>
        <script src="https://cdn.datatables.net/responsive/2.3.0/js/dataTables.responsive.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-Piv4xVNRyMGpqkS2by6br4gNJ7DXjqk09RmUpJ8jgGtD7zP9yug3goQfGII0yAns" crossorigin="anonymous"></script>
        <script src="js/valoresArendir.js"></script>

        <script>
            $(document).ready(function() {
                $('#myTable').DataTable({
                    responsive: true,
                    language: {
                        lengthMenu: "Mostrar _MENU_ registros por página",
                        zeroRecords: "No se encontraron resultados",
                        info: "Mostrando página _PAGE_ de _PAGES_",
                        infoEmpty: "No hay registros disponibles",
                        infoFiltered: "(filtrado de _MAX_ registros totales)",
                        search: "Buscar:",
                        paginate: {
                            first: "Primero",
                            last: "Último",
                            next: "Siguiente",
                            previous: "Anterior"
                        }
                    }
                });
            });
        </script>

    </body>

    </html>
<?php
//}
?>