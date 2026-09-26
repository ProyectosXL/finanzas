<?php

require_once __DIR__ . '/TarjetasSupervisoras.php';

/**
 * GastosSupervision
 * La parte de Gastos Supervisoras que toca la base: los gastos autorizados y el
 * maestro de supervisoras.
 *
 * POR QUE ESTA SEPARADA DE TarjetasSupervisoras
 * --------------------------------------------
 * Mismo criterio que CronogramaPagos / CronogramaDatos y que ComprasProyectadas /
 * ComprasProyectadasDatos: la regla -la ventana, el promedio, la proporcion, el
 * reparto- es lo delicado y se prueba sin base. Lo que hay aca son dos consultas,
 * que sin base no se pueden probar y que tampoco tienen nada que decidir.
 *
 * ES CONSISTENTE CON EL DASHBOARD DE SUPERVISION, A PROPOSITO
 * ----------------------------------------------------------
 * El mismo dato lo muestra el dashboard de presupuesto de supervision
 * (repo ProyectosXL/comercial, supervision/presupuesto/Class/Dashboard.php), y los
 * dos numeros tienen que poder compararse. Por eso se copian sus tres criterios:
 *
 *   ESTADO = 1                      solo los AUTORIZADOS
 *   MONTH/YEAR(FECHA_MODIF)         el mes del gasto sale de ahi, NO de la semana
 *   IMPORTE + IMPORTE_TARJETA       el efectivo y la tarjeta, sumados
 *
 * EL MES SALE DE FECHA_MODIF Y NO DE LA SEMANA, y esa es la que se hace mal: la
 * tabla tiene SEMANA y ANIO, que son lo que se tipea al cargar el gasto, pero el
 * dashboard agrupa por FECHA_MODIF. Una semana puede caer en dos meses, asi que
 * los dos criterios dan distinto y solo uno coincide con el dashboard.
 *
 * SOLO LOS AUTORIZADOS
 * --------------------
 * ESTADO = 1 es autorizado. Lo que no esta autorizado todavia no es un gasto que
 * vaya a salir de caja: es un pedido. ESTADO es un BIT, asi que el unico otro
 * valor posible es NULL -pendiente-, y hoy hay una sola fila asi.
 *
 * EL PRESUPUESTO Y LOS ADELANTOS NO SE LEEN
 * -----------------------------------------
 * RO_T_PRESUPUESTOS_SUPERVISION dice cuanto se AUTORIZO a gastar, y el cashflow
 * necesita cuanto se VA A GASTAR: las dos cosas difieren y la que predice la caja
 * es la segunda. FU_T_ADELANTOS_SUPERVISION es un circuito que ya no se usa.
 *
 * EL CRUCE CON EL MAESTRO ES POR NOMBRE
 * -------------------------------------
 * RO_T_GASTOS_SUPERVISION.SUPERVISORA guarda el NOMBRE, no un ID, asi que se cruza
 * por nombre contra RO_T_SUPERVISORAS_COMERCIAL igual que hace el dashboard. No es
 * lo ideal -un nombre tipeado distinto no matchea- pero es el dato que hay, y
 * cambiarlo es del otro modulo.
 *
 * LA COLLATION NO ES UN DETALLE. La columna de gastos es Modern_Spanish_CI_AI y la
 * del maestro puede no serlo, ademas de que el maestro vive en otro servidor
 * ([XL-LAKERBIS]). El cruce declara la collation explicitamente; sin eso, SQL
 * Server rechaza la comparacion entre dos collations distintas y la consulta
 * falla entera.
 *
 * EL MAESTRO ESTA EN OTRO SERVIDOR, Y SI NO RESPONDE SE SIGUE
 * ----------------------------------------------------------
 * RO_T_SUPERVISORAS_COMERCIAL se alcanza por linked server. Si no responde, los
 * gastos se leen igual y NINGUNA supervisora proyecta -no se puede saber quien
 * sigue trabajando- con un aviso que lo dice. Es el mismo criterio de
 * CronogramaDatos con el calendario: peor pero explicado, en vez de una pantalla
 * en blanco.
 */
class GastosSupervision {

    /** Los gastos, en la base 'central' */
    const TABLA = 'RO_T_GASTOS_SUPERVISION';

    /**
     * El maestro de supervisoras, en el servidor de locales.
     *
     * VA CON EL NOMBRE DE CUATRO PARTES y no con el prefijo de
     * Conexion::prefijoLocales(), que apunta a locales_lakers en minuscula y es
     * para las tablas de caja de los locales. Esta es la misma ruta que usa el
     * dashboard de supervision, que es con quien hay que coincidir.
     */
    const TABLA_MAESTRO = '[XL-LAKERBIS].LOCALES_LAKERS.dbo.RO_T_SUPERVISORAS_COMERCIAL';

    /** La collation con la que se comparan los nombres. Ver el encabezado */
    const COLLATION = 'Modern_Spanish_CI_AI';

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla de gastos */
    private $tabla = null;

    /** @var array|null Cache del maestro */
    private $maestro = null;

    /** @var array Avisos acumulados */
    private $avisos = [];

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LA RESOLUCION COMPLETA
       ==================================================================== */

    /**
     * Todo lo que Gastos Supervisoras necesita de la base, para una ventana.
     *
     * NO LANZA POR EL MAESTRO: puede fallar y los gastos se leen igual, con el
     * motivo en los avisos. Lo que si lanza es que no se puedan leer los gastos,
     * que es el dato sin el cual no hay nada que mostrar.
     *
     * @param array $ventana Lo que devolvio TarjetasSupervisoras::ventana()
     * @return array ['gastos' => [...], 'estados' => [...], 'avisos' => [...],
     *                'tabla_creada' => bool, 'maestro_disponible' => bool]
     */
    public function paraVentana($ventana) {
        $this->avisos = [];

        if (!$this->tablaCreada()) {
            $this->avisos[] = $this->avisoSinTabla();

            return ['gastos' => [], 'estados' => [], 'avisos' => $this->avisos,
                    'tabla_creada' => false, 'maestro_disponible' => false];
        }

        $gastos = $this->gastos($ventana['desde'], $ventana['hasta']);
        $maestro = $this->maestro();
        $disponible = ($maestro !== null);

        return [
            'gastos' => $gastos,
            'estados' => $this->estados($gastos, $maestro),
            'avisos' => $this->avisos,
            'tabla_creada' => true,
            'maestro_disponible' => $disponible
        ];
    }

    /** @return array Avisos de la ultima resolucion */
    public function avisos() {
        return $this->avisos;
    }

    /* ====================================================================
       LOS GASTOS
       ==================================================================== */

    /** @return bool Si la tabla de gastos existe */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA . "', 'U') AS T");

            if ($stmt === false) {
                return $this->tabla = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    /** @return string El aviso de tabla faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'No existe dbo.' . self::TABLA . ', que es de donde salen los gastos de supervisión. '
            . 'Esa tabla no la crea este repo: la mantiene el módulo de supervisión. Sin ella '
            . 'Gastos Supervisoras no puede estimar nada y la fila del tablero va en cero.';
    }

    /**
     * Los gastos AUTORIZADOS entre dos fechas, agrupados por supervisora y mes.
     *
     * SE AGRUPA EN SQL y no en PHP: son 1.371 filas en toda la historia, pero la
     * agrupacion por mes usa MONTH/YEAR(FECHA_MODIF), que es exactamente lo que
     * hace el dashboard, y hacerla aca es lo que garantiza que los dos numeros
     * coincidan. Devolver las filas sueltas y agrupar en PHP abriria la puerta a
     * agrupar por otro criterio.
     *
     * EL FILTRO POR FECHA VA SOBRE CAST(FECHA_MODIF AS DATE), igual que el
     * dashboard: FECHA_MODIF es un DATETIME y sin el CAST el ultimo dia del rango
     * se cortaria a la medianoche, perdiendo los gastos cargados ese dia.
     *
     * @param string $desde 'Y-m-d'
     * @param string $hasta 'Y-m-d'
     * @return array Filas con 'supervisora', 'mes', 'efectivo', 'tarjeta', 'filas'
     */
    public function gastos($desde, $hasta) {
        $sql = "SELECT LTRIM(RTRIM(g.SUPERVISORA)) AS SUPERVISORA,
                       YEAR(g.FECHA_MODIF)  AS ANIO,
                       MONTH(g.FECHA_MODIF) AS MES,
                       SUM(CAST(ISNULL(g.IMPORTE, 0) AS FLOAT))         AS EFECTIVO,
                       SUM(CAST(ISNULL(g.IMPORTE_TARJETA, 0) AS FLOAT)) AS TARJETA,
                       COUNT(*) AS FILAS
                FROM dbo." . self::TABLA . " g
                WHERE g.ESTADO = 1
                  AND CAST(g.FECHA_MODIF AS DATE) >= ?
                  AND CAST(g.FECHA_MODIF AS DATE) <= ?
                GROUP BY LTRIM(RTRIM(g.SUPERVISORA)),
                         YEAR(g.FECHA_MODIF), MONTH(g.FECHA_MODIF)
                ORDER BY SUPERVISORA, ANIO, MES";

        $stmt = sqlsrv_query($this->conectar(), $sql, [$desde, $hasta]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los gastos de supervisión'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'supervisora' => trim((string) $row['SUPERVISORA']),
                'mes' => sprintf('%04d-%02d', intval($row['ANIO']), intval($row['MES'])),
                'efectivo' => round(floatval($row['EFECTIVO']), 2),
                'tarjeta' => round(floatval($row['TARJETA']), 2),
                'filas' => intval($row['FILAS'])
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /* ====================================================================
       EL MAESTRO DE SUPERVISORAS
       ==================================================================== */

    /**
     * El maestro de supervisoras, indexado por nombre en mayusculas.
     *
     * DEVUELVE null -Y NO UN ARRAY VACIO- SI NO SE PUDO LEER, y la diferencia
     * importa: vacio significa "no hay ninguna supervisora cargada" y null
     * significa "no se sabe". Con vacio, ninguna proyectaria y el aviso diria que
     * todas estan de baja, que es una afirmacion falsa.
     *
     * @return array|null Mapa NOMBRE => ['id', 'nombre', 'activa']
     */
    public function maestro() {
        if ($this->maestro !== null) {
            return ($this->maestro === false) ? null : $this->maestro;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT ID, LTRIM(RTRIM(NOMBRE)) AS NOMBRE, ACTIVA
                 FROM " . self::TABLA_MAESTRO . "
                 ORDER BY NOMBRE");

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al leer el maestro de supervisoras'));
            }

            $mapa = [];

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $nombre = trim((string) $row['NOMBRE']);

                $mapa[self::clave($nombre)] = [
                    'id' => intval($row['ID']),
                    'nombre' => $nombre,
                    'activa' => (intval($row['ACTIVA']) === 1)
                ];
            }

            sqlsrv_free_stmt($stmt);
            $this->maestro = $mapa;

            return $this->maestro;
        } catch (Throwable $e) {
            /* EL MAESTRO VIVE EN OTRO SERVIDOR. Si no responde, los gastos se leen
               igual y ninguna supervisora proyecta, porque no se puede saber quien
               sigue trabajando. Un aviso que lo dice es mejor que una pantalla en
               blanco. */
            $this->avisos[] = 'No se pudo leer el maestro de supervisoras ('
                . self::TABLA_MAESTRO . '): ' . $e->getMessage() . '. Los gastos se ven igual, '
                . 'pero NINGUNA supervisora se proyecta: sin el maestro no hay forma de saber '
                . 'quién sigue trabajando, y proyectar a alguien que dejó la empresa pondría en '
                . 'el tablero un egreso que no va a salir.';

            $this->maestro = false;

            return null;
        }
    }

    /**
     * El estado de cada supervisora que aparece en los gastos.
     *
     * DEVUELVE UNA ENTRADA POR CADA NOMBRE DE LOS GASTOS, tambien los que no estan
     * en el maestro: TarjetasSupervisoras::motivoDe() necesita poder distinguir
     * "no esta en el maestro" de "no vino en este mapa", y sin la entrada las dos
     * cosas se leerian igual.
     *
     * CON EL MAESTRO CAIDO, NINGUNA PROYECTA. Ver maestro().
     *
     * @param array $gastos Lo que devolvio gastos()
     * @param array|null $maestro Lo que devolvio maestro()
     * @return array Mapa nombre => ['en_maestro' => bool, 'activa' => bool, 'id']
     */
    public function estados($gastos, $maestro) {
        $estados = [];

        foreach ($gastos as $g) {
            $nombre = $g['supervisora'];

            if (isset($estados[$nombre])) {
                continue;
            }

            if ($maestro === null) {
                $estados[$nombre] = ['en_maestro' => false, 'activa' => false, 'id' => null];
                continue;
            }

            $clave = self::clave($nombre);

            $estados[$nombre] = isset($maestro[$clave])
                ? ['en_maestro' => true, 'activa' => $maestro[$clave]['activa'],
                   'id' => $maestro[$clave]['id']]
                : ['en_maestro' => false, 'activa' => false, 'id' => null];
        }

        return $estados;
    }

    /**
     * Las supervisoras ACTIVAS que no tienen gastos en la ventana.
     *
     * NO GENERAN FILA -el universo son las que tienen gastos- pero SI un aviso con
     * su nombre: una supervisora activa que no aparece en la grilla puede ser
     * alguien que no cargo sus gastos, y eso es plata que el tablero no esta
     * proyectando. Una fila con promedio cero, en cambio, afirmaria que no gasta.
     *
     * @param array $gastos Lo que devolvio gastos()
     * @param array|null $maestro Lo que devolvio maestro()
     * @return array Lista de nombres
     */
    public function activasSinGastos($gastos, $maestro) {
        if ($maestro === null) {
            return [];
        }

        $conGastos = [];

        foreach ($gastos as $g) {
            $conGastos[self::clave($g['supervisora'])] = true;
        }

        $sin = [];

        foreach ($maestro as $clave => $s) {
            if ($s['activa'] && !isset($conGastos[$clave])) {
                $sin[] = $s['nombre'];
            }
        }

        return $sin;
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * La clave con la que se comparan dos nombres de supervisora.
     *
     * MAYUSCULAS Y SIN ESPACIOS DE MAS. La comparacion tiene que ser la misma en
     * los dos lados del cruce, y el nombre de los gastos lo tipea una persona:
     * 'Sonia Pacifico' y 'SONIA PACIFICO' son la misma. Los acentos NO se
     * normalizan aca porque la collation de las dos columnas
     * (Modern_Spanish_CI_AI) ya es acento-insensible, y hacerlo dos veces y de dos
     * formas distintas es lo que deja de coincidir.
     *
     * Estatica y pura.
     *
     * @param string $nombre
     * @return string
     */
    public static function clave($nombre) {
        return mb_strtoupper(trim((string) $nombre), 'UTF-8');
    }

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
        $msg = $contexto . ': ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
