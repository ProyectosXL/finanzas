# Módulo de Proyección de Ventas y Cobranzas

Proyección de ventas y su conversión en cobranzas. Reemplaza el modelo de Excel.

Rama: `feature/ventas-proyeccion`

---

## Orden de ejecución de los scripts

Estos scripts **ya se ejecutaron** contra `central`. Quedan documentados porque son reejecutables (las tablas se crean sólo si no existen y las semillas entran por `MERGE`), así que sirven para levantar el módulo en otra base. Si es el caso, corrélos a mano en este orden:

### 1. Tablas y semillas

```sql
-- sql/ventas_proyeccion.sql
```

Crea las seis tablas y carga las semillas:

| Tabla | Qué guarda |
| --- | --- |
| `RO_T_CASHFLOW_VENTAS_HIST` | Histórico agregado por mes / canal / tipo de comprobante |
| `RO_T_CASHFLOW_VENTAS_INDICE` | Índice de variación por mes |
| `RO_T_CASHFLOW_VENTAS_PARTIC` | Participación calculada + editada por canal |
| `RO_T_CASHFLOW_VENTAS_MIX` | Mix de cobro **plano** (9 filas de semilla). Desde `sql/cashflow_ventas_mix_nodo.sql` deja de leerse y queda por el histórico: ver el paso 5 |
| `RO_T_CASHFLOW_PARAMETROS` | Clave/valor genérico (alícuota, horizonte, feriados, respaldo) |
| `RO_T_CASHFLOW_VENTAS_PRECHEQ` | Neteo de cheques adelantados — vacía y cableada |

Es idempotente: las tablas se crean sólo si no existen y las semillas entran por `MERGE`, así que se puede volver a correr sin duplicar ni pisar valores ya editados.

### 1.b Migración: columna MODULO

Sólo si `RO_T_CASHFLOW_PARAMETROS` ya existía de una versión anterior:

```sql
-- sql/migracion_parametros_modulo.sql
```

Agrega la columna `MODULO` que usa la pestaña Parámetros para agrupar por módulo, y marca como `VENTAS` los parámetros ya cargados. Es idempotente y no toca ningún `VALOR`. El mismo bloque ya viene dentro de `ventas_proyeccion.sql`: alcanza con correr cualquiera de los dos.

### 2. Stored procedure

```sql
-- sql/SJ_CASHFLOW_VENTAS_HIST.sql
```

Hace `DROP` + `CREATE` del SP, así que también es reejecutable.

### 3. Carga inicial del histórico

```sql
EXEC SJ_CASHFLOW_VENTAS_HIST @Desde = '2025-01-01', @Hasta = '2025-12-31';
EXEC SJ_CASHFLOW_VENTAS_HIST @Desde = '2026-01-01', @Hasta = '2026-12-31';
```

Conviene cargar **dos años**: el horizonte de 12 meses cruza de año y cada mes proyectado lee el mismo mes del año anterior. Con un solo año cargado, los meses del horizonte que caen en el año siguiente proyectan cero.

Para la actualización periódica alcanza con:

```sql
EXEC SJ_CASHFLOW_VENTAS_HIST;   -- últimos 30 días
```

**Sobre el rango**: la tabla destino agrega al grano de mes, así que el SP expande internamente el rango al primer día del mes de `@Desde` y al último día del mes de `@Hasta`. Sin eso, un rango a mitad de mes borraría el mes entero y sólo repondría la porción pedida. Es lo que lo hace reejecutable sin perder importe ni duplicar filas.

### 4. Vista del tipo de cambio

```sql
-- sql/RO_V_DOLAR_OFICIAL_BCRA.sql
```

Hace `DROP` + `CREATE` de la vista, así que también es reejecutable. Devuelve **una fila por año/mes** con la cotización del **último día cargado** de ese mes, o sea el tipo de cambio de cierre; para el mes en curso, la última disponible.

La usa la sub-pestaña **Venta Acumulada**. Si no está creada, esa pestaña **igual funciona**: sale sólo en pesos y avisa arriba. Ver la clase `Cotizacion` más abajo.

### 5. El mix de cobro como árbol y la fila de costos de cobro

**Estos dos scripts NO se corrieron todavía.** Se corren **en el mismo momento de publicar** el código de `feature/ventas-mix-apertura`, en este orden:

```sql
-- sql/cashflow_ventas_mix_nodo.sql          crea el árbol y migra el mix plano
-- sql/cashflow_estructura_costos_cobro.sql  la fila "Costos de cobro" del tablero
```

**Por qué al publicar y no después**: el código nuevo sin el primer script **no se cae** —Ventas y el tablero siguen calculando con el mix plano, bruto y sin costos, y la fila de costos da cero—, pero en *Parámetros → Ventas* el mix se muestra **sólo para consulta**: no hay tabla donde guardar un nodo, así que **hasta correrlo el mix no se puede editar**. La pantalla lo dice y nombra el script.

| Script | Qué hace | Si no se corre |
| --- | --- | --- |
| `sql/cashflow_ventas_mix_nodo.sql` | Crea `RO_T_CASHFLOW_VENTAS_MIX_NODO` y migra el mix plano **una sola vez por canal** (un canal que ya tiene nodos no se toca). Ver *Mix de cobro: el árbol* | Mix plano, bruto y sin costos; Parámetros lo muestra sólo para consulta, con aviso. Ventas y el tablero muestran un aviso informativo |
| `sql/cashflow_estructura_costos_cobro.sql` | Agrega la fila **Costos de cobro (comisiones y tasas)** a la sección Ventas, entre Ecommerce y *Total Ventas*, apuntada a `VENTAS → COSTO_COBRO` | El tablero muestra la cobranza de Ventas **en bruto, sin restar el costo de cobro**, y no avisa: cada serie es correcta por separado. Mientras no haya costos cargados en el mix, da lo mismo |

El primero va después de `sql/ventas_proyeccion.sql` (crea el mix plano que migra); el segundo, después de `sql/cashflow_estructura_disponibilidades.sql` (crea la sección Ventas y sus filas por canal). Los dos son reejecutables.

**Apenas se corren, la proyección da exactamente lo mismo que antes.** Medido contra la base el 09/10/2026, con el código de `develop`, el código nuevo sin el script y el código nuevo con el árbol migrado (sobre `#temporales`), uno detrás del otro: las 160 celdas de cobranza por canal (28 días + 12 meses × 4 canales) dan iguales **al último bit**, el tramo da $ 2.441.776.733,02 y el horizonte $ 43.706.286.291,98 en las tres corridas, y el costo de cobro da 0,00. Ver *El invariante de la migración*.

---

## Modelo de cálculo

```
VentaNetaProyectada(M) = VentaNetaReal(M, año anterior) × (1 + índice_M)
VentaConIVA(M)         = VentaNetaProyectada(M) × (1 + alícuota_iva)
VentaCanal(c, M)       = VentaConIVA(M) × %Participación(c, M)
VentaDiaria(c, d)      = VentaCanal(c, M(d)) / (días_del_mes − feriados_comercio)
Bruto(c, h, d)         = VentaDiaria(c, d) × %efectivo(h)
Costo(c, h, d)         = Bruto(c, h, d) × (costo + tasa acumulados de h)
Neto(c, h, d)          = Bruto(c, h, d) − Costo(c, h, d)
FechaAcreditación      = d + Días(h)
                         → corrida al PRÓXIMO día bancario hábil si cae en no hábil
```

`h` es una **hoja** del árbol del mix de cobro del canal: su % efectivo es el producto de los porcentajes de su rama, su costo y su tasa se suman a lo largo de la rama y sus días son los del nivel más cercano que los tenga. Ver *Mix de cobro: el árbol*. El costo cae en la **misma fecha** que el bruto del que sale.

Toda la venta del módulo es **estimada**. El histórico de Tango se usa únicamente como base de cálculo.

### Los dos calendarios

No se mezclan nunca:

| Calendario | Para qué | Regla |
| --- | --- | --- |
| **Comercial** | Estimación de venta | Todos los días del año excepto los del parámetro `feriados_comercio` (`25/12`, `01/01`, `26/09`). Sábados y domingos **sí** tienen venta: las sucursales abren. |
| **Bancario** | Acreditación de cobranza | `RO_T_CALENDARIO.DIA_LABORAL = 1`, en la conexión `power`. Excluye sábados, domingos y feriados nacionales. |

Como el divisor de la venta diaria descuenta los feriados de comercio, el total mensual se conserva. Como los sábados y domingos de cobranza se corren al lunes, la cobranza se concentra los lunes: es un **efecto del corrimiento**, no una regla aparte.

### Pestaña Análisis de Ventas

Tres bloques, **ninguno abierto por canal**:

**1. Tendencia — Últimos 6 Meses** *(colapsable, cerrado por defecto)* — venta neta contra el mismo período del año anterior.

| Columna | Base | Qué es |
| --- | --- | --- |
| Mes | — | Los 6 meses que terminan en el mes de la última fecha cargada |
| Venta Neta | **neto s/ IVA** | Venta real del mes |
| Mismo Período Año Anterior | **neto s/ IVA** | Venta real del mismo tramo, un año atrás |
| Var. Interanual | — | `Venta Neta / Año Anterior − 1`, o guion si el año anterior no es positivo |

> **Por qué necesita un histórico diario**: el mes en curso está incompleto. Contra un mes entero del año anterior la variación saldría siempre hundida, porque enfrentaría los días transcurridos contra treinta. Este bloque sale de `RO_T_CASHFLOW_VENTAS_HIST_DIA` (SP `RO_SP_CASHFLOW_VENTAS_HIST_DIA`), que guarda la venta al grano de día, y **recorta el año anterior a los mismos días**. La fila del mes incompleto lleva el badge `parcial` y el rango de días comparados.

El día de corte es la **última fecha cargada**, no "ayer" calculado: el origen se actualiza de madrugada, así que normalmente da ayer, pero si el job no corrió el encabezado lo dice ("parcial al 03/09") en vez de comparar un mes contra unos pocos días. La ventana se ancla en el mes de esa fecha, con lo cual **toda fila que se muestra tiene dato**: el día 1 de mes salen seis meses cerrados y ninguna fila parcial.

Esta tabla **no** alimenta la proyección: la base de cálculo sigue siendo la tabla mensual. Sus importes son netos y no se comparan contra la Venta Proyectada del bloque siguiente, que lleva IVA.

**2. Proyección de Venta por Mes** — el card tiene **tres sub-pestañas** (tercer nivel de navegación, dentro del card):

| Sub-pestaña | Período | Base | Dato |
| --- | --- | --- | --- |
| **Venta Cashflow** *(abre por defecto)* | Mes actual + 11 | netos contra proyectada **con IVA** | La que alimenta la proyección |
| **Venta Acumulada** | Año calendario en curso | **neto s/ IVA** | Venta **real**, en pesos y en dólares |
| **Venta Balance** | 1/8 al 31/7 en curso | **con IVA** | Real de meses cerrados + proyectado |

> **Las tres no están en la misma base y no se comparan entre sí.** Cada una lo declara en el subtítulo del card —que cambia con la pestaña activa— y lo repite en el `th-sub` de cada columna de importe.

Las dos vistas nuevas se piden **lazy**, recién cuando se abre su pestaña (`getVentaAcumulada` y `getVentaBalance`), igual que la sub-pestaña Proyección. Así la pantalla que ya funcionaba no paga la consulta cruzada al linked server del tipo de cambio, que quizás nadie mire.

#### 2.a Venta Cashflow

| Columna | Base | Qué es |
| --- | --- | --- |
| Mes-Año | — | Mes proyectado (`sept-26`, `oct-26`, …) |
| Año Previo | **neto s/ IVA** | Venta neta real del mismo mes, **dos** años atrás. Sólo sirve para la comparación. |
| Año Anterior | **neto s/ IVA** | Venta neta real del mismo mes, **un** año atrás. Es la **base** de la proyección. |
| Var. Interanual | — | `Año Anterior / Año Previo − 1` |
| % de Variación | — | Editable. Se ingresa **el porcentaje**: `9` = +9% sobre el año anterior, y se guarda la tasa (`0,09`). Se guarda contra el `(año, mes)` **del mes proyectado**. |
| Venta Proyectada | **con IVA** | `Año Anterior × (1 + Variación) × (1 + IVA)` |

> **Ojo con la base**: las dos columnas de años son **netas sin IVA** y la Venta Proyectada **lleva IVA**. Por eso, aunque la variación esté en 0%, la proyectada es mayor que el año anterior: la diferencia es exactamente la alícuota. El encabezado de cada columna lo aclara y el tooltip de cada celda proyectada muestra la cuenta completa.

La venta proyectada de esta tabla y la de la grilla de Proyección salen del **mismo helper** (`Ventas::baseMensual()`), así que no se pueden desincronizar.

#### 2.b Venta Acumulada

Venta **real** del año calendario en curso, acumulada, **neta sin IVA**, en pesos y en dólares. Filas de enero al mes en curso.

| Columna | Base | Qué es |
| --- | --- | --- |
| Mes | — | Enero al mes en curso |
| Venta Neta | **neto s/ IVA** | Venta real del mes |
| Acumulado | **neto s/ IVA** | Acumulado del año en pesos |
| T/C | — | Cotización del **último día** del mes (cierre) |
| Venta Neta USD | **neto s/ IVA** | `Venta Neta / T/C del mes` |
| Acumulado USD | **neto s/ IVA** | **Suma de los meses ya valuados** |

Los **meses cerrados** salen de `RO_T_CASHFLOW_VENTAS_HIST` (sólo `FACTURA`: los remitos no entran, igual que en toda la proyección). El **mes en curso** está incompleto en la tabla mensual, así que sale de `RO_T_CASHFLOW_VENTAS_HIST_DIA` recortado a la última fecha cargada, y la fila lleva el badge `parcial al dd/mm`, igual que el bloque de tendencias. Si el corte todavía cae en el mes anterior, la fila del mes en curso **no se dibuja**: así toda fila que se muestra tiene dato.

> **Cada mes se valúa a SU propio tipo de cambio de cierre y los dólares se suman después.** El acumulado en dólares **no** es el acumulado en pesos dividido por un tipo de cambio: eso sería reexpresar toda la serie a moneda de hoy, que con inflación da un número completamente distinto. Está dicho en el tooltip de las columnas de dólares.

Un mes **sin cotización** muestra un guion —no un cero— y **no corta** el acumulado de los meses que sí la tienen; el total avisa cuántos meses quedaron sin valuar. Si la vista del tipo de cambio no está disponible en el entorno, la tabla sale **sólo en pesos** con un warning arriba, en vez de una columna entera de guiones.

#### 2.c Venta Balance

Año balance **1/8 al 31/7** en curso: si el mes actual es >= 8 va del 1/8 de este año al 31/7 del que viene; si no, del 1/8 del año pasado al 31/7 de este. Doce filas, de agosto a julio, **todo con IVA** para que las dos mitades sean sumables.

| Columna | Base | Qué es |
| --- | --- | --- |
| Mes | — | Agosto a julio |
| Origen | — | `Real` / `Proyectado` |
| Venta | **con IVA** | Real: `neto × (1 + alícuota)`. Proyectado: la venta proyectada, que ya lleva IVA. |
| Acumulado | **con IVA** | Acumulado del balance |

Al pie, el total del balance con el desglose de cuánto es real y cuánto proyectado. **Sólo pesos.**

> **El mes en curso siempre es proyectado**, aunque el histórico ya tenga venta cargada. "Mes cerrado" es un mes **estrictamente anterior** al mes actual: un mes a medio facturar sumado contra meses completos hunde el total del balance y no se nota.

El eje **no** usa `horizonte_meses` —es un parámetro editable y con 6 el balance saldría cortado a la mitad—: son doce meses fijos anclados al inicio del balance (`new Horizonte(0, 12, $feriados, new DateTime($inicioBalance))`). La venta proyectada igual sale de `Ventas::baseMensual()`, el **mismo helper** que la pestaña Cashflow, así que las dos no se pueden desincronizar.

**3. Control de Facturación** — por mes: `Facturas`, `Remitos` y `Total`. Es sólo un bloque de control para contrastar contra el tablero; los remitos **no** entran en la proyección.

### Superposición tramo / meses

Los 28 días se muestran en columnas diarias y la columna del mes acumula **únicamente** los días que quedaron fuera del tramo. Nada se cuenta dos veces.

En la vista **Meses**, el encabezado de cada mes de la tabla *Venta Proyectada* abre al hover la **participación por canal de esa columna**: canal, importe y % del total, más el rango de días que la columna cubre. Ese rango es lo que hace legible un mes recortado — un importe más chico son menos días, no una caída de venta.

El rango **no** es "el mes menos el tramo": la ventana de proyección arranca hoy, así que los días anteriores del mes en curso no aportan nada y no se cuentan como cubiertos. Un mes que queda íntegramente dentro del tramo lo dice en lugar de mostrar ceros.

Los porcentajes del tooltip salen del **cociente de los importes**, no de la participación guardada: los overrides mensuales se graban sin la validación del 100% que sí tiene el tramo, y el cociente siempre concuerda con los importes que están al lado. Dentro del tramo rige `tramo28`, pero no entra en este tooltip: la columna del mes contiene sólo días de fuera del tramo, calculados todos con la participación mensual.

### Participación por canal

- **Tramo de 28 días**: se calcula desde el mismo período del año anterior y el usuario puede editarla. La suma de los cuatro canales debe dar exactamente 100%; si no da, el guardado queda bloqueado y se muestra el desvío. La validación corre también en el servidor.
- **Meses 2 a 12**: automática desde el año anterior, con override manual previsto por mes × canal.
- **Fallback**: si el mes del año anterior no tiene datos o su venta total no es positiva, se usan los porcentajes fijos de respaldo de `RO_T_CASHFLOW_PARAMETROS` y el mes queda **marcado como estimado**.

### Cobranza Proyectada: el árbol del mix

La grilla de cobranza de la sub-pestaña Proyección es un **árbol que se abre y se cierra**: Canal › y cada nivel de su rama (Medio de pago › Tipo de tarjeta › Procesadora › Cuotas; en Ecommerce, un Marketplace arriba de todo). Cada nodo con ramas debajo es un subtotal. Arranca abierta hasta el primer nivel de cada canal, y dos botones abren todo o vuelven a ese punto.

| Columna | Qué es |
| --- | --- |
| Concepto | El nombre del nodo con la sangría de su nivel. El tooltip dice el nivel, el camino completo y, en una hoja que hereda los días, de quién |
| % efectivo | Qué parte de la venta del canal cobra esa rama: el producto de los porcentajes de su cadena |
| Costo + tasa | En una hoja, el acumulado de su rama. En un nodo con ramas debajo —y en la fila del canal—, el **promedio de sus hojas ponderado por % efectivo**: lo que cuesta cobrar la rama entera |
| Días | Los de la hoja. En los subtotales va vacía: cada hoja tiene los suyos |

- **Selector Bruto / Costo / Neto**: qué importe muestran las columnas de fechas. Arranca en **Bruto**, que es lo que muestra la fila de cada canal en el tablero. El costo se muestra **en negativo**, como la fila del tablero.
- **El pie muestra siempre tres filas**, sea cual sea el selector: *Cobranza bruta*, *Costos de cobro* (negativo) y *Cobranza neta*. Los tres importes vienen del servidor —el neto se calcula como bruto − costo celda por celda—, así que bruta − costos = neta en cada columna, sin que la pantalla reste nada.
- **Los KPIs** de cobranza del tramo y del horizonte muestran la neta al lado de la bruta.
- **Las hojas al 0%** se muestran igual, en cero. Los nodos inhabilitados —y todo lo que cuelga de ellos— no aparecen.
- **La pantalla no resuelve nada**: el % efectivo, la carga, los días y los importes de cada nodo vienen de `Ventas::cobranzaPorHojas()`, que usa la regla de `MixCobro::resolver()`.
- **Exportar** baja lo que se ve: las ramas cerradas no van, y los importes son los de la medida elegida. La tabla no se ordena por columna: es un árbol, y ordenar separaría cada nodo de su rama.

Un mix inválido —un grupo de hermanos activos que no suma 100%, una hoja sin días en toda su rama o un canal sin hojas— **no corta la proyección**: se calcula con lo que hay y se avisa como **crítico**, arriba de la grilla y en el tablero, diciendo el canal, la rama y cuánta venta de la ventana queda afuera. Una hoja sin días **no se proyecta**: sin plazo no hay fecha, y una fecha inventada sería plata en un día que nadie eligió. Parámetros no deja guardar un árbol así, así que si aparece es porque la tabla se tocó por otro lado.

---

## Conexiones

Todo el módulo se conecta a **`central`**, con una única excepción: la lectura del calendario bancario, que va a la conexión **`power`** (host de apps, base `DATABASE_POWER`), donde vive `RO_T_CALENDARIO`.

`RO_T_CALENDARIO` está poblada hasta 2027 y se sigue extendiendo. Si el motor pide una fecha que no existe, **no rompe**: asume hábil de lunes a viernes y devuelve un warning, que la pestaña muestra arriba de la grilla.

El tipo de cambio se lee también desde `central`, pero la vista `RO_V_DOLAR_OFICIAL_BCRA` resuelve por **linked server** contra `[XL-APPS]`. O sea que puede fallar aunque `central` responda perfecto —es lo que pasa en una máquina de desarrollo sin acceso a ese host—, y por eso se lee siempre envuelta en try/catch.

## Tipo de cambio — la clase `Cotizacion`

`cashflow/Class/Cotizacion.php` es el **único punto de acceso al tipo de cambio de todo el cashflow**, no sólo de Ventas: cualquier bloque que necesite mostrar importes en dólares lo pide acá, para que exista una sola lectura del origen y un solo criterio.

| Método | Devuelve |
| --- | --- |
| `mapaMensual($desde, $hasta)` | Mapa `'YYYY-MM' => float` con el T/C de cierre de cada mes del rango |
| `delMes($anio, $mes)` | `float\|null` — el T/C de cierre de un mes puntual |

Tres criterios, y los tres importan:

- **Cada mes se valúa a su propio T/C de cierre**, y los importes en dólares se suman recién después. Dividir un acumulado en pesos por un único tipo de cambio es otra cuenta —reexpresar la serie a moneda de hoy— y con inflación no se parece en nada.
- Un mes **sin cotización** devuelve `null`, **no cero**. Un cero se leería como "el dólar valía cero" y, además, dividir por él revienta.
- Si la vista **no existe** en el entorno, los métodos lanzan y el llamador sigue sin la parte en dólares con un warning. Es el mismo criterio que ya se aplicó a `getTendencias()` cuando dependía de una tabla nueva.

Los meses sin cotización **no están** en el mapa: la clave ausente es lo que distingue "no hay dato" de "el dato es cero".

---

## Parámetros

Ningún valor de negocio está escrito en el código. Todo sale de `RO_T_CASHFLOW_PARAMETROS` y del árbol del mix de cobro, `RO_T_CASHFLOW_VENTAS_MIX_NODO`, y se edita desde la pestaña **Parámetros**. Lo único fijo en el código son los **tipos de nivel** del árbol, que son estructura y no valores (ver *Mix de cobro: el árbol*).

### Agrupados por módulo

La pestaña se organiza en **sub-pestañas, una por módulo**, para que se entienda de un vistazo qué afecta cada valor. Hoy existe sólo **Ventas**; la columna `MODULO` de `RO_T_CASHFLOW_PARAMETROS` es la que atribuye cada parámetro a su pestaña.

> Si la tabla se creó con una versión anterior del script, la columna `MODULO` no existe todavía. Corré **`sql/migracion_parametros_modulo.sql`** (idempotente, no toca ningún valor ya editado). Mientras no lo hagas, la pestaña **igual funciona**: muestra todos los parámetros como Ventas y avisa arriba que falta la migración.

**Para agregar un módulo** hacen falta tres cosas:

1. Cargar sus parámetros con ese `MODULO` en `RO_T_CASHFLOW_PARAMETROS`.
2. Declararlo en `Parametros::$modulos` (nombre, ícono, descripción y qué secciones muestra).
3. Agregar el `<li>` y el `tab-pane` en `Tabs/parametros.php`.

Las secciones disponibles son `generales` (clave/valor del grupo `GENERAL`), `respaldo` (grupo `RESPALDO`) y `mix` (el árbol del mix de cobro, resuelto por `MixCobro::datosEditor()`).

| Clave | Semilla | Qué controla |
| --- | --- | --- |
| `alicuota_iva` | `0.21` | IVA sobre la venta neta proyectada |
| ~~`dias_prechequeado`~~ | `0` | **Sin uso.** Los días de pre-chequeado pasaron a ser por cliente. La fila queda en la tabla pero la pantalla no la muestra; ver más abajo |
| `horizonte_dias` | `28` | Columnas diarias de la proyección |
| `horizonte_meses` | `12` | Columnas mensuales de la proyección |
| `feriados_comercio` | `12-25,01-01,09-26` | Días sin venta estimada, formato `MM-DD` |
| `respaldo_locales` | `0.410` | Participación de respaldo |
| `respaldo_franquicias` | `0.365` | Participación de respaldo |
| `respaldo_mayoristas` | `0.140` | Participación de respaldo |
| `respaldo_ecommerce` | `0.085` | Participación de respaldo |

### Mix de cobro: el árbol

El mix de cobro es un **árbol por canal**, en `RO_T_CASHFLOW_VENTAS_MIX_NODO`:

```
LOCALES
├─ Efectivo                         10%   costo 3,10%           1 día
└─ Tarjeta                          90%
   ├─ Débito                        20%   costo 3,18%           7 días
   └─ Crédito                       80%
      ├─ Payway                     10%   costo 4,90%           1 día
      ├─ Mercado Pago               10%   (sin costo)          18 días
      ├─ Fiserv                     60%   costo 4,90%           7 días
      │  ├─ 3 cuotas                30%            tasa 0,90%   1 día
      │  └─ Resto                   70%                      (hereda 7)
      └─ Promo Bancarias            20%   costo 4,90%          15 días
ECOMMERCE
├─ Vtex                             70%
│  └─ Tarjeta                      100%                         2 días
└─ Mercado Libre                    30%   comisión 14%
   └─ Mercado Pago                 100%                        18 días
```

*(Los porcentajes son ilustrativos: los carga el usuario en Parámetros.)*

**Los tipos de nivel son una lista fija y ordenada del código** —`MixCobro::NIVELES`—, no de la base. Es estructura, no un valor de negocio, y la misma lista está en el `CHECK` de la columna `NIVEL`: **cambian juntas**.

| Orden | Código | Rótulo |
| ---: | --- | --- |
| 1 | `MARKETPLACE` | Marketplace |
| 2 | `MEDIO_PAGO` | Medio de pago |
| 3 | `TIPO_TARJETA` | Tipo de tarjeta |
| 4 | `PROCESADORA` | Procesadora |
| 5 | `CUOTAS` | Cuotas |

**Estructura** (la valida el servidor, `MixCobro::validarEstructura()`):

- El nivel de un hijo es **estrictamente posterior** al de su padre, y se pueden saltear niveles: Mercado Libre › Mercado Pago (medio) puede terminar ahí o bajar directo a Cuotas.
- Una rama puede **terminar en cualquier nivel**: Efectivo termina en Medio de pago, Payway en Procesadora, Fiserv baja a Cuotas.
- En **Ecommerce** todo cuelga de un marketplace: su primer nivel sólo admite `MARKETPLACE`. En **los demás canales** el primer nivel es `MEDIO_PAGO`, y `MARKETPLACE` no existe.
- **Franquicias y Mayoristas** usan el mismo árbol y hoy tienen un solo nivel. El modelo no les impide crecer, y el editor les permite cargar costo y tasa igual que a los demás.
- El nombre es **único entre hermanos**, sin distinguir mayúsculas ni acentos —como la collation de la columna—. El mismo nombre en otra rama sí vale: "Mercado Pago" como medio de pago de Mercado Libre y como procesadora de Crédito son nodos distintos.
- Porcentaje, costo y tasa entre 0% y 100%; días enteros y no negativos.

**La regla** (`MixCobro::resolver()`, la **única** implementación: la usan el motor, la pestaña Ventas, Parámetros y el tablero):

| | |
| --- | --- |
| **Activo y hoja** | Un nodo inactivo saca de juego **todo su subárbol**. Una hoja es un nodo en juego sin hijos en juego: inhabilitar "3 cuotas" y "Resto" convierte a Fiserv en hoja sin borrar nada, y entonces Fiserv necesita días |
| **% efectivo** | El producto de los porcentajes de la cadena: Tarjeta 90% × Crédito 80% × Fiserv 60% × 3 cuotas 30% = 12,96% de la venta del canal |
| **Costo y tasa** | **Se suman** a lo largo de la rama, con null como cero. Cada nivel carga sólo lo suyo y la hoja paga todo: Fiserv 4,90% + "3 cuotas" 0,90% = 5,80%. Los porcentajes **ya incluyen IVA e impuestos**. La comisión del marketplace es costo de cobro y entra en la misma fila del tablero |
| **Días** | **Gana el nivel más cercano** que los tenga; no se suman, porque el plazo lo pone quien acredita. Se cuentan desde el día de venta y se corren al próximo hábil bancario, como siempre |
| **Cuotas** | Una venta en cuotas se acredita **entera** a los días de su hoja. No se reparte por mes |

Y lo que exige para que la venta del canal llegue entera a la caja:

- Los hermanos **activos** de cada grupo suman 100%, en todos los niveles. Los inactivos no suman, tengan el porcentaje que tengan. Un grupo **fuera de juego** —debajo de un nodo inhabilitado— no se valida: apagar una rama no obliga a rehacerla.
- Toda hoja tiene días en algún nivel de su rama.
- El costo + tasa acumulado de una hoja es menor a 100%.
- El canal tiene al menos una hoja: sin ninguna, su venta desaparecería del cashflow.

**Vacío no es lo mismo en todos los campos, y es a propósito.** Un dato que falta es null y avisa —la regla del módulo—, **con una excepción explícita: el costo y la tasa vacíos valen cero y no avisan**, porque es una decisión de negocio que muchos nodos no tengan costo. Los días vacíos son "los define un nivel de arriba", y 0 días es "se acredita el mismo día".

### Mix de cobro: el editor de Parámetros

Se administra desde **Parámetros → Ventas → Mix de Cobro y Plazos**, un bloque por canal con columnas Nombre, Nivel, %, Costo %, Tasa %, Días, Activo y Última edición.

- **Lo heredado, a la vista.** Al lado de cada campo, en gris, lo que resuelve la regla para ese nodo: el costo + tasa de su rama ("rama: 5,80% (costo 4,90% + tasa 0,90%)") y de quién hereda los días ("hereda 7 de Fiserv"). Es lo que hace entendible la herencia.
- **El 100% de cada grupo.** El nodo padre —o el canal, para el primer nivel— muestra la Σ de sus hijos activos, en rojo si no da 100%. Con algún error, el canal lo lista arriba de sus nodos y **Guardar** queda bloqueado diciendo por qué.
- **La pantalla no tiene la regla.** Lo heredado, las sumas y si se puede guardar salen del servidor: del payload inicial y, mientras se edita, de `previsualizarMixArbol`, que resuelve el canal **como quedaría** con la misma función que el motor. Se consulta al **cambiar de campo**, no en cada tecla; mientras se tipea o se espera la respuesta, Guardar queda bloqueado.
- **Agregar**, en el encabezado de cada canal (primer nivel) y en cada nodo (hijo): nombre, nivel —sólo los que admite ese lugar—, costo, tasa y días. **No pide porcentaje: lo nuevo entra inhabilitado y en 0%**, así no rompe el 100% de su grupo. Para usarlo hay que encenderlo y reacomodar sus hermanos.
- **Inhabilitar** es el switch de *Activo*: no se borra nada. Inhabilitar un nodo con ramas debajo pide confirmación y dice cuántas hojas saca de la proyección.
- **Renombrar** se permite, respetando el único entre hermanos. **Cambiar el nivel de un nodo con hijos no**: si quedó mal, se inhabilita y se crea otro. Un nodo tampoco se mueve de rama.
- **Guardar** es por canal. Manda el árbol del canal y el servidor escribe **sólo los nodos que cambiaron**, en una transacción, después de volver a validar el canal entero como quedaría: un árbol inválido corta antes de abrir la transacción. Otro canal roto —por una edición directa en la base— no impide guardar este.
- **Exportar** baja lo que se ve, con el camino completo de cada nodo.
- **Sin permiso de edición** (`['parametros', 'VENTAS']`), el árbol se ve sin ningún control. **Sin el script**, también, y la tarjeta dice qué correr.

Las acciones son `previsualizarMixArbol`, `saveMixArbol` y `addMixNodo`. Las del mix plano —`getMixCobro`, `saveMixCobro`, `addMixCobro`— se retiraron, junto con su editor.

### El invariante de la migración

`sql/cashflow_ventas_mix_nodo.sql` migra el mix plano así, **una sola vez por canal** —si la tabla nueva ya tiene nodos de un canal, no lo toca—:

| Canal | Árbol migrado | % | Días | Activo |
| --- | --- | ---: | ---: | :---: |
| Locales | Efectivo *(era CASH)* | 10% | 1 | sí |
| Locales | Tarjeta | 90% | 2 | sí |
| Locales | Go Cuotas | 0% | 10 | **no** |
| Franquicias | Transferencia | 3% | 30 | sí |
| Franquicias | Echeq | 97% | 40 | sí |
| Mayoristas | Efectivo *(era CASH)* | 0% | 1 | no *(ya estaba)* |
| Mayoristas | Echeq | 100% | 60 | sí |
| Ecommerce | **Vtex** *(marketplace nuevo)* | 100% | — | sí |
| Ecommerce | Vtex › Tarjeta | 100% | 2 | sí |
| Ecommerce | Vtex › Go Cuotas | 0% | 10 | **no** |

Todos los medios son nodos de `MEDIO_PAGO`, con mayúscula inicial y sin costo ni tasa. Los nombres se muestran **tal cual se guardan**: son texto libre ("3 cuotas", "Resto") y la pantalla ya no los normaliza. Go Cuotas queda inhabilitado en todos los canales; si en alguna base estuviera activo con porcentaje, el script lo avisa, porque inhabilitarlo rompería el 100% de su canal. Cada nodo migrado guarda en `ID_MIX_ORIGEN` de qué fila vino. Mercado Libre lo carga el usuario después.

**Apenas se publica, la proyección da exactamente lo mismo que antes**: sin costos cargados, el bruto es el de antes y la fila de costos da cero. Vtex entra al 100% y sin días, así que no cambia ni el porcentaje ni el plazo de lo que cuelga de él. Medido contra la base el 09/10/2026 —ver el paso 5 de *Orden de ejecución*—: 160 celdas iguales al último bit.

`RO_T_CASHFLOW_VENTAS_MIX` **no se borra**: deja de leerse cuando existe la tabla nueva —aunque esté vacía: un árbol vacío es un mix sin medios, que se avisa, no una señal para volver al mix viejo— y queda por el histórico.

---

## Neteo de cheques adelantados

**El circuito está enchufado.** Hay clientes que entregan los echeqs *antes* de que se les facture: esa venta futura ya está cobrada y proyectarla de nuevo la contaría dos veces.

| Pieza | Qué hace |
| --- | --- |
| `Echeqs → Venta Cobrada Anticipada` | Dónde se tilda qué cheques netean, con su grilla temporal |
| `Parámetros → Pre-chequeado` | Qué clientes operan con la modalidad **y cuántos días adelanta cada uno** |
| `RO_V_CASHFLOW_VENTAS_PRECHEQ` | La vista origen. La crea `sql/echeqs_prechequeado.sql` |
| `RO_T_CASHFLOW_ECHEQ_PRECHEQ_CLIENTE.DIAS_PRECHEQUEADO` | Los días, **por cliente** |
| `Echeqs::diasDeCliente()` · `Echeqs::fechaVentaEstimada()` | Resuelven el plazo y la fecha. Las usan la pantalla **y** el neteo |
| `Echeqs::ventaYaCobrada()` | Decide qué queda fuera del cashflow. La usan la pantalla **y** el neteo, y es una sola implementación a propósito |
| `Ventas::getNeteoPrechequeado()` | Reparte el importe contra el eje y descarta lo que queda afuera |

### Dos fechas, dos funciones distintas

> Esto **cambió**. Antes las dos cosas las hacía la fecha estimada de venta.

```
FECHA_VENTA_ESTIMADA = FECHA_CHEQUE − días del CLIENTE
```

| Fecha | Qué decide | Con qué |
| --- | --- | --- |
| `FECHA_VENTA_EST` | **QUÉ** se muestra y qué netea | `Echeqs::ventaYaCobrada()` |
| `FECHA_CHEQUE` | **DÓNDE** cae el importe | `Horizonte::ubicar()` |

El argumento viejo era que el neteo tenía que caer donde está la cobranza proyectada de esa venta. **Ese razonamiento quedó superado:** lo que interesa es cuándo entra la plata del cheque, y eso lo dice la fecha del cheque. La fecha teórica sigue existiendo, pero sólo como argumento del filtro.

**`FECHA_VENTA_EST` sigue viajando en la fila y sigue siendo columna visible**: es el dato que explica *por qué ese cheque está en la lista*, aunque ya no sea el que lo ubica. En la grilla la del cheque va primera y destacada y la estimada queda en gris, como informativa —el mismo idioma que *Importe Factura* en Cobranzas May—.

**La pantalla y el neteo se mueven juntos.** `EcheqsController` arma el eje de la sub-pestaña con `'FECHA_CHEQUE'` y `Ventas::repartirNeteo()` ubica con `$fila['FECHA_CHEQUE']`. Si sólo cambiara uno, el usuario tildaría un cheque en una columna y el tablero lo restaría en otra, **sin ninguna pantalla donde notarlo**. Es el mismo motivo por el que `ventaYaCobrada()` es una sola función.

El importe cae en el bucket diario de esa fecha, o en el mensual si quedó fuera del tramo diario: es la misma regla de `Horizonte::ubicar()` que usa el resto del módulo, no una copia.

### Los días son por cliente, y no hay valor global

Antes eran **uno solo para todos**: el parámetro `dias_prechequeado`. Cada cliente negocia su propio adelanto, así que un único número obliga a elegir cuál de todos queda bien calculado.

**Para qué sirven los días, ahora.** Ya **no corren el importe** a ninguna columna: el importe cae en la fecha del cheque, con días o sin ellos. Lo que los días deciden es **si ese cheque entra o no**. Un cliente que adelanta mucho tiene ventas teóricas más viejas, y las que ya pasaron salen del cashflow.

- **No hay respaldo global.** Un cliente en cero tiene su venta estimada en la propia fecha del cheque. Un respaldo sería peor que el cero: un cliente sin configurar heredaría un adelanto que nadie eligió para él, y en pantalla sería indistinguible de uno configurado.
- **Los días se piden en el alta.** Son parte de configurar al cliente, no un dato que se descubre después. Cero es una respuesta válida; que falte, no.
- **La pantalla deja ver los que quedaron en cero**, con la marca *sin desplazar*: si alguien esperaba una venta estimada distinta y en Echeqs ve las dos fechas iguales, el motivo es ése y tiene que poder encontrarlo.
- **La pantalla y el neteo resuelven el plazo con la MISMA función.** Si aplicaran plazos distintos, uno filtraría cheques que el otro no y no habría ninguna pantalla donde se notara. `tests/test_echeqs.php` cubre dos clientes con días distintos: el mismo cheque entra para uno y no para el otro.

> **`dias_prechequeado` quedó sin uso.** La fila **no se borró** de `RO_T_CASHFLOW_PARAMETROS` —queda el valor que alguien había cargado, por si hace falta reconstruir con qué número se proyectó en su momento—, pero `Parametros::RETIRADOS` la saca del listado, así que ya no aparece como campo editable en *Parámetros → Ventas*. Un campo que se puede tocar y que no cambia nada es peor que no tenerlo.

`RO_T_CASHFLOW_VENTAS_PRECHEQ` **ya no es el origen** y no tiene lector. Queda creada porque puede tener filas en algún ambiente. Ver `sql/ventas_proyeccion.sql` §6.

### Lo que cae ANTES del eje se descarta, y se descarta callado

**Es la excepción deliberada a la regla del módulo**, y el motivo es que acá no se descarta plata: **esa venta ya está cobrada**.

Con días de pre-chequeado la fecha estimada de venta puede quedar antes del inicio del eje. Si quedó ahí, la factura ya se emitió y el cheque ya entró; el motor de Ventas proyecta cobranza de ventas **futuras**, así que esa venta no está en ninguna columna de la proyección y **no hay nada de donde restarla**. Un neteo sin contrapartida no es plata que al tablero le falte: es plata que al tablero no le toca.

Por eso **no va a `fuera_horizonte` ni deja aviso**. `fuera_horizonte` tiene un significado preciso en el módulo —cuánta plata el tablero *debería* mostrar y no muestra, ver `README-cashflow.md`— y este importe no es eso. Es el mismo criterio con el que `CobElectronicos` trata lo ya acreditado. Un aviso por esto aparecería todos los días, sobre algo que ya pasó y sobre lo que no hay ninguna acción posible, y un aviso permanente tapa a los que sí piden hacer algo.

> Esto es un cambio de criterio respecto de una versión anterior, que lo informaba como `fuera_horizonte` con aviso. La regla *"nunca se descarta en silencio"* sigue en pie para lo que el tablero deja de mostrar; lo que cambió es la lectura de **este** caso, que no es uno de esos. El de más allá del eje sí lo es — ver abajo. La sub-pestaña **Echeqs → Venta Cobrada Anticipada** aplica la misma regla y **tampoco muestra** esos cheques, así que pantalla y neteo no se pueden desalinear.

**El corte es contra el primer día del eje**, no contra `hoy` escrito a mano. No alcanza con preguntarle a `Horizonte::ubicar()` si encontró columna: una fecha de los primeros días del mes **en curso** cae en la columna de ese mes, que existe pero no representa ningún día futuro y la pantalla ni siquiera la dibuja.

**La regla vive en una sola función: `Echeqs::ventaYaCobrada()`.** La llaman los dos lados —`Ventas::repartirNeteo()` para decidir qué netea, y `Echeqs::cruzarPrechequeado()` para decidir qué muestra la sub-pestaña—. Si fueran dos implementaciones, la pantalla podría mostrar un cheque que el tablero no netea, y el usuario tildaría algo que no mueve nada **sin ninguna pantalla donde notarlo**.

El corte es un parámetro, no `hoy` escrito adentro: la sub-pestaña no le pasa nada y corta contra hoy; el neteo le pasa el **primer día del eje**. En la práctica son el mismo día —`Horizonte` arma el tramo diario empezando en hoy—, pero el neteo tiene que cortar contra el eje que efectivamente recibió y no contra el reloj.

### Lo que cae DESPUÉS del eje SÍ se avisa, y es el caso contrario

> Esto **cambió**, y no por cambiar de opinión: **es un caso nuevo que nació con el cambio de criterio.**

Antes, si el importe se ubicaba más allá del último mes, la venta teórica también estaba afuera —eran la misma fecha—: su cobranza proyectada no estaba en el cuadro, así que no había columna que netear y se descartaba callado por el mismo motivo que lo anterior al eje.

Ubicando por la fecha del cheque eso deja de valer. **La fecha del cheque es siempre posterior o igual a la teórica**, así que un cheque puede tener **su venta adentro del eje —con su cobranza proyectada dibujada— y su fecha afuera**. Ahí el cuadro muestra una cobranza que ese importe debería restar, y la resta desaparece: eso sí es plata que el tablero debería mostrar y no muestra, que es exactamente lo que `fuera_horizonte` significa en este módulo.

Por eso `repartirNeteo()` lo cuenta en `fuera_horizonte` y `avisosNeteo()` deja aviso. **Sólo cuando el importe es mayor que cero**, porque un aviso permanente tapa a los que sí piden hacer algo.

| | Antes del eje | Después del eje |
| --- | --- | --- |
| Qué es | venta ya facturada y cobrada | venta proyectada acá adentro, cheque afuera |
| Va a `fuera_horizonte` | no | **sí** |
| Avisa | no | **sí**, si hay importe |

**Medido sobre la cartera real al hacer el cambio: `0,00`.** El cheque más lejano vence el 18/03/2027 y el eje llega al 31/08/2027 —166 días de margen—, y el corrimiento máximo entre las dos fechas es de 60 días, el `DIAS_PRECHEQUEADO` más grande del maestro (19 clientes: 1 en 30, 3 en 35, 11 en 45, 4 en 60). El aviso no se ve hoy: está para el día que un cheque lejano o un `horizonte_meses` más corto lo despierten.

La sub-pestaña informa el mismo importe por su lado —`EjeVista::armar()` ya contaba `fuera_horizonte` y avisaba—, así que **las dos pantallas dicen lo mismo**.

### Lo que el neteo también avisa

- **Lo que no se pudo imputar a un canal.** El canal sale del prefijo del código de cliente (`Echeqs::canalDeCliente()`): `F` es Franquicias y `L` es Locales. Si algún importe no mapea, se resta sólo del total y el aviso dice por cuánta plata la fila total y su apertura por canal no reconcilian.

### Netea lo tildado, sin mirar el estado del cheque

**Es una decisión de negocio, y es la respuesta al punto que quedaba abierto.** Quien tilda es quien sabe si esa venta está prepagada, y para eso está la sub-pestaña. El módulo no filtra por estado.

Importa porque el dato podía llevar a la conclusión contraria. Verificado contra la base, `'A'` en `dbo.SBA14` es **aplicado**: el cheque ya salió de cartera. Todos los `'A'` con fecha futura traen `FECHA_SAL` y `T_COMP_SAL` informados, y se reparten en dos casos:

| `T_COMP_SAL` | Qué pasó | Cuántos |
| --- | --- | --- |
| `O/P` | Endosado a un proveedor en una orden de pago | 187 de 250 |
| `BDE` | Depositado en una boleta de depósito | 60 de 250 |

Ninguno de los dos está hoy reflejado en Saldos, y la sub-pestaña de cartera tampoco los muestra —sólo trae `'C'`—, así que **el tablero resta ese importe de la cobranza sin haberlo sumado en ninguna fila**. Es el precio de netear por tilde y no por estado, y es lo que se decidió: los cheques pre-chequeados están típicamente en `'A'` justamente porque se reciben y se usan antes de facturar, así que filtrar por `'C'` dejaría afuera casi todo el neteo.

Lo único que se excluye es `'X'` y `'R'` —anulado y rechazado—, que no son plata. Eso lo hace la vista origen, así que un cheque que se rechaza deja de netear **solo**, sin que nadie tenga que destildarlo.

El dato sigue a la vista para poder auditarlo: el pie de la sub-pestaña muestra los marcados **abiertos por estado** y hay dos tarjetas separando *marcados en cartera* de *marcados fuera de cartera*. No genera aviso en el tablero: es el caso normal, y un aviso que aparece siempre deja de leerse.

**Los totales de arriba de cada fecha también suman sólo los marcados**, igual que la grilla del pie: son la misma cuenta (`Js/eje-totales.js`, ver `README-cashflow.md`). Un cheque destildado se sigue viendo en su fila, pero no suma ni abajo ni arriba, porque no netea.

---

## Relación con las cobranzas reales

*Cobranzas FR* y *Cobranzas May* traen cobranza **real** de facturas ya emitidas. Este módulo proyecta cobranza de **ventas futuras**. La pestaña muestra sólo la proyectada y no cruza nada con las otras.

En el cashflow consolidado:

```
Cobranza total = cobranza real (facturas emitidas) + cobranza sobre ventas estimadas
```

---

## Usuario

Las tablas llevan el esquema de auditoría del módulo —`USUARIO_ALTA` / `FECHA_ALTA`, `USUARIO_MODIF` / `FECHA_MODIF` y, donde hay baja lógica, `USUARIO_BAJA` / `FECHA_BAJA`—, y cada escritura graba el `username` del padrón de Gestionusuarios, que resuelve el servidor: el navegador no manda usuario ni fecha. Sin usuario la escritura se rechaza (401) y sin el permiso de edición de la pestaña, también (403). Las filas de antes de `feature/cashflow-auditoria-usuario` quedan con lo que tenían —casi siempre sin usuario— y la pantalla lo dice. Ver *Auditoría y permisos de escritura* en `README-cashflow.md`.

---

## Archivos

```
sql/ventas_proyeccion.sql               DDL de las 6 tablas + semillas
sql/SJ_CASHFLOW_VENTAS_HIST.sql         Stored procedure del histórico
sql/RO_V_DOLAR_OFICIAL_BCRA.sql         Vista del T/C de cierre por mes
sql/cashflow_ventas_mix_nodo.sql        El árbol del mix de cobro y la migración del plano
sql/cashflow_estructura_costos_cobro.sql  La fila "Costos de cobro" del tablero
cashflow/Class/Ventas.php               Motor de proyección
cashflow/Class/MixCobro.php             El árbol del mix: la regla, el editor y su escritura
cashflow/Class/Cotizacion.php           Tipo de cambio — punto de acceso del cashflow
cashflow/Class/Parametros.php           Parámetros (y la lectura del mix plano, como respaldo)
cashflow/Controller/VentasController.php
cashflow/Controller/ParametrosController.php
cashflow/Tabs/ventas.php                Reemplaza el placeholder
cashflow/Tabs/parametros.php
cashflow/Js/Ingresos-Ventas.js
cashflow/Js/Parametros.js
cashflow/Css/Ingresos-Ventas.css
cashflow/Css/Parametros.css
cashflow/Css/main.css                   Modificado: sticky header compartido
cashflow/Js/main.js                     Modificado: helper de sticky header + título
cashflow/Controller/TabController.php   Modificado: 'parametros' en $validTabs
cashflow/Components/sidebar.php         Modificado: item Parámetros
cashflow/Tabs/crono_nacionalizacion.php Modificado: clase .tabla-temporal
cashflow/Tabs/proveedores_exterior.php  Modificado: clase .tabla-temporal
```

---

## Header fijo y columnas fijas

La clase `.tabla-temporal` (en `Css/main.css`, **no duplicada por pestaña**) acota la altura del contenedor para que `position: sticky` tenga contra qué pegarse, fija las dos filas del `thead` y el `tfoot` de totales abajo.

`ajustarStickyHeaders()` en `Js/main.js` mide el alto **real** de la primera fila del `thead` y lo publica como `--thead-row1-height`: las celdas con `rowspan="2"` abarcan las dos filas, así que un `offsetHeight` directo daría un valor falso. Un `MutationObserver` sobre `#tabContent` lo re-mide cuando las tablas se generan por AJAX, de modo que no hubo que tocar el JS de cada pestaña.

Las **columnas fijas en horizontal** ya no están cableadas: se eligen desde la pantalla y las resuelve `Js/columnas-fijas.js`. Ver `README-cashflow.md`.

En esta pestaña el selector va sólo en la tabla **Cobranza Proyectada**, con `Concepto` fija por defecto: es el nombre del nodo con su sangría, lo que dice de qué rama es cada número. Antes eran dos columnas, Canal y Medio de Pago; con el árbol el canal es una fila más, y la preferencia guardada usa una clave nueva para no aplicar los índices de la tabla vieja. Las otras tablas de Ventas (tendencias, proyección por mes, venta acumulada, balance, control de facturación) tienen cuatro o cinco columnas y **no scrollean a lo ancho**: un selector ahí sería un control que no resuelve nada. Conservan su primera columna fija, que es el default automático.

Una diferencia visible: las tres filas del pie de *Cobranza Proyectada* tienen su rótulo en una celda con `colspan="4"` sobre todo el bloque descriptivo, y una celda así **no se fija**. Al scrollear, el rótulo se va con el scroll y sigue pegado abajo, en vez de estacionar una banda de cuatro columnas de ancho encima de los importes.

---

## Relación con el módulo Cashflow

Ventas es uno de los proveedores de datos del tablero de Cashflow. Expone cuatro series a través del contrato común, más la apertura por canal de `COBRANZA`, `COSTO_COBRO` y `VENTA`:

| Serie | Qué es |
| --- | --- |
| `COBRANZA` | La caja: cobranza estimada sobre ventas futuras. **Bruta**: antes del costo de cobro y del neteo |
| `COSTO_COBRO` | El costo de cobro —comisiones de marketplace y procesadora, tasas de cuotas—, **en negativo**. Es una fila propia del tablero |
| `NETEO_PRECHEQUEADO` | El neteo de cheques adelantados, **en negativo**. Es una fila propia del tablero |
| `VENTA` | La venta proyectada con IVA. **No es caja**: en el tablero es una fila informativa que no entra en ninguna suma |

`COBRANZA_<CANAL>` y `COSTO_COBRO_<CANAL>` —también en negativo— son la apertura por canal, y el registro las declara como `componentes` de su total. Franquicias y Mayoristas hoy dan costo cero: su mix no tiene costos cargados.

Todas salen de una única llamada a `proyectarCobranzas()`, que resuelve venta, cobranza, costo y neteo en la misma pasada.

### El neteo y el costo de cobro son filas, no descuentos dentro de la cobranza

`COBRANZA` y las cuatro `COBRANZA_<CANAL>` salían **netas**: el neteo se restaba adentro de cada serie. Ahora salen **brutas**, y lo que se resta tiene su propia fila: el neteo de cheques adelantados y, con el mix en árbol, el costo de cobro.

El motivo es el mismo para los dos: son información que el tablero tiene que mostrar, no una corrección que tenga que esconder. Restado adentro de la cobranza, la única forma de saber cuánto se había neteado —o cuánto se quedan las procesadoras— era abrir otra pantalla; ahora el cuadro dice la cobranza proyectada, cuánto de eso ya estaba cobrado, cuánto cuesta cobrarla, y el neto.

> **Restar adentro y tener además la fila descontaría DOS VECES.** Si alguien vuelve a netear —o a restar el costo de cobro— adentro de `COBRANZA` o de las series por canal con su fila activa, el tablero muestra de menos exactamente ese importe, **y no hay ninguna validación que lo detecte**: las dos series son legítimas por separado. La regla es una sola: cada cosa se resta en un solo lugar, y ese lugar es su fila. Está escrito en el encabezado de `Class/Providers/VentasProvider.php`.

- **El signo se invierte en un solo lugar**, `VentasProvider::enNegativo()`. Las dos filas son de `TIPO = 'INGRESO'` y el motor suma los ingresos: un ingreso negativo resta. Ponerlas como `EGRESO` le daría signo −1 a un importe que ya viene negativo y terminarían *sumando*. Invertir mal el signo es invisible —el cuadro sigue dando un número razonable—, y por eso la inversión es una función estática con pruebas propias.
- **Las dos computan** (`COMPUTA = 1`): *Total Ventas* es la cobranza **neta**, la que efectivamente entra.
- **El neteo lleva el total de TODOS los canales**, no sólo Franquicias. Hoy todo el neteo es de franquicias porque son las que operan con pre-chequeado, pero eso es un hecho del padrón de clientes y no una regla del módulo: en cuanto un mayorista entregue cheques por adelantado, su neteo entra en la misma fila **sin tocar código**. El costo de cobro, igual: la fila usa `COSTO_COBRO`, el total de los cuatro canales.
- **Ninguna de las dos es componente de `COBRANZA`.** El registro declara en `componentes` qué series son apertura de qué total, y el validador rechaza que convivan. Estas dos no están ahí a propósito: no son una apertura de la cobranza sino filas que conviven con ella, y declararlas componentes haría que el validador rechace la combinación normal del tablero.
- **La pestaña Ventas muestra la bruta y, al lado, la neta de costos.** Las columnas arrancan en Bruto —lo que muestra la fila del canal en el tablero— y el pie muestra siempre bruta, costos y neta, con los tres importes calculados en el servidor. **El neteo no se muestra ahí**: el pie tenía una fila de neteo y se fue al tablero, porque eran dos lugares que tenían que dar lo mismo sin ninguna garantía de que lo hicieran. `VentasController` ya no expone `getNeteoPrechequeado`; el circuito sigue vivo y lo consume el proveedor del tablero.

La fila del neteo la crea `sql/cashflow_estructura_neteo_prechequeado.sql`, y la del costo de cobro `sql/cashflow_estructura_costos_cobro.sql`, que la ubica **entre Ecommerce y Total Ventas** leyendo los órdenes reales de la base (en `central`, 50 y 60: la fila entra en 55). **Si cualquiera de los dos no se corre, el tablero muestra la cobranza sin restar eso y no avisa**, porque cada serie por separado es correcta.

### Por qué las filas de Ventas no se agrupan en un renglón

Se evaluó dibujar las filas por canal, el neteo y el costo de cobro en un solo renglón que se abre, con el mecanismo de filas agrupadas de `sql/cashflow_estructura_grupos.sql`, y **se descartó**. Un grupo tiene que ser una corrida de filas consecutivas, y el neteo está entre Franquicias y Mayoristas, así que el grupo tendría que llevar **todas** las filas de ingreso de la sección: cerrado, el renglón daría exactamente *Total Ventas*, y el cuadro mostraría el mismo número dos veces, uno abajo del otro. La fila de costos queda suelta debajo de Ecommerce, y *Total Ventas* es la cobranza neta. El mecanismo de grupos no se tocó.

`proyectarVentas()` y `proyectarCobranzas()` aceptan un `Horizonte` opcional. La pestaña Ventas no lo pasa y arma el suyo desde los parámetros; el Cashflow **sí** lo pasa, para que la serie caiga exactamente en las mismas columnas sobre las que consolida el resto del tablero.

Ver `README-cashflow.md`.
