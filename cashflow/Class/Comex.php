<?php

require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Horizonte.php';

/**
 * Comex
 * Los datos de Comercio Exterior: los pagos a proveedores del exterior y el
 * cronograma de nacionalizacion.
 *
 * LA CONSUMEN DOS PESTANAS Y EL TABLERO, y por eso los dos getters devuelven
 * las filas crudas mas lo que se deriva de ellas: quien las muestra y quien las
 * agrupa tienen que estar mirando exactamente los mismos numeros.
 *
 * LOS PAGOS AL EXTERIOR SE VALUAN ACA, CON DOLAR FUTURO
 * -----------------------------------------------------
 * VALOR_FOB_DOLAR esta en dolares y el cashflow es en pesos, asi que alguien
 * tiene que convertir. Antes lo hacia ComexProvider con UN parametro global
 * -'comex_tipo_cambio_usd'- aplicado a todas las filas por igual, y la pestana
 * no convertia nada: mostraba dolares. Eran dos pantallas del mismo modulo
 * midiendo cosas distintas.
 *
 * Ahora cada fila se valua con la CURVA DE DOLAR FUTURO ROFEX segun el mes de
 * su fecha efectiva de pago, y la conversion vive en getProveedoresExterior():
 * la pestana y el tablero leen el mismo IMPORTE_ARS, calculado una sola vez. La
 * regla de que cotizacion le toca a cada fila es pura y vive en DolarFuturo,
 * donde esta el por que completo.
 *
 * EL DOLAR FUTURO ES EL UNICO CRITERIO. 'comex_tipo_cambio_usd' quedo en
 * Parametros::RETIRADOS: dos criterios de valuacion conviviendo significan dos
 * numeros distintos para el mismo contenedor sin que nadie pueda decir cual es
 * cual.
 *
 * LO QUE NO SE PUEDE VALUAR NO SE INVENTA
 * ---------------------------------------
 * Una fila sin fecha efectiva de pago no tiene mes, y sin mes no hay cotizacion
 * que aplicarle: IMPORTE_ARS queda en null -no en cero- y el llamador informa
 * cuantas son y cuanto suman en dolares. Lo mismo si la curva no se puede leer.
 * Ver el encabezado de Cotizacion, que documenta el criterio para todo el
 * modulo.
 *
 * LAS FECHAS EDITABLES VIVEN EN EL MAESTRO, NO ACA
 * ------------------------------------------------
 * Esto CAMBIO con feature/comex-fecha-maestra. Las dos fechas que se editan
 * desde el cashflow -la estimada de pago y la de nacionalizacion- se guardaban
 * en RO_T_CASHFLOW_COMEX_CRONO_NAC (FECHA_PAGO_EDIT, FECHA_NAC_EDIT) y el
 * maestro de la plataforma Comex no se enteraba: la app de Comercio Exterior
 * mostraba una fecha y el cashflow otra, las dos vigentes, y ninguna pantalla
 * decia que existia la otra.
 *
 * Ahora hay UN solo lugar por fecha, y es el maestro:
 *
 *     fecha estimada de pago            -> RO_T_IMPORTACIONES_ENCABEZADO.FECHA_EST_PAGO
 *     fecha estimada de nacionalizacion -> RO_T_IMPORTACIONES_ENCABEZADO.FECHA_DESP_ADU
 *
 * Las columnas EDIT quedan en la base -este modulo no borra nada- pero NADIE
 * LAS LEE. RO_T_CASHFLOW_COMEX_CRONO_NAC sigue viva por COTIZ_USD_EDIT, que es
 * otro circuito y no se toca. Ver sql/cashflow_comex_fecha_maestra.sql.
 *
 * COMO SE ESCRIBE SOBRE UNA TABLA AJENA
 * -------------------------------------
 * El maestro es de la plataforma Comex y no tiene columnas de auditoria, asi
 * que el rastro de quien edito y que decia antes va del lado del cashflow, en
 * RO_T_CASHFLOW_COMEX_FECHA_EDIT. No es una segunda fuente de verdad: la fecha
 * vigente es siempre la del maestro, y ese rastro solo contesta QUIEN la puso.
 * Si la app de Comex la mueve despues, el rastro deja de describir lo que se ve
 * y marcaVigente() lo detecta comparando contra el maestro.
 *
 * SE VEN LOS VENCIDOS, PERO NO SUMAN
 * ----------------------------------
 * Las dos consultas filtraban con ISNULL(FECHA_EMB, FECHA_EST_EMB) >= GETDATE()
 * y ese filtro escondia mas de la mitad del padron -42 de 76 contenedores al
 * 19/09/2026-, incluidos 10 con fecha de pago FUTURA y 18 con nacionalizacion
 * futura, que son pagos y gastos que el tablero tenia que estar contando. El
 * filtro se fue. Lo que decide donde impacta un contenedor es SU FECHA
 * EFECTIVA, no cuando embarco.
 *
 * AL CASHFLOW ENTRA LO QUE SE PAGA DE HOY EN ADELANTE. Un pago con la fecha ya
 * vencida NO SUMA: o ya salio -y entonces no es proyeccion- o no salio y hay
 * que corregirle la fecha. Las dos cosas son gestion de Comercio Exterior sobre
 * el dato. Lo implementa aporteAlEje() y aplica a PROVEEDORES EXTERIOR; en
 * Crono Nacionalizacion la regla no se pidio y las fechas vencidas se agrupan
 * como cualquier otra.
 *
 * Y NO SE REUBICA EN HOY, que es la otra mitad de la decision: la fila queda en
 * su fecha en vez de amontonarse en la primera columna. Se aparta de
 * Ingresos::ubicarCobroVencido() -que si ubica en el primer dia del eje las
 * cobranzas vencidas- por dos razones que no valen alla:
 *
 *   1. Aca la fecha SE EDITA desde la pestana. Una fecha de pago vencida es un
 *      dato a corregir, no un hecho consumado: el circuito correcto es que
 *      Comercio Exterior le cargue la fecha nueva, y para eso la fila ahora se
 *      ve. Reubicar en hoy pondria en la columna de hoy un egreso que nadie
 *      afirmo que sale hoy, y encima competiria con la correccion.
 *   2. Alla Tango dice si la factura sigue impaga, asi que reubicar es correcto:
 *      esa plata esta pendiente con seguridad. Aca no hay ninguna senal de que
 *      el pago no se haya hecho -el unico corte es que el contenedor todavia no
 *      tenga detalle cargado-, y al 19/09/2026 hay pagos vencidos de hasta 331
 *      dias. Amontonarlos en la columna de hoy pondria en el peor dia del
 *      tablero una montania de plata que probablemente ya salio.
 *
 * EN LA PESTANA ADEMAS ESTAN ESCONDIDAS por defecto, detras del interruptor
 * "Ver vencidas", con el conteo al lado. Esconder filas que valen cero en el
 * periodo no cambia ningun total: el interruptor es para poder ir a
 * corregirlas.
 *
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * COTIZ_USD_EDIT -el override por contenedor- y la tabla del rastro son de
 * scripts posteriores, asi que su existencia se pregunta y la pantalla sigue
 * funcionando sin ellos: las fechas se leen del maestro igual, los vencidos se
 * ven igual, y lo unico que no se puede es editar. Mismo patron que
 * ProveedoresCategorias::tieneOrigen().
 */
class Comex {

    /** Tabla propia del modulo: hoy solo se lee por el override de cotizacion */
    const TABLA_EDIT = 'RO_T_CASHFLOW_COMEX_CRONO_NAC';

    /** El rastro de que fechas del maestro las movio alguien desde el cashflow */
    const TABLA_HISTORIAL = 'RO_T_CASHFLOW_COMEX_FECHA_EDIT';

    /** Que pagos ya se hicieron, con su historial. Es un dato del cashflow */
    const TABLA_PAGADO = 'RO_T_CASHFLOW_COMEX_PAGADO';

    /** El maestro de la plataforma Comex, donde viven las dos fechas */
    const TABLA_MAESTRO = 'RO_T_IMPORTACIONES_ENCABEZADO';

    /**
     * Los dos campos editables, y en que columna del maestro vive cada uno.
     *
     * LA LISTA ES CERRADA Y VIVE ACA. El nombre de la columna nunca sale de lo
     * que manda el cliente: se busca en este mapa, asi que no hay forma de que
     * un pedido armado a mano escriba sobre otra columna del maestro.
     */
    const CAMPOS = [
        'PAGO' => 'FECHA_EST_PAGO',
        'NAC'  => 'FECHA_DESP_ADU'
    ];

    /** @var bool|null Cache de si la tabla ya tiene la columna COTIZ_USD_EDIT */
    private $cotizEdit = null;

    /** @var bool|null Cache de si existe la tabla del rastro */
    private $historial = null;

    /** @var bool|null Cache de si existe la tabla de pagados */
    private $pagado = null;

    /** @var bool|null Cache de si el maestro ya tiene el BIT FECHA_PAGO_CONF */
    private $fechaPagoConf = null;

    /** @var DolarFuturo|null Se construye una vez: la curva se lee y se cachea adentro */
    private $dolar = null;

    function __construct(){
        require_once __DIR__.'/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       ESTADO DEL MODULO
       ==================================================================== */

    /**
     * Si la tabla ya tiene la columna COTIZ_USD_EDIT de
     * sql/cashflow_comex_cotiz_edit.sql.
     *
     * SE PREGUNTA en vez de darla por hecha porque los pagos se siguen pudiendo
     * VALUAR sin ella: una instalacion que no corrio el script ve su pestana en
     * pesos, valuada con la curva. Lo que no puede es corregir la cotizacion de
     * una fila, y eso lo dice updateCotizacion() con su propio mensaje.
     *
     * @return bool
     */
    public function tieneCotizEdit() {
        if ($this->cotizEdit !== null) {
            return $this->cotizEdit;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT COL_LENGTH('dbo." . self::TABLA_EDIT . "', 'COTIZ_USD_EDIT') AS C");

        if ($stmt === false) {
            throw new Exception('Error al verificar la columna de cotización editable');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->cotizEdit = ($row && $row['C'] !== null);

        return $this->cotizEdit;
    }

    /**
     * Si ya existe la tabla del rastro, de
     * sql/cashflow_comex_fecha_maestra.sql.
     *
     * SE PREGUNTA en vez de darla por hecha porque las dos pestanas se LEEN sin
     * ella: las fechas salen del maestro, que siempre esta, y los vencidos se
     * ven igual. Lo que no se puede sin la tabla es EDITAR, y eso es a
     * proposito: escribir sobre el maestro de otra plataforma sin dejar rastro
     * de quien lo hizo es exactamente lo que esta tabla existe para evitar.
     * guardarFecha() lo dice con su propio mensaje.
     *
     * @return bool
     */
    public function tieneHistorial() {
        if ($this->historial !== null) {
            return $this->historial;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_HISTORIAL . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception('Error al verificar la tabla del historial de fechas');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->historial = ($row && $row['T'] !== null);

        return $this->historial;
    }

    /**
     * Si el maestro ya tiene el BIT FECHA_PAGO_CONF, de
     * administracion/comercioExterior/sql/10_fecha_pago_manual.sql.
     *
     * ES UNA COLUMNA DE LA OTRA PLATAFORMA, y este modulo la lee y la escribe
     * -guardarFecha() la prende cuando se mueve la fecha de pago desde aca-.
     * Por eso se pregunta y no se asume: los dos repos se despliegan juntos,
     * pero el DDL lo corre una persona y puede quedar atras. Sin la columna, la
     * pestana se comporta exactamente como antes: la fecha se guarda igual, el
     * rastro se guarda igual y la marca de editada la sigue decidiendo
     * marcaVigente().
     *
     * QUE APORTA EL BIT QUE EL RASTRO NO PODIA. El rastro dice "el cashflow
     * escribio esta fecha"; el BIT dice "esta fecha esta fijada a mano", sin
     * importar desde que aplicacion. Una fecha que alguien fijo desde Comercio
     * Exterior no deja rastro de este lado -es de otra plataforma- y aun asi
     * tiene que verse como fijada, porque lo que la marca contesta es si el
     * recalculo automatico de +5 dias la va a pisar.
     *
     * Mismo patron que tieneCotizEdit() y tieneHistorial().
     *
     * @return bool
     */
    public function tieneFechaPagoConf() {
        if ($this->fechaPagoConf !== null) {
            return $this->fechaPagoConf;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT COL_LENGTH('dbo." . self::TABLA_MAESTRO . "', 'FECHA_PAGO_CONF') AS C");

        if ($stmt === false) {
            throw new Exception('Error al verificar la columna de fecha de pago fijada');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->fechaPagoConf = ($row && $row['C'] !== null);

        return $this->fechaPagoConf;
    }

    /**
     * Las columnas del BIT de fecha de pago fijada, listas para el SELECT.
     *
     * SIN LA COLUMNA se piden literales con el mismo nombre y el BIT en cero,
     * que es exactamente lo que significa: sin el script 10 nadie fijo nada. El
     * resto del metodo no tiene que preguntar si el DDL corrio. Mismo truco que
     * rastroSelect() y pagadoSelect().
     *
     * @return string
     */
    private function confPagoSelect() {
        if (!$this->tieneFechaPagoConf()) {
            return "CAST(0 AS BIT)             FECHA_PAGO_CONF,
                    CAST(NULL AS VARCHAR(50))  FECHA_PAGO_CONF_USUARIO,
                    CAST(NULL AS DATETIME)     FECHA_PAGO_CONF_FECHA";
        }

        return "A.FECHA_PAGO_CONF,
                A.FECHA_PAGO_CONF_USUARIO,
                A.FECHA_PAGO_CONF_FECHA";
    }

    /**
     * Si ya existe la tabla de pagados, de sql/cashflow_comex_pagado.sql.
     *
     * SE PREGUNTA en vez de darla por hecha: sin ella las dos pestanas se leen
     * exactamente como antes -nadie marco nada, asi que no hay nada que
     * descontar- y lo unico que no se puede es marcar. Mismo patron que
     * tieneHistorial() y que tieneCotizEdit().
     *
     * @return bool
     */
    public function tienePagado() {
        if ($this->pagado !== null) {
            return $this->pagado;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_PAGADO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception('Error al verificar la tabla de pagos marcados');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->pagado = ($row && $row['T'] !== null);

        return $this->pagado;
    }

    /**
     * El aviso de que el tilde de pagado esta apagado, o cadena vacia.
     *
     * @return string
     */
    public function avisoSinPagado() {
        if ($this->tienePagado()) {
            return '';
        }

        return 'El tilde de «pagado» está apagado: falta la tabla ' . self::TABLA_PAGADO
            . '. Corré sql/cashflow_comex_pagado.sql contra la base central y la columna se '
            . 'vuelve marcable sola. Todo lo demás de esta pantalla funciona igual: mientras '
            . 'tanto no hay ningún pago marcado, así que el tablero proyecta todo lo pendiente.';
    }

    /**
     * El aviso de que la edicion de fechas esta apagada, o cadena vacia.
     *
     * Uno solo para las dos pestanas: las dos escriben sobre el mismo maestro y
     * les falta la misma tabla, asi que dos textos parecidos se
     * desincronizarian en la primera correccion.
     *
     * @return string
     */
    public function avisoSinHistorial() {
        if ($this->tieneHistorial()) {
            return '';
        }

        return 'La edición de fechas está apagada: falta la tabla '
            . self::TABLA_HISTORIAL . '. Corré sql/cashflow_comex_fecha_maestra.sql contra la '
            . 'base central y las fechas se vuelven editables solas. Todo lo demás de esta '
            . 'pantalla funciona igual: las fechas salen del maestro de Comercio Exterior, que '
            . 'es de donde salen ahora para las dos aplicaciones.';
    }

    /**
     * La curva de dolar futuro, con su cache.
     *
     * @return DolarFuturo
     */
    public function dolarFuturo() {
        if ($this->dolar === null) {
            $this->dolar = new DolarFuturo();
        }

        return $this->dolar;
    }

    /**
     * Avisos de configuracion pendiente de la pestana Proveedores Exterior.
     *
     * LO QUE FALTA SE DICE, AUNQUE NO ROMPA NADA. Mismo criterio que
     * ProveedoresCategorias::getAvisos(): una funcion que desaparece sin decir
     * por que es indistinguible de una que no se construyo.
     *
     * @return array
     */
    public function getAvisosExterior() {
        $avisos = [];
        $dolar = $this->dolarFuturo();

        /* Los dos scripts que esta pantalla puede no tener corridos. Se avisan
           los dos: cada uno apaga una cosa distinta -editar fechas y marcar
           pagos- y un solo mensaje generico no diria cual falta. */
        foreach ([$this->avisoSinHistorial(), $this->avisoSinPagado()] as $falta) {
            if ($falta !== '') {
                $avisos[] = $falta;
            }
        }

        if (!$dolar->disponible()) {
            $avisos[] = 'No se pudo leer la curva de dólar futuro ROFEX ('
                . DolarFuturo::ORIGEN . '), así que los pagos al exterior se muestran sin '
                . 'valuar en pesos. Los importes en dólares están: lo que falta es a cuánto '
                . 'convertirlos. '
                . ($dolar->error() === null ? '' : $dolar->error());

            return $avisos;
        }

        if (!$this->tieneCotizEdit()) {
            $avisos[] = 'La corrección manual de la cotización está apagada: falta la columna '
                . 'COTIZ_USD_EDIT. Corré sql/cashflow_comex_cotiz_edit.sql contra la base '
                . 'central y la columna se vuelve editable sola. Todo lo demás de esta pantalla '
                . 'funciona igual: los pagos se valúan con la curva.';
        }

        return $avisos;
    }

    /* ====================================================================
       EL RASTRO DE LA EDICION, EN LAS DOS CONSULTAS

       Las dos pestanas necesitan exactamente lo mismo -quien movio esta fecha,
       cuando, y que decia antes- sobre campos distintos del mismo maestro, asi
       que el pedazo de consulta se arma una sola vez.
       ==================================================================== */

    /**
     * Las columnas del rastro vigente de un campo, listas para el SELECT.
     *
     * SIN LA TABLA SE PIDEN LITERALES NULL con el mismo nombre, para que el
     * resto del metodo no tenga que preguntar si el script corrio. Es el mismo
     * truco que usa el override de cotizacion, y significa lo correcto: sin la
     * tabla no hay ningun rastro, y NULL es eso.
     *
     * @param string $campo 'PAGO' o 'NAC'
     * @return string
     */
    private function rastroSelect($campo) {
        if (!$this->tieneHistorial()) {
            return "CAST(NULL AS VARCHAR(50)) EDIT_USUARIO,
                    CAST(NULL AS DATETIME)    EDIT_FECHA,
                    CAST(NULL AS DATE)        EDIT_ANTERIOR,
                    CAST(NULL AS DATE)        EDIT_VALOR";
        }

        return "E.USUARIO        EDIT_USUARIO,
                E.FECHA_ALTA     EDIT_FECHA,
                E.FECHA_ANTERIOR EDIT_ANTERIOR,
                E.FECHA_NUEVA    EDIT_VALOR";
    }

    /**
     * El JOIN del rastro vigente de un campo, o cadena vacia si no hay tabla.
     *
     * El campo se toma de self::CAMPOS y no del argumento crudo: es una lista
     * cerrada del codigo, no entrada del usuario, y asi no hay literal que
     * pueda venir de afuera.
     *
     * @param string $campo 'PAGO' o 'NAC'
     * @return string
     */
    private function rastroJoin($campo) {
        if (!$this->tieneHistorial() || !isset(self::CAMPOS[$campo])) {
            return '';
        }

        return "LEFT JOIN " . self::TABLA_HISTORIAL . " E
                       ON E.ID_MG = A.ID AND E.VIGENTE = 1 AND E.CAMPO = '" . $campo . "'";
    }

    /* ====================================================================
       LO QUE YA SE PAGO

       Es una afirmacion DEL CASHFLOW sobre su propia proyeccion -"este egreso
       ya no lo esperamos"- y por eso vive del lado del cashflow y no en el
       maestro de Comercio Exterior. Ver sql/cashflow_comex_pagado.sql.
       ==================================================================== */

    /**
     * Las columnas de la marca de pagado, listas para el SELECT.
     *
     * SIN LA TABLA se piden literales con el mismo nombre y PAGADO en cero, que
     * es exactamente lo que significa: sin el script no hay nada marcado. Asi
     * el resto del metodo no tiene que preguntar si el DDL corrio. Mismo truco
     * que rastroSelect().
     *
     * @return string
     */
    private function pagadoSelect() {
        if (!$this->tienePagado()) {
            return "CAST(0 AS BIT)         PAGADO,
                    CAST(NULL AS DATETIME) PAGADO_FECHA,
                    CAST(NULL AS VARCHAR(50))  PAGADO_USUARIO,
                    CAST(NULL AS VARCHAR(200)) PAGADO_OBS";
        }

        return "CASE WHEN P.ID IS NULL THEN 0 ELSE 1 END PAGADO,
                P.FECHA_ALTA  PAGADO_FECHA,
                P.USUARIO     PAGADO_USUARIO,
                P.OBSERVACION PAGADO_OBS";
    }

    /**
     * El JOIN de la marca vigente de un concepto, o cadena vacia si no hay
     * tabla.
     *
     * El concepto sale de self::CAMPOS y no del argumento crudo: es una lista
     * cerrada del codigo, no entrada del usuario.
     *
     * @param string $concepto 'PAGO' o 'NAC'
     * @return string
     */
    private function pagadoJoin($concepto) {
        if (!$this->tienePagado() || !isset(self::CAMPOS[$concepto])) {
            return '';
        }

        return "LEFT JOIN " . self::TABLA_PAGADO . " P
                       ON P.ID_MG = A.ID AND P.VIGENTE = 1 AND P.CONCEPTO = '" . $concepto . "'";
    }

    /**
     * Le pone a una fila leida su fecha efectiva, si esta vencida y si la marca
     * de editada corresponde al valor que se ve.
     *
     * DE DONDE SALE 'EDITADA', QUE NO ES LO MISMO PARA LAS DOS FECHAS
     * ---------------------------------------------------------------
     * Para la FECHA DE PAGO sale del BIT FECHA_PAGO_CONF del maestro, que es el
     * dato de verdad sobre si esa fecha esta fijada a mano: lo prende tanto esta
     * pestana -guardarFecha()- como la pantalla de Comercio Exterior, y mientras
     * este en 1 el recalculo automatico de +5 dias no la toca. Comparar el
     * rastro contra el maestro no alcanzaba: una fecha fijada desde Comercio
     * Exterior no deja rastro de este lado y quedaba sin marcar.
     *
     * Para la FECHA DE NACIONALIZACION sigue saliendo de marcaVigente(), porque
     * no hay BIT equivalente y agregarlo esta fuera de alcance: ahi la marca
     * sigue queriendo decir "esto lo movio el cashflow", que es lo unico que se
     * puede afirmar.
     *
     * SIN EL SCRIPT 10 la fecha de pago vuelve a marcaVigente(), o sea a
     * comportarse exactamente como antes. La degradacion no cambia lo que la
     * pestana muestra hoy: solo se pierde poder marcar lo que se fijo del otro
     * lado, que es justo lo que la columna viene a agregar.
     *
     * El rastro viaja igual en RASTRO_VIGENTE, aparte de EDITADA: son dos cosas
     * -si esta fijada, y si lo que se ve lo puso el cashflow- y el front las
     * necesita separadas para elegir que tooltip mostrar.
     *
     * @param array $row Fila cruda, con las fechas ya pasadas a string
     * @param string $campoFecha Columna del maestro que manda ('FECHA_EST_PAGO'…)
     * @param string $destino Nombre del campo de fecha efectiva de la pestana
     * @param string $hoy
     * @param bool $conBitPago Si el BIT FECHA_PAGO_CONF esta disponible
     * @return array
     */
    private static function conFechaEfectiva($row, $campoFecha, $destino, $hoy, $conBitPago = false) {
        $fecha = isset($row[$campoFecha]) ? $row[$campoFecha] : null;

        $row[$destino] = $fecha;
        $row['VENCIDA'] = self::estaVencida($fecha, $hoy);

        /* Un BIT de SQL Server llega como '1'/'0' y un literal CAST(0 AS BIT)
           tambien: se normaliza a booleano acá, una sola vez, porque de este
           flag dependen el reparto en series, el filtro de la grilla y lo que
           aporta la fila al eje. Un '0' que sea verdadero en PHP sacaría del
           tablero todo lo que no está pagado. */
        $row['PAGADO'] = !empty($row['PAGADO']) && $row['PAGADO'] != '0';

        /* Si el rastro del cashflow describe el valor que se ve, o si quedo
           siendo historia porque la app de Comex movio la fecha despues. Se
           calcula siempre -las dos pestanas lo usan para el tooltip- y ademas
           es lo que decide EDITADA cuando no hay BIT. */
        $row['RASTRO_VIGENTE'] = self::marcaVigente(
            isset($row['EDIT_VALOR']) ? $row['EDIT_VALOR'] : null, $fecha);

        /* Un BIT de SQL Server llega como '1'/'0', igual que PAGADO mas arriba:
           se normaliza a booleano aca, una sola vez. */
        $row['FECHA_PAGO_CONF'] =
            !empty($row['FECHA_PAGO_CONF']) && $row['FECHA_PAGO_CONF'] != '0';

        $row['EDITADA'] = ($conBitPago && $campoFecha === 'FECHA_EST_PAGO')
            ? $row['FECHA_PAGO_CONF']
            : $row['RASTRO_VIGENTE'];

        return $row;
    }

    /**
     * Obtiene los datos de proveedores del exterior.
     *
     * LA FECHA DE PAGO SALE DEL MAESTRO -FECHA_EST_PAGO- y nada mas. Antes era
     * COALESCE(FECHA_PAGO_EDIT, FECHA_EST_PAGO), con la editada viviendo solo
     * del lado del cashflow; ver el encabezado de la clase.
     *
     * NO SE FILTRA POR FECHA DE EMBARQUE. Ese filtro escondia mas de la mitad
     * del padron, incluidos contenedores con el pago todavia por delante. Lo
     * que decide es la fecha de pago, y los vencidos se muestran marcados para
     * poder corregirles la fecha, que es lo unico que los devuelve al eje.
     *
     * Cada fila vuelve con su valuacion en pesos resuelta -IMPORTE_ARS- y con
     * QUE DOLAR se le aplico: el simbolo de la curva, el mes, la cotizacion y
     * por que es esa y no otra. Las cuatro cosas viajan juntas porque un
     * importe en pesos que no se puede atar a una cotizacion identificada no se
     * puede auditar contra nada, que es el mismo criterio de
     * Cotizacion::ultimaHasta().
     *
     * @param string|null $hoy Para poder probar el corte de vencidos sin
     *                         depender de que dia es. Por defecto, hoy.
     * @return array Listado de importaciones pendientes
     */
    public function getProveedoresExterior($hoy = null){
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        /* La columna del override es de un script posterior: si no esta, se
           pide NULL con su nombre para que el resto del metodo no tenga que
           preguntar. Es el mismo truco que ProveedoresCategorias::origenSql(),
           salvo que aca el literal es NULL y no un valor: sin la columna no hay
           ningun override cargado, y NULL es exactamente eso. */
        $cotizSql = $this->tieneCotizEdit() ? 'D.COTIZ_USD_EDIT' : 'CAST(NULL AS DECIMAL(12,4))';

        $sql = "SELECT
                    A.ID,
                    A.PROVEEDOR,
                    A.CONTENEDOR,
                    A.ORDEN_COMPRA,
                    UPPER(A.DESPACHANTE) DESPACHANTE,
                    A.VALOR_FOB_DOLAR,
                    ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD,
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM,
                    A.FECHA_ARR ETA,
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM,
                    A.FECHA_EST_PAGO,
                    " . $this->confPagoSelect() . ",
                    " . $this->rastroSelect('PAGO') . ",
                    " . $this->pagadoSelect() . ",
                    " . $cotizSql . " AS COTIZ_USD_EDIT
                FROM " . self::TABLA_MAESTRO . " A
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG
                LEFT JOIN " . self::TABLA_EDIT . " D ON A.ID = D.ID_MG
                " . $this->rastroJoin('PAGO') . "
                " . $this->pagadoJoin('PAGO') . "
                WHERE B.ID_MG IS NULL
                ORDER BY CASE WHEN A.FECHA_EST_PAGO IS NULL THEN 1 ELSE 0 END,
                         A.FECHA_EST_PAGO,
                         A.FECHA_ARR,
                         ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB)";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error en la consulta SQL: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }

        $v = [];

        // La curva se lee UNA vez para todo el listado, no una por fila.
        $curva = $this->dolarFuturo()->curva();

        /* Se resuelve UNA vez y no por fila: la existencia de la columna no
           cambia en medio de un listado, y tieneFechaPagoConf() haria una
           consulta por contenedor. */
        $conBitPago = $this->tieneFechaPagoConf();

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row = self::aTexto($row,
                ['ETD', 'ETA', 'FECHA_EST_PAGO', 'EDIT_ANTERIOR', 'EDIT_VALOR', 'EDIT_FECHA',
                 'PAGADO_FECHA', 'FECHA_PAGO_CONF_FECHA']);

            $row = self::conFechaEfectiva($row, 'FECHA_EST_PAGO', 'FECHA_PAGO_EFECTIVA', $hoy,
                                          $conBitPago);
            $row = self::valuar($row, $curva);

            /* Los dos importes derivados. Van DESPUES de valuar porque salen de
               IMPORTE_ARS, y son dos porque el reparto en series necesita las
               dos reglas por separado: ver aporteAlEje(). */
            $row['IMPORTE_PROYECTABLE'] = self::importeProyectable($row);
            $row['IMPORTE_EJE'] = self::aporteAlEje($row);

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Pasa a string las columnas de fecha que devuelve sqlsrv como DateTime.
     *
     * Existe porque los dos getters convertian la misma media docena de campos
     * con un if por campo, y cada campo nuevo era una linea mas que alguien se
     * podia olvidar. El modulo mueve fechas como string de punta a punta -ver
     * la nota de Js/notificaciones.js sobre no pasar por new Date(string)-.
     *
     * LA HORA SE CONSERVA en FECHA_ALTA y se corta en las fechas puras: una
     * datetime recortada a diez caracteres perderia a que hora se edito, que es
     * la mitad del rastro.
     *
     * @param array $row
     * @param array $campos
     * @return array
     */
    private static function aTexto($row, $campos) {
        foreach ($campos as $c) {
            if (isset($row[$c]) && $row[$c] instanceof DateTime) {
                $row[$c] = $row[$c]->format(
                    in_array($c, ['EDIT_FECHA', 'PAGADO_FECHA'], true) ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }

        return $row;
    }

    /* ====================================================================
       LAS REGLAS, PURAS

       Las tres deciden que ve el usuario y ninguna necesita la base, asi que se
       prueban sin ella. Es el mismo criterio con el que ya viven afuera de la
       consulta descartaCotizacion() y DolarFuturo::resolver().
       ==================================================================== */

    /**
     * Si una fecha efectiva ya paso.
     *
     * SIN FECHA NO ES VENCIDA. Son dos problemas distintos: una fecha vencida
     * hay que corregirla, una que falta hay que cargarla, y el aviso es otro.
     * Devolver true para las dos las juntaria en un solo numero que no sirve
     * para nada.
     *
     * @param mixed $fecha
     * @param string $hoy 'Y-m-d'
     * @return bool
     */
    public static function estaVencida($fecha, $hoy) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null) {
            return false;
        }

        return ($f < substr((string) $hoy, 0, 10));
    }

    /**
     * Cuanto vale una fila PARA PROYECTAR, mirando solo su fecha.
     *
     * UN PAGO CON LA FECHA VENCIDA NO SUMA. Es una regla de negocio, no una
     * consecuencia del eje: al cashflow entra lo que se paga de HOY EN
     * ADELANTE. Si la fecha ya paso, o el pago se hizo -y entonces no es
     * proyeccion- o no se hizo y hay que corregir la fecha. Las dos cosas son
     * gestion de Comercio Exterior sobre el dato, y hasta que alguien la haga,
     * ese importe no describe ningun movimiento futuro.
     *
     * POR QUE UN CAMPO APARTE Y NO FILTRAR LAS FILAS. Porque la fila tiene que
     * seguir viajando: la pestana la muestra -escondida detras del interruptor,
     * pero ahi- y es la unica forma de corregirle la fecha. Con un importe en
     * cero, Horizonte::agrupar() la saltea entera: no entra en ninguna columna,
     * y tampoco cae en 'fuera_horizonte', que es otra cosa -lo que quedo
     * despues del ultimo mes- y se arregla de otra manera.
     *
     * CERO Y NO null: null es "no se pudo valuar" y tiene su propio aviso, en
     * dolares. Cero es "vale, pero no entra". Son dos motivos distintos por los
     * que una celda queda vacia y la pantalla los informa por separado.
     *
     * EL IMPORTE DE ORIGEN NO SE TOCA: es la valuacion de la fila y se sigue
     * mostrando en su columna. Lo que cambia es cuanto de eso entra al periodo.
     *
     * EL CAMPO ES UN ARGUMENTO porque las dos pestanas tienen el suyo
     * -IMPORTE_ARS en Proveedores Exterior, IMPORTE_EST en Crono
     * Nacionalizacion- y la regla es la misma. Con el nombre escrito adentro,
     * la segunda pestana habria necesitado una copia de esta funcion, que es
     * como se desincronizan las reglas.
     *
     * @param array $fila Fila ya valuada, con VENCIDA resuelta
     * @param string $campoImporte De donde sale el importe de esa pestana
     * @return float|null
     */
    public static function importeProyectable($fila, $campoImporte = 'IMPORTE_ARS') {
        if (!empty($fila['VENCIDA'])) {
            return 0.0;
        }

        return (isset($fila[$campoImporte]) && $fila[$campoImporte] !== null)
            ? floatval($fila[$campoImporte])
            : null;
    }

    /**
     * Cuanto aporta una fila a LA FILA DEL TABLERO, que proyecta lo que falta
     * mover.
     *
     * Son dos reglas, y estan separadas a proposito porque el reparto en series
     * necesita las dos por separado:
     *
     *   importeProyectable()  0 si la FECHA ya paso
     *   aporteAlEje()         eso, y ademas 0 si YA SE PAGO
     *
     * UN PAGO MARCADO COMO HECHO SALE DEL FLUJO. Es lo que el tilde significa:
     * ese egreso ya no se espera. Pero el importe NO DESAPARECE DEL MODELO: el
     * proveedor lo sirve por una serie propia -PAGOS_PAGADOS- y el invariante
     * PAGOS + PAGOS_PAGADOS = PAGOS_TODO se cumple columna por columna. Es el
     * mismo criterio de la exclusion de cheques de cartera.
     *
     * SI YA ESTABA VENCIDO, marcarlo no mueve ningun numero del tablero: ya
     * valia cero. Lo que cambia es que la fila sale de la pantalla y deja de
     * pedir atencion, que es justamente para lo que se marca.
     *
     * @param array $fila Fila ya valuada, con VENCIDA y PAGADO resueltos
     * @param string $campoImporte De donde sale el importe de esa pestana
     * @return float|null
     */
    public static function aporteAlEje($fila, $campoImporte = 'IMPORTE_ARS') {
        if (!empty($fila['PAGADO'])) {
            return 0.0;
        }

        return self::importeProyectable($fila, $campoImporte);
    }

    /**
     * Si el rastro de edicion describe la fecha que se esta viendo.
     *
     * El rastro dice "el cashflow puso esta fecha". Si despues la app de
     * Comercio Exterior movio la misma columna, el rastro sigue siendo cierto
     * -alguien edito desde aca- pero ya NO explica lo que hay en la celda, y
     * marcarla como editada desde el cashflow seria atribuirle a este modulo un
     * valor que puso otro.
     *
     * Por eso la marca se calcula comparando, y no se guarda: un bit
     * persistido quedaria mintiendo desde el primer cambio hecho del otro lado,
     * que es un cambio que este modulo no ve pasar.
     *
     * @param mixed $valorEditado Lo que el cashflow escribio (FECHA_NUEVA)
     * @param mixed $valorActual Lo que dice hoy el maestro
     * @return bool
     */
    public static function marcaVigente($valorEditado, $valorActual) {
        $e = Horizonte::normalizarFecha($valorEditado);
        $a = Horizonte::normalizarFecha($valorActual);

        return ($e !== null && $e === $a);
    }

    /**
     * Los avisos por los importes cuya fecha efectiva ya venció.
     *
     * LO VENCIDO NO SE REUBICA EN HOY. Ver el encabezado de la clase: es la
     * decision que separa a estas dos pestanas de las tres de cobranza
     * proyectada, y este aviso es lo que la hace visible. Sin el, esos importes
     * caen en el 'fuera del horizonte' generico de EjeVista, donde se
     * confundirian con los que caen DESPUES del ultimo mes -que son otra cosa y
     * no se arreglan editando nada-.
     *
     * PUEDEN SER DOS AVISOS, PORQUE NO EN TODAS LAS PESTANAS LO VENCIDO QUEDA
     * AFUERA DEL CUADRO
     * ----------------------------------------------------------------------
     * En PROVEEDORES EXTERIOR es siempre uno: lo vencido no suma por regla -ver
     * aporteAlEje()- asi que ninguna fila entra en ninguna columna. El llamador
     * no pasa el eje y todas caen en la misma bolsa.
     *
     * En CRONO NACIONALIZACION esa regla no se pidio, y ahi aparece algo que no
     * es obvio: la columna del MES EN CURSO cubre los dias de ese mes que
     * quedaron fuera del tramo diario, o sea DIAS QUE YA PASARON. Una
     * nacionalizacion vencida de este mismo mes cae ahi, como cualquier otro
     * importe, y entra al tablero. Una de agosto no: queda fuera del eje.
     * Verificado contra la base el 19/09/2026: de 24 vencidas, 1 por
     * $ 55.238,12 caia en la columna de septiembre y 23 por $ 67.204,06
     * quedaban afuera.
     *
     * Un solo aviso diciendo "no suman en ninguna columna" seria falso para esa
     * primera, que es justo el error que este modulo no se permite: una nota que
     * dice lo contrario de lo que hace el codigo. Por eso el llamador que
     * necesita el reparto pasa el Horizonte, y el que no, no.
     *
     * Ese reparto lo decide Horizonte::agrupar() y NO se toca: es la regla de
     * "un importe va a un dia O a un mes" que hace sumables a las tres vistas.
     *
     * SOLO INFORMA LO VENCIDO, y no lo que no tiene fecha, aunque las dos cosas
     * queden fuera del eje. Lo segundo ya lo dicen dos avisos que existen y lo
     * dicen mejor: en Proveedores Exterior, avisosValuacion() lo informa EN
     * DOLARES -es la unica moneda en la que existe un importe que no se pudo
     * valuar-, y en Crono Nacionalizacion lo informa EjeVista con el importe en
     * pesos, que ahi si existe. Repetirlo aca daria "$ 0,00 sin fecha" al lado
     * de "U$S 164.526,47 sin fecha", que es el mismo hecho contado dos veces y
     * una de las dos mal.
     *
     * LA USAN LA PESTANA Y EL TABLERO: un solo texto, igual que
     * avisosValuacion().
     *
     * @param array $filas Filas con 'VENCIDA' resuelta
     * @param string $campoFecha Campo con la fecha efectiva
     * @param string $campoImporte Campo con el importe a informar
     * @param string $queEs Como se nombra la fecha en el mensaje
     * @param Horizonte|null $h El eje, para saber que columnas existen
     * @return array Lista de mensajes
     */
    public static function avisosVencidos($filas, $campoFecha, $campoImporte, $queEs, $h = null) {
        $afuera = 0;
        $adentro = 0;
        $impAfuera = 0.0;
        $impAdentro = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['VENCIDA'])) {
                continue;
            }

            $importe = isset($f[$campoImporte]) ? floatval($f[$campoImporte]) : 0.0;

            /* Si entro en alguna columna lo contesta el eje, fila por fila. No
               se deduce comparando la fecha contra el primer dia del tramo:
               cual mes del pasado tiene columna y cual no depende de
               horizonte_dias, que es un parametro editable. */
            if ($h !== null) {
                $serie = $h->agrupar([$f], $campoFecha, $campoImporte);

                if (array_sum($serie['dias']) + array_sum($serie['meses']) != 0) {
                    $adentro++;
                    $impAdentro += $importe;

                    continue;
                }
            }

            $afuera++;
            $impAfuera += $importe;
        }

        $avisos = [];

        /* EL TEXTO NO DICE DÓNDE ESTÁN EN LA PANTALLA, y es a propósito: este
           mismo mensaje lo muestran la pestaña y el tablero, y en Proveedores
           Exterior las vencidas además están escondidas por defecto detrás de
           un interruptor. Afirmar "están marcadas en la grilla" sería falso en
           dos de los tres casos. Qué se ve y qué no lo dice el contador que
           está al lado del interruptor; esto dice qué pasó y qué hacer. */
        /* EL TEXTO NO AFIRMA EL MOTIVO, y es a propósito: hay dos y dependen de
           la pestaña. En Proveedores Exterior lo vencido no suma POR REGLA —al
           cashflow entra lo que se paga de hoy en adelante, ver aporteAlEje()—;
           en Crono Nacionalización no suma cuando su fecha cayó fuera del eje.
           El hecho es el mismo y la acción también, así que el mensaje es uno. */
        if ($afuera > 0) {
            $avisos[] = $afuera . ' contenedor(es) por ' . self::plata($impAfuera)
                . ' tienen la ' . $queEs . ' ya vencida, así que NO suman en ninguna columna '
                . 'del período. No se los reubica en hoy, porque nadie afirmó que ese importe '
                . 'se mueve hoy: cargales la fecha nueva y entran solos.';
        }

        if ($adentro > 0) {
            $avisos[] = $adentro . ' contenedor(es) por ' . self::plata($impAdentro)
                . ' tienen la ' . $queEs . ' vencida pero dentro del mes en curso, así que SÍ '
                . 'entran, en la columna de ese mes —que cubre los días previos al tramo '
                . 'diario—. Están en el cuadro, en días que ya pasaron: es plata que todavía no '
                . 'se movió, no proyección.';
        }

        return $avisos;
    }

    /**
     * El aviso por lo que alguien marco como ya pagado.
     *
     * SE INFORMA SIEMPRE, aunque el importe ya no este en la fila del tablero
     * -precisamente por eso-. Una marca puesta en marzo que nadie recuerda es
     * exactamente lo que este aviso evita, igual que el de cheques excluidos:
     * sin el, un egreso que el tablero deberia estar proyectando desaparece y
     * no queda nada en pantalla que lo explique.
     *
     * SE MIDE SOBRE EL IMPORTE PROYECTABLE, no sobre lo que vale la fila: lo
     * que hay que informar es cuanto salio DE LA PROYECCION. Un pago marcado
     * que ademas estaba vencido ya no sumaba, asi que sacarlo no cambio ningun
     * numero y contarlo aca infliaria el aviso.
     *
     * ESTATICA Y PURA, y la usa el tablero. La pestana no la necesita: ahi el
     * conteo va al lado del interruptor, con el detalle de lo que esconde.
     *
     * @param array $filas Filas con 'PAGADO' resuelto
     * @param string $campoImporte Campo con el importe a informar
     * @param string $queEs Como se nombra el pago en el mensaje
     * @return array Lista de mensajes
     */
    public static function avisosPagados($filas, $campoImporte, $queEs) {
        $marcados = 0;
        $importe = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['PAGADO'])) {
                continue;
            }

            $marcados++;
            $importe += isset($f[$campoImporte]) ? floatval($f[$campoImporte]) : 0.0;
        }

        if ($marcados === 0) {
            return [];
        }

        return [$marcados . ' contenedor(es) tienen el ' . $queEs . ' marcado como YA HECHO, '
            . 'así que salieron de la proyección: ' . self::plata($importe) . ' que la fila '
            . 'del tablero ya no cuenta. El importe no se perdió —sale por su propia serie— y '
            . 'se destilda desde la pestaña si se marcó por error.'];
    }

    /** Un importe en pesos, con el formato del modulo */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /**
     * Le pone a una fila su importe en pesos y el dolar con el que se valuo.
     *
     * ES ESTATICA Y PURA -recibe la curva en vez de ir a buscarla- para que se
     * pueda probar sin base, que es lo que hace el resto de las reglas de este
     * modulo. La lectura de la curva queda afuera y se hace una sola vez por
     * listado.
     *
     * IMPORTE_ARS EN null NO ES CERO. Es "este pago existe y no se puede
     * valuar": sin fecha efectiva no hay mes, y sin mes no hay cotizacion. Un
     * cero se sumaria como si el contenedor no costara nada. El llamador
     * informa cuantos son y cuanto suman EN DOLARES, que es la unica moneda en
     * la que esos importes se pueden expresar.
     *
     * @param array $row Fila cruda, con FECHA_PAGO_EFECTIVA ya resuelta
     * @param array $curva Lo que devolvio DolarFuturo::curva()
     * @return array La misma fila con la valuacion agregada
     */
    public static function valuar($row, $curva) {
        $fob = isset($row['VALOR_FOB_DOLAR']) ? floatval($row['VALOR_FOB_DOLAR']) : 0;

        $cot = DolarFuturo::resolver(
            $curva,
            isset($row['FECHA_PAGO_EFECTIVA']) ? $row['FECHA_PAGO_EFECTIVA'] : null,
            isset($row['COTIZ_USD_EDIT']) ? $row['COTIZ_USD_EDIT'] : null
        );

        $row['COTIZ_USD'] = $cot['cotizacion'];
        $row['COTIZ_SIMBOLO'] = $cot['simbolo'];
        $row['COTIZ_ORIGEN'] = $cot['origen'];
        $row['COTIZ_MES'] = $cot['mes_curva'];
        $row['COTIZ_MOTIVO'] = $cot['motivo'];
        $row['COTIZ_DETALLE'] = DolarFuturo::explicar($cot);

        /* El override se devuelve normalizado -float o null- y no como lo trajo
           la base: la grilla lo usa para saber si dibujar la marca de
           "corregida a mano", y un '0.0000' de SQL Server es verdadero en PHP. */
        $row['COTIZ_USD_EDIT'] = DolarFuturo::validarCotizacion(
            isset($row['COTIZ_USD_EDIT']) ? $row['COTIZ_USD_EDIT'] : null);

        $row['IMPORTE_ARS'] = ($cot['cotizacion'] === null)
            ? null
            : round($fob * $cot['cotizacion'], 2);

        return $row;
    }

    /**
     * Los avisos de lo que no se pudo valuar o se valuo aproximando.
     *
     * VIVE ACA Y ES ESTATICA porque la necesitan los DOS consumidores: la
     * pestana -en su contenedor de avisos- y el tablero -como warning de la
     * serie-. Los dos describen exactamente las mismas filas, asi que el texto
     * tiene que ser uno solo; dos listas de mensajes parecidos se
     * desincronizan en el primer cambio, que es lo que este modulo evita en
     * todos lados.
     *
     * LOS DOS CASOS SE INFORMAN SIEMPRE. Son numeros que estan -o que no estan-
     * por una razon que no se ve mirando la celda.
     *
     * LO QUE NO SE PUDO VALUAR SE INFORMA EN DOLARES, que es la unica moneda en
     * la que existe. Decirlo en pesos exigiria valuarlo, que es justamente lo
     * que no se pudo hacer.
     *
     * @param array $filas Las filas que devolvio getProveedoresExterior()
     * @param string|null $ultimoMesCurva Hasta donde llega la curva, para el aviso
     * @return array Lista de mensajes
     */
    public static function avisosValuacion($filas, $ultimoMesCurva = null) {
        $sinValuar = 0;
        $usdSinValuar = 0.0;
        $aproximadas = 0;
        $overrides = 0;
        $avisos = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (!isset($f['COTIZ_USD']) || $f['COTIZ_USD'] === null) {
                $sinValuar++;
                $usdSinValuar += isset($f['VALOR_FOB_DOLAR'])
                    ? floatval($f['VALOR_FOB_DOLAR']) : 0;
                continue;
            }

            if ($f['COTIZ_ORIGEN'] === DolarFuturo::ORIGEN_APROXIMADA) {
                $aproximadas++;
            } elseif ($f['COTIZ_ORIGEN'] === DolarFuturo::ORIGEN_OVERRIDE) {
                $overrides++;
            }
        }

        if ($sinValuar > 0) {
            $avisos[] = $sinValuar . ' contenedor(es) por U$S '
                . number_format($usdSinValuar, 2, ',', '.') . ' no tienen fecha estimada de '
                . 'pago, así que no hay mes al que pedirle cotización y no se pueden valuar en '
                . 'pesos. No se les aplica ningún tipo de cambio inventado: cargales la fecha '
                . 'y el importe aparece.';
        }

        if ($aproximadas > 0) {
            $avisos[] = $aproximadas . ' contenedor(es) se pagan en un mes que la curva de '
                . 'dólar futuro no cubre'
                . ($ultimoMesCurva === null ? '' : ' (llega hasta ' . $ultimoMesCurva . ')')
                . ', así que se valuaron con la cotización del mes más cercano. Están marcados '
                . 'en la grilla.';
        }

        if ($overrides > 0) {
            $avisos[] = $overrides . ' contenedor(es) tienen la cotización corregida a mano, '
                . 'que manda sobre la curva. Están marcados en la grilla.';
        }

        return $avisos;
    }

    /**
     * Guarda una de las dos fechas editables ESCRIBIENDO SOBRE EL MAESTRO, y
     * deja el rastro de quien lo hizo del lado del cashflow.
     *
     * UNA SOLA FUNCION PARA LOS DOS CAMPOS. Eran dos -updateFechaPago() y
     * updateFechaNacPago()- que hacian lo mismo contra columnas distintas, y la
     * segunda se habia quedado sin la transaccion y sin los mensajes de error
     * que la primera fue ganando. Con el maestro de por medio esa divergencia
     * deja de ser cosmetica: son escrituras sobre una tabla ajena.
     *
     * TODO EN UNA TRANSACCION: el UPDATE del maestro, la baja del rastro
     * anterior, el rastro nuevo y -si corresponde- el descarte del override de
     * cotizacion. Si fueran escrituras sueltas y fallara una, el maestro
     * quedaria con una fecha que nadie puede atribuir a nadie, que es
     * exactamente el estado que esta entrega viene a terminar.
     *
     * SI NO CAMBIA NADA, NO SE ESCRIBE. Tipear la misma fecha que ya estaba no
     * es una edicion: un rastro por eso seria ruido en el historial, que es el
     * lugar donde despues hay que poder leer que paso.
     *
     * Y ESO INCLUYE AL BIT: reescribir la misma fecha NO la deja fijada. Es
     * deliberado y es la misma regla, pero tiene un borde: si la fecha que el
     * usuario quiere resulta ser la que el calculo automatico ya puso, confirmarla
     * tipeandola igual no la protege. Para fijarla hay que moverla, o hacerlo
     * desde la pantalla de Comercio Exterior. Cambiarlo significaria escribir
     * sobre el maestro sin que nada haya cambiado, que es lo que esta funcion
     * evita a proposito.
     *
     * MOVER LA FECHA DE PAGO DESDE ACA LA DEJA FIJADA. Se prende
     * FECHA_PAGO_CONF en el maestro, en el mismo UPDATE, y el recalculo
     * automatico de Comercio Exterior -embarque + 5 dias- deja de pisarla. El
     * rastro de RO_T_CASHFLOW_COMEX_FECHA_EDIT se sigue guardando igual: esa
     * tabla dice QUIEN la movio y DESDE DONDE, el BIT dice SI ESTA FIJADA, y no
     * son lo mismo. Una fecha fijada desde Comercio Exterior no deja rastro de
     * este lado y tiene que quedar protegida igual.
     *
     * NO HAY BAJAS FISICAS. Volver a editar la misma fecha marca VIGENTE = 0 la
     * anterior e inserta una nueva. Mismo criterio que
     * RO_T_CASHFLOW_ECHEQ_EXCLUIDO.
     *
     * SI CAMBIA EL MES DE PAGO, EL OVERRIDE DE COTIZACION SE DESCARTA
     * ---------------------------------------------------------------
     * Un override es una afirmacion sobre UN MES: "este pago de noviembre se
     * valua a tanto". Si el pago se corre a febrero, esa afirmacion ya no dice
     * nada sobre esta fila, y conservarla valuaria febrero con un numero que
     * alguien penso para noviembre sin que la pantalla lo indique. La curva del
     * mes nuevo es el dato que si corresponde.
     *
     * Y SE DEVUELVE, para que el front lo avise. Un descarte silencioso hace
     * que el usuario vea cambiar un importe que el no toco y no tenga donde
     * enterarse de por que. Solo aplica a 'PAGO': la nacionalizacion no se
     * valua en dolares.
     *
     * @param string $campo 'PAGO' o 'NAC'
     * @param int $idMg ID del contenedor en el maestro
     * @param mixed $fechaNueva Fecha nueva, 'Y-m-d'
     * @param string|null $usuario Quien edita. Todavia no hay login: llega null
     * @return array ['campo', 'id_mg', 'fecha_anterior', 'fecha_nueva',
     *                'sin_cambios', 'cotizacion_descartada', …]
     */
    public function guardarFecha($campo, $idMg, $fechaNueva, $usuario = null) {
        if (!$this->tieneHistorial()) {
            throw new Exception($this->avisoSinHistorial());
        }

        $campo = strtoupper(trim((string) $campo));

        /* LA VALIDACION QUE VALE ES LA DE ACA: el endpoint es alcanzable sin
           pasar por la grilla. La columna sale de la lista cerrada y nunca de
           lo que llego en el pedido. */
        if (!isset(self::CAMPOS[$campo])) {
            throw new Exception('No se sabe qué fecha hay que guardar. '
                . 'Las editables son la estimada de pago y la de nacionalización.');
        }

        $columna = self::CAMPOS[$campo];
        $idMg = intval($idMg);

        if ($idMg <= 0) {
            throw new Exception('Falta el contenedor al que corresponde la fecha.');
        }

        $nueva = Horizonte::normalizarFecha($fechaNueva);

        /* VACIO NO BORRA. A diferencia del override de cotizacion -donde vaciar
           es la unica forma de volver a la curva-, estas dos fechas son del
           maestro y las usa la otra aplicacion: dejarlas en NULL desde aca seria
           sacarle un dato a una pantalla que no es esta. Si hay que vaciarlas,
           se hace desde Comercio Exterior. */
        if ($nueva === null) {
            throw new Exception('La fecha tiene que ser una fecha válida. '
                . 'Desde el cashflow no se puede vaciar una fecha del maestro de Comercio '
                . 'Exterior: esa pantalla la usa también la otra aplicación.');
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $tieneCotiz = $this->tieneCotizEdit();

        /* Lo que dice hoy el maestro y que override tiene cargado la fila. Los
           dos deciden el resto: el primero es el valor anterior del rastro, el
           segundo si la cotizacion sobrevive al cambio de mes. */
        $stmt = sqlsrv_query($cid,
            "SELECT A." . $columna . " AS ACTUAL, "
            . ($tieneCotiz ? 'D.COTIZ_USD_EDIT' : 'CAST(NULL AS DECIMAL(12,4))') . " AS COTIZ
             FROM " . self::TABLA_MAESTRO . " A
             LEFT JOIN " . self::TABLA_EDIT . " D ON D.ID_MG = A.ID
             WHERE A.ID = ?", [$idMg]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la fecha actual del contenedor'));
        }

        $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        /* UN CONTENEDOR QUE NO ESTA NO SE DA DE ALTA. Esta pestana edita el
           padron de Comercio Exterior, no lo crea: un INSERT aca inventaria una
           importacion en el maestro de la otra aplicacion. */
        if (!$fila) {
            throw new Exception('El contenedor ' . $idMg . ' no está en '
                . self::TABLA_MAESTRO . '. Puede que lo hayan dado de baja desde Comercio '
                . 'Exterior mientras esta pantalla estaba abierta: actualizá y volvé a '
                . 'intentar.');
        }

        $anterior = Horizonte::normalizarFecha($fila['ACTUAL']);

        $r = [
            'campo' => $campo,
            'id_mg' => $idMg,
            'fecha_anterior' => $anterior,
            'fecha_nueva' => $nueva,
            'sin_cambios' => ($anterior === $nueva),
            'cotizacion_descartada' => false,
            'cotizacion_anterior' => null,
            'mes_anterior' => null,
            'mes_nuevo' => null
        ];

        if ($r['sin_cambios']) {
            return $r;
        }

        if ($campo === 'PAGO') {
            $r = array_merge($r,
                self::descartaCotizacion($anterior, $nueva, $fila['COTIZ']));
        }

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception('No se pudo abrir la transacción para guardar la fecha');
        }

        try {
            /* LA FECHA Y EL BIT, EN LA MISMA SENTENCIA.
               Mover la fecha estimada de pago desde aca ES fijarla a mano: si
               no quedara marcada, el recalculo automatico de Comercio Exterior
               -fecha de embarque + 5 dias- la pisaria en el primer guardado de
               esa pantalla, que es exactamente el problema que este BIT resuelve.

               Y va DENTRO de la transaccion, junto con el rastro, por lo mismo
               que el resto: si se escribiera aparte y fallara, el maestro
               quedaria con una fecha nueva que nadie protege.

               SOLO PARA 'PAGO'. La nacionalizacion no tiene BIT equivalente
               -esta fuera de alcance- y su marca la sigue decidiendo
               marcaVigente(). Ver conFechaEfectiva().

               Y SOLO SI EL SCRIPT 10 CORRIO: sin la columna se guarda la fecha
               igual, que es como funcionaba antes de esta entrega. */
            $marcaConf = ($campo === 'PAGO' && $this->tieneFechaPagoConf())
                ? ", FECHA_PAGO_CONF = 1,
                     FECHA_PAGO_CONF_USUARIO = ?,
                     FECHA_PAGO_CONF_FECHA = GETDATE()"
                : '';

            $params = ($marcaConf === '')
                ? [$nueva, $idMg]
                : [$nueva, $usuario, $idMg];

            $this->ejecutar($cid,
                "UPDATE " . self::TABLA_MAESTRO . "
                    SET " . $columna . " = ?" . $marcaConf . "
                  WHERE ID = ?",
                $params,
                'Error al guardar la fecha en ' . self::TABLA_MAESTRO);

            $this->ejecutar($cid,
                "UPDATE " . self::TABLA_HISTORIAL . "
                 SET VIGENTE = 0, FECHA_BAJA = GETDATE()
                 WHERE ID_MG = ? AND CAMPO = ? AND VIGENTE = 1",
                [$idMg, $campo],
                'Error al dar de baja el rastro anterior');

            $this->ejecutar($cid,
                "INSERT INTO " . self::TABLA_HISTORIAL . "
                     (ID_MG, CAMPO, FECHA_ANTERIOR, FECHA_NUEVA, VIGENTE, USUARIO, FECHA_ALTA)
                 VALUES (?, ?, ?, ?, 1, ?, GETDATE())",
                [$idMg, $campo, $anterior, $nueva, $usuario],
                'Error al guardar el rastro de la edición');

            /* El override solo se limpia si la columna existe: sin el script de
               cotizacion corrido, la instalacion guarda la fecha igual. */
            if ($tieneCotiz && $r['cotizacion_descartada']) {
                $this->ejecutar($cid,
                    "UPDATE " . self::TABLA_EDIT . "
                     SET COTIZ_USD_EDIT = NULL, FECHA_UPDATE = GETDATE()
                     WHERE ID_MG = ?",
                    [$idMg],
                    'Error al descartar la cotización cargada a mano');
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $r;
    }

    /**
     * Corre una escritura y lanza con el error de SQL Server si falla.
     *
     * Existe para que las cuatro escrituras de la transaccion de guardarFecha()
     * no sean cuatro bloques identicos de if/throw: la que se olvide el
     * chequeo dejaria la transaccion confirmando a medias sin que nadie se
     * entere.
     *
     * @param resource $cid
     * @param string $sql
     * @param array $params
     * @param string $contexto
     * @return void
     */
    private function ejecutar($cid, $sql, $params, $contexto) {
        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql($contexto));
        }

        sqlsrv_free_stmt($stmt);
    }

    /**
     * Marca -o desmarca- un pago como ya hecho.
     *
     * NO ESCRIBE EN EL MAESTRO DE COMERCIO EXTERIOR, y es la diferencia de
     * fondo con guardarFecha(). Una fecha es el mismo dato para las dos
     * aplicaciones; esto es una afirmacion DEL CASHFLOW sobre su propia
     * proyeccion -"este egreso ya no lo esperamos"- y Comex no tiene hoy ese
     * concepto. Ver sql/cashflow_comex_pagado.sql.
     *
     * NO HAY BAJAS FISICAS. Desmarcar marca VIGENTE = 0 y sella FECHA_BAJA;
     * volver a marcar inserta una fila nueva. Con un UPDATE, un tilde puesto
     * por error y corregido a los cinco minutos y una decision que estuvo
     * vigente tres semanas son indistinguibles despues del hecho, y la segunda
     * es la que explica por que el egreso proyectado del mes pasado era otro.
     *
     * LAS DOS ESCRITURAS VAN EN UNA TRANSACCION. Si la baja de la marca
     * anterior confirmara y el alta fallara, el contenedor quedaria sin marca
     * vigente y su importe volveria al tablero sin que nadie lo pidiera.
     *
     * MARCAR LO YA MARCADO NO HACE NADA, y desmarcar lo no marcado tampoco: son
     * el mismo gesto repetido, no una correccion, y una fila de historial por
     * cada clic en el mismo estado convierte el historial en ruido.
     *
     * @param string $concepto 'PAGO' o 'NAC'
     * @param int $idMg ID del contenedor en el maestro
     * @param bool $pagado Si queda marcado o no
     * @param mixed $obs Observacion opcional
     * @param string|null $usuario Quien marca. Todavia no hay login: llega null
     * @return array ['concepto', 'id_mg', 'pagado', 'sin_cambios']
     */
    public function marcarPagado($concepto, $idMg, $pagado, $obs = null, $usuario = null) {
        if (!$this->tienePagado()) {
            throw new Exception($this->avisoSinPagado());
        }

        $concepto = strtoupper(trim((string) $concepto));

        /* LA VALIDACION QUE VALE ES LA DE ACA: el endpoint es alcanzable sin
           pasar por la grilla, y un concepto que no existe dejaria una marca
           que ninguna serie descuenta. */
        if (!isset(self::CAMPOS[$concepto])) {
            throw new Exception('No se sabe qué pago hay que marcar. Los dos son el del '
                . 'proveedor del exterior y el de nacionalización.');
        }

        $idMg = intval($idMg);

        if ($idMg <= 0) {
            throw new Exception('Falta el contenedor que se quiere marcar.');
        }

        $pagado = (bool) $pagado;

        $obs = ($obs === null) ? null : trim((string) $obs);
        $obs = ($obs === '') ? null : mb_substr($obs, 0, 200);

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT ID FROM " . self::TABLA_PAGADO . "
             WHERE ID_MG = ? AND CONCEPTO = ? AND VIGENTE = 1",
            [$idMg, $concepto]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlEn(self::TABLA_PAGADO,
                'Error al verificar si el pago ya estaba marcado'));
        }

        $marcado = (bool) sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $r = [
            'concepto' => $concepto,
            'id_mg' => $idMg,
            'pagado' => $pagado,
            'sin_cambios' => ($marcado === $pagado)
        ];

        if ($r['sin_cambios']) {
            return $r;
        }

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception('No se pudo abrir la transacción para marcar el pago');
        }

        try {
            $this->ejecutar($cid,
                "UPDATE " . self::TABLA_PAGADO . "
                 SET VIGENTE = 0, FECHA_BAJA = GETDATE()
                 WHERE ID_MG = ? AND CONCEPTO = ? AND VIGENTE = 1",
                [$idMg, $concepto],
                'Error al dar de baja la marca anterior');

            if ($pagado) {
                $this->ejecutar($cid,
                    "INSERT INTO " . self::TABLA_PAGADO . "
                         (ID_MG, CONCEPTO, OBSERVACION, VIGENTE, USUARIO, FECHA_ALTA)
                     VALUES (?, ?, ?, 1, ?, GETDATE())",
                    [$idMg, $concepto, $obs, $usuario],
                    'Error al marcar el pago');
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $r;
    }

    /**
     * El historial de marcas de un contenedor, la vigente primero.
     *
     * LAS NO VIGENTES SON EL PUNTO, igual que en getHistorialFechas(): son lo
     * unico que explica por que el egreso proyectado de la semana pasada era
     * otro.
     *
     * @param int $idMg
     * @param string|null $concepto 'PAGO', 'NAC' o null para los dos
     * @return array
     */
    public function getHistorialPagado($idMg, $concepto = null) {
        if (!$this->tienePagado()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $concepto = ($concepto === null) ? null : strtoupper(trim((string) $concepto));
        $params = [intval($idMg)];
        $filtro = '';

        if ($concepto !== null && isset(self::CAMPOS[$concepto])) {
            $filtro = ' AND CONCEPTO = ?';
            $params[] = $concepto;
        }

        $stmt = sqlsrv_query($cid,
            "SELECT ID, ID_MG, CONCEPTO, OBSERVACION, VIGENTE, USUARIO, FECHA_ALTA, FECHA_BAJA
             FROM " . self::TABLA_PAGADO . "
             WHERE ID_MG = ?" . $filtro . "
             ORDER BY VIGENTE DESC, FECHA_ALTA DESC, ID DESC", $params);

        if ($stmt === false) {
            throw new Exception($this->errorSqlEn(self::TABLA_PAGADO,
                'Error al leer el historial de pagos marcados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach (['FECHA_ALTA', 'FECHA_BAJA'] as $c) {
                if (isset($row[$c]) && $row[$c] instanceof DateTime) {
                    $row[$c] = $row[$c]->format('Y-m-d H:i:s');
                }
            }

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * El historial completo de ediciones de un contenedor, el vigente primero.
     *
     * LAS NO VIGENTES SON EL PUNTO: con un UPDATE, un dedazo corregido a los
     * cinco minutos y una decision que estuvo vigente tres semanas son
     * indistinguibles despues del hecho, y la segunda es la que explica por que
     * el egreso proyectado de la semana pasada caia en otra columna.
     *
     * @param int $idMg
     * @param string|null $campo 'PAGO', 'NAC' o null para los dos
     * @return array
     */
    public function getHistorialFechas($idMg, $campo = null) {
        if (!$this->tieneHistorial()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $campo = ($campo === null) ? null : strtoupper(trim((string) $campo));
        $params = [intval($idMg)];
        $filtro = '';

        if ($campo !== null && isset(self::CAMPOS[$campo])) {
            $filtro = ' AND CAMPO = ?';
            $params[] = $campo;
        }

        $stmt = sqlsrv_query($cid,
            "SELECT ID, ID_MG, CAMPO, FECHA_ANTERIOR, FECHA_NUEVA, VIGENTE,
                    USUARIO, FECHA_ALTA, FECHA_BAJA
             FROM " . self::TABLA_HISTORIAL . "
             WHERE ID_MG = ?" . $filtro . "
             ORDER BY VIGENTE DESC, FECHA_ALTA DESC, ID DESC", $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de fechas'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row = self::aTexto($row, ['FECHA_ANTERIOR', 'FECHA_NUEVA']);

            foreach (['FECHA_ALTA', 'FECHA_BAJA'] as $c) {
                if (isset($row[$c]) && $row[$c] instanceof DateTime) {
                    $row[$c] = $row[$c]->format('Y-m-d H:i:s');
                }
            }

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Si al mover la fecha de pago hay que descartar el override de cotizacion.
     *
     * Estatica y pura: es la regla, y se prueba sin base. Ver la nota de
     * guardarFecha() sobre por que un override no sobrevive a un cambio de mes.
     *
     * SIN OVERRIDE NO HAY NADA QUE DESCARTAR, aunque cambie el mes. Y si la
     * fecha nueva no tiene mes utilizable, tampoco se descarta: no se pudo
     * afirmar que cambio de mes, asi que no se borra el trabajo de nadie.
     *
     * @param mixed $fechaAntes Fecha efectiva de pago antes del cambio
     * @param mixed $fechaDespues Fecha efectiva de pago despues del cambio
     * @param mixed $override Cotizacion cargada a mano, o null
     * @return array ['cotizacion_descartada', 'cotizacion_anterior', 'mes_anterior', 'mes_nuevo']
     */
    public static function descartaCotizacion($fechaAntes, $fechaDespues, $override) {
        $ov = DolarFuturo::validarCotizacion($override);
        $mesAntes = DolarFuturo::mesDe($fechaAntes);
        $mesDespues = DolarFuturo::mesDe($fechaDespues);

        return [
            'cotizacion_descartada' => ($ov !== null
                && $mesAntes !== null && $mesDespues !== null
                && $mesAntes !== $mesDespues),
            'cotizacion_anterior' => $ov,
            'mes_anterior' => $mesAntes,
            'mes_nuevo' => $mesDespues
        ];
    }

    /**
     * Guarda -o borra- la cotizacion cargada a mano para un contenedor.
     *
     * UN VALOR VACIO BORRA EL OVERRIDE y la fila vuelve a la curva. Es la unica
     * forma de deshacer una correccion, y tiene que ser la misma accion: un
     * endpoint aparte para borrar seria un segundo camino que hace lo contrario
     * del primero, y la pantalla tendria que decidir cual llamar.
     *
     * NO TOCA LA TABLA MAESTRA. FP_DOLAR_FUTURO_ROFEX es de solo lectura para
     * este modulo: el override vive en la tabla del cashflow y afecta a UN
     * contenedor, no a todos los del mes.
     *
     * @param int $idMg ID del maestro de importación
     * @param mixed $cotizacion Cotizacion, o vacio para volver a la curva
     * @return array ['cotizacion' => float|null, 'id_mg' => int]
     */
    public function updateCotizacion($idMg, $cotizacion) {
        if (!$this->tieneCotizEdit()) {
            throw new Exception('La corrección manual de la cotización está apagada: falta la '
                . 'columna COTIZ_USD_EDIT. Corré sql/cashflow_comex_cotiz_edit.sql contra la '
                . 'base central. Mientras tanto los pagos se valúan con la curva de dólar '
                . 'futuro, que es el criterio por defecto.');
        }

        $idMg = intval($idMg);

        if ($idMg <= 0) {
            throw new Exception('Falta el contenedor al que corresponde la cotización.');
        }

        /* LA VALIDACION QUE VALE ES LA DE ACA. La pantalla acota lo que se
           puede tipear, pero este endpoint es alcanzable sin pasar por ella.
           Se distingue "vacio" -que borra- de "no numerico" -que es un error-,
           porque validarCotizacion() devuelve null en los dos casos. */
        $vacio = ($cotizacion === null || trim((string) $cotizacion) === '');
        $valor = $vacio ? null : DolarFuturo::validarCotizacion($cotizacion);

        if (!$vacio && $valor === null) {
            throw new Exception('La cotización tiene que ser un número mayor a cero. '
                . 'Dejala vacía si querés que este contenedor vuelva a valuarse con la curva '
                . 'de dólar futuro.');
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmtCheck = sqlsrv_query($cid,
            "SELECT ID FROM " . self::TABLA_EDIT . " WHERE ID_MG = ?", [$idMg]);

        if ($stmtCheck === false) {
            throw new Exception($this->errorSql('Error al verificar registro existente'));
        }

        $existe = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        if ($existe) {
            $stmt = sqlsrv_query($cid,
                "UPDATE " . self::TABLA_EDIT . "
                 SET COTIZ_USD_EDIT = ?, FECHA_UPDATE = GETDATE()
                 WHERE ID_MG = ?",
                [$valor, $idMg]);
        } else {
            /* LOS CENTINELAS SIGUEN HACIENDO FALTA aunque nadie lea ya esas
               columnas: FECHA_NAC_ORIG y FECHA_NAC_EDIT nacieron NOT NULL, y
               esta fila se inserta solo para guardar el override. Vaciarlas
               seria un ALTER sobre una tabla que este modulo no necesita
               cambiar, y ponerles una fecha real inventaria una edicion. */
            $stmt = sqlsrv_query($cid,
                "INSERT INTO " . self::TABLA_EDIT . "
                     (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, COTIZ_USD_EDIT, FECHA_UPDATE)
                 VALUES (?, '1900-01-01', '1900-01-01', ?, GETDATE())",
                [$idMg, $valor]);
        }

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la cotización'));
        }

        sqlsrv_free_stmt($stmt);

        return ['id_mg' => $idMg, 'cotizacion' => $valor];
    }


    /**
     * Obtiene los datos del cronograma de nacionalización.
     *
     * SE LISTA POR FECHA DE NACIONALIZACION, que es la que decide cuándo
     * impacta el gasto. Antes el orden era COALESCE(FECHA_NAC_EDIT,
     * FECHA_DESP_ADU, FECHA_ARR, FECHA_EMB, FECHA_EST_EMB) y el corte era por
     * fecha de embarque: la tabla se leia por una fecha y se ordenaba por
     * cualquiera de cinco, asi que dos contenedores con la misma
     * nacionalizacion podian quedar en cualquier orden entre si.
     *
     * Ahora manda FECHA_DESP_ADU -del maestro- y nada mas. La de embarque queda
     * como la ultima desempatadora: es informacion de la fila, no el criterio.
     *
     * SIN FECHA DE NACIONALIZACION LA FILA NO SE PIERDE: va al final del
     * listado y su importe se informa aparte. Hoy todos los contenedores la
     * tienen -la calcula la app de Comercio Exterior- pero una fila que
     * desaparece porque le falta un dato es justamente lo que este modulo evita
     * en todos lados.
     *
     * @param string|null $hoy Para poder probar el corte de vencidos sin
     *                         depender de que dia es. Por defecto, hoy.
     * @return array Listado de importaciones con fechas de nacionalización
     */
    public function getCronoNacionalizacion($hoy = null) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        $sql = "SELECT
                    A.ID,
                    A.FECHA_EST_EMB,
                    A.PROVEEDOR,
                    A.CONTENEDOR,
                    A.ORDEN_COMPRA,
                    UPPER(A.DESPACHANTE) DESPACHANTE,
                    C.IMPORTE_EST,
                    A.FECHA_EMB ETD,
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM,
                    A.FECHA_ARR ETA,
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM,
                    A.FECHA_DESP_ADU FECHA_NAC,
                    /* El BIT viaja tambien en esta pestana aunque la marca de
                       editada de la nacionalizacion no salga de el: la fila es
                       el mismo contenedor y la columna de fecha de pago se
                       muestra en las dos grillas. */
                    " . $this->confPagoSelect() . ",
                    " . $this->rastroSelect('NAC') . ",
                    " . $this->pagadoSelect() . "
                FROM " . self::TABLA_MAESTRO . " A
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG
                LEFT JOIN
                (
                    SELECT ID_MG, SUM(IMPORTE) IMPORTE_EST
                    FROM RO_T_IMPORTACIONES_ESTIMACION_DETALLE
                    WHERE ID_CE BETWEEN 3 AND 10
                    GROUP BY ID_MG
                ) C ON A.ID = C.ID_MG
                " . $this->rastroJoin('NAC') . "
                " . $this->pagadoJoin('NAC') . "
                WHERE B.ID_MG IS NULL
                ORDER BY CASE WHEN A.FECHA_DESP_ADU IS NULL THEN 1 ELSE 0 END,
                         A.FECHA_DESP_ADU,
                         ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB)";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error en la consulta SQL: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row = self::aTexto($row, ['FECHA_EST_EMB', 'ETD', 'ETA', 'FECHA_NAC',
                'EDIT_ANTERIOR', 'EDIT_VALOR', 'EDIT_FECHA', 'PAGADO_FECHA',
                'FECHA_PAGO_CONF_FECHA']);

            $row = self::conFechaEfectiva($row, 'FECHA_NAC', 'FECHA_NAC_EFECTIVA', $hoy);

            /* Mismas dos reglas que en Proveedores Exterior: al cashflow entra
               lo que se mueve de hoy en adelante y lo que todavia no se pago.
               Ver aporteAlEje(). */
            $row['IMPORTE_PROYECTABLE'] = self::importeProyectable($row, 'IMPORTE_EST');
            $row['IMPORTE_EJE'] = self::aporteAlEje($row, 'IMPORTE_EST');

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors().
     *
     * Nombra la tabla, igual que el resto del modulo: el aviso es lo unico que
     * le dice a alguien contra que objeto fallo la escritura. Esta clase
     * escribe sobre CUATRO tablas -el maestro de Comex, el rastro de fechas, el
     * de pagados y la de la cotizacion-, asi que un mensaje que nombrara
     * siempre la misma mandaria a mirar el objeto equivocado.
     *
     * @param string $tabla
     * @param string $contexto
     * @return string
     */
    private function errorSqlEn($tabla, $contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . $tabla . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }

    /**
     * Como errorSqlEn(), para la tabla propia del modulo.
     *
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        return $this->errorSqlEn(self::TABLA_EDIT, $contexto);
    }

}
