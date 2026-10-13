<?php
/**
 * Lo que llega de una importacion de planilla, escrito UNA vez para los tres
 * importadores: el de pagos y el del maestro de Proveedores Locales, y el de
 * Cob. Electronicos.
 *
 * DE DONDE SALE
 * -------------
 * Vivia en ProveedoresController.php y Cob. Electronicos tenia su propia
 * lectura, que aceptaba SOLO un archivo subido. Desde que la planilla es un
 * Excel que el navegador lee con SheetJS, lo que llega no es el archivo sino su
 * primera hoja convertida a texto, en el campo 'contenido', y las dos lecturas
 * tenian que aprender lo mismo. Una copia por controller se desincroniza en el
 * primer cambio -el tope de tamaño, el mensaje-, asi que se junto aca.
 *
 * Acepta las dos formas: el texto en 'contenido' -lo que manda la pantalla- y
 * un upload real en 'archivo', que sigue sirviendo para probar el circuito con
 * un CSV sin pasar por el navegador. Lo que llegue lo resuelve igual
 * Planilla::parsear(), que es la que valida la forma.
 */

/**
 * Tope de tamaño de lo que se acepta. Una planilla de estas son unos pocos
 * kilobytes -el maestro, con 1.223 proveedores, no llega a 200-: algo de 4 MB
 * no es esto, y conviene rechazarlo antes de parsearlo en memoria.
 */
const PLANILLA_TOPE_BYTES = 4194304;

/**
 * El contenido de la planilla, como texto.
 *
 * @return string
 */
function contenidoSubido() {
    if (isset($_POST['contenido']) && trim((string) $_POST['contenido']) !== '') {
        $contenido = (string) $_POST['contenido'];
    } elseif (isset($_FILES['archivo']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('El archivo no se pudo subir (código '
                . intval($_FILES['archivo']['error']) . ').');
        }

        $contenido = file_get_contents($_FILES['archivo']['tmp_name']);

        if ($contenido === false) {
            throw new Exception('No se pudo leer el archivo subido.');
        }
    } else {
        throw new Exception('No se recibió ninguna planilla. Elegí el Excel y volvé a intentar.');
    }

    if (strlen($contenido) > PLANILLA_TOPE_BYTES) {
        throw new Exception('La planilla pesa más de 4 MB. Una planilla de importación no llega '
            . 'a eso: revisá que sea el archivo correcto.');
    }

    return $contenido;
}

/**
 * El nombre del archivo que eligio el usuario, para el resumen. Con el Excel
 * leido en el navegador el archivo no viaja, asi que el nombre llega aparte.
 *
 * @return string
 */
function nombreSubido() {
    if (isset($_POST['archivo_nombre']) && trim((string) $_POST['archivo_nombre']) !== '') {
        return basename(trim((string) $_POST['archivo_nombre']));
    }

    if (isset($_FILES['archivo']['name'])) {
        return basename((string) $_FILES['archivo']['name']);
    }

    return '';
}

/**
 * Devuelve la definicion de una plantilla como JSON y termina. El .xlsx lo arma
 * el navegador con esa definicion: ver Planilla::plantilla().
 *
 * @param array $plantilla
 */
function responderPlantilla($plantilla) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    echo json_encode(['success' => true, 'data' => $plantilla], JSON_UNESCAPED_UNICODE);
    exit;
}
