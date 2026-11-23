<?php
/**
 * Lo que el navegador no puede pedir directo: los .md y los .sql del modulo.
 *
 * No toca la base ni el servidor. Lee cashflow/.htaccess y verifica que la
 * regla siga ahi: si alguien la borra o le saca una extension, los README, los
 * articulos de la ayuda o los scripts vuelven a descargarse sin sesion, y nada
 * en la pantalla lo delata.
 *
 * LO QUE ESTA PRUEBA NO PUEDE VER es si Apache respeta el archivo
 * (AllowOverride). Eso es configuracion del servidor; ver el encabezado del
 * propio .htaccess.
 */

seccion('los .md y los .sql no se sirven por URL');

$rutaHtaccess = __DIR__ . '/../.htaccess';

chequear('cashflow/.htaccess existe', true, file_exists($rutaHtaccess));

$htaccess = file_exists($rutaHtaccess) ? str_replace("\r\n", "\n", file_get_contents($rutaHtaccess)) : '';

chequear('bloquea las dos extensiones', true,
    strpos($htaccess, "<FilesMatch \"\\.(md|sql)$\">\n    Require all denied\n</FilesMatch>") !== false);

// Ayuda/img tiene que seguir sirviendose: un bloqueo por carpeta sobre Ayuda/
// dejaria los articulos sin imagenes.
// Se mira el principio de renglon: el comentario del archivo nombra <Directory>.
chequear('y no bloquea carpetas enteras', false, (bool) preg_match('/^\s*<Directory/mi', $htaccess));
chequear('ni hay otro .htaccess en Ayuda/ que lo contradiga', false,
    file_exists(__DIR__ . '/../Ayuda/.htaccess'));

seccion('las pruebas no se piden por URL');

/* Pedir un .php de tests/ lo ejecuta: run.php corre la suite, que lee la base.
   La carpeta entera se niega; las pruebas se corren por linea de comandos. */
$rutaTests = __DIR__ . '/.htaccess';
chequear('tests/.htaccess existe', true, file_exists($rutaTests));
chequear('y niega todo', true,
    file_exists($rutaTests) && (bool) preg_match('/^Require all denied\s*$/m', file_get_contents($rutaTests)));
