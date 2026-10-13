/**
 * Sidebar JavaScript - Flujo de Fondos
 * Maneja la funcionalidad del sidebar colapsable
 */

$(document).ready(function() {
    
    initSidebar();
    
    // Restaurar estado del sidebar desde localStorage
    if ($(window).width() > 992) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            $('#sidebar').addClass('collapsed');
            $('#mainContent').addClass('sidebar-collapsed');
        }
    }

    // Si arranca contraido, los nombres tienen que aparecer desde el primer
    // hover, no recien despues de expandir y volver a contraer.
    sincronizarTooltipsMenu();
    
});

/**
 * Inicializa el sidebar
 */
function initSidebar() {
    
    const sidebar = $('#sidebar');
    const sidebarToggle = $('#sidebarToggle');
    const sidebarClose = $('#sidebarClose');
    const sidebarCollapseBtn = $('#sidebarCollapseBtn');
    const sidebarOverlay = $('#sidebarOverlay');
    const mainContent = $('#mainContent');
    
    // Toggle sidebar (móvil)
    sidebarToggle.on('click', function() {
        openSidebar();
    });
    
    // Close sidebar (móvil)
    sidebarClose.on('click', function() {
        closeSidebar();
    });
    
    // Collapse/Expand sidebar (desktop)
    sidebarCollapseBtn.on('click', function() {
        toggleSidebarCollapse();
    });
    
    // Expandir sidebar al hacer clic en el ícono cuando está colapsado
    sidebar.find('.sidebar-brand i').on('click', function() {
        if (sidebar.hasClass('collapsed') && $(window).width() > 992) {
            toggleSidebarCollapse();
        }
    });
    
    // Click en overlay cierra sidebar
    sidebarOverlay.on('click', function() {
        closeSidebar();
    });
    
    // Manejar resize de ventana
    $(window).on('resize', function() {
        if ($(window).width() > 992) {
            sidebar.removeClass('active');
            sidebarOverlay.removeClass('active');
            mainContent.removeClass('expanded');
        } else {
            sidebar.removeClass('collapsed');
            mainContent.removeClass('sidebar-collapsed');
        }

        sincronizarTooltipsMenu();
    });
    
    // Keyboard shortcut (Ctrl + B) para toggle
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            if ($(window).width() <= 992) {
                if (sidebar.hasClass('active')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            } else {
                toggleSidebarCollapse();
            }
        }
    });
}

/**
 * Abre el sidebar
 */
function openSidebar() {
    $('#sidebar').addClass('active');
    $('#sidebarOverlay').addClass('active');
    $('body').css('overflow', 'hidden');
}

/**
 * Cierra el sidebar
 */
function closeSidebar() {
    $('#sidebar').removeClass('active');
    $('#sidebarOverlay').removeClass('active');
    $('body').css('overflow', '');
}

/**
 * Toggle colapsar/expandir sidebar (desktop)
 */
function toggleSidebarCollapse() {
    const sidebar = $('#sidebar');
    const mainContent = $('#mainContent');
    
    sidebar.toggleClass('collapsed');
    mainContent.toggleClass('sidebar-collapsed');
    
    // Guardar preferencia en localStorage
    if (sidebar.hasClass('collapsed')) {
        localStorage.setItem('sidebarCollapsed', 'true');
    } else {
        localStorage.setItem('sidebarCollapsed', 'false');
    }

    // Lo llaman el boton, el icono de la marca y Ctrl+B: los tres pasan por aca.
    sincronizarTooltipsMenu();
}

/**
 * Los nombres del menu CONTRAIDO: un tooltip a la derecha de cada icono, con el
 * nombre de la pestaña -y su estado, si tiene: "Proveedores Locales ·
 * Pendiente"- y el de cada categoria. El texto lo arma el servidor
 * (Menu::tooltip(), en data-menu-tooltip): aca solo se prende y se apaga.
 *
 * SOLO CON EL MENU CONTRAIDO. Expandido, el nombre ya se lee y un cartel con lo
 * mismo seria ruido; ahi queda el title del estado, como siempre. Por eso el
 * tooltip se CREA al contraer y se DESCARTA al expandir, en vez de prenderlo y
 * apagarlo: al crearse, Bootstrap guarda el title nativo del estado -si no, se
 * verian los dos carteles juntos- y al descartarse lo devuelve.
 *
 * container: 'body' porque el sidebar contraido tiene overflow: hidden y
 * cortaria el tooltip contra su borde. En movil (<= 992 px) el menu no se
 * contrae, se abre encima: no hay nada que nombrar.
 *
 * Lo llaman la carga de la pagina -por si arranca contraido desde
 * localStorage-, toggleSidebarCollapse() -el boton y Ctrl+B- y el resize.
 */
function sincronizarTooltipsMenu() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
        return;
    }

    var contraido = $('#sidebar').hasClass('collapsed') && $(window).width() > 992;

    document.querySelectorAll('#sidebar [data-menu-tooltip]').forEach(function(el) {
        var actual = bootstrap.Tooltip.getInstance(el);

        if (contraido && !actual) {
            new bootstrap.Tooltip(el, {
                title: el.getAttribute('data-menu-tooltip'),
                placement: 'right',
                container: 'body',
                trigger: 'hover'
            });
        } else if (!contraido && actual) {
            actual.dispose();
        }
    });
}
