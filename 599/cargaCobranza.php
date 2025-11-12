<?php
// session_start(); 
// if(!isset($_GET['userName'])){
// 	header("Location:http://192.168.0.13:8000/");
// }else{
    include_once "controller/traerEquis.php";
    $detalleRemito = traerDetalle($_GET['codCliente']);
    $cliente = $detalleRemito[0]['RAZON_SOCI'];

    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Carga de Cobranza</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.5.2/css/bootstrap.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css" class="rel">
        <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css" class="rel">

        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        

        </link>

    </head>

    <body>

        <div class="alert alert-secondary">
            <div class="page-wrapper bg-secondary p-b-100 pt-2 font-robo">
                <div class="wrapper wrapper--w680"><div style="color:white; text-align:center"><h6>Carga de Cobranza</h6></div>
                    <div class="card card-1">
                        <div id="user" hidden><?= isset($_GET['userName']) ? $_GET['userName'] : "" ?></div>
                        <div class="row" style="margin-left:50px">
                            <h3><i class="bi bi-cash" style="margin-right:20px;font-size:50px"></i>Carga de Cobranza</h3>
                        </div>
                        <div class="row" style="margin-left:50px; margin-top: 1rem; margin-bottom: 1rem;">
                            <div class="col-4" id="cliente" attr-cliente="<?= $cliente?>"><strong>Cliente : </strong ><?= $cliente?></div>
                        </div>
                        <div class="row" style="margin-left:60px;margin-top">
                            <div><strong>Monto a Cobrar: <input class="form-control" type="text"  value ="$<?= number_format($_GET['importeAbonar'], 0, ',', '.') ?>" readonly id="montoACobrar" attr-valorReal="<?= $_GET['importeAbonar'] ?>"></strong></div>
                            <div hidden id="valorDescontado"><?= $_GET['valorDescontado']?></div>
                        </div>
                        <div class="row" style="margin-left:60px;margin-top:20px">
                            <div><strong>Cobro Efectivo: <input class="form-control" type="text" id="cobroEfectivo" onchange="setearValores()"></strong></div>
                        </div>
                        <div class="row" style="margin-left:60px;margin-top:20px">
                            <div><strong>Cobro Cheque: <input class="form-control" type="text" id="cobroCheque" readonly></strong></div>
                            <div style="margin-top: 1.5rem; margin-left: 0.5rem"><button class="btn btn-success" value="" data-toggle="modal" data-target="#exampleModal" onclick="completarModal('<?= $_GET['codCliente'] ?>')" id="botonCheques"><i class="bi bi-plus-square"></i></button><button class="btn btn-primary" value="" data-toggle="modal" data-target="#editarChequeModal" onclick="updateChequesModal()" id="botonEditarCheques" hidden><i class="bi bi-pencil-square"></i></button></div>
                            <div hidden id="idCheque"></div>
                        </div>
                        <div class="row" style="margin-left:60px;margin-top:20px; margin-bottom: 0.5rem;">
                            <div><strong>Saldo a Cobrar: <input class="form-control" type="text"  readonly id="saldoCobrar"></strong></div>
                        </div>
                        <div class="row">
                            <div style="margin-left: 20rem; margin-bottom: 2rem;"><button class="btn btn-primary" id="btnExport" onclick="confirmarCobro('<?= $_GET['codCliente'] ?>')"><i class="fa fa-file-excel-o"></i>Confirmar Cobro <i class="bi bi-check-square"></i></i></button></div>
                        </div>
                </div>
            </div>



            </table>
        <div class="modal fade  bd-example-modal-xl " id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">Carga de Cheques</h5>
                    </div>
                    <div class="modal-body">
                        <div class="row" style="margin-left:10px">Saldo a cobrar : <input class="form-control" type="text" style="width:100px;height:30px;margin-left:10px" readonly id="modalSaldo"></div>

                        <div style="margin-top:10px">
                                
                            <table class="table table-striped table-bordered" id="myTable" style="width: 99%;" cellspacing="0" data-page-length="100">
                                <thead class="thead-dark">
                                    <tr>
                                        <th style="text-align:center;" class="col-md-1">NRO.INTERNO</th>
                                        <th  class="col-md-1">BANCO EMISOR</th>
                                        <th  class="col-md-1">MONTO</th>
                                        <th  class="col-md-1">NRO.CHEQUE</th>
                                        <th  class="col-md-1">FECHA COBRO</th>
                                        <th  class="col-md-1" ></th>
                                    </tr>
                                </thead>
                                <tbody id="tableCheques">
                    
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Cancelar<i class="bi bi-x"></i></button>
                        <button type="button" class="btn btn-primary"  data-dismiss="modal" data-toggle="modal" onclick="registrarCheque('<?= $_GET['codCliente'] ?>')">Cargar <i class="bi bi-check-square"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade  bd-example-modal-xl " id="editarChequeModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">Carga de Cheques</h5>
                    </div>
                    <div class="modal-body">
                        <div class="row" style="margin-left:10px">Saldo a cobrar : <input class="form-control" type="text" style="width:100px;height:30px;margin-left:10px" readonly id="modalUpdateChequesSaldo"></div>

                        <div style="margin-top:10px">
                                
                            <table class="table table-striped table-bordered" id="myTable" style="width: 99%;" cellspacing="0" data-page-length="100">
                                <thead class="thead-dark">
                                    <tr>
                                        <th style="text-align:center;" class="col-md-1">NRO.INTERNO</th>
                                        <th  class="col-md-1">BANCO EMISOR</th>
                                        <th  class="col-md-1">MONTO</th>
                                        <th  class="col-md-1">NRO.CHEQUE</th>
                                        <th  class="col-md-1">FECHA COBRO</th>
                                    </tr>
                                </thead>
                                <tbody id="editarChequeTable">
                        
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Cancelar<i class="bi bi-x"></i></button>
                        <button type="button" class="btn btn-primary"  data-dismiss="modal" data-toggle="modal" onclick="updateCheque()">Cargar <i class="bi bi-check-square"></i></button>
                    </div>
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
        <script src="js/cargaCobranza.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    </body>

    </html>
    <script>

            $(document).ready(function() {
                let monto = document.querySelector("#montoACobrar");
                
                let newMonto = monto.getAttribute("attr-valorreal")
                newMonto = parseFloat(newMonto)
                
                newMonto = newMonto.toLocaleString('de-De', {
                    style: 'decimal',
                    maximumFractionDigits: 0,
                    minimumFractionDigits: 0
                }); 
            
                monto.value ="$" +newMonto
            });
            const setearSelect2 = ()=>{
                $(document).ready(function() {
                    $('.banco').select2();
                });
            };

            
        
    
    </script>
<!-- <?php
// }
?> -->