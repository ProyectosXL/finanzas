<?php
/**
 * La guarda de escritura de todos los controllers del modulo.
 *
 * Cada controller la llama UNA vez, apenas lee $action y antes del switch:
 *
 *     $usuario = autorizar('Saldos', $action);
 *
 * y despues usa $usuario en cada escritura. Lo que decide que permiso pide cada
 * accion es el mapa de AuthCashflow (ESCRITURAS / LECTURAS), no el controller.
 *
 * POR QUE ANTES DEL SWITCH Y NO EN CADA case: un case nuevo que escribe no
 * puede olvidarse de pedir permiso, porque no es el case el que lo pide. Si
 * nadie lo declaro en el mapa, la accion se rechaza (403) y
 * tests/test_auth_cashflow.php falla.
 *
 * Y POR QUE ACA Y NO EN EL catch DE CADA CONTROLLER: los catorce tienen su
 * propio try/catch, que contesta 200 con success = false. Una negativa de
 * permisos tiene que salir con su codigo HTTP -401 o 403- para que el front y
 * cualquier herramienta la distingan de un error de validacion, y repetir esa
 * traduccion en catorce catch es la forma segura de que uno quede distinto.
 */

require_once __DIR__ . '/../Class/AuthCashflow.php';

/**
 * Exige lo que el mapa pide para esta accion.
 *
 * Si la accion solo lee devuelve null y no pide nada. Si escribe, devuelve el
 * username del padron, que es lo que el controller pasa a la clase: el usuario
 * NUNCA sale del request.
 *
 * Sin usuario corta con 401; sin permiso, con 403. En los dos casos el cuerpo
 * es el mismo JSON que el resto del modulo: {success: false, message}.
 *
 * @param string $controlador Nombre del controller sin "Controller"
 * @param string $accion
 * @param string|null $sub Sub-pestaña ya resuelta, para las acciones del mapa
 *                         declaradas con AuthCashflow::SUB_DEL_PARAMETRO
 * @return string|null
 */
function autorizar($controlador, $accion, $sub = null) {
    try {
        return AuthCashflow::exigirAccion($controlador, $accion, $sub);
    } catch (AuthCashflowSinUsuario $e) {
        responderSinAutorizacion(401, $e->getMessage());
    } catch (AuthCashflowSinPermiso $e) {
        responderSinAutorizacion(403, $e->getMessage());
    }

    return null;
}

/**
 * Corta el request con el codigo y el mensaje.
 *
 * @param int $codigo
 * @param string $mensaje
 */
function responderSinAutorizacion($codigo, $mensaje) {
    http_response_code($codigo);

    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    echo json_encode([
        'success' => false,
        'message' => $mensaje
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
