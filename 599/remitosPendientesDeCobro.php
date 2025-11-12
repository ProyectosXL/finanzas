<?php
// session_start(); 
// if(!isset($_GET['userName'])){
// 	header("Location:http://192.168.0.13:8000/");
// }else{
    include_once "controller/traerEquis.php";
    $detalleDeRemito = traerDetalle($_GET['codClient']);

    ?>

    <!DOCTYPE html>
    <html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv='cache-control' content='no-cache'>
    <meta http-equiv='expires' content='0'>
    <meta http-equiv='pragma' content='no-cache'>
    <title>Remitos pendientes de cobro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/remitosPendientesDeCobro.css">

    </head>

    <body>

    <div class="page-wrapper">

        <div class="main-card">
            <div class="card-header-section">
                <i class="bi bi-cash"></i>
                <h3>Remitos pendientes de cobro</h3>
            </div>

            <div id="user" hidden><?= $_GET['userName'] ?></div>
            
            <div class="info-grid">
                <div class="info-item">
                    <label>Total deuda</label>
                    <input type="text" id="totalDeuda" readonly>
                </div>
                <div class="info-item" id="divImporteAabonar">
                    <label>Importe a abonar</label>
                    <input type="text" id="importeAbonar" readonly>
                </div>
                <div class="info-item" id="divImporteConDescuento">
                    <label>Importe con Descuento</label>
                    <input type="text" id="importeConDescuento" readonly>
                </div>
                <div class="info-item">
                    <label>% Descuento</label>
                    <input type="text" id="descuento" onchange="calcularDescuento()" placeholder="Colocar números enteros">
                </div>
                <div class="info-item actions-buttons">
                    <button class="btn-modern btn-primary-modern" value="" id="btnConfirmar">
                        <i class="bi bi-check-square"></i>
                        Confirmar
                    </button>
                </div>
                <div class="info-item actions-buttons">
                    <button class="btn-modern btn-success-modern btn_exportar" id="btnExport">
                        <i class="bi bi-file-earmark-excel"></i>
                        Exportar
                    </button>
                </div>
            </div>

            <div hidden id="codClient"><?= $_GET['codClient'] ?></div>
            
            <div class="client-info">
                <h5>Cliente: <span class="client-name"><?= $detalleDeRemito[0]['RAZON_SOCI'] ?></span></h5>
            </div>

            <div class="table-container">
                <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                        <thead class="thead-dark">
                            <tr>
                                <th>FECHA</th>
                                <th>CLIENTE</th>
                                <th>REMITO</th>
                                <th>MONTO</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($detalleDeRemito as $value) {
                                if ($value['CHEQUEADO'] == 1) {
                                    continue;
                                }
                                echo "<tr>";
                                echo "<td style='text-align:center' >" . $value['FECHA_MOV']->format('Y-m-d') . "</td>";
                                echo "<td style='text-align:center' >" . $value['RAZON_SOCI'] . "</td>";
                                echo "<td style='text-align:center' >" . $value['N_COMP'] . "</td>";
                                echo "<td style='text-align:center' id='monto' attr-realValue='" . $value['IMPORTE_TO'] . "'>" . $value['IMPORTE_TO'] . "</td>";
                                echo "<td style='text-align:center;width:20px' ><input type='checkbox' style='width:20px' onchange='checkMonto()' ></td>";
                                echo "</tr>";
                            }

                            ?>

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
            <script src="js/remitosPendientesDeCobro.js?version=1.0"></script>
            <script src="js/jquery.table2excel.js"></script>

    </body>

    </html>

    <script>
        
        const todosLosMontos = document.querySelectorAll('#monto');
        
        
        $(document).ready(function() {
                parseNumber();
                $('#myTable').DataTable({
                    responsive: true,
                    buttons: [
                        'copy', 'csv', 'excel', 'pdf', 'print'
                    ]
                });

                let total = 0;

                todosLosMontos.forEach(e => {
                    const valor = parseInt(e.getAttribute("attr-realValue"));
                    if (!isNaN(valor)) {
                        total += valor;
                    }
                }); 
                
                document.querySelector("#totalDeuda").value = "$ " + total.toLocaleString('es-AR', {
                    style: 'decimal',
                    maximumFractionDigits: 0,
                    minimumFractionDigits: 0
                });
                
        });
            
        $("#btnExport").click(function() {

            $('input[type=number]').each(function(){
                this.setAttribute('value',$(this).val());
            });

            $("table").table2excel({
                // exclude CSS class
                exclude: ".noExl",
                name: "Worksheet Name",
                filename: "Remitos", //do not include extension
                fileext: ".xls", // file extension
            });
        });
    </script>
<!-- <?php
// }
?> -->
