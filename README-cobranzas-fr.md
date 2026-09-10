# Módulo Cobranzas FR — Real a Cobrar vs Pendientes Proyectados (PPP)

Reemplaza la matriz unificada de **Cobranzas FR** dividiendo la gestión en dos matrices por solapas independientes (*Real a Cobrar* y *Pendientes Proyectados*) y alimenta las series del tablero de Cashflow: `COBRANZA`, `COBRANZA_PROYECTADA` y `COBRANZA_TOTAL`.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes se proyectan sumando a su fecha de emisión el Plazo Promedio de Pago (PPP) de cada cliente, con la opción de sobrescribir el PPP manualmente y aplicar escalas de descuento comerciales.**

```
Facturas Pendientes (GVA12 FAC en estado PEN)
        │
        ├─ Excluye comprobantes ya comprometidos en propuestas activas
        ├─ PPP del Cliente = Promedio de días de plazo de los últimos 3 cobros
        ├─ Si existe PPP_MANUAL en Parámetros, pisa el PPP calculado
        ▼
Fecha Probable de Cobro = Fecha Emisión + PPP
        │
        ├─ Escala de descuento por cliente / tramo de días / medio de pago (ECHEQ)
        ▼
Importe Neto Proyectado = Importe Bruto * (1 - % Descuento)
        │
        ▼
   Solapa "Pendientes Proyectados" (destacada en amarillo)
        │
        ▼
   serie COBRANZA_PROYECTADA → Cashflow
```

---

## Ejecución de los scripts

Contra `central`:

```sql
-- sql/cashflow_cobranzas_parametros.sql
```

1. Crea la tabla `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` para administrar las escalas de descuento por cliente, tramo de días (`DIAS_DESDE`, `DIAS_HASTA`) y medio de pago (`ECHEQ`).
2. Agrega la columna `PPP_MANUAL INT NULL` a la tabla `RO_T_PARAMETROS_DESC_CLIENTES`.
3. Es reejecutable y cuenta con índices por `COD_CLIENT`, `MEDIO_PAGO` y `ACTIVO`.

---

## Las Dos Matrices (Solapas Principales)

La interfaz de `Cobranzas FR` cuenta con dos pestañas de navegación dedicadas en la cabecera superior:

### 1. Solapa "Real a Cobrar"
- **Origen de datos:** Propuestas de pago de franquicias (`FP_propuestas_pago` en la base `apps`).
- **Criterio:** Facturas comprometidas y confirmadas por fecha efectiva de cobro pactada (`fecha_propuesta_pago`).
- **Estilo:** Visualización estándar en verde/azul (`.badge-real`, `.badge-cobro`).
- **Serie del Tablero:** `COBRANZA`.

### 2. Solapa "Pendientes Proyectados"
- **Origen de datos:** Comprobantes tipo `FAC` en estado `PEN` desde `GVA12` en `central` (Tango Gestión).
- **Filtro de exclusión:** Descarta comprobantes que ya se encuentren asociados a propuestas activas en `FP_propuestas_pago` para evitar cualquier doble cómputo.
- **Cálculo de Fecha Probable de Cobro:**
  $$\text{Fecha Probable de Cobro} = \text{Fecha Emisión} + \text{PPP}$$
- **Estilo:** Visualización destacada en tono ámbar/amarillo (`.fila-proyeccion`, `.badge-proyeccion`, `.cell-with-proy`).
- **Serie del Tablero:** `COBRANZA_PROYECTADA`.

---

## Cálculo y Override del Plazo Promedio de Pago (PPP)

El cálculo del PPP por cliente opera de la siguiente manera:

1. **PPP Calculado:**
   - Se buscan los últimos 3 cobros con estado `PAGADO` en `FP_propuestas_pago` para el cliente (`COD_CLIENT`).
   - Para cada cobro se obtiene el plazo real: `DATEDIFF(day, fecha_creacion, fecha_propuesta_pago)`.
   - Se promedian los plazos resultantes: `ROUND(AVG(dias_plazo))`.
   - Si el cliente no registra cobros históricos, se adopta el valor de respaldo por defecto (30 días).

2. **PPP Manual (Override en Parámetros):**
   - En la pestaña **Parámetros → Cobranzas**, cada cliente muestra su PPP calculado y un campo editable directo (`PPP_MANUAL`).
   - Al ingresar un valor manual, el sistema utiliza inmediatamente ese plazo para todas las proyecciones del cliente sin alterar el histórico calculado.

---

## Escalas de Descuento Comerciales

En **Parámetros → Cobranzas**, es posible configurar escalas de descuento por cliente según los días transcurridos hasta la fecha de cobro y el medio de pago:

- **Ejemplo:**
  - 0 a 20 días: **8%** de descuento.
  - 20 a 30 días: **6%** de descuento.
  - > 30 días: **0%** de descuento.
- **Cálculo:**
  $$\text{Días Transcurridos} = \text{DATEDIFF(day, Fecha Emisión, Fecha Probable Cobro)}$$
  $$\text{Importe Neto Proyectado} = \text{Importe Bruto} \times \left(1 - \frac{\text{\% Descuento}}{100}\right)$$

---

## Barra de Herramientas y Navegación

Dentro de cada solapa, la barra de herramientas superior proporciona:
- **Buscador rápido:** Filtrado en tiempo real por código de cliente, razón social o número de comprobante.
- **Modo Resumen vs Deep Dive:**
  - *Resumen:* Vista consolidada por cliente con 5 columnas base (`COD_CLI`, `RAZON_SOC`, `Importe Bruto`, `Importe Neto`, `Cobro`) más el eje temporal.
  - *Deep Dive:* Vista aperturada comprobante por comprobante mostrando fecha de emisión, tipo, número, descuento y días.
- **Selector de Eje Temporal:** Alterna entre *Días* (tramo diario), *Meses* (tramo mensual) y *Período Completo* mediante el componente común `Js/eje-vistas.js`.
- **Botones de Acción:** *Actualizar* datos y *Exportar* matriz a Excel.

---

## Integración con el Tablero de Cashflow

El proveedor `IngresosProvider` registra tres series en `CashflowRegistry`:

| Código Proveedor | Serie | Descripción | Fila en Cashflow |
| --- | --- | --- | --- |
| `COBRANZAS_FR` | `COBRANZA` | Cobranza real de franquicias | Cobranzas Franquicias (Real) |
| `COBRANZAS_FR` | `COBRANZA_PROYECTADA` | Cobranza proyectada pendientes (PPP) | Cobranzas Franquicias (Proyectada) |
| `COBRANZAS_FR` | `COBRANZA_TOTAL` | Total franquicias (Real + Proyectada) | Cobranzas Franquicias (Total) |

---

## Pruebas Automatizadas

Suite de pruebas implementada en `tests/test_cobranzas_proyeccion.php`:
- Cálculo exacto de PPP con 1, 2, 3 o más cobros históricos.
- Prioridad del override de `PPP_MANUAL` sobre el PPP calculado.
- Evaluación de escalas de descuento por rangos de días.
- Proyección de fechas probables de cobro y cálculo de importe neto.
- Integración completa con el `Horizonte` y el `CashflowRegistry`.

Ejecución de suite completa:
```bash
php tests/run.php
```
*Resultado: 817 OK, 0 fallas (10 archivos).*
