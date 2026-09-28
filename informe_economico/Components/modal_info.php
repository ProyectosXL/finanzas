<?php
/**
 * modal_info.php
 * "Acerca del informe": las reglas que explican cada numero. Es la version
 * corta del README; si una regla cambia, cambia en los dos.
 */
?>
<div id="ieModalInfo" class="ie-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ieInfoTitulo">
    <div class="ie-modal">
        <div class="ie-modal-header">
            <i class="bi bi-info-circle-fill" style="font-size:22px;color:var(--ie-accent)"></i>
            <div>
                <h2 id="ieInfoTitulo">Acerca del informe</h2>
                <p>Criterios y reglas del Informe Económico por Canal</p>
            </div>
            <button class="ie-cerrar" type="button" data-cerrar="ieModalInfo" title="Cerrar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="ie-modal-body ie-acordeon">

            <details open>
                <summary><i class="bi bi-question-circle"></i> ¿De dónde salen los datos?</summary>
                <div class="ie-acordeon-body">
                    <p>De <code>RO_T_RESUMEN_FINAL_IE</code>, la tabla que genera el proceso de <strong>Control de Gastos</strong>. Los importes se toman tal como están: ya pasaron por el paso 8 (coeficiente de ajuste).</p>
                    <p>Un mes se muestra solo si <strong>tiene resumen</strong>: algún rubro además de 1.1, 1.2, 1.6 y 1.8 (que carga el paso 2). Los meses sin resumen se listan en un aviso; este módulo no procesa nada.</p>
                    <p>Los registros repetidos de un mismo período, sucursal y rubro se <strong>suman</strong>.</p>
                </div>
            </details>

            <details>
                <summary><i class="bi bi-diagram-3"></i> Canales</summary>
                <div class="ie-acordeon-body">
                    <ul>
                        <li>100 Franquicias · 101 Mayoristas · 103 Otros ingresos.</li>
                        <li>Ecommerce: 102 (la histórica, que englobaba todo) + 301 VTEX + 302 Mercado Libre + 303 ICBC. Lo comparable contra años anteriores es el subtotal ECOMMERCE.</li>
                        <li>Todo lo demás es Locales propios. Un local cerrado (<code>HABILITADO = 0</code> en SUCURSALES_LAKERS) no suma salvo que se active "Incluir sucursales cerradas".</li>
                        <li><strong>Sin sucursal</strong> (NRO_SUCURSAL vacío o 0): registros viejos que no pertenecen a ninguna sucursal. Se muestran aparte y <strong>no suman al Total general</strong>, igual que el Excel.</li>
                    </ul>
                </div>
            </details>

            <details>
                <summary><i class="bi bi-list-ol"></i> La cascada</summary>
                <div class="ie-acordeon-body">
                    <ul>
                        <li>1.3 = 1.1 + 1.2 · 1.4 IVA = −(1.3 − 1.5), en negativo. Solo existen en locales; en LOCALES y en el Total son la suma de los locales.</li>
                        <li>1.9 TOTAL VENTAS SIN IVA = 1.5 + 1.6 + 1.7 + 1.8. El 1.1 y el 1.2 son informativos.</li>
                        <li>Mark up sin IVA: sobre la venta de mercadería (1.5 locales y ecommerce, 1.6 mayoristas, 1.7 franquicias). El 1.8 no entra: es recupero, no venta.</li>
                        <li>Mark up con IVA: solo en locales. En los demás canales y en el Total dice "no aplica": no se estima con 1,21 porque hay artículos al 10,5%.</li>
                        <li>RENTABILIDAD TOTAL = resultado de explotación / 1.9, salvo en la columna LOCALES, donde va sobre 1.5, como en el Excel.</li>
                        <li>Un dato que falta se muestra "—"; una división por cero, también. Nunca un 0 inventado.</li>
                    </ul>
                </div>
            </details>

            <details>
                <summary><i class="bi bi-currency-exchange"></i> Moneda y comparativo</summary>
                <div class="ie-acordeon-body">
                    <p>En U$S cada importe se divide por el TCC promedio del rango (<code>RO_V_DOLAR_OFICIAL_BCRA</code>); los % no cambian. Si falta la cotización de algún mes, el informe se muestra en pesos con un aviso.</p>
                    <p>"Comparar con año anterior" usa el mismo rango doce meses antes. La variación de un importe es % sobre el valor absoluto del año anterior; la de un % es la diferencia en puntos.</p>
                </div>
            </details>

            <details>
                <summary><i class="bi bi-pencil-square"></i> Edición de importes</summary>
                <div class="ie-acordeon-body">
                    <p>Con el permiso de edición, un clic en una celda de rubro abre sus registros. Se corrige el <strong>IMPORTE</strong> de cada registro, con motivo obligatorio, y queda en el historial (quién, cuándo, por qué). Se puede deshacer paso a paso o restaurar el valor original.</p>
                    <p>La corrección se escribe en <code>RO_T_RESUMEN_FINAL_IE</code>: la ven también Rentabilidad por Rubro y cualquier otro informe que lea esa tabla.</p>
                    <p><strong>Un reproceso desde Control de Gastos pisa todo lo editado</strong> (borra el período y lo vuelve a generar). Es lo esperado: la marca de "editado" desaparece sola y el historial queda como referencia.</p>
                </div>
            </details>

            <details>
                <summary><i class="bi bi-stoplights"></i> Semáforo del ranking</summary>
                <div class="ie-acordeon-body">
                    <p>Los umbrales se editan en Parámetros. Un valor justo en un límite toma la banda <strong>peor</strong> de las dos (15% de rentabilidad es amarillo; 15% de comercialización es amarillo). Un valor fuera de toda banda definida queda sin color.</p>
                </div>
            </details>

        </div>
        <div class="ie-modal-footer">
            <button class="ie-btn ie-btn-primario" type="button" data-cerrar="ieModalInfo"><i class="bi bi-check-circle-fill"></i> Entendido</button>
        </div>
    </div>
</div>
