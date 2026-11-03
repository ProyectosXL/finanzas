<?php
/**
 * Proveedores Locales: el pago con EXCEDENTE y el pago PARCIAL.
 *
 * Todo sin base, como test_prov_locales_medios.php: el % llega por parametro,
 * la clasificacion (clasificarImporte()), la importacion (compararImportacion())
 * y como entra al eje (proyectarPagoImportado()) son estaticas y puras. Lo que
 * toca la base se fija leyendo el metodo.
 *
 * Las reglas, en una linea cada una:
 *   - el % es uno, general, >= 0 y < 100 con dos decimales; 0 es valido;
 *   - sin el % (o roto) no se acepta ningun excedente, y se avisa en critico;
 *   - neto ± $1 es NETO; hasta neto × (1 + %) + $1 es EXCEDENTE; menos que
 *     neto − $1 es PARCIAL; mas que el maximo, cero o negativo es ERROR;
 *   - lo que no es NETO se guarda, y se proyecta lo pagado.
 */

require_once __DIR__ . '/../Class/Proveedores.php';
require_once __DIR__ . '/../Class/Parametros.php';

/** Que lance, y que el mensaje diga lo que tiene que decir */
$lanza = function ($nombre, $fn, $dice) {
    try {
        $fn();
    } catch (Throwable $e) {
        return chequear($nombre, true, strpos($e->getMessage(), $dice) !== false);
    }

    return chequear($nombre, 'que lance una excepcion', 'no lanzo');
};

/* ================================================================
   EL % DE EXCEDENTE PERMITIDO
   ================================================================ */
seccion('el %: 0, 5 y 12,5 se guardan');

chequear('0 es válido: no se acepta excedente', 0.0, Proveedores::validarPorcExcedente('0'));
chequear('5', 5.0, Proveedores::validarPorcExcedente(5));
chequear('12,5 con coma', 12.5, Proveedores::validarPorcExcedente('12,5'));
chequear('12.5 con punto', 12.5, Proveedores::validarPorcExcedente('12.5'));
chequear('99,99 es el máximo', 99.99, Proveedores::validarPorcExcedente('99,99'));

seccion('el %: -1, 100, vacío o texto se rechazan, y dicen por qué');

$lanza('−1', function () { Proveedores::validarPorcExcedente('-1'); }, 'de 0 a 99,99');
$lanza('100', function () { Proveedores::validarPorcExcedente('100'); }, 'menor a 100');
$lanza('150', function () { Proveedores::validarPorcExcedente(150); }, 'menor a 100');
$lanza('vacío', function () { Proveedores::validarPorcExcedente(''); }, 'de 0 a 99,99');
$lanza('texto', function () { Proveedores::validarPorcExcedente('cinco'); }, 'de 0 a 99,99');
$lanza('tres decimales', function () { Proveedores::validarPorcExcedente('5,125'); },
    'hasta dos decimales');
$lanza('con separador de miles', function () { Proveedores::validarPorcExcedente('1.000,5'); },
    'de 0 a 99,99');

seccion('el % guardado: lo que no sirve es null con el motivo, nunca un 5 de respaldo');

$p = Proveedores::porcExcedenteDesdeValor('5');
chequear('5 guardado vale 5', [5.0, null], [$p['porc'], $p['error']]);

$p = Proveedores::porcExcedenteDesdeValor('0');
chequear('0 guardado vale 0, no null', 0.0, $p['porc']);

$p = Proveedores::porcExcedenteDesdeValor(null);
chequear('sin la clave: null', null, $p['porc']);
chequear('y el motivo nombra el script y dónde cargarlo', true,
    strpos($p['error'], Proveedores::SCRIPT_EXCEDENTE) !== false
    && strpos($p['error'], 'Parámetros › Prov. Locales') !== false);

$p = Proveedores::porcExcedenteDesdeValor('abc');
chequear('roto: null', null, $p['porc']);
chequear('y el motivo muestra lo que está guardado', true, strpos($p['error'], '«abc»') !== false);

$p = Proveedores::porcExcedenteDesdeValor('120');
chequear('fuera de rango: null', null, $p['porc']);

seccion('el % tiene su propio camino de guardado');

chequear('Parametros repite la clave de Proveedores', Proveedores::CLAVE_PORC_EXCEDENTE,
    Parametros::CLAVE_PORC_EXCEDENTE_PROV);

$fuente = str_replace("
", "
", file_get_contents(__DIR__ . '/../Class/Parametros.php'));
$save = substr($fuente, strpos($fuente, 'public function saveParametro('));
$save = substr($save, 0, strpos($save, "\n    }\n"));

chequear('saveParametro() rechaza la clave antes de escribir', true,
    strpos($save, 'if ($clave === self::CLAVE_PORC_EXCEDENTE_PROV) {') !== false
    && strpos($save, 'if ($clave === self::CLAVE_PORC_EXCEDENTE_PROV) {') < strpos($save, 'UPDATE'));

$fuente = str_replace("
", "
", file_get_contents(__DIR__ . '/../Class/Proveedores.php'));
$guardar = substr($fuente, strpos($fuente, 'public function guardarPorcExcedente('));
$guardar = substr($guardar, 0, strpos($guardar, "\n    }\n"));

chequear('guardarPorcExcedente() valida con la misma regla', true,
    strpos($guardar, 'self::validarPorcExcedente($valor)') !== false);
chequear('firma con el usuario de escritura', true,
    strpos($guardar, 'AuthCashflow::usuarioDeEscritura($usuario)') !== false);
chequear('y deja quién y cuándo', true, strpos($guardar, 'Auditoria::SET_MODIF') !== false);

seccion('el script siembra el % en 5 sin pisarlo');

$sql = str_replace("
", "
", file_get_contents(__DIR__ . '/../' . Proveedores::SCRIPT_EXCEDENTE));

chequear('lo siembra en 5', true,
    strpos($sql, "VALUES (''prov_locales_pct_excedente'', ''5''") !== false);
chequear('sólo si no existe', true,
    strpos($sql, "IF NOT EXISTS (SELECT 1 FROM dbo.RO_T_CASHFLOW_PARAMETROS\n               WHERE CLAVE = 'prov_locales_pct_excedente')") !== false);
chequear('las aperturas que ya están quedan como NETO', true,
    strpos($sql, "DEFAULT ('NETO')") !== false);
chequear('la clasificación es una de tres', true,
    strpos($sql, "CHECK (CLASIFICACION IN ('NETO', 'EXCEDENTE', 'PARCIAL'))") !== false);
