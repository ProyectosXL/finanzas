const btnConfirmar = document.querySelector("#btnConfirmar");

const parseNumber = ()=>{

    let allMontos = document.querySelectorAll("#monto");

    allMontos.forEach(monto => {
        let valor = parseInt(monto.textContent);
        valor = valor.toLocaleString('de-De', {
            style: 'decimal',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        });
        monto.setAttribute("attr-realValue", monto.textContent)
        monto.textContent = "$ "+valor;
    });

}

const checkMonto = ()=>{

    const todosLosCheck = document.querySelectorAll('input[type="checkbox"]');
    let totalMontosCheck = 0;

    todosLosCheck.forEach(e => {
        if(e.checked){

            totalMontosCheck = totalMontosCheck + parseInt(e.parentElement.parentElement.childNodes[3].getAttribute("attr-realValue"))
        }
    });



        
 
        document.querySelector("#importeAbonar").setAttribute("attr-realValue", totalMontosCheck);
        document.querySelector("#importeAbonar").value = "$" +totalMontosCheck.toLocaleString('de-De', {
            style: 'decimal',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        });
        calcularDescuento();


    // document.querySelector("#importeAbonar").value = totalMontosCheck

}

btnConfirmar.addEventListener("click",function (){

    const todosLosCheck = document.querySelectorAll('input[type="checkbox"]');
    let userName = document.querySelector("#user").textContent;
    let totalMontosCheck = ""

    todosLosCheck.forEach(e => {

        if(e.checked){
            if(totalMontosCheck == ""){
                totalMontosCheck = e.parentElement.parentElement.childNodes[2].textContent
            }else{
                totalMontosCheck =  totalMontosCheck + "-" +e.parentElement.parentElement.childNodes[2].textContent
            }
        }

    });

    sessionStorage.setItem("Remitos", totalMontosCheck);
    // let descuento = document.querySelector("#descuento").getAttribute("attr-realValue");
    if( document.querySelector("#descuento").value == "" || document.querySelector("#descuento").value < 0 ){

        Swal.fire({
            icon: 'warning',
            title: 'No se cargó % de descuento, desea continuar?',
            showDenyButton: true,
            confirmButtonText: 'Aceptar',
            denyButtonText: 'Cancelar',
        }).then((result) => {

            if (result.isConfirmed) {

                Swal.fire('Guardado!', '', 'success').then((result)=>{
                    let montoTotalDeuda = document.querySelector("#totalDeuda").value
                    let importeAbonar = document.querySelector("#importeAbonar").value
                    let codCliente = document.querySelector("#codClient").textContent

                    window.location.href = "cargaCobranza.php?montoTotal="+montoTotalDeuda+"&importeAbonar="+importeAbonar.replace(/[$.]/g, "")+"&codCliente="+encodeURIComponent(codCliente)+"&valorDescontado=0&userName="+userName;

                })


            } else if (result.isDenied) {
                Swal.fire('El proceso fue cancelado', '', 'info')
            }
        })

    }else{

        Swal.fire('Guardado!', '', 'success').then((result)=>{
            
            let montoTotalDeuda = document.querySelector("#totalDeuda").value;
            let importe = document.querySelector("#importeAbonar").value.replace(/[$.]/g, "");
            let descuento = document.querySelector("#descuento").value;
            descuento = descuento.replace("%","");

            let porcentaje = 0;

            if(descuento != "" && descuento != 0){
                porcentaje = ( parseInt(importe)  *  parseInt(descuento)  ) / 100;
            }

            let importeAbonar = parseInt(importe) - parseInt(porcentaje);

            let codCliente = document.querySelector("#codClient").textContent

            window.location.href = "cargaCobranza.php?montoTotal="+montoTotalDeuda+"&importeAbonar="+importeAbonar+"&codCliente="+encodeURIComponent(codCliente)+"&valorDescontado="+porcentaje+"&userName="+userName;

        })


    }
})

const calcularDescuento = ()=>{

    let montoTotalDeuda = document.querySelector("#totalDeuda").value;
    let descuento = document.querySelector("#descuento").value;
    descuento = descuento.replace("%","");
    let importe = document.querySelector("#importeAbonar").value.replace(/[$.]/g, "");

    let porcentaje = 0;

    if(descuento != "" && descuento != 0){

        porcentaje = ( parseInt(importe)  *  parseInt(descuento)  ) / 100;


        let importeAbonar = parseInt(importe) - parseInt(porcentaje);
        document.querySelector("#importeConDescuento").setAttribute("attr-realValue", importeAbonar);
        document.querySelector("#importeConDescuento").value = "$" +  importeAbonar.toLocaleString('de-De', {
            style: 'decimal',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        });



    }else{
        document.querySelector("#importeConDescuento").setAttribute("attr-realValue", 0);
        document.querySelector("#importeConDescuento").value = "$ 0" 

    }

}