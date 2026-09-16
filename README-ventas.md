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
| `RO_T_CASHFLOW_VENTAS_MIX` | Mix de cobro y plazos (9 filas de semilla) |
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

---

## Modelo de cálculo

```
VentaNetaProyectada(M) = VentaNetaReal(M, año anterior) × (1 + índice_M)
VentaConIVA(M)         = VentaNetaProyectada(M) × (1 + alícuota_iva)
VentaCanal(c, M)       = VentaConIVA(M) × %Participación(c, M)
VentaDiaria(c, d)      = VentaCanal(c, M(d)) / (días_del_mes − feriados_comercio)
Monto(c, mp, d)        = VentaDiaria(c, d) × %Mix(c, mp)
FechaAcreditación      = d + DíasAcreditación(c, mp)
                         → corrida al PRÓXIMO día bancario hábil si cae en no hábil
```

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

Ningún valor de negocio está escrito en el código. Todo sale de `RO_T_CASHFLOW_PARAMETROS` y `RO_T_CASHFLOW_VENTAS_MIX`, y se edita desde la pestaña **Parámetros**.

### Agrupados por módulo

La pestaña se organiza en **sub-pestañas, una por módulo**, para que se entienda de un vistazo qué afecta cada valor. Hoy existe sólo **Ventas**; la columna `MODULO` de `RO_T_CASHFLOW_PARAMETROS` es la que atribuye cada parámetro a su pestaña.

> Si la tabla se creó con una versión anterior del script, la columna `MODULO` no existe todavía. Corré **`sql/migracion_parametros_modulo.sql`** (idempotente, no toca ningún valor ya editado). Mientras no lo hagas, la pestaña **igual funciona**: muestra todos los parámetros como Ventas y avisa arriba que falta la migración.

**Para agregar un módulo** hacen falta tres cosas:

1. Cargar sus parámetros con ese `MODULO` en `RO_T_CASHFLOW_PARAMETROS`.
2. Declararlo en `Parametros::$modulos` (nombre, ícono, descripción y qué secciones muestra).
3. Agregar el `<li>` y el `tab-pane` en `Tabs/parametros.php`.

Las secciones disponibles son `generales` (clave/valor del grupo `GENERAL`), `respaldo` (grupo `RESPALDO`) y `mix` (la tabla `RO_T_CASHFLOW_VENTAS_MIX`).

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

### Mix de cobro: alta e inhabilitación

Los medios de pago se administran desde **Parámetros → Mix de Cobro y Plazos**:

- **Agregar medio**: canal, nombre y días de acreditación. Entra **inhabilitado y en 0%**, para no romper el 100% del canal en el momento del alta. Para usarlo hay que activarlo y reacomodar los porcentajes.
- **Inhabilitar**: el switch de la columna *Activo*. Un medio inhabilitado **no se usa en la proyección y no aparece en la tabla de cobranza**, pero sigue visible en Parámetros para poder reactivarlo. No se borra el dato.

Reglas que valida el sistema (en el front y de nuevo en el servidor):

- Los medios **activos** de cada canal deben sumar 100%. Los inhabilitados no suman, sin importar qué porcentaje tengan guardado.
- Un canal no puede quedarse **sin ningún medio activo**: su venta no se convertiría en cobranza y el importe desaparecería del cashflow.
- No se puede repetir el mismo medio de pago dentro de un canal.

Mix de cobro inicial (cada canal suma 100%):

| Canal | Medio de pago | % Mix | Días acreditación |
| --- | --- | ---: | ---: |
| Locales | Cash | 10% | 1 |
| Locales | Tarjeta | 90% | 2 |
| Locales | Go Cuotas | 0% | 10 |
| Franquicias | Transferencia | 3% | 30 |
| Franquicias | Echeq | 97% | 40 |
| Mayoristas | Cash | 0% | 1 |
| Mayoristas | Echeq | 100% | 60 |
| Ecommerce | Tarjeta | 100% | 2 |
| Ecommerce | Go Cuotas | 0% | 10 |

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
| `Ventas::getNeteoPrechequeado()` | Reparte el importe contra el eje y descarta lo que queda afuera |

```
FECHA_VENTA_ESTIMADA = FECHA_CHEQUE − días del CLIENTE
```

El importe cae en el bucket diario de esa fecha, o en el mensual si quedó fuera del tramo diario: es la misma regla de `Horizonte::ubicar()` que usa el resto del módulo, no una copia.

### Los días son por cliente, y no hay valor global

Antes eran **uno solo para todos**: el parámetro `dias_prechequeado`. Cada cliente negocia su propio adelanto, así que un único número obliga a elegir cuál de todos queda bien calculado.

- **No hay respaldo global.** Un cliente en cero **no desplaza nada** y su cheque queda en su propia fecha. Un respaldo sería peor que el cero: un cliente sin configurar heredaría un desplazamiento que nadie eligió para él, y en pantalla sería indistinguible de uno configurado.
- **Los días se piden en el alta.** Son parte de configurar al cliente, no un dato que se descubre después. Cero es una respuesta válida; que falte, no.
- **La pantalla deja ver los que quedaron en cero**, con la marca *sin desplazar*: si alguien esperaba un corrimiento y en Echeqs ve el cheque en su propia fecha, el motivo es ése y tiene que poder encontrarlo.
- **La pantalla y el neteo resuelven el plazo con la MISMA función.** Si aplicaran plazos distintos, el tablero dejaría de cerrar y no habría ninguna pantalla donde se notara. `tests/test_echeqs.php` cubre dos clientes con días distintos y uno en cero.

> **`dias_prechequeado` quedó sin uso.** La fila **no se borró** de `RO_T_CASHFLOW_PARAMETROS` —queda el valor que alguien había cargado, por si hace falta reconstruir con qué número se proyectó en su momento—, pero `Parametros::RETIRADOS` la saca del listado, así que ya no aparece como campo editable en *Parámetros → Ventas*. Un campo que se puede tocar y que no cambia nada es peor que no tenerlo.

`RO_T_CASHFLOW_VENTAS_PRECHEQ` **ya no es el origen** y no tiene lector. Queda creada porque puede tener filas en algún ambiente. Ver `sql/ventas_proyeccion.sql` §6.

### Lo que cae fuera del eje se descarta, y se descarta callado

**Es la excepción deliberada a la regla del módulo**, y el motivo es que acá no se descarta plata: **esa venta ya está cobrada**.

Con días de pre-chequeado la fecha estimada de venta puede quedar antes del inicio del eje. Si quedó ahí, la factura ya se emitió y el cheque ya entró; el motor de Ventas proyecta cobranza de ventas **futuras**, así que esa venta no está en ninguna columna de la proyección y **no hay nada de donde restarla**. Un neteo sin contrapartida no es plata que al tablero le falte: es plata que al tablero no le toca.

Por eso **no va a `fuera_horizonte` ni deja aviso**. `fuera_horizonte` tiene un significado preciso en el módulo —cuánta plata el tablero *debería* mostrar y no muestra, ver `README-cashflow.md`— y este importe no es eso. Es el mismo criterio con el que `CobElectronicos` trata lo ya acreditado. Un aviso por esto aparecería todos los días, sobre algo que ya pasó y sobre lo que no hay ninguna acción posible, y un aviso permanente tapa a los que sí piden hacer algo.

> Esto es un cambio de criterio respecto de la versión anterior, que lo informaba como `fuera_horizonte` con aviso. La regla *"nunca se descarta en silencio"* sigue en pie para lo que el tablero deja de mostrar; lo que cambió es la lectura de este caso, que no es uno de esos. La sub-pestaña **Echeqs → Venta Cobrada Anticipada** aplica la misma regla y **tampoco muestra** esos cheques, así que pantalla y neteo no se pueden desalinear.

**El corte es contra el primer día del eje**, no contra `hoy` escrito a mano. No alcanza con preguntarle a `Horizonte::ubicar()` si encontró columna: una fecha de los primeros días del mes **en curso** cae en la columna de ese mes, que existe pero no representa ningún día futuro y la pantalla ni siquiera la dibuja.

**Lo posterior al horizonte se descarta igual y por lo mismo:** si la venta cae más allá del último mes del eje, su cobranza proyectada tampoco está en el cuadro.

### Lo único que el neteo sigue avisando

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

---

## Relación con las cobranzas reales

*Cobranzas FR* y *Cobranzas May* traen cobranza **real** de facturas ya emitidas. Este módulo proyecta cobranza de **ventas futuras**. La pestaña muestra sólo la proyectada y no cruza nada con las otras.

En el cashflow consolidado:

```
Cobranza total = cobranza real (facturas emitidas) + cobranza sobre ventas estimadas
```

---

## Usuario

Todavía no hay login. Todas las tablas tienen `USUARIO VARCHAR(50) NULL` y hoy se graba `NULL`. Los métodos de guardado ya reciben `$usuario` y los controllers lo resuelven con `usuarioActual()`, que lee `$_SESSION['usuario']`. Cuando exista el login, alcanza con poblar esa variable de sesión.

---

## Archivos

```
sql/ventas_proyeccion.sql               DDL de las 6 tablas + semillas
sql/SJ_CASHFLOW_VENTAS_HIST.sql         Stored procedure del histórico
sql/RO_V_DOLAR_OFICIAL_BCRA.sql         Vista del T/C de cierre por mes
cashflow/Class/Ventas.php               Motor de proyección
cashflow/Class/Cotizacion.php           Tipo de cambio — punto de acceso del cashflow
cashflow/Class/Parametros.php           Parámetros y mix de cobro
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

En esta pestaña el selector va sólo en la tabla **Cobranza Proyectada**, con `Canal` y `Medio de Pago` fijas por defecto — que es exactamente lo que hacía el CSS viejo con `:first-child` y `.col-medio`. Las otras tablas de Ventas (tendencias, proyección por mes, venta acumulada, balance, control de facturación) tienen cuatro o cinco columnas y **no scrollean a lo ancho**: un selector ahí sería un control que no resuelve nada. Conservan su primera columna fija, que es el default automático.

Una diferencia visible: el pie de *Cobranza Proyectada* tiene sus rótulos en celdas con `colspan="4"` sobre todo el bloque descriptivo, y una celda así **no se fija**. Antes se fijaba —era `tfoot td:first-child`— y al scrollear estacionaba una banda de cuatro columnas de ancho encima de los importes. Ahora el rótulo se va con el scroll y sigue pegado abajo, que es lo que se quería ver.

---

## Relación con el módulo Cashflow

Ventas es uno de los proveedores de datos del tablero de Cashflow. Expone dos series a través del contrato común:

| Serie | Qué es |
| --- | --- |
| `COBRANZA` | La caja: cobranza estimada sobre ventas futuras, ya neta del neteo de cheques adelantados |
| `VENTA` | La venta proyectada con IVA. **No es caja**: en el tablero es una fila informativa que no entra en ninguna suma |

Las dos salen de una única llamada a `proyectarCobranzas()`, que resuelve venta y cobranza en la misma pasada.

`proyectarVentas()` y `proyectarCobranzas()` aceptan un `Horizonte` opcional. La pestaña Ventas no lo pasa y arma el suyo desde los parámetros; el Cashflow **sí** lo pasa, para que la serie caiga exactamente en las mismas columnas sobre las que consolida el resto del tablero.

Ver `README-cashflow.md`.
