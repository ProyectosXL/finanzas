<?php

require_once __DIR__ . '/LogisticaPlanilla.php';
require_once __DIR__ . '/ProveedoresTango.php';
require_once __DIR__ . '/Planilla.php';

/**
 * Logistica
 * El maestro de fleteros y la lectura de todo lo que la planilla necesita.
 *
 * QUE ES UN FLETERO ACA
 * ---------------------
 * Un proveedor de CPA01 al que se le paga por HORA, con un regimen mensual
 * constante. No es una categoria de Tango ni un rubro del maestro de
 * Proveedores Locales: es una lista propia, que alguien carga.
 *
 * POR QUE LA LISTA ES PROPIA Y NO UN RUBRO DEL MAESTRO
 * -----------------------------------------------------
 * El maestro de Proveedores Locales clasifica DEUDA YA FACTURADA. Esto es lo
 * contrario: proyecta plata que todavia no se facturo, a partir de horas y de
 * un valor hora que no estan en ningun comprobante. Son dos cosas distintas
 * sobre los mismos proveedores, y meterlas en la misma tabla obligaria a que
 * cada fila contestara las dos preguntas.
 *
 * EL CODIGO SE VALIDA CONTRA CPA01, Y EL NOMBRE SALE DE AHI
 * ----------------------------------------------------------
 * Mismo patron que el alta manual del maestro de Proveedores Locales: el
 * autocomplete busca con ProveedoresTango::buscar() y el alta vuelve a chequear
 * con existe(), porque lo que manda el navegador es un pedido y no una
 * autorizacion. NOM_PROVEE no se tipea: si se pudiera, dos pantallas mostrarian
 * dos nombres para el mismo codigo y ninguno seria "el nombre del proveedor".
 *
 * EL NOMBRE SE GUARDA IGUAL, COMO COPIA. La fila tiene que seguir siendo
 * legible si Tango no responde -un codigo suelto no le dice nada a nadie- pero
 * la pantalla muestra el de CPA01 cuando puede leerlo, asi que un cambio de
 * nombre en Tango se ve enseguida.
 *
 * LA EXCLUSION EN PROVEEDORES LOCALES NO SE HACE DESDE ACA
 * ---------------------------------------------------------
 * Un fletero tiene deuda en Cuentas a Pagar Locales, asi que mientras este en
 * las dos pestanas su pago se cuenta dos veces. La exclusion existe y es manual
 * -RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO, desde el maestro de Proveedores
 * Locales-, y este modulo SOLO AVISA. Excluir a un proveedor saca su deuda real
 * y ya facturada del tablero: esa es una decision de quien mira las cuentas a
 * pagar, no un efecto de dar de alta un fletero. Es el mismo criterio que
 * ProveedoresTango::faltantes(), que avisa y no da de baja.
 *
 * LA LECTURA Y EL CALCULO VIVEN APARTE
 * -------------------------------------
 * Esta clase lee: fleteros, inflacion, cronograma. Quien calcula es
 * LogisticaPlanilla, que es pura y se prueba sin base. Mismo criterio que
 * DolarFuturo y que ComprasProyectadas.
 */
class Logistica {

    /** El maestro de fleteros, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_LOGISTICA_FLETEROS';

    /** @var Conexion */
    private $conn;

    /** @var ProveedoresTango|null */
    private $tango = null;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LA PLANILLA COMPLETA
       ==================================================================== */

    /**
     * Todo lo que la pestana y el proveedor del tablero necesitan: la planilla
     * calculada, el cronograma con el que se armo y los avisos.
     *
     * NO LANZA POR NINGUNO DE SUS TRES INSUMOS. Sin la tabla de fleteros la
     * planilla va vacia; sin inflacion los ajustes no se calculan y los meses
     * quedan en null; sin calendario el cronograma usa su fallback. Los tres
     * casos avisan. Lo unico que rinde la pantalla en blanco es que no se pueda
     * conectar a central, y eso ya lo maneja quien llama.
     *
     * @param Horizonte $h
     * @return array ['planilla' => ..., 'cronograma' => ..., 'avisos' => [...],
     *                'tabla_creada' => bool]
     */
    public function planilla($h) {
        $avisos = [];

        if (!$this->tablaCreada()) {
            $avisos[] = $this->avisoSinTabla();
        }

        $fleteros = $this->getFleteros(true);

        // Inflacion: sin ella los ajustes trimestrales no se calculan, pero el
        // trimestre base si, asi que la planilla sirve igual y lo dice.
        $inflacion = [];

        try {
            require_once __DIR__ . '/Inflacion.php';

            $inf = new Inflacion();
            $inflacion = $inf->valores();

            if ($inf->avisoSinTabla() !== '') {
                $avisos[] = $inf->avisoSinTabla();
            }
        } catch (Throwable $e) {
            $avisos[] = 'No se pudo leer la inflación mensual (' . $e->getMessage()
                . '). Los valores hora se proyectan sin ajuste trimestral.';
        }

        require_once __DIR__ . '/CronogramaDatos.php';

        $crono = (new CronogramaDatos())->paraHorizonte($h);

        foreach ($crono['avisos'] as $a) {
            $avisos[] = $a;
        }

        $planilla = LogisticaPlanilla::calcular($fleteros, $h, $crono['pagos'], $inflacion);

        foreach ($planilla['avisos'] as $a) {
            $avisos[] = $a;
        }

        foreach ($this->avisosExclusion($fleteros) as $a) {
            $avisos[] = $a;
        }

        return [
            'planilla' => $planilla,
            'cronograma' => $crono['pagos'],
            'avisos' => $avisos,
            'tabla_creada' => $this->tablaCreada()
        ];
    }

    /**
     * Avisa de los fleteros activos que NO estan excluidos de Proveedores
     * Locales.
     *
     * ES EL AVISO MAS IMPORTANTE DE LA PESTANA: mientras un fletero este en las
     * dos, el tablero cuenta su pago dos veces -una proyectada acá y una real
     * en Cuentas a Pagar Locales- y el cuadro cierra igual, sin que nada falle.
     *
     * SOLO AVISA, no excluye. Ver el encabezado de la clase.
     *
     * @param array $fleteros
     * @return array Lista de mensajes
     */
    private function avisosExclusion($fleteros) {
        if (empty($fleteros)) {
            return [];
        }

        try {
            require_once __DIR__ . '/ProveedoresExclusion.php';

            $excl = new ProveedoresExclusion();

            if ($excl->avisoSinTabla() !== '') {
                return [$excl->avisoSinTabla() . ' Hasta que exista no se puede saber si los '
                    . 'fleteros están excluidos de Cuentas a Pagar Locales, así que su pago '
                    . 'podría estar contándose dos veces en el tablero.'];
            }

            /* El código del módulo sale de ProveedoresExclusion y no de una
               constante propia: es su lista cerrada, y una copia acá se
               desincroniza en cuanto alguien agregue un módulo. */
            $excluidos = $excl->vigentes(ProveedoresExclusion::MODULO_PROV_LOCALES);
        } catch (Throwable $e) {
            return ['No se pudo verificar si los fleteros están excluidos de Cuentas a Pagar '
                . 'Locales (' . $e->getMessage() . '). Si no lo están, su pago se cuenta dos '
                . 'veces en el tablero.'];
        }

        $sinExcluir = [];

        foreach ($fleteros as $f) {
            $cod = trim((string) $f['COD_PROVEE']);

            if (!isset($excluidos[$cod])) {
                $sinExcluir[] = $cod;
            }
        }

        if (empty($sinExcluir)) {
            return [];
        }

        return ['Estos fleteros NO están excluidos de Cuentas a Pagar Locales: '
            . implode(', ', $sinExcluir) . '. Mientras no lo estén, su pago se cuenta DOS VECES '
            . 'en el tablero: una proyectada acá y otra por su deuda real. La exclusión se carga '
            . 'desde el maestro de Proveedores Locales.'];
    }

    /* ====================================================================
       EL MAESTRO DE FLETEROS
       ==================================================================== */

    /** @return bool Si la tabla existe */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                $this->tabla = false;

                return false;
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
            'Todavía no existe ' . self::TABLA . ': corré '
            . 'sql/cashflow_logistica_fleteros.sql contra la base central. Mientras tanto no '
            . 'se puede dar de alta ningún fletero y la fila Logística del tablero va en cero.';
    }

    /** @return ProveedoresTango La puerta a CPA01 */
    public function tango() {
        if ($this->tango === null) {
            $this->tango = new ProveedoresTango();
        }

        return $this->tango;
    }

    /**
     * Los fleteros cargados.
     *
     * EL NOMBRE SE REFRESCA CONTRA CPA01 cuando se puede leer: la copia guardada
     * es un respaldo, no la fuente. Si Tango no responde se usa la copia y no se
     * rompe nada.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar: mismo criterio que
     * DolarFuturo::curva(). Quien consume avisa con avisoSinTabla().
     *
     * @param bool $soloActivos
     * @return array Filas con COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE,
     *               MES_BASE, ACTIVO y la auditoría
     */
    public function getFleteros($soloActivos = false) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $sql = "SELECT COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE, ACTIVO,
                       USUARIO, FECHA_ALTA, FECHA_UPDATE, FECHA_BAJA
                FROM dbo." . self::TABLA
              . ($soloActivos ? " WHERE ACTIVO = 1" : "")
              . " ORDER BY NOMBRE, COD_PROVEE";

        $stmt = sqlsrv_query($this->conectar(), $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los fleteros'));
        }

        $filas = [];
        $codigos = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['COD_PROVEE'] = Planilla::codigo($row['COD_PROVEE']);
            $row['HORAS_MES'] = ($row['HORAS_MES'] === null) ? null : floatval($row['HORAS_MES']);
            $row['VALOR_HORA_BASE'] = ($row['VALOR_HORA_BASE'] === null)
                ? null : floatval($row['VALOR_HORA_BASE']);
            $row['MES_BASE'] = ($row['MES_BASE'] === null) ? null : trim((string) $row['MES_BASE']);
            $row['ACTIVO'] = (intval($row['ACTIVO']) === 1);

            foreach (['FECHA_ALTA', 'FECHA_UPDATE', 'FECHA_BAJA'] as $c) {
                if ($row[$c] instanceof DateTime) {
                    $row[$c] = $row[$c]->format('Y-m-d H:i:s');
                }
            }

            $codigos[] = $row['COD_PROVEE'];
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        /* UNA SOLA CONSULTA para todos los nombres, no una por fletero. Son
           pocos, pero el patrón es el del maestro y no hay motivo para el otro. */
        try {
            $enTango = $this->tango()->existentes($codigos);

            foreach ($filas as &$f) {
                $f['NOMBRE_TANGO'] = isset($enTango[$f['COD_PROVEE']])
                    ? $enTango[$f['COD_PROVEE']] : null;

                if ($f['NOMBRE_TANGO'] !== null) {
                    $f['NOMBRE'] = $f['NOMBRE_TANGO'];
                }
            }

            unset($f);
        } catch (Throwable $e) {
            /* CPA01 caído no tumba la pantalla: se muestran los nombres
               guardados. Mismo criterio que ProveedoresTango::disponible(). */
            foreach ($filas as &$f) {
                $f['NOMBRE_TANGO'] = null;
            }

            unset($f);
        }

        return $filas;
    }

    /**
     * Da de alta un fletero, o corrige los datos de uno existente.
     *
     * SIRVE TAMBIEN PARA REACTIVAR UNA BAJA, y la respuesta dice cuál de los
     * tres casos fue: mismo criterio que Echeqs::guardarClientePrechequeado().
     * Una segunda acción "reactivar" obligaría a la pantalla a saber de
     * antemano si el código ya existía.
     *
     * EL CODIGO SE VUELVE A VALIDAR CONTRA CPA01. Que el autocomplete lo haya
     * ofrecido no autoriza nada.
     *
     * @param string $codProvee
     * @param array $datos horas, valor_hora, mes_base (los tres opcionales)
     * @param string|null $usuario
     * @return array ['cod_provee', 'nombre', 'nuevo' => bool, 'reactivado' => bool]
     */
    public function guardarFletero($codProvee, $datos, $usuario = null) {
        $this->exigirTabla();

        $cod = Planilla::codigo($codProvee);

        if ($cod === '') {
            throw new Exception('Falta el código de proveedor.');
        }

        /* SI CPA01 NO SE PUEDE LEER NO SE DA DE ALTA. Es distinto de lo que hace
           la importación del maestro, que valida y sigue: acá el alta es de a
           uno y el nombre SALE de Tango, así que sin CPA01 no hay nada que
           guardar salvo un código que nadie puede verificar. */
        if (!$this->tango()->disponible()) {
            throw new Exception('No se puede leer CPA01, así que no hay contra qué validar el '
                . 'código ni de dónde sacar el nombre. Probá de nuevo en un rato.');
        }

        $nombre = $this->tango()->existe($cod);

        if ($nombre === null) {
            throw new Exception("El código '$cod' no existe en el maestro de proveedores de "
                . 'Tango (CPA01). Buscá el proveedor por nombre y elegilo de la lista.');
        }

        $horas = self::validarHoras(isset($datos['horas']) ? $datos['horas'] : null);
        $valor = self::validarValorHora(isset($datos['valor_hora']) ? $datos['valor_hora'] : null);
        $mesBase = self::validarMesBase(isset($datos['mes_base']) ? $datos['mes_base'] : null);

        $cid = $this->conectar();
        $actual = $this->getFletero($cod);

        if ($actual === null) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE, ACTIVO, USUARIO)
                 VALUES (?, ?, ?, ?, ?, 1, ?)",
                [$cod, $nombre, $horas, $valor, $mesBase, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de alta el fletero'));
            }

            sqlsrv_free_stmt($stmt);

            return ['cod_provee' => $cod, 'nombre' => $nombre,
                    'nuevo' => true, 'reactivado' => false];
        }

        /* REACTIVA SI ESTABA DE BAJA. FECHA_BAJA se limpia: si quedara, la fila
           diría a la vez que está activa y cuándo se dio de baja. */
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET NOMBRE = ?, HORAS_MES = ?, VALOR_HORA_BASE = ?, MES_BASE = ?,
                 ACTIVO = 1, FECHA_BAJA = NULL, USUARIO = ?, FECHA_UPDATE = GETDATE()
             WHERE COD_PROVEE = ?",
            [$nombre, $horas, $valor, $mesBase, $usuario, $cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el fletero'));
        }

        sqlsrv_free_stmt($stmt);

        return ['cod_provee' => $cod, 'nombre' => $nombre, 'nuevo' => false,
                'reactivado' => !$actual['ACTIVO']];
    }

    /**
     * Da de baja o reactiva un fletero.
     *
     * NO BORRA NADA: un fletero que dejó de trabajar tiene historia -las horas y
     * el valor hora con los que se proyectó- y borrarlo la tira.
     *
     * @param string $codProvee
     * @param bool $activo
     * @param string|null $usuario
     * @return array ['cod_provee', 'activo', 'nombre']
     */
    public function activarFletero($codProvee, $activo, $usuario = null) {
        $this->exigirTabla();

        $cod = Planilla::codigo($codProvee);
        $actual = $this->getFletero($cod);

        if ($actual === null) {
            throw new Exception("El fletero '$cod' no está cargado.");
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET ACTIVO = ?, FECHA_BAJA = CASE WHEN ? = 1 THEN NULL ELSE GETDATE() END,
                 USUARIO = ?, FECHA_UPDATE = GETDATE()
             WHERE COD_PROVEE = ?",
            [$activo ? 1 : 0, $activo ? 1 : 0, $usuario, $cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al cambiar el estado del fletero'));
        }

        sqlsrv_free_stmt($stmt);

        return ['cod_provee' => $cod, 'activo' => (bool) $activo,
                'nombre' => $actual['NOMBRE']];
    }

    /**
     * Un fletero por su código, o null si no está cargado.
     *
     * DEVOLVER null NO ES UN ERROR: es el resultado de una búsqueda, y es lo que
     * guardarFletero() usa para decidir si es alta o corrección.
     *
     * @param string $codProvee
     * @return array|null
     */
    public function getFletero($codProvee) {
        if (!$this->tablaCreada()) {
            return null;
        }

        $cod = Planilla::codigo($codProvee);

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE, ACTIVO
             FROM dbo." . self::TABLA . " WHERE COD_PROVEE = ?", [$cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar el fletero'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            return null;
        }

        $row['COD_PROVEE'] = Planilla::codigo($row['COD_PROVEE']);
        $row['ACTIVO'] = (intval($row['ACTIVO']) === 1);

        return $row;
    }

    /* ====================================================================
       VALIDACION

       LA QUE VALE ES ESTA, no la de la pantalla: el endpoint es alcanzable
       sin pasar por ella. Los tres campos son OPCIONALES -un fletero recien
       dado de alta no los tiene- pero si vienen, tienen que servir.
       ==================================================================== */

    /** @return float|null */
    public static function validarHoras($valor) {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            throw new Exception('Las horas por mes tienen que ser un número.');
        }

        $n = floatval($valor);

        /* CERO NO VALE, y no es una arbitrariedad: cero horas significa que no
           se le paga, y eso se expresa dando de baja al fletero. Cargado como
           cero, la planilla no podría distinguirlo de un olvido. El tope de 744
           son las horas de un mes de 31 días. */
        if ($n <= 0 || $n > 744) {
            throw new Exception('Las horas por mes tienen que ser mayores a 0 y no pueden '
                . 'superar 744 (las horas de un mes de 31 días). Se recibió ' . $n . '.');
        }

        return $n;
    }

    /** @return float|null */
    public static function validarValorHora($valor) {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            throw new Exception('El valor hora tiene que ser un número.');
        }

        $n = floatval($valor);

        if ($n <= 0) {
            throw new Exception('El valor hora tiene que ser mayor a 0. Un cero significaría '
                . 'que esa hora no se paga, y eso se expresa dando de baja al fletero.');
        }

        return $n;
    }

    /** @return string|null 'Y-m' */
    public static function validarMesBase($mes) {
        if ($mes === null || trim((string) $mes) === '') {
            return null;
        }

        $m = trim((string) $mes);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new Exception("Mes base inválido: '$mes'. Se esperaba el formato YYYY-MM.");
        }

        return $m;
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
