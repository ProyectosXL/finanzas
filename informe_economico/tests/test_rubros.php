<?php
require_once __DIR__ . '/../Class/Rubros.php';

seccion('Orden natural de COD_RUBRO');
chequear('7.9 < 7.10 < 7.11', ['7.1.', '7.2.', '7.9.', '7.10.', '7.11.'], Rubros::ordenar(['7.10.', '7.2.', '7.11.', '7.1.', '7.9.']));
chequear('5.2.1.5 < 5.3.1', ['5.1.7.', '5.2.1.1.', '5.2.1.5.', '5.3.1.'], Rubros::ordenar(['5.3.1.', '5.2.1.5.', '5.1.7.', '5.2.1.1.']));
chequear('4.1.1 < 4.2 y 4.3 < 4.3.1', ['4.1.1.', '4.2.', '4.3.', '4.3.1.'], Rubros::ordenar(['4.3.1.', '4.2.', '4.3.', '4.1.1.']));
chequear('Un codigo mas corto va antes', -1, Rubros::comparar('6.', '6.1.'));

seccion('Secciones y rubros sin seccion');
$maestro = [
    '1.5.' => ['nombre' => 'Ventas', 'cat' => null, 'activo' => 1],
    '2.' => ['nombre' => 'Costo', 'cat' => null, 'activo' => 1],
    '7.10.' => ['nombre' => 'Telefonia', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '7.9.' => ['nombre' => 'Movilidad', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '5.3.4.' => ['nombre' => 'Gastos varios', 'cat' => 'Otros Gastos Operativos', 'activo' => 0],
    '5.3.9.' => ['nombre' => 'Inactivo sin datos', 'cat' => 'Otros Gastos Operativos', 'activo' => 0],
    '8.1.' => ['nombre' => 'Sin categoria', 'cat' => null, 'activo' => 1]
];
$c = Rubros::clasificar(['1.5.', '2.', '7.10.', '5.3.4.', '8.1.', '9.9.'], $maestro);
chequear('Seccion ordenada por codigo, con los activos aunque no tengan datos', ['7.9.', '7.10.'], $c['porSeccion']['Gastos de Estructura']);
chequear('Un inactivo entra solo si tiene datos', ['5.3.4.'], $c['porSeccion']['Otros Gastos Operativos']);
chequear('Sin CAT o sin maestro van a Sin seccion; los fijos no', ['8.1.', '9.9.'], $c['sinSeccion']);
chequear('Los de ventas y costo no son "sin seccion" aunque tengan CAT NULL', false, in_array('2.', $c['sinSeccion'], true));

/* ------------------------------------------------------------------
   Alta, nombre, baja: las reglas puras. El maestro de prueba copia la
   numeracion real de cada categoria (verificada contra la base el
   06/11/2026), para que la propuesta se pruebe con los casos de verdad.
   ------------------------------------------------------------------ */
$real = [
    '1.5.' => ['nombre' => 'Ventas Minoristas sin IVA', 'cat' => null, 'activo' => 1],
    '2.' => ['nombre' => 'Costo de Ventas', 'cat' => null, 'activo' => 1],
    '4.1.1.' => ['nombre' => 'Arancel', 'cat' => 'Gastos de Comercialización', 'activo' => 1],
    '4.3.1.' => ['nombre' => 'Fotografia', 'cat' => 'Gastos de Comercialización', 'activo' => 1],
    '4.6.' => ['nombre' => 'Rendicion Supervisoras', 'cat' => 'Gastos de Comercialización', 'activo' => 1],
    '5.1.3.' => ['nombre' => 'Provision SAC', 'cat' => 'Gastos de Personal', 'activo' => 1],
    '5.1.7.' => ['nombre' => 'Reclutamiento', 'cat' => 'Gastos de Personal', 'activo' => 1],
    '5.2.1.5.' => ['nombre' => 'Impuestos y Tasas', 'cat' => 'Gastos de Ocupación', 'activo' => 1],
    '5.3.4.' => ['nombre' => 'Gastos varios', 'cat' => 'Otros Gastos Operativos', 'activo' => 0],
    '6.' => ['nombre' => 'Bienes de Uso', 'cat' => 'Bienes de Uso', 'activo' => 1],
    '7.5.' => ['nombre' => 'Provision SAC', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '7.9.' => ['nombre' => 'Movilidad', 'cat' => 'Gastos de Estructura', 'activo' => 1],
    '7.11.' => ['nombre' => 'Gastos bancarios', 'cat' => 'Gastos de Estructura', 'activo' => 1]
];
$cats = ['Bienes de Uso', 'Gastos de Comercialización', 'Gastos de Estructura', 'Gastos de Ocupación', 'Gastos de Personal', 'Otros Gastos Operativos'];

seccion('Codigo propuesto');
chequear('7.11. -> 7.12.', '7.12.', Rubros::proponerCodigo(['7.9.', '7.11.', '7.5.'], array_keys($real)));
chequear('4.3.1. -> 4.3.2.', '4.3.2.', Rubros::proponerCodigo(['4.3.1.'], []));
chequear('7.9. -> 7.10. (orden natural, no de texto)', '7.10.', Rubros::proponerCodigo(['7.9.', '7.1.'], []));
chequear('El ultimo es el de orden natural: 4.6. y no 4.3.1.', '4.7.', Rubros::proponerCodigo(['4.1.1.', '4.3.1.', '4.6.'], []));
chequear('5.2.1.5. -> 5.2.1.6.', '5.2.1.6.', Rubros::proponerCodigo(['5.2.1.5.'], []));
chequear('Salta los ocupados del maestro y del resumen', '7.14.', Rubros::proponerCodigo(['7.11.'], ['7.12.', '7.13.']));
chequear('Compara normalizado: un ocupado con espacios cuenta igual', '7.13.', Rubros::proponerCodigo(['7.11.'], [' 7.12. ']));
chequear('Los inactivos cuentan para la numeracion', '5.3.5.', Rubros::proponerCodigo(['5.3.4.'], []));
chequear('Categoria sin rubros: no propone', null, Rubros::proponerCodigo([], ['7.1.']));
chequear('Un solo segmento (6. en Bienes de Uso): no propone 7.', null, Rubros::proponerCodigo(['6.'], []));
chequear('Si excede el largo (8): no propone', null, Rubros::proponerCodigo(['5.2.1.9.'], []));
chequear('Si salta hasta exceder el largo: no propone', null, Rubros::proponerCodigo(['5.2.1.8.'], ['5.2.1.9.']));

seccion('Normalizacion y validacion del codigo');
chequear('Sin punto final se le agrega', '7.12.', Rubros::normalizarCodigo('7.12'));
chequear('Se quitan los espacios', '7.12.', Rubros::normalizarCodigo(' 7. 12 '));
chequear('Valido, normalizado', ['codigo' => '7.12.', 'error' => null], Rubros::validarCodigo(' 7.12', $real));
foreach (['7.a.', '7..12.', '.7.', '7,12', '7.09.', '7.0.', 'x'] as $mal) {
    chequear('Formato invalido: "' . $mal . '"', true, Rubros::validarCodigo($mal, $real)['error'] !== null);
}
chequear('Vacio', 'El código es obligatorio.', Rubros::validarCodigo('  ', $real)['error']);
foreach (['1.9.', '1.5.', '2.', '2.1.'] as $vc) {
    chequear($vc . ' rechazado: es de ventas o costo', true, strpos((string) Rubros::validarCodigo($vc, $real)['error'], 'ventas y costo') !== false);
}
chequear('12. no es "1." ni "2."', null, Rubros::validarCodigo('12.', $real)['error']);
chequear('Repetido en el maestro', 'El código 7.9. ya existe en el maestro.', Rubros::validarCodigo('7.9', $real)['error']);
chequear('Mas de 8 caracteres', true, strpos((string) Rubros::validarCodigo('5.2.1.10.', $real)['error'], 'más de 8') !== false);
chequear('8 caracteres justos pasa', null, Rubros::validarCodigo('5.2.1.6.', $real)['error']);

seccion('Coherencia codigo / categoria (aviso, no bloquea)');
chequear('Prefijo comun de Personal', '5.1.', Rubros::prefijoComun(['5.1.3.', '5.1.7.']));
chequear('Prefijo comun de Comercializacion', '4.', Rubros::prefijoComun(['4.1.1.', '4.3.1.', '4.6.']));
chequear('Sin codigos no hay prefijo', '', Rubros::prefijoComun([]));
chequear('Un 7.x en Personal avisa', true, Rubros::avisoCoherencia('7.20.', 'Gastos de Personal', ['5.1.3.', '5.1.7.']) !== null);
chequear('Un 5.2.x en Personal avisa aunque empiece con 5', true, Rubros::avisoCoherencia('5.2.9.', 'Gastos de Personal', ['5.1.3.', '5.1.7.']) !== null);
chequear('Un 5.1.8. en Personal no avisa', null, Rubros::avisoCoherencia('5.1.8.', 'Gastos de Personal', ['5.1.3.', '5.1.7.']));
chequear('41.1. no pasa por 4. (se compara por segmento)', true, Rubros::avisoCoherencia('41.1.', 'Gastos de Comercialización', ['4.1.1.', '4.6.']) !== null);
chequear('Categoria sin rubros: sin aviso', null, Rubros::avisoCoherencia('9.1.', 'Nueva', []));

seccion('Nombre');
chequear('Se quitan los espacios de los bordes', 'Seguros', Rubros::validarNombre('  Seguros ', $real)['nombre']);
chequear('Vacio', 'El nombre es obligatorio.', Rubros::validarNombre('   ', $real)['error']);
chequear('50 caracteres pasa', null, Rubros::validarNombre(str_repeat('á', 50), $real)['error']);
chequear('51 caracteres no', true, Rubros::validarNombre(str_repeat('a', 51), $real)['error'] !== null);
chequear('Un emoji no se puede guardar en VARCHAR', true, Rubros::validarNombre("Gastos \u{1F600}", $real)['error'] !== null);
chequear('Tildes y enie si', null, Rubros::validarNombre('Señalética y Comunicación', $real)['error']);
chequear('Repetido sin distinguir mayusculas: aviso', true, Rubros::validarNombre('MOVILIDAD', $real)['aviso'] !== null);
chequear('Repetido no es error', null, Rubros::validarNombre('MOVILIDAD', $real)['error']);
chequear('Al renombrar no se compara consigo mismo', null, Rubros::validarNombre('movilidad', $real, '7.9.')['aviso']);

seccion('Alta completa');
$a = Rubros::revisarAlta('7.12', 'Seguros', 'Gastos de Estructura', $real, $cats);
chequear('Alta valida, sin avisos', ['cod' => '7.12.', 'nombre' => 'Seguros', 'cat' => 'Gastos de Estructura', 'errores' => [], 'avisos' => []], $a);
chequear('Categoria obligatoria', true, in_array('Elegí una categoría.', Rubros::revisarAlta('7.12.', 'X', '', $real, $cats)['errores'], true));
chequear('Categoria inexistente', 1, count(Rubros::revisarAlta('7.12.', 'X', 'Inventada', $real, $cats)['errores']));
$b = Rubros::revisarAlta('7.20.', 'Provision SAC', 'Gastos de Personal', $real, $cats);
chequear('Fuera de numeracion y nombre repetido: dos avisos, ningun error', [[], 2], [$b['errores'], count($b['avisos'])]);
chequear('Con errores no se calculan avisos', [], Rubros::revisarAlta('7.9.', 'Movilidad', 'Gastos de Personal', $real, $cats)['avisos']);

seccion('Los fijos (1.x y 2) no se dan de alta, ni se renombran, ni se dan de baja');
chequear('Alta de 1.9. rechazada', 1, count(Rubros::revisarAlta('1.9.', 'Otra venta', 'Gastos de Estructura', $real, $cats)['errores']));
chequear('Alta de 2. rechazada', true, count(Rubros::revisarAlta('2.', 'Costo', 'Gastos de Estructura', $real, $cats)['errores']) > 0);
chequear('Renombrar 1.5. rechazado', [Rubros::MSJ_NO_EDITABLE], Rubros::revisarNombre('1.5.', 'Ventas', $real)['errores']);
chequear('Renombrar 2. rechazado', [Rubros::MSJ_NO_EDITABLE], Rubros::revisarNombre('2.', 'Costo', $real)['errores']);
chequear('Baja de 1.5. y de 2. rechazada', [Rubros::MSJ_NO_EDITABLE, Rubros::MSJ_NO_EDITABLE], [Rubros::errorNoEditable('1.5.'), Rubros::errorNoEditable('2.')]);
chequear('Un gasto si se edita', null, Rubros::errorNoEditable('7.9.'));

seccion('Cambio de nombre');
chequear('Valido', ['cod' => '7.9.', 'nombre' => 'Movilidad y viaticos', 'errores' => [], 'avisos' => []], Rubros::revisarNombre('7.9.', ' Movilidad y viaticos ', $real));
chequear('Rubro que no esta en el maestro', 1, count(Rubros::revisarNombre('9.9.', 'X', $real)['errores']));
chequear('Nombre de otro rubro: aviso', 1, count(Rubros::revisarNombre('7.9.', 'provision sac', $real)['avisos']));

seccion('Motivo de baja y reactivacion');
chequear('Obligatorio', 'El motivo es obligatorio.', Rubros::validarMotivo("  \n ")['error']);
chequear('Con texto pasa', null, Rubros::validarMotivo('No se usa mas')['error']);
chequear('Mas de 500 no', true, Rubros::validarMotivo(str_repeat('x', 501))['error'] !== null);

seccion('Un rubro nuevo entra solo en su seccion');
$conNuevo = $real;
$conNuevo['7.12.'] = ['nombre' => 'Seguros', 'cat' => 'Gastos de Estructura', 'activo' => 1];
$c = Rubros::clasificar(['1.5.', '7.9.'], $conNuevo);
chequear('Activo, con categoria y sin datos: aparece, ordenado', ['7.5.', '7.9.', '7.11.', '7.12.'], $c['porSeccion']['Gastos de Estructura']);
chequear('Y no esta en Sin seccion', false, in_array('7.12.', $c['sinSeccion'], true));
chequear('Un inactivo sin datos sigue sin mostrarse (regla existente)', false, isset($c['porSeccion']['Otros Gastos Operativos']));
$antes = Rubros::clasificar(['7.9.', '8.1.'], $real);
$conAlta = $real;
$conAlta['8.1.'] = ['nombre' => 'Del resumen', 'cat' => 'Gastos de Estructura', 'activo' => 1];
$despues = Rubros::clasificar(['7.9.', '8.1.'], $conAlta);
chequear('"No esta en el maestro": antes, sin seccion', ['8.1.'], $antes['sinSeccion']);
chequear('Al darlo de alta deja de estar sin seccion', [], $despues['sinSeccion']);
chequear('Y pasa a su seccion', 'Gastos de Estructura', $despues['seccionDe']['8.1.']);
chequear('ACTIVO NULL cuenta como activo', true, Rubros::esActivo(['activo' => null]));

seccion('Pantalla: ultimo cambio, ultimo periodo y nombre sugerido');
chequear('El mas reciente entre las dos auditorias', ['7.9.' => ['usuario' => 'b', 'fecha' => '2026-11-02 10:00:00'], '4.6.' => ['usuario' => 'a', 'fecha' => '2026-01-01 00:00:00']],
    Rubros::ultimoMovimiento(['7.9.' => ['usuario' => 'a', 'fecha' => '2026-11-01 10:00:00'], '4.6.' => ['usuario' => 'a', 'fecha' => '2026-01-01 00:00:00']],
        ['7.9.' => ['usuario' => 'b', 'fecha' => '2026-11-02 10:00:00']]));
chequear('Sin auditorias, nada', [], Rubros::ultimoMovimiento());
chequear('Ultimo periodo cronologico, no de texto', '12-2025', Rubros::ultimoPeriodo(['9-2025', '12-2025', '1-2025', 'basura']));
chequear('Sin periodos, null', null, Rubros::ultimoPeriodo([]));
chequear('Nombre del periodo mas reciente, ignorando vacios', 'Nuevo',
    Rubros::nombreMasReciente([['periodo' => '9-2025', 'nombre' => 'Viejo'], ['periodo' => '12-2025', 'nombre' => 'Nuevo '], ['periodo' => '1-2026', 'nombre' => '']]));
chequear('Sin nombre, null', null, Rubros::nombreMasReciente([['periodo' => '1-2026', 'nombre' => null]]));
