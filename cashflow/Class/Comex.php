<?php

require_once __DIR__ . '/DolarFuturo.php';

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
 * EL CODIGO NO ASUME QUE EL DDL SE CORRIO
 * ---------------------------------------
 * COTIZ_USD_EDIT -el override por contenedor- es de un script posterior, asi
 * que su existencia se pregunta con COL_LENGTH y la pantalla sigue funcionando
 * sin el: se valua con la curva y lo unico que no se puede es corregir una fila
 * a mano. Mismo patron que ProveedoresCategorias::tieneOrigen().
 */
class Comex {

    /** Tabla propia del modulo: guarda las fechas editadas y el override de cotizacion */
    const TABLA_EDIT = 'RO_T_CASHFLOW_COMEX_CRONO_NAC';

    /** @var bool|null Cache de si la tabla ya tiene la columna COTIZ_USD_EDIT */
    private $cotizEdit = null;

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

    /**
     * Obtiene los datos de proveedores del exterior
     * Incluye lógica de FECHA_PAGO_EDIT vs FECHA_PAGO_ORIG
     *
     * Cada fila vuelve con su valuacion en pesos resuelta -IMPORTE_ARS- y con
     * QUE DOLAR se le aplico: el simbolo de la curva, el mes, la cotizacion y
     * por que es esa y no otra. Las cuatro cosas viajan juntas porque un
     * importe en pesos que no se puede atar a una cotizacion identificada no se
     * puede auditar contra nada, que es el mismo criterio de
     * Cotizacion::ultimaHasta().
     *
     * @return array Listado de importaciones pendientes
     */
    public function getProveedoresExterior(){
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

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
                    D.FECHA_PAGO_EDIT,
                    " . $cotizSql . " AS COTIZ_USD_EDIT
                FROM RO_T_IMPORTACIONES_ENCABEZADO A
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG
                LEFT JOIN RO_T_CASHFLOW_COMEX_CRONO_NAC D ON A.ID = D.ID_MG
                WHERE B.ID_MG IS NULL
                AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
                ORDER BY COALESCE(D.FECHA_PAGO_EDIT, A.FECHA_EST_PAGO, A.FECHA_ARR, ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB))";

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

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Convertir objetos DateTime a strings
            if (isset($row['ETD']) && $row['ETD'] instanceof DateTime) {
                $row['ETD'] = $row['ETD']->format('Y-m-d');
            }
            if (isset($row['ETA']) && $row['ETA'] instanceof DateTime) {
                $row['ETA'] = $row['ETA']->format('Y-m-d');
            }
            if (isset($row['FECHA_EST_PAGO']) && $row['FECHA_EST_PAGO'] instanceof DateTime) {
                $row['FECHA_EST_PAGO'] = $row['FECHA_EST_PAGO']->format('Y-m-d');
            }
            if (isset($row['FECHA_PAGO_EDIT']) && $row['FECHA_PAGO_EDIT'] instanceof DateTime) {
                $row['FECHA_PAGO_EDIT'] = $row['FECHA_PAGO_EDIT']->format('Y-m-d');
            }

            // Determinar la fecha efectiva a utilizar para el cronograma
            $row['FECHA_PAGO_EFECTIVA'] = $row['FECHA_PAGO_EDIT'] ?? $row['FECHA_EST_PAGO'];

            $v[] = self::valuar($row, $curva);
        }

        sqlsrv_free_stmt($stmt);

        return $v;
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
     * Actualiza la fecha de pago estimada editada (Proveedores Exterior)
     *
     * SI CAMBIA EL MES DE PAGO, EL OVERRIDE DE COTIZACION SE DESCARTA
     * ---------------------------------------------------------------
     * Un override es una afirmacion sobre UN MES: "este pago de noviembre se
     * valua a tanto". Si el pago se corre a febrero, esa afirmacion ya no dice
     * nada sobre esta fila, y conservarla valuaria febrero con un numero que
     * alguien penso para noviembre sin que la pantalla lo indique. La curva del
     * mes nuevo es el dato que si corresponde.
     *
     * VA EN LA MISMA TRANSACCION QUE EL UPDATE DE LA FECHA. Si fueran dos
     * escrituras sueltas y fallara la segunda, la fila quedaria con la fecha
     * nueva y la cotizacion vieja, que es exactamente el estado que esta regla
     * existe para evitar.
     *
     * Y SE DEVUELVE, para que el front lo avise. Un descarte silencioso hace
     * que el usuario vea cambiar un importe que el no toco y no tenga donde
     * enterarse de por que.
     *
     * @param int $idMg ID del maestro de importación
     * @param string $fechaPagoOrig Fecha original de pago
     * @param string $fechaPagoEdit Fecha editada de pago
     * @return array ['cotizacion_descartada', 'cotizacion_anterior', 'mes_anterior', 'mes_nuevo']
     */
    public function updateFechaPago($idMg, $fechaPagoOrig, $fechaPagoEdit) {
        $cid = $this->conn->conectar('central');

        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        $tieneCotiz = $this->tieneCotizEdit();

        // Verificar si ya existe un registro. Se traen tambien la fecha y la
        // cotizacion vigentes: son las que deciden si el override sobrevive.
        $sqlCheck = "SELECT ID, FECHA_PAGO_EDIT, "
                  . ($tieneCotiz ? 'COTIZ_USD_EDIT' : 'CAST(NULL AS DECIMAL(12,4))')
                  . " AS COTIZ_USD_EDIT
                    FROM " . self::TABLA_EDIT . " WHERE ID_MG = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$idMg]);

        if ($stmtCheck === false) {
            throw new Exception('Error al verificar registro existente');
        }

        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);

        $r = self::descartaCotizacion(
            $exists ? ($exists['FECHA_PAGO_EDIT'] ?? $fechaPagoOrig) : $fechaPagoOrig,
            $fechaPagoEdit,
            $exists ? $exists['COTIZ_USD_EDIT'] : null
        );

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception('No se pudo abrir la transacción para guardar la fecha');
        }

        try {
            if ($exists) {
                /* El SET del override solo se arma si la columna existe: sin el
                   script corrido, la instalacion guarda la fecha igual. */
                $setCotiz = ($tieneCotiz && $r['cotizacion_descartada'])
                    ? ', COTIZ_USD_EDIT = NULL'
                    : '';

                $sqlUpdate = "UPDATE " . self::TABLA_EDIT . "
                             SET FECHA_PAGO_ORIG = ?,
                                 FECHA_PAGO_EDIT = ?,
                                 FECHA_UPDATE = GETDATE()" . $setCotiz . "
                             WHERE ID_MG = ?";
                $params = [$fechaPagoOrig, $fechaPagoEdit, $idMg];
                $stmt = sqlsrv_query($cid, $sqlUpdate, $params);
            } else {
                /* Insertar nuevo registro (con valores por defecto para NAC).
                   COTIZ_USD_EDIT no entra en la lista de columnas: una fila
                   nueva no tiene override, y NULL es justamente eso. */
                $sqlInsert = "INSERT INTO " . self::TABLA_EDIT . "
                             (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, FECHA_PAGO_ORIG, FECHA_PAGO_EDIT, FECHA_UPDATE)
                             VALUES (?, '1900-01-01', '1900-01-01', ?, ?, GETDATE())";
                $params = [$idMg, $fechaPagoOrig, $fechaPagoEdit];
                $stmt = sqlsrv_query($cid, $sqlInsert, $params);
            }

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al guardar la fecha'));
            }

            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($cid);
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        return $r;
    }

    /**
     * Si al mover la fecha de pago hay que descartar el override de cotizacion.
     *
     * Estatica y pura: es la regla, y se prueba sin base. Ver la nota de
     * updateFechaPago() sobre por que un override no sobrevive a un cambio de
     * mes.
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
            /* Mismos centinelas que usa updateFechaPago() al insertar: esta
               tabla sirve a las dos pestanas y una fila nueva no puede dejar
               las fechas de la otra en NULL. */
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
     * Obtiene los datos del cronograma de nacionalización
     * Aplica lógica de FECHA_NAC_EDIT vs FECHA_NAC_ORIG
     * @return array Listado de importaciones con fechas de nacionalización
     */
    public function getCronoNacionalizacion() {
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Query base
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
                    D.FECHA_NAC_EDIT
                FROM RO_T_IMPORTACIONES_ENCABEZADO A 
                LEFT JOIN RO_T_IMPORTACIONES_DETALLE B ON A.ID = B.ID_MG 
                LEFT JOIN
                (
                    SELECT ID_MG, SUM(IMPORTE) IMPORTE_EST 
                    FROM RO_T_IMPORTACIONES_ESTIMACION_DETALLE 
                    WHERE ID_CE BETWEEN 3 AND 10
                    GROUP BY ID_MG  
                ) C ON A.ID = C.ID_MG
                LEFT JOIN RO_T_CASHFLOW_COMEX_CRONO_NAC D ON A.ID = D.ID_MG
                WHERE B.ID_MG IS NULL 
                AND ISNULL(A.FECHA_EMB, A.FECHA_EST_EMB) >= CAST(GETDATE() AS DATE)
                ORDER BY COALESCE(D.FECHA_NAC_EDIT, A.FECHA_DESP_ADU, A.FECHA_ARR, A.FECHA_EMB, A.FECHA_EST_EMB)";

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
            // Convertir objetos DateTime a strings
            if (isset($row['FECHA_EST_EMB']) && $row['FECHA_EST_EMB'] instanceof DateTime) {
                $row['FECHA_EST_EMB'] = $row['FECHA_EST_EMB']->format('Y-m-d');
            }
            if (isset($row['ETD']) && $row['ETD'] instanceof DateTime) {
                $row['ETD'] = $row['ETD']->format('Y-m-d');
            }
            if (isset($row['ETA']) && $row['ETA'] instanceof DateTime) {
                $row['ETA'] = $row['ETA']->format('Y-m-d');
            }
            if (isset($row['FECHA_NAC']) && $row['FECHA_NAC'] instanceof DateTime) {
                $row['FECHA_NAC'] = $row['FECHA_NAC']->format('Y-m-d');
            }
            if (isset($row['FECHA_NAC_EDIT']) && $row['FECHA_NAC_EDIT'] instanceof DateTime) {
                $row['FECHA_NAC_EDIT'] = $row['FECHA_NAC_EDIT']->format('Y-m-d');
            }
            
            // Determinar la fecha efectiva a utilizar para el cronograma
            $row['FECHA_NAC_EFECTIVA'] = $row['FECHA_NAC_EDIT'] ?? $row['FECHA_NAC'];
            
            $v[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        
        return $v;
    }

    /**
     * Actualiza la fecha de nacionalización editada
     * @param int $idMg ID del maestro de importación
     * @param string $fechaNacOrig Fecha original de nacionalización
     * @param string $fechaNacEdit Fecha editada de nacionalización
     * @return bool True si se actualizó correctamente
     */
    public function updateFechaNacPago($idMg, $fechaNacOrig, $fechaNacEdit) {
        $cid = $this->conn->conectar('central');
        
        if (!$cid) {
            throw new Exception('No se pudo conectar a la base de datos');
        }

        // Verificar si ya existe un registro
        $sqlCheck = "SELECT ID FROM RO_T_CASHFLOW_COMEX_CRONO_NAC WHERE ID_MG = ?";
        $stmtCheck = sqlsrv_query($cid, $sqlCheck, [$idMg]);
        
        if ($stmtCheck === false) {
            throw new Exception('Error al verificar registro existente');
        }
        
        $exists = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtCheck);
        
        if ($exists) {
            // Actualizar registro existente
            $sqlUpdate = "UPDATE RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         SET FECHA_NAC_ORIG = ?, 
                             FECHA_NAC_EDIT = ?, 
                             FECHA_UPDATE = GETDATE()
                         WHERE ID_MG = ?";
            $params = [$fechaNacOrig, $fechaNacEdit, $idMg];
            $stmt = sqlsrv_query($cid, $sqlUpdate, $params);
        } else {
            // Insertar nuevo registro
            $sqlInsert = "INSERT INTO RO_T_CASHFLOW_COMEX_CRONO_NAC 
                         (ID_MG, FECHA_NAC_ORIG, FECHA_NAC_EDIT, FECHA_UPDATE)
                         VALUES (?, ?, ?, GETDATE())";
            $params = [$idMg, $fechaNacOrig, $fechaNacEdit];
            $stmt = sqlsrv_query($cid, $sqlInsert, $params);
        }
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errorMsg = 'Error al guardar la fecha: ';
            if ($errors) {
                foreach ($errors as $error) {
                    $errorMsg .= $error['message'] . ' ';
                }
            }
            throw new Exception($errorMsg);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }

    /**
     * Arma el mensaje de error a partir de sqlsrv_errors().
     *
     * Nombra la tabla, igual que el resto del modulo: el aviso es lo unico que
     * le dice a alguien contra que objeto fallo la escritura.
     *
     * @param string $contexto
     * @return string
     */
    private function errorSql($contexto) {
        $errores = sqlsrv_errors();
        $msg = $contexto . ' (' . self::TABLA_EDIT . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }

}
