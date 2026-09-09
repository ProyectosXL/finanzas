# Módulo Saldos — disponible inicial y caja de locales

Reemplaza el placeholder de la pestaña **Saldos** y alimenta las dos filas del tablero que hasta ahora rendían cero: *Saldo Inicial* y *Caja Locales*.

Rama: `feature/saldos`

---

## La idea en una línea

**Una carga es un evento fechado, no un `UPDATE`.** Los saldos no se pisan: cada carga inserta un juego nuevo de filas y la pantalla muestra, para cada dato, su último valor conocido **con la fecha en que se cargó**.

```
Pestaña 1 "Saldos"          Pestaña 2 "Saldos Locales"
efectivo central (SBA05)    caja de locales propios (Tango)
+ bancos (manual → API)     − reserva de caja
+ Mercado Pago (manual)     = neto a depositar
        │                            │
        ▼                            ▼
  serie DISPONIBLE            serie DEPOSITOS
        │                            │
        └──── SaldosProvider ────────┘
                     │
              Cashflow (motor)
        Saldo Inicial      Caja Locales
```

---

## Ejecución de los scripts

Contra `central`:

```sql
-- sql/cashflow_saldos.sql
```

Crea las cinco tablas, siembra la cuenta de efectivo de tesorería y carga los dos parámetros del módulo. **Es reejecutable**: las tablas se crean sólo si no existen y las semillas entran por `MERGE WHEN NOT MATCHED`, así que una segunda corrida no duplica nada ni pisa un valor ya editado. Verificado corriéndolo dos veces.

No depende de los otros scripts, pero las filas del tablero que alimenta las creó `sql/cashflow_estructura_disponibilidades.sql`.

Si el script **no se corrió**, la pantalla no falla: muestra un aviso y el tablero deja las dos filas en cero, igual que hace hoy con la estructura.

---

## Pestaña 1 — Saldos

Alimenta la fila **Saldo Inicial** (`DISPONIBLE`). El saldo del Cashflow es la suma de tres cosas:

| # | Qué | De dónde sale | Origen del dato |
| --- | --- | --- | --- |
| 1 | Efectivo de tesorería de casa central | `SBA05` en `central` | `CONSULTA` |
| 2 | Saldos bancarios | Carga manual hoy, API de Interbanking después | `MANUAL` → `API` |
| 3 | Mercado Pago | Carga manual | `MANUAL` |

### El efectivo central es un saldo puntual

```sql
SELECT COALESCE(SUM(CASE WHEN D_H = 'D' THEN MONTO ELSE -MONTO END), 0.00) AS SALDO
FROM SBA05
WHERE COD_CTA = ?
```

Devuelve **un solo número**: el acumulado de movimientos de esa cuenta hasta el momento en que se pregunta. No tiene fecha propia ni histórico. Por eso la fecha del dato es la de la consulta, y **el histórico lo construye este módulo**, guardando el valor en cada carga.

`COD_CTA` sale del parámetro `saldos_cta_tesoreria` y va **parametrizado** en la consulta: es un número de cuenta del plan contable y cambiarlo no puede requerir tocar código.

Ese saldo **no se acepta del navegador aunque esté en la pantalla**: al guardar, la clase lo vuelve a leer de `SBA05`. Es un dato del sistema, y tomarlo del cliente permitiría guardar cualquier cosa como si fuera lo que dice la contabilidad. Si la consulta falla en ese momento, la carga **no se guarda**: guardarla dejaría ese saldo en cero y el disponible quedaría informado de menos.

### El formulario manual se mantiene aunque entre la API

Es respaldo ante una falla de la integración. La columna *Origen* de cada fila dice si el saldo lo trajo la API, lo tipeó una persona o lo resolvió una consulta.

### Monedas: dos totales, sin mezclar

Las cuentas pueden estar en pesos o en dólares. **La pantalla no convierte nada**: cierra con un total en pesos y otro en dólares, cada uno en su moneda.

La conversión existe **sólo** para lo que el proveedor le entrega al Cashflow, porque el contrato de `CashflowProvider` exige pesos. La hace `SaldosProvider` con `Class/Cotizacion.php`, que es el único punto de acceso al tipo de cambio del cashflow: valúa cada saldo con la cotización de **cierre del mes** de su fecha.

Un mes **sin cotización** no vale cero: esos dólares **no entran al tablero** y se avisa el importe en su moneda. Un cero se leería como "no hay dólares". Si la vista `RO_V_DOLAR_OFICIAL_BCRA` no existe en el entorno, pasa lo mismo y la pestaña sigue funcionando.

### Cada fila muestra su propia fecha de carga

Es la regla transversal del relevamiento y está en el modelo, no calculada a ojo. La pantalla no muestra "las filas de la última carga" sino **el último saldo de cada cuenta**, con la fecha de la carga en la que se registró. La diferencia importa cuando alguien da de alta una cuenta después de la última carga o cuando una carga quedó incompleta: con el otro criterio esas cuentas desaparecerían del cuadro o se verían en cero.

Una cuenta que **nunca se cargó** dice `sin cargar`, no `0`. No es lo mismo.

---

## Pestaña 2 — Saldos Locales

Alimenta la fila **Caja Locales** (`DEPOSITOS`). Sale de una consulta contra el servidor `locales` que devuelve el último saldo de caja por sucursal y cuenta de tesorería, filtrando `CANAL = 'PROPIOS'` y `HABILITADO = 1`.

| Columna | Qué es | Editable |
| --- | --- | --- |
| Local | `NRO_SUCURSAL` + `DESC_SUCURSAL` | no |
| Saldo en caja | `SALDO_MONEDA` de la consulta | no |
| Fecha del saldo | `FECHA` de la consulta | no |
| Gestión | `Deposita` / `Envía` | **sí** |
| Reserva de caja | Mínimo que la sucursal debe conservar | **sí** |
| Neto a depositar | Saldo − reserva | no |
| Aporta al cashflow | Lo que efectivamente entra a la serie | no |

> Los nombres son los del relevamiento **corregidos**: los del Excel original no describían lo que contienen.

*Neto a depositar* y *Aporta al cashflow* son dos columnas y no una a propósito: es lo que hace visible **por qué** un neto de −70.000 aporta cero en lugar de restar.

### Las tres reglas

Están en `Saldos::armarSaldosLocales()`, que es un helper puro, y las tres tienen prueba.

1. **Sólo las sucursales en `Deposita` aportan.** Las que están en `Envía` se muestran en la pantalla —resaltadas— pero no entran a la serie: su efectivo no llega al banco por esta vía, y sumarlo sería contar plata que el tablero nunca va a ver acreditada.

2. **El neto es saldo menos reserva, sin ningún ajuste impositivo.**

3. **Un neto negativo aporta cero, no negativo.** Es el caso `40 FLORES 1` del relevamiento. Que la caja esté por debajo de la reserva no significa que la sucursal le saque plata al banco: significa que no manda nada. El neto negativo igual se muestra, con aviso, porque es información de la sucursal.

### Por qué no hay impuestos

El relevamiento menciona 0,6 % de impuesto al débito y 4 % de IIBB. **Esa parte quedó sin efecto y no está cableada ni "por las dudas".**

Existía porque en el Excel las cajas se actualizaban una vez por semana, así que había que estimar cuánto se iba a depositar y descontarle los impuestos a mano. Acá el saldo se lee todos los días y **el importe realmente acreditado, ya neto, aparece por sí solo en el saldo bancario de la Pestaña 1**. Calcularlo de nuevo sería estimar un dato que el sistema ya trae medido, y además lo contaría dos veces.

### La fecha de imputación

**Es la fecha del saldo que devuelve la consulta, sin corrimientos.** No hay regla de día de semana ni tratamiento de feriados: cuando la sucursal deposita, el movimiento queda registrado en Tango, y como la consulta corre todos los días el dato se actualiza solo. Un saldo de domingo se imputa el domingo y no se mueve al lunes.

### Un saldo con fecha anterior a hoy se imputa en la primera columna

El eje del tablero arranca hoy, y en la práctica **el último saldo que Tango tiene registrado es el de ayer**: la consulta no devuelve depósitos, devuelve el **saldo de caja** de cada local. Esa plata sigue en el cajón y todavía no llegó al banco, así que se imputa en la apertura del horizonte, con un aviso que dice de qué fecha es el saldo.

Descartarla mostraba la fila en cero justo cuando había millones para depositar, y el importe **no aparecía en ningún otro lado del tablero**: el saldo bancario de la Pestaña 1 recién lo va a mostrar cuando se acredite.

> **Reubicar no es el corrimiento que el relevamiento prohíbe.** Lo prohibido es mover una fecha que **sí** cae dentro del eje a otra por día hábil o feriado, y eso no se hace en ninguna de las dos series. Acá se trata una fecha que **no tiene columna** porque ya pasó. Es la misma regla que aplica el disponible inicial, y vive en un solo lugar: `Saldos::destinoEnEje()`. Las dos series describen **plata que existe ahora** —un saldo bancario, el efectivo de un cajón—, no movimientos ya ocurridos, así que una fecha pasada significa "esto ya es cierto hoy".

Una fecha **posterior** al eje sí queda `fuera_horizonte` y se informa: ésa es una fecha que el horizonte no cubre, no un dato que ya es cierto.

### Una fila por sucursal, no por cuenta

La consulta devuelve una fila por `(sucursal, cuenta de tesorería)`. La reserva, en cambio, es un mínimo **de la sucursal**: con dos cuentas y una reserva, restarla a cada una la descontaría dos veces. Así que los saldos se suman por sucursal, la columna `CUENTAS` dice cuántas se sumaron y `COD_CTA_CUENTA_TESORERIA` guarda los códigos separados por coma, para que la suma sea auditable.

---

## Modelo de datos

Cinco tablas, prefijo `RO_T_CASHFLOW_SALDOS_`. Cumplen tres propiedades:

| Propiedad | Cómo |
| --- | --- |
| **El histórico no se pisa** | El detalle cuelga de `ID_CARGA` y no tiene clave `(cuenta, fecha)` que se sobrescriba |
| **"Última carga" tiene respuesta única** | La resuelve la cabecera, con desempate por `ID` |
| **Los campos de la API existen desde el día uno** | Creados y en `NULL` mientras la carga sea manual |

| Tabla | Qué guarda |
| --- | --- |
| `RO_T_CASHFLOW_SALDOS_CUENTA` | Catálogo de cuentas (parámetro): tipo, moneda, origen y los siete campos de `/accounts` |
| `RO_T_CASHFLOW_SALDOS_CARGA` | Cabecera de cada carga: tipo, fecha y hora, usuario, origen, observaciones |
| `RO_T_CASHFLOW_SALDOS_DETALLE` | Histórico de saldos por cuenta y fecha, con los cinco `balances` y el `message` |
| `RO_T_CASHFLOW_SALDOS_SUCURSAL` | Gestión y reserva por local (parámetro) |
| `RO_T_CASHFLOW_SALDOS_LOCAL` | Histórico de la caja de los locales, con la gestión y la reserva **efectivas** |

### Por qué hay una cabecera de carga

Es lo que convierte *"la última carga"* en **una fila** y no en un `MAX(FECHA)`:

```sql
SELECT TOP 1 ID FROM RO_T_CASHFLOW_SALDOS_CARGA
WHERE TIPO = ? AND ACTIVO = 1
ORDER BY FECHA_CARGA DESC, ID DESC
```

Con `MAX(FECHA)` sobre el detalle, **dos cargas el mismo día** —que es lo que pasa cuando alguien se equivoca y vuelve a cargar— devolverían las filas de las dos mezcladas. El desempate por `ID` (un `IDENTITY`) es total y siempre gana la última insertada. Está probado, incluso con la fecha truncada a día.

`TIPO` (`SALDOS` / `LOCALES`) separa las dos pestañas: son dos procesos con dos cadencias, y si compartieran cabecera, la última carga de una podría ser una cabecera sin ninguna fila de la otra.

### Por qué se guardan la gestión y la reserva efectivas

`RO_T_CASHFLOW_SALDOS_LOCAL` guarda `GESTION`, `RESERVA` y `NETO_DEPOSITAR` **de cada carga**, y no sólo el parámetro vigente. Es lo que permite reconstruir una carga vieja después de que alguien cambie la reserva de una sucursal: recalcularla con la reserva de hoy daría un neto que nunca existió.

Lo mismo con `MONEDA` en el detalle de saldos: se copia de la cuenta en el momento de la carga y no se lee por `JOIN`, para que corregir la moneda de una cuenta no reescriba el significado del histórico.

### Qué guarda el botón *Guardar* de la Pestaña 2

**Las dos cosas, en una sola transacción:**

1. **El parámetro** (`RO_T_CASHFLOW_SALDOS_SUCURSAL`), con la gestión y la reserva que quedaron en pantalla. Es el mismo dato que se edita en Parámetros → Saldos: un solo lugar, editable desde los dos lados. **Es lo que el tablero va a usar de ahí en adelante.**
2. **La foto** (`RO_T_CASHFLOW_SALDOS_LOCAL`), con la gestión y la reserva efectivas de esa carga.

El **saldo no viaja desde el navegador**: al guardar, el servidor vuelve a correr la consulta y toma de ahí el saldo y la fecha. Del cliente se aceptan únicamente los dos valores editables. Si el saldo viniera del cliente, se podría grabar un número inventado como si fuera lo que dice Tango.

> **Antes sólo se guardaba la foto, y eso hacía que editar la reserva en esta pantalla no sirviera para nada**: no persistía —al recargar volvía el valor viejo— y el tablero no la veía, porque `SaldosProvider` lee el parámetro vigente y no la última carga. Los campos editables eran un simulador disfrazado de formulario.

**Sólo se escriben las sucursales que cambiaron.** La pantalla manda las veinte en cada guardado; sin el diff (`Saldos::resolverOverrides()`), cada guardado les pisaría `FECHA_UPDATE` y `USUARIO` a todas y la columna *Última edición* de Parámetros dejaría de significar algo. La reserva se compara con tolerancia: la columna es `DECIMAL(19,4)` y el valor da la vuelta por JSON y por un input numérico, así que una comparación estricta reportaría cambios que no existen.

Las dos escrituras van en la **misma transacción**: separadas, una falla a mitad de camino dejaría la reserva cambiada sin la foto que la explica, o al revés. Por eso el parámetro se escribe con el `$cid` de la transacción y no llamando a `saveSucursal()`, que abre su propia conexión — mismo criterio que `CashflowEstructura::guardar()`.

Editar una sucursal que la consulta devuelve pero que **nunca se sincronizó** le crea la fila de parámetro (`UPSERT`). La alternativa sería que el `UPDATE` no afectara ninguna fila y la edición se perdiera en silencio.

---

## Mapeo campo por campo contra la API de Interbanking

La integración **no está hecha**. Las columnas ya existen para que enchufarla no obligue a migrar datos. Llevan el nombre del `Anexo I` en mayúsculas a propósito: son literalmente los campos del proveedor, y nombrarlos igual hace que el mapeo sea una copia uno a uno, sin tabla de traducción que se desincronice.

### `/accounts` — Consulta de Cuentas → `RO_T_CASHFLOW_SALDOS_CUENTA`

| Campo de la API | Columna | Tipo | Hoy |
| --- | --- | --- | --- |
| `bank_id` | `BANK_ID` | `VARCHAR(3)` | `NULL` — código BCRA, 3 dígitos |
| `bank_name` | `BANK_NAME` | `VARCHAR(80)` | `NULL` |
| `account_number` | `ACCOUNT_NUMBER` | `VARCHAR(30)` | `NULL` |
| `account_type` | `ACCOUNT_TYPE` | `VARCHAR(2)` | `NULL` — `CC` / `CA` |
| `cbu` | `CBU` | `VARCHAR(22)` | `NULL` — índice único filtrado |
| `account_label` | `ACCOUNT_LABEL` | `VARCHAR(80)` | `NULL` |
| `currency` | **`MONEDA`** | `CHAR(3)` | Se carga a mano — `ARS` / `USD` |

**`currency` es la única que no lleva el nombre de la API.** Es el único campo de `/accounts` que el módulo ya necesita hoy —la pestaña cierra con un total por moneda y el proveedor convierte según él—, así que se le deja el nombre del dominio. Es **una** columna y no dos: dos columnas para el mismo dato terminan discrepando.

Columnas propias que la API no trae:

| Columna | Para qué |
| --- | --- |
| `NOMBRE` | Etiqueta que se ve en pantalla y que controla el usuario. `BANK_NAME` y `ACCOUNT_LABEL` son strings del proveedor, que puede cambiarlos de su lado. Cuando entre la API, una cuenta nueva nace con `NOMBRE = BANK_NAME` y el usuario puede renombrarla sin que la próxima sincronización se lo pise |
| `TIPO` | `BANCO` / `MERCADO_PAGO` / `EFECTIVO_CENTRAL` / `OTRO` — Mercado Pago y el efectivo no salen de Interbanking |
| `ORIGEN_DATO` | `API` / `MANUAL` / `CONSULTA` |
| `ORDEN`, `ACTIVO`, `FECHA_UPDATE`, `USUARIO` | Orden en pantalla, baja lógica y auditoría |

**El `CBU` es la clave con la que la API va a reconocer una cuenta ya cargada a mano.** Va con índice único **filtrado** (`WHERE CBU IS NOT NULL`) porque hoy casi todas las filas lo tienen en `NULL`, y un `UNIQUE` común de SQL Server admite un solo `NULL`.

### `/accounts/balances` — Consulta de Saldos → `RO_T_CASHFLOW_SALDOS_DETALLE`

| Campo de la API | Columna | Nota |
| --- | --- | --- |
| `row_date` | `FECHA_SALDO` | Ver abajo |
| `balances.countable_balance` | `COUNTABLE_BALANCE` | **El que alimenta el Cashflow** |
| `balances.initial_operating_balance` | `INITIAL_OPERATING_BALANCE` | |
| `balances.current_operating_balance` | `CURRENT_OPERATING_BALANCE` | |
| `balances.projected_balance_24hs` | `PROJECTED_BALANCE_24HS` | |
| `balances.projected_balance_48hs` | `PROJECTED_BALANCE_48HS` | |
| `message` | `MESSAGE` | Error **por cuenta** |

**Se guardan los cinco saldos aunque el tablero use uno solo.** Son cinco columnas y evitan una migración el día que se quiera mirar el proyectado a 24/48hs.

**`message` no se descarta.** La API responde `200` con cuentas que fallaron individualmente. Una fila con `MESSAGE` y los importes en `NULL` significa *"esta cuenta no se pudo leer"*, que no es lo mismo que *"esta cuenta tiene cero"* — y esa confusión es exactamente el error caro que este módulo viene a evitar. La pantalla lo muestra en rojo bajo el nombre de la cuenta y el tablero lo levanta como aviso.

### `historical_balances` (hasta 180 días) → `RO_T_CASHFLOW_SALDOS_DETALLE`

| Campo de la API | Columna |
| --- | --- |
| `operation_date` | `FECHA_SALDO` |
| `day_balance` | `DAY_BALANCE` |
| `total_debits` | `TOTAL_DEBITS` |
| `total_credits` | `TOTAL_CREDITS` |

**`FECHA_SALDO` mapea contra dos campos distintos** —`row_date` cuando la fila viene de `balances` y `operation_date` cuando viene de `historical_balances`— y por eso lleva nombre propio y no el de ninguno de los dos. La clave `UNIQUE (ID_CARGA, ID_CUENTA, FECHA_SALDO)` permite que una sola carga traiga los 180 días de una cuenta sin chocar.

---

## Qué queda pendiente para la integración

Fuera del alcance de este cambio; el modelo y la pantalla ya están listos para recibirla.

- **OAuth 2.0 client credentials.** No se escribió el cliente HTTP ni se guardan credenciales en ningún lado.
- **El token vive 7200 s** y hay que resguardarlo y renovarlo, no pedir uno por request.
- **Rate limits con bloqueo preventivo ante polling.** Hay que espaciar las consultas y no reintentar en bucle.
- **Sincronización de `/accounts`**: dar de alta las cuentas nuevas y **reconocer por `CBU`** las que ya se cargaron a mano, para no duplicarlas. `NOMBRE` no se pisa.
- **Backfill de `historical_balances`**: una carga con `ORIGEN = 'API'` y una fila por día y por cuenta.
- Pasar `ORIGEN_DATO` de las cuentas bancarias de `MANUAL` a `API`. El formulario manual **se mantiene** como respaldo.

---

## El proveedor

`Class/Providers/SaldosProvider.php`, registrado bajo **dos** códigos, igual que `ComexProvider`:

| Código | Serie | Qué devuelve |
| --- | --- | --- |
| `SALDOS` | `DISPONIBLE` | Último saldo conocido de cada cuenta, convertido a pesos |
| `CAJA_LOCALES` | `DEPOSITOS` | Neto a depositar de los locales que depositan |

Van separados porque **leen dos servidores distintos**: así una caída del servidor de locales no se lleva puesto el disponible bancario.

### El saldo va en la columna de su fecha y en cero en el resto

Es lo más fácil de romper en silencio. La fila `DISPONIBLE` es de tipo `SALDO_INICIAL`, y el motor toma lo que el módulo pone en **cada columna** como aporte de esa columna al arrastre (`Cashflow::sumarAporteSaldo()`). Repetir el saldo en las 28 columnas diarias sumaría la misma plata veintiocho veces: con 157 millones, el tablero cerraría con cuatro mil millones de caja inventada.

### Un saldo con fecha anterior al eje abre el horizonte

Las dos series aplican la misma regla, en un solo lugar (`Saldos::destinoEnEje()`): una fecha anterior a hoy no tiene columna propia y va a la apertura del horizonte, con aviso; una fecha dentro del eje no se toca nunca.

Vale para las dos porque las dos describen **plata que existe ahora** —un saldo bancario, el efectivo en el cajón de un local—, no movimientos ya ocurridos. Dejarlas afuera arrancaría el tablero en cero teniendo el dato, que es justamente el problema que este módulo viene a resolver.

### El enlace del tablero abre la sub-pestaña correcta

La fila *Caja Locales* la produce la **segunda** sub-pestaña, así que su entrada del registro declara `'subtab' => 'locales'` además de `'tab' => 'saldos'`. El motor lo pasa en la fila, `Cashflow.js` lo deja en `window.cfSubTabDestino` antes de navegar y `Saldos.js` lo consume al arrancar.

El tablero **no** activa la sub-pestaña por su cuenta: `loadTab()` carga por AJAX y no avisa cuándo terminó, así que en el momento del click el destino todavía no existe en el DOM. Se consume una sola vez, para que un cambio de pestaña posterior no vuelva a saltar ahí.

### Nunca tumba el tablero

`calcular()` puede lanzar y `series()` lo envuelve. Además cada serie atrapa sus propios problemas para poder rendir cero **con un aviso que diga qué pasó**, en lugar del mensaje genérico de la clase base: tablas sin crear, servidor de locales caído, ninguna cuenta cargada todavía, cuentas cargadas cuyo neto no supera la reserva.

`moneda_origen`, `tipo_cambio`, `fuera_horizonte` y `sin_fecha` se devuelven con valores reales. `moneda_origen` es `'ARS'`: la serie **está** en pesos y el enum del contrato sólo admite dos valores, así que el detalle de qué parte vino en dólares y con qué cotización va en `tipo_cambio` y en los avisos.

---

## Parámetros

Sub-pestaña **Parámetros → Saldos**, con tres secciones.

| Clave | Semilla | Qué controla |
| --- | --- | --- |
| `saldos_cta_tesoreria` | `100101` | Cuenta contable de `SBA05` con el efectivo de tesorería |
| `saldos_dias_alerta_carga` | `7` | Días desde la última carga a partir de los cuales se avisa que el disponible no es el de hoy |

**Bancos y cuentas** y **Otros saldos** son el mismo ABM sobre `RO_T_CASHFLOW_SALDOS_CUENTA`, separados por `TIPO`. Nombre y moneda son editables; el **tipo no**, porque es lo que decide de dónde sale el saldo y cambiarlo dejaría el histórico atribuido a un origen que nunca lo produjo. Si quedó mal, se inhabilita y se crea otra.

> Una cuenta nueva **entra activa**, a diferencia de un medio de pago del mix. No es una inconsistencia: un medio de pago nuevo rompe el 100 % de su canal, así que tiene que entrar apagado. Una cuenta no rompe ningún invariante y nace **sin saldo cargado**, que la pantalla muestra como `sin cargar` y no como cero, así que no puede informar de menos en silencio.

**Locales** trae la lista desde `SUCURSALES_LAKERS` con el botón *Sincronizar con locales*. La sincronización **nunca pisa `GESTION` ni `RESERVA`** —son valores que cargó una persona— y a las sucursales que desaparecen del origen las marca `ACTIVO = 0` en lugar de borrarlas.

**La gestión y la reserva se editan en los dos lados y son el mismo dato.** Esta sección y la Pestaña 2 escriben la misma tabla: acá se administra la lista completa, y en la Pestaña 2 se corrigen mirando los saldos del día, que es el momento en que uno se da cuenta de que una reserva está mal. El valor efectivo de cada carga queda además en el histórico. Ver *Qué guarda el botón Guardar de la Pestaña 2*.

---

## Conexiones

| Qué | Conexión |
| --- | --- |
| Tablas del módulo, parámetros, `SBA05` | `central` |
| `RO_T_SALDO_CAJA_SUCURSALES`, `SUCURSALES_LAKERS` | `locales` |
| Tipo de cambio (`RO_V_DOLAR_OFICIAL_BCRA`) | `central`, por linked server |

En `ENV = DEV` las tablas de locales se alcanzan por linked server con el nombre de cuatro partes `[XL-LAKERBIS].locales_lakers.dbo.`. `Conexion` ya tenía ese valor como propiedad privada y ahora lo expone con `prefijoLocales()`, para no repetir la condición sobre `ENV` en cada consulta.

---

## Pruebas

```bash
php tests/run.php saldos
```

85 casos, todos sin base salvo la última sección, que se saltea sola. Los criterios viven en **helpers estáticos puros**, al estilo de `Ventas::armarTendencias()`: lo delicado de este módulo no son las consultas sino las decisiones.

| Qué se verifica | Helper |
| --- | --- |
| Sólo las sucursales en `Deposita` aportan; las de `Envía` quedan fuera | `armarSaldosLocales()` |
| Un neto negativo aporta cero, no negativo | `armarSaldosLocales()` |
| El neto es saldo menos reserva, sin ajuste impositivo | `armarSaldosLocales()` |
| El importe se imputa en la fecha del saldo, sin corrimientos (ni de fin de semana) | `armarSerieLocales()` |
| Un saldo de caja de ayer se imputa en la primera columna, con aviso; uno posterior al eje queda fuera | `armarSerieLocales()` |
| El enlace del tablero lleva a la sub-pestaña de locales | `CashflowRegistry` |
| Reenviar los mismos valores no cuenta como cambio; sólo se escribe lo que se tocó | `resolverOverrides()` |
| Una sucursal sin parámetro cuenta como cambio, para que el `UPSERT` la cree | `resolverOverrides()` |
| Una gestión inválida o una reserva negativa cortan antes de abrir la transacción | `resolverOverrides()` |
| "Última carga" con dos cargas el mismo día, y con la misma marca de tiempo | `ultimaCarga()` |
| El saldo queda en la columna de su fecha y en cero en las otras 27 | `armarSerieDisponible()` |
| Un saldo viejo abre el horizonte en la primera columna, con aviso | `armarSerieDisponible()` |
| Los dólares se valúan con la cotización de su mes; sin cotización no se valúan a cero | `armarSerieDisponible()` |
| Los totales por moneda no se mezclan | `totalesPorMoneda()` |
| Varias cuentas de tesorería de una sucursal se consolidan en una fila | `agruparPorSucursal()` |

Verificado además contra la base real: el script corrido dos veces sin duplicar, la consulta de `SBA05`, y un alta de cuenta + carga + lectura por el proveedor que dejó el importe en una sola columna del eje.

---

## Usuario

Todavía no hay login. Todas las tablas tienen `USUARIO VARCHAR(50) NULL` y hoy se graba `NULL`. Los métodos de guardado ya reciben `$usuario` y los controllers lo resuelven con `usuarioActual()`, que lee `$_SESSION['usuario']`.

---

## Archivos

```
sql/cashflow_saldos.sql                        Las 5 tablas + semillas + parámetros
cashflow/Class/Saldos.php                      Motor del módulo y helpers puros
cashflow/Class/Providers/SaldosProvider.php    DISPONIBLE y DEPOSITOS
cashflow/Controller/SaldosController.php       Las dos pestañas y las dos cargas
cashflow/Tabs/saldos.php                       Reemplaza el placeholder
cashflow/Tabs/parametros_saldos.php            Sub-pestaña de Parámetros
cashflow/Js/Saldos.js
cashflow/Js/Parametros-Saldos.js
cashflow/Css/Saldos.css
tests/test_saldos.php
```

Modificados: `Class/CashflowRegistry.php` (los dos códigos a `disponible => true`) · `Class/Parametros.php` (módulo `SALDOS` y sus secciones) · `Controller/ParametrosController.php` (ABM de cuentas y locales) · `Tabs/parametros.php` (el `tab-pane`) · `Css/Parametros.css` · `class/conexion.php` (`prefijoLocales()`) · `tests/test_providers.php` (ahora hay 6 módulos con datos reales).

Ver `README-cashflow.md`.
