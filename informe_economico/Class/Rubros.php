<?php
/**
 * Rubros
 * El maestro de rubros contables (RO_T_RUBROS_CONTABLES): orden, seccion y la
 * edicion de CAT_RUBRO_CONTABLE.
 *
 * LA SECCION DE UN RUBRO ES SU CAT_RUBRO_CONTABLE
 * -----------------------------------------------
 * Los rubros de ventas y costo (1.1 a 2) tienen CAT NULL A PROPOSITO: la
 * estructura los ubica por su codigo. Cualquier OTRO rubro presente en la
 * tabla sin fila en el maestro, o sin CAT, va a un bloque "Sin seccion" con
 * aviso que manda a Parametros. Nunca se descarta en silencio: un gasto que no
 * se ve es un resultado inflado que nadie puede explicar.
 *
 * OJO: CAT_RUBRO_CONTABLE la usa tambien rentabilidad_rubro (en
 * getGastosPorCategoria). Cambiarla desde Parametros mueve los dos informes.
 */
require_once __DIR__ . '/BaseIE.php';

class Rubros {

    /**
     * Ventas y costo. Tal como estan en la tabla: CON punto final. La
     * estructura los pide por codigo y no se editan en Parametros.
     */
    const CODIGOS_FIJOS = ['1.1.', '1.2.', '1.3.', '1.4.', '1.5.', '1.6.', '1.7.', '1.8.', '2.'];

    const TABLA_AUDITORIA = 'RO_T_IE_RUBRO_CAT_AUDITORIA';

    /* ================================================================
       PURO
       ================================================================ */

    /**
     * Orden natural por segmentos numericos: 7.9 < 7.10 < 7.11. Como texto,
     * '7.10.' queda antes que '7.9.', que es el orden en que los devuelve el
     * ORDER BY de la base.
     */
    public static function comparar($a, $b) {
        $sa = self::segmentos($a);
        $sb = self::segmentos($b);
        $n = max(count($sa), count($sb));

        for ($i = 0; $i < $n; $i++) {
            // Un codigo que se termina antes va primero: 7 < 7.1
            if (!isset($sa[$i])) {
                return -1;
            }

            if (!isset($sb[$i])) {
                return 1;
            }

            if ($sa[$i] !== $sb[$i]) {
                return $sa[$i] < $sb[$i] ? -1 : 1;
            }
        }

        return strcmp((string) $a, (string) $b);
    }

    private static function segmentos($cod) {
        $partes = array_values(array_filter(explode('.', trim((string) $cod)), function ($s) {
            return $s !== '';
        }));

        return array_map('intval', $partes);
    }

    public static function ordenar(array $codigos) {
        $codigos = array_values(array_unique($codigos));
        usort($codigos, [self::class, 'comparar']);

        return $codigos;
    }

    public static function esFijo($cod) {
        return in_array($cod, self::CODIGOS_FIJOS, true);
    }

    /**
     * Arma las secciones.
     *
     * @param array $presentes Codigos que aparecen en los registros
     * @param array $maestro [cod => ['nombre' => , 'cat' => , 'activo' => ]]
     * @return array [
     *   'porSeccion' => [cat => [cod, ...]]  ordenados; con los del maestro
     *                    activos aunque no tengan datos y los inactivos solo si
     *                    tienen datos,
     *   'seccionDe'  => [cod => cat],
     *   'sinSeccion' => [cod, ...]  presentes, no fijos, sin maestro o sin CAT
     * ]
     */
    public static function clasificar(array $presentes, array $maestro) {
        $porSeccion = [];
        $seccionDe = [];
        $sinSeccion = [];
        $presentes = array_flip(array_map('strval', $presentes));

        foreach ($maestro as $cod => $m) {
            $cod = (string) $cod;
            $cat = isset($m['cat']) ? trim((string) $m['cat']) : '';

            if (self::esFijo($cod) || $cat === '') {
                continue;
            }

            $activo = !isset($m['activo']) || (int) $m['activo'] === 1;

            if (!$activo && !isset($presentes[$cod])) {
                continue;
            }

            $porSeccion[$cat][] = $cod;
            $seccionDe[$cod] = $cat;
        }

        foreach (array_keys($presentes) as $cod) {
            $cod = (string) $cod;

            if (self::esFijo($cod) || isset($seccionDe[$cod])) {
                continue;
            }

            $sinSeccion[] = $cod;
        }

        foreach ($porSeccion as $cat => $cods) {
            $porSeccion[$cat] = self::ordenar($cods);
        }

        return [
            'porSeccion' => $porSeccion,
            'seccionDe' => $seccionDe,
            'sinSeccion' => self::ordenar($sinSeccion)
        ];
    }

    /* ================================================================
       BASE
       ================================================================ */

    /** @return array [cod => ['nombre', 'cat', 'activo']] */
    public static function leerMaestro($cid) {
        $out = [];

        foreach (BaseIE::filas($cid, 'SELECT COD_RUBRO, RUBRO_CONTABLE, CAT_RUBRO_CONTABLE, ACTIVO FROM RO_T_RUBROS_CONTABLES') as $r) {
            $out[(string) $r['COD_RUBRO']] = [
                'nombre' => trim((string) $r['RUBRO_CONTABLE']),
                'cat' => $r['CAT_RUBRO_CONTABLE'] !== null ? trim((string) $r['CAT_RUBRO_CONTABLE']) : null,
                'activo' => $r['ACTIVO']
            ];
        }

        return $out;
    }

    /** Las categorias que ya existen: son las unicas que ofrece el desplegable */
    public static function categorias($cid) {
        $out = [];

        foreach (BaseIE::filas($cid, "SELECT DISTINCT CAT_RUBRO_CONTABLE AS CAT FROM RO_T_RUBROS_CONTABLES
                                       WHERE CAT_RUBRO_CONTABLE IS NOT NULL AND LTRIM(RTRIM(CAT_RUBRO_CONTABLE)) <> ''") as $r) {
            $out[] = trim($r['CAT']);
        }

        sort($out);

        return $out;
    }

    /**
     * Cambia la CAT de un rubro, con auditoria en una tabla propia (no se le
     * agregan columnas al maestro, que es de Control de Gastos).
     *
     * Van en una transaccion: un cambio sin su auditoria es justo lo que no
     * puede pasar en una columna que mueve dos informes.
     */
    public static function guardarCategoria($cid, $cod, $cat, $usuario) {
        if (self::esFijo($cod)) {
            throw new InvalidArgumentException('Los rubros de ventas y costo (1.x y 2) no se editan: la estructura los ubica por su código.');
        }

        if (!BaseIE::existe($cid, self::TABLA_AUDITORIA)) {
            throw new RuntimeException('Falta correr sql/ie_resumen_edicion.sql: sin la tabla de auditoría no se puede cambiar la categoría.');
        }

        $cat = trim((string) $cat);

        if (!in_array($cat, self::categorias($cid), true)) {
            throw new InvalidArgumentException('La categoría tiene que ser una de las existentes.');
        }

        if (!sqlsrv_begin_transaction($cid)) {
            throw new RuntimeException(BaseIE::error('No se pudo iniciar la transacción'));
        }

        try {
            $prev = BaseIE::filas($cid, 'SELECT CAT_RUBRO_CONTABLE FROM RO_T_RUBROS_CONTABLES WITH (UPDLOCK) WHERE COD_RUBRO = ?', [$cod]);

            if (count($prev) !== 1) {
                throw new RuntimeException('El rubro ' . $cod . ' no está en el maestro.');
            }

            $anterior = $prev[0]['CAT_RUBRO_CONTABLE'];

            if ($anterior !== null && trim($anterior) === $cat) {
                sqlsrv_rollback($cid);

                return false;
            }

            BaseIE::ejecutar($cid, 'UPDATE RO_T_RUBROS_CONTABLES SET CAT_RUBRO_CONTABLE = ? WHERE COD_RUBRO = ?', [$cat, $cod]);
            BaseIE::ejecutar($cid, 'INSERT INTO ' . self::TABLA_AUDITORIA . ' (COD_RUBRO, CAT_ANTERIOR, CAT_NUEVA, USUARIO, FECHA)
                                    VALUES (?, ?, ?, ?, GETDATE())', [$cod, $anterior, $cat, $usuario]);

            sqlsrv_commit($cid);
        } catch (Throwable $t) {
            sqlsrv_rollback($cid);
            throw $t;
        }

        return true;
    }
}
