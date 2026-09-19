<?php
/**
 * ProveedoresController.php
 * Endpoints de Proveedores Locales: el listado de cuentas a pagar, las dos
 * importaciones y la conciliacion contra Tango.
 *
 * NADA SE ESCRIBE SIN CONFIRMAR. Las tres operaciones que tocan datos en masa
 * -importar el maestro, importar pagos y conciliar- tienen su paso de
 * previsualizacion: primero se devuelve QUE CAMBIARIA y recien despues, con una
 * llamada aparte, se aplica. Es el mismo criterio de Cob. Electronicos, y el
 * motivo es que estas tres pueden mover cientos de filas de una: sin ver el diff
 * antes, un archivo equivocado se descubre despues.
 *
 * EL ARCHIVO NO SE GUARDA EN EL SERVIDOR. Se parsea en memoria y el resultado
 * del diff vuelve al navegador, que lo reenvia al confirmar. Guardar el archivo
 * obligaria a limpiar temporales y a manejar dos usuarios subiendo a la vez.
 *
 * LA VALIDACION QUE VALE ES LA DE ACA. La pantalla acota lo que se puede
 * tipear, pero lo que manda el navegador es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por la pantalla.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

/**
 * Usuario que realiza la edicion.
 * Todavia no hay login: devuelve NULL y se graba NULL.
 * @return string|null
 */
function usuarioActual() {
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

/**
 * El contenido del archivo subido.
 *
 * Acepta las dos formas: un upload real (multipart) y el contenido pegado en un
 * campo de texto. La segunda existe para poder probar el circuito sin subir
 * nada, y porque un usuario con el CSV abierto puede pegarlo mas rapido que
 * guardarlo y buscarlo.
 *
 * @return string
 */
function contenidoSubido() {
    if (isset($_FILES['archivo']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('El archivo no se pudo subir (código '
                . $_FILES['archivo']['error'] . ').');
        }

        return file_get_contents($_FILES['archivo']['tmp_name']);
    }

    if (isset($_POST['contenido']) && trim($_POST['contenido']) !== '') {
        return $_POST['contenido'];
    }

    throw new Exception('No se recibió ningún archivo. Elegí el CSV y volvé a intentar.');
}

/** Devuelve un CSV como descarga */
function descargarCsv($contenido, $nombre) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($contenido));

    echo $contenido;
    exit;
}

try {
    require_once __DIR__ . '/../Class/Proveedores.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    /* Las dos plantillas van ANTES del try de negocio: son descargas, no JSON, y
       no necesitan ni base ni datos cargados. */
    if ($action === 'plantillaPagos') {
        descargarCsv(Proveedores::plantillaCsv(), 'plantilla_pagos_proveedores.csv');
    }

    if ($action === 'plantillaMaestro') {
        descargarCsv(ProveedoresCategorias::plantillaCsv(), 'plantilla_maestro_proveedores.csv');
    }

    $prov = new Proveedores();

    switch ($action) {
        /* ================================================================
           EL LISTADO

           Una fila por vencimiento, con su categoria resuelta y su fecha ya
           ubicada en el eje. El payload es el mismo que usan las pestañas de
           cobranza: EjeVista lo arma contra el horizonte del tablero, asi que
           las columnas, el pie de totales y las tarjetas miden el mismo periodo.
           ================================================================ */
        case 'getPendientes':
            $h = Horizonte::desdeParametros(new Parametros());
            $items = $prov->getPendientes($h->hoy());

            $payload = EjeVista::armar($h, $items, 'Pago', 'IMPORTE_PENDIENTE');

            /* Los avisos propios van ADELANTE de los del eje: explican por que
               hay tanto importe en la columna de hoy, y eso se lee antes que lo
               que quedo afuera del horizonte. */
            $payload['warnings'] = array_merge(
                $prov->getAvisos(),
                Proveedores::avisosPendientes($items),
                $payload['warnings']
            );

            /* Los indicadores de la pestaña. El primero es el que importa: lo
               vencido sin fecha cargada es lo que esta apilado en el dia uno. */
            $payload['indicadores'] = indicadores($items);
            $payload['faltantes'] = $prov->categorias()->faltantesEnMaestro($items);
            $payload['formas_pago'] = ProveedoresCategorias::FORMAS_PAGO;

            /* Las dos formas que la pestaña muestra por defecto. Van en el
               payload y no escritas en el JS: si algun dia cambia el criterio,
               cambia en un solo lugar. */
            $payload['formas_cronograma'] = ProveedoresCategorias::FORMAS_CRONOGRAMA;

            /* Si se puede fijar la forma por factura. La pantalla lo pregunta
               en vez de suponerlo: sin el script, el listado se lee igual y lo
               que no se puede es escribir el override. Un desplegable que se
               dibuja y despues falla al guardar es peor que uno que no esta. */
            $payload['forma_por_factura'] = $prov->tieneColumnaPago('FORMA_PAGO_CRONOGRAMA');
            $payload['excluir_factura'] = $prov->tieneColumnaPago('EXCLUIDA');

            echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           FECHA DE PAGO, DE A UNA
           ================================================================ */
        case 'savePago':
            $data = bodyJson();

            foreach (['cod_provee', 't_comp', 'n_comp', 'fecha_pago'] as $campo) {
                if (!isset($data[$campo]) || $data[$campo] === '') {
                    throw new Exception('Falta el campo ' . $campo . ' para guardar la fecha.');
                }
            }

            $r = $prov->savePago(
                $data['cod_provee'], $data['t_comp'], $data['n_comp'], $data['fecha_pago'],
                isset($data['forma_pago']) ? $data['forma_pago'] : null,
                isset($data['observacion']) ? $data['observacion'] : null,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Fecha de pago guardada.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LA MISMA FECHA DE PAGO PARA VARIAS FACTURAS

           EL MISMO GESTO QUE LA EXCLUSION MASIVA: se eligen con los checks, se
           confirma viendo cuantas son y por cuanta plata, y recien ahi se
           escribe. El caso real no es una factura: son las ocho de un proveedor
           al que se le decide una fecha de una vez.

           UNA SOLA TRANSACCION PARA TODO EL LOTE, y solo se escribe la fecha:
           la forma del cronograma, la exclusion y la observacion de cada
           factura son otras decisiones y no se tocan. Lo hace
           Proveedores::saveFechaMasiva().
           ================================================================ */
        case 'saveFechaMasiva':
            $data = bodyJson();

            $comprobantes = isset($data['comprobantes']) ? $data['comprobantes'] : null;

            if (!is_array($comprobantes) || empty($comprobantes)) {
                throw new Exception('No llegó ninguna factura para fechar.');
            }

            if (!isset($data['fecha_pago']) || $data['fecha_pago'] === '') {
                throw new Exception('Falta la fecha de pago que hay que ponerles.');
            }

            $r = $prov->saveFechaMasiva($comprobantes, $data['fecha_pago'], usuarioActual());

            $cuantas = $r['tocados'];

            /* El mensaje dice QUE FECHA quedo, y no solo que se guardo: es un
               gesto que toca muchas filas de una, y lo unico que permite notar
               un dedazo en el año es leer la fecha que quedo escrita. */
            echo json_encode([
                'success' => true,
                'message' => ($cuantas === 1
                        ? 'La factura queda prevista para '
                        : $cuantas . ' facturas quedan previstas para ')
                    . date('d/m/Y', strtotime($r['fecha'])) . '.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LA FORMA DE PAGO DE UNA FACTURA

           ES UNA REGLA POR FACTURA, no el registro de por donde salio el pago
           -eso es 'forma_pago' y lo escribe la importacion-. Pisa a la del
           maestro SOLO para ese comprobante y decide si su importe entra al
           cashflow. NO TOCA EL MAESTRO.

           Mandar vacio saca el override y vuelve a decidir el maestro.
           ================================================================ */
        case 'saveFormaCronograma':
            $data = bodyJson();

            foreach (['cod_provee', 't_comp', 'n_comp'] as $campo) {
                if (empty($data[$campo])) {
                    throw new Exception('Falta el comprobante al que corresponde la forma.');
                }
            }

            $r = $prov->saveFormaCronograma(
                $data['cod_provee'], $data['t_comp'], $data['n_comp'],
                isset($data['forma']) ? $data['forma'] : '',
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => ($r['forma'] === null)
                    ? 'La forma vuelve a ser la del maestro.'
                    : 'Esta factura se trata como ' . $r['forma'] . '. El maestro no cambia.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EXCLUIR UNA FACTURA DEL CASHFLOW

           Por COMPROBANTE, a diferencia del rubro "Excluidos" del maestro, que
           es por proveedor. El importe sale de la fila del tablero pero no
           desaparece: va a PAGOS_EXCLUIDOS_FACTURA y el proveedor avisa cuanto
           es y con que motivos.

           EL MOTIVO ES OBLIGATORIO y lo valida Proveedores::saveExclusion(),
           no la pantalla: este endpoint es alcanzable sin pasar por la grilla.
           ================================================================ */
        case 'saveExclusion':
            $data = bodyJson();

            if (!array_key_exists('excluida', $data)) {
                throw new Exception('Falta decir si las facturas se excluyen o se incluyen.');
            }

            /* UNA SOLA ACCION PARA UNA Y PARA VARIAS. La pantalla manda siempre
               una lista; 'comprobantes' con un solo elemento es el caso de una.
               Un endpoint aparte para el masivo serian dos caminos que tienen
               que hacer exactamente lo mismo, y la transaccion es justo lo que
               no puede estar escrito dos veces. */
            $comprobantes = isset($data['comprobantes']) ? $data['comprobantes'] : null;

            if (!is_array($comprobantes) || empty($comprobantes)) {
                throw new Exception('No llegó ninguna factura para excluir.');
            }

            $r = $prov->saveExclusionMasiva(
                $comprobantes,
                !empty($data['excluida']),
                isset($data['motivo']) ? $data['motivo'] : null,
                usuarioActual()
            );

            $cuantas = $r['tocados'];

            echo json_encode([
                'success' => true,
                'message' => $r['excluida']
                    ? ($cuantas === 1
                        ? 'Factura excluida: su importe sale del cashflow y queda informado aparte.'
                        : $cuantas . ' facturas excluidas: sus importes salen del cashflow y '
                            . 'quedan informados aparte.')
                    : ($cuantas === 1
                        ? 'Factura incluida de nuevo en el cashflow.'
                        : $cuantas . ' facturas incluidas de nuevo en el cashflow.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deletePago':
            $data = bodyJson();

            foreach (['cod_provee', 't_comp', 'n_comp'] as $campo) {
                if (empty($data[$campo])) {
                    throw new Exception('Falta el comprobante cuya fecha hay que borrar.');
                }
            }

            $habia = $prov->deletePago($data['cod_provee'], $data['t_comp'], $data['n_comp']);

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'La fecha vuelve a ser la de vencimiento.'
                    : 'Ese comprobante no tenía fecha cargada.',
                'data' => ['habia' => $habia]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           IMPORTACION DE PAGOS

           Dos pasos: primero el diff, despues aplicar. Ver la nota del
           encabezado.
           ================================================================ */
        case 'previewPagos':
            $h = Horizonte::desdeParametros(new Parametros());
            $parse = Planilla::parsear(contenidoSubido(), Proveedores::columnasImportacion());

            $comp = Proveedores::compararImportacion(
                $parse['filas'], $prov->getPendientes($h->hoy()), $prov->getPagos(), $h->hoy());

            $comp['separador'] = $parse['separador'];

            echo json_encode(['success' => true, 'data' => $comp], JSON_UNESCAPED_UNICODE);
            break;

        case 'aplicarPagos':
            $data = bodyJson();

            if (empty($data['comparacion']['filas'])) {
                throw new Exception('No hay nada para importar. Volvé a subir el archivo.');
            }

            $r = $prov->aplicarImportacion($data['comparacion'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['altas'] . ' fecha(s) nueva(s) y ' . $r['cambios']
                    . ' modificada(s).',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           IMPORTACION DEL MAESTRO
           ================================================================ */
        case 'previewMaestro':
            $parse = Planilla::parsear(contenidoSubido(),
                ProveedoresCategorias::columnasImportacion());

            /* LOS CÓDIGOS SE VALIDAN CONTRA CPA01 EN UNA SOLA CONSULTA, no una
               por fila: la planilla real tiene 1.223 filas y una consulta por
               cada una serían 1.223 viajes a la base para una pantalla que
               tiene que responder mientras alguien espera.

               Si CPA01 no responde se pasa null, que NO es lo mismo que un mapa
               vacío: null significa "no se pudo validar" y el diff no marca
               nada en error, sólo avisa. Con un mapa vacío marcaría en error la
               planilla entera por un origen caído. */
            $validos = null;

            try {
                $validos = $prov->categorias()->tango()->existentes(
                    array_column($parse['filas'], 'cod_provee'));
            } catch (Throwable $e) {
                $validos = null;
            }

            /* Las listas de opciones son ADVERTENCIA y no error: un valor que
               no está en su lista se importa igual y se guarda tal como vino,
               marcado. La diferencia con CPA01 es qué significa cada cosa: un
               código que no existe hace que el proveedor no clasifique NADA,
               mientras que un rubro fuera de lista sí clasifica —crea su propia
               serie— y lo que hay que decidir es si esa serie tenía que
               existir. */
            $comp = ProveedoresCategorias::compararImportacion(
                $parse['filas'], $prov->categorias()->mapa(), $validos,
                $prov->categorias()->listasVigentes());

            $comp['separador'] = $parse['separador'];
            $comp['opciones_tipos'] = ProveedoresOpciones::TIPOS;

            echo json_encode(['success' => true, 'data' => $comp], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           BUSCADOR DE PROVEEDORES DE TANGO

           Alimenta el autocomplete del alta manual. Busca por código Y por
           nombre porque quien carga un proveedor casi nunca se acuerda del
           código: se acuerda del nombre.

           NO ES LA VALIDACIÓN. Que acá aparezca un proveedor no autoriza nada:
           guardarManual() vuelve a chequear contra CPA01, porque lo que manda
           el navegador es un pedido y no una autorización.
           ================================================================ */
        case 'buscarProveedorTango':
            $q = isset($_GET['q']) ? $_GET['q'] : '';

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $prov->categorias()->tango()->buscar($q),
                    'min' => ProveedoresTango::MIN_BUSQUEDA,
                    'max' => ProveedoresTango::MAX_RESULTADOS
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'aplicarMaestro':
            $data = bodyJson();

            if (empty($data['comparacion']['filas'])) {
                throw new Exception('No hay nada para importar. Volvé a subir el archivo.');
            }

            /* LAS BAJAS SE CONFIRMAN APARTE. Una planilla filtrada por error
               daria de baja medio maestro, asi que aplicarlas es una decision
               explicita y no un efecto de importar. */
            $r = $prov->categorias()->aplicarImportacion(
                $data['comparacion'],
                !empty($data['aplicar_bajas']),
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => $r['altas'] . ' alta(s), ' . $r['cambios'] . ' cambio(s) y '
                    . $r['bajas'] . ' baja(s).',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getMaestro':
            $mapa = $prov->categorias()->mapa();

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => array_values($mapa),
                    'avisos' => $prov->categorias()->getAvisos(),
                    'directores_no_excluidos' => $prov->categorias()->directoresNoExcluidos(),

                    /* El segundo control del maestro: quién está cargado y
                       vigente con un código que Tango no tiene. Sólo avisa; no
                       se da de baja nada automáticamente. Ver
                       ProveedoresCategorias::noEnTango(). */
                    'no_en_tango' => $prov->categorias()->noEnTango(),
                    'tango_disponible' => $prov->categorias()->tango()->disponible(),
                    'formas_pago' => ProveedoresCategorias::FORMAS_PAGO,

                    /* Si se puede cargar a mano. La pantalla lo pregunta en vez
                       de suponerlo: sin el script del origen el maestro se lee
                       igual, y lo que no se puede es escribirlo. Un formulario
                       que se dibuja y despues falla al guardar es peor que uno
                       que no aparece con el motivo al lado. */
                    'edicion_manual' => $prov->categorias()->tieneOrigen(),

                    /* Las cinco listas de valores válidos. En null si todavía
                       no se corrió su script: la pantalla vuelve a los campos
                       de texto con sugerencias —que es como funcionaban antes—
                       en lugar de dibujar desplegables vacíos que no dejan
                       cargar nada. Ver ProveedoresOpciones. */
                    'opciones' => $prov->categorias()->listasVigentes(),
                    'opciones_tipos' => ProveedoresOpciones::TIPOS,

                    /* Las sugerencias del datalist quedan para ese caso. No se
                       sacan: son el respaldo de cuando no hay listas. */
                    'rubros' => $prov->categorias()->rubrosCargados()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           CARGA Y EDICION MANUAL DEL MAESTRO

           Una sola accion para el alta y para la edicion, igual que en Dolares
           Comitente y por el mismo motivo: EDITAR NO ES UN UPDATE. Da de baja
           la version vigente e inserta una nueva, en una transaccion, que es
           exactamente lo que hace un CAMBIO de la importacion. Un endpoint
           'editProveedor' aparte insinuaria que hay un camino que modifica en
           el lugar, y no lo hay.
           ================================================================ */
        case 'saveProveedor':
            $data = bodyJson();

            if (empty($data['cod_provee'])) {
                throw new Exception('Falta el código del proveedor.');
            }

            $r = $prov->categorias()->guardarManual($data, usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => ($r['estado'] === 'ALTA')
                    ? 'Proveedor ' . $r['cod_provee'] . ' agregado al maestro.'
                    : 'Proveedor ' . $r['cod_provee'] . ' actualizado. La versión anterior '
                        . 'queda en el historial.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'deleteProveedor':
            $data = bodyJson();

            if (empty($data['cod_provee'])) {
                throw new Exception('Falta el código del proveedor que hay que dar de baja.');
            }

            $habia = $prov->categorias()->bajaManual($data['cod_provee']);

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'Proveedor dado de baja. Su deuda queda sin clasificar y la versión '
                        . 'anterior sigue en el historial.'
                    : 'Ese proveedor no estaba en el maestro.',
                'data' => ['habia' => $habia]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialProveedor':
            if (!isset($_GET['cod_provee'])) {
                throw new Exception('Falta el código de proveedor.');
            }

            echo json_encode([
                'success' => true,
                'data' => $prov->categorias()->getHistorial($_GET['cod_provee'])
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           CONCILIACION
           ================================================================ */
        case 'previewConciliacion':
            echo json_encode([
                'success' => true,
                'data' => $prov->conciliar(false, usuarioActual())
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'aplicarConciliacion':
            $r = $prov->conciliar(true, usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['resumen']['concilia'] . ' conciliado(s) y '
                    . $r['resumen']['reabre'] . ' reabierto(s).',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción no válida: ' . $action
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Los indicadores de la pestaña.
 *
 * EL PRIMERO ES EL QUE IMPORTA: cuanto hay vencido sin fecha de pago cargada.
 * Es el importe que esta apilado en el primer dia del eje, y el unico numero que
 * dice cuanto trabajo queda por hacer en esta pantalla.
 *
 * @param array $items
 * @return array
 */
function indicadores($items) {
    $total = 0.0;
    $vencidoSinFecha = 0.0;
    $nVencidoSinFecha = 0;
    $conFecha = 0.0;
    $nConFecha = 0;
    $excluido = 0.0;
    $proveedores = [];

    /* El desglose por forma de pago. La pestaña abre filtrada en lo que se
       gestiona por cronograma -echeq y transferencia-, asi que hay que poder
       decir en pantalla CUANTO queda afuera de ese filtro y en cuantas filas:
       un filtro que esconde plata sin decir cuanta es un filtro que miente. */
    $fuera = 0.0;
    $nFuera = 0;
    $porFormaFuera = [];

    /* Lo excluido a mano se cuenta aparte de lo excluido por rubro: son dos
       decisiones distintas -una es "este proveedor no es deuda comercial" y la
       otra "esta factura puntual no va"- y sólo la segunda saca el importe de la
       serie que usa la fila del tablero. */
    $excluidoManual = 0.0;
    $nExcluidoManual = 0;

    foreach ($items as $i) {
        $importe = floatval($i['IMPORTE_PENDIENTE']);
        $total += $importe;
        $proveedores[$i['COD_PROVEE']] = true;

        if (!empty($i['EXCLUIDO'])) {
            $excluido += $importe;
        }

        if (!empty($i['EXCLUIDA_MANUAL'])) {
            $excluidoManual += $importe;
            $nExcluidoManual++;
        }

        if (empty($i['CRONOGRAMA'])) {
            $fuera += $importe;
            $nFuera++;

            /* Se desglosa por la forma DEL MAESTRO y no por la del pago
               registrado: es la que decidio que esta fila quedara afuera, y un
               desglose que nombre otra forma no explica nada. */
            $forma = ($i['FORMA_PAGO_MAESTRO'] === null) ? 'sin forma' : $i['FORMA_PAGO_MAESTRO'];
            $porFormaFuera[$forma] = (isset($porFormaFuera[$forma]) ? $porFormaFuera[$forma] : 0)
                + $importe;
        }

        if ($i['ORIGEN_FECHA'] === 'CARGADA') {
            $conFecha += $importe;
            $nConFecha++;
        } elseif (!empty($i['SIN_FECHA_CARGADA'])) {
            $vencidoSinFecha += $importe;
            $nVencidoSinFecha++;
        }
    }

    arsort($porFormaFuera);

    return [
        'total' => round($total, 2),
        'vencimientos' => count($items),
        'proveedores' => count($proveedores),
        'vencido_sin_fecha' => round($vencidoSinFecha, 2),
        'n_vencido_sin_fecha' => $nVencidoSinFecha,
        'con_fecha' => round($conFecha, 2),
        'n_con_fecha' => $nConFecha,
        'excluido' => round($excluido, 2),
        'excluido_manual' => round($excluidoManual, 2),
        'n_excluido_manual' => $nExcluidoManual,
        'cronograma' => round($total - $fuera, 2),
        'fuera_cronograma' => round($fuera, 2),
        'n_fuera_cronograma' => $nFuera,
        'fuera_por_forma' => array_map(function ($v) { return round($v, 2); }, $porFormaFuera)
    ];
}
