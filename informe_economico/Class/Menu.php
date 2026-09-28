<?php
/**
 * Menu
 * Las pestanas del Informe Economico, como dato.
 *
 * PARA AGREGAR UNA PESTANA
 * ------------------------
 * Una entrada aca, su archivo en Tabs/ y su JS. Nada mas: index.php dibuja las
 * solapas desde esta lista, TabController solo sirve las que estan aca y cada
 * una pide su propio permiso.
 *
 * Esta pensado para la migracion de administracion/contabilidad/
 * rentabilidad_rubro, que va a ser una pestana mas:
 *
 *   ['tab' => 'rentabilidad_rubro', 'nombre' => 'Rentabilidad por Rubro',
 *    'icono' => 'bi-grid-3x3-gap', 'permiso' => 'ie.tab.rentabilidad_rubro',
 *    'filtros' => true]
 *
 * (mas su permiso en sql/ie_permisos.sql). Esa migracion NO se hizo.
 *
 * 'filtros' dice si la pestana usa la barra de filtros comun (periodo,
 * moneda, comparativo, cerradas). Parametros no la usa y la barra se oculta.
 */
require_once __DIR__ . '/AuthInformeEconomico.php';

class Menu {

    private static $pestanas = [
        ['tab' => 'canales',    'nombre' => 'IE por Canales',    'icono' => 'bi-diagram-3',       'permiso' => 'ie.tab.canales',    'filtros' => true],
        ['tab' => 'locales',    'nombre' => 'IE por Locales',    'icono' => 'bi-shop',            'permiso' => 'ie.tab.locales',    'filtros' => true],
        ['tab' => 'mensual',    'nombre' => 'Evolución Mensual', 'icono' => 'bi-calendar3-range', 'permiso' => 'ie.tab.mensual',    'filtros' => true],
        ['tab' => 'dashboard',  'nombre' => 'Dashboard',         'icono' => 'bi-speedometer2',    'permiso' => 'ie.tab.dashboard',  'filtros' => true],
        ['tab' => 'parametros', 'nombre' => 'Parámetros',        'icono' => 'bi-sliders',         'permiso' => 'ie.tab.parametros', 'filtros' => false]
    ];

    /** Todas, sin filtrar: es un hecho del codigo (lo usan las pruebas) */
    public static function todas() {
        return self::$pestanas;
    }

    /** Las que puede ver el usuario de la sesion */
    public static function permitidas() {
        return array_values(array_filter(self::$pestanas, function ($p) {
            return AuthInformeEconomico::puede($p['permiso']);
        }));
    }

    /** La definicion de una pestana, o null si no existe */
    public static function buscar($tab) {
        foreach (self::$pestanas as $p) {
            if ($p['tab'] === $tab) {
                return $p;
            }
        }

        return null;
    }
}
