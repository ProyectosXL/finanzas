<?php

require_once __DIR__ . '/Horizonte.php';

/**
 * CashflowProvider
 * Contrato que tiene que cumplir un modulo para alimentar el tablero de
 * Cashflow.
 *
 * QUE RESUELVE
 * ------------
 * El Cashflow no sabe -ni tiene que saber- como calcula sus importes cada
 * modulo. Solo le pide series ya resueltas contra el eje temporal comun.
 * Agregar un modulo al tablero es escribir una subclase de esta y registrarla
 * en CashflowRegistry; el motor no se toca.
 *
 * COMO SE IMPLEMENTA
 * ------------------
 * La subclase implementa calcular(), que PUEDE lanzar excepciones con total
 * libertad. Quien llama usa series(), que es final y envuelve a calcular() en
 * un try/catch.
 *
 * Esa division no es un detalle: es lo que hace cumplir la regla de que un
 * proveedor nunca puede tumbar el tablero. El tablero consolida varios
 * modulos, y en este codigo un parametro faltante lanza excepcion
 * (Parametros::num) y una conexion caida tambien. Si eso se propagara, un solo
 * modulo con problemas dejaria la pantalla entera en blanco. Con la regla en la
 * clase base en vez de en un comentario, la subclase no puede incumplirla por
 * olvido: el modulo que falla rinde ceros y deja un aviso, y el resto del
 * tablero sigue funcionando.
 *
 * QUE DEVUELVE
 * ------------
 * series() devuelve un mapa codigoSerie => serie, donde cada serie es:
 *
 *   'dias'            ['Y-m-d' => float]  todas las claves del eje, en cero
 *   'meses'           ['Y-m'   => float]  todas las claves del eje, en cero
 *   'moneda_origen'   'ARS' | 'USD'       informativo: en que moneda estaba
 *   'tipo_cambio'     float | null        con cual se convirtio, si se convirtio
 *   'fuera_horizonte' float               importe que quedo fuera del eje
 *   'sin_fecha'       float               importe sin fecha utilizable
 *   'warnings'        string[]            avisos propios de la serie
 *   'detalle'         array               anotaciones por columna (ver abajo)
 *   'por_fondo'       [clave => float]    cuanto de la serie corresponde a cada
 *                                         fondo de cobertura (ver abajo)
 *   'fondos'          [clave => string]   el nombre de cada fondo, si se sabe
 *
 * EL DETALLE POR FONDO: 'por_fondo' y 'fondos'
 * --------------------------------------------
 * Solo lo usan las series de la seccion Cobertura. Una serie de STOCK dice
 * cuanto stock aporta cada cuenta de fondo; la serie de USO dice cuanto se
 * aplico desde cada una. Las dos usan la misma clave (Fondos::claveFondo()),
 * y con eso el motor descuenta de cada fondo lo suyo y avisa por fondo cuando
 * se aplica de mas. Ver Cashflow::resolverCobertura().
 *
 * NO ES UN IMPORTE MAS: es como se reparte el total de la serie, y no entra en
 * ninguna suma. Es metadato, como 'detalle'.
 *
 * ESTAS DOS CLAVES SOBREVIVEN A normalizar() A PROPOSITO, y hay que tenerlo
 * presente al agregar otra: normalizar() arma la serie de salida con una lista
 * cerrada de claves, asi que cualquier cosa que un proveedor cuelgue de la
 * serie y no este en esa lista SE PIERDE EN SILENCIO. Asi paso con el reparto
 * por fondo de la cobertura: el proveedor lo colgaba, el motor lo esperaba, y
 * en el medio se descartaba; los avisos por fondo nunca llegaron a dispararse
 * contra la base real. Solo la prueba unitaria, que reemplaza pedirSeries()
 * y se saltea este paso, los veia funcionar.
 *
 * ANOTAR UNA CELDA: 'detalle'
 * ---------------------------
 * Mapa columna => ['importe' => float, 'nota' => string], con la columna en el
 * formato de Horizonte::columna() ('DIA|Y-m-d' o 'MES|Y-m').
 *
 *   'detalle' => [
 *       'DIA|2026-09-17' => ['importe' => 12345.67, 'nota' => 'De este importe...']
 *   ]
 *
 * Sirve para decir que UNA PARTE del importe de esa celda tiene algo que
 * contar. El caso que lo origino: en la cobranza proyectada de franquicias,
 * distinguir lo que sale del PPP -una estimacion estadistica- de lo que
 * Tesoreria pacto con el cliente por fuera de la app de cobranzas. Los dos
 * numeros tienen la misma pinta en el tablero y no significan lo mismo.
 *
 * NO ES UNA SERIE APARTE, y esa es la decision. Una serie nueva seria una fila
 * nueva del tablero, y esa fila sumaria un importe que la fila original ya
 * suma: doble conteo. 'detalle' es metadato SOBRE el mismo importe, no un
 * importe mas, asi que no entra en ninguna cuenta.
 *
 * Tampoco genera avisos: lo que cae fuera del eje ya lo informa la serie a la
 * que anota, porque son las mismas filas de origen.
 *
 * IMPORTANTE: los importes SIEMPRE se devuelven en pesos. Si el modulo maneja
 * otra moneda, la conversion la hace el proveedor y no el motor: el tipo de
 * cambio de un pago futuro es criterio de negocio del modulo que lo paga.
 * 'moneda_origen' y 'tipo_cambio' quedan para poder mostrar y auditar con que
 * valor se convirtio.
 *
 * 'fuera_horizonte' no es opcional: si un importe cae fuera del eje hay que
 * informarlo. Un tablero de consolidacion que informa de menos sin decirlo es
 * peor que uno que falla.
 */
abstract class CashflowProvider {

    /** @var array|null Cache del resultado: el motor pide las series una sola vez por pedido */
    private $cache = null;

    /** @var array Avisos acumulados */
    protected $warnings = [];

    /** @var string Codigo con el que el registro lo instancio */
    private $codigo;

    /**
     * El codigo lo inyecta CashflowRegistry::instanciar(). Una misma clase
     * puede estar registrada bajo varios codigos y servir series distintas
     * segun cual sea: es el caso de Comex, que atiende Proveedores Exterior y
     * Nacionalizaciones con consultas distintas. Asi cada instancia corre solo
     * la consulta de la serie que le toca.
     *
     * @param string $codigo Clave con la que figura en CashflowRegistry
     */
    public function __construct($codigo) {
        $this->codigo = (string) $codigo;
    }

    /**
     * Codigo con el que el proveedor esta registrado, por ejemplo 'VENTAS'.
     *
     * @return string
     */
    final public function codigo() {
        return $this->codigo;
    }

    /**
     * Calcula las series del modulo. PUEDE lanzar excepciones: series() las
     * atrapa.
     *
     * Se llama UNA sola vez por pedido, aunque el tablero tenga varias filas
     * apuntando a este proveedor, asi que conviene resolver todas las series de
     * una pasada. No hace falta devolver las claves del eje completas ni los
     * escalares: series() completa lo que falte.
     *
     * @param Horizonte $h Eje temporal contra el que hay que agrupar
     * @return array Mapa codigoSerie => ['dias' => [...], 'meses' => [...], ...]
     */
    abstract protected function calcular($h);

    /**
     * Series del modulo, ya normalizadas contra el eje. NO lanza nunca.
     *
     * @param Horizonte $h
     * @return array Mapa codigoSerie => serie
     */
    final public function series($h) {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->warnings = [];
        $crudas = [];

        try {
            $crudas = $this->calcular($h);

            if (!is_array($crudas)) {
                $crudas = [];
            }
        } catch (Throwable $e) {
            // Throwable y no Exception: tambien atrapa TypeError y compania.
            $this->warnings[] = $this->codigo() . ': no se pudo calcular ('
                . $e->getMessage() . '). Sus filas se muestran en cero.';
            $crudas = [];
        }

        $normalizadas = [];

        foreach ($crudas as $codigoSerie => $serie) {
            $normalizadas[$codigoSerie] = $this->normalizar($h, $serie);
        }

        $this->cache = $normalizadas;

        return $this->cache;
    }

    /**
     * Avisos acumulados en el ultimo series(). Incluye el de una falla atrapada.
     *
     * @return array
     */
    public function warnings() {
        return $this->warnings;
    }

    /**
     * Deja un aviso no fatal. Para usar desde calcular().
     *
     * @param string $mensaje
     */
    protected function avisar($mensaje) {
        $this->warnings[] = $mensaje;
    }

    /**
     * 'DIA|2026-09-06' => ['dias', '2026-09-06']. Devuelve [null, null] si el
     * id de columna no tiene la forma esperada.
     *
     * @param string $columna
     * @return array [rama, clave]
     */
    private static function partirColumna($columna) {
        if (strpos((string) $columna, 'DIA|') === 0) {
            return ['dias', substr($columna, 4)];
        }

        if (strpos((string) $columna, 'MES|') === 0) {
            return ['meses', substr($columna, 4)];
        }

        return [null, null];
    }

    /**
     * Completa una serie: todas las claves del eje, los escalares con su valor
     * por defecto, y los importes que vengan con una clave que no pertenece al
     * eje sumados a 'fuera_horizonte' en lugar de descartados.
     *
     * @param Horizonte $h
     * @param array $serie Serie tal como la devolvio calcular()
     * @return array Serie completa
     */
    private function normalizar($h, $serie) {
        $vacia = $h->serieVacia();

        $out = [
            'dias' => $vacia['dias'],
            'meses' => $vacia['meses'],
            'moneda_origen' => 'ARS',
            'tipo_cambio' => null,
            'fuera_horizonte' => 0,
            'sin_fecha' => 0,
            'warnings' => [],
            'detalle' => [],
            'por_fondo' => [],
            'fondos' => []
        ];

        if (!is_array($serie)) {
            return $out;
        }

        // El reparto por fondo viaja tal cual, con los importes como numeros y
        // los nombres como texto. Ver la nota del encabezado: lo que no esta en
        // esta lista se pierde, y esto ya se perdio una vez.
        if (isset($serie['por_fondo']) && is_array($serie['por_fondo'])) {
            foreach ($serie['por_fondo'] as $clave => $valor) {
                $out['por_fondo'][(string) $clave] = floatval($valor);
            }
        }

        if (isset($serie['fondos']) && is_array($serie['fondos'])) {
            foreach ($serie['fondos'] as $clave => $nombre) {
                $out['fondos'][(string) $clave] = (string) $nombre;
            }
        }

        foreach (['dias', 'meses'] as $rama) {
            if (!isset($serie[$rama]) || !is_array($serie[$rama])) {
                continue;
            }

            foreach ($serie[$rama] as $clave => $valor) {
                if (array_key_exists($clave, $out[$rama])) {
                    $out[$rama][$clave] = floatval($valor);
                } else {
                    $out['fuera_horizonte'] += floatval($valor);
                }
            }
        }

        if (isset($serie['moneda_origen'])) {
            $out['moneda_origen'] = $serie['moneda_origen'];
        }

        if (isset($serie['tipo_cambio'])) {
            $out['tipo_cambio'] = floatval($serie['tipo_cambio']);
        }

        foreach (['fuera_horizonte', 'sin_fecha'] as $clave) {
            if (isset($serie[$clave])) {
                $out[$clave] += floatval($serie[$clave]);
            }
        }

        if (isset($serie['warnings']) && is_array($serie['warnings'])) {
            $out['warnings'] = array_values($serie['warnings']);
        }

        // Solo sobreviven las anotaciones de columnas que EXISTEN en el eje.
        // Una anotacion sobre una columna que no se dibuja no se puede ver, y
        // dejarla pasar haria creer que el importe esta anotado en algun lado.
        if (isset($serie['detalle']) && is_array($serie['detalle'])) {
            foreach ($serie['detalle'] as $columna => $info) {
                list($rama, $clave) = self::partirColumna($columna);

                if ($rama !== null && array_key_exists($clave, $out[$rama])) {
                    $out['detalle'][$columna] = [
                        'importe' => isset($info['importe']) ? floatval($info['importe']) : 0,
                        'nota' => isset($info['nota']) ? (string) $info['nota'] : ''
                    ];
                }
            }
        }

        return $out;
    }
}
