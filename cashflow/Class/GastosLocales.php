<?php

require_once __DIR__ . '/GastosLocalesCalculo.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/AuthCashflow.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * GastosLocales
 * La lectura de lo que la pestana Proveedores -> Gastos Locales y su fila del
 * tablero necesitan: los egresos de caja de los locales, la lista de cuentas
 * excluidas, la inflacion y el cronograma.
 *
 * DE DONDE SALEN LOS GASTOS
 * -------------------------
 * CTA29 / CTA28 en el servidor 'locales' (base LOCALES_LAKERS), la tesoreria
 * consolidada de las sucursales. Es la misma conexion que Saldos usa para la
 * caja de los locales, y el mismo prefijo de linked server en DEV. CTA29 existe
 * tambien en 'central', pero alla no esta SUCURSALES_LAKERS: no es la base.
 *
 *   - Solo egresos (D_H = 'D') de cuentas que empiezan con 5, menos las del
 *     parametro gastos_locales_cuentas_excluidas (hoy, 522400).
 *   - Solo locales PROPIOS y HABILITADOS, el mismo filtro que Saldos Locales.
 *     Un local que cerro no va a gastar mas, y proyectar su promedio seria
 *     pedir plata para nadie.
 *   - Los 90 dias corridos que terminan AYER. La ventana la calcula el
 *     servidor (CAST(GETDATE() AS DATE)), sin hora: GETDATE()-90 a secas
 *     cortaria a la hora en que se mira la pantalla.
 *
 * COD_CTA ES FLOAT en CTA29. Se lleva a BIGINT y a texto en SQL, como hace
 * Saldos, para que LIKE '5%' compare contra '521700' y no contra '5.217e+005', y
 * para que la lista de excluidas se compare como numero.
 *
 * DOS CONSULTAS, Y LA SEGUNDA A PEDIDO
 * ------------------------------------
 * La pantalla y el tablero solo necesitan sucursal, cuenta e importe: la
 * primera consulta trae eso YA AGRUPADO por (local, cuenta), unas ochenta filas.
 * La leyenda, el usuario y el comprobante solo hacen falta para mirar el
 * detalle de un local, y van en comprobantes(), que se pide al abrirlo.
 *
 * EL CALCULO NO VIVE ACA: es GastosLocalesCalculo, que es puro. Mismo criterio
 * que Logistica / LogisticaPlanilla.
 */
class GastosLocales {

    /** La lista de cuentas excluidas, en RO_T_CASHFLOW_PARAMETROS */
    const PARAM_CUENTAS = 'gastos_locales_cuentas_excluidas';

    /** El valor por defecto, el mismo que siembra el script */
    const CUENTAS_DEFECTO = ['522400'];

    /** El script de esta entrega, para los avisos */
    const SCRIPT = 'sql/cashflow_gastos_locales.sql';

    /** El concepto del cronograma */
    const CONCEPTO = 'GASTOS_LOCALES';

    /** @var Conexion */
    private $conn;

    /** @var array|null Cache de cuentasExcluidas() */
    private $cuentas = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LA PLANILLA COMPLETA
       ==================================================================== */

    /**
     * Todo lo que la pestana y el proveedor necesitan.
     *
     * NO LANZA. Si la consulta de gastos falla, 'leido' va en false con un aviso
     * critico, y quien llama decide: la pestana muestra la grilla vacia y el
     * proveedor no rinde ninguna serie. La inflacion y el cronograma pueden
     * fallar por su lado y se sigue, con su aviso.
     *
     * Los avisos salen dos veces, como en Logistica: 'avisos' con los textos
     * (la pestana) y 'avisos_con_nivel' (el tablero). Son la misma lista.
     *
     * @param Horizonte $h
     * @return array ['leido' => bool, 'ventana' => ['desde', 'hasta'], 'locales',
     *                'partidas', 'resumen', 'cuentas_excluidas', 'cronograma_config',
     *                'faltan_inflacion', 'avisos', 'avisos_con_nivel']
     */
    public function planilla($h) {
        $avisos = [];
        $cuentas = $this->cuentasExcluidas();

        if ($cuentas['aviso'] !== null) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, $cuentas['aviso']);
        }

        $leido = true;
        $filas = [];
        $ventana = ['desde' => null, 'hasta' => null];

        try {
            $g = $this->gastos($cuentas['cuentas']);
            $filas = $g['filas'];
            $ventana = $g['ventana'];
        } catch (Throwable $e) {
            $leido = false;
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los egresos de caja de '
                . 'los locales (' . $e->getMessage() . '). No hay promedio que proyectar, así que '
                . 'la fila Gastos Locales del tablero va en CERO, y ese cero NO significa que los '
                . 'locales no gasten.');
        }

        /* La lista de locales propios habilitados, para que los que no gastaron
           nada en 90 dias se vean con su cero. Si no se puede leer se sigue con
           los que trajo la consulta: el total no cambia. */
        $sucursales = [];

        if ($leido) {
            try {
                require_once __DIR__ . '/Saldos.php';

                $sucursales = (new Saldos())->getSucursalesOrigen();
            } catch (Throwable $e) {
                $avisos[] = Aviso::nuevo(Aviso::INFO, 'No se pudo leer la lista de locales propios ('
                    . $e->getMessage() . '): sólo se ven los que tuvieron egresos. El total no cambia.');
            }
        }

        $inflacion = [];

        try {
            require_once __DIR__ . '/Inflacion.php';

            $inf = new Inflacion();
            $inflacion = $inf->valores();

            if ($inf->avisoSinTabla() !== '') {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, $inf->avisoSinTabla());
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer la inflación mensual ('
                . $e->getMessage() . '). Los meses que necesitan ajuste quedan sin proyectar.');
        }

        require_once __DIR__ . '/CronogramaDatos.php';

        $crono = (new CronogramaDatos())->paraHorizonte($h, self::CONCEPTO);

        foreach ($crono['avisos_con_nivel'] as $a) {
            $avisos[] = $a;
        }

        $locales = GastosLocalesCalculo::locales($filas, $sucursales);
        $semanas = GastosLocalesCalculo::semanas($h, $crono['pagos']);
        $proy = GastosLocalesCalculo::proyectar($locales, $h, $semanas, $inflacion);

        if (!empty($proy['faltan_inflacion'])) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING,
                Inflacion::avisoFaltan($proy['faltan_inflacion'], $h->hoy()));
        }

        return [
            'leido' => $leido,
            'ventana' => $ventana,
            'locales' => $proy['locales'],
            'partidas' => $proy['partidas'],
            'semanas' => $semanas,
            'resumen' => GastosLocalesCalculo::resumen($proy['locales']),
            'mes_base' => $proy['mes_base'],
            'cuentas_excluidas' => $cuentas,
            'cronograma_config' => $crono['config'],
            'faltan_inflacion' => $proy['faltan_inflacion'],
            'avisos' => Aviso::textos($avisos),
            'avisos_con_nivel' => $avisos
        ];
    }

    /* ====================================================================
       LOS EGRESOS DE CAJA
       ==================================================================== */

    /**
     * El filtro comun a las dos consultas. UNA SOLA VEZ: si la consulta del
     * total y la del detalle filtraran distinto, el detalle de un local no
     * sumaria lo que dice su fila.
     *
     * @param array $excluidas Lista de codigos de cuenta
     * @return array [string $sql, array $params]
     */
    private function filtro($excluidas) {
        $p = $this->prefijoLocales();

        $sql = "FROM {$p}CTA29 A
                INNER JOIN {$p}CTA28 B ON A.NRO_SUCURS = B.NRO_SUCURS AND A.N_COMP = B.N_COMP
                                      AND A.COD_COMP = B.COD_COMP
                INNER JOIN {$p}CTA_CUENTA_TESORERIA C ON A.COD_CTA = C.COD_CTA_CUENTA_TESORERIA
                INNER JOIN {$p}SUCURSALES_LAKERS D ON A.NRO_SUCURS = D.NRO_SUCURSAL
                WHERE A.FECHA >= DATEADD(day, -" . GastosLocalesCalculo::DIAS_VENTANA
                    . ", CAST(GETDATE() AS DATE))
                  AND A.FECHA < CAST(GETDATE() AS DATE)
                  AND A.NRO_SUCURS <> 1
                  AND A.D_H = 'D'
                  AND CAST(CAST(A.COD_CTA AS BIGINT) AS VARCHAR(20)) LIKE '5%'
                  AND D.CANAL = 'PROPIOS' AND D.HABILITADO = 1";
        $params = [];

        if (!empty($excluidas)) {
            $sql .= " AND CAST(A.COD_CTA AS BIGINT) NOT IN ("
                . implode(', ', array_fill(0, count($excluidas), '?')) . ")";

            foreach ($excluidas as $c) {
                $params[] = intval($c);
            }
        }

        return [$sql, $params];
    }

    /**
     * Los egresos de la ventana, agrupados por local y cuenta.
     *
     * @param array $excluidas
     * @return array ['filas' => [...], 'ventana' => ['desde', 'hasta']]
     */
    public function gastos($excluidas) {
        $cid = $this->conectar();
        list($desde, $params) = $this->filtro($excluidas);

        $sql = "SELECT A.NRO_SUCURS, MAX(D.DESC_SUCURSAL) AS DESC_SUCURSAL,
                       CAST(CAST(A.COD_CTA AS BIGINT) AS VARCHAR(20)) AS COD_CTA,
                       MAX(C.DESC_CTA_CUENTA_TESORERIA) AS DESC_CUENTA,
                       COUNT(*) AS MOVIMIENTOS, SUM(CAST(A.MONTO AS FLOAT)) AS TOTAL
                " . $desde . "
                GROUP BY A.NRO_SUCURS, CAST(CAST(A.COD_CTA AS BIGINT) AS VARCHAR(20))
                ORDER BY A.NRO_SUCURS";

        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer CTA29'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = $row;
        }

        sqlsrv_free_stmt($stmt);

        return ['filas' => $filas, 'ventana' => $this->ventana($cid)];
    }

    /**
     * Desde y hasta que dia va la ventana, segun el servidor. Es lo que dice la
     * pantalla: la ventana la decide la base, y mostrar una calculada en PHP
     * podria diferir en el dia si los relojes no coinciden.
     *
     * @return array ['desde' => 'Y-m-d', 'hasta' => 'Y-m-d']
     */
    private function ventana($cid) {
        $stmt = sqlsrv_query($cid,
            "SELECT CONVERT(VARCHAR(10), DATEADD(day, -" . GastosLocalesCalculo::DIAS_VENTANA
                . ", CAST(GETDATE() AS DATE)), 23) AS DESDE,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, CAST(GETDATE() AS DATE)), 23) AS HASTA");

        if ($stmt === false) {
            return ['desde' => null, 'hasta' => null];
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return ['desde' => $row['DESDE'], 'hasta' => $row['HASTA']];
    }

    /**
     * Los comprobantes de un local en la ventana, para el detalle.
     *
     * LA LEYENDA, SI EL RENGLON NO LA TIENE, ES LA DE OTRO RENGLON DEL MISMO
     * COMPROBANTE: es la regla de la consulta original. Va con OUTER APPLY y no
     * con una subconsulta por columna, y solo aca: el total no la necesita.
     *
     * @param int $nro Sucursal
     * @return array Lista de ['fecha', 'cod_comp', 'n_comp', 'cod_cta', 'cuenta',
     *               'monto', 'situacion', 'usuario', 'leyenda']
     */
    public function comprobantes($nro) {
        $n = filter_var($nro, FILTER_VALIDATE_INT);

        if ($n === false || $n <= 1) {
            throw new Exception("Sucursal inválida: '$nro'.");
        }

        $cuentas = $this->cuentasExcluidas();
        $cid = $this->conectar();
        $p = $this->prefijoLocales();
        list($desde, $params) = $this->filtro($cuentas['cuentas']);

        $sql = "SELECT CAST(A.FECHA AS DATE) AS FECHA, A.COD_COMP, A.N_COMP,
                       CAST(CAST(A.COD_CTA AS BIGINT) AS VARCHAR(20)) AS COD_CTA,
                       C.DESC_CTA_CUENTA_TESORERIA AS DESC_CUENTA,
                       CAST(A.MONTO AS FLOAT) AS MONTO, B.SITUACION, UPPER(B.USUARIO) AS USUARIO,
                       ISNULL(NULLIF(A.LEYENDA, ''), L.LEYENDA) AS LEYENDA
                " . str_replace("INNER JOIN {$p}SUCURSALES_LAKERS D ON A.NRO_SUCURS = D.NRO_SUCURSAL",
                    "INNER JOIN {$p}SUCURSALES_LAKERS D ON A.NRO_SUCURS = D.NRO_SUCURSAL
                OUTER APPLY (SELECT TOP 1 X.LEYENDA FROM {$p}CTA29 X
                             WHERE X.N_COMP = A.N_COMP AND X.COD_COMP = A.COD_COMP
                               AND X.NRO_SUCURS = A.NRO_SUCURS AND ISNULL(X.LEYENDA, '') <> '') L",
                    $desde) . "
                  AND A.NRO_SUCURS = ?
                ORDER BY A.FECHA DESC, A.N_COMP";

        $params[] = $n;
        $stmt = sqlsrv_query($cid, $sql, $params);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los comprobantes del local'));
        }

        $v = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $v[] = [
                'fecha' => ($row['FECHA'] instanceof DateTime)
                    ? $row['FECHA']->format('Y-m-d') : substr((string) $row['FECHA'], 0, 10),
                'cod_comp' => trim((string) $row['COD_COMP']),
                'n_comp' => trim((string) $row['N_COMP']),
                'cod_cta' => trim((string) $row['COD_CTA']),
                'cuenta' => trim((string) $row['DESC_CUENTA']),
                'monto' => floatval($row['MONTO']),
                'situacion' => trim((string) $row['SITUACION']),
                'usuario' => trim((string) $row['USUARIO']),
                'leyenda' => trim((string) $row['LEYENDA'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $v;
    }

    /* ====================================================================
       LAS CUENTAS EXCLUIDAS
       ==================================================================== */

    /**
     * La lista de cuentas que no entran en el promedio.
     *
     * SIN EL PARAMETRO VA EL DEFECTO, CON AVISO: sin lista, la 522400 entraria
     * al promedio y el egreso proyectado seria otro. Con el defecto la pantalla
     * dice lo mismo que va a decir el dia que se corra el script.
     *
     * @return array ['cuentas' => [...], 'texto', 'defecto' => bool, 'aviso',
     *                'usuario', 'fecha_modif', 'creado' => bool]
     */
    public function cuentasExcluidas() {
        if ($this->cuentas !== null) {
            return $this->cuentas;
        }

        $defecto = ['cuentas' => self::CUENTAS_DEFECTO, 'texto' => implode(', ', self::CUENTAS_DEFECTO),
                    'defecto' => true, 'usuario' => null, 'fecha_modif' => null, 'creado' => false,
                    'aviso' => 'Falta el parámetro de cuentas excluidas de Gastos Locales: corré '
                        . self::SCRIPT . ' contra la base central. Mientras tanto se excluye la '
                        . implode(', ', self::CUENTAS_DEFECTO) . ', que es el valor por defecto.'];

        try {
            $stmt = sqlsrv_query($this->conectarCentral(),
                "SELECT VALOR, USUARIO_MODIF, FECHA_MODIF FROM dbo.RO_T_CASHFLOW_PARAMETROS
                 WHERE CLAVE = ?", [self::PARAM_CUENTAS]);

            if ($stmt === false) {
                return $this->cuentas = $defecto;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
        } catch (Throwable $e) {
            return $this->cuentas = $defecto;
        }

        if (!$row) {
            return $this->cuentas = $defecto;
        }

        try {
            $cuentas = self::validarCuentas($row['VALOR']);
        } catch (Exception $e) {
            $defecto['creado'] = true;
            $defecto['aviso'] = 'La lista de cuentas excluidas de Gastos Locales tiene un valor que '
                . 'no se entiende (' . $e->getMessage() . '). Se excluye la '
                . implode(', ', self::CUENTAS_DEFECTO) . '. Corregila en Parámetros › Generales.';

            return $this->cuentas = $defecto;
        }

        return $this->cuentas = [
            'cuentas' => $cuentas,
            'texto' => implode(', ', $cuentas),
            'defecto' => false,
            'aviso' => null,
            'usuario' => $row['USUARIO_MODIF'],
            'fecha_modif' => ($row['FECHA_MODIF'] instanceof DateTime)
                ? $row['FECHA_MODIF']->format('Y-m-d H:i:s') : $row['FECHA_MODIF'],
            'creado' => true
        ];
    }

    /**
     * Valida y normaliza una lista de cuentas: codigos numericos, separados por
     * coma, espacio o punto y coma. Sin repetidos y en orden.
     *
     * UNA LISTA VACIA VALE: significa que no se excluye ninguna, y es una
     * decision que alguien puede tomar.
     *
     * LA QUE VALE ES ESTA, no la de la pantalla.
     *
     * @param mixed $texto
     * @return array Lista de codigos como texto
     */
    public static function validarCuentas($texto) {
        $partes = preg_split('/[\s,;]+/', trim((string) $texto), -1, PREG_SPLIT_NO_EMPTY);
        $v = [];

        foreach ($partes as $c) {
            if (!preg_match('/^\d{1,12}$/', $c)) {
                throw new Exception("'$c' no es un código de cuenta: van sólo números, separados por coma.");
            }

            $c = ltrim($c, '0');

            if ($c === '') {
                throw new Exception('La cuenta 0 no existe.');
            }

            $v[$c] = true;
        }

        $v = array_keys($v);
        sort($v, SORT_STRING);

        return array_map('strval', $v);
    }

    /**
     * Guarda la lista de cuentas excluidas.
     *
     * @param mixed $texto
     * @param string $usuario
     * @return array ['cuentas', 'texto']
     */
    public function guardarCuentas($texto, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $cuentas = self::validarCuentas($texto);
        $valor = implode(', ', $cuentas);

        $stmt = sqlsrv_query($this->conectarCentral(),
            "UPDATE dbo.RO_T_CASHFLOW_PARAMETROS SET VALOR = ?, " . Auditoria::SET_MODIF . "
             WHERE CLAVE = ?", [$valor, $usuario, self::PARAM_CUENTAS]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar las cuentas excluidas'));
        }

        $filas = sqlsrv_rows_affected($stmt);
        sqlsrv_free_stmt($stmt);

        if (intval($filas) === 0) {
            throw new Exception('Todavía no existe el parámetro de cuentas excluidas: corré '
                . self::SCRIPT . ' contra la base central.');
        }

        $this->cuentas = null;

        return ['cuentas' => $cuentas, 'texto' => $valor];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** Prefijo de las tablas del servidor de locales. Ver Saldos::prefijoLocales() */
    private function prefijoLocales() {
        return method_exists($this->conn, 'prefijoLocales') ? $this->conn->prefijoLocales() : '';
    }

    /** @return resource Conexion a 'locales' */
    private function conectar() {
        $cid = $this->conn->conectar('locales');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de los locales');
        }

        return $cid;
    }

    /** @return resource Conexion a 'central' */
    private function conectarCentral() {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos central');
        }

        return $cid;
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
