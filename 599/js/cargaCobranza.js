const completarModal = (codClient) => {

    traerCheques(codClient);
    let saldo = document.querySelector("#modalSaldo");
    let montoACobrar = document.querySelector("#montoACobrar").getAttribute("attr-valorReal");
    let cobroEfectivo = document.querySelector("#cobroEfectivo").getAttribute("attr-valorreal");


    let total = montoACobrar - cobroEfectivo;

    saldo.value = "$" + parseNumber(total);

}

const confirmarCobro = (codClient) => {

    let cobroEfectivo = document.querySelector("#cobroEfectivo").getAttribute("attr-valorReal");
    let cobroCheque = document.querySelector("#cobroCheque").getAttribute("attr-valorReal");
    let montoACobrar = document.querySelector("#montoACobrar").getAttribute("attr-valorReal");
    let saldoCobrar = document.querySelector("#saldoCobrar").getAttribute("attr-valorReal");
    let nombreCliente = document.querySelector("#cliente").getAttribute("attr-cliente")
    let idCheques = localStorage.getItem("idCheques");
    let valorDescontado = parseInt(document.querySelector("#valorDescontado").textContent);
    let username = document.querySelector("#user").textContent;


    if (parseInt(montoACobrar) != parseInt(saldoCobrar)) {
        Swal.fire({
            icon: 'warning',
            title: 'El importe cobrado no coincide con el importe a cobrar ¿Desea continuar?',
            showDenyButton: true,
            confirmButtonText: 'Aceptar',
            denyButtonText: 'Cancelar',
        }).then((result) => {
            // Read more about isConfirmed, isDenied below 
            if (result.isConfirmed) {
                Swal.fire('Guardado!', '', 'success')
            } else if (result.isDenied) {
                Swal.fire('El proceso fue cancelado', '', 'info')
            }
        })
    }

    if (cobroEfectivo < 1 && cobroCheque < 1) {
        Swal.fire({
            icon: 'error',
            title: 'Error de carga',
            text: 'Debe cargar el importe a cobrar!'
        })
    }

    if (saldoCobrar == 0) {

        Swal.fire({
            icon: 'warning',
            title: '¿Desea registrar el cobro?',
            showDenyButton: true,
            confirmButtonText: 'Aceptar',
            denyButtonText: 'Cancelar',
        }).then((result) => {
            // Read more about isConfirmed, isDenied below 
            if (result.isConfirmed) {
                let remitosEnCadena = sessionStorage.getItem("Remitos");
                var arrayDeRemitos = remitosEnCadena.split("-");

                // espacio

                $.ajax({
                    url: "controller/ejecutarCobroController.php",
                    type: "POST",
                    data: {
                        remitos: arrayDeRemitos,
                        cobroEfectivo: cobroEfectivo,
                        cobroCheque: cobroCheque,
                        saldoCobrar: saldoCobrar,
                        montoACobrar: montoACobrar,
                        codClient: codClient,
                        idCheque: idCheques,
                        nombreCliente: nombreCliente,
                        valorDescontado: valorDescontado,
                        username:username

                    },
                    success: function (data) {
                      
                        if(data == 1){

                            Swal.fire('Cobro confirmado!', '', 'success').then((result) => {

                                window.location.href = "composicionDeRemitos.php"

                            });

                        }else {
                            Swal.fire('Error!', 'El cobro ya fue realizado anteriormente, por favor regrese a la pantalla de seleccion y actualice', 'error').then((result) => {
                                window.location.href = "composicionDeRemitos.php";
                            });
                        }

                    }
                });

            } else if (result.isDenied) {
                Swal.fire('El cobro fue cancelado', '', 'info')
            }
        })
    }

}

const calcularSaldo = () => {

    let montoACobrar = document.querySelector("#montoACobrar").getAttribute("attr-valorReal");
    let saldoCobrar = document.querySelector("#saldoCobrar");

    efectivo = document.querySelector("#cobroEfectivo");




    let cheque = document.querySelector("#cobroCheque");




    if (efectivo.getAttribute("attr-valorreal") == "" || efectivo.getAttribute("attr-valorreal") == null) {
        efectivo.setAttribute("attr-valorreal", 0);
        efectivo.value = 0;
    }

    if (cheque.getAttribute("attr-valorreal") == "") {
        cheque.setAttribute("attr-valorreal", 0);
        cheque.value = 0;
    }

    let total = parseInt(montoACobrar) - (parseInt(efectivo.getAttribute("attr-valorreal")) + parseInt(cheque.getAttribute("attr-valorreal")));

    saldoCobrar.value = `$ ${parseNumber(total)} `
    saldoCobrar.setAttribute("attr-valorReal", total);

}


const traerCheques = (codClient) => {


    $.ajax({

        url: "controller/traerBancosController.php",
        type: "GET",
        success: function (result) {




            let bancos = JSON.parse(result);
            $.ajax({

                url: "controller/traerChequeController.php",
                type: "POST",
                data: {
                    codClient: codClient
                },
                success: function (result) {

                    let tableBody = $("#tableCheques").html();
                    let date = new Date()
                    let getYear = date.toLocaleString("default", { year: "numeric" });
                    let getMonth = date.toLocaleString("default", { month: "2-digit" });
                    let getDay = date.toLocaleString("default", { day: "2-digit" });
                    let dateFormat = getYear + "-" + getMonth + "-" + getDay;
                    let numCheque = 0;

                    if ((result) && result != "" && result > 0) {
                        numCheque = parseInt(result) + 1;
                    } else {
                        numCheque = 1;
                    }


                    let nuevaFila =
                        `<tr id="tdCheques">
                        <td style="width:40px;text-align:center" id="numInterno">${numCheque}</td> 
                        <td style="text-align:center" ><select class="banco" name="selectBanco" id="selectBanco" style="width:220px" onchange="comprobarSumFila(this)"> `

                    bancos.forEach(element => {
                        nuevaFila = nuevaFila + `<option value ="${element.ID}">${element.BANCO}</option>`
                    });

                    nuevaFila = nuevaFila + `</select></td>
             
                        <td style="text-align:center"><input type="text" style="width:120px" onchange="calcularMontoCheque(this)" id="divMontoCheque" value="$0"></td>
                        <td style="text-align:center"><input type="text" style="width:120px" onchange="comprobarSumFila(this)"></td>
                        <td style="text-align:center"><input type="date" style="width:120px" onchange="comprobarSumFila(this)" value="${dateFormat}"></td>
                        <td style="text-align:center"><button value="+" onclick="agregarFila(${(parseInt(result) + 1)},this)" hidden id="btnAgregarFila">+</button></td>


                    </tr>
                    `
                        ;
                    tableBody = "";
                    $("#tableCheques").html(tableBody + nuevaFila);
                    setearSelect2();

                }
            });
        }
    })
}

const agregarFila = (nroInterno, fila) => {

    fila.setAttribute("hidden", "true")
    let tableBody = $("#tableCheques").html();

    var date = new Date()
    let getYear = date.toLocaleString("default", { year: "numeric" });
    let getMonth = date.toLocaleString("default", { month: "2-digit" });
    let getDay = date.toLocaleString("default", { day: "2-digit" });
    let dateFormat = getYear + "-" + getMonth + "-" + getDay;
    $.ajax({

        url: "controller/traerBancosController.php",
        type: "GET",
        success: function (result) {

            let bancos = JSON.parse(result);
            let nuevaFila =
                `<tr id="tdCheques">
            <td style="width:40px;text-align:center" id="numInterno">${(nroInterno + 1)}</td>
            <td style="text-align:center"><select class="banco" name="selectBanco" id="selectBanco" style="width:220px" onchange="comprobarSumFila(this)">`

            bancos.forEach(element => {
                nuevaFila = nuevaFila + `<option value ="${element.ID}">${element.BANCO}</option>`
            });

            nuevaFila = nuevaFila + `</select></td>
            <td style="text-align:center"><input type="text" style="width:120px" onchange="calcularMontoCheque(this)" id="divMontoCheque" value="$0"></td>
            <td style="text-align:center"><input type="text" style="width:120px" onchange="comprobarSumFila(this)"></td>
            <td style="text-align:center"><input type="date" style="width:120px" onchange="comprobarSumFila(this)" value="${dateFormat}"></td>
            <td style="text-align:center"><button value="+" onclick="agregarFila(${(nroInterno + 1)},this)" hidden>+</button></td> 
        </tr>
        `;

            $("#tableCheques").append(nuevaFila);
            setearSelect2();


        }
    })

}

const calcularMontoCheques = (isModal = null) => {

    let total = 0;
    let cheques = ""
    if (isModal){
        cheques = document.querySelectorAll("#UpdateChequeTd")
    }else{
        cheques = document.querySelectorAll("#tdCheques")
    }
    cheques.forEach(element => {

        let chequeMonto = element.childNodes[5].childNodes[0].value.replace(/[$.]/g, "");
        total += parseInt(chequeMonto);

    })

    let cobroCheque = document.querySelector("#cobroCheque");
    cobroCheque.setAttribute("attr-valorreal", parseInt(total));
    cobroCheque.value ="$" +  parseNumber(total);
    calcularSaldo();

}


const registrarCheque = (codClient) => {
    let idCheques = document.querySelectorAll("#numInterno");
    document.querySelectorAll("#numInterno")[0].textContent;

    let todosLosIdCheques = "";

    idCheques.forEach(element => {

        if (todosLosIdCheques != "") {

            todosLosIdCheques = todosLosIdCheques + "," + element.textContent
        } else {
            todosLosIdCheques = element.textContent
        }

    });
    localStorage.removeItem("idCheques");
    localStorage.setItem("idCheques", todosLosIdCheques);


    document.querySelectorAll("#tdCheques").forEach((element, x ) => {
        let fechaCobro = element.childNodes[9].childNodes[0].value;
        let banco = element.childNodes[3].childNodes[0].value;
        let numeroDeCheque = element.childNodes[7].childNodes[0].value;
        let numeroDeInterno = element.childNodes[1].textContent;
        let chequeMonto = element.childNodes[5].childNodes[0].value.replace(/[$.]/g, "");
        let cod = codClient;
        
        document.querySelector("#idCheque").value = numeroDeInterno;

        
        $.ajax({

            url: "controller/registrarChequeController.php",
            type: "POST",
            data: {
                fechaCobro: fechaCobro,
                banco: banco,
                numeroDeCheque: numeroDeCheque,
                numeroDeInterno: numeroDeInterno,
                chequeMonto: chequeMonto,
                codClient: cod
            },
            success: function (data) {
               
            }
        });

    })

    document.querySelector("#botonCheques").hidden = true
    document.querySelector("#botonEditarCheques").hidden = false
    calcularMontoCheques();

}

const comprobarSumFila = (fila) => {
    let banco = fila.parentElement.parentElement.childNodes[3].childNodes[0].value
    let monto = fila.parentElement.parentElement.childNodes[5].childNodes[0].value

    fila.parentElement.parentElement.childNodes[5].childNodes[0].value = "$" + parseNumber(monto.replace(/[$.]/g, ""))
    let nroCheque = fila.parentElement.parentElement.childNodes[7].childNodes[0].value
    let fecha = fila.parentElement.parentElement.childNodes[9].childNodes[0].value


    if (banco != "" && monto != "" && nroCheque != "" && fecha != "") {
        fila.parentElement.parentElement.childNodes[11].childNodes[0].removeAttribute("hidden");
    }
}

const parseNumber = (number) => {
    number = parseInt(number);

    newNumber = number.toLocaleString('de-De', {
        style: 'decimal',
        maximumFractionDigits: 0,
        minimumFractionDigits: 0
    });

    return newNumber;
}

const setearValores = () => {

    let efectivo = document.querySelector("#cobroEfectivo");
    let cheque = document.querySelector("#cobroCheque");


    if (efectivo.value == "") {

        efectivo.value = 0;
    }
    if (cheque.value == "") {

        cheque.value = 0;
    }



    efectivo.setAttribute("attr-valorreal", efectivo.value.replace(/[$.]/g, ""));
    cheque.setAttribute("attr-valorreal", cheque.value.replace(/[$.]/g, ""));

    nuevoValor = parseNumber(efectivo.value.replace(/[$.]/g, ""));
    efectivo.value = "$" + nuevoValor;

    nuevoValorCheque = parseNumber(cheque.value.replace(/[$.]/g, ""));
    cheque.value = "$" + nuevoValorCheque;

    calcularSaldo();
}
const updateChequesModal = () =>{

    let saldo = document.querySelector("#modalUpdateChequesSaldo");
    let montoACobrar = document.querySelector("#montoACobrar").getAttribute("attr-valorReal");
    let cobroEfectivo = document.querySelector("#cobroEfectivo").getAttribute("attr-valorreal");
    let total = montoACobrar - cobroEfectivo;
    saldo.value = "$" + parseNumber(total);


    let idCheques = localStorage.getItem("idCheques")
    arrayIdCheques = idCheques.split(",");
    let tableBody = $("#editarChequeTable").html();
    tableBody = "";

    arrayIdCheques.forEach((element , x)  => {
        $.ajax({

            url: "controller/traerChequeDetalleController.php",
            type: "POST",
            data: {
                idCheque: element
            },
            success: function (result) {
                let cheque = JSON.parse(result);
                $.ajax({

                    url: "controller/traerBancosController.php",
                    type: "GET",
                    success: function (result) {
            
                        let bancos = JSON.parse(result);
          
                let date = new Date();
                let getYear = date.toLocaleString("default", { year: "numeric" });
                let getMonth = date.toLocaleString("default", { month: "2-digit" });
                let getDay = date.toLocaleString("default", { day: "2-digit" });
                let dateFormat = getYear + "-" + getMonth + "-" + getDay;
                let numCheque = cheque[0]['id'];
                let nuevaFila =
                    `<tr id="UpdateChequeTd">
                    <td style="width:40px;text-align:center" id="UpdateChequenumInterno">${numCheque}</td> 

                    <td style="text-align:center"><select class="banco" name="selectBanco" id="UpdateChequenumInterno" style="width:220px">`

                    bancos.forEach(element => {
                        if(element.ID == cheque[0]['banco']){
                        nuevaFila = nuevaFila + `<option value ="${element.ID}" selected>${element.BANCO}</option>`

                        }else{

                        nuevaFila = nuevaFila + `<option value ="${element.ID}">${element.BANCO}</option>`
                        }
                    });
        
                    nuevaFila = nuevaFila + `</select></td>
                    <td style="text-align:center"><input type="text" style="width:120px" value = "$${parseNumber(cheque[0]['monto'])}" onchange="calcularUpdateMontoCheque(this)" id="updateMontoCheque"></td>
                    <td style="text-align:center"><input type="text" style="width:120px" value ="${cheque[0]['num_cheque']}"></td>
                     <td style="text-align:center"><input type="date" style="width:120px" value="${dateFormat}"></td>
                    </tr>`;

               
                $("#editarChequeTable").html(tableBody + nuevaFila);
                tableBody = $("#editarChequeTable").html();
                }
                    })
            }
        });
            
    });

}

const updateCheque = () => {
    let todosLosCheques=  document.querySelectorAll("#UpdateChequeTd") 

    todosLosCheques.forEach(element => {
    let id = element.childNodes[1].textContent;
    let banco = element.childNodes[3].childNodes[0].value;
    let monto = parseInt(element.childNodes[5].childNodes[0].value.replace(/[$.]/g, ""));
    let nroCheque = element.childNodes[7].childNodes[0].value;
    let fechaCheque = element.childNodes[9].childNodes[0].value;

    $.ajax({

        url: "controller/actualizarChequeController.php",
        type: "POST",
        data: {
            id:id,
            banco:banco,
            monto:monto,
            nroCheque:nroCheque,
            fechaCheque:fechaCheque
        },
        success: function (result) {
        }})
    })
    calcularMontoCheques(true);
}

const calcularUpdateMontoCheque = (td)=>{
    convertirValor(td);
    descontarSaldo(true);
}
const convertirValor = (td) =>{
    td.value =   "$" + parseNumber(td.value); 
}

const descontarSaldo = (flag) =>{

    let allMontos = ""; 
    let montoACobrar = document.querySelector("#montoACobrar").getAttribute("attr-valorReal");
    let cobroEfectivo = document.querySelector("#cobroEfectivo").getAttribute("attr-valorreal");

    if(cobroEfectivo == "" || cobroEfectivo == null || cobroEfectivo <0 ){
        cobroEfectivo = 0;
    }

    let total = parseInt(montoACobrar) - parseInt(cobroEfectivo);

    let saldo = "";

    if (flag == true) {

        allMontos = document.querySelectorAll("#updateMontoCheque");
        saldo = document.querySelector("#modalUpdateChequesSaldo");
        
    }else {

        allMontos = document.querySelectorAll("#divMontoCheque");
        saldo = document.querySelector("#modalSaldo");
        
    }
    
    let nuevoSaldo = parseInt(total);

    allMontos.forEach(element => {

        nuevoSaldo = nuevoSaldo - parseInt(element.value.replace(/[$.]/g, ""));

    });

    saldo.value = "$" + parseNumber(nuevoSaldo);
}
const calcularMontoCheque = (td)=>{
    comprobarSumFila(td);
    descontarSaldo(false);
}

