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
        <div class="header-date">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"><?php echo date('d/m/Y'); ?></span>
        </div>
    </div>
</header>
