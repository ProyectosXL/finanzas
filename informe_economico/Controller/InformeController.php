<?php
/**
 * InformeController.php
 * Las cuatro vistas del informe y la edicion de importes.
 *
 *   action=informe    vista=canales|locales|mensual|dashboard  (ie.tab.<vista>)
 *   action=detalle    el detalle de una celda                  (ie.editar)
 *   action=editar     cambia un importe                        (ie.editar)
 *   action=deshacer   un paso atras en su historial            (ie.editar)
 *   action=restaurar  vuelve al valor del proceso              (ie.editar)
 */
require_once __DIR__ . '/comun.php';
require_once __DIR__ . '/../Class/Periodo.php';
require_once __DIR__ . '/../Class/InformeEconomico.php';
require_once __DIR__ . '/../Class/Ediciones.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieError('Método no permitido', 405);
}

$accion = iePost('action');

switch ($accion) {

    case 'informe':
        $vista = iePost('vista');

        if (!in_array($vista, ['canales', 'locales', 'mensual', 'dashboard'], true)) {
            ieError('Vista no válida.');
        }

        ieExigir('ie.tab.' . $vista);

        $desde = trim(iePost('desde'));
        $hasta = trim(iePost('hasta'));
        $err = Periodo::validarRango($desde, $hasta);

        if ($err !== null) {
            ieError($err);
        }

        $f = [
            'vista' => $vista,
            'desde' => Periodo::normalizar($desde),
            'hasta' => Periodo::normalizar($hasta),
            'moneda' => iePost('moneda') === 'USD' ? 'USD' : 'ARS',
            'comparar' => iePost('comparar') === '1',
            'cerradas' => iePost('cerradas') === '1',
            'canal' => iePost('canal', 'TODOS')
        ];

        $cid = ieConexion();

        try {
            $ie = new InformeEconomico($cid);
            $out = $vista === 'dashboard' ? $ie->dashboard($f) : $ie->generar($f);
            $out['puedeEditar'] = AuthInformeEconomico::puedeEditar();
            ieResponder($out);
        } catch (Throwable $t) {
            ieError($t->getMessage(), 500);
        }
        break;

    case 'detalle':
        ieExigir(AuthInformeEconomico::EDITAR);
        $cid = ieConexion();

        $sucursales = json_decode(iePost('sucursales', '[]'), true);
        $periodos = json_decode(iePost('periodos', '[]'), true);
        $cod = trim(iePost('cod'));

        if (!is_array($sucursales) || !is_array($periodos) || $cod === '') {
            ieError('Faltan datos de la celda.');
        }

        try {
            ieResponder(['ok' => true] + (new Ediciones($cid))->detalle($sucursales, $periodos, $cod));
        } catch (Throwable $t) {
            ieError($t->getMessage(), 500);
        }
        break;

    case 'editar':
    case 'deshacer':
    case 'restaurar':
        ieExigir(AuthInformeEconomico::EDITAR);
        $cid = ieConexion();

        $tipos = ['editar' => HistorialEdicion::EDICION, 'deshacer' => HistorialEdicion::DESHACER, 'restaurar' => HistorialEdicion::RESTAURAR];

        try {
            $r = (new Ediciones($cid))->aplicar(
                (int) iePost('id'),
                iePost('visto'),
                iePost('importe', null),
                iePost('motivo'),
                $tipos[$accion],
                AuthInformeEconomico::username()
            );
            ieResponder(['ok' => true] + $r);
        } catch (InvalidArgumentException $e) {
            ieError($e->getMessage(), 422);
        } catch (Throwable $t) {
            // Concurrencia, registro borrado, reproceso: 409 para que el
            // front sepa que tiene que recargar.
            ieError($t->getMessage(), 409);
        }
        break;

    default:
        ieError('Acción no válida.');
}
