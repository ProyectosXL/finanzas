<?php

require_once __DIR__ . '/Tarjetas.php';
require_once __DIR__ . '/TarjetasResumen.php';
require_once __DIR__ . '/TarjetasFactura.php';
require_once __DIR__ . '/TarjetasExclusion.php';
require_once __DIR__ . '/TarjetasVencimiento.php';
require_once __DIR__ . '/TarjetasSupervisoras.php';
require_once __DIR__ . '/TarjetasCorporativas.php';
require_once __DIR__ . '/TarjetasSocios.php';
require_once __DIR__ . '/GastosSupervision.php';
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

    /** @var GastosSupervision */
    private $gastos;

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
        $this->gastos = new GastosSupervision();
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
                'factura' => $this->vinculo->tablaCreada(),
                'exclusion' => $this->exclusion->tablaCreada(),
                'gastos' => $this->gastos->tablaCreada(),
                'vista_usuarios' => $this->tarjetas->vistaUsuariosCreada()
            ],
            'tarjetas' => $tarjetas,
            'supervisoras' => $this->supervisoras($h, $tarjetas, $resumenes, $habiles,
                                                  $inflacion),
            'corporativas' => $this->corporativas($h, $tarjetas, $resumenes, $habiles),
            'socios' => $this->socios($h, $tarjetas, $resumenes, $habiles, $inflacion)
        ];

        /* LOS AVISOS DEL MAESTRO VAN PRIMERO: un script que falta explica por que
           las tres sub-pestanas estan vacias, y ponerlo despues de los avisos de
           cada una lo escondería. */
        $datos['avisos'] = array_merge($this->maestroAvisos(), $this->avisos);

        return $datos;
    }

    /** @return array Avisos del modulo */
    public function avisos() {
        return $this->avisos;
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
            $salida['avisos'][] = 'No se pudieron leer los gastos de supervisión: '
                . $e->getMessage() . '. Gastos Supervisoras va en cero.';

            return $salida;
        }

        foreach ($base['avisos'] as $a) {
            $salida['avisos'][] = $a;
        }

        if (!$base['tabla_creada']) {
            return $salida;
        }

        $salida['disponible'] = true;

        $promedios = TarjetasSupervisoras::promedios($base['gastos'], $ventana);
        $estimado = TarjetasSupervisoras::estimar($promedios, $meses, $inflacion, $ventana,
            $base['estados']);

        /* LAS TARJETAS DE TIPO SUPERVISORA SE ASOCIAN POR EL NOMBRE DEL USUARIO.
           Ver TarjetasSupervisoras y el README: es el unico tipo que cruza contra
           algo, y lo hace por nombre porque RO_T_GASTOS_SUPERVISION guarda el
           nombre y no un ID. */
        $porSupervisora = $this->tarjetasPorSupervisora($tarjetas);

        /* EL CRONOGRAMA DE PAGOS, el MISMO que usa Logistica Local: el 2do y el
           4to viernes de Parametros -> Generales, con sus overrides. */
        $crono = $this->leerCronograma($h);
        $porMes = [];

        foreach ($crono['pagos'] as $p) {
            $porMes[$p['mes']][intval($p['nro'])] = $p;
        }

        foreach ($crono['avisos'] as $a) {
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

            foreach ($meses as $mes) {
                $celda = $fila['meses'][$mes];

                /* EL EFECTIVO: dos pagos iguales del cronograma. */
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
            $avisos[] = count($nombres) . ' supervisora(s) '
                . (isset($textos[$motivo]) ? $textos[$motivo] : 'no se proyectan')
                . ', así que no se proyectan: ' . implode('; ', $nombres) . '. Sus gastos se ven '
                . 'en la grilla; lo que no entra al tablero es su proyección.';
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

            if (!empty($sinGastos)) {
                $avisos[] = count($sinGastos) . ' supervisora(s) están activas y NO tienen '
                    . 'gastos autorizados en la ventana ' . $ventana['rotulo'] . ', así que no '
                    . 'aparecen en la grilla y no se proyecta nada por ellas: '
                    . implode(', ', $sinGastos) . '. No se les pone un promedio en cero —eso '
                    . 'afirmaría que no gastan—; si cargaron gastos y no están autorizados, '
                    . 'todavía no cuentan.';
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
            $avisos[] = 'Gastos Supervisoras: ' . $avisoInf;
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
    private function corporativas($h, $tarjetas, $resumenes, $habiles) {
        $hoy = $h->hoy();
        $meses = array_column($h->meses(), 'clave');

        $salida = [
            'filas' => [],
            'cobertura' => [],
            'resumenes' => [],
            'pagos' => [],
            'pagos_excluidos' => [],
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
            $salida['avisos'][] = 'No se pudieron leer las cuentas a pagar de Tango: '
                . $e->getMessage() . '. Tarjetas Pagos Corporativos va en cero, y eso NO '
                . 'significa que no haya facturas que pagar.';

            return $salida;
        }

        $salida['disponible'] = true;
        $facturas = TarjetasCorporativas::universo($pendientes);

        $vinculos = $this->leer(function () { return $this->vinculo->vigentes(); },
            'los vínculos factura-tarjeta', $salida['avisos']);
        $excluidas = $this->leer(function () { return $this->exclusion->vigentes(); },
            'las facturas excluidas', $salida['avisos']);

        $r = TarjetasCorporativas::resolver($facturas, $vinculos, $excluidas, $corporativas,
            $resumenes, $habiles, $meses, $hoy);

        $salida['filas'] = $r['filas'];

        foreach (TarjetasVencimiento::avisosCalendario($r['faltan_calendario']) as $a) {
            $salida['avisos'][] = $a;
        }

        $salida['cobertura'] = TarjetasCorporativas::cobertura($r['filas'], $corporativas,
            $resumenes, $habiles, $meses, $hoy);

        $salida['resumenes'] = TarjetasCorporativas::resumenesAProyectar($corporativas,
            $resumenes, $meses, $hoy);

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

        foreach (TarjetasCorporativas::avisos($r['filas'], $this->soloDe($resumenes,
                $corporativas)) as $a) {
            $salida['avisos'][] = $a;
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

        $socios = [];

        foreach ($tarjetas as $id => $t) {
            if ($t['TIPO'] === 'SOCIO' && $t['ACTIVA']) {
                $socios[$id] = $t;
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

            if ($avisoBase !== '') {
                $salida['avisos'][] = $avisoBase;
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

        sort($faltanInflacion);
        $avisoInf = Inflacion::avisoFaltan($faltanInflacion, $hoy);

        if ($avisoInf !== '') {
            $salida['avisos'][] = 'Tarjetas Socios: ' . $avisoInf;
        }

        foreach (TarjetasVencimiento::avisosCalendario($faltanCalendario) as $a) {
            $salida['avisos'][] = $a;
        }

        return $salida;
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

        foreach ($this->cronograma->avisos() as $a) {
            $this->avisos[] = $a;
        }

        return $habiles;
    }

    /** El cronograma de pagos del horizonte. @return array */
    private function leerCronograma($h) {
        try {
            return $this->cronograma->paraHorizonte($h);
        } catch (Throwable $e) {
            return ['pagos' => [], 'tabla_creada' => false,
                    'avisos' => ['No se pudo resolver el cronograma de pagos ('
                        . $e->getMessage() . '), así que la parte en EFECTIVO de Gastos '
                        . 'Supervisoras no tiene fechas y no se proyecta.']];
        }
    }

    /** El mapa de inflacion mensual. @return array */
    private function leerInflacion() {
        $mapa = $this->leer(function () { return $this->inflacion->valores(); },
            'la inflación mensual esperada', $this->avisos, []);

        $sinTabla = $this->inflacion->avisoSinTabla();

        if ($sinTabla !== '') {
            $this->avisos[] = $sinTabla;
        }

        return $mapa;
    }

    /** Los avisos del maestro de tarjetas */
    private function maestroAvisos() {
        try {
            return $this->tarjetas->avisos();
        } catch (Throwable $e) {
            return ['No se pudieron revisar las tarjetas: ' . $e->getMessage()];
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
            $avisos[] = 'No se pudo leer ' . $que . ': ' . $e->getMessage()
                . '. La parte que depende de eso queda sin resolver.';

            return $default;
        }
    }

    /**
     * Los resumenes de un subconjunto de tarjetas.
     *
     * Hace falta para que los avisos de una sub-pestana no miren los resumenes de
     * las otras: el aviso de posible doble conteo de Corporativas se dispara con
     * "hay un resumen para este mes", y con los resumenes de todas las tarjetas se
     * dispararia por el de una supervisora.
     *
     * @param array $resumenes Mapa ID_TARJETA => ['Y-m' => resumen]
     * @param array $tarjetas Mapa ID => tarjeta
     * @return array
     */
    private function soloDe($resumenes, $tarjetas) {
        $v = [];

        foreach ($resumenes as $id => $porMes) {
            if (isset($tarjetas[$id])) {
                $v[$id] = $porMes;
            }
        }

        return $v;
    }

    /** Un importe con el formato del modulo */
    private static function plata($n) {
        return ($n === null) ? '—' : ('$ ' . number_format($n, 2, ',', '.'));
    }
}
