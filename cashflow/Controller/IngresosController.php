<?php
/**
 * IngresosController.php
 * Controlador para operaciones de Ingresos (Cobranzas FR, etc)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

/**
 * El payload de una grilla de cobranzas: agrupado por cliente en Resumen, una
 * fila por comprobante en Deep Dive. Las dos formas devuelven exactamente el
 * mismo payload, asi que el front no las distingue.
 *
 * 'importe_bruto' se suma ademas del neto: el neto es el que va al eje -es la
 * plata que entra- y el bruto es una columna mas de la fila.
 *
 * @param array $items
 * @param bool $summary
 * @return array
 */
function payloadCobranzas($items, $summary) {
    $h = Horizonte::desdeParametros(new Parametros());

    if (!$summary) {
        return EjeVista::armar($h, $items, 'Cobro', 'importe_neto');
    }

    return EjeVista::armarAgrupado($h, $items, 'COD_CLI', 'Cobro', 'importe_neto',
        1, ['importe_bruto']);
}

try {
    require_once __DIR__ . '/../Class/Ingresos.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $ingresos = new Ingresos();

    /** El cuerpo JSON de un POST */
    $bodyJson = function () {
        $crudo = file_get_contents('php://input');
        $data = json_decode($crudo, true);

        return is_array($data) ? $data : [];
    };

    /** Usuario de sesion. Todavia no hay login: hoy graba NULL. */
    $usuarioActual = function () {
        return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
    };

    switch ($action) {
        /* Resumen y Deep Dive se arman con la MISMA lista de comprobantes: lo
           que cambia es quien la agrupa.

             Deep Dive -> EjeVista::armar(), una fila por comprobante
             Resumen   -> EjeVista::armarAgrupado() por COD_CLI, una fila por
                          cliente con los importes repartidos en las columnas
                          de la fecha de cada factura

           El agrupado va en EjeVista y no en la consulta porque agrupar por
           cliente + fecha en SQL obliga a que un cliente con cobros en tres
           fechas ocupe tres filas del resumen, y agrupar solo por cliente
           perderia la fecha, que es lo que ubica el importe en la grilla. */

        case 'getCobranzasFR':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;
            $origen = isset($_GET['origen']) ? $_GET['origen'] : 'todos';

            // El eje sale de horizonte_dias y horizonte_meses, el mismo del
            // tablero: esta pestaña deja de tener su ventana propia -eran los
            // dias del mes en curso y doce meses fijos-.
            echo json_encode([
                'success' => true,
                'data' => payloadCobranzas(
                    $ingresos->getCobranzasFR($summary, $origen),
                    $summary
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getCobranzasMay':
            $summary = isset($_GET['type']) && $_GET['type'] === 'deepdive' ? false : true;

            echo json_encode([
                'success' => true,
                'data' => payloadCobranzas($ingresos->getCobranzasMay(), $summary)
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Exportaciones Tasky: una fila por factura, sin Resumen -es un solo
           cliente-. A la grilla va el importe en PESOS DE HOY, que es el que
           entra al tablero; los dolares y la referencia de facturacion son
           columnas de la fila.

           Sin cotizacion, IMPORTE_PESOS_HOY es null y el agrupador lo saltea
           como cero: por eso los avisos de Ingresos::avisosExportaciones() van
           ADELANTE de los del eje, si no la grilla vacia no diria por que. */
        case 'getExportacionesTasky':
            $items = $ingresos->getExportacionesTasky();
            $cotiz = $ingresos->getCotizacionHoy();
            $h = Horizonte::desdeParametros(new Parametros());

            $payload = EjeVista::armar($h, $items, 'Cobro', 'IMPORTE_PESOS_HOY');
            $payload['cotizacion_hoy'] = $cotiz;
            $payload['dias_cobro'] = $ingresos->getDiasCobroExportaciones();
            $payload['warnings'] = array_merge(
                Ingresos::avisosExportaciones($items, $cotiz),
                $payload['warnings']
            );

            echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           FECHA DE COBRO MANUAL POR COMPROBANTE

           La validacion de la fecha se hace ACA de nuevo, aunque el input del
           navegador lleve `min` en el dia de hoy: lo que manda el navegador es
           un pedido, no una autorizacion. Vive en
           Ingresos::validarFechaCobroManual().
           ================================================================ */

        case 'saveFechaCobroManual':
            $data = $bodyJson();

            foreach (['t_comp', 'n_comp', 'fecha_cobro'] as $campo) {
                if (!isset($data[$campo]) || $data[$campo] === '') {
                    throw new Exception('Falta el campo ' . $campo
                        . ' para guardar la fecha de cobro.');
                }
            }

            $fecha = $ingresos->saveFechaManualFR(
                isset($data['cod_cliente']) ? $data['cod_cliente'] : '',
                $data['t_comp'],
                $data['n_comp'],
                $data['fecha_cobro'],
                $usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Fecha de cobro guardada.',
                'data' => ['fecha_cobro' => $fecha]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deleteFechaCobroManual':
            $data = $bodyJson();

            if (empty($data['t_comp']) || empty($data['n_comp'])) {
                throw new Exception('Falta el comprobante cuya fecha manual hay que borrar.');
            }

            $ingresos->deleteFechaManualFR($data['t_comp'], $data['n_comp']);

            echo json_encode([
                'success' => true,
                'message' => 'La fecha vuelve a calcularse con el PPP del cliente.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida: ' . $action
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
        'message' => 'Error fatal: ' . $e->getMessage()
    ]);
}
