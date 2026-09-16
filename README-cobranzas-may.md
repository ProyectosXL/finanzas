# Módulo Cobranzas Mayoristas (`Cobranzas May`) — Facturas Pendientes (+60 Días)

Implementación del módulo de **Cobranzas Mayoristas** en Cashflow (`c:\xampp\htdocs\finanzas`), siguiendo el patrón de diseño y arquitectura de **Cobranzas FR** y **Cob. Electrónicos**.

Rama: `feature/cobranzas-fr-proyeccion`; la fecha de cobro manual llegó en `feature/cashflow-estructura-inversiones`.

---

## La idea en una línea

**Lo que falta cobrar de cada factura mayorista se proyecta sumando a su fecha de emisión un plazo fijo de vencimiento (por defecto 60 días), configurable globalmente desde Parámetros — salvo que alguien haya cargado a mano la fecha de esa factura puntual, que es la que manda.**

```
Facturas pendientes de mayoristas
        GVA12 (cabecera)  ×  GVA46 (vencimientos)  ×  GVA07 (imputaciones)
        │
        ▼
IMPORTE_PENDIENTE = lo que falta cobrar   ← esto es lo que entra al cashflow
IMPORTE_FACTURA   = lo que decía la factura  (informativo, no se suma a nada)
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

> **El cambio grande de esta versión: se cobra el pendiente, no el facturado.** Hasta ahora la consulta leía `GVA12` a secas y usaba `g.IMPORTE` como importe a cobrar. Eso es el importe **facturado**: una factura cobrada a medias entraba al cashflow por su importe completo. Medido contra la cartera real del día en que se hizo el cambio, el tablero informaba **185.192.827,58** cuando lo que faltaba cobrar era **173.575.466,42**: once millones y medio de cobranza que ya había entrado.

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

El módulo implementa el flujo de **Camino 1: Facturas Pendientes** del sistema de mayoristas (`administracion/tesoreria/cobranzas/mayoristas.php`).

### La consulta: el pendiente sale de cruzar vencimientos con imputaciones

La consulta vive en `Ingresos::getCobranzasMay()` y hay una copia de referencia, con la tabla de signos explicada, en **`sql/_referencia_tango_pendientes_cobro.sql`** (el guion bajo la saca de la lista de scripts a correr en producción). Es la hermana del lado de ventas de `sql/_referencia_tango_pendientes.sql`, que es la de compras.

| Tabla | Qué aporta |
| --- | --- |
| `GVA12` | Cabecera del comprobante: cliente, fecha de emisión, importe facturado, estado |
| `GVA46` | **Vencimientos.** Un comprobante en cuotas tiene una fila por cuota, con su `IMPORTE_VT` y su `ESTADO_VTO` |
| `GVA07` | **Imputaciones:** qué comprobante canceló qué vencimiento |
| `GVA15` | Maestro de tipos de comprobante. `TIPO_COMP = 'D'` es débito |
| `GVA14` | Maestro de **clientes**: razón social y el flag `CLAUSULA` |

```
IMPORTE_PENDIENTE = SUM(GVA46.IMPORTE_VT + ISNULL(IMPU.IMPUTACIONES, 0))
```

**La tabla de signos de `IMPU` es lo importante de toda la consulta:**

```
T_COMP_CAN = 'REC'     -> RESTA   (recibo: el cliente pagó)
GVA15.TIPO_COMP = 'D'  -> SUMA    (nota de débito: deuda nueva)
cualquier otro caso    -> RESTA   (recibos, notas de crédito)
```

El `ISNULL` no es decoración: un vencimiento sin ninguna imputación devuelve `NULL` del `OUTER APPLY`, y un `NULL` adentro de una suma se lleva puesta la fila entera.

### Sólo facturas, y es a propósito

`T_COMP = 'FAC'`. La consulta anterior traía también `NDC`, `NDU`, `NC`, `NCC` y `NCU` como filas propias, con un `$multiplicador` que les daba vuelta el signo a las de crédito.

**Esa maquinaria se fue entera, y es lo que un lector futuro va a querer preguntar.** Las notas de crédito y débito **imputadas ya están descontadas del pendiente** por la subconsulta `IMPU`: traerlas además como filas propias las contaría dos veces. Con un solo `T_COMP` tampoco hay signo que invertir, así que `$isNC` y `$multiplicador` desaparecieron del código.

### Los otros filtros, y por qué están

| Filtro | Qué decide |
| --- | --- |
| `COD_CLIENT LIKE 'M%'` | Reemplaza a `'MA%'`. **Verificado contra la base:** no hay ningún cliente `M%` que no sea `MA%`, así que da el mismo conjunto y queda el filtro más simple |
| `GVA14.CLAUSULA = 0` | Deja afuera a los clientes con **cláusula de moneda extranjera** — el mismo campo que `CPA01.CLAUSULA` del lado de compras. **Verificado:** de 1.165 clientes `M%` hay **2 con `CLAUSULA = 1`** (`MAACCU` y `MAALLI`) y **ninguno de los dos tiene comprobantes pendientes**, de ningún tipo y de ninguna fecha. El filtro hoy no deja afuera un solo peso de cartera. Si alguno de esos dos empieza a operar, **esta línea lo esconde**: es el lugar donde mirar |
| `FECHA_EMIS >= hoy − 360 días` | Un **corte deliberado**, no una limitación técnica: una factura de hace más de un año sin cobrar no es cobranza proyectable. **Verificado:** hoy deja afuera dos facturas de 2017 por 44.652,05 en total |
| `ESTADO = 'PEN'` y `ESTADO_VTO <> 'PAG'` | No son el mismo filtro: la cabecera puede quedar en `'PEN'` con todas sus cuotas pagas |

### Las dos columnas de importe no significan lo mismo

| Columna | Qué es | Entra al cashflow |
| --- | --- | --- |
| `IMPORTE_PENDIENTE` → `importe_neto` / `importe_bruto` | Lo que **falta cobrar** | **Sí.** Es lo que se consolida contra el eje y lo que alimenta la serie `COBRANZA` |
| `IMPORTE_FACTURA` | Lo que decía la factura al emitirse | **No.** Es informativa: está para poder leer cuánto de esa factura ya se cobró |

`importe_bruto` e `importe_neto` son el **mismo número** —el pendiente—. El par existe porque es el contrato que `payloadCobranzas()` comparte con Cobranzas FR, donde sí difieren por la escala de descuento. Mayoristas no tiene escala.

En pantalla la columna de `importe_bruto` se llama **SALDO PENDIENTE**. Se llamaba *Importe Bruto* cuando el dato era `GVA12.IMPORTE`; desde que sale de cruzar `GVA46` con `GVA07` el rótulo viejo nombraba una cosa —el facturado— y mostraba otra. El nombre del campo en el payload no cambia: es el contrato compartido con FR.

`tests/test_cobranzas_may.php` fija que ningún pendiente supere a su importe facturado: si lo hiciera, la tabla de signos de `IMPU` estaría al revés.

### El pendiente puede volver en cero o en negativo

`IMPU` resta del pendiente **todo** lo imputado contra la factura: recibos, órdenes de pago, notas de crédito. Una factura sobre-imputada vuelve con `IMPORTE_PENDIENTE <= 0` aunque siga en `ESTADO = 'PEN'`.

- **No se muestran ni van al eje.** Una factura sin saldo no es plata a cobrar.
- **Las negativas dejan un aviso**, en la barra de avisos de la pestaña y también en el tablero. **Uno solo, agregado**, con el conteo y el importe total: uno por comprobante taparía el resto de la barra, y lo que hay que saber es que existen y por cuánto. El detalle está en Tango, no en esta pantalla.
- **Las que vuelven en cero no avisan nada.** Un cero no es plata que falte: es una factura ya cobrada entera que Tango todavía no cerró, y no hay nada que hacer con eso. Un negativo sí: contra esa factura se imputó más de lo que decía.

El texto del aviso lo arma `Ingresos::avisoSinSaldo()`, estático y con pruebas propias — tener facturas sobre-imputadas cargadas es justamente lo que no se le puede pedir a una tabla de Tango. Al día del cambio no había ninguna.

### La fecha de emisión puede venir nula

`NULLIF(GVA12.FECHA_EMIS, '18000101')`: `'18000101'` es como Tango escribe *sin fecha*. Antes el código hacía `new DateTime($row['FECHA_EMIS'])` sin chequear, y eso **con `null` no lanza**: devuelve la fecha de hoy, así que la factura se proyectaba a *hoy + 60 días* como si se hubiera emitido recién. Ahora la fila se saltea: sin fecha de emisión no hay nada de donde proyectar.

### El resto de la lógica

- **Cálculo de Proyección:**
  $$\text{Fecha Probable de Cobro} = \text{Fecha Emisión} + \text{Días de Plazo}$$
- **Las vencidas entran, ubicadas en hoy.** Antes se excluían los comprobantes cuya fecha probable de cobro fuera anterior al día de corte, y esa plata desaparecía de la pantalla sin aviso. Ahora entran si la fecha cae dentro de los últimos `Ingresos::DIAS_COBRO_VENCIDO` (180) días, se ubican en el primer día del eje y se marcan. La regla es la misma para las tres pestañas de cobranza proyectada y vive en `Ingresos::ubicarCobroVencido()`; está explicada en `README-cobranzas-fr.md`. **Una fecha cargada a mano no se reubica**: la pactó una persona, y moverla con una regla automática le mostraría su propia carga en otra columna. Se marca vencida —eso es un hecho— pero se dibuja donde está.
- **Días de Plazo:** Se obtienen desde `RO_T_CASHFLOW_PARAMETROS` (`cobranzas_may_dias_vto`, por defecto 60 días).

> **La fecha de cobro NO sale de `GVA46.FECHA_VTO`, aunque ahora la consulta toque esa tabla.** El `GROUP BY` junta los vencimientos y devuelve **una fila por comprobante**, así que no hay una sola fecha de vencimiento que nombrar. La regla no cambió: `FECHA_EMIS` + el parámetro, con la fecha manual mandando por encima, y las vencidas reubicadas en el primer día del eje.

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
     - *Resumen:* **una fila por cliente**, con los importes repartidos en las columnas de la grilla según la fecha de cobro de cada comprobante. Quedan `COD_CLI`, `RAZON_SOC`, `Importe Factura`, `SALDO PENDIENTE` e `Importe Neto`.
     - *Detalle Facturas:* apertura individual por comprobante con fecha de emisión, tipo, número, importe y fecha de cobro.

     Antes el Resumen agrupaba por cliente **y fecha**, así que un cliente con cobros en tres fechas ocupaba tres filas. El agrupado ahora lo hace `EjeVista::armarAgrupado()` sumando las series, no la consulta: así cada importe conserva la fecha que lo ubica en la grilla y la fila es una sola. `Ingresos::getCobranzasMay()` perdió su parámetro `$summary` — devuelve siempre una fila por comprobante. Ver `README-cobranzas-fr.md`.
   - **Selector de Eje Temporal:** Alterna entre vistas de *Días*, *Meses* y *Período Completo* (`Js/eje-vistas.js`).
   - **Acciones:** Botón de *Actualizar* y *Exportar a Excel*.
3. **La columna *Importe Factura*** va antes de las dos del pendiente, **en gris y marcada como informativa** en su `title`. Está apagada a propósito: si se leyera como un importe más del cuadro, el lector sumaría tres columnas de plata que miden dos cosas distintas. En *Resumen* se suma por cliente, igual que las otras dos.

> **La clave de columnas fijas cambió** a `cobranzas_may.con_importe_factura`. La selección se guarda por número de columna, así que agregar una columna en el medio corre todo lo que viene después: quien tuviera fijada *Importe Neto* se encontraría con *SALDO PENDIENTE* fijada y sin entender por qué. Cambiar la clave devuelve esa selección al default. Es lo mismo que se hizo cuando se fue la columna *Tipo*.

---

## Integración con el Tablero de Cashflow

El proveedor `IngresosProvider` registra la serie en `CashflowRegistry`:

| Código Proveedor | Serie | Descripción | Fila en Cashflow |
| --- | --- | --- | --- |
| `COBRANZAS_MAY` | `COBRANZA` | Cobranza proyectada de mayoristas (+60 días), por el **pendiente real** | Cobranza Mayoristas |

`IngresosProvider` consolida `getCobranzasMayTotales()`, que agrega `getCobranzasMay()` por fecha de cobro sumando `importe_neto` — el pendiente. `IMPORTE_FACTURA` no interviene en ningún punto del camino al tablero. El proveedor además repite el aviso de pendientes negativos: una factura sobre-imputada no puede salir del cuadro sin que nadie lo diga.

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
- **El aviso de pendientes negativos**, sin base: que no aparece con cero facturas, que es **uno solo** con siete, el singular y el plural, que el importe se muestra en valor absoluto —la palabra *NEGATIVO* ya está en la frase— y que dice explícitamente que esa plata **no entra al cashflow**.
- **Que lo que se cobra es el pendiente**, sobre las filas reales: que todos los comprobantes son `FAC`, que cada fila trae `IMPORTE_FACTURA`, que ninguna entra con pendiente cero o negativo, que **ningún pendiente supera a su importe facturado** —si lo hiciera, la tabla de signos de `IMPU` estaría al revés— y que la serie del tablero es **exactamente** el pendiente del listado y no el facturado.

Ejecución de la suite completa:
```bash
php tests/run.php
```
*Resultado: 2010 OK, 0 fallas (18 archivos).*
