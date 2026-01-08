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
}
