<?php
require_once __DIR__ . '/../Class/Periodo.php';

seccion('Validacion (las mismas reglas que rentabilidad_rubro)');
chequear('1-2025 es valido', true, Periodo::valido('1-2025'));
chequear('12-2025 es valido', true, Periodo::valido('12-2025'));
chequear('13-2025 no', false, Periodo::valido('13-2025'));
chequear('0-2025 no', false, Periodo::valido('0-2025'));
chequear('2025-01 no', false, Periodo::valido('2025-01'));
chequear('1-1999 no', false, Periodo::valido('1-1999'));
chequear('Desde mayor que Hasta', 'El período Desde no puede ser mayor que el período Hasta.', Periodo::validarRango('3-2026', '1-2026'));
chequear('Rango valido', null, Periodo::validarRango('1-2026', '3-2026'));
chequear('Normaliza el cero adelante', '6-2026', Periodo::normalizar('06-2026'));

seccion('Generar el rango');
chequear('Cruza de anio', ['11-2025', '12-2025', '1-2026', '2-2026'], Periodo::generar('11-2025', '2-2026'));
chequear('Un solo mes', ['6-2026'], Periodo::generar('6-2026', '6-2026'));

seccion('Rango del anio anterior');
chequear('Mismo rango 12 meses antes', ['1-2025', '2-2025', '3-2025'], Periodo::anioAnterior(['1-2026', '2-2026', '3-2026']));
chequear('Con cruce de anio', ['11-2024', '12-2024', '1-2025'], Periodo::anioAnterior(['11-2025', '12-2025', '1-2026']));
chequear('Desplazar enero para atras', '12-2025', Periodo::desplazar('1-2026', -1));
chequear('Rotulo corto', 'Jun-26', Periodo::corto('6-2026'));

seccion('Meses con resumen (criterio de existeResumen)');
chequear('Un mes con solo paso 2 no tiene resumen', ['6-2026'], Periodo::conResumen([
    '3-2026' => ['1.1.', '1.2.', '1.6.', '1.8.'],
    '6-2026' => ['1.1.', '2.', '5.1.1.']
]));
chequear('Con un rubro fuera del paso 2 alcanza', ['4-2026'], Periodo::conResumen(['4-2026' => ['7.1.']]));
