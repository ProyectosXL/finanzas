<?php
/**
 * Comercio Exterior: la fecha estimada de pago se puede FIJAR A MANO.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. La marca de la fecha de pago cambio de fuente: sale del BIT
 *      FECHA_PAGO_CONF del maestro y no de comparar el rastro. Si alguien la
 *      vuelve a derivar de marcaVigente(), una fecha fijada desde Comercio
 *      Exterior deja de marcarse y se vuelve indistinguible de una calculada.
 *      La pantalla no falla: muestra de menos.
 *
 *   2. La de NACIONALIZACION no cambio, y no tiene BIT. Si alguien "unifica"
 *      las dos ramas, esa fecha empieza a leer una columna que no le
 *      corresponde -o peor, un BIT que siempre vale 0- y pierde su marca.
 *
 *   3. guardarFecha() prende el BIT solo para 'PAGO'. Si se prendiera tambien
 *      al mover la nacionalizacion, quedaria fijada una fecha que nadie fijo y
 *      el recalculo de Comercio Exterior dejaria de correr sobre contenedores
 *      que si lo necesitan.
 *
 *   4. La degradacion. Sin el script 10 corrido, las dos aplicaciones tienen
 *      que seguir andando como antes. Una consulta que nombre la columna sin
 *      preguntar primero rompe la pestana entera con "Invalid column name", que
 *      es un error que no se ve hasta que alguien abre la pantalla.
 *
 * LAS REGLAS SE PRUEBAN SIN BASE. Lo que necesita SQL Server se saltea solo,
 * mismo criterio que test_comex_fecha_maestra.php.
 *
 * Y HAY PRUEBAS QUE LEEN ARCHIVOS -el DDL, el JS y el cableado de las
 * consultas- porque ahi no hay logica que llamar, hay CABLEADO, y el cableado
 * se lee. Se leen SIN SUS COMENTARIOS, por lo mismo que en el otro archivo:
 * estos archivos explican en prosa lo que dejaron de hacer, y buscar el patron
 * sobre el texto entero daria positivo en la nota que dice que ya no esta.
 */

require_once __DIR__ . '/../Class/Comex.php';

/**
 * La ruta del repo de Comercio Exterior, que se despliega al lado de este.
 *
 * TRES NIVELES PARA ARRIBA, no dos: desde cashflow/tests/ hay que salir de tests,
 * de cashflow y de finanzas para llegar a htdocs, que es donde estan los repos
 * hermanos. Eran dos cuando las pruebas vivian en la raiz del repo.
 *
 * SI EL DIRECTORIO NO ESTA, LAS COMPROBACIONES QUE LO NECESITAN SE SALTEAN -es un
 * repo aparte y puede no estar en la maquina-, y ese es justamente el riesgo de esta
 * ruta: una mal escrita NO falla, deja de medir en silencio. Al mover las pruebas
 * dejaron de correr 9 comprobaciones y la suite siguio en verde; lo delato el conteo
 * total, que bajo de 5488 a 5479.
 */
$rutaComex = __DIR__ . '/../../../administracion/comercioExterior';

/**
 * El codigo de un archivo, SIN SUS COMENTARIOS.
 *
 * Duplicada de test_comex_fecha_maestra.php a proposito: cada archivo de
 * pruebas se puede correr solo -`php tests/run.php fecha_pago_manual`- y el
 * corredor los incluye en orden alfabetico, asi que depender de que el otro se
 * haya cargado antes ataria el resultado al nombre de los archivos.
 */
if (!function_exists('codigoSinComentariosFPM')) {
    function codigoSinComentariosFPM($ruta) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);
        return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

/* ================================================================
   DE DONDE SALE LA MARCA DE CADA FECHA

   conFechaEfectiva() es privada -no es API del modulo, es el paso que le pone
   a una fila leida sus campos derivados- y se prueba por reflexion porque es
   LOGICA PURA sobre un array: no toca la base, no arma SQL, y su resultado es
   lo que decide que badge dibuja la grilla. Probarla a traves de la consulta
   exigiria una base con datos preparados para cada caso.
   ================================================================ */
$conFechaEfectiva = new ReflectionMethod('Comex', 'conFechaEfectiva');
$conFechaEfectiva->setAccessible(true);

/**
 * Corre conFechaEfectiva() sobre una fila armada a mano.
 *
 * @param array $row Lo que hace falta de la fila
 * @param string $campo 'FECHA_EST_PAGO' o 'FECHA_NAC'
 * @param bool $conBit Si el script 10 corrio en esa base
 * @return array
 */
function derivar(array $row, $campo, $conBit) {
    global $conFechaEfectiva;

    $destino = ($campo === 'FECHA_EST_PAGO') ? 'FECHA_PAGO_EFECTIVA' : 'FECHA_NAC_EFECTIVA';

    return $conFechaEfectiva->invoke(null, $row, $campo, $destino, '2026-09-19', $conBit);
}

seccion('la fecha de PAGO se marca por el BIT, no por el rastro');

/* EL CASO QUE JUSTIFICA TODO EL CAMBIO: la fecha la fijo alguien desde Comercio
   Exterior. No hay rastro de este lado -es otra aplicacion- y antes quedaba sin
   marcar, indistinguible de una que calculo el sistema. */
$fila = derivar([
    'FECHA_EST_PAGO'  => '2026-10-15',
    'FECHA_PAGO_CONF' => '1',
    'EDIT_VALOR'      => null,
], 'FECHA_EST_PAGO', true);

chequear('fijada desde Comex, sin rastro del cashflow: se marca', true, $fila['EDITADA']);
chequear('y el front sabe que el rastro NO la explica', false, $fila['RASTRO_VIGENTE']);

/* El otro lado del mismo caso: el cashflow la movio y el BIT quedo prendido en
   la misma transaccion, asi que se marca Y el rastro sirve para el tooltip. */
$fila = derivar([
    'FECHA_EST_PAGO'  => '2026-10-15',
    'FECHA_PAGO_CONF' => '1',
    'EDIT_VALOR'      => '2026-10-15',
], 'FECHA_EST_PAGO', true);

chequear('movida desde el cashflow: se marca', true, $fila['EDITADA']);
chequear('y el rastro describe lo que se ve', true, $fila['RASTRO_VIGENTE']);

/* UNA FECHA AUTOMATICA NO SE MARCA aunque haya un rastro viejo: el rastro dice
   que alguna vez se edito, el BIT dice que hoy no esta fijada, y lo que la
   grilla tiene que contestar es si el recalculo la va a pisar. */
$fila = derivar([
    'FECHA_EST_PAGO'  => '2026-11-20',
    'FECHA_PAGO_CONF' => '0',
    'EDIT_VALOR'      => '2026-10-15',
], 'FECHA_EST_PAGO', true);

chequear('automatica con rastro viejo: NO se marca', false, $fila['EDITADA']);

seccion('un BIT en cero no es verdadero');

/* SQL Server devuelve los BIT como '1'/'0' y un CAST(0 AS BIT) tambien. Un '0'
   sin normalizar es verdadero en PHP y dejaria TODA la grilla marcada como
   fijada a mano. Es el mismo cuidado que ya se le tiene a PAGADO. */
chequear('la cadena "0" se normaliza a false', false,
    derivar(['FECHA_EST_PAGO' => '2026-10-15', 'FECHA_PAGO_CONF' => '0'],
            'FECHA_EST_PAGO', true)['FECHA_PAGO_CONF']);

chequear('la cadena "1" se normaliza a true', true,
    derivar(['FECHA_EST_PAGO' => '2026-10-15', 'FECHA_PAGO_CONF' => '1'],
            'FECHA_EST_PAGO', true)['FECHA_PAGO_CONF']);

chequear('y si no vino la clave, tampoco', false,
    derivar(['FECHA_EST_PAGO' => '2026-10-15'], 'FECHA_EST_PAGO', true)['FECHA_PAGO_CONF']);

seccion('sin el script 10, la fecha de pago se marca como antes');

/* LA DEGRADACION. Sin la columna, confPagoSelect() pide un literal en cero, asi
   que el BIT llega apagado para todo el padron. Si EDITADA saliera igual del
   BIT, la pestana perderia una marca que hoy tiene. Vuelve a marcaVigente(),
   que es exactamente lo que hacia antes de esta rama. */
$fila = derivar([
    'FECHA_EST_PAGO'  => '2026-10-15',
    'FECHA_PAGO_CONF' => '0',
    'EDIT_VALOR'      => '2026-10-15',
], 'FECHA_EST_PAGO', false);

chequear('el rastro vigente sigue marcando', true, $fila['EDITADA']);

$fila = derivar([
    'FECHA_EST_PAGO'  => '2026-11-20',
    'FECHA_PAGO_CONF' => '0',
    'EDIT_VALOR'      => '2026-10-15',
], 'FECHA_EST_PAGO', false);

chequear('y un rastro que ya no describe la celda, no', false, $fila['EDITADA']);

seccion('la fecha de NACIONALIZACION no cambio: sigue saliendo del rastro');

/* No tiene BIT y agregarle uno esta fuera de alcance. Su marca sigue queriendo
   decir "esto lo movio el cashflow", que es lo unico que se puede afirmar. */
$fila = derivar([
    'FECHA_NAC'       => '2026-10-15',
    'FECHA_PAGO_CONF' => '1',
    'EDIT_VALOR'      => null,
], 'FECHA_NAC', true);

chequear('el BIT de la fecha de pago no la marca', false, $fila['EDITADA']);

$fila = derivar([
    'FECHA_NAC'       => '2026-10-15',
    'FECHA_PAGO_CONF' => '0',
    'EDIT_VALOR'      => '2026-10-15',
], 'FECHA_NAC', true);

chequear('y su propio rastro si', true, $fila['EDITADA']);

/* ================================================================
   EL CABLEADO DE LAS CONSULTAS Y DEL GUARDADO
   ================================================================ */
seccion('las dos consultas traen el BIT, y nunca sin preguntar');

$codigoComex = codigoSinComentariosFPM(__DIR__ . '/../Class/Comex.php');

chequear('hay un helper que decide si la columna se puede nombrar', true,
    strpos($codigoComex, 'function confPagoSelect()') !== false);

chequear('y las dos consultas lo usan', 2,
    substr_count($codigoComex, '$this->confPagoSelect()'));

/* LO QUE NO PUEDE PASAR: que la columna se nombre en una consulta sin pasar por
   el helper. Sin el script 10 eso rompe la pestana entera con "Invalid column
   name", y no se ve hasta que alguien la abre. */
chequear('la columna nunca se nombra suelta en un SELECT', false,
    strpos($codigoComex, 'A.FECHA_PAGO_CONF,') !== false
        && substr_count($codigoComex, 'A.FECHA_PAGO_CONF,') > 1);

chequear('la disponibilidad se pregunta con COL_LENGTH', true,
    strpos($codigoComex, "COL_LENGTH('dbo.\" . self::TABLA_MAESTRO . \"', 'FECHA_PAGO_CONF')") !== false);

seccion('guardarFecha prende el BIT solo para la fecha de pago');

/* Las dos condiciones van juntas en la misma linea a proposito: son las dos
   cosas que tienen que valer para tocar una columna de la otra plataforma. */
chequear('y solo si el script 10 corrio', true,
    strpos($codigoComex, "\$campo === 'PAGO' && \$this->tieneFechaPagoConf()") !== false);

/* EN LA MISMA SENTENCIA QUE LA FECHA, o sea dentro de la misma transaccion. Si
   fueran dos escrituras y fallara la segunda, el maestro quedaria con una fecha
   nueva que nada protege del recalculo. */
chequear('el BIT se escribe junto con la fecha', true,
    strpos($codigoComex, 'FECHA_PAGO_CONF = 1') !== false);

chequear('con quien la fijo, parametrizado', true,
    strpos($codigoComex, 'FECHA_PAGO_CONF_USUARIO = ?') !== false);

/* EL RASTRO SIGUE GUARDANDOSE. El BIT no lo reemplaza: esa tabla dice QUIEN la
   movio y DESDE DONDE, el BIT dice SI ESTA FIJADA. */
chequear('y el rastro del cashflow se sigue insertando', true,
    strpos($codigoComex, 'INSERT INTO " . self::TABLA_HISTORIAL') !== false);

/* ================================================================
   EL DDL

   El script lo corre una persona a mano, asi que tiene que poder correrse dos
   veces sin romper nada y no puede fallar en una base donde el cashflow nunca
   se instalo.
   ================================================================ */
seccion('el script 10 es idempotente y no asume el cashflow');

$ddl = $rutaComex . '/sql/10_fecha_pago_manual.sql';

if (!is_file($ddl)) {
    Pruebas::saltear('no esta el repo de Comercio Exterior al lado: ' . $ddl);
} else {
    $texto = file_get_contents($ddl);

    chequear('las tres columnas se crean solo si faltan', 3,
        substr_count($texto, "IF COL_LENGTH('dbo.RO_T_IMPORTACIONES_ENCABEZADO'"));

    chequear('el BIT nace en 0: lo que la aplicacion afirma hoy', true,
        strpos($texto, 'DF_RO_T_IMP_ENC_FECHA_PAGO_CONF DEFAULT 0') !== false);

    /* SIN LA TABLA DEL CASHFLOW EL BACKFILL SE SALTEA. Es el caso de uy y de
       cualquier base donde no se corrio cashflow_comex_fecha_maestra.sql. */
    chequear('el backfill pregunta si existe la tabla del cashflow', true,
        strpos($texto, "IF OBJECT_ID('dbo.RO_T_CASHFLOW_COMEX_FECHA_EDIT', 'U') IS NULL") !== false);

    chequear('y avisa en vez de fallar', true,
        strpos($texto, 'Backfill SALTEADO') !== false);

    /* LA MISMA REGLA QUE marcaVigente(): solo se marca lo que se puede
       demostrar que movio una persona Y que sigue siendo lo que se ve. */
    chequear('el backfill exige que el rastro coincida con el maestro', true,
        strpos($texto, 'CAST(A.FECHA_EST_PAGO AS DATE) = CAST(E.FECHA_NUEVA AS DATE)') !== false);

    chequear('y solo mira el rastro vigente de PAGO', true,
        strpos($texto, "E.CAMPO   = ''PAGO''") !== false
            && strpos($texto, 'E.VIGENTE = 1') !== false);

    /* SOLO SUBE, NUNCA BAJA: una segunda corrida no pisa lo que se fijo desde
       la pantalla ni revierte lo que alguien volvio a auto. */
    chequear('no vuelve a tocar lo ya marcado', true,
        strpos($texto, 'A.FECHA_PAGO_CONF = 0') !== false);

    chequear('escribe en transaccion, con rollback', true,
        strpos($texto, 'BEGIN TRANSACTION') !== false
            && strpos($texto, 'ROLLBACK TRANSACTION') !== false);

    chequear('y dice cuantos marco', true,
        strpos($texto, 'contenedores marcados como fecha de pago fijada a mano') !== false);
}

/* ================================================================
   EL FRONT

   Las dos fechas usan la MISMA funcion para dibujar la celda -por eso vive en
   Comex-fechas.js y no copiada- pero dicen cosas distintas, y eso es lo que se
   verifica: que la diferencia siga estando.
   ================================================================ */
seccion('la celda dice Manual en pago y Editada en nacionalizacion');

$js = codigoSinComentariosFPM(__DIR__ . '/../Js/Comex-fechas.js');

chequear('la de pago dice Manual', true,
    strpos($js, 'badge-fecha-manual">Manual<') !== false);

chequear('y la de nacionalizacion sigue diciendo Editada', true,
    strpos($js, 'badge-fecha-editada">Editada<') !== false);

chequear('la distincion sale del campo, no de otra cosa', true,
    strpos($js, "var esPago = (campo === 'PAGO');") !== false);

/* EL TOOLTIP DE QUIEN LA MOVIO SE CONSERVA TAL CUAL. Lo que se agrega es el
   otro caso: una fecha fijada desde Comercio Exterior, donde decir "la movio el
   cashflow" seria atribuirle a esta pantalla algo que no hizo. */
chequear('tooltipRastro sigue existiendo', true,
    strpos($js, 'function tooltipRastro(item)') !== false);

chequear('y hay otro para lo fijado del otro lado', true,
    strpos($js, 'function tooltipFijadaEnComex(item)') !== false);

chequear('cual se muestra lo decide el rastro vigente', true,
    strpos($js, 'item.RASTRO_VIGENTE ? tooltipRastro(item) : tooltipFijadaEnComex(item)') !== false);

/* ================================================================
   CONTRA LA BASE

   Solo LECTURA: escribir tocaria el maestro de otra aplicacion, y este arnes
   corre contra la base de verdad.
   ================================================================ */
seccion('contra la base: el BIT llega a las dos grillas');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $comex = new Comex();
    $hoy   = '2026-09-19';

    $ext = $comex->getProveedoresExterior($hoy);
    $nac = $comex->getCronoNacionalizacion($hoy);

    chequear('Proveedores Exterior trae filas', true, count($ext) > 0);

    /* LA CLAVE ESTA SIEMPRE, con script 10 o sin el: es lo que le permite al
       front no preguntar si el DDL corrio. */
    $sinClave = 0;
    foreach ($ext as $f) {
        if (!array_key_exists('FECHA_PAGO_CONF', $f)) $sinClave++;
        if (!array_key_exists('RASTRO_VIGENTE', $f))  $sinClave++;
    }
    chequear('todas las filas traen el BIT y el rastro', 0, $sinClave);

    $sinClaveNac = 0;
    foreach ($nac as $f) {
        if (!array_key_exists('FECHA_PAGO_CONF', $f)) $sinClaveNac++;
    }
    chequear('y Crono Nacionalizacion tambien trae el BIT', 0, $sinClaveNac);

    if (!$comex->tieneFechaPagoConf()) {
        Pruebas::saltear('falta el script 10 en esta base: el BIT llega apagado para todo');
    } else {
        /* CON LA COLUMNA, la marca de la fecha de pago tiene que salir del BIT
           y NO del rastro. Si alguna fila las tuviera pegadas al reves, la
           derivacion volvio a marcaVigente() sin que nada falle. */
        $desalineadas = 0;
        foreach ($ext as $f) {
            if ((bool) $f['EDITADA'] !== (bool) $f['FECHA_PAGO_CONF']) {
                $desalineadas++;
            }
        }
        chequear('la marca de pago sale del BIT en todas las filas', 0, $desalineadas);

        /* Y la de nacionalizacion NO: tiene que seguir saliendo del rastro. */
        $desalineadasNac = 0;
        foreach ($nac as $f) {
            if ((bool) $f['EDITADA'] !== (bool) $f['RASTRO_VIGENTE']) {
                $desalineadasNac++;
            }
        }
        chequear('y la de nacionalizacion sale del rastro', 0, $desalineadasNac);
    }
}
