<?php

require_once __DIR__ . '/Tarjetas.php';
require_once __DIR__ . '/Aviso.php';
require_once __DIR__ . '/TarjetasResumen.php';
require_once __DIR__ . '/TarjetasFactura.php';
require_once __DIR__ . '/TarjetasExclusion.php';
require_once __DIR__ . '/TarjetasFacturaVto.php';
require_once __DIR__ . '/TarjetasMensual.php';
require_once __DIR__ . '/TarjetasVencimiento.php';
require_once __DIR__ . '/TarjetasSupervisoras.php';
require_once __DIR__ . '/TarjetasCorporativas.php';
require_once __DIR__ . '/TarjetasSocios.php';
require_once __DIR__ . '/GastosSupervision.php';
require_once __DIR__ . '/TarjetasHistManual.php';
require_once __DIR__ . '/TarjetasProvProyecta.php';
require_once __DIR__ . '/TarjetasSeguros.php';
require_once __DIR__ . '/TarjetasPoliza.php';
require_once __DIR__ . '/CronogramaDatos.php';
require_once __DIR__ . '/Inflacion.php';
require_once __DIR__ . '/DolarFuturo.php';
require_once __DIR__ . '/Cotizacion.php';

/**
 * PagosTarjetas
 * El armador de la pestana Financiero -> Pagos con Tarjetas y Otros: lee todo una
 * vez y arma las tres sub-pestanas.
 *
 * POR QUE EXISTE, Y NO ESTA EN EL PROVEEDOR NI EN EL CONTROLLER
 * ------------------------------------------------------------
 * Porque los dos necesitan exactamente lo mismo, y si cada uno lo armara por su
 * cuenta la pestana y el tablero podrian mostrar numeros distintos para la misma
 * tarjeta. Es el mismo criterio con el que Logistica::planilla() alimenta a la vez
 * la pestana y a LogisticaProvider, y con el que la valuacion de los contenedores
 * de Comex vive en Comex y no en su proveedor.
 *
 * SOLO ORQUESTA: LEE Y DELEGA
 * ---------------------------
 * Ninguna regla de calculo vive aca. La ventana y el promedio los decide
 * TarjetasSupervisoras, la reubicacion y la cobertura TarjetasCorporativas, la base
 * y la regla del dolar TarjetasSocios, la fecha TarjetasVencimiento y la inflacion
 * Inflacion. Lo que hace esta clase es leer, cruzar y juntar los avisos.
 *
 * LO QUE ENTREGA VA DERECHO A Horizonte::acumular()
 * ------------------------------------------------
 * Cada sub-pestana devuelve, ademas de sus filas para la pantalla, una lista de
 * PAGOS con fecha e importe. Quien decide en que columna cae cada uno es el eje, no
 * esta clase: escribir esa decision aca seria una segunda version de la regla
 * "un importe va a un dia O a su mes, nunca a los dos", y la que ya existe esta
 * probada.
 *
 * NINGUNA LECTURA PUEDE TUMBAR LA PANTALLA
 * ----------------------------------------
 * Cada insumo va dentro de su propio try. Si falla el maestro de supervisoras, las
 * otras dos sub-pestanas se ven igual; si falla Tango, Supervisoras y Socios se ven
 * igual. Lo que falla deja su motivo en los avisos y su parte en cero o en null. Es
 * el mismo criterio de Parametros::getModulosConDatos() y de CronogramaDatos.
 */
class PagosTarjetas {

    /** @var Tarjetas */
    private $tarjetas;

    /** @var TarjetasResumen */
    private $resumen;

    /** @var TarjetasFactura */
    private $vinculo;

    /** @var TarjetasExclusion */
    private $exclusion;

    /** @var TarjetasFacturaVto Los vencimientos corregidos de Corporativas */
    private $vtoEdit;

    /** @var TarjetasMensual Las facturas marcadas como mensuales */
    private $mensual;

    /** @var GastosSupervision */
    private $gastos;

    /** @var TarjetasProvProyecta Los proveedores que proyectan */
    private $provProyecta;

    /** @var TarjetasPoliza Las polizas de seguros */
    private $poliza;

    /** @var TarjetasHistManual El historico de quien no carga gastos */
    private $histManual;

    /** @var CronogramaDatos */
    private $cronograma;

    /** @var Inflacion */
    private $inflacion;

    /** @var array Avisos del modulo entero */
    private $avisos = [];

    function __construct() {
        $this->tarjetas = new Tarjetas();
        $this->resumen = new TarjetasResumen();
        $this->vinculo = new TarjetasFactura();
        $this->exclusion = new TarjetasExclusion();
        $this->vtoEdit = new TarjetasFacturaVto();
        $this->mensual = new TarjetasMensual();
        $this->gastos = new GastosSupervision();
        $this->histManual = new TarjetasHistManual();
        $this->provProyecta = new TarjetasProvProyecta();
        $this->poliza = new TarjetasPoliza();
        $this->cronograma = new CronogramaDatos();
        $this->inflacion = new Inflacion();
    }

    /* ====================================================================
       LA RESOLUCION COMPLETA
       ==================================================================== */

    /**
     * Todo lo que la pestana y el proveedor necesitan, para un horizonte.
     *
     * @param Horizonte $h
     * @return array
     */
    public function calcular($h) {
        $this->avisos = [];

        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');

        /* LOS INSUMOS COMPARTIDOS, leidos UNA vez. Cada uno dentro de su try:
           ninguno puede tumbar la pantalla entera. */
        $tarjetas = $this->leerTarjetas();
        $resumenes = $this->leerResumenes();
        $habiles = $this->leerHabiles($h);
        $inflacion = $this->leerInflacion();

        $datos = [
            'hoy' => $hoy,
            'meses' => $meses,
            'tablas' => [
                'tarjetas' => $this->tarjetas->tablaCreada(),
                'resumen' => $this->resumen->tablaCreada(),
                'resumen_compartido' => $this->resumen->tablaCompartidoCreada(),
                'factura' => $this->vinculo->tablaCreada(),
                'exclusion' => $this->exclusion->tablaCreada(),
                'factura_vto' => $this->vtoEdit->tablaCreada(),
                'mensual' => $this->mensual->tablaCreada(),
                'prov_proyecta' => $this->provProyecta->tablaCreada(),
                'polizas' => $this->poliza->tablaCreada(),
                'gastos' => $this->gastos->tablaCreada(),
                'historico_manual' => $this->histManual->tablaCreada(),
                'vista_usuarios' => $this->tarjetas->vistaUsuariosCreada()
            ],
            'tarjetas' => $tarjetas,
            'supervisoras' => $this->supervisoras($h, $tarjetas, $resumenes, $habiles,
                                                  $inflacion),
            'corporativas' => $this->corporativas($h, $tarjetas, $resumenes, $habiles, $inflacion),
            'socios' => $this->socios($h, $tarjetas, $resumenes, $habiles, $inflacion)
        ];

        /* LOS AVISOS DEL MAESTRO VAN PRIMERO: un script que falta explica por que
           las tres sub-pestanas estan vacias, y ponerlo despues de los avisos de
           cada una lo escondería. */
        $datos['avisos'] = array_merge($this->maestroAvisos(), $this->avisos);

        /* LOS AVISOS SE ARMAN MEZCLADOS Y SE SEPARAN ACA, una sola vez. Adentro
           de la clase cada lista admite textos -que valen 'warning', el caso
           mas comun- y avisos con nivel para los que no lo son. Al salir, cada
           bloque lleva 'avisos' con los textos, que es lo que lee la pestana, y
           'avisos_con_nivel' con la gravedad, que es lo que lee el tablero. Un
           solo punto de salida porque cada sub-pestana tiene varios return
           tempranos, y normalizar en cada uno seria facil de olvidar. */
        foreach (['supervisoras', 'corporativas', 'socios'] as $parte) {
            $datos[$parte] = self::separarAvisos($datos[$parte]);
        }

        $datos = self::separarAvisos($datos);

        return $datos;
    }

    /**
     * Deja en un bloque 'avisos' con los textos y 'avisos_con_nivel' con la
     * misma lista con gravedad. Ver calcular().
     *
     * @param array $bloque
     * @return array
     */
    private static function separarAvisos($bloque) {
        $conNivel = Aviso::lista(isset($bloque['avisos']) ? $bloque['avisos'] : []);

        $bloque['avisos'] = Aviso::textos($conNivel);
        $bloque['avisos_con_nivel'] = $conNivel;

        return $bloque;
    }

    /** @return array Textos de los avisos generales del modulo */
    public function avisos() {
        return Aviso::textos($this->avisos);
    }

    /* ====================================================================
       GASTOS SUPERVISORAS
       ==================================================================== */

    /**
     * La sub-pestana Gastos Supervisoras.
     *
     * @return array
     */
    private function supervisoras($h, $tarjetas, $resumenes, $habiles, $inflacion) {
        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');
        $ventana = TarjetasSupervisoras::ventana($hoy);

        $salida = [
            'ventana' => $ventana,
            'cartel' => TarjetasSupervisoras::explicarVentana($ventana),
            'filas' => [],
            'pagos' => [],
            'avisos' => [],
            'sin_tarjeta' => 0.0,
            'disponible' => false
        ];

        try {
            $base = $this->gastos->paraVentana($ventana);
        } catch (Throwable $e) {
            $salida['avisos'][] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer los gastos de '
                . 'supervisión: ' . $e->getMessage() . '. Gastos Supervisoras va en cero.');

            return $salida;
        }

        // Los de GastosSupervision son la tabla que falta o el maestro que no
        // se pudo leer: los dos dejan la proyeccion en cero, critico.
        foreach (Aviso::lista($base['avisos'], Aviso::DANGER) as $a) {
            $salida['avisos'][] = $a;
        }

        if (!$base['tabla_creada']) {
            return $salida;
        }

        $salida['disponible'] = true;

        $promedios = TarjetasSupervisoras::promedios($base['gastos'], $ventana);

        /* LAS TARJETAS DE TIPO SUPERVISORA SE ASOCIAN POR EL NOMBRE DEL USUARIO.
           Ver TarjetasSupervisoras y el README: es el unico tipo que cruza contra
           algo, y lo hace por nombre porque RO_T_GASTOS_SUPERVISION guarda el
           nombre y no un ID. */
        $porSupervisora = $this->tarjetasPorSupervisora($tarjetas);

        /* QUIEN TIENE TARJETA Y NO CARGA GASTOS: su fila, con el historico
           manual si lo tiene. Mandan los gastos: un historico de alguien que
           ya tiene gastos en la ventana deja de usarse y se avisa. */
        $historicos = $this->leer(function () { return $this->histManual->vigentes(); },
            'el histórico manual de Gastos Supervisoras', $salida['avisos'], []);
        $manual = TarjetasSupervisoras::universoManual($porSupervisora, $promedios, $historicos);
        $promedios = $promedios + $manual['promedios'];
        ksort($promedios);

        $salida['historico_tabla'] = $this->histManual->tablaCreada();
        $salida['en_desuso'] = $manual['en_desuso'];

        $estados = TarjetasSupervisoras::estadosConTarjeta($base['estados'],
            array_keys($promedios), $porSupervisora);
        $estimado = TarjetasSupervisoras::estimar($promedios, $meses, $inflacion, $ventana,
            $estados);

        /* EL CRONOGRAMA SUPERVISORAS de Parametros -> Generales, con sus
           overrides: por defecto todos los lunes del mes. Hasta
           feature/cronogramas-tarjetas-corporativas era el de Logistica Local,
           el 2do y el 4to viernes. */
        $crono = $this->leerCronograma($h);
        $salida['cronograma'] = isset($crono['config']) ? $crono['config'] : null;
        $porMes = [];

        foreach ($crono['pagos'] as $p) {
            $porMes[$p['mes']][intval($p['nro'])] = $p;
        }

        foreach (isset($crono['avisos_con_nivel']) ? $crono['avisos_con_nivel'] : $crono['avisos']
                 as $a) {
            $salida['avisos'][] = $a;
        }

        $sinTarjeta = 0.0;
        $faltanCalendario = [];

        foreach ($estimado as $nombre => $fila) {
            $clave = GastosSupervision::clave($nombre);
            $tarjetasDe = isset($porSupervisora[$clave]) ? $porSupervisora[$clave] : [];

            /* MAS DE UNA TARJETA ACTIVA ES AMBIGUO: no se puede elegir una, porque
               elegir la primera pondria el importe en una fecha que puede no ser la
               correcta y nadie tendria donde notarlo. Se trata como sin tarjeta y
               se avisa con el motivo propio. */
            $tarjeta = (count($tarjetasDe) === 1) ? $tarjetasDe[0] : null;
            $motivoTarjeta = (count($tarjetasDe) === 0)
                ? TarjetasSupervisoras::SIN_TARJETA
                : ((count($tarjetasDe) > 1) ? TarjetasSupervisoras::VARIAS_TARJETAS : null);

            $fila['tarjeta'] = $tarjeta;
            $fila['tarjetas_activas'] = count($tarjetasDe);
            $fila['motivo_tarjeta'] = $motivoTarjeta;
            $fila['celdas'] = [];
            $fila['compartidos'] = [];

            foreach ($meses as $mes) {
                $celda = $fila['meses'][$mes];

                /* EL EFECTIVO: partes iguales en los pagos del mes del cronograma. */
                $pagosEfectivo = TarjetasSupervisoras::repartirEfectivo(
                    $celda['efectivo'], isset($porMes[$mes]) ? $porMes[$mes] : [], $hoy);

                /* LA TARJETA: el resumen si lo hay, y si no la estimacion en el
                   dia de vencimiento de la tarjeta. */
                $vto = null;

                if ($tarjeta !== null) {
                    $vto = TarjetasVencimiento::delMes($tarjeta['DIA_VENCIMIENTO'], $mes,
                        $habiles);

                    foreach ($vto['faltan'] as $m) {
                        if (!in_array($m, $faltanCalendario, true)) {
                            $faltanCalendario[] = $m;
                        }
                    }
                }

                $resumenMes = ($tarjeta !== null && isset($resumenes[$tarjeta['ID']][$mes]))
                    ? $resumenes[$tarjeta['ID']][$mes] : null;

                $tar = TarjetasSupervisoras::tarjetaDelMes($celda['tarjeta'], $tarjeta, $vto,
                    $resumenMes, $hoy);

                /* UN RESUMEN COMPARTIDO SE MARCA EN LA FILA, sume o no aca: es lo
                   que explica por que la parte tarjeta de este mes no es la
                   estimacion. */
                if ($resumenMes !== null && !empty($resumenMes['COMPARTIDO'])) {
                    $tar['nota_compartido'] = TarjetasResumen::notaCompartido($resumenMes,
                        $tarjeta['ID'], $tarjetas);
                    $fila['compartidos'][] = [
                        'mes' => $mes,
                        'importe' => $resumenMes['IMPORTE_ARS'],
                        'fecha' => $resumenMes['FECHA_VENCIMIENTO'],
                        'es_titular' => !empty($resumenMes['ES_TITULAR']),
                        'nota' => $tar['nota_compartido']
                    ];
                }

                $celda['pagos_efectivo'] = $pagosEfectivo;
                $celda['tarjeta_pago'] = $tar;
                $fila['celdas'][$mes] = $celda;

                /* LO QUE VA AL EJE. La fecha y el importe, nada mas: el eje decide
                   la columna. */
                foreach ($pagosEfectivo as $p) {
                    if ($p['proyecta'] && $p['importe'] !== null && $p['importe'] != 0) {
                        $salida['pagos'][] = [
                            'parte' => 'EFECTIVO',
                            'supervisora' => $nombre,
                            'mes' => $mes,
                            'fecha' => $p['fecha'],
                            'importe' => $p['importe']
                        ];
                    }
                }

                if ($tar['proyecta'] && $tar['importe'] !== null && $tar['fecha'] !== null) {
                    $salida['pagos'][] = [
                        'parte' => 'TARJETA',
                        'supervisora' => $nombre,
                        'mes' => $mes,
                        'fecha' => $tar['fecha'],
                        'importe' => $tar['importe'],
                        'origen' => $tar['origen'],
                        'id_tarjeta' => ($tarjeta === null) ? null : $tarjeta['ID']
                    ];
                }

                /* LA PARTE TARJETA QUE NO ENTRA POR NO TENER TARJETA: se acumula
                   para poder decir cuanto es. */
                if ($motivoTarjeta !== null && $celda['tarjeta'] !== null) {
                    $sinTarjeta += $celda['tarjeta'];
                }
            }

            unset($fila['meses']);
            $salida['filas'][] = $fila;
        }

        $salida['sin_tarjeta'] = $sinTarjeta;

        foreach (TarjetasVencimiento::avisosCalendario($faltanCalendario) as $a) {
            $salida['avisos'][] = $a;
        }

        foreach ($this->avisosSupervisoras($salida, $base, $ventana, $hoy) as $a) {
            $salida['avisos'][] = $a;
        }

        return $salida;
    }

    /**
     * Los avisos propios de Gastos Supervisoras.
     *
     * CADA UNO DESCRIBE UN HECHO DISTINTO y va aparte, por el mismo motivo que en
     * Corporativas: juntarlos haria que el importe no se pudiera atribuir a ninguna
     * causa.
     *
     * @return array
     */
    private function avisosSupervisoras($salida, $base, $ventana, $hoy) {
        $avisos = [];

        /* 1. LAS QUE NO PROYECTAN, agrupadas POR MOTIVO y no una por supervisora:
              son pocas, pero el motivo es lo accionable y el nombre es el detalle. */
        $porMotivo = [];

        foreach ($salida['filas'] as $f) {
            if ($f['proyecta']) {
                continue;
            }

            $porMotivo[$f['motivo']][] = $f['nombre'] . ' ('
                . self::plata($f['promedio']) . '/mes)';
        }

        $textos = [
            TarjetasSupervisoras::SIN_SUPERVISORA => 'no están en el maestro de supervisoras',
            TarjetasSupervisoras::INACTIVA => 'están inactivas en el maestro',
            TarjetasSupervisoras::SIN_IMPORTE => 'tienen gastos autorizados que suman cero'
        ];

        foreach ($porMotivo as $motivo => $nombres) {
            /* SIN HISTORICO VA APARTE: no tiene gastos que se vean en la grilla,
               y lo que hay que hacer es cargar algo, no revisar el maestro. */
            if ($motivo === TarjetasSupervisoras::SIN_HISTORICO) {
                $avisos[] = count($nombres) . ' persona(s) tienen tarjeta Supervisora y no cargan '
                    . 'gastos en la app de supervisión, así que no proyectan nada: '
                    . implode('; ', array_map(function ($n) {
                        return preg_replace('/ \(—\/mes\)$/', '', $n);
                    }, $nombres))
                    . '. Cargales el histórico manual de los últimos 3 meses desde su fila.';
                continue;
            }

            $avisos[] = count($nombres) . ' supervisora(s) '
                . (isset($textos[$motivo]) ? $textos[$motivo] : 'no se proyectan')
                . ', así que no se proyectan: ' . implode('; ', $nombres) . '. Sus gastos se ven '
                . 'en la grilla; lo que no entra al tablero es su proyección.';
        }

        /* EL HISTORICO MANUAL QUE DEJO DE USARSE: la persona empezo a cargar
           gastos, y mandan los gastos. Informativo: es el criterio funcionando,
           y el historico queda como historia. */
        if (!empty($salida['en_desuso'])) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, implode(', ', $salida['en_desuso'])
                . ' ya tiene(n) gastos autorizados en la ventana, así que su histórico manual '
                . 'dejó de usarse: mandan los gastos. El histórico queda cargado como historia.');
        }

        /* 2. LA PARTE TARJETA SIN TARJETA. No es plata que falte del universo: es
              plata que no tiene FECHA con la que entrar, y por eso el aviso dice
              que hacer. */
        $sinTarjeta = [];
        $varias = [];

        foreach ($salida['filas'] as $f) {
            if (!$f['proyecta']) {
                continue;
            }

            if ($f['motivo_tarjeta'] === TarjetasSupervisoras::SIN_TARJETA) {
                $sinTarjeta[] = $f['nombre'];
            } elseif ($f['motivo_tarjeta'] === TarjetasSupervisoras::VARIAS_TARJETAS) {
                $varias[] = $f['nombre'] . ' (' . $f['tarjetas_activas'] . ')';
            }
        }

        if (!empty($sinTarjeta)) {
            $avisos[] = count($sinTarjeta) . ' supervisora(s) no tienen ninguna tarjeta de tipo '
                . 'Supervisora cargada a su nombre, así que su parte TARJETA —'
                . self::plata($salida['sin_tarjeta']) . ' en todo el horizonte— no entra al '
                . 'tablero: sin tarjeta no hay día de vencimiento, y no se inventa una fecha. '
                . 'Son: ' . implode(', ', $sinTarjeta) . '. Se dan de alta en '
                . 'Parámetros › Tarjetas, con el nombre del usuario igual al de la supervisora. '
                . 'La parte en EFECTIVO entra igual, por el cronograma de pagos.';
        }

        if (!empty($varias)) {
            $avisos[] = count($varias) . ' supervisora(s) tienen MÁS DE UNA tarjeta activa a su '
                . 'nombre, así que no se puede saber en qué fecha vence su gasto y su parte '
                . 'tarjeta no entra al tablero: ' . implode(', ', $varias) . '. Dejá una sola '
                . 'activa en Parámetros › Tarjetas.';
        }

        /* 3. LAS SUPERVISORAS ACTIVAS SIN GASTOS EN LA VENTANA. No generan fila
              -el universo son las que tienen gastos- pero pueden ser alguien que
              no cargó sus gastos, y eso es plata que el tablero no proyecta. */
        try {
            $sinGastos = $this->gastos->activasSinGastos($base['gastos'],
                $this->gastos->maestro());

            /* QUIEN YA TIENE FILA -por su tarjeta- no es "alguien que no aparece
               en la grilla": su fila dice que hacer. */
            $conFila = [];

            foreach ($salida['filas'] as $f) {
                $conFila[GastosSupervision::clave($f['nombre'])] = true;
            }

            $sinGastos = array_values(array_filter($sinGastos, function ($n) use ($conFila) {
                return !isset($conFila[GastosSupervision::clave($n)]);
            }));

            if (!empty($sinGastos)) {
                // Informativo: explica el criterio de no inventar un promedio, y
                // autorizar gastos no es una accion de quien mira el tablero.
                $avisos[] = Aviso::nuevo(Aviso::INFO, count($sinGastos) . ' supervisora(s) están '
                    . 'activas y NO tienen gastos autorizados en la ventana ' . $ventana['rotulo']
                    . ', así que no aparecen en la grilla y no se proyecta nada por ellas: '
                    . implode(', ', $sinGastos) . '. No se les pone un promedio en cero —eso '
                    . 'afirmaría que no gastan—; si cargaron gastos y no están autorizados, '
                    . 'todavía no cuentan.');
            }
        } catch (Throwable $e) {
            // El maestro ya avisó por su cuenta; este aviso es un extra.
        }

        /* 4. LOS MESES DE INFLACION QUE FALTAN, juntos y una sola vez: doce avisos
              que dicen lo mismo esconden el resto. */
        $faltan = [];

        foreach ($salida['filas'] as $f) {
            foreach ($f['faltan_inflacion'] as $m) {
                if (!in_array($m, $faltan, true)) {
                    $faltan[] = $m;
                }
            }
        }

        sort($faltan);
        $avisoInf = Inflacion::avisoFaltan($faltan, $hoy);

        if ($avisoInf !== '') {
            // Sin el nombre de la sub-pestana adelante: en la pestana ya va en su
            // bloque, y en el tablero lo dice la seccion. Con el prefijo, el
            // proveedor lo duplicaba ("Gastos Supervisoras: Gastos Supervisoras: ...").
            $avisos[] = $avisoInf;
        }

        return $avisos;
    }

    /**
     * Las tarjetas de tipo SUPERVISORA, indexadas por el nombre de su usuario.
     *
     * SOLO LAS ACTIVAS: una tarjeta de baja no proyecta, asi que tomarla como la
     * tarjeta de la supervisora pondria su gasto en una fecha que no se va a usar.
     *
     * SE INDEXA CON LA MISMA CLAVE QUE EL CRUCE DE GASTOS
     * (GastosSupervision::clave()): mayusculas y sin espacios de mas. Dos
     * normalizaciones distintas para el mismo nombre es como se deja de encontrar.
     *
     * @param array $tarjetas Mapa ID => tarjeta
     * @return array Mapa clave => lista de tarjetas
     */
    private function tarjetasPorSupervisora($tarjetas) {
        $mapa = [];

        foreach ($tarjetas as $t) {
            if ($t['TIPO'] !== 'SUPERVISORA' || !$t['ACTIVA']) {
                continue;
            }

            $mapa[GastosSupervision::clave($t['NOMBRE_USUARIO'])][] = $t;
        }

        return $mapa;
    }

    /* ====================================================================
       TARJETAS PAGOS CORPORATIVOS
       ==================================================================== */

    /**
     * La sub-pestana Tarjetas Pagos Corporativos.
     *
     * @return array
     */
    private function corporativas($h, $tarjetas, $resumenes, $habiles, $inflacion = []) {
        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');

        $salida = [
            'filas' => [],
            'cobertura' => [],
            'resumenes' => [],
            'pagos' => [],
            'pagos_excluidos' => [],
            'vtos_inertes' => [],
            'estimaciones' => [],
            'seguros' => ['proveedores' => [], 'polizas' => [], 'tabla_creada' => false,
                          'facturas' => TarjetasSeguros::resumenFacturas([])],
            'avisos' => [],
            'disponible' => false
        ];

        /* SOLO LAS TARJETAS CORPORATIVAS Y ACTIVAS. Una de baja no proyecta, asi
           que no genera cobertura ni reemplaza nada con su resumen. */
        $corporativas = [];

        foreach ($tarjetas as $id => $t) {
            if ($t['TIPO'] === 'CORPORATIVA' && $t['ACTIVA']) {
                $corporativas[$id] = $t;
            }
        }

        try {
            require_once __DIR__ . '/Proveedores.php';

            $prov = new Proveedores();
            $pendientes = $prov->getPendientes($hoy);
        } catch (Throwable $e) {
            $salida['avisos'][] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron leer las cuentas a '
                . 'pagar de Tango: ' . $e->getMessage() . '. Tarjetas Pagos Corporativos va en '
                . 'cero, y eso NO significa que no haya facturas que pagar.');

            return $salida;
        }

        $salida['disponible'] = true;
        $facturas = TarjetasCorporativas::universo($pendientes);

        /* LAS FACTURAS DE LOS SEGUROS QUEDAN AFUERA: de la grilla, del total y de
           la serie. Un seguro se proyecta SOLO CON SUS POLIZAS; sus facturas en
           cuotas y la poliza describirian el mismo pago dos veces. Ver
           TarjetasSeguros. */
        $seguros = $this->leer(function () { return $this->segurosDelMaestro(); },
            'los proveedores de seguros del maestro', $salida['avisos'], []);
        $deSeguros = [];
        $sinSeguros = [];

        foreach ($facturas as $f) {
            if (isset($seguros[trim((string) $f['COD_PROVEE'])])) {
                $deSeguros[] = $f;
            } else {
                $sinSeguros[] = $f;
            }
        }

        $facturas = $sinSeguros;

        $vinculos = $this->leer(function () { return $this->vinculo->vigentes(); },
            'los vínculos factura-tarjeta', $salida['avisos']);
        $excluidas = $this->leer(function () { return $this->exclusion->vigentes(); },
            'las facturas excluidas', $salida['avisos']);

        /* LOS VENCIMIENTOS CORREGIDOS EN ESTA PESTANA: reemplazan al de Tango en
           toda la logica. Vacio si falta el script, que es lo cierto. */
        $vtos = $this->leer(function () { return $this->vtoEdit->vigentes(); },
            'los vencimientos corregidos', $salida['avisos'], []);

        $r = TarjetasCorporativas::resolver($facturas, $vinculos, $excluidas, $corporativas,
            $resumenes, $habiles, $meses, $hoy, $vtos);

        /* LAS CORRECCIONES QUE QUEDARON SIN CUOTA: Tango cambio el vencimiento.
           Viajan a la pantalla para poder darlas de baja. */
        $salida['vtos_inertes'] = TarjetasCorporativas::vtosInertes($facturas, $vtos);
        $avisoInertes = TarjetasCorporativas::avisoVtosInertes($salida['vtos_inertes']);

        if ($avisoInertes !== '') {
            $salida['avisos'][] = $avisoInertes;
        }

        /* Quien vinculo y quien excluyo cada factura, y cuando, para el tooltip
           de la fila (Js/auditoria.js). Se agrega aca y no en resolver(), que
           es puro y no necesita saberlo. */
        $audVinculo = $this->leer(function () { return $this->vinculo->auditoriaVigentes(); },
            'quién vinculó cada factura', $salida['avisos']);

        foreach ($r['filas'] as $i => $f) {
            $c = $f['CLAVE'];
            $r['filas'][$i]['VINCULO_USUARIO'] = isset($audVinculo[$c]) ? $audVinculo[$c]['USUARIO_ALTA'] : null;
            $r['filas'][$i]['VINCULO_FECHA'] = isset($audVinculo[$c]) ? $audVinculo[$c]['FECHA_ALTA'] : null;
            $r['filas'][$i]['EXCLUSION_USUARIO'] = isset($excluidas[$c]) ? $excluidas[$c]['USUARIO_ALTA'] : null;
            $r['filas'][$i]['EXCLUSION_FECHA'] = isset($excluidas[$c]) ? $excluidas[$c]['FECHA_ALTA'] : null;
        }

        /* LAS ESTIMACIONES POR PROVEEDOR: las facturas del ultimo mes de cada
           proveedor que proyecta se repiten hasta el fin del horizonte. Se
           comportan como una factura vinculada -cobertura y resumen-. Desde
           feature/tarjetas-resumenes-seguros reemplazan a las marcas mensuales,
           que quedan como historial. Ver
           TarjetasCorporativas::estimacionesPorProveedor(). */
        $marcados = $this->leer(function () { return $this->proveedoresQueProyectan(); },
            'los proveedores que proyectan', $salida['avisos'], []);

        // Un seguro no proyecta por proveedor: se proyecta por poliza.
        $marcados = array_diff_key($marcados, $seguros);

        foreach ($r['filas'] as $i => $f) {
            $r['filas'][$i]['PROVEEDOR_PROYECTA'] = isset($marcados[$f['COD_PROVEE']]);
        }

        $ep = TarjetasCorporativas::estimacionesPorProveedor($marcados, $r['filas'], $tarjetas,
            $resumenes, $habiles, $meses, $hoy);
        $salida['estimaciones'] = $ep['estimaciones'];
        $salida['proveedores_proyectan'] = array_values($marcados);

        foreach ($ep['faltan_calendario'] as $m) {
            if (!in_array($m, $r['faltan_calendario'], true)) {
                $r['faltan_calendario'][] = $m;
            }
        }

        foreach (TarjetasCorporativas::avisosEstimaciones($salida['estimaciones']) as $a) {
            $salida['avisos'][] = $a;
        }

        foreach (TarjetasCorporativas::avisosPorProveedor($marcados, $r['filas']) as $a) {
            $salida['avisos'][] = $a;
        }

        foreach (TarjetasCorporativas::avisosLeyendaRepetida($marcados, $r['filas']) as $a) {
            $salida['avisos'][] = $a;
        }

        $salida['filas'] = $r['filas'];

        foreach (TarjetasVencimiento::avisosCalendario($r['faltan_calendario']) as $a) {
            $salida['avisos'][] = $a;
        }

        /* LOS SEGUROS: las polizas y sus pagos, la linea informativa de sus
           facturas y los avisos. */
        $salida['seguros'] = $this->seguros($h, $seguros, $deSeguros, $tarjetas, $resumenes,
            $habiles, $inflacion, $vinculos, $excluidas, $vtos, $corporativas, $salida['avisos']);

        /* La cobertura se calcula sobre las facturas, las estimaciones y los pagos
           de las polizas que proyectan: los tres se comportan como una factura
           vinculada. */
        $salida['cobertura'] = TarjetasCorporativas::cobertura(array_merge($r['filas'],
            TarjetasCorporativas::estimacionesComoFilas($salida['estimaciones']),
            TarjetasSeguros::comoFilas($salida['seguros']['polizas'])), $corporativas,
            $resumenes, $habiles, $meses, $hoy);

        $salida['resumenes'] = TarjetasCorporativas::resumenesAProyectar($corporativas,
            $resumenes, $meses, $hoy);

        foreach ($salida['resumenes'] as $i => $res) {
            $salida['resumenes'][$i]['nota'] = TarjetasResumen::notaCompartido(
                $resumenes[$res['id_tarjeta']][$res['mes']], $res['id_tarjeta'], $tarjetas);
        }

        /* LO QUE VA AL EJE: las facturas que proyectan, la cobertura y los
           resumenes. Las excluidas van aparte, a su serie informativa. */
        foreach ($r['filas'] as $f) {
            if ($f['PROYECTA'] && $f['FECHA'] !== null) {
                $salida['pagos'][] = ['tipo' => 'FACTURA', 'fecha' => $f['FECHA'],
                                      'importe' => $f['IMPORTE'], 'clave' => $f['CLAVE']];
            }

            if ($f['MOTIVO'] === TarjetasCorporativas::EXCLUIDA && $f['FECHA'] !== null) {
                $salida['pagos_excluidos'][] = ['fecha' => $f['FECHA'],
                                                'importe' => $f['IMPORTE'],
                                                'clave' => $f['CLAVE']];
            }
        }

        foreach ($salida['estimaciones'] as $e) {
            foreach ($e['meses'] as $m) {
                if ($m['proyecta']) {
                    $salida['pagos'][] = ['tipo' => 'ESTIMACION', 'fecha' => $m['fecha'],
                                          'importe' => $m['importe'], 'id_estimacion' => $e['ID'],
                                          'id_tarjeta' => intval($e['ID_TARJETA']), 'mes' => $m['mes'],
                                          'proveedor' => ($e['RAZON_SOC'] ?: $e['COD_PROVEE'])];
                }
            }
        }

        foreach ($salida['seguros']['polizas'] as $p) {
            foreach ($p['resueltos']['pagos'] as $pago) {
                if ($pago['proyecta']) {
                    $salida['pagos'][] = ['tipo' => 'SEGURO', 'fecha' => $pago['fecha'],
                                          'importe' => $pago['importe'], 'id_poliza' => $p['ID'],
                                          'id_tarjeta' => intval($p['ID_TARJETA']),
                                          'mes' => $pago['mes'], 'poliza' => $p['POLIZA'],
                                          'proveedor' => ($p['RAZON_SOC'] ?: $p['COD_PROVEE']),
                                          'continuacion' => $pago['continuacion']];
                }
            }
        }

        foreach ($salida['cobertura'] as $c) {
            if ($c['proyecta']) {
                $salida['pagos'][] = ['tipo' => 'COBERTURA', 'fecha' => $c['fecha'],
                                      'importe' => $c['importe'],
                                      'id_tarjeta' => $c['id_tarjeta'], 'mes' => $c['mes']];
            }
        }

        foreach ($salida['resumenes'] as $res) {
            if ($res['proyecta']) {
                $salida['pagos'][] = ['tipo' => 'RESUMEN', 'fecha' => $res['fecha'],
                                      'importe' => $res['importe'],
                                      'id_tarjeta' => $res['id_tarjeta'],
                                      'mes' => $res['mes']];
            }
        }

        foreach (TarjetasCorporativas::avisosConNivel($r['filas']) as $a) {
            $salida['avisos'][] = $a;
        }

        return $salida;
    }

    /**
     * Los proveedores que proyectan: Mapa COD_PROVEE => fila.
     *
     * Es una costura, como TarjetasProvider::modulo(): lo que este metodo
     * devuelve decide cuanto se estima, y medir el tablero con otra lista -por
     * ejemplo, con los proveedores de las marcas mensuales viejas- no tiene que
     * pasar por escribir en la base.
     *
     * @return array
     */
    protected function proveedoresQueProyectan() {
        return $this->provProyecta->vigentes();
    }

    /**
     * Los proveedores de seguros: RUBRO = SEGUROS y TARJETA CORP en el maestro
     * de Proveedores Locales. Mapa COD_PROVEE => ['COD_PROVEE', 'RAZON_SOC'].
     *
     * Costura, como proveedoresQueProyectan(): medir el tablero con el maestro
     * corregido no tiene que pasar por escribirlo.
     *
     * @return array
     */
    protected function segurosDelMaestro() {
        require_once __DIR__ . '/ProveedoresCategorias.php';

        return TarjetasSeguros::proveedores((new ProveedoresCategorias())->mapa());
    }

    /**
     * La seccion Seguros de Corporativas.
     *
     * @param array $avisos Por referencia: los avisos van a Corporativas
     * @return array ['proveedores', 'polizas', 'facturas', 'tabla_creada']
     */
    private function seguros($h, $seguros, $deSeguros, $tarjetas, $resumenes, $habiles,
                             $inflacion, $vinculos, $excluidas, $vtos, $corporativas, &$avisos) {
        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');

        $salida = [
            'proveedores' => array_values($seguros),
            'polizas' => [],
            'facturas' => TarjetasSeguros::resumenFacturas($deSeguros),
            'tabla_creada' => $this->poliza->tablaCreada()
        ];

        $polizas = $this->leer(function () { return $this->poliza->vigentes(); },
            'las pólizas de seguros', $avisos, []);

        $curva = $this->leer(function () { return (new DolarFuturo())->curva(); },
            'la curva de dólar futuro', $avisos, []);
        $siguientes = TarjetasSeguros::siguientes($polizas);
        $faltanInf = [];
        $faltanCot = [];
        $faltanCal = [];
        $sinTarjeta = [];
        $conPoliza = [];

        foreach ($polizas as $p) {
            $id = intval($p['ID_TARJETA']);
            $t = isset($tarjetas[$id]) ? $tarjetas[$id] : null;

            try {
                $p['resueltos'] = TarjetasSeguros::pagos($p, $t, isset($resumenes[$id]) ? $resumenes[$id] : [],
                    $habiles, $meses, $hoy, $inflacion, $curva, $siguientes[$p['ID']]);
            } catch (Throwable $e) {
                $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudieron calcular los pagos de la póliza '
                    . $p['POLIZA'] . ': ' . $e->getMessage());
                $p['resueltos'] = ['pagos' => [], 'faltan_inflacion' => [], 'faltan_cotizacion' => [],
                                   'faltan_calendario' => [], 'ciclos' => 0];
            }

            $p['TARJETA'] = $t;
            $p['FIN_VIGENCIA'] = TarjetasSeguros::finVigencia($p['FECHA_INICIO'], $p['VIGENCIA_MESES']);
            $p['CUOTA'] = TarjetasSeguros::cuota($p['IMPORTE_TOTAL'], $p['VIGENCIA_MESES'], $p['GRACIA_MESES']);
            $p['SIGUIENTE'] = $siguientes[$p['ID']];
            $p['ES_SEGURO'] = isset($seguros[$p['COD_PROVEE']]);
            $conPoliza[$p['COD_PROVEE']] = true;

            foreach (['faltan_inflacion' => &$faltanInf, 'faltan_cotizacion' => &$faltanCot,
                      'faltan_calendario' => &$faltanCal] as $k => &$lista) {
                foreach ($p['resueltos'][$k] as $m) {
                    if (!in_array($m, $lista, true)) {
                        $lista[] = $m;
                    }
                }
            }

            unset($lista);

            if ($t === null || empty($t['ACTIVA']) || $t['TIPO'] !== 'CORPORATIVA') {
                $sinTarjeta[] = $p['POLIZA'] . ' de ' . ($p['RAZON_SOC'] ?: $p['COD_PROVEE']);
            }

            $salida['polizas'][] = $p;
        }

        if (!empty($faltanInf)) {
            sort($faltanInf);
            $avisos[] = 'Seguros: la continuación de una póliza en pesos necesita la inflación de los '
                . 'meses de la vigencia anterior, y falta la de ' . implode(', ', $faltanInf)
                . '. Esos pagos quedan en blanco y NO en cero.';
        }

        if (!empty($faltanCot)) {
            $avisos[] = 'Seguros: no hay dólar futuro para ' . implode(', ', $faltanCot)
                . ', así que los pagos en dólares de esos meses quedan en blanco y NO en cero.';
        }

        if (!empty($sinTarjeta)) {
            $avisos[] = count($sinTarjeta) . ' póliza(s) tienen una tarjeta inactiva o que ya no es '
                . 'corporativa, así que no proyectan: ' . implode('; ', $sinTarjeta)
                . '. Corregí la tarjeta de la póliza.';
        }

        foreach (TarjetasVencimiento::avisosCalendario($faltanCal) as $a) {
            $avisos[] = $a;
        }

        /* UN SEGURO SIN POLIZA: sus facturas ya no suman, y la poliza que las
           reemplaza no esta. El aviso dice cuanto proyectaban, con la misma regla
           que la grilla: es la plata que el tablero deja de ver. */
        $porProv = [];

        foreach ($deSeguros as $f) {
            $porProv[trim((string) $f['COD_PROVEE'])][] = $f;
        }

        foreach ($seguros as $cod => $s) {
            if (isset($conPoliza[$cod])) {
                continue;
            }

            $proyectaban = 0.0;

            if (isset($porProv[$cod])) {
                $rs = TarjetasCorporativas::resolver($porProv[$cod], $vinculos, $excluidas,
                    $corporativas, $resumenes, $habiles, $meses, $hoy, $vtos);

                foreach ($rs['filas'] as $f) {
                    if ($f['PROYECTA']) {
                        $proyectaban += $f['IMPORTE'];
                    }
                }
            }

            $avisos[] = Aviso::nuevo(Aviso::WARNING, 'Seguros · ' . $s['RAZON_SOC'] . ' no tiene póliza '
                . 'cargada, así que no proyecta nada'
                . ($proyectaban > 0
                    ? ': sus facturas proyectaban ' . self::plata($proyectaban) . ' en el horizonte, y '
                      . 'ya no suman'
                    : '')
                . '. Cargá su póliza en la sección Seguros.');
        }

        if ($salida['facturas']['cuantas'] > 0) {
            $avisos[] = Aviso::nuevo(Aviso::INFO, 'Hay ' . $salida['facturas']['cuantas']
                . ' vencimiento(s) de seguros pendientes en Tango, de '
                . $salida['facturas']['comprobantes'] . ' factura(s), por '
                . self::plata($salida['facturas']['importe']) . '. No suman: los seguros se '
                . 'proyectan con las pólizas.');
        }

        return $salida;
    }

    /* ====================================================================
       TARJETAS SOCIOS
       ==================================================================== */

    /**
     * La sub-pestana Tarjetas Socios.
     *
     * @return array
     */
    private function socios($h, $tarjetas, $resumenes, $habiles, $inflacion) {
        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');
        $mesActual = substr($hoy, 0, 7);

        $salida = [
            'filas' => [],
            'pagos' => [],
            'avisos' => [],
            'cartel' => '',
            'bcra' => null,
            'curva' => []
        ];

        /* POR BANCO desde feature/tarjetas-resumenes-seguros: una fila por banco
           de socios activo. Las tarjetas de socio con nombre quedaron de baja y
           NO proyectan; se leen solo para decir cuanto proyectaban mientras su
           banco no tenga base historica. */
        $socios = [];
        $viejas = [];

        foreach ($tarjetas as $id => $t) {
            if ($t['TIPO'] === Tarjetas::SOCIOS_BANCO && $t['ACTIVA']) {
                $socios[$id] = $t;
            } elseif ($t['TIPO'] === Tarjetas::SOCIO_HISTORIAL
                      && ($t['ACTIVA'] || $t['USUARIO_BAJA'] === 'MIGRACION')) {
                $viejas[$t['COD_BANCO']][$id] = $t;
            }
        }

        /* LAS DOS COTIZACIONES, leidas UNA vez para todas las tarjetas. Las dos
           dentro de su try: la que falle deja su parte en null y la otra sigue
           sirviendo. */
        $bcra = $this->leer(function () use ($hoy) {
            $c = new Cotizacion();

            return $c->ultimaHasta($hoy, Cotizacion::COMPRADOR);
        }, 'la cotización del dólar oficial del BCRA', $salida['avisos']);

        $curva = $this->leer(function () {
            $df = new DolarFuturo();

            return $df->curva();
        }, 'la curva de dólar futuro', $salida['avisos']);

        $salida['bcra'] = $bcra;
        $salida['curva'] = $curva;
        $salida['cartel'] = TarjetasSocios::explicarDolar($bcra, $curva);

        $faltanInflacion = [];
        $faltanCalendario = [];

        foreach ($socios as $id => $t) {
            $deLaTarjeta = isset($resumenes[$id]) ? $resumenes[$id] : [];
            $base = TarjetasSocios::base($deLaTarjeta, $mesActual);

            $e = TarjetasSocios::estimar($t, $base, $meses, $inflacion, $deLaTarjeta,
                $habiles, $curva, $bcra, $hoy);

            $fila = [
                'tarjeta' => $t,
                'base' => $base,
                'motivo' => $e['motivo'],
                'proximo' => $e['proximo'],
                'celdas' => [],
                'total_ars' => 0.0
            ];

            foreach ($meses as $mes) {
                $celda = $e['meses'][$mes];
                $celda['tooltip'] = TarjetasSocios::explicar($celda, $base);
                $fila['celdas'][$mes] = $celda;

                if ($celda['proyecta'] && $celda['total_ars'] !== null) {
                    $fila['total_ars'] += $celda['total_ars'];

                    $salida['pagos'][] = [
                        'id_tarjeta' => $id,
                        'mes' => $mes,
                        'fecha' => $celda['fecha'],
                        'importe' => $celda['total_ars'],
                        'origen' => $celda['origen']
                    ];
                }
            }

            $avisoBase = TarjetasSocios::avisoBase($base, $t['ROTULO']);

            /* SIN BASE, EL AVISO DICE CUANTO PROYECTABAN SUS TARJETAS VIEJAS: es
               la plata que el tablero no ve hasta que se cargue la base del
               banco. */
            if ($base['motivo'] === TarjetasSocios::SIN_BASE && isset($viejas[$t['COD_BANCO']])) {
                $avisoBase .= ' Sus tarjetas de socio proyectaban '
                    . self::plata($this->proyeccionViejas($viejas[$t['COD_BANCO']], $resumenes,
                        $meses, $inflacion, $habiles, $curva, $bcra, $hoy))
                    . ' en el horizonte, y eso hoy no está en el tablero.';
            }

            /* Sin base no hay estimacion y se arregla cargandola: atencion. La
               base incompleta solo explica que el promedio es menos firme:
               informativo. */
            if ($avisoBase !== '') {
                $salida['avisos'][] = Aviso::nuevo($base['motivo'] === TarjetasSocios::SIN_BASE
                    ? Aviso::WARNING : Aviso::INFO, $avisoBase);
            }

            foreach ($e['faltan_inflacion'] as $m) {
                if (!in_array($m, $faltanInflacion, true)) {
                    $faltanInflacion[] = $m;
                }
            }

            foreach ($e['faltan_calendario'] as $m) {
                if (!in_array($m, $faltanCalendario, true)) {
                    $faltanCalendario[] = $m;
                }
            }

            /* LOS MESES SIN COTIZACION, por tarjeta: es lo unico accionable -o se
               espera a que la API traiga la curva, o se revisa por que no esta- y
               un aviso por mes y por tarjeta seria ruido. */
            if (!empty($e['faltan_cotizacion'])) {
                $salida['avisos'][] = $t['ROTULO'] . ': ' . count($e['faltan_cotizacion'])
                    . ' mes(es) quedaron sin valuar el componente en dólares por falta de '
                    . 'cotización (' . implode(', ', $e['faltan_cotizacion']) . '). Quedan en '
                    . 'blanco y NO en cero.';
            }

            $salida['filas'][] = $fila;
        }

        /* UN BANCO CON TARJETAS DE SOCIO VIEJAS QUE TODAVIA NO ESTA DADO DE ALTA
           COMO BANCO DE SOCIOS: no tiene fila, asi que el aviso de base no lo
           alcanza. Sin esto, su plata desaparece del tablero sin que nada lo
           diga. */
        $conBanco = [];

        foreach ($socios as $t) {
            $conBanco[$t['COD_BANCO']] = true;
        }

        foreach ($viejas as $cod => $suyas) {
            if (isset($conBanco[$cod])) {
                continue;
            }

            $una = reset($suyas);
            $nombre = $una['DESC_BANCO'] ?: ('banco ' . $cod);

            $salida['avisos'][] = Aviso::nuevo(Aviso::WARNING, 'Socios · ' . $nombre . ': sus '
                . count($suyas) . ' tarjeta(s) de socio proyectaban '
                . self::plata($this->proyeccionViejas($suyas, $resumenes, $meses, $inflacion,
                    $habiles, $curva, $bcra, $hoy))
                . ' en el horizonte y ya no proyectan: Socios va por banco. Dá de alta el banco en '
                . 'Parámetros › Tarjetas y cargale la base histórica. Hasta entonces eso no está en '
                . 'el tablero.');
        }

        sort($faltanInflacion);
        $avisoInf = Inflacion::avisoFaltan($faltanInflacion, $hoy);

        if ($avisoInf !== '') {
            // Sin prefijo: ver el de Gastos Supervisoras.
            $salida['avisos'][] = $avisoInf;
        }

        foreach (TarjetasVencimiento::avisosCalendario($faltanCalendario) as $a) {
            $salida['avisos'][] = $a;
        }

        return $salida;
    }

    /**
     * Cuanto proyectaban, sumadas, unas tarjetas de socio con nombre: como si
     * siguieran activas, con su base y su regla. Solo para el aviso.
     *
     * @return float
     */
    private function proyeccionViejas($tarjetas, $resumenes, $meses, $inflacion, $habiles,
                                      $curva, $bcra, $hoy) {
        $total = 0.0;

        foreach ($tarjetas as $id => $t) {
            try {
                $total += TarjetasSocios::totalProyectado($t, isset($resumenes[$id]) ? $resumenes[$id] : [],
                    $meses, $inflacion, $habiles, $curva, $bcra, $hoy);
            } catch (Throwable $e) {
                // Un calendario roto ya avisa por su lado: el aviso dice lo que se pudo sumar.
            }
        }

        return $total;
    }

    /* ====================================================================
       LOS INSUMOS
       ==================================================================== */

    /**
     * Las tarjetas, indexadas por ID. Se traen TODAS, tambien las inactivas: la
     * pantalla las muestra y cada sub-pestana filtra las que le sirven.
     *
     * @return array
     */
    private function leerTarjetas() {
        return $this->leer(function () { return $this->tarjetas->porId(false); },
            'las tarjetas', $this->avisos, []);
    }

    /** Los resumenes vigentes, por tarjeta y mes. @return array */
    private function leerResumenes() {
        return $this->leer(function () { return $this->resumen->vigentes(); },
            'los resúmenes de tarjeta', $this->avisos, []);
    }

    /**
     * El calendario bancario para todo el horizonte MAS la cola del corrimiento.
     *
     * VA HACIA ADELANTE, al contrario del rango del cronograma de pagos: el
     * vencimiento de una tarjeta se corre al dia habil SIGUIENTE, asi que un dia 31
     * del ultimo mes del eje puede terminar en el mes siguiente. Ver
     * TarjetasVencimiento::rangoCalendario().
     *
     * @param Horizonte $h
     * @return array
     */
    private function leerHabiles($h) {
        $rango = TarjetasVencimiento::rangoCalendario($h);
        $habiles = $this->cronograma->habilesEntre($rango['desde'], $rango['hasta']);

        foreach ($this->cronograma->avisosConNivel() as $a) {
            $this->avisos[] = $a;
        }

        return $habiles;
    }

    /** El cronograma de pagos del horizonte. @return array */
    private function leerCronograma($h) {
        try {
            return $this->cronograma->paraHorizonte($h, 'SUPERVISORAS');
        } catch (Throwable $e) {
            return ['pagos' => [], 'tabla_creada' => false,
                    'avisos' => [Aviso::nuevo(Aviso::DANGER, 'No se pudo resolver el cronograma '
                        . 'de pagos (' . $e->getMessage() . '), así que la parte en EFECTIVO de '
                        . 'Gastos Supervisoras no tiene fechas y no se proyecta.')]];
        }
    }

    /** El mapa de inflacion mensual. @return array */
    private function leerInflacion() {
        $mapa = $this->leer(function () { return $this->inflacion->valores(); },
            'la inflación mensual esperada', $this->avisos, []);

        $sinTabla = $this->inflacion->avisoSinTabla();

        if ($sinTabla !== '') {
            // Sin inflacion los meses que la necesitan quedan sin proyectar.
            $this->avisos[] = Aviso::nuevo(Aviso::DANGER, $sinTabla);
        }

        return $mapa;
    }

    /** Los avisos del maestro de tarjetas */
    private function maestroAvisos() {
        try {
            return $this->tarjetas->avisosConNivel();
        } catch (Throwable $e) {
            return [Aviso::nuevo(Aviso::DANGER, 'No se pudieron revisar las tarjetas: '
                . $e->getMessage())];
        }
    }

    /**
     * Corre una lectura dentro de un try y deja el motivo en los avisos si falla.
     *
     * ESTA ESCRITO UNA VEZ porque son nueve lecturas con exactamente la misma
     * forma, y nueve try/catch copiados divergen en el primero que alguien corrige.
     * Lo que cambia entre ellas es QUE se lee y como se llama en el mensaje.
     *
     * @param callable $fn
     * @param string $que Para el mensaje
     * @param array $avisos Por referencia
     * @param mixed $default Que devolver si falla
     * @return mixed
     */
    private function leer($fn, $que, &$avisos, $default = null) {
        try {
            return $fn();
        } catch (Throwable $e) {
            // Critico: una lectura que fallo deja una parte sin resolver.
            $avisos[] = Aviso::nuevo(Aviso::DANGER, 'No se pudo leer ' . $que . ': '
                . $e->getMessage() . '. La parte que depende de eso queda sin resolver.');

            return $default;
        }
    }

    /** Un importe con el formato del modulo */
    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }
}
