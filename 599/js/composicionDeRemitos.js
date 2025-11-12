
const parseNumber = ()=>{

    let allDeuda = document.querySelectorAll("#colDeuda");
    let allCobrado = document.querySelectorAll("#colCobrado");

    allDeuda.forEach(deuda => {
        let valor = parseInt(deuda.textContent);
        valor = valor.toLocaleString('de-De', {
            style: 'decimal',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        });
        deuda.textContent = "$ "+valor;
    });

    allCobrado.forEach(cobrado => {
        let valor = parseInt(cobrado.textContent);
        valor = valor.toLocaleString('de-De', {
            style: 'decimal',
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        });
        cobrado.textContent = "$ "+valor;
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