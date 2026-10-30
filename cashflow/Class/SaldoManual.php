<?php

/**
 * SaldoManual
 * La regla de que saldo manda cuando una cuenta tiene un saldo cargado a mano,
 * y la validacion de lo que se carga.
 *
 * UNA SOLA REGLA PARA TODAS LAS CUENTAS
 * -------------------------------------
 * Desde feature/saldos-edicion-fila el saldo de cualquier cuenta del
 * disponible -menos el efectivo de tesoreria, que sigue en vivo de SBA05- se
 * edita desde su fila en Saldos > Saldos. Cada cuenta tiene, o no, una fuente
 * automatica (Interbanking, la consulta de Mercado Pago) y, o no, un saldo
 * manual. Cual de los dos manda lo decide resolver(), y lo usan las cuentas
 * de Interbanking (SaldosInterbanking::resolverSaldo()), las cuentas por
 * consulta (Saldos::aplicarConsultas()) y el guardado. La pestana y el
 * tablero leen las filas ya resueltas por Saldos::getFilasDisponible(), asi
 * que no pueden discrepar.
 *
 * EL MANUAL DE HOY MANDA TODO EL DIA
 * ----------------------------------
 * Aunque la fuente automatica traiga un saldo de hoy, incluso uno mas nuevo.
 * Es un pedido de Direccion: el manual se carga para ajustar el saldo cuando
 * hay cheques por acreditar, e Interbanking actualiza cada hora, asi que con
 * la regla anterior ("a igual fecha, Interbanking") el ajuste se perdia en la
 * proxima actualizacion. Desde manana vuelve a mandar la fuente automatica sin
 * que nadie haga nada.
 *
 * Fuera del dia de hoy la regla es la de siempre: gana la fecha mas nueva y, a
 * igual fecha, la fuente automatica, que es la oficial. Un manual de ayer
 * sigue sirviendo de respaldo si la fuente no trae nada o trae algo mas viejo.
 *
 * LA FECHA DEL MANUAL ES HOY Y LA PONE EL SERVIDOR: el dialogo no la pide.
 * Un saldo cargado a mano es lo que hay en la cuenta ahora.
 */
class SaldoManual {

    /** Lo que devuelve resolver() */
    const MANUAL = 'MANUAL';
    const AUTOMATICO = 'AUTOMATICO';

    /** Largo maximo de la observacion: el de las columnas */
    const OBSERVACION_MAX = 500;

    /**
     * Que saldo manda: el automatico o el manual. PURA.
     *
     *   1. Un manual DE HOY manda, contra cualquier dato automatico.
     *   2. Sin dato automatico manda el manual, de la fecha que sea.
     *   3. Si no, gana la fecha mas nueva y, a igual fecha, el automatico.
     *
     * Compara fechas y nada mas: quien llama ya eligio cual es el dato
     * automatico (el ultimo registro con saldo, la lectura en vivo) y cual el
     * manual vigente.
     *
     * @param string|null $fechaAutomatica 'Y-m-d' del dato automatico, o null si no hay
     * @param string|null $fechaManual 'Y-m-d' del manual vigente, o null si no hay
     * @param string $hoy 'Y-m-d'
     * @return string|null MANUAL, AUTOMATICO, o null si no hay ninguno
     */
    public static function resolver($fechaAutomatica, $fechaManual, $hoy) {
        $auto = self::fecha($fechaAutomatica);
        $manual = self::fecha($fechaManual);

        if ($manual === null) {
            return ($auto === null) ? null : self::AUTOMATICO;
        }

        if ($manual === substr((string) $hoy, 0, 10) || $auto === null) {
            return self::MANUAL;
        }

        return ($manual > $auto) ? self::MANUAL : self::AUTOMATICO;
    }

    /**
     * Si un manual es de hoy: es el que se puede quitar y el que manda sobre
     * la fuente automatica.
     *
     * @param string|null $fecha
     * @param string $hoy
     * @return bool
     */
    public static function esDeHoy($fecha, $hoy) {
        $f = self::fecha($fecha);

        return $f !== null && $f === substr((string) $hoy, 0, 10);
    }

    /**
     * Valida lo que llega del dialogo. La fecha no viaja: es hoy.
     *
     * EL IMPORTE ES OBLIGATORIO y puede ser negativo -una cuenta en
     * descubierto-, pero no vacio: un vacio tomado como cero diria "esta
     * cuenta no tiene plata" sin que nadie lo haya dicho.
     *
     * @param array $d ['saldo', 'observacion']
     * @return array ['saldo' => float, 'observacion' => string|null]
     */
    public static function validar($d) {
        $d = is_array($d) ? $d : [];
        $saldo = isset($d['saldo']) ? trim((string) $d['saldo']) : '';

        if ($saldo === '' || !is_numeric($saldo)) {
            throw new Exception('El saldo manual necesita un importe.');
        }

        $obs = isset($d['observacion']) ? trim((string) $d['observacion']) : '';

        if (mb_strlen($obs) > self::OBSERVACION_MAX) {
            throw new Exception('La observación no puede superar los ' . self::OBSERVACION_MAX
                . ' caracteres.');
        }

        return [
            'saldo' => round(floatval($saldo), 2),
            'observacion' => ($obs === '') ? null : $obs
        ];
    }

    /** 'Y-m-d' de una fecha, o null si no hay */
    private static function fecha($v) {
        $v = ($v === null) ? '' : substr(trim((string) $v), 0, 10);

        return ($v === '') ? null : $v;
    }
}
