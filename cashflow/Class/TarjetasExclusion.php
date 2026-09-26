<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/Proveedores.php';
require_once __DIR__ . '/TarjetasFactura.php';

/**
 * TarjetasExclusion
 * Que factura NO entra a Tarjetas Pagos Corporativos, y por que.
 *
 * ES DE ESTA PESTANA, Y NO TOCA LA EXCLUSION DE PROVEEDORES LOCALES
 * ----------------------------------------------------------------
 * Son dos decisiones distintas sobre la misma factura, y por eso son dos tablas:
 *
 *   RO_T_CASHFLOW_PROV_LOCALES_PAGO.EXCLUIDA   "no entra a Cuentas a Pagar
 *                                               Locales"
 *   esta tabla                                 "no entra a Tarjetas Pagos
 *                                               Corporativos"
 *
 * Compartir la tabla obligaria a que excluir de una pestana excluyera de la otra,
 * que es exactamente lo que no se quiere: estas facturas viven en las dos, en
 * series que no conviven en el tablero.
 *
 * PARA QUE SE USA, HOY
 * --------------------
 * El caso concreto es el DOBLE CONTEO con los gastos con tarjeta de las
 * supervisoras. Esos gastos se estiman en Gastos Supervisoras a partir de
 * RO_T_GASTOS_SUPERVISION, y los comprobantes de esos mismos gastos pueden estar
 * cargados en Tango como facturas de un proveedor con forma de pago TARJETA CORP.
 * Cuando eso pasa, el mismo peso entra dos veces.
 *
 * NO SE RESUELVE POR CODIGO: decidir cual de las dos puntas es la buena para un
 * comprobante concreto es mirar el comprobante, no aplicar una regla. Se maneja
 * excluyendo esas facturas con esta tabla, y se evalua en produccion.
 *
 * EL MOTIVO ES OBLIGATORIO
 * ------------------------
 * Y se exige aca y no solo en la pantalla: el endpoint es alcanzable sin pasar por
 * ella. Sacar plata del tablero sin decir por que no lo explica nadie tres meses
 * despues. Mismo criterio que ProveedoresExclusion::validarMotivo() y que el
 * override del cronograma de pagos.
 *
 * SIN BAJAS FISICAS, E HISTORIAL COMPLETO
 * ---------------------------------------
 * Volver a incluir marca VIGENTE = 0 y sella FECHA_BAJA con quien lo hizo. Con un
 * DELETE, "esta factura nunca se excluyo" y "se excluyo y se volvio atras" serian
 * indistinguibles despues del hecho, y lo segundo es lo que explica por que la
 * fila del tablero cambio de importe.
 *
 * EXCLUIR LO YA EXCLUIDO NO ES UN CAMBIO DE MOTIVO
 * ------------------------------------------------
 * Se ignora en silencio dentro de un lote -excluir ocho de las cuales tres ya
 * estaban es una operacion razonable- pero NO se pisa el motivo: pisarlo borraria
 * el por que de la decision que estaba vigente. Para cambiarlo se incluye y se
 * vuelve a excluir, y quedan las dos en el historial. Mismo criterio que
 * ProveedoresExclusion::excluir(), con la diferencia de que ahi es de a uno y
 * lanza, y aca el gesto es masivo y lanzar por una que ya estaba abortaria las
 * otras siete.
 */
class TarjetasExclusion {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_FACTURA_EXCLUIDA';

    /** Tope del motivo, el de la columna */
    const LARGO_MOTIVO = 200;

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
       LO PURO
       ==================================================================== */

    /**
     * El motivo, recortado y validado.
     *
     * Vacio o solo espacios es un motivo que FALTA, no un motivo: la tabla tiene
     * un CHECK por lo mismo.
     *
     * Estatica y pura.
     *
     * @param mixed $motivo
     * @param int $cuantas Cuantas facturas, para el mensaje
     * @return string
     */
    public static function validarMotivo($motivo, $cuantas = 1) {
        $m = trim((string) $motivo);

        if ($m === '') {
            throw new Exception('Poné el motivo por el que ' . ($cuantas === 1
                    ? 'esta factura no entra' : 'estas facturas no entran')
                . ' a Pagos con Tarjetas y Otros. Sin motivo, dentro de tres meses nadie va a '
                . 'poder explicar por qué falta ese importe.');
        }

        return mb_substr($m, 0, self::LARGO_MOTIVO);
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
            'Todavía no se puede excluir una factura de esta pestaña: falta la tabla '
            . self::TABLA . '. Corré sql/cashflow_tarjetas_facturas.sql contra la base central. '
            . 'Mientras tanto no hay ninguna excluida y todo entra como hasta ahora.';
    }

    /**
     * Las exclusiones vigentes: mapa clave de comprobante => fila.
     *
     * UNA CONSULTA POR PEDIDO, y no una por factura.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar: nadie esta excluido, que es
     * lo cierto. Mismo criterio que ProveedoresExclusion::vigentes().
     *
     * @return array Mapa 'COD|T|N' => ['ID', 'MOTIVO', 'USUARIO_ALTA', 'FECHA_ALTA']
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
            "SELECT ID, COD_PROVEE, T_COMP, N_COMP, MOTIVO, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las facturas excluidas'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = Proveedores::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);

            $this->vigentes[$clave] = [
                'ID' => intval($row['ID']),
                'MOTIVO' => $row['MOTIVO'],
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->vigentes;
    }

    /**
     * El historial de un comprobante: las exclusiones que tuvo, vigentes y dadas
     * de baja, de la mas nueva a la mas vieja.
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
            "SELECT MOTIVO, VIGENTE, USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?
             ORDER BY ID DESC",
            [Planilla::codigo($codProvee), Planilla::codigo($tComp), Planilla::codigo($nComp)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de exclusiones'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'MOTIVO' => $row['MOTIVO'],
                'VIGENTE' => (intval($row['VIGENTE']) === 1),
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
     * Excluye VARIAS facturas con UN SOLO MOTIVO, en una sola transaccion.
     *
     * EL MOTIVO ES UNO PARA TODAS, y eso no es una simplificacion: excluir ocho
     * facturas del mismo proveedor es UNA decision, y ocho motivos distintos para
     * una decision son ocho oportunidades de que digan cosas distintas. Mismo
     * criterio que Proveedores::saveExclusionMasiva().
     *
     * ES UNA SOLA TRANSACCION: sacar del cashflow ocho facturas con ocho llamadas
     * deja la puerta abierta a que la quinta falle y el tablero quede a mitad de
     * camino sin que nadie se entere. O entran todas o ninguna.
     *
     * LAS QUE YA ESTABAN EXCLUIDAS SE SALTEAN Y NO SE PISA SU MOTIVO. Ver el
     * encabezado: lanzar por una que ya estaba abortaria las otras siete, y pisar
     * el motivo borraria el por que de la decision vigente. La respuesta dice
     * cuantas se saltearon.
     *
     * @param array $comprobantes Filas con 'cod_provee', 't_comp', 'n_comp'
     * @param string $motivo
     * @param string|null $usuario
     * @return array ['excluidas' => int, 'ya_estaban' => int, 'motivo' => string]
     */
    public function excluir($comprobantes, $motivo, $usuario = null) {
        $this->exigirTabla();

        // Todo se valida antes de escribir nada.
        $claves = TarjetasFactura::normalizarClaves($comprobantes, 'excluir');
        $m = self::validarMotivo($motivo, count($claves));

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        $nuevas = 0;
        $yaEstaban = 0;

        try {
            foreach ($claves as $c) {
                if ($this->estaExcluida($cid, $c[0], $c[1], $c[2])) {
                    $yaEstaban++;
                    continue;
                }

                $stmt = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA . "
                        (COD_PROVEE, T_COMP, N_COMP, MOTIVO, VIGENTE,
                         USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, 1, ?, ?)",
                    [$c[0], $c[1], $c[2], $m, $usuario, $usuario]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al excluir la factura '
                        . $c[1] . ' ' . $c[2]));
                }

                sqlsrv_free_stmt($stmt);
                $nuevas++;
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la exclusión'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return ['excluidas' => $nuevas, 'ya_estaban' => $yaEstaban, 'motivo' => $m];
    }

    /**
     * Vuelve a incluir VARIAS facturas, en una sola transaccion.
     *
     * NO BORRA NADA: marca VIGENTE = 0 y sella FECHA_BAJA. USUARIO_ALTA no se pisa,
     * porque dice quien excluyo, que es otra persona y otra decision.
     *
     * @param array $comprobantes
     * @param string|null $usuario
     * @return array ['incluidas' => int, 'pedidas' => int]
     */
    public function incluir($comprobantes, $usuario = null) {
        $this->exigirTabla();

        $claves = TarjetasFactura::normalizarClaves($comprobantes, 'volver a incluir');
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        $tocadas = 0;

        try {
            foreach ($claves as $c) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA . "
                     SET VIGENTE = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                         USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND VIGENTE = 1",
                    [$usuario, $usuario, $c[0], $c[1], $c[2]]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al volver a incluir la factura '
                        . $c[1] . ' ' . $c[2]));
                }

                $tocadas += intval(sqlsrv_rows_affected($stmt));
                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la inclusión'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return ['incluidas' => $tocadas, 'pedidas' => count($claves)];
    }

    /**
     * Si un comprobante ya esta excluido, sobre la conexion que recibe.
     *
     * SE PIDE SOBRE ESA CONEXION y no sobre una nueva: dentro de una transaccion,
     * una conexion distinta no ve lo que la transaccion escribio.
     *
     * @return bool
     */
    private function estaExcluida($cid, $cod, $t, $n) {
        $stmt = sqlsrv_query($cid,
            "SELECT ID FROM dbo." . self::TABLA . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ? AND VIGENTE = 1",
            [$cod, $t, $n]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la exclusión'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return (bool) $row;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

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
