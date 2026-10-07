<?php

require_once __DIR__ . '/Aviso.php';

/**
 * DirectorioFranquicias
 * Que clientes son el universo de Cobranzas Franquicias, segun el directorio de
 * sucursales.
 *
 * LA DEFINICION
 * -------------
 * Una franquicia es un cliente de Tango cuyo codigo empieza con F o con L
 * (COD_CLIENT LIKE '[FL]%'). Los L son locales con gestion asistida, un
 * modelo nuevo. Todas se cargan en SUCURSALES_LAKERS, el directorio de
 * sucursales del servidor 'locales'.
 *
 * Cobranzas Franquicias trabaja SOLO CON LAS HABILITADAS en ese directorio:
 * la tarjeta de Parametros, las dos solapas de Cobranzas FR y las series del
 * tablero. Una franquicia dada de baja con facturas abiertas ya no se proyecta
 * -antes si: "la proyeccion no se filtra" era la regla, y dejo de serlo-.
 *
 * UNA SOLA LECTURA, Y UNA SOLA REGLA
 * ----------------------------------
 * Antes el directorio lo leia solo la tarjeta, con una consulta privada de
 * Parametros. Ahora lo leen tambien la proyeccion y la cobranza real, y las
 * tres tienen que ver el mismo directorio y decidir con la misma regla: si no,
 * la tarjeta listaria a un cliente que la pestana no proyecta. Por eso la
 * lectura vive aca, UNA VEZ POR PEDIDO -el tablero pide la cobranza tres veces
 * por carga-, y la decision es un helper puro con prueba.
 *
 * La lectura trae TAMBIEN las filas inhabilitadas. Sin ellas no se podria
 * distinguir una franquicia dada de baja de un cliente que nadie cargo en el
 * directorio, y son dos avisos distintos para dos personas distintas.
 *
 * INFORMAR DE MAS ANTES QUE VACIO
 * -------------------------------
 * Si el servidor 'locales' no responde, o el directorio no devuelve ninguna
 * franquicia habilitada -que es un problema de la consulta y no un hecho-, no
 * se filtra: entran todas las [FL]%, con un aviso. Una tarjeta vacia o una
 * cobranza en cero por una caida ajena es peor que mostrar de mas diciendolo.
 *
 * LO QUE QUEDA AFUERA SE AVISA
 * ----------------------------
 * Las facturas que no se traen salen del tablero, y eso no puede pasar en
 * silencio. Son tres casos y cada uno lo resuelve alguien distinto:
 *
 *   INHABILITADA     -> dada de baja en el directorio. Es una decision.
 *   SIN_ESTADO       -> HABILITADO en NULL. Probablemente un error de carga:
 *                       nadie dijo que este dada de baja.
 *   SIN_DIRECTORIO   -> cliente [FL]% de Tango que no esta en el directorio:
 *                       falta darlo de alta.
 *
 * Los avisos nombran a cada cliente con su importe, para que Tesoreria pueda
 * revisarlos uno por uno.
 */
class DirectorioFranquicias {

    /**
     * Los canales del directorio que son franquicias. 'FRANQUICIAS GA' es el
     * de los locales con gestion asistida (los L).
     *
     * UN CANAL NUEVO ENTRA AGREGANDOLO ACA, y no porque su nombre se parezca:
     * un LIKE 'FRANQUICIAS%' sumaria al universo cualquier canal que alguien
     * cree con ese prefijo, sin que nadie haya decidido que sus facturas se
     * cobran por este circuito.
     */
    const CANALES = ['FRANQUICIAS', 'FRANQUICIAS GA'];

    /** El prefijo de codigo de cliente de las franquicias, como clase de LIKE */
    const PATRON_SQL = '[FL]%';

    const HABILITADA = 'HABILITADA';
    const INHABILITADA = 'INHABILITADA';
    const SIN_ESTADO = 'SIN_ESTADO';
    const SIN_DIRECTORIO = 'SIN_DIRECTORIO';

    /** Cuantos clientes nombra un aviso antes de resumir el resto */
    const MAX_NOMBRADOS = 15;

    /** @var array|null|false Cache por pedido. false = todavia no se leyo */
    private static $cache = false;

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * El directorio, leido una vez por pedido.
     *
     * Con el prefijo de Conexion::prefijoLocales() por el mismo motivo que en
     * Saldos: en DEV la tabla se alcanza por linked server. Cualquier falla
     * devuelve null y la decide filtrarUniverso(), que no deja nada vacio.
     *
     * @return array|null Lo que devuelve armar(), o null si no se pudo leer
     */
    public static function leer() {
        if (self::$cache !== false) {
            return self::$cache;
        }

        require_once __DIR__ . '/../../class/conexion.php';
        $conn = new Conexion;
        $cid = $conn->conectar('locales');

        if (!$cid) {
            error_log('DirectorioFranquicias: no se pudo conectar a locales');

            return self::$cache = null;
        }

        $p = method_exists($conn, 'prefijoLocales') ? $conn->prefijoLocales() : '';

        // Las dos listas son literales del codigo, no entrada del usuario.
        $sql = "SELECT NRO_SUCURSAL, COD_CLIENT, DESC_SUCURSAL, HABILITADO
                FROM {$p}SUCURSALES_LAKERS
                WHERE CANAL IN ('" . implode("', '", self::CANALES) . "')
                  AND NRO_SUC_MADRE IS NULL
                ORDER BY COD_CLIENT, NRO_SUCURSAL";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            $errores = sqlsrv_errors();
            error_log('DirectorioFranquicias: error al leer SUCURSALES_LAKERS: '
                . ($errores ? $errores[0]['message'] : ''));

            return self::$cache = null;
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return self::$cache = self::armar($filas);
    }

    /**
     * Fija el directorio de este pedido, sin leer la base. Para las pruebas.
     *
     * @param array|null $directorio Lo que devuelve armar(), o null (caido)
     */
    public static function fijar($directorio) {
        self::$cache = $directorio;
    }

    /** Olvida lo leido: el proximo leer() vuelve a la base. */
    public static function olvidar() {
        self::$cache = false;
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * Lleva las filas del directorio a lo que usan los dos lados: el estado de
     * cada cliente y sus sucursales habilitadas.
     *
     * UN CLIENTE CON VARIAS SUCURSALES tiene UN estado:
     *   - alguna habilitada                -> HABILITADA
     *   - si no, alguna dada de baja (0)   -> INHABILITADA
     *   - si no -todas en NULL-            -> SIN_ESTADO
     * Una baja explicita es un hecho y un NULL es la falta de un dato, por eso
     * con una de cada una manda la baja.
     *
     * La tarjeta muestra SOLO las sucursales habilitadas: la dada de baja de
     * un cliente que sigue operando no describe donde opera.
     *
     * Una fila sin COD_CLIENT no se puede cruzar y se ignora.
     *
     * @param array $filas Filas NRO_SUCURSAL, COD_CLIENT, DESC_SUCURSAL, HABILITADO
     * @return array ['estados' => COD => estado, 'sucursales' => mapaSucursales()]
     */
    public static function armar($filas) {
        $filas = is_array($filas) ? $filas : [];
        $marcas = [];
        $habilitadas = [];

        foreach ($filas as $f) {
            $cod = self::codigo(isset($f['COD_CLIENT']) ? $f['COD_CLIENT'] : '');

            if ($cod === '') {
                continue;
            }

            $h = array_key_exists('HABILITADO', $f) ? $f['HABILITADO'] : null;
            $marca = ($h === null || $h === '') ? 'nulo' : ((intval($h) === 1) ? 'si' : 'no');

            $marcas[$cod][$marca] = true;

            if ($marca === 'si') {
                $habilitadas[] = $f;
            }
        }

        $estados = [];

        foreach ($marcas as $cod => $m) {
            $estados[$cod] = isset($m['si']) ? self::HABILITADA
                : (isset($m['no']) ? self::INHABILITADA : self::SIN_ESTADO);
        }

        return ['estados' => $estados, 'sucursales' => self::mapaSucursales($habilitadas)];
    }

    /**
     * Lleva filas del directorio a un mapa por cliente.
     *
     * Un cliente con mas de una sucursal queda en UNA entrada, con los numeros
     * y las descripciones concatenados: la tarjeta es por cliente, no por
     * local. Una fila sin COD_CLIENT no se puede cruzar y se ignora.
     *
     * @param array $filas Filas NRO_SUCURSAL, COD_CLIENT, DESC_SUCURSAL
     * @return array Mapa COD_CLIENT => ['nro_sucursal', 'desc_sucursal', 'cant_sucursales']
     */
    public static function mapaSucursales($filas) {
        $mapa = [];

        foreach ((is_array($filas) ? $filas : []) as $f) {
            $cod = self::codigo(isset($f['COD_CLIENT']) ? $f['COD_CLIENT'] : '');

            if ($cod === '') {
                continue;
            }

            $nro = trim((string) (isset($f['NRO_SUCURSAL']) ? $f['NRO_SUCURSAL'] : ''));
            $desc = trim((string) (isset($f['DESC_SUCURSAL']) ? $f['DESC_SUCURSAL'] : ''));

            if (!isset($mapa[$cod])) {
                $mapa[$cod] = ['nro_sucursal' => [], 'desc_sucursal' => [], 'cant_sucursales' => 0];
            }

            if ($nro !== '') {
                $mapa[$cod]['nro_sucursal'][] = $nro;
            }

            if ($desc !== '') {
                $mapa[$cod]['desc_sucursal'][] = $desc;
            }

            $mapa[$cod]['cant_sucursales']++;
        }

        foreach ($mapa as $cod => $m) {
            $mapa[$cod]['nro_sucursal'] = implode(', ', $m['nro_sucursal']);
            $mapa[$cod]['desc_sucursal'] = implode(' / ', $m['desc_sucursal']);
        }

        return $mapa;
    }

    /**
     * Si el directorio se puede usar para filtrar. Caido (null) o sin ninguna
     * habilitada, no: ver "informar de mas antes que vacio" en el encabezado.
     *
     * @param array|null $directorio Lo que devuelve armar()
     * @return bool
     */
    public static function utilizable($directorio) {
        if (!is_array($directorio) || empty($directorio['estados'])) {
            return false;
        }

        return in_array(self::HABILITADA, $directorio['estados'], true);
    }

    /**
     * El estado de un cliente en el directorio.
     *
     * @param string $codCliente
     * @param array $directorio Lo que devuelve armar(), ya utilizable
     * @return string HABILITADA | INHABILITADA | SIN_ESTADO | SIN_DIRECTORIO
     */
    public static function clasificar($codCliente, $directorio) {
        $cod = self::codigo($codCliente);

        return isset($directorio['estados'][$cod]) ? $directorio['estados'][$cod] : self::SIN_DIRECTORIO;
    }

    /**
     * Se queda con los items de las franquicias habilitadas, y cuenta lo que
     * dejo afuera. ES LA UNICA IMPLEMENTACION DE LA REGLA, y la usan la
     * tarjeta (un item por cliente) y la cobranza (un item por factura).
     *
     * Con el directorio inutilizable devuelve TODO, con el aviso de por que.
     *
     * Conserva las claves de $items: la tarjeta trae un mapa por cliente y la
     * cobranza una lista, y cada una sigue recibiendo lo suyo.
     *
     * @param array $items
     * @param array|null $directorio Lo que devuelve armar(), o null si cayo
     * @param string $campoCliente Campo con el codigo de cliente
     * @param string|null $campoImporte Campo con el importe a informar, o null
     * @param string|null $campoCantidad Si un item agrupa varios comprobantes
     *        -los totales de la cobranza real vienen por fecha y cliente-, el
     *        campo que dice cuantos. null = uno por item.
     * @return array ['items', 'afuera' => estado => COD => ['cantidad', 'importe'],
     *                'avisos' => [Aviso WARNING], 'filtrado' => bool]
     */
    public static function filtrarUniverso($items, $directorio, $campoCliente, $campoImporte = null,
                                           $campoCantidad = null) {
        $items = is_array($items) ? $items : [];

        if (!self::utilizable($directorio)) {
            return [
                'items' => $items,
                'afuera' => [],
                'avisos' => [Aviso::nuevo(Aviso::WARNING, self::textoCaido($directorio))],
                'filtrado' => false
            ];
        }

        $quedan = [];
        $afuera = [];

        foreach ($items as $clave => $it) {
            $cod = self::codigo(isset($it[$campoCliente]) ? $it[$campoCliente] : '');
            $estado = self::clasificar($cod, $directorio);

            if ($estado === self::HABILITADA) {
                $quedan[$clave] = $it;
                continue;
            }

            if (!isset($afuera[$estado][$cod])) {
                $afuera[$estado][$cod] = ['cantidad' => 0, 'importe' => 0.0];
            }

            $afuera[$estado][$cod]['cantidad'] += ($campoCantidad !== null && isset($it[$campoCantidad]))
                ? intval($it[$campoCantidad]) : 1;
            $afuera[$estado][$cod]['importe'] += ($campoImporte !== null && isset($it[$campoImporte]))
                ? floatval($it[$campoImporte]) : 0.0;
        }

        // Una lista sigue siendo lista: sin esto, json_encode la mandaria al
        // navegador como objeto por los huecos en los indices.
        if (array_values($items) === $items) {
            $quedan = array_values($quedan);
        }

        return ['items' => $quedan, 'afuera' => $afuera, 'avisos' => [], 'filtrado' => true];
    }

    /**
     * Junta dos conteos de afuera(), para sumar la cobranza real y la
     * proyectada de un mismo pedido.
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    public static function sumarAfuera($a, $b) {
        $out = is_array($a) ? $a : [];

        foreach ((is_array($b) ? $b : []) as $estado => $clientes) {
            foreach ($clientes as $cod => $d) {
                if (!isset($out[$estado][$cod])) {
                    $out[$estado][$cod] = ['cantidad' => 0, 'importe' => 0.0];
                }

                $out[$estado][$cod]['cantidad'] += $d['cantidad'];
                $out[$estado][$cod]['importe'] += $d['importe'];
            }
        }

        return $out;
    }

    /**
     * Cuantos clientes quedaron afuera en cada caso. Es lo que dice el pie de
     * la tarjeta, que no informa importes: lista clientes, no facturas.
     *
     * @param array $afuera
     * @return array estado => int, con los tres estados siempre presentes
     */
    public static function clientesAfuera($afuera) {
        $out = [self::INHABILITADA => 0, self::SIN_ESTADO => 0, self::SIN_DIRECTORIO => 0];

        foreach ((is_array($afuera) ? $afuera : []) as $estado => $clientes) {
            $out[$estado] = count($clientes);
        }

        return $out;
    }

    /**
     * Los avisos de las facturas que quedaron afuera: uno por caso, INFO,
     * nombrando a cada cliente con su importe.
     *
     * INFO y no WARNING: que una franquicia dada de baja no se proyecte es la
     * regla, no un problema. Se dice porque saca plata del tablero, y nadie
     * tiene que enterarse mirando que el numero bajo.
     *
     * Los clientes van del de mayor importe al de menor, que es el orden en
     * que conviene revisarlos.
     *
     * @param array $afuera Lo que devuelve filtrarUniverso()
     * @return array Lista de Aviso INFO
     */
    public static function avisosAfuera($afuera) {
        $afuera = is_array($afuera) ? $afuera : [];
        $avisos = [];

        $casos = [
            self::INHABILITADA => [
                'uno' => 'franquicia inhabilitada en el directorio de sucursales',
                'varios' => 'franquicias inhabilitadas en el directorio de sucursales',
                'cierre' => 'Una franquicia dada de baja no se proyecta.'
            ],
            self::SIN_ESTADO => [
                'uno' => 'franquicia con el estado sin cargar en el directorio de sucursales (HABILITADO vacío)',
                'varios' => 'franquicias con el estado sin cargar en el directorio de sucursales (HABILITADO vacío)',
                'cierre' => 'Probablemente es un error de carga del directorio y no una baja: si siguen '
                    . 'operando, hay que marcarlas habilitadas.'
            ],
            self::SIN_DIRECTORIO => [
                'uno' => 'cliente [FL] de Tango que no está cargado en el directorio de sucursales',
                'varios' => 'clientes [FL] de Tango que no están cargados en el directorio de sucursales',
                'cierre' => 'Falta darlos de alta en el directorio (SUCURSALES_LAKERS).'
            ]
        ];

        foreach ($casos as $estado => $t) {
            if (empty($afuera[$estado])) {
                continue;
            }

            $clientes = $afuera[$estado];

            uasort($clientes, function ($x, $y) {
                return ($y['importe'] <=> $x['importe']);
            });

            $facturas = 0;
            $importe = 0.0;
            $nombres = [];

            foreach ($clientes as $cod => $d) {
                $facturas += $d['cantidad'];
                $importe += $d['importe'];

                if (count($nombres) < self::MAX_NOMBRADOS) {
                    $nombres[] = $cod . ' (' . self::plata($d['importe']) . ')';
                }
            }

            $resto = count($clientes) - count($nombres);
            $n = count($clientes);

            $avisos[] = Aviso::nuevo(Aviso::INFO,
                $facturas . ' factura' . ($facturas === 1 ? '' : 's') . ' por ' . self::plata($importe)
                . ' de ' . $n . ' ' . ($n === 1 ? $t['uno'] : $t['varios'])
                . ' no ' . ($facturas === 1 ? 'entra' : 'entran') . ' en Cobranzas FR ni en el tablero: '
                . implode(', ', $nombres) . ($resto > 0 ? ' y ' . $resto . ' más' : '') . '. '
                . $t['cierre']);
        }

        return $avisos;
    }

    /** El codigo de cliente como se compara: mayusculas y sin espacios */
    public static function codigo($cod) {
        return strtoupper(trim((string) $cod));
    }

    /** Por que no se filtra, segun como fallo el directorio */
    private static function textoCaido($directorio) {
        $motivo = ($directorio === null)
            ? 'No se pudo leer el directorio de sucursales (servidor \'locales\')'
            : 'El directorio de sucursales no devolvió ninguna franquicia habilitada';

        return $motivo . ': se trabaja con todas las franquicias [FL] de Tango, también las dadas '
            . 'de baja, así que Cobranzas FR y el tablero pueden mostrar de más.';
    }

    /** $ 1.234,56, para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }
}
