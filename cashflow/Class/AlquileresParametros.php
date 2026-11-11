<?php

require_once __DIR__ . '/AlquileresConceptos.php';
require_once __DIR__ . '/AlquileresCalculo.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/ProveedoresOpciones.php';
require_once __DIR__ . '/Planilla.php';

/**
 * AlquileresParametros
 * Parametros -> Alquileres: lo que el cashflow necesita y la app de alquileres
 * no tiene. El dia de vencimiento general, las razones sociales dadas de alta,
 * los datos de cada local y los pagos extraordinarios. Las tablas son las de
 * sql/cashflow_alquileres.sql.
 *
 * UNA FILA VIGENTE POR CLAVE, CON HISTORIAL. Cambiar un dato es dar de baja la
 * vigente y dar de alta otra, en una transaccion: cada cambio queda con quien y
 * cuando, y rige desde ese momento. No hay bajas fisicas.
 *
 * LAS RAZONES SOCIALES SE DAN DE ALTA, NO SALEN DEL RUBRO
 * -------------------------------------------------------
 * El rubro del maestro de Proveedores Locales (la columna RUBRO, = ALQUILER,
 * con la comparacion tolerante de ProveedoresOpciones::buscarEnLista()) arma
 * la lista del buscador y es contra lo que el servidor valida un alta. Pero
 * que un proveedor sea de alquiler lo decide alguien, al darlo de alta: con el
 * rubro aparecen tambien Mercado Libre y Sploder. Un proveedor dado de alta que
 * despues pierde el rubro SIGUE DE ALTA, con un aviso: darlo de baja es una
 * decision. Es el mismo criterio que decide que factura de Tango es de
 * alquiler y sale de Proveedores Locales: AlquileresFacturas::esDeAlquiler().
 *
 * LA VALIDACION MANDA ACA. Los validadores son estaticos y puros, y la
 * pantalla solo explica y bloquea botones.
 */
class AlquileresParametros {

    const SCRIPT = 'sql/cashflow_alquileres.sql';

    const T_GENERAL = 'RO_T_CASHFLOW_ALQ_GENERAL';
    const T_RAZON = 'RO_T_CASHFLOW_ALQ_RAZON';
    const T_LOCAL = 'RO_T_CASHFLOW_ALQ_LOCAL';
    const T_EXTRA = 'RO_T_CASHFLOW_ALQ_EXTRA';
    const T_EXTRA_LOCAL = 'RO_T_CASHFLOW_ALQ_EXTRA_LOCAL';

    const TABLAS = [self::T_GENERAL, self::T_RAZON, self::T_LOCAL, self::T_EXTRA, self::T_EXTRA_LOCAL];

    /** El valor de la columna RUBRO del maestro que arma el buscador */
    const RUBRO = 'ALQUILER';

    const DIA_MIN = 1;
    const DIA_MAX = 28;
    const DIFERIMIENTO_MAX = 12;

    /** @var Conexion */
    private $conn;

    /** @var resource|null */
    private $cid = null;

    /** @var string[]|null */
    private $faltan = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       VALIDADORES PUROS
       ==================================================================== */

    /**
     * Un entero dentro de un rango. Vacio = null si se permite.
     *
     * @return int|null
     * @throws InvalidArgumentException
     */
    public static function entero($valor, $min, $max, $nombre, $vacioPermitido) {
        if ($valor === null || trim((string) $valor) === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        $t = trim((string) $valor);

        if (!preg_match('/^-?\d+$/', $t) || (int) $t < $min || (int) $t > $max) {
            throw new InvalidArgumentException("$nombre tiene que ser un número entero de $min a $max.");
        }

        return (int) $t;
    }

    /**
     * Un porcentaje >= 0 con hasta dos decimales. Acepta coma decimal.
     *
     * @return float|null Null si vino vacio y se permite
     * @throws InvalidArgumentException
     */
    public static function porcentaje($valor, $nombre, $vacioPermitido = true, $estricto = false) {
        if ($valor === null || trim((string) $valor) === '') {
            if ($vacioPermitido) {
                return null;
            }

            throw new InvalidArgumentException("Falta $nombre.");
        }

        $t = str_replace(',', '.', trim((string) $valor));

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) {
            throw new InvalidArgumentException("$nombre tiene que ser un número mayor o igual a 0, con hasta dos decimales.");
        }

        $v = (float) $t;

        if ($estricto && $v <= 0) {
            throw new InvalidArgumentException("$nombre tiene que ser mayor que 0.");
        }

        if ($v > 1000) {
            throw new InvalidArgumentException("$nombre no puede ser mayor que 1000 %.");
        }

        return $v;
    }

    /**
     * Los datos de un local, normalizados. Vacio es null: el local queda
     * incompleto y no proyecta, pero se puede guardar a medias (la grilla se
     * completa de a una columna). Lo que NO se acepta es un dato invalido, ni
     * un trimestral sin mes del ultimo ajuste: ese es el error que la
     * pantalla tiene que explicar.
     *
     * @param array $d
     * @return array
     * @throws InvalidArgumentException
     */
    public static function validarLocal($d) {
        $frec = self::entero($d['frecuencia'] ?? null, 1, 6, 'La frecuencia', true);

        if ($frec !== null && !isset(AlquileresCalculo::FRECUENCIAS[$frec])) {
            throw new InvalidArgumentException('La frecuencia tiene que ser mensual, bimestral, trimestral o semestral.');
        }

        $mes = self::entero($d['mes_ajuste'] ?? null, 1, 12, 'El mes del último ajuste', true);

        if ($frec !== null && $frec !== 1 && $mes === null) {
            throw new InvalidArgumentException('Con frecuencia ' . mb_strtolower(AlquileresCalculo::FRECUENCIAS[$frec])
                . ' hace falta el mes del último ajuste: es el que ancla el ciclo.');
        }

        if ($frec === 1) {
            $mes = null;   // en mensual no se usa: guardarlo seria un dato muerto
        }

        $cod = trim((string) ($d['cod_provee'] ?? ''));
        $fin = trim((string) ($d['fecha_fin'] ?? ''));

        if ($fin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            throw new InvalidArgumentException('La fecha de fin tiene que ser una fecha (AAAA-MM-DD).');
        }

        $venta = trim(str_replace(',', '.', (string) ($d['venta_mensual_estimada'] ?? '')));

        if ($venta !== '' && (!is_numeric($venta) || (float) $venta <= 0)) {
            throw new InvalidArgumentException('La venta mensual estimada tiene que ser un importe mayor que 0.');
        }

        return [
            'cod_provee' => ($cod === '') ? null : $cod,
            'frecuencia' => $frec,
            'mes_ajuste' => $mes,
            'pct_iva' => self::porcentaje($d['pct_iva'] ?? null, 'El % IVA'),
            'pct_percepcion' => self::porcentaje($d['pct_percepcion'] ?? null, 'El % percepción'),
            'pct_retencion' => self::porcentaje($d['pct_retencion'] ?? null, 'El % retención'),
            'fecha_fin' => ($fin === '') ? null : $fin,
            'venta_mensual_estimada' => ($venta === '') ? null : round((float) $venta, 2)
        ];
    }

    /**
     * Un pago extraordinario, normalizado.
     *
     * @param array $d
     * @return array
     * @throws InvalidArgumentException
     */
    public static function validarExtra($d) {
        $cod = trim((string) ($d['cod_provee'] ?? ''));

        if ($cod === '') {
            throw new InvalidArgumentException('Falta la razón social.');
        }

        $conceptos = array_values(array_unique(array_map('intval', (array) ($d['conceptos'] ?? []))));
        sort($conceptos);

        if (empty($conceptos)) {
            throw new InvalidArgumentException('Falta al menos un concepto base.');
        }

        foreach ($conceptos as $c) {
            if (!in_array($c, AlquileresConceptos::basesExtra(), true)) {
                throw new InvalidArgumentException('El concepto ' . $c . ' no se proyecta, así que no puede ser base '
                    . 'de un pago extraordinario.');
            }
        }

        $todos = !empty($d['todos']);
        $locales = array_values(array_unique(array_map('intval', (array) ($d['locales'] ?? []))));

        if (!$todos && empty($locales)) {
            throw new InvalidArgumentException('Elegí al menos un local, o "todos los de la razón social".');
        }

        return [
            'cod_provee' => $cod,
            'todos' => $todos,
            'locales' => $todos ? [] : $locales,
            'conceptos' => $conceptos,
            'mes_base' => self::entero($d['mes_base'] ?? null, 1, 12, 'El mes base', false),
            'pct' => self::porcentaje($d['pct'] ?? null, 'El % de incremento', false, true),
            'diferimiento' => self::entero($d['diferimiento'] ?? 0, 0, self::DIFERIMIENTO_MAX,
                                           'Los meses de diferimiento', false),
            'observacion' => mb_substr(trim((string) ($d['observacion'] ?? '')), 0, 200)
        ];
    }

    /**
     * Si un proveedor del maestro tiene RUBRO = ALQUILER, con la comparacion
     * tolerante de ProveedoresOpciones.
     *
     * @param string|null $rubro
     * @return bool
     */
    public static function esRubroAlquiler($rubro) {
        return ProveedoresOpciones::buscarEnLista($rubro, [self::RUBRO => ['valor' => self::RUBRO]]) !== null;
    }

    /**
     * Los del maestro con RUBRO = ALQUILER: el universo del buscador.
     *
     * @param array $mapa COD_PROVEE => fila de ProveedoresCategorias::mapa()
     * @return array COD_PROVEE => ['cod', 'nombre', 'rubro_economico']
     */
    public static function universoDelMaestro($mapa) {
        $out = [];

        foreach ($mapa as $cod => $m) {
            if (self::esRubroAlquiler($m['RUBRO'] ?? null)) {
                $out[$cod] = [
                    'cod' => (string) $cod,
                    'nombre' => (string) ($m['NOMBRE'] ?? ''),
                    'rubro_economico' => $m['RUBRO_ECONOMICO'] ?? null
                ];
            }
        }

        ksort($out);

        return $out;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return string[] Las tablas del script que faltan */
    public function tablasFaltantes() {
        if ($this->faltan === null) {
            $this->faltan = [];

            foreach (self::TABLAS as $t) {
                $row = $this->fila("SELECT OBJECT_ID(?, 'U') AS ID", ['dbo.' . $t],
                                   'Error al verificar las tablas de alquileres');

                if ($row === null || $row['ID'] === null) {
                    $this->faltan[] = $t;
                }
            }
        }

        return $this->faltan;
    }

    public function tablaCreada() {
        return empty($this->tablasFaltantes());
    }

    public function avisoSinTabla() {
        return $this->tablaCreada() ? ''
            : 'Falta correr ' . self::SCRIPT . ' contra la base central: no existen '
              . implode(', ', $this->tablasFaltantes()) . '. Sin eso no se pueden cargar los '
              . 'parámetros de Alquileres y ningún local proyecta. El resto del cashflow funciona '
              . 'como siempre.';
    }

    /**
     * El dia de vencimiento general vigente.
     *
     * @return array|null ['dia', 'usuario', 'fecha']
     */
    public function general() {
        if (!$this->tablaCreada()) {
            return null;
        }

        $r = $this->fila("SELECT TOP 1 DIA_VENCIMIENTO, USUARIO_MODIF, FECHA_MODIF FROM dbo." . self::T_GENERAL
                         . " WHERE VIGENTE = 1", [], 'Error al leer el día de vencimiento general');

        return ($r === null) ? null
            : ['dia' => (int) $r['DIA_VENCIMIENTO'], 'usuario' => $r['USUARIO_MODIF'],
               'fecha' => self::momento($r['FECHA_MODIF'])];
    }

    /**
     * Las razones sociales: las vigentes (dadas de alta) y el historial.
     *
     * @param bool $soloVigentes
     * @return array Lista de ['cod', 'razon', 'dia', 'diferimiento', 'vigente', + auditoria]
     */
    public function razones($soloVigentes = true) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $sql = "SELECT COD_PROVEE, RAZON_SOC, DIA_VENCIMIENTO, MESES_DIFERIMIENTO, VIGENTE,
                       USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF, USUARIO_BAJA, FECHA_BAJA
                FROM dbo." . self::T_RAZON
              . ($soloVigentes ? " WHERE VIGENTE = 1" : "") . " ORDER BY COD_PROVEE, ID";

        $out = [];

        foreach ($this->filas($sql, [], 'Error al leer las razones sociales') as $r) {
            $out[] = [
                'cod' => $r['COD_PROVEE'],
                'razon' => $r['RAZON_SOC'],
                'dia' => ($r['DIA_VENCIMIENTO'] === null) ? null : (int) $r['DIA_VENCIMIENTO'],
                'diferimiento' => (int) $r['MESES_DIFERIMIENTO'],
                'vigente' => ((int) $r['VIGENTE']) === 1,
                'usuario_alta' => $r['USUARIO_ALTA'], 'fecha_alta' => self::momento($r['FECHA_ALTA']),
                'usuario_modif' => $r['USUARIO_MODIF'], 'fecha_modif' => self::momento($r['FECHA_MODIF']),
                'usuario_baja' => $r['USUARIO_BAJA'], 'fecha_baja' => self::momento($r['FECHA_BAJA'])
            ];
        }

        return $out;
    }

    /**
     * Las razones sociales dadas de alta, por codigo.
     *
     * @return array COD_PROVEE => ['dia', 'diferimiento', 'razon']
     */
    public function razonesVigentes() {
        $out = [];

        foreach ($this->razones(true) as $r) {
            $out[$r['cod']] = $r;
        }

        return $out;
    }

    /**
     * Los datos vigentes de cada local.
     *
     * @return array NRO_SUCURS => fila normalizada (ver validarLocal()) + auditoria
     */
    public function locales() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $sql = "SELECT NRO_SUCURS, COD_PROVEE, FRECUENCIA_MESES, MES_ULT_AJUSTE, PCT_IVA, PCT_PERCEPCION,
                       PCT_RETENCION, FECHA_FIN, VENTA_MENSUAL_ESTIMADA, USUARIO_MODIF, FECHA_MODIF
                FROM dbo." . self::T_LOCAL . " WHERE VIGENTE = 1";

        $out = [];

        foreach ($this->filas($sql, [], 'Error al leer los datos de los locales') as $r) {
            $out[(int) $r['NRO_SUCURS']] = [
                'cod_provee' => $r['COD_PROVEE'],
                'frecuencia' => ($r['FRECUENCIA_MESES'] === null) ? null : (int) $r['FRECUENCIA_MESES'],
                'mes_ajuste' => ($r['MES_ULT_AJUSTE'] === null) ? null : (int) $r['MES_ULT_AJUSTE'],
                'pct_iva' => self::numero($r['PCT_IVA']),
                'pct_percepcion' => self::numero($r['PCT_PERCEPCION']),
                'pct_retencion' => self::numero($r['PCT_RETENCION']),
                'fecha_fin' => ($r['FECHA_FIN'] instanceof DateTime) ? $r['FECHA_FIN']->format('Y-m-d')
                    : (($r['FECHA_FIN'] === null) ? null : substr((string) $r['FECHA_FIN'], 0, 10)),
                'venta_mensual_estimada' => self::numero($r['VENTA_MENSUAL_ESTIMADA']),
                'usuario' => $r['USUARIO_MODIF'],
                'fecha' => self::momento($r['FECHA_MODIF'])
            ];
        }

        return $out;
    }

    /**
     * Los pagos extraordinarios vigentes, con sus locales.
     *
     * @return array Lista de ['id', 'cod_provee', 'todos', 'locales', 'conceptos',
     *               'mes_base', 'pct', 'diferimiento', 'observacion', + auditoria]
     */
    public function extras() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $locales = [];

        foreach ($this->filas("SELECT ID_EXTRA, NRO_SUCURS FROM dbo." . self::T_EXTRA_LOCAL . " WHERE VIGENTE = 1",
                              [], 'Error al leer los locales de los extraordinarios') as $r) {
            $locales[(int) $r['ID_EXTRA']][] = (int) $r['NRO_SUCURS'];
        }

        $out = [];
        $sql = "SELECT ID, COD_PROVEE, TODOS_LOCALES, CONCEPTOS, MES_BASE, PCT, MESES_DIFERIMIENTO, OBSERVACION,
                       USUARIO_ALTA, FECHA_ALTA
                FROM dbo." . self::T_EXTRA . " WHERE VIGENTE = 1 ORDER BY ID";

        foreach ($this->filas($sql, [], 'Error al leer los pagos extraordinarios') as $r) {
            $id = (int) $r['ID'];
            $out[] = [
                'id' => $id,
                'cod_provee' => $r['COD_PROVEE'],
                'todos' => ((int) $r['TODOS_LOCALES']) === 1,
                'locales' => $locales[$id] ?? [],
                'conceptos' => array_map('intval', array_filter(explode(',', (string) $r['CONCEPTOS']), 'strlen')),
                'mes_base' => (int) $r['MES_BASE'],
                'pct' => (float) $r['PCT'],
                'diferimiento' => (int) $r['MESES_DIFERIMIENTO'],
                'observacion' => $r['OBSERVACION'],
                'usuario_alta' => $r['USUARIO_ALTA'],
                'fecha_alta' => self::momento($r['FECHA_ALTA'])
            ];
        }

        return $out;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * El dia de vencimiento general.
     */
    public function guardarGeneral($dia, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $d = self::entero($dia, self::DIA_MIN, self::DIA_MAX, 'El día de vencimiento', false);

        $this->enTransaccion(function () use ($d, $usuario) {
            $this->darDeBaja(self::T_GENERAL, "VIGENTE = 1", [], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_GENERAL
                . " (DIA_VENCIMIENTO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF) VALUES (?, 1, ?, ?)",
                [$d, $usuario, $usuario], 'Error al guardar el día de vencimiento general');
        });

        return ['dia' => $d];
    }

    /**
     * Da de alta una razon social. El servidor la valida contra el universo
     * (RUBRO = ALQUILER en el maestro): que aparezca en el buscador no
     * autoriza nada.
     *
     * @param string $cod
     * @param array $universo Lo de universoDelMaestro()
     * @param string $usuario
     * @return array ['cod', 'nuevo']
     */
    public function altaRazon($cod, $universo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $c = trim((string) $cod);

        if (!isset($universo[$c])) {
            throw new InvalidArgumentException('El proveedor ' . $c . ' no tiene RUBRO = ' . self::RUBRO
                . ' en el maestro de Proveedores Locales: no se puede dar de alta como razón social de alquiler.');
        }

        if (isset($this->razonesVigentes()[$c])) {
            return ['cod' => $c, 'nuevo' => false];
        }

        $this->ejecutar("INSERT INTO dbo." . self::T_RAZON
            . " (COD_PROVEE, RAZON_SOC, DIA_VENCIMIENTO, MESES_DIFERIMIENTO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
               VALUES (?, ?, NULL, 0, 1, ?, ?)",
            [$c, mb_substr($universo[$c]['nombre'], 0, 100), $usuario, $usuario], 'Error al dar de alta la razón social');

        return ['cod' => $c, 'nuevo' => true];
    }

    /**
     * El dia de vencimiento y el diferimiento de una razon dada de alta.
     */
    public function guardarRazon($cod, $dia, $diferimiento, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $c = trim((string) $cod);
        $d = self::entero($dia, self::DIA_MIN, self::DIA_MAX, 'El día de vencimiento', true);
        $m = self::entero($diferimiento, 0, self::DIFERIMIENTO_MAX, 'Los meses de diferimiento', false);
        $vigentes = $this->razonesVigentes();

        if (!isset($vigentes[$c])) {
            throw new InvalidArgumentException('La razón social ' . $c . ' no está dada de alta.');
        }

        $razon = $vigentes[$c]['razon'];

        $this->enTransaccion(function () use ($c, $d, $m, $razon, $usuario) {
            $this->darDeBaja(self::T_RAZON, "COD_PROVEE = ? AND VIGENTE = 1", [$c], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_RAZON
                . " (COD_PROVEE, RAZON_SOC, DIA_VENCIMIENTO, MESES_DIFERIMIENTO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                   VALUES (?, ?, ?, ?, 1, ?, ?)",
                [$c, $razon, $d, $m, $usuario, $usuario], 'Error al guardar la razón social');
        });

        return ['cod' => $c, 'dia' => $d, 'diferimiento' => $m];
    }

    /**
     * Da de baja una razon social. Sus locales quedan incompletos ("razon
     * social dada de baja") y sus extraordinarios dejan de alcanzar a nadie;
     * no se tocan, para que volver a darla de alta los recupere.
     */
    public function bajaRazon($cod, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $c = trim((string) $cod);

        if ($this->darDeBaja(self::T_RAZON, "COD_PROVEE = ? AND VIGENTE = 1", [$c], $usuario) < 1) {
            throw new InvalidArgumentException('La razón social ' . $c . ' no estaba dada de alta.');
        }

        return ['cod' => $c];
    }

    /**
     * Los datos de un local. Se valida que sea un local de la app y que su
     * razon social este dada de alta.
     *
     * @param int $nro
     * @param array $datos
     * @param array $universo NRO_SUCURS => ['nombre', ...] de AlquileresApp::locales()
     * @param string $usuario
     */
    public function guardarLocal($nro, $datos, $universo, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $n = (int) $nro;

        if (!isset($universo[$n])) {
            throw new InvalidArgumentException('El local ' . $n . ' no es un local de la app de alquileres.');
        }

        $v = self::validarLocal($datos);

        if ($v['cod_provee'] !== null && !isset($this->razonesVigentes()[$v['cod_provee']])) {
            throw new InvalidArgumentException('La razón social ' . $v['cod_provee'] . ' no está dada de alta en '
                . 'la sección Razones sociales.');
        }

        $nombre = mb_substr((string) $universo[$n]['nombre'], 0, 100);

        $this->enTransaccion(function () use ($n, $v, $nombre, $usuario) {
            $this->darDeBaja(self::T_LOCAL, "NRO_SUCURS = ? AND VIGENTE = 1", [$n], $usuario);
            $this->ejecutar("INSERT INTO dbo." . self::T_LOCAL
                . " (NRO_SUCURS, DESC_SUCURS, COD_PROVEE, FRECUENCIA_MESES, MES_ULT_AJUSTE, PCT_IVA, PCT_PERCEPCION,
                     PCT_RETENCION, FECHA_FIN, VENTA_MENSUAL_ESTIMADA, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$n, $nombre, $v['cod_provee'], $v['frecuencia'], $v['mes_ajuste'], $v['pct_iva'],
                 $v['pct_percepcion'], $v['pct_retencion'], $v['fecha_fin'], $v['venta_mensual_estimada'],
                 $usuario, $usuario],
                'Error al guardar los datos del local');
        });

        return ['nro' => $n] + $v;
    }

    /**
     * Da de alta un pago extraordinario. Los locales elegidos tienen que ser de
     * esa razon social.
     *
     * @param array $datos
     * @param array $localesDeLaRazon NRO_SUCURS de los locales que hoy tienen esa razon
     * @param string $usuario
     * @return array ['id']
     */
    public function altaExtra($datos, $localesDeLaRazon, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $v = self::validarExtra($datos);

        if (!isset($this->razonesVigentes()[$v['cod_provee']])) {
            throw new InvalidArgumentException('La razón social ' . $v['cod_provee'] . ' no está dada de alta.');
        }

        foreach ($v['locales'] as $nro) {
            if (!in_array($nro, array_map('intval', $localesDeLaRazon), true)) {
                throw new InvalidArgumentException('El local ' . $nro . ' no tiene esa razón social.');
            }
        }

        $id = null;

        $this->enTransaccion(function () use ($v, $usuario, &$id) {
            $row = $this->fila("INSERT INTO dbo." . self::T_EXTRA
                . " (COD_PROVEE, TODOS_LOCALES, CONCEPTOS, MES_BASE, PCT, MESES_DIFERIMIENTO, OBSERVACION, VIGENTE,
                     USUARIO_ALTA, USUARIO_MODIF)
                   OUTPUT INSERTED.ID
                   VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
                [$v['cod_provee'], $v['todos'] ? 1 : 0, implode(',', $v['conceptos']), $v['mes_base'], $v['pct'],
                 $v['diferimiento'], ($v['observacion'] === '') ? null : $v['observacion'], $usuario, $usuario],
                'Error al dar de alta el pago extraordinario');
            $id = (int) $row['ID'];

            foreach ($v['locales'] as $nro) {
                $this->ejecutar("INSERT INTO dbo." . self::T_EXTRA_LOCAL
                    . " (ID_EXTRA, NRO_SUCURS, VIGENTE, USUARIO_ALTA, USUARIO_MODIF) VALUES (?, ?, 1, ?, ?)",
                    [$id, $nro, $usuario, $usuario], 'Error al guardar los locales del pago extraordinario');
            }
        });

        return ['id' => $id] + $v;
    }

    /** Da de baja un pago extraordinario y sus locales. */
    public function bajaExtra($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTablas();
        $i = (int) $id;
        $filas = 0;

        $this->enTransaccion(function () use ($i, $usuario, &$filas) {
            $filas = $this->darDeBaja(self::T_EXTRA, "ID = ? AND VIGENTE = 1", [$i], $usuario);
            $this->darDeBaja(self::T_EXTRA_LOCAL, "ID_EXTRA = ? AND VIGENTE = 1", [$i], $usuario);
        });

        if ($filas < 1) {
            throw new InvalidArgumentException('El pago extraordinario ' . $i . ' no estaba vigente.');
        }

        return ['id' => $i];
    }

    /* ====================================================================
       AUXILIARES
       ==================================================================== */

    private function exigirTablas() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** Baja logica: VIGENTE = 0 con quien y cuando. Devuelve las filas tocadas. */
    private function darDeBaja($tabla, $where, $params, $usuario) {
        $stmt = $this->ejecutar("UPDATE dbo." . $tabla . " SET VIGENTE = 0, " . Auditoria::SET_BAJA
            . " WHERE " . $where, array_merge([$usuario, $usuario], $params), 'Error al dar de baja');
        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return (int) $filas;
    }

    private function enTransaccion($fn) {
        $cid = $this->conectar();

        if (!sqlsrv_begin_transaction($cid)) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $fn();
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }
    }

    private function ejecutar($sql, $params, $contexto) {
        $stmt = sqlsrv_query($this->conectar(), $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        return $stmt;
    }

    private function fila($sql, $params, $contexto) {
        $stmt = $this->ejecutar($sql, $params, $contexto);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ?: null;
    }

    private function filas($sql, $params, $contexto) {
        $stmt = $this->ejecutar($sql, $params, $contexto);
        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $out;
    }

    private static function numero($v) {
        return ($v === null) ? null : (float) $v;
    }

    private static function momento($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 16);
    }

    private function conectar() {
        if ($this->cid === null) {
            $cid = $this->conn->conectar('central');

            if (!$cid) {
                throw new Exception('No se pudo conectar a la base de datos central');
            }

            $this->cid = $cid;
        }

        return $this->cid;
    }

    private function errorSql($contexto) {
        $msg = $contexto . ': ';

        foreach ((array) sqlsrv_errors() as $e) {
            $msg .= $e['message'] . ' ';
        }

        return trim($msg);
    }
}
