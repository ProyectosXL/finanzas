<?php

/**
 * Planilla
 * El mecanismo de importacion desde una planilla, escrito UNA vez.
 *
 * DE DONDE SALE
 * -------------
 * Todo esto vivia dentro de Class/CobElectronicos.php, que fue el primer modulo
 * que importo una planilla. Cuando aparecieron dos importaciones mas -el maestro
 * de proveedores y los pagos a proveedores locales- habia que elegir entre
 * copiar trescientas lineas de parser tres veces o extraerlas. Se extrajeron.
 *
 * CobElectronicos sigue exponiendo sus mismos metodos publicos y delega aca: no
 * cambio su contrato ni su comportamiento, y sus pruebas lo fijan.
 *
 * EL USUARIO SUBE UN EXCEL, Y ACA LLEGA TEXTO
 * --------------------------------------------
 * Leer un .xlsx en PHP necesita la extension `zip`, que en el servidor esta
 * instalada pero NO habilitada. Habilitarla es tocar el php.ini de produccion y
 * reiniciar Apache, y el modulo no puede depender de que ese cambio este hecho
 * en cada entorno. Por eso el Excel se lee EN EL NAVEGADOR, con SheetJS
 * (Js/planilla-excel.js), y lo que llega aca es su primera hoja convertida a
 * texto separado por tabulaciones: una linea por fila del Excel, las fechas
 * como aaaa-mm-dd y los importes con punto decimal.
 *
 * Asi este parser sigue siendo uno solo y sigue siendo puro: la validacion, el
 * diff y la confirmacion no cambiaron, porque no cambio lo que reciben. Lo
 * unico que se movio de lugar es la lectura del binario.
 *
 * Y si igual llega un .xlsx crudo -alguien que llamo al endpoint sin pasar por
 * la pantalla-, el parser lo detecta por su firma y dice que hacer, en lugar de
 * fallar con un archivo lleno de bytes binarios.
 *
 * QUE HACE Y QUE NO
 * -----------------
 * Aca se resuelve LA FORMA DEL ARCHIVO: separador, codificacion, encabezados,
 * numeros y fechas. Lo que significa cada fila -si el proveedor existe, si el
 * comprobante esta pendiente, si esto ya estaba cargado- es asunto de cada
 * modulo, porque depende de sus datos.
 *
 * Todo es ESTATICO Y PURO: no toca la base ni el sistema de archivos, asi que se
 * prueba entero sin base y sin archivos.
 *
 * LA DEFINICION DE COLUMNAS LA PONE EL MODULO
 * -------------------------------------------
 * Cada importacion pasa su propio mapa campo => ['titulo', 'obligatoria',
 * 'tipo', 'ayuda', 'sinonimos'], y de ese unico mapa salen las tres cosas: la
 * plantilla que se descarga, el mapeo del encabezado al parsear y la ayuda en
 * pantalla. Con tres listas distintas, la plantilla y el parser se
 * desincronizan en el primer cambio.
 */
class Planilla {

    /** Separadores que puede traer un CSV exportado de Excel */
    const SEPARADORES = [';', ',', "\t", '|'];

    /** Lo que puede ser una columna, para que la plantilla escriba la celda bien */
    const TIPOS = ['texto', 'fecha', 'importe'];

    /**
     * La plantilla que se descarga, COMO DEFINICION y no como archivo.
     *
     * El .xlsx lo arma el navegador con SheetJS (Js/planilla-excel.js), por el
     * mismo motivo por el que lo lee el navegador: este servidor no tiene la
     * extension `zip`. Lo que se manda es lo unico que el navegador no puede
     * inventar -los titulos, el tipo de cada columna, su ayuda y las filas de
     * ejemplo- y sale de la MISMA definicion de columnas que usa parsear(). Asi
     * la plantilla y el parser no pueden desincronizarse: no hay una segunda
     * lista de titulos en ningun lado, ni en el JS.
     *
     * EL TIPO ES LO QUE HACE QUE LA PLANTILLA SE LLENE BIEN. Una celda de fecha
     * de verdad -y no el texto '15/10/2026'- muestra el calendario y viaja como
     * fecha; un importe numerico no arrastra separadores de miles; y un codigo
     * marcado como texto no pierde los ceros a la izquierda que Excel le saca a
     * un numero. Una columna sin tipo declarado es texto, que es lo que menos
     * puede salir mal.
     *
     * LAS FILAS DE EJEMPLO VAN CARGADAS, no comentadas. Una plantilla vacia
     * obliga a adivinar el formato del numero y de la fecha, que es justo donde
     * falla una importacion. Las fechas de ejemplo van como aaaa-mm-dd y los
     * importes como numero: el navegador las escribe con el tipo de su columna.
     *
     * @param array $columnas Definicion de columnas del modulo
     * @param array $ejemplos Filas de ejemplo, cada una en el orden de $columnas
     * @param string $archivo Nombre del archivo que se descarga, sin extension
     * @return array ['archivo', 'columnas' => [['titulo', 'tipo', 'obligatoria',
     *               'ayuda']], 'filas']
     */
    public static function plantilla($columnas, $ejemplos, $archivo) {
        $cols = [];

        foreach ($columnas as $c) {
            $tipo = isset($c['tipo']) ? $c['tipo'] : 'texto';

            $cols[] = [
                'titulo' => $c['titulo'],
                'tipo' => in_array($tipo, self::TIPOS, true) ? $tipo : 'texto',
                'obligatoria' => !empty($c['obligatoria']),
                'ayuda' => isset($c['ayuda']) ? $c['ayuda'] : ''
            ];
        }

        return ['archivo' => $archivo, 'columnas' => $cols, 'filas' => array_values($ejemplos)];
    }

    /**
     * Lee el contenido de una planilla y devuelve las filas crudas.
     *
     * Lo normal es el texto que arma el navegador desde un Excel -separado por
     * tabulaciones, con cada celda entre comillas-, pero se sigue aceptando un
     * CSV como los que exporta Excel: detecta el separador y acepta los
     * formatos de numero y de fecha de cualquiera de los dos idiomas, asi que
     * quien no sabe en que configuracion regional esta su Excel no tiene que
     * saberlo.
     *
     * Cada fila devuelta lleva su numero de 'linea' en el archivo. Sin eso, un
     * error de importacion dice "hay una fila mal" y el usuario tiene que
     * buscarla a ojo entre mil doscientas.
     *
     * LA LINEA ES LA DEL ARCHIVO, CONTANDO LAS VACIAS. Antes las vacias se
     * filtraban ANTES de numerar, asi que una fila en blanco en el medio corria
     * todos los numeros de abajo: el error decia "linea 14" de una fila que en
     * la planilla era la 15. El texto que arma el navegador desde un Excel trae
     * una linea por fila de la hoja -las vacias incluidas-, asi que contando asi
     * el numero es el de la fila del Excel, encabezado = fila 1.
     *
     * @param string $contenido Contenido del archivo subido
     * @param array $columnas Definicion de columnas del modulo
     * @return array ['filas' => [...], 'errores' => [...], 'separador' => string]
     */
    public static function parsear($contenido, $columnas) {
        $contenido = (string) $contenido;

        /* Un .xlsx es un ZIP: empieza con 'PK'. Desde la pantalla no puede
           llegar asi -el navegador lo lee y manda texto-, asi que esto es para
           quien llame al endpoint por su cuenta, y le dice que hacer en lugar de
           fallar con un archivo lleno de bytes binarios. */
        if (substr($contenido, 0, 2) === 'PK') {
            throw new Exception('Llegó un .xlsx sin leer, y este servidor no abre archivos '
                . 'de Excel: los lee el navegador. Importalo desde la pantalla de importación, '
                . 'que convierte la primera hoja y manda su contenido.');
        }

        if (substr($contenido, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new Exception('Llegó un .xls sin leer, y este servidor no abre archivos de '
                . 'Excel: los lee el navegador. Importalo desde la pantalla de importación.');
        }

        // BOM de UTF-8: si queda, el primer titulo no matchea con nada.
        if (substr($contenido, 0, 3) === "\xEF\xBB\xBF") {
            $contenido = substr($contenido, 3);
        }

        // Excel en Windows guarda en la codificacion del sistema si no se elige
        // CSV UTF-8. Se convierte para que un texto con acento no quede como
        // basura y no matchee contra el maestro.
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        /* Se numera sobre TODAS las lineas y recien despues se saltean las
           vacias: ver la nota del docblock sobre el numero de linea. */
        $lineas = preg_split('/\r\n|\r|\n/', $contenido);
        $encabezado = null;

        foreach ($lineas as $i => $l) {
            if (trim($l) !== '') {
                $encabezado = $i;
                break;
            }
        }

        if ($encabezado === null) {
            throw new Exception('El archivo está vacío');
        }

        $separador = self::detectarSeparador($lineas[$encabezado]);
        $mapa = self::mapearEncabezado(str_getcsv($lineas[$encabezado], $separador), $columnas);

        $filas = [];
        $errores = [];

        for ($i = $encabezado + 1; $i < count($lineas); $i++) {
            if (trim($lineas[$i]) === '') {
                continue;
            }

            $celdas = str_getcsv($lineas[$i], $separador);
            $fila = ['linea' => $i + 1];
            $vacia = true;

            foreach ($mapa as $campo => $indice) {
                $valor = isset($celdas[$indice]) ? trim((string) $celdas[$indice]) : '';
                $fila[$campo] = $valor;

                if ($valor !== '') {
                    $vacia = false;
                }
            }

            // Una fila con separadores y nada mas es lo que deja Excel debajo de
            // los datos: se saltea en silencio, no es un error del usuario.
            if ($vacia) {
                continue;
            }

            $filas[] = $fila;
        }

        if (empty($filas)) {
            throw new Exception('El archivo no tiene ninguna fila de datos. La primera fila es '
                . 'el encabezado y abajo van los datos.');
        }

        return ['filas' => $filas, 'errores' => $errores, 'separador' => $separador];
    }

    /**
     * Separador de un CSV: el que mas veces aparece en el encabezado.
     *
     * Excel en espanol exporta con ';' y en ingles con ','. Adivinarlo es mas
     * barato que hacer que el usuario lo declare, y si se equivoca el encabezado
     * no matchea y el error lo dice.
     *
     * @param string $encabezado
     * @return string
     */
    public static function detectarSeparador($encabezado) {
        $mejor = ';';
        $max = -1;

        foreach (self::SEPARADORES as $sep) {
            $n = substr_count($encabezado, $sep);

            if ($n > $max) {
                $max = $n;
                $mejor = $sep;
            }
        }

        return $mejor;
    }

    /**
     * Empareja los titulos del archivo con los campos del modulo.
     *
     * La comparacion es sin acentos, sin espacios y sin mayusculas, y acepta los
     * sinonimos declarados: el usuario no tiene que escribir el titulo exacto, y
     * una columna de mas no molesta.
     *
     * @param array $titulos Celdas de la primera fila
     * @param array $columnas Definicion de columnas del modulo
     * @return array Mapa campo => indice de columna
     */
    public static function mapearEncabezado($titulos, $columnas) {
        $mapa = [];

        foreach ($titulos as $i => $titulo) {
            $normalizado = self::normalizarTitulo($titulo);

            foreach ($columnas as $campo => $def) {
                if (isset($mapa[$campo])) {
                    continue;
                }

                foreach ($def['sinonimos'] as $sinonimo) {
                    if ($normalizado === self::normalizarTitulo($sinonimo)) {
                        $mapa[$campo] = $i;
                        break 2;
                    }
                }
            }
        }

        $faltan = [];

        foreach ($columnas as $campo => $def) {
            if ($def['obligatoria'] && !isset($mapa[$campo])) {
                $faltan[] = $def['titulo'];
            }
        }

        if (!empty($faltan)) {
            throw new Exception('Al archivo le faltan columnas obligatorias: '
                . implode(', ', $faltan) . '. Descargá la plantilla y usá sus encabezados. '
                . 'Se encontraron: ' . implode(', ', array_map('strval', $titulos)) . '.');
        }

        return $mapa;
    }

    /**
     * Un CODIGO tipeado en una planilla, listo para comparar: sin espacios y en
     * mayusculas de verdad.
     *
     * EXISTE PORQUE strtoupper() NO SIRVE PARA ESTO. strtoupper() trabaja byte a
     * byte, asi que sobre UTF-8 deja intactos los caracteres multibyte:
     *
     *     strtoupper('ognuñe')     -> 'OGNUñE'    la eñe NO sube
     *     mb_strtoupper('ognuñe')  -> 'OGNUÑE'
     *
     * Y eso no es cosmetico: el codigo es la UNICA clave con la que la planilla
     * cruza contra las cuentas a pagar. Un 'OGNUñE' no matchea contra el
     * 'OGNUÑE' de Tango, y la fila quedaria sin cruzar sin que nadie entienda
     * por que. En el maestro hay 27 proveedores con caracteres no ASCII en el
     * codigo -eñes, y tambien '&' y '+'-.
     *
     * NO se sacan los acentos, a diferencia de normalizarTitulo(): un titulo de
     * columna se compara de forma laxa porque lo escribe quien arma el archivo,
     * pero un codigo de proveedor es un identificador y 'OGNUNE' y 'OGNUÑE'
     * pueden ser dos proveedores distintos.
     *
     * @param mixed $codigo
     * @return string
     */
    public static function codigo($codigo) {
        return mb_strtoupper(trim((string) $codigo), 'UTF-8');
    }

    /**
     * Cuantos CARACTERES tiene un texto, no cuantos bytes.
     *
     * strlen() cuenta bytes: strlen('OGNUÑE') da 7 porque la eñe ocupa dos en
     * UTF-8. Validar un largo con strlen contra un limite pensado en caracteres
     * rechaza codigos perfectamente validos, y el mensaje de error dice una
     * cantidad que el usuario no ve en su planilla.
     *
     * @param mixed $texto
     * @return int
     */
    public static function largo($texto) {
        return mb_strlen((string) $texto, 'UTF-8');
    }

    /** Titulo de columna comparable: sin acentos, sin espacios, en mayusculas */
    public static function normalizarTitulo($titulo) {
        $t = mb_strtoupper(trim((string) $titulo), 'UTF-8');

        $t = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $t
        );

        return preg_replace('/[^A-Z0-9]/', '', $t);
    }

    /**
     * Lleva a float un importe tipeado en una planilla.
     *
     * Acepta lo que exporta Excel en las dos configuraciones regionales:
     * '1069326,00' y '1069326.00'. Con los DOS separadores presentes, el que
     * este mas a la derecha es el decimal y el otro es de miles ('3.757.900,50').
     * Con UN solo separador se toma como decimal, salvo que aparezca mas de una
     * vez, que solo puede ser separador de miles ('1.648.264').
     *
     * Un solo punto o coma con tres decimales -'1.648'- es genuinamente
     * ambiguo, asi que la plantilla pide el importe SIN separador de miles.
     *
     * LO QUE MANDA EL NAVEGADOR DESDE UN EXCEL NO ES AMBIGUO: una celda
     * numerica viaja con su valor, sin miles y con punto decimal ('1069326.5',
     * '-1500'), que con un solo separador es justamente el caso decimal. El
     * formato con el que se veia en la planilla no viaja. Hay prueba.
     *
     * @param string $valor
     * @return float|null null si no es un numero
     */
    public static function numero($valor) {
        $v = trim((string) $valor);

        // Simbolos de moneda, espacios y espacios finos que pega Excel
        $v = str_replace(['$', ' ', "\xc2\xa0", "\xe2\x80\xaf", 'ARS', 'AR$'], '', $v);

        if ($v === '') {
            return null;
        }

        $negativo = (strpos($v, '-') !== false) || (strpos($v, '(') !== false);
        $v = preg_replace('/[^0-9.,]/', '', $v);

        if ($v === '' || !preg_match('/[0-9]/', $v)) {
            return null;
        }

        $puntos = substr_count($v, '.');
        $comas = substr_count($v, ',');

        if ($puntos > 0 && $comas > 0) {
            $decimal = (strrpos($v, '.') > strrpos($v, ',')) ? '.' : ',';
            $miles = ($decimal === '.') ? ',' : '.';
            $v = str_replace($miles, '', $v);
            $v = str_replace($decimal, '.', $v);
        } elseif ($comas > 1) {
            $v = str_replace(',', '', $v);
        } elseif ($puntos > 1) {
            $v = str_replace('.', '', $v);
        } elseif ($comas === 1) {
            $v = str_replace(',', '.', $v);
        }

        if (!is_numeric($v)) {
            return null;
        }

        return $negativo ? -abs(floatval($v)) : floatval($v);
    }

    /**
     * Lleva a 'Y-m-d' una fecha tipeada en una planilla.
     *
     * Acepta 'dd/mm/aaaa', 'dd-mm-aaaa', 'aaaa-mm-dd', 'dd/mm/aa' y el SERIAL de
     * Excel. El serial se acepta acotado -del 1954 al 2064- porque una columna
     * que quedo con formato numero exporta '46265' en lugar de la fecha, y sin
     * esto la importacion falla con un mensaje que no ayuda.
     *
     * Una fecha ambigua NO se adivina: 'dd/mm' sin anio devuelve null y la fila
     * queda como error, con su numero de linea.
     *
     * @param string $valor
     * @return string|null 'Y-m-d' o null
     */
    public static function fecha($valor) {
        $v = trim((string) $valor);

        if ($v === '') {
            return null;
        }

        // aaaa-mm-dd o aaaa/mm/dd, con hora opcional
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $v, $m)) {
            return self::armarFecha($m[1], $m[2], $m[3]);
        }

        // dd/mm/aaaa, dd-mm-aaaa, dd.mm.aaaa y su version de dos digitos
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $v, $m)) {
            $anio = intval($m[3]);

            if ($anio < 100) {
                $anio += ($anio < 70) ? 2000 : 1900;
            }

            return self::armarFecha($anio, $m[2], $m[1]);
        }

        // Serial de Excel. La base es 1899-12-30 por el bug del anio 1900 que
        // Excel conserva a proposito.
        if (preg_match('/^\d{5}$/', $v)) {
            $serial = intval($v);

            if ($serial >= 20000 && $serial <= 60000) {
                return date('Y-m-d', strtotime('1899-12-30 +' . $serial . ' day'));
            }
        }

        return null;
    }

    /** Valida y arma 'Y-m-d'. Devuelve null si la fecha no existe */
    private static function armarFecha($anio, $mes, $dia) {
        $anio = intval($anio);
        $mes = intval($mes);
        $dia = intval($dia);

        if (!checkdate($mes, $dia, $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }

    /**
     * Normaliza un texto de planilla contra una lista de valores declarados,
     * SIN PERDER LO QUE VINO.
     *
     * Devuelve las dos cosas: el valor normalizado -o null si no matcheo- y el
     * original tal cual estaba. Las dos hacen falta:
     *
     *   - el normalizado es con el que se agrupa y se decide;
     *   - el original es lo que hay que mostrar en la previsualizacion cuando no
     *     matcheo, porque "no reconocí FORMA DE PAGO" sin decir que decia la
     *     celda obliga a abrir la planilla y buscar la fila.
     *
     * NO SE DESCARTA EN SILENCIO un valor desconocido: se devuelve con
     * 'normalizado' en null y quien llama decide si es error o advertencia. Un
     * "echeq" en minuscula matchea contra "ECHEQ"; un "eqheck" no matchea y
     * tiene que verse.
     *
     * @param string $valor Lo que vino en la celda
     * @param array $validos Valores declarados (se comparan normalizados)
     * @return array ['normalizado' => string|null, 'original' => string]
     */
    public static function normalizarContra($valor, $validos) {
        $original = trim((string) $valor);

        if ($original === '') {
            return ['normalizado' => null, 'original' => ''];
        }

        $clave = self::normalizarTitulo($original);

        foreach ($validos as $v) {
            if (self::normalizarTitulo($v) === $clave) {
                return ['normalizado' => $v, 'original' => $original];
            }
        }

        return ['normalizado' => null, 'original' => $original];
    }
}
