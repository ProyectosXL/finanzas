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
            /* EL EJE ESTÁ EN PESOS y no en dólares. El cashflow es en pesos y
               esta pestaña es el detalle de una fila del tablero: si mostrara
               dólares, sus totales no se podrían comparar contra la fila que
               explica. Las TRES columnas en dólares —FOB, pagado y pendiente—
               quedan en la grilla como referencia, y son las que se comparan
               contra la pantalla de Comercio Exterior: tienen que dar los
               mismos números que Pagos::obtenerResumen() de allá.

               La valuación fila por fila -con la curva de dólar futuro ROFEX,
               o con el override que alguien cargó- ya viene resuelta del
               getter, así que acá no hay ninguna multiplicación. Y se aplica
               sobre el PENDIENTE: lo que el cashflow proyecta es lo que falta
               pagar. Ver Class/Comex.php y Class/DolarFuturo.php. */
            $filasExt = $comex->getProveedoresExterior();

            /* Y SE ARMA SOBRE IMPORTE_EJE, NO SOBRE IMPORTE_ARS. Los dos son la
               misma valuación en pesos; lo que cambia es que el primero vale
               CERO cuando el pago ya venció o ya se marcó como hecho, porque el
               cashflow proyecta lo que falta pagar de hoy en adelante. Ver
               Comex::aporteAlEje(). La columna de la grilla sigue mostrando
               IMPORTE_ARS: el contenedor vale eso aunque no entre en el
               período. */
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

                /* LO QUE COMERCIO EXTERIOR YA PAGÓ VA ARRIBA DE TODO lo demás
                   de esta pestaña, y no es orden de importancia genérico: es
                   plata que salió de la proyección sin que nadie de este lado
                   hiciera nada, así que es lo primero que hay que poder leer
                   cuando el número de ayer no es el de hoy. El sobrepago va
                   pegado porque es el mismo circuito mirado donde no cierra. */
                Comex::avisosSaldoComex($filasExt),
                Comex::avisosSobrepago($filasExt),
                Comex::avisosGrupo($filasExt),

                /* EN DÓLARES, y sobre el PENDIENTE: lo que no se pudo valuar es
                   lo que esta pantalla proyecta, no el FOB. Con VALOR_FOB_DOLAR
                   el aviso diría de más justamente en los contenedores que ya
                   tienen pagos hechos. */
                Comex::avisosValuacion($filasExt, $comex->dolarFuturo()->ultimoMes(),
                    'PENDIENTE_USD'),
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

            /* Y lo mismo para el tilde de pagado, que tiene su propia tabla:
               sin ella la casilla se dibuja deshabilitada y el aviso de arriba
               dice qué script falta. */
            $payload['pagado_editable'] = $comex->tienePagado();

            /* SI SE PUDO LEER LO YA PAGADO EN COMERCIO EXTERIOR. La grilla lo
               pregunta para no ofrecer un detalle de pagos que no va a poder
               traer, y para poder decir en la columna que el pendiente que
               muestra es el FOB entero porque no hay con qué descontarlo. El
               aviso con el motivo ya está arriba. */
            $payload['pagos_comex'] = $comex->tienePagosComex();

            echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LOS PAGOS YA CARGADOS DE UN CONTENEDOR — SOLO LECTURA

           Es el detalle detrás de la columna "Pagado U$S": de qué pagos sale
           ese número, con fecha, forma, medio e importe.

           NO HAY UN ENDPOINT PARA CARGARLOS, Y NO ES UNA ETAPA PENDIENTE. Los
           pagos se cargan en Comercio Exterior, que es el dueño del circuito;
           un alta de este lado serían dos formularios escribiendo la misma
           tabla con dos validaciones distintas. Es la decisión opuesta a la de
           las fechas —que sí se editan desde acá— y la diferencia está en quién
           es dueño del dato: la fecha estimada de pago la usan las dos
           aplicaciones, el pago al proveedor lo registra una sola.
           ================================================================ */
        case 'getPagosContenedor':
            echo json_encode([
                'success' => true,
                'data' => $comex->getPagosDelContenedor(
                    isset($_GET['id_mg']) ? $_GET['id_mg'] : 0)
            ], JSON_UNESCAPED_UNICODE);
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

        /* ================================================================
           MARCAR UN PAGO COMO YA HECHO

           NO toca el maestro de Comercio Exterior: es una afirmación del
           cashflow sobre su propia proyección. Ver Comex::marcarPagado().

           UN SOLO ENDPOINT PARA MARCAR Y DESMARCAR, por el mismo motivo por el
           que vaciar la cotización es el mismo endpoint que cargarla: deshacer
           tiene que ser el mismo gesto, no un segundo camino que hace lo
           contrario del primero.
           ================================================================ */
        case 'marcarPagado':
            $data = json_decode(file_get_contents('php://input'), true);

            if (!is_array($data) || !isset($data['id_mg']) || !isset($data['concepto'])
                || !array_key_exists('pagado', $data)) {
                throw new Exception('Faltan parámetros obligatorios: el contenedor, '
                    . 'qué pago es y si queda marcado.');
            }

            $r = $comex->marcarPagado(
                $data['concepto'],
                $data['id_mg'],
                !empty($data['pagado']),
                isset($data['observacion']) ? $data['observacion'] : null,
                isset($data['usuario']) ? $data['usuario'] : null
            );

            if ($r['sin_cambios']) {
                $mensaje = 'Ya estaba así: no se cambió nada.';
            } elseif ($r['pagado']) {
                /* SE DICE QUE SALE DEL TABLERO. Es la consecuencia que importa
                   y no se deduce de tildar una casilla. */
                $mensaje = 'Marcado como pagado: sale de la proyección y la fila del tablero '
                    . 'deja de contarlo. El importe no se pierde, y se destilda desde acá.';
            } else {
                $mensaje = 'Vuelve a la proyección: la fila del tablero lo cuenta otra vez.';
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Quién marcó este pago, cuándo, y si alguien lo destildó después. */
        case 'getHistorialPagado':
            echo json_encode([
                'success' => true,
                'data' => $comex->getHistorialPagado(
                    isset($_GET['id_mg']) ? $_GET['id_mg'] : 0,
                    isset($_GET['concepto']) ? $_GET['concepto'] : null
                )
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
            /* IMPORTE_EST viene de un LEFT JOIN sobre la estimación, así que
               puede ser nulo: esos casos suman cero y no distorsionan.

               Y ESTÁ EN DÓLARES: la fila llega valuada desde el getter, con la
               curva ROFEX del mes de su fecha de nacionalización. Ver
               Class/Comex.php y Class/Providers/ComexProvider.php, que es donde
               está el porqué y cómo se verificó. */
            $filasNac = $comex->getCronoNacionalizacion();

            /* Sobre IMPORTE_EJE, igual que Proveedores Exterior: una
               nacionalización con la fecha vencida no suma. Y ese campo sale
               ahora de IMPORTE_ARS —el importe YA CONVERTIDO—, que es el cambio
               que hace que el eje deje de ubicar dólares en columnas de pesos.
               La grilla sigue mostrando IMPORTE_EST en su columna, en dólares. */
            $payload = EjeVista::armar(
                ejeDelModulo(),
                $filasNac,
                'FECHA_NAC_EFECTIVA',
                'IMPORTE_EJE'
            );

            /* Mismo reparto que en Proveedores Exterior: primero lo que falta
               para poder editar, después lo que no se pudo valuar, después lo
               que no entra en ninguna columna, y al final lo que el eje
               descartó. */
            $faltantes = array_values(array_filter(
                [$comex->avisoSinHistorial(), $comex->avisoSinPagado()]));

            $payload['warnings'] = array_merge(
                $faltantes,
                /* Los mismos avisos que el tablero, escritos una sola vez. El
                   campo del importe en dólares y el nombre de la fecha son los
                   de esta pestaña. */
                Comex::avisosValuacion($filasNac, $comex->dolarFuturo()->ultimoMes(),
                    'IMPORTE_EST', 'fecha de nacionalización'),
                /* Sin el eje: ninguna vencida entra en ninguna columna, así que
                   no hay nada que repartir. El importe que se informa es
                   IMPORTE_ARS —lo que valen en pesos—; hasta
                   feature/comex-nac-usd se informaba IMPORTE_EST, que es un
                   número en dólares y salía con el signo de pesos adelante. */
                Comex::avisosVencidos($filasNac, 'FECHA_NAC_EFECTIVA', 'IMPORTE_ARS',
                    'fecha de nacionalización'),
                $payload['warnings']
            );

            /* DE DÓNDE SALE EL DÓLAR, igual que en Proveedores Exterior: un
               importe en pesos que no se puede atar a una cotización
               identificada no se puede auditar contra nada.

               SIN 'editable'. Acá la cotización NO se corrige a mano, y no es
               un olvido: COTIZ_USD_EDIT es una fila por contenedor y las dos
               pestañas valúan el mismo contenedor en dos fechas —y por lo tanto
               en dos meses— distintos. Ver Comex::valuar(). */
            $payload['cotizacion'] = [
                'origen' => DolarFuturo::ORIGEN,
                'disponible' => $comex->dolarFuturo()->disponible(),
                'ultimo_mes' => $comex->dolarFuturo()->ultimoMes(),
                'actualizada' => $comex->dolarFuturo()->actualizada()
            ];

            $payload['fechas_editables'] = $comex->tieneHistorial();

            /* Y lo mismo para el tilde de pagado, que tiene su propia tabla:
               sin ella la casilla se dibuja deshabilitada y el aviso de arriba
               dice qué script falta. */
            $payload['pagado_editable'] = $comex->tienePagado();

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
