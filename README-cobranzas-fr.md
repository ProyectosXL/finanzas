# Módulo Cobranzas FR — Real a Cobrar vs Pendientes Proyectados (PPP)

Reemplaza la matriz unificada de **Cobranzas FR** dividiendo la gestión en dos matrices por solapas independientes (*Real a Cobrar* y *Pendientes Proyectados*) y alimenta las series del tablero de Cashflow: `COBRANZA`, `COBRANZA_PROYECTADA` y `COBRANZA_TOTAL`.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes se proyectan sumando a su fecha de emisión el Plazo Promedio de Pago (PPP) de cada cliente, con la opción de sobrescribir el PPP manualmente y aplicar escalas de descuento comerciales.**

```
Facturas Pendientes (GVA12 FAC en estado PEN)
        │
        ├─ Excluye comprobantes ya contados por Real (ACEPTADA) o ya cobrados (PAGADO)
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
-- 1. sql/cashflow_cobranzas_parametros.sql
-- 2. sql/cashflow_estructura_split_cobranzas_fr.sql
```

1. Crea la tabla `RO_T_CASHFLOW_COBRANZAS_PARAM_DESC` para administrar las escalas de descuento por cliente, tramo de días (`DIAS_DESDE`, `DIAS_HASTA`) y medio de pago (`ECHEQ`).
2. Agrega la columna `PPP_MANUAL INT NULL` a la tabla `RO_T_PARAMETROS_DESC_CLIENTES`.
3. Es reejecutable y cuenta con índices por `COD_CLIENT`, `MEDIO_PAGO` y `ACTIVO`.

El segundo parte la fila `COBRANZAS_FR` del tablero en sus dos componentes. Es reejecutable y no borra nada.

---

## Las Dos Matrices (Solapas Principales)

La interfaz de `Cobranzas FR` cuenta con dos pestañas de navegación dedicadas en la cabecera superior:

### 1. Solapa "Real a Cobrar"
- **Origen de datos:** Propuestas de pago de franquicias (`FP_propuestas_pago` en la base `apps`).
- **Criterio:** Facturas comprometidas por fecha efectiva de cobro pactada (`fecha_propuesta_pago`), **únicamente de propuestas en estado `ACEPTADA`**.
- **Estilo:** Visualización estándar en verde/azul (`.badge-real`, `.badge-cobro`).
- **Serie del Tablero:** `COBRANZA_REAL`.

### 2. Solapa "Pendientes Proyectados"
- **Origen de datos:** Comprobantes tipo `FAC` en estado `PEN` desde `GVA12` en `central` (Tango Gestión).
- **Filtro de exclusión:** Descarta los comprobantes que ya cuenta *Real* (propuestas `ACEPTADA`) y los ya cobrados (`PAGADO`). Ver la invariante más abajo.
- **Cálculo de Fecha Probable de Cobro:**
  $$\text{Fecha Probable de Cobro} = \text{Fecha Emisión} + \text{PPP}$$
- **Estilo:** Visualización destacada en tono ámbar/amarillo (`.fila-proyeccion`, `.badge-proyeccion`, `.cell-with-proy`).
- **Serie del Tablero:** `COBRANZA_PROYECTADA`.

---

## La invariante entre las dos solapas

Los estados que existen de verdad en `FP_propuestas_pago` son exactamente tres: `ACEPTADA`, `PAGADO` y `PENDIENTE_APROBACION_CLIENTE`.

| Estado | Real a Cobrar | Pendientes Proyectados |
| --- | --- | --- |
| `ACEPTADA` | ✅ la cuenta | ❌ excluida |
| `PAGADO` | ❌ | ❌ — ya se cobró, no es plata a cobrar |
| `PENDIENTE_APROBACION_CLIENTE` | ❌ no está comprometida | ✅ vuelve a la proyección |

**Las dos consultas se tienen que mover juntas.** Antes, *Real* traía todo lo que no estuviera rechazado, cancelado, pagado ni vencido — o sea también lo que esperaba la aprobación del cliente— y la proyección excluía exactamente ese mismo conjunto. Acotar *Real* a `ACEPTADA` sin tocar la exclusión hubiera dejado las propuestas pendientes de aprobación **fuera de las dos solapas**: la plata se evapora en silencio y no hay ninguna pantalla donde se note.

Por eso la exclusión de la proyección es el **complemento exacto** de lo que cuenta *Real*, más lo ya cobrado. Vive escrita en dos constantes de `Class/Ingresos.php` (`ESTADOS_REAL` y `ESTADOS_YA_CONTADOS`), comentada en las dos funciones y probada en `tests/test_cobranzas_fr_split.php`, porque son dos consultas contra dos bases distintas y nada más las mantiene alineadas.

`getPPPClientes()` sigue usando `PAGADO` y no participa de esto: es el histórico con el que se calcula el plazo, no el universo a cobrar.

---

## La fila del tablero está partida en dos

`IngresosProvider` siempre expuso `COBRANZA_REAL` y `COBRANZA_PROYECTADA` como series separadas, pero el tablero tenía **una** fila apuntada a `COBRANZA`, que es la suma de las dos. En pantalla eso es un solo número que mezcla lo comprometido con lo estimado.

Ahora son dos filas, y el cambio es **de datos, no de código** — que es para lo que existe `RO_T_CASHFLOW_CONF_FILA`:

| CODIGO | NOMBRE | SERIE |
| --- | --- | --- |
| `COBRANZAS_FR_REAL` | Cobranzas Franquicias (Prop. aceptadas) | `COBRANZA_REAL` |
| `COBRANZAS_FR_PROY` | Cobranzas Franquicias Proyectadas | `COBRANZA_PROYECTADA` |

`COBRANZAS_FR` queda con `ACTIVO = 0`: **no se borra**, hay histórico de configuración y el editor la sigue mostrando. Y no puede volver a activarse junto a sus dos hijas — el registro declara `COBRANZA = [COBRANZA_REAL, COBRANZA_PROYECTADA]` en su bloque `componentes` y el validador rechaza tener el total y sus partes a la vez, porque contaría dos veces el mismo importe.

El script es `sql/cashflow_estructura_split_cobranzas_fr.sql`, y **toma la sección de la fila que reemplaza en vez de escribirla a mano**: en una base que corrió `cashflow_estructura_disponibilidades.sql`, `COBRANZAS_FR` vive en `DISPONIBILIDADES` y la sección `INGRESOS` quedó inhabilitada, así que escribir `INGRESOS` en duro dejaría dos filas activas colgando de una sección inhabilitada.

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
| `COBRANZAS_FR` | `COBRANZA_REAL` | Cobranza real de franquicias | `COBRANZAS_FR_REAL` — activa |
| `COBRANZAS_FR` | `COBRANZA_PROYECTADA` | Cobranza proyectada pendientes (PPP) | `COBRANZAS_FR_PROY` — activa |
| `COBRANZAS_FR` | `COBRANZA` / `COBRANZA_TOTAL` | Total franquicias (Real + Proyectada) | `COBRANZAS_FR` — **inactiva**, ver arriba |

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
