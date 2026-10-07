<?php

require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/DirectorioFranquicias.php';

/**
 * CobranzasExclusion
 * Que franquicias quedan afuera de Cobranzas Franquicias por decision de una
 * persona, y por que.
 *
 * QUE RESUELVE
 * ------------
 * Hay clientes cuyas facturas no se van a cobrar por este circuito aunque la
 * franquicia siga habilitada: uno en gestion judicial, uno que refinancia por
 * fuera, uno que se cobra por otro lado. Hasta ahora la unica forma de
 * sacarlos del tablero era inhabilitarlos en el directorio de sucursales, que
 * es de otra gente y dice otra cosa: si el local opera, no si se le cobra.
 * CUALES excluir es una decision de negocio y no la toma el codigo.
 *
 * POR CLIENTE, Y ALCANZA A TODAS SUS FACTURAS
 * -------------------------------------------
 * Las emitidas y las que vengan, en las dos solapas -Real a Cobrar y
 * Pendientes Proyectados- y en todo lo que suma: el eje, el pie, los KPIs,
 * getCobranzasFRTotales() y las series del tablero. Se aplica en PHP, despues
 * de leer, con separar(): la tabla esta en central y las propuestas en apps,
 * y el cruce con Tango no se hace con un JOIN.
 *
 * SOBRE EL UNIVERSO, NO EN LUGAR DE EL
 * ------------------------------------
 * La exclusion se aplica despues del filtro del directorio
 * (DirectorioFranquicias): una franquicia inhabilitada ya no se trae, asi que
 * no hace falta excluirla. Son dos decisiones independientes con duenos
 * distintos.
 *
 * NO CAMBIA EL PPP
 * ----------------
 * El PPP del grupo empresario sigue contando al cliente excluido: mide como
 * paga el grupo, no si se le cobra. Ni la vista ni getPPPClientes() leen esta
 * tabla.
 *
 * HISTORIAL, COMO PROVEEDORES Y ECHEQS
 * ------------------------------------
 * Sin bajas fisicas. Incluir de nuevo marca VIGENTE = 0 y sella quien y cuando;
 * excluir de nuevo inserta otra fila. Ver
 * sql/cashflow_cobranzas_cliente_excluido.sql.
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * Sin la tabla, vigentes() devuelve vacio -nadie esta excluido, que es lo
 * cierto- y lo que falla, con el motivo, es excluir. La tarjeta deshabilita
 * el switch y dice que script falta. Mismo patron que ProveedoresExclusion.
 */
class CobranzasExclusion {

    const TABLA = 'RO_T_CASHFLOW_COBRANZAS_CLIENTE_EXCLUIDO';

    /** El script que la crea, para los mensajes */
    const SCRIPT = 'sql/cashflow_cobranzas_cliente_excluido.sql';

    /** Tope del motivo, el de la columna */
    const LARGO_MOTIVO = 200;

    /** Cuantos motivos distintos nombra el cartel antes de resumir */
    const MAX_MOTIVOS = 5;

    /** @var Conexion */
    private $conn;

    /** @var bool|null */
    private $tabla = null;

    /** @var array|null Cache de vigentes, por pedido */
    private $vigentes = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /** @return bool Si ya se corrio el script de la tabla */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de exclusiones'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /** @return string El aviso de que falta el script, o cadena vacia */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' : self::textoSinTabla();
    }

    /**
     * El aviso de que falta el script. Estatico para que la prueba lo lea sin
     * base.
     *
     * @return string
     */
    public static function textoSinTabla() {
        return 'Todavía no se puede excluir un cliente de Cobranzas Franquicias: falta la tabla '
            . self::TABLA . '. Corré ' . self::SCRIPT . ' contra la base central. Mientras tanto '
            . 'no hay ninguno excluido y todo se cobra como hasta ahora.';
    }

    /**
     * Las exclusiones vigentes.
     *
     * UNA CONSULTA POR PEDIDO, y no una por factura: la usan la proyeccion,
     * la cobranza real y los totales del tablero.
     *
     * @return array Mapa COD_CLIENT => ['MOTIVO', 'USUARIO', 'FECHA_ALTA']
     */
    public function vigentes() {
        if ($this->vigentes !== null) {
            return $this->vigentes;
        }

        if (!$this->tablaCreada()) {
            return $this->vigentes = [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_CLIENT, MOTIVO, USUARIO_ALTA AS USUARIO, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los clientes excluidos'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[DirectorioFranquicias::codigo($row['COD_CLIENT'])] = [
                'MOTIVO' => $row['MOTIVO'],
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->vigentes = $mapa;
    }

    /**
     * Excluye un cliente de Cobranzas Franquicias.
     *
     * EL CODIGO SE VALIDA CONTRA GVA14: un codigo que no existe no tiene
     * facturas que excluir, y la exclusion quedaria puesta sin hacer nada. Y
     * tiene que ser una franquicia ([FL]%): excluir a un mayorista de este
     * circuito no saca nada.
     *
     * EL MOTIVO ES OBLIGATORIO, y se valida aca y no en la pantalla: el
     * endpoint es alcanzable sin pasar por ella.
     *
     * EXCLUIR LO YA EXCLUIDO ES UN ERROR y no un cambio de motivo: pisar el
     * motivo en silencio borraria el por que de la decision que estaba
     * vigente. Para cambiarlo se incluye y se vuelve a excluir, y quedan las
     * dos en el historial.
     *
     * @param string $codCliente
     * @param string $motivo
     * @param string $usuario
     * @return array ['cod_cliente', 'razon_social', 'motivo']
     */
    public function excluir($codCliente, $motivo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $cod = self::validarCodigo($codCliente);
        $m = self::validarMotivo($motivo);

        if (!$this->tablaCreada()) {
            throw new Exception(self::textoSinTabla());
        }

        $razon = $this->razonSocial($cod);

        if ($razon === null) {
            throw new Exception('El cliente "' . $cod . '" no existe en GVA14, el maestro de clientes '
                . 'de Tango. No se excluyó nada.');
        }

        $vigentes = $this->vigentes();

        if (isset($vigentes[$cod])) {
            throw new Exception($cod . ' ya está excluido de Cobranzas Franquicias (motivo: '
                . $vigentes[$cod]['MOTIVO'] . '). Para cambiar el motivo, volvé a incluirlo y '
                . 'excluilo de nuevo: las dos decisiones quedan en el historial.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "INSERT INTO dbo." . self::TABLA . "
                 (COD_CLIENT, MOTIVO, USUARIO_ALTA, USUARIO_MODIF)
             VALUES (?, ?, ?, ?)",
            [$cod, $m, $usuario, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al excluir el cliente'));
        }

        sqlsrv_free_stmt($stmt);
        $this->vigentes = null;

        return ['cod_cliente' => $cod, 'razon_social' => $razon, 'motivo' => $m];
    }

    /**
     * Vuelve a incluir un cliente.
     *
     * NO BORRA NADA: marca la vigente como dada de baja con quien y cuando. El
     * motivo queda en el historial, que es donde tiene que estar: describe una
     * decision que estuvo vigente.
     *
     * @param string $codCliente
     * @param string $usuario
     * @return bool Si estaba excluido
     */
    public function incluir($codCliente, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $cod = self::validarCodigo($codCliente);

        if (!$this->tablaCreada()) {
            throw new Exception(self::textoSinTabla());
        }

        /* USUARIO_ALTA no se pisa: dice quien excluyo. Quien volvio a incluir
           va en USUARIO_BAJA, su propia columna. */
        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE COD_CLIENT = ? AND VIGENTE = 1",
            [$usuario, $usuario, $cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al volver a incluir el cliente'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        $this->vigentes = null;

        return ($filas > 0);
    }

    /**
     * Todas las exclusiones de un cliente, la vigente y las dadas de baja, de
     * la mas nueva a la mas vieja.
     *
     * @param string $codCliente
     * @return array
     */
    public function historial($codCliente) {
        $cod = DirectorioFranquicias::codigo($codCliente);

        if ($cod === '' || !$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MOTIVO, VIGENTE, USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE COD_CLIENT = ?
             ORDER BY FECHA_ALTA DESC, ID DESC",
            [$cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de exclusiones'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'MOTIVO' => $row['MOTIVO'],
                'VIGENTE' => (intval($row['VIGENTE']) === 1),
                'USUARIO' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA']),
                'USUARIO_BAJA' => $row['USUARIO_BAJA'],
                'FECHA_BAJA' => self::fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /* ====================================================================
       HELPERS PUROS
       ==================================================================== */

    /**
     * El motivo, recortado y validado.
     *
     * Vacio o solo espacios es un motivo que falta, no un motivo: la tabla
     * tiene un CHECK por lo mismo.
     *
     * @param mixed $motivo
     * @return string
     */
    public static function validarMotivo($motivo) {
        $m = trim((string) $motivo);

        if ($m === '') {
            throw new Exception('El motivo es obligatorio: sacar a un cliente de Cobranzas '
                . 'Franquicias sin decir por qué no lo explica nadie después.');
        }

        return mb_substr($m, 0, self::LARGO_MOTIVO);
    }

    /**
     * El codigo de cliente, normalizado y validado como franquicia.
     *
     * @param mixed $codCliente
     * @return string
     */
    public static function validarCodigo($codCliente) {
        $cod = DirectorioFranquicias::codigo($codCliente);

        if ($cod === '') {
            throw new Exception('Falta el código del cliente.');
        }

        if (!preg_match('/^[FL]/', $cod)) {
            throw new Exception('"' . $cod . '" no es una franquicia: los clientes de Cobranzas '
                . 'Franquicias empiezan con F o con L. No se excluyó nada.');
        }

        return $cod;
    }

    /**
     * Separa los items de los clientes excluidos de los que se cobran.
     *
     * Los excluidos NO SE DESCARTAN: salen marcados, con el motivo, quien y
     * cuando, para que la pantalla los pueda mostrar atenuados con "Ver
     * excluidos" y para que el cartel diga cuanta plata quedo afuera. Lo que
     * suma -eje, pie, KPIs, tablero- usa solo 'incluidos'.
     *
     * Conserva lista o mapa, como DirectorioFranquicias::filtrarUniverso().
     *
     * @param array $items
     * @param array $vigentes Lo que devuelve vigentes()
     * @param string $campoCliente
     * @return array ['incluidos' => array, 'excluidos' => array]
     */
    public static function separar($items, $vigentes, $campoCliente) {
        $items = is_array($items) ? $items : [];
        $vigentes = is_array($vigentes) ? $vigentes : [];

        if (empty($vigentes)) {
            return ['incluidos' => $items, 'excluidos' => []];
        }

        $incluidos = [];
        $excluidos = [];

        foreach ($items as $clave => $it) {
            $cod = DirectorioFranquicias::codigo(isset($it[$campoCliente]) ? $it[$campoCliente] : '');

            if (!isset($vigentes[$cod])) {
                $incluidos[$clave] = $it;
                continue;
            }

            $it['EXCLUIDO'] = true;
            $it['MOTIVO_EXCLUSION'] = $vigentes[$cod]['MOTIVO'];
            $it['EXCLUSION_USUARIO'] = $vigentes[$cod]['USUARIO'];
            $it['EXCLUSION_FECHA'] = $vigentes[$cod]['FECHA_ALTA'];
            $excluidos[$clave] = $it;
        }

        if (array_values($items) === $items) {
            $incluidos = array_values($incluidos);
            $excluidos = array_values($excluidos);
        }

        return ['incluidos' => $incluidos, 'excluidos' => $excluidos];
    }

    /**
     * Cuanto quedo afuera por exclusion: facturas, clientes, importe y los
     * motivos. Es lo que dice el cartel de Cobranzas FR, que se ve aunque
     * "Ver excluidos" este apagado -es la unica forma de notar que hay plata
     * afuera-, y el aviso del tablero.
     *
     * @param array $excluidos Los 'excluidos' de separar()
     * @param string $campoCliente
     * @param string $campoImporte
     * @param string|null $campoCantidad Si cada item agrupa varios comprobantes
     * @return array ['facturas', 'clientes', 'importe', 'motivos' => ['COD: motivo']]
     */
    public static function resumen($excluidos, $campoCliente, $campoImporte, $campoCantidad = null) {
        $facturas = 0;
        $importe = 0.0;
        $motivos = [];

        foreach ((is_array($excluidos) ? $excluidos : []) as $it) {
            $cod = DirectorioFranquicias::codigo(isset($it[$campoCliente]) ? $it[$campoCliente] : '');

            $facturas += ($campoCantidad !== null && isset($it[$campoCantidad]))
                ? intval($it[$campoCantidad]) : 1;
            $importe += isset($it[$campoImporte]) ? floatval($it[$campoImporte]) : 0.0;
            $motivos[$cod] = $cod . ': ' . (isset($it['MOTIVO_EXCLUSION']) ? $it['MOTIVO_EXCLUSION'] : '');
        }

        ksort($motivos);

        return [
            'facturas' => $facturas,
            'clientes' => count($motivos),
            'importe' => round($importe, 2),
            'motivos' => array_values($motivos)
        ];
    }

    /**
     * El aviso del tablero sobre lo excluido. INFO: es una decision tomada,
     * no un problema; se dice porque saca plata de la fila.
     *
     * @param array $resumen Lo que devuelve resumen()
     * @return string|null null si no hay nada excluido
     */
    public static function textoAviso($resumen) {
        $f = isset($resumen['facturas']) ? intval($resumen['facturas']) : 0;

        if ($f < 1) {
            return null;
        }

        $c = intval($resumen['clientes']);
        $motivos = array_slice($resumen['motivos'], 0, self::MAX_MOTIVOS);
        $resto = count($resumen['motivos']) - count($motivos);

        return $f . ' factura' . ($f === 1 ? '' : 's') . ' por ' . self::plata($resumen['importe'])
            . ' de ' . $c . ' cliente' . ($c === 1 ? '' : 's') . ' excluido' . ($c === 1 ? '' : 's')
            . ' a mano de Cobranzas Franquicias no ' . ($f === 1 ? 'entra' : 'entran')
            . ' en el tablero. Motivos: ' . implode('; ', $motivos)
            . ($resto > 0 ? '; y ' . $resto . ' más' : '') . '. Se revisan en Parámetros → Cobranzas.';
    }

    private function razonSocial($cod) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT RAZON_SOCI FROM GVA14 WHERE COD_CLIENT = ?", [$cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('No se pudo leer GVA14 para validar el cliente'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? trim((string) $row['RAZON_SOCI']) : null;
    }

    private function conectar() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
    }

    /** 'Y-m-d H:i', o null */
    private static function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null) ? null : substr((string) $v, 0, 16);
    }

    /** $ 1.234,56, para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

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
