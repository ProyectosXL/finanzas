---
titulo: Proveedores Exterior
pestana: proveedores_exterior
categoria: Comercio Exterior
estado: completa
actualizado: 2026-11-23
usuarios: Comercio Exterior, Tesorería
bajada: Lo que falta pagarle a cada proveedor del exterior, contenedor por contenedor, en pesos y con el dólar del momento en que se paga.
---

## Para qué sirve

Muestra, contenedor por contenedor, cuánto falta pagarle al proveedor del exterior y cuándo.

Toma el FOB de cada contenedor, le resta lo que Comercio Exterior ya registró como pagado y pasa ese pendiente a pesos con el dólar que corresponde a la fecha de pago.

Es el detalle de la fila de Proveedores Exterior del tablero: lo que se ve en sus columnas es exactamente lo que esa fila proyecta. Ver [cómo se lee el tablero](ayuda:general_tablero).

Reemplaza a la ayuda vieja de Comercio Exterior, que era un solo cartel para esta pestaña y para Crono Nacionalización. El otro pago del mismo contenedor, el de la nacionalización, está en [Crono Nacionalización](ayuda:crono_nacionalizacion).

## De dónde salen los datos

:::diagrama
fuente: La app de Comercio Exterior | los contenedores, su FOB y sus fechas de embarque, llegada y pago estimado
fuente: Los pagos cargados en Comercio Exterior | lo que ya se le pagó a cada proveedor, en dólares
fuente: La curva de dólar futuro ROFEX | a cuánto está el dólar de cada mes futuro
fuente: El dólar BCRA | el vendedor del día hábil anterior, para los pagos de los próximos días
pestana: Proveedores Exterior | resta lo pagado y pasa el pendiente a pesos
tablero: Fila Proveedores Exterior | lo que falta pagar, de hoy en adelante
alt: La app de Comercio Exterior, sus pagos, la curva ROFEX y el dólar BCRA alimentan la pestaña, que le pasa al tablero lo que falta pagar.
:::

- **La app de Comercio Exterior**: cada contenedor con su proveedor, su orden de compra, su despachante, su FOB en dólares, la fecha de embarque (ETD), la de llegada (ETA) y la fecha estimada de pago. La fecha estimada de pago es la misma que ve esa aplicación: si la cambiás acá, cambia allá.
- **Los pagos cargados en Comercio Exterior**: los anticipos y saldos que ya se le pagaron al proveedor, en dólares. Desde acá se miran; no se cargan.
- **La curva de dólar futuro ROFEX**: a cuánto se puede comprar hoy un dólar de cada mes futuro. Es de sólo lectura y la actualiza un proceso automático.
- **El dólar BCRA**: la cotización oficial, punta vendedora, del día hábil anterior. Se usa para lo que se paga en los próximos días o ya venció.
- **Lo que se marca en esta pestaña**: el tilde de pagado y la cotización corregida a mano para un contenedor. Esas dos cosas son del cashflow: Comercio Exterior no las ve.

Entran sólo los contenedores cuya orden de compra principal tiene fecha de movimiento igual o posterior a **Contenedores desde (fecha de movimiento)**, de Parámetros. Lo anterior no se muestra ni se avisa.

## Cómo se calcula

A cada contenedor se le resta lo ya pagado en Comercio Exterior. Lo que queda es el **pendiente**, y es lo único que se proyecta.

El pendiente se pasa a pesos con un dólar que depende de la fecha estimada de pago:

- si se paga dentro de los próximos días (7 por defecto) o la fecha ya pasó, con el **dólar BCRA vendedor del día hábil anterior**;
- si se paga más adelante, con la **curva ROFEX del mes de pago**;
- si alguien corrigió la cotización de ese contenedor a mano, con esa cotización, que manda sobre las otras dos.

Ese importe en pesos va a la columna de su fecha. Lo que **no** suma en ninguna columna del período:

- lo que tiene la fecha de pago vencida;
- lo que está tildado como pagado;
- lo cancelado en Comercio Exterior (el pendiente es cero);
- una orden de compra que repite un contenedor que ya está en otra fila.

:::formula
Pendiente (U$S) = FOB total − Pagado en Comercio Exterior    (nunca menos de cero)
Importe ($) = Pendiente (U$S) × dólar aplicado
Dólar aplicado = cotización a mano, si hay; si no, BCRA vendedor del día hábil anterior para los próximos días y lo vencido; si no, curva ROFEX del mes de pago
:::

:::ejemplo ilustrativo
Un contenedor con FOB de **U$S 100.000**. Comercio Exterior registró un anticipo de **U$S 30.000**.

- Pendiente: 100.000 − 30.000 = **U$S 70.000**.
- Se paga dentro de cuatro meses, y la curva dice **$ 1.500** para ese mes.
- Importe ($): 70.000 × 1.500 = **$ 105.000.000**, en la columna de su fecha de pago.

Otro contenedor tiene **U$S 20.000** pendientes y se paga pasado mañana. Va al dólar BCRA vendedor del día hábil anterior, digamos **$ 1.450**: 20.000 × 1.450 = **$ 29.000.000**.

Entre los dos, la fila del tablero suma **$ 134.000.000**. Si el primero se tildara como pagado, la fila bajaría a $ 29.000.000.
:::

### Con qué dólar se valúa

**La tabla está en pesos**, porque el cashflow es en pesos. Las tres columnas en dólares quedan como referencia: son las que se comparan contra la factura del proveedor y contra la pantalla de pagos de Comercio Exterior.

Cada contenedor se valúa con el dólar del mes en que se paga, no con un número para todos. Dos contenedores que se pagan en meses distintos llevan dos dólares distintos, y la columna **Dólar aplicado** dice cuál le tocó a cada uno.

- **Curva ROFEX**: el valor del contrato del mes de pago, con su símbolo debajo (por ejemplo `DLR/NOV26`).
- **Dólar BCRA**: para los pagos de los próximos días y los vencidos. Es el vendedor del día hábil anterior: un lunes usa el del viernes; si ese día no tiene cotización, la última anterior. Debajo del valor dice `BCRA` y la fecha.
- **Mes fuera de la curva**: si el pago cae en un mes que la curva no publica, se usa el mes más cercano que tenga y la celda queda marcada. El importe es una aproximación.
- **Corregida a mano**: alguien le cargó una cotización a ese contenedor. Manda sobre la curva y sobre el BCRA. Debajo del valor dice "a mano".
- **Sin fecha estimada de pago no se valúa**: no hay mes al que pedirle cotización. La celda de pesos muestra un guión y no un cero, aunque el contenedor tenga una cotización corregida a mano.

Cuántos días cuentan como "próximos" se cambia en Parámetros (ver la sección Parámetros). En 0, todo va con la curva.

En Crono Nacionalización la regla es la misma pero con el dólar divisa: ver [Crono Nacionalización](ayuda:crono_nacionalizacion#con-que-dolar-se-valua-la-nacionalizacion).

## La pantalla

La pantalla, de arriba hacia abajo:

- Los avisos de la pestaña: los de la grilla y los que el tablero dice de ella.
- Las tres tarjetas, una por vista.
- El buscador y los interruptores **Ver vencidas** y **Ver pagados**, con lo que esconde cada uno.
- Los botones de vista, **Columnas fijas**, **Actualizar** y **Exportar**.
- La línea que dice qué período se mide y con qué dólar está valuada la tabla.
- Las columnas de cada contenedor, de **Proveedor** a **Pagado**.
- Las columnas del período, con su total arriba de cada fecha, y **Total**.
- El pie **TOTALES**.

### Las tarjetas

| Tarjeta | Qué suma |
| --- | --- |
| **Vista Días** | Lo que cae en el tramo diario. Debajo dice de qué día a qué día. |
| **Vista Meses** | Lo que cae en las columnas mensuales, después del tramo diario. |
| **Período Completo** | Todo el horizonte: Vista Días más Vista Meses. |

Las tarjetas miden el cronograma completo: no cambian con el buscador ni con los interruptores.

### Las tres vistas

Los botones **Días**, **Meses** y **Período completo** cambian las columnas del período:

- **Días**: una columna por día, desde hoy. Cuántas, lo dice **Columnas diarias** de Parámetros.
- **Meses**: una columna por mes, después del tramo diario. Cuántas, lo dice **Columnas mensuales**.
- **Período completo**: las dos juntas, en orden.

Un importe va a un día **o** a un mes, nunca a los dos. Por eso la vista Meses no cubre el horizonte entero: el primer mes sólo junta los días que quedaron fuera del tramo diario, y su encabezado lo avisa al pasar el mouse. La columna **Total** suma sólo las columnas de la vista que estás mirando.

Los sábados, domingos y feriados tienen la columna en gris suave. Las celdas con importe van en azul claro.

### Las columnas

| Columna | Qué muestra |
| --- | --- |
| **Proveedor** | El proveedor del exterior. Si el nombre es largo, completo al pasar el mouse. |
| **Contenedor** | El contenedor. Puede llevar la etiqueta **Costos cargados**. |
| **Orden Compra** | La orden de compra. |
| **Despachante** | El despachante. |
| **FOB total (USD)** | Lo que vale el contenedor según Comercio Exterior. |
| **Pagado (USD)** | Lo que ya se le pagó, según los pagos cargados en Comercio Exterior. Con pagos, lleva al lado cuántos son y se abre con un clic. |
| **Pendiente (USD)** | FOB total menos Pagado: lo que se proyecta. Puede llevar una etiqueta de estado. |
| **ETD** | La fecha de embarque, con **Conf** o **Est**. |
| **ETA** | La fecha de llegada, con **Conf** o **Est**. |
| **Fecha Est. Pago** | Cuándo se le paga al proveedor. Se edita con un clic. |
| **Dólar aplicado** | La cotización con la que se pasó a pesos, y de dónde sale. Se corrige con un clic. |
| **Importe ($)** | Pendiente por dólar aplicado. Un guión si no se pudo valuar. |
| **Pagado** | El tilde de "este pago ya se hizo". |

La tabla se ordena haciendo clic en el encabezado de cualquiera de estas columnas o de **Total**. **Columnas fijas** elige qué columnas quedan quietas al moverte a lo ancho; arrancan **Proveedor** y **Contenedor**.

### Las marcas de la fecha

| Marca | Qué quiere decir |
| --- | --- |
| **Manual** | La fecha de pago está fijada a mano: el cálculo automático de Comercio Exterior (embarque + 5 días) no la pisa. Al pasar el mouse dice quién la fijó y cuándo; si la movieron desde acá, también qué decía antes. |
| **Vencida** | La fecha ya pasó y el pago no se hizo. Ese importe no entra en ninguna columna del período hasta que se le cargue una fecha nueva. |
| **Sin fecha** | No hay fecha cargada: no hay dónde ubicar el importe ni mes para valuarlo. |
| Fila rosada | Toda la fila de una vencida, para encontrarla sin volver hasta la columna de la fecha. |

Una fecha vencida de un pago que ya se hizo (tildado, o cancelado en Comercio Exterior) no lleva la marca **Vencida**: no hay nada que corregir.

### Las marcas de la columna Dólar aplicado

| Marca | Qué quiere decir |
| --- | --- |
| Sin marca, con el símbolo del contrato | Curva ROFEX del mes de pago. |
| Borde verde y un ícono de banco, con `BCRA` y la fecha | Se paga en los próximos días o ya venció: dólar BCRA vendedor de esa fecha. |
| Borde violeta punteado y un ícono de rama | El mes de pago no está en la curva: se usó el mes más cercano. |
| Fondo amarillo, borde naranja y un lápiz, con "a mano" | Cotización corregida a mano para este contenedor. Al pasar el mouse dice quién y cuándo. |

El texto completo de por qué se usó esa cotización está al pasar el mouse sobre la celda.

### Las etiquetas del pendiente y del contenedor

| Etiqueta | Qué quiere decir |
| --- | --- |
| **Parcial** | Tiene pagos cargados en Comercio Exterior y todavía queda saldo: se proyecta lo que falta, no el FOB. |
| **Cancelado** | Los pagos cubren el FOB. No queda nada que proyectar y la fila sale sola del período, sin tildarla. La fila va en verde suave. |
| **Sobrepago** | Hay cargado más que el FOB. El pendiente se toma como cero; la diferencia se corrige en Comercio Exterior. |
| **Sin FOB** | El contenedor no tiene FOB cargado en Comercio Exterior: no se proyecta nada. |
| **Repetido** | Otra orden de compra del listado es del mismo contenedor. El importe lo lleva la otra fila; ésta va en cero. La fila va en gris y en cursiva. |
| **Costos cargados** | Comercio Exterior ya cargó los costos de nacionalización. Sigue acá porque al proveedor todavía se le debe; sale sola cuando el FOB queda pagado. |

Las columnas **ETD** y **ETA** llevan **Conf** (en verde) cuando la fecha está confirmada y **Est** (en amarillo) cuando es estimada. ETD es confirmada cuando Comercio Exterior cargó la fecha real de embarque; si no, se muestra la estimada. ETA es confirmada cuando Comercio Exterior la marcó como confirmada.

### Los interruptores

**Ver vencidas** y **Ver pagados** arrancan apagados: al abrir no se ven las vencidas ni las tildadas como pagadas. Al cashflow entra lo que falta mover de hoy en adelante, y ninguna de las dos suma en ninguna columna del período.

Al lado de cada interruptor dice siempre cuántas filas esconde, por ejemplo "3 vencidas escondidas" o "se ven los 2 pagados". Prendelos para corregir una fecha o para destildar lo que se marcó por error.

Esconderlas no cambia las tarjetas ni los totales de las columnas del período. Sí cambian los totales del pie de **FOB total (USD)**, **Pagado (USD)**, **Pendiente (USD)** e **Importe ($)**, que suman las filas que se están viendo.

Los contenedores cancelados en Comercio Exterior no tienen interruptor: se ven siempre, en verde.

### El tilde de pagado

La columna **Pagado** dice que ese pago ya se hizo. Tildarlo lo saca de la proyección y la fila del tablero deja de contarlo. Cada clic guarda: no hay que confirmar nada.

- Saca de la proyección **lo pendiente**, no el FOB: lo que ya se había pagado en Comercio Exterior ya estaba afuera.
- El importe no se pierde: se destilda con el mismo clic y vuelve.
- Queda registrado quién lo marcó y cuándo; se ve al pasar el mouse sobre el tilde.
- Es un dato del cashflow: Comercio Exterior no se entera.
- Son dos tildes por contenedor, uno acá y otro en Crono Nacionalización, y marcar uno no dice nada del otro.
- Una fila tildada se ve atenuada, y con **Ver pagados** apagado deja de verse.

Hay otra forma de que un pago salga de la proyección, que no depende de nadie de este lado: que Comercio Exterior cargue pagos hasta cubrir el FOB. Ese contenedor queda **Cancelado**.

### El pie y la línea del dólar

El pie **TOTALES** suma, sobre las filas que se están viendo, las tres columnas en dólares, **Importe ($)** y cada columna del período. Arriba de cada fecha va el mismo total.

El total de **Importe ($)** incluye lo vencido y lo que cae después del último mes, así que puede no coincidir con el de las columnas del período. La diferencia está en los avisos.

Debajo de los botones, una línea dice qué período se está midiendo y con qué dólar está valuada la tabla: "Valuado con dólar futuro ROFEX, curva hasta …", cuándo se actualizó la curva y la regla del dólar de los próximos días. Si la curva no se pudo leer, en rojo: "Sin curva de dólar futuro: los importes no se pueden expresar en pesos."

### Qué pasa cuando guardás una fecha

- **Se escribe en el maestro de Comercio Exterior.** No es una copia del cashflow: hay una sola fecha, y la ve también esa aplicación. La notificación lo dice: "Fecha actualizada en el maestro de Comercio Exterior: la ve también esa aplicación."
- **Queda un rastro**: quién la cambió, cuándo y qué decía antes. Se puede cambiar cuantas veces haga falta; cada cambio queda guardado.
- **La fecha de pago queda fijada a mano** (marca **Manual**): el cálculo automático de Comercio Exterior ya no la pisa.
- **Desde acá no se puede vaciar.** Si hay que borrarla, se hace en Comercio Exterior.
- **Si el pago pasa a otro mes y tenía una cotización corregida a mano, esa cotización se descarta** y el contenedor vuelve a la curva del mes nuevo. La notificación lo avisa, con otro color.
- **La tabla se vuelve a cargar sola**, con la columna, el dólar y los avisos nuevos.

Si no tenés permiso de edición sobre la pestaña, las fechas, el tilde y la cotización se ven pero no se pueden cambiar. Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Tareas frecuentes

### Editar una fecha

:::pasos
1. Hacé clic en la celda de **Fecha Est. Pago** del contenedor. Aparece un selector de fecha.
2. Elegí la fecha nueva.
3. Apretá **Enter** o hacé clic afuera de la celda para guardar. **Esc** cancela sin guardar.
Resultado: la fecha queda en el maestro de Comercio Exterior, marcada **Manual**, y la tabla se recarga con el importe en su columna nueva.
:::

Si elegís la misma fecha que ya tenía, no se guarda nada y la fecha no queda fijada.

### Corregir la cotización de un contenedor

:::pasos
1. Hacé clic en la celda de **Dólar aplicado** del contenedor.
2. Escribí la cotización. Acepta coma decimal, por ejemplo `1.450,75`.
3. Apretá **Enter**, o hacé clic afuera de la celda.
Resultado: ese contenedor se valúa con esa cotización y la celda queda marcada "a mano". La curva no cambia: es sólo para esa fila.
:::

### Volver a la curva

:::pasos
1. Hacé clic en la celda de **Dólar aplicado** que dice "a mano".
2. Borrá el valor y dejá el campo vacío.
3. Apretá **Enter**.
Resultado: la notificación dice "La cotización vuelve a salir de la curva de dólar futuro." y la fila se valúa otra vez con la regla de siempre.
:::

Es la única forma de deshacer una corrección. Mover la fecha de pago a otro mes también la descarta, sola.

### Tildar un pago como hecho

:::pasos
1. Buscá el contenedor.
2. Tildá la casilla de la columna **Pagado**.
Resultado: la notificación dice "Marcado como pagado: sale de la proyección y la fila del tablero deja de contarlo." La fila se esconde si **Ver pagados** está apagado.
:::

### Destildar un pago marcado por error

:::pasos
1. Prendé **Ver pagados**.
2. Buscá la fila, que se ve atenuada.
3. Sacale el tilde de la columna **Pagado**.
Resultado: la notificación dice "Vuelve a la proyección: la fila del tablero lo cuenta otra vez."
:::

### Ver de qué pagos sale "Pagado (USD)"

:::pasos
1. Hacé clic en la celda de **Pagado (USD)** del contenedor. Sólo se abre si tiene pagos: al lado del importe dice cuántos.
2. Mirá la cuenta de arriba (FOB total − pagado = pendiente) y la lista de pagos: **Fecha**, **Forma**, **Medio**, **Monto (USD)** y **Cargado el**.
Resultado: ves cada pago que Comercio Exterior cargó para ese contenedor. Es sólo lectura: los pagos se cargan en Comercio Exterior.
:::

Un pago que se cargó en pesos y Comercio Exterior pasó a dólares lleva un ícono de flechas; al pasar el mouse dice cuánto era en pesos.

### Corregir una fecha vencida

:::pasos
1. Prendé **Ver vencidas**. Aparecen las filas rosadas.
2. Si el pago ya se hizo, tildalo en **Pagado**. Si no, hacé clic en su **Fecha Est. Pago** y cargale la fecha nueva.
Resultado: la fila deja de figurar como vencida. Con fecha nueva, el importe entra en su columna; tildada, sale de la proyección.
:::

### Buscar un contenedor

:::pasos
1. Escribí en el buscador parte del proveedor, del contenedor o de la orden de compra.
Resultado: quedan sólo las filas que coinciden y el pie suma sólo ésas. Las tarjetas no cambian.
:::

El buscador no mira importes ni fechas. No distingue mayúsculas, pero sí acentos.

### Exportar lo que se ve

:::pasos
1. Dejá la tabla como la querés: vista, buscador e interruptores.
2. Tocá **Exportar**.
Resultado: se baja un Excel con las filas que se están viendo, ni una más.
:::

## Parámetros

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Días con dólar del día anterior** | Parámetros › Compras Exterior | 7 | Lo que se paga dentro de estos días, o ya venció, va al dólar BCRA vendedor del día hábil anterior; lo posterior, a la curva. En 0, todo va con la curva. Mueve el tablero. |
| **Antigüedad máxima del dólar del día anterior** | Parámetros › Compras Exterior | 3 | Si la última cotización BCRA tiene más de estos días, se usa igual y la pantalla avisa. No cambia ningún número. |
| **Contenedores desde (fecha de movimiento)** | Parámetros › Compras Exterior | 01/01/2025 | Qué contenedores entran: los de fecha de movimiento igual o posterior. No puede ser una fecha futura. |
| **Columnas diarias** | Parámetros › Calendario | 28 | Cuántos días tiene la vista Días. Subirlo pasa importes de una columna mensual a una diaria. |
| **Columnas mensuales** | Parámetros › Calendario | 12 | Cuántos meses tiene la vista Meses. Lo que cae después del último queda fuera del período. |
| Feriados bancarios | Parámetros › Calendario | Los del calendario bancario | Qué día es el "día hábil anterior" del dólar BCRA, y qué columnas diarias van en gris. |

Ver [permisos y parámetros](ayuda:general_permisos_parametros).

## Avisos y qué hacer

Los avisos se ven arriba de la tabla, con los que el tablero dice de esta pestaña. Ver [cómo se leen los avisos](ayuda:general_avisos).

:::aviso atencion
texto: N contenedor(es) por $ X tienen la fecha estimada de pago ya vencida, así que NO suman en ninguna columna del período. No se los reubica en hoy, porque nadie afirmó que ese importe se mueve hoy: cargales la fecha nueva y entran solos.
significa: Pagos con la fecha pasada que nadie tildó. No están en el tablero.
corrige: Prendé **Ver vencidas**: si ya se pagaron, tildalos; si no, cargales la fecha nueva.
:::

:::aviso atencion
texto: N contenedor(es) por U$S X no tienen fecha estimada de pago, así que no hay mes al que pedirle cotización y no se pueden valuar en pesos. No se les aplica ningún tipo de cambio inventado: cargales la fecha y el importe aparece.
significa: Pendientes sin fecha. No están en el tablero, y el monto va en dólares porque no se pudo pasar a pesos.
corrige: Cargales la **Fecha Est. Pago**, acá o en Comercio Exterior.
:::

:::aviso atencion
texto: N contenedor(es) tienen cargado en Comercio Exterior MÁS de lo que dice su FOB: U$S X de más. El pendiente de esos se toma como CERO —el cashflow no proyecta egresos negativos, que serían un ingreso que nadie afirmó— así que la diferencia no se ve en ninguna columna. Revisá en Comercio Exterior si hay un pago imputado al contenedor equivocado o un FOB desactualizado.
significa: Los pagos superan el FOB. Las filas llevan la etiqueta **Sobrepago**.
corrige: En Comercio Exterior: el pago o el FOB.
:::

:::aviso info
texto: N contenedor(es) ya tienen pagos cargados en Comercio Exterior, así que acá se proyecta sólo lo que FALTA pagar: $ X que la fila del tablero ya no cuenta. (Si corresponde: N de ellos quedaron CANCELADOS —saldo cero— y salieron del cuadro enteros, sin que nadie los tildara.)
significa: Plata que salió de la proyección porque se cargó un pago en Comercio Exterior, sin que nadie hiciera nada acá.
corrige: Nada. El detalle está en **Pagado (USD)**.
:::

:::aviso info
texto: N contenedor(es) tienen el pago marcado como YA HECHO, así que salieron de la proyección: $ X que la fila del tablero ya no cuenta. El importe no se perdió —sale por su propia serie— y se destilda desde la pestaña si se marcó por error.
significa: Lo tildado en **Pagado**. Si algunos ya estaban vencidos, el aviso lo dice: esos no sumaban y no entran en ese importe.
corrige: Nada, salvo que haya un tilde por error: prendé **Ver pagados** y destildalo.
:::

:::aviso info
texto: N contenedor(es) caen en un mes que la curva de dólar futuro no cubre (llega hasta …), así que se valuaron con la cotización del mes más cercano. Están marcados en la grilla.
significa: El importe en pesos es una aproximación. Los marca el borde violeta punteado.
corrige: Nada. Cuando la curva publique ese mes, se valúa solo.
:::

:::aviso info
texto: N contenedor(es) tienen la cotización corregida a mano, que manda sobre la curva. Están marcados en la grilla.
significa: Alguien le cargó una cotización a esos contenedores.
corrige: Si ya no corresponde, vaciá la celda de **Dólar aplicado**.
:::

:::aviso info
texto: N orden(es) de compra son del mismo contenedor que otra fila del listado. El FOB y los pagos son del contenedor, no de cada orden, así que el importe lo proyecta UNA sola fila —la de la orden principal— y estas van en cero para no contar el mismo egreso dos veces. Están marcadas en la grilla.
significa: Las filas con la etiqueta **Repetido**.
corrige: Nada: es cómo Comercio Exterior arma un contenedor con varias órdenes.
:::

:::aviso info
texto: Fila: $ X con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Pagos posteriores al último mes del período. Existen, pero quedan después del horizonte.
corrige: Si los querés ver, subí **Columnas mensuales** en Parámetros › Calendario.
:::

:::aviso atencion
texto: Fila: $ X sin fecha asignada no se pueden ubicar en el tablero.
significa: Importe sin fecha que no puede ir a ninguna columna.
corrige: Cargale la fecha al contenedor.
:::

Si el tablero no responde, esos dos últimos salen con el texto de la grilla: "$ X con fecha fuera del horizonte no se muestran en la tabla." y "$ X sin fecha asignada no se pueden ubicar en el tiempo y quedan fuera de la tabla."

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción.

| Mensaje | Cuándo |
| --- | --- |
| "Fecha actualizada en el maestro de Comercio Exterior: la ve también esa aplicación." | Se guardó la fecha. |
| "Se descartó la cotización cargada a mano" | Se guardó la fecha, el pago cambió de mes y tenía cotización a mano. Dice de qué mes a qué mes y qué cotización se descartó. |
| "No se pudo guardar la fecha: …" | Falló. La celda vuelve a lo que decía y no se escribió nada. |
| "Este contenedor se valúa a $ X por dólar. La curva no cambia: es sólo para esta fila." | Se guardó la cotización a mano. |
| "No se pudo guardar la cotización: …" | Falló: por ejemplo, un valor que no es un número mayor a cero. |
| "No se pudo guardar el tilde de pagado: …" | Falló. El tilde vuelve solo a donde estaba. |

Los mensajes de error no se cierran solos: el texto del servidor es lo que explica qué pasó.

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No se encuentra la tabla … de Comercio Exterior, así que no se puede saber qué se le pagó ya a cada proveedor del exterior: esta pantalla y la fila del tablero proyectan el FOB COMPLETO de cada contenedor, que es de más si hay pagos hechos.
significa: No se pudieron leer los pagos cargados en Comercio Exterior. Se proyecta de más: plata que quizás ya salió.
corrige: Es de la otra aplicación: avisale a quien administra la base.
:::

:::aviso critico
texto: No se pudo leer la curva de dólar futuro ROFEX (…), así que los pagos al exterior no se pueden pasar a pesos: la pestaña los muestra en dólares y la fila del tablero va en cero.
significa: Sin curva no hay a cuánto convertir. Las columnas en dólares están; las de pesos, no.
corrige: Avisale a Sistemas. Cuando la curva vuelve, se valúa solo.
:::

:::aviso critico
texto: N contenedor(es) por U$S X se pagan en los próximos días y van a dólar BCRA vendedor, pero no hay ninguna cotización publicada, así que no se pueden valuar en pesos y quedan fuera del tablero. No se usa la curva en su lugar.
significa: Son los pagos más cercanos, y no entran al tablero por falta de cotización.
corrige: Avisale a Sistemas que falta la cotización BCRA.
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
texto: La última cotización del dólar BCRA es del dd/mm/aaaa; se usa ésa para los pagos de los próximos N días.
significa: La cotización BCRA tiene más días que **Antigüedad máxima del dólar del día anterior**. Se usa igual.
corrige: Avisale a Sistemas que no se está cargando el dólar BCRA.
:::

:::aviso atencion
texto: No se pudo leer el dólar BCRA para los pagos de los próximos N días: (el motivo)
significa: Falló la lectura del dólar BCRA. Esos pagos quedan sin valuar y lo cuenta el aviso crítico de arriba.
corrige: Avisale a Sistemas con el motivo.
:::

:::aviso atencion
texto: La edición de fechas está apagada: falta la tabla …. Corré … contra la base central y las fechas se vuelven editables solas.
significa: Falta instalar algo del cashflow. Todo se lee igual; lo único que no se puede es cambiar fechas.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: El tilde de «pagado» está apagado: falta la tabla …. Corré … contra la base central y la columna se vuelve marcable sola.
significa: La casilla **Pagado** se ve deshabilitada. Mientras tanto no hay nada marcado y el tablero proyecta todo lo pendiente.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: La corrección manual de la cotización está apagada: falta la columna …. Corré … contra la base central y la columna se vuelve editable sola.
significa: **Dólar aplicado** no se puede corregir. Los pagos se valúan con la curva igual.
corrige: Sistemas, corriendo el script que nombra el aviso.
:::

:::aviso atencion
texto: No se pudieron traer los avisos del tablero para esta pestaña.
significa: Se ven los avisos de la pestaña, pero no los que el tablero dice de ella.
corrige: Probá con **Actualizar**. Si sigue, avisá a sistemas.
:::

## Preguntas frecuentes

:::pregunta ¿Por qué no se usa un tipo de cambio único para todos los contenedores?
Antes había un tipo de cambio único, cargado a mano, con el que se convertía igual el pago del mes que viene y el de dentro de once meses. Eso no es proyectar: es pasar todo a dólar de hoy.

Ahora cada contenedor se valúa con el dólar del mes en que se paga, que el mercado ya publica en la curva ROFEX. Ese tipo de cambio único se retiró y no convive con la curva: dos criterios a la vez darían dos números distintos para el mismo contenedor.
:::

:::pregunta ¿Por qué un contenedor aparece sin importe en pesos?
Un guión en **Importe ($)** quiere decir que no se pudo valuar; el motivo está al pasar el mouse. Casi siempre es que no tiene **Fecha Est. Pago**: sin fecha no hay mes, y sin mes no hay cotización.

No se le pone ningún tipo de cambio inventado, ni un cero, que se leería como "este contenedor no se paga". El aviso de arriba dice cuántos son y cuánto suman en dólares. Cargale la fecha y el importe aparece.

Si la fila tiene importe pero las columnas del período están vacías, es otra cosa: está vencida, tildada, cancelada o repetida.
:::

:::pregunta ¿Por qué un pago vencido no suma en ninguna columna?
Al cashflow entra lo que se paga de hoy en adelante. Si la fecha ya pasó, o el pago se hizo (y entonces no es proyección) o no se hizo y hay que corregirle la fecha.

Tampoco se lo pasa a la columna de hoy: nadie afirmó que ese pago sale hoy. Hasta que alguien lo tilde o le cargue una fecha nueva, el aviso de vencidos dice cuántos son y cuánto valen.
:::

:::pregunta ¿Por qué el tablero proyecta menos que el FOB?
Porque se proyecta lo que falta pagar. Si Comercio Exterior cargó un anticipo, ese anticipo ya salió y no se vuelve a proyectar. La columna **Pagado (USD)** dice cuánto, y con un clic, de qué pagos sale.
:::

:::pregunta ¿Puedo cargar un pago al proveedor desde acá?
No. Los pagos al proveedor del exterior se cargan en Comercio Exterior, que es dueño de ese circuito. Acá se miran. Si el pago ya se hizo y no está cargado allá, podés tildarlo en **Pagado** para sacarlo de la proyección.
:::

:::pregunta ¿Por qué sigue apareciendo un contenedor que ya tiene los costos cargados?
Porque al proveedor todavía se le debe plata. Un contenedor sale de esta pestaña cuando tiene los costos de nacionalización cargados **y** el FOB pagado. Es la misma regla de Gestión de Despachos en Comercio Exterior. Esos contenedores llevan la etiqueta **Costos cargados**.
:::

:::pregunta ¿Por qué los pagos de los próximos días no van con la curva?
Porque se pagan al tipo de cambio del momento. La curva del mes en curso ya trae una devaluación esperada que en una semana no ocurre. Por eso lo que se paga en los próximos días, y lo vencido, va al dólar BCRA vendedor del día hábil anterior.
:::

:::pregunta ¿Por qué la punta vendedora?
Porque es la que se paga para girarle a un proveedor del exterior.
:::

:::pregunta ¿Qué pasa con la cotización a mano si muevo la fecha de pago?
Si la fecha nueva cae en otro mes, se descarta y el contenedor vuelve a la curva del mes nuevo. Una cotización a mano es una afirmación sobre un mes: conservarla valuaría el mes nuevo con un número pensado para el viejo. La notificación lo avisa. Si la fecha nueva cae en el mismo mes, la cotización se conserva.
:::

:::pregunta ¿Esconder las vencidas o las pagadas cambia los totales?
No cambia las tarjetas ni los totales de las columnas del período, porque esas filas ya valían cero ahí. Sí cambian los totales del pie de las columnas en dólares y de **Importe ($)**, que suman lo que se ve.
:::

:::pregunta ¿Por qué no veo contenedores viejos?
Porque sólo entran los que tienen fecha de movimiento desde **Contenedores desde (fecha de movimiento)**, en Parámetros › Compras Exterior. Lo anterior no se muestra ni se avisa.
:::

:::pregunta ¿Escribir la misma fecha la deja fijada como Manual?
No. Si la fecha que elegís es la que ya estaba, no se guarda nada. Para fijarla hay que moverla, o fijarla desde Comercio Exterior.
:::

:::pregunta ¿El flete y el seguro se proyectan acá?
No. Esta pestaña proyecta el FOB, y Crono Nacionalización los gastos de nacionalización. El flete internacional y el seguro no los proyecta ninguna de las dos. Está pendiente que Comercio Exterior confirme si salen por otro circuito.
:::

## Glosario

| Término | Qué es |
| --- | --- |
| **FOB** | El valor de la mercadería puesta en el puerto de origen. Es lo que se le paga al proveedor del exterior, en dólares. |
| **Pagado** | Lo que ya se le pagó al proveedor, según los pagos cargados en Comercio Exterior. |
| **Pendiente** | FOB menos pagado. Es lo único que se proyecta. Nunca es negativo. |
| **Fecha estimada de pago** | Cuándo se le paga al proveedor. La calcula Comercio Exterior (embarque + 5 días) salvo que esté fijada a mano. |
| **Fecha fijada a mano** | Una fecha de pago que el cálculo automático no pisa. Lleva la marca **Manual**. |
| **Vencida** | Una fecha que ya pasó en un pago que no se hizo. No suma. |
| **Cancelado** | Contenedor cuyo FOB está cubierto por los pagos cargados en Comercio Exterior. |
| **Sobrepago** | Lo cargado supera el FOB. |
| **Curva ROFEX** | Los precios del dólar futuro, un contrato por mes (`DLR/NOV26` es noviembre de 2026). Dicen a cuánto se puede comprar hoy un dólar de ese mes. |
| **Dólar BCRA vendedor** | La cotización oficial del BCRA, punta vendedora. Se usa para lo que se paga en los próximos días o ya venció. |
| **Día hábil anterior** | El último día hábil antes de hoy: un lunes, el viernes. De ese día sale el dólar BCRA. |
| **Dólar divisa** | Otra cotización, la que usa Crono Nacionalización para los próximos días. Ver [Crono Nacionalización](ayuda:crono_nacionalizacion#glosario). |
| **Nacionalización** | El trámite de aduana para ingresar la mercadería, con sus impuestos y gastos. Se proyecta en Crono Nacionalización. |
| **Costos cargados** | Comercio Exterior ya cargó los costos reales de nacionalización del contenedor. |
| **Orden de compra principal** | Cuando un contenedor tiene varias órdenes de compra, la que lleva el FOB y los pagos. |
| **ETD / ETA** | Fecha de embarque / fecha de llegada. |
| **Tramo diario** | Las columnas de días de la vista Días. |
| **Horizonte** | El período que proyecta el cashflow: el tramo diario más los meses. |
