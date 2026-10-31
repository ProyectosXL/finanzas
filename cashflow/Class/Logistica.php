<?php

require_once __DIR__ . '/LogisticaPlanilla.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/ProveedoresTango.php';
require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

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

    /** Los pagos puntuales de los fleteros no habituales */
    const TABLA_PUNTUAL = 'RO_T_CASHFLOW_LOGISTICA_PAGO_PUNTUAL';

    /** El importe real del proximo pago de un habitual, cargado a mano */
    const TABLA_EDIT = 'RO_T_CASHFLOW_LOGISTICA_IMPORTE_EDIT';

    /** El script de los no habituales, para los avisos */
    const SCRIPT_NO_HABITUALES = 'sql/cashflow_logistica_no_habituales.sql';

    /** @var array|null Cache de esquema(): la columna HABITUAL y las dos tablas */
    private $esquema = null;

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
     * LOS AVISOS SALEN DOS VECES: 'avisos' con los textos, que es lo que lee
     * la pestana, y 'avisos_con_nivel' con la gravedad de cada uno, que es lo
     * que lee el tablero. Son la misma lista: 'avisos' es Aviso::textos() de
     * la otra, asi las dos pantallas no pueden decir cosas distintas.
     *
     * Niveles: lo que deja la fila en cero o cambia el numero sin que nadie lo
     * decida es critico (sin maestro de fleteros, sin inflacion, el pago que
     * se cuenta dos veces); lo que se arregla cargando algo es atencion (los
     * datos que le faltan a un fletero, el cronograma, el calendario).
     *
     * @param Horizonte $h
     * @return array ['planilla' => ..., 'cronograma' => ..., 'avisos' => [...],
     *                'avisos_con_nivel' => [...], 'tabla_creada' => bool]
     */
    public function planilla($h) {
        $avisos = [];

        if (!$this->tablaCreada()) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, $this->avisoSinTabla());
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
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $inf->avisoSinTabla());
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la inflación mensual ('
                . $e->getMessage() . '). Los valores hora se proyectan sin ajuste trimestral.');
        }

        require_once __DIR__ . '/CronogramaDatos.php';

        /* EL CRONOGRAMA DE LOGISTICA, que es uno de tres: el dia y la frecuencia
           salen de Parametros -> Generales. Con el defecto -el 2do y el 4to
           viernes- da exactamente lo mismo que cuando el viernes estaba escrito
           en el codigo. */
        $crono = (new CronogramaDatos())->paraHorizonte($h, 'LOGISTICA');

        foreach ($crono['avisos_con_nivel'] as $a) {
            $avisos[] = $a;
        }

        /* LOS PAGOS PUNTUALES Y LAS EDICIONES DEL PROXIMO PAGO. Sin el script
           no hay ninguno y todos son habituales: la planilla da lo de siempre.
           Si la lectura falla se sigue sin ellos, con un aviso critico: los
           numeros pueden estar mal y nadie lo decidio. */
        $puntuales = [];
        $ediciones = [];

        if (!$this->noHabitualesCreado()) {
            if ($this->tablaCreada()) {
                $avisos[] = Aviso::nuevo(Aviso::WARNING, $this->avisoSinNoHabituales());
            }
        } else {
            try {
                $puntuales = $this->getPagosPuntuales();
                $ediciones = $this->getEdiciones();
            } catch (Throwable $e) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los pagos puntuales ni '
                    . 'los importes editados (' . $e->getMessage() . '). Los fleteros no habituales '
                    . 'no proyectan nada y los próximos pagos van con el importe calculado.');
            }
        }

        $planilla = LogisticaPlanilla::calcular($fleteros, $h, $crono['pagos'], $inflacion,
            $puntuales, $ediciones);

        // Lo que le falta a cada fletero se arregla cargandolo: atencion.
        foreach (Aviso::lista($planilla['avisos'], Aviso::WARNING) as $a) {
            $avisos[] = $a;
        }

        // El pago contado dos veces: critico. El numero esta mal y nadie lo
        // decidio, aunque lo arregle el usuario; el texto dice como.
        foreach (Aviso::lista($this->avisosExclusion($fleteros), Aviso::DANGER) as $a) {
            $avisos[] = $a;
        }

        return [
            'planilla' => $planilla,
            'cronograma' => $crono['pagos'],

            // El dia y la frecuencia, para que la pantalla diga cuando se paga
            // sin tenerlo escrito: ver CronogramaPagos::describir().
            'cronograma_config' => $crono['config'],
            'avisos' => Aviso::textos($avisos),
            'avisos_con_nivel' => $avisos,
            'tabla_creada' => $this->tablaCreada(),
            'no_habituales' => $this->noHabitualesCreado()
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
                       " . ($this->noHabitualesCreado() ? "HABITUAL" : "CAST(1 AS BIT) AS HABITUAL") . ",
                       USUARIO_MODIF AS USUARIO, FECHA_ALTA, FECHA_MODIF AS FECHA_UPDATE,
                       USUARIO_BAJA, FECHA_BAJA
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
            $row['HABITUAL'] = (intval($row['HABITUAL']) === 1);

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

        /* CUANTOS PAGOS PUNTUALES FUTUROS TIENE CADA UNO, y por cuanto: es lo que
           dice la confirmacion de volver a habitual, en las dos pantallas. */
        $futuros = [];

        if ($this->noHabitualesCreado()) {
            try {
                $porCod = [];

                foreach ($this->getPagosPuntuales() as $p) {
                    $porCod[$p['COD_PROVEE']][] = $p;
                }

                foreach ($porCod as $cod => $lista) {
                    $futuros[$cod] = LogisticaPlanilla::puntualesFuturos($lista, date('Y-m-d'));
                }
            } catch (Throwable $e) {
                $futuros = [];
            }
        }

        foreach ($filas as &$f) {
            $f['PUNTUALES_FUTUROS'] = isset($futuros[$f['COD_PROVEE']])
                ? $futuros[$f['COD_PROVEE']] : ['cantidad' => 0, 'total' => 0.0];
        }

        unset($f);

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
     * @param string $usuario
     * @return array ['cod_provee', 'nombre', 'nuevo' => bool, 'reactivado' => bool]
     */
    public function guardarFletero($codProvee, $datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
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

        /* HABITUAL: si no viene, queda como estaba (un alta nace habitual). Sin
           el script no se puede marcar a nadie como no habitual: se rechaza en
           vez de guardar en silencio algo que no queda. */
        $habitual = ($actual === null) ? true : $actual['HABITUAL'];

        if (array_key_exists('habitual', $datos) && $datos['habitual'] !== null) {
            $habitual = filter_var($datos['habitual'], FILTER_VALIDATE_BOOLEAN);

            if (!$habitual && !$this->noHabitualesCreado()) {
                throw new Exception($this->avisoSinNoHabituales());
            }
        }

        $colHabitual = $this->noHabitualesCreado();

        if ($actual === null) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE, ACTIVO, USUARIO_ALTA,
                     USUARIO_MODIF" . ($colHabitual ? ", HABITUAL" : "") . ")
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?" . ($colHabitual ? ", ?" : "") . ")",
                array_merge([$cod, $nombre, $horas, $valor, $mesBase, $usuario, $usuario],
                    $colHabitual ? [$habitual ? 1 : 0] : []));

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de alta el fletero'));
            }

            sqlsrv_free_stmt($stmt);

            return ['cod_provee' => $cod, 'nombre' => $nombre,
                    'nuevo' => true, 'reactivado' => false, 'habitual' => $habitual,
                    'cambio_habitual' => false];
        }

        /* REACTIVA SI ESTABA DE BAJA. La baja se limpia: si quedara, la fila
           diría a la vez que está activa y cuándo y quién la dio de baja. */
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET NOMBRE = ?, HORAS_MES = ?, VALOR_HORA_BASE = ?, MES_BASE = ?,
                 " . ($colHabitual ? "HABITUAL = ?, " : "") . "
                 ACTIVO = 1, USUARIO_BAJA = NULL, FECHA_BAJA = NULL, " . Auditoria::SET_MODIF . "
             WHERE COD_PROVEE = ?",
            array_merge([$nombre, $horas, $valor, $mesBase],
                $colHabitual ? [$habitual ? 1 : 0] : [], [$usuario, $cod]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el fletero'));
        }

        sqlsrv_free_stmt($stmt);

        return ['cod_provee' => $cod, 'nombre' => $nombre, 'nuevo' => false,
                'reactivado' => !$actual['ACTIVO'], 'habitual' => $habitual,
                'cambio_habitual' => ($habitual !== $actual['HABITUAL'])];
    }

    /**
     * Da de baja o reactiva un fletero.
     *
     * NO BORRA NADA: un fletero que dejó de trabajar tiene historia -las horas y
     * el valor hora con los que se proyectó- y borrarlo la tira.
     *
     * @param string $codProvee
     * @param bool $activo
     * @param string $usuario
     * @return array ['cod_provee', 'activo', 'nombre']
     */
    public function activarFletero($codProvee, $activo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $cod = Planilla::codigo($codProvee);
        $actual = $this->getFletero($cod);

        if ($actual === null) {
            throw new Exception("El fletero '$cod' no está cargado.");
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET " . Auditoria::sqlBajaSegunEstado('ACTIVO') . ", ACTIVO = ?,
                 " . Auditoria::SET_MODIF . "
             WHERE COD_PROVEE = ?",
            array_merge(Auditoria::paramsBajaSegunEstado($activo, $usuario),
                [$activo ? 1 : 0, $usuario, $cod]));

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
            "SELECT COD_PROVEE, NOMBRE, HORAS_MES, VALOR_HORA_BASE, MES_BASE, ACTIVO,
                    " . ($this->noHabitualesCreado() ? "HABITUAL" : "CAST(1 AS BIT) AS HABITUAL") . "
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
        $row['HABITUAL'] = (intval($row['HABITUAL']) === 1);

        return $row;
    }

    /* ====================================================================
       EL ESQUEMA DE LOS NO HABITUALES
       ==================================================================== */

    /**
     * Si sql/cashflow_logistica_no_habituales.sql se corrio: la columna
     * HABITUAL y las dos tablas. Se pregunta todo junto y una vez.
     *
     * @return bool
     */
    public function noHabitualesCreado() {
        if ($this->esquema === null) {
            $this->esquema = false;

            try {
                $stmt = sqlsrv_query($this->conectar(),
                    "SELECT COL_LENGTH('dbo." . self::TABLA . "', 'HABITUAL') AS H,
                            OBJECT_ID('dbo." . self::TABLA_PUNTUAL . "', 'U') AS P,
                            OBJECT_ID('dbo." . self::TABLA_EDIT . "', 'U') AS E");

                if ($stmt !== false) {
                    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($stmt);

                    $this->esquema = ($row && $row['H'] !== null && $row['P'] !== null
                        && $row['E'] !== null);
                }
            } catch (Throwable $e) {
                $this->esquema = false;
            }
        }

        return $this->esquema;
    }

    /** @return string El aviso de script faltante */
    public function avisoSinNoHabituales() {
        return 'Todavía no se corrió ' . self::SCRIPT_NO_HABITUALES . ' contra la base central. '
            . 'Mientras tanto todos los fleteros son habituales y se proyectan como siempre, pero '
            . 'no se puede marcar a ninguno como no habitual, cargar pagos puntuales ni editar el '
            . 'importe del próximo pago.';
    }

    /* ====================================================================
       LOS PAGOS PUNTUALES DE LOS NO HABITUALES
       ==================================================================== */

    /**
     * Los pagos puntuales vigentes, pasados y futuros: los pasados son el
     * historial y se ven, aunque ya no proyecten.
     *
     * @param string|null $codProvee Uno solo, o todos
     * @return array Filas con ID, COD_PROVEE, FECHA ('Y-m-d'), IMPORTE y la auditoria
     */
    public function getPagosPuntuales($codProvee = null) {
        if (!$this->noHabitualesCreado()) {
            return [];
        }

        $params = [];
        $sql = "SELECT ID, COD_PROVEE, FECHA, IMPORTE, USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF,
                       FECHA_MODIF
                FROM dbo." . self::TABLA_PUNTUAL . " WHERE VIGENTE = 1";

        if ($codProvee !== null) {
            $sql .= " AND COD_PROVEE = ?";
            $params[] = Planilla::codigo($codProvee);
        }

        $stmt = sqlsrv_query($this->conectar(), $sql . " ORDER BY COD_PROVEE, FECHA, ID", $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los pagos puntuales'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'ID' => intval($row['ID']),
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'FECHA' => self::dia($row['FECHA']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
                'USUARIO_MODIF' => $row['USUARIO_MODIF'],
                'FECHA_MODIF' => self::momento($row['FECHA_MODIF'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * La fecha y el importe de un pago puntual, validados.
     *
     * LA FECHA VA DE MANANA EN ADELANTE: lo que se paga hoy ya esta reflejado
     * en el saldo bancario que abre el tablero, y la planilla no lo proyectaria
     * nunca. No se corre al dia habil: la cargo alguien que sabe cuando se paga.
     *
     * LA QUE VALE ES ESTA, no la de la pantalla. Estatica y pura.
     *
     * @param mixed $fecha 'Y-m-d'
     * @param mixed $importe
     * @param string $hoy 'Y-m-d'
     * @return array ['fecha' => 'Y-m-d', 'importe' => float]
     */
    public static function validarPagoPuntual($fecha, $importe, $hoy) {
        $f = substr(trim((string) $fecha), 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $f);

        if (!$d || $d->format('Y-m-d') !== $f) {
            throw new Exception("Fecha inválida: '$fecha'. Se esperaba el formato AAAA-MM-DD.");
        }

        if ($f <= $hoy) {
            throw new Exception('La fecha de un pago puntual tiene que ser de mañana en adelante. '
                . 'Lo que se paga hoy, o se pagó antes, ya está reflejado en el saldo bancario '
                . 'que abre el tablero: proyectarlo sería pedir dos veces la misma plata.');
        }

        return ['fecha' => $f, 'importe' => self::validarImporte($importe)];
    }

    /**
     * Un importe de pago: un numero mayor a cero. Cero no es un pago: es no
     * pagar, y se expresa dando de baja el pago.
     *
     * @return float
     */
    public static function validarImporte($importe) {
        if ($importe === null || $importe === '' || !is_numeric($importe)) {
            throw new Exception('El importe tiene que ser un número.');
        }

        $n = round(floatval($importe), 2);

        if ($n <= 0) {
            throw new Exception('El importe tiene que ser mayor a 0. Para no pagar, da de baja el pago.');
        }

        return $n;
    }

    /**
     * Alta o correccion de un pago puntual.
     *
     * SOLO PARA UN FLETERO ACTIVO Y NO HABITUAL: a un habitual el pago se le
     * calcula, y un puntual al lado se sumaria a la cuenta.
     *
     * UN PAGO QUE YA PASO NO SE CORRIGE: es historial.
     *
     * @param int|null $id null para el alta
     * @param string $codProvee
     * @param mixed $fecha
     * @param mixed $importe
     * @param string $usuario
     * @param string|null $hoy 'Y-m-d'
     * @return array ['id', 'cod_provee', 'fecha', 'importe', 'nuevo' => bool]
     */
    public function guardarPagoPuntual($id, $codProvee, $fecha, $importe, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;
        $this->exigirNoHabituales();

        $cod = Planilla::codigo($codProvee);
        $fletero = $this->getFletero($cod);

        if ($fletero === null || !$fletero['ACTIVO']) {
            throw new Exception("El fletero '$cod' no está cargado o está dado de baja.");
        }

        if ($fletero['HABITUAL']) {
            throw new Exception('A un fletero habitual el pago se le calcula con las horas y el '
                . 'valor hora. Para cargarle pagos puntuales, marcalo primero como no habitual.');
        }

        $v = self::validarPagoPuntual($fecha, $importe, $hoy);
        $cid = $this->conectar();

        if ($id === null || $id === '' || intval($id) === 0) {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA_PUNTUAL . "
                    (COD_PROVEE, FECHA, IMPORTE, USUARIO_ALTA, USUARIO_MODIF)
                 OUTPUT INSERTED.ID
                 VALUES (?, ?, ?, ?, ?)",
                [$cod, $v['fecha'], $v['importe'], $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al cargar el pago puntual'));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            return ['id' => intval($row['ID']), 'cod_provee' => $cod, 'fecha' => $v['fecha'],
                    'importe' => $v['importe'], 'nuevo' => true];
        }

        $actual = $this->getPagoPuntual($id, $cod);

        if ($actual['FECHA'] <= $hoy) {
            throw new Exception('Ese pago ya pasó y quedó en el historial: no se corrige.');
        }

        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_PUNTUAL . "
             SET FECHA = ?, IMPORTE = ?, " . Auditoria::SET_MODIF . "
             WHERE ID = ? AND VIGENTE = 1",
            [$v['fecha'], $v['importe'], $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al corregir el pago puntual'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id' => intval($id), 'cod_provee' => $cod, 'fecha' => $v['fecha'],
                'importe' => $v['importe'], 'nuevo' => false];
    }

    /**
     * Baja logica de un pago puntual. Uno que ya paso no se da de baja: es
     * historial.
     *
     * @param int $id
     * @param string $codProvee
     * @param string $usuario
     * @param string|null $hoy
     * @return array ['id', 'fecha', 'importe']
     */
    public function bajaPagoPuntual($id, $codProvee, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : $hoy;
        $this->exigirNoHabituales();

        $actual = $this->getPagoPuntual($id, Planilla::codigo($codProvee));

        if ($actual['FECHA'] <= $hoy) {
            throw new Exception('Ese pago ya pasó y quedó en el historial: no se da de baja.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA_PUNTUAL . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el pago puntual'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id' => intval($id), 'fecha' => $actual['FECHA'], 'importe' => $actual['IMPORTE']];
    }

    /** Un pago puntual vigente de un fletero, o lanza */
    private function getPagoPuntual($id, $cod) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, COD_PROVEE, FECHA, IMPORTE FROM dbo." . self::TABLA_PUNTUAL . "
             WHERE ID = ? AND COD_PROVEE = ? AND VIGENTE = 1", [intval($id), $cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar el pago puntual'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('Ese pago puntual no existe o ya fue dado de baja.');
        }

        return ['ID' => intval($row['ID']), 'FECHA' => self::dia($row['FECHA']),
                'IMPORTE' => floatval($row['IMPORTE'])];
    }

    /* ====================================================================
       EL IMPORTE DEL PROXIMO PAGO
       ==================================================================== */

    /**
     * Las ediciones vigentes del importe de un pago.
     *
     * @param string|null $desdeMes 'Y-m': solo las de ese mes en adelante
     * @return array Filas con COD_PROVEE, MES, NRO_PAGO, FECHA_PAGO, IMPORTE,
     *               IMPORTE_CALCULADO, USUARIO_ALTA, FECHA_ALTA
     */
    public function getEdiciones($desdeMes = null) {
        if (!$this->noHabitualesCreado()) {
            return [];
        }

        $params = [];
        $sql = "SELECT E.COD_PROVEE, E.MES, E.NRO_PAGO, E.FECHA_PAGO, E.IMPORTE, E.IMPORTE_CALCULADO,
                       E.USUARIO_ALTA, E.FECHA_ALTA, F.NOMBRE
                FROM dbo." . self::TABLA_EDIT . " E
                JOIN dbo." . self::TABLA . " F ON F.COD_PROVEE = E.COD_PROVEE
                WHERE E.VIGENTE = 1";

        if ($desdeMes !== null) {
            $sql .= " AND E.MES >= ?";
            $params[] = $desdeMes;
        }

        $stmt = sqlsrv_query($this->conectar(), $sql . " ORDER BY E.COD_PROVEE, E.MES, E.NRO_PAGO",
            $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los importes editados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'NOMBRE' => trim((string) $row['NOMBRE']),
                'MES' => trim((string) $row['MES']),
                'NRO_PAGO' => intval($row['NRO_PAGO']),
                'FECHA_PAGO' => self::dia($row['FECHA_PAGO']),
                'IMPORTE' => floatval($row['IMPORTE']),
                'IMPORTE_CALCULADO' => ($row['IMPORTE_CALCULADO'] === null)
                    ? null : floatval($row['IMPORTE_CALCULADO']),
                'USUARIO_ALTA' => $row['USUARIO_ALTA'],
                'FECHA_ALTA' => self::momento($row['FECHA_ALTA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Si un pago es el proximo de un fletero, segun la planilla. Estatica y pura.
     *
     * SOLO EL PROXIMO SE EDITA: los siguientes se calculan, y un importe real
     * se sabe cuando el pago esta cerca. Lo que manda el navegador es un pedido:
     * se verifica contra la planilla recalculada en el servidor.
     *
     * @param array $planilla Lo que devolvio LogisticaPlanilla::calcular()
     * @param string $cod
     * @param string $mes
     * @param int $nro
     * @return array El pago
     */
    public static function exigirProximo($planilla, $cod, $mes, $nro) {
        foreach ($planilla['fleteros'] as $f) {
            if ($f['cod_provee'] !== $cod) {
                continue;
            }

            if (empty($f['habitual'])) {
                throw new Exception('Un fletero no habitual no tiene pagos calculados: se le cargan '
                    . 'pagos puntuales.');
            }

            $prox = LogisticaPlanilla::proximoPago($f);

            if ($prox === null || $prox['mes'] !== $mes || intval($prox['nro']) !== intval($nro)) {
                throw new Exception('Sólo se puede editar el importe del próximo pago'
                    . ($prox === null ? '' : ', que es el del ' . $prox['fecha']) . '. Los '
                    . 'siguientes se calculan.');
            }

            return $prox;
        }

        throw new Exception("El fletero '$cod' no está activo en la planilla.");
    }

    /**
     * Carga el importe real del proximo pago de un fletero habitual. Da de baja
     * la edicion anterior de ese pago, si habia, y guarda la nueva con el
     * importe calculado del momento.
     *
     * @param Horizonte $h
     * @param string $codProvee
     * @param string $mes
     * @param int $nro
     * @param mixed $importe
     * @param string $usuario
     * @return array ['cod_provee', 'mes', 'nro', 'fecha', 'importe', 'importe_calculado']
     */
    public function guardarImporteProximo($h, $codProvee, $mes, $nro, $importe, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirNoHabituales();

        $cod = Planilla::codigo($codProvee);
        $imp = self::validarImporte($importe);
        $prox = self::exigirProximo($this->planilla($h)['planilla'], $cod, (string) $mes, $nro);
        $cid = $this->conectar();

        if (!sqlsrv_begin_transaction($cid)) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $this->bajaEdicion($cid, $cod, $prox['mes'], $prox['nro'], $usuario);

            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA_EDIT . "
                    (COD_PROVEE, MES, NRO_PAGO, FECHA_PAGO, IMPORTE_CALCULADO, IMPORTE,
                     USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$cod, $prox['mes'], intval($prox['nro']), $prox['fecha'],
                 $prox['importe_calculado'], $imp, $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar el importe del pago'));
            }

            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['cod_provee' => $cod, 'mes' => $prox['mes'], 'nro' => intval($prox['nro']),
                'fecha' => $prox['fecha'], 'importe' => $imp,
                'importe_calculado' => $prox['importe_calculado']];
    }

    /**
     * Deshace la edicion de un pago: vuelve al calculado. No borra nada.
     *
     * @return array ['habia' => bool]
     */
    public function deshacerImporte($codProvee, $mes, $nro, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirNoHabituales();

        $n = $this->bajaEdicion($this->conectar(), Planilla::codigo($codProvee),
            self::validarMesBase($mes), intval($nro), $usuario);

        return ['habia' => ($n > 0)];
    }

    /** VIGENTE = 0 la edicion vigente de un pago. @return int Cuantas */
    private function bajaEdicion($cid, $cod, $mes, $nro, $usuario) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA_EDIT . "
             SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             WHERE COD_PROVEE = ? AND MES = ? AND NRO_PAGO = ? AND VIGENTE = 1",
            [$usuario, $usuario, $cod, $mes, intval($nro)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el importe editado'));
        }

        $n = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return intval($n);
    }

    /** Lanza si el script de los no habituales no se corrio */
    private function exigirNoHabituales() {
        if (!$this->noHabitualesCreado()) {
            throw new Exception($this->avisoSinNoHabituales());
        }
    }

    /** Lleva a 'Y-m-d' lo que devuelve sqlsrv para una columna DATE */
    private static function dia($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d');
        }

        return ($valor === null || $valor === '') ? null : substr((string) $valor, 0, 10);
    }

    /** Lleva a 'Y-m-d H:i:s' lo que devuelve sqlsrv para un DATETIME */
    private static function momento($valor) {
        if ($valor instanceof DateTime) {
            return $valor->format('Y-m-d H:i:s');
        }

        return ($valor === null || $valor === '') ? null : (string) $valor;
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
