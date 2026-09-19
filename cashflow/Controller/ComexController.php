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

            /* EL EJE SE ARMA SOBRE IMPORTE_EJE Y NO SOBRE IMPORTE_ARS. Los dos
               son la misma valuación; lo que cambia es que el primero vale cero
               cuando el pago ya venció, porque al cashflow entra lo que se paga
               de hoy en adelante. Ver Comex::aporteAlEje(). La columna de la
               grilla sigue mostrando IMPORTE_ARS: el contenedor vale eso
               aunque no entre en el período. */
            $payload = EjeVista::armar(
                ejeDelModulo(),
                $filasExt,
                'FECHA_PAGO_EFECTIVA',
                'IMPORTE_EJE'
            );

            /* Los avisos propios van ADELANTE de los del eje: explican por qué
               hay contenedores que no aparecen con importe, y eso se lee antes
               que lo que quedó fuera del horizonte.

               El de vencidos va en el medio y no al final por el mismo motivo:
               el aviso genérico del eje dice cuánta plata quedó fuera del
               horizonte sin distinguir si cayó antes o después, y éste dice
               cuánta de esa es fecha vencida, que es la que se corrige desde
               esta misma pantalla. */
            $payload['warnings'] = array_merge(
                $comex->getAvisosExterior(),
                Comex::avisosValuacion($filasExt, $comex->dolarFuturo()->ultimoMes()),
                /* SIN EL EJE: acá no hay nada que repartir, porque ninguna
                   vencida entra en ninguna columna —no es que su fecha caiga
                   afuera, es que no suman por regla—. El importe que se informa
                   es IMPORTE_ARS, que es lo que valen; informar IMPORTE_EJE
                   daría "$ 0,00 vencidos", que no le dice nada a nadie. */
                Comex::avisosVencidos($filasExt, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
                    'fecha estimada de pago'),
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

            /* Lo mismo para las fechas, que ahora se escriben sobre el maestro
               de Comercio Exterior: sin la tabla del rastro no se puede dejar
               constancia de quién editó, y editar sin constancia es lo que esta
               entrega vino a terminar. La celda deja de invitar al clic y el
               aviso de arriba dice qué script falta. */
            $payload['fechas_editables'] = $comex->tieneHistorial();

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
            
        /* ================================================================
           LAS DOS FECHAS EDITABLES

           UN SOLO ENDPOINT PARA LAS DOS. Eran dos acciones que hacían lo mismo
           contra columnas distintas; ahora las dos escriben sobre
           RO_T_IMPORTACIONES_ENCABEZADO y dejan el mismo rastro, así que un
           segundo camino sólo podría divergir del primero.

           EL CLIENTE NO MANDA LA FECHA ANTERIOR. Antes viajaba
           'fecha_pago_orig' desde el navegador y era lo que se guardaba como
           valor original: el cliente decidía qué decía que había pisado. Ahora
           el servidor lee el maestro en la misma transacción en la que escribe,
           que es la única forma de que el rastro diga la verdad.
           ================================================================ */
        case 'updateFecha':
            $data = json_decode(file_get_contents('php://input'), true);

            if (!is_array($data) || !isset($data['id_mg']) || !isset($data['campo'])
                || !isset($data['fecha'])) {
                throw new Exception('Faltan parámetros obligatorios: el contenedor, '
                    . 'qué fecha es y cuál es la fecha nueva.');
            }

            $result = $comex->guardarFecha(
                $data['campo'],
                $data['id_mg'],
                $data['fecha'],
                isset($data['usuario']) ? $data['usuario'] : null
            );

            /* EL DESCARTE DE LA COTIZACIÓN SE AVISA. Si el pago se corrió a
               otro mes, el override que alguien había cargado dejó de aplicar y
               la fila volvió a la curva. Sin decirlo, el usuario ve cambiar un
               importe que no tocó. Ver Comex::guardarFecha(). */
            if ($result['sin_cambios']) {
                $mensaje = 'La fecha ya era ésa: no se cambió nada.';
            } elseif ($result['cotizacion_descartada']) {
                $mensaje = 'Fecha actualizada en el maestro de Comercio Exterior. El pago pasó '
                    . 'de ' . $result['mes_anterior'] . ' a ' . $result['mes_nuevo'] . ', así '
                    . 'que la cotización que tenía cargada a mano ($ '
                    . number_format($result['cotizacion_anterior'], 4, ',', '.') . ') se '
                    . 'descartó: este contenedor vuelve a valuarse con la curva de dólar futuro '
                    . 'del mes nuevo.';
            } else {
                /* SE DICE QUE SE ESCRIBIÓ SOBRE EL MAESTRO, y no es un detalle
                   de implementación: quien mueve la fecha desde acá tiene que
                   saber que la está moviendo también para Comercio Exterior. */
                $mensaje = 'Fecha actualizada en el maestro de Comercio Exterior: '
                    . 'la ve también esa aplicación.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Quién movió esta fecha, cuándo, y qué decía antes. Las no vigentes
           son el punto: son lo único que explica por qué el egreso proyectado
           de la semana pasada caía en otra columna. */
        case 'getHistorialFecha':
            echo json_encode([
                'success' => true,
                'data' => $comex->getHistorialFechas(
                    isset($_GET['id_mg']) ? $_GET['id_mg'] : 0,
                    isset($_GET['campo']) ? $_GET['campo'] : null
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getCronoNacionalizacion':
            // IMPORTE_EST viene de un LEFT JOIN sobre la estimación, así que
            // puede ser nulo: esos casos suman cero y no distorsionan.
            $filasNac = $comex->getCronoNacionalizacion();

            $payload = EjeVista::armar(
                ejeDelModulo(),
                $filasNac,
                'FECHA_NAC_EFECTIVA',
                'IMPORTE_EST'
            );

            /* Mismo reparto que en Proveedores Exterior: primero lo que falta
               para poder editar, después lo que no entra en ninguna columna, y
               al final lo que el eje descartó. */
            $avisoDDL = $comex->avisoSinHistorial();

            $payload['warnings'] = array_merge(
                ($avisoDDL === '' ? [] : [$avisoDDL]),
                Comex::avisosVencidos($filasNac, 'FECHA_NAC_EFECTIVA', 'IMPORTE_EST',
                    'fecha de nacionalización', ejeDelModulo()),
                $payload['warnings']
            );

            $payload['fechas_editables'] = $comex->tieneHistorial();

            echo json_encode([
                'success' => true,
                'data' => $payload
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
