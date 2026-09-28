<?php
/**
 * comun.php
 * Lo que comparten los controllers JSON del modulo: sesion, respuesta y la
 * guarda de permisos.
 *
 * Los permisos se chequean ACA, en el servidor, y no solo escondiendo botones:
 * sin 'ie.editar' la edicion no se dibuja, pero si alguien arma el pedido a
 * mano el controller responde 403 igual.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../Class/AuthInformeEconomico.php';
require_once __DIR__ . '/../Class/BaseIE.php';

function ieResponder($data, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ieError($mensaje, $codigo = 400) {
    ieResponder(['ok' => false, 'message' => $mensaje], $codigo);
}

/** Corta con 401/403 si no hay sesion o falta alguno de los permisos */
function ieExigir(...$permisos) {
    if (!AuthInformeEconomico::estaAutenticado()) {
        ieError('La sesión expiró. Volvé a ingresar.', 401);
    }

    foreach ($permisos as $p) {
        if (!AuthInformeEconomico::puede($p)) {
            ieError('Tu usuario no tiene el permiso ' . $p . '.', 403);
        }
    }
}

/**
 * La conexion del informe. En Uruguay no se consulta nada: el informe todavia
 * no esta habilitado alla (ver BaseIE::esUruguay()).
 */
function ieConexion() {
    if (BaseIE::esUruguay()) {
        ieResponder(['ok' => false, 'uruguay' => true,
            'message' => 'El Informe Económico todavía no está disponible para Uruguay.']);
    }

    try {
        return BaseIE::conectar('central');
    } catch (Throwable $t) {
        ieError($t->getMessage(), 500);
    }
}

function iePost($k, $def = '') {
    return isset($_POST[$k]) ? $_POST[$k] : $def;
}
