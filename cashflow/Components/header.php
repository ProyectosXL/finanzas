<header class="content-header">
    <div class="header-left">
        <h1 class="page-title" id="pageTitle"><?php echo htmlspecialchars($tituloInicial ?? 'Cashflow'); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <!-- La raiz es el modulo. Antes decia "Cashflow", que ahora es
                     el nombre de la pestana principal y quedaria repetido. -->
                <li class="breadcrumb-item"><a href="#">Flujo de Fondos</a></li>
                <li class="breadcrumb-item active" id="breadcrumbCurrent"><?php echo htmlspecialchars($tituloInicial ?? 'Cashflow'); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-right">
        <!-- La ayuda de la pestaña abierta, en un panel lateral
             (Components/ayuda_panel.php, Js/ayuda.js). Uno solo para todas:
             sabe cuál está abierta por el data-tab del <body>, que actualiza
             loadTab(). En el Centro de ayuda no se muestra (Css/ayuda.css). -->
        <button type="button" class="btn btn-sm btn-outline-primary btn-ayuda-pestana" id="btnAyudaPestana"
                aria-controls="ayudaPanel" title="Ayuda de esta pestaña">
            <i class="fas fa-circle-question"></i><span>Ayuda</span>
        </button>
        <div class="header-date">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"><?php echo date('d/m/Y'); ?></span>
        </div>
    </div>
</header>
