# Módulo Saldos — disponible inicial, caja de locales y fondos

Reemplaza el placeholder de la pestaña **Saldos** y alimenta las dos filas del tablero que hasta ahora rendían cero: *Saldo Inicial* y *Caja Locales*. Desde `feature/cuentas-inversion` también lleva las **cuentas de inversión y comitente**, que son el stock de la sección Cobertura. Desde `feature/saldos-interbanking` los **saldos bancarios se leen en vivo de Interbanking** y dejan de cargarse a mano.

Ramas: `feature/saldos`, `feature/cuentas-inversion`, `feature/saldos-locales-dia-acreditacion`, `feature/saldos-interbanking`

---

## La idea en una línea

**Una carga es un evento fechado, no un `UPDATE`.** Los saldos no se pisan: cada carga inserta un juego nuevo de filas y la pantalla muestra, para cada dato, su último valor conocido **con la fecha en que se cargó**. Los fondos siguen la misma idea con otra forma: no se cargan fotos, se cargan **movimientos**, y el saldo se calcula. Los bancos de Interbanking no se cargan: se leen en vivo, con la fecha de su dato.

```
Pestaña 1 "Saldos"              Pestaña 2 "Saldos Locales"      Pestaña 3 "Fondos"
efectivo central (SBA05)        caja de locales propios (Tango)  cuentas INVERSION / COMITENTE
+ bancos (Interbanking, en vivo) − reserva de caja               saldo inicial
+ bancos sin Interbanking (man.) = neto a depositar              + suscripciones − rescates
+ Mercado Pago y otros (manual)
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
-- 3. sql/cashflow_saldos_dia_acreditacion.sql (después del 1; el bloque también va
--                                             como 5.c dentro del 1)
-- 4. sql/cashflow_saldos_borrar_bancos_manuales.sql  (UNA VEZ, ANTES del 5 y antes de
--                                                    publicar feature/saldos-interbanking:
--                                                    primero con @SIMULAR = 1, después con 0)
-- 5. sql/cashflow_saldos_interbanking.sql            (después del 4)
```

**El 4 borra datos, y es la única excepción a "no hay bajas físicas".** Borra las cuentas `TIPO = 'BANCO'` del catálogo que pasan a venir de Interbanking, con todo su histórico de `RO_T_CASHFLOW_SALDOS_DETALLE`. El catálogo no tiene `NRO_BANCO`, así que no adivina: conserva lo que nombra `@CONSERVAR` (por defecto `BTG Uy`) y borra el resto de las `BANCO`. Arranca en simulación (`@SIMULAR = 1`): lista lo que borra y lo que conserva, con filas de detalle y último saldo, y no toca nada. Antes de borrar busca aplicaciones de cobertura `CTA_<id>` y cualquier FK al catálogo (los movimientos de fondos, entre ellas); si hay, aborta. No toca cuentas de otro tipo, fondos ni cabeceras de carga: si alguna cabecera quedara sin filas lo informa y la deja. Todo en una transacción, y una segunda corrida dice que no hay nada para borrar.

En modo real deja **constancia** en `RO_T_CASHFLOW_SALDOS_DEPURACION`, que crea: qué borró, qué conservó, quién y cuándo. **El código lee Interbanking sólo si existe esa constancia.** Mientras no se corra el 4, la pestaña y el tablero siguen con las cuentas manuales y un aviso crítico dice qué falta. El código no puede distinguir una cuenta manual vieja de un banco que ya está en Interbanking, así que sin esa traba publicar antes del borrado sumaría el mismo banco dos veces.

Contra la base del 08/10/2026 la simulación lista 8 cuentas (BBVA, Ciudad, Credicoop, Galicia, ICBC, Nación, Provincia y Santander) con 24 filas de detalle, y conserva BTG Uy. Ninguna cabecera queda vacía, porque todas conservan el efectivo y Mercado Pago, y no hay referencias. Se probó contra clones `#temporales` del catálogo y del detalle, sin tocar tablas `dbo`: la simulación, el modo real, la segunda corrida y el aborto con una aplicación `CTA_<id>`.

El 5 crea tres tablas: `RO_T_CASHFLOW_SALDOS_BANCO` (alias y estado de cada banco), `RO_T_CASHFLOW_SALDOS_BANCO_MANUAL` (respaldo manual de una cuenta que Interbanking no trae) y `RO_T_CASHFLOW_SALDOS_BANCO_CUENTA` (las cuentas ya vistas, para detectar las nuevas). Siembra como vistas, por `MERGE`, todas las cuentas que ya están en `BI_T_SALDOS_INTERBANKING`, para que el primer día no aparezca todo como nuevo. Es reejecutable y no borra nada. Sin él la pantalla funciona: todos los bancos activos y sin alias, ninguna cuenta nueva, sin respaldo, y Parámetros y la pestaña dicen qué script falta. Ver *Los saldos de Interbanking*.

El tercero agrega el **día de acreditación** de cada local (`RO_T_CASHFLOW_SALDOS_SUCURSAL.DIA_ACREDITACION`) y el día y la fecha efectivos en la foto de cada carga (`RO_T_CASHFLOW_SALDOS_LOCAL.DIA_ACREDITACION` y `FECHA_ACREDITACION`). Son tres columnas nullable, sin default, cada una agregada sólo si falta. No borra ni pisa nada, y si falta la tabla del 1 lo dice y no hace nada. Sin él, la pantalla funciona como antes: el día no se puede elegir, Caja Locales va entera a la primera columna, y la pestaña, Parámetros y el tablero avisan qué script falta. Ver *La fecha de imputación: el día de acreditación de cada local*.

El primero crea las cinco tablas, siembra la cuenta de efectivo de tesorería y carga los dos parámetros del módulo. **Es reejecutable**: las tablas se crean sólo si no existen y las semillas entran por `MERGE WHEN NOT MATCHED`, así que una segunda corrida no duplica nada ni pisa un valor ya editado. Verificado corriéndolo dos veces.

No depende de los otros scripts, pero las filas del tablero que alimenta las creó `sql/cashflow_estructura_disponibilidades.sql`.

El segundo agrega `CLASE` y el saldo inicial al catálogo de cuentas, crea la tabla de movimientos de los fondos, **migra** la última carga vigente de Otros Ingresos como saldo inicial de dos cuentas nuevas, reescribe el origen de las aplicaciones de cobertura a la clave de esas cuentas y reapunta las dos filas de stock del tablero. Ver *Pestaña 3 — Fondos*. También reejecutable: cada bloque pregunta antes de escribir, y las migraciones se guardan por clase de cuenta (si ya hay una cuenta `INVERSION`, no migra el saldo de inversiones). Verificado corriéndolo dos veces contra la base: 13 cuentas antes y después.

Si un script **no se corrió**, la pantalla no falla: muestra un aviso y el tablero deja las filas correspondientes en cero, igual que hace hoy con la estructura. Sin el segundo, las dos sub-pestañas de siempre funcionan igual, todas las cuentas son cuentas a la vista, y Fondos avisa qué script falta.

---

## Pestaña 1 — Saldos

Alimenta la fila **Saldo Inicial** (`DISPONIBLE`). El saldo del Cashflow es la suma de cuatro cosas:

| # | Qué | De dónde sale | Origen en pantalla |
| --- | --- | --- | --- |
| 1 | Efectivo de tesorería de casa central | `SBA05` en `central`, en vivo | Consulta |
| 2 | Bancos de Interbanking | `BI_T_SALDOS_INTERBANKING`, en vivo; respaldo manual si no trae el dato | Interbanking / Manual (respaldo) |
| 3 | Bancos sin Interbanking (hoy, BTG Uy) | Carga manual | Manual |
| 4 | Mercado Pago y otros | Carga manual | Manual |

La pestaña y el tablero leen las filas por **un solo camino**, `Saldos::getFilasDisponible()`, que junta las cargas (`getSaldosActuales()`) y las cuentas de Interbanking (`SaldosInterbanking`). Si cada uno las juntara por su lado, algún día no sumarían lo mismo. Hay una prueba, contra la base, de que el total de la pestaña es el Saldo Inicial del tablero.

### El efectivo central es un saldo puntual

```sql
SELECT COALESCE(SUM(CASE WHEN D_H = 'D' THEN MONTO ELSE -MONTO END), 0.00) AS SALDO
FROM SBA05
WHERE COD_CTA = ?
```

Devuelve **un solo número**: el acumulado de movimientos de esa cuenta hasta el momento en que se pregunta. No tiene fecha propia ni histórico. Por eso la fecha del dato es la de la consulta, y **el histórico lo construye este módulo**, guardando el valor en cada carga.

`COD_CTA` sale del parámetro `saldos_cta_tesoreria` y va **parametrizado** en la consulta: es un número de cuenta del plan contable y cambiarlo no puede requerir tocar código.

Ese saldo **no se acepta del navegador aunque esté en la pantalla**: al guardar, la clase lo vuelve a leer de `SBA05`. Es un dato del sistema, y tomarlo del cliente permitiría guardar cualquier cosa como si fuera lo que dice la contabilidad. Si la consulta falla en ese momento, la carga **no se guarda**: guardarla dejaría ese saldo en cero y el disponible quedaría informado de menos.

### Nueva carga: Mercado Pago, otros y bancos sin Interbanking

*Nueva carga* lista sólo el catálogo manual: el efectivo (que relee su consulta), Mercado Pago, los otros saldos y los bancos que no vienen por Interbanking. Las cuentas de Interbanking no son de la carga: no tienen ID en el catálogo, la pantalla no las manda y `guardarCargaSaldos()` sólo acepta cuentas del catálogo. Si una cuenta de Interbanking no trae el dato, su respaldo se carga desde su propia fila (ver *El respaldo manual*). La columna *Origen* dice de dónde salió cada saldo: Interbanking, Manual (respaldo), Manual o Consulta.

El KPI se llama **Última carga manual**, y su detalle dice "Mercado Pago, otros y bancos manuales": la fecha de los saldos bancarios está en cada fila y no en ese KPI. `saldos_dias_alerta_carga` también habla sólo de las cargas manuales.

### Monedas: dos totales, sin mezclar

Las cuentas pueden estar en pesos o en dólares. **La pantalla no convierte nada**: cierra con un total en pesos y otro en dólares, cada uno en su moneda.

La conversión existe **sólo** para lo que el proveedor le entrega al Cashflow, porque el contrato de `CashflowProvider` exige pesos. La hace `SaldosProvider` con `Class/Cotizacion.php`, que es el único punto de acceso al tipo de cambio del cashflow: valúa cada saldo con la cotización de **cierre del mes** de su fecha.

Un mes **sin cotización** no vale cero: esos dólares **no entran al tablero** y se avisa el importe en su moneda. Un cero se leería como "no hay dólares". Si la vista `RO_V_DOLAR_OFICIAL_BCRA` no existe en el entorno, pasa lo mismo y la pestaña sigue funcionando.

### Cada fila muestra su propia fecha de carga

Es la regla transversal del relevamiento y está en el modelo, no calculada a ojo. La pantalla no muestra "las filas de la última carga" sino **el último saldo de cada cuenta**, con la fecha de la carga en la que se registró. La diferencia importa cuando alguien da de alta una cuenta después de la última carga o cuando una carga quedó incompleta: con el otro criterio esas cuentas desaparecerían del cuadro o se verían en cero.

Una cuenta que **nunca se cargó** dice `sin cargar`, no `0`. No es lo mismo. Una cuenta de Interbanking sin dato se ve con guion, por el mismo motivo. En las de Interbanking, *Cargado el* es el `CREATED_AT` del registro, o el alta del respaldo.

### Filtro por tipo

El selector de la cabecera filtra la tabla por tipo de cuenta (`Banco`, `Mercado Pago`, `Efectivo`, `Otro`) y **los KPI *Total en Pesos* y *Total en Dólares*, y el pie de la tabla, se recalculan sobre lo filtrado** —el pie de cada KPI dice qué tipo está aplicado—. Es un filtro de pantalla, resuelto en el navegador con las filas que ya trajo el payload: lo que se guarda y lo que consume el tablero es siempre el conjunto completo.

Mientras dura una **carga**, el filtro se limpia y se bloquea: el guardado toma el input de cada fila, y una fila escondida por el filtro no tendría input, así que su saldo viajaría en cero.

---

## Los saldos de Interbanking

Un proceso externo a este repo trae todos los días, por cuenta, lo que informa Interbanking y lo deja en `BI_T_SALDOS_INTERBANKING` (`central`). `Class/SaldosInterbanking.php`, al estilo de `Fondos.php`, lo lee **en vivo** cada vez que se dibuja la pestaña o se calcula el tablero, igual que `SBA05`. **No se copia** a `RO_T_CASHFLOW_SALDOS_DETALLE` ni usa las cargas: la tabla de BI ya tiene el histórico, y dos copias del mismo dato terminan discrepando.

Las reglas son helpers estáticos y puros, con hoy inyectado: `elegirRegistro()`, `nombreBanco()`, `resolverSaldo()`, `armarCuentasBancarias()`, `armarBancos()`, `esNueva()`, `resolverBancos()`, `validarAlias()` y `validarRespaldo()`. Las lecturas sólo juntan datos.

### Una fila por cuenta, con el último saldo contable

- **La cuenta es `NRO_BANCO` + `NRO_CUENTA` + `MONEDA`.** Una cuenta que llega **sin moneda** no se toma y el aviso es crítico: asumir pesos sería inventar el dato.
- **El importe es `SALDO_CONTABLE`.** Los otros siete saldos de la tabla no se muestran ni se usan.
- **Se toma el último registro con contable no nulo**, por `FECHA_OPERACION`, después `CREATED_AT` (un nulo ordena como el más viejo) y después `ID`, todos descendentes. Nada impide dos registros del mismo día, y sin desempate la cuenta mostraría uno u otro.
- **Un registro nuevo sin contable no tapa al anterior.** Se usa el último no nulo, con aviso de atención: *"el registro del 08/10 vino sin saldo contable; se muestra el del 07/10"*.
- **Una cuenta que nunca trajo contable**, y sin respaldo, se ve **sin dato** (guion, nunca cero), no suma, y el aviso es **crítico**: es plata que existe y el disponible no incluye.

**La consulta es la única con lógica, y va contra la tabla de BI sola**, sin JOIN. Usa dos `ROW_NUMBER()` y trae como máximo dos registros por cuenta: el más nuevo de todos y el más nuevo con contable. La elección final la vuelve a hacer `elegirRegistro()`, y la prueba se hace sobre él.

**Si la tabla de BI no existe o falla la lectura**, no hay filas de Interbanking y va un aviso crítico: *"no se pudieron leer los saldos bancarios de Interbanking; el disponible no los incluye"*. El efectivo, Mercado Pago, los bancos manuales y los respaldos siguen entrando.

### El nombre: alias o Tango

Cada cuenta se ve como **`Alias o DESC_BANCO · N° cuenta`**. El nombre sale de `BANCO.DESC_BANCO` de Tango, cruzado **en PHP** y con trim: en Tango `NRO_BANCO` es `char(3)` y en BI `varchar(10)`. Tango tiene razones sociales y nombres viejos (`RIO DE LA PLATA S.A.` es Santander, `FRANCES` es BBVA), así que **el alias del banco manda** y lo muestran todas sus cuentas. Un banco que no está en Tango se ve `Banco <nro>`, con un aviso de atención que desaparece al ponerle alias.

### Inhabilitar un banco

`RO_T_CASHFLOW_SALDOS_BANCO` guarda alias y estado por `NRO_BANCO`. Un banco **inactivo** no sale en la pestaña, no suma al tablero, no usa su respaldo, no admite cargarlo y **no avisa nada**: ni cuentas nuevas, ni saldo viejo, ni saldo faltante. Es para un banco que ya no se opera (hoy, Supervielle). En Parámetros se sigue viendo, atenuado, para reactivarlo.

**Sin fila es activo y sin alias.** La lectura nunca escribe: un banco que aparece por primera vez en Interbanking entra al disponible sin que nadie lo dé de alta. La fila se crea, con `UPSERT`, cuando alguien guarda alias o estado.

### El saldo que no es de hoy

**La regla es estricta.** El proceso de BI integra todos los días, así que un saldo anterior a hoy se marca, **también a la mañana antes de que corra el proceso y los fines de semana**. No hay tolerancia ni parámetro de hora: escondería justo el día en que el proceso no corrió. Vale igual para un respaldo.

- **En la pestaña**, la fila se resalta como *"no es de ayer"* de Saldos Locales, dice **no es de hoy** debajo de la fecha, y un aviso de atención lista las cuentas con su fecha.
- **En el tablero**, el importe va a la primera columna, la apertura del horizonte, como siempre con `destinoEnEje()`. `armarSerieDisponible()` avisa en **WARNING**, sección *Saldo Inicial*, cuántas cuentas y qué importe no son del día y de qué fecha es el más viejo.

Cada fila lleva su **origen**, y eso decide el aviso. Lo que sale de una carga manual y quedó viejo conserva el aviso crítico *"Actualizá la carga de saldos"*: alguien tiene que hacerla. Lo de Interbanking y los respaldos (`SaldosInterbanking::ORIGENES_DEL_DIA`) no tiene carga que actualizar, y por eso es atención.

### El respaldo manual

Cuando Interbanking no trae el saldo contable de una cuenta por un error de la integración (hoy, 014 Provincia), se carga a mano desde su fila en Saldos › Saldos, con el ícono de saldo manual. El diálogo pide la fecha del saldo (no posterior a hoy), el importe (obligatorio, puede ser negativo) y una observación. Muestra el respaldo vigente, si lo hay, para reemplazarlo o quitarlo. El permiso es el de *Nueva carga*, `['saldos', null]`.

- **Gana la fecha más nueva y, a igual fecha, Interbanking**, que es la fuente oficial (`resolverSaldo()`). Cuando BI se arregla, Interbanking vuelve a mandar solo, sin quitar el respaldo. Es la regla inversa a la caja de los locales, donde gana el manual, porque acá el manual no corrige un dato: tapa uno que falta.
- **Historial sin bajas físicas.** Reemplazar da de baja el vigente (`VIGENTE = 0`, con usuario y fecha de baja) e inserta el nuevo con `ID_REEMPLAZA`, en una transacción. Quitar da de baja. Un índice único filtrado deja **un solo respaldo vigente por cuenta**.
- **Las cuentas son las de Interbanking más las que tienen respaldo vigente.** Con la lectura caída, o con una cuenta que BI dejó de traer, el respaldo sigue entrando: existe justamente para eso.
- Mientras se usa, la falla se acusa en **atención**: *"Interbanking no trae el saldo contable de <banco · cuenta>; se usa la carga manual del dd/mm"*. El origen dice "Manual (respaldo)", con un tooltip que cuenta quién lo cargó, cuándo, la observación y qué trae Interbanking.
- El servidor rechaza un respaldo de un banco inactivo, de una cuenta que no viene en Interbanking ni tiene respaldo, y uno cargado antes de la depuración.

### Las cuentas nuevas

Una cuenta nueva es una cuenta de Interbanking que **nadie marcó como vista** (`RO_T_CASHFLOW_SALDOS_BANCO_CUENTA`). La fila existe sólo si la cuenta se vio, así que su alta es quién y cuándo la marcó. Una cuenta nueva **entra al tablero** como cualquier otra de un banco activo, porque es plata que existe. En la pestaña lleva la marca **nueva**, y un aviso de atención en la pestaña y en el tablero dice banco, cuenta e importe: *"cuenta nueva en Interbanking; revisala en Parámetros › Saldos"*.

- Deja de ser nueva cuando alguien la marca como vista en Parámetros.
- Un banco inactivo no marca ni avisa.
- Una cuenta que sólo existe por su respaldo no es nueva.
- Sin el script no se marca ninguna.

La regla es una sola, `esNueva()`, y la usan la pestaña y Parámetros.

### Los bancos que no vienen por Interbanking

Siguen siendo cuentas `TIPO = 'BANCO'` del catálogo, se cargan en *Nueva carga*, conservan su histórico y entran por `getSaldosActuales()` con su moneda y su cotización. Hoy es BTG Uy, en USD. `addCuenta()` sigue aceptando `BANCO`, pero el catálogo no tiene `NRO_BANCO`, así que el servidor no puede saber si el banco que se da de alta ya viene por Interbanking. El alta responde con una advertencia (`Saldos::advertenciaAltaCuenta()`) que Parámetros muestra.

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
| Acreditación / envío | Próxima fecha en que el local acredita (o envía) su efectivo: `Lun · 12/10`, o `sin día` | no: el día se elige en Parámetros |
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

### La fecha de imputación: el día de acreditación de cada local

**Lo que aporta un local en *Deposita* se imputa en su próxima fecha de acreditación**, no en la fecha del saldo. La fecha del saldo dice cuándo se *contó* la plata; lo que el tablero necesita saber es cuándo *llega al banco*, y eso pasa el día de la semana en que el local deposita. Cada local va en su fecha, así que la fila *Caja Locales* se reparte en varias columnas.

> Hasta `feature/saldos-locales-dia-acreditacion` la regla era *"la fecha del saldo, sin corrimientos"*, y como el último saldo es el de ayer, en la práctica todo caía en la primera columna. **Esa regla dejó de valer para esta serie.** Sigue valiendo para un local sin día (ver abajo).

El día se carga por local, de lunes (1) a viernes (5), en **Parámetros → Saldos → Locales**, y sólo ahí. La regla es una sola, `Saldos::proximaFechaAcreditacion($dia, $hoy, $habiles)`, estática y pura:

1. **La primera vez que ese día de la semana cae hoy o después.** Si hoy es ese día, la fecha es **hoy**.
2. **Si esa fecha no es hábil, se corre al hábil siguiente.** Va hacia adelante porque es una acreditación y no un pago: el banco no acredita un día que no opera. Es la misma dirección que Ventas y la contraria de `CronogramaPagos`, que adelanta los pagos.

| Hoy | Día cargado | Próxima fecha | Por qué |
| --- | --- | --- | --- |
| mié 07/10 | miércoles | mié 07/10 | hoy es el día |
| mié 07/10 | martes | mar 13/10 | el martes de esta semana ya pasó |
| mié 07/10 | lunes | mar 13/10 | el lunes 12/10 es feriado: pasa al martes |
| lun 12/10 (feriado) | lunes | mar 13/10 | hoy es el día, pero hoy no se acredita |
| mié 30/12 | viernes | lun 04/01/2027 | el viernes 1/1 es feriado, y el fin de semana tampoco es hábil |

El corrimiento es `DiasHabiles::siguiente()`, **el mismo que usan Ventas y el vencimiento de las tarjetas**, con el mismo respaldo: una fecha que no está en `RO_T_CALENDARIO` se toma hábil de lunes a viernes y se avisa el mes que falta. El calendario se lee por `CronogramaDatos::habilesEntre()`, que pasa por `Ventas::getDiasHabiles()`, la única lectura de `RO_T_CALENDARIO` del módulo. Si no se puede leer, el aviso es crítico y vale el mismo respaldo. Dos calendarios con dos respaldos terminarían en fechas que no coinciden.

**La pestaña, el tablero y la foto calculan la fecha por el mismo camino.** `Saldos::contextoAcreditacion($hoy)` resuelve hoy y el calendario, y `armarSaldosLocales()` le pone a cada fila su `acreditacion`: día, fecha teórica y final, si se corrió, la etiqueta y el tooltip. `armarSerieLocales()` no la vuelve a calcular: toma la que trae la fila.

**Lo que no cambia:** el neto, la reserva, el saldo manual y su precedencia. Sólo cambia la columna en la que cae el importe, **así que el total de la serie es el mismo**. Medido contra la base el 07/10/2026, con el código anterior y el nuevo uno detrás del otro: $ 4.836.469,00 en los dos casos. Sin días cargados, todo sigue en el 07/10. Con días simulados por local y el calendario real, el mismo total se reparte en 07/10 $ 1.240.314,00, 08/10 $ 1.358.580,00 y 13/10 $ 2.237.575,00.

**Casos que siguen como antes:**

- **Local en *Deposita* sin día:** se imputa como hasta ahora, en la fecha del saldo, y si es anterior al eje, en la primera columna (ver la sección siguiente). Además, un aviso de atención en la pestaña y en el tablero lista **todos** los locales en *Deposita* sin día, aporten o no: el dato falta igual, y un local que hoy tiene la caja bajo la reserva mañana puede aportar.
- **Local en *Envía*:** sigue sin aportar. Su día es el de **envío** y es informativo; la pestaña lo muestra igual, con un tooltip que lo aclara.
- **Saldo sin fecha:** va a `sin_fecha` aunque el local tenga día. Imputarlo ahora cambiaría el total, y esta regla no lo toca.
- **Fecha de acreditación fuera del eje:** queda en `fuera_horizonte` y el motor la informa, como cualquier otra fecha.
- **Un calendario sin ningún hábil en 30 días:** la fecha es `null`, nunca la del tope. El local se imputa como sin día, con un aviso crítico.

**Sin `sql/cashflow_saldos_dia_acreditacion.sql`** todo funciona como antes, con un aviso de atención en la pestaña, en Parámetros y en el tablero.

En la pantalla, la celda dice `Lun · 12/10`. Se muestra el día en que **entra la plata**, no el cargado. Si la fecha se corrió, la celda dice *corrida por feriado* debajo, y el tooltip: *"El lunes 12/10 es feriado: pasa al martes 13/10."*. Sin día dice `sin día`, resaltado en ámbar: es atención y no error, porque se arregla cargando el día. El tooltip lo arma el backend (`explicarAcreditacion()`) para las dos gestiones, así sigue a la que haya en pantalla si alguien la cambia antes de guardar.

### Un saldo con fecha anterior a hoy se imputa en la primera columna

Vale para los locales **sin día de acreditación**; los que tienen día van a su fecha (ver arriba).

El eje del tablero arranca hoy, y en la práctica **el último saldo que Tango tiene registrado es el de ayer**: la consulta no devuelve depósitos, devuelve el **saldo de caja** de cada local. Esa plata sigue en el cajón y todavía no llegó al banco, así que se imputa en la apertura del horizonte, con un aviso que dice de qué fecha es el saldo.

Descartarla mostraba la fila en cero justo cuando había millones para depositar, y el importe **no aparecía en ningún otro lado del tablero**: el saldo bancario de la Pestaña 1 recién lo va a mostrar cuando se acredite.

> **Reubicar una fecha pasada no es correr una fecha.** Acá se trata una fecha que **no tiene columna** porque ya pasó. Es la misma regla que aplica el disponible inicial, y vive en un solo lugar: `Saldos::destinoEnEje()`. Las dos series describen **plata que existe ahora** —un saldo bancario, el efectivo de un cajón—, no movimientos ya ocurridos, así que una fecha pasada significa "esto ya es cierto hoy". El único corrimiento por feriado del módulo es el de la acreditación, y lo hace `proximaFechaAcreditacion()`, no esta regla.

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

`TIPO` ya existía y dice **de dónde sale** el saldo (`BANCO`, `MERCADO_PAGO`, `EFECTIVO_CENTRAL`, `OTRO`): decide el origen del dato, el filtro de la pestaña 1, y es inmutable. Un `BANCO` del catálogo es un banco **sin** Interbanking: los que vienen por Interbanking no están en el catálogo. `CLASE` es nueva y dice **qué es** la cuenta:

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

**No se registra contrapartida bancaria.** Un rescate saca plata del fondo y nada más: lo que entra al banco se ve en el saldo bancario, que trae Interbanking. Registrarla acá sería adelantar un dato que otro circuito ya va a medir, y las dos cifras podrían discrepar.

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

Las tablas llevan el prefijo `RO_T_CASHFLOW_SALDOS_`. Las de las cargas cumplen dos propiedades:

| Propiedad | Cómo |
| --- | --- |
| **El histórico no se pisa** | El detalle cuelga de `ID_CARGA` y no tiene clave `(cuenta, fecha)` que se sobrescriba |
| **"Última carga" tiene respuesta única** | La resuelve la cabecera, con desempate por `ID` |

Los saldos de Interbanking **no tienen tabla propia**: se leen de `BI_T_SALDOS_INTERBANKING`. Lo que guarda el módulo sobre ellos es lo que BI no sabe: cómo se llama cada banco, si se opera, qué cuentas ya se vieron y el respaldo manual.

| Tabla | Qué guarda |
| --- | --- |
| `RO_T_CASHFLOW_SALDOS_CUENTA` | Catálogo de cuentas manuales (parámetro): tipo, **clase**, moneda, origen y **saldo inicial con su fecha** (sólo fondos). Los siete campos de `/accounts` quedan **en desuso** (ver abajo) |
| `RO_T_CASHFLOW_SALDOS_CARGA` | Cabecera de cada carga: tipo, fecha y hora, usuario, origen, observaciones |
| `RO_T_CASHFLOW_SALDOS_DETALLE` | Histórico de saldos por cuenta y fecha. De los cinco `balances` y el `message` sólo se usa `COUNTABLE_BALANCE`; el resto, en desuso |
| `RO_T_CASHFLOW_SALDOS_BANCO` | Alias y estado de cada banco de Interbanking, por `NRO_BANCO`. Sin fila: activo y sin alias |
| `RO_T_CASHFLOW_SALDOS_BANCO_MANUAL` | Respaldo manual de una cuenta de Interbanking que no trae el dato. `VIGENTE`, `ID_REEMPLAZA` y un único vigente por cuenta |
| `RO_T_CASHFLOW_SALDOS_BANCO_CUENTA` | Las cuentas de Interbanking marcadas como vistas; la que no está es nueva |
| `RO_T_CASHFLOW_SALDOS_DEPURACION` | La constancia del borrado de las cuentas bancarias manuales. Sin ella el código no lee Interbanking |
| `RO_T_CASHFLOW_SALDOS_SUCURSAL` | Gestión, reserva y **día de acreditación** por local (parámetro). El día es `NULL` si no se cargó: no tiene default |
| `RO_T_CASHFLOW_SALDOS_LOCAL` | Histórico de la caja de los locales, con la gestión, la reserva, el día y la fecha de acreditación **efectivos** y `ORIGEN_DATO` del saldo |
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

Por el mismo motivo guarda `DIA_ACREDITACION` y `FECHA_ACREDITACION`: el día efectivo y la fecha calculada con la que ese local entró al tablero el día de la carga. Si después alguien cambia el día, la carga vieja sigue diciendo en qué columna estaba. Las cargas de antes del script quedan en `NULL`, igual que un local sin día: no se sabe qué día tenían, y un valor inventado diría que sí.

Lo mismo con `MONEDA` en el detalle de saldos: se copia de la cuenta en el momento de la carga y no se lee por `JOIN`, para que corregir la moneda de una cuenta no reescriba el significado del histórico.

### Qué guarda el botón *Guardar* de la Pestaña 2

**Las dos cosas, en una sola transacción:**

1. **El parámetro** (`RO_T_CASHFLOW_SALDOS_SUCURSAL`), con la gestión y la reserva que quedaron en pantalla. Es el mismo dato que se edita en Parámetros → Saldos: un solo lugar, editable desde los dos lados. **Es lo que el tablero va a usar de ahí en adelante.** El **día de acreditación no** viaja desde esta pantalla —es de sólo lectura acá— y el guardado no lo toca: `resolverOverrides()` pisa sólo gestión y reserva y conserva el resto de la fila.
2. **La foto** (`RO_T_CASHFLOW_SALDOS_LOCAL`), con la gestión, la reserva, el día y la fecha de acreditación efectivos de esa carga.

El **saldo no viaja desde el navegador**: al guardar, el servidor vuelve a correr la consulta y toma de ahí el saldo y la fecha. Del cliente se aceptan únicamente los dos valores editables. Si el saldo viniera del cliente, se podría grabar un número inventado como si fuera lo que dice Tango.

> **Antes sólo se guardaba la foto, y eso hacía que editar la reserva en esta pantalla no sirviera para nada**: no persistía —al recargar volvía el valor viejo— y el tablero no la veía, porque `SaldosProvider` lee el parámetro vigente y no la última carga. Los campos editables eran un simulador disfrazado de formulario.

**Sólo se escriben las sucursales que cambiaron.** La pantalla manda las veinte en cada guardado; sin el diff (`Saldos::resolverOverrides()`), cada guardado les pisaría `FECHA_UPDATE` y `USUARIO` a todas y la columna *Última edición* de Parámetros dejaría de significar algo. La reserva se compara con tolerancia: la columna es `DECIMAL(19,4)` y el valor da la vuelta por JSON y por un input numérico, así que una comparación estricta reportaría cambios que no existen.

Las dos escrituras van en la **misma transacción**: separadas, una falla a mitad de camino dejaría la reserva cambiada sin la foto que la explica, o al revés. Por eso el parámetro se escribe con `guardarSucursalEnTransaccion()` y el `$cid` de la transacción —mismo criterio que `CashflowEstructura::guardar()`—. Es el **único escritor del parámetro**: lo usa también *Guardar locales* de Parámetros.

Editar una sucursal que la consulta devuelve pero que **nunca se sincronizó** le crea la fila de parámetro (`UPSERT`). La alternativa sería que el `UPDATE` no afectara ninguna fila y la edición se perdiera en silencio.

---

## Mapeo campo por campo contra `BI_T_SALDOS_INTERBANKING`

El cashflow **no llama a la API de Interbanking**. La llama un proceso externo a este repo, que deja una fila por cuenta y por día de operación en `BI_T_SALDOS_INTERBANKING` (`central`, todas las columnas de texto en `Modern_Spanish_CI_AI`). El módulo sólo lee esa tabla.

| Columna de BI | Qué hace el módulo |
| --- | --- |
| `NRO_BANCO` | Identifica el banco. Se cruza con `BANCO.NRO_BANCO` de Tango (`char(3)`) en PHP, con trim, para el nombre. Es la clave de `RO_T_CASHFLOW_SALDOS_BANCO` |
| `NRO_CUENTA` | Con `NRO_BANCO` y `MONEDA`, identifica la cuenta. Se muestra junto al nombre del banco |
| `MONEDA` | Parte de la clave. Hoy todas vienen en `ARS`. Una cuenta sin moneda no se toma (crítico) |
| `TIPO_CUENTA` | Se muestra en Parámetros (`CC` → Cta. Cte., `CA` → Caja de Ahorro). Hoy todas vienen en `CC` |
| `FECHA_OPERACION` | La fecha del saldo, y el primer criterio para elegir el registro |
| `SALDO_CONTABLE` | **El importe.** El último no nulo de cada cuenta |
| `CREATED_AT` | *Cargado el*, y el segundo criterio de desempate |
| `ID` | El tercer criterio de desempate |
| `LABEL_CUENTA`, `NOMBRE_CUENTA` | No se usan: el nombre sale del alias o de Tango |
| `SALDO_OPERATIVO_INICIAL`, `SALDO_OPERATIVO_ACTUAL`, `SALDO_PROYECTADO_24HS`, `SALDO_PROYECTADO_48HS`, `SALDO_DIA`, `TOTAL_DEBITOS`, `TOTAL_CREDITOS` | No se muestran ni se usan |

**La tabla de BI no se escribe nunca**, ni para sembrar ni para marcar. Lo propio del módulo va en sus tablas, con la misma clave y la misma collation que el origen, para que el dato compare igual de los dos lados.

### Las columnas de la API, en desuso

Cuando se diseñó el módulo, la idea era que el cashflow llamara a la API y guardara lo que trajera. Por eso `RO_T_CASHFLOW_SALDOS_CUENTA` tiene los campos de `/accounts` (`BANK_ID`, `BANK_NAME`, `ACCOUNT_NUMBER`, `ACCOUNT_TYPE`, `CBU` con su índice único filtrado, `ACCOUNT_LABEL`) y `RO_T_CASHFLOW_SALDOS_DETALLE` tiene los de `/accounts/balances` y `historical_balances` (`INITIAL_OPERATING_BALANCE`, `CURRENT_OPERATING_BALANCE`, `PROJECTED_BALANCE_24HS`, `PROJECTED_BALANCE_48HS`, `DAY_BALANCE`, `TOTAL_DEBITS`, `TOTAL_CREDITS`, `MESSAGE`). **Quedan en desuso**, siempre en `NULL`: los bancos de Interbanking no están en el catálogo ni en el detalle. No se borran, y el código las sigue leyendo sin usarlas. `COUNTABLE_BALANCE` sí se usa: es el saldo de las cargas manuales.

`ORIGEN_DATO = 'API'` también queda sin uso: ninguna cuenta del catálogo viene de Interbanking.

---

## Qué queda pendiente

- **014 Provincia no trae saldo contable** por un error de la integración del lado de BI. Hasta que se resuelva se cubre con el respaldo manual. Cuando BI lo traiga con fecha igual o más nueva, manda solo.
- **Las columnas de la API en desuso** se pueden borrar con un script aparte, cuando se confirme que nada más las lee.
- **`BI_T_SALDOS_INTERBANKING` sólo tiene índice por `ID`.** La consulta del último saldo recorre la tabla entera, que hoy son decenas de filas y crece unas diez por día. Si algún día pesa, el índice que sirve es `(NRO_BANCO, NRO_CUENTA, MONEDA, FECHA_OPERACION DESC, CREATED_AT DESC, ID DESC) INCLUDE (SALDO_CONTABLE, TIPO_CUENTA)`. La tabla es del proceso de BI, así que lo crea quien la administra.

---

## El proveedor

`Class/Providers/SaldosProvider.php`, registrado bajo **dos** códigos, igual que `ComexProvider`:

| Código | Serie | Qué devuelve |
| --- | --- | --- |
| `SALDOS` | `DISPONIBLE` | Último saldo conocido de cada cuenta —las de las cargas y las de Interbanking, por `Saldos::getFilasDisponible()`—, convertido a pesos |
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

El aviso del disponible depende del **origen** de cada fila. Lo que sale de una carga manual y quedó viejo es crítico: *"Actualizá la carga de saldos"*. Lo de Interbanking y los respaldos es atención: cuántas cuentas, qué importe y la fecha más vieja. Ver *El saldo que no es de hoy*.

### El enlace del tablero abre la sub-pestaña correcta

La fila *Caja Locales* la produce la **segunda** sub-pestaña, así que su entrada del registro declara `'subtab' => 'locales'` además de `'tab' => 'saldos'`. El motor lo pasa en la fila, `Cashflow.js` lo deja en `window.cfSubTabDestino` antes de navegar y `Saldos.js` lo consume al arrancar.

El tablero **no** activa la sub-pestaña por su cuenta: `loadTab()` carga por AJAX y no avisa cuándo terminó, así que en el momento del click el destino todavía no existe en el DOM. Se consume una sola vez, para que un cambio de pestaña posterior no vuelva a saltar ahí.

### Nunca tumba el tablero

`calcular()` puede lanzar y `series()` lo envuelve. Además cada serie atrapa sus propios problemas para poder rendir cero **con un aviso que diga qué pasó**, en lugar del mensaje genérico de la clase base: tablas sin crear, servidor de locales caído, Interbanking que no se pudo leer, la depuración de los bancos manuales sin correr, ninguna cuenta cargada todavía, cuentas cargadas cuyo neto no supera la reserva. Los avisos de Interbanking llegan ya resueltos, con su nivel y la sección *Saldo Inicial*. Una cuenta de Interbanking sin dato no se cuenta como "sin cargar": ya tiene su aviso crítico.

`moneda_origen`, `tipo_cambio`, `fuera_horizonte` y `sin_fecha` se devuelven con valores reales. `moneda_origen` es `'ARS'`: la serie **está** en pesos y el enum del contrato sólo admite dos valores, así que el detalle de qué parte vino en dólares y con qué cotización va en `tipo_cambio` y en los avisos.

---

## Parámetros

Sub-pestaña **Parámetros → Saldos**, con seis secciones: Generales, Bancos de Interbanking, Bancos sin Interbanking, Otros saldos, Fondos y Locales.

| Clave | Semilla | Qué controla |
| --- | --- | --- |
| `saldos_cta_tesoreria` | `100101` | Cuenta contable de `SBA05` con el efectivo de tesorería |
| `saldos_dias_alerta_carga` | `7` | Días desde la última carga **manual** a partir de los cuales se avisa que esos saldos no son los de hoy. No aplica a Interbanking, que tiene su propia regla: el saldo es del día o no |

**Bancos de Interbanking** no es un ABM: es la lista de los bancos que trae Interbanking, más los que tienen fila en `RO_T_CASHFLOW_SALDOS_BANCO` aunque ya no vengan (`SaldosInterbanking::armarBancos()`).

- **Columnas:** N° de banco, nombre en Tango (o *no está en Tango*), alias editable, cuentas (número, tipo y moneda, con las marcas **nueva** y **con respaldo manual**), último dato, activo y última edición.
- ***Guardar bancos*** escribe sólo los que cambiaron, en una transacción y con `UPSERT`, con el mismo criterio de diff que *Guardar locales* (`resolverBancos()`). Un alias vacío se guarda `NULL`; uno de más de 100 caracteres lo rechaza el servidor.
- **Inactivar un banco con respaldo vigente** pide confirmación: el respaldo deja de usarse, pero no se borra.
- **Cada banco con cuentas nuevas** tiene *Marcar como vistas*, con confirmación. Las cuentas las vuelve a leer el servidor de Interbanking: del navegador sólo viaja el banco.
- Los inactivos se ven atenuados.
- Sin `sql/cashflow_saldos_interbanking.sql` los controles quedan apagados, con el aviso de qué correr.
- Sin la depuración, el aviso dice que Interbanking todavía no se lee.

**Bancos sin Interbanking**, **Otros saldos** y **Fondos de inversión y cuentas comitente** son el mismo ABM sobre `RO_T_CASHFLOW_SALDOS_CUENTA`: los fondos se separan por `CLASE` y el resto por `TIPO`. Nombre, moneda y clase (dentro del grupo) son editables; el **tipo no**, porque es lo que decide de dónde sale el saldo y cambiarlo dejaría el histórico atribuido a un origen que nunca lo produjo. Si quedó mal, se inhabilita y se crea otra. Los fondos llevan además el **saldo inicial con su fecha**, que se fija en el alta y se corrige ahí mismo; van los dos o ninguno, y la moneda de un fondo con movimientos no se cambia. Cada botón *Guardar* manda **su** grilla: apretar *Guardar fondos* no manda los bancos que uno estaba editando a medias. Por eso *Guardar cuentas* vive en la tarjeta de Otros saldos, y los bancos manuales tienen el suyo. El bloque de bancos sin Interbanking es para los que no vienen por la integración (hoy, BTG Uy): el catálogo no tiene `NRO_BANCO` para comprobarlo, así que el alta de un banco responde con una advertencia.

Sin `sql/cashflow_saldos_cuentas_fondo.sql`, los selectores de clase y el alta de fondos quedan apagados diciendo qué script falta, y el resto del ABM funciona como antes.

> Una cuenta nueva **entra activa**, a diferencia de un medio de pago del mix. No es una inconsistencia: un medio de pago nuevo rompe el 100 % de su canal, así que tiene que entrar apagado. Una cuenta no rompe ningún invariante y nace **sin saldo cargado**, que la pantalla muestra como `sin cargar` y no como cero, así que no puede informar de menos en silencio.

**Locales** trae la lista desde `SUCURSALES_LAKERS` con el botón *Sincronizar con locales*. La sincronización **nunca pisa `GESTION`, `RESERVA` ni `DIA_ACREDITACION`** —son valores que cargó una persona—, un local nuevo entra **sin día** y a las sucursales que desaparecen del origen las marca `ACTIVO = 0` en lugar de borrarlas. Que ninguna sentencia de la sincronización nombre el día lo fija una prueba sobre el código.

La grilla tiene la columna **Día de acreditación / envío**, con un selector *— sin día —* / lunes a viernes. **Es el único lugar donde se edita.** En *Deposita* decide en qué columna del tablero cae el local; en *Envía* es el día de envío y es informativo. Sin el script el selector aparece apagado, con el aviso de qué falta. Si igual llega un día al servidor, se descarta **sólo ese dato**: la gestión y la reserva se guardan, y la respuesta dice qué script correr. La validación que vale es la del servidor (`Saldos::validarDiaAcreditacion()`: 1 a 5, o vacío para *sin día*), y el `CHECK` de la columna es la tercera red.

**Guardar locales escribe sólo los locales que cambiaron, en una transacción** (`Saldos::guardarParametrosLocales()`, con el diff puro `resolverParametrosLocales()`). Antes cada fila era un `UPDATE` con su propia conexión: cada guardado sellaba a los veinte locales como editados por quien apretó el botón, y una falla a mitad de camino dejaba la mitad guardada. Un día ausente del pedido no se toca; uno vacío lo borra.

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

Corre `tests/test_saldos.php`, `tests/test_saldos_acreditacion.php` y `tests/test_saldos_interbanking.php` (464 casos entre los tres), todos sin base salvo la última sección de cada uno, que se saltea sola. Los criterios viven en **helpers estáticos puros**, al estilo de `Ventas::armarTendencias()`: lo delicado de este módulo no son las consultas sino las decisiones. Hoy se inyecta, así que las pruebas no caducan.

| Qué se verifica | Helper |
| --- | --- |
| Sólo las sucursales en `Deposita` aportan; las de `Envía` quedan fuera | `armarSaldosLocales()` |
| Un neto negativo aporta cero, no negativo | `armarSaldosLocales()` |
| El neto es saldo menos reserva, sin ajuste impositivo | `armarSaldosLocales()` |
| Un local **sin día** se imputa en la fecha del saldo, sin corrimientos (ni de fin de semana) | `armarSerieLocales()` |
| La próxima fecha: hoy es el día; hoy es el día y es feriado; el día cae feriado; feriados encadenados; cruce de mes y de año; fecha fuera del calendario; sin calendario; mapa sin hábiles; sin día | `proximaFechaAcreditacion()` |
| El día se valida en el servidor: 1 a 5, o vacío | `validarDiaAcreditacion()` |
| La celda (`Mar · 13/10`, `sin día`) y el tooltip, para Deposita y para Envía | `etiquetaAcreditacion()`, `explicarAcreditacion()` |
| Cada fila trae su fecha; los locales en Deposita sin día se avisan todos, también el que aporta cero; Envía sin día no; el mes sin calendario se avisa una vez; sin el script, nada cambia | `armarSaldosLocales()` |
| Dos locales con días distintos caen en dos columnas; el sin día, en la primera; Envía no aporta; **el total es el mismo que sin días**; un saldo sin fecha va a `sin_fecha`; una fecha fuera del eje, a `fuera_horizonte` | `armarSerieLocales()` |
| Guardar locales escribe sólo lo que cambió; un día ausente no se toca y uno vacío lo borra; sin el script se ignora sólo el día | `resolverParametrosLocales()` |
| La pestaña 2 no le borra el día a nadie | `resolverOverrides()` |
| La sincronización no nombra el día; la foto guarda día y fecha sólo con el script | sobre el código |
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

El día de acreditación se verificó además contra la base viva el 07/10/2026, sin tocar tablas permanentes. Se usó una copia de `Saldos` apuntada a `#temporales` clonadas de las seis tablas del módulo, en una sola conexión. Sin las columnas, *Guardar locales* guarda la reserva, ignora el día y lo informa. Con ellas:
- sólo los locales tocados quedan sellados;
- un día 6 se rechaza antes de escribir;
- la pestaña 2 cambia una reserva sin borrarle el día a nadie;
- la foto trae el día y la misma fecha que la regla;
- sincronizar no pisa los días.

Las tablas `dbo` quedaron iguales. El total de Caja Locales, antes y después, está en *La fecha de imputación*.

Verificado además contra la base real: la pestaña marcó los 3 locales sin cierre del 13/09 y, tras cargarlos a mano desde la pantalla, la cabecera quedó `MIXTA`, la foto con 17 filas `CONSULTA` + 3 `MANUAL`, y la pestaña y el tablero dejaron de avisar. También: el script corrido dos veces sin duplicar, la consulta de `SBA05`, y un alta de cuenta + carga + lectura por el proveedor que dejó el importe en una sola columna del eje.

### Interbanking

```bash
php cashflow/tests/run.php interbanking
```

`tests/test_saldos_interbanking.php`, 209 casos sin base salvo el último, que se saltea solo. El cableado de las pantallas está en `tests/test_tablas_controles.php`.

| Qué se verifica | Helper |
| --- | --- |
| El registro de hoy; sin registro de hoy, el último con su fecha; dos del mismo día desempatados por `CREATED_AT` y por `ID`; un `CREATED_AT` nulo ordena como el más viejo | `elegirRegistro()` |
| El más nuevo sin contable toma el anterior, con aviso de atención; sin ningún contable ni respaldo, sin dato (null), no aporta y aviso crítico; un cero es un dato | `elegirRegistro()`, `armarCuentasBancarias()` |
| Alias, `DESC_BANCO` sin espacios, banco fuera de Tango con aviso (que se va con alias), trim de `NRO_BANCO` | `nombreBanco()`, `armarCuentasBancarias()` |
| Una cuenta sin moneda no se toma y avisa en crítico | `armarCuentasBancarias()` |
| Un banco inactivo no aporta, no se lista y no avisa nada; sin fila es activo | `bancoActivo()`, `armarCuentasBancarias()` |
| "No es de hoy" estricto, también un sábado; sin dato no se marca; el aviso de la pestaña lista cada cuenta con su fecha | `noEsDeHoy()`, `avisoNoEsDeHoy()` |
| Un saldo de hoy va a su columna; uno viejo de Interbanking o de respaldo, a la primera con WARNING y no DANGER; lo manual conserva su crítico; una fila sin origen es una carga | `armarSerieDisponible()` |
| Un banco manual en USD entra con su cotización y la pestaña lo muestra en dólares | `armarSerieDisponible()`, `totalesPorMoneda()` |
| Respaldo: sin Interbanking se usa, con aviso de atención; el más nuevo gana a un Interbanking viejo; uno más nuevo de Interbanking gana; a igual fecha gana Interbanking; un banco inactivo no lo usa; con la lectura caída entra igual y con su nombre de Tango; "no es de hoy" le aplica | `resolverSaldo()`, `armarCuentasBancarias()` |
| Respaldo: fecha futura, importe vacío o no numérico, fecha inválida, observación larga y cuenta vacía se rechazan; un negativo se acepta | `validarRespaldo()` |
| Respaldo: reemplazar da de baja e inserta con `ID_REEMPLAZA`, en una transacción; quitar da de baja; ninguna borra; un solo vigente por índice | sobre el código y el script |
| Cuenta nueva: aporta, se marca y avisa; vista, ya no; en un banco inactivo no; sin script ninguna; una que sólo tiene respaldo no; Parámetros cuenta las de cada banco | `esNueva()`, `cuentasNuevasDe()`, `armarBancos()` |
| Guardado de bancos: sólo lo que cambió; alias vacío es null; más de 100 se rechaza; un banco desconocido se rechaza; uno con fila que ya no viene se puede editar | `resolverBancos()`, `validarAlias()` |
| La lectura caída: aviso crítico, y el efectivo y Mercado Pago siguen entrando | `armarCuentasBancarias()`, `armarSerieDisponible()` |
| La consulta: `ROW_NUMBER()`, partición por banco, cuenta y moneda, el orden de desempate, sin JOIN, sin los otros saldos | sobre el código |
| El script de borrado: arranca en simulación, respeta `@CONSERVAR`, no nombra otros tipos, aborta con referencias, detalle antes que cuentas en una transacción, no toca cabeceras, deja la constancia adentro | sobre el script |
| Sin la constancia de depuración no se lee Interbanking ni se acepta un respaldo | sobre el código |
| La pestaña y el tablero suman lo mismo; contra la base, el total de la pestaña es la serie del proveedor | `getFilasDisponible()` |

**Contra la base viva**, sin tocar tablas permanentes, con una copia de `SaldosInterbanking` apuntada a `#temporales` creadas con el cuerpo de `sql/cashflow_saldos_interbanking.sql`, en una sola conexión:
- la siembra de cuentas vistas (9) no duplica en la segunda corrida;
- el `UPSERT` de bancos escribe sólo lo que cambió, y reactivar limpia la baja;
- el respaldo reemplazado queda con su baja y el nuevo apunta a él; quitarlo dos veces se rechaza; el índice impide dos vigentes;
- el respaldo rechaza un banco inactivo, una cuenta que no existe y la falta de depuración;
- marcar como vistas es idempotente.

La sonda encontró un error real: con BI caído, los respaldos perdían el nombre de Tango. Está corregido y tiene prueba.

**Lo que cambia en el Saldo Inicial**, medido el 08/10/2026 sobre los mismos datos:
- **Antes:** la última carga manual, del 16/09. Los bancos sumaban $ 47.376.627,00 y BTG Uy US$ 71.000; el total en pesos de la pestaña era $ 182.169.963,14, todo con fecha del 16/09.
- **Con la depuración corrida:** efectivo $ 314.902,26 y Mercado Pago $ 134.478.433,88, de la carga manual, más Interbanking $ 82.266.886,09 (siete cuentas con saldo del día). Da **$ 217.060.222,23**, y BTG Uy sigue con sus US$ 71.000.
- **Provincia (014)** pasa de los $ 35.485.388,00 manuales del 16/09 a sin dato, con aviso crítico, hasta que se cargue su respaldo. **Supervielle (027)** no estaba en la carga manual y no suma: tampoco trae dato, y se va a inhabilitar.

### Los fondos

```bash
php cashflow/tests/run.php fondos
```

133 casos, sin base salvo la última sección. Fija las reglas de `Fondos` (qué clase es un fondo y cuál es **la única** regla que lo decide; el cambio de clase sólo dentro del grupo; el importe positivo con el signo en el tipo; el saldo inicial con su fecha, los dos o ninguno; la clave de fondo y su lectura), `saldoA()` con un escenario que tiene un movimiento anterior al saldo inicial, uno del mismo día, uno dado de baja y uno futuro —cada uno tiene que quedar donde corresponde—, los helpers de `Cobertura` sobre una lista de cuentas de mentira (el origen por defecto saltea inhabilitadas y dólares), y el **proveedor con cuentas inyectadas**: que sume sólo su clase, que reparta por cuenta con nombre, que valúe los dólares a hoy y a la punta vendedora, que sin cotización no invente y avise en dólares, y qué avisa (sin saldo inicial, movimientos futuros, sin cuentas, sin script). Y lo que puede haberse cableado mal: que las filas de stock validen contra el registro real y una sobre el proveedor retirado valide con advertencia; que el script agregue `CLASE` con su `CHECK`, migre **la última carga vigente** con desempate por ID, reescriba **todas** las aplicaciones (no sólo las vigentes), reapunte las filas en vez de crearlas y no borre nada; y que la pestaña, los parámetros y el controller tengan sus piezas.

Contra la base: que ninguna cuenta de fondo entre al disponible, que el saldo listado coincida con `saldoA()` sobre el historial, y que ninguna aplicación de cobertura vigente haya quedado sin cuenta. El circuito completo —alta de movimiento, corrección con `id_reemplaza`, rescate futuro que no entra, baja, y el stock del tablero en cada paso— se corrió a mano contra la base de desarrollo; los tres movimientos de prueba quedaron en `RO_T_CASHFLOW_SALDOS_FONDO_MOV` dados de baja, como historial.

---

## Usuario

Las tablas llevan el esquema de auditoría del módulo —`USUARIO_ALTA` / `FECHA_ALTA`, `USUARIO_MODIF` / `FECHA_MODIF` y, donde hay baja lógica, `USUARIO_BAJA` / `FECHA_BAJA`—, y cada escritura graba el `username` del padrón de Gestionusuarios, que resuelve el servidor: el navegador no manda usuario ni fecha. Sin usuario la escritura se rechaza (401) y sin el permiso de edición de la pestaña, también (403). Las filas de antes de `feature/cashflow-auditoria-usuario` quedan con lo que tenían —casi siempre sin usuario— y la pantalla lo dice. Ver *Auditoría y permisos de escritura* en `README-cashflow.md`.

---

## Archivos

```
sql/cashflow_saldos.sql                        Las 5 tablas + semillas + parámetros
sql/cashflow_saldos_cuentas_fondo.sql          CLASE, saldo inicial, movimientos de fondos y la migración
sql/cashflow_saldos_dia_acreditacion.sql       Día de acreditación por local, y día y fecha en la foto
sql/cashflow_saldos_borrar_bancos_manuales.sql Borra los bancos manuales que pasan a Interbanking; deja la constancia
sql/cashflow_saldos_interbanking.sql           Alias y estado por banco, respaldo manual y cuentas vistas
cashflow/Class/Saldos.php                      Motor del módulo y helpers puros
cashflow/Class/DiasHabiles.php                 El paso al próximo día hábil, compartido con Ventas y Tarjetas
cashflow/Class/Fondos.php                      Las cuentas de fondo: clases, cuenta corriente, claves de cobertura
cashflow/Class/SaldosInterbanking.php          Los saldos de Interbanking: lectura en vivo, reglas puras y escrituras
cashflow/Class/Providers/SaldosProvider.php    DISPONIBLE y DEPOSITOS
cashflow/Class/Providers/FondosProvider.php    STOCK de FONDO_INVERSION y FONDO_COMITENTE
cashflow/Controller/SaldosController.php       Las tres pestañas, las dos cargas y los movimientos
cashflow/Tabs/saldos.php                       Reemplaza el placeholder
cashflow/Tabs/parametros_saldos.php            Sub-pestaña de Parámetros
cashflow/Js/Saldos.js
cashflow/Js/Parametros-Saldos.js
cashflow/Css/Saldos.css
tests/test_saldos.php
tests/test_saldos_acreditacion.php
tests/test_saldos_interbanking.php
tests/test_dias_habiles.php
tests/test_fondos.php
```

De la rama `feature/saldos-locales-dia-acreditacion`: `Class/Saldos.php` (`proximaFechaAcreditacion()` y sus helpers, `contextoAcreditacion()`, `acreditacionCreada()`, el día en `getParametrosSucursales()`, `armarSaldosLocales()`, `armarSerieLocales()` y la foto; `guardarParametrosLocales()` reemplaza a `saveSucursal()`) · `Class/Providers/SaldosProvider.php` · `Class/Parametros.php` · `Controller/ParametrosController.php` · `Tabs/saldos.php`, `Js/Saldos.js`, `Css/Saldos.css` · `Tabs/parametros_saldos.php`, `Js/Parametros-Saldos.js` · `Class/Ventas.php` y `Class/TarjetasVencimiento.php` (delegan en `DiasHabiles`).

Modificados: `Class/CashflowRegistry.php` (los dos códigos a `disponible => true`) · `Class/Parametros.php` (módulo `SALDOS` y sus secciones) · `Controller/ParametrosController.php` (ABM de cuentas y locales) · `Tabs/parametros.php` (el `tab-pane`) · `Css/Parametros.css` · `class/conexion.php` (`prefijoLocales()`) · `tests/test_providers.php` (ahora hay 6 módulos con datos reales).

De la rama `feature/cuentas-inversion`: `Class/Saldos.php` (`fondosCreados()`, los fondos fuera de `getSaldosActuales()`, `CLASE` y saldo inicial en `getCuentas()`, `addCuenta()` y `saveCuenta()`, `getPestanaFondos()`) · `Class/Parametros.php` (clases y `fondos_creados` en el payload) · `Controller/ParametrosController.php` (clase y saldo inicial en el alta y el guardado) · `Class/CashflowRegistry.php` (`FONDO_INVERSION`, `FONDO_COMITENTE`; Otros Ingresos retirados) · `Class/Cobertura.php`, `Class/Cashflow.php`, `Class/CashflowProvider.php` y `Providers/CoberturaProvider.php` (los fondos son las cuentas: ver `README-cashflow.md`).

De la rama `feature/saldos-interbanking`:
- `Class/SaldosInterbanking.php`, nueva.
- `Class/Saldos.php`: `getFilasDisponible()`, el origen de cada fila, los avisos de `armarSerieDisponible()` por origen, `avisosAntiguedad()` para la carga manual, `advertenciaAltaCuenta()` y el payload de la pestaña con avisos con nivel.
- `Class/Providers/SaldosProvider.php`: lee por `getFilasDisponible()`.
- `Class/Parametros.php`: los bancos de Interbanking en el módulo `SALDOS`.
- `Class/AuthCashflow.php`: `guardarRespaldoBanco`, `quitarRespaldoBanco`, `saveBancosSaldo` y `marcarCuentasVistas`.
- `Controller/SaldosController.php` y `Controller/ParametrosController.php`: esas acciones, más la advertencia del alta.
- `Tabs/saldos.php`, `Js/Saldos.js` y `Css/Saldos.css`: las filas de Interbanking, el diálogo del respaldo, las marcas, el KPI y los avisos críticos.
- `Tabs/parametros_saldos.php`, `Js/Parametros-Saldos.js` y `Css/Parametros.css`: los dos bloques de bancos.
- `tests/test_saldos_interbanking.php`, nueva, y `tests/test_tablas_controles.php`.

Ver `README-cashflow.md`.
