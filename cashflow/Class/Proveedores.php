<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Ingresos.php';
require_once __DIR__ . '/ProveedoresCategorias.php';
require_once __DIR__ . '/Planilla.php';

/**
 * Proveedores
 * Las cuentas a pagar locales: cuanto se debe, cuando vence y cuando se paga.
 *
 * DE DONDE SALEN LOS PENDIENTES
 * -----------------------------
 * De Tango, con la consulta depurada de getPendientes(). El original que genera
 * Tango para su reporte de composicion de saldos esta guardado, sin ejecutar, en
 * sql/_referencia_tango_pendientes.sql, y el encabezado de ese archivo explica
 * que se saco y por que.
 *
 * LA CONSULTA REPRODUCE A TANGO AL CENTAVO. Aplicandole sus mismos filtros
 * -solo lo no vencido, sin excluir el exterior- da exactamente sus 158 filas y
 * sus $521.858.835,68. Esa verificacion es lo unico que permite afirmar que el
 * numero del tablero es el mismo que el del reporte.
 *
 * UNA FILA POR VENCIMIENTO, NO POR COMPROBANTE
 * --------------------------------------------
 * Un comprobante puede tener varios vencimientos -hay uno con doce cuotas- y
 * cada cuota cae en una columna distinta del eje. Agrupar por comprobante
 * pondria las doce en la fecha de la primera.
 *
 * EL PENDIENTE NO ES EL IMPORTE DEL VENCIMIENTO
 * ---------------------------------------------
 * Hay que restarle lo imputado, y ahi esta la parte que es facil hacer mal:
 * CPA05 no guarda solo pagos, guarda TODO lo que se imputa contra el
 * comprobante, y no todo lo cancela.
 *
 *     T_COMP_CAN = 'REC'   -> RESTA (aunque CPA21 diga que REC es 'D')
 *     CPA21.CRE_DEB = 'D'  -> SUMA   (una nota de debito es deuda NUEVA)
 *     cualquier otro caso  -> RESTA  (O/P, notas de credito)
 *
 * Sin esa tabla de signos una nota de debito imputada se resta como si fuera un
 * pago y el pendiente da NEGATIVO. Pasa de verdad: la FAC A0000500001731 de
 * DONNA DI DIO tiene un vencimiento de 14.614.380, una O/P que lo cancela entero
 * y una nota de debito de 3.342.062,08 imputada encima. Lo que se debe son esos
 * 3.342.062,08; restando todo da -3.342.062,08.
 *
 * QUE QUEDA AFUERA Y POR QUE
 * --------------------------
 * - COD_PROVEE LIKE 'Z%': son los proveedores del EXTERIOR, no proveedores de
 *   sistema. Y no es solo que no correspondan a un listado local: YA ENTRAN AL
 *   TABLERO por COMEX_PROV_EXT, asi que incluirlos los contaria dos veces. Son
 *   8.023 de los 9.344 millones pendientes totales.
 * - CPA01.CLAUSULA = 1: proveedores con clausula de moneda extranjera. Es el
 *   criterio del "Total Pendiente (CTE)" de Tango, que es el pendiente EN PESOS.
 *   Hoy deja afuera $20.938 de un transportista local de 2022 y 2023, que
 *   ademas tiene el importe en moneda extranjera en cero: casi seguro un error
 *   de maestro.
 *
 * LO VENCIDO ENTRA, SIN TECHO DE ANTIGUEDAD
 * -----------------------------------------
 * Cobranzas descarta lo vencido hace mas de Ingresos::DIAS_COBRO_VENCIDO dias.
 * ACA NO, y la diferencia no es un descuido: una factura vieja sin COBRAR puede
 * ser incobrable, pero una vieja sin PAGAR sigue siendo deuda. Con el techo de
 * 180 dias quedaban afuera $346,8 millones, el 29% del total, y no eran basura
 * administrativa: la mayor parte es un plan de cuotas vigente.
 *
 * La herramienta para redistribuir lo vencido es cargarle una fecha de pago, no
 * un filtro por antiguedad. Por eso getPendientes() informa aparte cuanto hay
 * VENCIDO SIN FECHA CARGADA: apilarlo en el primer dia del eje sin decirlo seria
 * mostrar que se paga todo hoy.
 *
 * DOS FORMAS DE PAGO QUE NO SON LA MISMA COSA
 * -------------------------------------------
 * Cada fila viaja con dos, y confundirlas fue un bug:
 *
 *   FORMA_PAGO_MAESTRO  como se le paga a ESE PROVEEDOR, segun el maestro. Es
 *                       una regla, y es lo UNICO que decide si el comprobante
 *                       entra al cronograma -al filtro de la pestaña y a la
 *                       serie PAGOS del tablero-.
 *   FORMA_PAGO          por que via salio o va a salir ESTE PAGO, si hay uno
 *                       registrado. Es un hecho, y solo se muestra.
 *
 * EL FILTRO MIRA LA REGLA, NO EL HECHO. Si lo decidiera la fila de pago, cargar
 * una fecha desde la grilla moveria la deuda de serie -la grilla manda la fecha
 * y nada mas, asi que la forma llegaria vacia- y un pago hecho por una via
 * distinta de la habitual sacaria al proveedor del cronograma sin que nadie lo
 * haya decidido.
 *
 * Que las dos difieran no es un error: es informacion. Significa que a ese
 * proveedor se le pago por una via distinta de la habitual, y lo que
 * eventualmente hay que corregir es el maestro.
 */
class Proveedores {

    /**
     * Prefijo de los proveedores del exterior.
     *
     * No son proveedores de sistema: son los de China, Hong Kong, India y las
     * razones sociales del grupo en el exterior. Quedan afuera porque ya entran
     * al tablero por COMEX_PROV_EXT.
     */
    const PREFIJO_EXTERIOR = 'Z';

    /** Tabla de fechas de pago, en la base central */
    const TABLA_PAGO = 'RO_T_CASHFLOW_PROV_LOCALES_PAGO';

    /** @var Conexion */
    private $conn;

    /** @var ProveedoresCategorias */
    private $categorias;

    /** @var bool|null Cache del chequeo de existencia de la tabla de pagos */
    private $tabla = null;

    /** @var array Cache de que columnas de override tiene la tabla de pagos */
    private $columnasPago = [];

    /**
     * @param ProveedoresCategorias|null $categorias Se puede inyectar para poder
     *        probar la clasificacion sin base.
     */
    function __construct($categorias = null) {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
        $this->categorias = ($categorias === null) ? new ProveedoresCategorias() : $categorias;
    }

    /** @return ProveedoresCategorias El resolutor de categorias */
    public function categorias() {
        return $this->categorias;
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
        $stmt = sqlsrv_query($cid, "SELECT OBJECT_ID('dbo." . self::TABLA_PAGO . "', 'U') AS T");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tabla de pagos'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->tabla = ($row && $row['T'] !== null);

        return $this->tabla;
    }

    /** @return array Avisos de configuracion pendiente */
    public function getAvisos() {
        $avisos = [];

        if (!$this->tablaCreada()) {
            $avisos[] = 'Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central. '
                . 'Mientras tanto, todo se proyecta a la fecha de vencimiento y no se '
                . 'puede cargar ninguna fecha.';
        }

        return array_merge($avisos, $this->categorias->getAvisos());
    }

    /* ====================================================================
       LOS PENDIENTES
       ==================================================================== */

    /**
     * Las cuentas a pagar locales, una fila por vencimiento.
     *
     * Cada fila viene con su categoria YA RESUELTA -rubro economico, rubro,
     * centro de costos, si esta excluido y a que serie del tablero va-, con su
     * fecha de pago cargada si la tiene, y con la fecha resuelta con la que
     * entra al eje. Quien consume no vuelve a mirar el maestro ni a resolver
     * fechas: eso pasa una sola vez, aca.
     *
     * @param string|null $hoy 'Y-m-d'; por defecto el dia de hoy. Se inyecta
     *        para poder probar sin que las pruebas caduquen.
     * @return array Listado de vencimientos
     */
    public function getPendientes($hoy = null) {
        $cid = $this->conectar();
        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        /* LA SUBCONSULTA DE IMPUTACIONES ES LA PARTE DELICADA. Ver la tabla de
           signos en el encabezado de la clase y en
           sql/_referencia_tango_pendientes.sql.

           El join con CPA54 va por ID_CPA04 y no por (COD_PROVEE, T_COMP,
           N_COMP) como hace Tango: esa terna NO es unica en CPA04 -hay 16
           repetidas, todas del proveedor generico 000000- y multiplicaria filas.
           Hoy ninguna de esas 16 esta pendiente, asi que las dos formas dan lo
           mismo; ID_CPA04 esta poblado en las tres tablas y no depende de eso. */
        $sql = "
            SELECT
                a.COD_PROVEE,
                p.NOM_PROVEE,
                a.T_COMP,
                a.N_COMP,
                a.LEYENDA,
                CAST(a.FECHA_EMIS AS DATE) AS FECHA_EMIS,
                CAST(a.FECHA_CONT AS DATE) AS FECHA_CONT,
                CAST(v.FECHA_VTO  AS DATE) AS FECHA_VTO,
                CAST(v.IMPORT_VTO AS FLOAT) AS IMPORTE_VTO,
                CAST(ISNULL(im.IMPUTACIONES, 0) AS FLOAT) AS IMPUTACIONES,
                CAST(v.IMPORT_VTO + ISNULL(im.IMPUTACIONES, 0) AS FLOAT) AS IMPORTE_PENDIENTE
            FROM CPA04 a
            INNER JOIN CPA01 p ON p.COD_PROVEE = a.COD_PROVEE
            INNER JOIN CPA54 v ON v.ID_CPA04   = a.ID_CPA04
            LEFT  JOIN (
                    SELECT i.ID_CPA04, i.FECHA_VTO,
                           SUM(CASE i.T_COMP_CAN
                                    WHEN 'REC' THEN -(i.IMPORT_CAN)
                                    ELSE CASE tc.CRE_DEB
                                             WHEN 'D' THEN +(i.IMPORT_CAN)
                                             ELSE -(i.IMPORT_CAN)
                                         END
                               END) AS IMPUTACIONES
                    FROM CPA05 i
                    LEFT JOIN CPA21 tc ON tc.T_COMP = i.T_COMP_CAN
                    GROUP BY i.ID_CPA04, i.FECHA_VTO
                 ) im ON im.ID_CPA04 = v.ID_CPA04 AND im.FECHA_VTO = v.FECHA_VTO
            WHERE a.ESTADO      = 'PEN'
              AND v.ESTADO_VTO <> 'PAG'
              AND a.COD_PROVEE NOT LIKE '" . self::PREFIJO_EXTERIOR . "%'
              AND p.CLAUSULA   <> 1
              AND v.IMPORT_VTO + ISNULL(im.IMPUTACIONES, 0) <> 0
            ORDER BY v.FECHA_VTO, a.COD_PROVEE, a.N_COMP
        ";

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al consultar las cuentas a pagar'));
        }

        $pagos = $this->getPagos();
        $items = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cod = Planilla::codigo($row['COD_PROVEE']);
            $tComp = Planilla::codigo($row['T_COMP']);
            $nComp = Planilla::codigo($row['N_COMP']);
            $clave = self::clavePago($cod, $tComp, $nComp);

            $cat = $this->categorias->categoria($cod);
            $pago = isset($pagos[$clave]) ? $pagos[$clave] : null;

            $fechaVto = Horizonte::normalizarFecha($row['FECHA_VTO']);
            $fechaEmis = Horizonte::normalizarFecha($row['FECHA_EMIS']);

            $resuelta = self::resolverFechaPago(
                ($pago === null) ? null : $pago['FECHA_PAGO'],
                $fechaVto,
                $fechaEmis,
                $cat['plazo_dias'],
                $hoyStr
            );

            $forma = self::formaQueSeMuestra($cat, $pago);

            $items[] = [
                'COD_PROVEE' => $cod,
                'RAZON_SOC' => trim((string) $row['NOM_PROVEE']),
                'T_COMP' => $tComp,
                'N_COMP' => $nComp,
                'LEYENDA' => trim((string) $row['LEYENDA']),
                'FECHA_EMIS' => $fechaEmis,
                'FECHA_CONT' => Horizonte::normalizarFecha($row['FECHA_CONT']),
                'FECHA_VTO' => $fechaVto,
                'IMPORTE_VTO' => round(floatval($row['IMPORTE_VTO']), 2),
                'IMPUTACIONES' => round(floatval($row['IMPUTACIONES']), 2),
                'IMPORTE_PENDIENTE' => round(floatval($row['IMPORTE_PENDIENTE']), 2),

                // La fecha con la que entra al eje, y de donde salio.
                'Pago' => $resuelta['fecha'],
                'PAGO_ORIGINAL' => $resuelta['original'],
                'ORIGEN_FECHA' => $resuelta['origen'],
                'VENCIDA' => $resuelta['vencida'],
                'SIN_FECHA_CARGADA' => $resuelta['sin_fecha_cargada'],

                // Lo cargado a mano, si hay.
                'FECHA_PAGO' => ($pago === null) ? null : $pago['FECHA_PAGO'],

                /* COMO SE LE PAGA A ESTE PROVEEDOR, segun el maestro. Es lo
                   UNICO que decide el filtro y la serie del tablero: ver
                   CRONOGRAMA, mas abajo. */
                'FORMA_PAGO_MAESTRO' => $cat['forma_pago'],

                /* La forma del pago REGISTRADO, que es otra cosa: es un hecho
                   sobre este comprobante, no una regla sobre el proveedor. Se
                   muestra en la columna Forma; si difiere de la del maestro,
                   eso es un dato para mirar -y capaz para corregir el maestro-,
                   no un motivo para cambiar lo que se filtra.

                   Sin pago registrado, o con uno que no dice la forma, se
                   muestra la del maestro: es lo mas cierto que se sabe. El
                   ORIGINAL sale de la MISMA fuente que el normalizado, porque
                   es el original DE ESE valor y no de otro. */
                'FORMA_PAGO' => $forma['normalizado'],
                'FORMA_PAGO_ORIG' => $forma['original'],
                'OBSERVACION' => ($pago === null) ? null : $pago['OBSERVACION'],
                'ESTADO_PAGO' => ($pago === null) ? null : $pago['ESTADO'],

                // La categoria, ya resuelta.
                'EN_MAESTRO' => $cat['en_maestro'],
                'RUBRO_ECONOMICO' => $cat['rubro_economico'],
                'RUBRO' => $cat['rubro'],
                'CENTRO_COSTOS' => $cat['centro_costos'],
                'EXCLUIDO' => $cat['excluido'],
                'SERIE' => $cat['serie'],

                /* EL OVERRIDE DE ESTA FACTURA, si alguien lo puso. Es una REGLA
                   -con que forma hay que tratar a este comprobante- puesta a
                   mano, y no toca el maestro: las otras facturas del mismo
                   proveedor siguen como estaban. */
                'FORMA_PAGO_CRONOGRAMA' => ($pago === null)
                    ? null : $pago['FORMA_PAGO_CRONOGRAMA'],

                /* Si se gestiona desde el cronograma de pagos -echeq,
                   transferencia, o forma desconocida-. Es lo que decide si
                   entra a la fila del tablero y lo que la pestaña filtra por
                   defecto. Ver ProveedoresCategorias::esDelCronograma().

                   SALE DE UNA REGLA, NO DE UN HECHO, y eso no cambio. Lo que
                   cambio es que la regla tiene dos escalones: el override de
                   ESTA factura si lo hay, y si no la forma del maestro, que es
                   una propiedad del PROVEEDOR.

                   NO SALE de FORMA_PAGO. Esa es el HECHO -por que via salio ese
                   pago- y la escribe la importacion de la planilla de pagos en
                   todas sus filas: si decidiera, importar reclasificaria
                   comprobantes dentro y fuera del cashflow sin que nadie lo
                   pida. Ver Proveedores::formaDelCronograma(). */
                'CRONOGRAMA' => ProveedoresCategorias::esDelCronograma(
                    self::formaDelCronograma($cat, $pago))
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $items;
    }

    /**
     * Con que forma de pago se decide si este comprobante entra al cronograma.
     *
     * SON DOS ESCALONES DE LA MISMA REGLA, y el orden importa:
     *
     *   1. el OVERRIDE de esta factura, si alguien lo puso a mano
     *   2. si no, la forma del MAESTRO, que es como se le paga a ese proveedor
     *
     * NO ENTRA FORMA_PAGO. Esa es un HECHO -por que via salio o va a salir ese
     * pago- y la escribe la importacion de la planilla en todas sus filas. Si
     * decidiera, importar la planilla de pagos pasaria a mover comprobantes
     * dentro y fuera del cashflow sin que nadie lo haya pedido. El filtro mira
     * una regla, no un hecho; lo que se agrego es un escalon mas fino de la
     * regla, no un cambio de criterio.
     *
     * EL OVERRIDE NO TOCA EL MAESTRO: las otras facturas del mismo proveedor
     * siguen clasificandose por la forma del maestro.
     *
     * Estatica y pura.
     *
     * @param array $cat Lo que devolvio ProveedoresCategorias::categoria()
     * @param array|null $pago La fila de override, si hay
     * @return string|null La forma con la que hay que decidir, o null si no se
     *         sabe -y entonces el comprobante entra igual y se marca-
     */
    public static function formaDelCronograma($cat, $pago) {
        if ($pago !== null && !empty($pago['FORMA_PAGO_CRONOGRAMA'])) {
            return $pago['FORMA_PAGO_CRONOGRAMA'];
        }

        return $cat['forma_pago'];
    }

    /**
     * Que forma de pago se MUESTRA en la columna Forma, y con que original.
     *
     * OJO: esto NO decide nada. Lo que entra al cronograma lo decide la forma
     * del maestro y solo esa -ver la seccion "DOS FORMAS DE PAGO QUE NO SON LA
     * MISMA COSA" del encabezado-. Esto es la columna de la grilla.
     *
     * Manda el pago registrado si lo hay, porque es un hecho sobre ESTE
     * comprobante; si no hay, o si el pago no dice la forma, se muestra la del
     * maestro, que es lo mas cierto que se sabe.
     *
     * EL ORIGINAL SALE DE LA MISMA FUENTE QUE EL NORMALIZADO. Mezclarlos
     * -normalizado del pago, original del maestro- mostraria el original de un
     * valor que no es el que se esta mostrando.
     *
     * SI EL NORMALIZADO ES null, EL ORIGINAL ES UN TYPO Y NO OTRA COSA. Antes
     * habia un segundo caso -una forma valida que habia quedado sin normalizar
     * porque el maestro se importo antes de que estuviera declarada- y habia
     * que distinguirlo, porque se arreglaba reimportando y no corrigiendo la
     * planilla. Ese caso ya no existe: la normalizacion se calcula al leer
     * contra la lista de hoy. Ver ProveedoresCategorias::mapa().
     *
     * Estatica y pura.
     *
     * @param array $cat Lo que devolvio ProveedoresCategorias::categoria()
     * @param array|null $pago La fila de pago, si hay
     * @return array ['normalizado', 'original']
     */
    public static function formaQueSeMuestra($cat, $pago) {
        $delPago = ($pago !== null && $pago['FORMA_PAGO'] !== null);

        $normalizado = $delPago ? $pago['FORMA_PAGO'] : $cat['forma_pago'];
        $original = $delPago
            ? $pago['FORMA_PAGO_ORIG']
            : (isset($cat['forma_pago_orig']) ? $cat['forma_pago_orig'] : null);

        /* Un pago que no dice la forma pero si trae su original -un valor que
           no matcheo al importar la planilla de pagos- conserva ese original:
           es lo que hay que mostrar para poder corregirlo. */
        if (!$delPago && $pago !== null && $pago['FORMA_PAGO_ORIG'] !== null
            && trim((string) $pago['FORMA_PAGO_ORIG']) !== '') {
            $original = $pago['FORMA_PAGO_ORIG'];
        }

        return [
            'normalizado' => $normalizado,
            'original' => $original
        ];
    }

    /* ====================================================================
       LA JERARQUIA DE LA FECHA DE PAGO
       ==================================================================== */

    /**
     * Con que fecha entra un vencimiento al eje. ES LA JERARQUIA, escrita una
     * sola vez:
     *
     *   1. la FECHA DE PAGO CARGADA, si hay una para ese comprobante
     *   2. si no, la FECHA DE VENCIMIENTO de Tango
     *   3. si no hay vencimiento usable, EMISION + PLAZO del maestro
     *   4. si nada de eso alcanza, sin fecha
     *
     * EL VENCIMIENTO VA ANTES QUE EL PLAZO, y no al reves. El vencimiento es un
     * dato del comprobante; el plazo es una costumbre del proveedor. Cuando los
     * dos existen, el que describe a ESTA factura es el vencimiento. El plazo
     * esta para cuando no hay vencimiento utilizable, que en esta base es raro
     * -Tango usa 1800-01-01 como centinela de "sin fecha"- pero pasa.
     *
     * Ademas el plazo esta vacio en el 62% del maestro y cuando esta no siempre
     * es un numero: CONTADO, DEBITO, '30 DIAS'. Ver
     * ProveedoresCategorias::plazoEnDias(), que devuelve null cuando el plazo no
     * permite calcular una fecha, y null hace caer al escalon siguiente.
     *
     * UNA FECHA CARGADA NO SE REUBICA AUNQUE ESTE VENCIDA. La cargo una persona;
     * moverla a hoy seria pisar su decision con una regla automatica y mostrarle
     * su propia carga en otra columna. Se marca vencida -eso es un hecho- y se
     * dibuja donde esta.
     *
     * UN VENCIMIENTO PASADO SIN FECHA CARGADA SI SE UBICA EN EL PRIMER DIA DEL
     * EJE, pero queda marcado con 'sin_fecha_cargada'. Esa marca es la que
     * alimenta el indicador de la pestana: sin ella, el tablero mostraria
     * ochocientos millones cayendo hoy como si estuviera decidido pagarlos hoy.
     *
     * NO HAY TECHO DE ANTIGUEDAD, a diferencia de cobranzas. Ver el encabezado
     * de la clase.
     *
     * Estatica y pura: es la regla mas facil de romper del modulo.
     *
     * @param string|null $fechaPago Fecha cargada a mano, 'Y-m-d'
     * @param string|null $fechaVto Vencimiento de Tango, 'Y-m-d'
     * @param string|null $fechaEmis Emision, 'Y-m-d'
     * @param int|null $plazoDias Plazo del maestro en dias, o null
     * @param string $hoy Primer dia del eje, 'Y-m-d'
     * @return array ['fecha', 'original', 'origen', 'vencida', 'sin_fecha_cargada']
     */
    public static function resolverFechaPago($fechaPago, $fechaVto, $fechaEmis,
                                             $plazoDias, $hoy) {
        $hoyStr = substr((string) $hoy, 0, 10);
        $cargada = self::fechaUtil($fechaPago);

        /* 1. La fecha cargada manda y no se reubica. */
        if ($cargada !== null) {
            return [
                'fecha' => $cargada,
                'original' => $cargada,
                'origen' => 'CARGADA',
                'vencida' => ($cargada < $hoyStr),
                'sin_fecha_cargada' => false
            ];
        }

        /* 2. El vencimiento de Tango. */
        $base = self::fechaUtil($fechaVto);
        $origen = 'VENCIMIENTO';

        /* 3. Emision + plazo del maestro, solo si no hubo vencimiento usable. */
        if ($base === null) {
            $emis = self::fechaUtil($fechaEmis);

            if ($emis !== null && $plazoDias !== null) {
                $d = new DateTime($emis);
                $d->setTime(0, 0, 0);
                $d->modify('+' . intval($plazoDias) . ' days');
                $base = $d->format('Y-m-d');
                $origen = 'PLAZO';
            }
        }

        /* 4. Sin nada con que ubicarlo. Se transporta asi y el eje lo informa en
              'sin_fecha' en lugar de perderlo. */
        if ($base === null) {
            return [
                'fecha' => null,
                'original' => null,
                'origen' => 'SIN_FECHA',
                'vencida' => false,
                'sin_fecha_cargada' => true
            ];
        }

        /* Lo vencido se ubica en el primer dia del eje SIN TECHO de antiguedad:
           se reusa la regla de las pestanas de cobranza con $diasAtras en null,
           que es como la usa Exportaciones Tasky. Ver
           Ingresos::ubicarCobroVencido(). */
        $ubic = Ingresos::ubicarCobroVencido($base, $hoyStr, null, false);

        return [
            'fecha' => $ubic['fecha'],
            'original' => $ubic['original'],
            'origen' => $origen,
            'vencida' => $ubic['vencida'],
            // Lo que alimenta el indicador: vencido y sin que nadie haya dicho
            // cuando se paga.
            'sin_fecha_cargada' => $ubic['vencida']
        ];
    }

    /**
     * Normaliza una fecha y descarta los centinelas de Tango.
     *
     * Tango usa 1800-01-01 para decir "sin fecha", y la consulta de referencia
     * lo convierte a NULL con un CASE. Aca se hace lo mismo pero en un solo
     * lugar: una fecha del ano 1800 no es una fecha de pago, es la ausencia de
     * una.
     *
     * @param mixed $valor
     * @return string|null 'Y-m-d'
     */
    public static function fechaUtil($valor) {
        $f = Horizonte::normalizarFecha($valor);

        if ($f === null || $f <= '1900-01-01') {
            return null;
        }

        return $f;
    }

    /* ====================================================================
       AVISOS
       ==================================================================== */

    /**
     * Lo que NO se ve en los numeros y hay que decir.
     *
     * EL PRIMERO ES EL MAS IMPORTANTE DEL MODULO: cuanto hay vencido sin que
     * nadie haya dicho cuando se paga. Ese importe se dibuja en el primer dia
     * del eje porque no hay otro lugar donde ponerlo, y sin este aviso se leeria
     * como "hoy se pagan ochocientos millones".
     *
     * CUENTAN TODO LO PENDIENTE, Y LO DICEN. Los usan los dos lados -la pestaña
     * y el proveedor del tablero- y los dos muestran MENOS que eso: la pestaña
     * abre filtrada por forma de pago y la fila del tablero usa PAGOS, que trae
     * solo el cronograma. Que cuenten el universo esta bien -son la
     * contrapartida de lo que no se ve- pero un numero que no coincide con el
     * que esta en pantalla tiene que decir a que se refiere, o se lee como un
     * error del sistema.
     *
     * Estatica y pura.
     *
     * @param array $items Filas de getPendientes()
     * @return array Lista de mensajes
     */
    public static function avisosPendientes($items) {
        $avisos = [];

        $vencidoSinFecha = 0.0;
        $compVencidos = 0;
        $sinFecha = 0.0;
        $compSinFecha = 0;
        $excluido = 0.0;
        $compExcluidos = 0;

        foreach ($items as $i) {
            $importe = floatval($i['IMPORTE_PENDIENTE']);

            if (!empty($i['EXCLUIDO'])) {
                $excluido += $importe;
                $compExcluidos++;
            }

            if ($i['ORIGEN_FECHA'] === 'SIN_FECHA') {
                $sinFecha += $importe;
                $compSinFecha++;
                continue;
            }

            if (!empty($i['SIN_FECHA_CARGADA'])) {
                $vencidoSinFecha += $importe;
                $compVencidos++;
            }
        }

        /* SOBRE TODO LO PENDIENTE. Se dice en cada uno y no una vez al final:
           los avisos se leen sueltos -van en una lista- y el que se lea sin el
           de al lado tiene que seguir diciendo a que se refiere. */
        $alcance = ' Es sobre TODAS las cuentas a pagar, así que no coincide con lo que '
            . 'muestra la pantalla si hay un filtro puesto.';

        if ($compVencidos > 0) {
            $avisos[] = $compVencidos . ' vencimiento(s) por ' . self::plata($vencidoSinFecha)
                . ' ya vencieron y NO tienen fecha de pago cargada. Se muestran en el primer '
                . 'día del eje porque no hay otro lugar donde ponerlos, pero eso no significa '
                . 'que se paguen hoy: cargales la fecha, de a uno en la grilla o importando '
                . 'la planilla de pagos.' . $alcance;
        }

        if ($compSinFecha > 0) {
            $avisos[] = $compSinFecha . ' vencimiento(s) por ' . self::plata($sinFecha)
                . ' no tienen fecha de vencimiento en Tango ni plazo de pago en el maestro, '
                . 'así que no se pueden ubicar en el eje.' . $alcance;
        }

        if ($compExcluidos > 0) {
            $avisos[] = $compExcluidos . ' vencimiento(s) por ' . self::plata($excluido)
                . ' son de proveedores con rubro "' . ProveedoresCategorias::RUBRO_EXCLUIDOS
                . '" en el maestro. Se listan acá pero su fila del tablero se puede '
                . 'inhabilitar desde Parámetros, o apuntarla a la serie que ya los deja '
                . 'afuera sin perder el criterio del cronograma.' . $alcance;
        }

        return $avisos;
    }

    /* ====================================================================
       FECHAS DE PAGO CARGADAS
       ==================================================================== */

    /**
     * Las fechas de pago cargadas, indexadas por comprobante.
     *
     * LA CLAVE INCLUYE AL PROVEEDOR, y es lo contrario de cobranzas: en compras
     * el numero de comprobante lo pone el proveedor, no nosotros. Hay 10.293
     * pares (T_COMP, N_COMP) repetidos entre proveedores locales. Ver el
     * encabezado de sql/cashflow_prov_locales.sql.
     *
     * @return array Mapa 'COD|T_COMP|N_COMP' => fila
     */
    public function getPagos() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $cid = $this->conectar();

        $sql = "SELECT COD_PROVEE, T_COMP, N_COMP, FECHA_PAGO, FORMA_PAGO, FORMA_PAGO_ORIG,
                       OBSERVACION, ESTADO, FECHA_CANCELADO, ORIGEN, USUARIO, "
                       . $this->overrideSql('FORMA_PAGO_CRONOGRAMA') . " AS FORMA_PAGO_CRONOGRAMA
                FROM dbo." . self::TABLA_PAGO;

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las fechas de pago'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = self::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);

            $mapa[$clave] = [
                'COD_PROVEE' => Planilla::codigo($row['COD_PROVEE']),
                'T_COMP' => Planilla::codigo($row['T_COMP']),
                'N_COMP' => Planilla::codigo($row['N_COMP']),
                'FECHA_PAGO' => Horizonte::normalizarFecha($row['FECHA_PAGO']),

                /* Normalizada contra la lista DE HOY y no contra la del dia que
                   se importo. Misma regla que el maestro y por el mismo motivo:
                   es un valor derivado del original, que esta guardado al lado.
                   Ver ProveedoresCategorias::mapa(). */
                'FORMA_PAGO' => ProveedoresCategorias::formaVigente(
                    $row['FORMA_PAGO_ORIG'], $row['FORMA_PAGO']),
                'FORMA_PAGO_ORIG' => $row['FORMA_PAGO_ORIG'],

                /* EL OVERRIDE DE ESTA FACTURA, que es otra cosa que FORMA_PAGO:
                   es una REGLA -con que forma hay que tratar a este
                   comprobante- y no un hecho. Es lo unico de esta tabla que
                   decide si el importe entra al cashflow. Ver la nota de
                   CRONOGRAMA en getPendientes(). */
                'FORMA_PAGO_CRONOGRAMA' => $row['FORMA_PAGO_CRONOGRAMA'],
                'OBSERVACION' => $row['OBSERVACION'],
                'ESTADO' => $row['ESTADO'],
                'FECHA_CANCELADO' => Horizonte::normalizarFecha($row['FECHA_CANCELADO']),
                'ORIGEN' => $row['ORIGEN'],
                'USUARIO' => $row['USUARIO']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Como se pide una columna de override en un SELECT: la columna si ya
     * existe, y NULL si el script que la agrega todavia no se corrio.
     *
     * SE PREGUNTA en vez de darla por hecha porque las cuentas a pagar se
     * siguen pudiendo LEER sin ella: el listado sale de Tango y una instalacion
     * que no corrio el script tiene que ver su pestana, no un error de SQL. NULL
     * es ademas el valor correcto: sin la columna no hay ningun override, que es
     * exactamente lo que NULL significa.
     *
     * @param string $columna
     * @return string
     */
    private function overrideSql($columna) {
        return $this->tieneColumnaPago($columna) ? $columna : 'NULL';
    }

    /**
     * Si la tabla de overrides ya tiene una columna. Se pregunta una vez por
     * columna y por pedido.
     *
     * @param string $columna
     * @return bool
     */
    public function tieneColumnaPago($columna) {
        if (isset($this->columnasPago[$columna])) {
            return $this->columnasPago[$columna];
        }

        if (!$this->tablaCreada()) {
            return false;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT COL_LENGTH('dbo." . self::TABLA_PAGO . "', ?) AS C", [$columna]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la columna ' . $columna));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $this->columnasPago[$columna] = ($row && $row['C'] !== null);

        return $this->columnasPago[$columna];
    }

    /**
     * La clave de un pago: proveedor + comprobante.
     *
     * Estatica y publica porque la usan la lectura, el guardado y el diff de la
     * importacion, y las tres tienen que armarla igual.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @return string
     */
    public static function clavePago($codProvee, $tComp, $nComp) {
        return Planilla::codigo($codProvee) . '|'
             . Planilla::codigo($tComp) . '|'
             . Planilla::codigo($nComp);
    }

    /* ====================================================================
       IMPORTACION DE LOS PAGOS

       La planilla con la que se decide CUANDO se paga cada factura. Es lo que
       disuelve los ochocientos millones apilados en el primer dia del eje: sin
       ella, todo lo vencido se dibuja hoy porque no hay otro lugar donde
       ponerlo.

       Mismo circuito que el maestro y que Cob. Electronicos: plantilla CSV,
       previsualizacion del diff, y nada se escribe hasta confirmar.
       ==================================================================== */

    /**
     * Las columnas de la planilla de pagos, con sus sinonimos.
     *
     * EL TIPO DE COMPROBANTE NO ES OBLIGATORIO, y es a proposito: quien arma la
     * planilla mira una factura y copia su numero, no su tipo. Cuando falta se
     * deduce de los pendientes del proveedor. Si ese numero existe con dos tipos
     * distintos -una FAC y una NDI con el mismo numero-, la fila queda en error
     * pidiendo que se aclare, en lugar de elegir una.
     *
     * @return array
     */
    public static function columnasImportacion() {
        return [
            'cod_provee' => [
                'titulo' => 'COD_PROVEEDOR',
                'obligatoria' => true,
                'ayuda' => 'Código del proveedor en Tango. Hace falta: el número de factura '
                    . 'solo no identifica un comprobante, porque lo emite el proveedor y dos '
                    . 'proveedores distintos repiten numeración.',
                'sinonimos' => ['CODPROVEEDOR', 'COD_PROVEE', 'CODIGO', 'COD_PROVEEDOR',
                                'PROVEEDOR', 'CODIGOPROVEEDOR']
            ],
            'n_comp' => [
                'titulo' => 'NRO_FACTURA',
                'obligatoria' => true,
                'ayuda' => 'Número de comprobante tal como figura en Tango, por ejemplo '
                    . 'A0000500001731.',
                'sinonimos' => ['NROFACTURA', 'N_COMP', 'NCOMP', 'NUMERO', 'NROCOMPROBANTE',
                                'FACTURA', 'COMPROBANTE']
            ],
            'fecha_pago' => [
                'titulo' => 'FECHA_PAGO',
                'obligatoria' => true,
                'ayuda' => 'Cuándo se piensa pagar. dd/mm/aaaa o aaaa-mm-dd.',
                'sinonimos' => ['FECHAPAGO', 'FECHA_PAGO', 'FECHA', 'PAGO', 'FECHADEPAGO']
            ],
            'forma_pago' => [
                'titulo' => 'FORMA_PAGO',
                'obligatoria' => false,
                'ayuda' => 'Opcional. Si no viene, se usa la forma habitual del proveedor '
                    . 'según el maestro. Válidos: '
                    . implode(', ', ProveedoresCategorias::FORMAS_PAGO) . '.',
                'sinonimos' => ['FORMAPAGO', 'FORMA_PAGO', 'FORMA', 'MEDIODEPAGO',
                                'FORMADEPAGO']
            ],
            't_comp' => [
                'titulo' => 'TIPO_COMP',
                'obligatoria' => false,
                'ayuda' => 'Opcional (FAC, NDI, NCP...). Si no viene se deduce de los '
                    . 'pendientes del proveedor.',
                'sinonimos' => ['TIPOCOMP', 'T_COMP', 'TCOMP', 'TIPO', 'TIPOCOMPROBANTE']
            ],
            'observacion' => [
                'titulo' => 'OBSERVACION',
                'obligatoria' => false,
                'ayuda' => 'Opcional, hasta 200 caracteres.',
                'sinonimos' => ['OBSERVACION', 'OBSERVACIONES', 'NOTAS', 'COMENTARIOS']
            ]
        ];
    }

    /**
     * La plantilla que se descarga, con filas de ejemplo cargadas.
     *
     * Las fechas de ejemplo se calculan sobre hoy para que nunca se vean viejas.
     *
     * @param string|null $ejemploFecha 'Y-m-d'
     * @return string
     */
    public static function plantillaCsv($ejemploFecha = null) {
        $fecha = ($ejemploFecha === null) ? date('Y-m-d') : substr((string) $ejemploFecha, 0, 10);
        $enUnaSemana = date('d/m/Y', strtotime($fecha . ' +7 day'));
        $enDosSemanas = date('d/m/Y', strtotime($fecha . ' +14 day'));

        return Planilla::plantillaCsv(self::columnasImportacion(), [
            ['MTDODI', 'A0000500001731', $enUnaSemana, 'TRANSFERENCIA', '', 'Ejemplo: borrar'],
            ['OGCOAN', 'A0000300001234', $enDosSemanas, 'ECHEQ', 'FAC',
             'Ejemplo: con tipo de comprobante']
        ]);
    }

    /**
     * Compara lo que trae el archivo contra los pendientes y contra lo ya
     * cargado, y dice QUE CAMBIARIA. No escribe nada.
     *
     * Helper PURO: recibe las filas parseadas, los pendientes y los pagos
     * cargados. Se prueba entero sin base y sin archivos.
     *
     * ESTADOS
     *   ALTA         el comprobante no tenia fecha cargada
     *   CAMBIO       la tenia y es otra; 'antes' dice cual
     *   SIN_CAMBIOS  ya estaba con esa fecha y esa forma
     *   ERROR        no se puede cargar; 'motivo' dice por que
     *
     * LO QUE NO MATCHEA CONTRA NINGUN PENDIENTE ES UN ERROR VISIBLE, no una
     * fila que se ignora. Un comprobante que no esta en el listado puede ser un
     * numero mal tipeado, un proveedor equivocado, o una factura que ya se pago
     * -y eso ultimo es informacion, no un descarte-. El motivo distingue los
     * tres casos, porque se arreglan distinto.
     *
     * @param array $filasArchivo Filas de Planilla::parsear()
     * @param array $pendientes Filas de getPendientes()
     * @param array $pagos Mapa de getPagos()
     * @param string|null $hoy 'Y-m-d' para validar que la fecha no sea absurda
     * @return array ['filas', 'resumen', 'avisos']
     */
    public static function compararImportacion($filasArchivo, $pendientes, $pagos, $hoy = null) {
        $hoyStr = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);

        /* Los pendientes, indexados de dos formas: por clave completa -cuando la
           planilla trae el tipo- y por (proveedor, numero) para poder deducirlo
           cuando no viene. */
        $porClave = [];
        $porNumero = [];

        foreach (is_array($pendientes) ? $pendientes : [] as $p) {
            $cod = Planilla::codigo($p['COD_PROVEE']);
            $tComp = Planilla::codigo($p['T_COMP']);
            $nComp = Planilla::codigo($p['N_COMP']);

            $porClave[self::clavePago($cod, $tComp, $nComp)] = $p;
            $porNumero[$cod . '|' . $nComp][] = $p;
        }

        $pagos = is_array($pagos) ? $pagos : [];
        $filas = [];
        $vistas = [];

        $resumen = [
            'altas' => 0, 'cambios' => 0, 'sin_cambios' => 0, 'errores' => 0,
            'importe_con_fecha' => 0.0, 'vencidos_resueltos' => 0,
            'forma_desconocida' => 0
        ];

        foreach (is_array($filasArchivo) ? $filasArchivo : [] as $cruda) {
            $fila = self::filaPago($cruda, $porClave, $porNumero, $hoyStr);

            if ($fila['estado'] !== 'ERROR') {
                $clave = self::clavePago($fila['cod_provee'], $fila['t_comp'], $fila['n_comp']);

                /* Dos filas para el mismo comprobante no se colapsan: cual vale
                   lo decide quien armo la planilla, no el importador. */
                if (isset($vistas[$clave])) {
                    $fila['estado'] = 'ERROR';
                    $fila['motivo'] = 'Este comprobante ya aparece en la línea '
                        . $vistas[$clave] . ' con otra fecha. Dejá una sola fila por '
                        . 'comprobante y volvé a importar.';
                } else {
                    $vistas[$clave] = $fila['linea'];
                    $existente = isset($pagos[$clave]) ? $pagos[$clave] : null;

                    if ($existente === null) {
                        $fila['estado'] = 'ALTA';
                        $fila['motivo'] = 'No tenía fecha de pago cargada.';
                    } elseif ($existente['FECHA_PAGO'] === $fila['fecha_pago']
                        && trim((string) $existente['FORMA_PAGO']) === trim((string) $fila['forma_pago'])) {
                        $fila['estado'] = 'SIN_CAMBIOS';
                        $fila['motivo'] = 'Ya estaba cargado igual.';
                    } else {
                        $fila['estado'] = 'CAMBIO';
                        $fila['antes'] = [
                            'fecha_pago' => $existente['FECHA_PAGO'],
                            'forma_pago' => $existente['FORMA_PAGO']
                        ];
                        $fila['motivo'] = 'Cambia la fecha cargada'
                            . ($existente['FECHA_PAGO'] !== null
                                ? ' (estaba en ' . self::formatoCorto($existente['FECHA_PAGO']) . ')'
                                : '') . '.';

                        /* Pisar una conciliada es distinto: Tango ya dijo que
                           ese comprobante se pago. Se permite -puede ser una
                           correccion- pero se marca. */
                        if ($existente['ESTADO'] === 'CONCILIADO') {
                            $fila['motivo'] .= ' OJO: este comprobante ya figura CONCILIADO '
                                . 'contra Tango, así que se pagó de verdad. Cambiarle la '
                                . 'fecha prevista no cambia eso.';
                        }
                    }
                }
            }

            if ($fila['estado'] !== 'ERROR') {
                $resumen['importe_con_fecha'] += $fila['importe_pendiente'];

                if ($fila['estaba_vencido']) {
                    $resumen['vencidos_resueltos']++;
                }

                if ($fila['forma_desconocida']) {
                    $resumen['forma_desconocida']++;
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

        return [
            'filas' => $filas,
            'resumen' => $resumen,
            'avisos' => self::avisosImportacionPagos($resumen)
        ];
    }

    /**
     * Normaliza y valida una fila de la planilla de pagos contra los pendientes.
     *
     * @param array $cruda
     * @param array $porClave Pendientes por clave completa
     * @param array $porNumero Pendientes por (proveedor, numero)
     * @param string $hoy
     * @return array
     */
    private static function filaPago($cruda, $porClave, $porNumero, $hoy) {
        $fila = [
            'linea' => isset($cruda['linea']) ? intval($cruda['linea']) : 0,
            'cod_provee' => '',
            't_comp' => '',
            'n_comp' => '',
            'fecha_pago' => null,
            'forma_pago' => null,
            'forma_pago_orig' => '',
            'forma_desconocida' => false,
            'observacion' => null,
            'razon_social' => '',
            'importe_pendiente' => 0.0,
            'fecha_vto' => null,
            'estaba_vencido' => false,
            'estado' => 'ALTA',
            'motivo' => '',
            'antes' => null
        ];

        $cod = Planilla::codigo(isset($cruda['cod_provee']) ? $cruda['cod_provee'] : '');
        $nComp = Planilla::codigo(isset($cruda['n_comp']) ? $cruda['n_comp'] : '');
        $tComp = Planilla::codigo(isset($cruda['t_comp']) ? $cruda['t_comp'] : '');

        $fila['cod_provee'] = $cod;
        $fila['n_comp'] = $nComp;
        $fila['t_comp'] = $tComp;

        if ($cod === '' || $nComp === '') {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'Falta el código de proveedor o el número de comprobante.';

            return $fila;
        }

        /* La fecha va antes que el cruce: sin fecha no hay nada que cargar,
           aunque el comprobante exista. */
        $fecha = Planilla::fecha(isset($cruda['fecha_pago']) ? $cruda['fecha_pago'] : '');

        if ($fecha === null) {
            $fila['estado'] = 'ERROR';
            $fila['motivo'] = 'La fecha de pago no se entiende: "'
                . trim((string) (isset($cruda['fecha_pago']) ? $cruda['fecha_pago'] : ''))
                . '". Usá dd/mm/aaaa o aaaa-mm-dd.';

            return $fila;
        }

        $fila['fecha_pago'] = $fecha;

        /* EL CRUCE CONTRA LOS PENDIENTES. Tres desenlaces, y cada uno se arregla
           distinto, asi que el motivo los distingue. */
        $pendiente = null;

        if ($tComp !== '') {
            $clave = self::clavePago($cod, $tComp, $nComp);
            $pendiente = isset($porClave[$clave]) ? $porClave[$clave] : null;

            if ($pendiente === null) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = 'No hay ningún comprobante pendiente ' . $tComp . ' '
                    . $nComp . ' del proveedor ' . $cod . '. Revisá el tipo, el número y el '
                    . 'código; si ya se pagó, no hace falta cargarle fecha.';

                return $fila;
            }
        } else {
            $candidatos = isset($porNumero[$cod . '|' . $nComp]) ? $porNumero[$cod . '|' . $nComp] : [];

            /* Un comprobante en cuotas tiene VARIOS vencimientos y aparece
               varias veces en los pendientes, pero es UN comprobante: eso no es
               ambiguedad. Lo que si lo es son dos TIPOS distintos con el mismo
               numero. */
            $tipos = [];

            foreach ($candidatos as $c) {
                $tipos[Planilla::codigo($c['T_COMP'])] = true;
            }

            if (count($tipos) === 0) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = 'El proveedor ' . $cod . ' no tiene ningún comprobante '
                    . $nComp . ' pendiente. Puede ser un número mal tipeado, un proveedor '
                    . 'equivocado, o una factura que ya se pagó.';

                return $fila;
            }

            if (count($tipos) > 1) {
                $fila['estado'] = 'ERROR';
                $fila['motivo'] = 'El proveedor ' . $cod . ' tiene el comprobante ' . $nComp
                    . ' con más de un tipo (' . implode(', ', array_keys($tipos)) . '). '
                    . 'Agregá la columna TIPO_COMP para aclarar cuál es.';

                return $fila;
            }

            $fila['t_comp'] = key($tipos);
            $pendiente = $candidatos[0];
        }

        $fila['razon_social'] = isset($pendiente['RAZON_SOC']) ? $pendiente['RAZON_SOC'] : '';
        $fila['fecha_vto'] = isset($pendiente['FECHA_VTO']) ? $pendiente['FECHA_VTO'] : null;

        /* El importe del comprobante es la SUMA de sus vencimientos pendientes:
           la fecha de pago se carga por comprobante, no por cuota, asi que lo
           que se esta reubicando es todo lo que se le debe. */
        $candidatos = isset($porNumero[$cod . '|' . $nComp]) ? $porNumero[$cod . '|' . $nComp] : [$pendiente];

        foreach ($candidatos as $c) {
            if (Planilla::codigo($c['T_COMP']) !== $fila['t_comp']) {
                continue;
            }

            $fila['importe_pendiente'] += floatval($c['IMPORTE_PENDIENTE']);

            if (!empty($c['SIN_FECHA_CARGADA'])) {
                $fila['estaba_vencido'] = true;
            }
        }

        /* La forma de pago: la de la planilla, y si no vino, la habitual del
           proveedor segun el maestro. Ver la nota de columnasImportacion(). */
        $forma = ProveedoresCategorias::normalizarFormaPago(
            isset($cruda['forma_pago']) ? $cruda['forma_pago'] : '');

        if ($forma['original'] === '') {
            $fila['forma_pago'] = isset($pendiente['FORMA_PAGO']) ? $pendiente['FORMA_PAGO'] : null;
        } else {
            $fila['forma_pago'] = $forma['normalizado'];
            $fila['forma_pago_orig'] = $forma['original'];
            $fila['forma_desconocida'] = ($forma['normalizado'] === null);
        }

        $obs = trim(isset($cruda['observacion']) ? (string) $cruda['observacion'] : '');
        $fila['observacion'] = ($obs === '') ? null : mb_substr($obs, 0, 200);

        return $fila;
    }

    /**
     * Los avisos del resumen de importacion de pagos.
     *
     * EL PRIMERO ES EL QUE IMPORTA: cuanto de lo vencido queda resuelto. Es la
     * razon de ser de esta importacion, y verlo antes de confirmar es lo que
     * permite saber si la planilla cubrio lo que tenia que cubrir.
     *
     * Estaticos y puros.
     *
     * @param array $resumen
     * @return array
     */
    private static function avisosImportacionPagos($resumen) {
        $avisos = [];

        if ($resumen['vencidos_resueltos'] > 0) {
            $avisos[] = $resumen['vencidos_resueltos'] . ' comprobante(s) que hoy están '
                . 'apilados en el primer día del eje por estar vencidos sin fecha pasan a '
                . 'tener una. Es lo que esta importación viene a resolver.';
        }

        if ($resumen['errores'] > 0) {
            $avisos[] = $resumen['errores'] . ' fila(s) no se pueden cargar y quedan afuera. '
                . 'El resto se importa igual: mirá el motivo de cada una, porque no todas '
                . 'fallan por lo mismo.';
        }

        if ($resumen['forma_desconocida'] > 0) {
            $avisos[] = $resumen['forma_desconocida'] . ' fila(s) traen una forma de pago que '
                . 'no está en la lista de válidas. Se guarda tal como vino.';
        }

        if ($resumen['altas'] === 0 && $resumen['cambios'] === 0 && $resumen['errores'] === 0) {
            $avisos[] = 'El archivo no cambia nada: todo lo que trae ya estaba cargado igual.';
        }

        return $avisos;
    }

    /**
     * Aplica una importacion de pagos ya confirmada.
     *
     * TODO EN UNA TRANSACCION, y solo las filas ALTA y CAMBIO: las que estan en
     * error no se tocan. Importar lo que se pueda es mejor que parar todo por
     * una fila mala, que obligaria a corregir la planilla entera antes de poder
     * cargar las buenas.
     *
     * @param array $comparacion Lo que devolvio compararImportacion()
     * @param string|null $usuario
     * @return array ['altas', 'cambios']
     */
    public function aplicarImportacion($comparacion, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $cid = $this->conectar();
        $aplicadas = ['altas' => 0, 'cambios' => 0];

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($comparacion['filas'] as $fila) {
                if ($fila['estado'] !== 'ALTA' && $fila['estado'] !== 'CAMBIO') {
                    continue;
                }

                /* LA IMPORTACION NO TOCA FORMA_PAGO_CRONOGRAMA, y es
                   deliberado: esa columna es una REGLA que alguien puso a mano
                   para una factura, y la planilla trae HECHOS -por que via
                   salio cada pago-. Si la importacion la escribiera, subir la
                   planilla reclasificaria comprobantes dentro y fuera del
                   cashflow sin que nadie lo haya pedido. Ver
                   formaDelCronograma(). */
                $this->guardarPago($cid,
                    $fila['cod_provee'], $fila['t_comp'], $fila['n_comp'],
                    [
                        'FECHA_PAGO' => $fila['fecha_pago'],
                        'FORMA_PAGO' => $fila['forma_pago'],
                        'FORMA_PAGO_ORIG' => ($fila['forma_pago_orig'] === '')
                            ? null : $fila['forma_pago_orig'],
                        'OBSERVACION' => $fila['observacion']
                    ],
                    'ARCHIVO', $usuario);

                $aplicadas[$fila['estado'] === 'ALTA' ? 'altas' : 'cambios']++;
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $aplicadas;
    }

    /**
     * Guarda la fecha de pago de un comprobante, de a uno.
     *
     * Es lo que usa la edicion fila por fila de la grilla. La importacion masiva
     * pasa por el mismo metodo privado, asi que las dos escriben igual.
     *
     * LO QUE NO SE MANDA NO SE PISA. La grilla edita UNA celda -la fecha- y
     * manda solo esa; si los campos que no viajaron se escribieran igual, cada
     * carga de fecha borraria la forma de pago y la observacion que habia
     * dejado la importacion de la planilla. Un endpoint que recibe un campo y
     * escribe cuatro no esta guardando una edicion, esta reemplazando la fila.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param string $fecha 'Y-m-d'
     * @param string|null $formaPago Si no viene, la que haya queda como esta
     * @param string|null $observacion Idem
     * @param string|null $usuario
     * @return array ['fecha', 'forma']
     */
    public function savePago($codProvee, $tComp, $nComp, $fecha, $formaPago = null,
                             $observacion = null, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $cod = Planilla::codigo($codProvee);
        $t = Planilla::codigo($tComp);
        $n = Planilla::codigo($nComp);

        if ($cod === '' || $t === '' || $n === '') {
            throw new Exception('Falta el proveedor o el comprobante al que corresponde la '
                . 'fecha de pago.');
        }

        $f = self::validarFechaPago($fecha);
        $forma = ProveedoresCategorias::normalizarFormaPago($formaPago);
        $obs = ($observacion === null) ? '' : trim((string) $observacion);

        /* Ver la nota del docblock de guardarPago(): lo que no entra al mapa no
           se escribe. Vacio y ausente son lo mismo acá porque la grilla no tiene
           con que mandar un vacio a proposito: edita la fecha y nada mas. */
        $campos = ['FECHA_PAGO' => $f];

        if ($forma['original'] !== '') {
            $campos['FORMA_PAGO'] = $forma['normalizado'];
            $campos['FORMA_PAGO_ORIG'] = $forma['original'];
        }

        if ($obs !== '') {
            $campos['OBSERVACION'] = mb_substr($obs, 0, 200);
        }

        $this->guardarPago($this->conectar(), $cod, $t, $n, $campos, 'MANUAL', $usuario);

        return ['fecha' => $f, 'forma' => $forma['normalizado']];
    }

    /**
     * Pone o saca el OVERRIDE DE FORMA DE PAGO de un comprobante.
     *
     * ES UNA REGLA POR FACTURA, no un hecho: dice con que forma hay que tratar a
     * ESTE comprobante para decidir si entra al cronograma del cashflow. Pisa a
     * la del maestro solo para el, y NO TOCA EL MAESTRO: las otras facturas del
     * mismo proveedor siguen como estaban.
     *
     * VA EN SU PROPIA COLUMNA y no en FORMA_PAGO, que es el hecho que escribe la
     * importacion de la planilla en todas sus filas. Ver formaDelCronograma().
     *
     * SACARLO ES MANDAR VACIO, y entonces vuelve a decidir el maestro. No borra
     * la fila: la fecha de pago y la observacion que esa fila tenga siguen
     * estando, porque son otra cosa.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @param string|null $forma Una de FORMAS_PAGO, o vacio para sacar el override
     * @param string|null $usuario
     * @return array ['forma' => string|null]
     */
    public function saveFormaCronograma($codProvee, $tComp, $nComp, $forma, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de overrides por comprobante. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        if (!$this->tieneColumnaPago('FORMA_PAGO_CRONOGRAMA')) {
            throw new Exception('Todavía no se puede fijar la forma de pago por factura. '
                . 'Corré sql/cashflow_prov_locales_forma_por_factura.sql contra la base '
                . 'central.');
        }

        $cod = Planilla::codigo($codProvee);
        $t = Planilla::codigo($tComp);
        $n = Planilla::codigo($nComp);

        if ($cod === '' || $t === '' || $n === '') {
            throw new Exception('Falta el proveedor o el comprobante.');
        }

        $v = trim((string) $forma);

        /* SE VALIDA CONTRA LA LISTA y no se acepta cualquier texto: esta forma
           DECIDE si el importe entra al cashflow, asi que una que no este en
           FORMAS_PAGO no decidiria nada y quedaria como un override que parece
           puesto y no hace nada. Es lo contrario de FORMA_PAGO, que guarda lo
           que diga la planilla porque describe un hecho. */
        if ($v !== '' && !isset(ProveedoresCategorias::FORMAS_PAGO[mb_strtoupper($v)])) {
            throw new Exception('"' . $v . '" no es una forma de pago conocida. Las válidas '
                . 'son: ' . implode(', ', array_keys(ProveedoresCategorias::FORMAS_PAGO)) . '.');
        }

        $normalizada = ($v === '') ? null : mb_strtoupper($v);

        $this->guardarPago($this->conectar(), $cod, $t, $n,
            ['FORMA_PAGO_CRONOGRAMA' => $normalizada], 'MANUAL', $usuario);

        return ['forma' => $normalizada];
    }

    /**
     * Borra la fecha de pago de un comprobante: vuelve a valer el vencimiento.
     *
     * ACA SI HAY BORRADO FISICO, igual que en la fecha manual de Cobranzas FR y
     * por el mismo motivo: la fila no es un importe ni un dato historico, es un
     * override puntual de un calculo, y su baja logica seria indistinguible de
     * no tenerla. Lo que este modulo no borra son las categorias del maestro,
     * que si explican como se clasificaba antes.
     *
     * @param string $codProvee
     * @param string $tComp
     * @param string $nComp
     * @return bool
     */
    public function deletePago($codProvee, $tComp, $nComp) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago.');
        }

        $stmt = sqlsrv_query($this->conectar(),
            "DELETE FROM dbo." . self::TABLA_PAGO . "
             WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?",
            [Planilla::codigo($codProvee),
             Planilla::codigo($tComp),
             Planilla::codigo($nComp)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al borrar la fecha de pago'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        return ($filas > 0);
    }

    /**
     * Valida y normaliza una fecha de pago.
     *
     * A DIFERENCIA DE LA FECHA DE COBRO DE COBRANZAS FR, ACA SI SE ACEPTAN
     * FECHAS PASADAS. Alla una fecha vieja hacia desaparecer la factura del
     * listado sin aviso; aca el listado muestra todo lo pendiente sin techo de
     * antiguedad, asi que una fecha de la semana pasada es una decision
     * legitima -se penso pagar y no se pago- y la factura sigue a la vista,
     * marcada como vencida.
     *
     * Estatica y pura.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFechaPago($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha de pago no es una fecha válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha de pago no existe en el calendario.');
        }

        return $f;
    }

    /**
     * Escribe una fecha de pago. Un UPDATE que no toca nada y despues un INSERT:
     * la unicidad es (COD_PROVEE, T_COMP, N_COMP), asi que no puede duplicar.
     *
     * NO PISA EL ESTADO NI LA FECHA REAL DE CANCELACION. Si el comprobante ya
     * estaba conciliado, cambiarle la prevision no lo desconcilia: Tango es la
     * verdad sobre el pago y esto es una prevision.
     *
     * TAMPOCO PISA LO QUE EL LLAMADOR NO TRAJO, y ahora eso es estructural en
     * vez de un booleano por campo: $campos es un mapa columna => valor con
     * EXACTAMENTE lo que hay que escribir, y lo que no esta en el mapa no entra
     * ni al UPDATE ni al INSERT.
     *
     * Con un flag por campo, agregar un override cuarto obligaba a agregar un
     * cuarto flag y a que todos los llamadores lo pasaran bien. Un endpoint que
     * recibe un campo y escribe cuatro no esta guardando una edicion: esta
     * reemplazando la fila, y asi se borraban la forma y la observacion que
     * habia dejado la importacion de la planilla.
     */
    private function guardarPago($cid, $cod, $t, $n, $campos, $origen, $usuario) {
        if (empty($campos)) {
            throw new Exception('No hay nada que guardar para ese comprobante.');
        }

        $sets = [];
        $params = [];

        foreach ($campos as $col => $valor) {
            $sets[] = $col . ' = ?';
            $params[] = $valor;
        }

        $sets[] = 'ORIGEN = ?';
        $sets[] = 'USUARIO = ?';
        $sets[] = 'FECHA_MOD = GETDATE()';
        $params[] = $origen;
        $params[] = $usuario;

        $sql = "UPDATE dbo." . self::TABLA_PAGO . "
                SET " . implode(', ', $sets) . "
                WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?";

        $stmt = sqlsrv_query($cid, $sql,
            array_merge($params, [$cod, $t, $n]));

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el comprobante'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if ($filas > 0) {
            return;
        }

        /* No habia fila. Lo que el llamador no trajo queda en su default, que es
           NULL para los tres overrides: "no hay decision tomada sobre esto". */
        $cols = array_merge(['COD_PROVEE', 'T_COMP', 'N_COMP'], array_keys($campos));
        $vals = array_merge([$cod, $t, $n], array_values($campos));

        $cols[] = 'ESTADO';
        $cols[] = 'ORIGEN';
        $cols[] = 'USUARIO';
        $vals[] = 'PREVISTO';
        $vals[] = $origen;
        $vals[] = $usuario;

        $sql = "INSERT INTO dbo." . self::TABLA_PAGO . "
                    (" . implode(', ', $cols) . ")
                VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";

        $stmt = sqlsrv_query($cid, $sql, $vals);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar el comprobante'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** dd/mm/aaaa, para los mensajes */
    private static function formatoCorto($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        return ($f === null) ? '' : date('d/m/Y', strtotime($f));
    }

    /* ====================================================================
       CONCILIACION CONTRA LAS CANCELADAS

       TANGO ES LA VERDAD SOBRE EL PAGO. Lo que se carga en la pestana es una
       PREVISION: cuando se piensa pagar. Cuando el comprobante aparece
       cancelado en Tango, dejo de ser una prevision y paso a ser un hecho, y
       deja de proyectarse -sale del listado de pendientes por si solo, porque
       ESTADO ya no es 'PEN'-.

       LA PREVISION NO SE BORRA AL CONCILIAR. Queda marcada como CONCILIADA con
       la fecha REAL de cancelacion al lado, sin pisar la prevista. Es lo unico
       que permite despues comparar lo que se planifico contra lo que paso: si
       se borrara, la unica pregunta que esta tabla puede contestar bien -"le
       acertamos a la fecha?"- quedaria sin respuesta para siempre.
       ==================================================================== */

    /**
     * Compara las previsiones cargadas contra el estado real en Tango.
     *
     * CONSULTA SOLO LO QUE TIENE PREVISION, no las 96.403 canceladas: la
     * pregunta es "de lo que yo tengo previsto, que ya se pago", y al reves
     * seria traer toda la historia de compras para cruzar contra un puñado.
     *
     * DETECTA LOS DOS SENTIDOS:
     *
     *   CONCILIA      estaba PREVISTO y el comprobante ya no esta pendiente en
     *                 Tango: se pago.
     *   REABRE        estaba CONCILIADO y el comprobante volvio a estar
     *                 pendiente. Pasa cuando se anula una orden de pago. Sin
     *                 esto, esa deuda quedaria marcada como pagada para siempre
     *                 aunque el tablero la vuelva a mostrar.
     *
     * NO ESCRIBE NADA SI $aplicar es false: primero se mira que cambiaria, igual
     * que en las dos importaciones.
     *
     * @param bool $aplicar
     * @param string|null $usuario
     * @return array ['concilia', 'reabre', 'sin_cambios', 'resumen']
     */
    public function conciliar($aplicar = false, $usuario = null) {
        if (!$this->tablaCreada()) {
            throw new Exception('Todavía no existe la tabla de fechas de pago. '
                . 'Corré sql/cashflow_prov_locales.sql contra la base central.');
        }

        $pagos = $this->getPagos();

        if (empty($pagos)) {
            return [
                'concilia' => [], 'reabre' => [], 'sin_cambios' => 0,
                'resumen' => ['concilia' => 0, 'reabre' => 0, 'sin_cambios' => 0,
                              'importe_conciliado' => 0.0],
                'avisos' => ['No hay ninguna fecha de pago cargada, así que no hay nada que '
                    . 'conciliar.']
            ];
        }

        $estados = $this->estadoEnTango($pagos);

        $concilia = [];
        $reabre = [];
        $sinCambios = 0;
        $importeConciliado = 0.0;

        foreach ($pagos as $clave => $pago) {
            $enTango = isset($estados[$clave]) ? $estados[$clave] : null;

            /* Un comprobante que NO aparece en Tango no se toca. Puede haber
               sido anulado, depurado, o cargado con un codigo que despues
               cambio. Marcarlo como pagado seria inventar un hecho. */
            if ($enTango === null) {
                $sinCambios++;
                continue;
            }

            $pendiente = ($enTango['ESTADO'] === 'PEN');

            if ($pago['ESTADO'] === 'PREVISTO' && !$pendiente) {
                $concilia[] = [
                    'cod_provee' => $pago['COD_PROVEE'],
                    't_comp' => $pago['T_COMP'],
                    'n_comp' => $pago['N_COMP'],
                    'razon_social' => $enTango['NOM_PROVEE'],
                    'fecha_prevista' => $pago['FECHA_PAGO'],
                    'fecha_cancelado' => $enTango['FECHA_CANCELADO'],
                    'estado_tango' => $enTango['ESTADO'],
                    'importe' => $enTango['IMPORTE'],
                    // Cuantos dias antes o despues de lo previsto se pago. Es
                    // el dato por el que existe guardar las dos fechas.
                    'desvio_dias' => self::desvioDias($pago['FECHA_PAGO'],
                        $enTango['FECHA_CANCELADO'])
                ];

                $importeConciliado += floatval($enTango['IMPORTE']);
                continue;
            }

            if ($pago['ESTADO'] === 'CONCILIADO' && $pendiente) {
                $reabre[] = [
                    'cod_provee' => $pago['COD_PROVEE'],
                    't_comp' => $pago['T_COMP'],
                    'n_comp' => $pago['N_COMP'],
                    'razon_social' => $enTango['NOM_PROVEE'],
                    'fecha_prevista' => $pago['FECHA_PAGO'],
                    'fecha_cancelado' => $pago['FECHA_CANCELADO']
                ];

                continue;
            }

            $sinCambios++;
        }

        if ($aplicar) {
            $this->aplicarConciliacion($concilia, $reabre, $usuario);
        }

        $resumen = [
            'concilia' => count($concilia),
            'reabre' => count($reabre),
            'sin_cambios' => $sinCambios,
            'importe_conciliado' => round($importeConciliado, 2)
        ];

        return [
            'concilia' => $concilia,
            'reabre' => $reabre,
            'sin_cambios' => $sinCambios,
            'resumen' => $resumen,
            'avisos' => self::avisosConciliacion($resumen, $concilia)
        ];
    }

    /**
     * El estado real en Tango de los comprobantes que tienen prevision.
     *
     * Se filtra por los PROVEEDORES involucrados y se cruza en PHP por
     * comprobante. Es mas simple y mas seguro que armar un IN con cientos de
     * pares, y el filtro por proveedor ya acota muchisimo.
     *
     * La FECHA REAL de cancelacion sale del ultimo comprobante que la cancelo
     * (MAX de CPA05.F_COMP_CAN). No de CPA04: ahi no hay fecha de pago, hay
     * fecha de emision y de contabilizacion, que son otra cosa.
     *
     * @param array $pagos Mapa de getPagos()
     * @return array Mapa clave => ['ESTADO', 'FECHA_CANCELADO', 'IMPORTE', 'NOM_PROVEE']
     */
    private function estadoEnTango($pagos) {
        $proveedores = [];

        foreach ($pagos as $p) {
            $proveedores[$p['COD_PROVEE']] = true;
        }

        if (empty($proveedores)) {
            return [];
        }

        $cid = $this->conectar();
        $codigos = array_keys($proveedores);
        $marcas = implode(',', array_fill(0, count($codigos), '?'));

        $sql = "SELECT a.COD_PROVEE, a.T_COMP, a.N_COMP, a.ESTADO, p.NOM_PROVEE,
                       CAST(a.IMPORTE_TO AS FLOAT) AS IMPORTE,
                       (SELECT MAX(i.F_COMP_CAN) FROM CPA05 i
                         WHERE i.ID_CPA04 = a.ID_CPA04) AS FECHA_CANCELADO
                FROM CPA04 a
                INNER JOIN CPA01 p ON p.COD_PROVEE = a.COD_PROVEE
                WHERE a.COD_PROVEE IN (" . $marcas . ")";

        $stmt = sqlsrv_query($cid, $sql, $codigos);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el estado en Tango'));
        }

        $estados = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = self::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);

            $estados[$clave] = [
                'ESTADO' => strtoupper(trim((string) $row['ESTADO'])),
                'FECHA_CANCELADO' => Horizonte::normalizarFecha($row['FECHA_CANCELADO']),
                'IMPORTE' => round(floatval($row['IMPORTE']), 2),
                'NOM_PROVEE' => trim((string) $row['NOM_PROVEE'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $estados;
    }

    /** Escribe el resultado de la conciliacion, todo en una transaccion */
    private function aplicarConciliacion($concilia, $reabre, $usuario) {
        if (empty($concilia) && empty($reabre)) {
            return;
        }

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($concilia as $c) {
                /* La fecha PREVISTA no se pisa: al lado se guarda la real. Es
                   lo unico que permite comparar despues prevision contra
                   realidad. */
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_PAGO . "
                     SET ESTADO = 'CONCILIADO', FECHA_CANCELADO = ?, FECHA_CONCILIA = GETDATE(),
                         USUARIO = ?, FECHA_MOD = GETDATE()
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?",
                    [$c['fecha_cancelado'], $usuario,
                     $c['cod_provee'], $c['t_comp'], $c['n_comp']]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al conciliar '
                        . $c['t_comp'] . ' ' . $c['n_comp']));
                }

                sqlsrv_free_stmt($stmt);
            }

            foreach ($reabre as $r) {
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA_PAGO . "
                     SET ESTADO = 'PREVISTO', FECHA_CANCELADO = NULL, FECHA_CONCILIA = NULL,
                         USUARIO = ?, FECHA_MOD = GETDATE()
                     WHERE COD_PROVEE = ? AND T_COMP = ? AND N_COMP = ?",
                    [$usuario, $r['cod_provee'], $r['t_comp'], $r['n_comp']]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al reabrir '
                        . $r['t_comp'] . ' ' . $r['n_comp']));
                }

                sqlsrv_free_stmt($stmt);
            }

            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }
    }

    /**
     * Cuantos dias antes (negativo) o despues (positivo) de lo previsto se pago.
     *
     * Es el dato por el que vale la pena guardar las dos fechas en lugar de
     * pisar una con la otra: contesta si la prevision sirve o si siempre se paga
     * mas tarde de lo que se dice.
     *
     * Estatica y pura.
     *
     * @param string|null $prevista
     * @param string|null $real
     * @return int|null null si falta alguna de las dos
     */
    public static function desvioDias($prevista, $real) {
        $p = Horizonte::normalizarFecha($prevista);
        $r = Horizonte::normalizarFecha($real);

        if ($p === null || $r === null) {
            return null;
        }

        $a = new DateTime($p);
        $b = new DateTime($r);
        $a->setTime(0, 0, 0);
        $b->setTime(0, 0, 0);

        return intval($a->diff($b)->format('%r%a'));
    }

    /**
     * Los avisos de la conciliacion. Dicen QUE CAMBIO, que es lo que evita
     * tener que comparar fila por fila.
     *
     * Estaticos y puros.
     *
     * @param array $resumen
     * @param array $concilia
     * @return array
     */
    private static function avisosConciliacion($resumen, $concilia) {
        $avisos = [];

        if ($resumen['concilia'] === 0 && $resumen['reabre'] === 0) {
            $avisos[] = 'Nada cambió: las ' . $resumen['sin_cambios'] . ' previsiones cargadas '
                . 'siguen como estaban.';

            return $avisos;
        }

        if ($resumen['concilia'] > 0) {
            $avisos[] = $resumen['concilia'] . ' comprobante(s) por '
                . self::plata($resumen['importe_conciliado']) . ' ya figuran cancelados en '
                . 'Tango. Su previsión queda marcada como conciliada, con la fecha real al '
                . 'lado: no se borra, porque es lo que permite comparar después lo que se '
                . 'planificó contra lo que pasó.';

            /* El desvio promedio contesta si la prevision sirve. Con pocos casos
               no significa nada, asi que se informa recien con cinco. */
            $conDesvio = [];

            foreach ($concilia as $c) {
                if ($c['desvio_dias'] !== null) {
                    $conDesvio[] = $c['desvio_dias'];
                }
            }

            if (count($conDesvio) >= 5) {
                $promedio = array_sum($conDesvio) / count($conDesvio);

                $avisos[] = 'En promedio se pagó ' . abs(round($promedio, 1)) . ' día(s) '
                    . ($promedio >= 0 ? 'DESPUÉS' : 'ANTES') . ' de lo previsto, sobre '
                    . count($conDesvio) . ' comprobante(s). Es lo que dice si la previsión '
                    . 'está sirviendo.';
            }
        }

        if ($resumen['reabre'] > 0) {
            $avisos[] = $resumen['reabre'] . ' comprobante(s) estaban conciliados y volvieron a '
                . 'estar pendientes en Tango: lo más probable es que se haya anulado una orden '
                . 'de pago. Vuelven a proyectarse con su fecha prevista.';
        }

        return $avisos;
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

    /** $ 1.234,56, para los avisos */
    private static function plata($n) {
        return '$ ' . number_format(floatval($n), 2, ',', '.');
    }

    /** Arma el mensaje de error a partir de sqlsrv_errors() */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ': ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
