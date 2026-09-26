# Módulo Proveedores Locales — cuentas a pagar, fecha de pago y conciliación

Las cuentas a pagar del mercado local: cuánto se debe, cuándo vence, cuándo se piensa pagar y —después— cuándo se pagó de verdad.

Rama: `feature/proveedores-locales-pagos-reales`

---

## La idea en una línea

**Tango dice cuánto se debe y cuándo vence; acá se decide cuándo se paga, y esa decisión es lo único que saca a la deuda vencida del primer día del eje.**

```
Tango (CPA04 + CPA54 + CPA01, con las imputaciones de CPA05)
        │  una fila por VENCIMIENTO, no por comprobante
        ▼
Class/Proveedores.php  ──────────────┐
        │                            │  resuelve la fecha con la que entra al eje:
        │                            │    fecha cargada → vencimiento → emisión + plazo
        ├─ Class/ProveedoresCategorias.php
        │     el maestro: qué es cada proveedor, y cómo se le paga
        ▼
   Pestaña "Proveedores Locales"      Cuentas a Pagar / Importar / Maestro
        │
        ▼
   ProveedoresProvider → fila "Cuentas a Pagar Locales" del tablero
```

---

## Para pasar a producción

Contra `central`, en cualquier momento:

```sql
-- 1. sql/cashflow_prov_locales.sql
-- 2. sql/cashflow_prov_locales_collation.sql      (antes de la primera importación)
-- 3. sql/cashflow_prov_locales_maestro_manual.sql (para cargar el maestro a mano)
-- 4. sql/cashflow_prov_locales_forma_por_factura.sql (forma de pago por factura)
-- 5. sql/cashflow_prov_locales_excluir_factura.sql   (excluir facturas sueltas)
-- 6. sql/cashflow_prov_locales_opciones.sql          (las cinco listas de opciones)
-- 7. sql/cashflow_prov_locales_fuente_fecha.sql      (de dónde salió cada fecha de pago)
-- 8. sql/cashflow_prov_exclusion_modulo.sql          (excluir un proveedor entero del módulo)
```

El octavo crea `RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO`, vacía: **el día que se corre el tablero no se mueve**. No depende de ningún otro. **Sin él** nadie está excluido —que es lo cierto—, la columna *PROV. LOCALES* del maestro se dibuja con un guion y la pestaña avisa qué script falta. Ver *Excluir un proveedor entero del módulo*.

El séptimo agrega `FUENTE_FECHA` a la tabla de pagos y la rellena desde `ORIGEN` en las filas que ya tienen fecha —hoy es exacto, ver *De dónde sale cada fecha*—. **Sin él la columna *Fecha de pago* distingue igual** lo importado de lo cargado a mano, leyendo `ORIGEN`, y la pestaña avisa que falta el script. El tablero no se mueve: la fuente sólo decide cómo se ve la fecha.

**El gesto de fechar en masa no agrega ningún script**, y eso es parte de su diseño: escribe `FECHA_PAGO` en la tabla que ya existe desde el primero, con el mismo camino de escritura que la celda de la grilla. En una base que corrió el primer script, funciona sin tocar nada.

El sexto crea `RO_T_CASHFLOW_PROV_LOCALES_OPCIONES` y **siembra las cinco listas con los valores que ya están cargados en el maestro vigente**, ordenados por frecuencia de uso: lo que se usa doscientas veces arriba, lo que se usó una vez al final, que es donde se nota que probablemente sea un typo. Los valores se siembran **tal como están guardados**, sin corregir mayúsculas ni espacios: corregirlos ahí cambiaría en silencio la serie del tablero de los proveedores que los tienen.

> Es la misma lección que este módulo ya aprendió con `FORMAS_PAGO`: la primera versión de esa lista se escribió a ojo y **ninguno** de esos cinco valores existía en el maestro real, mientras que los que sí existían y faltaban eran el 54% de los proveedores.

Es **reejecutable y no revierte decisiones**: los valores que ya están no se vuelven a insertar, los que alguien dio de baja **no se reactivan**, y los días de plazo ajustados a mano no se pisan. **Sin él los campos siguen siendo texto libre** con sugerencias —como funcionaban antes— y la importación no valida contra ninguna lista; la pantalla lo dice con el script al lado.

El quinto va **después** del cuarto: necesita que `FECHA_PAGO` ya sea nullable, y si no lo es aborta diciéndolo. Las filas quedan con `EXCLUIDA = 0`, así que el tablero no se mueve.

El cuarto agrega `FORMA_PAGO_CRONOGRAMA` y hace `FECHA_PAGO` nullable. Las filas que ya están quedan con el override en `NULL` —*"usa la forma del maestro"*—, así que el tablero no se mueve. **Sin él el listado se lee igual** y la columna *Cronograma* muestra la del maestro en vez de un desplegable que fallaría al guardar.

El tercero agrega `ORIGEN` al maestro y marca como `IMPORT` lo que ya está, que es lo que es. **Sin él el maestro se lee igual**: lo que no se puede es cargarlo a mano, y la pestaña lo dice con el script al lado en vez de dibujar un formulario que después falla.

Crea `RO_T_CASHFLOW_PROV_LOCALES_CATEG` (el maestro) y `RO_T_CASHFLOW_PROV_LOCALES_PAGO` (las fechas de pago), y renombra la fila del tablero. Es reejecutable.

**Sin él la pantalla no falla: avisa.** El listado de cuentas a pagar funciona igual —sale de Tango— pero todo se proyecta al vencimiento y no se puede cargar ninguna fecha.

`sql/_referencia_tango_pendientes.sql` **no se corre**: es la consulta original de Tango, guardada como referencia. El guión bajo la saca de la lista de scripts.

---

## La consulta, y por qué se puede confiar en ella

**Reproduce a Tango al centavo.** Aplicándole sus mismos filtros —sólo lo no vencido, sin excluir el exterior— da exactamente sus **158 filas** y sus **$521.858.835,68**.

Esa verificación es lo único que permite afirmar que el número del tablero es el mismo que el del reporte del que salió. Sin ella, "depuré la consulta de Tango" es una intención.

### Qué se sacó de la original

| Se sacó | Por qué |
| --- | --- |
| `CPA54.FECHA_VTO >= hoy` | **Hacía desaparecer todo lo vencido.** Es lo contrario de lo que el tablero tiene que hacer |
| `CPA57`, `CPA108` | Provincia y país: este listado no los usa |
| `SUCURSAL` y la subconsulta de `NRO_SUCURS` | Ídem |
| `GVA81` | Clasificación: ídem |
| `CPA_CONTACTOS_PROVEEDOR_HABITUAL` | Ídem |
| `LEFT JOIN EMPRESA ON 1 = 1` | Ídem |
| El `CASE 'BICLAUSU'` | Es una constante que Tango resuelve al generar la consulta: de las tres ramas sólo se evalúa una. El filtro queda escrito y comentado, no heredado de un `CASE` muerto |
| La columna `IMPORTETOTAL` de `TMP` | **Código muerto**: no se usa en el `SELECT` final, y además su `CASE` de signos está incompleto |

> El prompt decía que la consulta original *"tiene `SUM(...)` y no tiene `GROUP BY` — no corre"*. **Sí lo tiene**, al final, y corre: se ejecutó para obtener el número de referencia.

### Lo que sí hubo que copiar, y es lo más importante

`CPA05` **no guarda sólo pagos**: guarda todo lo que se imputa contra el comprobante, y no todo lo cancela.

| `T_COMP_CAN` | Efecto |
| --- | --- |
| `REC` | **resta** (aunque `CPA21` diga que `REC` es `'D'`) |
| `CPA21.CRE_DEB = 'D'` | **suma** — una nota de débito es deuda **nueva** |
| cualquier otro | **resta** (`O/P`, notas de crédito) |

`O/P` no figura en `CPA21` —es una orden de pago, no un comprobante de compras— así que cae en el `ELSE` y resta, que es lo correcto.

> **La rama de `REC` es código no ejercitado.** Se copió tal cual de la consulta de Tango, por fidelidad al origen, pero **no se pudo verificar con datos**: en `CPA05` hay 109.314 imputaciones y **ninguna** tiene `T_COMP_CAN = 'REC'` (son todas `O/P`, `NC*`, `ND*` o `AJU`). O sea que esa rama nunca se ejecuta hoy, y si algún día aparece un `REC`, será la primera vez que corra. Se deja porque Tango la declara explícitamente y sobreescribe el `CRE_DEB` de `CPA21` —que dice `'D'` para `REC`—, lo cual sólo tiene sentido si el caso existe en alguna instalación.

**Sin esa tabla de signos el pendiente da negativo.** Pasa de verdad:

```
DONNA DI DIO, FAC A0000500001731
  vencimiento          14.614.380,00
  O/P imputada        −14.614.380,00
  NDI imputada         +3.342.062,08   ← esto NO cancela, es deuda nueva
  pendiente real        3.342.062,08
```

Restando todo daba **−3.342.062,08**. Con los signos corregidos: **0 pendientes negativos** en las 522 filas.

### Una diferencia con Tango, a favor

Tango une `CPA04 ⋈ CPA54` por `(COD_PROVEE, T_COMP, N_COMP)`. Esa terna **no es única** en `CPA04`: hay 16 repetidas, todas del proveedor genérico `000000`. Hoy ninguna está pendiente, así que las dos formas dan lo mismo, pero acá se usa `ID_CPA04` —poblado en las tres tablas— que no depende de eso.

### Qué queda afuera

- **`COD_PROVEE LIKE 'Z%'`**: no son proveedores de sistema, son los del **exterior** (`ZE…`: China, Hong Kong, India, y `ZETASK`). Son 8.023 de los 9.344 millones pendientes totales, y **ya entran al tablero por `COMEX_PROV_EXT`**: sin el filtro se contarían dos veces.
- **`CPA01.CLAUSULA = 1`**: proveedores con cláusula de moneda extranjera. Es el criterio del *Total Pendiente (CTE)* de Tango, que es el pendiente en pesos. Hoy deja afuera $20.938 de un transportista local de 2022 y 2023, que además tiene el importe en moneda extranjera en cero: casi seguro un error de maestro.

**Total local: $1.415.279.515,19** en 544 vencimientos de 122 proveedores.

> **Todas las cifras de este archivo son una foto del 16/09/2026.** Salen de una base viva: cambian entre una corrida y la siguiente, y de hecho cambiaron mientras se escribía esto. Están para dar orden de magnitud y para poder decir *"esto no es teórico"*, no para cuadrar contra la pantalla.

---

## Lo vencido entra, sin techo de antigüedad

Cobranzas descarta lo vencido hace más de `Ingresos::DIAS_COBRO_VENCIDO` (180) días. **Acá no**, y la diferencia no es un descuido:

> Una factura vieja sin **cobrar** puede ser incobrable. Una vieja sin **pagar** sigue siendo deuda.

Con ese techo quedaban afuera **$346,8 millones — el 29%** del total, y no era basura administrativa: la mayor parte es un plan de cuotas vigente de un proveedor industrial.

**Pero no se apila en silencio.** El indicador *Vencido sin fecha* dice cuánto hay vencido **sin que nadie haya decidido cuándo se paga**: son **378 vencimientos por $965.189.059,14**.

Ese importe se dibuja en el primer día del eje porque no hay otro lugar donde ponerlo. **Eso no significa que se pague hoy**, y por eso el número está a la vista en rojo, con un filtro de un clic para aislarlo.

**La herramienta para redistribuirlo es cargar la fecha, no un filtro por antigüedad.** Verificado: cargarle fecha a 5 comprobantes bajó el vencido sin fecha de $839.609.418 a $525.591.802.

> **Consecuencia visible en el tablero:** hasta que se importe la planilla de pagos, el *Saldo Final* va a dar negativo los primeros días (−699 M el día 1). No es una proyección de caja: es esa deuda vencida apilada. La marca de columna negativa de la sección Cobertura la señala sola.

---

## La jerarquía de la fecha de pago

Escrita una sola vez, en `Proveedores::resolverFechaPago()`:

```
1. la FECHA DE PAGO CARGADA, si hay una para ese comprobante
2. si no, la FECHA DE VENCIMIENTO de Tango
3. si no hay vencimiento usable, EMISIÓN + PLAZO del maestro
4. si nada de eso alcanza, sin fecha
```

**El vencimiento va antes que el plazo, y no al revés.** El vencimiento es un dato de *esta* factura; el plazo es una costumbre del proveedor. Cuando los dos existen, el que la describe es el vencimiento.

El escalón 3 existe para cuando Tango trae su centinela `1800-01-01` en lugar de una fecha. Hoy no hay ninguno en `CPA54`, pero la consulta de referencia lo contempla, así que el código también: es una red, no la norma.

**Una fecha cargada no se reubica aunque esté vencida.** La cargó una persona; moverla a hoy sería pisar su decisión con una regla automática y mostrarle su propia carga en otra columna. Se marca vencida —eso es un hecho— y se dibuja donde está.

### La columna muestra la fecha que usa el eje

> Esto **cambió** en `feature/cashflow-vto-exclusiones-pestanas`.

La jerarquía ya existía y el eje ya proyectaba al vencimiento, pero **no se veía**: sin fecha cargada, la columna *Fecha de pago* quedaba vacía y el vencimiento aparecía sólo en el tooltip. Ahora la columna muestra siempre la fecha con la que el comprobante entra al eje, y dice de dónde salió. Medido contra la base el 24/09/2026:

| Fuente | Marca | Vencimientos | Importe |
| --- | --- | --- | --- |
| **Manual** — la tipeó alguien, en la celda o con el fechado masivo | azul | 6 | $ 168.114.825,44 |
| **Planilla** — vino de la planilla de pagos importada | azul + *planilla* | 15 | $ 6.502.283,08 |
| **Vencimiento** de Tango | gris + *vto.* / *vto. vencido* | 465 | $ 1.562.057.939,77 |
| **Plazo** del maestro | gris + *plazo* | 0 | — |

**Se calcula al leer y no se graba.** Grabar el vencimiento lo convertiría en una fecha *cargada*: rompería el indicador *vencido sin fecha*, la conciliación —que compara lo previsto contra lo real— y el contador del fechado masivo, y además dejaría de seguir a Tango si el vencimiento cambia. Es la regla de siempre del módulo: lo que se deriva, se deriva al leer.

**Una fecha vencida muestra su vencimiento original, no el día 1 del eje.** Son **382 de los 465** ($ 1.326.960.887,25): el eje los proyecta en su primer día, pero poner *hoy* en la columna diría que alguien decidió pagarlos hoy. La marca dice *vto. vencido* y el `title` explica dónde se proyecta y cómo reubicarlo.

**Mostrarla no la guarda.** El input guarda sólo en `change`, así que dejarlo como está no escribe nada. Para fijar una fecha hay que elegir otra, o usar el fechado masivo; elegir en el calendario la misma fecha que ya muestra no dispara nada. Es deliberado: confirmar el vencimiento no es una decisión nueva.

**La marca va en un atributo y la dibuja el CSS**, no como texto de la celda: el export a Excel se queda con el texto, y *"15/03/2026vto."* no es una fecha. Lo que baja es la fecha sola —antes, sin fecha cargada, bajaba vacía—.

### De dónde sale cada fecha: `FUENTE_FECHA`, y por qué no alcanzaba `ORIGEN`

La fila viaja con dos campos, y los dos quedan:

- `ORIGEN_FECHA` — el escalón de la jerarquía: `CARGADA`, `VENCIMIENTO`, `PLAZO` o `SIN_FECHA`. **No cambió**: lo miran los indicadores, el fechado masivo, el aviso de vencidos sin fecha y la conciliación, y para todos ellos una fecha tipeada y una importada son lo mismo, *alguien decidió*.
- `FUENTE_FECHA` — lo mismo con la carga desdoblada en `MANUAL` y `ARCHIVO`. Es lo que muestra la columna. Lo arma `Proveedores::fuenteFecha()`, que es pura.

Para desdoblar la carga ya había una columna, `ORIGEN`, y **no alcanza: describe la fila, no la fecha.** La tabla de pagos es la de *los overrides del comprobante* —fecha, forma, exclusión— y cualquier escritura pisa `ORIGEN`. Una fecha importada pasaría a figurar como manual el día que alguien excluyera esa factura o le cambiara la forma. Hoy no hay ningún caso —las 78 filas con fecha no tienen otro override—, así que el relleno desde `ORIGEN` es exacto; lo que la columna evita es que deje de serlo.

`FUENTE_FECHA` la escribe `guardarPago()`, **sólo cuando la escritura trae `FECHA_PAGO`**, con el mismo origen: `MANUAL` desde la grilla y el fechado masivo, `ARCHIVO` desde la importación. No se llama `ORIGEN_FECHA` porque ese nombre ya es el del escalón.

> **Fechar una cuota mueve todas las del comprobante.** La fecha cargada es por comprobante —la clave es proveedor + tipo + número—, y un comprobante en cuotas tiene una fila por vencimiento. Hoy son 6 comprobantes, 47 vencimientos, $ 63.520.993,81, y **ninguno tiene fecha cargada**. Se dejó así a propósito; cada cuota muestra su propio vencimiento mientras nadie cargue nada.

### Volver al vencimiento borra la fecha, no la fila

> Esto **cambió**, y era un bug.

El botón ↺ de la celda hacía `DELETE` de la fila entera de la tabla de pagos, y con ella se iban **la exclusión con su motivo, el override de forma y la observación**, decisiones que nadie había pedido deshacer.

Ahora `deletePago()` hace dos pasos en una transacción: pone `FECHA_PAGO` y `FUENTE_FECHA` en `NULL` —con eso sólo el comprobante ya cae al vencimiento— y **borra la fila únicamente si no le queda nada**: ni forma ni observación de la planilla, ni override de forma, ni exclusión, ni conciliación. La condición está escrita una vez, en `Proveedores::condicionFilaVacia()`, y pregunta sólo por las columnas que la base tiene.

**Sin `sql/cashflow_prov_locales_forma_por_factura.sql`** `FECHA_PAGO` no admite `NULL` y se hace lo de antes, el `DELETE` directo. Sin ese script tampoco existen la forma por factura ni la exclusión, así que lo único que se pierde es lo que se perdía antes.

### El plazo del maestro no es un número

En la planilla los valores son `CONTADO`, `7 DÍAS`, `30 DÍAS`, `15 DÍAS`, `DEBITO`, y está **vacío en 765 de 1.223 filas (62%)**.

```
CONTADO   → 0      se paga el día de la factura
'N DIAS'  → N
DEBITO    → null   se debita solo; el plazo no dice cuándo
vacío     → null
```

**`null` no es lo mismo que `0`.** Cero es *"se paga hoy"* y se usa para calcular; `null` es *"este plazo no dice cuándo"* y hace caer al escalón siguiente.

---

## La fecha se carga de a una, o de a muchas

> El gesto masivo es **nuevo**. La celda editable de la grilla no cambió.

Cargar la fecha es lo único que saca a la deuda vencida del primer día del eje, y **lo que hay para fechar son 378 vencimientos**. De a uno, eso son 378 gestos.

Y el caso real casi nunca es una factura: es *"a este proveedor le pagamos el 30"*, que son las ocho facturas que tiene abiertas. La pantalla ya sabía resolverlo —buscar el proveedor, *seleccionar todas las que se ven*— porque es exactamente lo mismo que hace la exclusión masiva.

**Es el mismo gesto, con el mismo orden:** se seleccionan, se lee cuántas son y por cuánta plata, se elige la fecha, y recién ahí se guarda.

### Una fecha para todas, y eso es lo que se está diciendo

No es una simplificación de la pantalla. *"A este proveedor le pagamos el 30"* es **una** decisión sobre ocho facturas. Cuando las fechas son distintas son decisiones distintas, y ésas se cargan celda por celda — que es lo que la grilla ya hacía y se sigue pudiendo hacer, sin `min` y aceptando fechas pasadas.

### Sólo la fecha

`saveFechaMasiva()` escribe **una columna**. La forma del cronograma, la exclusión y la observación de cada factura son otras decisiones, y un gesto que recibe una fecha y escribe cuatro columnas no está guardando una edición: está reemplazando la fila. Es la misma regla que ya defendía `guardarPago()`, y acá pesa más porque son muchas filas de una.

Verificado contra la base: una factura con `EXCLUIDA = 1`, su motivo y un `FORMA_PAGO_CRONOGRAMA` puesto a mano conserva las tres después de fecharla en masa.

**Tampoco desconcilia nada.** Si alguna de las elegidas ya se pagó, Tango tiene la fecha real y eso no se toca: lo que cambia es la previsión, y con ella el desvío que después contesta *"¿le acertamos a la fecha?"*. El diálogo lo dice cuando hay alguna.

### No se filtra lo que "ya está así", al revés que al excluir

Excluir tiene dos estados y se conocen **antes** de preguntar nada, así que lo que ya está excluido se saca del lote: volver a escribirlo sería una versión idéntica en la tabla y un número inflado en el mensaje.

Acá el estado final **es la fecha, y no existe hasta que se elige**. Volver a escribir la misma fecha no es un error: es alguien ratificando la decisión, y queda con su fecha de modificación. Lo que sí se dice antes de confirmar es **cuántas de las elegidas ya tenían una fecha cargada**, porque ésas son decisiones de otro que este gesto pisa.

### Una sola transacción, y escrita una sola vez

O se fechan todas o ninguna, por lo mismo que la exclusión masiva: ocho llamadas dejan la puerta abierta a que la quinta falle y el tablero quede a mitad de camino.

Lo que cambió es **dónde vive esa mecánica**. Los dos gestos masivos tienen igual el normalizado de las claves y la transacción, y lo único distinto entre ellos es qué columnas se escriben. Así que eso se separó en dos piezas —`normalizarClaves()` y `guardarLote()`— y los dos pasan por ahí:

```
saveExclusionMasiva ─┐                                      ┌─ EXCLUIDA + MOTIVO
                     ├─ normalizarClaves ─ guardarLote ─ guardarPago
saveFechaMasiva     ─┘                                      └─ FECHA_PAGO
```

Una segunda copia de una transacción no se ve hasta el día que algo falla en el medio, y para entonces ya escribió a medias. **La fecha se valida una vez y antes de abrir nada**, igual que las claves: es la misma para todas, así que una fecha inválida no puede descubrirse con diez facturas ya escritas.

`guardarPago()` sigue siendo el único lugar del módulo que toca la tabla de overrides.

### La fecha se elige en el diálogo, no en la barra

`Notificacion.pedirFecha()` — el tercero que usa el mismo armazón, junto a `confirmar()` y `pedirTexto()`. Mismo contrato que el segundo: **`null` al cancelar y la fecha al confirmar**, como string `aaaa-mm-dd` y nunca un `Date`, porque el módulo entero mueve fechas como texto para no pasar por `new Date(string)`.

Un campo de fecha suelto en la barra de acciones habría sido más corto de escribir y peor de usar: **el número que hace notar que se seleccionó de más hay que leerlo antes de elegir la fecha**, no después de guardar. Por eso van en la misma ventana.

El formato se valida aunque el campo sea `type="date"` —un navegador sin soporte lo degrada a texto— y el 31 de febrero se rechaza comparando contra lo que devuelve `Date`, que en JavaScript lo corre solo al 3 de marzo. La validación que vale igual es la del backend: `validarFechaPago()` hace lo mismo con `checkdate()`.

### El check se dibuja aunque falte el script de la exclusión

La columna de selección alimenta las dos acciones y **sólo una de las dos necesita `sql/cashflow_prov_locales_excluir_factura.sql`**. Sin ese script se puede fechar igual, y lo que se apaga —diciendo por qué— son los dos botones de excluir. Esconder el check dejaría sin la acción de todos los días a quien no corrió un script que no tiene nada que ver con ella.

**La selección se poda contra lo que vino del servidor**, como ya hacía el módulo: las dos acciones mandan lo que la pantalla está mostrando, y un comprobante que se canceló en Tango ya no está en la lista.

> **Una diferencia con la exclusión:** al fechar, la selección **no se limpia**. Es la misma pregunta de siempre —¿las filas siguen a la vista?—: una excluida se esconde por defecto, así que la barra quedaría hablando de facturas que ya no están; una fechada sigue en la tabla, movida de columna, y dejarla seleccionada es lo que permite corregir la fecha ahí mismo si el importe cayó donde no iba.

---

## El maestro de proveedores

Tango sabe cuánto se le debe a cada proveedor. No sabe si es un taller, un alquiler, un impuesto o un socio. Eso vive en la hoja **Maestro proveedores** del Excel *Cronograma de Pagos*, y `RO_T_CASHFLOW_PROV_LOCALES_CATEG` es una **copia reimportable** de esa hoja, con su fecha de importación a la vista.

### Por qué una copia y no `CPA01.COD_RUBRO`

La columna existe y está **vacía en los 4.893 proveedores**; `CAMPOS_ADICIONALES` también (los 3.694 que lo tienen traen el XML vacío).

Se evaluó empezar a cargarla desde Tango y se descartó: **obligaría a administración a mantener dos maestros en paralelo**, y dos maestros en paralelo terminan discrepando. La planilla sigue siendo la fuente.

### Pero el código sí se valida contra `CPA01`

> Esto es **nuevo**.

Que el **contenido** salga de la planilla no significa que el **código** pueda ser cualquiera. `CPA01` es el universo de proveedores que existen, y un código que no está ahí no va a cruzar contra ninguna cuenta a pagar: el proveedor se carga, **no clasifica nada**, y el síntoma aparece semanas después en otra pantalla, como una deuda sin rubro que nadie sabe por qué no clasifica.

Antes el código se validaba **sólo por largo**, así que `MTDOD` entraba igual que `MTDODI`.

| Dónde | Qué pasa si el código no existe |
| --- | --- |
| **Alta manual** | Se **rechaza**. Es la tabla maestra de proveedores: no hay alta con advertencia |
| **Importación** | La fila queda en `ERROR` y **el resto de la planilla se importa igual**. Parar todo por dos códigos malos obligaría a corregir la planilla entera antes de poder cargar las mil doscientas que están bien — el mismo criterio que ya regía para el código repetido |
| **Lo ya cargado** | Se **audita y se avisa**. No se da de baja nada automáticamente |
| **`CPA01` no responde** | La importación **no se puede confirmar**. Ver más abajo: no es lo mismo que "ninguna fila está mal" |

**No se filtra por empresa ni por estado de baja.** `COD_PROVEE` es único en `CPA01`: si existe, vale. Un proveedor dado de baja en Tango puede seguir teniendo deuda pendiente, y excluirlo haría imposible clasificar esa deuda.

**El chequeo de la importación es UNA consulta para todos los códigos del archivo**, no una por fila: la planilla real tiene 1.223 filas y una consulta por cada una serían 1.223 viajes a la base para una pantalla que responde mientras alguien espera. El diff sigue siendo **puro** —recibe el mapa de códigos válidos por parámetro— y por eso se prueba entero sin base.

#### La regla corre en el backend, no en el navegador

`aplicarImportacion()` **vuelve a leer `CPA01` y las listas y revalida cada fila** antes de tocar la base. El diff se reenvía desde el navegador para aplicar exactamente lo que la persona vio —y eso está bien— pero un diff que viene de afuera es un **pedido, no una autorización**: creerle al `estado` dejaría que un POST armado a mano marque `ALTA` una fila que la previsualización había rechazado, y el endpoint es alcanzable sin pasar por la pantalla. Es el mismo criterio con el que `guardarManual()` vuelve a consultar `CPA01` aunque el buscador ya haya ofrecido el código.

Además cubre un caso que no es un ataque y pasa solo: entre previsualizar y confirmar puede pasar un rato, y en ese rato alguien pudo dar de baja un valor desde *Parámetros*.

La regla está escrita **una sola vez**, en `revalidarFila()` —estática y pura, probada sin base—, porque dos copias de una validación se separan en el primer cambio y la que queda vieja es siempre la que decide si se escribe en la base.

**Si la revalidación no coincide, no se aplica nada**, que es lo contrario del alcance por fila de la previsualización. La diferencia: ahí las filas malas estaban a la vista y alguien decidió importar el resto; acá lo que se descubre es que lo confirmado no es lo que se había visto, y aplicar "la parte que sobrevive" sería aplicar algo que nadie miró.

#### Una fila mala es esa fila; un origen caído es todo

> Esto **cambió**. Antes un origen caído sólo avisaba, y se importaba igual.

Parece una inconsistencia y no lo es:

| | Alcance | Por qué |
| --- | --- | --- |
| Una **fila** con un código que no existe, o con un valor fuera de lista | **Sólo esa fila**. El resto de la planilla se importa | Se sabe exactamente cuál está mal. Hay 1.222 filas de las que no se sabe nada malo y no hay motivo para castigarlas |
| El **origen** de la validación no se puede leer (`CPA01` caído, o la consulta de las listas fallando) | **No se puede confirmar la importación** | No es que ninguna esté mal: es que **no se chequeó ninguna**, así que no existe el subconjunto de filas válidas que el alcance por fila supone que hay |

**Con el origen caído no se marca ninguna fila en error**: informar como malas mil doscientas filas que probablemente estén bien sería mentir. Se pasa `null`, que **no es lo mismo que un mapa vacío** —un mapa vacío es "ninguno de estos códigos existe", y ése sí deja cada fila en error—.

**La previsualización se muestra igual**, con los cambios que traería: ver qué cambiaría no hace daño y sigue sirviendo para saber en qué estado está la planilla. Lo que se bloquea es **confirmar**, y la pantalla lo dice en rojo, en el mismo lugar donde estaba el botón, con el mismo texto y el mismo criterio que ya usaba el alta manual: qué no se pudo leer, que el freno es a propósito, y que hay que probar de nuevo en un rato. Un botón apagado sin motivo se lee como una pantalla rota, y un *"no se puede importar"* a secas manda a buscar el problema en la planilla, que es el lugar equivocado.

> El alta manual ya se frenaba así desde antes. Lo que cambió es que la importación dejó de ser la excepción.

#### El nombre sale de `CPA01` y no se edita

En el alta manual, `NOM_PROVEE` se trae de Tango y el campo es de **sólo lectura**. El backend lo vuelve a traer al guardar e **ignora lo que mande el navegador**: el endpoint es alcanzable sin pasar por la pantalla. Si el nombre se pudiera tipear, dos pantallas mostrarían dos nombres para el mismo código y ninguna de las dos sería *el* nombre del proveedor. Es el mismo criterio que usa el pre-chequeado con la razón social de `GVA14`.

**En la importación el nombre lo sigue trayendo la planilla**, y es deliberado: la planilla es la fuente del maestro y el nombre que administración escribió es parte de lo que se está importando. Lo que la importación sí hace es validar que el código exista.

#### El buscador acepta el nombre, no sólo el código

Quien carga un proveedor casi nunca se acuerda del código: se acuerda del nombre. El campo de código del alta es un autocomplete contra `CPA01` que busca **por código y por nombre**, desde 2 caracteres y con un tope de 20 resultados, poniendo primero los que *empiezan* con lo tipeado. Un campo que sólo aceptara el código obliga a ir a Tango a buscarlo, que es justo el paso que esto viene a sacar.

Elegir de la lista es una comodidad, **no la validación**: `guardarManual()` vuelve a chequear contra `CPA01`.

#### La collation no es un detalle

`CPA01.COD_PROVEE` es `VARCHAR(6) COLLATE Latin1_General_BIN`, y hay **27 códigos con caracteres no ASCII**. La comparación es **binaria**: `OGNUNE` y `OGNUÑE` son dos proveedores distintos. Por eso los códigos viajan **siempre como parámetro** y nunca interpolados, y por eso `RO_T_CASHFLOW_PROV_LOCALES_CATEG.COD_PROVEE` declara la misma collation (ver `sql/cashflow_prov_locales_collation.sql`).

El buscador es la excepción y lleva `COLLATE` explícito: un `LIKE` contra una columna binaria **no encontraría `mtdodi` en minúscula**, que es como se tipea.

#### Vive en su propia clase, y eso es la decisión

`Class/ProveedoresTango.php`. Son **dos maestros distintos** y hay que poder distinguirlos:

| | |
| --- | --- |
| `CPA01` | Quién **existe** como proveedor. Lo mantiene Tango |
| `RO_T_CASHFLOW_PROV_LOCALES_CATEG` | Qué **es** cada proveedor para nosotros. Lo mantiene administración |

Meter la lectura de `CPA01` adentro de `ProveedoresCategorias` haría parecer que son el mismo maestro, que es exactamente la confusión que esta sección viene evitando.

### Para qué sirve

1. **Para que el tablero pueda abrir la deuda por rubro.** Hoy la fila es una sola; cuando el maestro esté cargado, partirla en alquileres, impuestos, logística y mercadería es **configuración desde Parámetros**, no un refactor: el proveedor ya entrega cada comprobante con su rubro resuelto y expone una serie por rubro además del total.
2. **Para la forma de pago habitual**, que sirve de valor por defecto al importar pagos.
3. **Para el plazo**, que es el último escalón de la jerarquía de fecha.

### Las cinco clasificaciones salen de listas, no de texto libre

> Esto es **nuevo**. Antes eran texto libre con un `datalist` de sugerencias armado con los valores ya cargados.

`RUBRO_ECONOMICO`, `RUBRO`, `CENTRO_COSTOS`, `PLAZO_PAGO` y `CRITERIO_DISTRIB` se eligen de **cinco listas administrables** desde *Parámetros → Prov. Locales*.

El motivo no es cosmético, y está en una de las cinco: **cada `RUBRO_ECONOMICO` distinto crea una serie propia en el tablero** (`serieDeRubro()`). Tipear `Alquileres ` con un espacio al final no es un typo: es una **fila nueva del cuadro** que nadie pidió, y que además hay que ir a configurar a *Parámetros → Cashflow* para que se vea.

El caso ya estaba documentado en este mismo README: la planilla trae `50% ECOMMERC` contra `50% ECOMMERCE`, dos filas contra doscientas. Hasta ahora eso se detectaba **a posteriori**, comparando parecidos, porque no había ninguna lista declarada contra la cual validar.

#### Una sola tabla con una columna `TIPO`, y no cinco tablas

`RO_T_CASHFLOW_PROV_LOCALES_OPCIONES`. Las cinco listas tienen la misma forma —un valor, un orden, una vigencia— y el mismo ABM. Cinco tablas serían cinco `CREATE`, cinco consultas y cinco pantallas idénticas que hay que mantener sincronizadas, y la primera que se olvide de un cambio queda distinta sin que nadie lo note. El `CHECK` sobre `TIPO` es lo que impide que un typo cree una sexta lista invisible.

**Las cinco son independientes entre sí.** `RUBRO` no depende de `RUBRO_ECONOMICO`: no hay jerarquía y elegir un rubro económico no acota los rubros disponibles.

#### No son como `FORMAS_PAGO`, y la diferencia es quién las decide

| | |
| --- | --- |
| `FORMAS_PAGO` | Constante del **código**. Con ellas se **decide**: `esDelCronograma()` define si un comprobante entra al cashflow. Agregar una es un cambio de código, con su docblock |
| Estas cinco | **Datos**. Administración agrega un centro de costos el día que abre un depósito, y no puede depender de que alguien toque código |

#### `PLAZO` es la única que el sistema usa para calcular

Las otras cuatro se guardan y se muestran. `PLAZO` se traduce a **días**, y esos días son el último escalón de la jerarquía de fecha de pago. Por eso la lista guarda **el texto y su interpretación**:

```
CONTADO    -> 0       se paga el día de la factura
'30 DIAS'  -> 30
DEBITO     -> null    se debita solo; la fecha no la decide un plazo
```

**`null` no es `0`.** Cero calcula una fecha; `null` hace caer la jerarquía al escalón siguiente. Perder esa distinción cambiaría la fecha de pago de todos los proveedores con `DEBITO`.

Guardar los días **en la lista** —en vez de derivarlos siempre del texto— es lo que permite declarar un plazo que `plazoEnDias()` no sabría interpretar, como `FIN DE MES → 30`. Cuando el valor **no** está en la lista, `plazoEnDias()` sigue siendo el fallback y nada cambia.

#### `CRITERIO_DISTRIB` es, por ahora, sólo un nombre

Se verificó antes de escribir esto: en todo el módulo se guarda, se muestra en la grilla, se compara en el diff y se audita por typos. **Ningún cálculo depende de él**: no define porcentajes por canal ni afecta a ninguna serie. La lista lo normaliza y nada más. El día que tenga que repartir un gasto entre canales, los porcentajes son columnas nuevas de esta misma tabla.

#### Un valor fuera de lista es un error, y nunca se agrega solo

> Esto **cambió**. Era una **advertencia**: la fila se importaba igual y se guardaba con lo que vino, marcada.

| | |
| --- | --- |
| Código fuera de `CPA01` | **Error**. El proveedor no clasificaría *nada* |
| Valor fuera de lista | **Error**. La fila no se carga, y el motivo nombra el campo y el valor |
| Campo **vacío** | **Válido**, y eso no cambió |

El argumento de la advertencia era que un rubro raro **sí** clasifica —crea su propia serie— mientras que un código inexistente no clasifica nada. Era cierto, y lo que no alcanzaba era el resultado: la fila nueva del cuadro quedaba creada igual, porque el aviso se leía **después** de importar.

**El motivo nombra el campo y el valor** — *"El centro de costos «DEPOSITO SUR» no está en la lista de Parámetros → Prov. Locales"*. Con cinco listas, un *"hay un valor inválido"* obliga a comparar los cinco campos contra las cinco listas para saber cuál es; y como el arreglo casi nunca es corregir la planilla —suele ser dar de alta el valor— el mensaje dice **dónde** se da de alta.

**El alcance es por fila**, igual que con `CPA01`: la fila mala no se carga y el resto de la planilla sí.

**Un campo vacío no es un valor fuera de lista.** En la planilla real hay **84 filas sin rubro económico y 765 sin plazo**: dejarlas en error haría que no se pueda importar nada.

**Si las listas no existen, no se valida nada y no se bloquea.** Sin `sql/cashflow_prov_locales_opciones.sql` corrido, el módulo funciona como antes de que las listas existieran: texto libre. Eso es **configuración pendiente**, no un origen caído, y apagar el módulo por una tabla que nunca se creó sería lo contrario de lo que hace el resto de este código. Si en cambio la tabla **está y la consulta falla**, rige el criterio de `CPA01` caído: no se marca nada y no se deja confirmar.

> Los dos casos daban el mismo `null` hasta ahora, porque un `catch (Throwable)` se comía la diferencia. Mientras los valores fuera de lista eran advertencia daba igual —en los dos casos no se marcaba nada—; desde que son regla deciden cosas opuestas, así que `listasVigentes()` lanza `OpcionesIlegibles` para poder distinguirlos.

**El valor no se corrige al canónico.** La comparación sigue siendo tolerante —`ALQUILERES` reconoce a `Alquileres`, y esa fila **no** es un error— pero lo que se guarda sigue siendo lo que vino. Pisarlo cambiaría en silencio la serie del tablero de ese proveedor, y el original es la evidencia de que la planilla tiene algo que arreglar. Es el mismo criterio que rige para las formas de pago desde el principio.

**Y nunca se agrega solo a la lista.** Si la importación las ampliara, las listas se llenarían con los typos de la planilla y dejarían de servir para validar nada.

#### ⚠️ La primera importación después de este cambio puede fallar en masa

Si las cinco listas de *Parámetros* no reflejan **todos** los valores en uso, la planilla tal como está hoy queda rechazada fila por fila. Es la consecuencia esperada de que las listas signifiquen algo, y la única forma de que sea manejable es no descubrir los valores de a uno.

Por eso la previsualización lista **todos los valores rechazados, agrupados por lista y sin repetir**, arriba del detalle fila por fila:

```
Valores que hay que dar de alta en Parámetros → Prov. Locales
Centro de costos: DEPOSITO SUR · PLANTA 2
Plazo de pago: 45 DIAS · 60 DIAS
```

Con eso se cargan en *Parámetros* de una sola pasada y se vuelve a importar. Sin eso serían decenas de vueltas sobre un archivo de 1.223 filas: corregir uno, reimportar, encontrar el siguiente.

**Los agrupa el backend**, que es el que sabe comparar como comparan las listas: `DEPOSITO SUR` y `Deposito Sur` son **un** valor que dar de alta, no dos — aunque sean **dos filas** rechazadas, y las dos se cuenten como tales.

#### La pantalla de administración muestra cuántos proveedores usan cada valor

Sin ese número, dar de baja es a ciegas: no hay forma de saber si se saca una opción que no usa nadie o una que tienen doscientos proveedores, que van a quedar todos marcados como fuera de lista. Es la misma razón por la que *Pre-chequeado* muestra los cheques vivos de cada cliente.

**Renombrar no propaga al maestro**, y la pantalla lo dice. El maestro guarda el **texto**, no un id: los proveedores cargados conservan el valor viejo y quedan marcados como fuera de lista hasta que alguien los edite. Propagar sería un `UPDATE` masivo que cambia de fila del tablero a cientos de proveedores desde una pantalla de configuración, sin previsualización y sin historial. En este módulo, un cambio masivo sobre el maestro es una **importación**, y las importaciones muestran el diff antes de confirmar.

**La baja es lógica.** Un valor dado de baja deja de ofrecerse pero no desaparece de los proveedores que ya lo tienen. Borrar la fila dejaría proveedores apuntando a un valor que ya no se puede explicar.

#### Los desplegables son alfabéticos y tienen buscador

> Esto es **nuevo**. Antes eran `<select>` nativos, ordenados por la columna `ORDEN`.

**Alfabético siempre**, con `localeCompare` en locale `es`: con un `sort()` pelado, `Ñandú` y los acentos se van al final por su código de carácter, que en una lista de rubros escritos en castellano es justo donde nadie los busca.

Se ordena en **los dos lados** —`ProveedoresOpciones::vigentes()` y el front— y no es desconfianza: el orden en el que se ofrecen los valores es una decisión de la pantalla, y dejarla escrita sólo en un `ORDER BY` la vuelve invisible para quien lee el JS; al revés, un backend que mande un orden que la pantalla ignora hace creer al que lee el SQL que ese orden significa algo.

**La columna `ORDEN` no se borró y dejó de decidir esto.** La sigue usando el alta de opciones —el valor nuevo va al final (`ISNULL(MAX(ORDEN), 0) + 1`)— y sigue ordenando la tabla de *Parámetros*, que es donde se administra. Lo que dejó de hacer es decidir el orden de los desplegables del alta manual, **y la pantalla de Parámetros lo dice**: un control que parece hacer algo que no hace es peor que no tenerlo.

**El buscador no agrega ninguna librería.** El patrón ya estaba resuelto a mano en este mismo módulo —el autocomplete de códigos de `CPA01`, con sus clases `.prov-tango-suge`— y esto es lo mismo contra una lista que ya está en memoria: un `<input>` que filtra sin distinguir mayúsculas ni acentos, flechas + Enter + Escape, y se cierra al hacer clic afuera.

**Sólo se elige de la lista: el `<input>` filtra, no carga.** Lo que se tipea nunca se guarda. Sería incoherente que el alta manual aceptara por tipeo un valor que la importación rechaza. Cuando el filtro no encuentra nada, el desplegable dice **dónde** se dan de alta los valores; sin eso el campo parece roto.

El componente **se comporta como un `<select>`**: expone `.value` de lectura y de escritura, que es lo único que usan `valor()` y `setValor()`. Fue la condición para no tener que tocar `abrirForm()` ni `guardarProveedor()`.

**Y no se repueblan los campos con el formulario abierto.** `pintarMaestro()` corre en cada tecla del buscador del maestro; repoblar le borraría lo que alguien está cargando, en silencio.

#### Un valor fuera de lista no se pierde al editar, pero no se puede guardar

Un proveedor cargado **antes** de que existieran las listas —o traído por una importación de cuando un valor fuera de lista era advertencia— tiene valores que el desplegable no ofrece. Si el campo los descartara, quedaría vacío **en silencio** y guardar le borraría el rubro al proveedor sin que nadie lo haya pedido.

Por eso el campo **conserva el valor guardado**, lo ofrece como opción al final de la lista —marcada *(fuera de lista)*, no mezclada en el orden alfabético— y se pinta en naranja.

**Pero guardar lo va a rechazar**, y es la contracara de que las listas sean una regla: `guardarManual()` pasa por la misma `normalizarFila()` que la importación, así que endurecerla ahí lo endureció acá también. Sería incoherente que el alta manual aceptara lo que la planilla tiene prohibido.

> El campo lo dice en el tooltip **antes** de apretar Guardar, en vez de dejar que se descubra al guardar. La salida es elegir uno de la lista, o dar de alta el valor en *Parámetros*.

**Sin el script corrido, nada de esto cambia:** el backend manda las listas en `null` y los campos siguen siendo texto libre con sugerencias, que es como funcionaban antes. Dibujar desplegables vacíos dejaría una pantalla donde no se puede cargar nada.

> Estas listas aplican **sólo a Proveedores Locales**, no a Proveedores Exterior.

### Rubro económico y rubro son dos columnas, no dos nombres de una

La planilla trae los dos, y clasifican en dos niveles distintos:

| | |
| --- | --- |
| **Rubro económico** | Es el que **abre la deuda por serie** en el tablero: `RUBRO_ALQUILERES`, `RUBRO_LOGISTICA`, `RUBRO_MERCADERIA`… Un proveedor sin este dato cae en `PAGOS_SIN_RUBRO` |
| **Rubro** | Clasifica **adentro** del económico. Es informativo: no arma ninguna serie, y viene vacío en buena parte de la planilla |

Las dos se ven en las dos solapas —*Maestro* y *Cuentas a Pagar*—, en el mismo orden. Antes *Cuentas a Pagar* mostraba una sola, así que el mismo proveedor se leía distinto según por dónde se lo mirara.

> Están en columnas separadas y no concatenadas en una celda a propósito: **el que decide tiene que poder leerse solo.** Juntarlos obligaría a saber cuál de los dos abre las series para interpretar la celda.

Cuando el económico falta se dice *sin clasificar* —el proveedor no está en el maestro, y eso es trabajo pendiente—; cuando falta el otro va un guion, porque está clasificado y esa columna simplemente vino vacía. Un mismo cartel para los dos casos mandaría a clasificar proveedores que ya lo están.

### Se puede cargar y editar a mano, y la planilla sigue mandando

> Esto es **nuevo**. Antes el maestro sólo se podía escribir importando el Excel.

El caso que lo pide es concreto y se ve en la propia pantalla: hay **20 proveedores con deuda por $17.011.478,01 que no están en el maestro**. Aparecen en Tango, nadie los agrega a la planilla, y su deuda queda sin clasificar. El control de faltantes ya los listaba; lo que no había era forma de resolverlos sin volver al Excel.

**Alta y edición son la misma operación, y no es un `UPDATE`.** `guardarManual()` da de baja la versión vigente e inserta una nueva, en una transacción — exactamente lo que hace un `CAMBIO` de la importación. Por eso hay **un solo formulario** y un solo endpoint: dos insinuarían que existe un camino que modifica en el lugar, y en este módulo no lo hay.

**Se normaliza con la misma función que la importación.** `normalizarFila()` era privada y ahora es pública: aplica el largo del código en caracteres, la normalización de la forma de pago contra `FORMAS_PAGO` y el plazo en días. Si la pantalla normalizara por su cuenta, el mismo proveedor quedaría clasificado distinto según por dónde entró, y no habría ninguna pantalla donde notarlo.

**El código no se puede cambiar al editar.** Es la clave con la que la fila cruza contra Tango y contra las fechas de pago ya cargadas; cambiarlo sería dar de baja un proveedor y dar de alta otro, y eso son dos gestos.

**Una baja no borra**: marca `VIGENTE = 0` igual que una baja de la importación. La deuda de ese proveedor pasa a contarse como *sin rubro* y el historial sigue explicando cómo se clasificaba antes.

#### La planilla manda, pero pisar trabajo manual se avisa

Hay dos fuentes escribiendo la misma tabla, y este README ya había descartado eso una vez: *"dos maestros en paralelo terminan discrepando"*. La decisión es la misma de entonces — **la planilla es la fuente** — así que una edición manual es una versión más y la próxima importación la pisa.

Lo que se agrega es que **no la pise en silencio**. La columna `ORIGEN` (`IMPORT` / `MANUAL`) hace posibles tres cosas:

| | |
| --- | --- |
| En la grilla del maestro | una columna que dice si esa versión salió de la planilla o de la pantalla |
| En el historial del proveedor | lo mismo, versión por versión |
| **En la previsualización del diff** | un aviso — *"N de los cambios pisan proveedores editados a mano"* — y la marca en cada fila |

> Entre trescientos cambios, los que borran lo que alguien cargó a mano son los únicos que esa persona querría revisar. El aviso dice cuántos son; la marca dice cuáles.

Se aplican igual al confirmar: el que decide es quien importa, viéndolo.

### Excluir una factura suelta

> Esto es **nuevo**. Antes sólo se podía excluir a un proveedor entero.

Una factura duplicada, una en disputa o una que se pagó por fuera de Tango no son un problema del proveedor: son un problema de **esa factura**.

**El motivo es obligatorio**, y lo valida `Proveedores::saveExclusionMasiva()` y no la pantalla —el endpoint es alcanzable sin pasar por la grilla—. Una factura sacada del cashflow sin motivo no la explica nadie tres meses después. Volver a incluirla borra el motivo: dejarlo haría que una factura incluida arrastre el texto de cuando estuvo afuera.

#### Se eligen varias y se confirman juntas, con UN motivo

> Esto **cambió**. Era un tilde por fila que actuaba solo y pedía el motivo con el `prompt` del navegador.

La columna es de **selección**, no de estado. Sacar plata del tablero no puede dispararse con un clic suelto, y el caso real no es una factura: son **las ocho de un proveedor**. Se resuelve con lo que la pantalla ya tenía — buscar el proveedor, *seleccionar todas las que se ven*, un motivo.

**Un motivo para todas, y no es una simplificación de la pantalla:** excluir las ocho facturas de un proveedor es **una** decisión, y ocho motivos distintos para una decisión son ocho oportunidades de que digan cosas distintas.

> **Esa columna hoy alimenta dos acciones.** La otra es poner la misma fecha de pago a todas, que es la que se usa todos los días; ésta es la excepción. Comparten la selección, el podado contra lo que vino del servidor y la barra que dice cuántas y por cuánto. Ver *La fecha se carga de a una, o de a muchas*.

**Es una sola transacción**, igual que el tildado masivo de Echeqs y por el mismo motivo: ocho llamadas dejan la puerta abierta a que la quinta falle y el tablero quede a mitad de camino sin que nadie se entere. Las claves se normalizan **antes** de abrirla: un comprobante mal identificado en la fila once no puede descubrirse con diez ya escritas.

`saveExclusion()` de a una **no duplica nada**: delega en la masiva con una lista de uno. Dos caminos que tienen que hacer lo mismo divergen, y lo que no puede estar escrito dos veces es la transacción.

En la barra de selección, antes de apretar nada: **cuántas facturas y por cuánta plata**. El importe es el dato que hace que alguien note que seleccionó de más.

#### El motivo se pide en un diálogo del módulo

`Notificacion.pedirTexto()` — el mismo control que ya hacía `confirmar()`, con un campo adentro. `window.prompt` no se puede formatear, no entra un detalle largo, no valida nada y se ve como un error del navegador en vez de como una decisión del sistema. Y acá hay que **leer cuántas facturas y por cuánto antes de escribir el motivo**, que en un prompt no entra.

- **Devuelve `null` al cancelar y el texto al confirmar.** `confirmar()` sigue devolviendo un booleano: su respuesta es sí o no, y la de ésta es el texto. Un `false` que a veces es `''` obligaría a cada llamador a distinguir dos ausencias distintas.
- **Un campo obligatorio vacío no cierra el diálogo**: dice por qué en el mismo lugar donde se escribe, en vez de cerrar y fallar después contra el servidor.
- El armazón está escrito **una vez** (`abrirDialogo()`): lo delicado no es el HTML, es que cerrar con la cruz, con Escape o clickeando afuera **también sea una respuesta, y sea la negativa**. Dos copias de eso se desincronizan en la primera corrección.
- Sin Bootstrap se cae al `prompt` del navegador, igual que `confirmar()` se cae al `confirm`: es feo, pero preguntar es lo que no puede faltar.

#### Por qué no alcanzaba con mandarla a `PAGOS_EXCLUIDOS`

Ésta es la parte que no es obvia. **La fila del tablero usa `PAGOS`, y `PAGOS` pertenece al corte del cronograma, que no excluye nada.** Mandar la factura tildada al corte de excluidos la habría sacado de `PAGOS_CRONO_OPERATIVOS`, que hoy **no usa ninguna fila**: el tilde no habría movido un peso.

Verificado contra la base: la fila está configurada con `ORIGEN_SERIE = 'PAGOS'`, y ahí adentro hay **$72.500.993,87 en 8 vencimientos de `OGRAZ`** —rubro *Excluidos*— que entran igual porque cobra por echeq.

Por eso la exclusión manual sale también del primer corte, con serie propia, y ese corte pasa a tener **tres partes**:

```
PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA = PAGOS_TODO
```

- **No cae en `PAGOS_FUERA_CRONOGRAMA`.** Ahí el aviso desglosa por forma de pago —*"esto no se planifica porque es un débito automático"*— y una factura excluida a mano lo ensuciaría.
- **Sigue en `PAGOS_TODO` y en `PAGOS_EXCLUIDOS`.** El importe no desaparece: queda auditable, y el proveedor **avisa cuánto es y con qué motivos** en cada carga del tablero.
- **Un proveedor excluido por rubro no se mueve.** Sacarlo de `PAGOS` sigue siendo apuntar la fila a `PAGOS_CRONO_OPERATIVOS` desde Parámetros; este cambio no toma esa decisión por nadie.

> **Desde la exclusión por proveedor el corte tiene cuatro partes**, por el mismo motivo: ver *Excluir un proveedor entero del módulo*.

> **El criterio se reusó en Echeqs.** *Cheques en Cartera* excluye con el mismo patrón —serie propia, motivo obligatorio, acción masiva, sin bajas físicas— y ahí el corte `A_COBRAR + A_COBRAR_EXCLUIDOS = A_COBRAR_TODO` nace con esa etapa, porque `ECHEQS` tenía una sola serie y era el universo entero. Ver *Excluir un cheque que no se va a poder cobrar* en `README-cashflow.md`. Lo que cambia allá es que la exclusión guarda **historial**: el cheque puede entrar y salir varias veces, y cada decisión queda con su motivo, su autor y sus fechas de alta y de baja.

#### No se ven por defecto, y el cartel dice cuántas son

Ya se decidió que no van al cashflow, así que en el trabajo normal —revisar qué hay que pagar— son ruido. El interruptor **Ver excluidas** viene **apagado**, al revés que el de al lado.

Pero esconder plata sin decir cuánta es exactamente lo que este módulo no hace, y acá pesa más que en el otro filtro: **esas filas están escondidas por defecto**, así que sin el cartel del período no hay ninguna pantalla donde alguien note que existen. Una exclusión puesta en marzo que nadie recuerda es justo lo que el cartel evita.

> *Hay 1 factura(s) excluida(s) a mano por $ 7.110.342,48, escondidas y fuera del cashflow — tildá Ver excluidas para revisarlas.*

Los indicadores siguen midiendo lo visible y diciendo el universo al lado, como con los otros dos filtros. Cuando se muestran, la fila se atenúa y el pendiente va tachado: es la fila la que cambió de significado, no una celda.

### Excluir un proveedor entero del módulo

> Esto es **nuevo**, de `feature/cashflow-vto-exclusiones-pestanas`.

Hay proveedores cuya deuda **ya se considera en otra pestaña** —la aduana, por ejemplo, en Crono Nacionalización— y que además aparecen en las cuentas a pagar de Tango. Si los dos lados los proyectan, el tablero cuenta dos veces la misma plata. Cuáles son lo sabe quien maneja Proveedores Locales; esto le da dónde decirlo. **El código no decide cuáles**: no hay ninguna lista precargada.

**Es por proveedor y alcanza a toda su deuda**, la ya emitida y la que venga. Para sacar una factura suelta está la exclusión por factura, que es otra decisión: *"esta factura no va"* y no *"este proveedor ya está contado"*.

#### Dónde vive, y por qué no en el maestro

En su propia tabla, `RO_T_CASHFLOW_PROV_EXCLUIDO_MODULO`, con la clave `(COD_PROVEE, MODULO)`. Lo maneja `Class/ProveedoresExclusion.php`.

- **No es una columna del maestro** porque el maestro es una copia reimportable de la planilla de administración, y la reimportación propone bajas: la exclusión se perdería. Tampoco es un dato que la planilla tenga.
- **Un proveedor se puede excluir sin estar en el maestro.** La deuda de un proveedor sin clasificar se proyecta igual, y obligar a clasificarlo para poder excluirlo mezcla dos decisiones. El código **sí** se valida contra `CPA01`, como el alta manual: uno que no existe no tiene deuda que excluir.
- **Es por módulo y no un bit.** Hoy el único es `PROV_LOCALES`; agregar otro es sumarlo a `ProveedoresExclusion::MODULOS` y al `CHECK` de la tabla —un `ALTER` de una línea— y escribir el código que lo lea. La tabla no cambia. `MODULO` es un `CHECK` y no una lista administrable desde Parámetros porque un módulo nuevo siempre viene con código: no hay nada que un usuario pueda dar de alta sin un despliegue. `tests/test_proveedores.php` fija que las dos listas coincidan.

#### Motivo obligatorio, y con historial

Como la exclusión de cheques de Echeqs: **sin bajas físicas**. Volver a incluir marca `VIGENTE = 0` y sella `FECHA_BAJA`; volver a excluir inserta una fila nueva. Un índice único filtrado por `VIGENTE = 1` impide dos vigentes para el mismo proveedor y módulo.

- **El motivo es obligatorio**, y lo valida el backend —el endpoint se alcanza sin pasar por la pantalla— y un `CHECK` de la tabla.
- **Excluir a uno ya excluido es un error, no un cambio de motivo**: pisar el motivo en silencio borraría el porqué de la decisión que estaba vigente. Para cambiarlo se incluye y se vuelve a excluir, y quedan las dos en el historial.
- El historial se abre desde la misma celda del maestro, con desde, hasta, motivo y quién.

#### Cómo se usa

- **Solapa Maestro**, columna *PROV. LOCALES*: el botón *excluir* pide el motivo; un excluido muestra la marca —con motivo, quién y cuándo en el `title`—, el botón para volver a incluirlo y el del historial. El interruptor **Sólo excluidos** los junta, con el conteo al lado.
- **Los excluidos que no están en el maestro** aparecen al final de la grilla del maestro, con el nombre de Tango, para poder verlos y volver a incluirlos. Y **se excluyen desde el aviso de proveedores con deuda que no están en el maestro**, que es el único lugar donde aparecen: por eso ese aviso ya no se recorta a doce.
- **En Cuentas a Pagar** los esconde el mismo interruptor **Ver excluidas** que a las facturas: las dos contestan *"¿esto va al cashflow?"* con un no. El cartel del período desglosa cuánto es de cada una.

#### En el tablero: la cuarta parte del corte

```
PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA + PAGOS_EXCLUIDOS_PROVEEDOR = PAGOS_TODO
```

Por lo mismo que la factura es la tercera: la fila del tablero usa `PAGOS`, y sólo una serie propia de ese corte saca el importe de ahí. **El importe no desaparece**: queda en `PAGOS_TODO` y en su serie, y el proveedor avisa en cada carga cuántos proveedores, cuánto y cuánto de eso estaba en la fila —excluir a uno que cobra por débito no mueve la fila, y el aviso no puede dar a entender que sí—.

- **En el segundo corte cuenta como excluido** (`PAGOS_EXCLUIDOS`, fuera de `PAGOS_OPERATIVOS` y de `PAGOS_CRONO_OPERATIVOS`), igual que la factura excluida a mano.
- **Si la factura también está excluida a mano, gana el proveedor**: el importe va una sola vez a `PAGOS_EXCLUIDOS_PROVEEDOR`. El tilde de la factura queda guardado y marcado, y vuelve a aplicar solo si el proveedor se reincluye. Por eso el aviso de facturas excluidas y el cartel no las cuentan dos veces.
- **Un excluido no cuenta en *Vencido sin fecha*** ni en el aviso de *lo que queda fuera del cronograma*: de él ya se decidió que no va, y ese aviso dice *"igual va a salir de la caja"*, que para un excluido es justo lo contrario.

**Verificado contra la base**, con la tabla todavía sin crear: las 33 series de `PROV_LOCALES` dan idénticas antes y después, y la nueva da cero. Y con una exclusión **simulada en memoria** —sin escribir nada— sobre un proveedor de $ 620.010,00: `PAGOS` bajó exactamente $ 620.010,00, `PAGOS_EXCLUIDOS_PROVEEDOR` subió lo mismo, `PAGOS_TODO` no se movió y el corte de cuatro partes cerró.

> `RO_V_PROVEEDORES_EGRE_DIRECTORES` y el rubro *Excluidos* **no cambiaron**: clasifican y controlan, que es otra cosa. Ver las dos secciones que siguen.

### El rubro "Excluidos"

Son los socios y los movimientos que no son deuda comercial. **No se filtran en la consulta: se clasifican**, y la fila del tablero que los agrupa se inhabilita desde Parámetros. Así sacarlos es un bit y no un cambio de código, y siguen visibles en la pestaña de detalle, que es donde alguien puede notar que uno está mal clasificado.

#### Pero en la grilla no se marcan, y antes sí

> Esto **cambió**.

La fila se atenuaba en gris e itálica, la etiqueta del rubro tenía color propio y el `title` del código decía *"Rubro Excluidos: se lista pero su fila del tablero se puede inhabilitar"*. Las tres decían lo mismo, y lo que decían no pasa: **en esta pantalla ese rubro no saca la deuda de ningún lado.**

La fila del tablero usa `PAGOS`, y `seriesDeItem()` reparte `PAGOS` por **cómo se paga** —cronograma o no— sin preguntar nunca por el rubro. Es el mismo hecho que ya estaba documentado dos secciones más arriba: los **8 vencimientos de `OGRAZ`** con rubro *Excluidos* entran a `PAGOS` igual que cualquier otro porque cobra por echeq. Tampoco los esconde el filtro ni los descuenta ninguna tarjeta.

Una fila gris por un rubro que no cambia ningún número manda a descartar plata que sí está en el cuadro — que es exactamente lo contrario de lo que este módulo hace con el resto de sus filtros.

Sacarlos del tablero **sigue siendo apuntar la fila a `PAGOS_CRONO_OPERATIVOS` desde Parámetros**, y hoy eso no está hecho. Hasta que lo esté, *Excluidos* es una clasificación que alimenta **otros cortes** —`PAGOS_EXCLUIDOS` y su serie por rubro— y no una decisión sobre esta grilla.

**El color salía de `EXCLUIDO`, que junta el rubro con el tilde por factura**, así que una factura excluida a mano de un proveedor de *Alquileres* mostraba `Alquileres` pintado como si fuera *Excluidos*.

Lo que **sí** se sigue marcando es la exclusión **por factura**: ésa va a su propia serie y efectivamente sale de `PAGOS`. Y cuánto pesa el rubro sobre el total lo sigue diciendo el aviso de la pantalla, que es donde un número agregado se lee una vez en lugar de repetirse en cada fila. Para encontrarlas en la grilla alcanza con escribir el rubro en el buscador.

### Hay una segunda lista de exclusión, y no manda

`RO_V_PROVEEDORES_EGRE_DIRECTORES` tiene 8 proveedores marcados como egreso de directores.

**Manda el maestro**, por dos motivos: es el que administración mantiene todos los días, y tiene 225 proveedores contra 8. La vista queda como **control**: si alguien figura en ella y no está marcado `Excluidos` en el maestro, se avisa.

> Una lista manda y la otra audita. Dos listas que **deciden** se contradicen y nadie se entera. Hoy la diferencia no es teórica: **2 de esos 8 tienen deuda pendiente** ($4,2 M).

---

## Las dos importaciones

Mismo circuito que Cob. Electrónicos: **plantilla CSV, previsualización del diff, y nada se escribe hasta confirmar**. El mecanismo está en `Class/Planilla.php`, escrito una sola vez para los tres importadores del módulo.

### Es CSV, no .xlsx, y se dice en pantalla

Leer un `.xlsx` necesita la extensión `zip` de PHP, que en este servidor **está instalada pero no habilitada**. Habilitarla es tocar el `php.ini` de producción y reiniciar Apache; el módulo no puede depender de que ese cambio esté hecho en cada entorno.

Si igual se sube un `.xlsx`, el parser lo **detecta por su firma** (`PK`) y dice qué hacer, en lugar de fallar con un archivo lleno de bytes binarios. Lo mismo con un `.xls` antiguo.

### La planilla viene sucia, y eso se muestra — no se arregla

> Un importador que corrige solo deja la planilla rota para siempre, porque nadie se entera nunca de que lo está. **Lo que hay que arreglar es la planilla.**

| Lo que trae la planilla real | Qué hace el importador |
| --- | --- |
| **Un código repetido** (1.222 únicos en 1.223 filas) | Deja en error **las dos** filas, nombrando cada una a la otra. Quedarse con la última elegiría por el usuario |
| **Códigos con `Ñ`, `&` o `+`** (27 proveedores) | Se cargan normal. Ver abajo: acá hubo un bug |
| `echeq` en minúscula | Matchea contra `ECHEQ`, y guarda el original al lado |
| `eqheck` (un typo real) | **No** matchea: se guarda tal como vino, con el normalizado en `null`, y se avisa |
| `50% ECOMMERC / 50% VENTAS` (2 casos) | Se detecta **sin inventar una lista de criterios válidos** — ver abajo |
| 84 filas sin rubro económico, 98 sin centro de costos | Se cargan igual, y el resumen dice cuántas son |

**Cómo se detecta el typo del criterio sin una lista declarada:** los criterios son texto que escribe administración, y declarar los válidos sería decidir por ellos. Lo que sí se puede afirmar sin inventar nada es que **un valor que aparece 2 veces y se parece 85% a otro que aparece 200 es sospechoso**. Eso se marca y se muestra; no se corrige.

Un criterio poco usado pero **distinto** de todos los demás no se marca: sin algo parecido y más frecuente no hay nada que afirmar, y avisar de todos los raros sería ruido.

### El código se mide en caracteres, no en bytes

Un bug que apareció en la primera importación real: `OGNUÑE` y `OGMAGÑ` se rechazaban como *"tiene 7 caracteres"* siendo proveedores válidos de Tango.

`strlen()` cuenta **bytes**, y en UTF-8 la `Ñ` ocupa dos. En `CPA01.COD_PROVEE` —`VARCHAR(6)` con collation `Latin1_General_BIN`— ocupa uno, así que el código entra perfectamente.

Son **27 proveedores** con caracteres no ASCII en el código: eñes, `&` y `+`.

Hay un segundo bug de la misma familia, más silencioso, que se corrigió junto con el primero: **`strtoupper()` tampoco es multibyte-safe.**

```
strtoupper('ognuñe')     →  'OGNUñE'    la eñe NO sube
mb_strtoupper('ognuñe')  →  'OGNUÑE'
```

Si la planilla trae el código en minúscula, `OGNUñE` **no matchea** contra el `OGNUÑE` de Tango y la fila queda sin cruzar sin que nadie entienda por qué. Toda normalización de código pasa ahora por `Planilla::codigo()`.

**Los acentos no se sacan**, a diferencia de `Planilla::normalizarTitulo()`: un título de columna se compara de forma laxa porque lo escribe quien arma el archivo, pero un código de proveedor es un identificador y `OGNUNE` y `OGNUÑE` pueden ser dos proveedores distintos.

Por el mismo motivo, las claves de cruce se declaran con la collation de Tango (`Latin1_General_BIN`) y no con la de la base (`Modern_Spanish_CI_AI`, que es **acento-insensible**): si no, para SQL Server `OGNUNE` y `OGNUÑE` serían el mismo valor en nuestras tablas y dos distintos en Tango. Hoy no hay ninguna colisión —se verificó—, pero el día que la haya, el UPDATE de baja del maestro daría de baja los dos y la fecha de pago de uno se le aplicaría al otro, sin error y en silencio.

En una base ya creada eso lo corrige `sql/cashflow_prov_locales_collation.sql`. **Conviene correrlo antes de la primera importación del maestro**, que es cuando las tablas están vacías.

### Las formas de pago son las de la planilla, no las que parecen razonables

La primera versión de `FORMAS_PAGO` se escribió a ojo: `CHEQUE`, `EFECTIVO`, `RETENCION`, `COMPENSACION`, `OTRO`. **Ninguno de esos cinco existe en el maestro real**, y los que sí existen faltaban. Resultado de la primera importación: **639 de 1.173 proveedores (54%) quedaron con la forma sin normalizar.**

Las seis reales, contadas sobre el maestro importado:

| | | | |
| --- | ---: | --- | ---: |
| CAJA | 390 | TARJETA CORP | 240 |
| TRANSFERENCIA | 259 | DEBITO | 32 |
| ECHEQ | 243 | MERCADO PAGO | 9 |

Agregar una forma es **agregar una entrada, y nada más**: las filas ya cargadas se renormalizan solas en el próximo pedido. Ver abajo.

### La forma normalizada se deriva al leer, no se congela al importar

`FORMA_PAGO` es un valor **derivado**: sale de pasar `FORMA_PAGO_ORIG` —lo que decía la celda— por `FORMAS_PAGO`, que vive en el código. Calcularlo al importar lo congelaba contra la lista de ese día, así que agregar una forma nueva no arreglaba ninguna de las filas ya cargadas.

**Y reimportar no es recalcular.** Hace el diff completo: necesita tener a mano el Excel vigente —si no es el mismo que se importó, aplica de paso cambios que nadie pidió—, propone bajas, y cada `CAMBIO` escribe una baja más un alta en el historial.

> Obligaba a correr una operación de **datos**, con efectos colaterales, para arreglar la consecuencia de un cambio de **código**. Eso es lo que estaba mal, no el trabajo de reimportar.

Se puede derivar al leer porque **el original está guardado en todas las filas**. Verificado sobre la base: 0 de 1.173 en el maestro y 0 de 15 en pagos tienen la normalizada sin su original. Y la normalización es una función pura de él.

Cuesta **7,23 ms por pedido** sobre las 1.173 filas del maestro, y el mapa se cachea, así que es una vez.

**La columna se sigue escribiendo igual**, y no es redundancia: guarda *qué decidió el sistema en esa importación*, que es lo que muestra el historial del proveedor. Lo que cambió es por dónde se **lee**.

Dos garantías, las dos con prueba:

- **Recalcular nunca borra un dato.** Sólo puede reconocer uno que antes no se reconocía. Una fila con la normalizada pero sin original —hoy no hay ninguna, pero una corrección a mano sobre la base podría dejarla así— conserva lo que tenga guardado.
- **El diff no se ensucia.** Compara contra `mapa()`, que es justamente lo que se renormaliza, así que reimportar la misma planilla sigue dando `SIN_CAMBIOS` y no 639 cambios falsos.

### Sólo el código es obligatorio

En la planilla real faltan datos en cientos de filas. Exigirlos haría que la importación **falle entera** por datos que administración todavía no cargó.

### Una baja masiva avisa distinto

Si el archivo daría de baja **más de la mitad** del maestro, lo más probable es que alguien exportó un filtro y no el maestro entero. El aviso lo dice, y **las bajas se confirman aparte, con su propio interruptor**: no pueden ser un efecto de importar.

### La planilla de pagos

Campos: **cód. proveedor, número de factura, fecha de pago, forma de pago**.

**El tipo de comprobante no es obligatorio**, a propósito: quien arma la planilla mira una factura y copia su número, no su tipo. Cuando falta se deduce de los pendientes del proveedor.

- Un comprobante **en cuotas** aparece varias veces en los pendientes y eso **no es ambigüedad**: es un comprobante con varios vencimientos. Se resuelve solo, y el importe que se reubica es la suma de sus cuotas —la fecha se carga por comprobante, no por cuota—.
- **Dos tipos distintos con el mismo número** sí lo son. Ahí la fila pide que se aclare con la columna `TIPO_COMP`, en lugar de elegir una.

**Lo que no cruza contra ningún pendiente es un error visible con su motivo**, y los tres casos se distinguen porque se arreglan distinto: número mal tipeado, proveedor equivocado, o factura que ya se pagó.

**El aviso que importa dice cuántos vencidos quedan resueltos.** Es la razón de ser de esta importación, y hay que verlo *antes* de confirmar.

---

## La clave incluye al proveedor, y es lo contrario de cobranzas

`RO_T_CASHFLOW_COBRANZAS_FR_FECHA_MANUAL` tiene unicidad `(T_COMP, N_COMP)` y el cliente queda al lado. **Acá no se puede hacer eso:**

> En ventas el comprobante lo emitimos **nosotros**, así que tipo + número identifica un comprobante y punto. En compras lo emite el **proveedor**. Dos proveedores distintos emiten, cada uno, su factura `A-0001-00000001`.

Verificado: hay **10.293 pares `(T_COMP, N_COMP)` repetidos entre proveedores locales**, sobre 35.777 filas de `CPA04`. Con la clave de cobranzas, la fecha de pago de una factura de Andreani se le aplicaría a una de Telecom.

El vencimiento **no** entra en la clave: un comprobante en cuotas tiene varios, pero la decisión *"esta factura se paga tal día"* se toma por factura. Si algún día hace falta pagar cuota por cuota, se agrega `FECHA_VTO` a la clave y el resto del circuito no cambia.

---

## La conciliación

**Tango es la verdad sobre el pago.** Lo que se carga en la pestaña es una **previsión**: cuándo se piensa pagar. Cuando el comprobante aparece cancelado, deja de proyectarse —sale del listado por sí solo, porque su `ESTADO` ya no es `PEN`—.

### La previsión no se borra al conciliar

Queda marcada `CONCILIADO` con la **fecha real al lado**, sin pisar la prevista.

> Es lo único que permite después contestar la pregunta por la que vale la pena guardar las dos: **¿le acertamos a la fecha?** Borrándola, esa pregunta se queda sin respuesta para siempre.

Por eso el resumen informa el **desvío promedio en días** entre lo previsto y lo real —recién con cinco casos, porque con menos no significa nada—. Ese número dice si la previsión está sirviendo o si siempre se paga más tarde de lo que se dice.

### Detecta los dos sentidos

| | |
| --- | --- |
| **CONCILIA** | estaba `PREVISTO` y el comprobante ya no está pendiente: se pagó |
| **REABRE** | estaba `CONCILIADO` y volvió a estar pendiente. Pasa cuando se anula una orden de pago. Sin esto, esa deuda quedaría marcada como pagada para siempre aunque el tablero la vuelva a mostrar |

**Un comprobante que no aparece en Tango no se toca**: pudo haberse anulado, depurado, o cargado con un código que después cambió. Marcarlo como pagado sería inventar un hecho.

**Consulta sólo lo que tiene previsión**, no las 96.403 canceladas locales: la pregunta es *"de lo que tengo previsto, qué ya se pagó"*. La fecha real sale del último comprobante que canceló (`MAX(CPA05.F_COMP_CAN)`) y **no** de `CPA04`, donde no hay fecha de pago sino de emisión y de contabilización.

Como las dos importaciones, **no escribe nada hasta confirmar**.

---

## Integración con el tablero

`ProveedoresProvider` sirve el código `PROV_LOCALES` con **siete series fijas** y **una por rubro**:

| Serie | Qué trae |
| --- | --- |
| `PAGOS_TODO` | El universo completo, todas las formas de pago |
| `PAGOS` | **Sólo echeq y transferencia** — es la que usa la fila del tablero |
| `PAGOS_FUERA_CRONOGRAMA` | Sólo lo que el criterio deja afuera |
| `PAGOS_OPERATIVOS` | Todo menos los rubros `Excluidos` |
| `PAGOS_EXCLUIDOS` | Sólo los excluidos |
| `PAGOS_CRONO_OPERATIVOS` | **Los dos criterios a la vez** |
| `PAGOS_SIN_RUBRO` | Sólo los que no están en el maestro |
| `PAGOS_EXCLUIDOS_FACTURA` | Sólo las facturas excluidas a mano, una por una |
| `PAGOS_EXCLUIDOS_PROVEEDOR` | Sólo los proveedores excluidos del módulo, enteros |
| `RUBRO_*` | Una por cada rubro económico del maestro |

**`PAGOS` no trae todo**, y el nombre engaña: trae el cronograma. Ver la sección anterior.

Son **dos particiones del mismo universo**, y las dos tienen que cerrar contra `PAGOS_TODO`:

```
PAGOS + PAGOS_FUERA_CRONOGRAMA + PAGOS_EXCLUIDOS_FACTURA
      + PAGOS_EXCLUIDOS_PROVEEDOR                        = PAGOS_TODO  (por cómo se paga)
PAGOS_OPERATIVOS + PAGOS_EXCLUIDOS                       = PAGOS_TODO  (por qué rubro es)
```

Eso lo fija `tests/test_proveedores.php` contra los datos reales: si una de las dos no cerrara, algún comprobante se estaría yendo a la serie equivocada.

### Las dos particiones son independientes, y por eso hace falta la séptima

Los dos criterios no se implican: un socio con rubro `Excluidos` que cobra por **transferencia** entra a `PAGOS`. No es teórico —hoy es **$109,6 M de un solo proveedor** dentro de la fila del tablero—, y hasta que existió `PAGOS_CRONO_OPERATIVOS` no había forma de aplicar los dos criterios a la vez: apuntar la fila a `PAGOS_OPERATIVOS` saca a los socios pero mete de vuelta los débitos automáticos, que es lo contrario de lo que la fila quiere decir.

**Sacar a los socios del tablero sin perder el criterio del cronograma es apuntar la fila a `PAGOS_CRONO_OPERATIVOS` desde Parámetros**: configuración, no código.

`PAGOS_CRONO_OPERATIVOS` **no parte nada**: es la intersección de una mitad de cada partición, así que se solapa con las dos. `PAGOS_SIN_RUBRO` tampoco: cruza las cuatro. El validador lo sabe —ver abajo—.

La regla de reparto vive en `ProveedoresProvider::seriesDeItem()`, estática y pura, y los cuatro cuadrantes se verifican sin base.

**El proveedor crea una serie por cada rubro del maestro, aunque hoy no tenga deuda.** Si no, una fila configurada contra un rubro sin pendientes se dibujaría como *"sin datos"* —con el ícono de que su módulo no devolvió nada— en lugar de mostrar un cero limpio, que es lo cierto.

### Las series por rubro son datos, no código

Cuáles existen depende de lo que administración cargue en el maestro. Escribirlas en `CashflowRegistry` obligaría a tocar código cada vez que aparece un rubro nuevo, que es exactamente lo que ese registro existe para evitar.

Por eso el registro ganó `'series_extra' => [clase, método]`: un punto de extensión acotado, resuelto **una sola vez por pedido** y que **no puede lanzar**. Si el maestro no existe todavía, el proveedor se queda con sus series fijas y el editor de estructura sigue abriendo —un registro que revienta deja sin pantalla a doce módulos que no tienen nada que ver—.

`serieExiste()` pasa ahora por `meta()` y no por la lista cruda: si no, el validador rechazaría una fila configurada contra un rubro del maestro.

**El total y cualquiera de sus aperturas no pueden estar activos a la vez** —sería contar dos veces lo mismo— y las de rubro se agregan solas a `componentes` para que el validador lo rechace.

### El validador mira las partes entre sí, no sólo contra el total

La regla original comparaba el **total** contra cada una de sus partes. Eso dejaba un agujero: `PAGOS` y `PAGOS_OPERATIVOS` son **dos partes**, ninguna es el total, y **se solapan en $1.297 M** —el 89% del universo contado dos veces, sin un solo aviso—.

Por eso el registro declara `particiones`: un mapa `total → corte → series`.

```
por cómo se paga      PAGOS · PAGOS_FUERA_CRONOGRAMA · PAGOS_EXCLUIDOS_FACTURA
                      · PAGOS_EXCLUIDOS_PROVEEDOR
por si está excluido  PAGOS_OPERATIVOS · PAGOS_EXCLUIDOS
por rubro             PAGOS_SIN_RUBRO · RUBRO_*
```

Dos series del **mismo** corte pueden convivir —son dos mitades, y es justamente cómo se mete al tablero lo que hoy queda fuera de la fila—. Dos de cortes **distintos**, no. `PAGOS_CRONO_OPERATIVOS` no figura en ninguno a propósito: es la intersección de una mitad de cada corte, así que se solapa con las cuatro.

El corte *por rubro* lo completa `resolverExtra()` con las series del maestro. Dos rubros distintos nunca comparten un comprobante —cada uno tiene uno solo—, así que todas juntas son un corte: **partir la fila en alquileres, impuestos y logística sigue siendo válido**, que es para lo que existen.

> **Sin `particiones` declaradas no cambia nada.** Un proveedor que no las declara toma todas sus partes como un único corte, que es como se comportaba antes: los cuatro canales de Ventas siguen pudiendo estar los cuatro activos.

### Es un egreso, y devuelve importes positivos

El signo lo pone el `TIPO` de la fila, no el dato. Es la regla de todos los proveedores de egresos; ver la nota de `sql/cashflow_estructura.sql`.

### La fila se renombró

De *Proveedores Locales* a **Cuentas a Pagar Locales**. Está en la sección *Costo de Mercadería*, pero de los $1.361 M pendientes sólo unos 80 son mercadería: el resto es aduana (367 M en **dos códigos distintos**, `OGADUN` y `OGADUA`), seguros, logística, alquileres, servicios, tarjetas y bancos. Dejarla llamándose *Proveedores Locales* dentro de *Costo de Mercadería* haría que la fila diga una cosa y muestre otra.

Es un cambio de dato: el nombre vive en `NOMBRE` y se edita desde Parámetros.

---

## El filtro por forma de pago

**La pestaña abre mostrando sólo lo que se paga por echeq o transferencia**, y la fila del tablero trae lo mismo.

El criterio: entra lo que se paga **decidiendo cuándo**. Una transferencia o un echeq se emiten el día que alguien elige; un débito automático se debita solo y la caja se paga en el mostrador, así que no se planifican de la misma manera.

**Lo que no se sabe, entra y se marca.** Una forma de pago en `null` —porque el proveedor no está en el maestro, o porque lo que trajo la planilla no se reconoció— no es lo mismo que una forma que quedó afuera del criterio: es un dato que falta. Esconder deuda por un dato que falta es la peor razón para esconderla, y además garantiza que nadie lo complete nunca, porque deja de verse. Esas filas se dibujan con la marca *sin forma*, así que no se confunden con un echeq confirmado.

### Una forma en naranja es un typo, y no hay segundo caso

Una forma que llega con el normalizado en `null` se dibuja en naranja con su original: es un typo de la planilla —`eqheck`— y se arregla allá.

**Hubo un segundo caso y ya no existe:** una forma perfectamente válida que había quedado sin normalizar porque el maestro se importó antes de que estuviera declarada en `FORMAS_PAGO`. Eran 133 vencimientos por $88.970.448,93 —129 de `TARJETA CORP` y 4 de `CAJA`— que entraban al filtro como si no se supiera cómo se pagan, y había que distinguirlos con un mensaje aparte porque se arreglaban reimportando y no corrigiendo nada. Derivar la normalización al leer eliminó la categoría entera.

> Esto estuvo invisible un tiempo por otro motivo: `categoria()` no devolvía el `FORMA_PAGO_ORIG` del maestro, así que esas 133 filas se dibujaban *"sin forma"* en gris y la marca naranja —que existe exactamente para este caso— no se ejecutaba nunca.

### Hay tres formas de pago por fila, sólo una decide, y en la grilla se ve una sola columna

Confundirlas fue un bug.

| | Qué es | ¿Decide? |
| --- | --- | --- |
| `FORMA_PAGO_CRONOGRAMA` | Con qué forma hay que tratar a **esta factura**. La pone una persona en la grilla | **Sí**, y le gana al maestro |
| `FORMA_PAGO_MAESTRO` | Cómo se le paga a **ese proveedor**, según el maestro | **Sí**, cuando no hay override |
| `FORMA_PAGO` | Por qué vía salió o va a salir **ese pago**. La trae la importación de la planilla | **No.** Sólo se muestra |

> El filtro mira una **regla**, no un **hecho**. Lo resuelve `Proveedores::formaDelCronograma()`, estática y pura.

```
CRONOGRAMA = esDelCronograma( override de la factura ?? forma del maestro )
```

**En pantalla es UNA sola columna, editable, y muestra la que decide.** `FORMA_PAGO_VIGENTE` viaja ya resuelta en cada fila —la regla se escribe una vez, en el backend, y la grilla muestra lo que decide en vez de una aproximación suya—.

- La opción vacía del desplegable **se nombra**: `CAJA · del maestro`. Así el caso normal muestra la forma real *y de dónde sale*, y volver a ella es lo que saca el override.
- Elegir cualquier otra guarda el override y la celda se marca en violeta.
- **Cuando el hecho difiere de lo que decide**, va un ícono al lado con el detalle, no una columna propia. Hubo dos columnas y se unificaron: obligaban a leer dos celdas para contestar una sola pregunta, y hoy **no hay ni un comprobante donde difieran**.

> Lo que se ve es lo que decide. Es la propiedad que importa en una columna que está al lado de los importes del cashflow: si mostrara una cosa y el tablero usara otra, no habría dónde notarlo.

#### Por qué el override va en su propia columna

> Esto es **nuevo**. Antes la única regla era la del maestro, y cambiarla movía toda la deuda de ese proveedor.

Una factura puntual puede pagarse distinto sin que eso cambie cómo se le paga al proveedor en general. Hasta ahora no había dónde decirlo: o se cambiaba el maestro —y se movía todo— o no se decía.

**Lo que no se podía hacer es que decidiera `FORMA_PAGO`**, que era la columna que ya estaba. Esa es un *hecho*, y **la escribe la importación de la planilla de pagos en todas sus filas**: si decidiera, subir la planilla pasaría a mover comprobantes dentro y fuera del cashflow sin que nadie lo haya pedido. Hoy no hay **ni un comprobante donde las dos difieran**, así que el daño no se vería hasta la primera planilla que traiga una vía distinta de la habitual.

Por eso `aplicarImportacion()` **no toca `FORMA_PAGO_CRONOGRAMA`**, y hay una prueba que lo fija.

- **No toca el maestro.** Las otras facturas del mismo proveedor siguen clasificándose igual.
- **Se valida contra `FORMAS_PAGO`**, al revés que `FORMA_PAGO`, que guarda lo que diga la planilla. Ésta *decide*: una forma que no está en la lista no decidiría nada y quedaría como un override que parece puesto y no hace nada.
- **Sacarlo es elegir "maestro:" en el desplegable.** No borra la fila: la fecha de pago y la observación siguen estando, porque son otra cosa.

**Que el hecho difiera de la regla no es un error: es información.** Significa que a ese proveedor se le pagó por una vía distinta de la habitual, y lo que eventualmente hay que corregir es el maestro.

#### `savePago()` no pisa lo que no le mandaron, y ahora es estructural

`guardarPago()` recibe **un mapa columna → valor con exactamente lo que hay que escribir**, y lo que no está en el mapa no entra ni al `UPDATE` ni al `INSERT`.

Antes eran dos booleanos —`$tocarForma`, `$tocarObs`—, y cada override nuevo obligaba a agregar un flag más y a que todos los llamadores lo pasaran bien. El bug original: un endpoint que recibe un campo y escribe cuatro no está guardando una edición, está reemplazando la fila —y borraba la forma y la observación que había dejado la importación—.

#### La tabla de pagos pasó a ser la tabla de overrides

`RO_T_CASHFLOW_PROV_LOCALES_PAGO` nació como *"las fechas de pago"* y hoy guarda tres decisiones sobre un comprobante: **la fecha, la forma con la que se lo trata y si se lo excluye**. Por eso `FECHA_PAGO` es **nullable**: con `NOT NULL` no había forma de guardar un override sin inventarle además una fecha, y una fecha inventada no es un dato que falte —es un dato falso que después alguien lee como una decisión—.

Una fila sin fecha cae sola al escalón siguiente de la jerarquía, el vencimiento de Tango, que es exactamente lo que pasaba cuando no había fila.

### Los filtros se pueden apagar, y dicen cuánto esconden

Son tres interruptores, y **sus defaults no son todos iguales porque no significan lo mismo**:

| Interruptor | Arranca | Por qué |
| --- | --- | --- |
| *Sólo echeq y transferencia* | **prendido** | Es el cronograma, que es el trabajo normal |
| *Sólo vencidos sin fecha* | apagado | Es un filtro de un clic para aislar lo que falta fechar |
| *Ver excluidas* | **apagado** | Ya se decidió que no van: mostrarlas en el trabajo normal es ruido |

Al lado del período, siempre a la vista:

> *Quedan afuera $141.423.489,13 en 392 vencimiento(s) (TARJETA CORP $88.129.222,37 · DEBITO $52.128.205,29 · CAJA $1.166.061,47) — destildá Sólo echeq y transferencia para verlos. · Hay 1 factura(s) excluida(s) a mano por $7.110.342,48, escondidas y fuera del cashflow — tildá Ver excluidas para revisarlas.*

Un filtro que esconde plata sin decir cuánta es un filtro que miente.

### El tablero también filtra, y eso deja plata afuera

La fila *Cuentas a Pagar Locales* usa la serie `PAGOS`, que **no trae todo**: trae el cronograma. Es una decisión de negocio, y su consecuencia es que **los débitos automáticos y la caja no se proyectan en el cashflow aunque esa plata igual salga**.

Por eso el proveedor **avisa cuánto quedó afuera, desglosado por forma**, en cada carga del tablero.

### `TARJETA CORP` ya no queda afuera del cuadro: entra por otra fila

Desde `feature/financiero-pagos-tarjetas`, las facturas cuya **forma de pago vigente** es `TARJETA CORP` **entran al tablero** por la fila *Pagos con Tarjetas y Otros* (proveedor `TARJETAS`, serie `TOTAL`, sección *Costos Indirectos*). Siguen quedando fuera de **esta** fila —`TARJETA CORP` no es una forma del cronograma— pero ya no fuera del cuadro.

El aviso lo dice separado, porque si no mandaría a buscar plata que ya está contada:

> *De eso, $ 89.858.783,43 de TARJETA CORP **SÍ** entran al cuadro, por la fila «Pagos con Tarjetas y Otros»: no hay que contarlos dos veces. Los otros $ 34.404.742,67 no entran por ninguna fila y van a salir de la caja igual.*

**Y ahí está el riesgo, que es la otra cara:** como entran por allá, esta fila **no** tiene que traerlas.

> ⚠️ **Si alguien apunta una fila activa a `PAGOS_TODO` o a `PAGOS_FUERA_CRONOGRAMA`, esas facturas se cuentan DOS VECES** —una acá y otra en *Pagos con Tarjetas y Otros*— y **el cuadro cierra igual**, así que nada lo delata.

**El validador de estructura no lo puede ver, y no se intentó que lo viera**: son dos proveedores distintos y el solapamiento es de **datos** —las mismas facturas de Tango—, no de series. `componentes` y `particiones` describen relaciones *dentro* de un proveedor; hacer que uno sepa qué datos lee otro acoplaría los dos y rompería justamente lo que hace que agregar un módulo al tablero no toque el motor.

En su lugar hay dos avisos, y alcanzan: el control al pie de `sql/cashflow_tarjetas_fila.sql`, que lo verifica al correr el script y lista las filas culpables, y el de `ProveedoresProvider`, que sale en cada carga del tablero. Verificado contra la base el 26/09/2026: la fila usa `PAGOS` y no hay doble conteo. Ver `README-pagos-tarjetas.md`.

### Lo que sigue quedando afuera del cuadro

Los **débitos automáticos** y la **caja**: ésos no entran por ninguna fila, y el aviso los separa de la tarjeta corporativa justamente para que se vea cuáles son. Si tienen que entrar por otra fila, esa fila todavía no existe; mientras tanto el aviso es lo único que impide que desaparezcan en silencio.

Meterlos es configuración, no código: `PAGOS` y `PAGOS_FUERA_CRONOGRAMA` son las dos mitades del universo y **pueden convivir** en dos filas distintas —el validador lo permite justamente porque no se pisan—. Pero **hoy activar `PAGOS_FUERA_CRONOGRAMA` duplicaría la tarjeta corporativa**, así que meter los débitos y la caja pide antes partir esa mitad, o excluirlos. Lo que nunca puede es `PAGOS_TODO` junto a cualquiera de sus partes.

Lo que entra al filtro, abierto por qué entra:

| | Importe | Venc. |
| --- | ---: | ---: |
| `ECHEQ` | 176.159.353,50 | 95 |
| `TRANSFERENCIA` | 1.092.047.604,71 | 38 |
| sin forma — **el proveedor no está en el maestro** | 6.297.561,76 | 21 |
| *(afuera)* `DEBITO` | *51.804.546,29* | *257* |
| *(afuera)* `TARJETA CORP` | *88.250.698,73* | *129* |
| *(afuera)* `CAJA` | *719.750,20* | *4* |

> Los `TARJETA CORP` y `CAJA` entraban al filtro hasta que la normalización pasó a derivarse al leer: eran **$88.970.448,93 en 133 vencimientos** dentro de la fila del tablero, porque el maestro se había importado con la lista vieja de `FORMAS_PAGO`. Salieron solos, sin reimportar y sin tocar un dato.

Lo único que sigue entrando por *"no se sabe cómo se paga"* son **$6.297.561,76 en 21 vencimientos** de proveedores que no están en el maestro — que es exactamente el caso para el que la regla existe.

---

## La pantalla

Tres sub-solapas, que son tres momentos del mismo circuito:

| | |
| --- | --- |
| **Cuentas a Pagar** | El listado, con la fecha editable celda por celda |
| **Importar** | Las dos planillas, con previsualización del diff |
| **Maestro** | Qué es cada proveedor, qué proveedores faltan, y el alta/edición de a uno |

> **Se llama *Cuentas a Pagar* y no *Pagos Reales***, que era el nombre propuesto. Lo que se carga es una **previsión**; lo real lo dice Tango cuando el comprobante se cancela, y eso lo resuelve la conciliación. Un rótulo que dijera *"reales"* prometería un hecho donde hay un plan.

**El indicador que importa es el segundo:** *Vencido sin fecha*. Va en rojo mientras haya algo y se apaga en verde al llegar a cero — una tarjeta que se ve igual con 839 millones pendientes y con cero no sirve para saber si hay trabajo por hacer. Hay un filtro de un clic para aislar exactamente esas filas.

### Las tarjetas miden lo que la tabla muestra

Los tres filtros —el buscador y los dos interruptores— son del navegador, así que los cuatro indicadores se suman ahí, en el mismo lugar donde ya se sumaba el pie de TOTALES.

> Un número arriba de una tabla describe esa tabla.

Antes salían del backend calculados sobre **todos** los vencimientos: con el filtro por forma de pago prendido —que es el default— la tarjeta decía *549 vencimientos* arriba de una tabla que mostraba **294**, y ni el buscador ni el interruptor de vencidos la movían.

**Lo que el filtro esconde no se pierde:** cuando lo visible difiere del universo, el pie de cada tarjeta dice el total. Es la misma regla del cartel de al lado del período, aplicada a las tarjetas.

Con una excepción deliberada: **la tarjeta roja se apaga en verde por el universo, no por lo visible.** Apagarla porque el filtro escondió lo que falta fechar diría que no hay trabajo por hacer justo cuando lo hay.

Los **avisos** sí siguen contando el universo —son la contrapartida de lo que no se ve— y cada uno lo dice. Un número que no coincide con el de la pantalla y no explica a qué se refiere se lee como un error del sistema.

**La fecha es lo único editable.** La celda tiene la misma pinta que la fecha manual de Cobranzas FR —es el mismo gesto— pero **sin `min` en hoy**: acá se aceptan fechas pasadas, porque el listado no tiene techo de antigüedad y *"se pensó pagar y no se pagó"* es una decisión legítima.

Y se carga de dos formas, que escriben lo mismo: **celda por celda, o para varias de una** desde la barra de selección. Ver *La fecha se carga de a una, o de a muchas*.

Guardar recarga la pestaña entera: la fecha cambia en qué columna del eje cae el importe, los totales del pie y los cuatro indicadores.

Componentes estándar: tarjetas KPI, buscador, selector de eje temporal (`Js/eje-vistas.js`), columnas fijas, Actualizar, Exportar a Excel y avisos por `Js/notificaciones.js`.

### La solapa Maestro: encabezado fijo y su propio Actualizar

> Las dos cosas son **nuevas**, y ninguna cambia ningún número.

**El encabezado de `#tablaMaestro` queda fijo al scrollear.** Son 1.223 proveedores con diez columnas: sin el encabezado a la vista, a la quinta fila ya no se sabe si lo que se está mirando es el rubro o el centro de costos. Es el mismo patrón que la cartera de Echeqs —contenedor con `max-height` y `thead` *sticky*—, con `border-collapse: separate` para que el borde inferior viaje con la celda (con `collapse` el borde es de la tabla, no de la celda, y desaparece justo cuando el encabezado se despega).

El alto lleva **techo y piso**, y el piso es el que importa: arriba de la tabla hay cosas que aparecen y desaparecen —los avisos, el control de faltantes y sobre todo el formulario de alta, que mide unos 200px—. Con sólo un techo calculado para el caso cerrado, abrir el formulario dejaba la tabla en tres filas; con el piso, cuando no entra lo que cede es el alto de la página.

**La solapa tiene su propio botón Actualizar**, igual que la de cuentas a pagar (mismo ícono, mismo texto, mismo estilo). El maestro se cargaba sólo al entrar a la solapa y después de guardar, así que una importación hecha desde otra pestaña no se veía sin recargar la página entera.

**No pisa el trabajo a medio hacer**, que es lo que separa *actualizar* de *cancelar*: el formulario abierto no se repuebla —lo impide `aplicarListas()`— y el filtro del buscador no se toca, porque `pintarMaestro()` lo **lee** del input en vez de guardarlo. Y se apaga mientras la petición está en vuelo, como el resto de los botones del módulo.

---

## Los tres controles

Sin ellos el maestro se desactualiza y nadie se entera.

1. **Proveedores con deuda que no están en el maestro.** Aparecen proveedores nuevos en Tango, nadie los agrega a la planilla, y su deuda queda sin clasificar —o peor, se la lee como si estuviera clasificada—. Se muestran ordenados **por importe**: si la lista es larga, lo que importa es por cuál empezar.
2. **Egresos de directores que el maestro no marca como `Excluidos`.** Ver arriba: manda el maestro, esto audita.
3. **Proveedores vigentes del maestro que no existen en `CPA01`.** Es el control **al revés** del primero: aquél busca deuda sin clasificación, éste busca clasificación sin proveedor. Un código que Tango no tiene no va a cruzar contra ninguna cuenta a pagar nunca; casi siempre es un código tipeado mal de antes de que hubiera validación, pero también puede ser un proveedor que Tango depuró. La lista dice si la versión vigente entró por **carga manual** o por la planilla, porque se corrigen en lugares distintos: una a mano, la otra en el Excel o vuelve en la próxima importación.

> **Sólo avisa: no da de baja nada.** Una baja automática borraría la clasificación de una deuda que puede seguir existiendo, y lo haría sin que nadie lo decida. Es el mismo criterio del control de directores.

---

## Pruebas

```bash
php tests/run.php proveedores
```

`tests/test_proveedores.php` fija sin base lo que decide **qué número sale y dónde cae**:

- **La jerarquía de la fecha** entera, incluida la parte que es fácil romper: que el vencimiento le gane al plazo, que una fecha cargada no se reubique aunque esté vencida, que no haya techo de antigüedad, y que `CONTADO` (cero días) **se use** en lugar de tratarse como "sin plazo".
- **Que el plazo no es un número**: `DEBITO` devuelve `null` y no `0`.
- **La suciedad de la planilla**: que el código repetido deje en error **las dos** filas, que `echeq` matchee y `eqheck` no, y que el typo del criterio se detecte sin lista declarada —y que un criterio raro pero distinto **no** se marque—.
- **Que las filas en error no ensucien las estadísticas de calidad**: una que falló por el código ni siquiera llegó a leer el rubro.
- **La validación contra `CPA01`**: que un código inexistente quede en error y el resto de la planilla se importe igual, que el chequeo de existencia vaya **antes** que el de duplicado —un código que no existe no se puede cargar ni una vez—, y que `null` (no se pudo leer `CPA01`) no marque ninguna fila pero **bloquee la confirmación**, mientras que un mapa vacío sí marca cada fila, porque son dos cosas distintas.
- **La asimetría de alcance**, que es la que se rompe si alguien "unifica" los dos casos: una fila mala deja afuera esa fila y deja confirmar igual; un origen caído no deja confirmar nada.
- **Que la regla corra al aplicar y no sólo al previsualizar**: `revalidarFila()` —pura, probada sin base— rechaza una fila que viene marcada `ALTA` con un código que `CPA01` no tiene, y rechaza un valor que la lista ya no ofrece; y `aplicarImportacion()` revalida **antes** de abrir la transacción, volviendo a leer los dos orígenes en vez de creerle al cuerpo del pedido.
- **La pantalla del maestro**: que el encabezado sea *sticky* con techo **y piso** —el piso es lo que evita que la tabla quede en tres filas con el formulario abierto—, y que el botón Actualizar exista, esté cableado, se apague mientras carga y no pise ni el formulario ni el filtro.
- **El diff de pagos**: que el tipo se deduzca, que un comprobante en cuotas no sea ambiguo, que dos tipos con el mismo número sí lo sean, y que lo que no cruza dé un error con motivo.
- **Que la clave incluya al proveedor**: dos proveedores con el mismo comprobante dan claves distintas.
- **El desvío** de la conciliación en los dos sentidos.
- **Que fechar en masa escriba una sola columna**: la prueba falla si aparece `EXCLUIDA`, `MOTIVO_EXCLUSION`, `FORMA_PAGO`, `OBSERVACION` o `ESTADO` en el cuerpo del método. Es la garantía de que el gesto no reemplaza la fila.
- **Que la transacción esté escrita una sola vez**: que `guardarLote()` la abra y tenga su rollback, que escriba por `guardarPago()`, y que **ninguna de las dos masivas tenga una propia**. Una segunda copia sin rollback no se ve hasta el día que algo falla en el medio.
- **Que la fecha y las claves se validen antes de abrir nada**, que la lista vacía se rechace, y que el 31 de febrero no pase ni por el campo del diálogo ni por el backend.
- **El cableado del gesto de punta a punta**: el botón en la barra, el `conectar()` que lo engancha, el endpoint, y que el check de selección **ya no dependa** del script de la exclusión.

Con base, además: que **ningún pendiente sea negativo** —el error que tenía la consulta antes de la tabla de signos—, que no entre ningún proveedor del exterior, que el total sea exactamente operativos + excluidos, y que **el registro declare exactamente las series que el proveedor devuelve**.

`tests/test_prov_locales_opciones.php` fija las cinco listas, también sin base —llegan por parámetro, ya resueltas—:

- **Qué es pertenecer a una lista**: que la comparación ignore mayúsculas, acentos y espacios, que `50% ECOMMERC` **no** matchee con `50% ECOMMERCE`, y que un campo vacío **no** cuente como fuera de lista (son dos cosas distintas y se cuentan aparte).
- **Que fuera de lista es un error** y la fila no se carga, que el motivo **nombre el campo, el valor y dónde se da de alta**, y que el valor se guarde **tal como vino** aun cuando matchea: corregirlo al canónico cambiaría en silencio la serie del tablero.
- **Que un campo vacío sigue siendo válido** —incluidos los que vienen con espacios sueltos—, que es lo que evita que la planilla real quede rechazada entera.
- **Que un error del código le gana al de fuera de lista**: los dos son errores y el motivo es uno solo, así que gana el que dice qué hacer.
- **Que sin listas cargadas no se valida nada y se puede confirmar igual**, y que con las listas **ilegibles** no se marca nada pero **no se puede confirmar**: son los dos `null` que antes eran el mismo.
- **Que los valores rechazados se agrupen sin repetir**: `DEPOSITO SUR` y `Deposito Sur` son un valor que dar de alta, aunque sean dos filas rechazadas.
- **Que una fila rechazada NO sea una fila ausente**, que es el riesgo más grave del cambio y el más silencioso: las bajas son *"el archivo no los trae"*, así que si una fila rechazada no contara como traída, cada valor fuera de lista propondría dar de baja a un proveedor que la planilla **sí** trae — y con el interruptor de bajas tildado le borraría la clasificación a cientos. Y que el que el archivo realmente no trae se siga proponiendo.
- **Que una fila rechazada siga entrando al control de duplicados**, en los dos órdenes: su código es válido, y sin eso un código repetido donde una de las dos filas tiene un rubro inválido cargaría la otra sin avisar que estaba repetido. Las que fallan **por el código** siguen sin entrar: ese código no se puede cargar ni una vez.
- **La semántica del plazo entera**: que los días salgan de la lista, que eso permita declarar `FIN DE MES` —que `plazoEnDias()` sola devuelve `null`—, que `CONTADO` sea `0` y `DEBITO` siga siendo `null`, y que un plazo fuera de lista caiga al fallback de siempre **y además deje la fila en error**.
- **Que el formulario no pierda un valor fuera de lista** al editar un proveedor, y que lo marque en el campo.
- **Que el desplegable busque sin librerías** —la prueba falla si aparece Select2, Tom Select o Choices—, que exponga `.value` como un `<select>`, y que diga dónde se dan de alta los valores cuando el filtro no encuentra nada.
- **Que el orden sea alfabético en los dos lados**, con `localeCompare` en `es` en el front y `alfabetico()` en el backend — incluido que la `Ñ` quede entre la `N` y la `O` y no al final.
- **Que la columna `ORDEN` siga editable y que la pantalla aclare para qué sirve.**

*Suite completa: 3427 OK, 0 fallas (24 archivos).*

---

## Archivos

```
sql/cashflow_prov_locales.sql                  Las dos tablas + la fila del tablero
sql/cashflow_prov_locales_collation.sql        Alinea la collation con la de Tango
sql/cashflow_prov_locales_maestro_manual.sql   ORIGEN: habilita la carga a mano
sql/cashflow_prov_locales_forma_por_factura.sql  El override de forma por factura
sql/cashflow_prov_locales_excluir_factura.sql    El tilde de exclusion por factura
sql/cashflow_prov_locales_opciones.sql         Las cinco listas de opciones + semilla
sql/_referencia_tango_pendientes.sql           La consulta de Tango, como referencia
cashflow/Class/Planilla.php                    El mecanismo de importacion CSV, compartido
cashflow/Class/Proveedores.php                 Cuentas a pagar, fechas de pago y conciliacion
cashflow/Class/ProveedoresCategorias.php       El maestro y el resolutor de categoria
cashflow/Class/ProveedoresTango.php            CPA01: quien existe como proveedor. Solo lectura
cashflow/Class/ProveedoresOpciones.php         Las cinco listas de valores validos
cashflow/Tabs/parametros_prov_locales.php      Su ABM, en Parametros
cashflow/Js/Parametros-Prov_locales.js
cashflow/Class/Providers/ProveedoresProvider.php
cashflow/Controller/ProveedoresController.php
cashflow/Tabs/proveedores_locales.php
cashflow/Js/Proveedores-Proveedores_locales.js
cashflow/Css/Proveedores-Proveedores_locales.css
tests/test_proveedores.php
```

Modificados: `Class/CashflowRegistry.php` (`PROV_LOCALES` disponible + `series_extra`) · `Class/CobElectronicos.php` (delega en `Planilla`, sin cambiar su contrato) · `Class/Menu.php` (la pestaña pasa a `DATOS`) · `Class/Parametros.php` (el módulo `PROV_LOCALES` y su sección) · `Tabs/parametros.php` (la sub-pestaña) · `Js/notificaciones.js` (`pedirFecha()`, el tercer diálogo sobre el mismo armazón) · `tests/test_providers.php` y `tests/test_menu.php` (los conteos).

Pruebas propias de las listas: `tests/test_prov_locales_opciones.php`.

---

## Pendientes conocidos

- **639 de los 1.173 proveedores del maestro tienen la forma de pago sin normalizar en la columna** —`CAJA`, `TARJETA CORP` y `MERCADO PAGO`, las tres que faltaban en la primera versión de `FORMAS_PAGO`—. **Ya no afecta a nada**: la normalización se deriva al leer, así que esas filas se clasifican bien igual. La columna se acomoda sola la próxima vez que se reimporte el maestro por cualquier otro motivo; no hace falta hacerlo por esto.
- **ARCA/Aduana está cargada con dos códigos** (`OGADUN` $235,4 M y `OGADUA` $131,8 M, mismo nombre). No se unifican en el resolutor: la clave es el código de Tango y arreglar el maestro no le toca a este módulo. Si los dos llevan el mismo rubro, el tablero los junta solo. El control de faltantes los muestra por separado, que es lo que va a revelar si la planilla trae uno solo.
- **Las series por rubro se resuelven contra el maestro en cada pedido.** Con 26 rubros y un cache por request alcanza; si algún día el maestro creciera mucho, el lugar para mirar es `CashflowRegistry::resolverExtra()`.
- **Antes de la primera importación con las listas como regla, hay que completar las cinco listas de *Parámetros*.** Mientras falten valores en uso, la planilla real va a rechazar filas en masa. La previsualización los lista agrupados justamente para poder cargarlos de una pasada, pero conviene hacerlo **antes** y no descubrirlo importando.
- **Un proveedor viejo con un valor fuera de lista no se puede guardar hasta que alguien resuelva ese valor.** Se abre, se ve y no se pierde —y el campo lo avisa en naranja— pero `Guardar` lo rechaza, incluso si lo que se estaba editando era otro campo. Es la contracara de que las listas signifiquen algo; si resultara molesto en la práctica, la discusión es si el alta manual debe poder conservar un valor heredado, no si la importación debe aceptarlo.
