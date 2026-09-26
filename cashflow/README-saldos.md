# Módulo Saldos — disponible inicial, caja de locales y fondos

Reemplaza el placeholder de la pestaña **Saldos** y alimenta las dos filas del tablero que hasta ahora rendían cero: *Saldo Inicial* y *Caja Locales*. Desde `feature/cuentas-inversion` también lleva las **cuentas de inversión y comitente**, que son el stock de la sección Cobertura.

Ramas: `feature/saldos`, `feature/cuentas-inversion`

---

## La idea en una línea

**Una carga es un evento fechado, no un `UPDATE`.** Los saldos no se pisan: cada carga inserta un juego nuevo de filas y la pantalla muestra, para cada dato, su último valor conocido **con la fecha en que se cargó**. Los fondos siguen la misma idea con otra forma: no se cargan fotos, se cargan **movimientos**, y el saldo se calcula.

```
Pestaña 1 "Saldos"          Pestaña 2 "Saldos Locales"      Pestaña 3 "Fondos"
efectivo central (SBA05)    caja de locales propios (Tango)  cuentas INVERSION / COMITENTE
+ bancos (manual → API)     − reserva de caja                saldo inicial
+ Mercado Pago (manual)     = neto a depositar               + suscripciones − rescates
        │                            │                                │
        ▼                            ▼                                ▼
  serie DISPONIBLE            serie DEPOSITOS                   series STOCK
        │                            │                                │
        └──── SaldosProvider ────────┘                         FondosProvider
                     │                                                │
              Cashflow (motor)                                 Cashflow (motor)
        Saldo Inicial      Caja Locales                   Cobertura: stock por fondo
```

**Los fondos no entran en Disponibilidades.** Su único rol en el tablero es ser stock de la sección Cobertura; si entraran a los dos lados, la misma plata se contaría dos veces.

---

## Ejecución de los scripts

Contra `central`, en este orden:

```sql
-- 1. sql/cashflow_saldos.sql
-- 2. sql/cashflow_saldos_cuentas_fondo.sql   (después de cashflow_cobertura.sql,
--                                             cashflow_cobertura_por_fondo.sql y
--                                             cashflow_dolares_comitente_cobertura.sql)
```

El primero crea las cinco tablas, siembra la cuenta de efectivo de tesorería y carga los dos parámetros del módulo. **Es reejecutable**: las tablas se crean sólo si no existen y las semillas entran por `MERGE WHEN NOT MATCHED`, así que una segunda corrida no duplica nada ni pisa un valor ya editado. Verificado corriéndolo dos veces.

No depende de los otros scripts, pero las filas del tablero que alimenta las creó `sql/cashflow_estructura_disponibilidades.sql`.

El segundo agrega `CLASE` y el saldo inicial al catálogo de cuentas, crea la tabla de movimientos de los fondos, **migra** la última carga vigente de Otros Ingresos como saldo inicial de dos cuentas nuevas, reescribe el origen de las aplicaciones de cobertura a la clave de esas cuentas y reapunta las dos filas de stock del tablero. Ver *Pestaña 3 — Fondos*. También reejecutable: cada bloque pregunta antes de escribir, y las migraciones se guardan por clase de cuenta (si ya hay una cuenta `INVERSION`, no migra el saldo de inversiones). Verificado corriéndolo dos veces contra la base: 13 cuentas antes y después.

Si un script **no se corrió**, la pantalla no falla: muestra un aviso y el tablero deja las filas correspondientes en cero, igual que hace hoy con la estructura. Sin el segundo, las dos sub-pestañas de siempre funcionan igual, todas las cuentas son cuentas a la vista, y Fondos avisa qué script falta.

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

### Filtro por tipo

El selector de la cabecera filtra la tabla por tipo de cuenta (`Banco`, `Mercado Pago`, `Efectivo`, `Otro`) y **los KPI *Total en Pesos* y *Total en Dólares*, y el pie de la tabla, se recalculan sobre lo filtrado** —el pie de cada KPI dice qué tipo está aplicado—. Es un filtro de pantalla, resuelto en el navegador con las filas que ya trajo el payload: lo que se guarda y lo que consume el tablero es siempre el conjunto completo.

Mientras dura una **carga**, el filtro se limpia y se bloquea: el guardado toma el input de cada fila, y una fila escondida por el filtro no tendría input, así que su saldo viajaría en cero.

---

## Pestaña 2 — Saldos Locales

Alimenta la fila **Caja Locales** (`DEPOSITOS`). Sale de una consulta contra el servidor `locales` sobre **`RO_T_SALDOS_CIERRE_SBA29`** que devuelve **el último registro** de caja por sucursal y cuenta —la fecha más nueva y, a igual fecha, el `ID` más alto, porque hay días cargados dos veces—, filtrando por `SUCURSALES_LAKERS` con `CANAL = 'PROPIOS'` y `HABILITADO = 1`. Es lo que se muestra hoy.

**El importe es `SALDO_CIER`, el saldo de cierre.** La tabla trae también `SALDO_APE` (apertura), que no se usa: lo que hay para depositar es lo que quedó al cerrar.

> Antes el origen era `RO_T_SALDO_CAJA_SUCURSALES.SALDO_MONEDA`. Se cambió por pedido del negocio; la foto que se guarda en `RO_T_CASHFLOW_SALDOS_LOCAL` conserva sus columnas (`SALDO_MONEDA`, `COD_CTA_CUENTA_TESORERIA`), así que el histórico sigue leyéndose igual.

| Columna | Qué es | Editable |
| --- | --- | --- |
| Local | `NRO_SUCURS` + `DESC_SUCURSAL` de `SUCURSALES_LAKERS` | no |
| Saldo en caja | `SALDO_CIER` de la consulta, o el saldo manual si es más nuevo | **sí**, para cuando la consulta no trajo el cierre |
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

### El saldo en caja se puede tipear cuando la consulta no trajo el cierre

La alimentación de `RO_T_SALDOS_CIERRE_SBA29` puede fallar —error de conexión, un cierre que no viajó— y entonces el último registro del local queda viejo. Por eso:

- **Las filas cuyo saldo no es el cierre de ayer se resaltan** (fondo y borde rojos, y *"no es de ayer"* debajo de la fecha). La regla es `fecha_saldo < ayer`, con `ayer` calculado por el servidor y devuelto en el payload; una fecha de hoy o posterior no está desactualizada, y una fila sin fecha sí. El aviso lista los locales y sube también al tablero.
- **La columna *Saldo en caja* es un input.** Un saldo distinto del que manda se guarda, con *Guardar*, en **`RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL`** como registro fechado **ayer** —el cierre que no llegó—, junto con lo que decía la consulta en ese momento (`SALDO_CONSULTA`, `FECHA_CONSULTA`) para poder explicarlo después.

**La regla de precedencia es una sola**, `Saldos::aplicarSaldosManuales()`, y la usan la pestaña, el guardado y el tablero: para cada local **gana el más nuevo por fecha** entre la consulta y el manual, y **a igual fecha gana el manual** —si alguien lo tipeó es porque el de la consulta no servía—. Cuando la consulta vuelve a traer un cierre más nuevo, vuelve a mandar sola: un manual no es un override permanente sino un dato con fecha.

Qué saldos son nuevos lo decide `saldosManualesNuevos()`, un helper puro con tolerancia de un centavo: la pantalla manda los 20 locales en cada guardado, y sin el diff cada guardado insertaría un manual por local y la consulta no volvería a mandar nunca. Un manual de un local que la consulta no devuelve no inventa la fila.

La foto (`RO_T_CASHFLOW_SALDOS_LOCAL`) guarda **`ORIGEN_DATO`** (`CONSULTA` / `MANUAL`) y la cabecera de una carga con manuales va como `MIXTA`, así el histórico dice de dónde salió el saldo que entró al tablero ese día. En pantalla, la fila con un manual lleva la marca *manual* (con quién y cuándo lo cargó) y debajo *"consulta: $X del dd/mm"*.

> La tabla y la columna las crea `sql/cashflow_saldos_local_manual.sql` (mismo bloque dentro de `cashflow_saldos.sql`). Sin correrlo, la pestaña funciona igual pero el saldo no se puede tipear y avisa qué script falta.

### Una fila por sucursal, no por cuenta

La consulta devuelve una fila por `(sucursal, cuenta de tesorería)`. La reserva, en cambio, es un mínimo **de la sucursal**: con dos cuentas y una reserva, restarla a cada una la descontaría dos veces. Así que los saldos se suman por sucursal, la columna `CUENTAS` dice cuántas se sumaron y `COD_CTA_CUENTA_TESORERIA` guarda los códigos (`COD_CTA`) separados por coma, para que la suma sea auditable.

---

## Pestaña 3 — Fondos

Las cuentas de **inversión** y **comitente** del catálogo, con su cuenta corriente. Alimentan las dos filas de stock de la sección **Cobertura** del tablero (`STOCK_INVERSIONES` y `STOCK_DOLARES_COMITENTE`), a través de `FondosProvider`.

> Hasta acá ese stock salía de dos **fotos** cargadas en *Otros Ingresos*: una del saldo invertido en pesos y otra de los dólares de la cuenta comitente. Una foto dice cuánto había el día que alguien la tomó y nada más: no explica de dónde salió el número ni permite asentar un rescate. Ahora cada fondo es una cuenta y su saldo **se calcula**. Las pestañas de Otros Ingresos se eliminaron; sus tablas quedan por el histórico: ver `README-otros-ingresos.md`.

### Una cuenta tiene TIPO y CLASE, y son dos preguntas

`TIPO` ya existía y dice **de dónde sale** el saldo (`BANCO`, `MERCADO_PAGO`, `EFECTIVO_CENTRAL`, `OTRO`): decide el origen del dato, lo que la API va a sincronizar, el filtro de la pestaña 1, y es inmutable. `CLASE` es nueva y dice **qué es** la cuenta:

| `CLASE` | Qué es | Cómo se carga | Dónde entra al tablero |
| --- | --- | --- | --- |
| `CTA_CORRIENTE`, `CAJA_AHORRO` | Plata a la vista | Foto del saldo (pestaña 1) | *Saldo Inicial* (Disponibilidades) |
| `INVERSION`, `COMITENTE` | Un fondo | Cuenta corriente (pestaña 3) | *Inversiones disponibles* / *Dólares en cuenta comitente* (Cobertura) |

Se descartó reorganizar `TIPO` porque son dos ejes independientes —un banco tiene cuentas corrientes *y* cajas de ahorro, un comitente puede estar en pesos o en dólares—, porque `TIPO` ya está escrito en el histórico, y porque `ACCOUNT_TYPE` de Interbanking (`CC`/`CA`) es la visión del proveedor del mismo dato, sólo para cuentas bancarias; `CLASE` es la del negocio y vale para todas. El argumento completo está arriba de `sql/cashflow_saldos_cuentas_fondo.sql`.

Las cuentas que ya estaban quedaron como `CTA_CORRIENTE`, que es lo que son todas hoy; si alguna es una caja de ahorro se corrige desde Parámetros. **La clase se cambia sólo dentro del mismo grupo**: entre las dos a la vista o entre los dos fondos. Cruzar de grupo dejaría el histórico de la cuenta —fotos en un caso, movimientos en el otro— leído como lo que no es. Si quedó mal, se inhabilita y se crea otra, igual que con el `TIPO`. La regla es `Fondos::cambioDeClasePermitido()`, y `Fondos::esFondo()` es **la única** que decide de qué lado del tablero va una cuenta: `Saldos::getSaldosActuales()` la usa para dejar los fondos fuera del disponible.

Los fondos migrados llevan `TIPO = 'OTRO'`: un fondo no es un banco ni una billetera, y su saldo no sale de ninguna consulta ni API sino de su cuenta corriente.

### El saldo es saldo inicial + suscripciones − rescates

```
saldo a una fecha = SALDO_INICIAL
                  + suscripciones − rescates
                    (movimientos vigentes con FECHA_SALDO_INICIAL < FECHA <= esa fecha)
```

La cuenta la hace `Fondos::saldoA()`, un helper puro, y la usan la pestaña y el proveedor: el tablero y la pantalla no pueden discrepar. Tres reglas que no son obvias:

- **El saldo inicial es al cierre de su fecha.** Un movimiento de esa fecha o anterior ya está incluido en él y no se vuelve a sumar (la pestaña lo marca *En saldo inicial*). Sin esa regla, fijar un saldo inicial nuevo después de haber cargado movimientos los contaría dos veces. Por lo mismo, `guardarMovimiento()` **rechaza** un movimiento anterior o igual a esa fecha: cargarlo no cambiaría el saldo y nada lo diría. Si el saldo inicial está mal, se corrige el saldo inicial.
- **El stock del tablero es el saldo a hoy.** Un rescate previsto para la semana que viene es un dato real —se acepta y se lista, marcado *Futuro*— pero no entra al saldo de hoy ni al stock: hoy la plata todavía está en el fondo. El proveedor avisa cuántos hay. **Donde sí entra es en la cobertura automática, en su fecha**: el motor rescata de cada fondo hasta su saldo *a la fecha de cada columna* (`Fondos::saldoProyectado()`: lo de hoy más lo previsto hasta ahí), así que un rescate cargado para el lunes deja de estar disponible desde el lunes y una suscripción prevista entra desde el suyo. Ver *La cobertura la calcula el motor* en `README-cashflow.md`.
- **Sin saldo inicial se arranca de cero**, y la pantalla dice *sin saldo inicial* en vez de mostrar un cero: no es lo mismo. El tablero también lo avisa.

El importe de un movimiento es **siempre positivo** y el signo lo pone el tipo (`SUSCRIPCION` suma, `RESCATE` resta), por el mismo motivo que el tablero no tiene columna de signo: "un rescate negativo" no significa nada. La moneda **no viaja**: se copia de la cuenta al guardar, como hace el detalle de saldos, así que corregir la moneda de una cuenta no reescribe lo que significan sus movimientos viejos. Por eso la moneda de un fondo **con movimientos no se deja cambiar**.

**No se registra contrapartida bancaria.** Un rescate saca plata del fondo y nada más: lo que entra al banco se va a ver en el saldo bancario, que en breve lo trae la API. Registrarla acá sería adelantar un dato que otro circuito ya va a medir, y las dos cifras podrían discrepar.

### El saldo inicial va en la cuenta

`SALDO_INICIAL` y `FECHA_SALDO_INICIAL` son dos columnas del catálogo, no un movimiento: es un parámetro de la cuenta, como el nombre, que se fija al darla de alta (o en la migración) y del que arranca la cuenta corriente. Los eventos son los movimientos; el saldo inicial es el punto de partida. Corregirlo es un `UPDATE` auditado (`FECHA_UPDATE`, `USUARIO`) desde Parámetros → Saldos, y van los dos o ninguno (`CHECK`).

### Editar un movimiento no es un UPDATE

Mismo circuito que `RO_T_CASHFLOW_COBERTURA_APLIC`: corregir marca `VIGENTE = 0` el anterior e inserta uno nuevo que apunta al que reemplaza (`ID_REEMPLAZA`), en una transacción; dar de baja marca `VIGENTE = 0` y no inserta nada. A diferencia de las aplicaciones, **la identidad no es la fecha**: dos suscripciones el mismo día a la misma cuenta son dos hechos distintos, y por eso la cadena de versiones va por `ID_REEMPLAZA`. El modal de la pestaña muestra **todo**: vigentes, corregidos (tachados, con cuál los reemplazó) y dados de baja. Es lo único que explica por qué el saldo del fondo de la semana pasada era otro.

### Los dólares se valúan como siempre

Una cuenta en `USD` se convierte con la **última cotización oficial conocida a hoy, punta vendedora** (`Cotizacion::ultimaHasta()`), que es el mismo criterio con el que se valuaba la foto de la cuenta comitente y la misma punta con la que se valúan las aplicaciones en dólares: consumir todo el saldo lo deja en cero. Sin cotización, esa cuenta **no entra** y se avisa el importe en dólares. La moneda la dice la **cuenta**, no la clase: una cuenta de inversión en dólares se valúa igual que una comitente. La pestaña, como la 1, **no convierte**: un KPI por clase y moneda.

### Cada cuenta de fondo es un fondo de cobertura

Antes el fondo del que descontaba cada aplicación era una constante del código (`Cobertura::ORIGENES`: `INVERSIONES`, `SUSCRIPCION`, `DOLARES`) y el registro declaraba a qué fondo pertenecía cada stock (`origen_cobertura`). Con cuentas que da de alta el usuario eso ya no puede ser una lista fija: **cada cuenta de fondo es un fondo**, su clave es `Fondos::claveFondo()` (`CTA_` + ID) y su moneda es la de la cuenta. Es la clave que guarda `RO_T_CASHFLOW_COBERTURA_APLIC.ORIGEN`, la que `Cobertura::origenes()` lista, y la que el motor cruza. El reparto por cuenta **viaja con la serie** (`por_fondo` y `fondos`, ver `CashflowProvider`) y `Cashflow::resolverCobertura()` descuenta de cada cuenta lo aplicado desde ella. El detalle de qué cambió en Cobertura está en `README-cashflow.md`.

Desde `feature/cobertura-automatica` la serie de stock lleva además **el saldo de cada cuenta en cada columna del eje** (`fondos_tope`), que es el tope del que el motor puede rescatar ese día, y la cotización vendedora de cada columna para las cuentas en dólares (`Cotizacion::ultimasHasta()`, una lectura para todo el eje). El orden en que el motor consume los fondos es **primero `INVERSION`, después `COMITENTE`, y dentro de cada clase el orden del catálogo** (`ORDEN`, `NOMBRE`), el mismo de esta pestaña. `getCuentasFondo()` devuelve los movimientos vigentes de cada cuenta sólo a pedido (`$conMovimientos`): el proveedor los necesita para proyectar, la pestaña no.

### La pantalla

Un KPI por clase y moneda, la tabla de cuentas con saldo inicial (y su fecha), suscripciones, rescates, saldo a hoy y último movimiento, y por cuenta el botón de movimientos que abre el historial completo. *Nuevo movimiento* pide cuenta, fecha, tipo, importe y observación; la moneda se muestra al lado del importe y es la de la cuenta. Desde el historial se corrige (abre el mismo formulario con el movimiento cargado y viaja `id_reemplaza`) o se da de baja, con confirmación. La sub-pestaña es lazy como la de locales, y el enlace de las filas de stock del tablero abre directamente en ella (`'subtab' => 'fondos'`).

Las cuentas se dan de alta en **Parámetros → Saldos**, sección *Fondos de inversión y cuentas comitente*, con su saldo inicial y fecha. Ver *Parámetros*.

---

## Modelo de datos

Seis tablas, prefijo `RO_T_CASHFLOW_SALDOS_`. Cumplen tres propiedades:

| Propiedad | Cómo |
| --- | --- |
| **El histórico no se pisa** | El detalle cuelga de `ID_CARGA` y no tiene clave `(cuenta, fecha)` que se sobrescriba |
| **"Última carga" tiene respuesta única** | La resuelve la cabecera, con desempate por `ID` |
| **Los campos de la API existen desde el día uno** | Creados y en `NULL` mientras la carga sea manual |

| Tabla | Qué guarda |
| --- | --- |
| `RO_T_CASHFLOW_SALDOS_CUENTA` | Catálogo de cuentas (parámetro): tipo, **clase**, moneda, origen, **saldo inicial con su fecha** (sólo fondos) y los siete campos de `/accounts` |
| `RO_T_CASHFLOW_SALDOS_CARGA` | Cabecera de cada carga: tipo, fecha y hora, usuario, origen, observaciones |
| `RO_T_CASHFLOW_SALDOS_DETALLE` | Histórico de saldos por cuenta y fecha, con los cinco `balances` y el `message` |
| `RO_T_CASHFLOW_SALDOS_SUCURSAL` | Gestión y reserva por local (parámetro) |
| `RO_T_CASHFLOW_SALDOS_LOCAL` | Histórico de la caja de los locales, con la gestión y la reserva **efectivas** y `ORIGEN_DATO` del saldo |
| `RO_T_CASHFLOW_SALDOS_LOCAL_MANUAL` | Saldo de caja tipeado a mano cuando la consulta no trajo el cierre; insert-only, fechado ayer |
| `RO_T_CASHFLOW_SALDOS_FONDO_MOV` | La cuenta corriente de cada fondo: suscripciones y rescates, con moneda copiada de la cuenta, `VIGENTE`, `ID_REEMPLAZA` y `FECHA_BAJA`. Nunca se borra ni se actualiza un importe |

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

Y `Class/Providers/FondosProvider.php`, también bajo dos códigos, uno por clase de fondo, porque son dos filas del tablero y cada una tiene que poder mostrar su moneda y su cotización:

| Código | Serie | Qué devuelve |
| --- | --- | --- |
| `FONDO_INVERSION` | `STOCK` | Suma del saldo a hoy de las cuentas `INVERSION`, en pesos, repartida por cuenta en `por_fondo` |
| `FONDO_COMITENTE` | `STOCK` | Ídem para las cuentas `COMITENTE`, con las de dólares valuadas a la última cotización a hoy, punta vendedora |

El importe va en el primer día del eje sólo para llegar al motor por el mismo camino que cualquier serie: el motor le vacía las columnas a una fila `STOCK_COBERTURA` y muestra el total. Ambas series llevan `por_fondo` (clave de cuenta → pesos) y `fondos` (clave → nombre), que es lo que `Cashflow::resolverCobertura()` cruza con lo aplicado. Las dos dependencias con base (`Fondos` y `Cotizacion`) van por fábrica, así que `tests/test_fondos.php` lo prueba con cuentas de mentira.

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

Sub-pestaña **Parámetros → Saldos**, con cinco secciones.

| Clave | Semilla | Qué controla |
| --- | --- | --- |
| `saldos_cta_tesoreria` | `100101` | Cuenta contable de `SBA05` con el efectivo de tesorería |
| `saldos_dias_alerta_carga` | `7` | Días desde la última carga a partir de los cuales se avisa que el disponible no es el de hoy |

**Bancos y cuentas**, **Otros saldos** y **Fondos de inversión y cuentas comitente** son el mismo ABM sobre `RO_T_CASHFLOW_SALDOS_CUENTA`: los fondos se separan por `CLASE` y el resto por `TIPO`. Nombre, moneda y clase (dentro del grupo) son editables; el **tipo no**, porque es lo que decide de dónde sale el saldo y cambiarlo dejaría el histórico atribuido a un origen que nunca lo produjo. Si quedó mal, se inhabilita y se crea otra. Los fondos llevan además el **saldo inicial con su fecha**, que se fija en el alta y se corrige ahí mismo; van los dos o ninguno, y la moneda de un fondo con movimientos no se cambia. Cada botón *Guardar* manda **su** grilla: apretar *Guardar fondos* no manda los bancos que uno estaba editando a medias.

Sin `sql/cashflow_saldos_cuentas_fondo.sql`, los selectores de clase y el alta de fondos quedan apagados diciendo qué script falta, y el resto del ABM funciona como antes.

> Una cuenta nueva **entra activa**, a diferencia de un medio de pago del mix. No es una inconsistencia: un medio de pago nuevo rompe el 100 % de su canal, así que tiene que entrar apagado. Una cuenta no rompe ningún invariante y nace **sin saldo cargado**, que la pantalla muestra como `sin cargar` y no como cero, así que no puede informar de menos en silencio.

**Locales** trae la lista desde `SUCURSALES_LAKERS` con el botón *Sincronizar con locales*. La sincronización **nunca pisa `GESTION` ni `RESERVA`** —son valores que cargó una persona— y a las sucursales que desaparecen del origen las marca `ACTIVO = 0` en lugar de borrarlas.

**La gestión y la reserva se editan en los dos lados y son el mismo dato.** Esta sección y la Pestaña 2 escriben la misma tabla: acá se administra la lista completa, y en la Pestaña 2 se corrigen mirando los saldos del día, que es el momento en que uno se da cuenta de que una reserva está mal. El valor efectivo de cada carga queda además en el histórico. Ver *Qué guarda el botón Guardar de la Pestaña 2*.

---

## Conexiones

| Qué | Conexión |
| --- | --- |
| Tablas del módulo, parámetros, `SBA05` | `central` |
| `RO_T_SALDOS_CIERRE_SBA29`, `SUCURSALES_LAKERS` | `locales` |
| Tipo de cambio (`RO_V_DOLAR_OFICIAL_BCRA`) | `central`, por linked server |

En `ENV = DEV` las tablas de locales se alcanzan por linked server con el nombre de cuatro partes `[XL-LAKERBIS].locales_lakers.dbo.`. `Conexion` ya tenía ese valor como propiedad privada y ahora lo expone con `prefijoLocales()`, para no repetir la condición sobre `ENV` en cada consulta.

---

## Pruebas

```bash
php cashflow/tests/run.php saldos
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
| El saldo tomado es el de cierre, no el de apertura | `agruparPorSucursal()` |
| Un manual más nuevo que la consulta manda; a igual fecha también; uno más viejo no | `aplicarSaldosManuales()` |
| Un manual de un local que la consulta no devuelve no inventa la fila | `aplicarSaldosManuales()` |
| El neto, el aporte y los totales salen del saldo **efectivo** | `armarSaldosLocales()` |
| Un saldo anterior a ayer queda desactualizado, con aviso; uno de ayer, de hoy o posterior no; uno sin fecha sí | `armarSaldosLocales()` |
| Sólo un saldo distinto del efectivo (más de un centavo) es un manual nuevo; ausente o null no cuenta | `saldosManualesNuevos()` |
| Un saldo negativo o no numérico se rechaza antes de abrir la transacción | `saldosManualesNuevos()` |

Verificado además contra la base real: la pestaña marcó los 3 locales sin cierre del 13/09 y, tras cargarlos a mano desde la pantalla, la cabecera quedó `MIXTA`, la foto con 17 filas `CONSULTA` + 3 `MANUAL`, y la pestaña y el tablero dejaron de avisar. También: el script corrido dos veces sin duplicar, la consulta de `SBA05`, y un alta de cuenta + carga + lectura por el proveedor que dejó el importe en una sola columna del eje.

### Los fondos

```bash
php cashflow/tests/run.php fondos
```

133 casos, sin base salvo la última sección. Fija las reglas de `Fondos` (qué clase es un fondo y cuál es **la única** regla que lo decide; el cambio de clase sólo dentro del grupo; el importe positivo con el signo en el tipo; el saldo inicial con su fecha, los dos o ninguno; la clave de fondo y su lectura), `saldoA()` con un escenario que tiene un movimiento anterior al saldo inicial, uno del mismo día, uno dado de baja y uno futuro —cada uno tiene que quedar donde corresponde—, los helpers de `Cobertura` sobre una lista de cuentas de mentira (el origen por defecto saltea inhabilitadas y dólares), y el **proveedor con cuentas inyectadas**: que sume sólo su clase, que reparta por cuenta con nombre, que valúe los dólares a hoy y a la punta vendedora, que sin cotización no invente y avise en dólares, y qué avisa (sin saldo inicial, movimientos futuros, sin cuentas, sin script). Y lo que puede haberse cableado mal: que las filas de stock validen contra el registro real y una sobre el proveedor retirado valide con advertencia; que el script agregue `CLASE` con su `CHECK`, migre **la última carga vigente** con desempate por ID, reescriba **todas** las aplicaciones (no sólo las vigentes), reapunte las filas en vez de crearlas y no borre nada; y que la pestaña, los parámetros y el controller tengan sus piezas.

Contra la base: que ninguna cuenta de fondo entre al disponible, que el saldo listado coincida con `saldoA()` sobre el historial, y que ninguna aplicación de cobertura vigente haya quedado sin cuenta. El circuito completo —alta de movimiento, corrección con `id_reemplaza`, rescate futuro que no entra, baja, y el stock del tablero en cada paso— se corrió a mano contra la base de desarrollo; los tres movimientos de prueba quedaron en `RO_T_CASHFLOW_SALDOS_FONDO_MOV` dados de baja, como historial.

---

## Usuario

Todavía no hay login. Todas las tablas tienen `USUARIO VARCHAR(50) NULL` y hoy se graba `NULL`. Los métodos de guardado ya reciben `$usuario` y los controllers lo resuelven con `usuarioActual()`, que lee `$_SESSION['usuario']`.

---

## Archivos

```
sql/cashflow_saldos.sql                        Las 5 tablas + semillas + parámetros
sql/cashflow_saldos_cuentas_fondo.sql          CLASE, saldo inicial, movimientos de fondos y la migración
cashflow/Class/Saldos.php                      Motor del módulo y helpers puros
cashflow/Class/Fondos.php                      Las cuentas de fondo: clases, cuenta corriente, claves de cobertura
cashflow/Class/Providers/SaldosProvider.php    DISPONIBLE y DEPOSITOS
cashflow/Class/Providers/FondosProvider.php    STOCK de FONDO_INVERSION y FONDO_COMITENTE
cashflow/Controller/SaldosController.php       Las tres pestañas, las dos cargas y los movimientos
cashflow/Tabs/saldos.php                       Reemplaza el placeholder
cashflow/Tabs/parametros_saldos.php            Sub-pestaña de Parámetros
cashflow/Js/Saldos.js
cashflow/Js/Parametros-Saldos.js
cashflow/Css/Saldos.css
tests/test_saldos.php
tests/test_fondos.php
```

Modificados: `Class/CashflowRegistry.php` (los dos códigos a `disponible => true`) · `Class/Parametros.php` (módulo `SALDOS` y sus secciones) · `Controller/ParametrosController.php` (ABM de cuentas y locales) · `Tabs/parametros.php` (el `tab-pane`) · `Css/Parametros.css` · `class/conexion.php` (`prefijoLocales()`) · `tests/test_providers.php` (ahora hay 6 módulos con datos reales).

De la rama `feature/cuentas-inversion`: `Class/Saldos.php` (`fondosCreados()`, los fondos fuera de `getSaldosActuales()`, `CLASE` y saldo inicial en `getCuentas()`, `addCuenta()` y `saveCuenta()`, `getPestanaFondos()`) · `Class/Parametros.php` (clases y `fondos_creados` en el payload) · `Controller/ParametrosController.php` (clase y saldo inicial en el alta y el guardado) · `Class/CashflowRegistry.php` (`FONDO_INVERSION`, `FONDO_COMITENTE`; Otros Ingresos retirados) · `Class/Cobertura.php`, `Class/Cashflow.php`, `Class/CashflowProvider.php` y `Providers/CoberturaProvider.php` (los fondos son las cuentas: ver `README-cashflow.md`).

Ver `README-cashflow.md`.
