
document.addEventListener("DOMContentLoaded", () => {
          

    let importeEfectivo = document.querySelectorAll("#importeEfectivo");
    let importeCheque = document.querySelectorAll("#importeCheque");
    let totalChequeInput = document.querySelector("#totalCheque");



    importeEfectivo.forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent))
        element.textContent ="$" +  parseNumber(element.textContent);

    });

    importeCheque.forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent))
        element.textContent ="$" + parseNumber(element.textContent);

    });

    totalEfectivo.value = parseNumber(totalEfectivo.value);


});

const calcularTotales = (row) =>{
    let todosLosCheck = document.querySelectorAll("#checkCalcularTotales");
    let totalEfectivoInput = document.querySelector("#totalEfectivo");
    let totalChequeInput = document.querySelector("#totalCheque");
    let totalEfectivo = "00,00"
    let totalCheque = "00,00"

    todosLosCheck.forEach(element => {

        if(element.checked){

            totalEfectivo = parseFloat(totalEfectivo) +  parseFloat(element.parentElement.parentElement.childNodes[7].getAttribute("attr-realValue"))
            totalCheque = parseFloat(totalCheque)    + parseFloat(element.parentElement.parentElement.childNodes[9].getAttribute("attr-realValue"))
        }
    });
    totalEfectivoParseado = parseNumber(totalEfectivo);
    totalChequeParseado = parseNumber(totalCheque);
    totalEfectivoInput.value = "$ " + totalEfectivoParseado
    totalChequeInput.value = "$ " + totalChequeParseado

};


const rendir = () => {

    Swal.fire({
        icon: 'warning',
        title: '¿Desea registrar la rendición?',
        showDenyButton: true,
        confirmButtonText: 'Aceptar',
        denyButtonText: 'Cancelar',
    }).then((result) => {
        / Read more about isConfirmed, isDenied below /
        if (result.isConfirmed) {
            let todosLosCheck = document.querySelectorAll("#checkCalcularTotales");
    
            let idCobroEnCadena = ""
            todosLosCheck.forEach(element => {

                if(element.checked){

                    if(idCobroEnCadena == ""){

                        idCobroEnCadena = element.parentElement.parentElement.childNodes[11].textContent 

                    }else{

                        idCobroEnCadena =  idCobroEnCadena + "-" + element.parentElement.parentElement.childNodes[11].textContent 
                    }
                }
            });

            let arrayDeCobros = idCobroEnCadena.split("-");
            let userName = document.querySelector("#userName").textContent;
          
            $.ajax({
                url: "controller/rendirValores.php",
                type: "POST",
                data: { cobros: arrayDeCobros, userName:userName },
                success: function (response) {
                    // console.log(response);
                }
            });

            Swal.fire('Guardada!', '', 'success').then((result) => {
                location.reload()
            })

        } else if (result.isDenied) {
        Swal.fire('La rendición fue cancelada!', '', 'info')
        }
    })

}

const parseNumber = (number) => {

    number = parseFloat(number);

    newNumber = number.toLocaleString('de-De', {
        style: 'decimal',
        maximumFractionDigits: 0,
        minimumFractionDigits: 0
    }); 
    return newNumber;
}