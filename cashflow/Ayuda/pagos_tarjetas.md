---
titulo: Pagos con Tarjetas y Otros
pestana: pagos_tarjetas
categoria: Financiero
estado: completa
actualizado: 2026-11-24
usuarios: Tesorería, Administración
bajada: Lo que se paga con tarjeta de crédito, en qué fecha: los gastos de las supervisoras, las facturas y los seguros de las tarjetas corporativas, y las tarjetas de los socios.
---

## Para qué sirve

Muestra cuánto se va a pagar con tarjetas de crédito, y en qué fecha. Tiene tres sub-pestañas, que son tres circuitos distintos:

- **Gastos Supervisoras**: estima lo que gastan las supervisoras, con el promedio de sus gastos autorizados de los últimos tres meses. Una parte se les paga en efectivo y otra sale en el débito de su tarjeta.
- **Tarjetas Pagos Corporativos**: las facturas de Tango que se pagan con tarjeta corporativa, en el débito de su tarjeta. Adentro están también las **estimaciones mensuales** de los proveedores que se repiten todos los meses y los **seguros**, que se proyectan con sus pólizas.
- **Tarjetas Socios**: estima lo que se paga de las tarjetas de los socios, banco por banco, con el promedio de sus últimos tres resúmenes.

Las tres comparten el maestro de tarjetas de Parámetros › Tarjetas y los **resúmenes**: cuando llega el resumen del banco, se carga, y su importe reemplaza a lo estimado de ese mes.

Lo que muestran las tres suma en una sola fila del tablero, **Pagos con Tarjetas y Otros**, en Costos Indirectos. Ver [cómo se lee el tablero](ayuda:general_tablero).

## De dónde salen los datos

:::diagrama
fuente: La app de supervisión | los gastos autorizados de cada supervisora, en efectivo y con tarjeta
fuente: El maestro de supervisoras | qué supervisoras están activas
fuente: Tango | las facturas pendientes con forma de pago TARJETA CORP
fuente: Parámetros › Tarjetas | las tarjetas, su día de vencimiento, su % de cobertura y los proveedores que proyectan
fuente: Los resúmenes y las pólizas cargados en esta pestaña | lo que el banco va a debitar y lo que se paga de cada seguro
fuente: Parámetros › Generales y Parámetros › Calendario | la inflación esperada, el cronograma de pagos y los días hábiles
fuente: El dólar BCRA y la curva de dólar futuro | para pasar a pesos lo que está en dólares
pestana: Pagos con Tarjetas y Otros | estima, ubica cada pago en el débito de su tarjeta y reemplaza lo estimado por los resúmenes
tablero: Fila Pagos con Tarjetas y Otros | supervisoras + corporativas + socios, columna por columna
alt: La app de supervisión, el maestro de supervisoras, Tango, Parámetros › Tarjetas, los resúmenes y pólizas, la inflación, el calendario y el dólar alimentan la pestaña Pagos con Tarjetas y Otros, que pasa la suma de sus tres partes a la fila Pagos con Tarjetas y Otros del tablero.
:::

- **La app de supervisión**: los gastos de cada supervisora que están **autorizados**, con lo que se pagó en efectivo y lo que se pagó con tarjeta. Los que no están autorizados no cuentan. El presupuesto de supervisión no se usa: dice cuánto se autorizó a gastar, y acá hace falta cuánto se va a gastar.
- **El maestro de supervisoras** (el de la app comercial): dice qué supervisoras están activas. Una inactiva no se proyecta.
- **Tango**: las facturas pendientes de cuentas a pagar, la misma lectura de Cuentas a Pagar Locales. Entran a Tarjetas Pagos Corporativos las que tienen forma de pago **TARJETA CORP**: la que se le puso a esa factura en Proveedores Locales o, si no tiene, la del maestro del proveedor.
- **Parámetros › Tarjetas**: cada tarjeta con su tipo (Supervisora, Corporativa o Socios por banco), su banco, su usuario, su día de vencimiento y su % de cobertura. Y la lista de **proveedores que proyectan**.
- **Lo que se carga en esta pestaña**: los resúmenes de cada tarjeta, el histórico manual de quien no carga gastos, la tarjeta de cada factura, las facturas excluidas, los vencimientos corregidos y las pólizas de seguros.
- **Parámetros › Generales**: la inflación mensual esperada, con la que se ajustan los importes estimados.
- **Parámetros › Calendario**: el cronograma de pagos de las supervisoras (la parte en efectivo) y los días hábiles, para correr los débitos que caen en un día no hábil.
- **El dólar BCRA y la curva de dólar futuro**: los mismos del resto del cashflow. Socios los usa para el componente en dólares de sus resúmenes, y Seguros para las pólizas en dólares.

No entran a Tarjetas Pagos Corporativos:

- las facturas de los proveedores de alquiler (Parámetros › Alquileres): se ven en la pestaña Alquileres;
- las de los proveedores excluidos de esta pestaña en Proveedores Locales › Maestro, columna **Tarjetas**;
- las de los proveedores de seguros (rubro **SEGUROS** y forma de pago **TARJETA CORP** en el maestro de Proveedores Locales): se proyectan con sus pólizas.

## Cómo se calcula

Las tres partes se calculan por separado y suman en la misma fila del tablero. Todo lo que muestra la pestaña es proyectado: lo que ya se debitó está en el saldo bancario. Ver [real y proyectado](ayuda:general_real_proyectado).

### El débito de una tarjeta

Cada tarjeta tiene un **día de vencimiento** en Parámetros › Tarjetas. El débito estimado de un mes es ese día:

- si el mes no tiene ese día (un 31 en un mes de 30), el último día del mes;
- si no es hábil, el **primer día hábil siguiente**.

Si la tarjeta tiene un resumen cargado ese mes, el débito es la fecha de vencimiento del resumen, tal cual, sin correrla.

Lo que tiene fecha de hoy o anterior no se proyecta: ese débito ya pasó.

### Los resúmenes

El resumen es lo que el banco va a debitar. Cuando se carga, **pisa la estimación de su mes**: va su importe, en su fecha, y lo estimado de ese mes deja de sumar.

- **El mes que pisa es el Período** que se carga, no el mes de la fecha de vencimiento. El importe sale en la columna de su fecha. Si los dos no coinciden, el mensaje al guardar lo avisa.
- **La cobertura no se aplica a un resumen**: es el importe real.
- **Un resumen marcado como pagado sale del horizonte**: esa plata ya está en el saldo bancario. Ese mes no vuelve a la estimación: queda en cero.
- **Un resumen dado de baja** no se borra: queda en el historial, y ese mes vuelve a proyectarse con la estimación.
- **Puede traer importe en $, en U$S o los dos**: la misma tarjeta tiene consumos en las dos monedas. Qué se hace con cada uno depende de la sub-pestaña (ver abajo).

### El resumen compartido

A veces el banco manda **un solo resumen para varias tarjetas de la misma cuenta**. Se carga una vez, eligiendo todas las tarjetas que incluye y marcando una como **titular**.

- **Pisa la estimación de todas** las tarjetas que incluye, en ese período.
- **Suma una sola vez, en la titular.** En las otras se ve marcado **compartido · no suma acá**.
- Sólo puede incluir tarjetas **Supervisora** y **Corporativa**, **activas**, **del mismo banco** y que **venzan el mismo día**.
- Darlo de baja lo saca de todas a la vez.

Si la titular es una tarjeta corporativa, el importe suma en Tarjetas Pagos Corporativos y en Gastos Supervisoras todas las tarjetas del grupo dicen **no suma acá**.

### Gastos Supervisoras

Se estima cuánto gasta cada supervisora por mes y se parte en efectivo y tarjeta.

- **La ventana** son los tres meses completos anteriores al mes en curso. Se mueve sola.
- **El promedio** es la suma de los gastos autorizados de la ventana dividida **siempre por 3**, aunque la supervisora tenga gastos en uno o dos meses: un mes sin gastos también es un dato.
- **La proporción** efectivo / tarjeta sale de la ventana entera, no de cada mes.
- **El mes base** es el último de la ventana. El promedio está en moneda de ese mes, y se ajusta por [la inflación](ayuda:gastos_locales#la-inflacion) compuesta desde el mes siguiente. Si falta la inflación de un mes del camino, ese mes y los siguientes quedan en blanco, no en cero.

:::formula
promedio = gastos autorizados de los 3 meses de la ventana ÷ 3
estimado del mes = promedio × inflación compuesta desde el mes siguiente al mes base
efectivo del mes = estimado × % efectivo, en partes iguales entre los pagos del cronograma Supervisoras de ese mes
tarjeta del mes = estimado × % tarjeta × (1 + % cobertura de su tarjeta), en el débito de su tarjeta
:::

**La parte en efectivo** va en partes iguales en los pagos del cronograma **Supervisoras** de Parámetros › Calendario (por defecto, todos los lunes, corridos al hábil siguiente). Los pagos del mes que ya pasaron también cuentan para dividir, pero no se proyectan. Cómo se arman esas fechas está en [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos).

**La parte con tarjeta** va en el débito de la tarjeta de la supervisora. La tarjeta se asocia por nombre: una tarjeta de tipo **Supervisora**, activa, cuyo usuario se llame igual que la supervisora en los gastos. No importan las mayúsculas; sí los acentos y los espacios de más.

- **Sin tarjeta a su nombre**, o **con más de una activa**, la parte con tarjeta no tiene fecha y no entra al tablero. Se avisa con el importe.
- **Si el débito de ese mes ya pasó** y no se cargó el resumen, no se proyecta: falta un dato, no es un pago que no va a salir.

**Un resumen reemplaza sólo la parte con tarjeta.** La parte en efectivo de ese mes se sigue proyectando igual. En Gastos Supervisoras **se usa sólo el importe en $ del resumen**: el importe en U$S no suma. Un resumen cargado sólo en dólares deja ese mes de la tarjeta en cero.

**Quién tiene fila**: cada nombre con gastos autorizados en la ventana, y cada tarjeta Supervisora activa aunque su usuario no tenga gastos. Las supervisoras inactivas en el maestro no aparecen. Una que no está en el maestro, o cuyos gastos suman cero, aparece pero no se proyecta. Quien no está en el maestro pero tiene tarjeta Supervisora activa se toma como activa.

**El histórico manual** es para quien tiene tarjeta Supervisora y no carga gastos en la app de supervisión. Se cargan tres meses de gasto con tarjeta; el promedio es la suma ÷ 3 (un mes en 0 también cuenta), todo con tarjeta, ajustado por inflación desde el último mes cargado, en el débito de su tarjeta y con su cobertura. Un resumen también lo pisa. **Mandan los gastos**: si la persona tiene gastos en la ventana, el histórico deja de usarse.

:::ejemplo ilustrativo
Una supervisora con estos gastos autorizados en la ventana:

| Mes | Efectivo | Tarjeta | Total |
| --- | ---: | ---: | ---: |
| Mes 1 | $ 300.000 | $ 700.000 | $ 1.000.000 |
| Mes 2 | $ 200.000 | $ 600.000 | $ 800.000 |
| Mes 3 | $ 400.000 | $ 800.000 | $ 1.200.000 |
| **Ventana** | **$ 900.000** | **$ 2.100.000** | **$ 3.000.000** |

- Promedio: $ 3.000.000 ÷ 3 = **$ 1.000.000** por mes. Proporción: 30 % efectivo, 70 % tarjeta.
- El mes siguiente al mes base tiene 2 % de inflación: estimado **$ 1.020.000**.
- Efectivo: 30 % = $ 306.000. Si el cronograma tiene cuatro lunes ese mes, son cuatro pagos de **$ 76.500**.
- Tarjeta: 70 % = $ 714.000. Su tarjeta tiene 5 % de cobertura: **$ 749.700**, el día de vencimiento de la tarjeta.

Si llega el resumen de ese mes por $ 800.000, la parte con tarjeta pasa a ser **$ 800.000**, en la fecha del resumen. Los cuatro pagos de $ 76.500 en efectivo siguen igual.
:::

### Tarjetas Pagos Corporativos

Son facturas reales de Tango. **Sólo entran al flujo las vinculadas a una tarjeta corporativa activa**: una factura sin tarjeta se ve en la grilla con su pendiente, pero no suma, esté vencida o no. Sin tarjeta no se sabe en qué débito sale.

Las facturas son sólo en pesos. Un resumen con importe en U$S en una tarjeta corporativa no se convierte: suma el importe en $.

**El vencimiento a considerar** es el de Tango o, si se corrigió en esta pestaña, el corregido. Corregirlo sólo vale acá: Tango y Cuentas a Pagar Locales no cambian. Se corrige por cuota.

**La fecha de pago** (columna **DÉBITO TARJETA**) de una factura vinculada es el **primer débito de su tarjeta a partir de su vencimiento a considerar**: el del mismo día, si coinciden, o el siguiente. Cada cuota busca el suyo. El débito de cada mes es el resumen cargado de la tarjeta, si lo hay, o el estimado.

- **Si ese débito ya pasó**, la factura sale en el **próximo pago de su tarjeta** (el primero después de hoy, entre resúmenes sin pagar y estimados). La grilla la marca **sale el dd/mm/aaaa**.
- **Si vence después del último débito del horizonte**, queda **sin fecha**.
- La fecha de pago cargada a mano en Proveedores Locales no se usa acá.

:::ejemplo ilustrativo
Una tarjeta corporativa vence el día 10. Una factura vinculada vence el 12 de marzo: el débito del 10 de marzo es anterior, así que sale en el del **10 de abril** (o el hábil siguiente, si ese día no es hábil). Si se corrige su vencimiento al 8 de marzo, pasa al débito del **10 de marzo**.
:::

**Cuándo suma una factura**, en este orden: si está **excluida**, no suma (va a una serie aparte, informativa); si su tarjeta tiene **resumen** cargado en su mes de pago, está **cubierta por resumen** y no suma aparte, porque el resumen ya la incluye; **sin tarjeta** no suma; **sin fecha** no suma; si no, **entra**.

**La cobertura** (*Cobertura gastos excepcionales*) es el % de cobertura de la tarjeta sobre todo lo que suma esa tarjeta en ese mes: las facturas que entran, las estimaciones mensuales y los seguros. Va en un renglón aparte, en el débito estimado de la tarjeta de ese mes. El % no multiplica las facturas: son deuda real de Tango. Una tarjeta con 0 % no tiene cobertura; un mes con resumen cargado tampoco, porque el resumen ya la incluye.

**Los proveedores que proyectan**

Un proveedor marcado en Parámetros › Tarjetas › **Proveedores que proyectan** repite sus facturas en los meses siguientes, en **Estimaciones mensuales**. Sirve para los abonos que llegan todos los meses y todavía no tienen la factura del mes que viene.

- Se repite **cada factura pendiente del proveedor que esté vinculada** a una tarjeta y no excluida. De una factura en cuotas, la última cuota. Si tiene dos facturas pendientes vinculadas, se repiten las dos.
- **Se repite todos los meses**, desde el mes siguiente al último mes de pago de la factura y hasta el fin del horizonte. No mira cada cuánto factura el proveedor: una factura bimestral también se repite todos los meses.
- **El importe** es el de la cuota en Tango (**IMPORTE BASE**), ajustado por la inflación compuesta desde el mes de pago de la factura. Si falta la inflación de un mes del camino, ese mes queda en blanco.
- **La fecha** es el débito de su tarjeta de cada mes.
- Cada mes dice su estado: **entra**, **cubierta por resumen** (la tarjeta tiene resumen ese mes), **ya pasó**, **sin tarjeta activa** o **falta inflación**.
- **Cuando se paga la factura, sale de pendientes y su repetición desaparece.** Si el proveedor no tiene otra factura pendiente vinculada, deja de proyectar hasta que llegue la nueva y se la vincule. No se usa la última pagada ni un histórico.

:::ejemplo ilustrativo
Un proveedor que proyecta tiene una factura vinculada de **$ 100.000** que se paga en marzo. La inflación esperada es 2 % en abril y 3 % en mayo.

- Abril: $ 100.000 × 1,02 = **$ 102.000**.
- Mayo: $ 100.000 × 1,02 × 1,03 = **$ 105.060**.

Cuando la factura se paga, las dos estimaciones desaparecen. Cuando llega la de abril y se vincula, se repite ésa desde mayo.
:::

### Seguros

Los proveedores de seguros son los que tienen rubro **SEGUROS** y forma de pago **TARJETA CORP** en el maestro de Proveedores Locales. Sus facturas de Tango no suman: se proyectan con las **pólizas** que se cargan en la sección **Seguros**.

:::formula
cuota = importe total de la vigencia ÷ (vigencia − gracia)
pagos = vigencia − gracia, uno por mes, seguidos, desde el mes del primer pago
:::

- **Cada cuota va en el débito de la tarjeta** de la póliza ese mes (el resumen cargado o el estimado).
- **En dólares**, la cuota se pasa a pesos con la curva de dólar futuro del mes del débito, la misma de [Proveedores Exterior](ayuda:proveedores_exterior#con-que-dolar-se-valua). Acá no se usa el dólar BCRA para los próximos días. Si la curva no llega a ese mes, se usa el mes más cercano y el tooltip lo dice como aproximado.
- **La gracia** son los meses del ciclo que quedan sin pago. Como los pagos van seguidos desde el primer pago, dónde caen depende del mes del primer pago: si el primer pago es el mes de inicio, quedan al final; si es más adelante, al principio.
- **Cuando la vigencia termina antes del fin del horizonte, el ciclo se repite como estimado** hasta que se cargue la póliza siguiente: mismo proveedor y mismo número o descripción, con inicio posterior al fin de ésta. Cada ciclo nuevo empieza una vigencia después, con su primer pago una vigencia después, y deja los mismos meses de gracia.
- **La continuación en dólares** repite el mismo importe. **En pesos**, el total del ciclo anterior más la **suma** de la inflación de los meses de la vigencia anterior (sumada, no compuesta). Los meses pasados sin inflación cargada se saltean; si falta uno del mes en curso en adelante, la continuación queda en blanco.
- Los pagos de una póliza generan cobertura y los pisa un resumen, como una factura vinculada.

:::ejemplo ilustrativo
Una póliza inventada: inicio el 1 de marzo de 2027, vigencia **12 meses**, gracia **2 meses**, primer pago en **marzo de 2027**, total **$ 1.200.000**, en una tarjeta que vence el día 10. La vigencia termina el 29 de febrero de 2028.

- Cuota: $ 1.200.000 ÷ (12 − 2) = **$ 120.000**.
- **Con cuota**: de marzo a diciembre de 2027 (diez pagos).
- **Gracia, sin pago**: enero y febrero de 2028.
- **Estimada** (no hay póliza siguiente cargada): el ciclo siguiente empieza el 1 de marzo de 2028, con cuotas de marzo a diciembre de 2028. Si la inflación de marzo de 2027 a febrero de 2028 fue 2 % cada mes, suma 24 %: el total pasa a $ 1.488.000 y la cuota a **$ 148.800**.
- Enero y febrero de 2029 vuelven a quedar sin pago, y en marzo de 2029 empieza otro ciclo estimado.

Si la misma póliza tuviera el primer pago en **abril de 2027**, las cuotas irían de abril de 2027 a enero de 2028, y el mes sin pago sería marzo de 2027 (al principio) y febrero de 2028.

Si se carga la póliza siguiente, con el mismo número y empezando el 1 de marzo de 2028, la estimada se corta y proyectan las cuotas de la nueva.
:::

### Tarjetas Socios

Va **por banco**, sin el nombre del socio: una fila por cada banco dado de alta con el tipo **Socios (por banco)** en Parámetros › Tarjetas.

- **La base** son los **últimos 3 resúmenes** del banco con período anterior al mes en curso, de cualquier origen (la base histórica o los resúmenes que se van cargando mes a mes). Se promedia en $ y en U$S por separado.
- **Con menos de 3 se divide por los que hay**, no por 3: un resumen que falta no es un mes sin consumos. Sin ninguno, el banco no estima.
- **El mes base** es el del resumen más reciente de la base.

:::formula
$ del mes = promedio en $ × (1 + % cobertura) × inflación compuesta desde el mes siguiente al mes base
U$S del mes = promedio en U$S × (1 + % cobertura)    (sin inflación)
total en pesos = $ del mes + U$S del mes × dólar
:::

- **La fecha** es el débito del banco de ese mes, con su día de vencimiento.
- **El dólar**: el **próximo vencimiento** del banco va al **dólar oficial del BCRA**, última cotización hasta hoy, punta compradora, porque es un pago inminente. Los siguientes, a la **curva de dólar futuro** del mes de su vencimiento (o el mes más cercano si la curva no llega).
- **La inflación ajusta sólo los pesos**: la curva de futuros ya incluye la devaluación esperada.
- **Un mes con resumen cargado** usa el resumen, en $ y en U$S, con su fecha, sin cobertura ni inflación. El U$S del resumen se convierte con la misma regla del dólar.
- Sin inflación, el componente en $ y el total quedan en blanco; sin cotización, el U$S en pesos y el total también.
- **Al tablero va sólo el total en pesos.**

:::ejemplo ilustrativo
Un banco de socios sin cobertura, con estos tres resúmenes de base: $ 300.000 y U$S 100; $ 400.000 y U$S 200; $ 500.000 y U$S 300.

- Base: **$ 400.000** y **U$S 200** por mes.
- Mes siguiente al mes base, con 2 % de inflación, y es el próximo vencimiento: $ 408.000 + U$S 200 × $ 1.000 (BCRA) = **$ 608.000**.
- El mes después, con otro 2 %: $ 416.160 + U$S 200 × $ 1.050 (dólar futuro de ese mes) = **$ 626.160**.
:::

### La fila del tablero

La fila **Pagos con Tarjetas y Otros** suma, columna por columna, Gastos Supervisoras (efectivo y tarjeta), Tarjetas Pagos Corporativos (facturas, cobertura, resúmenes, estimaciones mensuales y seguros) y Tarjetas Socios (el total en pesos). Las facturas excluidas van a una serie informativa que no suma.

No llegan al tablero: la parte con tarjeta sin tarjeta, las filas que no proyectan, los meses sin inflación o sin cotización, lo que tiene fecha de hoy o anterior, los resúmenes pagados, el U$S de un resumen de supervisora, la fila U$S de Socios, un resumen compartido en las tarjetas que no son titular, las facturas sin vincular, cubiertas o sin fecha, y las facturas de seguros.

Un resumen no es una fila aparte: va en la misma fila y la celda del tablero lo anota (por ejemplo "Resumen cargado de AAAA-MM: $ X, vence el dd/mm. Pisa la estimación de ese mes."). Las estimaciones mensuales y los seguros también se anotan, para que se vea qué parte no es una factura.

## La pantalla

La pantalla, de arriba hacia abajo:

- Los botones de las tres vistas, **Días**, **Meses** y **Período completo**, con el período que se mide al lado, y el botón **Actualizar**. Son uno solo para las tres sub-pestañas: cambiar de vista cambia las tres. Ver [las tres vistas](ayuda:general_tablero#las-tres-vistas).
- Las solapas **Gastos Supervisoras** (abre en ésta), **Tarjetas Pagos Corporativos** y **Tarjetas Socios**.
- Adentro de cada solapa, sus avisos, sus tarjetas, sus grillas.
- La grilla **Resúmenes de …**, cuando se abre desde la fila de una tarjeta.

Mientras carga dice "Calculando pagos con tarjetas…". Las tres sub-pestañas se calculan juntas, en un solo pedido.

### Lo común a las tres

- **Los avisos**: cada solapa tiene su panel, con los avisos generales de la pestaña, los de su parte y los que el tablero dice de ella. Ver [los avisos](ayuda:general_avisos).
- **Las columnas del eje**: arriba de cada fecha va el total de esa columna, y arriba de **TOTAL PERÍODO**, el total general. Las celdas van sin decimales y vacías en cero. Los sábados, domingos y feriados tienen su marca.
- **TOTAL PERÍODO** suma sólo las columnas que se están viendo. La vista Meses cubre sólo los días de fuera del tramo diario, así que su total no es el del horizonte completo.
- **El pie** dice **TOTALES** (en Socios, **TOTALES EN PESOS**).
- **Columnas fijas** y **Exportar** en cada grilla principal. Exportar baja un Excel con lo que se ve.
- **El ícono de resúmenes** (una factura con el signo $) en la fila de cada tarjeta o banco abre la grilla **Resúmenes de …**.
- Cada vez que se guarda algo, la pestaña entera se vuelve a calcular.

Sin permiso de edición, todo se ve pero no se puede cambiar. Ver [permisos y parámetros](ayuda:general_permisos_parametros#si-no-tenes-permiso-para-editar).

### La grilla de resúmenes

Es la misma para las tres sub-pestañas. Arriba dice "Resúmenes de" y la tarjeta, y los botones **Cargar resumen**, **Cargar base histórica** y la cruz para cerrarla.

| Columna | Qué muestra |
| --- | --- |
| **PERÍODO** | El mes cuya estimación pisa. |
| **IMPORTE $** | El importe en pesos. |
| **IMPORTE U$S** | El importe en dólares. |
| **VENCIMIENTO** | La fecha en que el banco lo debita. |
| **ORIGEN** | **Resumen del período** o **Base histórica**. |
| **PAGADO** | El interruptor: prendido, el resumen sale del horizonte. |
| **OBSERVACIÓN** | El texto cargado. Si es compartido, la marca **compartido · titular** o **compartido · no suma acá**; al pasar el mouse dice qué tarjetas incluye y cuál es la titular. |
| **AUDITORÍA** | Quién lo modificó y cuándo; si está de baja, quién lo bajó. |
| Tacho | Dar de baja. |

Los dados de baja se ven atenuados y sin controles. No hay un botón para editar un resumen: se corrige volviendo a cargar uno del mismo período (ver *Tareas frecuentes*).

### Gastos Supervisoras

- **Las cuatro tarjetas**:
  - **Proyectado en el período**: efectivo más tarjeta, en la vista que se está mirando.
  - **Supervisoras**: cuántas filas tiene la grilla. Cuenta también las de quien tiene tarjeta y no carga gastos.
  - **Sin tarjeta: no entra**: la parte con tarjeta que no tiene dónde caer porque la supervisora no tiene tarjeta, o tiene más de una. Es de todo el horizonte, no de la vista.
  - **No se proyectan**: cuántas filas no proyectan.
- **El cartel azul**: qué meses se promediaron, que siempre se divide por 3 y desde qué mes se compone la inflación.
- **Por supervisora**: el texto dice cómo va el efectivo (con la regla del cronograma, por ejemplo "todos los lunes") y la tarjeta.
- **La grilla**, una fila por supervisora:

| Columna | Qué muestra |
| --- | --- |
| **SUPERVISORA** | La flecha para abrir las subfilas **Efectivo** y **Tarjeta**, el nombre y sus marcas. |
| **PROMEDIO BASE** | El promedio mensual de la ventana. Si tiene gastos en menos de tres meses, dice "N de 3 meses": igual se divide por 3. |
| **% EFEC / % TARJ** | La proporción de la ventana. |
| **TARJETA ASOCIADA** | La tarjeta (últimos 4, banco y usuario), "+X% cob." si tiene cobertura, "vence el N", el ícono de resúmenes y una marca por cada resumen compartido. Si no tiene, "sin tarjeta" o "N tarjetas activas a su nombre". |
| Columnas del eje y **TOTAL PERÍODO** | Efectivo más tarjeta de cada columna. |

Las marcas de la columna **SUPERVISORA**:

- En gris, por qué no se proyecta: **no está en el maestro de supervisoras**, **inactiva en el maestro**, **sus gastos suman cero**, **no carga gastos: cargale el histórico** o **no se proyecta**. La fila se ve atenuada.
- En celeste, **histórico manual**: proyecta con el histórico cargado a mano. Al pasar el mouse dice los tres meses.
- El botón del reloj hacia atrás, para cargar o corregir el histórico manual. Sólo en las filas de quien no carga gastos, y con permiso.

Las marcas de resumen compartido de **TARJETA ASOCIADA** dicen **compartido AAAA-MM** (en azul, si esta tarjeta es la titular) o **compartido AAAA-MM · no suma acá** (en gris). Al pasar el mouse dicen el importe y el vencimiento.

En la subfila **Tarjeta**, si no hay dónde ponerla: **sin tarjeta: no entra al tablero** o **más de una tarjeta activa: no entra al tablero**.

Las celdas del eje de esta grilla no tienen tooltip ni marcan si el importe sale de un resumen. Para saberlo, mirá las marcas de **TARJETA ASOCIADA**, la grilla de resúmenes de la tarjeta o la nota de la celda en el tablero.

Si no hay filas, dice "Ninguna supervisora tiene gastos autorizados en la ventana …" o, si no se pudieron leer, "No se pudieron leer los gastos de supervisión. Los avisos de arriba dicen por qué."

### Tarjetas Pagos Corporativos

- **Las cuatro tarjetas**:
  - **Entra al flujo**: facturas + cobertura + resúmenes + estimaciones + seguros, en la vista. Abajo dice cuánto de eso es estimación por proveedor y cuánto es de seguros.
  - **Sin vincular**: el pendiente de todas las facturas sin tarjeta, vencidas o no, y cuántas son. No depende de la vista.
  - **Cobertura**: la cobertura de la vista.
  - **Excluidas**: el pendiente de las excluidas y cuántas son.
- **El cartel azul**, que resume qué facturas entran y cómo se ubican.
- **Facturas de tarjeta corporativa**, la grilla principal (ver abajo).
- **Estimaciones mensuales**: las de los proveedores que proyectan.
- **Seguros**: las pólizas.
- **Cobertura y resúmenes**: la cobertura de cada tarjeta y mes y los resúmenes cargados.

**La grilla de facturas**

Arriba: cuántos vencimientos hay, el buscador (**Proveedor o comprobante…**, busca en el código, la razón social y el comprobante), los interruptores **Ver vencidas** y **Ver excluidas**, **Columnas fijas** y **Exportar**. Debajo, una línea dice cuántas filas esconden los filtros y por cuánto.

- **Ver vencidas** arranca apagado y esconde las filas con vencimiento a considerar anterior a hoy. Esconde también las vinculadas que salen en el próximo pago de su tarjeta, que sí suman.
- **Ver excluidas** arranca apagado.
- El buscador y los interruptores no cambian el tablero ni el pie **TOTALES**, que suma todas las filas, también las escondidas.

Con permiso, al tildar facturas aparece la **barra de selección**: cuántas, por cuánto y de cuántos proveedores, el desplegable de la tarjeta y los botones **Vincular a la tarjeta**, **Desvincular**, **Excluir**, **Volver a incluir** y **Limpiar**.

| Columna | Qué muestra |
| --- | --- |
| **PROVEEDOR**, **RAZON SOCIAL**, **T_COMP**, **N_COMP** | La factura. |
| **VTO. EN TANGO** | El vencimiento de la cuota en Tango. Informativo. |
| **VTO. A CONSIDERAR** | El vencimiento con el que se calcula el débito: el de Tango o el corregido. Con permiso se edita ahí mismo. Si está corregido, se ve en azul, con el botón ↺ para volver al de Tango; al pasar el mouse dice el de Tango, el motivo y quién lo corrigió. Lleva **vencida** en rojo si es anterior a hoy. |
| **DÉBITO TARJETA** | La fecha en que se paga. Vinculada, en negrita. Sin vincular, el vencimiento en gris: no es un débito, y no suma. "fuera del horizonte" si no tiene fecha. Al pasar el mouse, la explicación de la fila. |
| **TARJETA** | La tarjeta, o "sin vincular". |
| **PENDIENTE** | Lo que falta pagar de la cuota. Tachado si está excluida. |
| **ESTADO** | **entra**, **sale el dd/mm/aaaa** (su débito ya pasó y va al próximo pago), **cubierta por resumen**, **sin tarjeta: no entra**, **sin fecha** o **excluida** (con el motivo). El ícono de repetir dice que el proveedor proyecta. |
| Columnas del eje y **TOTAL PERÍODO** | Cuánto de la factura cae en cada columna. |

La grilla abre ordenada por **DÉBITO TARJETA**. Las filas que no suman se ven atenuadas.

Si Tango cambió el vencimiento de una cuota que se había corregido acá, debajo de la grilla aparece una caja amarilla, "Vencimientos corregidos que ya no se aplican: Tango cambió el vencimiento de la cuota", con cada corrección y el botón **Dar de baja**.

**Estimaciones mensuales**

Una fila por factura que se repite: **PROVEEDOR** (con quién lo marcó), **FACTURA DE ORIGEN** (el comprobante, su vencimiento de Tango y el corregido, si lo tiene), **TARJETA** (**no proyecta** si la tarjeta no sirve), **IMPORTE BASE** (el de la cuota, antes de la inflación), **DÍA**, **ESTADO POR MES** (una marca por mes; al pasar el mouse, la fecha y la cuenta de la inflación) y las columnas del eje con el importe ajustado.

Desde acá no se desmarca: el proveedor se desmarca en Parámetros › Tarjetas.

**Seguros**

El botón **Cargar póliza** (con permiso) y una fila por póliza: **PROVEEDOR** (con el lápiz para corregirla y la cruz para darla de baja), **PÓLIZA**, **TARJETA**, **TOTAL VIGENCIA** (en $ o en U$S), **VIGENCIA** (las fechas, los meses, la gracia y el primer pago, con la marca **se continúa** o **sigue la del dd/mm/aaaa**), **CUOTA** y las columnas del eje. Los pagos de la continuación estimada van en itálica y atenuados; al pasar el mouse cada pago dice de dónde sale.

Abajo, una línea dice cuántas facturas de seguros hay pendientes en Tango, que no suman.

**Cobertura y resúmenes**

Una fila por cobertura de cada tarjeta y mes (**Cobertura gastos excepcionales**) y por cada resumen (**Resumen cargado** o **Resumen compartido**): **CONCEPTO**, **TARJETA**, **MES**, **FECHA**, **BASE**, **IMPORTE**, **ESTADO** (**entra**, **no entra** o **informativo**, para un compartido en una tarjeta que no es la titular) y el detalle.

### Tarjetas Socios

- **Las tres tarjetas**: **Proyectado en pesos** (pesos más el componente en U$S ya convertido, en la vista), **Bancos de socios** (cuántos hay) y **Sin base histórica** (cuántos no estiman).
- **El cartel azul del dólar**: con qué dólar se convierte el componente en U$S (el valor del BCRA, su fecha y su punta), hasta dónde llega la curva, y que la inflación ajusta sólo los pesos.
- **Por banco**, la grilla:

| Columna | Qué muestra |
| --- | --- |
| **BANCO** | "Socios ·" y el banco, la flecha para abrir sus filas, **sin base histórica** (en amarillo) o **N de 3**, y el ícono de resúmenes. |
| **BASE** | El promedio en $ y en US$ de la base. |
| **CONCEPTO** | La fila: **Total en pesos** (la única que se ve cerrada), **Consumos en $**, **U$S equivalente en $** y **U$S (informativa)**. |
| Columnas del eje y **TOTAL PERÍODO** | La fila en U$S, en dólares; las otras tres, en pesos. |

Al pasar el mouse por una celda, dice de dónde sale el número y con qué dólar se convirtió. Las que salen de un resumen van subrayadas en violeta. Un banco sin base se ve atenuado.

Si no hay bancos, dice "No hay ninguna tarjeta de tipo Socio activa. Se dan de alta en Parámetros › Tarjetas."

## Tareas frecuentes

### Cargar un resumen

:::pasos
1. En la fila de la tarjeta (o del banco), tocá el ícono de resúmenes. Se abre **Resúmenes de …**.
2. Tocá **Cargar resumen**.
3. En una tarjeta Supervisora o Corporativa, **Tarjeta(s) del resumen** viene con esta tarjeta tildada y marcada como titular. Si el resumen es sólo de ella, dejalo así.
4. Elegí el **Período**: el mes cuya estimación pisa.
5. Cargá el **Importe en $**, el **Importe en U$S** o los dos.
6. Cargá el **Vencimiento** y, si querés, una **Observación**.
7. Tocá **Cargar**.
Resultado: el mensaje dice "Resumen cargado para AAAA-MM. Pisa la estimación de ese mes." y el importe aparece en la columna de su vencimiento, en lugar de lo estimado.
:::

Al menos uno de los dos importes es obligatorio, y tienen que ser mayores a cero: si ese mes no hubo consumos en una moneda, dejala en blanco. En Gastos Supervisoras se usa sólo el importe en $.

### Cargar un resumen compartido

:::pasos
1. Abrí los resúmenes desde la fila de una de las tarjetas de la cuenta y tocá **Cargar resumen**.
2. En **Tarjeta(s) del resumen**, tildá todas las tarjetas que incluye el resumen. Cada una dice su tipo y su día de vencimiento.
3. Marcá la **titular**: la tarjeta que lleva el importe al tablero.
4. Completá **Período**, importes y **Vencimiento**.
5. Tocá **Cargar**.
Resultado: el mensaje dice "Resumen cargado para AAAA-MM. Incluye N tarjetas: pisa la estimación de todas y suma una sola vez, en la titular." La titular muestra **compartido AAAA-MM**; las otras, **compartido AAAA-MM · no suma acá**.
:::

Las tarjetas tienen que ser del mismo banco y vencer el mismo día. En una supervisora, el resumen reemplaza su parte con tarjeta; su parte en efectivo sigue proyectándose.

### Corregir un resumen o cambiar su titular

No hay un botón de editar: **se vuelve a cargar un resumen del mismo período** y el sistema corrige el que ya estaba. El formulario no viene con los datos del resumen anterior: hay que cargar todo de nuevo.

:::pasos
1. Abrí los resúmenes de una de las tarjetas del resumen y tocá **Cargar resumen**.
2. En **Tarjeta(s) del resumen**, tildá **otra vez todas** las tarjetas que tienen que quedar en el resumen.
3. Marcá como **titular** la que corresponda.
4. Cargá el mismo **Período**, los importes y el **Vencimiento** que tienen que quedar.
5. Tocá **Cargar**.
Resultado: el mensaje dice "Resumen corregido para AAAA-MM…". El resumen queda con la titular, las tarjetas, los importes, la fecha y la observación nuevos. El tilde de pagado no cambia.
:::

Lo que decide qué tarjetas quedan es lo que se tilda en este paso:

- **Una tarjeta que estaba en el resumen y no se tilda, sale del resumen** y ese mes vuelve a estimarse.
- Si se tilda **sólo una**, el resumen pasa a ser de esa sola tarjeta: queda como titular y todas las demás salen.
- Si se carga con **otro período**, es un resumen nuevo: el anterior queda como estaba.
- Si las tarjetas tildadas ya tienen dos resúmenes distintos en ese período, no se guarda: hay que dar de baja uno.

### Marcar un resumen como pagado

:::pasos
1. Abrí los resúmenes de la tarjeta.
2. Prendé el interruptor **PAGADO** del resumen.
Resultado: el mensaje dice "Resumen de AAAA-MM marcado como pagado: sale del horizonte, porque esa plata ya está reflejada en el saldo bancario." Apagándolo, vuelve a proyectarse.
:::

### Dar de baja un resumen

:::pasos
1. Abrí los resúmenes de la tarjeta.
2. Tocá el tacho del resumen y confirmá **Dar de baja**.
Resultado: el mensaje dice "Resumen de AAAA-MM dado de baja, de todas las tarjetas que incluía. No se borra: queda en el historial, y ese mes vuelve a proyectarse con la estimación."
:::

### Cargar la base histórica

Es para los bancos de Tarjetas Socios, que estiman con sus últimos tres resúmenes. El botón está en todas las tarjetas.

:::pasos
1. Abrí los resúmenes del banco y tocá **Cargar base histórica**.
2. Completá los tres renglones: **Período**, **Importe en $** y/o **Importe en U$S**, y **Vencimiento**. Cada renglón necesita al menos un importe.
3. Tocá **Cargar base**.
Resultado: el mensaje dice "Base histórica cargada: 3 resumen(es) (…). Entran como ya pagados, así que no suman al horizonte: son la base con la que se estima." El banco deja de figurar **sin base histórica** y empieza a estimar.
:::

O entran los tres o ninguno. Los tres períodos tienen que ser **anteriores al mes en curso**: el sistema no lo controla, pero la base sólo usa ésos. Un renglón con el mes en curso o uno futuro queda como un resumen pagado de ese mes, que pisa su estimación y no proyecta nada. Si en un período ya había un resumen, la base lo reemplaza.

### Gastos Supervisoras

**Dar de alta la tarjeta de una supervisora**

:::pasos
1. Andá a **Parámetros › Tarjetas** y tocá **Agregar tarjeta**.
2. En **Tipo**, elegí **Supervisora**.
3. Buscá el **Banco** por nombre o código y elegilo de la lista.
4. En **Usuario**, elegí a la supervisora.
5. Cargá los **Últimos 4** (obligatorios si esa persona ya tiene otra tarjeta en el mismo banco), el **% cobert.** y el **Día vto.**
6. Tocá el tilde.
Resultado: el mensaje dice "Tarjeta dada de alta: …". En Gastos Supervisoras, su parte con tarjeta pasa a salir en el débito de esa tarjeta.
:::

El nombre del usuario tiene que ser igual al que tiene la supervisora en los gastos (sin importar mayúsculas). Si no se asocia, revisá acentos y espacios. Si la supervisora no está en el desplegable, ver la pregunta frecuente sobre el desplegable **Usuario**.

**Cargar el histórico manual**

Para quien tiene tarjeta Supervisora y no carga gastos en la app de supervisión (marca **no carga gastos: cargale el histórico**).

:::pasos
1. En su fila, tocá el botón del reloj hacia atrás.
2. Revisá los tres renglones de **Mes**: vienen con los meses de la ventana, o con lo ya cargado.
3. Cargá el **Importe en $** de cada mes. Un mes sin gastos va en 0.
4. Tocá **Cargar histórico**.
Resultado: el mensaje dice "Histórico cargado para … (…). Proyecta el promedio de los 3 meses, ajustado por inflación, en el vencimiento de su tarjeta. Si empieza a cargar gastos, mandan los gastos." La fila lleva la marca **histórico manual**.
:::

Los meses tienen que ser meses cerrados, anteriores al mes en curso, y no pueden sumar cero. Volver a cargarlo reemplaza el anterior.

### Tarjetas Pagos Corporativos

**Vincular facturas a una tarjeta**

:::pasos
1. Tildá las facturas en la grilla.
2. En la barra de selección, elegí la tarjeta.
3. Tocá **Vincular a la tarjeta** y confirmá **Vincular**.
Resultado: el mensaje dice "N factura(s) vinculadas. … Ahora generan cobertura y un resumen de esa tarjeta las puede reemplazar." Pasan a salir en el débito de esa tarjeta; las vencidas, en su próximo pago.
:::

Se vincula el comprobante entero, con todas sus cuotas. Si alguna ya estaba en otra tarjeta, se mueve y el mensaje lo dice.

**Desvincular facturas**

:::pasos
1. Tildá las facturas y tocá **Desvincular**.
2. Confirmá **Desvincular**.
Resultado: dejan de generar cobertura y de sumar: sin tarjeta no hay fecha de pago. No se borra nada.
:::

**Excluir facturas o volver a incluirlas**

:::pasos
1. Tildá las facturas y tocá **Excluir**.
2. Escribí el **Motivo (el mismo para todas)**. Es obligatorio.
3. Tocá **Excluir N factura(s)**.
Resultado: salen de la fila del tablero y van a una serie informativa, con el motivo. Se ven con **Ver excluidas**. No se excluyen de Cuentas a Pagar Locales.
:::

Para deshacerlo, prendé **Ver excluidas**, tildalas y tocá **Volver a incluir**. El motivo queda en el historial.

**Corregir el vencimiento de una cuota**

:::pasos
1. En la columna **VTO. A CONSIDERAR**, elegí la fecha nueva (hoy o después).
2. En el diálogo **Corregir el vencimiento**, escribí un **Motivo** si querés (es opcional).
3. Tocá **Guardar**.
Resultado: el mensaje dice "Vencimiento corregido: en esta pestaña la cuota vence el …. En Tango sigue como estaba, y Cuentas a Pagar Locales no cambia." La celda queda en azul y **DÉBITO TARJETA** se recalcula sobre la fecha nueva.
:::

Cancelar deja la fecha como estaba. Elegir la misma fecha de Tango deshace la corrección.

**Volver al vencimiento de Tango**

:::pasos
1. En la celda corregida, tocá ↺.
Resultado: el mensaje dice "La cuota vuelve al vencimiento de Tango. La corrección queda en el historial: no se borra." **VTO. A CONSIDERAR** vuelve a mostrar la fecha de Tango y **DÉBITO TARJETA** se recalcula desde ella.
:::

Si la fecha de Tango ya pasó, la fila pasa a estar vencida: se esconde salvo con **Ver vencidas**. Si está vinculada, sale en el próximo pago de su tarjeta.

**Dar de baja una corrección que ya no se aplica**

:::pasos
1. En la caja amarilla de abajo de la grilla, tocá **Dar de baja** en la corrección.
Resultado: la corrección se da de baja y la caja deja de mostrarla. Si hace falta, corregí el vencimiento nuevo.
:::

**Marcar un proveedor que proyecta**

:::pasos
1. Andá a **Parámetros › Tarjetas**, sección **Proveedores que proyectan**.
2. En **Proveedor**, escribí la razón social o el código y elegilo de la lista. Están los que tienen facturas pendientes con forma de pago TARJETA CORP.
3. Confirmá **Marcar**.
Resultado: el mensaje dice "… proyecta: sus facturas pendientes vinculadas se repiten hasta el fin del horizonte, con inflación." Sus facturas aparecen en **Estimaciones mensuales**.
:::

Para que se repita, la factura tiene que estar vinculada a una tarjeta. Para dejar de proyectar, tocá la cruz de su fila (**Dejar de proyectar**) y confirmá **Dar de baja**: sus facturas siguen en la grilla, lo que se va es la repetición.

### Seguros

**Cargar una póliza**

:::pasos
1. En **Tarjetas Pagos Corporativos**, sección **Seguros**, tocá **Cargar póliza**.
2. Elegí el **Proveedor** y escribí el **Número o descripción de la póliza**.
3. Elegí la **Tarjeta** corporativa: de ella salen el día del débito y el % de cobertura.
4. Elegí la **Moneda** y cargá el **Importe total de la vigencia**.
5. Cargá el **Inicio de la vigencia**, la **Vigencia (meses)**, la **Gracia (meses)** y el **Mes del primer pago**.
6. Si querés, una **Observación**. Tocá **Cargar póliza**.
Resultado: el mensaje dice "Póliza cargada: … Son N pago(s) de $ X desde AAAA-MM, en el débito de su tarjeta." Las cuotas aparecen en el eje.
:::

El último pago tiene que caer dentro de la vigencia, con el día de la tarjeta: si no, el formulario lo rechaza y dice qué cambiar. Dos pólizas del mismo proveedor y número no pueden tener vigencias superpuestas.

**Renovar una póliza**

Cargá la póliza nueva con **el mismo proveedor y el mismo número o descripción**, con el inicio el día que termina la anterior o después. La continuación estimada de la anterior se corta y proyectan las cuotas de la nueva. La fila de la anterior pasa a decir **sigue la del dd/mm/aaaa**.

**Corregir o dar de baja una póliza**

Con el lápiz de la fila se abre el mismo formulario, con los datos cargados; se guarda con **Guardar**. Con la cruz, y confirmando **Dar de baja**, la póliza deja de proyectar. No se borra: queda en el historial. Si era la única del proveedor, el seguro no proyecta nada hasta que se cargue otra.

### Tarjetas Socios

**Dar de alta un banco de socios**

:::pasos
1. Andá a **Parámetros › Tarjetas** y tocá **Agregar tarjeta**.
2. En **Tipo**, elegí **Socios (por banco)**. **Usuario** y **Últimos 4** se apagan: van por banco.
3. Elegí el **Banco**, y cargá el **% cobert.** y el **Día vto.**
4. Tocá el tilde.
Resultado: el mensaje dice "Tarjeta dada de alta: Socios · …". El banco aparece en Tarjetas Socios **sin base histórica**, hasta que le cargues sus últimos tres resúmenes.
:::

Hay un solo banco de socios por banco. Después, cargale la base histórica desde su fila (ver *Cargar la base histórica*), y cada mes, el resumen que llegue.

## Parámetros

Se cambian en **Parámetros › Tarjetas**, salvo los que dicen otra cosa. Para quién puede editar, mirá [permisos y parámetros](ayuda:general_permisos_parametros).

| Parámetro | Dónde se cambia | Valor por defecto | Qué cambia en la proyección |
| --- | --- | --- | --- |
| **Tipo** | Parámetros › Tarjetas | Sin valor: obligatorio | En qué sub-pestaña aparece la tarjeta: **Supervisora**, Gastos Supervisoras; **Corporativa**, Tarjetas Pagos Corporativos; **Socios (por banco)**, Tarjetas Socios. El tipo no depende de quién sea el usuario |
| **Banco** | Parámetros › Tarjetas | Sin valor: se elige de la lista de Tango | El nombre de la tarjeta. En un resumen compartido, todas tienen que ser del mismo banco |
| **Usuario** | Parámetros › Tarjetas | Sin valor: se elige de la lista | En una Supervisora, su nombre se cruza con los gastos. Un banco de socios no tiene usuario |
| **Últimos 4** | Parámetros › Tarjetas | Vacío | Identifican la tarjeta. Obligatorios si el usuario ya tiene otra en el mismo banco |
| **% cobert.** | Parámetros › Tarjetas | 0 (de 0 a 100, en puntos: 5 es 5 %) | Supervisoras: multiplica la parte con tarjeta estimada. Socios: multiplica los $ y los U$S estimados. Corporativas: un renglón de cobertura aparte. Nunca a un resumen |
| **Día vto.** | Parámetros › Tarjetas | Sin valor: obligatorio, de 1 a 31 | El día del débito estimado, acotado al último día del mes y corrido al hábil siguiente |
| **Activa** | Parámetros › Tarjetas | Sí | Una inactiva no proyecta. Conserva sus resúmenes y se puede reactivar |
| **Proveedores que proyectan** | Parámetros › Tarjetas | Ninguno | Sus facturas pendientes vinculadas se repiten en Estimaciones mensuales |
| Cronograma **Supervisoras** | Parámetros › Calendario | Todos los lunes, corridos al hábil siguiente | Las fechas de la parte en efectivo. Ver [el cronograma de pagos](ayuda:gastos_locales#el-cronograma-de-pagos) |
| Inflación mensual esperada | Parámetros › Generales | Sin valor = falta | El ajuste de Supervisoras, del histórico manual, de los $ de Socios, de las estimaciones mensuales y de la continuación de las pólizas en pesos. Ver [la inflación](ayuda:gastos_locales#la-inflacion) |
| Feriados bancarios | Parámetros › Calendario | Lunes a viernes, si falta el mes | Qué débitos se corren al hábil siguiente |
| Columna **Tarjetas** | Proveedores Locales › Maestro | Incluido | Un proveedor excluido no entra a Tarjetas Pagos Corporativos |
| Rubro **SEGUROS** y forma de pago **TARJETA CORP** | Maestro de Proveedores Locales | — | El proveedor es un seguro: sus facturas no suman y se proyecta con sus pólizas |

### El desplegable Usuario

El desplegable **Usuario** de **Agregar tarjeta** no se tipea. Trae una lista fija de personas:

- los **directores activos**;
- las **supervisoras activas** del maestro de supervisoras de la app comercial;
- y algunas personas agregadas a mano en esa lista, que no son ni directores ni supervisoras.

No son los usuarios del sistema. **Si falta alguien, se pide a Proyectos e Innovación** que lo agreguen a la lista. Hasta entonces no hay forma de darle una tarjeta, ni de cargarle el histórico manual.

Una tarjeta cuyo usuario ya no está en la lista se sigue viendo con el nombre guardado, con la marca **fuera de la vista**, y no se da de baja sola.

En la grilla de Parámetros › Tarjetas, el **Tipo**, los **Últimos 4**, el **% cobertura** y el **Día vto.** se editan ahí mismo y se guardan al cambiar. El interruptor **Activa** da de baja o reactiva sin pedir confirmación.

## Avisos y qué hacer

Cada solapa muestra sus avisos y los generales de la pestaña. En el tablero van en el grupo de esta pestaña, con la sección **Supervisoras**, **Corporativas** o **Socios**. Ver [cómo se leen los avisos](ayuda:general_avisos).

### Generales

:::aviso atencion
texto: N tarjeta(s) tienen un usuario que ya no está activo en RO_V_CASHFLOW_USUARIOS_TARJETAS: … (…), …. Se siguen viendo con el nombre guardado y NO se dan de baja solas: si esa persona dejó la empresa, la tarjeta se da de baja a mano.
significa: El usuario de esas tarjetas ya no está en la lista de personas (dejó de ser director o supervisora activa). La tarjeta sigue proyectando.
corrige: Si la persona dejó la empresa, apagá **Activa** en Parámetros › Tarjetas. Si sigue, pedí a Proyectos e Innovación que la vuelvan a agregar a la lista.
:::

:::aviso info
texto: N tarjeta(s) tienen un código de banco que ya no está en el maestro de Tango: …. Se ven con el código en vez del nombre.
significa: El banco de la tarjeta no está más en Tango. Proyecta igual.
corrige: Nada, salvo que el banco esté mal cargado: en ese caso, dala de baja y cargala con el banco correcto.
:::

### Gastos Supervisoras

:::aviso atencion
texto: N persona(s) tienen tarjeta Supervisora y no cargan gastos en la app de supervisión, así que no proyectan nada: …. Cargales el histórico manual de los últimos 3 meses desde su fila.
significa: Tienen tarjeta pero ningún gasto en la ventana. Su fila dice **no carga gastos: cargale el histórico**.
corrige: Cargales el histórico manual con el botón del reloj de su fila.
:::

:::aviso atencion
texto: N supervisora(s) no están en el maestro de supervisoras, así que no se proyectan: … ($ X/mes); …. Sus gastos se ven en la grilla; lo que no entra al tablero es su proyección.
significa: El nombre de los gastos no está en el maestro de supervisoras, o está escrito distinto.
corrige: Revisá el nombre en el maestro de supervisoras de la app comercial.
:::

:::aviso atencion
texto: N supervisora(s) tienen gastos autorizados que suman cero, así que no se proyectan: … ($ X/mes); …. Sus gastos se ven en la grilla; lo que no entra al tablero es su proyección.
significa: Tienen gastos autorizados en la ventana, pero suman cero.
corrige: Nada desde acá. Revisá los gastos en la app de supervisión.
:::

:::aviso info
texto: … ya tiene(n) gastos autorizados en la ventana, así que su histórico manual dejó de usarse: mandan los gastos. El histórico queda cargado como historia.
significa: La persona empezó a cargar gastos. Se proyecta con ellos.
corrige: Nada.
:::

:::aviso atencion
texto: N supervisora(s) no tienen ninguna tarjeta de tipo Supervisora cargada a su nombre, así que su parte TARJETA —$ X en todo el horizonte— no entra al tablero: sin tarjeta no hay día de vencimiento, y no se inventa una fecha. Son: …. Se dan de alta en Parámetros › Tarjetas, con el nombre del usuario igual al de la supervisora. La parte en EFECTIVO entra igual, por el cronograma de pagos.
significa: Es el importe de la tarjeta **Sin tarjeta: no entra**.
corrige: Dales de alta la tarjeta en Parámetros › Tarjetas. Si la tienen y no se asocia, revisá que el nombre del usuario sea igual al de los gastos.
:::

:::aviso atencion
texto: N supervisora(s) tienen MÁS DE UNA tarjeta activa a su nombre, así que no se puede saber en qué fecha vence su gasto y su parte tarjeta no entra al tablero: … (N), …. Dejá una sola activa en Parámetros › Tarjetas.
significa: Con dos tarjetas Supervisora activas no se sabe en qué débito sale.
corrige: Apagá **Activa** en la que no se usa, en Parámetros › Tarjetas.
:::

:::aviso info
texto: N supervisora(s) están activas y NO tienen gastos autorizados en la ventana …, así que no aparecen en la grilla y no se proyecta nada por ellas: …. No se les pone un promedio en cero —eso afirmaría que no gastan—; si cargaron gastos y no están autorizados, todavía no cuentan.
significa: Supervisoras activas sin gastos autorizados en los tres meses.
corrige: Si tienen gastos sin autorizar, se autorizan en la app de supervisión. Si tienen tarjeta y no cargan gastos, cargales el histórico manual.
:::

:::aviso atencion
texto: Falta la inflación esperada de AAAA-MM, …. Se carga en Parámetros › Generales. Los meses que dependen de eso quedan en blanco y NO en cero: un cero se leería como que no hay nada que pagar.
significa: Sin la inflación de esos meses no se puede ajustar el promedio. Si un mes quedó fuera de lo que se puede cargar, el aviso agrega que queda FUERA de la ventana editable de la grilla. El mismo aviso sale en Tarjetas Socios, y en Estimaciones mensuales con "Estimaciones mensuales:" adelante.
corrige: Cargá la inflación en Parámetros › Generales.
:::

### Tarjetas Pagos Corporativos

:::aviso atencion
texto: N factura(s) por $ X sin vincular no entran al flujo: vinculalas a una tarjeta. De esas, N vencida(s) por $ X y N no vencida(s) por $ X.
significa: Es el importe de la tarjeta **Sin vincular**.
corrige: Vinculalas a su tarjeta desde la barra de selección.
:::

:::aviso info
texto: N factura(s) por $ X están excluidas de esta pestaña y no suman a la fila del tablero. Motivos: …. Van a su propia serie, que es informativa. NO están excluidas de Cuentas a Pagar Locales: son dos decisiones distintas.
significa: Facturas excluidas a mano en esta pestaña.
corrige: Si alguna no debía excluirse, prendé **Ver excluidas** y tocá **Volver a incluir**.
:::

:::aviso info
texto: N factura(s) por $ X están cubiertas por el resumen cargado de su tarjeta: ese importe ya las incluye, así que no suman aparte. Se siguen viendo en la grilla para poder controlarlas contra el resumen.
significa: Su tarjeta tiene el resumen de ese mes cargado.
corrige: Nada.
:::

:::aviso critico
texto: N factura(s) están vinculadas a una tarjeta que ya no existe. Se tratan como no vinculadas. Eso no debería poder pasar —la tabla tiene una FK— así que revisá si alguien borró una tarjeta a mano.
significa: Su tarjeta no está entre las corporativas activas. Sale también cuando la tarjeta se dio de baja o dejó de ser Corporativa. Esas facturas no suman.
corrige: Vinculalas a una tarjeta corporativa activa, o reactivá la tarjeta en Parámetros › Tarjetas.
:::

:::aviso info
texto: N proveedor(es) están excluidos de Tarjetas Pagos Corporativos y sus facturas no entran: $ X. … $ X (…); …. Se revisan en Proveedores Locales › Maestro, columna Tarjetas.
significa: Proveedores excluidos de esta pestaña en el maestro.
corrige: Si alguno no correspondía, revisalo en Proveedores Locales › Maestro, columna **Tarjetas**.
:::

:::aviso atencion
texto: N vencimiento(s) corregido(s) ya no se aplican: Tango cambió el vencimiento de esa cuota y la corrección quedó sin cuota a la que aplicarse. Son: … (vto. Tango dd/mm/aaaa); …. Dalos de baja en la lista de abajo de la grilla, y si hace falta corregí el vencimiento nuevo.
significa: La cuota corregida ya no existe con ese vencimiento en Tango.
corrige: Tocá **Dar de baja** en la caja amarilla de abajo de la grilla y, si hace falta, corregí el vencimiento nuevo.
:::

:::aviso atencion
texto: … tiene una factura sin vincular; vinculala para que entre al flujo y se proyecte.
significa: Un proveedor que proyecta tiene una factura nueva sin tarjeta: no se repite hasta que se vincule.
corrige: Vinculala a su tarjeta.
:::

:::aviso atencion
texto: …: hay N facturas pendientes de …; si son del mismo servicio, se proyectan dos veces.
significa: Un proveedor que proyecta tiene varias facturas pendientes con la misma leyenda. Cada una se repite todos los meses.
corrige: Si son del mismo servicio, pensá si el proveedor tiene que seguir proyectando.
:::

:::aviso atencion
texto: N estimación(es) mensual(es) no se proyectan porque su tarjeta está inactiva, ya no existe o no es corporativa: … ($ X/mes), …. Desmarcalas, o marcá de nuevo la factura con la tarjeta que corresponde.
significa: La tarjeta de la factura que se repite no sirve para proyectar.
corrige: Vinculá la factura a una tarjeta corporativa activa, o desmarcá el proveedor en Parámetros › Tarjetas.
:::

### Seguros

:::aviso atencion
texto: Seguros · … no tiene póliza cargada, así que no proyecta nada: sus facturas proyectaban $ X en el horizonte, y ya no suman. Cargá su póliza en la sección Seguros.
significa: El proveedor es un seguro y no tiene póliza. Sus facturas de Tango no suman.
corrige: Cargá su póliza con **Cargar póliza**.
:::

:::aviso info
texto: Hay N vencimiento(s) de seguros pendientes en Tango, de N factura(s), por $ X. No suman: los seguros se proyectan con las pólizas.
significa: Las facturas de seguros se ven en Tango, pero lo que suma son las pólizas.
corrige: Nada.
:::

:::aviso atencion
texto: N póliza(s) tienen una tarjeta inactiva o que ya no es corporativa, así que no proyectan: … de …; …. Corregí la tarjeta de la póliza.
significa: La tarjeta de la póliza se dio de baja o cambió de tipo.
corrige: Con el lápiz de la póliza, elegí una tarjeta corporativa activa.
:::

:::aviso atencion
texto: Seguros: la continuación de una póliza en pesos necesita la inflación de los meses de la vigencia anterior, y falta la de AAAA-MM, …. Esos pagos quedan en blanco y NO en cero.
significa: Falta la inflación de un mes del mes en curso en adelante, y la continuación estimada no se puede ajustar.
corrige: Cargá la inflación en Parámetros › Generales, o cargá la póliza siguiente.
:::

:::aviso info
texto: Seguros: la continuación de una póliza en pesos se ajusta sin la inflación de AAAA-MM, …, que son meses pasados sin cargar: se suma solo lo cargado en Parámetros › Generales.
significa: Faltan meses pasados. La continuación se ajusta con los que hay.
corrige: Nada; si se puede, cargá esos meses.
:::

:::aviso atencion
texto: Seguros: no hay dólar futuro para AAAA-MM, …, así que los pagos en dólares de esos meses quedan en blanco y NO en cero.
significa: La curva de dólar futuro no tiene ningún mes con que valuar esos pagos.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

### Tarjetas Socios

:::aviso atencion
texto: Socios · …: no tiene ningún resumen cargado con período anterior al mes en curso, así que no hay con qué estimar. Cargá la base histórica —los últimos 3 resúmenes— con el botón de su fila. Hasta entonces queda en blanco y NO en cero: un cero se leería como que esa tarjeta no se usa.
significa: El banco no tiene base. Si tenía tarjetas de socio de antes, el aviso agrega cuánto proyectaban.
corrige: Cargale la base histórica desde el ícono de resúmenes de su fila.
:::

:::aviso info
texto: Socios · …: la base son N resumen(es) y no 3, así que el promedio es menos firme. Se divide por N y no por 3: un resumen que falta no es un mes sin consumos, y dividir por 3 proyectaría de menos.
significa: El banco tiene uno o dos resúmenes anteriores al mes en curso.
corrige: Nada. Se completa solo a medida que se cargan los resúmenes de cada mes.
:::

:::aviso atencion
texto: Socios · …: sus N tarjeta(s) de socio proyectaban $ X en el horizonte y ya no proyectan: Socios va por banco. Dá de alta el banco en Parámetros › Tarjetas y cargale la base histórica. Hasta entonces eso no está en el tablero.
significa: Hay tarjetas de socio de antes, con nombre, de un banco que todavía no está dado de alta como banco de socios.
corrige: Dá de alta el banco con el tipo **Socios (por banco)** y cargale la base histórica.
:::

:::aviso atencion
texto: Socios · …: N mes(es) quedaron sin valuar el componente en dólares por falta de cotización (AAAA-MM, …). Quedan en blanco y NO en cero.
significa: No hay dólar con que convertir esos meses. El total en pesos queda en blanco.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

### Del tablero

Estos los dice el tablero sobre la fila, y se ven también en la solapa correspondiente.

:::aviso info
texto: Hay pagos proyectados por $ X con fecha posterior al final del horizonte, así que no entran en ninguna columna. Se ven igual en la pestaña.
significa: Hay importes de esa parte que caen fuera de las columnas del eje.
corrige: Si los querés ver en el tablero, subí las columnas mensuales en Parámetros › Calendario.
:::

:::aviso atencion
texto: Hay $ X sin una fecha con la que entrar al eje. Se ven en la pestaña, con el motivo.
significa: Importes que no tienen fecha (por ejemplo, la parte con tarjeta de una supervisora sin tarjeta).
corrige: Mirá en la solapa por qué no tienen fecha y corregilo.
:::

:::aviso atencion
texto: La fila va en CERO porque no hay supervisoras con gastos autorizados en la ventana, no hay facturas pendientes con forma de pago TARJETA CORP, no hay bancos de socios activos. NO significa que no haya que pagar nada.
significa: Ninguna de las tres partes tiene qué proyectar. Nombra sólo las que están vacías; si ninguna lo está, dice "y los otros avisos de esta pestaña dicen por qué".
corrige: Leé los otros avisos de la pestaña.
:::

### En Parámetros › Tarjetas

Esa sección muestra los avisos generales de arriba y, al guardar, sus mensajes de error. Los más comunes:

| Mensaje | Qué hacer |
| --- | --- |
| "Falta el tipo, el banco (elegilo de la lista), el usuario, el día de vencimiento." | Completá lo que nombra. El banco hay que elegirlo de la lista, no alcanza con escribirlo. |
| "… ya tiene otra tarjeta en este banco, así que hacen falta los últimos 4 dígitos para poder distinguirlas: sin ellos no habría forma de saber a cuál corresponde cada resumen." | Cargá los **Últimos 4**. |
| "El usuario con ID N no está en RO_V_CASHFLOW_USUARIOS_TARJETAS. Elegilo de la lista: son los directores y las supervisoras activos." | Elegilo del desplegable. Si no está, pedí a Proyectos e Innovación que lo agreguen. |
| "… ya está cargado como banco de socios. Hay uno por banco." | Ya existe; si está de baja, reactivalo. |
| "El código de banco "…" no existe en el maestro de bancos de Tango. Elegilo de la lista." | Elegí el banco de la lista. |
| "El día de vencimiento es obligatorio y tiene que ser un número entero del 1 al 31. Sin él la tarjeta no proyecta nada." | Corregí el **Día vto.** |
| "El % va en puntos y entre 0 y 100: 5 es 5 %." | Corregí el **% cobertura**. |
| "El proveedor … es un seguro: no proyecta por proveedor, se proyecta con sus pólizas." | Cargale su póliza en la sección Seguros. |
| "El proveedor … no tiene facturas pendientes con forma de pago TARJETA CORP: no hay nada que proyectar." | No hay qué repetir. |

### Los mensajes al guardar

No son avisos: aparecen un momento después de cada acción. Los de éxito están en cada tarea. Los errores llegan como "No se pudo guardar: Error: …", con el motivo:

| Mensaje | Cuándo |
| --- | --- |
| "El resumen tiene que traer al menos uno de los dos importes, en pesos o en dólares. Pueden venir los dos: la misma tarjeta tiene consumos en las dos monedas." | Un resumen sin importes. |
| "El importe en pesos tiene que ser mayor a cero. Un resumen en cero no se distingue de un campo que quedó vacío; si ese mes no hubo consumos en esa moneda, dejalo en blanco." | Un importe en cero (también "en dólares"). |
| "El resumen está cargado en el período AAAA-MM y vence el dd/mm/aaaa, que es de AAAA-MM. Pisa la estimación de AAAA-MM, que es el período, y sale en la columna de la fecha de vencimiento. Si querés que pise la de AAAA-MM, cargalo con ese período." | Se guardó, pero el Período y la fecha son de meses distintos. No es un error: revisá que el Período sea el correcto. |
| "Marcá cuál de las tarjetas elegidas es la titular: es la que lleva el resumen al tablero, una sola vez." | Un compartido sin titular. |
| "… es de tipo …: un resumen compartido sólo puede incluir tarjetas Supervisora y Corporativa." | Se eligió una tarjeta de otro tipo. |
| "… está dada de baja: un resumen compartido sólo incluye tarjetas activas." | Se eligió una tarjeta inactiva. |
| "Las tarjetas de un mismo resumen tienen que ser del mismo banco: … es de otro banco que …. Un banco manda un resumen por cuenta." | Tarjetas de bancos distintos. |
| "Las tarjetas de un mismo resumen tienen que vencer el mismo día: … vence el N y … el M. Si de verdad son de la misma cuenta, corregí el día en Parámetros › Tarjetas." | Tarjetas con distinto día de vencimiento. |
| "Las tarjetas elegidas ya tienen N resúmenes distintos en AAAA-MM. Dalos de baja y cargá uno solo: juntarlos sería decidir cuál de los importes vale." | Las tarjetas tildadas ya tienen resúmenes distintos en ese período. |
| "La base histórica son 3 resúmenes, uno por renglón, y llegaron N. No se cargó ningún resumen." | Faltan renglones en la base. |
| "El período AAAA-MM viene dos veces. No se cargó ningún resumen." | Dos renglones de la base con el mismo período. |
| "En AAAA-MM esta tarjeta ya está incluida en un resumen compartido. No se cargó ningún resumen." | Un renglón de la base choca con un compartido. |
| "Renglón N: AAAA-MM no es un mes cerrado. El histórico son meses anteriores al mes en curso. No se cargó nada." | Histórico manual con el mes en curso o uno futuro. |
| "Renglón N: falta el importe de AAAA-MM. Si ese mes no gastó, poné 0. No se cargó nada." | Histórico manual con un importe vacío. |
| "Los tres meses suman cero: no hay nada que proyectar. No se cargó nada." | Histórico manual en cero. |
| "… no tiene ninguna tarjeta de tipo Supervisora activa: sin tarjeta no hay día de débito, y el histórico no proyectaría nada. Dásela de alta en Parámetros › Tarjetas." | Histórico manual de alguien sin tarjeta. |
| "El vencimiento corregido no puede ser anterior a hoy (dd/mm/aaaa): una cuota que ya se debitó sale de pendientes sola." | Corrección con fecha pasada. |
| "Esa cuota no está entre las facturas pendientes de TARJETA CORP: puede que se haya pagado o que Tango le haya cambiado el vencimiento. Actualizá la pantalla." | La cuota cambió en Tango mientras se miraba. |
| "Poné el motivo por el que esta factura no entra a Pagos con Tarjetas y Otros. Sin motivo, dentro de tres meses nadie va a poder explicar por qué falta ese importe." | Exclusión sin motivo. |
| "La tarjeta N está dada de baja, así que no proyecta nada: …" | Se quiso vincular a una tarjeta inactiva. |
| "Con V mes(es) de vigencia y G de gracia son N pago(s), uno por mes desde AAAA-MM: el último cae en AAAA-MM (débito del dd/mm/aaaa) y la vigencia termina el dd/mm/aaaa. Adelantá el mes del primer pago o revisá la gracia." | El último pago de la póliza cae después del fin de la vigencia. |
| "La vigencia se superpone con la de otra póliza … de este proveedor (del … al …). Las vigencias no se pisan: la siguiente empieza cuando termina la anterior." | Dos pólizas iguales con vigencias superpuestas. |
| "El primer pago no puede ser anterior al mes de inicio de la vigencia (AAAA-MM)." | Primer pago antes del inicio. |
| "La gracia tiene que ser menor que la vigencia: si no, no queda ningún pago." | Gracia igual o mayor que la vigencia. |
| "No se pudieron leer los resúmenes: …" | Falló la lectura de la grilla de resúmenes. Probá de nuevo; si sigue, avisá a Sistemas. |

### Avisos para Sistemas

Los de instalación y los de lectura: falta una tabla, falta correr un script o no se pudo leer algo. No los corrige quien usa la pantalla: hay que avisar a sistemas, con el texto del aviso.

:::aviso critico
texto: No se pudieron calcular los pagos con tarjetas: …
significa: La pestaña no pudo cargar. Después de los dos puntos va el motivo.
corrige: Tocá **Actualizar**. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: Todavía no existe RO_T_CASHFLOW_TARJETAS: corré sql/cashflow_tarjetas.sql contra la base central. Mientras tanto no se pueden dar de alta tarjetas, así que las tres sub-pestañas se ven vacías y la fila del tablero va en cero.
significa: El maestro de tarjetas no está instalado.
corrige: Sistemas, corriendo el script.
:::

:::aviso critico
texto: La fila va en CERO porque todavía no existen las tablas del módulo. Corré sql/cashflow_tarjetas.sql y sql/cashflow_tarjetas_facturas.sql contra la base central. Un egreso en cero no significa que no haya que pagar nada.
significa: El aviso del tablero cuando el módulo no está instalado.
corrige: Sistemas, corriendo los scripts.
:::

:::aviso atencion
texto: No existe RO_V_CASHFLOW_USUARIOS_TARJETAS, que es de donde salen los usuarios de las tarjetas. Esta vista NO la crea ningún script de este repo: se mantiene aparte, y hay que pedir que la creen. Mientras falte no se pueden dar de alta tarjetas; las que ya están cargadas se ven con el nombre guardado.
significa: Falta la lista de personas del desplegable **Usuario**.
corrige: Sistemas, creando la lista.
:::

:::aviso critico
texto: No se pudieron revisar las tarjetas: …
significa: Falló la revisión del maestro de tarjetas.
corrige: Tocá **Actualizar**. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: No se pudo leer …: …. La parte que depende de eso queda sin resolver.
significa: Falló una lectura. Lo que nombra puede ser las tarjetas, los resúmenes de tarjeta, la inflación mensual esperada, el histórico manual de Gastos Supervisoras, los proveedores de seguros del maestro, los vínculos factura-tarjeta, las facturas excluidas, los vencimientos corregidos, los proveedores que proyectan, las pólizas de seguros, la curva de dólar futuro o la cotización del dólar oficial del BCRA.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas con el texto.
:::

:::aviso critico
texto: Todavía no existe RO_T_CASHFLOW_INFLACION_MES: corré sql/cashflow_parametros_generales.sql contra la base central. Mientras tanto no se puede cargar la inflación, y los valores hora de Logística Local se proyectan sin ajuste trimestral.
significa: La inflación no está instalada. Lo que se ajusta por inflación queda en blanco.
corrige: Sistemas, corriendo el script.
:::

:::aviso atencion
texto: RO_T_CALENDARIO no tiene datos para AAAA-MM. Se asumen hábiles los días de lunes a viernes.
significa: Falta el calendario de ese mes: los feriados no corren los débitos.
corrige: Avisá a Sistemas que cargue el calendario.
:::

:::aviso critico
texto: No existe dbo.RO_T_GASTOS_SUPERVISION, que es de donde salen los gastos de supervisión. Esa tabla no la crea este repo: la mantiene el módulo de supervisión. Sin ella Gastos Supervisoras no puede estimar nada y la fila del tablero va en cero.
significa: No se encuentran los gastos de la app de supervisión.
corrige: Avisá a Sistemas.
:::

:::aviso critico
texto: No se pudo leer el maestro de supervisoras (…): …. Los gastos se ven igual, pero NINGUNA supervisora se proyecta: sin el maestro no hay forma de saber quién sigue trabajando, y proyectar a alguien que dejó la empresa pondría en el tablero un egreso que no va a salir.
significa: El maestro de supervisoras no respondió. Gastos Supervisoras no proyecta nada.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: No se pudieron leer los gastos de supervisión: …. Gastos Supervisoras va en cero.
significa: Falló la lectura de los gastos.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: No se pudo resolver el cronograma de pagos (…), así que la parte en EFECTIVO de Gastos Supervisoras no tiene fechas y no se proyecta.
significa: Sin cronograma, el efectivo de las supervisoras no tiene fechas.
corrige: Revisá Parámetros › Calendario. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: No se pudieron leer las cuentas a pagar de Tango: …. Tarjetas Pagos Corporativos va en cero, y eso NO significa que no haya facturas que pagar.
significa: Falló la lectura de Tango.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

:::aviso atencion
texto: No se pudieron leer los proveedores excluidos de Tarjetas Pagos Corporativos (…): se toman como incluidos.
significa: Pueden aparecer facturas de proveedores excluidos: se proyecta de más.
corrige: Tocá **Actualizar** más tarde. Si sigue, avisá a Sistemas.
:::

:::aviso critico
texto: No se pudieron calcular los pagos de la póliza …: …
significa: Una póliza no se pudo calcular y no proyecta.
corrige: Avisá a Sistemas con el texto.
:::

Sin algunas tablas, los controles se ven apagados y lo dicen al pasar el mouse: "Falta correr sql/cashflow_tarjetas.sql" (cargar resúmenes), "Falta correr sql/cashflow_tarjetas_facturas.sql" (vincular y excluir), "Para corregir el vencimiento falta correr sql/cashflow_tarjetas_vto_mensual.sql", "Para cargar pólizas falta correr sql/cashflow_tarjetas_polizas.sql" y "Falta correr sql/cashflow_tarjetas_prov_proyecta.sql" (proveedores que proyectan). Sin el script de resúmenes compartidos, el resumen se carga en una sola tarjeta. Sin el de bancos de socios, el tipo **Socios (por banco)** no aparece. Son de Sistemas.

## Preguntas frecuentes

:::pregunta ¿Por qué no está la persona que busco en el desplegable Usuario?
El desplegable no son los usuarios del sistema: trae los directores activos, las supervisoras activas del maestro de supervisoras de la app comercial y algunas personas agregadas a mano en esa lista. No se puede tipear a otra persona.

Si falta alguien, pedí a **Proyectos e Innovación** que lo agreguen a la lista. Hasta entonces no se le puede dar una tarjeta ni cargarle el histórico manual.
:::

:::pregunta Cargué el resumen de una supervisora y su total no bajó a ese importe. ¿Por qué?
Porque el resumen reemplaza sólo la **parte con tarjeta**. La parte en efectivo de ese mes se sigue proyectando en los pagos del cronograma. Abrí la fila: la subfila **Tarjeta** tiene el importe del resumen y la subfila **Efectivo**, lo estimado.

Revisá también el **Período**: el resumen pisa la estimación del Período, pero sale en la columna de su fecha. Si son meses distintos, en la columna de la fecha puede quedar también la estimación del otro mes.
:::

:::pregunta Cargué un resumen de supervisora sólo en dólares y la tarjeta quedó en cero. ¿Por qué?
En Gastos Supervisoras se usa sólo el **importe en $** del resumen. El importe en U$S no suma. Un resumen sólo en dólares pisa la estimación del mes y deja la parte con tarjeta en cero. Si hay consumos en dólares, cargá su equivalente en pesos en **Importe en $**.
:::

:::pregunta ¿Cómo cambio la titular de un resumen compartido?
Volvé a cargar un resumen del **mismo período** desde una de las tarjetas, tildá **otra vez todas** las tarjetas que incluye, marcá la titular nueva y cargá los importes y el vencimiento. El sistema corrige el resumen que ya estaba: cambia la titular y deja las demás como adicionales.

Ojo: el formulario no trae las tarjetas del resumen. Si tildás sólo una, el resumen queda sólo en esa, y las demás vuelven a estimar.
:::

:::pregunta ¿Por qué un proveedor que proyecta se repite el doble?
Porque se repite **cada** factura pendiente vinculada del proveedor, todos los meses. Si tiene dos pendientes del mismo servicio, se repiten las dos; un aviso lo dice cuando tienen la misma leyenda.

Y no mira cada cuánto factura: una factura bimestral también se repite todos los meses. Si el proveedor no factura todos los meses, pensá si conviene marcarlo.
:::

:::pregunta Se pagó la factura y el proveedor dejó de proyectar. ¿Está bien?
Sí. Se repiten sus facturas **pendientes**: cuando se paga, sale de pendientes y su repetición desaparece. Si el proveedor no tiene otra pendiente vinculada, no proyecta nada hasta que llegue la factura nueva a Tango y la vincules. Sigue marcado en Parámetros › Tarjetas.
:::

:::pregunta ¿El importe de las estimaciones mensuales tiene inflación?
Sí. **IMPORTE BASE** es el de la cuota de la factura, sin ajustar. Las celdas del eje tienen el importe ajustado por la inflación compuesta desde el mes de pago de la factura, y al pasar el mouse por la marca de cada mes se ve la cuenta.
:::

:::pregunta Volví al vencimiento de Tango y VTO. A CONSIDERAR muestra la fecha de Tango, no la del débito. ¿Está mal?
No. **VTO. A CONSIDERAR** es el vencimiento de la factura: el de Tango o el corregido. La fecha en que se paga está en **DÉBITO TARJETA**, que se calcula desde ese vencimiento con el débito de la tarjeta. Si la factura no está vinculada, **DÉBITO TARJETA** muestra el vencimiento en gris porque no hay débito, y no suma: vinculala.
:::

:::pregunta ¿Por qué no veo una factura vinculada que sí suma?
Porque su vencimiento ya pasó: sale en el próximo pago de su tarjeta (**sale el dd/mm/aaaa**), pero **Ver vencidas** apagado la esconde. Prendelo. La línea de arriba de la grilla dice cuántas filas esconden los filtros y por cuánto, y el pie **TOTALES** las suma igual.
:::

:::pregunta ¿Por qué una supervisora no tiene tarjeta asociada si se la cargué?
La tarjeta se asocia por nombre: tiene que ser de tipo **Supervisora**, estar activa, y su usuario tiene que llamarse igual que la supervisora en los gastos. Las mayúsculas no importan, los acentos y los espacios sí. Si tiene dos tarjetas Supervisora activas, tampoco se asocia: dejá una sola.
:::

:::pregunta ¿Qué pasa cuando termina una póliza?
Si no está cargada la siguiente, el ciclo se repite como **estimado**: una vigencia después, con los mismos meses de pago y de gracia. En dólares, el mismo importe; en pesos, el total anterior más la inflación sumada de la vigencia anterior. Cuando cargues la póliza siguiente con el mismo número o descripción, la estimada se corta.
:::

:::pregunta ¿Por qué un mes de una póliza no tiene cuota?
Es un mes de gracia: la vigencia tiene más meses que pagos. Los pagos van seguidos desde el mes del primer pago, así que los meses sin pago quedan donde no alcanzan los pagos: al final si el primer pago es el mes de inicio, al principio si es más adelante. Cada ciclo estimado deja los mismos meses sin pago.
:::

:::pregunta ¿Por qué un banco de socios no estima?
Porque no tiene **base**: ningún resumen con período anterior al mes en curso. Cargale los últimos tres con **Cargar base histórica**. Los períodos tienen que ser anteriores al mes en curso; uno del mes en curso no cuenta para la base.
:::

:::pregunta ¿Qué hago cuando el banco debita un resumen?
Marcalo como pagado con el interruptor **PAGADO**: sale del horizonte, porque esa plata ya está en el saldo bancario. Si no lo marcás, el resumen sigue como pendiente.
:::

:::pregunta ¿La pestaña y el tablero dicen lo mismo?
Sí: la fila Pagos con Tarjetas y Otros es la suma de las tres sub-pestañas, columna por columna. Un resumen, una estimación mensual o un seguro dejan una nota en su celda del tablero. Más en [el tablero](ayuda:general_tablero).
:::

## Glosario

| Término | Qué quiere decir |
| --- | --- |
| Tarjeta Supervisora | Tarjeta del maestro de tipo Supervisora: se asocia a una supervisora por el nombre de su usuario |
| Tarjeta Corporativa | Tarjeta del maestro de tipo Corporativa: paga facturas de Tango y pólizas |
| Banco de socios | La fila del maestro de tipo Socios (por banco): un banco, sin el nombre del socio |
| Débito | La fecha en que el banco cobra la tarjeta: el día de vencimiento, acotado al mes y corrido al hábil siguiente, o la fecha del resumen |
| Resumen | Lo que el banco va a debitar de una tarjeta en un período. Pisa la estimación de ese mes |
| Período | El mes cuya estimación pisa un resumen |
| Resumen compartido | Un resumen para varias tarjetas de la misma cuenta: pisa la estimación de todas y suma una vez, en la titular |
| Titular | La tarjeta de un resumen compartido que lleva el importe al tablero |
| Pagado | Un resumen que ya se debitó: sale del horizonte |
| Base histórica | Los últimos tres resúmenes, cargados de una y ya pagados, con los que estima un banco de socios |
| Cobertura | El % de la tarjeta para gastos excepcionales, sobre lo estimado |
| Ventana | Los tres meses completos anteriores al mes en curso, con los que se promedian los gastos de las supervisoras |
| Mes base | El mes en cuya moneda está un promedio; la inflación se compone desde el siguiente |
| Histórico manual | Tres meses de gasto con tarjeta cargados a mano para quien tiene tarjeta Supervisora y no carga gastos |
| Vincular | Asignarle una tarjeta a una factura, con todas sus cuotas |
| Vencimiento a considerar | El vencimiento de Tango o el corregido en esta pestaña: desde él se busca el débito |
| Cubierta por resumen | Una factura cuya tarjeta tiene resumen en su mes de pago: no suma aparte |
| Proveedor que proyecta | Un proveedor cuyas facturas pendientes vinculadas se repiten todos los meses, con inflación |
| Seguro | Un proveedor con rubro SEGUROS y forma de pago TARJETA CORP: se proyecta con sus pólizas |
| Vigencia, gracia | Los meses que cubre una póliza; los que quedan sin pago |
| Continuación estimada | La repetición del ciclo de una póliza que terminó, hasta que se carga la siguiente |
| Próximo vencimiento | El primer débito de una tarjeta después de hoy; en Socios va al dólar BCRA |
| Dólar futuro | La curva de dólar futuro del cashflow: a cuánto está el dólar de cada mes |
| Horizonte | Los meses que proyecta el cashflow |
