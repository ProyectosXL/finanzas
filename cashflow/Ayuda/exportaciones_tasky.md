---
titulo: Exportaciones Tasky
pestana: exportaciones_tasky
categoria: Ingresos
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Comercio Exterior
bajada: Lo que falta cobrarle a Tasky en dólares, factura por factura, cuándo se estima cobrarlo y cuánto es en pesos al dólar de hoy.
---

## Para qué sirve

Muestra las facturas en dólares a Tasky, la razón social del grupo en Uruguay, que todavía no se cobraron, y cuándo se estima cobrarlas.

De cada factura toma lo que falta cobrar en dólares, le calcula una fecha de cobro estimada (la emisión más un plazo fijo) y lo pasa a pesos con el dólar de hoy, el mismo para todas.

Es una pestaña de consulta: no se carga ni se marca nada. Lo que se ve en sus columnas es lo que proyecta la fila Exportaciones Tasky del tablero. Ver [cómo se lee el tablero](ayuda:general_tablero).

## De dónde salen los datos

:::diagrama
fuente: Tango | las facturas a Tasky que siguen pendientes, con lo facturado en dólares
fuente: Tango, las imputaciones | lo ya cobrado o cancelado de cada factura, en dólares
fuente: El dólar oficial BCRA | la punta compradora de la última cotización cargada del mes en curso
fuente: Parámetros › Cobranzas | el plazo de cobro, en días
pestana: Exportaciones Tasky | resta lo cobrado, estima la fecha y pasa a pesos
tablero: Fila Exportaciones Tasky | lo que falta cobrar, en pesos al dólar de hoy
alt: Las facturas y las imputaciones de Tango, el dólar oficial BCRA y el plazo de Parámetros › Cobranzas alimentan la pestaña Exportaciones Tasky, que pasa al tablero lo que falta cobrar en pesos.
:::

- **Tango, las facturas**: las facturas a Tasky que Tango tiene como pendientes, con su fecha de emisión, lo facturado en dólares, la cotización con la que se facturó y el importe en pesos de ese momento. Sólo facturas: las notas de débito y de crédito no son filas.
- **Tango, las imputaciones**: lo que ya se cobró de cada factura con recibos, o se canceló con notas de crédito, en dólares. Se resta de lo facturado. Una nota de débito imputada a la factura suma deuda.
- **El dólar oficial BCRA**: la cotización oficial, punta compradora, de la última que está cargada en el mes en curso. Es una sola para todas las facturas.
- **Parámetros › Cobranzas**: el **Plazo de Cobro Exportaciones Tasky**, cuántos días después de la emisión se estima cobrar.

No hay filtro por antigüedad: entran todas las facturas pendientes, por viejas que sean.

## Cómo se calcula

### Lo que falta cobrar

A cada factura se le resta lo ya cobrado o cancelado en dólares. Lo que queda es el **Importe USD**, y es lo único que se proyecta.

Tango marca una factura como pendiente mirando los pesos, no los dólares. Si se facturó a un dólar y se cobró completa en dólares a otro, en pesos queda una diferencia de cambio y Tango la sigue mostrando pendiente. Acá esa factura **no aparece**: en dólares ya no se debe nada.

:::formula
Importe USD = facturado en dólares − cobrado o cancelado en dólares
Importe pesos hoy = Importe USD × dólar de hoy
Fecha cobro estimada = fecha de emisión + Plazo de Cobro Exportaciones Tasky (días corridos)
:::

### La fecha de cobro estimada

Es la fecha de emisión más el plazo, en días corridos. **No se corre al día hábil**: si cae un sábado, un domingo o un feriado, queda ese día. Esas columnas sólo se ven en gris.

Si la fecha estimada **ya pasó**, la factura es una **vencida sin cobrar**. Se ubica en la columna de hoy, el primer día del eje, y la columna **Fecha cobro estimada** dice **Vencida** con la fecha original. No se estima cobrarla hoy: está ahí para que siga a la vista. Una fecha estimada que es hoy no es vencida.

Las vencidas nunca se descartan por antigüedad. Más sobre cómo trata lo vencido cada módulo en [lo vencido](ayuda:general_real_proyectado#lo-vencido).

Una factura sin fecha de emisión no tiene fecha de cobro estimada y queda fuera de la grilla, con un aviso.

### Con qué dólar se valúa

Todas las facturas se valúan con **el mismo dólar**: el oficial BCRA, **punta compradora**, de la última cotización cargada del mes en curso. Una factura que se cobra dentro de tres meses lleva el mismo dólar que una que se cobra mañana.

Es a propósito. La deuda está fija en dólares y el cobro es futuro: valuarla a hoy es no suponer ninguna devaluación. La tarjeta **Dólar de hoy** muestra ese valor, pero no la fecha de la cotización.

**Si el mes en curso todavía no tiene ninguna cotización cargada** (puede pasar los primeros días de cada mes), no se usa la del mes anterior ni ninguna otra. La pantalla avisa en rojo, las columnas **Cotiz. hoy** e **Importe pesos hoy** van con un guión, la grilla y las tarjetas en pesos quedan en cero y la fila del tablero va en cero. Los dólares siguen a la vista. Cuando se carga la cotización del mes, se valúa solo.

En Proveedores Exterior el criterio es otro: cada pago se valúa con el dólar de su mes y la punta vendedora. Ver [con qué dólar se valúa](ayuda:proveedores_exterior#con-que-dolar-se-valua).

### Las columnas de facturación

**Cotiz. facturación** e **Importe pesos facturación** son de Tango, tal cual: con qué dólar se facturó y cuántos pesos dio **toda** la factura en ese momento. Son una referencia histórica y no entran en ninguna cuenta.

Por eso, en una factura con cobros parciales, **Importe pesos facturación** no es **Importe USD** × **Cotiz. facturación**: uno es la factura entera y el otro sólo el saldo.

:::ejemplo ilustrativo
Una factura de **USD 10.000**, emitida el 1 de octubre a un dólar de **$ 900**. Ya se cobró un recibo de **USD 4.000**. El plazo es de 30 días y el dólar de hoy es **$ 1.000**.

- **Importe USD**: 10.000 − 4.000 = **USD 6.000**.
- **Cotiz. facturación**: 900. **Importe pesos facturación**: 10.000 × 900 = **$ 9.000.000**, la factura entera. No se usa.
- **Importe pesos hoy**: 6.000 × 1.000 = **$ 6.000.000**.
- **Fecha cobro estimada**: 1 de octubre + 30 días = **31 de octubre**.

Si hoy es 20 de noviembre, esa fecha ya pasó: la factura va en la columna de hoy, en ámbar, con **Vencida 31/10**.

Otra factura de **USD 2.000**, emitida el 10 de noviembre, se estima cobrar el **10 de diciembre**. Va en esa columna por 2.000 × 1.000 = **$ 2.000.000**. Entre las dos, la tarjeta **Pendiente en USD** dice **USD 8.000** y la pestaña proyecta **$ 8.000.000**.
:::

## La pantalla

La pantalla, de arriba hacia abajo:

- Los avisos de la pestaña, con los que el tablero dice de ella.
- Las cinco tarjetas.
- El título, el buscador, los botones de las tres vistas, el selector de columnas fijas, **Actualizar** y **Exportar**.
- La línea que dice qué período se está mirando.
- La tabla, una fila por factura, con los totales de cada fecha arriba y el pie **TOTALES**.

### Las tarjetas

| Tarjeta | Qué muestra |
| --- | --- |
| **Pendiente en USD** | La suma de **Importe USD** de todas las facturas. Debajo, cuántas facturas son y cuántas están vencidas. |
| **Dólar de hoy** | El dólar con el que se valúa todo. Sin cotización del mes, dice **Sin cotización** en rojo. |
| **Vista Días** | Lo que cae en el tramo diario, en pesos de hoy. Debajo, de qué día a qué día. |
| **Vista Meses** | Lo que cae en las columnas mensuales, después del tramo diario. |
| **Período Completo** | Todo el horizonte. |

Las tarjetas miden todas las facturas: no cambian con el buscador. Las tres en pesos suman lo que entra en las columnas, así que no incluyen lo que queda fuera del horizonte o sin fecha.

### El encabezado

El subtítulo dice el plazo con el que se está calculando: "Cobro estimado a emisión + N días, valuadas al dólar de hoy".

Los botones **Días**, **Meses** y **Período completo** cambian las columnas del eje, como en todo el módulo: ver [las tres vistas](ayuda:general_tablero#las-tres-vistas). La pestaña abre en **Días**.

El selector de columnas fijas elige qué columnas quedan quietas al moverte a lo ancho; arrancan **N_COMP** y **RAZON_SOCI**.

**Actualizar** vuelve a leer Tango y el dólar. **Exportar** baja a Excel las filas que se están viendo.

### Las columnas

| Columna | Qué muestra |
| --- | --- |
| **FECHA_EMIS** | La fecha de emisión de la factura. |
| **N_COMP** | El número de comprobante. |
| **COD_CLIENT** | El código del cliente en Tango. |
| **RAZON_SOCI** | La razón social. Si es larga, completa al pasar el mouse. |
| **Importe USD** | Lo que falta cobrar en dólares: lo facturado menos lo cobrado o cancelado. No es el total de la factura. |
| **Cotiz. facturación** | El dólar con el que se facturó. Atenuada: es referencia. |
| **Importe pesos facturación** | Los pesos de toda la factura al facturar. Atenuada: es referencia. |
| **Cotiz. hoy** | El dólar de hoy, el mismo en todas las filas. Un guión si no hay cotización. |
| **Importe pesos hoy** | **Importe USD** × **Cotiz. hoy**. Es lo que va a la grilla y al tablero. Un guión si no hay cotización. |
| **Fecha cobro estimada** | La fecha estimada; al pasar el mouse, "Emisión + N días". Si venció, **Vencida** con la fecha original. Un guión si no hay fecha de emisión. |

Después van las columnas del eje, con el importe en pesos de hoy en la fecha de cobro estimada, y **Total**. Los sábados, domingos y feriados van en gris.

Una factura vencida va con toda la fila en ámbar, y su marca **Vencida** lleva un ícono de alerta. Al pasar el mouse dice la fecha original y que se ubica en el primer día del eje.

La tabla abre ordenada por **Fecha cobro estimada**, de la más cercana a la más lejana; las vencidas, que están en hoy, quedan primero, y las que no tienen fecha, al final. Se ordena por otra columna haciendo clic en su encabezado, y ese orden queda para la próxima vez.

Si no hay facturas pendientes, la tabla dice "No hay facturas pendientes a Tasky."

### Los totales y el buscador

El pie **TOTALES** suma **Importe USD**, **Importe pesos hoy** y cada columna del eje. Arriba de cada fecha va el mismo total. Sin cotización, el total de **Importe pesos hoy** es un guión.

El buscador hace dos cosas distintas:

- **Esconde las filas** que no tienen el texto en ninguna de sus celdas: comprobante, código, razón social, pero también fechas, importes o la palabra "Vencida". No distingue mayúsculas, pero sí acentos.
- **El pie y los totales de arriba suman** sólo las facturas cuyo comprobante, código de cliente o razón social tienen ese texto.

Si buscás una razón social o un comprobante completo, las dos cosas coinciden. Si buscás una fecha, un importe o unos pocos números (que también pueden estar en una fecha o un importe), las filas que se ven pueden no ser las que suma el pie.

Al cambiar de vista o tocar **Actualizar** con texto en el buscador, la tabla vuelve a mostrar todas las filas, pero el pie sigue sumando según el texto. Para que vuelvan a coincidir, borrá el buscador o volvé a escribir.

### Sin permiso de edición

La pestaña no tiene nada para editar: se ve igual con o sin permiso. El plazo se cambia en Parámetros, con el permiso de esa sección. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Tareas frecuentes

### Ver cuánto falta cobrar y cuándo

:::pasos
1. Mirá la tarjeta **Pendiente en USD**: es todo lo que falta cobrar, en dólares, y cuántas facturas son.
2. Elegí la vista **Meses** o **Período completo** para ver en qué mes cae cada cobro.
3. Mirá las tarjetas en pesos o el pie **TOTALES** para el importe al dólar de hoy.
Resultado: sabés cuánto se espera cobrar en cada período, valuado con un solo dólar.
:::

### Revisar las facturas vencidas

:::pasos
1. Mirá el aviso de facturas vencidas y el pie de la tarjeta **Pendiente en USD**: dicen cuántas son.
2. Buscá las filas en ámbar: quedan arriba, porque están ubicadas hoy.
3. Pasá el mouse por **Vencida** para ver la fecha de cobro estimada original.
Resultado: sabés qué facturas ya deberían haberse cobrado. Hay que gestionar el cobro o, si se cobró, revisar que el recibo esté imputado a la factura en Tango.
:::

### Cambiar el plazo de cobro

:::pasos
1. Andá a **Parámetros › Cobranzas**, tarjeta **Plazos de Cobro**.
2. En **Plazo de Cobro Exportaciones Tasky**, escribí los días (un número entero mayor que cero).
3. Salí del campo: se guarda solo y la tarjeta parpadea en verde.
4. Volvé a la pestaña y tocá **Actualizar**.
Resultado: todas las fechas de cobro estimadas se recalculan con el plazo nuevo, y cambian cuáles quedan vencidas. El subtítulo de la pestaña dice el plazo nuevo y la fila del tablero se mueve con él.
:::

### Buscar una factura

:::pasos
1. Escribí en el buscador parte del comprobante o de la razón social.
Resultado: quedan sólo las facturas que coinciden, y el pie suma sólo ésas. Las tarjetas no cambian.
:::

### Exportar lo que se ve

:::pasos
1. Dejá la tabla como la querés: vista, buscador y orden.
2. Tocá **Exportar**.
Resultado: se baja un Excel con las filas que se están viendo.
:::

## Parámetros

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Plazo de Cobro Exportaciones Tasky** | Parámetros › Cobranzas, tarjeta **Plazos de Cobro** | 30 días | Los días que se suman a la emisión para la fecha de cobro estimada, en días corridos. Cambia cuáles quedan vencidas y mueve el tablero. Si no está cargado, o lo guardado no es un número mayor que cero, se usa 30. |
| Horizonte de proyección | Parámetros › Calendario | El del módulo | Cuántas columnas diarias y mensuales hay. Lo que cae después queda fuera, con un aviso. |
| Feriados | Parámetros › Calendario | Los del calendario | Sólo qué columnas van en gris. No corren la fecha de cobro. |

No hay ningún parámetro del dólar: ni la punta, ni la fecha, ni una cotización a mano.

Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Avisos y qué hacer

Los avisos se ven arriba de la tabla, con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: N factura(s) por USD X tiene(n) la fecha de cobro estimada ya vencida: se ubica(n) en el primer día del eje. Es una factura vencida / Son facturas vencidas sin cobrar, no cobranza estimada para hoy.
significa: Facturas cuya fecha de emisión más el plazo ya pasó y siguen sin cobrar. Están en la columna de hoy, pero no se estima cobrarlas hoy. En el tablero, la celda de hoy lo anota. Sin cotización del mes, el tablero no lo repite: ya dice que la fila va en cero.
corrige: Gestioná el cobro. Si ya se cobró, revisá que el recibo esté imputado a la factura en Tango. Si el plazo no refleja cómo paga Tasky, cambialo en Parámetros › Cobranzas.
:::

:::aviso info
texto: Exportaciones Tasky: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Facturas cuya fecha de cobro estimada cae después del último mes del horizonte. Existen, pero no entran en ninguna columna.
corrige: Si las querés ver, agrandá el horizonte en Parámetros › Calendario.
:::

:::aviso atencion
texto: Exportaciones Tasky: $ X sin fecha asignada no se pueden ubicar en el tablero.
significa: Facturas sin fecha de emisión: no hay de dónde estimar el cobro.
corrige: Revisá la factura en Tango.
:::

Si el tablero no responde, esos dos últimos salen con el texto de la tabla: "$ X con fecha fuera del horizonte no se muestran en la tabla." y "$ X sin fecha asignada no se pueden ubicar en el tiempo y quedan fuera de la tabla."

### Los mensajes de la pantalla

No son avisos: aparecen un momento como notificación.

| Mensaje | Cuándo |
| --- | --- |
| "Error al cargar datos: …" | La pestaña no pudo leer las facturas. Después de los dos puntos va el motivo. |
| "Error de conexión: …" | No hubo respuesta del servidor. |
| "No hay datos para mostrar" | El servidor respondió sin la lista de facturas. |
| "El plazo tiene que ser un número entero mayor a 0." | En Parámetros › Cobranzas, se escribió un plazo vacío, cero o negativo. No se guarda. |
| "No se pudo guardar el parámetro: …" | En Parámetros › Cobranzas, falló al guardar el plazo. |
| "Error de conexión al guardar el parámetro: …" | En Parámetros › Cobranzas, no hubo respuesta al guardar el plazo. |

Cuando el plazo se guarda bien no hay mensaje: la tarjeta parpadea en verde.

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No hay cotización del dólar oficial BCRA para el mes en curso (RO_V_DOLAR_OFICIAL_BCRA): los USD X pendientes no se pueden valuar, así que la columna en pesos queda vacía y no entran a la grilla ni al tablero. No se asume ningún tipo de cambio.
significa: El mes en curso no tiene ninguna cotización cargada, o no se pudo leer. **Importe pesos hoy** va con un guión, la grilla y las tarjetas en pesos quedan en cero y la fila del tablero va en cero. Los dólares están. Puede pasar los primeros días de cada mes, hasta que se carga la primera cotización.
corrige: Avisale a sistemas que falta la cotización del dólar oficial BCRA del mes. Cuando se carga, tocá **Actualizar**.
:::

:::aviso critico
texto: No se pudo calcular (…). Sus filas se muestran en cero.
significa: El tablero no pudo leer las facturas a Tasky. Entre paréntesis va el motivo, por ejemplo "No se pudo conectar a la base de datos central" o "Error al consultar facturas pendientes de exportacion: …". La fila Exportaciones Tasky va en cero.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: El codigo de proveedor "…" no tiene serie definida.
significa: La fila del tablero está mal configurada y va en cero.
corrige: Sistemas, revisando la configuración de la fila.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Por qué el Importe USD es menor que lo que dice la factura?
Porque es lo que falta cobrar, no lo facturado. Se restan los recibos y las notas de crédito ya imputados a esa factura en Tango, en dólares. Si a la factura le imputaron una nota de débito, suma.
:::

:::pregunta ¿Por qué no aparece una factura que en Tango sigue pendiente?
Porque ya se cobró completa en dólares. Si se facturó a un dólar y se cobró a otro, en pesos queda una diferencia de cambio y Tango la deja pendiente por esa diferencia. En dólares no queda nada por cobrar, así que acá no se proyecta.
:::

:::pregunta ¿Por qué Importe pesos facturación no es Importe USD × Cotiz. facturación?
Porque **Importe pesos facturación** es lo que dio en pesos la factura entera cuando se emitió, y **Importe USD** es sólo el saldo. Si la factura tiene cobros parciales, no coinciden. Las dos columnas de facturación son referencia y no se usan para calcular.
:::

:::pregunta ¿Por qué todas las facturas tienen el mismo dólar?
Porque la deuda está fija en dólares y el cobro es futuro. Valuar cada factura con un dólar de su mes de cobro sería suponer una devaluación. Con el dólar de hoy no se supone ninguna.
:::

:::pregunta ¿Qué dólar es exactamente?
El oficial BCRA, punta compradora, de la última cotización cargada en el mes en curso. Normalmente es la del último día hábil, pero la tarjeta no muestra la fecha. Si el mes todavía no tiene ninguna, no se usa la del mes anterior: la pantalla avisa y los pesos quedan vacíos.
:::

:::pregunta ¿Por qué el día 1 la pestaña puede quedar sin pesos?
Porque el dólar se toma del mes en curso. Hasta que se carga la primera cotización del mes, no hay con qué valuar: aparece el aviso crítico, **Importe pesos hoy** va con un guión y la fila del tablero va en cero. Cuando la cotización se carga, alcanza con tocar **Actualizar**.
:::

:::pregunta ¿Por qué una factura vencida aparece en la columna de hoy?
Porque es una factura que ya debería haberse cobrado y sigue pendiente. Se pone en el primer día del eje para que no se pierda de vista, no porque se estime cobrarla hoy. La fila va en ámbar, el aviso dice cuántas son y, en el tablero, la celda de hoy anota cuánto de ese importe es vencido.
:::

:::pregunta ¿La fecha de cobro se corre si cae en fin de semana?
No. Es la emisión más el plazo, en días corridos. Si cae un sábado, un domingo o un feriado, queda en ese día, con la columna en gris.
:::

:::pregunta ¿Por qué el pie no coincide con las filas que veo?
Porque el buscador esconde las filas por el texto de cualquier celda, y el pie suma sólo las facturas cuyo comprobante, código o razón social tienen ese texto. Si buscaste una fecha o un importe, pueden no coincidir. También pasa si cambiaste de vista o tocaste **Actualizar** con texto en el buscador: las filas vuelven todas, pero el pie sigue filtrando. Borrá el buscador y vuelven a coincidir.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí: el tablero proyecta las mismas facturas, en las mismas fechas y con el mismo dólar. Puede haber diferencias de centavos, porque la pestaña redondea factura por factura y el tablero convierte la suma de cada fecha. La fila es sólo proyectada: no tiene parte real. Más en [real y proyectado](ayuda:general_real_proyectado).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| **Tasky** | La razón social del grupo en Uruguay. Se le factura en dólares. |
| **Importe USD** | Lo que falta cobrar de una factura en dólares: lo facturado menos lo cobrado o cancelado. |
| **Dólar de hoy** | El dólar oficial BCRA, punta compradora, de la última cotización cargada del mes en curso. Uno solo para todas las facturas. |
| **Punta compradora** | Lo que el banco paga por un dólar. |
| **Cotiz. facturación** | El dólar con el que se emitió la factura. Sólo referencia. |
| **Importe pesos facturación** | Los pesos de toda la factura al emitirla. Sólo referencia. |
| **Importe pesos hoy** | Importe USD por el dólar de hoy. Es lo que va a la grilla y al tablero. |
| **Fecha cobro estimada** | La fecha de emisión más el plazo de cobro, en días corridos. |
| **Plazo de Cobro Exportaciones Tasky** | Los días que se suman a la emisión. Se cambia en Parámetros › Cobranzas. |
| **Vencida** | Una factura cuya fecha de cobro estimada ya pasó y sigue sin cobrar. Se ubica en hoy, con la fecha original en la marca. |
| **Primer día del eje** | Hoy: la primera columna diaria. |
| **Diferencia de cambio** | Los pesos que quedan entre facturar a un dólar y cobrar a otro. Tango la deja pendiente; acá no se proyecta. |
| **Horizonte** | El período que proyecta el cashflow: el tramo diario más los meses. |
