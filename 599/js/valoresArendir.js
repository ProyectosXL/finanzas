
document.addEventListener("DOMContentLoaded", () => {
          

    let importeEfectivo = document.querySelectorAll(".importeEfectivo");
    let importeCheque = document.querySelectorAll(".importeCheque");

    importeEfectivo.forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent))
        element.textContent ="$" +  parseNumber(element.textContent);

    });

    importeCheque.forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent))
        element.textContent ="$" + parseNumber(element.textContent);

    });

});

const calcularTotales = (checkbox) =>{
    let todosLosCheck = document.querySelectorAll(".checkCalcularTotales");
    let totalEfectivoInput = document.querySelector("#totalEfectivo");
    let totalChequeInput = document.querySelector("#totalCheque");
    let totalEfectivo = 0;
    let totalCheque = 0;

    todosLosCheck.forEach(element => {

        if(element.checked){
            let row = element.closest('tr');
            let cells = row.querySelectorAll('td');
            
            // cells[3] = EFECTIVO, cells[4] = CHEQUES
            totalEfectivo += parseFloat(cells[3].getAttribute("attr-realValue")) || 0;
            totalCheque += parseFloat(cells[4].getAttribute("attr-realValue")) || 0;
        }
    });
    
    totalEfectivoParseado = parseNumber(totalEfectivo);
    totalChequeParseado = parseNumber(totalCheque);
    totalEfectivoInput.textContent = "$ " + totalEfectivoParseado
    totalChequeInput.textContent = "$ " + totalChequeParseado

};


const rendir = () => {

    Swal.fire({
        icon: 'warning',
        title: '¿Desea registrar la rendición?',
        showDenyButton: true,
        confirmButtonText: 'Aceptar',
        denyButtonText: 'Cancelar',
    }).then((result) => {
        /* Read more about isConfirmed, isDenied below */
        if (result.isConfirmed) {
            let todosLosCheck = document.querySelectorAll(".checkCalcularTotales");
    
            let idCobroEnCadena = ""
            todosLosCheck.forEach(element => {

                if(element.checked){
                    let row = element.closest('tr');
                    let idCobro = row.getAttribute('data-id-cobro');
                    
                    if(idCobroEnCadena == ""){
                        idCobroEnCadena = idCobro;
                    }else{
                        idCobroEnCadena = idCobroEnCadena + "-" + idCobro;
                    }
                }
            });

            let arrayDeCobros = idCobroEnCadena.split("-");
            let primerRow = document.querySelector('tr[data-username]');
            let userName = primerRow ? primerRow.getAttribute('data-username') : '';
          
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