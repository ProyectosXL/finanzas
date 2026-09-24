<?php
/**
 * Compras proyectadas: el proveedor, sus dos series y el script que crea las
 * filas del tablero.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LAS SERIES PROYECTADAS SE SUMAN A LAS DE COMEX. Son dos proveedores que
 *      miden universos DISJUNTOS -lo que ya tiene contenedor cargado contra lo
 *      que todavia no- y las cuatro filas conviven en el tablero. Si alguna
 *      serie de este proveedor terminara declarada como componente de las de
 *      Comex, o si una fila apuntara al proveedor equivocado, el mismo egreso
 *      entraria dos veces y el cuadro cerraria igual.
 *
 *   2. LA NACIONALIZACION SE VALUA CON EL DOLAR DEL MES EQUIVOCADO. Las dos
 *      series miran fechas distintas de la misma compra: el pago va X dias
 *      antes de la recepcion y la nacionalizacion Y. Con una sola valuacion,
 *      la nacionalizacion quedaria convertida con el dolar de un mes en el que
 *      no se mueve.
 *
 *   3. EL AVISO DE VALUACION CUENTA LO QUE NO ES. Comex::avisosValuacion() lee
 *      COTIZ_USD y COTIZ_ORIGEN, los nombres que escribe Comex::valuar(). Una
 *      fila con las dos valuaciones combinadas no puede tener esos campos dos
 *      veces, asi que pasarsela haria que el aviso declare TODOS los meses como
 *      "no se pudieron valuar" -exactamente lo contrario de lo que paso- sin
 *      que nada falle.
 *
 *   4. SIN EL SCRIPT, EL MODULO SE CAE EN VEZ DE AVISAR. Parametros::num()
 *      lanza cuando falta una clave. Si el proveedor lo usara, un script sin
 *      correr dejaria las dos filas en cero con un mensaje generico de
 *      excepcion, en vez de proyectar con los valores iniciales y decir que
 *      falta el script.
 *
 *   5. EL SCRIPT PISA FILAS EXISTENTES. Inserta en los ORDEN 15 y 25 para dejar
 *      cada fila proyectada pegada a su parte real. Si no verificara que el
 *      hueco esta libre, o si reordenara, cambiaria el cuadro sin que nadie lo
 *      pida.
 *
 * Lo que necesita SQL Server se saltea solo.
 */

require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
require_once __DIR__ . '/../cashflow/Class/Horizonte.php';
require_once __DIR__ . '/../cashflow/Class/Providers/ComprasProyectadasProvider.php';

/**
 * El codigo de un archivo, SIN SUS COMENTARIOS.
 *
 * MISMO CRITERIO QUE LAS PRUEBAS DE COMEX, y aca hizo falta enseguida: el
 * encabezado de este script EXPLICA que un PRINT no puede armar su texto con
 * una subconsulta, y buscar ese patron sobre el archivo entero da positivo en
 * la nota que dice que el patron no esta. La unica forma de pasar la prueba
 * seria borrar la explicacion.
 */
if (!function_exists('codigoSinComentariosCPP')) {
    function codigoSinComentariosCPP($ruta) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

/**
 * Un script SQL sin sus comentarios de BLOQUE.
 *
 * SOLO LOS BLOQUES, y no los de linea. Sacar los '--' parece mas prolijo y es
 * peor: en SQL un '--' tambien puede estar adentro de un literal, y este script
 * tiene un PRINT '--- Estado ---'. Borrar desde ahi hasta el fin de linea se
 * come la comilla de cierre y el punto y coma, y a partir de ese punto
 * cualquier patron que busque "PRINT ... algo" da positivo contra el resto del
 * archivo. Toda la prosa de este script vive en bloques, asi que alcanza.
 */
if (!function_exists('sqlSinComentariosCPP')) {
    function sqlSinComentariosCPP($ruta) {
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents($ruta));
    }
}

$provider = __DIR__ . '/../cashflow/Class/Providers/ComprasProyectadasProvider.php';
$registry = __DIR__ . '/../cashflow/Class/CashflowRegistry.php';
$script = __DIR__ . '/../sql/cashflow_compras_proyectadas.sql';

/* ========================================================================
   EL REGISTRO
   ======================================================================== */

seccion('El registro declara el modulo y sus dos series');

chequear('COMPRAS_PROY existe', true, CashflowRegistry::existe('COMPRAS_PROY'));
chequear('y esta disponible', true, CashflowRegistry::disponible('COMPRAS_PROY'));

$reg = null;

foreach (CashflowRegistry::todos() as $p) {
    if ($p['codigo'] === 'COMPRAS_PROY') {
        $reg = $p;
    }
}

chequear('declara sus dos series y solo esas',
    ['PAGOS_PROYECTADOS', 'NACIONALIZACION_PROYECTADA'], array_keys($reg['series']));

/* LA MONEDA IMPORTA: el presupuesto da FOB unitario en dolares y el proveedor
   lo valua con la curva. Declarar ARS es el error que Crono Nacionalizacion
   tuvo hasta feature/comex-nac-usd, y ahi nadie lo vio por meses. */
chequear('declara dolares como moneda de origen', 'USD', $reg['moneda']);

chequear('apunta a su propia pestana', 'compras_proyectadas', $reg['tab']);

seccion('Sus series NO se mezclan con las de Comex');

/* Son universos disjuntos, no partes de un total. Por eso NO hay
   'componentes': declarar una relacion habilitaria a que el validador acepte
   combinaciones que cuentan el mismo egreso dos veces. */
chequear('no declara componentes', false, isset($reg['componentes']));

foreach (['PAGOS_PROYECTADOS', 'NACIONALIZACION_PROYECTADA'] as $serie) {
    chequear('COMEX_PROV_EXT no ofrece ' . $serie,
        false, CashflowRegistry::serieExiste('COMEX_PROV_EXT', $serie));
    chequear('COMEX_NAC no ofrece ' . $serie,
        false, CashflowRegistry::serieExiste('COMEX_NAC', $serie));
}

foreach (['PAGOS', 'PAGOS_TODO', 'NACIONALIZACION', 'NACIONALIZACION_TODO'] as $serie) {
    chequear('COMPRAS_PROY no ofrece ' . $serie,
        false, CashflowRegistry::serieExiste('COMPRAS_PROY', $serie));
}

/* ========================================================================
   EL CABLEADO DEL PROVEEDOR
   ======================================================================== */

seccion('El proveedor valua cada serie por SU fecha');

$src = codigoSinComentariosCPP($provider);

chequear('el FOB se valua por la fecha de pago',
    true, strpos($src, "valuar(\$fila, \$curva, 'FOB_USD', 'FECHA_PAGO')") !== false);

chequear('la nacionalizacion por la suya',
    true, strpos($src, "valuar(\$fila, \$curva, 'NAC_USD', 'FECHA_NAC')") !== false);

chequear('y cada serie se ubica en el eje por su propia fecha',
    true, strpos($src, "agrupar(\$filas, 'FECHA_PAGO', 'IMPORTE_FOB_ARS')") !== false
       && strpos($src, "agrupar(\$filas, 'FECHA_NAC', 'IMPORTE_NAC_ARS')") !== false);

/* SIN MULTIPLICADOR. La valuacion es por fila: un factor unico en agrupar()
   volveria a la epoca del parametro global de tipo de cambio. */
chequear('no se agrupa con un multiplicador global',
    0, preg_match_all('/agrupar\([^)]*,\s*[\d.]+\s*\)/', $src));

seccion('Los avisos de valuacion reciben las filas REALMENTE valuadas');

/* Ver el punto 3 del encabezado: con la fila combinada, el aviso diria que
   ningun mes se pudo valuar. */
chequear('el aviso del FOB usa las filas del FOB',
    true, strpos($src, "avisosValuacion(\$grilla['filas_fob']") !== false);

chequear('y el de nacionalizacion las suyas',
    true, strpos($src, "avisosValuacion(\$grilla['filas_nac']") !== false);

chequear('y cuentan MESES, no contenedores',
    2, substr_count($src, "'mes(es) proyectado(s)'"));

seccion('El proveedor no se cae si falta el script');

/* Parametros::num() lanza cuando falta una clave, y eso dejaria el modulo en
   cero por un script sin correr. */
chequear('no usa Parametros::num', 0, substr_count($src, 'Parametros::num'));
chequear('ni Parametros::ent', 0, substr_count($src, 'Parametros::ent'));

chequear('declara un valor inicial para cada parametro',
    ['compras_proy_meses', 'compras_proy_anios_cuota', 'compras_proy_base_cuota',
     'compras_proy_dia_llegada', 'compras_proy_dias_pago', 'compras_proy_dias_nac',
     'compras_proy_nac_pct'],
    array_keys(ComprasProyectadasProvider::DEFAULTS));

/* LOS VALORES INICIALES DEL CODIGO Y LOS DEL SCRIPT SON LOS MISMOS. Si se
   separan, una instalacion con el script corrido y otra sin correr proyectan
   numeros distintos y nada lo dice. */
$sql = file_get_contents($script);

foreach (ComprasProyectadasProvider::DEFAULTS as $clave => $valor) {
    chequear('el script siembra ' . $clave . ' con el mismo valor que el codigo',
        true, strpos($sql, "('" . $clave . "', '" . $valor . "'") !== false);
}

seccion('Solo Argentina');

chequear('el pais es una constante y no un parametro suelto',
    'argentina', ComprasProyectadasProvider::PAIS);

chequear('y se le pasa a la lectura de versiones',
    true, strpos($src, 'versionesOficiales(self::PAIS)') !== false);

/* ========================================================================
   EL SCRIPT
   ======================================================================== */

seccion('El script crea las filas pegadas a su parte real');

chequear('crea la fila proyectada de Proveedores Exterior',
    true, strpos($sql, "'PROV_EXTERIOR_PROY'") !== false);

chequear('y la de Nacionalizaciones',
    true, strpos($sql, "'NACIONALIZACIONES_PROY'") !== false);

chequear('cada una apunta a COMPRAS_PROY',
    2, substr_count($sql, "'COMPRAS_PROY', 'PAGOS_PROYECTADOS'")
     + substr_count($sql, "'COMPRAS_PROY', 'NACIONALIZACION_PROYECTADA'"));

/* EL ORDEN SE DERIVA DE LA FILA REAL, no se escribe en duro: asi el script
   sigue valiendo si alguien reordena la seccion antes de correrlo. */
chequear('el orden se deriva del de la fila real',
    true, strpos($sql, '@ordenExt + 5') !== false && strpos($sql, '@ordenNac + 5') !== false);

chequear('verifica que el hueco este libre antes de insertar',
    true, strpos($sql, '@chocaExt') !== false && strpos($sql, '@chocaNac') !== false);

seccion('El script no reordena ni pisa filas existentes');

/* SIN COMENTARIOS: ver la nota de sqlSinComentariosCPP(). */
$sqlLimpio = sqlSinComentariosCPP($script);

/* Un UPDATE sobre ORDEN seria reordenar el tablero desde un script, que es lo
   que el de grupos tampoco hace. */
chequear('no hay ningun UPDATE sobre ORDEN',
    0, preg_match_all('/UPDATE[^;]*\bSET\b[^;]*\bORDEN\s*=/is', $sqlLimpio));

chequear('no borra nada',
    0, preg_match_all('/\b(DELETE|DROP|TRUNCATE)\b/i', $sqlLimpio));

/* Las dos filas REALES solo reciben sus columnas de grupo. Si el script les
   tocara ORIGEN_PROVIDER o ACTIVO, cambiaria lo que esas filas traen hoy. */
foreach (['ORIGEN_PROVIDER', 'ORIGEN_SERIE'] as $col) {
    chequear('no toca ' . $col . ' de las filas existentes',
        0, preg_match_all('/UPDATE[^;]*\bSET\b[^;]*' . $col . '\s*=/is', $sqlLimpio));
}

seccion('El script degrada sin romper');

chequear('avisa si falta el script de grupos',
    true, strpos($sql, 'falta sql/cashflow_estructura_grupos.sql') !== false);

chequear('y crea las filas igual, sin grupo',
    true, strpos($sql, "COL_LENGTH('dbo.RO_T_CASHFLOW_CONF_FILA', 'GRUPO') IS NULL") !== false);

chequear('controla que las dos partes queden seguidas',
    true, strpos($sql, 'entre las ') !== false && strpos($sql, 'NO se van a agrupar') !== false);

chequear('crea la tabla de ajustes solo si no esta',
    true, strpos($sql, "OBJECT_ID('dbo.RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE', 'U') IS NULL") !== false);

/* SIN BAJAS FISICAS: el indice unico va FILTRADO por VIGENTE, porque un UNIQUE
   comun prohibiria las filas historicas del mismo mes, que es lo que la tabla
   existe para guardar. */
chequear('el indice unico de los ajustes esta filtrado por VIGENTE',
    true, preg_match('/CREATE UNIQUE NONCLUSTERED INDEX[^;]*WHERE VIGENTE = 1/is', $sql) === 1);

chequear('los parametros se insertan solo si faltan',
    true, strpos($sql, 'WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS') !== false);

/* TIPO_DATO es NOT NULL en la tabla: sin el, el INSERT falla entero y ningun
   parametro se crea. Paso de verdad en la primera corrida. */
chequear('los parametros declaran TIPO_DATO', true, strpos($sql, 'TIPO_DATO') !== false);

/* PRINT toma una expresion escalar: una subconsulta ahi es un error de
   SINTAXIS, asi que el lote entero no llega a correr. Tambien paso de verdad:
   la primera corrida no creo ni un parametro por esto. */
chequear('ningun PRINT arma su texto con una subconsulta',
    0, preg_match_all('/PRINT[^;]*\(\s*SELECT\b/is', $sqlLimpio));

/* ========================================================================
   LA PANTALLA
   ======================================================================== */

$tab = __DIR__ . '/../cashflow/Tabs/compras_proyectadas.php';
$js = __DIR__ . '/../cashflow/Js/Compras-Proyectadas.js';
$ctrl = __DIR__ . '/../cashflow/Controller/ComprasProyectadasController.php';
$paramTab = __DIR__ . '/../cashflow/Tabs/parametros_compras_proy.php';
$paramJs = __DIR__ . '/../cashflow/Js/Parametros-Compras_proy.js';

seccion('Los archivos de la pantalla existen y estan enganchados');

foreach (['la pestana' => $tab, 'su JS' => $js, 'su controller' => $ctrl,
          'el panel de parametros' => $paramTab, 'su JS' => $paramJs,
          'su CSS' => __DIR__ . '/../cashflow/Css/Compras-Proyectadas.css'] as $que => $ruta) {
    chequear($que . ' existe', true, file_exists($ruta));
}

$menu = file_get_contents(__DIR__ . '/../cashflow/Class/Menu.php');

chequear('el menu declara la pestana',
    true, strpos($menu, "'tab' => 'compras_proyectadas'") !== false);

/* EL PLACEHOLDER SE DETECTA, NO SE DECLARA: si la pestana todavia incluyera
   Components/tab_placeholder.php, Menu la baja a 'pendiente' sin importar lo
   declarado. Declararla con datos Y dejar el placeholder seria prometer datos
   que no existen. */
chequear('y la pestana no incluye el placeholder',
    false, strpos(file_get_contents($tab), 'tab_placeholder') !== false);

$parametros = file_get_contents(__DIR__ . '/../cashflow/Class/Parametros.php');

chequear('Parametros declara el modulo',
    true, strpos($parametros, "'COMPRAS_PROY' => [") !== false);

/* El id del tab-pane lo arma parametros.php como 'paneParam' . ucfirst(
   strtolower(codigo)). Si no coincide, la sub-pestana se dibuja y no abre
   nada: un boton que no hace nada, sin ningun error. */
chequear('el tab-pane de Parametros tiene el id que arma la lista',
    true, strpos(file_get_contents(__DIR__ . '/../cashflow/Tabs/parametros.php'),
                 'id="paneParam' . ucfirst(strtolower('COMPRAS_PROY')) . '"') !== false);

seccion('La pantalla no calcula: le pide la grilla al proveedor');

$srcCtrl = codigoSinComentariosCPP($ctrl);

chequear('el controller usa el mismo proveedor que el tablero',
    true, strpos($srcCtrl, "new ComprasProyectadasProvider('COMPRAS_PROY')") !== false);

/* SI LA PESTANA REIMPLEMENTARA LA CUENTA, podria mostrar una estimacion
   distinta de la que el tablero suma, y nadie podria decir cual vale. Es la
   misma razon por la que Comercio Exterior mudo la valuacion al getter. */
chequear('y no vuelve a llamar a estimar() por su cuenta',
    0, substr_count($srcCtrl, 'ComprasProyectadas::estimar'));

chequear('ni arma su propia ventana',
    0, substr_count($srcCtrl, 'ComprasProyectadas::ventana'));

chequear('ni su propia cuota',
    0, substr_count($srcCtrl, 'ComprasProyectadas::cuota'));

/* EL ORDEN IMPORTA: series() es lo que acumula los avisos del proveedor. Si la
   grilla se pidiera primero, esos avisos todavia no existirian y la pestana
   diria menos que el tablero sobre los mismos numeros. */
chequear('pide las series ANTES que la grilla',
    true, strpos($srcCtrl, '$provider->series($h)') < strpos($srcCtrl, '$provider->grilla($h)'));

chequear('y sube los avisos del proveedor a la pantalla',
    true, strpos($srcCtrl, '$provider->warnings()') !== false);

seccion('El controller es de solo lectura');

foreach (['INSERT', 'UPDATE ', 'DELETE', 'MERGE', 'DROP'] as $verbo) {
    chequear('el controller no tiene ' . trim($verbo),
        0, preg_match_all('/\b' . trim($verbo) . '\b/i', $srcCtrl));
}

seccion('El JS reutiliza lo compartido del modulo y no usa alert()');

$srcJs = codigoSinComentariosCPP($js);
$srcParamJs = codigoSinComentariosCPP($paramJs);

foreach (['Compras-Proyectadas.js' => $srcJs,
          'Parametros-Compras_proy.js' => $srcParamJs] as $nombre => $s) {
    chequear($nombre . ' no usa alert()', 0, preg_match_all('/\balert\s*\(/', $s));
    chequear($nombre . ' no usa confirm()', 0, preg_match_all('/\bconfirm\s*\(/', $s));
    chequear($nombre . ' manda los fallos a Notificacion',
        true, strpos($s, 'Notificacion.') !== false);
}

/* LOS TEXTOS DEL SERVIDOR NUNCA SE INYECTAN COMO HTML: las dos pantallas arman
   filas con innerHTML y los avisos vienen con nombres de tabla y de archivo. */
foreach (['Compras-Proyectadas.js' => $srcJs,
          'Parametros-Compras_proy.js' => $srcParamJs] as $nombre => $s) {
    chequear($nombre . ' escapa lo que interpola',
        true, preg_match('/function esc\(/', $s) === 1);
}

$tabHtml = file_get_contents($tab);

chequear('el boton de exportar es declarativo, con data-exportar',
    true, strpos($tabHtml, 'data-exportar="tablaComprasProy"') !== false);

chequear('el detalle por rubro tambien se exporta',
    true, strpos($tabHtml, 'data-exportar="tablaDetalleVersion"') !== false);

/* Un boton con data-exportar lo engancha solo Js/tabla-export.js. Un listener
   propio seria la envoltura que el modulo ya saco de las otras pestanas. */
chequear('el JS no arma su propio exportador',
    0, preg_match_all('/function exportar\w*\s*\(/', $srcJs));

chequear('usa el control compartido de columnas fijas',
    true, strpos($srcJs, 'crearColumnasFijas(') !== false);

chequear('y el buscador filtra por un atributo, no por el texto de la fila',
    true, strpos($srcJs, 'data-busca') !== false);

seccion('La pantalla explica los siete estados');

/* Es el dato por el que se abre esta pantalla: el tablero muestra un numero y
   no puede decir POR QUE un mes vale cero. Si un estado nuevo no tuviera su
   entrada, la grilla lo dibujaria como ESTIMADO y diria algo falso. */
foreach (ComprasProyectadas::ESTADOS as $estado) {
    chequear('el JS sabe dibujar ' . $estado,
        true, preg_match('/\b' . $estado . ':\s*\{/', $srcJs) === 1);
}

seccion('El panel de parametros cubre los siete');

foreach (array_keys(ComprasProyectadasProvider::DEFAULTS) as $clave) {
    chequear('el panel edita ' . $clave, true, strpos($srcParamJs, "'" . $clave . "'") !== false);
}

/* Cada campo VALIDA ANTES DE MANDAR, con su rango: un dia 45 o un porcentaje
   negativo son numeros validos para la base y producen una proyeccion que no
   significa nada. */
chequear('cada parametro declara su rango',
    7, preg_match_all('/min:\s*-?\d+,\s*max:\s*\d+/', $srcParamJs)
     + preg_match_all("/tipo:\s*'opciones'/", $srcParamJs));

chequear('y se valida contra ese rango antes de guardar',
    true, strpos($srcParamJs, 'Notificacion.campoInvalido') !== false);

/* ========================================================================
   CONTRA LA BASE, SOLO LECTURA
   ======================================================================== */

seccion('Contra la base');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $h = new Horizonte(28, 12);
    $p = new ComprasProyectadasProvider('COMPRAS_PROY');
    $series = $p->series($h);

    chequear('el proveedor devuelve sus dos series',
        ['PAGOS_PROYECTADOS', 'NACIONALIZACION_PROYECTADA'], array_keys($series));

    foreach ($series as $cod => $s) {
        chequear($cod . ' declara dolares', 'USD', $s['moneda_origen']);
        chequear($cod . ' tiene todas las claves del eje',
            count($h->meses()), count($s['meses']));
        chequear($cod . ' no deja importes sin fecha', 0.0, floatval($s['sin_fecha']));

        $negativos = 0;

        foreach (array_merge(array_values($s['dias']), array_values($s['meses'])) as $v) {
            if ($v < 0) {
                $negativos++;
            }
        }

        chequear($cod . ' no aporta ningun importe negativo', 0, $negativos);
    }

    /* LAS DOS SERIES SALEN DEL MISMO CALCULO: la nacionalizacion es un
       porcentaje del FOB estimado, asi que su total -lo que entra al eje mas lo
       que queda afuera- tiene que guardar esa proporcion contra el del FOB. Es
       lo que detecta que alguna de las dos se haya desenganchado. */
    $g = $p->grilla($h);
    $pct = floatval($g['parametros']['compras_proy_nac_pct']);

    chequear('la nacionalizacion de la grilla es el porcentaje del FOB estimado',
        round($g['totales']['estimacion_usd'] * $pct / 100, 4),
        round($g['totales']['nacionalizacion_usd'], 4));

    /* Cada mes valuado por SU fecha: si las dos cotizaciones fueran siempre
       iguales, seria senial de que las dos series miran la misma fecha. */
    $distintas = 0;

    foreach ($g['filas'] as $f) {
        if ($f['COTIZ_FOB'] !== null && $f['COTIZ_NAC'] !== null
            && $f['COTIZ_FOB'] != $f['COTIZ_NAC']) {
            $distintas++;
        }
    }

    chequear('hay meses cuyo FOB y cuya nacionalizacion se valuan distinto',
        true, $distintas > 0);

    /* El detalle anota, pero NO suma: es metadato sobre el mismo importe. */
    $sumaDetalle = 0.0;

    foreach ($series['PAGOS_PROYECTADOS']['detalle'] as $d) {
        $sumaDetalle += $d['importe'];
    }

    $sumaSerie = array_sum($series['PAGOS_PROYECTADOS']['dias'])
        + array_sum($series['PAGOS_PROYECTADOS']['meses']);

    chequear('el detalle describe el mismo importe que la serie, no uno mas',
        round($sumaSerie, 2), round($sumaDetalle, 2));

    /* LA ESTRUCTURA DEL TABLERO TIENE QUE SEGUIR SIENDO VALIDA. Es la prueba
       que detecta una fila apuntada a un modulo que no existe, que es
       exactamente el estado en el que queda la base si el script corre antes
       de que el proveedor este registrado. */
    require_once __DIR__ . '/../cashflow/Class/CashflowEstructura.php';

    $e = new CashflowEstructura;
    $v = CashflowEstructura::validar($e->getSecciones(), $e->getFilas());

    $delModulo = [];

    foreach ($v['errores'] as $err) {
        if (strpos($err, 'COMPRAS_PROY') !== false || strpos($err, 'Proyectado') !== false) {
            $delModulo[] = $err;
        }
    }

    chequear('ninguna fila del tablero apunta a un modulo inexistente', [], $delModulo);
}
