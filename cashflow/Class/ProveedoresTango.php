<?php

require_once __DIR__ . '/Planilla.php';

/**
 * ProveedoresTango
 * El maestro de proveedores DE TANGO: CPA01. Solo lectura.
 *
 * POR QUE EXISTE, Y POR QUE NO VIVE DENTRO DE ProveedoresCategorias
 * -----------------------------------------------------------------
 * Son dos maestros distintos y hay que poder distinguirlos.
 *
 *   CPA01                                 quien EXISTE como proveedor
 *   RO_T_CASHFLOW_PROV_LOCALES_CATEG      que ES cada proveedor para nosotros
 *
 * El primero lo mantiene Tango y es el universo: si un codigo no esta ahi, no
 * existe y no va a cruzar contra ninguna cuenta a pagar. El segundo es la copia
 * de la planilla de administracion, con el rubro, el centro de costos y la
 * forma de pago. Meter la lectura de CPA01 adentro de la clase del maestro
 * propio haria parecer que son el mismo maestro, que es justo la confusion que
 * el encabezado de ProveedoresCategorias trabaja para evitar.
 *
 * QUE RESUELVE
 * ------------
 * Que un codigo mal tipeado no entre al maestro. Antes se podia cargar
 * cualquier cosa: el codigo se validaba solo por largo -hasta 6 caracteres- asi
 * que 'MTDOD' entraba igual que 'MTDODI', y el sintoma llegaba mucho despues,
 * como un proveedor cargado que nunca clasifica nada porque no cruza contra
 * ninguna deuda. Es el mismo problema -y la misma solucion- que
 * Echeqs::buscarCliente() resuelve contra GVA14 para el pre-chequeado.
 *
 * NO SE FILTRA POR EMPRESA NI POR ESTADO DE BAJA. COD_PROVEE es unico en CPA01:
 * si existe, vale. Un proveedor dado de baja en Tango puede seguir teniendo
 * deuda pendiente, asi que excluirlo haria imposible clasificar esa deuda.
 *
 * EL NOMBRE SALE DE ACA Y NO DEL NAVEGADOR
 * ----------------------------------------
 * En la carga manual, NOM_PROVEE se trae de CPA01 y es de solo lectura. Es el
 * mismo criterio que usa Echeqs::guardarClientePrechequeado() con la razon
 * social: si el nombre se pudiera tipear, dos pantallas mostrarian dos nombres
 * para el mismo codigo y ninguna de las dos seria "el nombre del proveedor".
 *
 * La IMPORTACION es distinta y a proposito: ahi el nombre lo sigue trayendo la
 * planilla. La planilla es la fuente del maestro -esa decision no cambia- y el
 * nombre que administracion escribio es parte de lo que se esta importando. Lo
 * que la importacion si hace es VALIDAR que el codigo exista.
 *
 * LA COLLATION IMPORTA, Y EL LARGO SE CUENTA EN CARACTERES
 * --------------------------------------------------------
 * CPA01.COD_PROVEE es VARCHAR(6) COLLATE Latin1_General_BIN, y hay 27 codigos
 * con caracteres no ASCII. Dos consecuencias:
 *
 *   - La comparacion es BINARIA: 'OGNUNE' y 'OGNUÑE' son dos proveedores
 *     distintos. Por eso RO_T_CASHFLOW_PROV_LOCALES_CATEG.COD_PROVEE declara la
 *     misma collation (ver sql/cashflow_prov_locales_collation.sql), y por eso
 *     aca los codigos van SIEMPRE como parametro y nunca interpolados.
 *   - El largo se cuenta con Planilla::largo() -mb_strlen- y no con strlen, que
 *     cuenta bytes: 'OGNUÑE' da 7 bytes y es un codigo valido de 6 caracteres.
 *
 * NO TUMBA LA PANTALLA SI CPA01 NO RESPONDE
 * ------------------------------------------
 * disponible() contesta si se pudo leer. Sin CPA01 el maestro se sigue leyendo
 * y la importacion se sigue pudiendo previsualizar: lo que no corre es la
 * validacion, y eso se avisa en lugar de marcar en error mil doscientas filas
 * que probablemente esten bien. Marcar todo en error por un origen caido seria
 * peor que no validar.
 */
class ProveedoresTango {

    /** El maestro de proveedores de Tango, en la base central */
    const TABLA = 'CPA01';

    /** Desde cuantos caracteres tiene sentido buscar */
    const MIN_BUSQUEDA = 2;

    /** Cuantos resultados devuelve el autocomplete */
    const MAX_RESULTADOS = 20;

    /**
     * Cuantos codigos entran en un IN por consulta.
     *
     * SQL Server admite 2.100 parametros, y la planilla real tiene 1.223 filas:
     * hoy entraria en una sola. El tope esta igual porque una planilla mas
     * grande fallaria con un error del driver que no dice nada sobre lo que
     * paso, y porque 2.100 es un limite del motor y no una propiedad de este
     * modulo.
     */
    const LOTE = 1000;

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de que la tabla se puede leer */
    private $disponible = null;

    /** @var array Cache de existe(), indexado por codigo */
    private $vistos = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * Si CPA01 se puede leer.
     *
     * Se pregunta con OBJECT_ID y no intentando un SELECT: una tabla que no
     * existe y una consulta que falla por otra razon son dos cosas distintas, y
     * la pantalla tiene que poder decir cual.
     *
     * @return bool
     */
    public function disponible() {
        if ($this->disponible !== null) {
            return $this->disponible;
        }

        try {
            $cid = $this->conectar();
            $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA . "') AS T");

            if ($stmt === false) {
                $this->disponible = false;

                return false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->disponible = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->disponible = false;
        }

        return $this->disponible;
    }

    /**
     * El nombre de un proveedor de Tango, o null si el codigo no existe.
     *
     * DEVOLVER null NO ES UN ERROR: es el resultado de una busqueda, y es lo
     * que el alta usa para decidir si rechaza. Mismo criterio que
     * Echeqs::buscarCliente().
     *
     * @param string $codProvee
     * @return string|null NOM_PROVEE, o null si no existe
     */
    public function existe($codProvee) {
        $cod = Planilla::codigo($codProvee);

        if ($cod === '') {
            return null;
        }

        if (array_key_exists($cod, $this->vistos)) {
            return $this->vistos[$cod];
        }

        /* El codigo va como PARAMETRO y no interpolado. Ademas de lo obvio, es
           lo unico que hace que un codigo con eñe llegue a una columna
           Latin1_General_BIN tal como se escribio. */
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT NOM_PROVEE FROM dbo." . self::TABLA . " WHERE COD_PROVEE = ?",
            [$cod]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar el proveedor en Tango'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->vistos[$cod] = $row ? trim((string) $row['NOM_PROVEE']) : null;

        return $this->vistos[$cod];
    }

    /**
     * Cuales de una lista de codigos existen en CPA01, EN UNA SOLA CONSULTA.
     *
     * ES UNA CONSULTA Y NO UNA POR FILA, y es la diferencia entre una
     * previsualizacion que tarda medio segundo y una que hace 1.223 viajes a la
     * base. La importacion del maestro es el unico llamador que importa y
     * necesita justamente eso.
     *
     * DEVUELVE UN MAPA CODIGO => NOMBRE y no una lista de codigos: quien
     * valida quiere saber si existe, pero quien muestra la previsualizacion
     * quiere poder decir "ese codigo no existe" al lado del nombre que la
     * planilla traia, y el nombre de Tango es con lo que se compara.
     *
     * @param array $codigos Codigos a buscar
     * @return array Mapa COD_PROVEE => NOM_PROVEE, solo con los que existen
     */
    public function existentes($codigos) {
        $unicos = [];

        foreach (is_array($codigos) ? $codigos : [] as $c) {
            $cod = Planilla::codigo($c);

            if ($cod !== '') {
                $unicos[$cod] = true;
            }
        }

        if (empty($unicos)) {
            return [];
        }

        $cid = $this->conectar();
        $encontrados = [];

        foreach (array_chunk(array_keys($unicos), self::LOTE) as $lote) {
            /* Los signos de pregunta salen de contar el lote, no de los datos:
               lo que se intercala en el SQL es la cantidad y nada mas. Los
               codigos viajan como parametros. */
            $marcas = implode(',', array_fill(0, count($lote), '?'));

            $stmt = sqlsrv_query($cid,
                "SELECT COD_PROVEE, NOM_PROVEE FROM dbo." . self::TABLA . "
                 WHERE COD_PROVEE IN (" . $marcas . ")",
                $lote);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al validar los códigos contra Tango'));
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $cod = Planilla::codigo($row['COD_PROVEE']);
                $encontrados[$cod] = trim((string) $row['NOM_PROVEE']);
                $this->vistos[$cod] = $encontrados[$cod];
            }

            sqlsrv_free_stmt($stmt);
        }

        return $encontrados;
    }

    /**
     * Busca proveedores por codigo O por nombre, para el autocomplete del alta.
     *
     * BUSCA POR LAS DOS COSAS porque quien carga un proveedor casi nunca se
     * acuerda del codigo: se acuerda del nombre. Un buscador que solo acepte el
     * codigo obliga a ir a Tango a buscarlo, que es exactamente el paso que
     * esto viene a sacar.
     *
     * DESDE DOS CARACTERES. Con uno, la consulta devuelve cientos de filas que
     * no acotan nada y el usuario igual tiene que seguir tipeando.
     *
     * ORDENA PONIENDO PRIMERO LOS QUE EMPIEZAN CON LO TIPEADO. Con 4.893
     * proveedores y un tope de 20 resultados, el orden decide que se ve: quien
     * escribe 'IRSA' espera 'IRSA INVERSIONES' antes que un nombre que contiene
     * 'irsa' en el medio.
     *
     * @param string $texto Lo que tipeo el usuario
     * @param int $max Tope de resultados
     * @return array Filas ['COD_PROVEE', 'NOM_PROVEE']
     */
    public function buscar($texto, $max = self::MAX_RESULTADOS) {
        $t = trim((string) $texto);

        if (Planilla::largo($t) < self::MIN_BUSQUEDA) {
            return [];
        }

        $max = max(1, min(intval($max), 100));

        /* Los comodines se escapan: un '%' o un '_' tipeados son texto que el
           usuario quiso buscar, no operadores. Sin esto, tipear '%' lista el
           maestro entero. */
        $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $t) . '%';
        $empieza = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $t) . '%';

        /* TOP va interpolado y los demas valores van como parametros: $max es
           un entero ya acotado por intval() y min(), no entrada del usuario.
           Es el mismo criterio que Cotizacion::ultimaHasta() con el nombre de
           la columna.

           La comparacion por codigo lleva COLLATE explicito: la columna es
           Latin1_General_BIN -binaria y case sensitive- y un LIKE contra ella
           no encontraria 'mtdodi' en minuscula, que es como se tipea. El
           nombre no lo necesita: NOM_PROVEE usa la collation de la base, que ya
           es acento e mayuscula insensible. */
        $sql = "SELECT TOP " . $max . " COD_PROVEE, NOM_PROVEE
                FROM dbo." . self::TABLA . "
                WHERE COD_PROVEE COLLATE Modern_Spanish_CI_AI LIKE ?
                   OR NOM_PROVEE LIKE ?
                ORDER BY
                    CASE WHEN COD_PROVEE COLLATE Modern_Spanish_CI_AI LIKE ? THEN 0
                         WHEN NOM_PROVEE LIKE ? THEN 1
                         ELSE 2 END,
                    NOM_PROVEE";

        $stmt = sqlsrv_query($this->conectar(), $sql, [$like, $like, $empieza, $empieza]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al buscar proveedores en Tango'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'NOM_PROVEE' => trim((string) $row['NOM_PROVEE'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Cuales de una lista de codigos NO existen en CPA01.
     *
     * ES EL CONTROL de lo ya cargado: un proveedor que esta vigente en el
     * maestro propio y no existe en Tango no va a clasificar nada nunca, y
     * nadie se entera hasta que alguien nota que una deuda aparece sin rubro.
     *
     * SOLO AVISA, no da de baja. Puede ser un codigo tipeado mal, pero tambien
     * un proveedor que Tango depuro: dar de baja automaticamente borraria la
     * clasificacion de una deuda que todavia existe. Quien decide es una
     * persona, igual que con directoresNoExcluidos().
     *
     * Estatica la parte que compara, para poder probarla sin base: aca solo va
     * la consulta.
     *
     * @param array $codigos
     * @return array Codigos que no estan en CPA01
     */
    public function faltantes($codigos) {
        $existen = $this->existentes($codigos);
        $faltan = [];

        foreach (is_array($codigos) ? $codigos : [] as $c) {
            $cod = Planilla::codigo($c);

            if ($cod !== '' && !isset($existen[$cod])) {
                $faltan[$cod] = true;
            }
        }

        return array_keys($faltan);
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
