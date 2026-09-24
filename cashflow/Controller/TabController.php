<?php
/**
 * TabController.php
 * Controlador para cargar tabs dinámicamente via AJAX
 */

// Validar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

// Obtener el tab solicitado
$tab = isset($_POST['tab']) ? $_POST['tab'] : '';

// Lista de tabs válidos
$validTabs = [
    'cashflow',
    'dashboard',
    'parametros',
    'ventas',
    'saldos',
    'echeqs',
    'cobranzas_fr',
    'cobranzas_may',
    'cob_electronicos',
    'exportaciones_tasky',
    'proveedores_exterior',
    'crono_nacionalizacion',
    'compras_proyectadas',
    'proveedores_locales',
    'logistica_local',
    'haberes',
    'impuestos',
    'alquileres',
    'llaves_renov',
    'pagos_tarjetas',
    'otros_socios',
    'prestamos'
];

// Validar tab
if (!in_array($tab, $validTabs)) {
    http_response_code(400);
    exit('Tab no válido');
}

// Ruta al archivo del tab
$tabFile = __DIR__ . '/../Tabs/' . $tab . '.php';

// Verificar que el archivo existe
if (file_exists($tabFile)) {
    include $tabFile;
} else {
    // Si no existe, mostrar placeholder
    $tabName = ucfirst(str_replace('_', ' ', $tab));
    include __DIR__ . '/../Components/tab_placeholder.php';
}
