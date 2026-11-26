<?php

require_once __DIR__ . '/ComprasProyectadasDatos.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * ComprasProyectadasRubros
 * El % importado por rubro: que parte de cada rubro del presupuesto de la app
 * de compras se compra en el exterior y entra en la proyeccion.
 *
 * QUE ES
 * ------
 * 100 %: el rubro entra completo. 0 %: queda excluido. Un valor intermedio,
 * por ejemplo 60 %, proyecta el 60 % de su FOB: el resto se compra o se
 * fabrica en el pais. Es SOLO por rubro, sin apertura por categoria.
 *
 * Reemplaza al filtro que la vista RO_V_COMPRA_PROYECTADA_VIGENTE tenia en la
 * base (rubro NOT LIKE '%cuero%'), que el cashflow no veia. El % lo aplica el
 * job (RO_SP_CASHFLOW_COMEX_PRESUP_RESUMEN) fila por fila, ANTES de sumar por
 * temporada. Las funciones puras de abajo -normalizar(), aplicar() y
 * excluido()- son la misma regla escrita en PHP, para las pantallas que leen
 * el presupuesto por rubro y para poder probarla sin base.
 *
 * UN RUBRO SIN FILA ENTRA AL 100 %, y el cashflow avisa "Rubro nuevo sin
 * configurar". Ni el job ni esta clase lo dan de alta solos.
 *
 * POR QUE VIVE APARTE DE ComprasProyectadasDatos: esa clase no escribe nada y
 * hay una prueba que lo verifica sobre el archivo entero. Mismo criterio que
 * ComprasProyectadasAjustes.
 *
 * SIN BAJAS FISICAS: cambiar un % marca VIGENTE = 0 la fila anterior e
 * inserta una nueva, en la misma transaccion. Ver guardar().
 *
 * EL CAMBIO RIGE DESDE QUE SE GUARDA, pero la proyeccion se recalcula en la
 * proxima corrida del job -o con "Recalcular la proyeccion" / "Actualizar
 * ahora"-. Hasta entonces el cashflow avisa que hay cambios posteriores al
 * ultimo calculo.
 */
class ComprasProyectadasRubros {

    /** La tabla, que crea sql/cashflow_compras_proy_rubro_pct.sql */
    const TABLA = ComprasProyectadasDatos::TABLA_RUBRO_PCT;

    /** Largo maximo del rubro, como lo declara la tabla */
    const RUBRO_MAX = 100;

    /** El % de un rubro que no esta en la tabla */
    const PCT_SIN_CONFIGURAR = 100.0;

    /**
     * Rubros a los que NO se les muestra la referencia de las OC, con el motivo.
     *
     * CALZADOS: se importan las partes y se arman en el pais. El armador
     * factura el servicio y tiene OC de proveedor nacional por el calzado
     * terminado, asi que las OC dirian "produccion nacional" de algo que se
     * importa: en VER 26-27 eran 97.158 unidades nacionales contra 11.550 del
     * exterior. El servicio se paga por Proveedores Locales.
     */
    const SIN_REFERENCIA_OC = [
        'CALZADOS' => 'Se importan las partes y se arman en el país: las OC nacionales son el '
            . 'servicio de armado, que se paga por Proveedores Locales. Las OC no sirven de referencia.'
    ];

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
       REGLAS PURAS
       ==================================================================== */

    /**
     * El rubro como se guarda y como se compara: en mayusculas, sin espacios
     * al principio ni al final y con los espacios repetidos colapsados a uno.
     *
     * ES LA MISMA REGLA QUE APLICA EL SP sobre el rubro de la vista, y tiene
     * que seguir siendolo: si se separan, un rubro configurado deja de
     * encontrar su % y entra al 100 % avisando que es nuevo. Solo espacios,
     * igual que alla: el REPLACE del SP no ve tabulaciones.
     *
     * @param mixed $rubro
     * @return string
     */
    public static function normalizar($rubro) {
        $r = trim((string) $rubro, ' ');
        $r = preg_replace('/ {2,}/', ' ', $r);

        return mb_strtoupper($r, 'UTF-8');
    }

    /**
     * Valida un % importado. PURA: no toca la base.
     *
     * Acepta coma o punto decimal: en pantalla se escribe "62,5".
     *
     * @param string $rubro
     * @param mixed $pct
     * @return array Lista de errores. Vacia = valido
     */
    public static function validar($rubro, $pct) {
        $errores = [];

        $rubro = self::normalizar($rubro);

        if ($rubro === '') {
            $errores[] = 'Falta el rubro.';
        } elseif (mb_strlen($rubro, 'UTF-8') > self::RUBRO_MAX) {
            $errores[] = 'El rubro no puede tener más de ' . self::RUBRO_MAX . ' caracteres.';
        }

        $texto = str_replace(',', '.', trim((string) $pct));

        if ($texto === '' || !is_numeric($texto)) {
            $errores[] = 'El % importado tiene que ser un número entre 0 y 100.';
        } elseif (floatval($texto) < 0 || floatval($texto) > 100) {
            $errores[] = 'El % importado tiene que estar entre 0 y 100: '
                . self::numero(floatval($texto)) . ' % no es una parte del rubro.';
        } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $texto)) {
            $errores[] = 'El % importado admite hasta dos decimales.';
        }

        return $errores;
    }

    /**
     * El % que corresponde a un rubro: el vigente, o 100 si no tiene.
     *
     * @param string $rubro
     * @param array $vigentes rubro normalizado => ['pct', ...] o => float
     * @return array ['pct' => float, 'configurado' => bool]
     */
    public static function pctDe($rubro, $vigentes) {
        $clave = self::normalizar($rubro);

        if (!isset($vigentes[$clave])) {
            return ['pct' => self::PCT_SIN_CONFIGURAR, 'configurado' => false];
        }

        $v = $vigentes[$clave];

        return ['pct' => floatval(is_array($v) ? $v['pct'] : $v), 'configurado' => true];
    }

    /**
     * Aplica el % a cada fila, SIN sumar: es la regla del SP -cada fila por
     * su %, antes de agrupar- escrita en PHP.
     *
     * @param array $filas Cada una con 'rubro' y 'fob_usd'
     * @param array $vigentes Ver pctDe()
     * @return array Las mismas filas con 'pct_importado', 'configurado' y
     *               'fob_proyectado'
     */
    public static function aplicar($filas, $vigentes) {
        $out = [];

        foreach ($filas as $f) {
            $p = self::pctDe(isset($f['rubro']) ? $f['rubro'] : '', $vigentes);

            $f['pct_importado'] = $p['pct'];
            $f['configurado'] = $p['configurado'];
            $f['fob_proyectado'] = floatval(isset($f['fob_usd']) ? $f['fob_usd'] : 0) * $p['pct'] / 100.0;

            $out[] = $f;
        }

        return $out;
    }

    /**
     * Cuanto FOB queda fuera de la proyeccion por el %, y por cuantos rubros.
     *
     * Se cuenta POR RUBRO y no por fila: un rubro en 0 % que esta en las dos
     * temporadas es un rubro, no dos. El importe si es la suma de todas las
     * temporadas: el FOB completo de las versiones oficiales, el mismo que
     * muestran Parametros y el detalle de cada version.
     *
     * @param array $rubros Lo que devuelve ComprasProyectadasDatos::presupuestoPorRubro():
     *                      cada uno con 'rubro', 'fob_presupuesto', 'pct_aplicado'
     * @return array ['fob' => float, 'en_cero' => [rubros], 'parciales' => [rubros]]
     */
    public static function excluido($rubros) {
        $fob = 0.0;
        $pcts = [];

        foreach ($rubros as $r) {
            $pct = floatval($r['pct_aplicado']);

            $fob += floatval($r['fob_presupuesto']) * (100.0 - $pct) / 100.0;
            $pcts[$r['rubro']] = $pct;
        }

        $enCero = array_keys(array_filter($pcts, function ($p) { return $p <= 0; }));
        $parciales = array_keys(array_filter($pcts, function ($p) { return $p > 0 && $p < 100; }));

        sort($enCero);
        sort($parciales);

        return ['fob' => $fob, 'en_cero' => $enCero, 'parciales' => $parciales];
    }

    /**
     * La tabla de Parametros: un renglon por rubro, venga del presupuesto, de
     * la tabla del %, o de los dos. PURA.
     *
     * EL FOB ES LA SUMA DE LAS TEMPORADAS CON VERSION OFICIAL, con el detalle
     * por temporada aparte para el tooltip. "Antes" es el FOB del presupuesto
     * que leyo el ultimo calculo; "despues" lo multiplica por el % vigente
     * HOY, asi un cambio recien guardado se ve en el momento, antes de que el
     * job recalcule.
     *
     *   en_presupuesto  false: tiene % pero ya no viene en el presupuesto. Se
     *                   muestra atenuado, no se borra.
     *   configurado     false: viene en el presupuesto y no tiene %. Entra al
     *                   100 %.
     *
     * @param array $presupuesto ComprasProyectadasDatos::presupuestoPorRubro()
     * @param array $vigentes ComprasProyectadasDatos::pctVigentes()
     * @return array Lista ordenada: primero los sin configurar, despues por FOB
     */
    public static function tablaParametros($presupuesto, $vigentes) {
        $filas = [];

        foreach ($presupuesto as $r) {
            $k = self::normalizar($r['rubro']);

            if (!isset($filas[$k])) {
                $filas[$k] = ['rubro' => $k, 'fob_presupuesto' => 0.0, 'temporadas' => [],
                              'en_presupuesto' => true];
            }

            $filas[$k]['fob_presupuesto'] += floatval($r['fob_presupuesto']);
            $filas[$k]['temporadas'][] = ['temporada' => $r['temporada'],
                                          'fob_presupuesto' => floatval($r['fob_presupuesto'])];
        }

        foreach ($vigentes as $k => $v) {
            if (!isset($filas[$k])) {
                $filas[$k] = ['rubro' => $k, 'fob_presupuesto' => 0.0, 'temporadas' => [],
                              'en_presupuesto' => false];
            }
        }

        $out = [];

        foreach ($filas as $k => $f) {
            $p = self::pctDe($k, $vigentes);

            $f['pct_importado'] = $p['configurado'] ? $p['pct'] : null;
            $f['configurado'] = $p['configurado'];
            $f['fob_proyectado'] = $f['fob_presupuesto'] * $p['pct'] / 100.0;
            $f['usuario'] = $p['configurado'] ? $vigentes[$k]['usuario'] : null;
            $f['fecha'] = $p['configurado'] ? $vigentes[$k]['fecha'] : null;

            foreach ($f['temporadas'] as $i => $t) {
                $f['temporadas'][$i]['fob_proyectado'] = $t['fob_presupuesto'] * $p['pct'] / 100.0;
            }

            $out[] = $f;
        }

        usort($out, function ($a, $b) {
            if ($a['en_presupuesto'] !== $b['en_presupuesto']) {
                return $a['en_presupuesto'] ? -1 : 1;
            }

            if ($a['configurado'] !== $b['configurado']) {
                return $a['configurado'] ? 1 : -1;
            }

            if ($a['fob_presupuesto'] != $b['fob_presupuesto']) {
                return ($a['fob_presupuesto'] > $b['fob_presupuesto']) ? -1 : 1;
            }

            return strcmp($a['rubro'], $b['rubro']);
        });

        return $out;
    }

    /* ====================================================================
       REFERENCIA: LO QUE SE COMPRO EN EL EXTERIOR EL ANIO ANTERIOR
       ==================================================================== */

    /**
     * La misma temporada del anio anterior, con su codigo en el articulo.
     *
     *   INV 27     -> INV 26,    '6W'   (XV6WKR02C0501)
     *   VER 27-28  -> VER 26-27, '6S'   (XT6SRZ03C0814)
     *
     * El codigo es el tercer y cuarto caracter del articulo: el ultimo digito
     * del anio en que ARRANCA la temporada y W/S. Se compara invierno con
     * invierno porque la mezcla cambia con la temporada: billeteras de cuero
     * tuvo 19,8 % del exterior en INV 26 y 0 % en VER 26-27.
     *
     * @param string $temporada 'INV AA' o 'VER AA-AA'
     * @return array|null ['temporada','codigo'] o null si no es una temporada
     */
    public static function temporadaAnterior($temporada) {
        $t = trim((string) $temporada);

        if (preg_match('/^INV (\d{2})$/', $t, $m)) {
            $a = (intval($m[1]) + 99) % 100;

            return ['temporada' => sprintf('INV %02d', $a), 'codigo' => ($a % 10) . 'W'];
        }

        if (preg_match('/^VER (\d{2})-(\d{2})$/', $t, $m)) {
            $a = (intval($m[1]) + 99) % 100;

            return ['temporada' => sprintf('VER %02d-%02d', $a, ($a + 1) % 100),
                    'codigo' => ($a % 10) . 'S'];
        }

        return null;
    }

    /**
     * Las temporadas de referencia de un presupuesto: la del anio anterior de
     * cada temporada que trae, sin repetir y en orden cronologico.
     *
     * @param array $presupuesto ComprasProyectadasDatos::presupuestoPorRubro()
     * @return array Lista de ['proyectada','temporada','codigo']
     */
    public static function temporadasReferencia($presupuesto) {
        $out = [];

        foreach ($presupuesto as $r) {
            $t = trim((string) $r['temporada']);
            $ant = self::temporadaAnterior($t);

            if ($ant !== null && !isset($out[$t])) {
                $out[$t] = ['proyectada' => $t] + $ant;
            }
        }

        /* Cronologico: INV AA arranca en febrero, VER AA-AA en agosto. */
        uasort($out, function ($a, $b) {
            return self::ordenTemporada($a['temporada']) - self::ordenTemporada($b['temporada']);
        });

        return array_values($out);
    }

    private static function ordenTemporada($t) {
        return intval(substr($t, 4, 2)) * 2 + (strpos($t, 'VER') === 0 ? 1 : 0);
    }

    /**
     * El % del exterior de cada rubro segun las OC de las temporadas de
     * referencia, en UNIDADES. PURA.
     *
     * EN UNIDADES Y NO EN IMPORTE: las OC nacionales estan en pesos, con una
     * cotizacion cargada a mano y muchos renglones a precio 0. Y el % se le
     * aplica a un FOB que es unidades por costo de importacion, asi que lo que
     * hay que estimar es que parte de las unidades viene de afuera.
     *
     * Un rubro sin OC en una temporada tiene pct null, no 0: "no se compro"
     * no es "se compro todo en el pais".
     *
     * @param array $lineas ComprasProyectadasDatos::ocPorTemporadaRubro():
     *                      cada una con 'codigo', 'rubro', 'ext', 'nac'
     * @param array $temporadas temporadasReferencia()
     * @return array rubro normalizado => ['temporadas' => [...], 'ext', 'nac', 'pct']
     */
    public static function referenciaOc($lineas, $temporadas) {
        $suma = [];

        foreach ($lineas as $l) {
            $k = self::normalizar($l['rubro']);
            $c = (string) $l['codigo'];

            if (!isset($suma[$k][$c])) {
                $suma[$k][$c] = ['ext' => 0.0, 'nac' => 0.0];
            }

            $suma[$k][$c]['ext'] += floatval($l['ext']);
            $suma[$k][$c]['nac'] += floatval($l['nac']);
        }

        $out = [];

        foreach ($suma as $k => $porCodigo) {
            $out[$k] = self::armarReferencia($porCodigo, $temporadas);
        }

        return $out;
    }

    /**
     * Le agrega a cada fila de tablaParametros() su referencia de las OC.
     *
     *   referencia_oc  ['temporadas','ext','nac','pct'], con pct null si el
     *                  rubro no tuvo OC; o ['motivo'] si el rubro esta en
     *                  SIN_REFERENCIA_OC.
     *
     * @param array $filas tablaParametros()
     * @param array $referencia referenciaOc()
     * @param array $temporadas temporadasReferencia()
     * @return array
     */
    public static function conReferencia($filas, $referencia, $temporadas) {
        foreach ($filas as $i => $f) {
            $k = self::normalizar($f['rubro']);

            if (isset(self::SIN_REFERENCIA_OC[$k])) {
                $filas[$i]['referencia_oc'] = ['motivo' => self::SIN_REFERENCIA_OC[$k]];
            } else {
                $filas[$i]['referencia_oc'] = isset($referencia[$k])
                    ? $referencia[$k]
                    : self::armarReferencia([], $temporadas);
            }
        }

        return $filas;
    }

    private static function armarReferencia($porCodigo, $temporadas) {
        $r = ['temporadas' => [], 'ext' => 0.0, 'nac' => 0.0, 'pct' => null];

        foreach ($temporadas as $t) {
            $u = isset($porCodigo[$t['codigo']]) ? $porCodigo[$t['codigo']] : ['ext' => 0.0, 'nac' => 0.0];

            $r['temporadas'][] = ['temporada' => $t['temporada'], 'codigo' => $t['codigo'],
                                  'ext' => $u['ext'], 'nac' => $u['nac'],
                                  'pct' => self::pctExterior($u['ext'], $u['nac'])];
            $r['ext'] += $u['ext'];
            $r['nac'] += $u['nac'];
        }

        $r['pct'] = self::pctExterior($r['ext'], $r['nac']);

        return $r;
    }

    private static function pctExterior($ext, $nac) {
        $total = $ext + $nac;

        return ($total > 0) ? round($ext / $total * 100.0, 1) : null;
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Asigna o cambia el % importado de un rubro.
     *
     * EL RUBRO TIENE QUE EXISTIR: venir en el presupuesto que leyo el ultimo
     * calculo, o tener ya un % cargado. Un rubro tipeado a mano con un error
     * se guardaria bien y no aplicaria a nada, y quien lo cargo creeria haber
     * movido algo.
     *
     * @param string $rubro
     * @param mixed $pct
     * @param string $usuario
     * @return array ['rubro','pct','anterior']
     */
    public function guardar($rubro, $pct, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        $errores = self::validar($rubro, $pct);

        if ($errores) {
            throw new Exception(implode(' ', $errores));
        }

        $rubro = self::normalizar($rubro);
        $pct = round(floatval(str_replace(',', '.', trim((string) $pct))), 2);

        if (!$this->datos->tieneRubroPct()) {
            throw new Exception(ComprasProyectadasDatos::AVISO_SIN_RUBRO_PCT);
        }

        $vigentes = $this->datos->pctVigentes();
        $conocido = isset($vigentes[$rubro]);

        if (!$conocido) {
            foreach ($this->datos->presupuestoPorRubro() as $r) {
                if (self::normalizar($r['rubro']) === $rubro) {
                    $conocido = true;
                    break;
                }
            }
        }

        if (!$conocido) {
            throw new Exception('El rubro ' . $rubro . ' no está en el presupuesto ni tiene un % '
                . 'cargado, así que un % ahí no cambiaría ningún importe.');
        }

        $anterior = $conocido && isset($vigentes[$rubro]) ? $vigentes[$rubro]['pct'] : null;

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception('No se pudo abrir la transacción para guardar el % importado');
        }

        try {
            /* LA BAJA Y EL ALTA VAN JUNTAS. El indice unico filtrado por
               VIGENTE prohibe dos vigentes del mismo rubro; separadas, un
               fallo de la segunda dejaria el rubro sin % -o sea, al 100 %- y
               con el anterior dado de baja. */
            $this->ejecutar($cid,
                "UPDATE " . self::TABLA . "
                 SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                 WHERE RUBRO = ? AND VIGENTE = 1",
                [$usuario, $usuario, $rubro],
                'Error al dar de baja el % anterior');

            $this->ejecutar($cid,
                "INSERT INTO " . self::TABLA . "
                     (RUBRO, PCT_IMPORTADO, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, 1, ?, ?)",
                [$rubro, $pct, $usuario, $usuario],
                'Error al guardar el % importado');

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return ['rubro' => $rubro, 'pct' => $pct, 'anterior' => $anterior];
    }

    /* ====================================================================
       LECTURA DEL HISTORIAL
       ==================================================================== */

    /**
     * Todos los % que tuvo un rubro, el vigente primero.
     *
     * @param string $rubro
     * @return array
     */
    public function historial($rubro) {
        if (!$this->datos->tieneRubroPct()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            return [];
        }

        $stmt = sqlsrv_query($cid,
            "SELECT ID, RUBRO, PCT_IMPORTADO, VIGENTE, USUARIO_ALTA, FECHA_ALTA,
                    USUARIO_BAJA, FECHA_BAJA
             FROM " . self::TABLA . "
             WHERE RUBRO = ?
             ORDER BY VIGENTE DESC, FECHA_ALTA DESC, ID DESC",
            [self::normalizar($rubro)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial del % importado'));
        }

        $out = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'id' => intval($row['ID']),
                'rubro' => (string) $row['RUBRO'],
                'pct' => floatval($row['PCT_IMPORTADO']),
                'vigente' => (intval($row['VIGENTE']) === 1),
                'usuario' => ($row['USUARIO_ALTA'] === null) ? null : (string) $row['USUARIO_ALTA'],
                'fecha_alta' => self::aFechaHora($row['FECHA_ALTA']),
                'usuario_baja' => ($row['USUARIO_BAJA'] === null) ? null : (string) $row['USUARIO_BAJA'],
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
     * 62.5 -> '62,5'; 60 -> '60'
     *
     * @param float $n
     * @return string
     */
    public static function numero($n) {
        $s = number_format(floatval($n), 2, ',', '.');

        return rtrim(rtrim($s, '0'), ',');
    }

    private function ejecutar($cid, $sql, $params, $contexto) {
        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        sqlsrv_free_stmt($stmt);
    }

    private static function aFechaHora($v) {
        if ($v === null) {
            return null;
        }

        return ($v instanceof DateTime) ? $v->format('Y-m-d H:i') : substr((string) $v, 0, 16);
    }

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
