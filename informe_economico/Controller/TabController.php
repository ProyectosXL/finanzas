<?php
/**
 * TabController.php
 * Sirve el HTML de una pestana, como el de cashflow.
 *
 * Solo sirve las pestanas que declara Menu, y solo con su permiso: la lista
 * de pestanas validas no se repite aca, sale del mismo dato que dibuja las
 * solapas.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../Class/Menu.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido');
}

$tab = isset($_POST['tab']) ? trim($_POST['tab']) : '';
$def = Menu::buscar($tab);

if ($def === null) {
    http_response_code(400);
    exit('Pestaña no válida');
}

if (!AuthInformeEconomico::puede($def['permiso'])) {
    http_response_code(403);
    echo '<div class="ie-estado"><div class="ie-estado-icon"><i class="bi bi-lock"></i></div>'
        . '<h3>Acceso restringido</h3><p>Tu usuario no tiene el permiso <code>'
        . htmlspecialchars($def['permiso']) . '</code> para esta pestaña.</p></div>';
    exit;
}

$puedeEditar = AuthInformeEconomico::puedeEditar();

include __DIR__ . '/../Tabs/' . $tab . '.php';
