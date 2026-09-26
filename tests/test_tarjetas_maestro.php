<?php
/**
 * El maestro de tarjetas y la validacion de los resumenes.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LOS ULTIMOS 4 SE GUARDAN COMO NUMERO. '0012' pasaria a ser 12, y el
 *      numero impreso en la tarjeta no coincidiria con el de la pantalla. Es una
 *      cadena de cuatro digitos, no un entero.
 *
 *   2. UN RESUMEN SIN NINGUN IMPORTE PISA LA ESTIMACION CON NADA. El mes queda en
 *      cero por haber cargado un dato, que es lo contrario de lo que el modulo
 *      promete: un dato que falta es null y un aviso.
 *
 *   3. UN IMPORTE EN CERO O NEGATIVO. El cero no se distingue de un campo vacio;
 *      el negativo convierte un EGRESO del tablero en un ingreso que nadie
 *      afirmo, en una fila cuyo TIPO es EGRESO.
 *
 *   4. EL % DE COBERTURA SIN TOPE. Un 500 tipeado donde iba 5 es un numero
 *      perfectamente valido que multiplica el egreso por seis sin que nada avise.
 *
 *   5. EL MES DEL RESUMEN Y SU FECHA DE VENCIMIENTO DIFIEREN EN SILENCIO. El MES
 *      es lo que decide que estimacion se pisa y la FECHA es donde sale el
 *      importe: cuando no coinciden hay que decirlo, porque las dos cosas son
 *      legitimas y solo quien carga sabe cual queria.
 *
 * Todo lo de aca es PURO: no toca la base. Las dos clases separan la validacion
 * de la escritura justamente para que esta parte se pueda probar sin SQL Server,
 * que es donde estan los errores que no se ven.
 *
 * Los tipos declarados en el PHP y en el CHECK de la tabla se comparan contra el
 * script, que es la unica forma de que no se separen.
 */

require_once __DIR__ . '/../cashflow/Class/Tarjetas.php';
require_once __DIR__ . '/../cashflow/Class/TarjetasResumen.php';
require_once __DIR__ . '/../cashflow/Class/Parametros.php';

/* ================================================================
   EL TIPO DE TARJETA

   Decide EN QUE SUB-PESTANA aparece, asi que un tipo desconocido no es un
   detalle: seria una tarjeta que no se ve en ninguna de las tres.
   ================================================================ */
seccion('el tipo de tarjeta');

chequear('son tres', 3, count(Tarjetas::TIPOS));
chequear('y son estos', ['SUPERVISORA', 'CORPORATIVA', 'SOCIO'],
    array_keys(Tarjetas::TIPOS));

chequear('se normaliza a mayusculas', 'SOCIO', Tarjetas::validarTipo('socio'));
chequear('y se le saca el espacio', 'CORPORATIVA', Tarjetas::validarTipo('  Corporativa '));

chequearLanza('un tipo desconocido se rechaza', function () {
    Tarjetas::validarTipo('GERENTE');
});

chequearLanza('un tipo vacio se rechaza', function () {
    Tarjetas::validarTipo('');
});

/* EL CHECK DE LA TABLA Y LA CONSTANTE TIENEN QUE DECIR LO MISMO. Un tipo que
   este en el PHP y no en la base lo rechaza la base al guardar; uno que este en
   la base y no en el PHP no lo puede cargar nadie. Se compara contra el script,
   que es la unica forma de que no se separen. */
$sqlTarjetas = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas.sql');

foreach (array_keys(Tarjetas::TIPOS) as $tipo) {
    chequear("el CHECK de la tabla incluye $tipo", true,
        strpos($sqlTarjetas, "'" . $tipo . "'") !== false);
}

/* ================================================================
   LOS ULTIMOS 4 DIGITOS

   Son lo unico que distingue dos tarjetas del mismo usuario en el mismo banco.
   ================================================================ */
seccion('los ultimos 4 digitos');

chequear('cuatro digitos valen', '1234', Tarjetas::validarUltimos4('1234'));

/* SE GUARDAN COMO TEXTO Y CON EL CERO DE ADELANTE. Con un entero, '0012' se
   convertiria en 12 y el numero de la pantalla no coincidiria con el de la
   tarjeta. */
chequear('el cero de adelante se conserva', '0012', Tarjetas::validarUltimos4('0012'));
chequear('y sigue siendo texto', true, is_string(Tarjetas::validarUltimos4('0012')));

chequear('vacio es null: son optativos', null, Tarjetas::validarUltimos4(''));
chequear('y los espacios solos tambien', null, Tarjetas::validarUltimos4('   '));

foreach (['123', '12345', '12a4', '', ' 1 2'] as $malo) {
    if ($malo === '') { continue; }

    chequearLanza("'$malo' se rechaza: tienen que ser exactamente cuatro digitos",
        function () use ($malo) { Tarjetas::validarUltimos4($malo); });
}

/* ================================================================
   EL % DE COBERTURA

   Va en PUNTOS -5 es 5 %-, igual que la inflacion mensual y al reves que la
   alicuota de IVA, que se guarda como tasa.
   ================================================================ */
seccion('el % de cobertura');

chequear('cinco es cinco', 5.0, Tarjetas::validarPct(5));
chequear('acepta decimales', 7.5, Tarjetas::validarPct('7.5'));

/* CERO ES VALIDO Y ES EL CASO NORMAL: la mayoria de las tarjetas no lleva
   cobertura. A diferencia de las horas de un fletero, donde un cero era
   indistinguible de un olvido, aca significa lo que dice. */
chequear('cero vale y significa sin cobertura', 0.0, Tarjetas::validarPct(0));
chequear('vacio tambien es cero', 0.0, Tarjetas::validarPct(''));
chequear('y null tambien', 0.0, Tarjetas::validarPct(null));

chequear('el tope es 100', 100.0, Tarjetas::validarPct(100));

chequearLanza('101 se rechaza', function () { Tarjetas::validarPct(101); });
chequearLanza('un negativo se rechaza', function () { Tarjetas::validarPct(-5); });
chequearLanza('un 500 tipeado donde iba 5 se rechaza', function () {
    Tarjetas::validarPct(500);
});
chequearLanza('y algo que no es numero', function () { Tarjetas::validarPct('cinco'); });

/* ================================================================
   EL ROTULO

   Esta escrito UNA vez y en el backend porque lo usan la grilla de Parametros,
   las tres sub-pestanas, los avisos del proveedor y el tooltip del tablero.
   Cinco lugares armando el mismo rotulo divergen en la primera correccion.
   ================================================================ */
seccion('como se nombra una tarjeta');

chequear('con los cuatro digitos y el banco',
    '•••• 1234 · SANTANDER S.A. · SONIA PACIFICO',
    Tarjetas::rotulo(['ULTIMOS_4' => '1234', 'COD_BANCO' => '025',
                      'DESC_BANCO' => 'SANTANDER S.A.', 'NOMBRE_USUARIO' => 'SONIA PACIFICO']));

/* SIN LOS ULTIMOS 4 SE DICE QUE NO ESTAN. Dejar el espacio vacio haria que una
   tarjeta sin identificar se leyera igual que una con los digitos en blanco, y
   no son lo mismo: la segunda no existe. */
chequear('sin los cuatro digitos lo dice',
    '•••• (sin identificar) · SANTANDER S.A.',
    Tarjetas::rotulo(['ULTIMOS_4' => null, 'COD_BANCO' => '025',
                      'DESC_BANCO' => 'SANTANDER S.A.']));

/* Sin la descripcion del banco se usa el codigo: BANCO puede no responder, y una
   tarjeta sin nombre de banco ni codigo no se puede identificar. */
chequear('sin el nombre del banco se usa el codigo',
    '•••• 1234 · banco 025',
    Tarjetas::rotulo(['ULTIMOS_4' => '1234', 'COD_BANCO' => '025', 'DESC_BANCO' => null]));

/* ================================================================
   LOS IMPORTES DE UN RESUMEN

   Al menos uno, los dos si corresponde, y ninguno en cero ni negativo.
   ================================================================ */
seccion('los importes de un resumen: al menos uno');

chequear('solo pesos', ['ars' => 150000.0, 'usd' => null],
    TarjetasResumen::validarImportes(150000, null));

chequear('solo dolares', ['ars' => null, 'usd' => 320.5],
    TarjetasResumen::validarImportes('', 320.5));

/* LOS DOS A LA VEZ, que es el caso que explica por que una tarjeta no tiene
   moneda: la misma tarjeta tiene consumos en pesos y en dolares. */
chequear('los dos juntos', ['ars' => 150000.0, 'usd' => 320.5],
    TarjetasResumen::validarImportes(150000, 320.5));

chequear('se redondea a dos decimales', 150000.12,
    TarjetasResumen::validarImportes(150000.1234, null)['ars']);

chequearLanza('sin ningun importe se rechaza', function () {
    TarjetasResumen::validarImportes(null, null);
});

chequearLanza('los dos vacios tambien', function () {
    TarjetasResumen::validarImportes('', '');
});

seccion('ni cero ni negativo');

/* UN CERO NO SE DISTINGUE DE UN CAMPO VACIO, y un campo vacio ya significa "ese
   mes no hubo consumos en esa moneda". Cargar un cero seria decir lo mismo de dos
   formas, y una de las dos pisaria la estimacion. */
chequearLanza('un cero en pesos se rechaza', function () {
    TarjetasResumen::validarImportes(0, null);
});

chequearLanza('un cero en dolares tambien', function () {
    TarjetasResumen::validarImportes(null, 0);
});

/* UN NEGATIVO CONVERTIRIA UN EGRESO EN UN INGRESO. La fila del tablero tiene
   TIPO = EGRESO, asi que un importe negativo se resta de los egresos: seria plata
   entrando que nadie afirmo. Un saldo a favor, si algun dia hay que modelarlo,
   es una decision y no una carga. */
chequearLanza('un negativo en pesos se rechaza', function () {
    TarjetasResumen::validarImportes(-150000, null);
});

chequearLanza('un negativo en dolares tambien', function () {
    TarjetasResumen::validarImportes(null, -320);
});

chequearLanza('y algo que no es numero', function () {
    TarjetasResumen::validarImportes('cien mil', null);
});

/* El CHECK de la tabla dice lo mismo: es la tercera red, despues de la pantalla
   y de la clase. */
chequear('el CHECK de la tabla exige al menos un importe', true,
    strpos($sqlTarjetas, 'IMPORTE_ARS IS NOT NULL OR IMPORTE_USD IS NOT NULL') !== false);
chequear('y que sean mayores a cero', true,
    strpos($sqlTarjetas, 'IMPORTE_ARS IS NULL OR IMPORTE_ARS > 0') !== false);

/* ================================================================
   LA FECHA DE VENCIMIENTO DEL RESUMEN
   ================================================================ */
seccion('la fecha del resumen');

chequear('una fecha valida pasa', '2026-10-15', TarjetasResumen::validarFecha('2026-10-15'));

chequear('un DateTime tambien', '2026-10-15',
    TarjetasResumen::validarFecha(new DateTime('2026-10-15 14:30:00')));

chequearLanza('una fecha que no existe se rechaza', function () {
    TarjetasResumen::validarFecha('2026-02-31');
});

chequearLanza('un texto cualquiera se rechaza', function () {
    TarjetasResumen::validarFecha('el 15');
});

chequearLanza('y vacia', function () { TarjetasResumen::validarFecha(''); });

/* ================================================================
   EL ORIGEN

   CARGA es el resumen del periodo; HISTORICO es la carga inicial de base de
   Tarjetas Socios, que ademas nace pagada.
   ================================================================ */
seccion('el origen de un resumen');

chequear('son dos', 2, count(TarjetasResumen::ORIGENES));
chequear('CARGA y HISTORICO', ['CARGA', 'HISTORICO'],
    array_keys(TarjetasResumen::ORIGENES));

chequear('HISTORICO se reconoce', 'HISTORICO', TarjetasResumen::validarOrigen('historico'));
chequear('CARGA tambien', 'CARGA', TarjetasResumen::validarOrigen('CARGA'));

/* CAE A CARGA Y NO A HISTORICO, y eso importa: HISTORICO tiene un efecto propio
   -la carga de base los da por pagados-, asi que un valor raro que cayera ahi
   marcaria como pagado un resumen que nadie pago. */
chequear('un valor raro cae a CARGA, que es el caso normal',
    'CARGA', TarjetasResumen::validarOrigen('CUALQUIERA'));
chequear('y vacio tambien', 'CARGA', TarjetasResumen::validarOrigen(''));

foreach (array_keys(TarjetasResumen::ORIGENES) as $origen) {
    chequear("el CHECK de la tabla incluye $origen", true,
        strpos($sqlTarjetas, "'" . $origen . "'") !== false);
}

/* ================================================================
   EL MES DEL RESUMEN CONTRA SU FECHA DE VENCIMIENTO

   El MES decide QUE ESTIMACION SE PISA; la FECHA decide EN QUE COLUMNA sale el
   importe. Que difieran es legitimo -el resumen que vence el 2 de noviembre
   puede ser el del periodo de octubre- pero tiene que decirse, porque solo quien
   carga sabe cual queria.
   ================================================================ */
seccion('cuando el mes y la fecha no coinciden');

chequear('coincidiendo no hay aviso', '',
    TarjetasResumen::avisoMesDistinto('2026-10', '2026-10-15'));

$aviso = TarjetasResumen::avisoMesDistinto('2026-10', '2026-11-02');

chequear('difiriendo hay aviso', true, $aviso !== '');
chequear('que nombra el periodo que se pisa', true, strpos($aviso, 'Pisa la estimación de 2026-10') !== false);
chequear('y el mes de la fecha', true, strpos($aviso, '2026-11') !== false);

/* AVISA, NO RECHAZA. Rechazarlo obligaria a cargar el resumen en el mes de su
   fecha, que no siempre es el periodo al que corresponde. */
chequear('el aviso explica como cambiarlo', true,
    strpos($aviso, 'cargalo con ese período') !== false);

/* ================================================================
   LOS MESES SE VALIDAN
   ================================================================ */
seccion('los meses del resumen se validan');

chequear('un mes valido pasa', '2026-10', TarjetasResumen::validarMes('2026-10'));
chequear('y se le saca el espacio', '2026-10', TarjetasResumen::validarMes(' 2026-10 '));

foreach (['2026-13', '2026-00', '26-10', '2026', 'octubre', '2026-1', ''] as $malo) {
    chequearLanza("el mes '$malo' se rechaza", function () use ($malo) {
        TarjetasResumen::validarMes($malo);
    });
}

/* ================================================================
   LA SUB-PESTANA DE PARAMETROS

   Se declara en Parametros::$modulos, y su pane y su JS tienen que existir: un
   modulo declarado sin pane genera un boton que abre una pestana vacia, y el
   <li> se genera solo a partir de la lista.
   ================================================================ */
seccion('Parametros -> Tarjetas esta declarada y tiene donde dibujarse');

$modulos = [];

foreach (Parametros::getModulos() as $m) {
    $modulos[$m['codigo']] = $m;
}

chequear('el modulo TARJETAS esta declarado', true, isset($modulos['TARJETAS']));
chequear('se llama Tarjetas', 'Tarjetas', $modulos['TARJETAS']['nombre']);
chequear('declara su seccion', ['tarjetas'], $modulos['TARJETAS']['secciones']);
chequear('y tiene descripcion', true, strlen($modulos['TARJETAS']['descripcion']) > 40);

/* DECLARA SU PROPIO ENDPOINT, igual que LOGISTICA y CASHFLOW: las mismas tablas
   se escriben desde las tres sub-pestanas, y dos endpoints escribiendo lo mismo
   se desincronizan en la primera validacion que alguien agregue de un solo lado. */
chequear('declara su propio endpoint', 'Controller/TarjetasController.php',
    $modulos['TARJETAS']['endpoint']);

chequear('el controller existe', true,
    file_exists(__DIR__ . '/../cashflow/' . $modulos['TARJETAS']['endpoint']));

/* VA PEGADA A PROV. LOCALES, igual que Logistica: las facturas de Pagos
   Corporativos son facturas pendientes de Tango de proveedores locales, asi que
   las dos pantallas se miran juntas. */
$codigos = array_keys($modulos);
$posTarjetas = array_search('TARJETAS', $codigos, true);
$posProv = array_search('PROV_LOCALES', $codigos, true);

chequear('va inmediatamente despues de Prov. Locales', 1, $posTarjetas - $posProv);

/* CASHFLOW SIGUE SIENDO LA ULTIMA: no es un modulo de datos como los demas, es
   la estructura del tablero. */
chequear('y Cashflow sigue al final', 'CASHFLOW', $codigos[count($codigos) - 1]);

$panes = file_get_contents(__DIR__ . '/../cashflow/Tabs/parametros.php');

chequear('parametros.php tiene el pane paneParamTarjetas', true,
    strpos($panes, 'id="paneParamTarjetas"') !== false);
chequear('y lo incluye', true, strpos($panes, "parametros_tarjetas.php") !== false);

chequear('el archivo de la sub-pestana existe', true,
    file_exists(__DIR__ . '/../cashflow/Tabs/parametros_tarjetas.php'));
chequear('y su JS tambien', true,
    file_exists(__DIR__ . '/../cashflow/Js/Parametros-Tarjetas.js'));

/* EL PREFIJO DE CLASES ES PROPIO. Parametros.js busca .param-input, .mix-* y
   .respaldo-* en TODO el documento, asi que un modulo que use esas clases se
   pisaria el guardado con Ventas.

   SE BUSCA SOBRE EL CODIGO SIN COMENTARIOS, igual que test_proveedores.php: los
   docblocks de los dos archivos NOMBRAN esas clases para explicar por que no se
   usan, y una busqueda sobre el texto crudo daria positivo justamente por la
   linea que documenta la regla. */
$tabTarjetas = preg_replace(['/\/\*.*?\*\//s', '/<!--.*?-->/s'], '',
    file_get_contents(__DIR__ . '/../cashflow/Tabs/parametros_tarjetas.php'));

chequear('la sub-pestana no usa .param-input', false,
    strpos($tabTarjetas, 'param-input') !== false);
chequear('ni .mix-', false, strpos($tabTarjetas, 'class="mix-') !== false);
chequear('y usa su prefijo propio ptar-', true, strpos($tabTarjetas, 'ptar-') !== false);

/* Nada de alert(): todo va a Notificacion, igual que el resto del modulo. Mismo
   criterio con los comentarios que arriba. */
$jsTarjetas = preg_replace(['/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '',
    file_get_contents(__DIR__ . '/../cashflow/Js/Parametros-Tarjetas.js'));

chequear('el JS no usa alert()', false, strpos($jsTarjetas, 'alert(') !== false);
chequear('usa Notificacion', true, strpos($jsTarjetas, 'Notificacion.') !== false);
chequear('y pega contra el controller del modulo', true,
    strpos($jsTarjetas, 'Controller/TarjetasController.php') !== false);

/* ================================================================
   LOS SCRIPTS SQL ESTAN, Y SON IDEMPOTENTES

   Lo que se verifica es la forma, no el efecto: que cada tabla se cree dentro de
   una guarda y que ninguno borre nada. Un script que no se puede volver a correr
   deja la instalacion a medias el dia que falla en la mitad.
   ================================================================ */
seccion('los scripts son reejecutables y no borran nada');

$scripts = [
    'cashflow_tarjetas.sql' => ['RO_T_CASHFLOW_TARJETAS', 'RO_T_CASHFLOW_TARJETAS_RESUMEN'],
    'cashflow_tarjetas_facturas.sql' => ['RO_T_CASHFLOW_TARJETAS_FACTURA',
                                         'RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA'],
    'cashflow_tarjetas_fila.sql' => []
];

foreach ($scripts as $archivo => $tablas) {
    $sql = @file_get_contents(__DIR__ . '/../sql/' . $archivo);

    chequear("$archivo existe", true, $sql !== false);

    if ($sql === false) {
        continue;
    }

    /* NINGUN DROP NI DELETE NI TRUNCATE. Lo que se reemplaza se inhabilita: es
       la regla de todos los scripts del modulo. */
    foreach (['DROP TABLE', 'DELETE FROM', 'TRUNCATE'] as $prohibido) {
        chequear("$archivo no tiene $prohibido", false,
            stripos($sql, $prohibido) !== false);
    }

    foreach ($tablas as $tabla) {
        chequear("$archivo crea $tabla dentro de una guarda", true,
            strpos($sql, "OBJECT_ID('dbo." . $tabla . "', 'U') IS NULL") !== false);
    }
}

/* La fila del tablero entra con NOT EXISTS sobre el CODIGO, asi que la segunda
   corrida no la duplica ni la pisa. */
$sqlFila = file_get_contents(__DIR__ . '/../sql/cashflow_tarjetas_fila.sql');

chequear('la fila del tablero se inserta con NOT EXISTS', true,
    strpos($sqlFila, "NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_CONF_FILA WHERE CODIGO = 'TARJETAS_PAGOS')") !== false);
chequear('apunta a TARJETAS / TOTAL', true,
    strpos($sqlFila, "'TARJETAS', 'TOTAL'") !== false);
chequear('va en COSTOS_INDIRECTOS', true,
    strpos($sqlFila, "'COSTOS_INDIRECTOS'") !== false);
chequear('con orden 45, entre Impuestos (40) y el subtotal (50)', true,
    strpos($sqlFila, '45, 1') !== false);

/* LA VISTA DE USUARIOS NO SE CREA: existe y se mantiene fuera de este repo. Un
   CREATE VIEW aca la pisaria con una version que este repo no mantiene. */
foreach ($scripts as $archivo => $t) {
    $sql = file_get_contents(__DIR__ . '/../sql/' . $archivo);

    chequear("$archivo no crea la vista de usuarios", false,
        stripos($sql, 'CREATE VIEW') !== false
        || stripos($sql, 'CREATE OR ALTER VIEW') !== false);
}

chequear('el script solo VERIFICA que la vista este', true,
    strpos($sqlTarjetas, "OBJECT_ID('dbo.RO_V_CASHFLOW_USUARIOS_TARJETAS')") !== false);

/* Y corre el control de IDs repetidos, que es lo que permite que ID_USUARIO
   alcance como clave del usuario de una tarjeta. */
chequear('y corre el control de IDs repetidos', true,
    strpos($sqlTarjetas, 'GROUP BY ID_DIRECTOR HAVING COUNT(*) > 1') !== false);
