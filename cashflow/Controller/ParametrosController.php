<?php
/**
 * ParametrosController.php
 * Controlador de la pestana Parametros.
 *
 * La pestana es de nivel raiz porque va a ir absorbiendo los parametros de los
 * demas modulos: hoy expone los generales, el mix de cobro y la participacion
 * fija de respaldo del modulo de ventas.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/**
 * Usuario que realiza la edicion.
 * Todavia no hay login: devuelve NULL y se graba NULL. Cuando exista, solo hay
 * que poblar $_SESSION['usuario'].
 * @return string|null Usuario actual
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

/**
 * Lee y decodifica el body JSON del request
 * @return array Datos del POST
 */
function bodyJson() {
    $postData = file_get_contents('php://input');
    $data = json_decode($postData, true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Parametros.php';

    // Obtener accion del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $parametros = new Parametros();

    switch ($action) {
        case 'getTodo':
            // Una sola llamada para pintar la pestana completa
            $mix = $parametros->getMixCobro();

            echo json_encode([
                'success' => true,
                'data' => [
                    'canales' => Parametros::CANALES,
                    'generales' => $parametros->getParametros('GENERAL'),
                    'respaldo' => $parametros->getParametros('RESPALDO'),
                    'mix' => $mix,
                    'mix_validacion' => Parametros::validarMix($mix)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getParametros':
            $grupo = isset($_GET['grupo']) ? $_GET['grupo'] : null;

            echo json_encode([
                'success' => true,
                'data' => $parametros->getParametros($grupo)
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveParametro':
            $data = bodyJson();

            if (!isset($data['clave']) || !isset($data['valor'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $parametros->saveParametro(
                $data['clave'],
                $data['valor'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Parametro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getMixCobro':
            $mix = $parametros->getMixCobro();

            echo json_encode([
                'success' => true,
                'data' => [
                    'mix' => $mix,
                    'mix_validacion' => Parametros::validarMix($mix)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMixCobro':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // Validacion en el servidor: el mix de cada canal debe sumar 100%.
            // Se valida sobre el estado resultante, no sobre lo que manda el
            // cliente sin contrastar.
            $actual = $parametros->getMixCobro();
            $porId = [];

            foreach ($data['filas'] as $fila) {
                if (!isset($fila['id'])) {
                    throw new Exception('Falta el ID de una fila del mix');
                }

                $porId[intval($fila['id'])] = $fila;
            }

            $simulado = [];

            foreach ($actual as $row) {
                if (isset($porId[intval($row['ID'])])) {
                    $row['PORCENTAJE'] = floatval($porId[intval($row['ID'])]['porcentaje']);
                }

                $simulado[] = $row;
            }

            foreach (Parametros::validarMix($simulado) as $canal => $check) {
                if (!$check['valido']) {
                    throw new Exception(
                        'El mix del canal ' . $canal . ' debe sumar 100%. Suma resultante: '
                        . number_format($check['suma'] * 100, 4, ',', '.') . '%'
                    );
                }
            }

            foreach ($data['filas'] as $fila) {
                $parametros->saveMixCobro(
                    $fila['id'],
                    $fila['porcentaje'],
                    $fila['dias_acreditacion'],
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Mix de cobro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveRespaldo':
            // Las participaciones de respaldo deben sumar 100% entre si
            $data = bodyJson();

            if (!isset($data['valores']) || !is_array($data['valores'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $suma = 0;

            foreach (Parametros::CANALES as $canal) {
                $clave = 'respaldo_' . strtolower($canal);

                if (!isset($data['valores'][$clave])) {
                    throw new Exception('Falta la participacion de respaldo de ' . $canal);
                }

                $suma += floatval($data['valores'][$clave]);
            }

            if (abs($suma - 1) >= 0.000001) {
                throw new Exception(
                    'Las participaciones de respaldo deben sumar 100%. Suma recibida: '
                    . number_format($suma * 100, 4, ',', '.') . '%'
                );
            }

            foreach (Parametros::CANALES as $canal) {
                $clave = 'respaldo_' . strtolower($canal);
                $parametros->saveParametro(
                    $clave,
                    (string)floatval($data['valores'][$clave]),
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Participaciones de respaldo guardadas correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ]);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
