<?php

require_once __DIR__ . '/Fondos.php';

/**
 * CoberturaAutomatica
 * El calculo del uso de cobertura: cuanto se rescata de cada fondo en cada
 * columna para que el saldo proyectado no quede abajo de cero.
 *
 * QUE RESUELVE
 * ------------
 * Hasta ahora el uso de cobertura se cargaba a mano, celda por celda, mirando
 * las columnas en rojo. Eso obligaba a rehacer la carga cada vez que se movia
 * un vencimiento. Ahora lo calcula el motor en cada carga del tablero, AL
 * VUELO y sin persistir nada: cambia un vencimiento y la cobertura se
 * recalcula sola. La carga manual sigue existiendo para pisar una fecha
 * puntual, y tiene precedencia.
 *
 * ES UNA FUNCION PURA, Y ESO NO ES UN DETALLE
 * -------------------------------------------
 * calcular() recibe numeros y devuelve numeros: no lee la base, no conoce el
 * Horizonte ni la estructura. Es la unica forma de probar un algoritmo con
 * tantos casos borde -redondeos, agotamiento, devoluciones- sin depender de lo
 * que haya cargado en las tablas. Quien la llama (Cashflow::resolverUsoCobertura)
 * arma las entradas a partir de lo que los proveedores le dieron. Es el mismo
 * reparto que Fondos::saldoA() y Saldos::armarSaldosLocales().
 *
 * EL ALGORITMO
 * ------------
 * Se recorren las columnas en orden cronologico arrastrando el saldo. En cada
 * una, en este orden:
 *
 *   1. Entra el flujo de la columna (sin ninguna cobertura) y lo cargado A MANO
 *      en esa columna. Lo manual va primero y entero: es una decision de
 *      tesoreria, y el motor solo cubre lo que siga faltando despues de ella.
 *
 *   2. Si el SALDO ACUMULADO queda abajo de cero, se rescata EXACTAMENTE lo que
 *      falta para llevarlo a cero. Ni un peso mas.
 *
 *      OJO: lo que dispara el rescate es el saldo ACUMULADO, no el flujo neto
 *      de la columna. Un dia que gasta mas de lo que entra pero viene con caja
 *      de sobra no necesita cobertura. Rescatar contra el flujo del dia sacaria
 *      plata de una inversion que rinde, sin necesitarla.
 *
 *   3. Si el saldo acumulado tiene SOBRANTE y antes el motor rescato, se
 *      devuelve al fondo (uso negativo) hasta recuperar lo rescatado, sin
 *      pasarse. Asi la plata vuelve a rendir en cuanto no hace falta.
 *
 * EL ORDEN DE CONSUMO
 * -------------------
 * Primero las cuentas de INVERSION, y recien cuando se agotan, las COMITENTE:
 * vender dolares es la ultima opcion. Dentro de cada clase, el orden del
 * catalogo de Saldos (ORDEN, NOMBRE), que es el mismo con el que se listan en
 * la pestana y en los desplegables. Lo fija ordenDeConsumo(), y NO depende de
 * como esten ordenadas las filas del cuadro: reordenarlas desde Parametros
 * cambiaria en silencio que fondo se vende primero, y eso es una decision de
 * negocio, no de presentacion.
 *
 * EL TOPE DE CADA FONDO
 * ---------------------
 * Es su saldo A LA FECHA DE LA COLUMNA -saldo inicial + suscripciones -
 * rescates hasta ahi, previstos incluidos- menos lo que ya se consumio en las
 * columnas anteriores, a mano o por el motor. Un fondo NUNCA queda en
 * negativo por el motor: si lo que queda es cero, se pasa al siguiente. Lo
 * unico que puede dejarlo abajo de cero es una carga manual que lo supere
 * -que Cobertura::guardar() rechaza- o un rescate previsto en Saldos -> Fondos
 * que se coma lo que el motor ya habia usado; en los dos casos se informa en
 * 'sobregirado' y el tope se clava en cero.
 *
 * LOS DOLARES SE VENDEN ENTEROS
 * -----------------------------
 * De un fondo en USD se toman dolares ENTEROS, redondeando hacia ARRIBA lo que
 * falta, valuados a la cotizacion del dia de la aplicacion, punta vendedora,
 * que es como se valua ese saldo (Cotizacion::ultimaHasta). Hacia arriba
 * porque el objetivo es llegar a cero: con un dolar de menos la columna sigue
 * en rojo por unos pesos. La devolucion redondea hacia ABAJO por el mismo
 * motivo: recomprar un dolar de mas dejaria el saldo abajo de cero.
 *
 * Sin cotizacion para ese dia no se vende ni se recompra: no hay con que
 * valuar, y valuar con cualquier cosa pondria en el cuadro pesos que nadie va
 * a recibir.
 *
 * SI NO ALCANZA, NO SE INVENTA PLATA
 * ----------------------------------
 * Con los dos fondos agotados se aplica todo lo que hay y la columna queda
 * en rojo. 'faltante' dice cuanto falta en cada una; el motor lo avisa.
 *
 * LA DEVOLUCION ES LIFO
 * ---------------------
 * Se le devuelve primero al fondo del que se saco ULTIMO. Con el orden de
 * consumo de arriba eso significa recomprar los dolares antes de volver a
 * suscribir a la inversion, que es lo que se quiere. Se lleva una pila de
 * rescates AUTOMATICOS: lo cargado a mano no se devuelve solo -es una decision
 * de alguien, y deshacerla en silencio seria peor que dejarla-; para eso esta
 * el importe manual negativo.
 *
 * NUNCA SE DEVUELVE MAS DE LO QUE SE SACO: eso seria inventar una suscripcion.
 * La pila lo garantiza, y ademas la devolucion se acota a lo que el fondo
 * tiene consumido: si una carga manual negativa ya lo dejo entero, no hay
 * nada que devolverle.
 *
 * LAS COLUMNAS MENSUALES SON UN SOLO PASO
 * ---------------------------------------
 * El eje no tiene resolucion diaria fuera del tramo: una columna mensual
 * acumula los dias de ese mes que quedan fuera del tramo diario, y para el
 * algoritmo es UNA columna. El uso que se calcula ahi es el del mes entero,
 * valuado a la cotizacion del ultimo dia del mes, que es la mas cercana a
 * cuando efectivamente se aplicaria. Un bache que ocurra a mitad de mes y se
 * tape solo antes de fin de mes no se ve, porque el eje no lo ve.
 */
class CoberturaAutomatica {

    /** Tolerancia para comparar pesos: por debajo de medio centavo es cero */
    const EPS = 0.005;

    /**
     * Ordena los fondos como se consumen: primero por clase, en el orden de
     * Fondos::CLASES_FONDO (INVERSION, COMITENTE), y dentro de cada clase por
     * 'orden' -la posicion en el catalogo- y despues por clave, para que el
     * resultado sea estable aunque dos cuentas compartan el orden.
     *
     * Una clase desconocida va al final: no se rescata de algo que no se sabe
     * que es antes que de un fondo conocido.
     *
     * @param array $fondos Lista de fondos con 'clave', 'clase' y 'orden'
     * @return array La misma lista, ordenada
     */
    public static function ordenDeConsumo($fondos) {
        $rango = array_flip(Fondos::CLASES_FONDO);
        $lista = array_values($fondos);

        usort($lista, function ($a, $b) use ($rango) {
            $ca = isset($rango[$a['clase']]) ? $rango[$a['clase']] : count($rango);
            $cb = isset($rango[$b['clase']]) ? $rango[$b['clase']] : count($rango);

            if ($ca !== $cb) {
                return $ca - $cb;
            }

            $oa = isset($a['orden']) ? intval($a['orden']) : 0;
            $ob = isset($b['orden']) ? intval($b['orden']) : 0;

            if ($oa !== $ob) {
                return $oa - $ob;
            }

            return strcmp((string) $a['clave'], (string) $b['clave']);
        });

        return $lista;
    }

    /**
     * Calcula el uso automatico de cobertura sobre un horizonte.
     *
     * @param array $columnas Ids de columna en orden cronologico
     *        (Horizonte::secuencia()).
     * @param array $flujo Mapa col => pesos. El flujo de cada columna SIN
     *        ninguna cobertura: aporte de saldo mas movimientos que computan.
     * @param array $fondos Lista YA ORDENADA como se consumen (ordenDeConsumo()).
     *        Cada uno:
     *          'clave'   string
     *          'moneda'  'ARS' | 'USD'
     *          'tope'    [col => float]  saldo del fondo a la fecha de esa
     *                    columna, en su moneda, ANTES de descontar aplicaciones
     *          'tc'      [col => float|null]  cotizacion vendedora del dia de
     *                    la columna; solo se mira en USD. null = no se puede
     *                    valuar ese dia
     *          'manual'  [col => float]  lo cargado a mano desde ese fondo en
     *                    esa columna, en su moneda. Puede ser negativo
     * @param array $manual Mapa col => pesos. TODO lo cargado a mano en la
     *        columna, ya en pesos, incluidas las aplicaciones de fondos que no
     *        estan en $fondos (una clave vieja, por ejemplo): entran al saldo
     *        aunque no descuenten de nadie.
     * @return array
     *   'saldo'       [col => pesos]  saldo acumulado al cierre de la columna,
     *                 con toda la cobertura aplicada
     *   'automatico'  [col => [clave => ['importe' => moneda, 'ars' => pesos]]]
     *                 solo las celdas distintas de cero; negativo = devolucion
     *   'faltante'    [col => pesos]  solo las columnas que quedan abajo de cero
     *                 con todo aplicado
     *   'consumido'   [clave => moneda]  lo que cada fondo tiene aplicado al
     *                 final del horizonte, manual + automatico, neto
     *   'sobregirado' [clave => [col => moneda]]  donde lo aplicado supera el
     *                 tope del fondo a esa fecha; el motor lo clava en cero y
     *                 lo informa
     */
    public static function calcular($columnas, $flujo, $fondos, $manual) {
        $fondos = array_values($fondos);
        $porClave = [];

        foreach ($fondos as $i => $f) {
            $porClave[$f['clave']] = $i;
        }

        $saldo = 0.0;
        $consumido = [];
        $pila = [];

        $out = ['saldo' => [], 'automatico' => [], 'faltante' => [],
                'consumido' => [], 'sobregirado' => []];

        foreach ($fondos as $f) {
            $consumido[$f['clave']] = 0.0;
        }

        foreach ($columnas as $col) {
            /* ---- 1. El flujo y lo manual ---------------------------------- */
            $saldo += isset($flujo[$col]) ? floatval($flujo[$col]) : 0.0;
            $saldo += isset($manual[$col]) ? floatval($manual[$col]) : 0.0;

            foreach ($fondos as $f) {
                if (isset($f['manual'][$col])) {
                    $consumido[$f['clave']] += floatval($f['manual'][$col]);
                }

                // Lo manual puede dejar el fondo abajo de cero (una carga
                // vieja, un rescate previsto). Se informa; el tope para el
                // motor queda en cero, nunca en negativo.
                $exceso = $consumido[$f['clave']] - self::tope($f, $col);

                if ($exceso > self::EPS) {
                    $out['sobregirado'][$f['clave']][$col] = round($exceso, 2);
                }
            }

            /* ---- 2. Falta plata: se rescata lo justo ---------------------- */
            if ($saldo < -self::EPS) {
                $falta = -$saldo;

                foreach ($fondos as $f) {
                    $clave = $f['clave'];
                    $disponible = self::tope($f, $col) - $consumido[$clave];

                    if ($disponible <= self::EPS) {
                        continue;
                    }

                    $toma = self::rescatar($f, $col, $falta, $disponible);

                    if ($toma === null) {
                        continue;
                    }

                    $saldo += $toma['ars'];
                    $falta -= $toma['ars'];
                    $consumido[$clave] += $toma['importe'];

                    self::sumarCelda($out['automatico'], $col, $clave, $toma['importe'], $toma['ars']);
                    self::apilar($pila, $clave, $toma['importe']);

                    if ($falta <= self::EPS) {
                        break;
                    }
                }

                if ($saldo < -self::EPS) {
                    $out['faltante'][$col] = round(-$saldo, 2);
                }
            }

            /* ---- 3. Sobra plata: se devuelve, LIFO ------------------------ */
            elseif ($saldo > self::EPS && !empty($pila)) {
                while ($saldo > self::EPS && !empty($pila)) {
                    $tope = $pila[count($pila) - 1];
                    $f = $fondos[$porClave[$tope['clave']]];

                    // No se le devuelve a un fondo que ya esta entero: una
                    // carga manual negativa pudo haberlo repuesto antes.
                    $cabe = min($tope['importe'], max(0.0, $consumido[$tope['clave']]));
                    $vuelve = self::devolver($f, $col, $saldo, $cabe);

                    if ($vuelve === null) {
                        break;
                    }

                    $saldo -= $vuelve['ars'];
                    $consumido[$tope['clave']] -= $vuelve['importe'];

                    self::sumarCelda($out['automatico'], $col, $tope['clave'],
                        -$vuelve['importe'], -$vuelve['ars']);

                    $pila[count($pila) - 1]['importe'] -= $vuelve['importe'];

                    if ($pila[count($pila) - 1]['importe'] <= self::EPS) {
                        array_pop($pila);
                    }
                }
            }

            $out['saldo'][$col] = round($saldo, 2);
        }

        foreach ($consumido as $clave => $v) {
            $out['consumido'][$clave] = round($v, 2);
        }

        return $out;
    }

    /* ====================================================================
       AYUDANTES
       ==================================================================== */

    /** El tope de un fondo en una columna; sin dato, cero: no se rescata de lo que no se sabe */
    private static function tope($f, $col) {
        return isset($f['tope'][$col]) ? floatval($f['tope'][$col]) : 0.0;
    }

    /** La cotizacion de un fondo en una columna, o null si no hay con que valuar */
    private static function tc($f, $col) {
        if (!isset($f['tc'][$col]) || floatval($f['tc'][$col]) <= 0) {
            return null;
        }

        return floatval($f['tc'][$col]);
    }

    /**
     * Cuanto se rescata de un fondo para cubrir $falta pesos, con $disponible
     * en su moneda. Devuelve ['importe' => moneda, 'ars' => pesos] o null si no
     * se puede sacar nada.
     *
     * En pesos es la cuenta directa. En dolares se venden ENTEROS, hacia
     * arriba, y como mucho los enteros que haya: con USD 66.000,50 se pueden
     * vender 66.000, y los 50 centavos quedan.
     */
    private static function rescatar($f, $col, $falta, $disponible) {
        if ($f['moneda'] !== 'USD') {
            $ars = round(min($falta, $disponible), 2);

            return ($ars <= 0) ? null : ['importe' => $ars, 'ars' => $ars];
        }

        $tc = self::tc($f, $col);

        if ($tc === null) {
            return null;
        }

        // El 1e-9 evita que un cociente exacto suba un dolar por error de
        // punto flotante: 3000 / 1500 tiene que dar 2, no 3.
        $necesarios = (int) ceil($falta / $tc - 1e-9);
        $enteros = (int) floor($disponible + 1e-9);
        $usd = min($necesarios, $enteros);

        if ($usd <= 0) {
            return null;
        }

        return ['importe' => $usd, 'ars' => round($usd * $tc, 2)];
    }

    /**
     * Cuanto se le devuelve a un fondo con $sobrante pesos, sin pasar de $cabe
     * en su moneda. En dolares, enteros hacia ABAJO: recomprar uno de mas
     * dejaria el saldo abajo de cero.
     */
    private static function devolver($f, $col, $sobrante, $cabe) {
        if ($cabe <= self::EPS) {
            return null;
        }

        if ($f['moneda'] !== 'USD') {
            $ars = round(min($sobrante, $cabe), 2);

            return ($ars <= self::EPS) ? null : ['importe' => $ars, 'ars' => $ars];
        }

        $tc = self::tc($f, $col);

        if ($tc === null) {
            return null;
        }

        $usd = min((int) floor($sobrante / $tc + 1e-9), (int) floor($cabe + 1e-9));

        if ($usd <= 0) {
            return null;
        }

        return ['importe' => $usd, 'ars' => round($usd * $tc, 2)];
    }

    /** Acumula en la celda (col, clave), creandola si no esta */
    private static function sumarCelda(&$celdas, $col, $clave, $importe, $ars) {
        if (!isset($celdas[$col][$clave])) {
            $celdas[$col][$clave] = ['importe' => 0.0, 'ars' => 0.0];
        }

        $celdas[$col][$clave]['importe'] = round($celdas[$col][$clave]['importe'] + $importe, 2);
        $celdas[$col][$clave]['ars'] = round($celdas[$col][$clave]['ars'] + $ars, 2);
    }

    /**
     * Apila un rescate. Dos rescates seguidos del mismo fondo se juntan en una
     * entrada: la pila dice de que fondo se saco ultimo, no cuantas veces.
     */
    private static function apilar(&$pila, $clave, $importe) {
        $n = count($pila);

        if ($n > 0 && $pila[$n - 1]['clave'] === $clave) {
            $pila[$n - 1]['importe'] += $importe;

            return;
        }

        $pila[] = ['clave' => $clave, 'importe' => $importe];
    }
}
