<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/ProveedoresTango.php';
require_once __DIR__ . '/ProveedoresOpciones.php';

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
 * PERO EL CODIGO SI SE VALIDA CONTRA CPA01
 * ----------------------------------------
 * Que el CONTENIDO salga de la planilla no significa que el CODIGO pueda ser
 * cualquiera. CPA01 es el universo de proveedores que existen, y un codigo que
 * no esta ahi no va a cruzar contra ninguna cuenta a pagar: el proveedor se
 * carga, clasifica nada, y el sintoma aparece semanas despues como una deuda
 * sin rubro que nadie sabe por que no clasifica.
 *
 * Antes el codigo se validaba solo por LARGO, asi que 'MTDOD' entraba igual que
 * 'MTDODI'. Ahora:
 *
 *   - El ALTA MANUAL se RECHAZA si el codigo no existe. Es la tabla maestra de
 *     proveedores: no hay alta con advertencia. Y el NOMBRE se trae de CPA01 y
 *     no se puede editar, por el mismo motivo por el que la razon social del
 *     pre-chequeado sale de GVA14: dos pantallas mostrando dos nombres para el
 *     mismo codigo no tienen forma de decir cual es el nombre del proveedor.
 *   - La IMPORTACION marca en ERROR la fila y sigue: las filas validas se
 *     importan igual. Parar la planilla entera por dos codigos malos obligaria
 *     a corregir todo antes de poder cargar las mil doscientas que estan bien,
 *     que es el mismo criterio que ya rige para el codigo repetido. Ahi el
 *     nombre lo sigue trayendo la planilla: es la fuente del maestro y el
 *     nombre que administracion escribio es parte de lo que se esta importando.
 *   - Lo YA CARGADO se audita: getAvisos() lista los vigentes cuyo codigo no
 *     existe en CPA01. SOLO AVISA. Dar de baja automaticamente borraria la
 *     clasificacion de una deuda que puede seguir existiendo.
 *
 * La lectura de CPA01 vive en ProveedoresTango, que es una clase aparte porque
 * son DOS maestros distintos y confundirlos es exactamente lo que este
 * encabezado viene evitando. Ver su docblock.
 *
 * UNA FILA MALA ES ESA FILA; UN ORIGEN CAIDO ES TODO
 * ---------------------------------------------------
 * Parece una inconsistencia y no lo es, asi que queda escrito.
 *
 * Una fila con un codigo que no existe, o con un valor que no esta en su lista,
 * queda en ERROR y NO SE CARGA; el resto de la planilla se importa igual. El
 * alcance es POR FILA porque se sabe exactamente cual esta mal: hay 1.222 filas
 * de las que no se sabe nada malo y no hay motivo para castigarlas.
 *
 * Que el ORIGEN de la validacion no se pueda leer -CPA01 caido, o la consulta
 * de las listas fallando- es otra cosa. No es "ninguna fila esta mal": es que
 * NO SE PUDO CHEQUEAR NINGUNA, asi que no existe el subconjunto de filas
 * validas que dejar pasar. Ahi la importacion se PREVISUALIZA igual -se ve que
 * cambiaria- pero NO SE PUEDE CONFIRMAR, y la pantalla dice por que. Es el
 * mismo criterio que ya usaba el alta manual, donde CPA01 caido frena el alta.
 *
 * La excepcion de la excepcion: que las listas NO EXISTAN -porque no se corrio
 * sql/cashflow_prov_locales_opciones.sql- no bloquea nada. Ahi no hay nada roto:
 * el modulo funciona como funcionaba antes de que las listas existieran, con
 * texto libre, y eso es una configuracion pendiente y no un origen caido. Ver
 * listasVigentes() y OpcionesIlegibles.
 *
 * LOS VALORES DE LAS CINCO LISTAS SON REGLA, NO SUGERENCIA
 * --------------------------------------------------------
 * Esto CAMBIO. Un valor fuera de lista se importaba igual, marcado, y el
 * argumento era que un rubro raro clasifica -crea su propia serie- mientras que
 * un codigo inexistente no clasifica nada. Cierto, pero el resultado era que la
 * serie del tablero se creaba igual: el aviso se leia despues de importar, y la
 * fila nueva del cuadro ya estaba.
 *
 * Ahora la fila queda en ERROR y el motivo NOMBRA EL CAMPO Y EL VALOR, porque
 * con cinco listas un "hay un valor invalido" no se puede accionar. Y la
 * previsualizacion junta todos los valores rechazados, agrupados por lista y sin
 * repetir, para que administracion los pueda dar de alta en Parametros de una
 * sola pasada en vez de descubrirlos de a uno reimportando.
 *
 * OJO CON LA PRIMERA IMPORTACION DESPUES DE ESTE CAMBIO: si las cinco listas de
 * Parametros no reflejan todos los valores en uso, la planilla tal como esta hoy
 * puede quedar rechazada en masa. Para eso esta la lista agrupada.
 *
 * UN CAMPO VACIO NO ES UN VALOR FUERA DE LISTA. En la planilla real hay 84 filas
 * sin rubro economico y 765 sin plazo: marcarlas en error haria que no se pueda
 * importar nada. Vacio es vacio, y ya se cuenta aparte.
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
     * Lo que se le pasa al diff cuando las listas de opciones EXISTEN pero no se
     * pudieron leer.
     *
     * Es un tercer estado, y hacen falta los tres: un array son las listas, null
     * es "todavia no se corrio el script" -no se valida nada, como antes- y esto
     * es "la consulta fallo". Los dos ultimos daban el mismo null hasta que los
     * valores fuera de lista pasaron a ser un error; ahora deciden cosas
     * opuestas, porque no poder chequear NINGUNA fila no es lo mismo que no
     * tener contra que chequearlas. Ver el encabezado de la clase.
     */
    const LISTAS_ILEGIBLES = 'LISTAS_ILEGIBLES';

    /**
     * Las formas de pago declaradas.
     *
     * SON LAS QUE TRAE LA PLANILLA, NO LAS QUE PARECEN RAZONABLES. La primera
     * version de esta lista se escribio a ojo -CHEQUE, EFECTIVO, RETENCION,
     * COMPENSACION, OTRO- y ninguno de esos cinco existe en el maestro real. Los
     * que si existen y faltaban eran CAJA, TARJETA CORP y MERCADO PAGO, que
     * juntos son 639 de los 1.173 proveedores: el 54% quedo con la forma sin
     * normalizar por haber adivinado en lugar de mirar.
     *
     * Estos seis salen de contar el maestro importado:
     *
     *     CAJA           390     TARJETA CORP   240
     *     TRANSFERENCIA  259     DEBITO          32
     *     ECHEQ          243     MERCADO PAGO     9
     *
     * La comparacion ignora mayusculas, acentos y espacios, asi que 'echeq',
     * 'Tarjeta Corp' y 'MERCADOPAGO' matchean. Un valor que NO matchea se guarda
     * igual, con el normalizado en null y su original a la vista: la
     * previsualizacion lo muestra y quien decide si es un typo o una forma nueva
     * es una persona.
     *
     * AGREGAR UNA FORMA ES AGREGAR UNA ENTRADA ACA, y nada mas: las filas ya
     * cargadas se renormalizan solas en el proximo pedido, porque el valor
     * normalizado se deriva al LEER y no se congela al importar. Ver mapa().
     *
     * Antes habia que reimportar el maestro entero, y esa es la razon por la
     * que los CAJA, TARJETA CORP y MERCADO PAGO -las tres formas que faltaban
     * en la primera version de esta lista- se quedaron 639 filas sin normalizar
     * hasta que alguien lo noto.
     */
    const FORMAS_PAGO = [
        'TRANSFERENCIA', 'ECHEQ', 'CAJA', 'TARJETA CORP', 'DEBITO', 'MERCADO PAGO'
    ];

    /**
     * Las formas de pago que se gestionan desde el cronograma de pagos.
     *
     * Es lo que se paga decidiendo CUANDO: una transferencia o un echeq se
     * emiten el dia que alguien elige. Las otras no se planifican de la misma
     * manera -un debito automatico se debita solo, la caja se paga en el
     * mostrador- asi que mezclarlas en la grilla de trabajo es ruido.
     *
     * NO ES UN FILTRO DE LA CONSULTA: los pendientes se traen TODOS y esta lista
     * solo decide que se muestra por defecto en la pestaña. Lo que queda fuera
     * del filtro sigue contandose, se informa cuanto es, y se puede ver con un
     * clic. Ver el encabezado de Tabs/proveedores_locales.php.
     */
    const FORMAS_CRONOGRAMA = ['ECHEQ', 'TRANSFERENCIA'];

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de existencia de la tabla */
    private $tabla = null;

    /** @var array|null Cache del maestro vigente, indexado por COD_PROVEE */
    private $mapa = null;

    /** @var bool|null Cache de si la tabla ya tiene la columna ORIGEN */
    private $origen = null;

    /** @var ProveedoresTango|null El maestro de Tango, para validar los codigos */
    private $tango = null;

    /** @var ProveedoresOpciones|null Las listas de valores validos del maestro */
    private $opciones = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /**
     * El maestro de proveedores de Tango, con su cache.
     *
     * Se expone para que el controlador pueda pedirle la validacion de una
     * planilla entera en UNA consulta, igual que expone mapa() para el diff.
     *
     * @return ProveedoresTango
     */
    public function tango() {
        if ($this->tango === null) {
            $this->tango = new ProveedoresTango();
        }

        return $this->tango;
    }

    /**
     * Las cinco listas de valores validos del maestro, con su cache.
     *
     * Igual que tango(): se expone para que el controlador pueda pedir las
     * listas UNA vez y pasarlas al diff, que es estatico y no toca la base.
     *
     * @return ProveedoresOpciones
     */
    public function opciones() {
        if ($this->opciones === null) {
            $this->opciones = new ProveedoresOpciones();
        }

        return $this->opciones;
    }

    /**
     * Las listas vigentes, o null si todavia no existe la tabla.
     *
     * DEVUELVE null Y NO UN MAPA VACIO cuando el script no se corrio, y la
     * diferencia es la misma que con CPA01: null es "no hay listas contra las
     * cuales validar" -y entonces no se marca nada, que es el comportamiento de
     * antes- mientras que un mapa vacio significaria "ninguna lista tiene
     * valores" y dejaria el maestro entero en error.
     *
     * Y SI LA CONSULTA FALLA, LANZA. Antes devolvia null tambien, porque un
     * catch (Throwable) se comia la diferencia entre "no existe la tabla" y "no
     * se pudo leer". Mientras un valor fuera de lista era advertencia daba
     * igual: en los dos casos no se marcaba nada. Desde que es ERROR ya no,
     * porque "no se pudo leer" significa que NINGUNA fila se chequeo y no hay
     * un subconjunto de validas que dejar pasar. Ver OpcionesIlegibles.
     *
     * @return array|null Las listas, o null si todavia no existe la tabla
     * @throws OpcionesIlegibles Si la tabla esta y la consulta falla
     */
    public function listasVigentes() {
        try {
            return $this->opciones()->tablaCreada() ? $this->opciones()->vigentes() : null;
        } catch (OpcionesIlegibles $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OpcionesIlegibles('No se pudieron leer las listas de valores de '
                . 'Parámetros → Prov. Locales, así que no hay contra qué validar el rubro, el '
                . 'centro de costos ni el plazo. Probá de nuevo en un rato. (' . $e->getMessage()
                . ')', 0, $e);
        }
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
     * Si la tabla ya tiene la columna ORIGEN de
     * sql/cashflow_prov_locales_maestro_manual.sql.
     *
     * SE PREGUNTA en vez de darla por hecha porque el maestro se sigue pudiendo
     * LEER sin ella: una instalacion que todavia no corrio ese script tiene que
     * ver su pestana, no un error de SQL. Lo que no puede es cargar a mano, y
     * eso lo dice guardarManual() con su propio mensaje.
     *
     * @return bool
     */
    public function tieneOrigen() {
        if ($this->origen !== null) {
            return $this->origen;
        }

        if (!$this->tablaCreada()) {
            return false;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COL_LENGTH('dbo." . self::TABLA . "', 'ORIGEN') AS C");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el origen del maestro'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->origen = ($row && $row['C'] !== null);

        return $this->origen;
    }

    /**
     * Como se pide el origen en un SELECT: la columna si esta, y el literal
     * 'IMPORT' si todavia no.
     *
     * Devolver el literal y no null es lo que hace que quien consume no tenga
     * que preguntar: sin la columna, todo lo que hay entro por la planilla, asi
     * que 'IMPORT' no es un relleno, es el dato cierto.
     *
     * @return string
     */
    private function origenSql() {
        return $this->tieneOrigen() ? 'ORIGEN' : "'IMPORT'";
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

        $avisos = [];

        if (empty($this->mapa())) {
            $avisos[] = 'El maestro de proveedores está vacío: importá la hoja '
                . '"Maestro proveedores" del Excel Cronograma de Pagos. Mientras tanto, '
                . 'todos los comprobantes se muestran sin clasificar y ninguno queda excluido.';
        }

        /* LO QUE FALTA SE DICE, AUNQUE NO ROMPA NADA. Sin la columna ORIGEN el
           maestro se lee igual y lo único que no se puede es cargarlo a mano,
           así que la pantalla esconde el botón de agregar. Una función que
           desaparece sin decir por qué es indistinguible de una que no se
           construyó: quien la fue a buscar no tiene dónde enterarse de que
           existe y de que falta un script. */
        if (!$this->tieneOrigen()) {
            $avisos[] = 'La carga manual de proveedores está apagada: falta la columna ORIGEN '
                . 'en el maestro. Corré sql/cashflow_prov_locales_maestro_manual.sql contra la '
                . 'base central y el botón de agregar aparece solo. Todo lo demás de esta '
                . 'pantalla funciona igual; lo único que no se puede es cargar o editar un '
                . 'proveedor de a uno.';
        }

        if (!$this->tango()->disponible()) {
            $avisos[] = 'No se pudo leer CPA01, el maestro de proveedores de Tango, así que los '
                . 'códigos no se están validando: un código mal tipeado se puede cargar y '
                . 'después no va a clasificar ninguna deuda. Todo lo demás funciona igual.';
        }

        return $avisos;
    }

    /**
     * Los proveedores VIGENTES del maestro cuyo codigo no existe en CPA01.
     *
     * ES EL SEGUNDO CONTROL DEL MODULO, y mira al reves que faltantesEnMaestro():
     * aquel busca deuda sin clasificar, este busca clasificacion sin proveedor.
     *
     * Un codigo que no esta en Tango no va a cruzar contra ninguna cuenta a
     * pagar NUNCA. Puede ser un codigo tipeado mal antes de que hubiera
     * validacion, o un proveedor que Tango depuro. Los dos casos se ven igual
     * desde acá y los dos hay que mirarlos.
     *
     * SOLO AVISA: NO DA DE BAJA NADA. Es el mismo criterio que
     * directoresNoExcluidos(). Una baja automatica borraria la clasificacion de
     * una deuda que puede seguir existiendo, y lo haria sin que nadie lo
     * decida.
     *
     * SI CPA01 NO SE PUEDE LEER devuelve vacio y no rompe: es un control, no un
     * requisito, y la pantalla ya avisa aparte que la validacion no corrio.
     *
     * @return array Filas ['COD_PROVEE', 'NOMBRE', 'RUBRO_ECONOMICO', 'ORIGEN']
     */
    public function noEnTango() {
        $mapa = $this->mapa();

        if (empty($mapa) || !$this->tango()->disponible()) {
            return [];
        }

        try {
            $faltan = $this->tango()->faltantes(array_keys($mapa));
        } catch (Throwable $e) {
            return [];
        }

        $v = [];

        foreach ($faltan as $cod) {
            $v[] = [
                'COD_PROVEE' => $cod,
                'NOMBRE' => $mapa[$cod]['NOMBRE'],
                'RUBRO_ECONOMICO' => $mapa[$cod]['RUBRO_ECONOMICO'],

                /* De donde salio la version vigente. Un codigo inexistente
                   cargado A MANO es un typo de alguien; uno que trajo la
                   planilla hay que corregirlo en la planilla, o no vuelve. */
                'ORIGEN' => $mapa[$cod]['ORIGEN']
            ];
        }

        return $v;
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
     * LA FORMA DE PAGO SE NORMALIZA ACA, AL LEER, Y NO AL IMPORTAR
     * ------------------------------------------------------------
     * FORMA_PAGO es un valor DERIVADO: sale de pasar FORMA_PAGO_ORIG -lo que
     * decia la celda- por la lista FORMAS_PAGO, que vive en el codigo.
     * Calcularlo al importar lo congelaba contra la lista de ese dia, asi que
     * agregar una forma nueva no arreglaba ninguna de las filas ya cargadas:
     * habia que reimportar el maestro entero.
     *
     * Y REIMPORTAR NO ES RECALCULAR. Hace el diff completo: necesita tener a
     * mano el Excel vigente -si no es el mismo que se importo, aplica de paso
     * cambios que nadie pidio-, propone bajas, y cada CAMBIO escribe una baja
     * mas un alta en el historial. Obligaba a correr una operacion de DATOS,
     * con efectos colaterales, para arreglar la consecuencia de un cambio de
     * CODIGO. Eso es lo que estaba mal, no el trabajo de reimportar.
     *
     * Derivarlo al leer cuesta 7 ms por pedido sobre las 1.173 filas del
     * maestro -y este mapa se cachea, asi que es una vez- y hace que agregar
     * una forma a FORMAS_PAGO tenga efecto en el proximo pedido.
     *
     * LA COLUMNA SE SIGUE ESCRIBIENDO IGUAL, y no es redundancia: guarda QUE
     * DECIDIO EL SISTEMA en esa importacion, que es lo que getHistorial()
     * muestra. Lo que cambio es por donde se LEE.
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
                       CRITERIO_DISTRIB, FECHA_IMPORTACION, "
                       . self::origenSql() . " AS ORIGEN
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
                'FORMA_PAGO' => self::formaVigente($row['FORMA_PAGO_ORIG'],
                                                   $row['FORMA_PAGO']),
                'FORMA_PAGO_ORIG' => $row['FORMA_PAGO_ORIG'],

                /* Lo que el sistema decidio cuando se importo esta fila. No se
                   usa para clasificar -para eso esta FORMA_PAGO, recalculada-
                   pero es lo que explica por que el tablero decia otra cosa
                   antes de agregar una forma a la lista. */
                'FORMA_PAGO_IMPORTADA' => $row['FORMA_PAGO'],
                'PLAZO_PAGO' => $row['PLAZO_PAGO'],
                'PLAZO_DIAS' => ($row['PLAZO_DIAS'] === null) ? null : intval($row['PLAZO_DIAS']),
                'CRITERIO_DISTRIB' => $row['CRITERIO_DISTRIB'],
                'FECHA_IMPORTACION' => $this->fechaHora($row['FECHA_IMPORTACION']),

                /* De donde salio esta version. Lo usa el diff para avisar antes
                   de pisar trabajo manual, y la grilla para marcarlo. */
                'ORIGEN' => $row['ORIGEN']
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
                'forma_pago_orig' => null,
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

            /* EL ORIGINAL VIAJA CON EL NORMALIZADO, igual que en la
               importacion y por el mismo motivo: cuando el normalizado es null,
               el original es lo unico que se puede mostrar. Sin esto la grilla
               dibuja "sin forma" para un proveedor cuyo maestro dice
               TARJETA CORP, y la marca naranja -que existe para exactamente
               este caso- no se ejecuta nunca. */
            'forma_pago_orig' => $m['FORMA_PAGO_ORIG'],
            'plazo_dias' => $m['PLAZO_DIAS'],
            'excluido' => self::esExcluido($m['RUBRO_ECONOMICO']),
            'serie' => self::serieDeRubro($m['RUBRO_ECONOMICO'])
        ];
    }

    /** Codigo de serie de los comprobantes cuyo proveedor no esta en el maestro */
    const SERIE_SIN_RUBRO = 'SIN_RUBRO';

    /**
     * Si un comprobante se gestiona desde el cronograma de pagos.
     *
     * Entra lo que se paga DECIDIENDO CUANDO: una transferencia o un echeq se
     * emiten el dia que alguien elige. Un debito automatico se debita solo y la
     * caja se paga en el mostrador, asi que no se planifican de la misma manera.
     *
     * LO QUE NO SE SABE, ENTRA Y SE MARCA. Una forma de pago en null -porque el
     * proveedor no esta en el maestro, o porque lo que trajo la planilla no se
     * reconocio- no es lo mismo que una forma que quedo afuera del criterio: es
     * un dato que falta. Esconder deuda por un dato que falta es la peor razon
     * para esconderla, y ademas garantiza que nadie lo complete nunca, porque
     * deja de verse.
     *
     * Quien entra sin forma conocida se dibuja con su marca en la grilla, asi
     * que se distingue de un echeq de verdad.
     *
     * LO QUE SE LE PASA ES LA FORMA DEL MAESTRO, no la del pago registrado: el
     * criterio es una propiedad del proveedor -a este se le paga por
     * transferencia- y no un dato de un comprobante suelto. Ver la seccion "DOS
     * FORMAS DE PAGO QUE NO SON LA MISMA COSA" del encabezado de Proveedores.
     *
     * Estatica y pura.
     *
     * @param string|null $formaPago Forma del maestro, YA normalizada
     * @return bool
     */
    public static function esDelCronograma($formaPago) {
        if ($formaPago === null || trim((string) $formaPago) === '') {
            return true;
        }

        return in_array($formaPago, self::FORMAS_CRONOGRAMA, true);
    }

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

    /**
     * La forma de pago de una fila ya guardada, contra la lista DE HOY.
     *
     * Es lo que hace que agregar una forma a FORMAS_PAGO tenga efecto sin
     * reimportar nada: el original esta guardado en todas las filas -verificado:
     * 0 de 1.173 en el maestro y 0 de 15 en pagos tienen la normalizada sin su
     * original- y la normalizacion es una funcion pura de el.
     *
     * EL SEGUNDO PARAMETRO ES UNA RED, NO UN CAMINO NORMAL. Si alguna fila
     * tuviera la normalizada sin su original -hoy no hay ninguna, pero una
     * correccion a mano sobre la base podria dejarla asi- se respeta lo que
     * este guardado en lugar de perderlo. Recalcular nunca puede BORRAR un
     * dato: solo puede reconocer uno que antes no se reconocia.
     *
     * Estatica y pura.
     *
     * @param string|null $original Lo que decia la celda de la planilla
     * @param string|null $guardada Lo que se decidio al importar
     * @return string|null
     */
    public static function formaVigente($original, $guardada) {
        if ($original === null || trim((string) $original) === '') {
            return $guardada;
        }

        return self::normalizarFormaPago($original)['normalizado'];
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
     * LA VALIDACION CONTRA CPA01 LLEGA POR PARAMETRO, YA RESUELTA
     * -----------------------------------------------------------
     * $validos es el mapa CODIGO => NOMBRE de los que existen en Tango, y lo
     * arma el llamador con UNA sola consulta para todos los codigos del archivo
     * -ProveedoresTango::existentes()-. Este metodo no toca la base, y esa es
     * la razon por la que el diff entero se puede probar sin base ni archivos.
     *
     * $validos EN null SIGNIFICA "NO SE PUDO VALIDAR", que no es lo mismo que
     * "ninguno existe". Ahi no se marca ninguna fila en error -seria informar
     * como malas mil doscientas que probablemente esten bien- pero la
     * importacion NO SE PUEDE CONFIRMAR: no es que ninguna este mal, es que no
     * se chequeo ninguna, y entonces no hay un subconjunto de filas validas que
     * dejar pasar. Se previsualiza igual, porque ver que cambiaria no hace
     * daño; lo que se bloquea es aplicar.
     *
     * LAS LISTAS DE OPCIONES SON REGLA, NO ADVERTENCIA
     * ------------------------------------------------
     * $opciones son las cinco listas vigentes, y tiene TRES estados:
     *
     *   array                 se valida. Un valor fuera de su lista deja la
     *                         fila en ERROR, con el campo y el valor en el
     *                         motivo, y el resto de la planilla se importa.
     *   null                  todavia no existe la tabla de opciones. No se
     *                         valida nada -texto libre, como antes- y no se
     *                         bloquea: es configuracion pendiente, no un origen
     *                         caido.
     *   self::LISTAS_ILEGIBLES  la tabla esta y la consulta fallo. Mismo
     *                         criterio que CPA01 en null: no se marca nada y no
     *                         se puede confirmar.
     *
     * Que esto haya dejado de ser una advertencia esta explicado en el
     * encabezado de la clase, igual que por que una fila mala es solo esa fila
     * y un origen caido es todo.
     *
     * @param array $filasArchivo Filas de Planilla::parsear()
     * @param array $existentes Maestro vigente, indexado por COD_PROVEE
     * @param array|null $validos Mapa COD_PROVEE => NOM_PROVEE de CPA01, o null
     *                            si la validacion no se pudo correr
     * @param array|string|null $opciones Listas vigentes, null si no existe la
     *                            tabla, o self::LISTAS_ILEGIBLES si no se leyo
     * @return array ['filas', 'bajas', 'resumen', 'avisos']
     */
    public static function compararImportacion($filasArchivo, $existentes, $validos = null,
                                               $opciones = null) {
        $existentes = is_array($existentes) ? $existentes : [];
        $validando = is_array($validos);

        /* Las listas ilegibles no se le pasan a normalizarFila(): ahi valen los
           dos estados de siempre -hay listas o no hay-. Lo que agrega el tercer
           estado es el bloqueo, que se resuelve acá. */
        $listasIlegibles = ($opciones === self::LISTAS_ILEGIBLES);
        $opciones = $listasIlegibles ? null : $opciones;
        $validandoListas = is_array($opciones);

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
            'forma_desconocida' => 0, 'plazo_no_usable' => 0,

            /* Cuantas filas traen un codigo que no existe en CPA01. Quedan en
               ERROR y no se importan; el resto si. Ver la nota del encabezado. */
            'no_en_tango' => 0,

            /* Si la validacion contra CPA01 llego a correr. Un cero en
               'no_en_tango' significa "ninguna fila esta mal" o "no se pudo
               chequear", y son dos cosas muy distintas. */
            'valido_contra_tango' => $validando,

            /* Cuantas filas traen ALGUN valor que no esta en su lista. YA NO SE
               IMPORTAN: cada una es un error, y el desglose por lista dice cual
               de las cinco hay que completar. */
            'fuera_de_lista' => 0,
            'fuera_de_lista_por_tipo' => [],

            /* LOS VALORES RECHAZADOS, AGRUPADOS POR LISTA Y SIN REPETIR. Es lo
               que permite darlos de alta en Parametros de UNA pasada. Sin esto,
               con la planilla real -1.223 filas- descubrirlos seria corregir
               uno, reimportar, descubrir el siguiente, y asi. Mapa
               TIPO => [valores]. */
            'valores_fuera_de_lista' => [],

            /* Si las listas llegaron a correr como validacion. Distingue el
               cero honesto del "no habia listas", igual que con CPA01. */
            'valido_contra_listas' => $validandoListas,

            /* La tabla de opciones esta y no se pudo leer. No es lo mismo que
               'valido_contra_listas' en false por no existir la tabla: eso es
               configuracion pendiente y esto es un origen caido. */
            'listas_ilegibles' => $listasIlegibles,

            /* Si esta importacion se puede aplicar, y si no, por que. Lo llena
               bloqueosImportacion() al final: no depende de ninguna fila, sino
               de si los origenes de la validacion se pudieron leer. */
            'puede_confirmar' => true,
            'bloqueos' => [],

            /* Cuantos de los cambios pisan una version cargada a mano. Ver la
               nota en el bucle. */
            'pisa_manuales' => 0
        ];

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $cruda) {
            $fila = self::normalizarFila($cruda, $opciones);

            /* EL CODIGO TIENE QUE EXISTIR EN CPA01, y se chequea ANTES que el
               duplicado: un codigo que no existe no se puede cargar ni una vez,
               asi que decir "esta repetido" seria contestar una pregunta que ya
               no importa. La fila queda en ERROR y el resto de la planilla se
               importa igual.

               TAMBIEN GANA SOBRE EL MOTIVO DE FUERA DE LISTA, por lo mismo: un
               proveedor que no existe no tiene rubro que discutir, y ademas el
               codigo se arregla mirando OTRO sistema. La marca 'fuera_lista'
               sigue viajando y la previsualizacion la muestra al lado, asi que
               no se pierde ninguna de las dos cosas.

               Lo que NO se pisa es un error del codigo MISMO -falta, o es mas
               largo del que Tango admite-: esos motivos dicen que hacer, y
               "no existe en CPA01" mandaria a buscar en Tango un codigo que de
               entrada no puede estar ahi. */
            if (!$fila['error_codigo'] && $validando
                && !isset($validos[$fila['cod_provee']])) {
                $fila['estado'] = 'ERROR';
                $fila['no_en_tango'] = true;
                $fila['motivo'] = 'El código "' . $fila['cod_provee'] . '" no existe en CPA01, '
                    . 'el maestro de proveedores de Tango. Un proveedor que no está en Tango no '
                    . 'va a cruzar contra ninguna cuenta a pagar, así que cargarlo no '
                    . 'clasificaría nada. Revisá el código en la planilla.';
                $resumen['no_en_tango']++;
            }

            $cod = $fila['cod_provee'];

            /* EL ARCHIVO LO TRAE, AUNQUE LA FILA NO SE PUEDA CARGAR.

               'tocados' es lo unico que decide las BAJAS, y una baja significa
               "el archivo NO lo trae". Una fila rechazada no es una fila
               ausente: el proveedor esta en la planilla, con un dato que hay que
               arreglar.

               Marcarlo igual importa mucho mas desde que un valor fuera de lista
               deja la fila en error: cada una de esas filas propondria dar de
               baja a un proveedor que la planilla SI trae y, con el interruptor
               de bajas tildado, le borraria la clasificacion. Antes solo pasaba
               con el codigo repetido -uno en toda la planilla- y por eso no se
               notaba. */
            if ($cod !== '') {
                $tocados[$cod] = true;
            }

            /* UNA FILA RECHAZADA POR UN VALOR FUERA DE LISTA SIGUE ENTRANDO AL
               CONTROL DE DUPLICADOS. Su codigo es valido: lo que esta mal es un
               valor. Si quedara afuera, un codigo repetido donde una de las dos
               filas tiene un rubro invalido se cargaria sin avisar que estaba
               repetido, que es justo lo que el control existe para evitar.

               No entran, en cambio, las que fallaron POR EL CODIGO: ese codigo
               no se puede cargar ni una vez, asi que "esta repetido" contestaria
               una pregunta que ya no importa. */
            $soloFueraDeLista = ($fila['estado'] === 'ERROR' && !empty($fila['fuera_lista'])
                && !$fila['error_codigo'] && !$fila['no_en_tango']);

            if ($fila['estado'] !== 'ERROR' || $soloFueraDeLista) {
                /* EL CODIGO REPETIDO NO SE COLAPSA. La planilla real tiene uno
                   (1.222 unicos en 1.223 filas). Quedarse con el ultimo elegiria
                   por el usuario y nadie se enteraria de que hay un duplicado.
                   Las DOS filas quedan en error, nombrando a la otra. */
                if (isset($vistos[$cod])) {
                    $fila['estado'] = 'ERROR';
                    $fila['motivo'] = 'El código ' . $cod . ' ya aparece en la línea '
                        . $vistos[$cod] . '. Está repetido en la planilla: dejá una sola fila '
                        . 'por proveedor y volvé a importar.';

                    /* La primera tambien pasa a error: si no, se cargaria una de
                       las dos sin que nadie haya decidido cual. La que YA estaba
                       en error -por un valor fuera de lista- no se vuelve a
                       contar ni se le pisa el motivo: ya no se iba a cargar. */
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

                    /* El estado solo se resuelve para las que SI se van a
                       cargar: una fila ya rechazada no es un ALTA ni un CAMBIO,
                       y decir que lo es la contaria entre lo que va a entrar. */
                    if (!$soloFueraDeLista) {
                        if (!isset($existentes[$cod])) {
                            $fila['estado'] = 'ALTA';
                            $fila['motivo'] = 'No estaba en el maestro.';
                        } else {
                            $fila = self::compararContraExistente($fila, $existentes[$cod]);

                            /* PISAR TRABAJO MANUAL SE AVISA ANTES DE CONFIRMAR.
                               La planilla manda -esa decision no cambia- pero
                               quien importa tiene que poder ver que entre los
                               300 cambios hay tres que borran lo que alguien
                               cargó a mano. Sin esto, la edición manual y la
                               importación se pisan en silencio, que es el
                               riesgo de tener dos fuentes. */
                            $fila['pisa_manual'] = ($fila['estado'] === 'CAMBIO'
                                && isset($existentes[$cod]['ORIGEN'])
                                && $existentes[$cod]['ORIGEN'] === 'MANUAL');
                        }
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

            /* LOS VALORES FUERA DE LISTA SE CUENTAN AUNQUE LA FILA ESTE EN
               ERROR, y ahora SIEMPRE lo está: es el error. Contarlos sólo en
               las filas que se cargan -como se hacía cuando era advertencia-
               daría cero justo en el caso que hay que informar.

               UNA FILA CUENTA UNA VEZ, aunque tenga tres campos mal: el numero
               que se informa arriba es "cuantas filas hay que arreglar". El
               desglose por lista cuenta cada campo, porque dice CUAL de las
               cinco esta incompleta, y la lista de valores junta los distintos
               para poder darlos de alta de una pasada. */
            if (!empty($fila['fuera_lista'])) {
                $resumen['fuera_de_lista']++;

                foreach ($fila['fuera_lista'] as $tipo => $valor) {
                    $resumen['fuera_de_lista_por_tipo'][$tipo] =
                        isset($resumen['fuera_de_lista_por_tipo'][$tipo])
                            ? $resumen['fuera_de_lista_por_tipo'][$tipo] + 1
                            : 1;

                    if (!isset($resumen['valores_fuera_de_lista'][$tipo])) {
                        $resumen['valores_fuera_de_lista'][$tipo] = [];
                    }

                    /* SIN REPETIR, y comparando igual que buscarEnLista(): si
                       la planilla trae 'DEPOSITO SUR' y 'Deposito Sur', es UN
                       valor que hay que dar de alta y no dos. Se conserva la
                       primera escritura, que es la que alguien va a copiar. */
                    $clave = Planilla::normalizarTitulo($valor);

                    if (!isset($resumen['valores_fuera_de_lista'][$tipo][$clave])) {
                        $resumen['valores_fuera_de_lista'][$tipo][$clave] = $valor;
                    }
                }
            }

            switch ($fila['estado']) {
                case 'ALTA': $resumen['altas']++; break;
                case 'CAMBIO': $resumen['cambios']++; break;
                case 'SIN_CAMBIOS': $resumen['sin_cambios']++; break;
                case 'ERROR': $resumen['errores']++; break;
            }

            if (!empty($fila['pisa_manual'])) {
                $resumen['pisa_manuales']++;
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

        /* Los valores rechazados se juntaron indexados por su clave normalizada
           -para no repetir 'DEPOSITO SUR' y 'Deposito Sur'-; lo que se publica
           es la lista, ordenada, que es como se lee y como se carga. */
        foreach ($resumen['valores_fuera_de_lista'] as $tipo => $valores) {
            $lista = array_values($valores);
            sort($lista);
            $resumen['valores_fuera_de_lista'][$tipo] = $lista;
        }

        $resumen['bloqueos'] = self::bloqueosImportacion($validando, $listasIlegibles);
        $resumen['puede_confirmar'] = empty($resumen['bloqueos']);

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
     * Por que esta importacion no se puede confirmar, si es que no se puede.
     *
     * UN ORIGEN QUE NO SE PUEDE LEER BLOQUEA TODO, y es la unica cosa que lo
     * hace. No es "ninguna fila esta mal": es que no se chequeo ninguna, asi que
     * no hay un subconjunto de filas validas que dejar pasar. El alcance por
     * fila -que es el de siempre- supone que se sabe cuales son las malas.
     *
     * EL TEXTO ES EL MISMO CRITERIO QUE guardarManual(): dice que no se pudo
     * leer, que el bloqueo es a proposito, y que hay que probar de nuevo en un
     * rato. Un mensaje que solo diga "no se puede importar" manda a buscar el
     * problema en la planilla, que es el lugar equivocado.
     *
     * Estatica y pura.
     *
     * @param bool $validoContraTango Si CPA01 se pudo leer
     * @param bool $listasIlegibles Si la tabla de opciones esta y no se leyo
     * @return array Los motivos, vacio si se puede confirmar
     */
    private static function bloqueosImportacion($validoContraTango, $listasIlegibles) {
        $bloqueos = [];

        if (!$validoContraTango) {
            $bloqueos[] = 'No se pudo leer CPA01, el maestro de proveedores de Tango, así que '
                . 'los códigos NO se pudieron validar: no es que estén todos bien, es que no se '
                . 'chequeó ninguno. La importación se frena a propósito, porque un código que no '
                . 'está en Tango no cruza contra ninguna cuenta a pagar y el proveedor quedaría '
                . 'cargado sin clasificar nada. Probá de nuevo en un rato.';
        }

        if ($listasIlegibles) {
            $bloqueos[] = 'No se pudieron leer las listas de valores de Parámetros → '
                . 'Prov. Locales, así que el rubro, el centro de costos y el plazo NO se '
                . 'pudieron validar: no es que estén todos bien, es que no se chequeó ninguno. '
                . 'La importación se frena a propósito. Probá de nuevo en un rato.';
        }

        return $bloqueos;
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
     * ES PUBLICA PORQUE LA CARGA MANUAL PASA POR ACA. Un proveedor cargado
     * desde la pantalla se normaliza con la MISMA funcion que uno importado:
     * el mismo largo de codigo, la misma normalizacion de forma de pago, el
     * mismo plazo en dias. Si la pantalla normalizara por su cuenta, un
     * proveedor cargado a mano se clasificaria distinto que el mismo proveedor
     * traido por la planilla, y nadie tendria donde notarlo.
     *
     * LAS LISTAS DE OPCIONES LLEGAN POR PARAMETRO, Y SON UNA REGLA
     * -------------------------------------------------------------
     * $opciones es lo que devuelve ProveedoresOpciones::vigentes(): las cinco
     * listas de valores validos. Llega por parametro para que esta funcion siga
     * siendo pura y se pueda probar sin base.
     *
     * UN VALOR QUE NO ESTA EN SU LISTA DEJA LA FILA EN ERROR, y el motivo nombra
     * QUE CAMPO y QUE VALOR. Esto CAMBIO: antes se importaba igual, marcado, con
     * el argumento de que un rubro raro clasifica -crea su serie- mientras que
     * un codigo inexistente no clasifica nada. Era cierto y no alcanzaba: la
     * serie del tablero quedaba creada igual, porque el aviso se leia despues de
     * importar. Ver el encabezado de la clase.
     *
     * El motivo nombra campo y valor porque con CINCO listas un "hay un valor
     * invalido" no le dice a nadie donde mirar.
     *
     * UN CAMPO VACIO NO ESTA FUERA DE LISTA: esta vacio, que ya se cuenta
     * aparte. Ver validarContraListas().
     *
     * Y NUNCA SE AGREGA SOLO A LA LISTA. Las listas las administra una persona
     * desde Parametros: si la importacion las ampliara, la lista se llenaria de
     * los typos de la planilla y dejaria de servir para validar nada.
     *
     * NO SE CORRIGE EL VALOR AL CANONICO. buscarEnLista() matchea ignorando
     * mayusculas y acentos -asi que 'alquileres' reconoce a 'Alquileres', y esa
     * fila NO es un error- pero lo que se guarda sigue siendo lo que vino.
     * Pisarlo cambiaria en silencio la serie del tablero de ese proveedor, y el
     * original es la evidencia de que la planilla tiene algo que corregir.
     *
     * EL PLAZO ES LA EXCEPCION, Y SOLO EN UNA COSA: si el valor esta en la
     * lista, los DIAS salen de la lista en lugar de derivarse del texto con
     * plazoEnDias(). Eso es lo que permite declarar un plazo que plazoEnDias()
     * no sabria interpretar, como 'FIN DE MES' -> 30. El texto guardado no
     * cambia; lo que cambia es de donde sale el numero.
     *
     * @param array $cruda Las mismas claves que las columnas de importacion
     * @param array|null $opciones Listas vigentes, o null para no validar
     * @return array
     */
    public static function normalizarFila($cruda, $opciones = null) {
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

            /* Si el codigo no existe en CPA01. Se resuelve afuera -esta funcion
               es pura y no toca la base- pero la clave viaja siempre, en false,
               para que la pantalla no tenga que preguntar si llego. */
            'no_en_tango' => false,

            /* Si la fila fallo POR EL CODIGO MISMO: falta, o es mas largo de lo
               que Tango admite. Lo mira compararImportacion() para saber si vale
               la pena preguntarle a CPA01 por ese codigo. Sin esta marca habria
               que deducirlo del texto del motivo, y un motivo es para leer, no
               para que otro codigo lo interprete. */
            'error_codigo' => false,

            /* Que campos traen un valor que no esta en su lista de opciones.
               Mapa TIPO => valor, para que la previsualizacion pueda decir cual
               es el valor raro y no solo que hay uno. Vacio si no se valido o
               si esta todo bien. Con al menos uno, la fila queda en ERROR. */
            'fuera_lista' => [],
            'estado' => 'ALTA',
            'motivo' => '',
            'cambios' => []
        ];

        $cod = Planilla::codigo(isset($cruda['cod_provee']) ? $cruda['cod_provee'] : '');

        if ($cod === '') {
            $fila['estado'] = 'ERROR';
            $fila['error_codigo'] = true;
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

            /* NO ES REDUNDANTE CON EL CHEQUEO DE CPA01: un codigo de siete
               caracteres tampoco existe en Tango, pero "tiene 7 y en Tango son
               6" dice que hay que hacer y "no existe en CPA01" manda a buscarlo
               a otro sistema. Gana el motivo que mas se acerca al arreglo. */
            $fila['error_codigo'] = true;
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

        return self::validarContraListas($fila, $opciones);
    }

    /**
     * Deja en ERROR la fila que trae un valor que no esta en su lista, y toma
     * de la lista los dias del plazo cuando si esta.
     *
     * Va aparte de normalizarFila() para que se lea de un saque QUE HACE Y QUE
     * NO HACE: valida y resuelve los dias. No corrige, no agrega nada a ninguna
     * lista y no toca el valor guardado. Ver la nota de normalizarFila().
     *
     * SIN LISTAS NO VALIDA Y NO MARCA NADA. $opciones en null es "no hay listas
     * cargadas" -no se corrio sql/cashflow_prov_locales_opciones.sql- y ahi el
     * comportamiento es el de antes: texto libre. Dejar en error el maestro
     * entero porque no existe la tabla contra la cual validarlo seria apagar el
     * modulo por una configuracion pendiente. Es la MISMA razon por la que el
     * front no dibuja desplegables vacios.
     *
     * Ojo: eso vale para "no existe la tabla". Que la tabla ESTE y no se pueda
     * leer es otra cosa y no llega hasta aca: lo corta listasVigentes(), que
     * lanza. Ver OpcionesIlegibles.
     *
     * Estatica y pura.
     *
     * @param array $fila Fila ya normalizada
     * @param array|null $opciones Listas vigentes, o null
     * @return array La fila, con 'fuera_lista' resuelto y el estado decidido
     */
    private static function validarContraListas($fila, $opciones) {
        if (!is_array($opciones)) {
            return $fila;
        }

        foreach (ProveedoresOpciones::TIPOS as $tipo => $def) {
            $campo = $def['campo'];
            $valor = isset($fila[$campo]) ? $fila[$campo] : null;

            /* Un campo VACIO no esta fuera de lista: esta vacio, que es otra
               cosa y ya se cuenta aparte. En la planilla real hay 84 filas sin
               rubro economico y 765 sin plazo; dejarlas en error haria que no
               se pueda importar nada. */
            if ($valor === null || trim((string) $valor) === '') {
                continue;
            }

            $enLista = ProveedoresOpciones::buscarEnLista(
                $valor, isset($opciones[$tipo]) ? $opciones[$tipo] : []);

            if ($enLista === null) {
                $fila['fuera_lista'][$tipo] = $valor;
                continue;
            }

            /* LOS DIAS SALEN DE LA LISTA cuando el plazo esta en ella. Es lo
               unico que la lista decide, y es lo que permite declarar
               'FIN DE MES' -> 30, que plazoEnDias() no sabria interpretar. El
               texto guardado no cambia. */
            if ($tipo === ProveedoresOpciones::TIPO_PLAZO) {
                $fila['plazo_dias'] = $enLista['plazo_dias'];
                $fila['plazo_no_usable'] = ($enLista['plazo_dias'] === null);
            }
        }

        if (empty($fila['fuera_lista'])) {
            return $fila;
        }

        /* NO SE PISA UN ERROR ANTERIOR. Si la fila ya fallo por el codigo, ese
           motivo manda: un proveedor que no existe no tiene rubro que discutir.
           La marca 'fuera_lista' viaja igual y la previsualizacion la muestra
           al lado, asi que no se pierde. */
        if ($fila['estado'] !== 'ERROR') {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = self::motivoFueraDeLista($fila['fuera_lista']);
        }

        return $fila;
    }

    /**
     * El motivo de una fila rechazada por valores fuera de lista.
     *
     * NOMBRA EL CAMPO Y EL VALOR, uno por uno. Con cinco listas, un "hay un
     * valor invalido" obliga a abrir la planilla y comparar los cinco campos
     * contra las cinco listas para saber cual es; y como el arreglo casi nunca
     * es corregir la planilla -suele ser dar de alta el valor en Parametros- el
     * mensaje tiene que decir DONDE se da de alta.
     *
     * Los cinco nombres son masculinos ('el rubro', 'el centro de costos', 'el
     * plazo de pago'), asi que el articulo puede ser uno solo.
     *
     * Estatica y pura.
     *
     * @param array $fuera Mapa TIPO => valor
     * @return string
     */
    private static function motivoFueraDeLista($fuera) {
        $partes = [];

        foreach ($fuera as $tipo => $valor) {
            $nombre = isset(ProveedoresOpciones::TIPOS[$tipo])
                ? mb_strtolower(ProveedoresOpciones::TIPOS[$tipo]['nombre'], 'UTF-8')
                : $tipo;

            $partes[] = 'el ' . $nombre . ' «' . $valor . '»';
        }

        $lista = (count($partes) === 1)
            ? ucfirst($partes[0])
            : 'Hay valores que no están en las listas de Parámetros → Prov. Locales: '
                . implode(', ', $partes);

        /* 'agregalo' y 'agregalos' van SIN tilde: el imperativo de vos es
           'agregá', y al pegarle el pronombre la palabra pasa a ser grave y
           pierde el acento escrito. */
        return $lista . ((count($partes) === 1)
                ? ' no está en la lista de Parámetros → Prov. Locales.'
                : '.')
            . ' Agrega' . (count($partes) === 1 ? 'lo' : 'los') . ' ahí si el valor es '
            . 'correcto, o corregí la planilla si es un error de tipeo.';
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

        /* EL CODIGO INEXISTENTE SE NOMBRA APARTE del conteo general de errores.
           Es el unico de los motivos que se arregla mirando OTRO sistema -hay
           que ir a Tango a ver cuál es el código de verdad- y no releyendo la
           planilla, así que decir sólo "N filas en error" manda a buscar el
           problema al lugar equivocado. */
        if (!empty($resumen['no_en_tango'])) {
            $avisos[] = $resumen['no_en_tango'] . ' fila(s) traen un código que NO existe en '
                . 'CPA01, el maestro de proveedores de Tango. No se cargan: un proveedor que no '
                . 'está en Tango no cruza contra ninguna cuenta a pagar, así que no clasificaría '
                . 'nada. Buscá el código correcto en Tango y corregí la planilla.';
        }

        /* QUE LA VALIDACION NO HAYA CORRIDO NO PUEDE PASAR DESAPERCIBIDO, y
           ahora ademas FRENA la importacion. Sin el aviso, una previsualización
           sin errores de código se lee como "todos los códigos existen", cuando
           en realidad es "no se chequeó ninguno", y el botón apagado sin motivo
           se lee como que la pantalla está rota.

           Los textos los arma bloqueosImportacion(), que es la que decide el
           bloqueo: dos redacciones del mismo hecho terminan diciendo cosas
           distintas en el primer cambio. */
        foreach (isset($resumen['bloqueos']) ? $resumen['bloqueos'] : [] as $b) {
            $avisos[] = 'ATENCIÓN, NO SE PUEDE CONFIRMAR: ' . $b;
        }

        /* PISAR TRABAJO MANUAL NO ES UN ERROR, ES UN DATO. La planilla manda:
           es la fuente del maestro y esa decisión no cambia. Pero entre 300
           cambios, los que borran lo que alguien cargó a mano son los únicos
           que esa persona querría revisar, y sin decirlo no hay forma de que
           los encuentre. */
        if (!empty($resumen['pisa_manuales'])) {
            $avisos[] = 'ATENCIÓN: ' . $resumen['pisa_manuales'] . ' de los cambios pisan '
                . 'proveedores que se habían editado a mano desde la pantalla. La planilla '
                . 'manda, así que se van a sobrescribir; quedan en el historial de cada '
                . 'proveedor. Están marcados en la lista de abajo.';
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

        /* LOS VALORES FUERA DE LISTA NO SE IMPORTAN. Esto CAMBIÓ: eran una
           advertencia y la fila se cargaba igual.

           El aviso nombra CUÁLES listas y CUÁNTOS valores distintos hay que dar
           de alta en cada una, porque con cinco listas decir sólo "hay valores
           fuera de lista" obliga a recorrer la previsualización entera para
           saber dónde mirar. Los valores en sí los lista la previsualización,
           agrupados y sin repetir: con la planilla real, descubrirlos de a uno
           reimportando serían decenas de vueltas.

           Y dice explícitamente que no se agregan solos: alguien va a esperar
           que sí. */
        if (!empty($resumen['fuera_de_lista'])) {
            $porTipo = [];
            $valores = isset($resumen['valores_fuera_de_lista'])
                ? $resumen['valores_fuera_de_lista'] : [];

            foreach ($resumen['fuera_de_lista_por_tipo'] as $tipo => $n) {
                $distintos = isset($valores[$tipo]) ? count($valores[$tipo]) : 0;

                $porTipo[] = (isset(ProveedoresOpciones::TIPOS[$tipo])
                        ? ProveedoresOpciones::TIPOS[$tipo]['nombre'] : $tipo)
                    . ' (' . $n . ' fila(s), ' . $distintos . ' valor(es) distinto(s))';
            }

            $avisos[] = $resumen['fuera_de_lista'] . ' fila(s) traen algún valor que NO está en '
                . 'las listas de opciones y POR ESO NO SE CARGAN: ' . implode(', ', $porTipo)
                . '. Los valores NO se agregan solos a las listas: si son correctos, cargalos en '
                . 'Parámetros → Prov. Locales —están listados acá abajo, agrupados, para darlos '
                . 'de alta de una sola pasada— y volvé a importar; si son un typo, corregí la '
                . 'planilla. Ojo con el RUBRO ECONÓMICO: cada valor distinto crea una fila propia '
                . 'en el tablero, y por eso conviene mirar dos veces antes de darlo de alta.';
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
     * SE VUELVE A VALIDAR ACA, CONTRA LOS ORIGENES LEIDOS AHORA
     * ----------------------------------------------------------
     * $comparacion es lo que MANDA EL NAVEGADOR: se reenvia el diff para que se
     * aplique exactamente lo que la persona vio, y eso esta bien, pero un diff
     * que viene de afuera es un PEDIDO y no una autorizacion. Confiar en su
     * 'estado' seria dejar que un POST armado a mano marque 'ALTA' una fila que
     * la previsualizacion habia rechazado, y este endpoint es alcanzable sin
     * pasar por la pantalla. Es el mismo criterio con el que guardarManual()
     * vuelve a consultar CPA01 aunque el buscador ya haya ofrecido el codigo.
     *
     * Ademas cubre un caso que no es un ataque y pasa solo: entre previsualizar
     * y confirmar puede pasar un rato, y en ese rato alguien pudo dar de baja un
     * valor en Parametros.
     *
     * SI LA REVALIDACION NO COINCIDE SE FRENA TODO Y NO SE APLICA NADA, que es
     * lo contrario del alcance por fila de la previsualizacion. La diferencia:
     * ahi las filas malas estaban a la vista y alguien decidio importar el
     * resto; aca lo que se descubre es que lo que se confirmo no es lo que se
     * habia visto, y aplicar "la parte que sobrevive" seria aplicar algo que
     * nadie miro. Se avisa y se vuelve a previsualizar, que es barato.
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

        $this->revalidarImportacion($comparacion);

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

    /**
     * Vuelve a validar, contra la base, el diff que mando el navegador.
     *
     * Lanza si no se puede aplicar. No devuelve nada: la unica respuesta util es
     * "segui" o el motivo por el que no. Ver la nota de aplicarImportacion().
     *
     * SE HACE ANTES DE ABRIR LA TRANSACCION, a proposito: lo que se esta
     * decidiendo es si se toca la base, no si se deshace lo tocado.
     *
     * @param array $comparacion Lo que mando el navegador
     * @throws Exception Si algun origen no se puede leer, o si alguna fila que
     *                   viene marcada para cargar ya no pasa la validacion
     */
    private function revalidarImportacion($comparacion) {
        $porCargar = [];

        foreach (isset($comparacion['filas']) ? $comparacion['filas'] : [] as $fila) {
            if (isset($fila['estado']) && ($fila['estado'] === 'ALTA'
                || $fila['estado'] === 'CAMBIO')) {
                $porCargar[] = $fila;
            }
        }

        if (empty($porCargar)) {
            return;
        }

        /* LOS DOS ORIGENES, LEIDOS AHORA. Si alguno no responde no se aplica
           nada, con el mismo texto y el mismo criterio que la previsualizacion:
           no se chequeo ninguna fila, asi que no hay ninguna que dejar pasar. */
        if (!$this->tango()->disponible()) {
            throw new Exception(self::textoBloqueoTango());
        }

        try {
            $validos = $this->tango()->existentes(array_column($porCargar, 'cod_provee'));
        } catch (Throwable $e) {
            throw new Exception(self::textoBloqueoTango());
        }

        // listasVigentes() ya distingue "no existe la tabla" -null, no se
        // valida- de "no se pudo leer", que lanza OpcionesIlegibles.
        $listas = $this->listasVigentes();

        foreach ($porCargar as $fila) {
            $motivo = self::revalidarFila($fila, $validos, $listas);

            if ($motivo === null) {
                continue;
            }

            throw new Exception('No se importó nada. La fila ' . $fila['linea'] . ' ('
                . $fila['cod_provee'] . ') no se puede cargar: ' . $motivo . ' Volvé a '
                . 'previsualizar el archivo: lo que se confirmó no coincide con lo que la '
                . 'validación dice ahora, y aplicar sólo una parte sería cargar algo que nadie '
                . 'miró.');
        }
    }

    /** El texto de CPA01 caido. Uno solo, para las tres pantallas que lo dicen */
    private static function textoBloqueoTango() {
        return 'No se pudo leer CPA01, el maestro de proveedores de Tango, así que no se puede '
            . 'verificar que los códigos existan. La importación se frena a propósito: un código '
            . 'que no está en Tango no cruza contra ninguna cuenta a pagar y el proveedor '
            . 'quedaría cargado sin clasificar nada. Probá de nuevo en un rato.';
    }

    /**
     * Si una fila que viene marcada para cargar sigue siendo cargable.
     *
     * ES LA MISMA REGLA QUE EL DIFF, aplicada a una fila ya normalizada: el
     * codigo tiene que existir en CPA01 y los cinco valores tienen que estar en
     * su lista. Se escribe una sola vez -aca- y no dos, porque dos copias de una
     * regla de validacion se separan en el primer cambio y la que queda vieja es
     * siempre la que decide si se escribe en la base.
     *
     * Estatica y pura: se prueba sin base, que es justamente lo que hace
     * verificable que la revalidacion diga lo mismo que la previsualizacion.
     *
     * @param array $fila Una fila del diff
     * @param array|null $validos Mapa COD_PROVEE => NOM_PROVEE de CPA01
     * @param array|null $listas Listas vigentes, o null si no existe la tabla
     * @return string|null El motivo por el que no se puede cargar, o null
     */
    public static function revalidarFila($fila, $validos, $listas) {
        $cruda = isset($fila['cod_provee']) ? $fila : [];

        /* Se vuelve a normalizar en vez de creerle a las claves que vinieron:
           el largo del codigo, el plazo en dias y la pertenencia a las listas
           los resuelve normalizarFila(), y es la misma funcion que corrio la
           previsualizacion. */
        $limpia = self::normalizarFila($cruda, $listas);

        if ($limpia['estado'] === 'ERROR') {
            return $limpia['motivo'];
        }

        if (is_array($validos) && !isset($validos[$limpia['cod_provee']])) {
            return 'el código "' . $limpia['cod_provee'] . '" no existe en CPA01, el maestro de '
                . 'proveedores de Tango.';
        }

        return null;
    }

    /* ====================================================================
       CARGA Y EDICION MANUAL

       El maestro sale de la planilla, y eso no cambia: LA PLANILLA SIGUE
       MANDANDO. Una edicion manual es una version mas, y la proxima
       importacion la pisa como pisa cualquier otra. Es lo que evita tener
       dos maestros en paralelo, que es la decision que este modulo ya tomo
       cuando descarto CPA01.COD_RUBRO.

       Lo que si se agrega es que pisar trabajo manual no sea invisible: la
       columna ORIGEN permite que el diff avise ANTES de confirmar. Ver
       compararImportacion() y sql/cashflow_prov_locales_maestro_manual.sql.

       PASA POR EL MISMO CAMINO QUE LA IMPORTACION, y no es por ahorrar
       codigo: normalizarFila() aplica el largo del codigo, la normalizacion
       de la forma de pago y el plazo en dias. Con una normalizacion propia,
       el mismo proveedor quedaria clasificado distinto segun por donde entro.
       ==================================================================== */

    /**
     * Los valores que ya existen en el maestro para los tres campos de
     * clasificacion, ordenados por uso.
     *
     * Es lo que el formulario de carga manual ofrece como sugerencia. NO es una
     * lista cerrada -se puede escribir uno nuevo- pero tiene que estar: cada
     * RUBRO_ECONOMICO distinto crea una serie propia en el tablero, asi que
     * tipear "Alquileres " con un espacio al final no es un detalle cosmetico,
     * es una fila nueva del cuadro que nadie pidio. Mostrando lo que ya hay, el
     * caso normal es elegir.
     *
     * @return array ['rubro_economico' => [valor => veces], 'rubro' => …, …]
     */
    public function rubrosCargados() {
        $campos = ['rubro_economico' => 'RUBRO_ECONOMICO',
                   'rubro' => 'RUBRO',
                   'centro_costos' => 'CENTRO_COSTOS'];
        $salida = [];

        foreach ($campos as $clave => $col) {
            $salida[$clave] = [];
        }

        foreach ($this->mapa() as $m) {
            foreach ($campos as $clave => $col) {
                $v = ($m[$col] === null) ? '' : trim((string) $m[$col]);

                if ($v === '') {
                    continue;
                }

                $salida[$clave][$v] = isset($salida[$clave][$v]) ? $salida[$clave][$v] + 1 : 1;
            }
        }

        foreach ($salida as $clave => $vs) {
            arsort($salida[$clave]);
        }

        return $salida;
    }

    /**
     * Carga o edita un proveedor del maestro, de a uno.
     *
     * NO HACE UPDATE: da de baja la version vigente e inserta una nueva, las
     * dos cosas en UNA transaccion. Es exactamente lo que hace un CAMBIO de la
     * importacion, y por el mismo motivo: el historial es lo unico que despues
     * explica por que un comprobante se clasificaba distinto.
     *
     * EL CODIGO SE VALIDA CONTRA CPA01 Y SE RECHAZA SI NO EXISTE
     * ----------------------------------------------------------
     * No hay alta con advertencia: CPA01 es la tabla maestra de proveedores, y
     * un codigo que no esta ahi no va a cruzar contra ninguna cuenta a pagar
     * nunca. Cargarlo igual crearia una fila que no clasifica nada y cuyo
     * sintoma -una deuda sin rubro- aparece semanas despues y en otra pantalla.
     *
     * EL NOMBRE SE TRAE DE CPA01 Y SE IGNORA EL QUE MANDE EL NAVEGADOR. Es
     * informativo y tiene que decir lo mismo que Tango, o dos pantallas van a
     * mostrar dos nombres para el mismo codigo. Mismo criterio que
     * Echeqs::guardarClientePrechequeado() con la razon social de GVA14.
     *
     * SI UN ORIGEN NO SE PUEDE LEER, EL ALTA SE BLOQUEA. La importacion hace lo
     * mismo desde que los valores de las listas son una regla: no poder chequear
     * no es lo mismo que estar todo bien. Antes acá se decía que esto era lo
     * contrario de lo que hacía la importación, y ya no lo es. Ver el encabezado
     * de la clase.
     *
     * UN VALOR FUERA DE LISTA TAMPOCO SE GUARDA. Esto CAMBIO, y sale solo:
     * normalizarFila() es la misma funcion que usa la importacion, y desde que
     * ahi un valor fuera de lista deja la fila en ERROR, este metodo -que ya
     * lanzaba con el motivo- rechaza igual. Era lo unico coherente: si la
     * importacion rechaza un rubro que no esta en la lista, el alta manual no
     * puede aceptarlo, porque el resultado seria el mismo dato en el mismo
     * maestro segun por donde entro.
     *
     * TIENE UNA CONSECUENCIA QUE HAY QUE SABER: un proveedor cargado ANTES de
     * que existieran las listas puede tener un valor que ninguna lista ofrece.
     * Se lo puede abrir y el formulario NO le borra el valor -lo conserva,
     * marcado en naranja, ver elegirValor() en el JS- pero guardar va a
     * rechazarlo hasta que alguien elija uno de la lista o de alta el valor en
     * Parametros. Es el precio de que las listas signifiquen algo, y la pantalla
     * lo dice en el campo en vez de dejar que se descubra al guardar.
     *
     * @param array $datos Las mismas claves que las columnas de importacion
     * @param string|null $usuario
     * @return array ['cod_provee', 'estado' => 'ALTA'|'CAMBIO', 'fila']
     * @throws OpcionesIlegibles Si las listas existen y no se pudieron leer
     */
    public function guardarManual($datos, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla del maestro. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        if (!$this->tieneOrigen()) {
            throw new Exception('El maestro todavía no distingue las cargas manuales de las '
                . 'importadas. Corré sql/cashflow_prov_locales_maestro_manual.sql contra la '
                . 'base central. Sin eso, una edición a mano quedaría indistinguible de la '
                . 'planilla y la próxima importación la pisaría sin avisar.');
        }

        /* Con las listas cargadas, el PLAZO en dias sale de la lista y no de
           derivar el texto, y un valor que no esta en su lista deja la fila en
           ERROR: es la misma funcion que valida la importacion. Si las listas no
           se pueden leer, listasVigentes() lanza y no se guarda nada. */
        $fila = self::normalizarFila($datos, $this->listasVigentes());

        if ($fila['estado'] === 'ERROR') {
            throw new Exception($fila['motivo']);
        }

        if (!$this->tango()->disponible()) {
            throw new Exception('No se pudo leer CPA01, el maestro de proveedores de Tango, así '
                . 'que no se puede verificar que el código exista. El alta se frena a propósito: '
                . 'un código que no está en Tango no cruza contra ninguna cuenta a pagar y el '
                . 'proveedor quedaría cargado sin clasificar nada. Probá de nuevo en un rato.');
        }

        $nombreTango = $this->tango()->existe($fila['cod_provee']);

        if ($nombreTango === null) {
            throw new Exception('El código "' . $fila['cod_provee'] . '" no existe en CPA01, el '
                . 'maestro de proveedores de Tango. Buscá el proveedor por nombre en el campo de '
                . 'código: el buscador trae el código correcto.');
        }

        /* EL NOMBRE ES EL DE TANGO, siempre. Lo que haya mandado el navegador
           se descarta: el campo es de sólo lectura en la pantalla, pero este
           método es alcanzable sin pasar por ella. */
        $fila['nombre'] = $nombreTango;

        $mapa = $this->mapa();
        $existia = isset($mapa[$fila['cod_provee']]);
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            if ($existia) {
                $this->bajaVigente($cid, $fila['cod_provee']);
            }

            $this->insertar($cid, $fila, $usuario, 'MANUAL');
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->mapa = null;

        return [
            'cod_provee' => $fila['cod_provee'],
            'estado' => $existia ? 'CAMBIO' : 'ALTA',
            'fila' => $fila
        ];
    }

    /**
     * Da de baja un proveedor del maestro.
     *
     * NO BORRA LA FILA, marca VIGENTE = 0 igual que una baja de la importacion:
     * la deuda de ese proveedor pasa a estar sin clasificar y el historial sigue
     * explicando como se clasificaba antes.
     *
     * @param string $codProvee
     * @return bool Si habia algo vigente para dar de baja
     */
    public function bajaManual($codProvee) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla del maestro.');
        }

        $cod = Planilla::codigo($codProvee);

        if ($cod === '') {
            throw new Exception('Falta el código del proveedor que hay que dar de baja.');
        }

        $mapa = $this->mapa();

        if (!isset($mapa[$cod])) {
            return false;
        }

        $this->bajaVigente($this->conectar(), $cod);
        $this->mapa = null;

        return true;
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

    /**
     * Inserta una fila del maestro.
     *
     * $origen dice de donde salio esta version. La columna puede no existir
     * todavia -es de un script posterior-, y entonces no entra al INSERT: su
     * default la pone en 'IMPORT', que es lo cierto en una instalacion que
     * todavia no puede cargar a mano.
     */
    private function insertar($cid, $fila, $usuario, $origen = 'IMPORT') {
        $cols = ['COD_PROVEE', 'NOMBRE', 'RUBRO_ECONOMICO', 'RUBRO', 'CENTRO_COSTOS',
                 'FORMA_PAGO', 'FORMA_PAGO_ORIG', 'PLAZO_PAGO', 'PLAZO_DIAS',
                 'CRITERIO_DISTRIB', 'CRITERIO_ORIG', 'VIGENTE', 'USUARIO'];
        $vals = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '1', '?'];

        $params = [
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
        ];

        if ($this->tieneOrigen()) {
            $cols[] = 'ORIGEN';
            $vals[] = '?';
            $params[] = $origen;
        }

        $stmt = sqlsrv_query($cid,
            "INSERT INTO dbo." . self::TABLA . "
                 (" . implode(', ', $cols) . ")
             VALUES (" . implode(', ', $vals) . ")",
            $params);

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

        /* EL ORIGEN VA EN EL HISTORIAL, y es la mitad de para qué sirve desde
           que el maestro se puede editar a mano: "esta versión la escribió una
           persona el martes" y "esta la trajo la planilla" explican cosas
           distintas cuando alguien pregunta por qué un comprobante cambió de
           rubro. */
        $sql = "SELECT ID, COD_PROVEE, NOMBRE, RUBRO_ECONOMICO, RUBRO, CENTRO_COSTOS,
                       FORMA_PAGO, FORMA_PAGO_ORIG, PLAZO_PAGO, PLAZO_DIAS,
                       CRITERIO_DISTRIB, VIGENTE, USUARIO, FECHA_IMPORTACION, FECHA_BAJA, "
                       . $this->origenSql() . " AS ORIGEN
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
