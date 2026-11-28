<?php

require_once __DIR__ . '/Planilla.php';
require_once __DIR__ . '/LlavesCalculo.php';

/**
 * LlavesSugeridos
 * Lo que Parametros -> Llaves y Renovaciones SUGIERE por operador, medido con
 * la historia: el % de llave, el % de comisiones y el % de FPC de sus
 * ultimas renovaciones en la app, y el % de impuestos con la relacion factura /
 * contrato de Tango. Una sugerencia no proyecta nada: el valor lo confirma una
 * persona, y hasta entonces manda el general (o falta, y se avisa).
 *
 * ESTATICA Y PURA. Los contratos, el alquiler de la app y las facturas entran
 * ya leidos.
 *
 * QUE RENOVACION CUENTA
 * ---------------------
 * Un contrato con llave real (> 1: el 1 es la marca de Costo de Ocupacion) que
 * empieza entre el dia siguiente y 120 dias despues del fin del anterior. Mas
 * hueco que eso no es una renovacion, es un contrato nuevo despues de una
 * negociacion larga, y su llave no se compara con el alquiler de ese mes.
 *
 *   % de llave        = llave / (1 + 2 + 8 del primer mes x meses), ponderado
 *                       por la base: los contratos grandes pesan mas.
 *   % de comisiones   = comisiones / llave, ponderado por la llave.
 *   % de FPC          = FPC / llave, ponderado por la llave.
 *
 * Es la medicion del plan (23/11/2026): % de llave IRSA 27,9 %, DOT 26,3 %,
 * Cencosud 16,7 %, San Justo 24,5 %. Comisiones + FPC sumaban IRSA 43,8 %,
 * DOT 40,1 %, Cencosud 39,5 %, San Justo 8,4 %; desde el script 73 se
 * sugieren por separado.
 *
 * LAS FACTURAS DICEN CUANTO SE FACTURA, NO COMO SE PAGA: IRSA factura la
 * "cuota inicial" una vez y la paga en cuotas con echeqs. Por eso de Tango
 * solo sale la relacion factura / contrato para el % de impuestos; las
 * facturas se muestran como referencia en el tooltip y nunca se sugieren
 * cuotas con ellas.
 */
class LlavesSugeridos {

    /** Mas dias que esto entre un contrato y el siguiente no es una renovacion */
    const HUECO_MAX = 120;

    /** Las leyendas de Tango de una llave, para la relacion factura / contrato */
    const LEYENDAS_LLAVE = ['CUOTA INICIAL', 'LLAVE', 'CONTRAPRESTACION'];

    /**
     * Las renovaciones de la app que sirven para medir, por operador.
     *
     * @param array $operadorDe NRO => COD_PROVEE (la razon principal)
     * @param array $contratos NRO => contratos de la app
     * @param array $alquilerApp NRO => 'Y-m' => 1 + 2 + 8 de la app (cerrado o fijo abierto)
     * @param array $nombres NRO => nombre del local
     * @return array COD => ['pct_llave' => float|null, 'pct_comisiones' => float|null, 'pct_fpc' => float|null,
     *                       'renovaciones' => [['local', 'id', 'desde', 'meses', 'llave', 'base', 'pct', 'comisiones',
     *                                         'pct_comisiones', 'fpc', 'pct_fpc']]]
     */
    public static function porOperador($operadorDe, $contratos, $alquilerApp, $nombres = []) {
        $out = [];

        foreach ($contratos as $nro => $lista) {
            $cod = Planilla::codigo($operadorDe[$nro] ?? '');

            if ($cod === '') {
                continue;
            }

            $ks = LlavesCalculo::limpiar($lista);

            foreach ($ks as $c) {
                $ant = LlavesCalculo::anteriorDe($ks, $c);

                if ($ant === null || $c['llave'] === null || $c['llave'] <= 1.0) {
                    continue;
                }

                $hueco = (strtotime($c['desde']) - strtotime($ant['hasta'])) / 86400;

                if ($hueco < 1 || $hueco > self::HUECO_MAX) {
                    continue;
                }

                $meses = LlavesCalculo::meses($c['desde'], $c['hasta']);
                $alq = $alquilerApp[$nro][substr($c['desde'], 0, 7)] ?? null;
                $base = ($alq !== null && $alq > 0) ? round($alq * $meses, 2) : null;
                $comis = (float) $c['comisiones'];
                $fpc = (float) $c['fpc'];

                $out[$cod]['renovaciones'][] = [
                    'local' => $nombres[$nro] ?? ('Local ' . $nro), 'nro' => $nro, 'id' => $c['id'], 'desde' => $c['desde'],
                    'meses' => $meses, 'llave' => $c['llave'], 'alquiler' => $alq, 'base' => $base,
                    'pct' => ($base === null) ? null : round($c['llave'] / $base * 100, 2),
                    'comisiones' => $comis, 'pct_comisiones' => round($comis / $c['llave'] * 100, 2),
                    'fpc' => $fpc, 'pct_fpc' => round($fpc / $c['llave'] * 100, 2)
                ];
            }
        }

        foreach ($out as $cod => $o) {
            $conBase = array_filter($o['renovaciones'], function ($r) { return $r['base'] !== null; });
            $sumBase = array_sum(array_column($conBase, 'base'));
            $sumLlave = array_sum(array_column($o['renovaciones'], 'llave'));

            $out[$cod]['pct_llave'] = ($sumBase > 0) ? round(array_sum(array_column($conBase, 'llave')) / $sumBase * 100, 2) : null;
            /* Cada uno sobre la llave, por separado: comisiones / llave y FPC / llave. */
            foreach (['comisiones', 'fpc'] as $k) {
                $out[$cod]['pct_' . $k] = ($sumLlave > 0)
                    ? round(array_sum(array_column($o['renovaciones'], $k)) / $sumLlave * 100, 2) : null;
            }
        }

        return $out;
    }

    /**
     * El % de impuestos sugerido por operador: lo que facturo de mas sobre la
     * llave del contrato.
     *
     * Cada factura con leyenda de llave y "L<nro>" se cruza con el contrato con
     * llave real de ese local que empieza mas cerca de su emision (entre 12
     * meses despues y 6 antes). Las facturas del mismo contrato se suman
     * (Caballito factura la llave en 4 "CTA"). Si la suma coincide (1 %) con
     * llave + comisiones, se facturaron juntas sin impuestos: Cencosud, LLAVE
     * L2 = 102 M + 38,4 M. El sugerido es la mediana de los recargos.
     *
     * @param array $facturas [['COD_PROVEE', 'T_COMP', 'N_COMP', 'FECHA_EMIS', 'IMPORTE', 'LEYENDA']]
     * @param array $contratos NRO => contratos
     * @param array $nombres NRO => nombre
     * @return array COD => ['pct' => float|null, 'cruces' => [['local', 'id', 'llave', 'facturado', 'pct', 'facturas']]]
     */
    public static function impuestos($facturas, $contratos, $nombres = []) {
        $grupos = [];

        foreach (is_array($facturas) ? $facturas : [] as $f) {
            $ley = strtoupper((string) $f['LEYENDA']);
            $esLlave = false;

            foreach (self::LEYENDAS_LLAVE as $l) {
                $esLlave = $esLlave || strpos($ley, $l) !== false;
            }

            if (!$esLlave || !preg_match('/\bL\s*(\d{1,3})\b/', $ley, $m)) {
                continue;
            }

            $nro = (int) $m[1];
            $emis = substr((string) $f['FECHA_EMIS'], 0, 10);
            $c = self::contratoDeFactura($contratos[$nro] ?? [], $emis);

            if ($c === null) {
                continue;
            }

            $k = Planilla::codigo($f['COD_PROVEE']) . '|' . $nro . '|' . $c['id'];
            $grupos[$k]['cod'] = Planilla::codigo($f['COD_PROVEE']);
            $grupos[$k]['nro'] = $nro;
            $grupos[$k]['contrato'] = $c;
            $grupos[$k]['importe'] = ($grupos[$k]['importe'] ?? 0.0) + (float) $f['IMPORTE'];
            $grupos[$k]['facturas'][] = trim($f['T_COMP'] . ' ' . $f['N_COMP']) . ' ' . LlavesCalculo::corta($emis) . ' '
                . LlavesCalculo::plata($f['IMPORTE']) . ' «' . trim((string) $f['LEYENDA']) . '»';
        }

        $out = [];

        foreach ($grupos as $g) {
            $c = $g['contrato'];
            $conComis = $c['llave'] + (float) $c['comisiones'];
            $juntas = ($conComis > 0 && abs($g['importe'] / $conComis - 1) < 0.01 && (float) $c['comisiones'] > 0);
            $pct = $juntas ? 0.0 : round(($g['importe'] / $c['llave'] - 1) * 100, 2);

            $out[$g['cod']]['cruces'][] = [
                'local' => $nombres[$g['nro']] ?? ('Local ' . $g['nro']), 'id' => $c['id'], 'llave' => $c['llave'],
                'facturado' => round($g['importe'], 2), 'pct' => $pct, 'juntas' => $juntas, 'facturas' => $g['facturas']
            ];
        }

        foreach ($out as $cod => $o) {
            $out[$cod]['pct'] = self::mediana(array_column($o['cruces'], 'pct'));
        }

        return $out;
    }

    /** El contrato con llave real de un local que empieza mas cerca de una fecha de emision. */
    private static function contratoDeFactura($lista, $emis) {
        $mejor = null;
        $dist = null;

        foreach ($lista as $c) {
            if ($c['llave'] === null || $c['llave'] <= 1.0) {
                continue;
            }

            $d = (strtotime($c['desde']) - strtotime($emis)) / 86400;

            if ($d > 366 || $d < -183) {
                continue;
            }

            if ($dist === null || abs($d) < $dist) {
                $mejor = $c;
                $dist = abs($d);
            }
        }

        return $mejor;
    }

    private static function mediana($xs) {
        $v = array_values(array_filter($xs, function ($x) { return $x !== null; }));

        if (empty($v)) {
            return null;
        }

        sort($v);
        $n = count($v);

        return ($n % 2) ? $v[intdiv($n, 2)] : round(($v[$n / 2 - 1] + $v[$n / 2]) / 2, 2);
    }
}
