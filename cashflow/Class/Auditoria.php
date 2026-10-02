<?php
/**
 * Auditoria
 * Los fragmentos SQL con los que TODAS las escrituras del modulo dejan dicho
 * quien y cuando.
 *
 * El esquema es el de Tarjetas, en todas las tablas:
 *
 *   USUARIO_ALTA  / FECHA_ALTA    quien y cuando creo la fila
 *   USUARIO_MODIF / FECHA_MODIF   quien y cuando la toco por ultima vez
 *   USUARIO_BAJA  / FECHA_BAJA    quien y cuando la dio de baja
 *
 * Las columnas las crea sql/cashflow_auditoria_usuario.sql. Las fechas las
 * pone SIEMPRE el servidor: GETDATE() aca, o el DEFAULT de la columna en los
 * INSERT. El usuario lo pasa el controller -el de la sesion, nunca el del
 * request- y cada metodo de escritura lo valida con
 * AuthCashflow::usuarioDeEscritura() antes de llegar aca.
 *
 * POR QUE FRAGMENTOS Y NO UN CONSTRUCTOR DE CONSULTAS: las escrituras del
 * modulo son SQL escrito a mano, cada una con su WHERE y sus guardas, y asi
 * tienen que seguir leyendose. Lo que se comparte es la parte que no puede
 * quedar distinta entre una tabla y otra.
 */
class Auditoria {

    /** El SET de toda modificacion. Un parametro: el usuario. */
    const SET_MODIF = 'USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()';

    /**
     * El SET de toda baja logica. Dos parametros: el usuario, dos veces.
     * Una baja tambien es la ultima modificacion de la fila.
     */
    const SET_BAJA = 'USUARIO_BAJA = ?, FECHA_BAJA = GETDATE(), USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()';

    /**
     * El SET de la baja cuando el UPDATE escribe el estado con un valor que
     * puede ser alta o baja: un guardado masivo de una grilla, o un "activar"
     * que tambien desactiva.
     *
     * Se sella la baja SOLO en la transicion de activo a inactivo, mirando el
     * valor que la fila tenia antes del UPDATE (en un SET, la columna vale lo
     * de antes). Guardar de nuevo una fila que ya estaba de baja no le cambia
     * quien la dio de baja. Y al reactivarla se limpian las dos columnas: una
     * fila activa con FECHA_BAJA diria a la vez que esta y que no esta. Es el
     * criterio que ya tenia Logistica con su FECHA_BAJA.
     *
     * Va con paramsBajaSegunEstado(), en ese orden.
     *
     * @param string $columnaEstado ACTIVO, VIGENTE o ACTIVA
     * @return string
     */
    public static function sqlBajaSegunEstado($columnaEstado) {
        $c = self::columna($columnaEstado);

        return "USUARIO_BAJA = CASE WHEN ? = 1 THEN NULL WHEN " . $c . " = 1 THEN ? ELSE USUARIO_BAJA END, "
            . "FECHA_BAJA = CASE WHEN ? = 1 THEN NULL WHEN " . $c . " = 1 THEN GETDATE() ELSE FECHA_BAJA END";
    }

    /**
     * Los parametros de sqlBajaSegunEstado().
     *
     * @param bool|int $activo El estado que se escribe
     * @param string $usuario
     * @return array
     */
    public static function paramsBajaSegunEstado($activo, $usuario) {
        $a = $activo ? 1 : 0;

        return [$a, $usuario, $a];
    }

    /**
     * El nombre de la columna de estado, verificado: va concatenado al SQL.
     */
    private static function columna($c) {
        if (!in_array($c, ['ACTIVO', 'VIGENTE', 'ACTIVA'], true)) {
            throw new InvalidArgumentException('Columna de estado desconocida: ' . $c);
        }

        return $c;
    }
}
