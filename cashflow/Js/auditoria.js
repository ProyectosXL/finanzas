/**
 * Quién modificó y cuándo, dicho igual en toda la pantalla.
 *
 * Todas las tablas del módulo guardan el mismo esquema -USUARIO_ALTA /
 * FECHA_ALTA, USUARIO_MODIF / FECHA_MODIF, USUARIO_BAJA / FECHA_BAJA-, y este
 * componente es el único que lo convierte en texto. Sin él, cada pestaña
 * armaba su propia frase ("por X el …", "— X", "desde el cashflow") y la misma
 * información se leía distinto en cada lado.
 *
 * DOS FORMAS, Y NINGUNA OCUPA LUGAR:
 *   - Auditoria.titulo(...) devuelve el texto para el title de una celda que
 *     ya existe: una fecha, un importe, un ajuste cargado a mano.
 *   - Auditoria.icono(...) devuelve un ícono chico con ese title, para las
 *     grillas de Parámetros y los maestros, donde la fila no tiene una celda
 *     natural para colgarlo.
 *
 * LOS PROCESOS NO SON PERSONAS. Un valor 'JOB:...' o 'SISTEMA:...' se muestra
 * como "Proceso automático (…)", con el nombre que declara
 * AuthCashflow::ORIGENES y que index.php deja en window.CASHFLOW_ORIGENES.
 *
 * UNA FILA DE ANTES DE LA AUDITORÍA no tiene usuario, y se dice: "sin usuario
 * registrado". No se inventa uno.
 */
(function () {
    'use strict';

    var VERBO = {
        alta: 'Cargado',
        modif: 'Modificado',
        baja: 'Dado de baja'
    };

    /** 'aaaa-mm-dd hh:mm[:ss]' o un Date de PHP serializado -> 'dd/mm/aaaa hh:mm' */
    function fecha(f) {
        if (!f) { return ''; }

        if (typeof f === 'object' && f.date) { f = f.date; }

        var s = String(f);
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);

        if (!m) { return s; }

        return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
    }

    /** El nombre con el que se muestra quien hizo el cambio */
    function quien(usuario) {
        var u = (usuario === null || usuario === undefined) ? '' : String(usuario).trim();

        if (u === '') { return ''; }

        if (/^(JOB|SISTEMA):/.test(u)) {
            var origenes = window.CASHFLOW_ORIGENES || {};
            var nombre = origenes[u] || u.substring(u.indexOf(':') + 1);

            return 'Proceso automático (' + nombre + ')';
        }

        return u;
    }

    /**
     * Una línea: "Modificado por X · dd/mm/aaaa hh:mm".
     *
     * @param {string} tipo 'alta', 'modif' o 'baja'
     * @param {string|null} usuario
     * @param {string|null} cuando
     * @returns {string} '' si no hay ni usuario ni fecha
     */
    function linea(tipo, usuario, cuando) {
        var u = quien(usuario);
        var f = fecha(cuando);

        if (!u && !f) { return ''; }

        return (VERBO[tipo] || VERBO.modif)
            + (u ? ' por ' + u : '')
            + (f ? ' · ' + f : '')
            + (u ? '' : ' (sin usuario registrado)');
    }

    /**
     * El texto completo, una línea por cada momento que tenga datos.
     *
     * Acepta lo que traiga cada payload, en cualquiera de estas formas:
     *   { usuario, fecha }                               -> una modificación
     *   { alta: {usuario, fecha}, modif: {...}, baja: {...} }
     *   una fila con USUARIO_ALTA, FECHA_ALTA, USUARIO_MODIF, ... (tal cual)
     *
     * La modificación no se repite cuando es la misma que el alta: una fila
     * que nadie tocó despues de cargarla dice "Cargado por …" y nada más.
     *
     * @returns {string}
     */
    function texto(dato) {
        if (!dato) { return ''; }

        var d = normalizar(dato);
        var lineas = [];

        if (d.alta) { lineas.push(linea('alta', d.alta.usuario, d.alta.fecha)); }

        if (d.modif && !(d.alta && mismo(d.alta, d.modif))) {
            lineas.push(linea('modif', d.modif.usuario, d.modif.fecha));
        }

        if (d.baja) { lineas.push(linea('baja', d.baja.usuario, d.baja.fecha)); }

        return lineas.filter(function (l) { return l !== ''; }).join('\n');
    }

    function normalizar(x) {
        if (x.alta || x.modif || x.baja) { return x; }

        if ('USUARIO_ALTA' in x || 'USUARIO_MODIF' in x || 'USUARIO_BAJA' in x
            || 'FECHA_MODIF' in x || 'FECHA_BAJA' in x) {
            return {
                alta: ('USUARIO_ALTA' in x || 'FECHA_ALTA' in x)
                    ? { usuario: x.USUARIO_ALTA, fecha: x.FECHA_ALTA } : null,
                modif: ('USUARIO_MODIF' in x || 'FECHA_MODIF' in x)
                    ? { usuario: x.USUARIO_MODIF, fecha: x.FECHA_MODIF } : null,
                baja: (x.FECHA_BAJA || x.USUARIO_BAJA)
                    ? { usuario: x.USUARIO_BAJA, fecha: x.FECHA_BAJA } : null
            };
        }

        return { modif: { usuario: x.usuario, fecha: x.fecha } };
    }

    /** Si dos momentos son el mismo: misma persona y el mismo minuto */
    function mismo(a, b) {
        return quien(a.usuario) === quien(b.usuario) && fecha(a.fecha) === fecha(b.fecha);
    }

    function escaparAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /** El texto, listo para ir dentro de title="…" */
    function titulo(dato) {
        return escaparAttr(texto(dato));
    }

    /**
     * Un ícono chico con el texto en el title. '' si no hay nada que decir:
     * un ícono sin contenido es ruido.
     *
     * @returns {string} HTML
     */
    function icono(dato) {
        var t = texto(dato);

        if (!t) { return ''; }

        return ' <i class="fas fa-user-pen auditoria-icono" title="' + escaparAttr(t) + '"'
            + ' aria-label="' + escaparAttr(t) + '"></i>';
    }

    window.Auditoria = {
        texto: texto,
        titulo: titulo,
        icono: icono,
        quien: quien,
        fecha: fecha,
        linea: linea
    };
})();
