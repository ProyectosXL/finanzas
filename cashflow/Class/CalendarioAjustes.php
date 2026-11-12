<?php

require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * CalendarioAjustes
 * Los feriados bancarios que el cashflow agrega o quita a mano sobre
 * RO_T_CALENDARIO.
 *
 * POR QUE EXISTE
 * --------------
 * RO_T_CALENDARIO es una tabla externa (XL-APPS / POWER_BI_CONTROL, conexion
 * 'power') que mantiene otro proceso y leen otros sistemas. NO SE ESCRIBE: un
 * feriado del cashflow cambiaria el calendario de todos, y la proxima carga del
 * proceso dueno lo pisaria sin aviso. Cuando el banco no opera un dia que esa
 * tabla da como habil -un feriado puente decretado tarde, un asueto- o al
 * reves, la correccion vive aca, en RO_T_CASHFLOW_CALENDARIO_AJUSTE (central).
 *
 * SE APLICA AL LEER, EN UN SOLO LUGAR
 * -----------------------------------
 * Ventas::getDiasHabiles() = getDiasHabilesBase() (la lectura de
 * RO_T_CALENDARIO, sin cambios y sigue siendo la unica) + aplicar(). Todo lo
 * que corre una fecha al dia habil -el cronograma de pagos, Ventas, Echeqs,
 * Tarjetas, Saldos, la marca de los dias no habiles del eje- ve el ajuste sin
 * enterarse de que existe. Materializar el calendario corregido en otra tabla
 * seria una copia que se desactualiza sola el dia que cambia RO_T_CALENDARIO.
 *
 * UNA FILA POR FECHA, CON EL VALOR QUE VALE
 * -----------------------------------------
 * DIA_LABORAL 0 es un feriado agregado; 1, un feriado bancario que se pasa a
 * habil. Solo de lunes a viernes -sabado y domingo nunca son habiles, y el
 * respaldo de DiasHabiles ya lo sabe- y solo si es distinto de lo que dice el
 * calendario externo: un ajuste igual no ajusta nada y en la grilla se leeria
 * como una correccion. Ver validar().
 *
 * NUNCA ROMPE EL CALCULO
 * ----------------------
 * Si la tabla no existe -no se corrio sql/cashflow_parametros_calendario.sql-
 * o la lectura falla, aplicar() devuelve el mapa intacto: el calendario es el
 * de siempre. La sub-pestana Calendario lo avisa con avisoSinTabla(). Las
 * escrituras si lanzan, porque guardar sin tabla no es un caso a tolerar.
 *
 * SIN BAJAS FISICAS, igual que el cronograma de pagos: quitar un ajuste marca
 * VIGENTE = 0 y deja la fila, con quien y cuando.
 */
class CalendarioAjustes {

    const TABLA = 'RO_T_CASHFLOW_CALENDARIO_AJUSTE';

    /** El script que crea la tabla, para los avisos */
    const SCRIPT = 'sql/cashflow_parametros_calendario.sql';

    /** Largo de DESCRIPCION */
    const LARGO_DESCRIPCION = 200;

    /** Los dos origenes de una fila de la grilla */
    const ORIGEN_CALENDARIO = 'CALENDARIO';
    const ORIGEN_AJUSTE = 'AJUSTE';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LO PURO
       ==================================================================== */

    /**
     * Pisa el mapa del calendario externo con los ajustes. Pura.
     *
     * UNA FECHA QUE EL MAPA NO TIENE SE AGREGA: un feriado cargado para un mes
     * que RO_T_CALENDARIO todavia no cubre vale igual, y el resto de ese mes
     * sigue sin dato -con su aviso de calendario faltante de siempre-.
     *
     * @param array $mapa Mapa 'Y-m-d' => bool
     * @param array $ajustes Mapa 'Y-m-d' => bool (true = habil)
     * @return array Mapa 'Y-m-d' => bool, ordenado por fecha
     */
    public static function aplicarAjustes($mapa, $ajustes) {
        $mapa = is_array($mapa) ? $mapa : [];

        if (empty($ajustes) || !is_array($ajustes)) {
            return $mapa;
        }

        foreach ($ajustes as $fecha => $habil) {
            $mapa[$fecha] = (bool) $habil;
        }

        ksort($mapa);

        return $mapa;
    }

    /**
     * Valida un ajuste antes de guardarlo. Pura.
     *
     * LAS TRES REGLAS
     *   1. Solo lunes a viernes. Sabado y domingo nunca son habiles: el
     *      respaldo de DiasHabiles y la marca del eje ya los tratan asi, y un
     *      sabado "habil" no lo acredita ningun banco.
     *   2. Distinto del calendario externo. Una fecha que RO_T_CALENDARIO no
     *      tiene se compara contra el respaldo -lunes a viernes, habil-, que
     *      es lo que el calculo usaria sin el ajuste.
     *   3. Descripcion obligatoria al marcar un feriado: RO_T_CALENDARIO no
     *      tiene el nombre, asi que es lo unico que dice que dia es. Al pasar
     *      a habil un feriado bancario es opcional.
     *
     * @param mixed $fecha 'Y-m-d'
     * @param mixed $diaLaboral 0 feriado, 1 habil
     * @param mixed $descripcion
     * @param bool|null $externo Lo que dice RO_T_CALENDARIO; null si no la tiene
     * @return array ['fecha', 'dia_laboral' => int, 'descripcion' => string|null]
     * @throws Exception con el motivo del rechazo
     */
    public static function validar($fecha, $diaLaboral, $descripcion, $externo) {
        $f = self::validarFecha($fecha);

        if (!in_array((string) $diaLaboral, ['0', '1'], true)
            && !is_bool($diaLaboral)) {
            throw new Exception('El día tiene que marcarse como feriado (0) o hábil (1).');
        }

        $habil = ($diaLaboral === true || (string) $diaLaboral === '1');

        if (intval(date('N', strtotime($f))) > 5) {
            throw new Exception('El ' . self::fechaLarga($f) . ' es fin de semana: sólo se ajustan '
                . 'días de lunes a viernes. Sábado y domingo nunca son hábiles.');
        }

        $vale = ($externo === null) ? true : (bool) $externo;

        if ($habil === $vale) {
            throw new Exception('El ' . self::fechaLarga($f) . ' ya es '
                . ($vale ? 'hábil' : 'feriado') . ' en el calendario bancario'
                . ($externo === null ? ' (RO_T_CALENDARIO no lo tiene y se toma hábil por ser '
                    . 'de lunes a viernes)' : '')
                . ': el ajuste no cambiaría nada.');
        }

        $desc = trim((string) $descripcion);

        if (!$habil && $desc === '') {
            throw new Exception('Escribí qué feriado es: RO_T_CALENDARIO no tiene el nombre, así que '
                . 'la descripción es lo único que lo explica.');
        }

        if (mb_strlen($desc) > self::LARGO_DESCRIPCION) {
            throw new Exception('La descripción no puede pasar de ' . self::LARGO_DESCRIPCION
                . ' caracteres.');
        }

        return [
            'fecha' => $f,
            'dia_laboral' => $habil ? 1 : 0,
            'descripcion' => ($desc === '') ? null : $desc
        ];
    }

    /**
     * Valida una fecha 'Y-m-d' real.
     *
     * @param mixed $fecha
     * @return string
     * @throws Exception
     */
    public static function validarFecha($fecha) {
        $f = trim((string) $fecha);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)
            || !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            throw new Exception("Fecha inválida: '$fecha'. Se esperaba el formato AAAA-MM-DD.");
        }

        return $f;
    }

    /**
     * Un año para la grilla: entero entre 2000 y 2100. Vacio, el actual.
     *
     * @param mixed $anio
     * @return int
     * @throws Exception
     */
    public static function validarAnio($anio) {
        if ($anio === null || $anio === '') {
            return intval(date('Y'));
        }

        if (!preg_match('/^\d{4}$/', trim((string) $anio))
            || intval($anio) < 2000 || intval($anio) > 2100) {
            throw new Exception("Año inválido: '$anio'.");
        }

        return intval($anio);
    }

    /**
     * La grilla de feriados bancarios de un año: los dias de lunes a viernes
     * NO HABILES de RO_T_CALENDARIO, mas los ajustes, cada uno con su origen.
     * Pura.
     *
     * UN FERIADO BANCARIO PASADO A HABIL SIGUE EN LA GRILLA, con origen Ajuste:
     * si desapareciera, no habria donde verlo ni desde donde quitar el ajuste.
     * Los sabados y domingos no van: nunca son habiles y son la mitad del
     * listado.
     *
     * @param array $base Mapa 'Y-m-d' => bool de RO_T_CALENDARIO, sin ajustes
     * @param array $ajustes Lista de filas de listar()
     * @return array Lista ordenada por fecha de ['fecha', 'dia', 'externo' => bool|null,
     *               'habil' => bool, 'origen', 'id', 'descripcion', 'usuario', 'fecha_update']
     */
    public static function grilla($base, $ajustes) {
        $porFecha = [];

        foreach (is_array($ajustes) ? $ajustes : [] as $a) {
            $porFecha[$a['fecha']] = $a;
        }

        $fechas = array_keys($porFecha);

        foreach (is_array($base) ? $base : [] as $fecha => $habil) {
            if (!$habil && intval(date('N', strtotime($fecha))) <= 5) {
                $fechas[] = $fecha;
            }
        }

        $fechas = array_values(array_unique($fechas));
        sort($fechas);

        $filas = [];

        foreach ($fechas as $fecha) {
            $externo = array_key_exists($fecha, (array) $base) ? (bool) $base[$fecha] : null;
            $a = isset($porFecha[$fecha]) ? $porFecha[$fecha] : null;

            $filas[] = [
                'fecha' => $fecha,
                'dia' => intval(date('N', strtotime($fecha))),
                'externo' => $externo,
                'habil' => $a ? (bool) $a['dia_laboral'] : ($externo === null ? true : $externo),
                'origen' => $a ? self::ORIGEN_AJUSTE : self::ORIGEN_CALENDARIO,
                'id' => $a ? $a['id'] : null,
                'descripcion' => $a ? $a['descripcion'] : null,
                'usuario' => $a ? $a['usuario'] : null,
                'fecha_update' => $a ? $a['fecha_update'] : null
            ];
        }

        return $filas;
    }

    /** '2026-11-23' -> 'lunes 23/11/2026', para los mensajes */
    public static function fechaLarga($fecha) {
        $dias = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $t = strtotime($fecha);

        return $dias[intval(date('N', $t))] . ' ' . date('d/m/Y', $t);
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Si la tabla existe. Con OBJECT_ID y no intentando un SELECT: una tabla
     * que no existe y una consulta que falla por otra razon son dos cosas
     * distintas, y la pantalla tiene que poder decir cual.
     *
     * @return bool
     */
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

    /** @return string El aviso de script faltante, o '' si la tabla esta */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe ' . self::TABLA . ': corré ' . self::SCRIPT . ' contra la base '
            . 'central. Mientras tanto los feriados bancarios son los de RO_T_CALENDARIO tal cual, '
            . 'y no se pueden agregar ni quitar.';
    }

    /**
     * El mapa del calendario externo con los ajustes aplicados.
     *
     * NUNCA LANZA. Sin tabla o con la lectura rota devuelve el mapa intacto
     * -el calendario de siempre- y deja el motivo en el log: este metodo esta
     * en el camino de todo lo que corre una fecha, y un ajuste que no se pudo
     * leer no puede tumbar el tablero.
     *
     * @param array $mapa Mapa 'Y-m-d' => bool de RO_T_CALENDARIO
     * @param string $desde 'Y-m-d'
     * @param string $hasta 'Y-m-d'
     * @return array Mapa 'Y-m-d' => bool
     */
    public function aplicar($mapa, $desde, $hasta) {
        if (!$this->tablaCreada()) {
            return $mapa;
        }

        try {
            return self::aplicarAjustes($mapa, $this->vigentesEntre($desde, $hasta));
        } catch (Throwable $e) {
            error_log('CalendarioAjustes::aplicar: ' . $e->getMessage());

            return $mapa;
        }
    }

    /**
     * Los ajustes vigentes de un rango, como mapa 'Y-m-d' => bool.
     * Protected: las pruebas lo reemplazan para probar aplicar() sin base.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function vigentesEntre($desde, $hasta) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT FECHA, DIA_LABORAL FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1 AND FECHA BETWEEN ? AND ?",
            [substr((string) $desde, 0, 10), substr((string) $hasta, 0, 10)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los ajustes del calendario'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[self::fechaDe($row['FECHA'])] = (intval($row['DIA_LABORAL']) === 1);
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Los ajustes vigentes de un año, con quien los cargo, para la grilla.
     * Vacio si la tabla no existe.
     *
     * @param mixed $anio
     * @return array Lista de ['id', 'fecha', 'dia_laboral' => int, 'descripcion',
     *               'usuario', 'fecha_update']
     */
    public function listar($anio) {
        $a = self::validarAnio($anio);

        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, FECHA, DIA_LABORAL, DESCRIPCION, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA . "
             WHERE VIGENTE = 1 AND FECHA BETWEEN ? AND ?
             ORDER BY FECHA",
            [$a . '-01-01', $a . '-12-31']);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los ajustes del calendario'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'id' => intval($row['ID']),
                'fecha' => self::fechaDe($row['FECHA']),
                'dia_laboral' => intval($row['DIA_LABORAL']),
                'descripcion' => $row['DESCRIPCION'],
                'usuario' => $row['USUARIO_MODIF'],
                'fecha_update' => ($row['FECHA_MODIF'] instanceof DateTime)
                    ? $row['FECHA_MODIF']->format('Y-m-d H:i:s') : $row['FECHA_MODIF']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Agrega un feriado o pasa a habil un feriado bancario.
     *
     * LA VALIDACION QUE VALE ES ESTA, contra lo que dice HOY RO_T_CALENDARIO
     * para esa fecha: la pantalla acota lo que se puede pedir, pero el
     * endpoint es alcanzable sin pasar por ella.
     *
     * Si la fecha ya tiene un ajuste vigente se corrige ese -su descripcion-:
     * como el ajuste tiene que ser distinto del calendario externo y solo hay
     * dos valores, el vigente y el nuevo dicen lo mismo.
     *
     * @param string $fecha 'Y-m-d'
     * @param mixed $diaLaboral 0 feriado, 1 habil
     * @param string|null $descripcion
     * @param string $usuario
     * @return array ['fecha', 'dia_laboral', 'descripcion', 'nuevo' => bool]
     */
    public function guardar($fecha, $diaLaboral, $descripcion, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $f = self::validarFecha($fecha);
        $v = self::validar($f, $diaLaboral, $descripcion, $this->valorExterno($f));
        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT ID FROM dbo." . self::TABLA . " WHERE FECHA = ? AND VIGENTE = 1", [$f]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la fecha'));
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($fila) {
            $sql = "UPDATE dbo." . self::TABLA . "
                    SET DIA_LABORAL = ?, DESCRIPCION = ?, " . Auditoria::SET_MODIF . "
                    WHERE ID = ? AND VIGENTE = 1";
            $params = [$v['dia_laboral'], $v['descripcion'], $usuario, intval($fila['ID'])];
        } else {
            $sql = "INSERT INTO dbo." . self::TABLA . "
                        (FECHA, DIA_LABORAL, DESCRIPCION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                    VALUES (?, ?, ?, 1, ?, ?)";
            $params = [$f, $v['dia_laboral'], $v['descripcion'], $usuario, $usuario];
        }

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el ajuste del calendario'));
        }

        sqlsrv_free_stmt($stmt);

        return $v + ['nuevo' => !$fila];
    }

    /**
     * Quita un ajuste: VIGENTE = 0, con quien y cuando. La fecha vuelve a lo
     * que diga RO_T_CALENDARIO.
     *
     * @param mixed $id
     * @param string $usuario
     * @return array ['id', 'fecha']
     * @throws Exception si no existe o ya estaba quitado
     */
    public function quitar($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        if (!is_numeric($id) || intval($id) <= 0) {
            throw new Exception('Falta el ajuste a quitar.');
        }

        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT FECHA FROM dbo." . self::TABLA . " WHERE ID = ? AND VIGENTE = 1", [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el ajuste'));
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$fila) {
            throw new Exception('Ese ajuste ya no está vigente: alguien lo quitó o lo reemplazó. '
                . 'Actualizá la pantalla.');
        }

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al quitar el ajuste del calendario'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id' => intval($id), 'fecha' => self::fechaDe($fila['FECHA'])];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * Lo que dice RO_T_CALENDARIO para una fecha, sin ajustes: true, false o
     * null si no la tiene. Por la unica lectura del modulo.
     *
     * El require va aca: Ventas requiere esta clase, y los dos en la cabecera
     * serian un ciclo de carga.
     *
     * @param string $fecha
     * @return bool|null
     */
    protected function valorExterno($fecha) {
        require_once __DIR__ . '/Ventas.php';

        $base = (new Ventas())->getDiasHabilesBase($fecha, $fecha);

        return array_key_exists($fecha, $base) ? (bool) $base[$fecha] : null;
    }

    /** Lanza si la tabla no existe */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** DateTime o texto -> 'Y-m-d' */
    private static function fechaDe($v) {
        return ($v instanceof DateTime) ? $v->format('Y-m-d') : substr((string) $v, 0, 10);
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
        $msg = $contexto . ': ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
