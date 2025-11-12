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
        <title>Reporte de cheques</title>
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
                <div class="wrapper wrapper--w680"><div style="color:white; text-align:center"><h6>Reporte de Cheques</h6></div>
                    <div class="card card-1">
                        
                        <div class="row" style="margin-left:50px">
                            <h3><strong><i class="bi bi-bank" style="margin-right:20px;font-size:50px"></i>Reporte de Cheques recibidos</strong></h3>

                        </div>
                        <form action="#" method="get">
                            <div class="row" style="margin-top:10px">

                                <div class="col-3" style="margin-left:50px">Desde: <input type="date" style="width:150px; height:45px" id='desde' name="desde" value="<?php echo $desde; ?>">Hasta: <input type="date" name="hasta" style="width:150px; height:45px" value="<?= $hasta ?>"></div>
                                <div class="col-3">Estado :  
                                    <select name="selectEstado" id="selectEstado" style="width:150px; height:45px">
                                        <option value="%">Todos</option>
                                        <option value="0">A Rendir</option>
                                        <option value="1">Rendido</option>

                                    </select>
                                    <button class="btn btn-primary btn-submit" value="" style="height:50px;margin-left:2px">filtrar <i class="bi bi-funnel-fill" style="color:white"></i></button>
                                </div>
                                <!-- <div class="col">  Busqueda Rapida: <input type="text" style="height:45px" placeholder="Sobre Cualquier Campo..." name="inputBuscar"></div> -->
                                <div class="col" style="text-align:right;margin-right:80px">    <h4><button class="btn btn-success btn_exportar" id="btnExport" style=" height:45px"><i class="fa fa-file-excel-o"></i> Exportar<i class="bi bi-file-earmark-excel"></i></button></h4></div>

                            </div>
                        </form>

                        <table class="table table-striped table-bordered" id="myTable" style="width: 99%;" cellspacing="0" data-page-length="100">
                            <thead class="thead-dark">
                                <tr>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">FECHA COBRO</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">NRO. INTERNO</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">CLIENTE</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">BANCO EMISOR</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">IMPORTE</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">NRO. CHEQUE</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">FECHA VENCIMIENTO</th>
                                    <th style="position: sticky; top: 0; z-index: 10;text-align:center">RENDIDO</th>

                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cheques as $key => $value) {
                    
                                ?>
                                    <tr style="text-align:center">
                                        <td id="numeroDeRemito" ><?php echo $value['fecha_cobro']->format('Y-m-d') ?></td>  
                                        <td><?php echo $value['id'] ?></td>
                                        <td id="importeEfectivo"><?php echo $value['nombre_cliente'] ?></td>
                                        <td id="importeCheque"><?php echo $value['NOMBRE_BANCO'] ?></td>
                                        <td id="numeroDeRemito" >$ <?php echo number_format($value['monto'], 0, ',', '.') ?></td>  
                                        <td id="numeroDeRemito" ><?php echo $value['num_cheque'] ?></td>  
                                        <td id="numeroDeRemito" ><?php echo $value['fecha_cheque']->format('Y-m-d') ?></td>  
                                        <?php if($value['rendido'] ==  1){ ?>
                                        <td id="numeroDeRemito" style="width:30px;height:30px"><i class="bi bi-check-circle-fill" style="color:green;"></i></td>  
                                        <?php }else{?>
                                        <td id="numeroDeRemito" style="width:30px;height:30px"></td>  
                                            <?php }?>
                                    </tr>

                                <?php } ?>
                        
                            </tbody>
            
                        </table>
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

    </body>

</html>
<script src="js/jquery.table2excel.js"></script>

<script>

        $(document).ready(function() {
            $('#myTable').DataTable({
                responsive: true,
                buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
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