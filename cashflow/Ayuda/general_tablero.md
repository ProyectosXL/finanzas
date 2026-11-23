---
titulo: Cómo leer el tablero
pestana:
categoria: General
estado: en_revision
actualizado: 2026-11-23
usuarios: Tesorería, Administración, Dirección
bajada: Qué mide cada número de la pestaña Cashflow, cómo se arrastra el saldo y qué hacer cuando algo no cierra.
---

## Para qué sirve el tablero

La pestaña **Cashflow** junta en un solo cuadro lo que entra y lo que sale de todos los módulos: ventas, cobranzas, cheques, proveedores, comercio exterior, tarjetas y el resto. Cada fila trae sus números de su pestaña; el tablero no se carga a mano.

El cuadro se lee de arriba hacia abajo: primero los ingresos con su total, después los egresos con el suyo, el flujo neto y, al final, la sección **Cobertura**. Qué filas y secciones hay se define en **Parámetros › Cashflow**.

Arriba del cuadro están las tarjetas, la barra que dice qué período miden y el panel **Sobre estos números**, con los avisos. Los avisos se explican en [Los avisos](ayuda:general_avisos).

## Ingresos, egresos y flujo neto

Cada fila es de ingreso o de egreso, y suma en su sección. Las filas de total de cada sección (por ejemplo, el total de ingresos) suman las filas de su sección.

El **flujo neto** de una columna es lo que entra menos lo que sale ese día o ese mes. Si en esa columna hay un saldo cargado en la fila **Saldo Inicial**, el flujo neto lo incluye: el bloque de ingresos arranca en lo disponible, que incluye el saldo en bancos.

Hay filas que se muestran pero no suman: llevan un ojo al lado del nombre y van en gris e itálica. Al pasar el mouse dicen **Informativa: se muestra pero no entra en ninguna suma**.

## El saldo y el arrastre

La fila **Saldo Inicial** muestra el saldo que se carga en la pestaña **Saldos**, en la columna de su fecha, y nada más. No acumula.

Lo que acumula son las filas de saldo acumulado, las que llevan el ícono de dos flechas al lado del nombre: **Flujo Neto Acumulado (sin cobertura)**, antes de la cobertura, y **Flujo Neto Acumulado (con cobertura)**, al final. Cada columna arranca con lo que dejó la anterior, le suma el saldo cargado y lo que se movió en esa columna. Eso es el **arrastre**: la posición proyectada al cierre de cada día o mes.

:::formula
saldo al cierre de la columna = saldo al cierre de la anterior + saldo cargado en la columna + ingresos − egresos
:::

El total de una fila de saldo no es una suma: es el saldo al final del período que estás mirando.

Si el saldo final de una columna da negativo, **la columna entera se pinta de rojo claro**, encabezado incluido. Así se ve de un vistazo qué día falta plata.

:::ejemplo ilustrativo
Tres días, con un saldo en bancos de **$ 10.000.000** cargado el primero.

| Fila | Día 1 | Día 2 | Día 3 |
| --- | ---: | ---: | ---: |
| Saldo Inicial | 10.000.000 | 0 | 0 |
| Ingresos | 3.000.000 | 2.000.000 | 1.000.000 |
| Egresos | 5.000.000 | 6.000.000 | 7.000.000 |
| Flujo neto (sin cobertura) | 8.000.000 | −4.000.000 | −6.000.000 |
| Saldo acumulado (sin cobertura) | 8.000.000 | 4.000.000 | −2.000.000 |
| Uso de cobertura | 0 | 0 | 2.000.000 |
| Saldo final (con cobertura) | 8.000.000 | 4.000.000 | 0 |

El día 1 el flujo neto es 10.000.000 + 3.000.000 − 5.000.000 = 8.000.000. El día 3 el saldo sin cubrir da −2.000.000; el motor rescata 2.000.000 de las inversiones y el saldo final queda en 0.
:::

## La cobertura

Cuando el saldo acumulado da negativo, la plata para taparlo suele existir: está invertida. La sección **Cobertura** muestra cuánto hay y cuándo se usa.

- Hay una fila de uso por tipo de fondo: una para las inversiones y otra para los dólares de la cuenta comitente. Debajo del nombre dice cuánto queda disponible en esos fondos, sobre todo el horizonte.
- **El motor la calcula solo** en cada carga: si el saldo acumulado queda abajo de cero, rescata exactamente lo que falta; si después sobra, devuelve al fondo. Primero usa las inversiones y, si no alcanzan, vende dólares de la comitente.
- Lo que calculó el motor se ve en **itálica azul**; lo cargado a mano, en **negrita con un punto**.
- Si con todos los fondos no alcanza, la celda lo dice al pasar el mouse (**AUN ASÍ FALTAN…**) y la columna queda en rojo.

Es la única fila que se puede tocar desde el tablero, y sólo si tenés permiso para editar la pestaña Cashflow. Hacé clic en la celda de un día, tipeá el importe en la moneda del fondo y apretá Enter; Escape cancela. Lo manual va primero y el motor cubre el resto. Un importe negativo devuelve plata al fondo, y vaciar la celda borra la carga manual de ese día. Las columnas de meses no se editan: se carga por día, en la vista **Días**.

## Las tarjetas

| Tarjeta | Qué dice |
| --- | --- |
| **Disponible Inicial** | Con cuánto se arranca hoy. Es la única que no cambia con la vista, y por eso va marcada aparte. |
| **Ingresos** | Lo que entra en las columnas que estás mirando, incluido el saldo que muestre la fila **Saldo Inicial** en esas columnas. |
| **Egresos** | Lo que sale en las columnas que estás mirando. |
| **Flujo Neto** | Ingresos menos egresos. No incluye la cobertura: si hubo, el pie lo dice aparte. |
| **Saldo Final** | El saldo acumulado al cierre de la última columna que estás mirando, ya con la cobertura. |
| **Saldo Mínimo** | El peor saldo acumulado del período y en qué columna cae. |

Los importes de las tarjetas van en millones a partir del millón; el número exacto aparece al pasar el mouse. Abajo de las tarjetas, una barra dice qué período miden ellas y la columna **Total**.

El chevron de la esquina de la última tarjeta las contrae a una franja con el título y el valor; el pie pasa a verse al pasar el mouse. El navegador recuerda cómo las dejaste.

## Las tres vistas

| Vista | Qué muestra |
| --- | --- |
| **Días** | Las columnas diarias, desde hoy. |
| **Meses** | Las columnas mensuales. Cada mes junta sólo los días que quedan fuera del tramo diario. |
| **Período completo** | Los días y después los meses, en orden. |

Las tarjetas y la columna **Total** miden exactamente las columnas que se ven. Por eso el total de **Meses** no es el de todo el horizonte: le faltan los días del tramo diario. Un importe va a un día o a un mes, nunca a los dos, así que el total de **Días** más el de **Meses** da el de **Período completo**.

Cuántos días y cuántos meses tiene el horizonte se configura en **Parámetros › Calendario**. Al abrir la pestaña, la vista arranca en **Días**.

### Lo que marca el eje

- Un **sábado, domingo o feriado** va con fondo gris suave; al pasar el mouse por el encabezado dice **Fin de semana** o **Feriado**.
- Un **feriado de comercio** lleva otro fondo y dice **Feriado de comercio: sin venta estimada**.
- Una columna que no cubre ningún día futuro va con un guión sobre fondo gris. No es un cero: ahí no hay nada que proyectar.

## Filas agrupadas

Algunos conceptos tienen una parte real y una parte estimada: por ejemplo, la cobranza de franquicias. En el tablero se ven como **un renglón con un chevron**, que muestra la suma. Tocá el chevron para ver las dos partes por separado.

Abrir o cerrar un renglón no cambia ningún total. El botón **Expandir todo** abre todos; cuando están todos abiertos dice **Agrupar todo**. El detalle está en [Real y proyectado](ayuda:general_real_proyectado#filas-con-parte-real-y-parte-proyectada).

## Ir a la pestaña de una fila

En las filas que salen de una pestaña, el nombre es un enlace: tocalo y se abre la pestaña que la alimenta, como si la eligieras en el menú. Si la fila sale de una sub-pestaña, se abre en esa.

Un renglón agrupado lleva enlace sólo si sus dos partes van a la misma pestaña; si no, cada parte lleva el suyo. Si no tenés permiso para ver esa pestaña, el enlace no lleva a ningún lado: el número igual se muestra.

El botón **Actualizar** vuelve a calcular todo el tablero y **Exportar** baja a Excel lo que se ve, con los grupos como estén abiertos o cerrados.

## Preguntas frecuentes

:::pregunta ¿Por qué el Flujo Neto y el Saldo Final no dan lo mismo?
Por dos motivos. El **Flujo Neto** no incluye la cobertura y el **Saldo Final** sí: cuando hubo cobertura, el pie de la tarjeta de Flujo Neto dice cuánto. Además, el Saldo Final arrastra todo lo anterior: en la vista **Meses** incluye lo que pasó en el tramo diario, aunque esas columnas no se vean.
:::

:::pregunta ¿Por qué el total de la vista Meses es más chico que el del Período completo?
Porque cada mes junta sólo los días que quedan fuera del tramo diario. Lo que pasa en el tramo diario está en **Días**. Sumá las dos vistas y tenés el **Período completo**. La barra debajo de las tarjetas dice siempre qué período estás midiendo.
:::

:::pregunta ¿Qué significa una columna pintada de rojo?
Que el saldo final de ese día o ese mes da negativo, ya con la cobertura aplicada. O sea, aun rescatando de los fondos falta plata. Pasá el mouse por la celda de cobertura de esa columna para ver cuánto falta. Ver [La cobertura](ayuda:general_tablero#la-cobertura).
:::

:::pregunta ¿Por qué hay celdas con un guión sobre fondo gris?
Son columnas que no cubren ningún día futuro. Por ejemplo, la del mes en curso cuando todos los días que le quedan entran en el tramo diario: lo único que esa columna podría juntar son días que ya pasaron. No es un cero: no hay nada que proyectar ahí.
:::

:::pregunta Falta un importe que esperaba ver. ¿Dónde está?
Abrí **Sobre estos números**. Ahí el tablero dice lo que quedó afuera y por qué: importes con fecha fuera del horizonte, importes sin fecha, lo marcado como ya pagado, módulos que todavía no existen. Cada grupo tiene **Ir a la pestaña** para corregirlo donde corresponde. Ver [Los avisos](ayuda:general_avisos).
:::

:::pregunta ¿Puedo corregir un número desde el tablero?
Sólo la cobertura, y sólo si tenés permiso para editar la pestaña Cashflow. Todo lo demás se corrige en la pestaña que alimenta la fila: tocá el nombre de la fila para ir.
:::

:::pregunta ¿Por qué la fila de saldo inicial está en cero casi todos los días?
Porque muestra el saldo cargado en **Saldos** sólo en la columna de su fecha. La posición de cada día la dicen las filas **Flujo Neto Acumulado**, las del ícono de dos flechas.
:::
