<?php

require_once __DIR__ . '/Planilla.php';

/**
 * ProveedoresCategorias
 * El maestro de proveedores: que es cada uno y como se le paga.
 *
 * QUE RESUELVE
 * ------------
 * Tango sabe cuanto se le debe a cada proveedor y cuando vence. No sabe si ese
 * proveedor es un taller, un alquiler, un impuesto o un socio. Eso vive en la
 * hoja "Maestro proveedores" del Excel Cronograma de Pagos, que mantiene
 * administracion a mano, y esta clase es la copia reimportable de esa hoja.
 *
 * POR QUE UNA COPIA Y NO CPA01.COD_RUBRO
 * --------------------------------------
 * CPA01 tiene la columna y esta vacia en los 4.893 proveedores; CAMPOS_ADICIONALES
 * tambien. Empezar a cargarla desde Tango obligaria a administracion a mantener
 * DOS maestros en paralelo, y dos maestros en paralelo terminan discrepando. La
 * planilla sigue siendo la fuente; esto es una copia con su fecha de importacion
 * a la vista, para que se sepa cuan vieja es.
 *
 * PARA QUE SIRVE, CONCRETAMENTE
 * -----------------------------
 * Para que el tablero pueda mostrar alquileres, impuestos y mercaderia en filas
 * distintas en lugar de todo en una. Hoy la fila del tablero es una sola; cuando
 * el maestro este cargado, partirla es CONFIGURACION y no un refactor, porque el
 * proveedor ya entrega cada comprobante con su rubro resuelto y expone una serie
 * por rubro ademas del total.
 *
 * Y para dos cosas mas: la forma de pago habitual -que sirve de valor por
 * defecto al importar pagos- y el plazo, que es el ultimo escalon de la
 * jerarquia de resolucion de fecha.
 *
 * EL RUBRO "Excluidos" SACA AL PROVEEDOR DEL TABLERO
 * --------------------------------------------------
 * Son los socios y los movimientos que no son deuda comercial. No se filtran en
 * la consulta: se clasifican, y la fila del tablero que los agrupa se inhabilita
 * desde Parametros. Asi "sacarlos" es un bit y no un cambio de codigo, y siguen
 * siendo visibles en la pestana de detalle, que es donde alguien puede notar que
 * uno esta mal clasificado.
 *
 * HAY UNA SEGUNDA LISTA DE EXCLUSION Y NO MANDA
 * ----------------------------------------------
 * RO_V_PROVEEDORES_EGRE_DIRECTORES tiene 8 proveedores que son egresos de
 * directores. MANDA EL MAESTRO, por dos motivos: es el que administracion
 * mantiene todos los dias, y tiene 225 proveedores contra 8. La vista queda como
 * CONTROL: si alguien figura en ella y NO esta marcado Excluidos en el maestro,
 * se avisa. Una lista manda y la otra audita; dos listas que deciden se
 * contradicen y nadie se entera.
 *
 * Hoy la diferencia no es teorica: 2 de esos 8 tienen deuda pendiente.
 *
 * LA PLANILLA VIENE SUCIA, Y ESO NO SE ARREGLA EN SILENCIO
 * --------------------------------------------------------
 * Tiene un codigo repetido, un 'echeq' en minuscula y un 'ECOMMERC' por
 * 'ECOMMERCE'. La importacion los MUESTRA en la previsualizacion en lugar de
 * corregirlos: lo que hay que arreglar es la planilla, y si el importador la
 * corrige sola, nadie se entera nunca de que esta mal.
 *
 * Por eso cada valor normalizado se guarda con su original al lado.
 */
class ProveedoresCategorias {

    /** Tabla del maestro, en la base central */
    const TABLA = 'RO_T_CASHFLOW_PROV_LOCALES_CATEG';

    /** Vista de Tango con los egresos de directores. Se usa SOLO como control. */
    const VISTA_DIRECTORES = 'RO_V_PROVEEDORES_EGRE_DIRECTORES';

    /**
     * El rubro economico que saca al proveedor del tablero.
     *
     * Se compara normalizado (sin acentos ni mayusculas), asi que 'Excluidos',
     * 'EXCLUIDOS' y 'excluidos' son el mismo.
     */
    const RUBRO_EXCLUIDOS = 'Excluidos';

    /**
     * Largo maximo del codigo de proveedor, EN CARACTERES.
     *
     * CPA01.COD_PROVEE es VARCHAR(6) con collation Latin1_General_BIN, donde una
     * eñe ocupa UN byte. En UTF-8 ocupa DOS, asi que el largo hay que contarlo
     * en caracteres -Planilla::largo()- y no con strlen, que cuenta bytes y
     * rechazaba codigos validos como OGNUÑE.
     */
    const LARGO_CODIGO = 6;

    /**
     * Las formas de pago declaradas.
     *
     * La planilla trae 9 valores y viene sucia. Se normaliza contra esta lista
     * SIN PERDER EL ORIGINAL: un 'echeq' en minuscula matchea contra 'ECHEQ'; un
     * valor que no matchea se guarda igual, con el normalizado en null, y la
     * previsualizacion lo muestra.
     *
     * Agregar una forma es agregar una entrada aca.
     */
    const FORMAS_PAGO = [
        'TRANSFERENCIA', 'CHEQUE', 'ECHEQ', 'EFECTIVO', 'DEBITO',
        'TARJETA', 'RETENCION', 'COMPENSACION', 'OTRO'
    ];

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de la tabla */
    private $tabla = null;

    /** @var array|null Cache del maestro vigente, indexado por COD_PROVEE */
    private $mapa = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_prov_locales.sql */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $cid = $this->conectar();
        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla del maestro'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Avisos de configuracion pendiente, para mostrar en pantalla.
     *
     * @return array
     */
    public function getAvisos() {
        if (!$this->tablaCreada()) {
            return ['Todavía no existe la tabla del maestro de proveedores. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central. '
                . 'Mientras tanto, los comprobantes se muestran sin clasificar.'];
        }

        if (empty($this->mapa())) {
            return ['El maestro de proveedores está vacío: importá la hoja '
                . '"Maestro proveedores" del Excel Cronograma de Pagos. Mientras tanto, '
                . 'todos los comprobantes se muestran sin clasificar y ninguno queda excluido.'];
        }

        return [];
    }

    /* ====================================================================
       LECTURA Y RESOLUCION
       ==================================================================== */

    /**
     * El maestro vigente, indexado por codigo de proveedor.
     *
     * Se cachea: el tablero resuelve la categoria de cada uno de los cientos de
     * vencimientos y no tiene sentido ir a la base por cada uno.
     *
     * @return array Mapa COD_PROVEE => fila del maestro
     */
    public function mapa() {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $this->mapa = [];

        if (!$this->tablaCreada()) {
            return $this->mapa;
        }

        $cid = $this->conectar();

        $sql = "SELECT COD_PROVEE, NOMBRE, RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS,
                       FORMA_PAGO, FORMA_PAGO_ORIG, PLAZO_PAGO, PLAZO_DIAS,
                       CRITERIO_DISTRIB, FECHA_IMPORTACION
                FROM dbo." . self::TABLA . "
                WHERE VIGENTE = 1";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el maestro de proveedores'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = Planilla::codigo($row['COD_PROVEE']);

            $this->mapa[$cod] = [
                'COD_PROVEE' => $cod,
                'NOMBRE' => $row['NOMBRE'],
                'RUBRO_ECONOMICO' => $row['RUBRO_ECONOMICO'],
                'RUBRO' => $row['RUBRO'],
                'CENTRO_COSTOS' => $row['CENTRO_COSTOS'],
                'FORMA_PAGO' => $row['FORMA_PAGO'],
                'FORMA_PAGO_ORIG' => $row['FORMA_PAGO_ORIG'],
                'PLAZO_PAGO' => $row['PLAZO_PAGO'],
                'PLAZO_DIAS' => ($row['PLAZO_DIAS'] === null) ? null : intval($row['PLAZO_DIAS']),
                'CRITERIO_DISTRIB' => $row['CRITERIO_DISTRIB'],
                'FECHA_IMPORTACION' => $this->fechaHora($row['FECHA_IMPORTACION'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->mapa;
    }

    /**
     * La categoria de un proveedor, SIEMPRE con la misma forma.
     *
     * Un proveedor que no esta en el maestro NO devuelve null ni revienta:
     * devuelve la misma estructura con 'en_maestro' en false y el rubro en
     * SIN_RUBRO. Asi quien consume no tiene que preguntar si existe, y un
     * proveedor sin clasificar se ve en el tablero como "Sin clasificar" en
     * lugar de desaparecer.
     *
     * ES EL UNICO LUGAR donde se decide que rubro le toca a un comprobante. El
     * proveedor del tablero, la grilla y los avisos lo llaman a este; ninguno
     * mira el maestro por su cuenta.
     *
     * @param string $codProvee
     * @return array
     */
    public function categoria($codProvee) {
        $cod = Planilla::codigo($codProvee);
        $mapa = $this->mapa();

        if (!isset($mapa[$cod])) {
            return [
                'en_maestro' => false,
                'rubro_economico' => null,
                'rubro' => null,
                'centro_costos' => null,
                'forma_pago' => null,
                'plazo_dias' => null,
                'excluido' => false,
                'serie' => self::SERIE_SIN_RUBRO
            ];
        }

        $m = $mapa[$cod];

        return [
            'en_maestro' => true,
            'rubro_economico' => $m['RUBRO_ECONOMICO'],
            'rubro' => $m['RUBRO'],
            'centro_costos' => $m['CENTRO_COSTOS'],
            'forma_pago' => $m['FORMA_PAGO'],
            'plazo_dias' => $m['PLAZO_DIAS'],
            'excluido' => self::esExcluido($m['RUBRO_ECONOMICO']),
            'serie' => self::serieDeRubro($m['RUBRO_ECONOMICO'])
        ];
    }

    /** Codigo de serie de los comprobantes cuyo proveedor no esta en el maestro */
    const SERIE_SIN_RUBRO = 'SIN_RUBRO';

    /**
     * Si un rubro economico saca al proveedor del tablero.
     *
     * Se compara normalizado: 'Excluidos', 'EXCLUIDOS' y 'excluidos' son el
     * mismo rubro. La planilla la escriben a mano.
     *
     * @param string|null $rubroEconomico
     * @return bool
     */
    public static function esExcluido($rubroEconomico) {
        if ($rubroEconomico === null || trim((string) $rubroEconomico) === '') {
            return false;
        }

        return Planilla::normalizarTitulo($rubroEconomico)
            === Planilla::normalizarTitulo(self::RUBRO_EXCLUIDOS);
    }

    /**
     * Convierte un rubro economico en un codigo de serie del tablero.
     *
     * El rubro lo escribe administracion en una planilla, asi que puede tener
     * espacios, acentos y barras. El codigo de serie es una clave que viaja al
     * registro de proveedores y a la configuracion de filas, y esa tiene que ser
     * estable y segura.
     *
     * Un rubro vacio da SIN_RUBRO, igual que un proveedor ausente del maestro:
     * las dos cosas significan "no se sabe donde va".
     *
     * @param string|null $rubroEconomico
     * @return string
     */
    public static function serieDeRubro($rubroEconomico) {
        $slug = Planilla::normalizarTitulo($rubroEconomico);

        if ($slug === '') {
            return self::SERIE_SIN_RUBRO;
        }

        // El codigo de serie se usa como clave del registro y de CONF_FILA, que
        // acota a 30 caracteres.
        return 'RUBRO_' . substr($slug, 0, 24);
    }

    /* ====================================================================
       HELPERS PUROS
       Son las reglas que conviene poder probar sin base ni archivos.
       ==================================================================== */

    /**
     * Interpreta el PLAZO DE PAGO de la planilla en dias.
     *
     * LOS VALORES NO SON NUMEROS. En la planilla son CONTADO, 7 DIAS, 30 DIAS,
     * 15 DIAS y DEBITO, y esta VACIO en 765 de 1.223 filas. Guardarlo como INT
     * obligaria a inventar un numero para CONTADO y para DEBITO.
     *
     *   CONTADO  -> 0    se paga el dia de la factura
     *   'N DIAS' -> N
     *   DEBITO   -> null se debita solo; la fecha no la decide un plazo
     *   vacio    -> null
     *
     * DEVOLVER null NO ES LO MISMO QUE DEVOLVER 0. Cero es "se paga hoy" y null
     * es "este plazo no dice cuando": el primero se usa para calcular una fecha
     * y el segundo hace caer la jerarquia al escalon siguiente.
     *
     * @param string|null $plazo
     * @return int|null Dias, o null si el plazo no permite calcular una fecha
     */
    public static function plazoEnDias($plazo) {
        $p = trim((string) $plazo);

        if ($p === '') {
            return null;
        }

        $norm = Planilla::normalizarTitulo($p);

        if ($norm === 'CONTADO' || $norm === 'CONTADOEFECTIVO') {
            return 0;
        }

        // DEBITO, DEBITOAUTOMATICO: se debita solo, no hay plazo que aplicar.
        if (strpos($norm, 'DEBITO') === 0) {
            return null;
        }

        // '30 DIAS', '30DIAS', '30 D', o un numero pelado.
        if (preg_match('/^(\d{1,3})/', $norm, $m)) {
            return intval($m[1]);
        }

        return null;
    }

    /**
     * Normaliza una forma de pago contra self::FORMAS_PAGO.
     *
     * Devuelve las dos cosas -normalizado y original- porque las dos hacen
     * falta: con el normalizado se agrupa y se decide, y el original es lo que
     * hay que mostrar cuando no matchea. Ver Planilla::normalizarContra().
     *
     * @param string|null $forma
     * @return array ['normalizado' => string|null, 'original' => string]
     */
    public static function normalizarFormaPago($forma) {
        return Planilla::normalizarContra($forma, self::FORMAS_PAGO);
    }

    /* ====================================================================
       CONTROLES
       Lo que evita que el maestro se desactualice sin que nadie se entere.
       ==================================================================== */

    /**
     * Los proveedores que tienen deuda pendiente y NO estan en el maestro.
     *
     * ES EL CONTROL MAS IMPORTANTE DE ESTE MODULO. Un maestro que se carga una
     * vez y no se vuelve a mirar se desactualiza sin aviso: aparecen proveedores
     * nuevos en Tango, nadie los agrega a la planilla, y su deuda queda sin
     * clasificar -o peor, se la lee como si estuviera clasificada-.
     *
     * Se calcula sobre los pendientes que le pasen, no consultando de nuevo: el
     * llamador ya los tiene y volver a la base seria la misma consulta dos veces.
     *
     * @param array $pendientes Filas con 'COD_PROVEE' y 'RAZON_SOC'
     * @return array Filas ['COD_PROVEE', 'RAZON_SOC', 'VENCIMIENTOS', 'IMPORTE']
     */
    public function faltantesEnMaestro($pendientes) {
        $mapa = $this->mapa();
        $faltan = [];

        foreach ($pendientes as $p) {
            $cod = Planilla::codigo($p['COD_PROVEE']);

            if (isset($mapa[$cod])) {
                continue;
            }

            if (!isset($faltan[$cod])) {
                $faltan[$cod] = [
                    'COD_PROVEE' => $cod,
                    'RAZON_SOC' => isset($p['RAZON_SOC']) ? $p['RAZON_SOC'] : '',
                    'VENCIMIENTOS' => 0,
                    'IMPORTE' => 0.0
                ];
            }

            $faltan[$cod]['VENCIMIENTOS']++;
            $faltan[$cod]['IMPORTE'] += floatval($p['IMPORTE_PENDIENTE']);
        }

        // De mayor a menor deuda: si la lista es larga, lo que importa es por
        // cual empezar.
        uasort($faltan, function ($a, $b) {
            return ($b['IMPORTE'] < $a['IMPORTE']) ? -1 : (($b['IMPORTE'] > $a['IMPORTE']) ? 1 : 0);
        });

        return array_values($faltan);
    }

    /**
     * Los proveedores que Tango marca como egreso de directores pero que el
     * maestro NO tiene como Excluidos.
     *
     * Son dos listas que dicen lo mismo con distinta autoridad. Manda el
     * maestro; esto audita. Si la vista tiene a alguien que el maestro no
     * excluye, alguna de las dos esta desactualizada y hay que mirarlo.
     *
     * Si la vista no existe devuelve vacio y no rompe: es un control, no un
     * requisito.
     *
     * @return array Filas ['COD_PROVEE', 'NOM_PROVEE', 'RUBRO_ECONOMICO', 'en_maestro']
     */
    public function directoresNoExcluidos() {
        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT COD_PROVEE, NOM_PROVEE FROM " . self::VISTA_DIRECTORES);

        if ($stmt === false) {
            // La vista puede no existir en otro entorno. Sin control, pero sin
            // romper: el maestro sigue mandando igual.
            return [];
        }

        $mapa = $this->mapa();
        $discrepan = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = Planilla::codigo($row['COD_PROVEE']);
            $enMaestro = isset($mapa[$cod]);
            $rubro = $enMaestro ? $mapa[$cod]['RUBRO_ECONOMICO'] : null;

            if ($enMaestro && self::esExcluido($rubro)) {
                continue;   // las dos listas coinciden
            }

            $discrepan[] = [
                'COD_PROVEE' => $cod,
                'NOM_PROVEE' => trim((string) $row['NOM_PROVEE']),
                'RUBRO_ECONOMICO' => $rubro,
                'en_maestro' => $enMaestro
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $discrepan;
    }

    /* ====================================================================
       IMPORTACION DEL MAESTRO

       La hoja "Maestro proveedores" del Excel Cronograma de Pagos, que
       administracion mantiene a mano. Mismo circuito que Cob. Electronicos:
       plantilla CSV, previsualizacion del diff, y NADA se escribe hasta que el
       usuario confirma.

       LA PLANILLA VIENE SUCIA Y ESO SE MUESTRA, NO SE ARREGLA. Tiene un codigo
       repetido, un 'echeq' en minuscula y un 'ECOMMERC' por 'ECOMMERCE'. Un
       importador que los corrige solo deja la planilla rota para siempre, porque
       nadie se entera nunca de que lo esta. Lo que hay que arreglar es la
       planilla.
       ==================================================================== */

    /**
     * Las columnas de la planilla, con sus sinonimos.
     *
     * Es la UNICA definicion: de aca salen la plantilla que se descarga, el
     * mapeo del encabezado al parsear y la ayuda de la pantalla.
     *
     * SOLO EL CODIGO ES OBLIGATORIO. El resto puede faltar y de hecho falta: en
     * la planilla real hay 84 filas sin rubro economico, 98 sin centro de costos
     * y 765 sin plazo de pago. Exigirlos haria que la importacion falle entera
     * por datos que administracion todavia no cargo.
     *
     * @return array Mapa campo => ['titulo', 'obligatoria', 'ayuda', 'sinonimos']
     */
    public static function columnasImportacion() {
        return [
            'cod_provee' => [
                'titulo' => 'CODIGO',
                'obligatoria' => true,
                'ayuda' => 'Código del proveedor en Tango, de 4 a 7 caracteres. Es lo único '
                    . 'que permite cruzar la planilla contra las cuentas a pagar.',
                'sinonimos' => ['CODIGO', 'COD_PROVEE', 'CODPROVEEDOR', 'COD_PROVEEDOR',
                                'CODIGOPROVEEDOR', 'PROVEEDOR']
            ],
            'nombre' => [
                'titulo' => 'NOMBRE',
                'obligatoria' => false,
                'ayuda' => 'Nombre del proveedor. No se usa para cruzar -para eso está el '
                    . 'código- pero es lo que permite reconocerlo en la previsualización.',
                'sinonimos' => ['NOMBRE', 'RAZON_SOCIAL', 'RAZONSOCIAL', 'NOM_PROVEE',
                                'DESCRIPCION']
            ],
            'rubro_economico' => [
                'titulo' => 'RUBRO ECONOMICO',
                'obligatoria' => false,
                'ayuda' => 'Es el que mapea a las filas del tablero. "'
                    . self::RUBRO_EXCLUIDOS . '" saca al proveedor del cuadro.',
                'sinonimos' => ['RUBROECONOMICO', 'RUBRO_ECONOMICO', 'RUBROECON', 'ECONOMICO']
            ],
            'rubro' => [
                'titulo' => 'RUBRO',
                'obligatoria' => false,
                'ayuda' => 'Apertura más fina que el rubro económico. Hoy sólo se guarda.',
                'sinonimos' => ['RUBRO']
            ],
            'centro_costos' => [
                'titulo' => 'CENTRO COSTOS',
                'obligatoria' => false,
                'ayuda' => 'Centro de costos al que se imputa. Hoy sólo se guarda.',
                'sinonimos' => ['CENTROCOSTOS', 'CENTRO_COSTOS', 'CENTRODECOSTOS', 'CCOSTOS',
                                'CENTRO_DE_COSTOS']
            ],
            'forma_pago' => [
                'titulo' => 'FORMA DE PAGO',
                'obligatoria' => false,
                'ayuda' => 'Cómo se le paga habitualmente. Se usa como valor por defecto al '
                    . 'importar pagos. Válidos: ' . implode(', ', self::FORMAS_PAGO) . '.',
                'sinonimos' => ['FORMADEPAGO', 'FORMA_PAGO', 'FORMAPAGO', 'MEDIODEPAGO']
            ],
            'plazo_pago' => [
                'titulo' => 'PLAZO DE PAGO',
                'obligatoria' => false,
                'ayuda' => 'CONTADO, DEBITO o "N DIAS". Se usa para estimar la fecha sólo '
                    . 'cuando el comprobante no trae vencimiento.',
                'sinonimos' => ['PLAZODEPAGO', 'PLAZO_PAGO', 'PLAZOPAGO', 'PLAZO', 'CONDICION']
            ],
            'criterio_distrib' => [
                'titulo' => 'CRITERIO DISTRIBUCION',
                'obligatoria' => false,
                'ayuda' => 'Cómo se reparte el gasto entre canales. Hoy sólo se guarda.',
                'sinonimos' => ['CRITERIODISTRIBUCION', 'CRITERIO_DISTRIBUCION', 'CRITERIO',
                                'DISTRIBUCION']
            ]
        ];
    }

    /**
     * La plantilla que se descarga, con filas de ejemplo cargadas.
     *
     * @return string
     */
    public static function plantillaCsv() {
        return Planilla::plantillaCsv(self::columnasImportacion(), [
            ['MTDODI', 'DONNA DI DIO S.R.L.', 'Mercaderia', 'Talleres', 'Fabrica',
             'TRANSFERENCIA', '30 DIAS', '100% VENTAS'],
            ['SAPALA', 'IRSA INVERSIONES Y REPRESENTACIONES SA', 'Alquileres', 'Shoppings',
             'Locales', 'TRANSFERENCIA', 'CONTADO', '100% LOCALES'],
            ['MTTESO', 'TESORERIA', self::RUBRO_EXCLUIDOS, '', '', 'EFECTIVO', '', '']
        ]);
    }

    /**
     * Compara lo que trae el archivo contra el maestro cargado y dice QUE
     * CAMBIARIA. No escribe nada.
     *
     * Es un helper PURO: recibe las filas ya parseadas y el maestro actual, y
     * devuelve el diff. Se prueba entero sin base y sin archivos, que es lo que
     * permite verificar los casos sucios -el codigo repetido, el 'echeq', el
     * 'ECOMMERC'- sin tener que fabricar un CSV.
     *
     * ESTADOS DE UNA FILA
     *   ALTA         el proveedor no estaba en el maestro
     *   CAMBIO       estaba y algun campo cambia; 'cambios' dice cuales
     *   SIN_CAMBIOS  estaba igual
     *   ERROR        no se puede cargar; 'motivo' dice por que
     *
     * Y aparte, las BAJAS: proveedores que estan vigentes en el maestro y que el
     * archivo NO trae. No se dan de baja en silencio -se listan y se confirman-
     * porque una planilla recortada por error daria de baja medio maestro.
     *
     * @param array $filasArchivo Filas de Planilla::parsear()
     * @param array $existentes Maestro vigente, indexado por COD_PROVEE
     * @return array ['filas', 'bajas', 'resumen', 'avisos']
     */
    public static function compararImportacion($filasArchivo, $existentes) {
        $existentes = is_array($existentes) ? $existentes : [];
        $filas = [];
        $vistos = [];
        $tocados = [];

        /* Cuantas veces aparece cada CRITERIO DISTRIBUCION. Ver
           criteriosSospechosos(): es como se detecta un typo sin tener una lista
           declarada de criterios validos. */
        $criterios = [];

        $resumen = [
            'altas' => 0, 'cambios' => 0, 'sin_cambios' => 0,
            'errores' => 0, 'bajas' => 0,
            'sin_rubro' => 0, 'excluidos' => 0,
            'forma_desconocida' => 0, 'plazo_no_usable' => 0
        ];

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $cruda) {
            $fila = self::filaImportacion($cruda);

            if ($fila['estado'] !== 'ERROR') {
                $cod = $fila['cod_provee'];

                /* EL CODIGO REPETIDO NO SE COLAPSA. La planilla real tiene uno
                   (1.222 unicos en 1.223 filas). Quedarse con el ultimo elegiria
                   por el usuario y nadie se enteraria de que hay un duplicado.
                   Las DOS filas quedan en error, nombrando a la otra. */
                if (isset($vistos[$cod])) {
                    $fila['estado'] = 'ERROR';
                    $fila['motivo'] = 'El código ' . $cod . ' ya aparece en la línea '
                        . $vistos[$cod] . '. Está repetido en la planilla: dejá una sola fila '
                        . 'por proveedor y volvé a importar.';

                    // La primera tambien pasa a error: si no, se cargaria una de
                    // las dos sin que nadie haya decidido cual.
                    foreach ($filas as $i => $anterior) {
                        if ($anterior['cod_provee'] === $cod && $anterior['estado'] !== 'ERROR') {
                            $filas[$i]['estado'] = 'ERROR';
                            $filas[$i]['motivo'] = 'El código ' . $cod . ' se repite en la '
                                . 'línea ' . $fila['linea'] . '. Está repetido en la planilla: '
                                . 'dejá una sola fila por proveedor y volvé a importar.';
                            $resumen['errores']++;
                            $resumen[strtolower($anterior['estado']) === 'alta'
                                ? 'altas' : (strtolower($anterior['estado']) === 'cambio'
                                    ? 'cambios' : 'sin_cambios')]--;
                        }
                    }
                } else {
                    $vistos[$cod] = $fila['linea'];

                    if (!isset($existentes[$cod])) {
                        $fila['estado'] = 'ALTA';
                        $fila['motivo'] = 'No estaba en el maestro.';
                    } else {
                        $tocados[$cod] = true;
                        $fila = self::compararContraExistente($fila, $existentes[$cod]);
                    }
                }
            }

            /* La calidad del dato se cuenta en toda fila que se vaya a cargar,
               incluso en una que no cambia nada: un 'echeq' en minuscula que ya
               estaba cargado sigue siendo un typo de la planilla y hay que
               arreglarlo.

               PERO NO EN LAS FILAS EN ERROR. Esas no se cargan, asi que no
               tienen calidad que evaluar; peor todavia, una fila que fallo por
               el codigo ni siquiera llego a leer el rubro y contaria como "sin
               rubro" siendo que lo trae. */
            if ($fila['estado'] !== 'ERROR') {
                if ($fila['forma_desconocida']) { $resumen['forma_desconocida']++; }
                if ($fila['plazo_no_usable']) { $resumen['plazo_no_usable']++; }
                if ($fila['excluido']) { $resumen['excluidos']++; }
                if ($fila['rubro_economico'] === null) { $resumen['sin_rubro']++; }

                if ($fila['criterio_distrib'] !== null) {
                    $clave = $fila['criterio_distrib'];
                    $criterios[$clave] = isset($criterios[$clave]) ? $criterios[$clave] + 1 : 1;
                }
            }

            switch ($fila['estado']) {
                case 'ALTA': $resumen['altas']++; break;
                case 'CAMBIO': $resumen['cambios']++; break;
                case 'SIN_CAMBIOS': $resumen['sin_cambios']++; break;
                case 'ERROR': $resumen['errores']++; break;
            }

            $filas[] = $fila;
        }

        /* Las bajas: lo que esta vigente y el archivo no trae. */
        $bajas = [];

        foreach ($existentes as $cod => $e) {
            if (isset($tocados[$cod])) {
                continue;
            }

            $bajas[] = [
                'cod_provee' => $cod,
                'nombre' => $e['NOMBRE'],
                'rubro_economico' => $e['RUBRO_ECONOMICO']
            ];
        }

        $resumen['bajas'] = count($bajas);

        $sospechosos = self::criteriosSospechosos($criterios);

        return [
            'filas' => $filas,
            'bajas' => $bajas,
            'resumen' => $resumen,
            'criterios' => $criterios,
            'criterios_sospechosos' => $sospechosos,
            'avisos' => self::avisosImportacion($resumen, count($existentes), $sospechosos)
        ];
    }

    /**
     * Los CRITERIO DISTRIBUCION que parecen un typo.
     *
     * NO HAY UNA LISTA DECLARADA DE CRITERIOS VALIDOS, y no se inventa una: son
     * texto que escribe administracion y declararla seria decidir por ellos cual
     * es el juego completo.
     *
     * Lo que si se puede afirmar sin inventar nada es que UN VALOR QUE APARECE
     * DOS VECES CUANDO OTRO PARECIDO APARECE DOSCIENTAS es sospechoso. Es
     * exactamente el caso de '50% ECOMMERC / 50% VENTAS' contra
     * '50% ECOMMERCE / 50% VENTAS': dos filas contra el resto.
     *
     * Se marca y se muestra; no se corrige. Lo que hay que arreglar es la
     * planilla, y si el importador lo arregla solo nadie se entera nunca.
     *
     * Estatica y pura.
     *
     * @param array $criterios Mapa criterio => cuantas veces aparece
     * @param int $umbral Hasta cuantas apariciones se considera sospechoso
     * @return array Filas ['criterio', 'veces', 'parecido_a']
     */
    public static function criteriosSospechosos($criterios, $umbral = 2) {
        $sospechosos = [];

        foreach ($criterios as $criterio => $veces) {
            if ($veces > $umbral) {
                continue;
            }

            /* Se busca un criterio MUCHO mas frecuente que se le parezca. Sin
               ese parecido, un criterio raro puede ser simplemente uno que se
               usa poco, y avisar de todos seria ruido. */
            $parecido = null;
            $mejor = 0;

            foreach ($criterios as $otro => $vecesOtro) {
                if ($otro === $criterio || $vecesOtro <= $veces) {
                    continue;
                }

                similar_text(
                    Planilla::normalizarTitulo($criterio),
                    Planilla::normalizarTitulo($otro),
                    $porcentaje
                );

                if ($porcentaje >= 85 && $vecesOtro > $mejor) {
                    $parecido = $otro;
                    $mejor = $vecesOtro;
                }
            }

            if ($parecido === null) {
                continue;
            }

            $sospechosos[] = [
                'criterio' => $criterio,
                'veces' => $veces,
                'parecido_a' => $parecido,
                'veces_parecido' => $mejor
            ];
        }

        return $sospechosos;
    }

    /**
     * Normaliza una fila cruda de la planilla y la valida.
     *
     * CADA VALOR NORMALIZADO VIAJA CON SU ORIGINAL. El normalizado es con el que
     * se agrupa y se decide; el original es lo que hay que mostrar cuando no
     * matchea, porque "no reconocí FORMA DE PAGO" sin decir que decía la celda
     * obliga a abrir la planilla y buscar la fila.
     *
     * @param array $cruda
     * @return array
     */
    private static function filaImportacion($cruda) {
        $linea = isset($cruda['linea']) ? intval($cruda['linea']) : 0;

        $fila = [
            'linea' => $linea,
            'cod_provee' => '',
            'nombre' => '',
            'rubro_economico' => null,
            'rubro' => null,
            'centro_costos' => null,
            'forma_pago' => null,
            'forma_pago_orig' => '',
            'forma_desconocida' => false,
            'plazo_pago' => null,
            'plazo_dias' => null,
            'plazo_no_usable' => false,
            'criterio_distrib' => null,
            'criterio_orig' => '',
            'excluido' => false,
            'estado' => 'ALTA',
            'motivo' => '',
            'cambios' => []
        ];

        $cod = Planilla::codigo(isset($cruda['cod_provee']) ? $cruda['cod_provee'] : '');

        if ($cod === '') {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'La fila no tiene código de proveedor.';

            return $fila;
        }

        /* El codigo de Tango es de 6 CARACTERES, y hay que contarlos con
           mb_strlen y no con strlen.

           strlen cuenta BYTES: 'OGNUÑE' da 7 porque la eñe ocupa dos en UTF-8, y
           el codigo quedaba rechazado siendo valido -existe en CPA01 con LEN 6-.
           En el maestro hay 27 proveedores con caracteres no ASCII en el codigo.
           Ver Planilla::largo(). */
        if (Planilla::largo($cod) > self::LARGO_CODIGO) {
            $fila['cod_provee'] = $cod;
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'El código "' . $cod . '" tiene ' . Planilla::largo($cod)
                . ' caracteres y en Tango son ' . self::LARGO_CODIGO . ' como máximo, así que '
                . 'no va a cruzar contra ninguna cuenta a pagar.';

            return $fila;
        }

        $fila['cod_provee'] = $cod;
        $fila['nombre'] = trim(isset($cruda['nombre']) ? $cruda['nombre'] : '');

        $fila['rubro_economico'] = self::textoONull($cruda, 'rubro_economico');
        $fila['rubro'] = self::textoONull($cruda, 'rubro');
        $fila['centro_costos'] = self::textoONull($cruda, 'centro_costos');
        $fila['excluido'] = self::esExcluido($fila['rubro_economico']);

        /* La forma de pago se normaliza contra la lista declarada SIN PERDER el
           original: un 'echeq' en minuscula matchea contra ECHEQ; un valor que
           no matchea se guarda igual y se muestra. */
        $forma = Planilla::normalizarContra(
            isset($cruda['forma_pago']) ? $cruda['forma_pago'] : '', self::FORMAS_PAGO);

        $fila['forma_pago'] = $forma['normalizado'];
        $fila['forma_pago_orig'] = $forma['original'];
        $fila['forma_desconocida'] = ($forma['original'] !== '' && $forma['normalizado'] === null);

        $fila['plazo_pago'] = self::textoONull($cruda, 'plazo_pago');
        $fila['plazo_dias'] = self::plazoEnDias($fila['plazo_pago']);

        /* Un plazo que no se puede llevar a dias NO es un error: DEBITO es un
           plazo legitimo que simplemente no dice cuando. Se cuenta para el
           resumen, porque es lo que explica que el tercer escalon de la
           jerarquia de fecha aplique a pocos proveedores. */
        $fila['plazo_no_usable'] = ($fila['plazo_pago'] !== null && $fila['plazo_dias'] === null);

        $criterio = trim(isset($cruda['criterio_distrib']) ? $cruda['criterio_distrib'] : '');
        $fila['criterio_orig'] = $criterio;
        $fila['criterio_distrib'] = ($criterio === '') ? null : $criterio;

        return $fila;
    }

    /** Un campo de texto de la planilla, o null si vino vacio */
    private static function textoONull($cruda, $campo) {
        $v = trim(isset($cruda[$campo]) ? (string) $cruda[$campo] : '');

        return ($v === '') ? null : $v;
    }

    /**
     * Compara una fila del archivo contra la que ya esta cargada.
     *
     * DICE QUE CAMBIA, CAMPO POR CAMPO. Un "cambió" sin decir qué obliga a
     * abrir las dos versiones para entender si el cambio es el que se esperaba.
     *
     * @param array $fila
     * @param array $existente
     * @return array
     */
    private static function compararContraExistente($fila, $existente) {
        $comparar = [
            'nombre' => 'NOMBRE',
            'rubro_economico' => 'RUBRO_ECONOMICO',
            'rubro' => 'RUBRO',
            'centro_costos' => 'CENTRO_COSTOS',
            'forma_pago' => 'FORMA_PAGO',
            'plazo_pago' => 'PLAZO_PAGO',
            'criterio_distrib' => 'CRITERIO_DISTRIB'
        ];

        $cambios = [];

        foreach ($comparar as $campo => $columna) {
            $nuevo = $fila[$campo];
            $viejo = isset($existente[$columna]) ? $existente[$columna] : null;

            // Se comparan como texto: null y '' son lo mismo para el usuario.
            if (trim((string) $nuevo) === trim((string) $viejo)) {
                continue;
            }

            $cambios[] = [
                'campo' => $columna,
                'antes' => $viejo,
                'ahora' => $nuevo
            ];
        }

        if (empty($cambios)) {
            $fila['estado'] = 'SIN_CAMBIOS';
            $fila['motivo'] = 'Ya estaba cargado igual.';

            return $fila;
        }

        $fila['estado'] = 'CAMBIO';
        $fila['cambios'] = $cambios;
        $fila['motivo'] = count($cambios) . ' campo(s) cambian.';

        return $fila;
    }

    /**
     * Los avisos del resumen de importacion.
     *
     * Son los que hacen que la previsualizacion sirva para decidir y no solo
     * para mirar. Estaticos y puros.
     *
     * @param array $resumen
     * @param int $cuantosHabia Proveedores vigentes antes de importar
     * @return array
     */
    private static function avisosImportacion($resumen, $cuantosHabia, $sospechosos = []) {
        $avisos = [];

        foreach ($sospechosos as $s) {
            $avisos[] = 'El criterio de distribución "' . $s['criterio'] . '" aparece '
                . $s['veces'] . ' vez/veces, y se parece mucho a "' . $s['parecido_a']
                . '", que aparece ' . $s['veces_parecido'] . '. Probablemente sea un error de '
                . 'tipeo en la planilla. Se guarda tal como vino: corregilo allá.';
        }

        if ($resumen['errores'] > 0) {
            $avisos[] = $resumen['errores'] . ' fila(s) no se pueden cargar y quedan afuera. '
                . 'El resto se importa igual: mirá el motivo de cada una.';
        }

        /* UNA BAJA MASIVA CASI SIEMPRE ES UNA PLANILLA RECORTADA. Si el archivo
           trae menos de la mitad de lo que hay cargado, lo mas probable es que
           alguien exporto un filtro y no el maestro entero. */
        if ($resumen['bajas'] > 0 && $cuantosHabia > 0
            && $resumen['bajas'] > ($cuantosHabia / 2)) {
            $avisos[] = 'ATENCIÓN: el archivo daría de baja ' . $resumen['bajas']
                . ' de los ' . $cuantosHabia . ' proveedores cargados. ¿Estás importando el '
                . 'maestro completo o una parte filtrada? Revisá la lista de bajas antes de '
                . 'confirmar.';
        } elseif ($resumen['bajas'] > 0) {
            $avisos[] = $resumen['bajas'] . ' proveedor(es) están cargados y el archivo no los '
                . 'trae. Se darían de baja (baja lógica: quedan en el historial).';
        }

        if ($resumen['forma_desconocida'] > 0) {
            $avisos[] = $resumen['forma_desconocida'] . ' fila(s) tienen una FORMA DE PAGO que '
                . 'no está en la lista de válidas. Se guardan tal como vinieron, pero no se '
                . 'van a poder usar como valor por defecto al importar pagos. Corregilas en la '
                . 'planilla: el importador no las arregla solo, a propósito.';
        }

        if ($resumen['sin_rubro'] > 0) {
            $avisos[] = $resumen['sin_rubro'] . ' fila(s) no tienen RUBRO ECONÓMICO. Esos '
                . 'proveedores se cargan igual, pero su deuda no se va a poder abrir por rubro '
                . 'en el tablero.';
        }

        if ($resumen['plazo_no_usable'] > 0) {
            $avisos[] = $resumen['plazo_no_usable'] . ' fila(s) tienen un PLAZO DE PAGO que no '
                . 'se puede llevar a días (DEBITO, por ejemplo). No es un error: para esos '
                . 'proveedores manda la fecha de vencimiento del comprobante.';
        }

        return $avisos;
    }

    /**
     * Aplica una importacion ya confirmada.
     *
     * TODO EN UNA TRANSACCION. Si se diera de baja el maestro viejo y fallara el
     * alta del nuevo, el tablero se quedaria sin ninguna clasificacion y nadie
     * sabria por que.
     *
     * NO HAY BAJA FISICA: lo reemplazado queda con VIGENTE = 0 y su FECHA_BAJA.
     * El historial es lo unico que explica por que un comprobante se clasificaba
     * distinto la semana pasada.
     *
     * LAS FILAS EN ERROR NO SE TOCAN. Se importa lo que se pueda; parar todo por
     * una fila mala obligaria a corregir la planilla entera antes de poder
     * cargar las mil doscientas que estan bien.
     *
     * @param array $comparacion Lo que devolvio compararImportacion()
     * @param bool $aplicarBajas Si se dan de baja los que el archivo no trae
     * @param string|null $usuario
     * @return array ['altas', 'cambios', 'bajas']
     */
    public function aplicarImportacion($comparacion, $aplicarBajas, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla del maestro. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $cid = $this->conectar();
        $aplicadas = ['altas' => 0, 'cambios' => 0, 'bajas' => 0];

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($comparacion['filas'] as $fila) {
                if ($fila['estado'] !== 'ALTA' && $fila['estado'] !== 'CAMBIO') {
                    continue;
                }

                // Un CAMBIO es una baja mas un alta: asi queda el historial.
                if ($fila['estado'] === 'CAMBIO') {
                    $this->bajaVigente($cid, $fila['cod_provee']);
                    $aplicadas['cambios']++;
                } else {
                    $aplicadas['altas']++;
                }

                $this->insertar($cid, $fila, $usuario);
            }

            if ($aplicarBajas) {
                foreach ($comparacion['bajas'] as $baja) {
                    $this->bajaVigente($cid, $baja['cod_provee']);
                    $aplicadas['bajas']++;
                }
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        // El mapa cacheado quedo viejo.
        $this->mapa = null;

        return $aplicadas;
    }

    /** Marca VIGENTE = 0 la fila vigente de un proveedor */
    private function bajaVigente($cid, $codProvee) {
        $stmt = sqlsrv_query($cid,
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = 0, FECHA_BAJA = GETDATE()
             WHERE COD_PROVEE = ? AND VIGENTE = 1",
            [$codProvee]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja el maestro anterior'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** Inserta una fila del maestro */
    private function insertar($cid, $fila, $usuario) {
        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA . "
                 (COD_PROVEE, NOMBRE, RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS,
                  FORMA_PAGO, FORMA_PAGO_ORIG, PLAZO_PAGO, PLAZO_DIAS,
                  CRITERIO_DISTRIB, CRITERIO_ORIG, VIGENTE, USUARIO)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
            [
                $fila['cod_provee'],
                ($fila['nombre'] === '') ? null : mb_substr($fila['nombre'], 0, 120),
                $fila['rubro_economico'],
                $fila['rubro'],
                $fila['centro_costos'],
                $fila['forma_pago'],
                ($fila['forma_pago_orig'] === '') ? null : $fila['forma_pago_orig'],
                $fila['plazo_pago'],
                $fila['plazo_dias'],
                $fila['criterio_distrib'],
                ($fila['criterio_orig'] === '') ? null : $fila['criterio_orig'],
                $usuario
            ]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al cargar el proveedor '
                . $fila['cod_provee']));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * El historial de un proveedor: todas sus versiones, de la mas nueva a la
     * mas vieja.
     *
     * Es lo que explica por que un comprobante se clasificaba distinto antes.
     *
     * @param string $codProvee
     * @return array
     */
    public function getHistorial($codProvee) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $cid = $this->conectar();

        $sql = "SELECT ID, COD_PROVEE, NOMBRE, RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS,
                       FORMA_PAGO, FORMA_PAGO_ORIG, PLAZO_PAGO, PLAZO_DIAS,
                       CRITERIO_DISTRIB, VIGENTE, USUARIO, FECHA_IMPORTACION, FECHA_BAJA
                FROM dbo." . self::TABLA . "
                WHERE COD_PROVEE = ?
                ORDER BY ID DESC";

        $stmt = sqlsrv_query($cid, $sql, [Planilla::codigo($codProvee)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial del proveedor'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['VIGENTE'] = intval($row['VIGENTE']);
            $row['PLAZO_DIAS'] = ($row['PLAZO_DIAS'] === null) ? null : intval($row['PLAZO_DIAS']);
            $row['FECHA_IMPORTACION'] = $this->fechaHora($row['FECHA_IMPORTACION']);
            $row['FECHA_BAJA'] = $this->fechaHora($row['FECHA_BAJA']);
            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
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

    /** Un DATETIME de SQL Server como texto, o null */
    private function fechaHora($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i:s');
        }

        return ($v === null) ? null : (string) $v;
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
