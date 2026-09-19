<?php
/**
 * EcheqsController.php
 * Controlador de la pestana Echeqs: cheques de terceros en cartera y venta
 * cobrada anticipada.
 *
 * LA LISTA DE IDS QUE MANDA EL NAVEGADOR NO ES UNA AUTORIZACION. El universo de
 * cheques marcables lo vuelve a resolver Echeqs::marcarCheques() contra el
 * maestro; un id de un cliente que no esta cargado se rechaza. Aceptarlo
 * guardaria una marca que despues no se ve en ninguna pantalla y que igual
 * netearia la cobranza de Ventas. Echeqs::excluirCheques() hace lo mismo contra
 * la cartera de hoy.
 *
 * LOS DOS TILDES DE ESTA PESTANA SON DOS COSAS DISTINTAS y por eso son dos
 * acciones distintas:
 *
 *   excluirCheques   cartera       "esta plata, ¿va a entrar?"
 *   marcarCheques    prechequeado  "esta venta, ¿ya se cobro?"
 *
 * Ver el encabezado de Class/Echeqs.php.
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
    require_once __DIR__ . '/../Class/Echeqs.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $echeqs = new Echeqs();

    switch ($action) {
        case 'getEcheqsCartera':
            // El eje sale de horizonte_dias y horizonte_meses, el mismo del
            // tablero: los importes por columna vienen ya resueltos y el
            // navegador no calcula ninguna fecha.
            $payload = EjeVista::armar(
                Horizonte::desdeParametros(new Parametros()),
                $echeqs->getEcheqsCartera(),
                'FECHA_PAGO',
                'IMPORTE'
            );

            /* LOS EXCLUIDOS VIAJAN EN 'filas' Y LOS ESCONDE LA PANTALLA, con un
               interruptor que se puede apagar. El eje los incluye porque sin
               sus importes por columna no habria forma de mostrarlos cuando el
               interruptor se prende.

               Por eso hacen falta los dos totales: 'totales' es el universo
               -contra el que cierra la tabla cuando se ven los excluidos- y
               'totales_netos' es lo que de verdad entra al cashflow, que es lo
               que muestran las tres tarjetas. La resta la hace PHP: en este
               modulo el front no calcula. */
            $payload['excluidos'] = Echeqs::resumenExcluidos($payload['filas']);
            $payload['totales_netos'] = Echeqs::totalesNetos(
                $payload['totales'], $payload['excluidos']);

            /* Si se puede excluir. La pantalla lo pregunta en vez de suponerlo:
               sin el script, el listado se lee igual y lo que no se puede es
               guardar la exclusion. Un boton que se dibuja y despues falla
               contra el servidor es peor que uno apagado que dice por que. Es
               el mismo criterio que 'excluir_factura' en
               ProveedoresController. */
            $payload['excluir_cheque'] = $echeqs->excluirCreada();

            echo json_encode([
                'success' => true,
                'data' => $payload
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getEcheqsPrechequeado':
            $filas = $echeqs->getEcheqsPrechequeado();

            // DOS FECHAS, DOS FUNCIONES DISTINTAS:
            //
            //   FECHA_VENTA_EST  decide QUE cheques se muestran
            //   FECHA_CHEQUE     decide DONDE cae cada importe en el eje
            //
            // Cada cheque se ubica en la fecha del CHEQUE, que es cuando entra
            // la plata. Antes se ubicaba en la fecha estimada de venta, porque
            // el criterio era caer donde esta la cobranza proyectada de esa
            // venta; ese razonamiento quedo superado. La fecha estimada sigue
            // siendo columna visible: es lo que explica por que ese cheque esta
            // en la lista.
            //
            // ESTA LINEA Y Ventas::repartirNeteo() SE MUEVEN JUNTAS. El modulo
            // esta construido para que la pantalla y el neteo no se puedan
            // desalinear: si solo cambiara una, el usuario tildaria un cheque
            // en una columna y el tablero lo restaria en otra.
            //
            // Los cheques cuya fecha ESTIMADA cae antes de hoy no llegan hasta
            // aca: los descarta Echeqs::cruzarPrechequeado() con
            // Echeqs::ventaYaCobrada(), la misma funcion que usa el neteo. Esa
            // venta ya se facturo y ya se cobro: esta fuera del cashflow, y la
            // leyenda de la sub-pestana lo dice.
            //
            // Lo POSTERIOR al horizonte si llega y si se avisa en 'descartes':
            // ese cheque esta en la tabla y su importe no tiene columna. El
            // neteo informa el mismo importe en 'fuera_horizonte', asi que las
            // dos pantallas dicen lo mismo.
            $payload = EjeVista::armar(
                Horizonte::desdeParametros(new Parametros()),
                $filas,
                'FECHA_CHEQUE',
                'IMPORTE'
            );

            // Los avisos van SIEMPRE, incluso -sobre todo- cuando el listado
            // esta vacio: es la diferencia entre "no hay clientes configurados"
            // y "esta pantalla no anda".
            $payload['resumen'] = Echeqs::resumenPrechequeado($filas);
            $payload['avisos'] = $echeqs->getAvisos();

            echo json_encode([
                'success' => true,
                'data' => $payload
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'marcarCheques':
            $data = bodyJson();

            if (!isset($data['ids']) || !is_array($data['ids'])
                || !array_key_exists('marcado', $data)) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $echeqs->marcarCheques(
                $data['ids'],
                !empty($data['marcado']),
                usuarioActual()
            );

            $mensaje = ($data['marcado'] ? 'Se tildaron ' : 'Se destildaron ')
                . $r['tocados'] . ' cheque(s).';

            if (!empty($r['rechazados'])) {
                $mensaje .= ' ' . count($r['rechazados']) . ' quedaron afuera porque ya no están '
                    . 'en el listado: actualizá la pantalla para ver cómo quedó.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EXCLUIR UN CHEQUE DE CARTERA DEL CASHFLOW

           Es plata que NO se va a poder cobrar. El importe sale de la fila del
           tablero pero no desaparece: va a la serie A_COBRAR_EXCLUIDOS y el
           proveedor avisa cuanto es y con que motivos.

           APLICA SOLO A CARTERA. El tilde de la otra sub-pestana contesta otra
           pregunta y tiene su propia accion: ver el encabezado.

           EL MOTIVO ES OBLIGATORIO y lo valida Echeqs::excluirCheques(), no la
           pantalla: este endpoint es alcanzable sin pasar por la grilla.
           ================================================================ */
        case 'excluirCheques':
            $data = bodyJson();

            if (!isset($data['ids']) || !is_array($data['ids'])
                || !array_key_exists('excluir', $data)) {
                throw new Exception('Faltan parametros obligatorios');
            }

            /* UNA SOLA ACCION PARA UNO Y PARA VARIOS. La pantalla manda siempre
               una lista; 'ids' con un solo elemento es el caso de uno. Un
               endpoint aparte para el masivo serian dos caminos que tienen que
               hacer exactamente lo mismo, y la transaccion es justo lo que no
               puede estar escrito dos veces. */
            $r = $echeqs->excluirCheques(
                $data['ids'],
                !empty($data['excluir']),
                isset($data['motivo']) ? $data['motivo'] : null,
                usuarioActual()
            );

            $cuantos = $r['tocados'];

            $mensaje = $r['excluidos']
                ? ($cuantos === 1
                    ? 'Cheque excluido: su importe sale del cashflow y queda informado aparte.'
                    : $cuantos . ' cheques excluidos: sus importes salen del cashflow y quedan '
                        . 'informados aparte.')
                : ($cuantos === 1
                    ? 'Cheque incluido de nuevo en el cashflow.'
                    : $cuantos . ' cheques incluidos de nuevo en el cashflow.');

            if (!empty($r['rechazados'])) {
                $mensaje .= ' ' . count($r['rechazados']) . ' quedaron afuera porque ya no están '
                    . 'en cartera: actualizá la pantalla para ver cómo quedó.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* El historial de un cheque: por que se lo excluyo, quien y cuando, y
           las decisiones anteriores. ES LO QUE JUSTIFICA QUE NO HAYA BAJAS
           FISICAS: sin una pantalla que lo muestre, el historial es una tabla
           que crece y que nadie mira. */
        case 'getHistorialExclusion':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

            if ($id <= 0) {
                throw new Exception('Falta el cheque');
            }

            echo json_encode([
                'success' => true,
                'data' => $echeqs->getHistorialExclusion($id)
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
