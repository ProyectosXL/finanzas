<?php
/**
 * index.php
 * Informe Economico por Canal.
 *
 * La pagina es una sola: topbar, filtros, solapas y un contenedor al que cada
 * pestana llega por AJAX (Controller/TabController.php), igual que en
 * cashflow. Las solapas salen de Class/Menu.php filtradas por permiso.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/Class/Menu.php';
require_once __DIR__ . '/Class/BaseIE.php';

if (!AuthInformeEconomico::estaAutenticado()) {
    header('Location: https://app.xl.com.ar/sistemas/login.php');
    exit;
}

$u = AuthInformeEconomico::usuario();
$pestanas = Menu::permitidas();
$uruguay = BaseIE::esUruguay();

// El HUB: el mismo destino que el boton de volver de cashflow
$urlHub = '/sistemas/hub/index.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe Económico | XL</title>
    <link rel="icon" type="image/jpeg" href="../images/logo.jpg">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="Css/informe_economico.css?v=<?php echo filemtime(__DIR__ . '/Css/informe_economico.css'); ?>">

    <!-- SheetJS para exportar a Excel, igual que rentabilidad_rubro -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <!-- Chart.js, la misma version que cashflow, para el Dashboard -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="ie-wrapper">

    <?php include __DIR__ . '/Components/topbar.php'; ?>

    <?php if ($uruguay): ?>
        <main class="ie-content">
            <div class="ie-estado">
                <div class="ie-estado-icon"><i class="bi bi-globe-americas"></i></div>
                <h3>No disponible para Uruguay</h3>
                <p>El Informe Económico por Canal funciona por ahora solo para Argentina.
                   En Uruguay faltan la cotización del dólar y un maestro de sucursales propio.</p>
            </div>
        </main>
    <?php elseif (!$pestanas): ?>
        <main class="ie-content">
            <div class="ie-estado">
                <div class="ie-estado-icon"><i class="bi bi-lock"></i></div>
                <h3>Acceso restringido</h3>
                <p>El usuario <strong>@<?php echo htmlspecialchars($u['username']); ?></strong>
                   (rol <?php echo htmlspecialchars($u['rol_nombre']); ?>) no tiene ninguna pestaña
                   habilitada en el Informe Económico. Los accesos se gestionan desde Gestionusuarios.</p>
                <p><a href="<?php echo $urlHub; ?>">Volver al HUB</a></p>
            </div>
        </main>
    <?php else: ?>
        <?php include __DIR__ . '/Components/filtros.php'; ?>
        <?php include __DIR__ . '/Components/tabs.php'; ?>
        <main class="ie-content" id="ieContenido"></main>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/Components/modal_info.php'; ?>
<?php include __DIR__ . '/Components/modal_edicion.php'; ?>

<?php if (!$uruguay && $pestanas): ?>
<script>
    /* Lo que el servidor sabe y el JS necesita. Los permisos se vuelven a
       chequear en cada controller: esto solo decide que se dibuja. */
    window.IE_CONFIG = <?php echo json_encode([
        'pestanas' => $pestanas,
        'puedeEditar' => AuthInformeEconomico::puedeEditar()
    ], JSON_UNESCAPED_UNICODE); ?>;
</script>
<?php foreach (['ie-comun', 'ie-tabla', 'ie-edicion', 'ie-informe', 'ie-dashboard', 'ie-parametros', 'ie-main'] as $js): ?>
<script src="Js/<?php echo $js; ?>.js?v=<?php echo filemtime(__DIR__ . '/Js/' . $js . '.js'); ?>"></script>
<?php endforeach; ?>
<?php else: ?>
<script src="Js/ie-comun.js"></script>
<?php endif; ?>
</body>
</html>
