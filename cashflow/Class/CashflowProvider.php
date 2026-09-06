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
            'warnings' => []
        ];

        if (!is_array($serie)) {
            return $out;
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

        return $out;
    }
}
