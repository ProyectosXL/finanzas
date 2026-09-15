# Módulo Cobranzas Mayoristas (`Cobranzas May`) — Facturas Pendientes (+60 Días)

Implementación del módulo de **Cobranzas Mayoristas** en Cashflow (`c:\xampp\htdocs\finanzas`), siguiendo el patrón de diseño y arquitectura de **Cobranzas FR** y **Cob. Electrónicos**.

Rama: `feature/cobranzas-fr-proyeccion`; la fecha de cobro manual llegó en `feature/cashflow-estructura-inversiones`.

---

## La idea en una línea

**Las facturas pendientes de clientes mayoristas se proyectan sumando a su fecha de emisión un plazo fijo de vencimiento (por defecto 60 días), configurable globalmente desde Parámetros — salvo que alguien haya cargado a mano la fecha de esa factura puntual, que es la que manda.**

```
Facturas Pendientes Mayoristas (GVA12 en base central: ESTADO = 'PEN', COD_CLIENT LIKE 'MA%')
        │
        ├─ Fecha cargada a mano para ese comprobante, si hay  ──┐  manda
        ├─ Parámetro Global: cobranzas_may_dias_vto             │  (Ingresos::resolverFechaCobro)
        ▼                                                      │
Fecha Probable de Cobro = Fecha Emisión + Días de Plazo (60d) ─┘
        │
        ▼
   Pestaña "Cobranzas May" (Matriz con Resumen / Detalle Facturas y Eje Temporal)
        │                  la fecha se edita en Detalle Facturas
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
- **Las vencidas entran, ubicadas en hoy.** Antes se excluían los comprobantes cuya fecha probable de cobro fuera anterior al día de corte, y esa plata desaparecía de la pantalla sin aviso. Ahora entran si la fecha cae dentro de los últimos `Ingresos::DIAS_COBRO_VENCIDO` (180) días, se ubican en el primer día del eje y se marcan. La regla es la misma para las tres pestañas de cobranza proyectada y vive en `Ingresos::ubicarCobroVencido()`; está explicada en `README-cobranzas-fr.md`. **Una fecha cargada a mano no se reubica**: la pactó una persona, y moverla con una regla automática le mostraría su propia carga en otra columna. Se marca vencida —eso es un hecho— pero se dibuja donde está.
- **Días de Plazo:** Se obtienen desde `RO_T_CASHFLOW_PARAMETROS` (`cobranzas_may_dias_vto`, por defecto 60 días).

---

## Fecha de cobro manual

> Esta sección reemplaza a lo que este README decía antes: *"Mayoristas no tiene fecha manual"*. Ahora sí la tiene.

El plazo de 60 días es un parámetro global: sirve para el grueso de la cartera y no sirve cuando alguien ya habló la fecha de una factura puntual. Esa fecha se carga **celda por celda en la grilla**, en *Detalle Facturas*, igual que en Cobranzas FR.

```
Fecha de cobro = fecha cargada a mano, si hay
                 fecha de emisión + cobranzas_may_dias_vto, si no
```

La jerarquía vive en **`Ingresos::resolverFechaCobro()`** y no se reimplementa acá: es el mismo método que usa Cobranzas FR.

### Es la misma tabla que Cobranzas FR, sin ninguna columna que los distinga

`RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL` sirve a los dos circuitos. El nombre dice `FR` por dónde nació, no por a quién sirve. No se creó una tabla paralela ni se le agregó una columna de origen, y las dos decisiones salen de la misma observación: **la clave ya es el comprobante**, y un comprobante pertenece a un solo circuito (franquicias es `COD_CLIENT LIKE 'FR%'`, mayoristas `LIKE 'MA%'`; no puede ser los dos).

- Una columna `ORIGEN` tendría que coincidir siempre con lo que dice el código de cliente: sería un dato que se puede contradecir con otro. Y si entrara en la unicidad, el mismo comprobante podría tener dos fechas manuales distintas.
- Una tabla paralela duplicaría el circuito entero —leer, guardar, borrar, la jerarquía— para guardar la misma fila con el mismo significado.

Por eso los métodos perdieron el sufijo: `Ingresos::getFechasManuales()`, `saveFechaManual()` y `deleteFechaManual()`. El razonamiento completo está en el encabezado de `sql/cashflow_cobranzas_fecha_manual.sql`. **No hay script nuevo que correr**: si ya corriste ese, mayoristas funciona.

### Acá la fecha manual NO cambia ningún importe

Es la única diferencia real con Cobranzas FR, y conviene tenerla presente antes de copiar cualquier otra cosa de aquella pestaña:

| | Cobranzas FR | Cobranzas May |
| --- | --- | --- |
| Días de la fila | se recalculan sobre la fecha resuelta | se recalculan sobre la fecha resuelta |
| Escala de descuento | **sí**: los días deciden el tramo | **no existe** |
| Importe neto | **cambia** con la fecha | siempre igual al bruto |
| Qué cambia al cargar la fecha | la columna, los días, el descuento y el neto | **sólo la columna** |

Verificado sobre los datos reales: en las 133 filas pendientes, `importe_neto == importe_bruto` y `Desc == '0%'` en todas, con o sin fecha manual. Lo fija `tests/test_cobranzas_may.php`.

Los **días sí** pasan a ser los reales (no el parámetro): mostrar `60` al lado de una fecha cargada a mano se contradiría a sí mismo. El plazo del parámetro queda en `PLAZO`, para poder auditar de cuánto se apartó la carga.

### En pantalla

- **Detalle Facturas:** la columna *Cobro* es un `<input type="date">`. Al cargar una fecha, la celda se pinta en azul y aparece un botón para volver a la fecha calculada. Guardar recarga la pestaña entera: la fecha cambia en qué columna del eje cae el importe y los totales del pie, y rehacer eso en el navegador sería reimplementar en JS la cuenta que ya hace el backend.
- **Resumen:** no es editable —la fila es un cliente, no un comprobante— y en su lugar aparece un indicador al lado del código diciendo que *alguna* de sus facturas tiene la fecha cargada a mano. La marca la repone `EjeVista::marcarAlguna()`, porque el agrupado descarta los campos que difieren dentro del grupo.
- El `min` del input está en hoy, pero eso es una comodidad del navegador: **la validación que vale es la del servidor** (`Ingresos::validarFechaCobroManual()`). No se aceptan fechas pasadas, porque una factura con fecha de ayer desaparecería del listado sin aviso y el usuario vería que su edición "borró" la fila.

Los endpoints son los mismos de Cobranzas FR: `IngresosController.php?action=saveFechaCobroManual` y `deleteFechaCobroManual`.

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
   - **Filtro por fecha de emisión (desde – hasta):** dos `<input type="date">` y un botón de limpiar. Es **server-side**: los extremos se mandan como parámetros y los items se filtran antes de `EjeVista`, porque filtrar escondiendo filas dejaría las columnas del eje, el pie de totales y las tarjetas mostrando el total sin filtrar. La validación (formato, calendario y `desde <= hasta`) corre en el servidor. Está explicado en `README-cobranzas-fr.md`.
   - **Resumen vs Detalle Facturas**, en sub-solapas anidadas (`nav nav-tabs`) directamente arriba de la tabla. Antes eran un `btn-group`; el cambio está explicado en `README-cobranzas-fr.md`. El nombre viejo era *Deep Dive*: se renombró sólo de cara al usuario, y el identificador interno sigue siendo `deepdive`.
     - *Resumen:* **una fila por cliente**, con los importes repartidos en las columnas de la grilla según la fecha de cobro de cada comprobante. Quedan `COD_CLI`, `RAZON_SOC`, `Importe Bruto` e `Importe Neto`.
     - *Detalle Facturas:* apertura individual por comprobante con fecha de emisión, tipo, número, importe y fecha de cobro.

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
- **Fecha manual:** la jerarquía de `resolverFechaCobro()`, que los días se recalculan sobre la fecha resuelta, y que una fecha manual vencida **no** se reubica en el primer día del eje mientras que una proyectada sí.
- **Que la fecha manual no cambia ningún importe:** sobre las filas reales, `importe_neto == importe_bruto` y `Desc == '0%'` en todas. Es la diferencia con Cobranzas FR y la que hay que dejar fijada, porque es justo lo que alguien podría copiar de allá por error.

Ejecución de la suite completa:
```bash
php tests/run.php
```
*Resultado: 1661 OK, 0 fallas (17 archivos).*
