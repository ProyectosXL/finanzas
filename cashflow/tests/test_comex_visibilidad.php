<?php
/**
 * Cuando un contenedor deja de verse en Proveedores Exterior.
 *
 * QUE FIJA. Desde feature/comex-visibilidad-saldo el padron ya no es "sin
 * detalle cargado": un contenedor sale solo cuando tiene costos de
 * nacionalizacion cargados Y los pagos cubren el FOB. La regla vive dos veces
 * aca -Comex::sigueEnProveedores(), pura, y Comex::sqlSigueVisible(), para el
 * WHERE- y una tercera en VisibilidadContenedor del repo administracion. Estas
 * pruebas fijan la pura caso por caso, que el SQL diga lo mismo contra la base,
 * que ComprasProyectadasDatos::cargado() tenga EXACTAMENTE el mismo padron, y
 * que nada de lo que ya cerraba deje de cerrar: el invariante de cuatro series,
 * la deduplicacion por grupo y los avisos.
 *
 * Y el disparador del recalculo de la estimacion en Comex, que vive en el
 * navegador -Js/Comex-fechas.js- y se prueba leyendo el codigo.
 */

require_once __DIR__ . '/../Class/Comex.php';
require_once __DIR__ . '/../Class/Horizonte.php';
require_once __DIR__ . '/../Class/ComprasProyectadasDatos.php';
require_once __DIR__ . '/../Class/Providers/ComexProvider.php';

if (!function_exists('codigoSinComentariosVIS')) {
    /** Ver codigoSinComentarios() en test_comex_fecha_maestra.php */
    function codigoSinComentariosVIS($ruta) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

seccion('(B): que estados cubren el FOB');

chequear('CANCELADO cubre', true, Comex::fobCubierto(Comex::ESTADO_CANCELADO));
chequear('SOBREPAGO cubre', true, Comex::fobCubierto(Comex::ESTADO_SOBREPAGO));
chequear('PENDIENTE no', false, Comex::fobCubierto(Comex::ESTADO_PENDIENTE));
chequear('SIN_FOB no: no hay contra que medir', false, Comex::fobCubierto(Comex::ESTADO_SIN_FOB));

seccion('NOT (A AND B): solo se va con costos Y el FOB cubierto');

$casos = [
    [false, 'PENDIENTE', true,  'sin costos y con saldo'],
    [false, 'CANCELADO', true,  'sin costos y pagado: sigue, como antes (sale solo del eje)'],
    [false, 'SOBREPAGO', true,  'sin costos y con sobrepago'],
    [false, 'SIN_FOB',   true,  'sin costos ni FOB'],
    [true,  'PENDIENTE', true,  'con costos y saldo pendiente: ESTO es lo nuevo'],
    [true,  'SIN_FOB',   true,  'con costos y sin FOB: no esta cubierto'],
    [true,  'CANCELADO', false, 'con costos y pagado: se va'],
    [true,  'SOBREPAGO', false, 'con costos y sobrepago: se va'],
];
foreach ($casos as $c) {
    chequear($c[3], $c[2], Comex::sigueEnProveedores($c[0], $c[1]));
}

/* Con los numeros: la regla pura sobre saldoPendiente(), que es como la usa
   la base. El borde del centavo es el de Comercio Exterior. */
chequear('con costos, 100,00 pagado de 100,00: se va', false,
    Comex::sigueEnProveedores(true, Comex::saldoPendiente(100.00, 100.00)['estado']));
chequear('con costos, medio centavo de menos: se va igual', false,
    Comex::sigueEnProveedores(true, Comex::saldoPendiente(100.00, 99.995)['estado']));
chequear('con costos, 100,01 contra 100,00 en double: queda (PENDIENTE)', true,
    Comex::sigueEnProveedores(true, Comex::saldoPendiente(100.01, 100.00)['estado']));

seccion('el SQL: EXISTS, por grupo, en FLOAT');

$sql = Comex::sqlSigueVisible('F', 'P');
chequear('(A) es un EXISTS', true, strpos(Comex::sqlTieneCostos(), 'EXISTS') === 0);
chequear('(A) mira el grupo entero', true,
    strpos(Comex::sqlTieneCostos(), 'COALESCE(GV.ID_PADRE, GV.ID) = ' . Comex::OC_PRINCIPAL) !== false);
chequear('castea a FLOAT antes de restar, como saldoPendiente()', true, substr_count($sql, 'AS FLOAT') >= 2);
chequear('con la tolerancia de un centavo', true, strpos($sql, '<= ' . Comex::TOLERANCIA_SALDO) !== false);

$codigoComex = codigoSinComentariosVIS(__DIR__ . '/../Class/Comex.php');
preg_match('/function getProveedoresExterior.*?\n    \}/s', $codigoComex, $cuerpoExt);
preg_match('/function getCronoNacionalizacion.*?\n    \}/s', $codigoComex, $cuerpoNac);

chequear('Proveedores Exterior ya no hace LEFT JOIN al detalle', false,
    stripos($cuerpoExt[0], 'LEFT JOIN RO_T_IMPORTACIONES_DETALLE') !== false);
chequear('y filtra con filtroPadron()', true, strpos($cuerpoExt[0], '$this->filtroPadron()') !== false);
chequear('y trae TIENE_COSTOS para la etiqueta', true, strpos($cuerpoExt[0], 'TIENE_COSTOS') !== false);
chequear('Crono Nacionalizacion NO cambio su criterio', true,
    strpos($cuerpoNac[0], 'LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG') !== false
    && strpos($cuerpoNac[0], 'WHERE B.ID_MG IS NULL') !== false);

$codigoCP = codigoSinComentariosVIS(__DIR__ . '/../Class/ComprasProyectadasDatos.php');
preg_match('/function cargado\(.*?\n    \}/s', $codigoCP, $cuerpoCargado);
chequear('cargado() usa EL MISMO filtro que la pestana', true,
    strpos($cuerpoCargado[0], '$this->comex->filtroPadron()') !== false);
chequear('y no conserva el corte viejo', false,
    stripos($cuerpoCargado[0], 'ID_MG IS NULL') !== false);

seccion('la grilla marca los que siguen por el saldo');

$jsProv = codigoSinComentariosVIS(__DIR__ . '/../Js/Comex-Proveedores_exterior.js');
chequear('la celda del contenedor mira TIENE_COSTOS', true, strpos($jsProv, 'item.TIENE_COSTOS') !== false);
chequear('y dice "Costos cargados"', true, strpos($jsProv, '>Costos cargados<') !== false);
chequear('con el estilo de las etiquetas de la pestana', true,
    strpos(file_get_contents(__DIR__ . '/../Css/Comex-Proveedores_exterior.css'),
        '.tab-proveedores_exterior .marca-costos-cargados') !== false);

seccion('mover la nacionalizacion recalcula la estimacion en Comex');

$jsFechas = codigoSinComentariosVIS(__DIR__ . '/../Js/Comex-fechas.js');
chequear('la URL del endpoint esta en UN solo lugar', 1,
    substr_count($jsFechas, '/administracion/comercioExterior/controller/recalcularEstimacion.php'));
chequear('y el fetch usa la constante', true, strpos($jsFechas, 'fetch(RECALCULO_ESTIMACION_URL') !== false);
chequear('el entorno viaja explicito', true,
    strpos($jsFechas, "var ENTORNO_COMEX = 'central'") !== false
    && strpos($jsFechas, 'entorno: ENTORNO_COMEX') !== false);
chequear('solo para la fecha de nacionalizacion, y solo si cambio', true,
    strpos($jsFechas, "cell.dataset.campo === 'NAC' && !(result.data && result.data.sin_cambios)") !== false);
chequear('y refresca despues, salga como salga', true,
    strpos($jsFechas, 'recalcularEstimacionComex(cell.dataset.id).then(refrescar)') !== false);
chequear('si falla avisa que la fecha se guardo igual', true,
    strpos($jsFechas, 'La fecha se guardó pero no se pudo recalcular la estimación') !== false);
chequear('con una advertencia visible, no un console.log', true,
    (bool) preg_match('/function recalcularEstimacionComex.*?Notificacion\.advertencia\(/s', $jsFechas));

/* ======================================================================
   CONTRA LA BASE, SOLO LECTURA
   ====================================================================== */
seccion('contra la base: el padron es el de la regla pura');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $comex = new Comex();
    $cid = (new Conexion)->conectar('central');

    /* El maestro entero con los datos crudos de la regla, para aplicarle la
       funcion pura y comparar contra lo que lista el SQL. */
    $pagado = $comex->tienePagosComex()
        ? '(SELECT ISNULL(SUM(PG.MONTO), 0) FROM ' . Comex::TABLA_PAGOS
          . ' PG WHERE PG.ID_ENCABEZADO = COALESCE(A.ID_PADRE, A.ID))'
        : 'CAST(0 AS DECIMAL(18,2))';

    $stmt = sqlsrv_query($cid,
        "SELECT A.ID,
                CASE WHEN " . Comex::sqlTieneCostos() . " THEN 1 ELSE 0 END TIENE_COSTOS,
                ISNULL(P.VALOR_FOB_DOLAR, A.VALOR_FOB_DOLAR) FOB,
                " . $pagado . " PAGADO
         FROM " . Comex::TABLA_MAESTRO . " A
         LEFT JOIN " . Comex::TABLA_MAESTRO . " P ON P.ID = COALESCE(A.ID_PADRE, A.ID)");

    $esperados = [];
    $todas = 0;
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $todas++;
        $estado = Comex::saldoPendiente($r['FOB'], $r['PAGADO'])['estado'];
        if (Comex::sigueEnProveedores(intval($r['TIENE_COSTOS']) === 1, $estado)) {
            $esperados[] = intval($r['ID']);
        }
    }

    $ext = $comex->getProveedoresExterior();
    $ids = array_map('intval', array_column($ext, 'ID'));
    sort($esperados);
    sort($ids);

    chequear('Proveedores Exterior lista exactamente lo que dice la regla pura ('
        . count($esperados) . ' de ' . $todas . ')', $esperados, $ids);
    chequear('sin filas repetidas: el EXISTS no multiplica', count($ids), count(array_unique($ids)));

    $cubiertasConCostos = 0;
    $conCostos = 0;
    foreach ($ext as $f) {
        if ($f['TIENE_COSTOS']) {
            $conCostos++;
            if (Comex::fobCubierto($f['ESTADO_PAGO'])) {
                $cubiertasConCostos++;
            }
        }
    }
    chequear('ninguna fila listada tiene costos y el FOB cubierto', 0, $cubiertasConCostos);
    echo '    (con costos cargados y saldo pendiente: ' . $conCostos . ')' . PHP_EOL;

    /* DUPLICA_GRUPO cuenta OCs, no lineas de costo: GRUPO_FILAS tiene que ser
       la cantidad real de filas del grupo en la grilla. Con el LEFT JOIN de
       antes aplicado a contenedores con detalle, contaria lineas. */
    $porGrupo = [];
    foreach ($ext as $f) {
        $porGrupo[$f['GRUPO_ID']] = isset($porGrupo[$f['GRUPO_ID']]) ? $porGrupo[$f['GRUPO_ID']] + 1 : 1;
    }
    $grupoMal = 0;
    foreach ($ext as $f) {
        if ($f['GRUPO_FILAS'] !== $porGrupo[$f['GRUPO_ID']]) {
            $grupoMal++;
        }
    }
    chequear('GRUPO_FILAS cuenta OCs del grupo, no lineas de costo', 0, $grupoMal);

    seccion('contra la base: los dos padrones son el mismo');

    /* ES LO QUE IMPIDE CONTAR DOS VECES EL MISMO SALDO. cargado() descuenta
       el pendiente de estos contenedores del presupuesto de compras: si tuviera
       uno que la pestana no, o al reves, el tablero diria dos cosas sobre la
       misma plata. Se comparan los IDs, no solo cuantos son. */
    $d = new ComprasProyectadasDatos;
    $cargado = $d->cargado();

    $vivos = [];
    foreach ($ext as $f) {
        if (empty($f['DUPLICA_GRUPO'])) {
            $vivos[] = intval($f['ID']);
        }
    }
    $idsCargado = array_map(function ($c) { return $c['id']; }, $cargado);
    sort($vivos);
    sort($idsCargado);

    chequear('cargado() trae exactamente los mismos contenedores que la pestana', $vivos, $idsCargado);

    $pendienteCargado = 0.0;
    foreach ($cargado as $c) {
        $pendienteCargado += $c['pendiente_usd'];
    }
    $pendientePestana = 0.0;
    foreach ($ext as $f) {
        if (empty($f['DUPLICA_GRUPO'])) {
            $pendientePestana += floatval($f['PENDIENTE_USD']);
        }
    }
    chequear('y el mismo pendiente en dolares', round($pendientePestana, 2), round($pendienteCargado, 2));

    seccion('contra la base: el invariante de cuatro series sigue cerrando');

    $prov = new ComexProvider('COMEX_PROV_EXT');
    $series = $prov->series(new Horizonte(28, 12));

    $faltan = array_diff(['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX', 'PAGOS_TODO'], array_keys($series));
    chequear('el proveedor arma las cuatro series', [], array_values($faltan));

    $descuadre = 0;
    $columnas = 0;
    if (empty($faltan)) {
        foreach (['dias', 'meses'] as $tramo) {
            foreach ($series['PAGOS_TODO'][$tramo] as $k => $todo) {
                $columnas++;
                $suma = 0.0;
                foreach (['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX'] as $parte) {
                    $suma += isset($series[$parte][$tramo][$k]) ? floatval($series[$parte][$tramo][$k]) : 0.0;
                }
                if (abs($suma - floatval($todo)) > 0.01) {
                    $descuadre++;
                }
            }
        }
    }
    chequear('PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO en las ' . $columnas . ' columnas',
        0, $descuadre);

    $fallaDura = array_filter($prov->warnings(), function ($w) {
        return stripos($w, 'no se pudo calcular') !== false;
    });
    chequear('el proveedor no cae en su aviso de falla', [], array_values($fallaDura));

    /* Las filas con costos cargados y sin fecha estimada de pago no se pueden
       valuar -sin mes no hay cotizacion- y ComexProvider lo dice en dolares.
       Con la regla nueva son muchas (250 al 01/10/2026): el aviso tiene que
       estar. */
    $sinFecha = 0;
    foreach ($ext as $f) {
        if ($f['FECHA_PAGO_EFECTIVA'] === null && $f['PENDIENTE_USD'] > 0) {
            $sinFecha++;
        }
    }
    /* El aviso ya no empieza con "Proveedores Exterior: ": desde el panel
       agrupado, la pestana la dice el grupo y no cada texto. Se lo reconoce
       por lo que dice -que no se pueden valuar por falta de la fecha
       estimada de pago-, que es lo que este control tiene que encontrar. */
    if ($sinFecha > 0) {
        $avisoValuacion = array_filter($prov->warnings(), function ($w) {
            return strpos($w, 'no se pueden valuar') !== false
                && strpos($w, 'fecha estimada de pago') !== false;
        });
        chequear('hay ' . $sinFecha . ' con saldo y sin fecha de pago, y el tablero lo avisa', true,
            count($avisoValuacion) > 0);
    }
}
