<?php

/**
 * Aviso
 * Un aviso con gravedad, y el armado del panel "Sobre estos numeros" del
 * tablero.
 *
 * POR QUE EXISTE
 * --------------
 * El tablero junta los avisos de todos los modulos en una sola lista. Con
 * cien avisos de texto plano, el que dice "el numero esta mal" y el que dice
 * "esto lo excluyo alguien a mano" se veian iguales, y el importante quedaba
 * tapado. Los tres niveles son los del Informe Economico
 * (informe_economico/Class/InformeEconomico.php, aviso()):
 *
 *   danger  -> Critico. El numero esta mal o incompleto por algo que no es una
 *              decision de nadie: un modulo que no se pudo calcular, un script
 *              que falta y deja la fila en cero, una tabla que no se pudo leer.
 *   warning -> Atencion. Plata que no entra, o entra en otro lugar, y que se
 *              arregla con una accion: cargar una fecha, un saldo, una
 *              cotizacion, correr un script que solo apaga la edicion.
 *   info    -> Informativo. Explica un criterio o algo que alguien ya decidio,
 *              y no pide nada: excluidos a mano, lo que sale por otra fila, lo
 *              que cae fuera del horizonte.
 *
 * El criterio completo y la tabla aviso por aviso estan en README-cashflow.md,
 * "Avisos del tablero".
 *
 * TEXTO SIN PREFIJO, Y 'seccion' CUANDO HACE FALTA
 * ------------------------------------------------
 * En el tablero los avisos se agrupan por pestana, asi que el texto no repite
 * de que pestana es. Cuando la pestana sola no alcanza para saber de que parte
 * habla (en Saldos: "Saldo Inicial" o "Caja Locales"; en Pagos con Tarjetas:
 * "Supervisoras", "Corporativas" o "Socios"), el aviso lleva 'seccion', que la
 * pantalla muestra en negrita antes del texto. Va en un campo aparte y no
 * pegado al texto porque es un dato de presentacion: las pestanas que muestran
 * el mismo texto en su propio panel no lo necesitan.
 *
 * LAS PESTANAS SIGUEN RECIBIENDO TEXTOS
 * -------------------------------------
 * Los paneles de cada pestana leen string[] y no cambian. Una funcion que arma
 * avisos de niveles distintos devuelve la lista con nivel, y la version que
 * usan las pestanas es un envoltorio de una linea con Aviso::textos(): asi el
 * texto se escribe una sola vez y las dos pantallas no pueden decir cosas
 * distintas sobre los mismos datos.
 */
class Aviso {

    const INFO = 'info';
    const WARNING = 'warning';
    const DANGER = 'danger';

    /** El grupo de los avisos que no son de ninguna pestana */
    const GRUPO_TABLERO = 'tablero';
    const NOMBRE_TABLERO = 'Tablero';

    /** Peso de cada nivel: mas alto, mas grave. Ordena grupos y avisos. */
    const PESO = [self::DANGER => 3, self::WARNING => 2, self::INFO => 1];

    /**
     * El nivel tal cual si es valido, o 'warning'.
     *
     * Un nivel mal escrito cae en Atencion y no en Informativo: si el nivel
     * esta mal no se sabe cuan grave es el aviso, y esconderlo entre los que
     * no piden nada seria peor que mostrarlo de mas. Tampoco va a Critico,
     * que abre el panel solo y gritaria por un error de tipeo.
     *
     * @param mixed $nivel
     * @return string
     */
    public static function nivel($nivel) {
        return (is_string($nivel) && isset(self::PESO[$nivel])) ? $nivel : self::WARNING;
    }

    /**
     * Un aviso con nivel.
     *
     * @param string $nivel 'danger' | 'warning' | 'info'
     * @param string $texto
     * @param string|null $seccion Subtitulo dentro de la pestana, o null
     * @return array ['nivel', 'texto', 'seccion']
     */
    public static function nuevo($nivel, $texto, $seccion = null) {
        return [
            'nivel' => self::nivel($nivel),
            'texto' => (string) $texto,
            'seccion' => ($seccion === null || $seccion === '') ? null : (string) $seccion
        ];
    }

    /**
     * Lleva cualquier aviso a la forma con nivel. Un texto suelto se toma como
     * 'warning' -o como $nivel, si se pasa-: asi una lista vieja, o una serie
     * armada a mano en una prueba, sigue funcionando sin tocarla.
     *
     * @param string|array $aviso
     * @param string $nivel Nivel para un texto suelto
     * @param string|null $seccion Seccion para un texto suelto
     * @return array ['nivel', 'texto', 'seccion']
     */
    public static function normalizar($aviso, $nivel = self::WARNING, $seccion = null) {
        if (is_array($aviso)) {
            return self::nuevo(
                isset($aviso['nivel']) ? $aviso['nivel'] : null,
                isset($aviso['texto']) ? $aviso['texto'] : '',
                isset($aviso['seccion']) ? $aviso['seccion'] : null
            );
        }

        return self::nuevo($nivel, $aviso, $seccion);
    }

    /**
     * Normaliza una lista entera. Ver normalizar().
     *
     * @param array $lista Textos y/o avisos con nivel
     * @param string $nivel Nivel para los textos sueltos
     * @param string|null $seccion Seccion para los textos sueltos
     * @return array
     */
    public static function lista($lista, $nivel = self::WARNING, $seccion = null) {
        $out = [];

        foreach ((is_array($lista) ? $lista : []) as $a) {
            $out[] = self::normalizar($a, $nivel, $seccion);
        }

        return $out;
    }

    /**
     * Solo los textos. Es lo que reciben los paneles de cada pestana.
     *
     * @param array $lista Textos y/o avisos con nivel
     * @return string[]
     */
    public static function textos($lista) {
        $out = [];

        foreach ((is_array($lista) ? $lista : []) as $a) {
            $out[] = is_array($a) ? (isset($a['texto']) ? (string) $a['texto'] : '') : (string) $a;
        }

        return $out;
    }

    /**
     * Agrupa y ordena los avisos del tablero. Funcion pura: no lee la base ni
     * el menu, todo lo recibe.
     *
     * POR QUE EN EL BACKEND. El repo prueba en PHP (tests/run.php) y no tiene
     * corredor para JS; el orden es una regla del panel y tiene que poder
     * probarse sin navegador. El front solo dibuja lo que llega.
     *
     * EL ORDEN DE LOS GRUPOS: primero los que tienen algun critico, despues los
     * que tienen atencion, al final los que solo informan. A igual gravedad,
     * "Tablero" primero -son los avisos del motor, que hablan del cuadro
     * entero- y despues en el orden del menu. Un grupo cuya pestana no esta en
     * el menu va al final de su nivel, en el orden en que aparecio.
     *
     * DENTRO DE CADA GRUPO: de mas grave a menos y, a igual gravedad, en el
     * orden en que se emitieron. El orden de emision no es arbitrario: cada
     * modulo avisa primero lo que explica a lo demas.
     *
     * @param array $avisos Lista de ['nivel', 'texto', 'seccion', 'grupo', 'origen']
     * @param array $ordenMenu Codigos de pestana en el orden del menu
     * @param array $titulos Mapa pestana => nombre a mostrar
     * @return array Grupos: ['grupo', 'nombre', 'link', 'nivel', 'cuenta', 'avisos']
     */
    public static function agrupar($avisos, $ordenMenu, $titulos) {
        $posMenu = array_flip(array_values($ordenMenu));
        $grupos = [];
        $aparicion = [];
        $i = 0;

        foreach ($avisos as $a) {
            $n = self::normalizar($a);
            $grupo = (isset($a['grupo']) && $a['grupo'] !== null && $a['grupo'] !== '')
                ? (string) $a['grupo'] : self::GRUPO_TABLERO;

            $n['grupo'] = $grupo;
            $n['origen'] = isset($a['origen']) ? $a['origen'] : null;
            $n['_i'] = $i++;

            if (!isset($grupos[$grupo])) {
                $aparicion[$grupo] = count($aparicion);
                $grupos[$grupo] = [
                    'grupo' => $grupo,
                    'nombre' => ($grupo === self::GRUPO_TABLERO)
                        ? self::NOMBRE_TABLERO
                        : (isset($titulos[$grupo]) ? $titulos[$grupo] : $grupo),
                    // "Ir a la pestana": el grupo del motor no tiene adonde ir.
                    'link' => ($grupo !== self::GRUPO_TABLERO),
                    'nivel' => self::INFO,
                    'cuenta' => [self::DANGER => 0, self::WARNING => 0, self::INFO => 0],
                    'avisos' => []
                ];
            }

            $grupos[$grupo]['avisos'][] = $n;
            $grupos[$grupo]['cuenta'][$n['nivel']]++;

            if (self::PESO[$n['nivel']] > self::PESO[$grupos[$grupo]['nivel']]) {
                $grupos[$grupo]['nivel'] = $n['nivel'];
            }
        }

        foreach ($grupos as $k => $g) {
            usort($g['avisos'], function ($x, $y) {
                $d = self::PESO[$y['nivel']] - self::PESO[$x['nivel']];

                return ($d !== 0) ? $d : ($x['_i'] - $y['_i']);
            });

            foreach ($g['avisos'] as $j => $a) {
                unset($g['avisos'][$j]['_i']);
            }

            $grupos[$k] = $g;
        }

        // Clave de orden: gravedad, despues Tablero, despues menu, despues
        // aparicion. Los fuera del menu van detras de todos los del menu.
        $clave = function ($g) use ($posMenu, $aparicion) {
            if ($g['grupo'] === self::GRUPO_TABLERO) {
                $pos = -1;
            } elseif (isset($posMenu[$g['grupo']])) {
                $pos = $posMenu[$g['grupo']];
            } else {
                $pos = count($posMenu) + $aparicion[$g['grupo']];
            }

            return [-self::PESO[$g['nivel']], $pos];
        };

        $lista = array_values($grupos);

        usort($lista, function ($x, $y) use ($clave) {
            return $clave($x) <=> $clave($y);
        });

        return $lista;
    }
}
