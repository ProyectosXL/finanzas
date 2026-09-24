<?php
/**
 * ComprasProyectadasController.php
 * Endpoints de la pestana Compras Proyectadas.
 *
 * LA PESTANA NO CALCULA NADA. Le pide la grilla al MISMO proveedor que alimenta
 * al tablero -ComprasProyectadasProvider::grilla()- y solo la dibuja. Es la
 * misma decision que tomo Comercio Exterior cuando la valuacion se mudo al
 * getter: con la cuenta en dos lados, la pestana y el tablero podrian mostrar
 * dos estimaciones distintas del mismo mes y nadie podria decir cual vale.
 *
 * LO UNICO QUE SE ESCRIBE ES EL AJUSTE MANUAL, que es una tabla del cashflow:
 * una afirmacion del cashflow sobre su propia proyeccion. El presupuesto de
 * compras, Tango y el maestro de Comercio Exterior se LEEN y nada mas.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../Class/Horizonte.php';
    require_once __DIR__ . '/../Class/Parametros.php';
    require_once __DIR__ . '/../Class/ComprasProyectadas.php';
    require_once __DIR__ . '/../Class/ComprasProyectadasDatos.php';
    require_once __DIR__ . '/../Class/ComprasProyectadasAjustes.php';
    require_once __DIR__ . '/../Class/Providers/ComprasProyectadasProvider.php';

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    /** El cuerpo JSON de un POST */
    function cuerpo() {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);

        return is_array($d) ? $d : [];
    }

    /**
     * Los meses que la ventana proyecta HOY.
     *
     * Se resuelve en el servidor y no se acepta del cliente: es lo que impide
     * guardar un ajuste sobre un mes que no aplica a nada. El endpoint es
     * alcanzable sin pasar por la grilla.
     *
     * @param Horizonte $h
     * @return array
     */
    function mesesDeLaVentana($h) {
        $p = new ComprasProyectadasProvider('COMPRAS_PROY');
        $g = $p->grilla($h);

        return array_column($g['meses'], 'mes');
    }

    switch ($action) {
        case 'getGrilla':
            /* EL MISMO EJE QUE EL TABLERO. Sale de horizonte_dias y
               horizonte_meses, igual que las dos pestanas de Comex: lo que la
               grilla muestra tiene que ser exactamente el periodo que se esta
               proyectando, porque de ahi sale el ultimo mes de la ventana. */
            $h = Horizonte::desdeParametros(new Parametros());

            $provider = new ComprasProyectadasProvider('COMPRAS_PROY');

            /* LAS SERIES VAN PRIMERO, Y NO ES INDISTINTO. series() corre el
               calculo entero y acumula los avisos del proveedor -el script sin
               correr, la curva que no se pudo leer, los meses valuados con el
               mes mas cercano-, que son los mismos que el tablero muestra en
               su fila. Pidiendo la grilla primero, esos avisos todavia no
               existen y la pestana los perderia: diria menos que el tablero
               sobre exactamente los mismos numeros.

               La grilla queda cacheada adentro del proveedor, asi que esto NO
               lee la base dos veces. */
            $series = $provider->series($h);
            $g = $provider->grilla($h);

            /* Los avisos de lectura van ADELANTE de los del calculo: explican
               por que puede faltar plata entera -no hay presupuesto, no se
               puede leer lo pagado- y eso se lee antes que un mes sin historia.
               El de la vista va primero de todos: es el unico que deja la fila
               en CERO. */
            $datos = new ComprasProyectadasDatos;
            $avisos = [];

            foreach ([$datos->avisoSinVista(), $datos->avisoSinPagos(),
                      $datos->avisoSinAjustes(),
                      ComprasProyectadasDatos::avisoContraste($datos->contrasteVista())] as $a) {
                if ($a !== '') {
                    $avisos[] = $a;
                }
            }

            /* Los del proveedor ya vienen prefijados con "Compras Proyectadas:"
               porque en el tablero conviven con los de todos los modulos. Aca
               ese prefijo sobra: la pantalla ya dice de que modulo es. */
            foreach ($provider->warnings() as $a) {
                $limpio = preg_replace('/^Compras Proyectadas[^:]*:\s*/', '', $a);

                if (!in_array($limpio, $avisos, true)) {
                    $avisos[] = $limpio;
                }
            }

            $totalesEje = [];

            foreach ($series as $codigo => $s) {
                $totalesEje[$codigo] = [
                    'eje' => array_sum($s['dias']) + array_sum($s['meses']),
                    'fuera_horizonte' => $s['fuera_horizonte'],
                    'tipo_cambio' => $s['tipo_cambio']
                ];
            }

            echo json_encode([
                'success' => true,
                'meses' => $g['filas'],
                'totales' => $g['totales'],
                'totales_eje' => $totalesEje,
                'ventana' => $g['ventana'],
                'cuota' => $g['cuota'],
                'versiones' => array_values($g['versiones']),
                'parametros' => $g['parametros'],
                'avisos' => $avisos,
                'notas' => $g['notas'],
                'hoy' => $h->hoy(),
                'fin_horizonte' => $h->fin()
            ]);
            break;

        case 'getDetalleVersion':
            /* El detalle por rubro de una version, para auditar de donde sale
               el FOB de la temporada. Es el unico lugar del modulo donde se ve
               el inc_fob del presupuesto al lado del porcentaje que el cashflow
               aplica: son 41 % contra 89 %, y esa diferencia tiene que poder
               mirarse fila por fila. */
            $id = isset($_GET['id_version']) ? intval($_GET['id_version']) : 0;

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Falta el id de la versión.']);
                break;
            }

            $datos = new ComprasProyectadasDatos;

            echo json_encode([
                'success' => true,
                'id_version' => $id,
                'detalle' => $datos->detalleVersion($id)
            ]);
            break;

        case 'guardarAjuste':
            $body = cuerpo();

            $ajustes = new ComprasProyectadasAjustes;
            $h = Horizonte::desdeParametros(new Parametros());

            /* EL USUARIO TODAVIA LLEGA NULL: no hay login en el modulo. La
               costura esta puesta -guardar() lo recibe y la tabla lo guarda-
               para que el dia que exista no haya que tocar nada. Es el mismo
               pendiente que el resto del modulo. */
            $r = $ajustes->guardar(
                isset($body['mes']) ? $body['mes'] : '',
                isset($body['importe_usd']) ? $body['importe_usd'] : null,
                isset($body['motivo']) ? $body['motivo'] : '',
                mesesDeLaVentana($h),
                null
            );

            echo json_encode(['success' => true, 'ajuste' => $r]);
            break;

        case 'quitarAjuste':
            $body = cuerpo();

            $ajustes = new ComprasProyectadasAjustes;
            $r = $ajustes->quitar(isset($body['mes']) ? $body['mes'] : '');

            echo json_encode([
                'success' => true,
                'mes' => $r['mes'],
                'sin_cambios' => $r['sin_cambios'],
                'message' => $r['sin_cambios']
                    ? 'Ese mes no tenía ningún ajuste vigente.'
                    : 'El mes vuelve a la estimación automática.'
            ]);
            break;

        case 'getHistorialAjustes':
            $ajustes = new ComprasProyectadasAjustes;
            $mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? $_GET['mes'] : null;

            echo json_encode([
                'success' => true,
                'mes' => $mes,
                'historial' => $ajustes->historial($mes)
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
    }
} catch (Throwable $e) {
    /* Throwable y no Exception: tambien atrapa TypeError y compania. La pestana
       muestra el mensaje en su contenedor de avisos; sin esto, un fallo devuelve
       HTML de error y el fetch se rompe con un mensaje que no dice nada. */
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo calcular la proyección de compras: ' . $e->getMessage()
    ]);
}
