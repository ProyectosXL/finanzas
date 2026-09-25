<?php
session_start();
require_once __DIR__ . '/Class/AuthCashflow.php';

// Redirección si no hay sesión
if (!AuthCashflow::estaAutenticado()) {
    header('Location: https://app.xl.com.ar/sistemas/login.php');
    exit;
}

$u = AuthCashflow::usuario();
$tabsPermitidos = AuthCashflow::tabsPermitidos();

// Si el usuario no tiene permisos para ninguna pestaña ni es admin
if (empty($tabsPermitidos)) {
    http_response_code(403);
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso Restringido · Cashflow</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center" style="max-width: 480px;">
            <div class="mb-3 text-danger"><i class="fas fa-lock fa-3x"></i></div>
            <h4 class="fw-bold text-dark mb-2">Acceso No Autorizado</h4>
            <p class="text-muted small mb-4">El usuario <strong>@' . htmlspecialchars($u['username']) . '</strong> (Rol: ' . htmlspecialchars($u['rol_nombre']) . ') no tiene solapas habilitadas en el <strong>Flujo de Fondos</strong>.</p>
            <a href="/sistemas/hub/index.php" class="btn btn-primary rounded-pill px-4">Volver al Hub</a>
        </div>
    </body>
    </html>';
    exit;
}

$tabInicial = AuthCashflow::primeraTabPermitida('cashflow');
$tabInicialFile = __DIR__ . '/Tabs/' . $tabInicial . '.php';
require_once __DIR__ . '/Class/Menu.php';
$tituloInicial = Menu::tituloTab($tabInicial);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tituloInicial); ?> | Flujo de Fondos | XL</title>
    <link rel="icon" type="image/jpeg" href="../images/logo.jpg">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="Css/sidebar.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="Css/main.css?v=<?php echo time(); ?>" rel="stylesheet">
    <!-- Notificaciones de acción (Js/notificaciones.js). Va acá y no en una
         pestaña porque su contenedor cuelga de <body> y tiene que sobrevivir al
         reemplazo de #tabContent. -->
    <link href="Css/notificaciones.css?v=<?php echo time(); ?>" rel="stylesheet">
    <!-- El indicador de carga de todas las pestañas (Js/cargando.js). Una sola
         hoja: antes cada pestaña traía su copia, y las que no la traían se
         veían bien solo si antes se había abierto otra. -->
    <link href="Css/cargando.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>
<body>
  
    <!-- Sidebar -->
    <?php include 'Components/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <?php include 'Components/header.php'; ?>
        
        <!-- Content Area -->
        <div class="content-area">
            <div id="tabContent">
                <?php 
                if (file_exists($tabInicialFile)) {
                    include $tabInicialFile;
                } else {
                    include __DIR__ . '/Tabs/cashflow.php';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- Custom JS -->
    <script src="Js/sidebar.js"></script>
    <!-- El indicador de carga. Va ANTES de main.js, que lo usa en loadTab(), y
         antes que cualquier pestaña: todas lo llaman al empezar a cargar. -->
    <script src="Js/cargando.js?v=<?php echo time(); ?>"></script>
    <script src="Js/main.js"></script>
    <!-- Notificaciones de una acción del usuario: reemplazan a alert() y
         confirm(). Van acá por el mismo motivo que eje-vistas.js: las pestañas
         se cargan por AJAX y esto tiene que existir antes que ellas. -->
    <script src="Js/notificaciones.js?v=<?php echo time(); ?>"></script>
    <!-- Las tres vistas del eje temporal (Días / Meses / Período completo).
         Va acá y no en cada pestaña porque lo usan todas las que tienen eje, y
         las pestañas se cargan por AJAX. -->
    <script src="Js/eje-vistas.js?v=<?php echo time(); ?>"></script>
    <!-- Qué columnas descriptivas quedan fijas al scrollear a lo ancho. Mismo
         motivo que los dos de arriba: es compartido y las pestañas llegan por
         AJAX. Va DESPUÉS de main.js, que lo consulta al medir. -->
    <script src="Js/columnas-fijas.js?v=<?php echo time(); ?>"></script>
    <!-- Ordenar la tabla clickeando el encabezado, y exportarla a Excel. Mismo
         motivo que los de arriba: los usan todas las pestañas con tabla, y las
         pestañas llegan por AJAX, así que tienen que existir antes que ellas. -->
    <script src="Js/tabla-orden.js?v=<?php echo time(); ?>"></script>
    <script src="Js/tabla-export.js?v=<?php echo time(); ?>"></script>
</body>
</html>
