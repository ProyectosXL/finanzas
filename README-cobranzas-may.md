# Módulo Cobranzas Mayoristas (`Cobranzas May`) — Facturas Pendientes (+60 Días)

Implementación del módulo de **Cobranzas Mayoristas** en Cashflow (`c:\xampp\htdocs\finanzas`), siguiendo el patrón de diseño y arquitectura de **Cobranzas FR** y **Cob. Electrónicos**.

Rama: `feature/cobranzas-fr-proyeccion`

---

## La idea en una línea

**Las facturas pendientes de clientes mayoristas se proyectan sumando a su fecha de emisión un plazo fijo de vencimiento (por defecto 60 días), configurable globalmente desde Parámetros.**

```
Facturas Pendientes Mayoristas (GVA12 en base central: ESTADO = 'PEN', COD_CLIENT NOT LIKE 'FR%')
        │
        ├─ Parámetro Global: cobranzas_may_dias_vto (editable en Parámetros -> Cobranzas)
        ▼
Fecha Probable de Cobro = Fecha Emisión + Días de Plazo (60d)
        │
        ▼
   Pestaña "Cobranzas May" (Matriz con Resumen / Deep Dive y Eje Temporal)
        │
        ▼
   Proveedor IngresosProvider (serie COBRANZA) → Cashflow Tablero
```

---

## Ejecución de los scripts

Contra `central`:

```sql
-- sql/cashflow_cobranzas_may.sql
```

1. Registra el parámetro global `cobranzas_may_dias_vto` con valor predeterminado `60` en `RO_T_CASHFLOW_PARAMETROS` dentro del módulo `COBRANZAS` y grupo `GENERAL`.
2. Es idempotente y reejecutable.

---

## Origen de Datos y Lógica de Negocio

El módulo implementa el flujo de **Camino 1: Facturas Pendientes** del sistema de mayoristas (`administracion/tesoreria/cobranzas/mayoristas.php`):

- **Origen de datos:** Comprobantes pendientes en `GVA12` con `ESTADO = 'PEN'`, clientes mayoristas que comienzan con código `MA` (`COD_CLIENT LIKE 'MA%'`).
- **Tipos de comprobante incluidos:** `FAC`, `NDC`, `NDU` (suman al saldo) y `NC`, `NCC`, `NCU` (restan al saldo).
- **Cálculo de Proyección:**
  $$\text{Fecha Probable de Cobro} = \text{Fecha Emisión} + \text{Días de Plazo}$$
- **Filtro Temporal:** Se excluyen comprobantes cuya $\text{Fecha Probable de Cobro} < \text{Fecha Actual (Día de corte)}$, ya que los cobros proyectados pasados no deben formar parte del flujo futuro de fondos.
- **Días de Plazo:** Se obtienen desde `RO_T_CASHFLOW_PARAMETROS` (`cobranzas_may_dias_vto`, por defecto 60 días).

---

## Parámetros de Configuración

En **Parámetros → Cobranzas**, se incorporó una tarjeta dedicada para **Cobranzas Mayoristas**:
- **Plazo de Proyección Mayoristas:** Campo numérico para editar el plazo en días.
- **Persistencia en tiempo real:** Al modificar el valor se envía automáticamente la petición `saveParametro` y queda registrado para toda la aplicación.

---

## Interfaz de Usuario y Controles

La pestaña **Cobranzas May** cuenta con todos los componentes estándar del sistema:

1. **Tarjetas de KPI en Cabecera:**
   - Total Pendiente Proyectado.
   - Cantidad de Comprobantes.
   - Clientes Mayoristas con deuda pendiente.
   - Plazo Promedio Aplicado (días).
2. **Barra de Herramientas:**
   - **Buscador rápido:** Filtrado en tiempo real por código de cliente, razón social o comprobante.
   - **Modo Resumen vs Deep Dive:**
     - *Resumen:* **una fila por cliente**, con los importes repartidos en las columnas de la grilla según la fecha de cobro de cada comprobante. Quedan `Tipo`, `COD_CLI`, `RAZON_SOC`, `Importe Bruto` e `Importe Neto`.
     - *Deep Dive:* apertura individual por comprobante con fecha de emisión, tipo, número, importe y fecha de cobro.

     Antes el Resumen agrupaba por cliente **y fecha**, así que un cliente con cobros en tres fechas ocupaba tres filas. El agrupado ahora lo hace `EjeVista::armarAgrupado()` sumando las series, no la consulta: así cada importe conserva la fecha que lo ubica en la grilla y la fila es una sola. `Ingresos::getCobranzasMay()` perdió su parámetro `$summary` — devuelve siempre una fila por comprobante. Ver `README-cobranzas-fr.md`.
   - **Selector de Eje Temporal:** Alterna entre vistas de *Días*, *Meses* y *Período Completo* (`Js/eje-vistas.js`).
   - **Acciones:** Botón de *Actualizar* y *Exportar a Excel*.

---

## Integración con el Tablero de Cashflow

El proveedor `IngresosProvider` registra la serie en `CashflowRegistry`:

| Código Proveedor | Serie | Descripción | Fila en Cashflow |
| --- | --- | --- | --- |
| `COBRANZAS_MAY` | `COBRANZA` | Cobranza proyectada de mayoristas (+60 días) | Cobranza Mayoristas |

---

## Pruebas Automatizadas

Suite de pruebas implementada en `tests/test_cobranzas_may.php`:
- Registro y disponibilidad de `COBRANZAS_MAY` en `CashflowRegistry`.
- Lectura y valor por defecto del parámetro `cobranzas_may_dias_vto`.
- Cálculo de la fecha probable de cobro ($\text{Fecha Emisión} + 60\text{ días}$).
- Estructura de salida de `getCobranzasMay()` y `getCobranzasMayTotales()`.
- Generación de series diarias y mensuales en `IngresosProvider` alineadas con el `Horizonte`.

Ejecución de la suite completa:
```bash
php tests/run.php
```
*Resultado: 844 OK, 0 fallas (11 archivos).*
