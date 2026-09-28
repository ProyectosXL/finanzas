<?php
/**
 * Sucursales
 * El estado de los locales propios, de SUCURSALES_LAKERS.
 *
 * Se lee del servidor 'locales' con el prefijo de Conexion::prefijoLocales(),
 * igual que cashflow/Class/Saldos.php: en ENV=DEV se alcanza por linked
 * server con el nombre de cuatro partes.
 *
 * Solo se usa para decidir que locales estan cerrados. El NOMBRE de cada
 * sucursal no sale de aca sino de RO_T_RESUMEN_FINAL_IE (ver Canales), porque
 * Franquicias, Mayoristas y Ecommerce no estan en este maestro.
 */
require_once __DIR__ . '/BaseIE.php';

class Sucursales {

    /**
     * @return array|null [nro => HABILITADO (0|1|null)] de los locales PROPIOS;
     *         null si no se pudo leer (el informe sigue, sin cerradas, y avisa)
     */
    public static function maestroPropios() {
        try {
            $cid = BaseIE::conectar('locales');
            $p = BaseIE::conexion()->prefijoLocales();
            $filas = BaseIE::filas($cid, "SELECT NRO_SUCURSAL, HABILITADO FROM {$p}SUCURSALES_LAKERS WHERE CANAL = 'PROPIOS'");
        } catch (Throwable $t) {
            return null;
        }

        $out = [];

        foreach ($filas as $f) {
            $out[(int) $f['NRO_SUCURSAL']] = $f['HABILITADO'] === null ? null : (int) $f['HABILITADO'];
        }

        return $out;
    }
}
