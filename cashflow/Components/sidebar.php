<!-- Sidebar Toggle Button (visible when collapsed) -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay (for mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <i class="fas fa-chart-line"></i>
            <span class="brand-text">Flujo de Fondos</span>
        </div>
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Contraer sidebar">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="sidebar-menu">
        <!-- Resumen - Siempre visible destacado -->
        <div class="menu-item-main">
            <a href="#" class="menu-link active" data-tab="resumen">
                <i class="fas fa-tachometer-alt"></i>
                <span>Resumen</span>
            </a>
        </div>

        <!-- Dashboard -->
        <div class="menu-item-main">
            <a href="#" class="menu-link" data-tab="dashboard">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- Parámetros - Nivel raíz: va absorbiendo los parámetros de todos los módulos -->
        <div class="menu-item-main">
            <a href="#" class="menu-link" data-tab="parametros">
                <i class="fas fa-sliders"></i>
                <span>Parámetros</span>
            </a>
        </div>

        <div class="menu-divider"></div>

        <!-- Categoría: Ingresos -->
        <div class="menu-category">
            <a href="#" class="category-header" data-bs-toggle="collapse" data-bs-target="#menuIngresos">
                <div class="category-title">
                    <i class="fas fa-arrow-trend-up"></i>
                    <span>Ingresos</span>
                </div>
                <i class="fas fa-chevron-down category-arrow"></i>
            </a>
            <div class="collapse" id="menuIngresos">
                <ul class="category-items">
                    <li><a href="#" class="menu-link" data-tab="ventas"><span>Ventas</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="saldos"><span>Saldos</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="echeqs"><span>Echeqs</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="cobranzas_fr"><span>Cobranzas FR</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="cobranzas_may"><span>Cobranzas May</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="cob_electronicos"><span>Cob. Electrónicos</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Categoría: Comercio Exterior -->
        <div class="menu-category">
            <a href="#" class="category-header collapsed" data-bs-toggle="collapse" data-bs-target="#menuComex">
                <div class="category-title">
                    <i class="fas fa-ship"></i>
                    <span>Comercio Exterior</span>
                </div>
                <i class="fas fa-chevron-down category-arrow"></i>
            </a>
            <div class="collapse" id="menuComex">
                <ul class="category-items">
                    <li><a href="#" class="menu-link" data-tab="proveedores_exterior"><span>Proveedores Exterior</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="crono_nacionalizacion"><span>Crono Nacionalización</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="despachante_asesor"><span>Despachante y Asesor</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Categoría: Proveedores -->
        <div class="menu-category">
            <a href="#" class="category-header collapsed" data-bs-toggle="collapse" data-bs-target="#menuProveedores">
                <div class="category-title">
                    <i class="fas fa-truck"></i>
                    <span>Proveedores</span>
                </div>
                <i class="fas fa-chevron-down category-arrow"></i>
            </a>
            <div class="collapse" id="menuProveedores">
                <ul class="category-items">
                    <li><a href="#" class="menu-link" data-tab="proveedores_locales"><span>Proveedores Locales</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="cronograma"><span>Cronograma</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="logistica_local"><span>Logística Local</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Categoría: RRHH y Operativos -->
        <div class="menu-category">
            <a href="#" class="category-header collapsed" data-bs-toggle="collapse" data-bs-target="#menuRRHH">
                <div class="category-title">
                    <i class="fas fa-users"></i>
                    <span>RRHH y Operativos</span>
                </div>
                <i class="fas fa-chevron-down category-arrow"></i>
            </a>
            <div class="collapse" id="menuRRHH">
                <ul class="category-items">
                    <li><a href="#" class="menu-link" data-tab="haberes"><span>Haberes</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="impuestos"><span>Impuestos</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="alquileres"><span>Alquileres</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="seguros"><span>Seguros</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="llaves_renov"><span>Llaves y Renov. Contratos</span></a></li>
                </ul>
            </div>
        </div>

        <!-- Categoría: Financiero -->
        <div class="menu-category">
            <a href="#" class="category-header collapsed" data-bs-toggle="collapse" data-bs-target="#menuFinanciero">
                <div class="category-title">
                    <i class="fas fa-landmark"></i>
                    <span>Financiero</span>
                </div>
                <i class="fas fa-chevron-down category-arrow"></i>
            </a>
            <div class="collapse" id="menuFinanciero">
                <ul class="category-items">
                    <li><a href="#" class="menu-link" data-tab="pagos_tarjetas"><span>Pagos con Tarjetas y Otros</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="otros_socios"><span>Otros Socios y No Prog.</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="bopreal"><span>Bopreal</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="prestamos"><span>Préstamos</span></a></li>
                    <li><a href="#" class="menu-link" data-tab="pagos_div"><span>Pagos Div. Marzo</span></a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="sidebar-footer">
        <span class="version-text">v1.0.0</span>
    </div>
</nav>
