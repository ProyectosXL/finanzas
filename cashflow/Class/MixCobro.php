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
     * @param array $nodos Nodos normalizados de todos los canales
     * @return array ['nodos' => [clave => nodo resuelto] en orden de dibujo,
     *               'canales' => [CANAL => ['hojas' => [claves], 'grupos' => [...],
     *               'errores' => [...], 'valido' => bool]], 'errores' => [...],
     *               'valido' => bool]
     */
    public static function resolver($nodos) {
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
     * @return array ['origen' => 'NODO'|'PLANO', 'nodos' => [...]]
     */
    public function getArbol() {
        if ($this->tablaCreada()) {
            return ['origen' => 'NODO', 'nodos' => $this->getNodos()];
        }

        $parametros = new Parametros();

        return ['origen' => 'PLANO', 'nodos' => self::desdeMixPlano($parametros->getMixCobro())];
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
     * @param array $nodos Nodos normalizados
     * @param string $origen 'NODO' | 'PLANO'
     * @return array
     */
    public static function datosEditor($nodos, $origen) {
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

        $niveles = [];

        foreach (self::NIVELES as $codigo => $rotulo) {
            $niveles[] = ['codigo' => $codigo, 'rotulo' => $rotulo];
        }

        $primerNivel = [];

        foreach (Parametros::CANALES as $canal) {
            $primerNivel[$canal] = self::nivelesPermitidos($canal, null);
        }

        return [
            'origen' => $origen,
            'editable' => $origen === 'NODO',
            'canales' => Parametros::CANALES,
            'niveles' => $niveles,
            'niveles_primer' => $primerNivel,
            'nodos' => $lista,
            'resuelto' => $resuelto,
            // El mix plano no tiene la forma de un arbol -Ecommerce no cuelga
            // de un marketplace- y no se edita: no se le aplica la estructura.
            'estructura' => $origen === 'NODO' ? self::validarEstructura($nodos) : []
        ];
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
     * @param array $actuales Nodos normalizados de todos los canales
     * @param string $canal
     * @param array $filas Las de resolverCambios()
     * @return array ['canal', 'valido', 'errores' => [textos], 'nodos' => [clave =>
     *               nodo resuelto], 'grupos' => [...], 'cambios' => int]
     */
    public static function previsualizar($actuales, $canal, $filas) {
        $canal = strtoupper(trim((string) $canal));

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
     * @param string $canal
     * @param array $filas Las de resolverCambios()
     * @param string $usuario El de AuthCashflow
     * @return int Cuantos nodos se escribieron
     */
    public function guardarCanal($canal, $filas, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        // Se valida ANTES de pedir la conexion de escritura: un arbol invalido
        // no llega a abrir la transaccion.
        $cambios = self::planGuardado($this->getNodos(), $canal, $filas);

        if (count($cambios) === 0) {
            return 0;
        }

        $cid = $this->conexionEscritura();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception(self::errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($cambios as $c) {
                $this->escribirNodo($cid, $c['id'], $c['campos'], $usuario);
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

        $sql = "INSERT INTO " . self::TABLA . "
                    (CANAL, ID_PADRE, NIVEL, NOMBRE, PORCENTAJE, COSTO, TASA, DIAS_ACREDITACION,
                     ACTIVO, ORDEN, USUARIO_ALTA, USUARIO_MODIF)
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?, ?)";

        $stmt = sqlsrv_query($this->conexionEscritura(), $sql, [
            $fila['CANAL'], $fila['ID_PADRE'], $fila['NIVEL'], $fila['NOMBRE'],
            $fila['COSTO'], $fila['TASA'], $fila['DIAS_ACREDITACION'],
            $orden + 1, $usuario, $usuario
        ]);

        if ($stmt === false) {
            throw new Exception(self::errorSql('Error al agregar el nodo al mix'));
        }

        $nuevo = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return intval($nuevo['ID']);
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
