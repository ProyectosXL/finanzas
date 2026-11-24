---
titulo: Cobranzas Mayoristas
pestana: cobranzas_may
categoria: Ingresos
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería
bajada: Lo que falta cobrar de cada factura de los clientes mayoristas y en qué fecha se espera cobrarlo: la emisión más el plazo, o la fecha pactada a mano.
---

## Para qué sirve

Muestra cuánto falta cobrar de cada factura pendiente de los clientes mayoristas, y cuándo se espera cobrarlo.

La fecha de cobro es la fecha de emisión más un plazo fijo, 60 días si no se cambió. Si con el cliente se habló otra fecha, se carga a mano, de a una factura o de a muchas.

Lo que se ve en las columnas del período es exactamente lo que proyecta la fila Cobranzas Mayoristas del tablero. Ver [cómo se lee el tablero](ayuda:general_tablero).

No es lo mismo que la fila Cobranza estimada - Mayoristas de Ventas: aquella es la cobranza de ventas que todavía no se hicieron; ésta, la de facturas ya emitidas.

## De dónde salen los datos

:::diagrama
fuente: Tango | las facturas pendientes de los clientes mayoristas, sus vencimientos y lo imputado contra cada una
fuente: Parámetros › Cobranzas | el plazo que se suma a la fecha de emisión
fuente: Las fechas cargadas a mano | la fecha de cobro pactada para una factura puntual
pestana: Cobranzas Mayoristas | resta lo imputado y ubica cada pendiente en su fecha de cobro
tablero: Fila Cobranzas Mayoristas | lo que falta cobrar, en su fecha
alt: Tango, el plazo de Parámetros › Cobranzas y las fechas cargadas a mano alimentan la pestaña Cobranzas Mayoristas, que pasa lo pendiente de cada factura a la fila Cobranzas Mayoristas del tablero.
:::

- **Tango**: la cuenta corriente de los clientes. De cada factura, el cliente, la fecha de emisión, el número, el importe con el que se emitió, sus vencimientos y lo que se imputó contra ella (recibos, notas de crédito, notas de débito). También el vendedor asignado al cliente. El cashflow sólo lee Tango: no escribe nada ahí.
- **Parámetros › Cobranzas**: el **Plazo de Proyección Mayoristas**, los días que se suman a la fecha de emisión.
- **Las fechas cargadas a mano**: la fecha de cobro que alguien pactó para una factura. Son del cashflow, y se guardan en el mismo lugar que las de Cobranzas FR.

Entran sólo las facturas que cumplen todo esto:

- Son facturas. Las notas de crédito y de débito no son filas propias: si están imputadas contra una factura, ya cambian su pendiente.
- Se emitieron en los últimos 360 días.
- Son de un cliente mayorista (su código empieza con M) y sin cláusula de moneda extranjera.
- Siguen pendientes en Tango y les queda algún vencimiento sin pagar.
- Tienen fecha de emisión.
- Les queda saldo a cobrar, mayor que cero.
- Si su fecha calculada ya venció, no venció hace más de 180 días (ver *Las facturas vencidas*).

Lo que no cumple alguna de esas condiciones no se muestra ni entra al tablero, y no hay aviso que lo diga. La excepción es una factura con saldo negativo: no se muestra, pero un aviso dice cuántas son y por cuánto.

Las exclusiones de clientes de Parámetros › Cobranzas no se aplican acá: son de Cobranzas FR.

## Cómo se calcula

### Lo que falta cobrar

De cada factura se toman sus vencimientos sin pagar y se les descuenta lo imputado en Tango: los recibos y las notas de crédito restan, las notas de débito suman. Lo que queda es el **saldo pendiente**, y es lo único que entra al cashflow.

El importe con el que se emitió la factura se muestra al lado, en gris, sólo como referencia.

Una devolución baja el pendiente recién cuando su nota de crédito está imputada contra la factura en Tango. Mientras no lo esté, la factura sigue con el saldo completo.

Mayoristas no tiene escala de descuento. Por eso **SALDO PENDIENTE** e **Importe Neto** son siempre el mismo número, y **Desc** dice siempre 0 %.

### La fecha de cobro

:::formula
Saldo pendiente = vencimientos sin pagar − recibos y notas de crédito imputados + notas de débito imputadas
Fecha de cobro = la fecha cargada a mano, si hay; si no, fecha de emisión + Plazo de Proyección Mayoristas
:::

- El plazo se suma en **días corridos**. Si la fecha cae en un sábado, domingo o feriado, queda ahí: no se corre a un día hábil.
- Una fecha cargada a mano manda sobre la calculada y no cambia el importe: sólo cambia en qué columna cae.
- **Dias** dice cuántos días hay entre la emisión y la fecha de cobro: el plazo, o los días reales si la fecha se cargó a mano.

### Las facturas vencidas

Una factura está vencida cuando su fecha de cobro ya pasó. Qué pasa con ella depende de quién puso esa fecha:

- **Fecha calculada vencida hace 180 días o menos**: el importe se ubica en el **primer día del eje**, que es hoy, aunque hoy sea sábado, domingo o feriado. La fila va en amarillo y un aviso dice cuántas son y cuánto suman. No es cobranza estimada para hoy: son facturas vencidas sin cobrar.
- **Fecha calculada vencida hace más de 180 días**: la factura no se muestra ni entra al tablero, y ningún aviso lo dice. Con el plazo de 60 días, eso pasa con las facturas emitidas hace más de 240 días.
- **Fecha cargada a mano vencida**: no se mueve a hoy, porque la cargó una persona. Queda en su fecha, marcada vencida, y tiene su propio aviso. Si esa fecha es de un mes anterior, queda fuera del horizonte y lo dice el aviso de lo que no entra en la tabla. Si es de este mes, antes de hoy, no tiene columna en ninguna vista: el importe no se ve en la grilla, pero sí suma en las tarjetas **Vista Meses** y **Período Completo**.

**Dias** de una factura vencida que se movió a hoy sigue diciendo el plazo: no cambia por moverla.

Para cómo trata cada pestaña lo vencido, ver [lo vencido](ayuda:general_real_proyectado#lo-vencido).

:::ejemplo ilustrativo
Una factura emitida el 1 de marzo por **$ 1.000.000**. El cliente pagó **$ 400.000** con un recibo imputado a la factura en Tango. El plazo es de 60 días.

- Saldo pendiente: 1.000.000 − 400.000 = **$ 600.000**. **Importe Factura** sigue diciendo $ 1.000.000, en gris.
- Fecha de cobro: 1 de marzo + 60 días corridos = **30 de abril**. **Dias**: 60.
- Si hoy es 15 de abril, los $ 600.000 van en la columna del 30 de abril.
- Si hoy es 10 de mayo, la fecha ya pasó: los $ 600.000 van en la columna de hoy, la fila se ve amarilla y el aviso de vencidas la cuenta.
- Si Tesorería pacta con el cliente el 20 de mayo y lo carga a mano, los $ 600.000 pasan a la columna del 20 de mayo y **Dias** dice 80. El importe no cambia.

Si además el cliente devolvió mercadería por $ 100.000 y la nota de crédito todavía no está imputada contra esta factura, el pendiente sigue en $ 600.000. Cuando se impute, baja a $ 500.000 solo.
:::

### Qué pasa al tablero

La fila Cobranzas Mayoristas del tablero suma el saldo pendiente de cada factura en su fecha de cobro, con las mismas reglas: las vencidas calculadas en hoy, las pactadas en su fecha. Toda la fila es proyectada: no hay parte real.

El tablero siempre mira la cartera entera. Los filtros de la pantalla (emisión, vendedor y el buscador) cambian lo que ves acá, no la fila del tablero.

## La pantalla

La pantalla, de arriba hacia abajo:

- Los avisos de la pestaña: los propios y los que el tablero dice de ella.
- Las tres tarjetas, una por vista.
- Las solapas **Resumen** y **Detalle Facturas**. La pestaña abre en **Resumen**.
- El título, **Cobranzas Mayoristas — Pendientes Proyectados**, con el buscador al lado.
- El filtro de **Emisión** (desde **a** hasta) con su goma, y el desplegable **Vendedor**.
- Los botones de las tres vistas, **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea que dice qué período se mide y, si hay, qué filtro está puesto.
- En **Detalle Facturas**, con permiso de edición y alguna factura tildada, la barra de selección.
- La grilla, con su pie **TOTALES**.

### Las tarjetas

| Tarjeta | Qué suma |
| --- | --- |
| **Vista Días** | Lo que cae en el tramo diario. Debajo dice de qué día a qué día. |
| **Vista Meses** | Lo que cae en las columnas mensuales, después del tramo diario. |
| **Período Completo** | Todo el horizonte: Vista Días más Vista Meses. |

Las tarjetas cambian con el filtro de emisión y con el de vendedor. No cambian con el buscador.

### Resumen y Detalle Facturas

- **Resumen**: una fila por cliente, con la suma de sus facturas en cada columna. Se ven **COD_CLI**, **RAZON_SOC**, **Vendedor**, **Importe Factura**, **SALDO PENDIENTE**, **Importe Neto** y las columnas del período. Abre con los clientes ordenados por su cobro más próximo.
- **Detalle Facturas**: una fila por factura, con todas las columnas. Es donde se cargan las fechas a mano. Abre ordenada por **Cobro**, de la más cercana a la más lejana.

Cambiar de solapa vuelve a pedir los datos.

### Las columnas

| Columna | Qué muestra |
| --- | --- |
| **COD_CLI** | El código del cliente. Al pasar el mouse dice a cuántos días de la emisión se proyectó, o que la fecha se cargó a mano. |
| **RAZON_SOC** | El nombre del cliente. Si es largo, completo al pasar el mouse. |
| **Vendedor** | El vendedor asignado al cliente en Tango, no el de la factura. **Sin vendedor**, en gris, si el cliente no tiene uno. Si el código no está en el maestro de vendedores de Tango, dice «Vendedor … (sin nombre en Tango)». |
| **FECHA** | La fecha de emisión. Sólo en Detalle Facturas. |
| **T_COMP** y **N_COMP** | El tipo y el número de comprobante. Sólo en Detalle Facturas. |
| **Desc** | Siempre 0 %: mayoristas no tiene escala de descuento. Sólo en Detalle Facturas. |
| **Dias** | Los días entre la emisión y la fecha de cobro. Sólo en Detalle Facturas. |
| **Importe Factura** | El importe con el que se emitió la factura, en gris. Informativo: no se suma a nada. |
| **SALDO PENDIENTE** | Lo que falta cobrar. Es lo que va al cashflow. |
| **Importe Neto** | El mismo número que SALDO PENDIENTE, en negrita. |
| **Cobro** | La fecha de cobro. Con permiso de edición, en Detalle Facturas, es un campo de fecha que se puede cambiar. Sólo en Detalle Facturas. |
| Las columnas del período | El importe de cada día o mes, con el total de la columna arriba de su fecha. |
| **Total** | La suma de las columnas de la vista que estás mirando. |

Las celdas en cero quedan vacías. **Columnas fijas** elige qué columnas quedan quietas al moverte a lo ancho; arrancan **COD_CLI** y **RAZON_SOC**. La tabla se ordena haciendo clic en el encabezado de una columna.

### Las marcas

| Marca | Dónde | Qué quiere decir |
| --- | --- | --- |
| Fila amarilla | Las dos solapas | La fecha de cobro ya pasó. En Resumen, alguna factura del cliente está vencida. |
| Triángulo al lado del código | Resumen | Alguna factura del cliente está vencida. El detalle está en Detalle Facturas. |
| Mano azul al lado del código | Resumen | Alguna factura del cliente tiene la fecha de cobro cargada a mano. |
| Campo de fecha azul con una flecha circular | Detalle Facturas | La fecha se cargó a mano. Al pasar el mouse dice quién y cuándo. La flecha la vuelve a la calculada. |
| **Vencida dd/mm/aaaa** | Detalle Facturas, sin permiso de edición | La factura está vencida; la fecha es la que tenía. |

En Detalle Facturas, la fecha de una factura vencida dice al pasar el mouse: «Vencida: la fecha probable era el dd/mm/aaaa y ya pasó. El importe se muestra en el primer día del eje.», o, si la fecha la cargó una persona, «Vencida: la fecha pactada era el dd/mm/aaaa y ya pasó. Se respeta tal cual porque la cargó una persona.».

### Las tres vistas

Los botones **Días**, **Meses** y **Período completo** cambian las columnas del período. La pestaña abre en **Días**. Los sábados, domingos y feriados tienen la columna marcada; un mes recortado va en itálica con un asterisco, porque sus primeros días están en el tramo diario. Ver [las tres vistas](ayuda:general_tablero#las-tres-vistas).

### El buscador

Se escribe y filtra en el momento. No distingue mayúsculas, pero sí acentos.

- **Las filas que se ven** son las que tienen el texto en cualquiera de sus celdas: también una fecha o un importe.
- **El pie TOTALES**, el total arriba de cada fecha, el check del encabezado y la barra de selección cuentan sólo las filas cuyo **COD_CLI**, **RAZON_SOC**, **Vendedor** o **N_COMP** tienen el texto.

Buscando un cliente, un vendedor o un comprobante, las dos cosas coinciden. Buscando una fecha o un importe, se ven filas que el pie no suma.

Si la tabla se vuelve a cargar con texto en el buscador (al tocar **Actualizar**, cambiar un filtro, la solapa o la vista, o guardar una fecha), vuelven a verse todas las filas hasta que escribas de nuevo; el pie sigue sumando sólo las que coinciden.

El buscador no cambia las tarjetas ni el tablero.

### Los filtros de emisión y de vendedor

Los dos se aplican antes de armar la grilla: cambian las filas, las columnas del período, el pie y las tarjetas. El tablero no cambia.

- **Emisión**: deja las facturas emitidas entre las dos fechas, inclusive. Se puede poner sólo una de las dos. La goma lo quita.
- **Vendedor**: **Todos**, cada vendedor con cuántas facturas tiene entre paréntesis, y **Sin vendedor** al final. La lista sale de la cartera entera, así que no cambia con el filtro de emisión. Con un vendedor elegido, el desplegable tiene el borde azul.

Con un filtro puesto, la línea del período lo dice, por ejemplo «Filtro: emitidas del dd/mm/aaaa al dd/mm/aaaa · vendedor …». Con vendedor agrega «El tablero sigue mostrando la cartera entera.».

Los avisos de vencidas de la pestaña se cuentan sobre lo filtrado. Ver la pregunta sobre los avisos repetidos, más abajo.

### Sin permiso de edición

Se ve todo igual, pero la fecha de **Cobro** es un texto y no un campo, y no hay checks ni barra de selección. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Tareas frecuentes

### Poner la fecha de cobro a una factura

:::pasos
1. Abrí la solapa **Detalle Facturas**.
2. Buscá la factura.
3. Elegí la fecha nueva en su celda **Cobro**. Tiene que ser hoy o posterior.
Resultado: se guarda sola, la notificación dice «Fecha de cobro guardada.» y la tabla se recarga con el importe en la columna de esa fecha y el campo en azul. El importe no cambia.
:::

### Volver una factura a la fecha calculada

:::pasos
1. En **Detalle Facturas**, buscá la factura con el campo de fecha azul.
2. Tocá la flecha circular al lado de la fecha.
Resultado: la notificación dice «La fecha vuelve a calcularse con el plazo de vencimiento.» y la factura vuelve a la fecha de emisión más el plazo.
:::

Si la fecha de emisión más el plazo ya pasó, la factura vuelve como vencida, en el primer día del eje.

### Poner la misma fecha a varias facturas

:::pasos
1. En **Detalle Facturas**, tildá las facturas. El check del encabezado tilda todas las que se están viendo; con el buscador puesto, las de ese cliente.
2. Leé la barra: cuántas facturas, por cuánto pendiente, de cuántos clientes y cuántas ya tienen fecha cargada a mano.
3. Tocá **Poner fecha de cobro**.
4. El diálogo **Fecha de cobro para varias facturas** repite cuántas son y avisa cuántas fechas cargadas a mano se pisan. Elegí la fecha en **Fecha de cobro (la misma para todas)**: hoy o posterior.
5. Tocá **Fechar N factura(s)**.
Resultado: la notificación dice «Fecha de cobro dd/mm/aaaa guardada en N factura(s).». Se guardan todas o ninguna. Las facturas quedan tildadas, por si hay que corregir la fecha.
:::

Si todas las tildadas ya comparten una fecha que no pasó, el diálogo viene con esa fecha puesta.

La selección se mantiene al usar el buscador, el filtro de emisión o el de vendedor. La barra cuenta sólo lo tildado que se ve. **Limpiar selección** destilda todo.

### Volver varias facturas a la fecha calculada

:::pasos
1. En **Detalle Facturas**, tildá las facturas.
2. Tocá **Volver a la fecha calculada**. Sólo se puede si alguna de las tildadas tiene la fecha cargada a mano.
3. La confirmación dice cuántas de las tildadas tienen fecha a mano. Las demás no cambian.
4. Tocá **Volver N factura(s)**.
Resultado: la notificación dice «N factura(s) vuelven a la fecha calculada.» y esas facturas pasan a la fecha de emisión más el plazo.
:::

### Correr una factura vencida

:::pasos
1. En **Detalle Facturas**, buscá la fila amarilla.
2. Si se habló una fecha con el cliente, cargala en **Cobro** (hoy o posterior).
Resultado: el importe sale del primer día del eje y va a la columna de la fecha nueva. La fila deja de estar vencida.
:::

No se puede cargar una fecha pasada: la factura desaparecería del listado de pendientes.

### Ver la cartera de un vendedor y exportarla

:::pasos
1. Elegí el vendedor en **Vendedor**.
2. Elegí la solapa y la vista que quieras.
3. Tocá **Exportar**.
Resultado: se baja un Excel con las filas y las columnas que se están viendo, con la columna **Vendedor**. Las tarjetas y el pie son los de ese vendedor; el tablero sigue con la cartera entera.
:::

### Ver las facturas emitidas en un período

:::pasos
1. Elegí la fecha **desde** y la fecha **hasta** en **Emisión**. Podés poner una sola.
Resultado: quedan las facturas emitidas en ese rango, y la grilla, el pie y las tarjetas suman sólo ésas. La goma quita el filtro.
:::

## Parámetros

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Plazo de Proyección Mayoristas** | Parámetros › Cobranzas, tarjeta **Plazos de Cobro** | 60 | Los días que se suman a la fecha de emisión de cada factura sin fecha cargada a mano. Mueve el tablero y cambia qué facturas quedan vencidas. |
| **Columnas diarias** | Parámetros › Calendario | 28 | Cuántos días tiene la vista Días. |
| **Columnas mensuales** | Parámetros › Calendario | 12 | Cuántos meses tiene la vista Meses. Lo que cae después del último queda fuera del período. |
| Feriados bancarios | Parámetros › Calendario | Los del calendario bancario | Sólo qué columnas diarias van marcadas. No corren ninguna fecha de esta pestaña. |

El plazo se guarda solo al cambiarlo: la tarjeta parpadea en verde. Tiene que ser un número entero mayor a 0. La descripción de arriba de Parámetros › Cobranzas lo llama **Plazo de Vencimiento Mayorista**: es el mismo. Si el parámetro no está cargado, se usa 60.

De Parámetros › Cobranzas, a esta pestaña sólo le toca ese plazo. La escala de descuento, el PPP, el medio de pago y las exclusiones son de Cobranzas FR.

Los 180 días de las vencidas y los 360 de antigüedad de la emisión no son parámetros: no se cambian desde la pantalla.

Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Avisos y qué hacer

Los avisos se ven arriba de la tabla, junto con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: N factura(s) por $ X tiene(n) la fecha probable de cobro ya vencida: se ubica(n) en el primer día del eje. Es una factura vencida / Son facturas vencidas sin cobrar, no cobranza estimada para hoy.
significa: Facturas cuya fecha de emisión más el plazo ya pasó, hace 180 días o menos. Su importe está en la columna de hoy. Son las filas amarillas.
corrige: Si se habló una fecha con el cliente, cargala en **Cobro**. Si no, es plata vencida que hay que gestionar.
:::

:::aviso atencion
texto: N factura(s) por $ X tiene(n) la fecha de cobro pactada a mano y ya vencida: se muestra(n) en su fecha, sin reubicar, porque la cargó una persona.
significa: Una fecha cargada a mano ya pasó y la factura sigue pendiente. El importe queda en esa fecha, que puede no tener columna.
corrige: Cargale una fecha nueva en **Cobro**, o volvela a la calculada con la flecha circular.
:::

:::aviso atencion
texto: N factura(s) de mayoristas vuelve(n) con saldo pendiente NEGATIVO por $ X: contra esa(s) factura(s) se imputó más de lo que decía. No se muestra(n) ni entra(n) al cashflow —un saldo negativo no es plata a cobrar—, pero el dato está acá porque el desvío hay que revisarlo en Tango.
significa: Contra esas facturas se imputó más que su saldo. No están en la grilla ni en el tablero. Cuenta la cartera entera, sin filtros.
corrige: En Tango: la imputación de más o la factura.
:::

:::aviso atencion
texto: N comprobante(s) sin fecha de emisión queda(n) fuera del filtro: no se puede(n) ubicar en el rango. Quitá el filtro para verlo(s).
significa: Con el filtro de emisión puesto, hay comprobantes sin fecha de emisión que no se pueden comparar con el rango.
corrige: Quitá el filtro con la goma.
:::

:::aviso info
texto: Cobranzas Mayoristas: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Importes con fecha después del último mes del período, o una fecha cargada a mano que ya pasó y es de un mes anterior.
corrige: Si son posteriores, subí **Columnas mensuales** en Parámetros › Calendario. Si es una fecha pactada vencida, cargale una fecha nueva.
:::

:::aviso atencion
texto: Cobranzas Mayoristas: $ X sin fecha asignada no se pueden ubicar en el tablero.
significa: Importe sin fecha de cobro que no puede ir a ninguna columna.
corrige: Cargale la fecha de cobro a la factura.
:::

Si el tablero no responde, esos dos últimos salen con el texto de la grilla: «$ X con fecha fuera del horizonte no se muestran en la tabla.» y «$ X sin fecha asignada no se pueden ubicar en el tiempo y quedan fuera de la tabla.».

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción.

| Mensaje | Cuándo |
| --- | --- |
| «Fecha de cobro guardada.» | Se guardó la fecha de una factura. |
| «La fecha vuelve a calcularse con el plazo de vencimiento.» | Se borró la fecha cargada a mano de una factura. |
| «Fecha de cobro dd/mm/aaaa guardada en N factura(s).» | Se guardó la misma fecha en varias. |
| «N factura(s) vuelven a la fecha calculada.» | Se borró la fecha a mano de varias. |
| «Error: La fecha de cobro no puede ser anterior a hoy (dd/mm/aaaa). Una factura con fecha pasada desaparecería del listado de pendientes.» | Se eligió una fecha pasada. No se guardó nada. |
| «Error: La fecha de cobro no es una fecha válida.» / «Error: La fecha de cobro no existe en el calendario.» | La fecha está mal escrita. |
| «Elegí la fecha en la que se acordó el cobro.» | En el diálogo de varias, se tocó **Fechar** sin fecha. |
| «No se aceptan fechas pasadas: la factura desaparecería del listado de pendientes.» | En el diálogo de varias, la fecha es anterior a hoy. |
| «Tu rol no tiene permiso para modificar esta sección (…). Podés consultarla, pero el cambio no se guardó.» | Quien guarda no tiene permiso de edición en Cobranzas Mayoristas ni en Cobranzas FR. |
| «Error al cargar datos: Error: El filtro por fecha de emisión está al revés: desde (dd/mm/aaaa) es posterior a hasta (dd/mm/aaaa), así que no hay ninguna factura que pueda entrar.» | El rango de emisión está al revés. |
| «Error al cargar datos: Error: La fecha "desde" del filtro no es una fecha válida.» (o "hasta", o «no existe en el calendario») | Una fecha del filtro de emisión está mal escrita. |
| «Error al cargar datos: Error: El filtro por vendedor no es un código de vendedor válido.» | El vendedor pedido no es un código posible. |
| «El plazo tiene que ser un número entero mayor a 0.» | En Parámetros › Cobranzas, el plazo está vacío, es cero o negativo. |
| «No se pudo guardar el parámetro: …» / «Error de conexión al guardar el parámetro: …» | Falló el guardado del plazo. |

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No se pudo calcular (…). Sus filas se muestran en cero.
significa: El tablero no pudo calcular la fila. Entre paréntesis va el error.
corrige: Tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: No se pudo cargar el módulo Cobranzas Mayoristas: sus filas se muestran en cero.
significa: El tablero no encontró el módulo de esta fila.
corrige: Avisá a sistemas.
:::

:::aviso critico
texto: El módulo Cobranzas Mayoristas no pudo calcularse (…): sus filas se muestran en cero.
significa: El módulo falló al calcular. Entre paréntesis va el error.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: Error al cargar datos: Error: No se pudo conectar a la base de datos central
significa: La pestaña no pudo leer nada. Sale como cartel.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: Error al cargar datos: Error: Error al consultar facturas pendientes mayoristas: …
significa: Falló la lectura de las facturas en Tango. Sale como cartel, con el error.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a sistemas.
:::

:::aviso critico
texto: Error de conexión: …
significa: No llegó respuesta del servidor. Sale como cartel.
corrige: Revisá la conexión y tocá **Actualizar**. Si sigue, avisá a sistemas.
:::

:::aviso atencion
texto: Error: Error al guardar la fecha de cobro: … / Error al borrar la fecha de cobro: … / No se pudo abrir la transacción: … / No se pudo confirmar el guardado: …
significa: Falló la escritura de una fecha. No se guardó nada: en las de varias facturas, se guardan todas o ninguna.
corrige: Probá de nuevo. Si sigue, avisá a sistemas con el texto.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

Dos cosas que no avisan: si no se pueden leer las fechas cargadas a mano, la pestaña proyecta todo con el plazo, como si no hubiera ninguna; y si no se puede leer el plazo, usa 60.

## Preguntas frecuentes

:::pregunta ¿Por qué una factura con devolución sigue con el saldo completo?
Porque la nota de crédito todavía no está imputada contra esa factura en Tango. El pendiente baja sólo con lo imputado.

Mientras tanto, si sabés que el cobro se corre, podés cargarle a mano una fecha de cobro posterior. Eso no cambia el importe. Cuando se impute la nota de crédito, el saldo baja solo.
:::

:::pregunta ¿Qué diferencia hay entre Importe Factura y SALDO PENDIENTE?
**Importe Factura** es con lo que se emitió; **SALDO PENDIENTE**, lo que falta cobrar después de lo imputado. Una factura cobrada en parte muestra los dos distintos. Al cashflow va sólo el pendiente.

En Resumen, **Importe Factura** suma las facturas del cliente, también como referencia. El pie **TOTALES** no suma ninguna de esas columnas: sólo las del período.
:::

:::pregunta ¿Por qué hay tanto importe en la columna de hoy?
Porque ahí van las facturas vencidas: las que tenían la fecha de emisión más el plazo antes de hoy. No es lo que se espera cobrar hoy: son facturas vencidas sin cobrar. Las filas amarillas son ésas, y el aviso dice cuántas son y cuánto suman.

La columna de hoy es la primera del eje aunque hoy sea sábado, domingo o feriado. Para sacar una factura de ahí, cargale la fecha que se habló con el cliente.
:::

:::pregunta ¿Por qué no aparece una factura vieja que sigue pendiente en Tango?
Puede ser por dos razones, y ninguna avisa:

- Su fecha calculada venció hace más de 180 días. Con el plazo de 60, son las emitidas hace más de 240 días.
- Se emitió hace más de 360 días.

Tampoco aparece si su saldo pendiente es cero o negativo; las negativas sí tienen aviso.
:::

:::pregunta ¿Una fecha cargada a mano cambia el importe?
No. Mayoristas no tiene escala de descuento: la fecha sólo cambia en qué columna cae el importe. En Cobranzas FR sí cambia, porque con los días cambia el descuento.
:::

:::pregunta ¿Por qué con el filtro de vendedor aparecen dos avisos de vencidas con números distintos?
Porque uno es de la pestaña y se cuenta sobre lo filtrado, y el otro es del tablero y se cuenta sobre la cartera entera. Sin filtros los dos dicen lo mismo y se ve uno solo. Con el filtro de emisión pasa lo mismo.
:::

:::pregunta ¿El filtro de vendedor cambia el tablero?
No. Cambia la grilla, el pie y las tarjetas de esta pestaña. El tablero sigue con la cartera entera, y la línea del período lo dice.
:::

:::pregunta ¿Por qué un cliente sale «Sin vendedor»?
Porque en Tango no tiene vendedor asignado. Se corrige en el cliente de Tango, y se ve acá al tocar **Actualizar**. El vendedor es el del cliente, no el de cada factura.
:::

:::pregunta ¿Por qué el pie no coincide con las filas que se ven cuando busco?
El buscador esconde las filas que no tienen el texto en ninguna celda, pero el pie suma sólo las que lo tienen en el código, el nombre, el vendedor o el número de comprobante. Buscando una fecha o un importe se ven filas que el pie no cuenta. Y si la tabla se recargó con el buscador escrito, se ven todas hasta que escribas de nuevo.
:::

:::pregunta ¿Por qué no puedo cargar una fecha pasada?
Porque una factura con fecha de cobro pasada quedaría vencida apenas se guarda. Para correr una vencida, la fecha nueva tiene que ser hoy o posterior.
:::

:::pregunta ¿Puedo cargar fechas en Resumen?
No. En Resumen la fila es un cliente, no una factura. La mano azul al lado del código avisa que alguna factura del cliente tiene fecha a mano; el detalle está en **Detalle Facturas**.
:::

:::pregunta ¿Los cheques de mayoristas se cuentan dos veces, acá y en Echeqs?
No. Un cheque en cartera entró con un recibo que ya canceló la factura, así que acá esa factura ya no tiene pendiente. El cheque se cuenta en Echeqs.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí, sin filtros: suman lo mismo, columna por columna. Con el filtro de emisión o de vendedor puesto, la pestaña muestra una parte y el tablero sigue con todo. Más sobre el tablero en [cómo se lee el tablero](ayuda:general_tablero).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Saldo pendiente | Lo que falta cobrar de una factura: sus vencimientos sin pagar menos lo imputado. Es lo que va al cashflow |
| Importe Factura | El importe con el que se emitió la factura. Informativo |
| Importe Neto | En mayoristas, igual al saldo pendiente: no hay descuento |
| Plazo de Proyección Mayoristas | Los días corridos que se suman a la fecha de emisión. 60 si no se cambió |
| Fecha calculada | Fecha de emisión más el plazo |
| Fecha cargada a mano | La fecha de cobro que alguien pactó para una factura. Manda sobre la calculada y no cambia el importe |
| Vencida | Una factura cuya fecha de cobro ya pasó. Si la fecha es calculada, va al primer día del eje; si es cargada a mano, queda en su fecha |
| Primer día del eje | Hoy: la primera columna diaria, aunque no sea hábil |
| Imputación | En Tango, aplicar un recibo, una nota de crédito o una nota de débito a una factura |
| Vendedor | El vendedor asignado al cliente en Tango |
| Resumen / Detalle Facturas | Una fila por cliente / una fila por factura |
| Tramo diario | Las columnas de días de la vista Días |
| Horizonte | El período que proyecta el cashflow: el tramo diario más los meses |
