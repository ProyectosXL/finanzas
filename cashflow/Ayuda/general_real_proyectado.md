---
titulo: Real y proyectado
pestana:
categoria: General
estado: completa
actualizado: 2026-11-27
usuarios: Tesorería, Administración, Dirección
bajada: Qué parte de cada número ya está comprometida, qué parte es una estimación y cómo se distinguen en pantalla.
---

## Real y estimado

El cashflow mezcla dos clases de plata. **Real** es lo que ya tiene un respaldo: una factura, una propuesta de pago aceptada, un contenedor cargado, un saldo en el banco. **Proyectado** es lo que el sistema estima: la cobranza que se espera de las ventas, lo que falta comprar del presupuesto, el alquiler de un mes que todavía no se facturó.

Las dos entran en el tablero y en los totales. Lo que cambia es cuánto se puede confiar en la fecha y en el importe, y por eso la pantalla las muestra distinto.

## Filas con parte real y parte proyectada

Algunos conceptos son las dos cosas a la vez. Hoy son, entre otros, **Cobranzas Franquicias** (propuestas aceptadas contra lo que se proyecta por el plazo promedio de pago), **Proveedores Exterior** y **Nacionalizaciones** (contenedores ya cargados contra lo que falta comprar del presupuesto). Qué filas se agrupan se define en **Parámetros › Cashflow**.

En el tablero cada uno es **un renglón con un chevron** que muestra la suma:

- Cerrado, el renglón muestra el total. Al pasar el mouse por una celda ves cuánto pone cada parte: **Real: $ … · Proyectado: $ …**.
- Abierto, las dos partes van debajo, con sangría y una etiqueta adelante del nombre: **Real** o **Proyectado**.
- **La parte proyectada va en itálica y en un tono más suave**, para que al abrir el renglón se distinga de la real.
- **Expandir todo** abre todos los renglones; cuando están todos abiertos, el mismo botón dice **Agrupar todo**.

Abrir o cerrar no cambia ningún subtotal, ni el flujo neto, ni el saldo, ni las tarjetas: el renglón es sólo la suma de lo que ya está en el cuadro. Tu navegador recuerda qué renglones dejaste abiertos; la primera vez arrancan cerrados.

:::ejemplo ilustrativo
La fila **Cobranzas Franquicias** de un día muestra **1.000.000**. Al pasar el mouse dice *Real: $ 400.000,00 · Proyectado: $ 600.000,00*.

Abierta, se ve así:

| Concepto | Día |
| --- | ---: |
| Cobranzas Franquicias | 1.000.000 |
| Real — las propuestas aceptadas | 400.000 |
| Proyectado — lo estimado por plazo de pago | 600.000 |

400.000 + 600.000 = 1.000.000. El total del día y el saldo son los mismos con el renglón abierto o cerrado.
:::

## Las marcas en el nombre de una fila

Al lado del nombre de una fila del tablero puede haber un ícono chico. Pasá el mouse para leer qué dice.

| Ícono | Qué significa |
| --- | --- |
| Dos flechas | Es un saldo acumulado: no es un dato cargado, se arrastra columna a columna. |
| Varita | Es la cobertura: la calcula el motor en cada carga. |
| Signo de dólar | El importe estaba en dólares y se convirtió a pesos. El tooltip dice con qué cotización o, si cada importe tiene la suya, que el detalle está en la pestaña. |
| Apretón de manos | Parte de lo que ves tiene fecha de cobro pactada con el cliente, no estimada. Dice cuánto y en cuántas columnas. |
| Ojo | Fila informativa: se muestra pero no entra en ninguna suma. Va en gris e itálica. |
| Círculo con una i | **Todavía no hay datos para esta fila.** |

## Las anotaciones de una celda

Una celda puede llevar una **línea violeta fina abajo**. Quiere decir que una parte de su importe tiene algo que contar. Pasá el mouse: el tooltip dice qué parte y por qué.

Hoy la usan, por ejemplo, la cobranza proyectada con fecha pactada a mano y la celda de hoy de **Exportaciones Tasky**, donde se ubican las facturas cuya fecha de cobro ya venció. Si la celda está en un renglón agrupado y cerrado, la anotación aparece igual, debajo del desglose real y proyectado.

## Calculado o cargado a mano

En la sección **Cobertura** del tablero conviven dos cosas: lo que rescata el motor y lo que alguien cargó a mano.

- Lo calculado va en **itálica azul**.
- Lo cargado a mano va en **negrita, con un punto** a la izquierda.

Al pasar el mouse, la celda desglosa las dos partes por fondo. Ver [La cobertura](ayuda:general_tablero#la-cobertura).

## El origen de cada importe en las pestañas

Algunas pestañas dicen de dónde sale cada importe con un **chip chico al lado del número**, y una leyenda arriba de la grilla explica cada chip. Si una celda junta importes de distintos orígenes, el chip dice **mixto** y el tooltip los desglosa.

- En **Alquileres**: real con el período cerrado, cargado en la app con el período abierto, calculado con la venta real, estimado (o estimado por inflación desde un mes), y la factura imputada.
- En **Llaves y Renov. Contratos**: contrato cargado, estimado, manual o factura. **Lo estimado va en itálica y atenuado.**

El detalle de cada uno está en su ayuda, por ejemplo [Llaves y Renov. Contratos](ayuda:llaves_renov).

## Lo vencido

Una fecha que ya pasó y no se pagó ni se cobró no es una proyección. Cada módulo la trata según qué significa:

- **Cobranzas Franquicias y Mayoristas**: las facturas proyectadas vencidas, de hasta 180 días atrás, entran en la columna de hoy. Las más viejas no entran. El aviso dice cuántas son y cuánto suman.
- **Exportaciones Tasky**: las facturas cuya fecha de cobro estimada ya venció se ubican hoy, con el aviso y la anotación en la celda.
- **Proveedores Exterior** y **Crono Nacionalización**: lo vencido no suma en el tablero; o ya se pagó, o hay que corregirle la fecha. En la pestaña se esconde detrás del interruptor **Ver vencidas**, que dice al lado cuántas esconde. Ver [Proveedores Exterior](ayuda:proveedores_exterior) y [Crono Nacionalización](ayuda:crono_nacionalizacion).

## Lo marcado como ya pagado

Cuando alguien marca un pago como ya hecho, ese importe **sale de la proyección**: el tablero deja de esperarlo.

En **Proveedores Exterior** y **Crono Nacionalización** el panel de avisos dice cuánto salió por eso, y lo marcado se esconde detrás del interruptor **Ver pagados** (en Crono Nacionalización, **Ver pagadas**), que dice al lado cuántos esconde. Prendelo para verlos y destildar el que se haya marcado por error. Además, **Proveedores Exterior** proyecta sólo lo que falta pagar: un pago cargado en Comercio Exterior ya baja el importe, sin que nadie marque nada.

En **Llaves y Renov. Contratos**, una renovación se marca con **Ya pagada** desde la solapa **Contratos**: se tilda qué se pagó —la **llave**, los **otros gastos** (comisiones, FPC y escribano) o los dos—, con la fecha y el comprobante, **sin importes**. Lo marcado deja de proyectarse en esa renovación. Ver [Llaves y Renov. Contratos](ayuda:llaves_renov).

## Pestañas que todavía no muestran datos reales

En el menú, una pestaña puede llevar una marca:

| Marca | Qué significa |
| --- | --- |
| Sin marca | La pestaña lee del sistema: podés confiar en lo que muestra. |
| **Maqueta** | La pantalla está dibujada pero los números son de ejemplo, todavía no salen del sistema. |
| **Pendiente** | Todavía no se desarrolló: muestra un aviso de sección en construcción. |

Con el menú contraído, el estado aparece al pasar el mouse por el ícono, al lado del nombre. Cada categoría muestra cuántas de sus pestañas tienen datos del sistema, por ejemplo 3/4.
