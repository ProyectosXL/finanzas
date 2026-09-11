/**
 * Main JavaScript - Flujo de Fondos
 * Maneja la navegación entre tabs y carga dinámica de contenido
 */

$(document).ready(function() {

    // Inicializar navegación de tabs
    initTabNavigation();

    // Header fijo de las tablas con eje temporal
    initStickyHeaders();

});

/**
 * Header fijo (sticky) de las tablas con eje temporal.
 *
 * El CSS de .tabla-temporal hace el trabajo, pero la segunda fila del thead
 * necesita saber el alto REAL de la primera para posicionarse debajo, y ese
 * alto depende del contenido (Crono Nacionalización tiene encabezados de dos
 * palabras, Ventas de una). Acá se mide y se publica como custom property.
 *
 * Se observa #tabContent porque las tablas se generan por AJAX después de que
 * el tab ya está cargado: así no hay que tocar el JS de cada pestaña.
 */
function initStickyHeaders() {
    ajustarStickyHeaders();

    var contenedor = document.getElementById('tabContent');

    if (contenedor && window.MutationObserver) {
        var pendiente = null;

        var observer = new MutationObserver(function() {
            clearTimeout(pendiente);
            pendiente = setTimeout(ajustarStickyHeaders, 60);
        });

        observer.observe(contenedor, { childList: true, subtree: true });
    }

    window.addEventListener('resize', function() {
        ajustarStickyHeaders();
    });
}

/**
 * Mide cada tabla temporal y publica:
 *   --thead-row1-height : alto real de la primera fila del thead
 *   --col-fija-N-left   : desplazamiento horizontal de cada columna fija
 *
 * El desplazamiento de una columna fija es la SUMA de los anchos de las fijas
 * anteriores, y hay que medirlo: los anchos los reparte el navegador según el
 * contenido, así que un valor escrito en el CSS se desalinea en cuanto una
 * razón social es más larga de lo previsto. Se acumula sobre el ancho real
 * medido y no sobre un `min-width`, por eso mismo.
 *
 * Quién lleva la clase .col-fija-N lo decide Js/columnas-fijas.js. Acá sólo se
 * mide lo que ya está marcado.
 */
function ajustarStickyHeaders() {
    // Las marcas primero: si la tabla se acaba de redibujar, sus celdas nuevas
    // todavía no tienen clase y no habría nada que medir.
    if (window.ColumnasFijas) {
        window.ColumnasFijas.reaplicar();
    }

    var contenedores = document.querySelectorAll('.tabla-temporal');

    contenedores.forEach(function(cont) {
        var tabla = cont.querySelector('table');

        if (!tabla || !tabla.tHead) {
            return;
        }

        var filas = tabla.tHead.rows;

        // Alto de la primera fila: se mide como la distancia hasta la segunda,
        // porque las celdas con rowspan="2" abarcan las dos filas y falsearían
        // un offsetHeight directo.
        if (filas.length > 1) {
            var alto = filas[1].getBoundingClientRect().top -
                       filas[0].getBoundingClientRect().top;

            if (alto > 0) {
                cont.style.setProperty('--thead-row1-height', Math.round(alto) + 'px');
            }
        }

        medirColumnasFijas(tabla);
    });
}

/**
 * Publica --col-fija-1-left … --col-fija-N-left sobre la tabla.
 *
 * Van sobre la <table> y no sobre el contenedor porque una pestaña puede tener
 * más de una tabla adentro del mismo wrapper, y cada una tiene sus propios
 * anchos. Las custom properties se heredan, así que las celdas las ven igual.
 *
 * @param {HTMLTableElement} tabla
 */
function medirColumnasFijas(tabla) {
    var cabecera = tabla.tHead.rows[0];

    if (!cabecera) {
        return;
    }

    var tope = window.ColumnasFijas ? window.ColumnasFijas.MAXIMO : 4;
    var acumulado = 0;

    for (var n = 1; n <= tope; n++) {
        var celda = cabecera.querySelector('.col-fija-' + n);

        if (!celda) {
            // Una columna que no está fija no aporta desplazamiento, pero su
            // variable se limpia igual: si quedara el valor de una selección
            // anterior, al volver a fijarla arrancaría corrida.
            tabla.style.removeProperty('--col-fija-' + n + '-left');
            continue;
        }

        tabla.style.setProperty('--col-fija-' + n + '-left', Math.round(acumulado) + 'px');
        acumulado += celda.getBoundingClientRect().width;
    }
}

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
 * Actualiza el header con el nombre del tab actual.
 *
 * El título sale del enlace del menú (data-encabezado, que pone
 * Components/sidebar.php a partir de Class/Menu.php). Antes había acá una
 * segunda lista de nombres escrita a mano, y se desactualizaba sola: cada
 * pestaña nueva aparecía en el encabezado con su código y guión bajo.
 *
 * @param {string} tabName - Código del tab
 */
function updateHeader(tabName) {
    const link = $('.menu-link[data-tab="' + tabName + '"]').first();
    const encabezado = link.length ? link.data('encabezado') : '';

    // Sin enlace en el menú (una pestaña a la que se llega por código, como
    // nacionalizacion_2) se humaniza el código, que es mejor que el crudo.
    const title = encabezado || tabName.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());

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
 * Pide JSON a un controller y devuelve directamente el payload.
 *
 * Todos los endpoints del módulo responden {success, data|message}. Esta
 * función desenvuelve ese sobre: quien la llama recibe el dato o una excepción
 * con el mensaje del servidor.
 *
 * Lee .text() y después JSON.parse a propósito: si PHP emite un fatal, la
 * respuesta es HTML y no JSON, y así el error que llega es legible en lugar de
 * un "Unexpected token <".
 *
 * NOTA: Ingresos-Ventas.js y Parametros.js tienen cada uno su propia copia
 * local de esto, anterior a esta función. Quedan como están para no tocar dos
 * pestañas que funcionan; el código nuevo usa ésta, y la limpieza de las dos
 * copias es un cambio aparte.
 *
 * @param {string} url - URL relativa a cashflow/
 * @param {Object} [body] - Si se pasa, va como POST con cuerpo JSON
 * @returns {Promise<any>} El contenido de 'data'
 */
function pedirJson(url, body) {
    var opciones = {};

    if (body) {
        opciones = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        };
    }

    return fetch(url, opciones)
        .then(function(r) {
            if (!r.ok) {
                throw new Error('Error HTTP: ' + r.status);
            }

            return r.text();
        })
        .then(function(texto) {
            var resultado;

            try {
                resultado = JSON.parse(texto);
            } catch (e) {
                console.error('Respuesta no JSON:', texto);
                throw new Error('Respuesta inválida del servidor. Revisá la consola.');
            }

            if (!resultado.success) {
                console.error('Error del servidor:', resultado);
                throw new Error(resultado.message || 'Error desconocido');
            }

            return resultado.data;
        });
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
