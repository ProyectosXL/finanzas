<?php
/**
 * ParametrosController.php
 * La pestana Parametros: ver requiere ie.tab.parametros; guardar, ademas,
 * ie.editar.
 *
 *   action=listar             estructura, rubros, categorias, umbrales y X
 *   action=guardar_fila       orden, etiqueta, visible y activo de una fila
 *   action=guardar_categoria  CAT_RUBRO_CONTABLE de un rubro (con auditoria)
 *   action=guardar_umbral     bandas de un indicador
 *   action=guardar_caida      el X de la alerta de caida de rentabilidad
 */
require_once __DIR__ . '/comun.php';
require_once __DIR__ . '/../Class/EstructuraFilas.php';
require_once __DIR__ . '/../Class/Rubros.php';
require_once __DIR__ . '/../Class/ParametrosIE.php';
require_once __DIR__ . '/../Class/Formulas.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ieError('Método no permitido', 405);
}

$accion = iePost('action');

if ($accion === 'listar') {
    ieExigir('ie.tab.parametros');
} else {
    ieExigir('ie.tab.parametros', AuthInformeEconomico::EDITAR);
}

$cid = ieConexion();
$usuario = AuthInformeEconomico::username();

try {
    switch ($accion) {

        case 'listar':
            $avisos = [];
            $estructura = null;

            if (EstructuraFilas::existe($cid)) {
                $estructura = EstructuraFilas::leer($cid);
            } else {
                $avisos[] = 'Falta correr sql/ie_estructura.sql: no hay estructura para editar y el informe no se puede armar.';
            }

            // Rubros del maestro + los que aparecen en el resumen sin estar en
            // el maestro. Los que no tienen seccion van primero: son los que
            // hay que resolver.
            $maestro = Rubros::leerMaestro($cid);
            $presentes = [];

            foreach (BaseIE::filas($cid, 'SELECT COD_RUBRO, COUNT(*) AS N, MAX(PERIODO) AS P FROM RO_T_RESUMEN_FINAL_IE GROUP BY COD_RUBRO') as $r) {
                $presentes[$r['COD_RUBRO']] = (int) $r['N'];
            }

            $clasif = Rubros::clasificar(array_keys($presentes), $maestro);
            $sinSec = array_flip($clasif['sinSeccion']);
            $rubros = [];

            foreach (array_unique(array_merge(array_keys($maestro), array_keys($presentes))) as $cod) {
                $rubros[] = [
                    'cod' => (string) $cod,
                    'nombre' => $maestro[$cod]['nombre'] ?? null,
                    'cat' => $maestro[$cod]['cat'] ?? null,
                    'enMaestro' => isset($maestro[$cod]),
                    'activo' => isset($maestro[$cod]) ? (int) $maestro[$cod]['activo'] : null,
                    'registros' => $presentes[$cod] ?? 0,
                    'fijo' => Rubros::esFijo((string) $cod),
                    'sinSeccion' => isset($sinSec[$cod])
                ];
            }

            usort($rubros, function ($a, $b) {
                return ($b['sinSeccion'] <=> $a['sinSeccion']) ?: Rubros::comparar($a['cod'], $b['cod']);
            });

            $umbrales = ParametrosIE::umbrales($cid);
            $caida = ParametrosIE::caidaPp($cid);

            if ($umbrales === null) {
                $avisos[] = 'Falta correr sql/ie_umbrales.sql: el ranking se muestra sin colores.';
            }

            if (!BaseIE::existe($cid, Rubros::TABLA_AUDITORIA)) {
                $avisos[] = 'Falta correr sql/ie_resumen_edicion.sql: la categoría de los rubros se ve, pero no se puede cambiar (no habría auditoría).';
            }

            ieResponder([
                'ok' => true,
                'estructura' => $estructura,
                'catalogo' => Formulas::catalogo(),
                'rubros' => $rubros,
                'categorias' => Rubros::categorias($cid),
                'umbrales' => $umbrales,
                'caidaPp' => $caida,
                'puedeEditar' => AuthInformeEconomico::puedeEditar(),
                'avisos' => $avisos
            ]);
            break;

        case 'guardar_fila':
            EstructuraFilas::guardar($cid, (int) iePost('id'), iePost('orden'), iePost('etiqueta'),
                iePost('visible') === '1', iePost('activo') === '1', $usuario);
            ieResponder(['ok' => true]);
            break;

        case 'guardar_categoria':
            $cambio = Rubros::guardarCategoria($cid, trim(iePost('cod')), iePost('cat'), $usuario);
            ieResponder(['ok' => true, 'cambio' => $cambio]);
            break;

        case 'guardar_umbral':
            $bandas = json_decode(iePost('bandas', '{}'), true);

            if (!is_array($bandas)) {
                ieError('Bandas inválidas.');
            }

            ParametrosIE::guardarUmbral($cid, (int) iePost('id'), $bandas, $usuario);
            ieResponder(['ok' => true]);
            break;

        case 'guardar_caida':
            ParametrosIE::guardarCaidaPp($cid, iePost('valor'), $usuario);
            ieResponder(['ok' => true]);
            break;

        default:
            ieError('Acción no válida.');
    }
} catch (InvalidArgumentException $e) {
    ieError($e->getMessage(), 422);
} catch (Throwable $t) {
    ieError($t->getMessage(), 500);
}
