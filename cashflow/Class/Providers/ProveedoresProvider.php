<?php

require_once __DIR__ . '/../CashflowProvider.php';
require_once __DIR__ . '/../Proveedores.php';

/**
 * ProveedoresProvider
 * Alimenta el tablero con las cuentas a pagar locales.
 *
 * Sirve un solo codigo del registro: PROV_LOCALES.
 *
 * ES UN EGRESO, PERO DEVUELVE IMPORTES POSITIVOS. El signo lo pone el TIPO de la
 * fila y no el dato, igual que todos los demas proveedores de egresos. Ver la
 * nota de sql/cashflow_estructura.sql.
 *
 * UNA CONSULTA, VARIAS SERIES
 * ---------------------------
 * Los pendientes se leen UNA vez y se reparten en varias series. El motor pide
 * las series de un proveedor una sola vez por pedido, asi que exponer diez no
 * cuesta diez consultas.
 *
 * LAS SERIES FIJAS son las que permiten armar el cuadro sin depender del
 * maestro. OJO CON LA PRIMERA: PAGOS *NO* TRAE TODO.
 *
 *   PAGOS_TODO             el universo: todas las formas, todos los rubros
 *   PAGOS                  solo el cronograma: echeq, transferencia, y lo que
 *                          no tiene forma conocida. Es la que usa la fila
 *   PAGOS_FUERA_CRONOGRAMA lo que el criterio del cronograma deja afuera
 *   PAGOS_OPERATIVOS       todo MENOS los proveedores marcados "Excluidos"
 *   PAGOS_EXCLUIDOS        solo los "Excluidos" (socios y movimientos que no
 *                          son deuda comercial)
 *   PAGOS_CRONO_OPERATIVOS los dos criterios a la vez
 *   PAGOS_SIN_RUBRO        los que no estan en el maestro
 *
 * Con ellas, sacar los Excluidos del tablero SIN perder el criterio del
 * cronograma es apuntar la fila a PAGOS_CRONO_OPERATIVOS desde Parametros:
 * configuracion, no codigo.
 *
 * LAS SERIES POR RUBRO son dinamicas: hay una por cada rubro economico que
 * exista en el maestro. No se pueden escribir en el registro porque son DATOS
 * -los escribe administracion en una planilla-, asi que el registro las pide
 * cuando las necesita. Ver CashflowRegistry y seriesDeRubro().
 *
 * Con ellas, partir la fila "Cuentas a Pagar Locales" en alquileres, impuestos,
 * logistica y mercaderia -cada una en su seccion- es agregar filas desde
 * Parametros. El motor no se toca y este proveedor tampoco.
 *
 * EL TOTAL Y SUS PARTES NO PUEDEN CONVIVIR. PAGOS trae todo y las demas son
 * aperturas suyas: activar las dos contaria dos veces el mismo importe. El
 * registro declara la relacion en 'componentes' y el validador la rechaza.
 *
 * LO VENCIDO SIN FECHA CARGADA SE INFORMA, NO SE ESCONDE
 * ------------------------------------------------------
 * Al 16/09/2026 son 378 vencimientos por $965 millones. Entran al eje en su
 * primer dia -no hay otro lugar donde ponerlos- y el aviso dice cuanto es, para
 * que nadie lea esa columna como "hoy se paga todo esto". Ver
 * Proveedores::avisosPendientes().
 */
class ProveedoresProvider extends CashflowProvider {

    /**
     * LA SERIE QUE USA LA FILA DEL TABLERO, y NO trae todo.
     *
     * Trae unicamente lo que se gestiona desde el cronograma de pagos: echeq,
     * transferencia, y lo que no tiene forma conocida. Es una decision de
     * negocio, no un detalle: deja fuera del cashflow los debitos automaticos,
     * la caja y la tarjeta corporativa, que igual salen de la caja. Al
     * 16/09/2026 son $51,8 millones en 257 vencimientos.
     *
     * POR ESO EL PROVEEDOR AVISA cuanto quedo afuera cada vez. Si esa plata
     * tiene que entrar al tablero por otra fila, todavia no existe; mientras
     * tanto, el aviso es lo unico que impide que desaparezca en silencio.
     *
     * Para ver el universo completo esta PAGOS_TODO.
     */
    const SERIE_TOTAL = 'PAGOS';

    /** Todas las formas de pago, sin el criterio del cronograma */
    const SERIE_TODO = 'PAGOS_TODO';

    /** Lo que el criterio del cronograma deja afuera */
    const SERIE_FUERA = 'PAGOS_FUERA_CRONOGRAMA';

    /** Las aperturas fijas, que no dependen de que el maestro este cargado */
    const SERIE_OPERATIVOS = 'PAGOS_OPERATIVOS';
    const SERIE_EXCLUIDOS = 'PAGOS_EXCLUIDOS';
    const SERIE_SIN_RUBRO = 'PAGOS_SIN_RUBRO';

    /**
     * LOS DOS CRITERIOS A LA VEZ: cronograma Y sin los rubros Excluidos.
     *
     * Existe porque las dos particiones son INDEPENDIENTES y hasta ahora no
     * habia forma de aplicar las dos. La fila del tablero podia traer el
     * cronograma -y entonces se llevaba tambien a los socios que cobran por
     * transferencia- o podia traer los operativos -y entonces se llevaba
     * tambien los debitos automaticos, que no se planifican-. Ninguna de las
     * dos es lo que la fila quiere decir.
     *
     * No es teorico: hoy la serie PAGOS incluye $109,6 millones de un solo
     * proveedor con rubro Excluidos que cobra por TRANSFERENCIA.
     */
    const SERIE_CRONO_OPERATIVOS = 'PAGOS_CRONO_OPERATIVOS';

    /**
     * LAS FACTURAS EXCLUIDAS A MANO, una por una.
     *
     * Es el tercer miembro del corte "por como se paga", y por eso existe como
     * serie propia en vez de reusar SERIE_EXCLUIDOS.
     *
     * EL MOTIVO ES CONCRETO: la fila del tablero usa PAGOS, y PAGOS pertenece al
     * corte del cronograma, que NO excluye nada. Mandar la factura tildada solo
     * a PAGOS_EXCLUIDOS la habria sacado de PAGOS_CRONO_OPERATIVOS, que hoy no
     * usa ninguna fila: el tilde no habria movido un peso del cashflow.
     *
     * Verificado al escribirlo: la fila esta configurada con ORIGEN_SERIE =
     * 'PAGOS' y ahi adentro hay $72.500.993,87 en 8 vencimientos de un proveedor
     * con rubro Excluidos que entran igual porque cobra por echeq.
     *
     * Asi el corte sigue cerrando con tres partes en vez de dos:
     *
     *     PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA = PAGOS_TODO
     *
     * El importe no desaparece: queda en su propia serie, visible y auditable, y
     * el proveedor avisa cuanto es y por que.
     */
    const SERIE_EXCLUIDOS_FACTURA = 'PAGOS_EXCLUIDOS_FACTURA';

    /**
     * LA DEUDA DE LOS PROVEEDORES EXCLUIDOS DE ESTE MODULO, entera.
     *
     * Son proveedores cuya deuda ya se considera en otra pestana -la aduana en
     * Crono Nacionalizacion, por ejemplo- y que proyectarlos tambien aca
     * contaria dos veces. Los marca quien maneja Proveedores Locales, desde la
     * solapa Maestro. Ver ProveedoresExclusion.
     *
     * Es el CUARTO miembro del corte "por como se paga", por lo mismo que la
     * exclusion por factura es el tercero: la fila del tablero usa PAGOS, y
     * solo una serie propia en ese corte saca el importe de ahi.
     *
     *     PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA
     *           + PAGOS_EXCLUIDOS_PROVEEDOR = PAGOS_TODO
     *
     * SI LA FACTURA TAMBIEN ESTA EXCLUIDA A MANO, GANA EL PROVEEDOR: el importe
     * va una sola vez, aca. El tilde de la factura queda guardado y vuelve a
     * aplicar solo si el proveedor se reincluye.
     */
    const SERIE_EXCLUIDOS_PROVEEDOR = 'PAGOS_EXCLUIDOS_PROVEEDOR';

    protected function calcular($h) {
        if ($this->codigo() !== 'PROV_LOCALES') {
            $this->avisar('Proveedores Locales: el codigo de proveedor "' . $this->codigo()
                . '" no tiene serie definida.');

            return [];
        }

        $prov = new Proveedores();

        foreach ($prov->getAvisos() as $aviso) {
            $this->avisar($aviso);
        }

        $items = $prov->getPendientes($h->hoy());

        foreach (Proveedores::avisosPendientes($items) as $aviso) {
            $this->avisar($aviso);
        }

        // Un proveedor con deuda que no esta en el maestro no se clasifica, y
        // eso hay que decirlo ACA y no solo en la pestana: quien mira el tablero
        // tiene que saber que parte del importe no esta pudiendo abrirse.
        $this->avisarFaltantes($prov, $items);
        $this->avisarFueraDelCronograma($items);
        $this->avisarExcluidasAMano($items);

        foreach (self::avisosExcluidosProveedor($items) as $aviso) {
            $this->avisar($aviso);
        }

        return $this->repartir($h, $items);
    }

    /**
     * Avisa cuanta deuda queda FUERA de la fila del tablero.
     *
     * ES EL AVISO MAS IMPORTANTE DE ESTE PROVEEDOR. La fila trae solo lo que se
     * gestiona por cronograma -echeq y transferencia-, asi que un debito
     * automatico, una compra con tarjeta corporativa o un pago por caja NO se
     * proyectan en el cashflow, aunque esa plata igual salga.
     *
     * Al 16/09/2026 son $51,8 millones, todos DEBITO. Si tienen que entrar por
     * otra fila, esa fila todavia no existe; mientras tanto este aviso es lo
     * unico que impide que la plata desaparezca del tablero sin que nadie lo
     * note.
     *
     * Se desglosa por forma de pago porque cada una se resuelve distinto: un
     * debito automatico podria entrar por Financiero, y una caja por Haberes o
     * por Caja Locales.
     *
     * @param array $items
     */
    private function avisarFueraDelCronograma($items) {
        $porForma = [];
        $total = 0;

        foreach ($items as $item) {
            /* UN PROVEEDOR EXCLUIDO NO SE CUENTA ACA: este aviso dice "queda
               fuera del tablero y igual va a salir de la caja", y de un
               excluido se decidio justo lo contrario -que ya esta contado en
               otra pestana-. Tiene su propio aviso. */
            if (!empty($item['CRONOGRAMA']) || !empty($item['EXCLUIDO_PROVEEDOR'])) {
                continue;
            }

            /* La forma DEL MAESTRO, que es la que decide. Ver el comentario de
               CRONOGRAMA en Proveedores::getPendientes(). */
            $forma = ($item['FORMA_PAGO_MAESTRO'] === null)
                ? 'sin forma' : $item['FORMA_PAGO_MAESTRO'];
            $importe = floatval($item['IMPORTE_PENDIENTE']);

            $porForma[$forma] = (isset($porForma[$forma]) ? $porForma[$forma] : 0) + $importe;
            $total += $importe;
        }

        if ($total == 0) {
            return;
        }

        arsort($porForma);
        $detalle = [];

        foreach ($porForma as $forma => $importe) {
            $detalle[] = $forma . ' $ ' . number_format($importe, 2, ',', '.');
        }

        $this->avisar('Cuentas a Pagar Locales: la fila trae SÓLO lo que se paga por echeq o '
            . 'transferencia. Quedan fuera del tablero $ ' . number_format($total, 2, ',', '.')
            . ' (' . implode('; ', $detalle) . '), que igual van a salir de la caja. El detalle '
            . 'está en la pestaña, quitando el filtro por forma de pago.');
    }

    /**
     * Avisa cuanta deuda se saco del cashflow tildandola factura por factura.
     *
     * ES PLATA QUE EL TABLERO DEJA DE MOSTRAR POR UNA DECISION, y por eso se
     * avisa: el modulo entero esta construido sobre que nada desaparezca sin
     * decir por que. Un tilde puesto en marzo que nadie recuerda es exactamente
     * lo que este aviso evita.
     *
     * SE NOMBRAN LOS MOTIVOS, hasta tres. El motivo es obligatorio al tildar, y
     * sin traerlo hasta aca el aviso diria cuanta plata falta pero no por que,
     * que obliga a abrir la pestana igual.
     *
     * @param array $items
     */
    private function avisarExcluidasAMano($items) {
        $total = 0;
        $cuantas = 0;
        $motivos = [];

        foreach ($items as $item) {
            /* La de un proveedor excluido no se cuenta aca: su importe va a la
               serie del proveedor y lo informa avisosExcluidosProveedor().
               Contarla en los dos avisos la sumaria dos veces. */
            if (empty($item['EXCLUIDA_MANUAL']) || !empty($item['EXCLUIDO_PROVEEDOR'])) {
                continue;
            }

            $total += floatval($item['IMPORTE_PENDIENTE']);
            $cuantas++;

            $m = trim((string) $item['MOTIVO_EXCLUSION']);

            if ($m !== '' && !in_array($m, $motivos, true)) {
                $motivos[] = $m;
            }
        }

        if ($cuantas === 0) {
            return;
        }

        $detalle = '';

        if (!empty($motivos)) {
            $primeros = array_slice($motivos, 0, 3);
            $detalle = ' Motivos: ' . implode('; ', $primeros)
                . (count($motivos) > 3 ? '; y ' . (count($motivos) - 3) . ' más.' : '.');
        }

        $this->avisar('Cuentas a Pagar Locales: ' . $cuantas . ' factura(s) por $ '
            . number_format($total, 2, ',', '.') . ' están excluidas a mano y no entran al '
            . 'cashflow.' . $detalle . ' Se ven en la pestaña, con el motivo al lado.');
    }

    /**
     * El aviso por la deuda de los proveedores excluidos de este modulo.
     *
     * ES PLATA QUE LA FILA DEL TABLERO DEJA DE MOSTRAR POR UNA DECISION, igual
     * que la de las facturas excluidas a mano, y se avisa por lo mismo: una
     * exclusion puesta hace meses que nadie recuerda es justo lo que este aviso
     * evita.
     *
     * DICE CUANTO ESTABA EN LA FILA -PAGOS, lo del cronograma- y cuanto no:
     * excluir a un proveedor que cobra por debito no mueve la fila, y el aviso
     * no puede dar a entender que si. Nombra a los proveedores, hasta cinco,
     * porque son pocos y es lo que alguien va a querer saber.
     *
     * SI ADEMAS HAY FACTURAS EXCLUIDAS A MANO del mismo proveedor, las cuenta:
     * no suman aparte -gana el proveedor- pero vuelven a aplicar si se lo
     * reincluye.
     *
     * Estatica y pura.
     *
     * @param array $items Filas de Proveedores::getPendientes()
     * @return array Lista de mensajes
     */
    public static function avisosExcluidosProveedor($items) {
        $total = 0.0;
        $enFila = 0.0;
        $cuantos = 0;
        $conFactura = 0;
        $proveedores = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (empty($item['EXCLUIDO_PROVEEDOR'])) {
                continue;
            }

            $importe = floatval($item['IMPORTE_PENDIENTE']);
            $total += $importe;
            $cuantos++;

            if (!empty($item['CRONOGRAMA'])) {
                $enFila += $importe;
            }

            if (!empty($item['EXCLUIDA_MANUAL'])) {
                $conFactura++;
            }

            $proveedores[$item['COD_PROVEE']] = true;
        }

        if ($cuantos === 0) {
            return [];
        }

        $codigos = array_keys($proveedores);
        $nombres = implode(', ', array_slice($codigos, 0, 5))
            . (count($codigos) > 5 ? ' y ' . (count($codigos) - 5) . ' más' : '');

        return ['Cuentas a Pagar Locales: ' . count($codigos) . ' proveedor(es) están excluidos '
            . 'de Proveedores Locales porque su deuda ya se considera en otra pestaña (' . $nombres
            . '): ' . $cuantos . ' vencimiento(s) por $ ' . number_format($total, 2, ',', '.')
            . ', de los que $ ' . number_format($enFila, 2, ',', '.') . ' estaban en la fila '
            . 'del tablero. El importe no se perdió: sale por su propia serie.'
            . ($conFactura > 0
                ? ' ' . $conFactura . ' de esos vencimientos además tienen la factura excluida '
                    . 'a mano; se cuentan una sola vez.'
                : '')
            . ' Se revisan en la solapa Maestro, con el motivo.'];
    }

    /**
     * Reparte los vencimientos en las series.
     *
     * @param Horizonte $h
     * @param array $items
     * @return array Mapa codigoSerie => serie
     */
    private function repartir($h, $items) {
        $series = [
            self::SERIE_TOTAL => $this->serieVacia($h),
            self::SERIE_TODO => $this->serieVacia($h),
            self::SERIE_FUERA => $this->serieVacia($h),
            self::SERIE_OPERATIVOS => $this->serieVacia($h),
            self::SERIE_EXCLUIDOS => $this->serieVacia($h),
            self::SERIE_CRONO_OPERATIVOS => $this->serieVacia($h),
            self::SERIE_SIN_RUBRO => $this->serieVacia($h),
            self::SERIE_EXCLUIDOS_FACTURA => $this->serieVacia($h),
            self::SERIE_EXCLUIDOS_PROVEEDOR => $this->serieVacia($h)
        ];

        /* SE CREA UNA SERIE POR CADA RUBRO DEL MAESTRO, aunque hoy no tenga
           deuda. Si no, un rubro sin pendientes no tendria serie, y una fila del
           tablero configurada contra el se dibujaria como "sin datos" -con el
           icono de que su modulo no devolvio nada- en lugar de mostrar un cero
           limpio, que es lo cierto: no hay deuda de ese rubro.

           Ademas hace que el conjunto de series que el registro declara y el que
           el proveedor devuelve sean exactamente el mismo, que es el invariante
           que fija tests/test_proveedores.php. */
        foreach (array_keys(self::seriesDeRubro()) as $rubro) {
            if (!isset($series[$rubro])) {
                $series[$rubro] = $this->serieVacia($h);
            }
        }

        foreach ($items as $item) {
            $importe = floatval($item['IMPORTE_PENDIENTE']);

            if ($importe == 0) {
                continue;
            }

            $destinos = self::seriesDeItem($item);

            foreach ($destinos as $destino) {
                // La serie del rubro se crea al vuelo: cuales existen depende
                // de lo que haya en el maestro, que es un dato.
                if (!isset($series[$destino])) {
                    $series[$destino] = $this->serieVacia($h);
                }
            }

            foreach ($destinos as $destino) {
                // Lo que cae fuera del eje se informa, no se descarta en
                // silencio. Solo se cuenta una vez, en PAGOS_TODO: las demas
                // informan lo mismo y el aviso saldria repetido.
                if (!$h->acumular($series[$destino], $item['Pago'], $importe)) {
                    if ($destino === self::SERIE_TODO) {
                        if ($item['Pago'] === null) {
                            $series[$destino]['sin_fecha'] += $importe;
                        } else {
                            $series[$destino]['fuera_horizonte'] += $importe;
                        }
                    }
                }
            }
        }

        return $series;
    }

    /**
     * A que series va un vencimiento. ES LA REGLA DE REPARTO, escrita una sola
     * vez y sin tocar la base, que es lo que permite verificar los cuatro
     * cuadrantes sin datos reales.
     *
     * DOS PARTICIONES INDEPENDIENTES, y una tercera serie que las cruza:
     *
     *   por COMO se paga   PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA
     *                            + PAGOS_EXCLUIDOS_PROVEEDOR
     *   por QUE rubro es   PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS
     *   las dos juntas     PAGOS_CRONO_OPERATIVOS
     *
     * Las dos particiones cierran cada una contra PAGOS_TODO. La tercera NO es
     * una particion: es la interseccion de una mitad de cada una, asi que se
     * solapa con las dos y el validador tiene que saberlo. Ver
     * CashflowRegistry, 'componentes' y 'solapan'.
     *
     * PAGOS_SIN_RUBRO tampoco parte nada: cruza las cuatro.
     *
     * Estatica y pura.
     *
     * @param array $item Una fila de Proveedores::getPendientes()
     * @return array Codigos de serie
     */
    public static function seriesDeItem($item) {
        $cronograma = !empty($item['CRONOGRAMA']);
        $excluido = !empty($item['EXCLUIDO']);
        $excluidaManual = !empty($item['EXCLUIDA_MANUAL']);
        $excluidoProveedor = !empty($item['EXCLUIDO_PROVEEDOR']);

        // PAGOS_TODO es el universo; PAGOS trae solo el cronograma. Las
        // aperturas por rubro y por excluidos parten PAGOS_TODO, no PAGOS:
        // describen QUE es cada deuda, no como se paga.
        $destinos = [self::SERIE_TODO];

        /* EL PRIMER CORTE TIENE TRES PARTES. Una factura excluida a mano no va
           ni a PAGOS ni a PAGOS_FUERA_CRONOGRAMA: va a la suya. Es lo que hace
           que el tilde saque el importe de la fila del tablero, que usa PAGOS.

           No se pregunta por $excluido sino por $excluidaManual: un proveedor
           con rubro "Excluidos" sigue repartiendose por como se le paga, como
           siempre. Sacarlo de PAGOS es otra decision y se toma desde Parametros
           apuntando la fila a PAGOS_CRONO_OPERATIVOS. */
        /* Y UNA CUARTA: el proveedor entero excluido de este modulo. VA ANTES
           que la factura: si las dos cosas pasan, gana el proveedor y el
           importe se cuenta una sola vez. Ver SERIE_EXCLUIDOS_PROVEEDOR. */
        if ($excluidoProveedor) {
            $destinos[] = self::SERIE_EXCLUIDOS_PROVEEDOR;
        } elseif ($excluidaManual) {
            $destinos[] = self::SERIE_EXCLUIDOS_FACTURA;
        } else {
            $destinos[] = $cronograma ? self::SERIE_TOTAL : self::SERIE_FUERA;
        }

        $destinos[] = $excluido ? self::SERIE_EXCLUIDOS : self::SERIE_OPERATIVOS;

        if ($cronograma && !$excluido) {
            $destinos[] = self::SERIE_CRONO_OPERATIVOS;
        }

        if (empty($item['EN_MAESTRO'])) {
            $destinos[] = self::SERIE_SIN_RUBRO;
        }

        $rubro = isset($item['SERIE']) ? $item['SERIE'] : ProveedoresCategorias::SERIE_SIN_RUBRO;

        if ($rubro !== ProveedoresCategorias::SERIE_SIN_RUBRO) {
            $destinos[] = $rubro;
        }

        return $destinos;
    }

    /** Una serie vacia con los escalares en su valor por defecto */
    private function serieVacia($h) {
        $serie = $h->serieVacia();
        $serie['moneda_origen'] = 'ARS';
        $serie['tipo_cambio'] = null;
        $serie['fuera_horizonte'] = 0;
        $serie['sin_fecha'] = 0;

        return $serie;
    }

    /**
     * Avisa cuando hay deuda de proveedores que no estan en el maestro.
     *
     * Se nombran los tres mas grandes y se dice cuantos son en total: la lista
     * completa puede tener cien y en un aviso no se lee. El detalle esta en la
     * pestana.
     *
     * @param Proveedores $prov
     * @param array $items
     */
    private function avisarFaltantes($prov, $items) {
        $faltan = $prov->categorias()->faltantesEnMaestro($items);

        if (empty($faltan)) {
            return;
        }

        $total = 0;

        foreach ($faltan as $f) {
            $total += $f['IMPORTE'];
        }

        $nombres = [];

        foreach (array_slice($faltan, 0, 3) as $f) {
            $nombres[] = $f['RAZON_SOC'];
        }

        $this->avisar('Cuentas a Pagar Locales: ' . count($faltan) . ' proveedor(es) con deuda '
            . 'por $ ' . number_format($total, 2, ',', '.') . ' no están en el maestro, así que '
            . 'su importe no se puede abrir por rubro (' . implode(', ', $nombres)
            . (count($faltan) > 3 ? ' y otros' : '') . '). Importá la hoja "Maestro proveedores" '
            . 'actualizada.');
    }

    /**
     * Los codigos de serie por rubro que existen HOY en el maestro.
     *
     * Lo usa el registro para poder declararlas: son datos, no codigo, asi que
     * no se pueden escribir en una constante. Si el maestro no esta cargado
     * devuelve vacio y el tablero funciona igual con las series fijas.
     *
     * NO LANZA: si la tabla no existe o la base no responde, el editor de
     * estructura tiene que seguir abriendo.
     *
     * @return array Mapa codigoSerie => descripcion
     */
    public static function seriesDeRubro() {
        try {
            $categorias = new ProveedoresCategorias();
            $series = [];

            foreach ($categorias->mapa() as $m) {
                $rubro = trim((string) $m['RUBRO_ECONOMICO']);

                if ($rubro === '') {
                    continue;
                }

                $codigo = ProveedoresCategorias::serieDeRubro($rubro);

                if ($codigo === ProveedoresCategorias::SERIE_SIN_RUBRO) {
                    continue;
                }

                $series[$codigo] = 'Pagos - ' . $rubro;
            }

            ksort($series);

            return $series;
        } catch (Throwable $e) {
            return [];
        }
    }
}
