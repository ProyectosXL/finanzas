---
titulo: Gastos Locales
pestana: gastos_locales
categoria: Proveedores
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Administración
bajada: Lo que los locales propios pagan con la caja, proyectado desde el promedio de los últimos 90 días y llevado a cada mes con la inflación.
---

## Para qué sirve

Proyecta lo que los locales propios van a pagar con el efectivo de su caja: los gastos que salen del local y no pasan por Cuentas a Pagar.

No hay facturas ni compromisos de por medio: se toma lo que cada local gastó en los últimos 90 días, se saca el gasto de un día y se proyecta hacia adelante. En las semanas del tramo diario va una semana de gasto el día de pago del cronograma; después, un importe por mes. Todo se lleva a moneda del mes en que se paga con la inflación esperada.

Es el detalle de la fila Gastos Locales del tablero: lo que se ve en sus columnas es exactamente lo que esa fila proyecta. Ver [cómo se lee el tablero](ayuda:general_tablero).

Este artículo también explica, para todo el módulo, [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos) de Parámetros › Calendario y [la inflación](ayuda:gastos_locales#la-inflacion) de Parámetros › Generales.

## De dónde salen los datos

:::diagrama
fuente: La tesorería de los locales en Tango | los egresos de caja de cada local propio, cuenta por cuenta
fuente: Los locales propios habilitados | la misma lista que usa la caja de los locales en Saldos
fuente: Parámetros › Generales | las cuentas que no entran y la inflación mensual esperada
fuente: Parámetros › Calendario | el día de pago, los feriados bancarios y el largo del tramo diario
pestana: Gastos Locales | saca el gasto diario y lo reparte en semanas y meses
tablero: Fila Gastos Locales | todo proyectado, de hoy en adelante
alt: La tesorería de los locales en Tango, la lista de locales propios y los dos Parámetros alimentan la pestaña Gastos Locales, que pasa su proyección a la fila Gastos Locales del tablero.
:::

- **La tesorería de los locales en Tango**: los comprobantes de caja de cada sucursal. Se leen cada vez que se abre la pestaña; no se guarda nada. Entran sólo:
  - los **egresos** (lo que sale de la caja);
  - de las cuentas cuyo código **empieza con 5**;
  - menos las **cuentas excluidas** de Parámetros › Generales (por defecto, la 522400);
  - de los **90 días corridos que terminan ayer**. Lo de hoy no entra;
  - de los locales **propios y habilitados**. Las franquicias y los locales cerrados quedan afuera, y los egresos de la sucursal 1 no se cuentan.
- **Los locales propios habilitados**: la lista completa, para que también se vean los que no gastaron nada en 90 días, con $ 0.
- **Parámetros › Generales**: qué cuentas no entran en el promedio y [la inflación](ayuda:gastos_locales#la-inflacion) de cada mes.
- **Parámetros › Calendario**: [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos) (qué día de la semana se paga), los feriados bancarios y las **Columnas diarias**, que dicen cuántos días tiene el tramo diario.

## Cómo se calcula

Todo lo de esta pestaña es proyección: no hay una parte real. Lo que ya se pagó está en el saldo de los bancos, que es con lo que abre el tablero.

### El gasto diario de cada local

:::formula
Gasto diario = total de los últimos 90 días ÷ 90
:::

Siempre se divide por 90, también para un local que gastó en pocos días: un día sin gastos también es un dato. Lo mismo para cada cuenta del detalle.

Sólo se suman los egresos: un movimiento de ingreso en una cuenta 5, como una reversión, no resta.

### Las semanas del tramo diario

En el tramo diario se paga **una semana de gasto** cada día de pago del cronograma **Gastos Locales** (por defecto, todos los lunes):

:::formula
Semana = gasto diario × 7 × factor de inflación del mes en que se paga
:::

- Si el día de pago no es hábil, se paga el **día hábil siguiente**.
- Una semana entra si su día de pago **antes de correrse** cae dentro del tramo diario.
- Un pago con fecha de **hoy o anterior no se proyecta**: esa plata ya salió y está en el saldo. Se mira la fecha ya corrida: si hoy es un lunes feriado, el pago pasa a mañana y sí se proyecta.

Con 28 columnas diarias se pagan 4 semanas. Si hoy es día de pago y es hábil, son 3: la de hoy ya está en el saldo.

### Los meses

Después del tramo diario va **un importe por mes**, con los días de ese mes que quedan fuera del tramo:

:::formula
Mes = gasto diario × días del mes después del tramo diario × factor de inflación del mes
:::

No es "30 días por mes": un mes entero cuenta sus 28, 29, 30 o 31 días, y un mes partido por el final del tramo cuenta sólo su parte. Si las **Columnas diarias** están en 0, no hay semanas y todo va a los meses, desde mañana.

**Ningún día se cuenta dos veces.** Las semanas cuentan pagos y los meses cuentan días posteriores al tramo. Si el último día de pago del tramo es feriado y se paga el día siguiente, ya fuera del tramo, ese pago cae en la columna de su mes, pero el mes no vuelve a contar esa semana.

:::ejemplo ilustrativo
Un local gastó **$ 9.000.000** en los últimos 90 días: **$ 100.000** por día. Hoy es jueves 12 de noviembre y las columnas diarias son 28, así que el tramo diario va del 12/11 al 9/12. Se paga todos los lunes y, supongamos, el lunes 23 es feriado. La inflación cargada es 2 % para diciembre y 3 % para enero.

| Partida | Cuándo | Cuenta | Importe |
| --- | --- | --- | ---: |
| Semana | Lunes 16/11 | 100.000 × 7 × 1 (mes en curso) | $ 700.000 |
| Semana | Martes 24/11 (el 23 no es hábil) | 100.000 × 7 × 1 | $ 700.000 |
| Semana | Lunes 30/11 | 100.000 × 7 × 1 | $ 700.000 |
| Semana | Lunes 7/12 | 100.000 × 7 × 1,02 | $ 714.000 |
| Diciembre | Del 10 al 31: 22 días | 100.000 × 22 × 1,02 | $ 2.244.000 |
| Enero | 31 días | 100.000 × 31 × 1,02 × 1,03 | $ 3.256.860 |

La tarjeta **Promedio mensual** diría $ 3.000.000 (100.000 × 30), pero la columna de enero dice $ 3.256.860: enero tiene 31 días y lleva la inflación de diciembre y la de enero.
:::

### El cronograma de pagos

Dice qué día se paga cada concepto que no tiene una fecha propia. Se cambia en **Parámetros › Calendario › Cronograma de pagos**, y lo usan cuatro conceptos, cada uno con su día de la semana y su frecuencia:

| Concepto | Lo usa | Por defecto | Si el día no es hábil | Se mueve a mano |
| --- | --- | --- | --- | --- |
| **Proveedores Locales** | Cuentas a Pagar Locales | El 2do y el 4to miércoles | Se paga el hábil anterior | Sí |
| **Logística Local** | Logística Local | El 2do y el 4to viernes | Se paga el hábil anterior | Sí |
| **Supervisoras** | La parte en efectivo de Gastos Supervisoras, en Pagos con Tarjetas y Otros | Todos los lunes | Se paga el hábil siguiente | Sí |
| **Gastos Locales** | Gastos Locales, en las semanas del tramo diario | Todos los lunes | Se paga el hábil siguiente | No |

El valor por defecto es el que se usa si el cronograma de ese concepto no está configurado, con un aviso.

**Las dos frecuencias:**

- **Quincenal**: el 2do y el 4to de ese día de la semana en el mes. Son siempre dos pagos, también en un mes que tiene cinco: el quinto no es un pago.
- **Semanal**: todos los de ese día en el mes, cuatro o cinco.

**Gastos Locales es siempre semanal**: su importe es una semana de gasto, y quincenal pagaría la mitad. Su desplegable de **Frecuencia** sólo ofrece **Semanal**.

**Hábil** es un día que el banco opera: de lunes a viernes, menos los feriados bancarios (el calendario bancario más lo que se agrega o se quita en Parámetros › Calendario › Feriados). Sábado y domingo nunca son hábiles. Si el calendario no tiene datos de un mes, se toman hábiles los días de lunes a viernes y un aviso lo dice. Los feriados de comercio no corren ningún pago: son días sin venta, otra cosa.

**El corrimiento**: si el día de pago no es hábil, el pago va al día hábil anterior, salvo **Supervisoras** y **Gastos Locales**, que van al siguiente. Por eso un pago puede caer en otro mes: el 1er lunes feriado de un mes que empieza lunes, en un concepto que corre al anterior, se paga el viernes del mes anterior.

**Las fechas no se guardan: se calculan.** Si se agrega o se quita un feriado bancario, los pagos se corren solos. Lo único que se guarda es un pago movido a mano.

**Las tres tablas de la pantalla.**

- **Configuración**: una fila por concepto, con **Concepto**, **Lo usa**, **Día de la semana**, **Frecuencia** y **Se paga** (por ejemplo, "todos los lunes de cada mes"). El chip **por defecto** dice que ese concepto no tiene el parámetro cargado.
- **Próximos pagos**: los dos próximos pagos de cada concepto que se puede mover a mano, con **Pago**, **Teórica** (el día que dice la configuración), **Calculada** (ya corrida, con el chip **corrida** si se corrió), **Fecha de pago** y **Motivo**. Gastos Locales no va acá.
- **Resto del horizonte**: un renglón por mes y una columna por concepto, con todas sus fechas. Se ven, no se editan. Llevan el chip **corrida** o **a mano** cuando corresponde.

**Cómo usa las fechas cada concepto.**

- **Proveedores Locales**: cada factura se paga en el primer día de pago desde que vence, o desde hoy si ya venció.
- **Logística Local** y **Supervisoras**: el importe de un mes se reparte en partes iguales entre todos sus pagos —mitad y mitad en quincenal, un cuarto o un quinto en semanal—, contando también los que ya pasaron.
- **Gastos Locales**: una semana de gasto en cada pago del tramo diario. Ver [las semanas del tramo diario](ayuda:gastos_locales#las-semanas-del-tramo-diario).

**Los pagos movidos a mano.**

Un pago se puede mover a otra fecha cuando se sabe algo que el calendario no sabe, por ejemplo que se adelanta por vacaciones.

- **Se pueden mover** los de Proveedores Locales, Logística Local y Supervisoras. Los de Gastos Locales, no: nadie mueve el día de un promedio.
- **Sólo los dos próximos** de cada concepto, en la tabla **Próximos pagos**.
- **El motivo es obligatorio**: después es lo único que explica por qué ese pago no cayó donde decía la cuenta.
- **Hasta 31 días** de su fecha teórica, para que un año mal tipeado no mande el pago a otro mes sin que nadie se dé cuenta.
- **La fecha a mano no se corre**, aunque caiga en un día no hábil: se respeta tal cual. **Salvo en Supervisoras**: ahí una fecha a mano que no es hábil se paga el hábil siguiente, y la fila lo muestra con un chip **se paga el dd/mm/aaaa**.
- Queda con el chip **a mano**, y quién lo movió y cuándo.

**Qué lo vuelve a la fecha calculada:**

- El botón **Volver a la fecha calculada** de su fila.
- **Cambiar el día o la frecuencia del concepto.** Da de baja los pagos movidos a mano de ese concepto desde el mes en curso, incluido: con otro día, el pago 2 de un mes es otra fecha. Los de meses anteriores quedan. Antes de guardar, la confirmación dice cuántos son. En Logística Local, además, se pierden los importes editados del próximo pago de los fleteros, y la confirmación nombra cada uno.

**Lo que no lo toca:** volver a guardar el mismo día y la misma frecuencia, agregar o quitar un feriado, o cambiar el horizonte. El pago movido se guarda por concepto, mes y número de pago, no por fecha.

Nada se borra: un pago que vuelve a la fecha calculada queda en el historial (el botón del reloj de su fila), con quién lo movió, cuándo y quién lo dio de baja.

### La inflación

Es la **Inflación mensual esperada** de **Parámetros › Generales**: el % de cada mes calendario. Se carga en puntos: `2` es 2 %. Acepta de −50 a 100.

**La grilla tiene 14 meses**: los 2 anteriores al mes en curso, el mes en curso y los 11 siguientes. Las columnas son **Mes**, **% mensual**, **Cargado como**, **Última edición** y **Se proyecta**. En **Se proyecta**, los 11 siguientes dicen **Sí**; los tres primeros, incluido el mes en curso, dicen "Ya pasó: sólo sirve para un ajuste viejo". Un mes sin valor lleva el chip **sin cargar**. Ningún mes se borra cuando la ventana se corre.

**Las dos modalidades:**

- **Variable — un % por mes**: se escribe el % en la celda de cada mes y se guarda al salir del campo. Queda **Cargado como** VARIABLE.
- **Constante — un único % para todos los meses**: se escribe en **% mensual** y se toca **Aplicar a todos**, que escribe ese % en los 14 meses de la grilla.

Lo que el cálculo lee es **siempre la grilla, mes por mes**. Cambiar la modalidad no cambia ningún mes: para que el % único rija hay que tocar **Aplicar a todos**.

**Compuesta: Gastos Locales, Gastos Supervisoras, Tarjetas Pagos Corporativos y Tarjetas Socios.**

Estas pestañas parten de un importe ya conocido (un promedio, un resumen, una factura) y lo llevan a moneda del mes en que se paga. La inflación se **compone**: cada mes sube sobre el anterior ya aumentado.

:::formula
Factor del mes M = (1 + % del mes siguiente al base ÷ 100) × … × (1 + % de M ÷ 100)
:::

- **El mes base vale 1**: no se ajusta, y un mes anterior al base tampoco.
- **En Gastos Locales el mes base es el mes en curso.** Lo que se paga este mes va sin ajuste; el mes que viene lleva su %; el siguiente, los dos compuestos. El % del mes en curso no se usa.
- En las solapas de Pagos con Tarjetas y Otros, el mes base es el del dato del que parte cada una. Lo explica ese artículo.

:::ejemplo ilustrativo
Hoy es noviembre. La grilla dice 2 % para diciembre y 3 % para enero.

- Noviembre: factor **1**.
- Diciembre: 1,02.
- Enero: 1,02 × 1,03 = **1,0506**, no 1,05: el 3 % de enero se aplica sobre lo que ya subió en diciembre.
:::

**Si falta el % de un mes**, todo importe que lo necesita queda **en blanco, nunca en cero**: un cero se leería como que no hay nada que pagar. Para proyectar un mes hacen falta todos los meses desde el siguiente al base hasta él, así que si falta diciembre quedan en blanco diciembre y todos los que siguen. Los meses que no lo necesitan se proyectan igual. Un aviso dice qué meses cargar. En el tablero, lo que queda en blanco no suma.

La grilla llega hasta 11 meses después del actual, que es lo que cubren 12 **Columnas mensuales**. Con más columnas, los meses de más no tienen dónde cargarse y quedan en blanco: el aviso dice que están fuera de la ventana editable.

**Sumada: Logística Local, Alquileres y Seguros.**

Un ajuste pactado se enuncia como una suma, y así se calcula: sin componer.

- **Logística Local**: el ajuste trimestral del valor hora de los fleteros suma la inflación del mes del ajuste y la de los dos anteriores. Ver Logística Local.
- **Alquileres**: el ajuste de cada local suma la inflación de los meses de su frecuencia de ajuste.
- **Seguros**, en Pagos con Tarjetas y Otros: la continuación de una póliza en pesos suma la inflación de los meses de su vigencia anterior.

Por eso la grilla guarda los meses que ya pasaron: un ajuste de este mes puede necesitar la inflación de los dos anteriores.

## La pantalla

La pantalla es de sólo lectura. De arriba hacia abajo:

- Los avisos de la pestaña: los suyos y los que el tablero dice de ella.
- Las tres tarjetas.
- La tarjeta **Gastos de caja por local**, con el ícono de información que explica la cuenta.
- El buscador **Buscar local…** y los botones de las tres vistas del eje.
- **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea del período que se mide y del cronograma.
- La grilla, con un local por fila.
- El pie **TOTALES**.
- La nota de abajo, que resume cómo se reparte el gasto.

### Las tarjetas

| Tarjeta | Qué dice |
| --- | --- |
| **Total de los últimos 90 días** | Todos los egresos que entran en el promedio, de todos los locales. Debajo, "Del dd/mm/aaaa al dd/mm/aaaa · N locales (M sin egresos)". |
| **Promedio mensual** | El gasto diario de todos los locales × 30. Debajo, "Gasto diario $ X × 30". Es una referencia: las columnas de los meses usan los días de cada mes y la inflación. |
| **Proyectado** | Lo que se proyecta en la vista que estás mirando (Días, Meses o Período completo), con inflación. |

Las tres miden todos los locales: no cambian con el buscador. Si los gastos no se pudieron leer, muestran "—".

### La línea del cronograma

Debajo de los botones dice qué período se mide y "Se paga todos los lunes: N semana(s) en el tramo diario. Cuentas excluidas: 522400." (con el día y las cuentas que estén cargados; "ninguna" si la lista está vacía), y que el día se cambia en Parámetros › Calendario y las cuentas en Parámetros › Generales.

### La grilla

| Columna | Qué muestra |
| --- | --- |
| **Local** | El nombre y el número del local. La flecha de la izquierda abre el detalle. Un local sin ningún egreso en la ventana lleva el chip **sin egresos en 90 días**. |
| **Total 90 días** | Lo que el local gastó en la ventana. |
| **Gasto diario** | Total 90 días ÷ 90. |
| Una por día o por mes | Lo que se proyecta en esa columna, según la vista. |
| **Total** | La suma de las columnas de la vista que estás mirando. |

- **Las tres vistas**: **Días**, **Meses** y **Período completo**. Ver [las tres vistas](ayuda:general_tablero#las-tres-vistas).
- **El tooltip**: pasá el mouse por un importe y vas a ver de dónde sale: si es una semana o un mes, cuántos días, el gasto diario y su cuenta, y la inflación con el % de cada mes. Por ejemplo: "Semana pagada el 24/11 (el 23/11 no es hábil: se corre al día hábil siguiente): 7 días. $ 100.000,00 por día ($ 9.000.000,00 / 90). Mes en curso: sin ajuste por inflación. Importe $ 700.000,00." Si una celda junta dos partidas, como una semana corrida y su mes, el tooltip trae las dos.
- **Un guión** quiere decir que no hay nada en esa columna o que falta la inflación de ese mes. El tooltip dice cuál de las dos.
- Un local **sin egresos en 90 días** muestra $ 0,00 en las columnas donde se paga.
- Los sábados, domingos y feriados tienen la columna marcada.
- La grilla no se ordena por columna: cada local lleva su detalle debajo.
- La columna **Local** queda fija al moverte a lo ancho. Con **Columnas fijas** se elige cuáles.

### El detalle de un local

Tocá la flecha del local. Aparece una fila por cuenta, "código — descripción (N mov.)", con su total y su gasto diario, de la que más pesa a la que menos. Al final, el enlace **Ver los comprobantes de los 90 días**.

Si el local no tuvo egresos, dice "Sin egresos de caja en cuentas 5 en los últimos 90 días."

### Los comprobantes

El enlace abre **Egresos de caja**, con el nombre del local, y una fila por comprobante: **Fecha**, **Comprobante**, **Cuenta**, **Leyenda**, **Usuario** e **Importe**, del más nuevo al más viejo. Si un renglón no tiene leyenda, se muestra la de otro renglón del mismo comprobante. Abajo dice "N comprobante(s) · total $ X", que es el **Total 90 días** del local. **Exportar** baja la lista a Excel.

### El pie

**TOTALES** suma los locales que se están viendo: **Total 90 días**, **Gasto diario**, cada columna y **Total**. Sin búsqueda, es exactamente la fila Gastos Locales del tablero. Arriba de cada fecha va el mismo total de la columna.

**Exportar** baja a Excel lo que se ve: la vista, la búsqueda y los detalles que estén abiertos.

## Tareas frecuentes

### Ver de dónde sale un importe

:::pasos
1. Buscá el local con **Buscar local…** (por nombre o por número).
2. Pasá el mouse por el importe.
Resultado: el tooltip dice si es una semana o un mes, cuántos días, el gasto diario y la inflación con la que se llevó a ese mes.
:::

### Ver qué gastó un local

:::pasos
1. Tocá la flecha a la izquierda del local.
2. Mirá qué cuentas pesan más.
3. Tocá **Ver los comprobantes de los 90 días**.
Resultado: se abre la lista de comprobantes de caja de ese local en la ventana, que se puede exportar a Excel.
:::

### Dejar una cuenta afuera del promedio

:::pasos
1. Andá a **Parámetros › Generales**, tarjeta **Gastos Locales**.
2. En **Cuentas excluidas**, agregá el código de la cuenta, separado de los otros por coma.
3. Tocá **Guardar**.
Resultado: el mensaje dice "Listo: el promedio de Gastos Locales deja afuera …" con la lista. La pestaña y el tablero recalculan sin esa cuenta la próxima vez que se abren.
:::

Si dejás la lista vacía, entran todas las cuentas 5. Los ceros a la izquierda y los repetidos se sacan solos.

### Cambiar el día en que se pagan los gastos de los locales

:::pasos
1. Andá a **Parámetros › Calendario**, tarjeta **Cronograma de pagos**, tabla **Configuración**.
2. En la fila **Gastos Locales**, elegí el **Día de la semana**.
3. Leé la confirmación, "¿Pagar Gastos Locales todos los …s de cada mes?", y tocá **Cambiar**.
Resultado: el mensaje dice "El cronograma de Gastos Locales pasa a ser todos los …s de cada mes." y las semanas del tramo diario se pagan ese día.
:::

Si cancelás, el desplegable vuelve a lo que estaba guardado.

### Mover a mano un pago de otro concepto

Vale para Proveedores Locales, Logística Local y Supervisoras.

:::pasos
1. Andá a **Parámetros › Calendario**, tarjeta **Cronograma de pagos**, tabla **Próximos pagos**.
2. En la fila del pago, elegí la fecha nueva en **Fecha de pago**.
3. Escribí el **Motivo**.
4. Tocá el botón del tilde.
Resultado: el mensaje dice "Fecha movida: el … de aaaa-mm de … va el aaaa-mm-dd." y la fila queda con el chip **a mano**.
:::

### Volver un pago a su fecha calculada

:::pasos
1. En **Próximos pagos**, buscá la fila con el chip **a mano**.
2. Tocá **Volver a la fecha calculada** (la flecha circular).
Resultado: el mensaje dice "El pago vuelve a su fecha calculada. La fecha anterior queda en el historial: no se borra."
:::

### Cargar la inflación de los próximos meses

:::pasos
1. Andá a **Parámetros › Generales**, tarjeta **Inflación mensual esperada**.
2. Si es un mismo % para todos los meses, elegí la modalidad **Constante**, escribí el % en **% mensual** y tocá **Aplicar a todos**.
3. Si cambia mes a mes, escribí el % en la celda de cada mes y salí del campo.
Resultado: los meses dejan de decir **sin cargar** y los importes en blanco de Gastos Locales se completan la próxima vez que se abre la pestaña.
:::

## Parámetros

En esta pestaña no se edita nada. Lo que mueve sus números está en dos sub-pestañas de Parámetros, cada una con su permiso. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Cuentas excluidas** | Parámetros › Generales › Gastos Locales | 522400 | Qué cuentas 5 no entran en el promedio. Vacío: entran todas |
| **Inflación mensual esperada** | Parámetros › Generales | Sin cargar | El factor de cada mes posterior al actual. Un mes sin cargar deja en blanco ese mes y los siguientes |
| **Día de la semana** de Gastos Locales | Parámetros › Calendario › Cronograma de pagos | Lunes | Qué día se paga cada semana del tramo diario |
| **Frecuencia** de Gastos Locales | Parámetros › Calendario › Cronograma de pagos | Semanal | No se cambia: es la única opción |
| **Columnas diarias** | Parámetros › Calendario › Horizonte de proyección | 28 | Cuántos días tiene el tramo diario: cuántas semanas se pagan y desde cuándo van los meses |
| **Columnas mensuales** | Parámetros › Calendario › Horizonte de proyección | 12 | Hasta qué mes se proyecta |
| Feriados bancarios | Parámetros › Calendario › Feriados | Los del calendario bancario | Qué día de pago no es hábil y se corre al siguiente |

Los 90 días de la ventana no son un parámetro: están fijos.

## Avisos y qué hacer

Los avisos se ven arriba de la grilla, con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: Falta la inflación esperada de aaaa-mm, …. Se carga en Parámetros › Generales. Los meses que dependen de eso quedan en blanco y NO en cero: un cero se leería como que no hay nada que pagar.
significa: Algún mes del horizonte no tiene su % de inflación. Ese mes y los siguientes quedan en blanco en la grilla y no suman en el tablero. Si el mes está fuera de la grilla de Parámetros, el aviso agrega "aaaa-mm queda FUERA de la ventana editable de la grilla, que arranca 2 meses antes del mes en curso, así que hoy no hay dónde tipearlo."
corrige: Cargá el % de esos meses en Parámetros › Generales › Inflación mensual esperada. Si el mes está fuera de la grilla es porque el horizonte tiene más de 12 **Columnas mensuales**: bajalas a 12 en Parámetros › Calendario o avisá a sistemas.
:::

:::aviso atencion
texto: La lista de cuentas excluidas de Gastos Locales tiene un valor que no se entiende (…). Se excluye la 522400. Corregila en Parámetros › Generales.
significa: Lo guardado en **Cuentas excluidas** no es una lista de códigos. Mientras tanto se deja afuera sólo la 522400.
corrige: Parámetros › Generales › Gastos Locales: escribí los códigos separados por coma y tocá **Guardar**.
:::

:::aviso atencion
texto: El cronograma de Gastos Locales tiene un valor que no se entiende (…). Se usa el valor por defecto, todos los lunes. Corregilo en Parámetros › Calendario.
significa: El día o la frecuencia guardados no son válidos. Mientras tanto se paga todos los lunes.
corrige: Parámetros › Calendario › Cronograma de pagos: elegí el día en la fila **Gastos Locales**.
:::

:::aviso info
texto: Hay $ X de gastos de locales con fecha posterior al final del horizonte, así que no entran en ninguna columna.
significa: El último pago del tramo se corrió a un día que ya está después del último mes del horizonte. Sólo pasa con un horizonte muy corto.
corrige: Si lo querés ver, subí las **Columnas mensuales** en Parámetros › Calendario.
:::

:::aviso info
texto: No se pudo leer la lista de locales propios (…): sólo se ven los que tuvieron egresos. El total no cambia.
significa: No aparecen los locales que no gastaron nada en 90 días. Los números están bien.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

### En Parámetros › Generales y Parámetros › Calendario

Esas sub-pestañas también pueden mostrar estos. Los de cada concepto del cronograma salen con su nombre y su valor por defecto en lugar de los puntos suspensivos:

:::aviso atencion
texto: El cronograma de … tiene un valor que no se entiende (…). Se usa el valor por defecto, …. Corregilo en Parámetros › Calendario.
significa: El día o la frecuencia guardados de ese concepto no son válidos. Mientras tanto se paga como dice el valor por defecto.
corrige: Elegí de nuevo el día y la frecuencia de ese concepto en **Configuración**.
:::

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción en Parámetros.

| Mensaje | Cuándo |
| --- | --- |
| "Listo: el promedio de Gastos Locales deja afuera …." | Se guardaron las cuentas excluidas. |
| "Listo: no se excluye ninguna cuenta. El promedio de Gastos Locales toma todas las cuentas 5." | Se guardó la lista vacía. |
| "'…' no es un código de cuenta: van sólo números, separados por coma." | En **Cuentas excluidas** hay algo que no es un número. No se guarda. |
| "La cuenta 0 no existe." | Se escribió una cuenta 0. No se guarda. |
| "No hay pagos movidos a mano de … desde este mes, así que no se da de baja ninguno." | La confirmación de un cambio de día o frecuencia, sin pagos movidos. Con pagos movidos dice "Se dan de baja N pago(s) movido(s) a mano de … desde este mes: con otro día apuntan a otra fecha. Quedan en el historial." |
| "El cronograma de … pasa a ser … de cada mes." | Se cambió el día o la frecuencia. Si había pagos movidos a mano, agrega cuántos se dieron de baja. |
| "El cronograma de … ya era ese: no se cambió nada." | Se guardó la misma configuración. No se da de baja ningún pago movido. |
| "No se pudo cambiar el cronograma: …" | Falló. El desplegable vuelve a lo guardado. |
| "Fecha movida: el … de aaaa-mm de … va el aaaa-mm-dd." | Se movió un pago a mano. Si ya estaba movido, dice "Fecha corregida". |
| "Elegí una fecha." y "El motivo es obligatorio." | Falta la fecha o el motivo. No se guarda. |
| "No se pudo guardar la fecha: La fecha aaaa-mm-dd está a N días del … de aaaa-mm (aaaa-mm-dd). Un pago no se puede mover más de 31 días: revisá el año y el mes." | La fecha está a más de 31 días de la teórica. |
| "El pago vuelve a su fecha calculada. La fecha anterior queda en el historial: no se borra." | Se volvió un pago a la fecha calculada. Si no estaba movido: "Este pago ya estaba en su fecha calculada." |
| "Se aplicó X % a los 14 meses de la ventana. Los meses anteriores no se tocaron: describen lo que ya pasó." | Se tocó **Aplicar a todos**. |
| "Modalidad guardada." | Se cambió la modalidad de la inflación. Con **Constante** agrega que los meses siguen como estaban hasta tocar **Aplicar a todos**. |
| "No se pudo guardar la inflación de …: El porcentaje de inflación tiene que estar entre -50 y 100. Se recibió X." | El % de un mes está fuera de rango. El campo vuelve al último valor guardado. |
| "El porcentaje de … tiene que ser un número." | La celda de un mes quedó vacía o con texto. |

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No se pudieron leer los egresos de caja de los locales (…). No hay promedio que proyectar, así que la fila Gastos Locales del tablero va en CERO, y ese cero NO significa que los locales no gasten.
significa: No se pudo leer la tesorería de los locales. La grilla queda vacía y el tablero en cero.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas con el texto entre paréntesis.
:::

:::aviso critico
texto: No se pudieron calcular los gastos de los locales: …
significa: La pestaña no pudo cargar. Después de los dos puntos va el motivo.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo calcular (…). Sus filas se muestran en cero.
significa: El tablero no pudo calcular la fila Gastos Locales. Entre paréntesis va el motivo; por ejemplo, "No se encontró ningún día hábil en los 30 días siguientes a aaaa-mm-dd. Revisá RO_T_CALENDARIO."
corrige: Avisá a sistemas con el texto del aviso.
:::

:::aviso critico
texto: Todavía no existe RO_T_CASHFLOW_INFLACION_MES: corré sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto no se puede cargar la inflación, y los valores hora de Logística Local se proyectan sin ajuste trimestral.
significa: La grilla de inflación no está instalada. En esta pestaña, todo lo que no es del mes en curso queda en blanco.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudo leer la inflación mensual (…). Los meses que necesitan ajuste quedan sin proyectar.
significa: Falló la lectura de la inflación. Sólo se proyecta lo del mes en curso.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo leer el calendario bancario (…). Las fechas se calculan asumiendo hábiles los días de lunes a viernes, así que un feriado no corre ninguna fecha.
significa: Sin el calendario, un lunes feriado se toma como día de pago.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: RO_T_CALENDARIO no tiene datos para aaaa-mm. Se asumen hábiles los días de lunes a viernes.
significa: El calendario bancario no llega a ese mes. Un feriado de ese mes no corre ningún pago.
corrige: Avisá a sistemas que hay que extender el calendario bancario.
:::

:::aviso atencion
texto: Falta el parámetro de cuentas excluidas de Gastos Locales: corré sql/cashflow_gastos_locales.sql contra la base central. Mientras tanto se excluye la 522400, que es el valor por defecto.
significa: La lista de cuentas excluidas no está instalada. En Parámetros › Generales el campo se ve deshabilitado, con el chip **por defecto**.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Falta configurar el cronograma de Gastos Locales: corré sql/cashflow_gastos_locales.sql contra la base central. Mientras tanto se paga todos los lunes, que es el valor por defecto.
significa: El día de pago no está instalado. Mientras tanto se paga los lunes y en Parámetros › Calendario el día no se puede cambiar.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

En Parámetros › Generales y Parámetros › Calendario:

:::aviso atencion
texto: No se pudieron leer las cuentas excluidas de Gastos Locales: …
significa: La tarjeta Gastos Locales de Parámetros › Generales no pudo cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudo leer la inflación mensual: …
significa: La grilla de inflación de Parámetros › Generales no pudo cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Falta configurar el cronograma de …: corré … contra la base central. Mientras tanto se paga …, que es el valor por defecto.
significa: El cronograma de ese concepto no está instalado. Se usa su valor por defecto y su día no se puede cambiar.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudieron leer los pagos movidos a mano del cronograma de … (…). Se usan las fechas calculadas.
significa: Los pagos movidos a mano de ese concepto no se están aplicando: cada pago va en su fecha calculada.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Todavía no existe RO_T_CASHFLOW_CRONOGRAMA_PAGO_EDIT: corré sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto las fechas del cronograma se calculan y se ven, pero no se pueden editar.
significa: No se puede mover ningún pago a mano.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Los pagos movidos a mano todavía no distinguen concepto: corré sql/cashflow_cronograma_conceptos.sql contra la base central. Mientras tanto sólo se pueden mover los pagos de Logística Local, que son los que ya había.
significa: Sólo se pueden mover a mano los pagos de Logística Local.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: No se pudo resolver el cronograma de pagos: …
significa: Las tablas del cronograma de Parámetros › Calendario no pudieron cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Dónde cambio el día en que se pagan los gastos de los locales?
En **Parámetros › Calendario › Cronograma de pagos**, tabla **Configuración**, fila **Gastos Locales**, columna **Día de la semana**. Las cuentas excluidas, en cambio, están en **Parámetros › Generales**, tarjeta **Gastos Locales**.
:::

:::pregunta ¿Puedo pagar los gastos de los locales cada quince días, o mover un lunes a mano?
No. Gastos Locales es siempre semanal, porque cada pago es una semana de gasto: quincenal pagaría la mitad. Y sus pagos no se mueven a mano: no aparece en **Próximos pagos**, y sus fechas se ven en **Resto del horizonte**. Lo que sí se puede cambiar es el día de la semana.
:::

:::pregunta ¿Por qué el tramo diario tiene 3 semanas y no 4?
Porque hoy es día de pago y es hábil. El pago de hoy no se proyecta: esa plata ya salió y está en el saldo con el que abre el tablero. Mañana vuelven a ser 4.
:::

:::pregunta ¿Por qué un mes muestra menos que el promedio mensual?
Porque es el primer mes después del tramo diario y cuenta sólo los días que quedan fuera de él: los demás ya se pagaron en las semanas. Los meses enteros cuentan todos sus días y llevan inflación, así que suelen dar más que la tarjeta **Promedio mensual**, que es el gasto diario × 30 sin inflación.
:::

:::pregunta ¿Por qué hay importes en blanco?
Falta la inflación de ese mes o de alguno anterior, desde el mes que viene. Sin ese %, no se puede llevar el gasto a moneda de ese mes, y un cero diría que los locales no gastan. El aviso de arriba dice qué meses cargar en Parámetros › Generales.
:::

:::pregunta ¿Por qué un local dice «sin egresos en 90 días»?
Porque en la ventana no tiene ningún egreso en cuentas 5 que entre en el promedio. También le pasa a un local cuyos egresos fueron todos en cuentas excluidas. Se muestra con $ 0 para que se vea que está en la lista de locales propios.
:::

:::pregunta ¿Lo que se gastó hoy entra en el promedio?
No. La ventana son los 90 días corridos que terminan ayer. Lo de hoy entra mañana.
:::

:::pregunta ¿Una devolución en una cuenta 5 resta?
No. Sólo se suman los egresos. Un ingreso en una cuenta 5, como una reversión, no baja el total.
:::

:::pregunta ¿Los totales de la pestaña y la fila del tablero dicen lo mismo?
Sí. La fila Gastos Locales es la suma de los locales, columna por columna, igual que el pie **TOTALES** sin búsqueda. Con una búsqueda, el pie suma sólo los locales que se ven; las tarjetas siguen sumando todos.
:::

:::pregunta ¿Por qué un pago de Supervisoras movido a un sábado se paga otro día?
Porque Supervisoras se corre al día hábil siguiente, y en ese concepto también se corre la fecha cargada a mano. En Proveedores Locales y Logística Local, en cambio, la fecha a mano se respeta aunque no sea hábil.
:::

:::pregunta ¿Si cambio el día de un concepto, pierdo los pagos movidos a mano?
Sí, los de ese concepto desde el mes en curso: con otro día, cada número de pago es otra fecha. La confirmación dice cuántos son antes de guardar, y quedan en el historial. Los de meses anteriores no se tocan. Agregar un feriado o cambiar el horizonte no los toca.
:::

:::pregunta ¿Por qué la inflación de enero no es la suma de diciembre y enero?
En Gastos Locales, Gastos Supervisoras, Tarjetas Pagos Corporativos y Tarjetas Socios se compone: el 3 % de enero se aplica sobre lo que ya subió en diciembre. Con 2 % y 3 % da 5,06 %, no 5 %. En Logística Local, Alquileres y Seguros se suma, porque reproduce un ajuste pactado. Ver [la inflación](ayuda:gastos_locales#la-inflacion).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Egreso de caja | Lo que un local paga con el efectivo de su caja, registrado en la tesorería del local en Tango |
| Cuentas 5 | Las cuentas de tesorería cuyo código empieza con 5: las de gastos |
| Cuentas excluidas | Las cuentas 5 que no entran en el promedio. Se cargan en Parámetros › Generales |
| Ventana de 90 días | Los 90 días corridos que terminan ayer |
| Gasto diario | El total de los 90 días ÷ 90 |
| Promedio mensual | El gasto diario × 30. Sólo lo usa la tarjeta |
| Local propio habilitado | Un local del canal Propios que está abierto. No incluye franquicias ni locales cerrados |
| Tramo diario | Las columnas de días del eje, desde hoy. Cuántas, lo dice **Columnas diarias** |
| Semana de gasto | El gasto diario × 7, que se paga el día del cronograma |
| Cronograma de pagos | Qué día de la semana y con qué frecuencia se paga cada concepto. Está en Parámetros › Calendario |
| Quincenal | El 2do y el 4to de un día de la semana en el mes |
| Semanal | Todos los de un día de la semana en el mes |
| Fecha teórica | El día que dice la configuración del cronograma, antes de correrlo |
| Fecha calculada | La teórica corrida al hábil anterior o siguiente, si no era hábil |
| Corrida | Un pago cuyo día no era hábil y pasó a otro |
| Pago movido a mano | Un pago con una fecha cargada a mano y un motivo. Lleva el chip **a mano** |
| Día hábil | Un día que el banco opera: de lunes a viernes, menos los feriados bancarios |
| Inflación compuesta | Cada mes sube sobre el anterior ya aumentado: (1 + % de un mes) × (1 + % del siguiente) |
| Mes base | El mes en cuya moneda está el importe de partida. Vale 1. En Gastos Locales, el mes en curso |
