<?php
/**
 * ProveedoresController.php
 * Endpoints de Proveedores Locales: el listado de cuentas a pagar, las dos
 * importaciones y la conciliacion contra Tango.
 *
 * NADA SE ESCRIBE SIN CONFIRMAR. Las operaciones que tocan datos en masa
 * -importar el maestro, importar pagos y asignar retenciones- tienen su paso de
 * previsualizacion: primero se devuelve QUE CAMBIARIA y recien despues, con una
 * llamada aparte, se aplica. Es el mismo criterio de Cob. Electronicos, y el
 * motivo es que pueden mover cientos de filas de una: sin ver el diff antes, un
 * archivo equivocado se descubre despues.
 *
 * LA EXCEPCION ES LA CONCILIACION, y es a proposito: no decide nada, copia lo
 * que dice Tango. Corre sola al cargar los pendientes, para cualquiera que
 * pueda ver la pestaña, con un usuario fijo. Ver getPendientes.
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

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

/* La lectura de lo subido y la respuesta de las plantillas son las mismas que
   las de Cob. Electronicos: viven en un solo lugar. */
require_once __DIR__ . '/planilla_subida.php';

try {
    require_once __DIR__ . '/../Class/Proveedores.php';
    require_once __DIR__ . '/../Class/EjeVista.php';
    require_once __DIR__ . '/../Class/Parametros.php';
    // Por importeAPagar(): los indicadores suman lo mismo que la fila del tablero.
    require_once __DIR__ . '/../Class/Providers/ProveedoresProvider.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    /* Quien escribe y si puede, antes de tocar nada: null en las acciones
       que solo leen. Ver Controller/autorizacion.php. */
    require_once __DIR__ . '/autorizacion.php';
    $usuario = autorizar('Proveedores', $action);

    /* Las dos plantillas van ANTES de instanciar nada: no necesitan ni base ni
       datos cargados. Devuelven la DEFINICION -titulos, tipos y ejemplos, de la
       misma columnasImportacion() que usa el parser- y el .xlsx lo arma el
       navegador. Ver Planilla::plantilla(). */
    if ($action === 'plantillaPagos') {
        responderPlantilla(Proveedores::plantilla());
    }

    if ($action === 'plantillaMaestro') {
        responderPlantilla(ProveedoresCategorias::plantilla());
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
            $items = $prov->getPendientes($h->hoy(), $h);

            /* LA CONCILIACION CORRE SOLA, antes de devolver la grilla y para
               cualquiera que pueda ver la pestaña: no es una decisión de nadie,
               es copiar lo que dice Tango. Firma con un usuario fijo
               (Proveedores::usuarioConciliacion()). Usa los pendientes que se
               acaban de leer, así que sólo consulta Tango por las previstas que
               salieron de ahí.

               SI FALLA, LA PESTAÑA CARGA IGUAL: la grilla no depende de ella. Se
               avisa en atención. */
            $avisosConciliacion = [];

            try {
                $avisosConciliacion = $prov->conciliarAutomatica($items)['avisos'];
            } catch (Throwable $e) {
                $avisosConciliacion = [Aviso::nuevo(Aviso::WARNING, 'No se pudo conciliar con Tango ('
                    . $e->getMessage() . '). La grilla se muestra igual; lo ya pagado sale de los '
                    . 'pendientes por sí solo, y la conciliación se reintenta en la próxima carga.',
                    'Conciliación')];
            }

            /* EL EJE VA EN NETO: cada comprobante cae en su fecha por lo que sale
               de la caja -el neto de retenciones donde las hay-, igual que la
               fila del tablero. El pendiente sigue en su columna. */
            $payload = EjeVista::armar($h, $items, 'Pago', 'IMPORTE_A_PAGAR');

            /* Los avisos propios van ADELANTE de los del eje: explican por que
               hay tanto importe en la columna de hoy, y eso se lee antes que lo
               que quedo afuera del horizonte.

               Van dos veces: como textos, como siempre, y CON SU NIVEL, que es
               lo que lee el panel de avisos de la pestaña. Son los mismos
               metodos que lee el tablero, asi que el nivel es el mismo en los
               dos lados; los del eje no traen nivel y son atencion. */
            $payload['avisos_con_nivel'] = array_merge(
                $prov->getAvisosConNivel(),
                $avisosConciliacion,
                $prov->avisosCronogramaConNivel(),
                Proveedores::avisosPendientesConNivel($items),
                Aviso::lista($payload['warnings'])
            );
            $payload['warnings'] = Aviso::textos($payload['avisos_con_nivel']);

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
            /* Los impuestos activos, en su orden: el pie, los indicadores y el
               Excel llevan una columna por cada uno. Vacio sin el script. */
            $retCfg = $prov->retenciones()->config();
            $payload['retenciones'] = [
                'disponible' => !empty($retCfg['disponible']),
                'impuestos' => ProveedoresRetenciones::paraMaestro($retCfg, [], $h->hoy())['impuestos']
            ];

            $payload['forma_por_factura'] = $prov->tieneColumnaPago('FORMA_PAGO_CRONOGRAMA');
            $payload['excluir_factura'] = $prov->tieneColumnaPago('EXCLUIDA');
            $payload['excluir_proveedor'] = $prov->exclusion()->tablaCreada();

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
                $usuario
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

            $r = $prov->saveFechaMasiva($comprobantes, $data['fecha_pago'], $usuario);

            $cuantas = $r['tocados'];

            /* El mensaje dice QUE FECHA quedo, y no solo que se guardo: es un
               gesto que toca muchas filas de una, y lo unico que permite notar
               un dedazo en el año es leer la fecha que quedo escrita. Y cuantas
               se saltearon por estar pagadas con dos medios. */
            echo json_encode([
                'success' => true,
                'message' => ($cuantas === 1
                        ? 'La factura queda prevista para '
                        : $cuantas . ' facturas quedan previstas para ')
                    . date('d/m/Y', strtotime($r['fecha'])) . '.'
                    . ($r['omitidos'] > 0
                        ? ' ' . $r['omitidos'] . ' pagada(s) con dos medios no se tocaron.'
                        : ''),
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
                $usuario
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
                $usuario
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

            $habia = $prov->deletePago($data['cod_provee'], $data['t_comp'], $data['n_comp'],
                $usuario);

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

            /* Los pendientes SIN el horizonte: una fila por vencimiento, sin
               partir, que es lo que el diff necesita para saber el pendiente de
               cada comprobante. Las aperturas vigentes van aparte, para que
               reimportar igual sea SIN_CAMBIOS y una fila sola deshaga los dos
               medios. */
            /* El IMPORTE se compara contra el NETO a pagar: el total menos las
               retenciones del comprobante, con las alicuotas de ahora. */
            $pendientes = $prov->getPendientes($h->hoy());

            /* LOS QUE NO CRUZAN SE MIRAN EN TANGO, y sólo ésos: si ya se
               pagaron son una advertencia con la fecha real, no un error. */
            $enTango = $prov->estadoComprobantesEnTango(
                Proveedores::sinCruce($parse['filas'], $pendientes));

            $comp = Proveedores::compararImportacion(
                $parse['filas'], $pendientes, $prov->getPagos(), $h->hoy(),
                $prov->getAperturas(), $prov->tablaMedioCreada(), $prov->retenciones()->config(),
                $enTango);

            $comp['separador'] = $parse['separador'];

            echo json_encode(['success' => true, 'data' => $comp], JSON_UNESCAPED_UNICODE);
            break;

        case 'aplicarPagos':
            $data = bodyJson();

            if (empty($data['comparacion']['filas'])) {
                throw new Exception('No hay nada para importar. Volvé a subir el archivo.');
            }

            /* aplicarImportacion() NO LE CREE al diff que vuelve: recalcula con
               la entrada del archivo contra la base de ahora, y si algo cambio
               desde la previsualizacion no aplica nada. */
            $h = Horizonte::desdeParametros(new Parametros());
            $r = $prov->aplicarImportacion($data['comparacion'], $usuario, $h->hoy());

            echo json_encode([
                'success' => true,
                'message' => $r['altas'] . ' comprobante(s) con fecha nueva y ' . $r['cambios']
                    . ' modificado(s).',
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
               vacío: null significa "no se pudo validar" —ninguna fila se marca
               en error y la importación no se puede confirmar— y un mapa vacío
               significa "ninguno de estos códigos existe", que sí deja cada
               fila en error. Ver ProveedoresCategorias::compararImportacion().

               Se pregunta primero si la tabla está: sin eso, una planilla sin
               filas devolvería un mapa vacío sin haber consultado nada, y el
               diff leería "ninguno existe" donde no se preguntó. */
            $validos = null;

            try {
                if ($prov->categorias()->tango()->disponible()) {
                    $validos = $prov->categorias()->tango()->existentes(
                        array_column($parse['filas'], 'cod_provee'));
                }
            } catch (Throwable $e) {
                $validos = null;
            }

            /* Las listas de opciones son REGLA: un valor que no está en la suya
               deja la fila en ERROR y no se carga. El resto de la planilla se
               importa igual, que es el mismo alcance por fila que rige para el
               código inexistente.

               Y hay que distinguir los dos motivos por los que puede no haber
               listas. Que no exista la tabla —el script no se corrió— NO
               bloquea: el módulo funciona como antes, con texto libre. Que la
               tabla esté y la consulta falle sí, porque ahí no se chequeó
               ninguna fila. listasVigentes() lanza para poder diferenciarlo. */
            $opciones = null;

            try {
                $opciones = $prov->categorias()->listasVigentes();
            } catch (OpcionesIlegibles $e) {
                $opciones = ProveedoresCategorias::LISTAS_ILEGIBLES;
            }

            $comp = ProveedoresCategorias::compararImportacion(
                $parse['filas'], $prov->categorias()->mapa(), $validos, $opciones);

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
               explicita y no un efecto de importar.

               NO SE MIRA EL 'puede_confirmar' QUE VINO EN EL CUERPO: lo mandó
               el navegador, así que es un dato del pedido y no una
               autorización. aplicarImportacion() vuelve a leer CPA01 y las
               listas y decide con eso. */
            $r = $prov->categorias()->aplicarImportacion(
                $data['comparacion'],
                !empty($data['aplicar_bajas']),
                $usuario
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
            $avisosMaestro = $prov->categorias()->getAvisos();

            $excluidos = $prov->exclusion()->vigentes(ProveedoresExclusion::MODULO_PROV_LOCALES);
            $nombresExcluidos = [];

            if ($prov->exclusion()->avisoSinTabla() !== '') {
                $avisosMaestro[] = $prov->exclusion()->avisoSinTabla();
            }

            /* El nombre de los excluidos que NO están en el maestro sale de
               Tango: sin él, la fila sería un código suelto. Son pocos, así que
               una consulta por código alcanza; si CPA01 no se puede leer se
               muestran sin nombre, que no rompe nada. */
            foreach (array_keys($excluidos) as $codExcl) {
                if (!isset($mapa[$codExcl])) {
                    try {
                        $nombresExcluidos[$codExcl] = $prov->categorias()->tango()->existe($codExcl);
                    } catch (Exception $e) {
                        $nombresExcluidos[$codExcl] = null;
                    }
                }
            }

            /* LAS LISTAS PUEDEN NO PODER LEERSE, Y ESTA PANTALLA NO SE CAE POR
               ESO: el maestro se lee igual y verlo sigue sirviendo. Lo que no se
               va a poder es guardar —guardarManual() lanza con el motivo— así
               que el aviso va acá arriba y no como sorpresa al apretar Guardar.

               Se manda 'opciones' en null, que para el front es "no hay listas":
               los campos vuelven a ser texto libre. Es lo mismo que ve cuando
               falta el script, y es lo correcto, porque dibujar desplegables con
               listas que no se pudieron leer sería dibujarlos vacíos. */
            $opcionesMaestro = null;

            try {
                $opcionesMaestro = $prov->categorias()->listasVigentes();
            } catch (OpcionesIlegibles $e) {
                $avisosMaestro[] = $e->getMessage() . ' Mientras tanto el maestro se puede mirar, '
                    . 'pero no se puede guardar ningún proveedor ni importar la planilla: no hay '
                    . 'contra qué validar los valores.';
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => array_values($mapa),
                    'avisos' => $avisosMaestro,
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

                    /* Las cinco listas de valores válidos, ya en orden
                       alfabético: es el orden en el que se ofrecen, y lo decide
                       el backend para que el SQL no mande un orden que la
                       pantalla ignora. En null si todavía no se corrió su
                       script —o si no se pudieron leer, ver arriba—: la pantalla
                       vuelve a los campos de texto con sugerencias, en lugar de
                       dibujar desplegables vacíos que no dejan cargar nada. */
                    'opciones' => $opcionesMaestro,
                    'opciones_tipos' => ProveedoresOpciones::TIPOS,

                    /* Las sugerencias del datalist quedan para ese caso. No se
                       sacan: son el respaldo de cuando no hay listas. */
                    'rubros' => $prov->categorias()->rubrosCargados(),

                    /* LOS EXCLUIDOS DE PROVEEDORES LOCALES, por código, con su
                       motivo y quién y cuándo. Van aparte de 'filas' porque no
                       son del maestro: un proveedor se puede excluir sin estar
                       en él, y la reimportación de la planilla no los toca.
                       'nombres' trae el de Tango de los que no están en el
                       maestro, para poder mostrarlos. Ver ProveedoresExclusion. */
                    'excluir_proveedor' => $prov->exclusion()->tablaCreada(),
                    'excluidos' => $excluidos,
                    'nombres_excluidos' => $nombresExcluidos,

                    /* LAS RETENCIONES DE CADA PROVEEDOR: los impuestos activos y
                       el valor efectivo de hoy, ya resuelto -general, del
                       proveedor, no se retiene, o no aplica-. Lo resuelve la
                       misma regla del cálculo del neto. Viven en su propia tabla:
                       la importación del maestro no las toca. */
                    'retenciones' => ProveedoresRetenciones::paraMaestro(
                        $prov->retenciones()->config(), array_keys($mapa), date('Y-m-d'))
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LAS ALÍCUOTAS DE RETENCIÓN DE LOS PROVEEDORES

           Se cargan de a muchos -desde la grilla del maestro, con los
           filtros y la selección- o de a uno -desde el proveedor-. Es la misma
           operación: una lista de uno es el caso individual.

           PRIMERO SE MIRA QUÉ HARÍA: a cuántos se aplica, cuántos ya tenían ese
           valor y cuántos tenían otro. Después se aplica, TODO O NADA, y el
           servidor vuelve a calcular el plan contra la base de ahora: lo que
           mostró el diálogo es un pedido, no una autorización.

           Rige DESDE HOY: la fecha la pone el servidor, no el pedido.
           ================================================================ */
        case 'previewRetencionMasiva':
            $data = bodyJson();

            $plan = ProveedoresRetenciones::planAsignacion(
                $prov->retenciones()->config(),
                isset($data['cods']) ? $data['cods'] : [],
                isset($data['impuesto']) ? $data['impuesto'] : '',
                isset($data['accion']) ? $data['accion'] : '',
                isset($data['alicuota']) ? $data['alicuota'] : null,
                date('Y-m-d'),
                $prov->categorias()->mapa());

            echo json_encode(['success' => true, 'data' => ProveedoresRetenciones::resumenPlan($plan)],
                JSON_UNESCAPED_UNICODE);
            break;

        case 'asignarRetencion':
            $data = bodyJson();

            $origen = (isset($data['origen']) && $data['origen'] === ProveedoresRetenciones::ORIGEN_INDIVIDUAL)
                ? ProveedoresRetenciones::ORIGEN_INDIVIDUAL : ProveedoresRetenciones::ORIGEN_MASIVA;

            $plan = $prov->retenciones()->asignar(
                isset($data['cods']) ? $data['cods'] : [],
                isset($data['impuesto']) ? $data['impuesto'] : '',
                isset($data['accion']) ? $data['accion'] : '',
                isset($data['alicuota']) ? $data['alicuota'] : null,
                $origen,
                $prov->categorias()->mapa(),
                $usuario);

            $r = ProveedoresRetenciones::resumenPlan($plan);

            echo json_encode([
                'success' => true,
                'message' => ($r['aplicar'] === 0
                        ? 'No cambió nada: ' . ($r['iguales'] === 1 ? 'ya tenía' : 'ya tenían')
                            . ' ese valor.'
                        : $r['nombre'] . ': ' . $r['aplicar'] . ' proveedor(es) actualizado(s), '
                            . 'desde hoy. El valor anterior queda en el historial.')
                    . ($r['iguales'] > 0 && $r['aplicar'] > 0
                        ? ' ' . $r['iguales'] . ' ya tenían ese valor y no se tocaron.'
                        : ''),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getRetencionesProveedor':
            if (!isset($_GET['cod_provee'])) {
                throw new Exception('Falta el código de proveedor.');
            }

            $cod = Planilla::codigo($_GET['cod_provee']);
            $config = $prov->retenciones()->config();
            $ret = ProveedoresRetenciones::paraMaestro($config, [$cod], date('Y-m-d'));

            echo json_encode([
                'success' => true,
                'data' => [
                    'disponible' => $ret['disponible'],
                    'impuestos' => $ret['impuestos'],
                    'alcance_todos' => $ret['alcance_todos'],
                    'efectivas' => isset($ret['efectivas'][$cod]) ? $ret['efectivas'][$cod] : [],
                    'historial' => $prov->retenciones()->historialProveedor($cod)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EXCLUIR UN PROVEEDOR DE PROVEEDORES LOCALES

           Por proveedor y para toda su deuda: su deuda ya se considera en otra
           pestaña. Motivo obligatorio, código validado contra CPA01, sin bajas
           físicas. Ver ProveedoresExclusion.
           ================================================================ */
        case 'excluirProveedor':
            $data = bodyJson();

            $r = $prov->exclusion()->excluir(
                isset($data['cod_provee']) ? $data['cod_provee'] : '',
                ProveedoresExclusion::MODULO_PROV_LOCALES,
                isset($data['motivo']) ? $data['motivo'] : '',
                $usuario);

            echo json_encode([
                'success' => true,
                'message' => $r['cod_provee'] . ' (' . $r['nombre'] . ') quedó excluido de '
                    . 'Proveedores Locales: toda su deuda sale de la fila del tablero y pasa a '
                    . 'su propia serie.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'incluirProveedor':
            $data = bodyJson();

            if (empty($data['cod_provee'])) {
                throw new Exception('Falta el código del proveedor.');
            }

            $habia = $prov->exclusion()->incluir($data['cod_provee'],
                ProveedoresExclusion::MODULO_PROV_LOCALES, $usuario);

            echo json_encode([
                'success' => true,
                'message' => $habia
                    ? 'Proveedor incluido de nuevo en Proveedores Locales. La exclusión anterior '
                        . 'queda en el historial.'
                    : 'Ese proveedor no estaba excluido.',
                'data' => ['habia' => $habia]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getHistorialExclusion':
            if (!isset($_GET['cod_provee'])) {
                throw new Exception('Falta el código de proveedor.');
            }

            echo json_encode([
                'success' => true,
                'data' => $prov->exclusion()->historial($_GET['cod_provee'],
                    ProveedoresExclusion::MODULO_PROV_LOCALES)
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

            $r = $prov->categorias()->guardarManual($data, $usuario);

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

            $habia = $prov->categorias()->bajaManual($data['cod_provee'], $usuario);

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

        /* LA CONCILIACION NO TIENE ENDPOINT: corre sola en getPendientes. Ver
           Proveedores::conciliarAutomatica(). */

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

    /* Y el proveedor entero excluido de este modulo, que es la tercera
       decision: "esta deuda ya se cuenta en otra pestana". Si una factura
       tiene las dos -proveedor y factura-, gana el proveedor y se cuenta una
       sola vez, aca. Es el mismo reparto que ProveedoresProvider::seriesDeItem(). */
    $excluidoProveedor = 0.0;
    $nExcluidoProveedor = 0;
    $provExcluidos = [];

    $vencimientos = 0;

    /* LO RETENIDO, aparte y por impuesto. Los importes de arriba son lo que sale
       de la caja -el neto donde hay retencion-, igual que el eje y la fila del
       tablero; la retencion es plata que no sale, y se dice cuanta es. */
    $retenido = 0.0;
    $retenidoPorImpuesto = [];

    foreach ($items as $i) {
        $importe = ProveedoresProvider::importeAPagar($i);
        $total += $importe;
        $proveedores[$i['COD_PROVEE']] = true;

        $retenido += isset($i['RETENIDO']) ? floatval($i['RETENIDO']) : 0.0;

        foreach (isset($i['RETENIDO_POR_IMPUESTO']) ? $i['RETENIDO_POR_IMPUESTO'] : [] as $codigo => $r) {
            $retenidoPorImpuesto[$codigo] = (isset($retenidoPorImpuesto[$codigo])
                ? $retenidoPorImpuesto[$codigo] : 0.0) + floatval($r);
        }

        /* Las dos partes de un comprobante pagado con dos medios son UN
           vencimiento: la segunda suma su importe y no se cuenta. Es la misma
           regla que la pantalla, en Proveedores::cuentaComoVencimiento(). */
        $cuenta = Proveedores::cuentaComoVencimiento($i) ? 1 : 0;
        $vencimientos += $cuenta;

        if (!empty($i['EXCLUIDO'])) {
            $excluido += $importe;
        }

        if (!empty($i['EXCLUIDO_PROVEEDOR'])) {
            $excluidoProveedor += $importe;
            $nExcluidoProveedor += $cuenta;
            $provExcluidos[$i['COD_PROVEE']] = true;

            /* Un excluido no queda "fuera del filtro por forma" -ya se decidio
               que no va, y el cartel lo dice en su propia parte- ni es trabajo
               pendiente de fechar: la tarjeta "Vencido sin fecha" mide cuanto
               falta decidir, y de este proveedor ya se decidio. */
            continue;
        }

        if (!empty($i['EXCLUIDA_MANUAL'])) {
            $excluidoManual += $importe;
            $nExcluidoManual += $cuenta;
        }

        if (empty($i['CRONOGRAMA'])) {
            $fuera += $importe;
            $nFuera += $cuenta;

            /* Se desglosa por la forma DEL MAESTRO y no por la del pago
               registrado: es la que decidio que esta fila quedara afuera, y un
               desglose que nombre otra forma no explica nada. */
            $forma = ($i['FORMA_PAGO_MAESTRO'] === null) ? 'sin forma' : $i['FORMA_PAGO_MAESTRO'];
            $porFormaFuera[$forma] = (isset($porFormaFuera[$forma]) ? $porFormaFuera[$forma] : 0)
                + $importe;
        }

        if ($i['ORIGEN_FECHA'] === 'CARGADA') {
            $conFecha += $importe;
            $nConFecha += $cuenta;
        } elseif (!empty($i['SIN_FECHA_CARGADA'])) {
            $vencidoSinFecha += $importe;
            $nVencidoSinFecha += $cuenta;
        }
    }

    arsort($porFormaFuera);

    return [
        'total' => round($total, 2),
        'vencimientos' => $vencimientos,
        'proveedores' => count($proveedores),
        'vencido_sin_fecha' => round($vencidoSinFecha, 2),
        'n_vencido_sin_fecha' => $nVencidoSinFecha,
        'con_fecha' => round($conFecha, 2),
        'n_con_fecha' => $nConFecha,
        'excluido' => round($excluido, 2),
        'excluido_manual' => round($excluidoManual, 2),
        'n_excluido_manual' => $nExcluidoManual,
        'excluido_proveedor' => round($excluidoProveedor, 2),
        'n_excluido_proveedor' => $nExcluidoProveedor,
        'n_proveedores_excluidos' => count($provExcluidos),
        'cronograma' => round($total - $fuera, 2),
        'fuera_cronograma' => round($fuera, 2),
        'n_fuera_cronograma' => $nFuera,
        'fuera_por_forma' => array_map(function ($v) { return round($v, 2); }, $porFormaFuera),
        'retenido' => round($retenido, 2),
        'retenido_por_impuesto' => array_map(function ($v) { return round($v, 2); }, $retenidoPorImpuesto)
    ];
}
