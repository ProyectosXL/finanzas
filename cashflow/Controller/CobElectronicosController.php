<?php
/**
 * CobElectronicosController.php
 * Controlador de la pestana Cob. Electronicos: acreditaciones de las
 * procesadoras de pago.
 *
 * NI EL NETO NI LA TASA SE ACEPTAN DEL CLIENTE. Del navegador vienen la
 * procesadora, el importe bruto, la fecha de acreditacion y las observaciones;
 * la tasa y el neto los resuelve Class/CobElectronicos.php con las alicuotas
 * vigentes. Aceptarlos permitiria guardar cualquier numero como si fuera el
 * calculado, que es la version informatica del *0.969 escrito a mano en el
 * Excel.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * La plantilla del importador se resuelve ANTES de declarar el JSON: es una
 * descarga de archivo y no una respuesta de datos, asi que necesita sus propios
 * encabezados. Va aca arriba y no en el switch para que no haya forma de que se
 * emita el header de JSON primero.
 */
if (isset($_GET['action']) && $_GET['action'] === 'plantilla') {
    require_once __DIR__ . '/../Class/CobElectronicos.php';

    $nombre = 'plantilla-cobranzas-electronicas.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: no-store');

    echo CobElectronicos::plantillaCsv();
    exit;
}

header('Content-Type: application/json');

/**
 * Usuario que realiza la edicion.
 * Todavia no hay login: devuelve NULL y se graba NULL. Cuando exista, solo hay
 * que poblar $_SESSION['usuario'].
 * @return string|null
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

/**
 * Lee y decodifica el body JSON del request
 * @return array
 */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/CobElectronicos.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $modulo = new CobElectronicos();

    switch ($action) {
        case 'getPestana':
            // Los filtros van por GET porque son parte de lo que se esta
            // mirando: asi la pantalla se puede recargar con el mismo recorte.
            echo json_encode([
                'success' => true,
                'data' => $modulo->getPestana([
                    'id_procesadora' => isset($_GET['id_procesadora'])
                        ? intval($_GET['id_procesadora']) : 0,
                    'desde' => isset($_GET['desde']) ? $_GET['desde'] : null,
                    'hasta' => isset($_GET['hasta']) ? $_GET['hasta'] : null,
                    // Las acreditaciones ya ocurridas no se muestran por
                    // defecto: ya pasaron y no hay nada que hacer con ellas.
                    'incluir_acreditadas' => !empty($_GET['incluir_acreditadas'])
                ])
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addMovimiento':
            $data = bodyJson();

            if (!isset($data['id_procesadora']) || !isset($data['importe_bruto'])
                || !isset($data['fecha_acreditacion'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $modulo->addMovimiento(
                $data['id_procesadora'],
                $data['importe_bruto'],
                $data['fecha_acreditacion'],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            // El mensaje dice con que tasa se calculo: es el dato que el usuario
            // no tipeo y que define el importe que va a ver en el tablero.
            $mensaje = 'Movimiento guardado. Importe neto $ '
                . number_format($r['neto'], 2, ',', '.') . ', calculado con una retención del '
                . number_format($r['tasa'] * 100, 4, ',', '.') . '%.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMovimiento':
            $data = bodyJson();

            if (!isset($data['id']) || !isset($data['importe_bruto'])
                || !isset($data['fecha_acreditacion'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // La tasa se vuelve a resolver aunque solo haya cambiado el bruto:
            // se resuelve contra la fecha de acreditacion, y si esa fecha se
            // movio, la tasa que corresponde puede ser otra.
            $r = $modulo->saveMovimiento(
                $data['id'],
                $data['importe_bruto'],
                $data['fecha_acreditacion'],
                isset($data['observaciones']) ? $data['observaciones'] : null,
                usuarioActual()
            );

            $mensaje = 'Movimiento actualizado. Importe neto $ '
                . number_format($r['neto'], 2, ',', '.') . ', recalculado con una retención del '
                . number_format($r['tasa'] * 100, 4, ',', '.') . '%.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           IMPORTACION DESDE PLANILLA

           Dos pasos: 'previsualizarImportacion' no escribe nada y devuelve que
           cambiaria; 'confirmarImportacion' aplica. El segundo vuelve a leer la
           base y a calcular el diff con el mismo helper puro, asi que no confia
           en lo que se dibujo en la pantalla.

           Del cliente llegan los DATOS DE ENTRADA del archivo -procesadora,
           bruto, fecha, id externo, observaciones-, los mismos que se tipearian
           a mano. La tasa y el neto los sigue calculando el servidor.
           ================================================================ */

        case 'previsualizarImportacion':
            if (!isset($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
                throw new Exception('No llego ningun archivo. Elegi el CSV y volve a intentar.');
            }

            if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('El archivo no se pudo subir (codigo '
                    . intval($_FILES['archivo']['error']) . ').');
            }

            // Tope de tamano: una planilla de acreditaciones son unos pocos
            // kilobytes. Un archivo de 4 MB no es esto y conviene rechazarlo
            // antes de leerlo en memoria.
            if ($_FILES['archivo']['size'] > 4 * 1024 * 1024) {
                throw new Exception('El archivo pesa más de 4 MB. Una planilla de acreditaciones '
                    . 'no llega a eso: revisá que sea el archivo correcto.');
            }

            $contenido = file_get_contents($_FILES['archivo']['tmp_name']);

            if ($contenido === false) {
                throw new Exception('No se pudo leer el archivo subido');
            }

            echo json_encode([
                'success' => true,
                'data' => $modulo->previsualizarImportacion(
                    $contenido,
                    basename($_FILES['archivo']['name']),
                    // El periodo que cubre el archivo es opcional y va por POST
                    // porque acompana al archivo. Sin el se infiere de las
                    // fechas que traen las filas.
                    [
                        'desde' => isset($_POST['periodo_desde']) ? $_POST['periodo_desde'] : null,
                        'hasta' => isset($_POST['periodo_hasta']) ? $_POST['periodo_hasta'] : null
                    ]
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'confirmarImportacion':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $modulo->importar(
                $data['filas'],
                isset($data['archivo']) ? $data['archivo'] : '',
                // Las bajas son opt-in: un archivo parcial no puede dar de baja
                // lo que no estaba mirando.
                !empty($data['aplicar_bajas']),
                usuarioActual(),
                // El mismo periodo con el que se previsualizo: si no, las bajas
                // que se aplican no serian las que se mostraron.
                [
                    'desde' => isset($data['periodo_desde']) ? $data['periodo_desde'] : null,
                    'hasta' => isset($data['periodo_hasta']) ? $data['periodo_hasta'] : null
                ]
            );

            $a = $r['aplicado'];

            $mensaje = 'Importación aplicada: ' . $a['altas'] . ' alta(s), '
                . $a['cambios'] . ' cambio(s)'
                . ($a['bajas'] > 0 ? (' y ' . $a['bajas'] . ' baja(s)') : '')
                . '. ' . $r['resumen']['sin_cambios'] . ' fila(s) ya estaban cargadas igual y no '
                . 'se tocaron.';

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaMovimiento':
            $data = bodyJson();

            if (!isset($data['id'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // Baja LOGICA: la fila queda, deja de sumar al tablero.
            $modulo->bajaMovimiento($data['id'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Movimiento dado de baja. No se borró: queda inhabilitado y sale '
                           . 'del tablero.'
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
