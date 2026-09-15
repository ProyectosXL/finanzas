<?php

require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/Ingresos.php';
require_once __DIR__ . '/ProveedoresCategorias.php';

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
                t.CRE_DEB,
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
            LEFT  JOIN CPA21 t ON t.T_COMP     = a.T_COMP
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
            $cod = strtoupper(trim((string) $row['COD_PROVEE']));
            $tComp = strtoupper(trim((string) $row['T_COMP']));
            $nComp = strtoupper(trim((string) $row['N_COMP']));
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

            $items[] = [
                'COD_PROVEE' => $cod,
                'RAZON_SOC' => trim((string) $row['NOM_PROVEE']),
                'T_COMP' => $tComp,
                'N_COMP' => $nComp,
                'CRE_DEB' => trim((string) $row['CRE_DEB']),
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
                'FORMA_PAGO' => ($pago === null) ? $cat['forma_pago'] : $pago['FORMA_PAGO'],
                'FORMA_PAGO_ORIG' => ($pago === null) ? null : $pago['FORMA_PAGO_ORIG'],
                'OBSERVACION' => ($pago === null) ? null : $pago['OBSERVACION'],
                'ESTADO_PAGO' => ($pago === null) ? null : $pago['ESTADO'],

                // La categoria, ya resuelta.
                'EN_MAESTRO' => $cat['en_maestro'],
                'RUBRO_ECONOMICO' => $cat['rubro_economico'],
                'RUBRO' => $cat['rubro'],
                'CENTRO_COSTOS' => $cat['centro_costos'],
                'EXCLUIDO' => $cat['excluido'],
                'SERIE' => $cat['serie']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $items;
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

        if ($compVencidos > 0) {
            $avisos[] = $compVencidos . ' vencimiento(s) por ' . self::plata($vencidoSinFecha)
                . ' ya vencieron y NO tienen fecha de pago cargada. Se muestran en el primer '
                . 'día del eje porque no hay otro lugar donde ponerlos, pero eso no significa '
                . 'que se paguen hoy: cargales la fecha, de a uno en la grilla o importando '
                . 'la planilla de pagos.';
        }

        if ($compSinFecha > 0) {
            $avisos[] = $compSinFecha . ' vencimiento(s) por ' . self::plata($sinFecha)
                . ' no tienen fecha de vencimiento en Tango ni plazo de pago en el maestro, '
                . 'así que no se pueden ubicar en el eje.';
        }

        if ($compExcluidos > 0) {
            $avisos[] = $compExcluidos . ' vencimiento(s) por ' . self::plata($excluido)
                . ' son de proveedores con rubro "' . ProveedoresCategorias::RUBRO_EXCLUIDOS
                . '" en el maestro. Se listan acá pero su fila del tablero se puede '
                . 'inhabilitar desde Parámetros.';
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
                       OBSERVACION, ESTADO, FECHA_CANCELADO, ORIGEN, USUARIO
                FROM dbo." . self::TABLA_PAGO;

        $stmt = sqlsrv_query($cid, $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las fechas de pago'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $clave = self::clavePago($row['COD_PROVEE'], $row['T_COMP'], $row['N_COMP']);

            $mapa[$clave] = [
                'COD_PROVEE' => strtoupper(trim((string) $row['COD_PROVEE'])),
                'T_COMP' => strtoupper(trim((string) $row['T_COMP'])),
                'N_COMP' => strtoupper(trim((string) $row['N_COMP'])),
                'FECHA_PAGO' => Horizonte::normalizarFecha($row['FECHA_PAGO']),
                'FORMA_PAGO' => $row['FORMA_PAGO'],
                'FORMA_PAGO_ORIG' => $row['FORMA_PAGO_ORIG'],
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
        return strtoupper(trim((string) $codProvee)) . '|'
             . strtoupper(trim((string) $tComp)) . '|'
             . strtoupper(trim((string) $nComp));
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
