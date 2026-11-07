<?php
/**
 * ParametrosController.php
 * La pestana Parametros: ver requiere ie.tab.parametros; guardar, ademas,
 * ie.editar.
 *
 *   action=listar             estructura, rubros, categorias, umbrales y X
 *   action=guardar_fila       orden, etiqueta, visible y activo de una fila
 *   action=guardar_categoria  CAT_RUBRO_CONTABLE de un rubro (con auditoria)
 *   action=proponer_codigo    categoria -> codigo propuesto y sus rubros
 *   action=alta_rubro         alta en el maestro (con auditoria)
 *   action=guardar_nombre     RUBRO_CONTABLE de un rubro (con auditoria)
 *   action=info_baja          cuentas relacionadas y registros, para el modal
 *   action=cambiar_estado     baja / reactivacion, con motivo (con auditoria)
 *   action=guardar_umbral     bandas de un indicador
 *
 * Alta y nombre: si hay avisos (codigo fuera de la numeracion, nombre
 * repetido) y no viene confirmar=1, no se escribe nada y se responde
 * {ok:false, confirmar:true, avisos}; la pantalla pregunta y reenvia. Asi
 * las reglas viven en un solo lugar (Rubros) y no duplicadas en el JS.
 *
 * Uruguay: ieConexion() corta antes del switch, asi que ninguna accion llega
 * a escribir; Rubros vuelve a chequearlo (ver exigirEscritura).
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
            $ultimos = Rubros::ultimosCambios($cid);
            $sugeridos = Rubros::nombresSugeridos($cid, array_values(array_map('strval', array_diff(array_keys($presentes), array_keys($maestro)))));
            $rubros = [];

            foreach (array_unique(array_merge(array_keys($maestro), array_keys($presentes))) as $cod) {
                $cod = (string) $cod;
                $rubros[] = [
                    'cod' => $cod,
                    'nombre' => $maestro[$cod]['nombre'] ?? null,
                    'cat' => $maestro[$cod]['cat'] ?? null,
                    'enMaestro' => isset($maestro[$cod]),
                    'activo' => isset($maestro[$cod]) ? (Rubros::esActivo($maestro[$cod]) ? 1 : 0) : null,
                    'registros' => $presentes[$cod] ?? 0,
                    // fijo = 1.x y 2: ni categoria, ni nombre, ni baja
                    'fijo' => Rubros::errorNoEditable($cod) !== null,
                    'sinSeccion' => isset($sinSec[$cod]),
                    'ultimo' => $ultimos[$cod] ?? null,
                    'nombreSugerido' => $sugeridos[$cod] ?? null
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

            $auditoriaRubros = Rubros::hayAuditoriaRubros($cid);

            if (!$auditoriaRubros) {
                $avisos[] = 'Falta correr ' . Rubros::SCRIPT_AUDITORIA_RUBRO . ': los rubros se ven y se les puede cambiar la categoría, '
                    . 'pero no se pueden dar de alta, renombrar, dar de baja ni reactivar (no habría auditoría).';
            }

            ieResponder([
                'ok' => true,
                'estructura' => $estructura,
                'catalogo' => Formulas::catalogo(),
                'rubros' => $rubros,
                'categorias' => Rubros::categorias($cid),
                'auditoriaRubros' => $auditoriaRubros,
                'scriptAuditoriaRubros' => Rubros::SCRIPT_AUDITORIA_RUBRO,
                'largos' => ['codigo' => Rubros::LARGO_CODIGO, 'nombre' => Rubros::LARGO_NOMBRE, 'motivo' => Rubros::LARGO_MOTIVO],
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

        case 'proponer_codigo':
            ieResponder(['ok' => true] + Rubros::proponer($cid, iePost('cat')));
            break;

        case 'alta_rubro':
            ieResponder(Rubros::alta($cid, iePost('cod'), iePost('nombre'), iePost('cat'), iePost('confirmar') === '1', $usuario));
            break;

        case 'guardar_nombre':
            ieResponder(Rubros::guardarNombre($cid, trim(iePost('cod')), iePost('nombre'), iePost('confirmar') === '1', $usuario));
            break;

        case 'info_baja':
            ieResponder(['ok' => true] + Rubros::infoBaja($cid, trim(iePost('cod'))));
            break;

        case 'cambiar_estado':
            $activo = iePost('activo');

            if ($activo !== '0' && $activo !== '1') {
                ieError('Estado inválido.', 422);
            }

            Rubros::cambiarEstado($cid, trim(iePost('cod')), $activo === '1', iePost('motivo'), $usuario);
            ieResponder(['ok' => true]);
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
