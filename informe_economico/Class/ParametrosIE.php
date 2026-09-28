<?php
/**
 * ParametrosIE
 * Umbrales del semaforo (RO_T_IE_UMBRAL) y parametros sueltos del modulo
 * (RO_T_IE_PARAMETRO). La estructura de filas esta en EstructuraFilas y la
 * categoria de los rubros en Rubros.
 *
 * Cada guardado deja usuario y fecha (USUARIO_MOD / FECHA_MOD).
 */
require_once __DIR__ . '/BaseIE.php';
require_once __DIR__ . '/Semaforo.php';

class ParametrosIE {

    const TABLA_UMBRAL = 'RO_T_IE_UMBRAL';
    const TABLA_PARAMETRO = 'RO_T_IE_PARAMETRO';

    /** El X de la alerta de caida de rentabilidad, en puntos porcentuales enteros */
    const CAIDA_PP = 'alerta_caida_rentabilidad_pp';

    const COLORES = ['VERDE', 'AMARILLO', 'NARANJA', 'ROJO'];

    /* ================================================================
       UMBRALES
       ================================================================ */

    /**
     * @return array|null [INDICADOR => ['nombre', 'sentido', 'bandas' => [...]]]
     *         null si falta la tabla (el ranking va sin colores)
     */
    public static function umbrales($cid) {
        if (!BaseIE::existe($cid, self::TABLA_UMBRAL)) {
            return null;
        }

        $out = [];

        foreach (BaseIE::filas($cid, 'SELECT * FROM ' . self::TABLA_UMBRAL . ' ORDER BY ORDEN, ID') as $r) {
            $bandas = [];

            foreach (self::COLORES as $c) {
                $bandas[$c] = [
                    'desde' => $r[$c . '_DESDE'] !== null ? (float) $r[$c . '_DESDE'] : null,
                    'hasta' => $r[$c . '_HASTA'] !== null ? (float) $r[$c . '_HASTA'] : null
                ];
            }

            $out[$r['INDICADOR']] = [
                'id' => (int) $r['ID'],
                'nombre' => $r['NOMBRE'],
                'sentido' => $r['SENTIDO'],
                'bandas' => $bandas,
                'usuario' => $r['USUARIO_MOD'],
                'fecha' => $r['FECHA_MOD']
            ];
        }

        return $out;
    }

    /**
     * Valida las bandas de un indicador. Puro.
     *
     * Solo lo que haria que el semaforo mienta: un limite que no es numero, o
     * una banda con el desde mayor o igual que el hasta. Bandas que se pisan o
     * dejan huecos se permiten: el orden de peor a mejor resuelve el pisado, y
     * un hueco es un valor sin color, que es lo que el usuario configuro.
     *
     * @param array $bandas [COLOR => ['desde' => string|null, 'hasta' => string|null]] en %
     * @return array ['ok', 'error', 'bandas' => en fracciones]
     */
    public static function validarBandas(array $bandas) {
        $out = [];

        foreach (self::COLORES as $c) {
            $b = $bandas[$c] ?? [];
            $lim = [];

            foreach (['desde', 'hasta'] as $k) {
                $v = isset($b[$k]) ? trim(str_replace(',', '.', (string) $b[$k])) : '';

                if ($v === '') {
                    $lim[$k] = null;
                } elseif (!is_numeric($v)) {
                    return ['ok' => false, 'error' => 'El límite "' . $k . '" de ' . strtolower($c) . ' no es un número.', 'bandas' => null];
                } else {
                    // En pantalla se carga en %, en la tabla va la fraccion
                    $lim[$k] = round((float) $v / 100, 4);
                }
            }

            if ($lim['desde'] !== null && $lim['hasta'] !== null && $lim['desde'] >= $lim['hasta']) {
                return ['ok' => false, 'error' => 'En ' . strtolower($c) . ' el "desde" tiene que ser menor que el "hasta".', 'bandas' => null];
            }

            $out[$c] = $lim;
        }

        return ['ok' => true, 'error' => null, 'bandas' => $out];
    }

    public static function guardarUmbral($cid, $id, array $bandasPct, $usuario) {
        $v = self::validarBandas($bandasPct);

        if (!$v['ok']) {
            throw new InvalidArgumentException($v['error']);
        }

        $set = [];
        $params = [];

        foreach (self::COLORES as $c) {
            $set[] = $c . '_DESDE = ?';
            $params[] = $v['bandas'][$c]['desde'];
            $set[] = $c . '_HASTA = ?';
            $params[] = $v['bandas'][$c]['hasta'];
        }

        $params[] = $usuario;
        $params[] = (int) $id;

        $n = BaseIE::ejecutar($cid, 'UPDATE ' . self::TABLA_UMBRAL . ' SET ' . implode(', ', $set)
            . ', USUARIO_MOD = ?, FECHA_MOD = GETDATE() WHERE ID = ?', $params);

        if ($n !== 1) {
            throw new RuntimeException('El indicador no existe.');
        }
    }

    /* ================================================================
       PARAMETRO DE ALERTA
       ================================================================ */

    /** @return array|null ['valor' => int, 'usuario', 'fecha'] o null si falta la tabla o la fila */
    public static function caidaPp($cid) {
        if (!BaseIE::existe($cid, self::TABLA_PARAMETRO)) {
            return null;
        }

        $f = BaseIE::filas($cid, 'SELECT VALOR, USUARIO_MOD, FECHA_MOD FROM ' . self::TABLA_PARAMETRO . ' WHERE CLAVE = ?', [self::CAIDA_PP]);

        if (!$f || !ctype_digit(trim((string) $f[0]['VALOR']))) {
            return null;
        }

        return ['valor' => (int) $f[0]['VALOR'], 'usuario' => $f[0]['USUARIO_MOD'], 'fecha' => $f[0]['FECHA_MOD']];
    }

    /** X es un entero de puntos porcentuales, sin decimales */
    public static function guardarCaidaPp($cid, $valor, $usuario) {
        $v = trim((string) $valor);

        if (!ctype_digit($v) || (int) $v > 100) {
            throw new InvalidArgumentException('La caída tiene que ser un número entero de puntos porcentuales, entre 0 y 100.');
        }

        $n = BaseIE::ejecutar($cid, 'UPDATE ' . self::TABLA_PARAMETRO . ' SET VALOR = ?, USUARIO_MOD = ?, FECHA_MOD = GETDATE() WHERE CLAVE = ?',
            [$v, $usuario, self::CAIDA_PP]);

        if ($n !== 1) {
            throw new RuntimeException('Falta el parámetro: corré sql/ie_umbrales.sql.');
        }
    }
}
