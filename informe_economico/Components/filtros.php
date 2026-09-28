<?php
/**
 * filtros.php
 * Los filtros comunes a las pestanas de informe. Parametros no los usa y la
 * barra se oculta ahi (Menu: 'filtros' => false).
 *
 * Lo propio de cada pestana (Mostrar %, el canal de Evolucion Mensual) va en
 * la pestana, no aca.
 */
?>
<section class="ie-filtros" id="ieFiltros">
    <div class="ie-filtros-inner">

        <div class="ie-filtro">
            <label class="ie-filtro-label" for="ieDesde"><i class="bi bi-calendar3"></i> Período Desde</label>
            <input type="text" id="ieDesde" class="ie-input" placeholder="Ej: 1-2026" maxlength="7" autocomplete="off">
        </div>

        <div class="ie-filtro">
            <label class="ie-filtro-label" for="ieHasta"><i class="bi bi-calendar3-range"></i> Período Hasta</label>
            <input type="text" id="ieHasta" class="ie-input" placeholder="Ej: 6-2026" maxlength="7" autocomplete="off">
        </div>

        <div class="ie-filtro">
            <span class="ie-filtro-label"><i class="bi bi-currency-exchange"></i> Moneda</span>
            <div class="ie-toggle-group">
                <input type="radio" name="ieMoneda" id="ieMonedaARS" value="ARS" checked>
                <label for="ieMonedaARS">$ ARS</label>
                <input type="radio" name="ieMoneda" id="ieMonedaUSD" value="USD">
                <label for="ieMonedaUSD">U$S</label>
            </div>
        </div>

        <div class="ie-filtro">
            <span class="ie-filtro-label"><i class="bi bi-arrow-left-right"></i> Análisis horizontal</span>
            <label class="ie-switch"><input type="checkbox" id="ieComparar"> Comparar con año anterior</label>
        </div>

        <div class="ie-filtro">
            <span class="ie-filtro-label"><i class="bi bi-shop-window"></i> Locales</span>
            <label class="ie-switch"><input type="checkbox" id="ieCerradas"> Incluir sucursales cerradas</label>
        </div>

        <div class="ie-acciones">
            <button id="ieBtnAplicar" class="ie-btn ie-btn-primario" type="button">
                <i class="bi bi-search"></i> Aplicar
            </button>
            <button id="ieBtnExportar" class="ie-btn ie-btn-sec" type="button" disabled
                    title="Exporta lo que se está viendo: layout, %, comparativo y toggles">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </button>
        </div>
    </div>

    <div id="ieAlertaFiltros" class="ie-alerta-filtros" style="display:none;"></div>
</section>
