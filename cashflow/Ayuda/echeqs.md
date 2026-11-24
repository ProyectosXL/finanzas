---
titulo: Echeqs
pestana: echeqs
categoria: Ingresos
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Administración
bajada: Cuándo y por cuánto entra la plata de los cheques de terceros —los que están en cartera y los depositados que todavía no acreditaron—, y qué cheques de venta ya cobrada se restan de la cobranza de Ventas.
---

## Para qué sirve

La pestaña tiene dos sub-pestañas, que contestan dos preguntas distintas sobre los mismos cheques.

**Cheques en Cartera** dice cuándo entra la plata de los cheques de terceros que tiene la empresa. Muestra los que siguen en cartera en Tango, cada uno en el día en que se puede depositar, y los que Tesorería ya depositó y el banco todavía no acreditó, cada uno en el día en que acredita y por lo que queda después de la retención del banco. Lo que se ve acá entra al tablero en las filas **Echeqs en cartera** y **Echeqs depositados**.

**Venta Cobrada Anticipada** es para los clientes que entregan los cheques antes de que se les facture. Esa venta todavía no ocurrió, pero ya está cobrada: si la pestaña Ventas la proyectara como cobranza, se contaría dos veces. Lo que se tilda acá se resta de la cobranza proyectada de Ventas, en la fila **Neteo cheques adelantados** del tablero.

Un mismo cheque puede estar en las dos sub-pestañas, y así es como corresponde: suma una vez como disponibilidad y se resta una vez de la cobranza estimada, así que queda contado una sola vez.

Cómo se lee el tablero está en [el tablero](ayuda:general_tablero).

## De dónde salen los datos

:::diagrama
fuente: Tango | los cheques de terceros: en cartera, depositados con boleta de depósito, y los de los clientes de venta cobrada anticipada
fuente: El extracto bancario de Interbanking | los créditos con el número de cheque, que confirman que un depositado ya acreditó
fuente: Parámetros › Cheques | el plazo de acreditación, el plazo de presentación, las alícuotas de retención y los clientes de venta cobrada anticipada
fuente: Parámetros › Calendario | el horizonte y los feriados bancarios
fuente: Lo que se marca en esta pestaña | exclusiones, fechas de acreditación, marcas «ya acreditó» y tildes de venta cobrada anticipada
pestana: Echeqs | ubica cada cheque en el día en que entra la plata
tablero: Filas Echeqs en cartera, Echeqs depositados y Neteo cheques adelantados | la cartera por el importe, los depositados por el neto, y lo tildado en negativo en Ventas
alt: Tango, el extracto de Interbanking, Parámetros › Cheques, Parámetros › Calendario y lo marcado en la pestaña alimentan Echeqs, que pasa la cartera y los depositados a las filas Echeqs en cartera y Echeqs depositados, y lo tildado de venta cobrada anticipada a la fila Neteo cheques adelantados.
:::

- **Tango**: los cheques de terceros. Para **Cheques en Cartera**, todos los que están en cartera, de cualquier cliente (también mayoristas y cheques sin cliente), y los que Tesorería registró como depositados con una boleta de depósito a una cuenta de banco. Para **Venta Cobrada Anticipada**, los cheques de los clientes cargados en Parámetros, con fecha de hoy en adelante, que no están anulados ni rechazados. El cashflow sólo lee Tango: no escribe nada en él.
- **El extracto bancario de Interbanking**: los créditos de los últimos días. Si uno tiene el número de un cheque depositado y su mismo importe, el cheque se da por acreditado.
- **Parámetros › Cheques**: los dos plazos, las alícuotas de retención del depósito y el listado de clientes de venta cobrada anticipada, con sus días de pre-chequeado.
- **Parámetros › Calendario**: el horizonte (cuántos días y meses se proyectan) y los feriados bancarios, que deciden qué días son hábiles.
- **Lo que se marca en esta pestaña**: las exclusiones, las fechas de acreditación de los cheques en plazo de presentación, las marcas «ya acreditó» y los tildes de venta cobrada anticipada. Son del cashflow: Tango no las ve.

## Cómo se calcula

Las dos sub-pestañas corren los cheques al día hábil de la misma forma: si la fecha cae sábado, domingo o feriado bancario, el importe va al primer día hábil siguiente. Más allá del fin del horizonte no se corre nada.

### Cheques en Cartera

En la misma grilla conviven dos clases de cheques, que van a dos filas distintas del tablero:

- **Los que están en cartera** en Tango: suman en **Echeqs en cartera**, por el importe del cheque.
- **Los depositados** en Tango que el banco todavía no acreditó: suman en **Echeqs depositados**, por el neto (el importe menos la retención).

Un cheque está en uno o en otro, nunca en los dos: cuando Tesorería registra el depósito en Tango, el cheque sale de cartera.

### Dónde cae un cheque en cartera

Cada cheque en cartera tiene un **día hábil**: su fecha, o el hábil siguiente si cae un fin de semana o un feriado. Ese día es el que importa:

- **Si su día hábil es hoy o después**, el cheque está vigente y suma ese día, por el importe. Si se corrió, lleva la marca **corrida**.
- **Si su día hábil ya pasó** y el cheque sigue en cartera, está **en plazo de presentación** o **vencido**, según cuántos días pasaron desde la fecha escrita.

La cartera suma el importe completo el mismo día hábil del cheque. No le suma días de acreditación ni le descuenta retención: eso pasa recién cuando el cheque se deposita en Tango.

### En plazo de presentación

La Ley de Cheques da un plazo para presentar un cheque al cobro: 30 días corridos desde su fecha, que se cambian en Parámetros › Cheques. Un cheque está **en plazo de presentación** cuando se cumplen las tres cosas:

1. **Tango lo tiene en cartera** en el momento en que se abre la pestaña.
2. **Su día hábil ya pasó**: ya no cae hoy ni en adelante.
3. **No pasaron más días que el plazo** desde la fecha escrita en el cheque. El último día del plazo todavía está en plazo.

Ese cheque no está vencido: se puede depositar. Pero el cashflow no sabe cuándo va a entrar esa plata, así que **no lo suma hasta que alguien le asigne una fecha de acreditación**. Esa fecha sólo vive en el cashflow.

- **Sin fecha asignada**: no suma en ninguna columna, y un aviso dice cuántos son y cuánto valen.
- **Con fecha asignada de hoy en adelante**: suma ese día, por el importe, en **Echeqs en cartera**.
- **Con fecha asignada ya pasada**: la fecha llegó y el cheque sigue en cartera, así que no se cumplió. No suma, y un aviso pide reasignarla.

Pasado el plazo, el cheque está **vencido**: ya no se puede presentar al cobro y no suma. Una fecha asignada deja de aplicar. Hay que resolverlo en Tango (rechazo o baja) o excluirlo.

**La pestaña sólo mira lo que dice Tango.** Un cheque que ya se depositó en el banco, o que se usó para pagar, pero que Tango todavía tiene en cartera, sigue apareciendo en plazo de presentación. Se corrige en Tango, registrando el depósito con su boleta (o el pago): en cuanto Tango lo saca de cartera, sale solo de esta lista. Si se registró y sigue apareciendo, tocá **Actualizar**: la pantalla no se refresca sola.

Sin el plazo de presentación configurado, los cheques de día hábil pasado no se leen: no se puede saber si están en plazo o vencidos, y la pantalla lo avisa.

:::ejemplo ilustrativo
El plazo de presentación es de 30 días. Hoy es lunes 10 de marzo.

- Un cheque fechado el **sábado 15 de marzo**: su día hábil es el lunes 17. Está vigente y suma el 17, con la marca **corrida**.
- Un cheque fechado el **sábado 8 de marzo**: su día hábil es hoy, lunes 10. Está vigente y suma hoy.
- Un cheque fechado el **1 de marzo**, que sigue en cartera: su día hábil ya pasó, y desde el 1 de marzo pasaron 9 días. Está **en plazo de presentación** hasta el 31 de marzo. No suma hasta que se le asigne una fecha; si se le asigna el miércoles 12, suma el 12.
- Un cheque fechado el **5 de febrero**, que sigue en cartera: pasaron 33 días. Está **vencido** y no suma.
:::

### Los depositados que todavía no acreditaron

Cuando Tesorería registra en Tango la boleta de depósito de un cheque a una cuenta de banco, el cheque sale de cartera. Pero el banco no lo acredita en el momento: lo acredita a los días hábiles que dice **Plazo de acreditación** (2 por defecto). Mientras tanto la plata no está en la cartera ni en el saldo bancario, y por eso la grilla los sigue mostrando, con la marca **depositado**.

Son el arrastre de los depósitos de días anteriores: cheques con la boleta de un día anterior (o de hoy) que acreditan de mañana en adelante.

:::formula
Acredita = la fecha de la boleta de depósito + el plazo de acreditación, en días hábiles
Retención = importe del cheque × tasa (la suma de las alícuotas vigentes al día en que acredita)
Neto = importe del cheque − retención
:::

- **La fecha del cheque no interviene.** Con el plazo en 2, un depósito del lunes acredita el miércoles, y uno del viernes, del sábado o del domingo, el martes. Un feriado en el medio suma un día. Un cheque con fecha de dentro de veinte días depositado hoy acredita igual a los dos días hábiles.
- **La tasa no depende del banco**: hay un solo juego de alícuotas para todos los cheques, en Parámetros › Cheques.
- Nada de esto se guarda: se calcula cada vez que se abre la pestaña. Por eso cambiar el plazo o una alícuota mueve también los depositados que todavía no acreditaron.

Cada depositado tiene uno de estos estados, en este orden:

| Estado | Cuándo | Suma |
| --- | --- | --- |
| No se ve | Su acreditación es anterior a hoy: ya está en el saldo bancario | No |
| **acreditado (Interbanking)** | El extracto de Interbanking ya trae un crédito con su número y su importe, entre la boleta y hoy | No. Se ve hasta su fecha de acreditación |
| **acreditado (por plazo)** | Su acreditación es hoy | No. Se ve sólo hoy; mañana deja de verse |
| **acreditado** | Alguien lo marcó **Ya acreditó** | No. Se ve hasta su fecha de acreditación |
| **depositado, sin proyectar** | Le falta la fecha de acreditación o el neto (sin plazo, sin día hábil o sin alícuotas) | No. Un aviso crítico dice por qué |
| **depositado** | Todo lo demás | Sí, en **Echeqs depositados**, el día que acredita, por el neto |

:::ejemplo ilustrativo
Tesorería deposita un cheque de **$ 1.000.000** el lunes. El plazo de acreditación es de 2 días hábiles y las alícuotas vigentes suman **3 %**.

- Acredita: el miércoles.
- Retención: 3 % × $ 1.000.000 = **$ 30.000**.
- Neto: $ 1.000.000 − $ 30.000 = **$ 970.000**.

El lunes y el martes el cheque se ve con la marca **depositado** y suma $ 970.000 el miércoles en **Echeqs depositados**. El miércoles se ve como **acreditado (por plazo)** y ya no suma: está en el saldo bancario. El jueves deja de verse.
:::

### Excluidos

Un cheque en cartera que se sabe que no se va a cobrar (el cliente avisó que no lo cubre, está judicializado, en gestión de cambio) se puede **excluir del cashflow**, con un motivo. Deja de sumar en **Echeqs en cartera** y se informa aparte. El cheque sigue en cartera en Tango: lo único que cambia es que el cashflow deja de contar esa plata.

La exclusión es sólo de cartera. Si después ese cheque se deposita en Tango, entra en **Echeqs depositados** como cualquier otro.

### Venta Cobrada Anticipada

Los clientes que operan con esta modalidad se cargan en Parámetros › Cheques, cada uno con sus **días de pre-chequeado**: cuántos días antes de la fecha del cheque se le factura. Sólo entran códigos de franquicias y locales.

:::formula
Fecha venta estimada = fecha del cheque − días de pre-chequeado del cliente
:::

Cada cheque usa sus dos fechas para dos cosas distintas:

- **La fecha venta estimada decide si el cheque aparece.** Si es de hoy en adelante, la venta todavía no se facturó y hay algo que restar de la cobranza. Si ya pasó, esa venta se facturó y se cobró: el cheque no se muestra y no se resta nada. Un cliente con 0 días usa la fecha del cheque.
- **La fecha del cheque decide dónde cae el importe**: su día hábil, corrido como en la cartera. Es cuando entra la plata.

**Los cheques entran tildados**: estar en el listado de clientes es haber optado por la modalidad. Lo que se hace en la pantalla es destildar las excepciones. Todo lo tildado se resta de la cobranza proyectada de Ventas, en la fila **Neteo cheques adelantados**, en el día hábil de cada cheque.

La resta va por el tilde, no por el estado del cheque en Tango. Un cheque tildado que ya salió de cartera (depositado o endosado a un proveedor) también se resta. Lo que cae después del fin del horizonte no se resta, y lo avisa.

:::ejemplo ilustrativo
Un cliente tiene 10 días de pre-chequeado. Hoy es 10 de marzo.

- Un cheque de **$ 500.000** con fecha 25 de marzo: su venta estimada es el 15 de marzo, de hoy en adelante. Aparece tildado y resta $ 500.000 de la cobranza de Ventas el 25 de marzo.
- Un cheque de **$ 300.000** con fecha 15 de marzo: su venta estimada es el 5 de marzo, que ya pasó. No aparece: esa venta ya se cobró.

Si el primero está en cartera, también suma $ 500.000 en **Echeqs en cartera** el 25 de marzo. En el tablero queda $ 500.000 − $ 500.000 = 0 de más: contado una sola vez.
:::

## La pantalla

Arriba de todo, las dos sub-pestañas: **Cheques en Cartera**, que es la que abre, y **Venta Cobrada Anticipada**, que se carga recién la primera vez que se abre.

Lo que tienen en común:

- **El panel de avisos**, plegable, arriba de cada sub-pestaña. Trae los avisos de esa sub-pestaña y los que el tablero dice de Echeqs, que van a las dos. Ver [los avisos](ayuda:general_avisos).
- **Las tres vistas**: los botones **Días**, **Meses** y **Período completo** cambian las columnas del período. Ver [las tres vistas](ayuda:general_tablero#las-tres-vistas). Los sábados, domingos y feriados tienen la columna marcada, y un mes recortado lo dice al pasar el mouse por su encabezado.
- **Columnas fijas**, **Actualizar** y **Exportar**, que baja a Excel lo que se está viendo.
- **El total de cada columna** va arriba de su fecha y también al pie.
- **Sin permiso de edición** de la pestaña, no hay casillas: se ven las marcas, pero no se puede excluir, asignar fechas, marcar «ya acreditó» ni tildar. Ver [permisos y parámetros](ayuda:general_permisos_parametros#ver-y-editar-dos-permisos-por-pestana).

### Cheques en Cartera

De arriba hacia abajo:

- El panel de avisos.
- Las cinco tarjetas.
- El cartel de los excluidos, si hay.
- El título **Cheques en cartera**, con la línea que cuenta cuántos cheques suman y cuántos van aparte.
- El buscador, que busca en cliente, código, banco y número de cheque, y los interruptores **Ver excluidos**, **Ocultar depositados** y **Ver valores en plazo de presentación sin fecha**.
- Los botones de vista, **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea del período que se mide.
- La barra de selección, sólo cuando hay cheques seleccionados.
- La grilla y el pie **TOTALES**.

### Las tarjetas de Cheques en Cartera

| Tarjeta | Qué suma |
| --- | --- |
| **Vista Días** | La cartera que suma, en el tramo diario. Debajo, el período. |
| **Vista Meses** | La cartera que suma, en las columnas mensuales. |
| **Período Completo** | La cartera que suma, en todo el horizonte. |
| **Depositados (neto)** | Los depositados que se proyectan, por el neto. Debajo: cuántos, el bruto y la retención, o «Ninguno se proyecta»; y cuántos ya acreditaron o están sin proyectar. |
| **En plazo de presentación** | El importe de todos los cheques en plazo de presentación. Debajo: cuántos tienen fecha y suman, cuántos no tienen y no suman, y los vencidos. |

Las tres primeras son la fila **Echeqs en cartera**: no incluyen los excluidos ni los depositados, y sí los en plazo de presentación con fecha asignada (el pie lo dice: «incluye N en plazo de presentación con fecha»). La cuarta es la fila **Echeqs depositados**.

Si no hay cheques en plazo de presentación, la quinta dice «Ninguno»; si no se pueden leer, «No se leen: falta correr el script» o «No se leen: revisá los avisos».

Las tarjetas no cambian con el buscador ni con los interruptores.

### El cartel de los excluidos

Si hay cheques excluidos, arriba de la tabla un cartel dice cuántos son, por cuánto, y hasta tres motivos: «esa plata no entra al cashflow y ya está descontada de las tarjetas de arriba». Se ve siempre, aunque los excluidos estén escondidos.

### Los interruptores

| Interruptor | Arranca | Qué hace |
| --- | --- | --- |
| **Ver excluidos** | Apagado | Muestra los excluidos, atenuados y con el importe tachado. |
| **Ocultar depositados** | Apagado: los depositados se ven | Esconde todos los depositados (los que suman, los acreditados y los sin proyectar), para mirar sólo la cartera. |
| **Ver valores en plazo de presentación sin fecha** | Apagado | Muestra los cheques en plazo de presentación que no tienen fecha. Debajo dice cuántos esconde y por cuánto. |

Los en plazo de presentación con fecha, los de fecha pasada y los vencidos se ven siempre.

Lo escondido gana sobre el buscador: si buscás un cheque en plazo sin fecha con el interruptor apagado, debajo dice que coincide y que prendas el interruptor.

### Las columnas de Cheques en Cartera

| Columna | Qué muestra |
| --- | --- |
| **Fecha de pago** | La fecha escrita en el cheque, sin correr. La tabla abre ordenada por ella. |
| **N° Cheque** | El número del cheque. |
| **Banco** | El banco emisor. |
| **Cliente** | Quien emitió el cheque, con su código debajo. |
| **Importe** | El importe del cheque, el bruto. |
| **Acredita** | El día en que cae el importe, en la grilla y en el tablero (ver abajo). |
| **Retención** | Sólo en los depositados: lo que retiene el banco. |
| **Neto** | Sólo en los depositados: el importe menos la retención. Es lo que suma en **Echeqs depositados**. |
| La casilla | La selección, y debajo las marcas del cheque. |

Después van las columnas del período y **Total**.

**Fecha de pago** y **Acredita** pueden decir cosas distintas, y es a propósito:

- **Un cheque en cartera vigente**: Acredita es su día hábil. Si la fecha cae un fin de semana o un feriado, Acredita dice el hábil siguiente y la marca **corrida** explica el cambio. El importe está en esa columna.
- **Un cheque en plazo de presentación**: Acredita es la fecha asignada. Con permiso, es un selector para elegirla; vacío si no tiene fecha o si la que tenía ya pasó (la pasada está en el tooltip de la marca). Sin permiso, la fecha o un guión.
- **Un vencido**: Acredita va vacía.
- **Un depositado**: Acredita es la fecha de la boleta más el plazo de acreditación. La fecha del cheque no interviene, y no lleva marca **corrida**.

### Las marcas de los cheques

Debajo de la casilla, cada cheque puede llevar marcas. Al pasar el mouse, cada una explica el detalle.

| Marca | Qué quiere decir |
| --- | --- |
| **corrida** | Sólo en un vigente: la fecha cae un fin de semana o un feriado y el importe está en el hábil siguiente. El tooltip dice de qué día a cuál y por qué. |
| **en plazo · sin fecha** | En plazo de presentación, sin fecha de acreditación: no suma. |
| **en plazo · fecha asignada** | En plazo de presentación, con fecha: suma ese día. El tooltip dice quién la asignó, cuándo y con qué observación. |
| **fecha asignada pasada** | La fecha asignada llegó y el cheque sigue en cartera: no suma. Hay que reasignarla. |
| **vencido** | Superó el plazo de presentación: no suma. |
| **excluido** | Excluido del cashflow. El tooltip dice el motivo, quién y cuándo. |
| **depositado** | Depositado en Tango: suma en **Echeqs depositados** el día que acredita, por el neto. |
| **acreditado (Interbanking)** | El extracto de Interbanking ya lo trae: no suma. |
| **acreditado (por plazo)** | Hoy es su día de acreditación: no suma, y mañana deja de verse. |
| **acreditado** | Marcado **Ya acreditó**: no suma. |
| **depositado, sin proyectar** | Le falta la fecha de acreditación o el neto: no suma. |

El tooltip de los cheques en plazo y vencidos dice la fecha del cheque, cuántos días pasaron y hasta qué día va el plazo, más las fechas asignadas antes y quién las dio de baja.

El tooltip de los depositados dice la boleta de depósito y su fecha, la cuenta, el banco emisor, la fecha del cheque, cuándo acredita (y a cuántos días hábiles de la boleta), el bruto, la tasa con su desglose y el neto. Si el extracto lo dio por acreditado, el crédito; si alguien lo marcó, quién, cuándo y con qué observación, y las marcas deshechas.

Las filas también se distinguen por color: el excluido va atenuado y tachado, el depositado que suma va resaltado, y el acreditado y el sin proyectar van atenuados.

### La barra de selección

Aparece cuando hay cheques seleccionados, sólo con permiso de edición. Dice cuántos son, cuánto suman, de qué banco o de cuántos bancos, y cuántos son depositados, en plazo de presentación, vencidos o ya excluidos. La casilla del encabezado selecciona todo lo que se está viendo.

| Botón | Se puede apretar si |
| --- | --- |
| **Ya acreditó** | Todos los seleccionados son depositados que suman. |
| **Deshacer ya acreditó** | Todos están marcados «ya acreditó». |
| **Asignar fecha** | Todos están en plazo de presentación (con o sin fecha, o con la fecha pasada). |
| **Deshacer fecha** | Todos tienen una fecha asignada, también si ya pasó. |
| **Excluir del cashflow** | Hay alguno sin excluir y ninguno está depositado. Los en plazo y los vencidos se pueden excluir. |
| **Volver a incluir** | Hay alguno excluido. |
| **Limpiar selección** | Siempre. |

Un botón apagado dice por qué al pasar el mouse.

### El pie de Cheques en Cartera

**TOTALES** suma las filas que se están viendo. Si hay más de una clase de cheque a la vista, debajo se abre en una línea por cada una, para compararla con su fila del tablero:

- **En cartera · fila "Echeqs en cartera"**, y debajo, en cursiva, **↳ de esos, en plazo de presentación con fecha · ya sumados arriba**.
- **Vencidos · no suman**.
- **En plazo de presentación sin fecha · no suman** (también los de fecha pasada).
- **Depositados · fila "Echeqs depositados"**.
- **Acreditados · no suman**.
- **Depositados sin proyectar · no suman**.
- **Excluidos · no suman**.

En una línea con depositados, el total del período no es igual al **Importe**: el importe es bruto y el período suma el neto. La diferencia es la **Retención**.

El buscador y los interruptores cambian las filas, el pie y los totales sobre cada fecha. No cambian las tarjetas.

### Venta Cobrada Anticipada

De arriba hacia abajo:

- El panel de avisos.
- La leyenda azul: lo tildado se resta de la cobranza proyectada de Ventas, y un cheque en cartera aparece acá y en la otra sub-pestaña, contado una sola vez.
- Las cuatro tarjetas.
- El título **Cheques de clientes pre-chequeados**, con tres leyendas: entran tildados, se ven sólo los de venta teórica desde hoy, y cada importe se ubica por la fecha del cheque.
- El desplegable **Todos los clientes**, con un ítem por cliente que tiene cheques en el listado y cuántos tiene.
- El buscador, que busca en cliente, código, banco y número de cheque.
- El mensaje de guardado, que se va solo, y los botones de vista, **Columnas fijas**, **Actualizar** y **Exportar** (los tildes bajan como Sí o No).
- La línea del período y el panel de avisos de la grilla: lo que cae fuera del horizonte y los problemas de calendario.
- La grilla y el pie.

### Las tarjetas de Venta Cobrada Anticipada

| Tarjeta | Qué suma |
| --- | --- |
| **A netear de Ventas** | Todo lo tildado. Debajo, cuántos de cuántos están tildados. |
| **En el listado** | Todos los cheques del listado, tildados o no. |
| **Marcados en cartera** | Los tildados que Tango tiene en cartera («Estado C · cierran solos»). |
| **Marcados fuera de cartera** | Los tildados que ya salieron de cartera: aplicados o depositados. |

Las tarjetas son de todo el listado: no cambian con el desplegable de clientes ni con el buscador.

### Las columnas de Venta Cobrada Anticipada

| Columna | Qué muestra |
| --- | --- |
| La casilla | El tilde. La del encabezado tilda o destilda todo lo que se está viendo. Sin permiso, un ✓ o un guión. |
| **Fecha del cheque** | La que ubica el importe, con la marca **corrida** si cae un fin de semana o un feriado. |
| **Fecha venta estimada** | En gris: la fecha del cheque menos los días del cliente. Explica por qué el cheque está en la lista, no dónde cae. |
| **Días** | Los días de pre-chequeado del cliente («−10 d»). Un **0** quiere decir que el cliente no tiene días cargados. |
| **N° Cheque**, **Banco**, **Cliente** | Del cheque, con el código del cliente debajo. |
| **Estado** | **C**: en cartera. **A**: aplicado, ya salió de cartera, depositado en el banco o endosado a un proveedor. |
| **Importe** | El importe del cheque. |
| **Marca** | De dónde sale el tilde: **del cliente** (nadie lo tocó), **tildado a mano** o **destildado a mano**, con quién y cuándo al pasar el mouse. |

Después van las columnas del período y **Total**, que suman sólo lo tildado.

El pie tiene **MARCADO (visible)**, lo tildado de lo que se está viendo, con sus columnas; **Total del listado visible**, todo lo que se ve; y debajo, lo tildado abierto por estado.

## Tareas frecuentes

### Cheques en Cartera

Todas estas tareas piden el permiso de edición de la pestaña. Ver [si no tenés permiso para editar](ayuda:general_permisos_parametros#si-no-tenes-permiso-para-editar).

### Asignarle fecha a un cheque en plazo de presentación

:::pasos
1. Prendé **Ver valores en plazo de presentación sin fecha** para ver los que no tienen fecha.
2. Buscá el cheque. Lleva la marca **en plazo · sin fecha** o **fecha asignada pasada**.
3. En la columna **Acredita**, elegí la fecha en el selector. Tiene que ser un día hábil, de mañana en adelante, y no posterior al último día de su plazo ni al fin del horizonte.
Resultado: se guarda al elegirla. El aviso dice «N cheque(s) en plazo de presentación acreditan el dd/mm/aaaa.», el cheque pasa a **en plazo · fecha asignada** y suma ese día en **Echeqs en cartera**.
:::

Vaciar el selector no deshace la fecha: eso se hace con **Deshacer fecha**.

### Asignar la misma fecha a varios cheques

:::pasos
1. Seleccioná los cheques. Todos tienen que estar en plazo de presentación.
2. Tocá **Asignar fecha** en la barra.
3. Elegí la **Fecha de acreditación** y, si querés, escribí una **Observación**.
4. Tocá **Asignar a N cheque(s)**.
Resultado: todos suman ese día en **Echeqs en cartera**. Si alguno ya tenía fecha, se reemplaza y la anterior queda en el historial.
:::

Es todo o nada: si la fecha no sirve para uno de los cheques (por ejemplo, porque su plazo vence antes), no se asigna a ninguno y el mensaje dice cuál y por qué.

### Deshacer una fecha asignada

:::pasos
1. Seleccioná los cheques con fecha.
2. Tocá **Deshacer fecha** y confirmá.
Resultado: «N fecha(s) deshechas: esos cheques dejan de sumar.» Vuelven a **en plazo · sin fecha**. La fecha queda en el historial, dada de baja.
:::

### Marcar «Ya acreditó» un depositado

Sirve cuando el banco acreditó un depositado antes de su plazo y la plata ya está en el saldo bancario.

:::pasos
1. Seleccioná los cheques con la marca **depositado**.
2. Tocá **Ya acreditó**.
3. Si querés, escribí una **Observación** (por ejemplo, en qué extracto lo viste).
4. Tocá **Marcar N cheque(s)**.
Resultado: «N cheque(s) marcados como acreditados.» Salen de **Echeqs depositados**, y se siguen viendo con la marca **acreditado** hasta su fecha de acreditación.
:::

Para deshacerla, seleccionalos y tocá **Deshacer ya acreditó**: vuelven a **Echeqs depositados** si acreditan de mañana en adelante. La marca queda en el historial.

### Excluir cheques que no se van a cobrar

:::pasos
1. Buscá los cheques. Para todos los de un cliente, escribí el cliente en el buscador y tildá la casilla del encabezado.
2. Tocá **Excluir del cashflow**.
3. Escribí el **Motivo (el mismo para todos)**. Es obligatorio.
4. Tocá **Excluir N cheque(s)**.
Resultado: «N cheque(s) excluidos. El tablero ya no cuenta esa plata.» Salen de las tarjetas y de **Echeqs en cartera**, y el cartel de excluidos los cuenta.
:::

Para corregir el motivo, volvé a excluirlos con el motivo nuevo: los dos quedan en el historial.

### Volver a incluir un cheque excluido

:::pasos
1. Prendé **Ver excluidos**.
2. Seleccioná los cheques.
3. Tocá **Volver a incluir** y confirmá.
Resultado: «N cheque(s) incluidos de nuevo en el cashflow.» Sus importes vuelven a la fila del tablero. La exclusión queda en el historial, dada de baja.
:::

### Corregir un cheque depositado que sigue en plazo de presentación

:::pasos
1. Anotá el **N° Cheque**.
2. En Tango, registrá su depósito con la boleta de depósito a la cuenta del banco (o el pago, si se usó para pagar).
3. Volvé a la pestaña y tocá **Actualizar**.
Resultado: el cheque sale de la cartera. Si se depositó, aparece con la marca **depositado** y suma en **Echeqs depositados** el día que acredita.
:::

### Venta Cobrada Anticipada

Tildar y destildar pide el permiso de edición de la pestaña. Cargar clientes pide el de Parámetros › Cheques.

### Destildar un cheque que no corresponde a una venta ya cobrada

:::pasos
1. Elegí el cliente en **Todos los clientes** o buscá el cheque.
2. Sacale el tilde de la casilla.
Resultado: se guarda al instante. Al lado de los botones dice «1 cheque(s) destildados. Ventas ya usa este neteo.», la **Marca** pasa a **destildado a mano** y el cheque deja de restarse de la cobranza de Ventas.
:::

Si no se pudo guardar, una alerta lo dice y la casilla vuelve a como estaba.

### Tildar o destildar todos los cheques de un cliente

:::pasos
1. Elegí el cliente en **Todos los clientes**.
2. Tocá la casilla del encabezado.
3. Confirmá la pregunta del navegador, que dice cuántos cheques y por cuánto.
Resultado: todos los que se ven quedan tildados (o destildados), en una sola operación.
:::

### Agregar un cliente de venta cobrada anticipada

:::pasos
1. Andá a **Parámetros › Cheques**, sección **Venta cobrada anticipada**.
2. Tocá **Agregar cliente**.
3. Escribí el **Código de cliente** tal como está en Tango (distingue mayúsculas) y tocá **Buscar**: la **Razón social** la trae Tango.
4. Cargá los **Días de pre-chequeado** (0 si el cliente no adelanta).
5. Tocá **Agregar**.
Resultado: el aviso dice cuántos cheques vivos trae, que ya aparecen tildados en Echeqs › Venta Cobrada Anticipada. Si no trae ninguno, revisá el código.
:::

## Parámetros

Los de los cheques se cambian en **Parámetros › Cheques**, que tiene su propio permiso de edición. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia |
| --- | --- | --- | --- |
| **Plazo de acreditación** | Parámetros › Cheques › Depósito de cheques | 2 días hábiles | La fecha de acreditación de los depositados: la boleta más estos días hábiles. Entero de 1 a 30. Mueve también los depositados que todavía no acreditaron. |
| **Plazo de presentación de los cheques** | Parámetros › Cheques › Depósito de cheques | 30 días corridos | Hasta cuántos días desde su fecha un cheque en cartera está en plazo de presentación; después, vencido. Entero de 1 a 30. |
| **Alícuotas de retención de los cheques** | Parámetros › Cheques › Depósito de cheques | Ninguna: se cargan en la sección, una por concepto (hoy, IIBB y SICREB) | La retención y el neto de los depositados. La tasa es la suma de las alícuotas vigentes al día en que acredita cada cheque. Sin ninguna vigente, los depositados no se proyectan. |
| **Clientes de venta cobrada anticipada** (código, días de pre-chequeado, activo) | Parámetros › Cheques › Venta cobrada anticipada | Sin clientes. Días: 0 | Qué cheques aparecen en Venta Cobrada Anticipada y desde cuándo. Sin clientes, la sub-pestaña está vacía y no se resta nada de Ventas. |
| **Horizonte de proyección** | Parámetros › Calendario | El del tablero | Las columnas del período, y hasta dónde se corren las fechas. |
| **Feriados bancarios** | Parámetros › Calendario | Los del calendario bancario | Qué días son hábiles: la corrida de los cheques, el conteo del plazo de acreditación y qué fechas se pueden asignar. |

Arriba de la sección **Depósito de cheques** se ve la **Tasa de retención vigente hoy**. Las alícuotas no se editan: se carga una vigencia nueva con **Cargar alícuota** (concepto, alícuota y **Vigencia desde**) y la anterior queda en el histórico. Dar de baja una alícuota hace que deje de regir.

Un cliente no se borra: se da de baja con el interruptor **Activo**. Sus cheques dejan de aparecer y de restarse, y sus tildes a mano quedan por si vuelve. Los **Días de pre-chequeado** se cambian en la misma tabla y se guardan al salir del campo.

## Avisos y qué hacer

Los avisos se ven arriba de cada sub-pestaña, con los que el tablero dice de Echeqs. Los de la cartera también llegan al tablero. Ver [cómo se leen los avisos](ayuda:general_avisos).

### En Cheques en Cartera

:::aviso atencion
texto: N cheque(s) en plazo de presentación sin fecha de acreditación por $ X no se proyectan. Asignales una fecha en Ingresos › Echeqs.
significa: Hay cheques en cartera cuyo día hábil pasó, todavía en plazo de presentación, sin fecha. Esa plata no está en ninguna fila.
corrige: Prendé **Ver valores en plazo de presentación sin fecha** y asignales una fecha en **Acredita**. Si ya se depositaron, registrá el depósito en Tango. Si no se van a cobrar, excluilos.
:::

:::aviso atencion
texto: N cheque(s) en plazo de presentación por $ X tenían fecha de acreditación ya pasada y siguen en cartera: reasignales la fecha en Ingresos › Echeqs.
significa: La fecha asignada llegó y Tango sigue teniendo el cheque en cartera. No suma.
corrige: Asignales una fecha nueva. Si ya se depositaron, registrá el depósito en Tango.
:::

:::aviso atencion
texto: N cheque(s) vencidos por $ X superaron el plazo de presentación y siguen en cartera en Tango: ya no se pueden presentar al cobro y no se proyectan. Resolvelos en Tango —rechazo o baja— o excluilos en Ingresos › Echeqs.
significa: Cheques que pasaron el plazo de presentación. No suman.
corrige: En Tango, con el rechazo o la baja. Mientras tanto, podés excluirlos con su motivo.
:::

:::aviso critico
texto: N cheque(s) depositado(s) en Tango por $ X brutos no se proyectan en "Echeqs depositados": Los cheques no tienen ninguna alícuota vigente al dd/mm/aaaa, así que no se puede calcular el importe neto del depósito. Cargá sus alícuotas en Parámetros → Cheques con una vigencia igual o anterior a esa fecha.
significa: Depositados sin neto, porque no hay ninguna alícuota vigente a su acreditación. Esa plata no está en ninguna fila.
corrige: Parámetros › Cheques: cargá las alícuotas con una vigencia que llegue a esa fecha.
:::

:::aviso critico
texto: N cheque(s) depositado(s) en Tango por $ X brutos no se proyectan en "Echeqs depositados": Las alícuotas vigentes de los cheques al dd/mm/aaaa suman X%, así que el neto saldría cero o negativo. Corregilas en Parámetros → Cheques.
significa: Las alícuotas cargadas suman 100 % o más.
corrige: Parámetros › Cheques: dá de baja la alícuota equivocada y cargá la correcta.
:::

:::aviso critico
texto: N cheque(s) depositado(s) en Tango por $ X brutos no se proyectan en "Echeqs depositados": El plazo de acreditación guardado ("x") no sirve: … Corregilo en Parámetros → Cheques.
significa: El plazo de acreditación guardado no es un entero de 1 a 30. Sin plazo no hay fecha de acreditación.
corrige: Parámetros › Cheques: cargá el **Plazo de acreditación**.
:::

:::aviso critico
texto: El plazo de presentación guardado ("x") no sirve: … Mientras tanto los cheques en plazo de presentación y los vencidos no se leen. Corregilo en Parámetros → Cheques.
significa: El plazo de presentación guardado no es un entero de 1 a 30. Los cheques de día hábil pasado no se ven.
corrige: Parámetros › Cheques: cargá el **Plazo de presentación de los cheques**.
:::

:::aviso atencion
texto: No se encontró en Tango la cuenta que debita la boleta de depósito NNNN: sus cheques se cuentan como depositados en un banco, sin poder verificarlo.
significa: La boleta no tiene en Tango el renglón de la cuenta. Se proyecta igual, como un depósito en un banco.
corrige: Revisá la boleta en Tango. Si no era un depósito a un banco, corregila allá.
:::

:::aviso critico
texto: N cheque(s) en cartera por $ X quedan en su fecha sin correrse a un día hábil: No se encontró ningún día hábil en los 30 días siguientes a aaaa-mm-dd. Revisá RO_T_CALENDARIO.
significa: El calendario no tiene ningún día hábil después de esa fecha. Los cheques quedan en su fecha, que no es hábil.
corrige: Revisá los feriados en Parámetros › Calendario. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Echeqs en cartera: RO_T_CALENDARIO no tiene datos para aaaa-mm. Se asumen hábiles los días de lunes a viernes.
significa: El calendario bancario no llega a ese mes: los feriados de ese mes no corren los cheques.
corrige: Cargá los feriados de ese mes en Parámetros › Calendario.
:::

:::aviso atencion
texto: Echeqs depositados: RO_T_CALENDARIO no tiene datos para aaaa-mm. Se asumen hábiles los días de lunes a viernes.
significa: Lo mismo, para la fecha de acreditación de los depositados: puede estar corrida si hay un feriado.
corrige: Cargá los feriados de ese mes en Parámetros › Calendario.
:::

:::aviso info
texto: N cheque(s) por $ X están excluidos a mano y no entran al cashflow. Motivos: … Se ven en la pestaña, tildando "Ver excluidos".
significa: Lo dice el tablero: hay cheques excluidos. No pide nada.
corrige: Nada. Si alguno ya no corresponde, prendé **Ver excluidos** y volvé a incluirlo.
:::

:::aviso atencion
texto: $ X con fecha fuera del horizonte no se muestran en la tabla.
significa: Cheques cuyo día cae después del último mes del período. Existen, pero quedan después del horizonte.
corrige: Si los querés ver, ampliá el horizonte en Parámetros › Calendario.
:::

:::aviso info
texto: Fila: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Lo mismo, dicho por el tablero.
corrige: Si los querés ver, ampliá el horizonte en Parámetros › Calendario.
:::

### En Venta Cobrada Anticipada

:::aviso atencion
texto: No hay clientes configurados para venta cobrada anticipada. Cargalos en Parámetros → Cheques: sin clientes, esa sub-pestaña se muestra vacía a propósito.
significa: No hay ningún cliente activo cargado. No se resta nada de Ventas.
corrige: Parámetros › Cheques › Venta cobrada anticipada: **Agregar cliente**.
:::

:::aviso atencion
texto: Estos códigos están en el maestro pero quedan fuera del módulo, que sólo mira franquicias y locales: X, Y. Sus cheques nunca van a aparecer.
significa: Hay clientes cargados cuyo código no es de una franquicia ni de un local.
corrige: Revisá el código en Parámetros › Cheques. Si está mal, dalo de baja.
:::

:::aviso atencion
texto: Estos clientes están cargados pero hoy no tienen ningún cheque vivo: X, Y. Puede ser normal, o puede ser un código mal tipeado.
significa: Esos clientes no tienen cheques de hoy en adelante en Tango.
corrige: Si el cliente sí entregó cheques, revisá su código en Parámetros › Cheques (distingue mayúsculas).
:::

:::aviso critico
texto: Venta cobrada anticipada: No se encontró ningún día hábil en los 30 días siguientes a aaaa-mm-dd. Revisá RO_T_CALENDARIO. Esos cheques quedan en su fecha.
significa: El calendario no tiene ningún día hábil después de esa fecha. Los cheques quedan en su fecha.
corrige: Revisá los feriados en Parámetros › Calendario. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Venta cobrada anticipada: RO_T_CALENDARIO no tiene datos para aaaa-mm. Se asumen hábiles los días de lunes a viernes.
significa: El calendario bancario no llega a ese mes.
corrige: Cargá los feriados de ese mes en Parámetros › Calendario.
:::

:::aviso atencion
texto: $ X con fecha fuera del horizonte no se muestran en la tabla.
significa: Cheques tildados cuya fecha cae después del horizonte. Su venta puede estar adentro del cuadro, y el tablero no la resta.
corrige: Si los querés ver, ampliá el horizonte en Parámetros › Calendario.
:::

### En Parámetros › Cheques

:::aviso atencion
texto: Los cheques no tienen ninguna alícuota de retención vigente hoy, así que los depositados no tienen neto y no se proyectan: un neto igual al bruto informaría de más. Cargalas abajo, en Depósito de cheques.
significa: No hay alícuotas vigentes. La **Tasa de retención vigente hoy** dice «sin alícuotas».
corrige: **Cargar alícuota**, con su vigencia.
:::

También muestra, si pasa, el del plazo de acreditación o de presentación que no sirve (los textos de arriba).

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción.

| Mensaje | Cuándo |
| --- | --- |
| "N cheque(s) en plazo de presentación acreditan el dd/mm/aaaa." | Se asignó una fecha. |
| "N fecha(s) deshechas: esos cheques dejan de sumar." | Se deshizo una fecha. |
| "N cheque(s) marcados como acreditados." | Se marcó **Ya acreditó**. Dice por cuánto neto salen de **Echeqs depositados**. |
| "N marca(s) deshechas." | Se deshizo **Ya acreditó**. |
| "N cheque(s) excluidos. El tablero ya no cuenta esa plata." | Se excluyó. |
| "N cheque(s) incluidos de nuevo en el cashflow." | Se volvió a incluir. |
| "N cheque(s) tildados. Ventas ya usa este neteo." | Se tildó en Venta Cobrada Anticipada (o «destildados»). |
| "No se asignó ninguna fecha: …" | La fecha no sirve: es hoy o ya pasó, no es hábil, es posterior al último día del plazo del cheque o al fin del horizonte, o algún cheque no está en plazo de presentación. Dice cuál y por qué. No se guardó ninguna. |
| "No se deshizo ninguna fecha: …" | Algún cheque no tenía fecha. Puede que la pantalla haya quedado vieja: tocá **Actualizar**. |
| "No se marcó ningún cheque: …" | Alguno está en cartera, ya acreditó, acredita hoy o no se proyecta. No se marcó ninguno. |
| "No se pudo guardar: …" | Falló la exclusión. Por ejemplo, falta el motivo o ninguno sigue en cartera. |
| "No se pudo guardar la marca, así que se dejó como estaba: …" | Falló un tilde de Venta Cobrada Anticipada. La casilla vuelve sola. |

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: Hay cheques depositados en Tango y ninguna fila activa del tablero muestra la serie ECHEQS → DEPOSITADOS: su neto no entra en ninguna fila. Corré sql/cashflow_estructura_echeqs_depositados.sql o activá la fila en Parámetros → Cashflow.
significa: Los depositados se calculan pero el tablero no tiene la fila **Echeqs depositados**. Esa plata no está en el tablero.
corrige: Sistemas, creando o activando la fila.
:::

:::aviso critico
texto: N cheque(s) depositado(s) en Tango por $ X brutos no se proyectan en "Echeqs depositados": Falta el parámetro echeqs_dias_acreditacion_deposito: corré sql/cashflow_echeqs_deposito.sql contra la base central.
significa: El plazo de acreditación no está instalado. Los depositados no se proyectan.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: Falta el parámetro echeqs_dias_vencidos_cartera (el plazo de presentación de los cheques): corré sql/cashflow_echeqs_vencidos.sql contra la base central. Mientras tanto los cheques en plazo de presentación y los vencidos no se leen.
significa: El plazo de presentación no está instalado. Los cheques de día hábil pasado no se ven.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Los cheques en plazo de presentación y los vencidos no se leen: falta correr sql/cashflow_echeqs_vencidos.sql contra la base central. Lo demás de la pestaña funciona igual.
significa: Falta instalar las fechas de acreditación. La tarjeta **En plazo de presentación** dice «No se leen: falta correr el script».
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso info
texto: Para marcar un cheque depositado como "ya acreditó" hace falta correr sql/cashflow_echeqs_acreditado.sql contra la base central. Mientras tanto, los depositados se proyectan hasta su fecha de acreditación.
significa: Los botones **Ya acreditó** y **Deshacer ya acreditó** no se muestran.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso info
texto: No se encontró la tabla BI_T_EXTRACTOS_INTERBANKING: los cheques depositados se dan por acreditados por plazo o con la marca "ya acreditó", sin confirmarlo con el banco.
significa: No se puede leer el extracto de Interbanking. Ningún cheque sale como **acreditado (Interbanking)**.
corrige: Avisá a sistemas. Mientras tanto, marcá **Ya acreditó** lo que veas acreditado.
:::

:::aviso atencion
texto: Para excluir cheques hace falta correr sql/cashflow_echeqs_excluir.sql contra la base central.
significa: Sale al pasar el mouse por **Excluir del cashflow** o **Volver a incluir**, que están apagados.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Todavía no existen las tablas de venta cobrada anticipada. Corré sql/echeqs_prechequeado.sql contra la base central. Mientras tanto, Cheques en Cartera funciona igual: sale entera de Tango.
significa: Venta Cobrada Anticipada no está instalada.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Todavía no existe la tabla de alícuotas de los cheques. Corré sql/cashflow_echeqs_deposito.sql contra la base central. Mientras tanto los cheques depositados en Tango no tienen neto y no se proyectan; la cartera se ve y se proyecta como siempre.
significa: Sale en Parámetros › Cheques: el depósito de cheques no está instalado.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: Falta la tabla RO_T_CASHFLOW_ECHEQ_FECHA_ACREDITACION: corré sql/cashflow_echeqs_vencidos.sql contra la base central. Mientras tanto los cheques en plazo de presentación y los vencidos no se leen.
significa: Sale en Parámetros › Cheques: falta instalar las fechas de acreditación.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso critico
texto: No se pudieron cargar los cheques en cartera: …
significa: Cheques en Cartera no pudo cargar. Después de los dos puntos va el error.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudieron cargar los cheques pre-chequeados: …
significa: Venta Cobrada Anticipada no pudo cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Qué quiere decir «en plazo de presentación»?
Es un cheque cuya fecha ya pasó y que **Tango todavía tiene en cartera**, dentro del plazo para presentarlo al cobro (30 días corridos, los de la Ley de Cheques, salvo que se cambie en Parámetros › Cheques). No está vencido: se puede depositar.

Mientras siga en cartera, el cashflow no sabe cuándo va a entrar esa plata, así que no lo suma hasta que le pongas una fecha de acreditación en la columna **Acredita**.

Si ya lo depositaste o lo usaste para pagar, el dato a corregir está en Tango: registrá la boleta de depósito (o el pago). En cuanto Tango lo saca de cartera, sale solo de esta lista. Si un depósito ya registrado sigue apareciendo, tocá **Actualizar**.

Pasado el plazo está vencido: ya no se puede presentar, y se resuelve en Tango o se excluye.
:::

:::pregunta ¿Por qué no veo los cheques en plazo de presentación sin fecha?
Porque están escondidos por defecto: no suman, y en el trabajo normal son ruido. Prendé **Ver valores en plazo de presentación sin fecha**. Debajo del interruptor dice cuántos esconde y por cuánto, y el panel de avisos los cuenta siempre.
:::

:::pregunta ¿Qué son los cheques marcados «depositado» que aparecen con Retención y Neto?
Son cheques que Tesorería ya depositó (la boleta está en Tango) y que el banco todavía no acreditó. Ya no están en cartera, pero la plata todavía no está en el saldo bancario. Son el arrastre de los depósitos de días anteriores.

El cashflow los muestra el día que acreditan (la fecha de la boleta más el plazo de acreditación, 2 días hábiles por defecto), por el importe menos la retención. Suman en **Echeqs depositados**, no en **Echeqs en cartera**. El día que acreditan dejan de sumar y al día siguiente desaparecen.

Si querés ver sólo la cartera, prendé **Ocultar depositados**.
:::

:::pregunta ¿Por qué Acredita dice otra fecha que Fecha de pago?
**Fecha de pago** es la fecha escrita en el cheque. **Acredita** es el día en que cae el importe, y es la columna del período donde está.

- En un cheque en cartera, si la fecha cae un fin de semana o un feriado, Acredita dice el hábil siguiente y el cheque lleva la marca **corrida**.
- En un depositado, Acredita es la boleta más el plazo de acreditación: la fecha del cheque no interviene. Un cheque con fecha de dentro de un mes, depositado hoy, acredita a los días hábiles del plazo.
- En un cheque en plazo de presentación, Acredita es la fecha que se le asignó.

Un cheque en cartera suma el importe completo su mismo día hábil: no se le agregan días de acreditación. Eso pasa recién cuando se deposita en Tango.
:::

:::pregunta ¿Puedo corregir un «acreditado (por plazo)»?
No hace falta. Es un depositado cuya fecha de acreditación es hoy: ya no suma en ninguna fila, se ve sólo hoy y mañana deja de verse. No tiene acción en la pantalla, ni con permiso.

**Ya acreditó** sirve para los depositados que todavía suman (los que acreditan de mañana en adelante) y que el banco acreditó antes.
:::

:::pregunta ¿Por qué no veo las casillas para seleccionar cheques?
Porque no tenés el permiso de edición de Echeqs. Excluir, asignar fechas, marcar «ya acreditó» y tildar en Venta Cobrada Anticipada piden ese permiso; sin él, se ven las marcas pero no las casillas ni la barra. Pedilo a quien da los permisos: ver [permisos y parámetros](ayuda:general_permisos_parametros#ver-y-editar-dos-permisos-por-pestana).

Si las casillas están pero **Excluir del cashflow** está apagado, pasá el mouse: puede haber un depositado en la selección, o faltar algo de instalación.
:::

:::pregunta ¿La retención es la misma para todos los bancos?
Sí. Hay un solo juego de alícuotas para todos los cheques, en Parámetros › Cheques, y la tasa es la suma de las vigentes al día en que acredita cada cheque. No depende del banco emisor ni del banco donde se deposita.
:::

:::pregunta ¿Por qué un cheque está en las dos sub-pestañas?
Porque son dos preguntas distintas. En **Cheques en Cartera** suma como disponibilidad; en **Venta Cobrada Anticipada**, tildado, se resta de la cobranza que Ventas proyecta para esa venta. Las dos cosas juntas dejan el cheque contado una sola vez.

Excluir un cheque de la cartera y destildarlo en Venta Cobrada Anticipada son decisiones independientes: ninguna implica la otra.
:::

:::pregunta ¿Por qué un cheque de un cliente pre-chequeado no aparece en Venta Cobrada Anticipada?
Puede ser por alguna de estas razones:

- Su **Fecha venta estimada** ya pasó: esa venta se facturó y se cobró, y no hay nada que restar.
- Su fecha es anterior a hoy, o Tango lo tiene anulado o rechazado.
- El cliente está dado de baja en Parámetros › Cheques, o su código no es de una franquicia ni de un local.
:::

:::pregunta ¿Por qué A netear de Ventas no coincide con MARCADO (visible)?
La tarjeta suma todo lo tildado del listado; el pie, sólo lo tildado que se está viendo con el desplegable de clientes y el buscador. Además, el tablero no resta lo que cae después del fin del horizonte, y la tarjeta sí lo cuenta.
:::

:::pregunta ¿Excluir un cheque lo cambia en Tango?
No. El cheque sigue en cartera en Tango. Lo único que cambia es que el cashflow deja de contar esa plata. Si después se deposita en Tango, entra en **Echeqs depositados** como cualquier otro.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí. **Echeqs en cartera** suma lo mismo que las tres primeras tarjetas, y **Echeqs depositados** lo mismo que la tarjeta **Depositados (neto)**, columna por columna. Lo tildado en Venta Cobrada Anticipada está en la fila **Neteo cheques adelantados** de Ventas, en negativo. Las tres son proyectadas: ver [real y proyectado](ayuda:general_real_proyectado).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Cheque en cartera | Un cheque de terceros que Tango tiene en cartera: todavía no se depositó ni se usó |
| Fecha de pago | La fecha escrita en el cheque |
| Día hábil del cheque | Su fecha, o el hábil siguiente si cae un fin de semana o un feriado |
| Corrida | La marca de un cheque cuya fecha cayó un día no hábil y se pasó al siguiente |
| Plazo de presentación | Los días corridos desde su fecha en que un cheque se puede presentar al cobro: 30, por la Ley de Cheques |
| En plazo de presentación | Un cheque en cartera cuyo día hábil ya pasó y que todavía está dentro del plazo de presentación. Suma sólo con una fecha de acreditación asignada |
| Fecha de acreditación asignada | La fecha que se le pone a un cheque en plazo de presentación para decir cuándo entra. Sólo vive en el cashflow |
| Vencido | Un cheque en cartera que superó el plazo de presentación. No se puede presentar y no suma |
| Depositado | Un cheque que Tesorería registró en Tango con una boleta de depósito a una cuenta de banco |
| Plazo de acreditación | Los días hábiles entre la boleta de depósito y la acreditación: 2 por defecto |
| Retención | Lo que retiene el banco sobre el depósito: el importe por la suma de las alícuotas vigentes |
| Neto | El importe del cheque menos la retención. Es lo que suma un depositado |
| Acreditado | Un depositado que ya está en el saldo bancario: por plazo, según el extracto de Interbanking o marcado «ya acreditó». No suma |
| Excluido | Un cheque en cartera que se decidió que no se va a cobrar. No suma |
| Venta cobrada anticipada | La modalidad de los clientes que entregan los cheques antes de que se les facture |
| Días de pre-chequeado | Cuántos días antes de la fecha del cheque se le factura al cliente |
| Fecha venta estimada | La fecha del cheque menos los días de pre-chequeado. Decide si el cheque aparece |
| Neteo | Lo tildado en Venta Cobrada Anticipada, que se resta de la cobranza proyectada de Ventas |
| Horizonte | El período que proyecta el cashflow: el tramo diario más los meses |
