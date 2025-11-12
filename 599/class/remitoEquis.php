<?php

class RemitoEquis {

    function __construct(){

        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
        
    }

    public function traerTodos($campo = null){

        $cid = $this->conn->conectar('central');

        if($campo){
            $sql = "SET DATEFORMAT YMD SELECT A.*,C.rendido FROM SJ_EQUIS_TABLE A
            LEFT JOIN sj_administracion_cobros_por_remito B ON B.num_rem = A.N_COMP COLLATE Latin1_General_BIN
            LEFt JOIN sj_administracion_cobros C ON C.id = B.id_cobro WHERE (FECHA_MOV BETWEEN '2022-02-10 00:00:00.000' AND '2022-02-15 00:00:00.000') AND RAZON_SOCI LIKE '%$campo%'  AND C.rendido != 1 ORDER BY  COD_PRO_CL, N_COMP";

        }else{

            $sql = "SET DATEFORMAT YMD 
                    SELECT ISNULL(E.GRUPO_EMPR, A.COD_PRO_CL) COD_PRO_CL, ISNULL(E.NOMBRE_GRU, A.RAZON_SOCI) RAZON_SOCI, SUM(A.IMPORTE_TO) IMPORTE_TO, SUM(A.CANT_ART) CANT_ART, c.importe_total, A.CHEQUEADO
                    FROM SJ_EQUIS_TABLE A
                    LEFT JOIN sj_administracion_cobros_por_remito B ON B.num_rem = A.N_COMP COLLATE Latin1_General_BIN
                    LEFT JOIN sj_administracion_cobros C ON C.id = B.id_cobro
                    LEFT JOIN STA14 D ON A.N_COMP = D.N_COMP
                    LEFT JOIN (SELECT A.COD_CLIENT, A.GRUPO_EMPR, B.NOMBRE_GRU FROM GVA14 A INNER JOIN GVA62 B ON A.GRUPO_EMPR = B.GRUPO_EMPR) E ON A.COD_PRO_CL = E.COD_CLIENT
                    WHERE (A.FECHA_MOV >= GETDATE()-700) AND (C.rendido != 1 OR C.rendido IS NULL) AND D.ESTADO_MOV != 'A'
                    GROUP BY ISNULL(E.GRUPO_EMPR, A.COD_PRO_CL), ISNULL(E.NOMBRE_GRU, A.RAZON_SOCI), c.importe_total, A.CHEQUEADO, E.NOMBRE_GRU 
                    ORDER BY COD_PRO_CL";

        }

        $stmt = sqlsrv_query($cid, $sql);

        $v = [];
        
        try {
            
            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
    
            return $v;
    
        }
        catch (\Throwable $th) {
            die("Error en sqlsrv_exec");
        }
    

    }


    public function traerDetalle($codClient){

        $cid = $this->conn->conectar('central');

        $sql = "SET DATEFORMAT YMD
                SELECT * FROM
                (
                SELECT A.FECHA_MOV, ISNULL(C.GRUPO_EMPR, A.COD_PRO_CL) COD_PRO_CL, A.RAZON_SOCI, A.N_COMP, A.IMPORTE_TO, A.CANT_ART, A.GC_GDT_NUM_GUIA, A.CHEQUEADO, A.FECHA_CHEQUEADO FROM SJ_EQUIS_TABLE A
                LEFT JOIN STA14 B ON A.N_COMP = B.N_COMP
                LEFT JOIN (SELECT A.COD_CLIENT, A.GRUPO_EMPR, B.NOMBRE_GRU FROM GVA14 A INNER JOIN GVA62 B ON A.GRUPO_EMPR = B.GRUPO_EMPR) C ON A.COD_PRO_CL = C.COD_CLIENT
                WHERE B.ESTADO_MOV != 'A'
                ) A
                WHERE COD_PRO_CL = '$codClient'
                ORDER BY  A.COD_PRO_CL, A.N_COMP";

        $stmt = sqlsrv_query($cid, $sql);
        $v=[];
     
        try {
            
            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
    
            return $v;
    
        }
        catch (\Throwable $th) {
            die("Error en sqlsrv_exec");
        }
    


    }
    
    public function cambiarEstado ($remito) {
   
        $cid = $this->conn->conectar('central');

        
        $sql = "UPDATE SJ_EQUIS_TABLE SET CHEQUEADO = '1' ,FECHA_CHEQUEADO =getdate() WHERE N_COMP = '$remito'";

        try {
            $stmt = sqlsrv_query($cid, $sql);
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }


    }

    public function cargarCheque ($data){

        $cid = $this->conn->conectar('central');

        $numeroDeInterno = $data['numeroDeInterno'];
        $numeroDeCheque = $data['numeroDeCheque'];
        $banco = $data['banco'];
        $chequeMonto = $data['chequeMonto'];
        $fechaCobro = $data['fechaCobro'];
        $codClient = $data['codClient'];

        
        $sql = "INSERT INTO sj_administracion_cheques (cod_client, num_cheque, banco, monto, fecha_cheque) VALUES ('$codClient', '$numeroDeCheque', '$banco', '$chequeMonto', '$fechaCobro') ";
        
        try {
            $stmt = sqlsrv_query($cid, $sql);

    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }
    
    public function traerCheque ($codClient){

        $cid = $this->conn->conectar('central');
        
        $sql = "SELECT * FROM sj_administracion_cheques WHERE cod_client = '$codClient' ORDER BY num_cheque DESC";
        
        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
            
            return $v;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function traerChequeNumInterno (){

        $cid = $this->conn->conectar('central');
        
        $sql = "SELECT ISNULL(MAX(ID),0) FROM sj_administracion_cheques";
        
        try {

            $stmt = sqlsrv_query($cid, $sql);

           $row = sqlsrv_fetch_array($stmt);
    
   
            return $row[0];
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }
    public function buscarCobro ($cod_client, $importe_efectivo, $importe_cheque, $importe_total) {

            
            $cid = $this->conn->conectar('central');
            
            $sql = "SELECT * FROM sj_administracion_cobros 
            WHERE cod_client = '$cod_client' 
            AND importe_efectivo = '$importe_efectivo' 
            AND importe_cheque = '$importe_cheque' 
            AND importe_total = '$importe_total' 
            AND CAST (fecha_cobro AS DATE) = CAST (getdate() AS DATE)";
  
            try {
    
                $stmt = sqlsrv_query($cid, $sql);

                $hasRows = sqlsrv_has_rows($stmt);
        
                return $hasRows;
               
            }
            catch (\Throwable $th) {
    
                die("Error en sqlsrv_exec");
    
            }


    }

    public function guardarCobro ($cod_client, $importe_efectivo, $importe_cheque, $importe_total, $nombreCliente, $valorDescontado, $username ) {
        
        $cid = $this->conn->conectar('central');
        
        $sql = "INSERT INTO sj_administracion_cobros (cod_client, importe_efectivo, importe_cheque, importe_total,rendido, nombre_cliente, descuento, user_cobro) VALUES ('$cod_client', '$importe_efectivo', '$importe_cheque', '$importe_total','0','$nombreCliente', '$valorDescontado ', '$username')SELECT SCOPE_IDENTITY()";

        try {

            $stmt = sqlsrv_query($cid, $sql);

            $next_result = sqlsrv_next_result($stmt);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                $v[] = $row;

            }

            return ( $v[0][""]);
    
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function guardarCobroRemito ($idCobro, $remito ) {

        $cid = $this->conn->conectar('central');
        
        $sql = "INSERT INTO sj_administracion_cobros_por_remito (id_cobro, num_rem,rendido,fecha_rendido) VALUES ('$idCobro', '$remito','0',getdate())";
   
        try {

            $stmt = sqlsrv_query($cid, $sql);

            return true;
    
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function traerRemito ($numRemito){

        $cid = $this->conn->conectar('central');
        
        $sql = "SELECT * FROM SJ_EQUIS_TABLE WHERE N_COMP in ($numRemito) ORDER BY FECHA_MOV ASC";

        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
            
            return $v;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function traerTodosLosCheques (){

        $cid = $this->conn->conectar('central');
        
        $sql = "SELECT ID, cod_client, MAX(importe_efectivo) importe_efectivo, MAX(importe_cheque) importe_cheque, importe_total, fecha_cobro, rendido, 
                nombre_cliente, descuento, user_rinde, SUM(IMPORTE_TO) IMPORTE_TO FROM
                (
                SELECT A.*,C.IMPORTE_TO FROM sj_administracion_cobros A 
                INNER JOIN sj_administracion_cobros_por_remito B ON A.ID = B.id_cobro
                INNER JOIN SJ_EQUIS_TABLE C ON B.num_rem = C.N_COMP collate Latin1_General_BIN
                WHERE A.rendido = 0
                ) A
                GROUP BY ID, cod_client, importe_total, fecha_cobro, rendido, nombre_cliente, descuento, user_rinde";

        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
            
            return $v;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function rendirCobro ($id, $userName = null){
        
        $cid = $this->conn->conectar('central');
        
        $sql = "UPDATE sj_administracion_cobros SET rendido = 1,user_rinde = '$userName' ,fecha_cobro = getdate() where id = '$id'";
        
        try {

            $stmt = sqlsrv_query($cid, $sql);
            
            return true;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }

    public function asignarCobroPorCheque ($idCobro,$idCheque){

        $cid = $this->conn->conectar('central');
        
        $sql = "INSERT INTO sj_administracion_cobros_por_cheque (id_cobro, id_cheque) VALUES ('$idCobro', '$idCheque')";
        
   
        try {

            $stmt = sqlsrv_query($cid, $sql);
            
            return true;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }


    public function traerReporteTodosLosCheques ($inputBuscar, $selectEstado, $desde, $hasta){

        $cid = $this->conn->conectar('central');

        $sql ="SET dateformat YMD SELECT * from sj_administracion_cobros A
        INNER JOIN sj_administracion_cheques B ON B.cod_client = A.cod_client COLLATE Latin1_General_BIN
        INNER JOIN sj_administracion_cobros_por_cheque E ON E.id_cobro = A.id AND E.id_cheque = B.id
        LEFT JOIN (SELECT ID, BANCO NOMBRE_BANCO FROM RO_T_BANCOS) C ON b.banco = C.ID 
        WHERE A.rendido LIKE '%$selectEstado%' AND (A.nombre_cliente LIKE '%$inputBuscar%' OR B.id LIKE '%$inputBuscar%' OR B.banco LIKE '%$inputBuscar%' OR B.monto LIKE '%$inputBuscar%' OR B.num_cheque LIKE '%$inputBuscar%') AND A.fecha_cobro BETWEEN '$desde' AND '$hasta' ";
        try {

            $stmt = sqlsrv_query($cid, $sql);
   
            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {
    
                $v[] = $row;
    
            }
            
            return $v;
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }

    }
    
    public function traerEfectivoCheque () {

        $cid = $this->conn->conectar('central');

        $sql = " SELECT distinct a.* from sj_administracion_cobros a
        INNER JOIN sj_administracion_cobros_por_remito B ON B.id_cobro = a.id 
        where a.rendido = 0
        and b.num_rem in 
        (
        select distinct N_COMP collate Latin1_General_BIN from SJ_EQUIS_TABLE where CHEQUEADO = 1
        )";

        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                $v[] = $row;

            }
            
            return $v;

        }
            catch (\Throwable $th) {

                die("Error en sqlsrv_exec");

        }
    }

    public function traerBancos () {

        $cid = $this->conn->conectar('central');

        $sql = "SELECT * FROM RO_T_BANCOS ";
        
        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                $v[] = $row;

            }
            
            return $v;

        }
            catch (\Throwable $th) {

                die("Error en sqlsrv_exec");

        }
    }
    
    public function listarDetalleRemitos ($desde, $hasta, $selectEstado, $inputBuscar, $selectTalonario ) {
        
        $cid = $this->conn->conectar('central');

        $sql = "SET DATEFORMAT YMD SELECT * FROM (
        SELECT A.*, F.NOMBRE_VEN, estado =
        CASE
            WHEN D.ESTADO_MOV = 'A' THEN 'ANULADO'
            when A.CHEQUEADO =  1 AND C.rendido = 0 THEN 'COBRADO'
            when A.CHEQUEADO = 1 AND C.rendido = 1 THEN 'RENDIDO'
            WHEN A.CHEQUEADO = 0 THEN 'DEBE'
        END
        FROM SJ_EQUIS_TABLE A 
        LEFT JOIN sj_administracion_cobros_por_remito B on a.N_COMP = B.num_rem collate Latin1_General_BIN
        LEFT JOIN sj_administracion_cobros C on C.id = B.id_cobro
        LEFT JOIN STA14 D on D.N_COMP = A.N_COMP
		LEFT JOIN GVA14 E ON D.COD_PRO_CL = E.COD_CLIENT
		LEFT JOIN GVA23 F ON E.COD_VENDED = F.COD_VENDED 
        )A
        WHERE (A.FECHA_MOV BETWEEN '$desde' AND '$hasta') AND (A.N_COMP LIKE '%$inputBuscar%' OR A.RAZON_SOCI LIKE '%$inputBuscar%' OR A.IMPORTE_TO LIKE '%$inputBuscar%' OR A.CANT_ART LIKE '%$inputBuscar%' OR A.GC_GDT_NUM_GUIA LIKE '%$inputBuscar%')
        AND A.estado LIKE '%$selectEstado%' AND A.N_COMP LIKE '%$selectTalonario%'";

        try {
            
            $stmt = sqlsrv_query($cid, $sql);


                    $v = [];

                    while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                        $v[] = $row;

                    }
                    
                    return $v;

                }
                    catch (\Throwable $th) {

                        die("Error en sqlsrv_exec");

                }

    }
    public function traerChequeDetalle ($idCheque) {

        $sql = "SELECT * FROM sj_administracion_cheques WHERE id = '$idCheque'";

        $cid = $this->conn->conectar('central');

        
        try {

            $stmt = sqlsrv_query($cid, $sql);

            $v = [];

            while ($row = sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) {

                $v[] = $row;

            }
            
            return $v;

        }
            catch (\Throwable $th) {

                die("Error en sqlsrv_exec");

        }

    }
    public function actualizarCheque ($data){
        $cid = $this->conn->conectar('central');

        
        $id = $data['id'];
        $nroCheque = $data['nroCheque'];
        $banco = $data['banco'];
        $monto = $data['monto'];
        $fechaCheque = $data['fechaCheque'];

        $sql = "UPDATE sj_administracion_cheques SET num_cheque = $nroCheque, banco = $banco , monto = $monto, fecha_cheque = '$fechaCheque' WHERE id = $id";

        try {
            $stmt = sqlsrv_query($cid, $sql);
    
        }
        catch (\Throwable $th) {

            die("Error en sqlsrv_exec");

        }


    }
}