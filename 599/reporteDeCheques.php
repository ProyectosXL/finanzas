<?php
// session_start(); 
// if(!isset($_GET['userName'])){
// 	header("Location:http://192.168.0.13:8000/");
// }else{
    include_once "controller/traerEquis.php";


    $inputBuscar = isset($_GET['inputBuscar']) ? $_GET['inputBuscar'] : '%' ;
    $selectEstado = isset($_GET['selectEstado']) ?  $_GET['selectEstado'] : '%';


    if(isset($_GET['desde']) &&$_GET['desde'] != "" ){
        $desde = $_GET['desde'];
    }else{
        $desde = date("Y-m-d");
    }
    if(isset($_GET['hasta']) &&$_GET['hasta'] != "" ){
        $hasta = $_GET['hasta'];
    }else{
        $hasta = date('Y-m-d',strtotime("+1 days"));
    }



    $cheques = traerReporteDeCheques($inputBuscar, $selectEstado, $desde, $hasta);

?>

<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reporte de Cheques</title>
        <link rel="icon" type="image/jpeg" href="../images/logo.jpg">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/reporteDeCheques.css">
    </head>

    <body>

        <div class="page-wrapper">
            <div class="main-card">
                <div class="card-header-section">
                    <i class="bi bi-bank"></i>
                    <h3>Reporte de Cheques Recibidos</h3>
                </div>

                <form action="#" method="get">
                    <div class="filter-section">
                        <div class="filter-grid">
                            <div class="filter-item">
                                <label>Rango de Fechas</label>
                                <div class="date-range">
                                    <input type="date" id="desde" name="desde" value="<?php echo $desde; ?>">
                                    <span class="date-separator">→</span>
                                    <input type="date" id="hasta" name="hasta" value="<?= $hasta ?>">
                                </div>
                            </div>

                            <div class="filter-item">
                                <label>Estado</label>
                                <select name="selectEstado" id="selectEstado">
                                    <option value="%" <?= $selectEstado == '%' ? 'selected' : '' ?>>Todos</option>
                                    <option value="0" <?= $selectEstado == '0' ? 'selected' : '' ?>>A Rendir</option>
                                    <option value="1" <?= $selectEstado == '1' ? 'selected' : '' ?>>Rendido</option>
                                </select>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn-modern btn-primary-modern">
                                <i class="bi bi-funnel-fill"></i>
                                Filtrar
                            </button>
                        </div>
                    </div>
                </form>

                <div class="actions-section">
                    <button class="btn-modern btn-success-modern" id="btnExport">
                        <i class="bi bi-file-earmark-excel"></i>
                        Exportar
                    </button>
                </div>

                <div class="table-container">
                    <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                        <thead class="thead-dark">
                            <tr>
                                <th>FECHA COBRO</th>
                                <th>NRO. INTERNO</th>
                                <th>CLIENTE</th>
                                <th>BANCO EMISOR</th>
                                <th>IMPORTE</th>
                                <th>NRO. CHEQUE</th>
                                <th>FECHA VENCIMIENTO</th>
                                <th>RENDIDO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cheques as $key => $value) { ?>
                                <tr>
                                    <td><?php echo $value['fecha_cobro']->format('Y-m-d') ?></td>  
                                    <td><?php echo $value['id'] ?></td>
                                    <td><?php echo $value['nombre_cliente'] ?></td>
                                    <td><?php echo $value['NOMBRE_BANCO'] ?></td>
                                    <td>$ <?php echo number_format($value['monto'], 0, ',', '.') ?></td>  
                                    <td><?php echo $value['num_cheque'] ?></td>  
                                    <td><?php echo $value['fecha_cheque']->format('Y-m-d') ?></td>  
                                    <td>
                                        <?php if($value['rendido'] == 1) { ?>
                                            <i class="bi bi-check-circle-fill status-icon check"></i>
                                        <?php } ?>
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
        <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
        <script src="js/jquery.table2excel.js"></script>

    </body>

</html>

<script>

    $(document).ready(function() {
        $('#myTable').DataTable({
            responsive: true,
            buttons: ['copy', 'csv', 'excel', 'pdf', 'print'],
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

    $("#btnExport").click(function(e) {
        e.preventDefault();
        
        $('input[type=number]').each(function(){
            this.setAttribute('value', $(this).val());
        });

        $("table").table2excel({
            exclude: ".noExl",
            name: "Reporte de Cheques",
            filename: "ReporteCheques_" + new Date().toISOString().slice(0,10),
            fileext: ".xls"
        });
    });

</script>
<!-- <?php
// }
?> -->