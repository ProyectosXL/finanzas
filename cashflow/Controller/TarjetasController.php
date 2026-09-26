<?php
/**
 * TarjetasController.php
 * Endpoints del circuito de tarjetas: el maestro, los resumenes, el vinculo
 * factura-tarjeta, la exclusion por factura y los datos de las tres
 * sub-pestanas.
 *
 * UN SOLO CONTROLLER PARA LAS CUATRO PANTALLAS, y por eso no vive en
 * ParametrosController: el maestro se da de alta en Parametros -> Tarjetas y los
 * resumenes se cargan desde las tres sub-pestanas de Financiero -> Pagos con
 * Tarjetas y Otros, contra las mismas tablas. Dos endpoints escribiendo lo mismo
 * se desincronizan en la primera validacion que alguien agregue de un solo lado.
 * Es el mismo criterio con el que Logistica y el modulo CASHFLOW declaran su
 * propio endpoint.
 *
 * LA VALIDACION QUE VALE ES LA DE LAS CLASES. La pantalla acota lo que se puede
 * tipear, pero lo que manda el navegador es un pedido y no una autorizacion: el
 * endpoint es alcanzable sin pasar por ella. El banco se vuelve a chequear contra
 * BANCO y el usuario contra la vista en cada guardado, aunque el desplegable los
 * haya ofrecido.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Class/AuthCashflow.php';

/**
 * Usuario que realiza la edicion, para la auditoria de las cuatro tablas.
 *
 * PIDE EL USUARIO DEL PADRON Y NO SOLO $_SESSION['usuario'], a diferencia del
 * resto de los controllers del modulo. Esas cuatro tablas exigen auditoria
 * -USUARIO_ALTA, USUARIO_MODIF, USUARIO_BAJA- y grabarla en NULL la convierte en
 * decoracion: la columna existe, el campo esta, y no contesta quien hizo el
 * cambio.
 *
 * AuthCashflow::init() ya resuelve el usuario de la sesion mirando las cuatro
 * claves con las que las distintas pantallas del sistema la escriben
 * ('username', 'usuario', 'nodo_usuario_activo', 'fp_auth_user'), asi que
 * preguntarle a el es preguntar una sola vez y bien.
 *
 * EL FALLBACK A $_SESSION['usuario'] QUEDA para el caso en que el padron no se
 * pueda leer -Gestionusuarios es otro modulo de htdocs y puede no estar-: ahi
 * AuthCashflow falla cerrado y devuelve null, pero la sesion puede tener el
 * nombre igual. Y si tampoco, se graba NULL, que es lo que hace hoy todo el
 * modulo.
 *
 * El resto del modulo sigue grabando NULL y eso se resuelve aparte: cambiarlo
 * aca para diecinueve pantallas seria un refactor que no es de esta tarea.
 *
 * @return string|null
 */
function usuarioActual() {
    $u = AuthCashflow::usuario();

    if ($u !== null && !empty($u['username'])) {
        return substr(trim((string) $u['username']), 0, 50);
    }

    return isset($_SESSION['usuario']) ? substr(trim((string) $_SESSION['usuario']), 0, 50) : null;
}

/** @return array El cuerpo JSON del request */
function bodyJson() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos válidos en el request');
    }

    return $data;
}

/* ============================================================================
   ARMADO DE LAS FILAS DEL EJE

   Cuatro helpers de PRESENTACION: adaptan lo que PagosTarjetas calculo a la forma
   que EjeVista::armar() y armarAgrupado() esperan. No deciden nada de negocio -no
   suman, no filtran por reglas, no eligen fechas- y por eso viven aca y no en las
   clases: lo unico que hacen es acomodar campos.
   ============================================================================ */

/**
 * Agrega a cada item una 'clave_eje' compuesta, para poder agrupar por mas de un
 * campo.
 *
 * armarAgrupado() agrupa por UN campo. Las subfilas de una supervisora se agrupan
 * por (supervisora, parte), asi que la clave se arma antes en vez de agregarle un
 * segundo parametro a una funcion que usan cuatro pestanas.
 *
 * @param array $items
 * @param array $campos
 * @return array
 */
function conClave($items, $campos) {
    $v = [];

    foreach ($items as $item) {
        $partes = [];

        foreach ($campos as $c) {
            $partes[] = isset($item[$c]) ? (string) $item[$c] : '';
        }

        $item['clave_eje'] = implode('|', $partes);
        $v[] = $item;
    }

    return $v;
}

/**
 * Agrega 'IMPORTE_EJE' a cada factura: su importe si proyecta, y CERO si no.
 *
 * ES LO QUE PERMITE QUE LAS QUE NO ENTRAN SIGAN EN LA GRILLA. Una factura
 * excluida, cubierta por un resumen o vencida sin vincular tiene que verse -con sus
 * columnas descriptivas, su marca y su motivo- y aportar cero al eje. Filtrarlas
 * las escondería, y usar 'IMPORTE' las haria sumar.
 *
 * @param array $filas
 * @return array
 */
function conImporteEje($filas) {
    $v = [];

    foreach ($filas as $f) {
        $f['IMPORTE_EJE'] = !empty($f['PROYECTA']) ? floatval($f['IMPORTE']) : 0.0;
        $v[] = $f;
    }

    return $v;
}

/**
 * Deja solo los items que proyectan.
 *
 * A diferencia de las facturas, la cobertura y los resumenes que no proyectan SI se
 * filtran del eje: no son filas de un listado que haya que poder revisar entera,
 * son renglones calculados que se muestran aparte con su motivo.
 *
 * @param array $items
 * @param string $campo
 * @return array
 */
function soloProyectan($items, $campo) {
    $v = [];

    foreach ($items as $i) {
        if (!empty($i[$campo])) {
            $v[] = $i;
        }
    }

    return $v;
}

/**
 * Extrae de las filas de socios un item por (tarjeta, mes) con un campo como
 * importe.
 *
 * SOLO LOS MESES QUE PROYECTAN, y solo los que tienen ese campo resuelto: un mes en
 * null no es un cero, y mandarlo como cero al eje lo convertiria en uno.
 *
 * @param array $filas
 * @param string $campo 'usd', 'usd_en_pesos' o 'ars'
 * @return array
 */
function celdasDe($filas, $campo) {
    $v = [];

    foreach ($filas as $f) {
        foreach ($f['celdas'] as $mes => $c) {
            if (empty($c['proyecta']) || !isset($c[$campo]) || $c[$campo] === null) {
                continue;
            }

            $v[] = [
                'id_tarjeta' => $f['tarjeta']['ID'],
                'mes' => $mes,
                'fecha' => $c['fecha'],
                'importe' => $c[$campo]
            ];
        }
    }

    return $v;
}

try {
    require_once __DIR__ . '/../Class/Tarjetas.php';
    require_once __DIR__ . '/../Class/TarjetasResumen.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $tarjetas = new Tarjetas();

    switch ($action) {
        /* ================================================================
           LA PESTANA COMPLETA

           UN SOLO PEDIDO PARA LAS TRES SUB-PESTANAS, y no tres. Los insumos son
           compartidos -las tarjetas, los resumenes, el calendario, la inflacion,
           las dos cotizaciones- y PagosTarjetas los lee UNA vez: tres endpoints
           harian tres veces la misma lectura, que contra la base real son unos
           1,4 segundos cada una.

           Y hay una razon mas fuerte: las tres sub-pestanas tienen que describir
           el MISMO estado. Con tres pedidos, uno puede salir antes y otro despues
           de que alguien cargue un resumen, y la pantalla mostraria dos momentos
           distintos a la vez.
           ================================================================ */
        case 'getDatos':
            require_once __DIR__ . '/../Class/PagosTarjetas.php';
            require_once __DIR__ . '/../Class/Parametros.php';
            require_once __DIR__ . '/../Class/Horizonte.php';
            require_once __DIR__ . '/../Class/EjeVista.php';

            $parametros = new Parametros();
            $h = Horizonte::desdeParametros($parametros);
            $datos = (new PagosTarjetas())->calcular($h);

            /* LAS FILAS DEL EJE LAS ARMA EjeVista, Y NO EL NAVEGADOR.
               armarAgrupado() ubica cada importe en su columna aplicando la regla
               "un importe va a un dia O a su mes, nunca a los dos", que es la
               misma que usa el tablero. Si el front repartiera los importes por
               mes a partir del detalle, esa regla quedaria escrita una segunda vez
               -y en JavaScript- y las columnas diarias se perderian.

               UNA LLAMADA POR TIPO DE FILA: armarAgrupado() ubica en el eje UN
               solo campo -el que recibe como importe- y sus 'camposSuma' son
               totales escalares, no series por columna. Asi que cada renglon que
               la pantalla dibuja con importes por columna necesita su propia
               pasada sobre los mismos items. Son pasadas sobre listas de decenas
               de elementos. */
            $sup = $datos['supervisoras'];
            $corp = $datos['corporativas'];
            $soc = $datos['socios'];

            echo json_encode([
                'success' => true,
                'data' => [
                    /* EL EJE VA UNA SOLA VEZ: las tres sub-pestanas dibujan las
                       mismas columnas, y crearEjeVistas() sabe armarse con esto.
                       Un eje por sub-pestana serian tres copias del mismo dato. */
                    'eje' => EjeVista::eje($h),
                    'hoy' => $datos['hoy'],
                    'meses' => $datos['meses'],
                    'tablas' => $datos['tablas'],
                    'avisos' => $datos['avisos'],
                    'tarjetas' => array_values($datos['tarjetas']),
                    'tipos' => Tarjetas::TIPOS,
                    'origenes' => TarjetasResumen::ORIGENES,

                    'supervisoras' => [
                        'ventana' => $sup['ventana'],
                        'cartel' => $sup['cartel'],
                        'filas' => $sup['filas'],
                        'avisos' => $sup['avisos'],
                        'sin_tarjeta' => $sup['sin_tarjeta'],
                        'disponible' => $sup['disponible'],

                        /* Una fila por supervisora -el renglon que se ve- y una por
                           supervisora y parte, que son las dos subfilas
                           expandibles. */
                        'eje_total' => EjeVista::armarAgrupado($h, $sup['pagos'],
                            'supervisora', 'fecha', 'importe'),
                        'eje_partes' => EjeVista::armarAgrupado($h,
                            conClave($sup['pagos'], ['supervisora', 'parte']),
                            'clave_eje', 'fecha', 'importe')
                    ],

                    'corporativas' => [
                        'avisos' => $corp['avisos'],
                        'disponible' => $corp['disponible'],
                        'cobertura' => $corp['cobertura'],
                        'resumenes' => $corp['resumenes'],

                        /* UNA FILA POR VENCIMIENTO, con armar() y no
                           armarAgrupado(): la grilla es el listado de facturas y
                           cada fila es un vencimiento, igual que en Proveedores
                           Locales.

                           SE UBICA 'IMPORTE_EJE' Y NO 'IMPORTE': las que no
                           proyectan -excluidas, cubiertas por un resumen, vencidas
                           sin vincular- tienen que SEGUIR EN LA GRILLA con sus
                           columnas descriptivas, pero aportar CERO al eje. Con
                           'IMPORTE' aportarian su importe y el pie de la tabla
                           dejaria de coincidir con la fila del tablero. */
                        'eje' => EjeVista::armar($h,
                            conImporteEje($corp['filas']), 'FECHA', 'IMPORTE_EJE'),

                        'eje_cobertura' => EjeVista::armar($h,
                            soloProyectan($corp['cobertura'], 'proyecta'),
                            'fecha', 'importe'),
                        'eje_resumenes' => EjeVista::armar($h,
                            soloProyectan($corp['resumenes'], 'proyecta'),
                            'fecha', 'importe')
                    ],

                    'socios' => [
                        'cartel' => $soc['cartel'],
                        'filas' => $soc['filas'],
                        'avisos' => $soc['avisos'],
                        'bcra' => $soc['bcra'],

                        /* CUATRO RENGLONES POR TARJETA, y cada uno necesita su
                           propia pasada: el total en pesos, el componente en U$S
                           -informativo, no suma en pesos-, su equivalente en pesos
                           y el componente en pesos. */
                        'eje_total' => EjeVista::armarAgrupado($h, $soc['pagos'],
                            'id_tarjeta', 'fecha', 'importe'),
                        'eje_usd' => EjeVista::armarAgrupado($h,
                            celdasDe($soc['filas'], 'usd'),
                            'id_tarjeta', 'fecha', 'importe'),
                        'eje_usd_pesos' => EjeVista::armarAgrupado($h,
                            celdasDe($soc['filas'], 'usd_en_pesos'),
                            'id_tarjeta', 'fecha', 'importe'),
                        'eje_ars' => EjeVista::armarAgrupado($h,
                            celdasDe($soc['filas'], 'ars'),
                            'id_tarjeta', 'fecha', 'importe')
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EL VINCULO FACTURA-TARJETA
           ================================================================ */
        case 'vincularFacturas':
            require_once __DIR__ . '/../Class/TarjetasFactura.php';

            $data = bodyJson();

            if (!isset($data['comprobantes']) || !isset($data['id_tarjeta'])) {
                throw new Exception('Faltan las facturas o la tarjeta');
            }

            $r = (new TarjetasFactura())->vincular($data['comprobantes'], $data['id_tarjeta'],
                usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['vinculadas'] . ' factura(s) vinculadas.'
                    . ($r['movidas'] > 0
                        ? ' ' . $r['movidas'] . ' estaban en otra tarjeta y se movieron; las dos '
                          . 'decisiones quedan en el historial.'
                        : '')
                    . ' Ahora generan cobertura y un resumen de esa tarjeta las puede reemplazar.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'desvincularFacturas':
            require_once __DIR__ . '/../Class/TarjetasFactura.php';

            $data = bodyJson();

            if (!isset($data['comprobantes'])) {
                throw new Exception('Faltan las facturas');
            }

            $r = (new TarjetasFactura())->desvincular($data['comprobantes'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['desvinculadas'] . ' factura(s) desvinculadas. No se borra nada: '
                    . 'queda en el historial. Dejan de generar cobertura, y si están vencidas '
                    . 'dejan de entrar al flujo, porque sin tarjeta no hay fecha de pago.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LA EXCLUSION POR FACTURA, DE ESTA PESTANA
           ================================================================ */
        case 'excluirFacturas':
            require_once __DIR__ . '/../Class/TarjetasExclusion.php';

            $data = bodyJson();

            if (!isset($data['comprobantes'])) {
                throw new Exception('Faltan las facturas');
            }

            $r = (new TarjetasExclusion())->excluir($data['comprobantes'],
                isset($data['motivo']) ? $data['motivo'] : null, usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['excluidas'] . ' factura(s) excluidas de Pagos con Tarjetas y '
                    . 'Otros.'
                    . ($r['ya_estaban'] > 0
                        ? ' ' . $r['ya_estaban'] . ' ya estaban excluidas y no se tocaron: su '
                          . 'motivo anterior se conserva.'
                        : '')
                    . ' Salen de la fila del tablero y van a su propia serie, que es '
                    . 'informativa. NO se excluyen de Cuentas a Pagar Locales.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'incluirFacturas':
            require_once __DIR__ . '/../Class/TarjetasExclusion.php';

            $data = bodyJson();

            if (!isset($data['comprobantes'])) {
                throw new Exception('Faltan las facturas');
            }

            $r = (new TarjetasExclusion())->incluir($data['comprobantes'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['incluidas'] . ' factura(s) volvieron al flujo. El motivo de la '
                    . 'exclusión queda en el historial: describe una decisión que estuvo '
                    . 'vigente.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* El historial de un comprobante: a que tarjetas estuvo vinculado y que
           exclusiones tuvo. Los dos juntos, porque la pregunta que alguien se hace
           frente a una factura rara es "que le pasó a esta factura". */
        case 'getHistorialFactura':
            require_once __DIR__ . '/../Class/TarjetasFactura.php';
            require_once __DIR__ . '/../Class/TarjetasExclusion.php';

            foreach (['cod_provee', 't_comp', 'n_comp'] as $campo) {
                if (!isset($_GET[$campo])) {
                    throw new Exception('Falta ' . $campo);
                }
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'vinculos' => (new TarjetasFactura())->historial($_GET['cod_provee'],
                        $_GET['t_comp'], $_GET['n_comp']),
                    'exclusiones' => (new TarjetasExclusion())->historial($_GET['cod_provee'],
                        $_GET['t_comp'], $_GET['n_comp'])
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           EL MAESTRO
           Lo pide Parametros -> Tarjetas, y tambien las tres sub-pestanas para
           poder ofrecer contra que tarjeta cargar un resumen.
           ================================================================ */
        case 'getTarjetas':
            $tipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? $_GET['tipo'] : null;

            echo json_encode([
                'success' => true,
                'data' => [
                    'filas' => $tarjetas->getTarjetas(false, $tipo),
                    'tipos' => Tarjetas::TIPOS,

                    /* LISTAS ORDENADAS Y NO MAPAS: un mapa se convierte en un
                       objeto JSON y Object.keys() no respeta el orden en que se
                       escribio. Ver Tarjetas::comoLista(). */
                    'bancos' => $tarjetas->bancosLista(),
                    'usuarios' => $tarjetas->usuariosLista(),
                    'tabla_creada' => $tarjetas->tablaCreada(),
                    'vista_creada' => $tarjetas->vistaUsuariosCreada(),
                    'bancos_disponibles' => $tarjetas->bancosDisponibles(),
                    'avisos' => $tarjetas->avisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* Sirve para el alta, para corregir los datos y para reactivar una baja:
           la clase resuelve cual de los tres es y lo dice en la respuesta. Una
           accion "reactivar" aparte obligaria a la pantalla a saber de antemano
           en que estado estaba. */
        case 'saveTarjeta':
            $data = bodyJson();

            $r = $tarjetas->guardarTarjeta(
                isset($data['id']) ? $data['id'] : null,
                [
                    'tipo' => isset($data['tipo']) ? $data['tipo'] : null,
                    'cod_banco' => isset($data['cod_banco']) ? $data['cod_banco'] : null,
                    'id_usuario' => isset($data['id_usuario']) ? $data['id_usuario'] : null,
                    'ultimos_4' => isset($data['ultimos_4']) ? $data['ultimos_4'] : null,
                    'pct_cobertura' => isset($data['pct_cobertura'])
                        ? $data['pct_cobertura'] : null,
                    'dia_vencimiento' => isset($data['dia_vencimiento'])
                        ? $data['dia_vencimiento'] : null
                ],
                usuarioActual());

            $mensaje = $r['nuevo']
                ? ('Tarjeta dada de alta: ' . $r['rotulo'] . '.')
                : ($r['reactivada']
                    ? ('Tarjeta reactivada: ' . $r['rotulo'] . '.')
                    : ('Datos guardados: ' . $r['rotulo'] . '.'));

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'activarTarjeta':
            $data = bodyJson();

            if (!isset($data['id']) || !array_key_exists('activa', $data)) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $r = $tarjetas->activarTarjeta($data['id'], !empty($data['activa']), usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['activa']
                    ? ('Tarjeta reactivada: ' . $r['rotulo'] . '.')
                    : ('Tarjeta dada de baja: ' . $r['rotulo'] . '. Deja de proyectar, pero no '
                        . 'se borra: conserva todos sus resúmenes y se puede reactivar.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           LOS RESUMENES
           Se cargan desde las tres sub-pestanas, sobre la fila de la tarjeta.
           ================================================================ */
        case 'getResumenes':
            $resumen = new TarjetasResumen();

            if (isset($_GET['id_tarjeta']) && $_GET['id_tarjeta'] !== '') {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'historial' => $resumen->historial($_GET['id_tarjeta']),
                        'origenes' => TarjetasResumen::ORIGENES,
                        'tabla_creada' => $resumen->tablaCreada(),
                        'aviso' => $resumen->avisoSinTabla()
                    ]
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'vigentes' => $resumen->vigentes(),
                    'origenes' => TarjetasResumen::ORIGENES,
                    'tabla_creada' => $resumen->tablaCreada(),
                    'aviso' => $resumen->avisoSinTabla()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveResumen':
            $data = bodyJson();

            if (!isset($data['id_tarjeta']) || !isset($data['mes'])) {
                throw new Exception('Faltan la tarjeta y el período del resumen');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->guardar($data['id_tarjeta'], $data['mes'], [
                'importe_ars' => isset($data['importe_ars']) ? $data['importe_ars'] : null,
                'importe_usd' => isset($data['importe_usd']) ? $data['importe_usd'] : null,
                'fecha_vencimiento' => isset($data['fecha_vencimiento'])
                    ? $data['fecha_vencimiento'] : null,
                'origen' => isset($data['origen']) ? $data['origen'] : null,
                'observacion' => isset($data['observacion']) ? $data['observacion'] : null
            ], usuarioActual());

            /* SE DICE QUE PISA LA ESTIMACION, y en el mismo mensaje: un "guardado"
               a secas no explica por que la fila del tablero cambio de numero. */
            $mensaje = ($r['nuevo'] ? 'Resumen cargado' : 'Resumen corregido')
                . ' para ' . $r['mes'] . '. Pisa la estimación de ese mes.'
                . ($r['aviso'] !== '' ? ' ' . $r['aviso'] : '');

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'pagarResumen':
            $data = bodyJson();

            if (!isset($data['id']) || !array_key_exists('pagado', $data)) {
                throw new Exception('Faltan parámetros obligatorios');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->marcarPagado($data['id'], !empty($data['pagado']), usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $r['pagado']
                    ? ('Resumen de ' . $r['mes'] . ' marcado como pagado: sale del horizonte, '
                        . 'porque esa plata ya está reflejada en el saldo bancario.')
                    : ('Resumen de ' . $r['mes'] . ' desmarcado: vuelve a proyectarse.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaResumen':
            $data = bodyJson();

            if (!isset($data['id'])) {
                throw new Exception('Falta el resumen');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->darDeBaja($data['id'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Resumen de ' . $r['mes'] . ' dado de baja. No se borra: queda en '
                    . 'el historial, y ese mes vuelve a proyectarse con la estimación.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* La carga inicial de base de Tarjetas Socios: varios resumenes de una,
           en UNA transaccion, con ORIGEN = HISTORICO y ya pagados. */
        case 'cargarBase':
            $data = bodyJson();

            if (!isset($data['id_tarjeta']) || !isset($data['resumenes'])) {
                throw new Exception('Faltan la tarjeta y los resúmenes de la base');
            }

            $resumen = new TarjetasResumen();
            $r = $resumen->cargarBase($data['id_tarjeta'], $data['resumenes'], usuarioActual());

            $mensaje = 'Base histórica cargada: ' . $r['cargados'] . ' resumen(es) ('
                . implode(', ', $r['meses']) . '). Entran como ya pagados, así que no suman al '
                . 'horizonte: son la base con la que se estima.';

            foreach ($r['avisos'] as $a) {
                $mensaje .= ' ' . $a;
            }

            echo json_encode([
                'success' => true,
                'message' => $mensaje,
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
