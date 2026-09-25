<?php
/**
 * TabController.php
 * Controlador para cargar tabs dinámicamente via AJAX
 */

require_once __DIR__ . '/../Class/AuthCashflow.php';

// Validar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

// Obtener el tab solicitado
$tab = isset($_POST['tab']) ? trim($_POST['tab']) : '';

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

// Validar permisos del usuario para la solapa
if (!AuthCashflow::puede($tab)) {
    $u = AuthCashflow::usuario();
    $rolNombre = $u['rol_nombre'] ?? 'Sin Rol';
    $uname = $u['username'] ?? 'Usuario';
    http_response_code(403);
    echo '<div class="p-5 text-center bg-white rounded-4 border my-4 shadow-sm">
        <div class="mb-3">
            <i class="fas fa-lock text-danger" style="font-size: 2.5rem;"></i>
        </div>
        <h4 class="h5 fw-bold text-dark mb-2">Acceso Restringido a esta Sección</h4>
        <p class="text-muted small mb-0">Tu usuario (<strong>@' . htmlspecialchars($uname) . '</strong> - Rol: <strong>' . htmlspecialchars($rolNombre) . '</strong>) no tiene asignado el permiso para acceder a esta solapa en el Flujo de Fondos.</p>
    </div>';
    exit;
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
