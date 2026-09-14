<?php
/**
 * ParametrosController.php
 * Controlador de la pestana Parametros.
 *
 * La pestana es de nivel raiz porque va a ir absorbiendo los parametros de los
 * demas modulos: hoy expone los generales, el mix de cobro y la participacion
 * fija de respaldo del modulo de ventas.
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
 * @return array Datos del POST
 */
function bodyJson() {
    $postData = file_get_contents('php://input');
    $data = json_decode($postData, true);

    if (!is_array($data)) {
        throw new Exception('No se recibieron datos validos en el request');
    }

    return $data;
}

/**
 * Mensaje de un guardado de alicuota, con la tasa resultante y lo que dejo el
 * recalculo de los pendientes.
 *
 * @param array $r Resultado de CobElectronicos::addAlicuota()
 * @return string
 */
function mensajeAlicuota($r) {
    return 'Alícuota guardada como vigencia nueva: la anterior queda en el histórico. '
        . 'La tasa total vigente de esa procesadora es ahora del '
        . number_format($r['tasa_total'] * 100, 4, ',', '.') . '%. '
        . mensajeRecalculo($r['recalculo']);
}

/**
 * Que dejo el recalculo automatico de los movimientos pendientes.
 *
 * Se informa SIEMPRE, incluso cuando no cambio nada: un recalculo que no se
 * informa es un cambio de importes en silencio.
 *
 * @param array $rec Resultado de CobElectronicos::recalcularPendientes()
 * @return string
 */
function mensajeRecalculo($rec) {
    $cambios = isset($rec['cambios']) ? count($rec['cambios']) : 0;
    $acreditados = isset($rec['acreditados']) ? intval($rec['acreditados']) : 0;

    $mensaje = ($cambios === 0)
        ? 'No cambió el importe neto de ningún movimiento pendiente.'
        : 'Se recalcularon ' . $cambios . ' movimiento(s) pendiente(s), con una diferencia total '
            . 'de $ ' . number_format(isset($rec['diferencia']) ? $rec['diferencia'] : 0, 2, ',', '.')
            . ' en el neto.';

    if ($acreditados > 0) {
        $mensaje .= ' Los ' . $acreditados . ' movimiento(s) ya acreditados no se tocaron: '
            . 'conservan la tasa con la que se calcularon.';
    }

    foreach (isset($rec['avisos']) ? $rec['avisos'] : [] as $a) {
        $mensaje .= ' ' . $a;
    }

    return $mensaje;
}

try {
    require_once __DIR__ . '/../Class/Parametros.php';

    // Obtener accion del request
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    $parametros = new Parametros();

    switch ($action) {
        case 'getTodo':
            // Una sola llamada para pintar la pestana completa, agrupada por
            // modulo: cada sub-pestana muestra los parametros que afectan a esa
            // pestana de la aplicacion.
            echo json_encode([
                'success' => true,
                'data' => [
                    'canales' => Parametros::CANALES,
                    'modulos' => $parametros->getModulosConDatos(),
                    'avisos' => $parametros->getAvisos()
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getParametros':
            $grupo = isset($_GET['grupo']) ? $_GET['grupo'] : null;

            echo json_encode([
                'success' => true,
                'data' => $parametros->getParametros($grupo)
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveParametro':
            $data = bodyJson();

            if (!isset($data['clave']) || !isset($data['valor'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $parametros->saveParametro(
                $data['clave'],
                $data['valor'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Parametro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getMixCobro':
            $mix = $parametros->getMixCobro();

            echo json_encode([
                'success' => true,
                'data' => [
                    'mix' => $mix,
                    'mix_validacion' => Parametros::validarMix($mix)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMixCobro':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // Validacion en el servidor: los medios ACTIVOS de cada canal deben
            // sumar 100%. Se valida sobre el estado resultante, no sobre lo que
            // manda el cliente sin contrastar.
            $actual = $parametros->getMixCobro();
            $porId = [];

            foreach ($data['filas'] as $fila) {
                if (!isset($fila['id'])) {
                    throw new Exception('Falta el ID de una fila del mix');
                }

                $porId[intval($fila['id'])] = $fila;
            }

            $simulado = [];

            foreach ($actual as $row) {
                $id = intval($row['ID']);

                if (isset($porId[$id])) {
                    $row['PORCENTAJE'] = floatval($porId[$id]['porcentaje']);
                    $row['ACTIVO'] = !empty($porId[$id]['activo']) ? 1 : 0;
                }

                $simulado[] = $row;
            }

            foreach (Parametros::validarMix($simulado) as $canal => $check) {
                if ($check['activos'] === 0) {
                    throw new Exception(
                        'El canal ' . $canal . ' quedaria sin ningun medio de pago activo: '
                        . 'su venta no se convertiria en cobranza.'
                    );
                }

                if (!$check['valido']) {
                    throw new Exception(
                        'El mix activo del canal ' . $canal . ' debe sumar 100%. Suma resultante: '
                        . number_format($check['suma'] * 100, 4, ',', '.') . '%'
                    );
                }
            }

            foreach ($data['filas'] as $fila) {
                $parametros->saveMixCobro(
                    $fila['id'],
                    $fila['porcentaje'],
                    $fila['dias_acreditacion'],
                    !empty($fila['activo']),
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Mix de cobro guardado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addMixCobro':
            $data = bodyJson();

            if (!isset($data['canal']) || !isset($data['medio_pago'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            // Entra inhabilitado y en 0%: activarlo es un paso aparte que exige
            // reacomodar el canal para que vuelva a sumar 100%.
            $id = $parametros->addMixCobro(
                $data['canal'],
                $data['medio_pago'],
                isset($data['dias_acreditacion']) ? $data['dias_acreditacion'] : 0,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Medio de pago agregado. Queda inhabilitado y en 0%: '
                           . 'activalo y reacomoda los porcentajes del canal para guardar.',
                'data' => ['id' => $id]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveRespaldo':
            // Las participaciones de respaldo deben sumar 100% entre si
            $data = bodyJson();

            if (!isset($data['valores']) || !is_array($data['valores'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            $suma = 0;

            foreach (Parametros::CANALES as $canal) {
                $clave = 'respaldo_' . strtolower($canal);

                if (!isset($data['valores'][$clave])) {
                    throw new Exception('Falta la participacion de respaldo de ' . $canal);
                }

                $suma += floatval($data['valores'][$clave]);
            }

            if (abs($suma - 1) >= 0.000001) {
                throw new Exception(
                    'Las participaciones de respaldo deben sumar 100%. Suma recibida: '
                    . number_format($suma * 100, 4, ',', '.') . '%'
                );
            }

            foreach (Parametros::CANALES as $canal) {
                $clave = 'respaldo_' . strtolower($canal);
                $parametros->saveParametro(
                    $clave,
                    (string)floatval($data['valores'][$clave]),
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Participaciones de respaldo guardadas correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           MODULO SALDOS

           El ABM vive en Class/Saldos.php, que es la clase duena de esas
           tablas, y sigue el mismo patron que el mix de cobro: un add que
           chequea la clave natural antes de insertar, un save que no crea, y
           ninguna baja fisica.
           ================================================================ */

        case 'addCuentaSaldo':
            $data = bodyJson();

            if (!isset($data['tipo']) || !isset($data['nombre']) || !isset($data['moneda'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Saldos.php';

            $id = (new Saldos())->addCuenta(
                $data['tipo'],
                $data['nombre'],
                $data['moneda'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Cuenta agregada. Queda activa y sin saldo cargado: '
                           . 'se muestra como "sin cargar" hasta la próxima carga.',
                'data' => ['id' => $id]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveCuentasSaldo':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Saldos.php';

            $saldos = new Saldos();

            foreach ($data['filas'] as $fila) {
                if (!isset($fila['id'])) {
                    throw new Exception('Falta el ID de una cuenta');
                }

                $saldos->saveCuenta(
                    $fila['id'],
                    isset($fila['nombre']) ? $fila['nombre'] : '',
                    isset($fila['moneda']) ? $fila['moneda'] : 'ARS',
                    !empty($fila['activo']),
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Cuentas guardadas correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveSucursalesSaldo':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Saldos.php';

            $saldos = new Saldos();

            foreach ($data['filas'] as $fila) {
                if (!isset($fila['nro_sucursal'])) {
                    throw new Exception('Falta el número de una sucursal');
                }

                $saldos->saveSucursal(
                    $fila['nro_sucursal'],
                    isset($fila['gestion']) ? $fila['gestion'] : 'DEPOSITA',
                    isset($fila['reserva']) ? $fila['reserva'] : 0,
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Locales guardados correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'sincronizarSucursales':
            require_once __DIR__ . '/../Class/Saldos.php';

            // Trae los locales propios habilitados desde el servidor de
            // locales. NO pisa la gestion ni la reserva ya cargadas, y a los
            // que desaparecen del origen los inhabilita en lugar de borrarlos.
            $r = (new Saldos())->sincronizarSucursales(usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Locales sincronizados: ' . $r['altas'] . ' nuevos, '
                           . $r['reactivadas'] . ' reactivados, ' . $r['bajas']
                           . ' inhabilitados. La gestión y la reserva ya cargadas no se tocaron.',
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           MODULO COB. ELECTRONICOS

           El ABM vive en Class/CobElectronicos.php, que es la clase duena de
           esas tablas, con el mismo patron que Saldos: un add que chequea la
           clave natural antes de insertar, un save que no crea, y ninguna baja
           fisica.

           Dos particularidades de este modulo, las dos del lado de la clase:
             - una procesadora nueva entra INACTIVA y no se puede activar sin
               alicuota vigente;
             - guardar o dar de baja una alicuota RECALCULA los movimientos
               pendientes, y el resultado vuelve en la respuesta para que la
               pantalla pueda decir que cambio.
           ================================================================ */

        case 'addProcesadoraCobel':
            $data = bodyJson();

            if (!isset($data['razon_social'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/CobElectronicos.php';

            $id = (new CobElectronicos())->addProcesadora(
                $data['razon_social'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Procesadora agregada. Queda inactiva hasta que tenga al menos una '
                           . 'alícuota vigente: sin alícuota, sus movimientos no podrían calcular '
                           . 'el importe neto.',
                'data' => ['id' => $id]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveProcesadorasCobel':
            $data = bodyJson();

            if (!isset($data['filas']) || !is_array($data['filas'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/CobElectronicos.php';

            $cobel = new CobElectronicos();

            foreach ($data['filas'] as $fila) {
                if (!isset($fila['id'])) {
                    throw new Exception('Falta el ID de una procesadora');
                }

                // Activar una procesadora sin alicuotas vigentes lo rechaza la
                // clase: es el invariante del modulo, no una validacion de
                // pantalla.
                $cobel->saveProcesadora(
                    $fila['id'],
                    isset($fila['razon_social']) ? $fila['razon_social'] : '',
                    !empty($fila['activo']),
                    usuarioActual()
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Procesadoras guardadas correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addAlicuotaCobel':
            $data = bodyJson();

            if (!isset($data['id_procesadora']) || !isset($data['concepto'])
                || !isset($data['alicuota']) || !isset($data['vigencia_desde'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/CobElectronicos.php';

            // SIEMPRE INSERTA UNA VIGENCIA NUEVA: no pisa la anterior, asi los
            // movimientos ya informados conservan su tasa.
            $r = (new CobElectronicos())->addAlicuota(
                $data['id_procesadora'],
                $data['concepto'],
                $data['alicuota'],
                $data['vigencia_desde'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => mensajeAlicuota($r),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaAlicuotaCobel':
            $data = bodyJson();

            if (!isset($data['id'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/CobElectronicos.php';

            // Baja LOGICA. Deja de regir, pero la fila queda: es lo que explica
            // con que tasa se calculo un movimiento de ese periodo.
            $r = (new CobElectronicos())->bajaAlicuota($data['id'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Alícuota dada de baja. ' . mensajeRecalculo($r['recalculo']),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           MODULO ECHEQS - MAESTRO DE PRE-CHEQUEADO

           El ABM vive en Class/Echeqs.php, que es la clase duena de esas
           tablas. Mismo patron que Saldos y Cob. Electronicos: un alta que
           chequea la clave natural antes de insertar y ninguna baja fisica.

           La particularidad de este maestro es que el codigo se valida contra
           dbo.GVA14 antes de guardarlo. Un codigo tipeado mal no da error: da un
           listado vacio en la pestana Echeqs y nadie entiende por que.
           ================================================================ */

        case 'buscarClientePrecheq':
            require_once __DIR__ . '/../Class/Echeqs.php';

            $codigo = isset($_GET['codigo']) ? $_GET['codigo'] : '';
            $cliente = (new Echeqs())->buscarCliente($codigo);

            // No es un error que no exista: es el resultado de una busqueda, y
            // la pantalla lo usa para decidir si habilita el boton de agregar.
            echo json_encode([
                'success' => true,
                'data' => ($cliente === null)
                    ? ['encontrado' => false]
                    : [
                        'encontrado' => true,
                        'codigo' => $cliente['COD_CLIENT'],
                        'razon_social' => $cliente['RAZON_SOCI']
                    ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'addClientePrecheq':
            $data = bodyJson();

            if (!isset($data['codigo'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Echeqs.php';

            // Sirve tambien para reactivar una baja: la clase resuelve cual de
            // los dos casos es y lo dice en la respuesta. Los dias son
            // obligatorios en el alta y opcionales al reactivar -ahi se
            // conserva el plazo que el cliente ya tenia-, y eso tambien lo
            // resuelve la clase.
            $r = (new Echeqs())->guardarClientePrechequeado(
                $data['codigo'],
                array_key_exists('dias', $data) ? $data['dias'] : null,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => ($r['reactivado'] ? 'Cliente reactivado: ' : 'Cliente agregado: ')
                    . $r['cliente'] . ' - ' . $r['razon_social'] . '. '
                    . ($r['cheques_vivos'] > 0
                        ? 'Sus ' . $r['cheques_vivos'] . ' cheque(s) vivos ya aparecen tildados '
                          . 'en Echeqs → Venta Cobrada Anticipada.'
                        : 'Hoy no tiene ningún cheque vivo, así que la pantalla de Echeqs no va a '
                          . 'mostrar nada suyo.'),
                'data' => $r
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveDiasPrecheq':
            $data = bodyJson();

            if (!isset($data['codigo']) || !array_key_exists('dias', $data)) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Echeqs.php';

            $dias = (new Echeqs())->guardarDiasCliente(
                $data['codigo'], $data['dias'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => $dias === 0
                    ? 'El cliente queda sin desplazamiento: sus cheques se netean en su propia '
                      . 'fecha.'
                    : 'La venta se estima ' . $dias . ' día(s) antes de la fecha del cheque.',
                'data' => ['dias' => $dias]
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'bajaClientePrecheq':
            $data = bodyJson();

            if (!isset($data['codigo'])) {
                throw new Exception('Faltan parametros obligatorios');
            }

            require_once __DIR__ . '/../Class/Echeqs.php';

            // Baja LOGICA: la fila queda, sus cheques salen del listado y dejan
            // de netear la cobranza de Ventas.
            (new Echeqs())->bajaClientePrechequeado($data['codigo'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Cliente dado de baja. No se borró: queda inhabilitado, sus cheques '
                           . 'salen del listado y dejan de netear la cobranza proyectada.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* ================================================================
           MODULO COBRANZAS (PPP por grupo empresario y escala de descuento)
           ================================================================ */

        case 'getCobranzasClientesConfig':
            // Grupos empresarios con su PPP y sus franquicias habilitadas.
            echo json_encode([
                'success' => true,
                'data' => $parametros->getCobranzasClientesConfig()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'savePPPManualGrupo':
            $data = bodyJson();

            if (!isset($data['cod_agrup'])) {
                throw new Exception('Falta el grupo empresario');
            }

            $parametros->savePPPManualGrupo(
                $data['cod_agrup'],
                isset($data['ppp_manual']) ? $data['ppp_manual'] : null,
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Plazo Promedio de Pago del grupo actualizado: aplica a todos sus clientes.'
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveMedioPagoCliente':
            $data = bodyJson();

            if (!isset($data['cod_cliente']) || !isset($data['medio_pago'])) {
                throw new Exception('Faltan campos obligatorios para guardar el medio de pago');
            }

            $parametros->saveMedioPagoCliente(
                $data['cod_cliente'],
                $data['medio_pago'],
                usuarioActual()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Medio de pago actualizado correctamente'
            ], JSON_UNESCAPED_UNICODE);
            break;

        /* La escala de descuento es UNA sola y general: no hay endpoint por
           cliente ni por tramo. Se lee y se guarda entera, que es lo unico que
           permite validar que no se solape ni deje huecos. */
        case 'getEscalaDescuento':
            echo json_encode([
                'success' => true,
                'data' => $parametros->getEscalaDescuentoGeneral()
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'saveEscalaDescuento':
            $data = bodyJson();

            if (!isset($data['tramos']) || !is_array($data['tramos'])) {
                throw new Exception('Faltan los tramos de la escala de descuento');
            }

            $escala = $parametros->saveEscalaDescuentoGeneral($data['tramos'], usuarioActual());

            echo json_encode([
                'success' => true,
                'message' => 'Escala de descuento guardada correctamente',
                'data' => $escala
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
