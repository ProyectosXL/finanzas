---
titulo: Llaves y Renov. Contratos
pestana: llaves_renov
categoria: RRHH y Operativos
estado: en_revision
actualizado: 2026-11-23
usuarios: Tesorería, Administración
bajada: Cuánto se paga de llave y otros gastos en cada renovación de contrato, de obras de imagen, de aperturas y de lo pactado a mano, y en qué fecha.
---

## Para qué sirve

Muestra cuánto se va a pagar, y cuándo, de la llave y los otros gastos de cada renovación de contrato de alquiler, de las obras de renovación de imagen, de las aperturas de locales nuevos y de los compromisos pactados a mano.

Si el contrato nuevo ya está cargado en la app de alquileres, usa sus importes. Si todavía no está, estima la renovación con un porcentaje del alquiler.

Reemplaza las hojas «LLves y Renov Contratos» y «Detalles de vencimientos» del Excel de Flujo de Fondos de Tesorería. Lo que muestra entra al tablero en la fila Llaves y Renovaciones.

## De dónde salen los datos

:::diagrama
fuente: La app de alquileres | los contratos de cada local: vigencia, llave, comisiones y FPC (Fondo de Promoción Colectiva)
fuente: La pestaña Alquileres | el alquiler de cada mes (alquiler, complementario y VMA), con el que se estima la llave
fuente: Parámetros › Alquileres | el operador (la razón social principal) y el % de retención de cada local
fuente: El gestor de locales | los m² de cada local, para el segmento de la obra
fuente: Tango | las facturas de llave, para sugerir el % de impuestos de cada operador
fuente: La curva del dólar futuro | el dólar de cada mes, para obras y aperturas
fuente: Parámetros › Llaves y Renovaciones | porcentajes, cuotas, obras, aperturas, compromisos y ya pagadas
fuente: Alquileres › Facturas | las facturas marcadas como llave o renovación
pestana: Llaves y Renov. Contratos | calcula cuánto se paga y en qué fecha
tablero: Fila Llaves y Renovaciones | lo real (las facturas) y lo proyectado (el resto)
alt: La app de alquileres, la pestaña Alquileres, Parámetros › Alquileres, el gestor de locales, Tango, el dólar futuro, Parámetros › Llaves y Renovaciones y Alquileres › Facturas alimentan la pestaña Llaves y Renov. Contratos, que pasa sus pagos a la fila Llaves y Renovaciones del tablero.
:::

- **La app de alquileres**: cada contrato de cada local, con su vigencia, la llave, las comisiones y el FPC de lanzamiento. El cashflow sólo la lee: no escribe nada en ella.
- **La pestaña Alquileres**: el alquiler mensual de cada local (el alquiler, el complementario y el valor mínimo mensual —VMA—, netos), tal como ella lo proyecta. Con ese importe se estima la llave. Acá no se recalcula ni se ajusta.
- **Parámetros › Alquileres**: el operador de cada local, que es su razón social principal, y el % de retención del local.
- **El gestor de locales**: los m² totales de cada local (venta, depósito y baulera). Dicen si el local es chico, mediano o grande para la obra de imagen. También se lee sin escribir.
- **Tango**: las facturas de llave de los últimos 24 meses. Sólo sirven para sugerir el % de impuestos de cada operador en Parámetros.
- **La curva del dólar futuro**: pasa a pesos el costo en dólares de las obras y las aperturas.
- **Parámetros › Llaves y Renovaciones**: lo que la app no tiene. Porcentajes, cuotas, segmentos, costos en dólares, datos por operador y por local, renovaciones ya pagadas, obras, aperturas y compromisos a mano.
- **Alquileres › Facturas**: las facturas que alguien marcó con **Es llave / renovación**. Son lo real, y reemplazan lo proyectado.

Sólo se tienen en cuenta los locales habilitados: un local cerrado no renueva.

## Cómo se calcula

Cada renovación se identifica por el contrato que vence. Por eso lo que se carga sobre ella (ya pagada, una factura marcada) sigue valiendo cuando administración carga el contrato nuevo en la app.

### La renovación cargada en la app

Si el contrato nuevo ya está en la app de alquileres, se usa su llave tal cual. Se paga en cuotas mensuales iguales y seguidas. La primera cuota va el día de inicio del contrato, movida por el corrimiento del operador si tiene uno.

Las comisiones y el FPC del contrato van juntos, en un solo pago, el día de la primera cuota (la firma).

Vale para cualquier contrato con cuotas por delante, también el vigente si empezó hace poco.

Si la app tiene una llave de 1, no es un importe: es una marca que quiere decir «tiene llave, monto desconocido». Esa llave se estima, con los meses de ese contrato y el % del operador o el general. Nunca se pagan $ 1.

### Los parámetros por local, cuando se carga el contrato nuevo

Lo que se cargó para un local en Parámetros vale para su renovación. Cuando administración carga el contrato nuevo en la app:

- **Deja de valer lo que dice cuánto**: **No renueva**, **Llave a mano ($)**, **% propio** y **Meses**. Manda el importe del contrato.
- **Sigue valiendo lo que dice cómo se paga**: **Cuotas** y **1.ª cuota**, también sobre el contrato cargado.

En la sección **Por local** la renovación cargada sigue en la tabla, con **renueva con el #…** debajo del contrato. Ahí se pueden cambiar **Cuotas** y **1.ª cuota**; las otras columnas se ven deshabilitadas, y al pasar el mouse dicen que manda el contrato.

### La renovación estimada

Si el contrato vigente vence dentro del horizonte, o ya venció, y la renovación no está cargada, se estima:

:::formula
llave estimada = % de llave × alquiler del mes de la primera cuota (alquiler + complementario + VMA, como lo proyecta Alquileres) × meses
otros gastos estimados = % de otros gastos × llave estimada + escribano
a pagar de cada pago = neto + neto × % de impuestos del operador − neto × % de retención del local
:::

- **El % de llave** es el del local; si no tiene, el del operador; si no, el general.
- **Los meses** son los cargados para el local; si no hay, los del contrato de llave.
- **Las cuotas** son las del local; si no hay, las del operador; si no, las generales.
- **La primera cuota** va en el mes del día siguiente al fin del contrato vigente, más el corrimiento del operador, salvo que el local tenga cargado otro mes. Las demás van el mismo día de los meses siguientes.
- **Si el contrato ya venció** sin renovación cargada, la primera cuota va al primer día hábil después de hoy, y la pantalla lo avisa.

Sólo se estima llave si el contrato de llave tiene llave (cualquier importe mayor que cero, también la marca de 1). Es el mismo criterio que usa Costo de Ocupación. El contrato de llave es el vigente o, si el vigente es un contrato puente, el último anterior que no lo sea. Un contrato largo sin llave corta: ese local no estima llave, aunque sí sus otros gastos.

Si la pestaña Alquileres no tiene el alquiler de ese mes para el local, esa llave no se estima y se avisa.

Una llave cargada a mano en Parámetros reemplaza la cuenta.

### Los otros gastos

En una renovación estimada, los otros gastos son el % de otros gastos del operador sobre la llave, más el escribano y otros. En una cargada, son las comisiones más el FPC del contrato.

Van en un solo pago, el día de la primera cuota. El escribano es un importe final: no lleva impuestos ni retención.

### Impuestos y retención

A la llave y a los otros gastos se les suma el % de impuestos del operador (lo que factura de más sobre el contrato) y se les resta el % de retención del local, los dos sobre el neto. Se calcula cuota por cuota, con la misma regla de redondeo a centavos que Alquileres.

Las cuotas se reparten en partes iguales al centavo: siempre suman exacto el total.

Sin % de impuestos del operador, o sin % de retención del local, la llave y los otros gastos de ese local no se proyectan. Un aviso dice cuánto neto queda afuera. **El escribano sí se proyecta**, porque no lleva impuestos ni retención.

:::ejemplo ilustrativo
Un local cuyo contrato vence el 31 de marzo y no tiene la renovación cargada. La renovación empieza el 1 de abril. El operador no tiene corrimiento.

- Alquiler de abril (alquiler, complementario y VMA, como lo proyecta Alquileres): **$ 8.000.000**.
- % de llave: 25 % (el general). Meses del contrato: 6. Cuotas: 4.
- Llave estimada: 25 % × $ 8.000.000 × 6 = **$ 12.000.000**, en 4 cuotas de $ 3.000.000.
- % de otros gastos del operador: 40 %. Otros gastos: 40 % × $ 12.000.000 = $ 4.800.000, más $ 300.000 de escribano.
- % de impuestos del operador: 20 %. % de retención del local: 2 %.

Cada cuota va el día 1 de su mes. Si ese día no es hábil, pasa al siguiente día hábil.

| Pago | Mes | Neto | Impuestos (20 %) | Retención (2 %) | Escribano | A pagar |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Llave, cuota 1 de 4 | Abril | $ 3.000.000 | $ 600.000 | $ 60.000 | — | $ 3.540.000 |
| Llave, cuota 2 de 4 | Mayo | $ 3.000.000 | $ 600.000 | $ 60.000 | — | $ 3.540.000 |
| Llave, cuota 3 de 4 | Junio | $ 3.000.000 | $ 600.000 | $ 60.000 | — | $ 3.540.000 |
| Llave, cuota 4 de 4 | Julio | $ 3.000.000 | $ 600.000 | $ 60.000 | — | $ 3.540.000 |
| Otros gastos, en la firma | Abril | $ 4.800.000 | $ 960.000 | $ 96.000 | $ 300.000 | $ 5.964.000 |
| **Total** | | $ 16.800.000 | $ 3.360.000 | $ 336.000 | $ 300.000 | **$ 20.124.000** |

En abril salen $ 3.540.000 + $ 5.964.000 = $ 9.504.000. En mayo, junio y julio, $ 3.540.000 cada mes.
:::

### Las obras y las aperturas

Se cargan a mano en Parámetros, con un mes estimado. Se pagan el día de pago general de ese mes (15, si no se cambió), corrido al hábil siguiente.

El importe es el costo en dólares por el dólar futuro de esa fecha. El costo es el propio de la obra o la apertura, si tiene; si no, el del segmento.

El segmento de una obra es el cargado a mano o, si no, el que dan los m² del local: hasta el corte de chico es chico, hasta el de mediano es mediano, y más es grande. Un local que cae justo en un corte es del segmento de abajo. Una apertura lleva el segmento siempre a mano.

Si la curva no llega al mes del pago, se usa el mes más cercano y el tooltip lo dice como aproximado.

### Los compromisos a mano

Lo pactado que no está en la app (prendas, sellados, servicio comercial, cuotas acordadas) se proyecta en su fecha y por su monto, tal cual.

### Las facturas marcadas como llave

Una factura marcada con **Es llave / renovación** en Alquileres › Facturas es lo real. Reemplaza todo lo proyectado de su local y su concepto en esa renovación. Una factura de obra reemplaza la obra de ese local en ese mes.

Se paga en los pagos que se le cargaron o, si no tiene, entera en su vencimiento a considerar. Un pago con fecha vencida pasa al siguiente día hábil desde hoy. Si la factura ya está pagada en Tango, no suma, pero sigue cortando lo proyectado.

### Lo que corta lo proyectado

- **No renueva**: no se estima nada de esa renovación.
- **Ya pagada**: el concepto marcado (la llave, los otros gastos o los dos) deja de proyectarse en esa renovación, cargada o estimada.
- **Una factura marcada como llave**: reemplaza lo proyectado de ese concepto en esa renovación.
- **Las fechas de hoy o anteriores**: lo proyectado con fecha de hoy o anterior no se muestra. Si una cuota de un contrato firmado ya pasó, en el mes en curso o el anterior, y no tiene factura ni marca de pagada, la pantalla lo avisa.

## La pantalla

<!-- Acá va la captura: img/llaves_renov/pantalla.png con las marcas A, B, … dibujadas -->

:::marcas
A: El enlace a Parámetros › Llaves y Renovaciones y el botón **Actualizar**.
B: Los avisos.
C: Las cuatro tarjetas.
D: Las solapas **Proyección**, **Contratos** y **Facturas**.
E: El buscador y los botones de las tres vistas del eje.
F: Los botones **Abrir todo** y **Exportar**.
G: El período que se mide y la leyenda de los chips de origen.
H: La grilla.
:::

### Las tarjetas

- **Total del horizonte**: todo lo que se paga en el horizonte (llaves, renovaciones, obras, aperturas y compromisos).
- **Renovaciones en el horizonte**: cuántas renovaciones tienen pagos de llave u otros gastos en el horizonte, facturas incluidas. Abajo, cuánto suman esos mismos pagos. Una factura cuenta en la renovación a la que se imputó: no suma una renovación aparte.
- **Estimado**: cuánto de lo que se paga es estimado. Abajo, contra cuánto está en un contrato o una factura y cuánto se cargó a mano.
- **Locales sin dato**: cuántos locales no proyectan algo porque falta un dato. Pasá el mouse por el pie para ver cuáles.

### La solapa Proyección

La grilla se abre por operador; cada operador en sus locales; y cada local en **Llave**, **Otros gastos** y **Obra de imagen**. Aparte van **Aperturas** y **Compromisos a mano**. Tocá la flecha de una fila para abrirla.

- **Las tres vistas**: **Días**, **Meses** y **Período completo**. La pestaña abre en Período completo.
- **Los totales**: el total de cada columna va arriba de su fecha y también al pie, en **TOTALES**.
- **Lo estimado va en itálica y atenuado.** Lo que está en un contrato, una factura o se cargó a mano va en letra normal.
- **El chip de origen**: cada importe lleva un chip que dice de dónde sale: **Contrato cargado**, **Estimado**, **Manual** o **Factura**. Si una celda junta pagos de distinto origen, el chip dice **mixto**.
- **El tooltip**: pasá el mouse por un importe y vas a ver la cuenta completa (el %, el alquiler, los meses, la cuota, los impuestos y la retención, y si la fecha se corrió por no ser hábil) y su origen.
- **El buscador** filtra por operador o local.
- **Abrir todo** abre o cierra todas las filas.
- **Exportar** baja la grilla a Excel con todas las filas abiertas.
- La primera columna queda fija al moverte a lo ancho.

### La solapa Contratos

Es lo que era la hoja «Detalles de vencimientos»: una fila por local con el contrato vigente.

Las columnas son **Local**, **Operador**, **Estado**, **Desde**, **Hasta**, **Meses**, **Llave**, **Comisiones**, **FPC**, **Renovación** (si la renovación está cargada en la app y desde cuándo), **m²** y **Segmento**. Debajo del nombre del local van las notas de lo que corta lo proyectado: no renueva, ya pagada, la factura que la reemplaza o que el contrato no tiene llave.

Una llave de 1 se ve como «1» con un asterisco: tiene llave, pero el monto no se conoce y se estima.

Los estados, que se filtran con el desplegable **Todos los estados**:

- **Vigente**: el contrato vence después del horizonte.
- **Vence en el horizonte**: se estima su renovación hasta que se cargue el contrato nuevo.
- **Vencido sin renovar**: venció y no hay contrato nuevo en la app. Si su contrato de llave tiene llave, se estima con la primera cuota después de hoy.
- **Renovado**: el contrato nuevo ya está cargado en la app.
- **No renueva**: así se marcó en Parámetros; no se estima nada.
- **Sin contrato en la app**: el local no tiene ningún contrato cargado.
- **Temporal (proyecta según vigencia)**: un local temporal; no se estima su renovación.

Con permiso de edición de la pestaña, cada fila con una renovación marcable tiene el botón **Ya pagada**. **Exportar** baja lo que se está viendo.

### La solapa Facturas

Las facturas marcadas como llave o renovación en Alquileres › Facturas, con **Comprobante**, **Local**, **Concepto**, **Mes**, **Estado** (**Pendiente** o **Pagada: no suma**), **A pagar en el horizonte** y **Pagos** (la fecha y el importe de cada uno). Se marcan y se deshacen en Alquileres › Facturas, no acá.

## Tareas frecuentes

### Marcar «Ya pagada» una renovación desde Contratos

:::pasos
1. Abrí la solapa **Contratos** y buscá el local.
2. Tocá **Ya pagada** en su fila.
3. Elegí la **Renovación**.
4. En **Qué se pagó** vienen tildados **Llave** y **Otros gastos**. Escribí el monto de cada uno, o destildá el que no se pagó.
5. Cargá la **Fecha del pago** (hoy o antes) y, si querés, el **Comprobante** y una observación.
6. Tocá **Marcar**.
Resultado: lo marcado deja de proyectarse en esa renovación y aparece como nota en la fila del local. La marca sigue valiendo cuando se cargue el contrato nuevo.
:::

### Marcar «Ya pagada» una renovación desde Parámetros

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones** (el enlace de arriba de la pestaña te lleva).
2. En la sección **Ya pagadas**, elegí la **Renovación**.
3. Cargá la **Fecha del pago** y el monto de **Llave ($)** y de **Otros gastos ($)**, o destildá el concepto que no se pagó.
4. Si querés, cargá el **Comprobante** y una observación.
5. Tocá **Marcar**.
Resultado: la marca aparece en la tabla de abajo y ese concepto deja de proyectarse en esa renovación. Para deshacerla, tocá el tacho de su fila: lo proyectado vuelve.
:::

### Marcar una factura como llave en Alquileres › Facturas

:::pasos
1. En la pestaña **Alquileres**, abrí la solapa **Facturas**.
2. Tocá **Es llave** en la fila de la factura, o tildá varias y tocá **Es llave / renovación**.
3. Elegí el **Local**, el **Concepto** (**Llave**, **Otros gastos** u **Obra**) y el **Mes al que se imputa**.
4. Si es una sola factura pendiente que se paga en partes, tocá **Repartir en las cuotas del operador** o agregá cada pago con **Pago**. Los pagos tienen que sumar el pendiente menos la **Retención**.
5. Tocá **Previsualizar** y leé qué renovación reemplaza.
6. Tocá **Marcar**.
Resultado: la factura sale de Alquileres, aparece en la solapa **Facturas** de esta pestaña y reemplaza lo proyectado de ese local y concepto en esa renovación.
:::

La llave y los otros gastos se imputan a la renovación del local que empieza más cerca del mes elegido. Una factura imputada en Alquileres o excluida no se puede marcar: primero hay que deshacer la imputación o volver a incluirla. Para ver las pagadas de meses anteriores y marcarlas (no suman, sólo cortan lo proyectado), prendé **Ver pagadas de meses anteriores**. Las marcadas se ven con **Ver excluidas**, y se deshacen con **Deshacer**.

### Cargar el % de impuestos de un operador

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones**, sección **Por operador**.
2. Buscá el operador. En gris está el % sugerido; pasá el mouse para ver de qué facturas sale.
3. Escribí el valor en **% impuestos** (aunque sea el sugerido; si no factura impuestos, escribí `0`).
4. Salí del campo: se guarda solo.
Resultado: la llave y los otros gastos de los locales de ese operador se proyectan, y el aviso de ese operador desaparece.
:::

### Marcar que un local no renueva

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones**, sección **Por local**.
2. Buscá el local (están los que vencen en el horizonte o ya vencieron; los que ya tienen la renovación cargada tienen esta columna deshabilitada).
3. Escribí el motivo en la columna **No renueva** y tildá la casilla.
4. Salí del campo: se guarda con el motivo.
Resultado: el local pasa a **No renueva** en la solapa Contratos y no se estima nada de esa renovación.
:::

### Cargar una llave a mano

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones**, sección **Por local**.
2. Buscá el local.
3. Escribí el importe neto en **Llave a mano ($)**. Si hace falta, cambiá también **Cuotas** o **1.ª cuota**.
4. Salí del campo: se guarda solo.
Resultado: la llave de esa renovación sale de ese importe, con el chip **Manual**, en las cuotas que correspondan. Los otros gastos estimados se calculan sobre ella.
:::

### Cargar un compromiso a mano

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones**, sección **Compromisos a mano**.
2. Elegí el **Operador** de la lista (una razón social de Alquileres o un proveedor del maestro).
3. Si corresponde, elegí el **Local**.
4. Cargá el **Concepto**, la **Fecha** y el **Monto ($)**.
5. Si son varias cuotas mensuales del mismo importe, poné cuántas en **Cuotas**.
6. Si querés, elegí la **Forma de pago** y escribí una observación.
7. Tocá **Agregar**.
Resultado: el compromiso aparece en el grupo **Compromisos a mano** de la Proyección, con el chip **Manual**. En serie, cada cuota va el mismo día de cada mes (o el último día, si ese mes no lo tiene).
:::

### Cargar una obra de imagen

:::pasos
1. Andá a **Parámetros › Llaves y Renovaciones**, sección **Obras de renovación de imagen**.
2. Elegí el **Local** y el **Mes estimado**.
3. Dejá el **Segmento** en «Por sus m²» o elegí uno.
4. Si la obra tiene un costo distinto del de su segmento, cargalo en **Costo propio (U$S)**.
5. Tocá el botón con el signo más.
Resultado: la obra aparece en la fila **Obra de imagen** del local, en pesos al dólar futuro de su mes.
:::

## Parámetros

Se cambian en **Parámetros › Llaves y Renovaciones**. Los datos generales, por operador y por local se guardan al salir del campo. Las ya pagadas, obras, aperturas y compromisos se cargan con su formulario y se dan de baja; no se editan. Todo queda en el historial. Para quién puede editar, mirá [permisos y parámetros](ayuda:general_permisos_parametros).

Un campo vacío no es cero. Vacío en el % de llave o en las cuotas es «el de arriba»; vacío en el escribano o en el % de impuestos es «sin dato» y avisa. Sólo un `0` cargado es cero.

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| % de llave sobre el alquiler | Generales | 25 | El % de la llave estimada cuando ni el local ni el operador tienen uno |
| Cuotas de la llave | Generales | 4 | En cuántas cuotas se paga la llave cuando ni el local ni el operador dicen otra cosa |
| Día de pago de la obra y la apertura | Generales | 15 | El día del mes estimado en que se pagan, corrido al hábil siguiente |
| Chico hasta (m²) y Mediano hasta (m²) | Generales | Vacío; se sugieren y se confirman con **Confirmar los sugeridos** | El segmento de cada local por sus m². Sin ellos, las obras sin segmento a mano no se proyectan |
| Costo en U$S por segmento (obra de imagen y apertura; chico, mediano y grande) | Generales | Vacío | El costo de una obra o apertura sin costo propio. Sin él, no se proyecta |
| % de llave | Por operador | Vacío = el general (en gris, el sugerido) | El % de la llave estimada de sus locales |
| % otros gastos | Por operador | Vacío = sin dato (en gris, el sugerido) | Los otros gastos estimados: ese % de la llave |
| Escribano y otros | Por operador | Vacío = sin dato | Un importe por renovación, sin impuestos, que se suma a los otros gastos |
| % impuestos | Por operador | Vacío = sin dato (en gris, el sugerido) | Lo que se suma a la llave y los otros gastos. Sin él, no se proyectan |
| Cuotas | Por operador | Vacío = las generales | En cuántas cuotas se paga la llave de sus locales |
| Corrim. | Por operador | Vacío = 0 | Cuántos meses se mueve la primera cuota respecto del inicio del contrato nuevo (negativo = antes) |
| No renueva (con motivo) | Por local | Sin tildar | No se estima nada de esa renovación. Deja de valer cuando se carga el contrato nuevo |
| Llave a mano ($) | Por local | Vacío = calculada | Reemplaza la llave estimada (chip Manual). Deja de valer cuando se carga el contrato nuevo |
| % propio | Por local | Vacío = el del operador o el general | El % de la llave estimada de ese local. Deja de valer cuando se carga el contrato nuevo |
| Meses | Por local | Vacío = los del contrato de llave | Los meses de la llave estimada. Deja de valer cuando se carga el contrato nuevo |
| Cuotas | Por local | Vacío = las del operador o las generales | En cuántas cuotas se paga la llave de ese local. Sigue valiendo con el contrato nuevo cargado |
| 1.ª cuota | Por local | Vacío = el mes del día siguiente al fin del contrato (el mes en curso, si ya venció) | El mes de la primera cuota. Sigue valiendo con el contrato nuevo cargado |
| Renovación, fecha, montos de llave y otros gastos, comprobante | Ya pagadas | Los dos conceptos tildados | El concepto marcado deja de proyectarse en esa renovación. No suma |
| Local, mes estimado, segmento, costo propio | Obras de renovación de imagen | Segmento vacío = por sus m²; costo vacío = el del segmento | Una obra en ese mes, en pesos al dólar futuro. Una por local y mes |
| Nombre, Nro., mes estimado, segmento, costo propio | Aperturas de locales nuevos | Segmento obligatorio; costo vacío = el del segmento | Una apertura en ese mes, en pesos al dólar futuro |
| Operador, local, concepto, fecha, monto, cuotas, forma de pago | Compromisos a mano | 1 cuota | Un pago, o una serie mensual, en su fecha y por su monto. La forma de pago es informativa |

El operador de cada local y su % de retención no se cargan acá: salen de Parámetros › Alquileres.

## Avisos y qué hacer

Cada aviso sale arriba de la pestaña, con la parte de la pantalla a la que se refiere adelante. También llegan al tablero. Para cómo se leen los avisos en general, mirá [los avisos](ayuda:general_avisos).

:::aviso atencion
texto: Contratos: El contrato de N venció el dd/mm/aaaa; si se renovó, cargá el contrato nuevo en la app de alquileres. Mientras tanto se estima su renovación con la primera cuota en el mes en curso (si su contrato tiene llave), o marcalo como «No renueva» en Parámetros › Llaves y Renovaciones.
significa: El contrato del local venció y no hay contrato nuevo en la app. Se estima igual, con la primera cuota después de hoy.
corrige: Administración carga el contrato nuevo en la app de alquileres. Si el local no renueva, marcalo en Parámetros › Llaves y Renovaciones › Por local.
:::

:::aviso info
texto: Contratos: Contratos que vencen dentro del horizonte: N (dd/mm/aaaa), …. Se estima su renovación hasta que se cargue el contrato nuevo en la app.
significa: Esas renovaciones están estimadas, no en un contrato.
corrige: Nada que corregir. Cuando administración cargue el contrato nuevo, la estimación se reemplaza sola.
:::

:::aviso atencion
texto: Contratos: Sin ningún contrato en la app de alquileres: N. No se proyecta su llave ni su renovación; se carga el contrato en la app de alquileres.
significa: El local no tiene ningún contrato cargado.
corrige: Administración, cargando el contrato en la app de alquileres.
:::

:::aviso atencion
texto: Contratos: Sin razón social en Parámetros › Alquileres: N. Sin operador no se proyecta su llave ni sus otros gastos: se carga en Parámetros › Alquileres › Locales.
significa: El local no tiene operador, y sin él no hay % ni cuotas con qué proyectar.
corrige: Parámetros › Alquileres › Locales: cargá la razón social principal del local.
:::

:::aviso atencion
texto: Proyección: Sin alquiler proyectado en Alquileres para el mes de la primera cuota: N (mes). No se estima esa llave: el mes cae fuera del horizonte de Alquileres o el local no proyecta ahí (revisalo en Parámetros › Alquileres), o se carga la llave a mano en Parámetros › Llaves y Renovaciones.
significa: La pestaña Alquileres no tiene el alquiler de ese mes para el local, así que no hay con qué estimar la llave.
corrige: Revisá el local en Parámetros › Alquileres, o cargá la llave a mano en Parámetros › Llaves y Renovaciones › Por local.
:::

:::aviso atencion
texto: Parámetros: El operador N no tiene % de impuestos sobre llave y otros gastos: no se proyectan la llave ni los otros gastos (el % de otros gastos, o las comisiones y el FPC del contrato) de N, por $ X netos sin impuestos. El escribano sí se proyecta: no lleva impuestos. Se carga en Parámetros › Llaves y Renovaciones › Por operador.
significa: Falta el % de impuestos del operador. Lo que se ve en el aviso es el neto que queda afuera. El escribano se proyecta igual.
corrige: Parámetros › Llaves y Renovaciones › Por operador, columna **% impuestos**. Si no factura impuestos, cargá `0`.
:::

:::aviso atencion
texto: Parámetros: Sin % de retención en Parámetros › Alquileres: N ($ X netos). No se proyectan su llave ni sus otros gastos; el escribano sí, porque no lleva retención.
significa: Al local le falta el % de retención. El escribano se proyecta igual.
corrige: Parámetros › Alquileres, en el local.
:::

:::aviso atencion
texto: Parámetros: Operador sin % de otros gastos sobre la llave: N. Sus otros gastos estimados no suman ese %: se carga en Parámetros › Llaves y Renovaciones › Por operador.
significa: En las renovaciones estimadas de ese operador, los otros gastos sólo llevan el escribano (si está cargado).
corrige: Parámetros › Llaves y Renovaciones › Por operador, columna **% otros gastos**.
:::

:::aviso atencion
texto: Parámetros: Operador sin escribano y otros por renovación: N. Se proyecta la parte del % y el escribano no se suma; si es 0, cargá 0 en Parámetros › Llaves y Renovaciones › Por operador.
significa: Falta el importe de escribano del operador. Lo demás de los otros gastos se proyecta igual.
corrige: Parámetros › Llaves y Renovaciones › Por operador, columna **Escribano y otros** (`0` si no hay).
:::

:::aviso atencion
texto: Proyección: Cuotas de contratos firmados que ya pasaron sin factura ni marca de pagada: N (contrato #N del dd/mm/aaaa), cuota N del dd/mm/aaaa por $ X. Pueden estar sin pagar o sin factura: si ya se pagó, marcalo «Ya pagada» en Parámetros o imputá la factura como llave en Alquileres › Facturas.
significa: Una cuota de un contrato cargado tenía fecha en este mes o el anterior, ya pasó y no hay nada que diga que se pagó.
corrige: Si se pagó, marcala **Ya pagada** o marcá la factura con **Es llave / renovación** en Alquileres › Facturas. Si no se pagó, averiguá por qué.
:::

:::aviso atencion
texto: Parámetros: Faltan confirmar los cortes de segmento por m² en Parámetros › Llaves y Renovaciones › Generales: las obras sin segmento a mano no se proyectan.
significa: Hay obras que dependen del segmento por m², y los cortes no están cargados.
corrige: Parámetros › Llaves y Renovaciones › Generales: **Confirmar los sugeridos** o cargá los cortes.
:::

:::aviso atencion
texto: Parámetros: Local sin m² ni segmento: N. Su obra no se proyecta: cargá los m² en el Gestor de Locales o el segmento a mano en la obra.
significa: El local de una obra no tiene m² y la obra no tiene segmento.
corrige: Cargá los m² en el gestor de locales, o dá de baja la obra y cargala de nuevo con su segmento.
:::

:::aviso atencion
texto: Parámetros: Segmento sin costo en U$S: OBRA chico, …. Esas obras o aperturas no se proyectan: se carga en Parámetros › Llaves y Renovaciones › Generales, o un costo propio en cada una.
significa: Falta el costo en dólares de ese segmento.
corrige: Parámetros › Llaves y Renovaciones › Generales, tabla **Costo en U$S por segmento**, o un costo propio en la obra o la apertura.
:::

:::aviso info
texto: Facturas: Facturas marcadas como llave que ya no están pendientes en Tango: …. Se toman como pagadas (no suman y siguen cortando lo proyectado). Si alguna se anuló, deshacé la marca.
significa: Las facturas se pagaron, o se anularon, desde que se marcaron.
corrige: Si alguna se anuló, tocá **Deshacer** en Alquileres › Facturas.
:::

:::aviso atencion
texto: Facturas: Facturas marcadas como llave cuyo pendiente cambió desde que se repartieron en pagos: …. Los pagos siguen como se cargaron: deshacé la marca y volvé a repartirla.
significa: El pendiente de la factura en Tango ya no es el que se repartió en pagos.
corrige: En Alquileres › Facturas: **Deshacer** y volver a marcarla con los pagos nuevos.
:::

:::aviso info
texto: Facturas: N factura(s) de alquiler por $ X están excluidas como pago de contrato y no suman en ningún lado: …. Si alguna es una llave o una renovación, volvé a incluirla y marcala «Es llave / renovación» en Alquileres › Facturas.
significa: Hay facturas excluidas en Alquileres que quizás son llaves.
corrige: En Alquileres › Facturas: volvé a incluir la que sea llave y marcala con **Es llave / renovación**.
:::

:::aviso info
texto: Facturas: N factura(s) con leyenda de llave, cuota inicial, servicio de comercialización o prenda siguen en Cuentas a Pagar Locales: …. Si ese concepto también está en Llaves (contrato o estimado), se cuenta dos veces: marcalo «Ya pagada» o cargá el pago como compromiso, según corresponda.
significa: Una factura que parece de llave sigue en Proveedores Locales. Si esta pestaña también la proyecta, se cuenta dos veces.
corrige: Marcá **Ya pagada** el concepto de esa renovación, o cargá el pago como compromiso a mano, según corresponda.
:::

### En Parámetros › Llaves y Renovaciones

Esa sección también puede mostrar estos:

:::aviso atencion
texto: Generales: Faltan confirmar los cortes de segmento por m²: sin ellos ninguna obra sin segmento a mano se proyecta. Se sugieren los terciles de los m² de los locales de hoy.
significa: Los cortes chico y mediano no están cargados.
corrige: **Confirmar los sugeridos**, o cargá los dos cortes.
:::

:::aviso info
texto: Generales: Locales sin m² en el Gestor de Locales: N. Una obra en ellos necesita el segmento a mano.
significa: Esos locales no tienen m², así que no tienen segmento.
corrige: Cargá los m² en el gestor de locales, o el segmento a mano en cada obra.
:::

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: Parámetros: Faltan las tablas de Parámetros › Llaves y Renovaciones: hay que correr sql/cashflow_llaves.sql contra la base central. Sin ellas se proyectan sólo las llaves de los contratos cargados en la app si el operador tiene % de impuestos, que tampoco se puede cargar: no se proyecta nada.
significa: La sección de parámetros todavía no está instalada. La pestaña no proyecta nada.
corrige: Sistemas, instalando la sección de parámetros.
:::

:::aviso critico
texto: No se pudieron leer los locales y contratos de la app de alquileres (…): no hay nada que proyectar.
significa: No se pudo leer la app de alquileres. Entre paréntesis va el error.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo proyectar llaves y renovaciones: …
significa: La pestaña no pudo cargar. Después de los dos puntos va el error.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Faltan las tablas de Parámetros › Alquileres (sql/cashflow_alquileres.sql): sin el operador de cada local no se proyecta ninguna llave.
significa: Parámetros › Alquileres no está instalado, así que ningún local tiene operador.
corrige: Sistemas, instalando Parámetros › Alquileres.
:::

:::aviso atencion
texto: No se pudieron leer los parámetros de Alquileres (…): sin el operador de cada local no se proyecta ninguna llave.
significa: Falló la lectura de Parámetros › Alquileres. Ninguna llave se proyecta.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: No se pudieron leer los m² de los locales (…): no hay segmento por m² para las obras.
significa: No se pudo leer el gestor de locales. Las obras sin segmento a mano no se proyectan.
corrige: Tocá **Actualizar** más tarde, o cargá el segmento a mano en la obra. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Proyección: No se pudo leer el alquiler proyectado de Alquileres (…): no se estima ninguna llave. Los contratos cargados se proyectan igual.
significa: Sin el alquiler de la pestaña Alquileres no hay con qué estimar. Lo cargado en la app sí se ve.
corrige: Revisá que la pestaña Alquileres cargue bien y tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Proyección: No se pudo leer la curva del dólar futuro (…): las obras y las aperturas no se proyectan en pesos.
significa: Sin el dólar futuro, las obras y las aperturas quedan afuera.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Proyección: Sin dólar futuro para el mes: N (Obra de imagen, aaaa-mm, U$S X). No se proyectan en pesos.
significa: No hay ninguna cotización de dólar futuro con qué pasar a pesos esa obra o apertura.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

En Parámetros › Llaves y Renovaciones:

:::aviso critico
texto: Faltan las tablas de Parámetros › Llaves y Renovaciones: hay que correr sql/cashflow_llaves.sql contra la base central. Hasta entonces no se estima ninguna llave, obra ni apertura, y no se cargan compromisos; los contratos de la app se ven igual.
significa: La sección no está instalada: no deja guardar nada.
corrige: Sistemas, instalando la sección de parámetros.
:::

:::aviso critico
texto: No se pudieron leer los parámetros de Llaves y Renovaciones: …
significa: La sección no pudo cargar.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Por operador: No se pudo medir el % sugerido de cada operador (…).
significa: No hay sugeridos de % de llave ni de % de otros gastos. Lo cargado se usa igual.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Por operador: No se pudieron leer de Tango las facturas de llave (…): no hay % de impuestos sugerido.
significa: No hay sugerido de % de impuestos. Lo cargado se usa igual.
corrige: Tocá **Actualizar** más tarde, o cargá el % a mano. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Por qué un local no estima llave?
Puede ser por alguna de estas razones. Mirá la nota debajo del local en la solapa **Contratos** y los avisos de arriba:

- Su contrato de llave no tiene llave: es el criterio de Costo de Ocupación.
- Su contrato vence después del horizonte (estado **Vigente**).
- La renovación ya está cargada en la app: se usa la llave del contrato, no una estimada.
- Está marcado **No renueva**, es un local temporal o no tiene contrato en la app.
- No tiene razón social en Parámetros › Alquileres.
- La pestaña Alquileres no tiene su alquiler para el mes de la primera cuota.
- Al operador le falta el % de impuestos o al local el % de retención.
- La llave ya está marcada **Ya pagada**, o la reemplaza una factura marcada como llave.
:::

:::pregunta ¿Qué es un contrato puente?
Un contrato sin llave y de menos de 12 meses. Administración lo carga para cubrir el tiempo entre el fin de un contrato y la firma del siguiente.

Un puente no hace que el local deje de pagar llave. Si el vigente es un puente, la llave se estima con el último contrato anterior que no lo sea: con su llave como criterio y con sus meses.

Un contrato sin llave de 12 meses o más sí corta: ese local no estima llave.
:::

:::pregunta ¿Qué hago con una factura de llave que se paga en varios echeqs?
La factura dice cuánto se facturó, no cómo se paga. Marcala con **Es llave / renovación** en Alquileres › Facturas y, en el mismo paso, repartila en pagos: **Repartir en las cuotas del operador** arma una propuesta, y con **Pago** agregás o cambiás cada uno con la fecha de cada echeq.

Los pagos tienen que sumar el pendiente menos la retención, y no pueden tener fecha anterior a hoy. Repartir se puede de a una factura pendiente; si tildás varias, cada una va entera en su vencimiento a considerar.

Si la factura ya está pagada en Tango, no se reparte: se marca igual, no suma y sólo corta lo proyectado.
:::

:::pregunta ¿Qué pasa si marco «Ya pagada» sólo la llave?
Los otros gastos de esa renovación siguen proyectados. Por eso el formulario trae los dos conceptos tildados: lo normal es marcar la renovación entera.

Si después se pagan los otros gastos, marcá ese concepto: el que ya estaba marcado no se toca. Para corregir un monto, dá de baja la marca en Parámetros y cargala de nuevo.
:::

:::pregunta ¿Por qué un operador no proyecta nada?
Casi siempre es porque le falta el **% impuestos** en Parámetros › Llaves y Renovaciones › Por operador. Vacío es «sin dato», no cero: sin ese %, la llave y los otros gastos de sus locales no se proyectan, y un aviso dice cuánto neto queda afuera.

El número en gris es sólo una sugerencia y no se guarda. Para usarlo, escribilo en el campo. Si el operador no factura impuestos sobre la llave, cargá `0`.
:::

:::pregunta ¿Qué pasa cuando administración carga el contrato nuevo?
La renovación deja de estimarse y pasa a usar la llave, las comisiones y el FPC del contrato, con el chip **Contrato cargado**. La excepción es una llave de 1 en la app: esa se sigue estimando, con los meses del contrato. El local pasa a **Renovado** en la solapa Contratos.

De lo cargado en **Por local**, el **% propio**, los **Meses**, la **Llave a mano** y **No renueva** dejan de valer: manda el contrato. Las **Cuotas** y la **1.ª cuota** siguen valiendo.

Las marcas de **Ya pagada** y las facturas marcadas como llave siguen valiendo, porque se guardan por el contrato que vence.
:::

:::pregunta ¿Qué quiere decir una llave de 1 en la solapa Contratos?
Es una marca de administración: el contrato tiene llave, pero el monto no se conoce. La pestaña nunca proyecta $ 1: estima la llave con los meses de ese contrato y el % del operador o el general.
:::

:::pregunta ¿Por qué no puedo marcar «Ya pagada» el primer contrato de un local?
Una renovación se identifica por el contrato que vence. El primer contrato de un local no renueva a ninguno, así que no tiene con qué marcarse. Tampoco aparece el botón en un local sin razón social, porque no tiene renovaciones proyectadas.
:::

:::pregunta ¿Qué hago si una factura de llave quedó en Proveedores Locales?
Un aviso informativo nombra las facturas con leyenda de llave, cuota inicial, servicio de comercialización o prenda que siguen en Cuentas a Pagar Locales. Si ese concepto también está proyectado acá, se cuenta dos veces.

Esas facturas no están en Alquileres › Facturas (su proveedor no es una razón social dada de alta en Alquileres), así que no se marcan como llave desde ahí. Lo que dice el aviso: marcá **Ya pagada** ese concepto de la renovación o cargá el pago como compromiso a mano, según corresponda.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí: suman los mismos pagos, columna por columna. En el tablero, la fila Llaves y Renovaciones se abre en real (las facturas marcadas como llave) y proyectado (todo lo demás). Cada celda con algo estimado lo anota, y la cuenta está en esta pestaña. Más sobre real y proyectado en [real y proyectado](ayuda:general_real_proyectado) y sobre el tablero en [el tablero](ayuda:general_tablero).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Renovación | El contrato que sigue a uno que vence. Se identifica por el contrato que vence |
| Contrato vigente | El que cubre hoy; si se superponen, el que empezó último; si ninguno cubre hoy, el último que venció |
| Renovación cargada | La que ya está en la app de alquileres: sus importes salen del contrato |
| Renovación estimada | La que todavía no está en la app: la llave se calcula con un % del alquiler |
| Contrato de llave | El que dice si la renovación tiene llave y con cuántos meses se estima: el vigente o, si es un puente, el último anterior que no lo sea |
| Contrato puente | Un contrato sin llave y de menos de 12 meses, entre el fin de un contrato y la firma del siguiente |
| Llave | Lo que se paga al operador por renovar el contrato, en cuotas |
| Operador | La razón social principal del local en Parámetros › Alquileres: el que cobra la llave |
| Otros gastos | Lo que se paga en la firma además de la llave: comisiones y FPC (cargada), o un % de la llave más el escribano (estimada) |
| FPC lanzamiento | Fondo de Promoción Colectiva: el aporte al fondo de promoción del shopping que se paga al abrir o renovar el contrato. Va con las comisiones, en los otros gastos. |
| VMA | Valor mínimo mensual: con el alquiler y el complementario, forma el alquiler con el que se estima la llave |
| Escribano y otros | Un importe fijo por renovación, por operador, que se suma a los otros gastos sin impuestos ni retención |
| % de impuestos | Lo que el operador factura de más sobre la llave y los otros gastos |
| Segmento | Chico, mediano o grande, según los m² totales del local. Define el costo en dólares de una obra o una apertura |
| Corrimiento | Cuántos meses se mueve la primera cuota respecto del inicio del contrato nuevo (negativo = antes) |
| Corrido al hábil | Un pago que cae en un día no hábil pasa al siguiente día hábil |
| Ya pagada | La marca de que la llave o los otros gastos de una renovación ya salieron de caja: ese concepto deja de proyectarse |
| No renueva | La marca de que un local no va a renovar: no se estima nada de esa renovación |
| Origen | De dónde sale un importe: Contrato cargado, Estimado, Manual o Factura (mixto si una celda junta varios) |
| Compromiso a mano | Un pago pactado que no está en la app, cargado con su fecha y su monto |
| Horizonte | Los meses que proyecta el cashflow |
