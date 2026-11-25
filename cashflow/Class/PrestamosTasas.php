<?php

require_once __DIR__ . '/Aviso.php';

/**
 * PrestamosTasas
 * Las tasas de referencia de los prestamos a tasa variable -TAMAR y BADLAR-,
 * tal como las publica el BCRA, y la regla con la que se proyectan.
 *
 * DE DONDE SALEN. El proceso de Sistemas (Franco Pertus) corre todos los dias a
 * las 8:00 y graba en XL-APPS / sistemas:
 *
 *   dbo.FP_BCRA_VARIABLES_MONETARIAS   una fila por fecha y variable. Valor en
 *                                      % n.a. (23.6900 = 23,69 %); la UVA en
 *                                      pesos, que aca no se usa
 *   dbo.FP_BCRA_VARIABLES_LOG          una fila por corrida y variable, con
 *                                      Estado OK / SIN_DATO / ERROR
 *
 * SOLO LEE, como DolarFuturo con FP_DOLAR_DIVISAS: el nombre de cuatro partes
 * desde 'central', y ningun metodo arma un INSERT, un UPDATE ni un DELETE. Lo
 * fija una prueba. EL CASHFLOW NO ESCRIBE EN [XL-APPS].
 *
 * SE BUSCA POR IdVariable, NO POR EL TEXTO: 'TAMAR' es una etiqueta que el
 * proceso podria cambiar; el id es el de la API del BCRA.
 *
 * LOS VALORES VIENEN EN %. La lectura los deja en % (asi se muestran) y la
 * division por 100 se hace UNA vez, en tnaDecimal(), justo antes de calcular.
 *
 * NUNCA LANZA. Sin XL-APPS devuelve listas vacias y deja el motivo en error():
 * los prestamos variables no se proyectan -con aviso critico- y los fijos
 * siguen como si nada.
 *
 * LA REGLA ES PURA y vive en los metodos estaticos: base(), promedio(),
 * ultimo(), avisos(). PrestamosCalculo la llama con lo ya leido.
 */
class PrestamosTasas {

    const ORIGEN_VALORES = '[XL-APPS].sistemas.dbo.FP_BCRA_VARIABLES_MONETARIAS';
    const ORIGEN_LOG = '[XL-APPS].sistemas.dbo.FP_BCRA_VARIABLES_LOG';

    /** Las tasas de referencia y su IdVariable en la API del BCRA */
    const TAMAR = 'TAMAR';
    const BADLAR = 'BADLAR';
    const ID_VARIABLE = [self::TAMAR => 44, self::BADLAR => 7];
    const NOMBRES = [self::TAMAR => 'TAMAR', self::BADLAR => 'BADLAR'];

    /** Con que criterio se proyecta la tasa base de un prestamo */
    const PROMEDIO = 'PROMEDIO';
    const ULTIMO = 'ULTIMO';
    const CRITERIOS = [self::PROMEDIO => 'Promedio de los últimos días hábiles', self::ULTIMO => 'Último valor publicado'];

    /**
     * De donde salio la tasa de una cuota. Viaja a la grilla del cronograma.
     * FIJA y CORREGIDA los pone PrestamosCalculo; el resto, base().
     */
    const O_FIJA = 'FIJA';
    const O_PROMEDIO = 'PROMEDIO';
    const O_PROMEDIO_VIEJO = 'PROMEDIO_VIEJO';
    const O_ULTIMO = 'ULTIMO';
    const O_MANUAL = 'MANUAL';
    const O_CORREGIDA = 'CORREGIDA';
    const NOMBRE_ORIGEN = [
        self::O_FIJA => 'Fija',
        self::O_PROMEDIO => 'Promedio',
        self::O_PROMEDIO_VIEJO => 'Promedio más viejo disponible',
        self::O_ULTIMO => 'Último valor',
        self::O_MANUAL => 'Manual',
        self::O_CORREGIDA => 'Corregida'
    ];

    /** Los estados del log que piden atencion */
    const ESTADOS_MALOS = ['ERROR', 'SIN_DATO'];

    /** Los defaults de los dos parametros, escritos tambien en sql/cashflow_prestamos.sql */
    const DIAS_PROMEDIO_INICIAL = 5;
    const ANTIGUEDAD_MAX_INICIAL = 5;

    /** @var Conexion */
    private $conn;

    /** @var string|null */
    private $error = null;

    /** @var array|null Cache: una lectura por pedido */
    private $leido = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LECTURA (solo SELECT)
       ==================================================================== */

    /**
     * Los valores publicados de TAMAR y BADLAR y la ultima corrida del log de
     * cada una. NO LANZA: sin conexion o sin las tablas, listas vacias y el
     * motivo en error().
     *
     * @return array ['series' => [ref => ['Y-m-d' => valor en %]], 'log' => [ref => fila|null],
     *                'disponible' => bool]
     */
    public function leer() {
        if ($this->leido !== null) {
            return $this->leido;
        }

        $this->leido = ['series' => [self::TAMAR => [], self::BADLAR => []],
                        'log' => [self::TAMAR => null, self::BADLAR => null], 'disponible' => false];

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            $this->error = 'No se pudo conectar a la base para leer las tasas del BCRA.';

            return $this->leido;
        }

        $ids = implode(',', array_values(self::ID_VARIABLE));

        $stmt = @sqlsrv_query($cid, "SELECT CAST(Fecha AS DATE) AS Fecha, IdVariable, CAST(Valor AS FLOAT) AS Valor
                                      FROM " . self::ORIGEN_VALORES . "
                                      WHERE IdVariable IN ($ids) AND Valor IS NOT NULL
                                      ORDER BY Fecha");

        if ($stmt === false) {
            $this->error = 'No se pudieron leer las tasas del BCRA (' . self::ORIGEN_VALORES . '): '
                . self::mensajeSql();

            return $this->leido;
        }

        $porId = array_flip(self::ID_VARIABLE);

        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $ref = $porId[(int) $r['IdVariable']] ?? null;

            if ($ref !== null) {
                $this->leido['series'][$ref][self::fecha($r['Fecha'])] = (float) $r['Valor'];
            }
        }

        sqlsrv_free_stmt($stmt);
        $this->leido['disponible'] = true;

        // El log es secundario: si no se puede leer, las tasas igual sirven.
        $stmt = @sqlsrv_query($cid, "SELECT L.IdVariable, L.FechaEjecucion, L.Estado, CAST(L.UltimaFechaDato AS DATE) AS UltimaFechaDato,
                                            L.Detalle
                                     FROM " . self::ORIGEN_LOG . " L
                                     WHERE L.IdVariable IN ($ids)
                                       AND L.Id = (SELECT MAX(X.Id) FROM " . self::ORIGEN_LOG . " X WHERE X.IdVariable = L.IdVariable)");

        if ($stmt !== false) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $ref = $porId[(int) $r['IdVariable']] ?? null;

                if ($ref !== null) {
                    $this->leido['log'][$ref] = [
                        'estado' => strtoupper(trim((string) $r['Estado'])),
                        'ejecucion' => ($r['FechaEjecucion'] instanceof DateTime) ? $r['FechaEjecucion']->format('Y-m-d H:i') : (string) $r['FechaEjecucion'],
                        'ultima_fecha' => self::fecha($r['UltimaFechaDato']),
                        'detalle' => (string) $r['Detalle']
                    ];
                }
            }

            sqlsrv_free_stmt($stmt);
        }

        return $this->leido;
    }

    /** @return string|null Por que no se pudieron leer, o null */
    public function error() {
        $this->leer();

        return $this->error;
    }

    private static function fecha($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 10);
    }

    private static function mensajeSql() {
        $e = sqlsrv_errors();

        return isset($e[0]['message']) ? $e[0]['message'] : 'error desconocido';
    }

    /* ====================================================================
       LA REGLA (pura)
       ==================================================================== */

    /** % n.a. -> decimal. La unica division por 100 de la tasa. */
    public static function tnaDecimal($pct) {
        return ($pct === null) ? null : round((float) $pct / 100, 10);
    }

    /**
     * Los valores de una serie anteriores a una fecha (exclusive), en orden.
     *
     * @param array $serie 'Y-m-d' => valor
     * @param string|null $antesDe null = todos
     * @return array 'Y-m-d' => valor, ordenado por fecha
     */
    private static function hasta($serie, $antesDe) {
        $serie = is_array($serie) ? $serie : [];
        ksort($serie);

        if ($antesDe === null) {
            return $serie;
        }

        return array_filter($serie, function ($f) use ($antesDe) { return $f < $antesDe; }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * El promedio simple de los ultimos N registros con dato anteriores a una
     * fecha. La tabla solo tiene dias habiles publicados, asi que no hace
     * falta el calendario.
     *
     * @return array|null ['valor', 'desde', 'hasta', 'n', 'suficiente'] o null sin ningun dato
     */
    public static function promedio($serie, $n, $antesDe = null) {
        $n = max(1, (int) $n);
        $lista = self::hasta($serie, $antesDe);

        if (empty($lista)) {
            return null;
        }

        $ultimos = array_slice($lista, -$n, null, true);
        $fechas = array_keys($ultimos);

        return [
            'valor' => round(array_sum($ultimos) / count($ultimos), 6),
            'desde' => $fechas[0],
            'hasta' => end($fechas),
            'n' => count($ultimos),
            'suficiente' => count($ultimos) >= $n
        ];
    }

    /** El primer promedio de N que se puede armar: el mas viejo disponible. */
    public static function promedioMasViejo($serie, $n) {
        $n = max(1, (int) $n);
        $lista = self::hasta($serie, null);

        if (empty($lista)) {
            return null;
        }

        $primeros = array_slice($lista, 0, $n, true);
        $fechas = array_keys($primeros);

        return ['valor' => round(array_sum($primeros) / count($primeros), 6), 'desde' => $fechas[0],
                'hasta' => end($fechas), 'n' => count($primeros), 'suficiente' => count($primeros) >= $n];
    }

    /** El ultimo valor publicado antes de una fecha (exclusive). */
    public static function ultimo($serie, $antesDe = null) {
        $lista = self::hasta($serie, $antesDe);

        if (empty($lista)) {
            return null;
        }

        $fechas = array_keys($lista);
        $f = end($fechas);

        return ['valor' => (float) $lista[$f], 'fecha' => $f];
    }

    /**
     * La tasa base (en %) del periodo de intereses que empieza en $inicio.
     *
     * EL ORDEN DE PRECEDENCIA (la correccion de la cuota, que gana sobre todo,
     * la aplica PrestamosCalculo antes de llamar aca):
     *   1. El valor manual de Parametros para el mes de $inicio.
     *   2. Si el periodo ya empezo ($inicio <= hoy): el promedio de los N
     *      registros anteriores a $inicio (o el ultimo, segun el criterio). Si
     *      no hay N registros antes de esa fecha, el promedio mas viejo
     *      disponible, con aviso.
     *   3. Si es futuro: la base vigente, el promedio de los ultimos N
     *      registros (o el ultimo publicado), constante.
     *
     * @param array $serie 'Y-m-d' => valor en % de la tasa de referencia
     * @param string $inicio 'Y-m-d'
     * @param string $hoy 'Y-m-d'
     * @param string $criterio PROMEDIO | ULTIMO
     * @param int $n Dias del promedio
     * @param array $manual 'Y-m' => valor en % (los de esta tasa)
     * @return array ['valor' (% o null), 'origen', 'detalle', 'aviso' (string|null)]
     */
    public static function base($serie, $inicio, $hoy, $criterio, $n, $manual = []) {
        $mes = substr((string) $inicio, 0, 7);
        $manual = is_array($manual) ? $manual : [];

        if (isset($manual[$mes]) && $manual[$mes] !== null && $manual[$mes] !== '') {
            return ['valor' => (float) $manual[$mes], 'origen' => self::O_MANUAL,
                    'detalle' => 'valor cargado a mano para ' . $mes, 'aviso' => null];
        }

        $empezado = ($inicio <= $hoy);
        $antesDe = $empezado ? $inicio : null;

        if ($criterio === self::ULTIMO) {
            $u = self::ultimo($serie, $antesDe);

            if ($u === null && $empezado) {
                $p = self::promedioMasViejo($serie, 1);

                return ($p === null)
                    ? ['valor' => null, 'origen' => null, 'detalle' => 'sin datos', 'aviso' => null]
                    : ['valor' => $p['valor'], 'origen' => self::O_PROMEDIO_VIEJO,
                       'detalle' => 'no hay publicaciones antes del ' . self::corta($inicio) . ': se usa la más vieja (' . self::corta($p['desde']) . ')',
                       'aviso' => 'antes del ' . self::corta($inicio)];
            }

            return ($u === null)
                ? ['valor' => null, 'origen' => null, 'detalle' => 'sin datos', 'aviso' => null]
                : ['valor' => $u['valor'], 'origen' => self::O_ULTIMO,
                   'detalle' => 'último valor publicado (' . self::corta($u['fecha']) . ')', 'aviso' => null];
        }

        $p = self::promedio($serie, $n, $antesDe);

        if ($p === null || !$p['suficiente']) {
            $viejo = self::promedioMasViejo($serie, $n);

            if ($viejo === null) {
                return ['valor' => null, 'origen' => null, 'detalle' => 'sin datos', 'aviso' => null];
            }

            if ($empezado || $p === null) {
                return ['valor' => $viejo['valor'], 'origen' => self::O_PROMEDIO_VIEJO,
                        'detalle' => 'no hay ' . (int) $n . ' publicaciones antes del ' . self::corta($inicio)
                            . ': se usa el promedio más viejo disponible (' . self::corta($viejo['desde']) . ' a ' . self::corta($viejo['hasta']) . ')',
                        'aviso' => 'antes del ' . self::corta($inicio)];
            }
        }

        return ['valor' => $p['valor'], 'origen' => self::O_PROMEDIO,
                'detalle' => 'promedio de ' . $p['n'] . ' publicaciones (' . self::corta($p['desde']) . ' a ' . self::corta($p['hasta']) . ')',
                'aviso' => null];
    }

    /**
     * Cuantos dias habiles pasaron desde una fecha (exclusive) hasta hoy
     * (inclusive). Un dia que el mapa no tiene vale de lunes a viernes, como
     * DiasHabiles.
     */
    public static function habilesDesde($fecha, $hoy, $habiles) {
        $n = 0;
        $cursor = date('Y-m-d', strtotime($fecha . ' +1 day'));

        while ($cursor <= $hoy) {
            $es = isset($habiles[$cursor]) ? (bool) $habiles[$cursor] : ((int) date('N', strtotime($cursor)) <= 5);
            $n += $es ? 1 : 0;
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        return $n;
    }

    /**
     * Los avisos de las tasas, para las referencias que algun prestamo
     * variable usa. Uno por hecho.
     *
     * @param array $leido Lo de leer()
     * @param string|null $error Lo de error()
     * @param string[] $usadas Las referencias de los prestamos variables
     * @param string $hoy
     * @param array $habiles
     * @param int $antiguedadMax Dias habiles de antiguedad que se toleran
     * @return array Avisos ['nivel', 'texto', 'seccion']
     */
    public static function avisos($leido, $error, $usadas, $hoy, $habiles, $antiguedadMax) {
        $out = [];

        if (empty($usadas)) {
            return $out;
        }

        if ($error !== null || empty($leido['disponible'])) {
            $out[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer las tasas de referencia del BCRA'
                . ($error !== null ? ' (' . $error . ')' : '') . '. Los préstamos a tasa variable no se proyectan; '
                . 'los de tasa fija, sí.');

            return $out;
        }

        foreach ($usadas as $ref) {
            $nombre = self::NOMBRES[$ref] ?? $ref;
            $u = self::ultimo($leido['series'][$ref] ?? [], null);
            $log = $leido['log'][$ref] ?? null;

            if ($u === null) {
                $out[] = Aviso::nuevo(Aviso::DANGER, 'No hay ningún valor publicado de ' . $nombre
                    . ': los préstamos que la usan no se proyectan.');
                continue;
            }

            if ($log !== null && in_array($log['estado'], self::ESTADOS_MALOS, true)) {
                $out[] = Aviso::nuevo(Aviso::WARNING, 'La última actualización de ' . $nombre . ' terminó en '
                    . $log['estado'] . ' (' . $log['ejecucion'] . '). Se proyecta con el último valor disponible, del '
                    . self::corta($u['fecha']) . '.');
            }

            $dias = self::habilesDesde($u['fecha'], $hoy, $habiles);

            if ($dias > (int) $antiguedadMax) {
                $out[] = Aviso::nuevo(Aviso::WARNING, 'El último valor de ' . $nombre . ' es del ' . self::corta($u['fecha'])
                    . ', hace ' . $dias . ' días hábiles (se toleran ' . (int) $antiguedadMax . '). Revisá con Sistemas '
                    . 'que el proceso de tasas del BCRA esté corriendo.');
            }
        }

        return $out;
    }

    /** dd/mm/aaaa */
    public static function corta($fecha) {
        $t = strtotime((string) $fecha);

        return $t ? date('d/m/Y', $t) : (string) $fecha;
    }
}
