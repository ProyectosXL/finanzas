<?php
/**
 * Comercio Exterior: el cashflow proyecta LO QUE FALTA PAGAR, no el FOB.
 *
 * QUE SE ROMPE EN SILENCIO SI ESTO NO SE PRUEBA
 *
 *   1. LA REGLA DEL SALDO VIVE EN DOS REPOS. Es la de
 *      Pagos::obtenerResumen() de administracion, replicada acá porque son dos
 *      aplicaciones y dos despliegues. Si una de las dos se mueve -la
 *      tolerancia, el orden de los estados, el tope del sobrepago- las dos
 *      pantallas muestran dos saldos distintos del mismo contenedor y ninguna
 *      falla. Estas pruebas fijan la de este lado con los mismos casos que
 *      describe el encabezado de allá.
 *
 *   2. EL PENDIENTE NUNCA ES NEGATIVO. Si un sobrepago pasara con signo, el
 *      cashflow proyectaría un INGRESO que nadie afirmó, y encima compensado
 *      en silencio contra el resto de la columna del día.
 *
 *   3. EL INVARIANTE PASO A TENER TRES PARTES:
 *
 *          PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO
 *
 *      Si se rompe, el importe que sale de la proyección se evapora o se
 *      cuenta dos veces, y las dos cosas dan un tablero que no cierra sin que
 *      nada se caiga. Se verifica sobre los ocho casos posibles, que es el
 *      producto de las tres cosas que pueden pasarle a una fila: estar
 *      vencida, estar tildada y tener pagos cargados en Comex.
 *
 *   4. UNA FILA QUE REPITE UN CONTENEDOR NO PUEDE SUMAR. El FOB y los pagos
 *      salen ahora de la OC PRINCIPAL, así que dos órdenes de compra del mismo
 *      contenedor valen lo mismo: sin el corte, ese egreso entra dos veces al
 *      tablero. Hoy no hay ninguna OC hija en la base -ver la nota de más
 *      abajo- así que esto SOLO se puede verificar acá, sin base.
 *
 *   5. LA DEGRADACION. La tabla de pagos es de la otra plataforma. Si no se
 *      puede leer, el pendiente vuelve a valer el FOB completo -o sea que el
 *      tablero proyecta de más- y eso TIENE que avisarse. Es la única
 *      degradación del módulo que cambia números en vez de apagar un botón.
 *
 * LAS REGLAS SE PRUEBAN SIN BASE, que es por lo que viven afuera de las
 * consultas. Lo que necesita SQL Server se saltea solo, mismo criterio que
 * test_comex_fecha_maestra.php.
 *
 * Y HAY PRUEBAS QUE LEEN ARCHIVOS -el cableado de la consulta, el del
 * proveedor y el del registro- porque ahí no hay lógica que llamar, hay
 * CABLEADO, y el cableado se lee. Se leen SIN SUS COMENTARIOS, por lo mismo que
 * en los otros dos archivos: estos archivos explican en prosa lo que dejaron de
 * hacer, y buscar el patrón sobre el texto entero daría positivo en la nota que
 * dice que ya no está.
 */

require_once __DIR__ . '/../cashflow/Class/Comex.php';
require_once __DIR__ . '/../cashflow/Class/Providers/ComexProvider.php';
require_once __DIR__ . '/../cashflow/Class/CashflowRegistry.php';

/**
 * El codigo de un archivo, SIN SUS COMENTARIOS.
 *
 * Duplicada de los otros dos archivos de Comex a proposito: cada uno se puede
 * correr solo -`php tests/run.php saldo_pendiente`- y el corredor los incluye
 * en orden alfabetico, asi que depender de que otro se haya cargado antes
 * ataria el resultado al nombre de los archivos.
 */
if (!function_exists('codigoSinComentariosSP')) {
    function codigoSinComentariosSP($ruta) {
        $src = file_get_contents($ruta);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
    }
}

/* ================================================================
   LA REGLA DEL SALDO

   Es la cuenta de Pagos::obtenerResumen() del repo administracion. Los cinco
   casos de abajo son los cinco que esa funcion distingue, mas el borde de la
   tolerancia, que es lo unico que no se ve mirando el codigo.
   ================================================================ */

seccion('sin pagos: el pendiente es el FOB, o sea lo que se mostraba antes');

$r = Comex::saldoPendiente(77408.00, 0);

chequear('el FOB pasa entero', 77408.00, $r['fob']);
chequear('no hay nada pagado', 0.0, $r['pagado']);
chequear('el pendiente es el FOB', 77408.00, $r['pendiente']);
chequear('no hay nada imputado', 0.0, $r['imputado']);
chequear('ni sobrepago', 0.0, $r['sobrepago']);
chequear('y el estado es PENDIENTE', 'PENDIENTE', $r['estado']);

seccion('pago parcial: el caso del contenedor 733');

/* Los numeros son los de la base al 22/09/2026, verificados contra lo que
   devuelve Pagos::obtenerResumen(733) en Comercio Exterior. Estan escritos acá
   porque son el ancla entre las dos aplicaciones: si esta prueba falla, una de
   las dos se movio. */
$r = Comex::saldoPendiente(77408.00, 10000.00);

chequear('el FOB', 77408.00, $r['fob']);
chequear('lo pagado', 10000.00, $r['pagado']);
chequear('el pendiente', 67408.00, $r['pendiente']);
chequear('lo imputado es el complemento', 10000.00, $r['imputado']);
chequear('y sigue siendo PENDIENTE', 'PENDIENTE', $r['estado']);

/* EL INVARIANTE DE LA CUENTA, que es lo que hace cerrar al de las series:
   pendiente + imputado siempre da el FOB, sin excepciones ni redondeos. */
chequear('pendiente + imputado = FOB', 77408.00, $r['pendiente'] + $r['imputado']);

seccion('cancelado: saldo cero, y sale del flujo solo');

$r = Comex::saldoPendiente(24750.00, 24750.00);

chequear('el pendiente es cero', 0.0, $r['pendiente']);
chequear('todo el FOB quedo imputado', 24750.00, $r['imputado']);
chequear('el estado', 'CANCELADO', $r['estado']);
chequear('y no hay sobrepago', 0.0, $r['sobrepago']);

seccion('la tolerancia de un centavo, que es la de Comercio Exterior');

/* SIN LA TOLERANCIA un contenedor efectivamente cancelado quedaria proyectando
   "faltan U$S 0,01" para siempre: los pagos se cargan redondeados a dos
   decimales y la suma de varios parciales casi nunca da exacta. El valor es el
   MISMO de Pagos::obtenerResumen(), copiado a proposito. */
chequear('la constante sigue siendo un centavo', 0.01, Comex::TOLERANCIA_SALDO);

$r = Comex::saldoPendiente(100.00, 99.995);
chequear('medio centavo de menos ya es CANCELADO', 'CANCELADO', $r['estado']);
chequear('y el pendiente va a cero', 0.0, $r['pendiente']);

$r = Comex::saldoPendiente(100.00, 100.005);
chequear('medio centavo de mas tambien es CANCELADO', 'CANCELADO', $r['estado']);

/* Y NO DISPARA EL AVISO DE SOBREPAGO. Sin este tope, la tolerancia declararia
   el contenedor cancelado y el aviso mandaria igual a corregir a mano media
   diferencia de medio centavo: dos partes del mismo modulo diciendo cosas
   distintas del mismo numero. */
chequear('y no cuenta como sobrepago', 0.0, $r['sobrepago']);

$r = Comex::saldoPendiente(100.00, 99.98);
chequear('dos centavos de menos ya NO es cancelado', 'PENDIENTE', $r['estado']);
chequear('y el pendiente son esos dos centavos', 0.02, $r['pendiente']);

seccion('sobrepago: el pendiente es cero, NUNCA negativo');

$r = Comex::saldoPendiente(1000.00, 1500.00);

chequear('el estado', 'SOBREPAGO', $r['estado']);
chequear('el pendiente es cero y no -500', 0.0, $r['pendiente']);
chequear('lo imputado se topea en el FOB', 1000.00, $r['imputado']);
chequear('y el exceso se informa aparte', 500.00, $r['sobrepago']);

// El invariante aguanta el borde: es lo que hace que la serie del FOB cierre
// aunque haya plata cargada de mas.
chequear('pendiente + imputado = FOB tambien acá', 1000.00, $r['pendiente'] + $r['imputado']);

seccion('sin FOB cargado');

/* EL ORDEN DE LOS ESTADOS ES EL DE COMEX, y define este borde: un contenedor
   sin FOB con pagos encima es SIN_FOB y no SOBREPAGO. Si alguien invirtiera las
   dos ramas, las dos aplicaciones nombrarian distinto el mismo contenedor. */
$r = Comex::saldoPendiente(0, 5000.00);

chequear('el estado', 'SIN_FOB', $r['estado']);
chequear('no se proyecta nada', 0.0, $r['pendiente']);
chequear('no hay nada que imputar', 0.0, $r['imputado']);

/* Y AUN ASI SE INFORMA EL EXCESO. Es plata cargada contra nada, que es el mismo
   problema que un sobrepago; mandarla a otro aviso solo por el nombre del
   estado la dejaria sin quien la cuente. */
chequear('pero el exceso se informa igual', 5000.00, $r['sobrepago']);

$r = Comex::saldoPendiente(0, 0);
chequear('sin FOB y sin pagos tambien es SIN_FOB', 'SIN_FOB', $r['estado']);
chequear('y no hay exceso', 0.0, $r['sobrepago']);

seccion('los cuatro estados tienen los nombres de Comercio Exterior');

// Si alguien los "mejora" de este lado, las dos pantallas dejan de poder
// compararse sin un diccionario en el medio.
chequear('SIN_FOB', 'SIN_FOB', Comex::ESTADO_SIN_FOB);
chequear('PENDIENTE', 'PENDIENTE', Comex::ESTADO_PENDIENTE);
chequear('CANCELADO', 'CANCELADO', Comex::ESTADO_CANCELADO);
chequear('SOBREPAGO', 'SOBREPAGO', Comex::ESTADO_SOBREPAGO);

/* ================================================================
   LO QUE SE LE PONE A LA FILA

   conSaldo() es privada -no es API, es el paso que le pone a una fila leida sus
   campos derivados- y se prueba por reflexion porque es LOGICA PURA sobre un
   array. Mismo criterio que conFechaEfectiva() en
   test_comex_fecha_pago_manual.php.
   ================================================================ */

seccion('la fila derivada');

$conSaldo = new ReflectionMethod('Comex', 'conSaldo');
$conSaldo->setAccessible(true);

$f = $conSaldo->invoke(null, [
    'VALOR_FOB_DOLAR' => '77408.00',
    'PAGADO_USD' => '10000.00',
    'PAGOS_CANT' => '1'
]);

chequear('el FOB queda normalizado a float', 77408.00, $f['VALOR_FOB_DOLAR']);
chequear('el pendiente', 67408.00, $f['PENDIENTE_USD']);
chequear('la cantidad de pagos, como entero', 1, $f['PAGOS_CANT']);
chequear('y la fila se marca como parcial', true, $f['PAGO_PARCIAL']);

/* 'PARCIAL' NO ES UN QUINTO ESTADO: es PENDIENTE con pagos encima. Un
   contenedor sin pagos no es parcial, y uno cancelado tampoco -ya no falta
   nada-. */
$f = $conSaldo->invoke(null, ['VALOR_FOB_DOLAR' => 1000, 'PAGADO_USD' => 0, 'PAGOS_CANT' => 0]);
chequear('sin pagos no es parcial', false, $f['PAGO_PARCIAL']);

$f = $conSaldo->invoke(null,
    ['VALOR_FOB_DOLAR' => 1000, 'PAGADO_USD' => 1000, 'PAGOS_CANT' => 3]);
chequear('cancelado tampoco es parcial', false, $f['PAGO_PARCIAL']);
chequear('aunque tenga tres pagos', 3, $f['PAGOS_CANT']);

/* EL BIT SE NORMALIZA A BOOLEANO. SQL Server lo devuelve como '1'/'0' y un '0'
   es VERDADERO en PHP: sin esto, la primera fila con DUPLICA_GRUPO = 0 saldria
   del tablero. Mismo problema que ya tuvieron PAGADO y FECHA_PAGO_CONF. */
$f = $conSaldo->invoke(null, ['VALOR_FOB_DOLAR' => 1000, 'DUPLICA_GRUPO' => '0']);
chequear('el "0" de SQL Server es false', false, $f['DUPLICA_GRUPO']);

$f = $conSaldo->invoke(null, ['VALOR_FOB_DOLAR' => 1000, 'DUPLICA_GRUPO' => '1']);
chequear('y el "1" es true', true, $f['DUPLICA_GRUPO']);

$f = $conSaldo->invoke(null, ['VALOR_FOB_DOLAR' => 1000]);
chequear('sin el campo, la fila no repite a nadie', false, $f['DUPLICA_GRUPO']);
chequear('y esta sola en su grupo', 1, $f['GRUPO_FILAS']);

/* ================================================================
   LA VALUACION DE LOS TRES IMPORTES
   ================================================================ */

seccion('los dolares a pesos');

chequear('el importe por la cotizacion', 115638424.0, Comex::enPesos(67408, 1715.5));

/* SIN COTIZACION ES null Y NO CERO, que es el criterio de todo el modulo: null
   es "no se pudo valuar" -y se informa en dolares-, cero seria "este
   contenedor no cuesta nada". */
chequear('sin cotizacion, null', null, Comex::enPesos(67408, null));
chequear('y con importe cero, cero', 0.0, Comex::enPesos(0, 1715.5));

/* ================================================================
   EL INVARIANTE DE LAS SERIES

   PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO, y lo que lo hace cerrar es
   que los cuatro campos se anulen JUNTOS cuando la fila no proyecta.
   ================================================================ */

seccion('una fila que repite un contenedor no aporta a ninguna serie');

/* El FOB y los pagos salen de la OC principal, asi que dos filas del mismo
   grupo valen lo mismo. Sin este corte, el contenedor entra dos veces. */
$repetida = ['IMPORTE_ARS' => 1000, 'DUPLICA_GRUPO' => true];

chequear('no es proyectable', 0.0, Comex::importeProyectable($repetida));
chequear('ni aporta al eje', 0.0, Comex::aporteAlEje($repetida));

/* Y TAMPOCO APORTA AL UNIVERSO. Si PAGOS_TODO la contara, el invariante
   quedaria sin cerrar por el FOB entero de esa fila, que es peor que el doble
   conteo original porque encima no se ve. */
chequear('ni al FOB completo', 0.0,
    Comex::importeProyectable(['IMPORTE_FOB_ARS' => 1000, 'DUPLICA_GRUPO' => true],
        'IMPORTE_FOB_ARS'));

/* LA CONDICION ES !empty() Y NO AL REVES, y eso es lo que deja viva a Crono
   Nacionalizacion: esa pestaña no trae el campo -no comparte pagos con nadie- y
   un campo ausente es falso. Con la condicion invertida se habria ido entera a
   cero. */
chequear('sin el campo, la fila sigue sumando', 1000.0,
    Comex::importeProyectable(['IMPORTE_ARS' => 1000]));
chequear('y Crono Nacionalizacion tambien', 55238.12,
    Comex::importeProyectable(['IMPORTE_EST' => 55238.12], 'IMPORTE_EST'));

seccion('el invariante de tres partes, sobre los ocho casos posibles');

/* Las tres cosas que pueden pasarle a una fila, combinadas: vencida, tildada y
   con pagos en Comex. Cada caso trae sus tres importes en pesos ya resueltos,
   con IMPORTE_FOB_ARS = IMPORTE_ARS + IMPORTE_PAGADO_ARS, que es como los
   calcula getProveedoresExterior(). */
$casos = [];

foreach ([false, true] as $vencida) {
    foreach ([false, true] as $tildada) {
        foreach ([0.0, 400.0] as $pagadoArs) {
            $casos[] = [
                'nombre' => ($vencida ? 'vencida' : 'al dia')
                    . ($tildada ? ' + tildada' : '')
                    . ($pagadoArs > 0 ? ' + con pagos en Comex' : ''),
                'fila' => [
                    'VENCIDA' => $vencida,
                    'PAGADO' => $tildada,
                    'IMPORTE_ARS' => 1000.0 - $pagadoArs,
                    'IMPORTE_PAGADO_ARS' => $pagadoArs,
                    'IMPORTE_FOB_ARS' => 1000.0
                ]
            ];
        }
    }
}

foreach ($casos as $caso) {
    $f = $caso['fila'];

    $pagos = floatval(Comex::aporteAlEje($f));
    $pagados = empty($f['PAGADO']) ? 0.0 : floatval(Comex::importeProyectable($f));
    $comex = floatval(Comex::importeProyectable($f, 'IMPORTE_PAGADO_ARS'));
    $todo = floatval(Comex::importeProyectable($f, 'IMPORTE_FOB_ARS'));

    chequear('cierra con ' . $caso['nombre'], $todo, $pagos + $pagados + $comex);
}

/* Y LOS DOS CASOS QUE IMPORTA MIRAR UNO POR UNO, porque son los que este
   cambio introduce. */
$parcial = [
    'IMPORTE_ARS' => 600.0, 'IMPORTE_PAGADO_ARS' => 400.0, 'IMPORTE_FOB_ARS' => 1000.0
];

chequear('un parcial proyecta solo lo que falta', 600.0, Comex::aporteAlEje($parcial));
chequear('y lo pagado sale por su propia serie', 400.0,
    Comex::importeProyectable($parcial, 'IMPORTE_PAGADO_ARS'));

$cancelado = [
    'IMPORTE_ARS' => 0.0, 'IMPORTE_PAGADO_ARS' => 1000.0, 'IMPORTE_FOB_ARS' => 1000.0
];

chequear('un cancelado no proyecta nada', 0.0, Comex::aporteAlEje($cancelado));
chequear('sin que nadie lo tilde', 0.0,
    empty($cancelado['PAGADO']) ? 0.0 : 1.0);
chequear('y su importe sigue en el universo', 1000.0,
    Comex::importeProyectable($cancelado, 'IMPORTE_FOB_ARS'));

/* EL TILDE SE APLICA SOBRE EL PENDIENTE, NO SOBRE EL FOB. Tildar un contenedor
   con la mitad pagada saca de la proyeccion LA MITAD QUE FALTABA: la otra ya
   habia salido por PAGOS_COMEX. */
$parcialTildado = $parcial + ['PAGADO' => true];

chequear('tildar un parcial saca solo el pendiente', 0.0, Comex::aporteAlEje($parcialTildado));
chequear('y eso es lo que va a PAGOS_PAGADOS', 600.0,
    Comex::importeProyectable($parcialTildado));

/* ================================================================
   LOS AVISOS

   Son lo unico que explica por que la fila del tablero bajo. Se prueban sin
   base porque el texto es la mitad del trabajo: un aviso que no sale, o que
   sale diciendo otra cosa, deja un numero sin explicacion.
   ================================================================ */

seccion('el aviso de lo que Comercio Exterior ya pago');

$avisos = Comex::avisosSaldoComex([
    ['PAGOS_CANT' => 1, 'ESTADO_PAGO' => 'PENDIENTE', 'IMPORTE_PAGADO_PROYECTABLE' => 17155000],
    ['PAGOS_CANT' => 2, 'ESTADO_PAGO' => 'CANCELADO', 'IMPORTE_PAGADO_PROYECTABLE' => 0],
    ['PAGOS_CANT' => 0, 'ESTADO_PAGO' => 'PENDIENTE', 'IMPORTE_PAGADO_PROYECTABLE' => 0]
]);

chequear('sale uno', 1, count($avisos));
chequear('cuenta los dos con pagos', true, strpos($avisos[0], '2 contenedor(es)') === 0);
chequear('dice el importe que salio de la proyeccion', true,
    strpos($avisos[0], '$ 17.155.000,00') !== false);
chequear('y separa el cancelado', true, strpos($avisos[0], '1 de ellos quedó CANCELADO') !== false);

/* SIN PAGOS NO HAY AVISO. Un mensaje que diga "0 contenedores tienen pagos" en
   todas las cargas es ruido que hace que los avisos dejen de leerse. */
chequear('sin pagos cargados no dice nada', 0, count(Comex::avisosSaldoComex([
    ['PAGOS_CANT' => 0, 'ESTADO_PAGO' => 'PENDIENTE']
])));
chequear('con la lista vacia tampoco', 0, count(Comex::avisosSaldoComex([])));
chequear('ni con basura', 0, count(Comex::avisosSaldoComex(null)));

/* SE MIDE SOBRE EL IMPORTE PROYECTABLE Y NO SOBRE LO PAGADO A SECAS: lo que
   hay que informar es cuanto salio DE LA PROYECCION. Un contenedor con pagos
   que ademas estaba vencido ya no sumaba, asi que sus pagos no sacaron nada. */
$avisos = Comex::avisosSaldoComex([
    ['PAGOS_CANT' => 1, 'ESTADO_PAGO' => 'PENDIENTE', 'IMPORTE_PAGADO_PROYECTABLE' => 0,
     'IMPORTE_PAGADO_ARS' => 999999]
]);

chequear('un vencido con pagos no infla el aviso', true,
    strpos($avisos[0], '$ 0,00') !== false);

seccion('el aviso de sobrepago');

$avisos = Comex::avisosSobrepago([
    ['SOBREPAGO_USD' => 500.0],
    ['SOBREPAGO_USD' => 0.0],
    ['SOBREPAGO_USD' => 1200.50]
]);

chequear('sale uno', 1, count($avisos));
chequear('cuenta los dos', true, strpos($avisos[0], '2 contenedor(es)') === 0);

/* EN DOLARES, que es la moneda en la que esta el dato y en la que se va a ir a
   buscar del otro lado. En pesos habria que valuarlo, y lo que hay que
   comparar es contra una factura. */
chequear('en dolares', true, strpos($avisos[0], 'U$S 1.700,50') !== false);
chequear('dice que el pendiente se toma como cero', true,
    strpos($avisos[0], 'CERO') !== false);

chequear('sin sobrepagos no dice nada', 0, count(Comex::avisosSobrepago([
    ['SOBREPAGO_USD' => 0.0]
])));
chequear('ni con la lista vacia', 0, count(Comex::avisosSobrepago([])));
chequear('ni con basura', 0, count(Comex::avisosSobrepago(null)));

seccion('el aviso de las OC repetidas');

$avisos = Comex::avisosGrupo([
    ['DUPLICA_GRUPO' => true],
    ['DUPLICA_GRUPO' => false],
    ['DUPLICA_GRUPO' => true]
]);

chequear('sale uno', 1, count($avisos));
chequear('cuenta las dos', true, strpos($avisos[0], '2 orden(es) de compra') === 0);

/* NO MANDA A CORREGIR NADA, a diferencia del de vencidos y el de sobrepago: no
   hay nada que corregir. Es como Comercio Exterior modela un contenedor con
   varias ordenes, y el cashflow se limita a no contarlo dos veces. */
chequear('no manda a corregir nada', false, strpos($avisos[0], 'corregi') !== false);

// Hoy no hay ninguna OC hija en la base, asi que el caso normal es que no salga.
chequear('sin repetidas no dice nada', 0, count(Comex::avisosGrupo([
    ['DUPLICA_GRUPO' => false]
])));
chequear('ni con la lista vacia', 0, count(Comex::avisosGrupo([])));
chequear('ni con basura', 0, count(Comex::avisosGrupo(null)));

/* ================================================================
   EL CABLEADO

   No hay logica que llamar: hay cableado, y el cableado se lee. Mismo criterio
   que test_tablas_controles.php.
   ================================================================ */

seccion('la consulta lee los pagos de la OC PRINCIPAL');

$comexSrc = codigoSinComentariosSP(__DIR__ . '/../cashflow/Class/Comex.php');

chequear('nombra la tabla de pagos de Comercio Exterior', true,
    strpos($comexSrc, 'RO_T_IMPORTACIONES_ENCABEZADO_PAGOS') !== false);

/* EL COALESCE ES LA REGLA DE COMEX -ver encabezado.php::resolverIdPrincipal()-
   y hoy no cambia ninguna fila, porque el padron no tiene hijas. Se verifica
   igual: el dia que aparezca una, la cuenta tiene que dar lo mismo de los dos
   lados. */
chequear('resuelve la OC principal con COALESCE', true,
    strpos($comexSrc, 'COALESCE(A.ID_PADRE, A.ID)') !== false);

chequear('y el FOB tambien sale de ahi', true,
    strpos($comexSrc, 'ISNULL(OC.VALOR_FOB_DOLAR, A.VALOR_FOB_DOLAR)') !== false);

/* LA DEDUPLICACION ES LO QUE IMPIDE EL DOBLE CONTEO. El riesgo lo introduce
   este cambio -antes cada fila proyectaba su propio FOB- asi que si alguien
   saca el ROW_NUMBER, dos OCs del mismo contenedor vuelven a valer lo mismo y
   las dos suman. */
chequear('deduplica el grupo con ROW_NUMBER', true,
    strpos($comexSrc, 'ROW_NUMBER() OVER') !== false);

seccion('lo que se valua es el pendiente, no el FOB');

chequear('la valuacion se pide sobre PENDIENTE_USD', true,
    strpos($comexSrc, "self::valuar(\$row, \$curva, 'PENDIENTE_USD')") !== false);

$ctrlSrc = codigoSinComentariosSP(__DIR__ . '/../cashflow/Controller/ComexController.php');

/* EL AVISO DE VALUACION TAMBIEN HABLA DEL PENDIENTE. Con VALOR_FOB_DOLAR
   diria de mas justamente en los contenedores que ya tienen pagos hechos, que
   son los unicos donde los dos numeros difieren. */
chequear('el aviso de valuacion mide el pendiente', true,
    strpos($ctrlSrc, "'PENDIENTE_USD'") !== false);

/* Y EL DEL TABLERO TAMBIEN, con el MISMO campo. Los dos consumidores describen
   las mismas filas y el texto es uno solo; si uno midiera el FOB y el otro el
   pendiente, la pestaña y el tablero dirían dos cosas distintas sobre los
   mismos contenedores -y sólo sobre los que ya tienen pagos, que son
   exactamente los que hay que mirar-. */
chequear('y el del tablero usa el mismo campo', true,
    strpos(codigoSinComentariosSP(__DIR__ . '/../cashflow/Class/Providers/ComexProvider.php'),
        "avisosValuacion(\$filas, \$dolar->ultimoMes(), 'PENDIENTE_USD')") !== false);

chequear('y el endpoint de lectura de pagos existe', true,
    strpos($ctrlSrc, 'getPagosContenedor') !== false);

/* SOLO LECTURA: los pagos se cargan en Comercio Exterior. Un alta de este lado
   serian dos formularios escribiendo la misma tabla con dos validaciones. */
foreach (['INSERT INTO RO_T_IMPORTACIONES_ENCABEZADO_PAGOS',
          'UPDATE RO_T_IMPORTACIONES_ENCABEZADO_PAGOS',
          'DELETE FROM RO_T_IMPORTACIONES_ENCABEZADO_PAGOS'] as $escritura) {
    chequear('el cashflow no escribe: ' . substr($escritura, 0, 6), false,
        strpos($comexSrc, $escritura) !== false);
}

seccion('la grilla y el pie se corrieron juntos');

$jsProv = codigoSinComentariosSP(
    __DIR__ . '/../cashflow/Js/Comex-Proveedores_exterior.js');
$htmlProv = codigoSinComentariosSP(__DIR__ . '/../cashflow/Tabs/proveedores_exterior.php');

/* EL SINTOMA QUE ESTO EVITA: agregar dos columnas al encabezado y no al pie
   -o al reves- corre la tabla entera dos celdas, y el numero que uno lee bajo
   "Importe ($)" es el de otra columna. No rompe nada: solo miente. Se cuentan
   las de rowspan="2", que son las descriptivas; la ultima del encabezado es la
   del eje, que es una sola celda con colspan. */
$descriptivas = substr_count($htmlProv, '<th rowspan="2"');

chequear('el encabezado tiene trece columnas descriptivas', 13, $descriptivas);

preg_match('/<td colspan="(\d+)" class="total-label">TOTALES<\/td>/', $jsProv, $mPie);

/* El pie reparte las trece en cinco tramos: el rotulo, las tres en dolares,
   las tres fechas, la del dolar, la del importe en pesos y la del tilde. */
chequear('y el pie las reparte todas', 13,
    intval($mPie[1])   // el rotulo
    + 3                // FOB, pagado y pendiente
    + 3                // ETD, ETA y la fecha de pago
    + 1                // el dolar aplicado
    + 1                // el importe en pesos
    + 1);              // el tilde

/* LA CLAVE DE COLUMNAS FIJAS SE GUARDA POR INDICE, asi que mover columnas sin
   cambiarla le deja a la gente fijada OTRA columna sin nada que lo explique.
   Mismo `_v2` que ya lleva Crono Nacionalizacion. */
chequear('la clave de columnas fijas cambio con el layout', true,
    strpos($jsProv, "clave: 'proveedores_exterior_v2'") !== false);

seccion('las cuatro series y el registro');

$provSrc = codigoSinComentariosSP(
    __DIR__ . '/../cashflow/Class/Providers/ComexProvider.php');

chequear('el proveedor sirve PAGOS_COMEX', true, strpos($provSrc, "'PAGOS_COMEX'") !== false);
chequear('PAGOS_TODO agrupa el FOB completo', true,
    strpos($provSrc, "'IMPORTE_FOB_PROYECTABLE'") !== false);

/* EL REGISTRO TIENE QUE DECLARAR LAS CUATRO CON SU 'componentes'. Es lo que
   impide activar el universo y una de sus partes al mismo tiempo en una fila
   del tablero, que es como se cuenta dos veces la misma plata. */
$meta = CashflowRegistry::meta('COMEX_PROV_EXT');

foreach (['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX', 'PAGOS_TODO'] as $serie) {
    chequear('el registro declara ' . $serie, true, isset($meta['series'][$serie]));
}

chequear('y PAGOS_TODO tiene las tres partes',
    ['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX'], $meta['componentes']['PAGOS_TODO']);

/* ================================================================
   CONTRA LA BASE

   Lo unico que no se puede verificar sin ella: que la consulta corra y que el
   contenedor 733 de exactamente lo que dice Comercio Exterior. Se saltea solo
   si no hay conexion, igual que el resto del modulo.
   ================================================================ */

seccion('el contenedor 733, contra la base');

$comexDb = null;

try {
    $comexDb = new Comex();
    $filas = $comexDb->getProveedoresExterior();
} catch (Exception $e) {
    $filas = null;
    echo '    (sin base: ' . $e->getMessage() . ')' . PHP_EOL;
}

if ($filas === null) {
    echo '    salteado' . PHP_EOL;
} else {
    $f733 = null;

    foreach ($filas as $f) {
        if (intval($f['ID']) === 733) {
            $f733 = $f;
        }
    }

    if ($f733 === null) {
        /* Si el 733 ya tiene detalle cargado sale del listado, que es correcto
           y no una falla: lo dice en vez de fallar. */
        echo '    (el contenedor 733 ya no esta en el listado: tiene detalle cargado)'
            . PHP_EOL;
    } else {
        chequear('el FOB del 733', 77408.00, $f733['VALOR_FOB_DOLAR']);
        chequear('lo pagado', 10000.00, $f733['PAGADO_USD']);
        chequear('el pendiente', 67408.00, $f733['PENDIENTE_USD']);
        chequear('el estado', 'PENDIENTE', $f733['ESTADO_PAGO']);
        chequear('y tiene un pago cargado', 1, $f733['PAGOS_CANT']);

        /* LO QUE SE PROYECTA ES EL PENDIENTE VALUADO, y no el FOB: es el
           chequeo de punta a punta de toda la rama. */
        chequear('el importe en pesos sale del pendiente',
            Comex::enPesos(67408.00, $f733['COTIZ_USD']), $f733['IMPORTE_ARS']);
    }

    /* Y EL INVARIANTE, SOBRE EL PADRON ENTERO. Fila por fila, sin eje: si no
       cierra acá, no puede cerrar en ninguna columna. */
    $descuadre = 0;

    foreach ($filas as $f) {
        if ($f['IMPORTE_FOB_ARS'] === null) {
            continue;
        }

        if (abs($f['IMPORTE_FOB_ARS'] - $f['IMPORTE_ARS'] - $f['IMPORTE_PAGADO_ARS']) > 0.005) {
            $descuadre++;
        }
    }

    chequear('el FOB en pesos es el pendiente mas lo pagado, en las ' . count($filas)
        . ' filas', 0, $descuadre);
}
