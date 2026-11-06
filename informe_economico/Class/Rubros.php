<?php
/**
 * Rubros
 * El maestro de rubros contables (RO_T_RUBROS_CONTABLES): orden, seccion, y lo
 * que se edita desde Parametros -> Rubros: alta, nombre, categoria, baja y
 * reactivacion.
 *
 * LA SECCION DE UN RUBRO ES SU CAT_RUBRO_CONTABLE
 * -----------------------------------------------
 * Los rubros de ventas y costo (1.1 a 2) tienen CAT NULL A PROPOSITO: la
 * estructura los ubica por su codigo. Cualquier OTRO rubro presente en la
 * tabla sin fila en el maestro, o sin CAT, va a un bloque "Sin seccion" con
 * aviso que manda a Parametros. Nunca se descarta en silencio: un gasto que no
 * se ve es un resultado inflado que nadie puede explicar.
 *
 * EL MAESTRO ES COMPARTIDO
 * ------------------------
 * Es de Control de Gastos (repo administracion): sus desplegables leen el
 * maestro entero, sin filtrar ACTIVO, y el proceso del periodo copia
 * COD_RUBRO y RUBRO_CONTABLE a RO_T_INTEGRAL_TANGO_2 y de ahi al resumen.
 * CAT_RUBRO_CONTABLE la usa tambien rentabilidad_rubro (getGastosPorCategoria).
 * Por eso: no se le agregan columnas, todo cambio va con auditoria en una
 * tabla propia, y los largos se validan contra el circuito, no contra el
 * maestro (ver LARGO_CODIGO).
 */
require_once __DIR__ . '/BaseIE.php';
require_once __DIR__ . '/Periodo.php';

class Rubros {

    /**
     * Ventas y costo. Tal como estan en la tabla: CON punto final. La
     * estructura los pide por codigo y no se editan en Parametros.
     */
    const CODIGOS_FIJOS = ['1.1.', '1.2.', '1.3.', '1.4.', '1.5.', '1.6.', '1.7.', '1.8.', '2.'];

    /** Cambios de categoria (script 2). Se sigue usando tal cual */
    const TABLA_AUDITORIA = 'RO_T_IE_RUBRO_CAT_AUDITORIA';

    /** Alta, nombre, baja y reactivacion (script 5, solo Argentina) */
    const TABLA_AUDITORIA_RUBRO = 'RO_T_IE_RUBRO_AUDITORIA';
    const SCRIPT_AUDITORIA_RUBRO = 'sql/ie_rubros_auditoria.sql';

    /**
     * Largos maximos: los de la columna MAS ANGOSTA del circuito, no los del
     * maestro. El maestro admite COD_RUBRO de 10 y RUBRO_CONTABLE de 60, pero
     * el proceso de Control de Gastos los copia a RO_T_INTEGRAL_TANGO_2 (y
     * _FINAL, _CUENTAS_2, _PRORRATEOS, _AMORTIZACIONES), con COD_RUBRO de 8 y
     * RUBRO_CONTABLE de 50, y RO_T_RESUMEN_FINAL_IE tiene el nombre de 50. Un
     * codigo de 9 se grabaria bien en el maestro y romperia (o truncaria) el
     * proceso del periodo al clasificar. Hoy el mas largo, '5.2.1.5.', mide 8.
     */
    const LARGO_CODIGO = 8;
    const LARGO_NOMBRE = 50;
    const LARGO_MOTIVO = 500;

    const MSJ_NO_EDITABLE = 'Los rubros de ventas y costo (1.x y 2) no se editan: la estructura los ubica por su código.';

    /* ================================================================
       PURO
       ================================================================ */

    /**
     * Orden natural por segmentos numericos: 7.9 < 7.10 < 7.11. Como texto,
     * '7.10.' queda antes que '7.9.', que es el orden en que los devuelve el
     * ORDER BY de la base.
     */
    public static function comparar($a, $b) {
        $sa = self::segmentos($a);
        $sb = self::segmentos($b);
        $n = max(count($sa), count($sb));

        for ($i = 0; $i < $n; $i++) {
            // Un codigo que se termina antes va primero: 7 < 7.1
            if (!isset($sa[$i])) {
                return -1;
            }

            if (!isset($sb[$i])) {
                return 1;
            }

            if ($sa[$i] !== $sb[$i]) {
                return $sa[$i] < $sb[$i] ? -1 : 1;
            }
        }

        return strcmp((string) $a, (string) $b);
    }

    private static function segmentos($cod) {
        $partes = array_values(array_filter(explode('.', trim((string) $cod)), function ($s) {
            return $s !== '';
        }));

        return array_map('intval', $partes);
    }

    public static function ordenar(array $codigos) {
        $codigos = array_values(array_unique($codigos));
        usort($codigos, [self::class, 'comparar']);

        return $codigos;
    }

    public static function esFijo($cod) {
        return in_array($cod, self::CODIGOS_FIJOS, true);
    }

    /**
     * Si el codigo es de ventas o costo (empieza con 1. o 2.). Es mas amplio
     * que esFijo a proposito: '1.9.' no es un fijo, pero la estructura ubica
     * esa familia por codigo, y un rubro nuevo ahi quedaria fuera de toda
     * seccion sin que nadie lo note.
     */
    public static function esVentaOCosto($cod) {
        $s = self::segmentos($cod);

        return isset($s[0]) && ($s[0] === 1 || $s[0] === 2);
    }

    /** null si el rubro se puede renombrar o dar de baja; si no, el porque */
    public static function errorNoEditable($cod) {
        return self::esFijo((string) $cod) || self::esVentaOCosto($cod) ? self::MSJ_NO_EDITABLE : null;
    }

    /**
     * ACTIVO NULL cuenta como activo, igual que en clasificar: el maestro
     * admite NULL y Control de Gastos no filtra por esa columna, asi que un
     * NULL nunca significo "dado de baja".
     */
    public static function esActivo(array $m) {
        return !isset($m['activo']) || (int) $m['activo'] === 1;
    }

    /**
     * Arma las secciones.
     *
     * @param array $presentes Codigos que aparecen en los registros
     * @param array $maestro [cod => ['nombre' => , 'cat' => , 'activo' => ]]
     * @return array [
     *   'porSeccion' => [cat => [cod, ...]]  ordenados; con los del maestro
     *                    activos aunque no tengan datos y los inactivos solo si
     *                    tienen datos,
     *   'seccionDe'  => [cod => cat],
     *   'sinSeccion' => [cod, ...]  presentes, no fijos, sin maestro o sin CAT
     * ]
     */
    public static function clasificar(array $presentes, array $maestro) {
        $porSeccion = [];
        $seccionDe = [];
        $sinSeccion = [];
        $presentes = array_flip(array_map('strval', $presentes));

        foreach ($maestro as $cod => $m) {
            $cod = (string) $cod;
            $cat = isset($m['cat']) ? trim((string) $m['cat']) : '';

            if (self::esFijo($cod) || $cat === '') {
                continue;
            }

            if (!self::esActivo($m) && !isset($presentes[$cod])) {
                continue;
            }

            $porSeccion[$cat][] = $cod;
            $seccionDe[$cod] = $cat;
        }

        foreach (array_keys($presentes) as $cod) {
            $cod = (string) $cod;

            if (self::esFijo($cod) || isset($seccionDe[$cod])) {
                continue;
            }

            $sinSeccion[] = $cod;
        }

        foreach ($porSeccion as $cat => $cods) {
            $porSeccion[$cat] = self::ordenar($cods);
        }

        return [
            'porSeccion' => $porSeccion,
            'seccionDe' => $seccionDe,
            'sinSeccion' => self::ordenar($sinSeccion)
        ];
    }

    /* ---------- Codigo de un rubro nuevo ---------- */

    /**
     * Quita todos los espacios y agrega el punto final si falta: en la tabla
     * los codigos estan todos con punto final ('7.12.'), y un '7.12' sin el
     * seria otro codigo para la base y el mismo para quien lo lee.
     */
    public static function normalizarCodigo($cod) {
        $cod = preg_replace('/\s+/u', '', (string) $cod);

        if ($cod !== '' && substr($cod, -1) !== '.') {
            $cod .= '.';
        }

        return $cod;
    }

    /**
     * Valida el codigo de un rubro nuevo.
     *
     * Cada segmento es un numero de 1 en adelante SIN ceros a la izquierda:
     * '7.09.' y '7.9.' se ordenan en el mismo lugar y se leen igual, pero la
     * base los toma como dos rubros distintos.
     *
     * Que ya este en el RESUMEN no es error: es justamente el caso de "Dar de
     * alta" un rubro que aparece en el informe sin estar en el maestro.
     *
     * @param array $maestro [cod => ...]
     * @return array ['codigo' => normalizado, 'error' => string|null]
     */
    public static function validarCodigo($cod, array $maestro) {
        $n = self::normalizarCodigo($cod);
        $error = null;

        $enMaestro = [];
        foreach (array_keys($maestro) as $c) {
            $enMaestro[self::normalizarCodigo($c)] = true;
        }

        if ($n === '') {
            $error = 'El código es obligatorio.';
        } elseif (!preg_match('/^(?:[1-9][0-9]*\.)+$/', $n)) {
            $error = 'El código tiene que ser números separados por punto, como 7.12. (sin letras, sin puntos dobles y sin ceros a la izquierda).';
        } elseif (self::esVentaOCosto($n)) {
            $error = 'Los códigos que empiezan con 1. o 2. son de ventas y costo: la estructura los ubica por código y no se dan de alta desde acá.';
        } elseif (strlen($n) > self::LARGO_CODIGO) {
            $error = 'El código no puede tener más de ' . self::LARGO_CODIGO . ' caracteres: el proceso de Control de Gastos lo copia a tablas que no admiten más.';
        } elseif (isset($enMaestro[$n])) {
            $error = 'El código ' . $n . ' ya existe en el maestro.';
        }

        return ['codigo' => $n, 'error' => $error];
    }

    /**
     * El prefijo que comparten TODOS los codigos de una categoria: '5.1.' en
     * Gastos de Personal, '5.2.1.' en Ocupacion, '7.' en Estructura. Se usa
     * el prefijo y no el primer segmento porque Personal, Ocupacion y Otros
     * Gastos Operativos empiezan todos con 5: comparando solo el primero, un
     * 5.1.x cargado en Ocupacion pasaria sin aviso.
     *
     * @return string '' si no hay codigos o no comparten nada
     */
    public static function prefijoComun(array $codigos) {
        $pref = null;

        foreach ($codigos as $c) {
            $s = self::segmentos($c);

            if ($pref === null) {
                $pref = $s;
                continue;
            }

            $i = 0;
            while ($i < count($pref) && isset($s[$i]) && $s[$i] === $pref[$i]) {
                $i++;
            }

            $pref = array_slice($pref, 0, $i);
        }

        return $pref ? implode('.', $pref) . '.' : '';
    }

    /**
     * Aviso (no bloquea) si el codigo no sigue la numeracion de la categoria.
     * No bloquea porque hay casos legitimos (una categoria que se reorganiza)
     * y la estructura ubica al rubro por su CAT, no por su codigo.
     *
     * @return string|null
     */
    public static function avisoCoherencia($cod, $cat, array $codigosCategoria) {
        $pref = self::prefijoComun($codigosCategoria);

        if ($pref === '') {
            return null;
        }

        $p = self::segmentos($pref);

        if (array_slice(self::segmentos($cod), 0, count($p)) === $p) {
            return null;
        }

        return 'El código ' . $cod . ' no sigue la numeración de "' . $cat . '", cuyos rubros empiezan con ' . $pref
            . '. Revisá que sea la categoría correcta.';
    }

    /**
     * El codigo propuesto para un rubro nuevo de una categoria: al ultimo (en
     * orden natural, activos e inactivos) se le suma 1 en el ultimo segmento,
     * saltando los ocupados en el maestro o en el resumen (un codigo que esta
     * en el resumen ya tiene importes de otro rubro).
     *
     * No propone nada (null) si:
     *   - la categoria no tiene rubros: no hay de donde sacar la numeracion;
     *   - el ultimo tiene un solo segmento ('6.' en Bienes de Uso): sumarle 1
     *     da '7.', que es el prefijo de Gastos de Estructura;
     *   - el resultado excede LARGO_CODIGO.
     * En esos casos el campo queda vacio y lo carga el usuario.
     *
     * @param array $codigosCategoria codigos de la categoria
     * @param array $ocupados codigos del maestro y del resumen
     * @return string|null
     */
    public static function proponerCodigo(array $codigosCategoria, array $ocupados) {
        if (!$codigosCategoria) {
            return null;
        }

        $orden = self::ordenar($codigosCategoria);
        $s = self::segmentos(end($orden));

        if (count($s) < 2) {
            return null;
        }

        $ocup = [];
        foreach ($ocupados as $c) {
            $ocup[self::normalizarCodigo($c)] = true;
        }

        $ult = count($s) - 1;

        // Termina siempre: cada vuelta el codigo crece, y al pasar el largo
        // maximo se corta.
        do {
            $s[$ult]++;
            $cod = implode('.', $s) . '.';

            if (strlen($cod) > self::LARGO_CODIGO) {
                return null;
            }
        } while (isset($ocup[$cod]));

        return $cod;
    }

    /* ---------- Nombre ---------- */

    /**
     * Valida el nombre de un rubro (alta o cambio de nombre).
     *
     * Un nombre repetido NO bloquea: hoy ya hay dos 'Provision SAC' (5.1.3. y
     * 7.5.), en secciones distintas, y es legitimo. Se avisa y se pide
     * confirmar.
     *
     * La columna es VARCHAR (no NVARCHAR): un caracter fuera de Windows-1252
     * se guardaria como '?'. Se rechaza antes de grabarlo roto.
     *
     * @param array $maestro [cod => ['nombre']]
     * @param string|null $codPropio el rubro que se renombra (no se compara consigo mismo)
     * @return array ['nombre' => sin espacios en los bordes, 'error' => ?, 'aviso' => ?]
     */
    public static function validarNombre($nombre, array $maestro, $codPropio = null) {
        $n = trim((string) $nombre);
        $error = null;
        $aviso = null;

        if ($n === '') {
            $error = 'El nombre es obligatorio.';
        } elseif (mb_strlen($n, 'UTF-8') > self::LARGO_NOMBRE) {
            $error = 'El nombre no puede tener más de ' . self::LARGO_NOMBRE . ' caracteres (tiene ' . mb_strlen($n, 'UTF-8')
                . '): el proceso de Control de Gastos lo copia a tablas que no admiten más.';
        } elseif (@mb_convert_encoding(mb_convert_encoding($n, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') !== $n) {
            $error = 'El nombre tiene caracteres que la base no puede guardar (emojis o símbolos especiales).';
        } else {
            $buscado = mb_strtolower($n, 'UTF-8');

            foreach ($maestro as $cod => $m) {
                if ($codPropio !== null && (string) $cod === (string) $codPropio) {
                    continue;
                }

                if (mb_strtolower(trim((string) ($m['nombre'] ?? '')), 'UTF-8') === $buscado) {
                    $aviso = 'Ya hay otro rubro con ese nombre: ' . $cod . ' ' . trim((string) $m['nombre']) . '.';
                    break;
                }
            }
        }

        return ['nombre' => $n, 'error' => $error, 'aviso' => $aviso];
    }

    /* ---------- Reglas completas de cada operacion ---------- */

    /**
     * Todas las reglas del alta, sin base: lo que el servidor rechaza
     * (errores) y lo que pide confirmar (avisos).
     *
     * @param array $categorias las existentes (Rubros::categorias)
     * @return array ['cod', 'nombre', 'cat', 'errores' => [], 'avisos' => []]
     */
    public static function revisarAlta($cod, $nombre, $cat, array $maestro, array $categorias) {
        $errores = [];
        $avisos = [];
        $cat = trim((string) $cat);

        if ($cat === '') {
            $errores[] = 'Elegí una categoría.';
        } elseif (!in_array($cat, $categorias, true)) {
            $errores[] = 'La categoría tiene que ser una de las existentes.';
        }

        $c = self::validarCodigo($cod, $maestro);
        if ($c['error'] !== null) {
            $errores[] = $c['error'];
        }

        $n = self::validarNombre($nombre, $maestro);
        if ($n['error'] !== null) {
            $errores[] = $n['error'];
        }

        if (!$errores) {
            $deLaCat = [];
            foreach ($maestro as $k => $m) {
                if (isset($m['cat']) && trim((string) $m['cat']) === $cat) {
                    $deLaCat[] = (string) $k;
                }
            }

            $coh = self::avisoCoherencia($c['codigo'], $cat, $deLaCat);
            if ($coh !== null) {
                $avisos[] = $coh;
            }

            if ($n['aviso'] !== null) {
                $avisos[] = $n['aviso'];
            }
        }

        return ['cod' => $c['codigo'], 'nombre' => $n['nombre'], 'cat' => $cat, 'errores' => $errores, 'avisos' => $avisos];
    }

    /** Reglas del cambio de nombre, sin base. Mismo formato que revisarAlta */
    public static function revisarNombre($cod, $nombre, array $maestro) {
        $errores = [];
        $avisos = [];
        $n = self::validarNombre($nombre, $maestro, $cod);

        if (($e = self::errorNoEditable($cod)) !== null) {
            $errores[] = $e;
        } elseif (!isset($maestro[$cod])) {
            $errores[] = 'El rubro ' . $cod . ' no está en el maestro.';
        } elseif ($n['error'] !== null) {
            $errores[] = $n['error'];
        } elseif ($n['aviso'] !== null) {
            $avisos[] = $n['aviso'];
        }

        return ['cod' => (string) $cod, 'nombre' => $n['nombre'], 'errores' => $errores, 'avisos' => $avisos];
    }

    /** El motivo de una baja o reactivacion: obligatorio, como en la base (CHECK) */
    public static function validarMotivo($motivo) {
        $m = trim((string) $motivo);

        if ($m === '') {
            return ['motivo' => $m, 'error' => 'El motivo es obligatorio.'];
        }

        if (mb_strlen($m, 'UTF-8') > self::LARGO_MOTIVO) {
            return ['motivo' => $m, 'error' => 'El motivo no puede tener más de ' . self::LARGO_MOTIVO . ' caracteres.'];
        }

        return ['motivo' => $m, 'error' => null];
    }

    /* ---------- Lo que muestra la pantalla ---------- */

    /**
     * El movimiento mas reciente de cada rubro entre varias auditorias (la de
     * categoria y la de alta/nombre/baja). Las fechas vienen 'Y-m-d H:i:s',
     * que ordenan bien como texto.
     *
     * @param array ...$listas cada una [cod => ['usuario', 'fecha', ...]]
     * @return array [cod => el de fecha mayor]
     */
    public static function ultimoMovimiento(array ...$listas) {
        $out = [];

        foreach ($listas as $l) {
            foreach ($l as $cod => $mov) {
                if (!isset($out[$cod]) || strcmp((string) $mov['fecha'], (string) $out[$cod]['fecha']) > 0) {
                    $out[$cod] = $mov;
                }
            }
        }

        return $out;
    }

    /**
     * El periodo mas reciente, en orden CRONOLOGICO. MAX(PERIODO) en la base
     * compara texto ('9-2025' > '12-2025') y daria cualquiera.
     */
    public static function ultimoPeriodo(array $periodos) {
        $ult = null;

        foreach ($periodos as $p) {
            if (!Periodo::valido((string) $p)) {
                continue;
            }

            if ($ult === null || Periodo::comparar($p, $ult) > 0) {
                $ult = $p;
            }
        }

        return $ult;
    }

    /**
     * El nombre sugerido para dar de alta un rubro que esta en el resumen y
     * no en el maestro: el RUBRO_CONTABLE no vacio de su periodo mas reciente.
     *
     * @param array $filas [['periodo' => , 'nombre' => ], ...]
     * @return string|null
     */
    public static function nombreMasReciente(array $filas) {
        $mejor = null;

        foreach ($filas as $f) {
            $n = trim((string) ($f['nombre'] ?? ''));

            if ($n === '' || !Periodo::valido((string) $f['periodo'])) {
                continue;
            }

            if ($mejor === null || Periodo::comparar($f['periodo'], $mejor['periodo']) > 0) {
                $mejor = ['periodo' => $f['periodo'], 'nombre' => $n];
            }
        }

        return $mejor ? $mejor['nombre'] : null;
    }

    /* ================================================================
       BASE
       ================================================================ */

    /** @return array [cod => ['nombre', 'cat', 'activo']] */
    public static function leerMaestro($cid) {
        $out = [];

        foreach (BaseIE::filas($cid, 'SELECT COD_RUBRO, RUBRO_CONTABLE, CAT_RUBRO_CONTABLE, ACTIVO FROM RO_T_RUBROS_CONTABLES') as $r) {
            $out[(string) $r['COD_RUBRO']] = [
                'nombre' => trim((string) $r['RUBRO_CONTABLE']),
                'cat' => $r['CAT_RUBRO_CONTABLE'] !== null ? trim((string) $r['CAT_RUBRO_CONTABLE']) : null,
                'activo' => $r['ACTIVO']
            ];
        }

        return $out;
    }

    /** Las categorias que ya existen: son las unicas que ofrece el desplegable */
    public static function categorias($cid) {
        $out = [];

        foreach (BaseIE::filas($cid, "SELECT DISTINCT CAT_RUBRO_CONTABLE AS CAT FROM RO_T_RUBROS_CONTABLES
                                       WHERE CAT_RUBRO_CONTABLE IS NOT NULL AND LTRIM(RTRIM(CAT_RUBRO_CONTABLE)) <> ''") as $r) {
            $out[] = trim($r['CAT']);
        }

        sort($out);

        return $out;
    }

    /** Los codigos que aparecen en el resumen */
    public static function codigosEnResumen($cid) {
        return array_map(function ($r) {
            return (string) $r['COD_RUBRO'];
        }, BaseIE::filas($cid, 'SELECT DISTINCT COD_RUBRO FROM RO_T_RESUMEN_FINAL_IE WHERE COD_RUBRO IS NOT NULL'));
    }

    public static function hayAuditoriaRubros($cid) {
        return BaseIE::existe($cid, self::TABLA_AUDITORIA_RUBRO);
    }

    /**
     * Antes de escribir en el maestro: nunca en Uruguay, y nunca sin la
     * tabla de auditoria. ieConexion() ya corta en 'uy' antes de llegar
     * aca; esto es la segunda barrera, por si algun dia se llama desde otro
     * lado. Y en TASKY_SA el script 5 no se corre, asi que la tabla tampoco
     * esta: es la tercera.
     */
    private static function exigirEscritura($cid) {
        if (BaseIE::esUruguay()) {
            throw new RuntimeException('El Informe Económico todavía no está disponible para Uruguay.');
        }

        if (!self::hayAuditoriaRubros($cid)) {
            throw new RuntimeException('Falta correr ' . self::SCRIPT_AUDITORIA_RUBRO . ': sin la tabla de auditoría no se puede dar de alta, renombrar ni dar de baja un rubro.');
        }
    }

    private static function auditar($cid, $cod, $tipo, $anterior, $nuevo, $motivo, $usuario) {
        BaseIE::ejecutar($cid, 'INSERT INTO ' . self::TABLA_AUDITORIA_RUBRO . '
                                (COD_RUBRO, TIPO, VALOR_ANTERIOR, VALOR_NUEVO, MOTIVO, USUARIO, FECHA)
                                VALUES (?, ?, ?, ?, ?, ?, GETDATE())',
            [$cod, $tipo, $anterior, $nuevo, ($motivo === null || $motivo === '') ? null : $motivo, $usuario]);
    }

    /**
     * Lo que necesita el modal de alta al elegir una categoria: el codigo
     * propuesto y los rubros que ya tiene (activos e inactivos).
     */
    public static function proponer($cid, $cat) {
        $cat = trim((string) $cat);

        if (!in_array($cat, self::categorias($cid), true)) {
            throw new InvalidArgumentException('La categoría tiene que ser una de las existentes.');
        }

        $maestro = self::leerMaestro($cid);
        $deLaCat = [];

        foreach ($maestro as $cod => $m) {
            if (isset($m['cat']) && $m['cat'] === $cat) {
                $deLaCat[] = (string) $cod;
            }
        }

        $rubros = [];
        foreach (self::ordenar($deLaCat) as $cod) {
            $rubros[] = ['cod' => $cod, 'nombre' => $maestro[$cod]['nombre'], 'activo' => self::esActivo($maestro[$cod])];
        }

        return [
            'codigo' => self::proponerCodigo($deLaCat, array_merge(array_keys($maestro), self::codigosEnResumen($cid))),
            'prefijo' => self::prefijoComun($deLaCat),
            'rubros' => $rubros
        ];
    }

    /**
     * Da de alta un rubro: INSERT en el maestro con ACTIVO = 1 y su
     * auditoria, en una transaccion.
     *
     * Si hay avisos (codigo fuera de la numeracion, nombre repetido) y el
     * usuario todavia no confirmo, NO escribe: devuelve los avisos para que
     * la pantalla pregunte. Las reglas viven aca y no en el JS, para que
     * haya una sola version.
     *
     * El maestro no tiene UNIQUE sobre COD_RUBRO (su PK es un ID identity),
     * asi que la base no frena un repetido: lo frena el SELECT con UPDLOCK +
     * HOLDLOCK, que bloquea el RANGO (tambien la ausencia del codigo) hasta
     * el commit. Dos altas simultaneas del mismo codigo: la segunda espera y
     * despues lo ve.
     *
     * @return array ['ok' => true, 'cod'] o ['ok' => false, 'confirmar' => true, 'avisos']
     */
    public static function alta($cid, $cod, $nombre, $cat, $confirmado, $usuario) {
        self::exigirEscritura($cid);

        $r = self::revisarAlta($cod, $nombre, $cat, self::leerMaestro($cid), self::categorias($cid));

        if ($r['errores']) {
            throw new InvalidArgumentException(implode(' ', $r['errores']));
        }

        if ($r['avisos'] && !$confirmado) {
            return ['ok' => false, 'confirmar' => true, 'avisos' => $r['avisos']];
        }

        if (!sqlsrv_begin_transaction($cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $ya = BaseIE::filas($cid, 'SELECT COUNT(*) AS N FROM RO_T_RUBROS_CONTABLES WITH (UPDLOCK, HOLDLOCK) WHERE COD_RUBRO = ?', [$r['cod']]);

            if ((int) $ya[0]['N'] > 0) {
                throw new InvalidArgumentException('El código ' . $r['cod'] . ' ya existe en el maestro (lo dio de alta alguien más mientras tanto). No se guardó nada.');
            }

            BaseIE::ejecutar($cid, 'INSERT INTO RO_T_RUBROS_CONTABLES (COD_RUBRO, RUBRO_CONTABLE, CAT_RUBRO_CONTABLE, ACTIVO) VALUES (?, ?, ?, 1)',
                [$r['cod'], $r['nombre'], $r['cat']]);

            self::auditar($cid, $r['cod'], 'ALTA', null,
                json_encode(['cod' => $r['cod'], 'nombre' => $r['nombre'], 'cat' => $r['cat']], JSON_UNESCAPED_UNICODE), null, $usuario);

            sqlsrv_commit($cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($cid);
            throw $t;
        }

        return ['ok' => true, 'cod' => $r['cod']];
    }

    /**
     * Cambia el nombre de un rubro, con auditoria, en una transaccion. Igual
     * que el alta: con avisos sin confirmar no escribe.
     *
     * Los gastos ya clasificados conservan el nombre anterior: Control de
     * Gastos copia RUBRO_CONTABLE a RO_T_INTEGRAL_TANGO_2 al clasificar. El
     * informe toma el nombre del maestro, asi que ahi cambia en todos los
     * meses.
     *
     * @return array ['ok' => true, 'cambio' => bool] o ['ok' => false, 'confirmar' => true, 'avisos']
     */
    public static function guardarNombre($cid, $cod, $nombre, $confirmado, $usuario) {
        self::exigirEscritura($cid);

        $r = self::revisarNombre($cod, $nombre, self::leerMaestro($cid));

        if ($r['errores']) {
            throw new InvalidArgumentException(implode(' ', $r['errores']));
        }

        if ($r['avisos'] && !$confirmado) {
            return ['ok' => false, 'confirmar' => true, 'avisos' => $r['avisos']];
        }

        if (!sqlsrv_begin_transaction($cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $prev = BaseIE::filas($cid, 'SELECT RUBRO_CONTABLE FROM RO_T_RUBROS_CONTABLES WITH (UPDLOCK) WHERE COD_RUBRO = ?', [$r['cod']]);

            if (count($prev) !== 1) {
                throw new RuntimeException('El rubro ' . $r['cod'] . ' no está en el maestro, o está repetido.');
            }

            $anterior = $prev[0]['RUBRO_CONTABLE'];

            if ($anterior !== null && trim($anterior) === $r['nombre']) {
                sqlsrv_rollback($cid);

                return ['ok' => true, 'cambio' => false];
            }

            BaseIE::ejecutar($cid, 'UPDATE RO_T_RUBROS_CONTABLES SET RUBRO_CONTABLE = ? WHERE COD_RUBRO = ?', [$r['nombre'], $r['cod']]);
            self::auditar($cid, $r['cod'], 'NOMBRE', $anterior, $r['nombre'], null, $usuario);

            sqlsrv_commit($cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($cid);
            throw $t;
        }

        return ['ok' => true, 'cambio' => true];
    }

    /**
     * Baja (ACTIVO = 0) o reactivacion (ACTIVO = 1), con motivo obligatorio y
     * auditoria. No borra nada.
     *
     * No se bloquea por tener cuentas relacionadas o datos: en el informe, un
     * inactivo con datos en el rango se sigue mostrando y sumando
     * (clasificar). Y Control de Gastos no filtra inactivos: la pantalla lo
     * avisa antes de confirmar.
     */
    public static function cambiarEstado($cid, $cod, $activo, $motivo, $usuario) {
        self::exigirEscritura($cid);

        if (($e = self::errorNoEditable($cod)) !== null) {
            throw new InvalidArgumentException($e);
        }

        $m = self::validarMotivo($motivo);

        if ($m['error'] !== null) {
            throw new InvalidArgumentException($m['error']);
        }

        if (!sqlsrv_begin_transaction($cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $prev = BaseIE::filas($cid, 'SELECT ACTIVO FROM RO_T_RUBROS_CONTABLES WITH (UPDLOCK) WHERE COD_RUBRO = ?', [$cod]);

            if (count($prev) !== 1) {
                throw new RuntimeException('El rubro ' . $cod . ' no está en el maestro, o está repetido.');
            }

            if (self::esActivo(['activo' => $prev[0]['ACTIVO']]) === (bool) $activo) {
                throw new InvalidArgumentException($activo ? 'El rubro ' . $cod . ' ya está activo.' : 'El rubro ' . $cod . ' ya está dado de baja.');
            }

            BaseIE::ejecutar($cid, 'UPDATE RO_T_RUBROS_CONTABLES SET ACTIVO = ? WHERE COD_RUBRO = ?', [$activo ? 1 : 0, $cod]);
            self::auditar($cid, $cod, $activo ? 'REACTIVACION' : 'BAJA', $activo ? '0' : '1', $activo ? '1' : '0', $m['motivo'], $usuario);

            sqlsrv_commit($cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($cid);
            throw $t;
        }
    }

    /** Lo que muestra el modal de baja: cuentas relacionadas y registros en el resumen */
    public static function infoBaja($cid, $cod) {
        $rel = BaseIE::filas($cid, 'SELECT COUNT(*) AS N FROM RO_T_RELACION_CUENTA_RUBRO_CONTABLE WHERE COD_RUBRO = ?', [$cod]);
        $per = BaseIE::filas($cid, 'SELECT PERIODO, COUNT(*) AS N FROM RO_T_RESUMEN_FINAL_IE WHERE COD_RUBRO = ? GROUP BY PERIODO', [$cod]);

        return [
            'relaciones' => (int) $rel[0]['N'],
            'registros' => array_sum(array_map(function ($r) { return (int) $r['N']; }, $per)),
            'ultimoPeriodo' => self::ultimoPeriodo(array_column($per, 'PERIODO'))
        ];
    }

    /**
     * El ultimo movimiento de cada rubro en las dos auditorias (categoria, y
     * alta/nombre/baja si ya se corrio el script 5).
     *
     * @return array [cod => ['usuario', 'fecha', 'tipo']]
     */
    public static function ultimosCambios($cid) {
        $listas = [];
        $fuentes = [
            self::TABLA_AUDITORIA => "'CATEGORIA'",
            self::TABLA_AUDITORIA_RUBRO => 'TIPO'
        ];

        foreach ($fuentes as $tabla => $tipo) {
            if (!BaseIE::existe($cid, $tabla)) {
                continue;
            }

            $l = [];
            foreach (BaseIE::filas($cid, 'SELECT COD_RUBRO, USUARIO, FECHA, ' . $tipo . ' AS TIPO FROM (
                                              SELECT *, ROW_NUMBER() OVER (PARTITION BY COD_RUBRO ORDER BY FECHA DESC, ID DESC) AS RN FROM ' . $tabla . '
                                          ) X WHERE RN = 1') as $r) {
                $l[(string) $r['COD_RUBRO']] = ['usuario' => $r['USUARIO'], 'fecha' => $r['FECHA'], 'tipo' => $r['TIPO']];
            }

            $listas[] = $l;
        }

        return self::ultimoMovimiento(...$listas);
    }

    /**
     * El nombre sugerido para los rubros del resumen que no estan en el
     * maestro (ver nombreMasReciente).
     *
     * @return array [cod => nombre|null]
     */
    public static function nombresSugeridos($cid, array $cods) {
        if (!$cods) {
            return [];
        }

        $porCod = [];
        foreach (BaseIE::filas($cid, 'SELECT COD_RUBRO, PERIODO, RUBRO_CONTABLE FROM RO_T_RESUMEN_FINAL_IE
                                      WHERE COD_RUBRO IN (' . BaseIE::marcas(count($cods)) . ')
                                      GROUP BY COD_RUBRO, PERIODO, RUBRO_CONTABLE', array_values($cods)) as $r) {
            $porCod[(string) $r['COD_RUBRO']][] = ['periodo' => $r['PERIODO'], 'nombre' => $r['RUBRO_CONTABLE']];
        }

        $out = [];
        foreach ($cods as $c) {
            $out[$c] = self::nombreMasReciente($porCod[$c] ?? []);
        }

        return $out;
    }

    /**
     * Cambia la CAT de un rubro, con auditoria en una tabla propia (no se le
     * agregan columnas al maestro, que es de Control de Gastos).
     *
     * Van en una transaccion: un cambio sin su auditoria es justo lo que no
     * puede pasar en una columna que mueve dos informes.
     */
    public static function guardarCategoria($cid, $cod, $cat, $usuario) {
        if (self::esFijo($cod)) {
            throw new InvalidArgumentException(self::MSJ_NO_EDITABLE);
        }

        if (!BaseIE::existe($cid, self::TABLA_AUDITORIA)) {
            throw new RuntimeException('Falta correr sql/ie_resumen_edicion.sql: sin la tabla de auditoría no se puede cambiar la categoría.');
        }

        $cat = trim((string) $cat);

        if (!in_array($cat, self::categorias($cid), true)) {
            throw new InvalidArgumentException('La categoría tiene que ser una de las existentes.');
        }

        if (!sqlsrv_begin_transaction($cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $prev = BaseIE::filas($cid, 'SELECT CAT_RUBRO_CONTABLE FROM RO_T_RUBROS_CONTABLES WITH (UPDLOCK) WHERE COD_RUBRO = ?', [$cod]);

            if (count($prev) !== 1) {
                throw new RuntimeException('El rubro ' . $cod . ' no está en el maestro.');
            }

            $anterior = $prev[0]['CAT_RUBRO_CONTABLE'];

            if ($anterior !== null && trim($anterior) === $cat) {
                sqlsrv_rollback($cid);

                return false;
            }

            BaseIE::ejecutar($cid, 'UPDATE RO_T_RUBROS_CONTABLES SET CAT_RUBRO_CONTABLE = ? WHERE COD_RUBRO = ?', [$cat, $cod]);
            BaseIE::ejecutar($cid, 'INSERT INTO ' . self::TABLA_AUDITORIA . ' (COD_RUBRO, CAT_ANTERIOR, CAT_NUEVA, USUARIO, FECHA)
                                    VALUES (?, ?, ?, ?, GETDATE())', [$cod, $anterior, $cat, $usuario]);

            sqlsrv_commit($cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($cid);
            throw $t;
        }

        return true;
    }
}
