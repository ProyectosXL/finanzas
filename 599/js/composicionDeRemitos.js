
const parseNumber = ()=>{

    let allDeuda = document.querySelectorAll(".col-deuda");
    let allCobrado = document.querySelectorAll(".col-cobrado");

    allDeuda.forEach(deuda => {
        const rawValue = parseInt(deuda.getAttribute("attr-realValue") || deuda.textContent);
        if (rawValue === 0) {
            deuda.closest("tr").classList.add("fila-saldada");
            deuda.textContent = "—";
        } else {
            let valor = rawValue.toLocaleString('de-De', {
                style: 'decimal',
                maximumFractionDigits: 0,
                minimumFractionDigits: 0
            });
            deuda.textContent = "$ " + valor;
        }
    });

    allCobrado.forEach(cobrado => {
        let valor = parseInt(cobrado.textContent);
        if (isNaN(valor) || valor === 0) {
            cobrado.textContent = "—";
            cobrado.style.color = "#aaa";
        } else {
            valor = valor.toLocaleString('de-De', {
                style: 'decimal',
                maximumFractionDigits: 0,
                minimumFractionDigits: 0
            });
            cobrado.textContent = "$ " + valor;
        }
    });

}

const verDetalle = (rem) =>{
        
    codClient = rem.parentElement.parentElement.childNodes[0].getAttribute("attr-codClient") ;
    let userName = document.querySelector("#user").textContent


    window.location.href = "remitosPendientesDeCobro.php?codClient="+ encodeURIComponent(codClient) + "&userName="+ userName;
}

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