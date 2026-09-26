<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/Proveedores.php';
require_once __DIR__ . '/Tarjetas.php';

/**
 * TarjetasFactura
 * Con que tarjeta se paga cada factura de tarjeta corporativa.
 *
 * QUE RESUELVE
 * ------------
 * Las facturas pendientes de Tango con forma de pago vigente 'TARJETA CORP' entran
 * al flujo por su vencimiento SIN necesidad de vincularlas. Vincularlas cambia dos
 * cosas, y ninguna es si entran:
 *
 *   1. GENERAN COBERTURA: el % de la tarjeta sobre la suma de sus facturas de cada
 *      mes sale como un renglon aparte.
 *   2. UN RESUMEN LAS PUEDE REEMPLAZAR: si se carga el resumen de esa tarjeta y
 *      ese mes, esas facturas ya estan adentro de ese importe.
 *
 * Y una tercera, que aparece cuando la factura esta vencida: sin tarjeta no hay
 * fecha de pago, asi que una vencida sin vincular NO se proyecta. Ver
 * Class/TarjetasCorporativas.php.
 *
 * LA CLAVE ES EL COMPROBANTE, LA MISMA QUE PROVEEDORES LOCALES
 * -----------------------------------------------------------
 * (COD_PROVEE, T_COMP, N_COMP), armada con Proveedores::clavePago(). NO incluye el
 * vencimiento: la tarjeta con la que se paga una factura es una propiedad de LA
 * FACTURA y no de cada cuota, y agregar la fecha habilitaria vincular la cuota 3 a
 * una tarjeta y la 4 a otra, que no describe nada real.
 *
 * Un comprobante en cuotas queda vinculado entero y cada cuota genera su cobertura
 * en el mes de SU vencimiento.
 *
 * SIN BAJAS FISICAS
 * -----------------
 * Desvincular marca ACTIVO = 0 y sella FECHA_BAJA con quien lo hizo. Con un
 * DELETE, "esta factura nunca estuvo vinculada" y "estuvo vinculada a otra
 * tarjeta" serian indistinguibles despues del hecho, y eso es justamente lo que
 * explica por que la cobertura de un mes cambio.
 *
 * VINCULAR ES MASIVO, Y ES UNA SOLA TRANSACCION
 * ---------------------------------------------
 * Por el mismo motivo que la exclusion masiva de Proveedores Locales: vincular las
 * ocho facturas de un proveedor con ocho llamadas deja la puerta abierta a que la
 * quinta falle y la cobertura del mes quede calculada a medias sin que nadie se
 * entere. O entran todas o ninguna.
 *
 * NO SE VALIDA CONTRA LOS PENDIENTES DE HOY, igual que la exclusion por factura de
 * Proveedores Locales: el vinculo vive por comprobante y puede existir para uno
 * que hoy no esta pendiente -se pago, o se anulo-. Borrarlo o rechazarlo perderia
 * la decision el dia que el comprobante vuelva.
 */
class TarjetasFactura {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_FACTURA';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    /** @var array|null Cache de vigentes() */
    private $vigentes = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_tarjetas_facturas.sql */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                return $this->tabla = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    /** @return string El aviso de script faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe ' . self::TABLA . ': corré sql/cashflow_tarjetas_facturas.sql '
            . 'contra la base central. La sub-pestaña se lee igual —las facturas salen de '
            . 'Tango— pero no se puede vincular ninguna a una tarjeta, así que no hay cobertura '
            . 'y ningún resumen puede reemplazar facturas.';
    }

    /**
     * Los vinculos vigentes: mapa clave de comprobante => ID_TARJETA.
     *
     * UNA CONSULTA POR PEDIDO, y no una por factura: el universo son 135
     * vencimientos y la sub-pestana los resuelve todos de una pasada.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar: no hay ningun vinculo, que
     * es lo cierto.
     *
     * @return array Mapa 'COD|T|N' => int
     */
    public function vigentes() {
        if ($this->vigentes !== null) {
            return $this->vigentes;
        }

        $this->vigentes = [];

        if (!$this->tablaCreada()) {
            return $this->vigentes;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, T_COMP, N_COMP, ID_TARJETA
             FROM dbo." . self::TABLA . "
             WHERE ACTIVO = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los vínculos factura-tarjeta'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = Proveedores::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);
            $this->vigentes[$clave] = intval($row['ID_TARJETA']);
        }

        sqlsrv_free_stmt($stmt);

        return $this->vigentes;
    }

    /**
     * El historial de un comprobante: a que tarjetas estuvo vinculado, con quien
     * y cuando.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @return array
     */
    public function historial($codProvee, $tComp, $nComp) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, ID_TARJETA, ACTIVO, USUARIO_ALTA, FECHA_ALTA,
                    USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?
             ORDER BY ID DESC",
            [Planilla::codigo($codProvee), Planilla::codigo($tComp), Planilla::codigo($nComp)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial del vínculo'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'ID' => intval($row['ID']),
                'ID_TARJETA' => intval($row['ID_TARJETA']),
                'ACTIVO' => (intval($row['ACTIVO']) === 1),
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => self::momento($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Vincula VARIAS facturas a UNA tarjeta, en una sola transaccion.
     *
     * UNA TARJETA PARA TODAS, y eso no es una simplificacion de la pantalla:
     * vincular ocho facturas a una tarjeta es UNA decision, y ocho tarjetas
     * distintas en un mismo gesto serian ocho decisiones sin forma de revisarlas.
     *
     * VINCULAR LO YA VINCULADO A OTRA TARJETA LO MUEVE, y deja las dos en el
     * historial: es una correccion legitima -se cargo la tarjeta equivocada- y
     * rechazarla obligaria a desvincular primero, que son dos gestos para una
     * correccion.
     *
     * TODO SE VALIDA ANTES DE ABRIR LA TRANSACCION: un comprobante mal
     * identificado en el renglon once no puede descubrirse con diez ya escritos.
     * Mismo criterio que Proveedores::normalizarClaves().
     *
     * @param array $comprobantes Filas con 'cod_provee', 't_comp', 'n_comp'
     * @param mixed $idTarjeta
     * @param string|null $usuario
     * @return array ['id_tarjeta', 'vinculadas' => int, 'movidas' => int]
     */
    public function vincular($comprobantes, $idTarjeta, $usuario = null) {
        $this->exigirTabla();

        $id = intval($idTarjeta);
        $this->exigirTarjeta($id);

        $claves = self::normalizarClaves($comprobantes, 'vincular');

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        $movidas = 0;

        try {
            foreach ($claves as $c) {
                $movidas += $this->escribir($cid, $c[0], $c[1], $c[2], $id, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el vínculo'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return ['id_tarjeta' => $id, 'vinculadas' => count($claves), 'movidas' => $movidas];
    }

    /**
     * Desvincula VARIAS facturas, en una sola transaccion.
     *
     * NO BORRA NADA: marca ACTIVO = 0 con quien y cuando.
     *
     * @param array $comprobantes
     * @param string|null $usuario
     * @return array ['desvinculadas' => int]
     */
    public function desvincular($comprobantes, $usuario = null) {
        $this->exigirTabla();

        $claves = self::normalizarClaves($comprobantes, 'desvincular');

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        $tocadas = 0;

        try {
            foreach ($claves as $c) {
                $tocadas += $this->darDeBaja($cid, $c[0], $c[1], $c[2], $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la desvinculación'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return ['desvinculadas' => $tocadas, 'pedidas' => count($claves)];
    }

    /**
     * Escribe un vinculo: da de baja el vigente si apunta a otra tarjeta, e
     * inserta el nuevo.
     *
     * SI YA APUNTA A LA MISMA TARJETA NO HACE NADA. Reinsertar dejaria dos filas
     * en el historial diciendo lo mismo, y el indice unico filtrado por ACTIVO
     * ademas lo rechazaria.
     *
     * @return int 1 si movio un vinculo que existia, 0 si era nuevo o ya estaba
     */
    private function escribir($cid, $cod, $t, $n, $idTarjeta, $usuario) {
        $stmt = sqlsrv_query($cid,
            "SELECT ID, ID_TARJETA FROM dbo." . self::TABLA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ACTIVO = 1",
            [$cod, $t, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el vínculo'));
        }

        $actual = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $movida = 0;

        if ($actual) {
            if (intval($actual['ID_TARJETA']) === $idTarjeta) {
                return 0;
            }

            $this->darDeBaja($cid, $cod, $t, $n, $usuario);
            $movida = 1;
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA . "
                (COD_PROVEE, T_COMP, N_COMP, ID_TARJETA, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
             VALUES (?, ?, ?, ?, 1, ?, ?)",
            [$cod, $t, $n, $idTarjeta, $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al vincular la factura ' . $t . ' ' . $n));
        }

        sqlsrv_free_stmt($stmt);

        return $movida;
    }

    /** Marca ACTIVO = 0 el vinculo vigente de un comprobante. @return int filas tocadas */
    private function darDeBaja($cid, $cod, $t, $n, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET ACTIVO = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND ACTIVO = 1",
            [$usuario, $usuario, $cod, $t, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al desvincular la factura'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return intval($filas);
    }

    /* ====================================================================
       VALIDACION
       ==================================================================== */

    /**
     * Las claves de un lote de comprobantes, normalizadas y sin repetidos.
     *
     * SE RESUELVE ENTERO ANTES DE ABRIR NINGUNA TRANSACCION. Esta escrito con la
     * misma forma que Proveedores::normalizarClaves() -que es privada- y tiene que
     * rechazar exactamente lo mismo: que se considera una factura identificada.
     *
     * Estatica y pura.
     *
     * @param array $comprobantes
     * @param string $gesto Para el mensaje
     * @return array clave => [cod, t, n]
     */
    public static function normalizarClaves($comprobantes, $gesto) {
        $claves = [];

        foreach (is_array($comprobantes) ? $comprobantes : [] as $c) {
            $cod = Planilla::codigo(isset($c['cod_provee']) ? $c['cod_provee'] : '');
            $t = Planilla::codigo(isset($c['t_comp']) ? $c['t_comp'] : '');
            $n = Planilla::codigo(isset($c['n_comp']) ? $c['n_comp'] : '');

            if ($cod === '' || $t === '' || $n === '') {
                throw new Exception('Falta el proveedor o el comprobante en uno de los renglones.');
            }

            // Indexado por la clave: la misma factura mandada dos veces es una.
            $claves[Proveedores::clavePago($cod, $t, $n)] = [$cod, $t, $n];
        }

        if (empty($claves)) {
            throw new Exception('No llegó ninguna factura para ' . $gesto . '.');
        }

        return $claves;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * Lanza si la tarjeta no existe o esta inactiva.
     *
     * LA FK LO IMPIDE PARA LA QUE NO EXISTE, pero con un error que no explica
     * nada. Y para la INACTIVA no hay FK que ayude: vincular a una tarjeta de baja
     * dejaria la factura sin proyectar cobertura y sin que nada lo diga, porque
     * una tarjeta inactiva no entra en ningun calculo.
     */
    private function exigirTarjeta($idTarjeta) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ACTIVA FROM dbo." . Tarjetas::TABLA . " WHERE ID = ?", [$idTarjeta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tarjeta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('La tarjeta ' . $idTarjeta . ' no está cargada. Se da de alta en '
                . 'Parámetros › Tarjetas.');
        }

        if (intval($row['ACTIVA']) !== 1) {
            throw new Exception('La tarjeta ' . $idTarjeta . ' está dada de baja, así que no '
                . 'proyecta nada: vincular una factura a ella la dejaría sin cobertura y sin que '
                . 'nada lo diga. Reactivala en Parámetros › Tarjetas, o elegí otra.');
        }
    }

    /** Lanza si la tabla no existe */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** Lleva a 'Y-m-d H:i' lo que devuelve sqlsrv para un DATETIME */
    private static function momento($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 16);
    }

    /** @return resource Conexion a 'central' */
    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::TABLA . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
