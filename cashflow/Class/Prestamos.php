<?php

require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/PrestamosCalculo.php';
require_once __DIR__ . '/PrestamosParametros.php';
require_once __DIR__ . '/PrestamosTasas.php';

/**
 * Prestamos
 * Arma lo que piden la pestaña Financiero › Prestamos, Parametros › Prestamos
 * y el proveedor del tablero. NO TIENE REGLAS PROPIAS: la cuenta es de
 * PrestamosCalculo (puro), las tasas de PrestamosTasas y los datos de
 * PrestamosParametros. Aca solo se lee, se junta y se agrupa.
 *
 * NUNCA LANZA al leer: una lectura que falla deja un aviso y el resto sigue.
 */
class Prestamos {

    /** @var string */
    private $hoy;

    /** @var PrestamosParametros */
    private $par;

    /** @var PrestamosTasas */
    private $tasas;

    function __construct($hoy = null, $par = null, $tasas = null) {
        $this->hoy = ($hoy === null) ? date('Y-m-d') : substr((string) $hoy, 0, 10);
        $this->par = ($par === null) ? new PrestamosParametros() : $par;
        $this->tasas = ($tasas === null) ? new PrestamosTasas() : $tasas;
    }

    public function hoy() {
        return $this->hoy;
    }

    public function par() {
        return $this->par;
    }

    /* ====================================================================
       LAS CUENTAS BANCARIAS
       ==================================================================== */

    /**
     * Las cuentas bancarias para el desplegable: las mismas que muestra Saldos
     * (getFilasDisponible()): las de Interbanking y las cuentas BANCO del
     * catalogo que no son fondo. Informativo en esta etapa.
     *
     * @param array $avisos Se agregan los de la lectura
     * @return array clave => nombre
     */
    public function cuentas(&$avisos = []) {
        try {
            require_once __DIR__ . '/Saldos.php';
            $r = (new Saldos())->getFilasDisponible($this->hoy);

            return self::cuentasDe($r['filas'] ?? []);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudieron leer las cuentas bancarias de Saldos ('
                . $e->getMessage() . '): no se puede elegir el banco de un préstamo nuevo.');

            return [];
        }
    }

    /**
     * Pura: de las filas de Saldos, las bancarias. Las de Interbanking por su
     * clave; las del catalogo por su ID, si son BANCO y no son fondo.
     */
    public static function cuentasDe($filas) {
        $out = [];

        foreach ($filas as $f) {
            if (strtoupper((string) ($f['tipo'] ?? '')) !== 'BANCO') {
                continue;
            }

            if (!empty($f['clave'])) {
                $clave = 'IB|' . $f['clave'];
            } elseif (!empty($f['id_cuenta'])) {
                if (in_array(strtoupper((string) ($f['clase'] ?? '')), ['INVERSION', 'COMITENTE'], true)) {
                    continue;
                }

                $clave = 'CTA_' . (int) $f['id_cuenta'];
            } else {
                continue;
            }

            $out[$clave] = trim((string) ($f['nombre'] ?? $clave)) . (empty($f['moneda']) ? '' : ' (' . $f['moneda'] . ')');
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /* ====================================================================
       LAS TASAS DE REFERENCIA
       ==================================================================== */

    /** Lo leido de XL-APPS, el error si lo hubo, los dias del promedio y lo manual. */
    public function insumosTasas($generales, $manual) {
        $leido = $this->tasas->leer();

        return [
            'leido' => $leido,
            'error' => $this->tasas->error(),
            'calculo' => ['disponible' => !empty($leido['disponible']) && $this->tasas->error() === null,
                          'series' => $leido['series'], 'n' => (int) $generales['DIAS_PROMEDIO'], 'manual' => $manual]
        ];
    }

    /**
     * "Ultima TAMAR publicada: XX % (dd/mm)" y "Promedio ultimos N dias habiles".
     *
     * @return array ref => ['ultimo' => ['valor', 'fecha'] | null, 'promedio' => [...] | null]
     */
    public static function resumenTasas($series, $n) {
        $out = [];

        foreach (PrestamosTasas::NOMBRES as $ref => $nombre) {
            $out[$ref] = ['nombre' => $nombre, 'ultimo' => PrestamosTasas::ultimo($series[$ref] ?? []),
                          'promedio' => PrestamosTasas::promedio($series[$ref] ?? [], $n)];
        }

        return $out;
    }

    /** El calendario bancario de un rango. Sin el, lunes a viernes, con aviso. */
    public function habiles($desde, $hasta, &$avisos) {
        try {
            require_once __DIR__ . '/CronogramaDatos.php';

            return (new CronogramaDatos())->habilesEntre($desde, $hasta);
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'No se pudo leer el calendario bancario (' . $e->getMessage()
                . '): las cuotas se corren al hábil asumiendo hábiles los días de lunes a viernes.');

            return [];
        }
    }

    /* ====================================================================
       PARAMETROS › PRESTAMOS
       ==================================================================== */

    /** Todo lo que necesita la sub-pestaña de Parametros. */
    public function parametros() {
        $avisos = [];
        $tabla = $this->par->tablaCreada();
        $out = ['tabla_creada' => $tabla, 'script' => PrestamosParametros::SCRIPT, 'generales' => [], 'costos' => [],
                'tramos' => [], 'tasas_manual' => [], 'referencias' => [], 'listas' => self::listas()];

        if (!$tabla) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, PrestamosParametros::avisoSinTabla());
        }

        try {
            $out['generales'] = $this->par->generales();

            if ($tabla) {
                $out['costos'] = $this->par->costosParam();
                $out['tramos'] = $this->par->tramosParam();
                $out['tasas_manual'] = $this->par->tasasManual()['lista'];
            }
        } catch (Throwable $e) {
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los parámetros de Préstamos (' . $e->getMessage() . ').');
        }

        $leido = $this->tasas->leer();
        $error = $this->tasas->error();
        $n = (int) ($out['generales']['DIAS_PROMEDIO'] ?? PrestamosTasas::DIAS_PROMEDIO_INICIAL);
        $out['referencias'] = self::resumenTasas($leido['series'], $n);
        $out['dias_promedio'] = $n;

        $habiles = $this->habiles(date('Y-m-d', strtotime($this->hoy . ' -40 days')), $this->hoy, $avisos);
        $avisos = array_merge($avisos, PrestamosTasas::avisos($leido, $error, array_keys(PrestamosTasas::NOMBRES), $this->hoy,
            $habiles, (int) ($out['generales']['ANTIGUEDAD_MAX'] ?? PrestamosTasas::ANTIGUEDAD_MAX_INICIAL)));

        $out['avisos'] = $avisos;

        return $out;
    }

    /** Las listas de opciones, con sus nombres de pantalla. */
    public static function listas() {
        return [
            'monedas' => PrestamosParametros::MONEDAS,
            'sistemas' => PrestamosCalculo::SISTEMAS,
            'frecuencias' => PrestamosCalculo::NOMBRE_FRECUENCIA,
            'bases' => PrestamosParametros::BASES,
            'momentos' => PrestamosParametros::MOMENTOS,
            'referencias' => PrestamosTasas::NOMBRES,
            'criterios' => PrestamosTasas::CRITERIOS,
            'formas_pago' => PrestamosParametros::FORMAS_PAGO,
            'estados' => PrestamosCalculo::NOMBRE_ESTADO,
            'origenes_tasa' => PrestamosTasas::NOMBRE_ORIGEN,
            'conceptos' => PrestamosCalculo::NOMBRE_CONCEPTO,
            'comision' => PrestamosParametros::GENERALES['CANCEL_MODO']['lista']
        ];
    }
}
