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

    /* Quien escribe y si puede, antes de tocar nada: null en las acciones
       que solo leen. Ver Controller/autorizacion.php. */
    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Echeqs', $action);

    $echeqs = new Echeqs();

    switch ($action) {
        case 'getEcheqsCartera':
            $hoy = date('Y-m-d');

            /* DOS LECTURAS, UNA GRILLA. La cartera (ESTADO = 'C') y los
               depositados en Tango (boleta BDE), que ya salieron de cartera.
               Un cheque esta en una o en la otra, nunca en las dos. Los
               depositados vienen de getDepositadosTango(), la misma lectura
               que usa el proveedor: la pestana y el tablero suman lo mismo. */
            $h = Horizonte::desdeParametros(new Parametros());
            $dep = $echeqs->getDepositadosTango($hoy);
            $cartera = $echeqs->getEcheqsCartera($h);
            $filas = array_merge($cartera, $dep['filas']);

            /* El eje sale de horizonte_dias y horizonte_meses, el mismo del
               tablero: los importes por columna vienen ya resueltos y el
               navegador no calcula ninguna fecha.

               CADA FILA SE UBICA POR FECHA_EJE E IMPORTE_EJE, que resolvio
               Echeqs::ubicarEnEje(): uno de cartera en su dia habil -la fecha
               del cheque, corrida si cae un fin de semana o un feriado- y por
               el bruto, uno depositado que se proyecta en su acreditacion y
               por el neto, y uno acreditado o sin datos en ninguna columna.
               Es la misma regla que usa el proveedor, asi que la grilla y el
               tablero ponen cada peso en la misma columna. El mismo eje $h
               decide hasta donde se corre. */
            $payload = EjeVista::armar(
                $h,
                $filas,
                'FECHA_EJE',
                'IMPORTE_EJE'
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
            $payload['depositados'] = Echeqs::resumenDepositos($payload['filas']);

            /* LAS TRES TARJETAS SON LA CARTERA COBRABLE SIN DEPOSITADOS: lo
               mismo que la fila "Echeqs en cartera" del tablero. Los
               depositados van en su propia tarjeta, con bruto, retencion y
               neto, que es la fila "Echeqs depositados". Las dos restas las
               hace PHP con el mismo helper: el front no calcula. */
            $payload['totales_netos'] = Echeqs::totalesNetos(
                Echeqs::totalesNetos($payload['totales'], $payload['excluidos']),
                $payload['depositados']);

            // Los de los depositados: sin plazo, sin habil o sin alicuotas
            // son criticos -esa plata no esta en ninguna fila-.
            foreach ($dep['avisos'] as $a) {
                $payload['warnings'][] = $a;
            }

            // Los del corrimiento de la cartera: calendario faltante y sin
            // habil. Los mismos que deja el proveedor en el tablero.
            foreach (Echeqs::avisosCartera($cartera) as $a) {
                $payload['warnings'][] = $a;
            }

            // LA FILA DEL TABLERO: hay depositados que se proyectan y ninguna
            // fila activa los muestra. Esa plata salio de "Echeqs en cartera"
            // en Tango y no entra en ningun lado.
            if ($payload['depositados']['cheques'] > 0 && !$echeqs->filaDepositadosActiva()) {
                $payload['warnings'][] = ['nivel' => 'danger', 'texto' => 'Hay cheques '
                    . 'depositados en Tango y ninguna fila activa del tablero muestra la serie '
                    . 'ECHEQS → DEPOSITADOS: su neto no entra en ninguna fila. Corré '
                    . Echeqs::SCRIPT_FILA_DEPOSITADOS . ' o activá la fila en Parámetros → '
                    . 'Cashflow.'];
            }

            /* Si se puede excluir. La pantalla lo pregunta en vez de suponerlo:
               sin el script, el listado se lee igual y lo que no se puede es
               guardar la exclusion. Un boton que se dibuja y despues falla
               contra el servidor es peor que uno apagado que dice por que. Es
               el mismo criterio que 'excluir_factura' en
               ProveedoresController. */
            $payload['excluir_cheque'] = $echeqs->excluirCreada();

            /* Si se puede marcar "ya acredito". Sin el script el boton no se
               dibuja y la pestana dice que falta: los depositados se
               proyectan hasta que vence su plazo. */
            $payload['acreditado'] = $echeqs->acreditadoCreado();

            if (!$payload['acreditado']) {
                $payload['warnings'][] = ['nivel' => 'info', 'texto' => 'Para marcar un cheque '
                    . 'depositado como "ya acreditó" hace falta correr ' . Echeqs::SCRIPT_ACREDITADO
                    . ' contra la base central. Mientras tanto, los depositados se proyectan hasta '
                    . 'su fecha de acreditación.'];
            }

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
                $usuario
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
                $usuario
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

        /* ================================================================
           LA MARCA "YA ACREDITO"

           Un cheque depositado que ya acredito antes de su plazo sale de
           "Echeqs depositados": el saldo de Interbanking ya lo tiene. Del
           navegador llegan SOLO LOS IDS -y una observacion opcional-: el
           origen es MANUAL y la fecha, hoy. Lo valida Echeqs contra la
           lectura de la base, todo o nada.
           ================================================================ */
        case 'marcarAcreditados':
            $data = bodyJson();

            if (!isset($data['ids']) || !is_array($data['ids'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $echeqs->marcarAcreditados(
                $data['ids'],
                isset($data['observacion']) ? $data['observacion'] : null,
                $usuario
            );

            echo json_encode([
                'success' => true,
                'message' => $r['marcados'] . ' cheque(s) marcados como acreditados: salen de '
                    . '"Echeqs depositados". Se siguen viendo hasta su fecha de acreditación.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deshacerAcreditados':
            $data = bodyJson();

            if (!isset($data['ids']) || !is_array($data['ids'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $r = $echeqs->deshacerAcreditados($data['ids'], $usuario);

            echo json_encode([
                'success' => true,
                'message' => $r['deshechos'] . ' marca(s) deshechas. Los cheques vuelven a '
                    . '"Echeqs depositados" si acreditan de mañana en adelante. La marca queda en '
                    . 'el historial, dada de baja.',
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
