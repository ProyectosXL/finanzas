/**
 * Main JavaScript - Flujo de Fondos
 * Maneja la navegación entre tabs y carga dinámica de contenido
 */

$(document).ready(function() {
    
    // Inicializar navegación de tabs
    initTabNavigation();
    
});

/**
 * Inicializa la navegación entre tabs
 */
function initTabNavigation() {
    
    // Click en links del menú
    $('.menu-link').on('click', function(e) {
        e.preventDefault();
        
        const tabName = $(this).data('tab');
        
        if (tabName) {
            loadTab(tabName);
            
            // Actualizar estado activo en el menú
            $('.menu-link').removeClass('active');
            $(this).addClass('active');
            
            // Cerrar sidebar en móvil
            if ($(window).width() <= 992) {
                closeSidebar();
            }
        }
    });
}

/**
 * Carga un tab dinámicamente
 * @param {string} tabName - Nombre del tab a cargar
 */
function loadTab(tabName) {
    
    // Mostrar loader
    $('#tabContent').html(`
        <div class="d-flex justify-content-center align-items-center" style="min-height: 300px;">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `);
    
    // Cargar contenido via AJAX
    $.ajax({
        url: 'Controller/TabController.php',
        type: 'POST',
        data: { tab: tabName },
        success: function(response) {
            $('#tabContent').html(response);
            
            // Actualizar título y breadcrumb
            updateHeader(tabName);
            
            // Reinicializar componentes si es necesario
            initComponents();
        },
        error: function() {
            $('#tabContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error al cargar el contenido. Intente nuevamente.
                </div>
            `);
        }
    });
}

/**
 * Actualiza el header con el nombre del tab actual
 * @param {string} tabName - Nombre del tab
 */
function updateHeader(tabName) {
    const titles = {
        'resumen': 'Resumen',
        'dashboard': 'Dashboard',
        'ventas': 'Ventas',
        'saldos': 'Saldos',
        'echeqs': 'Echeqs',
        'cobranzas_fr': 'Cobranzas FR',
        'cobranzas_may': 'Cobranzas May',
        'cob_electronicos': 'Cobranzas Electrónicos',
        'proveedores_exterior': 'Proveedores Exterior',
        'crono_nacionalizacion': 'Crono Nacionalización',
        'nacionalizacion_2': 'Nacionalización (2)',
        'despachante_asesor': 'Despachante y Asesor',
        'proveedores_locales': 'Proveedores Locales',
        'cronograma': 'Cronograma',
        'logistica_local': 'Logística Local',
        'haberes': 'Haberes',
        'impuestos': 'Impuestos',
        'alquileres': 'Alquileres',
        'seguros': 'Seguros',
        'llaves_renov': 'Llaves y Renov. Contratos',
        'pagos_tarjetas': 'Pagos con Tarjetas y Otros',
        'otros_socios': 'Otros Socios y No Prog.',
        'bopreal': 'Bopreal',
        'prestamos': 'Préstamos',
        'pagos_div': 'Pagos Div. Marzo'
    };
    
    const title = titles[tabName] || tabName;
    
    $('#pageTitle').text(title);
    $('#breadcrumbCurrent').text(title);
}

/**
 * Inicializa componentes después de cargar un tab
 */
function initComponents() {
    // DataTables
    if ($.fn.DataTable) {
        $('.datatable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
            },
            pageLength: 25,
            responsive: true
        });
    }
    
    // Select2
    if ($.fn.select2) {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    }
    
    // Tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
}

/**
 * Formatea números como moneda
 * @param {number} value - Valor a formatear
 * @param {string} currency - Moneda (ARS, USD)
 * @returns {string} Valor formateado
 */
function formatCurrency(value, currency = 'ARS') {
    const options = {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2
    };
    
    return new Intl.NumberFormat('es-AR', options).format(value);
}

/**
 * Formatea números con separador de miles
 * @param {number} value - Valor a formatear
 * @returns {string} Valor formateado
 */
function formatNumber(value) {
    return new Intl.NumberFormat('es-AR').format(value);
}
