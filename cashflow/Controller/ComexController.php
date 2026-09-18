<?php
/**
 * ComexController.php
 * Controlador para operaciones de Comercio Exterior (COMEX)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/Comex.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    // Obtener acción del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $comex = new Comex();

    /**
     * Eje temporal de las pestañas, el mismo del tablero.
     *
     * Sale de horizonte_dias y horizonte_meses, así que estas pestañas dejan de
     * tener su ventana propia -eran los días del mes en curso y doce meses
     * fijos- y muestran exactamente el período que se está proyectando.
     *
     * @return Horizonte
     */
    function ejeDelModulo() {
        static $h = null;

        if ($h === null) {
            $h = Horizonte::desdeParametros(new Parametros());
        }

        return $h;
    }

    switch ($action) {
        case 'getProveedoresExterior':
            /* EL EJE SE ARMA SOBRE IMPORTE_ARS, no sobre VALOR_FOB_DOLAR. El
               cashflow es en pesos y esta pestaña es el detalle de una fila del
               tablero: si mostrara dólares, sus totales no se podrían comparar
               contra la fila que explica. La columna en dólares sigue en la
               grilla como referencia.

               La valuación fila por fila -con la curva de dólar futuro ROFEX,
               o con el override que alguien cargó- ya viene resuelta del
               getter, así que acá no hay ninguna multiplicación. Ver
               Class/Comex.php y Class/DolarFuturo.php. */
            $filasExt = $comex->getProveedoresExterior();

            $payload = EjeVista::armar(
                ejeDelModulo(),
                $filasExt,
                'FECHA_PAGO_EFECTIVA',
                'IMPORTE_ARS'
            );

            /* Los avisos propios van ADELANTE de los del eje: explican por qué
               hay contenedores que no aparecen con importe, y eso se lee antes
               que lo que quedó fuera del horizonte. */
            $payload['warnings'] = array_merge(
                $comex->getAvisosExterior(),
                Comex::avisosValuacion($filasExt, $comex->dolarFuturo()->ultimoMes()),
                $payload['warnings']
            );

            /* De dónde sale el dólar y hasta cuándo llega la curva. La pantalla
               lo muestra en el pie: un importe en pesos que no se puede atar a
               una cotización identificada y fechada no se puede auditar contra
               nada. */
            $payload['cotizacion'] = [
                'origen' => DolarFuturo::ORIGEN,
                'disponible' => $comex->dolarFuturo()->disponible(),
                'ultimo_mes' => $comex->dolarFuturo()->ultimoMes(),
                'actualizada' => $comex->dolarFuturo()->actualizada(),
                'curva' => array_values($comex->dolarFuturo()->curva()),

                /* Si se puede corregir a mano. La pantalla lo pregunta en vez de
                   suponerlo: sin el script la grilla se lee igual y lo que no se
                   puede es escribir el override. Una celda que se dibuja
                   editable y después falla al guardar es peor que una que no lo
                   es, con el motivo al lado. */
                'editable' => $comex->tieneCotizEdit()
            ];

            echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LA COTIZACIÓN DE UN CONTENEDOR

           Pisa a la curva SOLO para ese contenedor y NO toca la tabla maestra
           del ROFEX, que para este módulo es de sólo lectura.

           Mandar vacío saca el override y la fila vuelve a la curva.
           ================================================================ */
        case 'updateCotizacion':
            $data = json_decode(file_get_contents('php://input'), true);

            if (!is_array($data) || !isset($data['id_mg'])) {
                throw new Exception('Falta el contenedor al que corresponde la cotización.');
            }

            $r = $comex->updateCotizacion(
                $data['id_mg'],
                array_key_exists('cotizacion', $data) ? $data['cotizacion'] : null
            );

            echo json_encode([
                'success' => true,
                'message' => ($r['cotizacion'] === null)
                    ? 'La cotización vuelve a salir de la curva de dólar futuro.'
                    : 'Este contenedor se valúa a $ '
                        . number_format($r['cotizacion'], 4, ',', '.')
                        . ' por dólar. La curva no cambia: es sólo para esta fila.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getProveedoresExteriorRaw':
            // Solo los datos crudos sin procesamiento
            $datos = $comex->getProveedoresExterior();
            echo json_encode([
                'success' => true,
                'data' => $datos
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'updateFechaPago':
            // Recibir datos del POST
            $postData = file_get_contents('php://input');
            $data = json_decode($postData, true);
            
            if (!isset($data['id_mg']) || !isset($data['fecha_pago_orig']) || !isset($data['fecha_pago_edit'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }
            
            $result = $comex->updateFechaPago(
                $data['id_mg'],
                $data['fecha_pago_orig'],
                $data['fecha_pago_edit']
            );

            /* EL DESCARTE DE LA COTIZACIÓN SE AVISA. Si el pago se corrió a
               otro mes, el override que alguien había cargado dejó de aplicar y
               la fila volvió a la curva. Sin decirlo, el usuario ve cambiar un
               importe que no tocó. Ver Comex::updateFechaPago(). */
            echo json_encode([
                'success' => true,
                'message' => $result['cotizacion_descartada']
                    ? 'Fecha actualizada. El pago pasó de ' . $result['mes_anterior'] . ' a '
                        . $result['mes_nuevo'] . ', así que la cotización que tenía cargada a '
                        . 'mano ($ ' . number_format($result['cotizacion_anterior'], 4, ',', '.')
                        . ') se descartó: este contenedor vuelve a valuarse con la curva de '
                        . 'dólar futuro del mes nuevo.'
                    : 'Fecha actualizada correctamente',
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'getCronoNacionalizacion':
            // IMPORTE_EST viene de un LEFT JOIN sobre la estimación, así que
            // puede ser nulo: esos casos suman cero y no distorsionan.
            echo json_encode([
                'success' => true,
                'data' => EjeVista::armar(
                    ejeDelModulo(),
                    $comex->getCronoNacionalizacion(),
                    'FECHA_NAC_EFECTIVA',
                    'IMPORTE_EST'
                )
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'updateFechaNacPago':
            // Recibir datos del POST
            $postData = file_get_contents('php://input');
            $data = json_decode($postData, true);
            
            if (!isset($data['id_mg']) || !isset($data['fecha_nac_orig']) || !isset($data['fecha_nac_edit'])) {
                throw new Exception('Faltan parámetros obligatorios');
            }
            
            $result = $comex->updateFechaNacPago(
                $data['id_mg'],
                $data['fecha_nac_orig'],
                $data['fecha_nac_edit']
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'Fecha actualizada correctamente'
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
