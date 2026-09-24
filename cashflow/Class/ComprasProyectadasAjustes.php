<?php

require_once __DIR__ . '/ComprasProyectadas.php';
require_once __DIR__ . '/ComprasProyectadasDatos.php';

/**
 * ComprasProyectadasAjustes
 * El alta y la baja del ajuste manual por mes. Es la UNICA clase del modulo que
 * escribe.
 *
 * POR QUE VIVE APARTE DE ComprasProyectadasDatos
 * ----------------------------------------------
 * Esa clase lee tres fuentes que son de OTRAS aplicaciones -el presupuesto de
 * compras, Tango y el maestro de Comercio Exterior- y la regla de que no
 * escribe ninguna es la que hay que poder verificar de un vistazo. Con el alta
 * adentro, "este archivo no tiene INSERT" deja de ser una prueba posible.
 *
 * Esta tabla, en cambio, es del cashflow: es una afirmacion del cashflow sobre
 * su propia proyeccion, igual que el tilde de pagado de Comex.
 *
 * QUE ES UN AJUSTE, Y QUE NO ES
 * -----------------------------
 * Un importe en U$S FOB que REEMPLAZA la estimacion automatica de un mes. No la
 * corrige ni la suma: la reemplaza. La nacionalizacion de ese mes se recalcula
 * sobre el importe ajustado, con el mismo porcentaje que usa el resto.
 *
 * NO es una correccion de la cuota ni del presupuesto. Si lo que esta mal es el
 * reparto entre meses, lo que hay que tocar son los parametros; si lo que esta
 * mal es el total de la temporada, hay que guardar otra version oficial en la
 * app de compras. El ajuste existe para lo que ninguno de los dos puede saber:
 * una compra puntual que alguien de Comercio Exterior ya sabe que va a entrar
 * en ese mes y que el presupuesto no refleja.
 *
 * SIN BAJAS FISICAS
 * -----------------
 * Corregir un ajuste marca VIGENTE = 0 el anterior e inserta uno nuevo. Con un
 * UPDATE, un dedazo corregido a los cinco minutos y una decision que estuvo
 * vigente tres semanas son indistinguibles despues del hecho. Mismo criterio
 * que RO_T_CASHFLOW_ECHEQ_EXCLUIDO y que el tilde de pagado de Comex.
 *
 * LA VERSION SE RESUELVE ACA, NO LLEGA DEL CLIENTE
 * ------------------------------------------------
 * El ajuste queda atado a la version oficial de la temporada de ese mes, y ese
 * id lo busca el servidor. Es lo que despues permite descartarlo solo cuando
 * la oficial cambia: si el cliente lo mandara, bastaria con mandar el id nuevo
 * para que un numero viejo siguiera aplicandose contra un presupuesto que no
 * miro nunca.
 *
 * Y POR ESO UN MES SIN VERSION OFICIAL NO SE PUEDE AJUSTAR. No hay a que atarlo:
 * el ajuste no tendria forma de caducar, y quedaria aplicandose para siempre
 * sobre una temporada que nadie presupuesto.
 */
class ComprasProyectadasAjustes {

    /** La tabla, que crea sql/cashflow_compras_proyectadas.sql */
    const TABLA = 'RO_T_CASHFLOW_COMPRAS_PROY_AJUSTE';

    /** Largo maximo del motivo, como lo declara la tabla */
    const MOTIVO_MAX = 300;

    /** @var Conexion */
    private $conn;

    /** @var ComprasProyectadasDatos */
    private $datos;

    function __construct($datos = null) {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->datos = ($datos === null) ? new ComprasProyectadasDatos : $datos;
    }

    /* ====================================================================
       VALIDACION PURA
       ==================================================================== */

    /**
     * Valida los datos de un ajuste. PURA: no toca la base.
     *
     * VIVE SEPARADA Y SIN BASE porque estas cuatro reglas son las que deciden
     * si un numero entra al tablero, y ninguna se puede verificar mirando la
     * pantalla: un mes mal escrito, un importe negativo o un mes que no esta en
     * la ventana producen un ajuste que se guarda bien y no aplica a nada.
     *
     * EL IMPORTE PUEDE SER CERO, y es un caso real: significa "este mes no se
     * compra nada", que es distinto de no tener ajuste. Lo que no puede es ser
     * negativo: un egreso negativo seria un ingreso que nadie afirmo.
     *
     * @param string $mes 'Y-m'
     * @param mixed $importe
     * @param string|null $motivo
     * @param array $mesesVentana Los meses que la ventana proyecta hoy
     * @return array Lista de errores. Vacia = valido
     */
    public static function validar($mes, $importe, $motivo, $mesesVentana = null) {
        $errores = [];

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)) {
            $errores[] = 'El mes tiene que venir como AAAA-MM.';
        } elseif (is_array($mesesVentana) && !in_array($mes, $mesesVentana, true)) {
            /* UN AJUSTE FUERA DE LA VENTANA NO APLICA A NADA. Se guardaria bien
               y no cambiaria ningun numero, asi que quien lo cargo creeria
               haber movido algo. La ventana se mueve con el tiempo y con los
               parametros, y eso esta bien: lo que no puede es aceptarse hoy
               sabiendo que hoy no aplica. */
            $errores[] = 'El mes ' . $mes . ' no está en la ventana que se proyecta hoy, '
                . 'así que un ajuste ahí no cambiaría ningún importe.';
        }

        if (!is_numeric($importe)) {
            $errores[] = 'El importe tiene que ser un número en dólares.';
        } elseif (floatval($importe) < 0) {
            $errores[] = 'El importe no puede ser negativo: sería un ingreso, no un egreso.';
        }

        /* EL MOTIVO ES OBLIGATORIO, igual que en la exclusion de cheques. Un
           ajuste reemplaza una cuenta que el sistema sabe hacer; meses despues,
           el motivo es lo unico que explica por que ese mes dice otra cosa. */
        $motivo = trim((string) $motivo);

        if ($motivo === '') {
            $errores[] = 'Hace falta el motivo: es lo único que después explica por qué ese '
                . 'mes no muestra la estimación automática.';
        }

        return $errores;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Carga o corrige el ajuste de un mes.
     *
     * @param string $mes 'Y-m' de RECEPCION
     * @param mixed $importeUsd
     * @param string $motivo
     * @param array $mesesVentana Meses que la ventana proyecta hoy
     * @param string|null $usuario
     * @return array ['mes','importe_usd','id_version','temporada']
     */
    public function guardar($mes, $importeUsd, $motivo, $mesesVentana = null, $usuario = null) {
        if (!$this->datos->tieneAjustes()) {
            throw new Exception($this->datos->avisoSinAjustes());
        }

        $errores = self::validar($mes, $importeUsd, $motivo, $mesesVentana);

        if ($errores) {
            throw new Exception(implode(' ', $errores));
        }

        $mes = substr((string) $mes, 0, 7);
        $importeUsd = round(floatval($importeUsd), 2);
        $motivo = mb_substr(trim((string) $motivo), 0, self::MOTIVO_MAX);

        /* LA VERSION LA RESUELVE EL SERVIDOR. Ver el encabezado: si viniera del
           cliente, mandar el id nuevo alcanzaria para revivir un ajuste viejo. */
        $temporada = ComprasProyectadas::temporada($mes);

        if ($temporada === null) {
            throw new Exception('No se pudo resolver la temporada de ' . $mes . '.');
        }

        $versiones = $this->datos->versionesOficiales();

        if (!isset($versiones[$temporada['codigo']])) {
            throw new Exception('La temporada ' . $temporada['codigo'] . ' no tiene versión '
                . 'oficial de presupuesto, así que no hay a qué atar el ajuste: no tendría '
                . 'forma de caducar cuando alguien guarde una. Marcá una versión oficial en '
                . 'la app de compras y volvé a intentar.');
        }

        $idVersion = intval($versiones[$temporada['codigo']]['id_version']);

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception('No se pudo abrir la transacción para guardar el ajuste');
        }

        try {
            /* LA BAJA Y EL ALTA VAN JUNTAS. El indice unico filtrado por
               VIGENTE prohibe dos ajustes vigentes del mismo mes, asi que si
               fueran dos escrituras sueltas y fallara la segunda, el mes
               quedaria SIN ajuste y con el anterior dado de baja: se perderia
               un importe sin que nadie lo haya pedido. */
            $this->ejecutar($cid,
                "UPDATE " . self::TABLA . "
                 SET VIGENTE = 0, FECHA_BAJA = GETDATE()
                 WHERE MES = ? AND VIGENTE = 1",
                [$mes],
                'Error al dar de baja el ajuste anterior');

            $this->ejecutar($cid,
                "INSERT INTO " . self::TABLA . "
                     (MES, IMPORTE_USD, ID_VERSION, TEMPORADA, MOTIVO, VIGENTE, USUARIO, FECHA_ALTA)
                 VALUES (?, ?, ?, ?, ?, 1, ?, GETDATE())",
                [$mes, $importeUsd, $idVersion, $temporada['codigo'], $motivo, $usuario],
                'Error al guardar el ajuste');

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return [
            'mes' => $mes,
            'importe_usd' => $importeUsd,
            'id_version' => $idVersion,
            'temporada' => $temporada['codigo']
        ];
    }

    /**
     * Saca el ajuste de un mes: el mes vuelve a la estimacion automatica.
     *
     * NO BORRA LA FILA. Marca la vigente y deja el rastro, que es lo que
     * permite despues contestar cuanto tiempo estuvo puesto ese numero.
     *
     * @param string $mes 'Y-m'
     * @return array ['mes','sin_cambios']
     */
    public function quitar($mes) {
        if (!$this->datos->tieneAjustes()) {
            throw new Exception($this->datos->avisoSinAjustes());
        }

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)) {
            throw new Exception('El mes tiene que venir como AAAA-MM.');
        }

        $mes = substr((string) $mes, 0, 7);

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "UPDATE " . self::TABLA . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE MES = ? AND VIGENTE = 1",
            [$mes]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al quitar el ajuste'));
        }

        $filas = sqlsrv_rows_affected($stmt);

        return ['mes' => $mes, 'sin_cambios' => ($filas < 1)];
    }

    /* ====================================================================
       LECTURA DEL HISTORIAL
       ==================================================================== */

    /**
     * El historial completo de un mes, o de todos: el vigente primero.
     *
     * INCLUYE LOS NO VIGENTES, que es el punto de guardarlos. Un ajuste que
     * estuvo puesto tres semanas y se saco explica un tablero de hace un mes;
     * sin el historial, ese tablero no se puede reconstruir.
     *
     * @param string|null $mes 'Y-m', o null para todos
     * @return array
     */
    public function historial($mes = null) {
        if (!$this->datos->tieneAjustes()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $sql = "SELECT ID, MES, IMPORTE_USD, ID_VERSION, TEMPORADA, MOTIVO,
                       VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA
                FROM " . self::TABLA;

        $params = [];

        if ($mes !== null) {
            $sql .= " WHERE MES = ?";
            $params[] = substr((string) $mes, 0, 7);
        }

        $sql .= " ORDER BY MES, VIGENTE DESC, FECHA_ALTA DESC";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de ajustes'));
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'id' => intval($row['ID']),
                'mes' => trim((string) $row['MES']),
                'importe_usd' => floatval($row['IMPORTE_USD']),
                'id_version' => intval($row['ID_VERSION']),
                'temporada' => ($row['TEMPORADA'] === null) ? null : trim((string) $row['TEMPORADA']),
                'motivo' => ($row['MOTIVO'] === null) ? '' : (string) $row['MOTIVO'],
                'vigente' => (intval($row['VIGENTE']) === 1),
                'usuario' => ($row['USUARIO'] === null) ? null : (string) $row['USUARIO'],
                'fecha_alta' => self::aFechaHora($row['FECHA_ALTA']),
                'fecha_baja' => self::aFechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    /* ====================================================================
       UTILIDADES
       ==================================================================== */

    /**
     * @param resource $cid
     * @param string $sql
     * @param array $params
     * @param string $contexto
     */
    private function ejecutar($cid, $sql, $params, $contexto) {
        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * @param mixed $v
     * @return string|null
     */
    private static function aFechaHora($v) {
        if ($v === null) {
            return null;
        }

        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : substr((string) $v, 0, 16);
    }

    /**
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $msg = $contexto . '.';

        foreach ((array) sqlsrv_errors() as $e) {
            if (isset($e['message'])) {
                $msg .= ' ' . $e['message'];
            }
        }

        return $msg;
    }
}
