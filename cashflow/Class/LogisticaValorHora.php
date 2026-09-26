<?php

require_once __DIR__ . '/Inflacion.php';

/**
 * LogisticaValorHora
 * Cuanto vale la hora de un fletero en cada mes: el ajuste trimestral por
 * inflacion, escrito una sola vez.
 *
 * LA REGLA COMPLETA, EN CINCO LINEAS
 * ----------------------------------
 *   1. El valor base YA ESTA AJUSTADO y rige SU mes y los dos siguientes.
 *      Con MES_BASE = sep-26, el valor base rige en sep, oct y nov.
 *   2. Cada tres meses contados desde MES_BASE hay un ajuste: dic-26, mar-27,
 *      jun-27...
 *   3. El % de un ajuste es la SUMA SIN COMPONER de la inflacion de SU mes y de
 *      los dos anteriores: dic-26 = oct + nov + dic.
 *   4. LA BASE SE CORRE: cada ajuste se aplica sobre el valor del trimestre
 *      anterior, no sobre el valor base original.
 *   5. El valor ajustado rige DESDE el mes del ajuste, para los DOS pagos de
 *      ese mes.
 *
 * Con 2 % mensual constante: dic-26 = sep-26 x 1,06 y mar-27 = dic-26 x 1,06.
 *
 * NO HAY OVERRIDE DEL VALOR AJUSTADO
 * -----------------------------------
 * El valor de un trimestre futuro SIEMPRE sale de la formula. Lo editable es la
 * base -VALOR_HORA_BASE y MES_BASE, que es lo que efectivamente se pacto- y la
 * inflacion. Un tercer lugar donde tocar el mismo numero haria imposible
 * contestar por que un mes vale lo que vale: no se sabria si sale de la cuenta
 * o de una correccion que alguien cargo y nadie recuerda.
 *
 * NO SE REDONDEA EN CADA TRIMESTRE
 * ---------------------------------
 * La cadena se calcula con precision completa y el redondeo es presentacion.
 * Redondeando en cada paso, el valor de dentro de un ano dependeria de cuantas
 * veces se redondeo en el medio, que es una propiedad del recorrido y no del
 * acuerdo. La prueba contra el historico real reproduce los tres fleteros al
 * centavo con este criterio.
 *
 * LO QUE NO SE PUEDE CALCULAR QUEDA EN null, NUNCA EN CERO
 * ---------------------------------------------------------
 * Tres casos, y los tres se distinguen con 'motivo':
 *
 *   ANTES_DE_BASE   el mes es anterior al MES_BASE. No hay ningun valor
 *                   pactado para ese mes: el valor base describe su mes en
 *                   adelante, y estirarlo hacia atras seria afirmar algo que
 *                   nadie cargo.
 *   SIN_BASE        el fletero no tiene valor hora base cargado.
 *   SIN_INFLACION   falta la inflacion de alguno de los meses que suma un
 *                   ajuste de la cadena. Desde ese ajuste en adelante no hay
 *                   valor, y 'faltan' dice que meses hay que cargar.
 *
 * Un cero en cualquiera de los tres se leeria como "esa hora no se paga", que
 * es lo contrario de lo que pasa. Es el mismo criterio de Cotizacion y de
 * DolarFuturo.
 *
 * ES PURA: no toca la base ni lee parametros. Recibe la base del fletero, los
 * meses a resolver y el mapa de inflacion, y devuelve numeros.
 */
class LogisticaValorHora {

    /** Cada cuantos meses hay un ajuste */
    const MESES_TRIMESTRE = 3;

    /* Por que un mes no tiene valor. Viajan hasta el front, que dibuja una
       marca distinta para cada uno. */
    const OK = '';
    const ANTES_DE_BASE = 'ANTES_DE_BASE';
    const SIN_BASE = 'SIN_BASE';
    const SIN_INFLACION = 'SIN_INFLACION';

    /**
     * El valor hora de UN mes, con el detalle de como se llego a el.
     *
     * DEVUELVE SIEMPRE LA MISMA ESTRUCTURA, con 'valor' en null cuando no se
     * puede calcular y 'motivo' diciendo por que.
     *
     * 'pct', 'componentes' y 'rige_desde' describen el ULTIMO ajuste aplicado,
     * que es el que puso en vigencia el valor de este mes. Es lo que muestra el
     * tooltip de la planilla: un valor hora sin decir de que ajuste sale y de
     * que meses de inflacion se compone no se puede verificar contra nada. En
     * el trimestre base no hay ningun ajuste, asi que 'pct' va en null y
     * 'rige_desde' es el propio MES_BASE.
     *
     * @param string $mesBase 'Y-m'
     * @param float|null $valorBase Valor hora ya ajustado que rige desde $mesBase
     * @param string $mes Mes a resolver, 'Y-m'
     * @param array $inflacion Mapa 'Y-m' => float, en puntos porcentuales
     * @return array ['mes', 'valor'|null, 'motivo', 'ajusta', 'pct'|null,
     *                'componentes', 'rige_desde', 'faltan']
     */
    public static function delMes($mesBase, $valorBase, $mes, $inflacion) {
        $out = [
            'mes' => (string) $mes,
            'valor' => null,
            'motivo' => self::OK,
            'ajusta' => false,
            'pct' => null,
            'componentes' => [],
            'rige_desde' => (string) $mesBase,
            'faltan' => []
        ];

        if ($valorBase === null || $valorBase === '' || floatval($valorBase) <= 0) {
            $out['motivo'] = self::SIN_BASE;

            return $out;
        }

        $distancia = Inflacion::distancia($mesBase, $mes);

        if ($distancia < 0) {
            $out['motivo'] = self::ANTES_DE_BASE;

            return $out;
        }

        /* Cuantos ajustes ya ocurrieron entre el mes base y este. La division
           entera es la regla "rige su mes y los dos siguientes": los meses 0, 1
           y 2 dan cero ajustes; el 3 da uno. */
        $ajustes = intdiv($distancia, self::MESES_TRIMESTRE);

        $out['ajusta'] = ($ajustes > 0 && $distancia % self::MESES_TRIMESTRE === 0);
        $out['rige_desde'] = Inflacion::mesMas($mesBase, $ajustes * self::MESES_TRIMESTRE);

        $valor = floatval($valorBase);

        for ($i = 1; $i <= $ajustes; $i++) {
            $mesAjuste = Inflacion::mesMas($mesBase, $i * self::MESES_TRIMESTRE);
            $acum = Inflacion::acumulada($inflacion, $mesAjuste);

            /* El detalle es SIEMPRE el del ultimo ajuste que se intento, tambien
               cuando falla: es lo que la pantalla necesita para decir que meses
               hay que cargar, y sin el el aviso diria "falta inflacion" sin
               decir cual. */
            $out['pct'] = $acum['pct'];
            $out['componentes'] = $acum['meses'];

            if ($acum['pct'] === null) {
                $out['motivo'] = self::SIN_INFLACION;
                $out['faltan'] = $acum['faltan'];
                $out['rige_desde'] = $mesAjuste;

                return $out;
            }

            $valor = $valor * (1 + $acum['pct'] / 100);
        }

        $out['valor'] = $valor;

        return $out;
    }

    /**
     * El valor hora de una lista de meses.
     *
     * @param string $mesBase 'Y-m'
     * @param float|null $valorBase
     * @param array $meses Lista de 'Y-m'
     * @param array $inflacion Mapa 'Y-m' => float
     * @return array Mapa 'Y-m' => el resultado de delMes()
     */
    public static function serie($mesBase, $valorBase, $meses, $inflacion) {
        $serie = [];

        foreach ($meses as $mes) {
            $serie[$mes] = self::delMes($mesBase, $valorBase, $mes, $inflacion);
        }

        return $serie;
    }

    /**
     * Los meses de ajuste que caen dentro de un rango, para poder decir en
     * pantalla cuando toca el proximo.
     *
     * @param string $mesBase 'Y-m'
     * @param string $desde 'Y-m'
     * @param string $hasta 'Y-m'
     * @return array Lista de 'Y-m'
     */
    public static function mesesDeAjuste($mesBase, $desde, $hasta) {
        $meses = [];
        $total = Inflacion::distancia($mesBase, $hasta);

        for ($i = self::MESES_TRIMESTRE; $i <= $total; $i += self::MESES_TRIMESTRE) {
            $mes = Inflacion::mesMas($mesBase, $i);

            if ($mes >= $desde) {
                $meses[] = $mes;
            }
        }

        return $meses;
    }

    /**
     * El texto del tooltip de un mes: de que ajuste sale su valor hora y de que
     * meses de inflacion se compone.
     *
     * VA EN EL BACKEND y no en el JS porque es la explicacion de una cuenta que
     * hace el backend. Con el texto en el front, cambiar la regla obligaria a
     * cambiarla en dos lados y el segundo se olvida.
     *
     * @param array $detalle Lo que devolvio delMes()
     * @return string
     */
    public static function explicar($detalle) {
        if ($detalle['motivo'] === self::SIN_BASE) {
            return 'Este fletero no tiene valor hora base cargado, así que no se proyecta.';
        }

        if ($detalle['motivo'] === self::ANTES_DE_BASE) {
            return 'El mes es anterior al mes base del valor hora (' . $detalle['rige_desde']
                . '), así que no hay un valor pactado para él.';
        }

        $partes = [];

        foreach ($detalle['componentes'] as $mes => $pct) {
            $partes[] = $mes . ': ' . ($pct === null ? 'sin cargar' : self::pct($pct));
        }

        if ($detalle['motivo'] === self::SIN_INFLACION) {
            return 'No se puede calcular el ajuste de ' . $detalle['rige_desde']
                . ': falta la inflación de ' . implode(', ', $detalle['faltan'])
                . '. El ajuste suma ' . implode(' + ', $partes)
                . '. Cargala en Parámetros › Generales.';
        }

        if ($detalle['pct'] === null) {
            return 'Es el valor base, sin ajustar: rige desde ' . $detalle['rige_desde']
                . ' y por los dos meses siguientes.';
        }

        return 'Rige desde el ajuste de ' . $detalle['rige_desde'] . ', de '
            . self::pct($detalle['pct']) . ' = ' . implode(' + ', $partes)
            . ' (suma sin componer), aplicado sobre el valor del trimestre anterior.';
    }

    /** 6 -> '6,00 %' */
    private static function pct($valor) {
        return number_format(floatval($valor), 2, ',', '.') . ' %';
    }
}
