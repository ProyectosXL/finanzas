/**
 * planilla-excel.js — leer un Excel en el navegador y bajar las plantillas.
 *
 * ES EL UNICO LECTOR DE EXCEL DEL MODULO, y lo comparten las tres
 * importaciones: la planilla de pagos y el maestro de Proveedores Locales, y la
 * de Cob. Electrónicos. Es el gemelo de Class/Planilla.php en el navegador:
 * una sola conversión de fechas y de importes, porque tres copias se
 * desincronizan en el primer cambio y la que queda vieja es la que manda
 * fechas corridas un día.
 *
 * POR QUE EN EL NAVEGADOR
 * -----------------------
 * Leer un .xlsx en PHP necesita la extensión `zip`, que en el servidor está
 * instalada pero no habilitada. Acá se lee con SheetJS -el mismo patrón que
 * bi/kpis (handleArchivoTickets)- y lo que viaja al servidor es la PRIMERA
 * HOJA convertida al texto que ya entiende Planilla::parsear(), en el campo
 * 'contenido'. La validación, el diff y la confirmación siguen en el backend,
 * sin cambios: lo único que se movió es la lectura del binario.
 *
 * EL TEXTO QUE SE ARMA
 * --------------------
 *  - Una línea por FILA DEL EXCEL, las vacías incluidas, y tantas líneas
 *    vacías adelante como filas vacías haya arriba de la tabla. Así el número
 *    de línea que Planilla::parsear() pone en cada error es la fila que el
 *    usuario ve en Excel, encabezado = fila 1.
 *  - Separado por tabulaciones, con cada celda entre comillas: un texto con
 *    ';' o con comillas adentro no puede partir una fila. Los saltos de línea y
 *    las tabulaciones de adentro de una celda se cambian por un espacio.
 *  - En el ENCABEZADO se borran ';', ',' y '|'. Planilla::detectarSeparador()
 *    elige el separador que más aparece en el encabezado, y un título como
 *    "RAZON, SOCIAL" no puede ganarle a la tabulación. A los títulos no les
 *    cambia nada: se comparan sin signos (Planilla::normalizarTitulo()).
 *
 * LAS FECHAS SE LEEN DEL SERIAL, NO DE UN Date
 * --------------------------------------------
 * Con cellDates, SheetJS arma un Date en la zona horaria del navegador, y con
 * la de Argentina algunas fechas quedan el día anterior a las 23:59: redondear
 * no lo arregla para todas. Por eso la hoja se lee SIN cellDates: una celda de
 * fecha llega con su número de serie de Excel y su formato, y el serial se pasa
 * a aaaa-mm-dd con aritmética entera (fechaDeSerial()), sin pasar nunca por
 * Date. Una fecha que vino como TEXTO en la celda se manda tal cual: la
 * entiende Planilla::fecha().
 *
 * LOS IMPORTES VIAJAN CON SU VALOR, sin separador de miles y con punto
 * decimal. El formato con el que se veían en la planilla no viaja, y eso es lo
 * que saca la ambigüedad de '1.648'.
 *
 * LOS CEROS A LA IZQUIERDA, IGUAL QUE CON EL CSV. Excel convierte '000123' en
 * el número 123 si la celda no es de texto, y al guardar como CSV escribía lo
 * que la celda MUESTRA: con formato General, '123'; con un formato de ceros
 * ('000000'), '000123'. Acá se hace lo mismo -una celda numérica con formato de
 * ceros viaja con su texto mostrado, cualquier otra con su número-, así que el
 * Excel no queda ni mejor ni peor que el CSV. No se completa nada.
 *
 * SHEETJS SE CARGA RECIÉN CUANDO HACE FALTA, de cdnjs, en la versión 0.18.5
 * -la de informe_economico y bi/kpis-. Son unos 900 KB que no tienen por qué
 * bajar en cada carga del módulo. Si no carga -sin red, cdnjs caído- la
 * promesa se rechaza con un mensaje que se puede mostrar tal cual, y el próximo
 * intento vuelve a probar.
 *
 * LA PARTE PURA ESTÁ ESCRITA EN ES3 para poder correrla con cscript, el
 * JScript que trae Windows (tests/js/planilla_excel.wsf): el proyecto no tiene
 * node. Nada de map, forEach, trim, indexOf de arrays ni Object.keys ahí.
 */

var PlanillaExcel = (function() {
    'use strict';

    var URL_SHEETJS = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';

    /** Lo que se acepta. Un CSV ya no: la importación es el Excel directo */
    var EXTENSIONES = { xlsx: true, xls: true };

    /**
     * Serial del 01/01/1970, en el sistema de 1900. Con él se pasa de "días de
     * Excel" a "días desde 1970" sin tocar un Date.
     */
    var SERIAL_1970 = 25569;

    /** Diferencia entre los dos sistemas de fechas de Excel (1900 y 1904) */
    var DIAS_1904 = 1462;

    /* ================================================================
       LA PARTE PURA (ES3)
       ================================================================ */

    function dos(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /**
     * Serial de Excel -> 'aaaa-mm-dd', con aritmética entera.
     *
     * Es el algoritmo civil_from_days de Howard Hinnant: convierte días desde
     * 1970 en año, mes y día sin pasar por Date, así que el resultado no
     * depende de la zona horaria del navegador ni de su horario de verano. La
     * parte decimal del serial es la hora y se descarta.
     *
     * ANTES DEL 01/03/1900 NO SE CONVIERTE: Excel cuenta un 29/02/1900 que no
     * existió, y debajo del serial 61 todo está corrido un día. Ninguna fecha
     * de pago vive ahí; devolver null es más honesto que devolver un día mal.
     *
     * @param {number} serial
     * @param {boolean} es1904 Si el libro usa el sistema de 1904 (Mac viejo)
     * @returns {string|null}
     */
    function fechaDeSerial(serial, es1904) {
        if (typeof serial !== 'number' || isNaN(serial)) {
            return null;
        }

        var dias = Math.floor(serial) + (es1904 ? DIAS_1904 : 0);

        if (dias < 61) {
            return null;
        }

        var z = dias - SERIAL_1970 + 719468;
        var era = Math.floor(z / 146097);
        var doe = z - era * 146097;
        var yoe = Math.floor((doe - Math.floor(doe / 1460) + Math.floor(doe / 36524)
            - Math.floor(doe / 146096)) / 365);
        var doy = doe - (365 * yoe + Math.floor(yoe / 4) - Math.floor(yoe / 100));
        var mp = Math.floor((5 * doy + 2) / 153);
        var d = doy - Math.floor((153 * mp + 2) / 5) + 1;
        var m = (mp < 10) ? mp + 3 : mp - 9;
        var y = yoe + era * 400 + (m <= 2 ? 1 : 0);

        return y + '-' + dos(m) + '-' + dos(d);
    }

    /**
     * 'aaaa-mm-dd' -> serial de Excel. Es la vuelta de fechaDeSerial()
     * (days_from_civil), para escribir las fechas de ejemplo de la plantilla
     * como celdas de fecha de verdad.
     *
     * @param {string} ymd
     * @returns {number|null}
     */
    function serialDeFecha(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(ymd));

        if (!m) {
            return null;
        }

        var y = parseInt(m[1], 10);
        var mes = parseInt(m[2], 10);
        var d = parseInt(m[3], 10);

        y -= (mes <= 2) ? 1 : 0;

        var era = Math.floor(y / 400);
        var yoe = y - era * 400;
        var doy = Math.floor((153 * (mes > 2 ? mes - 3 : mes + 9) + 2) / 5) + d - 1;
        var doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy;

        return era * 146097 + doe - 719468 + SERIAL_1970;
    }

    /**
     * Un número como lo espera Planilla::numero(): sin miles, con punto
     * decimal y SIN NOTACIÓN EXPONENCIAL. String(0.0000001) es '1e-7', y
     * Planilla::numero() le saca la 'e' y lee 17: por eso los no enteros pasan
     * por toFixed(6) y se les sacan los ceros de más.
     *
     * @param {number} v
     * @returns {string}
     */
    function numeroComoTexto(v) {
        if (v === Math.floor(v) && Math.abs(v) < 1e15) {
            return String(v);
        }

        var t = v.toFixed(6).replace(/0+$/, '').replace(/\.$/, '');

        return (t === '-0') ? '0' : t;
    }

    /** Un formato que sólo rellena con ceros: '000000'. Ver el encabezado */
    function esFormatoCeros(z) {
        return typeof z === 'string' && /^0{2,}$/.test(z);
    }

    /**
     * El texto con el que viaja una celda de SheetJS.
     *
     * @param {Object|undefined} celda {t, v, w, z}
     * @param {boolean} esFecha Si el formato de la celda es de fecha. Lo decide
     *        quien llama, con XLSX.SSF.is_date(): acá no hay SheetJS
     * @param {boolean} es1904
     * @returns {string}
     */
    function textoDeCelda(celda, esFecha, es1904) {
        if (!celda) {
            return '';
        }

        if (celda.t === 'n' && typeof celda.v === 'number') {
            if (esFecha) {
                var f = fechaDeSerial(celda.v, es1904);

                if (f !== null) {
                    return f;
                }
            }

            if (esFormatoCeros(celda.z) && celda.w !== undefined) {
                return String(celda.w);
            }

            return numeroComoTexto(celda.v);
        }

        if (celda.t === 's') {
            return String(celda.v);
        }

        if (celda.t === 'b') {
            return celda.v ? 'VERDADERO' : 'FALSO';
        }

        // Un error de fórmula ('#N/A'), o lo que haya: lo que la celda muestra.
        if (celda.w !== undefined) {
            return String(celda.w);
        }

        return (celda.v === undefined || celda.v === null) ? '' : String(celda.v);
    }

    /** Una celda del texto: entre comillas, sin saltos ni tabulaciones adentro */
    function celdaDeTexto(s, esEncabezado) {
        var v = String(s).replace(/[\t\r\n]+/g, ' ');

        if (esEncabezado) {
            v = v.replace(/[;,|]/g, ' ');
        }

        if (/^\s*$/.test(v)) {
            return '';
        }

        return '"' + v.replace(/"/g, '""') + '"';
    }

    /**
     * La matriz de textos de una hoja -una fila por fila del Excel, desde la
     * fila 1- convertida al texto que lee Planilla::parsear(). Ver el
     * encabezado del archivo.
     *
     * Las filas vacías del final se sacan: son las que Excel arrastra debajo
     * de los datos, y no corren ningún número.
     *
     * @param {Array} filas Arrays de textos, o null para una fila sin nada
     * @returns {string}
     */
    function textoDeMatriz(filas) {
        var lineas = [];
        var encabezadoVisto = false;
        var ultimaConDatos = -1;
        var i, j;

        for (i = 0; i < filas.length; i++) {
            var fila = filas[i] || [];
            var celdas = [];
            var vacia = true;

            for (j = 0; j < fila.length; j++) {
                if (!/^\s*$/.test(String(fila[j] === undefined || fila[j] === null ? '' : fila[j]))) {
                    vacia = false;
                }
            }

            for (j = 0; j < fila.length; j++) {
                var v = (fila[j] === undefined || fila[j] === null) ? '' : fila[j];

                celdas.push(celdaDeTexto(v, !vacia && !encabezadoVisto));
            }

            if (!vacia) {
                encabezadoVisto = true;
                ultimaConDatos = i;
            }

            lineas.push(vacia ? '' : celdas.join('\t'));
        }

        lineas.length = ultimaConDatos + 1;

        return lineas.join('\n');
    }

    /**
     * Una celda de la plantilla, escrita con el tipo de su columna. Ver
     * Planilla::plantilla(): una fecha de verdad muestra el calendario, un
     * importe numérico no arrastra miles, y un texto marcado '@' no pierde los
     * ceros a la izquierda de lo que se tipee encima.
     *
     * @param {*} valor
     * @param {string} tipo 'texto' | 'fecha' | 'importe'
     * @returns {Object|null} Celda de SheetJS, o null si no hay nada
     */
    function celdaDePlantilla(valor, tipo) {
        if (valor === null || valor === undefined || valor === '') {
            return null;
        }

        if (tipo === 'fecha') {
            var serial = serialDeFecha(valor);

            if (serial !== null) {
                return { t: 'n', v: serial, z: 'dd/mm/yyyy' };
            }
        }

        if (tipo === 'importe' && typeof valor === 'number') {
            return { t: 'n', v: valor, z: '#,##0.00' };
        }

        return { t: 's', v: String(valor), z: '@' };
    }

    /** La extensión de un nombre de archivo, en minúsculas */
    function extension(nombre) {
        var m = /\.([A-Za-z0-9]+)$/.exec(String(nombre || ''));

        return m ? m[1].toLowerCase() : '';
    }

    /**
     * Si los primeros bytes son de un Excel: un .xlsx es un ZIP ('PK\3\4') y un
     * .xls -o un .xlsx con contraseña- es un contenedor CFB (D0 CF 11 E0). Un
     * CSV renombrado a .xlsx no pasa: SheetJS lo leería como texto sin decir
     * nada, y el usuario no sabría que subió otra cosa.
     *
     * @param {Array|Uint8Array} b
     * @returns {boolean}
     */
    function firmaDeExcel(b) {
        if (!b || b.length < 4) {
            return false;
        }

        return (b[0] === 0x50 && b[1] === 0x4B && b[2] === 0x03 && b[3] === 0x04)
            || (b[0] === 0xD0 && b[1] === 0xCF && b[2] === 0x11 && b[3] === 0xE0);
    }

    /* ================================================================
       EN EL NAVEGADOR
       ================================================================ */

    var cargando = null;

    /**
     * Carga SheetJS una vez. Si falla, la próxima llamada vuelve a probar: un
     * corte de red de un minuto no puede dejar la importación rota hasta que
     * alguien recargue la página.
     *
     * @returns {Promise}
     */
    function cargarLibreria() {
        if (typeof XLSX !== 'undefined' && XLSX.read) {
            return Promise.resolve(XLSX);
        }

        if (cargando) {
            return cargando;
        }

        cargando = new Promise(function(resolver, rechazar) {
            var script = document.createElement('script');
            var plazo = setTimeout(function() { fallar(); }, 30000);

            function fallar() {
                clearTimeout(plazo);
                cargando = null;

                if (script.parentNode) { script.parentNode.removeChild(script); }

                rechazar(new Error('No se pudo cargar el lector de Excel. Revisá la conexión '
                    + 'a internet y volvé a intentar: el archivo se lee en el navegador y para '
                    + 'eso hace falta bajar la librería.'));
            }

            script.src = URL_SHEETJS;
            script.async = true;
            script.onerror = fallar;
            script.onload = function() {
                clearTimeout(plazo);

                if (typeof XLSX !== 'undefined' && XLSX.read) {
                    resolver(XLSX);
                } else {
                    fallar();
                }
            };

            document.head.appendChild(script);
        });

        return cargando;
    }

    function leerBytes(archivo) {
        return new Promise(function(resolver, rechazar) {
            var lector = new FileReader();

            lector.onload = function() { resolver(new Uint8Array(lector.result)); };
            lector.onerror = function() {
                rechazar(new Error('No se pudo abrir el archivo "' + archivo.name + '". Si lo '
                    + 'tenés abierto en Excel, cerralo y volvé a elegirlo.'));
            };

            lector.readAsArrayBuffer(archivo);
        });
    }

    /**
     * El mensaje de una lectura que falló, dicho para quien tiene el archivo.
     *
     * SÓLO 'password' ES UNA CONTRASEÑA. Un .xlsx con contraseña es un
     * contenedor CFB y SheetJS dice "password-protected"; un ZIP roto, en
     * cambio, dice "Unsupported ZIP encryption", y mandar a sacarle una
     * contraseña que no tiene sería mandar a buscar el problema donde no está.
     */
    function mensajeLectura(nombre, e) {
        var detalle = (e && e.message) ? e.message : String(e);

        if (/password/i.test(detalle)) {
            return 'El archivo "' + nombre + '" está protegido con contraseña. Abrilo en Excel, '
                + 'sacale la contraseña, guardalo y volvé a subirlo.';
        }

        return 'No se pudo leer "' + nombre + '" como Excel (' + detalle + '). Puede estar '
            + 'dañado: abrilo en Excel, guardalo de nuevo como .xlsx y volvé a probar.';
    }

    /**
     * La primera hoja, como la matriz de textos que convierte textoDeMatriz().
     *
     * SE RECORREN LAS CELDAS QUE EXISTEN, no el rango '!ref': una hoja con
     * formato aplicado hasta la fila un millón tiene un rango de un millón de
     * filas y diez celdas con datos. Las celdas combinadas traen el valor sólo
     * en la de arriba a la izquierda, y las demás quedan vacías: no se repiten.
     */
    function matrizDeHoja(hoja, es1904) {
        var filas = [];
        var clave, pos, celda;

        for (clave in hoja) {
            if (!Object.prototype.hasOwnProperty.call(hoja, clave) || clave.charAt(0) === '!') {
                continue;
            }

            pos = XLSX.utils.decode_cell(clave);
            celda = hoja[clave];

            if (!filas[pos.r]) { filas[pos.r] = []; }

            filas[pos.r][pos.c] = textoDeCelda(celda,
                celda.t === 'n' && !!celda.z && XLSX.SSF.is_date(celda.z), es1904);
        }

        return filas;
    }

    /**
     * Lee un Excel elegido por el usuario y devuelve el texto de su PRIMERA
     * hoja, sin preguntar ni validar su nombre.
     *
     * Todo lo que puede fallar antes de llegar al servidor falla acá, con un
     * mensaje que se puede mostrar tal cual: otro formato, un archivo dañado o
     * protegido, una hoja vacía o la librería que no cargó.
     *
     * @param {File} archivo
     * @returns {Promise<{contenido: string, nombre: string, hoja: string}>}
     */
    function leer(archivo) {
        if (!archivo) {
            return Promise.reject(new Error('Elegí el archivo de Excel que querés importar.'));
        }

        if (!EXTENSIONES[extension(archivo.name)]) {
            return Promise.reject(new Error('"' + archivo.name + '" no es un archivo de Excel '
                + '(.xlsx o .xls). Si la planilla está en CSV, abrila en Excel y guardala como '
                + 'Libro de Excel.'));
        }

        return cargarLibreria()
            .then(function() { return leerBytes(archivo); })
            .then(function(bytes) {
                if (!firmaDeExcel(bytes)) {
                    throw new Error('"' + archivo.name + '" tiene extensión de Excel pero no lo '
                        + 'es: puede estar dañado o ser otro formato renombrado. Abrilo en Excel, '
                        + 'guardalo como .xlsx y volvé a probar.');
                }

                var libro;

                try {
                    libro = XLSX.read(bytes, { type: 'array', cellDates: false, cellNF: true });
                } catch (e) {
                    throw new Error(mensajeLectura(archivo.name, e));
                }

                if (!libro || !libro.SheetNames || !libro.SheetNames.length) {
                    throw new Error('"' + archivo.name + '" no tiene ninguna hoja.');
                }

                var nombreHoja = libro.SheetNames[0];
                var es1904 = !!(libro.Workbook && libro.Workbook.WBProps
                    && libro.Workbook.WBProps.date1904);
                var contenido = textoDeMatriz(matrizDeHoja(libro.Sheets[nombreHoja], es1904));

                if (contenido === '') {
                    throw new Error('La primera hoja de "' + archivo.name + '" ("' + nombreHoja
                        + '") está vacía. La importación lee siempre la primera hoja.');
                }

                return { contenido: contenido, nombre: archivo.name, hoja: nombreHoja };
            });
    }

    /**
     * Arma el FormData con lo leído: 'contenido' y el nombre del archivo, que
     * el resumen de la previsualización muestra. Los campos de más (el período
     * de Cob. Electrónicos) los agrega quien llama.
     */
    function formulario(leido) {
        var form = new FormData();

        form.append('contenido', leido.contenido);
        form.append('archivo_nombre', leido.nombre);

        return form;
    }

    /**
     * Baja la plantilla .xlsx de una importación, armada con la definición que
     * devuelve el backend (Planilla::plantilla()). La primera hoja es la que
     * se completa y se vuelve a subir; la segunda dice qué va en cada columna.
     *
     * @param {string} url Endpoint que devuelve la definición
     * @returns {Promise}
     */
    function bajarPlantilla(url) {
        return Promise.all([cargarLibreria(), pedirJson(url)]).then(function(r) {
            var def = r[1];
            var libro = XLSX.utils.book_new();
            var titulos = [];
            var anchos = [];
            var i, j;

            for (j = 0; j < def.columnas.length; j++) {
                titulos.push(def.columnas[j].titulo);
                anchos.push({ wch: Math.max(14, def.columnas[j].titulo.length + 4) });
            }

            var hoja = XLSX.utils.aoa_to_sheet([titulos]);

            for (i = 0; i < def.filas.length; i++) {
                for (j = 0; j < def.columnas.length; j++) {
                    var celda = celdaDePlantilla(def.filas[i][j], def.columnas[j].tipo);

                    if (celda) {
                        hoja[XLSX.utils.encode_cell({ r: i + 1, c: j })] = celda;
                    }
                }
            }

            hoja['!ref'] = XLSX.utils.encode_range({
                s: { r: 0, c: 0 },
                e: { r: def.filas.length, c: def.columnas.length - 1 }
            });
            hoja['!cols'] = anchos;

            var ayuda = [['Columna', 'Obligatoria', 'Qué va']];

            for (j = 0; j < def.columnas.length; j++) {
                ayuda.push([def.columnas[j].titulo, def.columnas[j].obligatoria ? 'Sí' : 'No',
                            def.columnas[j].ayuda]);
            }

            var hojaAyuda = XLSX.utils.aoa_to_sheet(ayuda);
            hojaAyuda['!cols'] = [{ wch: 24 }, { wch: 12 }, { wch: 100 }];

            XLSX.utils.book_append_sheet(libro, hoja, 'Planilla');
            XLSX.utils.book_append_sheet(libro, hojaAyuda, 'Ayuda');
            XLSX.writeFile(libro, def.archivo + '.xlsx');
        });
    }

    /**
     * Los botones de plantilla de las tres importaciones: cualquier elemento
     * con data-plantilla-excel="<endpoint>". Un solo oyente en el documento,
     * porque las pestañas se cargan por AJAX y se redibujan enteras.
     *
     * El botón se apaga mientras baja y SE VUELVE A PRENDER AUNQUE FALLE: un
     * botón que queda apagado después de un error se lee como una pantalla
     * rota.
     */
    function conectarPlantillas() {
        document.addEventListener('click', function(e) {
            var btn = e.target && e.target.closest
                ? e.target.closest('[data-plantilla-excel]') : null;

            if (!btn || btn.disabled) {
                return;
            }

            e.preventDefault();
            btn.disabled = true;

            /* .then(null, ...) y no .catch(): 'catch' es palabra reservada en
               ES3, y este archivo tiene que poder cargarse en cscript. */
            bajarPlantilla(btn.getAttribute('data-plantilla-excel'))
                .then(null, function(error) {
                    if (typeof Notificacion !== 'undefined') {
                        Notificacion.error('No se pudo armar la plantilla: ' + error.message);
                    }
                })
                .then(function() {
                    btn.disabled = false;
                });
        });
    }

    if (typeof document !== 'undefined' && document.addEventListener) {
        conectarPlantillas();
    }

    return {
        URL_SHEETJS: URL_SHEETJS,
        leer: leer,
        formulario: formulario,
        bajarPlantilla: bajarPlantilla,
        cargarLibreria: cargarLibreria,

        // La parte pura, expuesta para tests/js/planilla_excel.wsf
        fechaDeSerial: fechaDeSerial,
        serialDeFecha: serialDeFecha,
        numeroComoTexto: numeroComoTexto,
        textoDeCelda: textoDeCelda,
        textoDeMatriz: textoDeMatriz,
        celdaDePlantilla: celdaDePlantilla,
        extension: extension,
        firmaDeExcel: firmaDeExcel
    };
})();
