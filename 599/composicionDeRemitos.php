<?php
// session_start(); 
// if(!isset($_GET['userName'])){
// 	header("Location:http://192.168.0.13:8000/");
// }else{
    $userName = isset($_GET['userName']) ? $_GET['userName'] : "";
    include_once "controller/traerEquis.php";
    include_once "controller/ejecutarSpController.php";

   cargarEquisTable();
    
    $todosLosRemitos = traerTodos();


    $newArray = [];
    $remitoActual = "";
    $valores = traerEfectivoCheque();

    $totalEfectivo = 0;
    $totalCheque = 0;

    foreach ($valores as  $value) {
        $totalEfectivo = $totalEfectivo + $value['importe_efectivo'];
        $totalCheque = $totalCheque + $value['importe_cheque'];
    } 

    foreach ($todosLosRemitos as $remito => $value) {
        $totalDeuda = 0;
        $totalCobrado = 0;

    
        
        if($remitoActual != $value['COD_PRO_CL']){

            foreach ($todosLosRemitos as $val ) {

                if($value['COD_PRO_CL'] == $val['COD_PRO_CL'] ){

                    if ($val['CHEQUEADO'] == 1 ){
                        if($val['importe_total'] != null){
                            $totalCobrado = $totalCobrado + $val['importe_total'];
                        }else{
                            $totalCobrado = $totalCobrado + $val['IMPORTE_TO'];
                        }
                    }else{
                        $totalDeuda = $totalDeuda + $val['IMPORTE_TO'];
                    }
                    
                }
            }
            
            $remitoActual = $value['COD_PRO_CL'];
            $newArray[$remito]['totalCobrado']=$totalCobrado;
            $newArray[$remito]['totalDeuda']=$totalDeuda;
            $newArray[$remito]['nombreCliente']=$value['RAZON_SOCI'];
            $newArray[$remito]['codCliente']=$value['COD_PRO_CL'];
        }

    }


    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Composicion De Remitos</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/composicionDeRemitos.css">

    </head>

    <body>

        <div class="page-wrapper">
            <div class="main-card">
                <div class="card-header-section">
                    <i class="bi bi-cash"></i>
                    <h3>Composición de saldo a cobrar</h3>
                </div>

                <div id="user" hidden><?=$userName; ?></div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Total Deuda</div>
                            <div class="stat-card-value" id="sumValorDeuda">$0</div>
                        </div>
                    </div>
                    <div class="stat-card efectivo">
                        <div class="stat-icon">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Efectivo</div>
                            <div class="stat-card-value"><?= '$'.number_format($totalEfectivo, 0, ',', '.')?></div>
                        </div>
                    </div>
                    <div class="stat-card cheque">
                        <div class="stat-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-card-header">Cheques</div>
                            <div class="stat-card-value"><?= '$'.number_format($totalCheque, 0, ',', '.')?></div>
                        </div>
                    </div>
                </div>

                <div class="actions-section">
                    <button class="btn-modern btn-success-modern btn_exportar" id="btnExport">
                        <i class="bi bi-file-earmark-excel"></i>
                        Exportar
                    </button>
                </div>
        
                <div class="table-container">
                    <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                            <thead class="thead-dark">
                                <tr>
                                    <th>CLIENTE</th>
                                    <th>A COBRAR</th>
                                    <th>COBRADO</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php 
                                        foreach($newArray as $b){
                                            echo "<tr>";
                                            echo "<td style='text-align:center' attr-codClient='".$b['codCliente']."'>".$b['nombreCliente']."</td>";
                                            echo "<td style='text-align:center;' id='colDeuda' attr-realValue=".$b['totalDeuda'].">".$b['totalDeuda']."</td>";
                                            echo "<td style='text-align:center' id='colCobrado'>".$b['totalCobrado']."</td>";
                                            if($b['totalDeuda'] > 0){

                                                echo "<td style='text-align:center'><button class='btn-edit' onclick='verDetalle(this)'><i class='bi bi-pencil-square'></i> Ver</button></td>";
                                            }else{
                                                echo "<td style='text-align:center'></td>";

                                            }
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

    </body>

    </html>
    <script src="js/jquery.table2excel.js"></script>
    <script src="js/composicionDeRemitos.js"></script>

    <script>

            $(document).ready(function() {
                // Primero calcular el total antes de formatear
                const totalDeudas = document.querySelectorAll("#colDeuda");
                let valorTotalDeudas = 0;
                
                totalDeudas.forEach(e => {
                    const valor = parseInt(e.getAttribute("attr-realValue"));
                    if (!isNaN(valor)) {
                        valorTotalDeudas += valor;
                    }
                });

                // Actualizar el total de deuda
                const elementoTotal = document.querySelector("#sumValorDeuda");
                if (elementoTotal) {
                    elementoTotal.textContent = "$ " + valorTotalDeudas.toLocaleString('es-AR', {
                        style: 'decimal',
                        maximumFractionDigits: 0,
                        minimumFractionDigits: 0
                    });
                }

                // Luego formatear los números de la tabla
                parseNumber();

                // Finalmente inicializar DataTable
                $('#myTable').DataTable({
                    responsive: true,
                    buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
                });
            });

    </script>
<!-- <?php
// }
?> -->