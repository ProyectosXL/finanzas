
document.addEventListener("DOMContentLoaded", () => {

    document.querySelectorAll(".importeEfectivo").forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent) || 0);
        element.textContent = "$" + parseNumber(element.textContent);
    });

    document.querySelectorAll(".importeCheque").forEach(element => {
        element.setAttribute("attr-realValue", parseFloat(element.textContent) || 0);
        element.textContent = "$" + parseNumber(element.textContent);
    });

    document.querySelectorAll(".importeDolares").forEach(element => {
        const val = parseFloat(element.textContent) || 0;
        element.setAttribute("attr-realValue", val);
        element.textContent = val > 0 ? "U$S " + parseNumber(val) : "—";
    });

});

const calcularTotales = (checkbox) => {
    let todosLosCheck = document.querySelectorAll(".checkCalcularTotales");
    let totalEfectivo = 0;
    let totalCheque = 0;
    let totalDolares = 0;

    todosLosCheck.forEach(element => {
        if (element.checked) {
            let cells = element.closest('tr').querySelectorAll('td');
            // cells[3]=EFECTIVO, cells[4]=CHEQUES, cells[5]=U$S
            totalEfectivo += parseFloat(cells[3].getAttribute("attr-realValue")) || 0;
            totalCheque   += parseFloat(cells[4].getAttribute("attr-realValue")) || 0;
            totalDolares  += parseFloat(cells[5].getAttribute("attr-realValue")) || 0;
        }
    });

    document.querySelector("#totalEfectivo").textContent = "$ " + parseNumber(totalEfectivo);
    document.querySelector("#totalCheque").textContent   = "$ " + parseNumber(totalCheque);
    document.querySelector("#totalDolares").textContent  = "U$S " + parseNumber(totalDolares);
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