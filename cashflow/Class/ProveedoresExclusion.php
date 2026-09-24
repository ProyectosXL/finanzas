<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/ProveedoresTango.php';

/**
 * ProveedoresExclusion
 * Que proveedores quedan afuera de un modulo del cashflow, y por que.
 *
 * QUE RESUELVE
 * ------------
 * Hay proveedores cuya deuda ya se considera en otra pestana -la aduana, por
 * ejemplo, en Crono Nacionalizacion- y que ademas aparecen en las cuentas a
 * pagar de Tango. Si los dos lados los proyectan, el tablero cuenta dos veces
 * la misma plata. Quien maneja Proveedores Locales sabe cuales son: esto es
 * donde lo dice. CUALES excluir es una decision de negocio y no la toma el
 * codigo.
 *
 * POR PROVEEDOR, Y ALCANZA A TODA SU DEUDA
 * ----------------------------------------
 * La ya emitida y la que venga. Para sacar una factura suelta esta la
 * exclusion por factura -Proveedores::saveExclusionMasiva()-, que es otra
 * decision: "esta factura no va" y no "este proveedor ya esta contado".
 *
 * POR MODULO, Y NO UN BIT DEL MAESTRO
 * -----------------------------------
 * La clave es (COD_PROVEE, MODULO). Hoy el unico modulo es Proveedores Locales;
 * agregar otro es sumarlo a MODULOS y al CHECK de la tabla, y escribir el
 * codigo que lo lea. La tabla no cambia.
 *
 * NO VIVE EN EL MAESTRO porque el maestro es una copia reimportable de la
 * planilla de administracion, y la reimportacion propone bajas: la exclusion
 * se perderia. Y porque un proveedor se puede excluir sin estar en el
 * maestro. Por eso es una clase aparte de ProveedoresCategorias, igual que
 * ProveedoresTango: son datos distintos con duenos distintos.
 *
 * HISTORIAL, COMO ECHEQS
 * ----------------------
 * Sin bajas fisicas. Incluir de nuevo marca VIGENTE = 0 y sella FECHA_BAJA;
 * excluir de nuevo inserta otra fila. Ver sql/cashflow_prov_exclusion_modulo.sql.
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * Sin la tabla, vigentes() devuelve vacio -nadie esta excluido, que es lo
 * cierto- y lo que falla, con el motivo, es excluir. Mismo patron que el resto
 * del modulo.
 */
class ProveedoresExclusion {

    const TABLA = 'RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO';

    /** Proveedores Locales: la unica que existe hoy */
    const MODULO_PROV_LOCALES = 'PROV_LOCALES';

    /**
     * Los modulos que admiten exclusion, con su nombre para la pantalla.
     *
     * TIENE QUE COINCIDIR CON EL CHECK de la tabla. Un modulo que este aca y no
     * alla lo rechaza la base al guardar; uno que este alla y no aca no lo
     * puede escribir nadie.
     */
    const MODULOS = [
        self::MODULO_PROV_LOCALES => 'Proveedores Locales'
    ];

    /** Tope del motivo, el de la columna */
    const LARGO_MOTIVO = 200;

    /** @var Conexion */
    private $conn;

    /** @var ProveedoresTango */
    private $tango;

    /** @var bool|null */
    private $tabla = null;

    /** @var array Cache de vigentes por modulo, por pedido */
    private $vigentes = [];

    /**
     * @param ProveedoresTango|null $tango Se puede inyectar
     */
    function __construct($tango = null) {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->tango = $tango;
    }

    /** @return bool Si ya se corrio sql/cashflow_prov_exclusion_modulo.sql */
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
        if ($this->tablaCreada()) {
            return '';
        }

        return 'Todavía no se puede excluir un proveedor de Proveedores Locales: falta la tabla '
            . self::TABLA . '. Corré sql/cashflow_prov_exclusion_modulo.sql contra la base '
            . 'central. Mientras tanto no hay ninguno excluido y todo se proyecta como hasta ahora.';
    }

    /**
     * Las exclusiones vigentes de un modulo.
     *
     * UNA CONSULTA POR PEDIDO, y no una por proveedor: la usa getPendientes()
     * para cada vencimiento.
     *
     * @param string $modulo
     * @return array Mapa COD_PROVEE => ['MOTIVO', 'USUARIO', 'FECHA_ALTA']
     */
    public function vigentes($modulo) {
        $modulo = self::validarModulo($modulo);

        if (isset($this->vigentes[$modulo])) {
            return $this->vigentes[$modulo];
        }

        if (!$this->tablaCreada()) {
            return $this->vigentes[$modulo] = [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, MOTIVO, USUARIO, FECHA_ALTA
             FROM dbo." . self::TABLA . "
             WHERE MODULO = ? AND VIGENTE = 1",
            [$modulo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los proveedores excluidos'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[Planilla::codigo($row['COD_PROVEE'])] = [
                'MOTIVO' => $row['MOTIVO'],
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->vigentes[$modulo] = $mapa;
    }

    /**
     * Excluye un proveedor de un modulo.
     *
     * EL CODIGO SE VALIDA CONTRA CPA01, como el alta manual del maestro: un
     * codigo que no existe no tiene deuda que excluir, y la exclusion quedaria
     * puesta sin hacer nada. NO hace falta que este en el maestro.
     *
     * EL MOTIVO ES OBLIGATORIO, y se valida aca y no en la pantalla: el
     * endpoint es alcanzable sin pasar por ella.
     *
     * EXCLUIR LO YA EXCLUIDO ES UN ERROR y no un cambio de motivo: pisar el
     * motivo en silencio borraria el por que de la decision que estaba
     * vigente. Para cambiarlo se incluye y se vuelve a excluir, y quedan las
     * dos en el historial.
     *
     * @param string $codProvee
     * @param string $modulo
     * @param string $motivo
     * @param string|null $usuario
     * @return array ['cod_provee', 'nombre', 'motivo']
     */
    public function excluir($codProvee, $modulo, $motivo, $usuario = null) {
        $modulo = self::validarModulo($modulo);
        $cod = Planilla::codigo($codProvee);
        $m = self::validarMotivo($motivo);

        if ($cod === '') {
            throw new Exception('Falta el código del proveedor.');
        }

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        $tango = $this->tango();

        if (!$tango->disponible()) {
            throw new Exception('No se pudo leer CPA01, el maestro de proveedores de Tango, así '
                . 'que no se puede validar el código. No se excluyó nada.');
        }

        $nombre = $tango->existe($cod);

        if ($nombre === null) {
            throw new Exception('El código "' . $cod . '" no existe en CPA01, el maestro de '
                . 'proveedores de Tango. No se excluyó nada.');
        }

        $vigentes = $this->vigentes($modulo);

        if (isset($vigentes[$cod])) {
            throw new Exception($cod . ' ya está excluido de ' . self::MODULOS[$modulo]
                . ' (motivo: ' . $vigentes[$cod]['MOTIVO'] . '). Para cambiar el motivo, '
                . 'volvé a incluirlo y excluilo de nuevo: las dos decisiones quedan en el '
                . 'historial.');
        }

        /* EL INDICE UNICO FILTRADO es la red contra dos pantallas excluyendo a
           la vez: la segunda falla en vez de dejar dos vigentes. */
        $stmt = sqlsrv_query($this->conectar(),
            "INSERT INTO dbo." . self::TABLA . " (COD_PROVEE, MODULO, MOTIVO, USUARIO)
             VALUES (?, ?, ?, ?)",
            [$cod, $modulo, $m, $usuario]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al excluir el proveedor'));
        }

        sqlsrv_free_stmt($stmt);
        unset($this->vigentes[$modulo]);

        return ['cod_provee' => $cod, 'nombre' => $nombre, 'motivo' => $m];
    }

    /**
     * Vuelve a incluir un proveedor en un modulo.
     *
     * NO BORRA NADA: marca la vigente como dada de baja y le sella la fecha.
     * El motivo queda en el historial, que es donde tiene que estar: describe
     * una decision que estuvo vigente.
     *
     * @param string $codProvee
     * @param string $modulo
     * @param string|null $usuario
     * @return bool Si estaba excluido
     */
    public function incluir($codProvee, $modulo, $usuario = null) {
        $modulo = self::validarModulo($modulo);
        $cod = Planilla::codigo($codProvee);

        if ($cod === '') {
            throw new Exception('Falta el código del proveedor.');
        }

        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }

        /* USUARIO no se pisa: dice quien excluyo. Quien volvio a incluir no
           tiene columna propia, y pisar esa diria que el que incluyo fue el que
           excluyo. Cuando haya login y haga falta, es una columna mas. */
        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE COD_PROVEE = ? AND MODULO = ? AND VIGENTE = 1",
            [$cod, $modulo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al volver a incluir el proveedor'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);
        unset($this->vigentes[$modulo]);

        return ($filas > 0);
    }

    /**
     * Todas las exclusiones de un proveedor en un modulo, la vigente y las
     * dadas de baja, de la mas nueva a la mas vieja.
     *
     * @param string $codProvee
     * @param string $modulo
     * @return array
     */
    public function historial($codProvee, $modulo) {
        $modulo = self::validarModulo($modulo);
        $cod = Planilla::codigo($codProvee);

        if ($cod === '' || !$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MOTIVO, VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE COD_PROVEE = ? AND MODULO = ?
             ORDER BY FECHA_ALTA DESC, ID DESC",
            [$cod, $modulo]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de exclusiones'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'MOTIVO' => $row['MOTIVO'],
                'VIGENTE' => (intval($row['VIGENTE']) === 1),
                'USUARIO' => $row['USUARIO'],
                'FECHA_ALTA' => self::fechaHora($row['FECHA_ALTA']),
                'FECHA_BAJA' => self::fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /**
     * El modulo, validado contra MODULOS.
     *
     * Estatica y pura. Un modulo desconocido es un error de programacion o un
     * pedido armado a mano: se rechaza antes de llegar a la base, con la lista
     * de los validos.
     *
     * @param mixed $modulo
     * @return string
     */
    public static function validarModulo($modulo) {
        $m = strtoupper(trim((string) $modulo));

        if (!isset(self::MODULOS[$m])) {
            throw new Exception('"' . $modulo . '" no es un módulo que admita exclusión de '
                . 'proveedores. Los válidos son: ' . implode(', ', array_keys(self::MODULOS)) . '.');
        }

        return $m;
    }

    /**
     * El motivo, recortado y validado.
     *
     * Estatica y pura. Vacio o solo espacios es un motivo que falta, no un
     * motivo: la tabla tiene un CHECK por lo mismo.
     *
     * @param mixed $motivo
     * @return string
     */
    public static function validarMotivo($motivo) {
        $m = trim((string) $motivo);

        if ($m === '') {
            throw new Exception('El motivo es obligatorio: sacar a un proveedor del cashflow sin '
                . 'decir por qué no lo explica nadie después.');
        }

        return mb_substr($m, 0, self::LARGO_MOTIVO);
    }

    /** @return ProveedoresTango */
    private function tango() {
        if ($this->tango === null) {
            $this->tango = new ProveedoresTango();
        }

        return $this->tango;
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
