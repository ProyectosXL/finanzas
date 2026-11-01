<?php

require_once __DIR__ . '/Tarjetas.php';
require_once __DIR__ . '/TarjetasResumen.php';
require_once __DIR__ . '/AuthCashflow.php';

/**
 * TarjetasHistManual
 * El historico manual de Gastos Supervisoras: el gasto mensual de los ultimos 3
 * meses de una persona que tiene una tarjeta de tipo Supervisora y no carga
 * gastos en la app de supervision.
 *
 * LA PERSONA ES EL USUARIO DE LA VISTA, NO EL NOMBRE
 * -------------------------------------------------
 * Se guarda por ID_USUARIO de RO_V_CASHFLOW_USUARIOS_TARJETAS, el mismo que
 * lleva su tarjeta. No se cruza contra RO_T_SUPERVISORAS_COMERCIAL: quien carga
 * un historico manual justamente no esta ahi. Una persona fuera de la vista no
 * puede tener tarjeta, y por eso tampoco historico.
 *
 * TRES MESES, EN UNA TRANSACCION
 * ------------------------------
 * Se cargan los tres juntos o ninguno, por el mismo motivo que la base
 * historica de los resumenes: con dos, el promedio cambiaria sin que nadie se
 * entere. Volver a cargar da de baja el historico anterior y graba el nuevo.
 *
 * LA REGLA DE CALCULO NO VIVE ACA: la usa TarjetasSupervisoras, que es pura.
 */
class TarjetasHistManual {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_HIST_MANUAL';

    /** El script que la crea */
    const SCRIPT = 'sql/cashflow_tarjetas_historico_manual.sql';

    /** Cuantos meses forman el historico. Los mismos que la ventana */
    const MESES = 3;

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LO PURO: VALIDACION
       ==================================================================== */

    /**
     * Los renglones del historico, validados: exactamente tres meses distintos,
     * todos anteriores al mes en curso, con un importe de cero o mas, y que no
     * sumen cero.
     *
     * ANTERIORES AL MES EN CURSO por el mismo motivo que la ventana de las
     * supervisoras: el mes en curso esta incompleto, y promediarlo proyectaria de
     * menos.
     *
     * UN MES EN CERO ES UN DATO -ese mes no gasto- y se divide igual por tres.
     * TODO EN CERO, en cambio, no es un historico: proyectaria cero doce meses,
     * que es el cero que este modulo no pone.
     *
     * Estatica y pura.
     *
     * @param array $renglones Filas con 'mes' e 'importe'
     * @param string $hoy 'Y-m-d'
     * @return array Mapa 'Y-m' => float, en orden cronologico
     */
    public static function validar($renglones, $hoy) {
        $lista = is_array($renglones) ? array_values($renglones) : [];
        $mesActual = substr((string) $hoy, 0, 7);

        if (count($lista) !== self::MESES) {
            throw new Exception('El histórico son ' . self::MESES . ' meses, uno por renglón, y '
                . 'llegaron ' . count($lista) . '. No se cargó nada.');
        }

        $meses = [];

        foreach ($lista as $i => $r) {
            $n = $i + 1;
            $m = TarjetasResumen::validarMes(isset($r['mes']) ? $r['mes'] : null);
            $v = isset($r['importe']) ? $r['importe'] : null;

            if ($m >= $mesActual) {
                throw new Exception('Renglón ' . $n . ': ' . $m . ' no es un mes cerrado. El '
                    . 'histórico son meses anteriores al mes en curso. No se cargó nada.');
            }

            if (isset($meses[$m])) {
                throw new Exception('El mes ' . $m . ' viene dos veces. No se cargó nada.');
            }

            if ($v === null || $v === '' || !is_numeric($v)) {
                throw new Exception('Renglón ' . $n . ': falta el importe de ' . $m . '. Si ese '
                    . 'mes no gastó, poné 0. No se cargó nada.');
            }

            if (floatval($v) < 0) {
                throw new Exception('Renglón ' . $n . ': el importe no puede ser negativo. No se '
                    . 'cargó nada.');
            }

            $meses[$m] = round(floatval($v), 2);
        }

        if (array_sum($meses) <= 0) {
            throw new Exception('Los tres meses suman cero: no hay nada que proyectar. No se '
                . 'cargó nada.');
        }

        ksort($meses);

        return $meses;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio el script */
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
            'Todavía no existe ' . self::TABLA . ': corré ' . self::SCRIPT . ' contra la base '
            . 'central. Mientras tanto no se puede cargar el histórico manual de quien no carga '
            . 'gastos en la app de supervisión.';
    }

    /**
     * Los historicos vigentes, por usuario.
     *
     * VACIO SI FALTA EL SCRIPT, sin lanzar: no hay ningun historico cargado.
     *
     * @return array Mapa ID_USUARIO => ['id_usuario', 'nombre', 'meses' =>
     *               ['Y-m' => float], 'usuario_alta', 'fecha_alta']
     */
    public function vigentes() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID_USUARIO, NOMBRE_USUARIO, MES, IMPORTE, USUARIO_ALTA, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1
             ORDER BY ID_USUARIO, MES");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el histórico manual'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $id = intval($row['ID_USUARIO']);

            if (!isset($mapa[$id])) {
                $mapa[$id] = [
                    'id_usuario' => $id,
                    'nombre' => trim((string) $row['NOMBRE_USUARIO']),
                    'meses' => [],
                    'usuario_alta' => $row['USUARIO_ALTA'],
                    'fecha_alta' => ($row['FECHA_ALTA'] instanceof DateTime)
                        ? $row['FECHA_ALTA']->format('Y-m-d H:i') : $row['FECHA_ALTA']
                ];
            }

            $mapa[$id]['meses'][trim((string) $row['MES'])] = round(floatval($row['IMPORTE']), 2);
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Carga el historico de una persona: da de baja el vigente y graba los tres
     * meses, en una transaccion.
     *
     * LA PERSONA TIENE QUE ESTAR EN LA VISTA Y TENER UNA TARJETA SUPERVISORA
     * ACTIVA: sin tarjeta no hay dia de debito, y el historico no proyectaria
     * nada.
     *
     * @param mixed $idUsuario
     * @param array $renglones Filas con 'mes' e 'importe'
     * @param string $usuario
     * @param string $hoy 'Y-m-d'
     * @return array ['id_usuario', 'nombre', 'meses' => ['Y-m' => float]]
     */
    public function cargar($idUsuario, $renglones, $usuario, $hoy) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        $meses = self::validar($renglones, $hoy);
        $id = intval($idUsuario);

        $tarjetas = new Tarjetas();

        if (!$tarjetas->vistaUsuariosCreada()) {
            throw new Exception($tarjetas->avisoSinVista());
        }

        $usuarios = $tarjetas->usuarios();

        if (!isset($usuarios[$id])) {
            throw new Exception('El usuario ' . $id . ' no está en '
                . Tarjetas::VISTA_USUARIOS . ': el histórico manual es sólo para personas de '
                . 'esa vista.');
        }

        $conTarjeta = array_filter($tarjetas->getTarjetas(true, 'SUPERVISORA'),
            function ($t) use ($id) { return intval($t['ID_USUARIO']) === $id; });

        if (empty($conTarjeta)) {
            throw new Exception($usuarios[$id] . ' no tiene ninguna tarjeta de tipo Supervisora '
                . 'activa: sin tarjeta no hay día de débito, y el histórico no proyectaría nada. '
                . 'Dásela de alta en Parámetros › Tarjetas.');
        }

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $this->bajar($cid, $id, $usuario);

            foreach ($meses as $mes => $importe) {
                $stmt = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA . "
                        (ID_USUARIO, NOMBRE_USUARIO, MES, IMPORTE, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, 1, ?, ?)",
                    [$id, $usuarios[$id], $mes, $importe, $usuario, $usuario]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al cargar ' . $mes));
                }

                sqlsrv_free_stmt($stmt);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el histórico'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['id_usuario' => $id, 'nombre' => $usuarios[$id], 'meses' => $meses];
    }

    /**
     * Da de baja el historico vigente de una persona. No borra nada.
     *
     * @param mixed $idUsuario
     * @param string $usuario
     * @return array ['id_usuario']
     */
    public function darDeBaja($idUsuario, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        $this->bajar($this->conectar(), intval($idUsuario), $usuario);

        return ['id_usuario' => intval($idUsuario)];
    }

    /** Baja logica del historico vigente de un usuario, sobre la conexion dada */
    private function bajar($cid, $idUsuario, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE ID_USUARIO = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($idUsuario)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el histórico'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

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
