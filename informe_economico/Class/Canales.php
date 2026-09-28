<?php
/**
 * Canales
 * El mapeo canal <-> sucursal, en UN solo lugar.
 *
 * POR QUE ACA Y NO EN SQL
 * -----------------------
 * rentabilidad_rubro repite el CASE de canal tres veces en SQL, y la vista
 * RO_V_RESUMEN_FINAL_IE lo tiene una cuarta vez; ninguna de las cuatro conoce
 * 301-303, asi que clasifican las aperturas de Ecommerce como locales propios.
 * Aca el mapeo es una funcion pura con pruebas, y lo usa todo el informe.
 *
 * NOTA PARA LA MIGRACION DE rentabilidad_rubro: con este mapeo, 301-303 pasan
 * a ser Ecommerce. Esta anotado en el README como pendiente.
 */
require_once __DIR__ . '/Periodo.php';

class Canales {

    const LOCALES = 'LOCALES';
    const FRANQUICIAS = 'FRANQUICIAS';
    const MAYORISTAS = 'MAYORISTAS';
    const ECOMMERCE = 'ECOMMERCE';
    const OTROS = 'OTROS';

    /**
     * Registros viejos que no pertenecen a ninguna sucursal: NRO_SUCURSAL NULL
     * o 0. NO es un canal del negocio: van a una columna propia que no suma al
     * Total general, igual que en el Excel (que directamente no los tiene).
     *
     * La 0 NO se mapea a Ecommerce aunque en SUCURSALES_LAKERS la 0 sea
     * "ML FULL": los registros con 0 de esta tabla son gastos sin sucursal, no
     * ventas de Mercado Libre. Decision confirmada.
     */
    const SIN_SUCURSAL = 'SIN_SUCURSAL';

    /** Clave con la que se indexa una fila sin sucursal */
    const CLAVE_SIN = 'SIN';

    /**
     * 102 es la sucursal historica que englobaba todo Ecommerce; despues se
     * abrio en 301 VTEX, 302 Mercado Libre y 303 ICBC.
     */
    const APERTURAS_ECOMMERCE = [102, 301, 302, 303];

    const FRANQUICIA = 100;
    const MAYORISTA = 101;
    const OTROS_INGRESOS = 103;

    /** Nombres para cuando la tabla no trae ningun DESC_SUCURSAL */
    const NOMBRES_DEFECTO = [
        100 => 'Franquicias',
        101 => 'Mayoristas',
        102 => 'Ecommerce',
        301 => 'Ecommerce VTEX',
        302 => 'Ecommerce ML',
        303 => 'Ecommerce ICBC',
        103 => 'Otros ingresos'
    ];

    /** Rotulos de columna de cada canal, en el orden en que se muestran */
    const ETIQUETAS = [
        self::LOCALES => 'LOCALES',
        self::FRANQUICIAS => 'Franquicias',
        self::MAYORISTAS => 'Mayoristas',
        self::ECOMMERCE => 'Ecommerce',
        self::OTROS => 'Otros ingresos'
    ];

    /**
     * La clave de sucursal con la que se indexa: el numero, o CLAVE_SIN para
     * NULL y 0.
     */
    public static function clave($nro) {
        if ($nro === null || $nro === '' || (int) $nro === 0) {
            return self::CLAVE_SIN;
        }

        return (int) $nro;
    }

    /** El canal de una sucursal */
    public static function canal($nro) {
        $k = self::clave($nro);

        if ($k === self::CLAVE_SIN) {
            return self::SIN_SUCURSAL;
        }

        if ($k === self::FRANQUICIA) {
            return self::FRANQUICIAS;
        }

        if ($k === self::MAYORISTA) {
            return self::MAYORISTAS;
        }

        if (in_array($k, self::APERTURAS_ECOMMERCE, true)) {
            return self::ECOMMERCE;
        }

        if ($k === self::OTROS_INGRESOS) {
            return self::OTROS;
        }

        return self::LOCALES;
    }

    public static function esLocal($nro) {
        return self::canal($nro) === self::LOCALES;
    }

    /**
     * El nombre de cada sucursal.
     *
     * Sale de RO_T_RESUMEN_FINAL_IE y no de SUCURSALES_LAKERS porque Franquicias,
     * Mayoristas y Ecommerce no estan en SUCURSALES_LAKERS. Se toma el
     * DESC_SUCURSAL no nulo del periodo MAS RECIENTE: el nombre cambio con el
     * tiempo (MDP ALDREY / Paseo Aldrey) y el que vale es el ultimo.
     *
     * @param array $filas [['NRO_SUCURSAL'=>, 'PERIODO'=>, 'DESC_SUCURSAL'=>], ...]
     * @return array [clave => ['nombre' => string, 'defecto' => bool]]
     *         'defecto' es true cuando no hubo ningun DESC y se uso el de
     *         NOMBRES_DEFECTO o 'Sucursal N' (este ultimo lleva aviso).
     */
    public static function resolverNombres(array $filas) {
        $mejor = [];

        foreach ($filas as $f) {
            $k = self::clave($f['NRO_SUCURSAL']);

            if (!isset($mejor[$k])) {
                $mejor[$k] = null;
            }

            $desc = isset($f['DESC_SUCURSAL']) ? trim((string) $f['DESC_SUCURSAL']) : '';

            if ($desc === '') {
                continue;
            }

            if ($mejor[$k] === null || Periodo::comparar($f['PERIODO'], $mejor[$k]['periodo']) > 0) {
                $mejor[$k] = ['periodo' => $f['PERIODO'], 'desc' => $desc];
            }
        }

        $out = [];

        foreach ($mejor as $k => $m) {
            if ($k === self::CLAVE_SIN) {
                $out[$k] = ['nombre' => 'Sin sucursal', 'defecto' => false];
            } elseif ($m !== null) {
                $out[$k] = ['nombre' => $m['desc'], 'defecto' => false];
            } else {
                $out[$k] = self::nombreDefecto($k);
            }
        }

        return $out;
    }

    /**
     * Nombre cuando la tabla no trae ninguno. 'defecto_con_aviso' distingue un
     * nombre conocido (100, 101...) de un 'Sucursal N' que hay que avisar.
     */
    public static function nombreDefecto($k) {
        if (isset(self::NOMBRES_DEFECTO[$k])) {
            return ['nombre' => self::NOMBRES_DEFECTO[$k], 'defecto' => true, 'aviso' => false];
        }

        return ['nombre' => 'Sucursal ' . $k, 'defecto' => true, 'aviso' => true];
    }

    const ABIERTO = 'ABIERTO';
    const CERRADO = 'CERRADO';
    /** Local propio que no esta en SUCURSALES_LAKERS: se trata como abierto */
    const NO_ESTA = 'NO_ESTA';
    /** Esta, pero con HABILITADO NULL: se trata como abierto */
    const SIN_ESTADO = 'SIN_ESTADO';

    /**
     * Estado de un local propio.
     *
     * Cerrado es SOLO HABILITADO = 0. Un NULL no se interpreta: se trata como
     * abierto y se avisa ("estado no cargado en SUCURSALES_LAKERS"), igual que
     * un local que no esta en el maestro. Decidir en silencio que un local
     * esta cerrado lo sacaria del subtotal LOCALES sin que nadie lo vea.
     *
     * @param int $nro
     * @param array $maestro [nro => HABILITADO (0|1|null)]
     */
    public static function estadoLocal($nro, array $maestro) {
        if (!array_key_exists((int) $nro, $maestro)) {
            return self::NO_ESTA;
        }

        $h = $maestro[(int) $nro];

        if ($h === null || $h === '') {
            return self::SIN_ESTADO;
        }

        return ((int) $h === 0) ? self::CERRADO : self::ABIERTO;
    }
}
