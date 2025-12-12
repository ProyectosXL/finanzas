
// /599/reporte/js/reporte.js

let datosFiltrados = [];
let datosOriginales = [];

document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips de Bootstrap
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Cargar datos originales
    datosOriginales = [...datosReporte];
    datosFiltrados = [...datosReporte];
    
    // Renderizar tabla y KPIs iniciales
    renderizarTabla(datosFiltrados);
    calcularKPIs(datosFiltrados);
    
    // Event listeners
    document.getElementById('btnAplicarFiltros').addEventListener('click', aplicarFiltros);
    document.getElementById('btnLimpiarFiltros').addEventListener('click', limpiarFiltros);
    document.getElementById('btnExportar').addEventListener('click', exportarExcel);
});

function renderizarTabla(datos) {
    const tbody = document.getElementById('tbodyReporte');
    tbody.innerHTML = '';
    
    if (datos.length === 0) {
        const fila = document.createElement('tr');
        fila.innerHTML = '<td colspan="6" class="text-center">No hay datos disponibles para el período seleccionado</td>';
        tbody.appendChild(fila);
        return;
    }
    
    datos.forEach(registro => {
        const porcentaje = calcularPorcentaje(registro.imp_remitos, registro.imp_venta);
        
        const fila = document.createElement('tr');
        fila.innerHTML = `
            <td>${registro.anio}</td>
            <td>${registro.mes_nombre}</td>
            <td>${formatearDinero(registro.imp_venta)}</td>
            <td>${formatearDinero(registro.imp_remitos)}</td>
            <td><strong>${porcentaje}%</strong></td>
            <td>${formatearUSD(registro.venta_usd)}</td>
        `;
        tbody.appendChild(fila);
    });
}

function calcularKPIs(datos) {
    let totalVenta = 0;
    let totalRemitos = 0;
    let totalUSD = 0;
    
    datos.forEach(registro => {
        totalVenta += parseFloat(registro.imp_venta) || 0;
        totalRemitos += parseFloat(registro.imp_remitos) || 0;
        totalUSD += parseFloat(registro.venta_usd) || 0;
    });
    
    const porcentajeTotal = calcularPorcentaje(totalRemitos, totalVenta);
    
    document.getElementById('kpiVentaTotal').textContent = formatearDinero(totalVenta);
    document.getElementById('kpiRemitosTotal').textContent = formatearDinero(totalRemitos);
    document.getElementById('kpiPorcentajeTotal').textContent = porcentajeTotal + '%';
    document.getElementById('kpiVentaUSD').textContent = formatearUSD(totalUSD);
}

function aplicarFiltros() {
    const mesDesde = parseInt(document.getElementById('mesDesde').value);
    const anioDesde = parseInt(document.getElementById('anioDesde').value);
    const mesHasta = parseInt(document.getElementById('mesHasta').value);
    const anioHasta = parseInt(document.getElementById('anioHasta').value);
    
    datosFiltrados = datosOriginales.filter(registro => {
        const fechaRegistro = parseInt(registro.anio) * 100 + parseInt(registro.mes);
        const fechaDesde = anioDesde * 100 + mesDesde;
        const fechaHasta = anioHasta * 100 + mesHasta;
        
        return fechaRegistro >= fechaDesde && fechaRegistro <= fechaHasta;
    });
    
    renderizarTabla(datosFiltrados);
    calcularKPIs(datosFiltrados);
}

function limpiarFiltros() {
    const anioActual = new Date().getFullYear();
    document.getElementById('mesDesde').value = '1';
    document.getElementById('anioDesde').value = (anioActual - 1).toString();
    document.getElementById('mesHasta').value = '12';
    document.getElementById('anioHasta').value = anioActual.toString();
    
    datosFiltrados = [...datosOriginales];
    renderizarTabla(datosFiltrados);
    calcularKPIs(datosFiltrados);
}

function exportarExcel() {
    $("#tablaReporte").table2excel({
        exclude: ".noExl",
        name: "Reporte de Remitos",
        filename: "ReporteRemitos_" + new Date().toISOString().slice(0,10),
        fileext: ".xls"
    });
}

function formatearDinero(valor) {
    const numero = parseFloat(valor) || 0;
    return '$' + numero.toLocaleString('es-AR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
}

function formatearUSD(valor) {
    const numero = parseFloat(valor) || 0;
    return 'U$S ' + numero.toLocaleString('es-AR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
}

function calcularPorcentaje(remitos, venta) {
    const rem = parseFloat(remitos) || 0;
    const ven = parseFloat(venta) || 0;
    if (ven === 0) return 0;
    return Math.round((rem / ven) * 100);
}