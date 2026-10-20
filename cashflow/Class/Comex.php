<?php

require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * Comex
 * Los datos de Comercio Exterior: los pagos a proveedores del exterior y el
 * cronograma de nacionalizacion.
 *
 * LA CONSUMEN DOS PESTANAS Y EL TABLERO, y por eso los dos getters devuelven
 * las filas crudas mas lo que se deriva de ellas: quien las muestra y quien las
 * agrupa tienen que estar mirando exactamente los mismos numeros.
 *
 * LAS DOS PESTANAS ESTAN EN DOLARES, Y LAS DOS SE VALUAN ACA
 * ----------------------------------------------------------
 * VALOR_FOB_DOLAR esta en dolares y el cashflow es en pesos, asi que alguien
 * tiene que convertir. Antes lo hacia ComexProvider con UN parametro global
 * -'comex_tipo_cambio_usd'- aplicado a todas las filas por igual, y la pestana
 * no convertia nada: mostraba dolares. Eran dos pantallas del mismo modulo
 * midiendo cosas distintas.
 *
 * Ahora cada fila se valua con la CURVA DE DOLAR FUTURO ROFEX segun el mes de
 * su fecha efectiva, y la conversion vive en los dos getters: la pestana y el
 * tablero leen el mismo IMPORTE_ARS, calculado una sola vez. La regla de que
 * cotizacion le toca a cada fila es pura y vive en DolarFuturo, donde esta el
 * por que completo.
 *
 * Y EL GASTO DE NACIONALIZACION TAMBIEN ESTA EN DOLARES. Hasta
 * feature/comex-nac-usd este archivo y ComexProvider afirmaban lo contrario
 * -"ya estan en pesos, no hay conversion"- y era falso: IMPORTE_EST suma los
 * conceptos 3 a 10 de RO_T_IMPORTACIONES_ESTIMACION_DETALLE, que la pantalla de
 * Comercio Exterior calcula como porcentajes del CIF, y el CIF arranca en
 * VALOR_FOB_DOLAR. Verificado contra la base el 21/09/2026: el cociente
 * IMPORTE_EST / VALOR_FOB_DOLAR va de 0,71 a 1,04 en los 12 contenedores con
 * estimacion cargada, o sea una fraccion del FOB en la MISMA moneda; si
 * estuviera en pesos daria del orden de mil. El tablero venia ubicando dolares
 * en columnas de pesos.
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
 * el dato. Lo implementa aporteAlEje() y vale en LAS DOS PESTANAS: la regla se
 * sumo a Crono Nacionalizacion en feature/comex-pagado, y esta nota decia hasta
 * feature/comex-nac-usd que alla las vencidas se agrupaban como cualquier otra.
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
 * LO QUE SE PROYECTA ES LO QUE FALTA PAGAR, NO EL FOB
 * ---------------------------------------------------
 * Esto CAMBIO con feature/comex-saldo-pendiente. La app de Comercio Exterior
 * permite pagos parciales al proveedor del exterior -los guarda en
 * RO_T_IMPORTACIONES_ENCABEZADO_PAGOS, en DOLARES- y el cashflow proyectaba el
 * VALOR_FOB_DOLAR entero, como si no se hubiera pagado nada. Un contenedor con
 * el 80% anticipado entraba al tablero por el 100%.
 *
 * Ahora la fila proyecta el SALDO, con la regla de Comex y ni una coma mas:
 *
 *     pendiente U$S = VALOR_FOB_DOLAR - SUM(PAGOS.MONTO)
 *
 * Es literalmente la cuenta de Pagos::obtenerResumen() del repo administracion,
 * replicada aca -no incluida- porque son DOS APLICACIONES Y DOS DESPLIEGUES: el
 * cashflow no puede requerir un archivo que vive en otro repo. Lo que si tiene
 * que dar es EL MISMO NUMERO, y por eso la regla vive en saldoPendiente(), que
 * es pura, se prueba sin base y copia hasta la tolerancia de un centavo.
 *
 * SI LA REGLA DEL SALDO CAMBIA ALLA, HAY QUE CAMBIARLA ACA. No hay forma de que
 * el codigo lo detecte solo: son dos repos. El encabezado de Pagos.php del otro
 * lado lo dice, y esta nota es la otra mitad del pacto.
 *
 * Y EL PACTO TIENE UNA SEGUNDA CLAUSULA desde feature/comex-visibilidad-saldo:
 * CUANDO UN CONTENEDOR DEJA DE VERSE. Gestion de Despachos (alla) y Proveedores
 * Exterior (aca) lo sacan solo cuando tiene costos de nacionalizacion cargados
 * Y los pagos cubren el FOB, evaluado por grupo. Aca vive en
 * sigueEnProveedores() y sqlSigueVisible(); alla en VisibilidadContenedor. Si
 * cambia de un lado, se cambia del otro: si no, un contenedor estaria en una
 * pantalla y no en la otra sin que nadie pueda explicar por que.
 *
 * EL FOB Y LOS PAGOS SALEN DE LA OC PRINCIPAL -COALESCE(ID_PADRE, ID)-, igual
 * que en Comex: un contenedor con varias ordenes de compra tiene UN pago al
 * proveedor, no uno por orden. Al 22/09/2026 el padron de central no tiene NI
 * UNA hija -los 76 contenedores del listado son principales- asi que hoy ese
 * COALESCE no cambia ninguna fila; se escribe igual porque la columna existe y
 * el dia que aparezca una hija la cuenta tiene que dar lo mismo de los dos
 * lados. Ver la nota de DUPLICA_GRUPO en getProveedoresExterior(), que es lo
 * que impide que ese dia el contenedor se cuente dos veces.
 *
 * TRES COSAS QUE SE SIGUEN DE ESTO, y que estan donde dice cada una:
 *
 *   - SOBREPAGO: si lo cargado supera al FOB, el pendiente es CERO y no
 *     negativo. El cashflow no proyecta egresos negativos, y la diferencia se
 *     informa aparte porque es un dato a corregir en Comex.
 *   - SALDO CERO: un contenedor cancelado sale del flujo SOLO, sin que nadie
 *     lo tilde, porque su pendiente vale cero. El tilde manual sigue existiendo
 *     para lo que no se cargo en Comex.
 *   - EL IMPORTE NO DESAPARECE DEL MODELO. Lo que los pagos de Comex sacaron de
 *     la proyeccion se sirve por una serie propia -PAGOS_COMEX- igual que el
 *     tilde se sirve por PAGOS_PAGADOS, y el invariante pasa a ser
 *     PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO, con PAGOS_TODO
 *     valiendo el FOB completo. Ver aporteAlEje() y ComexProvider.
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
     * Los pagos al proveedor del exterior, de la plataforma Comex.
     *
     * EL CASHFLOW LA LEE Y NUNCA LA ESCRIBE. Los pagos se cargan en Comercio
     * Exterior, que es el dueno del circuito; de este lado son el dato que dice
     * cuanto falta pagar. MONTO esta en DOLARES desde
     * comercioExterior/sql/08_pagos_en_dolares.sql.
     */
    const TABLA_PAGOS = 'RO_T_IMPORTACIONES_ENCABEZADO_PAGOS';

    /**
     * Un centavo. Es LA MISMA tolerancia de Pagos::obtenerResumen() en Comercio
     * Exterior, y esta copiada a proposito: los pagos se cargan redondeados a
     * dos decimales y la suma de varios parciales casi nunca da exacta, asi que
     * sin ella un contenedor efectivamente cancelado quedaria proyectando
     * "U$S 0,01" para siempre. Si alla se mueve, se mueve aca.
     */
    const TOLERANCIA_SALDO = 0.01;

    /**
     * Los cuatro estados de pago, con los nombres EXACTOS de Comercio Exterior.
     *
     * No son sinonimos elegidos de nuevo: son los que devuelve
     * Pagos::obtenerResumen(), y que las dos aplicaciones nombren igual el
     * mismo estado es lo que permite comparar una pantalla contra la otra sin
     * un diccionario en el medio.
     */
    const ESTADO_SIN_FOB   = 'SIN_FOB';
    const ESTADO_PENDIENTE = 'PENDIENTE';
    const ESTADO_CANCELADO = 'CANCELADO';
    const ESTADO_SOBREPAGO = 'SOBREPAGO';

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

    /** @var bool|null Cache de si existe la tabla de pagos de Comercio Exterior */
    private $pagosComex = null;

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
     * Si existe la tabla de pagos de Comercio Exterior.
     *
     * ES DE LA OTRA PLATAFORMA Y ESTA DESDE SIEMPRE, asi que preguntar parece
     * de mas. No lo es: son DOS REPOS Y DOS DESPLIEGUES, esta pestana ya lee
     * dos cosas de Comex que pueden no estar -FECHA_PAGO_CONF, del script 10- y
     * nombrar una tabla ausente rompe la pantalla entera con "Invalid object
     * name", que es un error que no se ve hasta que alguien la abre.
     *
     * SIN LA TABLA el pendiente vale el FOB completo, o sea exactamente lo que
     * esta pestana mostraba antes de feature/comex-saldo-pendiente, Y SE AVISA:
     * un tablero que proyecta de mas sin decirlo es peor que uno que falla. Ver
     * avisoSinPagosComex().
     *
     * Mismo patron que tienePagado() y tieneCotizEdit().
     *
     * @return bool
     */
    public function tienePagosComex() {
        if ($this->pagosComex !== null) {
            return $this->pagosComex;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_PAGOS . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception('Error al verificar la tabla de pagos de Comercio Exterior');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->pagosComex = ($row && $row['T'] !== null);

        return $this->pagosComex;
    }

    /**
     * El aviso de que no se puede leer lo ya pagado en Comex, o cadena vacia.
     *
     * NO ES UN AVISO DE CONFIGURACION COMO LOS OTROS DOS. Los otros apagan una
     * funcion -editar una fecha, marcar un tilde- y todo lo demas sigue igual.
     * Este cambia LOS NUMEROS: sin la tabla el tablero vuelve a proyectar el FOB
     * completo, que es plata que puede haber salido ya. Por eso el texto dice
     * que se esta proyectando de mas, y no solo que falta algo.
     *
     * @return string
     */
    public function avisoSinPagosComex() {
        if ($this->tienePagosComex()) {
            return '';
        }

        return 'No se encuentra la tabla ' . self::TABLA_PAGOS . ' de Comercio Exterior, así '
            . 'que no se puede saber qué se le pagó ya a cada proveedor del exterior: esta '
            . 'pantalla y la fila del tablero proyectan el FOB COMPLETO de cada contenedor, '
            . 'que es de más si hay pagos hechos. Es una tabla de la otra aplicación: '
            . 'revisalo con quien administra la base central.';
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

    /* ====================================================================
       LOS AVISOS, ESCRITOS UNA SOLA VEZ

       Los leen el tablero -ComexProvider- y las dos pestanas. Hasta
       feature/cashflow-avisos-kpis-comex-nac-parcial cada controller armaba
       su lista con las funciones sueltas de abajo, y se habian separado del
       proveedor: la pestana no decia lo marcado como ya hecho, no mandaba
       nivel, y la curva caida se contaba con otras palabras. Ahora la pestana
       muestra tambien los avisos del tablero (Cashflow::avisosDePestana()), y
       para que no salgan dos veces el texto tiene que ser el mismo: por eso la
       lista es una, con nivel, y la pestana solo le agrega lo que es suyo
       -los scripts que apagan una edicion-.
       ==================================================================== */

    /**
     * El aviso de que la curva no se pudo leer, o null.
     *
     * UN TEXTO PARA LAS DOS PANTALLAS. El de la pestana decia "se muestran sin
     * valuar en pesos" y el del tablero "van en cero": las dos cosas pasan a
     * la vez, asi que el texto dice las dos. Critico: la fila entera en cero
     * por falta de cotizacion, y nadie lo decidio.
     *
     * @param string $queVa 'los pagos al exterior' o 'los gastos de nacionalización'
     * @return array|null ['nivel', 'texto', 'seccion']
     */
    public function avisoSinCurva($queVa) {
        $dolar = $this->dolarFuturo();

        if ($dolar->disponible()) {
            return null;
        }

        return Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la curva de dólar futuro ROFEX ('
            . DolarFuturo::ORIGEN . '), así que ' . $queVa . ' no se pueden pasar a pesos: la '
            . 'pestaña los muestra en dólares y la fila del tablero va en cero. Los importes en '
            . 'dólares están: lo que falta es a cuánto convertirlos. '
            . ($dolar->error() === null ? '' : $dolar->error()));
    }

    /**
     * Los avisos de los pagos al exterior, con nivel: los del tablero y de la
     * pestana Proveedores Exterior.
     *
     * EL ORDEN ES EL DE EMISION DEL PROVEEDOR, y dentro de cada nivel es el
     * orden en que se leen: el panel ordena por gravedad y respeta este orden
     * entre los iguales.
     *
     * SOBRE EL PENDIENTE, no sobre el FOB: lo que no se pudo valuar es lo que
     * esta fila proyecta. Con VALOR_FOB_DOLAR el aviso diria de mas justamente
     * en los contenedores que ya tienen pagos hechos.
     *
     * Los niveles: lo vencido y lo sin fecha se arreglan cargando una fecha
     * (atencion); lo marcado, lo pagado en Comercio Exterior y la orden que
     * comparte contenedor son criterios ya aplicados (informativo); el
     * sobrepago hay que ir a corregirlo a Comex (atencion); y sin la tabla de
     * pagos se proyecta de mas sin que nadie lo decida (critico).
     *
     * @param array $filas Las de getProveedoresExterior()
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function avisosExteriorConNivel($filas) {
        $avisos = array_merge(
            self::avisosValuacionConNivel($filas, $this->dolarFuturo()->ultimoMes(),
                'PENDIENTE_USD'),
            /* Sin el eje: ninguna vencida entra en ninguna columna. Se informa
               IMPORTE_ARS -lo que valen-; IMPORTE_EJE daria "$ 0,00". */
            self::avisosVencidosConNivel($filas, 'FECHA_PAGO_EFECTIVA', 'IMPORTE_ARS',
                'fecha estimada de pago'),
            Aviso::lista(self::avisosPagados($filas, 'IMPORTE_PROYECTABLE', 'pago'), Aviso::INFO),
            Aviso::lista(self::avisosSaldoComex($filas), Aviso::INFO),
            Aviso::lista(self::avisosSobrepago($filas), Aviso::WARNING),
            Aviso::lista(self::avisosGrupo($filas), Aviso::INFO)
        );

        if ($this->avisoSinPagosComex() !== '') {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, $this->avisoSinPagosComex());
        }

        return $avisos;
    }

    /**
     * Los avisos de la nacionalizacion, con nivel: los del tablero y de la
     * pestana Crono Nacionalizacion. Mismo criterio que
     * avisosExteriorConNivel().
     *
     * El campo del importe en dolares y el nombre de la fecha son los de esta
     * pestana: con los de la otra, el aviso diria "U$S 0,00" y nombraria la
     * fecha de pago.
     *
     * @param array $filas Las de getCronoNacionalizacion()
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function avisosNacionalizacionConNivel($filas) {
        $filas = is_array($filas) ? $filas : [];

        /* LA VALUACION Y LA ESTIMACION QUE FALTA SON DE LAS FILAS ESTIMADAS, y
           de las titulares. Las que tienen costos reales -que desde el pago
           parcial vuelven a la lista- no se valuan con la curva: su base ya esta
           en pesos. Contarlas cambiaria estos avisos sin que nada haya cambiado.
           Lo marcado, lo mismo: un contenedor con costos reales y sin pagos
           parciales no suma con o sin tilde. */
        $estimadas = array_values(array_filter($filas, function ($f) {
            return empty($f['TIENE_COSTOS']) && empty($f['DUPLICA_GRUPO']);
        }));
        $proyectables = array_values(array_filter($filas, function ($f) {
            return empty($f['NAC_SIN_PROYECCION']) && empty($f['DUPLICA_GRUPO']);
        }));

        /* LO VENCIDO ES EL SALDO: su fecha es la de nacionalizacion, salvo que
           tenga pagos parciales y una fecha del saldo cargada. Sin pagos
           parciales el aviso dice exactamente lo de antes; si alguno de los
           vencidos tiene pagos parciales, lo nombra. */
        $conSaldo = false;

        foreach ($proyectables as $f) {
            if (!empty($f['NAC_CON_PARCIALES']) && self::vencidaPendiente($f)) {
                $conSaldo = true;
            }
        }

        return array_merge(
            self::avisosValuacionConNivel($estimadas, $this->dolarFuturo()->ultimoMes(),
                'IMPORTE_EST', 'fecha de nacionalización'),
            self::avisosVencidosConNivel($proyectables, 'FECHA_SALDO_EFECTIVA', 'SALDO_ARS',
                $conSaldo ? 'fecha de nacionalización —o la del saldo, si tienen pagos parciales—'
                          : 'fecha de nacionalización'),
            Aviso::lista(self::avisosPagados($proyectables, 'IMPORTE_PROYECTABLE', 'nacionalización'),
                Aviso::INFO),
            self::avisosPagosParcialesConNivel($filas),
            self::avisosSinEstimacionConNivel($estimadas)
        );
    }

    /**
     * Un cero no dice si no hay contenedores o si los hay sin importe cargado.
     *
     * SE MIDE SOBRE EL IMPORTE DE ORIGEN Y NO SOBRE LA SERIE: la serie puede
     * dar cero porque todos los contenedores estan vencidos, que es otra cosa
     * completamente. Midiendo la serie, ese caso diria "ninguno tiene gastos
     * cargados" sobre contenedores que si los tienen.
     *
     * Atencion: se arregla cargando la estimacion de gastos. Antes lo emitia
     * solo el proveedor y terminaba en "Es lo mismo que muestra la pestana
     * Crono Nacionalizacion", que no era cierto: la pestana no lo decia.
     *
     * @param array $filas
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosSinEstimacionConNivel($filas) {
        $filas = is_array($filas) ? $filas : [];
        $total = 0.0;

        foreach ($filas as $f) {
            $total += isset($f['IMPORTE_EST']) ? floatval($f['IMPORTE_EST']) : 0.0;
        }

        if (count($filas) === 0 || $total != 0) {
            return [];
        }

        return [Aviso::nuevo(Aviso::WARNING, 'Hay ' . count($filas) . ' contenedores en el '
            . 'cronograma pero ninguno tiene gastos de nacionalización estimados cargados, así '
            . 'que la fila va en cero.')];
    }

    /**
     * Lo que apaga una edicion de la pestana Proveedores Exterior: solo de la
     * pestana, porque el tablero no edita nada.
     *
     * LO QUE FALTA SE DICE, AUNQUE NO ROMPA NADA. Mismo criterio que
     * ProveedoresCategorias::getAvisos(): una funcion que desaparece sin decir
     * por que es indistinguible de una que no se construyo. Se avisan por
     * separado porque cada uno apaga una cosa distinta.
     *
     * Atencion: un script que falta y solo apaga la edicion. La tabla de pagos
     * de Comex -que cambia los numeros- no esta aca: es critica y la dice
     * avisosExteriorConNivel(), igual que el tablero.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function avisosEdicionExteriorConNivel() {
        $avisos = Aviso::lista(array_values(array_filter(
            [$this->avisoSinHistorial(), $this->avisoSinPagado()])), Aviso::WARNING);

        /* Sin curva no hay nada que corregir: el override pisa una cotizacion
           que no se pudo leer. El aviso de la curva ya lo dice. */
        if ($this->dolarFuturo()->disponible() && !$this->tieneCotizEdit()) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'La corrección manual de la cotización '
                . 'está apagada: falta la columna COTIZ_USD_EDIT. Corré '
                . 'sql/cashflow_comex_cotiz_edit.sql contra la base central y la columna se '
                . 'vuelve editable sola. Todo lo demás de esta pantalla funciona igual: los pagos '
                . 'se valúan con la curva.');
        }

        return $avisos;
    }

    /**
     * Lo que apaga una edicion de la pestana Crono Nacionalizacion.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public function avisosEdicionNacionalizacionConNivel() {
        return Aviso::lista(array_values(array_filter(
            [$this->avisoSinHistorial(), $this->avisoSinPagado(), $this->avisoSinNacPago()])),
            Aviso::WARNING);
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

        return "E.USUARIO_ALTA   EDIT_USUARIO,
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
                P.USUARIO_ALTA PAGADO_USUARIO,
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

    /* ====================================================================
       LO QUE FALTA PAGAR

       El FOB y los pagos salen de la OC PRINCIPAL -COALESCE(ID_PADRE, ID)-,
       que es la regla de Comercio Exterior: ver encabezado.php::
       resolverIdPrincipal() y Pagos::obtenerResumen() del repo administracion.
       Un contenedor con varias ordenes de compra tiene UN pago al proveedor.
       ==================================================================== */

    /** El ID de la OC principal de una fila, que es de donde sale todo */
    const OC_PRINCIPAL = 'COALESCE(A.ID_PADRE, A.ID)';

    /**
     * El FOB y lo pagado, listos para el SELECT.
     *
     * SIN LA TABLA DE PAGOS se piden literales con el mismo nombre y CERO
     * pagado, que es lo unico que se puede afirmar: sin poder leerla, no hay
     * ningun pago conocido. El pendiente queda valiendo el FOB completo -lo que
     * esta pestana mostraba antes- y avisoSinPagosComex() dice que eso es de
     * mas. Mismo truco que rastroSelect() y pagadoSelect(), con una diferencia
     * que importa: aca la degradacion cambia numeros, no apaga un boton.
     *
     * @return string
     */
    private function saldoSelect() {
        $pagado = $this->tienePagosComex()
            ? "ISNULL(PG.MONTO, 0)  PAGADO_USD,
               ISNULL(PG.CANT, 0)   PAGOS_CANT"
            : "CAST(0 AS DECIMAL(18,2)) PAGADO_USD,
               CAST(0 AS INT)          PAGOS_CANT";

        /* EL FOB SALE DE LA PRINCIPAL, con la propia como respaldo. El ISNULL
           no es defensivo de mas: ID_PADRE es una FK al mismo maestro y si
           apuntara a una fila que ya no esta, sin respaldo este contenedor
           pasaria a valer NULL y desapareceria del tablero en silencio. */
        return "ISNULL(OC.VALOR_FOB_DOLAR, A.VALOR_FOB_DOLAR) VALOR_FOB_DOLAR,
                " . self::OC_PRINCIPAL . " GRUPO_ID,
                " . $pagado . ",
                " . self::duplicaGrupoSelect();
    }

    /**
     * Los APPLY que traen el FOB y los pagos de la OC principal.
     *
     * @return string
     */
    private function saldoApply() {
        $sql = "OUTER APPLY (SELECT OC0.VALOR_FOB_DOLAR
                             FROM " . self::TABLA_MAESTRO . " OC0
                             WHERE OC0.ID = " . self::OC_PRINCIPAL . ") OC";

        if (!$this->tienePagosComex()) {
            return $sql;
        }

        return $sql . "
                OUTER APPLY (SELECT ISNULL(SUM(PG0.MONTO), 0) MONTO, COUNT(PG0.ID) CANT
                             FROM " . self::TABLA_PAGOS . " PG0
                             WHERE PG0.ID_ENCABEZADO = " . self::OC_PRINCIPAL . ") PG";
    }

    /**
     * El BIT que dice que esta fila REPITE un contenedor que ya esta en el
     * listado, y que por lo tanto no tiene que volver a proyectarlo.
     *
     * POR QUE HACE FALTA, Y POR QUE NO HACIA FALTA ANTES
     * --------------------------------------------------
     * Hasta feature/comex-saldo-pendiente cada fila proyectaba SU PROPIO
     * VALOR_FOB_DOLAR, asi que dos filas del mismo grupo eran dos importes
     * distintos y no habia nada que deduplicar. Ahora las dos leen el FOB y los
     * pagos DE LA PRINCIPAL -que es la regla de Comex- y por lo tanto valen lo
     * MISMO: si las dos aportaran al eje, el contenedor entraria dos veces.
     *
     * O sea que el riesgo lo introduce este cambio, y por eso se cierra aca.
     *
     * EL TITULAR ES LA PRINCIPAL SI ESTA, Y SI NO LA DE ID MAS CHICO. Lo
     * segundo no es un capricho: el listado deja afuera los contenedores que ya
     * tienen detalle cargado, asi que puede pasar que la principal no este y
     * queden solo hijas. Dejar el grupo sin titular haria desaparecer ese
     * egreso del tablero, que es peor que elegir una fila por un criterio
     * estable.
     *
     * ESTA DORMIDO EN LA BASE DE HOY. Al 22/09/2026 los 76 contenedores del
     * listado son principales -no hay NI UNA fila con ID_PADRE cargado en
     * central- asi que este CASE vale 0 en todas. Se escribe igual porque la
     * columna existe, Comex la resuelve, y el dia que aparezca una hija el
     * tablero no puede empezar a contar doble sin que nadie se entere.
     *
     * LA PARTICION MIRA EL LISTADO, NO EL MAESTRO: las funciones de ventana
     * corren DESPUES del WHERE, asi que dos filas solo se pisan si las dos
     * estan efectivamente en la grilla.
     *
     * @return string
     */
    private static function duplicaGrupoSelect() {
        return "COUNT(*) OVER (PARTITION BY " . self::OC_PRINCIPAL . ") GRUPO_FILAS,
                CASE WHEN ROW_NUMBER() OVER (
                          PARTITION BY " . self::OC_PRINCIPAL . "
                          ORDER BY CASE WHEN A.ID_PADRE IS NULL THEN 0 ELSE 1 END, A.ID) = 1
                     THEN 0 ELSE 1 END DUPLICA_GRUPO";
    }

    /**
     * EL SALDO PENDIENTE, LA REGLA ENTERA, PURA.
     *
     * Es la cuenta de Pagos::obtenerResumen() del repo administracion, con los
     * mismos estados y la misma tolerancia. Vive replicada y no incluida porque
     * son dos aplicaciones y dos despliegues; vive SOLA y pura para que se
     * pueda probar sin base y comparar linea por linea contra la otra.
     *
     *     pendiente = VALOR_FOB_DOLAR - SUMA(MONTO)
     *
     * EL PENDIENTE NUNCA ES NEGATIVO. Si lo cargado supera al FOB, vale cero:
     * un egreso negativo en el cashflow seria un INGRESO que nadie afirmo, y
     * ademas se compensaria en silencio contra el resto de la columna. Lo que
     * sobra se devuelve aparte, en 'sobrepago', para que la pantalla lo pueda
     * decir con su importe.
     *
     * 'IMPUTADO' ES EL COMPLEMENTO Y SE DEVUELVE CALCULADO, no vuelto a medir:
     * es el pedazo del FOB que los pagos ya cubrieron, topeado en el FOB. Por
     * construccion vale FOB - pendiente, y eso es justo lo que hace que el
     * invariante de series cierre al centavo en vez de al centavo mas o menos.
     * Medirlo como min(pagado, fob) daria lo mismo casi siempre y no siempre,
     * que es la peor de las dos opciones.
     *
     * LOS CUATRO ESTADOS SE EVALUAN EN EL ORDEN DE COMEX, y ese orden es el que
     * decide los bordes: un contenedor sin FOB cargado es SIN_FOB aunque tenga
     * pagos -no hay contra que compararlos- y no SOBREPAGO. El exceso se
     * informa igual, porque existe.
     *
     * @param mixed $fobUsd VALOR_FOB_DOLAR de la OC principal
     * @param mixed $pagadoUsd SUMA de los pagos de la OC principal, en U$S
     * @return array ['fob','pagado','pendiente','imputado','sobrepago','estado']
     */
    public static function saldoPendiente($fobUsd, $pagadoUsd) {
        $fob = floatval($fobUsd);
        $pagado = floatval($pagadoUsd);
        $saldo = $fob - $pagado;

        if ($fob <= 0) {
            $estado = self::ESTADO_SIN_FOB;
        } elseif ($saldo < -self::TOLERANCIA_SALDO) {
            $estado = self::ESTADO_SOBREPAGO;
        } elseif (abs($saldo) <= self::TOLERANCIA_SALDO) {
            $estado = self::ESTADO_CANCELADO;
        } else {
            $estado = self::ESTADO_PENDIENTE;
        }

        /* Un FOB negativo no existe, pero si lo hubiera no puede volverse un
           'imputado' negativo que despues se sume al tablero. */
        $base = ($fob > 0) ? $fob : 0.0;
        $pendiente = ($estado === self::ESTADO_PENDIENTE) ? $saldo : 0.0;
        $exceso = $pagado - $base;

        return [
            'fob' => $fob,
            'pagado' => $pagado,
            'pendiente' => $pendiente,
            'imputado' => $base - $pendiente,

            /* CON LA MISMA TOLERANCIA que el estado, y no a secas: sin esto un
               contenedor cancelado que pago medio centavo de mas dispararia el
               aviso de sobrepago, que manda a corregir a mano una diferencia
               que la tolerancia ya declaro irrelevante. */
            'sobrepago' => ($exceso > self::TOLERANCIA_SALDO) ? $exceso : 0.0,
            'estado' => $estado
        ];
    }

    /* ====================================================================
       CUANDO UN CONTENEDOR DEJA DE VERSE

       Esto CAMBIO con feature/comex-visibilidad-saldo. Hasta ahi el padron de
       Proveedores Exterior era "sin detalle cargado": un contenedor salia de
       la proyeccion en cuanto Comercio Exterior cargaba sus costos de
       nacionalizacion, AUNQUE AL PROVEEDOR TODAVIA SE LE DEBIERA el FOB. El
       saldo desaparecia del tablero sin haberse pagado.

       Ahora sale solo cuando cumple LAS DOS:

         (A) tiene costos de nacionalizacion confirmados: filas en
             RO_T_IMPORTACIONES_DETALLE para cualquier OC del grupo. La
             estimacion de PCI no cuenta;
         (B) los pagos cubren el FOB: saldoPendiente() da CANCELADO o
             SOBREPAGO. SIN_FOB no cuenta como cubierto.

       Por GRUPO: (A) mira el detalle de cualquier OC -se replica a todas- y
       (B) el FOB y los pagos de la principal, como el saldo. Sin ventana de
       tiempo: aca nunca la hubo.

       ES LA REGLA DE GESTION DE DESPACHOS, replicada y no incluida, como la del
       saldo: vive en VisibilidadContenedor del repo administracion y el
       encabezado de su Pagos.php es la otra mitad del pacto. La de alla suma
       una ventana de 6 meses para lo que no tiene saldo, que aca no aplica.
       ==================================================================== */

    /** (B): los pagos cubren el FOB. SIN_FOB no: no hay contra que medir */
    public static function fobCubierto($estado) {
        return $estado === self::ESTADO_CANCELADO || $estado === self::ESTADO_SOBREPAGO;
    }

    /**
     * NOT (A AND B): si el contenedor sigue en el padron de Proveedores
     * Exterior -y por lo tanto en el de ComprasProyectadasDatos::cargado()-.
     *
     * @param bool $tieneCostos (A), a nivel grupo
     * @param string $estado saldoPendiente()['estado']
     * @return bool
     */
    public static function sigueEnProveedores($tieneCostos, $estado) {
        return !($tieneCostos && self::fobCubierto($estado));
    }

    /**
     * (A) en SQL, para la fila de alias A.
     *
     * EXISTS Y NO EL LEFT JOIN DE ANTES. El corte viejo era LEFT JOIN
     * RO_T_IMPORTACIONES_DETALLE ... WHERE ID_MG IS NULL, que no multiplicaba
     * filas porque se quedaba solo con las que no tenian detalle. Ahora pasan
     * contenedores con detalle, y el mismo JOIN daria una fila por cada linea
     * de costo: DUPLICA_GRUPO y GRUPO_FILAS contarian lineas de costo como si
     * fueran OCs repetidas.
     *
     * @return string
     */
    public static function sqlTieneCostos() {
        return "EXISTS (SELECT 1
                        FROM RO_T_IMPORTACIONES_DETALLE DV
                        INNER JOIN " . self::TABLA_MAESTRO . " GV ON GV.ID = DV.ID_MG
                        WHERE COALESCE(GV.ID_PADRE, GV.ID) = " . self::OC_PRINCIPAL . ")";
    }

    /**
     * sigueEnProveedores() en SQL, para el WHERE.
     *
     * EN FLOAT, a proposito: saldoPendiente() resta en double, y con DECIMAL
     * los dos lados caerian en estados distintos justo en el borde del
     * centavo -100,01 contra 100,00 es 0,01 exacto en DECIMAL y
     * 0,010000000000005 en double-. Es el mismo CAST que usa
     * VisibilidadContenedor del otro repo.
     *
     * @param string $fobExpr FOB de la principal
     * @param string $pagadoExpr SUM(MONTO) de la principal, nunca NULL
     * @return string
     */
    public static function sqlSigueVisible($fobExpr, $pagadoExpr) {
        $fob = "CAST(ISNULL(" . $fobExpr . ", 0) AS FLOAT)";

        return "NOT (" . self::sqlTieneCostos() . "
                     AND " . $fob . " > 0
                     AND " . $fob . " - CAST(" . $pagadoExpr . " AS FLOAT) <= " . self::TOLERANCIA_SALDO . ")";
    }

    /* ====================================================================
       DESDE QUE FECHA DE MOVIMIENTO SE CONSIDERA UN CONTENEDOR

       Desde feature/cashflow-avisos-kpis-comex-nac-parcial los dos padrones
       -Proveedores Exterior y lo ya cargado que descuenta Compras
       Proyectadas- y Crono Nacionalizacion traen solo los contenedores cuyo
       FECHA_MOV DE LA OC PRINCIPAL es igual o posterior a un parametro,
       comex_fecha_mov_desde (Parametros > Compras Exterior, 01/01/2025 por
       defecto).

       POR QUE. El padron arrastraba contenedores de 2023 y 2024 que nadie
       va a pagar ni nacionalizar desde el cashflow: al 20/10/2026 eran 149
       de las 348 filas de Proveedores Exterior, y 149 de los 255 avisos de
       "no se pudieron valuar". Ninguno sumaba en el eje -no tenian fecha o
       estaban vencidos-; lo unico que hacian era tapar los avisos que si
       piden algo.

       LA FECHA ES LA DE LA OC PRINCIPAL, COALESCE(ID_PADRE, ID), que es el
       criterio de Comercio Exterior para la ventana de Gestion de Despachos
       (VisibilidadContenedor, repo administracion). Una OC y sus hijas
       entran o salen juntas. Si la principal no esta -una FK colgada-, cuenta
       la propia, igual que el FOB en saldoSelect().

       FECHA_MOV ES OBLIGATORIO EN COMERCIO EXTERIOR: la pantalla de alta lo
       pide y al 20/10/2026 no hay un solo encabezado sin el. Por eso no hay
       caso "sin fecha": si apareciera, la comparacion con NULL lo dejaria
       afuera, que es lo mismo que pasa con un contenedor anterior al corte.

       LO QUE QUEDA AFUERA NO SE AVISA, a proposito: esa informacion no se
       considera, y un aviso que la cuente seria justamente el ruido que este
       corte viene a sacar. Vale tambien para un contenedor anterior con
       saldo pendiente y fecha futura.
       ==================================================================== */

    /** La clave del parametro, en RO_T_CASHFLOW_PARAMETROS */
    const PARAM_FECHA_MOV_DESDE = 'comex_fecha_mov_desde';

    /**
     * El valor con el que se trabaja sin el parametro. Esta escrito dos veces
     * -aca y en sql/cashflow_comex_fecha_mov_desde.sql-, como los de Compras
     * Proyectadas: el script lo siembra, y el codigo no depende de que se haya
     * corrido.
     */
    const FECHA_MOV_DESDE_INICIAL = '2025-01-01';

    /** @var string|null Cache del parametro, una lectura por instancia */
    private $fechaMovDesde = null;

    /**
     * La fecha de corte a partir del valor del parametro. Un valor que no es
     * una fecha 'aaaa-mm-dd' real -o que no esta- vale la inicial: el
     * parametro lo valida validarFechaMovDesde() al guardarlo, asi que esto
     * solo pasa sin el script o con un valor cargado a mano en la base.
     *
     * @param mixed $valor
     * @return string 'Y-m-d'
     */
    public static function fechaMovDesde($valor) {
        $v = trim((string) $valor);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
            && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return $v;
        }

        return self::FECHA_MOV_DESDE_INICIAL;
    }

    /**
     * La validacion del parametro al guardarlo, en el servidor: una fecha real
     * y no futura. Una fecha futura dejaria los dos padrones vacios -ningun
     * contenedor se movio todavia- y el tablero sin pagos al exterior.
     *
     * @param mixed $valor
     * @param string $hoy 'Y-m-d'
     * @return string La fecha normalizada
     * @throws Exception Si no es una fecha o es futura
     */
    public static function validarFechaMovDesde($valor, $hoy) {
        $v = trim((string) $valor);

        if (self::fechaMovDesde($v) !== $v) {
            throw new Exception('"Contenedores desde" tiene que ser una fecha (aaaa-mm-dd).');
        }

        if ($v > substr((string) $hoy, 0, 10)) {
            throw new Exception('"Contenedores desde" no puede ser una fecha futura: dejaría afuera '
                . 'todos los contenedores.');
        }

        return $v;
    }

    /**
     * La regla del corte, pura: si un contenedor entra.
     *
     * Es sqlDesdeFechaMov() escrita en PHP, para poder probar sin base los
     * casos que la base de hoy no tiene -no hay una sola OC hija- y para
     * comparar una contra la otra fila por fila cuando hay base, igual que
     * VisibilidadContenedor en Comercio Exterior.
     *
     * @param mixed $fechaMovPrincipal FECHA_MOV de COALESCE(ID_PADRE, ID), o null si no esta
     * @param mixed $fechaMovPropia FECHA_MOV de la fila
     * @param mixed $desde El parametro
     * @return bool
     */
    public static function entraPorFechaMov($fechaMovPrincipal, $fechaMovPropia, $desde) {
        $f = Horizonte::normalizarFecha($fechaMovPrincipal);

        if ($f === null) {
            $f = Horizonte::normalizarFecha($fechaMovPropia);
        }

        return $f !== null && $f >= self::fechaMovDesde($desde);
    }

    /**
     * El corte en SQL, para la fila de alias A. Lo usan filtroPadron() -o sea
     * los dos padrones- y getCronoNacionalizacion(): la regla esta escrita una
     * vez.
     *
     * La fecha va como literal porque los WHERE de este modulo se arman como
     * texto y se reusan en otras consultas (ComprasProyectadasDatos::cargado()),
     * sin parametros; pasa antes por fechaMovDesde(), que solo deja salir
     * 'aaaa-mm-dd'.
     *
     * @param mixed $desde
     * @return string
     */
    public static function sqlDesdeFechaMov($desde) {
        return "ISNULL((SELECT FMV.FECHA_MOV FROM " . self::TABLA_MAESTRO . " FMV
                        WHERE FMV.ID = " . self::OC_PRINCIPAL . "), A.FECHA_MOV)
                >= '" . self::fechaMovDesde($desde) . "'";
    }

    /**
     * El parametro vigente. Sin la fila -no se corrio el script- o sin poder
     * leerla, la inicial: el corte se aplica igual. El aviso de que falta el
     * script va solo en Parametros, no en las pestanas.
     *
     * @return string 'Y-m-d'
     */
    public function fechaMovDesdeVigente() {
        if ($this->fechaMovDesde !== null) {
            return $this->fechaMovDesde;
        }

        $valor = null;
        $cid = $this->conn->conectar('central');

        if ($cid) {
            $stmt = @sqlsrv_query($cid,
                "SELECT VALOR FROM RO_T_CASHFLOW_PARAMETROS WHERE CLAVE = ?",
                [self::PARAM_FECHA_MOV_DESDE]);

            if ($stmt !== false) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $valor = $row ? $row['VALOR'] : null;
                sqlsrv_free_stmt($stmt);
            }
        }

        $this->fechaMovDesde = self::fechaMovDesde($valor);

        return $this->fechaMovDesde;
    }

    /**
     * El WHERE del padron de Proveedores Exterior, sobre los alias de
     * saldoApply(): OC para el FOB de la principal y PG para sus pagos.
     *
     * UN SOLO LUGAR PARA LOS DOS PADRONES. ComprasProyectadasDatos::cargado()
     * descuenta el pendiente de ESTOS contenedores, y arma su consulta con los
     * mismos alias para poder usar exactamente este filtro: si los dos
     * padrones se separaran, el tablero contaria dos veces el mismo saldo.
     *
     * SIN LA TABLA DE PAGOS lo pagado es cero, igual que en saldoSelect(): nada
     * esta cubierto y todo contenedor con FOB sigue en el padron. Es la misma
     * degradacion -proyectar de mas- que avisoSinPagosComex() ya avisa.
     *
     * Y EL CORTE POR FECHA DE MOVIMIENTO VA ACA, no en cada consulta: asi los
     * dos padrones siguen siendo el mismo. Ver sqlDesdeFechaMov().
     *
     * @return string
     */
    public function filtroPadron() {
        return self::sqlSigueVisible(
            'ISNULL(OC.VALOR_FOB_DOLAR, A.VALOR_FOB_DOLAR)',
            $this->tienePagosComex() ? 'ISNULL(PG.MONTO, 0)' : 'CAST(0 AS DECIMAL(18,2))')
            . "
                AND " . self::sqlDesdeFechaMov($this->fechaMovDesdeVigente());
    }

    /**
     * Le pone a una fila leida su saldo pendiente y todo lo que se deriva de el.
     *
     * VALOR_FOB_DOLAR SE PISA CON EL FLOAT ya normalizado: la base lo devuelve
     * como string y la grilla, los avisos y las tres valuaciones lo leen por
     * nombre. Normalizarlo una vez aca es lo mismo que hace conFechaEfectiva()
     * con los BIT.
     *
     * 'PAGO_PARCIAL' NO ES UN QUINTO ESTADO, y por eso no esta en las
     * constantes: es PENDIENTE con pagos encima. Se deriva aca -y no en el
     * front- porque es lo que la pestana tiene que poder mostrar de un vistazo
     * y la regla de cuando vale no puede quedar escrita en el navegador.
     *
     * @param array $row Fila cruda, con VALOR_FOB_DOLAR y PAGADO_USD de la principal
     * @return array
     */
    private static function conSaldo($row) {
        $s = self::saldoPendiente(
            isset($row['VALOR_FOB_DOLAR']) ? $row['VALOR_FOB_DOLAR'] : 0,
            isset($row['PAGADO_USD']) ? $row['PAGADO_USD'] : 0);

        $row['VALOR_FOB_DOLAR'] = $s['fob'];
        $row['PAGADO_USD'] = $s['pagado'];
        $row['PENDIENTE_USD'] = $s['pendiente'];
        $row['IMPUTADO_USD'] = $s['imputado'];
        $row['SOBREPAGO_USD'] = $s['sobrepago'];
        $row['ESTADO_PAGO'] = $s['estado'];

        $row['PAGOS_CANT'] = isset($row['PAGOS_CANT']) ? intval($row['PAGOS_CANT']) : 0;
        $row['PAGO_PARCIAL'] =
            ($row['PAGOS_CANT'] > 0 && $s['estado'] === self::ESTADO_PENDIENTE);

        /* Un BIT de SQL Server llega como '1'/'0', igual que PAGADO: se
           normaliza aca, una sola vez, porque de el depende si la fila aporta
           al eje. Un '0' verdadero en PHP sacaria del tablero medio padron. */
        $row['DUPLICA_GRUPO'] =
            !empty($row['DUPLICA_GRUPO']) && $row['DUPLICA_GRUPO'] != '0';
        $row['GRUPO_FILAS'] = isset($row['GRUPO_FILAS']) ? intval($row['GRUPO_FILAS']) : 1;

        /* Mismo cuidado que DUPLICA_GRUPO. TIENE_COSTOS es la (A) de
           sigueEnProveedores(): decide la etiqueta "Costos cargados" de la
           grilla. Que la fila este aca con costos quiere decir que esta por el
           saldo, y sin la marca se confunde con una a la que le faltan. */
        $row['TIENE_COSTOS'] =
            !empty($row['TIENE_COSTOS']) && $row['TIENE_COSTOS'] != '0';

        return $row;
    }

    /**
     * Un importe en dolares llevado a pesos con la cotizacion que le toco.
     *
     * SIN COTIZACION DEVUELVE null Y NO CERO, que es el criterio de todo el
     * modulo: null es "no se pudo valuar" -y tiene su propio aviso, en
     * dolares-, cero seria "este contenedor no cuesta nada".
     *
     * Existe porque desde feature/comex-saldo-pendiente la fila tiene TRES
     * importes en pesos -el pendiente, lo ya pagado y el FOB completo- y los
     * tres se convierten con la MISMA cotizacion. Con la cuenta escrita tres
     * veces, cambiar el redondeo en una sola rompe el invariante de series sin
     * que nada falle.
     *
     * @param mixed $usd
     * @param mixed $cotizacion
     * @return float|null
     */
    public static function enPesos($usd, $cotizacion) {
        return ($cotizacion === null) ? null : round(floatval($usd) * floatval($cotizacion), 2);
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
     * EL PADRON YA NO ES "SIN DETALLE CARGADO". Un contenedor con costos
     * cargados sigue mientras le quede saldo por pagar, y sale cuando el FOB
     * esta cubierto: ver sigueEnProveedores() y filtroPadron(). Al 01/10/2026
     * eso suma 278 contenedores, 250 de ellos sin fecha estimada de pago: van
     * como "Sin fecha" y el aviso de valuacion informa su saldo en dolares.
     *
     * LO QUE SE VALUA ES EL PENDIENTE, NO EL FOB. Cada fila trae el FOB de su
     * OC principal, lo que Comercio Exterior ya registro como pagado y la resta
     * de los dos, y es esa resta la que se lleva la cotizacion. Ver el
     * encabezado de la clase y saldoPendiente().
     *
     * Cada fila vuelve con su valuacion en pesos resuelta -IMPORTE_ARS- y con
     * QUE DOLAR se le aplico: el simbolo de la curva, el mes, la cotizacion y
     * por que es esa y no otra. Las cuatro cosas viajan juntas porque un
     * importe en pesos que no se puede atar a una cotizacion identificada no se
     * puede auditar contra nada, que es el mismo criterio de
     * Cotizacion::ultimaHasta().
     *
     * Y VUELVE CON LOS OTROS DOS IMPORTES EN PESOS -lo ya pagado y el FOB
     * completo- porque son las dos series que hacen que el egreso que sale de
     * la proyeccion no desaparezca del modelo. No son datos de la grilla: son
     * lo que el tablero necesita para que el invariante cierre.
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

        /* Quien cargo la cotizacion a mano y cuando: la fila de CRONO_NAC es una
           por contenedor y su ultima modificacion es la del override. */
        $cotizAudSql = $this->tieneCotizEdit()
            ? 'D.USUARIO_MODIF AS COTIZ_USUARIO, D.FECHA_MODIF AS COTIZ_FECHA'
            : 'CAST(NULL AS VARCHAR(50)) AS COTIZ_USUARIO, CAST(NULL AS DATETIME) AS COTIZ_FECHA';

        $sql = "SELECT
                    A.ID,
                    A.PROVEEDOR,
                    A.CONTENEDOR,
                    A.ORDEN_COMPRA,
                    UPPER(A.DESPACHANTE) DESPACHANTE,
                    " . $this->saldoSelect() . ",
                    ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) ETD,
                    CASE WHEN A.FECHA_EMB IS NULL THEN 0 ELSE 1 END ETD_CONFIRM,
                    A.FECHA_ARR ETA,
                    CASE WHEN A.ETA_CONFIRMADA = 1 THEN 1 ELSE 0 END ETA_CONFIRM,
                    A.FECHA_EST_PAGO,
                    " . $this->confPagoSelect() . ",
                    " . $this->rastroSelect('PAGO') . ",
                    " . $this->pagadoSelect() . ",
                    " . $cotizSql . " AS COTIZ_USD_EDIT,
                    " . $cotizAudSql . ",
                    CASE WHEN " . self::sqlTieneCostos() . " THEN 1 ELSE 0 END TIENE_COSTOS
                FROM " . self::TABLA_MAESTRO . " A
                LEFT JOIN " . self::TABLA_EDIT . " D ON A.ID = D.ID_MG
                " . $this->rastroJoin('PAGO') . "
                " . $this->pagadoJoin('PAGO') . "
                " . $this->saldoApply() . "
                WHERE " . $this->filtroPadron() . "
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
                 'PAGADO_FECHA', 'FECHA_PAGO_CONF_FECHA', 'COTIZ_FECHA']);

            $row = self::conFechaEfectiva($row, 'FECHA_EST_PAGO', 'FECHA_PAGO_EFECTIVA', $hoy,
                                          $conBitPago);

            /* EL SALDO VA ANTES DE VALUAR, porque es lo que se valua. Lo que el
               cashflow proyecta es el PENDIENTE -ver el encabezado de la
               clase-, asi que la curva se aplica sobre PENDIENTE_USD y no sobre
               el FOB. */
            $row = self::conSaldo($row);
            $row = self::valuar($row, $curva, 'PENDIENTE_USD');

            /* LAS OTRAS DOS VALUACIONES, con la MISMA cotizacion de la fila.
               Lo ya pagado y el FOB completo no entran a la proyeccion, pero
               tienen que existir en pesos: son las dos series que hacen que el
               importe que sale del flujo no desaparezca del modelo. */
            $row['IMPORTE_PAGADO_ARS'] = self::enPesos($row['IMPUTADO_USD'], $row['COTIZ_USD']);

            /* EL FOB EN PESOS SE DERIVA SUMANDO, y no multiplicando otra vez.
               round(a*c) + round(b*c) no siempre es round((a+b)*c): la
               diferencia es de un centavo por fila, y multiplicada por 76 filas
               deja el invariante PAGOS + PAGOS_PAGADOS + PAGOS_COMEX =
               PAGOS_TODO sin cerrar, que es justo lo que estas tres series
               existen para poder verificar. Entre un FOB en pesos exacto al
               centavo y un invariante que cierra, gana el invariante: el FOB
               se muestra EN DOLARES, que es la moneda en la que es el dato. */
            $row['IMPORTE_FOB_ARS'] =
                ($row['IMPORTE_ARS'] === null || $row['IMPORTE_PAGADO_ARS'] === null)
                    ? null
                    : round($row['IMPORTE_ARS'] + $row['IMPORTE_PAGADO_ARS'], 2);

            /* Los cuatro importes derivados. Van DESPUES de valuar porque salen
               de los tres campos en pesos, y son cuatro porque el reparto en
               series necesita cada regla por separado: ver aporteAlEje(). */
            $row['IMPORTE_PROYECTABLE'] = self::importeProyectable($row);
            $row['IMPORTE_EJE'] = self::aporteAlEje($row);
            $row['IMPORTE_PAGADO_PROYECTABLE'] =
                self::importeProyectable($row, 'IMPORTE_PAGADO_ARS');
            $row['IMPORTE_FOB_PROYECTABLE'] =
                self::importeProyectable($row, 'IMPORTE_FOB_ARS');

            /* Va AL FINAL porque necesita el tilde y el saldo resueltos. Solo
               decide lo que se muestra: ver vencidaPendiente(). */
            $row['VENCIDA_PENDIENTE'] = self::vencidaPendiente($row);

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /**
     * Los pagos ya cargados de un contenedor, uno por uno. SOLO LECTURA.
     *
     * POR QUE LA PESTANA TIENE QUE PODER VERLOS
     * -----------------------------------------
     * Porque desde feature/comex-saldo-pendiente el importe que proyecta una
     * fila depende de ellos, y un numero que cambio por algo que paso en OTRA
     * aplicacion, sin forma de ver que fue, es indistinguible de un error de
     * esta. La columna dice CUANTO se pagó; esto dice de que pagos sale.
     *
     * NO SE ESCRIBE NADA, Y NO ES UNA ETAPA PENDIENTE: los pagos se cargan en
     * Comercio Exterior, que es el dueno del circuito. Duplicar el alta de este
     * lado significaria dos formularios escribiendo la misma tabla con dos
     * validaciones distintas, que es exactamente el problema que las dos
     * aplicaciones vienen resolviendo con las fechas.
     *
     * LOS PAGOS SON DE LA OC PRINCIPAL, igual que el saldo: se pide por
     * COALESCE(ID_PADRE, ID) para que pedir el detalle de una hija devuelva los
     * pagos del contenedor y no una lista vacia. Es la misma resolucion que
     * hace Pagos::obtenerPagosPorEncabezado() del otro lado.
     *
     * MONTO_ORIGEN_ARS SE TRAE y puede no existir: es de un script posterior
     * -comercioExterior/sql/08_pagos_en_dolares.sql-. Sin la columna se pide
     * NULL con su nombre, que significa lo correcto: sin el script no hay
     * ninguna fila convertida. Mismo recurso que usa Pagos.php alla.
     *
     * @param int $idMg ID del contenedor, principal o hija
     * @return array Lista de pagos, la mas vieja primero
     */
    public function getPagosDelContenedor($idMg) {
        if (!$this->tienePagosComex()) {
            return [];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $origenArs = $this->tieneOrigenArs()
            ? 'P.MONTO_ORIGEN_ARS'
            : 'CAST(NULL AS DECIMAL(18,2))';

        $sql = "SELECT P.ID, P.ID_ENCABEZADO, P.FECHA_PAGO, P.FORMA_PAGO, P.MEDIO_PAGO,
                       P.MONTO, P.FECHA_CREACION, " . $origenArs . " AS MONTO_ORIGEN_ARS
                FROM " . self::TABLA_PAGOS . " P
                WHERE P.ID_ENCABEZADO = (SELECT COALESCE(E.ID_PADRE, E.ID)
                                         FROM " . self::TABLA_MAESTRO . " E
                                         WHERE E.ID = ?)
                ORDER BY P.FECHA_PAGO, P.ID";

        $stmt = sqlsrv_query($cid, $sql, [intval($idMg)]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlEn(self::TABLA_PAGOS, 'leer los pagos cargados'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row = self::aTexto($row, ['FECHA_PAGO', 'FECHA_CREACION']);
            $row['MONTO'] = floatval($row['MONTO']);
            $row['MONTO_ORIGEN_ARS'] =
                ($row['MONTO_ORIGEN_ARS'] === null) ? null : floatval($row['MONTO_ORIGEN_ARS']);

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /** @var bool|null Cache de si la tabla de pagos ya tiene MONTO_ORIGEN_ARS */
    private $origenArs = null;

    /**
     * Si la tabla de pagos ya tiene MONTO_ORIGEN_ARS, del script 08 de Comex.
     *
     * Mismo patron que tieneCotizEdit(), y el mismo que usa Pagos.php del otro
     * lado para la misma columna.
     *
     * @return bool
     */
    private function tieneOrigenArs() {
        if ($this->origenArs !== null) {
            return $this->origenArs;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT COL_LENGTH('dbo." . self::TABLA_PAGOS . "', 'MONTO_ORIGEN_ARS') AS C");

        $this->origenArs = false;

        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $this->origenArs = ($row && $row['C'] !== null);
            sqlsrv_free_stmt($stmt);
        }

        return $this->origenArs;
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
                    in_array($c, ['EDIT_FECHA', 'PAGADO_FECHA', 'FECHA_CREACION'], true)
                        ? 'Y-m-d H:i:s' : 'Y-m-d');
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
     * Si una fila vencida TODAVIA PIDE QUE ALGUIEN HAGA ALGO con ella.
     *
     * Una fecha vencida es un dato a corregir: o el pago salio, o hay que
     * cargarle la fecha nueva. Si el pago YA SALIO no hay nada que corregir, y
     * eso lo dice cualquiera de dos cosas:
     *
     *   - el TILDE de pagado, que puso alguien desde la pestana
     *   - el saldo CANCELADO por los pagos cargados en Comercio Exterior
     *
     * Una fila asi deja de ser "vencida" para el contador, el interruptor Ver
     * vencidas, el badge rojo y el aviso de vencidos. Antes esas cuatro cosas
     * miraban VENCIDA a secas, y al 24/09/2026 contaban como vencidas 31 filas
     * que estaban TODAS pagadas: el aviso pedia corregir fechas que nadie tenia
     * que corregir.
     *
     * ES UN FLAG APARTE Y NO UN CAMBIO EN VENCIDA, y eso es lo delicado.
     * importeProyectable() anula por VENCIDA, y de ese campo salen PAGOS_PAGADOS
     * y PAGOS_TODO. Si una fila pagada dejara de ser VENCIDA, su importe pasaria
     * a sumar en PAGOS_PAGADOS y el tablero se moveria. VENCIDA sigue diciendo
     * lo que siempre dijo -la fecha ya paso- y decide los importes; esto decide
     * solo lo que se muestra.
     *
     * Estatica y pura. Un campo ausente es falso, asi que una fila sin PAGADO
     * ni ESTADO_PAGO -la de Crono Nacionalizacion no trae saldo- se trata como
     * no pagada.
     *
     * @param array $fila Fila con VENCIDA resuelta, y PAGADO / ESTADO_PAGO si los tiene
     * @return bool
     */
    public static function vencidaPendiente($fila) {
        if (empty($fila['VENCIDA']) || !empty($fila['PAGADO'])) {
            return false;
        }

        /* En Crono, otras dos formas de "ya se pago": los pagos parciales que
           cubren la base, y los costos reales cargados sin pagos parciales
           -que como siempre se toman como pagados-. Ninguna de las dos tiene
           una fecha que corregir. Las filas de Proveedores Exterior no traen
           estos campos. Ver repartoNacionalizacion(). */
        if (!empty($fila['CUBIERTA_PARCIALES']) || !empty($fila['NAC_SIN_PROYECCION'])) {
            return false;
        }

        return !(isset($fila['ESTADO_PAGO']) && $fila['ESTADO_PAGO'] === self::ESTADO_CANCELADO);
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
     * EL CAMPO ES UN ARGUMENTO, aunque desde feature/comex-nac-usd las dos
     * pestanas pasen el mismo -IMPORTE_ARS-: hasta entonces Crono
     * Nacionalizacion pasaba IMPORTE_EST, porque se creia que ese importe
     * estaba en pesos. Queda como argumento por lo que hace posible: una
     * tercera pestana con otro campo no necesita una copia de esta funcion, que
     * es como se desincronizan las reglas. El default es el de las dos.
     *
     * Y UNA FILA QUE REPITE UN CONTENEDOR TAMPOCO SUMA. Desde
     * feature/comex-saldo-pendiente el FOB y los pagos salen de la OC
     * PRINCIPAL, asi que dos ordenes de compra del mismo contenedor valen
     * exactamente lo mismo y sumar las dos contaria el egreso dos veces. La
     * fila viaja igual -se ve en la grilla, marcada- por la misma razon por la
     * que viajan las vencidas: esconder una fila no explica nada.
     *
     * EL FLAG SE PREGUNTA CON !empty() Y NO AL REVES: una fila que no trae
     * DUPLICA_GRUPO suma. Con la condicion invertida -"suma solo si es
     * titular"- cualquier consulta que se olvidara de pedirlo se iria entera a
     * cero. Crono Nacionalizacion lo trae desde feature/cashflow-avisos-kpis-
     * comex-nac-parcial -la base y los pagos parciales son del contenedor-,
     * pero ya no pasa por aca: sus importes los decide
     * repartoNacionalizacion().
     *
     * @param array $fila Fila ya valuada, con VENCIDA resuelta
     * @param string $campoImporte De donde sale el importe de esa pestana
     * @return float|null
     */
    public static function importeProyectable($fila, $campoImporte = 'IMPORTE_ARS') {
        if (!empty($fila['VENCIDA']) || !empty($fila['DUPLICA_GRUPO'])) {
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
     * se cumple columna por columna. Es el mismo criterio de la exclusion de
     * cheques de cartera.
     *
     * EL INVARIANTE TIENE TRES PARTES DESDE feature/comex-saldo-pendiente:
     *
     *     PAGOS + PAGOS_PAGADOS + PAGOS_COMEX = PAGOS_TODO
     *
     * porque ahora hay DOS formas de que un egreso salga de la proyeccion, y
     * son distintas: el TILDE es una afirmacion del cashflow sobre su propia
     * proyeccion -se pone y se saca desde la pestana- y los PAGOS DE COMEX son
     * un hecho cargado en la otra aplicacion, que este modulo solo lee. Una
     * sola serie para las dos no dejaria contestar cual de las dos cosas
     * explica que la fila del tablero haya bajado.
     *
     * Y PAGOS_TODO PASA A VALER EL FOB COMPLETO, que es lo que esta pestana
     * proyectaba antes de esta rama. No es un efecto colateral: es lo que hace
     * que el tablero mismo muestre el antes y el despues del cambio, sin tener
     * que ir a buscarlo a ningun lado.
     *
     * EL TILDE SE APLICA SOBRE EL PENDIENTE, NO SOBRE EL FOB. Tildar un
     * contenedor que ya tiene la mitad pagada en Comex saca de la proyeccion
     * LA MITAD QUE FALTABA, que es lo unico que estaba proyectado. La otra
     * mitad ya habia salido por PAGOS_COMEX.
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
     * PUEDE DAR DOS AVISOS, AUNQUE HOY NINGUNA PESTANA LOS NECESITE
     * -------------------------------------------------------------
     * En las DOS pestanas lo vencido no suma por regla -ver aporteAlEje()- asi
     * que ninguna fila entra en ninguna columna, los dos llamadores llaman SIN
     * pasarle el Horizonte y todas caen en la misma bolsa.
     *
     * La capacidad de dar dos queda porque hay un caso que no es obvio y que
     * estuvo vivo: la columna del MES EN CURSO cubre los dias de ese mes que
     * quedaron fuera del tramo diario, o sea DIAS QUE YA PASARON. Mientras
     * Crono Nacionalizacion no tuvo la regla, una nacionalizacion vencida de
     * este mismo mes caia ahi y entraba al tablero -al 19/09/2026, 1 de 24 por
     * $ 55.238,12- y un solo aviso diciendo "no suman en ninguna columna"
     * habria sido falso para esa. Si alguien vuelve a dejar entrar lo vencido
     * en algun lado, el aviso no puede mentir: eso es lo que esta guardado aca.
     *
     * Ese reparto lo decide Horizonte::agrupar() y NO se toca: es la regla de
     * "un importe va a un dia O a un mes" que hace sumables a las tres vistas.
     *
     * SOLO INFORMA LO VENCIDO, y no lo que no tiene fecha, aunque las dos cosas
     * queden fuera del eje. Lo segundo ya lo dice mejor avisosValuacion(), que
     * desde feature/comex-nac-usd corre en LAS DOS pestanas y lo informa EN
     * DOLARES: sin fecha efectiva no hay mes, sin mes no hay cotizacion y el
     * importe en pesos ni siquiera existe. Repetirlo aca daria "$ 0,00 sin
     * fecha" al lado de "U$S 164.526,47 sin fecha", que es el mismo hecho
     * contado dos veces y una de las dos mal.
     *
     * LA USAN LA PESTANA Y EL TABLERO: un solo texto, igual que
     * avisosValuacion().
     *
     * @param array $filas Filas con 'VENCIDA' resuelta, y 'PAGADO' / 'ESTADO_PAGO'
     *        si los tienen: una vencida ya pagada no se avisa
     * @param string $campoFecha Campo con la fecha efectiva
     * @param string $campoImporte Campo con el importe a informar
     * @param string $queEs Como se nombra la fecha en el mensaje
     * @param Horizonte|null $h El eje, para saber que columnas existen
     * @return array Lista de mensajes
     */
    public static function avisosVencidos($filas, $campoFecha, $campoImporte, $queEs, $h = null) {
        return Aviso::textos(self::avisosVencidosConNivel($filas, $campoFecha, $campoImporte, $queEs, $h));
    }

    /**
     * Los mismos avisos de avisosVencidos(), con su gravedad. Lo lee el
     * tablero; las pestanas leen los textos.
     *
     * Lo que NO suma pide cargar una fecha nueva: atencion. Lo que SI entra,
     * en la columna del mes en curso, esta en el cuadro y solo explica por
     * que aparece en dias que ya pasaron: informativo.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosVencidosConNivel($filas, $campoFecha, $campoImporte, $queEs, $h = null) {
        $afuera = 0;
        $adentro = 0;
        $impAfuera = 0.0;
        $impAdentro = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            /* LO VENCIDO QUE YA SE PAGO NO SE AVISA: el aviso pide cargar una
               fecha nueva, y a un pago hecho no hay que cargarle nada. Ya lo
               cuenta avisosPagados(). Ver vencidaPendiente(). */
            if (!self::vencidaPendiente($f)) {
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
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $afuera . ' contenedor(es) por '
                . self::plata($impAfuera)
                . ' tienen la ' . $queEs . ' ya vencida, así que NO suman en ninguna columna '
                . 'del período. No se los reubica en hoy, porque nadie afirmó que ese importe '
                . 'se mueve hoy: cargales la fecha nueva y entran solos.');
        }

        if ($adentro > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $adentro . ' contenedor(es) por '
                . self::plata($impAdentro)
                . ' tienen la ' . $queEs . ' vencida pero dentro del mes en curso, así que SÍ '
                . 'entran, en la columna de ese mes —que cubre los días previos al tramo '
                . 'diario—. Están en el cuadro, en días que ya pasaron: es plata que todavía no '
                . 'se movió, no proyección.');
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
        $vencidos = 0;
        $importe = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['PAGADO'])) {
                continue;
            }

            $marcados++;
            $importe += isset($f[$campoImporte]) ? floatval($f[$campoImporte]) : 0.0;

            if (!empty($f['VENCIDA'])) {
                $vencidos++;
            }
        }

        if ($marcados === 0) {
            return [];
        }

        /* CUANTOS YA ESTABAN VENCIDOS SE DICE APARTE. Esos no sacaron nada de la
           proyeccion -valian cero por la fecha-, asi que el importe del aviso
           no los incluye. Sin decirlo, el texto contaba "21 contenedores... $
           0,00" y se leia como un error: al 24/09/2026 eran todos vencidos en
           Crono Nacionalizacion y 10 de 16 en Proveedores Exterior. */
        $detalleVencidos = '';

        if ($vencidos === $marcados) {
            $detalleVencidos = ($marcados === 1 ? ' Ya tenía' : ' Todos ya tenían')
                . ' la fecha vencida, así que no sumaban: marcarlos no movió ningún número.';
        } elseif ($vencidos > 0) {
            $detalleVencidos = ' ' . $vencidos . ' de ellos ya tenían la fecha vencida y no '
                . 'sumaban, así que no entran en ese importe.';
        }

        return [$marcados . ' contenedor(es) tienen el ' . $queEs . ' marcado como YA HECHO, '
            . 'así que salieron de la proyección: ' . self::plata($importe) . ' que la fila '
            . 'del tablero ya no cuenta.' . $detalleVencidos . ' El importe no se perdió —sale '
            . 'por su propia serie— y se destilda desde la pestaña si se marcó por error.'];
    }

    /**
     * El aviso por lo que Comercio Exterior ya registro como pagado.
     *
     * ES EL AVISO MAS IMPORTANTE DE LA PANTALLA, porque describe plata que
     * SALIO DE LA PROYECCION SIN QUE NADIE DE ESTE LADO HAGA NADA. El tilde de
     * pagado por lo menos lo puso una persona mirando la grilla; esto pasa solo,
     * cuando alguien carga un pago en la otra aplicacion. Sin el aviso, la fila
     * del tablero baja de un dia para el otro y no hay nada en esta pantalla
     * que explique por que.
     *
     * DICE CUANTOS ESTAN CANCELADOS APARTE, y no es un detalle decorativo: un
     * contenedor con saldo cero DESAPARECE del cuadro entero -sus celdas del
     * eje quedan vacias, igual que un vencido- y eso se lee como un contenedor
     * sin importe. Un parcial, en cambio, sigue estando con lo que falta.
     *
     * SE MIDE SOBRE EL IMPORTE PROYECTABLE DE LO PAGADO -no sobre lo pagado a
     * secas- por el mismo motivo que avisosPagados(): lo que hay que informar
     * es cuanto salio DE LA PROYECCION. Un contenedor con pagos que ademas
     * tiene la fecha vencida ya no sumaba, asi que sus pagos no sacaron nada
     * del tablero y contarlos aca inflaria el aviso.
     *
     * ESTATICA Y PURA, y la usan la pestana y el tablero: un solo texto, igual
     * que avisosValuacion() y avisosVencidos().
     *
     * @param array $filas Filas con ESTADO_PAGO y PAGOS_CANT resueltos
     * @return array Lista de mensajes
     */
    public static function avisosSaldoComex($filas) {
        $conPagos = 0;
        $cancelados = 0;
        $importe = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['PAGOS_CANT'])) {
                continue;
            }

            $conPagos++;
            $importe += isset($f['IMPORTE_PAGADO_PROYECTABLE'])
                ? floatval($f['IMPORTE_PAGADO_PROYECTABLE']) : 0.0;

            if (isset($f['ESTADO_PAGO']) && $f['ESTADO_PAGO'] === self::ESTADO_CANCELADO) {
                $cancelados++;
            }
        }

        if ($conPagos === 0) {
            return [];
        }

        return [$conPagos . ' contenedor(es) ya tienen pagos cargados en Comercio Exterior, '
            . 'así que acá se proyecta sólo lo que FALTA pagar: ' . self::plata($importe)
            . ' que la fila del tablero ya no cuenta. '
            . ($cancelados === 0 ? '' : ($cancelados === 1
                ? '1 de ellos quedó CANCELADO —saldo cero— y salió del cuadro entero, sin '
                    . 'que nadie lo tildara. '
                : $cancelados . ' de ellos quedaron CANCELADOS —saldo cero— y salieron del '
                    . 'cuadro enteros, sin que nadie los tildara. '))
            . 'El importe no se perdió: sale por su propia serie. Los pagos se cargan en '
            . 'Comercio Exterior; desde acá se miran, con el detalle en la columna Pagado.'];
    }

    /**
     * El aviso por los contenedores que tienen cargado MAS de lo que valen.
     *
     * EL PENDIENTE SE TOMA COMO CERO Y NUNCA NEGATIVO -ver saldoPendiente()-
     * asi que el sobrepago no se ve en ninguna columna: no baja el total, no
     * aparece como ingreso, no hace nada. Ese silencio es exactamente el
     * problema, porque un sobrepago es casi siempre un dato mal cargado -un
     * pago imputado al contenedor equivocado, un FOB desactualizado- y es en
     * Comercio Exterior donde hay que arreglarlo.
     *
     * SE INFORMA EN DOLARES, que es la moneda en la que esta el dato y en la
     * que se va a ir a buscar del otro lado. Convertirlo a pesos con la curva
     * del mes de pago le agregaria una cotizacion a un numero que hay que
     * comparar contra una factura.
     *
     * INCLUYE A LOS 'SIN_FOB' CON PAGOS, que Comex clasifica aparte: un
     * contenedor sin FOB cargado y con pagos encima tiene el mismo problema
     * -plata imputada contra nada- y mandarlo a otro aviso solo por el nombre
     * del estado lo dejaria sin quien lo cuente.
     *
     * @param array $filas Filas con SOBREPAGO_USD resuelto
     * @return array Lista de mensajes
     */
    public static function avisosSobrepago($filas) {
        $cuantos = 0;
        $usd = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            $exceso = isset($f['SOBREPAGO_USD']) ? floatval($f['SOBREPAGO_USD']) : 0.0;

            if ($exceso <= 0) {
                continue;
            }

            $cuantos++;
            $usd += $exceso;
        }

        if ($cuantos === 0) {
            return [];
        }

        return [$cuantos . ' contenedor(es) tienen cargado en Comercio Exterior MÁS de lo que '
            . 'dice su FOB: U$S ' . number_format($usd, 2, ',', '.') . ' de más. El pendiente '
            . 'de esos se toma como CERO —el cashflow no proyecta egresos negativos, que serían '
            . 'un ingreso que nadie afirmó— así que la diferencia no se ve en ninguna columna. '
            . 'Revisá en Comercio Exterior si hay un pago imputado al contenedor equivocado o '
            . 'un FOB desactualizado.'];
    }

    /**
     * El aviso por las ordenes de compra que repiten un contenedor del listado.
     *
     * SE INFORMA AUNQUE HOY NO PASE NUNCA. Al 22/09/2026 no hay ninguna OC hija
     * en central, asi que este aviso no sale; el dia que salga va a estar
     * describiendo la unica razon por la que una fila con importe tiene todas
     * las celdas del eje vacias, y sin el eso se lee como un error de la
     * pantalla.
     *
     * NO DICE "CORREGILO", a diferencia del de vencidos y el de sobrepago: no
     * hay nada que corregir. Es como Comercio Exterior modela un contenedor con
     * varias ordenes de compra, y el cashflow se limita a no contarlo dos veces.
     *
     * @param array $filas Filas con DUPLICA_GRUPO resuelto
     * @return array Lista de mensajes
     */
    public static function avisosGrupo($filas) {
        $cuantas = 0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (!empty($f['DUPLICA_GRUPO'])) {
                $cuantas++;
            }
        }

        if ($cuantas === 0) {
            return [];
        }

        return [$cuantas . ' orden(es) de compra son del mismo contenedor que otra fila del '
            . 'listado. El FOB y los pagos son del contenedor, no de cada orden, así que el '
            . 'importe lo proyecta UNA sola fila —la de la orden principal— y estas van en cero '
            . 'para no contar el mismo egreso dos veces. Están marcadas en la grilla.'];
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
     * LAS DOS PESTANAS VALUAN, Y POR ESO LOS DOS CAMPOS SON ARGUMENTOS
     * ----------------------------------------------------------------
     * Proveedores Exterior valua PENDIENTE_USD por el mes de
     * FECHA_PAGO_EFECTIVA; Crono Nacionalizacion valua IMPORTE_EST por el mes
     * de FECHA_NAC_EFECTIVA. La regla es la misma -el importe en dolares por la
     * cotizacion del mes en que se mueve- y el campo es lo unico distinto. Con
     * los nombres escritos adentro, la segunda pestana habria necesitado una
     * copia de esta funcion, que es como se desincronizan las reglas; es el
     * mismo criterio de importeProyectable() y aporteAlEje().
     *
     * EL DEFAULT SIGUE SIENDO VALOR_FOB_DOLAR Y NINGUN LLAMADOR LO USA. No es
     * un olvido de feature/comex-saldo-pendiente: el unico llamador que valuaba
     * el FOB ahora pasa PENDIENTE_USD explicito, y dejar el default apuntando
     * al FOB hace que quien escriba la llamada tenga que DECIDIR cual de los
     * dos quiere, en vez de heredar en silencio el que le toque al default del
     * dia. Los dos campos existen en la fila y valen cosas distintas.
     *
     * LA COTIZACION NO CAMBIO DE CRITERIO, NI SIQUIERA EL OVERRIDE. COTIZ_USD_
     * EDIT sigue siendo una correccion sobre QUE DOLAR se aplica, no sobre que
     * importe: se multiplica por el pendiente igual que antes se multiplicaba
     * por el FOB, y descartaCotizacion() sigue atada al cambio de mes del pago.
     * Un contenedor cancelado con override cargado vale cero, porque cero por
     * cualquier cotizacion es cero, y eso es lo correcto: lo que dejo de haber
     * es el importe, no el dolar.
     *
     * LOS NOMBRES DE SALIDA SON LOS MISMOS EN LAS DOS -IMPORTE_ARS, COTIZ_USD,
     * COTIZ_MES, COTIZ_ORIGEN, COTIZ_MOTIVO, COTIZ_DETALLE- porque es el mismo
     * concepto y avisosValuacion() los lee por nombre.
     *
     * EL OVERRIDE SOLO EXISTE EN PROVEEDORES EXTERIOR. La consulta de Crono
     * Nacionalizacion no trae COTIZ_USD_EDIT, asi que esas filas se valuan
     * SIEMPRE con la curva. No es un olvido: el override vive en una tabla con
     * UNA fila por contenedor, y las dos pestanas valuan el mismo contenedor en
     * dos fechas distintas -la de pago y la de nacionalizacion- que caen en
     * meses distintos. Compartirlo aplicaria a la nacionalizacion una
     * correccion que alguien cargo pensando en el pago, y descartaCotizacion()
     * -atada al cambio de mes DEL PAGO- no sabe nada de la otra fecha.
     *
     * @param array $row Fila cruda, con su fecha efectiva ya resuelta
     * @param array $curva Lo que devolvio DolarFuturo::curva()
     * @param string $campoImporte De donde sale el importe EN DOLARES
     * @param string $campoFecha Que fecha decide el mes de la curva
     * @return array La misma fila con la valuacion agregada
     */
    public static function valuar($row, $curva, $campoImporte = 'VALOR_FOB_DOLAR',
                                  $campoFecha = 'FECHA_PAGO_EFECTIVA') {
        $usd = isset($row[$campoImporte]) ? floatval($row[$campoImporte]) : 0;

        $cot = DolarFuturo::resolver(
            $curva,
            isset($row[$campoFecha]) ? $row[$campoFecha] : null,
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
            : round($usd * $cot['cotizacion'], 2);

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
     * EL CAMPO Y EL NOMBRE DE LA FECHA SON ARGUMENTOS desde que las dos
     * pestanas valuan. El importe en dolares se llama VALOR_FOB_DOLAR en una e
     * IMPORTE_EST en la otra, y la fecha que falta es la de pago o la de
     * nacionalizacion. Con los dos escritos adentro, el aviso de Crono
     * Nacionalizacion habria dicho "U$S 0,00" -sumando un campo que esa pestana
     * no trae- y habria nombrado una fecha que no es la que falta: un aviso que
     * dice algo distinto de lo que paso es peor que no tenerlo.
     *
     * @param array $filas Las filas ya valuadas de cualquiera de las dos
     * @param string|null $ultimoMesCurva Hasta donde llega la curva, para el aviso
     * @param string $campoImporte De donde sale el importe EN DOLARES
     * @param string $queEs Como se nombra en el mensaje la fecha que falta
     * @return array Lista de mensajes
     */
    /**
     * @param string $queSon Como se nombra lo que se cuenta. Los dos
     *        consumidores originales cuentan CONTENEDORES, pero Compras
     *        Proyectadas cuenta MESES: su unidad no es una fila del maestro
     *        sino un mes de la ventana. Con el sustantivo escrito adentro, su
     *        aviso mandaria a "cargarle la fecha" a un contenedor que no
     *        existe.
     */
    public static function avisosValuacion($filas, $ultimoMesCurva = null,
                                           $campoImporte = 'VALOR_FOB_DOLAR',
                                           $queEs = 'fecha estimada de pago',
                                           $queSon = 'contenedor(es)') {
        return Aviso::textos(self::avisosValuacionConNivel($filas, $ultimoMesCurva,
            $campoImporte, $queEs, $queSon));
    }

    /**
     * Los mismos avisos de avisosValuacion(), con su gravedad. Lo lee el
     * tablero; las pestanas leen los textos.
     *
     * Lo que no se pudo valuar no entra y se arregla cargando la fecha:
     * atencion. Valuado con el mes mas cercano o con la cotizacion corregida a
     * mano es un criterio ya aplicado, y esta marcado en la grilla:
     * informativo.
     *
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosValuacionConNivel($filas, $ultimoMesCurva = null,
                                                   $campoImporte = 'VALOR_FOB_DOLAR',
                                                   $queEs = 'fecha estimada de pago',
                                                   $queSon = 'contenedor(es)') {
        $sinValuar = 0;
        $usdSinValuar = 0.0;
        $aproximadas = 0;
        $overrides = 0;
        $avisos = [];

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (!isset($f['COTIZ_USD']) || $f['COTIZ_USD'] === null) {
                $sinValuar++;
                $usdSinValuar += isset($f[$campoImporte])
                    ? floatval($f[$campoImporte]) : 0;
                continue;
            }

            if ($f['COTIZ_ORIGEN'] === DolarFuturo::ORIGEN_APROXIMADA) {
                $aproximadas++;
            } elseif ($f['COTIZ_ORIGEN'] === DolarFuturo::ORIGEN_OVERRIDE) {
                $overrides++;
            }
        }

        if ($sinValuar > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $sinValuar . ' ' . $queSon . ' por U$S '
                . number_format($usdSinValuar, 2, ',', '.') . ' no tienen ' . $queEs
                . ', así que no hay mes al que pedirle cotización y no se pueden valuar en '
                . 'pesos. No se les aplica ningún tipo de cambio inventado: cargales la fecha '
                . 'y el importe aparece.');
        }

        /* "CAEN EN" y no "se pagan en": el mismo texto lo usa Crono
           Nacionalización, donde lo que cae en ese mes es la nacionalización y
           no un pago. */
        if ($aproximadas > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $aproximadas . ' ' . $queSon
                . ' caen en un mes que la curva de dólar futuro no cubre'
                . ($ultimoMesCurva === null ? '' : ' (llega hasta ' . $ultimoMesCurva . ')')
                . ', así que se valuaron con la cotización del mes más cercano. Están marcados '
                . 'en la grilla.');
        }

        if ($overrides > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $overrides . ' ' . $queSon
                . ' tienen la cotización corregida a mano, que manda sobre la curva. Están '
                . 'marcados en la grilla.');
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
     * enterarse de por que.
     *
     * SOLO APLICA A 'PAGO'. Este docblock decia hasta feature/comex-nac-usd que
     * el motivo era que "la nacionalizacion no se valua en dolares", y eso ya no
     * es cierto: se valua, con la curva del mes de su propia fecha. El motivo
     * verdadero es otro y es el mismo por el que Crono Nacionalizacion no tiene
     * override: COTIZ_USD_EDIT es UNA fila por contenedor y las dos pestanas lo
     * miran en dos fechas distintas. Mover la fecha de nacionalizacion no puede
     * descartar una correccion que se cargo para el mes del pago. Ver valuar().
     *
     * @param string $campo 'PAGO' o 'NAC'
     * @param int $idMg ID del contenedor en el maestro
     * @param mixed $fechaNueva Fecha nueva, 'Y-m-d'
     * @param string $usuario Quien edita: el de la sesion. Firma el rastro y la
     *        marca FECHA_PAGO_CONF_USUARIO del maestro
     * @return array ['campo', 'id_mg', 'fecha_anterior', 'fecha_nueva',
     *                'sin_cambios', 'cotizacion_descartada', …]
     */
    public function guardarFecha($campo, $idMg, $fechaNueva, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

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
                 SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                 WHERE ID_MG = ? AND CAMPO = ? AND VIGENTE = 1",
                [$usuario, $usuario, $idMg, $campo],
                'Error al dar de baja el rastro anterior');

            $this->ejecutar($cid,
                "INSERT INTO " . self::TABLA_HISTORIAL . "
                     (ID_MG, CAMPO, FECHA_ANTERIOR, FECHA_NUEVA, VIGENTE, USUARIO_ALTA,
                      USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, 1, ?, ?)",
                [$idMg, $campo, $anterior, $nueva, $usuario, $usuario],
                'Error al guardar el rastro de la edición');

            /* El override solo se limpia si la columna existe: sin el script de
               cotizacion corrido, la instalacion guarda la fecha igual. */
            if ($tieneCotiz && $r['cotizacion_descartada']) {
                $this->ejecutar($cid,
                    "UPDATE " . self::TABLA_EDIT . "
                     SET COTIZ_USD_EDIT = NULL, " . Auditoria::SET_MODIF . "
                     WHERE ID_MG = ?",
                    [$usuario, $idMg],
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
    private function ejecutar($cid, $sql, $params, $contexto, $tabla = null) {
        $stmt = sqlsrv_query($cid, $sql, $params);

        /* El error nombra la tabla contra la que fallo: con el pago parcial esta
           clase escribe en seis, y uno que nombrara siempre la del override
           mandaria a mirar la equivocada. */
        if ($stmt === false) {
            throw new Exception($tabla === null
                ? $this->errorSql($contexto) : $this->errorSqlEn($tabla, $contexto));
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
     * @param mixed $obs Observacion opcional, null si no hay
     * @param string $usuario Quien marca: el de la sesion
     * @return array ['concepto', 'id_mg', 'pagado', 'sin_cambios']
     */
    public function marcarPagado($concepto, $idMg, $pagado, $obs, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

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
                 SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
                 WHERE ID_MG = ? AND CONCEPTO = ? AND VIGENTE = 1",
                [$usuario, $usuario, $idMg, $concepto],
                'Error al dar de baja la marca anterior');

            if ($pagado) {
                $this->ejecutar($cid,
                    "INSERT INTO " . self::TABLA_PAGADO . "
                         (ID_MG, CONCEPTO, OBSERVACION, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, 1, ?, ?)",
                    [$idMg, $concepto, $obs, $usuario, $usuario],
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
            "SELECT ID, ID_MG, CONCEPTO, OBSERVACION, VIGENTE, USUARIO_ALTA, FECHA_ALTA,
                    USUARIO_BAJA, FECHA_BAJA
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
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_BAJA, FECHA_BAJA
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
     * @param string $usuario
     * @return array ['cotizacion' => float|null, 'id_mg' => int]
     */
    public function updateCotizacion($idMg, $cotizacion, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

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
                 SET COTIZ_USD_EDIT = ?, " . Auditoria::SET_MODIF . "
                 WHERE ID_MG = ?",
                [$valor, $usuario, $idMg]);
        } else {
            /* LOS CENTINELAS SIGUEN HACIENDO FALTA aunque nadie lea ya esas
               columnas: FECHA_NAC_ORIG y FECHA_NAC_EDIT nacieron NOT NULL, y
               esta fila se inserta solo para guardar el override. Vaciarlas
               seria un ALTER sobre una tabla que este modulo no necesita
               cambiar, y ponerles una fecha real inventaria una edicion. */
            $stmt = sqlsrv_query($cid,
                "INSERT INTO " . self::TABLA_EDIT . "
                     (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, COTIZ_USD_EDIT, USUARIO_ALTA,
                      USUARIO_MODIF)
                 VALUES (?, '1900-01-01', '1900-01-01', ?, ?, ?)",
                [$idMg, $valor, $usuario, $usuario]);
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
     * LOS GASTOS DE NACIONALIZACION ESTAN EN DOLARES Y SE VALUAN ACA
     * -------------------------------------------------------------
     * IMPORTE_EST -la suma de los conceptos 3 a 10- sale de una cadena que
     * arranca en VALOR_FOB_DOLAR, asi que esta EN DOLARES, igual que el pago al
     * proveedor. Hasta feature/comex-nac-usd esta pestana lo ubicaba en el eje
     * como si fueran pesos; ver el encabezado de ComexProvider, que es donde
     * estaba escrita la afirmacion equivocada.
     *
     * Entonces cada fila se valua igual que en Proveedores Exterior: con la
     * cotizacion de la curva ROFEX del mes de SU fecha efectiva, que aca es la
     * de nacionalizacion y no la de pago. El importe en dolares se sigue
     * devolviendo en IMPORTE_EST -la grilla lo muestra- y lo que entra al eje
     * es IMPORTE_ARS.
     *
     * Y DESDE feature/cashflow-avisos-kpis-comex-nac-parcial:
     *
     *   - SOLO LOS CONTENEDORES DESDE "Contenedores desde", por la fecha de
     *     movimiento de la OC principal: sqlDesdeFechaMov(), el mismo corte de
     *     los dos padrones.
     *   - LOS CONTENEDORES CON COSTOS REALES VUELVEN A LA LISTA, que antes los
     *     dejaba afuera (LEFT JOIN al detalle ... WHERE ID_MG IS NULL). Siguen
     *     sin sumar -se toman como ya pagados- salvo que tengan un pago parcial
     *     con saldo: ahi suman ese saldo. La grilla los esconde detras de "Ver
     *     con costos reales". SIN sql/cashflow_comex_nac_pago.sql siguen
     *     afuera, como siempre: sin pagos parciales no tienen nada que ofrecer.
     *   - CADA FILA TRAE LA BASE, LO PAGADO Y EL SALDO DE SU CONTENEDOR, y lo
     *     que aporta a cada serie: repartoNacionalizacion(). La estimacion se
     *     lee de la OC principal, que es donde la guarda Comercio Exterior, y
     *     las hijas no suman (DUPLICA_GRUPO).
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
                    " . $this->pagadoSelect() . ",
                    " . self::OC_PRINCIPAL . " GRUPO_ID,
                    " . self::duplicaGrupoSelect() . ",
                    CASE WHEN " . self::sqlTieneCostos() . " THEN 1 ELSE 0 END TIENE_COSTOS,
                    CR.COSTO_REAL_ARS,
                    " . $this->nacPagoSelect() . "
                FROM " . self::TABLA_MAESTRO . " A
                /* LA ESTIMACION ES DEL CONTENEDOR y Comercio Exterior la guarda en
                   la OC principal: se lee de ahi. Una hija lee la misma y no suma
                   (DUPLICA_GRUPO). Al 20/10/2026, 114 estimaciones y ninguna en
                   una hija. */
                LEFT JOIN
                (
                    SELECT ID_MG, SUM(IMPORTE) IMPORTE_EST
                    FROM RO_T_IMPORTACIONES_ESTIMACION_DETALLE
                    WHERE ID_CE BETWEEN 3 AND 10
                    GROUP BY ID_MG
                ) C ON C.ID_MG = " . self::OC_PRINCIPAL . "
                " . self::costoRealApply() . "
                " . $this->nacPagoApply() . "
                " . $this->rastroJoin('NAC') . "
                " . $this->pagadoJoin('NAC') . "
                WHERE " . ($this->tieneNacPago() ? '1 = 1' : 'NOT ' . self::sqlTieneCostos()) . "
                  AND " . self::sqlDesdeFechaMov($this->fechaMovDesdeVigente()) . "
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

        // La curva se lee UNA vez para todo el listado, no una por fila, igual
        // que en getProveedoresExterior().
        $curva = $this->dolarFuturo()->curva();

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row = self::aTexto($row, ['FECHA_EST_EMB', 'ETD', 'ETA', 'FECHA_NAC',
                'EDIT_ANTERIOR', 'EDIT_VALOR', 'EDIT_FECHA', 'PAGADO_FECHA',
                'FECHA_PAGO_CONF_FECHA', 'NAC_FECHA_SALDO']);

            if (isset($row['NAC_FECHA_SALDO_ALTA']) && $row['NAC_FECHA_SALDO_ALTA'] instanceof DateTime) {
                $row['NAC_FECHA_SALDO_ALTA'] = $row['NAC_FECHA_SALDO_ALTA']->format('Y-m-d H:i:s');
            }

            /* Un BIT de SQL Server llega como '1'/'0', y la base y los pagos como
               texto: se normalizan aca, una sola vez, porque de ellos depende lo
               que la fila aporta a cada serie. */
            $row['TIENE_COSTOS'] = !empty($row['TIENE_COSTOS']) && $row['TIENE_COSTOS'] != '0';
            $row['DUPLICA_GRUPO'] = !empty($row['DUPLICA_GRUPO']) && $row['DUPLICA_GRUPO'] != '0';
            $row['GRUPO_ID'] = intval($row['GRUPO_ID']);
            $row['GRUPO_FILAS'] = isset($row['GRUPO_FILAS']) ? intval($row['GRUPO_FILAS']) : 1;
            $row['COSTO_REAL_ARS'] = ($row['COSTO_REAL_ARS'] === null) ? null : floatval($row['COSTO_REAL_ARS']);
            $row['NAC_PAGADO_ARS'] = floatval($row['NAC_PAGADO_ARS']);
            $row['NAC_PAGOS_CANT'] = intval($row['NAC_PAGOS_CANT']);

            $row = self::conFechaEfectiva($row, 'FECHA_NAC', 'FECHA_NAC_EFECTIVA', $hoy);

            /* EL GASTO ESTA EN DOLARES: se valua con la curva del mes de la
               fecha de NACIONALIZACION, que es cuando se mueve. IMPORTE_EST
               queda como esta -es el importe en dolares y la grilla lo
               muestra-. */
            $row = self::valuar($row, $curva, 'IMPORTE_EST', 'FECHA_NAC_EFECTIVA');

            /* LA BASE, EL SALDO Y LO QUE APORTA A CADA SERIE, en una sola
               funcion pura: repartoNacionalizacion(). Sin pagos parciales da lo
               mismo que las dos reglas de Proveedores Exterior -lo vencido y lo
               tildado no suman- sobre IMPORTE_ARS, el importe en PESOS. */
            $row = self::repartoNacionalizacion($row, $hoy);

            // Solo decide lo que se muestra: ver vencidaPendiente().
            $row['VENCIDA_PENDIENTE'] = self::vencidaPendiente($row);

            $v[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       EL PAGO PARCIAL DE LA NACIONALIZACION

       Desde feature/cashflow-avisos-kpis-comex-nac-parcial. Hasta ahi la
       nacionalizacion era todo o nada: el tilde "Pagado" saca el importe entero
       de la proyeccion. Pero se puede pagar una parte y quedar un saldo, y ese
       saldo es un egreso que el tablero tiene que seguir proyectando, en la
       fecha en que se lo espera pagar.

       EL DATO ES DEL CASHFLOW. Los pagos parciales y la fecha del saldo viven en
       RO_T_CASHFLOW_COMEX_NAC_PAGO y RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA
       (sql/cashflow_comex_nac_pago.sql). Comercio Exterior no se entera, y este
       modulo no escribe nada del otro lado.

       LA BASE, EN PESOS, Y POR QUE HAY DOS
       ------------------------------------
         - CON COSTOS REALES (filas en RO_T_IMPORTACIONES_DETALLE para cualquier
           OC del grupo, la (A) de sqlTieneCostos()): el Costo Total Real, la
           suma de IMPORTE_$ de esas filas. Ya esta en pesos: cada fila trae su
           tipo de cambio. Es el total que muestra Comercio Exterior.
         - SIN COSTOS REALES: el Costo Total Estimado, IMPORTE_ARS, o sea los
           conceptos 3 a 10 de la estimacion valuados con la curva ROFEX del mes
           de la fecha de NACIONALIZACION. La fecha del saldo NO cambia la
           cotizacion: solo decide en que columna cae.

       LAS DOS BASES NO TIENEN LA MISMA COMPOSICION, y hay que saberlo para leer
       un saldo. La real incluye el FLETE INTERNACIONAL (es una linea del maestro
       de gastos de Comex) y no los impuestos que la estimacion calcula (IVA,
       IVA adicional, ganancias, IIBB: conceptos 5 a 8); la estimada, al reves.
       Por eso, cuando un contenedor pasa de estimado a real, EL SALDO PUEDE
       CAMBIAR POR COMPOSICION Y NO SOLO POR PRECISION: no es que la estimacion
       erro, es que mide otra cosa. Y puede quedar pagado de mas: ver
       saldoNacionalizacion().

       UNA SOLA CLAVE: EL CONTENEDOR, O SEA LA OC PRINCIPAL
       ----------------------------------------------------
       En Comercio Exterior la estimacion y los pagos son del contenedor y viven
       en la OC principal, y los costos reales se cargan desde la principal y se
       COPIAN ENTEROS en cada OC del grupo (OrdenDeCompra::insertDetalleReplicado,
       repo administracion). Sumar el detalle del grupo multiplicaria el costo
       por la cantidad de OCs; Comex imprime la copia de UNA.

       Asi que la base, los pagos y la fecha del saldo son del contenedor:
       COALESCE(ID_PADRE, ID). Se graba contra la principal -la resuelve el
       servidor, nunca el navegador- y SE VUELVE A RESOLVER AL LEER: si una OC
       que era principal pasa a ser hija de otra, sus pagos siguen apareciendo
       en el contenedor. En la grilla, una fila por OC: todo lo del contenedor
       va en la fila titular (DUPLICA_GRUPO = 0, el mismo criterio que
       Proveedores Exterior) y las hijas no suman. Al 20/10/2026 no hay una sola
       OC hija, asi que hoy esto no cambia ninguna fila.
       ==================================================================== */

    /** Los pagos parciales de la nacionalizacion. Ver el bloque de arriba */
    const TABLA_NAC_PAGO = 'RO_T_CASHFLOW_COMEX_NAC_PAGO';

    /** La fecha del saldo de cada contenedor, con historial */
    const TABLA_NAC_SALDO_FECHA = 'RO_T_CASHFLOW_COMEX_NAC_SALDO_FECHA';

    /**
     * UN PESO, y no TOLERANCIA_SALDO.
     *
     * TOLERANCIA_SALDO es un centavo DE DOLAR, copiada de Comercio Exterior
     * para que el saldo del FOB de las dos aplicaciones de el mismo numero.
     * Este saldo es otro: es del cashflow, esta en PESOS y se arma con
     * importes que no cierran al centavo por construccion -un pago en dolares
     * pasado a pesos con su cotizacion, contra una base que es una suma de
     * lineas con su propio tipo de cambio, o una estimacion valuada con la
     * curva-. Con un centavo, un contenedor pagado entero quedaria
     * proyectando "$ 0,37" para siempre; un peso no mueve ningun numero del
     * tablero y cierra esos casos.
     */
    const TOLERANCIA_SALDO_NAC = 1.0;

    /** @var bool|null Cache de si existen las tablas del pago parcial */
    private $nacPago = null;

    /**
     * El saldo de la nacionalizacion de un contenedor. PURA.
     *
     *     saldo = base - suma de los pagos parciales vigentes
     *
     *   - NUNCA NEGATIVO. Si lo pagado supera la base, el saldo es cero y lo
     *     que sobra va en 'sobrepago', para que la pestana lo avise en pesos:
     *     un egreso negativo seria un ingreso que nadie afirmo. Pasa sobre todo
     *     cuando el contenedor pasa de estimado a real y el costo baja.
     *   - CUBIERTO: hay pagos y cubren la base, con la tolerancia de un peso.
     *     El contenedor se comporta como pagado aunque nadie lo haya tildado.
     *   - SIN BASE (sin estimacion ni costo real, o sin curva), el saldo es null
     *     y no cero: null es "no se sabe", y el aviso de valuacion lo dice.
     *   - 'imputado' es la parte de la base que los pagos ya cubrieron, y se
     *     devuelve calculada -base menos saldo- para que las series cierren al
     *     centavo, igual que saldoPendiente().
     *
     * @param mixed $base En pesos, o null
     * @param mixed $pagado Suma de los pagos vigentes, en pesos
     * @return array ['base','pagado','saldo','imputado','sobrepago','cubierto']
     */
    public static function saldoNacionalizacion($base, $pagado) {
        $p = max(0.0, floatval($pagado));

        if ($base === null) {
            return ['base' => null, 'pagado' => $p, 'saldo' => null, 'imputado' => null,
                    'sobrepago' => 0.0, 'cubierto' => false];
        }

        $b = max(0.0, floatval($base));
        $dif = $b - $p;
        $cubierto = ($p > 0 && $dif <= self::TOLERANCIA_SALDO_NAC);
        $saldo = $cubierto ? 0.0 : round($dif, 2);

        return [
            'base' => $b,
            'pagado' => $p,
            'saldo' => $saldo,
            'imputado' => round($b - $saldo, 2),
            'sobrepago' => (-$dif > self::TOLERANCIA_SALDO_NAC) ? round(-$dif, 2) : 0.0,
            'cubierto' => $cubierto
        ];
    }

    /**
     * El contenedor de una OC: COALESCE(ID_PADRE, ID). PURA.
     *
     * Es la clave de la base, de los pagos y de la fecha del saldo, y se
     * resuelve AL GRABAR y AL LEER: un pago grabado contra una OC que despues
     * paso a ser hija de otra pertenece al contenedor nuevo. El SQL hace lo
     * mismo con COALESCE(NE.ID_PADRE, NE.ID) en nacPagoApply(); esta version
     * es la que se prueba sin base.
     *
     * @param int $idMg
     * @param array $padrePorId id => ID_PADRE (null si es principal)
     * @return int
     */
    public static function contenedorDe($idMg, $padrePorId) {
        $id = intval($idMg);

        return (isset($padrePorId[$id]) && $padrePorId[$id] !== null)
            ? intval($padrePorId[$id]) : $id;
    }

    /**
     * Lo pagado y la cantidad de pagos de cada contenedor, a partir de los
     * pagos vigentes. PURA: es nacPagoApply() escrita en PHP, para probar el
     * caso que la base de hoy no tiene -una OC que cambio de grupo-.
     *
     * @param array $pagos Lista de ['ID_MG', 'IMPORTE_ARS', 'VIGENTE']
     * @param array $padrePorId id => ID_PADRE
     * @return array contenedor => ['ars' => float, 'cant' => int]
     */
    public static function pagosPorContenedor($pagos, $padrePorId) {
        $v = [];

        foreach (is_array($pagos) ? $pagos : [] as $p) {
            if (isset($p['VIGENTE']) && empty($p['VIGENTE'])) {
                continue;
            }

            $c = self::contenedorDe($p['ID_MG'], $padrePorId);

            if (!isset($v[$c])) {
                $v[$c] = ['ars' => 0.0, 'cant' => 0];
            }

            $v[$c]['ars'] += floatval($p['IMPORTE_ARS']);
            $v[$c]['cant']++;
        }

        return $v;
    }

    /**
     * De que OC del grupo se lee el costo real. PURA.
     *
     * De LA PRINCIPAL: Comex carga los costos desde ahi y los copia en cada
     * OC. Si la principal no tuviera copia -no deberia pasar- la de ID mas
     * chico que la tenga. Nunca la suma de las copias. El SQL de
     * costoRealApply() ordena igual.
     *
     * @param int $principal
     * @param int[] $conDetalle IDs del grupo con filas en el detalle
     * @return int|null
     */
    public static function copiaCostoReal($principal, $conDetalle) {
        $ids = array_map('intval', is_array($conDetalle) ? $conDetalle : []);

        if (in_array(intval($principal), $ids, true)) {
            return intval($principal);
        }

        if (empty($ids)) {
            return null;
        }

        sort($ids);

        return $ids[0];
    }

    /**
     * Le pone a una fila de Crono la base, el saldo y lo que aporta a cada
     * serie del tablero. PURA, y la unica que decide esos numeros: la grilla,
     * el tablero y los avisos leen los campos que deja.
     *
     * LAS TRES SERIES Y EN QUE COLUMNA VA CADA PARTE
     * ---------------------------------------------
     * Un contenedor con pagos parciales tiene dos pedazos en dos fechas:
     *
     *   el SALDO, en la fecha del saldo (la cargada, o la de nacionalizacion)
     *   lo IMPUTADO -la parte de la base ya pagada-, en la de nacionalizacion
     *
     *   NACIONALIZACION         el saldo, salvo que este tildado o cubierto
     *   NACIONALIZACION_PAGADAS el saldo si esta tildado, MAS lo imputado
     *   NACIONALIZACION_TODO    el saldo MAS lo imputado: la base entera
     *
     * TODO se arma con las dos partes y no con la base en una sola fecha, y
     * eso es lo que hace que NACIONALIZACION + NACIONALIZACION_PAGADAS =
     * NACIONALIZACION_TODO cierre COLUMNA POR COLUMNA aunque el saldo caiga en
     * otra fecha: cada pedazo esta en las dos series, en la misma columna.
     * Cada pedazo se anula con la regla de vencidas de SU fecha: el saldo con
     * la del saldo, lo imputado con la de nacionalizacion.
     *
     * SIN PAGOS PARCIALES es exactamente lo de antes: el saldo es la base, la
     * fecha del saldo es la de nacionalizacion y no hay nada imputado.
     *
     * Y NO ENTRAN EN NINGUNA SERIE: las OC hijas (DUPLICA_GRUPO: el contenedor
     * lo proyecta la titular) y los contenedores con costos reales SIN pagos
     * parciales, que como siempre se toman como ya pagados. Con pagos
     * parciales, su base de TODO pasa a ser el costo real.
     *
     * @param array $fila Con IMPORTE_ARS, TIENE_COSTOS, COSTO_REAL_ARS,
     *        NAC_PAGADO_ARS, NAC_PAGOS_CANT, NAC_FECHA_SALDO,
     *        FECHA_NAC_EFECTIVA, PAGADO y DUPLICA_GRUPO resueltos
     * @param string $hoy 'Y-m-d'
     * @return array La fila con la base, el saldo y los aportes
     */
    public static function repartoNacionalizacion($fila, $hoy) {
        $real = !empty($fila['TIENE_COSTOS']);
        $parciales = isset($fila['NAC_PAGOS_CANT']) && intval($fila['NAC_PAGOS_CANT']) > 0;
        $duplica = !empty($fila['DUPLICA_GRUPO']);
        $tilde = !empty($fila['PAGADO']);

        $base = $real
            ? (isset($fila['COSTO_REAL_ARS']) && $fila['COSTO_REAL_ARS'] !== null
                ? floatval($fila['COSTO_REAL_ARS']) : 0.0)
            : (isset($fila['IMPORTE_ARS']) && $fila['IMPORTE_ARS'] !== null
                ? floatval($fila['IMPORTE_ARS']) : null);

        $s = self::saldoNacionalizacion($base,
            $parciales ? (isset($fila['NAC_PAGADO_ARS']) ? $fila['NAC_PAGADO_ARS'] : 0) : 0);

        $fechaNac = isset($fila['FECHA_NAC_EFECTIVA']) ? $fila['FECHA_NAC_EFECTIVA'] : null;
        $fechaCargada = ($parciales && !empty($fila['NAC_FECHA_SALDO']))
            ? Horizonte::normalizarFecha($fila['NAC_FECHA_SALDO']) : null;
        $fechaSaldo = ($fechaCargada !== null) ? $fechaCargada : $fechaNac;
        $saldoVencido = self::estaVencida($fechaSaldo, $hoy);

        // Con costos reales y sin pagos parciales: ya pagado, como siempre.
        $sinProyeccion = $real && !$parciales;

        if ($duplica || $sinProyeccion || $saldoVencido) {
            $saldoProy = 0.0;
        } else {
            $saldoProy = $s['saldo'];
        }

        $imputadoProy = ($duplica || !$parciales || self::estaVencida($fechaNac, $hoy))
            ? 0.0 : floatval($s['imputado']);

        $fila['BASE_ORIGEN'] = $real ? 'REAL' : 'ESTIMADO';
        $fila['BASE_ARS'] = $s['base'];
        $fila['NAC_CON_PARCIALES'] = $parciales;
        $fila['NAC_PAGADO_ARS'] = $parciales ? $s['pagado'] : 0.0;
        $fila['SALDO_ARS'] = $s['saldo'];
        $fila['NAC_IMPUTADO_ARS'] = $parciales ? $s['imputado'] : 0.0;
        $fila['NAC_SOBREPAGO_ARS'] = $s['sobrepago'];
        $fila['CUBIERTA_PARCIALES'] = $s['cubierto'];
        $fila['FECHA_SALDO_CARGADA'] = $fechaCargada;
        $fila['FECHA_SALDO_EFECTIVA'] = $fechaSaldo;
        $fila['NAC_SIN_PROYECCION'] = $sinProyeccion;

        /* LA FECHA QUE VENCE ES LA DEL SALDO: es la que decide si el importe
           que falta entra. Sin pagos parciales es la de nacionalizacion, o sea
           lo de siempre. Un saldo vencido no suma, se avisa con los vencidos y
           se corrige cambiando la fecha. */
        $fila['VENCIDA'] = $saldoVencido;

        /* Una fila con costos reales se esconde detras de "Ver con costos
           reales", SALVO que sume: con un pago parcial y saldo mayor a cero
           se ve siempre. Una fila que suma no puede estar escondida. */
        $fila['OCULTA_POR_COSTOS'] = $real
            && !($parciales && $s['saldo'] !== null && $s['saldo'] > 0);

        $fila['IMPORTE_EJE'] = ($tilde || $s['cubierto'])
            ? 0.0 : (($saldoProy === null) ? null : floatval($saldoProy));
        $fila['NAC_PAGADAS_SALDO'] = $tilde ? $saldoProy : 0.0;
        $fila['NAC_TODO_SALDO'] = $saldoProy;
        $fila['NAC_IMPUTADO_PROY'] = $imputadoProy;

        // Lo que el tilde saca de la proyeccion: el saldo. Lo lee avisosPagados().
        $fila['IMPORTE_PROYECTABLE'] = $saldoProy;

        return $fila;
    }

    /**
     * La validacion de un pago parcial, en el servidor. PURA.
     *
     *   - fecha de pago: una fecha real, hoy o anterior. Una fecha futura no
     *     es un pago.
     *   - moneda ARS o USD. En pesos, el importe. En dolares, el importe en
     *     dolares y la cotizacion usada: el equivalente en pesos lo calcula
     *     esto, con enPesos(), y es lo que se resta.
     *   - importe mayor a cero.
     *   - fecha del saldo: OBLIGATORIA si la nacionalizacion ya vencio o no
     *     tiene fecha -sin ella el saldo no tendria donde caer-, y siempre hoy o
     *     posterior. Si la nacionalizacion no vencio es opcional: sin ella el
     *     saldo cae en la de nacionalizacion.
     *
     * El dialogo valida lo mismo para explicar y bloquear el boton; esto es lo
     * que decide.
     *
     * @param array $datos fecha_pago, moneda, importe, importe_usd, cotizacion,
     *        fecha_saldo, observacion
     * @param string $hoy 'Y-m-d'
     * @param string|null $fechaNac La fecha de nacionalizacion efectiva
     * @return array ['fecha_pago','moneda','importe_usd','cotizacion','importe_ars','fecha_saldo','observacion']
     * @throws Exception Con el motivo, para mostrar tal cual
     */
    public static function validarPagoNac($datos, $hoy, $fechaNac) {
        $hoy = substr((string) $hoy, 0, 10);
        $fecha = self::fechaValida(isset($datos['fecha_pago']) ? $datos['fecha_pago'] : null);

        if ($fecha === null) {
            throw new Exception('Falta la fecha de pago, o no es una fecha.');
        }

        if ($fecha > $hoy) {
            throw new Exception('La fecha de pago no puede ser futura: un pago que todavía no se '
                . 'hizo no es un pago. Lo que falta se proyecta como saldo.');
        }

        $moneda = strtoupper(trim((string) (isset($datos['moneda']) ? $datos['moneda'] : '')));

        if ($moneda !== 'ARS' && $moneda !== 'USD') {
            throw new Exception('La moneda tiene que ser pesos o dólares.');
        }

        $usd = null;
        $cotiz = null;

        if ($moneda === 'USD') {
            $usd = self::numeroPositivo(isset($datos['importe_usd']) ? $datos['importe_usd'] : null);
            $cotiz = self::numeroPositivo(isset($datos['cotizacion']) ? $datos['cotizacion'] : null);

            if ($usd === null) {
                throw new Exception('El importe en dólares tiene que ser mayor a cero.');
            }

            if ($cotiz === null) {
                throw new Exception('Falta la cotización con la que se pagaron los dólares.');
            }

            $ars = self::enPesos($usd, $cotiz);
        } else {
            $ars = self::numeroPositivo(isset($datos['importe']) ? $datos['importe'] : null);
        }

        if ($ars === null || $ars <= 0) {
            throw new Exception('El importe tiene que ser mayor a cero.');
        }

        $saldoCrudo = isset($datos['fecha_saldo']) ? trim((string) $datos['fecha_saldo']) : '';
        $fechaSaldo = self::fechaValida($saldoCrudo);

        if ($saldoCrudo !== '' && $fechaSaldo === null) {
            throw new Exception('La fecha del saldo no es una fecha.');
        }

        $nac = Horizonte::normalizarFecha($fechaNac);

        if ($fechaSaldo === null && ($nac === null || $nac < $hoy)) {
            throw new Exception($nac === null
                ? 'El contenedor no tiene fecha de nacionalización: cargá la fecha en la que se '
                    . 'va a pagar el saldo.'
                : 'La fecha de nacionalización ya venció: cargá la fecha en la que se va a pagar '
                    . 'el saldo, si no lo que falta no entra en ninguna columna.');
        }

        if ($fechaSaldo !== null && $fechaSaldo < $hoy) {
            throw new Exception('La fecha del saldo tiene que ser hoy o posterior: es cuándo se '
                . 'espera pagar lo que falta.');
        }

        $obs = trim((string) (isset($datos['observacion']) ? $datos['observacion'] : ''));

        return [
            'fecha_pago' => $fecha,
            'moneda' => $moneda,
            'importe_usd' => $usd,
            'cotizacion' => $cotiz,
            'importe_ars' => round($ars, 2),
            'fecha_saldo' => $fechaSaldo,
            'observacion' => ($obs === '') ? null : mb_substr($obs, 0, 200)
        ];
    }

    /** Una fecha 'aaaa-mm-dd' real, o null */
    private static function fechaValida($valor) {
        $v = trim((string) $valor);

        return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
            && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) ? $v : null;
    }

    /** Un numero mayor a cero, o null. Acepta coma decimal */
    private static function numeroPositivo($valor) {
        $v = str_replace(',', '.', trim((string) $valor));

        if ($v === '' || !is_numeric($v) || floatval($v) <= 0) {
            return null;
        }

        return floatval($v);
    }

    /**
     * Los avisos de los pagos parciales, con nivel. Los emite ComexProvider
     * -y por eso los dice tambien la pestana, que muestra los del tablero-.
     *
     *   - Informativo: cuantos contenedores tienen pagos parciales, cuanto se
     *     pago y cuanto saldo falta. Es un criterio ya aplicado que explica por
     *     que la fila del tablero proyecta menos que la estimacion.
     *   - Atencion: el pagado de mas, en pesos. El saldo se toma como cero y la
     *     diferencia no se ve en ninguna columna; hay que revisar los pagos.
     *
     * @param array $filas Las de getCronoNacionalizacion()
     * @return array Lista de ['nivel', 'texto', 'seccion']
     */
    public static function avisosPagosParcialesConNivel($filas) {
        $cuantos = 0;
        $pagado = 0.0;
        $saldo = 0.0;
        $conSobrepago = 0;
        $sobrepago = 0.0;

        foreach (is_array($filas) ? $filas : [] as $f) {
            if (empty($f['NAC_CON_PARCIALES']) || !empty($f['DUPLICA_GRUPO'])) {
                continue;
            }

            $cuantos++;
            $pagado += floatval($f['NAC_PAGADO_ARS']);

            // Lo que falta, salvo que el tilde lo haya dado por pagado entero.
            if (empty($f['PAGADO'])) {
                $saldo += floatval($f['SALDO_ARS']);
            }

            if (!empty($f['NAC_SOBREPAGO_ARS'])) {
                $conSobrepago++;
                $sobrepago += floatval($f['NAC_SOBREPAGO_ARS']);
            }
        }

        $avisos = [];

        if ($cuantos > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, $cuantos . ' contenedor(es) con pagos parciales: '
                . self::plata($pagado) . ' pagados, ' . self::plata($saldo) . ' de saldo pendiente.');
        }

        if ($conSobrepago > 0) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $conSobrepago . ' contenedor(es) con pagado de '
                . 'más: ' . self::plata($sobrepago) . ' por encima de su costo de nacionalización. '
                . 'El saldo se toma como cero —el cashflow no proyecta egresos negativos— así que la '
                . 'diferencia no se ve en ninguna columna. Pasa cuando el costo real resulta menor '
                . 'que el estimado sobre el que se pagó, o con un pago cargado de más: revisá los '
                . 'pagos del contenedor.');
        }

        return $avisos;
    }

    /**
     * Si existen las tablas del pago parcial, de sql/cashflow_comex_nac_pago.sql.
     *
     * SE PREGUNTA en vez de darla por hecha, mismo patron que tienePagado():
     * sin ellas Crono funciona exactamente como hoy -no hay pagos parciales,
     * asi que nada cambia- y lo unico que no se puede es registrarlos.
     *
     * @return bool
     */
    public function tieneNacPago() {
        if ($this->nacPago !== null) {
            return $this->nacPago;
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "SELECT OBJECT_ID('dbo." . self::TABLA_NAC_PAGO . "', 'U') AS P,
                    OBJECT_ID('dbo." . self::TABLA_NAC_SALDO_FECHA . "', 'U') AS F");

        if ($stmt === false) {
            throw new Exception('Error al verificar las tablas del pago parcial');
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->nacPago = ($row && $row['P'] !== null && $row['F'] !== null);

        return $this->nacPago;
    }

    /**
     * El aviso de que el pago parcial esta apagado, o cadena vacia. Solo de la
     * pestana, igual que el del tilde: el tablero no edita nada.
     *
     * @return string
     */
    public function avisoSinNacPago() {
        if ($this->tieneNacPago()) {
            return '';
        }

        return 'El pago parcial de la nacionalización está apagado: faltan las tablas '
            . self::TABLA_NAC_PAGO . ' y ' . self::TABLA_NAC_SALDO_FECHA . '. Corré '
            . 'sql/cashflow_comex_nac_pago.sql contra la base central y el botón aparece solo. '
            . 'Todo lo demás de esta pantalla funciona igual: sin pagos parciales, se proyecta la '
            . 'nacionalización entera, y los contenedores con costos reales no se muestran.';
    }

    /**
     * Lo pagado, la cantidad de pagos y la fecha del saldo vigente del
     * contenedor, listos para el SELECT. SIN LAS TABLAS, literales con el
     * mismo nombre: ningun pago y ninguna fecha, que es exactamente lo que
     * significa. Mismo truco que pagadoSelect().
     *
     * @return string
     */
    private function nacPagoSelect() {
        if (!$this->tieneNacPago()) {
            return "CAST(0 AS DECIMAL(18,2))   NAC_PAGADO_ARS,
                    CAST(0 AS INT)             NAC_PAGOS_CANT,
                    CAST(NULL AS DATE)         NAC_FECHA_SALDO,
                    CAST(NULL AS VARCHAR(50))  NAC_FECHA_SALDO_USUARIO,
                    CAST(NULL AS DATETIME)     NAC_FECHA_SALDO_ALTA";
        }

        return "ISNULL(NPG.ARS, 0)    NAC_PAGADO_ARS,
                ISNULL(NPG.CANT, 0)   NAC_PAGOS_CANT,
                NSF.FECHA_SALDO       NAC_FECHA_SALDO,
                NSF.USUARIO_ALTA      NAC_FECHA_SALDO_USUARIO,
                NSF.FECHA_ALTA        NAC_FECHA_SALDO_ALTA";
    }

    /**
     * Los APPLY de los pagos y de la fecha del saldo del contenedor.
     *
     * POR EL MAESTRO, NUNCA POR ID_MG A SECAS: cada pago se atribuye al
     * contenedor de SU OC hoy, COALESCE(NE.ID_PADRE, NE.ID). Si la OC contra la
     * que se grabo paso a ser hija de otra, el pago sigue en el contenedor. Es
     * pagosPorContenedor() en SQL. La fecha del saldo, igual; si quedaran dos
     * vigentes en un contenedor -dos principales que se juntaron-, manda la
     * mas reciente.
     *
     * @return string
     */
    private function nacPagoApply() {
        if (!$this->tieneNacPago()) {
            return '';
        }

        return "OUTER APPLY (SELECT SUM(NP.IMPORTE_ARS) ARS, COUNT(NP.ID) CANT
                             FROM " . self::TABLA_NAC_PAGO . " NP
                             INNER JOIN " . self::TABLA_MAESTRO . " NE ON NE.ID = NP.ID_MG
                             WHERE NP.VIGENTE = 1
                               AND COALESCE(NE.ID_PADRE, NE.ID) = " . self::OC_PRINCIPAL . ") NPG
                OUTER APPLY (SELECT TOP 1 SF.FECHA_SALDO, SF.USUARIO_ALTA, SF.FECHA_ALTA
                             FROM " . self::TABLA_NAC_SALDO_FECHA . " SF
                             INNER JOIN " . self::TABLA_MAESTRO . " SE ON SE.ID = SF.ID_MG
                             WHERE SF.VIGENTE = 1
                               AND COALESCE(SE.ID_PADRE, SE.ID) = " . self::OC_PRINCIPAL . "
                             ORDER BY SF.FECHA_ALTA DESC, SF.ID DESC) NSF";
    }

    /**
     * El costo real del contenedor: la copia de UNA OC del grupo, la que elige
     * copiaCostoReal() -la principal, o la de ID mas chico con detalle-.
     * Nunca la suma del grupo: Comex copia el detalle entero en cada OC.
     *
     * @return string
     */
    private static function costoRealApply() {
        return 'OUTER APPLY (SELECT SUM(DR.[IMPORTE_$]) COSTO_REAL_ARS
                             FROM RO_T_IMPORTACIONES_DETALLE DR
                             WHERE DR.ID_MG = (
                                 SELECT TOP 1 GX.ID
                                 FROM ' . self::TABLA_MAESTRO . ' GX
                                 WHERE COALESCE(GX.ID_PADRE, GX.ID) = ' . self::OC_PRINCIPAL . '
                                   AND EXISTS (SELECT 1 FROM RO_T_IMPORTACIONES_DETALLE DX
                                               WHERE DX.ID_MG = GX.ID)
                                 ORDER BY CASE WHEN GX.ID = ' . self::OC_PRINCIPAL . ' THEN 0 ELSE 1 END,
                                          GX.ID)) CR';
    }

    /**
     * El contenedor de una OC, leido del maestro: COALESCE(ID_PADRE, ID).
     * Lo resuelve el servidor, nunca el navegador.
     *
     * @param int $idMg
     * @return int
     * @throws Exception Si la OC no esta en el maestro
     */
    public function principalDe($idMg) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid, "SELECT COALESCE(ID_PADRE, ID) AS P FROM "
            . self::TABLA_MAESTRO . " WHERE ID = ?", [intval($idMg)]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlEn(self::TABLA_MAESTRO, 'No se pudo leer el contenedor'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('Ese contenedor no está en el maestro de Comercio Exterior.');
        }

        return intval($row['P']);
    }

    /**
     * La fila titular de un contenedor en Crono, con su base y su saldo
     * resueltos POR LA MISMA CONSULTA que dibuja la grilla: el dialogo, el
     * servidor y el tablero no pueden calcular el saldo de dos maneras.
     *
     * @param int $principal
     * @param string $hoy
     * @return array
     * @throws Exception Si el contenedor no esta en el cronograma
     */
    private function filaTitular($principal, $hoy) {
        foreach ($this->getCronoNacionalizacion($hoy) as $f) {
            if (intval($f['GRUPO_ID']) === intval($principal) && empty($f['DUPLICA_GRUPO'])) {
                return $f;
            }
        }

        throw new Exception('Ese contenedor no está en el cronograma de nacionalización (puede '
            . 'tener la fecha de movimiento anterior a "Contenedores desde").');
    }

    /**
     * Registra un pago parcial de la nacionalizacion.
     *
     * TODO EN UNA TRANSACCION: el pago y, si vino, la fecha del saldo. Si se
     * grabara el pago y fallara la fecha, el saldo de una nacionalizacion
     * vencida quedaria sin columna donde caer.
     *
     * @param array $datos id_mg y lo que valida validarPagoNac()
     * @param string $usuario
     * @param string|null $hoy
     * @return array ['contenedor', 'antes', 'despues', 'moneda', 'importe_ars']
     */
    public function registrarPagoNac($datos, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        if (!$this->tieneNacPago()) {
            throw new Exception($this->avisoSinNacPago());
        }

        $principal = $this->principalDe(isset($datos['id_mg']) ? $datos['id_mg'] : 0);
        $fila = $this->filaTitular($principal, $hoy);

        if ($fila['BASE_ARS'] === null) {
            throw new Exception('Este contenedor no tiene costo de nacionalización estimado ni real: '
                . 'no hay contra qué calcular el saldo. Cargá la estimación en Comercio Exterior.');
        }

        $v = self::validarPagoNac($datos, $hoy, $fila['FECHA_NAC_EFECTIVA']);

        $cid = $this->conn->conectar('central');

        if (!$cid || !sqlsrv_begin_transaction($cid)) {
            throw new Exception('No se pudo abrir la transacción para registrar el pago.');
        }

        try {
            $this->ejecutar($cid,
                "INSERT INTO " . self::TABLA_NAC_PAGO . "
                    (ID_MG, FECHA_PAGO, MONEDA, IMPORTE_USD, COTIZACION, IMPORTE_ARS, OBSERVACION,
                     VIGENTE, USUARIO_ALTA, FECHA_ALTA)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())",
                [$principal, $v['fecha_pago'], $v['moneda'], $v['importe_usd'], $v['cotizacion'],
                 $v['importe_ars'], $v['observacion'], $usuario],
                'No se pudo registrar el pago', self::TABLA_NAC_PAGO);

            if ($v['fecha_saldo'] !== null && $v['fecha_saldo'] !== $fila['FECHA_SALDO_CARGADA']) {
                $this->grabarFechaSaldo($cid, $principal, $v['fecha_saldo'],
                    $fila['FECHA_SALDO_CARGADA'], $usuario);
            }

            sqlsrv_commit($cid);
        } catch (Exception $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return [
            'contenedor' => $principal,
            'antes' => self::saldoNacionalizacion($fila['BASE_ARS'], $fila['NAC_PAGADO_ARS']),
            'despues' => self::saldoNacionalizacion($fila['BASE_ARS'],
                floatval($fila['NAC_PAGADO_ARS']) + $v['importe_ars']),
            'moneda' => $v['moneda'],
            'importe_ars' => $v['importe_ars']
        ];
    }

    /**
     * Da de baja la fecha del saldo vigente del contenedor -todas, si quedaron
     * dos por una reagrupacion- e inserta la nueva. Dentro de la transaccion
     * de quien llama.
     */
    private function grabarFechaSaldo($cid, $principal, $fecha, $anterior, $usuario) {
        $this->ejecutar($cid,
            "UPDATE SF SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             FROM " . self::TABLA_NAC_SALDO_FECHA . " SF
             INNER JOIN " . self::TABLA_MAESTRO . " SE ON SE.ID = SF.ID_MG
             WHERE SF.VIGENTE = 1 AND COALESCE(SE.ID_PADRE, SE.ID) = ?",
            [$usuario, $usuario, $principal],
            'No se pudo dar de baja la fecha del saldo anterior', self::TABLA_NAC_SALDO_FECHA);

        $this->ejecutar($cid,
            "INSERT INTO " . self::TABLA_NAC_SALDO_FECHA . "
                (ID_MG, FECHA_SALDO, FECHA_ANTERIOR, VIGENTE, USUARIO_ALTA, FECHA_ALTA)
             VALUES (?, ?, ?, 1, ?, GETDATE())",
            [$principal, $fecha, $anterior, $usuario],
            'No se pudo grabar la fecha del saldo', self::TABLA_NAC_SALDO_FECHA);
    }

    /**
     * Cambia la fecha del saldo sin cargar un pago nuevo, con historial.
     *
     * SOLO CON PAGOS PARCIALES VIGENTES: sin ninguno, el gasto entero se
     * proyecta en la fecha de nacionalizacion, que se edita en su columna. Una
     * fecha del saldo sin saldo no la leeria nadie.
     *
     * @return array ['contenedor', 'fecha', 'anterior', 'sin_cambios']
     */
    public function cambiarFechaSaldoNac($idMg, $fecha, $usuario, $hoy = null) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        if (!$this->tieneNacPago()) {
            throw new Exception($this->avisoSinNacPago());
        }

        $f = self::fechaValida($fecha);

        if ($f === null) {
            throw new Exception('La fecha del saldo no es una fecha.');
        }

        if ($f < $hoy) {
            throw new Exception('La fecha del saldo tiene que ser hoy o posterior: es cuándo se '
                . 'espera pagar lo que falta.');
        }

        $principal = $this->principalDe($idMg);
        $fila = $this->filaTitular($principal, $hoy);

        if (empty($fila['NAC_CON_PARCIALES'])) {
            throw new Exception('Este contenedor no tiene pagos parciales: su nacionalización se '
                . 'proyecta entera en la fecha de nacionalización, que se edita en su columna.');
        }

        if ($fila['FECHA_SALDO_CARGADA'] === $f) {
            return ['contenedor' => $principal, 'fecha' => $f, 'anterior' => $f, 'sin_cambios' => true];
        }

        $cid = $this->conn->conectar('central');

        if (!$cid || !sqlsrv_begin_transaction($cid)) {
            throw new Exception('No se pudo abrir la transacción para cambiar la fecha.');
        }

        try {
            $this->grabarFechaSaldo($cid, $principal, $f, $fila['FECHA_SALDO_CARGADA'], $usuario);
            sqlsrv_commit($cid);
        } catch (Exception $e) {
            sqlsrv_rollback($cid);
            throw $e;
        }

        return ['contenedor' => $principal, 'fecha' => $f,
                'anterior' => $fila['FECHA_SALDO_CARGADA'], 'sin_cambios' => false];
    }

    /**
     * Anula un pago parcial: baja logica, queda en el historial.
     *
     * @param int $id
     * @param string $usuario
     * @return array ['id', 'importe_ars']
     */
    public function anularPagoNac($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);

        if (!$this->tieneNacPago()) {
            throw new Exception($this->avisoSinNacPago());
        }

        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $stmt = sqlsrv_query($cid,
            "UPDATE " . self::TABLA_NAC_PAGO . " SET VIGENTE = 0, " . Auditoria::SET_BAJA . "
             OUTPUT INSERTED.ID, INSERTED.IMPORTE_ARS
             WHERE ID = ? AND VIGENTE = 1",
            [$usuario, $usuario, intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSqlEn(self::TABLA_NAC_PAGO, 'No se pudo anular el pago'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('Ese pago no existe o ya estaba anulado.');
        }

        return ['id' => intval($row['ID']), 'importe_ars' => floatval($row['IMPORTE_ARS'])];
    }

    /**
     * Los pagos del contenedor -vigentes y anulados- y el historial de la
     * fecha del saldo. Por el contenedor de HOY de cada pago, igual que la
     * grilla.
     *
     * @param int $idMg Cualquier OC del contenedor
     * @return array ['contenedor', 'pagos', 'fechas_saldo']
     */
    public function getPagosNac($idMg) {
        $principal = $this->principalDe($idMg);

        if (!$this->tieneNacPago()) {
            return ['contenedor' => $principal, 'pagos' => [], 'fechas_saldo' => []];
        }

        $cid = $this->conn->conectar('central');
        $leer = function ($sql, $fechas) use ($cid, $principal) {
            $stmt = sqlsrv_query($cid, $sql, [$principal]);

            if ($stmt === false) {
                throw new Exception($this->errorSqlEn(self::TABLA_NAC_PAGO,
                    'No se pudo leer el historial del pago parcial'));
            }

            $v = [];

            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Las de auditoria, con hora: es lo que dice quien y cuando.
                foreach ($fechas as $c) {
                    if (isset($r[$c]) && $r[$c] instanceof DateTime) {
                        $r[$c] = $r[$c]->format(
                            in_array($c, ['FECHA_ALTA', 'FECHA_BAJA'], true) ? 'Y-m-d H:i:s' : 'Y-m-d');
                    }
                }

                $v[] = $r;
            }

            sqlsrv_free_stmt($stmt);

            return $v;
        };

        return [
            'contenedor' => $principal,
            'pagos' => $leer(
                "SELECT NP.ID, NP.ID_MG, NP.FECHA_PAGO, NP.MONEDA, NP.IMPORTE_USD, NP.COTIZACION,
                        NP.IMPORTE_ARS, NP.OBSERVACION, NP.VIGENTE, NP.USUARIO_ALTA, NP.FECHA_ALTA,
                        NP.USUARIO_BAJA, NP.FECHA_BAJA
                 FROM " . self::TABLA_NAC_PAGO . " NP
                 INNER JOIN " . self::TABLA_MAESTRO . " NE ON NE.ID = NP.ID_MG
                 WHERE COALESCE(NE.ID_PADRE, NE.ID) = ?
                 ORDER BY NP.FECHA_PAGO, NP.ID",
                ['FECHA_PAGO', 'FECHA_ALTA', 'FECHA_BAJA']),
            'fechas_saldo' => $leer(
                "SELECT SF.ID, SF.FECHA_SALDO, SF.FECHA_ANTERIOR, SF.VIGENTE, SF.USUARIO_ALTA,
                        SF.FECHA_ALTA, SF.USUARIO_BAJA, SF.FECHA_BAJA
                 FROM " . self::TABLA_NAC_SALDO_FECHA . " SF
                 INNER JOIN " . self::TABLA_MAESTRO . " SE ON SE.ID = SF.ID_MG
                 WHERE COALESCE(SE.ID_PADRE, SE.ID) = ?
                 ORDER BY SF.FECHA_ALTA, SF.ID",
                ['FECHA_SALDO', 'FECHA_ANTERIOR', 'FECHA_ALTA', 'FECHA_BAJA'])
        ];
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
