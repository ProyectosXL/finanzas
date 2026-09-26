<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Logistica.php';

/**
 * LogisticaProvider
 * Alimenta la fila "Logistica" (seccion COSTOS DIRECTOS) con los pagos
 * proyectados a los fleteros.
 *
 * Codigo LOGISTICA, serie PAGOS, moneda de origen ARS y sin tipo de cambio: los
 * importes ya estan en pesos, asi que no hay ninguna conversion que hacer ni
 * que auditar.
 *
 * TODO LO QUE APORTA ES PROYECCION
 * ---------------------------------
 * No hay parte real. Los pagos a fleteros no se leen de ningun comprobante: se
 * calculan a partir de las horas del mes y del valor hora. Por eso esta fila no
 * esta partida en REAL y PROYECTADO como las dos de Comercio Exterior, y por
 * eso no hay nada que conciliar contra Tango.
 *
 * LOS IMPORTES SON FINALES: sin IVA y sin ningun otro concepto.
 *
 * EL REPARTO EN EL EJE NO LO DECIDE ESTE PROVEEDOR
 * -------------------------------------------------
 * La planilla entrega una lista de pagos con fecha e importe, y el eje los
 * ubica con Horizonte::acumular(), que aplica la regla "un importe va a un dia
 * O a su mes, nunca a los dos". Eso es lo que hace que un mes partido por el
 * final del tramo diario -octubre, con el pago del 9 adentro y el del 23
 * afuera- lleve en su columna mensual SOLO la mitad que queda afuera, sin que
 * nadie tenga que programarlo acá.
 *
 * LO QUE YA SE PAGO NO ENTRA, Y NO SE AVISA
 * ------------------------------------------
 * Un pago con fecha anterior o igual a hoy ya salio de la cuenta y ya esta
 * reflejado en el saldo bancario que abre el cuadro. Proyectarlo seria pedir
 * dos veces la misma plata. No se suma a 'fuera_horizonte' ni se avisa, por el
 * mismo motivo que CobElectronicosProvider no avisa de sus acreditaciones
 * pasadas: no es plata que el tablero informe de menos, es plata que ya paso.
 *
 * La consecuencia: LA COLUMNA DE HOY NUNCA RECIBE NADA de esta fila, aunque el
 * tramo diario arranque hoy. Es el criterio, no un hueco.
 *
 * UNA FILA EN CERO SIEMPRE SE EXPLICA
 * ------------------------------------
 * Un egreso en cero se lee como "no hay que pagar nada", que es lo contrario de
 * lo que pasa cuando falta el script, no hay fleteros cargados o a los que hay
 * les faltan las horas. Los cuatro casos avisan diciendo cual es.
 *
 * NUNCA TUMBA EL TABLERO
 * -----------------------
 * calcular() puede lanzar; series() lo envuelve. Ademas se atrapan los casos
 * propios para poder rendir cero CON UN AVISO QUE DIGA QUE PASO, en lugar del
 * mensaje generico de la clase base.
 */
class LogisticaProvider extends CashflowProvider {

    protected function calcular($h) {
        return ['PAGOS' => $this->pagos($h)];
    }

    /**
     * El modulo del que salen los datos.
     *
     * Es una costura para poder probar: lo delicado de este proveedor son los
     * casos en los que tiene que rendir CERO CON UN AVISO -tabla sin crear,
     * ningun fletero, fleteros sin horas- y esos casos no se pueden montar en
     * una base real sin borrar la tabla. Con el metodo separado, la prueba pasa
     * un doble y verifica el aviso sin base.
     *
     * Es la misma idea que CobElectronicosProvider::modulo().
     *
     * @return Logistica
     */
    protected function modulo() {
        return new Logistica();
    }

    /**
     * Serie PAGOS: las mitades del importe mensual de cada fletero, en la fecha
     * del cronograma que les toca.
     *
     * @param Horizonte $h
     * @return array Serie
     */
    private function pagos($h) {
        $modulo = $this->modulo();
        $datos = $modulo->planilla($h);

        /* Los avisos de la planilla se levantan tal cual: ya vienen con el
           nombre del fletero y con qué le falta, que es lo que explica un total
           más chico de lo esperado sin tener que abrir la pestaña. */
        foreach ($datos['avisos'] as $a) {
            $this->avisar('Logística: ' . $a);
        }

        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        $aProyectar = LogisticaPlanilla::pagosAProyectar($datos['planilla']);

        foreach ($aProyectar as $p) {
            /* EL EJE DECIDE LA COLUMNA. Ver el encabezado: acá sólo se entrega
               fecha e importe. Lo que caiga fuera del eje se informa, que es lo
               que exige el contrato: un tablero de consolidación que informa de
               menos sin decirlo es peor que uno que falla. */
            if (!$h->acumular($serie, $p['fecha'], $p['importe'])) {
                $serie['fuera_horizonte'] += $p['importe'];
            }
        }

        if ($serie['fuera_horizonte'] > 0) {
            $this->avisar('Logística: hay pagos proyectados por $ '
                . number_format($serie['fuera_horizonte'], 2, ',', '.')
                . ' con fecha posterior al final del horizonte, así que no entran en ninguna '
                . 'columna. Se ven igual en la pestaña Logística Local.');
        }

        /* UNA FILA EN CERO SE EXPLICA SIEMPRE. Cuál de los motivos es se decide
           con lo que efectivamente se leyó, y no con lo que se supone: así el
           aviso es el correcto sin importar por dónde se llegó al cero. */
        if (array_sum($serie['dias']) == 0 && array_sum($serie['meses']) == 0
            && empty($aProyectar)) {
            $this->avisarCero($datos);
        }

        return $serie;
    }

    /**
     * Por que la fila va en cero.
     *
     * Los avisos de la planilla ya dicen QUE le falta a cada fletero. Este dice
     * lo otro: que el resultado de eso es una fila en cero, que es lo que se ve
     * en el tablero y lo que alguien podria leer como "no hay que pagar nada".
     *
     * @param array $datos Lo que devolvio Logistica::planilla()
     */
    private function avisarCero($datos) {
        if (!$datos['tabla_creada']) {
            // El aviso del script ya salió con los de la planilla; acá va la
            // consecuencia sobre el cuadro.
            $this->avisar('Logística: la fila va en CERO porque todavía no existe el maestro '
                . 'de fleteros. Un egreso en cero no significa que no haya que pagar nada.');

            return;
        }

        $fleteros = $datos['planilla']['fleteros'];

        if (empty($fleteros)) {
            $this->avisar('Logística: la fila va en CERO porque no hay ningún fletero activo '
                . 'cargado. Se dan de alta en Parámetros › Logística.');

            return;
        }

        $this->avisar('Logística: hay ' . count($fleteros) . ' fletero(s) activos pero la fila '
            . 'va en CERO: a ninguno se le pudo calcular un importe. Los avisos de arriba dicen '
            . 'qué le falta a cada uno. NO significa que no haya que pagarles.');
    }
}
