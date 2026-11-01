<?php

require_once __DIR__ . '/Tarjetas.php';
require_once __DIR__ . '/Horizonte.php';
require_once __DIR__ . '/AuthCashflow.php';

/**
 * TarjetasResumen
 * El resumen de cada tarjeta y mes: cuanto vino, cuando vence y si ya se pago.
 *
 * QUE RESUELVE
 * ------------
 * Las tres sub-pestanas ESTIMAN. El resumen es el dato real, y cuando existe
 * MANDA: pisa la estimacion de esa tarjeta en ese mes, con su importe y con su
 * fecha.
 *
 * EN EL TABLERO NO ES UNA FILA APARTE, Y ESO ES LA DECISION
 * --------------------------------------------------------
 * Entre la fecha estimada y la del resumen hay unos cinco dias, asi que las dos
 * caen casi siempre en la misma columna. Una fila aparte sumaria un importe que
 * la fila original ya suma -doble conteo- y partir la fila en "estimado" y
 * "real" mostraria dos numeros de los que solo uno vale.
 *
 * Va en la MISMA fila, y la celda queda anotada con 'detalle' -la anotacion de
 * celda que ya existe en el contrato del proveedor- diciendo que hay un resumen
 * cargado, por cuanto y cuando vence. Asi el numero es uno y se puede saber de
 * donde sale.
 *
 * PISA EN CUALQUIER MES DEL HORIZONTE, NO SOLO EN EL ACTUAL
 * --------------------------------------------------------
 * Parado a fines de septiembre se carga el resumen que vence en octubre, y eso
 * tiene que pisar la estimacion de octubre. Por eso la clave es (tarjeta, mes) y
 * no "el ultimo resumen": un resumen no es el estado actual de la tarjeta, es el
 * dato de un mes.
 *
 * PAGADO SACA EL RESUMEN DEL HORIZONTE
 * ------------------------------------
 * Esa plata ya salio y ya esta reflejada en el saldo bancario que abre el
 * cuadro; proyectarla seria pedir dos veces la misma plata. Es el mismo criterio
 * con el que Comex saca de la proyeccion lo marcado como pagado y Logistica no
 * proyecta un pago con fecha anterior o igual a hoy.
 *
 * SE PUEDE DESMARCAR, y entonces vuelve a proyectarse. Por eso el tilde no borra
 * nada.
 *
 * DOS ORIGENES, Y LOS DOS SON HISTORIA
 * ------------------------------------
 *   CARGA      el resumen del periodo, el que se carga mes a mes
 *   HISTORICO  la carga inicial de base de Tarjetas Socios: los ultimos tres
 *              resumenes, cargados de una para poder empezar a estimar
 *
 * Los dos cuentan para la base de la estimacion de Socios: la base son los
 * ultimos tres resumenes DE CUALQUIER ORIGEN. Lo que cambia es de donde salieron,
 * y eso es lo que despues explica por que hay tres resumenes viejos cargados el
 * mismo dia. Ver Class/TarjetasSocios.php.
 *
 * SIN BAJAS FISICAS
 * -----------------
 * ACTIVO = 0 en vez de DELETE, con quien y cuando. Corregir un resumen tipeado
 * mal es un UPDATE sobre el vigente; lo que deja historia es darlo de baja, y
 * con un DELETE "nunca se cargo un resumen de ese mes" y "se cargo y se dio de
 * baja" serian indistinguibles despues del hecho.
 *
 * LA VALIDACION QUE VALE ES LA DE ACA
 * -----------------------------------
 * La pantalla acota lo que se puede tipear, pero lo que manda el navegador es un
 * pedido y no una autorizacion: el endpoint es alcanzable sin pasar por ella. Los
 * CHECK de la tabla son la tercera red.
 *
 * UN RESUMEN PARA VARIAS TARJETAS
 * -------------------------------
 * Las tarjetas adicionales de una misma cuenta -mismo banco, mismo dia de
 * vencimiento- reciben UN resumen del banco. ID_TARJETA es la TITULAR, la que lo
 * lleva al tablero en la serie de su tipo; las ADICIONALES viven en
 * RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA (sql/cashflow_tarjetas_resumen_compartido.sql).
 *
 * vigentes() devuelve el mismo resumen en cada tarjeta que incluye, con
 * ES_TITULAR. Asi PISA LA ESTIMACION DE TODAS -las facturas vinculadas, la
 * cobertura, la parte tarjeta de la supervisora- y SUMA UNA SOLA VEZ: quien
 * proyecta un resumen mira ES_TITULAR, y las otras tarjetas lo muestran como
 * informativo. Es la unica forma de que un resumen compartido no se cuente una
 * vez por tarjeta.
 */
class TarjetasResumen {

    /** La tabla, en la base 'central' */
    const TABLA = 'RO_T_CASHFLOW_TARJETAS_RESUMEN';

    /** Las tarjetas adicionales de un resumen compartido */
    const TABLA_COMPARTIDO = 'RO_T_CASHFLOW_TARJETAS_RESUMEN_TARJETA';

    /** El script que la crea */
    const SCRIPT_COMPARTIDO = 'sql/cashflow_tarjetas_resumen_compartido.sql';

    /**
     * Los tipos que pueden compartir un resumen, con la sub-pestana donde se
     * proyecta. Los socios no: van por banco y tienen su propio circuito.
     */
    const TIPOS_COMPARTIBLES = [
        'SUPERVISORA' => 'Gastos Supervisoras',
        'CORPORATIVA' => 'Tarjetas Pagos Corporativos'
    ];

    /** El resumen del periodo, cargado mes a mes */
    const CARGA = 'CARGA';

    /** La carga inicial de base de Tarjetas Socios */
    const HISTORICO = 'HISTORICO';

    /**
     * Los dos origenes, con su nombre para la pantalla.
     * TIENE QUE COINCIDIR CON EL CHECK de la tabla.
     */
    const ORIGENES = [
        self::CARGA => 'Resumen del período',
        self::HISTORICO => 'Base histórica'
    ];

    /** Cuantos resumenes forman la base de la estimacion de Socios */
    const RESUMENES_BASE = 3;

    /** Tope de la observacion, el de la columna */
    const LARGO_OBSERVACION = 500;

    /** @var Conexion */
    private $conn;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    /** @var array|null Cache de vigentes() */
    private $vigentes = null;

    /** @var bool|null Cache del chequeo de la tabla de compartidos */
    private $tablaCompartido = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LO PURO: VALIDACION
       ==================================================================== */

    /**
     * Los dos importes, validados. Devuelve los dos, con null el que no vino.
     *
     * AL MENOS UNO ES OBLIGATORIO. Un resumen sin ningun importe no es un
     * resumen: es una fila que pisa la estimacion con nada, y el mes quedaria en
     * cero por haber cargado un dato.
     *
     * NI CERO NI NEGATIVO. Un cero no se distingue de un olvido -que es
     * exactamente lo que el campo vacio significa- y un negativo convertiria un
     * EGRESO del tablero en un ingreso que nadie afirmo, en una fila cuyo TIPO es
     * EGRESO. Un saldo a favor, si algun dia hay que modelarlo, es una decision y
     * no una carga.
     *
     * Estatica y pura.
     *
     * @param mixed $ars
     * @param mixed $usd
     * @return array ['ars' => float|null, 'usd' => float|null]
     */
    public static function validarImportes($ars, $usd) {
        $r = [
            'ars' => self::validarImporte($ars, 'en pesos'),
            'usd' => self::validarImporte($usd, 'en dólares')
        ];

        if ($r['ars'] === null && $r['usd'] === null) {
            throw new Exception('El resumen tiene que traer al menos uno de los dos importes, '
                . 'en pesos o en dólares. Pueden venir los dos: la misma tarjeta tiene consumos '
                . 'en las dos monedas.');
        }

        return $r;
    }

    /**
     * Un importe suelto. null cuando no vino.
     *
     * @param mixed $valor
     * @param string $cual Para el mensaje
     * @return float|null
     */
    private static function validarImporte($valor, $cual) {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            throw new Exception('El importe ' . $cual . ' tiene que ser un número. Se recibió "'
                . $valor . '".');
        }

        $v = round(floatval($valor), 2);

        if ($v <= 0) {
            throw new Exception('El importe ' . $cual . ' tiene que ser mayor a cero. Un resumen '
                . 'en cero no se distingue de un campo que quedó vacío; si ese mes no hubo '
                . 'consumos en esa moneda, dejalo en blanco.');
        }

        return $v;
    }

    /**
     * Valida un mes 'Y-m'.
     *
     * @param mixed $mes
     * @return string
     */
    public static function validarMes($mes) {
        $m = trim((string) $mes);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new Exception("Mes inválido: '$mes'. Se esperaba el formato YYYY-MM.");
        }

        return $m;
    }

    /**
     * Valida una fecha de vencimiento 'Y-m-d'.
     *
     * NO SE CORRE AL DIA HABIL, a diferencia de la fecha de la estimacion: esta
     * la tipea alguien leyendo el resumen, asi que es un hecho y correrla seria
     * contradecirlo. Ver Class/TarjetasVencimiento.php.
     *
     * @param mixed $fecha
     * @return string 'Y-m-d'
     */
    public static function validarFecha($fecha) {
        $f = Horizonte::normalizarFecha($fecha);

        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            throw new Exception('La fecha de vencimiento del resumen no es una fecha válida.');
        }

        list($a, $m, $d) = array_map('intval', explode('-', $f));

        if (!checkdate($m, $d, $a)) {
            throw new Exception('La fecha de vencimiento del resumen no existe en el calendario.');
        }

        return $f;
    }

    /**
     * El origen, normalizado. Cualquier cosa que no sea HISTORICO es CARGA.
     *
     * CAE A CARGA Y NO A HISTORICO porque es el caso normal -el resumen del mes-
     * y porque HISTORICO tiene un efecto propio: lo marca la carga de base, que
     * ademas los da por pagados. Un valor raro que caiga ahi marcaria como pagado
     * un resumen que nadie pago.
     *
     * Estatica y pura.
     *
     * @param mixed $origen
     * @return string
     */
    public static function validarOrigen($origen) {
        return (strtoupper(trim((string) $origen)) === self::HISTORICO)
            ? self::HISTORICO : self::CARGA;
    }

    /**
     * Si la fecha de un vencimiento coincide con el mes que dice el resumen.
     *
     * NO ES UN ERROR QUE DIFIERAN y por eso esto AVISA en vez de rechazar: el
     * resumen que vence el 2 de noviembre puede ser el del periodo de octubre, y
     * quien lo carga sabe mejor que esta funcion a que periodo pertenece. Lo que
     * no puede pasar es que difieran sin que nadie lo vea, porque el MES es lo
     * que decide que estimacion se pisa.
     *
     * Estatica y pura.
     *
     * @param string $mes 'Y-m'
     * @param string $fecha 'Y-m-d'
     * @return string El aviso, o '' si coinciden
     */
    public static function avisoMesDistinto($mes, $fecha) {
        $mesFecha = substr((string) $fecha, 0, 7);

        if ($mesFecha === $mes) {
            return '';
        }

        return 'El resumen está cargado en el período ' . $mes . ' y vence el '
            . substr($fecha, 8, 2) . '/' . substr($fecha, 5, 2) . '/' . substr($fecha, 0, 4)
            . ', que es de ' . $mesFecha . '. Pisa la estimación de ' . $mes . ', que es el '
            . 'período, y sale en la columna de la fecha de vencimiento. Si querés que pise la '
            . 'de ' . $mesFecha . ', cargalo con ese período.';
    }

    /**
     * La base historica, validada entera: exactamente RESUMENES_BASE renglones,
     * cada uno con su mes, al menos un importe y su vencimiento, sin meses
     * repetidos.
     *
     * EXACTAMENTE TRES, Y LO DICE EL SERVIDOR. Con dos la tarjeta estimaria un
     * promedio distinto sin que nadie se entere; con cuatro, el cuarto pisaria
     * uno de los tres sin que la base lo use. La grilla de la pantalla tiene tres
     * renglones, pero el endpoint es alcanzable sin pasar por ella.
     *
     * Estatica y pura.
     *
     * @param array $resumenes Filas con mes, importe_ars, importe_usd,
     *                         fecha_vencimiento, observacion
     * @return array ['filas' => ['Y-m' => [...]], 'avisos' => [...]]
     */
    public static function validarBase($resumenes) {
        $lista = is_array($resumenes) ? array_values($resumenes) : [];

        if (count($lista) !== self::RESUMENES_BASE) {
            throw new Exception('La base histórica son ' . self::RESUMENES_BASE . ' resúmenes, '
                . 'uno por renglón, y llegaron ' . count($lista) . '. No se cargó ningún resumen.');
        }

        $filas = [];
        $avisos = [];

        foreach ($lista as $i => $r) {
            $n = $i + 1;

            try {
                $m = self::validarMes(isset($r['mes']) ? $r['mes'] : null);
                $importes = self::validarImportes(
                    isset($r['importe_ars']) ? $r['importe_ars'] : null,
                    isset($r['importe_usd']) ? $r['importe_usd'] : null);
                $fecha = self::validarFecha(
                    isset($r['fecha_vencimiento']) ? $r['fecha_vencimiento'] : null);
            } catch (Throwable $e) {
                throw new Exception('Renglón ' . $n . ': ' . $e->getMessage()
                    . ' No se cargó ningún resumen.');
            }

            if (isset($filas[$m])) {
                throw new Exception('El período ' . $m . ' viene dos veces. No se cargó ningún '
                    . 'resumen.');
            }

            $aviso = self::avisoMesDistinto($m, $fecha);

            if ($aviso !== '') {
                $avisos[] = 'Renglón ' . $n . ': ' . $aviso;
            }

            $filas[$m] = [
                'mes' => $m,
                'ars' => $importes['ars'],
                'usd' => $importes['usd'],
                'fecha' => $fecha,
                'obs' => isset($r['observacion']) && trim((string) $r['observacion']) !== ''
                    ? mb_substr(trim((string) $r['observacion']), 0, self::LARGO_OBSERVACION)
                    : null
            ];
        }

        return ['filas' => $filas, 'avisos' => $avisos];
    }

    /**
     * Las tarjetas de un resumen, validadas: cual es la titular y cuales las
     * adicionales.
     *
     * CON UNA SOLA TARJETA ES EL RESUMEN DE SIEMPRE, de cualquier tipo, y esa
     * tarjeta es la titular.
     *
     * CON VARIAS, SON ADICIONALES DE UNA MISMA CUENTA, y eso se verifica: MISMO
     * BANCO y MISMO DIA DE VENCIMIENTO, porque el banco manda un resumen por
     * cuenta y la cuenta vence un dia. Dos tarjetas de bancos distintos en un
     * resumen serian un error de carga que pisaria la estimacion de una tarjeta
     * con el importe de otra. Solo SUPERVISORA y CORPORATIVA, mezcladas: los
     * socios van por banco. Y todas ACTIVAS: una de baja no proyecta, y un
     * resumen titular de una tarjeta de baja no sumaria en ningun lado.
     *
     * Estatica y pura.
     *
     * @param array $ids Los ID de las tarjetas elegidas
     * @param mixed $titular El ID de la titular; con una sola tarjeta puede venir vacio
     * @param array $tarjetas Mapa ID => tarjeta, todas
     * @return array ['titular' => int, 'adicionales' => [int]]
     */
    public static function validarTarjetas($ids, $titular, $tarjetas) {
        $lista = [];

        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            $i = intval($id);

            if ($i > 0 && !in_array($i, $lista, true)) {
                $lista[] = $i;
            }
        }

        if (empty($lista)) {
            throw new Exception('Elegí al menos una tarjeta para el resumen.');
        }

        foreach ($lista as $i) {
            if (!isset($tarjetas[$i])) {
                throw new Exception('La tarjeta ' . $i . ' no está cargada. Se da de alta en '
                    . 'Parámetros › Tarjetas.');
            }
        }

        if (count($lista) === 1) {
            return ['titular' => $lista[0], 'adicionales' => []];
        }

        $t = intval($titular);

        if (!in_array($t, $lista, true)) {
            throw new Exception('Marcá cuál de las tarjetas elegidas es la titular: es la que '
                . 'lleva el resumen al tablero, una sola vez.');
        }

        $primera = $tarjetas[$t];

        foreach ($lista as $i) {
            $x = $tarjetas[$i];

            if (!isset(self::TIPOS_COMPARTIBLES[$x['TIPO']])) {
                throw new Exception($x['ROTULO'] . ' es de tipo ' . $x['TIPO_NOMBRE'] . ': un resumen '
                    . 'compartido sólo puede incluir tarjetas Supervisora y Corporativa.');
            }

            if (empty($x['ACTIVA'])) {
                throw new Exception($x['ROTULO'] . ' está dada de baja: un resumen compartido sólo '
                    . 'incluye tarjetas activas.');
            }

            if ((string) $x['COD_BANCO'] !== (string) $primera['COD_BANCO']) {
                throw new Exception('Las tarjetas de un mismo resumen tienen que ser del mismo '
                    . 'banco: ' . $x['ROTULO'] . ' es de otro banco que ' . $primera['ROTULO']
                    . '. Un banco manda un resumen por cuenta.');
            }

            if (intval($x['DIA_VENCIMIENTO']) !== intval($primera['DIA_VENCIMIENTO'])) {
                throw new Exception('Las tarjetas de un mismo resumen tienen que vencer el mismo '
                    . 'día: ' . $x['ROTULO'] . ' vence el ' . intval($x['DIA_VENCIMIENTO'])
                    . ' y ' . $primera['ROTULO'] . ' el ' . intval($primera['DIA_VENCIMIENTO'])
                    . '. Si de verdad son de la misma cuenta, corregí el día en Parámetros › '
                    . 'Tarjetas.');
            }
        }

        return [
            'titular' => $t,
            'adicionales' => array_values(array_filter($lista, function ($i) use ($t) {
                return $i !== $t;
            }))
        ];
    }

    /**
     * Completa el mapa de resumenes vigentes con los compartidos: el mismo
     * resumen aparece en cada tarjeta que incluye.
     *
     * CADA ENTRADA DICE SI ES LA TITULAR. Ver el encabezado de la clase: pisa la
     * estimacion en todas, y suma solo en la titular.
     *
     * UNA ADICIONAL QUE YA TIENE SU PROPIO RESUMEN ESE MES NO SE PISA. No deberia
     * pasar -guardar() lo rechaza- y si pasa, quedarse con el propio es lo que no
     * esconde nada: el compartido sigue sumando en su titular.
     *
     * Estatica y pura.
     *
     * @param array $vigentes Mapa ID_TARJETA => ['Y-m' => fila], las titulares
     * @param array $adicionales Mapa ID_RESUMEN => [ID_TARJETA]
     * @return array El mismo mapa, con las adicionales y los campos ID_TITULAR,
     *               ES_TITULAR, TARJETAS y COMPARTIDO en cada entrada
     */
    public static function conCompartidos($vigentes, $adicionales) {
        $salida = [];

        foreach ($vigentes as $idTarjeta => $porMes) {
            foreach ($porMes as $mes => $r) {
                $extra = isset($adicionales[$r['ID']]) ? array_values($adicionales[$r['ID']]) : [];

                $r['ID_TITULAR'] = intval($idTarjeta);
                $r['TARJETAS'] = array_merge([intval($idTarjeta)], array_map('intval', $extra));
                $r['COMPARTIDO'] = !empty($extra);
                $r['ES_TITULAR'] = true;

                $salida[$idTarjeta][$mes] = $r;
            }
        }

        foreach ($salida as $idTarjeta => $porMes) {
            foreach ($porMes as $mes => $r) {
                if (!$r['ES_TITULAR'] || !$r['COMPARTIDO']) {
                    continue;
                }

                foreach (array_slice($r['TARJETAS'], 1) as $otra) {
                    if (isset($salida[$otra][$mes])) {
                        continue;
                    }

                    $copia = $r;
                    $copia['ES_TITULAR'] = false;
                    $salida[$otra][$mes] = $copia;
                }
            }
        }

        return $salida;
    }

    /**
     * Los resumenes vigentes que ya ocupan alguna de estas tarjetas en un mes,
     * como titular o como adicional.
     *
     * Estatica y pura.
     *
     * @param array $ids Las tarjetas
     * @param string $mes 'Y-m'
     * @param array $mapa Lo que devolvio conCompartidos()
     * @return array Mapa ID_RESUMEN => [ID_TARJETA que lo tienen]
     */
    public static function ocupados($ids, $mes, $mapa) {
        $v = [];

        foreach ($ids as $id) {
            if (isset($mapa[$id][$mes])) {
                $v[$mapa[$id][$mes]['ID']][] = intval($id);
            }
        }

        return $v;
    }

    /**
     * Lo que dice la marca de un resumen compartido en la fila de una tarjeta:
     * con quienes lo comparte y en que sub-pestana se proyecta.
     *
     * Estatica y pura.
     *
     * @param array $resumen Una entrada de conCompartidos()
     * @param int $desde La tarjeta desde cuya fila se mira
     * @param array $tarjetas Mapa ID => tarjeta
     * @return string '' si no es compartido
     */
    public static function notaCompartido($resumen, $desde, $tarjetas) {
        if (empty($resumen['COMPARTIDO'])) {
            return '';
        }

        $otras = [];

        foreach ($resumen['TARJETAS'] as $id) {
            if (intval($id) !== intval($desde)) {
                $otras[] = isset($tarjetas[$id]) ? $tarjetas[$id]['ROTULO'] : ('tarjeta ' . $id);
            }
        }

        $titular = isset($tarjetas[$resumen['ID_TITULAR']]) ? $tarjetas[$resumen['ID_TITULAR']] : null;
        $donde = ($titular !== null && isset(self::TIPOS_COMPARTIBLES[$titular['TIPO']]))
            ? self::TIPOS_COMPARTIBLES[$titular['TIPO']] : 'la sub-pestaña de la titular';

        return 'Resumen compartido con: ' . implode('; ', $otras) . '. Se proyecta una sola vez, '
            . 'en ' . $donde . (intval($desde) === intval($resumen['ID_TITULAR'])
                ? ', en esta tarjeta, que es la titular.'
                : ($titular !== null ? ', en la titular (' . $titular['ROTULO'] . ').' : '.'));
    }

    /* ====================================================================
       LECTURA
       ==================================================================== */

    /** @return bool Si ya se corrio sql/cashflow_tarjetas.sql */
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

    /** @return string El aviso de script faltante, o '' */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe ' . self::TABLA . ': corré sql/cashflow_tarjetas.sql contra la '
            . 'base central. Mientras tanto no se pueden cargar resúmenes, así que todo se '
            . 'proyecta con la estimación y Tarjetas Socios no tiene base con la que estimar.';
    }

    /**
     * Los resumenes VIGENTES, indexados por tarjeta y mes.
     *
     * UNA CONSULTA POR PEDIDO y no una por tarjeta: las tres sub-pestanas los
     * necesitan todos para decidir, tarjeta por tarjeta y mes por mes, si pisan
     * la estimacion.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar: no hay ningun resumen
     * cargado, que es lo cierto.
     *
     * @return array Mapa ID_TARJETA => ['Y-m' => fila]
     */
    public function vigentes() {
        if ($this->vigentes !== null) {
            return $this->vigentes;
        }

        $this->vigentes = [];

        if (!$this->tablaCreada()) {
            return $this->vigentes;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, ID_TARJETA, MES, IMPORTE_ARS, IMPORTE_USD, FECHA_VENCIMIENTO,
                    PAGADO, FECHA_PAGADO, USUARIO_PAGADO, ORIGEN, OBSERVACION,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA . "
             WHERE ACTIVO = 1
             ORDER BY ID_TARJETA, MES");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer los resúmenes'));
        }

        $titulares = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $f = self::fila($row);
            $titulares[$f['ID_TARJETA']][$f['MES']] = $f;
        }

        sqlsrv_free_stmt($stmt);

        /* LAS ADICIONALES, recien con el statement cerrado: ver la nota del pool
           de conexiones en Class/Tarjetas.php. */
        $this->vigentes = self::conCompartidos($titulares, $this->adicionalesVigentes());

        return $this->vigentes;
    }

    /** @return bool Si ya se corrio sql/cashflow_tarjetas_resumen_compartido.sql */
    public function tablaCompartidoCreada() {
        if ($this->tablaCompartido !== null) {
            return $this->tablaCompartido;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo." . self::TABLA_COMPARTIDO . "', 'U') AS T");

            if ($stmt === false) {
                return $this->tablaCompartido = false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tablaCompartido = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tablaCompartido = false;
        }

        return $this->tablaCompartido;
    }

    /**
     * Las tarjetas adicionales vigentes de cada resumen vigente.
     *
     * VACIO SI FALTA EL SCRIPT, sin lanzar: todos los resumenes son de una sola
     * tarjeta, que es lo cierto.
     *
     * @param array|null $idsResumen Solo estos resumenes, vigentes o no; null
     *                               para los vigentes
     * @return array Mapa ID_RESUMEN => [ID_TARJETA]
     */
    private function adicionalesVigentes($idsResumen = null) {
        if (!$this->tablaCompartidoCreada()) {
            return [];
        }

        if ($idsResumen !== null && empty($idsResumen)) {
            return [];
        }

        /* PARA UN RESUMEN DADO DE BAJA se traen las adicionales que se bajaron
           con el: son las que tenia, y el historial tiene que poder decirlo. */
        $sql = ($idsResumen === null)
            ? "SELECT a.ID_RESUMEN, a.ID_TARJETA
               FROM dbo." . self::TABLA_COMPARTIDO . " a
               JOIN dbo." . self::TABLA . " r ON r.ID = a.ID_RESUMEN AND r.ACTIVO = 1
               WHERE a.VIGENTE = 1
               ORDER BY a.ID_RESUMEN, a.ID"
            : "SELECT a.ID_RESUMEN, a.ID_TARJETA
               FROM dbo." . self::TABLA_COMPARTIDO . " a
               JOIN dbo." . self::TABLA . " r ON r.ID = a.ID_RESUMEN
               WHERE a.ID_RESUMEN IN (" . implode(',', array_map('intval', $idsResumen)) . ")
                 AND (a.VIGENTE = 1 OR (r.ACTIVO = 0 AND a.FECHA_BAJA >= r.FECHA_BAJA))
               ORDER BY a.ID_RESUMEN, a.ID";

        $stmt = sqlsrv_query($this->conectar(), $sql);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer las tarjetas de los resúmenes compartidos'));
        }

        $mapa = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $mapa[intval($row['ID_RESUMEN'])][] = intval($row['ID_TARJETA']);
        }

        sqlsrv_free_stmt($stmt);

        return $mapa;
    }

    /**
     * Los resumenes vigentes de UNA tarjeta, por mes.
     *
     * @param mixed $idTarjeta
     * @return array Mapa 'Y-m' => fila
     */
    public function deTarjeta($idTarjeta) {
        $todos = $this->vigentes();
        $id = intval($idTarjeta);

        return isset($todos[$id]) ? $todos[$id] : [];
    }

    /**
     * El historial completo de una tarjeta: los vigentes y los dados de baja, del
     * mas nuevo al mas viejo. INCLUYE LOS COMPARTIDOS en los que la tarjeta es
     * adicional, con ID_TITULAR, ES_TITULAR y TARJETAS.
     *
     * @param mixed $idTarjeta
     * @return array Lista de filas
     */
    public function historial($idTarjeta) {
        if (!$this->tablaCreada()) {
            return [];
        }

        $id = intval($idTarjeta);
        $compartido = $this->tablaCompartidoCreada();

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, ID_TARJETA, MES, IMPORTE_ARS, IMPORTE_USD, FECHA_VENCIMIENTO,
                    PAGADO, FECHA_PAGADO, USUARIO_PAGADO, ORIGEN, OBSERVACION, ACTIVO,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF,
                    USUARIO_BAJA, FECHA_BAJA
             FROM dbo." . self::TABLA . "
             WHERE ID_TARJETA = ?"
            . ($compartido
                ? " OR ID IN (SELECT ID_RESUMEN FROM dbo." . self::TABLA_COMPARTIDO
                  . " WHERE ID_TARJETA = ?)"
                : '') . "
             ORDER BY MES DESC, ID DESC",
            $compartido ? [$id, $id] : [$id]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el historial de resúmenes'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $f = self::fila($row);
            $f['ACTIVO'] = (intval($row['ACTIVO']) === 1);
            $f['USUARIO_BAJA'] = $row['USUARIO_BAJA'];
            $f['FECHA_BAJA'] = self::momento($row['FECHA_BAJA']);
            $filas[] = $f;
        }

        sqlsrv_free_stmt($stmt);

        $adicionales = $this->adicionalesVigentes(array_column($filas, 'ID'));

        foreach ($filas as $i => $f) {
            $extra = isset($adicionales[$f['ID']]) ? $adicionales[$f['ID']] : [];

            $filas[$i]['ID_TITULAR'] = $f['ID_TARJETA'];
            $filas[$i]['TARJETAS'] = array_merge([$f['ID_TARJETA']], $extra);
            $filas[$i]['COMPARTIDO'] = !empty($extra);
            $filas[$i]['ES_TITULAR'] = ($f['ID_TARJETA'] === $id);
        }

        return $filas;
    }

    /**
     * Un resumen por su ID, vigente o no.
     *
     * @param mixed $id
     * @return array|null
     */
    public function getResumen($id) {
        if (!$this->tablaCreada()) {
            return null;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ID, ID_TARJETA, MES, IMPORTE_ARS, IMPORTE_USD, FECHA_VENCIMIENTO,
                    PAGADO, FECHA_PAGADO, USUARIO_PAGADO, ORIGEN, OBSERVACION, ACTIVO,
                    USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, FECHA_MODIF
             FROM dbo." . self::TABLA . " WHERE ID = ?",
            [intval($id)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer el resumen'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            return null;
        }

        $f = self::fila($row);
        $f['ACTIVO'] = (intval($row['ACTIVO']) === 1);

        return $f;
    }

    /**
     * Normaliza una fila cruda.
     *
     * @param array $row
     * @return array
     */
    private static function fila($row) {
        return [
            'ID' => intval($row['ID']),
            'ID_TARJETA' => intval($row['ID_TARJETA']),
            'MES' => trim((string) $row['MES']),
            'IMPORTE_ARS' => ($row['IMPORTE_ARS'] === null) ? null
                : round(floatval($row['IMPORTE_ARS']), 2),
            'IMPORTE_USD' => ($row['IMPORTE_USD'] === null) ? null
                : round(floatval($row['IMPORTE_USD']), 2),
            'FECHA_VENCIMIENTO' => Horizonte::normalizarFecha($row['FECHA_VENCIMIENTO']),
            'PAGADO' => (intval($row['PAGADO']) === 1),
            'FECHA_PAGADO' => self::momento($row['FECHA_PAGADO']),
            'USUARIO_PAGADO' => $row['USUARIO_PAGADO'],
            'ORIGEN' => trim((string) $row['ORIGEN']),
            'ORIGEN_NOMBRE' => isset(self::ORIGENES[trim((string) $row['ORIGEN'])])
                ? self::ORIGENES[trim((string) $row['ORIGEN'])] : trim((string) $row['ORIGEN']),
            'OBSERVACION' => $row['OBSERVACION'],
            'USUARIO_ALTA' => $row['USUARIO_ALTA'],
            'FECHA_ALTA' => self::momento($row['FECHA_ALTA']),
            'USUARIO_MODIF' => $row['USUARIO_MODIF'],
            'FECHA_MODIF' => self::momento($row['FECHA_MODIF'])
        ];
    }

    /* ====================================================================
       ESCRITURA
       ==================================================================== */

    /**
     * Carga o corrige el resumen de una tarjeta y un mes.
     *
     * ALTA O CORRECCION SEGUN EXISTA EL VIGENTE DE ESE MES, y la respuesta dice
     * cual fue: una accion "corregir" aparte obligaria a la pantalla a saber de
     * antemano si ya habia uno.
     *
     * CORREGIR ES UN UPDATE Y NO UNA BAJA MAS UN ALTA. Lo que deja historia es
     * darlo de baja; un importe tipeado mal y corregido a los dos minutos no es
     * una decision que haya que poder reconstruir, y dos filas por cada
     * correccion llenarian el historial de ruido que esconde las bajas de verdad.
     *
     * VARIAS TARJETAS: con $datos['tarjetas'] (lista de ID) y $datos['titular'].
     * Sin ellas es el resumen de una tarjeta, la $idTarjeta. Ver
     * validarTarjetas(). ALTA O CORRECCION LO DECIDE EL RESUMEN QUE YA OCUPA
     * ESAS TARJETAS EN EL MES: si es uno, se corrige -importes, titular y
     * adicionales-; si son dos distintos, se rechaza, porque juntarlos seria
     * decidir cual de los dos importes vale.
     *
     * TODO VA EN UNA TRANSACCION: el resumen y sus adicionales. Con el resumen
     * escrito y una adicional rechazada por el indice, el mes de esa tarjeta
     * quedaria proyectando su estimacion arriba del resumen que la incluye.
     *
     * @param mixed $idTarjeta
     * @param string $mes 'Y-m'
     * @param array $datos importe_ars, importe_usd, fecha_vencimiento, origen,
     *                     observacion, tarjetas, titular
     * @param string $usuario
     * @return array ['id', 'mes', 'nuevo' => bool, 'aviso' => string,
     *                'titular' => int, 'adicionales' => [int]]
     */
    public function guardar($idTarjeta, $mes, $datos, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $m = self::validarMes($mes);
        $importes = self::validarImportes(
            isset($datos['importe_ars']) ? $datos['importe_ars'] : null,
            isset($datos['importe_usd']) ? $datos['importe_usd'] : null);
        $fecha = self::validarFecha(
            isset($datos['fecha_vencimiento']) ? $datos['fecha_vencimiento'] : null);
        $origen = self::validarOrigen(isset($datos['origen']) ? $datos['origen'] : null);
        $obs = isset($datos['observacion']) && trim((string) $datos['observacion']) !== ''
            ? mb_substr(trim((string) $datos['observacion']), 0, self::LARGO_OBSERVACION)
            : null;

        $ids = (isset($datos['tarjetas']) && is_array($datos['tarjetas']) && !empty($datos['tarjetas']))
            ? $datos['tarjetas'] : [$idTarjeta];
        $titular = isset($datos['titular']) && $datos['titular'] !== '' && $datos['titular'] !== null
            ? $datos['titular'] : $idTarjeta;

        $maestro = new Tarjetas();
        $sel = self::validarTarjetas($ids, $titular, $maestro->porId(false));
        $id = $sel['titular'];

        if (!empty($sel['adicionales']) && !$this->tablaCompartidoCreada()) {
            throw new Exception('Todavía no existe ' . self::TABLA_COMPARTIDO . ': corré '
                . self::SCRIPT_COMPARTIDO . ' contra la base central. Hasta entonces un resumen '
                . 'se carga en una sola tarjeta.');
        }

        /* QUE RESUMEN OCUPA YA ESAS TARJETAS ESE MES, leido de nuevo y no del
           cache: es lo que decide si esto es un alta o una correccion. */
        $this->vigentes = null;
        $todas = array_merge([$id], $sel['adicionales']);
        $ocupados = self::ocupados($todas, $m, $this->vigentes());

        if (count($ocupados) > 1) {
            throw new Exception('Las tarjetas elegidas ya tienen ' . count($ocupados) . ' resúmenes '
                . 'distintos en ' . $m . '. Dalos de baja y cargá uno solo: juntarlos sería '
                . 'decidir cuál de los importes vale.');
        }

        $actual = empty($ocupados) ? null : intval(key($ocupados));

        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            if ($actual === null) {
                $stmt = sqlsrv_query($cid,
                    "INSERT INTO dbo." . self::TABLA . "
                        (ID_TARJETA, MES, IMPORTE_ARS, IMPORTE_USD, FECHA_VENCIMIENTO,
                         ORIGEN, OBSERVACION, ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?);
                     SELECT CAST(SCOPE_IDENTITY() AS INT) AS ID;",
                    [$id, $m, $importes['ars'], $importes['usd'], $fecha, $origen, $obs,
                     $usuario, $usuario]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al cargar el resumen'));
                }

                sqlsrv_next_result($stmt);
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt);

                $idResumen = ($row && $row['ID'] !== null) ? intval($row['ID']) : null;

                if ($idResumen === null) {
                    throw new Exception('No se pudo leer el número del resumen recién cargado.');
                }
            } else {
                $idResumen = $actual;

                /* LAS ADICIONALES QUE SALEN SE BAJAN ANTES de cambiar la titular:
                   la nueva titular puede ser una que era adicional. */
                $this->bajarAdicionales($cid, $idResumen, $sel['adicionales'], $usuario);

                /* NO SE PISA EL TILDE DE PAGADO. Corregir el importe de un resumen
                   ya pagado no lo despaga: son dos hechos distintos y el segundo lo
                   decide una persona con su propia accion. */
                $stmt = sqlsrv_query($cid,
                    "UPDATE dbo." . self::TABLA . "
                     SET ID_TARJETA = ?, IMPORTE_ARS = ?, IMPORTE_USD = ?, FECHA_VENCIMIENTO = ?,
                         ORIGEN = ?, OBSERVACION = ?, USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                     WHERE ID = ?",
                    [$id, $importes['ars'], $importes['usd'], $fecha, $origen, $obs, $usuario,
                     $idResumen]);

                if ($stmt === false) {
                    throw new Exception($this->errorSql('Error al corregir el resumen'));
                }

                sqlsrv_free_stmt($stmt);
            }

            $this->altaAdicionales($cid, $idResumen, $m, $sel['adicionales'], $usuario);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar el resumen'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return [
            'id' => $idResumen,
            'mes' => $m,
            'nuevo' => ($actual === null),
            'aviso' => self::avisoMesDistinto($m, $fecha),
            'titular' => $id,
            'adicionales' => $sel['adicionales']
        ];
    }

    /**
     * Da de baja las adicionales vigentes de un resumen que no estan en la lista
     * que queda. Con una lista vacia, todas.
     */
    private function bajarAdicionales($cid, $idResumen, $quedan, $usuario) {
        if (!$this->tablaCompartidoCreada()) {
            return;
        }

        $sql = "UPDATE dbo." . self::TABLA_COMPARTIDO . "
                SET VIGENTE = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                    USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                WHERE ID_RESUMEN = ? AND VIGENTE = 1"
            . (empty($quedan) ? ''
                : ' AND ID_TARJETA NOT IN (' . implode(',', array_map('intval', $quedan)) . ')');

        $stmt = sqlsrv_query($cid, $sql, [$usuario, $usuario, intval($idResumen)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al sacar tarjetas del resumen'));
        }

        sqlsrv_free_stmt($stmt);
    }

    /** Da de alta las adicionales que todavia no estan vigentes en el resumen */
    private function altaAdicionales($cid, $idResumen, $mes, $adicionales, $usuario) {
        foreach ($adicionales as $idTarjeta) {
            $stmt = sqlsrv_query($cid,
                "IF NOT EXISTS (SELECT 1 FROM dbo." . self::TABLA_COMPARTIDO . "
                                WHERE ID_RESUMEN = ? AND ID_TARJETA = ? AND VIGENTE = 1)
                 INSERT INTO dbo." . self::TABLA_COMPARTIDO . "
                     (ID_RESUMEN, ID_TARJETA, MES, VIGENTE, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, 1, ?, ?)",
                [intval($idResumen), intval($idTarjeta), intval($idResumen), intval($idTarjeta),
                 $mes, $usuario, $usuario]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al agregar la tarjeta ' . $idTarjeta
                    . ' al resumen'));
            }

            sqlsrv_free_stmt($stmt);
        }
    }

    /**
     * Marca o desmarca un resumen como pagado.
     *
     * MARCARLO LO SACA DEL HORIZONTE. Desmarcarlo lo devuelve, y por eso se
     * limpian la fecha y el usuario: si quedaran, la fila diria a la vez que no
     * esta pagada y quien la pago. El CHECK de la tabla lo exige.
     *
     * @param mixed $id
     * @param bool $pagado
     * @param string $usuario
     * @return array ['id', 'pagado', 'mes']
     */
    public function marcarPagado($id, $pagado, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $actual = $this->getResumen($id);

        if ($actual === null) {
            throw new Exception('El resumen ' . $id . ' no existe.');
        }

        if (!$actual['ACTIVO']) {
            throw new Exception('El resumen ' . $id . ' está dado de baja: no se puede marcar '
                . 'como pagado. Volvé a cargarlo si corresponde.');
        }

        $marcar = !empty($pagado);

        $stmt = sqlsrv_query($this->conectar(),
            "UPDATE dbo." . self::TABLA . "
             SET PAGADO = ?,
                 FECHA_PAGADO = CASE WHEN ? = 1 THEN GETDATE() ELSE NULL END,
                 USUARIO_PAGADO = CASE WHEN ? = 1 THEN ? ELSE NULL END,
                 USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
             WHERE ID = ?",
            [$marcar ? 1 : 0, $marcar ? 1 : 0, $marcar ? 1 : 0, $usuario, $usuario,
             intval($actual['ID'])]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al marcar el resumen como pagado'));
        }

        sqlsrv_free_stmt($stmt);
        $this->vigentes = null;

        return ['id' => intval($actual['ID']), 'pagado' => $marcar, 'mes' => $actual['MES']];
    }

    /**
     * Da de baja un resumen.
     *
     * NO BORRA NADA: marca ACTIVO = 0 con quien y cuando. Al quedar sin resumen
     * vigente, ese mes vuelve a proyectarse con la estimacion, que es el efecto
     * que se busca.
     *
     * @param mixed $id
     * @param string $usuario
     * @return array ['id', 'mes', 'id_tarjeta']
     */
    public function darDeBaja($id, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $actual = $this->getResumen($id);

        if ($actual === null) {
            throw new Exception('El resumen ' . $id . ' no existe.');
        }

        if (!$actual['ACTIVO']) {
            throw new Exception('El resumen ' . $id . ' ya estaba dado de baja.');
        }

        /* UN COMPARTIDO SE VA DE TODAS SUS TARJETAS A LA VEZ: el resumen y sus
           adicionales en la misma transaccion. Las adicionales se bajan DESPUES,
           asi su FECHA_BAJA no es anterior a la del resumen y el historial las
           sigue mostrando como suyas. */
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            $stmt = sqlsrv_query($cid,
                "UPDATE dbo." . self::TABLA . "
                 SET ACTIVO = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(),
                     USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                 WHERE ID = ? AND ACTIVO = 1",
                [$usuario, $usuario, intval($actual['ID'])]);

            if ($stmt === false) {
                throw new Exception($this->errorSql('Error al dar de baja el resumen'));
            }

            sqlsrv_free_stmt($stmt);

            $this->bajarAdicionales($cid, $actual['ID'], [], $usuario);

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la baja'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return ['id' => intval($actual['ID']), 'mes' => $actual['MES'],
                'id_tarjeta' => $actual['ID_TARJETA']];
    }

    /**
     * Carga la BASE HISTORICA de una tarjeta: varios resumenes de una, con
     * ORIGEN = HISTORICO y ya pagados.
     *
     * ES UNA SOLA TRANSACCION, por el mismo motivo que la exclusion masiva de
     * Proveedores Locales y el tildado masivo de Echeqs: cargar tres resumenes
     * con tres llamadas deja la puerta abierta a que el tercero falle y la
     * tarjeta quede con una base de dos, que estima un promedio distinto sin que
     * nadie se entere. O entran todos o ninguno.
     *
     * NACEN PAGADOS, y no es un atajo: son resumenes que ya vencieron y ya se
     * pagaron, asi que no tienen que entrar al horizonte. Si entraran, cargar la
     * base sumaria al tablero tres egresos del pasado.
     *
     * TODO SE VALIDA ANTES DE ABRIR LA TRANSACCION: un importe mal tipeado en el
     * tercer renglon no puede descubrirse con dos ya escritos. Mismo criterio que
     * Proveedores::normalizarClaves().
     *
     * @param mixed $idTarjeta
     * @param array $resumenes Filas con mes, importe_ars, importe_usd,
     *                         fecha_vencimiento, observacion
     * @param string $usuario
     * @return array ['id_tarjeta', 'cargados' => int, 'meses' => ['Y-m'], 'avisos' => []]
     */
    public function cargarBase($idTarjeta, $resumenes, $usuario) {
        $usuario = AuthCashflow::usuarioDeEscritura($usuario);
        $this->exigirTabla();

        $id = intval($idTarjeta);
        $this->exigirTarjeta($id);

        // 1. Validar TODO antes de escribir nada: los tres renglones, juntos.
        $base = self::validarBase($resumenes);
        $filas = $base['filas'];
        $avisos = $base['avisos'];

        /* UNA TARJETA ADICIONAL DE UN RESUMEN COMPARTIDO no recibe una base propia
           en ese mes: su mes ya lo cubre el resumen de la titular, y un segundo
           resumen lo pisaria dos veces. */
        $this->vigentes = null;
        $mapa = $this->vigentes();

        foreach ($filas as $m => $f) {
            if (isset($mapa[$id][$m]) && empty($mapa[$id][$m]['ES_TITULAR'])) {
                throw new Exception('En ' . $m . ' esta tarjeta ya está incluida en un resumen '
                    . 'compartido. No se cargó ningún resumen.');
            }
        }

        // 2. Escribir, todo o nada.
        $cid = $this->conectar();

        if (sqlsrv_begin_transaction($cid) === false) {
            throw new Exception($this->errorSql('No se pudo abrir la transacción'));
        }

        try {
            foreach ($filas as $f) {
                $this->escribirBase($cid, $id, $f, $usuario);
            }

            if (sqlsrv_commit($cid) === false) {
                throw new Exception($this->errorSql('No se pudo confirmar la carga'));
            }
        } catch (Throwable $e) {
            sqlsrv_rollback($cid);

            throw $e;
        }

        $this->vigentes = null;

        return [
            'id_tarjeta' => $id,
            'cargados' => count($filas),
            'meses' => array_keys($filas),
            'avisos' => $avisos
        ];
    }

    /**
     * Escribe un resumen de base: pisa el vigente de ese mes si lo hay.
     *
     * PISA EN VEZ DE FALLAR, a diferencia de guardar(): cargar la base es una
     * operacion que se rehace -alguien tipeo mal un mes y vuelve a cargar los
     * tres- y hacerla fallar por el mes que ya estaba obligaria a dar de baja a
     * mano antes de poder corregir.
     */
    private function escribirBase($cid, $idTarjeta, $f, $usuario) {
        $actual = $this->vigenteDe($cid, $idTarjeta, $f['mes']);

        if ($actual !== null) {
            $stmt = sqlsrv_query($cid,
                "UPDATE dbo." . self::TABLA . "
                 SET IMPORTE_ARS = ?, IMPORTE_USD = ?, FECHA_VENCIMIENTO = ?,
                     ORIGEN = ?, OBSERVACION = ?, PAGADO = 1, FECHA_PAGADO = GETDATE(),
                     USUARIO_PAGADO = ?, USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                 WHERE ID = ?",
                [$f['ars'], $f['usd'], $f['fecha'], self::HISTORICO, $f['obs'],
                 $usuario, $usuario, intval($actual['ID'])]);
        } else {
            $stmt = sqlsrv_query($cid,
                "INSERT INTO dbo." . self::TABLA . "
                    (ID_TARJETA, MES, IMPORTE_ARS, IMPORTE_USD, FECHA_VENCIMIENTO,
                     PAGADO, FECHA_PAGADO, USUARIO_PAGADO, ORIGEN, OBSERVACION,
                     ACTIVO, USUARIO_ALTA, USUARIO_MODIF)
                 VALUES (?, ?, ?, ?, ?, 1, GETDATE(), ?, ?, ?, 1, ?, ?)",
                [$idTarjeta, $f['mes'], $f['ars'], $f['usd'], $f['fecha'],
                 $usuario, self::HISTORICO, $f['obs'], $usuario, $usuario]);
        }

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al cargar el resumen de '. $f['mes']));
        }

        sqlsrv_free_stmt($stmt);
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /**
     * El resumen vigente de una tarjeta y un mes, sobre una conexion dada.
     *
     * SE PIDE SOBRE LA CONEXION QUE RECIBE y no sobre una nueva: dentro de una
     * transaccion, una conexion distinta no ve lo que la transaccion escribio.
     *
     * @return array|null
     */
    private function vigenteDe($cid, $idTarjeta, $mes) {
        $stmt = sqlsrv_query($cid,
            "SELECT ID, PAGADO FROM dbo." . self::TABLA . "
             WHERE ID_TARJETA = ? AND MES = ? AND ACTIVO = 1",
            [intval($idTarjeta), $mes]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el resumen del mes'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        return $row ? ['ID' => intval($row['ID']), 'PAGADO' => (intval($row['PAGADO']) === 1)]
                    : null;
    }

    /**
     * Lanza si la tarjeta no existe.
     *
     * LA FK DE LA TABLA TAMBIEN LO IMPIDE, pero con un error que no explica nada.
     * Esto da el mensaje.
     */
    private function exigirTarjeta($idTarjeta) {
        $stmt = sqlsrv_query($this->conectar(),
            "SELECT ACTIVA FROM dbo." . Tarjetas::TABLA . " WHERE ID = ?", [intval($idTarjeta)]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar la tarjeta'));
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        if (!$row) {
            throw new Exception('La tarjeta ' . $idTarjeta . ' no está cargada. Se da de alta '
                . 'en Parámetros › Tarjetas.');
        }

        /* UNA TARJETA INACTIVA ACEPTA RESUMENES. No proyecta, pero sus resumenes
           son historia y puede hacer falta completarla -por ejemplo para cerrar
           la base historica de una tarjeta que se dio de baja-. Rechazarlo
           obligaria a reactivarla para poder cargar un dato del pasado. */
    }

    /** Lanza si la tabla no existe */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
    }

    /** Lleva a 'Y-m-d H:i' lo que devuelve sqlsrv para un DATETIME */
    private static function momento($v) {
        if ($v instanceof DateTime) {
            return $v->format('Y-m-d H:i');
        }

        return ($v === null || $v === '') ? null : substr((string) $v, 0, 16);
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
        $msg = $contexto . ' (' . self::TABLA . '): ';

        if ($errores) {
            foreach ($errores as $e) {
                $msg .= $e['message'] . ' ';
            }
        }

        return $msg;
    }
}
