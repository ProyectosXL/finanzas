<?php
/**
 * AyudaController
 * Los endpoints de la ayuda funcional: el panel lateral de una pestana, el
 * Centro de ayuda, un articulo completo y la busqueda.
 *
 * TODO ES LECTURA, y de todos. Las cuatro acciones estan en
 * AuthCashflow::LECTURAS; lo unico que se exige es un usuario identificado
 * (autorizarLectura('ayuda'), que es de todos: AuthCashflow::PARA_TODOS). No
 * hay escrituras: los articulos son archivos del repo y no se editan desde la
 * app.
 *
 * Los articulos se leen del disco en cada pedido y no se guardan en ningun
 * lado: son pocos y chicos, y asi un articulo editado se ve en el proximo
 * pedido sin regenerar nada. La medicion esta en README-cashflow.md.
 */

header('Content-Type: application/json; charset=utf-8');

function responder($data) {
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
}

try {
    require_once __DIR__ . '/../Class/Ayuda.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    require_once __DIR__ . '/autorizacion.php';
    autorizar('Ayuda', $action);

    // autorizar() ya rechazo cualquier accion que no este en el mapa; las que
    // quedan son las cuatro lecturas, y piden un usuario identificado.
    autorizarLectura('ayuda');

    // Nada de esto escribe en la sesion: se libera para no frenar los pedidos
    // de la pestana que esta abierta detras del panel.
    AuthCashflow::liberarSesion();

    $menu = Menu::estructuraCompleta();
    $tabs = Ayuda::categoriasDeTabs($menu);
    $hoy = date('Y-m-d');

    switch ($action) {
        case 'getPanel':
            responder(Ayuda::panel((string) ($_GET['tab'] ?? ''), $menu, Ayuda::cargar(), $tabs,
                (string) ($_GET['q'] ?? ''), $hoy));
            break;

        case 'getCentro':
            responder(Ayuda::centro($menu, Ayuda::cargar()));
            break;

        case 'getArticulo':
            $art = Ayuda::completo((string) ($_GET['nombre'] ?? ''), Ayuda::cargar(), $tabs, $hoy);

            if ($art === null) {
                throw new InvalidArgumentException('Ese artículo de la ayuda no existe.');
            }

            responder($art);
            break;

        case 'buscar':
            $articulos = Ayuda::cargar();
            responder(AyudaBusqueda::buscar(AyudaBusqueda::indice($articulos), (string) ($_GET['q'] ?? '')));
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
