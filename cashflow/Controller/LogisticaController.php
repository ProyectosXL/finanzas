<?php
/**
 * LogisticaController.php
 * Endpoints de Logistica Local: la planilla proyectada y el maestro de
 * fleteros.
 *
 * EL MAESTRO SE ADMINISTRA DESDE LAS DOS PANTALLAS, y por eso vive aca y no en
 * ParametrosController: el alta se hace en Parametros -> Logistica y las horas
 * y el valor hora se corrigen tambien desde la planilla, que es donde se ve el
 * efecto. Dos endpoints que escriben la misma tabla se desincronizan en la
 * primera validacion que alguien agregue de un solo lado. Es el mismo criterio
 * con el que el modulo CASHFLOW declara su propio endpoint en Parametros.
 *
 * LA VALIDACION QUE VALE ES LA DE LA CLASE. La pantalla acota lo que se puede
 * tipear, pero lo que manda el navegador es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por ella. El codigo de proveedor se vuelve a
 * chequear contra CPA01 en cada guardado, aunque el autocomplete lo haya
 * ofrecido.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

try {
    require_once __DIR__ . '/../Class/Logistica.php';
    require_once __DIR__ . '/../Class/Parametros.php';
    require_once __DIR__ . '/../Class/Horizonte.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/ProveedoresTango.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    /* Quien escribe y si puede, antes de tocar nada: null en las acciones
       que solo leen. Ver Controller/autorizacion.php. */
    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Logistica', $action);

    $log = new Logistica();

    switch ($action) {
        /* ================================================================
           LA PLANILLA

           El payload del eje lo arma EjeVista::armarAgrupado(), igual que los
           Resumen de Cobranzas FR y May: una fila por fletero, con importes en
           VARIAS columnas a la vez. Es lo que corresponde acá, porque los dos
           pagos de un mes caen en fechas distintas y pueden caer en columnas
           distintas.

           NO se reimplementa el reparto: los pagos entran como items con su
           fecha y su importe, y el eje los ubica con la misma regla que usa el
           tablero. Así la pestaña y el tablero no pueden dar distinto.
           ================================================================ */
        case 'getPlanilla':
            $parametros = new Parametros();
            $h = Horizonte::desdeParametros($parametros);
            $datos = $log->planilla($h);

            $pagos = LogisticaPlanilla::pagosAProyectar($datos['planilla']);
            $payload = EjeVista::armarAgrupado($h, $pagos, 'cod_provee', 'fecha', 'importe');

            /* Las filas del eje traen los importes por columna pero no saben de
               quién son ni qué valor hora rige: eso se pega acá, indexado por
               código, para que el front no tenga que cruzar dos listas. */
            $porCodigo = [];

            foreach ($datos['planilla']['fleteros'] as $f) {
                $porCodigo[$f['cod_provee']] = $f;
            }

            foreach ($payload['filas'] as &$fila) {
                $cod = isset($fila['cod_provee']) ? $fila['cod_provee'] : '';
                $fila['detalle'] = isset($porCodigo[$cod]) ? $porCodigo[$cod] : null;
            }

            unset($fila);

            echo json_encode([
                'success' => true,
                'data' => [
                    'eje' => $payload,
                    /* La planilla COMPLETA viaja igual, y no es redundante: el
                       eje sólo trae lo que se proyecta, y la pantalla tiene que
                       poder mostrar también lo que NO se proyecta y por qué.
                       Un fletero sin horas no tiene ninguna fila en el eje. */
                    'planilla' => $datos['planilla'],
                    'cronograma' => $datos['cronograma'],
                    'cronograma_config' => $datos['cronograma_config'],
                    'hoy' => $h->hoy(),
                    'avisos' => $datos['avisos'],
                    'tabla_creada' => $datos['tabla_creada'],
                    'no_habituales' => $datos['no_habituales']
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EL MAESTRO DE FLETEROS
           ================================================================ */
        case 'getFleteros':
            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $log->getFleteros(false),
                    'tabla_creada' => $log->tablaCreada(),
                    'no_habituales' => $log->noHabitualesCreado(),
                    'aviso_no_habituales' => ($log->tablaCreada() && !$log->noHabitualesCreado())
                        ? $log->avisoSinNoHabituales() : '',
                    'tango_disponible' => $log->tango()->disponible(),
                    'min_busqueda' => ProveedoresTango::MIN_BUSQUEDA,
                    'aviso' => $log->avisoSinTabla()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Alimenta el autocomplete del alta. Busca por código Y por nombre
           porque quien carga un fletero se acuerda del nombre, no del código.

           NO ES LA VALIDACIÓN: que acá aparezca un proveedor no autoriza nada.
           guardarFletero() vuelve a chequear contra CPA01. Es el mismo patrón
           —y la misma clase— que el alta manual del maestro de Proveedores
           Locales. */
        case 'buscarProveedorTango':
            $q = isset($_GET['q']) ? $_GET['q'] : '';

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $log->tango()->buscar($q),
                    'min' => ProveedoresTango::MIN_BUSQUEDA,
                    'max' => ProveedoresTango::MAX_RESULTADOS
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Sirve para el alta, para corregir los datos y para reactivar una
           baja: la clase resuelve cuál de los tres es y lo dice en la
           respuesta. Una acción "reactivar" aparte obligaría a la pantalla a
           saber de antemano si el código ya existía. */
        case 'saveFletero':
            $data = bodyJson();

            if (!isset($data['cod_provee'])) {
                throw new Exception('Falta el código de proveedor');
            }

            $r = $log->guardarFletero($data['cod_provee'], [
                'horas' => isset($data['horas']) ? $data['horas'] : null,
                'valor_hora' => isset($data['valor_hora']) ? $data['valor_hora'] : null,
                'mes_base' => isset($data['mes_base']) ? $data['mes_base'] : null,
                // Si no viene, queda como estaba: la planilla vieja no la manda.
                'habitual' => array_key_exists('habitual', $data) ? $data['habitual'] : null
            ], $usuario);

            $mensaje = $r['nuevo']
                ? ('Fletero dado de alta: ' . $r['nombre'] . ' (' . $r['cod_provee'] . ').')
                : ($r['reactivado']
                    ? ('Fletero reactivado: ' . $r['nombre'] . '.')
                    : ('Datos guardados: ' . $r['nombre'] . '.'));

            if ($r['cambio_habitual']) {
                $mensaje .= $r['habitual']
                    ? ' Vuelve a ser habitual: se proyecta con sus horas y su valor hora, y sus '
                      . 'pagos puntuales dejan de proyectarse (quedan guardados).'
                    : ' Pasa a no habitual: deja de proyectarse por cálculo. Sus horas y su valor '
                      . 'hora se conservan; se le cargan pagos puntuales.';
            }

            /* SE DICE SI TODAVÍA NO PROYECTA, y en el mismo mensaje: un alta que
               contesta "guardado" y deja la fila del tablero igual que antes se
               lee como que ya está configurado. A un no habitual no se le piden. */
            $faltan = [];

            if ($r['habitual']) {
                if (empty($data['horas'])) { $faltan[] = 'las horas por mes'; }
                if (empty($data['valor_hora'])) { $faltan[] = 'el valor hora base'; }
                if (empty($data['mes_base'])) { $faltan[] = 'el mes base'; }
            }

            if (!empty($faltan)) {
                $mensaje .= ' Todavía no se proyecta: le falta ' . implode(', ', $faltan)
                    . '. No se cuenta como cero.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'activarFletero':
            $data = bodyJson();

            if (!isset($data['cod_provee']) || !array_key_exists('activo', $data)) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $r = $log->activarFletero($data['cod_provee'], !empty($data['activo']),
                $usuario);

            echo json_encode([
                'success' => true,
                'message' => $r['activo']
                    ? ('Fletero reactivado: ' . $r['nombre'] . '.')
                    : ('Fletero dado de baja: ' . $r['nombre'] . '. No se borra: conserva las '
                        . 'horas y el valor hora con los que se proyectó, y se puede reactivar.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LOS PAGOS PUNTUALES DE UN NO HABITUAL

           La fecha va de mañana en adelante y se valida en la clase, no acá
           ni sólo en la pantalla.
           ================================================================ */
        case 'savePagoPuntual':
            $data = bodyJson();

            if (!isset($data['cod_provee'])) {
                throw new Exception('Falta el fletero');
            }

            $r = $log->guardarPagoPuntual(isset($data['id']) ? $data['id'] : null,
                $data['cod_provee'], isset($data['fecha']) ? $data['fecha'] : '',
                isset($data['importe']) ? $data['importe'] : null, $usuario);

            echo json_encode([
                'success' => true,
                'message' => ($r['nuevo'] ? 'Pago puntual cargado' : 'Pago puntual corregido')
                    . ': $ ' . number_format($r['importe'], 2, ',', '.') . ' el '
                    . date('d/m/Y', strtotime($r['fecha'])) . '.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaPagoPuntual':
            $data = bodyJson();

            if (!isset($data['id']) || !isset($data['cod_provee'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $r = $log->bajaPagoPuntual($data['id'], $data['cod_provee'], $usuario);

            echo json_encode([
                'success' => true,
                'message' => 'Pago puntual dado de baja ($ ' . number_format($r['importe'], 2, ',', '.')
                    . ' del ' . date('d/m/Y', strtotime($r['fecha'])) . '). No se borra: queda '
                    . 'en el historial.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EL IMPORTE DEL PRÓXIMO PAGO DE UN HABITUAL

           Que sea el próximo lo verifica la clase contra la planilla
           recalculada acá, no contra lo que dice la pantalla.
           ================================================================ */
        case 'saveImporteProximo':
            $data = bodyJson();

            foreach (['cod_provee', 'mes', 'nro', 'importe'] as $campo) {
                if (!isset($data[$campo])) {
                    throw new Exception('Faltan parámetros obligatorios');
                }
            }

            $r = $log->guardarImporteProximo(Horizonte::desdeParametros(new Parametros()),
                $data['cod_provee'], $data['mes'], $data['nro'], $data['importe'], $usuario);

            echo json_encode([
                'success' => true,
                'message' => 'Importe del pago del ' . date('d/m/Y', strtotime($r['fecha']))
                    . ': $ ' . number_format($r['importe'], 2, ',', '.')
                    . ($r['importe_calculado'] === null ? '.'
                        : ' (el cálculo daba $ ' . number_format($r['importe_calculado'], 2, ',', '.')
                          . '). Los pagos siguientes se siguen calculando.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deshacerImporteProximo':
            $data = bodyJson();

            if (!isset($data['cod_provee']) || !isset($data['mes']) || !isset($data['nro'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $r = $log->deshacerImporte($data['cod_provee'], $data['mes'], $data['nro'], $usuario);

            echo json_encode([
                'success' => true,
                'message' => $r['habia']
                    ? 'El pago vuelve al importe calculado. El editado queda en el historial.'
                    : 'Ese pago ya estaba con el importe calculado.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida. Acción recibida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
