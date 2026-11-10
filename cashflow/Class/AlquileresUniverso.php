<?php

require_once __DIR__ . '/AlquileresFacturas.php';
require_once __DIR__ . '/Aviso.php';

/**
 * AlquileresUniverso
 * Lee lo que AlquileresFacturas necesita para decidir: las razones sociales
 * dadas de alta, los proveedores excluidos de Alquileres y si se cumple la
 * condicion de seguridad (modulo disponible, fila del tablero activa, tablas
 * del script).
 *
 * NO DECIDE NADA. La regla es AlquileresFacturas, que es pura; esto solo junta
 * los datos, una vez por pedido. Lo comparten Proveedores Locales, Tarjetas, la
 * pestaña Alquileres y los proveedores del tablero, que en un mismo pedido del
 * tablero preguntan los tres: leer tres veces no solo seria mas lento, podria
 * dar respuestas distintas si alguien guarda en el medio.
 *
 * NUNCA LANZA. Si algo no se puede leer, el contexto queda en el lado seguro y
 * avisa:
 *   - sin las razones, ninguna factura es de alquiler: todo queda en
 *     Proveedores Locales, que es como estaba antes de este modulo;
 *   - sin la estructura del tablero, la condicion no se cumple, por lo mismo.
 * En los dos casos ninguna factura desaparece del cuadro.
 */
class AlquileresUniverso {

    /** @var array|null El contexto de este pedido */
    private static $delPedido = null;

    /**
     * El contexto de este pedido, leido una sola vez.
     *
     * @return array Lo de AlquileresFacturas::contexto() + 'avisos'
     */
    public static function delPedido() {
        if (self::$delPedido === null) {
            self::$delPedido = (new self())->leer();
        }

        return self::$delPedido;
    }

    /**
     * Olvida el contexto leido. Para despues de una escritura que lo cambia
     * (dar de alta o de baja una razon social, excluir) y para las pruebas.
     */
    public static function olvidar() {
        self::$delPedido = null;
    }

    /**
     * Lee y arma el contexto.
     *
     * @return array
     */
    public function leer() {
        $avisos = [];

        return AlquileresFacturas::contexto(
            $this->razones($avisos),
            $this->excluidos($avisos),
            AlquileresFacturas::condicion($this->disponible(), $this->seriesActivas($avisos),
                                         $this->tablas($avisos))
        ) + ['avisos' => $avisos];
    }

    /** @return string[] Los codigos dados de alta en Parametros -> Alquileres */
    protected function razones(&$avisos) {
        try {
            require_once __DIR__ . '/AlquileresParametros.php';
            $par = new AlquileresParametros();

            /* Sin el script de Parametros -> Alquileres no hay nadie dado de
               alta, que es lo cierto: no hace falta avisar aca, ya lo dicen la
               pestaña y Parametros. */
            return $par->tablaCreada() ? array_keys($par->razonesVigentes()) : [];
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer las razones sociales dadas de '
                . 'alta en Parámetros › Alquileres (' . $e->getMessage() . '): mientras tanto ninguna '
                . 'factura se toma como alquiler y todas siguen en Proveedores Locales.');

            return [];
        }
    }

    /**
     * Los excluidos de Alquileres.
     *
     * HOY NO HAY NINGUNO: el modulo ALQUILERES de ProveedoresExclusion todavia no
     * existe (llega con sql/cashflow_prov_exclusion_modulos_ampliar.sql). Va
     * como metodo propio para que agregarlo sea cambiar solo esto.
     *
     * @return string[]
     */
    protected function excluidos(&$avisos) {
        return [];
    }

    /** @return bool Si el modulo ALQUILERES esta construido en el registro */
    protected function disponible() {
        require_once __DIR__ . '/CashflowRegistry.php';

        return CashflowRegistry::disponible(AlquileresFacturas::MODULO);
    }

    /**
     * Las series de las filas ACTIVAS del tablero que leen ALQUILERES.
     *
     * Una consulta a la configuracion, y no a CashflowEstructura: la condicion
     * solo necesita saber si la fila existe y esta activa, no validar la
     * estructura entera en cada pedido de Proveedores Locales.
     *
     * @return string[]
     */
    protected function seriesActivas(&$avisos) {
        try {
            $cid = $this->conectar();
            $stmt = sqlsrv_query($cid,
                "IF OBJECT_ID('dbo.RO_T_CASHFLOW_CONF_FILA', 'U') IS NOT NULL
                     SELECT ORIGEN_SERIE FROM dbo.RO_T_CASHFLOW_CONF_FILA
                     WHERE ORIGEN_PROVIDER = ? AND ACTIVO = 1",
                [AlquileresFacturas::MODULO]);

            if ($stmt === false) {
                throw new Exception(self::errores());
            }

            $series = [];

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $series[] = (string) $row['ORIGEN_SERIE'];
            }

            sqlsrv_free_stmt($stmt);

            return $series;
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer la estructura del tablero para saber '
                . 'si la fila de Alquileres está activa (' . $e->getMessage() . '): mientras tanto las '
                . 'facturas de alquiler siguen en Proveedores Locales.');

            return [];
        }
    }

    /** @return bool Si existen las tablas de sql/cashflow_alquileres_facturas.sql */
    protected function tablas(&$avisos) {
        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . AlquileresFacturas::TABLA_IMPUTACION . "', 'U') AS T");

            if ($stmt === false) {
                throw new Exception(self::errores());
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            return ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo verificar si ya se corrió '
                . AlquileresFacturas::SCRIPT . ' (' . $e->getMessage() . ').');

            return false;
        }
    }

    private function conectar() {
        require_once __DIR__ . '/../../class/conexion.php';
        $cid = (new Conexion())->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    private static function errores() {
        $msg = '';

        foreach (sqlsrv_errors() ?: [] as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
