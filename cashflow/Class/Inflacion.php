<?php

/**
 * Inflacion
 * El % de inflacion esperado de cada mes calendario, y la suma de a tres meses
 * con la que se ajustan los precios del cashflow.
 *
 * POR QUE EXISTE
 * --------------
 * Hoy la usa un solo consumidor -el valor hora de Logistica Local, que se
 * ajusta cada tres meses- pero no es un dato de Logistica: es una expectativa
 * de la empresa sobre la economia, y cualquier costo que se proyecte a doce
 * meses la va a necesitar. Por eso vive en Parametros -> Generales, al lado del
 * horizonte y de la alicuota, y no adentro del modulo que hoy la consume.
 *
 * SE GUARDA POR MES CALENDARIO, Y LOS MESES VIEJOS NO SE BORRAN
 * -------------------------------------------------------------
 * Un ajuste usa la inflacion de SU mes y la de los DOS ANTERIORES: el de
 * diciembre suma octubre + noviembre + diciembre. O sea que un mes sigue
 * haciendo falta durante tres meses despues de haber pasado. La ventana que la
 * pantalla deja editar se corre sola con el calendario; la tabla no se depura
 * nunca. Si se depurara, un ajuste que ayer se calculaba hoy se caeria sin que
 * nada hubiera cambiado.
 *
 * LA VENTANA EMPIEZA DOS MESES ANTES DEL MES EN CURSO
 * ----------------------------------------------------
 * Lo que hay que proyectar son los once meses siguientes al actual. Pero el
 * primer ajuste de un fletero cuyo MES_BASE es anterior a hoy puede caer en un
 * mes que necesite inflacion YA PASADA, y si la ventana arrancara en el mes que
 * viene ese dato no habria donde cargarlo: el mes quedaria sin proyectar para
 * siempre, avisando que falta un numero que la pantalla no ofrece tipear.
 *
 * LAS DOS MODALIDADES, Y CUAL ES LA FUENTE DE VERDAD
 * ---------------------------------------------------
 *   CONSTANTE  un unico % que se estampa en todos los meses de la ventana
 *   VARIABLE   un % por mes
 *
 * EN LAS DOS, LO QUE EL CALCULO LEE ES SIEMPRE LA TABLA POR MES. El parametro
 * 'inflacion_pct_constante' es lo ultimo que alguien tipeo, no lo que se
 * aplica: si despues se pasa a VARIABLE y se corrige un mes, ese mes vale lo
 * que dice la tabla y el parametro queda como estaba. Resolver la modalidad
 * constante en el momento del calculo -en vez de estamparla- seria peor: los
 * meses que quedan atras perderian su valor el dia que alguien cambia el
 * porcentaje, y un ajuste ya proyectado cambiaria retroactivamente.
 *
 * LOS PORCENTAJES VAN EN PUNTOS, NO EN TASA
 * ------------------------------------------
 * 2 es 2 %, no 0,02. Es como se tipea y como se lee en pantalla, y la unica
 * division por 100 vive en quien aplica el ajuste. La alicuota de IVA hace lo
 * contrario -se guarda como tasa- y por eso esto va dicho: son dos criterios
 * distintos en la misma pestana, y el que no esta escrito se adivina mal.
 *
 * SIN UN MES NO HAY AJUSTE: null Y AVISO, NUNCA CERO
 * ---------------------------------------------------
 * Si falta la inflacion de alguno de los tres meses que un ajuste suma, el
 * ajuste NO se calcula: acumulada() devuelve 'pct' en null y dice que meses
 * faltan. Tomar los que estan y sumar cero por los que no daria un ajuste mas
 * chico que el real y nadie tendria donde enterarse. Es el mismo criterio de
 * Cotizacion y de DolarFuturo.
 *
 * LA RESOLUCION ES PURA Y VIVE APARTE DE LA LECTURA
 * --------------------------------------------------
 * valores() toca la base; ventana(), acumulada() y los helpers de meses no.
 * Todo lo que decide cuanto ajusta un mes es funcion pura del mapa de
 * inflacion, asi que se prueba entero sin base.
 */
class Inflacion {

    /** Cuantos meses entran en un ajuste: el del ajuste y los dos anteriores */
    const MESES_ACUMULA = 3;

    /** Meses anteriores al actual que la ventana deja editar. Ver el encabezado */
    const MESES_ATRAS = 2;

    /** Meses posteriores al actual que se proyectan */
    const MESES_ADELANTE = 11;

    /** Las dos modalidades de carga */
    const CONSTANTE = 'CONSTANTE';
    const VARIABLE = 'VARIABLE';

    /** Tope de un % mensual aceptable. Ver validar() */
    const PCT_MAX = 100;
    const PCT_MIN = -50;

    /** @var Conexion */
    private $conn;

    /** @var array|null Cache de valores(): una sola lectura por pedido */
    private $valores = null;

    /** @var bool|null Cache del chequeo de la tabla */
    private $tabla = null;

    function __construct() {
        require_once __DIR__ . '/../../class/conexion.php';
        $this->conn = new Conexion;
    }

    /* ====================================================================
       LO PURO
       Deciden que meses hay y cuanto ajusta cada uno, sin tocar la base.
       ==================================================================== */

    /**
     * Los meses que la pantalla deja editar: el mes en curso, los dos
     * anteriores y los once siguientes.
     *
     * Cada mes viaja con 'proyecta', que dice si es uno de los once que se
     * proyectan o uno de los tres que estan ahi para que un MES_BASE viejo
     * pueda resolverse. La pantalla los dibuja distinto; el calculo no los
     * distingue, porque para un ajuste de octubre la inflacion de agosto vale
     * exactamente igual que la de octubre.
     *
     * @param string|null $hoy 'Y-m-d'; por defecto hoy
     * @return array Lista de ['mes' => 'Y-m', 'proyecta' => bool]
     */
    public static function ventana($hoy = null) {
        $mesActual = substr(self::hoy($hoy), 0, 7);
        $meses = [];

        for ($i = -self::MESES_ATRAS; $i <= self::MESES_ADELANTE; $i++) {
            $meses[] = [
                'mes' => self::mesMas($mesActual, $i),
                'proyecta' => ($i > 0)
            ];
        }

        return $meses;
    }

    /**
     * Solo las claves de la ventana, para quien no necesita el detalle.
     *
     * @param string|null $hoy 'Y-m-d'
     * @return array Lista de 'Y-m'
     */
    public static function mesesVentana($hoy = null) {
        return array_column(self::ventana($hoy), 'mes');
    }

    /**
     * Cuanto ajusta un mes: la SUMA SIN COMPONER de su inflacion y la de los dos
     * meses anteriores.
     *
     * SIN COMPONER, y es una decision de negocio y no una simplificacion: el
     * ajuste que se pacta con un fletero se enuncia como "la suma de la
     * inflacion del trimestre". Componer daria 6,12 % donde la negociacion dice
     * 6 %, y el valor resultante no coincidiria con ningun papel.
     *
     * DEVUELVE SIEMPRE LA MISMA ESTRUCTURA, con 'pct' en null cuando falta
     * alguno de los tres meses. Asi quien consume no tiene que preguntar si el
     * array existe, que es el mismo criterio de DolarFuturo::resolver().
     *
     * 'meses' trae los tres con su valor -null el que falta- porque es lo que la
     * pantalla muestra en el tooltip: un 6 % sin decir de donde sale no se puede
     * verificar contra nada.
     *
     * @param array $mapa Mapa 'Y-m' => float, en PUNTOS porcentuales
     * @param string $mes Mes del ajuste, 'Y-m'
     * @return array ['pct' => float|null, 'meses' => ['Y-m' => float|null], 'faltan' => ['Y-m']]
     */
    public static function acumulada($mapa, $mes) {
        $detalle = [];
        $faltan = [];
        $suma = 0;

        /* De atras hacia adelante: el tooltip se lee en orden cronologico
           ("octubre + noviembre + diciembre"), que es como se enuncia el
           acuerdo. */
        for ($i = self::MESES_ACUMULA - 1; $i >= 0; $i--) {
            $m = self::mesMas($mes, -$i);

            if (isset($mapa[$m]) && $mapa[$m] !== null) {
                $detalle[$m] = floatval($mapa[$m]);
                $suma += $detalle[$m];
            } else {
                $detalle[$m] = null;
                $faltan[] = $m;
            }
        }

        return [
            'pct' => empty($faltan) ? $suma : null,
            'meses' => $detalle,
            'faltan' => $faltan
        ];
    }

    /**
     * Corre un mes 'Y-m' n meses (n puede ser negativo).
     *
     * Va por (anio * 12 + mes) y no por DateTime->modify('+1 month'), que sobre
     * el dia 31 salta de mes: '2026-01-31' + 1 mes da marzo. Aca no hay dia, asi
     * que la aritmetica entera es la operacion exacta y no una aproximacion.
     *
     * @param string $mes 'Y-m'
     * @param int $n Meses a sumar
     * @return string 'Y-m'
     */
    public static function mesMas($mes, $n) {
        $partes = explode('-', (string) $mes);

        if (count($partes) < 2) {
            throw new Exception("Mes invalido: '$mes'. Se esperaba 'YYYY-MM'.");
        }

        $total = intval($partes[0]) * 12 + (intval($partes[1]) - 1) + intval($n);

        return sprintf('%04d-%02d', intdiv($total, 12), ($total % 12) + 1);
    }

    /**
     * Cuantos meses hay entre dos meses 'Y-m'. Negativo si $hasta es anterior.
     *
     * @param string $desde 'Y-m'
     * @param string $hasta 'Y-m'
     * @return int
     */
    public static function distancia($desde, $hasta) {
        $a = explode('-', (string) $desde);
        $b = explode('-', (string) $hasta);

        if (count($a) < 2 || count($b) < 2) {
            throw new Exception('Mes invalido: se esperaba el formato YYYY-MM.');
        }

        return (intval($b[0]) * 12 + intval($b[1])) - (intval($a[0]) * 12 + intval($a[1]));
    }

    /**
     * Valida un porcentaje mensual antes de guardarlo.
     *
     * EL RANGO NO ES DECORATIVO. Un 2000 tipeado de mas -o un 2 donde se quiso
     * poner 0,2- no falla en la base: es un numero valido, y el valor hora de
     * dentro de un ano sale multiplicado por veinte sin que nada avise. El
     * minimo admite deflacion, que es un escenario raro pero no imposible.
     *
     * @param mixed $valor
     * @return float El porcentaje normalizado
     * @throws Exception si no es un numero dentro del rango
     */
    public static function validar($valor) {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            throw new Exception('El porcentaje de inflación tiene que ser un número.');
        }

        $pct = floatval($valor);

        if ($pct < self::PCT_MIN || $pct > self::PCT_MAX) {
            throw new Exception('El porcentaje de inflación tiene que estar entre '
                . self::PCT_MIN . ' y ' . self::PCT_MAX . '. Se recibió ' . $pct . '.');
        }

        return $pct;
    }

    /**
     * Valida un mes 'Y-m'.
     *
     * @param mixed $mes
     * @return string El mes normalizado
     * @throws Exception si no tiene la forma esperada
     */
    public static function validarMes($mes) {
        $m = trim((string) $mes);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new Exception("Mes inválido: '$mes'. Se esperaba el formato YYYY-MM.");
        }

        return $m;
    }

    /**
     * Normaliza una modalidad. Cualquier cosa que no sea CONSTANTE es VARIABLE:
     * la modalidad decide como se EDITA, asi que un valor raro que caiga en la
     * mas conservadora -la que no estampa once meses de una- es el error barato.
     *
     * @param mixed $modalidad
     * @return string
     */
    public static function modalidad($modalidad) {
        return (strtoupper(trim((string) $modalidad)) === self::CONSTANTE)
            ? self::CONSTANTE
            : self::VARIABLE;
    }

    /* ====================================================================
       LECTURA Y ESCRITURA
       ==================================================================== */

    /**
     * Si la tabla existe. Se pregunta con OBJECT_ID y no intentando un SELECT:
     * una tabla que no existe y una consulta que falla por otra razon son dos
     * cosas distintas, y la pantalla tiene que poder decir cual.
     *
     * @return bool
     */
    public function tablaCreada() {
        if ($this->tabla !== null) {
            return $this->tabla;
        }

        try {
            $stmt = sqlsrv_query($this->conectar(),
                "SELECT OBJECT_ID('dbo.RO_T_CASHFLOW_INFLACION_MES', 'U') AS T");

            if ($stmt === false) {
                $this->tabla = false;

                return false;
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);

            $this->tabla = ($row && $row['T'] !== null);
        } catch (Throwable $e) {
            $this->tabla = false;
        }

        return $this->tabla;
    }

    /** @return string El aviso de script faltante, o '' si la tabla esta */
    public function avisoSinTabla() {
        return $this->tablaCreada() ? '' :
            'Todavía no existe RO_T_CASHFLOW_INFLACION_MES: corré '
            . 'sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto '
            . 'no se puede cargar la inflación, y los valores hora de Logística Local se '
            . 'proyectan sin ajuste trimestral.';
    }

    /**
     * Todos los meses cargados, como mapa 'Y-m' => float.
     *
     * SE TRAE LA TABLA ENTERA y no solo la ventana. Son unas pocas decenas de
     * filas -una por mes- y quien resuelve un ajuste necesita meses que pueden
     * estar fuera de la ventana de edicion. Filtrar aca obligaria a que cada
     * llamador supiera de antemano que meses va a necesitar, que es justamente
     * lo que depende del MES_BASE de cada fletero.
     *
     * DEVUELVE VACIO SI LA TABLA NO EXISTE, sin lanzar: es el mismo criterio de
     * DolarFuturo::curva(). Quien consume avisa con avisoSinTabla().
     *
     * @return array Mapa 'Y-m' => float, en puntos porcentuales
     */
    public function valores() {
        if ($this->valores !== null) {
            return $this->valores;
        }

        $this->valores = [];

        if (!$this->tablaCreada()) {
            return $this->valores;
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MES, PORCENTAJE FROM dbo.RO_T_CASHFLOW_INFLACION_MES ORDER BY MES");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la inflación mensual'));
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $this->valores[trim((string) $row['MES'])] = floatval($row['PORCENTAJE']);
        }

        sqlsrv_free_stmt($stmt);

        return $this->valores;
    }

    /**
     * La ventana con el valor de cada mes y quien lo cargo, lista para la
     * pantalla.
     *
     * @param string|null $hoy 'Y-m-d'
     * @return array Lista de ['mes', 'proyecta', 'porcentaje'|null, 'modalidad'|null,
     *                         'usuario'|null, 'fecha_update'|null]
     */
    public function grilla($hoy = null) {
        $detalle = $this->detalle();
        $filas = [];

        foreach (self::ventana($hoy) as $m) {
            $d = isset($detalle[$m['mes']]) ? $detalle[$m['mes']] : null;

            $filas[] = [
                'mes' => $m['mes'],
                'proyecta' => $m['proyecta'],
                'porcentaje' => $d ? $d['porcentaje'] : null,
                'modalidad' => $d ? $d['modalidad'] : null,
                'usuario' => $d ? $d['usuario'] : null,
                'fecha_update' => $d ? $d['fecha_update'] : null
            ];
        }

        return $filas;
    }

    /**
     * Las filas completas, indexadas por mes. Lo usa grilla(); valores() alcanza
     * para calcular.
     *
     * @return array Mapa 'Y-m' => fila
     */
    private function detalle() {
        if (!$this->tablaCreada()) {
            return [];
        }

        $stmt = sqlsrv_query($this->conectar(),
            "SELECT MES, PORCENTAJE, MODALIDAD, USUARIO, FECHA_UPDATE
             FROM dbo.RO_T_CASHFLOW_INFLACION_MES ORDER BY MES");

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al leer la inflación mensual'));
        }

        $filas = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[trim((string) $row['MES'])] = [
                'porcentaje' => floatval($row['PORCENTAJE']),
                'modalidad' => trim((string) $row['MODALIDAD']),
                'usuario' => $row['USUARIO'],
                'fecha_update' => ($row['FECHA_UPDATE'] instanceof DateTime)
                    ? $row['FECHA_UPDATE']->format('Y-m-d H:i:s')
                    : $row['FECHA_UPDATE']
            ];
        }

        sqlsrv_free_stmt($stmt);

        return $filas;
    }

    /**
     * Guarda el % de UN mes. Alta o correccion, segun exista o no.
     *
     * LA VALIDACION QUE VALE ES ESTA. La pantalla acota lo que se puede tipear,
     * pero lo que manda el navegador es un pedido y no una autorizacion: el
     * endpoint es alcanzable sin pasar por la pantalla.
     *
     * @param string $mes 'Y-m'
     * @param mixed $porcentaje En puntos porcentuales
     * @param string $modalidad Con cual se cargo
     * @param string|null $usuario
     * @return array ['mes', 'porcentaje', 'nuevo' => bool]
     */
    public function guardar($mes, $porcentaje, $modalidad = self::VARIABLE, $usuario = null) {
        $this->exigirTabla();

        $m = self::validarMes($mes);
        $pct = self::validar($porcentaje);
        $mod = self::modalidad($modalidad);
        $cid = $this->conectar();

        $stmt = sqlsrv_query($cid,
            "SELECT MES FROM dbo.RO_T_CASHFLOW_INFLACION_MES WHERE MES = ?", [$m]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al verificar el mes'));
        }

        $existe = (bool) sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);

        $sql = $existe
            ? "UPDATE dbo.RO_T_CASHFLOW_INFLACION_MES
               SET PORCENTAJE = ?, MODALIDAD = ?, USUARIO = ?, FECHA_UPDATE = GETDATE()
               WHERE MES = ?"
            : "INSERT INTO dbo.RO_T_CASHFLOW_INFLACION_MES (PORCENTAJE, MODALIDAD, USUARIO, MES)
               VALUES (?, ?, ?, ?)";

        $stmt = sqlsrv_query($cid, $sql, [$pct, $mod, $usuario, $m]);

        if ($stmt === false) {
            throw new Exception($this->errorSql('Error al guardar la inflación del mes'));
        }

        sqlsrv_free_stmt($stmt);
        $this->valores = null;

        return ['mes' => $m, 'porcentaje' => $pct, 'nuevo' => !$existe];
    }

    /**
     * Estampa un unico % en TODOS los meses de la ventana: la modalidad
     * constante.
     *
     * ESCRIBE LOS MESES EN VEZ DE RESOLVER LA CONSTANTE AL CALCULAR, y es la
     * decision que sostiene todo lo demas. Ver el encabezado: si la constante se
     * resolviera al vuelo, los meses que van quedando atras perderian su valor
     * el dia que alguien cambia el porcentaje, y un ajuste ya proyectado
     * cambiaria retroactivamente.
     *
     * NO TOCA LOS MESES FUERA DE LA VENTANA. Lo que ya paso quedo como estaba:
     * la constante describe lo que se espera de acá en adelante, no una
     * correccion del pasado.
     *
     * @param mixed $porcentaje En puntos porcentuales
     * @param string|null $usuario
     * @param string|null $hoy 'Y-m-d'
     * @return array ['porcentaje', 'meses' => int]
     */
    public function guardarConstante($porcentaje, $usuario = null, $hoy = null) {
        $this->exigirTabla();

        $pct = self::validar($porcentaje);
        $meses = self::mesesVentana($hoy);

        foreach ($meses as $m) {
            $this->guardar($m, $pct, self::CONSTANTE, $usuario);
        }

        return ['porcentaje' => $pct, 'meses' => count($meses)];
    }

    /* ====================================================================
       INFRAESTRUCTURA
       ==================================================================== */

    /** @param string|null $hoy @return string 'Y-m-d' */
    private static function hoy($hoy) {
        return ($hoy === null || $hoy === '') ? date('Y-m-d') : substr((string) $hoy, 0, 10);
    }

    /** Lanza si la tabla no existe. Guardar sin tabla no es un caso a tolerar */
    private function exigirTabla() {
        if (!$this->tablaCreada()) {
            throw new Exception($this->avisoSinTabla());
        }
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
