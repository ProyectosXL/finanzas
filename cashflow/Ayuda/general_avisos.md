---
titulo: Los avisos
pestana:
categoria: General
estado: completa
actualizado: 2026-11-23
usuarios: Tesorería, Administración, Dirección
bajada: Qué dice cada nivel de aviso, dónde aparecen y cómo se corrige lo que avisan.
---

## Para qué están

Un aviso explica un número: por qué una fila da cero, qué plata quedó afuera, qué está estimado o qué decidió alguien a mano. Sin el aviso, un cero se lee como si fuera un dato.

Los avisos los arma el sistema con los datos. No se cierran ni se borran: siguen ahí mientras el dato siga igual, y desaparecen solos cuando se corrige.

## Los tres niveles

| Nivel | Cuándo | Qué te pide |
| --- | --- | --- |
| **Crítico** | El número está mal o incompleto por algo que no decidió nadie: un módulo que no se pudo calcular o cargar, un error de configuración, una lectura que falló, una fila en cero porque falta una cotización, el saldo inicial desactualizado, un pago contado dos veces. | No confiar en ese número hasta que se resuelva. |
| **Atención** | Plata que no entra al tablero, o entra en otro lugar, y que se arregla con una acción: cargar una fecha, un saldo o una cotización, vincular una factura, completar un maestro. | Hacer esa acción, en la pestaña que corresponde. |
| **Info** | Explica un criterio o algo que alguien ya decidió: lo excluido a mano, lo marcado como ya hecho, lo que sale por otra fila, lo que cae fuera del horizonte, los módulos que todavía no existen. | Nada, salvo que la decisión esté mal. |

Que algo se pueda arreglar no le baja el nivel: si el número está mal y nadie lo decidió, es **Crítico**, y el texto dice cómo arreglarlo.

Cada aviso lleva el color y el ícono de su nivel. Estos tres son textos reales:

:::aviso critico
texto: No se pudo armar el tablero: …
significa: El tablero no se pudo calcular. Lo que se ve no está actualizado o no está completo.
corrige: Tocá **Actualizar** para volver a calcularlo. Lo que sigue a los dos puntos dice qué falló.
:::

:::aviso atencion
texto: *Nombre de la fila*: $ … sin fecha asignada no se pueden ubicar en el tablero.
significa: Esa plata existe pero no tiene fecha, así que no está en ninguna columna.
corrige: En la pestaña de esa fila, cargándole la fecha.
:::

:::aviso info
texto: *Nombre de la fila*: $ … con fecha fuera del horizonte proyectado no se muestran en el tablero.
significa: Son importes con fecha más allá del último mes que mira el tablero. No es un error.
corrige: Nada. Si querés verlos, se agranda el horizonte en **Parámetros › Calendario**.
:::

## En el tablero: Sobre estos números

En la pestaña **Cashflow**, arriba del cuadro, está el panel **Sobre estos números**. El título dice cuántos avisos hay, y al lado lleva un chip por nivel con su cantidad, así que cerrado igual se sabe qué hay.

Adentro, los avisos van **agrupados por pestaña**: cada grupo es la pestaña del módulo que lo generó, con el nombre completo de su encabezado. Por ejemplo, los de Proveedores Locales van en **Cuentas a Pagar Locales**. Los avisos del propio cuadro —la configuración, el arrastre, la cobertura— van en el grupo **Tablero**.

El orden es por gravedad:

1. Primero los grupos con algún crítico, después los que tienen alguno de atención y al final los que sólo informan.
2. Entre grupos del mismo nivel, primero **Tablero** y después en el orden del menú.
3. Adentro de cada grupo, de más grave a menos.

Cuando hace falta decir de qué parte de la pestaña habla, el aviso empieza con esa parte en negrita: por ejemplo **Caja Locales** en Saldos, o **Corporativas** en Pagos con Tarjetas.

### Ir a la pestaña

Cada grupo tiene el enlace **Ir a la pestaña**, que abre esa pestaña como el menú. Si el grupo junta varias sub-pestañas, como Saldos, abre la primera. El grupo **Tablero** no lo tiene.

Si no tenés permiso para ver esa pestaña, el enlace no aparece. El aviso sí: el número del tablero lo incluye igual.

## En cada pestaña: el panel Avisos

Las demás pestañas muestran sus avisos en un panel que dice **Avisos (N)**, con los mismos chips por nivel. Sin avisos, el panel no aparece.

Las pestañas que alimentan una fila del tablero muestran ahí también **lo que el tablero dice de ellas**, con el mismo texto y el mismo nivel, más los suyos propios. Si un aviso está en los dos lados, aparece una sola vez. Para verlos alcanza con poder ver la pestaña, aunque no puedas abrir el tablero.

Si esos avisos no se pueden traer, la pestaña funciona igual y el panel suma uno de atención: **No se pudieron traer los avisos del tablero para esta pestaña.**

En **Saldos**, **Pagos con Tarjetas y Otros** y **Echeqs** cada sub-pestaña tiene su panel. Cada aviso va a la sub-pestaña de la parte de la que habla; los que no hablan de ninguna parte van a todas.

## Qué arranca abierto

| Panel | Arranca |
| --- | --- |
| **Sobre estos números** y **Avisos (N)** | Abierto si hay algún crítico. Si no, como lo dejaste la última vez; la primera vez, cerrado. |
| Cada grupo de **Sobre estos números** | Abierto si tiene algún crítico. Si no, como lo dejaste. |

Un crítico no se puede esconder: el panel se abre solo cada vez, porque es el aviso que dice que el número está mal. Lo demás lo recuerda tu navegador, por pestaña y por panel.

## Cómo se corrigen

Casi nunca se corrige en el tablero: el tablero sólo junta lo que le mandan las pestañas.

:::pasos
1. En **Sobre estos números**, abrí el grupo y leé el aviso entero: casi siempre dice qué falta y dónde.
2. Tocá **Ir a la pestaña**.
3. En la pestaña, abrí **Avisos (N)**: vas a encontrar el mismo aviso, a veces con más detalle de la pestaña.
4. Hacé lo que pide: cargar la fecha, el saldo o la cotización, vincular la factura, completar el maestro, o corregir el parámetro en **Parámetros**.
5. Volvé al tablero y tocá **Actualizar**.
Resultado: el aviso desaparece, o cambia de importe, porque el dato cambió.
:::

Un aviso **Info** no pide nada. Si describe una decisión equivocada —algo excluido o marcado como pagado por error—, se deshace donde se tomó: por ejemplo, destildándolo con **Ver pagados** en Proveedores Exterior.

## Aviso o notificación

No son lo mismo.

| | Aviso | Notificación |
| --- | --- | --- |
| Sobre qué | Los datos: lo que el número incluye o deja afuera. | Algo que hiciste: se guardó, falló, falta un campo. |
| Dónde | En la pantalla, en el panel de avisos. | En la esquina de abajo a la derecha, encima de todo. |
| Cuánto dura | Mientras el dato siga así. | Un éxito se va solo; un error se queda hasta que lo cerrás, porque trae el porqué. |

Hay carteles que no van en el panel porque se tienen que ver siempre: las explicaciones fijas de una pestaña, los bloqueos y avisos de una importación antes de confirmarla, y los resúmenes de un filtro.
