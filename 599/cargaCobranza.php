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
        <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.dataTables.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.9.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <link rel="stylesheet" href="css/cargaCobranza.css">

    </head>

    <body>

        <div class="page-wrapper">
            <div class="main-card">
                <div class="card-header-section">
                    <i class="bi bi-cash-coin"></i>
                    <h3>Carga de Cobranza</h3>
                </div>

                <div id="user" hidden><?= isset($_GET['userName']) ? $_GET['userName'] : "" ?></div>
                
                <div class="client-section">
                    <strong>Cliente:</strong>
                    <span id="cliente" attr-cliente="<?= $cliente?>"><?= $cliente?></span>
                </div>

                <div class="form-grid">
                    <div class="form-item">
                        <label>
                            <i class="bi bi-currency-dollar"></i>
                            Monto a Cobrar
                        </label>
                        <input type="text" value="$<?= number_format($_GET['importeAbonar'], 0, ',', '.') ?>" readonly id="montoACobrar" attr-valorReal="<?= $_GET['importeAbonar'] ?>">
                        <div hidden id="valorDescontado"><?= $_GET['valorDescontado']?></div>
                    </div>

                    <div class="form-item">
                        <label>
                            <i class="bi bi-wallet2"></i>
                            Cobro Efectivo
                        </label>
                        <input type="text" id="cobroEfectivo" onchange="setearValores()" placeholder="Ingrese monto en efectivo">
                    </div>

                    <div class="form-item">
                        <label>
                            <i class="bi bi-credit-card"></i>
                            Cobro Cheque
                        </label>
                        <div class="input-with-button">
                            <input type="text" id="cobroCheque" readonly>
                            <button class="btn-icon btn-success" data-toggle="modal" data-target="#exampleModal" onclick="completarModal('<?= $_GET['codCliente'] ?>')" id="botonCheques" title="Agregar cheque">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                            <button class="btn-icon btn-primary" data-toggle="modal" data-target="#editarChequeModal" onclick="updateChequesModal()" id="botonEditarCheques" hidden title="Editar cheque">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <div hidden id="idCheque"></div>
                    </div>
                </div>

                <div class="saldo-section">
                    <div class="saldo-icon">
                        <i class="bi bi-calculator"></i>
                    </div>
                    <div class="saldo-content">
                        <label>Saldo a Cobrar</label>
                        <input type="text" readonly id="saldoCobrar">
                    </div>
                </div>

                <div class="actions-section">
                    <button class="btn-confirm" id="btnExport" onclick="confirmarCobro('<?= $_GET['codCliente'] ?>')">
                        <i class="bi bi-check-circle"></i>
                        Confirmar Cobro
                    </button>
                </div>
            </div>
        </div>
        <div class="modal fade bd-example-modal-xl" id="exampleModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">
                            <i class="bi bi-credit-card"></i>
                            Carga de Cheques
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="modal-saldo">
                            <strong>Saldo a cobrar:</strong>
                            <input type="text" readonly id="modalSaldo">
                        </div>

                        <div>
                            <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>NRO. INTERNO</th>
                                        <th>BANCO EMISOR</th>
                                        <th>MONTO</th>
                                        <th>NRO. CHEQUE</th>
                                        <th>FECHA COBRO</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="tableCheques">
                    
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">
                            <i class="bi bi-x-lg"></i>
                            Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="registrarCheque('<?= $_GET['codCliente'] ?>')">
                            <i class="bi bi-check-lg"></i>
                            Cargar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade bd-example-modal-xl" id="editarChequeModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">
                            <i class="bi bi-pencil-square"></i>
                            Editar Cheques
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="modal-saldo">
                            <strong>Saldo a cobrar:</strong>
                            <input type="text" readonly id="modalUpdateChequesSaldo">
                        </div>

                        <div>
                            <table class="table table-striped table-bordered" id="myTable" cellspacing="0" data-page-length="100">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>NRO. INTERNO</th>
                                        <th>BANCO EMISOR</th>
                                        <th>MONTO</th>
                                        <th>NRO. CHEQUE</th>
                                        <th>FECHA COBRO</th>
                                    </tr>
                                </thead>
                                <tbody id="editarChequeTable">
                        
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger" data-dismiss="modal">
                            <i class="bi bi-x-lg"></i>
                            Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="updateCheque()">
                            <i class="bi bi-check-lg"></i>
                            Actualizar
                        </button>
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
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="js/cargaCobranza.js"></script>
    </body>

    </html>
    <script>

            $(document).ready(function() {
                let monto = document.querySelector("#montoACobrar");
                
                let newMonto = monto.getAttribute("attr-valorreal")
                newMonto = parseFloat(newMonto)
                
                newMonto = newMonto.toLocaleString('es-AR', {
                    style: 'decimal',
                    maximumFractionDigits: 0,
                    minimumFractionDigits: 0
                }); 
            
                monto.value = "$" + newMonto;
            });
            
            const setearSelect2 = () => {
                $(document).ready(function() {
                    $('.banco').select2();
                });
            };

    </script>
<!-- <?php
// }
?> -->