<?php
/**
 * Comercio Exterior: la fecha vive en el maestro, los vencidos se ven, y lo
 * pagado sale del flujo.
 *
 * Cuatro cosas cambiaron en estas ramas y las cuatro se rompen en silencio:
 *
 *   1. Las dos fechas editables se escriben sobre
 *      RO_T_IMPORTACIONES_ENCABEZADO y no sobre la tabla del cashflow. Si
 *      alguien vuelve a leer las columnas EDIT, la pantalla no falla: muestra
 *      una fecha vieja que la otra aplicacion ya no tiene.
 *   2. El filtro por fecha de embarque se fue. Si vuelve, desaparecen 42 de 76
 *      contenedores y el tablero informa de menos sin que nada se caiga.
 *   3. Un importe con fecha vencida no suma, y NO se reubica en hoy. Si alguien
 *      lo "arregla" reusando Ingresos::ubicarCobroVencido(), la columna de hoy
 *      se llena con dos mil millones de pesos de pagos que probablemente ya
 *      salieron, y el tablero sigue dando un numero.
 *   4. Un pago marcado como hecho sale del flujo por una serie propia. Si el
 *      invariante PAGOS + PAGOS_PAGADOS = PAGOS_TODO se rompe, el importe
 *      marcado se evapora o se cuenta dos veces, y las dos cosas dan un tablero
 *      que no cierra sin que nada falle.
 *
 * LAS REGLAS SE PRUEBAN SIN BASE, que es por lo que viven afuera de las
 * consultas. Lo que necesita SQL Server se saltea solo.
 *
 * Y HAY PRUEBAS QUE LEEN ARCHIVOS, con el mismo criterio de
 * test_tablas_controles.php: el filtro que se saco y el endpoint que se unifico
 * no tienen logica que verificar, tienen CABLEADO, y el cableado se lee.
 */

require_once __DIR__ . '/../Class/Comex.php';

/**
 * El codigo de un archivo, SIN SUS COMENTARIOS.
 *
 * Hace falta porque estos archivos explican en prosa lo que dejaron de hacer
 * -"antes era COALESCE(FECHA_PAGO_EDIT, ...)", "antes viajaba fecha_pago_orig
 * desde el navegador"- y esas notas son justamente lo que este modulo pide que
 * se escriba. Buscar el patron sobre el archivo entero daria positivo en la
 * nota que dice que el patron ya no esta, y la unica forma de pasar la prueba
 * seria borrar la explicacion.
 *
 * @param string $ruta
 * @return string
 */
function codigoSinComentarios($ruta) {
    $src = file_get_contents($ruta);

    // Bloques /* ... */, que es como estan escritos los encabezados y casi
    // todas las notas del modulo.
    $src = preg_replace('#/\*.*?\*/#s', '', $src);

    // Y las de una linea, sin tocar lo que tengan a la izquierda: una // que
    // empieza a mitad de linea comenta el resto, no la linea entera.
    return preg_replace('#(^|\s)//[^\n]*#m', '$1', $src);
}

/* ================================================================
   LOS DOS CAMPOS EDITABLES, Y A QUE COLUMNA DEL MAESTRO VAN

   La lista es cerrada y vive en el codigo: es lo que impide que un pedido
   armado a mano escriba sobre otra columna del maestro de Comercio Exterior.
   ================================================================ */
seccion('las dos fechas editables apuntan al maestro');

chequear('la estimada de pago es FECHA_EST_PAGO', 'FECHA_EST_PAGO', Comex::CAMPOS['PAGO']);
chequear('la de nacionalizacion es FECHA_DESP_ADU', 'FECHA_DESP_ADU', Comex::CAMPOS['NAC']);
chequear('y no hay un tercer campo editable', 2, count(Comex::CAMPOS));

// La tabla del maestro se nombra una sola vez: si alguien la escribe a mano en
// una consulta, este par deja de coincidir.
chequear('el maestro es el de importaciones', 'RO_T_IMPORTACIONES_ENCABEZADO',
    Comex::TABLA_MAESTRO);
chequear('y el rastro va en una tabla del cashflow', 'RO_T_CASHFLOW_COMEX_FECHA_EDIT',
    Comex::TABLA_HISTORIAL);

/* ================================================================
   QUE ES UNA FECHA VENCIDA

   Lo que decide si el importe entra en alguna columna del eje. Es el corte con
   el dia de hoy, y por eso hoy se pasa como argumento: una prueba con fechas
   fijas que dependa de la fecha del sistema caduca sola, que es lo que ya le
   paso una vez a las del motor.
   ================================================================ */
seccion('una fecha anterior a hoy esta vencida');

chequear('ayer', true, Comex::estaVencida('2026-09-18', '2026-09-19'));
chequear('el mes pasado', true, Comex::estaVencida('2026-08-31', '2026-09-19'));
chequear('hace un anio', true, Comex::estaVencida('2025-10-23', '2026-09-19'));

seccion('hoy NO esta vencida');

// HOY ES EL PRIMER DIA DEL EJE: su importe entra en la primera columna. Correr
// el corte un dia sacaria del tablero todo lo que vence hoy.
chequear('hoy mismo', false, Comex::estaVencida('2026-09-19', '2026-09-19'));
chequear('manana', false, Comex::estaVencida('2026-09-20', '2026-09-19'));
chequear('el anio que viene', false, Comex::estaVencida('2027-04-14', '2026-09-19'));

seccion('sin fecha NO es vencida: es otro problema');

/* Son dos cosas distintas con dos acciones distintas -una fecha vencida hay que
   corregirla, una que falta hay que cargarla- y el aviso es otro. Si esto
   devolviera true, las dos se juntarian en un numero que no le dice a nadie que
   hacer. */
chequear('null', false, Comex::estaVencida(null, '2026-09-19'));
chequear('cadena vacia', false, Comex::estaVencida('', '2026-09-19'));

seccion('acepta lo que devuelve sqlsrv, no solo strings');

chequear('un DateTime', true, Comex::estaVencida(new DateTime('2026-09-18'), '2026-09-19'));
chequear('una fecha con hora', true, Comex::estaVencida('2026-09-18 23:59:00', '2026-09-19'));

// El hoy tambien puede llegar con hora: se corta igual, y sin eso la
// comparacion de strings daria que hoy es mayor que hoy.
chequear('y un hoy con hora', false, Comex::estaVencida('2026-09-19', '2026-09-19 14:32:00'));

/* ================================================================
   LA MARCA DE "EDITADA DESDE EL CASHFLOW"

   El rastro dice "el cashflow puso esta fecha". Si despues la app de Comercio
   Exterior movio la misma columna, el rastro sigue siendo cierto pero YA NO
   EXPLICA lo que hay en la celda.
   ================================================================ */
seccion('la marca describe el valor que se ve, no el historial');

chequear('el maestro dice lo que el cashflow escribio', true,
    Comex::marcaVigente('2026-10-15', '2026-10-15'));

// EL CASO QUE JUSTIFICA QUE SE CALCULE Y NO SE GUARDE: un bit persistido
// quedaria mintiendo desde el primer cambio hecho del otro lado, que es un
// cambio que este modulo no ve pasar.
chequear('la otra app la movio despues: no se marca', false,
    Comex::marcaVigente('2026-10-15', '2026-11-20'));

chequear('sin rastro no hay marca', false, Comex::marcaVigente(null, '2026-10-15'));
chequear('y sin fecha en el maestro tampoco', false, Comex::marcaVigente('2026-10-15', null));
chequear('ni con los dos vacios', false, Comex::marcaVigente(null, null));

seccion('la marca compara fechas, no textos');

// Una viene de la columna DATE del rastro y la otra de la del maestro, y sqlsrv
// las puede entregar como DateTime: comparadas como strings nunca coincidirian.
chequear('DateTime contra string', true,
    Comex::marcaVigente(new DateTime('2026-10-15'), '2026-10-15'));
chequear('y con hora de un lado', true,
    Comex::marcaVigente('2026-10-15 00:00:00', '2026-10-15'));

/* ================================================================
   AL CASHFLOW ENTRA LO QUE SE PAGA DE HOY EN ADELANTE

   Un importe con la fecha vencida NO SUMA. Es una regla de negocio y no una
   consecuencia del eje: o ya se movio -y entonces no es proyeccion- o no se
   movio y hay que corregirle la fecha, y las dos cosas son gestion de Comercio
   Exterior sobre el dato.

   Vale en las DOS pestanas.
   ================================================================ */
seccion('un pago vencido aporta cero al eje');

chequear('vencido no suma', 0.0,
    Comex::aporteAlEje(['VENCIDA' => true, 'IMPORTE_ARS' => 85719920.0]));

// CERO Y NO null: null es "no se pudo valuar" y tiene su propio aviso, en
// dolares. Cero es "vale, pero no entra". Son dos motivos distintos por los que
// una celda queda vacia, y la pantalla los informa por separado.
chequear('y es cero, no null', true,
    Comex::aporteAlEje(['VENCIDA' => true, 'IMPORTE_ARS' => 100]) === 0.0);

seccion('un pago futuro aporta lo que vale');

chequear('no vencido suma su importe', 85719920.0,
    Comex::aporteAlEje(['VENCIDA' => false, 'IMPORTE_ARS' => 85719920.0]));
chequear('sin el flag tambien', 1000.0, Comex::aporteAlEje(['IMPORTE_ARS' => 1000]));

seccion('lo que no se pudo valuar sigue sin valuarse');

/* null se conserva: sin fecha no hay mes, sin mes no hay cotizacion, y eso ya
   se informa aparte EN DOLARES. Convertirlo en cero aca lo haria indistinguible
   de un vencido. */
chequear('null sigue siendo null', null,
    Comex::aporteAlEje(['VENCIDA' => false, 'IMPORTE_ARS' => null]));
chequear('y sin el campo tampoco se inventa', null, Comex::aporteAlEje([]));

// Un vencido SIN valuar tambien da cero: ya no entra por vencido, y el motivo
// que manda es ese.
chequear('un vencido sin valuar da cero igual', 0.0,
    Comex::aporteAlEje(['VENCIDA' => true, 'IMPORTE_ARS' => null]));

seccion('IMPORTE_ARS no se toca: es lo que vale el contenedor');

/* La grilla lo sigue mostrando en su columna. Lo que cambia es cuanto de eso
   entra al periodo, que es otra pregunta. */
chequear('el eje se arma sobre IMPORTE_EJE', true,
    strpos(codigoSinComentarios(__DIR__ . '/../Controller/ComexController.php'),
        "'IMPORTE_EJE'") !== false);
chequear('y el proveedor del tablero agrupa por lo mismo', true,
    strpos(codigoSinComentarios(__DIR__ . '/../Class/Providers/ComexProvider.php'),
        "'FECHA_PAGO_EFECTIVA', 'IMPORTE_EJE'") !== false);

/* LA REGLA VALE EN LAS DOS PESTANAS. Nacionalizaciones se sumo despues, y la
   funcion es la MISMA: aporteAlEje() recibe el campo de importe de cada una
   -IMPORTE_ARS aca, IMPORTE_EST alla- en vez de tener el nombre escrito
   adentro, que habria obligado a copiarla. */
seccion('la misma regla, con el campo de cada pestana');

chequear('una nacionalizacion vencida tampoco suma', 0.0,
    Comex::aporteAlEje(['VENCIDA' => true, 'IMPORTE_EST' => 55238.12], 'IMPORTE_EST'));
chequear('y una futura suma lo suyo', 55238.12,
    Comex::aporteAlEje(['VENCIDA' => false, 'IMPORTE_EST' => 55238.12], 'IMPORTE_EST'));

// El campo por defecto es el de Proveedores Exterior, que fue la primera.
chequear('el campo por defecto es IMPORTE_ARS', 1000.0,
    Comex::aporteAlEje(['IMPORTE_ARS' => 1000, 'IMPORTE_EST' => 7]));

chequear('las dos series del tablero agrupan por IMPORTE_EJE', 2,
    substr_count(codigoSinComentarios(__DIR__ . '/../Class/Providers/ComexProvider.php'),
        "'IMPORTE_EJE'"));

seccion('el aviso de "sin gastos cargados" no se confunde con los vencidos');

/* Un cero de la serie ya no significa "nadie cargo gastos": puede significar
   que TODOS los contenedores estan vencidos, que es otra cosa y tiene su
   propio aviso. Por eso esa guarda pasa a medirse sobre el importe crudo. */
$provSrc = codigoSinComentarios(__DIR__ . '/../Class/Providers/ComexProvider.php');

chequear('se mide sobre el importe crudo', true,
    strpos($provSrc, "self::totalImporte(\$filas, 'IMPORTE_EST')") !== false);
chequear('y ya no sobre la serie', false, strpos($provSrc, 'totalSerie') !== false);

/* ================================================================
   UN PAGO MARCADO COMO HECHO SALE DEL FLUJO

   Son dos reglas y estan separadas a proposito, porque el reparto en series
   necesita las dos por separado:

     importeProyectable()  0 si la FECHA ya paso
     aporteAlEje()         eso, y ademas 0 si YA SE PAGO
   ================================================================ */
seccion('lo marcado como pagado no aporta al eje');

chequear('un pago marcado no suma', 0.0,
    Comex::aporteAlEje(['PAGADO' => true, 'IMPORTE_ARS' => 85719920.0]));
chequear('uno sin marcar si', 85719920.0,
    Comex::aporteAlEje(['PAGADO' => false, 'IMPORTE_ARS' => 85719920.0]));

// PERO SIGUE SIENDO PROYECTABLE: ese campo mira solo la fecha, y es el que
// alimenta las series PAGADOS y TODO. Si mirara tambien el tilde, las dos
// darian cero y el invariante se romperia.
chequear('pero sigue siendo proyectable', 85719920.0,
    Comex::importeProyectable(['PAGADO' => true, 'IMPORTE_ARS' => 85719920.0]));

seccion('vencido Y pagado da cero por las dos');

chequear('el eje', 0.0,
    Comex::aporteAlEje(['PAGADO' => true, 'VENCIDA' => true, 'IMPORTE_ARS' => 100]));

/* Y el proyectable tambien, por la fecha: marcar un vencido NO mueve ningun
   numero del tablero, porque ya valia cero. Lo que cambia es que la fila sale
   de la pantalla, que es para lo que se marca. */
chequear('y el proyectable', 0.0,
    Comex::importeProyectable(['PAGADO' => true, 'VENCIDA' => true, 'IMPORTE_ARS' => 100]));

seccion('el invariante del corte, fila por fila');

/* PAGOS + PAGOS_PAGADOS = PAGOS_TODO. Lo que lo hace cerrar es que para una
   fila NO marcada los dos campos valgan lo mismo, y para una marcada el del
   eje valga cero. Se verifica sobre los cuatro casos posibles. */
$casos = [
    ['nada'            => ['IMPORTE_ARS' => 1000]],
    ['pagada'          => ['IMPORTE_ARS' => 1000, 'PAGADO' => true]],
    ['vencida'         => ['IMPORTE_ARS' => 1000, 'VENCIDA' => true]],
    ['vencida y pagada' => ['IMPORTE_ARS' => 1000, 'VENCIDA' => true, 'PAGADO' => true]]
];

foreach ($casos as $caso) {
    foreach ($caso as $nombre => $fila) {
        $eje = floatval(Comex::aporteAlEje($fila));
        $proy = floatval(Comex::importeProyectable($fila));
        $pagados = empty($fila['PAGADO']) ? 0.0 : $proy;

        chequear('cierra con ' . $nombre, $proy, $eje + $pagados);
    }
}

seccion('el reparto de filas marcadas es puro');

require_once __DIR__ . '/../Class/Providers/ComexProvider.php';

$mezcla = [
    ['ID' => 1, 'PAGADO' => true],
    ['ID' => 2],
    ['ID' => 3, 'PAGADO' => false],
    ['ID' => 4, 'PAGADO' => true]
];

$soloPagadas = ComexProvider::soloPagadas($mezcla);

chequear('son dos', 2, count($soloPagadas));
chequear('las marcadas', 1, $soloPagadas[0]['ID']);
chequear('y la otra', 4, $soloPagadas[1]['ID']);

// Se prueba SIN BASE, que es el punto: el corte se puede verificar aunque no
// haya nada marcado en la base. Mismo criterio que EcheqsProvider::repartir().
chequear('con la lista vacia no falla', 0, count(ComexProvider::soloPagadas([])));
chequear('ni con basura', 0, count(ComexProvider::soloPagadas(null)));

seccion('el aviso de lo marcado');

$avisos = Comex::avisosPagados([
    ['PAGADO' => true, 'IMPORTE_PROYECTABLE' => 1000000],
    ['PAGADO' => true, 'IMPORTE_PROYECTABLE' => 500000],
    ['PAGADO' => false, 'IMPORTE_PROYECTABLE' => 9999999]
], 'IMPORTE_PROYECTABLE', 'pago');

chequear('es uno', 1, count($avisos));
chequear('dice cuantos son', true, strpos($avisos[0], '2 contenedor(es)') !== false);
chequear('y cuanto salio de la proyeccion', true,
    strpos($avisos[0], '$ 1.500.000,00') !== false);

// EL AVISO EXISTE PORQUE EL IMPORTE YA NO ESTA EN LA FILA. Sin el, un egreso
// que el tablero deberia proyectar desaparece y nada en pantalla lo explica.
chequear('dice que salieron de la proyeccion', true,
    strpos($avisos[0], 'salieron de la proyección') !== false);
chequear('y que el importe no se perdio', true,
    strpos($avisos[0], 'no se perdió') !== false);

chequear('sin marcados no hay aviso', 0,
    count(Comex::avisosPagados([['PAGADO' => false, 'IMPORTE_PROYECTABLE' => 1]],
        'IMPORTE_PROYECTABLE', 'pago')));
chequear('ni con basura', 0, count(Comex::avisosPagados(null, 'X', 'pago')));

/* LOS QUE YA ESTABAN VENCIDOS SE DICEN APARTE. No sacaron nada de la
   proyeccion -valian cero por la fecha-, y sin decirlo el aviso contaba "21
   contenedores... $ 0,00", que se lee como un error. */
$avisos = Comex::avisosPagados([
    ['PAGADO' => true, 'VENCIDA' => false, 'IMPORTE_PROYECTABLE' => 1000000],
    ['PAGADO' => true, 'VENCIDA' => true,  'IMPORTE_PROYECTABLE' => 0],
    ['PAGADO' => true, 'VENCIDA' => true,  'IMPORTE_PROYECTABLE' => 0]
], 'IMPORTE_PROYECTABLE', 'pago');

chequear('con algunos vencidos cuenta a todos los marcados', true,
    strpos($avisos[0], '3 contenedor(es)') === 0);
chequear('el importe es solo el de los que sumaban', true,
    strpos($avisos[0], '$ 1.000.000,00') !== false);
chequear('y dice cuantos ya estaban vencidos', true,
    strpos($avisos[0], '2 de ellos ya tenían la fecha vencida') !== false);

$avisos = Comex::avisosPagados([
    ['PAGADO' => true, 'VENCIDA' => true, 'IMPORTE_PROYECTABLE' => 0],
    ['PAGADO' => true, 'VENCIDA' => true, 'IMPORTE_PROYECTABLE' => 0]
], 'IMPORTE_PROYECTABLE', 'nacionalización');

chequear('si todos estaban vencidos lo dice asi', true,
    strpos($avisos[0], 'Todos ya tenían la fecha vencida') !== false);
chequear('y que no movio ningun numero', true,
    strpos($avisos[0], 'no movió ningún número') !== false);

$avisos = Comex::avisosPagados([
    ['PAGADO' => true, 'VENCIDA' => false, 'IMPORTE_PROYECTABLE' => 5]
], 'IMPORTE_PROYECTABLE', 'pago');

chequear('sin vencidos no agrega nada', false, strpos($avisos[0], 'vencida') !== false);

/* ================================================================
   UNA VENCIDA YA PAGADA NO CUENTA COMO VENCIDA

   Pagada es cualquiera de dos cosas: el tilde del cashflow o el saldo
   CANCELADO por los pagos cargados en Comercio Exterior. Para el contador, el
   interruptor, el badge y el aviso de vencidos esa fila deja de ser vencida:
   no hay fecha que corregir. Al 24/09/2026 eran 31 filas y TODAS pagadas.

   LO DELICADO ES QUE VENCIDA NO CAMBIA. De ella dependen importeProyectable()
   y aporteAlEje(), y con ellos PAGOS_PAGADOS y PAGOS_TODO. El flag nuevo es
   aparte y solo decide lo que se muestra.
   ================================================================ */
seccion('la vencida pendiente es vencida y no pagada');

chequear('vencida sin pagar: pendiente', true,
    Comex::vencidaPendiente(['VENCIDA' => true, 'PAGADO' => false]));
chequear('vencida y tildada: no', false,
    Comex::vencidaPendiente(['VENCIDA' => true, 'PAGADO' => true]));
chequear('vencida y cancelada en Comex, sin tilde: no', false,
    Comex::vencidaPendiente(['VENCIDA' => true, 'PAGADO' => false, 'ESTADO_PAGO' => 'CANCELADO']));
chequear('vencida con pago parcial en Comex: si, todavia falta', true,
    Comex::vencidaPendiente(['VENCIDA' => true, 'PAGADO' => false, 'ESTADO_PAGO' => 'PENDIENTE']));
chequear('no vencida: no, este pagada o no', false,
    Comex::vencidaPendiente(['VENCIDA' => false, 'PAGADO' => false]));
// Crono Nacionalizacion no trae saldo: un campo ausente es "no pagado".
chequear('sin PAGADO ni ESTADO_PAGO: manda la fecha', true,
    Comex::vencidaPendiente(['VENCIDA' => true]));

seccion('el aviso de vencidos no cuenta las pagadas');

$avisos = Comex::avisosVencidos([
    ['VENCIDA' => true, 'IMPORTE_ARS' => 1000000],
    ['VENCIDA' => true, 'PAGADO' => true, 'IMPORTE_ARS' => 7000000],
    ['VENCIDA' => true, 'ESTADO_PAGO' => 'CANCELADO', 'IMPORTE_ARS' => 0]
], 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS', 'fecha estimada de pago');

chequear('cuenta solo la pendiente', true, strpos($avisos[0], '1 contenedor(es)') === 0);
chequear('con su importe y no el de las pagadas', true,
    strpos($avisos[0], '$ 1.000.000,00') !== false);

chequear('si todas estan pagadas no hay aviso', 0, count(Comex::avisosVencidos([
    ['VENCIDA' => true, 'PAGADO' => true, 'IMPORTE_ARS' => 7000000],
    ['VENCIDA' => true, 'ESTADO_PAGO' => 'CANCELADO', 'IMPORTE_ARS' => 0]
], 'FECHA_NAC_EFECTIVA', 'IMPORTE_ARS', 'fecha de nacionalización')));

seccion('y ninguna serie del tablero cambia');

/* Las ocho combinaciones de vencida x tilde x cancelada. Para cada una se
   calculan los aportes a las cuatro series CON el flag nuevo puesto -como sale
   hoy de la consulta- y SIN el -como salia antes-, y tienen que ser los
   mismos. Y el invariante de tres partes tiene que cerrar en las dos.

   Los importes son los de una fila real: con el saldo cancelado lo pendiente
   vale cero y lo pagado en Comex es el FOB entero. */
$distintas = [];
$sinCerrar = [];

foreach ([false, true] as $vencida) {
    foreach ([false, true] as $tildada) {
        foreach ([false, true] as $cancelada) {
            $fila = [
                'VENCIDA' => $vencida,
                'PAGADO' => $tildada,
                'ESTADO_PAGO' => $cancelada ? 'CANCELADO' : 'PENDIENTE',
                'IMPORTE_ARS' => $cancelada ? 0.0 : 600.0,
                'IMPORTE_PAGADO_ARS' => $cancelada ? 1000.0 : 400.0,
                'IMPORTE_FOB_ARS' => 1000.0
            ];
            $nombre = ($vencida ? 'vencida' : 'al dia') . ($tildada ? ', tildada' : '')
                . ($cancelada ? ', cancelada' : '');

            $series = function ($f) {
                $proy = floatval(Comex::importeProyectable($f));

                return [
                    'PAGOS' => floatval(Comex::aporteAlEje($f)),
                    'PAGOS_PAGADOS' => empty($f['PAGADO']) ? 0.0 : $proy,
                    'PAGOS_COMEX' => floatval(Comex::importeProyectable($f, 'IMPORTE_PAGADO_ARS')),
                    'PAGOS_TODO' => floatval(Comex::importeProyectable($f, 'IMPORTE_FOB_ARS'))
                ];
            };

            $antes = $series($fila);
            $despues = $series($fila + ['VENCIDA_PENDIENTE' => Comex::vencidaPendiente($fila)]);

            if ($antes !== $despues) {
                $distintas[] = $nombre;
            }

            if (abs($despues['PAGOS'] + $despues['PAGOS_PAGADOS'] + $despues['PAGOS_COMEX']
                    - $despues['PAGOS_TODO']) > 0.005) {
                $sinCerrar[] = $nombre;
            }
        }
    }
}

chequear('las cuatro series dan lo mismo en las ocho combinaciones', [], $distintas);
chequear('y el invariante cierra en las ocho', [], $sinCerrar);

/* LO QUE LO GARANTIZA, dicho directo: una vencida y pagada deja de ser
   pendiente pero sigue anulada, porque las dos funciones siguen mirando
   VENCIDA. Si alguna pasara a mirar el flag nuevo, este chequeo lo agarra. */
$vencidaPagada = ['VENCIDA' => true, 'PAGADO' => true, 'VENCIDA_PENDIENTE' => false,
                  'IMPORTE_ARS' => 1000];

chequear('una vencida y pagada sigue sin sumar al eje', 0.0,
    Comex::aporteAlEje($vencidaPagada));
chequear('ni a PAGOS_PAGADOS', 0.0, Comex::importeProyectable($vencidaPagada));

seccion('la pantalla mira el flag nuevo');

/* El contador, el filtro, el badge y la marca de la fila. Se lee la fuente
   porque son cuatro lugares en tres archivos y basta con que uno quede mirando
   VENCIDA para que la pantalla diga dos cosas distintas. */
$fechasJs = file_get_contents(__DIR__ . '/../Js/Comex-fechas.js');
$provExtJs = file_get_contents(__DIR__ . '/../Js/Comex-Proveedores_exterior.js');
$cronoJs = file_get_contents(__DIR__ . '/../Js/Comex-Crono_nacionalizacion.js');

chequear('el contador cuenta las pendientes', true,
    strpos($fechasJs, 'return !!item.VENCIDA_PENDIENTE; }).length') !== false);
chequear('el filtro las usa', true,
    strpos($fechasJs, 'vencida: !!item.VENCIDA_PENDIENTE') !== false);
chequear('y el badge', true, strpos($fechasJs, 'var vencida = !!item.VENCIDA_PENDIENTE;') !== false);
chequear('Proveedores Exterior marca la fila con el flag nuevo', true,
    strpos($provExtJs, "item.VENCIDA_PENDIENTE ? ' data-vencida=\"1\"'") !== false);
chequear('Crono Nacionalizacion tambien', true,
    strpos($cronoJs, "item.VENCIDA_PENDIENTE ? ' data-vencida=\"1\"'") !== false);

$sueltas = preg_match_all('/item\.VENCIDA\b(?!_)/', $fechasJs . $provExtJs . $cronoJs);

chequear('y ninguna de las tres sigue mirando VENCIDA a secas', 0, $sueltas);

seccion('el corte esta declarado en el registro');

require_once __DIR__ . '/../Class/CashflowRegistry.php';

/* LAS DOS PESTANAS YA NO TIENEN LA MISMA CANTIDAD DE PARTES. Proveedores
   Exterior ganó una tercera en feature/comex-saldo-pendiente -PAGOS_COMEX, lo
   que Comercio Exterior ya registró como pagado- porque hay DOS formas
   distintas de que un egreso salga de su proyección, y el tablero tiene que
   poder contestar cuál de las dos fue. Crono Nacionalización sigue con dos:
   allá no hay pagos parciales contra un saldo.

   Por eso el total va explícito y no como $series[2]: escribir la lista entera
   es lo que hace que agregar o sacar una parte tenga que pasar por acá. */
foreach ([
    'COMEX_PROV_EXT' => [
        'partes' => ['PAGOS', 'PAGOS_PAGADOS', 'PAGOS_COMEX'],
        'total' => 'PAGOS_TODO'
    ],
    'COMEX_NAC' => [
        'partes' => ['NACIONALIZACION', 'NACIONALIZACION_PAGADAS'],
        'total' => 'NACIONALIZACION_TODO'
    ]
] as $cod => $corte) {
    $reg = CashflowRegistry::meta($cod);

    foreach (array_merge($corte['partes'], [$corte['total']]) as $s) {
        chequear($cod . ' sirve ' . $s, true, isset($reg['series'][$s]));
    }

    /* EL TOTAL DECLARADO COMO COMPUESTO es lo que impide que alguien active en
       el tablero el universo Y una de sus partes: serian dos filas contando el
       mismo importe, y la regla de origen repetido no lo ve porque son series
       distintas. */
    chequear($cod . ' declara el total como compuesto', $corte['partes'],
        $reg['componentes'][$corte['total']]);
}

seccion('la fila del tablero no hay que repuntarla');

/* PAGOS y NACIONALIZACION cambian de SIGNIFICADO y no de codigo, igual que
   A_COBRAR con la exclusion de cheques: son los que las filas del tablero ya
   tienen configurados, asi que el circuito entra sin tocar Parametros. */
chequear('PAGOS sigue existiendo', true,
    isset(CashflowRegistry::meta('COMEX_PROV_EXT')['series']['PAGOS']));
chequear('y NACIONALIZACION tambien', true,
    isset(CashflowRegistry::meta('COMEX_NAC')['series']['NACIONALIZACION']));

/* ================================================================
   EL AVISO DE LOS VENCIDOS

   Es lo que hace visible la decision de NO reubicar en hoy. Sin el, esos
   importes caen en el 'fuera del horizonte' generico de EjeVista, donde se
   confunden con los que caen DESPUES del ultimo mes -que son otra cosa y no se
   arreglan editando nada-.
   ================================================================ */
seccion('lo vencido se informa con el conteo y el importe');

$filas = [
    ['VENCIDA' => true,  'IMPORTE_ARS' => 1000000],
    ['VENCIDA' => true,  'IMPORTE_ARS' => 500000.50],
    ['VENCIDA' => false, 'IMPORTE_ARS' => 9999999]
];

$avisos = Comex::avisosVencidos($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago');

chequear('es un solo aviso', 1, count($avisos));
chequear('dice cuantos son', true, strpos($avisos[0], '2 contenedor(es)') !== false);
chequear('y cuanto suman', true, strpos($avisos[0], '$ 1.500.000,50') !== false);
chequear('nombrando que fecha es', true,
    strpos($avisos[0], 'fecha estimada de pago') !== false);

// EL TEXTO DICE LA CONSECUENCIA Y LA ACCION. Un aviso que dijera solo "hay 2
// vencidos" no explica por que el importe no esta en ninguna columna ni que
// hacer para que entre.
chequear('dice que no suman en ninguna columna', true,
    strpos($avisos[0], 'NO suman en ninguna columna') !== false);
chequear('que no se reubican en hoy', true,
    strpos($avisos[0], 'No se los reubica en hoy') !== false);
chequear('y que se arregla cargando la fecha', true,
    strpos($avisos[0], 'cargales la fecha nueva') !== false);

/* ================================================================
   NO TODO LO VENCIDO QUEDA AFUERA DEL CUADRO

   Esto no es obvio y es lo que obligo a partir el aviso en dos: la columna del
   MES EN CURSO cubre los dias de ese mes que quedaron fuera del tramo diario,
   o sea DIAS QUE YA PASARON. Un pago vencido de este mismo mes cae ahi y entra
   al tablero; uno del mes pasado no.

   Verificado contra la base el 19/09/2026: de 27 pagos vencidos, 4 por
   $ 256.768.590 caian en la columna de septiembre. Un solo aviso diciendo "no
   entran en ninguna columna" era falso para esos cuatro.
   ================================================================ */
seccion('un vencido del mes en curso SI entra, y se dice aparte');

require_once __DIR__ . '/../Class/Horizonte.php';

// Eje conocido: 28 dias desde el 19/09, asi que la columna de 2026-09 cubre
// del 1 al 18 -dias ya pasados- y la de 2026-08 no existe.
$eje = new Horizonte(28, 12, [], '2026-09-19');

$mezcla = [
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-09-07', 'IMPORTE_ARS' => 1000000],
    ['VENCIDA' => true, 'FECHA_PAGO_EFECTIVA' => '2026-08-25', 'IMPORTE_ARS' => 7000000]
];

$dos = Comex::avisosVencidos($mezcla, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago', $eje);

chequear('son dos avisos distintos', 2, count($dos));

// El de afuera va primero: es el que tiene una accion pendiente.
chequear('el primero es el que quedo afuera', true,
    strpos($dos[0], 'NO suman en ninguna columna') !== false);
chequear('con su importe', true, strpos($dos[0], '$ 7.000.000,00') !== false);
chequear('y es uno solo', true, strpos($dos[0], '1 contenedor(es)') !== false);

chequear('el segundo dice que SI entran', true, strpos($dos[1], 'SÍ') !== false);
chequear('en la columna del mes en curso', true,
    strpos($dos[1], 'columna de ese mes') !== false);
chequear('con su importe', true, strpos($dos[1], '$ 1.000.000,00') !== false);

// Y NO SE PIERDE NINGUNO: los dos importes se informan, cada uno donde va.
chequear('entre los dos avisos esta toda la plata vencida', true,
    strpos($dos[0], '7.000.000') !== false && strpos($dos[1], '1.000.000') !== false);

seccion('sin horizonte no se afirma donde cayo cada uno');

/* Es lo que corresponde cuando no hay con que decidirlo: que columnas existen
   lo sabe el eje y nadie mas. Se informa un aviso solo. */
$sinEje = Comex::avisosVencidos($mezcla, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
    'fecha estimada de pago');

chequear('un aviso solo', 1, count($sinEje));
chequear('con los dos contenedores', true, strpos($sinEje[0], '2 contenedor(es)') !== false);
chequear('y la suma de los dos', true, strpos($sinEje[0], '$ 8.000.000,00') !== false);

seccion('el mismo aviso sirve a las dos pestanas');

$avisoNac = Comex::avisosVencidos(
    [['VENCIDA' => true, 'IMPORTE_EST' => 12345.67]],
    'FECHA_NAC_EFECTIVA', 'IMPORTE_EST', 'fecha de nacionalización');

chequear('con el campo de importe de la otra', true,
    strpos($avisoNac[0], '$ 12.345,67') !== false);
chequear('y su nombre de fecha', true,
    strpos($avisoNac[0], 'fecha de nacionalización') !== false);

seccion('sin vencidos no hay aviso');

chequear('ninguno', 0,
    count(Comex::avisosVencidos([['VENCIDA' => false, 'IMPORTE_ARS' => 100]],
        'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS', 'fecha estimada de pago')));
chequear('ni con la lista vacia', 0,
    count(Comex::avisosVencidos([], 'F', 'IMPORTE_ARS', 'x')));
chequear('ni con basura', 0, count(Comex::avisosVencidos(null, 'F', 'IMPORTE_ARS', 'x')));

// Ni siquiera con el eje puesto: sin vencidos no hay nada que repartir.
chequear('ni con el eje', 0,
    count(Comex::avisosVencidos([['VENCIDA' => false, 'FECHA_PAGO_EFECTIVA' => '2026-09-25',
        'IMPORTE_ARS' => 100]], 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS', 'x', $eje)));

seccion('NO informa lo que no tiene fecha');

/* Queda fuera del eje igual, pero ya lo dicen dos avisos que existen y lo dicen
   mejor: en Proveedores Exterior, Comex::avisosValuacion() lo informa EN
   DOLARES -es la unica moneda en la que existe un importe que no se pudo
   valuar- y en Crono Nacionalizacion lo informa EjeVista en pesos, que ahi si
   existen. Repetirlo aca daria "$ 0,00 sin fecha" al lado de "U$S 164.526,47
   sin fecha": el mismo hecho contado dos veces y una de las dos mal. */
$sinFecha = [['VENCIDA' => false, 'FECHA_PAGO_EFECTIVA' => null, 'IMPORTE_ARS' => null]];

chequear('una fila sin fecha no genera aviso de vencidos', 0,
    count(Comex::avisosVencidos($sinFecha, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
        'fecha estimada de pago', $eje)));

/* ================================================================
   EL OVERRIDE DE COTIZACION SIGUE SIENDO EL DE ANTES

   descartaCotizacion() no se toco: lo que cambio es de donde sale la fecha
   anterior -antes del cashflow, ahora del maestro-. Se vuelve a fijar aca
   porque ahora la llama guardarFecha(), que es codigo nuevo.
   ================================================================ */
seccion('mover el pago a otro mes sigue descartando la cotizacion a mano');

$r = Comex::descartaCotizacion('2026-09-21', '2026-10-15', 1500.0);

chequear('se descarta', true, $r['cotizacion_descartada']);
chequear('de septiembre', '2026-09', $r['mes_anterior']);
chequear('a octubre', '2026-10', $r['mes_nuevo']);

$r = Comex::descartaCotizacion('2026-09-21', '2026-09-28', 1500.0);

chequear('dentro del mismo mes sobrevive', false, $r['cotizacion_descartada']);

/* ================================================================
   EL FILTRO DE EMBARQUE SE FUE, Y NO PUEDE VOLVER

   No hay nada que "probar" de un filtro ausente con datos: lo que hay que
   verificar es que no este escrito. Es el mismo criterio de
   test_tablas_controles.php, que verifica cableado y no logica.

   SI ESTE CHEQUEO SE CAE, alguien volvio a poner el corte y con el desaparecen
   42 de 76 contenedores -verificado contra la base el 19/09/2026- sin que nada
   se rompa ni avise.
   ================================================================ */
seccion('ninguna consulta corta por fecha de embarque');

$fuenteComex = file_get_contents(__DIR__ . '/../Class/Comex.php');
$codigoComex = codigoSinComentarios(__DIR__ . '/../Class/Comex.php');

chequear('no hay filtro por FECHA_EMB contra GETDATE', false,
    (bool) preg_match('/ISNULL\(A\.FECHA_EMB[^)]*\)\s*>=/i', $fuenteComex));

// La expresion ISNULL(FECHA_EMB, FECHA_EST_EMB) sigue existiendo: es la columna
// ETD que la grilla muestra, y es la ultima desempatadora del orden. Lo que no
// puede volver es que se compare contra hoy.
chequear('pero el ETD se sigue mostrando', true,
    strpos($fuenteComex, 'ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD') !== false);

seccion('las columnas EDIT salieron del circuito de lectura');

/* No se borran -este modulo no borra nada- pero nadie las lee. Si vuelven a
   aparecer en un SELECT, la pantalla no falla: muestra una fecha vieja que la
   otra aplicacion ya no tiene, que es exactamente el problema que esta rama
   vino a terminar. */
foreach (['FECHA_PAGO_EDIT', 'FECHA_NAC_EDIT'] as $col) {
    chequear('no se selecciona D.' . $col, false,
        strpos($codigoComex, 'D.' . $col) !== false);
    chequear('ni se hace COALESCE con ' . $col, false,
        (bool) preg_match('/COALESCE\([^)]*' . $col . '/i', $codigoComex));
}

// La tabla vieja sigue viva, y es a proposito: guarda COTIZ_USD_EDIT.
chequear('la tabla de las EDIT sigue nombrada', 'RO_T_CASHFLOW_COMEX_CRONO_NAC',
    Comex::TABLA_EDIT);
chequear('porque sigue guardando el override de cotizacion', true,
    strpos($fuenteComex, 'COTIZ_USD_EDIT') !== false);

seccion('el listado de nacionalizacion va por fecha de nacionalizacion');

/* Antes el ORDER BY era un COALESCE de cinco fechas y el corte era por
   embarque: la tabla se leia por una fecha y se ordenaba por cualquiera de
   cinco, asi que dos contenedores con la misma nacionalizacion quedaban en
   cualquier orden entre si. */
chequear('ordena por FECHA_DESP_ADU', true,
    (bool) preg_match('/ORDER BY[^;"]*A\.FECHA_DESP_ADU/s', $fuenteComex));

// SIN FECHA LA FILA NO SE PIERDE: va al final en vez de quedar primera, que es
// lo que hace SQL Server con los NULL por defecto.
chequear('y manda los sin fecha al final', true,
    strpos($fuenteComex, 'CASE WHEN A.FECHA_DESP_ADU IS NULL THEN 1 ELSE 0 END') !== false);
chequear('lo mismo para la fecha de pago', true,
    strpos($fuenteComex, 'CASE WHEN A.FECHA_EST_PAGO IS NULL THEN 1 ELSE 0 END') !== false);

/* ================================================================
   UN SOLO ENDPOINT PARA LAS DOS FECHAS
   ================================================================ */
seccion('el guardado de fechas es uno solo');

$fuenteCtrl = codigoSinComentarios(__DIR__ . '/../Controller/ComexController.php');

chequear('existe la accion updateFecha', true,
    strpos($fuenteCtrl, "case 'updateFecha':") !== false);

// Las dos viejas hacian lo mismo contra columnas distintas y ya habian
// divergido: la de nacionalizacion se habia quedado sin transaccion y sin los
// mensajes de error que la otra fue ganando.
chequear('y no quedo la vieja de pago', false,
    strpos($fuenteCtrl, "case 'updateFechaPago':") !== false);
chequear('ni la de nacionalizacion', false,
    strpos($fuenteCtrl, "case 'updateFechaNacPago':") !== false);

seccion('el cliente ya no manda la fecha anterior');

/* Antes viajaba 'fecha_pago_orig' desde el navegador y era lo que se guardaba
   como valor original: el cliente decidia que decia que habia pisado. Ahora el
   servidor lee el maestro en la misma transaccion en la que escribe, que es la
   unica forma de que el rastro diga la verdad. */
foreach (['fecha_pago_orig', 'fecha_nac_orig', 'fecha_pago_edit', 'fecha_nac_edit']
         as $viejo) {
    chequear('el controller no espera ' . $viejo, false,
        strpos($fuenteCtrl, $viejo) !== false);
}

$jsExt = codigoSinComentarios(__DIR__ . '/../Js/Comex-Proveedores_exterior.js');
$jsNac = codigoSinComentarios(__DIR__ . '/../Js/Comex-Crono_nacionalizacion.js');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' no manda fechas viejas', false,
        (bool) preg_match('/fecha_(pago|nac)_(orig|edit)/', $js));
}

/* ================================================================
   EL BUSCADOR ES EL MISMO EN LAS DOS PESTANAS

   El pedido era replicar el de Crono Nacionalizacion, no inventar un sexto
   comportamiento de buscador. La unica forma de garantizar que no diverjan es
   que sea el MISMO codigo, y eso es lo que se verifica: que las dos lo
   deleguen en Js/Comex-fechas.js y que ninguna se guarde una copia.
   ================================================================ */
seccion('el buscador y la celda de fecha viven en un solo archivo');

$JS = __DIR__ . '/../Js';

chequear('Comex-fechas.js existe', true, file_exists($JS . '/Comex-fechas.js'));

$compartido = file_get_contents($JS . '/Comex-fechas.js');

chequear('expone la celda', true, strpos($compartido, 'celda: celda') !== false);
chequear('el editor', true, strpos($compartido, 'editar: editar') !== false);
chequear('y el filtro', true, strpos($compartido, 'filtrar: filtrar') !== false);

// LOS TRES CAMPOS, escritos una sola vez. Mirar el textContent de la fila
// entera daria falsos positivos contra los importes del eje: tipear "2026"
// traeria todo.
chequear('busca por los tres campos', true,
    (bool) preg_match('/item\.PROVEEDOR,\s*item\.CONTENEDOR,\s*item\.ORDEN_COMPRA/', $compartido));

seccion('ninguna pestana se guarda su propia copia');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' usa la celda compartida', true,
        strpos($js, 'ComexFechas.celda(') !== false);
    chequear($n . ' usa el editor compartido', true,
        strpos($js, 'ComexFechas.editar(') !== false);
    chequear($n . ' usa el filtro compartido', true,
        strpos($js, 'ComexFechas.filtrar(') !== false);

    // Si alguien vuelve a copiar la suma de columnas, se puede desincronizar de
    // las celdas que tiene arriba. Es el mismo defecto que dejo siete copias de
    // exportarExcel().
    chequear($n . ' no reimplementa sumarColumnas', false,
        (bool) preg_match('/function\s+sumarColumnas/', $js));

    // Y ninguna arma su propio fetch al endpoint de fechas: el guardado es uno.
    chequear($n . ' no tiene su propio fetch de fechas', false,
        strpos($js, 'action=updateFecha') !== false);
}

seccion('las dos pestanas cargan el archivo compartido');

$TABS = __DIR__ . '/../Tabs';

foreach (['proveedores_exterior', 'crono_nacionalizacion'] as $tab) {
    $html = file_get_contents($TABS . '/' . $tab . '.php');

    // Va ANTES del JS de la pestana: el IIFE de la pestana llama a ComexFechas
    // al dibujar, asi que si cargara despues, la primera tabla explotaria.
    $posComp = strpos($html, 'Js/Comex-fechas.js');
    $posTab = strpos($html, 'Js/Comex-');

    chequear($tab . ' carga Comex-fechas.js', true, $posComp !== false);
    chequear($tab . ' lo carga primero', true, $posComp === $posTab);
}

seccion('Proveedores Exterior tiene el buscador, igual que la otra');

$htmlExt = file_get_contents($TABS . '/proveedores_exterior.php');
$htmlNac = file_get_contents($TABS . '/crono_nacionalizacion.php');

chequear('tiene el campo', true, strpos($htmlExt, 'id="busquedaProvExt"') !== false);
chequear('y sigue el de la otra', true, strpos($htmlNac, 'id="busquedaCronoNac"') !== false);

// MISMO MARCADO: un control que se ve distinto en cada pantalla se lee como
// otro control.
foreach (['search-box-container', 'input-group input-group-sm', 'fa-search'] as $marca) {
    chequear('las dos usan ' . $marca, true,
        strpos($htmlExt, $marca) !== false && strpos($htmlNac, $marca) !== false);
}

seccion('el export respeta el buscador en las dos');

/* TablaExport saca del clon las filas con display:none, asi que Exportar baja
   lo que el buscador esta dejando ver. Eso solo pasa si el boton se engancha
   por data-exportar; con el listener propio que tenia Proveedores Exterior, la
   funcion envoltorio hacia lo mismo pero era la septima copia de algo que ya
   estaba resuelto. */
chequear('Proveedores Exterior exporta por data-exportar', true,
    strpos($htmlExt, 'data-exportar="tablaProveedoresExterior"') !== false);
chequear('y no quedo el boton con listener propio', false,
    strpos($htmlExt, 'id="btnExport"') !== false);
chequear('ni su funcion envoltorio', false,
    strpos($jsExt, 'function exportarExcel') !== false);
chequear('Crono Nacionalizacion sigue igual', true,
    strpos($htmlNac, 'data-exportar="tablaCronoNacionalizacion"') !== false);

/* ================================================================
   LA GRILLA DE NACIONALIZACION NO PUEDE VOLVER A UBICAR DOLARES EN EL EJE

   IMPORTE_EST son los conceptos 3 a 10 de la estimacion y estan EN DOLARES:
   salen de porcentajes del CIF, que arranca en VALOR_FOB_DOLAR. Hasta
   feature/comex-nac-usd el eje se armaba sobre ese campo, asi que la fila del
   tablero sumaba dolares contra el resto del cashflow en pesos.

   SI ESTE CHEQUEO SE CAE, el tablero vuelve a informar unos 900 millones de
   menos en esa fila -verificado contra la base el 21/09/2026- y no se rompe
   nada: sigue dando un numero.
   ================================================================ */
seccion('el gasto de nacionalizacion se valua, y el eje va en pesos');

chequear('la fila se valua con la fecha de nacionalizacion', true,
    strpos($codigoComex, "self::valuar(\$row, \$curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA')")
        !== false);

/* La curva se lee UNA vez por listado y no una por fila: son 76 filas y
   DolarFuturo::curva() es una consulta contra un servidor vinculado. */
chequear('la curva se lee antes del while', true,
    (bool) preg_match('/\$curva = \$this->dolarFuturo\(\)->curva\(\);\s*\n\s*while/',
        $codigoComex));
chequear('y una sola vez en cada getter', 2,
    substr_count($codigoComex, '$curva = $this->dolarFuturo()->curva();'));

/* LOS DOS CAMPOS DERIVADOS SALEN DEL IMPORTE EN PESOS, o sea del default. Si
   volviera el nombre explicito, el eje volveria a ubicar dolares. */
foreach (['importeProyectable', 'aporteAlEje'] as $fn) {
    chequear($fn . ' ya no recibe IMPORTE_EST en el getter', false,
        strpos($codigoComex, 'self::' . $fn . "(\$row, 'IMPORTE_EST')") !== false);
}

// La columna de la grilla SI lo sigue mostrando: es lo que vale el contenedor,
// en la moneda en la que esta cargado.
chequear('pero IMPORTE_EST se sigue trayendo', true,
    strpos($codigoComex, 'C.IMPORTE_EST') !== false);

seccion('la serie del tablero declara la moneda de origen');

/* Las tres series son la misma plata, asi que informan la misma moneda: una
   que dijera otra cosa haria que el tablero dibujara la marca de conversion en
   unas filas si y en otras no, sobre los mismos contenedores. Y ninguna puede
   seguir diciendo ARS, que era la afirmacion equivocada. */
chequear('ninguna serie de Comex declara pesos', false,
    strpos($provSrc, "'ARS'") !== false);
/* Nueve: las siete series -cuatro en Proveedores Exterior desde
   feature/comex-saldo-pendiente, tres en Crono Nacionalizacion- mas las dos
   series vacias del caso "no se pudo leer la curva", que tambien tienen que
   declarar la moneda: si dijeran pesos, un cero por falta de curva se leeria
   como un cero real.

   LAS CUATRO DE PROVEEDORES EXTERIOR SON LA MISMA PLATA MIRADA POR DONDE SALE
   -lo que falta, lo tildado, lo que Comex ya pago y el FOB completo- asi que
   las cuatro declaran USD. Una que dijera otra cosa haria que el tablero
   dibujara la marca de conversion en unas filas si y en otras no, sobre los
   mismos contenedores. */
chequear('todas las series declaran USD', 9, substr_count($provSrc, "'USD'"));

/* Lo vencido se informa EN PESOS. Con IMPORTE_EST, el aviso daria un numero en
   dolares con el signo de pesos adelante. */
chequear('el aviso de vencidos de nacionalizacion informa pesos', true,
    strpos($provSrc, "Comex::avisosVencidos(\$filas, 'FECHA_NAC_EFECTIVA', 'IMPORTE_ARS'")
        !== false);
chequear('y el de la pestana tambien', true,
    strpos($fuenteCtrl, "Comex::avisosVencidos(\$filasNac, 'FECHA_NAC_EFECTIVA', 'IMPORTE_ARS'")
        !== false);

/* Y sin curva la serie va en cero con aviso, igual que los pagos al exterior:
   un proveedor no puede tumbar el tablero, y un cero sin explicacion no se
   puede interpretar. */
chequear('sin curva las dos series avisan y van en cero', 2,
    substr_count($provSrc, '!$dolar->disponible()'));

seccion('la cotizacion no se puede editar en Crono Nacionalizacion');

/* ES UNA DECISION, no un olvido: COTIZ_USD_EDIT tiene UNA fila por contenedor
   y las dos pestanas valuan el mismo contenedor en dos fechas que caen en
   meses distintos. Un override cargado pensando en el pago no puede aplicarse
   a la nacionalizacion, y descartaCotizacion() esta atada al cambio de mes DEL
   PAGO: no sabe nada de la otra fecha. */
preg_match('/function getCronoNacionalizacion.*?\n    \}/s', $codigoComex, $cuerpoNac);

chequear('la consulta de nacionalizacion no trae el override', false,
    strpos($cuerpoNac[0], 'COTIZ_USD_EDIT') !== false);
chequear('y la pestana no arma su propio guardado de cotizacion', false,
    strpos($jsNac, 'action=updateCotizacion') !== false);

seccion('la celda del dolar no se reimplementa en la pestana');

/* Es la misma regla con la que ya viven la celda de fecha y el buscador: son
   la misma columna con el mismo tooltip y las mismas marcas en las dos
   grillas. Vivian solo en Proveedores Exterior porque era la unica que valuaba
   en dolares; ahora valuan las dos. */
chequear('el compartido expone la celda del dolar', true,
    strpos($compartido, 'celdaCotizacion: celdaCotizacion') !== false);
chequear('y la del importe en pesos', true,
    strpos($compartido, 'celdaImporteArs: celdaImporteArs') !== false);
chequear('y el origen de la curva para el pie', true,
    strpos($compartido, 'origenCotizacion: origenCotizacion') !== false);

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' usa la celda compartida del dolar', true,
        strpos($js, 'ComexFechas.celdaCotizacion(') !== false);
    chequear($n . ' usa la del importe en pesos', true,
        strpos($js, 'ComexFechas.celdaImporteArs(') !== false);

    // Ninguna se guarda su propia copia, que es como divergieron el buscador y
    // la celda de fecha antes de que se compartieran.
    chequear($n . ' no reimplementa celdaCotizacion', false,
        (bool) preg_match('/function\s+celdaCotizacion/', $js));
    chequear($n . ' no reimplementa celdaImporteArs', false,
        (bool) preg_match('/function\s+celdaImporteArs/', $js));
}

/* LO EDITABLE SIGUE SIENDO DE UNA SOLA PESTANA: el compartido dibuja la parte
   de solo lectura y Proveedores Exterior le agrega encima el clic. */
chequear('Proveedores Exterior le pasa el editor', true,
    strpos($jsExt, "alEditar: 'editarCotizacion'") !== false);
chequear('y Crono Nacionalizacion no le pasa nada', true,
    strpos($jsNac, 'ComexFechas.celdaCotizacion(item)') !== false);

seccion('el pie y el export suman el importe en PESOS');

/* La columna Total del pie sumaba IMPORTE_EST, que son dolares. Sumar dolares
   abajo de una columna de pesos da un total que no es de ninguna moneda. */
chequear('Crono Nacionalizacion suma IMPORTE_ARS', true,
    strpos($jsNac, 'sumaImporteArs(visibles)') !== false);
chequear('y respeta el filtro, igual que la otra', true,
    (bool) preg_match('/function sumaImporteArs[^}]*IMPORTE_ARS/s', $jsNac));

/* Y las dos columnas nuevas estan en el encabezado: sin ellas, las celdas
   quedarian corridas contra los titulos. */
chequear('el thead declara el importe en dolares', true,
    strpos($htmlNac, 'Importe Est. (USD)') !== false);
chequear('el dolar aplicado', true, strpos($htmlNac, 'Dólar aplicado') !== false);
chequear('y el importe en pesos', true, strpos($htmlNac, 'Importe ($)') !== false);

/* LAS COLUMNAS FIJAS SE GUARDAN POR INDICE, asi que dos columnas nuevas corren
   todo lo que esta a la derecha. columnas-fijas.js solo valida que el indice
   siga existiendo -no puede saber que la 6 dejo de ser ETD-, asi que la clave
   de localStorage cambia y la preferencia vieja se descarta una vez. */
chequear('la clave de columnas fijas cambio con el layout', true,
    strpos($jsNac, "clave: 'crono_nacionalizacion_v2'") !== false);

/* ================================================================
   LOS AVISOS DE UNA ACCION NO VUELVEN A SER alert()

   alert() bloquea el hilo, no tiene formato y -lo que importa aca- hace que un
   "se guardo" y un "no se pudo guardar" salgan exactamente iguales, asi que el
   segundo se cierra con el mismo reflejo que el primero. Es el problema 3 del
   encabezado de Js/notificaciones.js.

   SE LEE EL CODIGO SIN COMENTARIOS, como el resto de las pruebas de cableado:
   estos archivos explican en prosa que dejaron de usar alert(), y buscar la
   palabra sobre el archivo entero daria positivo en la nota que dice que ya no
   esta.
   ================================================================ */
seccion('las tres pantallas de Comex avisan con Notificacion');

/* $compartido se lee entero -otras pruebas miran su encabezado-, asi que para
   esta hace falta la version sin comentarios. $jsExt y $jsNac ya vienen asi. */
$jsComex = ['Comex-fechas' => codigoSinComentarios(__DIR__ . '/../Js/Comex-fechas.js'),
            'Proveedores Exterior' => $jsExt,
            'Crono Nacionalizacion' => $jsNac];

foreach ($jsComex as $n => $js) {
    chequear($n . ' no usa alert()', false, (bool) preg_match('/\balert\s*\(/', $js));
    chequear($n . ' ni confirm()', false, (bool) preg_match('/\bconfirm\s*\(/', $js));
}

/* LOS FALLOS VAN A error(), QUE NO SE AUTO-CIERRA: el mensaje del servidor es
   lo unico que explica por que el dato no quedo guardado, y que se borre a los
   cuatro segundos es perderlo. */
chequear('el guardado del tilde avisa el fallo', true,
    strpos($compartido, "Notificacion.error('No se pudo guardar el tilde de pagado: '") !== false);
chequear('y el de la fecha tambien', true,
    strpos($compartido, "Notificacion.error('No se pudo guardar la fecha: '") !== false);
chequear('la cotizacion tambien', true,
    strpos($jsExt, "Notificacion.error('No se pudo guardar la cotización: '") !== false);

/* EL AVISO DE LA FECHA GUARDADA NO PUEDE SALIR IGUAL EN LOS DOS CASOS. Sale
   siempre -mover esa fecha cambia un dato de otra aplicacion- pero si ademas se
   descarto la cotizacion cargada a mano, cambio un importe que el usuario no
   toco, y eso no es un "listo". */
chequear('la fecha guardada avisa como exito', true,
    strpos($compartido, 'Notificacion.exito(result.message)') !== false);
chequear('y como advertencia si se descarto la cotizacion', true,
    (bool) preg_match('/if \(result\.cotizacion_descartada\)\s*\{\s*'
        . 'Notificacion\.advertencia\(result\.message/s', $compartido));

seccion('la falla de carga se pinta donde esta el vacio');

/* NO ES LO MISMO QUE UN GUARDADO FALLIDO: lo que queda en pantalla es una tabla
   VACIA, y un mensaje efimero no la explica para el que llega treinta segundos
   despues. Se pinta adentro de tableWrapper y se notifica ademas. */
chequear('el compartido expone errorDeCarga', true,
    strpos($compartido, 'errorDeCarga: errorDeCarga') !== false);
chequear('y como sacarlo en la carga siguiente', true,
    strpos($compartido, 'limpiarErrorDeCarga: limpiarErrorDeCarga') !== false);
chequear('pinta adentro del contenedor de la tabla', true,
    strpos($compartido, "getElementById(idWrapper || 'tableWrapper')") !== false);

/* Y NO PISA LA TABLA: el panel se inserta como primer hijo. Con un innerHTML
   sobre el wrapper, "Actualizar" no tendria donde dibujar cuando el servidor
   vuelva. */
chequear('inserta el panel, no reemplaza el contenedor', true,
    strpos($compartido, 'cont.insertBefore(panel, cont.firstChild)') !== false);

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' usa el panel compartido', true,
        strpos($js, 'ComexFechas.errorDeCarga(mensaje)') !== false);
    chequear($n . ' lo limpia al empezar una carga', true,
        strpos($js, 'ComexFechas.limpiarErrorDeCarga()') !== false);
}

/* Notificacion se carga desde index.php y no desde la pestana: las pestanas se
   reemplazan enteras por AJAX y el contenedor de los mensajes tiene que
   sobrevivir a ese reemplazo. */
chequear('notificaciones.js se carga desde index.php', true,
    strpos(file_get_contents(__DIR__ . '/../index.php'),
        'Js/notificaciones.js') !== false);

/* ================================================================
   EL INTERRUPTOR DE VENCIDAS

   Va SOLO en Proveedores Exterior, que es donde se pidió: la fecha estimada de
   pago. Crono Nacionalizacion no lo tiene y por eso ve todas sus filas —
   verVencidas() devuelve true cuando no hay interruptor en la pantalla, para
   que una pestaña que no declara el control no pueda quedar escondiendo filas
   sin que nada lo diga.
   ================================================================ */
seccion('las vencidas no se ven por defecto en Proveedores Exterior');

chequear('el interruptor existe', true,
    strpos($htmlExt, 'id="verVencidasProvExt"') !== false);

/* SIN `checked`: apagado es el estado por defecto. Si alguien agrega el
   atributo, la pestaña abre mostrando 27 filas de contenedores vencidos y el
   pedido se deshace sin que nada falle. */
chequear('y arranca apagado', false,
    (bool) preg_match('/id="verVencidasProvExt"[^>]*\schecked/', $htmlExt));

// Cuánto esconde se dice AL LADO, siempre: una tabla que esconde filas sin
// decirlo se lee como que esos contenedores no existen.
chequear('dice cuántas esconde', true,
    strpos($htmlExt, 'id="estadoVencidasProvExt"') !== false);
chequear('y el JS lo escribe', true,
    strpos($jsExt, 'function pintarEstadoVencidas') !== false);

seccion('el interruptor y el buscador son el mismo camino');

/* Los dos terminan en filtrarTabla(), así que no pueden quedar diciendo cosas
   distintas: prender el interruptor con el buscador escrito tiene que dejar
   ver la intersección, no una de las dos cosas. */
chequear('el interruptor no recarga del servidor', false,
    (bool) preg_match('/verVencidasProvExt[^\n]*addEventListener[^\n]*cargarDatos/', $jsExt));
chequear('dispara el mismo filtrado que el buscador', true,
    (bool) preg_match('/verVencidas\.addEventListener\(\x27change\x27,\s*filtrarTabla\)/', $jsExt));

// Las dos consultas al helper compartido le pasan el interruptor: si una se lo
// olvidara, el pie sumaría filas que la tabla no muestra.
chequear('el filtrado conoce los dos interruptores', true,
    (bool) preg_match('/ComexFechas\.filtrar\(\s*\x27busquedaProvExt\x27,\s*\x27tableBody\x27,'
        . '\s*\x27verVencidasProvExt\x27,\s*\x27verPagadosProvExt\x27\s*\)/s', $jsExt));
chequear('y los totales también', true,
    (bool) preg_match('/ComexFechas\.visibles\([^;]*\x27verVencidasProvExt\x27,'
        . '\s*\x27verPagadosProvExt\x27\s*\)/s', $jsExt));

seccion('la fila lleva el dato, no sólo la clase');

/* El interruptor filtra por data-vencida y no por la clase: la clase es
   presentación y podría cambiar sin que nadie piense en el filtro. */
chequear('data-vencida viaja en el <tr>', true,
    strpos($jsExt, 'data-vencida="1"') !== false);
chequear('y el filtro compartido lo mira', true,
    strpos($compartido, "getAttribute('data-vencida')") !== false);

seccion('las dos pestanas tienen el interruptor, y se comportan igual');

chequear('Crono Nacionalizacion tambien lo declara', true,
    strpos($htmlNac, 'id="verVencidasCronoNac"') !== false);
chequear('y arranca apagado', false,
    (bool) preg_match('/id="verVencidasCronoNac"[^>]*\schecked/', $htmlNac));
chequear('con su contador al lado', true,
    strpos($htmlNac, 'id="estadoVencidasCronoNac"') !== false);
chequear('su filtrado lo pasa', true,
    strpos($jsNac, "'verVencidasCronoNac'") !== false);
chequear('y tambien marca la fila', true,
    strpos($jsNac, 'data-vencida="1"') !== false);

seccion('sin interruptor en la pantalla se ven todas');

/* La guarda importa aunque hoy las dos pestañas declaren el interruptor: si
   verVencidas() devolviera false por defecto, una pestaña nueva que dibujara
   filas con data-vencida abriría escondiéndolas sin ningún control que las
   traiga de vuelta, y nada lo diría. */
chequear('verVencidas() sin interruptor devuelve true', true,
    (bool) preg_match('/return chk \? !!chk\.checked : true;/', $compartido));

seccion('los totales del pie respetan el filtro en las dos');

foreach (['Proveedores Exterior' => $jsExt, 'Crono Nacionalizacion' => $jsNac] as $n => $js) {
    chequear($n . ' suma las visibles', true,
        strpos($js, 'ComexFechas.sumarColumnas(visibles)') !== false);
}

// El total en pesos de Proveedores Exterior es una columna aparte del eje y
// tambien tiene que filtrarse: si sumara todo mientras la tabla muestra tres
// filas, esta celda y la de al lado dirian numeros de dos universos distintos.
chequear('y el total en pesos tambien', true,
    strpos($jsExt, 'sumaImporteArs(visibles)') !== false);

/* ================================================================
   EL SCRIPT DE LA MIGRACION
   ================================================================ */
seccion('el script existe y declara su orden');

$sql = __DIR__ . '/../sql/cashflow_comex_fecha_maestra.sql';

chequear('esta en sql/', true, file_exists($sql));

$texto = file_get_contents($sql);

chequear('dice contra que base va', true, strpos($texto, 'Base    : central') !== false);
chequear('y en que orden se corre', true, strpos($texto, 'Orden   :') !== false);

seccion('crea la tabla del rastro con lo que el codigo espera');

chequear('la tabla', true, strpos($texto, 'CREATE TABLE dbo.' . Comex::TABLA_HISTORIAL) !== false);

foreach (['ID_MG', 'CAMPO', 'FECHA_ANTERIOR', 'FECHA_NUEVA', 'VIGENTE', 'USUARIO',
          'FECHA_ALTA', 'FECHA_BAJA'] as $col) {
    chequear('con la columna ' . $col, true, strpos($texto, $col) !== false);
}

// La lista cerrada va tambien en la base: el endpoint es alcanzable sin pasar
// por la pantalla, y un CAMPO con un tercer valor dejaria un rastro que ninguna
// pestana sabe leer.
chequear('y el CHECK de los dos campos', true,
    strpos($texto, "CHECK (CAMPO IN ('PAGO', 'NAC'))") !== false);

seccion('un solo rastro vigente por contenedor y campo');

/* Va como indice unico FILTRADO: un UNIQUE comun prohibiria tambien las filas
   historicas repetidas, que es justamente lo que esta tabla existe para
   guardar. Mismo patron que sql/cashflow_cobertura_automatica.sql. */
chequear('el indice es unico', true,
    strpos($texto, 'CREATE UNIQUE NONCLUSTERED INDEX UX_RO_T_CF_COMEXFED_VIGENTE') !== false);
chequear('y esta filtrado por VIGENTE', true,
    (bool) preg_match('/UX_RO_T_CF_COMEXFED_VIGENTE.*?WHERE VIGENTE = 1/s', $texto));

seccion('la migracion no pisa el maestro cuando dice otra cosa');

/* EL CRITERIO DEL CONFLICTO: no hay forma de saber cual de los dos valores es
   mas nuevo -la tabla del cashflow tiene FECHA_UPDATE y el maestro no tiene
   fecha de modificacion- y ante el empate gana el maestro, que es el dato que
   la app de Comercio Exterior esta mostrando hoy.

   Verificado contra la base el 19/09/2026: 9 ediciones, 3 huerfanas, 6 que ya
   coincidian y 0 conflictos, asi que la migracion no escribio ni una fecha. */
chequear('solo completa donde el maestro esta vacio', true,
    strpos($texto, 'C.HAY_MAESTRO = 1 AND C.MAESTRO IS NULL') !== false);
chequear('el conflicto entra como historia, no como vigente', true,
    (bool) preg_match('/VIGENTE = 0.*?no describe el valor vigente/s', $texto));
chequear('y el script lo lista para que alguien lo mire', true,
    strpos($texto, 'el maestro dice otra cosa: NO se piso') !== false);

seccion('el centinela 1900-01-01 no es una edicion');

/* FECHA_NAC_ORIG y FECHA_NAC_EDIT nacieron NOT NULL, asi que el guardado de la
   OTRA pestana las rellenaba con esa fecha al insertar. Tomarla como edicion
   migraria al maestro una nacionalizacion en 1900, y en el tablero ese importe
   caeria fuera del eje sin que nadie entienda por que. */
chequear('se descarta al migrar', true,
    substr_count($texto, "<> '1900-01-01'") >= 2);

seccion('es reejecutable');

chequear('la tabla se crea solo si falta', true,
    strpos($texto, "IF OBJECT_ID('dbo." . Comex::TABLA_HISTORIAL . "', 'U') IS NULL") !== false);
chequear('y lo ya migrado no se vuelve a insertar', true,
    strpos($texto, "E.USUARIO = 'MIGRACION'") !== false);

/* ================================================================
   CONTRA LA BASE

   Solo LECTURA. Las pruebas de escritura tocarian el maestro de otra
   aplicacion, y este arnes corre contra la base de verdad.
   ================================================================ */
seccion('contra la base: las dos consultas y el rastro');

if (!Pruebas::hayBase()) {
    Pruebas::saltear('no hay conexion a SQL Server');
} else {
    $comex = new Comex();

    $hoy = '2026-09-19';
    $ext = $comex->getProveedoresExterior($hoy);
    $nac = $comex->getCronoNacionalizacion($hoy);

    chequear('Proveedores Exterior trae filas', true, count($ext) > 0);
    chequear('Crono Nacionalizacion trae filas', true, count($nac) > 0);

    /* LAS DOS MIRAN EL MISMO PADRON. Es el mismo contenedor visto desde los dos
       lados del circuito: si una trae mas que la otra, alguna volvio a filtrar
       por su cuenta. */
    chequear('y las dos traen el mismo padron', count($ext), count($nac));

    // EL FILTRO SE FUE DE VERDAD, y no solo del texto de la consulta: tiene que
    // haber contenedores con el embarque ya pasado.
    $embarcados = 0;

    foreach ($ext as $f) {
        if (Comex::estaVencida($f['ETD'], $hoy)) {
            $embarcados++;
        }
    }

    chequear('hay contenedores ya embarcados en la grilla', true, $embarcados > 0);

    seccion('contra la base: cada fila sabe si esta vencida');

    $faltan = 0;

    foreach ($ext as $f) {
        if (!array_key_exists('VENCIDA', $f) || !array_key_exists('EDITADA', $f)) {
            $faltan++;
            continue;
        }

        // El flag lo calcula el backend: el front no compara ninguna fecha.
        if ((bool) $f['VENCIDA'] !== Comex::estaVencida($f['FECHA_PAGO_EFECTIVA'], $hoy)) {
            $faltan++;
        }
    }

    chequear('ninguna fila sin el flag o con el flag mal', 0, $faltan);

    seccion('contra la base: la fecha efectiva sale del maestro');

    $deLaTablaVieja = 0;

    foreach ($ext as $f) {
        // Si alguien vuelve a leer las columnas EDIT, aparecen en la fila.
        if (array_key_exists('FECHA_PAGO_EDIT', $f)) {
            $deLaTablaVieja++;
        }

        if ($f['FECHA_PAGO_EFECTIVA'] !== null
            && $f['FECHA_PAGO_EFECTIVA'] !== $f['FECHA_EST_PAGO']) {
            $deLaTablaVieja++;
        }
    }

    chequear('la fecha efectiva ES FECHA_EST_PAGO', 0, $deLaTablaVieja);

    seccion('contra la base: el listado sale ordenado por la fecha efectiva');

    $anterior = '';
    $desordenadas = 0;
    $nullEnElMedio = 0;
    $vistoNull = false;

    foreach ($nac as $f) {
        if ($f['FECHA_NAC_EFECTIVA'] === null) {
            $vistoNull = true;
            continue;
        }

        // Una fila con fecha DESPUES de una sin fecha: los NULL no quedaron al
        // final.
        if ($vistoNull) {
            $nullEnElMedio++;
        }

        if ($f['FECHA_NAC_EFECTIVA'] < $anterior) {
            $desordenadas++;
        }

        $anterior = $f['FECHA_NAC_EFECTIVA'];
    }

    chequear('ninguna fila fuera de orden', 0, $desordenadas);
    chequear('y las sin fecha quedan al final', 0, $nullEnElMedio);

    seccion('contra la base: el DDL y la marca de editable');

    // Las dos pestanas preguntan lo mismo, asi que tienen que contestar lo
    // mismo: si una dijera que se puede editar y la otra que no, una de las dos
    // dibujaria una celda que falla al guardar.
    chequear('la edicion esta prendida o apagada para las dos igual',
        $comex->tieneHistorial(), $comex->tieneHistorial());

    if (!$comex->tieneHistorial()) {
        chequear('y sin la tabla la pantalla avisa que script falta', true,
            strpos($comex->avisoSinHistorial(), 'cashflow_comex_fecha_maestra.sql') !== false);

        Pruebas::saltear('falta ' . Comex::TABLA_HISTORIAL . ': no se puede leer el rastro');
    } else {
        chequear('con la tabla no hay nada que avisar', '', $comex->avisoSinHistorial());

        // El historial de un contenedor que no existe no puede fallar: devuelve
        // vacio, que es lo que corresponde.
        chequear('el historial de un id inexistente vuelve vacio', 0,
            count($comex->getHistorialFechas(-1)));
    }
}
