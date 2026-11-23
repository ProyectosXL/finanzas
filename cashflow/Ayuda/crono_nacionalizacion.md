---
titulo: Crono Nacionalización
pestana: crono_nacionalizacion
categoria: Comercio Exterior
estado: en_revision
actualizado: 2026-11-23
usuarios: Comercio Exterior, Tesorería
bajada: Cuánto falta pagar de la nacionalización de cada contenedor y cuándo, en pesos, con sus pagos parciales y su saldo.
---

## Para qué sirve

Muestra, contenedor por contenedor, cuánto va a costar nacionalizarlo y cuándo, y cuánto falta pagar de eso.

Toma los gastos de nacionalización que estimó Comercio Exterior, los pasa a pesos con el dólar de la fecha de nacionalización y les resta los pagos parciales que se registren acá.

Es el detalle de la fila de Nacionalizaciones del tablero: lo que se ve en sus columnas es exactamente lo que esa fila proyecta. Ver [cómo se lee el tablero](ayuda:general_tablero).

Es el otro pago del mismo contenedor que está en [Proveedores Exterior](ayuda:proveedores_exterior): allá el pago al proveedor, acá la nacionalización. Las dos pestañas comparten la forma de editar fechas, las marcas, los interruptores y el tilde; eso está explicado una sola vez, en Proveedores Exterior, y acá se enlaza.

## De dónde salen los datos

:::diagrama
fuente: La app de Comercio Exterior | los contenedores, sus fechas y la estimación de gastos de nacionalización, en dólares
fuente: Los costos reales cargados en Comercio Exterior | lo que de verdad costó nacionalizar, en pesos
fuente: La curva de dólar futuro ROFEX | a cuánto está el dólar de cada mes futuro
fuente: El dólar divisa | el vendedor del día hábil anterior, para las nacionalizaciones de los próximos días
pestana: Crono Nacionalización | pasa la estimación a pesos y le resta los pagos parciales
tablero: Fila Nacionalizaciones | el saldo que falta pagar, de hoy en adelante
alt: La app de Comercio Exterior, sus costos reales, la curva ROFEX y el dólar divisa alimentan la pestaña, que le pasa al tablero el saldo de cada nacionalización.
:::

- **La app de Comercio Exterior**: cada contenedor con su proveedor, su orden de compra, su despachante, las fechas de embarque, de llegada y de nacionalización, y la **estimación de gastos de nacionalización**: derechos, tasa estadística, IVA, IVA adicional, Ganancias, Ingresos Brutos, SIM y antidumping. Comercio Exterior los calcula como porcentajes del CIF, que arranca en el FOB en dólares: por eso la estimación está **en dólares**. La fecha de nacionalización es la misma que ve esa aplicación: si la cambiás acá, cambia allá.
- **Los costos reales cargados en Comercio Exterior**: cuando el contenedor ya se nacionalizó, lo que costó de verdad, en pesos.
- **La curva de dólar futuro ROFEX**: a cuánto se puede comprar hoy un dólar de cada mes futuro.
- **El dólar divisa**: la cotización divisa, punta vendedora, del día hábil anterior. Se usa para lo que se nacionaliza en los próximos días o ya venció.
- **Lo que se registra en esta pestaña**: el tilde de pagada, los pagos parciales y la fecha del saldo. Son del cashflow: Comercio Exterior no se entera.

Entran sólo los contenedores cuya orden de compra principal tiene fecha de movimiento igual o posterior a **Contenedores desde (fecha de movimiento)**, de Parámetros.

## Cómo se calcula

El costo de la nacionalización de cada contenedor es una de dos cosas:

- **Estimado**, mientras Comercio Exterior no cargó los costos reales: la estimación en dólares pasada a pesos con el dólar de la **fecha de nacionalización**.
- **Real**, cuando Comercio Exterior ya los cargó: el total en pesos que figura allá.

A ese costo se le restan los pagos parciales registrados acá. Lo que queda es el **saldo**, y es lo que se proyecta: en la **fecha del saldo** si se cargó una, o en la de nacionalización si no.

No suma en ninguna columna del período:

- lo que tiene la fecha (de nacionalización o del saldo) vencida;
- lo tildado como **Pagada completa**;
- lo que los pagos parciales ya cubren;
- un contenedor con costos reales y sin pagos parciales: se toma como ya pagado;
- una orden de compra que repite un contenedor que ya está en otra fila.

:::formula
Importe ($) = Importe Est. (USD) × dólar aplicado
Costo ($) = costo real, si Comercio Exterior lo cargó; si no, Importe ($)
Saldo ($) = Costo ($) − pagos parciales vigentes    (nunca menos de cero)
Dólar aplicado = dólar divisa vendedor del día hábil anterior para los próximos días y lo vencido; si no, curva ROFEX del mes de nacionalización
:::

:::ejemplo ilustrativo
Un contenedor con gastos de nacionalización estimados en **U$S 8.000** se nacionaliza dentro de tres meses. La curva dice **$ 1.500** para ese mes.

- Costo estimado: 8.000 × 1.500 = **$ 12.000.000**, en la fecha de nacionalización.
- Se registra un pago parcial de **$ 5.000.000**, con fecha del saldo a fin de mes.
- Saldo: 12.000.000 − 5.000.000 = **$ 7.000.000**, que el tablero proyecta a fin de mes.
- Después se paga **U$S 2.000** a una cotización de **$ 1.450**: son 2.000 × 1.450 = **$ 2.900.000**. El saldo baja a 7.000.000 − 2.900.000 = **$ 4.100.000**.

Si ese mismo contenedor se nacionalizara pasado mañana, iría al dólar divisa vendedor del día hábil anterior, digamos **$ 1.440**: 8.000 × 1.440 = **$ 11.520.000**.
:::

### Con qué dólar se valúa la nacionalización

Es la misma regla que en Proveedores Exterior ([con qué dólar se valúa](ayuda:proveedores_exterior#con-que-dolar-se-valua)), con tres diferencias:

- **La fecha que manda es la de nacionalización**, no la de pago. Es el mismo contenedor, pero los dos egresos caen en meses distintos y les toca un punto distinto de la curva.
- **Los próximos días van al dólar divisa vendedor**, no al BCRA. Los días son los mismos (**Días con dólar del día anterior**), y también el día hábil anterior. Si no hay cotización divisa, esos importes quedan sin valuar y la pantalla avisa: no se usa el BCRA ni la curva en su lugar.
- **La cotización no se corrige a mano.** La celda de **Dólar aplicado** es de sólo lectura.

La **fecha del saldo** no cambia la cotización: sólo decide en qué columna cae lo que falta.

## La pantalla

La pantalla, de arriba hacia abajo:

- Los avisos de la pestaña: los de la grilla y los que el tablero dice de ella.
- Las tres tarjetas, una por vista.
- El buscador y los interruptores **Ver vencidas**, **Ver pagadas** y **Ver con costos reales**.
- Los botones de vista, **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea que dice qué período se mide y con qué dólar está valuada la tabla.
- Las columnas de cada contenedor, de **Fecha Est. EMB** a **Pagada**.
- Las cuatro columnas del pago parcial: **Costo ($)**, **Pagado ($)**, **Saldo ($)** y **Fecha del saldo**.
- Las columnas del período, con su total arriba de cada fecha, **Total** y el pie **TOTALES**.

Las tarjetas (**Vista Días**, **Vista Meses**, **Período Completo**), las tres vistas, el buscador, **Columnas fijas** y **Exportar** funcionan igual que en Proveedores Exterior: ver [las tarjetas](ayuda:proveedores_exterior#las-tarjetas) y [las tres vistas](ayuda:proveedores_exterior#las-tres-vistas). Acá las columnas fijas por defecto también son **Proveedor** y **Contenedor**.

La tabla está ordenada por **fecha de nacionalización**; los contenedores sin esa fecha van al final.

### Qué contenedores muestra

- **Los que no tienen costos reales**: siempre, con su costo estimado.
- **Los que ya tienen costos reales**: no suman, porque se toman como ya pagados. Se ven con **Ver con costos reales** prendido, y desde ahí se les puede registrar un pago parcial.
- **Los que tienen costos reales y un pago parcial con saldo**: se ven siempre, porque suman ese saldo.

### Las columnas

| Columna | Qué muestra |
| --- | --- |
| **Fecha Est. EMB** | La fecha estimada de embarque. |
| **Proveedor**, **Contenedor**, **Orden Compra**, **Despachante** | Los datos del contenedor. |
| **Importe Est. (USD)** | Los gastos de nacionalización estimados, en dólares, tal como los cargó Comercio Exterior. |
| **Dólar aplicado** | La cotización con la que se pasó a pesos, y de dónde sale. No se edita. |
| **Importe ($)** | Importe Est. por dólar aplicado. Un guión si no se pudo valuar. |
| **ETD** | La fecha real de embarque, con **Conf**; si todavía no está, un guión con **Est**. |
| **ETA** | La fecha de llegada, con **Conf** si Comercio Exterior la marcó confirmada o **Est** si no. |
| **Fecha Nac.** | Cuándo se nacionaliza. Se edita con un clic. |
| **Pagada** | El tilde para registrar un pago: pregunta si fue completo o una parte. |
| **Costo ($)** | El costo del contenedor, con la etiqueta **real** o **estimado**. Al pasar el mouse dice de dónde sale. |
| **Pagado ($)** | La suma de los pagos parciales. Con pagos, lleva un ícono de lista para verlos. |
| **Saldo ($)** | Costo menos pagado: lo que falta. |
| **Fecha del saldo** | Cuándo se espera pagar lo que falta. |

### Lo que es distinto en las marcas

Las marcas de la fecha, la fila rosada y las de la columna del dólar están explicadas en Proveedores Exterior: [las marcas de la fecha](ayuda:proveedores_exterior#las-marcas-de-la-fecha) y [las de la columna Dólar aplicado](ayuda:proveedores_exterior#las-marcas-de-la-columna-dolar-aplicado). Acá cambia esto:

| Marca | Qué quiere decir |
| --- | --- |
| **Editada** | En la **Fecha Nac.**: esa fecha la movió alguien desde el cashflow. Al pasar el mouse dice quién, cuándo y qué decía antes. Si después Comercio Exterior la cambió, la marca desaparece. Acá no hay marca **Manual**. |
| Borde azul y un ícono de transferencia, con `Divisa` y la fecha | En **Dólar aplicado**: se nacionaliza en los próximos días o ya venció, y va al dólar divisa vendedor de esa fecha. Reemplaza a la marca del dólar BCRA. |
| **real** / **estimado** | En **Costo ($)**: si el costo es el real de Comercio Exterior o la estimación pasada a pesos. "sin costo" quiere decir que no hay costo con el que calcular el saldo: ni real, ni estimado que se haya podido pasar a pesos. |
| **Pagado con pagos parciales** | En **Saldo ($)**, en verde: los pagos ya cubren el costo y el contenedor salió de la proyección, sin tilde. Si se pagó de más, lleva un triángulo; al pasar el mouse dice cuánto. |
| Fecha del saldo en gris | No hay pagos parciales: es la fecha de nacionalización. Se cambia en **Fecha Nac.** |
| "(la de nac.)" | Hay pagos parciales pero no se cargó otra fecha para el saldo: cae en la de nacionalización. |
| Lápiz al lado de la fecha del saldo | Se puede cambiar con un clic. |
| Fecha del saldo en rojo | La fecha del saldo venció: el saldo no suma hasta que se cargue otra. |
| Guiones en las cuatro columnas del pago | Es una orden de compra que repite un contenedor: el costo, los pagos y el saldo van en la fila de la orden principal. |
| Fila con el texto en gris | Contenedor con costos reales que no suma. |

### Los interruptores

**Ver vencidas** y **Ver pagadas** funcionan como en Proveedores Exterior ([los interruptores](ayuda:proveedores_exterior#los-interruptores)). Acá **Ver pagadas** esconde también las que los pagos parciales ya cubren, aunque nadie las haya tildado.

**Ver con costos reales** arranca apagado y esconde los contenedores con costos reales que no suman. Al lado dice cuántos esconde. Una fila que suma no se esconde nunca.

### El tilde de Pagada

En Proveedores Exterior el tilde saca el pago con un clic ([el tilde de pagado](ayuda:proveedores_exterior#el-tilde-de-pagado)). Acá **tildar pregunta primero cómo se pagó**:

- **Pagada completa**: sale entera de la proyección. Si tenía pagos parciales, el saldo también sale.
- **Fue un pago parcial**: abre el diálogo para registrar lo que se pagó. El tilde queda sin marcar y lo que falta se sigue proyectando.
- **Cancelar**, la cruz, **Esc** o un clic afuera: no cambia nada.

**Destildar** es un clic, como siempre: la nacionalización vuelve a la proyección.

Este tilde es el de la nacionalización. El del proveedor del exterior está en la otra pestaña, y marcar uno no dice nada del otro.

### El pie y la línea del dólar

El pie **TOTALES** suma, sobre las filas que se están viendo, **Importe ($)**, **Costo ($)**, **Pagado ($)**, **Saldo ($)** y cada columna del período. No suma la columna en dólares ni la cotización. En **Costo ($)**, **Pagado ($)** y **Saldo ($)** no suman las órdenes que repiten un contenedor, que ahí muestran guiones.

La línea debajo de los botones dice con qué dólar está valuada la tabla, igual que en Proveedores Exterior ([el pie y la línea del dólar](ayuda:proveedores_exterior#el-pie-y-la-linea-del-dolar)), pero con la regla del dólar divisa: "Las nacionalizaciones de los próximos N días —hasta el …— y las vencidas van a dólar divisa vendedor del …; las posteriores, a dólar futuro ROFEX del mes en que caen."

### Cuando guardás la fecha de nacionalización

Pasa lo mismo que con la fecha de pago ([qué pasa cuando guardás una fecha](ayuda:proveedores_exterior#que-pasa-cuando-guardas-una-fecha)): se escribe en el maestro de Comercio Exterior, queda el rastro y no se puede vaciar. Dos diferencias:

- **Comercio Exterior recalcula la estimación de costos** del contenedor con las alícuotas de la fecha nueva, y la tabla se recarga con los importes nuevos. Si el recálculo falla, la fecha queda guardada igual y sale una advertencia.
- No hay cotización a mano que descartar, ni marca **Manual**: la fecha movida desde acá lleva **Editada**.

## Tareas frecuentes

### Editar la fecha de nacionalización

Es la misma tarea que en Proveedores Exterior ([editar una fecha](ayuda:proveedores_exterior#editar-una-fecha)), sobre la columna **Fecha Nac.**

:::pasos
1. Hacé clic en la celda de **Fecha Nac.** y elegí la fecha nueva.
2. Apretá **Enter** o hacé clic afuera. **Esc** cancela.
Resultado: la fecha queda en el maestro de Comercio Exterior, marcada **Editada**, Comercio Exterior recalcula la estimación y la tabla se recarga.
:::

### Registrar que la nacionalización se pagó entera

:::pasos
1. Tocá la casilla de **Pagada** del contenedor.
2. En la pregunta "¿Cómo se pagó?", elegí **Pagada completa**.
Resultado: sale entera de la proyección. La fila se esconde si **Ver pagadas** está apagado.
:::

### Registrar un pago parcial

:::pasos
1. Tocá la casilla de **Pagada** del contenedor y elegí **Fue un pago parcial**.
2. Cargá la **Fecha de pago**: hoy o anterior.
3. En **Moneda** elegí **Pesos** y cargá el **Importe ($)**.
4. Cargá la **Fecha estimada de pago del saldo**: cuándo pensás pagar lo que falta. Es obligatoria si la nacionalización ya venció o no tiene fecha; si no, podés dejarla vacía y el saldo cae en la fecha de nacionalización.
5. Si querés, escribí una **Observación**.
6. Mirá el cuadro **Antes** / **Después** del costo, lo pagado y el saldo, y tocá **Registrar pago**.
Resultado: la notificación dice cuánto se registró y cuánto saldo queda, y el tablero proyecta ese saldo en su fecha.
:::

El botón **Registrar pago** no se habilita hasta que esté todo lo necesario; debajo dice qué falta. Si en realidad se pagó completa, cerrá el diálogo, tocá el tilde otra vez y elegí **Pagada completa**.

Si la columna **Costo ($)** dice "sin costo", o la fila es una orden de compra que repite un contenedor, la opción **Fue un pago parcial** aparece deshabilitada con el motivo.

### Registrar un pago parcial hecho en dólares

:::pasos
1. Abrí el pago parcial como en la tarea de arriba.
2. En **Moneda** elegí **Dólares**.
3. Cargá el **Importe (U$S)** y la **Cotización usada**.
4. Completá las fechas y tocá **Registrar pago**.
Resultado: se guarda el equivalente en pesos, que es lo que se resta del saldo. El diálogo lo muestra antes de guardar: "Equivale a $ X: es lo que se resta del saldo."
:::

### Cambiar la fecha del saldo

:::pasos
1. Hacé clic en la celda de **Fecha del saldo**, la que tiene el lápiz.
2. Elegí la fecha nueva: hoy o posterior.
3. Tocá **Cambiar**.
Resultado: "El saldo se proyecta ahora en la fecha nueva. La anterior queda en el historial."
:::

Sólo se puede con pagos parciales y mientras quede saldo. Sin pagos parciales, el saldo es la nacionalización entera y se mueve cambiando la **Fecha Nac.**

### Ver y anular un pago parcial

:::pasos
1. Hacé clic en el ícono de lista de la columna **Pagado ($)**.
2. Mirá los pagos: fecha, moneda, dólares, cotización, pesos, observación y quién los cargó. Abajo, cómo se fue moviendo la fecha del saldo.
3. Para anular uno, tocá **Anular** y confirmá.
Resultado: el pago deja de restar, el saldo vuelve a subir y se proyecta otra vez. El pago no se borra: queda en la lista, tachado, como anulado.
:::

### Volver a proyectar una nacionalización tildada por error

:::pasos
1. Prendé **Ver pagadas**.
2. Sacale el tilde a la casilla de **Pagada**.
Resultado: "Vuelve a la proyección: la fila del tablero lo cuenta otra vez."
:::

### Registrar un pago a un contenedor con costos reales

:::pasos
1. Prendé **Ver con costos reales**.
2. Tocá la casilla de **Pagada** del contenedor y elegí **Fue un pago parcial**.
3. Cargá el pago y la fecha del saldo.
Resultado: el saldo se calcula sobre el costo real. Mientras quede saldo, la fila suma y se ve siempre.
:::

### Buscar y exportar

Igual que en Proveedores Exterior: [buscar un contenedor](ayuda:proveedores_exterior#buscar-un-contenedor) y [exportar lo que se ve](ayuda:proveedores_exterior#exportar-lo-que-se-ve).

## Parámetros

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Días con dólar del día anterior** | Parámetros › Compras Exterior | 7 | Lo que se nacionaliza dentro de estos días, o ya venció, va al dólar divisa vendedor del día hábil anterior; lo posterior, a la curva. En 0, todo va con la curva. Mueve el tablero. |
| **Antigüedad máxima del dólar del día anterior** | Parámetros › Compras Exterior | 3 | Si la última cotización divisa tiene más de estos días, se usa igual y la pantalla avisa. No cambia ningún número. |
| **Contenedores desde (fecha de movimiento)** | Parámetros › Compras Exterior | 01/01/2025 | Qué contenedores entran: los de fecha de movimiento igual o posterior. No puede ser una fecha futura. |
| **Columnas diarias** | Parámetros › Calendario | 28 | Cuántos días tiene la vista Días. |
| **Columnas mensuales** | Parámetros › Calendario | 12 | Cuántos meses tiene la vista Meses. Lo que cae después del último queda fuera del período. |
| Feriados bancarios | Parámetros › Calendario | Los del calendario bancario | Qué día es el "día hábil anterior" del dólar divisa, y qué columnas diarias van en gris. |

Los dos primeros son los mismos que usa Proveedores Exterior: allá deciden el dólar BCRA, acá el divisa. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Avisos y qué hacer

Los avisos se ven arriba de la tabla, con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: N contenedor(es) por $ X tienen la fecha de nacionalización ya vencida, así que NO suman en ninguna columna del período. No se los reubica en hoy, porque nadie afirmó que ese importe se mueve hoy: cargales la fecha nueva y entran solos.
significa: Nacionalizaciones con la fecha pasada que nadie dio por pagadas. Si alguno tiene pagos parciales, el texto dice "fecha de nacionalización —o la del saldo, si tienen pagos parciales—": lo vencido es la fecha del saldo.
corrige: Prendé **Ver vencidas**: si ya se pagó, tildala; si no, cargale la fecha nueva en **Fecha Nac.** o en **Fecha del saldo**.
:::

:::aviso atencion
texto: N contenedor(es) por U$S X no tienen fecha de nacionalización, así que no hay mes al que pedirle cotización y no se pueden valuar en pesos. No se les aplica ningún tipo de cambio inventado: cargales la fecha y el importe aparece.
significa: Contenedores sin fecha de nacionalización. El monto va en dólares porque no se pudo pasar a pesos.
corrige: Cargales la **Fecha Nac.**, acá o en Comercio Exterior.
:::

:::aviso atencion
texto: N contenedor(es) con pagado de más: $ X por encima de su costo de nacionalización. El saldo se toma como cero —el cashflow no proyecta egresos negativos— así que la diferencia no se ve en ninguna columna. Pasa cuando el costo real resulta menor que el estimado sobre el que se pagó, o con un pago cargado de más: revisá los pagos del contenedor.
significa: Los pagos parciales superan el costo.
corrige: Abrí los pagos desde **Pagado ($)** y anulá el que esté de más, si corresponde.
:::

:::aviso atencion
texto: Hay N contenedores en el cronograma pero ninguno tiene gastos de nacionalización estimados cargados, así que la fila va en cero.
significa: La fila del tablero da cero porque no hay estimaciones, no porque no haya contenedores.
corrige: Cargar la estimación en Comercio Exterior.
:::

:::aviso info
texto: N contenedor(es) con pagos parciales: $ X pagados, $ Y de saldo pendiente.
significa: Por qué la fila del tablero proyecta menos que la estimación.
corrige: Nada.
:::

:::aviso info
texto: N contenedor(es) tienen el nacionalización marcado como YA HECHO, así que salieron de la proyección: $ X que la fila del tablero ya no cuenta. El importe no se perdió —sale por su propia serie— y se destilda desde la pestaña si se marcó por error.
significa: Lo tildado como **Pagada completa**. Si algunos ya estaban vencidos, el aviso lo dice: esos no sumaban y no entran en ese importe.
corrige: Nada, salvo que haya un tilde por error: prendé **Ver pagadas** y destildalo.
:::

:::aviso info
texto: N contenedor(es) caen en un mes que la curva de dólar futuro no cubre (llega hasta …), así que se valuaron con la cotización del mes más cercano. Están marcados en la grilla.
significa: El importe en pesos es una aproximación. Los marca el borde violeta punteado.
corrige: Nada. Cuando la curva publique ese mes, se valúa solo.
:::

:::aviso info
texto: Fila: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Nacionalizaciones posteriores al último mes del período.
corrige: Si las querés ver, subí **Columnas mensuales** en Parámetros › Calendario.
:::

:::aviso atencion
texto: Fila: $ X sin fecha asignada no se pueden ubicar en el tablero.
significa: Importe sin fecha que no puede ir a ninguna columna.
corrige: Cargale la fecha al contenedor.
:::

Si el tablero no responde, esos dos últimos salen con el texto de la grilla, que termina en "no se muestran en la tabla." y "quedan fuera de la tabla."

### Los mensajes al guardar

| Mensaje | Cuándo |
| --- | --- |
| "Fecha actualizada en el maestro de Comercio Exterior: la ve también esa aplicación." | Se guardó la fecha de nacionalización. |
| "No se recalculó la estimación" | La fecha se guardó pero Comercio Exterior no pudo recalcular la estimación: los gastos siguen calculados con la fecha anterior hasta que alguien la guarde desde Comercio Exterior. |
| "Pago registrado por $ X. Queda un saldo de $ Y, que se sigue proyectando." | Se registró un pago parcial. Si fue en dólares, aclara que es el equivalente en pesos. |
| "Pago registrado por $ X. Con esto la nacionalización queda pagada: sale de la proyección." | El pago cubrió el costo. Si se pagó de más, agrega cuánto. |
| "Pago anulado: deja de restar y el saldo vuelve a subir $ X. Queda en el historial del contenedor." | Se anuló un pago parcial. |
| "El saldo se proyecta ahora en la fecha nueva. La anterior queda en el historial." | Se cambió la fecha del saldo. |

Si algo falla, el diálogo o la notificación dicen por qué, y no se guarda nada.

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No se pudo leer la curva de dólar futuro ROFEX (…), así que los gastos de nacionalización no se pueden pasar a pesos: la pestaña los muestra en dólares y la fila del tablero va en cero.
significa: Sin curva no hay a cuánto convertir. La columna en dólares está; las de pesos, no.
corrige: Avisale a Sistemas. Cuando la curva vuelve, se valúa solo.
:::

:::aviso critico
texto: No se pudo leer el dólar divisa (…), así que las nacionalizaciones de los próximos N días y las vencidas no se pueden pasar a pesos y quedan fuera del tablero. No se usa el dólar BCRA ni la curva en su lugar. (el motivo)
significa: Falló la lectura del dólar divisa. Las nacionalizaciones más cercanas quedan sin valuar.
corrige: Avisale a Sistemas con el motivo.
:::

:::aviso critico
texto: N contenedor(es) por U$S X caen en los próximos días o ya vencieron y van a dólar divisa vendedor, pero no hay ninguna cotización publicada, así que no se pueden valuar en pesos y quedan fuera del tablero. No se usa el dólar BCRA ni la curva en su lugar.
significa: Se pudo leer el dólar divisa, pero no hay ninguna cotización. Esas nacionalizaciones no entran al tablero.
corrige: Avisale a Sistemas que falta la cotización divisa.
:::

:::aviso critico
texto: La tabla quedó vacía. (y el mensaje del servidor)
significa: La pestaña no pudo cargar, o el servidor no devolvió ningún contenedor.
corrige: Probá con **Actualizar**. Si vuelve a pasar, pasale a Sistemas el mensaje del cartel.
:::

:::aviso atencion
texto: La curva de dólar futuro ROFEX se actualizó por última vez el dd/mm/aaaa hh:mm. Los pagos posteriores a los próximos N días se valúan con esa curva.
significa: La curva no es la del último día hábil. Los números salen igual, con la última que hay.
corrige: Puede salir un día hábil temprano, antes de que corra el proceso que baja la curva. Si sigue durante el día, avisale a Sistemas.
:::

:::aviso atencion
texto: La última cotización del dólar divisa es del dd/mm/aaaa; se usa ésa para las nacionalizaciones de los próximos N días.
significa: La cotización divisa tiene más días que **Antigüedad máxima del dólar del día anterior**. Se usa igual.
corrige: Avisale a Sistemas que no se está cargando el dólar divisa.
:::

:::aviso atencion
texto: El pago parcial de la nacionalización está apagado: faltan las tablas …. Corré … contra la base central y el botón aparece solo.
significa: Falta instalar algo del cashflow. La pregunta del tilde no ofrece el pago parcial, se proyecta la nacionalización entera y los contenedores con costos reales no se muestran.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: La edición de fechas está apagada: falta la tabla …. Corré … contra la base central y las fechas se vuelven editables solas.
significa: Todo se lee igual; lo único que no se puede es cambiar fechas.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: El tilde de «pagado» está apagado: falta la tabla …. Corré … contra la base central y la columna se vuelve marcable sola.
significa: **Pagada completa** no está disponible. Mientras tanto no hay nada marcado y el tablero proyecta todo lo pendiente.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Por qué acá no se puede corregir la cotización a mano?
Porque la corrección a mano es una por contenedor, y las dos pestañas valúan el mismo contenedor en dos fechas que caen en meses distintos: la de pago y la de nacionalización. Compartirla aplicaría a la nacionalización una cotización que alguien cargó pensando en el pago. Tenerla acá también necesitaría su propia regla de descarte cuando cambia el mes de nacionalización, y nadie lo pidió todavía.
:::

:::pregunta ¿Por qué acá se usa el dólar divisa y en Proveedores Exterior el BCRA?
Es una decisión de cada pestaña: las nacionalizaciones de los próximos días se valúan con el dólar divisa vendedor, y los pagos al proveedor con el BCRA vendedor. La regla de los días es la misma, y también el día hábil anterior. Si falta el dólar divisa, no se usa el BCRA en su lugar.
:::

:::pregunta ¿Por qué la estimación está en dólares?
Porque Comercio Exterior calcula los gastos de nacionalización como porcentajes del CIF, y el CIF arranca en el FOB en dólares. Por eso se pasan a pesos con la curva, igual que el pago al proveedor.
:::

:::pregunta ¿Por qué cambió el saldo cuando Comercio Exterior cargó los costos reales?
Porque el costo real y el estimado no miden lo mismo. El real incluye el flete internacional y no los impuestos que suma la estimación (IVA, IVA adicional, Ganancias e Ingresos Brutos). El saldo puede cambiar por eso, no sólo porque la estimación estuviera mal. Si el real queda por debajo de lo pagado, el saldo queda en cero y un aviso dice cuánto se pagó de más.
:::

:::pregunta ¿Qué diferencia hay entre "Pagada completa" y "Fue un pago parcial"?
**Pagada completa** saca toda la nacionalización de la proyección, tenga o no pagos parciales. **Fue un pago parcial** registra lo que se pagó y deja el resto como saldo, que se sigue proyectando en su fecha. Si con los pagos parciales se llega al costo, la fila dice **Pagado con pagos parciales** y también sale, sin tilde.
:::

:::pregunta ¿La fecha del saldo cambia el dólar con que se valúa?
No. El dólar sale de la fecha de nacionalización. La fecha del saldo sólo decide en qué columna cae lo que falta.
:::

:::pregunta ¿Comercio Exterior se entera de los pagos parciales?
No. Los pagos parciales, la fecha del saldo y el tilde son del cashflow. Lo único que se escribe en Comercio Exterior es la fecha de nacionalización.
:::

:::pregunta ¿Por qué no aparecen los contenedores que ya tienen costos reales?
Porque se toman como ya pagados y no suman. Están detrás de **Ver con costos reales**. Si uno tiene un pago parcial y le queda saldo, se ve siempre, porque suma.
:::

:::pregunta ¿Por qué mover la fecha de nacionalización cambió los importes?
Porque Comercio Exterior recalcula la estimación con las alícuotas de la fecha nueva, y porque la fecha nueva puede caer en otro mes de la curva.
:::

:::pregunta ¿Por qué una nacionalización vencida no suma?
Por la misma razón que un pago vencido en Proveedores Exterior: ver [esa pregunta](ayuda:proveedores_exterior#por-que-un-pago-vencido-no-suma-en-ninguna-columna). Acá, con pagos parciales, lo que vence es la fecha del saldo.
:::

:::pregunta ¿El flete y el seguro están en la nacionalización?
No en la estimación que se proyecta acá: ver [esa pregunta](ayuda:proveedores_exterior#el-flete-y-el-seguro-se-proyectan-aca). Sí están dentro del costo real cuando Comercio Exterior lo carga, que incluye el flete internacional.
:::

## Glosario

| Término | Qué es |
| --- | --- |
| **Nacionalización** | El trámite de aduana para ingresar la mercadería, con sus impuestos y gastos. |
| **Gastos de nacionalización estimados** | Derechos, tasa estadística, IVA, IVA adicional, Ganancias, Ingresos Brutos, SIM y antidumping, calculados por Comercio Exterior. En dólares. |
| **CIF** | FOB más flete más seguro: la base sobre la que se calculan los impuestos. |
| **Costo estimado** | La estimación pasada a pesos con el dólar de la fecha de nacionalización. |
| **Costo real** | Lo que Comercio Exterior cargó como costo de nacionalización, en pesos. Incluye el flete internacional. |
| **Pago parcial** | Una parte de la nacionalización ya pagada, registrada acá. |
| **Saldo** | Costo menos pagos parciales. Es lo que se proyecta. Nunca es negativo. |
| **Fecha del saldo** | Cuándo se espera pagar lo que falta. Sin pagos parciales, es la de nacionalización. |
| **Pagado de más** | Los pagos parciales superan el costo. |
| **Dólar divisa vendedor** | La cotización divisa, punta vendedora. Se usa para lo que se nacionaliza en los próximos días o ya venció. |
| **Día hábil anterior** | El último día hábil antes de hoy: un lunes, el viernes. De ese día sale el dólar divisa. |
| **Curva ROFEX** | Los precios del dólar futuro, un contrato por mes. |
| **Fecha Est. EMB** | La fecha estimada de embarque. |
| **Orden de compra principal** | Cuando un contenedor tiene varias órdenes, la que lleva el costo, los pagos y el saldo. |
