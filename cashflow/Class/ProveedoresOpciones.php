<?php

require_once __DIR__ . '/Planilla.php';

/**
 * La consulta de las listas fallo: la tabla esta, pero no se pudo leer.
 *
 * TIENE CLASE PROPIA PARA QUE NO SE CONFUNDA CON "todavia no se corrio el
 * script". Antes las dos situaciones terminaban en el mismo null, porque un
 * catch (Throwable) se comia la diferencia, y mientras los valores fuera de
 * lista eran una advertencia no importaba: en los dos casos no se marcaba nada.
 *
 * Ahora si importa. Un valor fuera de lista deja la fila en ERROR, asi que "no
 * pude leer las listas" no puede resolverse dejando pasar todo: no hay un
 * subconjunto de filas validas que dejar pasar, porque no se validó ninguna. Es
 * el mismo criterio que CPA01 caido.
 *
 * Ver ProveedoresCategorias::listasVigentes(), que es quien la lanza.
 */
class OpcionesIlegibles extends Exception {
}

/**
 * ProveedoresOpciones
 * Las cinco listas de valores validos del maestro de Proveedores Locales.
 *
 * QUE RESUELVE
 * ------------
 * RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS, PLAZO_PAGO y CRITERIO_DISTRIB eran
 * texto libre. El formulario ofrecia un datalist armado con los valores ya
 * cargados -ProveedoresCategorias::rubrosCargados()- pero era una sugerencia:
 * se podia escribir cualquier cosa.
 *
 * Y una de las cinco no es cosmetica: CADA RUBRO_ECONOMICO DISTINTO CREA UNA
 * SERIE PROPIA EN EL TABLERO, via serieDeRubro(). Tipear "Alquileres " con un
 * espacio al final no es un typo, es una fila nueva del cuadro que nadie pidio.
 *
 * El caso ya estaba documentado en el propio modulo: la planilla trae
 * '50% ECOMMERC' contra '50% ECOMMERCE', dos filas contra doscientas, y eso hoy
 * se detecta a POSTERIORI comparando parecidos -criteriosSospechosos()- porque
 * no habia ninguna lista declarada contra la cual validar. Ahora la hay.
 *
 * SON CINCO LISTAS INDEPENDIENTES
 * -------------------------------
 * RUBRO no depende de RUBRO_ECONOMICO: no hay jerarquia y elegir un rubro
 * economico no acota los rubros disponibles. Si algun dia hiciera falta, seria
 * una columna nueva y no una tabla mas.
 *
 * NO SON COMO FORMAS_PAGO, Y LA DIFERENCIA ES QUIEN LAS DECIDE
 * ------------------------------------------------------------
 * FORMAS_PAGO es una constante del CODIGO: las seis formas son un criterio de
 * negocio del que dependen decisiones -esDelCronograma() decide con ellas si un
 * comprobante entra al cashflow- asi que agregar una es un cambio de codigo con
 * su docblock explicando por que.
 *
 * Estas cinco son DATOS: administracion agrega un centro de costos nuevo el dia
 * que abre un deposito, y no puede depender de que alguien toque codigo. Por
 * eso viven en una tabla y se administran desde Parametros.
 *
 * LA BAJA ES LOGICA, Y ESO TIENE UNA CONSECUENCIA QUE HAY QUE VER
 * ---------------------------------------------------------------
 * Un valor dado de baja deja de OFRECERSE, pero los proveedores que ya lo
 * tienen lo conservan: la pantalla los marca como "fuera de lista" y siguen
 * funcionando igual. Borrar la fila dejaria proveedores apuntando a un valor
 * que ya no se puede explicar, y ademas cambiaria la serie del tablero de los
 * que tengan ese rubro economico.
 *
 * EL PLAZO ES LA UNICA QUE EL SISTEMA USA PARA CALCULAR
 * -----------------------------------------------------
 * Las otras cuatro se guardan y se muestran. PLAZO se traduce a DIAS, y esos
 * dias son el ultimo escalon de la jerarquia de resolucion de fecha de pago.
 * Por eso la lista guarda el texto Y su interpretacion:
 *
 *     CONTADO   -> 0      se paga el dia de la factura
 *     '30 DIAS' -> 30
 *     DEBITO    -> null   se debita solo; la fecha no la decide un plazo
 *
 * NULL NO ES CERO. Cero es "se paga hoy" y null es "este plazo no dice cuando":
 * el primero calcula una fecha y el segundo hace caer la jerarquia al escalon
 * siguiente. Perder esa distincion cambiaria la fecha de pago de todos los
 * proveedores con DEBITO.
 *
 * Guardarlo en la lista -en vez de derivarlo siempre del texto- permite que
 * administracion declare un plazo que plazoEnDias() no sabria interpretar,
 * como 'FIN DE MES' -> 30. Cuando el valor NO esta en la lista, plazoEnDias()
 * sigue siendo el fallback: ver ProveedoresCategorias::normalizarFila().
 *
 * CRITERIO_DISTRIB ES SOLO UN NOMBRE, POR AHORA
 * ----------------------------------------------
 * Se verifico antes de escribir esto: en todo el modulo se guarda, se muestra,
 * se compara en el diff y se audita por typos. NINGUN CALCULO DEPENDE DE EL. La
 * lista lo normaliza y nada mas. El dia que tenga que repartir un gasto entre
 * canales, los porcentajes son columnas nuevas de esta misma tabla.
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * tablaCreada() se pregunta, igual que en ProveedoresCategorias. Sin la tabla,
 * la pantalla vuelve al comportamiento anterior -texto libre con sugerencias- y
 * lo dice, en lugar de quedarse con desplegables vacios que no dejan cargar
 * nada.
 *
 * "NO EXISTE LA TABLA" Y "NO SE PUDO LEER" SON DOS COSAS DISTINTAS
 * ----------------------------------------------------------------
 * Y desde que las listas son REGLA y no advertencia, la diferencia decide algo:
 * sin tabla el modulo funciona como antes -texto libre, no se valida nada- y
 * una consulta que FALLA significa que no se pudo chequear ninguna fila, que no
 * es lo mismo que "ninguna esta mal". Ver OpcionesIlegibles, arriba, y
 * ProveedoresCategorias::listasVigentes().
 */
class ProveedoresOpciones {

    /** La tabla de opciones, en la base central */
    const TABLA = 'RO_T_CASHFLOW_PROV_LOCALES_OPCIONES';

    /**
     * Las cinco listas, con su nombre de cara al usuario y la columna del
     * maestro que validan.
     *
     * ES LA UNICA DEFINICION: de aca salen el CHECK que espera el script, las
     * sub-pestanas de Parametros, la validacion de la importacion y el mapeo
     * campo -> lista del formulario. Con tres listas distintas, la pantalla y
     * el validador se desincronizan en el primer cambio, que es exactamente lo
     * que le paso a FORMAS_PAGO.
     */
    const TIPOS = [
        'RUBRO_ECONOMICO' => [
            'nombre' => 'Rubro económico',
            'campo' => 'rubro_economico',
            'columna' => 'RUBRO_ECONOMICO',
            'ayuda' => 'Es el que abre la deuda por serie en el tablero: cada valor distinto '
                . 'crea una fila propia. Por eso es la lista que más conviene tener corta.'
        ],
        'RUBRO' => [
            'nombre' => 'Rubro',
            'campo' => 'rubro',
            'columna' => 'RUBRO',
            'ayuda' => 'Clasifica adentro del rubro económico, pero NO depende de él: las dos '
                . 'listas son independientes. Hoy sólo se guarda.'
        ],
        'CENTRO_COSTOS' => [
            'nombre' => 'Centro de costos',
            'campo' => 'centro_costos',
            'columna' => 'CENTRO_COSTOS',
            'ayuda' => 'Centro de costos al que se imputa el gasto. Hoy sólo se guarda.'
        ],
        'PLAZO' => [
            'nombre' => 'Plazo de pago',
            'campo' => 'plazo_pago',
            'columna' => 'PLAZO_PAGO',
            'ayuda' => 'La única lista que el sistema USA para calcular: sus días son el último '
                . 'escalón de la fecha de pago, cuando el comprobante no trae vencimiento. '
                . 'Dejá los días vacíos para un plazo que no dice cuándo, como DÉBITO.'
        ],
        'CRITERIO_DISTRIB' => [
            'nombre' => 'Criterio de distribución',
            'campo' => 'criterio_distrib',
            'columna' => 'CRITERIO_DISTRIB',
            'ayuda' => 'Cómo se reparte el gasto entre canales. Hoy es sólo un nombre: no '
                . 'define porcentajes ni afecta ningún cálculo.'
        ]
    ];

    /** El unico tipo que lleva dias */
    const TIPO_PLAZO = 'PLAZO';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de la tabla */
    private $tabla = null;

    /** @var array|null Cache de las listas */
    private $listas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_prov_locales_opciones.sql */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        $cid = $this->conectar();
        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de opciones'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /**
     * Avisos de configuracion pendiente.
     *
     * LO QUE FALTA SE DICE, AUNQUE NO ROMPA NADA: sin la tabla, los campos del
     * formulario vuelven a ser texto libre con sugerencias -que es como
     * funcionaban antes- y quien esperaba desplegables no tiene donde enterarse
     * de por que no los ve.
     *
     * @return array
     */
    public function getAvisos() {
        if (!$this->tablaCreada()) {
            return ['Todavía no existen las listas de opciones del maestro de proveedores. '
                . 'Corré sql/cashflow_prov_locales_opciones.sql contra la base central: siembra '
                . 'las cinco listas con los valores que ya están cargados. Mientras tanto, los '
                . 'campos del alta manual siguen siendo texto libre con sugerencias, y la '
                . 'importación no valida contra ninguna lista.'];
        }

        $avisos = [];
        $vacias = [];

        foreach ($this->listas() as $tipo => $opciones) {
            $hayVigentes = false;

            foreach ($opciones as $o) {
                if ($o['VIGENTE']) {
                    $hayVigentes = true;
                    break;
                }
            }

            if (!$hayVigentes) {
                $vacias[] = self::TIPOS[$tipo]['nombre'];
            }
        }

        /* UNA LISTA VACIA NO ES UN ERROR pero deja el desplegable de esa
           columna sin opciones, y entonces esa columna no se puede cargar desde
           la pantalla. Es lo mismo que pasaba con el maestro vacio. */
        if (!empty($vacias)) {
            $avisos[] = count($vacias) === 1
                ? 'La lista "' . $vacias[0] . '" no tiene ningún valor vigente, así que ese '
                    . 'campo no se va a poder elegir en el alta manual. Cargale valores acá.'
                : count($vacias) . ' listas no tienen ningún valor vigente ('
                    . implode(', ', $vacias) . '), así que esos campos no se van a poder elegir '
                    . 'en el alta manual. Cargales valores acá.';
        }

        return $avisos;
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /**
     * Las cinco listas completas, INCLUIDAS LAS BAJAS.
     *
     * Se traen todas y no solo las vigentes porque el editor tiene que poder
     * reactivar una baja, que es el mismo criterio con el que Parametros pide
     * los clientes pre-chequeados y las alicuotas historicas de
     * Cob. Electronicos.
     *
     * Quien necesita solo las que se ofrecen usa vigentes().
     *
     * VIENEN ORDENADAS POR 'ORDEN', Y ESO ORDENA LA TABLA DE PARAMETROS Y NADA
     * MAS. Los desplegables del alta manual son alfabeticos -los arma
     * vigentes(), que reordena- asi que este ORDER BY ya no decide que ve quien
     * carga un proveedor: decide en que fila de la pantalla de administracion
     * aparece cada valor, que es para lo que administracion lo acomoda.
     *
     * @return array Mapa TIPO => lista de opciones
     */
    public function listas() {
        if ($this->listas !== null) {
            return $this->listas;
        }

        $this->listas = [];

        foreach (array_keys(self::TIPOS) as $tipo) {
            $this->listas[$tipo] = [];
        }

        if (!$this->tablaCreada()) {
            return $this->listas;
        }

        $sql = "SELECT ID, TIPO, VALOR, ORDEN, VIGENTE, PLAZO_DIAS, FECHA_ALTA, FECHA_BAJA
                FROM dbo." . self::TABLA . "
                ORDER BY TIPO, VIGENTE DESC, ORDEN, VALOR";

        $stmt = sqlsrv_query($this->conectar(), $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las listas de opciones'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $tipo = trim((string) $row['TIPO']);

            // Un TIPO que el codigo no conoce no se dibuja en ningun lado: se
            // ignora en vez de romper la pantalla. El CHECK de la tabla lo
            // impide, pero el CHECK puede no existir en una base vieja.
            if (!isset($this->listas[$tipo])) {
                continue;
            }

            $this->listas[$tipo][] = [
                'ID' => intval($row['ID']),
                'TIPO' => $tipo,
                'VALOR' => trim((string) $row['VALOR']),
                'ORDEN' => intval($row['ORDEN']),
                'VIGENTE' => (intval($row['VIGENTE']) === 1),
                'PLAZO_DIAS' => ($row['PLAZO_DIAS'] === null) ? null : intval($row['PLAZO_DIAS']),
                'FECHA_ALTA' => $this->fechaHora($row['FECHA_ALTA']),
                'FECHA_BAJA' => $this->fechaHora($row['FECHA_BAJA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $this->listas;
    }

    /**
     * Solo lo que se OFRECE hoy, listo para validar y para poblar un select,
     * EN ORDEN ALFABETICO.
     *
     * ES LO QUE SE LE PASA AL VALIDADOR de la importacion, que es estatico y
     * puro: la consulta se hace una vez acá y el diff no toca la base.
     *
     * POR QUE ALFABETICO Y NO POR 'ORDEN'
     * -----------------------------------
     * Estas listas se ofrecen en un desplegable con buscador, y las de rubro y
     * centro de costos son largas. En una lista larga el unico orden que
     * permite BUSCAR con la vista es el alfabetico: con cualquier otro hay que
     * recorrerla entera para saber si un valor esta o no esta, y el buscador
     * tampoco ayuda a quien no sabe si lo que busca existe.
     *
     * LA COLUMNA 'ORDEN' SIGUE EXISTIENDO Y YA NO DECIDE ESTO. La usa el alta
     * de opciones -el valor nuevo va al final- y sigue ordenando la tabla de
     * Parametros, que es donde se administra. Lo que dejo de decidir es el
     * orden de los desplegables del alta manual.
     *
     * SE ORDENA ACA Y NO SOLO EN EL FRONT, a proposito: un backend que mande un
     * orden que la pantalla ignora hace creer al que lee el SQL que ese orden
     * significa algo.
     *
     * @return array Mapa TIPO => [VALOR => ['valor', 'plazo_dias']], alfabetico
     */
    public function vigentes() {
        $salida = [];

        foreach ($this->listas() as $tipo => $opciones) {
            $vigentes = [];

            foreach ($opciones as $o) {
                if ($o['VIGENTE']) {
                    $vigentes[] = $o;
                }
            }

            $salida[$tipo] = self::alfabetico($vigentes);
        }

        return $salida;
    }

    /**
     * Ordena alfabeticamente una lista de opciones y la devuelve indexada por
     * valor, que es la forma que espera buscarEnLista().
     *
     * Estatica y pura: es la regla del orden, y se prueba sin base.
     *
     * @param array $opciones Filas como las devuelve listas()
     * @return array Mapa VALOR => ['valor', 'plazo_dias']
     */
    public static function alfabetico($opciones) {
        $filas = array_values(is_array($opciones) ? $opciones : []);

        usort($filas, function ($a, $b) {
            $x = self::claveOrden($a['VALOR']);
            $y = self::claveOrden($b['VALOR']);

            /* A igual clave desempata el texto original, para que el orden sea
               ESTABLE: 'Fabrica' y 'Fábrica' comparan igual, y sin desempate
               quedarian en el orden en que los devolvio la base, que puede
               cambiar entre dos pedidos y mover una opcion de lugar sin que
               nadie haya tocado nada. */
            return ($x === $y) ? strcmp($a['VALOR'], $b['VALOR']) : strcmp($x, $y);
        });

        $salida = [];

        foreach ($filas as $o) {
            $salida[$o['VALOR']] = [
                'valor' => $o['VALOR'],
                'plazo_dias' => $o['PLAZO_DIAS']
            ];
        }

        return $salida;
    }

    /**
     * La clave con la que se compara un valor para ordenarlo: en mayusculas y
     * sin acentos.
     *
     * NO ES normalizarTitulo(), que ademas saca espacios y simbolos. Para
     * ordenar eso importa: '100% LOCALES' y '100 LOCALES' tienen que poder
     * quedar en lugares distintos. Lo unico que hay que neutralizar para que el
     * orden sea el que espera quien lee en castellano son los acentos y la
     * caja: con un strcmp pelado, 'Ñandu' y 'Óptica' se van al final de la
     * lista por su codigo de caracter, que es justo donde nadie los busca.
     *
     * @param string $valor
     * @return string
     */
    private static function claveOrden($valor) {
        $v = mb_strtoupper(trim((string) $valor), 'UTF-8');

        return str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $v
        );
    }

    /* ====================================================================
       HELPERS PUROS
       La regla de si un valor pertenece a una lista. Se prueba sin base.
       ==================================================================== */

    /**
     * Busca un valor en una lista, ignorando mayusculas, acentos y espacios.
     *
     * LA COMPARACION ES TOLERANTE Y EL RESULTADO ES EL VALOR CANONICO. Es el
     * mismo criterio -y el mismo normalizador- que Planilla::normalizarContra()
     * usa para las formas de pago: 'alquileres' y 'ALQUILERES ' son el mismo
     * rubro, y quedarse con el de la lista es lo que evita que la planilla
     * siembre variantes.
     *
     * PERO NO CORRIGE EL DATO GUARDADO. Devolver el canonico sirve para DECIDIR
     * -si esta o no esta- y para poder mostrar contra que matcheo; lo que se
     * guarda sigue siendo lo que vino. Ver normalizarFila().
     *
     * Estatica y pura.
     *
     * @param string|null $valor
     * @param array $lista Mapa VALOR => ['valor', 'plazo_dias'], de vigentes()
     * @return array|null La entrada de la lista, o null si no esta
     */
    public static function buscarEnLista($valor, $lista) {
        $v = trim((string) $valor);

        if ($v === '' || !is_array($lista)) {
            return null;
        }

        if (isset($lista[$v])) {
            return $lista[$v];
        }

        $clave = Planilla::normalizarTitulo($v);

        foreach ($lista as $opcion => $datos) {
            if (Planilla::normalizarTitulo($opcion) === $clave) {
                return $datos;
            }
        }

        return null;
    }

    /**
     * Valida un valor que alguien quiere agregar a una lista.
     *
     * @param string $tipo
     * @param string $valor
     * @return string El valor normalizado en espacios
     */
    public static function validarValor($tipo, $valor) {
        if (!isset(self::TIPOS[$tipo])) {
            throw new Exception('La lista "' . $tipo . '" no existe. Las listas son: '
                . implode(', ', array_keys(self::TIPOS)) . '.');
        }

        /* Se recortan los espacios de los extremos y nada mas: el valor se
           guarda como lo escribieron. Lo que NO puede pasar es que entren
           "Alquileres" y "Alquileres " como dos opciones distintas, que es
           justamente el problema que estas listas vienen a resolver. */
        $v = trim((string) $valor);

        if ($v === '') {
            throw new Exception('El valor no puede estar vacío.');
        }

        if (Planilla::largo($v) > 60) {
            throw new Exception('El valor no puede tener más de 60 caracteres: es el largo de '
                . 'la columna del maestro, y uno más largo se guardaría cortado.');
        }

        return $v;
    }

    /**
     * Valida los dias de un plazo.
     *
     * VACIO ES null Y NO CERO, y es la distincion entera de este campo: null es
     * "este plazo no dice cuando" -DEBITO- y cero es "se paga el dia de la
     * factura" -CONTADO-. El primero hace caer la jerarquia de fecha al escalon
     * siguiente y el segundo calcula una fecha.
     *
     * Estatica y pura.
     *
     * @param mixed $dias
     * @return int|null
     */
    public static function validarDias($dias) {
        if ($dias === null || $dias === '' || $dias === false) {
            return null;
        }

        if (!is_numeric($dias)) {
            throw new Exception('Los días del plazo tienen que ser un número entero. Dejalo '
                . 'vacío si el plazo no dice cuándo se paga, como DÉBITO: vacío y cero no son '
                . 'lo mismo. Cero significa que se paga el día de la factura.');
        }

        $n = intval($dias);

        if ($n < 0) {
            throw new Exception('Los días del plazo no pueden ser negativos: correrían la fecha '
                . 'de pago hacia atrás del comprobante.');
        }

        if ($n > 365) {
            throw new Exception('Los días del plazo no pueden superar 365.');
        }

        return $n;
    }

    /* ====================================================================
       ABM
       ==================================================================== */

    /**
     * Agrega un valor a una lista, o REACTIVA el que estaba de baja.
     *
     * REACTIVAR Y NO INSERTAR UN DUPLICADO. El indice unico es por (TIPO,
     * VALOR) e incluye las bajas justamente para esto: dos filas del mismo
     * valor -una vigente y otra no- son indistinguibles en la pantalla y hacen
     * que la baja parezca no haber funcionado.
     *
     * @param string $tipo
     * @param string $valor
     * @param mixed $plazoDias Solo para TIPO = PLAZO
     * @return array ['id', 'valor', 'reactivado']
     */
    public function agregar($tipo, $valor, $plazoDias = null) {
        $this->exigirTabla();

        $v = self::validarValor($tipo, $valor);
        $dias = ($tipo === self::TIPO_PLAZO) ? self::validarDias($plazoDias) : null;
        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT ID, VIGENTE FROM dbo." . self::TABLA . " WHERE TIPO = ? AND VALOR = ?",
            [$tipo, $v]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar la opción'));
        }

        $existe = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if ($existe) {
            if (intval($existe['VIGENTE']) === 1) {
                throw new Exception('"' . $v . '" ya está en la lista.');
            }

            $up = sqlsrv_query($cid,
                "UPDATE dbo." . self::TABLA . "
                 SET VIGENTE = 1, FECHA_BAJA = NULL, FECHA_ALTA = GETDATE()
                     " . ($tipo === self::TIPO_PLAZO ? ', PLAZO_DIAS = ?' : '') . "
                 WHERE ID = ?",
                ($tipo === self::TIPO_PLAZO)
                    ? [$dias, intval($existe['ID'])]
                    : [intval($existe['ID'])]);

            if ($up === false) {
                throw new Exception($this->errorSql('Error al reactivar la opción'));
            }

            sqlsrv_free_stmt($up);
            $this->listas = null;

            return ['id' => intval($existe['ID']), 'valor' => $v, 'reactivado' => true];
        }

        /* Va al FINAL de la lista. Lo nuevo no se cuela arriba de lo que el
           usuario ya ordeno: si tiene que ir arriba, lo sube él. */
        $ins = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA . " (TIPO, VALOR, ORDEN, VIGENTE, PLAZO_DIAS)
             VALUES (?, ?,
                     (SELECT ISNULL(MAX(ORDEN), 0) + 1 FROM dbo." . self::TABLA . " WHERE TIPO = ?),
                     1, ?)",
            [$tipo, $v, $tipo, $dias]);

        if ($ins === false) {
            throw new Exception($this->errorSql('Error al agregar la opción'));
        }

        sqlsrv_free_stmt($ins);
        $this->listas = null;

        return ['id' => null, 'valor' => $v, 'reactivado' => false];
    }

    /**
     * Renombra un valor, cambia su orden o sus dias.
     *
     * RENOMBRAR NO TOCA LOS PROVEEDORES QUE YA LO TIENEN, y hay que saberlo:
     * el maestro guarda el TEXTO, no un id. Si se renombra "Alquileres" a
     * "Alquiler", los proveedores cargados siguen diciendo "Alquileres" y
     * quedan marcados como fuera de lista hasta que alguien los edite.
     *
     * NO SE PROPAGA A PROPOSITO. Propagar seria un UPDATE masivo sobre el
     * maestro que cambiaria de rubro -y por lo tanto de fila del tablero- a
     * cientos de proveedores desde una pantalla de configuracion, sin
     * previsualizacion y sin historial. En este modulo, un cambio masivo sobre
     * el maestro es una importacion, y las importaciones muestran el diff antes
     * de confirmar. La pantalla avisa cuantos proveedores quedarian afuera.
     *
     * @param int $id
     * @param array $datos ['valor', 'orden', 'plazo_dias']
     * @return array
     */
    public function guardar($id, $datos) {
        $this->exigirTabla();

        $id = intval($id);
        $actual = $this->porId($id);

        if ($actual === null) {
            throw new Exception('No existe la opción que se quiere editar.');
        }

        $sets = [];
        $params = [];

        if (array_key_exists('valor', $datos)) {
            $sets[] = 'VALOR = ?';
            $params[] = self::validarValor($actual['TIPO'], $datos['valor']);
        }

        if (array_key_exists('orden', $datos)) {
            $sets[] = 'ORDEN = ?';
            $params[] = max(0, intval($datos['orden']));
        }

        /* LOS DIAS SOLO EXISTEN PARA PLAZO. Aceptarlos en otra lista dejaria un
           dato que ninguna pantalla muestra y que nadie puede explicar despues. */
        if (array_key_exists('plazo_dias', $datos) && $actual['TIPO'] === self::TIPO_PLAZO) {
            $sets[] = 'PLAZO_DIAS = ?';
            $params[] = self::validarDias($datos['plazo_dias']);
        }

        if (empty($sets)) {
            return ['id' => $id, 'cambios' => 0];
        }

        $params[] = $id;

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . " SET " . implode(', ', $sets) . " WHERE ID = ?",
            $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la opción'));
        }

        sqlsrv_free_stmt($stmt);
        $this->listas = null;

        return ['id' => $id, 'cambios' => count($sets)];
    }

    /**
     * Da de baja un valor, o lo reactiva.
     *
     * BAJA LOGICA: deja de ofrecerse en el formulario pero NO desaparece de los
     * proveedores que ya lo tienen. Borrar la fila dejaria proveedores
     * apuntando a un valor que ya no se puede explicar, y en el caso de
     * RUBRO_ECONOMICO cambiaria la serie del tablero de esos proveedores.
     *
     * @param int $id
     * @param bool $vigente
     * @return array
     */
    public function baja($id, $vigente = false) {
        $this->exigirTabla();

        $id = intval($id);
        $actual = $this->porId($id);

        if ($actual === null) {
            throw new Exception('No existe la opción que se quiere dar de baja.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET VIGENTE = ?,
                 FECHA_BAJA = " . ($vigente ? 'NULL' : 'GETDATE()') . "
             WHERE ID = ?",
            [$vigente ? 1 : 0, $id]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al dar de baja la opción'));
        }

        sqlsrv_free_stmt($stmt);
        $this->listas = null;

        return ['id' => $id, 'valor' => $actual['VALOR'], 'vigente' => (bool) $vigente];
    }

    /**
     * Cuantos proveedores VIGENTES del maestro usan cada valor de una lista.
     *
     * ES LO QUE LE DA SENTIDO A LA PANTALLA DE ADMINISTRACION. Sin esto, dar de
     * baja un valor es a ciegas: no hay forma de saber si saca una opcion que
     * no usa nadie o una que tienen doscientos proveedores, que van a quedar
     * todos marcados como fuera de lista.
     *
     * Se calcula sobre el mapa que le pasen y no consultando de nuevo: el
     * llamador ya tiene el maestro cargado y volver a la base seria la misma
     * consulta dos veces. Es el mismo criterio de faltantesEnMaestro().
     *
     * Estatica y pura.
     *
     * @param array $mapaMaestro Lo que devuelve ProveedoresCategorias::mapa()
     * @return array Mapa TIPO => [VALOR => cuantos]
     */
    public static function usos($mapaMaestro) {
        $usos = [];

        foreach (self::TIPOS as $tipo => $def) {
            $usos[$tipo] = [];
        }

        foreach (is_array($mapaMaestro) ? $mapaMaestro : [] as $m) {
            foreach (self::TIPOS as $tipo => $def) {
                $col = $def['columna'];
                $v = isset($m[$col]) ? trim((string) $m[$col]) : '';

                if ($v === '') {
                    continue;
                }

                $usos[$tipo][$v] = isset($usos[$tipo][$v]) ? $usos[$tipo][$v] + 1 : 1;
            }
        }

        foreach ($usos as $tipo => $vs) {
            arsort($usos[$tipo]);
        }

        return $usos;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Una opcion por su id, o null */
    private function porId($id) {
        foreach ($this->listas() as $opciones) {
            foreach ($opciones as $o) {
                if ($o['ID'] === intval($id)) {
                    return $o;
                }
            }
        }

        return null;
    }

    /** Lanza con el nombre del script si la tabla no existe */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existen las listas de opciones. '
                . 'Corré sql/cashflow_prov_locales_opciones.sql contra la base central.');
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
