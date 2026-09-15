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
            $cod = strtoupper(trim((string) $row['COD_PROVEE']));

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
        $cod = strtoupper(trim((string) $codProvee));
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
            $cod = strtoupper(trim((string) $p['COD_PROVEE']));

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
            $cod = strtoupper(trim((string) $row['COD_PROVEE']));
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
