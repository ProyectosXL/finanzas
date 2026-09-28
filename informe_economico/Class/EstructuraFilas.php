<?php
/**
 * EstructuraFilas
 * Que filas tiene el informe y en que orden: RO_T_IE_ESTRUCTURA_FILA.
 *
 * LOS CINCO TIPOS
 * ---------------
 *   TITULO             un rotulo de seccion ("1. VENTAS")
 *   RUBRO              un codigo puntual (CLAVE = COD_RUBRO): se usa para 1.x y 2
 *   RUBROS_DE_SECCION  se expande en todos los rubros de esa CAT_RUBRO_CONTABLE
 *                      (columna SECCION), ordenados por codigo. Un rubro nuevo
 *                      entra solo en cuanto tiene categoria.
 *   CALCULO / RATIO    una formula del catalogo cerrado de Formulas, por CLAVE
 *
 * El bloque "Sin seccion" NO es configurable: lo agrega el codigo al final
 * cada vez que hay rubros presentes sin CAT. Si dependiera de una fila de
 * configuracion, alguien podria ocultarlo, y es justo lo que no se puede
 * esconder.
 */
require_once __DIR__ . '/BaseIE.php';
require_once __DIR__ . '/Formulas.php';

class EstructuraFilas {

    const TABLA = 'RO_T_IE_ESTRUCTURA_FILA';
    const TIPOS = ['TITULO', 'RUBRO', 'RUBROS_DE_SECCION', 'CALCULO', 'RATIO'];

    /* ================================================================
       PURO
       ================================================================ */

    /**
     * Expande la configuracion en las filas que se dibujan.
     *
     * @param array $config filas de la tabla ya ordenadas por ORDEN:
     *        ['CLAVE', 'TIPO', 'SECCION', 'ETIQUETA', 'VISIBLE', 'ACTIVO']
     * @param array $clasif Rubros::clasificar()
     * @param array $maestro [cod => ['nombre']]
     * @return array ['filas' => [...], 'avisos' => [string, ...]]
     *   cada fila: ['id', 'tipo' (TITULO|RUBRO|CALCULO|RATIO), 'etiqueta',
     *               'cod' (RUBRO), 'clave' (CALCULO/RATIO), 'enfasis',
     *               'formato', 'sinSeccion' (bool)]
     */
    public static function expandir(array $config, array $clasif, array $maestro) {
        $cat = Formulas::catalogo();
        $filas = [];
        $avisos = [];

        foreach ($config as $c) {
            if ((int) ($c['ACTIVO'] ?? 1) !== 1 || (int) ($c['VISIBLE'] ?? 1) !== 1) {
                continue;
            }

            $tipo = $c['TIPO'];
            $clave = (string) $c['CLAVE'];
            $etiqueta = trim((string) ($c['ETIQUETA'] ?? ''));

            switch ($tipo) {
                case 'TITULO':
                    $filas[] = ['id' => $clave, 'tipo' => 'TITULO', 'etiqueta' => $etiqueta];
                    break;

                case 'RUBRO':
                    $filas[] = self::filaRubro($clave, $etiqueta, $maestro, false);
                    break;

                case 'RUBROS_DE_SECCION':
                    $sec = trim((string) ($c['SECCION'] ?? ''));

                    if (!isset($clasif['porSeccion'][$sec])) {
                        $avisos[] = 'La fila ' . $clave . ' expande la sección "' . $sec
                            . '", que no tiene ningún rubro en el maestro.';
                        break;
                    }

                    foreach ($clasif['porSeccion'][$sec] as $cod) {
                        $filas[] = self::filaRubro($cod, '', $maestro, false);
                    }
                    break;

                case 'CALCULO':
                case 'RATIO':
                    if (!isset($cat[$clave])) {
                        // Una clave que el codigo no conoce no se inventa: se
                        // saltea y se avisa.
                        $avisos[] = 'La fila ' . $clave . ' no corresponde a ninguna fórmula conocida y no se muestra.';
                        break;
                    }

                    $f = $cat[$clave];
                    $filas[] = [
                        'id' => $clave,
                        'tipo' => $f['tipo'],
                        'clave' => $clave,
                        'etiqueta' => $etiqueta !== '' ? $etiqueta : $f['etiqueta'],
                        'enfasis' => $f['enfasis'],
                        'formato' => $f['formato'] ?? ($f['tipo'] === 'RATIO' ? 'pct' : 'importe')
                    ];
                    break;

                default:
                    $avisos[] = 'La fila ' . $clave . ' tiene un tipo desconocido (' . $tipo . ').';
            }
        }

        if (!empty($clasif['sinSeccion'])) {
            $filas[] = ['id' => 'TIT_SIN_SECCION', 'tipo' => 'TITULO', 'etiqueta' => 'SIN SECCIÓN', 'alerta' => true];

            foreach ($clasif['sinSeccion'] as $cod) {
                $filas[] = self::filaRubro($cod, '', $maestro, true);
            }
        }

        return ['filas' => $filas, 'avisos' => $avisos];
    }

    private static function filaRubro($cod, $etiqueta, array $maestro, $sinSeccion) {
        $nombre = $etiqueta !== '' ? $etiqueta : ($maestro[$cod]['nombre'] ?? 'Rubro sin nombre en el maestro');

        return [
            'id' => 'R_' . $cod,
            'tipo' => 'RUBRO',
            'cod' => $cod,
            'etiqueta' => $cod . ' ' . $nombre,
            'enfasis' => '',
            'formato' => 'importe',
            'sinSeccion' => $sinSeccion
        ];
    }

    /* ================================================================
       BASE
       ================================================================ */

    public static function existe($cid) {
        return BaseIE::existe($cid, self::TABLA);
    }

    /** Todas las filas, activas o no: el ABM las muestra todas */
    public static function leer($cid) {
        return BaseIE::filas($cid, 'SELECT ID, CLAVE, TIPO, SECCION, ORDEN, ETIQUETA, VISIBLE, ACTIVO,
                                           USUARIO_MOD, FECHA_MOD
                                    FROM ' . self::TABLA . ' ORDER BY ORDEN, ID');
    }

    /**
     * Guarda lo editable de una fila: orden, etiqueta, visible y activo. La
     * clave, el tipo y la seccion no se tocan desde la pantalla: son la
     * formula, y la formula vive en el codigo.
     */
    public static function guardar($cid, $id, $orden, $etiqueta, $visible, $activo, $usuario) {
        if (!is_numeric($orden) || (int) $orden != $orden) {
            throw new InvalidArgumentException('El orden tiene que ser un número entero.');
        }

        $etiqueta = trim((string) $etiqueta);

        if (mb_strlen($etiqueta) > 150) {
            throw new InvalidArgumentException('La etiqueta no puede tener más de 150 caracteres.');
        }

        $n = BaseIE::ejecutar($cid, 'UPDATE ' . self::TABLA . '
                                     SET ORDEN = ?, ETIQUETA = ?, VISIBLE = ?, ACTIVO = ?, USUARIO_MOD = ?, FECHA_MOD = GETDATE()
                                     WHERE ID = ?',
            [(int) $orden, $etiqueta, $visible ? 1 : 0, $activo ? 1 : 0, $usuario, (int) $id]);

        if ($n !== 1) {
            throw new RuntimeException('La fila no existe.');
        }
    }
}
