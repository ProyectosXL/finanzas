<?php

require_once __DIR__ . '/Parametros.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * MixCobro
 * El mix de cobro de las ventas proyectadas: un ARBOL de nodos por canal, y la
 * regla que lo resuelve hasta las hojas.
 *
 * EL ARBOL
 * --------
 *   Locales    : Medio de pago > Tipo de tarjeta > Procesadora > Cuotas
 *   Ecommerce  : Marketplace > Medio de pago > Tipo de tarjeta > ...
 *   Franquicias y Mayoristas: hoy, un solo nivel.
 *
 * Cada nodo carga SOLO LO SUYO: su porcentaje entre sus hermanos, su costo, su
 * tasa y sus dias de acreditacion. Lo que necesita el motor -el porcentaje de
 * la venta del canal que cobra cada hoja, cuanto cuesta cobrarlo y en cuantos
 * dias se acredita- se calcula al leer. Guardarlo resuelto lo dejaria viejo en
 * cuanto alguien tocara un nodo de arriba.
 *
 * LA REGLA (resolver())
 * ---------------------
 *   Activo y hoja   Un nodo inactivo saca de juego TODO su subarbol. Una hoja es
 *                   un nodo en juego sin hijos en juego: inhabilitar "3 cuotas" y
 *                   "Resto" convierte a Fiserv en hoja sin borrar nada.
 *   % efectivo      El producto de los porcentajes de la cadena:
 *                   Tarjeta 90% x Credito 80% x Fiserv 60% x 3 cuotas 30% = 12,96%
 *   Costo y tasa    SE SUMAN a lo largo de la rama, con null como cero. Cada
 *                   nivel carga lo suyo -la comision del marketplace, la de la
 *                   procesadora, la tasa de las cuotas- y la hoja paga todo.
 *   Dias            GANA EL NIVEL MAS CERCANO que los tenga. No se suman: el
 *                   plazo lo pone quien acredita, y "3 cuotas" a 1 dia debajo de
 *                   un Fiserv a 7 dias se acredita a 1 dia, no a 8.
 *
 * Y lo que la regla exige para que la venta del canal llegue entera a la caja:
 * los hermanos en juego de cada grupo suman 100%, toda hoja tiene dias en algun
 * nivel de su rama, el costo + tasa acumulado de una hoja es menor a 100%, y
 * el canal tiene al menos una hoja.
 *
 * UNA SOLA REGLA PARA TODOS
 * -------------------------
 * La resuelven ACA el motor de Ventas, la pestana Ventas, Parametros (que la
 * pide al servidor mientras se edita, con previsualizarMixArbol) y las pruebas.
 * Si cada uno la calculara por su lado, algun dia la pantalla diria que Resto
 * hereda 7 dias y el tablero lo acreditaria a otros. Por eso no hay una copia
 * en JS.
 *
 * LOS TIPOS DE NIVEL SON UNA LISTA DEL CODIGO, no de la base: NIVELES. Su orden
 * es el de la jerarquia. LA LISTA Y EL CHECK CK_RO_T_CASHFLOW_VENTAS_MIX_NODO_NIVEL
 * de sql/cashflow_ventas_mix_nodo.sql CAMBIAN JUNTAS.
 *
 * SIN EL SCRIPT
 * -------------
 * Mientras no se corre sql/cashflow_ventas_mix_nodo.sql, el mix sale de la
 * tabla plana RO_T_CASHFLOW_VENTAS_MIX, convertida por desdeMixPlano() en un
 * arbol de un nivel. Asi el motor tiene UN solo camino -siempre recorre hojas-
 * y el mix viejo da exactamente la cobranza de siempre: bruta y sin costos.
 *
 * LAS CLAVES: 'N<id>' para un nodo de la tabla nueva y 'M<id>' para una fila
 * del mix plano. Es el ID y no el camino de nombres porque renombrar un nodo
 * no tiene que cambiar la clave de su fila.
 */
class MixCobro {

    /**
     * Los tipos de nivel, en el orden de la jerarquia: un hijo siempre tiene
     * un nivel POSTERIOR al de su padre, y se pueden saltear niveles.
     */
    const NIVELES = [
        'MARKETPLACE' => 'Marketplace',
        'MEDIO_PAGO' => 'Medio de pago',
        'TIPO_TARJETA' => 'Tipo de tarjeta',
        'PROCESADORA' => 'Procesadora',
        'CUOTAS' => 'Cuotas'
    ];

    /** El unico canal con marketplaces: en el, todo cuelga de uno */
    const CANAL_MARKETPLACE = 'ECOMMERCE';

    /** Primer nivel de los demas canales */
    const PRIMER_NIVEL = 'MEDIO_PAGO';

    const TABLA = 'RO_T_CASHFLOW_VENTAS_MIX_NODO';

    /** Largo de NOMBRE en la tabla */
    const LARGO_NOMBRE = 50;

    /** La tolerancia con la que el mix plano validaba el 100%, que es la misma */
    const TOLERANCIA = 0.000001;

    /** Separador del camino de nombres en pantalla y en los avisos */
    const SEPARADOR = ' › ';

    /* ---- El mix por mes (sql/cashflow_ventas_mix_mes.sql) ----------------- */

    const TABLA_CANAL = 'RO_T_CASHFLOW_VENTAS_MIX_CANAL';
    const TABLA_MES = 'RO_T_CASHFLOW_VENTAS_MIX_MES';
    const TABLA_NODO_MES = 'RO_T_CASHFLOW_VENTAS_MIX_NODO_MES';

    /** Un canal sin fila en TABLA_CANAL es CONSTANTE */
    const MODOS = ['CONSTANTE', 'VARIABLE'];

    /**
     * Lo que un mes puede cambiar de un nodo. La estructura -padre, nivel,
     * nombre, orden- es del constante y no varia por mes.
     */
    const CAMPOS_MES = ['PORCENTAJE', 'COSTO', 'TASA', 'DIAS_ACREDITACION', 'ACTIVO'];

    const NOMBRES_MES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
                         'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /* ====================================================================
       NORMALIZACION
       ==================================================================== */

    /**
     * Las filas de la tabla nueva, con los tipos que espera el resto de la
     * clase y su clave. Pura.
     *
     * NULL SE CONSERVA en costo, tasa y dias: es lo que distingue "este nivel
     * no agrega costo" y "lo define un nivel superior" de un cero cargado.
     *
     * @param array $filas Filas de RO_T_CASHFLOW_VENTAS_MIX_NODO
     * @return array Nodos normalizados, en el orden recibido
     */
    public static function normalizar($filas) {
        $v = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $v[] = self::nodo($f, 'N');
        }

        return $v;
    }

    /**
     * El mix plano como un arbol de un nivel. Pura.
     *
     * Es el respaldo mientras no se corre el script: cada fila es un nodo de
     * MEDIO_PAGO en el primer nivel de su canal, con su porcentaje, sus dias y
     * su estado, y sin costo ni tasa. Asi el motor recorre siempre hojas y el
     * mix viejo da exactamente la cobranza de antes.
     *
     * El nombre queda como esta guardado (CASH, TARJETA): renombrar es parte de
     * la migracion, no de leer el mix viejo.
     *
     * @param array $mix Filas de Parametros::getMixCobro()
     * @return array Nodos normalizados
     */
    public static function desdeMixPlano($mix) {
        $v = [];

        foreach ((is_array($mix) ? $mix : []) as $f) {
            $f['ID_PADRE'] = null;
            $f['NIVEL'] = self::PRIMER_NIVEL;
            $f['NOMBRE'] = isset($f['MEDIO_PAGO']) ? $f['MEDIO_PAGO'] : '';
            $f['COSTO'] = null;
            $f['TASA'] = null;
            $v[] = self::nodo($f, 'M');
        }

        return $v;
    }

    /** Un nodo con sus tipos. $prefijo arma la clave: 'N' o 'M' */
    private static function nodo($f, $prefijo) {
        $id = intval($f['ID']);

        return [
            'ID' => $id,
            'clave' => $prefijo . $id,
            'CANAL' => strtoupper(trim((string) $f['CANAL'])),
            'ID_PADRE' => (isset($f['ID_PADRE']) && $f['ID_PADRE'] !== null && $f['ID_PADRE'] !== '')
                ? intval($f['ID_PADRE']) : null,
            'NIVEL' => strtoupper(trim((string) $f['NIVEL'])),
            'NOMBRE' => trim((string) $f['NOMBRE']),
            'PORCENTAJE' => floatval($f['PORCENTAJE']),
            'COSTO' => self::numeroONull(isset($f['COSTO']) ? $f['COSTO'] : null),
            'TASA' => self::numeroONull(isset($f['TASA']) ? $f['TASA'] : null),
            'DIAS_ACREDITACION' => (isset($f['DIAS_ACREDITACION']) && $f['DIAS_ACREDITACION'] !== null
                    && $f['DIAS_ACREDITACION'] !== '')
                ? intval($f['DIAS_ACREDITACION']) : null,
            'ACTIVO' => intval($f['ACTIVO']) === 1 ? 1 : 0,
            'ORDEN' => isset($f['ORDEN']) ? intval($f['ORDEN']) : 0,
            'USUARIO_MODIF' => isset($f['USUARIO_MODIF']) ? $f['USUARIO_MODIF'] : null,
            'FECHA_MODIF' => isset($f['FECHA_MODIF']) ? $f['FECHA_MODIF'] : null
        ];
    }

    private static function numeroONull($v) {
        return ($v === null || $v === '') ? null : floatval($v);
    }

    /** Posicion de un nivel en la jerarquia, o null si no es un nivel */
    public static function posicionNivel($nivel) {
        $pos = array_search($nivel, array_keys(self::NIVELES), true);

        return $pos === false ? null : $pos;
    }

    /** El rotulo de un nivel, o el codigo si no esta en la lista */
    public static function rotuloNivel($nivel) {
        return isset(self::NIVELES[$nivel]) ? self::NIVELES[$nivel] : (string) $nivel;
    }

    /**
     * Los tipos de nivel como viajan a la pantalla: [['codigo', 'rotulo']],
     * en el orden de la jerarquia.
     *
     * Una lista y no el mapa: la leyenda de colores (Js/mix-niveles.js)
     * muestra los tipos en el orden de NIVELES, y una lista lo lleva escrito.
     * Parametros y Ventas la reciben de aca, con la misma forma, asi ninguna
     * pantalla escribe los tipos, su orden ni sus rotulos a mano.
     */
    public static function nivelesLista() {
        $niveles = [];

        foreach (self::NIVELES as $codigo => $rotulo) {
            $niveles[] = ['codigo' => $codigo, 'rotulo' => $rotulo];
        }

        return $niveles;
    }

    /** LOCALES -> Locales */
    public static function nombreCanal($canal) {
        return ucfirst(strtolower((string) $canal));
    }

    /**
     * Los niveles que admite un hijo de ese padre, en ese canal. Pura.
     *
     * Es lo que ofrece el formulario de Agregar y lo mismo que despues exige
     * validarEstructura(): primer nivel de Ecommerce, solo MARKETPLACE; primer
     * nivel de los demas, MEDIO_PAGO; debajo de un nodo, cualquier nivel
     * posterior al suyo, y nunca MARKETPLACE fuera de Ecommerce.
     *
     * @param string $canal
     * @param string|null $nivelPadre null para el primer nivel
     * @return array Lista de codigos de nivel
     */
    public static function nivelesPermitidos($canal, $nivelPadre) {
        if ($nivelPadre === null) {
            return [$canal === self::CANAL_MARKETPLACE ? 'MARKETPLACE' : self::PRIMER_NIVEL];
        }

        $desde = self::posicionNivel($nivelPadre);

        if ($desde === null) {
            return [];
        }

        $v = [];

        foreach (array_keys(self::NIVELES) as $pos => $nivel) {
            if ($pos > $desde && $nivel !== 'MARKETPLACE') {
                $v[] = $nivel;
            }
        }

        return $v;
    }

    /**
     * Como se comparan dos nombres de hermanos: sin mayusculas, sin acentos y
     * sin espacios de mas, igual que la collation Modern_Spanish_CI_AI de la
     * columna. Si el PHP comparara distinto que la base, un nombre que el
     * servidor dejo pasar reventaria en el indice unico.
     */
    public static function nombreComparable($nombre) {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $nombre)), 'UTF-8');

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
                          'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }

    /* ====================================================================
       ESTRUCTURA
       ==================================================================== */

    /**
     * Lo que tiene que cumplir el arbol para poder guardarse, mirando la
     * forma y los rangos. Pura.
     *
     * VALE PARA TODOS LOS NODOS, en juego o no: un nodo inactivo con un nivel
     * imposible se activa con un switch, y el error tiene que aparecer antes.
     * Lo que depende de estar en juego -las sumas, los dias de las hojas- lo
     * dice resolver().
     *
     * @param array $nodos Nodos normalizados (de uno o de todos los canales)
     * @return array Lista de errores ['clave', 'canal', 'texto']
     */
    public static function validarEstructura($nodos) {
        $errores = [];
        $porId = [];

        foreach ($nodos as $n) {
            $porId[$n['ID']] = $n;
        }

        $vistos = [];

        foreach ($nodos as $n) {
            $err = function ($texto) use (&$errores, $n) {
                $errores[] = ['clave' => $n['clave'], 'canal' => $n['CANAL'], 'texto' => $texto];
            };
            $quien = '"' . $n['NOMBRE'] . '" (' . self::nombreCanal($n['CANAL']) . ')';

            if (!in_array($n['CANAL'], Parametros::CANALES, true)) {
                $err('El nodo ' . $quien . ' es de un canal que no existe: ' . $n['CANAL'] . '.');
                continue;
            }

            if ($n['NOMBRE'] === '') {
                $err('Hay un nodo sin nombre en ' . self::nombreCanal($n['CANAL']) . '.');
            } elseif (mb_strlen($n['NOMBRE'], 'UTF-8') > self::LARGO_NOMBRE) {
                $err('El nombre ' . $quien . ' supera los ' . self::LARGO_NOMBRE . ' caracteres.');
            }

            if (self::posicionNivel($n['NIVEL']) === null) {
                $err('El nodo ' . $quien . ' tiene un nivel desconocido: ' . $n['NIVEL'] . '.');
                continue;
            }

            self::validarRangos($n, $err, $quien);

            // ---- El lugar en el arbol ---------------------------------------
            $padre = null;

            if ($n['ID_PADRE'] !== null) {
                if (!isset($porId[$n['ID_PADRE']])) {
                    $err('El nodo ' . $quien . ' cuelga de un nodo que no existe.');
                    continue;
                }

                $padre = $porId[$n['ID_PADRE']];

                if ($padre['CANAL'] !== $n['CANAL']) {
                    $err('El nodo ' . $quien . ' cuelga de un nodo de otro canal.');
                    continue;
                }

                if (self::enCiclo($n, $porId)) {
                    $err('El nodo ' . $quien . ' es ancestro de sí mismo.');
                    continue;
                }
            }

            $permitidos = self::nivelesPermitidos($n['CANAL'], $padre === null ? null : $padre['NIVEL']);

            if (!in_array($n['NIVEL'], $permitidos, true)) {
                $err(self::porQueNoVaElNivel($n, $padre, $quien));
            }

            // ---- El nombre, unico entre hermanos ----------------------------
            $clave = $n['CANAL'] . '|' . ($n['ID_PADRE'] === null ? '' : $n['ID_PADRE']) . '|'
                   . self::nombreComparable($n['NOMBRE']);

            if (isset($vistos[$clave])) {
                $err('Hay dos nodos que se llaman "' . $n['NOMBRE'] . '" bajo '
                    . ($padre === null ? self::nombreCanal($n['CANAL']) : '"' . $padre['NOMBRE'] . '"')
                    . '. El mismo nombre puede repetirse en otra rama, no entre hermanos.');
            }

            $vistos[$clave] = true;
        }

        return $errores;
    }

    /** El mensaje de un nivel que no corresponde, diciendo cual si va */
    private static function porQueNoVaElNivel($n, $padre, $quien) {
        $rotulo = self::rotuloNivel($n['NIVEL']);

        if ($n['NIVEL'] === 'MARKETPLACE' && $n['CANAL'] !== self::CANAL_MARKETPLACE) {
            return 'El nodo ' . $quien . ' es un marketplace, y los marketplaces sólo existen en Ecommerce.';
        }

        if ($padre === null) {
            return $n['CANAL'] === self::CANAL_MARKETPLACE
                ? 'En Ecommerce todo cuelga de un marketplace: ' . $quien . ' es ' . $rotulo
                    . ' y está en el primer nivel.'
                : 'El primer nivel de ' . self::nombreCanal($n['CANAL']) . ' es Medio de pago: '
                    . $quien . ' es ' . $rotulo . '.';
        }

        return 'El nodo ' . $quien . ' es ' . $rotulo . ' y cuelga de "' . $padre['NOMBRE']
            . '", que es ' . self::rotuloNivel($padre['NIVEL']) . ': el nivel de un hijo tiene '
            . 'que ser posterior al de su padre.';
    }

    /** Porcentaje, costo y tasa entre 0 y 100%; dias enteros y no negativos */
    private static function validarRangos($n, $err, $quien) {
        if ($n['PORCENTAJE'] < 0 || $n['PORCENTAJE'] > 1 + self::TOLERANCIA) {
            $err('El porcentaje de ' . $quien . ' tiene que estar entre 0% y 100%.');
        }

        foreach (['COSTO' => 'costo', 'TASA' => 'tasa'] as $campo => $texto) {
            if ($n[$campo] !== null && ($n[$campo] < 0 || $n[$campo] > 1 + self::TOLERANCIA)) {
                $err('El ' . $texto . ' de ' . $quien . ' tiene que estar entre 0% y 100%.');
            }
        }

        if ($n['DIAS_ACREDITACION'] !== null && $n['DIAS_ACREDITACION'] < 0) {
            $err('Los días de acreditación de ' . $quien . ' no pueden ser negativos.');
        }
    }

    /** Si subiendo desde el nodo se vuelve a el */
    private static function enCiclo($n, $porId) {
        $visto = [$n['ID'] => true];
        $actual = $n;

        while ($actual['ID_PADRE'] !== null && isset($porId[$actual['ID_PADRE']])) {
            $actual = $porId[$actual['ID_PADRE']];

            if (isset($visto[$actual['ID']])) {
                return true;
            }

            $visto[$actual['ID']] = true;
        }

        return false;
    }

    /* ====================================================================
       LA REGLA
       ==================================================================== */

    /**
     * Resuelve el arbol. Pura.
     *
     * Devuelve TODOS los nodos, en juego o no, en el orden en que se dibujan
     * (por canal, y dentro de cada padre por ORDEN y despues por ID), cada uno
     * con lo que resuelve la regla para el. Los inactivos tambien: Parametros
     * muestra que heredaria un nodo apagado antes de encenderlo.
     *
     * Por nodo:
     *   camino               [['clave','nombre','nivel','rotulo'], ...] desde
     *                        el primer nivel hasta el nodo
     *   profundidad          0 en el primer nivel
     *   en_juego             el nodo y todos sus ancestros activos
     *   hoja                 en juego y sin hijos en juego
     *   porcentaje_efectivo  producto de la cadena: que parte de la venta del
     *                        canal cobra. En un nodo con hijos, la de su rama.
     *   costo, tasa          ACUMULADOS en la rama, null como cero
     *   carga                costo + tasa
     *   carga_ponderada      en un nodo con hijos en juego, el promedio de la
     *                        carga de sus hojas ponderado por su % efectivo: lo
     *                        que cuesta cobrar la rama entera. En una hoja, su
     *                        carga. null si la rama no tiene hojas con %.
     *   dias, dias_de        los del nivel mas cercano que los tenga, y la clave
     *                        y el nombre de ese nivel; null si ninguno
     *   hijos                claves de los hijos, en orden
     *
     * Por canal: sus hojas, sus grupos de hermanos y sus errores. Los errores
     * son lo que impide que la venta del canal llegue entera a la caja:
     *   SUMA      un grupo de hermanos en juego que no suma 100%
     *   SIN_DIAS  una hoja sin dias en ningun nivel de su rama
     *   CARGA     una hoja cuyo costo + tasa acumulado llega a 100%
     *   SIN_HOJAS un canal sin ninguna hoja
     * Cada uno trae la fraccion de la venta del canal afectada ('afuera'),
     * para que el motor pueda decir cuanta plata queda fuera.
     *
     * UN GRUPO FUERA DE JUEGO NO SE VALIDA. Los hijos de un nodo apagado no
     * cobran nada, sumen lo que sumen; exigirles el 100% impediria apagar una
     * rama sin rehacerla. Se informa la suma igual, marcado.
     *
     * EL MIX DE UN MES. Con $mes y $config, cada canal en VARIABLE con mix
     * propio para ese mes se resuelve con los valores del mes puestos sobre
     * los nodos del constante (nodosDelMes()), y despues con EL MISMO
     * algoritmo. Sin $mes, exactamente lo de siempre: es lo que hace que con
     * todos los canales en constante la proyeccion no cambie ni un centavo.
     * Con $mes, cada canal dice ademas si uso su mix propio ('mes_propio') y
     * cada error lleva el mes.
     *
     * @param array $nodos Nodos normalizados de todos los canales
     * @param int|null $mes 1 a 12: el mes de la VENTA
     * @param array|null $config La de getConfig() / armarConfig()
     * @return array ['nodos' => [clave => nodo resuelto] en orden de dibujo,
     *               'canales' => [CANAL => ['hojas' => [claves], 'grupos' => [...],
     *               'errores' => [...], 'valido' => bool]], 'errores' => [...],
     *               'valido' => bool]
     */
    public static function resolver($nodos, $mes = null, $config = null) {
        if ($mes === null || $config === null) {
            return self::resolverArbol($nodos);
        }

        $delMes = self::nodosDelMes($nodos, $config, $mes);
        $r = self::resolverArbol($delMes['nodos']);
        $r['mes'] = intval($mes);
        $r['errores'] = [];

        foreach (Parametros::CANALES as $canal) {
            $c = $r['canales'][$canal];
            $c['mes_propio'] = self::mesPropio($config, $canal, $mes);

            // Un nodo sin valores en un mes cargado -una edicion directa en
            // la base- se proyecta con los del constante, y se dice: el mes
            // no es del todo lo que alguien guardo.
            foreach ($delMes['faltan'] as $n) {
                if ($n['CANAL'] === $canal && isset($r['nodos'][$n['clave']])) {
                    $rama = self::textoCamino($canal, $r['nodos'][$n['clave']]['camino']);
                    $c['errores'][] = [
                        'tipo' => 'SIN_VALOR_MES', 'canal' => $canal, 'clave' => $n['clave'],
                        'rama' => $rama, 'afuera' => 0.0,
                        'texto' => $rama . ' no tiene valores cargados para ' . self::nombreMes($mes)
                            . ': se usan los del mix constante.'
                    ];
                }
            }

            foreach ($c['errores'] as $i => $e) {
                $c['errores'][$i]['mes'] = intval($mes);
            }

            $c['valido'] = count($c['errores']) === 0;
            $r['canales'][$canal] = $c;
            $r['errores'] = array_merge($r['errores'], $c['errores']);
        }

        $r['valido'] = count($r['errores']) === 0;

        return $r;
    }

    /**
     * Resuelve un arbol por cada mes del anio pedido. Pura.
     *
     * Un mes en el que ningun canal tiene mix propio comparte el MISMO
     * resultado que el constante: no se resuelve de nuevo, y con todo en
     * constante hay un solo arbol para todos los meses.
     *
     * @param array $nodos
     * @param array $config
     * @param array $meses Meses del anio, 1 a 12
     * @return array [mes => resultado de resolver()]
     */
    public static function resolverPorMes($nodos, $config, $meses) {
        $base = null;
        $r = [];

        foreach (array_unique(array_map('intval', $meses)) as $mes) {
            $propio = false;

            foreach (Parametros::CANALES as $canal) {
                $propio = $propio || self::mesPropio($config, $canal, $mes);
            }

            if ($propio) {
                $r[$mes] = self::resolver($nodos, $mes, $config);
            } else {
                $r[$mes] = $base !== null ? $base : ($base = self::resolverArbol($nodos));
            }
        }

        return $r;
    }

    /** El algoritmo de resolver(), sobre nodos ya puestos en su mes */
    private static function resolverArbol($nodos) {
        $porId = [];
        $hijosDe = [];

        foreach ($nodos as $n) {
            $porId[$n['ID']] = $n;
        }

        foreach ($nodos as $n) {
            // Un padre que no existe o un ciclo es un error de estructura: el
            // nodo se dibuja colgado de su canal para no perderlo de vista.
            $padre = ($n['ID_PADRE'] !== null && isset($porId[$n['ID_PADRE']])
                      && !self::enCiclo($n, $porId))
                ? $n['ID_PADRE'] : null;
            $hijosDe[$n['CANAL'] . '|' . ($padre === null ? '' : $padre)][] = $n;
        }

        foreach ($hijosDe as $k => $lista) {
            usort($lista, function ($a, $b) {
                return ($a['ORDEN'] - $b['ORDEN']) ?: ($a['ID'] - $b['ID']);
            });
            $hijosDe[$k] = $lista;
        }

        $r = ['nodos' => [], 'canales' => [], 'errores' => [], 'valido' => true];

        foreach (Parametros::CANALES as $canal) {
            $c = ['hojas' => [], 'grupos' => [], 'errores' => [], 'valido' => true];
            $ctx = ['canal' => $canal, 'camino' => [], 'en_juego' => true, 'porc' => 1.0,
                    'costo' => 0.0, 'tasa' => 0.0, 'dias' => null, 'dias_de' => null,
                    'clave_padre' => null, 'nombre_padre' => self::nombreCanal($canal)];

            self::bajar($canal, null, $ctx, $hijosDe, $r['nodos'], $c);

            if (count($c['hojas']) === 0) {
                $c['errores'][] = [
                    'tipo' => 'SIN_HOJAS', 'canal' => $canal, 'clave' => null,
                    'rama' => self::nombreCanal($canal), 'afuera' => 1.0,
                    'texto' => 'El canal ' . self::nombreCanal($canal) . ' no tiene ningún medio de '
                        . 'cobro en juego: su venta no se convierte en cobranza.'
                ];
            }

            $c['valido'] = count($c['errores']) === 0;
            $r['canales'][$canal] = $c;
            $r['errores'] = array_merge($r['errores'], $c['errores']);
        }

        // Los nodos de un canal que no esta en el modelo no se resuelven:
        // validarEstructura() ya lo dice.
        $r['valido'] = count($r['errores']) === 0;

        return $r;
    }

    /**
     * Recorre los hijos de un padre (null = el canal) y devuelve la suma de
     * % efectivo por carga de las hojas de abajo, para la carga ponderada.
     */
    private static function bajar($canal, $idPadre, $ctx, $hijosDe, &$salida, &$c) {
        $clave = $canal . '|' . ($idPadre === null ? '' : $idPadre);
        $hijos = isset($hijosDe[$clave]) ? $hijosDe[$clave] : [];
        $acum = ['peso' => 0.0, 'carga' => 0.0];

        // ---- El grupo de hermanos ------------------------------------------
        $suma = 0.0;
        $activos = 0;

        foreach ($hijos as $h) {
            if ($h['ACTIVO'] === 1) {
                $suma += $h['PORCENTAJE'];
                $activos++;
            }
        }

        // Un grupo sin hijos activos no es un grupo invalido: su padre es una
        // hoja. En el primer nivel, eso es un canal sin hojas, y lo dice el
        // llamador.
        if (count($hijos) > 0) {
            $rama = self::textoCamino($canal, $ctx['camino']);
            $valido = !$ctx['en_juego'] || $activos === 0 || abs($suma - 1) < self::TOLERANCIA;

            $c['grupos'][] = [
                'padre' => $ctx['clave_padre'], 'nombre_padre' => $ctx['nombre_padre'],
                'rama' => $rama, 'suma' => $suma, 'activos' => $activos,
                'en_juego' => $ctx['en_juego'], 'valido' => $valido
            ];

            if (!$valido) {
                $c['errores'][] = [
                    'tipo' => 'SUMA', 'canal' => $canal, 'clave' => $ctx['clave_padre'], 'rama' => $rama,
                    'suma' => $suma,
                    // Lo que falta para el 100%, sobre la venta del canal. Si
                    // sobra, es negativo: se cobra mas de lo que se vende.
                    'afuera' => (1 - $suma) * $ctx['porc'],
                    'texto' => 'Los medios en juego de ' . $rama . ' suman '
                        . self::porcentajeTexto($suma) . ' y tienen que sumar 100%.'
                ];
            }
        }

        foreach ($hijos as $h) {
            $camino = array_merge($ctx['camino'], [[
                'clave' => $h['clave'], 'nombre' => $h['NOMBRE'], 'nivel' => $h['NIVEL'],
                'rotulo' => self::rotuloNivel($h['NIVEL'])
            ]]);
            $enJuego = $ctx['en_juego'] && $h['ACTIVO'] === 1;
            $dias = $h['DIAS_ACREDITACION'] !== null ? $h['DIAS_ACREDITACION'] : $ctx['dias'];
            $diasDe = $h['DIAS_ACREDITACION'] !== null
                ? ['clave' => $h['clave'], 'nombre' => $h['NOMBRE']] : $ctx['dias_de'];

            $nodo = [
                'clave' => $h['clave'], 'id' => $h['ID'], 'canal' => $canal,
                'padre' => $ctx['clave_padre'], 'nivel' => $h['NIVEL'],
                'rotulo' => self::rotuloNivel($h['NIVEL']), 'nombre' => $h['NOMBRE'],
                'camino' => $camino, 'profundidad' => count($ctx['camino']),
                'activo' => $h['ACTIVO'], 'en_juego' => $enJuego, 'hoja' => false,
                'porcentaje' => $h['PORCENTAJE'],
                'costo_propio' => $h['COSTO'], 'tasa_propia' => $h['TASA'],
                'dias_propios' => $h['DIAS_ACREDITACION'],
                'porcentaje_efectivo' => $ctx['porc'] * $h['PORCENTAJE'],
                'costo' => $ctx['costo'] + ($h['COSTO'] === null ? 0 : $h['COSTO']),
                'tasa' => $ctx['tasa'] + ($h['TASA'] === null ? 0 : $h['TASA']),
                'dias' => $dias, 'dias_de' => $diasDe,
                'hijos' => [], 'orden' => $h['ORDEN'],
                'usuario_modif' => $h['USUARIO_MODIF'], 'fecha_modif' => $h['FECHA_MODIF']
            ];
            $nodo['carga'] = $nodo['costo'] + $nodo['tasa'];
            $nodo['carga_ponderada'] = null;

            // Se reserva el lugar ANTES de bajar: el orden de la salida es el
            // de dibujo, padre antes que hijos.
            $salida[$h['clave']] = $nodo;

            $sub = self::bajar($canal, $h['ID'], [
                'canal' => $canal, 'camino' => $camino, 'en_juego' => $enJuego,
                'porc' => $nodo['porcentaje_efectivo'], 'costo' => $nodo['costo'],
                'tasa' => $nodo['tasa'], 'dias' => $dias, 'dias_de' => $diasDe,
                'clave_padre' => $h['clave'], 'nombre_padre' => $h['NOMBRE']
            ], $hijosDe, $salida, $c);

            $hijosClaves = isset($hijosDe[$canal . '|' . $h['ID']])
                ? array_column($hijosDe[$canal . '|' . $h['ID']], 'clave') : [];
            $salida[$h['clave']]['hijos'] = $hijosClaves;

            $hijosEnJuego = $enJuego && $sub['en_juego'] > 0;

            if ($enJuego && !$hijosEnJuego) {
                // ---- Una hoja ---------------------------------------------
                $salida[$h['clave']]['hoja'] = true;
                $salida[$h['clave']]['carga_ponderada'] = $nodo['carga'];
                $c['hojas'][] = $h['clave'];

                if ($dias === null) {
                    $c['errores'][] = [
                        'tipo' => 'SIN_DIAS', 'canal' => $canal, 'clave' => $h['clave'],
                        'rama' => self::textoCamino($canal, $camino),
                        'afuera' => $nodo['porcentaje_efectivo'],
                        'texto' => self::textoCamino($canal, $camino) . ' no tiene días de '
                            . 'acreditación ni en ese nodo ni en ninguno de arriba.'
                    ];
                }

                if ($nodo['carga'] >= 1 - self::TOLERANCIA) {
                    $c['errores'][] = [
                        'tipo' => 'CARGA', 'canal' => $canal, 'clave' => $h['clave'],
                        'rama' => self::textoCamino($canal, $camino),
                        'afuera' => $nodo['porcentaje_efectivo'],
                        'texto' => 'El costo + tasa acumulado de ' . self::textoCamino($canal, $camino)
                            . ' es ' . self::porcentajeTexto($nodo['carga'])
                            . ' y tiene que ser menor a 100%.'
                    ];
                }

                $acum['peso'] += $nodo['porcentaje_efectivo'];
                $acum['carga'] += $nodo['porcentaje_efectivo'] * $nodo['carga'];
            } elseif ($hijosEnJuego) {
                $salida[$h['clave']]['carga_ponderada'] = $sub['peso'] > 0
                    ? $sub['carga'] / $sub['peso'] : null;
                $acum['peso'] += $sub['peso'];
                $acum['carga'] += $sub['carga'];
            }

            if ($enJuego) {
                $acum['en_juego'] = (isset($acum['en_juego']) ? $acum['en_juego'] : 0) + 1;
            }
        }

        $acum['en_juego'] = isset($acum['en_juego']) ? $acum['en_juego'] : 0;

        return $acum;
    }

    /** "Locales › Tarjeta › Credito" */
    public static function textoCamino($canal, $camino) {
        return implode(self::SEPARADOR, array_merge(
            [self::nombreCanal($canal)], array_column($camino, 'nombre')));
    }

    /** 0.1296 -> "12,96%" */
    public static function porcentajeTexto($fraccion) {
        return number_format($fraccion * 100, 2, ',', '.') . '%';
    }

    /* ====================================================================
       EL MIX POR MES
       ====================================================================

       Cada canal es CONSTANTE -un arbol para todos los meses, como siempre- o
       VARIABLE: el MISMO arbol, con porcentaje, costo, tasa, dias y activo
       propios para los meses del anio que se cargaron. Un mes sin mix propio
       usa el constante. El mes que decide es el de la VENTA.

       LA CONFIG (armarConfig()):
         'tablas' => bool             si se corrio el script
         'modos'  => [CANAL => 'CONSTANTE'|'VARIABLE']
         'meses'  => [CANAL => [MES => ['id', 'activo', 'usuario_modif',
                      'fecha_modif', 'valores' => [ID_NODO => CAMPOS_MES +
                      USUARIO_MODIF, FECHA_MODIF]]]]
       Trae tambien los meses dados de baja (activo 0): sus filas existen, y
       volver a cargar el mes las reactiva en vez de insertar otras.
       ==================================================================== */

    /** Todo en constante: sin el script, o mientras no se cargue nada */
    public static function configVacia() {
        return ['tablas' => false, 'modos' => array_fill_keys(Parametros::CANALES, 'CONSTANTE'),
                'meses' => []];
    }

    /**
     * La config, de las filas de las tres tablas. Pura: los cruces del modulo
     * van en PHP, no en un JOIN.
     *
     * @param array $canales Filas de TABLA_CANAL
     * @param array $meses Filas de TABLA_MES
     * @param array $valores Filas de TABLA_NODO_MES
     * @return array
     */
    public static function armarConfig($canales, $meses, $valores) {
        $cfg = self::configVacia();
        $cfg['tablas'] = true;

        foreach ((is_array($canales) ? $canales : []) as $f) {
            $canal = strtoupper(trim((string) $f['CANAL']));
            $modo = strtoupper(trim((string) $f['MODO']));

            if (isset($cfg['modos'][$canal]) && in_array($modo, self::MODOS, true)) {
                $cfg['modos'][$canal] = $modo;
            }
        }

        $porId = [];

        foreach ((is_array($meses) ? $meses : []) as $f) {
            $canal = strtoupper(trim((string) $f['CANAL']));
            $mes = intval($f['MES']);

            $cfg['meses'][$canal][$mes] = [
                'id' => intval($f['ID']),
                'activo' => intval($f['ACTIVO']) === 1,
                'usuario_modif' => isset($f['USUARIO_MODIF']) ? $f['USUARIO_MODIF'] : null,
                'fecha_modif' => isset($f['FECHA_MODIF']) ? $f['FECHA_MODIF'] : null,
                'valores' => []
            ];
            $porId[intval($f['ID'])] = [$canal, $mes];
        }

        foreach ((is_array($valores) ? $valores : []) as $f) {
            $idMes = intval($f['ID_MIX_MES']);

            if (!isset($porId[$idMes])) {
                continue;
            }

            list($canal, $mes) = $porId[$idMes];
            $cfg['meses'][$canal][$mes]['valores'][intval($f['ID_NODO'])] = self::valoresMes($f);
        }

        return $cfg;
    }

    /** Los CAMPOS_MES de una fila, con los tipos de nodo(): null se conserva */
    private static function valoresMes($f) {
        return [
            'PORCENTAJE' => floatval($f['PORCENTAJE']),
            'COSTO' => self::numeroONull(isset($f['COSTO']) ? $f['COSTO'] : null),
            'TASA' => self::numeroONull(isset($f['TASA']) ? $f['TASA'] : null),
            'DIAS_ACREDITACION' => (isset($f['DIAS_ACREDITACION']) && $f['DIAS_ACREDITACION'] !== null
                    && $f['DIAS_ACREDITACION'] !== '')
                ? intval($f['DIAS_ACREDITACION']) : null,
            'ACTIVO' => intval($f['ACTIVO']) === 1 ? 1 : 0,
            'USUARIO_MODIF' => isset($f['USUARIO_MODIF']) ? $f['USUARIO_MODIF'] : null,
            'FECHA_MODIF' => isset($f['FECHA_MODIF']) ? $f['FECHA_MODIF'] : null
        ];
    }

    /** "Diciembre" */
    public static function nombreMes($mes) {
        $m = intval($mes);

        return isset(self::NOMBRES_MES[$m]) ? self::NOMBRES_MES[$m] : (string) $mes;
    }

    /** Un mes del anio valido, o una excepcion que lo diga */
    public static function validarMes($mes) {
        if (!is_numeric($mes) || floatval($mes) != intval($mes) || intval($mes) < 1 || intval($mes) > 12) {
            throw new Exception('El mes tiene que ser un número de 1 a 12.');
        }

        return intval($mes);
    }

    /** Si el canal tiene ese mes cargado (activo), este en el modo que este */
    public static function mesCargado($config, $canal, $mes) {
        return !empty($config['meses'][$canal][intval($mes)]['activo']);
    }

    /**
     * Si el canal usa su mix propio en ese mes: esta en VARIABLE y el mes esta
     * cargado. Un canal en CONSTANTE ignora sus meses, aunque los tenga.
     */
    public static function mesPropio($config, $canal, $mes) {
        return $config !== null && $mes !== null
            && isset($config['modos'][$canal]) && $config['modos'][$canal] === 'VARIABLE'
            && self::mesCargado($config, $canal, $mes);
    }

    /**
     * Los nodos como quedan en un mes: los del constante, con los valores del
     * mes encima en los canales que lo usan. Pura.
     *
     * LA ESTRUCTURA ES SIEMPRE LA DEL CONSTANTE: ID, padre, nivel, nombre y
     * orden no se tocan, asi las claves de los nodos son las mismas en todos
     * los meses. Un nodo sin fila en un mes cargado conserva los valores del
     * constante y vuelve en 'faltan', para que resolver() lo diga.
     *
     * @return array ['nodos' => [...], 'faltan' => [nodos]]
     */
    public static function nodosDelMes($nodos, $config, $mes) {
        $v = [];
        $faltan = [];

        foreach ($nodos as $n) {
            if (self::mesPropio($config, $n['CANAL'], $mes)) {
                $valores = $config['meses'][$n['CANAL']][intval($mes)]['valores'];

                if (isset($valores[$n['ID']])) {
                    $n = array_merge($n, $valores[$n['ID']]);
                } else {
                    $faltan[] = $n;
                }
            }

            $v[] = $n;
        }

        return ['nodos' => $v, 'faltan' => $faltan];
    }

    /**
     * Lo que un mes tiene hoy para cada nodo de un canal: sus valores si esta
     * cargado, los del constante si no. Es de donde parte un guardado, lo que
     * se copia a otros meses y lo que la pantalla muestra al elegir el mes.
     * Pura. No mira el modo del canal: es lo guardado, no lo que se usa.
     *
     * @return array [ID_NODO => CAMPOS_MES + 'propio' => bool, ...]
     */
    public static function valoresDelMes($nodos, $config, $canal, $mes) {
        $cargado = self::mesCargado($config, $canal, $mes);
        $propios = $cargado ? $config['meses'][$canal][intval($mes)]['valores'] : [];
        $v = [];

        foreach ($nodos as $n) {
            if ($n['CANAL'] !== $canal) {
                continue;
            }

            $base = isset($propios[$n['ID']]) ? $propios[$n['ID']] : $n;
            $fila = ['propio' => isset($propios[$n['ID']])];

            foreach (array_merge(self::CAMPOS_MES, ['USUARIO_MODIF', 'FECHA_MODIF']) as $campo) {
                $fila[$campo] = $base[$campo];
            }

            $v[$n['ID']] = $fila;
        }

        return $v;
    }

    /** La config con el canal en VARIABLE: para ver o validar un mes sin importar el modo */
    private static function configVariable($config, $canal) {
        $config['modos'][$canal] = 'VARIABLE';

        return $config;
    }

    /**
     * Que se escribe al guardar un mes, y como queda la config. Pura.
     *
     * EN UN MES SE CARGAN VALORES, NO ESTRUCTURA. Un nombre o un nivel que no
     * es el del constante se rechaza: renombrar "Credito" solo en diciembre
     * dejaria dos nodos con la misma clave y distinto nombre.
     *
     * UN MES QUE NO ESTABA CARGADO SE ESCRIBE ENTERO -todos los nodos del
     * canal, todos sus campos-: desde ese momento el mes tiene sus propios
     * valores y no sigue los cambios del constante. Uno ya cargado escribe
     * solo lo que cambio. Un nodo sin fila en el mes se escribe entero.
     *
     * @param array $actuales Nodos normalizados de todos los canales
     * @param array $config
     * @param string $canal
     * @param int $mes
     * @param array $filas Las de resolverCambios()
     * @return array ['cambios' => [['id', 'existe', 'campos']], 'nuevo' => bool,
     *               'config' => la config como quedaria, con el canal en VARIABLE]
     */
    public static function resolverCambiosMes($actuales, $config, $canal, $mes, $filas) {
        $canal = strtoupper(trim((string) $canal));
        $mes = self::validarMes($mes);

        if (!in_array($canal, Parametros::CANALES, true)) {
            throw new Exception('Canal inválido: ' . $canal);
        }

        $nombreMes = self::nombreMes($mes);
        $cargado = self::mesCargado($config, $canal, $mes);
        $existentes = isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes]['valores'] : [];
        $base = self::valoresDelMes($actuales, $config, $canal, $mes);
        $porId = [];

        foreach ($actuales as $n) {
            $porId[$n['ID']] = $n;
        }

        $nuevos = $base;
        $vistos = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $id = isset($f['id']) ? intval($f['id']) : 0;

            if (!isset($porId[$id])) {
                throw new Exception('El nodo ' . $id . ' no existe: recargá la pantalla y volvé a guardar.');
            }

            if (isset($vistos[$id])) {
                throw new Exception('El nodo ' . $id . ' vino dos veces en el mismo guardado.');
            }

            $vistos[$id] = true;
            $nodo = $porId[$id];
            $quien = '"' . $nodo['NOMBRE'] . '"';

            if ($nodo['CANAL'] !== $canal) {
                throw new Exception('El nodo ' . $quien . ' es de ' . self::nombreCanal($nodo['CANAL'])
                    . ' y se está guardando ' . self::nombreCanal($canal) . '.');
            }

            if (array_key_exists('nombre', $f) && $f['nombre'] !== null
                    && self::validarNombre($f['nombre']) !== $nodo['NOMBRE']) {
                throw new Exception('En ' . $nombreMes . ' se cargan los valores de cada nodo: el nombre de '
                    . $quien . ' se cambia en el mix constante, y vale para todos los meses.');
            }

            if (array_key_exists('nivel', $f) && $f['nivel'] !== null
                    && strtoupper(trim((string) $f['nivel'])) !== $nodo['NIVEL']) {
                throw new Exception('En ' . $nombreMes . ' se cargan los valores de cada nodo: el nivel de '
                    . $quien . ' se cambia en el mix constante, y vale para todos los meses.');
            }

            $v = $nuevos[$id];

            if (array_key_exists('porcentaje', $f)) {
                $v['PORCENTAJE'] = self::validarFraccion($f['porcentaje'], 'El porcentaje de ' . $quien, false);
            }

            if (array_key_exists('costo', $f)) {
                $v['COSTO'] = self::validarFraccion($f['costo'], 'El costo de ' . $quien, true);
            }

            if (array_key_exists('tasa', $f)) {
                $v['TASA'] = self::validarFraccion($f['tasa'], 'La tasa de ' . $quien, true);
            }

            if (array_key_exists('dias', $f)) {
                $v['DIAS_ACREDITACION'] = self::validarDias($f['dias'], $quien);
            }

            if (array_key_exists('activo', $f)) {
                $v['ACTIVO'] = !empty($f['activo']) ? 1 : 0;
            }

            $nuevos[$id] = $v;
        }

        $cambios = [];
        $valores = [];

        foreach ($nuevos as $id => $v) {
            $existe = isset($existentes[$id]);
            $campos = [];

            foreach (self::CAMPOS_MES as $col) {
                $distinto = in_array($col, ['PORCENTAJE', 'COSTO', 'TASA'], true)
                    ? self::numeroDistinto($base[$id][$col], $v[$col])
                    : $base[$id][$col] !== $v[$col];

                // Un mes nuevo, o un nodo que no tenia fila, va entero
                if (!$cargado || !$base[$id]['propio'] || $distinto) {
                    $campos[$col] = $v[$col];
                }
            }

            if (count($campos)) {
                $cambios[] = ['id' => $id, 'existe' => $existe, 'campos' => $campos];
            }

            unset($v['propio']);
            $valores[$id] = $v;
        }

        $simulado = self::configVariable($config, $canal);
        $simulado['meses'][$canal][$mes] = array_merge(
            isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes]
                : ['id' => null, 'usuario_modif' => null, 'fecha_modif' => null],
            ['activo' => true, 'valores' => $valores]
        );

        return ['cambios' => $cambios, 'nuevo' => !$cargado, 'config' => $simulado];
    }

    /**
     * Que se escribe al guardar un mes, validado contra el mes COMO QUEDARIA.
     * Pura. Lo mismo que planGuardado() para el constante: un mes invalido
     * corta aca, antes de que el llamador abra la transaccion.
     *
     * @return array Lo de resolverCambiosMes()
     */
    public static function planGuardadoMes($actuales, $config, $canal, $mes, $filas) {
        $canal = strtoupper(trim((string) $canal));
        $vista = self::previsualizar($actuales, $canal, $filas, $mes, $config);

        if (!$vista['valido']) {
            throw new Exception('El mix de ' . self::nombreMes($mes) . ' de ' . self::nombreCanal($canal)
                . ' no se guardó: ' . implode(' ', $vista['errores']));
        }

        return self::resolverCambiosMes($actuales, $config, $canal, $mes, $filas);
    }

    /**
     * En que meses cargados siguen activos los nodos que el constante apaga,
     * y como quedan si se los apaga tambien ahi. Pura.
     *
     * Es la casilla "Inhabilitarlo tambien en esos meses" del editor. Cada mes
     * afectado se valida como quedaria, con la misma regla: si alguno queda
     * invalido -un grupo que ya no suma 100%-, se corta TODO y se dice que mes
     * y por que. No se guarda el constante a medias.
     *
     * Mira los meses cargados de cualquier canal en cualquier modo: un canal
     * en constante conserva sus meses para cuando vuelva a variable.
     *
     * @param array $actuales Nodos ANTES del guardado del constante
     * @param array $simulado Nodos como quedarian (resolverCambios())
     * @param array $config
     * @param string $canal
     * @param array $cambios Los del constante (resolverCambios())
     * @param array|null $soloIds Los nodos para los que se tildo la casilla;
     *                   null = todos los que se apagan
     * @return array [mes => ['id' => ID_MIX_MES, 'cambios' => [['id', 'existe', 'campos']]]]
     */
    public static function planInhabilitarEnMeses($actuales, $simulado, $config, $canal, $cambios,
                                                  $soloIds = null) {
        $apagados = [];
        $solo = $soloIds === null ? null : array_map('intval', $soloIds);

        foreach ($cambios as $c) {
            if (array_key_exists('ACTIVO', $c['campos']) && $c['campos']['ACTIVO'] === 0
                    && ($solo === null || in_array($c['id'], $solo, true))) {
                $apagados[] = $c['id'];
            }
        }

        $plan = [];
        $meses = isset($config['meses'][$canal]) ? $config['meses'][$canal] : [];

        if (!count($apagados)) {
            return $plan;
        }

        ksort($meses);

        foreach ($meses as $mes => $m) {
            if (!$m['activo']) {
                continue;
            }

            $valores = self::valoresDelMes($actuales, $config, $canal, $mes);
            $filas = [];

            foreach ($apagados as $id) {
                if (isset($valores[$id]) && $valores[$id]['ACTIVO'] === 1) {
                    $filas[] = ['id' => $id, 'activo' => false];
                }
            }

            if (!count($filas)) {
                continue;
            }

            $vista = self::previsualizar($simulado, $canal, $filas, $mes, $config);

            if (!$vista['valido']) {
                throw new Exception('No se guardó nada: al inhabilitarlo también en ' . self::nombreMes($mes)
                    . ', ese mes queda mal. ' . implode(' ', $vista['errores'])
                    . ' Reacomodá ' . self::nombreMes($mes) . ' antes, o destildá la casilla.');
            }

            $plan[$mes] = ['id' => $m['id'],
                           'cambios' => self::resolverCambiosMes($simulado, $config, $canal, $mes, $filas)['cambios']];
        }

        return $plan;
    }

    /**
     * Que se escribe al copiar un mes a otros. Pura.
     *
     * SE COPIA LO GUARDADO del mes de origen, que tiene que estar cargado y
     * ser valido. Cada destino queda con exactamente esos valores, todos los
     * nodos y todos los campos, tuviera o no mix propio: el dialogo avisa
     * cuales se pisan.
     *
     * @return array [mes destino => ['id' => ID_MIX_MES|null, 'cambios' => [...]]]
     */
    public static function planCopia($actuales, $config, $canal, $origen, $destinos) {
        $canal = strtoupper(trim((string) $canal));
        $origen = self::validarMes($origen);

        if (!in_array($canal, Parametros::CANALES, true)) {
            throw new Exception('Canal inválido: ' . $canal);
        }

        if (!is_array($destinos) || !count($destinos)) {
            throw new Exception('Elegí al menos un mes al que copiar ' . self::nombreMes($origen) . '.');
        }

        if (!self::mesCargado($config, $canal, $origen)) {
            throw new Exception(self::nombreMes($origen) . ' usa el mix constante: guardalo como mes '
                . 'propio antes de copiarlo.');
        }

        $vista = self::previsualizar($actuales, $canal, [], $origen, $config);

        if (!$vista['valido']) {
            throw new Exception(self::nombreMes($origen) . ' tiene problemas y no se copia: '
                . implode(' ', $vista['errores']));
        }

        $valores = self::valoresDelMes($actuales, $config, $canal, $origen);
        $plan = [];

        foreach ($destinos as $d) {
            $mes = self::validarMes($d);

            if ($mes === $origen) {
                throw new Exception('No se puede copiar ' . self::nombreMes($origen) . ' sobre sí mismo.');
            }

            if (isset($plan[$mes])) {
                throw new Exception(self::nombreMes($mes) . ' vino dos veces.');
            }

            $existentes = isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes]['valores'] : [];
            $cambios = [];

            foreach ($valores as $id => $v) {
                $cambios[] = ['id' => $id, 'existe' => isset($existentes[$id]),
                              'campos' => array_intersect_key($v, array_flip(self::CAMPOS_MES))];
            }

            $plan[$mes] = [
                'id' => isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes]['id'] : null,
                'cambios' => $cambios
            ];
        }

        ksort($plan);

        return $plan;
    }

    /* ====================================================================
       GUARDADO
       ==================================================================== */

    /**
     * Que nodos cambiaron en un guardado de Parametros, y como queda el arbol.
     * Pura.
     *
     * La pantalla manda el arbol entero del canal; sin el diff, cada guardado
     * les sellaba USUARIO_MODIF y FECHA_MODIF a todos los nodos, y "Ultima
     * edicion" diria que alguien edito el canal entero el mismo segundo. Es el
     * mismo criterio que Saldos::resolverParametrosLocales().
     *
     * VALIDA LO DEL CAMPO ACA, y el arbol COMO QUEDARIA lo valida el llamador
     * con validarEstructura() y resolver() sobre 'simulado', antes de abrir la
     * transaccion, como hacia el guardado del mix plano con su simulado.
     *
     * LO QUE NO SE PUEDE CAMBIAR
     *   - El padre: mover un nodo de rama no es editarlo. ID_PADRE no viaja.
     *   - El nivel de un nodo CON HIJOS, activos o no: sus hijos quedarian con
     *     un nivel que quizas ya no es posterior al suyo. Si quedo mal, se
     *     inhabilita y se crea otro.
     *   - Un nodo de otro canal: el guardado es de un canal por vez.
     *
     * Un campo ausente no se toca; costo, tasa y dias vacios son null.
     *
     * @param array $actuales Nodos normalizados de TODOS los canales
     * @param string $canal Canal que se guarda
     * @param array $filas [['id', 'nombre'?, 'nivel'?, 'porcentaje'?, 'costo'?,
     *                     'tasa'?, 'dias'?, 'activo'?], ...] porcentajes en fraccion
     * @return array ['cambios' => [['id', 'campos' => [COLUMNA => valor]]],
     *               'simulado' => nodos normalizados como quedarian]
     */
    public static function resolverCambios($actuales, $canal, $filas) {
        $canal = strtoupper(trim((string) $canal));
        $porId = [];
        $conHijos = [];

        foreach ($actuales as $n) {
            $porId[$n['ID']] = $n;

            if ($n['ID_PADRE'] !== null) {
                $conHijos[$n['ID_PADRE']] = true;
            }
        }

        $cambios = [];
        $vistos = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $id = isset($f['id']) ? intval($f['id']) : 0;

            if (!isset($porId[$id])) {
                throw new Exception('El nodo ' . $id . ' no existe: recargá la pantalla y volvé a guardar.');
            }

            if (isset($vistos[$id])) {
                throw new Exception('El nodo ' . $id . ' vino dos veces en el mismo guardado.');
            }

            $vistos[$id] = true;
            $antes = $porId[$id];
            $quien = '"' . $antes['NOMBRE'] . '"';

            if ($antes['CANAL'] !== $canal) {
                throw new Exception('El nodo ' . $quien . ' es de ' . self::nombreCanal($antes['CANAL'])
                    . ' y se está guardando ' . self::nombreCanal($canal) . '.');
            }

            $nuevo = $antes;

            if (array_key_exists('nombre', $f)) {
                $nuevo['NOMBRE'] = self::validarNombre($f['nombre']);
            }

            if (array_key_exists('nivel', $f)) {
                $nuevo['NIVEL'] = strtoupper(trim((string) $f['nivel']));

                if ($nuevo['NIVEL'] !== $antes['NIVEL'] && isset($conHijos[$id])) {
                    throw new Exception('No se puede cambiar el nivel de ' . $quien . ' porque tiene '
                        . 'nodos debajo. Si quedó mal, inhabilitalo y creá otro.');
                }
            }

            if (array_key_exists('porcentaje', $f)) {
                $nuevo['PORCENTAJE'] = self::validarFraccion($f['porcentaje'], 'El porcentaje de ' . $quien, false);
            }

            if (array_key_exists('costo', $f)) {
                $nuevo['COSTO'] = self::validarFraccion($f['costo'], 'El costo de ' . $quien, true);
            }

            if (array_key_exists('tasa', $f)) {
                $nuevo['TASA'] = self::validarFraccion($f['tasa'], 'La tasa de ' . $quien, true);
            }

            if (array_key_exists('dias', $f)) {
                $nuevo['DIAS_ACREDITACION'] = self::validarDias($f['dias'], $quien);
            }

            if (array_key_exists('activo', $f)) {
                $nuevo['ACTIVO'] = !empty($f['activo']) ? 1 : 0;
            }

            $campos = [];

            foreach (['NOMBRE', 'NIVEL', 'ACTIVO', 'DIAS_ACREDITACION'] as $col) {
                if ($nuevo[$col] !== $antes[$col]) {
                    $campos[$col] = $nuevo[$col];
                }
            }

            foreach (['PORCENTAJE', 'COSTO', 'TASA'] as $col) {
                if (self::numeroDistinto($antes[$col], $nuevo[$col])) {
                    $campos[$col] = $nuevo[$col];
                }
            }

            if (count($campos)) {
                $cambios[] = ['id' => $id, 'campos' => $campos];
            }

            $porId[$id] = $nuevo;
        }

        return ['cambios' => $cambios, 'simulado' => array_values($porId)];
    }

    /** Dos numeros nullables distintos: null contra cero SI es distinto */
    private static function numeroDistinto($a, $b) {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return abs($a - $b) > self::TOLERANCIA / 10;
    }

    /**
     * El nombre validado: sin espacios de mas, no vacio y que entre en la
     * columna. Lo comparten el alta y el guardado.
     */
    public static function validarNombre($nombre) {
        $n = trim(preg_replace('/\s+/u', ' ', (string) $nombre));

        if ($n === '') {
            throw new Exception('El nombre no puede estar vacío.');
        }

        if (mb_strlen($n, 'UTF-8') > self::LARGO_NOMBRE) {
            throw new Exception('El nombre "' . $n . '" supera los ' . self::LARGO_NOMBRE . ' caracteres.');
        }

        return $n;
    }

    /**
     * Una fraccion entre 0 y 1. Con $vacioEsNull, vacio o null es null: el
     * costo y la tasa vacios valen cero sin ser un cero cargado.
     */
    public static function validarFraccion($v, $que, $vacioEsNull) {
        if ($v === null || $v === '') {
            if ($vacioEsNull) {
                return null;
            }

            throw new Exception($que . ' no puede estar vacío.');
        }

        if (!is_numeric($v)) {
            throw new Exception($que . ' no es un número: "' . $v . '".');
        }

        $f = floatval($v);

        if ($f < 0 || $f > 1 + self::TOLERANCIA) {
            throw new Exception($que . ' tiene que estar entre 0% y 100%.');
        }

        return round($f, 6);
    }

    /** Dias: vacio es null (los define un nivel de arriba); si no, entero >= 0 */
    public static function validarDias($v, $quien) {
        if ($v === null || $v === '') {
            return null;
        }

        if (!is_numeric($v) || floatval($v) != intval($v)) {
            throw new Exception('Los días de ' . $quien . ' tienen que ser un número entero.');
        }

        if (intval($v) < 0) {
            throw new Exception('Los días de ' . $quien . ' no pueden ser negativos.');
        }

        return intval($v);
    }

    /**
     * Un nodo nuevo, validado y como quedaria. Pura.
     *
     * ENTRA INHABILITADO Y EN 0%, como entraba un medio de pago en el mix
     * plano: no rompe el 100% de su grupo en el momento del alta. Para usarlo
     * hay que encenderlo y reacomodar los porcentajes de sus hermanos, y esa
     * validacion corre al guardar. El formulario no pide porcentaje por eso.
     *
     * Valida el arbol entero CON el nodo nuevo: el nivel contra su padre y el
     * nombre contra sus hermanos salen de validarEstructura(), la misma que
     * corre al guardar.
     *
     * @param array $actuales Nodos normalizados de todos los canales
     * @param array $datos ['canal', 'id_padre'|null, 'nombre', 'nivel', 'costo'?,
     *                     'tasa'?, 'dias'?]
     * @return array La fila a insertar (columnas de la tabla)
     */
    public static function nodoNuevo($actuales, $datos) {
        $canal = strtoupper(trim((string) (isset($datos['canal']) ? $datos['canal'] : '')));

        if (!in_array($canal, Parametros::CANALES, true)) {
            throw new Exception('Canal inválido: ' . $canal);
        }

        $idPadre = (isset($datos['id_padre']) && $datos['id_padre'] !== null && $datos['id_padre'] !== '')
            ? intval($datos['id_padre']) : null;

        $fila = [
            'CANAL' => $canal,
            'ID_PADRE' => $idPadre,
            'NIVEL' => strtoupper(trim((string) (isset($datos['nivel']) ? $datos['nivel'] : ''))),
            'NOMBRE' => self::validarNombre(isset($datos['nombre']) ? $datos['nombre'] : ''),
            'PORCENTAJE' => 0.0,
            'COSTO' => self::validarFraccion(isset($datos['costo']) ? $datos['costo'] : null, 'El costo', true),
            'TASA' => self::validarFraccion(isset($datos['tasa']) ? $datos['tasa'] : null, 'La tasa', true),
            'DIAS_ACREDITACION' => self::validarDias(isset($datos['dias']) ? $datos['dias'] : null, 'el nodo nuevo'),
            'ACTIVO' => 0
        ];

        // Un ID que no choca con ninguno, solo para validar
        $maxId = 0;

        foreach ($actuales as $n) {
            $maxId = max($maxId, $n['ID']);
        }

        $simulado = array_merge($actuales, [self::nodo(array_merge($fila, ['ID' => $maxId + 1]), 'N')]);

        foreach (self::validarEstructura($simulado) as $e) {
            if ($e['clave'] === 'N' . ($maxId + 1)) {
                throw new Exception($e['texto']);
            }
        }

        return $fila;
    }

    /* ====================================================================
       LECTURA DE BASE
       ==================================================================== */

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del sondeo de la tabla */
    private $tabla = null;

    /** @var bool|null Cache del sondeo de las tablas del mix por mes */
    private $tablasMes = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /** La conexion a central, o una excepcion que diga por que no */
    protected function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        return $cid;
    }

    /**
     * Si ya se corrio sql/cashflow_ventas_mix_nodo.sql.
     *
     * Sin la tabla nada se cae: el mix sale del plano (getArbol()) y
     * Parametros lo muestra solo lectura, diciendo que script correr.
     *
     * @return bool
     */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $stmt = sqlsrv_query($this->conectar(), "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al verificar la tabla del mix de cobro'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Los nodos de la tabla nueva, normalizados. Todos, activos o no: la
     * regla necesita los inactivos para saber que sacan de juego.
     *
     * @return array
     */
    public function getNodos() {
        $sql = "SELECT ID, CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA,
                       DIAS_ACREDITACION, ACTIVO, ORDEN, USUARIO_MODIF, FECHA_MODIF
                FROM " . self::TABLA . "
                ORDER BY CANAL, ORDEN, ID";

        $stmt = sqlsrv_query($this->conectar(), $sql);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al leer el mix de cobro'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Con la hora: es el "cuando" de la auditoria (Js/auditoria.js)
            $row['FECHA_MODIF'] = ($row['FECHA_MODIF'] instanceof DateTime)
                ? $row['FECHA_MODIF']->format('Y-m-d H:i:s') : $row['FECHA_MODIF'];
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return self::normalizar($filas);
    }

    /**
     * El mix vigente, de donde sea que salga.
     *
     * CON LA TABLA NUEVA, el mix plano DEJA DE LEERSE, aunque la tabla este
     * vacia: un arbol vacio es un mix sin medios -y el motor lo avisa-, no
     * una senal para volver al mix viejo. Si no, borrar el ultimo nodo de un
     * canal resucitaria sin aviso un mix que ya nadie edita.
     *
     * 'config' es el mix por mes (getConfig()). Con el mix plano, todo
     * constante: un mes no tiene nodos sobre los que cargar valores.
     *
     * @return array ['origen' => 'NODO'|'PLANO', 'nodos' => [...], 'config' => [...]]
     */
    public function getArbol() {
        if ($this->tablaCreada()) {
            return ['origen' => 'NODO', 'nodos' => $this->getNodos(), 'config' => $this->getConfig()];
        }

        $parametros = new Parametros();

        return ['origen' => 'PLANO', 'nodos' => self::desdeMixPlano($parametros->getMixCobro()),
                'config' => self::configVacia()];
    }

    /**
     * Si ya se corrio sql/cashflow_ventas_mix_mes.sql: las tres tablas.
     *
     * Sin ellas nada se cae: todos los canales quedan en constante, que es
     * exactamente la proyeccion de antes.
     *
     * @return bool
     */
    public function tablasMesCreadas() {
        if ($this->tablasMes !== null) {
            return $this->tablasMes;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT OBJECT_ID('dbo." . self::TABLA_CANAL . "', 'U') AS C,
                    OBJECT_ID('dbo." . self::TABLA_MES . "', 'U') AS M,
                    OBJECT_ID('dbo." . self::TABLA_NODO_MES . "', 'U') AS V");

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al verificar las tablas del mix por mes'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tablasMes = ($row && $row['C'] !== null && $row['M'] !== null && $row['V'] !== null);

        return $this->tablasMes;
    }

    /**
     * El mix por mes: modos, meses y valores. Sin las tablas, todo constante.
     * Tres lecturas sin JOIN: el cruce lo hace armarConfig(), en PHP.
     *
     * @return array
     */
    public function getConfig() {
        if (!$this->tablaCreada() || !$this->tablasMesCreadas()) {
            return self::configVacia();
        }

        return self::armarConfig(
            $this->leer("SELECT CANAL, MODO FROM " . self::TABLA_CANAL, 'los modos del mix'),
            $this->leer("SELECT ID, CANAL, MES, ACTIVO, USUARIO_MODIF, FECHA_MODIF FROM " . self::TABLA_MES,
                'los meses del mix'),
            $this->leer("SELECT ID_MIX_MES, ID_NODO, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION, ACTIVO,
                                USUARIO_MODIF, FECHA_MODIF
                         FROM " . self::TABLA_NODO_MES, 'los valores del mix por mes')
        );
    }

    /** Las filas de una consulta, con FECHA_MODIF con hora (Js/auditoria.js) */
    private function leer($sql, $que) {
        $stmt = sqlsrv_query($this->conectar(), $sql);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al leer ' . $que));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (isset($row['FECHA_MODIF']) && $row['FECHA_MODIF'] instanceof DateTime) {
                $row['FECHA_MODIF'] = $row['FECHA_MODIF']->format('Y-m-d H:i:s');
            }

            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /* ====================================================================
       EL EDITOR DE PARAMETROS
       ==================================================================== */

    /**
     * Lo que necesita Parametros -> Ventas -> Mix de Cobro y Plazos para
     * dibujar el arbol. Pura.
     *
     * VA RESUELTO: cada nodo con lo que la regla dice de el -su % efectivo,
     * su costo + tasa acumulado, de quien hereda los dias-, cada grupo de
     * hermanos con su suma, y los niveles que admite cada nodo y cada hijo
     * nuevo. La pantalla pinta, no calcula: si tuviera su copia de la regla,
     * algun dia diria que "Resto" hereda 7 dias y el tablero lo acreditaria a
     * otros.
     *
     * SIN EL SCRIPT ('PLANO') NO ES EDITABLE: el arbol que se muestra es el
     * mix plano, de un nivel, y no hay tabla donde guardar un nodo nuevo.
     *
     * EL MIX POR MES VIAJA ENTERO: el modo de cada canal y, por canal y por
     * cada uno de los doce meses, si esta cargado y los valores de cada nodo
     * (los propios, o los del constante si no lo esta). Elegir un mes en la
     * pantalla no pide nada para mostrar los valores; lo heredado y las
     * sumas los pide a previsualizarMixArbol con ese mes, como al editar.
     *
     * @param array $nodos Nodos normalizados
     * @param string $origen 'NODO' | 'PLANO'
     * @param array|null $config La de getConfig(); null es todo constante
     * @return array
     */
    public static function datosEditor($nodos, $origen, $config = null) {
        $config = $config === null ? self::configVacia() : $config;
        $resuelto = self::resolver($nodos);
        $conHijos = [];

        foreach ($nodos as $n) {
            if ($n['ID_PADRE'] !== null) {
                $conHijos[$n['ID_PADRE']] = true;
            }
        }

        $porId = [];

        foreach ($nodos as $n) {
            $porId[$n['ID']] = $n;
        }

        $lista = [];

        foreach ($nodos as $n) {
            $padre = ($n['ID_PADRE'] !== null && isset($porId[$n['ID_PADRE']])) ? $porId[$n['ID_PADRE']] : null;
            $n['tiene_hijos'] = isset($conHijos[$n['ID']]);
            $n['niveles_permitidos'] = self::nivelesPermitidos($n['CANAL'], $padre === null ? null : $padre['NIVEL']);
            $n['niveles_hijo'] = self::nivelesPermitidos($n['CANAL'], $n['NIVEL']);
            $lista[] = $n;
        }

        $primerNivel = [];

        foreach (Parametros::CANALES as $canal) {
            $primerNivel[$canal] = self::nivelesPermitidos($canal, null);
        }

        return [
            'origen' => $origen,
            'editable' => $origen === 'NODO',
            'canales' => Parametros::CANALES,
            'niveles' => self::nivelesLista(),
            'niveles_primer' => $primerNivel,
            'nodos' => $lista,
            'resuelto' => $resuelto,
            // El mix plano no tiene la forma de un arbol -Ecommerce no cuelga
            // de un marketplace- y no se edita: no se le aplica la estructura.
            'estructura' => $origen === 'NODO' ? self::validarEstructura($nodos) : [],
            // Sin el script del mix por mes, todo constante y el selector
            // de modo deshabilitado diciendo que falta.
            'por_mes' => $origen === 'NODO' && !empty($config['tablas']),
            'modos' => $config['modos'],
            'nombres_mes' => array_values(self::NOMBRES_MES),
            'meses' => self::mesesEditor($nodos, $config),
            'vistas_mes' => self::vistasMes($nodos, $config)
        ];
    }

    /**
     * Los meses CARGADOS de cada canal, ya resueltos: lo heredado, las sumas
     * y los errores de cada uno. Viajan en el payload porque quien no edita
     * tambien ve el arbol de cada mes, y previsualizarMixArbol pide permiso
     * de edicion. Un mes sin cargar se ve con lo resuelto del constante.
     *
     * @return array [CANAL => [MES => lo de previsualizar()]]
     */
    private static function vistasMes($nodos, $config) {
        $v = [];

        foreach (Parametros::CANALES as $canal) {
            foreach (array_keys(self::NOMBRES_MES) as $mes) {
                if (self::mesCargado($config, $canal, $mes)) {
                    $v[$canal][$mes] = self::previsualizar($nodos, $canal, [], $mes, $config);
                }
            }
        }

        return $v;
    }

    /**
     * Por canal y por mes: si esta cargado, quien lo toco por ultima vez, y
     * los valores de cada nodo por clave. Pura.
     */
    private static function mesesEditor($nodos, $config) {
        $v = [];
        $claves = array_column($nodos, 'clave', 'ID');

        foreach (Parametros::CANALES as $canal) {
            foreach (self::NOMBRES_MES as $mes => $nombre) {
                $m = isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes] : null;
                $valores = [];

                foreach (self::valoresDelMes($nodos, $config, $canal, $mes) as $id => $val) {
                    $valores[$claves[$id]] = $val;
                }

                $v[$canal][$mes] = [
                    'cargado' => self::mesCargado($config, $canal, $mes),
                    'usuario_modif' => $m ? $m['usuario_modif'] : null,
                    'fecha_modif' => $m ? $m['fecha_modif'] : null,
                    'valores' => $valores
                ];
            }
        }

        return $v;
    }

    /**
     * Como quedaria un canal con lo que hay en pantalla, sin guardar nada.
     * Pura. Es lo que devuelve previsualizarMixArbol.
     *
     * Los errores de campo -un costo de mas de 100%, unos dias con decimales-
     * vuelven como errores y NO como excepcion: mientras se edita, un campo
     * mal tipeado es un estado normal y la pantalla tiene que decir cual es y
     * bloquear Guardar, no tirar un cartel de error.
     *
     * CON $mes, como quedaria ESE MES del canal: los valores que llegan se
     * ponen sobre lo que el mes tiene -sus valores, o los del constante si
     * todavia no esta cargado- y se resuelve con resolver($nodos, $mes). El
     * canal se ve en variable aunque hoy este en constante: es el mes lo que
     * se esta mirando.
     *
     * @param array $actuales Nodos normalizados de todos los canales
     * @param string $canal
     * @param array $filas Las de resolverCambios()
     * @param int|null $mes null es el constante
     * @param array|null $config Obligatoria con $mes
     * @return array ['canal', 'valido', 'errores' => [textos], 'nodos' => [clave =>
     *               nodo resuelto], 'grupos' => [...], 'cambios' => int, 'mes']
     */
    public static function previsualizar($actuales, $canal, $filas, $mes = null, $config = null) {
        $canal = strtoupper(trim((string) $canal));

        if ($mes !== null) {
            return self::previsualizarMes($actuales, $canal, $filas, $mes,
                $config === null ? self::configVacia() : $config);
        }

        try {
            $r = self::resolverCambios($actuales, $canal, $filas);
        } catch (Exception $e) {
            $resuelto = self::resolver($actuales);

            return self::vistaCanal($resuelto, $canal, [$e->getMessage()], 0);
        }

        $errores = [];

        foreach (self::validarEstructura($r['simulado']) as $e) {
            if ($e['canal'] === $canal) {
                $errores[] = $e['texto'];
            }
        }

        $resuelto = self::resolver($r['simulado']);

        foreach ($resuelto['canales'][$canal]['errores'] as $e) {
            $errores[] = $e['texto'];
        }

        return self::vistaCanal($resuelto, $canal, $errores, count($r['cambios']));
    }

    /** previsualizar() de un mes */
    private static function previsualizarMes($actuales, $canal, $filas, $mes, $config) {
        try {
            $mes = self::validarMes($mes);
            $r = self::resolverCambiosMes($actuales, $config, $canal, $mes, $filas);
        } catch (Exception $e) {
            $mesOk = is_numeric($mes) && intval($mes) >= 1 && intval($mes) <= 12 ? intval($mes) : 1;
            $resuelto = self::resolver($actuales, $mesOk, self::configVariable($config, $canal));
            $vista = self::vistaCanal($resuelto, $canal, [$e->getMessage()], 0);
            $vista['mes'] = $mesOk;

            return $vista;
        }

        $resuelto = self::resolver($actuales, $mes, $r['config']);
        $errores = [];

        foreach ($resuelto['canales'][$canal]['errores'] as $e) {
            $errores[] = $e['texto'];
        }

        $vista = self::vistaCanal($resuelto, $canal, $errores, count($r['cambios']));
        $vista['mes'] = $mes;
        $vista['nuevo'] = $r['nuevo'];

        return $vista;
    }

    /** La parte de un canal de lo que resolvio la regla */
    private static function vistaCanal($resuelto, $canal, $errores, $cambios) {
        $nodos = [];

        foreach ($resuelto['nodos'] as $clave => $n) {
            if ($n['canal'] === $canal) {
                $nodos[$clave] = $n;
            }
        }

        return [
            'canal' => $canal,
            'valido' => count($errores) === 0,
            'errores' => $errores,
            'nodos' => $nodos,
            'grupos' => isset($resuelto['canales'][$canal]) ? $resuelto['canales'][$canal]['grupos'] : [],
            'cambios' => $cambios
        ];
    }

    /**
     * Que se escribe en un guardado, validado contra el arbol COMO QUEDARIA.
     * Pura.
     *
     * Valida el canal entero despues de aplicar lo que llega: la estructura
     * (niveles, nombres, rangos) y la regla (sumas, dias de las hojas, carga,
     * hojas). Cualquier error corta ACA, antes de que el llamador abra la
     * transaccion: un arbol invalido no se escribe ni a medias. Es lo que
     * el guardado del mix plano hacia con su simulado, ahora sobre el arbol.
     *
     * Solo bloquean los errores DEL CANAL que se guarda: otro canal roto -por
     * una edicion directa en la base- no tiene por que impedir corregir este.
     *
     * @param array $actuales Nodos normalizados de todos los canales
     * @param string $canal
     * @param array $filas Las de resolverCambios()
     * @return array Los cambios de resolverCambios(), solo los nodos que cambiaron
     */
    public static function planGuardado($actuales, $canal, $filas) {
        $canal = strtoupper(trim((string) $canal));
        $vista = self::previsualizar($actuales, $canal, $filas);

        if (!$vista['valido']) {
            throw new Exception('El mix de ' . self::nombreCanal($canal) . ' no se guardó: '
                . implode(' ', $vista['errores']));
        }

        return self::resolverCambios($actuales, $canal, $filas)['cambios'];
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /** Corta si todavia no se corrio el script: sin tabla no hay donde guardar */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception('Falta correr sql/cashflow_ventas_mix_nodo.sql: hasta entonces el '
                . 'mix de cobro sale del mix plano y no se puede editar.');
        }
    }

    /**
     * Guarda el arbol de un canal: escribe SOLO los nodos que cambiaron, en
     * una transaccion. O se guardan todos o ninguno: un arbol a medio guardar
     * es un arbol cuyas sumas no dan, y el tablero lo proyectaria asi.
     *
     * CON $inhabilitarEnMeses, lo que este guardado apaga se apaga tambien en
     * los meses cargados donde seguia activo, en la MISMA transaccion. Cada
     * mes afectado se valida antes como quedaria: si alguno queda invalido no
     * se escribe nada. Ver planInhabilitarEnMeses().
     *
     * @param string $canal
     * @param array $filas Las de resolverCambios()
     * @param string $usuario El de AuthCashflow
     * @param bool|array $inhabilitarEnMeses true = todo lo que se apaga; una
     *                   lista de IDs = solo esos nodos (la casilla se contesta
     *                   nodo por nodo); false = ninguno
     * @return int Cuantos nodos se escribieron (en el constante)
     */
    public function guardarCanal($canal, $filas, $usuario, $inhabilitarEnMeses = false) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        // Se valida ANTES de pedir la conexion de escritura: un arbol invalido
        // no llega a abrir la transaccion. Los meses tambien.
        $actuales = $this->getNodos();
        $cambios = self::planGuardado($actuales, $canal, $filas);
        $enMeses = [];

        if (count($cambios) === 0) {
            return 0;
        }

        if ($inhabilitarEnMeses && $this->tablasMesCreadas()) {
            $canal = strtoupper(trim((string) $canal));
            $enMeses = self::planInhabilitarEnMeses($actuales,
                self::resolverCambios($actuales, $canal, $filas)['simulado'],
                $this->getConfig(), $canal, $cambios,
                is_array($inhabilitarEnMeses) ? $inhabilitarEnMeses : null);
        }

        $cid = $this->conexionEscritura();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception(self::errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($cambios as $c) {
                $this->escribirNodo($cid, $c['id'], $c['campos'], $usuario);
            }

            foreach ($enMeses as $mes => $p) {
                foreach ($p['cambios'] as $c) {
                    $this->escribirValorMes($cid, $p['id'], $c['id'], $c['campos'], $c['existe'], $usuario);
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception(self::errorSql('No se pudo confirmar el guardado del mix'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return count($cambios);
    }

    /**
     * Agrega un nodo. Entra INHABILITADO Y EN 0%: ver nodoNuevo().
     *
     * Va al final de sus hermanos. El UNIQUE de la tabla es la ultima red: si
     * dos personas agregan el mismo nombre a la vez, la segunda choca ahi.
     *
     * ENTRA TAMBIEN EN CADA MES DE SU CANAL que tenga fila -cargado o dado de
     * baja, que puede volver-, igual que en el constante: inhabilitado, en 0%
     * y con su costo, su tasa y sus dias. En la misma transaccion: un nodo sin
     * valores en un mes cargado se proyectaria con los del constante.
     *
     * @param array $datos Los de nodoNuevo()
     * @param string $usuario
     * @return int ID del nodo creado
     */
    public function agregarNodo($datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $actuales = $this->getNodos();
        $fila = self::nodoNuevo($actuales, $datos);
        $orden = 0;

        foreach ($actuales as $n) {
            if ($n['CANAL'] === $fila['CANAL'] && $n['ID_PADRE'] === $fila['ID_PADRE']) {
                $orden = max($orden, $n['ORDEN']);
            }
        }

        $config = $this->getConfig();
        $meses = isset($config['meses'][$fila['CANAL']]) ? $config['meses'][$fila['CANAL']] : [];
        $cid = $this->conexionEscritura();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception(self::errorSql('No se pudo abrir la transacción'));
        }

        try {
            $id = $this->insertarNodo($cid, $fila, $orden + 1, $usuario);

            foreach ($meses as $m) {
                $this->escribirValorMes($cid, $m['id'], $id, [
                    'PORCENTAJE' => 0.0, 'COSTO' => $fila['COSTO'], 'TASA' => $fila['TASA'],
                    'DIAS_ACREDITACION' => $fila['DIAS_ACREDITACION'], 'ACTIVO' => 0
                ], false, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception(self::errorSql('No se pudo confirmar el alta del nodo'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $id;
    }

    /** El INSERT del nodo nuevo: 0% e inhabilitado, sin parametro */
    protected function insertarNodo($cid, $fila, $orden, $usuario) {
        $sql = "INSERT INTO " . self::TABLA . "
                    (CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION,
                     ACTIVO, ORDEN, USUARIO_ALTA, USUARIO_MODIF)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?, ?)";

        $stmt = sqlsrv_query($cid, $sql, [
            $fila['CANAL'], $fila['ID_PADRE'], $fila['NIVEL'], $fila['NOMBRE'],
            $fila['COSTO'], $fila['TASA'], $fila['DIAS_ACREDITACION'],
            $orden, $usuario, $usuario
        ]);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al agregar el nodo al mix'));
        }

        $nuevo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($nuevo['ID']);
    }

    /* ---- El mix por mes ------------------------------------------------ */

    /** Corta si todavia no se corrio el script del mix por mes */
    private function exigirTablasMes() {
        $this->exigirTabla();

        if (!$this->tablasMesCreadas()) {
            throw new Exception('Falta correr sql/cashflow_ventas_mix_mes.sql: hasta entonces todos los '
                . 'canales usan el mix constante y no se pueden cargar meses.');
        }
    }

    /**
     * Pasa un canal a CONSTANTE o VARIABLE. No toca ningun mes: volver a
     * constante los deja guardados para cuando vuelva a variable.
     *
     * @return bool Si cambio algo
     */
    public function guardarModo($canal, $modo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablasMes();

        $canal = strtoupper(trim((string) $canal));
        $modo = strtoupper(trim((string) $modo));

        if (!in_array($canal, Parametros::CANALES, true)) {
            throw new Exception('Canal inválido: ' . $canal);
        }

        if (!in_array($modo, self::MODOS, true)) {
            throw new Exception('El mix de un canal es CONSTANTE o VARIABLE, no "' . $modo . '".');
        }

        if ($this->getConfig()['modos'][$canal] === $modo) {
            return false;
        }

        $this->escribirModo($this->conexionEscritura(), $canal, $modo, $usuario);

        return true;
    }

    /**
     * Guarda un mes de un canal: lo carga si no lo estaba -o lo reactiva si
     * se habia dado de baja- y escribe sus valores, en una transaccion.
     * Validado ANTES como quedaria, con planGuardadoMes(): un mes invalido no
     * llega a abrir la transaccion.
     *
     * @return int Cuantos valores se escribieron
     */
    public function guardarMes($canal, $mes, $filas, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablasMes();

        $canal = strtoupper(trim((string) $canal));
        $config = $this->getConfig();
        $plan = self::planGuardadoMes($this->getNodos(), $config, $canal, $mes, $filas);
        $mes = self::validarMes($mes);

        if (!$plan['nuevo'] && count($plan['cambios']) === 0) {
            return 0;
        }

        $idMes = isset($config['meses'][$canal][$mes]) ? $config['meses'][$canal][$mes]['id'] : null;
        $cid = $this->conexionEscritura();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception(self::errorSql('No se pudo abrir la transacción'));
        }

        try {
            $idMes = $this->asegurarMes($cid, $canal, $mes, $idMes, $usuario);

            foreach ($plan['cambios'] as $c) {
                $this->escribirValorMes($cid, $idMes, $c['id'], $c['campos'], $c['existe'], $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception(self::errorSql('No se pudo confirmar el guardado del mes'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return count($plan['cambios']);
    }

    /**
     * Copia un mes cargado a otros, en una transaccion: todos o ninguno.
     * Ver planCopia().
     *
     * @return int Cuantos meses se escribieron
     */
    public function copiarMes($canal, $origen, $destinos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablasMes();

        $canal = strtoupper(trim((string) $canal));
        $plan = self::planCopia($this->getNodos(), $this->getConfig(), $canal, $origen, $destinos);
        $cid = $this->conexionEscritura();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception(self::errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($plan as $mes => $p) {
                $idMes = $this->asegurarMes($cid, $canal, $mes, $p['id'], $usuario);

                foreach ($p['cambios'] as $c) {
                    $this->escribirValorMes($cid, $idMes, $c['id'], $c['campos'], $c['existe'], $usuario);
                }
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception(self::errorSql('No se pudo confirmar la copia'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return count($plan);
    }

    /**
     * "Usar el constante en este mes": da de baja el mes, sin borrar nada.
     * Sus valores quedan, y si se vuelve a cargar se reactiva la misma fila.
     */
    public function quitarMes($canal, $mes, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablasMes();

        $canal = strtoupper(trim((string) $canal));
        $mes = self::validarMes($mes);
        $config = $this->getConfig();

        if (!self::mesCargado($config, $canal, $mes)) {
            throw new Exception(self::nombreMes($mes) . ' de ' . self::nombreCanal($canal)
                . ' ya usa el mix constante.');
        }

        $this->bajaMes($this->conexionEscritura(), $config['meses'][$canal][$mes]['id'], $usuario);
    }

    /** El modo de un canal: UPDATE, o INSERT si el canal no tenia fila */
    protected function escribirModo($cid, $canal, $modo, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE " . self::TABLA_CANAL . " SET MODO = ?, " . Auditoria::SET_MODIF . " WHERE CANAL = ?;
             IF @@ROWCOUNT = 0
                 INSERT INTO " . self::TABLA_CANAL . " (CANAL, MODO, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?);",
            [$modo, $usuario, $canal, $canal, $modo, $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al guardar el modo del mix'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * La fila del mes, activa: la reactiva si existia -limpiando la baja- o
     * la crea.
     *
     * @return int ID_MIX_MES
     */
    protected function asegurarMes($cid, $canal, $mes, $idMes, $usuario) {
        if ($idMes !== null) {
            $stmt = sqlsrv_query($cid,
                "UPDATE " . self::TABLA_MES . " SET ACTIVO = 1, USUARIO_BAJA = NULL, FECHA_BAJA = NULL, "
                . Auditoria::SET_MODIF . " WHERE ID = ?",
                [$usuario, intval($idMes)]);

            if ($stmt === false) {
                throw new Exception(self::errorSql('Error al cargar el mes del mix'));
            }

            sqlsrv_free_stmt($stmt);

            return intval($idMes);
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO " . self::TABLA_MES . " (CANAL, MES, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
             OUTPUT INSERTED.ID
             VALUES (?, ?, 1, ?, ?)",
            [$canal, intval($mes), $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al cargar el mes del mix'));
        }

        $nuevo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($nuevo['ID']);
    }

    /**
     * Los valores de un nodo en un mes: UPDATE de las columnas que cambiaron,
     * o INSERT con todas si el nodo no tenia fila en ese mes.
     *
     * Las columnas salen de CAMPOS_MES, una lista cerrada. Si cambia ACTIVO
     * en un UPDATE, la baja se sella o se limpia con el criterio del modulo.
     */
    protected function escribirValorMes($cid, $idMes, $idNodo, $campos, $existe, $usuario) {
        if (!$existe) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO " . self::TABLA_NODO_MES . "
                    (ID_MIX_MES, ID_NODO, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION, ACTIVO,
                     USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [intval($idMes), intval($idNodo), $campos['PORCENTAJE'], $campos['COSTO'], $campos['TASA'],
                 $campos['DIAS_ACREDITACION'], $campos['ACTIVO'] ? 1 : 0, $usuario, $usuario]);
        } else {
            $sets = [];
            $params = [];

            foreach ($campos as $col => $valor) {
                if (in_array($col, ['PORCENTAJE', 'COSTO', 'TASA', 'DIAS_ACREDITACION'], true)) {
                    $sets[] = $col . ' = ?';
                    $params[] = $valor;
                }
            }

            if (array_key_exists('ACTIVO', $campos)) {
                $sets[] = Auditoria::sqlBajaSegunEstado('ACTIVO');
                $params = array_merge($params, Auditoria::paramsBajaSegunEstado($campos['ACTIVO'], $usuario));
                $sets[] = 'ACTIVO = ?';
                $params[] = $campos['ACTIVO'] ? 1 : 0;
            }

            $sets[] = Auditoria::SET_MODIF;
            $params[] = $usuario;
            $params[] = intval($idMes);
            $params[] = intval($idNodo);

            $stmt = sqlsrv_query($cid, "UPDATE " . self::TABLA_NODO_MES . " SET " . implode(', ', $sets)
                . " WHERE ID_MIX_MES = ? AND ID_NODO = ?", $params);
        }

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al guardar el nodo ' . $idNodo . ' en el mes'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** La baja logica de un mes: ACTIVO = 0, con quien y cuando */
    protected function bajaMes($cid, $idMes, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE " . self::TABLA_MES . " SET ACTIVO = 0, " . Auditoria::SET_BAJA . " WHERE ID = ?",
            [$usuario, $usuario, intval($idMes)]);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al volver el mes al mix constante'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * La conexion donde se escribe.
     *
     * Protegida, junto con escribirNodo(), para probar el guardado sin tocar
     * la tabla real: la prueba la reemplaza por la suya y registra que se
     * escribe. Ver tests/test_mix_cobro_guardado.php.
     *
     * @return resource
     */
    protected function conexionEscritura() {
        return $this->conectar();
    }

    /**
     * Un UPDATE con SOLO las columnas que cambiaron, mas la auditoria.
     *
     * Las columnas salen de resolverCambios(), que solo devuelve las del
     * nodo, asi que lo que se concatena al SQL es una lista cerrada. Si cambia
     * ACTIVO, se sella la baja o se limpia con el mismo criterio que el resto
     * del modulo (Auditoria::sqlBajaSegunEstado).
     *
     * @param resource $cid
     * @param int $id
     * @param array $campos COLUMNA => valor
     * @param string $usuario
     */
    protected function escribirNodo($cid, $id, $campos, $usuario) {
        $columnas = ['NOMBRE', 'NIVEL', 'PORCENTAJE', 'COSTO', 'TASA', 'DIAS_ACREDITACION'];
        $sets = [];
        $params = [];

        foreach ($campos as $col => $valor) {
            if (in_array($col, $columnas, true)) {
                $sets[] = $col . ' = ?';
                $params[] = $valor;
            }
        }

        if (array_key_exists('ACTIVO', $campos)) {
            $sets[] = Auditoria::sqlBajaSegunEstado('ACTIVO');
            $params = array_merge($params, Auditoria::paramsBajaSegunEstado($campos['ACTIVO'], $usuario));
            $sets[] = 'ACTIVO = ?';
            $params[] = $campos['ACTIVO'] ? 1 : 0;
        }

        $sets[] = Auditoria::SET_MODIF;
        $params[] = $usuario;
        $params[] = intval($id);

        $stmt = sqlsrv_query($cid, "UPDATE " . self::TABLA . " SET " . implode(', ', $sets)
            . " WHERE ID = ?", $params);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al guardar el nodo ' . $id . ' del mix'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** El mensaje de un error de sqlsrv, con su contexto */
    private static function errorSql($contexto) {
        $errorMsg = $contexto . ': ';

        foreach ((sqlsrv_errors() ?: []) as $error) {
            $errorMsg .= $error['message'] . ' ';
        }

        return $errorMsg;
    }
}
