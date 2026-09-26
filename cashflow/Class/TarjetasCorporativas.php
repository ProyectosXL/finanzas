<?php

require_once __DIR__ . '/TarjetasVencimiento.php';
require_once __DIR__ . '/ProveedoresCategorias.php';

/**
 * TarjetasCorporativas
 * Las facturas pendientes de Tango que se pagan con tarjeta corporativa: cuando
 * salen, cuanta cobertura generan y cual reemplaza un resumen cargado.
 *
 * TODO LO DE ACA ES PURO. Recibe las facturas ya leidas -por
 * Proveedores::getPendientes(), la MISMA consulta de Cuentas a Pagar Locales-,
 * los vinculos, las exclusiones, las tarjetas y los resumenes, y devuelve
 * numeros. Es lo que permite probar la cobertura, la exclusion y el reemplazo por
 * resumen sin montar facturas en Tango.
 *
 * EL UNIVERSO: FORMA DE PAGO VIGENTE 'TARJETA CORP'
 * ------------------------------------------------
 * VIGENTE quiere decir el override por factura de Proveedores Locales
 * (FORMA_PAGO_CRONOGRAMA) si lo hay, y si no la forma del maestro. Esa resolucion
 * ya viene hecha en FORMA_PAGO_VIGENTE, que arma Proveedores::formaDelCronograma():
 * no se rehace aca, porque dos versiones de la misma regla clasificarian distinto
 * la misma factura en dos pantallas.
 *
 * NO SE ESCRIBE UNA SEGUNDA CONSULTA A CPA04/CPA54. La de getPendientes()
 * reproduce a Tango al centavo -con la tabla de signos de las imputaciones, que es
 * la parte facil de hacer mal- y duplicarla seria duplicar ese riesgo.
 *
 * VAN DIRECTO AL FLUJO
 * --------------------
 * No hace falta marcarlas ni vincularlas para que entren: son deuda real y ya
 * emitida. Vincularlas a una tarjeta cambia DOS cosas -generan cobertura y las
 * puede reemplazar un resumen- pero no si entran.
 *
 * LA FECHA ES EL VENCIMIENTO DE TANGO
 * -----------------------------------
 * Y NO la jerarquia de Proveedores Locales, que prefiere la fecha de pago cargada
 * a mano. Es una diferencia deliberada: esta pestana es la dueña del circuito de
 * tarjeta corporativa, y el pago de una tarjeta lo fija el banco, no una fecha que
 * alguien cargo pensando en un echeq. La consecuencia hay que tenerla presente: la
 * MISMA factura puede verse en fechas distintas en las dos pestanas. Ver
 * README-pagos-tarjetas.md.
 *
 * LO VENCIDO NO SE APILA EN EL PRIMER DIA DEL EJE
 * -----------------------------------------------
 * Y ahi esta la diferencia mas grande con Proveedores Locales, que si lo hace.
 * Una tarjeta se paga UNA VEZ POR MES:
 *
 *   vencida Y VINCULADA a una tarjeta -> sale en el PROXIMO PAGO de esa tarjeta
 *                                        (el resumen cargado si lo hay, o el
 *                                        proximo DIA_VENCIMIENTO posterior a hoy).
 *                                        Entra en la cobertura de ese mes y la
 *                                        pisa el resumen de ese mes, igual que las
 *                                        facturas que vencen ahi.
 *
 *   vencida Y SIN VINCULAR             -> NO se proyecta, y se avisa con el
 *                                        conteo y el importe. Sin tarjeta no hay
 *                                        fecha de pago, y apilarla en el dia uno
 *                                        seria inventar una: afirmaria que se paga
 *                                        hoy.
 *
 * SOLO EN PESOS. Las facturas de Tango de estos proveedores estan en pesos; la
 * consulta de getPendientes() ademas deja afuera a los de clausula de moneda
 * extranjera.
 *
 * LA COBERTURA ES UN RENGLON APARTE, NO UN MULTIPLICADOR
 * -----------------------------------------------------
 * En Gastos Supervisoras y en Tarjetas Socios el % de cobertura multiplica la base
 * estimada. Aca NO, y es a proposito: la base son facturas reales de Tango, y
 * multiplicarlas seria inventar deuda sobre un comprobante que existe. La
 * cobertura sale como una fila propia -"Cobertura gastos excepcionales"- por
 * tarjeta y mes, en el DIA_VENCIMIENTO de la tarjeta.
 *
 * LAS NO VINCULADAS NO GENERAN COBERTURA: no se sabe con que tarjeta se pagan, asi
 * que no hay % que aplicar.
 *
 * EL RESUMEN REEMPLAZA LAS VINCULADAS DE SU MES, Y SU COBERTURA
 * ------------------------------------------------------------
 * El resumen es lo que el banco va a debitar: si esta cargado, las facturas
 * vinculadas que vencen en ese mes ya estan adentro. Siguen viendose en la grilla,
 * marcadas, pero no suman.
 *
 * LAS NO VINCULADAS DEL MISMO MES NO SE TOCAN, y ahi hay un riesgo que se avisa:
 * pueden estar incluidas en el resumen y contarse dos veces. No se puede resolver
 * por codigo -saber si un consumo del resumen corresponde a una factura concreta
 * es mirar el resumen- asi que se avisa y se resuelve vinculando o excluyendo.
 */
class TarjetasCorporativas {

    /** La forma de pago que define el universo */
    const FORMA = 'TARJETA CORP';

    /** Por que una factura no entra al flujo */
    const OK = 'OK';
    const EXCLUIDA = 'EXCLUIDA';
    const CUBIERTA = 'CUBIERTA';
    const VENCIDA_SIN_TARJETA = 'VENCIDA_SIN_TARJETA';
    const SIN_FECHA = 'SIN_FECHA';

    /** De donde sale la fecha con la que entra al eje */
    const FECHA_VTO = 'VENCIMIENTO';
    const FECHA_REUBICADA = 'REUBICADA';

    /* ====================================================================
       EL UNIVERSO
       ==================================================================== */

    /**
     * Las facturas de tarjeta corporativa, de todo el listado de pendientes.
     *
     * MIRA FORMA_PAGO_VIGENTE, que es la que decide. Ver el encabezado: la
     * resolucion del override ya viene hecha por Proveedores::formaDelCronograma()
     * y no se rehace aca.
     *
     * SE COMPARA EXACTO Y NO NORMALIZANDO, y eso es correcto porque el valor YA
     * viene normalizado por los dos caminos posibles:
     *
     *   - la forma del maestro la normaliza ProveedoresCategorias::mapa() AL LEER,
     *     contra FORMAS_PAGO;
     *   - el override por factura lo valida y normaliza
     *     Proveedores::saveFormaCronograma() AL ESCRIBIR, contra la misma lista, y
     *     rechaza cualquier texto que no este en ella.
     *
     * Asi que FORMA_PAGO_VIGENTE es siempre una de las constantes de FORMAS_PAGO o
     * null. Normalizar de nuevo aca seria una tercera normalizacion del mismo
     * valor, que es exactamente como se separan dos criterios.
     *
     * Estatica y pura.
     *
     * @param array $pendientes Lo que devolvio Proveedores::getPendientes()
     * @return array Las filas de tarjeta corporativa, tal como venian
     */
    public static function universo($pendientes) {
        $v = [];

        foreach (is_array($pendientes) ? $pendientes : [] as $p) {
            $forma = isset($p['FORMA_PAGO_VIGENTE']) ? trim((string) $p['FORMA_PAGO_VIGENTE']) : '';

            if ($forma === self::FORMA) {
                $v[] = $p;
            }
        }

        return $v;
    }

    /**
     * La clave de un comprobante, la MISMA que Proveedores Locales.
     *
     * Se delega en Proveedores::clavePago() en vez de repetir el formato: las
     * cuatro tablas del circuito y la de Proveedores Locales comparten una sola
     * clave, y dos formas de armarla divergirian en el primer codigo con espacios.
     *
     * Estatica y pura.
     *
     * @param array $f Una fila de getPendientes()
     * @return string
     */
    public static function clave($f) {
        require_once __DIR__ . '/Proveedores.php';

        return Proveedores::clavePago(
            isset($f['COD_PROVEE']) ? $f['COD_PROVEE'] : '',
            isset($f['T_COMP']) ? $f['T_COMP'] : '',
            isset($f['N_COMP']) ? $f['N_COMP'] : '');
    }

    /* ====================================================================
       LA RESOLUCION
       ==================================================================== */

    /**
     * Resuelve cada factura: su tarjeta, su fecha, si entra al flujo y por que no.
     *
     * DEVUELVE LAS FILAS ENTERAS, tambien las que no entran: la grilla tiene que
     * poder mostrar las excluidas -atenuadas y tachadas- y las cubiertas por un
     * resumen, y un listado que las esconde se lee como que esas facturas no
     * existen. Quien acumula contra el eje filtra por 'proyecta'.
     *
     * EL ORDEN DE PRECEDENCIA, y no es arbitrario:
     *
     *   1. EXCLUIDA gana sobre todo. Es una decision explicita de una persona, y
     *      una factura excluida que ademas estuviera cubierta por un resumen se
     *      contaria en la serie informativa Y en el resumen.
     *   2. CUBIERTA por el resumen de su tarjeta y su mes.
     *   3. VENCIDA_SIN_TARJETA.
     *   4. entra, en su fecha.
     *
     * Estatica y pura.
     *
     * @param array $facturas Lo que devolvio universo()
     * @param array $vinculos Mapa clave => ID_TARJETA
     * @param array $excluidas Mapa clave => ['MOTIVO', ...]
     * @param array $tarjetas Mapa ID => tarjeta
     * @param array $resumenes Mapa ID_TARJETA => ['Y-m' => resumen]
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $meses Los meses del horizonte, 'Y-m'
     * @param string $hoy 'Y-m-d'
     * @return array ['filas' => [...], 'faltan_calendario' => ['Y-m']]
     */
    public static function resolver($facturas, $vinculos, $excluidas, $tarjetas,
                                    $resumenes, $habiles, $meses, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $filas = [];
        $faltan = [];

        /* EL PROXIMO PAGO SE RESUELVE UNA VEZ POR TARJETA y no una por factura:
           es la misma cuenta para todas las vencidas de la misma tarjeta, y
           hacerla por factura la repetiria hasta diez veces por tarjeta sin que
           el resultado pueda cambiar. */
        $proximos = [];

        foreach ($tarjetas as $id => $t) {
            $delaTarjeta = isset($resumenes[$id]) ? $resumenes[$id] : [];

            $proximos[$id] = TarjetasVencimiento::proximoPago(
                $t['DIA_VENCIMIENTO'], $delaTarjeta, $meses, $habiles, $hoyStr);
        }

        foreach ($facturas as $f) {
            $clave = self::clave($f);
            $idTarjeta = isset($vinculos[$clave]) ? intval($vinculos[$clave]) : null;
            $tarjeta = ($idTarjeta !== null && isset($tarjetas[$idTarjeta]))
                ? $tarjetas[$idTarjeta] : null;

            $fila = array_merge($f, [
                'CLAVE' => $clave,
                'ID_TARJETA' => $tarjeta === null ? null : $idTarjeta,
                'TARJETA' => $tarjeta,

                /* LA TARJETA VINCULADA PERO QUE YA NO EXISTE se informa aparte:
                   la FK de la tabla lo impide, asi que solo puede pasar si alguien
                   la borro a mano, y confundirlo con "sin vincular" esconderia una
                   base inconsistente. */
                'VINCULO_ROTO' => ($idTarjeta !== null && $tarjeta === null),

                'EXCLUIDA_TARJETAS' => isset($excluidas[$clave]),
                'MOTIVO_EXCLUSION_TARJETAS' => isset($excluidas[$clave])
                    ? $excluidas[$clave]['MOTIVO'] : null,
                'IMPORTE' => round(floatval($f['IMPORTE_PENDIENTE']), 2)
            ]);

            $fechaVto = isset($f['FECHA_VTO']) ? $f['FECHA_VTO'] : null;
            $fila['VENCIDA'] = ($fechaVto !== null && $fechaVto < $hoyStr);

            /* LA FECHA: el vencimiento de Tango, salvo que este vencida y
               vinculada, que se reubica en el proximo pago de su tarjeta. */
            $fila['FECHA'] = $fechaVto;
            $fila['FECHA_ORIGEN'] = self::FECHA_VTO;
            $fila['MES_PAGO'] = ($fechaVto === null) ? null : substr($fechaVto, 0, 7);
            $fila['REUBICADA'] = false;

            if ($fila['VENCIDA'] && $tarjeta !== null) {
                $prox = $proximos[$idTarjeta];

                if ($prox !== null) {
                    $fila['FECHA'] = $prox['fecha'];
                    $fila['FECHA_ORIGEN'] = self::FECHA_REUBICADA;
                    $fila['MES_PAGO'] = $prox['mes'];
                    $fila['REUBICADA'] = true;

                    if ($prox['vencimiento'] !== null) {
                        foreach ($prox['vencimiento']['faltan'] as $m) {
                            if (!in_array($m, $faltan, true)) {
                                $faltan[] = $m;
                            }
                        }
                    }
                } else {
                    /* LA TARJETA NO TIENE NINGUN PAGO EN EL HORIZONTE. Pasa si el
                       eje se termina antes; el importe cae fuera del horizonte y
                       eso es lo que se informa, sin inventarle una fecha. */
                    $fila['FECHA'] = null;
                    $fila['MES_PAGO'] = null;
                }
            }

            /* EL RESUMEN DE SU TARJETA Y SU MES DE PAGO. Si esta, la factura ya
               esta adentro de ese importe. */
            $resumen = ($tarjeta !== null && $fila['MES_PAGO'] !== null
                        && isset($resumenes[$idTarjeta][$fila['MES_PAGO']]))
                ? $resumenes[$idTarjeta][$fila['MES_PAGO']] : null;

            $fila['CUBIERTA_POR_RESUMEN'] = ($resumen !== null);
            $fila['ID_RESUMEN'] = ($resumen === null) ? null : $resumen['ID'];

            $fila['MOTIVO'] = self::motivoDe($fila);
            $fila['PROYECTA'] = ($fila['MOTIVO'] === self::OK);

            /* LA EXPLICACION VIAJA CON LA FILA, armada por el backend. Es el
               tooltip de la fila en la grilla, y describe una decision que toma el
               backend: con el texto en el front, cambiar la regla obligaria a
               cambiarla en dos lados y el segundo se olvida. Mismo criterio que
               LogisticaValorHora::explicar() y que el resto del modulo. */
            $fila['EXPLICACION'] = self::explicar($fila);

            $filas[] = $fila;
        }

        return ['filas' => $filas, 'faltan_calendario' => $faltan];
    }

    /**
     * Por que una factura no entra al flujo, o OK si entra.
     *
     * Estatica y pura. El orden de precedencia esta explicado en resolver().
     *
     * @param array $fila Una fila ya resuelta
     * @return string
     */
    public static function motivoDe($fila) {
        if (!empty($fila['EXCLUIDA_TARJETAS'])) {
            return self::EXCLUIDA;
        }

        if (!empty($fila['CUBIERTA_POR_RESUMEN'])) {
            return self::CUBIERTA;
        }

        /* VENCIDA Y SIN TARJETA: no se proyecta. Sin tarjeta no hay fecha de pago,
           y el vencimiento de Tango ya paso, asi que no queda ninguna fecha que
           no sea inventada. */
        if (!empty($fila['VENCIDA']) && empty($fila['ID_TARJETA'])) {
            return self::VENCIDA_SIN_TARJETA;
        }

        if (empty($fila['FECHA'])) {
            return self::SIN_FECHA;
        }

        return self::OK;
    }

    /**
     * El texto que explica por que una factura no entra.
     *
     * LO ARMA EL BACKEND, por el mismo motivo que los demas textos del modulo:
     * describe una decision que toma el backend.
     *
     * Estatica y pura.
     *
     * @param array $fila
     * @return string
     */
    public static function explicar($fila) {
        switch ($fila['MOTIVO']) {
            case self::EXCLUIDA:
                return 'Excluida de esta pestaña: '
                    . ($fila['MOTIVO_EXCLUSION_TARJETAS'] ?: 'sin motivo registrado')
                    . '. No suma a la fila del tablero; va a su propia serie, que es '
                    . 'informativa. NO está excluida de Cuentas a Pagar Locales: son dos '
                    . 'decisiones distintas.';

            case self::CUBIERTA:
                return 'Cubierta por el resumen de ' . $fila['MES_PAGO'] . ' de su tarjeta: ese '
                    . 'importe ya incluye esta factura, así que no suma aparte. Se sigue viendo '
                    . 'para poder controlarla contra el resumen.';

            case self::VENCIDA_SIN_TARJETA:
                return 'Venció el ' . self::corto($fila['FECHA_VTO']) . ' y no está vinculada a '
                    . 'ninguna tarjeta, así que no hay fecha de pago con la que proyectarla. '
                    . 'Vinculala a una tarjeta para que entre al flujo: sale en el próximo '
                    . 'vencimiento de esa tarjeta. No se apila en el primer día del eje, porque '
                    . 'eso afirmaría que se paga hoy.';

            case self::SIN_FECHA:
                return 'No tiene fecha de vencimiento en Tango y no se pudo ubicar en el eje.';
        }

        if (!empty($fila['REUBICADA'])) {
            return 'Venció el ' . self::corto($fila['FECHA_VTO']) . ' y sale en el próximo pago '
                . 'de su tarjeta, el ' . self::corto($fila['FECHA']) . ': una tarjeta se paga una '
                . 'vez por mes.';
        }

        return 'Entra por su fecha de vencimiento de Tango, el '
            . self::corto($fila['FECHA']) . '.';
    }

    /* ====================================================================
       LA COBERTURA
       ==================================================================== */

    /**
     * La cobertura de cada tarjeta y mes: un renglon de estimacion aparte.
     *
     *     cobertura(t, m) = PCT_COBERTURA(t) / 100 x Σ facturas vinculadas a t
     *                       cuyo mes de pago es m
     *
     * SOBRE LAS VINCULADAS Y NO EXCLUIDAS, Y NO CUBIERTAS POR UN RESUMEN. Las tres
     * exclusiones son por el mismo motivo: la cobertura acompaña a un importe que
     * esta en la fila, y un importe que no esta no necesita cobertura.
     *
     * SE UBICA EN EL DIA_VENCIMIENTO DE LA TARJETA, no en la fecha de cada
     * factura: es un gasto de la tarjeta, no de la factura, y la tarjeta se debita
     * una vez por mes.
     *
     * EL RESUMEN LA REEMPLAZA: si hay resumen para (t, m), no hay cobertura de ese
     * mes, porque el resumen ya es el importe real que se va a debitar.
     *
     * UNA TARJETA CON 0 % NO GENERA NINGUNA FILA, y no una fila en cero: una fila
     * de cobertura en cero se lee como "esta tarjeta tiene cobertura y este mes no
     * la usa", que es otra cosa.
     *
     * Estatica y pura.
     *
     * @param array $filas Lo que devolvio resolver()['filas']
     * @param array $tarjetas Mapa ID => tarjeta
     * @param array $resumenes Mapa ID_TARJETA => ['Y-m' => resumen]
     * @param array $habiles Mapa 'Y-m-d' => bool
     * @param array $meses Los meses del horizonte
     * @param string $hoy 'Y-m-d'
     * @return array Lista de ['id_tarjeta', 'mes', 'base', 'pct', 'importe',
     *               'fecha', 'proyecta', 'motivo', 'facturas' => int]
     */
    public static function cobertura($filas, $tarjetas, $resumenes, $habiles, $meses, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $base = [];

        foreach ($filas as $f) {
            /* SOLO LO QUE EFECTIVAMENTE SUMA. Ver el encabezado del metodo: la
               cobertura acompaña a un importe que esta en la fila. */
            if (!$f['PROYECTA'] || empty($f['ID_TARJETA']) || $f['MES_PAGO'] === null) {
                continue;
            }

            $id = intval($f['ID_TARJETA']);
            $mes = $f['MES_PAGO'];

            if (!isset($base[$id][$mes])) {
                $base[$id][$mes] = ['importe' => 0.0, 'facturas' => 0];
            }

            $base[$id][$mes]['importe'] += $f['IMPORTE'];
            $base[$id][$mes]['facturas']++;
        }

        $v = [];

        foreach ($base as $id => $porMes) {
            if (!isset($tarjetas[$id])) {
                continue;
            }

            $t = $tarjetas[$id];
            $pct = floatval($t['PCT_COBERTURA']);

            /* SIN COBERTURA NO HAY FILA. Ver el encabezado del metodo. */
            if ($pct <= 0) {
                continue;
            }

            ksort($porMes);

            foreach ($porMes as $mes => $b) {
                /* EL RESUMEN REEMPLAZA LA COBERTURA DE SU MES: ya es el importe
                   real que el banco va a debitar. */
                if (isset($resumenes[$id][$mes])) {
                    continue;
                }

                $vto = TarjetasVencimiento::delMes($t['DIA_VENCIMIENTO'], $mes, $habiles);
                $importe = $b['importe'] * $pct / 100;
                $vencida = ($vto['fecha'] <= $hoyStr);

                $v[] = [
                    'id_tarjeta' => $id,
                    'mes' => $mes,
                    'base' => round($b['importe'], 2),
                    'pct' => $pct,
                    'importe' => $importe,
                    'facturas' => $b['facturas'],
                    'fecha' => $vto['fecha'],
                    'proyecta' => !$vencida,
                    'motivo' => $vencida
                        ? 'El vencimiento de este mes ya pasó, así que esta cobertura no se '
                          . 'proyecta.'
                        : null,
                    'tooltip' => 'Cobertura gastos excepcionales: ' . self::pct($pct) . ' % de '
                        . self::plata($b['importe']) . ' en ' . $b['facturas'] . ' factura(s) '
                        . 'vinculadas que se pagan en ' . $mes . '. '
                        . TarjetasVencimiento::explicar($vto)
                ];
            }
        }

        return $v;
    }

    /* ====================================================================
       LOS RESUMENES QUE ENTRAN AL FLUJO
       ==================================================================== */

    /**
     * Los resumenes de las tarjetas corporativas que se proyectan.
     *
     * UN RESUMEN REEMPLAZA a las facturas vinculadas de su mes y a su cobertura,
     * asi que entra al flujo con su propio importe y su propia fecha.
     *
     * UN RESUMEN PAGADO NO ENTRA: ya salio de la cuenta y ya esta en el saldo
     * bancario que abre el cuadro.
     *
     * SOLO EL COMPONENTE EN PESOS. Las facturas de tarjeta corporativa estan en
     * pesos, asi que un resumen con importe en dolares en una tarjeta corporativa
     * es un dato que no pertenece a este circuito: se informa en un aviso y no se
     * convierte, porque convertirlo lo metería al flujo por una puerta que esta
     * pestaña no tiene.
     *
     * Estatica y pura.
     *
     * @param array $tarjetas Mapa ID => tarjeta (solo las corporativas)
     * @param array $resumenes Mapa ID_TARJETA => ['Y-m' => resumen]
     * @param array $meses Los meses del horizonte
     * @param string $hoy 'Y-m-d'
     * @return array Lista de ['id_tarjeta', 'mes', 'importe', 'fecha', 'proyecta',
     *               'pagado', 'motivo', 'usd']
     */
    public static function resumenesAProyectar($tarjetas, $resumenes, $meses, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $enHorizonte = array_fill_keys($meses, true);
        $v = [];

        foreach ($tarjetas as $id => $t) {
            if (!isset($resumenes[$id])) {
                continue;
            }

            foreach ($resumenes[$id] as $mes => $r) {
                /* SOLO LOS MESES DEL EJE. Un resumen de un mes anterior es
                   historia: ya vencio y no hay nada que proyectar. */
                if (!isset($enHorizonte[$mes])) {
                    continue;
                }

                $pagado = !empty($r['PAGADO']);
                $ars = isset($r['IMPORTE_ARS']) ? $r['IMPORTE_ARS'] : null;
                $vencido = ($r['FECHA_VENCIMIENTO'] !== null
                            && $r['FECHA_VENCIMIENTO'] < $hoyStr);

                $v[] = [
                    'id_tarjeta' => $id,
                    'id_resumen' => $r['ID'],
                    'mes' => $mes,
                    'importe' => $ars,
                    'usd' => isset($r['IMPORTE_USD']) ? $r['IMPORTE_USD'] : null,
                    'fecha' => $r['FECHA_VENCIMIENTO'],
                    'pagado' => $pagado,
                    'proyecta' => (!$pagado && $ars !== null && $ars > 0 && !$vencido),
                    'motivo' => $pagado
                        ? 'Pagado: sale del horizonte, porque esa plata ya está reflejada en el '
                          . 'saldo bancario.'
                        : ($ars === null
                            ? 'El resumen no tiene importe en pesos, y las facturas de tarjeta '
                              . 'corporativa son en pesos.'
                            : ($vencido
                                ? 'Venció el ' . self::corto($r['FECHA_VENCIMIENTO'])
                                  . ' y no está marcado como pagado: no se proyecta, pero '
                                  . 'convendría marcarlo.'
                                : null))
                ];
            }
        }

        return $v;
    }

    /* ====================================================================
       LOS AVISOS
       ==================================================================== */

    /**
     * Lo que queda afuera y hay que decir.
     *
     * CADA AVISO DESCRIBE UN HECHO DISTINTO Y VA APARTE. Juntarlos en uno haria
     * que el importe total no se pudiera atribuir a ninguna causa, que es
     * justamente lo que un aviso tiene que permitir.
     *
     * Estatica y pura.
     *
     * @param array $filas Lo que devolvio resolver()['filas']
     * @param array $resumenes Mapa ID_TARJETA => ['Y-m' => resumen]
     * @return array Lista de mensajes
     */
    public static function avisos($filas, $resumenes = []) {
        $avisos = [];

        /* 1. VENCIDAS SIN VINCULAR: es el aviso que va PRIMERO, porque es plata
              real y ya emitida que el tablero NO esta mostrando, y se arregla con
              una accion concreta en esta misma pantalla. */
        $vencidas = self::juntar($filas, self::VENCIDA_SIN_TARJETA);

        if ($vencidas['cuantas'] > 0) {
            $avisos[] = $vencidas['cuantas'] . ' factura(s) vencidas por '
                . self::plata($vencidas['importe']) . ' NO entran al flujo porque no están '
                . 'vinculadas a ninguna tarjeta: sin tarjeta no hay fecha de pago. Vinculalas a '
                . 'una tarjeta para que entren, en el próximo vencimiento de esa tarjeta.';
        }

        /* 2. SIN VINCULAR PERO NO VENCIDAS: entran igual, por su vencimiento de
              Tango. Lo que NO generan es cobertura, y eso es lo que el aviso dice:
              no es plata que falte, es cobertura que falta. */
        $sinVincular = 0;
        $impSinVincular = 0.0;

        foreach ($filas as $f) {
            if ($f['PROYECTA'] && empty($f['ID_TARJETA'])) {
                $sinVincular++;
                $impSinVincular += $f['IMPORTE'];
            }
        }

        if ($sinVincular > 0) {
            $avisos[] = $sinVincular . ' factura(s) por ' . self::plata($impSinVincular)
                . ' entran al flujo por su vencimiento de Tango pero NO están vinculadas a una '
                . 'tarjeta, así que no generan cobertura y ningún resumen las puede reemplazar.';
        }

        /* 3. EXCLUIDAS: plata que el tablero deja de mostrar POR UNA DECISION. Se
              avisa siempre, tambien cuando el interruptor las tiene escondidas: una
              exclusion puesta hace tres meses que nadie recuerda es exactamente lo
              que este aviso evita. */
        $excluidas = self::juntar($filas, self::EXCLUIDA);

        if ($excluidas['cuantas'] > 0) {
            $avisos[] = $excluidas['cuantas'] . ' factura(s) por '
                . self::plata($excluidas['importe']) . ' están excluidas de esta pestaña y no '
                . 'suman a la fila del tablero.'
                . (empty($excluidas['motivos']) ? ''
                    : ' Motivos: ' . implode('; ', array_slice($excluidas['motivos'], 0, 3))
                      . (count($excluidas['motivos']) > 3
                        ? '; y ' . (count($excluidas['motivos']) - 3) . ' más.' : '.'))
                . ' Van a su propia serie, que es informativa. NO están excluidas de Cuentas a '
                . 'Pagar Locales: son dos decisiones distintas.';
        }

        /* 4. CUBIERTAS POR UN RESUMEN: no es un problema, es el mecanismo
              funcionando. Se avisa porque explica por que la fila bajó de importe
              sin que ninguna factura desapareciera. */
        $cubiertas = self::juntar($filas, self::CUBIERTA);

        if ($cubiertas['cuantas'] > 0) {
            $avisos[] = $cubiertas['cuantas'] . ' factura(s) por '
                . self::plata($cubiertas['importe']) . ' están cubiertas por el resumen cargado '
                . 'de su tarjeta: ese importe ya las incluye, así que no suman aparte. Se siguen '
                . 'viendo en la grilla para poder controlarlas contra el resumen.';
        }

        /* 5. EL DOBLE CONTEO POSIBLE, y es el aviso mas delicado de la pestana:
              si hay un resumen cargado para un mes y quedan facturas NO VINCULADAS
              que se pagan en ese mes, esas facturas pueden estar incluidas en el
              resumen y contarse dos veces. No se puede resolver por codigo -saber
              si un consumo del resumen corresponde a una factura concreta es
              mirar el resumen- asi que se avisa. */
        $mesesConResumen = [];

        foreach ($resumenes as $id => $porMes) {
            foreach ($porMes as $mes => $r) {
                $mesesConResumen[$mes] = true;
            }
        }

        $riesgo = 0;
        $impRiesgo = 0.0;
        $mesesRiesgo = [];

        foreach ($filas as $f) {
            if (!$f['PROYECTA'] || !empty($f['ID_TARJETA']) || $f['MES_PAGO'] === null) {
                continue;
            }

            if (isset($mesesConResumen[$f['MES_PAGO']])) {
                $riesgo++;
                $impRiesgo += $f['IMPORTE'];
                $mesesRiesgo[$f['MES_PAGO']] = true;
            }
        }

        if ($riesgo > 0) {
            $meses = array_keys($mesesRiesgo);
            sort($meses);

            $avisos[] = 'POSIBLE DOBLE CONTEO: hay ' . $riesgo . ' factura(s) por '
                . self::plata($impRiesgo) . ' sin vincular que se pagan en '
                . implode(', ', $meses) . ', y esos meses ya tienen un resumen cargado. Si esas '
                . 'facturas están incluidas en el resumen, el mismo peso se cuenta dos veces. '
                . 'Vinculalas a la tarjeta del resumen —y el resumen las reemplaza— o excluilas.';
        }

        /* 6. UN VINCULO QUE APUNTA A UNA TARJETA QUE YA NO ESTA. La FK lo impide,
              asi que solo puede pasar si alguien la borro a mano: es una base
              inconsistente y confundirlo con "sin vincular" lo esconderia. */
        $rotos = 0;

        foreach ($filas as $f) {
            if (!empty($f['VINCULO_ROTO'])) {
                $rotos++;
            }
        }

        if ($rotos > 0) {
            $avisos[] = $rotos . ' factura(s) están vinculadas a una tarjeta que ya no existe. '
                . 'Se tratan como no vinculadas. Eso no debería poder pasar —la tabla tiene una '
                . 'FK— así que revisá si alguien borró una tarjeta a mano.';
        }

        return $avisos;
    }

    /**
     * Junta el conteo, el importe y los motivos de las filas de un motivo.
     *
     * @param array $filas
     * @param string $motivo
     * @return array ['cuantas' => int, 'importe' => float, 'motivos' => [...]]
     */
    private static function juntar($filas, $motivo) {
        $cuantas = 0;
        $importe = 0.0;
        $motivos = [];

        foreach ($filas as $f) {
            if ($f['MOTIVO'] !== $motivo) {
                continue;
            }

            $cuantas++;
            $importe += $f['IMPORTE'];

            $m = trim((string) $f['MOTIVO_EXCLUSION_TARJETAS']);

            if ($m !== '' && !in_array($m, $motivos, true)) {
                $motivos[] = $m;
            }
        }

        return ['cuantas' => $cuantas, 'importe' => $importe, 'motivos' => $motivos];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** 'Y-m-d' => 'd/m/Y' */
    private static function corto($fecha) {
        if ($fecha === null || $fecha === '') {
            return '—';
        }

        return substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4);
    }

    /** Un importe con el formato del modulo */
    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }

    /** Un porcentaje con dos decimales */
    private static function pct($n) {
        return ($n === null) ? '—' : number_format($n, 2, ',', '.');
    }
}
